<?php
/**
 * SEO Pro: creates the database schema the module needs and keeps the contract of the data stored in it.
 *
 * startup() is called from startup/startup of admin and catalog. While the stored seo_pro_schema_version
 * differs from VERSION, install() runs. Every step checks first, so a repeated run changes nothing.
 * Each capability is checked and created on its own: a failure of one step does not stop the others,
 * and the version is written only when every capability is ready. For the current request startup()
 * sets the config flags
 *   seo_pro_schema_failed                  the column main_category is not ready (the flag of 1.6)
 *   seo_pro_h1_product_ready               product_description.meta_h1 is ready
 *   seo_pro_h1_category_ready              category_description.meta_h1 is ready
 *   seo_pro_h1_information_ready           information_description.meta_h1 is ready
 *   seo_pro_manufacturer_description_ready the table manufacturer_description is ready
 * A missing flag means "not ready". The code that reads or writes a new field checks its own flag.
 */
class Seo_pro_schema {
	const VERSION = '1.7';

	// Capability => the config flag startup() sets. main_category keeps its own inverted flag seo_pro_schema_failed.
	const FLAGS = array(
		'h1_product'               => 'seo_pro_h1_product_ready',
		'h1_category'              => 'seo_pro_h1_category_ready',
		'h1_information'           => 'seo_pro_h1_information_ready',
		'manufacturer_description' => 'seo_pro_manufacturer_description_ready'
	);

	// The H1 capabilities: capability => the description table
	const H1_TABLES = array(
		'h1_product'     => 'product_description',
		'h1_category'    => 'category_description',
		'h1_information' => 'information_description'
	);

	// The text fields of manufacturer_description and their limits: characters for varchar, bytes for TEXT
	const MANUFACTURER_FIELDS = array(
		'description'      => array('text', 65535),
		'meta_title'       => array('varchar', 255),
		'meta_h1'          => array('varchar', 255),
		'meta_description' => array('varchar', 255),
		'meta_keyword'     => array('varchar', 255)
	);

	const H1_LIMIT = 255;

	private $db;

	private $log;

	private $cache;

	private $capabilities;

	public function __construct($db, $log, $cache) {
		$this->db = $db;
		$this->log = $log;
		$this->cache = $cache;
		$this->capabilities = array_fill_keys(self::capabilityNames(), false);
	}

	public static function capabilityNames() {
		return array_merge(array('main_category'), array_keys(self::FLAGS));
	}

	/**
	 * Sets the flags of the current request. With the version of this release stored nothing is queried:
	 * the schema is taken as ready. A column removed by hand under a stored version is not detected.
	 */
	public static function startup($config, $db, $log, $cache) {
		if ($config->get('seo_pro_schema_version') === self::VERSION) {
			$capabilities = array_fill_keys(self::capabilityNames(), true);
		} else {
			$schema = new self($db, $log, $cache);

			$config->set('seo_pro_schema_failed', !$schema->install());

			$capabilities = $schema->getCapabilities();
		}

		foreach (self::FLAGS as $name => $flag) {
			$config->set($flag, !empty($capabilities[$name]));
		}
	}

	/**
	 * Never throws: every failure is written to the log and the next request tries again.
	 *
	 * @return bool whether the column main_category exists and can be used by this request
	 */
	public function install(): bool {
		$this->capabilities = array_fill_keys(self::capabilityNames(), false);

		$locked = false;
		$step = 'lock';

		try {
			$lock = $this->db->query("SELECT GET_LOCK('seo_pro_schema', 10) AS locked");

			$locked = !empty($lock->row['locked']);

			if (!$locked) {
				// Another request is installing the schema right now, or the lock failed: nothing is altered without the lock
				$this->write('the lock was not obtained, the next request tries again');
			}

			// With the lock every step checks the schema again before its DDL; without it only checks are made
			$this->ensureAll($locked);

			if ($locked && !in_array(false, $this->capabilities, true)) {
				$step = 'cache reset';

				// Maps built by an older version may hold wrong entries, and the core caches hold rows without the new fields
				Seo_pro_cache::clear($this->db, $this->cache);

				foreach (array('product', 'category', 'manufacturer') as $key) {
					$this->cache->delete($key);
				}

				// The version is the mark of a finished migration, so it is written last
				$step = 'version record';

				$this->db->query("DELETE FROM `" . DB_PREFIX . "setting` WHERE `code` = 'seo_pro' AND `key` = 'seo_pro_schema_version'");
				$this->db->query("INSERT INTO `" . DB_PREFIX . "setting` SET `store_id` = '0', `code` = 'seo_pro', `key` = 'seo_pro_schema_version', `value` = '" . $this->db->escape(self::VERSION) . "', `serialized` = '0'");
			}
		} catch (\Throwable $e) {
			$this->write('failed at the step "' . $step . '": ' . $e->getMessage());

			// The lock query itself has failed: the schema may still be there
			if ($step === 'lock') {
				$this->ensureAll(false);
			}
		} finally {
			// Only the lock this call has obtained is released
			if ($locked) {
				try {
					$this->db->query("SELECT RELEASE_LOCK('seo_pro_schema')");
				} catch (\Throwable $e) {
					$this->write('failed at the step "lock release": ' . $e->getMessage());
				}
			}
		}

		return $this->capabilities['main_category'];
	}

	/**
	 * @return array capability => bool, as confirmed by the last install(); all false before it
	 */
	public function getCapabilities(): array {
		return $this->capabilities;
	}

	// Every capability on its own: an exception of one step leaves only this capability not ready
	private function ensureAll($alter) {
		$this->capabilities['main_category'] = $this->guarded(function () use ($alter) {
			return $this->ensureMainCategory($alter);
		});

		foreach (self::H1_TABLES as $name => $table) {
			$this->capabilities[$name] = $this->guarded(function () use ($alter, $table) {
				return $this->ensureH1($table, $alter);
			});
		}

		$this->capabilities['manufacturer_description'] = $this->guarded(function () use ($alter) {
			return $this->ensureManufacturerDescription($alter);
		});
	}

	private $step;

	private function guarded($check) {
		$this->step = '';

		try {
			return (bool)$check();
		} catch (\Throwable $e) {
			$this->write('failed at the step "' . $this->step . '": ' . $e->getMessage());

			return false;
		}
	}

	private function ensureMainCategory($alter) {
		// The step names of 1.6 are kept for this column
		$this->step = 'column check';

		if ($this->describe('product_to_category', 'main_category')) {
			return true;
		}

		if (!$alter) {
			return false;
		}

		$this->step = 'column creation';

		$this->db->query("ALTER TABLE `" . DB_PREFIX . "product_to_category` ADD `main_category` tinyint(1) NOT NULL DEFAULT '0' AFTER `category_id`");

		return true;
	}

	private function ensureH1($table, $alter) {
		$this->step = 'meta_h1 check of ' . $table;

		$column = $this->describe($table, 'meta_h1');

		if ($column) {
			if (!self::isStringType($column['Type'], self::H1_LIMIT)) {
				// A column of another extension: its type is never changed here
				$this->write('the column meta_h1 of ' . $table . ' has the type ' . $column['Type'] . ', a text of ' . self::H1_LIMIT . ' characters is needed: H1 of this table is not used');

				return false;
			}

			return true;
		}

		if (!$alter) {
			return false;
		}

		$this->step = 'meta_h1 creation of ' . $table;

		$this->db->query("ALTER TABLE `" . DB_PREFIX . $table . "` ADD `meta_h1` varchar(255) NOT NULL DEFAULT '' AFTER `meta_title`");

		return true;
	}

	private function ensureManufacturerDescription($alter) {
		$this->step = 'manufacturer_description check';

		$exists = $this->tableExists('manufacturer_description');

		if (!$exists) {
			if (!$alter) {
				return false;
			}

			$this->step = 'manufacturer_description creation';

			$this->db->query("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "manufacturer_description` (`manufacturer_id` int(11) NOT NULL, `language_id` int(11) NOT NULL, `description` text NOT NULL, `meta_title` varchar(255) NOT NULL DEFAULT '', `meta_h1` varchar(255) NOT NULL DEFAULT '', `meta_description` varchar(255) NOT NULL DEFAULT '', `meta_keyword` varchar(255) NOT NULL DEFAULT '', PRIMARY KEY (`manufacturer_id`, `language_id`))" . $this->tableOptions('manufacturer'));

			$this->step = 'manufacturer_description check';
		}

		$problems = $this->manufacturerDescriptionProblems();

		if ($problems['conflict']) {
			// An existing table of another extension: types and keys are never changed here
			$this->write('the table manufacturer_description is not compatible (' . implode('; ', $problems['conflict']) . '): descriptions of manufacturers are not used');

			return false;
		}

		if (!$problems['missing']) {
			return true;
		}

		if (!$alter) {
			return false;
		}

		// A compatible table without some of the text columns: they are added, the rows keep their values
		$this->step = 'manufacturer_description columns';

		foreach ($problems['missing'] as $field) {
			if ($field === 'description') {
				$this->db->query("ALTER TABLE `" . DB_PREFIX . "manufacturer_description` ADD `description` text NOT NULL");
			} else {
				$this->db->query("ALTER TABLE `" . DB_PREFIX . "manufacturer_description` ADD `" . $field . "` varchar(255) NOT NULL DEFAULT ''");
			}
		}

		$this->step = 'manufacturer_description check';

		$problems = $this->manufacturerDescriptionProblems();

		return !$problems['conflict'] && !$problems['missing'];
	}

	// conflict: what cannot be fixed without changing existing data; missing: text columns that can be added
	private function manufacturerDescriptionProblems() {
		$columns = array();

		foreach ($this->db->query("SHOW COLUMNS FROM `" . DB_PREFIX . "manufacturer_description`")->rows as $row) {
			$columns[$row['Field']] = $row['Type'];
		}

		$conflict = array();
		$missing = array();

		foreach (array('manufacturer_id', 'language_id') as $field) {
			if (!isset($columns[$field])) {
				$conflict[] = 'no column ' . $field;
			} elseif (!preg_match('/^(int|bigint)(\(\d+\))?( unsigned)?$/i', $columns[$field])) {
				$conflict[] = $field . ' is ' . $columns[$field];
			}
		}

		foreach (self::MANUFACTURER_FIELDS as $field => $limit) {
			if (!isset($columns[$field])) {
				$missing[] = $field;
			} elseif ($limit[0] === 'text' ? !preg_match('/^(text|mediumtext|longtext)$/i', $columns[$field]) : !self::isStringType($columns[$field], $limit[1])) {
				$conflict[] = $field . ' is ' . $columns[$field];
			}
		}

		$key = array();

		foreach ($this->db->query("SHOW KEYS FROM `" . DB_PREFIX . "manufacturer_description` WHERE `Key_name` = 'PRIMARY'")->rows as $row) {
			$key[(int)$row['Seq_in_index']] = $row['Column_name'];
		}

		ksort($key);

		if (array_values($key) !== array('manufacturer_id', 'language_id')) {
			$conflict[] = 'the primary key is (' . implode(', ', $key) . '), (manufacturer_id, language_id) is needed';
		}

		return array('conflict' => $conflict, 'missing' => $missing);
	}

	// The engine, the charset and the collation of an existing table, only when they are plain names
	private function tableOptions($table) {
		$query = $this->db->query("SHOW TABLE STATUS LIKE '" . $this->db->escape(addcslashes(DB_PREFIX . $table, '\\_%')) . "'");

		$options = '';

		if ($query->num_rows) {
			$engine = isset($query->row['Engine']) ? (string)$query->row['Engine'] : '';
			$collation = isset($query->row['Collation']) ? (string)$query->row['Collation'] : '';

			if (preg_match('/^[A-Za-z0-9_]+$/D', $engine)) {
				$options .= ' ENGINE=' . $engine;
			}

			// The charset is the first part of the name of the collation
			if (preg_match('/^([A-Za-z0-9]+)_[A-Za-z0-9_]+$/D', $collation, $match)) {
				$options .= ' DEFAULT CHARSET=' . $match[1] . ' COLLATE=' . $collation;
			}
		}

		return $options;
	}

	private function tableExists($table) {
		return (bool)$this->db->query("SHOW TABLES LIKE '" . $this->db->escape(addcslashes(DB_PREFIX . $table, '\\_%')) . "'")->num_rows;
	}

	// The DESCRIBE row of the column, or null
	private function describe($table, $column) {
		$query = $this->db->query("DESCRIBE `" . DB_PREFIX . $table . "` `" . $column . "`");

		return $query->num_rows ? $query->row + array('Type' => '') : null;
	}

	// A column that keeps a text of $length characters without cutting it
	private static function isStringType($type, $length) {
		if (preg_match('/^(text|mediumtext|longtext)$/i', $type)) {
			return true;
		}

		return preg_match('/^varchar\((\d+)\)$/i', $type, $match) && (int)$match[1] >= $length;
	}

	private function write($message) {
		try {
			$this->log->write('SEO Pro schema: ' . $message);
		} catch (\Throwable $e) {
			// The log is not available: the migration result does not depend on it
		}
	}

	/*
	 * The contract of the new fields: they are stored the way the core stores the text of a form, escaped once by
	 * Request::clean() (htmlspecialchars, ENT_COMPAT). A value of the model API (an import) is brought to this form
	 * by text(); a value that is already escaped does not change. The length is checked on the stored form.
	 */

	/**
	 * The stored form of a text: ", <, > and a bare & escaped, the entities that are already there kept.
	 * An escaped text (a form after Request::clean()) is returned unchanged. Invalid UTF-8 becomes U+FFFD.
	 */
	public static function text($value) {
		return htmlspecialchars((string)$value, ENT_COMPAT | ENT_SUBSTITUTE, 'UTF-8', false);
	}

	// textError() of the stored form of $value: 'type' for a value that is not valid UTF-8 text, else the length of text($value)
	private static function storedError($value, $limit, $bytes = false) {
		return self::textError($value, $limit, $bytes) ?: self::textError(self::text($value), $limit, $bytes);
	}

	/**
	 * @return string '' when $value can be stored, 'type' when it is not a string of valid UTF-8, 'length' when it is too long
	 */
	public static function textError($value, $limit, $bytes = false) {
		if (!is_string($value) || !preg_match('//u', $value)) {
			return 'type';
		}

		$length = $bytes ? strlen($value) : (function_exists('utf8_strlen') ? utf8_strlen($value) : mb_strlen($value, 'UTF-8'));

		return $length > $limit ? 'length' : '';
	}

	/**
	 * Prepares the descriptions of a product, a category or an information page for the core INSERTs.
	 * Throws before any write: when the column is not ready, when the descriptions or one meta_h1 is wrong.
	 * A language without the key meta_h1 keeps the stored H1 ($id of the edited record, 0 for a new one); '' clears it.
	 * Every meta_h1 is returned in the stored form (text()).
	 *
	 * @param string $entity product, category or information
	 */
	public static function prepareH1($db, $config, $entity, $id, $data) {
		if (!is_string($entity) || !array_key_exists('h1_' . $entity, self::H1_TABLES)) {
			throw new \InvalidArgumentException('SEO Pro: unknown entity ' . $entity);
		}

		$table = self::H1_TABLES['h1_' . $entity];

		if (!$config->get(self::FLAGS['h1_' . $entity])) {
			throw new \RuntimeException('SEO Pro: the column meta_h1 of ' . $table . ' is not ready, the descriptions are not saved');
		}

		if (!is_array($data) || !array_key_exists($table, $data)) {
			return $data;
		}

		if (!is_array($data[$table])) {
			throw new \InvalidArgumentException('SEO Pro: ' . $table . ' must be an array');
		}

		$missing = false;

		foreach ($data[$table] as $language_id => $value) {
			if (!is_array($value)) {
				throw new \InvalidArgumentException('SEO Pro: ' . $table . '[' . $language_id . '] must be an array');
			}

			if (!array_key_exists('meta_h1', $value)) {
				$missing = true;
			} elseif (self::storedError($value['meta_h1'], self::H1_LIMIT)) {
				throw new \InvalidArgumentException('SEO Pro: ' . $table . '[' . $language_id . '][meta_h1] must be a text of at most ' . self::H1_LIMIT . ' characters');
			} else {
				$data[$table][$language_id]['meta_h1'] = self::text($value['meta_h1']);
			}
		}

		if (!$missing) {
			return $data;
		}

		$stored = array();

		if ((int)$id > 0) {
			foreach ($db->query("SELECT language_id, meta_h1 FROM " . DB_PREFIX . $table . " WHERE " . $entity . "_id = '" . (int)$id . "'")->rows as $row) {
				$stored[(int)$row['language_id']] = self::text($row['meta_h1']);
			}
		}

		foreach ($data[$table] as $language_id => $value) {
			if (!array_key_exists('meta_h1', $value)) {
				$data[$table][$language_id]['meta_h1'] = isset($stored[(int)$language_id]) ? $stored[(int)$language_id] : '';
			}
		}

		return $data;
	}

	/**
	 * The errors of the H1 fields of a form: language_id => 'type' | 'length' (of the stored form). A language without meta_h1 is no error.
	 */
	public static function h1Errors($descriptions) {
		$errors = array();

		if (is_array($descriptions)) {
			foreach ($descriptions as $language_id => $value) {
				if (is_array($value) && array_key_exists('meta_h1', $value)) {
					$error = self::storedError($value['meta_h1'], self::H1_LIMIT);

					if ($error) {
						$errors[$language_id] = $error;
					}
				}
			}
		}

		return $errors;
	}

	/**
	 * The errors of manufacturer_description of a form or of a model call.
	 *
	 * @return array ['container' => bool, 'fields' => [language_id => [field => 'type' | 'length']]]
	 */
	public static function manufacturerErrors($descriptions) {
		$result = array('container' => false, 'fields' => array());

		if (!is_array($descriptions)) {
			$result['container'] = true;

			return $result;
		}

		foreach ($descriptions as $language_id => $fields) {
			if (!self::isId($language_id) || !is_array($fields)) {
				$result['container'] = true;

				continue;
			}

			foreach (self::MANUFACTURER_FIELDS as $field => $limit) {
				if (array_key_exists($field, $fields)) {
					$error = self::storedError($fields[$field], $limit[1], $limit[0] === 'text');

					if ($error) {
						$result['fields'][$language_id][$field] = $error;
					}
				}
			}
		}

		return $result;
	}

	/**
	 * Checks manufacturer_description of the model data before any write.
	 * Throws when the table is not ready or the data is wrong.
	 *
	 * @return array|null null when $data has no manufacturer_description (nothing is changed), else language_id => [field => stored form] of the sent fields
	 */
	public static function prepareManufacturer($config, $data) {
		if (!$config->get(self::FLAGS['manufacturer_description'])) {
			throw new \RuntimeException('SEO Pro: the table manufacturer_description is not ready, the manufacturer is not saved');
		}

		if (!is_array($data) || !array_key_exists('manufacturer_description', $data)) {
			return null;
		}

		$errors = self::manufacturerErrors($data['manufacturer_description']);

		if ($errors['container'] || $errors['fields']) {
			throw new \InvalidArgumentException('SEO Pro: manufacturer_description must be language_id => [field => text]; descriptions up to 65535 bytes, other fields up to 255 characters');
		}

		$descriptions = array();

		foreach ($data['manufacturer_description'] as $language_id => $fields) {
			$descriptions[(int)$language_id] = array_map(array(self::class, 'text'), array_intersect_key($fields, self::MANUFACTURER_FIELDS));
		}

		return $descriptions;
	}

	/**
	 * Writes the sent fields of each sent language; the other fields and languages keep their stored values.
	 *
	 * @param array|null $descriptions the result of prepareManufacturer()
	 */
	public static function saveManufacturer($db, $manufacturer_id, $descriptions) {
		if (!$descriptions) {
			return;
		}

		foreach ($descriptions as $language_id => $fields) {
			if (!$fields) {
				continue;
			}

			$insert = array();
			$update = array();

			foreach (self::MANUFACTURER_FIELDS as $field => $limit) {
				$value = "'" . $db->escape(array_key_exists($field, $fields) ? $fields[$field] : '') . "'";

				$insert[] = "`" . $field . "` = " . $value;

				if (array_key_exists($field, $fields)) {
					$update[] = "`" . $field . "` = " . $value;
				}
			}

			$db->query("INSERT INTO `" . DB_PREFIX . "manufacturer_description` SET `manufacturer_id` = '" . (int)$manufacturer_id . "', `language_id` = '" . (int)$language_id . "', " . implode(', ', $insert) . " ON DUPLICATE KEY UPDATE " . implode(', ', $update));
		}
	}

	/**
	 * Removes the descriptions of a manufacturer that is being deleted. Called before the core deletes anything:
	 * when the table is not ready nothing is deleted at all.
	 */
	public static function deleteManufacturer($db, $config, $manufacturer_id) {
		if (!$config->get(self::FLAGS['manufacturer_description'])) {
			throw new \RuntimeException('SEO Pro: the table manufacturer_description is not ready, the manufacturer is not deleted');
		}

		$db->query("DELETE FROM `" . DB_PREFIX . "manufacturer_description` WHERE `manufacturer_id` = '" . (int)$manufacturer_id . "'");
	}

	/**
	 * @return array language_id => [field => stored text]; empty while the table is not ready
	 */
	public static function getManufacturer($db, $config, $manufacturer_id) {
		$descriptions = array();

		if (!$config->get(self::FLAGS['manufacturer_description'])) {
			return $descriptions;
		}

		foreach ($db->query("SELECT * FROM `" . DB_PREFIX . "manufacturer_description` WHERE `manufacturer_id` = '" . (int)$manufacturer_id . "'")->rows as $row) {
			foreach (self::MANUFACTURER_FIELDS as $field => $limit) {
				$descriptions[(int)$row['language_id']][$field] = isset($row[$field]) ? (string)$row[$field] : '';
			}
		}

		return $descriptions;
	}

	/**
	 * The values the form shows. Each field: the sent value, else the stored one, else ''. A value that is not a string is shown as ''.
	 */
	public static function manufacturerFormValues($posted, $stored) {
		$posted = is_array($posted) ? $posted : array();
		$stored = is_array($stored) ? $stored : array();

		$values = array();

		foreach (array_keys($stored + $posted) as $language_id) {
			foreach (self::MANUFACTURER_FIELDS as $field => $limit) {
				if (isset($posted[$language_id]) && is_array($posted[$language_id]) && array_key_exists($field, $posted[$language_id])) {
					$value = $posted[$language_id][$field];
				} elseif (isset($stored[$language_id][$field])) {
					$value = $stored[$language_id][$field];
				} else {
					$value = '';
				}

				$values[$language_id][$field] = is_string($value) ? $value : '';
			}
		}

		return $values;
	}

	// A language id as a form or the model API sends it: a positive int or its decimal string within signed INT
	private static function isId($value) {
		if (is_int($value)) {
			return $value >= 1 && $value <= 2147483647;
		}

		return is_string($value) && preg_match('/^[1-9][0-9]{0,9}$/D', $value) && (strlen($value) < 10 || strcmp($value, '2147483647') <= 0);
	}
}

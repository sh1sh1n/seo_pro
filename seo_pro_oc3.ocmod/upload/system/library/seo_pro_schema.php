<?php
/**
 * SEO Pro: creates the database schema the module needs.
 *
 * Called from startup/startup of admin and catalog while the stored
 * seo_pro_schema_version differs from VERSION. Every step checks first,
 * so a repeated run changes nothing. When install() returns false, startup
 * sets the config flag seo_pro_schema_failed for the current request and the
 * code that reads or writes main_category works without the column.
 */
class Seo_pro_schema {
	const VERSION = '1.6.1';

	private $db;

	private $log;

	private $cache;

	public function __construct($db, $log, $cache) {
		$this->db = $db;
		$this->log = $log;
		$this->cache = $cache;
	}

	/**
	 * Never throws: every failure is written to the log and the next request tries again.
	 *
	 * @return bool whether the column main_category exists and can be used by this request
	 */
	public function install(): bool {
		$ready = false;
		$locked = false;
		$step = 'lock';

		try {
			$lock = $this->db->query("SELECT GET_LOCK('seo_pro_schema', 10) AS locked");

			$locked = !empty($lock->row['locked']);

			$step = 'column check';

			if (!$locked) {
				// Another request is installing the schema right now, or the lock failed: nothing is altered without the lock
				$this->log->write('SEO Pro schema: the lock was not obtained, the next request tries again');

				$ready = $this->hasColumn('product_to_category', 'main_category');
			} else {
				if (!$this->hasColumn('product_to_category', 'main_category')) {
					$step = 'column creation';

					$this->db->query("ALTER TABLE `" . DB_PREFIX . "product_to_category` ADD `main_category` tinyint(1) NOT NULL DEFAULT '0' AFTER `category_id`");
				}

				// From here the column exists: a failure below leaves the migration unfinished, not the column missing
				$ready = true;

				$step = 'cache reset';

				// Maps built by an older version may hold wrong entries
				Seo_pro_cache::clear($this->db, $this->cache);

				// The version is the mark of a finished migration, so it is written last
				$step = 'version record';

				$this->db->query("DELETE FROM `" . DB_PREFIX . "setting` WHERE `code` = 'seo_pro' AND `key` = 'seo_pro_schema_version'");
				$this->db->query("INSERT INTO `" . DB_PREFIX . "setting` SET `store_id` = '0', `code` = 'seo_pro', `key` = 'seo_pro_schema_version', `value` = '" . $this->db->escape(self::VERSION) . "', `serialized` = '0'");
			}
		} catch (\Throwable $e) {
			$this->write('failed at the step "' . $step . '": ' . $e->getMessage());

			// The lock query itself has failed: the column may still be there
			if ($step === 'lock') {
				try {
					$ready = $this->hasColumn('product_to_category', 'main_category');
				} catch (\Throwable $e) {
					$this->write('failed at the step "column check": ' . $e->getMessage());
				}
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

		return $ready;
	}

	private function write($message) {
		try {
			$this->log->write('SEO Pro schema: ' . $message);
		} catch (\Throwable $e) {
			// The log is not available: the migration result does not depend on it
		}
	}

	private function hasColumn($table, $column) {
		$query = $this->db->query("DESCRIBE `" . DB_PREFIX . $table . "` `" . $column . "`");

		return (bool)$query->num_rows;
	}
}

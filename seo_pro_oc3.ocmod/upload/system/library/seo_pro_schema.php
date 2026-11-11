<?php
/**
 * SEO Pro: creates the database schema the module needs.
 *
 * Called from startup/startup of admin and catalog while the stored
 * seo_pro_schema_version differs from VERSION. Every step checks first,
 * so a repeated run changes nothing.
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

	public function install(): void {
		$lock = $this->db->query("SELECT GET_LOCK('seo_pro_schema', 10) AS locked");

		// Another request is installing the schema right now
		if (empty($lock->row['locked'])) {
			return;
		}

		try {
			if (!$this->hasColumn('product_to_category', 'main_category')) {
				$this->db->query("ALTER TABLE `" . DB_PREFIX . "product_to_category` ADD `main_category` tinyint(1) NOT NULL DEFAULT '0' AFTER `category_id`");
			}

			$this->db->query("DELETE FROM `" . DB_PREFIX . "setting` WHERE `code` = 'seo_pro' AND `key` = 'seo_pro_schema_version'");
			$this->db->query("INSERT INTO `" . DB_PREFIX . "setting` SET `store_id` = '0', `code` = 'seo_pro', `key` = 'seo_pro_schema_version', `value` = '" . $this->db->escape(self::VERSION) . "', `serialized` = '0'");

			// Maps built by an older version may hold wrong entries
			$this->cache->delete('seo_pro');
		} catch (\Exception $e) {
			$this->log->write('SEO Pro schema: ' . $e->getMessage());
		} finally {
			$this->db->query("SELECT RELEASE_LOCK('seo_pro_schema')");
		}
	}

	private function hasColumn($table, $column) {
		$query = $this->db->query("DESCRIBE `" . DB_PREFIX . $table . "` `" . $column . "`");

		return (bool)$query->num_rows;
	}
}

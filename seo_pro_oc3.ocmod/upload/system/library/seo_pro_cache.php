<?php
/**
 * SEO Pro: clears the caches of the module.
 *
 * The file cache deletes by prefix, Redis, Mem and Memcached delete only the
 * exact key, so every key is named.
 */
class Seo_pro_cache {
	public static function clear($db, $cache): void {
		$cache->delete('product.seopath');
		$cache->delete('category.seopath');

		// File cache: also removes the maps of deleted stores and languages
		$cache->delete('seo_pro');

		$store_ids = array(0);

		foreach ($db->query("SELECT store_id FROM `" . DB_PREFIX . "store`")->rows as $row) {
			$store_ids[] = (int)$row['store_id'];
		}

		$languages = $db->query("SELECT language_id FROM `" . DB_PREFIX . "language`")->rows;

		foreach ($store_ids as $store_id) {
			foreach ($languages as $language) {
				$cache->delete('seo_pro.' . $store_id . '.' . (int)$language['language_id']);
			}
		}
	}
}

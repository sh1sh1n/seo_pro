<?php
/**
 * SEO Pro: the cache keys of the module and their reset.
 *
 * The file cache deletes by prefix, Redis, Mem and Memcached delete only the
 * exact key, so every key is named.
 */
class Seo_pro_cache {
	/**
	 * Number of groups of the cached paths of products and of categories.
	 * An id is kept in the group id % PATH_GROUPS. clear() deletes the groups
	 * 0 .. PATH_GROUPS - 1 by name, so a smaller number would leave the old
	 * groups in Redis, Mem and Memcached until they expire: change it only
	 * together with a reset of the whole cache.
	 */
	const PATH_GROUPS = 32;

	/**
	 * Key of the group that holds the path of a product ('p') or a category ('c').
	 *
	 * The number has two digits, so the names of the groups sort in their order.
	 */
	public static function pathKey(string $type, int $id): string {
		return sprintf('seo_pro.path.%s.%02d', $type, $id % self::PATH_GROUPS);
	}

	public static function clear($db, $cache): void {
		// Keys of 1.7 and older
		$cache->delete('product.seopath');
		$cache->delete('category.seopath');

		for ($group = 0; $group < self::PATH_GROUPS; $group++) {
			$cache->delete(self::pathKey('p', $group));
			$cache->delete(self::pathKey('c', $group));
		}

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

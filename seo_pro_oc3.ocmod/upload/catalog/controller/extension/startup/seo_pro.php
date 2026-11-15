<?php
class ControllerExtensionStartupSeoPro extends Controller {
	private $cache_data = null;

	private $cache_store_id = null;

	private $cache_language_id = null;

	// Groups of the cached paths of products and categories read in this request, by cache key
	private static $path_groups = array();

	// MAX(seo_url_id) of the table, read once per request
	private $max_id = null;

	// The map differs from the table seo_url: set by the checks, the map is then built again
	private $stale = false;

	// The keywords taken from the map while an address is resolved or built; null when they are not collected
	private $hits = null;

	// Routes whose ids are turned into aliases, and the parameters each of them owns
	private $page_routes = array(
		'product/product'           => array('path', 'product_id'),
		'product/category'          => array('path'),
		'product/manufacturer/info' => array('manufacturer_id'),
		'information/information'   => array('information_id')
	);

	private $feed_routes = array(
		'extension/feed/google_sitemap',
		'extension/feed/google_base',
		'extension/feed/sitemap_ml'
	);

	public function index() {
		// The store has chosen the standard SEO URL implementation
		if ($this->config->get('config_seo_url_type') !== 'seo_pro') {
			return $this->load->controller('startup/seo_url');
		}

		// Add rewrite to url class
		if ($this->config->get('config_seo_url')) {
			$this->url->addRewrite($this);
		} else {
			return;
		}

		$this->loadCache();

		// The address, or the route when there is no address, sent as an array ("_route_[]=x", "route[]=x") is not a page.
		// A route next to an address is replaced below, whatever it is
		$key = isset($this->request->get['_route_']) ? '_route_' : 'route';

		if (isset($this->request->get[$key]) && !is_string($this->request->get[$key])) {
			unset($this->request->get['_route_']);

			$this->request->get['route'] = 'error/not_found';

			return new Action('error/not_found');
		}

		// Decode URL
		if (!isset($this->request->get['_route_'])) {
			$this->validate();
		} else {
			$route = $this->request->get['_route_'];
			unset($this->request->get['_route_']);

			if (trim($route, '/') === 'sitemap.xml') {
				$this->request->get['route'] = $this->getSitemapRoute();

				return new Action($this->request->get['route']);
			}

			$page = $this->resolveAddress($route);

			// The map is older than the table: one rebuild, then the address is resolved again
			if ($this->stale) {
				$this->loadCache(true);

				$page = $this->resolveAddress($route);
			}

			// Nothing is written to the request before the whole address is resolved,
			// and the ids a visitor has sent never choose the page
			if ($page === null) {
				$this->request->get['route'] = 'error/not_found';
			} else {
				foreach ($page['params'] as $key => $value) {
					$this->request->get[$key] = $value;
				}

				$this->request->get['route'] = $page['route'];

				if ($page['route'] === 'product/product' && !isset($this->request->get['path'])) {
					$path = $this->getPathByProduct($this->request->get['product_id']);
					if ($path) $this->request->get['path'] = $path;
				}
			}

			$this->validate();

			if (isset($this->request->get['route'])) {
				return new Action($this->request->get['route']);
			}
		}
	}

	/**
	 * The page of an address. Sets $this->stale when a keyword of the address shows that the map is out of date.
	 *
	 * @return array|null array('route' => string, 'params' => array)
	 */
	private function resolveAddress($route) {
		$this->stale = false;
		$this->hits = array();

		$path = trim(utf8_strtolower($route), '/');

		$postfix = utf8_strtolower(trim((string)$this->config->get('config_seo_url_postfix')));

		// Only addresses without a trailing slash carry the postfix: a category keyword may itself end with it
		if ($postfix !== '' && substr($route, -1) !== '/' && substr($path, -strlen($postfix)) === $postfix) {
			$path = substr($path, 0, -strlen($postfix));
		}

		$parts = explode('/', $path);

		// Part by part. The first part that is not a keyword ends the search: the rest of the address costs no queries
		$queries = array();

		foreach ($parts as $keyword) {
			$query = ($keyword === '') ? null : $this->getQueryByKeyword($keyword, true);

			if ($query === null) {
				$queries = null;

				break;
			}

			$queries[] = $query;
		}

		// The address as a whole is a keyword: used only when the address does not resolve part by part,
		// so "/cat.html" is the keyword "cat" with the postfix, and the keyword "cat.html" only when "cat" does not exist
		if ($queries === null) {
			$query = $this->getQueryByKeyword($route);

			if ($query !== null) {
				$queries = array($query);
			}
		}

		$page = ($queries === null) ? null : $this->resolveQueries($queries);

		$resolved = ($queries !== null);

		// The address is a route that has a keyword: validate() redirects it to the keyword with the current parameters
		if ($page === null && $this->getKeywordByQuery($route) !== null) {
			$page = $this->resolveQueries(array($route));

			if ($page !== null && $page['route'] !== $route) {
				$page = null;
			}

			$resolved = true;
		}

		// An address that is not made of keywords is 404 with any map: it costs no query
		if ($resolved && !$this->stale && !$this->isMapCurrent($this->hits)) {
			$this->stale = true;
		}

		$this->hits = null;

		return $page;
	}

	public function rewrite($link) {
		if (!$this->config->get('config_seo_url')) return $link;

		$this->loadCache();

		$component = parse_url(str_replace('&amp;', '&', $link));

		if ($component === false) return $link;

		$scheme = $component['scheme'] ?? '';

		$data = array();
		parse_str($component['query'] ?? '', $data);

		if (!isset($data['route']) || !is_string($data['route'])) return $link;

		$route = $data['route'];
		unset($data['route']);

		$base = ($scheme === 'https') ? $this->config->get('config_ssl') : $this->config->get('config_url');

		if ($route === 'common/home') {
			$seo_url = $base;

			if ($data) {
				$seo_url .= '?' . http_build_query($data, '', '&amp;');
			}

			return $seo_url;
		}

		switch ($route) {
			case 'product/product':
				if (isset($data['product_id']) && is_scalar($data['product_id'])) {
					$explode = explode('/', (string)$data['product_id']);

					// The module owns only path and product_id, every other parameter is kept as is
					$own = array();
					if ($this->config->get('config_seo_url_include_path')) {
						$own['path'] = $this->getPathByProduct($explode[0]);
						if (!$own['path']) return $link;
					}
					$own['product_id'] = $explode[0];

					$data = $own + array_diff_key($data, array('path' => '', 'product_id' => ''));
				}
				break;

			case 'product/category':
				if (isset($data['path']) && is_scalar($data['path'])) {
					$category = explode('_', (string)$data['path']);
					$category = end($category);
					$data['path'] = $this->getPathByCategory($category);
					if (!$data['path']) return $link;
				}
				break;

			default:
				break;
		}

		$link = $base . 'index.php?route=' . $route;

		if ($data) {
			$link .= '&amp;' . http_build_query($data, '', '&amp;');
		}

		$queries = array();
		$postfix = false;

		if (isset($this->page_routes[$route])) {
			foreach ($data as $key => $value) {
				if (!in_array($key, $this->page_routes[$route], true) || !is_scalar($value)) {
					continue;
				}

				if ($key === 'path') {
					foreach (explode('_', (string)$value) as $category) {
						$queries[] = 'category_id=' . $category;
					}
				} else {
					$queries[] = $key . '=' . $value;
					$postfix = true;
				}

				unset($data[$key]);
			}
		}

		if (empty($queries)) {
			$queries[] = $route;
		}

		$seo_url = '';
		foreach ($queries as $query) {
			$keyword = $this->getKeywordByQuery($query);

			if ($keyword === null) return $link;

			$seo_url .= '/' . rawurlencode($keyword);
		}

		$seo_url = trim($seo_url, '/');

		if ($seo_url == '') return $link;

		$seo_url = $base . $seo_url;

		if ($postfix) {
			$seo_url .= trim((string)$this->config->get('config_seo_url_postfix'));
		} else {
			$seo_url .= '/';
		}

		if (substr($seo_url, -2) == '//') {
			$seo_url = substr($seo_url, 0, -1);
		}

		if ($data) {
			$seo_url .= '?' . http_build_query($data, '', '&amp;');
		}

		return $seo_url;
	}

	/**
	 * Keyword -> query. Only looks up: the request, the headers and the route are not touched.
	 * The map is the one index() has loaded.
	 *
	 * @param bool $other_languages also look for the keyword of another language of this store, one SQL query
	 *
	 * @return string|null null when there is no such keyword; an empty query is returned as it is
	 */
	private function getQueryByKeyword($keyword, $other_languages = false) {
		$keyword = (string)$keyword;

		if (isset($this->cache_data['keywords'][$keyword])) {
			if ($this->hits !== null) {
				$this->hits[] = $keyword;
			}

			return (string)$this->cache_data['keywords'][$keyword];
		}

		if (!$other_languages) {
			return null;
		}

		$query = $this->db->query("SELECT `query`, LOWER(`keyword`) AS 'keyword', language_id FROM " . DB_PREFIX . "seo_url WHERE keyword = '" . $this->db->escape($keyword) . "' AND store_id = '" . (int)$this->config->get('config_store_id') . "' ORDER BY (language_id = '" . (int)$this->config->get('config_language_id') . "') DESC, seo_url_id LIMIT 1");

		if (!$query->row) {
			return null;
		}

		// A record of the current language that the map does not have, or has with another query.
		// The record is compared, not the address: the database may find "café" for "cafe", and the map has "café"
		if ((int)$query->row['language_id'] === $this->cache_language_id) {
			$known = $this->cache_data['keywords'][$query->row['keyword']] ?? null;

			if ($known === null || (string)$known !== (string)$query->row['query']) {
				$this->stale = true;
			}
		}

		return (string)$query->row['query'];
	}

	/**
	 * Compares keywords taken from the map with the table seo_url, one query by the index of keyword.
	 * Finds a keyword or a query changed in place and a deleted record, which MAX(seo_url_id) does not show.
	 *
	 * @param array $keywords keys of the map
	 *
	 * @return bool false when the map is out of date
	 */
	private function isMapCurrent(array $keywords) {
		if (!$keywords) {
			return true;
		}

		$in = array();

		foreach ($keywords as $keyword) {
			$in[(string)$keyword] = "'" . $this->db->escape((string)$keyword) . "'";
		}

		$query = $this->db->query("SELECT LOWER(`keyword`) AS 'keyword', `query` FROM " . DB_PREFIX . "seo_url WHERE store_id = '" . (int)$this->cache_store_id . "' AND language_id = '" . (int)$this->cache_language_id . "' AND keyword IN (" . implode(', ', $in) . ") ORDER BY seo_url_id");

		$found = array();

		foreach ($query->rows as $row) {
			// As in the map, a keyword belongs to its first record
			if (isset($found[$row['keyword']])) {
				continue;
			}

			$found[$row['keyword']] = true;

			if (!isset($this->cache_data['keywords'][$row['keyword']]) || (string)$this->cache_data['keywords'][$row['keyword']] !== (string)$row['query']) {
				return false;
			}
		}

		foreach ($keywords as $keyword) {
			if (!isset($found[$keyword])) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Query -> keyword of the current store and language, from the map index() or rewrite() has loaded.
	 *
	 * @return string|null null when the query has no keyword
	 */
	private function getKeywordByQuery($query) {
		$query = (string)$query;

		if (isset($this->cache_data['queries'][$query])) {
			if ($this->hits !== null) {
				$this->hits[] = (string)$this->cache_data['queries'][$query];
			}

			return (string)$this->cache_data['queries'][$query];
		}

		return null;
	}

	/**
	 * The kinds of seo_url.query the module supports. The whole string must match, nothing is cut or corrected.
	 *
	 * @return array|null array('type' => 'category_id'|'product_id'|'manufacturer_id'|'information_id', 'id' => string)
	 *                    or array('type' => 'route', 'route' => string); null for every other string
	 */
	private function classifyQuery($query) {
		if (!is_string($query)) {
			return null;
		}

		if (preg_match('/^(category_id|product_id|manufacturer_id|information_id)=([1-9][0-9]{0,9})$/D', $query, $match)) {
			// The ids of the stock schema are signed INT
			return Seo_pro_schema::isId($match[2]) ? array('type' => $match[1], 'id' => $match[2]) : null;
		}

		if (preg_match('/^[A-Za-z0-9_]+(?:\/[A-Za-z0-9_]+)*$/D', $query)) {
			return array('type' => 'route', 'route' => $query);
		}

		return null;
	}

	/**
	 * The page a sequence of queries opens: categories, categories and a product, one manufacturer,
	 * one information page or one route. Every other sequence is not a page.
	 *
	 * @return array|null array('route' => string, 'params' => array)
	 */
	private function resolveQueries(array $queries) {
		$categories = array();
		$entity = null;

		foreach ($queries as $query) {
			$item = $this->classifyQuery($query);

			// Nothing follows a product, a manufacturer or an information page
			if ($item === null || $entity !== null) {
				return null;
			}

			if ($item['type'] === 'route') {
				return (count($queries) === 1) ? array('route' => $item['route'], 'params' => array()) : null;
			}

			if ($item['type'] === 'category_id') {
				if (in_array($item['id'], $categories, true)) {
					return null;
				}

				$categories[] = $item['id'];
			} else {
				$entity = $item;
			}
		}

		if ($entity === null) {
			return $categories ? array('route' => 'product/category', 'params' => array('path' => implode('_', $categories))) : null;
		}

		if ($entity['type'] === 'product_id') {
			$params = $categories ? array('path' => implode('_', $categories)) : array();

			return array('route' => 'product/product', 'params' => $params + array('product_id' => $entity['id']));
		}

		if ($categories) {
			return null;
		}

		if ($entity['type'] === 'manufacturer_id') {
			return array('route' => 'product/manufacturer/info', 'params' => array('manufacturer_id' => $entity['id']));
		}

		return array('route' => 'information/information', 'params' => array('information_id' => $entity['id']));
	}

	/**
	 * @param bool $rebuild build the map from the table even when the cached one looks current
	 */
	private function loadCache($rebuild = false) {
		$store_id = (int)$this->config->get('config_store_id');
		$language_id = (int)$this->config->get('config_language_id');

		if (!$rebuild && $this->cache_data !== null && $this->cache_store_id === $store_id && $this->cache_language_id === $language_id) {
			return;
		}

		// Read before the records: a record added in between only makes the next request build the map again
		if ($this->max_id === null || $rebuild) {
			$query = $this->db->query("SELECT MAX(seo_url_id) AS max_id FROM " . DB_PREFIX . "seo_url");

			$this->max_id = (int)($query->row['max_id'] ?? 0);
		}

		$this->cache_store_id = $store_id;
		$this->cache_language_id = $language_id;

		$cache_key = 'seo_pro.' . $store_id . '.' . $language_id;

		$cache_data = $rebuild ? null : $this->cache->get($cache_key);

		// A record added past the models of the core (an import, another extension) changes MAX(seo_url_id)
		if (!is_array($cache_data) || !isset($cache_data['max_id']) || (int)$cache_data['max_id'] !== $this->max_id) {
			$query = $this->db->query("SELECT LOWER(`keyword`) AS 'keyword', `query` FROM " . DB_PREFIX . "seo_url WHERE store_id = '" . $store_id . "' AND language_id = '" . $language_id . "' ORDER BY seo_url_id");

			$cache_data = array('max_id' => $this->max_id, 'keywords' => array(), 'queries' => array());

			foreach ($query->rows as $row) {
				// The keyword already belongs to an earlier query: this query keeps a plain link
				if (isset($cache_data['keywords'][$row['keyword']])) {
					continue;
				}

				$cache_data['keywords'][$row['keyword']] = $row['query'];
				$cache_data['queries'][$row['query']] = $row['keyword'];
			}

			$this->cache->set($cache_key, $cache_data);
		}

		$this->cache_data = $cache_data;
	}

	private function getPathByProduct($product_id) {
		$product_id = (int)$product_id;
		if ($product_id < 1) return false;

		$path = $this->getPathGroup('p', $product_id);

		if (!isset($path[$product_id])) {
			// Without the column main_category the first category of the product is used
			$order = $this->config->get('seo_pro_schema_failed') ? '' : " ORDER BY main_category DESC";

			$query = $this->db->query("SELECT category_id FROM " . DB_PREFIX . "product_to_category WHERE product_id = '" . (int)$product_id . "'" . $order . " LIMIT 1");
			$path[$product_id] = $this->getPathByCategory($query->num_rows ? (int)$query->row['category_id'] : 0);

			$this->setPathGroup('p', $product_id, $path);
		}

		return $path[$product_id];
	}

	private function getPathByCategory($category_id) {
		$category_id = (int)$category_id;
		if ($category_id < 1) return false;

		$path = $this->getPathGroup('c', $category_id);

		if (!isset($path[$category_id])) {
			$query = $this->db->query("SELECT GROUP_CONCAT(path_id ORDER BY level SEPARATOR '_') AS path FROM " . DB_PREFIX . "category_path WHERE category_id = '" . $category_id . "'");

			$path[$category_id] = ($query->num_rows && $query->row['path'] !== null) ? $query->row['path'] : '';

			$this->setPathGroup('c', $category_id, $path);
		}

		return $path[$category_id];
	}

	// The cached paths are split into Seo_pro_cache::PATH_GROUPS groups: a miss rewrites only the group of its id,
	// and each group is read from the cache once per request
	private function getPathGroup($type, $id) {
		$key = Seo_pro_cache::pathKey($type, $id);

		if (!isset(self::$path_groups[$key])) {
			$group = $this->cache->get($key);

			self::$path_groups[$key] = is_array($group) ? $group : array();
		}

		return self::$path_groups[$key];
	}

	private function setPathGroup($type, $id, $group) {
		$key = Seo_pro_cache::pathKey($type, $id);

		self::$path_groups[$key] = $group;

		$this->cache->set($key, $group);
	}

	private function validate() {
		if (php_sapi_name() === 'cli') {
			return;
		}

		if (isset($this->request->get['route']) && $this->request->get['route'] == 'error/not_found') {
			return;
		}

		if (ltrim($this->request->server['REQUEST_URI'] ?? '', '/') == 'sitemap.xml') {
			$this->request->get['route'] = $this->getSitemapRoute();

			return;
		}

		if (empty($this->request->get['route'])) {
			$this->request->get['route'] = 'common/home';
		}

		if (in_array($this->request->get['route'], $this->feed_routes, true)) {
			return;
		}

		// A redirect would lose the body of POST and other non-GET requests
		if (!$this->isRedirectable()) {
			return;
		}

		if (isset($this->request->server['HTTP_X_REQUESTED_WITH']) && strtolower($this->request->server['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
			return;
		}

		if (isset($this->request->get['page']) && !is_array($this->request->get['page']) && (float)$this->request->get['page'] < 1) {
			unset($this->request->get['page']);
		}

		$https = $this->isHttps();

		$base = $https ? $this->config->get('config_ssl') : $this->config->get('config_url');
		// "https://host/": up to the third slash. A value with fewer slashes keeps its first character, as it always did.
		$base = preg_match('~^(?:[^/]*/){3}~', $base, $match) ? $match[0] : substr($base, 0, 1);

		$url = str_replace('&amp;', '&', $base . ltrim($this->request->server['REQUEST_URI'] ?? '', '/'));
		$this->hits = array();

		$seo = str_replace('&amp;', '&', $this->url->link($this->request->get['route'], $this->getQueryString(array('route')), $https));

		$hits = $this->hits;
		$this->hits = null;

		// urldecode() on both sides: a space may come as "+" or as "%20"
		if (urldecode($url) != urldecode($seo)) {
			// A permanent redirect is not sent to a keyword that is changed or deleted in the table
			if (!$this->isMapCurrent($hits)) {
				$this->loadCache(true);

				$seo = str_replace('&amp;', '&', $this->url->link($this->request->get['route'], $this->getQueryString(array('route')), $https));

				if (urldecode($url) == urldecode($seo)) {
					return;
				}
			}

			$this->response->redirect($seo, 301);
		}
	}

	private function getSitemapRoute() {
		if ($this->config->get('feed_sitemap_ml_status')) {
			return 'extension/feed/sitemap_ml';
		} else {
			return 'extension/feed/google_sitemap';
		}
	}

	private function isRedirectable(): bool {
		$method = strtoupper($this->request->server['REQUEST_METHOD'] ?? 'GET');

		return $method === 'GET' || $method === 'HEAD';
	}

	private function isHttps(): bool {
		return !empty($this->request->server['HTTPS']) && strtolower((string)$this->request->server['HTTPS']) !== 'off';
	}

	private function getQueryString($exclude = array()) {
		if (!is_array($exclude)) {
			$exclude = array();
		}

		$data = array_diff_key($this->request->get, array_flip($exclude));

		// Request::clean() has applied htmlspecialchars() to the names and to the values: restore what the visitor sent
		return http_build_query($this->decodeQuery($data), '', '&');
	}

	// Exactly one layer is removed, the one Request::clean() has added
	private function decodeQuery($data) {
		if (!is_array($data)) {
			return htmlspecialchars_decode((string)$data, ENT_COMPAT);
		}

		$decoded = array();

		foreach ($data as $key => $value) {
			if (is_string($key)) {
				$key = htmlspecialchars_decode($key, ENT_COMPAT);
			}

			$decoded[$key] = $this->decodeQuery($value);
		}

		return $decoded;
	}
}

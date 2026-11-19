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

	// The router of the core with the modifications of other extensions: false until it is asked for, null when it cannot be loaded
	private $core = false;

	// Links the router of the core was asked for in this request: the address, or false when the core has none for the link
	private $core_links = array();

	// rewrite() of the core is running
	private $core_busy = false;

	// rewrite() of the core has built an address since the flag was cleared
	private $core_used = false;

	// Links are built without the router of the core
	private $core_off = false;

	// The names of the parameters the router of the core has set for the address of this request; null for every other request
	private $core_params = null;

	// The ids the core itself reads from seo_url.query
	private $own_keys = array('category_id', 'product_id', 'manufacturer_id', 'information_id');

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
			if (($this->request->get['route'] ?? null) === 'product/product' && $this->isRedirectable()) {
				$this->handleProductGetHead($this->request->get);
			} else {
				$this->validate();
			}
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

			// An address with a record of another extension is a page only when the router of the core, as modified, says so
			if ($page !== null && isset($page['core'])) {
				$page = $this->resolveByCore($route);

				// A page of the module the core has read in a mixed address ("/blog-category/product") is built by the module, as any other
				if ($page !== null && !isset($this->page_routes[$page['route']])) {
					$this->core_params = array_keys($page['params']);
				}
			}

			// Nothing is written to the request before the whole address is resolved,
			// and the ids a visitor has sent never choose the page
			if ($page === null) {
				$this->request->get['route'] = 'error/not_found';
			} else {
				// The parameters of the page come first, in the order of the address: a modification of the core
				// writes the parts of a link in the order of its parameters, the visitor must not choose it
				$this->request->get = $page['params'] + $this->request->get;

				$this->request->get['route'] = $page['route'];

				$original_request = $this->request->get;

				if ($page['route'] === 'product/product' && isset($this->request->get['product_id']) && !isset($this->request->get['path'])) {
					$path = $this->getPathByProduct($this->request->get['product_id']);
					if ($path) $this->request->get['path'] = $path;
				}
			}

			if (($this->request->get['route'] ?? null) === 'product/product' && $this->isRedirectable()) {
				$this->handleProductGetHead($original_request);
			} else {
				$this->validate();
			}

			if (isset($this->request->get['route'])) {
				return new Action($this->request->get['route']);
			}
		}
	}

	/**
	 * The page of an address. Sets $this->stale when a keyword of the address shows that the map is out of date.
	 *
	 * @return array|null array('route' => string, 'params' => array),
	 *                    or array('core' => true): the address has a record of another extension and is left to the router of the core
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

		if ($queries === null) {
			$page = null;
		} elseif ($this->hasForeign($queries)) {
			$page = array('core' => true);
		} else {
			$page = $this->resolveQueries($queries);
		}

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

		$component = parse_url(str_replace('&amp;', '&', $link));

		if ($component === false) return $link;

		$scheme = $component['scheme'] ?? '';

		$data = array();
		parse_str($component['query'] ?? '', $data);

		if (!isset($data['route']) || !is_string($data['route'])) return $link;

		$route = $data['route'];
		unset($data['route']);

		if ($route === 'product/product') {
			if (!isset($data['product_id']) || !Seo_pro_schema::isId($data['product_id'])) return $link;
			return $this->buildProductLink((int)$data['product_id'], $data, $scheme === 'https');
		}

		$this->loadCache();

		$base = ($scheme === 'https') ? $this->config->get('config_ssl') : $this->config->get('config_url');

		if ($route === 'common/home') {
			$seo_url = $base;

			if ($data) {
				$seo_url .= '?' . http_build_query($data, '', '&amp;');
			}

			return $seo_url;
		}

		switch ($route) {
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

		// The store has records of other extensions, and the link has parameters one of them may own.
		// Asked before the keyword of the route itself: such an extension may build "/list/item" from a route and an id
		if ($data && !$this->core_off && !empty($this->cache_data['foreign']) && !isset($this->page_routes[$route])) {
			$seo_url = $this->rewriteByCore($base, $route, $data);

			if ($seo_url !== null) {
				return $seo_url;
			}
		}

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
	 * The kinds of seo_url.query the module opens itself. The whole string must match, nothing is cut or corrected.
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
	 * A record of another extension: not a record of the module, and not one the unmodified core reads as its own id.
	 * A wrong record of the module ("product_id=0", "product_id=1x") and an empty query are not foreign, they answer 404.
	 */
	private function isForeign($query) {
		if (!is_string($query) || $query === '' || $this->classifyQuery($query) !== null) {
			return false;
		}

		$part = explode('=', $query, 2);

		return !in_array($part[0], $this->own_keys, true);
	}

	/**
	 * @return bool true when a query is foreign and every other one is a record of the module or foreign
	 */
	private function hasForeign(array $queries) {
		$foreign = false;

		foreach ($queries as $query) {
			if ($this->isForeign($query)) {
				$foreign = true;
			} elseif ($this->classifyQuery($query) === null) {
				return false;
			}
		}

		return $foreign;
	}

	/**
	 * ControllerStartupSeoUrl with the modifications of other extensions, loaded the way the loader of the core does.
	 * Built only in a store that has foreign records.
	 *
	 * @return object|null
	 */
	private function getCore() {
		if ($this->core === false) {
			$this->core = null;

			if (!class_exists('ControllerStartupSeoUrl', false)) {
				$file = DIR_APPLICATION . 'controller/startup/seo_url.php';

				if (function_exists('modification')) {
					$file = modification($file);
				}

				if (is_file($file)) {
					include_once($file);
				}
			}

			if (class_exists('ControllerStartupSeoUrl', false)) {
				$this->core = new ControllerStartupSeoUrl($this->registry);
			}
		}

		return $this->core;
	}

	/**
	 * The page the router of the core gives to an address. The core sees the address as it was sent and nothing else:
	 * the parameters of the visitor are put back after it, under the ones the core has set.
	 *
	 * @return array|null array('route' => string, 'params' => array)
	 */
	private function resolveByCore($route) {
		$core = $this->getCore();

		if ($core === null || !is_callable(array($core, 'index'))) {
			return null;
		}

		$get = $this->request->get;
		$url = $this->registry->get('url');

		// index() of the core adds its rewrite() to the url class: every link would then cost SQL queries.
		// It gets an object that ignores this call and passes every other one to the url class
		$this->registry->set('url', new class($url) {
			private $url;

			public function __construct($url) {
				$this->url = $url;
			}

			public function addRewrite($rewrite) {
			}

			public function __call($method, $args) {
				return call_user_func_array(array($this->url, $method), $args);
			}
		});

		$this->request->get = array('_route_' => $route);

		try {
			$core->index();

			$params = $this->request->get;
		} finally {
			$this->request->get = $get;
			$this->registry->set('url', $url);
		}

		if (!is_array($params) || !isset($params['route']) || !is_string($params['route']) || $params['route'] === 'error/not_found') {
			return null;
		}

		// The unmodified core puts the record itself ("blog_id=3") into the route: not a page
		$item = $this->classifyQuery($params['route']);

		if ($item === null || $item['type'] !== 'route') {
			return null;
		}

		unset($params['_route_'], $params['route']);

		return array('route' => $item['route'], 'params' => $params);
	}

	/**
	 * The address rewrite() of the core, as modified, builds for a link. The core chooses the keywords,
	 * the parameters it has left are written the way the module writes them.
	 * The core does not get "path": it reads it as categories in a link of any route.
	 *
	 * @param string $base  the address of the store
	 * @param string $route a route that is not a page of the module
	 * @param array  $data  the parameters of the link without the route
	 *
	 * @return string|null null when the core has no address for the link
	 */
	private function rewriteByCore($base, $route, array $data) {
		$params = array_diff_key($data, array('path' => ''));

		// rewrite() of a modification has asked for a link itself
		if (!$params || $this->core_busy) {
			return null;
		}

		$link = $base . 'index.php?route=' . $route . '&amp;' . http_build_query($params, '', '&amp;');

		$key = $this->cache_language_id . ' ' . $link . (isset($data['path']) ? ' ' . http_build_query(array('path' => $data['path'])) : '');

		if (!isset($this->core_links[$key])) {
			$this->core_links[$key] = false;

			$core = $this->getCore();

			$component = parse_url(str_replace('&amp;', '&', $link));

			// rewrite() of the core reads the scheme and the host of the link without a check
			if ($core !== null && is_callable(array($core, 'rewrite')) && isset($component['scheme']) && isset($component['host'])) {
				$this->core_busy = true;

				try {
					$seo_url = $core->rewrite($link);
				} finally {
					$this->core_busy = false;
				}

				if (is_string($seo_url) && $seo_url !== '' && $seo_url !== $link) {
					$part = explode('?', $seo_url, 2);

					$rest = array();

					if (isset($part[1])) {
						parse_str(str_replace('&amp;', '&', $part[1]), $rest);
					}

					// In the order of the link; a parameter the modification has added comes last
					$rest = array_intersect_key($data, $rest + array('path' => '')) + $rest;

					$this->core_links[$key] = $part[0] . ($rest ? '?' . http_build_query($rest, '', '&amp;') : '');
				}
			}
		}

		if ($this->core_links[$key] === false) {
			return null;
		}

		$this->core_used = true;

		return $this->core_links[$key];
	}

	/**
	 * An address rewrite() of the core has built opens the given route. A modification may turn a parameter
	 * into keywords in a link of any route and drop the route, as the core does with "path":
	 * "index.php?route=checkout/cart&blog_path=1" then becomes the address of a blog category.
	 *
	 * @param string $seo_url an address of this store
	 */
	private function opensRoute($seo_url, $route) {
		$path = (string)parse_url($seo_url, PHP_URL_PATH);

		$base = (string)parse_url((string)$this->config->get('config_url'), PHP_URL_PATH);

		if ($base !== '' && strpos($path, $base) === 0) {
			$path = substr($path, strlen($base));
		}

		$path = rawurldecode(ltrim($path, '/'));

		if ($path === '') {
			return false;
		}

		$page = $this->resolveByCore($path);

		return $page !== null && $page['route'] === $route;
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
		// A map of 1.7.2 does not say whether the store has records of other extensions
		if (!is_array($cache_data) || !isset($cache_data['max_id']) || !isset($cache_data['foreign']) || (int)$cache_data['max_id'] !== $this->max_id) {
			$query = $this->db->query("SELECT LOWER(`keyword`) AS 'keyword', `query` FROM " . DB_PREFIX . "seo_url WHERE store_id = '" . $store_id . "' AND language_id = '" . $language_id . "' ORDER BY seo_url_id");

			$cache_data = array('max_id' => $this->max_id, 'foreign' => false, 'keywords' => array(), 'queries' => array());

			foreach ($query->rows as $row) {
				if (!$cache_data['foreign'] && $this->isForeign((string)$row['query'])) {
					$cache_data['foreign'] = true;
				}

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

	private $product_addresses = array();
	private $product_categories = array();

	private function addressContext() {
		return (int)$this->config->get('config_store_id') . ':' . (int)$this->config->get('config_language_id') . ':';
	}

	// The self-JOIN includes every query type. Only the optional CI prefilter changes with metadata.
	private function addressAliasJoin($query) {
		$store = (int)$this->config->get('config_store_id');
		$language = (int)$this->config->get('config_language_id');
		$ci = Seo_pro_schema::keywordPrefilterMode($this->config, $this->db, $this->log) === 'ci';
		return " LEFT JOIN " . DB_PREFIX . "seo_url su ON su.store_id = " . $store . " AND su.language_id = " . $language .
			" AND su.`query` = " . $query . " AND CAST(su.`query` AS BINARY) = CAST(" . $query . " AS BINARY)" .
			" LEFT JOIN " . DB_PREFIX . "seo_url d ON d.store_id = su.store_id AND d.language_id = su.language_id" .
			($ci ? " AND d.keyword = su.keyword" : "") .
			" AND CAST(LOWER(d.keyword) AS BINARY) = CAST(LOWER(su.keyword) AS BINARY) AND d.seo_url_id < su.seo_url_id";
	}

	private function selectOwnedKeyword($rows, $query) {
		$candidates = array();
		foreach ($rows as $row) {
			if ($row['alias_id'] === null || $row['alias_query'] !== $query) continue;
			$id = (int)$row['alias_id'];
			if (!isset($candidates[$id])) $candidates[$id] = array('keyword' => $row['alias_keyword'], 'blocked' => false);
			if ($row['earlier_alias_id'] !== null) $candidates[$id]['blocked'] = true;
		}
		ksort($candidates, SORT_NUMERIC);
		$keyword = null;
		foreach ($candidates as $candidate) if (!$candidate['blocked']) $keyword = $candidate['keyword'];
		return $keyword;
	}

	private function readProductAddressData($product_id) {
		$key = $this->addressContext() . $product_id;
		if (array_key_exists($key, $this->product_addresses)) return $this->product_addresses[$key];
		$pc = !$this->config->get('seo_pro_schema_failed');
		$sql = "SELECT p.product_id, " . ($pc ? "pc.category_id, pc.main_category" : "NULL AS category_id, 0 AS main_category") .
			", su.seo_url_id AS alias_id, su.`query` AS alias_query, LOWER(su.keyword) AS alias_keyword, d.seo_url_id AS earlier_alias_id" .
			" FROM (SELECT " . (int)$product_id . " AS product_id) p" .
			($pc ? " LEFT JOIN " . DB_PREFIX . "product_to_category pc ON pc.product_id = p.product_id" : "") .
			$this->addressAliasJoin("CONCAT('product_id=', p.product_id)") .
			" ORDER BY su.seo_url_id" . ($pc ? ", pc.category_id" : "") . ", d.seo_url_id";
		$rows = $this->db->query($sql)->rows;
		$main = array();
		foreach ($rows as $row) if ($row['category_id'] !== null && (int)$row['main_category'] === 1) $main[(int)$row['category_id']] = true;
		return $this->product_addresses[$key] = array('keyword' => $this->selectOwnedKeyword($rows, 'product_id=' . (int)$product_id), 'main_category_id' => count($main) === 1 ? (int)key($main) : null);
	}

	private function readProductCategoryData($category_id, $incoming = false) {
		$key = $this->addressContext() . $category_id;
		if (array_key_exists($key, $this->product_categories)) return $this->product_categories[$key];
		$store = (int)$this->config->get('config_store_id');
		$language = (int)$this->config->get('config_language_id');
		$sql = "SELECT root.category_id AS requested_category_id, cp.path_id, cp.level, c.category_id AS existing_category_id, c.parent_id, c.status," .
			" cs.store_id AS available_store_id, cd.category_id AS translated_category_id, su.seo_url_id AS alias_id, su.`query` AS alias_query," .
			" LOWER(su.keyword) AS alias_keyword, d.seo_url_id AS earlier_alias_id FROM (SELECT " . (int)$category_id . " AS category_id) root" .
			" LEFT JOIN " . DB_PREFIX . "category_path cp ON cp.category_id = root.category_id" .
			" LEFT JOIN " . DB_PREFIX . "category c ON c.category_id = cp.path_id" .
			" LEFT JOIN " . DB_PREFIX . "category_to_store cs ON cs.category_id = cp.path_id AND cs.store_id = " . $store .
			" LEFT JOIN " . DB_PREFIX . "category_description cd ON cd.category_id = cp.path_id AND cd.language_id = " . $language .
			$this->addressAliasJoin("CONCAT('category_id=', cp.path_id)") .
			" ORDER BY cp.level, su.seo_url_id, d.seo_url_id" . ($incoming ? " /* incoming_path */" : "");
		$rows = $this->db->query($sql)->rows;
		$result = array('valid' => false, 'path' => '', 'keywords' => array());
		$segments = array(); $levels = array();
		foreach ($rows as $row) {
			if ($row['path_id'] === null || $row['existing_category_id'] === null || (int)$row['status'] !== 1 || $row['available_store_id'] === null || $row['translated_category_id'] === null) return $this->product_categories[$key] = $result;
			$id = (int)$row['path_id']; $level = (int)$row['level'];
			if ((isset($levels[$level]) && $levels[$level] !== $id) || (isset($segments[$id]) && (int)$segments[$id]['level'] !== $level)) return $this->product_categories[$key] = $result;
			$levels[$level] = $id; $segments[$id] = $row;
		}
		ksort($levels, SORT_NUMERIC); $parent = 0; $level = 0;
		foreach ($levels as $n => $id) {
			if ($n !== $level++ || (int)$segments[$id]['parent_id'] !== $parent) return $this->product_categories[$key] = $result;
			$parent = $id;
		}
		if (!$levels || $parent !== (int)$category_id) return $this->product_categories[$key] = $result;
		$result['valid'] = true;
		$result['path'] = implode('_', $levels);
		foreach ($levels as $id) $result['keywords'][] = $this->selectOwnedKeyword(array_values(array_filter($rows, function ($row) use ($id) { return (int)$row['path_id'] === $id; })), 'category_id=' . $id);
		return $this->product_categories[$key] = $result;
	}

	private function buildProductBaseAddress($product_id, $https) {
		$product = $this->readProductAddressData($product_id);
		$category = null;
		if ($this->config->get('config_seo_url_include_path') && $product['main_category_id']) $category = $this->readProductCategoryData($product['main_category_id']);
		$path = $category && $category['valid'] ? $category['path'] : '';
		$keywords = $path ? $category['keywords'] : array();
		$keywords[] = $product['keyword'];
		$base = $https ? $this->config->get('config_ssl') : $this->config->get('config_url');
		$hits = array();
		if (!in_array(null, $keywords, true) && !in_array('', $keywords, true)) {
			$hits = $keywords;
			return array('url' => $base . implode('/', array_map('rawurlencode', $keywords)) . trim((string)$this->config->get('config_seo_url_postfix')), 'params' => array(), 'hits' => $hits);
		}
		$params = array('route' => 'product/product');
		if ($path) $params['path'] = $path;
		$params['product_id'] = $product_id;
		return array('url' => $base . 'index.php', 'params' => $params, 'hits' => array());
	}

	private function buildProductLink($product_id, $extras, $https) {
		$address = $this->buildProductBaseAddress($product_id, $https);
		if ($this->hits !== null) $this->hits = array_merge($this->hits, $address['hits']);
		$extras = array_diff_key($extras, array_flip(array('route', 'path', 'product_id', 'manufacturer_id', 'sort', 'order', 'page', 'limit')));
		$params = $address['params'] + $extras;
		$query = '';
		if (isset($params['route'])) { $query = 'route=' . $params['route']; unset($params['route']); }
		if ($params) $query .= ($query !== '' ? '&amp;' : '') . http_build_query($params, '', '&amp;');
		return $address['url'] . ($query !== '' ? '?' . $query : '');
	}

	private function getPathByProduct($product_id) {
		if (!Seo_pro_schema::isId($product_id)) return false;
		$product = $this->readProductAddressData((int)$product_id);
		if (!$product['main_category_id']) return false;
		$category = $this->readProductCategoryData($product['main_category_id']);
		return $category['valid'] ? $category['path'] : false;
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

	private function productNotFound() {
		$this->request->get['route'] = 'error/not_found';
	}

	private function handleProductGetHead($original) {
		if (php_sapi_name() === 'cli' || ($original['route'] ?? null) === 'error/not_found' || !$this->isRedirectable()) return;
		if (!isset($original['product_id']) || !Seo_pro_schema::isId($original['product_id'])) { $this->productNotFound(); return; }
		$id = (int)$original['product_id'];
		$https = $this->isHttps();
		// The base the stock Url selects: config_ssl only for a secure request with a nonempty value, else config_url.
		// Its origin is taken, REQUEST_URI already carries the subdirectory of the store
		$base = ($https && $this->config->get('config_ssl')) ? $this->config->get('config_ssl') : $this->config->get('config_url');
		$base = preg_match('~^(?:[^/]*/){3}~', (string)$base, $match) ? $match[0] : substr((string)$base, 0, 1);
		$url = str_replace('&amp;', '&', $base . ltrim($this->request->server['REQUEST_URI'] ?? '', '/'));
		// The arguments of the link: the parameters of the request with the one layer of Request::clean() removed, once,
		// without the route and with the validated id. Url serializes them, every rewriter of the chain parses them
		$args = $this->decodeQuery($original);
		unset($args['route']);
		$args['product_id'] = $id;
		// One build through the registered Url chain. Request GET and Registry Url are not touched; the transient fields
		// are scoped to the build and returned, their entry values are back on every exit
		$build = function () use ($args, $https) {
			$entry_hits = $this->hits;
			$entry_core_used = $this->core_used;
			$this->hits = array();
			$this->core_used = false;
			try {
				$seo = str_replace('&amp;', '&', $this->url->link('product/product', $args, $https));
				$hits = $this->hits;
				$core_used = $this->core_used;
			} finally {
				$this->hits = $entry_hits;
				$this->core_used = $entry_core_used;
			}
			return array($seo, $hits, $core_used);
		};
		list($seo, $hits, $core_used) = $build();
		if (urldecode($url) === urldecode($seo)) return;
		// At most one freshness rebuild and one core-disabled rebuild, the target is compared after each, no loop
		if (!$this->isMapCurrent($hits)) {
			$this->loadCache(true);
			list($seo, $hits, $core_used) = $build();
			if (urldecode($url) === urldecode($seo)) return;
		}
		if ($core_used && !$this->opensRoute($seo, 'product/product')) {
			$core_off = $this->core_off;
			$this->core_off = true;
			try { list($seo, $hits, $core_used) = $build(); }
			finally { $this->core_off = $core_off; }
			if (urldecode($url) === urldecode($seo)) return;
		}
		$this->load->model('catalog/product');
		$product = $this->model_catalog_product->getProduct($id);
		if (!$product) { $this->productNotFound(); return; }
		if (array_key_exists('manufacturer_id', $original) && (!Seo_pro_schema::isId($original['manufacturer_id']) || (int)$original['manufacturer_id'] !== (int)$product['manufacturer_id'])) { $this->productNotFound(); return; }
		if (array_key_exists('path', $original)) {
			$path = $original['path'];
			if (!is_string($path) || !preg_match('/^[1-9][0-9]*(?:_[1-9][0-9]*)*$/D', $path)) { $this->productNotFound(); return; }
			$ids = explode('_', $path);
			foreach ($ids as $category) if (!Seo_pro_schema::isId($category)) { $this->productNotFound(); return; }
			$category = $this->readProductCategoryData((int)end($ids), true);
			if (!$category['valid'] || $category['path'] !== $path) { $this->productNotFound(); return; }
		}
		$this->response->redirect($seo, 301);
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
		$this->core_used = false;

		$seo = $this->getCanonical($https);

		$hits = $this->hits;
		$this->hits = null;

		// urldecode() on both sides: a space may come as "+" or as "%20"
		if (urldecode($url) != urldecode($seo)) {
			// A permanent redirect is not sent to a keyword that is changed or deleted in the table
			if (!$this->isMapCurrent($hits)) {
				$this->loadCache(true);

				$seo = $this->getCanonical($https);

				if (urldecode($url) == urldecode($seo)) {
					return;
				}
			}

			// The address comes from the router of the core: a parameter of the visitor must not send the request to another page
			if ($this->core_used && !$this->opensRoute($seo, $this->request->get['route'])) {
				$this->core_off = true;

				try {
					$seo = $this->getCanonical($https);
				} finally {
					$this->core_off = false;
				}

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

	/**
	 * The address of the page of this request with its parameters.
	 * A page of the router of the core is built from the parameters the core has read in the address, and the parameters
	 * of the visitor follow as a query string: a modification of the core may turn any of its parameters into a part
	 * of the address, or drop the address when one of them has no keyword.
	 */
	private function getCanonical($https) {
		$route = $this->request->get['route'];

		if ($this->core_params === null) {
			return str_replace('&amp;', '&', $this->url->link($route, $this->getQueryString(array('route')), $https));
		}

		$page = array_intersect_key($this->request->get, array_flip($this->core_params));
		unset($page['route']);

		$seo = str_replace('&amp;', '&', $this->url->link($route, http_build_query($this->decodeQuery($page), '', '&'), $https));

		$rest = $this->getQueryString(array_merge(array('route'), $this->core_params));

		if ($rest !== '') {
			$seo .= ((strpos($seo, '?') === false) ? '?' : '&') . $rest;
		}

		return $seo;
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

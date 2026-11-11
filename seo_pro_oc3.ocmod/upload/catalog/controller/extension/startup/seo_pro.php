<?php
class ControllerExtensionStartupSeoPro extends Controller {
	private $cache_data = null;

	private $cache_store_id = null;

	private $cache_language_id = null;

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

		// Decode URL
		if (!isset($this->request->get['_route_'])) {
			$this->validate();
		} else {
			$route_ = $route = $this->request->get['_route_'];
			unset($this->request->get['_route_']);

			if (trim($route, '/') === 'sitemap.xml') {
				$this->request->get['route'] = $this->getSitemapRoute();

				return new Action($this->request->get['route']);
			}

			$path = trim(utf8_strtolower($route), '/');

			$postfix = utf8_strtolower(trim((string)$this->config->get('config_seo_url_postfix')));

			// Only addresses without a trailing slash carry the postfix: a category keyword may itself end with it
			if ($postfix !== '' && substr($route, -1) !== '/' && substr($path, -strlen($postfix)) === $postfix) {
				$path = substr($path, 0, -strlen($postfix));
			}

			$parts = explode('/', $path);

			$rows = array();
			foreach ($parts as $keyword) {
				if (isset($this->cache_data['keywords'][$keyword])) {
					$rows[] = array('keyword' => $keyword, 'query' => $this->cache_data['keywords'][$keyword]);
				} elseif ($keyword != '') {
					$query_multilang = $this->db->query("SELECT `query` FROM " . DB_PREFIX . "seo_url WHERE keyword = '" . $this->db->escape($keyword) . "' AND store_id = '" . (int)$this->config->get('config_store_id') . "' ORDER BY (language_id = '" . (int)$this->config->get('config_language_id') . "') DESC, seo_url_id LIMIT 1");
					if ($query_multilang->row) $rows[] = array('keyword' => $keyword, 'query' => $query_multilang->row['query']);
				}
			}

			// The address as a whole is a keyword: used only when the address does not resolve part by part,
			// so "/cat.html" is the keyword "cat" with the postfix, and the keyword "cat.html" only when "cat" does not exist
			if (count($rows) != sizeof($parts) && isset($this->cache_data['keywords'][$route])) {
				$keyword = $route;
				$parts = array($keyword);
				$rows = array(array('keyword' => $keyword, 'query' => $this->cache_data['keywords'][$keyword]));
			}

			if (count($rows) == sizeof($parts)) {
				$queries = array();
				foreach ($rows as $row) {
					$queries[utf8_strtolower($row['keyword'])] = $row['query'];
				}

				reset($parts);
				foreach ($parts as $part) {
					if (!isset($queries[$part])) return false;
					$url = explode('=', $queries[$part], 2);

					if ($url[0] == 'category_id') {
						if (!isset($this->request->get['path'])) {
							$this->request->get['path'] = $url[1];
						} else {
							$this->request->get['path'] .= '_' . $url[1];
						}
					} elseif (count($url) > 1) {
						$this->request->get[$url[0]] = $url[1];
					}
				}
			} else {
				$this->request->get['route'] = 'error/not_found';
			}

			if (isset($this->request->get['product_id'])) {
				$this->request->get['route'] = 'product/product';
				if (!isset($this->request->get['path'])) {
					$path = $this->getPathByProduct($this->request->get['product_id']);
					if ($path) $this->request->get['path'] = $path;
				}
			} elseif (isset($this->request->get['path'])) {
				$this->request->get['route'] = 'product/category';
			} elseif (isset($this->request->get['manufacturer_id'])) {
				$this->request->get['route'] = 'product/manufacturer/info';
			} elseif (isset($this->request->get['information_id'])) {
				$this->request->get['route'] = 'information/information';
			} elseif (isset($this->cache_data['queries'][$route_])) {
				// The address is a route that has a keyword: validate() redirects it to the keyword with the current parameters
				$this->request->get['route'] = $route_;
			} else {
				if (isset($queries[$parts[0]])) {
					$this->request->get['route'] = $queries[$parts[0]];
				}
			}

			$this->validate();

			if (isset($this->request->get['route'])) {
				return new Action($this->request->get['route']);
			}
		}
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

		$rows = array();
		foreach ($queries as $query) {
			if (isset($this->cache_data['queries'][$query])) {
				$rows[] = array('query' => $query, 'keyword' => $this->cache_data['queries'][$query]);
			}
		}

		if (count($rows) != count($queries)) return $link;

		$aliases = array();
		foreach ($rows as $row) {
			$aliases[$row['query']] = $row['keyword'];
		}

		$seo_url = '';
		foreach ($queries as $query) {
			$seo_url .= '/' . rawurlencode($aliases[$query]);
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

	private function loadCache() {
		$store_id = (int)$this->config->get('config_store_id');
		$language_id = (int)$this->config->get('config_language_id');

		if ($this->cache_data !== null && $this->cache_store_id === $store_id && $this->cache_language_id === $language_id) {
			return;
		}

		$this->cache_store_id = $store_id;
		$this->cache_language_id = $language_id;

		$cache_key = 'seo_pro.' . $store_id . '.' . $language_id;

		$cache_data = $this->cache->get($cache_key);

		if (!is_array($cache_data)) {
			$query = $this->db->query("SELECT LOWER(`keyword`) AS 'keyword', `query` FROM " . DB_PREFIX . "seo_url WHERE store_id = '" . $store_id . "' AND language_id = '" . $language_id . "' ORDER BY seo_url_id");

			$cache_data = array('keywords' => array(), 'queries' => array());

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

		static $path = null;
		if (!isset($path)) {
			$path = $this->cache->get('product.seopath');
			if (!is_array($path)) {
				$path = array();
			}
		}

		if (!isset($path[$product_id])) {
			// Without the column main_category the first category of the product is used
			$order = $this->config->get('seo_pro_schema_failed') ? '' : " ORDER BY main_category DESC";

			$query = $this->db->query("SELECT category_id FROM " . DB_PREFIX . "product_to_category WHERE product_id = '" . (int)$product_id . "'" . $order . " LIMIT 1");
			$path[$product_id] = $this->getPathByCategory($query->num_rows ? (int)$query->row['category_id'] : 0);

			$this->cache->set('product.seopath', $path);
		}

		return $path[$product_id];
	}

	private function getPathByCategory($category_id) {
		$category_id = (int)$category_id;
		if ($category_id < 1) return false;

		static $path = null;
		if (!isset($path)) {
			$path = $this->cache->get('category.seopath');
			if (!is_array($path)) {
				$path = array();
			}
		}

		if (!isset($path[$category_id])) {
			$query = $this->db->query("SELECT GROUP_CONCAT(path_id ORDER BY level SEPARATOR '_') AS path FROM " . DB_PREFIX . "category_path WHERE category_id = '" . $category_id . "'");

			$path[$category_id] = ($query->num_rows && $query->row['path'] !== null) ? $query->row['path'] : '';

			$this->cache->set('category.seopath', $path);
		}

		return $path[$category_id];
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
		$base = substr($base, 0, $this->strpos_offset('/', $base, 3) + 1);

		$url = str_replace('&amp;', '&', $base . ltrim($this->request->server['REQUEST_URI'] ?? '', '/'));
		$seo = str_replace('&amp;', '&', $this->url->link($this->request->get['route'], $this->getQueryString(array('route')), $https));

		// urldecode() on both sides: a space may come as "+" or as "%20"
		if (urldecode($url) != urldecode($seo)) {
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

	private function strpos_offset($needle, $haystack, $occurrence) {
		// explode the haystack
		$arr = explode($needle, $haystack);
		// check the needle is not out of bounds
		switch($occurrence) {
			case $occurrence == 0:
				return false;
			case $occurrence > max(array_keys($arr)):
				return false;
			default:
				return strlen(implode($needle, array_slice($arr, 0, $occurrence)));
		}
	}

	private function getQueryString($exclude = array()) {
		if (!is_array($exclude)) {
			$exclude = array();
		}

		$data = array_diff_key($this->request->get, array_flip($exclude));

		// Request::clean() has applied htmlspecialchars(): restore the values the visitor sent
		return http_build_query($this->decodeValues($data), '', '&');
	}

	private function decodeValues($data) {
		if (is_array($data)) {
			foreach ($data as $key => $value) {
				$data[$key] = $this->decodeValues($value);
			}

			return $data;
		}

		return htmlspecialchars_decode((string)$data, ENT_COMPAT);
	}
}

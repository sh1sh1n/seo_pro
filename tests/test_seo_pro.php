<?php
// Stubs and helpers for cases.php. A failed check, a PHP warning or an unfinished run ends as HTTP 500 (see cases.php).
error_reporting(E_ALL);
set_error_handler(function ($no, $str, $file, $line) { echo "  !! PHP NOTICE: $str at " . basename($file) . ":$line\n"; $GLOBALS['notices']++; return true; });
$GLOBALS['notices'] = 0;
if (!getenv('OC_DIR')) { http_response_code(500); exit("set OC_DIR to opencart-3.0.5.1/upload\n"); }
$OC = rtrim(getenv('OC_DIR'), '/') . '/';
define('DB_PREFIX', 'oc_');
require $OC . 'system/helper/utf8.php';
require $OC . 'system/library/url.php';
require $OC . 'system/library/request.php';
// The module loads the router of the core the way the core loads a controller: the file changed by OCMOD when there is one.
// SEO_PRO_MODIFICATION: a directory with the files ocmod_sim.php has written, set by the case that needs a modified core
define('DIR_APPLICATION', $OC . 'catalog/');
define('DIR_MODIFICATION', defined('SEO_PRO_MODIFICATION') ? rtrim(SEO_PRO_MODIFICATION, '/') . '/' : $OC . 'system/storage/modification/');
function modification($filename) { $file = DIR_MODIFICATION . 'catalog/' . substr($filename, strlen(DIR_APPLICATION)); return is_file($file) ? $file : $filename; }

class Action { public $route; function __construct($r) { $this->route = $r; } }
class Registry { private $d = []; function get($k) { return $this->d[$k] ?? null; } function set($k, $v) { $this->d[$k] = $v; } }
abstract class Controller { protected $registry; function __construct($r) { $this->registry = $r; } function __get($k) { return $this->registry->get($k); } }
class Config { public $d = []; function get($k) { return $this->d[$k] ?? null; } function set($k, $v) { $this->d[$k] = $v; } }
// Keeps the values as JSON like the file cache does, and records the keys it was asked for
class Cache { public $d = []; public $gets = []; public $sets = []; function get($k) { $this->gets[] = $k; return $this->d[$k] ?? null; } function set($k, $v) { $this->sets[] = $k; $this->d[$k] = json_decode(json_encode($v), true); } function delete($k) {} }
class Redirect extends Exception { public $url; public $status; function __construct($u, $s) { $this->url = $u; $this->status = $s; } }
class Response { public $headers = []; function addHeader($h) { $this->headers[] = $h; } function redirect($url, $status = 302) { throw new Redirect(str_replace('&amp;', '&', $url), $status); } }
class Session { public $data = ['language' => 'en-gb']; }
class ProductModel { public $calls = 0; public $products = []; function getProduct($id) { $this->calls++; return $this->products[$id] ?? ['product_id' => $id, 'manufacturer_id' => 5]; } }
class Loader { public $registry; function __construct($r) { $this->registry = $r; } function model($route) { if ($route !== 'catalog/product') throw new Exception('unexpected model'); } public $calls = []; function controller($r) { $this->calls[] = $r; return 'delegated:' . $r; } }
class Res { public $rows; public $row; public $num_rows; function __construct($rows) { $this->rows = $rows; $this->row = $rows[0] ?? []; $this->num_rows = count($rows); } }
class DB {
	public $categories = []; public $main = []; public $collation = 'utf8mb4_unicode_ci'; public $seo = []; public $p2c = []; public $paths = []; public $log = [];
	function escape($s) { return addslashes($s); }
	function query($sql) {
		$this->log[] = $sql;
		if (strpos($sql, 'SHOW FULL COLUMNS') === 0) return new Res([['Type' => 'varchar(255)', 'Collation' => $this->collation]]);
		if (preg_match('~FROM \(SELECT (\d+) AS (product_id|category_id)\)~', $sql, $m)) {
			$id = (int)$m[1]; preg_match('~su.store_id = (\d+)~', $sql, $st); preg_match('~su.language_id = (\d+)~', $sql, $la);
			$aliases = function ($query) use ($st, $la) {
				$out = [];
				foreach ($this->seo as $i => $row) if ($row[0] == $st[1] && $row[1] == $la[1] && $row[2] === $query) {
					$earlier = null;
					foreach ($this->seo as $j => $d) if (($d[4] ?? $j + 1) < ($row[4] ?? $i + 1) && $d[0] == $row[0] && $d[1] == $row[1] && utf8_strtolower($d[3]) === utf8_strtolower($row[3])) { $earlier = $d[4] ?? $j + 1; break; }
					$out[] = ['alias_id' => $row[4] ?? $i + 1, 'alias_query' => $row[2], 'alias_keyword' => utf8_strtolower($row[3]), 'earlier_alias_id' => $earlier];
				}
				return $out ?: [['alias_id' => null, 'alias_query' => null, 'alias_keyword' => null, 'earlier_alias_id' => null]];
			};
			$out = [];
			if ($m[2] === 'product_id') {
				$cats = strpos($sql, 'JOIN oc_product_to_category') !== false ? ($this->main[$id] ?? (isset($this->p2c[$id]) ? [$this->p2c[$id] => 1] : [])) : [];
				foreach ($cats ?: [0 => 0] as $cat => $main) foreach ($aliases('product_id=' . $id) as $a) $out[] = ['product_id' => $id, 'category_id' => $cat ?: null, 'main_category' => $main] + $a;
			} else {
				foreach (isset($this->paths[$id]) ? explode('_', $this->paths[$id]) : [] as $level => $cat) {
					$c = $this->categories[$cat] ?? null;
					foreach ($aliases('category_id=' . $cat) as $a) $out[] = ['requested_category_id' => $id, 'path_id' => $cat, 'level' => $level, 'existing_category_id' => $c ? $cat : null, 'parent_id' => $c['parent_id'] ?? null, 'status' => $c['status'] ?? 0, 'available_store_id' => $c && !empty($c['store']) ? (int)$st[1] : null, 'translated_category_id' => $c && !empty($c['language']) ? $cat : null] + $a;
				}
			}
			return new Res($out);
		}
		// The router of the core: a keyword of any language of the store, a query of the current language
		if (preg_match("~^SELECT \* FROM oc_seo_url WHERE keyword = '(.*)' AND store_id = '(\d+)'$~", $sql, $m)) {
			foreach ($this->seo as $i => $row) if ($row[3] === stripslashes($m[1]) && $row[0] == $m[2]) return new Res([['seo_url_id' => $row[4] ?? $i + 1, 'store_id' => $row[0], 'language_id' => $row[1], 'query' => $row[2], 'keyword' => $row[3]]]); return new Res([]);
		}
		if (preg_match("~^SELECT \* FROM oc_seo_url WHERE `query` = '(.*)' AND store_id = '(\d+)' AND language_id = '(\d+)'$~", $sql, $m)) {
			foreach ($this->seo as $i => $row) if ($row[2] === stripslashes($m[1]) && $row[0] == $m[2] && $row[1] == $m[3]) return new Res([['seo_url_id' => $row[4] ?? $i + 1, 'store_id' => $row[0], 'language_id' => $row[1], 'query' => $row[2], 'keyword' => $row[3]]]); return new Res([]);
		}
		if (strpos($sql, 'SELECT MAX(seo_url_id) AS max_id FROM oc_seo_url') === 0) return new Res([['max_id' => $this->seo ? max(array_keys($this->seo)) + 1 : null]]);
		if (preg_match("~FROM oc_seo_url WHERE store_id = '(\d+)' AND language_id = '(\d+)' AND keyword IN \((.*)\) ORDER BY seo_url_id$~", $sql, $m)) {
			$in = array_map(fn($k) => stripslashes(substr($k, 1, -1)), explode(', ', $m[3]));
			$r = []; foreach ($this->seo as $row) if ($row[0] == $m[1] && $row[1] == $m[2] && in_array(strtolower($row[3]), $in, true)) $r[] = ['keyword' => strtolower($row[3]), 'query' => $row[2]]; return new Res($r);
		}
		if (preg_match("~FROM oc_seo_url WHERE store_id = '(\d+)' AND language_id = '(\d+)'~", $sql, $m)) {
			$r = []; foreach ($this->seo as $row) if ($row[0] == $m[1] && $row[1] == $m[2]) $r[] = ['keyword' => strtolower($row[3]), 'query' => $row[2]]; return new Res($r);
		}
		if (preg_match("~FROM oc_seo_url WHERE keyword = '(.*)' AND store_id = '(\d+)'~", $sql, $m)) {
			// The current language first, as ORDER BY (language_id = ...) DESC does
			preg_match("~language_id = '(\d+)'\) DESC~", $sql, $l); $seo = $this->seo; uasort($seo, fn($a, $b) => [$a[1] != $l[1], 0] <=> [$b[1] != $l[1], 0]);
			foreach ($seo as $row) if ($row[3] === stripslashes($m[1]) && $row[0] == $m[2]) return new Res([['query' => $row[2], 'keyword' => strtolower($row[3]), 'language_id' => (string)$row[1]]]); return new Res([]);
		}
		if (preg_match("~product_to_category WHERE product_id = '(\d+)'~", $sql, $m)) return new Res(isset($this->p2c[$m[1]]) ? [['category_id' => $this->p2c[$m[1]]]] : []);
		if (preg_match("~category_path WHERE category_id = '(\d+)'~", $sql, $m)) return new Res([['path' => $this->paths[$m[1]] ?? null]]);
		throw new Exception("unexpected SQL $sql");
	}
}
// OpenCart loads the library with its autoloader
require __DIR__ . '/../seo_pro_oc3.ocmod/upload/system/library/seo_pro_cache.php';
require_once __DIR__ . '/../seo_pro_oc3.ocmod/upload/system/library/seo_pro_schema.php';
require __DIR__ . '/../seo_pro_oc3.ocmod/upload/catalog/controller/extension/startup/seo_pro.php';

function env($opts = []) {
	$r = new Registry();
	$c = new Config(); $c->d = ['config_seo_url_type' => 'seo_pro', 'config_seo_url' => 1, 'config_store_id' => 0, 'config_language_id' => 1,
		'config_url' => 'http://shop.test/', 'config_ssl' => 'https://shop.test/', 'config_seo_url_postfix' => '.html', 'config_seo_url_include_path' => 1] + ($opts['config'] ?? []);
	foreach ($opts['config'] ?? [] as $k => $v) $c->d[$k] = $v;
	// 'db' and 'cache' of an earlier request: the table and the cached map live on between requests
	if (isset($opts['db'])) { $db = $opts['db']; $db->log = []; goto ready; }
	$db = new DB();
	// store, lang, query, keyword. The id of a record is its index + 1
	$db->seo = [[0,1,'product_id=1','tyre'], [0,1,'product_id=2','road.tyre'], [0,1,'category_id=10','moto'], [0,1,'category_id=11','enduro'],
		[0,1,'manufacturer_id=5','heidenau'], [0,1,'information_id=4','about'], [0,1,'information/contact','contact'], [0,1,'product/search','search'],
		[1,1,'product_id=3','foo'], [0,2,'product_id=1','shina'], [0,1,'product_id=6','tyre']];
	$deep = []; for ($i = 100; $i < 112; $i++) { $deep[] = $i; $db->seo[] = [0,1,"category_id=$i","c$i"]; }
	$db->seo[] = [0,1,'product_id=9','deep'];
	// 1.6.2: category keywords with and without the postfix, a feed route with a keyword
	array_push($db->seo, [0,1,'category_id=30','cat'], [0,1,'category_id=31','cat.html'], [0,1,'category_id=32','sub.html'], [0,1,'extension/feed/google_sitemap','gsitemap'],
		[0,1,'product_id=41','box'], [0,1,'category_id=33','box.html'], [0,1,'product_id=42','legacy.html']);
	foreach ($opts['seo'] ?? [] as $row) $db->seo[] = $row;
	$db->p2c = [1 => 11, 2 => 10, 9 => 111, 77 => 10];
	$db->paths = [10 => '10', 11 => '10_11', 111 => implode('_', $deep), 30 => '30', 31 => '31', 32 => '31_32', 33 => '33'];
	foreach ($deep as $i => $id) $db->paths[$id] = implode('_', array_slice($deep, 0, $i + 1));
	foreach ($db->paths as $path) { $parent = 0; foreach (explode('_', $path) as $cat) { $db->categories[$cat] = ['parent_id' => $parent, 'status' => 1, 'store' => 1, 'language' => 1]; $parent = (int)$cat; } }
	ready:
	$url = new Url($c->get('config_url'), $c->get('config_ssl'));
	$req = new Request(); $req->get = $req->clean($opts['get'] ?? []); $req->post = $opts['post'] ?? []; $req->server = ($opts['server'] ?? []) + ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/'];
	foreach (['config' => $c, 'db' => $db, 'cache' => $opts['cache'] ?? new Cache(), 'url' => $url, 'request' => $req, 'response' => new Response(), 'session' => new Session(), 'load' => new Loader($r), 'model_catalog_product' => new ProductModel()] as $k => $v) $r->set($k, $v);
	return $r;
}
$fail = 0;
function check($name, $got, $exp) { global $fail; $ok = $got === $exp; if (!$ok) $fail++; echo ($ok ? 'ok   ' : 'FAIL ') . $name . ($ok ? '' : "\n       got: " . var_export($got, true) . "\n       exp: " . var_export($exp, true)) . "\n"; }
function boot($opts) { $r = env($opts); $c = new ControllerExtensionStartupSeoPro($r); return [$r, $c]; }
// validate() returns at once under the CLI SAPI, so the cases run under the built-in server.
// run(): one request. Returns [what the test sees, the registry]. $opts: config, server, seo (extra rows), post.
function run($uri, $method = 'GET', $opts = []) {
	$p = parse_url($uri); parse_str($p['query'] ?? '', $get);
	$path = ltrim($p['path'], '/');
	if ($path !== '' && $path !== 'index.php') $get['_route_'] = rawurldecode($path);
	$opts['get'] = $get; $opts['server'] = ($opts['server'] ?? []) + ['REQUEST_URI' => $uri, 'REQUEST_METHOD' => $method];
	[$r, $c] = boot($opts);
	try { $res = $c->index(); } catch (Redirect $e) { return [['redirect' => $e->url, 'status' => $e->status], $r]; }
	return [['route' => $r->get('request')->get['route'] ?? null, 'get' => $r->get('request')->get, 'res' => $res instanceof Action ? $res->route : $res], $r];
}
function req($uri, $method = 'GET', $config = [], $server = [], $seo = []) { return run($uri, $method, ['config' => $config, 'server' => $server, 'seo' => $seo])[0]; }
function link_($route, $args = '', $config = [], $seo = []) { [$r, $c] = boot(['config' => $config, 'seo' => $seo]); $c->index(); return $r->get('url')->link($route, $args); }
// SQL of the request, by kind. 'core': the queries of the router of the core and of its modifications
function sql($r, $kind) { $re = ['map' => "~FROM oc_seo_url WHERE store_id = '\d+' AND language_id = '\d+' ORDER BY~", 'fallback' => '~^SELECT `query`.* FROM oc_seo_url WHERE keyword~', 'core' => '~^SELECT \* FROM oc_seo_url~', 'max' => '~SELECT MAX\(seo_url_id\)~', 'verify' => '~AND keyword IN~', 'product_address' => '~FROM \(SELECT \d+ AS product_id\)~', 'category_address' => '~FROM \(SELECT \d+ AS category_id\)~', 'incoming_path' => '~incoming_path~', 'schema_metadata' => '~^SHOW FULL COLUMNS~', 'foreign_core' => '~^SELECT \* FROM oc_seo_url~', 'all' => '~~'][$kind]; return count(preg_grep($re, $r->get('db')->log)); }
// Follows the redirects of a GET request. Returns [number of redirects, last result]; more than 5 redirects is a loop.
function follow($uri, $opts = []) {
	for ($n = 0; $n <= 5; $n++) {
		[$x] = run($uri, 'GET', $opts);
		if (!isset($x['redirect'])) return [$n, $x];
		$p = parse_url($x['redirect']); $uri = ($p['path'] ?? '/') . (isset($p['query']) ? '?' . $p['query'] : '');
	}
	return ['loop', $x];
}
// The parameters the visitor sent, as PHP parses them
function params($x) { $g = $x['get'] ?? []; unset($g['route'], $g['path'], $g['product_id'], $g['manufacturer_id'], $g['information_id']); return json_decode(json_encode($g), true); }
// The index in DB::$seo of a keyword of store 0, language 1
function row($db, $keyword) { foreach ($db->seo as $i => $row) if ($row[0] == 0 && $row[1] == 1 && $row[3] === $keyword) return $i; throw new Exception("no keyword $keyword"); }
// A new request: the groups of paths read by the previous one are forgotten
function new_request() { $p = new ReflectionProperty('ControllerExtensionStartupSeoPro', 'path_groups'); if (PHP_VERSION_ID < 80100) $p->setAccessible(true); $p->setValue(null, []); }
function call($c, $method, ...$args) { $m = new ReflectionMethod($c, $method); if (PHP_VERSION_ID < 80100) $m->setAccessible(true); return $m->invoke($c, ...$args); }

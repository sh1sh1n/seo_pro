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

class Action { public $route; function __construct($r) { $this->route = $r; } }
class Registry { private $d = []; function get($k) { return $this->d[$k] ?? null; } function set($k, $v) { $this->d[$k] = $v; } }
abstract class Controller { protected $registry; function __construct($r) { $this->registry = $r; } function __get($k) { return $this->registry->get($k); } }
class Config { public $d = []; function get($k) { return $this->d[$k] ?? null; } }
class Cache { public $d = []; function get($k) { return $this->d[$k] ?? null; } function set($k, $v) { $this->d[$k] = json_decode(json_encode($v), true); } function delete($k) {} }
class Redirect extends Exception { public $url; public $status; function __construct($u, $s) { $this->url = $u; $this->status = $s; } }
class Response { public $headers = []; function addHeader($h) { $this->headers[] = $h; } function redirect($url, $status = 302) { throw new Redirect(str_replace('&amp;', '&', $url), $status); } }
class Session { public $data = ['language' => 'en-gb']; }
class Loader { public $calls = []; function controller($r) { $this->calls[] = $r; return 'delegated:' . $r; } }
class Res { public $rows; public $row; public $num_rows; function __construct($rows) { $this->rows = $rows; $this->row = $rows[0] ?? []; $this->num_rows = count($rows); } }
class DB {
	public $seo = []; public $p2c = []; public $paths = []; public $log = [];
	function escape($s) { return addslashes($s); }
	function query($sql) {
		$this->log[] = $sql;
		if (preg_match("~FROM oc_seo_url WHERE store_id = '(\d+)' AND language_id = '(\d+)'~", $sql, $m)) {
			$r = []; foreach ($this->seo as $row) if ($row[0] == $m[1] && $row[1] == $m[2]) $r[] = ['keyword' => strtolower($row[3]), 'query' => $row[2]]; return new Res($r);
		}
		if (preg_match("~FROM oc_seo_url WHERE keyword = '(.*)' AND store_id = '(\d+)'~", $sql, $m)) {
			foreach ($this->seo as $row) if ($row[3] === stripslashes($m[1]) && $row[0] == $m[2]) return new Res([['query' => $row[2]]]); return new Res([]);
		}
		if (preg_match("~product_to_category WHERE product_id = '(\d+)'~", $sql, $m)) return new Res(isset($this->p2c[$m[1]]) ? [['category_id' => $this->p2c[$m[1]]]] : []);
		if (preg_match("~category_path WHERE category_id = '(\d+)'~", $sql, $m)) return new Res([['path' => $this->paths[$m[1]] ?? null]]);
		throw new Exception("unexpected SQL $sql");
	}
}
require __DIR__ . '/../seo_pro_oc3.ocmod/upload/catalog/controller/extension/startup/seo_pro.php';

function env($opts = []) {
	$r = new Registry();
	$c = new Config(); $c->d = ['config_seo_url_type' => 'seo_pro', 'config_seo_url' => 1, 'config_store_id' => 0, 'config_language_id' => 1,
		'config_url' => 'http://shop.test/', 'config_ssl' => 'https://shop.test/', 'config_seo_url_postfix' => '.html', 'config_seo_url_include_path' => 1] + ($opts['config'] ?? []);
	foreach ($opts['config'] ?? [] as $k => $v) $c->d[$k] = $v;
	$db = new DB();
	// store, lang, query, keyword
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
	$url = new Url($c->get('config_url'), $c->get('config_ssl'));
	$req = new Request(); $req->get = $req->clean($opts['get'] ?? []); $req->post = $opts['post'] ?? []; $req->server = ($opts['server'] ?? []) + ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/'];
	foreach (['config' => $c, 'db' => $db, 'cache' => new Cache(), 'url' => $url, 'request' => $req, 'response' => new Response(), 'session' => new Session(), 'load' => new Loader()] as $k => $v) $r->set($k, $v);
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
// SQL of the request, by kind
function sql($r, $kind) { $re = ['map' => '~FROM oc_seo_url WHERE store_id~', 'fallback' => '~FROM oc_seo_url WHERE keyword~', 'all' => '~~'][$kind]; return count(preg_grep($re, $r->get('db')->log)); }
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
function call($c, $method, ...$args) { $m = new ReflectionMethod($c, $method); if (PHP_VERSION_ID < 80100) $m->setAccessible(true); return $m->invoke($c, ...$args); }

<?php
// F01, F04, F05: every reset point clears the module caches on a cache that deletes only the exact key (Redis, Mem, Memcached).
// usage: php test_cache.php <dir with files produced by ocmod_sim.php>. Exit code 1 when a check fails.
error_reporting(E_ALL); define('DB_PREFIX', 'oc_');
if (empty($argv[1])) { fwrite(STDERR, "usage: php test_cache.php <ocmod_sim out dir>\n"); exit(2); }
$out = rtrim($argv[1], '/');
require __DIR__ . '/../seo_pro_oc3.ocmod/upload/system/library/seo_pro_cache.php';

class Res { public $rows; public $row; public $num_rows; function __construct($rows = []) { $this->rows = $rows; $this->row = $rows[0] ?? []; $this->num_rows = count($rows); } }
class DB { public $log = [];
	function escape($s) { return addslashes($s); }
	function getLastId() { return 1; }
	function query($sql) { $this->log[] = $sql;
		if (strpos($sql, 'SELECT store_id FROM `oc_store`') === 0) return new Res([['store_id' => 1], ['store_id' => 2]]);
		if (strpos($sql, 'SELECT language_id FROM `oc_language`') === 0) return new Res([['language_id' => 1], ['language_id' => 2]]);
		return new Res(); } }
class ExactCache { public $d = [];
	function get($k) { return $this->d[$k] ?? null; } function set($k, $v) { $this->d[$k] = $v; } function delete($k) { unset($this->d[$k]); }
	function warm() { $this->d = ['product.seopath' => 1, 'category.seopath' => 1, 'other' => 1]; foreach ([0, 1, 2] as $s) foreach ([1, 2] as $l) $this->d["seo_pro.$s.$l"] = 1; }
	function left() { return implode(',', array_keys($this->d)); } }
class Any { function __call($n, $a) { return true; } function __get($k) { return $this; } }
class Stop extends Exception {}
class Response { function redirect($u) { throw new Stop(); } }
class Session { public $data = ['user_token' => 't']; }
class Request { public $get = []; public $post = []; public $server = ['REQUEST_METHOD' => 'GET']; }
class Registry { private $d = []; function get($k) { return $this->d[$k] ?? null; } function set($k, $v) { $this->d[$k] = $v; } }
abstract class Base { protected $registry; function __construct($r) { $this->registry = $r; } function __get($k) { return $this->registry->get($k) ?? new Any(); } }
abstract class Model extends Base {}
abstract class Controller extends Base {}

$fail = 0;
function check($name, $got, $exp) { global $fail; $ok = $got === $exp; if (!$ok) $fail++; echo ($ok ? 'ok   ' : 'FAIL ') . $name . ($ok ? '' : "\n       got: " . var_export($got, true) . "\n       exp: " . var_export($exp, true)) . "\n"; }
function reg() { $r = new Registry(); foreach (['db' => new DB(), 'cache' => new ExactCache(), 'response' => new Response(), 'session' => new Session(), 'request' => new Request()] as $k => $v) $r->set($k, $v); $r->get('cache')->warm(); return $r; }

echo "-- F05: Seo_pro_cache::clear on an exact-key cache\n";
$r = reg(); Seo_pro_cache::clear($r->get('db'), $r->get('cache'));
check('only foreign keys left', $r->get('cache')->left(), 'other');

echo "\n-- F01: Design > SEO URL (modified admin/model/design/seo_url.php)\n";
require $out . '/admin/model/design/seo_url.php';
$data = ['store_id' => 0, 'language_id' => 1, 'query' => 'product_id=1', 'keyword' => 'new-tyre'];
foreach (['addSeoUrl' => [$data], 'editSeoUrl' => [5, $data], 'deleteSeoUrl' => [5]] as $method => $args) {
	$r = reg(); (new ModelDesignSeoUrl($r))->$method(...$args);
	check($method . ' clears the caches', $r->get('cache')->left(), 'other');
	$sql = $r->get('db')->log;
	check($method . ' clears after the write', strpos($sql[0], 'oc_seo_url') !== false && strpos($sql[1], 'oc_store') !== false, true);
}

echo "\n-- F04: Repair (modified admin/controller/catalog/category.php)\n";
require $out . '/admin/controller/catalog/category.php';
class RepairModel { public $calls = 0; public $cache; public $seen;
	function repairCategories() { $this->calls++; $this->seen = $this->cache->left(); } }
$r = reg(); $m = new RepairModel(); $m->cache = $r->get('cache'); $r->set('model_catalog_category', $m);
try { (new ControllerCatalogCategory($r))->repair(); } catch (Stop $e) {}
check('repairCategories called once', $m->calls, 1);
check('caches were warm during repair', strpos($m->seen, 'category.seopath') !== false, true);
check('caches cleared after repair', $r->get('cache')->left(), 'other');
check('cleared once', count(preg_grep('~oc_store~', $r->get('db')->log)), 1);

echo "\n-- other reset points (modified admin models)\n";
foreach (['product' => ['ModelCatalogProduct', 'deleteProduct'], 'category' => ['ModelCatalogCategory', 'deleteCategory'], 'manufacturer' => ['ModelCatalogManufacturer', 'deleteManufacturer'], 'information' => ['ModelCatalogInformation', 'deleteInformation']] as $file => [$class, $method]) {
	require $out . '/admin/model/catalog/' . $file . '.php';
	$r = reg(); (new $class($r))->$method(7);
	check("$class::$method clears the caches", $r->get('cache')->left(), 'other');
}
foreach (['product', 'category', 'manufacturer', 'information'] as $file) {
	$src = file_get_contents($out . '/admin/model/catalog/' . $file . '.php');
	check("$file: a clear after each core delete", substr_count($src, 'Seo_pro_cache::clear($this->db, $this->cache);'), 3);
}

echo "\nfailed: $fail\n";
exit($fail ? 1 : 0);

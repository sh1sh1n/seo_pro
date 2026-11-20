<?php
// F01, F04, F05: every reset point clears the module caches on a cache that deletes only the exact key (Redis, Mem, Memcached).
// usage: php test_cache.php <dir with files produced by ocmod_sim.php>. Exit code 1 when a check fails.
error_reporting(E_ALL); define('DB_PREFIX', 'oc_');
if (empty($argv[1])) { fwrite(STDERR, "usage: php test_cache.php <ocmod_sim out dir>\n"); exit(2); }
$out = rtrim($argv[1], '/');
require __DIR__ . '/../seo_pro_oc3.ocmod/upload/system/library/seo_pro_cache.php';
// OpenCart loads the library with its autoloader
require __DIR__ . '/../seo_pro_oc3.ocmod/upload/system/library/seo_pro_schema.php';

class Res { public $rows; public $row; public $num_rows; function __construct($rows = []) { $this->rows = $rows; $this->row = $rows[0] ?? []; $this->num_rows = count($rows); } }
class DB { public $log = [];
	function escape($s) { return addslashes($s); }
	function getLastId() { return 1; }
	function query($sql) { $this->log[] = $sql;
		if (strpos($sql, 'SELECT store_id FROM `oc_store`') === 0) return new Res([['store_id' => 1], ['store_id' => 2]]);
		if (strpos($sql, 'SELECT language_id FROM `oc_language`') === 0) return new Res([['language_id' => 1], ['language_id' => 2]]);
		return new Res(); } }
// Every key the module writes: the maps of each store and language, the groups of paths, the keys of 1.7 and older
function module_keys() { $k = ['product.seopath', 'category.seopath']; for ($g = 0; $g < 32; $g++) { $k[] = sprintf('seo_pro.path.p.%02d', $g); $k[] = sprintf('seo_pro.path.c.%02d', $g); } foreach ([0, 1, 2] as $s) foreach ([1, 2] as $l) { $k[] = "seo_pro.$s.$l"; for ($g = 0; $g < 64; $g++) $k[] = sprintf('seo_pro.%d.%d.k.%02d', $s, $l, $g); } return $k; }
class ExactCache { public $d = []; public $del = [];
	function get($k) { return $this->d[$k] ?? null; } function set($k, $v) { $this->d[$k] = $v; } function delete($k) { $this->del[] = $k; unset($this->d[$k]); }
	function warm() { $this->d = array_fill_keys(module_keys(), 1) + ['other' => 1]; }
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
check('32 groups of paths', Seo_pro_cache::PATH_GROUPS, 32);
check('64 groups of keywords of a map', Seo_pro_cache::KEYWORD_GROUPS, 64);
check('the key of a group of keywords is the one the controller reads', [Seo_pro_cache::keywordKey(0, 1, 'moto'), Seo_pro_cache::keywordKey(2, 3, 'moto')], [sprintf('seo_pro.0.1.k.%02d', crc32('moto') % 64), sprintf('seo_pro.2.3.k.%02d', crc32('moto') % 64)]);
$del = $r->get('cache')->del;
check('each group of products and of categories deleted by its name', array_values(array_intersect(module_keys(), $del)), module_keys());
check('groups 00..31 of both kinds, nothing else under seo_pro.path', count(preg_grep('~^seo_pro\.path\.[pc]\.(0\d|1\d|2\d|3[01])$~', $del)) === 64 && count(preg_grep('~^seo_pro\.path\.~', $del)) === 64, true);
check('keys of 1.7 deleted', array_values(array_intersect(['product.seopath', 'category.seopath'], $del)), ['product.seopath', 'category.seopath']);
check('the key of every group is the one the controller writes', array_map(fn($id) => Seo_pro_cache::pathKey('p', $id), [1, 31, 32, 63, 1000]), ['seo_pro.path.p.01', 'seo_pro.path.p.31', 'seo_pro.path.p.00', 'seo_pro.path.p.31', 'seo_pro.path.p.08']);

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
check('caches were warm during repair', strpos($m->seen, 'seo_pro.path.c.00') !== false, true);
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

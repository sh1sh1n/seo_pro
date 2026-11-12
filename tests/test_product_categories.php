<?php
// F7: addProduct/editProduct/copyProduct of the admin product model after OCMOD, on a database stub that keeps the links.
// usage: php test_product_categories.php <dir with files produced by ocmod_sim.php>. Exit code 1 when a check fails.
error_reporting(E_ALL); define('DB_PREFIX', 'oc_');
// The core model reads many fields of $data the stub does not send
set_error_handler(function ($no, $str) { if (strpos($str, 'Undefined') === 0) return true; throw new ErrorException($str); });
if (empty($argv[1])) { fwrite(STDERR, "usage: php test_product_categories.php <ocmod_sim out dir>\n"); exit(2); }
$out = rtrim($argv[1], '/');
require __DIR__ . '/../seo_pro_oc3.ocmod/upload/system/library/seo_pro_cache.php';

class Res { public $rows; public $row; public $num_rows; function __construct($rows = []) { $this->rows = $rows; $this->row = $rows[0] ?? []; $this->num_rows = count($rows); } }
// p2c: product_id => [category_id => main_category]; the pair (product_id, category_id) is the primary key
class DB { public $log = []; public $p2c = []; public $last = 100; public $column = true;
	function escape($s) { return addslashes((string)$s); }
	function getLastId() { return $this->last; }
	function query($sql) { $this->log[] = $sql;
		if (!$this->column && strpos($sql, 'main_category') !== false) throw new Exception("Unknown column 'main_category'");
		if (preg_match("~^INSERT INTO oc_product SET ~", $sql)) { $this->last++; return new Res(); }
		if (preg_match("~^INSERT INTO oc_product_to_category SET product_id = '(\d+)', category_id = '(\d+)'$~", $sql, $m)) {
			if (isset($this->p2c[$m[1]][$m[2]])) throw new Exception("Duplicate entry '{$m[1]}-{$m[2]}' for key 'PRIMARY'");
			$this->p2c[$m[1]][$m[2]] = 0; return new Res(); }
		if (preg_match("~^DELETE FROM oc_product_to_category WHERE product_id = '(\d+)'$~", $sql, $m)) { unset($this->p2c[$m[1]]); return new Res(); }
		if (preg_match("~^UPDATE oc_product_to_category SET main_category = IF\(category_id = '(\d+)', 1, 0\) WHERE product_id = '(\d+)'$~", $sql, $m)) {
			foreach ($this->p2c[$m[2]] ?? [] as $c => $v) $this->p2c[$m[2]][$c] = ((int)$c === (int)$m[1]) ? 1 : 0; return new Res(); }
		if (preg_match("~^SELECT category_id FROM oc_product_to_category WHERE product_id = '(\d+)' AND main_category = '1' LIMIT 1$~", $sql, $m)) {
			foreach ($this->p2c[$m[1]] ?? [] as $c => $v) if ($v) return new Res([['category_id' => (string)$c]]); return new Res(); }
		if (preg_match("~^SELECT (category_id|\*) FROM oc_product_to_category WHERE product_id = '(\d+)'$~", $sql, $m)) {
			$r = []; foreach ($this->p2c[$m[2]] ?? [] as $c => $v) $r[] = ['product_id' => $m[2], 'category_id' => (string)$c] + ($this->column ? ['main_category' => (string)$v] : []); return new Res($r); }
		if (strpos($sql, 'product_to_category') !== false) throw new LogicException("unexpected SQL $sql");
		if (preg_match("~^SELECT DISTINCT \* FROM oc_product p WHERE p.product_id = '(\d+)'~", $sql, $m)) return new Res([['product_id' => $m[1], 'model' => 'M']]);
		if (strpos($sql, 'SELECT store_id FROM `oc_store`') === 0) return new Res([['store_id' => 1]]);
		if (strpos($sql, 'SELECT language_id FROM `oc_language`') === 0) return new Res([['language_id' => 1]]);
		return new Res(); } }
class Cache { public $d = []; public $sql_at_clear = [];
	function get($k) { return $this->d[$k] ?? null; } function set($k, $v) { $this->d[$k] = $v; }
	function delete($k) { unset($this->d[$k]); } }
class Config { public $d = []; function get($k) { return $this->d[$k] ?? null; } }
class Registry { private $d = []; function get($k) { return $this->d[$k] ?? null; } function set($k, $v) { $this->d[$k] = $v; } }
abstract class Model { protected $registry; function __construct($r) { $this->registry = $r; } function __get($k) { return $this->registry->get($k); } }
require $out . '/admin/model/catalog/product.php';
// The fields the core model reads without isset()
function d($data) { return $data + ['product_description' => []]; }

$fail = 0;
function check($name, $got, $exp) { global $fail; $ok = $got === $exp; if (!$ok) $fail++; echo ($ok ? 'ok   ' : 'FAIL ') . $name . ($ok ? '' : "\n       got: " . var_export($got, true) . "\n       exp: " . var_export($exp, true)) . "\n"; }
function env($column = true) { $r = new Registry(); $db = new DB(); $db->column = $column; $c = new Config(); $c->d['seo_pro_schema_failed'] = !$column; $c->d['config_language_id'] = 1;
	$r->set('db', $db); $r->set('config', $c); $r->set('cache', new Cache()); return [$r, new ModelCatalogProduct($r), $db]; }
// add (product 101) and edit (product 7 that had the categories 10* and 12) with the same data; returns the links of both
function both($data, $column = true) {
	$res = [];
	[$r, $m, $db] = env($column); try { $m->addProduct(d($data)); $res[] = $db->p2c[101] ?? []; } catch (\Throwable $e) { $res[] = get_class($e); }
	[$r, $m, $db] = env($column); $db->p2c[7] = [10 => 1, 12 => 0]; try { $m->editProduct(7, d($data)); $res[] = $db->p2c[7] ?? []; } catch (\Throwable $e) { $res[] = get_class($e); }
	return $res;
}
function same($name, $data, $exp, $column = true) { check($name, both($data, $column), [$exp, $exp]); }

echo "-- add and edit\n";
same('[10, 11], main 11', ['product_category' => [10, 11], 'main_category_id' => 11], [10 => 0, 11 => 1]);
same('[10, 11], main "11" as the form sends it', ['product_category' => ['10', '11'], 'main_category_id' => '11'], [10 => 0, 11 => 1]);
same('main category removed from the list: not restored, the first is main', ['product_category' => [11, 12], 'main_category_id' => 10], [11 => 1, 12 => 0]);
same('no main_category_id: the first', ['product_category' => [11, 10]], [11 => 1, 10 => 0]);
same('main_category_id 0: the first', ['product_category' => [11, 10], 'main_category_id' => '0'], [11 => 1, 10 => 0]);
same('main_category_id of a foreign category: the first, no link', ['product_category' => [11, 10], 'main_category_id' => '99'], [11 => 1, 10 => 0]);
foreach ([-1, '1.5', 'abc', ['11'], true, null, 1.0, '11 ', "11\n", '2147483648'] as $bad) same('main_category_id ' . json_encode($bad) . ': no choice', ['product_category' => [10, 11], 'main_category_id' => $bad], [10 => 1, 11 => 0]);
same('main_category_id "011": 11', ['product_category' => [10, 11], 'main_category_id' => '011'], [10 => 0, 11 => 1]);
same('empty list', ['product_category' => [], 'main_category_id' => 10], []);
same('no list', ['main_category_id' => 10], []);
same('[10, 10, 11]: no duplicate key', ['product_category' => [10, 10, 11], 'main_category_id' => 11], [10 => 0, 11 => 1]);
same('["10", "010", "11"]', ['product_category' => ['10', '010', '11']], [10 => 1, 11 => 0]);
same('sparse indexes', ['product_category' => [5 => '11', 9 => '10'], 'main_category_id' => '10'], [11 => 0, 10 => 1]);
same('invalid elements are dropped, the order stays', ['product_category' => [0, '12', -3, '1.5', 1.5, 'x', '', true, null, ['10'], '11', '2147483648', '2147483647', '12']], [12 => 1, 11 => 0, 2147483647 => 0]);
foreach (['10', null, 10, 0, '', true, false, 1.5] as $bad) {
	same('product_category ' . json_encode($bad) . ' instead of a list: rejected', ['product_category' => $bad], 'InvalidArgumentException');
	[$r, $m, $db] = env(); try { $m->addProduct(d(['product_category' => $bad])); } catch (\Throwable $e) {}
	check('add, product_category ' . json_encode($bad) . ': no query at all', $db->log, []);
	[$r, $m, $db] = env(); $db->p2c[7] = [10 => 1, 12 => 0]; try { $m->editProduct(7, d(['product_category' => $bad])); } catch (\Throwable $e) {}
	check('edit, product_category ' . json_encode($bad) . ': no query, old links and the main category stay', [$db->log, $db->p2c[7]], [[], [10 => 1, 12 => 0]]);
}
[$r, $m, $db] = env(); try { $m->addProduct(d(['product_category' => '10'])); } catch (\Throwable $e) {}
check('add, product_category is a string: no query at all', $db->log, []);
[$r, $m, $db] = env(); $db->p2c[7] = [10 => 1, 12 => 0]; try { $m->editProduct(7, d(['product_category' => 10])); } catch (\Throwable $e) {}
check('edit, product_category is not an array: no query, old links stay', [$db->log, $db->p2c[7]], [[], [10 => 1, 12 => 0]]);

[$r, $m, $db] = env(); $db->p2c[7] = [10 => 1, 12 => 0];
$m->editProduct(7, d(['product_category' => [10, 11, 12], 'main_category_id' => 12])); $a = $db->p2c[7];
$m->editProduct(7, d(['product_category' => [10, 11, 12], 'main_category_id' => 11])); $b = $db->p2c[7];
$m->editProduct(7, d(['product_category' => [10, 11, 12], 'main_category_id' => 11])); $c = $db->p2c[7];
check('another main category: the old mark is removed; a repeated save changes nothing', [$a, $b, $c], [[10 => 0, 11 => 0, 12 => 1], [10 => 0, 11 => 1, 12 => 0], [10 => 0, 11 => 1, 12 => 0]]);
check('the main category is never set with DELETE + INSERT of one link', count(preg_grep("~product_to_category.*AND category_id~", $db->log)), 0);
check('one UPDATE of the marks per save', count(preg_grep('~^UPDATE oc_product_to_category SET main_category~', $db->log)), 3);

echo "\n-- copy\n";
[$r, $m, $db] = env(); $db->p2c[7] = [10 => 0, 11 => 1, 12 => 0];
$m->copyProduct(7);
check('copy keeps the categories and the main one', $db->p2c[101] ?? null, [10 => 0, 11 => 1, 12 => 0]);
check('the original is not changed', $db->p2c[7], [10 => 0, 11 => 1, 12 => 0]);
[$r, $m, $db] = env(); $db->p2c[7] = [10 => 0, 11 => 0];
$m->copyProduct(7); check('original without a main category: the first', $db->p2c[101] ?? null, [10 => 1, 11 => 0]);
[$r, $m, $db] = env(); $db->p2c[7] = [];
$m->copyProduct(7); check('original without categories', $db->p2c[101] ?? [], []);

echo "\n-- the column main_category is not ready\n";
same('add and edit keep the links', ['product_category' => [10, 10, 11], 'main_category_id' => 11], [10 => 0, 11 => 0], false);
same('a stale main_category_id creates no link', ['product_category' => [11], 'main_category_id' => 10], [11 => 0], false);
[$r, $m, $db] = env(false); $db->p2c[7] = [10 => 0, 11 => 0];
$m->copyProduct(7); $m->editProduct(7, d(['product_category' => [11]])); $m->addProduct(d(['product_category' => [10]]));
check('copy keeps the links', $db->p2c[101] ?? null, [10 => 0, 11 => 0]);
check('no SQL with main_category', count(preg_grep('~main_category~', $db->log)), 0);
check('getProductMainCategoryId is 0', $m->getProductMainCategoryId(7), 0);

echo "\n-- caches\n";
foreach (['add' => fn($m) => $m->addProduct(d(['product_category' => [10, 11], 'main_category_id' => 11])), 'edit' => fn($m) => $m->editProduct(7, d(['product_category' => [10, 11], 'main_category_id' => 11])), 'copy' => fn($m) => $m->copyProduct(7)] as $name => $f) {
	[$r, $m, $db] = env(); $db->p2c[7] = [10 => 0, 11 => 1]; $cache = $r->get('cache');
	$cache->d = ['product.seopath' => [7 => '10', 101 => '10'], 'category.seopath' => [10 => '10'], 'seo_pro.0.1' => [], 'seo_pro.1.1' => [], 'other' => 1];
	$f($m);
	check("$name: the module caches are cleared", array_keys($cache->d), ['other']);
	$log = $db->log; $update = max(array_keys(preg_grep('~^UPDATE oc_product_to_category SET main_category~', $log))); $clear = min(array_keys(preg_grep('~oc_store`~', $log)));
	check("$name: cleared after the marks are written", $update < $clear, true);
	$id = $name === 'edit' ? 7 : 101;
	check("$name: the next path is built from the saved main category", $m->getProductMainCategoryId($id), 11);
}

echo "\nfailed: $fail\n";
exit($fail ? 1 : 0);

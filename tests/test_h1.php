<?php
// 1.7: HTML H1 (meta_h1) in the admin models of products, categories and information pages after OCMOD,
// on a database stub that keeps the description tables. The data is checked after each call, not the SQL text.
// usage: OC_DIR=... php test_h1.php <dir with files produced by ocmod_sim.php>. Exit code 1 when a check fails.
error_reporting(E_ALL); define('DB_PREFIX', 'oc_');
// The core models read many fields of $data the stub does not send
set_error_handler(function ($no, $str) { if (strpos($str, 'Undefined') === 0 || strpos($str, 'Trying to access array offset') === 0) return true; throw new ErrorException($str); });
if (empty($argv[1]) || !getenv('OC_DIR')) { fwrite(STDERR, "usage: OC_DIR=<opencart-3.0.5.1/upload> php test_h1.php <ocmod_sim out dir>\n"); exit(2); }
$out = rtrim($argv[1], '/');
require rtrim(getenv('OC_DIR'), '/') . '/system/helper/utf8.php';
require __DIR__ . '/../seo_pro_oc3.ocmod/upload/system/library/seo_pro_cache.php';
require __DIR__ . '/../seo_pro_oc3.ocmod/upload/system/library/seo_pro_schema.php';

class Res { public $rows; public $row; public $num_rows; function __construct($rows = []) { $this->rows = $rows; $this->row = $rows[0] ?? []; $this->num_rows = count($rows); } }
// $t: table => [id => [language_id => row]] for product_description, category_description, information_description.
// $column false: the table has no meta_h1, SQL naming it fails as on a real database.
class DB { public $log = []; public $t = []; public $last = 100; public $column = true;
	function escape($s) { return addslashes((string)$s); }
	function getLastId() { return $this->last; }
	function writes() { return array_values(preg_grep('~^(INSERT|UPDATE|DELETE|ALTER|CREATE)~', $this->log)); }
	function query($sql) { $this->log[] = $sql;
		if (preg_match('~^(INSERT INTO|DELETE FROM|SELECT [^F]* FROM) oc_(product|category|information)_description ~', $sql, $m)) {
			$table = $m[2] . '_description';
			if (!$this->column && strpos($sql, 'meta_h1') !== false) throw new Exception("Unknown column 'meta_h1' in 'field list'");
			if (strpos($sql, 'INSERT') === 0) { preg_match_all("~(\w+) = '((?:[^'\\\\]|\\\\.)*)'~", $sql, $mm); $row = array_map('stripslashes', array_combine($mm[1], $mm[2]));
				$id = $row[$m[2] . '_id']; if (isset($this->t[$table][$id][$row['language_id']])) throw new Exception('Duplicate entry for key PRIMARY'); $this->t[$table][$id][$row['language_id']] = $row; return new Res(); }
			preg_match("~WHERE {$m[2]}_id = '(\d+)'~", $sql, $w);
			if (strpos($sql, 'DELETE') === 0) { unset($this->t[$table][$w[1]]); return new Res(); }
			$rows = array_values($this->t[$table][$w[1]] ?? []);
			if (strpos($sql, 'SELECT language_id, meta_h1') === 0) $rows = array_map(fn($r) => ['language_id' => $r['language_id'], 'meta_h1' => $r['meta_h1'] ?? ''], $rows);
			return new Res($rows); }
		if (preg_match('~^INSERT INTO oc_(product|category|information) SET ~', $sql)) { $this->last++; return new Res(); }
		if (preg_match("~^SELECT DISTINCT \* FROM oc_product p WHERE p.product_id = '(\d+)'~", $sql, $m)) return new Res([['product_id' => $m[1], 'model' => 'M']]);
		if (strpos($sql, 'SELECT store_id FROM `oc_store`') === 0) return new Res([['store_id' => 0]]);
		if (strpos($sql, 'SELECT language_id FROM `oc_language`') === 0) return new Res([['language_id' => 1]]);
		return new Res(); } }
class Cache { function get($k) { return null; } function set($k, $v) {} function delete($k) {} }
class Config { public $d = []; function get($k) { return $this->d[$k] ?? null; } }
class Registry { private $d = []; function get($k) { return $this->d[$k] ?? null; } function set($k, $v) { $this->d[$k] = $v; } }
abstract class Model { protected $registry; function __construct($r) { $this->registry = $r; } function __get($k) { return $this->registry->get($k); } }
foreach (['product', 'category', 'information'] as $e) require $out . '/admin/model/catalog/' . $e . '.php';

$fail = 0;
function check($name, $got, $exp) { global $fail; $ok = $got === $exp; if (!$ok) $fail++; echo ($ok ? 'ok   ' : 'FAIL ') . $name . ($ok ? '' : "\n       got: " . var_export($got, true) . "\n       exp: " . var_export($exp, true)) . "\n"; }
const ENT = ['product' => ['ModelCatalogProduct', 'name'], 'category' => ['ModelCatalogCategory', 'name'], 'information' => ['ModelCatalogInformation', 'title']];
function env($e, $ready = true, $column = true) { $r = new Registry(); $db = new DB(); $db->column = $column; $c = new Config(); $c->d = ['config_language_id' => 1, 'seo_pro_schema_failed' => false, 'seo_pro_h1_' . $e . '_ready' => $ready];
	$r->set('db', $db); $r->set('config', $c); $r->set('cache', new Cache()); $class = ENT[$e][0]; return [new $class($r), $db]; }
// One description: the fields the core INSERT needs and the given H1 fields
function desc($e, $title, $h1 = []) { return [ENT[$e][1] => $title, 'description' => '', 'tag' => '', 'meta_title' => $title, 'meta_description' => '', 'meta_keyword' => ''] + $h1; }
function add($m, $e, $descs) { $f = 'add' . ucfirst($e); return $m->$f([$e . '_description' => $descs]); }
function edit($m, $e, $id, $descs) { $f = 'edit' . ucfirst($e); return $m->$f($id, [$e . '_description' => $descs]); }
function getd($m, $e, $id) { $f = 'get' . ucfirst($e) . 'Descriptions'; return $m->$f($id); }
function h1s($db, $e, $id) { $r = []; foreach ($db->t[$e . '_description'][$id] ?? [] as $l => $row) $r[$l] = $row['meta_h1'] ?? null; return $r; }
function err($f) { try { $f(); return null; } catch (\Throwable $x) { return get_class($x); } }

foreach (array_keys(ENT) as $e) {
	echo "-- $e\n";
	[$m, $db] = env($e);
	add($m, $e, [1 => desc($e, 'One', ['meta_h1' => 'H1 one']), 2 => desc($e, 'Zwei', ['meta_h1' => 'H1 zwei'])]);
	check("$e add: H1 of two languages stored", h1s($db, $e, 101), [1 => 'H1 one', 2 => 'H1 zwei']);
	$d = getd($m, $e, 101);
	check("$e get*Descriptions returns meta_h1, the form is reopened with it", [$d[1]['meta_h1'] ?? null, $d[2]['meta_h1'] ?? null], ['H1 one', 'H1 zwei']);
	edit($m, $e, 101, [1 => desc($e, 'One'), 2 => desc($e, 'Zwei', ['meta_h1' => 'new'])]);
	check("$e edit without meta_h1 of language 1: the stored H1 stays, language 2 changes", h1s($db, $e, 101), [1 => 'H1 one', 2 => 'new']);
	edit($m, $e, 101, [1 => desc($e, 'One', ['meta_h1' => '']), 2 => desc($e, 'Zwei', ['meta_h1' => '0'])]);
	check("$e edit: '' clears H1, '0' is a value", h1s($db, $e, 101), [1 => '', 2 => '0']);
	add($m, $e, [1 => desc($e, 'Three')]);
	check("$e add without meta_h1: empty H1", h1s($db, $e, 102), [1 => '']);
	edit($m, $e, 102, [1 => desc($e, 'Three'), 2 => desc($e, 'Drei')]);
	check("$e edit adds a language without meta_h1: empty for it", h1s($db, $e, 102), [1 => '', 2 => '']);
	edit($m, $e, 102, [1 => desc($e, 'Three')]);
	check("$e edit without a language: the core deletes it as before", h1s($db, $e, 102), [1 => '']);
	// Request::clean() escapes the form once: that form is stored as it is and reopened unchanged
	$escaped = htmlspecialchars('Шина "K60" & <Scout> \'x\'', ENT_COMPAT, 'UTF-8');
	edit($m, $e, 102, [1 => desc($e, 'Three', ['meta_h1' => $escaped])]);
	$again = getd($m, $e, 102); edit($m, $e, 102, $again); edit($m, $e, 102, getd($m, $e, 102));
	check("$e quotes, &, Unicode: stored once escaped, two repeated saves change nothing", [h1s($db, $e, 102)[1], getd($m, $e, 102)[1]['meta_h1']], [$escaped, $escaped]);
	check("$e repeated saves keep the other fields", getd($m, $e, 102)[1][ENT[$e][1]], 'Three');
	// An import through the model API sends raw text: it is stored the way the form stores it and never becomes markup
	$raw = '"><img src=x onerror=alert(1)> & \'q\''; $stored = htmlspecialchars($raw, ENT_COMPAT, 'UTF-8');
	edit($m, $e, 102, [1 => desc($e, 'Three', ['meta_h1' => $raw])]);
	check("$e raw text of an import: stored as Request::clean() stores it, no markup", [h1s($db, $e, 102)[1], getd($m, $e, 102)[1]['meta_h1'], strpos(h1s($db, $e, 102)[1], '<')], [$stored, $stored, false]);
	edit($m, $e, 102, getd($m, $e, 102));
	check("$e the escaped import saved again: not escaped twice", h1s($db, $e, 102)[1], $stored);
	add($m, $e, [1 => desc($e, 'Mixed', ['meta_h1' => '&amp; " &lt; AT&T'])]);
	check("$e mixed text: the entities are kept, the raw characters escaped", h1s($db, $e, 103)[1], '&amp; &quot; &lt; AT&amp;T');
	$db->t[$e . '_description'][103][1]['meta_h1'] = $raw;
	edit($m, $e, 103, [1 => desc($e, 'Mixed')]);
	check("$e a raw H1 written by another tool, edit without meta_h1: kept in the stored form", h1s($db, $e, 103)[1], $stored);
	foreach (['42 raw quotes, 252 characters escaped' => [str_repeat('"', 42), null], '43 raw quotes, 258 characters escaped' => [str_repeat('"', 43), 'InvalidArgumentException']] as $name => [$value, $exp]) {
		[$m2, $db2] = env($e); $got = err(fn() => add($m2, $e, [1 => desc($e, 'Q', ['meta_h1' => $value])]));
		check("$e add, $name: the limit is checked on the stored form", [$got, $exp ? $db2->writes() : h1s($db2, $e, 101)], [$exp, $exp ? [] : [1 => str_repeat('&quot;', 42)]]);
	}

	echo "\n-- $e: limits and wrong values, nothing is written\n";
	foreach (['255 letters' => [str_repeat('я', 255), null], '256 letters' => [str_repeat('я', 256), 'InvalidArgumentException'],
		'255 with an entity counted escaped' => [str_repeat('a', 250) . '&amp;', null], '256 with an entity counted escaped' => [str_repeat('a', 251) . '&amp;', 'InvalidArgumentException'],
		'array' => [['x'], 'InvalidArgumentException'], 'int' => [5, 'InvalidArgumentException'], 'null' => [null, 'InvalidArgumentException'], 'invalid UTF-8' => ["\xC3\x28", 'InvalidArgumentException']] as $name => [$value, $exp]) {
		[$m, $db] = env($e); $db->t[$e . '_description'][7] = [1 => ['language_id' => '1', 'meta_h1' => 'old']];
		$got = err(fn() => edit($m, $e, 7, [1 => desc($e, 'Seven', ['meta_h1' => $value])]));
		check("$e edit, meta_h1 $name", [$got, $exp ? $db->writes() : true, h1s($db, $e, 7)], [$exp, $exp ? [] : true, [1 => $exp ? 'old' : $value]]);
		[$m, $db] = env($e); $got = err(fn() => add($m, $e, [1 => desc($e, 'New', ['meta_h1' => $value])]));
		check("$e add, meta_h1 $name", [$got, $exp ? $db->writes() : []], [$exp, []]);
	}
	foreach (['a string instead of the descriptions' => 'x', 'a string instead of a language' => [1 => 'x']] as $name => $descs) {
		[$m, $db] = env($e); $db->t[$e . '_description'][7] = [1 => ['language_id' => '1', 'meta_h1' => 'old']];
		check("$e edit, $name: rejected before any write", [err(fn() => edit($m, $e, 7, $descs)), $db->writes(), h1s($db, $e, 7)], ['InvalidArgumentException', [], [1 => 'old']]);
	}

	echo "\n-- $e: the column is not ready\n";
	foreach (['the flag is false' => false, 'the flag is missing' => null] as $name => $flag) {
		[$m, $db] = env($e, $flag, true); $db->t[$e . '_description'][7] = [1 => ['language_id' => '1', 'meta_h1' => 'old', ENT[$e][1] => 'Seven']];
		check("$e edit, $name: rejected before any SQL, the stored H1 stays", [err(fn() => edit($m, $e, 7, [1 => desc($e, 'Seven')])), $db->log, h1s($db, $e, 7)], ['RuntimeException', [], [1 => 'old']]);
		check("$e add, $name: rejected before any SQL", [err(fn() => add($m, $e, [1 => desc($e, 'New')])), $db->log], ['RuntimeException', []]);
	}
	[$m, $db] = env($e, false, false); $db->t[$e . '_description'][7] = [1 => ['language_id' => '1', ENT[$e][1] => 'Seven']];
	check("$e without the column: get*Descriptions gives an empty H1", getd($m, $e, 7)[1]['meta_h1'] ?? null, '');
	echo "\n";
}

echo "-- copy of a product\n";
[$m, $db] = env('product'); $db->t['product_description'][7] = [1 => desc('product', 'Seven', ['meta_h1' => 'H1 seven']) + ['product_id' => '7', 'language_id' => '1'], 2 => desc('product', 'Sieben', ['meta_h1' => '']) + ['product_id' => '7', 'language_id' => '2']];
$m->copyProduct(7);
check('copy keeps H1 of every language', h1s($db, 'product', 101), [1 => 'H1 seven', 2 => '']);
check('the original is not changed', h1s($db, 'product', 7), [1 => 'H1 seven', 2 => '']);
[$m, $db] = env('product', false); $db->t['product_description'][7] = [1 => desc('product', 'Seven') + ['product_id' => '7', 'language_id' => '1']];
check('copy with the column not ready: rejected before any write', [err(fn() => $m->copyProduct(7)), $db->writes()], ['RuntimeException', []]);

echo "\n-- the contract checks of the library\n";
check('textError', [Seo_pro_schema::textError('', 255), Seo_pro_schema::textError(str_repeat('ж', 255), 255), Seo_pro_schema::textError(str_repeat('ж', 256), 255), Seo_pro_schema::textError(str_repeat('ж', 3), 5, true), Seo_pro_schema::textError(1, 255), Seo_pro_schema::textError("\xFF", 255)], ['', '', 'length', 'length', 'type', 'type']);
check('h1Errors: only sent meta_h1 is checked', Seo_pro_schema::h1Errors([1 => ['name' => 'x'], 2 => ['meta_h1' => ['a']], 3 => ['meta_h1' => str_repeat('a', 256)], 4 => ['meta_h1' => '0'], 5 => 'x']), [2 => 'type', 3 => 'length']);
check('h1Errors counts the stored form', Seo_pro_schema::h1Errors([1 => ['meta_h1' => str_repeat('"', 43)], 2 => ['meta_h1' => str_repeat('&quot;', 42)]]), [1 => 'length']);
check('text(): escaped text unchanged, raw characters escaped, invalid UTF-8 substituted', [Seo_pro_schema::text('&quot;a&quot; &amp; &lt;b&gt; \'c\''), Seo_pro_schema::text('"a" & <b> \'c\''), Seo_pro_schema::text("x\xFF")], ['&quot;a&quot; &amp; &lt;b&gt; \'c\'', '&quot;a&quot; &amp; &lt;b&gt; \'c\'', "x\u{FFFD}"]);
check('h1Errors of a missing or wrong container: none (the core form fails on it)', [Seo_pro_schema::h1Errors(null), Seo_pro_schema::h1Errors('x')], [[], []]);
check('prepareH1 of an unknown entity', err(fn() => Seo_pro_schema::prepareH1(new DB(), new Config(), 'manufacturer', 0, [])), 'InvalidArgumentException');

echo "\nfailed: $fail\n";
exit($fail ? 1 : 0);

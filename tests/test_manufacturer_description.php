<?php
// 1.7: descriptions of manufacturers in the admin model after OCMOD, on a database stub that keeps
// manufacturer, manufacturer_to_store, seo_url and manufacturer_description. The data is checked after each call.
// usage: OC_DIR=... php test_manufacturer_description.php <dir with files produced by ocmod_sim.php>. Exit code 1 when a check fails.
error_reporting(E_ALL); define('DB_PREFIX', 'oc_');
set_error_handler(function ($no, $str) { if (strpos($str, 'Undefined') === 0) return true; throw new ErrorException($str); });
if (empty($argv[1]) || !getenv('OC_DIR')) { fwrite(STDERR, "usage: OC_DIR=<opencart-3.0.5.1/upload> php test_manufacturer_description.php <ocmod_sim out dir>\n"); exit(2); }
$out = rtrim($argv[1], '/');
require rtrim(getenv('OC_DIR'), '/') . '/system/helper/utf8.php';
require __DIR__ . '/../seo_pro_oc3.ocmod/upload/system/library/seo_pro_cache.php';
require __DIR__ . '/../seo_pro_oc3.ocmod/upload/system/library/seo_pro_schema.php';

class Res { public $rows; public $row; public $num_rows; function __construct($rows = []) { $this->rows = $rows; $this->row = $rows[0] ?? []; $this->num_rows = count($rows); } }
const FIELDS = ['description', 'meta_title', 'meta_h1', 'meta_description', 'meta_keyword'];
// $m: manufacturer_id => name; $md: manufacturer_id => [language_id => [field => value]] with the primary key (manufacturer_id, language_id)
class DB { public $log = []; public $m = []; public $m2s = []; public $md = []; public $last = 0; public $table = true; public $fail = null;
	function escape($s) { return addslashes((string)$s); }
	function getLastId() { return $this->last; }
	function writes() { return array_values(preg_grep('~^(INSERT|UPDATE|DELETE)~', $this->log)); }
	function query($sql) { $this->log[] = $sql;
		if (strpos($sql, 'manufacturer_description') !== false) {
			if (!$this->table) throw new Exception("Table 'oc_manufacturer_description' doesn't exist");
			if ($this->fail && strpos($sql, $this->fail) === 0) throw new Exception('Lost connection');
			if (preg_match("~^INSERT INTO `oc_manufacturer_description` SET `manufacturer_id` = '(\d+)', `language_id` = '(\d+)', (.*) ON DUPLICATE KEY UPDATE (.*)$~s", $sql, $q)) {
				$set = fn($s) => preg_match_all("~`(\w+)` = '((?:[^'\\\\]++|\\\\.)*+)'~", $s, $mm) ? array_map('stripslashes', array_combine($mm[1], $mm[2])) : [];
				$this->md[$q[1]][$q[2]] = isset($this->md[$q[1]][$q[2]]) ? array_replace($this->md[$q[1]][$q[2]], $set($q[4])) : $set($q[3]); return new Res(); }
			if (preg_match("~^DELETE FROM `oc_manufacturer_description` WHERE `manufacturer_id` = '(\d+)'$~", $sql, $q)) { unset($this->md[$q[1]]); return new Res(); }
			if (preg_match("~^SELECT \* FROM `oc_manufacturer_description` WHERE `manufacturer_id` = '(\d+)'$~", $sql, $q)) { $r = []; foreach ($this->md[$q[1]] ?? [] as $l => $f) $r[] = ['manufacturer_id' => $q[1], 'language_id' => (string)$l] + $f; return new Res($r); }
			throw new LogicException("unexpected SQL $sql"); }
		if (preg_match("~^INSERT INTO oc_manufacturer SET name = '([^']*)'~", $sql, $q)) { $this->m[++$this->last] = $q[1]; return new Res(); }
		if (preg_match("~^UPDATE oc_manufacturer SET name = '([^']*)'.* WHERE manufacturer_id = '(\d+)'~", $sql, $q)) { $this->m[$q[2]] = $q[1]; return new Res(); }
		if (preg_match("~^DELETE FROM `oc_manufacturer` WHERE manufacturer_id = '(\d+)'~", $sql, $q)) { unset($this->m[$q[1]]); return new Res(); }
		if (strpos($sql, 'SELECT store_id FROM `oc_store`') === 0) return new Res([['store_id' => 0]]);
		if (strpos($sql, 'SELECT language_id FROM `oc_language`') === 0) return new Res([['language_id' => 1]]);
		return new Res(); } }
class Cache { public $del = []; function get($k) { return null; } function set($k, $v) {} function delete($k) { $this->del[] = $k; } }
class Config { public $d = []; function get($k) { return $this->d[$k] ?? null; } }
class Registry { private $d = []; function get($k) { return $this->d[$k] ?? null; } function set($k, $v) { $this->d[$k] = $v; } }
abstract class Model { protected $registry; function __construct($r) { $this->registry = $r; } function __get($k) { return $this->registry->get($k); } }
require $out . '/admin/model/catalog/manufacturer.php';

$fail = 0;
function check($name, $got, $exp) { global $fail; $ok = $got === $exp; if (!$ok) $fail++; echo ($ok ? 'ok   ' : 'FAIL ') . $name . ($ok ? '' : "\n       got: " . var_export($got, true) . "\n       exp: " . var_export($exp, true)) . "\n"; }
function env($ready = true) { $r = new Registry(); $db = new DB(); $c = new Config(); $c->d = ['config_language_id' => 1, 'seo_pro_manufacturer_description_ready' => $ready];
	$r->set('db', $db); $r->set('config', $c); $r->set('cache', new Cache()); return [new ModelCatalogManufacturer($r), $db, $r->get('cache')]; }
function full($text) { return array_combine(FIELDS, array_map(fn($f) => "$text $f", FIELDS)); }
function err($f) { try { $f(); return null; } catch (\Throwable $x) { return get_class($x); } }
function base($data = []) { return ['name' => 'Heidenau', 'sort_order' => '0'] + $data; }
// Manufacturer 5 with descriptions of languages 1 and 2
function seeded($ready = true) { [$m, $db, $c] = env($ready); $db->m[5] = 'Heidenau'; $db->last = 5; $db->md[5] = [1 => full('en'), 2 => full('ru')]; return [$m, $db, $c]; }

echo "-- add, edit, read\n";
[$m, $db, $c] = env();
$id = $m->addManufacturer(base(['manufacturer_description' => [1 => full('en'), 2 => ['meta_h1' => 'Хайденау "H1" &amp;']]]));
check('add: two languages, a partial language gets empty fields, a raw quote is stored escaped, an entity is kept', [$id, $db->md[1] ?? null], [1, [1 => full('en'), 2 => ['description' => '', 'meta_title' => '', 'meta_h1' => 'Хайденау &quot;H1&quot; &amp;', 'meta_description' => '', 'meta_keyword' => '']]]);
check('the descriptions are written before the cache reset', max(array_keys(preg_grep('~manufacturer_description~', $db->log))) < min(array_keys(preg_grep('~oc_store`~', $db->log))), true);
check('getManufacturerDescriptions gives every field of every language', $m->getManufacturerDescriptions(1), [1 => full('en'), 2 => ['description' => '', 'meta_title' => '', 'meta_h1' => 'Хайденау &quot;H1&quot; &amp;', 'meta_description' => '', 'meta_keyword' => '']]);
check('the core cache of manufacturers is cleared once', count(array_keys($c->del, 'manufacturer')), 1);
[$m, $db] = env(); $m->addManufacturer(base());
check('add without manufacturer_description: no row', [$db->md, count(preg_grep('~manufacturer_description~', $db->log))], [[], 0]);

[$m, $db] = seeded(); $m->editManufacturer(5, base());
check('edit without manufacturer_description: the descriptions are not touched', [$db->md[5], count(preg_grep('~manufacturer_description~', $db->log))], [[1 => full('en'), 2 => full('ru')], 0]);
[$m, $db] = seeded(); $m->editManufacturer(5, base(['manufacturer_description' => []]));
check('edit with an empty array: no update, nothing deleted', $db->md[5], [1 => full('en'), 2 => full('ru')]);
check('edit with an empty array: no SQL on the descriptions', count(preg_grep('~manufacturer_description~', $db->log)), 0);
[$m, $db] = seeded(); $m->editManufacturer(5, base(['manufacturer_description' => [2 => ['meta_title' => 'new title', 'description' => '']]]));
check('edit of two fields of one language: only they change, "" clears, language 1 stays', $db->md[5], [1 => full('en'), 2 => array_replace(full('ru'), ['description' => '', 'meta_title' => 'new title'])]);
[$m, $db] = seeded(); $m->editManufacturer(5, base(['manufacturer_description' => [1 => [], 2 => ['meta_h1' => '0']]]));
check('edit: an empty language is no update, "0" is a value', $db->md[5], [1 => full('en'), 2 => array_replace(full('ru'), ['meta_h1' => '0'])]);
[$m, $db] = seeded(); $m->editManufacturer(5, base(['manufacturer_description' => [3 => ['meta_title' => 'fr']]]));
check('edit: a new language is added, the others stay', [$db->md[5][1], $db->md[5][2], $db->md[5][3]], [full('en'), full('ru'), ['description' => '', 'meta_title' => 'fr', 'meta_h1' => '', 'meta_description' => '', 'meta_keyword' => '']]);
[$m, $db] = seeded(); $m->editManufacturer(5, base(['manufacturer_description' => [1 => ['meta_title' => 'x', 'store_id' => '9', 'name' => 'y']]]));
check('edit: unknown fields are ignored and never reach SQL', [$db->md[5][1]['meta_title'], count(preg_grep('~store_id|`name`~', preg_grep('~manufacturer_description~', $db->log)))], ['x', 0]);
[$m, $db] = seeded(); $text = str_repeat('я', 255); $html = str_repeat('&lt;p&gt;Шина&lt;/p&gt;', 1000);
$m->editManufacturer(5, base(['manufacturer_description' => [1 => ['meta_title' => $text, 'description' => $html, 'meta_keyword' => "it's \\ ok"]]]));
check('edit: 255 letters, a long HTML and quotes are stored unchanged', [$db->md[5][1]['meta_title'], $db->md[5][1]['description'], $db->md[5][1]['meta_keyword']], [$text, $html, "it's \\ ok"]);
$again = $m->getManufacturerDescriptions(5); $m->editManufacturer(5, base(['manufacturer_description' => $again]));
check('a repeated save of the read values changes nothing', $m->getManufacturerDescriptions(5), $again);
// An import through the model API sends raw text and raw HTML: stored as Request::clean() stores a form, never as markup
[$m, $db] = seeded(); $raw = '"><img src=x onerror=alert(123)>'; $esc = htmlspecialchars($raw, ENT_COMPAT, 'UTF-8');
$m->editManufacturer(5, base(['manufacturer_description' => [1 => ['meta_title' => $raw, 'meta_h1' => $raw, 'meta_description' => $raw, 'meta_keyword' => $raw, 'description' => '<p>A &amp; B "x"</p>']]]));
check('edit, raw text of an import: every field stored escaped once, the HTML of the description too', $db->md[5][1], ['description' => '&lt;p&gt;A &amp; B &quot;x&quot;&lt;/p&gt;', 'meta_title' => $esc, 'meta_h1' => $esc, 'meta_description' => $esc, 'meta_keyword' => $esc]);
$again = $m->getManufacturerDescriptions(5); $m->editManufacturer(5, base(['manufacturer_description' => $again]));
check('the escaped import saved again: not escaped twice', $m->getManufacturerDescriptions(5), $again);
[$m, $db] = env(); $m->addManufacturer(base(['manufacturer_description' => [1 => ['meta_title' => $raw]]]));
check('add, raw text of an import: stored escaped', $db->md[1][1]['meta_title'], $esc);

echo "\n-- wrong data: rejected before the first write\n";
$wrong = [
	'a string instead of the array' => 'x', 'null' => null, 'a language that is not an array' => [1 => 'x'], 'language id 0' => [0 => ['meta_title' => 'x']],
	'language id "01"' => ['01' => ['meta_title' => 'x']], 'language id "a"' => ['a' => ['meta_title' => 'x']], 'a field that is an array' => [1 => ['meta_h1' => ['x']]],
	'a field that is an int' => [1 => ['meta_title' => 5]], 'meta_title of 256 letters' => [1 => ['meta_title' => str_repeat('я', 256)]],
	'meta_keyword of 256 bytes as entities' => [1 => ['meta_keyword' => str_repeat('&amp;', 52)]], 'description over 65535 bytes' => [1 => ['description' => str_repeat('я', 32768)]],
	'meta_h1 of 43 raw quotes, 258 characters escaped' => [1 => ['meta_h1' => str_repeat('"', 43)]], 'description of 65535 bytes with a raw <' => [1 => ['description' => '<' . str_repeat('a', 65534)]],
	'invalid UTF-8' => [1 => ['meta_h1' => "\xC3\x28"]], 'one good language and one wrong' => [1 => ['meta_title' => 'ok'], 2 => ['meta_title' => str_repeat('a', 256)]]];
foreach ($wrong as $name => $value) {
	[$m, $db] = seeded();
	check("edit, $name: InvalidArgumentException, no write, nothing changed", [err(fn() => $m->editManufacturer(5, base(['manufacturer_description' => $value]))), $db->writes(), $db->md[5], $db->m[5]], ['InvalidArgumentException', [], [1 => full('en'), 2 => full('ru')], 'Heidenau']);
	[$m, $db] = env();
	check("add, $name: InvalidArgumentException, no manufacturer created", [err(fn() => $m->addManufacturer(base(['manufacturer_description' => $value]))), $db->writes(), $db->m], ['InvalidArgumentException', [], []]);
}
[$m, $db] = seeded();
check('edit: 65535 bytes of description is accepted', [err(fn() => $m->editManufacturer(5, base(['manufacturer_description' => [1 => ['description' => str_repeat('a', 65535)]]]))), strlen($db->md[5][1]['description'])], [null, 65535]);

echo "\n-- delete\n";
[$m, $db] = seeded(); $db->md[6] = [1 => full('other')];
$m->deleteManufacturer(5);
check('delete removes the descriptions of this manufacturer only', [isset($db->m[5]), $db->md], [false, [6 => [1 => full('other')]]]);
check('the descriptions are deleted before the core deletes anything', strpos($db->writes()[0], 'manufacturer_description') !== false, true);
[$m, $db] = seeded(); $db->fail = 'DELETE';
check('the description delete fails: nothing else is deleted', [err(fn() => $m->deleteManufacturer(5)), isset($db->m[5]), $db->md[5]], ['Exception', true, [1 => full('en'), 2 => full('ru')]]);
[$m, $db] = env(); $db->m[9] = 'No descriptions'; $m->deleteManufacturer(9);
check('delete of a manufacturer without descriptions', isset($db->m[9]), false);

echo "\n-- the table is not ready: add, edit and delete are blocked before any SQL\n";
foreach (['the flag is false' => false, 'the flag is missing' => null] as $name => $flag) {
	[$m, $db] = seeded($flag);
	check("add, $name", [err(fn() => $m->addManufacturer(base(['manufacturer_description' => [1 => full('x')]]))), $db->log], ['RuntimeException', []]);
	check("add without descriptions, $name", [err(fn() => $m->addManufacturer(base())), $db->log], ['RuntimeException', []]);
	check("edit, $name: the descriptions stay", [err(fn() => $m->editManufacturer(5, base())), $db->log, $db->md[5]], ['RuntimeException', [], [1 => full('en'), 2 => full('ru')]]);
	check("delete, $name: the manufacturer and its descriptions stay", [err(fn() => $m->deleteManufacturer(5)), $db->log, isset($db->m[5]), count($db->md[5])], ['RuntimeException', [], true, 2]);
	check("getManufacturerDescriptions, $name: empty, no SQL", [$m->getManufacturerDescriptions(5), $db->log], [[], []]);
}

echo "\n-- the values of the form\n";
$stored = [1 => full('en'), 2 => full('ru')];
check('GET: the stored values', Seo_pro_schema::manufacturerFormValues([], $stored), $stored);
check('POST: each sent field wins, a missing field is the stored one, wrong values are ""', Seo_pro_schema::manufacturerFormValues([1 => ['meta_title' => 'sent', 'meta_h1' => ['x']], 3 => ['description' => 'new']], $stored),
	[1 => array_replace(full('en'), ['meta_title' => 'sent', 'meta_h1' => '']), 2 => full('ru'), 3 => ['description' => 'new', 'meta_title' => '', 'meta_h1' => '', 'meta_description' => '', 'meta_keyword' => '']]);
check('a wrong container is never shown', [Seo_pro_schema::manufacturerFormValues('x', null), Seo_pro_schema::manufacturerFormValues([1 => 'x'], [])], [[], [1 => array_fill_keys(FIELDS, '')]]);
check('manufacturerErrors names the language and the field', Seo_pro_schema::manufacturerErrors([1 => ['meta_title' => str_repeat('a', 256), 'meta_h1' => 'ok'], 2 => ['description' => 7]]), ['container' => false, 'fields' => [1 => ['meta_title' => 'length'], 2 => ['description' => 'type']]]);

echo "\nfailed: $fail\n";
exit($fail ? 1 : 0);

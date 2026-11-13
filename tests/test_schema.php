<?php
// F4 and 1.7: Seo_pro_schema::install(), getCapabilities() and startup() on a stub database that keeps the tables,
// their columns and primary keys. Exit code 1 when a check fails.
// usage: php test_schema.php
error_reporting(E_ALL); define('DB_PREFIX', 'oc_');
set_error_handler(function ($no, $str, $file, $line) { throw new ErrorException($str, 0, $no, $file, $line); });
require __DIR__ . '/../seo_pro_oc3.ocmod/upload/system/library/seo_pro_schema.php';
require __DIR__ . '/../seo_pro_oc3.ocmod/upload/system/library/seo_pro_cache.php';

class R { public $row; public $rows; public $num_rows; function __construct($rows = []) { $this->rows = $rows; $this->row = $rows[0] ?? []; $this->num_rows = count($rows); } }
// $cols: table => [column => type]; $keys: table => primary key columns; $status: table => SHOW TABLE STATUS row; $rows: table => number of rows.
// $lock: what GET_LOCK answers. $fail: "kind" or "kind table" => exception. $held: the lock is held by this connection.
class DB { public $log = []; public $lock = 1; public $fail = []; public $held = false; public $foreign = false; public $released_foreign = false; public $version = null;
	public $cols = []; public $keys = []; public $status = []; public $rows = [];
	function __construct() {
		$this->cols['product_to_category'] = ['product_id' => 'int(11)', 'category_id' => 'int(11)'];
		foreach (['product', 'category', 'information'] as $e) $this->cols[$e . '_description'] = [$e . '_id' => 'int(11)', 'language_id' => 'int(11)', 'meta_title' => 'varchar(255)', 'meta_description' => 'varchar(255)'];
		$this->cols['manufacturer'] = ['manufacturer_id' => 'int(11)', 'name' => 'varchar(64)'];
		$this->status['manufacturer'] = ['Name' => 'oc_manufacturer', 'Engine' => 'MyISAM', 'Collation' => 'utf8mb4_unicode_ci'];
	}
	function escape($s) { return addslashes($s); }
	function kind($sql) {
		foreach (['SELECT GET_LOCK' => 'GET_LOCK', 'SELECT RELEASE_LOCK' => 'RELEASE_LOCK', 'DESCRIBE' => 'DESCRIBE', 'ALTER' => 'ALTER', 'CREATE' => 'CREATE', 'SHOW TABLE STATUS' => 'STATUS', 'SHOW TABLES' => 'TABLES', 'SHOW COLUMNS' => 'COLUMNS', 'SHOW KEYS' => 'KEYS', 'DELETE FROM `oc_setting`' => 'DELETE', 'INSERT INTO `oc_setting`' => 'INSERT'] as $p => $k) if (strpos($sql, $p) === 0) return $k;
		foreach (['oc_store`', 'oc_language`'] as $k) if (strpos($sql, $k) !== false) return $k;
		return '?'; }
	function table($sql) { return preg_match('~oc_([a-z_]+)~', str_replace('\\', '', $sql), $m) ? $m[1] : ''; }
	// "KIND table" as the checks below name a query
	function name($sql) { $t = $this->table($sql); $k = $this->kind($sql); return in_array($k, ['GET_LOCK', 'RELEASE_LOCK', 'DELETE', 'INSERT', 'oc_store`', 'oc_language`'], true) ? $k : "$k $t"; }
	function query($sql) { $this->log[] = $sql; $kind = $this->kind($sql); $t = $this->table($sql);
		foreach ([$kind, "$kind $t"] as $f) if (isset($this->fail[$f])) throw $this->fail[$f];
		switch ($kind) {
			case 'GET_LOCK': if ($this->lock) $this->held = true; else $this->foreign = true; return new R([['locked' => $this->lock]]);
			case 'RELEASE_LOCK': if ($this->held) $this->held = false; else $this->released_foreign = true; return new R([['RELEASE_LOCK' => 1]]);
			case 'DESCRIBE': preg_match('~` `(\w+)`$~', $sql, $m); return new R(isset($this->cols[$t][$m[1]]) ? [['Field' => $m[1], 'Type' => $this->cols[$t][$m[1]]]] : []);
			case 'ALTER': if (!$this->held) throw new LogicException('ALTER without the lock'); preg_match('~ ADD `(\w+)` (\S+)~', $sql, $m);
				if (isset($this->cols[$t][$m[1]])) throw new Exception("Duplicate column name '{$m[1]}'"); $this->cols[$t][$m[1]] = $m[2]; return new R();
			case 'CREATE': if (!$this->held) throw new LogicException('CREATE without the lock'); if (!isset($this->cols[$t])) {
				preg_match_all('~`(\w+)` (int\(11\)|text|varchar\(255\))~', $sql, $m); $this->cols[$t] = array_combine($m[1], $m[2]); $this->keys[$t] = ['manufacturer_id', 'language_id']; $this->rows[$t] = 0; } return new R();
			case 'TABLES': return new R(isset($this->cols[$t]) ? [['Tables_in_shop' => 'oc_' . $t]] : []);
			case 'STATUS': return new R(isset($this->status[$t]) ? [$this->status[$t]] : []);
			case 'COLUMNS': if (!isset($this->cols[$t])) throw new Exception("Table 'oc_$t' doesn't exist"); $r = []; foreach ($this->cols[$t] as $f => $ty) $r[] = ['Field' => $f, 'Type' => $ty]; return new R($r);
			case 'KEYS': $r = []; foreach ($this->keys[$t] ?? [] as $i => $c) $r[] = ['Key_name' => 'PRIMARY', 'Seq_in_index' => (string)($i + 1), 'Column_name' => $c]; return new R(array_reverse($r));
			case 'DELETE': $this->version = null; return new R();
			case 'INSERT': preg_match("~`value` = '([^']*)'~", $sql, $m); $this->version = $m[1]; return new R();
			case 'oc_store`': return new R([['store_id' => 1]]);
			case 'oc_language`': return new R([['language_id' => 1], ['language_id' => 2]]);
		}
		throw new LogicException("unexpected SQL $sql"); }
	// The schema of a finished migration
	function complete() { $this->cols['product_to_category']['main_category'] = 'tinyint(1)'; foreach (['product', 'category', 'information'] as $e) $this->cols[$e . '_description']['meta_h1'] = 'varchar(255)';
		$this->cols['manufacturer_description'] = ['manufacturer_id' => 'int(11)', 'language_id' => 'int(11)', 'description' => 'text', 'meta_title' => 'varchar(255)', 'meta_h1' => 'varchar(255)', 'meta_description' => 'varchar(255)', 'meta_keyword' => 'varchar(255)'];
		$this->keys['manufacturer_description'] = ['manufacturer_id', 'language_id']; return $this; } }
class Log { public $l = []; function write($m) { $this->l[] = $m; } }
class Cache { public $del = []; public $fail = false; function delete($k) { if ($this->fail) throw new RuntimeException('cache is down'); $this->del[] = $k; } }
class Config { public $d = []; public $sets = []; function get($k) { return $this->d[$k] ?? null; } function set($k, $v) { $this->sets[] = $k; $this->d[$k] = $v; } }

$fail = 0;
function check($name, $got, $exp) { global $fail; $ok = $got === $exp; if (!$ok) $fail++; echo ($ok ? 'ok   ' : 'FAIL ') . $name . ($ok ? '' : "\n       got: " . var_export($got, true) . "\n       exp: " . var_export($exp, true)) . "\n"; }
// Runs install() once more on the same database when $db is given
function run($setup = null, $db = null) {
	$db = $db ?: new DB(); $log = new Log(); $c = new Cache(); if ($setup) $setup($db, $c);
	$db->log = [];
	$s = new Seo_pro_schema($db, $log, $c);
	try { $ready = $s->install(); $thrown = null; } catch (\Throwable $e) { $ready = null; $thrown = get_class($e) . ': ' . $e->getMessage(); }
	return ['ready' => $ready, 'thrown' => $thrown, 'caps' => $s->getCapabilities(), 'kinds' => array_map([$db, 'name'], $db->log), 'sql' => $db->log, 'log' => $log->l, 'del' => $c->del, 'db' => $db];
}
function caps($main, $p, $c, $i, $m) { return ['main_category' => $main, 'h1_product' => $p, 'h1_category' => $c, 'h1_information' => $i, 'manufacturer_description' => $m]; }
function ddl($x) { return array_values(preg_grep('~^(ALTER|CREATE)~', $x['kinds'])); }
$all = caps(true, true, true, true, true); $none = caps(false, false, false, false, false);
$caches = ['product.seopath', 'category.seopath', 'seo_pro', 'seo_pro.0.1', 'seo_pro.0.2', 'seo_pro.1.1', 'seo_pro.1.2', 'product', 'category', 'manufacturer'];
$checks = ['DESCRIBE product_to_category', 'DESCRIBE product_description', 'DESCRIBE category_description', 'DESCRIBE information_description', 'TABLES manufacturer_description'];
$tail = ['oc_store`', 'oc_language`', 'DELETE', 'INSERT', 'RELEASE_LOCK'];
$full = ['GET_LOCK', 'DESCRIBE product_to_category', 'ALTER product_to_category', 'DESCRIBE product_description', 'ALTER product_description', 'DESCRIBE category_description', 'ALTER category_description',
	'DESCRIBE information_description', 'ALTER information_description', 'TABLES manufacturer_description', 'STATUS manufacturer', 'CREATE manufacturer_description', 'COLUMNS manufacturer_description', 'KEYS manufacturer_description', ...$tail];
$done = array_merge(['GET_LOCK'], array_slice($checks, 0, 4), ['TABLES manufacturer_description', 'COLUMNS manufacturer_description', 'KEYS manufacturer_description'], $tail);
$denied = fn($what) => new Exception("Error: $what command denied");
$complete = fn($d) => $d->complete();

echo "-- success\n";
$x = run();
check('fresh: ready, every capability', [$x['ready'], $x['thrown'], $x['caps']], [true, null, $all]);
check('fresh: lock, each check before its DDL, cache reset, version, release', $x['kinds'], $full);
check('fresh: DDL of main_category', $x['sql'][2], "ALTER TABLE `oc_product_to_category` ADD `main_category` tinyint(1) NOT NULL DEFAULT '0' AFTER `category_id`");
check('fresh: DDL of meta_h1', [$x['sql'][4], $x['sql'][6], $x['sql'][8]], array_map(fn($t) => "ALTER TABLE `oc_$t` ADD `meta_h1` varchar(255) NOT NULL DEFAULT '' AFTER `meta_title`", ['product_description', 'category_description', 'information_description']));
check('fresh: manufacturer_description with the engine and the collation of manufacturer', $x['sql'][11], "CREATE TABLE IF NOT EXISTS `oc_manufacturer_description` (`manufacturer_id` int(11) NOT NULL, `language_id` int(11) NOT NULL, `description` text NOT NULL, `meta_title` varchar(255) NOT NULL DEFAULT '', `meta_h1` varchar(255) NOT NULL DEFAULT '', `meta_description` varchar(255) NOT NULL DEFAULT '', `meta_keyword` varchar(255) NOT NULL DEFAULT '', PRIMARY KEY (`manufacturer_id`, `language_id`)) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
check('fresh: the table name in LIKE is escaped', [$x['sql'][9], $x['sql'][10]], ["SHOW TABLES LIKE 'oc\\\\_manufacturer\\\\_description'", "SHOW TABLE STATUS LIKE 'oc\\\\_manufacturer'"]);
check('fresh: version 1.7 written last, for store 0', [$x['db']->version, Seo_pro_schema::VERSION, strpos($x['sql'][17], "`store_id` = '0', `code` = 'seo_pro', `key` = 'seo_pro_schema_version'") !== false], ['1.7', '1.7', true]);
check('fresh: module caches and the core caches of the changed rows cleared', $x['del'], $caches);
check('fresh: lock released, nothing in the log', [$x['db']->held, $x['db']->released_foreign, $x['log']], [false, false, []]);
$x = run(null, $x['db']);
check('second run: no DDL, same result', [$x['ready'], $x['caps'], ddl($x), $x['db']->version, $x['db']->held], [true, $all, [], '1.7', false]);
$x = run($complete);
check('schema exists: ready without DDL', [$x['ready'], $x['caps'], $x['kinds']], [true, $all, $done]);
check('schema exists: version and caches', [$x['db']->version, $x['del'], $x['db']->held], ['1.7', $caches, false]);
$x = run(fn($d) => $d->cols['product_to_category']['main_category'] = 'tinyint(1)');
check('upgrade from 1.6.3 (main_category exists): only the new structures are created', [$x['caps'], ddl($x)], [$all, ['ALTER product_description', 'ALTER category_description', 'ALTER information_description', 'CREATE manufacturer_description']]);

echo "\n-- lock is busy\n";
$x = run(fn($d) => $d->lock = 0);
check('busy, nothing exists: not ready, only checks, nothing written', [$x['ready'], $x['thrown'], $x['caps'], $x['kinds'], $x['db']->version, $x['del']], [false, null, $none, array_merge(['GET_LOCK'], $checks), null, []]);
check('busy: the lock of the other request is not released, one line in the log', [$x['db']->released_foreign, $x['log']], [false, ['SEO Pro schema: the lock was not obtained, the next request tries again']]);
$x = run(function ($d) { $d->lock = 0; $d->complete(); });
check('busy, schema exists: ready, nothing written', [$x['ready'], $x['caps'], ddl($x), $x['db']->version, $x['db']->released_foreign], [true, $all, [], null, false]);
$x = run(function ($d) { $d->lock = 0; $d->cols['product_to_category']['main_category'] = 'tinyint(1)'; });
check('busy, only main_category exists: main_category ready, the rest not, no DDL', [$x['ready'], $x['caps'], ddl($x)], [true, caps(true, false, false, false, false), []]);
$x = run(function ($d) use ($denied) { $d->lock = 0; $d->fail['DESCRIBE'] = $denied('DESCRIBE'); });
check('busy, column checks fail: not ready, no exception', [$x['ready'], $x['thrown'], $x['caps'], $x['db']->released_foreign, count($x['log'])], [false, null, $none, false, 5]);
check('busy, column checks fail: the step of main_category in the log', $x['log'][1], 'SEO Pro schema: failed at the step "column check": Error: DESCRIBE command denied');
$x = run(fn($d) => $d->lock = null);
check('GET_LOCK answers NULL: as busy', [$x['ready'], $x['kinds']], [false, array_merge(['GET_LOCK'], $checks)]);

echo "\n-- failures\n";
$x = run(fn($d) => $d->fail['GET_LOCK'] = $denied('GET_LOCK'));
check('GET_LOCK throws, nothing exists: not ready, no DDL, no release', [$x['ready'], $x['thrown'], $x['kinds']], [false, null, array_merge(['GET_LOCK'], $checks)]);
check('GET_LOCK throws: step and reason in the log', $x['log'], ['SEO Pro schema: failed at the step "lock": Error: GET_LOCK command denied']);
$x = run(function ($d) use ($denied) { $d->fail['GET_LOCK'] = $denied('GET_LOCK'); $d->complete(); });
check('GET_LOCK throws, schema exists: ready, nothing written', [$x['ready'], $x['caps'], ddl($x), $x['db']->version], [true, $all, [], null]);
$x = run(function ($d) use ($denied) { $d->fail['GET_LOCK'] = $denied('GET_LOCK'); $d->fail['DESCRIBE'] = $denied('DESCRIBE'); });
check('GET_LOCK and the column checks throw: not ready, no exception', [$x['ready'], $x['thrown'], count($x['log'])], [false, null, 5]);
$x = run(fn($d) => $d->fail['DESCRIBE product_to_category'] = $denied('DESCRIBE'));
check('DESCRIBE of main_category throws: only main_category not ready, lock released', [$x['ready'], $x['thrown'], $x['caps'], $x['db']->held], [false, null, caps(false, true, true, true, true), false]);
check('DESCRIBE throws: log, no version', [$x['log'], $x['db']->version, $x['del']], [['SEO Pro schema: failed at the step "column check": Error: DESCRIBE command denied'], null, []]);
$x = run(fn($d) => $d->fail['ALTER'] = $denied('ALTER'));
check('no ALTER privilege: columns not ready, the new table is, no version, lock released', [$x['ready'], $x['thrown'], $x['caps'], $x['db']->version, $x['db']->held, $x['del']], [false, null, caps(false, false, false, false, true), null, false, []]);
check('no ALTER privilege: log', $x['log'], ['SEO Pro schema: failed at the step "column creation": Error: ALTER command denied',
	'SEO Pro schema: failed at the step "meta_h1 creation of product_description": Error: ALTER command denied', 'SEO Pro schema: failed at the step "meta_h1 creation of category_description": Error: ALTER command denied',
	'SEO Pro schema: failed at the step "meta_h1 creation of information_description": Error: ALTER command denied']);
$x['db']->fail = []; $x = run(null, $x['db']);
check('no ALTER privilege, then granted: the next run finishes without CREATE', [$x['ready'], $x['caps'], ddl($x), $x['db']->version], [true, $all, ['ALTER product_to_category', 'ALTER product_description', 'ALTER category_description', 'ALTER information_description'], '1.7']);
$x = run(fn($d) => $d->fail['ALTER'] = new TypeError('driver error'));
check('ALTER throws an Error: not ready, no exception, lock released', [$x['ready'], $x['thrown'], $x['db']->held], [false, null, false]);
foreach (['DELETE', 'INSERT'] as $kind) {
	$x = run(fn($d) => $d->fail[$kind] = $denied($kind));
	check("version $kind throws: the schema is ready, the version is not written", [$x['ready'], $x['thrown'], $x['caps'], $x['db']->version, $x['db']->held], [true, null, $all, null, false]);
	check("version $kind throws: caches were cleared before, log", [$x['del'], $x['log']], [$caches, ['SEO Pro schema: failed at the step "version record": Error: ' . $kind . ' command denied']]);
	$x['db']->fail = []; $x = run(null, $x['db']);
	check("version $kind throws: the next run repeats the unfinished part", [$x['ready'], $x['kinds'], $x['db']->version], [true, $done, '1.7']);
}
$x = run(fn($d, $c) => $c->fail = true);
check('cache reset throws: the schema is ready, the version is not written', [$x['ready'], $x['thrown'], $x['caps'], $x['db']->version, $x['db']->held], [true, null, $all, null, false]);
check('cache reset throws: log', $x['log'], ['SEO Pro schema: failed at the step "cache reset": cache is down']);
$x = run(fn($d) => $d->fail['oc_store`'] = $denied('SELECT'));
check('cache reset query throws: ready, version not written', [$x['ready'], $x['thrown'], $x['db']->version], [true, null, null]);
$x = run(fn($d) => $d->fail['RELEASE_LOCK'] = $denied('RELEASE_LOCK'));
check('RELEASE_LOCK throws: ready, no exception, version written', [$x['ready'], $x['thrown'], $x['db']->version], [true, null, '1.7']);
check('RELEASE_LOCK throws: log', $x['log'], ['SEO Pro schema: failed at the step "lock release": Error: RELEASE_LOCK command denied']);
$x = run(function ($d) use ($denied) { $d->fail['ALTER product_to_category'] = $denied('ALTER'); $d->fail['RELEASE_LOCK'] = $denied('RELEASE_LOCK'); });
check('ALTER and RELEASE_LOCK throw: the first reason stays in the log', [$x['ready'], $x['thrown'], $x['log']], [false, null, ['SEO Pro schema: failed at the step "column creation": Error: ALTER command denied', 'SEO Pro schema: failed at the step "lock release": Error: RELEASE_LOCK command denied']]);

echo "\n-- partial readiness\n";
$x = run(fn($d) => $d->fail['ALTER category_description'] = $denied('ALTER'));
check('ALTER of one table fails: only its capability is not ready', [$x['ready'], $x['caps']], [true, caps(true, true, false, true, true)]);
check('one capability not ready: no cache reset, no version', [$x['del'], $x['db']->version, $x['db']->held], [[], null, false]);
$x['db']->fail = []; $x = run(null, $x['db']);
check('the next run creates only the missing column and finishes', [$x['caps'], ddl($x), $x['db']->version, $x['del']], [$all, ['ALTER category_description'], '1.7', $caches]);
$x = run(fn($d) => $d->fail['CREATE'] = $denied('CREATE'));
check('CREATE fails: manufacturer_description not ready, the rest ready, no version', [$x['ready'], $x['caps'], $x['db']->version], [true, caps(true, true, true, true, false), null]);
check('CREATE fails: log', $x['log'], ['SEO Pro schema: failed at the step "manufacturer_description creation": Error: CREATE command denied']);
$x = run(fn($d) => $d->fail['COLUMNS'] = $denied('SHOW'));
check('the table cannot be described after CREATE: not ready', [$x['caps']['manufacturer_description'], $x['log']], [false, ['SEO Pro schema: failed at the step "manufacturer_description check": Error: SHOW command denied']]);
$x = run(fn($d) => $d->fail['TABLES'] = $denied('SHOW'));
check('SHOW TABLES fails: not ready, no CREATE', [$x['caps']['manufacturer_description'], in_array('CREATE manufacturer_description', $x['kinds'], true)], [false, false]);
$x = run(function ($d) { $d->lock = 0; });
check('busy: no CREATE', in_array('CREATE manufacturer_description', $x['kinds'], true), false);

echo "\n-- existing structures of other extensions\n";
$x = run(function ($d) { $d->complete(); $d->cols['product_description']['meta_h1'] = 'varchar(64)'; });
check('meta_h1 varchar(64): H1 of products not ready, the column is not changed', [$x['caps'], ddl($x), $x['db']->cols['product_description']['meta_h1'], $x['db']->version], [caps(true, false, true, true, true), [], 'varchar(64)', null]);
check('meta_h1 varchar(64): the conflict is in the log', $x['log'], ['SEO Pro schema: the column meta_h1 of product_description has the type varchar(64), a text of 255 characters is needed: H1 of this table is not used']);
$x = run(function ($d) { $d->complete(); $d->cols['category_description']['meta_h1'] = 'text'; $d->cols['information_description']['meta_h1'] = 'VARCHAR(512)'; });
check('meta_h1 as text or a longer varchar: ready', [$x['caps'], $x['db']->version], [$all, '1.7']);
$x = run(function ($d) { $d->complete(); $d->rows['manufacturer_description'] = 3; unset($d->cols['manufacturer_description']['meta_h1'], $d->cols['manufacturer_description']['description']); });
check('compatible table without meta_h1 and description: the columns are added, no CREATE', [$x['caps']['manufacturer_description'], ddl($x)], [true, ['ALTER manufacturer_description', 'ALTER manufacturer_description']]);
check('compatible table: non-destructive DDL, the rows stay', [array_values(preg_grep('~manufacturer_description` ADD~', $x['sql'])), $x['db']->rows['manufacturer_description'], $x['db']->version],
	[["ALTER TABLE `oc_manufacturer_description` ADD `description` text NOT NULL", "ALTER TABLE `oc_manufacturer_description` ADD `meta_h1` varchar(255) NOT NULL DEFAULT ''"], 3, '1.7']);
$x = run(function ($d) { $d->complete(); unset($d->cols['manufacturer_description']['meta_keyword']); $d->lock = 0; });
check('compatible table without a column, lock busy: not ready, no DDL', [$x['caps']['manufacturer_description'], ddl($x)], [false, []]);
foreach ([
	'primary key (manufacturer_id)' => fn($d) => $d->keys['manufacturer_description'] = ['manufacturer_id'],
	'primary key (language_id, manufacturer_id)' => fn($d) => $d->keys['manufacturer_description'] = ['language_id', 'manufacturer_id'],
	'no primary key' => fn($d) => $d->keys['manufacturer_description'] = [],
	'language_id varchar(5)' => fn($d) => $d->cols['manufacturer_description']['language_id'] = 'varchar(5)',
	'no column manufacturer_id' => function ($d) { unset($d->cols['manufacturer_description']['manufacturer_id']); },
	'meta_title varchar(64)' => fn($d) => $d->cols['manufacturer_description']['meta_title'] = 'varchar(64)',
	'description varchar(255)' => fn($d) => $d->cols['manufacturer_description']['description'] = 'varchar(255)'] as $name => $f) {
	$x = run(function ($d) use ($f) { $d->complete(); $d->rows['manufacturer_description'] = 2; $f($d); });
	check("incompatible manufacturer_description ($name): not ready, nothing changed, no version", [$x['caps'], ddl($x), $x['db']->version, $x['db']->rows['manufacturer_description']], [caps(true, true, true, true, false), [], null, 2]);
	check("incompatible manufacturer_description ($name): the conflict is in the log", count(preg_grep('~^SEO Pro schema: the table manufacturer_description is not compatible \(.+\): descriptions of manufacturers are not used$~', $x['log'])), 1);
}
$x = run(function ($d) { $d->complete(); $d->cols['manufacturer_description'] += ['store_id' => 'int(11)']; $d->cols['manufacturer_description']['description'] = 'mediumtext'; $d->cols['manufacturer_description']['manufacturer_id'] = 'int(10) unsigned'; });
check('extra column, mediumtext, unsigned id: compatible', [$x['caps']['manufacturer_description'], ddl($x)], [true, []]);

echo "\n-- options of the new table\n";
foreach ([
	'InnoDB, utf8mb3' => [['Engine' => 'InnoDB', 'Collation' => 'utf8mb3_general_ci'], ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci'],
	'no status row' => [null, ''],
	'engine with SQL' => [['Engine' => 'InnoDB; DROP TABLE x', 'Collation' => 'utf8_general_ci'], ' DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci'],
	'collation binary, engine NULL' => [['Engine' => null, 'Collation' => 'binary'], ''],
	'collation with a quote' => [['Engine' => 'MyISAM', 'Collation' => "utf8_x'"], ' ENGINE=MyISAM']] as $name => [$status, $options]) {
	$x = run(function ($d) use ($status) { if ($status === null) unset($d->status['manufacturer']); else $d->status['manufacturer'] = $status; });
	$create = array_values(preg_grep('~^CREATE~', $x['sql']))[0] ?? '';
	check("table options, $name", substr($create, strpos($create, '`language_id`))') + strlen('`language_id`))')), $options);
}

echo "\n-- getCapabilities and startup\n";
check('getCapabilities before install: all false', (new Seo_pro_schema(new DB(), new Log(), new Cache()))->getCapabilities(), $none);
$flags = ['seo_pro_h1_product_ready', 'seo_pro_h1_category_ready', 'seo_pro_h1_information_ready', 'seo_pro_manufacturer_description_ready'];
$config = new Config(); $config->d['seo_pro_schema_version'] = '1.7'; $db = new DB();
Seo_pro_schema::startup($config, $db, new Log(), new Cache());
check('stored version 1.7: no SQL, every flag set to true, seo_pro_schema_failed not set', [$db->log, $config->sets, array_map(fn($f) => $config->d[$f], $flags)], [[], $flags, [true, true, true, true]]);
foreach (['no version' => null, 'version 1.6.1 of 1.6.3' => '1.6.1'] as $name => $v) {
	$config = new Config(); $config->d['seo_pro_schema_version'] = $v; $db = new DB(); $db->fail['ALTER information_description'] = $denied('ALTER');
	Seo_pro_schema::startup($config, $db, new Log(), new Cache());
	check("$name: install runs, the flags follow the capabilities", [$config->d['seo_pro_schema_failed'], array_map(fn($f) => $config->d[$f], $flags), $db->version], [false, [true, true, false, true], null]);
}
$config = new Config(); $db = new DB(); $db->fail['GET_LOCK'] = $denied('GET_LOCK'); $db->fail['DESCRIBE'] = $denied('DESCRIBE'); $db->fail['TABLES'] = $denied('SHOW');
Seo_pro_schema::startup($config, $db, new Log(), new Cache());
check('nothing can be checked: every flag false, seo_pro_schema_failed true', [$config->d['seo_pro_schema_failed'], array_map(fn($f) => $config->d[$f], $flags)], [true, [false, false, false, false]]);

echo "\nfailed: $fail\n";
exit($fail ? 1 : 0);

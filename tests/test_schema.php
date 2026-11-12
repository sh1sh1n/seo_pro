<?php
// F4: Seo_pro_schema::install() on a stub database. Exit code 1 when a check fails.
// usage: php test_schema.php
error_reporting(E_ALL); define('DB_PREFIX', 'oc_');
set_error_handler(function ($no, $str, $file, $line) { throw new ErrorException($str, 0, $no, $file, $line); });
require __DIR__ . '/../seo_pro_oc3.ocmod/upload/system/library/seo_pro_schema.php';
require __DIR__ . '/../seo_pro_oc3.ocmod/upload/system/library/seo_pro_cache.php';

class R { public $row; public $rows; public $num_rows; function __construct($row, $n, $rows = []) { $this->row = $row; $this->num_rows = $n; $this->rows = $rows; } }
// $lock: what GET_LOCK answers. $has: the column exists. $fail: kinds of query that throw, kind => exception.
// $held: the lock is held by this connection. $foreign: the lock is held by another connection.
class DB { public $log = []; public $lock = 1; public $has = 0; public $fail = []; public $held = false; public $foreign = false; public $released_foreign = false; public $version = null;
	function escape($s) { return addslashes($s); }
	function kind($sql) { foreach (['GET_LOCK', 'RELEASE_LOCK', 'DESCRIBE', 'ALTER', 'DELETE', 'INSERT', 'oc_store`', 'oc_language`'] as $k) if (strpos($sql, $k) !== false) return $k; return '?'; }
	function query($sql) { $this->log[] = $sql; $kind = $this->kind($sql);
		if (isset($this->fail[$kind])) throw $this->fail[$kind];
		switch ($kind) {
			case 'GET_LOCK': if ($this->lock) $this->held = true; else $this->foreign = true; return new R(['locked' => $this->lock], 1);
			case 'RELEASE_LOCK': if ($this->held) $this->held = false; else $this->released_foreign = true; return new R(['RELEASE_LOCK' => 1], 1);
			case 'DESCRIBE': return new R([], $this->has);
			case 'ALTER': if (!$this->held) throw new LogicException('ALTER without the lock'); $this->has = 1; return new R([], 0);
			case 'DELETE': $this->version = null; return new R([], 0);
			case 'INSERT': preg_match("~`value` = '([^']*)'~", $sql, $m); $this->version = $m[1]; return new R([], 0);
			case 'oc_store`': return new R([], 1, [['store_id' => 1]]);
			case 'oc_language`': return new R([], 2, [['language_id' => 1], ['language_id' => 2]]);
		}
		throw new LogicException("unexpected SQL $sql"); } }
class Log { public $l = []; function write($m) { $this->l[] = $m; } }
class Cache { public $del = []; public $fail = false; function delete($k) { if ($this->fail) throw new RuntimeException('cache is down'); $this->del[] = $k; } }

$fail = 0;
function check($name, $got, $exp) { global $fail; $ok = $got === $exp; if (!$ok) $fail++; echo ($ok ? 'ok   ' : 'FAIL ') . $name . ($ok ? '' : "\n       got: " . var_export($got, true) . "\n       exp: " . var_export($exp, true)) . "\n"; }
// Runs install() once more on the same database when $db is given
function run($setup = null, $db = null) {
	$db = $db ?: new DB(); $log = new Log(); $c = new Cache(); if ($setup) $setup($db, $c);
	$db->log = [];
	try { $ready = (new Seo_pro_schema($db, $log, $c))->install(); $thrown = null; } catch (\Throwable $e) { $ready = null; $thrown = get_class($e) . ': ' . $e->getMessage(); }
	return ['ready' => $ready, 'thrown' => $thrown, 'kinds' => array_map([$db, 'kind'], $db->log), 'sql' => $db->log, 'log' => $log->l, 'del' => $c->del, 'db' => $db];
}
$caches = ['product.seopath', 'category.seopath', 'seo_pro', 'seo_pro.0.1', 'seo_pro.0.2', 'seo_pro.1.1', 'seo_pro.1.2'];
$full = ['GET_LOCK', 'DESCRIBE', 'ALTER', 'oc_store`', 'oc_language`', 'DELETE', 'INSERT', 'RELEASE_LOCK'];
$denied = fn($what) => new Exception("Error: $what command denied");

echo "-- success\n";
$x = run();
check('fresh: ready', [$x['ready'], $x['thrown']], [true, null]);
check('fresh: lock, check, ALTER, cache reset, version, release', $x['kinds'], $full);
check('fresh: DDL', $x['sql'][2], "ALTER TABLE `oc_product_to_category` ADD `main_category` tinyint(1) NOT NULL DEFAULT '0' AFTER `category_id`");
check('fresh: version written last, for store 0', [$x['db']->version, strpos($x['sql'][6], "`store_id` = '0', `code` = 'seo_pro', `key` = 'seo_pro_schema_version'") !== false], [Seo_pro_schema::VERSION, true]);
check('fresh: caches cleared', $x['del'], $caches);
check('fresh: lock released, nothing in the log', [$x['db']->held, $x['db']->released_foreign, $x['log']], [false, false, []]);
$x = run(null, $x['db']);
check('second run: no ALTER, same result', [$x['ready'], in_array('ALTER', $x['kinds']), $x['db']->version, $x['db']->held], [true, false, Seo_pro_schema::VERSION, false]);
$x = run(fn($d) => $d->has = 1);
check('column exists: ready without ALTER', [$x['ready'], $x['kinds']], [true, array_values(array_diff($full, ['ALTER']))]);
check('column exists: version and caches', [$x['db']->version, $x['del'], $x['db']->held], [Seo_pro_schema::VERSION, $caches, false]);

echo "\n-- lock is busy\n";
$x = run(fn($d) => $d->lock = 0);
check('busy, no column: not ready, nothing altered or written', [$x['ready'], $x['thrown'], $x['kinds'], $x['db']->version, $x['del']], [false, null, ['GET_LOCK', 'DESCRIBE'], null, []]);
check('busy: the lock of the other request is not released', [$x['db']->released_foreign, count($x['log'])], [false, 1]);
$x = run(function ($d) { $d->lock = 0; $d->has = 1; });
check('busy, column exists: ready, nothing written', [$x['ready'], $x['kinds'], $x['db']->version, $x['db']->released_foreign], [true, ['GET_LOCK', 'DESCRIBE'], null, false]);
$x = run(function ($d) use ($denied) { $d->lock = 0; $d->fail['DESCRIBE'] = $denied('DESCRIBE'); });
check('busy, column check fails: not ready, no exception', [$x['ready'], $x['thrown'], $x['db']->released_foreign, count($x['log'])], [false, null, false, 2]);
$x = run(fn($d) => $d->lock = null);
check('GET_LOCK answers NULL: as busy', [$x['ready'], $x['kinds']], [false, ['GET_LOCK', 'DESCRIBE']]);

echo "\n-- failures\n";
$x = run(fn($d) => $d->fail['GET_LOCK'] = $denied('GET_LOCK'));
check('GET_LOCK throws, no column: not ready, no ALTER, no release', [$x['ready'], $x['thrown'], $x['kinds']], [false, null, ['GET_LOCK', 'DESCRIBE']]);
check('GET_LOCK throws: step and reason in the log', $x['log'], ['SEO Pro schema: failed at the step "lock": Error: GET_LOCK command denied']);
$x = run(function ($d) use ($denied) { $d->fail['GET_LOCK'] = $denied('GET_LOCK'); $d->has = 1; });
check('GET_LOCK throws, column exists: ready, nothing written', [$x['ready'], $x['kinds'], $x['db']->version], [true, ['GET_LOCK', 'DESCRIBE'], null]);
$x = run(function ($d) use ($denied) { $d->fail['GET_LOCK'] = $denied('GET_LOCK'); $d->fail['DESCRIBE'] = $denied('DESCRIBE'); });
check('GET_LOCK and the column check throw: not ready, no exception', [$x['ready'], $x['thrown'], count($x['log'])], [false, null, 2]);
$x = run(fn($d) => $d->fail['DESCRIBE'] = $denied('DESCRIBE'));
check('DESCRIBE throws: not ready, lock released', [$x['ready'], $x['thrown'], $x['kinds'], $x['db']->held], [false, null, ['GET_LOCK', 'DESCRIBE', 'RELEASE_LOCK'], false]);
check('DESCRIBE throws: log', $x['log'], ['SEO Pro schema: failed at the step "column check": Error: DESCRIBE command denied']);
$x = run(fn($d) => $d->fail['ALTER'] = $denied('ALTER'));
check('no ALTER privilege: not ready, no version, lock released', [$x['ready'], $x['thrown'], $x['kinds'], $x['db']->version, $x['db']->held, $x['del']], [false, null, ['GET_LOCK', 'DESCRIBE', 'ALTER', 'RELEASE_LOCK'], null, false, []]);
check('no ALTER privilege: log', $x['log'], ['SEO Pro schema: failed at the step "column creation": Error: ALTER command denied']);
$x['db']->fail = []; $x = run(null, $x['db']);
check('no ALTER privilege, then granted: the next run finishes', [$x['ready'], $x['kinds'], $x['db']->version], [true, $full, Seo_pro_schema::VERSION]);
$x = run(fn($d) => $d->fail['ALTER'] = new TypeError('driver error'));
check('ALTER throws an Error: not ready, no exception, lock released', [$x['ready'], $x['thrown'], $x['db']->held], [false, null, false]);
foreach (['DELETE', 'INSERT'] as $kind) {
	$x = run(fn($d) => $d->fail[$kind] = $denied($kind));
	check("version $kind throws: the column is ready, the version is not written", [$x['ready'], $x['thrown'], $x['db']->has, $x['db']->version, $x['db']->held], [true, null, 1, null, false]);
	check("version $kind throws: caches were cleared before, log", [$x['del'], $x['log']], [$caches, ['SEO Pro schema: failed at the step "version record": Error: ' . $kind . ' command denied']]);
	$x['db']->fail = []; $x = run(null, $x['db']);
	check("version $kind throws: the next run repeats the unfinished part", [$x['ready'], $x['kinds'], $x['db']->version], [true, array_values(array_diff($full, ['ALTER'])), Seo_pro_schema::VERSION]);
}
$x = run(fn($d, $c) => $c->fail = true);
check('cache reset throws: the column is ready, the version is not written', [$x['ready'], $x['thrown'], $x['db']->version, $x['db']->held], [true, null, null, false]);
check('cache reset throws: log', $x['log'], ['SEO Pro schema: failed at the step "cache reset": cache is down']);
$x = run(fn($d) => $d->fail['oc_store`'] = $denied('SELECT'));
check('cache reset query throws: ready, version not written', [$x['ready'], $x['thrown'], $x['db']->version], [true, null, null]);
$x = run(fn($d) => $d->fail['RELEASE_LOCK'] = $denied('RELEASE_LOCK'));
check('RELEASE_LOCK throws: ready, no exception, version written', [$x['ready'], $x['thrown'], $x['db']->version], [true, null, Seo_pro_schema::VERSION]);
check('RELEASE_LOCK throws: log', $x['log'], ['SEO Pro schema: failed at the step "lock release": Error: RELEASE_LOCK command denied']);
$x = run(function ($d) use ($denied) { $d->fail['ALTER'] = $denied('ALTER'); $d->fail['RELEASE_LOCK'] = $denied('RELEASE_LOCK'); });
check('ALTER and RELEASE_LOCK throw: the first reason stays in the log', [$x['ready'], $x['thrown'], $x['log']], [false, null, ['SEO Pro schema: failed at the step "column creation": Error: ALTER command denied', 'SEO Pro schema: failed at the step "lock release": Error: RELEASE_LOCK command denied']]);

echo "\nfailed: $fail\n";
exit($fail ? 1 : 0);

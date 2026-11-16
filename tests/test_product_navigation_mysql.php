<?php
// Deliver the candidate runtime as PHP source on stdin, without copying files to the stand.
if (in_array('--bundle', $argv ?? [], true)) {
    $source = file_get_contents(__FILE__);
    $runtime = '';
    foreach (['seo_pro_oc3.ocmod/upload/system/library/seo_pro_schema.php', 'seo_pro_oc3.ocmod/upload/catalog/controller/extension/startup/seo_pro.php'] as $file) $runtime .= substr(file_get_contents(dirname(__DIR__) . '/' . $file), 5) . "\n";
    echo str_replace('// RUNTIME' . '_SOURCE', $runtime, $source); exit;
}
function storeConstants($path) {
    $tokens = token_get_all(file_get_contents($path)); $values = [];
    $literal = function ($t) {
        if (!is_array($t)) return null;
        if ($t[0] === T_LNUMBER) return (int)$t[1];
        if ($t[0] !== T_CONSTANT_ENCAPSED_STRING) return null;
        $s = substr($t[1], 1, -1);
        return $t[1][0] === "'" ? str_replace(["\\'", "\\\\"], ["'", "\\"], $s) : stripcslashes($s);
    };
    $tokens = array_values(array_filter($tokens, function ($t) { return !is_array($t) || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true); }));
    for ($i = 0; $i + 5 < count($tokens); $i++) {
        if (!is_array($tokens[$i]) || strtolower($tokens[$i][1]) !== 'define' || $tokens[$i + 1] !== '(') continue;
        $key = $literal($tokens[$i + 2]);
        if (!in_array($key, ['DB_HOSTNAME','DB_USERNAME','DB_PASSWORD','DB_DATABASE','DB_PORT','DB_PREFIX'], true)) continue;
        $value = $literal($tokens[$i + 4]);
        if ($tokens[$i + 3] !== ',' || $tokens[$i + 5] !== ')' || $value === null) throw new RuntimeException('unsupported computed database definition');
        $values[$key] = $value;
    }
    foreach (['DB_HOSTNAME','DB_USERNAME','DB_PASSWORD','DB_DATABASE','DB_PREFIX'] as $key) if (!array_key_exists($key, $values)) throw new RuntimeException('missing database definition');
    return $values;
}
class Registry { private $values = []; function set($k, $v) { $this->values[$k] = $v; } function get($k) { return $this->values[$k] ?? null; } }
class Config extends Registry {}
abstract class Controller { protected $registry; function __construct($r) { $this->registry = $r; } function __get($k) { return $this->registry->get($k); } }
class NavigationLog { function write($s) { echo "diagnostic: $s\n"; } }
class NavigationDB {
    public $connection; public $log = []; public $readonly = false;
    function __construct($connection) { $this->connection = $connection; }
    function escape($s) { return $this->connection->real_escape_string($s); }
    function query($sql) {
        if ($this->readonly && !preg_match('/^(SELECT|SHOW|EXPLAIN)\b/i', ltrim($sql))) throw new LogicException('read-only SQL guard');
        $start = microtime(true); $q = $this->connection->query($sql);
        $rows = $q instanceof mysqli_result ? $q->fetch_all(MYSQLI_ASSOC) : [];
        $this->log[] = ['sql' => $sql, 'rows' => count($rows), 'ms' => (microtime(true) - $start) * 1000];
        return (object)['rows' => $rows, 'row' => $rows[0] ?? [], 'num_rows' => count($rows)];
    }
}
// RUNTIME_SOURCE
if (!class_exists('ControllerExtensionStartupSeoPro')) {
    require dirname(__DIR__) . '/seo_pro_oc3.ocmod/upload/system/library/seo_pro_schema.php';
    require dirname(__DIR__) . '/seo_pro_oc3.ocmod/upload/catalog/controller/extension/startup/seo_pro.php';
}
function addressCall($c, $name, $id) { $m = new ReflectionMethod($c, $name); return $m->invoke($c, $id); }
function addressController($db, $mode = null) {
    $r = new Registry(); $cfg = new Config();
    foreach (['config_store_id' => 0, 'config_language_id' => 1, 'config_seo_url' => 1, 'config_seo_url_include_path' => 1, 'config_url' => 'https://test.mozap.ru/', 'config_ssl' => 'https://test.mozap.ru/', 'config_seo_url_postfix' => '.html', 'seo_pro_keyword_prefilter_mode' => $mode] as $k => $v) $cfg->set($k, $v);
    $r->set('db', $db); $r->set('config', $cfg); $r->set('log', new NavigationLog());
    return new ControllerExtensionStartupSeoPro($r);
}
function snapshotSeo($db, $prefix) { return $db->query('SELECT COUNT(*) AS n, MAX(seo_url_id) AS max_id FROM ' . $prefix . 'seo_url')->row; }
function fixtureMatrix($db, $original) {
    $run = date('YmdHis') . '_' . bin2hex(random_bytes(3)); $tables = []; $before = snapshotSeo($db, $original); $checks = 0;
    $assert = function ($ok, $label) use (&$checks) { if (!$ok) throw new RuntimeException('fixture assertion: ' . $label); $checks++; };
    $available = array_column($db->query('SHOW COLLATION')->rows, 'Collation');
    try {
        $schemas = [
            'seo_url' => "seo_url_id int NOT NULL, store_id int NOT NULL, language_id int NOT NULL, `query` varchar(255) NOT NULL, keyword varchar(255) NOT NULL, PRIMARY KEY(seo_url_id), KEY `query` (`query`(250)), KEY keyword (keyword(250))",
            'product_to_category' => 'product_id int NOT NULL, category_id int NOT NULL, main_category tinyint NOT NULL DEFAULT 0, PRIMARY KEY(product_id,category_id)',
            'category_path' => 'category_id int NOT NULL, path_id int NOT NULL, level int NOT NULL, PRIMARY KEY(category_id,path_id)',
            'category' => 'category_id int NOT NULL PRIMARY KEY, parent_id int NOT NULL, status tinyint NOT NULL',
            'category_to_store' => 'category_id int NOT NULL, store_id int NOT NULL, PRIMARY KEY(category_id,store_id)',
            'category_description' => 'category_id int NOT NULL, language_id int NOT NULL, PRIMARY KEY(category_id,language_id)'
        ];
        foreach ($schemas as $suffix => $schema) {
            $name = DB_PREFIX . $suffix;
            if ($db->query("SHOW TABLES LIKE '" . $db->escape($name) . "'")->num_rows) throw new RuntimeException('fixture name occupied');
            $db->query("CREATE TABLE $name ($schema) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"); $tables[] = $name;
        }
        echo 'created: ' . json_encode($tables) . "\n";
        $p = DB_PREFIX;
        $db->query("INSERT INTO {$p}category VALUES (10,0,1),(11,10,1)");
        $db->query("INSERT INTO {$p}category_path VALUES (10,10,0),(11,10,0),(11,11,1)");
        $db->query("INSERT INTO {$p}category_to_store VALUES (10,0),(11,0)");
        $db->query("INSERT INTO {$p}category_description VALUES (10,1),(11,1)");
        $db->query("INSERT INTO {$p}seo_url VALUES (1,0,1,'category_id=10','root'),(2,0,1,'category_id=11','leaf')");
        for ($batch = 0; $batch < 30; $batch++) {
            $values = [];
            for ($j = 0; $j < 1000; $j++) { $i = $batch * 1000 + $j; $values[] = '(' . (10000 + $i) . ",0,1,'unused=$i','load-$i')"; }
            $db->query("INSERT INTO {$p}seo_url VALUES " . implode(',', $values));
        }
        for ($id = 1; $id <= 20; $id++) {
            $db->query("INSERT INTO {$p}product_to_category VALUES ($id,11,1)");
            $db->query("INSERT INTO {$p}seo_url VALUES (" . (100 + $id) . ",0,1,'product_id=$id','p$id')");
        }
        foreach (['utf8mb4_general_ci', 'utf8mb4_unicode_ci', 'utf8mb4_0900_as_cs', 'utf8mb4_bin'] as $collation) {
            if (!in_array($collation, $available, true)) { echo "not run collation $collation on " . $db->connection->server_info . "\n"; continue; }
            $db->query("ALTER TABLE {$p}seo_url MODIFY keyword varchar(255) CHARACTER SET utf8mb4 COLLATE $collation NOT NULL");
            $c = addressController($db); $start = count($db->log);
            for ($id = 1; $id <= 20; $id++) $assert(addressCall($c, 'readProductAddressData', $id)['keyword'] === 'p' . $id, 'product keyword');
            $assert(addressCall($c, 'readProductCategoryData', 11)['path'] === '10_11', 'hierarchy');
            $calls = count($db->log) - $start;
            $assert($calls === 22, '20+1 address SQL and one metadata');
            for ($id = 1; $id <= 20; $id++) addressCall($c, 'readProductAddressData', $id);
            $assert(count($db->log) - $start === $calls, 'memoization');
            // Independent reference: first lowercase byte key wins; last usable assignment for an object wins.
            $pairs = ['Pair','pair','café','cafe','tail','tail ',str_repeat('a',250).'x',str_repeat('a',250).'y',''];
            $id = 500;
            foreach ($pairs as $kw) { $q = 'product_id=' . ($id % 2 ? 51 : 50); $db->query("INSERT INTO {$p}seo_url VALUES ($id,0,1,'$q','" . $db->escape($kw) . "')"); $id++; }
            $ref = []; $seen = [];
            foreach ($db->query("SELECT `query`, LOWER(keyword) AS keyword FROM {$p}seo_url ORDER BY seo_url_id")->rows as $row) { if (array_key_exists($row['keyword'], $seen)) continue; $seen[$row['keyword']] = true; $ref[$row['query']] = $row['keyword']; }
            foreach (['ci','exact'] as $mode) {
                // CI is compared with exact only on the confirmed CI branch; on CS the prefilter can miss a case pair.
                if ($mode === 'ci' && strpos($collation, '_ci') === false) continue;
                $c = addressController($db, $mode);
                foreach ([50,51] as $product) $assert(addressCall($c, 'readProductAddressData', $product)['keyword'] === ($ref['product_id=' . $product] ?? null), 'reference ownership ' . $mode);
            }
            $db->query("DELETE FROM {$p}seo_url WHERE seo_url_id BETWEEN 500 AND 508");
            echo "PASS fixtures $collation; address=21 metadata=1 repeat=0\n";
        }
        echo "PASS fixture checks=$checks\n";
    } finally {
        foreach (array_reverse($tables) as $name) $db->query('DROP TABLE ' . $name);
        foreach ($tables as $name) if ($db->query("SHOW TABLES LIKE '" . $db->escape($name) . "'")->num_rows) throw new RuntimeException('fixture cleanup failed');
        $after = snapshotSeo($db, $original);
        echo 'dropped: ' . json_encode($tables) . "\ncontrol seo_url before=" . json_encode($before) . ' after=' . json_encode($after) . "\n";
        if ($before !== $after) throw new RuntimeException('control seo_url changed');
    }
}
function benchmarkAddresses($db) {
    $db->readonly = true; echo 'server: ' . $db->connection->server_info . "\n";
    $groups = $db->query('SELECT category_id, COUNT(*) AS n FROM ' . DB_PREFIX . 'product_to_category WHERE main_category=1 GROUP BY category_id HAVING n >= 20 ORDER BY category_id LIMIT 3')->rows;
    if (!$groups) throw new RuntimeException('not enough marked products');
    $ids = [];
    foreach ($groups as $i => $g) {
        $limit = count($groups) === 3 ? ($i === 2 ? 6 : 7) : 20;
        foreach ($db->query('SELECT pc.product_id FROM ' . DB_PREFIX . 'product_to_category pc WHERE pc.main_category=1 AND pc.category_id=' . (int)$g['category_id'] . ' AND (SELECT COUNT(*) FROM ' . DB_PREFIX . 'product_to_category x WHERE x.product_id=pc.product_id AND x.main_category=1)=1 ORDER BY pc.product_id LIMIT ' . $limit)->rows as $row) $ids[] = (int)$row['product_id'];
    }
    $c = addressController($db); $offset = count($db->log); $start = microtime(true);
    foreach ($ids as $id) { $p = addressCall($c, 'readProductAddressData', $id); if ($p['main_category_id']) addressCall($c, 'readProductCategoryData', $p['main_category_id']); }
    $queries = array_slice($db->log, $offset); $counts = ['product_address' => 0, 'category_address' => 0, 'schema_metadata' => 0]; $first = [];
    foreach ($queries as $q) {
        $kind = strpos($q['sql'], 'AS product_id)') !== false ? 'product_address' : (strpos($q['sql'], 'AS category_id)') !== false ? 'category_address' : 'schema_metadata'); $counts[$kind]++; $first[$kind] = $first[$kind] ?? $q;
    }
    echo 'series N=' . count($ids) . ' groups=' . json_encode($counts) . ' ms=' . ((microtime(true) - $start) * 1000) . "\n";
    $offset = count($db->log); foreach ($ids as $id) addressCall($c, 'readProductAddressData', $id);
    echo 'repeat SQL=' . (count($db->log) - $offset) . "\n";
    foreach (['product_address', 'category_address'] as $kind) {
        if (!isset($first[$kind])) continue; $q = $first[$kind];
        $plan = $db->query('EXPLAIN ' . $q['sql'])->rows;
        echo "$kind rows=" . $q['rows'] . ' ms=' . $q['ms'] . ' SQL=' . $q['sql'] . "\nEXPLAIN " . json_encode($plan, JSON_UNESCAPED_UNICODE) . "\n";
        foreach ($plan as $row) if (in_array($row['table'], ['su','d'], true) && $row['type'] === 'ALL') throw new RuntimeException('STOP: full seo_url scan in CI address SELECT');
    }
}
try {
    $mode = getenv('SEO_PRO_MYSQL_MODE') ?: '--fixtures';
    foreach (['--fixtures','--read-only-benchmark','--stand-scenarios'] as $arg) if (in_array($arg, $argv ?? [], true)) $mode = $arg;
    if (!extension_loaded('mysqli')) { echo "not run: MySQLi unavailable\n"; exit(2); }
    $path = getenv('SEO_PRO_TEST_STORE_CONFIG'); if (!$path || !is_file($path)) { echo "not run: set SEO_PRO_TEST_STORE_CONFIG\n"; exit(2); }
    $v = storeConstants($path); if (!preg_match('/^[a-zA-Z0-9_]+$/D', $v['DB_PREFIX'])) throw new RuntimeException('unsupported database prefix');
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    try { $conn = new mysqli($v['DB_HOSTNAME'], $v['DB_USERNAME'], $v['DB_PASSWORD'], $v['DB_DATABASE'], (int)($v['DB_PORT'] ?? 3306)); $conn->set_charset('utf8mb4'); } catch (Throwable $e) { echo "not run: database connection unavailable\n"; exit(2); }
    define('DB_PREFIX', $mode === '--fixtures' ? 'seo_pro_fx_' . date('YmdHis') . '_' . bin2hex(random_bytes(3)) . '_' : $v['DB_PREFIX']);
    $db = new NavigationDB($conn);
    if ($mode === '--fixtures') fixtureMatrix($db, $v['DB_PREFIX']);
    elseif ($mode === '--read-only-benchmark') benchmarkAddresses($db);
    else { echo "not run: stand scenarios require verified installed candidate and baseline\n"; exit(2); }
} catch (Throwable $e) {
    // Database exceptions can contain connection details or store values: never print them.
    echo ($e instanceof mysqli_sql_exception ? 'FAIL database operation (details withheld)' : 'FAIL ' . $e->getMessage()) . "\n"; exit(1);
}

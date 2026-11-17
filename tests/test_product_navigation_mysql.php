<?php
// Deliver the candidate runtime as PHP source on stdin, without copying files to the stand.
if (in_array('--bundle', $argv ?? [], true)) {
    $source = file_get_contents(__FILE__);
    $http = file_get_contents(__DIR__ . '/test_product_navigation_http.php');
    $http = substr($http, strpos($http, '// BEGIN STAND HTTP'), strpos($http, '// END STAND HTTP') - strpos($http, '// BEGIN STAND HTTP'));
    $source = str_replace('// STAND_HTTP' . '_SOURCE', $http, $source);
    $runtime = '';
    $stand = rtrim((string)getenv('SEO_PRO_STAND_TESTS_DIR'), '/');
    if ($stand) {
        $manifest=[]; foreach (['scripts/suite.sh','baseline_cdecbdc/addr_cdecbdc.txt','baseline_cdecbdc/canon3_cdecbdc.txt','baseline_cdecbdc/mixed3_cdecbdc.txt'] as $path) { if(!is_file($stand.'/'.$path)) {fwrite(STDERR,"missing stand manifest\n");exit(2);} $manifest[$path]=file_get_contents($stand.'/'.$path); }
        $runtime .= "define('SEO_PRO_STAND_MANIFEST', '" . base64_encode(json_encode($manifest)) . "');\n";
    }
    $oc = rtrim((string)getenv('OC_DIR'), '/');
    if (!$oc || !is_file($oc . '/system/library/url.php')) { fwrite(STDERR, "set OC_DIR for stdin bundle\n"); exit(2); }
    foreach (['system/helper/utf8.php','system/library/url.php','system/library/request.php'] as $file) $runtime .= substr(file_get_contents($oc . '/' . $file), 5) . "\n";
    $runtime .= "define('SEO_PRO_CANDIDATE_SHA256', '" . hash_file('sha256', dirname(__DIR__) . '/seo_pro_oc3.ocmod/upload/catalog/controller/extension/startup/seo_pro.php') . "');\n";
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
class NavigationCache { private $data = []; function get($k) { return $this->data[$k] ?? null; } function set($k,$v) { $this->data[$k] = $v; } }
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
// STAND_HTTP_SOURCE
// RUNTIME_SOURCE
if (!class_exists('ControllerExtensionStartupSeoPro')) {
    $oc = rtrim((string)getenv('OC_DIR'), '/');
    if (!$oc) { echo "not run: set OC_DIR\n"; exit(2); }
    require $oc . '/system/helper/utf8.php';
    require $oc . '/system/library/url.php';
    require $oc . '/system/library/request.php';
    require dirname(__DIR__) . '/seo_pro_oc3.ocmod/upload/system/library/seo_pro_schema.php';
    require dirname(__DIR__) . '/seo_pro_oc3.ocmod/upload/catalog/controller/extension/startup/seo_pro.php';
}
function addressCall($c, $name, $id) { $m = new ReflectionMethod($c, $name); return $m->invoke($c, $id); }
function addressController($db, $mode = null) {
    $r = new Registry(); $cfg = new Config();
    foreach (['config_store_id' => 0, 'config_language_id' => 1, 'config_seo_url' => 1, 'config_seo_url_include_path' => 1, 'config_url' => 'https://test.mozap.ru/', 'config_ssl' => 'https://test.mozap.ru/', 'config_seo_url_postfix' => '.html', 'seo_pro_keyword_prefilter_mode' => $mode] as $k => $v) $cfg->set($k, $v);
    $r->set('db', $db); $r->set('cache', new NavigationCache()); $r->set('config', $cfg); $r->set('log', new NavigationLog());
    $url = new Url($cfg->get('config_url'), $cfg->get('config_ssl')); $r->set('url', $url);
    $c = new ControllerExtensionStartupSeoPro($r); $url->addRewrite($c);
    return $c;
}
function addressHref($c, $id) { return $c->url->link('product/product', ['product_id' => $id], true); }
function snapshotSeo($db, $prefix) { return $db->query('SELECT COUNT(*) AS n, MAX(seo_url_id) AS max_id FROM ' . $prefix . 'seo_url')->row; }
function fixtureMatrix($db, $original, $credentials) {
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
            $series=array_slice($db->log,$start);$milliseconds=array_sum(array_column($series,'ms'));
            echo 'fixture series collation='.$collation.' mode='.$c->config->get('seo_pro_keyword_prefilter_mode').' SQL_ms='.$milliseconds."\n";
            $assert($calls === 22, '20+1 address SQL and one metadata');
            for ($id = 1; $id <= 20; $id++) $assert(addressHref($c, $id) === 'https://test.mozap.ru/root/leaf/p' . $id . '.html', 'Url real SQL end to end');
            $assert(count($db->log) - $start === $calls, 'memoization');
            // Independent reference: first lowercase byte key wins; last usable assignment for an object wins.
            $pairs = ['Pair','pair','café','cafe','tail','tail ',str_repeat('a',250).'x',str_repeat('a',250).'y',str_repeat('a',250).'12',str_repeat('a',250).'123',str_repeat('a',250).'1234',str_repeat('a',250).'12345',''];
            $id = 500;
            foreach ($pairs as $kw) { $q = 'product_id=' . ($id % 2 ? 51 : 50); $db->query("INSERT INTO {$p}seo_url VALUES ($id,0,1,'$q','" . $db->escape($kw) . "')"); $id++; }
            foreach(['product_id=90','category_id=90','manufacturer_id=90','information_id=90','product/search','foreign_id=90','product_id=bad'] as $n=>$owner) {
                $first=700+2*$n;$last=$first+1;
                $db->query("INSERT INTO {$p}seo_url VALUES ($first,0,1,'$owner','OWNED-$n'),($last,0,1,'product_id=50','owned-$n')");
            }
            $db->query("INSERT INTO {$p}seo_url VALUES (400,1,1,'foreign_id=1','scoped'),(401,0,2,'foreign_id=1','scoped'),(801,0,1,'product_id=51','scoped')");
            $ref = []; $seen = [];
            foreach ($db->query("SELECT `query`, LOWER(keyword) AS keyword FROM {$p}seo_url WHERE store_id=0 AND language_id=1 ORDER BY seo_url_id")->rows as $row) { if (array_key_exists($row['keyword'], $seen)) continue; $seen[$row['keyword']] = true; $ref[$row['query']] = $row['keyword']; }
            foreach (['ci','exact'] as $mode) {
                // CI is compared with exact only on the confirmed CI branch; on CS the prefilter can miss a case pair.
                if ($mode === 'ci' && strpos($collation, '_ci') === false) continue;
                $c = addressController($db, $mode);
                foreach ([50,51] as $product) {
                    $assert(addressCall($c, 'readProductAddressData', $product)['keyword'] === ($ref['product_id=' . $product] ?? null), 'reference ownership ' . $mode);
                    $query = end($db->log)['sql']; $joined = $db->query($query)->rows; $actual = [];
                    foreach ($joined as $row) if ($row['earlier_alias_id'] !== null) $actual[] = (int)$row['alias_id'] . ':' . (int)$row['earlier_alias_id'];
                    $all = $db->query("SELECT seo_url_id, `query`, LOWER(keyword) AS keyword FROM {$p}seo_url WHERE store_id=0 AND language_id=1 ORDER BY seo_url_id")->rows; $expected = [];
                    foreach ($all as $su) if ($su['query'] === 'product_id=' . $product) foreach ($all as $d) if ((int)$d['seo_url_id'] < (int)$su['seo_url_id'] && $d['keyword'] === $su['keyword']) $expected[] = (int)$su['seo_url_id'] . ':' . (int)$d['seo_url_id'];
                    sort($actual); sort($expected); $assert($actual === $expected, 'all earlier owner pairs ' . $mode);
                }
            }
            if (strpos($collation, '_ci') !== false) {
                $db->query("INSERT INTO {$p}seo_url VALUES (650,0,1,'product_id=70','café-fallback')");
                $incoming = addressController($db);
                addressCall($incoming, 'loadCache', false);
                $page = addressCall($incoming, 'resolveAddress', 'cafe-fallback.html');
                $assert(($page['params']['product_id'] ?? null) === '70', 'real collation incoming cafe/café fallback');
                $db->query("UPDATE {$p}seo_url SET keyword='renamed-fallback' WHERE seo_url_id=650");
                addressCall($incoming, 'resolveAddress', 'café-fallback.html');
                $stale = new ReflectionProperty($incoming, 'stale');
                $assert($stale->getValue($incoming) === true, 'UPDATE detected by existing incoming map verification');
                addressCall($incoming, 'loadCache', true);
                $assert(addressCall($incoming, 'resolveAddress', 'café-fallback.html') === null, 'old keyword is unknown after existing stale rebuild');
                $db->query("DELETE FROM {$p}seo_url WHERE seo_url_id=650");
            }
            $db->query("DELETE FROM {$p}seo_url WHERE seo_url_id BETWEEN 500 AND 512 OR seo_url_id BETWEEN 700 AND 713 OR seo_url_id IN (400,401,801)");
            echo "PASS fixtures $collation; address=21 metadata=1 repeat=0\n";
        }
        $originalRow=$db->query('SELECT * FROM '.DB_PREFIX.'seo_url WHERE seo_url_id=101')->row;
        $state=new StandScenario($db,$credentials,'fixture-restore',['seo_url'=>'seo_url_id=101']);
        try {
            $db->query("UPDATE ".DB_PREFIX."seo_url SET keyword='fixture-injected-change' WHERE seo_url_id=101");
            $state->insert('seo_url',['seo_url_id'=>900,'store_id'=>0,'language_id'=>1,'query'=>'product_id=999','keyword'=>'fixture-injected-new']);
            throw new RuntimeException('fixture injected failure');
        }catch(RuntimeException $e){$assert($e->getMessage()==='fixture injected failure','injected failure caught');}
        finally{$state->restore();}
        $assert($db->query('SELECT * FROM '.DB_PREFIX.'seo_url WHERE seo_url_id=101')->row===$originalRow,'row restored after exception');
        $assert($db->query('SELECT * FROM '.DB_PREFIX.'seo_url WHERE seo_url_id=900')->num_rows===0,'only own inserted row removed after exception');
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
    foreach ($ids as $id) { addressHref($c, $id); }
    $queries = array_slice($db->log, $offset); $counts = ['product_address' => 0, 'category_address' => 0, 'schema_metadata' => 0]; $first = [];
    foreach ($queries as $q) {
        $kind = strpos($q['sql'], 'AS product_id)') !== false ? 'product_address' : (strpos($q['sql'], 'AS category_id)') !== false ? 'category_address' : 'schema_metadata'); $counts[$kind]++; $first[$kind] = $first[$kind] ?? $q;
    }
    echo 'series N=' . count($ids) . ' groups=' . json_encode($counts) . ' ms=' . ((microtime(true) - $start) * 1000) . "\n";
    $offset = count($db->log); foreach ($ids as $id) addressHref($c, $id);
    echo 'repeat SQL=' . (count($db->log) - $offset) . "\n";
    foreach (['product_address', 'category_address'] as $kind) {
        if (!isset($first[$kind])) continue; $q = $first[$kind];
        $plan = $db->query('EXPLAIN ' . $q['sql'])->rows;
        echo "$kind rows=" . $q['rows'] . ' ms=' . $q['ms'] . ' SQL=' . $q['sql'] . "\nEXPLAIN " . json_encode($plan, JSON_UNESCAPED_UNICODE) . "\n";
        try { $actual=$db->query('EXPLAIN ANALYZE '.$q['sql'])->rows; echo 'EXPLAIN ANALYZE '.json_encode($actual,JSON_UNESCAPED_UNICODE)."\n"; }
        catch(mysqli_sql_exception $e){echo 'not run EXPLAIN ANALYZE on '.$db->connection->server_info."\n";}
        foreach ($plan as $row) if (in_array($row['table'], ['su','d'], true) && $row['type'] === 'ALL') throw new RuntimeException('STOP: full seo_url scan in CI address SELECT');
    }
    $one=array_column($db->query('SELECT pc.product_id FROM '.DB_PREFIX.'product_to_category pc WHERE pc.main_category=1 AND pc.category_id='.(int)$groups[0]['category_id'].' AND (SELECT COUNT(*) FROM '.DB_PREFIX.'product_to_category x WHERE x.product_id=pc.product_id AND x.main_category=1)=1 ORDER BY pc.product_id LIMIT 20')->rows,'product_id');
    $none=array_column($db->query('SELECT p.product_id FROM '.DB_PREFIX.'product p WHERE NOT EXISTS (SELECT 1 FROM '.DB_PREFIX.'product_to_category pc WHERE pc.product_id=p.product_id AND pc.main_category=1) ORDER BY p.product_id LIMIT 20')->rows,'product_id');
    foreach(['one-category'=>[$one,21],'no-main'=>[$none,20]] as $label=>[$sample,$budget]) {
        if(count($sample)!==20){echo "not run $label: fewer than 20 products\n";continue;}
        $c=addressController($db);$offset=count($db->log);$start=microtime(true);foreach($sample as $id)addressHref($c,(int)$id);
        $series=array_slice($db->log,$offset);$count=['product_address'=>0,'category_address'=>0,'schema_metadata'=>0];
        foreach($series as $q){$kind=strpos($q['sql'],'AS product_id)')!==false?'product_address':(strpos($q['sql'],'AS category_id)')!==false?'category_address':'schema_metadata');$count[$kind]++;}
        $offset=count($db->log);foreach($sample as $id)addressHref($c,(int)$id);$repeat=count($db->log)-$offset;
        echo $label.' N=20 groups='.json_encode($count).' repeat='.$repeat.' ms='.((microtime(true)-$start)*1000)."\n";
        if($count['product_address']+$count['category_address']>$budget||$count['schema_metadata']>1||$repeat!==0)throw new RuntimeException('SQL budget exceeded');
    }

}
function verifyStandBuild($build, $db) {
    $root = '/home/mozap-test/htdocs/test.mozap.ru/';
    $expected = $build === 'baseline' ? '0408eceaa6019b377f7a41388786c98fbb5c740a3a59ebb6527ef078cfeb457b' : (defined('SEO_PRO_CANDIDATE_SHA256') ? SEO_PRO_CANDIDATE_SHA256 : hash_file('sha256', dirname(__DIR__) . '/seo_pro_oc3.ocmod/upload/catalog/controller/extension/startup/seo_pro.php'));
    if (!in_array($build, ['baseline','candidate'], true) || hash_file('sha256', $root . 'catalog/controller/extension/startup/seo_pro.php') !== $expected) throw new RuntimeException('installed controller does not match requested build');
    if (hash_file('sha256', $root . 'system/framework.php') !== '15f86433ae1245e1ef0bbc3372e8a3d8a55460904a566c9526f46505b34beae6' || hash_file('sha256', $root . 'system/library/config.php') !== '270ea1919a0e241c88e7a156b2061b6f440b2a0c98b53cb5f18bf3c40a2b992f') throw new RuntimeException('framework configuration chain changed; recheck effective cache engine');
    $default = file_get_contents($root . 'system/config/default.php'); $catalog = file_get_contents($root . 'system/config/catalog.php');
    if (!preg_match("/\['cache_engine'\]\s*=\s*'file'/", $default) || strpos($catalog, 'cache_engine') !== false) throw new RuntimeException('effective file cache not established');
    $mod = $db->query("SELECT version,status,xml FROM " . DB_PREFIX . "modification WHERE code='seo_pro'")->row;
    if (!$mod || (int)$mod['status'] !== 1 || hash('sha256',$mod['xml']) !== 'fd8bbc9f818b39e87e84b84912ac21f3093d347efbe7d0536c2c8117421b6cd5') throw new RuntimeException('installed OCMOD differs from unchanged 1.8 operations');
    echo "modification version=" . $mod['version'] . " status=" . $mod['status'] . " xml_sha256=" . hash('sha256',$mod['xml']) . "\n";
    echo "verified build=$build controller_sha256=$expected; effective cache=file by unchanged framework/config load chain default+catalog before Cache\n";
}
class StandScenario {
    private $db; private $saved = []; private $created = []; private $counts = []; private $seo; private $keys = [];
    function __construct($db, $credentials, $name, $targets) {
        $this->db = $db; $this->seo = snapshotSeo($db, DB_PREFIX);
        foreach ($targets as $table => $where) {
            if (!preg_match('/^[a-z_]+$/D', $table)) throw new LogicException('invalid scenario table');
            $this->saved[$table] = $db->query('SELECT * FROM ' . DB_PREFIX . $table . ' WHERE ' . $where)->rows;
            $this->counts[$table] = $db->query('SELECT COUNT(*) AS n FROM ' . DB_PREFIX . $table)->row['n'];
            $primary = $db->query("SHOW KEYS FROM " . DB_PREFIX . $table . " WHERE Key_name='PRIMARY'")->rows;
            if (!$primary) throw new RuntimeException('scenario table has no primary key');
            usort($primary, function ($a,$b) { return (int)$a['Seq_in_index'] <=> (int)$b['Seq_in_index']; });
            $this->keys[$table] = array_column($primary, 'Column_name');
        }
        $dir = getenv('HOME') . '/backups'; if (!is_dir($dir) && !mkdir($dir,0700,true)) throw new RuntimeException('backup directory unavailable');
        $file = $dir . '/seo_pro_navigation_' . $name . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.sql';
        // The password is only in the child environment, never on its command line or in output.
        $cmd = ['mysqldump','--host=' . $credentials['DB_HOSTNAME'],'--port=' . (int)($credentials['DB_PORT'] ?? 3306),'--user=' . $credentials['DB_USERNAME'],'--skip-lock-tables','--no-tablespaces',$credentials['DB_DATABASE']];
        foreach (array_keys($targets) as $table) $cmd[] = DB_PREFIX . $table;
        $process = proc_open($cmd,[0=>['pipe','r'],1=>['file',$file,'x'],2=>['pipe','w']],$pipes,null,array_merge(getenv(),['MYSQL_PWD'=>$credentials['DB_PASSWORD']]));
        if (!is_resource($process)) throw new RuntimeException('backup process unavailable');
        fclose($pipes[0]); stream_get_contents($pipes[2]); fclose($pipes[2]); $code = proc_close($process);
        if ($code !== 0 || !is_file($file) || filesize($file) === 0) { if (is_file($file)) unlink($file); throw new RuntimeException('backup failed; scenario not started'); }
        chmod($file,0600); echo "backup=$file tables=" . implode(',', array_keys($targets)) . "\n";
    }
    function insert($table,$row) {
        if (!isset($this->saved[$table])) throw new LogicException('table not backed up');
        $this->writeRow('INSERT',$table,$row);
        $this->created[] = [$table, array_intersect_key($row,array_flip($this->keys[$table]))];
    }
    private function predicate($row) { $out=[]; foreach ($row as $k=>$v) $out[]='`'.$k.'` <=> '.($v===null?'NULL':"'".$this->db->escape((string)$v)."'"); return implode(' AND ',$out); }
    private function writeRow($verb,$table,$row) {
        $cols = implode(',',array_map(function($k){return '`'.$k.'`';},array_keys($row)));
        $values=implode(',',array_map(function($v){return $v===null?'NULL':"'".$this->db->escape((string)$v)."'";},$row));
        $this->db->query($verb.' INTO '.DB_PREFIX.$table.' ('.$cols.') VALUES ('.$values.')');
    }
    function restore() {
        foreach (array_reverse($this->created) as [$table,$row]) $this->db->query('DELETE FROM '.DB_PREFIX.$table.' WHERE '.$this->predicate($row));
        foreach ($this->saved as $table=>$rows) foreach ($rows as $row) $this->writeRow('REPLACE',$table,$row);
        foreach ($this->saved as $table=>$rows) {
            foreach ($rows as $row) {
                $key=array_intersect_key($row,array_flip($this->keys[$table]));
                $current=$this->db->query('SELECT * FROM '.DB_PREFIX.$table.' WHERE '.$this->predicate($key))->row;
                if ($current!==$row) throw new RuntimeException('scenario restoration row mismatch '.$table);
            }
            if ($this->db->query('SELECT COUNT(*) AS n FROM '.DB_PREFIX.$table)->row['n']!==$this->counts[$table]) throw new RuntimeException('scenario restoration count mismatch '.$table);
        }
        $after=snapshotSeo($this->db,DB_PREFIX); echo 'restored seo_url before='.json_encode($this->seo).' after='.json_encode($after)."\n";
        if ($after!==$this->seo) throw new RuntimeException('scenario restoration COUNT/MAX mismatch');
    }
}
function runStandScenarios($db,$credentials,$build) {
    verifyStandBuild($build,$db);
    if (!function_exists('standHttpRequest')) throw new RuntimeException('use --bundle for stand HTTP functions');
    if (getenv('SEO_PRO_STAND_SCENARIO') === 'verify') { standVerifyProducts($db,$build); return; }
    $base='https://test.mozap.ru'; $checks=0;
    $assert=function($ok,$name)use(&$checks){$checks++;if(!$ok)throw new RuntimeException('scenario assertion '.$name);};
    $request=function($uri,$status,$location=null)use($base,$assert){$r=standHttpRequest($base.$uri);$okLocation=$location===null?($status===301?$r['location']!=='':$r['location']===''):$r['location']===$location;$assert($r['status']===$status&&$okLocation,$uri);return $r;};
    $id=1000001; $aliases=$db->query("SELECT * FROM ".DB_PREFIX."seo_url WHERE store_id=0 AND language_id=1 AND `query`='product_id=$id' ORDER BY seo_url_id")->rows;
    if (count($aliases)!==1) throw new RuntimeException('scenario requires one product alias');
    $alias=$aliases[0]; $aliasId=(int)$alias['seo_url_id'];
    $original=$request('/index.php?route=product/product&product_id='.$id,301)['location'];
    $token='load-nav-'.bin2hex(random_bytes(6));
    $state=new StandScenario($db,$credentials,'keyword',['seo_url'=>'seo_url_id='.$aliasId]);
    try {
        // Warm the actual map, then UPDATE without changing its id; no cache clear between requests.
        standHttpRequest($original);
        $db->query("UPDATE ".DB_PREFIX."seo_url SET keyword='".$db->escape($token)."' WHERE seo_url_id=$aliasId");
        $target=str_replace($alias['keyword'],$token,$original);
        $request('/index.php?route=product/product&product_id='.$id,301,$target);
        $request(standUri($original),404); $request(standUri($target),200);
        $db->query('DELETE FROM '.DB_PREFIX.'seo_url WHERE seo_url_id='.$aliasId);
        $request(standUri($target),404);
    } finally {$state->restore();}
    $request(standUri($original),200);
    $assert($db->query("SELECT seo_url_id FROM ".DB_PREFIX."seo_url WHERE keyword='".$db->escape($token)."'")->num_rows===0,'temporary keyword absent');
    // A direct main-category switch changes new outgoing links and the old incoming path only in the candidate.
    if ($build==='candidate') {
        $marks=$db->query('SELECT * FROM '.DB_PREFIX.'product_to_category WHERE product_id='.$id)->rows;
        $other=$db->query('SELECT category_id FROM '.DB_PREFIX.'category WHERE status=1 AND category_id NOT IN (SELECT category_id FROM '.DB_PREFIX.'product_to_category WHERE product_id='.$id.') ORDER BY category_id LIMIT 1')->row;
        if (!$other) throw new RuntimeException('no category for main switch scenario');
        $category=(int)$other['category_id'];
        $state=new StandScenario($db,$credentials,'main',['product_to_category'=>'product_id='.$id]);
        try {
            $db->query('UPDATE '.DB_PREFIX.'product_to_category SET main_category=0 WHERE product_id='.$id);
            $state->insert('product_to_category',['product_id'=>$id,'category_id'=>$category,'main_category'=>1]);
            $c=addressController($db); $target=str_replace('&amp;','&',addressHref($c,$id));
            $assert($target!==$original,'new main changes outgoing Url');
            $request(standUri($original),301,$target); $request(standUri($target),200);
        } finally {$state->restore();}
    }
    // The old unsafe foreign.php phases are one self-restoring scenario now.
    $seo=snapshotSeo($db,DB_PREFIX);$newId=(int)$seo['max_id']+1;
    $pages=['/','/desktops/','/camera/','/component/monitor/test.html','/index.php?route=product/search&search=apple','/index.php?route=information/sitemap','/index.php?route=product/product/review&product_id=42','/index.php?route=product/compare','/no-such-page.html'];
    $snapshot=function()use($pages,$base){$out=[];foreach($pages as $uri){$r=standHttpRequest($base.$uri);preg_match_all('~(?:href|src|action)="([^"]*)"~',$r['body'],$m);$out[$uri]=[$r['status'],$m[1]];}return $out;};
    $before=$snapshot(); $state=new StandScenario($db,$credentials,'foreign',['seo_url'=>'seo_url_id='.$newId]);
    try {
        $state->insert('seo_url',['seo_url_id'=>$newId,'store_id'=>0,'language_id'=>1,'query'=>'blog_id=3','keyword'=>$token]);
        foreach (['/'.$token,'/'.$token.'/','/'.$token.'.html','/desktops/'.$token.'.html','/'.$token.'.html?product_id=28'] as $uri) $request($uri,404);
        $assert($snapshot()===$before,'foreign row preserves nine page link sets');
    } finally {$state->restore();}
    $assert($snapshot()===$before,'foreign removal preserves nine page link sets');

    // Route INSERT / DELETE, with the original seo_url id explicitly restored by the scenario guard.
    $route='information/sitemap';
    if ($db->query("SELECT seo_url_id FROM ".DB_PREFIX."seo_url WHERE store_id=0 AND language_id=1 AND `query`='$route'")->num_rows) throw new RuntimeException('route fixture is already occupied');
    $newId=(int)snapshotSeo($db,DB_PREFIX)['max_id']+1;
    $state=new StandScenario($db,$credentials,'route',['seo_url'=>'seo_url_id='.$newId]);
    try {
        $request('/index.php?route='.$route,200);
        $state->insert('seo_url',['seo_url_id'=>$newId,'store_id'=>0,'language_id'=>1,'query'=>$route,'keyword'=>$token]);
        $request('/index.php?route='.$route,301,$base.'/'.$token.'/'); $request('/'.$token.'/',200);
        $db->query('DELETE FROM '.DB_PREFIX.'seo_url WHERE seo_url_id='.$newId); $request('/'.$token.'/',404);
    } finally {$state->restore();}
    // Existing Deals article keyword UPDATE / DELETE, without clearing the active map.
    $blog=$db->query("SELECT * FROM ".DB_PREFIX."seo_url WHERE store_id=0 AND language_id=1 AND `query`='blogarticle_id=1' ORDER BY seo_url_id")->rows;
    if(count($blog)===1) {
        $blogId=(int)$blog[0]['seo_url_id'];$old=$blog[0]['keyword'];
        $state=new StandScenario($db,$credentials,'blog-keyword',['seo_url'=>'seo_url_id='.$blogId]);
        try {
            $request('/'.$old,200);
            $db->query("UPDATE ".DB_PREFIX."seo_url SET keyword='".$db->escape($token)."' WHERE seo_url_id=$blogId");
            $request('/'.$old,404);$request('/'.$token,200);
            $db->query('DELETE FROM '.DB_PREFIX.'seo_url WHERE seo_url_id='.$blogId);$request('/'.$token,404);
        }finally{$state->restore();}
        $request('/'.$old,200);
    }else echo "not run Deals keyword scenario: one article alias required\n";
    if ($build==='candidate') {
        $main=$db->query('SELECT category_id FROM '.DB_PREFIX.'product_to_category WHERE product_id='.$id.' AND main_category=1')->row;
        if(!$main)throw new RuntimeException('main category fixture unavailable');$cat=(int)$main['category_id'];
        $catAlias=$db->query("SELECT seo_url_id FROM ".DB_PREFIX."seo_url WHERE store_id=0 AND language_id=1 AND `query`='category_id=$cat' ORDER BY seo_url_id DESC LIMIT 1")->row;
        if(!$catAlias)throw new RuntimeException('main category keyword fixture unavailable');$catAliasId=(int)$catAlias['seo_url_id'];
        $state=new StandScenario($db,$credentials,'category-keyword',['seo_url'=>'seo_url_id='.$catAliasId]);
        try {
            standHttpRequest($original);
            $db->query("UPDATE ".DB_PREFIX."seo_url SET keyword='".$db->escape($token)."' WHERE seo_url_id=$catAliasId");
            $target=str_replace('&amp;','&',addressHref(addressController($db),$id));
            $assert($target!==$original,'category keyword changes outgoing Url');
            $request('/index.php?route=product/product&product_id='.$id,301,$target);$request(standUri($target),200);
        }finally{$state->restore();}
        $categoryRow=$db->query('SELECT * FROM '.DB_PREFIX.'category WHERE category_id='.$cat)->row;
        $state=new StandScenario($db,$credentials,'hierarchy',['category'=>'category_id='.$cat,'category_path'=>'category_id='.$cat]);
        try {
            standHttpRequest($original);
            $db->query('UPDATE '.DB_PREFIX.'category SET parent_id=0 WHERE category_id='.$cat);
            $db->query('DELETE FROM '.DB_PREFIX.'category_path WHERE category_id='.$cat);
            $state->insert('category_path',['category_id'=>$cat,'path_id'=>$cat,'level'=>0]);
            $target=str_replace('&amp;','&',addressHref(addressController($db),$id));
            $request('/index.php?route=product/product&product_id='.$id,301,$target);$request(standUri($target),200);
        }finally{$state->restore();}
    }else echo "not run candidate main/hierarchy/category-keyword expectations on baseline 1.8\n";
    // Reproduce the historical canon3 setup with cloned complete rows, inside the same backup/finally guard.
    $categoryTables=['oct_blogcategory','oct_blogcategory_description','oct_blogcategory_path','oct_blogcategory_to_store','oct_blogcategory_to_layout'];
    $articleTables=['oct_blogarticle','oct_blogarticle_description','oct_blogarticle_to_category','oct_blogarticle_to_store','oct_blogarticle_to_layout'];
    $targets=[];
    foreach($categoryTables as $table)$targets[$table]='blogcategory_id=2';
    foreach($articleTables as $table)$targets[$table]='blogarticle_id=2';
    $newId=(int)snapshotSeo($db,DB_PREFIX)['max_id']+1;$targets['seo_url']='seo_url_id BETWEEN '.$newId.' AND '.($newId+2);
    foreach($targets as $table=>$where)if($db->query('SELECT * FROM '.DB_PREFIX.$table.' WHERE '.$where)->num_rows)throw new RuntimeException('historical scenario IDs already occupied');
    if($db->query("SELECT seo_url_id FROM ".DB_PREFIX."seo_url WHERE store_id=0 AND language_id=1 AND (`query` IN ('product/search','blogarticle_id=2','blogcategory_id=2') OR keyword IN ('load-blog-sub','load-blog-nested-article','search'))")->num_rows)throw new RuntimeException('historical scenario aliases already occupied');
    $state=new StandScenario($db,$credentials,'canon3',$targets);
    try {
        foreach(array_merge($categoryTables,$articleTables) as $table) {
            if($table==='oct_blogcategory_path')continue;
            $key=in_array($table,$categoryTables,true)?'blogcategory_id':'blogarticle_id';
            $rows=$db->query('SELECT * FROM '.DB_PREFIX.$table.' WHERE '.$key.'=1')->rows;
            foreach($rows as $row) {
                $row[$key]=2;
                if($table==='oct_blogcategory'){$row['parent_id']=1;$row['status']=1;}
                if($table==='oct_blogarticle'){$row['status']=1;$row['viewed']=0;}
                if($table==='oct_blogarticle_to_category')$row['blogcategory_id']=2;
                if(substr($table,-12)==='_description') {
                    $name=$key==='blogcategory_id'?'load nested blog category':'load nested article';
                    $row['name']=$name;if(array_key_exists('meta_title',$row))$row['meta_title']=$name;if(array_key_exists('meta_h1',$row))$row['meta_h1']='';
                }
                $state->insert($table,$row);
            }
        }
        $state->insert('oct_blogcategory_path',['blogcategory_id'=>2,'blog_path_id'=>1,'level'=>0]);
        $state->insert('oct_blogcategory_path',['blogcategory_id'=>2,'blog_path_id'=>2,'level'=>1]);
        foreach([['blogcategory_id=2','load-blog-sub'],['blogarticle_id=2','load-blog-nested-article'],['product/search','search']] as $n=>$entry)$state->insert('seo_url',['seo_url_id'=>$newId+$n,'store_id'=>0,'language_id'=>1,'query'=>$entry[0],'keyword'=>$entry[1]]);
        standHttpRegression($build,'',true);
    }finally{$state->restore();}
    echo "PASS stand scenarios build=$build checks=$checks\n";
}

try {
    $mode = getenv('SEO_PRO_MYSQL_MODE') ?: '--fixtures';
    foreach (['--fixtures','--read-only-benchmark','--stand-scenarios'] as $arg) if (in_array($arg, $argv ?? [], true)) $mode = $arg;
    if (!extension_loaded('mysqli')) { echo "not run: MySQLi unavailable\n"; exit(2); }
    $path = getenv('SEO_PRO_TEST_STORE_CONFIG'); if (!$path || !is_readable($path)) { echo "not run: set SEO_PRO_TEST_STORE_CONFIG\n"; exit(2); }
    try { $v = storeConstants($path); if (!preg_match('/^[a-zA-Z0-9_]+$/D', $v['DB_PREFIX'])) throw new RuntimeException('unsupported prefix'); } catch (Throwable $e) { echo "not run: unsupported database configuration literals\n"; exit(2); }
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    try { $conn = new mysqli($v['DB_HOSTNAME'], $v['DB_USERNAME'], $v['DB_PASSWORD'], $v['DB_DATABASE'], (int)($v['DB_PORT'] ?? 3306)); $conn->set_charset('utf8mb4'); } catch (Throwable $e) { echo "not run: database connection unavailable\n"; exit(2); }
    define('DB_PREFIX', $mode === '--fixtures' ? 'seo_pro_fx_' . date('YmdHis') . '_' . bin2hex(random_bytes(3)) . '_' : $v['DB_PREFIX']);
    $db = new NavigationDB($conn);
    if ($mode === '--fixtures') fixtureMatrix($db, $v['DB_PREFIX'], $v);
    elseif ($mode === '--read-only-benchmark') benchmarkAddresses($db);
    else runStandScenarios($db, $v, getenv('SEO_PRO_STAND_BUILD') ?: '');
} catch (Throwable $e) {
    // Database exceptions can contain connection details or store values: never print them.
    echo ($e instanceof mysqli_sql_exception ? 'FAIL database operation (details withheld)' : 'FAIL ' . $e->getMessage()) . "\n"; exit(1);
}

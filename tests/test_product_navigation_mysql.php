<?php
// Deliver the candidate runtime as PHP source on stdin, without copying files to the stand.
/*
 * MODES
 *   legacy (unchanged): --fixtures | --read-only-benchmark | --stand-scenarios | --bundle
 *   supervised (Phase C, local emitter only):
 *     php tests/test_product_navigation_mysql.php --emit-supervised > bundle.php
 *     env  OC_DIR                     stock OpenCart 3.0.5.1 upload dir (utf8.php, url.php, request.php are bundled)
 *          SEO_PRO_RUNTIME_DIR        one baseline export or candidate root containing seo_pro_oc3.ocmod/upload/...
 *          SEO_PRO_BUILD_LABEL        baseline | candidate  (must equal manifest.build)
 *          SEO_PRO_CASE_MANIFEST      local path of the case manifest (schema below), validated before emission
 *          SEO_PRO_EMIT_MANIFEST_OUT  local path, must not exist; receives the bundle manifest (pnms-bundle/1, hashes)
 *     stdout: one PHP source for `php` on the remote stdin. It contains the supervisor support, the shared
 *     library, the remote entry and two separately encoded payloads (workload worker, cleanup worker).
 *     No DB config content, no shop file, no HTTP dispatcher; nothing is installed, no schema startup runs.
 *     Emission exit: 0 ok, 1 invalid input/verification failure, 2 missing local input.
 *   remote entry (inside the emitted bundle; for the later shell):
 *     ssh -o BatchMode=yes <target> env SEO_PRO_TEST_STORE_CONFIG=<remote-config-path> php < bundle.php
 *     Each remote process (supervisor, workload worker, cleanup worker) reads the credentials itself with
 *     storeConstants(); only the config PATH (and optionally SEO_PRO_PNMS_CHUNK_SECONDS) travels in the environment.
 *     stdout: one pnms-result/1 JSON document {status,label,reasons,run_id,chunk_id,stage,build,manifest_sha256,bundle,
 *     limits{effective,...},supervisor,workload{evidence},case_status,evidence_transfer{file:{bytes,sha256}},
 *     preflight (preflight stage only), evidence{"events.jsonl":string,"ledger.json":object,"metrics.json":object}|null};
 *     early errors carry the identifiers once the embedded case hash is verified. run_dir inside the shop installation
 *     (directory of the store config) is refused. exit status 0/1/2/3/4 (severity 4 > 1 > 3 > 2 > 0). Files: <run_dir>/chunks/<chunk_id>/
 *     {ledger.json, events.jsonl, metrics.json} (supervisor), workload.json (worker evidence, exclusive create),
 *     result.json (entry, exclusive create).
 *
 * CASE MANIFEST  schema "pnms-case/1" (JSON object, unknown keys rejected, every key below required unless noted)
 *   run_id, chunk_id   ^[a-z0-9][a-z0-9-]{0,31}$
 *   stage              preflight | read-only-stand | semantic | timing | diagnostic
 *                      (preflight = the single c001 chunk: server/feature inventory, frozen shop sample selection,
 *                       durable shop control, disposable probe, expected-error probe, LAST the SLEEP cancel probe)
 *   build              baseline | candidate      (semantic and diagnostic: candidate only)
 *   run_dir            absolute remote private dir ^/[A-Za-z0-9._/-]{1,200}$, no "..", no "//"
 *   fixture_prefix     ^pnsx_[a-z0-9]{6,20}_$    (ledger prefix; must not overlap the shop prefix, checked remotely)
 *   engine             null | MyISAM | InnoDB    (required for semantic/timing/diagnostic: the verified shop engine)
 *   collation          null | utf8mb4_general_ci | utf8mb4_unicode_ci | utf8mb4_0900_as_cs | utf8mb4_bin
 *                      (required for semantic/timing/diagnostic, null otherwise)
 *   variant            null | clean | conflict   (required for timing/diagnostic, null otherwise)
 *   mode               natural | forced-exact    (forced-exact: timing/diagnostic, candidate, CI collation only)
 *   repetition         null | 1..3               (required for read-only-stand and timing)
 *   pair_position      null | 1 | 2              (required for read-only-stand and natural timing; null otherwise)
 *   sample             read-only-stand only (null otherwise), frozen sorted shop product IDs from the preflight chunk
 *                      (workload.evidence.cases[id=sample].data.sample; also top-level preflight.sample):
 *                      {"three": null|{"groups":[{"category_id":int,"ids":[7]},{..7},{..6}]},
 *                       "one": null|{"category_id":int,"ids":[20]}, "none": null|{"ids":[20]}}
 *   sample_sha256      sha256 of the canonical sample JSON (null when sample is null)
 *   limits             optional: {chunk_seconds, cleanup_seconds, max_select, max_other} each <= supervisor default
 *                      remote env SEO_PRO_PNMS_CHUNK_SECONDS (optional): ^[1-9][0-9]{0,2}$, <= limits.chunk_seconds
 *                      (or 180) — lowers chunk_seconds only; anything else is refused (exit 1), never clamped
 *   expect             optional: {"runtime":{relpath:sha256}, "oc":{relpath:sha256}} enforced by the emitter
 */
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
// ==PNMS:lib:BEGIN==
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

/* Shared supervised-mode library (PNMS). Declarations only; shipped in the supervisor, workload and cleanup sources. */
final class PNMS
{
    const MANIFEST_SCHEMA = 'pnms-case/1';
    const BUNDLE_SCHEMA = 'pnms-bundle/1';
    const EVIDENCE_SCHEMA = 'pnms-evidence/1';
    const RESULT_SCHEMA = 'pnms-result/1';
    /** 83-chunk schedule: the frozen shop sample is selected inside the preflight chunk (no separate inventory chunk). */
    const STAGES = ['preflight', 'read-only-stand', 'semantic', 'timing', 'diagnostic'];
    const STAND_STAGES = ['read-only-stand'];
    /** Exact shop tables the preflight chunk may read (sample selection + durable control); it still writes only its probe. */
    const PREFLIGHT_SHOP_READ = ['seo_url', 'product', 'product_to_category', 'category', 'category_path', 'category_to_store', 'category_description'];
    /** Durable shop control read before and after the fixture work of every fixture-owning chunk. */
    const SHOP_CONTROL_READ = ['seo_url', 'product_to_category'];
    /** The one permitted server error of the disposable error-propagation probe (ER_BAD_FIELD_ERROR on the owned probe table). */
    const ERROR_PROBE_ERRNO = 1054;
    const TRANSFER_MAX = ['events.jsonl' => 8388608, 'ledger.json' => 1048576, 'metrics.json' => 8388608];
    const CHUNK_SECONDS_ENV = 'SEO_PRO_PNMS_CHUNK_SECONDS';
    const FIXTURE_STAGES = ['semantic', 'timing', 'diagnostic'];
    const BUILDS = ['baseline', 'candidate'];
    const COLLATIONS = ['utf8mb4_general_ci', 'utf8mb4_unicode_ci', 'utf8mb4_0900_as_cs', 'utf8mb4_bin'];
    const CI_COLLATIONS = ['utf8mb4_general_ci', 'utf8mb4_unicode_ci'];
    const ENGINES = ['MyISAM', 'InnoDB'];
    const FIXTURE_TABLES = ['seo_url', 'product_to_category', 'category_path', 'category', 'category_to_store', 'category_description'];
    const PROBE_TABLE = 'probe';
    const SHOP_READ_TABLES = ['seo_url', 'product', 'product_to_category', 'product_to_store', 'product_description', 'category', 'category_path',
        'category_to_store', 'category_description', 'store', 'language', 'manufacturer', 'information', 'setting'];
    const RUNTIME_ROOT = 'seo_pro_oc3.ocmod/upload/';
    const RUNTIME_FILES = ['system/library/seo_pro_schema.php', 'system/library/seo_pro_cache.php', 'catalog/controller/extension/startup/seo_pro.php'];
    const OC_FILES = ['system/helper/utf8.php', 'system/library/url.php', 'system/library/request.php'];
    const EVIDENCE_MAX = 4194304;
    const CASE_STATUSES = ['pass', 'fail', 'incomplete', 'unrun', 'pending-supervisor'];
    const BASE_URL = 'https://pnms.invalid/';
    /** Injected, synthetic and identical for both builds; hashed as config_sha256. Never read from the shop. */
    const CONFIG = ['config_store_id' => 0, 'config_language_id' => 1, 'config_seo_url' => 1, 'config_seo_url_include_path' => 1,
        'config_url' => self::BASE_URL, 'config_ssl' => self::BASE_URL, 'config_seo_url_postfix' => '.html'];
    const SAMPLE_SIZES = [7, 7, 6];
    const SERIES_N = 20;
    const PROBE_SLEEP = '5.5';
    const PROBE_EFFECTIVE_NS = 4000000000;
}

class PNMS_GuardError extends LogicException {}
class PNMS_Incomplete extends RuntimeException {}
class PNMS_AssertFail extends RuntimeException {}
class PNMS_ConfigError extends RuntimeException {}

final class PNMS_Lib
{
    public static function isStand($stage) { return in_array($stage, PNMS::STAND_STAGES, true); }
    public static function isFixture($stage) { return in_array($stage, PNMS::FIXTURE_STAGES, true); }

    /** Credentials of THIS process, read locally through the existing parser. Fixed error codes only. */
    public static function credentials()
    {
        $path = getenv('SEO_PRO_TEST_STORE_CONFIG');
        if (!is_string($path) || $path === '' || $path[0] !== '/' || !is_file($path) || !is_readable($path)) throw new PNMS_ConfigError('config-path');
        try {
            $v = storeConstants($path);
        } catch (Throwable $e) {
            throw new PNMS_ConfigError('config-literals');
        }
        foreach (['DB_HOSTNAME', 'DB_USERNAME', 'DB_PASSWORD', 'DB_DATABASE', 'DB_PREFIX'] as $k) $v[$k] = (string)$v[$k];
        if (isset($v['DB_PORT'])) $v['DB_PORT'] = (int)$v['DB_PORT'];
        if (!preg_match('/^[a-z0-9_]{1,32}$/D', $v['DB_PREFIX'])) throw new PNMS_ConfigError('config-prefix');
        return $v;
    }

    /** Shared by the worker (executes it) and the remote entry (permits exactly its digest). Read-only, owned table. */
    public static function errorProbeSql($probeTable)
    {
        return 'SELECT pnms_absent_column FROM `' . $probeTable . '`';
    }

    public static function evidencePath(array $m)
    {
        return rtrim($m['run_dir'], '/') . '/chunks/' . $m['chunk_id'] . '/workload.json';
    }

    /** Manual literal escaping for the worker adapter (the supervised driver exposes no escape call); assumes backslash escapes are enabled. */
    public static function escape($s)
    {
        return strtr((string)$s, ['\\' => '\\\\', "\0" => '\\0', "\n" => '\\n', "\r" => '\\r', "'" => "\\'", '"' => '\\"', "\x1a" => '\\Z']);
    }

    /** A loggable code: our own fixed messages, otherwise only the class name (raw driver/server text never). */
    public static function code(Throwable $e)
    {
        foreach (['PNMS_GuardError', 'PNMS_Incomplete', 'PNMS_AssertFail', 'PNMS_ConfigError', 'PNS_WorkerStop', 'PNS_WorkerFailure', 'PNS_ProtocolError', 'PNS_ControlError', 'PNS_LedgerError'] as $c) {
            if ($e instanceof $c) return $c . ':' . substr(preg_replace('/[^a-z0-9:.,_+-]/', '', (string)$e->getMessage()), 0, 120);
        }
        return 'unexpected:' . get_class($e);
    }

    public static function parses($src)
    {
        try {
            token_get_all((string)$src, TOKEN_PARSE);
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    public static function canonical($v)
    {
        return json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** Exclusive, 0600, never overwrites: temp file + link(). */
    public static function writeExclusive($path, $data)
    {
        $dir = dirname($path);
        clearstatcache();
        if (!is_dir($dir) || is_link($dir) || file_exists($path) || is_link($path)) throw new RuntimeException('exclusive-target');
        $tmp = $dir . '/.' . basename($path) . '.' . bin2hex(random_bytes(6));
        $old = umask(0077);
        try {
            $h = @fopen($tmp, 'xb');
            if (!$h) throw new RuntimeException('exclusive-open');
            $off = 0;
            while ($off < strlen($data)) {
                $n = fwrite($h, substr($data, $off));
                if ($n === false || $n === 0) { fclose($h); throw new RuntimeException('exclusive-write'); }
                $off += $n;
            }
            fflush($h);
            fclose($h);
            if (!@chmod($tmp, 0600) || !@link($tmp, $path)) throw new RuntimeException('exclusive-link');
        } finally {
            if (file_exists($tmp)) @unlink($tmp);
            umask($old);
        }
    }

    public static function cleanupMain()
    {
        PNS_Worker::cleanupMain(function ($role) {
            return new PNS_MysqliWorkerDriver(PNMS_Lib::credentials());
        });
    }
}

/** Case manifest validator, shared by the emitter, the remote entry and the worker. */
final class PNMS_Manifest
{
    const KEYS = ['schema', 'run_id', 'chunk_id', 'stage', 'build', 'run_dir', 'fixture_prefix', 'engine', 'collation', 'variant', 'mode',
        'repetition', 'pair_position', 'sample', 'sample_sha256', 'limits', 'expect'];
    const OPTIONAL = ['limits', 'expect'];

    private static function bad($code) { throw new InvalidArgumentException('manifest-' . $code); }

    public static function sha(array $m) { return hash('sha256', PNMS_Lib::canonical($m)); }
    public static function sampleSha($s) { return hash('sha256', PNMS_Lib::canonical($s)); }

    public static function validate($m)
    {
        if (!is_array($m)) self::bad('type');
        foreach (self::KEYS as $k) {
            if (!array_key_exists($k, $m)) {
                if (!in_array($k, self::OPTIONAL, true)) self::bad('missing-' . $k);
                $m[$k] = null;
            }
        }
        if (array_diff(array_keys($m), self::KEYS)) self::bad('unknown-key');
        $o = [];
        foreach (self::KEYS as $k) $o[$k] = $m[$k];
        if ($o['schema'] !== PNMS::MANIFEST_SCHEMA) self::bad('schema');
        foreach (['run_id', 'chunk_id'] as $k) if (!is_string($o[$k]) || !preg_match('/^[a-z0-9][a-z0-9-]{0,31}$/D', $o[$k])) self::bad($k);
        if (!in_array($o['stage'], PNMS::STAGES, true)) self::bad('stage');
        if (!in_array($o['build'], PNMS::BUILDS, true)) self::bad('build');
        $d = $o['run_dir'];
        if (!is_string($d) || !preg_match('~^/[A-Za-z0-9._/-]{1,200}$~D', $d) || strpos($d, '//') !== false || in_array('..', explode('/', $d), true)
            || in_array('.', explode('/', $d), true)) self::bad('run_dir');
        if (!is_string($o['fixture_prefix']) || !preg_match('/^pnsx_[a-z0-9]{6,20}_$/D', $o['fixture_prefix'])) self::bad('fixture_prefix');
        $stage = $o['stage'];
        $fixture = PNMS_Lib::isFixture($stage);
        if ($fixture ? !in_array($o['engine'], PNMS::ENGINES, true) : $o['engine'] !== null) self::bad('engine');
        if ($fixture ? !in_array($o['collation'], PNMS::COLLATIONS, true) : $o['collation'] !== null) self::bad('collation');
        $needVariant = in_array($stage, ['timing', 'diagnostic'], true);
        if ($needVariant ? !in_array($o['variant'], ['clean', 'conflict'], true) : $o['variant'] !== null) self::bad('variant');
        if (!in_array($o['mode'], ['natural', 'forced-exact'], true)) self::bad('mode');
        if ($o['mode'] === 'forced-exact' && (!$needVariant || $o['build'] !== 'candidate' || !in_array($o['collation'], PNMS::CI_COLLATIONS, true))) self::bad('forced-exact');
        if (in_array($stage, ['semantic', 'diagnostic'], true) && $o['build'] !== 'candidate') self::bad('candidate-only');
        $needRep = in_array($stage, ['read-only-stand', 'timing'], true);
        if ($needRep ? !(is_int($o['repetition']) && $o['repetition'] >= 1 && $o['repetition'] <= 3) : $o['repetition'] !== null) self::bad('repetition');
        $paired = $stage === 'read-only-stand' || ($stage === 'timing' && $o['mode'] === 'natural');
        if ($paired ? !in_array($o['pair_position'], [1, 2], true) : $o['pair_position'] !== null) self::bad('pair_position');
        if ($stage === 'read-only-stand') {
            $o['sample'] = self::sample($o['sample']);
            if (!is_string($o['sample_sha256']) || !hash_equals(self::sampleSha($o['sample']), $o['sample_sha256'])) self::bad('sample_sha256');
        } elseif ($o['sample'] !== null || $o['sample_sha256'] !== null) {
            self::bad('sample');
        }
        if ($o['limits'] !== null) {
            if (!is_array($o['limits'])) self::bad('limits');
            foreach ($o['limits'] as $k => $v) {
                if (!array_key_exists($k, PNS_Supervisor::LIMIT_DEFAULTS)) self::bad('limits-key');
                $def = PNS_Supervisor::LIMIT_DEFAULTS[$k];
                $ok = is_int($def) ? is_int($v) : (is_int($v) || is_float($v));
                if (!$ok || $v <= 0 || $v > $def) self::bad('limits-value');
            }
        }
        if ($o['expect'] !== null) {
            if (!is_array($o['expect']) || array_diff(array_keys($o['expect']), ['runtime', 'oc'])) self::bad('expect');
            foreach (['runtime' => PNMS::RUNTIME_FILES, 'oc' => PNMS::OC_FILES] as $k => $files) {
                if (!isset($o['expect'][$k])) continue;
                if (!is_array($o['expect'][$k])) self::bad('expect-' . $k);
                foreach ($o['expect'][$k] as $f => $h) if (!in_array($f, $files, true) || !is_string($h) || !preg_match('/^[0-9a-f]{64}$/D', $h)) self::bad('expect-' . $k);
            }
        }
        return $o;
    }

    private static function ids($a, $n)
    {
        if (!is_array($a) || array_keys($a) !== range(0, $n - 1)) self::bad('sample-ids');
        $prev = 0;
        foreach ($a as $id) {
            if (!is_int($id) || $id <= $prev) self::bad('sample-ids-order');
            $prev = $id;
        }
        return $a;
    }

    /** Canonical (key-ordered) sample; IDs ascending inside each group; groups in manifest order. */
    public static function sample($s)
    {
        if (!is_array($s)) self::bad('sample');
        $keys = array_keys($s);
        sort($keys);
        if ($keys !== ['none', 'one', 'three']) self::bad('sample-keys');
        $o = ['three' => null, 'one' => null, 'none' => null];
        $all = [];
        if ($s['three'] !== null) {
            if (!is_array($s['three']) || array_keys($s['three']) !== ['groups'] || !is_array($s['three']['groups']) || count($s['three']['groups']) !== 3) self::bad('sample-three');
            $cats = [];
            $groups = [];
            foreach (array_values($s['three']['groups']) as $i => $g) {
                if (!is_array($g)) self::bad('sample-three');
                $gk = array_keys($g);
                sort($gk);
                if ($gk !== ['category_id', 'ids'] || !is_int($g['category_id']) || $g['category_id'] < 1) self::bad('sample-three');
                $groups[] = ['category_id' => $g['category_id'], 'ids' => self::ids($g['ids'], PNMS::SAMPLE_SIZES[$i])];
                $cats[] = $g['category_id'];
                $all = array_merge($all, $g['ids']);
            }
            if (count(array_unique($cats)) !== 3 || count(array_unique($all)) !== 20) self::bad('sample-three-distinct');
            $o['three'] = ['groups' => $groups];
        }
        if ($s['one'] !== null) {
            if (!is_array($s['one'])) self::bad('sample-one');
            $k = array_keys($s['one']);
            sort($k);
            if ($k !== ['category_id', 'ids'] || !is_int($s['one']['category_id']) || $s['one']['category_id'] < 1) self::bad('sample-one');
            $o['one'] = ['category_id' => $s['one']['category_id'], 'ids' => self::ids($s['one']['ids'], PNMS::SERIES_N)];
        }
        if ($s['none'] !== null) {
            if (!is_array($s['none']) || array_keys($s['none']) !== ['ids']) self::bad('sample-none');
            $o['none'] = ['ids' => self::ids($s['none']['ids'], PNMS::SERIES_N)];
        }
        return $o;
    }
}

/**
 * Worker-side SQL guard and per-build classifier. Every statement is checked BEFORE dispatch:
 * verb allowed for the phase, no comments/statement separators/user variables, banned words,
 * writes only to exact run-owned names, reads only from the exact allowlist (+ three information_schema views).
 */
final class PNMS_Sql
{
    const BANNED = ['GLOBAL', 'PERSIST', 'PERSIST_ONLY', 'KILL', 'FLUSH', 'GRANT', 'REVOKE', 'LOCK', 'UNLOCK', 'OUTFILE', 'DUMPFILE', 'LOAD_FILE',
        'BENCHMARK', 'GET_LOCK', 'RELEASE_LOCK', 'RELEASE_ALL_LOCKS', 'IS_FREE_LOCK', 'IS_USED_LOCK', 'DROP', 'TRUNCATE', 'ALTER', 'RENAME',
        'SHUTDOWN', 'HANDLER', 'DELAYED', 'SHARE', 'TEMPORARY'];
    const META = ['TABLES', 'COLUMNS', 'STATISTICS'];
    const CLAUSE = ['WHERE', 'LEFT', 'RIGHT', 'INNER', 'CROSS', 'NATURAL', 'STRAIGHT_JOIN', 'JOIN', 'ON', 'ORDER', 'GROUP', 'HAVING', 'LIMIT',
        'UNION', 'USE', 'FORCE', 'IGNORE', 'LIKE', 'SET', 'VALUES', 'WINDOW'];
    const KINDS = [
        'setup' => ['CREATE' => 'create', 'INSERT' => 'write', 'UPDATE' => 'write', 'DELETE' => 'write', 'SELECT' => 'select', 'SHOW' => 'select'],
        'measure' => ['SELECT' => 'select', 'SHOW' => 'select'],
        'diagnostic' => ['EXPLAIN' => 'explain'],
    ];
    /** The only comment allowed: the candidate's exact incoming-path trailer. */
    const INCOMING_TRAILER = ' /* incoming_path */';

    public static function tokens($sql)
    {
        if (!is_string($sql) || $sql === '' || strlen($sql) > 4194304) throw new PNMS_GuardError('sql-size');
        $n = strlen($sql);
        $out = [];
        $i = 0;
        while ($i < $n) {
            $c = $sql[$i];
            if ($c === "'" || $c === '"') {
                $q = $c;
                $i++;
                $closed = false;
                while ($i < $n) {
                    $d = $sql[$i];
                    if ($d === '\\') { $i += 2; continue; }
                    if ($d === $q) {
                        if ($i + 1 < $n && $sql[$i + 1] === $q) { $i += 2; continue; }
                        $i++;
                        $closed = true;
                        break;
                    }
                    $i++;
                }
                if (!$closed) throw new PNMS_GuardError('sql-unterminated-string');
                $out[] = ['s', ''];
                continue;
            }
            if ($c === '`') {
                $j = strpos($sql, '`', $i + 1);
                if ($j === false || $j === $i + 1) throw new PNMS_GuardError('sql-identifier');
                $out[] = ['i', substr($sql, $i + 1, $j - $i - 1)];
                $i = $j + 1;
                continue;
            }
            if (ctype_space($c)) { $i++; continue; }
            if (preg_match('/\G[A-Za-z0-9_$.]+/', $sql, $mm, 0, $i)) {
                $out[] = ['w', $mm[0]];
                $i += strlen($mm[0]);
                continue;
            }
            if ($c === ';' || $c === '#' || $c === '@' || $c === '\\' || ord($c) >= 0x80) throw new PNMS_GuardError('sql-forbidden-character');
            $next = $i + 1 < $n ? $sql[$i + 1] : '';
            if (($c === '-' && $next === '-') || ($c === '/' && $next === '*') || ($c === '*' && $next === '/')) throw new PNMS_GuardError('sql-comment');
            $out[] = ['p', $c];
            $i++;
        }
        return $out;
    }

    /** ctx: owned (exact writable names), read (exact readable names), sleep (bool; preflight probe only). */
    public static function check($sql, $phase, array $ctx)
    {
        $s = (string)$sql;
        $tl = strlen(self::INCOMING_TRAILER);
        if (strlen($s) > $tl && substr($s, -$tl) === self::INCOMING_TRAILER) $s = substr($s, 0, -$tl);
        $t = self::tokens($s);
        if (!$t || $t[0][0] !== 'w') throw new PNMS_GuardError('sql-verb');
        $u = [];
        foreach ($t as $k => $x) $u[$k] = $x[0] === 'w' ? strtoupper($x[1]) : null;
        $verb = $u[0];
        foreach ($u as $k => $w) {
            if ($w === null) continue;
            if (in_array($w, self::BANNED, true)) throw new PNMS_GuardError('sql-banned-word');
            if ($w === 'SLEEP' && empty($ctx['sleep'])) throw new PNMS_GuardError('sql-sleep');
            if ($w === 'INTO' && $verb !== 'INSERT') throw new PNMS_GuardError('sql-into');
            if ($w === 'UPDATE' && $k > 0) throw new PNMS_GuardError('sql-update-clause');
            if (in_array($w, ['FORMAT', 'ANALYZE'], true) && !($verb === 'EXPLAIN' && $k === 1 && $w === 'ANALYZE')) throw new PNMS_GuardError('sql-explain-option');
        }
        if (!isset(self::KINDS[$phase][$verb])) throw new PNMS_GuardError('sql-verb-phase');
        $kind = self::KINDS[$phase][$verb];
        $target = null;
        $name = function ($k) use ($t) {
            if (!isset($t[$k]) || !in_array($t[$k][0], ['i', 'w'], true)) throw new PNMS_GuardError('sql-target');
            return $t[$k][1];
        };
        if ($verb === 'EXPLAIN') {
            $at = 1;
            if (($u[1] ?? null) === 'ANALYZE') { $kind = 'analyze'; $at = 2; }
            if (($u[$at] ?? null) !== 'SELECT') throw new PNMS_GuardError('sql-explain-shape');
        } elseif ($verb === 'CREATE') {
            if (($u[1] ?? null) !== 'TABLE' || ($t[3] ?? null) !== ['p', '(']) throw new PNMS_GuardError('sql-create-shape');
            $target = $name(2);
        } elseif ($verb === 'INSERT') {
            if (($u[1] ?? null) !== 'INTO') throw new PNMS_GuardError('sql-insert-shape');
            $target = $name(2);
        } elseif ($verb === 'UPDATE') {
            $target = $name(1);
        } elseif ($verb === 'DELETE') {
            if (($u[1] ?? null) !== 'FROM') throw new PNMS_GuardError('sql-delete-shape');
            $target = $name(2);
        }
        if ($target !== null && !in_array($target, $ctx['owned'], true)) throw new PNMS_GuardError('sql-write-not-owned');
        $refs = [];
        $count = count($t);
        for ($k = 0; $k < $count; $k++) {
            if (!in_array($u[$k], ['FROM', 'JOIN', 'INTO', 'UPDATE', 'TABLE'], true)) continue;
            $j = $k + 1;
            while (isset($t[$j]) && in_array($t[$j][0], ['i', 'w'], true)) {
                $refs[] = $t[$j][1];
                if ($u[$k] !== 'FROM') break;
                $j++;
                if (($u[$j] ?? null) === 'AS') $j++;
                if (isset($t[$j]) && in_array($t[$j][0], ['i', 'w'], true) && !in_array($u[$j], self::CLAUSE, true)) $j++;
                if (($t[$j] ?? null) !== ['p', ',']) break;
                $j++;
            }
        }
        foreach ($refs as $r) {
            if (strpos($r, '.') !== false) {
                $parts = explode('.', $r);
                if (count($parts) !== 2 || strtolower($parts[0]) !== 'information_schema' || !in_array(strtoupper($parts[1]), self::META, true) || $kind !== 'select') {
                    throw new PNMS_GuardError('sql-schema-not-allowed');
                }
                continue;
            }
            if (strtoupper($r) === 'DUAL' && $kind !== 'create' && $kind !== 'write') continue;
            if (!in_array($r, $ctx['read'], true) && !in_array($r, $ctx['owned'], true)) throw new PNMS_GuardError('sql-table-not-allowed');
        }
        if (in_array($kind, ['create', 'write'], true) && $target === null) throw new PNMS_GuardError('sql-target');
        return ['verb' => $verb, 'kind' => $kind, 'target' => $target, 'tables' => array_values(array_unique($refs))];
    }

    /** Per-build classification. Candidate ownership shapes are never used to classify baseline 1.8 SQL. */
    public static function classify($sql, $p, $build)
    {
        $q = preg_quote($p, '/');
        $tb = function ($n) use ($q) { return '`?' . $q . $n . '`?'; };
        if (preg_match('/^SHOW\s+FULL\s+COLUMNS\s+FROM\s+' . $tb('seo_url') . '\s+LIKE\s+\'keyword\'\s*$/i', $sql)) return 'metadata';
        if (preg_match('/^SELECT\s+MAX\(\s*`?seo_url_id`?\s*\)\s+AS\s+`?max_id`?\s+FROM\s+' . $tb('seo_url') . '\s*$/i', $sql)) return 'map_max';
        $candidateShape = preg_match('/ AS product_id\) p /', $sql) || preg_match('/ AS category_id\) root /', $sql);
        if ($build === 'candidate') {
            if (preg_match('/^SELECT p\.product_id, .* FROM \(SELECT \d+ AS product_id\) p /s', $sql)) return 'product_ownership';
            if (preg_match('/^SELECT root\.category_id AS requested_category_id, .* FROM \(SELECT \d+ AS category_id\) root /s', $sql)) return 'category_ownership';
        } elseif ($candidateShape) {
            return 'unexpected_candidate_shape';
        }
        if (stripos($sql, ' JOIN ') === false && preg_match('/^SELECT\s.*\sFROM\s+' . $tb('seo_url') . '\s+WHERE\s+`?store_id`?\s*=.*ORDER\s+BY\s+`?seo_url_id`?\s*$/is', $sql)) return 'map';
        if (stripos($sql, 'GROUP_CONCAT') !== false && preg_match('/FROM\s+' . $tb('category_path') . '\s/i', $sql)) return 'path_category';
        if (preg_match('/(FROM|JOIN)\s+' . $tb('product_to_category') . '\s/i', $sql)) return 'path_product';
        if (preg_match('/(FROM|JOIN)\s+' . $tb('category_path') . '\s/i', $sql)) return 'path_category';
        if (preg_match('/(FROM|JOIN)\s+' . $tb('seo_url') . '\s/i', $sql)) return 'seo_lookup';
        return 'other';
    }

    public static function addressClasses($build)
    {
        return $build === 'candidate' ? ['product_ownership', 'category_ownership'] : [];
    }
}
// ==PNMS:lib:END==
// ==PNMS:stubs:BEGIN==
class Registry { private $values = []; function set($k, $v) { $this->values[$k] = $v; } function get($k) { return $this->values[$k] ?? null; } }
class Config extends Registry {}
abstract class Controller { protected $registry; function __construct($r) { $this->registry = $r; } function __get($k) { return $this->registry->get($k); } }
// ==PNMS:stubs:END==
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
// ==PNMS:worker:BEGIN==
/* Workload worker (one build, one DB_PREFIX). Every SQL of the runtime and of this harness goes through PNMS_Run::exec -> PNS_Worker::statement. */

/** Run-owned module cache with observable keys and counters. */
final class PNMS_Cache
{
    private $data = [];
    public $gets = 0;
    public $sets = [];
    public $deletes = 0;
    public function get($k) { $this->gets++; return $this->data[$k] ?? null; }
    public function set($k, $v) { $this->sets[$k] = ($this->sets[$k] ?? 0) + 1; $this->data[$k] = $v; }
    public function delete($k) { $this->deletes++; unset($this->data[$k]); }
    public function keys() { $k = array_keys($this->data); sort($k); return $k; }
}

/** Silent logger: counts diagnostics, never prints (worker stdout is captured but not retained). */
final class PNMS_Log
{
    public $writes = 0;
    public function write($s) { $this->writes++; }
}

/** The deliberate failure of the restoration-after-error case (never caught as anything else). */
class PNMS_Injected extends RuntimeException {}

/** DB adapter handed to the runtime build. Plan-before-execute in discovery; unplanned digests are refused while timed. */
final class PNMS_DB
{
    private $run;
    public $mode = 'discovery';       // discovery | timed | semantic
    public $caseKey = '';
    public $capture = null;          // list of executed SQL records of the current series, or null
    public $modeProbe = null;        // callable returning the current prefilter mode, for plan inspection
    public $last = [];               // class => last executed SQL text (worker memory only, never in evidence)

    public function __construct(PNMS_Run $run) { $this->run = $run; }
    public function escape($s) { return PNMS_Lib::escape($s); }

    public function query($sql)
    {
        $sql = (string)$sql;
        $class = PNMS_Sql::classify($sql, $this->run->prefix, $this->run->build);
        $isSelect = strncasecmp(ltrim($sql), 'SELECT', 6) === 0;
        if ($isSelect) {
            $mode = $this->modeProbe ? (string)call_user_func($this->modeProbe) : '';
            $this->run->plan($sql, $class, $this->caseKey, $mode, $this->mode === 'timed');
        }
        $r = $this->run->exec('measure', $sql);
        $this->last[$class] = $sql;
        $rec = ['ord' => $r['ord'], 'class' => $class, 'digest' => $r['digest'], 'ns' => $r['ns'], 'fetched_rows' => $r['n']];
        if (is_array($this->capture)) $this->capture[] = $rec;
        return (object)['rows' => $r['rows'], 'row' => $r['rows'][0] ?? [], 'num_rows' => $r['n']];
    }
}

final class PNMS_Run
{
    public $w;
    public $m = null;
    public $stage = null;
    public $build = null;
    public $prefix = null;
    public $shop = null;
    public $outcome = 'running';
    private $cred = null;
    private $role = null;
    private $ctx = ['owned' => [], 'read' => []];
    private $stmts = [];
    private $cases = [];
    private $notes = [];
    private $evPath = '';
    private $tag = '';
    private $plans = [];
    private $resets = 0;
    private $connections = 0;
    private $dataset = null;
    private $configSha = null;
    private $staticsSeen = null;
    private $written = false;
    private $dead = null;

    public function __construct(PNS_Worker $w) { $this->w = $w; }
    public function __debugInfo() { return ['stage' => $this->stage, 'build' => $this->build]; }

    public function init()
    {
        $w = $this->w;
        $m = PNMS_Manifest::validate($w->manifest());
        $tag = (string)getenv('SEO_PRO_PNMS_EVIDENCE_TAG');
        $path = (string)getenv('SEO_PRO_PNMS_EVIDENCE');
        if (!preg_match('/^[0-9a-f]{32}$/D', $tag) || $path !== PNMS_Lib::evidencePath($m)) throw new PNMS_AssertFail('evidence-target');
        $this->m = $m;
        $this->tag = $tag;
        $this->evPath = $path;
        $this->stage = $m['stage'];
        $this->build = $m['build'];
        if ($w->build() !== $this->build) throw new PNMS_AssertFail('boot-build-mismatch');
        $this->cred = PNMS_Lib::credentials();
        $this->shop = $this->cred['DB_PREFIX'];
        $this->prefix = $w->dbPrefix();
        $expected = PNMS_Lib::isStand($this->stage) ? $this->shop : $m['fixture_prefix'];
        if ($this->prefix !== $expected) throw new PNMS_AssertFail('db-prefix-mismatch');
        if (!defined('DB_PREFIX')) define('DB_PREFIX', $this->prefix);
        if (DB_PREFIX !== $this->prefix) throw new PNMS_AssertFail('db-prefix-constant');
        $owned = [];
        if ($this->stage === 'preflight') $owned[] = $this->prefix . PNMS::PROBE_TABLE;
        elseif (PNMS_Lib::isFixture($this->stage)) foreach (PNMS::FIXTURE_TABLES as $s) $owned[] = $this->prefix . $s;
        $read = [];
        if (PNMS_Lib::isStand($this->stage)) foreach (PNMS::SHOP_READ_TABLES as $s) $read[] = $this->shop . $s;
        elseif ($this->stage === 'preflight') foreach (PNMS::PREFLIGHT_SHOP_READ as $s) $read[] = $this->shop . $s;
        else foreach (PNMS::SHOP_CONTROL_READ as $s) $read[] = $this->shop . $s;
        foreach ($owned as $o) if (strpos($o, $this->shop) === 0) throw new PNMS_AssertFail('owned-overlaps-shop');
        $this->ctx = ['owned' => $owned, 'read' => $read];
        $cfg = PNMS::CONFIG;
        ksort($cfg);
        $this->configSha = hash('sha256', PNMS_Lib::canonical($cfg));
    }

    public function dispatch()
    {
        if (PNMS_Lib::isFixture($this->stage)) {
            // Durable read-only shop control around all fixture writes of this chunk (fixtures never touch shop rows).
            $before = $this->shopControl();
            switch ($this->stage) {
                case 'semantic': $this->stageSemantic(); break;
                case 'timing': $this->stageTiming(); break;
                default: $this->stageDiagnostic(); break;
            }
            $this->shopControlCase($before);
            return;
        }
        switch ($this->stage) {
            case 'preflight': $this->stagePreflight(); break;
            case 'read-only-stand': $this->stageStand(); break;
            default: throw new PNMS_AssertFail('stage');
        }
    }

    /** COUNT/MAX of the shop's seo_url and COUNT of product_to_category (read-only; values are counts/IDs, not row payloads). */
    private function shopControl()
    {
        $p = $this->shop;
        $a = $this->exec('measure', 'SELECT COUNT(*) AS n, MAX(seo_url_id) AS m FROM `' . $p . 'seo_url`')['rows'][0] ?? [];
        $b = $this->exec('measure', 'SELECT COUNT(*) AS n FROM `' . $p . 'product_to_category`')['rows'][0] ?? [];
        return ['seo_url_count' => (int)($a['n'] ?? -1), 'seo_url_max_id' => isset($a['m']) ? (int)$a['m'] : null, 'product_to_category_count' => (int)($b['n'] ?? -1)];
    }

    /** Shared stand: a change can also come from other activity; it is reported as a failure, never ignored. */
    private function shopControlCase(array $before)
    {
        $after = $this->shopControl();
        $same = $after === $before;
        $this->addCase('shop-control', $same ? 'pass' : 'fail', $same ? null : 'shop-control-changed',
            ['before' => $before, 'after' => $after, 'scope' => 'workload fixture writes; cleanup DROPs touch owned names only']);
    }

    const CACHE_STATES = 'cold: statics reset + fresh controller/Config without prefilter key + empty run-owned module cache; '
        . 'warm: statics reset + fresh controller/Config + cache populated by cold; repeat: same controller as warm. No server/OS cache is flushed.';
    // Binary casts: CONCAT_WS over columns with different collations would raise an illegal-mix error.
    const CHECK_COLS = ['seo_url' => ['seo_url_id', 'store_id', 'language_id', 'CAST(`query` AS BINARY)', 'CAST(keyword AS BINARY)'], 'product_to_category' => ['product_id', 'category_id', 'main_category'],
        'category_path' => ['category_id', 'path_id', 'level'], 'category' => ['category_id', 'parent_id', 'status'], 'category_to_store' => ['category_id', 'store_id'],
        'category_description' => ['category_id', 'language_id']];

    private function expect($ok, $code) { if (!$ok) throw new PNMS_AssertFail($code); }

    /** One evidence case; incomplete/assertion/guard outcomes are recorded, protocol stops propagate. */
    private function runCase($id, callable $fn)
    {
        try {
            $data = $fn();
            $this->addCase($id, 'pass', null, is_array($data) ? $data : []);
            return true;
        } catch (PNMS_Incomplete $e) {
            $this->addCase($id, 'incomplete', PNMS_Lib::code($e));
        } catch (PNMS_AssertFail $e) {
            $this->addCase($id, 'fail', PNMS_Lib::code($e));
        } catch (PNMS_GuardError $e) {
            $this->addCase($id, 'fail', PNMS_Lib::code($e));
        }
        return false;
    }

    // ---- deterministic fixtures (prefix-independent definition, explicit IDs, fixed insertion order) ----

    private static function loadBatches()
    {
        $out = [];
        for ($batch = 0; $batch < 30; $batch++) {
            $rows = [];
            for ($j = 0; $j < 1000; $j++) { $i = $batch * 1000 + $j; $rows[] = [10000 + $i, 0, 1, 'unused=' . $i, 'load-' . $i]; }
            $out[] = $rows;
        }
        return $out;
    }

    /** The existing conflict cases: case pairs, diacritics, trailing space, prefix positions 251-255, empty alias, cross-query owners, scopes. */
    private static function conflictStatements()
    {
        $out = [];
        $pairs = ['Pair', 'pair', 'café', 'cafe', 'tail', 'tail ', str_repeat('a', 250) . 'x', str_repeat('a', 250) . 'y', str_repeat('a', 250) . '12',
            str_repeat('a', 250) . '123', str_repeat('a', 250) . '1234', str_repeat('a', 250) . '12345', ''];
        $id = 500;
        foreach ($pairs as $kw) { $out[] = [[$id, 0, 1, 'product_id=' . ($id % 2 ? 51 : 50), $kw]]; $id++; }
        foreach (['product_id=90', 'category_id=90', 'manufacturer_id=90', 'information_id=90', 'product/search', 'foreign_id=90', 'product_id=bad'] as $n => $owner) {
            $first = 700 + 2 * $n;
            $out[] = [[$first, 0, 1, $owner, 'OWNED-' . $n], [$first + 1, 0, 1, 'product_id=50', 'owned-' . $n]];
        }
        $out[] = [[400, 1, 1, 'foreign_id=1', 'scoped'], [401, 0, 2, 'foreign_id=1', 'scoped'], [801, 0, 1, 'product_id=51', 'scoped']];
        return $out;
    }

    private static function datasetDef($kind, $variant, $collation, $engine)
    {
        $tables = [
            'seo_url' => 'seo_url_id int NOT NULL, store_id int NOT NULL, language_id int NOT NULL, `query` varchar(255) NOT NULL, keyword varchar(255) CHARACTER SET utf8mb4 COLLATE '
                . $collation . ' NOT NULL, PRIMARY KEY(seo_url_id), KEY `query` (`query`(250)), KEY keyword (keyword(250))',
            'product_to_category' => 'product_id int NOT NULL, category_id int NOT NULL, main_category tinyint NOT NULL DEFAULT 0, PRIMARY KEY(product_id,category_id)',
            'category_path' => 'category_id int NOT NULL, path_id int NOT NULL, level int NOT NULL, PRIMARY KEY(category_id,path_id)',
            'category' => 'category_id int NOT NULL PRIMARY KEY, parent_id int NOT NULL, status tinyint NOT NULL',
            'category_to_store' => 'category_id int NOT NULL, store_id int NOT NULL, PRIMARY KEY(category_id,store_id)',
            'category_description' => 'category_id int NOT NULL, language_id int NOT NULL, PRIMARY KEY(category_id,language_id)',
        ];
        $ins = [];
        if ($kind === 'semantic') {
            // The existing 20-product / one-leaf semantic dataset, unchanged (independent from the performance dataset).
            $ins[] = ['category', [[10, 0, 1], [11, 10, 1]]];
            $ins[] = ['category_path', [[10, 10, 0], [11, 10, 0], [11, 11, 1]]];
            $ins[] = ['category_to_store', [[10, 0], [11, 0]]];
            $ins[] = ['category_description', [[10, 1], [11, 1]]];
            $ins[] = ['seo_url', [[1, 0, 1, 'category_id=10', 'root'], [2, 0, 1, 'category_id=11', 'leaf']]];
            foreach (self::loadBatches() as $b) $ins[] = ['seo_url', $b];
            for ($id = 1; $id <= 20; $id++) {
                $ins[] = ['product_to_category', [[$id, 11, 1]]];
                $ins[] = ['seo_url', [[100 + $id, 0, 1, 'product_id=' . $id, 'p' . $id]]];
            }
        } else {
            // Performance dataset: 30,000 load aliases + 60 products (1-20 -> 11/12/13 as 7/7/6, 21-40 -> 11, 41-60 none).
            $ins[] = ['category', [[10, 0, 1], [11, 10, 1], [12, 10, 1], [13, 10, 1]]];
            $ins[] = ['category_path', [[10, 10, 0], [11, 10, 0], [11, 11, 1], [12, 10, 0], [12, 12, 1], [13, 10, 0], [13, 13, 1]]];
            $ins[] = ['category_to_store', [[10, 0], [11, 0], [12, 0], [13, 0]]];
            $ins[] = ['category_description', [[10, 1], [11, 1], [12, 1], [13, 1]]];
            $ins[] = ['seo_url', [[1, 0, 1, 'category_id=10', 'root'], [2, 0, 1, 'category_id=11', 'leaf-a'], [3, 0, 1, 'category_id=12', 'leaf-b'], [4, 0, 1, 'category_id=13', 'leaf-c']]];
            foreach (self::loadBatches() as $b) $ins[] = ['seo_url', $b];
            $pc = [];
            $su = [];
            for ($id = 1; $id <= 60; $id++) {
                $cat = self::perfLeaf($id);
                if ($cat !== null) $pc[] = [$id, $cat, 1];
                $su[] = [100 + $id, 0, 1, 'product_id=' . $id, 'p' . $id];
            }
            $ins[] = ['product_to_category', $pc];
            $ins[] = ['seo_url', $su];
            if ($variant === 'conflict') foreach (self::conflictStatements() as $rows) $ins[] = ['seo_url', $rows];
        }
        return ['kind' => $kind, 'variant' => $variant, 'engine' => $engine, 'collation' => $collation, 'tables' => $tables, 'inserts' => $ins];
    }

    private static function perfLeaf($id)
    {
        if ($id <= 7) return 11;
        if ($id <= 14) return 12;
        if ($id <= 20) return 13;
        if ($id <= 40) return 11;
        return null;
    }

    private static function perfShapes()
    {
        return ['three' => ['ids' => range(1, 20), 'k' => 3], 'one' => ['ids' => range(21, 40), 'k' => 1], 'none' => ['ids' => range(41, 60), 'k' => 0]];
    }

    private static function perfHref($id, $keyword)
    {
        $leaf = self::perfLeaf($id);
        $path = $leaf === null ? '' : 'root/' . ['11' => 'leaf-a', '12' => 'leaf-b', '13' => 'leaf-c'][(string)$leaf] . '/';
        return PNMS::BASE_URL . $path . rawurlencode($keyword) . '.html';
    }

    private function insertRows($suffix, array $rows)
    {
        $vals = [];
        foreach ($rows as $row) {
            $v = [];
            foreach ($row as $x) $v[] = is_int($x) ? (string)$x : "'" . PNMS_Lib::escape($x) . "'";
            $vals[] = '(' . implode(',', $v) . ')';
        }
        $this->exec('setup', 'INSERT INTO `' . $this->prefix . $suffix . '` VALUES ' . implode(',', $vals));
    }

    /** Server content must equal the local definition (COUNT + SUM(CRC32(CONCAT_WS))); normalized DDL is hashed per table. */
    private function verifyDataset(array $def)
    {
        $expect = [];
        foreach (PNMS::FIXTURE_TABLES as $s) $expect[$s] = ['n' => 0, 'h' => 0];
        foreach ($def['inserts'] as $ins) {
            foreach ($ins[1] as $row) { $expect[$ins[0]]['n']++; $expect[$ins[0]]['h'] += crc32(implode('|', array_map('strval', $row))); }
        }
        $schema = [];
        foreach (PNMS::FIXTURE_TABLES as $s) {
            $t = $this->prefix . $s;
            $ddl = (string)($this->exec('setup', 'SHOW CREATE TABLE `' . $t . '`')['rows'][0]['Create Table'] ?? '');
            $schema[$s] = hash('sha256', preg_replace('/ AUTO_INCREMENT=\d+/', '', str_replace('`' . $t . '`', '`{t}`', $ddl)));
            $row = $this->exec('setup', "SELECT COUNT(*) AS n, SUM(CRC32(CONCAT_WS('|', " . implode(', ', self::CHECK_COLS[$s]) . '))) AS h FROM `' . $t . '`')['rows'][0] ?? [];
            if ((int)($row['n'] ?? -1) !== $expect[$s]['n'] || (string)($row['h'] ?? '0') !== (string)($expect[$s]['n'] ? $expect[$s]['h'] : '0')) {
                if (!($expect[$s]['n'] === 0 && (int)($row['n'] ?? -1) === 0)) throw new PNMS_AssertFail('dataset-content-' . $s);
            }
        }
        $defHash = hash('sha256', PNMS_Lib::canonical($def));
        ksort($schema);
        return ['definition_sha256' => $defHash, 'schema_sha256' => hash('sha256', PNMS_Lib::canonical($schema)), 'content_verified' => true,
            'rows' => array_map(function ($e) { return $e['n']; }, $expect)];
    }

    /** Collation availability and shop-engine match first; then CREATE with the final collation (never ALTER), load and verify. */
    private function prepareFixture($kind, $variant)
    {
        $col = $this->m['collation'];
        $engine = $this->m['engine'];
        if ($this->exec('measure', "SHOW COLLATION WHERE Collation = '" . PNMS_Lib::escape($col) . "'")['n'] !== 1) {
            $this->addCase('collation', 'unrun', 'collation-unavailable', ['collation' => $col]);
            return false;
        }
        $shopEngine = $this->exec('measure', "SELECT ENGINE AS e FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '"
            . PNMS_Lib::escape($this->shop . 'seo_url') . "'")['rows'][0]['e'] ?? null;
        if ($shopEngine !== $engine) {
            $this->addCase('engine', 'unrun', 'engine-differs-from-shop', ['manifest' => $engine, 'shop' => self::clean($shopEngine, 32)]);
            return false;
        }
        $def = self::datasetDef($kind, $variant, $col, $engine);
        foreach ($def['tables'] as $suffix => $cols) $this->createTable($suffix, $cols, $engine, 'utf8mb4_unicode_ci');
        foreach ($def['inserts'] as $ins) $this->insertRows($ins[0], $ins[1]);
        try {
            $this->setDataset($this->verifyDataset($def) + ['kind' => $kind, 'variant' => $variant, 'engine' => $engine, 'collation' => $col]);
        } catch (PNMS_AssertFail $e) {
            $this->addCase('dataset', 'fail', PNMS_Lib::code($e));
            return false;
        }
        return true;
    }

    // ---- series ----------------------------------------------------------------------------

    private static function countClasses(array $cap)
    {
        $c = ['metadata' => 0, 'map_max' => 0, 'map' => 0, 'product_ownership' => 0, 'category_ownership' => 0, 'path_product' => 0, 'path_category' => 0,
            'seo_lookup' => 0, 'unexpected_candidate_shape' => 0, 'other' => 0];
        foreach ($cap as $r) $c[$r['class']] = ($c[$r['class']] ?? 0) + 1;
        return $c;
    }

    /** One N-product Url::link series. series_ns is the instrumented (supervised) wall time; worker_sql_ns is mysqli time after GO. */
    private function measureSeries(PNMS_DB $db, array $ctl, array $ids, $k, $state, $timed, ?array $expect)
    {
        $db->capture = [];
        $hrefs = [];
        $t0 = hrtime(true);
        try {
            foreach ($ids as $id) $hrefs[$id] = $ctl['url']->link('product/product', ['product_id' => $id], true);
        } finally {
            $series = hrtime(true) - $t0;
            $cap = $db->capture;
            $db->capture = null;
        }
        $counts = self::countClasses($cap);
        $sqlNs = 0;
        foreach ($cap as $r) $sqlNs += $r['ns'];
        $budget = null;
        if ($this->build === 'candidate') {
            $budget = $state === 'repeat' ? count($cap) === 0
                : ($counts['product_ownership'] === count($ids) && $counts['category_ownership'] === $k && $counts['metadata'] <= 1);
        }
        $hrefOk = null;
        if ($expect !== null) {
            $hrefOk = true;
            foreach ($ids as $id) {
                if (!array_key_exists($id, $expect)) continue;
                $want = $expect[$id];
                $ok = $want === null ? strpos($hrefs[$id], 'product_id=' . $id) !== false : $hrefs[$id] === $want;
                if (!$ok) $hrefOk = false;
            }
        }
        return ['state' => $state, 'sample' => $timed, 'n' => count($ids), 'k' => $k, 'mode' => $ctl['config']->get('seo_pro_keyword_prefilter_mode'),
            'counts' => $counts, 'address_sql' => $counts['product_ownership'] + $counts['category_ownership'], 'total_sql' => count($cap),
            'series_ns' => $series, 'worker_sql_ns' => $sqlNs, 'instrumented_overhead_ns' => $series - $sqlNs, 'budget_ok' => $budget, 'href_ok' => $hrefOk,
            'href_sha256' => hash('sha256', implode("\n", $hrefs)), 'diagnostic_writes' => $ctl['log']->writes, 'sql' => $timed ? $cap : count($cap)];
    }

    /** cold -> warm (fresh controller, populated cache) -> same-controller repeat. */
    private function sequence(PNMS_DB $db, $caseKey, array $ids, $k, $forced, $timed, ?array $expect)
    {
        $db->caseKey = $caseKey;
        $db->mode = $timed ? 'timed' : 'discovery';
        $cache = new PNMS_Cache();
        $out = [];
        $cold = $this->controller($db, $cache, $forced);
        $out['cold'] = $this->measureSeries($db, $cold, $ids, $k, 'cold', $timed, $expect);
        $warm = $this->controller($db, $cache, $forced);
        $out['warm'] = $this->measureSeries($db, $warm, $ids, $k, 'warm', $timed, $expect);
        $out['repeat'] = $this->measureSeries($db, $warm, $ids, $k, 'repeat', $timed, $expect);
        $out['module_cache'] = ['keys' => $cache->keys(), 'sets' => $cache->sets, 'gets' => $cache->gets];
        $db->mode = 'discovery';
        return $out;
    }

    private function judge(array $seq, $expectedMode)
    {
        $codes = [];
        foreach (['cold', 'warm', 'repeat'] as $s) {
            if ($seq[$s]['budget_ok'] === false) $codes[] = 'budget-' . $s;
            if ($seq[$s]['href_ok'] === false) $codes[] = 'href-' . $s;
        }
        if ($expectedMode !== null && $seq['cold']['mode'] !== $expectedMode) $codes[] = 'mode-' . $expectedMode;
        return $codes;
    }

    /**
     * Untimed discovery for ALL shapes (EXPLAIN before each new SELECT digest), discard request state, then the timed
     * sequence per shape. A digest first seen while timed makes that shape incomplete (never EXPLAIN inside timing).
     */
    private function discoverAndTime($prefix, array $shapes, $forced, $expectedMode, callable $expectFor, ?callable $afterDiscovery = null)
    {
        $db = new PNMS_DB($this);
        $disc = [];
        foreach ($shapes as $name => $s) {
            try {
                $disc[$name] = $this->sequence($db, $prefix . ':' . $name, $s['ids'], $s['k'], $forced, false, $expectFor($name));
            } catch (PNMS_Incomplete $e) {
                $disc[$name] = PNMS_Lib::code($e);
            } catch (PNMS_GuardError $e) {
                $disc[$name] = 'fail ' . PNMS_Lib::code($e);
            } catch (PNMS_AssertFail $e) {
                $disc[$name] = 'fail ' . PNMS_Lib::code($e);
            }
        }
        if ($afterDiscovery) $afterDiscovery($db);
        $this->note('discovery state discarded (cache/controllers/Config/statics); server caches not reset: preparatory warming disclosed');
        foreach ($shapes as $name => $s) {
            $id = $prefix . ':' . $name;
            if (!is_array($disc[$name])) {
                $fail = strpos($disc[$name], 'fail ') === 0;
                $this->addCase($id, $fail ? 'fail' : 'incomplete', 'discovery ' . ($fail ? substr($disc[$name], 5) : $disc[$name]));
                continue;
            }
            $summary = [];
            foreach (['cold', 'warm', 'repeat'] as $st) $summary[$st] = array_intersect_key($disc[$name][$st], array_flip(['counts', 'total_sql', 'mode', 'budget_ok', 'href_ok']));
            $this->runCase($id, function () use ($db, $id, $s, $forced, $expectedMode, $expectFor, $name, $summary) {
                $t = $this->sequence($db, $id, $s['ids'], $s['k'], $forced, true, $expectFor($name));
                $codes = $this->judge($t, $expectedMode);
                if ($codes) throw new PNMS_AssertFail(implode(',', $codes));
                return ['timed' => $t, 'discovery' => $summary, 'forced_mode' => $forced, 'cache_states' => self::CACHE_STATES,
                    'budgets' => $this->build === 'candidate' ? 'N+K address, metadata<=1, repeat=0' : 'not-applicable-baseline (counts recorded)'];
            });
        }
    }

    private function forcedMode()
    {
        if ($this->m['mode'] !== 'forced-exact') return null;
        $this->note('forced-exact: Config seo_pro_keyword_prefilter_mode=exact injected before each candidate controller construction');
        return 'exact';
    }

    private function expectedMode($forced)
    {
        if ($this->build !== 'candidate') return null;
        return $forced ?? (in_array($this->m['collation'], PNMS::CI_COLLATIONS, true) ? 'ci' : 'exact');
    }

    // ---- independent ownership reference (PHP, from a full ordered dump of the owned fixture) ----

    private function ownershipReference()
    {
        $all = $this->exec('measure', 'SELECT seo_url_id, `query`, LOWER(keyword) AS keyword FROM `' . $this->prefix . 'seo_url` WHERE store_id = 0 AND language_id = 1 ORDER BY seo_url_id')['rows'];
        $ref = [];
        $seen = [];
        // first lowercase byte key wins; the last usable assignment for an object wins
        foreach ($all as $row) {
            if (array_key_exists($row['keyword'], $seen)) continue;
            $seen[$row['keyword']] = true;
            $ref[$row['query']] = $row['keyword'];
        }
        return [$ref, $all];
    }

    /** Every earlier owner pair of the product's aliases, from the runtime's own ownership SQL vs the PHP reference. */
    private function pairCheck(PNMS_DB $db, array $all, $product)
    {
        $sql = $db->last['product_ownership'] ?? null;
        if (!is_string($sql)) throw new PNMS_AssertFail('ownership-sql-missing');
        $actual = [];
        foreach ($this->exec('measure', $sql)['rows'] as $row) if ($row['earlier_alias_id'] !== null) $actual[] = (int)$row['alias_id'] . ':' . (int)$row['earlier_alias_id'];
        $expected = [];
        foreach ($all as $su) {
            if ($su['query'] !== 'product_id=' . $product) continue;
            foreach ($all as $d) if ((int)$d['seo_url_id'] < (int)$su['seo_url_id'] && $d['keyword'] === $su['keyword']) $expected[] = (int)$su['seo_url_id'] . ':' . (int)$d['seo_url_id'];
        }
        sort($actual);
        sort($expected);
        $this->expect($actual === $expected, 'earlier-owner-pairs');
        return count($expected);
    }

    // ---- stages ----------------------------------------------------------------------------

    /** Read-only stand: frozen manifest IDs re-verified first; both builds run identical IDs/Config. No shop writes are possible (guard). */
    private function stageStand()
    {
        $sample = $this->m['sample'];
        $p = $this->shop;
        $shapes = [];
        $verified = [];
        foreach (['three' => 3, 'one' => 1, 'none' => 0] as $name => $k) {
            $s = $sample[$name];
            if ($s === null) { $this->addCase('stand:' . $name, 'unrun', 'shape-absent-in-manifest'); continue; }
            $groups = $name === 'three' ? $s['groups'] : ($name === 'one' ? [$s] : []);
            $ok = true;
            $ids = [];
            foreach ($groups as $g) {
                $n = $this->exec('measure', 'SELECT COUNT(*) AS n FROM ' . $p . 'product_to_category pc JOIN ' . $p . 'product pr ON pr.product_id = pc.product_id WHERE pc.category_id = '
                    . (int)$g['category_id'] . ' AND pc.product_id IN (' . implode(',', $g['ids']) . ') AND ' . $this->singleMainPredicate('pc'))['rows'][0]['n'] ?? -1;
                if ((int)$n !== count($g['ids']) || !$this->validCategory($g['category_id'])) $ok = false;
                $ids = array_merge($ids, $g['ids']);
            }
            if ($name === 'none') {
                $ids = $s['ids'];
                $n = $this->exec('measure', 'SELECT COUNT(*) AS n FROM ' . $p . 'product pr WHERE pr.product_id IN (' . implode(',', $ids) . ') AND NOT EXISTS (SELECT 1 FROM '
                    . $p . 'product_to_category pc WHERE pc.product_id = pr.product_id AND pc.main_category = 1)')['rows'][0]['n'] ?? -1;
                if ((int)$n !== PNMS::SERIES_N) $ok = false;
            }
            $verified[$name] = $ok;
            if (!$ok) { $this->addCase('stand:' . $name, 'unrun', 'frozen-sample-mismatch'); continue; }
            $shapes[$name] = ['ids' => $ids, 'k' => $k];
        }
        $this->setDataset(['kind' => 'shop-frozen-sample', 'sample_sha256' => $this->m['sample_sha256'], 'verified' => $verified]);
        if (!$shapes) return;
        $this->discoverAndTime('stand', $shapes, null, null, function ($name) { return null; });
    }

    private function stageTiming()
    {
        if (!$this->prepareFixture('perf', $this->m['variant'])) return;
        $forced = $this->forcedMode();
        $conflict = $this->m['variant'] === 'conflict';
        $ref = null;
        $all = null;
        if ($conflict) list($ref, $all) = $this->ownershipReference();
        $expectFor = function ($name) use ($ref, $conflict) {
            $e = [];
            foreach (self::perfShapes()[$name]['ids'] as $id) {
                $kw = $conflict ? ($ref['product_id=' . $id] ?? null) : 'p' . $id;
                $e[$id] = ($kw === null || $kw === '') ? null : self::perfHref($id, $kw);
            }
            return $this->build === 'candidate' ? $e : null;
        };
        $after = null;
        if ($conflict && $this->build === 'candidate') {
            $after = function (PNMS_DB $db) use ($ref, $all, $forced) {
                $this->runCase('timing:conflict-reference', function () use ($db, $ref, $all, $forced) {
                    $db->caseKey = 'timing:reference';
                    $db->mode = 'discovery';
                    $ctl = $this->controller($db, new PNMS_Cache(), $forced);
                    $checked = 0;
                    foreach (range(41, 60) as $id) {
                        $kw = self::call($ctl['c'], 'readProductAddressData', $id)['keyword'];
                        $this->expect($kw === ($ref['product_id=' . $id] ?? null), 'reference-ownership-' . $id);
                        if ($id === 50 || $id === 51) $checked += $this->pairCheck($db, $all, $id);
                    }
                    return ['products' => 20, 'earlier_owner_pairs' => $checked, 'untimed' => true];
                });
            };
        }
        if ($conflict && $this->build !== 'candidate') $this->note('conflict reference not asserted for baseline 1.8 (its ownership semantics differ); timings only');
        $this->discoverAndTime('timing', self::perfShapes(), $forced, $this->expectedMode($forced), $expectFor, $after);
    }

    /** Candidate only, after all timing chunks: at most one bounded EXPLAIN ANALYZE per ownership shape and series shape, outside timing. */
    private function stageDiagnostic()
    {
        $info = $this->serverInfo();
        if (!$this->prepareFixture('perf', $this->m['variant'])) return;
        $forced = $this->forcedMode();
        $db = new PNMS_DB($this);
        foreach (self::perfShapes() as $name => $s) {
            $this->runCase('diagnostic:' . $name, function () use ($db, $name, $s, $forced, $info) {
                $db->caseKey = 'diagnostic:' . $name;
                $db->mode = 'discovery';
                $db->last = [];
                $ctl = $this->controller($db, new PNMS_Cache(), $forced);
                $cold = $this->measureSeries($db, $ctl, $s['ids'], $s['k'], 'cold', false, null);
                $out = ['mode' => $cold['mode'], 'counts' => $cold['counts'], 'analyze' => []];
                foreach (['product_ownership', 'category_ownership'] as $class) {
                    if (!isset($db->last[$class])) continue;
                    if (!$info['analyze']) { $out['analyze'][$class] = 'unmeasured-unsupported'; continue; }
                    $r = $this->exec('diagnostic', 'EXPLAIN ANALYZE ' . $db->last[$class]);
                    $text = (string)($r['rows'][0]['EXPLAIN'] ?? '');
                    $out['analyze'][$class] = ['ord' => $r['ord'], 'worker_ns' => $r['ns'],
                        'plan' => substr(preg_replace('/[^\x20-\x7e\n]/', '', str_replace($this->prefix, '{p}', $text)), 0, 8192)];
                }
                return $out;
            });
            if (!$info['analyze']) $this->cases[count($this->cases) - 1]['gaps'] = true;
        }
    }

    /** Existing semantic correctness matrix for one collation (candidate only), on run-owned fixtures; no shop row is touched. */
    private function stageSemantic()
    {
        if (!$this->prepareFixture('semantic', null)) return;
        $col = $this->m['collation'];
        $ci = in_array($col, PNMS::CI_COLLATIONS, true);
        $t = '`' . $this->prefix . 'seo_url`';
        $db = new PNMS_DB($this);
        $db->mode = 'semantic';
        $this->runCase('semantic:natural', function () use ($db, $ci) {
            $db->caseKey = 'semantic:natural';
            $ctl = $this->controller($db, new PNMS_Cache());
            $db->capture = [];
            for ($id = 1; $id <= 20; $id++) $this->expect(self::call($ctl['c'], 'readProductAddressData', $id)['keyword'] === 'p' . $id, 'product-keyword');
            $this->expect(self::call($ctl['c'], 'readProductCategoryData', 11)['path'] === '10_11', 'hierarchy');
            $cap = $db->capture;
            $counts = self::countClasses($cap);
            $mode = $ctl['config']->get('seo_pro_keyword_prefilter_mode');
            $this->expect($mode === ($ci ? 'ci' : 'exact'), 'mode-detection');
            $this->expect(count($cap) === 22 && $counts['product_ownership'] === 20 && $counts['category_ownership'] === 1 && $counts['metadata'] === 1, 'budget-20+1-and-metadata');
            for ($id = 1; $id <= 20; $id++) $this->expect($ctl['url']->link('product/product', ['product_id' => $id], true) === PNMS::BASE_URL . 'root/leaf/p' . $id . '.html', 'url-end-to-end');
            $this->expect(count($db->capture) === count($cap), 'memoization');
            $ns = 0;
            foreach ($cap as $r) $ns += $r['ns'];
            $db->capture = null;
            return ['mode' => $mode, 'counts' => $counts, 'worker_sql_ns' => $ns];
        });
        foreach (self::conflictStatements() as $rows) $this->insertRows('seo_url', $rows);
        list($ref, $all) = $this->ownershipReference();
        foreach (['ci', 'exact'] as $mode) {
            // CI is compared with exact only on the confirmed CI branch; on CS/binary the prefilter can miss a case pair.
            if ($mode === 'ci' && !$ci) continue;
            $this->runCase('semantic:reference-' . $mode, function () use ($db, $mode, $ref, $all) {
                $db->caseKey = 'semantic:reference-' . $mode;
                $ctl = $this->controller($db, new PNMS_Cache(), $mode);
                $pairs = 0;
                foreach ([50, 51] as $product) {
                    $this->expect(self::call($ctl['c'], 'readProductAddressData', $product)['keyword'] === ($ref['product_id=' . $product] ?? null), 'reference-ownership');
                    $pairs += $this->pairCheck($db, $all, $product);
                }
                return ['forced_mode' => $mode, 'earlier_owner_pairs' => $pairs];
            });
        }
        if ($ci) {
            $this->runCase('semantic:incoming-fallback', function () use ($db, $t) {
                $db->caseKey = 'semantic:incoming';
                $this->insertRows('seo_url', [[650, 0, 1, 'product_id=70', 'café-fallback']]);
                try {
                    $in = $this->controller($db, new PNMS_Cache());
                    self::call($in['c'], 'loadCache', false);
                    $page = self::call($in['c'], 'resolveAddress', 'cafe-fallback.html');
                    $this->expect(($page['params']['product_id'] ?? null) === '70', 'incoming-cafe-fallback');
                    $this->exec('setup', 'UPDATE ' . $t . " SET keyword = 'renamed-fallback' WHERE seo_url_id = 650");
                    self::call($in['c'], 'resolveAddress', 'café-fallback.html');
                    $stale = new ReflectionProperty($in['c'], 'stale');
                    $stale->setAccessible(true);
                    $this->expect($stale->getValue($in['c']) === true, 'update-detected-by-map-verification');
                    self::call($in['c'], 'loadCache', true);
                    $this->expect(self::call($in['c'], 'resolveAddress', 'café-fallback.html') === null, 'old-keyword-unknown-after-rebuild');
                } finally {
                    $this->exec('setup', 'DELETE FROM ' . $t . ' WHERE seo_url_id = 650');
                }
                return ['untimed' => true];
            });
        }
        $this->exec('setup', 'DELETE FROM ' . $t . ' WHERE seo_url_id BETWEEN 500 AND 512 OR seo_url_id BETWEEN 700 AND 713 OR seo_url_id IN (400,401,801)');
        $base = self::datasetDef('semantic', null, $col, $this->m['engine']);
        $this->runCase('semantic:restoration-after-error', function () use ($t, $base) {
            // Ported onto the run-owned fixture (the legacy variant mutated a shop row; supervised mode never does).
            $before = $this->exec('measure', 'SELECT seo_url_id, store_id, language_id, `query`, keyword FROM ' . $t . ' WHERE seo_url_id = 101')['rows'];
            $this->expect(count($before) === 1, 'restore-row-present');
            try {
                $this->exec('setup', 'UPDATE ' . $t . " SET keyword = 'fixture-injected-change' WHERE seo_url_id = 101");
                $this->insertRows('seo_url', [[900, 0, 1, 'product_id=999', 'fixture-injected-new']]);
                throw new PNMS_Injected('fixture-injected-failure');
            } catch (PNMS_Injected $e) {
                // expected
            } finally {
                $this->exec('setup', 'UPDATE ' . $t . " SET keyword = '" . PNMS_Lib::escape($before[0]['keyword']) . "' WHERE seo_url_id = 101");
                $this->exec('setup', 'DELETE FROM ' . $t . ' WHERE seo_url_id = 900');
            }
            $after = $this->exec('measure', 'SELECT seo_url_id, store_id, language_id, `query`, keyword FROM ' . $t . ' WHERE seo_url_id = 101')['rows'];
            $this->expect($after === $before, 'row-restored-after-exception');
            $this->verifyDataset($base);
            return ['dataset_restored' => true];
        });
    }

    // ---- statement execution ---------------------------------------------------------------

    private function useRole($role)
    {
        if ($this->role === $role) return;
        $this->w->close();
        $this->role = null;
        $this->w->connect($role, new PNS_MysqliWorkerDriver($this->cred));   // fresh instance, options before connect
        $this->role = $role;
        $this->connections++;
    }

    /** The only SQL path. Guard first, then BEGIN/GO/END/ACK. A non-zero errno never returns (the supervisor STOPs). */
    /** After a STOP or a channel failure nothing more may be sent (e.g. from a finally block): rethrow instead. */
    private function protocol(callable $fn)
    {
        if ($this->dead !== null) throw $this->dead === 'stop' ? new PNS_WorkerStop('stopped') : new PNS_WorkerFailure('worker-dead');
        try {
            return $fn();
        } catch (PNS_WorkerStop $e) {
            $this->dead = 'stop';
            throw $e;
        } catch (PNS_WorkerFailure $e) {
            $this->dead = 'failure';
            throw $e;
        }
    }

    public function exec($phase, $sql, $sleep = false)
    {
        $this->protocol(function () use ($phase) { $this->useRole($phase === 'setup' ? 'setup' : 'measure'); });
        $g = PNMS_Sql::check($sql, $phase, $this->ctx + ['sleep' => $sleep]);
        $digest = hash('sha256', $sql);
        $i = count($this->stmts);
        $this->stmts[] = [$digest, false];
        $r = $this->protocol(function () use ($phase, $g, $sql) { return $this->w->statement($phase, $g['kind'], $sql, $g['target']); });
        $this->stmts[$i][1] = true;
        if ((int)$r['errno'] !== 0) throw new PNMS_AssertFail('sql-errno');
        return ['ord' => $i + 1, 'digest' => $digest, 'rows' => $r['rows'], 'n' => count($r['rows']), 'ns' => (int)$r['duration_ns'], 'affected' => (int)$r['affected']];
    }

    /**
     * The only path that returns a non-zero errno instead of failing: preflight only, and only for the exact
     * error-probe SQL on the owned probe table (the supervisor independently permits that one digest/errno once).
     */
    private function execExpectError($sql)
    {
        $probe = $this->prefix . PNMS::PROBE_TABLE;
        if ($this->stage !== 'preflight' || $sql !== PNMS_Lib::errorProbeSql($probe) || !in_array($probe, $this->ctx['owned'], true)) throw new PNMS_GuardError('expected-error-scope');
        $this->protocol(function () { $this->useRole('measure'); });
        $g = PNMS_Sql::check($sql, 'measure', $this->ctx + ['sleep' => false]);
        $i = count($this->stmts);
        $this->stmts[] = [hash('sha256', $sql), false];
        $r = $this->protocol(function () use ($g, $sql) { return $this->w->statement('measure', $g['kind'], $sql, null); });
        $this->stmts[$i][1] = true;
        return ['ord' => $i + 1, 'errno' => (int)$r['errno']];
    }

    public function createTable($suffix, $columns, $engine, $collation)
    {
        $name = $this->prefix . $suffix;
        if (!in_array($name, $this->ctx['owned'], true)) throw new PNMS_GuardError('create-not-owned');
        if (!in_array($engine, PNMS::ENGINES, true) || !in_array($collation, array_merge(PNMS::COLLATIONS, ['utf8mb4_unicode_ci']), true)) throw new PNMS_GuardError('create-options');
        $this->protocol(function () use ($name) {
            $this->useRole('setup');
            $this->w->registerCreate($name);   // durable ledger entry before CREATE is submitted
        });
        $this->exec('setup', 'CREATE TABLE `' . $name . '` (' . $columns . ') ENGINE=' . $engine . ' DEFAULT CHARSET=utf8mb4 COLLATE=' . $collation);
        return $name;
    }

    /**
     * EXPLAIN before the first execution of each SELECT digest of a case (diagnostic phase, 2 s, outside any series timer).
     * While timed, an unplanned digest makes the case incomplete instead of adding EXPLAIN into the timed call.
     */
    public function plan($sql, $class, $caseKey, $mode, $timed)
    {
        $key = $this->build . '|' . $caseKey . '|' . $mode . '|' . hash('sha256', $sql);
        if (isset($this->plans[$key])) {
            if ($this->plans[$key]['blocked']) throw new PNMS_Incomplete('plan-blocked-' . $class);
            return;
        }
        if ($timed) throw new PNMS_Incomplete('unplanned-digest-in-timing');
        $r = $this->exec('diagnostic', 'EXPLAIN ' . $sql);
        $rows = [];
        $fullSeo = false;
        foreach ($r['rows'] as $row) {
            $tbl = (string)($row['table'] ?? '');
            $type = (string)($row['type'] ?? '');
            $rows[] = ['table' => substr(preg_replace('/[^A-Za-z0-9_<>,]/', '', str_replace($this->prefix, '{p}', $tbl)), 0, 64),
                'type' => substr(preg_replace('/[^A-Za-z_]/', '', $type), 0, 16),
                'key' => substr(preg_replace('/[^A-Za-z0-9_,]/', '', (string)($row['key'] ?? '')), 0, 64),
                'rows_estimate' => isset($row['rows']) ? (int)$row['rows'] : null,
                'extra' => substr(preg_replace('/[^A-Za-z0-9 ;,_()-]/', '', (string)($row['Extra'] ?? '')), 0, 160)];
            if (in_array($tbl, ['su', 'd'], true) && strtoupper($type) === 'ALL') $fullSeo = true;
        }
        $ownership = in_array($class, ['product_ownership', 'category_ownership'], true);
        $blocked = $this->build === 'candidate' && $ownership && $mode === 'ci' && $fullSeo;
        $this->plans[$key] = ['class' => $class, 'mode' => $mode, 'case' => $caseKey, 'digest' => hash('sha256', $sql), 'explain_ord' => $r['ord'],
            'plan' => $rows, 'full_seo_url_scan' => $fullSeo, 'risk' => $fullSeo && !$blocked ? ($ownership ? 'exact-or-cs-full-scan' : 'expected-map-scan') : null,
            'blocked' => $blocked];
        if ($blocked) throw new PNMS_Incomplete('ci-ownership-full-scan');
    }

    // ---- runtime state ---------------------------------------------------------------------

    /** Strict reset at every simulated request: exactly ControllerExtensionStartupSeoPro::$path_groups; drift fails. */
    public function resetStatics()
    {
        $rc = new ReflectionClass('ControllerExtensionStartupSeoPro');
        $statics = array_keys($rc->getStaticProperties());
        sort($statics);
        if ($statics !== ['path_groups']) throw new PNMS_AssertFail('runtime-static-drift');
        foreach (['Seo_pro_schema', 'Seo_pro_cache'] as $c) {
            if (class_exists($c, false) && (new ReflectionClass($c))->getStaticProperties()) throw new PNMS_AssertFail('runtime-static-drift-' . strtolower($c));
        }
        $p = $rc->getProperty('path_groups');
        $p->setAccessible(true);
        $p->setValue(null, []);
        if ($p->getValue() !== []) throw new PNMS_AssertFail('runtime-static-reset');
        $this->staticsSeen = $statics;
        $this->resets++;
    }

    /** Fresh Registry/Config/Url/controller; forced prefilter mode is injected before construction and recorded by the caller. */
    public function controller(PNMS_DB $db, PNMS_Cache $cache, $forcedMode = null, $resetStatics = true)
    {
        if ($resetStatics) $this->resetStatics();
        $r = new Registry();
        $cfg = new Config();
        foreach (PNMS::CONFIG as $k => $v) $cfg->set($k, $v);
        if ($forcedMode !== null) {
            if ($forcedMode !== 'exact' && $forcedMode !== 'ci') throw new PNMS_AssertFail('forced-mode');
            $cfg->set('seo_pro_keyword_prefilter_mode', $forcedMode);
        }
        $log = new PNMS_Log();
        $r->set('db', $db);
        $r->set('cache', $cache);
        $r->set('config', $cfg);
        $r->set('log', $log);
        $url = new Url($cfg->get('config_url'), $cfg->get('config_ssl'));
        $r->set('url', $url);
        $c = new ControllerExtensionStartupSeoPro($r);
        $url->addRewrite($c);
        $db->modeProbe = function () use ($cfg) { return $cfg->get('seo_pro_keyword_prefilter_mode'); };
        return ['c' => $c, 'config' => $cfg, 'log' => $log, 'url' => $url];
    }

    public static function call($c, $name)
    {
        $m = new ReflectionMethod($c, $name);
        $m->setAccessible(true);
        return $m->invokeArgs($c, array_slice(func_get_args(), 2));
    }

    // ---- evidence --------------------------------------------------------------------------

    public function addCase($id, $status, $code = null, array $data = [], $gaps = false)
    {
        if (!in_array($status, PNMS::CASE_STATUSES, true)) throw new PNMS_AssertFail('case-status');
        $this->cases[] = ['id' => $id, 'status' => $status, 'code' => $code, 'gaps' => (bool)$gaps, 'data' => $data];
        return count($this->cases) - 1;
    }

    public function setCase($i, $status, $code = null, ?array $data = null)
    {
        $this->cases[$i]['status'] = $status;
        $this->cases[$i]['code'] = $code;
        if ($data !== null) $this->cases[$i]['data'] = $data + $this->cases[$i]['data'];
    }

    public function note($n) { if (count($this->notes) < 200) $this->notes[] = substr((string)$n, 0, 160); }

    public function writeEvidence()
    {
        if ($this->written || $this->evPath === '') return;
        $this->written = true;
        $doc = [
            'schema' => PNMS::EVIDENCE_SCHEMA, 'tag' => $this->tag, 'chunk_id' => $this->m['chunk_id'], 'stage' => $this->stage, 'build' => $this->build,
            'manifest_sha256' => PNMS_Manifest::sha($this->m), 'outcome' => $this->outcome, 'php' => PHP_VERSION,
            'db_prefix_kind' => PNMS_Lib::isStand($this->stage) ? 'shop' : 'fixture', 'config_sha256' => $this->configSha,
            'runtime_statics' => $this->staticsSeen, 'static_resets' => $this->resets, 'connections' => $this->connections,
            'dataset' => $this->dataset, 'plans' => array_values($this->plans), 'cases' => $this->cases, 'statements' => $this->stmts, 'notes' => $this->notes,
        ];
        $json = json_encode($doc, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        if (!is_string($json) || strlen($json) > PNMS::EVIDENCE_MAX) {
            $doc['plans'] = [];
            $doc['cases'] = [['id' => 'evidence', 'status' => 'fail', 'code' => 'evidence-oversize', 'gaps' => false, 'data' => []]];
            $json = json_encode($doc, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        }
        PNMS_Lib::writeExclusive($this->evPath, $json . "\n");
    }

    public function setDataset(array $d) { $this->dataset = $d; }

    // ---- preflight -------------------------------------------------------------------------

    private static function clean($v, $n = 120) { return substr(preg_replace('/[^A-Za-z0-9_.,=+ -]/', '', (string)$v), 0, $n); }

    private function serverInfo()
    {
        $v = (string)($this->exec('measure', 'SELECT VERSION() AS v')['rows'][0]['v'] ?? '');
        $family = stripos($v, 'mariadb') !== false ? 'mariadb' : 'mysql';
        $num = preg_match('/^(\d+\.\d+\.\d+)/', $v, $mm) ? $mm[1] : '0.0.0';
        return ['family' => $family, 'version' => $num, 'analyze' => $family === 'mysql' && version_compare($num, '8.0.18', '>=')];
    }

    private function shopDefinition()
    {
        $names = [];
        foreach (PNMS::FIXTURE_TABLES as $s) $names[] = "'" . PNMS_Lib::escape($this->shop . $s) . "'";
        $out = ['tables' => [], 'seo_url_columns' => [], 'seo_url_indexes' => []];
        foreach ($this->exec('measure', 'SELECT TABLE_NAME AS t, ENGINE AS e, TABLE_COLLATION AS c FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (' . implode(',', $names) . ') ORDER BY TABLE_NAME')['rows'] as $row) {
            $out['tables'][substr((string)$row['t'], strlen($this->shop))] = ['engine' => self::clean($row['e'], 32), 'collation' => self::clean($row['c'], 64)];
        }
        $seo = "'" . PNMS_Lib::escape($this->shop . 'seo_url') . "'";
        foreach ($this->exec('measure', 'SELECT COLUMN_NAME AS n, COLUMN_TYPE AS y, COLLATION_NAME AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ' . $seo . ' ORDER BY ORDINAL_POSITION')['rows'] as $row) {
            $out['seo_url_columns'][] = [self::clean($row['n'], 64), self::clean(str_replace(['(', ')'], ['=', ''], $row['y']), 64), self::clean($row['c'], 64)];
        }
        foreach ($this->exec('measure', 'SELECT INDEX_NAME AS i, SEQ_IN_INDEX AS s, COLUMN_NAME AS n, SUB_PART AS p FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ' . $seo . ' ORDER BY INDEX_NAME, SEQ_IN_INDEX')['rows'] as $row) {
            $out['seo_url_indexes'][] = [self::clean($row['i'], 64), (int)$row['s'], self::clean($row['n'], 64), $row['p'] === null ? null : (int)$row['p']];
        }
        return $out;
    }

    /**
     * The single c001 chunk (runs only after the supervisor's control/identity preflight succeeded and before any
     * benchmark chunk): server/feature inventory, shop definition, bounded read-only frozen sample selection, durable
     * shop control before/after the probe writes, run-owned probe table, plain EXPLAIN, optional bounded EXPLAIN ANALYZE,
     * the one permitted expected-error probe, then the LAST statement: a SLEEP probe on the owned table that must be
     * KILL-QUERY cancelled at 2 s. The supervisor then STOPs the worker and a fresh cleanup worker drops the probe.
     */
    private function stagePreflight()
    {
        $info = $this->serverInfo();
        $vars = [];
        foreach ($this->exec('measure', "SHOW VARIABLES WHERE Variable_name IN ('have_query_cache','query_cache_type','query_cache_size','performance_schema','max_execution_time','max_statement_time','lock_wait_timeout','sql_mode','version_comment')")['rows'] as $row) {
            $vars[self::clean($row['Variable_name'] ?? '', 64)] = self::clean($row['Value'] ?? '', 200);
        }
        $this->addCase('server', 'pass', null, $info + ['variables' => $vars]);
        $have = [];
        foreach ($this->exec('measure', "SHOW COLLATION WHERE Collation IN ('" . implode("','", PNMS::COLLATIONS) . "')")['rows'] as $row) $have[] = (string)$row['Collation'];
        $missing = array_values(array_diff(PNMS::COLLATIONS, $have));
        $this->addCase('collations', 'pass', $missing ? 'coverage-gap' : null, ['available' => array_values(array_intersect(PNMS::COLLATIONS, $have)), 'missing' => $missing], (bool)$missing);
        $def = $this->shopDefinition();
        $engine = $def['tables']['seo_url']['engine'] ?? null;
        $this->addCase('shop-definition', in_array($engine, PNMS::ENGINES, true) ? 'pass' : 'incomplete', in_array($engine, PNMS::ENGINES, true) ? null : 'shop-engine-unsupported', $def);
        $this->inventorySample();
        $before = $this->shopControl();
        $probeEngine = in_array($engine, PNMS::ENGINES, true) ? $engine : 'MyISAM';
        $t = $this->createTable(PNMS::PROBE_TABLE, 'id int NOT NULL, PRIMARY KEY (id)', $probeEngine, 'utf8mb4_unicode_ci');
        $this->exec('setup', 'INSERT INTO `' . $t . '` VALUES (1)');
        $this->addCase('probe-create', 'pass', null, ['engine' => $probeEngine]);
        $read = $this->exec('measure', 'SELECT id FROM `' . $t . '`');
        $this->addCase('probe-read', $read['n'] === 1 ? 'pass' : 'fail', $read['n'] === 1 ? null : 'probe-rows', ['ord' => $read['ord'], 'digest' => $read['digest']]);
        $ex = $this->exec('diagnostic', 'EXPLAIN SELECT id FROM `' . $t . '`');
        $this->addCase('explain', 'pass', null, ['ord' => $ex['ord']]);
        if ($info['analyze']) {
            $an = $this->exec('diagnostic', 'EXPLAIN ANALYZE SELECT id FROM `' . $t . '`');
            $this->addCase('explain-analyze', 'pass', null, ['supported' => true, 'ord' => $an['ord']]);
        } else {
            $this->addCase('explain-analyze', 'pass', 'unmeasured-unsupported', ['supported' => false], true);
        }
        // All probe writes are done: the shop control must be unchanged (the probe SELECTs below write nothing).
        $this->shopControlCase($before);
        // Error propagation: the supervisor permits exactly this digest with exactly this errno, once. The worker must
        // receive the server errno through END/ACK; the supervisor's record decides the case (pending until then).
        $esql = PNMS_Lib::errorProbeSql($t);
        $ei = $this->addCase('error-propagation', 'pending-supervisor', null, ['digest' => hash('sha256', $esql), 'expected_errno' => PNMS::ERROR_PROBE_ERRNO]);
        $er = $this->execExpectError($esql);
        if ($er['errno'] !== PNMS::ERROR_PROBE_ERRNO) $this->setCase($ei, 'fail', $er['errno'] === 0 ? 'error-probe-succeeded' : 'error-probe-errno', ['observed_errno' => $er['errno']]);
        else $this->setCase($ei, 'pending-supervisor', null, ['observed_errno' => $er['errno'], 'ord' => $er['ord']]);
        $sql = 'SELECT SLEEP(' . PNMS::PROBE_SLEEP . ') AS s FROM `' . $t . '`';
        $i = $this->addCase('cancel-probe', 'pending-supervisor', null, ['digest' => hash('sha256', $sql), 'sleep_s' => (float)PNMS::PROBE_SLEEP]);
        $r = $this->exec('measure', $sql, true);   // expected: STOP after the confirmed KILL QUERY (PNS_WorkerStop propagates)
        $this->setCase($i, 'fail', 'cancel-not-exercised', ['worker_ns' => $r['ns']]);
    }

    // ---- inventory (read-only shop; fixes the frozen sample) -------------------------------

    private function singleMainPredicate($alias)
    {
        $p = $this->shop;
        return $alias . '.main_category = 1 AND (SELECT COUNT(*) FROM ' . $p . 'product_to_category x WHERE x.product_id = ' . $alias . '.product_id AND x.main_category = 1) = 1';
    }

    /** A category whose complete category_path chain exists, is enabled, in store 0 and translated for language 1. */
    private function validCategory($id)
    {
        $p = $this->shop;
        $rows = $this->exec('measure', 'SELECT cp.path_id AS p, cp.level AS l, c.parent_id AS pa, c.status AS s, cs.store_id AS st, cd.category_id AS d FROM ' . $p . 'category_path cp'
            . ' LEFT JOIN ' . $p . 'category c ON c.category_id = cp.path_id LEFT JOIN ' . $p . 'category_to_store cs ON cs.category_id = cp.path_id AND cs.store_id = 0'
            . ' LEFT JOIN ' . $p . 'category_description cd ON cd.category_id = cp.path_id AND cd.language_id = 1 WHERE cp.category_id = ' . (int)$id . ' ORDER BY cp.level')['rows'];
        if (!$rows) return false;
        $parent = 0;
        foreach ($rows as $n => $r) {
            if ((int)$r['l'] !== $n || $r['pa'] === null || (int)$r['s'] !== 1 || $r['st'] === null || $r['d'] === null || (int)$r['pa'] !== $parent) return false;
            $parent = (int)$r['p'];
        }
        return $parent === (int)$id;
    }

    private function idsInCategory($cat, $limit)
    {
        $p = $this->shop;
        $ids = [];
        foreach ($this->exec('measure', 'SELECT pc.product_id AS id FROM ' . $p . 'product_to_category pc JOIN ' . $p . 'product pr ON pr.product_id = pc.product_id WHERE pc.category_id = ' . (int)$cat
            . ' AND ' . $this->singleMainPredicate('pc') . ' ORDER BY pc.product_id LIMIT ' . (int)$limit)['rows'] as $r) $ids[] = (int)$r['id'];
        return $ids;
    }

    /**
     * Bounded read-only frozen sample (inside preflight): <= 1 grouping SELECT, <= 40 category validations, <= 4 ID
     * SELECTs, 1 no-main SELECT. Groups 7/7/6 across three distinct valid categories, 20 in one valid category, 20 without
     * a main category; IDs ascending. Overlap between series is allowed (the design fixes each shape, not disjointness).
     * A missing shape is recorded as incomplete (exit 3), never as completed.
     */
    private function inventorySample()
    {
        $p = $this->shop;
        $counts = [];
        foreach ($this->exec('measure', 'SELECT pc.category_id AS c, COUNT(*) AS n FROM ' . $p . 'product_to_category pc JOIN ' . $p . 'product pr ON pr.product_id = pc.product_id WHERE '
            . $this->singleMainPredicate('pc') . ' GROUP BY pc.category_id HAVING n >= 6 ORDER BY pc.category_id LIMIT 200')['rows'] as $r) $counts[(int)$r['c']] = (int)$r['n'];
        $valid = [];
        $checked = 0;
        foreach ($counts as $cat => $n) {
            if ($checked++ >= 40) break;
            if ($this->validCategory($cat)) $valid[$cat] = $n;
        }
        $sample = ['three' => null, 'one' => null, 'none' => null];
        $seven = array_keys(array_filter($valid, function ($n) { return $n >= 7; }));
        if (count($seven) >= 2) {
            $third = null;
            foreach ($valid as $cat => $n) if ($n >= 6 && $cat !== $seven[0] && $cat !== $seven[1]) { $third = $cat; break; }
            if ($third !== null) {
                $groups = [];
                foreach ([$seven[0], $seven[1], $third] as $i => $cat) $groups[] = ['category_id' => $cat, 'ids' => $this->idsInCategory($cat, PNMS::SAMPLE_SIZES[$i])];
                $sample['three'] = ['groups' => $groups];
            }
        }
        foreach ($valid as $cat => $n) {
            if ($n < PNMS::SERIES_N) continue;
            $sample['one'] = ['category_id' => $cat, 'ids' => $this->idsInCategory($cat, PNMS::SERIES_N)];
            break;
        }
        $none = [];
        foreach ($this->exec('measure', 'SELECT pr.product_id AS id FROM ' . $p . 'product pr WHERE NOT EXISTS (SELECT 1 FROM ' . $p . 'product_to_category pc WHERE pc.product_id = pr.product_id AND pc.main_category = 1) ORDER BY pr.product_id LIMIT ' . PNMS::SERIES_N)['rows'] as $r) $none[] = (int)$r['id'];
        if (count($none) === PNMS::SERIES_N) $sample['none'] = ['ids' => $none];
        try {
            $sample = PNMS_Manifest::sample($sample);
        } catch (InvalidArgumentException $e) {
            $this->addCase('sample', 'fail', 'sample-shape-invalid');
            return;
        }
        foreach (['three', 'one', 'none'] as $shape) {
            $this->addCase('shape:' . $shape, $sample[$shape] === null ? 'incomplete' : 'pass', $sample[$shape] === null ? 'shape-unavailable' : null);
        }
        $this->addCase('sample', 'pass', null, ['sample' => $sample, 'sample_sha256' => PNMS_Manifest::sampleSha($sample),
            'valid_definition' => 'single main_category row; product exists; full enabled category_path chain in store 0 with language 1 description',
            'categories_checked' => min($checked, 40)]);
    }
}

final class PNMS_WorkerEntry
{
    public static function main()
    {
        PNS_Worker::main(function (PNS_Worker $w) {
            $run = new PNMS_Run($w);
            try {
                $run->init();
                $run->dispatch();
                $run->outcome = 'complete';
            } catch (PNS_WorkerStop $e) {
                $run->outcome = 'stopped';
                throw $e;
            } catch (Throwable $e) {
                $run->outcome = 'error';
                $run->note('worker-error ' . PNMS_Lib::code($e));
                throw $e;
            } finally {
                $run->writeEvidence();
            }
        });
    }
}
// ==PNMS:worker:END==
// ==PNMS:remote:BEGIN==
/* Remote supervisor entry: runs inside the emitted stdin bundle; never loads a runtime build. */
final class PNMS_Remote
{
    public static function main()
    {
        $doc = ['schema' => PNMS::RESULT_SCHEMA, 'status' => 1, 'label' => 'failed', 'reasons' => []];
        try {
            $doc = self::run();
        } catch (Throwable $e) {
            $doc['reasons'][] = 'remote-internal ' . PNMS_Lib::code($e);
            $doc['status'] = 1;
        }
        echo json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR) . "\n";
        exit((int)$doc['status']);
    }

    /** Early result: identifiers only once the embedded case hash has been verified (never from an unverified case). */
    private static function early($code, $status, array $ids = [])
    {
        return ['schema' => PNMS::RESULT_SCHEMA, 'status' => $status, 'label' => $status === 2 ? 'not-run-preflight' : 'failed', 'reasons' => [$code]] + $ids;
    }

    /**
     * Strict optional remote lowering of the chunk submission window: positive integral seconds without sign,
     * leading zero or fraction, <= the manifest/default value and <= 180. Never clamped, never widened.
     * Returns [effective_seconds|null, error_code|null].
     */
    public static function chunkSecondsEnv($raw, array $m)
    {
        if ($raw === false || $raw === null) return [null, null];
        $ceiling = min(PNS::CHUNK_SECONDS, (float)($m['limits']['chunk_seconds'] ?? PNS::CHUNK_SECONDS));
        if (!is_string($raw) || !preg_match('/^[1-9][0-9]{0,2}$/D', $raw)) return [null, 'chunk-seconds-env-invalid'];
        $v = (int)$raw;
        if ($v > 180 || $v > $ceiling) return [null, 'chunk-seconds-env-above-limit'];
        return [$v, null];
    }

    /** True when $dir (or its nearest existing ancestor, resolved) lies inside the shop installation that holds the config. */
    public static function insideInstallation($dir, $configPath)
    {
        $cfg = realpath((string)$configPath);
        if ($cfg === false) return true;
        $roots = array_unique([rtrim(dirname((string)$configPath), '/'), rtrim(dirname($cfg), '/')]);
        $probe = $dir;
        while ($probe !== '/' && $probe !== '' && !file_exists($probe)) $probe = dirname($probe);
        $real = realpath($probe);
        $cands = array_unique(array_filter([rtrim($dir, '/'), $real === false ? null : rtrim($real, '/') . substr($dir, strlen($probe))]));
        foreach ($cands as $c) foreach ($roots as $r) if ($r !== '' && ($c === $r || strpos($c . '/', $r . '/') === 0)) return true;
        return false;
    }

    /**
     * Bounded transfer of the supervisor-owned evidence files: regular 0600 files (no link) directly under the chunk dir,
     * per-file size cap, JSON objects / JSON-object lines. Returns [evidence|null, summary, error_code|null].
     */
    public static function transfer($chunkDir)
    {
        $out = [];
        $summary = [];
        foreach (PNMS::TRANSFER_MAX as $name => $max) {
            $path = $chunkDir . '/' . $name;
            clearstatcache();
            if (!file_exists($path)) return [null, $summary, 'evidence-transfer-missing-' . $name];
            if (is_link($path) || !is_file($path) || (fileperms($path) & 0777) !== 0600) return [null, $summary, 'evidence-transfer-file-' . $name];
            $size = filesize($path);
            if (!is_int($size) || $size > $max) return [null, $summary, 'evidence-transfer-oversize-' . $name];
            $raw = file_get_contents($path, false, null, 0, $max + 1);
            if (!is_string($raw) || strlen($raw) !== $size) return [null, $summary, 'evidence-transfer-read-' . $name];
            if ($name === 'events.jsonl') {
                foreach (explode("\n", rtrim($raw, "\n")) as $line) {
                    if ($line === '' && $raw === '') break;
                    $d = json_decode($line, false, 32);
                    if (!is_object($d)) return [null, $summary, 'evidence-transfer-shape-' . $name];
                }
                $out[$name] = $raw;
            } else {
                $d = json_decode($raw, false, 64);
                if (!is_object($d)) return [null, $summary, 'evidence-transfer-shape-' . $name];
                $out[$name] = $d;
            }
            $summary[$name] = ['bytes' => $size, 'sha256' => hash('sha256', $raw)];
        }
        return [$out, $summary, null];
    }

    private static function run()
    {
        $embed = defined('PNMS_EMBED') ? json_decode((string)base64_decode(PNMS_EMBED, true), true, 64) : null;
        if (!is_array($embed) || ($embed['schema'] ?? null) !== PNMS::BUNDLE_SCHEMA || !is_array($embed['sources'] ?? null)) return self::early('embed-invalid', 1);
        try {
            $m = PNMS_Manifest::validate($embed['case'] ?? null);
        } catch (InvalidArgumentException $e) {
            return self::early('manifest-invalid', 1);
        }
        if (!is_string($embed['case_sha256'] ?? null) || !hash_equals($embed['case_sha256'], PNMS_Manifest::sha($m))) return self::early('manifest-hash', 1);
        $ids = ['run_id' => $m['run_id'], 'chunk_id' => $m['chunk_id'], 'stage' => $m['stage'], 'build' => $m['build'], 'manifest_sha256' => PNMS_Manifest::sha($m),
            'bundle' => ['sources' => $embed['sources'], 'runtime' => $embed['runtime'] ?? null, 'oc' => $embed['oc'] ?? null], 'remote_php' => PHP_VERSION];
        try {
            $work = PNS_Bundle::decodePayload($embed['workload'] ?? '');
            $clean = PNS_Bundle::decodePayload($embed['cleanup'] ?? '');
        } catch (InvalidArgumentException $e) {
            return self::early('payload-invalid', 1, $ids);
        }
        if (!hash_equals((string)$embed['sources']['workload_sha256'], hash('sha256', $work)) || !hash_equals((string)$embed['sources']['cleanup_sha256'], hash('sha256', $clean))) {
            return self::early('payload-hash', 1, $ids);
        }
        list($envSeconds, $envErr) = self::chunkSecondsEnv(getenv(PNMS::CHUNK_SECONDS_ENV), $m);
        if ($envErr !== null) return self::early($envErr, 1, $ids);
        if (!PNMS_Lib::parses($work) || !PNMS_Lib::parses($clean)) return self::early('remote-php-parse', 2, $ids);
        if (!extension_loaded('mysqli')) return self::early('mysqli-unavailable', 2, $ids);
        try {
            $cred = PNMS_Lib::credentials();
        } catch (PNMS_ConfigError $e) {
            return self::early(PNMS_Lib::code($e), 2, $ids);
        }
        if (self::insideInstallation(rtrim($m['run_dir'], '/'), (string)getenv('SEO_PRO_TEST_STORE_CONFIG'))) return self::early('run-dir-inside-installation', 1, $ids);
        $shop = $cred['DB_PREFIX'];
        if (!PNS_Ledger::validPrefix($m['fixture_prefix'], $shop)) return self::early('fixture-prefix-overlaps-shop', 1, $ids);
        $chunkDir = rtrim($m['run_dir'], '/') . '/chunks/' . $m['chunk_id'];
        $evPath = PNMS_Lib::evidencePath($m);
        $tag = bin2hex(random_bytes(16));
        $limits = [];
        foreach ((array)$m['limits'] as $k => $v) $limits[$k] = $v;
        if ($envSeconds !== null) $limits['chunk_seconds'] = $envSeconds;   // only ever lower (checked above)
        $expectError = null;
        if ($m['stage'] === 'preflight') {
            $expectError = ['phase' => 'measure', 'kind' => 'select', 'digest' => hash('sha256', PNMS_Lib::errorProbeSql($m['fixture_prefix'] . PNMS::PROBE_TABLE)),
                'errno' => PNMS::ERROR_PROBE_ERRNO];
        }
        $spec = [
            'run_dir' => rtrim($m['run_dir'], '/'), 'chunk_id' => $m['chunk_id'], 'build' => $m['build'],
            'db_prefix' => PNMS_Lib::isStand($m['stage']) ? $shop : $m['fixture_prefix'], 'shop_prefix' => $shop, 'fixture_prefix' => $m['fixture_prefix'],
            'workload_source' => $work, 'cleanup_source' => $clean, 'manifest' => $m,
            'env' => ['PATH' => '/usr/bin:/bin', 'SEO_PRO_TEST_STORE_CONFIG' => (string)getenv('SEO_PRO_TEST_STORE_CONFIG'),
                'SEO_PRO_PNMS_EVIDENCE' => $evPath, 'SEO_PRO_PNMS_EVIDENCE_TAG' => $tag],
            'limits' => $limits, 'expect_error' => $expectError,
        ];
        $control = new PNS_MysqlControl($cred);
        $cred = null;
        $sup = (new PNS_Supervisor($control))->run($spec);
        $control = null;
        $ev = self::readEvidence($evPath, $tag, $m);
        $cross = self::crossCheck($sup, $ev);
        $derived = self::derive($m, $sup, $ev, $cross);
        $observed = [];
        $ord = 0;
        foreach ($sup['statements'] as $s) {
            if (($s['worker'] ?? null) !== 'workload') continue;
            $ord++;
            if (in_array($s['phase'] ?? null, ['measure', 'diagnostic'], true)) $observed[] = [$ord, $s['metrics'] ?? 'unmeasured', $s['outcome'] ?? null, $s['supervised_ms'] ?? null];
        }
        $states = [];
        foreach ($sup['ledger'] as $name => $e) $states[$name] = $e['state'];
        // Required supervisor evidence must be retained and transferable; otherwise no success is reported.
        $evidence = null;
        $transfer = null;
        if ($sup['evidence_dir'] === null) {
            $derived['reasons'][] = 'supervisor-evidence-unretained';
            // A chunk that never reached the workload (status 2: access/control unavailable) stays not-run-preflight.
            if ((int)$sup['status'] !== 2) $derived['status'] = PNS::worse($derived['status'], 1);
        } elseif ($sup['evidence_dir'] !== $chunkDir) {
            $derived['reasons'][] = 'supervisor-evidence-dir-unexpected';
            $derived['status'] = PNS::worse($derived['status'], 1);
        } else {
            list($evidence, $transfer, $terr) = self::transfer($chunkDir);
            if ($terr !== null) {
                $derived['reasons'][] = $terr;
                $derived['status'] = PNS::worse($derived['status'], 1);
                $evidence = null;
            }
        }
        $derived['label'] = self::label($derived['status'], $derived['gaps']);
        $effective = is_array($sup['limits'] ?? null) ? $sup['limits'] : null;
        $doc = [
            'schema' => PNMS::RESULT_SCHEMA, 'status' => $derived['status'], 'label' => $derived['label'], 'reasons' => $derived['reasons'],
        ] + $ids + [
            'manifest' => $m,
            'limits' => ['effective' => $effective, 'manifest' => $m['limits'], 'chunk_seconds_env' => $envSeconds,
                'statement_seconds' => PNS::STATEMENT_LIMITS, 'confirm_seconds' => PNS::CONFIRM_WINDOW],
            'supervisor' => ['status' => $sup['status'], 'findings' => $sup['findings'], 'counters' => $sup['counters'], 'kills' => $sup['kills'],
                'caps' => $sup['caps'], 'ledger' => (object)$states, 'recovery' => (object)$sup['recovery'], 'captures' => $sup['captures'],
                'statements' => count($sup['statements']), 'evidence_dir' => $sup['evidence_dir'], 'expected_error' => $sup['expected_error'] ?? null],
            'workload' => ['present' => $ev['present'], 'valid' => $ev['valid'], 'code' => $ev['code'], 'evidence' => $ev['doc']],
            'cross_check' => $cross, 'case_status' => (object)$derived['cases'], 'observed' => $observed,
            'evidence_transfer' => $transfer,
        ];
        if ($m['stage'] === 'preflight') $doc['preflight'] = self::preflightSummary($ev, $derived);
        if ($sup['evidence_dir'] !== null) {
            try {
                PNMS_Lib::writeExclusive($chunkDir . '/result.json', json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR) . "\n");
                $doc['result_file'] = $chunkDir . '/result.json';
            } catch (RuntimeException $e) {
                $doc['reasons'][] = 'result-write-failed';
                $doc['status'] = PNS::worse($doc['status'], 4);
                $doc['label'] = self::label($doc['status'], $derived['gaps']);
            }
        }
        // Transferred copies only on stdout (result.json keeps their sizes/hashes, not a second copy).
        $doc['evidence'] = $evidence;
        return $doc;
    }

    private static function label($st, $gaps)
    {
        return [4 => 'unresolved', 1 => 'failed', 3 => 'incomplete', 2 => 'not-run-preflight', 0 => $gaps ? 'completed-with-measurement-gaps' : 'completed'][$st];
    }

    /** Stable compact preflight summary for the orchestrator; values are copies of workload/supervisor evidence. */
    private static function preflightSummary(array $ev, array $derived)
    {
        $cases = [];
        if ($ev['valid']) foreach ($ev['doc']['cases'] as $c) $cases[$c['id']] = $c;
        $sample = $cases['sample']['data'] ?? [];
        $shapes = [];
        foreach (['three', 'one', 'none'] as $s) $shapes[$s] = $derived['cases']['shape:' . $s] ?? 'unrun';
        return [
            'sample' => $sample['sample'] ?? null, 'sample_sha256' => $sample['sample_sha256'] ?? null, 'sample_status' => $derived['cases']['sample'] ?? 'unrun',
            'shapes' => $shapes,
            'shop_engine' => $cases['shop-definition']['data']['tables']['seo_url']['engine'] ?? null,
            'collations' => isset($cases['collations']) ? ['available' => $cases['collations']['data']['available'] ?? [], 'missing' => $cases['collations']['data']['missing'] ?? []] : null,
            'shop_control' => $derived['cases']['shop-control'] ?? 'unrun',
            'error_propagation' => $derived['cases']['error-propagation'] ?? 'unrun',
            'cancel_probe' => $derived['cases']['cancel-probe'] ?? 'unrun',
            'probe' => $derived['probe'],
        ];
    }

    public static function readEvidence($path, $tag, array $m)
    {
        $r = ['present' => false, 'valid' => false, 'code' => null, 'doc' => null];
        clearstatcache();
        if (!file_exists($path)) { $r['code'] = 'missing'; return $r; }
        $r['present'] = true;
        if (is_link($path) || !is_file($path) || (fileperms($path) & 0777) !== 0600 || filesize($path) > PNMS::EVIDENCE_MAX + 1) { $r['code'] = 'file'; return $r; }
        $d = json_decode((string)file_get_contents($path), true, 64);
        if (!is_array($d) || ($d['schema'] ?? null) !== PNMS::EVIDENCE_SCHEMA || !is_string($d['tag'] ?? null) || !hash_equals($tag, $d['tag'])) { $r['code'] = 'identity'; return $r; }
        if (($d['chunk_id'] ?? null) !== $m['chunk_id'] || ($d['stage'] ?? null) !== $m['stage'] || ($d['build'] ?? null) !== $m['build']
            || ($d['manifest_sha256'] ?? null) !== PNMS_Manifest::sha($m)) { $r['code'] = 'manifest'; return $r; }
        if (!in_array($d['outcome'] ?? null, ['running', 'complete', 'stopped', 'error'], true) || !is_array($d['cases'] ?? null) || !is_array($d['statements'] ?? null)) { $r['code'] = 'shape'; return $r; }
        foreach ($d['cases'] as $c) {
            if (!is_array($c) || !is_string($c['id'] ?? null) || !preg_match('/^[a-z0-9:._-]{1,96}$/D', $c['id']) || !in_array($c['status'] ?? null, PNMS::CASE_STATUSES, true)
                || !(($c['code'] ?? null) === null || (is_string($c['code']) && strlen($c['code']) <= 160)) || !is_bool($c['gaps'] ?? null) || !is_array($c['data'] ?? null)) { $r['code'] = 'case'; return $r; }
        }
        foreach ($d['statements'] as $s) {
            if (!is_array($s) || count($s) !== 2 || !is_string($s[0]) || !preg_match('/^[0-9a-f]{64}$/D', $s[0]) || !is_bool($s[1])) { $r['code'] = 'statements'; return $r; }
        }
        unset($d['tag']);
        $r['valid'] = true;
        $r['doc'] = $d;
        return $r;
    }

    /** Every worker-submitted statement must be exactly the supervisor's workload statement sequence (digest by digest). */
    public static function crossCheck(array $sup, array $ev)
    {
        if (!$ev['valid']) return ['ok' => false, 'code' => 'no-evidence'];
        if (count($sup['statements']) >= PNS::EVIDENCE_STATEMENTS_MAX) return ['ok' => false, 'code' => 'supervisor-statements-truncated'];
        $s = [];
        foreach ($sup['statements'] as $x) if (($x['worker'] ?? null) === 'workload') $s[] = $x['digest'];
        $returned = [];
        $pending = [];
        foreach ($ev['doc']['statements'] as $e) {
            if ($e[1]) {
                if ($pending) return ['ok' => false, 'code' => 'pending-before-returned'];
                $returned[] = $e[0];
            } else {
                $pending[] = $e[0];
            }
        }
        if (count($pending) > 1) return ['ok' => false, 'code' => 'multiple-pending'];
        if ($s === $returned) return ['ok' => true, 'code' => $pending ? 'last-not-dispatched' : 'exact', 'statements' => count($s)];
        if ($pending && $s === array_merge($returned, $pending)) return ['ok' => true, 'code' => 'last-stopped-after-end', 'statements' => count($s)];
        return ['ok' => false, 'code' => 'digest-sequence-mismatch'];
    }

    /**
     * The preflight probes are accepted only together: the only finding is deadline-cancel; exactly one KILL QUERY,
     * confirmed and effective (< 4 s) on the LAST workload statement (the SLEEP digest); exactly one supervisor-recorded
     * expected error with the permitted digest/errno, observed by the worker; a fresh cleanup worker dropped every
     * owned table and the residue check passed (no recovery item).
     */
    public static function probeOutcome(array $sup, array $ev, $expectedDigest = null)
    {
        $no = ['expected' => false, 'error_probe' => false];
        if (!$ev['valid']) return $no;
        $digest = null;
        $errCase = null;
        foreach ($ev['doc']['cases'] as $c) {
            if ($c['id'] === 'cancel-probe') $digest = $c['data']['digest'] ?? null;
            if ($c['id'] === 'error-propagation') $errCase = $c;
        }
        $work = array_values(array_filter($sup['statements'], function ($s) { return ($s['worker'] ?? null) === 'workload'; }));
        $errs = array_values(array_filter($work, function ($s) { return ($s['ok'] ?? true) === false && ($s['outcome'] ?? null) !== 'censored'; }));
        $errorOk = $errCase !== null && $errCase['status'] === 'pending-supervisor' && ($errCase['data']['observed_errno'] ?? null) === PNMS::ERROR_PROBE_ERRNO
            && is_string($expectedDigest) && ($errCase['data']['digest'] ?? null) === $expectedDigest
            && ($sup['expected_error'] ?? null) === ['permitted' => true, 'consumed' => 1]
            && count($errs) === 1 && ($errs[0]['outcome'] ?? null) === 'expected-error' && ($errs[0]['digest'] ?? null) === $expectedDigest
            && ($errs[0]['errno'] ?? null) === PNMS::ERROR_PROBE_ERRNO;
        $no['error_probe'] = $errorOk;
        if (!is_string($digest)) return $no;
        $codes = array_keys($sup['findings']);
        sort($codes);
        if ($codes !== ['deadline-cancel'] || $sup['kills'] !== 1 || $sup['recovery'] || !$sup['ledger']) return $no;
        foreach ($sup['ledger'] as $e) if ($e['state'] !== 'dropped') return $no;
        $cleanup = false;
        foreach ($sup['captures'] as $cap) if (($cap['worker'] ?? null) === 'cleanup' && ($cap['exit'] ?? null) === 0) $cleanup = true;
        if (!$cleanup) return $no;
        $hit = $work ? $work[count($work) - 1] : null;   // the SLEEP must be the last workload statement
        if (!$hit || ($hit['digest'] ?? null) !== $digest || ($hit['cancel'] ?? null) !== 'confirmed' || !is_int($hit['worker_duration_ns'] ?? null)
            || $hit['worker_duration_ns'] >= PNMS::PROBE_EFFECTIVE_NS) return $no;
        if (!$errorOk) return $no;
        return ['expected' => true, 'error_probe' => true, 'kill_requested_ms' => $hit['kill_requested_ms'] ?? null, 'cancel_confirmed_ms' => $hit['cancel_confirmed_ms'] ?? null,
            'worker_ms' => round($hit['worker_duration_ns'] / 1e6, 3)];
    }

    /** Derived status never edits supervisor/worker evidence; it only combines them (severity 4 > 1 > 3 > 2 > 0). */
    public static function derive(array $m, array $sup, array $ev, array $cross)
    {
        $reasons = [];
        $cases = [];
        $gaps = false;
        $supStatus = (int)$sup['status'];
        $probe = $m['stage'] === 'preflight'
            ? self::probeOutcome($sup, $ev, hash('sha256', PNMS_Lib::errorProbeSql($m['fixture_prefix'] . PNMS::PROBE_TABLE))) : null;
        $expectedProbe = $probe !== null && $probe['expected'];
        $st = 0;
        if ($expectedProbe) $reasons[] = 'expected-probe-cancel';
        elseif ($supStatus !== 0) $reasons[] = 'supervisor-status-' . $supStatus;
        $st = PNS::worse($st, $expectedProbe ? 0 : $supStatus);
        if (!$ev['present']) {
            $reasons[] = 'workload-evidence-missing';
            if (in_array($supStatus, [0, 3], true)) $st = PNS::worse($st, 1);
        } elseif (!$ev['valid']) {
            $reasons[] = 'workload-evidence-invalid-' . $ev['code'];
            $st = PNS::worse($st, 1);
        } else {
            if (!$cross['ok']) { $reasons[] = 'cross-check-' . $cross['code']; $st = PNS::worse($st, 1); }
            $outcome = $ev['doc']['outcome'];
            if ($outcome === 'error' || $outcome === 'running') { $reasons[] = 'worker-' . $outcome; $st = PNS::worse($st, 1); }
            if ($outcome === 'stopped' && !$expectedProbe) { $reasons[] = 'worker-stopped'; $st = PNS::worse($st, 3); }
            foreach ($ev['doc']['cases'] as $c) {
                $s = $c['status'];
                // Only the two preflight probes may be resolved by the supervisor record, and only together.
                if ($s === 'pending-supervisor') $s = ($expectedProbe && in_array($c['id'], ['cancel-probe', 'error-propagation'], true)) ? 'pass' : 'fail';
                $cases[$c['id']] = $s;
                if ($s === 'fail') $st = PNS::worse($st, 1);
                if ($s === 'incomplete' || $s === 'unrun') $st = PNS::worse($st, 3);
                if ($c['gaps']) $gaps = true;
            }
        }
        foreach ($sup['statements'] as $s) {
            if (($s['worker'] ?? null) === 'workload' && in_array($s['phase'] ?? null, ['measure', 'diagnostic'], true) && ($s['outcome'] ?? null) === 'ok' && ($s['metrics'] ?? null) === 'unmeasured') $gaps = true;
        }
        // Defensive: a preflight can never be reported completed without both probes (earlier stops already carry their own status).
        if ($m['stage'] === 'preflight' && $st === 0 && !($probe['expected'] ?? false)) { $reasons[] = 'preflight-probes-not-accepted'; $st = 1; }
        return ['status' => $st, 'label' => self::label($st, $gaps), 'reasons' => $reasons, 'cases' => $cases, 'probe' => $probe, 'gaps' => $gaps];
    }
}
// ==PNMS:remote:END==

/** Local emitter (never shipped). */
final class PNMS_Emitter
{
    public static function main()
    {
        try {
            $bundle = self::emit();
        } catch (PNMS_ConfigError $e) {
            fwrite(STDERR, 'not emitted: ' . $e->getMessage() . "\n");
            exit(2);
        } catch (Throwable $e) {
            fwrite(STDERR, 'not emitted: ' . ($e instanceof RuntimeException || $e instanceof LogicException ? $e->getMessage() : 'internal ' . get_class($e)) . "\n");
            exit(1);
        }
        fwrite(STDOUT, $bundle);
        exit(0);
    }

    private static function env($k)
    {
        $v = getenv($k);
        if (!is_string($v) || $v === '') throw new PNMS_ConfigError('missing ' . $k);
        return $v;
    }

    public static function section($src, $name)
    {
        $b = '// ==PNMS:' . $name . ':BEGIN' . "==\n";
        $e = '// ==PNMS:' . $name . ':END' . "==\n";
        if (substr_count($src, $b) !== 1 || substr_count($src, $e) !== 1) throw new RuntimeException('extract-marker-' . $name);
        $i = strpos($src, $b) + strlen($b);
        $j = strpos($src, $e);
        if ($j <= $i || trim(substr($src, $i, $j - $i)) === '') throw new RuntimeException('extract-empty-' . $name);
        return substr($src, $i, $j - $i);
    }

    /** '<?php' file -> '<?php' snippet: inline HTML rejected, only a trailing close tag is stripped. */
    public static function snippet($src, $label)
    {
        if (!is_string($src) || !preg_match('/^<\?php\s/', $src)) throw new RuntimeException('snippet-open-tag ' . $label);
        $tokens = token_get_all($src);
        $out = '';
        $closed = false;
        foreach ($tokens as $t) {
            $id = is_array($t) ? $t[0] : null;
            $text = is_array($t) ? $t[1] : $t;
            if ($closed) {
                if ($id === T_INLINE_HTML && trim($text) === '') continue;
                throw new RuntimeException('snippet-after-close-tag ' . $label);
            }
            if ($id === T_INLINE_HTML || $id === T_OPEN_TAG_WITH_ECHO) throw new RuntimeException('snippet-inline-html ' . $label);
            if ($id === T_CLOSE_TAG) { $closed = true; continue; }
            if ($id === T_OPEN_TAG && $out !== '') throw new RuntimeException('snippet-reopen ' . $label);
            $out .= $text;
        }
        return rtrim($out) . "\n";
    }

    /** Declared class/interface/trait names (lowercase), anonymous classes and ::class excluded. */
    public static function classes($src)
    {
        $names = [];
        $prev = null;
        $tokens = token_get_all($src);
        $n = count($tokens);
        for ($i = 0; $i < $n; $i++) {
            $t = $tokens[$i];
            if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) continue;
            if (is_array($t) && in_array($t[0], [T_CLASS, T_INTERFACE, T_TRAIT], true) && !(is_array($prev) && in_array($prev[0], [T_DOUBLE_COLON, T_NEW], true))) {
                for ($j = $i + 1; $j < $n; $j++) {
                    if (is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) continue;
                    if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) $names[] = strtolower($tokens[$j][1]);
                    break;
                }
            }
            $prev = $t;
        }
        return $names;
    }

    /** True when a source defines a DB_* constant from a literal (i.e. would carry shop config). */
    public static function definesDbLiteral($src)
    {
        $t = array_values(array_filter(token_get_all($src), function ($x) { return !is_array($x) || !in_array($x[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true); }));
        $n = count($t);
        for ($i = 0; $i + 4 < $n; $i++) {
            if (!is_array($t[$i]) || $t[$i][0] !== T_STRING || strtolower($t[$i][1]) !== 'define' || $t[$i + 1] !== '(') continue;
            if (!is_array($t[$i + 2]) || $t[$i + 2][0] !== T_CONSTANT_ENCAPSED_STRING || stripos(substr($t[$i + 2][1], 1), 'DB_') !== 0) continue;
            if (is_array($t[$i + 4]) && in_array($t[$i + 4][0], [T_CONSTANT_ENCAPSED_STRING, T_LNUMBER], true)) return true;
        }
        return false;
    }

    private static function verify($src, $label, array $mustHave, array $mustNot)
    {
        if (strlen($src) > PNS::SOURCE_MAX) throw new RuntimeException('source-size ' . $label);
        if (!PNMS_Lib::parses($src)) throw new RuntimeException('syntax ' . $label);
        $c = self::classes($src);
        if (count($c) !== count(array_unique($c))) throw new RuntimeException('duplicate-class ' . $label);
        foreach ($mustHave as $k) if (!in_array($k, $c, true)) throw new RuntimeException('missing-class ' . $label . ' ' . $k);
        foreach ($mustNot as $k) if (in_array($k, $c, true)) throw new RuntimeException('forbidden-class ' . $label . ' ' . $k);
        if (self::definesDbLiteral($src)) throw new RuntimeException('config-literal ' . $label);
        return count($c);
    }

    private static function readRegular($root, $rel, $label)
    {
        $path = rtrim($root, '/') . '/' . $rel;
        $real = realpath($path);
        $rootReal = realpath($root);
        if ($real === false || $rootReal === false || strpos($real, rtrim($rootReal, '/') . '/') !== 0 || !is_file($real) || is_link($path)) throw new RuntimeException('source-file ' . $label . ' ' . $rel);
        $s = file_get_contents($real);
        if (!is_string($s) || $s === '') throw new RuntimeException('source-read ' . $label . ' ' . $rel);
        return $s;
    }

    public static function emit()
    {
        if (!class_exists('PNS', false)) require __DIR__ . '/product_navigation_supervisor.php';
        $oc = self::env('OC_DIR');
        $rt = self::env('SEO_PRO_RUNTIME_DIR');
        $label = self::env('SEO_PRO_BUILD_LABEL');
        $casePath = self::env('SEO_PRO_CASE_MANIFEST');
        $outPath = self::env('SEO_PRO_EMIT_MANIFEST_OUT');
        if (!in_array($label, PNMS::BUILDS, true)) throw new RuntimeException('build-label');
        if (!is_file($casePath) || filesize($casePath) > PNS::MANIFEST_MAX) throw new RuntimeException('case-manifest-file');
        $case = json_decode((string)file_get_contents($casePath), true, 32);
        $case = PNMS_Manifest::validate($case);
        if ($case['build'] !== $label) throw new RuntimeException('manifest-build-label-mismatch');
        $caseSha = PNMS_Manifest::sha($case);

        $runtime = [];
        $runtimeSnippets = [];
        foreach (PNMS::RUNTIME_FILES as $rel) {
            $s = self::readRegular($rt, PNMS::RUNTIME_ROOT . $rel, 'runtime');
            $runtime[$rel] = hash('sha256', $s);
            $runtimeSnippets[] = self::snippet($s, $rel);
        }
        $ocHash = [];
        $ocSnippets = [];
        foreach (PNMS::OC_FILES as $rel) {
            $s = self::readRegular($oc, $rel, 'oc');
            $ocHash[$rel] = hash('sha256', $s);
            $ocSnippets[] = self::snippet($s, $rel);
        }
        foreach (['runtime' => $runtime, 'oc' => $ocHash] as $k => $have) {
            foreach ((array)($case['expect'][$k] ?? []) as $rel => $want) if (!hash_equals($want, $have[$rel])) throw new RuntimeException('expected-hash ' . $k . ' ' . $rel);
        }
        $controller = end($runtimeSnippets);
        if (!preg_match('/\bprivate\s+static\s+\$path_groups\s*=/', $controller)) throw new RuntimeException('runtime-path-groups-field');

        $self = file_get_contents(__FILE__);
        $sec = [];
        foreach (['lib', 'stubs', 'worker', 'remote'] as $n) $sec[$n] = self::section($self, $n);
        $support = PNS_Bundle::supportSource();

        $workSnippets = array_merge(["<?php\n" . $sec['lib'], "<?php\n" . $sec['stubs']], $ocSnippets, $runtimeSnippets,
            ["<?php\n" . $sec['worker'], "<?php\nPNMS_WorkerEntry::main();\n"]);
        $work = PNS_Bundle::workerSource($workSnippets);
        $clean = PNS_Bundle::workerSource(["<?php\n" . $sec['lib'], "<?php\nPNMS_Lib::cleanupMain();\n"]);
        $runtimeClasses = ['controllerextensionstartupseopro', 'seo_pro_schema', 'seo_pro_cache', 'url'];
        $checks = [];
        $checks['workload_classes'] = self::verify($work, 'workload', array_merge($runtimeClasses, ['pns_worker', 'pnms_run', 'registry', 'config', 'controller']), ['pnms_remote', 'pnms_emitter', 'pns_supervisor_entry']);
        $checks['cleanup_classes'] = self::verify($clean, 'cleanup', ['pns_worker', 'pnms_lib'], array_merge($runtimeClasses, ['pnms_run', 'pnms_remote', 'registry']));

        $sources = [
            'harness_sha256' => hash('sha256', $self), 'support_sha256' => hash('sha256', $support), 'lib_sha256' => hash('sha256', $sec['lib']),
            'stubs_sha256' => hash('sha256', $sec['stubs']), 'worker_section_sha256' => hash('sha256', $sec['worker']), 'remote_sha256' => hash('sha256', $sec['remote']),
            'workload_sha256' => hash('sha256', $work), 'cleanup_sha256' => hash('sha256', $clean),
        ];
        $embed = ['schema' => PNMS::BUNDLE_SCHEMA, 'case' => $case, 'case_sha256' => $caseSha, 'sources' => $sources, 'runtime' => $runtime, 'oc' => $ocHash,
            'workload' => PNS_Bundle::encodePayload($work), 'cleanup' => PNS_Bundle::encodePayload($clean)];
        $bundle = "<?php\n" . $support . "\n" . $sec['lib'] . "\n" . $sec['remote'] . "\ndefine('PNMS_EMBED', '" . base64_encode(json_encode($embed, JSON_UNESCAPED_SLASHES)) . "');\nPNMS_Remote::main();\n";
        $checks['bundle_classes'] = self::verify($bundle, 'bundle', ['pns_supervisor', 'pnms_remote', 'pnms_manifest'], array_merge($runtimeClasses, ['pnms_run', 'pnms_emitter', 'registry']));

        // Extraction check: what the remote entry will decode is byte-identical to what was verified here.
        if (!preg_match("/\ndefine\('PNMS_EMBED', '([A-Za-z0-9+\/=]+)'\);\nPNMS_Remote::main\(\);\n$/", $bundle, $mm)) throw new RuntimeException('extract-embed');
        $back = json_decode((string)base64_decode($mm[1], true), true, 64);
        if (!is_array($back) || PNS_Bundle::decodePayload($back['workload']) !== $work || PNS_Bundle::decodePayload($back['cleanup']) !== $clean
            || PNMS_Manifest::sha(PNMS_Manifest::validate($back['case'])) !== $caseSha) throw new RuntimeException('extract-roundtrip');
        $checks['syntax'] = 'token_get_all TOKEN_PARSE on workload, cleanup and bundle (local PHP ' . PHP_VERSION . '; remote re-checks)';
        $checks['extraction'] = 'markers unique; payloads decode byte-identical; case hash re-validated';
        $checks['no_config'] = 'no DB_* literal define; store config never read by the emitter';

        $manifest = ['schema' => PNMS::BUNDLE_SCHEMA, 'build' => $label, 'case' => $case, 'case_sha256' => $caseSha, 'sources' => $sources + ['bundle_sha256' => hash('sha256', $bundle)],
            'runtime' => $runtime, 'oc' => $ocHash, 'checks' => $checks, 'php_local' => PHP_VERSION,
            'entrypoint' => ['remote' => 'php reading this bundle on stdin', 'env' => ['SEO_PRO_TEST_STORE_CONFIG'], 'stdout' => PNMS::RESULT_SCHEMA,
                'exit' => '0 ok | 1 assertion/protocol | 2 preflight unsupported | 3 deadline/budget/incomplete | 4 unresolved cleanup/cancellation']];
        PNMS_Lib::writeExclusive($outPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        return $bundle;
    }
}

if (in_array('--emit-supervised', $argv ?? [], true)) PNMS_Emitter::main();
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
    if (getenv('SEO_PRO_STAND_SCENARIO') === 'focused') { standFocusedProducts($db); return; }
    if ((getenv('SEO_PRO_STAND_SCENARIO') ?: '') !== '') throw new InvalidArgumentException('unknown stand scenario selector');
    $base='https://test.mozap.ru'; $checks=0;
    $assert=function($ok,$name)use(&$checks){$checks++;if(!$ok)throw new RuntimeException('scenario assertion '.$name);};
    $request=function($uri,$status,$location=null)use($base,$assert){$r=standHttpRequest($base.$uri);$okLocation=$location===null?($status===301?$r['location']!=='':$r['location']===''):$r['location']===$location;$assert($r['status']===$status&&$okLocation,$uri);return $r;};
    $id=1000001; $aliases=$db->query("SELECT * FROM ".DB_PREFIX."seo_url WHERE store_id=0 AND language_id=1 AND `query`='product_id=$id' ORDER BY seo_url_id")->rows;
    if (count($aliases)!==1) throw new RuntimeException('scenario requires one product alias');
    $alias=$aliases[0]; $aliasId=(int)$alias['seo_url_id'];
    $original=$request('/index.php?route=product/product&product_id='.$id,301)['location'];
    $token='load-nav-'.bin2hex(random_bytes(6));
    $originalMain=$db->query('SELECT category_id FROM '.DB_PREFIX.'product_to_category WHERE product_id='.$id.' AND main_category=1')->row;
    if (!$originalMain) throw new RuntimeException('visible product list fixture requires a marked category');
    $assertList=function($target)use($base,$assert,$originalMain){
        $url=$base.'/index.php?route=product/category&path='.(int)$originalMain['category_id'].'&sort=pd.name&order=ASC';$result=null;
        for($hop=0;$hop<4;$hop++){$result=standHttpRequest($url);if($result['location']==='')break;if(strpos($result['location'],$base.'/')!==0)throw new RuntimeException('unexpected list redirect host');$url=$result['location'];}
        $assert($result['status']===200&&$result['location']==='','actual list reaches a page');
        preg_match_all('~<a\b[^>]*href=["\']([^"\']+)["\']~i',$result['body'],$matches);
        $hrefs=array_map(function($href){return html_entity_decode($href,ENT_QUOTES,'UTF-8');},$matches[1]);
        $assert(in_array($target,$hrefs,true),'actual list renders current product href after SQL mutation');
        echo "PASS actual list current product href after mutation\n";
    };

    $assertProductPage=function($target)use($request,$assert,$base){
        $r=$request(standUri($target),200);[$canonical,$h1]=standDocument($r['body'],$base);
        $assert($canonical===standUri($target),'actual product canonical follows changed address');
        $assert($h1!==''&&$h1!=='-','actual changed product page retains H1');
        echo "PASS actual product canonical and H1 after mutation\n";
    };
    $state=new StandScenario($db,$credentials,'keyword',['seo_url'=>'seo_url_id='.$aliasId]);
    try {
        // Warm the actual map, then UPDATE without changing its id; no cache clear between requests.
        standHttpRequest($original);
        $db->query("UPDATE ".DB_PREFIX."seo_url SET keyword='".$db->escape($token)."' WHERE seo_url_id=$aliasId");
        $target=str_replace($alias['keyword'],$token,$original);
        $request('/index.php?route=product/product&product_id='.$id,301,$target);
        $assertList($target);
        $request(standUri($original),404); $assertProductPage($target);
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
            $assertList($target);
            $request(standUri($original),301,$target); $assertProductPage($target);
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
            $assertList($target);
            $request('/index.php?route=product/product&product_id='.$id,301,$target);$assertProductPage($target);
        }finally{$state->restore();}
        $categoryRow=$db->query('SELECT * FROM '.DB_PREFIX.'category WHERE category_id='.$cat)->row;
        if ($db->query('SELECT category_id FROM '.DB_PREFIX.'category_path WHERE path_id='.$cat.' AND category_id<>'.$cat.' LIMIT 1')->num_rows) throw new RuntimeException('hierarchy fixture requires a leaf main category');
        $parents=$db->query('SELECT c.category_id FROM '.DB_PREFIX.'category c WHERE c.status=1 AND c.category_id<>'.$cat.' AND NOT EXISTS (SELECT 1 FROM '.DB_PREFIX.'category_path cp WHERE cp.category_id=c.category_id AND cp.path_id='.$cat.') ORDER BY c.category_id LIMIT 30')->rows;
        $parentPath=null; $parentId=null; $lookup=addressController($db);
        foreach ($parents as $row) {
            $data=addressCall($lookup,'readProductCategoryData',(int)$row['category_id']);
            if ($data['valid'] && !in_array(null,$data['keywords'],true) && !in_array('',$data['keywords'],true)) { $parentPath=explode('_',$data['path']); $parentId=(int)$row['category_id']; break; }
        }
        if ($parentPath===null) throw new RuntimeException('no available parent for real hierarchy move');

        $state=new StandScenario($db,$credentials,'hierarchy',['category'=>'category_id='.$cat,'category_path'=>'category_id='.$cat]);
        try {
            standHttpRequest($original);
            $db->query('UPDATE '.DB_PREFIX.'category SET parent_id='.$parentId.' WHERE category_id='.$cat);
            $db->query('DELETE FROM '.DB_PREFIX.'category_path WHERE category_id='.$cat);
            foreach (array_merge($parentPath,[$cat]) as $level=>$pathId) $state->insert('category_path',['category_id'=>$cat,'path_id'=>(int)$pathId,'level'=>$level]);
            $target=str_replace('&amp;','&',addressHref(addressController($db),$id));
            $assert($target!==$original,'real hierarchy move changes outgoing Url');
            $assertList($target);
            $request('/index.php?route=product/product&product_id='.$id,301,$target);$assertProductPage($target);
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

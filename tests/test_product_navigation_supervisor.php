<?php
// Offline tests of tests/product_navigation_supervisor.php: real disposable PHP_BINARY worker processes,
// file-backed fake control/server state and an injected fake clock. No MySQL, no SSH, no network.
// usage: php tests/test_product_navigation_supervisor.php
require __DIR__ . '/product_navigation_supervisor.php';

$GLOBALS['checks'] = 0;
$GLOBALS['failed'] = 0;
function check($ok, $label)
{
    $GLOBALS['checks']++;
    if (!$ok) {
        $GLOBALS['failed']++;
        echo "FAIL $label\n";
    }
}

function rmtree($path)
{
    if (is_link($path) || is_file($path)) { @unlink($path); return; }
    if (!is_dir($path)) return;
    foreach (scandir($path) as $f) if ($f !== '.' && $f !== '..') rmtree($path . '/' . $f);
    @rmdir($path);
}

// Private scratch, removed by this process (owner) at exit.
$SCRATCH = rtrim(realpath(sys_get_temp_dir()), '/') . '/pns-test-' . bin2hex(random_bytes(6));
PNS_Files::privateDir($SCRATCH);
register_shutdown_function(function () use ($SCRATCH) { rmtree($SCRATCH); });

final class TestClock implements PNS_Clock
{
    public $t = 1000.0;
    public $step;
    public function __construct($step) { $this->step = $step; }
    public function now() { return $this->t; }
    public function idle() { $this->t += $this->step; }
    public function pollInterval() { return 0.01; }
    public function advance($s) { $this->t += $s; }
}

/** Fake control over the fake worker driver's state files in $dir. */
final class FakeControl implements PNS_Control
{
    public $dir;
    public $kills = [];
    public $calls = [];
    public $failPreflight = false;
    public $identityMismatch = false;
    public $killIgnored = false;
    public $observeTimeout = false;
    public $hideTables = false;
    public $existing = [];
    public $sql = 0;
    /** SQL statements one preflight() runs (the real control runs several). */
    public $preflightCost = 1;
    /** With a TestClock: fake seconds each statementState() probe takes. */
    public $clock = null;
    public $stateDelay = 0.0;

    public function __construct($dir, array $opts)
    {
        $this->dir = $dir;
        foreach ($opts as $k => $v) $this->$k = $v;
    }

    private function call($m) { $this->calls[$m] = ($this->calls[$m] ?? 0) + 1; $this->sql++; }

    public function statementCount() { return $this->sql; }

    private function ident($connId)
    {
        $f = $this->dir . '/ident-' . (int)$connId . '.json';
        return is_file($f) ? json_decode(file_get_contents($f), true) : null;
    }

    public function preflight()
    {
        $this->call('preflight');
        $this->sql += $this->preflightCost - 1;
        if ($this->failPreflight) throw new PNS_ControlUnsupported('fake-unsupported');
        return ['family' => 'fake', 'select_limit' => 'fake', 'secret_like' => ['not scalar']];
    }

    public function verifyIdentity($connId, $marker)
    {
        $this->call('verifyIdentity');
        $j = $this->ident($connId);
        if ($this->identityMismatch || !$j || $j['marker'] !== $marker) return null;
        return (int)$j['thread'];
    }

    public function statementState($connId, $threadId)
    {
        $this->call('statementState');
        if ($this->clock !== null && $this->stateDelay > 0) $this->clock->advance($this->stateDelay);   // a slow synchronous probe
        $j = $this->ident($connId);
        if (!$j || (int)$j['thread'] !== (int)$threadId) return 'gone';
        $s = @file_get_contents($this->dir . '/state-' . (int)$connId);
        return $s === 'running' ? 'running' : 'idle';
    }

    public function killQuery($connId)
    {
        $this->call('killQuery');
        $this->kills[] = (int)$connId;
        if (!$this->killIgnored) touch($this->dir . '/kill-' . (int)$connId);
    }

    public function tableExists($table)
    {
        $this->call('tableExists');
        if ($this->hideTables) return false;
        return in_array($table, $this->existing, true) || is_file($this->dir . '/tables/' . $table);
    }

    public function sessionLockWait($threadId)
    {
        $this->call('sessionLockWait');
        foreach (glob($this->dir . '/ident-*.json') as $f) {
            $j = json_decode(file_get_contents($f), true);
            if ((int)$j['thread'] === (int)$threadId) return $j['lock'];
        }
        return null;
    }

    public function observeStatement($threadId, $tag)
    {
        $this->call('observeStatement');
        if ($this->observeTimeout) throw new PNS_ControlTimeout('fake-timeout');
        $f = $this->dir . '/obs-' . (int)$threadId . '-' . str_replace(':', '-', $tag);
        return is_file($f) ? ['rows_examined' => (int)file_get_contents($f), 'rows_sent' => (int)file_get_contents($f)] : null;
    }
}

// Worker payload parts. They are only ever streamed to child processes, never evaluated here.
$FAKE_DRIVER = <<<'PHP'
<?php
final class PNS_TestRuntimeMarker {}
final class FakeDriver implements PNS_WorkerDriver
{
    private $dir; private $id = 0; private $thread = 0; private $used = false;
    public function __construct($dir) { $this->dir = $dir; }
    private function ident($thread, $marker, $lock)
    {
        file_put_contents($this->dir . '/ident-' . $this->id . '.json', json_encode(['thread' => $thread, 'marker' => $marker, 'lock' => $lock]));
    }
    private function log($s) { file_put_contents($this->dir . '/executed.log', $s . "\n", FILE_APPEND); }
    private function blockUntilKill()
    {
        $kill = $this->dir . '/kill-' . $this->id;
        $end = microtime(true) + 20;
        while (!is_file($kill) && microtime(true) < $end) usleep(5000);
        $killed = is_file($kill);
        @unlink($kill);
        return $killed;
    }
    public function open($role, $connect, $read, $marker)
    {
        if ($this->used) throw new PNS_WorkerFailure('driver-reuse');
        $this->used = true;
        $this->id = random_int(1000, 999999);
        $this->thread = random_int(1, 1 << 30);
        $lock = $role === 'cleanup' ? 1 : null;
        $this->ident($this->thread, $marker, $lock);
        file_put_contents($this->dir . '/state-' . $this->id, 'idle');
        $this->log('OPEN ' . $role . ' ' . $this->id);
        return ['conn_id' => $this->id, 'connect_timeout' => $connect, 'read_timeout' => $read, 'reconnect' => false, 'lock_wait_timeout' => $lock, 'session_sql' => $role === 'cleanup' ? 5 : 3];
    }
    public function execute($sql)
    {
        $state = $this->dir . '/state-' . $this->id;
        file_put_contents($state, 'running');
        $this->log('SQL ' . $sql);
        $r = ['errno' => 0, 'rows' => [], 'affected' => 0];
        if (strpos($sql, 'CRASHGONE') !== false) {
            if (preg_match('/CREATE TABLE `([a-z0-9_]+)`/', $sql, $m)) touch($this->dir . '/tables/' . $m[1]);
            @unlink($this->dir . '/ident-' . $this->id . '.json');
            @unlink($state);
            exit(9);
        }
        if (strpos($sql, 'CRASHHANG') !== false) exit(9);
        if (strpos($sql, 'STALE') !== false) {
            $this->ident($this->thread + 1, 'another-session', null);   // numeric id reused by another thread
            $this->blockUntilKill();
            $r['errno'] = 1317;
        } elseif (strpos($sql, 'BLOCK') !== false || (strpos($sql, 'DROP TABLE') !== false && is_file($this->dir . '/block-drop'))) {
            $r['errno'] = $this->blockUntilKill() ? 1317 : 2013;
        } elseif (strpos($sql, 'EXPECTERR') !== false) {
            $r['errno'] = 1054;   // deterministic server-side error of a probe on an owned table
        } elseif (preg_match('/CREATE TABLE `([a-z0-9_]+)`/', $sql, $m)) {
            $f = $this->dir . '/tables/' . $m[1];
            if (file_exists($f)) $r['errno'] = 1050; else touch($f);
        } elseif (preg_match('/DROP TABLE `([a-z0-9_]+)`/', $sql, $m)) {
            $f = $this->dir . '/tables/' . $m[1];
            if (!file_exists($f)) $r['errno'] = 1051; else unlink($f);
        } elseif (strpos($sql, 'INSERT') !== false) {
            $r['affected'] = 2;
        } else {
            $r['rows'] = [['n' => 1]];
        }
        if ($r['errno'] === 0 && preg_match('#^/\*pns:(\d+):(\d+)\*/#', $sql, $m)) {
            file_put_contents($this->dir . '/obs-' . $this->thread . '-' . $m[1] . '-' . $m[2], (string)(count($r['rows']) + 4));
        }
        file_put_contents($state, 'idle');
        return $r;
    }
    public function close()
    {
        @unlink($this->dir . '/ident-' . $this->id . '.json');
        @unlink($this->dir . '/state-' . $this->id);
        $this->log('CLOSE ' . $this->id);
    }
}
PHP;

$WORKLOAD_MAIN = <<<'PHP'
<?php
PNS_Worker::main(function (PNS_Worker $w) {
    $m = $w->manifest();
    $dir = $m['srv'];
    $t = $m['table'];
    $s = $m['scenario'];
    $new = function () use ($dir) { return new FakeDriver($dir); };
    $raw = function ($bytes) { $f = fopen('php://fd/3', 'wb'); fwrite($f, $bytes); fclose($f); };
    $hold = function () { usleep(4000000); };
    $begin = function ($conn) { return ['conn' => $conn, 'kind' => 'select', 'digest' => hash('sha256', 'x'), 'table' => null, 'ddl_head' => null]; };
    $fixture = function () use ($w, $new, $t) {
        $w->connect('setup', $new());
        $w->registerCreate($t);
        $w->statement('setup', 'create', "CREATE TABLE `$t` (id INT NOT NULL) ENGINE=MyISAM", $t);
        $w->statement('setup', 'write', "INSERT INTO `$t` (id) VALUES (1),(2)", $t);
        $w->close();
    };
    switch ($s) {
        case 'exit-before-hello':
            exit(7);
        case 'noise':
            fwrite(STDOUT, str_repeat('o', 3 << 20));
            fwrite(STDERR, str_repeat("CANARY-STDERR-SECRET\n", 100000));
            // no break: then the normal round trip
        case 'roundtrip':
        case 'gating-delay':
        case 'observer-timeout':
        case 'cleanup-block':
        case 'budget-other':
        case 'preflight-cost':
        case 'cleanup-window':
        case 'residue-late':
            $fixture();
            $w->connect('measure', $new());
            for ($i = 0; $i < 3; $i++) $w->statement('measure', 'select', "SELECT id FROM `$t` WHERE id = $i");
            $w->statement('diagnostic', 'explain', "EXPLAIN SELECT id FROM `$t`");
            $w->close();
            return;
        case 'expected-error':
        case 'unexpected-error':
        case 'expected-error-errno':
        case 'expected-error-twice':
        case 'expected-error-unpermitted':
            $fixture();
            $w->connect('measure', $new());
            $e1 = $w->statement('measure', 'select', "SELECT nope FROM `$t` /*EXPECTERR*/");
            if ($e1['errno'] !== 1054) throw new RuntimeException('probe errno not propagated');
            $w->statement('measure', 'select', $s === 'expected-error-twice' ? "SELECT nope FROM `$t` /*EXPECTERR*/" : "SELECT id FROM `$t`");
            $w->close();
            return;
        case 'budget':
            $w->connect('measure', $new());
            for ($i = 0; $i < 3; $i++) $w->statement('measure', 'select', "SELECT $i");
            return;
        case 'shape-shop-write':
            $w->connect('setup', $new());
            $w->registerCreate($t);
            $w->statement('setup', 'create', "CREATE TABLE `$t` (id INT NOT NULL)", $t);
            $w->statement('setup', 'write', "INSERT INTO `oc_product` (product_id) VALUES (1)", $t);   // owned name, shop target
            return;
        case 'blocked':
        case 'unconfirmed':
        case 'late-confirm':
        case 'short-window':
            $fixture();
            $w->connect('measure', $new());
            $w->statement('measure', 'select', 'SELECT 1 /*BLOCK*/');
            $w->statement('measure', 'select', 'SELECT 2');
            return;
        case 'race':
            $w->connect('measure', $new());
            $w->statement('measure', 'select', 'SELECT 1 /*RACE*/');
            $w->statement('measure', 'select', 'SELECT 2');
            return;
        case 'stale-cancel':
            $w->connect('measure', $new());
            $w->statement('measure', 'select', 'SELECT 1 /*STALE*/');
            return;
        case 'crash-hang':
            $w->connect('measure', $new());
            $w->statement('measure', 'select', 'SELECT 1 /*CRASHHANG*/');
            return;
        case 'hello-mismatch':
            $w->connect('setup', $new());
            $w->statement('setup', 'setup', 'SET @x = 1');
            return;
        case 'gating':
            $w->connect('measure', $new());
            $b = $w->event('BEGIN', 'measure', $begin(1));
            $w->awaitReply(['GO'], $b, 5.0);
            // END and the next BEGIN in one write: the BEGIN does not wait for ACK
            $raw($w->frame('END', 'measure', ['conn' => 1, 're' => $b, 'ok' => true, 'errno' => 0, 'duration_ns' => 1, 'rows' => 0, 'affected' => 0])
                . $w->frame('BEGIN', 'measure', $begin(1)));
            $hold();
            return;
        case 'bad-token':
            $w->connect('measure', $new());
            $f = json_decode($w->frame('BEGIN', 'measure', $begin(1)), true);
            $f['token'] = str_repeat('0', 32);
            $raw(json_encode($f) . "\n");
            $hold();
            return;
        case 'old-conn':
            $w->connect('setup', $new());
            $w->close();
            $w->connect('measure', $new());
            $w->event('BEGIN', 'measure', $begin(1));   // identity of connection 1 is dead
            $hold();
            return;
        case 'oversized':
            $w->connect('measure', $new());
            $raw(str_repeat('x', 17000) . "\n");
            $hold();
            return;
        case 'malformed':
            $w->connect('measure', $new());
            $raw("{not json\n");
            $hold();
            return;
        case 'broken':
            $w->connect('measure', $new());
            $raw('{"v":1,"nonce":');
            exit(0);
        case 'crash-before-ack':
            $w->connect('setup', $new());
            $w->event('REGISTER_CREATE', 'setup', ['conn' => 1, 'table' => $t]);
            exit(5);
        case 'crash-after-ack':
            $w->connect('setup', $new());
            $w->registerCreate($t);
            $w->statement('setup', 'create', "CREATE TABLE `$t` (id INT NOT NULL)", $t);
            exit(5);
        case 'crash-during-create':
            $w->connect('setup', $new());
            $w->registerCreate($t);
            $w->statement('setup', 'create', "CREATE TABLE `$t` (id INT NOT NULL) /*CRASHGONE*/", $t);
            return;
        case 'collision':
        case 'collision-create':
            $w->connect('setup', $new());
            $w->registerCreate($t);
            $w->statement('setup', 'create', "CREATE TABLE `$t` (id INT NOT NULL)", $t);
            return;
        case 'duplicate':
            $w->connect('setup', $new());
            $w->registerCreate($t);
            $w->statement('setup', 'create', "CREATE TABLE `$t` (id INT NOT NULL)", $t);
            $w->registerCreate($t);
            return;
    }
    throw new RuntimeException('unknown scenario');
});
PHP;

$CLEANUP_MAIN = <<<'PHP'
<?php
PNS_Worker::cleanupMain(function ($role) { return new FakeDriver(PNS_Worker::boot()->manifest()['srv']); });
PHP;

$WORKLOAD_SOURCE = PNS_Bundle::workerSource([$FAKE_DRIVER, $WORKLOAD_MAIN]);
$CLEANUP_SOURCE = PNS_Bundle::workerSource([$FAKE_DRIVER, $CLEANUP_MAIN]);
$RUN_DIR = $SCRATCH . '/run';

/** Runs one real supervised chunk; returns [result, control, srv dir, spec]. */
function scenario($name, array $opts = [])
{
    global $SCRATCH, $RUN_DIR, $WORKLOAD_SOURCE, $CLEANUP_SOURCE;
    $srv = $SCRATCH . '/srv-' . $name;
    PNS_Files::privateDir($srv);
    PNS_Files::privateDir($srv . '/tables');
    $prefix = 'pnsxt_' . substr(md5($name), 0, 8) . '_';
    $table = $prefix . 'seo_url';
    foreach ($opts['pre_tables'] ?? [] as $t) touch($srv . '/tables/' . ($t === '@' ? $table : $t));
    foreach ($opts['files'] ?? [] as $f) touch($srv . '/' . $f);
    $control = new FakeControl($srv, array_diff_key($opts['control'] ?? [], ['existing' => 1]));
    if (isset($opts['control']['existing'])) $control->existing = [$table];
    $clock = $opts['clock'] ?? new PNS_MonotonicClock();
    $sup = new PNS_Supervisor($control, $clock, $opts['hooks'] ?? []);
    $spec = [
        'run_dir' => $RUN_DIR, 'chunk_id' => $opts['chunk'] ?? $name, 'build' => 'test', 'db_prefix' => $prefix,
        'shop_prefix' => 'oc_', 'fixture_prefix' => $prefix, 'workload_source' => $WORKLOAD_SOURCE, 'cleanup_source' => $CLEANUP_SOURCE,
        'manifest' => ['scenario' => $name, 'srv' => $srv, 'table' => $table, 'canary' => 'CANARY-MANIFEST-VALUE'],
        'limits' => $opts['limits'] ?? [],
    ];
    if (array_key_exists('expect_error', $opts)) {
        $x = $opts['expect_error'];
        if (is_array($x) && ($x['digest'] ?? null) === '@') $x['digest'] = hash('sha256', "SELECT nope FROM `$table` /*EXPECTERR*/");
        $spec['expect_error'] = $x;
    }
    $r = $sup->run($spec);
    return [$r, $control, $srv, $spec, $table];
}

function has(array $r, $code) { return isset($r['findings'][$code]); }
function codes(array $r) { return implode(',', array_keys($r['findings'])); }
function executed($srv) { return is_file($srv . '/executed.log') ? file($srv . '/executed.log', FILE_IGNORE_NEW_LINES) : []; }
function sqlLines($srv) { return array_values(array_filter(executed($srv), function ($l) { return strpos($l, 'SQL ') === 0; })); }
function stateOf(array $r, $t) { return $r['ledger'][$t]['state'] ?? null; }
function mode($path) { clearstatcache(); return fileperms($path) & 0777; }

// --- status severity 4 > 1 > 3 > 2 > 0
check(PNS::worse(0, 2) === 2 && PNS::worse(2, 3) === 3 && PNS::worse(3, 1) === 1 && PNS::worse(1, 4) === 4, 'severity order upward');
check(PNS::worse(4, 1) === 4 && PNS::worse(1, 3) === 1 && PNS::worse(3, 2) === 3 && PNS::worse(2, 0) === 2, 'severity order kept');

// --- ledger: private atomic file, transitions, restricted deletion
$ld = $SCRATCH . '/ledger-unit';
PNS_Files::privateDir($ld);
check(mode($ld) === 0700, 'private dir is 0700');
$lclock = new PNS_MonotonicClock();
$ledger = PNS_Ledger::create($ld . '/ledger.json', 'pnsxt_unit_', 'oc_', $lclock);
check(is_file($ld . '/ledger.json') && mode($ld . '/ledger.json') === 0600, 'ledger exists before any CREATE, mode 0600');
$old = fopen($ld . '/ledger.json', 'rb');
$ledger->register('pnsxt_unit_a');
rewind($old);
$oldDoc = json_decode(stream_get_contents($old), true);
fclose($old);
$newDoc = json_decode(file_get_contents($ld . '/ledger.json'), true);
check(is_array($oldDoc) && $oldDoc['entries'] === [] && $newDoc['entries']['pnsxt_unit_a']['state'] === 'intended', 'ledger replaced by rename: an open reader keeps the complete previous version');
check(mode($ld . '/ledger.json') === 0600 && glob($ld . '/.*.tmp') === [], 'no temp file remains, mode kept');
$threw = function (callable $f) { try { $f(); return false; } catch (PNS_LedgerError $e) { return true; } };
check($threw(function () use ($ledger) { $ledger->register('oc_product'); }), 'shop table name rejected');
check($threw(function () use ($ledger) { $ledger->register('pnsxt_unit_'); }), 'bare prefix rejected');
check($threw(function () use ($ledger) { $ledger->register('pnsxt_unit_A'); }), 'invalid characters rejected');
check($threw(function () use ($ledger) { $ledger->register('pnsxt_unit_a'); }), 'duplicate registration rejected');
check($threw(function () use ($ledger) { $ledger->transition('pnsxt_unit_a', 'dropped'); }), 'intended cannot jump to dropped');
check($ledger->droppable() === [], 'an intention alone authorizes no deletion');
$ledger->transition('pnsxt_unit_a', 'confirmed-created');
check($ledger->droppable() === ['pnsxt_unit_a'], 'confirmed-created is droppable');
$ledger->transition('pnsxt_unit_a', 'tainted');
check($ledger->droppable() === ['pnsxt_unit_a'] && $ledger->recoveryItems() === ['pnsxt_unit_a' => 'tainted'], 'tainted droppable and a recovery item');
$ledger->transition('pnsxt_unit_a', 'dropped');
check($ledger->droppable() === [] && $ledger->recoveryItems() === [], 'dropped leaves nothing');
$ledger->register('pnsxt_unit_b');
$ledger->transition('pnsxt_unit_b', 'ownership-uncertain');
check($ledger->droppable() === [] && $ledger->recoveryItems() === ['pnsxt_unit_b' => 'ownership-uncertain'], 'ownership-uncertain never droppable');
$ledger->register('pnsxt_unit_c');
$ledger->finalizeUnsubmitted();
check($ledger->state('pnsxt_unit_c') === 'not-created', 'unsubmitted intention finalized as not-created');
$ledger->register('pnsxt_unit_d');
$ledger->markSubmitted('pnsxt_unit_d');
$ledger->finalizeUnsubmitted();
check($ledger->state('pnsxt_unit_d') === 'intended' && isset($ledger->recoveryItems()['pnsxt_unit_d']), 'submitted intention stays a recovery item');
$before = hash_file('sha256', $ld . '/ledger.json');
try { PNS_Ledger::create($ld . '/ledger.json', 'pnsxt_unit_', 'oc_', $lclock); $ex = false; } catch (PNS_LedgerExists $e) { $ex = true; }
check($ex && hash_file('sha256', $ld . '/ledger.json') === $before, 'existing ledger refused and untouched');
check(!PNS_Ledger::validPrefix('oc_pns_', 'oc_') && !PNS_Ledger::validPrefix('o_', 'oc_') && PNS_Ledger::validPrefix('pnsx_1_', 'oc_'), 'fixture prefix must be distinct from the shop prefix');
mkdir($SCRATCH . '/open-dir', 0755);
chmod($SCRATCH . '/open-dir', 0755);
try { PNS_Files::privateDir($SCRATCH . '/open-dir'); $ok = false; } catch (RuntimeException $e) { $ok = true; }
check($ok, 'group/other-accessible dir rejected');
symlink($ld, $SCRATCH . '/link-dir');
try { PNS_Files::privateDir($SCRATCH . '/link-dir'); $ok = false; } catch (RuntimeException $e) { $ok = true; }
check($ok, 'symlinked dir rejected');

// --- bundle payload and source separation
$enc = PNS_Bundle::encodePayload($WORKLOAD_SOURCE);
check(PNS_Bundle::decodePayload($enc) === $WORKLOAD_SOURCE, 'payload round trip');
$tamper = json_decode(base64_decode($enc), true);
$tamper['source'] = base64_encode($WORKLOAD_SOURCE . ' ');
try { PNS_Bundle::decodePayload(base64_encode(json_encode($tamper))); $ok = false; } catch (InvalidArgumentException $e) { $ok = true; }
check($ok, 'tampered payload rejected by its hash');
$support = file_get_contents(__DIR__ . '/product_navigation_supervisor.php');
$bad = [];
$strings = [];
foreach (token_get_all($support) as $tok) {
    if (!is_array($tok)) continue;
    if (in_array($tok[0], [T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE, T_EVAL], true)) $bad[] = $tok[1];
    if ($tok[0] === T_CONSTANT_ENCAPSED_STRING) $strings[] = $tok[1];
}
check($bad === [], 'support source has no include/require/eval');
check(strpos($support, 'seo_pro_oc3.ocmod') === false && strpos($support, 'ControllerExtensionStartupSeoPro') === false, 'support source names no runtime module');
check(!preg_grep('/KILL\s+CONNECTION/i', $strings) && preg_grep('/^.KILL QUERY .$/', $strings), 'only KILL QUERY is ever sent');
$open = (new ReflectionMethod('PNS_MysqliWorkerDriver', 'open'));
$body = implode('', array_slice(file(__DIR__ . '/product_navigation_supervisor.php'), $open->getStartLine() - 1, $open->getEndLine() - $open->getStartLine() + 1));
check(strpos($body, 'MYSQLI_OPT_CONNECT_TIMEOUT') < strpos($body, 'real_connect') && strpos($body, 'MYSQLI_OPT_READ_TIMEOUT') < strpos($body, 'real_connect'), 'static: worker connect/read options are set before connect');
$copen = (new ReflectionMethod('PNS_MysqlControl', 'preflight'));
$body = implode('', array_slice(file(__DIR__ . '/product_navigation_supervisor.php'), $copen->getStartLine() - 1, $copen->getEndLine() - $copen->getStartLine() + 1));
check(strpos($body, 'MYSQLI_OPT_READ_TIMEOUT') < strpos($body, 'real_connect'), 'static: control read option is set before connect');

// --- spec validation: no widening, no shop-like fixture prefix
list($r) = scenario('widen', ['limits' => ['max_select' => 5000]]);
check($r['status'] === 1 && has($r, 'invalid-spec:limits') && $r['evidence_dir'] === null, 'widened limit rejected before any process');
list($r) = scenario('widen-time', ['limits' => ['chunk_seconds' => 181]]);
check($r['status'] === 1 && has($r, 'invalid-spec:limits'), 'longer chunk window rejected');

// --- valid round trip: setup, measure and a fresh cleanup worker, three distinct connections
list($r, $c, $srv, $spec, $t) = scenario('roundtrip');
check($r['status'] === 0 && $r['findings'] === [], 'round trip status 0 (' . codes($r) . ')');
check(stateOf($r, $t) === 'dropped' && array_column($r['ledger'][$t]['history'] ?? [], 0) === ['intended', 'confirmed-created', 'dropped'], 'ledger intended -> confirmed-created -> dropped');
check(!file_exists($srv . '/tables/' . $t) && $r['recovery'] === [], 'no residue');
check($r['counters']['select'] === 3 && $r['counters']['cleanup'] === 1 && $r['kills'] === 0, 'counters: 3 measured SELECTs, 1 cleanup DROP, no KILL');
$opens = array_values(preg_grep('/^OPEN /', executed($srv)));
check(count($opens) === 3 && strpos($opens[0], 'OPEN setup') === 0 && strpos($opens[1], 'OPEN measure') === 0 && strpos($opens[2], 'OPEN cleanup') === 0
    && count(array_unique(array_map(function ($l) { return explode(' ', $l)[2]; }, $opens))) === 3, 'distinct setup/measure/cleanup connections');
check(count($r['captures']) === 2 && $r['captures'][0]['worker'] === 'workload' && $r['captures'][1]['worker'] === 'cleanup' && $r['captures'][1]['exit'] === 0, 'cleanup ran in a separate fresh worker process');
$sel = array_values(array_filter($r['statements'], function ($s) { return $s['kind'] === 'select'; }));
check(count($sel) === 3 && $sel[0]['outcome'] === 'ok' && $sel[0]['metrics'] === ['rows_examined' => 5, 'rows_sent' => 5] && is_int($sel[0]['worker_duration_ns']) && $sel[0]['supervised_ms'] >= 0, 'per-statement attributable metrics and separate worker/supervised timings');
$ev = $r['evidence_dir'];
check($ev !== null && mode($ev) === 0700 && mode($ev . '/ledger.json') === 0600 && mode($ev . '/metrics.json') === 0600 && mode($ev . '/events.jsonl') === 0600, 'evidence files 0600 in a 0700 dir');
$metrics = json_decode(file_get_contents($ev . '/metrics.json'), true);
check($metrics['status'] === 0 && $metrics['sources']['workload_sha256'] === hash('sha256', $WORKLOAD_SOURCE), 'metrics carry the status and source hash');
$all = file_get_contents($ev . '/metrics.json') . file_get_contents($ev . '/events.jsonl') . file_get_contents($ev . '/ledger.json');
check(strpos($all, 'CANARY') === false && strpos($all, $srv) === false && strpos($all, 'not scalar') === false, 'no manifest/path/non-scalar caps in evidence');
$lines = array_map('json_decode', file($ev . '/events.jsonl', FILE_IGNORE_NEW_LINES));
check(count(array_filter($lines, 'is_object')) === count($lines) && count($lines) > 10, 'events.jsonl is valid bounded JSON lines');

// --- conforming worker waits for every reply (a delayed reply exposes any premature event)
list($r) = scenario('gating-delay', ['hooks' => ['before_reply' => function ($type) { if ($type === 'ACK' || $type === 'GO') usleep(120000); }]]);
check($r['status'] === 0 && !has($r, 'event-before-reply'), 'worker library submits nothing before GO/ACK (' . codes($r) . ')');

// --- rogue worker sends the next BEGIN without waiting for ACK
list($r, $c, $srv) = scenario('gating');
check($r['status'] === 1 && has($r, 'event-before-reply') && $r['kills'] === 0, 'BEGIN before ACK stops the worker (' . codes($r) . ')');

// --- blocked statement: exactly one KILL QUERY, confirmed, censored, cleanup still runs
$clock = new TestClock(0.05);
$real = microtime(true);
list($r, $c, $srv, $spec, $t) = scenario('blocked', ['clock' => $clock]);
$realElapsed = microtime(true) - $real;
check($r['status'] === 3 && has($r, 'deadline-cancel') && count($c->kills) === 1 && $r['kills'] === 1, 'blocked worker gets exactly one cancellation (' . codes($r) . ')');
$blk = array_values(array_filter($r['statements'], function ($s) { return $s['kind'] === 'select'; }));
check(count($blk) === 1 && $blk[0]['outcome'] === 'censored' && $blk[0]['cancel'] === 'confirmed' && $blk[0]['kill_requested_ms'] !== null && $blk[0]['cancel_confirmed_ms'] !== null, 'censored sample with request/confirmation times, no next statement');
check(stateOf($r, $t) === 'dropped' && !file_exists($srv . '/tables/' . $t), 'cleanup after confirmed end');
check($clock->t - 1000.0 >= 2.0 && $realElapsed < $clock->t - 1000.0, 'fake clock: deadline reached without waiting real time');

// --- cancellation never confirmed: status 4, ledger preserved, no second KILL, no cleanup
list($r, $c, $srv, $spec, $t) = scenario('unconfirmed', ['clock' => new TestClock(0.05), 'control' => ['killIgnored' => true]]);
check($r['status'] === 4 && has($r, 'cancel-unconfirmed') && count($c->kills) === 1, 'unconfirmed cancel: 4 and a single KILL (' . codes($r) . ')');
check(has($r, 'cleanup-skipped-unconfirmed-statement') && stateOf($r, $t) === 'confirmed-created' && isset($r['recovery'][$t]) && file_exists($srv . '/tables/' . $t), 'no cleanup while a statement may run; recovery item kept');
check(!preg_grep('/^OPEN cleanup/', executed($srv)), 'no cleanup worker started');

// --- END arriving at the deadline boundary: raced/censored, no KILL
$clock = new TestClock(0.0001);
$ends = 0;
list($r, $c, $srv) = scenario('race', ['clock' => $clock, 'hooks' => ['event' => function ($m, $clk) use (&$ends) { if ($m['type'] === 'END' && ++$ends === 1) $clk->advance(2.5); }]]);
check($r['status'] === 3 && has($r, 'deadline-race-censored') && count($c->kills) === 0, 'raced END censored without KILL (' . codes($r) . ')');
check(count(sqlLines($srv)) === 1 && ($r['statements'][0]['outcome'] ?? null) === 'censored' && ($c->calls['statementState'] ?? 0) >= 1, 'idle confirmed, no further statement dispatched');

// --- stale identity: reused id belongs to another thread -> no KILL
list($r, $c) = scenario('stale-cancel', ['clock' => new TestClock(0.05)]);
check($r['status'] === 4 && has($r, 'identity-stale-no-kill') && count($c->kills) === 0, 'stale identity: no KILL, status 4 (' . codes($r) . ')');

// --- HELLO mapping not verifiable -> the workload does not run
list($r, $c, $srv) = scenario('hello-mismatch', ['control' => ['identityMismatch' => true]]);
check($r['status'] === 2 && has($r, 'identity-unverified') && sqlLines($srv) === [] && count($c->kills) === 0, 'unverified identity blocks all workload SQL (' . codes($r) . ')');

// --- mismatched token / dead connection identity
list($r, $c) = scenario('bad-token');
check($r['status'] === 1 && has($r, 'event-identity') && count($c->kills) === 0, 'wrong worker token rejected (' . codes($r) . ')');
list($r, $c) = scenario('old-conn');
check($r['status'] === 1 && has($r, 'begin-stale-connection') && count($c->kills) === 0, 'statement on a replaced connection rejected (' . codes($r) . ')');

// --- worker loss mid-statement
list($r, $c, $srv) = scenario('crash-hang', ['clock' => new TestClock(0.05)]);
check($r['status'] === 4 && has($r, 'worker-lost-statement-unconfirmed') && count($c->kills) === 0, 'loss with server statement still running: 4, no delayed KILL (' . codes($r) . ')');
list($r, $c, $srv) = scenario('exit-before-hello');
check($r['status'] === 1 && has($r, 'worker-lost') && sqlLines($srv) === [], 'exit before HELLO is a failure (' . codes($r) . ')');

// --- crashes around CREATE acknowledgement
list($r, $c, $srv, $spec, $t) = scenario('crash-before-ack');
check($r['status'] === 1 && in_array(stateOf($r, $t), [null, 'not-created'], true) && !file_exists($srv . '/tables/' . $t) && !preg_grep('/^OPEN cleanup/', executed($srv)), 'crash before CREATE ack: nothing created or dropped (' . codes($r) . ')');
list($r, $c, $srv, $spec, $t) = scenario('crash-after-ack');
check($r['status'] === 1 && stateOf($r, $t) === 'dropped' && !file_exists($srv . '/tables/' . $t), 'crash after confirmed CREATE: fresh cleanup drops it (' . codes($r) . ')');
list($r, $c, $srv, $spec, $t) = scenario('crash-during-create');
check($r['status'] === 4 && stateOf($r, $t) === 'ownership-uncertain' && file_exists($srv . '/tables/' . $t) && !preg_grep('/DROP/', executed($srv)), 'interrupted CREATE: ownership-uncertain, never dropped (' . codes($r) . ')');

// --- name collisions
list($r, $c, $srv, $spec, $t) = scenario('collision', ['control' => ['existing' => true]]);
check($r['status'] === 1 && has($r, 'fixture-name-collision') && stateOf($r, $t) === null && !preg_grep('/CREATE/', executed($srv)), 'existing name rejected before CREATE (' . codes($r) . ')');
list($r, $c, $srv, $spec, $t) = scenario('collision-create', ['pre_tables' => ['@'], 'control' => ['hideTables' => true]]);
check($r['status'] === 1 && stateOf($r, $t) === 'rejected-collision' && file_exists($srv . '/tables/' . $t) && !preg_grep('/DROP/', executed($srv)), 'CREATE collision is not adopted nor dropped (' . codes($r) . ')');
list($r, $c, $srv, $spec, $t) = scenario('duplicate');
check($r['status'] === 1 && has($r, 'duplicate-fixture-name') && stateOf($r, $t) === 'dropped', 'duplicate registration rejected, owned table still cleaned (' . codes($r) . ')');

// --- oversized / malformed / truncated events
foreach (['oversized' => 'event-oversized', 'malformed' => 'event-malformed', 'broken' => 'event-truncated'] as $n => $code) {
    list($r) = scenario($n);
    check($r['status'] === 1 && has($r, $code), "$n event stops the worker (" . codes($r) . ')');
}

// --- pipe backpressure: >1 MiB stdout/stderr before HELLO is drained, capped and reported
list($r, $c, $srv, $spec, $t) = scenario('noise');
$cap = $r['captures'][0] ?? null;
check($cap && $cap['stdout']['bytes'] === 3 << 20 && $cap['stdout']['captured'] === PNS::CAPTURE_MAX && $cap['stdout']['truncated'] && $cap['stderr']['truncated'], 'outputs drained and capped at 1 MiB');
check($r['status'] === 3 && has($r, 'capture-truncated') && stateOf($r, $t) === 'dropped', 'truncation propagates as incomplete, never PASS (' . codes($r) . ')');
$all = file_get_contents($r['evidence_dir'] . '/metrics.json') . file_get_contents($r['evidence_dir'] . '/events.jsonl');
check(strpos($all, 'CANARY') === false && strpos($all, '"LIMIT"') !== false, 'no raw stderr retained; explicit LIMIT event');

// --- observer timeout
list($r, $c, $srv, $spec, $t) = scenario('observer-timeout', ['control' => ['observeTimeout' => true]]);
check($r['status'] === 4 && has($r, 'observer-failure') && count(preg_grep('/^SQL .*SELECT id/', executed($srv))) === 1, 'observer timeout stops dispatch with status 4 (' . codes($r) . ')');

// --- cleanup statement timeout
list($r, $c, $srv, $spec, $t) = scenario('cleanup-block', ['clock' => new TestClock(0.02), 'files' => ['block-drop']]);
check($r['status'] === 4 && has($r, 'cleanup-drop-failed') && count($c->kills) === 1, 'blocked DROP cancelled once, cleanup not successful (' . codes($r) . ')');
check(stateOf($r, $t) === 'confirmed-created' && isset($r['recovery'][$t]) && file_exists($srv . '/tables/' . $t), 'recovery item kept in ledger');

// --- budgets and preflight
list($r, $c, $srv) = scenario('budget', ['limits' => ['max_select' => 2]]);
check($r['status'] === 3 && has($r, 'select-budget') && $r['counters']['select'] === 2 && count(sqlLines($srv)) === 2, 'SELECT budget stops submission (' . codes($r) . ')');
list($r, $c, $srv) = scenario('preflight', ['control' => ['failPreflight' => true]]);
check($r['status'] === 2 && executed($srv) === [] && $r['captures'] === [] && json_decode(file_get_contents($r['evidence_dir'] . '/metrics.json'), true)['status'] === 2, 'missing control blocks the workload (status 2)');

// --- error propagation: one exact permitted error vs every other query error
$permit = function ($errno = 1054, $digest = '@') { return ['phase' => 'measure', 'kind' => 'select', 'digest' => $digest, 'errno' => $errno]; };
$errOf = function (array $r) { return array_values(array_filter($r['statements'], function ($s) { return ($s['ok'] ?? true) === false; })); };
list($r, $c, $srv, $spec, $t) = scenario('expected-error', ['expect_error' => $permit()]);
$e = $errOf($r);
check($r['status'] === 0 && $r['findings'] === [] && count($e) === 1 && $e[0]['outcome'] === 'expected-error' && $e[0]['errno'] === 1054
    && $r['expected_error'] === ['permitted' => true, 'consumed' => 1], 'permitted error recorded, ACKed, not a finding (' . codes($r) . ')');
check(count(preg_grep('/^SQL .*SELECT id FROM/', executed($srv))) === 1 && stateOf($r, $t) === 'dropped', 'dispatch continues after the expected error; cleanup ran');
check(strpos(file_get_contents($r['evidence_dir'] . '/events.jsonl'), '"EXPECTED_ERROR"') !== false
    && json_decode(file_get_contents($r['evidence_dir'] . '/metrics.json'), true)['expected_error']['consumed'] === 1, 'expected error retained in events and metrics');
list($r, $c, $srv) = scenario('unexpected-error', ['expect_error' => $permit(1054, str_repeat('0', 64))]);
check($r['status'] === 1 && has($r, 'statement-error') && $errOf($r)[0]['outcome'] === 'error' && !preg_grep('/^SQL .*SELECT id FROM/', executed($srv)), 'error of another digest is unexpected and stops dispatch (' . codes($r) . ')');
list($r, $c, $srv) = scenario('expected-error-errno', ['expect_error' => $permit(1146)]);
check($r['status'] === 1 && has($r, 'expected-error-errno-mismatch') && has($r, 'statement-error') && $r['expected_error']['consumed'] === 0, 'same digest with another errno is unexpected (' . codes($r) . ')');
list($r, $c, $srv) = scenario('expected-error-twice', ['expect_error' => $permit()]);
check($r['status'] === 1 && has($r, 'expected-error-repeated') && $r['expected_error']['consumed'] === 1, 'the permit is consumed once only (' . codes($r) . ')');
list($r, $c, $srv) = scenario('expected-error-unpermitted');
check($r['status'] === 1 && has($r, 'statement-error') && $r['expected_error'] === ['permitted' => false, 'consumed' => 0], 'without a permit every query error is a failure (' . codes($r) . ')');
foreach (['kill-errno' => $permit(1317), 'client-errno' => $permit(2013), 'bad-digest' => $permit(1054, 'x')] as $n => $x) {
    list($r, $c, $srv) = scenario('permit-' . $n, ['expect_error' => $x]);
    check($r['status'] === 1 && has($r, 'invalid-spec:expect-error') && executed($srv) === [], "invalid permit $n refused before any process");
}
list($r) = scenario('permit-phase', ['expect_error' => ['phase' => 'setup', 'kind' => 'write', 'digest' => str_repeat('a', 64), 'errno' => 1054]]);
check($r['status'] === 1 && has($r, 'invalid-spec:expect-error'), 'permit limited to a measure SELECT');

// --- an earlier chunk ledger is never overwritten
$prev = $RUN_DIR . '/chunks/roundtrip';
$h = hash_file('sha256', $prev . '/ledger.json') . hash_file('sha256', $prev . '/metrics.json');
list($r, $c, $srv) = scenario('roundtrip-again', ['chunk' => 'roundtrip']);
check($r['status'] === 1 && has($r, 'ledger-exists') && executed($srv) === [] && hash_file('sha256', $prev . '/ledger.json') . hash_file('sha256', $prev . '/metrics.json') === $h, 'existing ledger refused, evidence untouched');

// --- control/session SQL is charged as actually executed and the "other" cap is enforced with a reserve
list($r, $c) = scenario('preflight-cost', ['control' => ['preflightCost' => 8]]);
check($r['status'] === 0 && $r['counters']['control'] === $c->sql && $r['counters']['control'] >= 8, 'multi-SQL preflight charged as its real statement count (' . codes($r) . ')');
check($r['counters']['session'] === 3 + 3 + 5 && $r['counters']['other'] === $r['counters']['control'] + $r['counters']['session'] + 3, 'worker bootstrap session SQL counted in other (setup+measure+cleanup)');
$cap = PNS::RESERVE_BASE + 1 + PNS::SESSION_SQL_MAX + 13;
list($r, $c, $srv, $spec, $t) = scenario('budget-other', ['limits' => ['max_other' => $cap]]);
check($r['status'] === 3 && has($r, 'other-budget') && $r['counters']['other'] <= $cap && $r['counters']['select'] >= 1 && $r['counters']['select'] < 3, 'other cap stops the workload before it is exceeded (' . codes($r) . ')');
check(stateOf($r, $t) === 'dropped' && $r['recovery'] === [] && !has($r, 'control-reserve-exhausted'), 'reserve still covers the cleanup worker and residue check');
list($r, $c) = scenario('preflight-cap', ['limits' => ['max_other' => PNS::RESERVE_BASE + PNS::SESSION_SQL_MAX + PNS::PREFLIGHT_SQL_MAX - 1]]);
check($r['status'] === 3 && has($r, 'other-budget') && ($c->calls['preflight'] ?? 0) === 0 && $r['captures'] === [], 'preflight refused before it runs when its bound does not fit');

// --- ended/idle confirmation: no late answer, no probe without a whole read timeout left
$clock = new TestClock(0.05);
list($r, $c, $srv, $spec, $t) = scenario('late-confirm', ['clock' => $clock, 'control' => ['clock' => $clock, 'stateDelay' => PNS::CONFIRM_WINDOW + 0.5]]);
check($r['status'] === 4 && has($r, 'cancel-unconfirmed') && count($c->kills) === 1 && ($c->calls['statementState'] ?? 0) === 1, 'idle answer returned after the 3 s window is not accepted (' . codes($r) . ')');
check(!preg_grep('/^OPEN cleanup/', executed($srv)) && stateOf($r, $t) === 'confirmed-created' && isset($r['recovery'][$t]), 'late confirmation does not proceed to cleanup');
$clock = new TestClock(0.05);
list($r, $c, $srv) = scenario('short-window', ['clock' => $clock, 'control' => ['clock' => $clock, 'stateDelay' => 1.2, 'killIgnored' => true]]);
check($r['status'] === 4 && has($r, 'cancel-unconfirmed') && ($c->calls['statementState'] ?? 0) === 1 && !preg_grep('/^OPEN cleanup/', executed($srv)), 'no probe started with less than a control read timeout left (' . codes($r) . ')');

// --- cleanup window covers the DROPs and the residue verification
list($r, $c, $srv, $spec, $t) = scenario('cleanup-window', ['limits' => ['cleanup_seconds' => 3.0]]);
check($r['status'] === 4 && has($r, 'cleanup-window-exhausted') && stateOf($r, $t) === 'confirmed-created' && file_exists($srv . '/tables/' . $t) && !preg_grep('/DROP/', executed($srv)), 'no DROP granted whose cancellation could outlast the cleanup window (' . codes($r) . ')');
$dones = 0;
list($r, $c, $srv, $spec, $t) = scenario('residue-late', ['clock' => new TestClock(0.0001), 'hooks' => ['event' => function ($m, $clk) use (&$dones) { if ($m['type'] === 'DONE' && ++$dones === 2) $clk->advance(PNS::CLEANUP_SECONDS + 1); }]]);
check($r['status'] === 4 && has($r, 'residue-unverified-window') && stateOf($r, $t) === 'dropped' && ($c->calls['tableExists'] ?? 0) === 1, 'residue not verified after the cleanup window (' . codes($r) . ')');

// --- worker-side statement guard: kind/table/SQL agree before anything is submitted
list($r, $c, $srv, $spec, $t) = scenario('shape-shop-write');
check($r['status'] === 1 && has($r, 'worker-error') && !preg_grep('/oc_product/', executed($srv)) && stateOf($r, $t) === 'dropped', 'DML on a shop table refused in the worker, owned table cleaned (' . codes($r) . ')');
$tt = 'pnsx_1_seo_url';
$shape = [
    [null, 'measure', 'select', 'SELECT keyword FROM oc_seo_url WHERE store_id = 0', null],
    [null, 'measure', 'select', 'SHOW INDEX FROM oc_seo_url', null],
    ['not-read-only', 'measure', 'select', 'UPDATE oc_seo_url SET keyword = 1', null],
    ['not-read-only', 'measure', 'select', 'SELECT * FROM oc_product FOR UPDATE', null],
    ['not-read-only', 'measure', 'select', "SELECT 1 INTO OUTFILE '/tmp/x'", null],
    ['executable-comment', 'measure', 'select', 'SELECT 1 /*!50000 , SLEEP(1) */', null],
    ['table', 'measure', 'select', 'SELECT 1', $tt],
    ['phase-kind', 'measure', 'write', "INSERT INTO `$tt` VALUES (1)", $tt],
    [null, 'diagnostic', 'explain', 'EXPLAIN SELECT 1', null],
    ['explain-shape', 'diagnostic', 'explain', 'EXPLAIN DELETE FROM oc_product', null],
    [null, 'diagnostic', 'analyze', 'EXPLAIN ANALYZE SELECT 1', null],
    [null, 'setup', 'setup', 'SET SESSION sql_mode = DEFAULT', null],
    ['setup-global', 'setup', 'setup', 'SET GLOBAL max_connections = 1', null],
    [null, 'setup', 'create', "CREATE TABLE `$tt` (id INT)", $tt],
    ['create-shape', 'setup', 'create', "CREATE TABLE IF NOT EXISTS `$tt` (id INT)", $tt],
    ['create-shape', 'setup', 'create', 'CREATE TABLE `oc_x` (id INT)', $tt],
    [null, 'setup', 'write', "INSERT INTO `$tt` (id) VALUES (1)", $tt],
    [null, 'setup', 'write', "UPDATE `$tt` SET id = 2 WHERE id = 1", $tt],
    [null, 'setup', 'write', "DELETE FROM `$tt` WHERE id = 2", $tt],
    ['write-multi-table', 'setup', 'write', "DELETE FROM `$tt` USING `$tt` JOIN oc_product", $tt],
    ['write-shape', 'setup', 'write', "UPDATE `$tt`, oc_product SET id = 2", $tt],
    [null, 'cleanup', 'drop', "DROP TABLE `$tt`", $tt],
    ['drop-shape', 'cleanup', 'drop', "DROP TABLE `$tt`, oc_product", $tt],
    ['drop-shape', 'cleanup', 'drop', 'DROP TABLE `oc_product`', $tt],
];
$bad = [];
foreach ($shape as $i => $s) if (PNS_Worker::checkShape($s[1], $s[2], $s[3], $s[4]) !== $s[0]) $bad[] = $i;
check($bad === [], 'statement shape guard table (' . implode(',', $bad) . ')');

// --- strict server-variable checks and persistent-link refusal (no connection attempted)
check(PNS_MysqlControl::exactInt(2000, 2000) && PNS_MysqlControl::exactInt('2000', 2000) && !PNS_MysqlControl::exactInt(2000.0, 2000)
    && !PNS_MysqlControl::exactInt('2000.0', 2000) && !PNS_MysqlControl::exactInt('02000', 2000) && !PNS_MysqlControl::exactInt(null, 2000), 'max_execution_time accepted as native int or exact text only');
check(PNS_MysqlControl::exactSeconds(2.0, 2) && PNS_MysqlControl::exactSeconds('2.000000', 2) && !PNS_MysqlControl::exactSeconds('20', 2) && !PNS_MysqlControl::exactSeconds(2, 2), 'max_statement_time strict');
$pc = new PNS_MysqlControl(['DB_HOSTNAME' => 'p:db.invalid', 'DB_USERNAME' => 'u', 'DB_PASSWORD' => 'x', 'DB_DATABASE' => 'd']);
try { $pc->preflight(); $msg = null; } catch (PNS_ControlUnsupported $e) { $msg = $e->getMessage(); }
check($msg === 'persistent-connection' && $pc->statementCount() === 0, 'persistent control host refused before connecting');
try { (new PNS_MysqliWorkerDriver(['DB_HOSTNAME' => ' P:db.invalid']))->open('setup', 2.0, 15.0, 'm'); $msg = null; } catch (PNS_WorkerFailure $e) { $msg = $e->getMessage(); }
check($msg === 'persistent-connection', 'persistent worker host refused before connecting');

// --- the supervisor process never loaded worker/runtime source
check(!class_exists('PNS_TestRuntimeMarker', false) && !class_exists('FakeDriver', false), 'worker payload classes never defined in the supervisor');
check(array_map('realpath', get_included_files()) === [realpath(__FILE__), realpath(__DIR__ . '/product_navigation_supervisor.php')], 'supervisor loaded only its support file');

echo "checks: {$GLOBALS['checks']}, failed: {$GLOBALS['failed']}\n";
exit($GLOBALS['failed'] === 0 ? 0 : 1);

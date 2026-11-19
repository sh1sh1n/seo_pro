<?php
/*
 * Test-only supervisor support for the supervised product-navigation MySQL runs (runner A).
 * This is NOT a shop bootstrap: including it defines classes only, runs nothing, loads no module
 * class, and contains no include/require/eval. It is never autoloaded by a shop request.
 *
 * Roles
 *   PNS_Supervisor  one PHP process: independent control connection (PNS_Control), deadlines,
 *                   counters, the durable fixture ledger (PNS_Ledger) and bounded evidence.
 *   workload worker a PHP_BINARY child started with proc_open(argument array). Its whole source
 *                   (a boot header + the supplied worker source) is streamed to the child's stdin;
 *                   PHP compiles it from stdin, it is never saved as a file. The supervisor only
 *                   handles that source as a string and never evaluates it.
 *   cleanup worker  always a FRESH child (PNS_Worker::cleanupMain) launched only after the workload
 *                   statement end is confirmed; it receives the ledger's confirmed-owned names only.
 *
 * Pipes: 0 stdin (source, then closed), 1 stdout and 2 stderr (drained, capped at 1 MiB each,
 * never retained raw), 3 events child->supervisor, 4 acknowledgements supervisor->child.
 * All supervisor pipes are non-blocking and drained in one stream_select loop.
 *
 * Protocol v1, newline-delimited JSON, each line <= 16 KiB. Every message carries
 * v=1, nonce (run), token (worker), seq (strictly +1 per direction), phase, type.
 *   worker -> supervisor                     supervisor -> worker
 *   HELLO {conn,role,conn_id,...,session_sql} HELLO_ACK {re}  (worker waits <= 2 s)
 *   REGISTER_CREATE {conn,table}             ACK {re,decision=accept|reject}  (<= 5 s)
 *   BEGIN {conn,kind,digest,table,ddl_head}  GO {re}          (<= 5 s)
 *   END {conn,re,ok,errno,duration_ns,...}   ACK {re,decision=continue}       (<= 5 s)
 *   DONE {reason=done|stopped|error}         STOP {re,reason}  (instead of any reply)
 *                                            CLEANUP {tables}  (cleanup worker, after HELLO_ACK)
 * An event received before the reply it must wait for, a wrong nonce/token/seq/phase, unknown or
 * missing fields, an oversized or truncated line all stop the worker (status 1).
 *
 * Phase C usage (supervisor side, e.g. inside the emitted stdin bundle):
 *   $control = new PNS_MysqlControl($credentialsFromLocalConfigParser);
 *   $result  = (new PNS_Supervisor($control))->run([
 *       'run_dir' => private absolute dir (created 0700 / verified), 'chunk_id' => 'c01',
 *       'build' => 'baseline'|'candidate'|..., 'db_prefix' => prefix the worker uses,
 *       'shop_prefix' => 'oc_', 'fixture_prefix' => 'pnsx_<unique>_',
 *       'workload_source' => PNS_Bundle::decodePayload($encodedWorkload),
 *       'cleanup_source'  => PNS_Bundle::decodePayload($encodedCleanup),
 *       'manifest' => validated case manifest (array, passed to the worker in the boot header),
 *       'env' => ['SEO_PRO_TEST_STORE_CONFIG' => path, ...]  (non-secret selectors only),
 *       'limits' => ['chunk_seconds'=>remaining, 'max_select'=>..., 'max_other'=>..., 'cleanup_seconds'=>...] (may only lower defaults),
 *       'expect_error' => null | ['phase'=>'measure','kind'=>'select','digest'=>sha256(untagged SQL),'errno'=>1000..1999]
 *                       (optional: exactly one workload SELECT with that digest may END with that errno, once; it is
 *                        recorded as outcome 'expected-error' and ACKed. Any other error, a wrong errno or a second
 *                        occurrence is 'statement-error' (status 1) and stops dispatch. Timeout/interrupt errnos refused.)
 *   ]);
 *   exit($result['status']);   // 0 ok, 1 protocol/assertion, 2 preflight, 3 deadline/budget/incomplete, 4 unresolved
 *   Evidence: <run_dir>/chunks/<chunk_id>/{ledger.json,events.jsonl,metrics.json}, 0600 files in 0700 dirs.
 * Worker side (workload source = PNS_Bundle::workerSource([...]) built locally by the emitter):
 *   PNS_Worker::main(function (PNS_Worker $w) {
 *       $w->connect('setup', new PNS_MysqliWorkerDriver($credentials));     // HELLO
 *       $w->registerCreate($table);                                         // ledger first
 *       $w->statement('setup', 'create', 'CREATE TABLE `'.$table.'` (...)', $table);
 *       $w->statement('setup', 'write', 'INSERT INTO `'.$table.'` ...', $table);
 *       $w->close();                                                        // before the next connection
 *       $w->connect('measure', new PNS_MysqliWorkerDriver($credentials));
 *       $r = $w->statement('measure', 'select', $sql);                      // $r['rows'] for the caller
 *       $w->statement('diagnostic', 'explain', 'EXPLAIN '.$sql);
 *   });
 *   Cleanup source: PNS_Worker::cleanupMain(function ($role) use ($c) { return new PNS_MysqliWorkerDriver($c); });
 *
 * Known limits: KILL QUERY is cooperative; option behaviour and KILL privilege of the real stand
 * are verified only by the Phase D probe chunk; examined rows come from existing Performance
 * Schema history only and are otherwise 'unmeasured'.
 */

final class PNS
{
    const PROTOCOL = 1;
    const EVENT_MAX = 16384;
    const CAPTURE_MAX = 1048576;
    const ACK_BUFFER_MAX = 65536;
    const REPLY_MAX = 65536;
    const SOURCE_MAX = 16777216;
    const MANIFEST_MAX = 1048576;
    const CONNECT_TIMEOUT = 2.0;
    const CONTROL_READ_TIMEOUT = 2.0;
    const READ_TIMEOUT_SETUP = 15.0;
    const READ_TIMEOUT_STATEMENT = 6.0;
    const ACK_HELLO = 2.0;
    const ACK_OTHER = 5.0;
    const CONFIRM_WINDOW = 3.0;
    const STARTUP_SECONDS = 20.0;
    const EXIT_GRACE = 5.0;
    const CHUNK_SECONDS = 180.0;
    const CLEANUP_SECONDS = 30.0;
    const MAX_SELECT = 1000;
    const MAX_OTHER = 6000;
    const LOCK_WAIT_TIMEOUT = 1;
    /** Session SQL a worker driver may run while opening one connection (charset, id, identity, 2 x lock wait). */
    const SESSION_SQL_MAX = 5;
    /** Upper bound of SQL statements one PNS_MysqlControl::preflight() call executes. */
    const PREFLIGHT_SQL_MAX = 12;
    /** Minimum spacing of ended/idle probes; with CONFIRM_WINDOW and CONTROL_READ_TIMEOUT it bounds probes per confirmation. */
    const CONFIRM_POLL = 0.1;
    /**
     * "other" statements held back from the workload for cancellation/confirmation (2 x (2 + 11 probes) + 11)
     * and the cleanup worker's session/identity/lock checks (5 + 2); residue checks add one per ledger entry.
     */
    const RESERVE_BASE = 50;
    const EVIDENCE_EVENTS_MAX = 20000;
    const EVIDENCE_STATEMENTS_MAX = 8000;
    const STATEMENT_LIMITS = ['setup' => 10.0, 'measure' => 2.0, 'diagnostic' => 2.0, 'cleanup' => 2.0];
    const PHASE_KINDS = ['setup' => ['create', 'write', 'setup', 'select'], 'measure' => ['select'], 'diagnostic' => ['explain', 'analyze'], 'cleanup' => ['drop']];
    const ROLE_PHASES = ['setup' => ['setup'], 'measure' => ['measure', 'diagnostic'], 'cleanup' => ['cleanup']];
    const ENVELOPE = ['v', 'nonce', 'token', 'seq', 'phase', 'type'];
    const EVENT_KEYS = [
        'HELLO' => ['conn', 'role', 'conn_id', 'build', 'db_prefix', 'connect_timeout', 'read_timeout', 'reconnect', 'lock_wait_timeout', 'session_sql'],
        'REGISTER_CREATE' => ['conn', 'table'],
        'BEGIN' => ['conn', 'kind', 'digest', 'table', 'ddl_head'],
        'END' => ['conn', 're', 'ok', 'errno', 'duration_ns', 'rows', 'affected'],
        'DONE' => ['reason'],
    ];
    /** Severity 4 > 1 > 3 > 2 > 0. */
    const STATUS_RANK = [0 => 0, 2 => 1, 3 => 2, 1 => 3, 4 => 4];

    public static function worse($a, $b)
    {
        return self::STATUS_RANK[$b] > self::STATUS_RANK[$a] ? $b : $a;
    }

    public static function same($a, $b)
    {
        return (is_int($a) || is_float($a)) && abs((float)$a - (float)$b) < 1e-9;
    }

    public static function tableName($t)
    {
        return is_string($t) && preg_match('/^[a-z0-9_]{1,64}$/', $t) === 1;
    }
}

/** Exception messages are fixed codes only; raw server/driver messages never enter them. */
class PNS_ProtocolError extends RuntimeException {}
class PNS_ControlError extends RuntimeException {}
class PNS_ControlTimeout extends PNS_ControlError {}
class PNS_ControlUnsupported extends PNS_ControlError {}
/** The call was refused before it ran: it would exceed the "other" statement cap (or the reserve). */
class PNS_ControlBudget extends PNS_ControlError {}
class PNS_LedgerError extends RuntimeException {}
class PNS_LedgerExists extends PNS_LedgerError {}
class PNS_WorkerStop extends RuntimeException {}
class PNS_WorkerFailure extends RuntimeException {}

interface PNS_Clock
{
    /** Monotonic seconds. */
    public function now();
    /** Called when a poll found no activity; a fake clock advances here. */
    public function idle();
    /** Real seconds one stream_select may wait. */
    public function pollInterval();
}

final class PNS_MonotonicClock implements PNS_Clock
{
    public function now() { return hrtime(true) / 1e9; }
    public function idle() {}
    public function pollInterval() { return 0.05; }
}

final class PNS_Files
{
    public static function privateDir($path)
    {
        clearstatcache();
        if (!is_string($path) || $path === '' || $path[0] !== '/') throw new RuntimeException('private-dir-path');
        if (is_link($path)) throw new RuntimeException('private-dir-symlink');
        if (!file_exists($path)) {
            $old = umask(077);
            $ok = @mkdir($path, 0700);
            umask($old);
            if (!$ok) throw new RuntimeException('private-dir-create');
        }
        self::assertPrivateDir($path);
    }

    public static function assertPrivateDir($path)
    {
        clearstatcache();
        $st = @lstat($path);
        if ($st === false || ($st['mode'] & 0170000) !== 0040000) throw new RuntimeException('private-dir-type');
        if (($st['mode'] & 0077) !== 0) throw new RuntimeException('private-dir-mode');
        if (function_exists('posix_geteuid') && $st['uid'] !== posix_geteuid()) throw new RuntimeException('private-dir-owner');
    }

    /** Write a 0600 temp file in the same private dir, flush/fsync, then rename over the target. */
    public static function atomicWrite($path, $content)
    {
        $dir = dirname($path);
        self::assertPrivateDir($dir);
        if (is_link($path)) throw new RuntimeException('atomic-target-symlink');
        $tmp = $dir . '/.' . basename($path) . '.' . bin2hex(random_bytes(8)) . '.tmp';
        $old = umask(077);
        $h = @fopen($tmp, 'xb');
        umask($old);
        if ($h === false) throw new RuntimeException('atomic-open');
        $ok = @chmod($tmp, 0600);
        $off = 0;
        $len = strlen($content);
        while ($ok && $off < $len) {
            $n = @fwrite($h, substr($content, $off, 1 << 20));
            if ($n === false || $n === 0) { $ok = false; break; }
            $off += $n;
        }
        $ok = $ok && @fflush($h);
        if ($ok && function_exists('fsync')) $ok = @fsync($h);
        @fclose($h);
        if (!$ok || !@rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('atomic-write');
        }
    }
}

/**
 * Durable fixture ledger, owned only by the supervisor. Persisted atomically on every change.
 * intended -> confirmed-created -> tainted -> dropped; ambiguous creation -> ownership-uncertain
 * (never dropped automatically); rejected-collision / not-created are terminal and not owned.
 */
final class PNS_Ledger
{
    const DROPPABLE = ['confirmed-created', 'tainted'];
    const NEXT = [
        'intended' => ['confirmed-created', 'ownership-uncertain', 'rejected-collision', 'not-created'],
        'confirmed-created' => ['tainted', 'dropped', 'ownership-uncertain'],
        'tainted' => ['dropped', 'ownership-uncertain'],
        'dropped' => [], 'ownership-uncertain' => [], 'rejected-collision' => [], 'not-created' => [],
    ];

    private $path;
    private $prefix;
    private $shopPrefix;
    private $clock;
    private $t0;
    private $entries = [];
    private $submitted = [];

    public static function validPrefix($prefix, $shopPrefix)
    {
        if (!is_string($prefix) || !preg_match('/^[a-z][a-z0-9_]{1,40}_$/', $prefix)) return false;
        if (!is_string($shopPrefix)) return false;
        if ($shopPrefix !== '' && (strpos($prefix, $shopPrefix) === 0 || strpos($shopPrefix, $prefix) === 0)) return false;
        return true;
    }

    public static function create($path, $prefix, $shopPrefix, PNS_Clock $clock)
    {
        if (!self::validPrefix($prefix, $shopPrefix)) throw new PNS_LedgerError('ledger-prefix');
        clearstatcache();
        if (file_exists($path) || is_link($path)) throw new PNS_LedgerExists('ledger-exists');
        try {
            PNS_Files::assertPrivateDir(dirname($path));
        } catch (RuntimeException $e) {
            throw new PNS_LedgerError('ledger-dir');
        }
        $l = new self();
        $l->path = $path;
        $l->prefix = $prefix;
        $l->shopPrefix = $shopPrefix;
        $l->clock = $clock;
        $l->t0 = $clock->now();
        $l->persist();
        return $l;
    }

    public function validName($t)
    {
        return PNS::tableName($t) && strpos($t, $this->prefix) === 0 && strlen($t) > strlen($this->prefix)
            && !($this->shopPrefix !== '' && strpos($t, $this->shopPrefix) === 0);
    }

    public function has($t) { return isset($this->entries[$t]); }
    public function state($t) { return isset($this->entries[$t]) ? $this->entries[$t]['state'] : null; }
    public function submitted($t) { return isset($this->submitted[$t]); }
    public function entries() { return $this->entries; }

    public function register($t)
    {
        if (!$this->validName($t)) throw new PNS_LedgerError('ledger-name');
        if ($this->has($t)) throw new PNS_LedgerError('ledger-duplicate');
        $this->entries[$t] = ['state' => 'intended', 'history' => [['intended', $this->ms()]]];
        try {
            $this->persist();
        } catch (PNS_LedgerError $e) {
            unset($this->entries[$t]);
            throw $e;
        }
    }

    public function markSubmitted($t)
    {
        if ($this->state($t) !== 'intended') throw new PNS_LedgerError('ledger-submit');
        $this->submitted[$t] = true;
    }

    public function transition($t, $to)
    {
        $from = $this->state($t);
        if ($from === null || !in_array($to, self::NEXT[$from], true)) throw new PNS_LedgerError('ledger-transition');
        $this->entries[$t]['state'] = $to;
        $this->entries[$t]['history'][] = [$to, $this->ms()];
        $this->persist();
    }

    /** Only confirmed run-owned names with the run prefix; never a wildcard. */
    public function droppable()
    {
        $out = [];
        foreach ($this->entries as $t => $e) if (in_array($e['state'], self::DROPPABLE, true) && $this->validName($t)) $out[] = $t;
        return $out;
    }

    public function recoveryItems()
    {
        $out = [];
        foreach ($this->entries as $t => $e) {
            if (in_array($e['state'], ['confirmed-created', 'tainted', 'ownership-uncertain', 'intended'], true)) $out[$t] = $e['state'];
        }
        return $out;
    }

    /** An intention whose CREATE was never granted GO was never submitted: not created. */
    public function finalizeUnsubmitted()
    {
        foreach ($this->entries as $t => $e) if ($e['state'] === 'intended' && !$this->submitted($t)) $this->transition($t, 'not-created');
    }

    private function ms()
    {
        return (int)round(($this->clock->now() - $this->t0) * 1000);
    }

    private function persist()
    {
        $doc = ['version' => 1, 'fixture_prefix' => $this->prefix, 'shop_prefix' => $this->shopPrefix, 'entries' => (object)$this->entries];
        try {
            PNS_Files::atomicWrite($this->path, json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        } catch (RuntimeException $e) {
            throw new PNS_LedgerError('ledger-write');
        }
    }
}

/**
 * Independent control/observer interface. Every call is bounded (control read timeout 2 s);
 * failures throw PNS_ControlError (PNS_ControlTimeout when the bound expired).
 */
interface PNS_Control
{
    /** Verify controls; return a scalar capability map. Throws PNS_ControlUnsupported when identity/cancel controls are missing. */
    public function preflight();
    /** Thread id when $connId is live and carries @pns_identity = $marker, otherwise null. */
    public function verifyIdentity($connId, $marker);
    /** 'running' | 'idle' | 'gone' for the connection whose thread id is $threadId (a different thread on a reused id is 'gone'). */
    public function statementState($connId, $threadId);
    /** KILL QUERY only, never KILL CONNECTION. */
    public function killQuery($connId);
    public function tableExists($table);
    /** Session lock_wait_timeout of that thread, or null when not observable. */
    public function sessionLockWait($threadId);
    /** ['rows_examined'=>int,'rows_sent'=>int] attributable to statement tag "conn:seq", or null (unmeasured). */
    public function observeStatement($threadId, $tag);
    /** Cumulative number of SQL statements this control has sent to the server (the supervisor charges the delta per call). */
    public function statementCount();
}

final class PNS_MysqlControl implements PNS_Control
{
    private $credentials;
    private $db = null;
    private $sql = 0;

    public function __construct(array $credentials)
    {
        foreach (['DB_HOSTNAME', 'DB_USERNAME', 'DB_PASSWORD', 'DB_DATABASE'] as $k) {
            if (!isset($credentials[$k]) || !is_string($credentials[$k])) throw new InvalidArgumentException('control-credentials');
        }
        $this->credentials = $credentials;
    }

    public function __debugInfo() { return ['connected' => $this->db !== null]; }

    public function statementCount() { return $this->sql; }

    /** Integer server variable as returned with or without MYSQLI_OPT_INT_AND_FLOAT_NATIVE; no loose casts. */
    public static function exactInt($v, $want)
    {
        return (is_int($v) && $v === $want) || (is_string($v) && $v === (string)$want);
    }

    /** MariaDB max_statement_time (DOUBLE): native float or its decimal text. */
    public static function exactSeconds($v, $want)
    {
        return (is_float($v) && $v === (float)$want) || (is_string($v) && preg_match('/^' . (int)$want . '(\.0+)?$/', $v) === 1);
    }

    /** A persistent mysqli link ("p:" host) may be shared/reused and would break per-connection identity. */
    public static function persistentHost($host)
    {
        return is_string($host) && strncasecmp(ltrim($host), 'p:', 2) === 0;
    }

    public function preflight()
    {
        if (self::persistentHost($this->credentials['DB_HOSTNAME'])) throw new PNS_ControlUnsupported('persistent-connection');
        if (!extension_loaded('mysqli')) throw new PNS_ControlUnsupported('mysqli');
        if (!defined('MYSQLI_OPT_READ_TIMEOUT')) throw new PNS_ControlUnsupported('read-timeout-option');
        if (filter_var(ini_get('mysqli.reconnect'), FILTER_VALIDATE_BOOLEAN)) throw new PNS_ControlUnsupported('reconnect-enabled');
        mysqli_report(MYSQLI_REPORT_OFF);
        $db = mysqli_init();
        if (!$db || !$db->options(MYSQLI_OPT_CONNECT_TIMEOUT, (int)PNS::CONNECT_TIMEOUT) || !$db->options(MYSQLI_OPT_READ_TIMEOUT, (int)PNS::CONTROL_READ_TIMEOUT)) {
            throw new PNS_ControlUnsupported('control-options');
        }
        $c = $this->credentials;
        if (!@$db->real_connect($c['DB_HOSTNAME'], $c['DB_USERNAME'], $c['DB_PASSWORD'], $c['DB_DATABASE'], (int)($c['DB_PORT'] ?? 3306))) {
            throw new PNS_ControlUnsupported('control-connect');
        }
        $this->sql++;   // set_charset sends SET NAMES
        if (!@$db->set_charset('utf8mb4')) throw new PNS_ControlUnsupported('control-charset');
        $this->db = $db;
        $version = (string)$db->server_info;
        $family = stripos($version, 'mariadb') !== false ? 'mariadb' : 'mysql';
        // Verified session SELECT limit where supported; otherwise reported as 'unsupported' (a named gap, not a pass).
        $limit = 'unsupported';
        try {
            if ($family === 'mysql') {
                if ($this->exec('SET SESSION max_execution_time = 2000') && self::exactInt($this->scalar('SELECT @@SESSION.max_execution_time'), 2000)) $limit = 'max_execution_time=2000';
            } elseif ($this->exec('SET SESSION max_statement_time = 2') && self::exactSeconds($this->scalar('SELECT @@SESSION.max_statement_time'), 2)) {
                $limit = 'max_statement_time=2';
            }
        } catch (PNS_ControlError $e) {
            $limit = 'unsupported';
        }
        $own = $this->scalar('SELECT CONNECTION_ID()');
        if (!(is_int($own) || (is_string($own) && ctype_digit($own))) || (int)$own < 1) throw new PNS_ControlUnsupported('control-connection-id');
        $own = (int)$own;
        if (!$this->exec("SET @pns_identity = 'pns-control-probe'")) throw new PNS_ControlUnsupported('identity-probe');
        $tid = $this->verifyIdentity($own, 'pns-control-probe');
        if ($tid === null) throw new PNS_ControlUnsupported('identity-mapping');
        if ($this->sessionLockWait($tid) === null) throw new PNS_ControlUnsupported('session-variables');
        if ($this->statementState($own, $tid) !== 'running') throw new PNS_ControlUnsupported('thread-state');
        return [
            'family' => $family,
            'version' => substr(preg_replace('/[^0-9A-Za-z.\-]/', '', $version), 0, 40),
            'select_limit' => $limit,
            'identity' => 'performance_schema.threads+user_variables_by_thread',
            'cancel' => 'KILL QUERY',
        ];
    }

    public function verifyIdentity($connId, $marker)
    {
        $rows = $this->rows('SELECT t.THREAD_ID AS tid FROM performance_schema.threads t'
            . ' JOIN performance_schema.user_variables_by_thread u ON u.THREAD_ID = t.THREAD_ID'
            . ' WHERE t.PROCESSLIST_ID = ' . (int)$connId . " AND u.VARIABLE_NAME = 'pns_identity'"
            . " AND u.VARIABLE_VALUE = '" . $this->db->real_escape_string((string)$marker) . "'");
        return count($rows) === 1 ? (int)$rows[0]['tid'] : null;
    }

    public function statementState($connId, $threadId)
    {
        $rows = $this->rows('SELECT THREAD_ID AS tid, PROCESSLIST_COMMAND AS cmd FROM performance_schema.threads WHERE PROCESSLIST_ID = ' . (int)$connId);
        if (count($rows) !== 1 || (int)$rows[0]['tid'] !== (int)$threadId) return 'gone';
        return $rows[0]['cmd'] === 'Sleep' ? 'idle' : 'running';
    }

    public function killQuery($connId)
    {
        $this->connected();
        $this->sql++;
        if (@$this->db->query('KILL QUERY ' . (int)$connId) === false && $this->db->errno !== 1094) $this->fail();
    }

    public function tableExists($table)
    {
        if (!PNS::tableName($table)) throw new PNS_ControlError('control-table-name');
        return (int)$this->scalar("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . $table . "'") > 0;
    }

    public function sessionLockWait($threadId)
    {
        $rows = $this->rows('SELECT VARIABLE_VALUE AS v FROM performance_schema.variables_by_thread WHERE THREAD_ID = ' . (int)$threadId . " AND VARIABLE_NAME = 'lock_wait_timeout'");
        return count($rows) === 1 ? (int)$rows[0]['v'] : null;
    }

    public function observeStatement($threadId, $tag)
    {
        if (!preg_match('/^\d+:\d+$/', (string)$tag)) throw new PNS_ControlError('control-tag');
        $this->connected();
        $this->sql++;
        $r = @$this->db->query('SELECT ROWS_EXAMINED AS e, ROWS_SENT AS s FROM performance_schema.events_statements_history'
            . ' WHERE THREAD_ID = ' . (int)$threadId . " AND SQL_TEXT LIKE '/*pns:" . $tag . "*/%' ORDER BY EVENT_ID DESC LIMIT 1");
        if ($r === false) {
            if (in_array($this->db->errno, [1142, 1146], true)) return null;
            $this->fail();
        }
        $row = $r->fetch_assoc();
        $r->free();
        return $row ? ['rows_examined' => (int)$row['e'], 'rows_sent' => (int)$row['s']] : null;
    }

    private function connected()
    {
        if ($this->db === null) throw new PNS_ControlError('control-not-connected');
    }

    private function fail()
    {
        $e = (int)$this->db->errno;
        if (in_array($e, [2006, 2013, 3024, 1969, 1317], true)) throw new PNS_ControlTimeout('control-read');
        throw new PNS_ControlError('control-query');
    }

    /** Statement without a result set; true on success. Counted. */
    private function exec($sql)
    {
        $this->connected();
        $this->sql++;
        return @$this->db->query($sql) !== false;
    }

    private function rows($sql)
    {
        $this->connected();
        $this->sql++;
        $r = @$this->db->query($sql);
        if ($r === false) $this->fail();
        if (!$r instanceof mysqli_result) return [];
        $rows = $r->fetch_all(MYSQLI_ASSOC);
        $r->free();
        return $rows;
    }

    private function scalar($sql)
    {
        $rows = $this->rows($sql);
        if (count($rows) !== 1) throw new PNS_ControlError('control-scalar');
        // Raw value: string by default, int/float under MYSQLI_OPT_INT_AND_FLOAT_NATIVE; callers compare strictly.
        return reset($rows[0]);
    }
}

/** One worker child: proc_open argument array, non-blocking bounded pipes. */
final class PNS_Process
{
    private $proc;
    private $pipes = [];
    private $stdin = '';
    private $stdinOff = 0;
    private $acks = '';
    private $ackFailed = false;
    private $buf = '';
    private $lines = [];
    private $received = 0;
    private $broken = null;
    private $cap = [];
    private $alive = true;
    private $exit = null;

    public static function start(array $cmd, array $env, $payload)
    {
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'], 3 => ['pipe', 'w'], 4 => ['pipe', 'r']];
        $pipes = [];
        $proc = @proc_open($cmd, $descriptors, $pipes, '/', $env);
        if (!is_resource($proc)) return null;
        $p = new self();
        $p->proc = $proc;
        $p->pipes = $pipes;
        $p->stdin = $payload;
        foreach ($pipes as $pipe) stream_set_blocking($pipe, false);
        foreach ([1, 2] as $i) $p->cap[$i] = ['bytes' => 0, 'captured' => 0, 'truncated' => false, 'hash' => hash_init('sha256')];
        return $p;
    }

    /** One bounded select over stdout/stderr/events (read) and stdin/acks (write). Returns true on any progress. */
    public function pump($timeout)
    {
        $read = [];
        foreach ([1, 2, 3] as $i) if (isset($this->pipes[$i])) $read[] = $this->pipes[$i];
        $write = [];
        if (isset($this->pipes[0])) $write[] = $this->pipes[0];
        if ($this->acks !== '' && isset($this->pipes[4])) $write[] = $this->pipes[4];
        if (!$read && !$write) {
            if ($timeout > 0) usleep((int)($timeout * 1e6));
            return false;
        }
        $e = null;
        $sec = (int)floor($timeout);
        $usec = (int)round(($timeout - $sec) * 1e6);
        $n = @stream_select($read, $write, $e, $sec, $usec);
        if (!$n) return false;
        $active = false;
        foreach ($read as $s) {
            $i = array_search($s, $this->pipes, true);
            if ($i === false) continue;
            $d = @fread($s, 65536);
            if ($d === false || $d === '') {
                if (feof($s)) {
                    $this->closePipe($i);
                    if ($i === 3) $this->eventsEnded();
                    $active = true;
                }
                continue;
            }
            $active = true;
            if ($i === 3) $this->ingest($d);
            else $this->capture($i, $d);
        }
        foreach ($write as $s) {
            $i = array_search($s, $this->pipes, true);
            if ($i === 0) $active = $this->writeStdin() || $active;
            elseif ($i === 4) $active = $this->flushAcks() || $active;
        }
        return $active;
    }

    public function nextLine()
    {
        if (!$this->lines) return null;
        $l = array_shift($this->lines);
        if ($l === false) throw new PNS_ProtocolError($this->broken);
        return $l;
    }

    public function send($line)
    {
        if (!isset($this->pipes[4])) { $this->ackFailed = true; return; }
        if (strlen($this->acks) + strlen($line) > PNS::ACK_BUFFER_MAX) throw new PNS_ProtocolError('ack-backpressure');
        $this->acks .= $line;
        $this->flushAcks();
    }

    public function received() { return $this->received; }
    public function ackFailed() { return $this->ackFailed; }
    public function eventsClosed() { return !isset($this->pipes[3]); }

    public function running()
    {
        if (!$this->alive) return false;
        $s = is_resource($this->proc) ? @proc_get_status($this->proc) : false;
        if (!$s || !$s['running']) {
            $this->alive = false;
            if ($s) $this->exit = $s['signaled'] ? -(int)$s['termsig'] : (int)$s['exitcode'];
        }
        return $this->alive;
    }

    public function exitCode()
    {
        $this->running();
        return $this->exit;
    }

    /** Read what is left after exit; bounded in real time. */
    public function drain()
    {
        $end = hrtime(true) + 1000000000;
        while ((isset($this->pipes[1]) || isset($this->pipes[2]) || isset($this->pipes[3])) && hrtime(true) < $end) $this->pump(0.01);
    }

    public function terminate()
    {
        if ($this->running()) {
            @proc_terminate($this->proc, 9);
            $end = hrtime(true) + 2000000000;
            while ($this->running() && hrtime(true) < $end) usleep(5000);
        }
        $this->drain();
        $this->close();
    }

    public function close()
    {
        foreach (array_keys($this->pipes) as $i) $this->closePipe($i);
        if (is_resource($this->proc)) {
            if ($this->running()) {
                @proc_terminate($this->proc, 9);
                $end = hrtime(true) + 2000000000;
                while ($this->running() && hrtime(true) < $end) usleep(5000);
            }
            $code = @proc_close($this->proc);
            if ($this->exit === null && $code !== -1) $this->exit = $code;
        }
        $this->proc = null;
    }

    /** Sizes, truncation and a digest only; raw output is never retained. */
    public function captureSummary()
    {
        $out = [];
        foreach ([1 => 'stdout', 2 => 'stderr'] as $i => $name) {
            $c = $this->cap[$i];
            $out[$name] = ['bytes' => $c['bytes'], 'captured' => $c['captured'], 'truncated' => $c['truncated'], 'sha256' => hash_final(hash_copy($c['hash']))];
        }
        return $out;
    }

    private function capture($i, $d)
    {
        $c = &$this->cap[$i];
        $c['bytes'] += strlen($d);
        $room = PNS::CAPTURE_MAX - $c['captured'];
        if ($room > 0) {
            $part = strlen($d) > $room ? substr($d, 0, $room) : $d;
            hash_update($c['hash'], $part);
            $c['captured'] += strlen($part);
        }
        if (strlen($d) > $room) $c['truncated'] = true;
    }

    private function ingest($d)
    {
        if ($this->broken !== null) return;
        $this->buf .= $d;
        while (($p = strpos($this->buf, "\n")) !== false) {
            if ($p + 1 > PNS::EVENT_MAX) { $this->breakChannel('event-oversized'); return; }
            $this->lines[] = substr($this->buf, 0, $p);
            $this->received++;
            $this->buf = (string)substr($this->buf, $p + 1);
        }
        if (strlen($this->buf) >= PNS::EVENT_MAX) $this->breakChannel('event-oversized');
    }

    private function breakChannel($code)
    {
        $this->broken = $code;
        $this->buf = '';
        $this->lines[] = false;
    }

    private function eventsEnded()
    {
        if ($this->broken === null && $this->buf !== '') $this->breakChannel('event-truncated');
    }

    private function writeStdin()
    {
        $n = @fwrite($this->pipes[0], substr($this->stdin, $this->stdinOff, 65536));
        if ($n === false) {
            $this->closePipe(0);
            $this->stdin = '';
            return true;
        }
        $this->stdinOff += $n;
        if ($this->stdinOff >= strlen($this->stdin)) {
            $this->closePipe(0);
            $this->stdin = '';
        }
        return $n > 0;
    }

    private function flushAcks()
    {
        if ($this->acks === '' || !isset($this->pipes[4])) return false;
        $n = @fwrite($this->pipes[4], $this->acks);
        if ($n === false) {
            $this->ackFailed = true;
            $this->acks = '';
            $this->closePipe(4);
            return true;
        }
        $this->acks = (string)substr($this->acks, $n);
        return $n > 0;
    }

    private function closePipe($i)
    {
        if (isset($this->pipes[$i])) {
            @fclose($this->pipes[$i]);
            unset($this->pipes[$i]);
        }
    }
}

final class PNS_WorkerState
{
    public $role;
    public $token;
    public $proc;
    public $expect = 1;
    public $out = 0;
    public $ordinal = 0;
    public $mark = 0;
    public $conn = null;
    public $serial = 0;
    public $state = 'starting';
    public $stopping = false;
    public $inflight = null;
    public $deadline = 0.0;
    public $windowEnd = 0.0;
    public $tables = [];
    public $dropped = [];
    public $eofAt = null;
}

final class PNS_Supervisor
{
    const LIMIT_DEFAULTS = ['chunk_seconds' => PNS::CHUNK_SECONDS, 'cleanup_seconds' => PNS::CLEANUP_SECONDS, 'max_select' => PNS::MAX_SELECT, 'max_other' => PNS::MAX_OTHER];

    private $control;
    private $clock;
    private $hooks;
    private $spec;
    private $limits;
    private $nonce;
    private $t0;
    private $status;
    private $findings;
    private $log;
    private $logDropped;
    private $counters;
    private $statements;
    private $statementsDropped;
    private $ledger;
    private $chunkDir;
    private $kills;
    private $unresolved;
    private $captures;
    private $caps;
    private $gaps;
    /** >0 while a cancellation/confirmation or the cleanup stage may draw on the reserved "other" statements. */
    private $reserved = 0;
    /** Consumptions of spec expect_error (accepted at most once). */
    private $expectedErrors = 0;
    /** @var PNS_WorkerState|null */
    private $w;

    /**
     * $hooks are test injection points only: 'event' => fn(array $msg, PNS_Clock $c) before an event is handled,
     * 'before_reply' => fn(string $type, PNS_Clock $c) before a reply is written.
     */
    public function __construct(PNS_Control $control, ?PNS_Clock $clock = null, array $hooks = [])
    {
        foreach ($hooks as $k => $h) {
            if (!in_array($k, ['event', 'before_reply'], true) || !is_callable($h)) throw new InvalidArgumentException('hook');
        }
        $this->control = $control;
        $this->clock = $clock ?: new PNS_MonotonicClock();
        $this->hooks = $hooks;
    }

    /**
     * Supervise one chunk: preflight, one workload worker, then (if anything is owned and every
     * statement end was confirmed) one fresh cleanup worker, residue check and evidence.
     * Returns ['status','findings','counters','kills','statements','ledger','recovery','captures','caps','gaps','evidence_dir'].
     * counters: select (workload SELECT/ANALYZE), other (all setup/diagnostic/control/session SQL, capped at max_other
     * including a reserve for cancellation and cleanup), control and session (parts of other), cleanup (DROPs).
     */
    public function run(array $spec)
    {
        $this->status = 0;
        $this->findings = [];
        $this->log = [];
        $this->logDropped = 0;
        // other = workload setup/diagnostic statements + control/observer SQL + worker session SQL (control and session are its parts)
        $this->counters = ['select' => 0, 'other' => 0, 'control' => 0, 'session' => 0, 'cleanup' => 0];
        $this->gaps = [];
        $this->reserved = 0;
        $this->statements = [];
        $this->statementsDropped = 0;
        $this->ledger = null;
        $this->chunkDir = null;
        $this->kills = 0;
        $this->unresolved = false;
        $this->captures = [];
        $this->caps = [];
        $this->w = null;
        $this->spec = null;
        $this->limits = null;
        $this->expectedErrors = 0;
        $this->nonce = bin2hex(random_bytes(16));
        $this->t0 = $this->clock->now();
        try {
            $this->spec = self::validateSpec($spec);
        } catch (InvalidArgumentException $e) {
            $this->finding('invalid-spec:' . $e->getMessage(), 1);
            return $this->result(false);
        }
        $this->limits = $this->spec['limits'];
        try {
            PNS_Files::privateDir($this->spec['run_dir']);
            PNS_Files::privateDir($this->spec['run_dir'] . '/chunks');
            $dir = $this->spec['run_dir'] . '/chunks/' . $this->spec['chunk_id'];
            PNS_Files::privateDir($dir);
            $this->ledger = PNS_Ledger::create($dir . '/ledger.json', $this->spec['fixture_prefix'], $this->spec['shop_prefix'], $this->clock);
            $this->chunkDir = $dir;
        } catch (PNS_LedgerExists $e) {
            $this->finding('ledger-exists', 1);   // an earlier ledger is never overwritten
            return $this->result(false);
        } catch (RuntimeException $e) {
            $this->finding('run-dir-unusable', 2);
            return $this->result(false);
        }
        try {
            $this->execute();
        } catch (PNS_LedgerError $e) {
            $this->finding('ledger-write-failed', 4);
            $this->abandonIfRunning();
        } catch (Throwable $e) {
            $this->finding('supervisor-internal', 1);
            $this->abandonIfRunning();
        }
        return $this->result(true);
    }

    public static function validateSpec(array $s)
    {
        $req = ['run_dir', 'chunk_id', 'build', 'db_prefix', 'shop_prefix', 'fixture_prefix', 'workload_source', 'cleanup_source', 'manifest'];
        foreach ($req as $k) if (!array_key_exists($k, $s)) throw new InvalidArgumentException('missing-' . $k);
        if (array_diff(array_keys($s), array_merge($req, ['env', 'limits', 'expect_error']))) throw new InvalidArgumentException('unknown-key');
        // At most ONE exact expected server error: one measure SELECT digest with one server errno, consumable once.
        $x = $s['expect_error'] ?? null;
        if ($x !== null) {
            if (!is_array($x) || array_keys($x) !== ['phase', 'kind', 'digest', 'errno'] || $x['phase'] !== 'measure' || $x['kind'] !== 'select'
                || !is_string($x['digest']) || !preg_match('/^[0-9a-f]{64}$/D', $x['digest']) || !is_int($x['errno']) || $x['errno'] < 1000 || $x['errno'] > 1999
                || in_array($x['errno'], [1317, 3024, 1028], true)) throw new InvalidArgumentException('expect-error');
        }
        $s['expect_error'] = $x;
        if (!is_string($s['run_dir']) || $s['run_dir'] === '' || $s['run_dir'][0] !== '/' || strpos($s['run_dir'], "\0") !== false
            || in_array('..', explode('/', $s['run_dir']), true)) throw new InvalidArgumentException('run-dir');
        foreach (['chunk_id', 'build'] as $k) {
            if (!is_string($s[$k]) || !preg_match('/^[a-z0-9][a-z0-9-]{0,31}$/', $s[$k])) throw new InvalidArgumentException($k);
        }
        foreach (['db_prefix', 'shop_prefix'] as $k) {
            if (!is_string($s[$k]) || !preg_match('/^[a-z0-9_]{0,32}$/', $s[$k])) throw new InvalidArgumentException($k);
        }
        if (!PNS_Ledger::validPrefix($s['fixture_prefix'], $s['shop_prefix'])) throw new InvalidArgumentException('fixture-prefix');
        foreach (['workload_source', 'cleanup_source'] as $k) {
            if (!is_string($s[$k]) || strncmp($s[$k], '<?php', 5) !== 0 || strlen($s[$k]) > PNS::SOURCE_MAX) throw new InvalidArgumentException($k);
        }
        $j = is_array($s['manifest']) ? json_encode($s['manifest']) : false;
        if ($j === false || strlen($j) > PNS::MANIFEST_MAX) throw new InvalidArgumentException('manifest');
        $env = isset($s['env']) ? $s['env'] : [];
        if (!is_array($env)) throw new InvalidArgumentException('env');
        $env += ['PATH' => '/usr/bin:/bin'];
        foreach ($env as $k => $v) {
            if (!is_string($k) || !preg_match('/^(PATH|TMPDIR|LANG|LC_ALL|SEO_PRO_[A-Z0-9_]+)$/', $k) || preg_match('/PASS|SECRET|TOKEN|PWD|KEY/', $k)
                || !is_string($v) || strlen($v) > 4096 || preg_match('/[\x00\r\n]/', $v)) throw new InvalidArgumentException('env');
        }
        $s['env'] = $env;
        $limits = self::LIMIT_DEFAULTS;
        $given = isset($s['limits']) ? $s['limits'] : [];
        if (!is_array($given)) throw new InvalidArgumentException('limits');
        foreach ($given as $k => $v) {
            // limits may only be lowered (remaining global budget); never widened
            if (!isset($limits[$k]) || !(is_int($v) || is_float($v)) || $v <= 0 || $v > $limits[$k]) throw new InvalidArgumentException('limits');
            $limits[$k] = is_int(self::LIMIT_DEFAULTS[$k]) ? (int)$v : (float)$v;
        }
        $s['limits'] = $limits;
        return $s;
    }

    private function execute()
    {
        try {
            $caps = $this->control('preflight');
        } catch (PNS_ControlBudget $e) {
            return;   // other-budget already recorded
        } catch (PNS_ControlError $e) {
            $this->finding('preflight-control-unavailable', 2);
            return;
        }
        foreach (is_array($caps) ? $caps : [] as $k => $v) {
            if (is_string($k) && preg_match('/^[a-z_]{1,32}$/', $k) && is_scalar($v)) $this->caps[$k] = substr(preg_replace('/[^0-9A-Za-z.=+_\- ]/', '', (string)$v), 0, 80);
        }
        if (($this->caps['select_limit'] ?? 'unsupported') === 'unsupported') {
            // Control SELECTs stay bounded by the 2 s read timeout only; reported, never presented as a verified limit.
            $this->gaps[] = 'control-select-limit-unsupported';
            $this->log(['type' => 'LIMIT', 'what' => 'control-select-limit-unsupported']);
        }
        if (!is_string(PHP_BINARY) || PHP_BINARY === '' || !function_exists('proc_open')) {
            $this->finding('process-unavailable', 2);
            return;
        }
        $this->superviseWorker('workload', $this->spec['workload_source'], $this->t0 + $this->limits['chunk_seconds'], []);
        $this->cleanupAndVerify();
    }

    private function cleanupAndVerify()
    {
        if ($this->unresolved) {
            $this->finding('cleanup-skipped-unconfirmed-statement', 4);
        } else {
            // One cleanup window covers the cleanup worker AND the residue verification; nothing is verified after it.
            $end = $this->now() + $this->limits['cleanup_seconds'];
            $this->reserved++;
            try {
                $drop = $this->ledger->droppable();
                if ($drop) $this->superviseWorker('cleanup', $this->spec['cleanup_source'], $end, $drop);
                if (!$this->unresolved) {
                    try {
                        foreach ($this->ledger->entries() as $name => $e) {
                            if (!in_array($e['state'], ['dropped', 'confirmed-created', 'tainted'], true)) continue;
                            if ($this->now() + PNS::CONTROL_READ_TIMEOUT > $end) { $this->finding('residue-unverified-window', 4); break; }
                            $exists = $this->control('tableExists', $name);
                            if ($this->now() > $end) { $this->finding('residue-unverified-window', 4); break; }   // late answer not used
                            if ($e['state'] === 'dropped' && $exists) $this->finding('fixture-residue', 4);
                            if ($e['state'] !== 'dropped' && !$exists) $this->finding('fixture-state-mismatch', 4);
                        }
                    } catch (PNS_ControlError $e) {
                        $this->finding('residue-unverified', 4);
                    }
                }
            } finally {
                $this->reserved--;
            }
        }
        $this->ledger->finalizeUnsubmitted();
        if ($this->ledger->recoveryItems()) $this->finding('recovery-items', 4);
    }

    private function result($write)
    {
        $r = [
            'status' => $this->status, 'findings' => $this->findings, 'counters' => $this->counters, 'kills' => $this->kills,
            'statements' => $this->statements, 'ledger' => $this->ledger ? $this->ledger->entries() : [],
            'recovery' => $this->ledger ? $this->ledger->recoveryItems() : [], 'captures' => $this->captures, 'caps' => $this->caps,
            'gaps' => $this->gaps, 'evidence_dir' => null, 'limits' => $this->limits,
            'expected_error' => ['permitted' => is_array($this->spec['expect_error'] ?? null), 'consumed' => $this->expectedErrors],
        ];
        if (!$write || $this->chunkDir === null) return $r;
        $gaps = $this->gaps !== [];
        foreach ($this->statements as $st) if (isset($st['metrics']) && $st['metrics'] === 'unmeasured') $gaps = true;
        try {
            $events = '';
            foreach ($this->log as $rec) $events .= json_encode($rec, JSON_UNESCAPED_SLASHES) . "\n";
            if ($this->logDropped) $events .= json_encode(['type' => 'LIMIT', 'what' => 'events', 'dropped' => $this->logDropped]) . "\n";
            PNS_Files::atomicWrite($this->chunkDir . '/events.jsonl', $events);
            $metrics = [
                'version' => 1, 'chunk' => $this->spec['chunk_id'], 'build' => $this->spec['build'], 'db_prefix' => $this->spec['db_prefix'],
                'status' => $r['status'], 'findings' => $r['findings'], 'counters' => $r['counters'], 'kills' => $r['kills'],
                'limits' => $this->limits + ['statement_seconds' => PNS::STATEMENT_LIMITS, 'confirm_seconds' => PNS::CONFIRM_WINDOW,
                    'connect_timeout' => PNS::CONNECT_TIMEOUT, 'control_read_timeout' => PNS::CONTROL_READ_TIMEOUT,
                    'read_timeout_setup' => PNS::READ_TIMEOUT_SETUP, 'read_timeout_statement' => PNS::READ_TIMEOUT_STATEMENT,
                    'event_bytes' => PNS::EVENT_MAX, 'capture_bytes' => PNS::CAPTURE_MAX],
                'statements' => $r['statements'], 'statements_dropped' => $this->statementsDropped, 'measurement_gaps' => $gaps, 'gaps' => $r['gaps'],
                'captures' => $r['captures'], 'caps' => $r['caps'], 'recovery' => (object)$r['recovery'], 'expected_error' => $r['expected_error'],
                'sources' => ['workload_sha256' => hash('sha256', $this->spec['workload_source']), 'cleanup_sha256' => hash('sha256', $this->spec['cleanup_source'])],
                'env_keys' => array_keys($this->spec['env']),
                'note' => 'supervisor/IPC instrumented timings; censored statements are not latency samples',
            ];
            PNS_Files::atomicWrite($this->chunkDir . '/metrics.json', json_encode($metrics, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
            $r['evidence_dir'] = $this->chunkDir;
        } catch (RuntimeException $e) {
            $this->finding('evidence-write-failed', 4);
            $r['status'] = $this->status;
            $r['findings'] = $this->findings;
        }
        return $r;
    }

    private function finding($code, $status)
    {
        if (!isset($this->findings[$code])) $this->findings[$code] = ['status' => $status, 'count' => 0];
        $this->findings[$code]['count']++;
        $this->status = PNS::worse($this->status, $status);
        $this->log(['type' => 'FINDING', 'code' => $code, 'status' => $status]);
    }

    private function log(array $rec)
    {
        if (count($this->log) >= PNS::EVIDENCE_EVENTS_MAX) { $this->logDropped++; return; }
        $this->log[] = ['t_ms' => $this->ms($this->now())] + $rec;
    }

    private function ms($t)
    {
        return $t === null ? null : round(($t - $this->t0) * 1000, 3);
    }

    private function now()
    {
        return $this->clock->now();
    }

    /** "other" statements held back for cancellation/confirmation and the cleanup stage. */
    private function reserve()
    {
        return PNS::RESERVE_BASE + ($this->ledger ? count($this->ledger->entries()) : 0);
    }

    /**
     * Highest "other" count an operation may reach. Outside the reserve, SESSION_SQL_MAX more is kept free so a
     * worker connection opened after any granted reply still fits below max_other - reserve.
     */
    private function room($reserved)
    {
        return $this->limits['max_other'] - ($reserved ? 0 : $this->reserve() + PNS::SESSION_SQL_MAX);
    }

    private function inReserve()
    {
        return $this->reserved > 0 || ($this->w !== null && $this->w->role === 'cleanup');
    }

    /**
     * Every control/observer call is charged as the SQL it actually ran ("other" + "control"), refused
     * beforehand when its upper bound would pass the cap, and drawn from the reserve only during
     * cancellation/confirmation/cleanup.
     */
    private function control($method)
    {
        $args = array_slice(func_get_args(), 1);
        $bound = $method === 'preflight' ? PNS::PREFLIGHT_SQL_MAX : 1;
        $reserved = $this->inReserve();
        if ($this->counters['other'] + $bound > $this->room($reserved)) {
            $this->finding($reserved ? 'control-reserve-exhausted' : 'other-budget', $reserved ? 4 : 3);
            throw new PNS_ControlBudget('control-budget');
        }
        $before = $this->controlCount();
        try {
            $r = call_user_func_array([$this->control, $method], $args);
        } catch (PNS_ControlError $e) {
            $this->charge($before, $bound);
            throw $e;
        } catch (Throwable $e) {
            $this->charge($before, $bound);
            throw new PNS_ControlError('control-exception');
        }
        $this->charge($before, $bound);
        return $r;
    }

    private function controlCount()
    {
        try {
            $n = $this->control->statementCount();
        } catch (Throwable $e) {
            return null;
        }
        return is_int($n) && $n >= 0 ? $n : null;
    }

    private function charge($before, $bound)
    {
        $after = $this->controlCount();
        $n = ($before === null || $after === null || $after < $before) ? $bound : max(1, $after - $before);
        if ($n > $bound) $this->finding('control-cost-unbounded', 1);
        $this->counters['other'] += $n;
        $this->counters['control'] += $n;
    }

    private static function command()
    {
        return [PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'log_errors=0', '-d', 'html_errors=0', '-d', 'mysqli.reconnect=0', '-d', 'max_execution_time=0'];
    }

    private function superviseWorker($role, $source, $windowEnd, array $tables)
    {
        $token = bin2hex(random_bytes(16));
        $boot = [
            'v' => PNS::PROTOCOL, 'nonce' => $this->nonce, 'token' => $token, 'role' => $role, 'chunk' => $this->spec['chunk_id'],
            'build' => $this->spec['build'], 'db_prefix' => $this->spec['db_prefix'], 'manifest' => $this->spec['manifest'],
            'limits' => ['connect' => PNS::CONNECT_TIMEOUT, 'read_setup' => PNS::READ_TIMEOUT_SETUP, 'read_statement' => PNS::READ_TIMEOUT_STATEMENT,
                'ack_hello' => PNS::ACK_HELLO, 'ack_other' => PNS::ACK_OTHER],
        ];
        $payload = "<?php define('PNS_BOOT', '" . base64_encode(json_encode($boot)) . "'); ?>\n" . $source;
        $proc = PNS_Process::start(self::command(), $this->spec['env'], $payload);
        if (!$proc) {
            $this->finding($role === 'cleanup' ? 'cleanup-process-unavailable' : 'process-unavailable', $role === 'cleanup' ? 4 : 2);
            return;
        }
        $w = new PNS_WorkerState();
        $w->role = $role;
        $w->token = $token;
        $w->proc = $proc;
        $w->tables = $tables;
        $w->windowEnd = $windowEnd;
        $w->deadline = $this->now() + PNS::STARTUP_SECONDS;   // past the window, HELLO is answered with STOP
        $this->w = $w;
        $this->log(['type' => 'SPAWN', 'role' => $role]);
        while ($w->state !== 'terminated') {
            $this->drainEvents();
            if ($w->state === 'terminated') break;
            $now = $this->now();
            if ($w->state === 'inflight') {
                $st = $w->inflight;
                if ($st['cancel'] === null && $now >= $st['deadline']) {
                    $this->cancelInflight('deadline');
                    if ($w->inflight['cancel'] !== 'confirmed') { $this->abandonWorker(); break; }
                    continue;
                }
                if ($st['cancel'] === 'confirmed' && $now >= $st['end_wait']) {
                    $this->finding('end-missing-after-cancel', 1);
                    $this->abandonWorker();
                    break;
                }
            } elseif ($w->state === 'starting') {
                if ($now >= $w->deadline) {
                    $this->finding('hello-timeout', 4);
                    $this->abandonWorker();
                    break;
                }
            } elseif ($w->state === 'free') {
                if ($now >= $w->windowEnd) {
                    if (!$w->stopping) { $this->windowFinding(); $w->stopping = true; }
                    if ($now >= $w->windowEnd + PNS::EXIT_GRACE) {
                        $this->finding('worker-unresponsive', 3);
                        $this->abandonWorker();
                        break;
                    }
                }
            } elseif ($now >= $w->deadline) {
                $this->finding($w->state === 'done' ? 'worker-exit-timeout' : 'worker-ignored-stop', 1);
                $this->abandonWorker();
                break;
            }
            if (!$w->proc->running()) {
                $w->proc->drain();
                $this->drainEvents();
                if ($w->state !== 'terminated') $this->workerExited();
                break;
            }
            if ($w->proc->ackFailed()) {
                $this->finding('ack-channel-broken', 1);
                $this->abandonWorker();
                break;
            }
            if ($w->proc->eventsClosed() && $w->state !== 'done') {
                if ($w->eofAt === null) $w->eofAt = $now;
                elseif ($now - $w->eofAt >= PNS::EXIT_GRACE) {
                    $this->finding('event-channel-closed', 1);
                    $this->abandonWorker();
                    break;
                }
            }
            if (!$w->proc->pump($this->clock->pollInterval())) $this->clock->idle();
        }
        $this->w = null;
    }

    private function windowFinding()
    {
        if ($this->w->role === 'cleanup') $this->finding('cleanup-window-exhausted', 4);
        else $this->finding('chunk-time-budget', 3);
    }

    private function drainEvents()
    {
        $w = $this->w;
        while ($w->state !== 'terminated') {
            try {
                $line = $w->proc->nextLine();
                if ($line === null) return;
                $this->handleLine($line);
            } catch (PNS_ProtocolError $e) {
                $this->violation($e->getMessage());
            } catch (PNS_ControlBudget $e) {
                // refused before it ran (finding recorded); no statement is in flight at HELLO/REGISTER time
                $w->stopping = true;
                if ($w->inflight !== null && !$this->unresolved) {
                    $this->finding('control-failure', 4);
                    $this->unresolved = true;
                }
                $this->abandonWorker();
            } catch (PNS_ControlError $e) {
                $this->finding('control-failure', 4);
                $w->stopping = true;
                $this->abandonWorker();
            } catch (PNS_LedgerError $e) {
                $this->finding('ledger-write-failed', 4);
                $this->abandonWorker();
            }
        }
    }

    private function violation($code)
    {
        $this->finding(is_string($code) && $code !== '' ? $code : 'protocol-violation', 1);
        $w = $this->w;
        $w->stopping = true;
        if ($w->inflight !== null && $w->inflight['end_at'] === null && $w->inflight['cancel'] === null) $this->cancelInflight('protocol');
        $this->abandonWorker();
    }

    private function handleLine($line)
    {
        $w = $this->w;
        $w->ordinal++;
        if ($w->ordinal <= $w->mark) throw new PNS_ProtocolError('event-before-reply');
        $m = json_decode($line, true, 8);
        if (!is_array($m)) throw new PNS_ProtocolError('event-malformed');
        foreach (PNS::ENVELOPE as $k) if (!array_key_exists($k, $m)) throw new PNS_ProtocolError('event-envelope');
        if ($m['v'] !== PNS::PROTOCOL) throw new PNS_ProtocolError('event-version');
        if (!is_string($m['nonce']) || !hash_equals($this->nonce, $m['nonce']) || !is_string($m['token']) || !hash_equals($w->token, $m['token'])) {
            throw new PNS_ProtocolError('event-identity');
        }
        if ($m['seq'] !== $w->expect) throw new PNS_ProtocolError('event-sequence');
        $w->expect++;
        if (!is_string($m['type']) || !array_key_exists($m['type'], PNS::EVENT_KEYS)) throw new PNS_ProtocolError('event-type');
        $have = array_keys($m);
        $want = array_merge(PNS::ENVELOPE, PNS::EVENT_KEYS[$m['type']]);
        sort($have);
        sort($want);
        if ($have !== $want) throw new PNS_ProtocolError('event-fields');
        self::checkFields($m);
        $safe = array_intersect_key($m, array_flip(['type', 'seq', 'phase', 'conn', 'role', 'kind', 'digest', 'table', 're', 'ok', 'errno', 'duration_ns', 'rows', 'affected', 'reason']));
        $this->log(['dir' => 'in', 'worker' => $w->role] + $safe);
        if (isset($this->hooks['event'])) call_user_func($this->hooks['event'], $safe, $this->clock);
        switch ($m['type']) {
            case 'HELLO': $this->onHello($m); break;
            case 'REGISTER_CREATE': $this->onRegister($m); break;
            case 'BEGIN': $this->onBegin($m); break;
            case 'END': $this->onEnd($m); break;
            case 'DONE': $this->onDone($m); break;
        }
    }

    private static function checkFields(array $m)
    {
        $phase = ['HELLO' => 'hello', 'REGISTER_CREATE' => 'setup', 'DONE' => 'stop'];
        if (isset($phase[$m['type']]) ? $m['phase'] !== $phase[$m['type']] : !(is_string($m['phase']) && array_key_exists($m['phase'], PNS::PHASE_KINDS))) {
            throw new PNS_ProtocolError('event-phase');
        }
        $ok = true;
        foreach ($m as $k => $v) {
            switch ($k) {
                case 'conn': case 're': $ok = is_int($v) && $v > 0; break;
                case 'conn_id': $ok = is_int($v) && $v > 0; break;
                case 'errno': case 'duration_ns': case 'rows': case 'affected': $ok = is_int($v) && $v >= 0; break;
                case 'role': $ok = in_array($v, ['setup', 'measure', 'cleanup'], true); break;
                case 'build': case 'db_prefix': $ok = is_string($v) && strlen($v) <= 32; break;
                case 'connect_timeout': case 'read_timeout': $ok = is_int($v) || is_float($v); break;
                case 'reconnect': case 'ok': $ok = is_bool($v); break;
                case 'lock_wait_timeout': $ok = $v === null || is_int($v); break;
                case 'session_sql': $ok = is_int($v) && $v >= 0 && $v <= PNS::SESSION_SQL_MAX; break;
                case 'table': $ok = $v === null || PNS::tableName($v); break;
                case 'kind': $ok = is_string($v) && in_array($v, ['create', 'write', 'setup', 'select', 'explain', 'analyze', 'drop'], true); break;
                case 'digest': $ok = is_string($v) && preg_match('/^[0-9a-f]{64}$/', $v) === 1; break;
                case 'ddl_head': $ok = $v === null || (is_string($v) && strlen($v) <= 200 && preg_match('/^[\x20-\x7e]*$/', $v) === 1); break;
                case 'reason': $ok = in_array($v, ['done', 'stopped', 'error'], true); break;
            }
            if (!$ok) throw new PNS_ProtocolError('event-field-' . $k);
        }
    }

    private function reply($type, $phase, $re, array $fields)
    {
        $w = $this->w;
        $w->proc->pump(0);   // collect anything already sent, so a premature event is detected
        if (isset($this->hooks['before_reply'])) call_user_func($this->hooks['before_reply'], $type, $this->clock);
        $w->out++;
        $msg = ['v' => PNS::PROTOCOL, 'nonce' => $this->nonce, 'token' => $w->token, 'seq' => $w->out, 'phase' => $phase, 'type' => $type, 're' => $re] + $fields;
        $w->proc->send(json_encode($msg, JSON_UNESCAPED_SLASHES) . "\n");
        $w->mark = $w->proc->received();
        $rec = ['dir' => 'out', 'worker' => $w->role, 'type' => $type, 'phase' => $phase, 're' => $re];
        if (isset($fields['decision'])) $rec['decision'] = $fields['decision'];
        if (isset($fields['tables'])) $rec['tables'] = count($fields['tables']);
        $this->log($rec);
    }

    /** Reply STOP instead of the requested acknowledgement when dispatch must end. */
    private function stopNow($re)
    {
        $w = $this->w;
        if (!$w->stopping && $this->now() >= $w->windowEnd) {
            $this->windowFinding();
            $w->stopping = true;
        }
        if (!$w->stopping) return false;
        $this->reply('STOP', 'stop', $re, ['reason' => 'stop']);
        $w->state = 'stopped';
        $w->deadline = $this->now() + PNS::EXIT_GRACE;
        return true;
    }

    private function onHello(array $m)
    {
        $w = $this->w;
        if ($w->state !== 'starting' && $w->state !== 'free') throw new PNS_ProtocolError('hello-out-of-order');
        $allowed = $w->role === 'cleanup' ? ['cleanup'] : ['setup', 'measure'];
        if (!in_array($m['role'], $allowed, true)) throw new PNS_ProtocolError('hello-role');
        if ($m['conn'] !== $w->serial + 1) throw new PNS_ProtocolError('hello-connection-serial');
        $read = $m['role'] === 'setup' ? PNS::READ_TIMEOUT_SETUP : PNS::READ_TIMEOUT_STATEMENT;
        if (!PNS::same($m['connect_timeout'], PNS::CONNECT_TIMEOUT) || !PNS::same($m['read_timeout'], $read) || $m['reconnect'] !== false) {
            throw new PNS_ProtocolError('hello-connection-options');
        }
        if ($m['role'] === 'cleanup' ? $m['lock_wait_timeout'] !== PNS::LOCK_WAIT_TIMEOUT : $m['lock_wait_timeout'] !== null) {
            throw new PNS_ProtocolError('hello-lock-wait');
        }
        if ($m['build'] !== $this->spec['build'] || $m['db_prefix'] !== $this->spec['db_prefix']) throw new PNS_ProtocolError('hello-identity');
        // A new HELLO means the previous connection was closed: its identity is dead for good.
        $w->conn = null;
        $w->serial = $m['conn'];
        // The driver's connection bootstrap SQL already ran; it is charged here and fits the headroom kept by room().
        $this->counters['other'] += $m['session_sql'];
        $this->counters['session'] += $m['session_sql'];
        if ($this->counters['other'] > $this->room(true) - ($this->inReserve() ? 0 : $this->reserve())) {
            $this->finding($this->inReserve() ? 'control-reserve-exhausted' : 'other-budget', $this->inReserve() ? 4 : 3);
            $this->abandonWorker();
            return;
        }
        $marker = $w->token . ':' . $m['conn'];
        $tid = $this->control('verifyIdentity', $m['conn_id'], $marker);
        if (!is_int($tid)) {
            $this->finding('identity-unverified', 2);
            $this->abandonWorker();
            return;
        }
        if ($m['role'] === 'cleanup' && $this->control('sessionLockWait', $tid) !== PNS::LOCK_WAIT_TIMEOUT) {
            $this->finding('cleanup-lock-wait-unverified', 4);
            $this->abandonWorker();
            return;
        }
        $w->conn = ['serial' => $m['conn'], 'role' => $m['role'], 'conn_id' => $m['conn_id'], 'thread_id' => $tid, 'marker' => $marker, 'valid' => true];
        if ($this->stopNow($m['seq'])) return;
        $this->reply('HELLO_ACK', 'hello', $m['seq'], []);
        $w->state = 'free';
        if ($m['role'] === 'cleanup') $this->reply('CLEANUP', 'cleanup', null, ['tables' => array_values($w->tables)]);
    }

    private function onRegister(array $m)
    {
        $w = $this->w;
        $c = $w->conn;
        if ($w->state !== 'free') throw new PNS_ProtocolError('register-out-of-order');
        if (!$c || !$c['valid'] || $c['role'] !== 'setup' || $m['conn'] !== $c['serial']) throw new PNS_ProtocolError('register-connection');
        if (!$this->ledger->validName($m['table'])) throw new PNS_ProtocolError('register-name');
        if ($this->stopNow($m['seq'])) return;
        $reject = null;
        if ($this->ledger->has($m['table'])) $reject = 'duplicate-fixture-name';
        elseif ($this->control('tableExists', $m['table'])) $reject = 'fixture-name-collision';
        if ($reject !== null) {
            $this->finding($reject, 1);
            $w->stopping = true;
            $this->reply('ACK', 'setup', $m['seq'], ['decision' => 'reject']);
            return;
        }
        $this->ledger->register($m['table']);   // durable before the worker may submit CREATE
        $this->reply('ACK', 'setup', $m['seq'], ['decision' => 'accept']);
    }

    private function onBegin(array $m)
    {
        $w = $this->w;
        $c = $w->conn;
        if ($w->state !== 'free') throw new PNS_ProtocolError('begin-out-of-order');
        if (!$c || !$c['valid'] || $m['conn'] !== $c['serial']) throw new PNS_ProtocolError('begin-stale-connection');
        if (!in_array($m['phase'], PNS::ROLE_PHASES[$c['role']], true) || !in_array($m['kind'], PNS::PHASE_KINDS[$m['phase']], true)) {
            throw new PNS_ProtocolError('begin-phase-kind');
        }
        $t = $m['table'];
        if ($m['kind'] !== 'create' && $m['ddl_head'] !== null) throw new PNS_ProtocolError('begin-ddl-unexpected');
        switch ($m['kind']) {
            case 'create':
                if (!is_string($t) || $this->ledger->state($t) !== 'intended' || $this->ledger->submitted($t)) throw new PNS_ProtocolError('create-not-registered');
                if (!is_string($m['ddl_head']) || stripos($m['ddl_head'], 'CREATE TABLE `' . $t . '` ') !== 0 || stripos($m['ddl_head'], 'IF NOT EXISTS') !== false) {
                    throw new PNS_ProtocolError('create-statement-shape');
                }
                break;
            case 'write':
                if (!is_string($t) || $this->ledger->state($t) !== 'confirmed-created') throw new PNS_ProtocolError('write-not-owned');
                break;
            case 'drop':
                if ($w->role !== 'cleanup' || !is_string($t) || !in_array($t, $w->tables, true) || isset($w->dropped[$t])
                    || !in_array($this->ledger->state($t), PNS_Ledger::DROPPABLE, true)) throw new PNS_ProtocolError('drop-not-allowlisted');
                break;
            default:
                if ($t !== null) throw new PNS_ProtocolError('begin-table-unexpected');
        }
        if ($this->stopNow($m['seq'])) return;
        if ($w->role === 'cleanup') {
            // Never GO a cleanup statement whose cancellation request + confirmation + residue check would pass the window.
            if ($this->now() + PNS::STATEMENT_LIMITS['cleanup'] + PNS::CONFIRM_WINDOW + PNS::CONTROL_READ_TIMEOUT > $w->windowEnd) {
                $this->windowFinding();
                $w->stopping = true;
                $this->stopNow($m['seq']);
                return;
            }
            $counter = 'cleanup';
        } else {
            $counter = ($m['phase'] !== 'setup' && in_array($m['kind'], ['select', 'analyze'], true)) ? 'select' : 'other';
            $max = $counter === 'select' ? $this->limits['max_select'] : $this->room(false);
            if ($this->counters[$counter] + 1 > $max) {
                $this->finding($counter . '-budget', 3);
                $w->stopping = true;
                $this->stopNow($m['seq']);
                return;
            }
        }
        $this->counters[$counter]++;
        if ($m['kind'] === 'drop') $w->dropped[$t] = true;
        if ($m['kind'] === 'create') $this->ledger->markSubmitted($t);
        $now = $this->now();   // cancellation deadline starts immediately before GO
        $w->inflight = [
            'seq' => $m['seq'], 'phase' => $m['phase'], 'kind' => $m['kind'], 'digest' => $m['digest'], 'table' => $t, 'conn' => $c['serial'],
            'worker' => $w->role, 'go_at' => $now, 'deadline' => $now + PNS::STATEMENT_LIMITS[$m['phase']], 'cancel' => null, 'kill_at' => null,
            'confirmed_at' => null, 'end_at' => null, 'end_wait' => null,
        ];
        $this->reply('GO', $m['phase'], $m['seq'], []);
        $w->state = 'inflight';
    }

    private function onEnd(array $m)
    {
        $w = $this->w;
        if ($w->state !== 'inflight') throw new PNS_ProtocolError('end-out-of-order');
        $st = $w->inflight;
        if ($m['re'] !== $st['seq'] || $m['conn'] !== $st['conn'] || $m['phase'] !== $st['phase']) throw new PNS_ProtocolError('end-mismatch');
        if ($m['ok'] !== ($m['errno'] === 0)) throw new PNS_ProtocolError('end-result');
        $now = $this->now();
        $raced = $st['cancel'] !== null || $now >= $st['deadline'];
        $st += ['ok' => $m['ok'], 'errno' => $m['errno'], 'worker_duration_ns' => $m['duration_ns'], 'rows' => $m['rows'], 'affected' => $m['affected'], 'raced' => $raced];
        $st['end_at'] = $now;
        $w->inflight = $st;
        if ($raced && $st['cancel'] === null) {
            // END at the deadline boundary: censored, no KILL; confirm the server side is idle before deciding.
            $w->stopping = true;
            $this->finding('deadline-race-censored', 3);
            $c = $w->conn;
            try {
                $confirmed = $c && $c['valid'] && $this->confirmEnded($c['conn_id'], $c['thread_id'], $now);
            } catch (PNS_ControlError $e) {
                $confirmed = false;
            }
            if (!$confirmed) {
                $this->finding('race-end-unconfirmed', 4);
                $this->unresolved = true;
            }
        }
        $st['outcome'] = $raced ? 'censored' : ($m['ok'] ? 'ok' : 'error');
        $this->applyOutcome($st, $m['ok'], $m['errno'], $raced, false);
        if (!$raced && !$m['ok'] && !in_array($st['kind'], ['create', 'write', 'drop'], true)) {
            $x = $this->spec['expect_error'];
            $match = $x !== null && $w->role === 'workload' && $st['phase'] === $x['phase'] && $st['kind'] === $x['kind'] && hash_equals($x['digest'], (string)$st['digest']);
            if ($match && $m['errno'] === $x['errno'] && $this->expectedErrors === 0) {
                // The single permitted error: recorded, never a generic continue-on-error.
                $this->expectedErrors++;
                $st['outcome'] = 'expected-error';
                $this->log(['type' => 'EXPECTED_ERROR', 're' => $st['seq'], 'errno' => $m['errno']]);
            } else {
                if ($match) $this->finding($this->expectedErrors ? 'expected-error-repeated' : 'expected-error-errno-mismatch', 1);
                $this->finding('statement-error', 1);
                $w->stopping = true;
            }
        }
        if (!$raced && $m['ok'] && in_array($st['phase'], ['measure', 'diagnostic'], true)) {
            $st['metrics'] = 'unmeasured';
            try {
                $obs = $this->control('observeStatement', $w->conn['thread_id'], $st['conn'] . ':' . $st['seq']);
                if (is_array($obs) && isset($obs['rows_examined']) && is_int($obs['rows_examined'])) {
                    $st['metrics'] = ['rows_examined' => $obs['rows_examined']] + (isset($obs['rows_sent']) && is_int($obs['rows_sent']) ? ['rows_sent' => $obs['rows_sent']] : []);
                }
            } catch (PNS_ControlBudget $e) {
                $w->stopping = true;   // refused before running: stays 'unmeasured', other-budget recorded, dispatch ends
            } catch (PNS_ControlError $e) {
                $this->finding('observer-failure', 4);   // control bound expired: stop dispatch, cancellation state unknown
                $w->stopping = true;
            }
        }
        $this->recordStatement($st);
        $w->inflight = null;
        if ($this->unresolved) {
            $this->abandonWorker();
            return;
        }
        if (!$this->stopNow($m['seq'])) {
            $this->reply('ACK', $st['phase'], $m['seq'], ['decision' => 'continue']);
            $w->state = 'free';
        }
    }

    private function onDone(array $m)
    {
        $w = $this->w;
        if (!in_array($w->state, ['starting', 'free', 'stopped'], true)) throw new PNS_ProtocolError('done-out-of-order');
        if ($m['reason'] === 'error') $this->finding('worker-error', 1);
        if ($w->conn) $w->conn['valid'] = false;
        $w->state = 'done';
        $w->deadline = $this->now() + PNS::EXIT_GRACE;
    }

    private function cancelInflight($why)
    {
        $this->reserved++;
        try {
            $this->cancelInflightReserved($why);
        } finally {
            $this->reserved--;
        }
    }

    private function cancelInflightReserved($why)
    {
        $w = $this->w;
        $w->stopping = true;
        $w->inflight['cancel'] = 'requested';
        $w->inflight['cancel_reason'] = $why;
        $w->inflight['deadline_hit_at'] = $this->now();
        if ($why === 'deadline') $this->finding('deadline-cancel', 3);
        $c = $w->conn;
        try {
            if (!$c || !$c['valid']) {
                $this->finding('cancel-identity-invalid', 4);
            } else {
                // Re-verify the live mapping right before KILL; a reused id or a different thread gets no KILL.
                $tid = $this->control('verifyIdentity', $c['conn_id'], $c['marker']);
                if ($tid !== $c['thread_id']) {
                    $w->conn['valid'] = false;
                    $this->finding('identity-stale-no-kill', 4);
                } else {
                    $this->control('killQuery', $c['conn_id']);   // exactly once per statement, never retried
                    $this->kills++;
                    $w->inflight['kill_at'] = $this->now();
                    $this->log(['type' => 'KILL_QUERY', 're' => $w->inflight['seq'], 'why' => $why]);
                    if ($this->confirmEnded($c['conn_id'], $c['thread_id'], $w->inflight['kill_at'])) {
                        $w->inflight['cancel'] = 'confirmed';
                        $w->inflight['confirmed_at'] = $this->now();
                        $w->inflight['end_wait'] = $this->now() + PNS::ACK_OTHER;
                        $this->log(['type' => 'CANCEL_CONFIRMED', 're' => $w->inflight['seq']]);
                        return;
                    }
                    $this->finding('cancel-unconfirmed', 4);
                }
            }
        } catch (PNS_ControlError $e) {
            $this->finding('control-failure-during-cancel', 4);
        }
        $w->inflight['cancel'] = 'unresolved';
        $this->unresolved = true;
    }

    /**
     * Ended/idle confirmation strictly within CONFIRM_WINDOW of $from. A probe is started only while a whole
     * control read timeout still fits in the window, and an answer returned after the window is not accepted
     * (false: the caller reports unresolved and no cleanup follows). Probes are spaced by CONFIRM_POLL and drawn
     * from the reserve; pipes keep draining meanwhile.
     */
    private function confirmEnded($connId, $threadId, $from)
    {
        $end = $from + PNS::CONFIRM_WINDOW;
        $this->reserved++;
        try {
            while (true) {
                if ($end - $this->now() < PNS::CONTROL_READ_TIMEOUT) {
                    $this->log(['type' => 'CONFIRM_WINDOW_EXHAUSTED']);
                    return false;
                }
                $s = $this->control('statementState', $connId, $threadId);
                $at = $this->now();
                if ($at > $end) {
                    $this->log(['type' => 'CONFIRM_LATE', 'state' => is_string($s) ? substr($s, 0, 8) : null]);
                    return false;
                }
                if ($s === 'idle' || $s === 'gone') return true;
                if ($s !== 'running') throw new PNS_ControlError('control-state');
                while ($this->now() < $at + PNS::CONFIRM_POLL) {
                    if (!$this->w->proc->pump($this->clock->pollInterval())) $this->clock->idle();
                }
            }
        } finally {
            $this->reserved--;
        }
    }

    private function applyOutcome(array $st, $ok, $errno, $censored, $interrupted)
    {
        $t = $st['table'];
        if ($st['kind'] === 'create') {
            if ($ok && !$interrupted) {
                $to = 'confirmed-created';
            } elseif (!$censored && !$interrupted && $errno === 1050) {
                $to = 'rejected-collision';
                $this->finding('create-name-collision', 1);
            } elseif (!$censored && !$interrupted && $errno >= 1000 && $errno < 2000) {
                $to = 'not-created';
                $this->finding('create-failed', 1);
            } else {
                $to = 'ownership-uncertain';
                $this->finding('ownership-uncertain', 4);
            }
            $this->ledger->transition($t, $to);
            if ($to !== 'confirmed-created') $this->w->stopping = true;
        } elseif ($st['kind'] === 'write') {
            if (!$ok || $censored || $interrupted) {
                if ($this->ledger->state($t) === 'confirmed-created') $this->ledger->transition($t, 'tainted');
                $this->finding('fixture-tainted', 3);
                if (!$censored && !$interrupted) $this->finding('write-failed', 1);
                $this->w->stopping = true;
            }
        } elseif ($st['kind'] === 'drop') {
            if ($ok && !$interrupted) {
                $this->ledger->transition($t, 'dropped');
            } else {
                $this->finding('cleanup-drop-failed', 4);
                $this->w->stopping = true;
            }
        }
    }

    private function interrupted(array $st)
    {
        $st['outcome'] = 'interrupted';
        try {
            $this->applyOutcome($st, false, 0, true, true);
        } catch (PNS_LedgerError $e) {
            $this->finding('ledger-write-failed', 4);
        }
        $this->recordStatement($st);
    }

    private function recordStatement(array $st)
    {
        if (count($this->statements) >= PNS::EVIDENCE_STATEMENTS_MAX) {
            if ($this->statementsDropped++ === 0) $this->log(['type' => 'LIMIT', 'what' => 'statements']);
            return;
        }
        $out = [];
        foreach (['seq', 'worker', 'phase', 'kind', 'digest', 'table', 'conn', 'outcome', 'ok', 'errno', 'rows', 'affected', 'worker_duration_ns', 'raced', 'cancel', 'metrics'] as $k) {
            if (array_key_exists($k, $st)) $out[$k] = $st[$k];
        }
        $out['deadline_s'] = PNS::STATEMENT_LIMITS[$st['phase']];
        $out['go_ms'] = $this->ms($st['go_at']);
        $out['end_ms'] = $this->ms($st['end_at']);
        $out['supervised_ms'] = $st['end_at'] === null ? null : round(($st['end_at'] - $st['go_at']) * 1000, 3);
        $out['kill_requested_ms'] = $this->ms($st['kill_at']);
        $out['cancel_confirmed_ms'] = $this->ms($st['confirmed_at']);
        $this->statements[] = $out;
    }

    /** Stop the worker process now. A running statement without confirmed end makes the run unresolved. */
    private function abandonWorker()
    {
        $w = $this->w;
        if ($w === null || $w->state === 'terminated') return;
        $st = $w->inflight;
        if ($st !== null && $st['end_at'] === null) {
            if ($st['cancel'] !== 'confirmed') {
                if (!$this->unresolved) $this->finding('statement-end-unconfirmed', 4);
                $this->unresolved = true;
            }
            $this->interrupted($st);
        }
        $w->inflight = null;
        if ($w->conn) $w->conn['valid'] = false;
        $w->proc->terminate();
        $this->log(['type' => 'TERMINATED', 'worker' => $w->role]);
        $this->finishProcess();
        $w->state = 'terminated';
    }

    private function abandonIfRunning()
    {
        try {
            $this->abandonWorker();
        } catch (Throwable $e) {
            $this->unresolved = true;
            $this->finding('worker-state-unknown', 4);
        }
    }

    /** The process ended on its own. After loss the identity is invalid: observation by thread id only, never KILL. */
    private function workerExited()
    {
        $w = $this->w;
        if ($w->state === 'done') {
            if ($w->proc->exitCode() !== 0) $this->finding('worker-exit-nonzero', 1);
        } else {
            $st = $w->inflight;
            $c = $w->conn;
            if ($c) $w->conn['valid'] = false;
            if ($st !== null && $st['end_at'] === null && $st['cancel'] !== 'confirmed') {
                $confirmed = false;
                if ($c) {
                    try {
                        $confirmed = $this->confirmEnded($c['conn_id'], $c['thread_id'], $this->now());
                    } catch (PNS_ControlError $e) {
                        $confirmed = false;
                    }
                }
                if ($confirmed) {
                    $st['cancel'] = 'confirmed';
                    $this->finding('worker-lost', 1);
                } else {
                    $this->finding('worker-lost-statement-unconfirmed', 4);
                    $this->unresolved = true;
                }
                $this->interrupted($st);
            } else {
                $this->finding('worker-lost', 1);
                if ($st !== null && $st['end_at'] === null) $this->interrupted($st);
            }
        }
        $w->inflight = null;
        $w->proc->close();
        $this->finishProcess();
        $w->state = 'terminated';
    }

    private function finishProcess()
    {
        $w = $this->w;
        $cap = ['worker' => $w->role, 'exit' => $w->proc->exitCode()] + $w->proc->captureSummary();
        foreach (['stdout', 'stderr'] as $k) {
            if ($cap[$k]['truncated']) {
                $this->finding('capture-truncated', 3);
                $this->log(['type' => 'LIMIT', 'what' => $w->role . '-' . $k, 'bytes' => $cap[$k]['bytes'], 'captured' => $cap[$k]['captured']]);
            }
        }
        $this->captures[] = $cap;
    }
}

/** One worker connection. Each instance opens exactly one NEW connection (distinct setup/measure/cleanup instances). */
interface PNS_WorkerDriver
{
    /**
     * Set connect/read options BEFORE connecting, connect, set @pns_identity = $marker
     * (and lock_wait_timeout = 1 for role cleanup, verified). Returns
     * ['conn_id'=>int,'connect_timeout'=>number,'read_timeout'=>number,'reconnect'=>false,'lock_wait_timeout'=>int|null,
     *  'session_sql'=>int (SQL statements run while opening, 0..PNS::SESSION_SQL_MAX; charged as "other")].
     * Persistent links (host "p:...") must be refused.
     * Throws PNS_WorkerFailure with a fixed code only.
     */
    public function open($role, $connectTimeout, $readTimeout, $marker);
    /** ['errno'=>int,'rows'=>array,'affected'=>int]; no raw server message. */
    public function execute($sql);
    public function close();
}

final class PNS_MysqliWorkerDriver implements PNS_WorkerDriver
{
    private $credentials;
    private $db = null;
    private $used = false;

    public function __construct(array $credentials)
    {
        $this->credentials = $credentials;
    }

    public function __debugInfo() { return ['connected' => $this->db !== null]; }

    public function open($role, $connectTimeout, $readTimeout, $marker)
    {
        if ($this->used) throw new PNS_WorkerFailure('driver-reuse');
        $this->used = true;
        if (PNS_MysqlControl::persistentHost($this->credentials['DB_HOSTNAME'] ?? '')) throw new PNS_WorkerFailure('persistent-connection');
        if (!extension_loaded('mysqli') || !defined('MYSQLI_OPT_READ_TIMEOUT')) throw new PNS_WorkerFailure('read-timeout-option');
        if (filter_var(ini_get('mysqli.reconnect'), FILTER_VALIDATE_BOOLEAN)) throw new PNS_WorkerFailure('reconnect-enabled');
        mysqli_report(MYSQLI_REPORT_OFF);
        $connect = (int)ceil($connectTimeout);
        $read = (int)ceil($readTimeout);
        $db = mysqli_init();
        if (!$db || !$db->options(MYSQLI_OPT_CONNECT_TIMEOUT, $connect) || !$db->options(MYSQLI_OPT_READ_TIMEOUT, $read)) throw new PNS_WorkerFailure('options');
        $c = $this->credentials;
        if (!@$db->real_connect((string)($c['DB_HOSTNAME'] ?? ''), (string)($c['DB_USERNAME'] ?? ''), (string)($c['DB_PASSWORD'] ?? ''), (string)($c['DB_DATABASE'] ?? ''), (int)($c['DB_PORT'] ?? 3306))) {
            throw new PNS_WorkerFailure('connect');
        }
        $session = 3;   // SET NAMES (set_charset), SELECT CONNECTION_ID(), SET @pns_identity
        if (!@$db->set_charset('utf8mb4')) throw new PNS_WorkerFailure('charset');
        $r = @$db->query('SELECT CONNECTION_ID() AS id');
        $row = $r instanceof mysqli_result ? $r->fetch_assoc() : null;
        if (!$row || (int)$row['id'] < 1) throw new PNS_WorkerFailure('connection-id');
        $id = (int)$row['id'];
        if (!@$db->query("SET @pns_identity = '" . $db->real_escape_string((string)$marker) . "'")) throw new PNS_WorkerFailure('identity');
        $lock = null;
        if ($role === 'cleanup') {
            $r = @$db->query('SET SESSION lock_wait_timeout = ' . PNS::LOCK_WAIT_TIMEOUT) ? @$db->query('SELECT @@SESSION.lock_wait_timeout AS v') : false;
            $row = $r instanceof mysqli_result ? $r->fetch_assoc() : null;
            if (!$row || (int)$row['v'] !== PNS::LOCK_WAIT_TIMEOUT) throw new PNS_WorkerFailure('lock-wait');
            $lock = PNS::LOCK_WAIT_TIMEOUT;
            $session += 2;
        }
        $this->db = $db;
        return ['conn_id' => $id, 'connect_timeout' => (float)$connect, 'read_timeout' => (float)$read, 'reconnect' => false, 'lock_wait_timeout' => $lock, 'session_sql' => $session];
    }

    public function execute($sql)
    {
        if ($this->db === null) throw new PNS_WorkerFailure('not-connected');
        $r = @$this->db->query($sql);
        if ($r === false) return ['errno' => (int)$this->db->errno ?: 2000, 'rows' => [], 'affected' => 0];
        if ($r instanceof mysqli_result) {
            $rows = $r->fetch_all(MYSQLI_ASSOC);
            $r->free();
            return ['errno' => 0, 'rows' => $rows, 'affected' => 0];
        }
        return ['errno' => 0, 'rows' => [], 'affected' => max(0, (int)$this->db->affected_rows)];
    }

    public function close()
    {
        if ($this->db !== null) {
            @$this->db->close();
            $this->db = null;
        }
    }
}

/** Worker-side protocol client. Submits no SQL before GO and nothing after END before ACK. */
final class PNS_Worker
{
    private static $instance = null;
    private $boot;
    private $events;
    private $acks;
    private $seq = 0;
    private $peer = 0;
    private $buf = '';
    private $driver = null;
    private $serial = 0;
    private $connRole = null;
    private $cleanupTables = null;

    public static function boot()
    {
        if (self::$instance !== null) return self::$instance;
        if (!defined('PNS_BOOT')) throw new PNS_WorkerFailure('boot-missing');
        $b = json_decode((string)base64_decode(PNS_BOOT, true), true);
        if (!is_array($b) || ($b['v'] ?? null) !== PNS::PROTOCOL || !is_string($b['nonce'] ?? null) || !preg_match('/^[0-9a-f]{32}$/', $b['nonce'])
            || !is_string($b['token'] ?? null) || !preg_match('/^[0-9a-f]{32}$/', $b['token']) || !in_array($b['role'] ?? null, ['workload', 'cleanup'], true)
            || !is_array($b['limits'] ?? null) || !is_array($b['manifest'] ?? null)) throw new PNS_WorkerFailure('boot-invalid');
        $ev = @fopen('php://fd/3', 'wb');
        $ack = @fopen('php://fd/4', 'rb');
        if (!$ev || !$ack) throw new PNS_WorkerFailure('boot-channels');
        stream_set_blocking($ack, false);
        $w = new self();
        $w->boot = $b;
        $w->events = $ev;
        $w->acks = $ack;
        return self::$instance = $w;
    }

    public function manifest() { return $this->boot['manifest']; }
    public function build() { return $this->boot['build']; }
    public function dbPrefix() { return $this->boot['db_prefix']; }
    public function cleanupTables() { return (array)$this->cleanupTables; }

    /** Opens a new connection (the previous one must be closed) and performs HELLO / HELLO_ACK. */
    public function connect($role, PNS_WorkerDriver $driver)
    {
        if ($this->driver !== null) throw new PNS_WorkerFailure('connection-still-open');
        $allowed = $this->boot['role'] === 'cleanup' ? ['cleanup'] : ['setup', 'measure'];
        if (!in_array($role, $allowed, true)) throw new PNS_WorkerFailure('connection-role');
        $l = $this->boot['limits'];
        $serial = $this->serial + 1;
        $info = $driver->open($role, $l['connect'], $role === 'setup' ? $l['read_setup'] : $l['read_statement'], $this->boot['token'] . ':' . $serial);
        if (!is_array($info) || !is_int($info['conn_id'] ?? null)) throw new PNS_WorkerFailure('driver-info');
        $session = $info['session_sql'] ?? null;
        if (!is_int($session) || $session < 0 || $session > PNS::SESSION_SQL_MAX) throw new PNS_WorkerFailure('driver-session-sql');
        $this->serial = $serial;
        $this->driver = $driver;
        $this->connRole = $role;
        $s = $this->event('HELLO', 'hello', [
            'conn' => $serial, 'role' => $role, 'conn_id' => $info['conn_id'], 'build' => $this->boot['build'], 'db_prefix' => $this->boot['db_prefix'],
            'connect_timeout' => $info['connect_timeout'], 'read_timeout' => $info['read_timeout'], 'reconnect' => $info['reconnect'] === true,
            'lock_wait_timeout' => $info['lock_wait_timeout'], 'session_sql' => $session,
        ]);
        $this->awaitReply(['HELLO_ACK'], $s, $l['ack_hello']);
        if ($role === 'cleanup') {
            $c = $this->awaitReply(['CLEANUP'], null, $l['ack_other']);
            if (!isset($c['tables']) || !is_array($c['tables'])) throw new PNS_WorkerFailure('cleanup-list');
            foreach ($c['tables'] as $t) if (!PNS::tableName($t)) throw new PNS_WorkerFailure('cleanup-list');
            $this->cleanupTables = $c['tables'];
        }
        return $serial;
    }

    /** Throws PNS_WorkerStop when the supervisor rejects the name. */
    public function registerCreate($table)
    {
        if ($this->driver === null) throw new PNS_WorkerFailure('not-connected');
        $s = $this->event('REGISTER_CREATE', 'setup', ['conn' => $this->serial, 'table' => $table]);
        $m = $this->awaitReply(['ACK'], $s, $this->boot['limits']['ack_other']);
        if (($m['decision'] ?? null) !== 'accept') throw new PNS_WorkerStop('register-rejected');
    }

    /**
     * BEGIN -> GO -> execute -> END -> ACK. Returns the driver result plus duration_ns.
     * The executed SQL is prefixed with the attribution tag "/*pns:<conn>:<seq>*\/"; digest/ddl_head use the untagged SQL.
     */
    public function statement($phase, $kind, $sql, $table = null)
    {
        if ($this->driver === null) throw new PNS_WorkerFailure('not-connected');
        if (!in_array($phase, PNS::ROLE_PHASES[$this->connRole] ?? [], true)) throw new PNS_WorkerFailure('statement-role-phase');
        $bad = self::checkShape($phase, $kind, $sql, $table);
        if ($bad !== null) throw new PNS_WorkerFailure('statement-shape-' . $bad);   // refused before BEGIN: nothing submitted
        $ddl = $kind === 'create' ? substr(preg_replace('/\s+/', ' ', ltrim($sql)), 0, 200) : null;
        $b = $this->event('BEGIN', $phase, ['conn' => $this->serial, 'kind' => $kind, 'digest' => hash('sha256', $sql), 'table' => $table, 'ddl_head' => $ddl]);
        $this->awaitReply(['GO'], $b, $this->boot['limits']['ack_other']);
        $t0 = hrtime(true);
        $r = $this->driver->execute('/*pns:' . $this->serial . ':' . $b . '*/ ' . $sql);
        $dt = hrtime(true) - $t0;
        $errno = (int)($r['errno'] ?? 2000);
        $e = $this->event('END', $phase, [
            'conn' => $this->serial, 're' => $b, 'ok' => $errno === 0, 'errno' => max(0, $errno), 'duration_ns' => $dt,
            'rows' => count((array)($r['rows'] ?? [])), 'affected' => max(0, (int)($r['affected'] ?? 0)),
        ]);
        $this->awaitReply(['ACK'], $e, $this->boot['limits']['ack_other']);
        return ['errno' => $errno, 'rows' => (array)($r['rows'] ?? []), 'affected' => (int)($r['affected'] ?? 0), 'duration_ns' => $dt];
    }

    /**
     * Trusted-worker guard on the SQL text itself (the supervisor only sees kind/table/digest). Returns null when
     * allowed, else a fixed reason. The SQL is checked as given, never rewritten.
     *   select  SELECT/SHOW only; no INTO OUTFILE/DUMPFILE/@var, FOR UPDATE/SHARE, LOCK IN SHARE MODE
     *   explain EXPLAIN [FORMAT=x] SELECT;  analyze EXPLAIN ANALYZE / ANALYZE [FORMAT=x] SELECT
     *   setup   SET SESSION / @@SESSION. / @user_var / NAMES; never GLOBAL/PERSIST
     *   create  CREATE TABLE `<table>` ... (no IF NOT EXISTS);  drop exactly DROP TABLE `<table>`
     *   write   INSERT/REPLACE INTO `<table>`, UPDATE `<table>` SET, DELETE FROM `<table>` [WHERE]; no JOIN/USING
     * Owned kinds require a valid table; other kinds must not name one. Executable comments are always refused.
     */
    public static function checkShape($phase, $kind, $sql, $table)
    {
        if (!is_string($phase) || !isset(PNS::PHASE_KINDS[$phase]) || !in_array($kind, PNS::PHASE_KINDS[$phase], true)) return 'phase-kind';
        if (!is_string($sql) || trim($sql) === '' || strpos($sql, "\0") !== false) return 'empty';
        if (preg_match('#/\*M?!#i', $sql)) return 'executable-comment';
        $owned = in_array($kind, ['create', 'write', 'drop'], true);
        if ($owned ? !PNS::tableName($table) : $table !== null) return 'table';
        $s = trim($sql);
        $q = $owned ? preg_quote('`' . $table . '`', '/') : '';
        switch ($kind) {
            case 'select':
                if (!preg_match('/^(SELECT|SHOW)\b/i', $s)) return 'not-read-only';
                if (preg_match('/\bINTO\s+(OUTFILE\b|DUMPFILE\b|@)|\bFOR\s+(UPDATE|SHARE)\b|\bLOCK\s+IN\s+SHARE\s+MODE\b/i', $s)) return 'not-read-only';
                return null;
            case 'explain':
                return preg_match('/^EXPLAIN\s+(FORMAT\s*=\s*\w+\s+)?SELECT\b/i', $s) ? null : 'explain-shape';
            case 'analyze':
                return preg_match('/^(EXPLAIN\s+ANALYZE|ANALYZE)(\s+FORMAT\s*=\s*\w+)?\s+SELECT\b/i', $s) ? null : 'analyze-shape';
            case 'setup':
                if (preg_match('/\b(GLOBAL|PERSIST|PERSIST_ONLY)\b|@@(GLOBAL|PERSIST)/i', $s)) return 'setup-global';
                return preg_match('/^SET\s+(SESSION\s|@@SESSION\.|@[A-Za-z_]|NAMES\s)/i', $s) ? null : 'setup-shape';
            case 'create':
                if (!preg_match('/^CREATE\s+TABLE\s+' . $q . '\s/i', $s) || preg_match('/\bIF\s+NOT\s+EXISTS\b/i', $s)) return 'create-shape';
                return null;
            case 'write':
                if (preg_match('/^(INSERT|REPLACE)\s+INTO\s+' . $q . '(\s|\()/i', $s)) return null;
                if (preg_match('/\b(JOIN|USING)\b/i', $s)) return 'write-multi-table';
                if (preg_match('/^UPDATE\s+' . $q . '\s+SET\b/i', $s) || preg_match('/^DELETE\s+FROM\s+' . $q . '(\s+WHERE\b.*)?$/is', $s)) return null;
                return 'write-shape';
            case 'drop':
                return $s === 'DROP TABLE `' . $table . '`' ? null : 'drop-shape';
        }
        return 'phase-kind';
    }

    public function close()
    {
        if ($this->driver !== null) {
            $this->driver->close();
            $this->driver = null;
            $this->connRole = null;
        }
    }

    public function done($reason)
    {
        $this->close();
        $this->event('DONE', 'stop', ['reason' => $reason]);
    }

    /** Low-level framing (protocol tests use it to emit deliberately invalid sequences). */
    public function frame($type, $phase, array $fields)
    {
        $this->seq++;
        $line = json_encode(['v' => PNS::PROTOCOL, 'nonce' => $this->boot['nonce'], 'token' => $this->boot['token'], 'seq' => $this->seq,
            'phase' => $phase, 'type' => $type] + $fields, JSON_UNESCAPED_SLASHES) . "\n";
        if (strlen($line) > PNS::EVENT_MAX) throw new PNS_WorkerFailure('event-too-large');
        return $line;
    }

    public function write($bytes)
    {
        $off = 0;
        while ($off < strlen($bytes)) {
            $n = @fwrite($this->events, substr($bytes, $off));
            if ($n === false || $n === 0) throw new PNS_WorkerFailure('event-write');
            $off += $n;
        }
    }

    public function event($type, $phase, array $fields)
    {
        $this->write($this->frame($type, $phase, $fields));
        return $this->seq;
    }

    public function awaitReply(array $types, $re, $timeout)
    {
        $end = hrtime(true) / 1e9 + $timeout;
        while (true) {
            $pos = strpos($this->buf, "\n");
            if ($pos !== false) {
                $line = substr($this->buf, 0, $pos);
                $this->buf = (string)substr($this->buf, $pos + 1);
                $m = json_decode($line, true, 8);
                if (!is_array($m) || ($m['v'] ?? null) !== PNS::PROTOCOL || ($m['nonce'] ?? null) !== $this->boot['nonce']
                    || ($m['token'] ?? null) !== $this->boot['token'] || ($m['seq'] ?? null) !== $this->peer + 1
                    || !is_string($m['phase'] ?? null) || !is_string($m['type'] ?? null) || !array_key_exists('re', $m)) throw new PNS_WorkerFailure('reply-invalid');
                $this->peer++;
                if ($m['type'] === 'STOP') throw new PNS_WorkerStop('stop');
                if (!in_array($m['type'], $types, true)) throw new PNS_WorkerFailure('reply-unexpected');
                if ($re !== null && $m['re'] !== $re) throw new PNS_WorkerFailure('reply-mismatch');
                return $m;
            }
            if (strlen($this->buf) > PNS::REPLY_MAX) throw new PNS_WorkerFailure('reply-too-large');
            $left = $end - hrtime(true) / 1e9;
            if ($left <= 0) throw new PNS_WorkerFailure('ack-timeout');
            $r = [$this->acks];
            $w = null;
            $x = null;
            $sec = (int)floor($left);
            if (@stream_select($r, $w, $x, $sec, (int)(($left - $sec) * 1e6))) {
                $d = @fread($this->acks, 65536);
                if ($d === false || $d === '') {
                    if (feof($this->acks)) throw new PNS_WorkerFailure('ack-closed');
                } else {
                    $this->buf .= $d;
                }
            }
        }
    }

    /** Worker entry point: exit 0 after DONE done/stopped, 3 on any failure (no message printed). */
    public static function main(callable $workload)
    {
        try {
            $w = self::boot();
        } catch (Throwable $e) {
            exit(3);
        }
        try {
            $workload($w);
            $w->done('done');
        } catch (PNS_WorkerStop $e) {
            try {
                $w->done('stopped');
            } catch (Throwable $x) {
                exit(3);
            }
        } catch (Throwable $e) {
            try {
                $w->done('error');
            } catch (Throwable $x) {
            }
            exit(3);
        }
        exit(0);
    }

    /** Fresh cleanup worker: drops exactly the CLEANUP allowlist, one supervised statement each. */
    public static function cleanupMain(callable $driverFactory)
    {
        self::main(function (PNS_Worker $w) use ($driverFactory) {
            $w->connect('cleanup', $driverFactory('cleanup'));
            foreach ($w->cleanupTables() as $t) $w->statement('cleanup', 'drop', 'DROP TABLE `' . $t . '`', $t);
        });
    }
}

/** Source bundling helpers, used locally by the Phase C emitter (not inside the remote supervisor). */
final class PNS_Bundle
{
    public static function supportSource()
    {
        $s = file_get_contents(__FILE__);
        if (!is_string($s) || strncmp($s, '<?php', 5) !== 0) throw new RuntimeException('support-source');
        return substr($s, 5);
    }

    /** One worker source: this support code followed by the given '<?php' snippets. */
    public static function workerSource(array $snippets)
    {
        $out = '<?php' . self::supportSource();
        foreach ($snippets as $s) {
            if (!is_string($s) || strncmp($s, '<?php', 5) !== 0) throw new InvalidArgumentException('snippet');
            $out .= "\n" . substr($s, 5);
        }
        if (strlen($out) > PNS::SOURCE_MAX) throw new InvalidArgumentException('source-size');
        return $out;
    }

    public static function encodePayload($source)
    {
        return base64_encode(json_encode(['sha256' => hash('sha256', $source), 'source' => base64_encode($source)]));
    }

    public static function decodePayload($encoded)
    {
        $j = json_decode((string)base64_decode((string)$encoded, true), true);
        $src = is_array($j) && is_string($j['source'] ?? null) ? base64_decode($j['source'], true) : false;
        if (!is_string($src) || !is_string($j['sha256'] ?? null) || !hash_equals($j['sha256'], hash('sha256', $src))) throw new InvalidArgumentException('payload');
        return $src;
    }
}

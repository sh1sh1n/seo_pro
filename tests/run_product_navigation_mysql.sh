#!/bin/sh
# Local orchestrator for the supervised product-navigation MySQL runs (design_draft.md, Phase C).
#
# usage:
#   sh tests/run_product_navigation_mysql.sh --preflight|--read-only-stand|--matrix|--all
#        [--run-id=ID] [--global-seconds=N] [--chunk-seconds=N] [--fake-transport=/abs/exe]
#   sh tests/run_product_navigation_mysql.sh --plan <mode>      offline: print the frozen schedule only
#   sh tests/run_product_navigation_mysql.sh --self-test        offline: pure-function + propagation checks
#                                                               (needs local PHP; never contacts MySQL/ssh)
#
# Required environment (stand guide supplies the SSH/config values; never put secrets here):
#   PHP                         local PHP binary (default: php)
#   OC_DIR                      local OpenCart 3.0.5.1 upload/ (stock utf8/url/request for the emitter)
#   SEO_PRO_TEST_SSH            ssh target (alias/host from ~/.ssh/config); not needed with --fake-transport
#   SEO_PRO_TEST_STORE_CONFIG   absolute REMOTE path of the shop config read by the remote parser
# Optional overrides (secure defaults; no further required settings):
#   SEO_PRO_REMOTE_RUN_BASE     absolute REMOTE base dir; run dir is <base>/seo-pro-pns-<run-id>
#                               (default base /tmp; random run id; <= 200 chars; carried in each case manifest)
#   SEO_PRO_EVIDENCE_DIR        existing local evidence dir (default: <repo>/docs/designs/product-navigation-
#                               brainstorm/remediation-review-2026-11-17, a local review folder that
#                               fresh clones do not have; then set this to an existing absolute dir
#                               OUTSIDE the checkout); output goes to execution/<run-id>/ (must not
#                               exist yet)
#   SEO_PRO_REMOTE_PHP          optional remote PHP binary (default: php)
#
# Guarantees implemented here (see the design, sections 4 and 6):
#   * Before ANY ssh: input validation, candidate identity (full HEAD must descend from the floor
#     commit 499faaa via git merge-base --is-ancestor; worktree must be clean: no tracked, staged or
#     untracked changes), read-only git export of the
#     clean 1.8 baseline into a private mktemp dir, php -l of all sources, sha256 of all sources,
#     the immutable manifest.json with the fixed 83-chunk schedule, and the preflight bundle.
#   * Before EVERY dispatch (before the clock is read): HEAD is still the captured candidate_head, the
#     worktree is still clean, and every hashed source still matches its manifest sha256; otherwise
#     that chunk is not launched (status 1).
#   * --plan and --self-test are offline and perform no git/worktree validation.
#   * After the preflight chunk: engine/collations/server/sample are frozen from its authenticated
#     workload case evidence into preflight.json; every later case manifest and bundle is generated,
#     validated and linted before the next ssh. Emission failure stops before ssh.
#   * One ssh per dispatched chunk, bundle streamed on stdin (no scp/rsync, no file copy, no
#     password, BatchMode). Exactly the 83 frozen chunks at most; never retried.
#   * Global cap 900 s as one local monotonic ABSOLUTE deadline fixed immediately before the first
#     dispatch (preflight included); every chunk's whole window (connect + startup + submission +
#     30 s cleanup + kill grace + drain) must end at or before it, computed in integer ms with
#     floor rounding and a 1 s local slack. 90 chunks max; per chunk <=180 s submission + 30 s
#     cleanup. A local watchdog kill is a loss (exit 4); finishing past the window is status 3.
#   * Any non-zero chunk stops all further dispatch. Severity 4 > 1 > 3 > 2 > 0 is preserved.
#   * Result labels: completed, completed-with-measurement-gaps, incomplete, failed, unsupported,
#     unresolved, unrun-*, not-selected. Nothing is ever labelled PASS.
#
# INTEGRATION CONTRACT (tests/test_product_navigation_mysql.php, provisional interface). Each lives in
# exactly one function below:
#   build_case / helper 'case'   case manifest pnms-case/1: exact key order, canonical JSON
#                                (byte-identical PNMS_Lib::canonical), stage names preflight |
#                                read-only-stand | semantic | timing | diagnostic, engine/collation/
#                                variant/repetition/pair_position/sample nulls per stage, fixture_prefix
#                                pnsx_<alnum 6..20>_, limits (chunk/cleanup/select/other), expect hashes.
#   emit_bundle / 'bundleman'    SEO_PRO_EMIT_MANIFEST_OUT -> pnms-bundle/1 {schema, build, case,
#                                case_sha256, sources{...,bundle_sha256}, runtime, oc, checks, php_local,
#                                entrypoint}; cross-checked against the bundle's embedded PNMS_EMBED.
#   remote_command               bundle on stdin; env only: SEO_PRO_TEST_STORE_CONFIG and the non-secret
#                                lowered dispatch budget SEO_PRO_PNMS_CHUNK_SECONDS; no argv.
#   helper 'result'              stdout pnms-result/1 (one multiline JSON document). Full form is bound to
#                                the streamed case manifest + bundle sources and carries limits{effective,
#                                manifest, chunk_seconds_env (== dispatched budget), statement_seconds,
#                                confirm_seconds}, evidence_transfer{file:{bytes,sha256}} and evidence
#                                {events.jsonl, metrics.json, ledger.json} (required for status 0; null only
#                                with an explicit unretained/transfer reason). Early form: codes only, or
#                                codes + verified identity (run_id..remote_php) once the case hash is known.
#                                Bounded: anything over a local cap is never accepted and is status 3.
#   helper 'dataset'             preflight: workload.evidence.cases id=server|collations|shop-definition|
#                                sample (data.sample + data.sample_sha256). Missing sample = exit 3.
#
# Offline hook (--fake-transport=EXE): EXE is called as `EXE <chunk-id> <remote-command>` with the
# bundle on stdin, in place of ssh only. Caps, watchdog, bounds, parsing and labels are unchanged;
# manifest/REPORT say transport=fake, so the run is never stand evidence.
#
# Exit codes: 0 completed selected workload; 1 assertion/protocol; 2 unsupported/missing preflight
# control; 3 deadline/budget/missing dataset/sample/collation/required metric; 4 unresolved cleanup/
# cancellation or supervisor/SSH loss (raw ssh 255, local watchdog).
set -u
umask 077

NL='
'
TAB='	'
ROOT=$(cd "$(dirname "$0")/.." && pwd)
PHP=${PHP:-php}

# ---------------------------------------------------------------- frozen constants (do not widen)
# Candidate floor: the verified merged source commit on main. The audited candidate is the actual full
# HEAD of a CLEAN checkout that has this commit as an ancestor (git merge-base --is-ancestor); that HEAD
# is captured once and recorded in manifest.json as candidate_head (plus candidate_floor_commit).
CANDIDATE_FLOOR_COMMIT=499faaa43a7b32cad46a677cdc563b58da20ecdf
CANDIDATE_HEAD=
BASELINE_COMMIT=cdecbdc63a347aab44923bda0be30a251f42f496
RUNTIME_FILES='seo_pro_oc3.ocmod/upload/catalog/controller/extension/startup/seo_pro.php
seo_pro_oc3.ocmod/upload/system/library/seo_pro_schema.php
seo_pro_oc3.ocmod/upload/system/library/seo_pro_cache.php'
TEST_SOURCES='tests/test_product_navigation_mysql.php
tests/product_navigation_supervisor.php'
COLLATIONS='utf8mb4_general_ci utf8mb4_unicode_ci utf8mb4_0900_as_cs utf8mb4_bin'
CI_COLLATIONS='utf8mb4_general_ci utf8mb4_unicode_ci'
VARIANTS='clean conflict'
SCHEDULE_TOTAL=83
GLOBAL_SECONDS_MAX=900
GLOBAL_CHUNKS_MAX=90
CHUNK_SECONDS_MAX=180
CLEANUP_SECONDS=30
MAX_SELECT=1000
MAX_OTHER=6000
# Whole chunk window = submission + cleanup + OVERHEAD, and the whole window is charged against the
# global deadline (never added on top of it). OVERHEAD = ssh connect (10) + supervisor startup (15)
# + TERM->KILL grace (5) + bounded stdout/stderr drain (5). Watchdog TERM fires at
# window - KILL_GRACE - DRAIN, KILL at window - DRAIN; readers are killed at window.
CONNECT_SECONDS=10
STARTUP_SECONDS=15
KILL_GRACE_SECONDS=5
DRAIN_SECONDS=5
OVERHEAD_SECONDS=$((CONNECT_SECONDS + STARTUP_SECONDS + KILL_GRACE_SECONDS + DRAIN_SECONDS))
WATCHDOG_EXTRA=$((CLEANUP_SECONDS + OVERHEAD_SECONDS))
SLACK_MS=1000
# Must equal RESULT_MAX in the helper: one cap for the whole remote result document.
STDOUT_MAX=16777216
STDERR_MAX=65536
BUNDLE_MAX=33554432

# ---------------------------------------------------------------- pure helpers
die() { printf 'run_product_navigation_mysql: %s\n' "$1" >&2; exit "${2:-1}"; }

# POSIX single-quote for a remote sh command line. JSON stringification is not shell escaping.
sq() { printf "'%s'" "$(printf '%s' "$1" | sed "s/'/'\\\\''/g")"; }

# valid REGEX VALUE: whole value, single line, non-empty
valid() {
    case $2 in ''|*"$NL"*) return 1 ;; esac
    printf '%s\n' "$2" | grep -Eq "$1"
}

# Severity order 4 > 1 > 3 > 2 > 0.
rank() { case $1 in 0) echo 0 ;; 2) echo 1 ;; 3) echo 2 ;; 1) echo 3 ;; *) echo 4 ;; esac; }
worse() { if [ "$(rank "$2")" -gt "$(rank "$1")" ]; then echo "$2"; else echo "$1"; fi; }

label_for() { # status gaps
    case $1 in
        0) if [ "$2" -gt 0 ]; then echo completed-with-measurement-gaps; else echo completed; fi ;;
        1) echo failed ;; 2) echo unsupported ;; 3) echo incomplete ;; *) echo unresolved ;;
    esac
}

# map_status RC WATCHDOG_FIRED -> transport-level status. Raw ssh 255, a watchdog kill, or any
# exit code outside the 0..4 contract means the remote state is unknown: 4.
map_status() {
    if [ "$2" = 1 ]; then echo 4; return; fi
    case $1 in 0|1|2|3|4) echo "$1" ;; *) echo 4 ;; esac
}

# chunk_budget REMAINING_MS CHUNK_CAP -> submission seconds for the next chunk, or 0 (do not dispatch).
# Integer only; floors the remaining time after a 1 s slack, then charges the whole window.
chunk_budget() {
    _rem=$1
    if [ "$_rem" -le "$SLACK_MS" ]; then echo 0; return 0; fi
    _s=$(( (_rem - SLACK_MS) / 1000 - WATCHDOG_EXTRA ))
    if [ "$_s" -gt "$2" ]; then _s=$2; fi
    if [ "$_s" -lt 1 ]; then _s=0; fi
    echo "$_s"
}

# Fixed schedule. Columns: id stage build collation variant mode repetition order
# Stage codes: P preflight, R read-only-stand, S semantic, N natural timing, F forced-exact timing,
# D diagnostic. (Contract stage names: see stage_name.)
schedule_rows() {
    _i=0
    _row() { _i=$((_i + 1)); printf 'c%03d\t%s\t%s\t%s\t%s\t%s\t%s\t%s\n' "$_i" "$@"; }
    _pair() { # rep -> builds in order baseline/candidate, candidate/baseline, baseline/candidate
        case $1 in 2) echo 'candidate baseline' ;; *) echo 'baseline candidate' ;; esac
    }
    _row P candidate - - natural 1 1
    for _r in 1 2 3; do _o=0
        for _b in $(_pair $_r); do _o=$((_o + 1)); _row R "$_b" - - natural "$_r" "$_o"; done
    done
    for _c in $COLLATIONS; do _row S candidate "$_c" - natural 1 1; done
    for _c in $COLLATIONS; do for _v in $VARIANTS; do for _r in 1 2 3; do _o=0
        for _b in $(_pair $_r); do _o=$((_o + 1)); _row N "$_b" "$_c" "$_v" natural "$_r" "$_o"; done
    done; done; done
    for _c in $CI_COLLATIONS; do for _v in $VARIANTS; do for _r in 1 2 3; do
        _row F candidate "$_c" "$_v" forced-exact "$_r" 1
    done; done; done
    for _c in $COLLATIONS; do for _v in $VARIANTS; do _row D candidate "$_c" "$_v" natural 1 1; done; done
    for _c in $CI_COLLATIONS; do for _v in $VARIANTS; do _row D candidate "$_c" "$_v" forced-exact 1 1; done; done
}

stages_for_mode() {
    case $1 in
        --preflight) echo P ;; --read-only-stand) echo 'P R' ;;
        --matrix) echo 'P S N F D' ;; --all) echo 'P R S N F D' ;; *) return 1 ;;
    esac
}
# Contract stage names (pnms-case/1 "stage").
stage_name() {
    case $1 in P) echo preflight ;; R) echo read-only-stand ;; S) echo semantic ;;
        N|F) echo timing ;; D) echo diagnostic ;; esac
}
selected() { case " $2 " in *" $1 "*) return 0 ;; esac; return 1; }

# Per-chunk prefix: pnsx_ + one alphanumeric token (8 hex run tag + chunk id, 12 chars, no inner
# underscore) + _; unique per run and chunk; the remote side rejects any overlap with the shop prefix.
fixture_prefix() { printf 'pnsx_%s%s_' "$RUN_TAG" "$1"; }

RE_SSH='^[A-Za-z0-9_][A-Za-z0-9._@-]{0,127}$'
RE_ABS='^/[A-Za-z0-9._/-]{1,255}$'
RE_RUNID='^[a-z0-9][a-z0-9-]{3,31}$'
RE_BIN='^[A-Za-z0-9_][A-Za-z0-9._/-]{0,127}$'
RE_BARE_BIN='^[A-Za-z0-9_][A-Za-z0-9._-]{0,63}$'
# php_bin_ok VALUE: validated absolute path (abs_ok) or a bare binary token (no '/'), resolved via remote PATH.
php_bin_ok() {
    case $1 in /*) abs_ok "$1"; return $? ;; esac
    valid "$RE_BARE_BIN" "$1"
}
RE_INT='^[1-9][0-9]{0,3}$'
RE_PREFIX='^pnsx_[a-z0-9]{6,20}_$'
# Explicit if/case only: correctness never depends on errexit or on && / || precedence.
# abs_ok PATH: plain absolute path, no '.'/'..' component, no empty component, no trailing '/',
# no component starting with '-', charset [A-Za-z0-9._/-], length <= 256.
abs_ok() {
    if valid "$RE_ABS" "$1"; then :; else return 1; fi
    case $1 in
        */../*|*/..|*/./*|*/.|*//*|*/|*/-*) return 1 ;;
    esac
    return 0
}
# int_in VALUE MIN MAX: decimal without sign/leading zero, MIN <= VALUE <= MAX.
int_in() {
    if valid "$RE_INT" "$1"; then :; else return 1; fi
    if [ "$1" -lt "$2" ]; then return 1; fi
    if [ "$1" -gt "$3" ]; then return 1; fi
    return 0
}

# ---------------------------------------------------------------- INTEGRATION: remote command
# Bundle on stdin, no argv. The remote environment is cleared and carries only the non-secret config
# path selector and the lowered per-chunk dispatch budget (SEO_PRO_PNMS_CHUNK_SECONDS <= the case
# manifest's limits.chunk_seconds; the PHP side is to enforce it). No `php -n`: distro CLI builds load
# mysqli through the CLI ini, so only exact, safe -d overrides are passed.
remote_command() { # ID CHUNK_SECONDS
    printf 'umask 077 && exec env -i PATH=/usr/local/bin:/usr/bin:/bin SEO_PRO_TEST_STORE_CONFIG=%s SEO_PRO_PNMS_CHUNK_SECONDS=%s %s -d display_errors=stderr -d log_errors=0 -d html_errors=0' \
        "$(sq "$STORE_CONFIG")" "$(sq "$2")" "$(sq "$REMOTE_PHP")"
}

# ---------------------------------------------------------------- local PHP helper
write_helper() {
cat > "$1" <<'PHPHELPER'
<?php
// Local helper for run_product_navigation_mysql.sh: hashing, monotonic clock, case/bundle manifest
// construction and validation, authenticated parsing of remote pnms-result/1 output. Prints only fixed
// key=value lines; remote content reaches evidence only after validation against the streamed manifest.
declare(strict_types=1);
// Local acceptance caps (bytes as reported in evidence_transfer for the remote files). They are deliberately
// below PNMS::TRANSFER_MAX (8 MiB events/metrics): a larger transfer is never accepted locally and becomes
// status 3 (incomplete). RESULT_MAX == STDOUT_MAX in the shell. Whole-result bound: events JSON-escaped (<= 3x)
// + metrics/ledger + workload evidence (PNMS::EVIDENCE_MAX 4 MiB) fits in 16 MiB.
const RESULT_MAX = 16777216; const EVENTS_MAX = 2097152; const DOC_MAX = 1048576; const EVENT_LINES_MAX = 50000;
const FILE_MAX = ['events.jsonl' => EVENTS_MAX, 'metrics.json' => 1048576, 'ledger.json' => 1048576];
const BMAN_MAX = 1048576; const CASE_MAX = 1048576;
const COLLATIONS = ['utf8mb4_general_ci', 'utf8mb4_unicode_ci', 'utf8mb4_0900_as_cs', 'utf8mb4_bin'];
const CI_COLLATIONS = ['utf8mb4_general_ci', 'utf8mb4_unicode_ci'];
const ENGINES = ['MyISAM', 'InnoDB'];
const STAGES = ['preflight', 'inventory', 'read-only-stand', 'semantic', 'timing', 'diagnostic'];
const FIXTURE_STAGES = ['semantic', 'timing', 'diagnostic'];
const STAGE_NAMES = ['P' => 'preflight', 'R' => 'read-only-stand', 'S' => 'semantic', 'N' => 'timing', 'F' => 'timing', 'D' => 'diagnostic'];
const RUNTIME_FILES = ['system/library/seo_pro_schema.php', 'system/library/seo_pro_cache.php', 'catalog/controller/extension/startup/seo_pro.php'];
const OC_FILES = ['system/helper/utf8.php', 'system/library/url.php', 'system/library/request.php'];
const SOURCE_KEYS = ['harness_sha256', 'support_sha256', 'lib_sha256', 'stubs_sha256', 'worker_section_sha256', 'remote_sha256', 'workload_sha256', 'cleanup_sha256'];
const CASE_KEYS = ['schema', 'run_id', 'chunk_id', 'stage', 'build', 'run_dir', 'fixture_prefix', 'engine', 'collation', 'variant', 'mode',
    'repetition', 'pair_position', 'sample', 'sample_sha256', 'limits', 'expect'];
const LIMIT_MAX = ['chunk_seconds' => 180, 'cleanup_seconds' => 30, 'max_select' => 1000, 'max_other' => 6000];
const BMAN_KEYS = ['schema', 'build', 'case', 'case_sha256', 'sources', 'runtime', 'oc', 'checks', 'php_local', 'entrypoint'];
const RESULT_REQUIRED = ['schema', 'status', 'label', 'reasons', 'run_id', 'chunk_id', 'stage', 'build', 'manifest', 'manifest_sha256', 'bundle',
    'remote_php', 'limits', 'supervisor', 'workload', 'cross_check', 'case_status', 'observed', 'evidence_transfer', 'evidence'];
const RESULT_OPTIONAL = ['result_file', 'preflight'];   // result_file iff evidence retained; preflight iff stage preflight
const EARLY_KEYS = ['schema', 'status', 'label', 'reasons'];
const EARLY_ID_KEYS = ['run_id', 'chunk_id', 'stage', 'build', 'manifest_sha256', 'bundle', 'remote_php'];
// Early codes exactly as PNMS_Remote emits them: unidentified (before the case hash is verified) are status 1 only.
const EARLY_BARE_RE = '/^(embed-invalid|manifest-invalid|manifest-hash|remote-internal [A-Za-z0-9:.,_+-]{1,150})$/D';
const EARLY_ID_RE = [1 => '/^(payload-invalid|payload-hash|chunk-seconds-env-invalid|chunk-seconds-env-above-limit|run-dir-inside-installation|fixture-prefix-overlaps-shop)$/D',
    2 => '/^(remote-php-parse|mysqli-unavailable|PNMS_ConfigError:[a-z0-9:.,_+-]{0,120})$/D'];
const LIMITS_KEYS = ['effective', 'manifest', 'chunk_seconds_env', 'statement_seconds', 'confirm_seconds'];
const STATEMENT_SECONDS = ['setup' => 10.0, 'measure' => 2.0, 'diagnostic' => 2.0, 'cleanup' => 2.0];   // PNS::STATEMENT_LIMITS
const CONFIRM_SECONDS = 3.0;                                                                              // PNS::CONFIRM_WINDOW
const TRANSFER_FAIL_RE = '/^evidence-transfer-(missing|file|oversize|read|shape)-(events\.jsonl|metrics\.json|ledger\.json)$/D';
const EVIDENCE_KEYS = ['events.jsonl', 'metrics.json', 'ledger.json'];
const LABELS = [0 => ['completed', 'completed-with-measurement-gaps'], 1 => ['failed'], 2 => ['not-run-preflight'], 3 => ['incomplete'], 4 => ['unresolved']];

function out(string $k, $v): void { echo $k, '=', $v, "\n"; }
function fail(string $c): void { fwrite(STDERR, "orch-helper: $c\n"); exit(1); }
function jenc($v): string {
    $s = json_encode($v, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION);
    if ($s === false) fail('json-encode'); return $s . "\n";
}
/** Byte-identical to PNMS_Lib::canonical(). */
function canon($v): string {
    $s = json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($s)) fail('canonical'); return $s;
}
function writeNew(string $p, string $c): void {
    $h = @fopen($p, 'xb'); if (!$h) fail('exists-or-unwritable');
    if (fwrite($h, $c) !== strlen($c)) fail('short-write'); fclose($h); chmod($p, 0400);
}
function tsv(string $f): array {
    $r = []; foreach (file($f, FILE_IGNORE_NEW_LINES) ?: [] as $l) if ($l !== '') $r[] = explode("\t", $l); return $r;
}
function kv(array $args): array {
    $r = []; foreach ($args as $a) { $p = strpos($a, '='); if ($p === false) fail('kv'); $r[substr($a, 0, $p)] = substr($a, $p + 1); } return $r;
}
function hex64($v): bool { return is_string($v) && preg_match('/^[0-9a-f]{64}$/D', $v) === 1; }
function isList($v): bool { return is_array($v) && ($v === [] || array_keys($v) === range(0, count($v) - 1)); }
function keySet($v, array $want): bool {
    if (!is_array($v)) return false; $k = array_keys($v); sort($k, SORT_STRING); sort($want, SORT_STRING); return $k === $want;
}
function hashMap($v, array $files): bool {
    if (!keySet($v, $files)) return false; foreach ($v as $h) if (!hex64($h)) return false; return true;
}
function readJson(string $f, int $max, int $depth = 64) {
    $raw = @file_get_contents($f, false, null, 0, $max + 1);
    if (!is_string($raw) || $raw === '' || strlen($raw) > $max) return null;
    $j = json_decode($raw, true, $depth); return is_array($j) ? $j : null;
}
function secretKey($v): bool {
    if (!is_array($v)) return false;
    foreach ($v as $k => $x) {
        if (is_string($k) && preg_match('/passw|secret|credential|db_user|db_pass/i', $k)) return true;
        if (secretKey($x)) return true;
    }
    return false;
}

/** Mirror of PNMS_Manifest::sample(): returns the canonical sample or null when the shape is invalid. */
function sampleIds($a, int $n) {
    if (!isList($a) || count($a) !== $n) return null;
    $p = 0; foreach ($a as $id) { if (!is_int($id) || $id <= $p) return null; $p = $id; } return $a;
}
function normSample($s) {
    if (!keySet($s, ['three', 'one', 'none'])) return null;
    $o = ['three' => null, 'one' => null, 'none' => null];
    if ($s['three'] !== null) {
        $t = $s['three'];
        if (!is_array($t) || array_keys($t) !== ['groups'] || !isList($t['groups']) || count($t['groups']) !== 3) return null;
        $groups = []; $cats = []; $all = [];
        foreach ($t['groups'] as $i => $g) {
            if (!keySet($g, ['category_id', 'ids']) || !is_int($g['category_id']) || $g['category_id'] < 1) return null;
            $ids = sampleIds($g['ids'], [7, 7, 6][$i]); if ($ids === null) return null;
            $groups[] = ['category_id' => $g['category_id'], 'ids' => $ids]; $cats[] = $g['category_id']; $all = array_merge($all, $ids);
        }
        if (count(array_unique($cats)) !== 3 || count(array_unique($all)) !== 20) return null;
        $o['three'] = ['groups' => $groups];
    }
    if ($s['one'] !== null) {
        if (!keySet($s['one'], ['category_id', 'ids']) || !is_int($s['one']['category_id']) || $s['one']['category_id'] < 1) return null;
        $ids = sampleIds($s['one']['ids'], 20); if ($ids === null) return null;
        $o['one'] = ['category_id' => $s['one']['category_id'], 'ids' => $ids];
    }
    if ($s['none'] !== null) {
        if (!is_array($s['none']) || array_keys($s['none']) !== ['ids']) return null;
        $ids = sampleIds($s['none']['ids'], 20); if ($ids === null) return null;
        $o['none'] = ['ids' => $ids];
    }
    return $o;
}

/** Mirror of PNMS_Manifest::validate() on an already-normalized manifest (exact key order). */
function checkCase($m): ?string {
    if (!is_array($m) || array_keys($m) !== CASE_KEYS) return 'case-keys';
    if ($m['schema'] !== 'pnms-case/1') return 'case-schema';
    foreach (['run_id', 'chunk_id'] as $k) if (!is_string($m[$k]) || !preg_match('/^[a-z0-9][a-z0-9-]{0,31}$/D', $m[$k])) return 'case-' . $k;
    $st = $m['stage'];
    if (!in_array($st, STAGES, true)) return 'case-stage';
    if (!in_array($m['build'], ['baseline', 'candidate'], true)) return 'case-build';
    $d = $m['run_dir'];
    if (!is_string($d) || !preg_match('~^/[A-Za-z0-9._/-]{1,200}$~D', $d) || strpos($d, '//') !== false || array_intersect(['..', '.'], explode('/', $d))) return 'case-run_dir';
    if (!is_string($m['fixture_prefix']) || !preg_match('/^pnsx_[a-z0-9]{6,20}_$/D', $m['fixture_prefix'])) return 'case-fixture_prefix';
    $fx = in_array($st, FIXTURE_STAGES, true);
    if ($fx ? !in_array($m['engine'], ENGINES, true) : $m['engine'] !== null) return 'case-engine';
    if ($fx ? !in_array($m['collation'], COLLATIONS, true) : $m['collation'] !== null) return 'case-collation';
    $nv = in_array($st, ['timing', 'diagnostic'], true);
    if ($nv ? !in_array($m['variant'], ['clean', 'conflict'], true) : $m['variant'] !== null) return 'case-variant';
    if (!in_array($m['mode'], ['natural', 'forced-exact'], true)) return 'case-mode';
    if ($m['mode'] === 'forced-exact' && (!$nv || $m['build'] !== 'candidate' || !in_array($m['collation'], CI_COLLATIONS, true))) return 'case-forced-exact';
    if (in_array($st, ['semantic', 'diagnostic'], true) && $m['build'] !== 'candidate') return 'case-candidate-only';
    $nr = in_array($st, ['read-only-stand', 'timing'], true);
    if ($nr ? !(is_int($m['repetition']) && $m['repetition'] >= 1 && $m['repetition'] <= 3) : $m['repetition'] !== null) return 'case-repetition';
    $pp = $st === 'read-only-stand' || ($st === 'timing' && $m['mode'] === 'natural');
    if ($pp ? !in_array($m['pair_position'], [1, 2], true) : $m['pair_position'] !== null) return 'case-pair_position';
    if ($st === 'read-only-stand') {
        $n = normSample($m['sample']);
        if ($n === null || canon($n) !== canon($m['sample']) || !hex64($m['sample_sha256']) || !hash_equals(hash('sha256', canon($n)), $m['sample_sha256'])) return 'case-sample';
    } elseif ($m['sample'] !== null || $m['sample_sha256'] !== null) return 'case-sample';
    if ($m['limits'] !== null) {
        if (!is_array($m['limits']) || isList($m['limits'])) return 'case-limits';
        foreach ($m['limits'] as $k => $v) if (!isset(LIMIT_MAX[$k]) || !is_int($v) || $v < 1 || $v > LIMIT_MAX[$k]) return 'case-limits';
    }
    if ($m['expect'] !== null) {
        if (!is_array($m['expect']) || array_diff(array_keys($m['expect']), ['runtime', 'oc'])) return 'case-expect';
        foreach (['runtime' => RUNTIME_FILES, 'oc' => OC_FILES] as $k => $files) if (isset($m['expect'][$k]) && !hashMap($m['expect'][$k], $files)) return 'case-expect';
    }
    return null;
}

function buildCase(array $f, $d, string $rt, string $oc): array {
    $nul = function ($k) use ($f) { $v = $f[$k] ?? '-'; return $v === '-' ? null : $v; };
    $int = function ($k) use ($f) { $v = $f[$k] ?? '-'; if ($v === '-') return null; if (!preg_match('/^[1-9][0-9]{0,3}$/D', $v)) fail('case-int-' . $k); return (int)$v; };
    $stage = $f['stage'] ?? '';
    $engine = null;
    if (in_array($stage, FIXTURE_STAGES, true)) { $engine = is_array($d) ? ($d['engine'] ?? null) : null; if (!in_array($engine, ENGINES, true)) fail('case-engine-unfrozen'); }
    $sample = null; $ssha = null;
    if ($stage === 'read-only-stand') {
        $sample = normSample(is_array($d) ? ($d['sample'] ?? null) : null); if ($sample === null) fail('case-sample-unfrozen');
        $ssha = hash('sha256', canon($sample));
        if (!hex64($d['sample_sha256'] ?? null) || !hash_equals($ssha, $d['sample_sha256'])) fail('case-sample-sha');
    }
    $limits = [];
    foreach (array_keys(LIMIT_MAX) as $k) $limits[$k] = $int($k);
    $m = ['schema' => 'pnms-case/1', 'run_id' => $f['run_id'] ?? null, 'chunk_id' => $f['chunk_id'] ?? null, 'stage' => $stage, 'build' => $f['build'] ?? null,
        'run_dir' => $f['run_dir'] ?? null, 'fixture_prefix' => $f['fixture_prefix'] ?? null, 'engine' => $engine, 'collation' => $nul('collation'),
        'variant' => $nul('variant'), 'mode' => $f['mode'] ?? null, 'repetition' => $int('repetition'), 'pair_position' => $int('pair_position'),
        'sample' => $sample, 'sample_sha256' => $ssha, 'limits' => $limits, 'expect' => null];
    if ($rt !== '-' && $oc !== '-') {
        $e = ['runtime' => [], 'oc' => []];
        foreach (RUNTIME_FILES as $r) { $h = @hash_file('sha256', $rt . '/seo_pro_oc3.ocmod/upload/' . $r); if (!$h) fail('case-expect-runtime'); $e['runtime'][$r] = $h; }
        foreach (OC_FILES as $r) { $h = @hash_file('sha256', $oc . '/' . $r); if (!$h) fail('case-expect-oc'); $e['oc'][$r] = $h; }
        $m['expect'] = $e;
    }
    $err = checkCase($m); if ($err) fail($err);
    return $m;
}
function loadCase(string $f): array {
    $raw = @file_get_contents($f, false, null, 0, CASE_MAX + 1);
    if (!is_string($raw) || strlen($raw) > CASE_MAX) fail('case-read');
    $m = json_decode($raw, true, 32);
    if (checkCase($m) !== null || canon($m) . "\n" !== $raw) fail('case-not-canonical');
    return [$m, hash('sha256', canon($m))];
}
/** pnms-bundle/1 manifest from SEO_PRO_EMIT_MANIFEST_OUT, bound to our case manifest. */
function loadBman(string $file, array $case, string $csha): array {
    $j = readJson($file, BMAN_MAX); if ($j === null) return [null, 'bman-json'];
    if (!keySet($j, BMAN_KEYS)) return [null, 'bman-keys'];
    if ($j['schema'] !== 'pnms-bundle/1') return [null, 'bman-schema'];
    if ($j['build'] !== $case['build']) return [null, 'bman-build'];
    if (!is_array($j['case']) || canon($j['case']) !== canon($case) || $j['case_sha256'] !== $csha) return [null, 'bman-case'];
    $s = $j['sources'];
    if (!is_array($s) || array_keys($s) !== array_merge(SOURCE_KEYS, ['bundle_sha256'])) return [null, 'bman-sources'];
    foreach ($s as $h) if (!hex64($h)) return [null, 'bman-sources'];
    if (!hashMap($j['runtime'], RUNTIME_FILES) || !hashMap($j['oc'], OC_FILES)) return [null, 'bman-files'];
    $e = $j['entrypoint'];
    if (!is_array($e) || ($e['stdout'] ?? null) !== 'pnms-result/1' || !isList($e['env'] ?? null) || !in_array('SEO_PRO_TEST_STORE_CONFIG', $e['env'], true)
        || array_diff($e['env'], ['SEO_PRO_TEST_STORE_CONFIG', 'SEO_PRO_PNMS_CHUNK_SECONDS'])) return [null, 'bman-entrypoint'];
    if (is_array($case['expect'])) foreach (['runtime', 'oc'] as $x) foreach ((array)($case['expect'][$x] ?? []) as $rel => $h) if (($j[$x][$rel] ?? null) !== $h) return [null, 'bman-expect'];
    if (secretKey($j)) return [null, 'bman-secret-key'];
    return [$j, null];
}
function embSources(array $bm): array { $s = $bm['sources']; unset($s['bundle_sha256']); return $s; }

function num($v): bool { return (is_int($v) || is_float($v)) && is_finite((float)$v); }
function trEntry($x, int $min): bool { return keySet($x, ['bytes', 'sha256']) && is_int($x['bytes']) && $x['bytes'] >= $min && hex64($x['sha256']); }
/**
 * Transferred evidence against evidence_transfer (remote file bytes + sha256). events.jsonl travels as the raw
 * string: its length and sha256 must equal the remote file exactly. metrics.json/ledger.json travel DECODED (the
 * remote file may be pretty-printed differently), so their remote hash cannot be recomputed from the decoded value;
 * the declared byte count is bounded here and the stored copy is labelled (see 'result'). 'cap:' = bound, status 3.
 */
function checkEvidence($ev, $tr): ?string {
    if (!keySet($ev, EVIDENCE_KEYS)) return 'evidence-keys';
    if (!keySet($tr, EVIDENCE_KEYS)) return 'evidence-transfer-keys';
    foreach (EVIDENCE_KEYS as $n) if (!trEntry($tr[$n], $n === 'events.jsonl' ? 0 : 2)) return 'evidence-transfer-entry-' . $n;
    $e = $ev['events.jsonl'];
    if (!is_string($e)) return 'evidence-events-type';
    if (strlen($e) !== $tr['events.jsonl']['bytes']) return 'evidence-events-bytes';
    if (!hash_equals($tr['events.jsonl']['sha256'], hash('sha256', $e))) return 'evidence-events-sha256';
    foreach (FILE_MAX as $n => $max) if ($tr[$n]['bytes'] > $max) return 'cap:evidence-' . $n . '-oversize';
    if ($e !== '') {
        if (substr($e, -1) !== "\n" || preg_match('//u', $e) !== 1) return 'evidence-events-framing';
        if (substr_count($e, "\n") > EVENT_LINES_MAX) return 'cap:evidence-events-lines';
        foreach (explode("\n", substr($e, 0, -1)) as $l) { $x = json_decode($l, true, 32); if (!is_array($x) || (isList($x) && $x !== [])) return 'evidence-events-line'; }
    }
    foreach (['metrics.json', 'ledger.json'] as $n) {
        if (!is_array($ev[$n]) || (isList($ev[$n]) && $ev[$n] !== [])) return 'evidence-' . $n . '-type';
    }
    return null;
}
/** Identity of a full or identified-early result against the streamed case manifest + bundle manifest. */
function checkIdent(array $d, array $case, string $csha, array $bm): ?string {
    foreach (['run_id', 'chunk_id', 'stage', 'build'] as $k) if (($d[$k] ?? null) !== $case[$k]) return 'result-identity-' . $k;
    if (($d['manifest_sha256'] ?? null) !== $csha) return 'result-manifest';
    $b = $d['bundle'] ?? null;
    if (!keySet($b, ['sources', 'runtime', 'oc']) || !is_array($b['sources']) || canon($b['sources']) !== canon(embSources($bm))
        || !is_array($b['runtime']) || canon($b['runtime']) !== canon($bm['runtime']) || !is_array($b['oc']) || canon($b['oc']) !== canon($bm['oc'])) return 'result-bundle-sources';
    if (!is_string($d['remote_php'] ?? null) || !preg_match('/^[0-9A-Za-z.+~-]{1,64}$/D', $d['remote_php'])) return 'result-remote-php';
    return null;
}
/** limits: manifest copy, env == dispatched budget, effective chunk == env (<= budget), other effective == manifest, frozen statement/confirm. */
function checkLimits($l, array $case, int $budget, int $st): ?string {
    if (!keySet($l, LIMITS_KEYS)) return 'result-limits-keys';
    if (!is_array($l['manifest']) || canon($l['manifest']) !== canon($case['limits'])) return 'result-limits-manifest';
    $want = []; foreach (LIMIT_MAX as $k => $mx) $want[$k] = is_array($case['limits']) && isset($case['limits'][$k]) ? $case['limits'][$k] : $mx;
    if ($budget > $want['chunk_seconds']) return 'result-limits-budget-above-manifest';
    if ($l['chunk_seconds_env'] !== $budget) return 'result-limits-env';
    if (!keySet($l['statement_seconds'], array_keys(STATEMENT_SECONDS))) return 'result-limits-statement';
    foreach (STATEMENT_SECONDS as $k => $v) if (!num($l['statement_seconds'][$k]) || (float)$l['statement_seconds'][$k] !== $v) return 'result-limits-statement';
    if (!num($l['confirm_seconds']) || (float)$l['confirm_seconds'] !== CONFIRM_SECONDS) return 'result-limits-confirm';
    $e = $l['effective'];
    if ($e === null) return $st === 0 ? 'result-limits-effective-missing' : null;   // supervisor refused the spec
    if (!keySet($e, array_keys(LIMIT_MAX))) return 'result-limits-effective-keys';
    foreach ($want as $k => $w) if (!num($e[$k]) || $e[$k] <= 0 || $e[$k] > $w || $e[$k] > LIMIT_MAX[$k]) return 'result-limits-effective-' . $k;
    if ((float)$e['chunk_seconds'] > (float)$budget || (float)$e['chunk_seconds'] !== (float)$l['chunk_seconds_env']) return 'result-limits-effective-chunk_seconds';
    foreach (['cleanup_seconds', 'max_select', 'max_other'] as $k) if ((float)$e[$k] !== (float)$want[$k]) return 'result-limits-effective-' . $k;
    return null;
}
/** evidence / evidence_transfer / evidence_dir / result_file coherence (mirrors PNMS_Remote::run). */
function checkRetention(array $d, array $case, int $st): ?string {
    $cdir = rtrim($case['run_dir'], '/') . '/chunks/' . $case['chunk_id'];
    $r = $d['reasons']; $ev = $d['evidence']; $tr = $d['evidence_transfer'];
    if (!array_key_exists('evidence_dir', $d['supervisor'])) return 'result-evidence-dir-missing';
    $dir = $d['supervisor']['evidence_dir'];
    if ($dir !== null && !is_string($dir)) return 'result-evidence-dir';
    $hasRf = array_key_exists('result_file', $d);
    if ($hasRf && ($dir === null || $d['result_file'] !== $cdir . '/result.json')) return 'result-file';
    if (!$hasRf && $dir !== null && !($st === 4 && in_array('result-write-failed', $r, true))) return 'result-file-missing';
    if ($ev !== null) {
        if ($dir !== $cdir) return 'result-evidence-dir';
        $e = checkEvidence($ev, $tr);
        return $e === null ? null : (strncmp($e, 'cap:', 4) === 0 ? 'cap:result-' . substr($e, 4) : 'result-' . $e);
    }
    // No evidence: never for status 0; otherwise only with the explicit reason PNMS_Remote records.
    if ($st === 0) return 'result-evidence-required';
    if ($tr === null) {
        if ($dir === null && in_array('supervisor-evidence-unretained', $r, true)) return in_array($st, [1, 2, 4], true) ? null : 'result-evidence-unretained-status';
        if ($dir !== null && $dir !== $cdir && in_array('supervisor-evidence-dir-unexpected', $r, true)) return in_array($st, [1, 4], true) ? null : 'result-evidence-unretained-status';
        return 'result-evidence-null-unexplained';
    }
    if ($dir !== $cdir || !is_array($tr) || array_diff(array_keys($tr), EVIDENCE_KEYS)) return 'result-evidence-transfer-partial';
    foreach ($tr as $n => $x) if (!trEntry($x, $n === 'events.jsonl' ? 0 : 2)) return 'result-evidence-transfer-partial';
    $why = preg_grep(TRANSFER_FAIL_RE, $r);
    if (count($why) !== 1) return 'result-evidence-null-unexplained';
    $failed = preg_replace('/^evidence-transfer-[a-z]+-/', '', reset($why));
    if (isset($tr[$failed]) || !in_array($st, [1, 4], true)) return 'result-evidence-transfer-inconsistent';
    return null;
}
/** pnms-result/1: [doc|null, error|null, form early|full|'']. Errors prefixed 'cap:' are local bounds (status 3). */
function parseResult(string $file, array $case, string $csha, array $bm, int $budget): array {
    $raw = @file_get_contents($file, false, null, 0, RESULT_MAX + 1);
    if (!is_string($raw) || $raw === '') return [null, 'result-missing', ''];
    if (strlen($raw) > RESULT_MAX) return [null, 'cap:result-oversize', ''];
    if (strpos($raw, "\0") !== false || preg_match('//u', $raw) !== 1) return [null, 'result-encoding', ''];
    if ($raw[0] !== '{' || substr($raw, -1) !== "\n") return [null, 'result-framing', ''];
    $d = json_decode($raw, true, 128);
    if (!is_array($d) || isList($d)) return [null, 'result-json', ''];
    if (($d['schema'] ?? null) !== 'pnms-result/1') return [null, 'result-schema', ''];
    $st = $d['status'] ?? null;
    if (!is_int($st) || $st < 0 || $st > 4) return [null, 'result-status', ''];
    if (!is_string($d['label'] ?? null) || !in_array($d['label'], LABELS[$st], true)) return [null, 'result-label', ''];
    $r = $d['reasons'] ?? null;
    if (!isList($r) || count($r) > 64) return [null, 'result-reasons', ''];
    foreach ($r as $x) if (!is_string($x) || !preg_match('/^[A-Za-z0-9:.,_+ -]{1,160}$/D', $x)) return [null, 'result-reasons', ''];
    if (secretKey($d)) return [null, 'result-secret-key', ''];
    $bare = keySet($d, EARLY_KEYS);
    if ($bare || keySet($d, array_merge(EARLY_KEYS, EARLY_ID_KEYS))) {
        // Early form: refusal before any supervisor run, exactly one fixed code, never evidence. Identifiers are
        // present only once the embedded case hash was verified, and then must match the streamed case exactly.
        if (count($r) !== 1) return [null, 'result-early-reasons', 'early'];
        if ($bare) {
            if ($st !== 1 || !preg_match(EARLY_BARE_RE, $r[0])) return [null, 'result-early-code', 'early'];
        } else {
            if (!isset(EARLY_ID_RE[$st]) || !preg_match(EARLY_ID_RE[$st], $r[0])) return [null, 'result-early-code', 'early'];
            $e = checkIdent($d, $case, $csha, $bm); if ($e) return [null, $e, 'early'];
        }
        return [$d, null, 'early'];
    }
    $keys = array_keys($d);
    if (array_diff(RESULT_REQUIRED, $keys)) return [null, 'result-missing-key', 'full'];
    if (array_diff($keys, RESULT_REQUIRED, RESULT_OPTIONAL)) return [null, 'result-unknown-key', 'full'];
    $e = checkIdent($d, $case, $csha, $bm); if ($e) return [null, $e, 'full'];
    if (!is_array($d['manifest']) || canon($d['manifest']) !== canon($case)) return [null, 'result-manifest', 'full'];
    $w = $d['workload'];
    if (!is_array($d['supervisor']) || !is_array($d['cross_check']) || !is_array($d['case_status']) || !isList($d['observed'])
        || !is_array($w) || !is_bool($w['present'] ?? null) || !is_bool($w['valid'] ?? null) || !array_key_exists('evidence', $w)
        || $w['valid'] !== is_array($w['evidence'])) return [null, 'result-shape', 'full'];
    if (($case['stage'] === 'preflight') !== array_key_exists('preflight', $d) || (array_key_exists('preflight', $d) && !is_array($d['preflight']))) return [null, 'result-preflight-key', 'full'];
    $e = checkLimits($d['limits'], $case, $budget, $st); if ($e) return [null, $e, 'full'];
    $e = checkRetention($d, $case, $st); if ($e) return [null, $e, 'full'];
    return [$d, null, 'full'];
}
function budgetArg(string $s): int {
    if (!preg_match('/^[1-9][0-9]{0,2}$/D', $s) || (int)$s > LIMIT_MAX['chunk_seconds']) fail('budget-arg'); return (int)$s;
}
function caseById(array $cases): array {
    $by = [];
    foreach ($cases as $c) {
        if (!is_array($c) || !is_string($c['id'] ?? null) || !is_string($c['status'] ?? null) || !is_array($c['data'] ?? null)) return [null, 'preflight-case-shape'];
        if (isset($by[$c['id']])) return [null, 'preflight-case-duplicate'];
        $by[$c['id']] = $c;
    }
    return [$by, null];
}

// ---- report aggregation: saved verified chunk results (chunks/<id>/result.json) + schedule/status only.
// Never invents samples: a group is aggregated only with exactly 3 completed, source-matched repetitions.
const AGG_STATES = ['cold', 'warm', 'repeat'];
const AGG_METRICS = ['series_ns' => 'instrumented series ns', 'worker_sql_ns' => 'worker SQL ns', 'total_sql' => 'SQL count'];
function m3(array $v): array { sort($v, SORT_NUMERIC); return ['median' => $v[1], 'min' => $v[0], 'max' => $v[2]]; }
/** One saved chunk -> [['key' => source-match key, 'cases' => [id => [state][metric] | null]], null] or [null, code]. */
function aggChunk(string $dir, array $row, array $st): array {
    [$id, $sc, $b, $c, $v, $m, $r] = $row;
    if (!in_array($st[$id][0] ?? 'unrun', LABELS[0], true) || ($st[$id][1] ?? '') !== '0') return [null, 'label-' . ($st[$id][0] ?? 'unrun')];
    $d = readJson("$dir/$id/result.json", 2 * RESULT_MAX, 128);
    if ($d === null) return [null, 'result-unreadable'];
    $mf = $d['manifest'] ?? null;
    if (($d['schema'] ?? null) !== 'pnms-result/1' || ($d['status'] ?? null) !== 0 || ($d['label'] ?? null) !== $st[$id][0] || ($d['chunk_id'] ?? null) !== $id
        || ($d['build'] ?? null) !== $b || !is_array($mf) || ($mf['mode'] ?? null) !== $m || ($mf['collation'] ?? null) !== ($c === '-' ? null : $c)
        || ($mf['variant'] ?? null) !== ($v === '-' ? null : $v) || ($mf['repetition'] ?? null) !== (int)$r) return [null, 'result-identity'];
    $ev = $d['workload']['evidence'] ?? null;
    if (($d['workload']['valid'] ?? false) !== true || !is_array($ev) || !isList($ev['cases'] ?? null)) return [null, 'evidence-missing'];
    $ds = $ev['dataset'] ?? null; $src = $d['bundle']['sources'] ?? null;
    $common = array_diff(SOURCE_KEYS, ['workload_sha256']); $cs = [];
    if (!is_array($src) || !hex64($ev['config_sha256'] ?? null) || !is_array($ds) || !hex64($src['workload_sha256'] ?? null)
        || !hashMap($d['bundle']['runtime'] ?? null, RUNTIME_FILES) || !hashMap($d['bundle']['oc'] ?? null, OC_FILES) || !is_string($d['remote_php'] ?? null)) return [null, 'source-hashes-missing'];
    foreach ($common as $k) { if (!hex64($src[$k] ?? null)) return [null, 'source-hashes-missing']; $cs[$k] = $src[$k]; }
    if ($sc === 'R') { if (!hex64($ds['sample_sha256'] ?? null) || $ds['sample_sha256'] !== ($mf['sample_sha256'] ?? null)) return [null, 'sample-sha'];
    } elseif (!hex64($ds['definition_sha256'] ?? null) || !hex64($ds['schema_sha256'] ?? null)) return [null, 'dataset-hashes'];
    // Build-independent exact sources (workload_sha256 covers per-build runtime files, so it is compared per build in bkey).
    $bkey = canon(['workload' => $src['workload_sha256'], 'runtime' => $d['bundle']['runtime']]);
    $key = canon(['sources' => $cs, 'oc' => $d['bundle']['oc'], 'remote_php' => $d['remote_php'], 'config' => $ev['config_sha256'], 'definition' => $ds['definition_sha256'] ?? null,
        'schema' => $ds['schema_sha256'] ?? null, 'sample' => $ds['sample_sha256'] ?? null, 'engine' => $mf['engine'] ?? null]);
    $cases = [];
    foreach ($ev['cases'] as $k) {
        if (!is_array($k) || !is_string($k['id'] ?? null) || !is_array($k['data']['timed'] ?? null)) continue;
        $t = $k['data']['timed']; $x = null;
        if (($k['status'] ?? null) === 'pass') {
            $x = ['nk' => [$t['cold']['n'] ?? null, $t['cold']['k'] ?? null]];
            foreach (AGG_STATES as $s) foreach (array_keys(AGG_METRICS) as $mm) {
                $val = $t[$s][$mm] ?? null; if (!is_int($val) || $val < 0) { $x = null; break 2; } $x[$s][$mm] = $val;
            }
        }
        $cases[$k['id']] = isset($cases[$k['id']]) ? null : $x; // duplicate id = unusable
    }
    return [['key' => $key, 'bkey' => $bkey, 'gap' => $d['label'] !== 'completed', 'cases' => $cases], null];
}
function aggregate(array $rows, array $st, string $dir): array {
    $groups = [];
    foreach ($rows as $row) {
        if (count($row) < 9 || $row[8] !== 'yes') continue;
        if ($row[1] === 'R') $g = 'read-only-stand natural';
        elseif ($row[1] === 'N' || $row[1] === 'F') $g = "timing {$row[5]} {$row[3]} {$row[4]}";
        else continue;
        $groups[$g]['paired'] = $row[1] !== 'F';
        $groups[$g]['rows'][(int)$row[6]][$row[2]] = $row;
    }
    $out = []; $total = 0;
    foreach ($groups as $g => $G) {
        $need = $G['paired'] ? ['baseline', 'candidate'] : ['candidate'];
        $reps = []; $rej = []; $basis = []; $gaps = [];
        foreach ([1, 2, 3] as $r) {
            $got = []; $why = null;
            foreach ($need as $b) {
                $row = $G['rows'][$r][$b] ?? null;
                if ($row === null) { $why = "$b-not-scheduled"; break; }
                [$c, $e] = aggChunk($dir, $row, $st);
                if ($c === null) { $why = "{$row[0]}:$e"; break; }
                $got[$b] = $c + ['id' => $row[0], 'order' => (int)$row[7]];
            }
            if ($why === null && $G['paired'] && $got['baseline']['key'] !== $got['candidate']['key']) $why = 'source-mismatch';
            if ($why === null && $reps && ($ref = reset($reps))['candidate']['key'] !== $got['candidate']['key']) $why = 'cross-rep-source-mismatch';
            if ($why === null && $reps) foreach ($need as $b) if ($ref[$b]['bkey'] !== $got[$b]['bkey']) { $why = "$b-build-source-drift"; break; }
            if ($why !== null) { $rej[] = "r$r:$why"; continue; }
            $reps[$r] = $got;
            foreach ($got as $x) if ($x['gap']) $gaps[] = "r$r:{$x['id']}";
            uasort($got, function ($x, $y) { return $x['order'] <=> $y['order']; });
            $basis[] = "r$r " . implode('->', array_map(function ($b, $x) { return $x['id'] . '(' . $b[0] . ')'; }, array_keys($got), $got));
        }
        $res = ['paired' => $G['paired'], 'completed' => count($reps), 'rejected' => $rej, 'basis' => implode(', ', $basis), 'stats' => [], 'cases_rejected' => [], 'gaps' => $gaps];
        if (count($reps) === 3) {
            $ids = [];
            foreach ($reps as $got) foreach ($got as $x) $ids += array_fill_keys(array_keys($x['cases']), 1);
            foreach (array_keys($ids) as $cid) {
                $why = null;
                foreach ($reps as $r => $got) {
                    foreach ($need as $b) if (!is_array($got[$b]['cases'][$cid] ?? null)) { $why = "r$r:$b-case-not-pass-or-missing"; break 2; }
                    if ($G['paired'] && $got['baseline']['cases'][$cid]['nk'] !== $got['candidate']['cases'][$cid]['nk']) { $why = "r$r:shape-mismatch"; break; }
                }
                if ($why !== null) { $res['cases_rejected'][] = "$cid:$why"; continue; }
                foreach (AGG_STATES as $s) foreach (array_keys(AGG_METRICS) as $mm) {
                    $col = []; foreach ($need as $b) $col[$b] = array_map(function ($got) use ($b, $cid, $s, $mm) { return $got[$b]['cases'][$cid][$s][$mm]; }, array_values($reps));
                    $e = ['candidate' => m3($col['candidate'])];
                    if ($G['paired']) { $e['baseline'] = m3($col['baseline']); $e['delta'] = m3(array_map(function ($x, $y) { return $x - $y; }, $col['candidate'], $col['baseline'])); }
                    $res['stats'][$cid][$s][$mm] = $e;
                }
            }
            if ($res['stats']) $total++;
        }
        $out[$g] = $res;
    }
    $f = function ($e) { return $e === null ? '-' : $e['median'] . ' [' . $e['min'] . '..' . $e['max'] . ']'; };
    $md = "\n## Timing aggregation (median [min..max] over exactly 3 completed repetitions)\n\n"
        . "Computed only from saved, verified status-0 chunk results (`completed` or `completed-with-measurement-gaps`, gaps listed separately); all 3 repetitions must share build-independent sources, OC sources, remote PHP, injected config hash and dataset definition/schema (or frozen sample) hashes. Delta = candidate - baseline per pair, then median/min/max of the 3 paired deltas. Instrumented series time and worker SQL time are separate metrics; cold/warm/repeat are never merged. Raw per-chunk evidence stays in chunks/. Three samples support no p95/significance claim.\n\n"
        . "Groups with 3 completed matched repetitions: $total of " . count($out) . ".\n\n";
    foreach ($out as $g => $res) if ($res['completed'] !== 3 || !$res['stats']) {
        $md .= "- `$g`: not aggregated; completed " . ($res['paired'] ? 'matched pairs' : 'candidate runs') . ' ' . $res['completed'] . '/3'
            . ($res['rejected'] ? '; ' . implode(', ', $res['rejected']) : '') . ($res['cases_rejected'] ? '; cases: ' . implode(', ', $res['cases_rejected']) : '') . "\n";
    }
    if ($total > 0) {
        $md .= "\n| group | case | state | metric | baseline | candidate | delta (cand-base) | basis |\n|---|---|---|---|---|---|---|---|\n";
        foreach ($out as $g => $res) foreach ($res['stats'] as $cid => $ss) foreach ($ss as $s => $ms) foreach ($ms as $mm => $e) {
            $lab = $res['paired'] ? $g : "$g (DIAGNOSTIC: forced-exact candidate-only, no baseline, no delta)";
            $md .= "| $lab | $cid | $s | " . AGG_METRICS[$mm] . ' | ' . $f($e['baseline'] ?? null) . ' | ' . $f($e['candidate']) . ' | ' . $f($e['delta'] ?? null) . ' | ' . $res['basis'] . " |\n";
        }
        foreach ($out as $g => $res) if ($res['stats'] && $res['cases_rejected']) $md .= "\n- `$g` cases excluded: " . implode(', ', $res['cases_rejected']) . "\n";
        foreach ($out as $g => $res) if ($res['stats'] && $res['gaps']) $md .= "\n- `$g` includes completed-with-measurement-gaps chunks (optional work gaps; required timings present): " . implode(', ', $res['gaps']) . "\n";
    }
    return ['md' => $md, 'groups' => $out, 'total' => $total];
}
function statusMap(string $f): array { $s = []; foreach (tsv($f) as $r) $s[$r[0]] = [$r[1] ?? '', $r[2] ?? '']; return $s; }

$a = $argv; array_shift($a); $cmd = array_shift($a) ?? '';
switch ($cmd) {
case 'mono': echo intdiv(hrtime(true), 1000000), "\n"; break;
case 'sha': $h = @hash_file('sha256', $a[0] ?? ''); if (!$h) fail('sha'); echo $h, "\n"; break;
case 'shastr': echo hash('sha256', $a[0] ?? ''), "\n"; break;
case 'size': $s = @filesize($a[0] ?? ''); echo $s === false ? -1 : $s, "\n"; break;
case 'manifest': // OUT META_TSV HASHES_TSV SCHEDULE_TSV
    [$outf, $meta, $hashes, $sched] = $a;
    $m = ['manifest' => 1];
    foreach (tsv($meta) as $r) $m[$r[0]] = $r[1] ?? '';
    foreach (['global_seconds', 'global_chunks', 'chunk_seconds', 'cleanup_seconds', 'max_select', 'max_other', 'watchdog_extra_seconds', 'schedule_total', 'overhead_seconds', 'slack_ms'] as $k) if (isset($m[$k])) $m[$k] = (int)$m[$k];
    $m['git_status'] = array_values(array_filter(explode('|', $m['git_status'] ?? ''), 'strlen'));
    $m['source_sha256'] = []; foreach (tsv($hashes) as $r) $m['source_sha256'][$r[1]] = $r[0];
    $m['schedule'] = [];
    foreach (tsv($sched) as $r) {
        $rep = in_array($r[1], ['R', 'N', 'F'], true) ? (int)$r[6] : null; $pp = in_array($r[1], ['R', 'N'], true) ? (int)$r[7] : null;
        $m['schedule'][] = ['id' => $r[0], 'stage_code' => $r[1], 'stage' => STAGE_NAMES[$r[1]], 'build' => $r[2], 'collation' => $r[3] === '-' ? null : $r[3],
            'variant' => $r[4] === '-' ? null : $r[4], 'mode' => $r[5], 'repetition' => $rep, 'pair_position' => $pp, 'selected' => $r[8] === 'yes', 'fixture_prefix' => $r[9]];
    }
    $m['schedule_sha256'] = hash_file('sha256', $sched);
    writeNew($outf, jenc($m)); echo hash_file('sha256', $outf), "\n"; break;
case 'case': // OUT DATASET|- RUNTIME_ROOT|- OC_DIR|- k=v... -> canonical pnms-case/1; prints its canonical sha256
    $outf = array_shift($a); $ds = array_shift($a); $rt = array_shift($a); $oc = array_shift($a);
    $d = null; if ($ds !== '-') { $d = readJson($ds, DOC_MAX); if ($d === null) fail('dataset-read'); }
    $m = buildCase(kv($a), $d, $rt, $oc);
    writeNew($outf, canon($m) . "\n"); echo hash('sha256', canon($m)), "\n"; break;
case 'bundleman': // MANIFEST_OUT BUNDLE CASE ARTIFACT CHUNK BUILD -> validated copy; prints artifact sha256
    [$mf, $bf, $cf, $art, $chunk, $build] = $a;
    [$case, $csha] = loadCase($cf);
    if ($case['chunk_id'] !== $chunk || $case['build'] !== $build) fail('bman-identity');
    [$bm, $err] = loadBman($mf, $case, $csha); if ($err) fail($err);
    $bsha = @hash_file('sha256', $bf); if (!is_string($bsha) || !hash_equals($bm['sources']['bundle_sha256'], $bsha)) fail('bman-bundle-sha');
    $src = (string)file_get_contents($bf);
    if (!preg_match("/\ndefine\('PNMS_EMBED', '([A-Za-z0-9+\/=]+)'\);\nPNMS_Remote::main\(\);\n\z/", $src, $mm)) fail('bman-embed');
    $emb = json_decode((string)base64_decode($mm[1], true), true, 64); $src = null;
    if (!is_array($emb) || ($emb['schema'] ?? null) !== 'pnms-bundle/1' || !is_array($emb['case'] ?? null) || canon($emb['case']) !== canon($case)
        || ($emb['case_sha256'] ?? null) !== $csha || !is_array($emb['sources'] ?? null) || canon($emb['sources']) !== canon(embSources($bm))
        || !is_array($emb['runtime'] ?? null) || canon($emb['runtime']) !== canon($bm['runtime']) || !is_array($emb['oc'] ?? null) || canon($emb['oc']) !== canon($bm['oc'])) fail('bman-embed-mismatch');
    writeNew($art, jenc(['emitter_bundle_manifest' => 1, 'chunk_id' => $chunk, 'build' => $build, 'case_sha256' => $csha, 'bundle_manifest' => $bm]));
    echo hash_file('sha256', $art), "\n"; break;
case 'result': // STDOUT OUTDIR CASE BUNDLE_MANIFEST BUDGET -> validated evidence; prints error/class/status/gaps/form/reasons/evidence
    [$file, $dir, $cf, $mf, $bud] = $a;
    $budget = budgetArg($bud);
    [$case, $csha] = loadCase($cf);
    [$bm, $berr] = loadBman($mf, $case, $csha);
    if ($berr) { out('error', $berr); out('class', 'protocol'); out('status', 1); out('gaps', 0); out('form', 'none'); break; }
    [$d, $err, $form] = parseResult($file, $case, $csha, $bm, $budget);
    if ($err) {
        // Never accepted, nothing stored. A local bound is incomplete (3); anything else is a protocol failure (1).
        $cap = strncmp($err, 'cap:', 4) === 0;
        out('error', $err); out('class', $cap ? 'cap' : 'protocol'); out('status', $cap ? 3 : 1); out('gaps', 0); out('form', $form ?: 'none'); break;
    }
    $ev = $d['evidence'] ?? null; $tr = $d['evidence_transfer'] ?? null; unset($d['evidence']);
    $rj = jenc($d); if (strlen($rj) > 2 * RESULT_MAX) { out('error', 'cap:result-reencode-oversize'); out('class', 'cap'); out('status', 3); out('gaps', 0); out('form', $form); break; }
    // metrics/ledger: re-read as objects so {} vs [] survives; stored copy is a re-encoding of the decoded value.
    $evo = null;
    if (is_array($ev)) {
        $o = json_decode((string)file_get_contents($file, false, null, 0, RESULT_MAX + 1), false, 128);
        $evo = is_object($o) && isset($o->evidence) && is_object($o->evidence) ? $o->evidence : null;
        if ($evo === null) { out('error', 'result-evidence-reread'); out('class', 'protocol'); out('status', 1); out('gaps', 0); out('form', $form); break; }
    }
    writeNew($dir . '/result.json', $rj);
    $stored = null;
    if (is_array($ev)) {
        writeNew($dir . '/events.jsonl', $ev['events.jsonl']);
        $stored['events.jsonl'] = ['bytes' => strlen($ev['events.jsonl']), 'sha256' => hash('sha256', $ev['events.jsonl']), 'representation' => 'raw-bytes', 'matches_remote' => true];
        foreach (['metrics.json', 'ledger.json'] as $n) {
            $c = jenc($evo->{$n}); writeNew($dir . '/' . $n, $c); $same = hash_equals($tr[$n]['sha256'], hash('sha256', $c));
            $stored[$n] = ['bytes' => strlen($c), 'sha256' => hash('sha256', $c), 'representation' => $same ? 'raw-bytes' : 'decoded-reencoded', 'matches_remote' => $same];
        }
    }
    if ($tr !== null) writeNew($dir . '/evidence-transfer.json', jenc(['evidence_transfer' => 1, 'remote_files' => $tr ?: new stdClass(), 'stored' => $stored,
        'note' => 'remote_files = byte length + sha256 of the remote chunk files. events.jsonl is stored byte-identical (verified). metrics.json/ledger.json are transferred decoded; '
            . 'a decoded-reencoded copy differs from the remote file only in JSON formatting and its remote hash is recorded, not recomputable.']));
    out('error', ''); out('class', ''); out('status', $d['status']); out('gaps', $d['label'] === 'completed-with-measurement-gaps' ? 1 : 0); out('form', $form);
    out('evidence', is_array($ev) ? 'present' : 'absent');
    out('reasons', substr(implode('+', str_replace(' ', '_', $d['reasons'])), 0, 400)); break;
case 'dataset': // STDOUT OUT CASE BUNDLE_MANIFEST BUDGET -> freezes preflight facts from authenticated workload case evidence
    [$file, $outf, $cf, $mf, $bud] = $a;
    $budget = budgetArg($bud);
    [$case, $csha] = loadCase($cf);
    [$bm, $berr] = loadBman($mf, $case, $csha);
    if ($berr) { out('error', $berr); out('class', 'malformed'); break; }
    [$d, $err, $form] = parseResult($file, $case, $csha, $bm, $budget);
    if ($err) { out('error', $err); out('class', strncmp($err, 'cap:', 4) === 0 ? 'cap' : 'malformed'); break; }
    if ($form === 'early') { out('error', 'preflight-early-result'); out('class', 'blocked'); break; }
    $w = $d['workload'];
    if (!$w['valid'] || !isList($w['evidence']['cases'] ?? null)) { out('error', 'preflight-workload-evidence'); out('class', 'missing'); break; }
    [$by, $cerr] = caseById($w['evidence']['cases']);
    if ($cerr) { out('error', $cerr); out('class', 'malformed'); break; }
    $s = $by['server']['data'] ?? null;
    if (!is_array($s)) { out('error', 'preflight-server-missing'); out('class', 'missing'); break; }
    if (!in_array($s['family'] ?? null, ['mysql', 'mariadb'], true) || !is_string($s['version'] ?? null) || !preg_match('/^\d{1,3}\.\d{1,3}\.\d{1,4}$/D', $s['version'])
        || !is_bool($s['analyze'] ?? null)) { out('error', 'preflight-server-shape'); out('class', 'malformed'); break; }
    $coll = $by['collations']['data']['available'] ?? null;
    if (!isList($coll)) { out('error', 'preflight-collations-missing'); out('class', 'missing'); break; }
    if (array_diff($coll, COLLATIONS) || count($coll) !== count(array_unique($coll))) { out('error', 'preflight-collations-shape'); out('class', 'malformed'); break; }
    $coll = array_values(array_intersect(COLLATIONS, $coll));
    if (!isset($by['shop-definition'])) { out('error', 'preflight-shop-definition-missing'); out('class', 'missing'); break; }
    $eng = $by['shop-definition']['data']['tables']['seo_url']['engine'] ?? null;
    $engine = in_array($eng, ENGINES, true) ? $eng : null;
    $sample = null; $ssha = null; $scode = 'sample-case-missing';
    if (isset($by['sample'])) {
        $sc = $by['sample'];
        if ($sc['status'] !== 'pass') $scode = 'sample-case-not-pass';
        else {
            $ns = normSample($sc['data']['sample'] ?? null);
            if ($ns === null) { out('error', 'preflight-sample-shape'); out('class', 'malformed'); break; }
            $h = hash('sha256', canon($ns));
            if (!hex64($sc['data']['sample_sha256'] ?? null) || !hash_equals($h, $sc['data']['sample_sha256'])) { out('error', 'preflight-sample-sha'); out('class', 'malformed'); break; }
            $sample = $ns; $ssha = $h; $scode = null;
        }
    }
    $shapes = [];
    if ($sample !== null) foreach ($sample as $k => $v) if ($v === null) $shapes[] = $k;
    $o = ['preflight_dataset' => 1, 'source_chunk' => $case['chunk_id'], 'manifest_sha256' => $csha, 'result_status' => $d['status'], 'result_label' => $d['label'],
        'server' => ['family' => $s['family'], 'version' => $s['version'], 'analyze' => $s['analyze']], 'remote_php' => $d['remote_php'],
        'engine' => $engine, 'engine_code' => $engine === null ? 'shop-engine-unsupported' : null,
        'collations_available' => $coll, 'collations_missing' => array_values(array_diff(COLLATIONS, $coll)),
        'sample' => $sample, 'sample_sha256' => $ssha, 'sample_code' => $scode, 'shapes_missing' => $shapes];
    writeNew($outf, jenc($o));
    out('error', ''); out('class', ''); out('engine', $engine ?? '-'); out('collations', implode(',', $coll));
    out('sample', $sample === null ? 0 : 1); out('sample_code', $scode ?? '-'); out('shapes_missing', implode(',', $shapes)); out('sha', hash_file('sha256', $outf)); break;
case 'freeze': // OUT DATASET_JSON|- PREFLIGHT_STATUS CASES_TSV BUNDLES_TSV EMITTER_TSV
    [$outf, $ds, $st, $cases, $bund, $emit] = $a;
    $r = ['preflight' => 1, 'preflight_status' => (int)$st, 'dataset' => $ds === '-' ? null : json_decode((string)file_get_contents($ds), true),
        'dataset_sha256' => $ds === '-' ? null : hash_file('sha256', $ds), 'case_manifest_sha256' => [], 'bundle_sha256' => [],
        'emitter_bundle_manifests' => []];
    foreach (tsv($cases) as $c) $r['case_manifest_sha256'][$c[0]] = $c[1];
    foreach (tsv($bund) as $c) $r['bundle_sha256'][$c[0]] = $c[1];
    foreach (tsv($emit) as $c) {
        if (count($c) !== 3 || !hex64($c[2])) fail('freeze-emitter-row');
        $r['emitter_bundle_manifests'][$c[0]] = ['artifact' => $c[1], 'sha256' => $c[2]];
    }
    writeNew($outf, jenc($r)); echo hash_file('sha256', $outf), "\n"; break;
case 'stderr': // IN OUT CAP: transport stderr is NEVER copied; only fixed codes + size + digest.
    [$in, $outf, $cap] = $a; $n = @filesize($in); if ($n === false) $n = -1;
    $h = $n >= 0 ? @hash_file('sha256', $in) : false;
    $d = ['transport_stderr' => 1, 'content' => 'withheld', 'bytes_captured' => $n, 'capture_cap' => (int)$cap,
        'truncated' => $n > (int)$cap, 'sha256' => $h ?: null, 'code' => $n < 0 ? 'stderr-unreadable' : ($n === 0 ? 'stderr-empty' : 'stderr-present')];
    writeNew($outf, jenc($d)); out('code', $d['code']); out('sha', $d['sha256'] ?? '-'); break;
case 'selftest-fixture': // DIR: offline fixtures for --self-test only (synthetic; never stand evidence)
    $dir = $a[0];
    $case = buildCase(['run_id' => 'selftest-run', 'chunk_id' => 'c001', 'stage' => 'preflight', 'build' => 'candidate', 'run_dir' => '/tmp/seo-pro-pns-selftest-run',
        'fixture_prefix' => 'pnsx_0123abcdc001_', 'collation' => '-', 'variant' => '-', 'mode' => 'natural', 'repetition' => '-', 'pair_position' => '-',
        'chunk_seconds' => '180', 'cleanup_seconds' => '30', 'max_select' => '1000', 'max_other' => '6000'], null, '-', '-');
    $csha = hash('sha256', canon($case));
    writeNew("$dir/case.json", canon($case) . "\n");
    $h = function ($s) { return hash('sha256', (string)$s); };
    $src = []; foreach (SOURCE_KEYS as $k) $src[$k] = $h($k);
    $rt = []; foreach (RUNTIME_FILES as $f) $rt[$f] = $h($f);
    $oc = []; foreach (OC_FILES as $f) $oc[$f] = $h($f);
    $embed = ['schema' => 'pnms-bundle/1', 'case' => $case, 'case_sha256' => $csha, 'sources' => $src, 'runtime' => $rt, 'oc' => $oc, 'workload' => 'x', 'cleanup' => 'y'];
    $bundle = "<?php\n// synthetic\ndefine('PNMS_EMBED', '" . base64_encode(json_encode($embed, JSON_UNESCAPED_SLASHES)) . "');\nPNMS_Remote::main();\n";
    writeNew("$dir/bundle.php", $bundle); writeNew("$dir/bundle-tampered.php", $bundle . ' ');
    $bm = ['schema' => 'pnms-bundle/1', 'build' => 'candidate', 'case' => $case, 'case_sha256' => $csha, 'sources' => $src + ['bundle_sha256' => $h($bundle)],
        'runtime' => $rt, 'oc' => $oc, 'checks' => [], 'php_local' => PHP_VERSION,
        'entrypoint' => ['remote' => 'stdin', 'env' => ['SEO_PRO_TEST_STORE_CONFIG'], 'stdout' => 'pnms-result/1', 'exit' => '0..4']];
    writeNew("$dir/bman.json", jenc($bm));
    $sample = ['three' => ['groups' => [['category_id' => 3, 'ids' => range(1, 7)], ['category_id' => 4, 'ids' => range(8, 14)], ['category_id' => 5, 'ids' => range(15, 20)]]],
        'one' => ['category_id' => 6, 'ids' => range(21, 40)], 'none' => null];
    $cases = [
        ['id' => 'server', 'status' => 'pass', 'code' => null, 'gaps' => false, 'data' => ['family' => 'mysql', 'version' => '8.0.36', 'analyze' => true, 'variables' => []]],
        ['id' => 'collations', 'status' => 'pass', 'code' => null, 'gaps' => false, 'data' => ['available' => COLLATIONS, 'missing' => []]],
        ['id' => 'shop-definition', 'status' => 'pass', 'code' => null, 'gaps' => false, 'data' => ['tables' => ['seo_url' => ['engine' => 'InnoDB', 'collation' => 'utf8mb4_general_ci']]]],
        ['id' => 'sample', 'status' => 'pass', 'code' => null, 'gaps' => false, 'data' => ['sample' => $sample, 'sample_sha256' => $h(canon($sample))]],
    ];
    $base = ['schema' => 'pnms-result/1', 'status' => 0, 'label' => 'completed', 'reasons' => ['expected-probe-cancel'], 'run_id' => 'selftest-run', 'chunk_id' => 'c001',
        'stage' => 'preflight', 'build' => 'candidate', 'manifest' => $case, 'manifest_sha256' => $csha, 'bundle' => ['sources' => $src, 'runtime' => $rt, 'oc' => $oc],
        'remote_php' => '8.2.0',
        // Same shape/flags as PNMS_Remote::run (budget 120 s passed via SEO_PRO_PNMS_CHUNK_SECONDS; floats encode as ints).
        'limits' => ['effective' => ['chunk_seconds' => 120.0, 'cleanup_seconds' => 30.0, 'max_select' => 1000, 'max_other' => 6000], 'manifest' => $case['limits'],
            'chunk_seconds_env' => 120, 'statement_seconds' => ['setup' => 10.0, 'measure' => 2.0, 'diagnostic' => 2.0, 'cleanup' => 2.0], 'confirm_seconds' => 3.0],
        'supervisor' => ['status' => 0, 'findings' => [], 'ledger' => new stdClass(), 'evidence_dir' => '/tmp/seo-pro-pns-selftest-run/chunks/c001', 'expected_error' => null],
        'workload' => ['present' => true, 'valid' => true, 'code' => null, 'evidence' => ['schema' => 'pnms-evidence/1', 'cases' => $cases, 'statements' => []]],
        'cross_check' => ['ok' => true, 'code' => 'exact'], 'case_status' => new stdClass(), 'observed' => [],
        'evidence_transfer' => null, 'preflight' => ['sample_status' => 'pass'],
        'result_file' => '/tmp/seo-pro-pns-selftest-run/chunks/c001/result.json',
        'evidence' => ['events.jsonl' => "{\"event\":\"start\"}\n", 'metrics.json' => ['m' => 1], 'ledger.json' => ['l' => 1]]];
    $pp = function ($d) { return json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"; };
    // evidence_transfer as PNMS_Remote::transfer computes it over the remote files (pretty or compact raw JSON).
    $trOf = function (array $ev, bool $compact = false) use ($pp) {
        $t = ['events.jsonl' => ['bytes' => strlen($ev['events.jsonl']), 'sha256' => hash('sha256', $ev['events.jsonl'])]];
        foreach (['ledger.json', 'metrics.json'] as $n) { $raw = $compact ? json_encode($ev[$n], JSON_UNESCAPED_SLASHES) : $pp($ev[$n]); $t[$n] = ['bytes' => strlen($raw), 'sha256' => hash('sha256', $raw)]; }
        return $t;
    };
    $base['evidence_transfer'] = $trOf($base['evidence']);
    $ids = []; foreach (EARLY_ID_KEYS as $k) $ids[$k] = $base[$k];
    $early = function (int $st, string $code, ?array $ids) use ($pp) {
        return $pp(['schema' => 'pnms-result/1', 'status' => $st, 'label' => $st === 2 ? 'not-run-preflight' : 'failed', 'reasons' => [$code]] + ($ids ?? []));
    };
    $v = ['ok0' => $pp($base)];
    $x = $base; $x['status'] = 3; $x['label'] = 'incomplete'; $v['inc3'] = $pp($x);
    $x = $base; $x['label'] = 'completed-with-measurement-gaps'; $v['gaps0'] = $pp($x);
    // Early forms exactly as PNMS_Remote::early(): identified once the case hash is verified.
    $v['early2'] = $early(2, 'mysqli-unavailable', $ids);
    $v['early2cfg'] = $early(2, 'PNMS_ConfigError:config-path', $ids);
    $v['early1dur'] = $early(1, 'chunk-seconds-env-invalid', $ids);
    $v['early1bare'] = $early(1, 'manifest-hash', null);
    $v['early2bare'] = $early(2, 'mysqli-unavailable', null);
    $v['early2badcode'] = $early(2, 'payload-hash', $ids);
    $x = $ids; $x['chunk_id'] = 'c002'; $v['earlyidwrong'] = $early(1, 'payload-hash', $x);
    $v['earlypartial'] = $early(1, 'payload-hash', ['run_id' => 'selftest-run']);
    $v['early0'] = $pp(['schema' => 'pnms-result/1', 'status' => 0, 'label' => 'completed', 'reasons' => []]);
    // Full status 2 (Performance Schema / control denied) with retained bounded evidence: empty events, empty ledger.
    $x = $base; $x['status'] = 2; $x['label'] = 'not-run-preflight'; $x['reasons'] = ['control-preflight-denied:performance_schema'];
    $x['supervisor'] = ['status' => 2, 'findings' => [['code' => 'control-preflight-denied', 'severity' => 2]], 'ledger' => new stdClass(),
        'evidence_dir' => '/tmp/seo-pro-pns-selftest-run/chunks/c001', 'expected_error' => null];
    $x['workload'] = ['present' => false, 'valid' => false, 'code' => 'missing', 'evidence' => null];
    $x['cross_check'] = ['ok' => false, 'code' => 'no-evidence'];
    $x['evidence'] = ['events.jsonl' => '', 'metrics.json' => ['captures' => []], 'ledger.json' => ['entries' => new stdClass()]];
    $x['evidence_transfer'] = $trOf($x['evidence']); $pf2 = $x; $v['pf2retained'] = $pp($x);
    $x = $pf2; $x['reasons'][] = 'supervisor-evidence-unretained'; $x['supervisor']['evidence_dir'] = null; $x['evidence'] = null; $x['evidence_transfer'] = null;
    unset($x['result_file']); $v['pf2unretained'] = $pp($x);
    $x['status'] = 3; $x['label'] = 'incomplete'; $v['unret3'] = $pp($x);
    $x = $base; $x['evidence'] = null; $x['evidence_transfer'] = null; $v['nullev0'] = $pp($x);
    $x['status'] = 1; $x['label'] = 'failed'; $v['nullev1noreason'] = $pp($x);
    $x['reasons'][] = 'evidence-transfer-oversize-metrics.json'; $x['evidence_transfer'] = array_intersect_key($base['evidence_transfer'], ['events.jsonl' => 1, 'ledger.json' => 1]);
    $v['nullev1reason'] = $pp($x);
    $x = $base; $x['evidence_transfer']['events.jsonl']['sha256'] = $h('x'); $v['evsha'] = $pp($x);
    $x = $base; $x['evidence_transfer']['events.jsonl']['bytes']++; $v['evbytes'] = $pp($x);
    $x = $base; $x['evidence_transfer']['metrics.json']['bytes'] = FILE_MAX['metrics.json'] + 1; $v['evcap'] = $pp($x);
    $x = $base; $x['evidence_transfer'] = $trOf($x['evidence'], true); $v['rawcompact0'] = $pp($x);
    $x = $base; $x['limits']['chunk_seconds_env'] = 100; $v['limenv'] = $pp($x);
    $x = $base; $x['limits']['effective']['chunk_seconds'] = 180; $v['limeff'] = $pp($x);
    $x = $base; $x['limits']['statement_seconds']['measure'] = 5; $v['limstmt'] = $pp($x);
    $x = $base; $x['limits']['manifest']['max_select'] = 999; $v['limman'] = $pp($x);
    $x = $base; $x['limits']['effective'] = null; $v['limnull0'] = $pp($x);
    $x = $base; unset($x['limits']); $v['nolimits'] = $pp($x);
    $x = $base; unset($x['result_file']); $v['nofile0'] = $pp($x);
    $x = $base; unset($x['preflight']); $v['nopreflight'] = $pp($x);
    $v['malformed'] = "{\"schema\": \"pnms-result/1\",\n";
    $v['truncated'] = substr($v['ok0'], 0, intdiv(strlen($v['ok0']), 2));
    $v['badutf8'] = "{\"schema\":\"pnms-result/1\",\"x\":\"\xff\"}\n";
    $x = $base; $x['chunk_id'] = 'c002'; $v['identity'] = $pp($x);
    $x = $base; $x['manifest']['fixture_prefix'] = 'pnsx_0123abcdc002_'; $v['manifest'] = $pp($x);
    $x = $base; $x['bundle']['sources']['lib_sha256'] = $h('other'); $v['sources'] = $pp($x);
    $x = $base; $x['label'] = 'failed'; $v['badlabel'] = $pp($x);
    $x = $base; $x['status'] = 7; $v['badstatus'] = $pp($x);
    $x = $base; $x['supervisor']['db_password'] = 'x'; $v['secret'] = $pp($x);
    $x = $base; $x['extra'] = 1; $v['unknownkey'] = $pp($x);
    $x = $base; unset($x['evidence']); $v['noevidence'] = $pp($x);
    $x = $base; $x['evidence']['events.jsonl'] = str_repeat("{\"a\":1}\n", 262145); $x['evidence_transfer'] = $trOf($x['evidence']); $v['evbig'] = $pp($x);
    $x = $base; $x['evidence']['events.jsonl'] = "not-json\n"; $x['evidence_transfer'] = $trOf($x['evidence']); $v['evline'] = $pp($x);
    $x = $base; array_splice($x['workload']['evidence']['cases'], 3, 1); $v['pfnosample'] = $pp($x);
    $x = $base; array_splice($x['workload']['evidence']['cases'], 0, 1); $v['pfnoserver'] = $pp($x);
    $x = $base; $x['workload']['evidence']['cases'][3]['data']['sample_sha256'] = $h('x'); $v['pfbadsha'] = $pp($x);
    $x = $base; $x['workload']['evidence']['cases'][2]['data']['tables']['seo_url']['engine'] = 'Aria'; $v['pfengine'] = $pp($x);
    $v['oversize'] = str_repeat(' ', RESULT_MAX + 1);
    foreach ($v as $n => $c) writeNew("$dir/$n.out", $c);
    writeNew("$dir/empty.out", '');
    echo "ok\n"; break;
case 'aggregate': // SCHEDULE_TSV STATUS_TSV CHUNKS_DIR -> markdown section on stdout
    [$sched, $stf, $dir] = $a;
    echo aggregate(tsv($sched), statusMap($stf), $dir)['md']; break;
case 'selftest-aggregate': // DIR: synthetic three-pair aggregation checks (offline only; never stand evidence)
    $dir = $a[0]; $h = function ($s) { return hash('sha256', (string)$s); };
    $rows = []; $n = 0;
    foreach ([1, 2, 3] as $r) foreach ($r === 2 ? ['candidate', 'baseline'] : ['baseline', 'candidate'] as $o => $b)
        $rows[] = [sprintf('c%03d', ++$n), 'N', $b, 'utf8mb4_bin', 'clean', 'natural', (string)$r, (string)($o + 1), 'yes', 'p'];
    foreach ([1, 2, 3] as $r) $rows[] = [sprintf('c%03d', ++$n), 'F', 'candidate', 'utf8mb4_general_ci', 'clean', 'forced-exact', (string)$r, '1', 'yes', 'p'];
    $series = ['baseline' => [1 => 100, 2 => 300, 3 => 200], 'candidate' => [1 => 90, 2 => 150, 3 => 400]];
    $mk = function (string $sub, ?callable $tweak) use ($dir, $rows, $series, $h) {
        @mkdir("$dir/$sub", 0700); $st = [];
        foreach ($rows as $row) {
            [$id, , $b, $c, $v, $m, $r] = $row; $rv = $row[1] === 'F' ? 500 + (int)$r : $series[$b][(int)$r];
            $t = []; foreach (AGG_STATES as $i => $s) $t[$s] = ['n' => 20, 'k' => 3, 'series_ns' => $rv + $i, 'worker_sql_ns' => intdiv($rv, 2), 'total_sql' => 23];
            $d = ['schema' => 'pnms-result/1', 'status' => 0, 'label' => 'completed', 'chunk_id' => $id, 'build' => $b,
                'manifest' => ['engine' => 'InnoDB', 'collation' => $c, 'variant' => $v, 'mode' => $m, 'repetition' => (int)$r, 'sample_sha256' => null],
                'bundle' => ['sources' => array_combine(SOURCE_KEYS, array_map($h, SOURCE_KEYS)) + ['bundle_sha256' => $h("bundle-$b")],
                    'runtime' => array_combine(RUNTIME_FILES, array_map(function ($f) use ($h, $b) { return $h("$b:$f"); }, RUNTIME_FILES)),
                    'oc' => array_combine(OC_FILES, array_map($h, OC_FILES))], 'remote_php' => '8.2.0',
                'workload' => ['valid' => true, 'evidence' => ['config_sha256' => $h('cfg'), 'dataset' => ['definition_sha256' => $h('def'), 'schema_sha256' => $h('sch')],
                    'cases' => [['id' => 'timing:three', 'status' => 'pass', 'code' => null, 'gaps' => false, 'data' => ['timed' => $t]]]]]];
            $d['bundle']['sources']['workload_sha256'] = $h("workload-$b");
            $s = ['completed', '0'];
            if ($tweak) $tweak($id, $d, $s);
            @mkdir("$dir/$sub/$id", 0700); writeNew("$dir/$sub/$id/result.json", jenc($d)); $st[$id] = $s;
        }
        return aggregate($rows, $st, "$dir/$sub");
    };
    $bad = [];
    $ok = $mk('ok', null); $g = $ok['groups']['timing natural utf8mb4_bin clean'] ?? null;
    $e = $g['stats']['timing:three']['cold']['series_ns'] ?? null;
    if (($g['completed'] ?? 0) !== 3 || $e === null) $bad[] = 'three-pairs';
    elseif ($e['baseline'] !== ['median' => 200, 'min' => 100, 'max' => 300] || $e['candidate'] !== ['median' => 150, 'min' => 90, 'max' => 400]
        || $e['delta'] !== ['median' => -10, 'min' => -150, 'max' => 200]) $bad[] = 'median-min-max';
    if (($g['stats']['timing:three']['warm']['worker_sql_ns']['delta']['median'] ?? null) !== -5) $bad[] = 'worker-sql-distinct';
    if (($g['basis'] ?? '') !== 'r1 c001(b)->c002(c), r2 c003(c)->c004(b), r3 c005(b)->c006(c)') $bad[] = 'basis-order';
    $fx = $ok['groups']['timing forced-exact utf8mb4_general_ci clean']['stats']['timing:three']['cold']['series_ns'] ?? [];
    if (($fx['candidate'] ?? null) !== ['median' => 502, 'min' => 501, 'max' => 503] || array_key_exists('delta', $fx) || array_key_exists('baseline', $fx)) $bad[] = 'forced-candidate-only';
    if (strpos($ok['md'], 'DIAGNOSTIC: forced-exact candidate-only') === false || $ok['total'] !== 2) $bad[] = 'forced-label';
    $miss = $mk('miss', function ($id, &$d, &$s) { if ($id === 'c004') $s = ['incomplete', '3']; });
    if ($miss['groups']['timing natural utf8mb4_bin clean']['completed'] !== 2 || $miss['groups']['timing natural utf8mb4_bin clean']['stats']
        || strpos($miss['md'], 'r2:c004:label-incomplete') === false) $bad[] = 'missing-pair-rejected';
    $mm = $mk('mism', function ($id, &$d, &$s) use ($h) { if ($id === 'c006') $d['workload']['evidence']['config_sha256'] = $h('other'); });
    if ($mm['groups']['timing natural utf8mb4_bin clean']['completed'] !== 2 || strpos($mm['md'], 'r3:source-mismatch') === false) $bad[] = 'mismatched-pair-rejected';
    $gp = $mk('gapok', function ($id, &$d, &$s) { if ($id === 'c003') { $d['label'] = 'completed-with-measurement-gaps'; $s = [$d['label'], '0']; } });
    if ($gp['groups']['timing natural utf8mb4_bin clean']['completed'] !== 3 || strpos($gp['md'], 'measurement-gaps chunks (optional work gaps; required timings present): r2:c003') === false) $bad[] = 'optional-gap-accepted';
    $dr = $mk('drift', function ($id, &$d, &$s) use ($h) { if ($id === 'c005') $d['bundle']['runtime'][RUNTIME_FILES[0]] = $h('drift'); });
    if ($dr['groups']['timing natural utf8mb4_bin clean']['completed'] !== 2 || strpos($dr['md'], 'r3:baseline-build-source-drift') === false) $bad[] = 'same-build-drift-rejected';
    $xd = $mk('xrep', function ($id, &$d, &$s) use ($h) { if ($id === 'c005' || $id === 'c006') $d['workload']['evidence']['dataset']['definition_sha256'] = $h('def2'); });
    if ($xd['groups']['timing natural utf8mb4_bin clean']['completed'] !== 2 || strpos($xd['md'], 'r3:cross-rep-source-mismatch') === false) $bad[] = 'cross-rep-dataset-rejected';
    $cf = $mk('casefail', function ($id, &$d, &$s) { if ($id === 'c002') $d['workload']['evidence']['cases'][0]['status'] = 'incomplete'; });
    if ($cf['groups']['timing natural utf8mb4_bin clean']['stats']) $bad[] = 'incomplete-case-rejected';
    $none = aggregate($rows, [], "$dir/none");
    if ($none['total'] !== 0 || strpos($none['md'], 'completed matched pairs 0/3; r1:c001:label-unrun') === false) $bad[] = 'zero-completed';
    echo $bad ? 'bad:' . implode(',', $bad) : 'ok', "\n"; break;
default: fail('unknown command');
}
PHPHELPER
}
helper() { "$PHP" -n -d display_errors=stderr -d memory_limit=1024M "$HELPER" "$@"; }
mono_ms() { helper mono; }

# build_case OUT DATASET|- RUNTIME_ROOT|- OC_DIR|- ID STAGE_CODE BUILD COLL VARIANT MODE REP ORDER PREFIX
# Maps the schedule row onto pnms-case/1 nulls: repetition for R/N/F only, pair_position for R/N only.
build_case() {
    _rep=-; _pp=-
    case $6 in R|N|F) _rep=${11} ;; esac
    case $6 in R|N) _pp=${12} ;; esac
    helper case "$1" "$2" "$3" "$4" run_id="$RUN_ID" chunk_id="$5" stage="$(stage_name "$6")" build="$7" run_dir="$REMOTE_RUN_DIR" \
        fixture_prefix="${13}" collation="$8" variant="$9" mode="${10}" repetition="$_rep" pair_position="$_pp" \
        chunk_seconds="$CHUNK_SECONDS" cleanup_seconds="$CLEANUP_SECONDS" max_select="$MAX_SELECT" max_other="$MAX_OTHER"
}

# assess_chunk RC WATCHDOG_FIRED DRAINED RDIR EDIR CASE BUNDLE_MANIFEST BUDGET -> C_STATUS C_GAPS C_FIND
# BUDGET = the SEO_PRO_PNMS_CHUNK_SECONDS value passed by remote_command for this dispatch.
# Pure propagation from transport facts + bounded remote stdout; no clock, no ssh (exercised by --self-test).
assess_chunk() {
    _rc=$1; _rd=$4; _ed=$5
    C_STATUS=$(map_status "$_rc" "$2"); C_GAPS=0; C_FIND="rc=$_rc"
    if [ "$2" = 1 ]; then C_FIND="$C_FIND,watchdog-kill-loss"; fi
    if [ "$_rc" = 255 ]; then C_FIND="$C_FIND,ssh-255-loss"; fi
    if [ "$3" = 0 ]; then C_STATUS=4; C_FIND="$C_FIND,output-stream-held-open-loss"; fi
    osz=$(helper size "$_rd/stdout"); esz=$(helper size "$_rd/stderr")
    if valid '^[0-9]{1,12}$' "$osz"; then :; else osz=0; C_STATUS=$(worse "$C_STATUS" 1); C_FIND="$C_FIND,stdout-unreadable"; fi
    if valid '^[0-9]{1,12}$' "$esz"; then :; else esz=0; C_STATUS=$(worse "$C_STATUS" 1); C_FIND="$C_FIND,stderr-unreadable"; fi
    # Over the local cap: truncated by the reader, never parsed or accepted -> incomplete.
    if [ "$osz" -gt "$STDOUT_MAX" ]; then C_STATUS=$(worse "$C_STATUS" 3); C_FIND="$C_FIND,stdout-cap-exceeded-truncated-not-accepted"; fi
    if [ "$esz" -gt "$STDERR_MAX" ]; then C_STATUS=$(worse "$C_STATUS" 3); C_FIND="$C_FIND,stderr-truncated"; fi
    # Transport stderr: fixed code + byte count + sha256 only. Raw text (which may hold exception
    # messages, paths or credentials) never reaches evidence; no best-effort filtering.
    if [ "$esz" -gt 0 ]; then
        if helper stderr "$_rd/stderr" "$_ed/transport-stderr.json" "$STDERR_MAX" > /dev/null 2>&1; then
            C_FIND="$C_FIND,transport-stderr-present:${esz}b"
        else C_STATUS=$(worse "$C_STATUS" 1); C_FIND="$C_FIND,transport-stderr-digest-failed"; fi
    fi
    rm -f "$_rd/stderr"
    if [ "$osz" -gt 0 ] && [ "$osz" -le "$STDOUT_MAX" ]; then
        helper result "$_rd/stdout" "$_ed" "$6" "$7" "$8" > "$_rd/ev" 2> "$_rd/ev.err"
        everr=$(sed -n 's/^error=//p' "$_rd/ev"); est=$(sed -n 's/^status=//p' "$_rd/ev"); eform=$(sed -n 's/^form=//p' "$_rd/ev")
        ecls=$(sed -n 's/^class=//p' "$_rd/ev")
        if [ -n "$everr" ] && [ "$ecls" = cap ] && [ "$est" = 3 ]; then
            C_STATUS=$(worse "$C_STATUS" 3); C_FIND="$C_FIND,$everr,result-not-accepted"
        elif [ -n "$everr" ] || ! valid '^[0-4]$' "$est"; then
            C_STATUS=$(worse "$C_STATUS" 1); C_FIND="$C_FIND,${everr:-result-helper-failed}"
        else
            C_GAPS=$(sed -n 's/^gaps=//p' "$_rd/ev"); valid '^[01]$' "$C_GAPS" || C_GAPS=0
            if [ "$est" != "$_rc" ]; then C_STATUS=$(worse "$C_STATUS" 1); C_FIND="$C_FIND,result-status-$est-transport-$_rc-mismatch"; fi
            C_STATUS=$(worse "$C_STATUS" "$est")
            if [ "$eform" = early ]; then C_FIND="$C_FIND,result-early-codes-only"; fi
            if [ "$(sed -n 's/^evidence=//p' "$_rd/ev")" = absent ] && [ "$eform" = full ]; then C_FIND="$C_FIND,remote-evidence-absent"; fi
            ers=$(sed -n 's/^reasons=//p' "$_rd/ev"); if [ -n "$ers" ]; then C_FIND="$C_FIND,reasons:$ers"; fi
        fi
    elif [ "$osz" -gt "$STDOUT_MAX" ]; then
        : # cap already recorded as status 3 above; a cap skip is not also a missing result
    else
        C_STATUS=$(worse "$C_STATUS" 1); C_FIND="$C_FIND,no-remote-result"
    fi
    chmod 400 "$_ed"/* 2>/dev/null
    return 0
}

self_test() {
    fails=0
    t() { if [ "$2" = "$3" ]; then :; else fails=$((fails + 1)); printf 'self-test FAIL %s: got [%s] want [%s]\n' "$1" "$2" "$3"; fi; }
    rows=$(schedule_rows)
    t total "$(printf '%s\n' "$rows" | wc -l | tr -d ' ')" 83
    t unique "$(printf '%s\n' "$rows" | cut -f1 | sort -u | wc -l | tr -d ' ')" 83
    for s in P:1 R:6 S:4 N:48 F:12 D:12; do
        t "stage ${s%%:*}" "$(printf '%s\n' "$rows" | awk -F'\t' -v s="${s%%:*}" '$2==s' | wc -l | tr -d ' ')" "${s#*:}"
    done
    for m in --preflight:1 --read-only-stand:7 --matrix:77 --all:83; do
        st=$(stages_for_mode "${m%%:*}"); n=0
        for s in $st; do n=$((n + $(printf '%s\n' "$rows" | awk -F'\t' -v s="$s" '$2==s' | wc -l))); done
        t "mode ${m%%:*}" "$n" "${m#*:}"
    done
    t ro-order "$(printf '%s\n' "$rows" | awk -F'\t' '$2=="R"{printf "%s ", $3}')" 'baseline candidate candidate baseline baseline candidate '
    t diag-last "$(printf '%s\n' "$rows" | tail -12 | cut -f2 | sort -u)" D
    t forced-ci-only "$(printf '%s\n' "$rows" | awk -F'\t' '$6=="forced-exact"{print $4}' | sort -u | tr '\n' ' ')" 'utf8mb4_general_ci utf8mb4_unicode_ci '
    t forced-candidate "$(printf '%s\n' "$rows" | awk -F'\t' '$6=="forced-exact"{print $3}' | sort -u)" candidate
    t stage-names "$(for s in P R S N F D; do printf '%s ' "$(stage_name $s)"; done)" 'preflight read-only-stand semantic timing timing diagnostic '
    t worse-1 "$(worse 3 1)" 1; t worse-2 "$(worse 4 1)" 4; t worse-3 "$(worse 2 3)" 3; t worse-4 "$(worse 0 2)" 2
    t worse-5 "$(worse 1 3)" 1
    t map-255 "$(map_status 255 0)" 4; t map-wd "$(map_status 0 1)" 4; t map-7 "$(map_status 7 0)" 4
    t map-143 "$(map_status 143 0)" 4; t map-3 "$(map_status 3 0)" 3
    t label-gap "$(label_for 0 2)" completed-with-measurement-gaps; t label-4 "$(label_for 4 0)" unresolved
    t sq "$(sq "a'b c")" "'a'\\''b c'"
    if valid "$RE_SSH" '-oProxyCommand=x'; then t ssh-dash bad rejected; fi
    if valid "$RE_SSH" "host${NL}x"; then t ssh-nl bad rejected; fi
    for p in '/a/../b' '/..' '/../a' '/a/..' '/a/.' '/./a' '/a/./b' '/a//b' '//a' '/a/' '/' '.' 'a/b' \
        '' "/a b" "/a${NL}b" "/a${TAB}b" '/a;b' '/a$b' "/a'b" '/-x' '/a/-x'; do
        if abs_ok "$p"; then t "abs-reject[$p]" bad rejected; fi
    done
    for p in /tmp /a/b.c /a/..b /a/b.. /a/.hidden /tmp/seo-pro-pns-x; do
        if abs_ok "$p"; then :; else t "abs-accept[$p]" rejected ok; fi
    done
    for p in php php8.3 php-8.3 /opt/homebrew/bin/php /usr/bin/php8.3; do
        if php_bin_ok "$p"; then :; else t "php-accept[$p]" rejected ok; fi
    done
    for p in '' bin/php ./php ../php /opt/../php /opt//php /opt/php/ '-php' '.php' 'php;x' "php'x" 'p hp' "php${NL}x" '/opt/-x' '$php'; do
        if php_bin_ok "$p"; then t "php-reject[$p]" bad rejected; fi
    done
    if valid "$RE_RUNID" 'ABC'; then t runid-upper bad rejected; fi
    if valid "$RE_RUNID" 'abc-'; then :; else t runid-ok rejected ok; fi
    if valid "$RE_RUNID" "a$(printf '%032d' 0)"; then t runid-33 bad rejected; fi
    if valid "$RE_RUNID" "20261119-123456-0123456789abcdef"; then :; else t runid-default rejected ok; fi
    for v in 0 -1 1801 901 '1 ' 01x ''; do
        if int_in "$v" 1 900; then t "int-reject[$v]" bad rejected; fi
    done
    if int_in 900 1 900 && int_in 1 1 900; then :; else t int-bounds rejected ok; fi
    t budget-full "$(chunk_budget 900000 180)" 180
    t budget-edge "$(chunk_budget $(( (180 + WATCHDOG_EXTRA) * 1000 + SLACK_MS )) 180)" 180
    t budget-floor "$(chunk_budget $(( (180 + WATCHDOG_EXTRA) * 1000 + SLACK_MS - 1 )) 180)" 179
    t budget-min "$(chunk_budget $(( (1 + WATCHDOG_EXTRA) * 1000 + SLACK_MS )) 180)" 1
    t budget-none "$(chunk_budget $(( (1 + WATCHDOG_EXTRA) * 1000 + SLACK_MS - 1 )) 180)" 0
    t budget-neg "$(chunk_budget -5000 180)" 0
    t budget-cap "$(chunk_budget 900000 60)" 60
    STORE_CONFIG=/c REMOTE_PHP=php RUN_ID=selftest-run REMOTE_RUN_DIR=/tmp/seo-pro-pns-selftest-run
    rc_line=$(remote_command c001 10)
    case $rc_line in *" -n "*) t remote-no-n has-n absent ;; esac
    case $rc_line in *"'php' -d display_errors=stderr -d log_errors=0 -d html_errors=0") : ;; *) t remote-flags "$rc_line" exact ;; esac
    case $rc_line in *" SEO_PRO_PNMS_CHUNK_SECONDS='10' "*) : ;; *) t remote-budget "$rc_line" budget-env ;; esac
    case $rc_line in *" -- "*|*--supervised*|*--run-dir*|*--chunk*) t remote-no-argv "$rc_line" no-argv ;; esac
    REMOTE_PHP=/opt/homebrew/bin/php; rc_abs=$(remote_command c001 10); REMOTE_PHP=php
    case $rc_abs in *"'/opt/homebrew/bin/php' -d display_errors=stderr -d log_errors=0 -d html_errors=0") : ;; *) t remote-abs-php "$rc_abs" quoted-abs ;; esac
    RUN_TAG=0123abcd; p=$(fixture_prefix c083)
    t prefix "$p" pnsx_0123abcdc083_
    if valid "$RE_PREFIX" "$p"; then :; else t prefix-contract "$p" match; fi
    if valid "$RE_PREFIX" 'pnsx_0123_abcd_'; then t prefix-inner-underscore bad rejected; fi
    t prefix-unique "$(schedule_rows | cut -f1 | while read -r i; do fixture_prefix "$i"; echo; done | sort -u | wc -l | tr -d ' ')" 83
    t watchdog "$((CHUNK_SECONDS_MAX + WATCHDOG_EXTRA))" 245
    # The window charged to the global deadline is the whole watchdog window: 900 s fits 3 full chunks.
    t window-fits "$(( (GLOBAL_SECONDS_MAX * 1000 - SLACK_MS) / 1000 / (CHUNK_SECONDS_MAX + WATCHDOG_EXTRA) ))" 3

    # ---- offline propagation: synthetic remote outputs through the real parser (no MySQL, no ssh)
    if command -v "$PHP" >/dev/null 2>&1; then :; else
        printf 'self-test FAIL php: local PHP (%s) is required for the propagation checks; nothing reported as passing\n' "$PHP"; return 1
    fi
    ST=$(mktemp -d "${TMPDIR:-/tmp}/pn-selftest.XXXXXXXX") || return 1
    HELPER=$ST/helper.php; write_helper "$HELPER"
    mkdir "$ST/fx"
    t fixture "$(helper selftest-fixture "$ST/fx" 2>&1)" ok
    FX=$ST/fx; pn=0; PB=120   # PB = dispatched SEO_PRO_PNMS_CHUNK_SECONDS (fixtures report env/effective 120)
    _prop() { # NAME RC FIRED DRAINED EXPECT [STDERR_TEXT]
        pn=$((pn + 1)); PR=$ST/r$pn; PE=$ST/e$pn; mkdir "$PR" "$PE"
        cp "$FX/$1.out" "$PR/stdout"; printf '%s' "${6:-}" > "$PR/stderr"
        assess_chunk "$2" "$3" "$4" "$PR" "$PE" "$FX/case.json" "$FX/bman.json" "$PB"
        t "propagate[$1 rc=$2 wd=$3 drained=$4 budget=$PB]" "$C_STATUS" "$5"
    }
    _stored() { [ -f "$PE/result.json" ] || t "accepted-stored[$1]" absent stored; }
    _notstored() { [ -f "$PE/result.json" ] && t "rejected-not-stored[$1]" stored absent; }
    _prop ok0 0 0 1 0
    for f in result.json events.jsonl metrics.json ledger.json evidence-transfer.json; do [ -f "$PE/$f" ] || t "evidence-written[$f]" missing present; done
    t label-ok "$(label_for "$C_STATUS" "$C_GAPS")" completed
    grep -q '"representation": "decoded-reencoded"' "$PE/evidence-transfer.json" && t ok0-raw-identical decoded raw
    _prop rawcompact0 0 0 1 0; _stored rawcompact0
    grep -q '"representation": "decoded-reencoded"' "$PE/evidence-transfer.json" || t rawcompact-noted missing decoded-reencoded
    grep -q '"matches_remote": false' "$PE/evidence-transfer.json" || t rawcompact-mismatch-recorded missing recorded
    _prop gaps0 0 0 1 0; t label-gaps "$(label_for "$C_STATUS" "$C_GAPS")" completed-with-measurement-gaps
    # Budget: env/effective must equal the dispatched value.
    PB=60; _prop ok0 0 0 1 1; _notstored budget-mismatch; PB=120
    # Genuine status 2 (Performance Schema / control denied) stays 2: retained bounded evidence, or explicitly unretained.
    _prop pf2retained 2 0 1 2; _stored pf2retained
    for f in events.jsonl metrics.json ledger.json evidence-transfer.json; do [ -f "$PE/$f" ] || t "pf2-evidence-written[$f]" missing present; done
    [ -s "$PE/events.jsonl" ] && t pf2-events-empty nonempty empty
    grep -q '"entries": {}' "$PE/ledger.json" || t pf2-ledger-object "$(cat "$PE/ledger.json")" '{"entries": {}}'
    _prop pf2unretained 2 0 1 2; _stored pf2unretained
    [ -f "$PE/events.jsonl" ] && t pf2-unretained-no-evidence present absent
    _prop unret3 3 0 1 1; _notstored unret3
    # Identified early results: config missing (2) and mysqli (2) stay 2; bad duration (1) is a refusal, not a protocol failure.
    _prop early2 2 0 1 2
    case $C_FIND in *result-early-codes-only*) : ;; *) t early2-form "$C_FIND" early ;; esac
    [ -f "$PE/events.jsonl" ] && t early-no-evidence present absent
    _prop early2cfg 2 0 1 2
    _prop early1dur 1 0 1 1
    case $C_FIND in *result-early-codes-only*) : ;; *) t early1dur-accepted "$C_FIND" early-accepted ;; esac
    _prop early1bare 1 0 1 1
    case $C_FIND in *result-early-codes-only*) : ;; *) t early1bare-accepted "$C_FIND" early-accepted ;; esac
    for n in early2bare early2badcode earlyidwrong earlypartial; do
        _prop "$n" 2 0 1 1
        case $C_FIND in *result-early-codes-only*) t "early-rejected[$n]" accepted rejected ;; esac
    done
    # Evidence/transfer/limits shape: status 0 never without evidence; nonzero null only with the recorded reason.
    for n in noevidence nullev0 evsha evbytes evline limenv limeff limstmt limman limnull0 nolimits nofile0 nopreflight; do
        _prop "$n" 0 0 1 1; _notstored "$n"
    done
    _prop nullev1noreason 1 0 1 1; _notstored nullev1noreason
    _prop nullev1reason 1 0 1 1; _stored nullev1reason
    [ -f "$PE/events.jsonl" ] && t nullev1-no-evidence present absent
    [ -f "$PE/evidence-transfer.json" ] || t nullev1-transfer-summary missing present
    # Over a local bound: never accepted, never stored, incomplete (3) - not PASS, not protocol failure.
    for n in evcap evbig oversize; do
        _prop "$n" 0 0 1 3; _notstored "$n"
    done
    _prop evcap 1 0 1 1
    # Stdout cap skip: 3 with its finding, never also no-remote-result; independent failures still dominate.
    _prop oversize 0 0 1 3
    case $C_FIND in *stdout-cap-exceeded-truncated-not-accepted*) : ;; *) t oversize-finding "$C_FIND" cap ;; esac
    case $C_FIND in *no-remote-result*) t oversize-no-missing "$C_FIND" no-missing ;; esac
    _prop oversize 3 0 1 3
    _prop oversize 1 0 1 1
    _prop oversize 2 0 1 3
    _prop oversize 0 1 1 4
    _prop oversize 0 0 0 4
    _prop evcap 0 0 1 3
    case $C_FIND in *result-not-accepted*) : ;; *) t evcap-finding "$C_FIND" result-not-accepted ;; esac
    case $C_FIND in *no-remote-result*) t evcap-no-missing "$C_FIND" no-missing ;; esac
    # Stderr over its cap: truncated (3) on an otherwise valid result; a mismatch (1) still dominates.
    big=$(head -c $((STDERR_MAX + 1)) /dev/zero | tr '\0' x)
    _prop ok0 0 0 1 3 "$big"
    case $C_FIND in *stderr-truncated*) : ;; *) t stderr-trunc-finding "$C_FIND" stderr-truncated ;; esac
    _prop ok0 3 0 1 1 "$big"
    _prop truncated 0 0 1 1 "$big"
    _prop inc3 3 0 1 3; t label-inc "$(label_for "$C_STATUS" "$C_GAPS")" incomplete
    _prop inc3 0 0 1 1
    _prop ok0 3 0 1 1
    _prop inc3 4 0 1 4
    _prop ok0 255 0 1 4
    case $C_FIND in *ssh-255-loss*) : ;; *) t ssh255-finding "$C_FIND" ssh-255-loss ;; esac
    _prop empty 255 0 1 4
    _prop empty 0 0 1 1
    _prop ok0 7 0 1 4
    _prop ok0 0 1 1 4
    _prop ok0 0 0 0 4
    _prop truncated 4 0 1 4
    _prop early2 1 0 1 1
    _prop early0 0 0 1 1
    for n in malformed truncated badutf8 identity manifest sources badlabel badstatus secret unknownkey; do
        _prop "$n" 0 0 1 1; _notstored "$n"
    done
    _prop malformed 3 0 1 1
    _prop ok0 0 0 1 0 'PASSWORD=hunter2 /etc/opencart/config.php'
    if grep -q hunter2 "$PE/transport-stderr.json" 2>/dev/null; then t stderr-withheld leaked withheld; fi
    [ -f "$PE/transport-stderr.json" ] || t stderr-digest missing present

    # ---- preflight freeze + case manifest contract
    _ds() { # NAME -> DS_OUT (key=value lines)
        rm -f "$ST/ds.json"; helper dataset "$FX/$1.out" "$ST/ds.json" "$FX/case.json" "$FX/bman.json" 120 > "$ST/ds.out" 2>/dev/null
        DS_OUT=$(cat "$ST/ds.out")
    }
    _kv() { printf '%s\n' "$DS_OUT" | sed -n "s/^$1=//p"; }
    _ds pfnosample; t ds-nosample "$(_kv error)|$(_kv sample)|$(_kv sample_code)" '|0|sample-case-missing'
    _ds pfnoserver; t ds-noserver "$(_kv error)|$(_kv class)" 'preflight-server-missing|missing'
    _ds pfbadsha; t ds-badsha "$(_kv class)" malformed
    _ds early2; t ds-early "$(_kv class)" blocked
    _ds truncated; t ds-truncated "$(_kv class)" malformed
    _ds pf2retained; t ds-pf2-not-promoted "$(_kv error)|$(_kv class)" 'preflight-workload-evidence|missing'
    _ds evcap; t ds-cap "$(_kv class)" cap
    _ds pfengine; t ds-engine-unsupported "$(_kv error)|$(_kv engine)" '|-'
    cp "$ST/ds.json" "$ST/ds-noengine.json"
    _ds ok0
    t ds-ok "$(_kv error)|$(_kv engine)|$(_kv sample)|$(_kv shapes_missing)|$(_kv collations)" \
        '|InnoDB|1|none|utf8mb4_general_ci,utf8mb4_unicode_ci,utf8mb4_0900_as_cs,utf8mb4_bin'
    CHUNK_SECONDS=180
    if csha=$(build_case "$ST/r.json" "$ST/ds.json" - - c002 R baseline - - natural 1 1 pnsx_0123abcdc002_ 2>/dev/null); then
        t case-canonical "$csha" "$(helper shastr "$(cat "$ST/r.json")")"
        grep -q '"stage":"read-only-stand"' "$ST/r.json" || t case-r-stage missing present
        grep -q '"engine":null,"collation":null,"variant":null,"mode":"natural","repetition":1,"pair_position":1,"sample":{"three"' "$ST/r.json" || t case-r-nulls "$(cat "$ST/r.json" | cut -c1-200)" contract
    else t case-r failed built; fi
    if build_case "$ST/n.json" "$ST/ds.json" - - c020 N candidate utf8mb4_bin conflict natural 2 1 pnsx_0123abcdc020_ > /dev/null 2>&1; then
        grep -q '"stage":"timing","build":"candidate"' "$ST/n.json" || t case-n-stage bad timing
        grep -q '"engine":"InnoDB","collation":"utf8mb4_bin","variant":"conflict","mode":"natural","repetition":2,"pair_position":1,"sample":null,"sample_sha256":null' "$ST/n.json" || t case-n-nulls bad contract
    else t case-n failed built; fi
    if build_case "$ST/f.json" "$ST/ds.json" - - c060 F candidate utf8mb4_general_ci clean forced-exact 3 1 pnsx_0123abcdc060_ > /dev/null 2>&1; then
        grep -q '"repetition":3,"pair_position":null' "$ST/f.json" || t case-f-pair bad null
    else t case-f failed built; fi
    if build_case "$ST/d.json" "$ST/ds.json" - - c078 D candidate utf8mb4_unicode_ci clean natural 1 1 pnsx_0123abcdc078_ > /dev/null 2>&1; then
        grep -q '"repetition":null,"pair_position":null' "$ST/d.json" || t case-d-nulls bad null
    else t case-d failed built; fi
    if build_case "$ST/x1.json" "$ST/ds-noengine.json" - - c008 S candidate utf8mb4_bin - natural 1 1 pnsx_0123abcdc008_ > /dev/null 2>&1; then t case-s-no-engine built refused; fi
    _ds pfnosample
    if build_case "$ST/x2.json" "$ST/ds.json" - - c002 R baseline - - natural 1 1 pnsx_0123abcdc002_ > /dev/null 2>&1; then t case-r-no-sample built refused; fi
    if build_case "$ST/x3.json" - - - c080 D candidate utf8mb4_bin clean forced-exact 1 1 pnsx_0123abcdc080_ > /dev/null 2>&1; then t case-forced-bin built refused; fi
    if build_case "$ST/x4.json" - - - c001 P candidate - - natural 1 1 pnsx_0123_abcd_ > /dev/null 2>&1; then t case-prefix-underscore built refused; fi
    if helper bundleman "$FX/bman.json" "$FX/bundle.php" "$FX/case.json" "$ST/art.json" c001 candidate > /dev/null 2>&1; then :; else t bundleman-ok refused accepted; fi
    if helper bundleman "$FX/bman.json" "$FX/bundle-tampered.php" "$FX/case.json" "$ST/art2.json" c001 candidate > /dev/null 2>&1; then t bundleman-tampered accepted refused; fi
    mkdir "$ST/agg"
    t aggregate "$(helper selftest-aggregate "$ST/agg" 2>&1)" ok
    chmod -R u+w "$ST" 2>/dev/null; rm -rf "$ST"

    if [ "$fails" = 0 ]; then echo 'self-test: schedule/severity/quoting/validation/contract/propagation checks consistent (offline only; no MySQL, no ssh)'; return 0; fi
    return 1
}

# ---------------------------------------------------------------- arguments
MODE= PLAN=0 RUN_ID= FAKE= GLOBAL_SECONDS=$GLOBAL_SECONDS_MAX CHUNK_SECONDS=$CHUNK_SECONDS_MAX
for a in "$@"; do
    case $a in
        --preflight|--read-only-stand|--matrix|--all) [ -z "$MODE" ] || die 'exactly one stage mode' 1; MODE=$a ;;
        --plan) PLAN=1 ;;
        --self-test) self_test; exit $? ;;
        --run-id=*) RUN_ID=${a#--run-id=} ;;
        --fake-transport=*) FAKE=${a#--fake-transport=} ;;
        --global-seconds=*) GLOBAL_SECONDS=${a#--global-seconds=} ;;
        --chunk-seconds=*) CHUNK_SECONDS=${a#--chunk-seconds=} ;;
        *) die 'unknown argument' 1 ;;
    esac
done
[ -n "$MODE" ] || die 'one of --preflight --read-only-stand --matrix --all is required' 1
if STAGES=$(stages_for_mode "$MODE"); then :; else die 'internal: unknown mode' 1; fi
# Limits may only be lowered, never widened.
if int_in "$GLOBAL_SECONDS" 1 "$GLOBAL_SECONDS_MAX"; then :; else die 'global seconds must be 1..900' 1; fi
if int_in "$CHUNK_SECONDS" 1 "$CHUNK_SECONDS_MAX"; then :; else die 'chunk seconds must be 1..180' 1; fi

if [ "$(schedule_rows | wc -l | tr -d ' ')" = "$SCHEDULE_TOTAL" ]; then :; else die 'internal: frozen schedule is not 83 chunks' 1; fi

if [ "$PLAN" = 1 ]; then
    # Deterministic offline plan: no environment, no files, no emission, no ssh.
    printf '# frozen schedule (%s chunks); mode %s selects stages: %s\n' "$SCHEDULE_TOTAL" "$MODE" "$STAGES"
    printf '# caps: global %ss/%s chunks from first dispatch; chunk %ss + cleanup %ss; %s SELECT / %s other\n' \
        "$GLOBAL_SECONDS" "$GLOBAL_CHUNKS_MAX" "$CHUNK_SECONDS" "$CLEANUP_SECONDS" "$MAX_SELECT" "$MAX_OTHER"
    printf 'id\tstage\tbuild\tcollation\tvariant\tmode\trep\torder\tselected\n'
    schedule_rows | while IFS=$TAB read -r id st b c v m r o; do
        if selected "$st" "$STAGES"; then s=yes; else s=no; fi
        printf '%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\n' "$id" "$(stage_name "$st")" "$b" "$c" "$v" "$m" "$r" "$o" "$s"
    done
    exit 0
fi

# ---------------------------------------------------------------- environment validation
OC_DIR=${OC_DIR:-}
if [ -n "$OC_DIR" ] && [ -f "$OC_DIR/system/library/url.php" ]; then :; else die 'OC_DIR must be set and contain system/library/url.php' 1; fi
if command -v "$PHP" >/dev/null 2>&1; then :; else die 'PHP not found' 1; fi
if command -v git >/dev/null 2>&1; then :; else die 'git not found' 1; fi
if [ -n "$FAKE" ]; then
    # Offline hook: replaces ONLY the transport. Every check, cap and label path stays identical;
    # the run is marked transport=fake and can never be reported as stand evidence.
    if abs_ok "$FAKE" && [ -f "$FAKE" ] && [ -x "$FAKE" ]; then :; else die 'fake transport must be a plain absolute executable path' 1; fi
    TRANSPORT=fake; SSH_TARGET=fake.invalid
else
    TRANSPORT=ssh; SSH_TARGET=${SEO_PRO_TEST_SSH:-}
    if command -v ssh >/dev/null 2>&1; then :; else die 'ssh not found' 1; fi
fi
if valid "$RE_SSH" "$SSH_TARGET"; then :; else die 'SEO_PRO_TEST_SSH missing or not a plain host/alias' 1; fi
STORE_CONFIG=${SEO_PRO_TEST_STORE_CONFIG:-}
if abs_ok "$STORE_CONFIG"; then :; else die 'SEO_PRO_TEST_STORE_CONFIG must be a plain absolute remote path' 1; fi
REMOTE_BASE=${SEO_PRO_REMOTE_RUN_BASE:-/tmp}
if abs_ok "$REMOTE_BASE"; then :; else die 'SEO_PRO_REMOTE_RUN_BASE must be a plain absolute remote path' 1; fi
REMOTE_PHP=${SEO_PRO_REMOTE_PHP:-php}
if php_bin_ok "$REMOTE_PHP"; then :; else die 'SEO_PRO_REMOTE_PHP is not a plain binary path' 1; fi
EVIDENCE_BASE=${SEO_PRO_EVIDENCE_DIR:-$ROOT/docs/designs/product-navigation-brainstorm/remediation-review-2026-11-17}
if abs_ok "$EVIDENCE_BASE" && [ -d "$EVIDENCE_BASE" ] && [ ! -L "$EVIDENCE_BASE" ]; then :; else
    die 'evidence dir (SEO_PRO_EVIDENCE_DIR or the canonical review dir) must be an existing plain absolute directory' 1
fi
if [ -z "$RUN_ID" ]; then
    rnd=$(od -An -N8 -tx1 /dev/urandom | tr -d ' \n')
    case $rnd in ????????????????) : ;; *) die 'cannot read /dev/urandom for run id' 1 ;; esac
    RUN_ID=$(date -u +%Y%m%d-%H%M%S)-$rnd
fi
if valid "$RE_RUNID" "$RUN_ID"; then :; else die 'run id must match [a-z0-9][a-z0-9-]{3,31} (pnms-case/1 run_id)' 1; fi
# Private, unique-per-run remote directory (pnms-case/1 run_dir, <= 200 chars).
REMOTE_RUN_DIR=$REMOTE_BASE/seo-pro-pns-$RUN_ID
if abs_ok "$REMOTE_RUN_DIR" && [ "${#REMOTE_RUN_DIR}" -le 200 ]; then :; else die 'remote run dir is not a plain absolute path of at most 200 chars' 1; fi

# ---------------------------------------------------------------- private scratch + helper
TMP=$(mktemp -d "${TMPDIR:-/tmp}/pn-orch.XXXXXXXX") || die 'mktemp failed' 1
chmod 700 "$TMP"
TPID= WPID= RPID= INFLIGHT=
on_exit() {
    for p in $WPID $RPID; do kill -TERM "$p" 2>/dev/null; wait "$p" 2>/dev/null; done
    rm -rf "$TMP"
}
trap on_exit EXIT
mkdir "$TMP/lib" "$TMP/baseline" "$TMP/bundles" "$TMP/cases" "$TMP/run" "$TMP/home" "$TMP/emit" || die 'scratch' 1
HELPER=$TMP/lib/orch_helper.php
write_helper "$HELPER" || die 'helper' 1

# ---------------------------------------------------------------- evidence directory (immutable)
[ -d "$EVIDENCE_BASE/execution" ] || mkdir -m 700 "$EVIDENCE_BASE/execution" || die 'cannot create execution/' 1
EVID=$EVIDENCE_BASE/execution/$RUN_ID
mkdir -m 700 "$EVID" 2>/dev/null || die 'evidence run directory already exists or is unwritable; run ids are never reused' 1
mkdir -m 700 "$EVID/local-parts" "$EVID/chunks" "$EVID/emitter-manifests" || die 'evidence layout' 1
EMIT_TSV=$TMP/emitter.tsv; : > "$EMIT_TSV"
COMMANDS=$EVID/commands.md
STATUS_TSV=$TMP/status.tsv; : > "$STATUS_TSV"
OVERALL=0
STOPPED=
PF_NOTES=
part_no=0

# local_part NAME CMD... : runs a local pre-SSH step, keeps its log; any failure stops before ssh.
local_part() {
    part_no=$((part_no + 1)); pn=$(printf '%02d' "$part_no"); pname=$1; shift
    plog="$EVID/local-parts/$pn-$pname.log"
    if "$@" > "$plog" 2>&1; then printf 'local %s %s: ok\n' "$pn" "$pname"; chmod 400 "$plog"; return 0; fi
    chmod 400 "$plog"; printf 'local %s %s: FAILED (see local-parts/%s-%s.log); no ssh started\n' "$pn" "$pname" "$pn" "$pname"
    OVERALL=$(worse "$OVERALL" 1); finalize "stopped-before-ssh:$pname"
}

{
    printf '# Invocation evidence, run %s\n\n' "$RUN_ID"
    printf 'Generated by tests/run_product_navigation_mysql.sh. Commands are recorded as executed or attempted; this file does not claim any result.\n\n'
    printf -- '- mode: `%s`; transport: `%s`\n' "$MODE" "$TRANSPORT"
    printf -- '- orchestrator argv: `sh tests/run_product_navigation_mysql.sh'; for a in "$@"; do printf ' %s' "$(sq "$a")"; done; printf '`\n'
    printf -- '- ssh target: `$SEO_PRO_TEST_SSH` (sha256 %s, value not recorded)\n' "$(helper shastr "$SSH_TARGET")"
    printf -- '- remote config path: %s; remote run dir: %s; remote php: %s\n' "$(sq "$STORE_CONFIG")" "$(sq "$REMOTE_RUN_DIR")" "$(sq "$REMOTE_PHP")"
    printf -- '- local PHP: %s; OC_DIR: %s\n\n' "$(sq "$PHP")" "$(sq "$OC_DIR")"
} > "$COMMANDS"

# ---------------------------------------------------------------- report (observed facts only)
finalize() { # reason
    reason=$1
    {
        printf '# Supervised product-navigation MySQL run %s\n\n' "$RUN_ID"
        if [ "$TRANSPORT" = fake ]; then printf '**OFFLINE FAKE TRANSPORT: this is not stand evidence.**\n\n'; fi
        printf 'Mode `%s`; orchestrator exit status %s; end reason `%s`.\n\n' "$MODE" "$OVERALL" "$reason"
        printf 'Labels are derived from observed exit codes and authenticated pnms-result/1 documents only. No case is labelled PASS; unrun, incomplete and unmeasured items are not verified. Functional assertions, if any, are reported in the chunk evidence separately from performance evidence, which is never claimed complete by this report.\n\n'
        if [ -n "$PF_NOTES" ]; then printf 'Preflight coverage findings: `%s`\n\n' "$PF_NOTES"; fi
        printf '| chunk | stage | build | collation | variant | mode | rep | order | label | status | findings |\n|---|---|---|---|---|---|---|---|---|---|---|\n'
        while IFS=$TAB read -r id st b c v m r o sel pfx; do
            line=$(grep "^$id$TAB" "$STATUS_TSV" | tail -1)
            if [ -n "$line" ]; then lab=$(printf '%s' "$line" | cut -f2); s=$(printf '%s' "$line" | cut -f3); f=$(printf '%s' "$line" | cut -f4)
            elif [ "$sel" = yes ]; then lab=unrun-after-stop; s=-; f=-
            else lab=not-selected; s=-; f=-; fi
            printf '| %s | %s | %s | %s | %s | %s | %s | %s | %s | %s | %s |\n' "$id" "$(stage_name "$st")" "$b" "$c" "$v" "$m" "$r" "$o" "$lab" "$s" "$f"
        done < "$TMP/schedule.tsv"
        if helper aggregate "$TMP/schedule.tsv" "$STATUS_TSV" "$EVID/chunks" 2>/dev/null; then :; else
            printf '\n## Timing aggregation\n\nAggregation helper failed; no timing statistics are reported (0 completed samples claimed).\n'
        fi
        printf '\nKnown limits: KILL QUERY is cooperative; examined rows are unmeasured unless attributable Performance Schema history was available; three samples support no p95/significance claim. Remote recovery ledger (if any) stays in the remote run directory and was not deleted.\n'
    } > "$EVID/REPORT.md" 2>/dev/null
    chmod 400 "$EVID/REPORT.md" "$COMMANDS" 2>/dev/null
    printf 'run %s: exit %s (%s); evidence %s\n' "$RUN_ID" "$OVERALL" "$reason" "$EVID"
    exit "$OVERALL"
}

on_signal() {
    [ -n "$TPID" ] && kill -TERM "$TPID" 2>/dev/null
    if [ -n "$INFLIGHT" ]; then
        printf '%s\tunresolved\t4\tinterrupted-locally-remote-state-unknown\n' "$INFLIGHT" >> "$STATUS_TSV"
        OVERALL=4
    else OVERALL=$(worse "$OVERALL" 3); fi
    finalize interrupted
}
trap on_signal INT TERM HUP

# ---------------------------------------------------------------- schedule (frozen) -> TSV with selection + prefix
RUN_TAG=$(helper shastr "$RUN_ID" | cut -c1-8)
schedule_rows | while IFS=$TAB read -r id st b c v m r o; do
    if selected "$st" "$STAGES"; then s=yes; else s=no; fi
    printf '%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\n' "$id" "$st" "$b" "$c" "$v" "$m" "$r" "$o" "$s" "$(fixture_prefix "$id")"
done > "$TMP/schedule.tsv"
[ "$(wc -l < "$TMP/schedule.tsv" | tr -d ' ')" = "$SCHEDULE_TOTAL" ] || { OVERALL=1; finalize schedule-mismatch; }
[ "$(cut -f10 "$TMP/schedule.tsv" | grep -Ec "$RE_PREFIX")" = "$SCHEDULE_TOTAL" ] || { OVERALL=1; finalize fixture-prefix-contract; }
[ "$(cut -f10 "$TMP/schedule.tsv" | sort -u | wc -l | tr -d ' ')" = "$SCHEDULE_TOTAL" ] || { OVERALL=1; finalize fixture-prefix-not-unique; }

# ---------------------------------------------------------------- pre-SSH: candidate identity, clean worktree, baseline export
# Only this run's own evidence directory is excluded from the clean check, and only when the evidence
# base lies inside the checkout (the documented setup keeps it outside, so nothing is excluded).
case $EVID in "$ROOT"/*) EVID_PATHSPEC=":(exclude)${EVID#"$ROOT"/}" ;; *) EVID_PATHSPEC= ;; esac
# worktree_status OUT: porcelain status of tracked, staged and untracked (incl. ignored-not) changes.
worktree_status() {
    if [ -n "$EVID_PATHSPEC" ]; then
        git -C "$ROOT" status --porcelain=v1 --untracked-files=all -- . "$EVID_PATHSPEC" > "$1"
    else
        git -C "$ROOT" status --porcelain=v1 --untracked-files=all > "$1"
    fi
}
check_candidate() {
    case $CANDIDATE_FLOOR_COMMIT in ????????????????????????????????????????) : ;; *) echo 'internal: floor commit is not a full sha'; return 1 ;; esac
    head=$(git -C "$ROOT" rev-parse --verify 'HEAD^{commit}') || { echo 'cannot resolve HEAD'; return 1; }
    if valid '^[0-9a-f]{40}$' "$head"; then :; else echo "HEAD is not a full sha: $head"; return 1; fi
    echo "candidate HEAD $head"
    git -C "$ROOT" cat-file -e "$CANDIDATE_FLOOR_COMMIT^{commit}" || { echo "floor commit $CANDIDATE_FLOOR_COMMIT missing"; return 1; }
    if git -C "$ROOT" merge-base --is-ancestor "$CANDIDATE_FLOOR_COMMIT" "$head"; then
        echo "floor $CANDIDATE_FLOOR_COMMIT is an ancestor of HEAD"
    else
        echo "HEAD does not descend from floor $CANDIDATE_FLOOR_COMMIT; not auditing this revision"; return 1
    fi
    worktree_status "$TMP/git-status" || { echo 'git status failed'; return 1; }
    if [ -s "$TMP/git-status" ]; then
        echo 'worktree not clean (tracked, staged or untracked changes); commit or remove them first:'
        cat "$TMP/git-status"; return 1
    fi
    echo 'worktree clean'
    printf '%s\n' "$head" > "$TMP/candidate-head"
}
# source_guard: run before every dispatch; HEAD, clean worktree and every hashed source (candidate,
# baseline export, stock OC) must be unchanged since hash_sources recorded them in manifest.json.
source_guard() {
    h=$(git -C "$ROOT" rev-parse --verify 'HEAD^{commit}' 2>/dev/null) || return 1
    [ -n "$CANDIDATE_HEAD" ] && [ "$h" = "$CANDIDATE_HEAD" ] || return 1
    worktree_status "$TMP/guard-status" 2>/dev/null || return 1
    [ ! -s "$TMP/guard-status" ] || return 1
    while IFS=$TAB read -r want tag; do
        f=${tag#*:}
        case $tag in candidate:*) p=$ROOT/$f ;; baseline:*) p=$TMP/baseline/$f ;; stock:*) p=$OC_DIR/$f ;; *) return 1 ;; esac
        got=$(helper sha "$p" 2>/dev/null) || return 1
        [ "$got" = "$want" ] || return 1
    done < "$TMP/hashes.tsv"
    return 0
}
export_baseline() {
    git -C "$ROOT" cat-file -e "$BASELINE_COMMIT^{commit}" || { echo 'baseline commit missing'; return 1; }
    printf '%s\n' "$RUNTIME_FILES" | while IFS= read -r f; do
        mkdir -p "$TMP/baseline/$(dirname "$f")" || exit 1
        git -C "$ROOT" show "$BASELINE_COMMIT:$f" > "$TMP/baseline/$f" || { echo "export failed: $f"; exit 1; }
        echo "exported $BASELINE_COMMIT:$f"
    done
}
lint_sources() {
    for f in $RUNTIME_FILES $TEST_SOURCES; do "$PHP" -l "$ROOT/$f" || return 1; done
    for f in $RUNTIME_FILES; do "$PHP" -l "$TMP/baseline/$f" || return 1; done
    sh -n "$ROOT/tests/run_product_navigation_mysql.sh" && echo 'sh -n orchestrator: ok'
}
hash_sources() {
    : > "$TMP/hashes.tsv"
    for f in $RUNTIME_FILES $TEST_SOURCES tests/run_product_navigation_mysql.sh; do
        h=$(helper sha "$ROOT/$f") || return 1; printf '%s\tcandidate:%s\n' "$h" "$f" >> "$TMP/hashes.tsv"
    done
    for f in $RUNTIME_FILES; do
        h=$(helper sha "$TMP/baseline/$f") || return 1; printf '%s\tbaseline:%s\n' "$h" "$f" >> "$TMP/hashes.tsv"
    done
    for f in system/helper/utf8.php system/library/url.php system/library/request.php; do
        h=$(helper sha "$OC_DIR/$f") || return 1; printf '%s\tstock:%s\n' "$h" "$f" >> "$TMP/hashes.tsv"
    done
    cat "$TMP/hashes.tsv"
}
write_manifest() {
    {
        printf 'design\tapproved-context/design_draft.md (Phase C/E fixed schedule)\n'
        printf 'run_id\t%s\nmode\t%s\nstages\t%s\ntransport\t%s\n' "$RUN_ID" "$MODE" "$STAGES" "$TRANSPORT"
        printf 'candidate_head\t%s\ncandidate_floor_commit\t%s\nbaseline_commit\t%s\n' "$CANDIDATE_HEAD" "$CANDIDATE_FLOOR_COMMIT" "$BASELINE_COMMIT"
        printf 'ssh_target_sha256\t%s\nremote_config_path\t%s\nremote_run_dir\t%s\nremote_php\t%s\n' "$(helper shastr "$SSH_TARGET")" "$STORE_CONFIG" "$REMOTE_RUN_DIR" "$REMOTE_PHP"
        printf 'global_seconds\t%s\nglobal_chunks\t%s\nchunk_seconds\t%s\ncleanup_seconds\t%s\n' "$GLOBAL_SECONDS" "$GLOBAL_CHUNKS_MAX" "$CHUNK_SECONDS" "$CLEANUP_SECONDS"
        printf 'max_select\t%s\nmax_other\t%s\nwatchdog_extra_seconds\t%s\nschedule_total\t%s\n' "$MAX_SELECT" "$MAX_OTHER" "$WATCHDOG_EXTRA" "$SCHEDULE_TOTAL"
        printf 'overhead_seconds\t%s\nslack_ms\t%s\n' "$OVERHEAD_SECONDS" "$SLACK_MS"
        printf 'deadline_model\tlocal monotonic absolute deadline set immediately before c001 launch; each whole chunk window (submission + cleanup + overhead) must end by it; integer ms, floor, 1 s slack; lowered per-chunk budget passed as SEO_PRO_PNMS_CHUNK_SECONDS\n'
        printf 'case_manifest\tpnms-case/1 canonical JSON (PNMS_Lib::canonical byte-identical); sha256 listed in preflight.json\n'
        printf 'emitter_manifests\tper successful emission: emitter-manifests/<id>.json (pnms-bundle/1 validated against case + embedded PNMS_EMBED + bundle sha256)\n'
        printf 'remote_result\tpnms-result/1 bound to the streamed case manifest and bundle sources; early form = codes only; optional bounded evidence\n'
        printf 'transport_stderr\tfixed code + byte count + sha256 only (chunks/<id>/transport-stderr.json); raw content never stored\n'
        printf 'pair_order\tbaseline/candidate, candidate/baseline, baseline/candidate (alternating, not balanced; no significance claim)\n'
        printf 'dataset_source\tfrozen from c001 workload case evidence (server, collations, shop-definition, sample) into preflight.json before any later chunk\n'
        printf 'git_status\t%s\n' "$(tr '\t\n' ' |' < "$TMP/git-status")"
    } > "$TMP/meta.tsv"
    MANIFEST_SHA=$(helper manifest "$EVID/manifest.json" "$TMP/meta.tsv" "$TMP/hashes.tsv" "$TMP/schedule.tsv") || return 1
    echo "manifest.json sha256 $MANIFEST_SHA"
}

local_part candidate-identity check_candidate
# Captured once (local_part runs in this shell, but the value is read from the file it wrote).
CANDIDATE_HEAD=$(cat "$TMP/candidate-head" 2>/dev/null)
if valid '^[0-9a-f]{40}$' "$CANDIDATE_HEAD"; then :; else OVERALL=$(worse "$OVERALL" 1); finalize stopped-before-ssh:candidate-head-unrecorded; fi
local_part baseline-export export_baseline
local_part source-lint lint_sources
local_part source-hashes hash_sources
local_part manifest write_manifest
printf -- '- manifest.json sha256 %s (0400, written before any ssh)\n\n## Chunks\n\n' "$(helper sha "$EVID/manifest.json")" >> "$COMMANDS"

# ---------------------------------------------------------------- INTEGRATION: emitter
runtime_root() { case $1 in baseline) echo "$TMP/baseline" ;; candidate) echo "$ROOT" ;; *) return 1 ;; esac; }
# emit_bundle ID BUILD CASE_MANIFEST OUT_BUNDLE OUT_BUNDLE_MANIFEST LOG
# test_product_navigation_mysql.php --emit-supervised: env OC_DIR, SEO_PRO_RUNTIME_DIR, SEO_PRO_BUILD_LABEL,
# SEO_PRO_CASE_MANIFEST, SEO_PRO_EMIT_MANIFEST_OUT (must not exist; receives pnms-bundle/1). Bundle on stdout.
# Clean environment: no SSH/config variables reach the emitter.
emit_bundle() {
    rt=$(runtime_root "$2") || return 1
    [ ! -e "$5" ] || { echo 'bundle manifest target already exists' >> "$6"; return 1; }
    env -i PATH="${PATH}" HOME="$TMP/home" TMPDIR="$TMP/emit" OC_DIR="$OC_DIR" \
        SEO_PRO_RUNTIME_DIR="$rt" SEO_PRO_BUILD_LABEL="$2" SEO_PRO_CASE_MANIFEST="$3" \
        SEO_PRO_EMIT_MANIFEST_OUT="$5" \
        "$PHP" "$ROOT/tests/test_product_navigation_mysql.php" --emit-supervised > "$4" 2> "$6" || return 1
    sz=$(helper size "$4"); [ "$sz" -gt 0 ] && [ "$sz" -le "$BUNDLE_MAX" ] || { echo "bundle size $sz out of bounds" >> "$6"; return 1; }
    [ -s "$5" ] || { echo 'emitter wrote no bundle manifest' >> "$6"; return 1; }
    if "$PHP" -l "$4" >> "$6" 2>&1; then :; else return 1; fi
    art=emitter-manifests/$1.json
    if hsha=$(helper bundleman "$5" "$4" "$3" "$EVID/$art" "$1" "$2" 2>> "$6"); then :; else echo 'bundle manifest failed validation' >> "$6"; return 1; fi
    if valid '^[0-9a-f]{64}$' "$hsha"; then :; else echo 'bundle manifest artifact digest missing' >> "$6"; return 1; fi
    printf '%s\t%s\t%s\n' "$1" "$art" "$hsha" >> "$EMIT_TSV"
    echo "bundle manifest $art sha256 $hsha" >> "$6"
}

# prepare_chunk ID ST B C V M R O PFX DATASET -> canonical case manifest + bundle; appends cases.tsv/bundles.tsv
prepare_chunk() {
    cm=$TMP/cases/$1.json
    rt=$(runtime_root "$3") || return 1
    csha=$(build_case "$cm" "${10}" "$rt" "$OC_DIR" "$1" "$2" "$3" "$4" "$5" "$6" "$7" "$8" "$9") || return 1
    printf '%s\t%s\n' "$1" "$csha" >> "$TMP/cases.tsv"
    elog=$EVID/local-parts/emit-$1.log
    emit_bundle "$1" "$3" "$cm" "$TMP/bundles/$1.php" "$TMP/bundles/$1.manifest.json" "$elog"; erc=$?
    chmod 400 "$elog" 2>/dev/null
    [ "$erc" = 0 ] || return 1
    chmod 400 "$TMP/bundles/$1.php"
    printf '%s\t%s\n' "$1" "$(helper sha "$TMP/bundles/$1.php")" >> "$TMP/bundles.tsv"
}
bundle_sha() { grep "^$1$TAB" "$TMP/bundles.tsv" | cut -f2; }

# exec_transport ID REMOTE_CMD : replaces the background subshell so TPID is the transport itself.
exec_transport() {
    if [ "$TRANSPORT" = fake ]; then exec "$FAKE" "$1" "$2"; fi
    exec ssh -T -e none -o BatchMode=yes -o ConnectTimeout=10 -o ServerAliveInterval=10 -o ServerAliveCountMax=3 \
        -o StrictHostKeyChecking=yes -o ForwardAgent=no -o ForwardX11=no -o ClearAllForwardings=yes \
        -o PermitLocalCommand=no -o RequestTTY=no -- "$SSH_TARGET" "$2"
}

# ---------------------------------------------------------------- watchdog (no leaked sleepers)
# watchdog FLAG DELAY1 SIG1 DELAY2 SIG2 PID... -- always started as
#   watchdog ... </dev/null >/dev/null 2>&1 &
# so neither it nor its sleep child holds the orchestrator's stdout/stderr or any fifo (no inherited
# FD deadlock for a caller reading our output). On TERM it kills its own sleep child and exits, so
# cancelling it never leaves a sleeping process behind. DELAY2=0 means a single stage.
watchdog() {
    _flag=$1; _d1=$2; _s1=$3; _d2=$4; _s2=$5; shift 5
    _sp=
    trap 'if [ -n "$_sp" ]; then kill -KILL "$_sp" 2>/dev/null; fi; exit 0' TERM INT HUP
    sleep "$_d1" & _sp=$!
    wait "$_sp"; _sp=
    : > "$_flag"
    kill "-$_s1" "$@" 2>/dev/null
    if [ "$_d2" -gt 0 ]; then
        sleep "$_d2" & _sp=$!
        wait "$_sp"; _sp=
        kill "-$_s2" "$@" 2>/dev/null
    fi
    exit 0
}
stop_bg() { # PID: cancel a watchdog and reap it
    if [ -n "$1" ]; then kill -TERM "$1" 2>/dev/null; wait "$1" 2>/dev/null; fi
    return 0
}

# ---------------------------------------------------------------- dispatch one chunk (never retried)
DISPATCHED=0
DEADLINE_MS=
T0=
# dispatch ID BUILD -> sets C_STATUS C_GAPS C_FIND
dispatch() {
    cid=$1; cbuild=$2
    # Everything not depending on the budget is done BEFORE the clock is read, so the only work
    # between the reading and the transport launch is shell-local (covered by SLACK_MS).
    if [ -z "$DEADLINE_MS" ] && [ "$cid" != c001 ]; then C_STATUS=1; C_GAPS=0; C_FIND=internal-dispatch-before-preflight; return 1; fi
    if [ "$DISPATCHED" -ge "$GLOBAL_CHUNKS_MAX" ]; then C_STATUS=3; C_GAPS=0; C_FIND=global-chunk-cap-not-dispatched; return 1; fi
    rdir=$TMP/run/$cid; edir=$EVID/chunks/$cid
    if mkdir "$rdir" && mkdir -m 700 "$edir" && mkfifo "$rdir/out.fifo" "$rdir/err.fifo"; then :; else
        C_STATUS=1; C_GAPS=0; C_FIND=local-run-dir; return 1
    fi
    bsha=$(bundle_sha "$cid")
    # Sources/HEAD/worktree must be unchanged since hashing + bundling; checked before the clock read.
    if source_guard && [ -n "$bsha" ] && [ "$(helper sha "$TMP/bundles/$cid.php" 2>/dev/null)" = "$bsha" ]; then :; else C_STATUS=1; C_GAPS=0; C_FIND=source-changed-before-launch; return 1; fi

    now=$(mono_ms)
    if valid '^[0-9]{1,19}$' "$now"; then :; else C_STATUS=1; C_GAPS=0; C_FIND=local-monotonic-clock; return 1; fi
    if [ -z "$DEADLINE_MS" ]; then T0=$now; DEADLINE_MS=$((now + GLOBAL_SECONDS * 1000)); fi
    rem_ms=$((DEADLINE_MS - now))
    csec=$(chunk_budget "$rem_ms" "$CHUNK_SECONDS")
    if [ "$csec" -lt 1 ]; then C_STATUS=3; C_GAPS=0; C_FIND="global-deadline-not-dispatched:remaining-ms=$rem_ms"; return 1; fi
    window=$((csec + WATCHDOG_EXTRA))
    term_at=$((window - KILL_GRACE_SECONDS - DRAIN_SECONDS))
    start=$now
    win_end=$((start + window * 1000))
    rcmd=$(remote_command "$cid" "$csec")
    DISPATCHED=$((DISPATCHED + 1))

    head -c $((STDOUT_MAX + 1)) < "$rdir/out.fifo" > "$rdir/stdout" 2>/dev/null & HOUT=$!
    head -c $((STDERR_MAX + 1)) < "$rdir/err.fifo" > "$rdir/stderr" 2>/dev/null & HERR=$!
    INFLIGHT=$cid
    exec_transport "$cid" "$rcmd" < "$TMP/bundles/$cid.php" > "$rdir/out.fifo" 2> "$rdir/err.fifo" & TPID=$!
    watchdog "$rdir/watchdog" "$term_at" TERM "$KILL_GRACE_SECONDS" KILL "$TPID" </dev/null >/dev/null 2>&1 & WPID=$!
    {
        printf '### %s (%s, dispatch %s/%s)\n\n' "$cid" "$cbuild" "$DISPATCHED" "$GLOBAL_CHUNKS_MAX"
        printf -- '- stdin bundle sha256 %s; case manifest sha256 %s; global remaining %sms at launch; chunk submission %ss; cleanup %ss; whole window %ss (TERM at %ss, KILL at +%ss, drain %ss); window ends %sms before the global deadline\n' \
            "$bsha" "$(grep "^$cid$TAB" "$TMP/cases.tsv" | cut -f2)" "$rem_ms" "$csec" "$CLEANUP_SECONDS" "$window" "$term_at" "$KILL_GRACE_SECONDS" "$DRAIN_SECONDS" "$((DEADLINE_MS - win_end))"
        if [ "$TRANSPORT" = fake ]; then printf -- '- `%s %s %s < bundle`\n' "$(sq "$FAKE")" "$(sq "$cid")" "$(sq "$rcmd")"
        else printf -- '- `ssh -T -e none -o BatchMode=yes -o ConnectTimeout=10 -o ServerAliveInterval=10 -o ServerAliveCountMax=3 -o StrictHostKeyChecking=yes -o ForwardAgent=no -o ForwardX11=no -o ClearAllForwardings=yes -o PermitLocalCommand=no -o RequestTTY=no -- "$SEO_PRO_TEST_SSH" %s < bundle`\n' "$(sq "$rcmd")"; fi
    } >> "$COMMANDS"
    wait "$TPID"; rc=$?
    stop_bg "$WPID"; WPID=
    fired=0; if [ -f "$rdir/watchdog" ]; then fired=1; fi
    # Bounded drain: a grandchild still holding a fifo cannot block us past the window.
    watchdog "$rdir/drain-killed" "$DRAIN_SECONDS" KILL 0 KILL "$HOUT" "$HERR" </dev/null >/dev/null 2>&1 & RPID=$!
    wait "$HOUT" 2>/dev/null; wait "$HERR" 2>/dev/null
    stop_bg "$RPID"; RPID=
    drained=1; if [ -f "$rdir/drain-killed" ]; then drained=0; fi
    TPID=; INFLIGHT=
    end=$(mono_ms)

    assess_chunk "$rc" "$fired" "$drained" "$rdir" "$edir" "$TMP/cases/$cid.json" "$TMP/bundles/$cid.manifest.json" "$csec"
    # Acceptance is capped by the chunk window AND the global deadline; a late finish is never accepted.
    if valid '^[0-9]{1,19}$' "$end"; then
        if [ "$end" -gt "$win_end" ]; then C_STATUS=$(worse "$C_STATUS" 3); C_FIND="$C_FIND,chunk-window-overrun"; fi
        if [ "$end" -gt "$DEADLINE_MS" ]; then C_STATUS=$(worse "$C_STATUS" 3); C_FIND="$C_FIND,global-deadline-overrun"; fi
    else C_STATUS=$(worse "$C_STATUS" 1); C_FIND="$C_FIND,local-monotonic-clock"; end=$start; fi
    printf -- '- monotonic start %sms end %sms (%sms after first dispatch); exit %s -> status %s\n\n' "$start" "$end" "$((end - T0))" "$rc" "$C_STATUS" >> "$COMMANDS"
    return 0
}

record() { # id label status findings
    printf '%s\t%s\t%s\t%s\n' "$1" "$2" "$3" "$4" >> "$STATUS_TSV"
    # '-' = not dispatched after an earlier stop; the stop itself already carries the severity.
    [ "$3" = - ] || OVERALL=$(worse "$OVERALL" "$3")
    printf '%s %s: %s (status %s) %s\n' "$1" "$5" "$2" "$3" "$4"
}

# ---------------------------------------------------------------- preflight chunk (always first)
PF_ROW=$(head -1 "$TMP/schedule.tsv")
IFS=$TAB read -r id st b c v m r o sel pfx <<EOF
$PF_ROW
EOF
: > "$TMP/cases.tsv"; : > "$TMP/bundles.tsv"
local_part preflight-bundle prepare_chunk "$id" "$st" "$b" "$c" "$v" "$m" "$r" "$o" "$pfx" -

dispatch "$id" "$b"
PF_STATUS=$C_STATUS
DS_OK=0 DS_CLASS= PF_ENGINE= PF_COLL= PF_SAMPLE=0 PF_MISSING=
if [ -s "$TMP/run/$id/stdout" ] && [ "$(helper size "$TMP/run/$id/stdout")" -le "$STDOUT_MAX" ]; then
    helper dataset "$TMP/run/$id/stdout" "$TMP/dataset.json" "$TMP/cases/$id.json" "$TMP/bundles/$id.manifest.json" "$csec" > "$TMP/pf" 2>/dev/null
    PF_ERR=$(sed -n 's/^error=//p' "$TMP/pf"); DS_CLASS=$(sed -n 's/^class=//p' "$TMP/pf")
    if [ -z "$PF_ERR" ] && [ -s "$TMP/dataset.json" ]; then
        DS_OK=1
        PF_ENGINE=$(sed -n 's/^engine=//p' "$TMP/pf"); [ "$PF_ENGINE" = - ] && PF_ENGINE=
        PF_COLL=$(sed -n 's/^collations=//p' "$TMP/pf" | tr ',' ' ')
        PF_SAMPLE=$(sed -n 's/^sample=//p' "$TMP/pf")
        PF_MISSING=$(sed -n 's/^shapes_missing=//p' "$TMP/pf")
        [ "$PF_SAMPLE" = 1 ] || PF_NOTES="${PF_NOTES}preflight-sample-missing:$(sed -n 's/^sample_code=//p' "$TMP/pf") "
        [ -n "$PF_MISSING" ] && PF_NOTES="${PF_NOTES}read-only-shapes-missing:$PF_MISSING "
        [ -n "$PF_ENGINE" ] || PF_NOTES="${PF_NOTES}shop-engine-unsupported "
    else C_FIND="$C_FIND,dataset:${PF_ERR:-unparsed}"; fi
fi
if [ "$PF_STATUS" = 0 ] && [ "$DS_OK" != 1 ]; then
    case $DS_CLASS in missing|cap) PF_STATUS=3 ;; *) PF_STATUS=1 ;; esac
fi
record "$id" "$(label_for "$PF_STATUS" "$C_GAPS")" "$PF_STATUS" "$C_FIND" preflight
# Missing sample or shape is never substituted or mocked: the run cannot exit better than 3.
if [ "$PF_STATUS" = 0 ] && { [ "$PF_SAMPLE" != 1 ] || [ -n "$PF_MISSING" ]; }; then
    OVERALL=$(worse "$OVERALL" 3); printf 'preflight coverage (unrun, not substituted): %s\n' "$PF_NOTES"
fi

if [ "$PF_STATUS" != 0 ] || [ "$MODE" = --preflight ]; then
    # Status 2/other blocks: store whatever was authenticated, without requiring sample/metrics.
    if [ "$DS_OK" = 1 ]; then pfds=$TMP/dataset.json; else pfds=-; fi
    if helper freeze "$EVID/preflight.json" "$pfds" "$PF_STATUS" "$TMP/cases.tsv" "$TMP/bundles.tsv" "$EMIT_TSV" > /dev/null; then :; else
        OVERALL=$(worse "$OVERALL" 1); finalize preflight-freeze-failed
    fi
    if [ "$PF_STATUS" != 0 ]; then finalize "preflight-blocked-all-costly-stages"; fi
    finalize preflight-only
fi

# skip_reason STAGE_CODE COLLATION -> prints a coverage-gap code, or nothing when the chunk can run.
skip_reason() {
    if [ "$2" != - ] && ! selected "$2" "$PF_COLL"; then echo "collation-unavailable:$2"; return 0; fi
    case $1 in
        S|N|F|D) if [ -z "$PF_ENGINE" ]; then echo shop-engine-unsupported; fi ;;
        R) if [ "$PF_SAMPLE" != 1 ]; then echo preflight-sample-missing; fi ;;
    esac
}

# ---------------------------------------------------------------- freeze dataset; prepare every later chunk before the next ssh
prep_all() {
    while IFS=$TAB read -r id st b c v m r o sel pfx; do
        [ "$id" = c001 ] && continue; [ "$sel" = yes ] || continue
        [ -z "$(skip_reason "$st" "$c")" ] || continue
        prepare_chunk "$id" "$st" "$b" "$c" "$v" "$m" "$r" "$o" "$pfx" "$TMP/dataset.json" || { echo "prepare failed: $id"; return 1; }
        echo "prepared $id $b $(bundle_sha "$id")"
    done < "$TMP/schedule.tsv"
}
local_part prepare-chunks prep_all
if helper freeze "$EVID/preflight.json" "$TMP/dataset.json" "$PF_STATUS" "$TMP/cases.tsv" "$TMP/bundles.tsv" "$EMIT_TSV" > /dev/null; then :; else
    OVERALL=$(worse "$OVERALL" 1); finalize preflight-freeze-failed
fi
printf -- '- emitter bundle manifests (pnms-bundle/1 validated, 0400): %s, listed with sha256 in preflight.json\n' "$(wc -l < "$EMIT_TSV" | tr -d ' ')" >> "$COMMANDS"
printf -- '- preflight.json sha256 %s (dataset frozen from c001; written before chunk c002)\n\n' "$(helper sha "$EVID/preflight.json")" >> "$COMMANDS"

# ---------------------------------------------------------------- main dispatch loop (rows c002..c083, fixed order)
while IFS=$TAB read -r id st b c v m r o sel pfx; do
    [ "$id" = c001 ] && continue
    [ "$sel" = yes ] || continue
    sname=$(stage_name "$st")
    if [ -n "$STOPPED" ]; then record "$id" unrun-after-stop - "$STOPPED" "$sname"; continue; fi
    skip=$(skip_reason "$st" "$c")
    if [ -n "$skip" ]; then record "$id" unrun-coverage-gap 3 "$skip" "$sname"; continue; fi
    if dispatch "$id" "$b"; then :; else
        record "$id" unrun-global-cap "$C_STATUS" "$C_FIND" "$sname"; STOPPED="global-cap-after-$id"; continue
    fi
    record "$id" "$(label_for "$C_STATUS" "$C_GAPS")" "$C_STATUS" "$C_FIND" "$sname"
    # Any non-zero chunk is a required stop: no retry, no continuation, no widened limits.
    [ "$C_STATUS" = 0 ] || STOPPED="stopped-after-$id-status-$C_STATUS"
done < "$TMP/schedule.tsv"

if [ -n "$STOPPED" ]; then finalize "$STOPPED"; fi
finalize schedule-exhausted

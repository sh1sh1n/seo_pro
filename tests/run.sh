#!/bin/sh
# Runs every test. usage: OC_DIR=/path/to/opencart-3.0.5.1/upload [PHP=php] [PORT=8765] sh tests/run.sh
# Exit code 0 only when every part passed.
set -u
: "${OC_DIR:?set OC_DIR to opencart-3.0.5.1/upload}"
PHP=${PHP:-php}
PORT=${PORT:-8765}
ROOT=$(cd "$(dirname "$0")/.." && pwd)
TMP=$(mktemp -d)
SERVER=
status=0
cleanup() { [ -n "$SERVER" ] && kill "$SERVER" 2>/dev/null; rm -rf "$TMP"; }
trap cleanup EXIT INT TERM

part() { name=$1; shift; if "$@" > "$TMP/log" 2>&1; then echo "PASS $name"; else echo "FAIL $name"; cat "$TMP/log"; status=1; fi; }

part 'php -l sources' sh -c "for f in \$(find '$ROOT/seo_pro_oc3.ocmod/upload' '$ROOT/tests' -name '*.php'); do '$PHP' -l \"\$f\" > /dev/null || exit 1; done"

# The core with upload/ over it, then install.xml applied the way Refresh does
mkdir "$TMP/core" && cp -R "$OC_DIR/." "$TMP/core/" && cp -R "$ROOT/seo_pro_oc3.ocmod/upload/." "$TMP/core/"
part 'ocmod' "$PHP" "$ROOT/tests/ocmod_sim.php" --check=install.xml "$TMP/core" "$TMP/out" "$OC_DIR/system/modification.xml" "$ROOT/seo_pro_oc3.ocmod/install.xml"
part 'php -l modified core files' sh -c "for f in \$(find '$TMP/out' -name '*.php'); do '$PHP' -l \"\$f\" > /dev/null || exit 1; done"

# The controller cases pass only when the server started here is alive, answers with the id of this run
# and the run reports that it has finished. Another server on the port, even one answering HTTP 200, is a failure.
RUN_ID="$$x$(date +%s)"
OC_DIR=$OC_DIR SEO_PRO_RUN_ID=$RUN_ID "$PHP" -S "127.0.0.1:$PORT" -t "$ROOT/tests" > "$TMP/server.log" 2>&1 &
SERVER=$!
controller() {
	i=0
	while :; do
		kill -0 "$SERVER" 2>/dev/null || { echo "the PHP server did not start on port $PORT (is the port in use?)"; cat "$TMP/server.log"; return 1; }
		[ "$(curl -s --max-time 2 "http://127.0.0.1:$PORT/cases.php?ping=1")" = "seo_pro cases ready $RUN_ID" ] && break
		i=$((i + 1)); [ $i -ge 50 ] && { echo "port $PORT does not answer as the server of this run"; return 1; }
		sleep 0.1
	done
	code=$(curl -sS --max-time 300 -o "$TMP/cases.txt" -w '%{http_code}' "http://127.0.0.1:$PORT/cases.php") || return 1
	[ "$code" = 200 ] || { echo "HTTP $code"; grep -v -E '^(ok |--|$)' "$TMP/cases.txt"; return 1; }
	kill -0 "$SERVER" 2>/dev/null || { echo 'the PHP server died during the run'; return 1; }
	grep -q "^seo_pro cases finished $RUN_ID\$" "$TMP/cases.txt" || { echo 'the answer is not a finished run of cases.php'; return 1; }
	grep -E '^notices: 0, failed: 0$' "$TMP/cases.txt"
}
part 'controller (cases.php)' controller
kill "$SERVER" 2>/dev/null; SERVER=

part 'schema' "$PHP" "$ROOT/tests/test_schema.php"
part 'cache resets' "$PHP" "$ROOT/tests/test_cache.php" "$TMP/out"
part 'product categories' "$PHP" "$ROOT/tests/test_product_categories.php" "$TMP/out"
part 'seo_url audit' "$PHP" "$ROOT/tests/test_audit.php"
part 'store settings' env OC_DIR="$OC_DIR" "$PHP" "$ROOT/tests/test_store_settings.php" "$TMP/out"

exit $status

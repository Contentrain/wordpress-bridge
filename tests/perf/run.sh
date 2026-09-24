#!/usr/bin/env bash
# BR-23: time a Migrate-style export of a synthetic large site on a throwaway WordPress.
#   MEMORY=64M LIMIT=1800 tests/perf/run.sh [posts] [attachments] [comments]
# MEMORY is PHP's memory_limit for the web requests (shared hosting often gives 64M);
# LIMIT is the most seconds the export may take (Migrate waits 30 minutes);
# MAX_EXECUTION, when set, is PHP's max_execution_time for them (shared hosts: often 30).
set -euo pipefail
here="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
compose=(docker compose -f "$here/compose.yml")
cli=("${compose[@]}" run --rm -T cli)
php=("${compose[@]}" exec -T wordpress php)
posts="${1:-5000}" attachments="${2:-20000}" comments="${3:-20000}"
memory="${MEMORY:-128M}" limit="${LIMIT:-1800}" max_execution="${MAX_EXECUTION:-}"
finish() {
  status=$?
  if [ "$status" != 0 ]; then
    echo "--- PHP errors (last 40 lines of the web container) ---"
    "${compose[@]}" logs --no-color --tail=400 wordpress 2>/dev/null | grep -iE 'fatal|error|memory|exceeded' | tail -40 || true
    echo "--- export state files ---"
    "${compose[@]}" exec -T wordpress sh -c 'ls -la /tmp/contentrain-bridge-*/*/state.json 2>/dev/null; php -r "echo \"memory_limit=\", ini_get(\"memory_limit\"), \" max_execution_time=\", ini_get(\"max_execution_time\"), PHP_EOL;"' || true
  fi
  "${compose[@]}" down -v >/dev/null 2>&1 || true
  exit "$status"
}
trap finish EXIT

"${compose[@]}" up -d db wordpress
for _ in $(seq 1 60); do "${cli[@]}" core version >/dev/null 2>&1 && break; sleep 2; done
ini="memory_limit=$memory"
[ -n "$max_execution" ] && ini="$ini\nmax_execution_time=$max_execution"
"${compose[@]}" exec -T wordpress sh -c "printf '$ini\n' > /usr/local/etc/php/conf.d/zz-bridge-perf.ini && apache2ctl -k graceful" >/dev/null 2>&1
"${compose[@]}" exec -T wordpress php -r 'echo "memory_limit=", ini_get("memory_limit"), PHP_EOL;'
"${cli[@]}" core install --url=http://localhost:8094 --title='Bridge Acceptance' --admin_user=bridge-admin --admin_password=bridge-local-only --admin_email=bridge-admin@example.test --skip-email >/dev/null
"${cli[@]}" plugin activate contentrain-bridge >/dev/null
"${cli[@]}" rewrite structure '/%postname%/' >/dev/null
# The generator is setup, not the thing measured: it gets its own memory.
"${compose[@]}" exec -T wordpress php -d memory_limit=1G /var/www/html/wp-content/plugins/contentrain-bridge/tests/perf/large-site.php "$posts" "$attachments" "$comments"
password="$("${cli[@]}" user application-password create bridge-admin perf --porcelain | tr -d '[:space:]')"
mkdir -p "$here/.out"
LIMIT="$limit" MEMORY="$memory" MAX_EXECUTION="$max_execution" node "$here/perf/export-timing.mjs" http://localhost:8094 bridge-admin "$password" "$here/.out/perf-report.json"

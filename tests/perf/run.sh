#!/usr/bin/env bash
# BR-23: time a Migrate-style export of a synthetic large site on a throwaway WordPress.
#   tests/perf/run.sh [posts] [attachments] [comments]
set -euo pipefail
here="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
compose=(docker compose -f "$here/compose.yml")
cli=("${compose[@]}" run --rm -T cli)
php=("${compose[@]}" exec -T wordpress php)
posts="${1:-5000}" attachments="${2:-20000}" comments="${3:-20000}"
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
"${cli[@]}" core install --url=http://localhost:8094 --title='Bridge Acceptance' --admin_user=bridge-admin --admin_password=bridge-local-only --admin_email=bridge-admin@example.test --skip-email >/dev/null
"${cli[@]}" plugin activate contentrain-bridge >/dev/null
"${cli[@]}" rewrite structure '/%postname%/' >/dev/null
"${php[@]}" /var/www/html/wp-content/plugins/contentrain-bridge/tests/perf/large-site.php "$posts" "$attachments" "$comments"
password="$("${cli[@]}" user application-password create bridge-admin perf --porcelain | tr -d '[:space:]')"
mkdir -p "$here/.out"
node "$here/perf/export-timing.mjs" http://localhost:8094 bridge-admin "$password" "$here/.out/perf-report.json"

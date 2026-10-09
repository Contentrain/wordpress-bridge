#!/usr/bin/env bash
# The robots.txt switch against a throwaway WordPress (tests/robots.php), then Plugin Check over the same plugin.
set -euo pipefail
here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
compose=(docker compose ${BRIDGE_TEST_PROJECT:+-p "$BRIDGE_TEST_PROJECT"} -f "$here/compose.yml")
cli=("${compose[@]}" run --rm -T cli)
cleanup() { if [ "${KEEP:-0}" != "1" ]; then "${compose[@]}" down -v >/dev/null 2>&1 || true; fi; }
trap cleanup EXIT
"${compose[@]}" up -d db wordpress
for _ in $(seq 1 60); do
  if "${cli[@]}" core version >/dev/null 2>&1; then break; fi
  sleep 2
done
"${cli[@]}" core install --url=http://localhost:8094 --title='Bridge Acceptance' \
  --admin_user=bridge-admin --admin_password=bridge-local-only \
  --admin_email=bridge-admin@example.test --skip-email >/dev/null
"${cli[@]}" plugin activate contentrain-bridge >/dev/null
"${compose[@]}" exec -T wordpress php /var/www/html/wp-content/plugins/contentrain-bridge/tests/robots.php
# The served pairs, for tests/robots-parity.mjs (Contentrain Migrate's own parser).
rm -rf "$here/.out/robots" && mkdir -p "$here/.out"
docker cp "$("${compose[@]}" ps -q wordpress):/tmp/bridge-robots" "$here/.out/robots"

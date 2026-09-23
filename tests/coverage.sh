#!/usr/bin/env bash
# B-02 against the acceptance WordPress that tests/run.sh left up (KEEP=1):
# add the sources the fixture lacked, export in both scopes, check every source
# adds up, then compare Bridge's RawIR with a WXR export read by A-03's parser.
set -euo pipefail
here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
compose=(docker compose -f "$here/compose.yml")
cli=("${compose[@]}" run --rm -T cli)
wp=("${compose[@]}" exec -T wordpress)
content=/var/www/html/wp-content

"${wp[@]}" sh -c "mkdir -p $content/mu-plugins && cp /var/www/html/wp-content/plugins/contentrain-bridge/tests/fixtures/bridge-coverage-cpt.php $content/mu-plugins/ && chown -R www-data:www-data $content/mu-plugins"
cleanup() { "${wp[@]}" rm -f "$content/mu-plugins/bridge-coverage-cpt.php" >/dev/null 2>&1 || true; }
trap cleanup EXIT

log="$(mktemp)"
"${wp[@]}" php /var/www/html/wp-content/plugins/contentrain-bridge/tests/coverage.php | tee "$log"
grep -q 'Coverage output: ' "$log"
# BR-19: the same site started, advanced and read over REST.
"${wp[@]}" php /var/www/html/wp-content/plugins/contentrain-bridge/tests/remote.php
# The same site as WXR, the A-03 input.
"${wp[@]}" sh -c "rm -rf $content/uploads/bridge-wxr && mkdir -p $content/uploads/bridge-wxr && chown www-data:www-data $content/uploads/bridge-wxr"
"${cli[@]}" export --dir="$content/uploads/bridge-wxr" --filename_format=site.xml >/dev/null
rm -rf "$here/.out/coverage"
mkdir -p "$here/.out"
id="$("${compose[@]}" ps -q wordpress)"
docker cp "$id:/tmp/bridge-coverage" "$here/.out/coverage"
docker cp "$id:$content/uploads/bridge-wxr/site.xml" "$here/.out/coverage/site.xml"
chmod -R a+rX "$here/.out"
node "$here/coverage-rawir.mjs" "$here/.out/coverage" "$here/.out/coverage/site.xml"
node "$here/verify-store.mjs" "$here/.out/coverage/private/store"

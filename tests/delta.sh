#!/usr/bin/env bash
# B-06 delta cursor against the acceptance WordPress that tests/run.sh left up
# (run it with KEEP=1 first). Mutates that throwaway site; copies the plans and
# inventories to tests/.out/delta for the planner fixture and the secret scan.
set -euo pipefail
here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
compose=(docker compose -f "$here/compose.yml")
# Pretty permalinks, set before the PHP process that takes T0: taxonomy link
# structures are registered at init, so switching inside the script is too late.
"${compose[@]}" run --rm -T cli rewrite structure '/%postname%/' >/dev/null
log="$(mktemp)"
"${compose[@]}" exec -T wordpress \
  php /var/www/html/wp-content/plugins/contentrain-bridge/tests/delta.php | tee "$log"
out="$(sed -n 's/^.*Delta output: //p' "$log" | tail -1)"
test -n "$out"
rm -rf "$here/.out/delta"
mkdir -p "$here/.out"
docker cp "$("${compose[@]}" ps -q wordpress):$out" "$here/.out/delta"
chmod -R a+rX "$here/.out"
node "$here/delta-contract.mjs" "$here/.out/delta"
node "$here/delta-fixture.mjs" "$here/.out/delta/planner"

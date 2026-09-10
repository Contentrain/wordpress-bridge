#!/usr/bin/env bash
# Bring up a throwaway WordPress, install the plugin and run the acceptance
# script inside it. Nothing here touches a real site: the container refuses to
# run unless the environment is `local` and the site is named "Bridge
# Acceptance", and the plugin is mounted read-only.
set -euo pipefail

here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
compose=(docker compose -f "$here/compose.yml")
cli=("${compose[@]}" run --rm -T cli)

cleanup() {
  if [ "${KEEP:-0}" != "1" ]; then
    "${compose[@]}" down -v >/dev/null 2>&1 || true
  fi
}
trap cleanup EXIT

"${compose[@]}" up -d db wordpress

# wp-cli waits for the database itself; the web container only has to have
# unpacked WordPress into the shared volume.
for _ in $(seq 1 60); do
  if "${cli[@]}" core version >/dev/null 2>&1; then break; fi
  sleep 2
done

"${cli[@]}" core install \
  --url=http://localhost:8094 --title='Bridge Acceptance' \
  --admin_user=bridge-admin --admin_password=bridge-local-only \
  --admin_email=bridge-admin@example.test --skip-email >/dev/null

"${cli[@]}" plugin activate contentrain-bridge >/dev/null

log="$(mktemp)"
"${compose[@]}" exec -T wordpress \
  php /var/www/html/wp-content/plugins/contentrain-bridge/tests/integration.php | tee "$log"

# Keep the finished store on the host: it is the artifact the external
# verification (real Contentrain CLI, canonical byte parity) runs against.
store="$(sed -n 's/^.*Output: //p' "$log" | tail -1)"
if [ -n "$store" ]; then
  out="$here/.out"
  rm -rf "$out"
  mkdir -p "$out"
  docker cp "$("${compose[@]}" ps -q wordpress):$store" "$out/store"
  echo "Store copied to $out/store"
fi

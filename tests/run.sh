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

# The field layer most WordPress content actually lives in, so the fixture
# has to exercise it rather than assume it. Secure Custom Fields (WP.org, a
# free fork of ACF that carries every Pro field type — clone, Options Page —
# for free) rather than ACF itself: `clone` and Options Page have no free ACF
# license to test against, and SCF keeps ACF's own function names and
# database format, so this one fixture proves both the field types ACF Free
# already covered *and* the ones it does not, instead of two fixtures that
# could quietly drift apart. The two plugins define the same functions and
# cannot both be active.
if ! "${cli[@]}" plugin is-installed secure-custom-fields >/dev/null 2>&1; then
  "${cli[@]}" plugin install secure-custom-fields --version=6.9.5 >/dev/null
fi
"${cli[@]}" plugin activate secure-custom-fields >/dev/null

# Menus and language pairs are only real once a real multilingual plugin
# tags real content; Polylang is free, from wordpress.org, and required for
# the same reason ACF is: a failed dependency install is not a passing test.
if ! "${cli[@]}" plugin is-installed polylang >/dev/null 2>&1; then
  "${cli[@]}" plugin install polylang >/dev/null
fi
"${cli[@]}" plugin activate polylang >/dev/null

# The free BeAPI plugin that gives an Options Page a real per-language copy of
# its values, so the export's own warning for it (an Options Page's values
# are otherwise a single, default-language row — see Models::options_page())
# is proven against the real thing detecting it, not a guessed constant.
if ! "${cli[@]}" plugin is-installed acf-options-for-polylang >/dev/null 2>&1; then
  "${cli[@]}" plugin install acf-options-for-polylang --version=2.0.0 >/dev/null
fi
"${cli[@]}" plugin activate acf-options-for-polylang >/dev/null

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
  # `docker cp` writes with the host user's ownership and the container's modes.
  # The plugin directory is mounted into the wp-cli container, which runs as
  # uid 33, so a store it cannot traverse makes Plugin Check die on a directory
  # it was never meant to look at. Invisible locally, where the same user owns
  # everything; the first real CI run failed on it.
  chmod -R a+rX "$out"
  echo "Store copied to $out/store"
fi

# Polylang filters WordPress's own term queries by "current language" once
# active — a global behaviour change, not just new fields the way ACF adds
# them. The store this run produced is already captured on disk; later CI
# steps (test:seo, test:delta, test:e2e, ...) reuse this same KEEP=1 site and
# know nothing about Polylang, so leaving it active would break their own
# taxonomy lookups for terms nothing here ever tags with a language.
"${cli[@]}" plugin deactivate polylang acf-options-for-polylang >/dev/null

# SCF stays active: test:delta, test:text and test:integrations all reuse this
# same KEEP=1 site and expect a real ACF-compatible plugin (any function-name
# check they run, `acf_add_local_field_group` and friends, is satisfied by
# either ACF or SCF equally). Only B-11's own e2e.sh activates
# `advanced-custom-fields` itself, later still, and deactivates SCF first for
# exactly that reason — see the comment there.

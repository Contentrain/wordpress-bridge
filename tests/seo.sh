#!/usr/bin/env bash
# B-04 against the acceptance WordPress that tests/run.sh left up (KEEP=1).
# 1. No SEO or redirect plugin: the export says "none".
# 2. Yoast SEO + Redirection (pinned, from wordpress.org), a custom permalink
#    structure and the fixture; every exported value is checked against the
#    live site, then fed to @contentrain/verify.
# 3. Both plugins deactivated: their data is still exported, not claimed as served.
set -euo pipefail
here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
compose=(docker compose -f "$here/compose.yml")
cli=("${compose[@]}" run --rm -T cli)
php=("${compose[@]}" exec -T wordpress php)
plugin=/var/www/html/wp-content/plugins/contentrain-bridge/tests

if "${cli[@]}" plugin is-installed wordpress-seo >/dev/null 2>&1; then
  echo "SKIP: no-plugin case needs a fresh fixture (Yoast was installed by an earlier run)"
else
  "${php[@]}" "$plugin/seo-none.php" none
fi

"${cli[@]}" plugin install wordpress-seo --version=28.5 --activate >/dev/null
"${cli[@]}" plugin install redirection --version=5.10.0 --activate >/dev/null
"${cli[@]}" redirection database install >/dev/null
"${cli[@]}" rewrite structure '/blog/%year%/%postname%/' --category-base=topics --tag-base=labels >/dev/null
"${php[@]}" "$plugin/seo-fixture.php"
# Yoast renders from its indexables; build them before comparing against pages.
"${cli[@]}" yoast index --reindex --skip-confirmation >/dev/null 2>&1 || "${cli[@]}" yoast index >/dev/null
log="$(mktemp)"
"${php[@]}" "$plugin/seo.php" | tee "$log"
out="$(sed -n 's/^.*SEO output: //p' "$log" | tail -1)"
test -n "$out"
rm -rf "$here/.out/seo"
mkdir -p "$here/.out"
docker cp "$("${compose[@]}" ps -q wordpress):$out" "$here/.out/seo"
chmod -R a+rX "$here/.out"

"${cli[@]}" plugin deactivate wordpress-seo redirection >/dev/null
"${php[@]}" "$plugin/seo-none.php" inactive

node "$here/seo-verify.mjs" "$here/.out/seo"

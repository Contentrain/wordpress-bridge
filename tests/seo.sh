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

# BR-22: each plugin Bridge renders for, live, one at a time: Bridge's rendering against the head it serves.
prefix="$("${cli[@]}" db prefix | tr -d '[:space:]')"
# AIOSEO builds its own aioseo_posts; the fixture's reduced one would stand in its way (seo-live.php restores the row).
"${cli[@]}" db query "DROP TABLE IF EXISTS ${prefix}aioseo_posts" >/dev/null
live=0
# Pinned, like Yoast above: a plugin release that changes what it prints must be a deliberate update here.
for pair in rank_math:seo-by-rank-math:1.0.279 aioseo:all-in-one-seo-pack:5.0.2 seopress:wp-seopress:10.2; do
  IFS=: read -r provider slug version <<<"$pair"
  "${cli[@]}" plugin install "$slug" --version="$version" --activate >/dev/null
  "${cli[@]}" plugin list --name="$slug" --fields=name,version --format=csv | tail -1
  # Every plugin is compared even when one differs, so a run reports all three.
  "${php[@]}" "$plugin/seo-live.php" "$provider" || live=1
  "${cli[@]}" plugin deactivate "$slug" >/dev/null
done
test "$live" = 0

node "$here/seo-verify.mjs" "$here/.out/seo"

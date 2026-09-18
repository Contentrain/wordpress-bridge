#!/usr/bin/env bash
# B-11 end to end: WordPress fixture -> Bridge export (-> GitHub) -> Astro build,
# then three edits -> second export -> Git diff -> Astro rebuild.
#
# Runs against the acceptance WordPress that tests/run.sh left up (KEEP=1).
# The GitHub leg is the B-10 path and runs only when BRIDGE_TEST_REPO and
# BRIDGE_TEST_TOKEN are set; without them it is reported as skipped, and the
# delivered tree is the export itself, committed to a local Git repository.
# The repository must be dedicated to this run: a default branch holding an
# earlier run's content is refused, as a real user's would be. Set
# BRIDGE_TEST_RESET=1 to let the run reset it first (repository names with
# "test" or "e2e" only; this discards that branch's history).
set -euo pipefail
here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
compose=(docker compose -f "$here/compose.yml")
cli=("${compose[@]}" run --rm -T cli)
php=("${compose[@]}" exec -T -e BRIDGE_TEST_REPO="${BRIDGE_TEST_REPO:-}" -e BRIDGE_TEST_TOKEN="${BRIDGE_TEST_TOKEN:-}" -e BRIDGE_TEST_RESET="${BRIDGE_TEST_RESET:-}" wordpress php /var/www/html/wp-content/plugins/contentrain-bridge/tests/e2e.php)
out="$here/.out/e2e"

if [ -z "${BRIDGE_TEST_REPO:-}" ] || [ -z "${BRIDGE_TEST_TOKEN:-}" ]; then
  echo "SKIP: GitHub leg (BRIDGE_TEST_REPO/BRIDGE_TEST_TOKEN not set) — delivering to a local Git repository instead"
fi

for plugin in advanced-custom-fields:6.8.10 wordpress-seo:28.5 redirection:5.10.0; do
  name="${plugin%%:*}"
  "${cli[@]}" plugin is-installed "$name" >/dev/null 2>&1 || "${cli[@]}" plugin install "$name" --version="${plugin##*:}" >/dev/null
  "${cli[@]}" plugin activate "$name" >/dev/null
done
"${cli[@]}" redirection database install >/dev/null
"${cli[@]}" rewrite structure '/%postname%/' >/dev/null
"${cli[@]}" option update show_on_front posts >/dev/null

"${php[@]}" fixture
"${php[@]}" export t0
"${php[@]}" mutate
"${php[@]}" export t1
"${php[@]}" delta

rm -rf "$out"
mkdir -p "$here/.out"
docker cp "$("${compose[@]}" ps -q wordpress):/tmp/bridge-e2e" "$out"
chmod -R a+rX "$here/.out"

test -d "$here/e2e-astro/node_modules" || (cd "$here/e2e-astro" && npm ci --ignore-scripts --no-audit --no-fund >/dev/null)
node "$here/e2e-check.mjs" build "$out/t0" "$out/dist-t0"
node "$here/e2e-check.mjs" diff "$out/t0" "$out/t1" "$out/mutation.json"
node "$here/e2e-check.mjs" build "$out/t1" "$out/dist-t1" >/dev/null
node "$here/e2e-check.mjs" rebuilt "$out/t1" "$out/dist-t1" "$out/mutation.json" "$out/t0"
echo "Studio intake: waiting on staging (not run)"

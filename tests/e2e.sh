#!/usr/bin/env bash
# B-11 end to end: WordPress fixture -> Bridge export (-> GitHub) -> Astro build,
# then three edits -> second export -> Git diff -> Astro rebuild.
#
# Runs against the acceptance WordPress that tests/run.sh left up (KEEP=1).
# The GitHub leg is the B-10 path and runs only when BRIDGE_TEST_REPO and
# BRIDGE_TEST_TOKEN are set; without them it is reported as skipped, and the
# delivered tree is the export itself, committed to a local Git repository.
# The repository must be dedicated to these runs. Each run cuts its own base
# branch, `e2e/<run>`, from the repository's first commit (creating that commit
# if the repository is still empty), delivers and accepts against it, and
# deletes every branch it made on exit, pass or fail. The default branch keeps
# its first commit only, so no run inherits another's content and nothing is
# ever reset. The output is checked for the token before the script exits.
set -euo pipefail
here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
compose=(docker compose -f "$here/compose.yml")
cli=("${compose[@]}" run --rm -T cli)
run_id="${GITHUB_RUN_ID:-local-$(date -u +%Y%m%d-%H%M%S)}-${GITHUB_RUN_ATTEMPT:-1}-$RANDOM"
php_cmd=("${compose[@]}" exec -T -e BRIDGE_TEST_REPO="${BRIDGE_TEST_REPO:-}" -e BRIDGE_TEST_TOKEN="${BRIDGE_TEST_TOKEN:-}" -e BRIDGE_E2E_RUN="$run_id" wordpress php /var/www/html/wp-content/plugins/contentrain-bridge/tests/e2e.php)
log="$(mktemp)"
# Every WordPress-side stage's output also goes to $log, so the exit check below
# sees what CI prints, including an uncaught exception's stack trace.
php() { "${php_cmd[@]}" "$@" 2>&1 | tee -a "$log"; return "${PIPESTATUS[0]}"; }
github=0
if [ -n "${BRIDGE_TEST_REPO:-}" ] && [ -n "${BRIDGE_TEST_TOKEN:-}" ]; then github=1; fi
finish() {
  status=$?
  if [ "$github" = 1 ]; then php github-cleanup || status=1; fi
  # No part of the token may reach the output: not the value, not its prefix.
  if [ -n "${BRIDGE_TEST_TOKEN:-}" ] && grep -qF -- "${BRIDGE_TEST_TOKEN:0:12}" "$log"; then
    echo "FAIL: the GitHub token appears in the e2e output"; status=1
  fi
  if grep -qE 'github_pat_|ghp_[A-Za-z0-9]' "$log"; then
    echo "FAIL: a GitHub token prefix appears in the e2e output"; status=1
  fi
  rm -f "$log"
  exit "$status"
}
trap finish EXIT
out="$here/.out/e2e"

if [ "$github" = 0 ]; then
  echo "SKIP: GitHub leg (BRIDGE_TEST_REPO/BRIDGE_TEST_TOKEN not set) — delivering to a local Git repository instead"
fi

# test:wordpress leaves Secure Custom Fields active for the gates in between
# (test:delta, test:text, test:integrations, ...) that need a real ACF-
# compatible plugin. SCF and ACF define the same functions — active together,
# that is a fatal duplicate-function error, not a compatibility question — so
# it comes out right before this step's own `advanced-custom-fields` goes in.
if "${cli[@]}" plugin is-active secure-custom-fields >/dev/null 2>&1; then
  "${cli[@]}" plugin deactivate secure-custom-fields >/dev/null
fi

for plugin in advanced-custom-fields:6.8.10 wordpress-seo:28.5 redirection:5.10.0; do
  name="${plugin%%:*}"
  "${cli[@]}" plugin is-installed "$name" >/dev/null 2>&1 || "${cli[@]}" plugin install "$name" --version="${plugin##*:}" >/dev/null
  "${cli[@]}" plugin activate "$name" >/dev/null
done
"${cli[@]}" redirection database install >/dev/null
"${cli[@]}" rewrite structure '/%postname%/' >/dev/null
"${cli[@]}" option update show_on_front posts >/dev/null

php fixture
if [ "$github" = 1 ]; then php github-prepare; fi
php export t0
php mutate
php export t1
php delta

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

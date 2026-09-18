#!/usr/bin/env bash
# B-07 against the acceptance WordPress that tests/run.sh left up (KEEP=1).
set -euo pipefail
here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
compose=(docker compose -f "$here/compose.yml")
cli=("${compose[@]}" run --rm -T cli)
wp=("${compose[@]}" exec -T wordpress)
content=/var/www/html/wp-content
"${cli[@]}" plugin is-installed mailchimp-for-wp >/dev/null 2>&1 || "${cli[@]}" plugin install mailchimp-for-wp --version=4.14.1 >/dev/null
"${cli[@]}" plugin activate mailchimp-for-wp >/dev/null
"${wp[@]}" sh -c "mkdir -p $content/mu-plugins && cp $content/plugins/contentrain-bridge/tests/fixtures/bridge-integrations-head.php $content/mu-plugins/"
cleanup() {
  "${wp[@]}" rm -f "$content/mu-plugins/bridge-integrations-head.php" >/dev/null 2>&1 || true
  "${cli[@]}" plugin deactivate mailchimp-for-wp >/dev/null 2>&1 || true
}
trap cleanup EXIT
log="$(mktemp)"
"${wp[@]}" php "$content/plugins/contentrain-bridge/tests/integrations.php" | tee "$log"
grep -q 'Integrations output: ' "$log"
rm -rf "$here/.out/integrations"
mkdir -p "$here/.out"
docker cp "$("${compose[@]}" ps -q wordpress):/tmp/bridge-integrations" "$here/.out/integrations"
chmod -R a+rX "$here/.out"
# Migrate's intake gets the reconnect list as an issue, in Studio's handoff-issue shape.
node --input-type=module -e "
import { mkdtempSync, readFileSync } from 'node:fs'; import { tmpdir } from 'node:os'; import { join } from 'node:path'
import { prepareMigrate } from '$here/../tools/prepare-migrate.mjs'
const summary = prepareMigrate('$here/.out/integrations/store', join(mkdtempSync(join(tmpdir(), 'bridge-int-')), 'intake'))
const issue = summary.issues.find((i) => i.code === 'integration_reconnect_required')
if (!issue || !issue.services.some((s) => s.service === 'hubspot') || issue.services.some((s) => s.service === 'embed-youtube')) throw new Error('FAIL: intake issue')
console.log('PASS: the Migrate intake carries integration_reconnect_required with ' + issue.services.length + ' services (embeds left out)')
"

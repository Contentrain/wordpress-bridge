#!/usr/bin/env bash
# B-08 against the acceptance WordPress that tests/run.sh left up (KEEP=1):
# install the fixture theme, scan every source of interface text, export, and
# verify the resulting store with the published Contentrain toolchain.
set -euo pipefail
here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
compose=(docker compose -f "$here/compose.yml")
cli=("${compose[@]}" run --rm -T cli)
wp=("${compose[@]}" exec -T wordpress)
themes=/var/www/html/wp-content/themes

previous="$("${cli[@]}" option get stylesheet 2>/dev/null | tail -1)"
"${wp[@]}" sh -c "rm -rf $themes/bridge-text-theme && cp -r /var/www/html/wp-content/plugins/contentrain-bridge/tests/fixtures/bridge-text-theme $themes/ \
  && head -c 2200000 /dev/zero | tr '\\0' 'x' > $themes/bridge-text-theme/assets/huge.js && chown -R www-data:www-data $themes/bridge-text-theme"
"${cli[@]}" theme activate bridge-text-theme >/dev/null
"${cli[@]}" rewrite structure '/%postname%/' >/dev/null
restore() { "${cli[@]}" theme activate "$previous" >/dev/null 2>&1 || true; }
trap restore EXIT

log="$(mktemp)"
"${wp[@]}" php /var/www/html/wp-content/plugins/contentrain-bridge/tests/text.php | tee "$log"
grep -q 'Text output: ' "$log"
rm -rf "$here/.out/text"
mkdir -p "$here/.out"
docker cp "$("${compose[@]}" ps -q wordpress):/tmp/bridge-text" "$here/.out/text"
chmod -R a+rX "$here/.out"
# The dictionary and singleton previews, read by the published toolchain (B-09's serializer gate).
node "$here/verify-store.mjs" "$here/.out/text/store"
# The inventory reaches Migrate's intake as RawIR's hardcoded_text block.
node --input-type=module -e "
import { mkdtempSync, readFileSync } from 'node:fs'; import { tmpdir } from 'node:os'; import { join } from 'node:path'
import { prepareMigrate } from '$here/../tools/prepare-migrate.mjs'
const target = join(mkdtempSync(join(tmpdir(), 'bridge-text-intake-')), 'intake')
prepareMigrate('$here/.out/text/store', target)
const raw = JSON.parse(readFileSync(join(target, 'rawir.json'), 'utf8'))
const inv = JSON.parse(readFileSync('$here/.out/text/store/bridge/hardcoded-text.json', 'utf8'))
if (raw.hardcoded_text?.format !== 'contentrain-bridge-hardcoded-text@1' || raw.hardcoded_text.candidates.length !== inv.candidates.length) throw new Error('FAIL: rawir.hardcoded_text')
console.log('PASS: rawir.hardcoded_text carries all ' + inv.candidates.length + ' candidates into the Migrate intake')
"

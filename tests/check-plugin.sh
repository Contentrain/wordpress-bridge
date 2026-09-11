#!/usr/bin/env bash
# Official Plugin Check against runtime files; development files are not packaged.
set -euo pipefail
here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cli=(docker compose -f "$here/compose.yml" run --rm -T cli)
"${cli[@]}" plugin install plugin-check --version=2.1.0 --force --activate >/dev/null
mkdir -p "$here/.out"
status=0
"${cli[@]}" plugin check contentrain-bridge \
  --exclude-directories=tests,tools,dist,.github \
  --exclude-files=.gitignore,package.json,package-lock.json,RELEASING.md \
  --format=strict-json > "$here/.out/plugin-check.json" || status=$?
node --input-type=module - "$here/.out/plugin-check.json" "$status" <<'JS'
import {readFileSync} from 'node:fs';
const findings = JSON.parse(readFileSync(process.argv[2], 'utf8'));
if (!Array.isArray(findings) || Number(process.argv[3]) > 1) throw new Error('Plugin Check did not finish normally');
const errors = findings.filter(row => row.type === 'ERROR');
const warnings = findings.filter(row => row.type === 'WARNING');
console.log(`Plugin Check: ${errors.length} errors, ${warnings.length} warnings`);
if (errors.length) { console.error(errors); process.exit(1); }
JS

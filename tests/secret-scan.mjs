// Scan everything Bridge would hand over — the finished store and the delta
// artifacts — for the credentials and personal data the fixtures plant, and
// for credential shapes in general. The scanner proves itself first on a
// planted file: a scan that cannot fail proves nothing.
//
//   node tests/secret-scan.mjs [dir ...]
import { mkdtempSync, readdirSync, readFileSync, rmSync, statSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'

const here = dirname(fileURLToPath(import.meta.url))
import { existsSync } from 'node:fs'
const roots = process.argv.slice(2).length ? process.argv.slice(2) : ['store', 'delta', 'seo', 'text', 'integrations'].map((d) => join(here, '.out', d)).filter((d) => existsSync(d))

// Planted by tests/integration.php and tests/delta.php, or configured in compose.yml.
const CANARIES = [
  'never-export-me', 'never-export-nested', 'hidden-credential', 'private@example.test', 'private-in-meta@example.test',
  '192.0.2.1', 'test-only-token', 'bridge-local-only', 'bridge-admin@example.test', 'delta-unreleased-launch',
  // Planted by tests/seo-fixture.php in Yoast's general option.
  'never-export-semrush', 'never-export-wincher', 'never-export-myyoast',
  // Planted by tests/integrations.php in the services' own settings.
  'never-export-mailchimp', 'never-export-akismet', 'never-export-recaptcha', 'never-export-wpforms', 'never-export-gf-feed-key',
]
const PATTERNS = [
  /-----BEGIN [A-Z ]*PRIVATE KEY-----/,
  /\bgh[pousr]_[A-Za-z0-9]{20,}/,
  /\bgithub_pat_[A-Za-z0-9_]{20,}/,
  /\bsk-[A-Za-z0-9]{20,}/,
  /\bAKIA[0-9A-Z]{16}\b/,
  /\bxox[abpr]-[A-Za-z0-9-]{10,}/,
]

const walk = (dir) => readdirSync(dir).flatMap((name) => {
  const path = join(dir, name)
  return statSync(path).isDirectory() ? walk(path) : [path]
})
const scan = (dirs) => {
  const hits = []
  let files = 0
  for (const dir of dirs) {
    for (const file of walk(dir)) {
      if (/\.(png|jpe?g|gif|webp|zip)$/i.test(file)) continue
      files++
      const text = readFileSync(file, 'utf8')
      for (const canary of CANARIES) if (text.includes(canary)) hits.push(`${file}: ${canary}`)
      for (const pattern of PATTERNS) if (pattern.test(text)) hits.push(`${file}: ${pattern}`)
    }
  }
  return { files, hits }
}

const control = mkdtempSync(join(tmpdir(), 'bridge-secret-control-'))
try {
  writeFileSync(join(control, 'planted.json'), JSON.stringify({ token: 'ghp_' + 'Z'.repeat(36), note: 'never-export-me' }))
  const planted = scan([control])
  if (planted.hits.length !== 2) throw new Error('FAIL: the scanner missed a planted secret: ' + JSON.stringify(planted.hits))
  console.log('PASS: the scanner finds planted secrets (negative control)')
} finally { rmSync(control, { recursive: true, force: true }) }

const { files, hits } = scan(roots)
if (!files) throw new Error('FAIL: nothing to scan in ' + roots.join(', '))
if (hits.length) {
  console.error(hits.join('\n'))
  throw new Error(`FAIL: ${hits.length} secret(s) in the export`)
}
console.log(`PASS: ${files} exported files, 0 secrets (${CANARIES.length} canaries, ${PATTERNS.length} patterns)`)

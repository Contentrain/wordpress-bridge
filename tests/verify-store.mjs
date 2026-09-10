// Verify an exported store against the real Contentrain toolchain.
//
// The plugin's own Validator is written by the same author as the writer, so
// it can only prove internal consistency. This script proves the two things a
// user actually depends on: that Contentrain reads the store, and that the
// bytes are the ones Contentrain itself would have written — otherwise the
// first edit in Studio reorders files and buries the real change in noise.
//
//   node tests/verify-store.mjs [storeDir]
//
// Needs the published packages. Point CONTENTRAIN_TYPES at a local build to
// verify against unreleased serializer changes.
import { execFileSync } from 'node:child_process'
import { mkdtempSync, readdirSync, readFileSync, statSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { dirname, join } from 'node:path'
import { fileURLToPath, pathToFileURL } from 'node:url'

const here = dirname(fileURLToPath(import.meta.url))
const store = process.argv[2] ?? join(here, '.out', 'store')
const typesEntry = process.env.CONTENTRAIN_TYPES ?? '@contentrain/types'

const { canonicalStringify, MODEL_FIELD_ORDER } = await import(
  /^[./]/.test(typesEntry) ? pathToFileURL(typesEntry).href : typesEntry
)

const walk = (dir) =>
  readdirSync(dir).flatMap((name) => {
    const path = join(dir, name)
    return statSync(path).isDirectory() ? walk(path) : [path]
  })

const fail = (message) => {
  console.error('FAIL: ' + message)
  process.exitCode = 1
}

// -- 1. The published CLI reads the store --
// A throwaway git repo: `contentrain validate` expects a project, not a folder.
const project = mkdtempSync(join(tmpdir(), 'bridge-verify-'))
execFileSync('cp', ['-R', store + '/.', project])
execFileSync('git', ['init', '-q'], { cwd: project })
execFileSync('git', ['add', '-A'], { cwd: project })
execFileSync('git', ['-c', 'user.email=verify@example.test', '-c', 'user.name=verify', 'commit', '-qm', 'store'], { cwd: project })

// One retry: the first `npx --yes` on a cold cache fetches the CLI, and a
// failed fetch is not a verdict about the store. A second failure is.
let output = ''
let error = null
for (let attempt = 0; attempt < 2; attempt++) {
  try {
    output = execFileSync('npx', ['--yes', 'contentrain', 'validate'], { cwd: project, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] })
    error = null
    break
  } catch (thrown) {
    error = thrown
    output = (thrown.stdout ?? '') + (thrown.stderr ?? '')
  }
}
const plain = output.replace(new RegExp(String.fromCharCode(27) + '\\[[0-9;]*m', 'g'), '')
if (error) {
  fail('contentrain validate exited non-zero\n' + plain)
  process.exit(1)
}
if (!/Project is valid/.test(plain)) {
  fail('contentrain validate did not report a valid project\n' + plain)
  process.exit(1)
}
const counts = /Models checked:\s*(\d+)[\s\S]*?Entries checked:\s*(\d+)/.exec(plain)
console.log('PASS: contentrain validate -- ' + (counts ? counts[1] + ' models, ' + counts[2] + ' entries' : 'valid'))

// -- 2. Every store file is byte-identical to canonical serialization --
let checked = 0
for (const file of walk(join(store, '.contentrain'))) {
  if (!file.endsWith('.json')) continue
  const text = readFileSync(file, 'utf8')
  const expected = canonicalStringify(JSON.parse(text), file.includes('/models/') ? MODEL_FIELD_ORDER : undefined)
  if (expected !== text) {
    const actualLines = text.split('\n')
    const index = expected.split('\n').findIndex((line, i) => line !== actualLines[i])
    fail('not canonical: ' + file + ' (first difference on line ' + (index + 1) + ')')
  }
  checked++
}
if (checked === 0) fail('no store files were checked -- wrong directory?')
if (!process.exitCode) console.log('PASS: canonical byte parity -- ' + checked + ' files')

// -- 3. Documents parse as frontmatter + body --
let documents = 0
for (const file of walk(join(store, '.contentrain'))) {
  if (!file.endsWith('.md')) continue
  if (!/^---\n[\s\S]*?\n---\n/.test(readFileSync(file, 'utf8'))) fail('document has no frontmatter: ' + file)
  documents++
}
console.log('PASS: ' + documents + ' documents carry frontmatter')

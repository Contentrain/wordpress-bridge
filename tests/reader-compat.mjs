// Can the Contentrain reader read what this plugin writes?
//
// The plugin refuses to finalize an export whose document metadata needs
// escaped characters, because the published `@contentrain/types` reader strips
// the quotes off a scalar without decoding its escapes — a title with a quote
// in it, or an excerpt with a newline, came back changed. Refusing is the right
// behaviour while that is true, and the wrong behaviour the moment it is not.
//
// Deciding which it is needs real output. The acceptance suite writes
// `reader-compat.md` using this plugin's own frontmatter writer with the guard
// off, alongside `reader-compat.json` holding the values that went in. This
// script reads the document back with a chosen reader build and compares.
//
//   node tests/reader-compat.mjs                                  # published reader
//   CONTENTRAIN_TYPES=../ai/packages/types/dist/index.mjs node tests/reader-compat.mjs
//
// Exit 0 means the guard in Policy::frontmatter can be lifted against that
// reader, with its two "blocks finalization" acceptance checks.

import { readFileSync } from 'node:fs'
import { dirname, join, resolve } from 'node:path'
import { fileURLToPath, pathToFileURL } from 'node:url'

const here = dirname(fileURLToPath(import.meta.url))
const store = process.argv[2] ?? join(here, '.out', 'store')
const typesEntry = process.env.CONTENTRAIN_TYPES ?? '@contentrain/types'

const { parseMarkdownFrontmatter } = await import(
  /^[./]/.test(typesEntry) ? pathToFileURL(resolve(typesEntry)).href : typesEntry
)

const document = readFileSync(join(store, 'reader-compat.md'), 'utf8')
const expected = JSON.parse(readFileSync(join(store, 'reader-compat.json'), 'utf8'))

const { frontmatter, body } = parseMarkdownFrontmatter(document)

let failed = 0
const report = (ok, line) => {
  if (!ok) failed += 1
  console.log(`${ok ? 'PASS' : 'FAIL'}: ${line}`)
}

for (const key of Object.keys(expected).sort()) {
  const want = JSON.stringify(expected[key])
  const got = JSON.stringify(frontmatter[key])
  report(want === got, `${key} reads back unchanged -- wrote ${want}, read ${got}`)
}
report(body === 'Body text.', `body reads back unchanged -- read ${JSON.stringify(body)}`)

console.log(`\nReader: ${typesEntry}`)
if (failed) {
  console.error(
    `\n${failed} value(s) do not survive this reader. `
    + 'Keep the guard in Policy::frontmatter: an export finalized against this reader would change the content.',
  )
  process.exit(1)
}
console.log('\nEvery escape-bearing value survives. The guard in Policy::frontmatter can be lifted against this reader.')

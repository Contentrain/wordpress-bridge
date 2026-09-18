// B-04 done criterion: "A-08 verify can establish old/new parity with this data."
//
// The old site is the pages WordPress actually served (captured by
// tests/seo.php). The new site is a head built only from what Bridge exported.
// @contentrain/verify runs with the captured pages as its baseline and Bridge's
// redirects as the host's rules. Parity holds when every error verify reports on
// the rebuilt pages is one the old site already had — an intentional noindex or
// cross-domain canonical is the source's decision, not a migration loss — and
// verify must still catch a loss we introduce on purpose.
//
//   node tests/seo-verify.mjs [seoDir]
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'
import { verify } from '@contentrain/verify'

const dir = process.argv[2] ?? join(dirname(fileURLToPath(import.meta.url)), '.out', 'seo')
const read = (name) => JSON.parse(readFileSync(join(dir, name), 'utf8'))
const { site, pages } = read('pages.json')
const entries = read('seo-entries.json')
const redirects = read('redirects.json')
const fixture = read('fixture.json')
let checks = 0
const check = (ok, message) => {
  if (!ok) throw new Error('FAIL: ' + message)
  checks++
  console.log('PASS: ' + message)
}

const esc = (s) => String(s).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;')
/** A page head from an exported SEO entry — nothing else goes in. */
const rebuild = (entry, { dropSchema = false, dropTitle = false } = {}) => {
  const e = entry.yoast
  const robots = (e.robots_served ?? []).join(', ')
  const head = [
    dropTitle ? '' : `<title>${esc(e.title)}</title>`,
    e.description ? `<meta name="description" content="${esc(e.description)}">` : '',
    robots ? `<meta name="robots" content="${esc(robots)}">` : '',
    e.canonical ? `<link rel="canonical" href="${esc(e.canonical)}">` : '',
    ...Object.entries(e.open_graph ?? {}).map(([k, v]) => `<meta property="og:${k}" content="${esc(v)}">`),
    ...Object.entries(e.twitter ?? {}).map(([k, v]) => `<meta name="twitter:${k}" content="${esc(v)}">`),
    e.schema?.graph && !dropSchema ? `<script type="application/ld+json">${JSON.stringify(e.schema.graph)}</script>` : '',
  ].join('\n')
  const body = `<h1>${esc(e.title)}</h1><p>${'Migrated body text. '.repeat(40)}</p>`
  return `<!doctype html><html lang="en-US"><head>${head}</head><body>${body}</body></html>`
}

const baseline = pages.map((p) => ({ url: p.url, status: 200, html: readFileSync(join(dir, 'captured', p.key + '.html'), 'utf8') }))
const rebuilt = (opts = {}) => pages.map((p) => ({ url: p.url, status: 200, html: rebuild(entries[p.entry], opts[p.key] ?? {}) }))
// The host's rules: this fixture's plain, served redirects (regex rules need a server, not a map).
const rules = redirects.redirects
  .filter((r) => !r.regex && r.match === 'url' && r.from.startsWith('/') && r.from.includes(fixture.run))
  .map(({ from, to, status }) => ({ from, to, status }))
const errors = (report) => report.findings.filter((f) => f.severity === 'error').map((f) => `${f.check} ${f.url ?? ''}`).sort()

check(rules.length >= 5, `${rules.length} exported redirects handed to verify as the host's rules`)

// The old site measured by the same gate: what it already "fails" is its own intent.
const old = verify({ site, documents: baseline, redirects: rules })
const migrated = verify({ site, documents: rebuilt(), redirects: rules, baseline: { documents: baseline } })
// The old pages are full theme renders; their navigation reaches pages outside
// this set, so they carry link errors the head-only rebuild cannot. Parity is
// "no new error", not "the same errors".
const oldErrors = new Set(errors(old))
console.log(`old site: ${oldErrors.size} distinct errors; rebuilt: ${JSON.stringify(errors(migrated))}`)
check(errors(migrated).every((e) => oldErrors.has(e)), 'every error on the rebuilt pages is one the old site already had (no new error)')
check(errors(migrated).length === 2 && errors(migrated).some((e) => e.startsWith('identity.canonical-mismatch')) && errors(migrated).some((e) => e.startsWith('indexing.noindex')), 'the two remaining errors are the source\'s intent: a cross-domain canonical and a noindex page')
const lossChecks = ['structured.type-lost', 'status.mismatch', 'status.baseline-page-missing', 'structured.jsonld-invalid', 'status.redirect-target-missing', 'status.redirect-loop', 'identity.title-missing', 'identity.title-duplicate', 'identity.canonical-duplicate']
check(!migrated.findings.some((f) => lossChecks.includes(f.check)), 'no JSON-LD type, status, title or redirect loss against the baseline')
check(!migrated.findings.some((f) => f.check === 'identity.description-missing' || f.check === 'identity.open-graph-incomplete') || old.findings.some((f) => f.check === 'identity.description-missing' || f.check === 'identity.open-graph-incomplete'), 'description and Open Graph are no worse than the old site')
check(migrated.groups.includes('structured') && migrated.groups.includes('status') && migrated.documents === pages.length, `verify ran structured and status parity over ${pages.length} pages`)

// verify 0.2.0 reports an intentional noindex and a cross-domain canonical as
// errors whatever the baseline says; measured here so the gap is visible.
const noindexOld = pages.filter((p) => /noindex/.test(entries[p.entry].yoast.robots?.index ?? '')).map((p) => p.url).sort()
check(JSON.stringify(migrated.findings.filter((f) => f.check === 'indexing.noindex').map((f) => f.url).sort()) === JSON.stringify(noindexOld), `indexing.noindex fires exactly on the source's intentional noindex pages (${noindexOld.length}) — a verify gap, not a loss`)

// Sensitivity: a loss introduced on purpose must be caught.
const faq = pages.find((p) => p.key === 'faq_page')
const lostSchema = verify({ site, documents: rebuilt({ faq_page: { dropSchema: true } }), redirects: rules, baseline: { documents: baseline } })
check(lostSchema.findings.some((f) => f.check === 'structured.type-lost' && f.url === faq.url), 'dropping the exported JSON-LD is caught as structured.type-lost')
const lostTitle = verify({ site, documents: rebuilt({ faq_page: { dropTitle: true } }), redirects: rules, baseline: { documents: baseline } })
check(lostTitle.findings.some((f) => f.check === 'identity.title-missing'), 'dropping the exported title is caught')
const lostPage = verify({ site, documents: rebuilt().filter((d) => d.url !== faq.url), redirects: rules, baseline: { documents: baseline }, build: true })
const gaps = []
if (lostPage.findings.some((f) => f.check === 'status.baseline-page-missing')) {
  check(lostPage.findings.some((f) => f.check === 'status.baseline-page-missing' && f.url === faq.url), 'a page the migration did not produce, with no redirect, is caught')
  const covered = verify({ site, documents: rebuilt().filter((d) => d.url !== faq.url), redirects: [...rules, { from: faq.url, to: pages[0].url, status: 301 }], baseline: { documents: baseline }, build: true })
  check(!covered.findings.some((f) => f.check === 'status.baseline-page-missing' && f.url === faq.url), 'the same page covered by a redirect rule is not reported missing')
} else {
  // Not a pass: the installed verify cannot see a page the migration dropped.
  gaps.push('status.baseline-page-missing is not in the installed @contentrain/verify; a dropped page goes unreported')
}

// Nothing secret in what verify was handed.
const handed = JSON.stringify({ entries, redirects, seo: read('seo.json'), routing: read('routing.json') })
for (const canary of ['never-export-semrush', 'never-export-wincher', 'never-export-myyoast', 'ghp_']) check(!handed.includes(canary), `no ${canary} in the SEO artifacts`)
for (const gap of gaps) console.log('GAP: ' + gap)
console.log(`\n${checks} verify parity checks passed${gaps.length ? `, ${gaps.length} verify gap(s) reported` : ''}.`)

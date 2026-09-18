// "The same RawIR as A-03": the site exported twice — by Bridge (private scope,
// through prepare-migrate) and as a WXR file read by @contentrain/wp-import's own
// parser — must describe the same records the same way. Differences are counted
// by field and must be zero, reusable blocks and navigations included; what
// Bridge withholds (passwords, trash) is named, not ignored.
//
//   node tests/coverage-rawir.mjs <coverageDir> <site.wxr>
import { mkdtempSync, readFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { parseWxr } from '@contentrain/wp-import'
import { prepareMigrate } from '../tools/prepare-migrate.mjs'

const [dir, wxrFile] = process.argv.slice(2)
let checks = 0
const check = (ok, message) => {
  if (!ok) throw new Error('FAIL: ' + message)
  checks++
  console.log('PASS: ' + message)
}
const target = join(mkdtempSync(join(tmpdir(), 'bridge-a03-')), 'intake')
prepareMigrate(join(dir, 'private/store'), target)
const bridge = JSON.parse(readFileSync(join(target, 'rawir.json'), 'utf8'))
const { raw: wxr } = await parseWxr(readFileSync(wxrFile, 'utf8'))
check(bridge.version === wxr.version && bridge.provenance.kind === 'bridge' && wxr.provenance.kind === 'wxr', `both are RawIR v${bridge.version} (bridge / wxr provenance)`)

// Posts: every WXR record Bridge also exports, field by field.
const byId = (list) => new Map(list.map((p) => [p.id, p]))
const b = byId(bridge.posts)
const w = byId(wxr.posts.filter((p) => p.status !== 'trash'))
const withheld = wxr.posts.filter((p) => p.status === 'trash').length
const fields = ['type', 'status', 'slug', 'title', 'parent', 'menu_order', 'content', 'excerpt', 'author', 'comment_status', 'sticky']
const diffs = {}
const missing = []
for (const [id, wp] of w) {
  const bp = b.get(id)
  if (!bp) { missing.push(`${wp.type}:${id}`); continue }
  for (const f of fields) {
    const norm = (v) => (typeof v === 'string' ? v.trim() : v ?? null)
    if (JSON.stringify(norm(bp[f])) !== JSON.stringify(norm(wp[f]))) (diffs[f] ??= []).push(`${id}: bridge ${JSON.stringify(bp[f])?.slice(0, 60)} / wxr ${JSON.stringify(wp[f])?.slice(0, 60)}`)
  }
  const bt = bp.terms.map((t) => `${t.taxonomy}:${t.slug}`).sort()
  const wt = wp.terms.map((t) => `${t.taxonomy}:${t.slug}`).sort()
  if (JSON.stringify(bt) !== JSON.stringify(wt)) (diffs.terms ??= []).push(`${id}: bridge ${bt} / wxr ${wt}`)
}
for (const [f, list] of Object.entries(diffs)) console.log(`  ${f}: ${list.length} differ, e.g. ${list[0]}`)
check(!missing.length, `every one of ${w.size} WXR posts (trash withheld: ${withheld}) is in Bridge's RawIR${missing.length ? ': missing ' + missing.join(', ') : ''}`)
check(!Object.keys(diffs).length, `and equal on ${fields.length + 1} fields: ${[...fields, 'terms'].join(', ')}`)
const onlyBridge = bridge.posts.filter((p) => !w.has(p.id))
const types = [...new Set(onlyBridge.map((p) => p.type))].sort()
check(!onlyBridge.length, `Bridge has no record WXR lacks${onlyBridge.length ? ': ' + types.join(', ') : ''}; wp_block and wp_navigation are compared like any post`)

// Attachments, terms, authors, comments.
const ids = (list) => list.map((x) => x.id).sort((a, c) => a - c)
check(JSON.stringify(ids(bridge.attachments)) === JSON.stringify(ids(wxr.attachments)), `attachments: the same ${bridge.attachments.length} ids`)
const urlDiff = bridge.attachments.filter((a) => wxr.attachments.find((x) => x.id === a.id)?.url !== a.url)
check(!urlDiff.length, 'attachments: the same file URLs')
const tkey = (t) => `${t.taxonomy}:${t.slug}:${t.name}`
const bterms = new Set(bridge.terms.map(tkey))
const wmissing = wxr.terms.filter((t) => !bterms.has(tkey(t)))
check(!wmissing.length, `terms: all ${wxr.terms.length} WXR terms are in Bridge's RawIR with the same slug and name${wmissing.length ? ': ' + wmissing.map(tkey).join(', ') : ''}`)
const wauthors = new Set(wxr.authors.map((a) => a.login))
check(bridge.authors.every((a) => wauthors.has(a.login)) && bridge.authors.every((a) => !a.email), `authors: Bridge's ${bridge.authors.length} are among WXR's ${wxr.authors.length}, and carry no email`)
const wc = new Map(wxr.comments.map((c) => [c.id, c]))
const cdiff = bridge.comments.filter((c) => !wc.has(c.id) || wc.get(c.id).content !== c.content || wc.get(c.id).post !== c.post)
check(bridge.comments.length > 0 && !cdiff.length, `comments: Bridge's ${bridge.comments.length} match WXR's by id, post and content`)
check(bridge.posts.every((p) => p.password === null), 'Bridge withholds every post password (WXR carries them)')
console.log(`\n${checks} A-03 RawIR parity checks passed.`)

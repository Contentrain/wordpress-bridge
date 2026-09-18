// The A-09 planner fixture (ai PR #215, `planSourceDelta`) as Bridge writes it:
// `planner/t0-store` and `planner/t1-export` in the planner's own fixture layout
// (`.contentrain/` contents at the top, `entry-source-map.json` beside them) and
// `planner/t1.delta.json`. Every one of the twelve entries must be placeable by
// the planner's rules — posts through the source map, terms and attachments by
// their `wp_id` in the type's model — in T0 (and, for a created record, in T1):
// an unmapped entry is a record the planner would have to skip.
//
//   node tests/delta-fixture.mjs <plannerDir>
import { existsSync, readdirSync, readFileSync, statSync } from 'node:fs'
import { join } from 'node:path'

const dir = process.argv[2]
let checks = 0
const check = (ok, message) => {
  if (!ok) throw new Error('FAIL: ' + message)
  checks++
  console.log('PASS: ' + message)
}
const json = (path) => JSON.parse(readFileSync(path, 'utf8'))
const TAXONOMIES = new Set(['category', 'post_tag', 'nav_menu', 'post_format'])

/** Mirrors `place()` in ai/packages/wp-import/src/delta.ts (PR #215). */
function place(store, wpType, wpId) {
  const model = (id) => (existsSync(join(store, 'models', id + '.json')) ? json(join(store, 'models', id + '.json')) : null)
  const locale = json(join(store, 'config.json')).locales.default
  const typeModels = (wpType === 'attachment' ? ['wp-media', 'media'] : [`wp-tax-${wpType.replace(/_/g, '-')}`]).map(model).filter(Boolean)
  if (wpType === 'attachment' || TAXONOMIES.has(wpType) || typeModels.length) {
    if (!typeModels.length) return { unmapped: 'no-model-for-type' }
    for (const m of typeModels) {
      const data = join(store, 'content', m.domain, m.id, m.i18n ? locale + '.json' : 'data.json')
      const entries = existsSync(data) ? json(data) : {}
      const id = Object.keys(entries).find((k) => entries[k].wp_id === wpId)
      if (id) return { model: m.id, entry_id: id }
    }
    return { unmapped: 'entry-not-found' }
  }
  const ref = json(join(store, 'entry-source-map.json'))[String(wpId)]
  if (!ref) return { unmapped: 'not-in-source-map' }
  const m = model(ref.model_id)
  if (!m) return { unmapped: 'no-model-for-type' }
  const doc = join(store, 'content', m.domain, m.id, m.i18n ? `${ref.entry_id}/${ref.locale}.md` : `${ref.entry_id}.md`)
  return m.kind === 'document' && !existsSync(doc) ? { unmapped: 'entry-not-found' } : { model: m.id, entry_id: ref.entry_id }
}

for (const side of ['t0-store', 't1-export']) {
  const top = readdirSync(join(dir, side)).sort()
  check(['config.json', 'content', 'entry-source-map.json', 'meta', 'models'].every((n) => top.includes(n)) && !top.includes('.contentrain') && !top.includes('bridge'), `${side}: the planner fixture layout (store contents at the top, entry-source-map.json beside): ${top.join(' ')}`)
}
const delta = json(join(dir, 't1.delta.json'))
check(delta.version === 1 && delta.cursor.kind === 'bridge_inventory' && delta.entries.length === 12, 't1.delta.json: a SourceDeltaPlan with the twelve entries')
const unmapped = []
for (const e of delta.entries) {
  const where = e.op === 'created' ? 't1-export' : 't0-store'
  const at = place(join(dir, where), e.wp_type, e.wp_id)
  const label = `${e.wp_type}:${e.wp_id}:${e.op}${e.deleted_kind ? '/' + e.deleted_kind : ''}`
  console.log(`  ${label.padEnd(30)} ${where.padEnd(10)} ${at.unmapped ? 'UNMAPPED ' + at.unmapped : at.model + '/' + at.entry_id}`)
  if (at.unmapped) unmapped.push(label)
  if (['updated', 'moved'].includes(e.op)) {
    const after = place(join(dir, 't1-export'), e.wp_type, e.wp_id)
    if (after.unmapped || after.entry_id !== at.entry_id) unmapped.push(label + ' (T1)')
  }
}
check(!unmapped.length, `all 12 entries place in the store (unmapped 0)${unmapped.length ? ': ' + unmapped.join(', ') : ''}; updates and moves also in the T1 export, at the same entry`)
console.log(`\n${checks} planner fixture checks passed.`)

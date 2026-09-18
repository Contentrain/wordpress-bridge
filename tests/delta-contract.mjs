// The delta files as a TypeScript planner reads them: recompute each
// inventory's hash with JSON.stringify (not PHP), and check every plan against
// the SourceDeltaPlan shape. A hash only PHP can reproduce is not a cursor.
//
//   node tests/delta-contract.mjs [deltaDir]
import { createHash } from 'node:crypto'
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'

const dir = process.argv[2] ?? join(dirname(fileURLToPath(import.meta.url)), '.out', 'delta')
const read = (name) => JSON.parse(readFileSync(join(dir, name), 'utf8'))
let checks = 0
const check = (ok, message) => {
  if (!ok) throw new Error('FAIL: ' + message)
  checks++
  console.log('PASS: ' + message)
}

const byKey = (a, b) => (a.wp_type < b.wp_type ? -1 : a.wp_type > b.wp_type ? 1 : a.wp_id - b.wp_id)
const inventoryHash = (records) =>
  createHash('sha256')
    .update([...records].sort(byKey).map((r) => JSON.stringify([r.wp_type, r.wp_id, r.fingerprint, r.path ?? null, r.status ?? null])).join('\n'))
    .digest('hex')

for (const name of ['inventory-t0.json', 'inventory-t1.json']) {
  const inv = read(name)
  check(inv.format === 'contentrain-bridge-inventory@2', `${name}: format`)
  check(inventoryHash(inv.records) === inv.inventory_hash, `${name}: hash recomputes in JavaScript`)
  check(inv.records.every((r) => typeof r.wp_type === 'string' && Number.isInteger(r.wp_id) && /^[0-9a-f]{64}$/.test(r.fingerprint)), `${name}: every record has wp_type, wp_id, fingerprint`)
}

const OPS = new Set(['created', 'updated', 'deleted', 'moved'])
const KINDS = new Set(['bridge_inventory', 'rest_modified_after', 'wxr_export'])
for (const name of ['delta.json', 'delivered-delta.json', 'rest-only.json', 'scope-lost.json', 'refused.json']) {
  const plan = read(name)
  check(plan.version === 1 && typeof plan.generated_at === 'string' && typeof plan.deletions_detectable === 'boolean', `${name}: plan header`)
  check(KINDS.has(plan.cursor.kind) && typeof plan.cursor.taken_at === 'string', `${name}: cursor`)
  check(plan.entries.every((e) => OPS.has(e.op) && Number.isInteger(e.wp_id) && typeof e.wp_type === 'string' && e.wp_type !== ''), `${name}: entries carry op, wp_id and wp_type`)
  check(plan.entries.every((e) => e.model === undefined && e.entry_id === undefined && e.conflict === undefined) && plan.redirects === undefined, `${name}: store-side fields left to the planner`)
  check(plan.entries.every((e) => e.op !== 'deleted' || ['trashed', 'purged'].includes(e.deleted_kind)), `${name}: every deletion is trashed or purged`)
}
const t0 = read('inventory-t0.json')
const t1 = read('inventory-t1.json')
const delta = read('delta.json')
check(delta.cursor.inventory_hash === t0.inventory_hash && delta.next_cursor.inventory_hash === t1.inventory_hash, 'delta.json: cursor T0 -> next_cursor T1')
const ids = (plan) => plan.entries.map((e) => `${e.wp_type}:${e.wp_id}:${e.op}${e.deleted_kind ? '/' + e.deleted_kind : ''}`).sort()
check(JSON.stringify(ids(delta)) === JSON.stringify(read('expected.json')), 'delta.json: exactly the expected entries')
check(JSON.stringify(ids(read('delivered-delta.json'))) === JSON.stringify(ids(delta)), 'delivered delta equals the standalone delta')
check(read('rest-only.json').deletions_detectable === false && read('refused.json').deletions_detectable === false, 'REST-only and refused plans never claim detectable deletions')
console.log(`\n${checks} contract checks passed.`)

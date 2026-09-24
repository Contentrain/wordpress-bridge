// BR-23: an export driven the way Migrate drives it (application password, POST
// /exports, advance until ready, the snapshot read in 8 MiB chunks), timed per phase.
//
//   node tests/perf/export-timing.mjs <origin> <user> <app-password> [report.json]
import { writeFileSync } from 'node:fs'

const [origin, user, password, reportFile] = process.argv.slice(2)
const auth = 'Basic ' + Buffer.from(`${user}:${password}`).toString('base64')
const base = `${origin.replace(/\/$/, '')}/wp-json/contentrain-bridge/v1/exports`
const call = async (url, method = 'GET', body) => {
  const started = performance.now()
  const response = await fetch(url, { method, headers: { authorization: auth, ...(body ? { 'content-type': 'application/json' } : {}) }, ...(body ? { body: JSON.stringify(body) } : {}) })
  const text = await response.text()
  const ms = performance.now() - started
  if (!response.ok) throw new Error(`${method} ${url} → HTTP ${response.status}: ${text.slice(0, 300)}`)
  return { json: JSON.parse(text), ms }
}

const t0 = performance.now()
const { json: started } = await call(base, 'POST', { comments: true, media_files: false, fresh: true })
let exp = started.export
const phases = {}
let calls = 0
let busy = 0
let lastPhase = exp.phase
let phaseStart = performance.now()
let phaseSteps = exp.step
while (!['ready', 'failed'].includes(exp.phase)) {
  const { json, ms } = await call(`${base}/${exp.id}/advance`, 'POST')
  calls++
  if (json.busy) busy++
  const next = json.export
  if (next.phase !== lastPhase) {
    phases[lastPhase] = { seconds: (performance.now() - phaseStart) / 1000, steps: next.step - phaseSteps }
    // A phase that began and ended inside one call is attributed to that call.
    lastPhase = next.phase
    phaseStart = performance.now()
    phaseSteps = next.step
  }
  process.stdout.write(`advance #${calls}: ${ms.toFixed(0)} ms → ${next.phase} step ${next.step} posts ${next.counts.posts} comments ${next.counts.comments}\n`)
  exp = next
}
const exportSeconds = (performance.now() - t0) / 1000
if (exp.phase === 'failed') throw new Error('export failed: ' + JSON.stringify(exp.error))

// The snapshot read as Migrate reads it: the file list, then rawir.json in 8 MiB chunks.
const readStart = performance.now()
const { json: list } = await call(`${base}/${exp.id}`)
const files = Object.entries(list.files)
const rawir = list.files['bridge/rawir.json']
const chunk = 8 * 1024 * 1024
let chunks = 0
for (let offset = 0; offset < rawir.bytes || (rawir.bytes === 0 && chunks === 0); offset += chunk) {
  await call(`${base}/${exp.id}?file=${encodeURIComponent('bridge/rawir.json')}&offset=${offset}&length=${Math.min(chunk, rawir.bytes - offset)}`)
  chunks++
}
const readSeconds = (performance.now() - readStart) / 1000

const report = {
  memory_limit: process.env.MEMORY ?? null,
  limit_seconds: Number(process.env.LIMIT ?? 1800),
  export_seconds: Math.round(exportSeconds * 10) / 10,
  advance_calls: calls,
  busy_answers: busy,
  steps: exp.step,
  phases: Object.fromEntries(Object.entries(phases).map(([k, v]) => [k, { seconds: Math.round(v.seconds * 10) / 10, steps: v.steps }])),
  counts: exp.counts,
  files: files.length,
  bytes: files.reduce((n, [, f]) => n + f.bytes, 0),
  rawir_bytes: rawir.bytes,
  rawir_read: { chunks, seconds: Math.round(readSeconds * 10) / 10 },
}
console.log(JSON.stringify(report, null, 2))
if (reportFile) writeFileSync(reportFile, JSON.stringify(report, null, 2) + '\n')
if (report.export_seconds > report.limit_seconds) {
  console.error(`FAIL: the export took ${report.export_seconds} s, over the ${report.limit_seconds} s a reader waits`)
  process.exit(1)
}
console.log(`PASS: exported in ${report.export_seconds} s (limit ${report.limit_seconds} s) at memory_limit ${report.memory_limit}`)

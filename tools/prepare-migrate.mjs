// Verified Bridge snapshot -> Migrate intake cache. No WordPress or GitHub requests.
import { createHash, randomUUID } from 'node:crypto'
import { copyFileSync, existsSync, lstatSync, mkdirSync, readFileSync, realpathSync, renameSync, rmSync, writeFileSync } from 'node:fs'
import { dirname, join, resolve, sep } from 'node:path'
import { pathToFileURL } from 'node:url'
import { buildCommentsExport, summarizeComments } from '@contentrain/wp-import'

export function prepareMigrate(source, destination) {
  const root = realpathSync(source)
  const target = resolve(destination)
  if (existsSync(target)) throw new Error('Destination already exists; refusing to replace an intake cache')
  const manifest = JSON.parse(readFileSync(join(root, 'bridge/manifest.json'), 'utf8'))
  if (manifest.format !== 'contentrain-bridge@1' || !manifest.files || !/^[a-f0-9]{32}$/.test(manifest.snapshot)) throw new Error('Unsupported Bridge manifest')
  const verified = new Map()
  for (const [path, info] of Object.entries(manifest.files)) {
    if (!/^(?:\.contentrain\/|bridge\/|media\/|CONTENTRAIN-EXPORT\.md$)/.test(path) || path.includes('\\') || path.split('/').some(p => p === '..' || p === '.' || p === '')) throw new Error('Unsafe snapshot path')
    const file = join(root, path)
    if (lstatSync(file).isSymbolicLink() || !realpathSync(file).startsWith(root + sep)) throw new Error('Snapshot symlink escapes its root')
    if (!Number.isSafeInteger(info.bytes) || info.bytes < 0 || info.bytes > 64 * 1024 * 1024 || lstatSync(file).size !== info.bytes) throw new Error('Invalid snapshot file size: ' + path)
    const bytes = readFileSync(file)
    if (createHash('sha256').update(bytes).digest('hex') !== info.sha256) throw new Error('Snapshot hash mismatch: ' + path)
    verified.set(path, file)
  }
  const json = (path, fallback) => {
    if (!verified.has(path)) {
      if (fallback !== undefined) return fallback
      throw new Error('Snapshot missing required file: ' + path)
    }
    return JSON.parse(readFileSync(verified.get(path), 'utf8'))
  }
  const values = path => Object.values(json(path, {}))
  const config = json('.contentrain/config.json')
  const entries = json('bridge/entry-source-map.json')
  const raw = {
    version: 1,
    provenance: { kind: 'bridge', fetched_at: manifest.created_at, tool: 'contentrain-bridge/' + manifest.version },
    site: json('bridge/site.json'),
    posts: values('bridge/raw-posts.json'), authors: values('bridge/raw-authors.json'),
    terms: values('bridge/raw-terms.json'), attachments: values('bridge/raw-attachments.json'),
    comments: values('bridge/raw-comments.json'), menus: json('bridge/raw-menus.json', []),
    language_pairs: values('bridge/language-pairs.json'), options: json('bridge/options.json', {}),
    // RawIR.redirects is RawRedirect[]: what the site serves. Excluded rules stay in bridge/redirects.json.
    redirects: json('bridge/redirects.json', { redirects: [] }).redirects,
  }
  // Not yet RawIR fields (proposed in the B-04 contract); carried beside it rather than dropped.
  const seo = json('bridge/seo.json', null)
  if (seo) raw.seo = { ...seo, entries: json('bridge/seo-entries.json', {}) }
  const routing = json('bridge/routing.json', null)
  if (routing) raw.routing = routing
  // B-08: every interface-text candidate with its one outcome; not yet a RawIR field (proposed).
  // B-07: services to reconnect; not yet a RawIR field (proposed).
  const integrations = json('bridge/integrations.json', null)
  if (integrations) raw.integrations = integrations.services
  const text = json('bridge/hardcoded-text.json', null)
  if (text) raw.hardcoded_text = text
  for (const post of raw.posts) {
    const entry = entries[String(post.id)]
    if (!entry || !verified.has(`.contentrain/models/${entry.model_id}.json`)) throw new Error('Post has no exported model address: ' + post.id)
  }
  const comments = buildCommentsExport(raw, entries, { generated_at: manifest.created_at })
  const commentSummary = summarizeComments(comments)
  const models = [...verified.keys()].filter(p => p.startsWith('.contentrain/models/') && p.endsWith('.json')).map(p => json(p))
  const report = { source: raw.provenance, models: Object.fromEntries(models.map(m => [m.id, { kind: m.kind, domain: m.domain, fields: Object.keys(m.fields ?? {}).length }])), bridge_snapshot: manifest.snapshot }
  const warnings = values('bridge/warnings.json')
  const summary = { site: raw.site.url, generated_at: manifest.created_at, tool: 'contentrain-bridge', provenance: raw.provenance, locale: config.locales.default, langs: config.locales.supported, models: models.length, entries: Object.keys(entries).length, posts: raw.posts.length, attachments: raw.attachments.length, comments: commentSummary, truncated: [], warnings: warnings.map(w => `${w.source}: ${w.reason}`), requests: 0, ms: 0, bridge_snapshot: manifest.snapshot, complete_source_coverage: manifest.complete_source_coverage === true }
  // Open items for the person running the migration, in Studio's handoff-issue shape ({ code, … }).
  const reconnect = (raw.integrations ?? []).filter((s) => s.reconnect_required)
  summary.issues = reconnect.length ? [{ code: 'integration_reconnect_required', services: reconnect.map((s) => ({ service: s.service, name: s.name, category: s.category, secret_present: s.secret_present })) }] : []
  const staging = target + '.tmp-' + randomUUID()
  const write = (path, content) => { const file = join(staging, path); mkdirSync(dirname(file), { recursive: true }); writeFileSync(file, typeof content === 'string' ? content : JSON.stringify(content, null, 2) + '\n') }
  try {
    mkdirSync(staging, { recursive: true })
    for (const [path, file] of verified) {
      const output = path.startsWith('.contentrain/') ? 'store/' + path : path.startsWith('media/') ? 'public/' + path : path
      mkdirSync(dirname(join(staging, output)), { recursive: true }); copyFileSync(file, join(staging, output))
    }
    write('rawir.json', raw)
    write('entry-source-map.json', entries)
    write('comments-export.json', comments)
    write('import-report.json', report)
    write('intake-summary.json', summary)
    renameSync(staging, target)
    return summary
  } catch (error) { rmSync(staging, { recursive: true, force: true }); throw error }
}

if (process.argv[1] && import.meta.url === pathToFileURL(resolve(process.argv[1])).href) {
  if (process.argv.length !== 4) { console.error('Usage: node tools/prepare-migrate.mjs <unpacked-export> <new-intake-directory>'); process.exitCode = 1 }
  else { try { console.log(JSON.stringify(prepareMigrate(process.argv[2], process.argv[3]), null, 2)) } catch (error) { console.error(error.message); process.exitCode = 1 } }
}

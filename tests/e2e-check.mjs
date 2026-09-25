// B-11, consumer side: build the delivered store with Astro through
// @contentrain/query's loader, and check what came out against what went in.
//
//   node tests/e2e-check.mjs build <storeDir> <outDir>        one page per entry, relations, media, menu, lang, redirects
//   node tests/e2e-check.mjs diff <t0Dir> <t1Dir> <mutation.json>   the second delivery changes only the mutated records
import { execFileSync } from 'node:child_process'
import { cpSync, existsSync, mkdirSync, readdirSync, readFileSync, rmSync, statSync } from 'node:fs'
import { dirname, join, relative } from 'node:path'
import { fileURLToPath } from 'node:url'

const here = dirname(fileURLToPath(import.meta.url))
let checks = 0
const check = (ok, message) => {
  if (!ok) throw new Error('FAIL: ' + message)
  checks++
  console.log('PASS: ' + message)
}
const json = (path) => JSON.parse(readFileSync(path, 'utf8'))
const walk = (dir) => (existsSync(dir) ? readdirSync(dir).flatMap((n) => (statSync(join(dir, n)).isDirectory() ? walk(join(dir, n)) : [join(dir, n)])) : [])

/** Entries per model, read from the store the way Contentrain lays it out. */
function entries(store) {
  const config = json(join(store, '.contentrain/config.json'))
  const out = {}
  for (const file of readdirSync(join(store, '.contentrain/models')).filter((f) => f.endsWith('.json'))) {
    const model = json(join(store, '.contentrain/models', file))
    const dir = join(store, '.contentrain/content', model.domain, model.id)
    if (model.kind === 'singleton') out[model.id] = 1
    else if (model.kind === 'document') out[model.id] = walk(dir).filter((f) => f.endsWith('.md')).length
    else {
      const files = model.i18n ? config.locales.supported.map((l) => join(dir, l + '.json')) : [join(dir, 'data.json')]
      out[model.id] = files.filter(existsSync).reduce((n, f) => n + Object.keys(json(f)).length, 0)
    }
  }
  return out
}

function build(store, out) {
  // Media travels in the store under media/; Astro serves it from publicDir.
  const pub = join(store, 'media-public')
  rmSync(pub, { recursive: true, force: true })
  mkdirSync(pub, { recursive: true })
  if (existsSync(join(store, 'media'))) cpSync(join(store, 'media'), join(pub, 'media'), { recursive: true })
  rmSync(out, { recursive: true, force: true })
  execFileSync(join(here, 'e2e-astro/node_modules/.bin/astro'), ['build', '--outDir', out], { cwd: join(here, 'e2e-astro'), env: { ...process.env, CONTENTRAIN_STORE: store, ASTRO_TELEMETRY_DISABLED: '1' }, stdio: ['ignore', 'pipe', 'inherit'] })
  rmSync(pub, { recursive: true, force: true })
}

function page(out, model, id) {
  const file = join(out, model, id, 'index.html')
  return existsSync(file) ? readFileSync(file, 'utf8') : null
}

const [mode, ...args] = process.argv.slice(2)

if (mode === 'build') {
  const [store, out] = args
  build(store, out)
  check(true, 'astro build completes over the delivered store')
  const expected = entries(store)
  const total = Object.values(expected).reduce((a, b) => a + b, 0)
  let built = 0
  for (const [model, count] of Object.entries(expected)) {
    const pages = walk(join(out, model)).filter((f) => f.endsWith('index.html')).length
    if (pages !== count) console.log(`  ${model}: ${count} entries, ${pages} pages`)
    built += pages
  }
  check(built === total, `one page per delivered entry: ${built} pages = ${total} entries across ${Object.keys(expected).length} models`)
  const map = json(join(store, 'bridge/entry-source-map.json'))
  const fixture = json(join(dirname(store), 'fixture.json'))
  const post = map[String(fixture.edit)]
  const html = page(out, post.model_id, post.entry_id)
  check(html && html.includes('data-relation="author"') && html.includes('bridge-admin'), 'the post page resolves its author relation')
  check(/data-relation="terms_category">[^<]*E2E Guides/.test(html) && /data-relation="terms_post_tag">[^<]*e2e-tag/.test(html), 'category and tag relations resolve to their names')
  check(/data-media>\/?media\/[^<]+e2e-cover-[a-f0-9]+\.png</.test(html) && walk(join(out, 'media')).some((f) => /e2e-cover-.*\.png$/.test(f)), 'the featured image is a transferred file the site serves')
  const guide = map[String(fixture.page)]
  const guideHtml = page(out, guide.model_id, guide.entry_id)
  check(guideHtml && /data-list="acf_e2e_steps">Export, Build</.test(guideHtml), 'the ACF repeater rows are in the page, in order')
  const menu = walk(join(out, 'wp-menu-items')).map((f) => readFileSync(f, 'utf8'))
  check(['Guide', 'Guides', 'Contentrain'].every((t) => menu.some((h) => h.includes(`<h1>${t}</h1>`))) && menu.some((h) => h.includes('https://contentrain.io/')), 'the menu items are pages with their titles and targets')
  const lang = json(join(store, '.contentrain/config.json')).locales.default
  check(html.includes(`<html lang="${lang}"`), `pages carry the store's language (${lang})`)
  const rules = json(join(store, 'bridge/redirects.json')).redirects
  const plain = rules.filter((r) => !r.regex && r.match === 'url' && r.from.startsWith('/') && r.to.startsWith('/') && r.from !== r.to)
  const missing = plain.filter((r) => {
    const f = join(out, r.from.replace(/^\/|\/$/g, ''), 'index.html')
    return !existsSync(f) || !readFileSync(f, 'utf8').includes(r.to)
  })
  check(plain.length >= 2 && !missing.length, `${plain.length} of ${rules.length} served redirects become Astro redirects (${rules.length - plain.length} external/pattern rules left to the host)`)
  console.log(`\n${checks} build checks passed. ${built} pages.`)
} else if (mode === 'diff') {
  const [t0, t1, mutationFile] = args
  const mutation = json(mutationFile)
  const map0 = json(join(t0, 'bridge/entry-source-map.json'))
  const ids = [mutation.updated, mutation.moved, mutation.trashed].map(String)
  const expected = new Set(ids.map((id) => `${map0[id].model_id}/${map0[id].entry_id}`))
  // What Git sees: a repository at T0, the T1 delivery committed on top.
  const repo = join(dirname(t0), 'repo')
  rmSync(repo, { recursive: true, force: true })
  mkdirSync(repo)
  const git = (...a) => execFileSync('git', a, { cwd: repo, encoding: 'utf8', env: { ...process.env, GIT_AUTHOR_NAME: 'e2e', GIT_AUTHOR_EMAIL: 'e2e@example.test', GIT_COMMITTER_NAME: 'e2e', GIT_COMMITTER_EMAIL: 'e2e@example.test' } })
  git('init', '-q')
  cpSync(t0, repo, { recursive: true })
  git('add', '-A'); git('commit', '-qm', 'T0')
  for (const f of walk(repo).filter((f) => !f.includes('/.git/'))) rmSync(f)
  cpSync(t1, repo, { recursive: true })
  git('add', '-A'); git('commit', '-qm', 'T1')
  const changes = git('diff', '--name-status', 'HEAD~1', 'HEAD').trim().split('\n').filter(Boolean).map((l) => l.split('\t'))
  const content = changes.filter(([, p]) => p.startsWith('.contentrain/'))
  const removed = changes.filter(([s]) => s === 'D').map(([, p]) => p)
  // Entry-level: a document file is one entry; a collection file holds many, compared key by key.
  const touched = new Set()
  for (const [, path] of content) {
    const doc = path.match(/^\.contentrain\/(?:content\/[^/]+|meta)\/([^/]+)\/([^/]+?)(?:\/[^/]+)?\.(md|json)$/)
    const before = existsSync(join(t0, path)) ? readFileSync(join(t0, path), 'utf8') : null
    const after = existsSync(join(t1, path)) ? readFileSync(join(t1, path), 'utf8') : null
    if (path.endsWith('.md') || (doc && /\.contentrain\/meta\/[^/]+\/[^/]+\/[^/]+\.json$/.test(path))) touched.add(`${doc[1]}/${doc[2]}`)
    else {
      const a = before ? JSON.parse(before) : {}
      const b = after ? JSON.parse(after) : {}
      const model = path.split('/').at(-2)
      for (const key of new Set([...Object.keys(a), ...Object.keys(b)])) if (JSON.stringify(a[key]) !== JSON.stringify(b[key])) touched.add(`${model}/${key}`)
    }
  }
  console.log('changed content entries: ' + JSON.stringify([...touched].sort()))
  check([...touched].every((e) => expected.has(e)) && [...expected].every((e) => touched.has(e)), `Git diff touches exactly the ${expected.size} mutated entries in .contentrain (${content.length} files)`)
  const trashed = map0[String(mutation.trashed)]
  check(removed.some((p) => p.includes(trashed.entry_id)), 'the trashed post leaves the store: its files are removed in Git')
  const raw = (dir) => json(join(dir, 'bridge/raw-posts.json'))
  const r0 = raw(t0), r1 = raw(t1)
  const rawTouched = [...new Set([...Object.keys(r0), ...Object.keys(r1)])].filter((k) => JSON.stringify(r0[k]) !== JSON.stringify(r1[k])).sort()
  check(JSON.stringify(rawTouched) === JSON.stringify([...ids].sort()), `raw posts differ only for the mutated WordPress ids (${rawTouched.join(', ')})`)
  const delta = json(join(dirname(t1), 't1.delta.json'))
  const ops = delta.entries.filter((e) => e.wp_type === 'post').map((e) => `${e.wp_id}:${e.op}`).sort()
  check(JSON.stringify(ops) === JSON.stringify([`${mutation.updated}:updated`, `${mutation.moved}:moved`, `${mutation.trashed}:deleted`].sort()), `the B-06 delta names the same three records: ${ops.join(', ')}`)
  const redirects = json(join(t1, 'bridge/redirects.json')).redirects
  check(redirects.some((r) => r.source === 'wordpress' && r.to.includes(mutation.new_slug)), 'the slug change arrives as a WordPress old-slug redirect to the new address')
  console.log(`\n${checks} diff checks passed. Bridge bookkeeping files changed: ${changes.filter(([, p]) => p.startsWith('bridge/')).length}; removed files: ${removed.length}`)
} else if (mode === 'rebuilt') {
  const [store, out, mutationFile, t0] = args
  const mutation = json(mutationFile)
  const map0 = json(join(t0, 'bridge/entry-source-map.json'))
  const edited = map0[String(mutation.updated)]
  const moved = map0[String(mutation.moved)]
  const trashed = map0[String(mutation.trashed)]
  check((page(out, edited.model_id, edited.entry_id) ?? '').includes(mutation.new_text), 'the edited body reaches its own page after the rebuild')
  check((page(out, moved.model_id, moved.entry_id) ?? '').includes(`data-slug>${mutation.new_slug}<`), 'the renamed post keeps its entry page and shows the new slug')
  check(page(out, trashed.model_id, trashed.entry_id) === null, 'the trashed post has no page')
  const expected = entries(store)
  const total = Object.values(expected).reduce((a, b) => a + b, 0)
  const built = Object.keys(expected).reduce((n, m) => n + walk(join(out, m)).filter((f) => f.endsWith('index.html')).length, 0)
  check(built === total, `one page per entry after the delta: ${built} = ${total}`)
  console.log(`\n${checks} rebuild checks passed.`)
} else {
  throw new Error('Usage: e2e-check.mjs build|diff|rebuilt …')
}

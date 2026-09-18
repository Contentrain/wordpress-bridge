// The consumer side of the chain: a store Bridge delivered, read by
// @contentrain/query's Astro loader. CONTENTRAIN_STORE points at the checkout.
import { defineConfig } from 'astro/config'
import { existsSync, readFileSync } from 'node:fs'
import { join } from 'node:path'

const store = process.env.CONTENTRAIN_STORE
if (!store || !existsSync(join(store, '.contentrain/config.json'))) throw new Error('Set CONTENTRAIN_STORE to a delivered Contentrain store')

// Bridge's served, plain redirects become Astro's. A regex or prefix rule is
// not a from→to pair, and an external target is left to the host; both are
// counted by the e2e check, not dropped silently.
const file = join(store, 'bridge/redirects.json')
const rules = existsSync(file) ? JSON.parse(readFileSync(file, 'utf8')).redirects : []
const redirects = Object.fromEntries(
  rules
    .filter((r) => !r.regex && r.match === 'url' && r.from.startsWith('/') && r.to.startsWith('/') && r.from !== r.to)
    .map((r) => [r.from.replace(/\/$/, '') || '/', { status: r.status, destination: r.to }]),
)

export default defineConfig({
  output: 'static',
  trailingSlash: 'ignore',
  redirects,
  publicDir: join(store, 'media-public'),
})

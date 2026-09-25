import { test } from 'node:test'
import assert from 'node:assert/strict'
import { cpSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { prepareMigrate } from '../tools/prepare-migrate.mjs'

test('Bridge intake preserves source identities and rejects tampering', () => {
  const root = mkdtempSync(join(tmpdir(), 'bridge-intake-test-'))
  try {
    const source = join(root, 'source')
    cpSync(new URL('./.out/store', import.meta.url), source, { recursive: true })
    const target = join(root, 'intake')
    const summary = prepareMigrate(source, target)
    assert.equal(summary.provenance.kind, 'bridge')
    const entries = JSON.parse(readFileSync(join(source, 'bridge/entry-source-map.json')))
    assert.deepEqual(JSON.parse(readFileSync(join(target, 'entry-source-map.json'))), entries)
    assert.deepEqual(JSON.parse(readFileSync(join(target, 'comments-export.json'))).entries, entries)
    assert.deepEqual(readFileSync(join(target, 'store/.contentrain/config.json')), readFileSync(join(source, '.contentrain/config.json')))
    // B-04: redirects are RawIR's own field; SEO and routing ride beside it until types adopts them.
    const raw = JSON.parse(readFileSync(join(target, 'rawir.json')))
    const redirects = JSON.parse(readFileSync(join(source, 'bridge/redirects.json')))
    assert.deepEqual(raw.redirects, redirects.redirects)
    assert.deepEqual(raw.redirects_excluded, redirects.excluded)
    assert.equal(raw.seo.format, 'contentrain-bridge-seo@1')
    assert.equal(raw.routing.format, 'contentrain-bridge-routing@1')
    assert.throws(() => prepareMigrate(source, target), /already exists/)
    writeFileSync(join(source, '.contentrain/config.json'), '{}')
    assert.throws(() => prepareMigrate(source, join(root, 'tampered')), /size|hash/)
  } finally { rmSync(root, { recursive: true, force: true }) }
})

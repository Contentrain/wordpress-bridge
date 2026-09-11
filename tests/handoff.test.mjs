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
    assert.throws(() => prepareMigrate(source, target), /already exists/)
    writeFileSync(join(source, '.contentrain/config.json'), '{}')
    assert.throws(() => prepareMigrate(source, join(root, 'tampered')), /size|hash/)
  } finally { rmSync(root, { recursive: true, force: true }) }
})

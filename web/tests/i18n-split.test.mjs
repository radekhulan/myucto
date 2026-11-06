import { test } from 'node:test'
import assert from 'node:assert/strict'
import { mkdtempSync, mkdirSync, writeFileSync, readFileSync, rmSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import i18nSplit from '../plugins/i18n-split.mjs'

function fixture(t) {
  t.mock.timers.enable({ apis: ['setTimeout'] })
  const directory = mkdtempSync(join(tmpdir(), 'myucto-i18n-hmr-'))
  t.after(() => rmSync(directory, { recursive: true, force: true }))
  const srcDir = join(directory, 'src')
  const i18nDir = join(srcDir, 'i18n')
  mkdirSync(i18nDir, { recursive: true })
  writeFileSync(join(i18nDir, 'namespaces.generated.json'), JSON.stringify({ core: ['common'] }))
  const messages = title => JSON.stringify({ common: { ready: 'Ready' }, client_mcp: { title } })
  for (const locale of ['cs', 'en']) {
    writeFileSync(join(i18nDir, `${locale}.json`), messages('MCP'))
  }
  const plugin = i18nSplit({ srcDir })
  const sent = []
  const errors = []
  let changed
  plugin.buildStart()
  plugin.configureServer({
    watcher: { add() {}, on(event, callback) { assert.equal(event, 'change'); changed = callback } },
    ws: { send(message) { sent.push(message) } },
    config: { logger: { error(message) { errors.push(message) } } },
  })
  return {
    sent, errors, messages,
    file: locale => join(i18nDir, `${locale}.json`),
    change: file => changed(file),
    chunk: locale => JSON.parse(readFileSync(join(i18nDir, 'chunks', locale, 'client_mcp.json'), 'utf8')),
  }
}

for (const locale of ['cs', 'en']) {
  test(`updated ${locale} messages regenerate chunks and refresh already loaded translations`, t => {
    const f = fixture(t)
    writeFileSync(f.file(locale), f.messages('Updated hint'))
    f.change(f.file(locale))
    assert.equal(f.chunk(locale).client_mcp.title, 'MCP')
    t.mock.timers.tick(50)
    assert.equal(f.chunk(locale).client_mcp.title, 'Updated hint')
    assert.deepEqual(f.sent, [{ type: 'full-reload' }])
    f.change(join(tmpdir(), 'unrelated.vue'))
    t.mock.timers.tick(1000)
    assert.equal(f.sent.length, 1)
    assert.deepEqual(f.errors, [])
  })

  test(`incomplete ${locale} write preserves both locales and retries the completed file`, t => {
    const f = fixture(t)
    writeFileSync(f.file(locale), '{"client_mcp":')
    f.change(f.file(locale))
    assert.doesNotThrow(() => t.mock.timers.tick(50))
    for (const language of ['cs', 'en']) assert.equal(f.chunk(language).client_mcp.title, 'MCP')
    assert.deepEqual(f.sent, [])
    assert.deepEqual(f.errors, [])
    // Dokončení nemusí vyvolat další událost watcheru; omezený retry je přesto zachytí.
    writeFileSync(f.file(locale), f.messages('Completed write'))
    t.mock.timers.tick(100)
    assert.equal(f.chunk(locale).client_mcp.title, 'Completed write')
    assert.deepEqual(f.sent, [{ type: 'full-reload' }])
    assert.deepEqual(f.errors, [])
  })

  test(`permanent invalid ${locale} JSON is reported once and a later valid change recovers`, t => {
    const f = fixture(t)
    writeFileSync(f.file(locale), '{')
    f.change(f.file(locale))
    t.mock.timers.tick(50)
    for (let retry = 0; retry < 3; retry++) t.mock.timers.tick(100)
    assert.equal(f.errors.length, 1)
    assert.match(f.errors[0], /myucto-i18n-split/)
    t.mock.timers.tick(1000)
    assert.equal(f.errors.length, 1)
    assert.deepEqual(f.sent, [])
    assert.equal(f.chunk(locale).client_mcp.title, 'MCP')
    writeFileSync(f.file(locale), f.messages('Recovered'))
    f.change(f.file(locale))
    t.mock.timers.tick(50)
    assert.equal(f.chunk(locale).client_mcp.title, 'Recovered')
    assert.deepEqual(f.sent, [{ type: 'full-reload' }])
  })
}

test('rapid writes to both locales coalesce into a single regeneration and reload', t => {
  const f = fixture(t)
  writeFileSync(f.file('cs'), '')
  f.change(f.file('cs'))
  t.mock.timers.tick(25)
  writeFileSync(f.file('cs'), f.messages('Česky'))
  writeFileSync(f.file('en'), f.messages('English'))
  f.change(f.file('cs'))
  f.change(f.file('en'))
  t.mock.timers.tick(25)
  assert.deepEqual(f.sent, [])
  t.mock.timers.tick(25)
  assert.equal(f.chunk('cs').client_mcp.title, 'Česky')
  assert.equal(f.chunk('en').client_mcp.title, 'English')
  assert.deepEqual(f.sent, [{ type: 'full-reload' }])
  assert.deepEqual(f.errors, [])
})

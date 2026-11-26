import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import test from 'node:test';

import { TOOLS } from '../src/tools.mjs';

function run(input, env = process.env) {
  const child = spawnSync(process.execPath, ['src/hosted-bridge.mjs'], {
    cwd: new URL('..', import.meta.url),
    input: JSON.stringify(input),
    encoding: 'utf8',
    env,
  });
  assert.equal(child.status, 0, child.stderr);
  return JSON.parse(child.stdout);
}

const list = (scope, boundSupplierId = null) => run({ operation: 'list', scope, boundSupplierId });

test('hostovaný katalog vrací všechny povolené nástroje v jedné odpovědi', () => {
  for (const scope of ['read', 'read_write']) {
    const page = list(scope);
    const names = page.tools.map((tool) => tool.name);
    const expected = TOOLS.filter((tool) => scope === 'read_write' || !tool.write).map((tool) => tool.name);
    assert.deepEqual(names, expected);
    assert.equal(page.nextCursor, undefined);
    assert.equal(new Set(names).size, names.length);
  }
});

test('hostovaný katalog nabízí dotazy do registrů pouze s rozsahem čtení a zápis', () => {
  const read = list('read');
  const readWrite = list('read_write');
  for (const name of ['lookup_vat_id_in_vies', 'lookup_vat_registry_bank_accounts']) {
    assert.equal(read.tools.some((tool) => tool.name === name), false);
    const lookup = readWrite.tools.find((tool) => tool.name === name);
    assert.ok(lookup);
    assert.equal(lookup.annotations.readOnlyHint, true);
    assert.match(lookup.description, /Čtení a zápis/);
  }
});

test('interní PHP požadavky nepoužívají OPcache file cache hostitele', (t) => {
  const php = process.env.MYINVOICE_MCP_PHP_BINARY || 'php';
  if (spawnSync(php, ['-v']).error) return t.skip('PHP CLI není dostupné.');
  const directory = mkdtempSync(join(tmpdir(), 'myucto-mcp-opcache-'));
  try {
    writeFileSync(join(directory, 'php.ini'), `opcache.file_cache="${directory.replaceAll('\\', '/')}"\n`);
    const env = { ...process.env, PHPRC: directory, PHP_INI_SCAN_DIR: '' };
    const configured = spawnSync(php, ['-r', "echo get_cfg_var('opcache.file_cache');"], { env, encoding: 'utf8' });
    assert.equal(configured.status, 0, configured.stderr);
    assert.ok(configured.stdout.length > 0);
    const apiScript = join(directory, 'api.php');
    writeFileSync(apiScript, `<?php
      $input = json_decode(stream_get_contents(STDIN), true);
      echo json_encode(['status' => 200, 'headers' => ['Content-Type' => 'application/json'],
        'body' => json_encode(['cache_disabled' => get_cfg_var('opcache.file_cache') === '',
          'method' => $input['method']])]);
    `);
    const result = run({ operation: 'call', scope: 'read', name: 'whoami',
      arguments: {}, phpBinary: php, apiScript, apiUrl: 'https://example.test/api/v1', token: 'synthetic-token' }, env);
    assert.equal(result.isError, undefined);
    assert.deepEqual(result.structuredContent, { cache_disabled: true, method: 'GET' });
  } finally {
    rmSync(directory, { recursive: true, force: true });
  }
});

test('nevázaný grant vyžaduje firmu u datových nástrojů', () => {
  const tools = list('read').tools;
  assert.equal(tools.find((tool) => tool.name === 'list_suppliers').inputSchema.properties.supplier_id, undefined);
  assert.equal(tools.find((tool) => tool.name === 'whoami').inputSchema.properties.supplier_id, undefined);
  for (const tool of tools.filter((item) => !['list_suppliers', 'whoami'].includes(item.name))) {
    assert.equal(tool.inputSchema.properties.supplier_id.type, 'integer', tool.name);
    assert.ok(tool.inputSchema.required.includes('supplier_id'), tool.name);
  }
  const missing = run({ operation: 'call', scope: 'read', name: 'list_invoices', arguments: {} });
  assert.equal(missing.isError, true);
  assert.match(missing.content[0].text, /supplier_id/);
});

test('starší grant zůstává vázaný na původní firmu', () => {
  const schema = list('read', 7).tools.find((tool) => tool.name === 'list_invoices').inputSchema;
  assert.equal(schema.properties.supplier_id, undefined);
  const mismatch = run({
    operation: 'call', scope: 'read', boundSupplierId: 7,
    name: 'list_invoices', arguments: { supplier_id: 8 },
  });
  assert.equal(mismatch.isError, true);
  assert.match(mismatch.content[0].text, /původně schválenou firmu/);
});

test('doména firmy nepovolí volání nástroje pro jinou firmu', () => {
  const mismatch = run({
    operation: 'call', scope: 'read', lockedSupplierId: 7,
    name: 'list_invoices', arguments: { supplier_id: 8 },
  });
  assert.equal(mismatch.isError, true);
  assert.match(mismatch.content[0].text, /doména je omezená na jinou firmu/);
});

test('most s vlastním PHP předá nahrávaný soubor a stažené PDF v base64', (t) => {
  const php = process.env.MYINVOICE_MCP_PHP_BINARY || 'php';
  if (spawnSync(php, ['-v']).error) return t.skip('PHP CLI není dostupné.');
  const directory = mkdtempSync(join(tmpdir(), 'myucto-mcp-files-'));
  try {
    const apiScript = join(directory, 'api.php');
    writeFileSync(apiScript, `<?php
      $input = json_decode(stream_get_contents(STDIN), true);
      if ($input['method'] === 'GET') {
        echo json_encode(['status' => 200, 'headers' => ['Content-Type' => 'application/pdf',
          'Content-Disposition' => 'attachment; filename="F-1.pdf"'], 'body' => '',
          'bodyBase64' => base64_encode("%PDF-\\x00\\xff")]);
        return;
      }
      $body = base64_decode($input['bodyBase64'], true);
      echo json_encode(['status' => 200, 'headers' => ['Content-Type' => 'application/json'],
        'body' => json_encode(['has_pdf' => str_contains($body, "%PDF-\\x00\\xff"),
          'multipart' => str_starts_with($input['headers']['Content-Type'], 'multipart/form-data; boundary=')])]);
    `);
    const base = { scope: 'read_write', operation: 'call', phpBinary: php, apiScript,
      apiUrl: 'https://example.test/api/v1', token: 'synthetic-token', boundSupplierId: 1 };
    const download = run({ ...base, name: 'download_invoice_pdf', arguments: { id: 1, format: 'base64' } });
    assert.equal(download.isError, undefined, JSON.stringify(download));
    assert.equal(JSON.parse(download.content[0].text).content_base64, Buffer.from('%PDF-\x00\xff', 'latin1').toString('base64'));

    const upload = run({ ...base, name: 'upload_invoice_attachment', arguments: {
      id: 1, filename: 'a.pdf', content_base64: Buffer.from('%PDF-\x00\xff', 'latin1').toString('base64'),
    } });
    assert.equal(upload.isError, undefined, JSON.stringify(upload));
    assert.deepEqual(upload.structuredContent, { has_pdf: true, multipart: true });
  } finally {
    rmSync(directory, { recursive: true, force: true });
  }
});

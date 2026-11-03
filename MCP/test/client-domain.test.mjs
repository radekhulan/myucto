import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { runHosted } from '../src/hosted-core.mjs';
import { TOOLS_BY_NAME } from '../src/tools.mjs';

const contracts = JSON.parse(readFileSync(new URL('../src/tool-api.generated.json', import.meta.url), 'utf8'));

test('client tool contracts refer to real tools and explicit API operations', () => {
  for (const [name, { api: operations }] of Object.entries(contracts)) {
    assert.ok(TOOLS_BY_NAME.has(name), name);
    assert.ok(operations.length > 0, name);
    for (const [method, path] of operations) {
      assert.match(method, /^(GET|POST|PUT|DELETE)$/);
      assert.match(path, /^\/api\//);
    }
  }
});

test('hosted list intersects role tools with the token read scope', async () => {
  const result = await runHosted({ operation: 'list', scope: 'read', boundSupplierId: 1,
    allowedTools: ['whoami', 'list_invoices', 'create_invoice'] });
  assert.deepEqual(result.tools.map(t => t.name), ['whoami', 'list_invoices']);
});

test('a direct call cannot bypass the client tool list', async () => {
  let called = false;
  const result = await runHosted({ operation: 'call', scope: 'read_write', boundSupplierId: 1,
    allowedTools: ['whoami'], name: 'create_invoice', arguments: {} }, () => { called = true; });
  assert.equal(result.isError, true);
  assert.equal(called, false);
});

test('an empty client tool list fails closed', async () => {
  const result = await runHosted({ operation: 'list', scope: 'read_write', allowedTools: [] });
  assert.deepEqual(result.tools, []);
});

test('výkazy respektují čtecí rozsah tokenu i přímé volání', async () => {
  const allowedTools = ['get_work_report', 'add_work_report_entry', 'add_work_report_material', 'remove_work_report_entry'];
  const input = { scope: 'read', boundSupplierId: 1, allowedTools };
  const list = await runHosted({ ...input, operation: 'list' });
  assert.deepEqual(list.tools.map(t => t.name), ['get_work_report']);
  for (const name of allowedTools.slice(1)) {
    let called = false;
    const invoke = async (settings) => runHosted({ ...input, ...settings, operation: 'call', name,
      arguments: { invoice_id: 7, description: 'Syntetická práce', hours: 1 } }, () => { called = true; });
    assert.equal((await invoke({})).isError, true);
    assert.equal((await invoke({ scope: 'read_write', allowedTools: ['get_work_report'] })).isError, true);
    assert.equal(called, false);
  }
});

const documentReadTools = ['list_currencies', 'resolve_price_list_item'];
const documentWriteTools = ['update_purchase_invoice', 'add_purchase_invoice_item', 'update_purchase_invoice_item',
  'remove_purchase_invoice_item', 'upload_invoice_attachment', 'upload_purchase_invoice_pdf', 'import_purchase_invoice_file'];

test('číselníky jsou čtecí, změny přijatých konceptů a soubory vyžadují zápisový token i povolený nástroj', async () => {
  const input = { boundSupplierId: 1, allowedTools: [...documentReadTools, ...documentWriteTools] };
  for (const scope of ['read', 'read_write']) {
    const list = await runHosted({ ...input, scope, operation: 'list' });
    assert.deepEqual(list.tools.map(t => t.name).sort(),
      (scope === 'read' ? documentReadTools : input.allowedTools).toSorted());
  }
  for (const name of documentWriteTools) {
    let called = false;
    const invoke = (settings) => runHosted({ ...input, operation: 'call', name, arguments: {}, ...settings },
      () => { called = true; });
    const read = await invoke({ scope: 'read' });
    assert.equal(read.isError, true, name);
    assert.match(read.content[0].text, /jen pro čtení/);
    const denied = await invoke({ scope: 'read_write', allowedTools: documentReadTools });
    assert.equal(denied.isError, true, name);
    assert.match(denied.content[0].text, /nemáte oprávnění/);
    assert.equal(called, false);
  }
});

test('klientské souborové nástroje posílají multipart ve své firmě a náhrada PDF vyžaduje potvrzení', async () => {
  const content = '%PDF-synthetic';
  const file = { filename: 'synthetic.pdf', content_base64: Buffer.from(content).toString('base64') };
  const input = { operation: 'call', scope: 'read_write', boundSupplierId: 1, lockedSupplierId: 1,
    apiUrl: 'https://portal.example.test/api/v1', token: 'synthetic-token',
    allowedTools: ['upload_invoice_attachment', 'upload_purchase_invoice_pdf', 'import_purchase_invoice_file'] };
  for (const entry of [
    { name: 'upload_invoice_attachment', args: { id: 7, ...file }, path: '/api/v1/invoices/7/attachments' },
    { name: 'import_purchase_invoice_file', args: file, path: '/api/v1/purchase-invoices/import-structured' },
    { name: 'upload_purchase_invoice_pdf', args: { id: 7, ...file }, path: '/api/v1/purchase-invoices/7/pdf' },
    { name: 'upload_purchase_invoice_pdf', args: { id: 7, ...file }, existing: true, blocked: true },
    { name: 'upload_purchase_invoice_pdf', args: { id: 7, ...file, confirm: true }, existing: true, path: '/api/v1/purchase-invoices/7/pdf' },
  ]) {
    const calls = [];
    const result = await runHosted({ ...input, name: entry.name, arguments: entry.args }, async (url, options) => {
      calls.push([options.method, new URL(url).pathname]);
      assert.equal(options.headers['X-Supplier-Id'], '1');
      if (options.method === 'GET') {
        return new Response(JSON.stringify({ id: 7, pdf_path: entry.existing ? 'synthetic.pdf' : null }));
      }
      assert.equal(options.method, 'POST');
      assert.match(options.headers['Content-Type'], /^multipart\/form-data; boundary=/);
      const body = Buffer.from(options.body).toString('utf8');
      assert.match(body, /filename="synthetic.pdf"/);
      assert.ok(body.includes(content));
      return new Response(JSON.stringify({ id: 7, uploaded: true }));
    });
    if (entry.blocked) {
      assert.equal(result.isError, true);
      assert.match(result.content[0].text, /chybí potvrzení/);
      assert.deepEqual(calls, [['GET', '/api/v1/purchase-invoices/7']]);
    } else {
      assert.equal(result.isError, undefined, JSON.stringify(result));
      assert.deepEqual(calls.at(-1), ['POST', entry.path]);
    }
  }
});

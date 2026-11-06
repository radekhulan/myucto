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

test('provozní čtecí nástroje fungují s read tokenem, zachovají filtry a neobejdou katalog ani firmu', async () => {
  const cases = [
    { name: 'bank_account_balances', args: {}, path: '/bank-statements/account-balances', query: {} },
    { name: 'list_bank_match_suggestions', args: { id: 7 }, path: '/bank-statements/7/match-suggestions', query: {} },
    { name: 'recurring_invoice_history', args: { id: 7, page: 2, per_page: 5 }, path: '/recurring/7/invoices', query: { page: '2', per_page: '5' } },
    { name: 'search_products', args: { query: 'Syntetické zboží', limit: 5 }, path: '/stock/items/search', query: { q: 'Syntetické zboží', limit: '5' } },
    { name: 'stock_availability', args: { item_ids: [7, 8], warehouse_id: 9 }, path: '/stock/availability', query: { item_ids: '7,8', warehouse_id: '9' } },
    { name: 'list_warehouses', args: { active_only: true }, path: '/stock/warehouses', query: { active: '1' } },
    { name: 'get_warehouse', args: { id: 9 }, path: '/stock/warehouses/9', query: {} },
  ];
  const input = { boundSupplierId: 1, lockedSupplierId: 1, apiUrl: 'https://portal.example.test/api/v1',
    token: 'synthetic-token', allowedTools: cases.map(entry => entry.name) };
  for (const scope of ['read', 'read_write']) {
    const list = await runHosted({ ...input, scope, operation: 'list' });
    assert.deepEqual(list.tools.map(tool => tool.name).sort(), input.allowedTools.toSorted());
    assert.ok(list.tools.every(tool => tool.annotations.readOnlyHint && !tool.annotations.destructiveHint));
    for (const entry of cases) {
      const calls = [];
      const fetcher = async (url, options) => {
        calls.push(options.method);
        assert.equal(options.method, 'GET');
        assert.equal(options.headers['X-Supplier-Id'], '1');
        assert.equal(options.headers['X-MyUcto-Tool'], entry.name);
        assert.equal(new URL(url).pathname, `/api/v1${entry.path}`);
        assert.deepEqual(Object.fromEntries(new URL(url).searchParams), entry.query);
        return new Response(JSON.stringify({ synthetic: true }));
      };
      const call = { ...input, scope, operation: 'call', name: entry.name, arguments: entry.args };
      const result = await runHosted(call, fetcher);
      assert.equal(result.isError, undefined, JSON.stringify(result));
      assert.deepEqual(result.structuredContent, { synthetic: true });
      assert.deepEqual(calls, ['GET']);
      const denied = await runHosted({ ...call, allowedTools: [] }, fetcher);
      assert.equal(denied.isError, true);
      assert.match(denied.content[0].text, /nemáte oprávnění/);
      const foreign = await runHosted({ ...call, arguments: { ...entry.args, supplier_id: 2 } }, fetcher);
      assert.equal(foreign.isError, true);
      assert.match(foreign.content[0].text, /původně schválenou firmu/);
      assert.deepEqual(calls, ['GET']);
    }
  }
});

const readDetails = [
  { name: 'stock_quantities', path: '/stock/quantities', response: {
    items: [{ stock_item_id: 7, on_hand: '5.000', reserved: '2.000', sellable: '3.000', in_transit: '1.000' }], total: 1,
  }, variants: [
    { args: { item_ids: [7, 8], warehouse_id: 9 }, query: { item_ids: '7,8', warehouse_id: '9' } },
    { args: {}, query: {} },
    { args: { item_ids: [] }, query: {} },
  ] },
  { name: 'list_stock_locations', path: '/stock/locations', response: [{ id: 7, code: 'SYNTHETIC', warehouse_id: 9 }],
    variants: [{ args: { warehouse_id: 9 }, query: { warehouse_id: '9' } }, { args: {}, query: {} }] },
  { name: 'get_product_packaging', path: '/stock/items/7/packaging', response: { packaging: [{ code: 'BOX', factor: '6' }] },
    variants: [{ args: { id: 7 }, query: {} }] },
  { name: 'get_product_tracking', path: '/stock/items/7/tracking', response: {
    tracking_mode: 'lot', units: [], inventory: [{ lot_number: 'SYNTHETIC', expires_on: '2027-01-01' }], history: [],
  }, variants: [{ args: { id: 7 }, query: {} }] },
  { name: 'get_purchase_invoice_payment', path: '/purchase-invoices/7/payment-qr', response: {
    account_number: '1000000005', bank_code: '0100', amount: '100.00', currency: 'CZK',
  }, variants: [
    { args: { id: 7 }, query: {}, qr: 'data:image/png;base64,U1lOVEhFVElD' },
    { args: { id: 7 }, query: {} },
  ] },
  { name: 'list_expense_categories', path: '/expense-categories', response: [{ id: 7, name: 'Syntetická kategorie' }],
    variants: [{ args: { include_archived: true }, query: { include_archived: '1' } },
      { args: { include_archived: false }, query: {} }, { args: {}, query: {} }] },
  { name: 'list_invoice_advance_candidates', path: '/invoices/7/advance-candidates',
    response: { candidates: [{ id: 8, invoice_type: 'proforma', currency: 'CZK' }] },
    variants: [{ args: { id: 7 }, query: {} }] },
  { name: 'list_proforma_final_candidates', path: '/invoices/7/final-candidates',
    response: { candidates: [{ id: 8, invoice_type: 'invoice', currency: 'CZK' }] },
    variants: [{ args: { id: 7 }, query: {} }] },
  { name: 'list_invoice_advance_drawdown_candidates', path: '/invoices/7/advance-drawdown',
    response: [{ id: 8, total_with_vat: 1000, available: 250 }],
    variants: [{ args: { id: 7 }, query: {} }] },
  { name: 'list_purchase_advance_candidates', path: '/purchase-invoices/7/advance-candidates',
    response: { candidates: [{ id: 8, document_kind: 'advance', currency: 'CZK' }] },
    variants: [{ args: { id: 7 }, query: {} }] },
  { name: 'list_purchase_settlement_candidates', path: '/purchase-invoices/7/settlement-candidates',
    response: { candidates: [{ id: 8, document_kind: 'invoice', currency: 'CZK' }] },
    variants: [{ args: { id: 7 }, query: {} }] },
  { name: 'preview_invoice_penalty', path: '/invoices/7/penalty/preview',
    response: { total_days: 30, total_interest: 9.86 }, variants: [
      { args: { id: 7 }, query: {} },
      { args: { id: 7, as_of: '2026-02-01', principal: 100.5 }, query: { as_of: '2026-02-01', principal: '100.5' } },
    ] },
];

test('klientské čtecí doplňky skladu a fakturace jsou v obou rozsazích pouze čtecí', async () => {
  const allowedTools = readDetails.map(entry => entry.name);
  for (const scope of ['read', 'read_write']) {
    const list = await runHosted({ operation: 'list', scope, boundSupplierId: 1, allowedTools });
    assert.deepEqual(list.tools.map(tool => tool.name).sort(), allowedTools.toSorted());
    assert.ok(list.tools.every(tool => tool.annotations.readOnlyHint && !tool.annotations.destructiveHint));
  }
});

for (const entry of readDetails) {
  test(`${entry.name}: GET zachová filtry a odpověď a odmítne cizí firmu i nepovolené volání`, async () => {
    const input = { operation: 'call', boundSupplierId: 1, lockedSupplierId: 1,
      apiUrl: 'https://portal.example.test/api/v1', token: 'synthetic-token', allowedTools: [entry.name], name: entry.name };
    for (const scope of ['read', 'read_write']) {
      for (const variant of entry.variants) {
        const calls = [];
        const fetcher = async (url, options) => {
          calls.push(options.method);
          assert.equal(options.headers['X-Supplier-Id'], '1');
          assert.equal(options.headers['X-MyUcto-Tool'], entry.name);
          assert.equal(new URL(url).pathname, `/api/v1${entry.path}`);
          assert.deepEqual(Object.fromEntries(new URL(url).searchParams), variant.query);
          return new Response(JSON.stringify(variant.qr ? { ...entry.response, qr_data_uri: variant.qr } : entry.response));
        };
        const call = { ...input, scope, arguments: variant.args };
        const result = await runHosted(call, fetcher);
        assert.equal(result.isError, undefined, JSON.stringify(result));
        const expected = entry.name === 'get_purchase_invoice_payment'
          ? { ...entry.response, has_qr_image: Boolean(variant.qr) } : entry.response;
        assert.deepEqual(result.structuredContent, Array.isArray(expected) ? { result: expected } : expected);
        assert.deepEqual(calls, ['GET']);
        const denied = await runHosted({ ...call, allowedTools: [] }, fetcher);
        assert.equal(denied.isError, true);
        assert.match(denied.content[0].text, /nemáte oprávnění/);
        const foreign = await runHosted({ ...call, arguments: { ...variant.args, supplier_id: 2 } }, fetcher);
        assert.equal(foreign.isError, true);
        assert.match(foreign.content[0].text, /původně schválenou firmu/);
        assert.deepEqual(calls, ['GET']);
      }
    }
  });
}

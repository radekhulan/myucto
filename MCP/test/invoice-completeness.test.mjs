import assert from 'node:assert/strict';
import test from 'node:test';

import { TOOLS_BY_NAME } from '../src/tools.mjs';

/**
 * Úplnost MCP pro vydané a přijaté faktury: dobropis jen s vazbou, storno zaúčtovaného
 * dokladu jen v aplikaci, dimenze konceptu, číselníky a klasifikace položek přijatých faktur.
 */
class FakeClient {
  constructor(responses = {}) {
    this.responses = responses;
    this.calls = [];
  }

  response(method, path) {
    const value = this.responses[`${method} ${path}`];
    if (value instanceof Error) throw value;
    return structuredClone(value ?? { ok: true });
  }

  async get(path, query, tool) {
    this.calls.push({ method: 'GET', path, query, tool });
    return this.response('GET', path);
  }

  async post(path, body, tool) {
    this.calls.push({ method: 'POST', path, body, tool });
    return this.response('POST', path);
  }

  async put(path, body, tool) {
    this.calls.push({ method: 'PUT', path, body, tool });
    return this.response('PUT', path);
  }

  async del(path, tool, query) {
    this.calls.push({ method: 'DELETE', path, query, tool });
    return this.response('DELETE', path);
  }

  writes() {
    return this.calls.filter((call) => call.method !== 'GET');
  }
}

const run = (client, name, args) => {
  const tool = TOOLS_BY_NAME.get(name);
  assert.ok(tool, `Nástroj ${name} musí existovat.`);
  return tool.run(client, args, name);
};

const ITEM = { description: 'Licence', quantity: 1, unit_price_without_vat: 100, vat_rate_id: 1 };

// ── create_invoice ─────────────────────────────────────────────────────────

test('create_invoice nenabízí storno a dobropis bez vazby odmítne', async () => {
  const types = TOOLS_BY_NAME.get('create_invoice').inputSchema.properties.invoice_type.enum;
  assert.equal(types.includes('cancellation'), false);

  const client = new FakeClient();
  await assert.rejects(run(client, 'create_invoice', { client_id: 8, invoice_type: 'credit_note', items: [ITEM] }),
    /parent_invoice_id.*cancel_invoice/s);
  assert.deepEqual(client.writes(), []);
});

test('dobropis s vazbou převezme odběratele opravované faktury', async () => {
  const client = new FakeClient({ 'GET /invoices/70': { id: 70, status: 'paid', invoice_type: 'invoice', client_id: 8 } });
  await run(client, 'create_invoice', { invoice_type: 'credit_note', parent_invoice_id: 70, items: [{ ...ITEM, quantity: -1 }] });
  const [post] = client.writes();
  assert.equal(post.path, '/invoices');
  assert.equal(post.body.client_id, 8);
  assert.equal(post.body.parent_invoice_id, 70);
});

test('dobropis ke konceptu nebo jinému odběrateli se nezaloží', async () => {
  const client = new FakeClient({ 'GET /invoices/70': { id: 70, status: 'draft', invoice_type: 'invoice', client_id: 8 } });
  await assert.rejects(run(client, 'create_invoice', { invoice_type: 'credit_note', parent_invoice_id: 70, items: [ITEM] }),
    /není vystavená/);
  const other = new FakeClient({ 'GET /invoices/70': { id: 70, status: 'issued', invoice_type: 'invoice', client_id: 8 } });
  await assert.rejects(run(other, 'create_invoice', { client_id: 9, invoice_type: 'credit_note', parent_invoice_id: 70, items: [ITEM] }),
    /stejného odběratele/);
  assert.deepEqual([...client.writes(), ...other.writes()], []);
});

test('zjednodušený doklad nemusí mít odběratele, jiný doklad ano', async () => {
  await assert.rejects(run(new FakeClient(), 'create_invoice', { items: [ITEM] }), /client_id/);
  const client = new FakeClient();
  await run(client, 'create_invoice', { is_simplified: true, items: [ITEM], payment_method: 'cash', cash_register_id: 2 });
  assert.equal(client.writes()[0].body.is_simplified, true);
  assert.equal(client.writes()[0].body.cash_register_id, 2);
});

test('create_invoice pošle účty položky, měnu jako ID a ruční kurz', async () => {
  const client = new FakeClient({
    'GET /codebooks/currencies': [{ id: 1, code: 'CZK', is_default: true }, { id: 2, code: 'EUR', is_active: true }],
  });
  await run(client, 'create_invoice', {
    client_id: 8, currency: 'EUR', exchange_rate: 24.5, payment_method: 'offset', number_series_id: 3,
    items: [{ ...ITEM, revenue_account_code: '604', counter_account_code: '315', posting_rule_key: 'prodej.zbozi', vat_classification_code: '26' }],
  });
  const body = client.writes()[0].body;
  assert.equal(body.currency_id, 2);
  assert.equal('currency' in body, false);
  assert.equal(body.exchange_rate, 24.5);
  assert.equal(body.payment_method, 'offset');
  assert.equal(body.number_series_id, 3);
  assert.deepEqual(body.items[0], { ...ITEM, revenue_account_code: '604', counter_account_code: '315', posting_rule_key: 'prodej.zbozi', vat_classification_code: '26' });
});

// ── Politika: deník jen ke čtení ───────────────────────────────────────────

const issued = (extra = {}) => ({
  id: 42, status: 'issued', invoice_type: 'invoice', varsymbol: '20260042', total_with_vat: 1210, currency: 'CZK',
  booked_at: null, locked: { journal_entry_id: null }, cash_documents: [], ...extra,
});

test('interní storno zaúčtované faktury MCP odmítne, dobropis k ní založí', async () => {
  for (const invoice of [
    issued({ booked_at: '2026-10-01 10:00:00' }),
    issued({ locked: { journal_entry_id: 9 } }),
    issued({ cash_documents: [{ id: 3, status: 'posted' }] }),
  ]) {
    const client = new FakeClient({ 'GET /invoices/42': invoice });
    await assert.rejects(run(client, 'cancel_invoice', { id: 42, mode: 'internal', confirm: true }), /zaúčtovaná.*v aplikaci/s);
    assert.deepEqual(client.writes(), []);
  }
  const client = new FakeClient({ 'GET /invoices/42': issued({ booked_at: '2026-10-01 10:00:00' }) });
  await run(client, 'cancel_invoice', { id: 42, mode: 'credit_note', confirm: true });
  assert.deepEqual(client.writes()[0].body, { mode: 'credit_note' });
});

test('nezaúčtovanou fakturu MCP interně stornuje', async () => {
  const client = new FakeClient({ 'GET /invoices/42': issued() });
  await run(client, 'cancel_invoice', { id: 42, mode: 'internal', confirm: true });
  assert.equal(client.writes()[0].path, '/invoices/42/cancel');
});

test('zrušení storna zaúčtované faktury MCP odmítne', async () => {
  const client = new FakeClient({ 'GET /invoices/42': issued({ status: 'cancelled', booked_at: '2026-10-01 10:00:00' }) });
  await assert.rejects(run(client, 'uncancel_invoice', { id: 42, confirm: true }), /Zrušení storna.*v aplikaci/s);
  assert.deepEqual(client.writes(), []);
});

const purchase = (extra = {}) => ({
  id: 40, status: 'received', vendor_company_name: 'Dodavatel s.r.o.', vendor_invoice_number: 'FA-1',
  total_with_vat: 1210, currency: 'CZK', booked_at: null, locked: { journal_entry_id: null }, cash_payments: [],
  items: [
    { id: 501, description: 'Licence', expense_kind: 'service', expense_account_code: '518' },
    { id: 502, description: 'Zboží', expense_kind: 'material', expense_account_code: null },
  ],
  ...extra,
});

/** BEZ OPRAVY PADÁ: cancel_purchase_invoice stornoval i zaúčtovanou fakturu se zápisem v deníku. */
test('storno zaúčtované přijaté faktury MCP odmítne, nezaúčtovanou stornuje', async () => {
  for (const pi of [purchase({ booked_at: '2026-10-01' }), purchase({ cash_payments: [{ id: 5 }] })]) {
    const client = new FakeClient({ 'GET /purchase-invoices/40': pi });
    await assert.rejects(run(client, 'cancel_purchase_invoice', { id: 40, confirm: true }), /zaúčtovan.*aplikaci/s);
    assert.deepEqual(client.writes(), []);
  }
  const client = new FakeClient({ 'GET /purchase-invoices/40': purchase() });
  await run(client, 'cancel_purchase_invoice', { id: 40, confirm: true });
  assert.deepEqual(client.writes()[0].body, { target: 'cancelled' });
});

test('přijetí zaúčtované faktury hlásí zaúčtování, ne přijetí', async () => {
  const client = new FakeClient({ 'GET /purchase-invoices/40': purchase({ status: 'booked' }) });
  await assert.rejects(run(client, 'receive_purchase_invoice', { id: 40, confirm: true }), /je zaúčtovaná/);
});

// ── Klasifikace položek přijaté faktury ────────────────────────────────────

test('druh nákladu, účet a členění DPH po položkách; změna jen účtu nese současný druh', async () => {
  const client = new FakeClient({ 'GET /purchase-invoices/40': purchase() });
  await run(client, 'set_purchase_invoice_expense_kinds', {
    id: 40,
    items: [
      { item_id: 501, expense_account_code: '518.200' },
      { row: 2, vat_classification_code: '23' },
    ],
  });
  assert.deepEqual(client.writes()[0].body, { items: [
    { id: 501, expense_kind: 'service', expense_account_code: '518.200' },
    { id: 502, vat_classification_code: '23' },
  ] });
  await assert.rejects(run(client, 'set_purchase_invoice_expense_kinds', { id: 40, items: [{ row: 1 }] }), /expense_kind/);
});

test('založení přijaté faktury nese účty položek, pokladnu, řadu, účet k úhradě a zaokrouhlení', async () => {
  const client = new FakeClient({ 'GET /codebooks/currencies': [{ id: 1, code: 'CZK', is_default: true }] });
  await run(client, 'create_purchase_invoice', {
    vendor_id: 7, vendor_invoice_number: 'FA-9', issue_date: '2026-10-01', due_date: '2026-10-15', currency: 'CZK',
    payment_method: 'cash', cash_register_id: 2, number_series_id: 4, rounding: -0.4,
    payment: { account_number: '1000000005', bank_code: '0100' },
    items: [{ ...ITEM, expense_account_code: '518', counter_account_code: '325', posting_rule_key: 'sluzby' }],
  });
  const body = client.writes()[0].body;
  assert.equal(body.cash_register_id, 2);
  assert.equal(body.number_series_id, 4);
  assert.equal(body.rounding, -0.4);
  assert.deepEqual(body.payment, { account_number: '1000000005', bank_code: '0100', source: 'manual' });
  assert.equal(body.items[0].counter_account_code, '325');
  assert.equal(body.items[0].posting_rule_key, 'sluzby');
  assert.equal(body.items[0].expense_account_code, '518');
  assert.equal('status' in body, false);
});

// ── Dimenze konceptu ───────────────────────────────────────────────────────

test('dimenze konceptu: změní jen zadané typy a položky, rozpad typu s jedinou hodnotou zmizí', async () => {
  const client = new FakeClient({
    'GET /invoices/42': { id: 42, status: 'draft', items: [{ id: 101 }, { id: 102 }, { id: 103, item_kind: 'discount' }] },
    'GET /accounting/dimensions/documents/invoices/42': {
      header: { 1: 10 }, items: { 2: { 1: 11, 2: 20 } }, splits: { 0: { 2: [{ value_id: 21, share: 0.5 }, { value_id: 22, share: 0.5 }] } },
    },
  });
  await run(client, 'set_invoice_dimensions', {
    id: 42,
    header: [{ type_id: 2, value_id: 23 }],
    items: [{ item_id: 102, dimensions: [{ type_id: 2, value_id: null }] }, { row: 1, dimensions: [{ type_id: 1, value_id: 12 }] }],
  });
  const [put] = client.writes();
  assert.equal(put.path, '/accounting/dimensions/documents/invoices/42');
  assert.deepEqual(put.body, { header: { 1: 10, 2: 23 }, items: { 1: { 1: 12 }, 2: { 1: 11 } }, splits: {} });
});

test('dimenze vystavené faktury ani přijaté přijaté faktury MCP nezapíše', async () => {
  const client = new FakeClient({ 'GET /purchase-invoices/40': purchase() });
  await assert.rejects(run(client, 'set_purchase_invoice_dimensions', { id: 40, header: [{ type_id: 1, value_id: 2 }] }),
    /není koncept.*v aplikaci/s);
  assert.deepEqual(client.writes(), []);
});

// ── Číselníky ──────────────────────────────────────────────────────────────

test('číselníky volají správné cesty a předkontace filtrují podle strany', async () => {
  const client = new FakeClient({
    'GET /accounting/posting-rules': {
      'prodej.zbozi': { debit_account_code: '311', credit_account_code: '604', line_sale: true, line_purchase: false },
      'nakup.sluzby': { debit_account_code: '518', credit_account_code: '321', line_sale: false, line_purchase: true },
    },
  });
  assert.deepEqual((await run(client, 'list_posting_rules', { side: 'sale' })).map((rule) => rule.key), ['prodej.zbozi']);
  await run(client, 'list_revenue_categories', { include_archived: true });
  await run(client, 'list_vat_classifications', { direction: 'purchase' });
  await run(client, 'list_invoice_number_series', { type: 'credit_note' });
  await run(client, 'list_purchase_number_series', {});
  await run(client, 'list_branding_profiles', {});
  assert.deepEqual(client.calls.map(({ path, query }) => [path, query]), [
    ['/accounting/posting-rules', null],
    ['/revenue-categories', { include_archived: 1 }],
    ['/vat-classifications', { direction: 'purchase', include_archived: undefined }],
    ['/invoices/number-series', { type: 'credit_note' }],
    ['/purchase-invoices/number-series', null],
    ['/branding-profiles', null],
  ]);
  for (const name of ['list_posting_rules', 'list_revenue_categories', 'list_vat_classifications', 'list_branding_profiles']) {
    assert.equal(TOOLS_BY_NAME.get(name).write, false, name);
  }
});

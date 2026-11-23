import assert from 'node:assert/strict';
import test from 'node:test';

import { TOOLS, TOOLS_BY_NAME } from '../src/tools.mjs';
import { PURCHASE_TOOLS } from '../src/purchase-tools.mjs';

class FakeClient {
  constructor(responses = {}) {
    this.responses = responses;
    this.calls = [];
  }

  response(method, path) {
    return structuredClone(this.responses[`${method} ${path}`] ?? { ok: true });
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

const tool = (name) => {
  const found = TOOLS_BY_NAME.get(name);
  assert.ok(found, `Nástroj ${name} musí existovat.`);
  return found;
};

const CURRENCIES = [
  { id: 1, code: 'CZK', is_active: true, is_default: true },
  { id: 2, code: 'EUR', is_active: true, is_default: false },
];

/** Koncept přijaté faktury tak, jak ho vrací GET /purchase-invoices/{id}. */
const draft = (overrides = {}) => ({
  id: 40,
  status: 'draft',
  vendor_id: 7,
  vendor_company_name: 'Dodavatel s.r.o.',
  vendor_invoice_number: 'FA-2026-118',
  varsymbol: null,
  document_kind: 'invoice',
  issue_date: '2026-09-10',
  tax_date: '2026-09-09',
  delivery_date: null,
  due_date: '2026-09-24',
  received_at: '2026-09-12',
  currency_id: 1,
  currency: 'CZK',
  exchange_rate: null,
  reverse_charge: false,
  prices_include_vat: false,
  language: 'cs',
  note_above_items: null,
  note_below_items: 'Původní poznámka',
  advance_paid_amount: 0,
  payment_currency_id: null,
  payment_exchange_rate: null,
  paid_amount_payment_ccy: null,
  paid_amount_invoice_ccy: null,
  exchange_diff_base: null,
  vat_classification_code: '40',
  vat_deduction: 'proportional',
  vat_deduction_percent: 62,
  tax_deductible: false,
  is_fixed_asset: false,
  expense_category_id: 5,
  project_id: 3,
  payment_method: 'bank_transfer',
  payment_account_number: '1000000005',
  payment_bank_code: '0100',
  payment_iban: null,
  payment_bic: null,
  payment_variable_symbol: '2026118',
  vat_overrides: null,
  total_with_vat: 3630,
  booked_at: null,
  locked: { journal_entry_id: null, booked_at: null },
  linked_advance: null,
  items: [
    {
      id: 501, purchase_invoice_id: 40, description: 'Licence', quantity: 1, duration_minutes: null,
      unit: 'ks', unit_price_without_vat: 2000, vat_rate_id: 1, vat_rate_snapshot: 21,
      total_without_vat: 2000, total_vat: 420, total_with_vat: 2420, order_index: 0,
      vat_classification_code: '23', is_fixed_asset: false, expense_kind: 'material',
      expense_account_code: '501300', accrual_from: '2026-09-01', accrual_to: '2027-08-31',
      counter_account_code: '325', posting_rule_key: 'licence.sw',
      stock_item_id: 12, stock_sku: 'LIC', small_asset: null,
    },
    {
      id: 502, purchase_invoice_id: 40, description: 'Konzultace', quantity: 2, duration_minutes: 120,
      unit: 'hod', unit_price_without_vat: 500, vat_rate_id: 1, vat_rate_snapshot: 21,
      total_without_vat: 1000, total_vat: 210, total_with_vat: 1210, order_index: 1,
      vat_classification_code: '40', is_fixed_asset: true, expense_kind: 'fixed_asset',
      expense_account_code: null, accrual_from: null, accrual_to: null,
      stock_item_id: null, small_asset: null,
    },
  ],
  ...overrides,
});

/** BEZ OPRAVY PADÁ: protiúčet a předkontace řádku se při každé úpravě položek ztrácely. */
const HIDDEN_FIRST_LINE = {
  id: 501, description: 'Licence', quantity: 1, duration_minutes: null, unit: 'ks', unit_price_without_vat: 2000,
  vat_rate_id: 1, order_index: 0, vat_classification_code: '23', is_fixed_asset: false,
  expense_kind: 'material', expense_account_code: '501300', counter_account_code: '325',
  posting_rule_key: 'licence.sw', accrual_from: '2026-09-01', accrual_to: '2027-08-31', stock_item_id: 12,
};
const HIDDEN_SECOND_LINE = {
  id: 502, description: 'Konzultace', quantity: 2, duration_minutes: 120, unit: 'hod', unit_price_without_vat: 500,
  vat_rate_id: 1, order_index: 1, vat_classification_code: '40', is_fixed_asset: true,
  expense_kind: 'fixed_asset', expense_account_code: null, accrual_from: null, accrual_to: null,
  stock_item_id: null,
};

const withDraft = (overrides = {}, extra = {}) => new FakeClient({
  'GET /purchase-invoices/40': draft(overrides),
  'GET /codebooks/currencies': CURRENCIES,
  ...extra,
});

test('nástroje přijatých faktur jsou v katalogu a mají správné příznaky', () => {
  assert.equal(new Set(TOOLS.map(({ name }) => name)).size, TOOLS.length);
  for (const t of PURCHASE_TOOLS) assert.equal(TOOLS_BY_NAME.get(t.name), t, t.name);

  for (const name of [
    'list_purchase_advance_candidates', 'list_purchase_settlement_candidates', 'get_purchase_invoice_payment',
    'verify_purchase_payment_account', 'list_purchase_payment_candidates', 'list_purchase_payment_orders',
    'get_purchase_payment_order', 'get_purchase_invoice_activity', 'list_expense_categories',
  ]) {
    assert.equal(tool(name).write, false, name);
  }
  for (const name of [
    'create_purchase_invoice', 'update_purchase_invoice', 'add_purchase_invoice_item',
    'update_purchase_invoice_item', 'mark_purchase_invoice_paid', 'set_purchase_invoice_document_kind',
    'set_purchase_invoice_project', 'set_purchase_invoice_expense_kinds', 'set_purchase_invoice_exchange_rate',
    'link_purchase_advance', 'create_purchase_payment_order', 'mark_purchase_invoices_payment_ordered',
  ]) {
    assert.equal(tool(name).write, true, name);
    assert.ok(!tool(name).destructive, name);
  }
  for (const name of [
    'remove_purchase_invoice_item', 'delete_purchase_invoice', 'receive_purchase_invoice',
    'cancel_purchase_invoice', 'unlink_purchase_advance', 'set_purchase_invoice_payment_account',
    'delete_purchase_payment_order',
  ]) {
    assert.equal(tool(name).destructive, true, name);
    assert.ok(tool(name).inputSchema.properties.confirm, name);
  }
  // Zaúčtování, odeslání do banky a AI kontace nejsou v katalogu záměrně.
  for (const forbidden of [
    'book_purchase_invoice', 'submit_purchase_payment_order', 'suggest_purchase_posting',
  ]) {
    assert.equal(TOOLS_BY_NAME.has(forbidden), false, forbidden);
  }
});

test('žádný nástroj nepošle přechod na booked ani odeslání příkazu do banky', async () => {
  const client = withDraft({ status: 'received' });
  await tool('mark_purchase_invoice_paid').run(client, { id: 40 }, 'mark_purchase_invoice_paid');
  await tool('cancel_purchase_invoice').run(client, { id: 40, confirm: true }, 'cancel_purchase_invoice');
  for (const call of client.writes()) {
    assert.notEqual(call.body?.target, 'booked');
    assert.doesNotMatch(call.path, /submit|book/);
  }
});

test('založení posílá koncept v korunách bez kurzu a bez stavu', async () => {
  const client = new FakeClient({ 'GET /codebooks/currencies': CURRENCIES });
  await tool('create_purchase_invoice').run(client, {
    vendor_id: 7,
    vendor_invoice_number: ' FA-1 ',
    issue_date: '2026-09-10',
    due_date: '2026-09-24',
    tax_date: '2026-09-09',
    received_at: '2026-09-12',
    currency: 'czk',
    document_kind: 'advance',
    note: 'Pozn.',
    items: [
      { description: 'Licence', quantity: 1, unit_price_without_vat: 2000, vat_rate_id: 1, expense_kind: 'fixed_asset' },
      { description: 'Doprava', quantity: 1, unit: 'ks', unit_price_without_vat: 100, vat_rate_id: 1, accrual_from: '2026-09-01', accrual_to: '2026-12-31' },
    ],
  }, 'create_purchase_invoice');

  const post = client.calls.at(-1);
  assert.equal(post.method, 'POST');
  assert.equal(post.path, '/purchase-invoices');
  assert.deepEqual(post.body, {
    vendor_id: 7,
    vendor_invoice_number: 'FA-1',
    issue_date: '2026-09-10',
    due_date: '2026-09-24',
    currency_id: 1,
    document_kind: 'advance',
    tax_date: '2026-09-09',
    received_at: '2026-09-12',
    note_below_items: 'Pozn.',
    items: [
      { description: 'Licence', quantity: 1, unit: 'ks', unit_price_without_vat: 2000, vat_rate_id: 1,
        order_index: 0, expense_kind: 'fixed_asset', is_fixed_asset: true },
      { description: 'Doprava', quantity: 1, unit: 'ks', unit_price_without_vat: 100, vat_rate_id: 1,
        order_index: 1, accrual_from: '2026-09-01', accrual_to: '2026-12-31' },
    ],
  });
  assert.equal('status' in post.body, false);
  assert.equal('vat_classification_code' in post.body.items[0], false);
});

test('založení v cizí měně načte kurz ČNB k DUZP', async () => {
  const client = new FakeClient({
    'GET /codebooks/currencies': CURRENCIES,
    'GET /codebooks/cnb-rate': { rate: 24.335, rate_date: '2026-09-09' },
  });
  await tool('create_purchase_invoice').run(client, {
    vendor_id: 7, vendor_invoice_number: 'INV-9', issue_date: '2026-09-10', due_date: '2026-09-24',
    tax_date: '2026-09-09', currency: 'EUR',
    items: [{ description: 'SaaS', quantity: 1, unit_price_without_vat: 20, vat_rate_id: 4 }],
  }, 'create_purchase_invoice');

  const rate = client.calls.find((call) => call.path === '/codebooks/cnb-rate');
  assert.deepEqual(rate.query, { currency: 'EUR', date: '2026-09-09' });
  const body = client.calls.at(-1).body;
  assert.equal(body.currency_id, 2);
  assert.equal(body.exchange_rate, 24.335);
  assert.equal(body.exchange_rate_date, '2026-09-09');
  assert.equal(body.exchange_rate_source, 'cnb');
});

test('založení bez měny nebo s nulovým množstvím nic nezapíše', async () => {
  const client = new FakeClient({ 'GET /codebooks/currencies': CURRENCIES });
  const base = { vendor_id: 7, vendor_invoice_number: 'X', issue_date: '2026-09-10', due_date: '2026-09-24' };
  await assert.rejects(
    () => tool('create_purchase_invoice').run(client, { ...base, items: [{ description: 'a', quantity: 1, unit_price_without_vat: 1, vat_rate_id: 1 }] }),
    /měnu/,
  );
  await assert.rejects(
    () => tool('create_purchase_invoice').run(client, { ...base, currency: 'CZK', items: [{ description: 'a', quantity: 0, unit_price_without_vat: 1, vat_rate_id: 1 }] }),
    /nula/,
  );
  assert.equal(client.writes().length, 0);
});

test('úprava hlavičky pošle úplnou hlavičku ze stavu a jen zadané změny', async () => {
  const client = withDraft();
  const result = await tool('update_purchase_invoice').run(client, {
    id: 40, due_date: '2026-10-01', note: 'Nová poznámka',
  }, 'update_purchase_invoice');

  const put = client.calls.at(-1);
  assert.equal(put.method, 'PUT');
  assert.equal(put.path, '/purchase-invoices/40');
  assert.deepEqual(put.body, {
    vendor_id: 7, vendor_invoice_number: 'FA-2026-118', document_kind: 'invoice',
    issue_date: '2026-09-10', tax_date: '2026-09-09', delivery_date: null, due_date: '2026-10-01',
    received_at: '2026-09-12', currency_id: 1, reverse_charge: false, prices_include_vat: false,
    language: 'cs', note_above_items: null, note_below_items: 'Nová poznámka', advance_paid_amount: 0,
    payment_currency_id: null, payment_exchange_rate: null, paid_amount_payment_ccy: null,
    paid_amount_invoice_ccy: null, exchange_diff_base: null, vat_classification_code: '40',
    vat_deduction: 'proportional', vat_deduction_percent: 62, tax_deductible: false,
    is_fixed_asset: false, expense_category_id: 5,
  });
  // Volitelné sloupce, které server bez klíče nechá být, se bez změny neposílají.
  for (const key of ['items', 'project_id', 'payment_method', 'payment', 'varsymbol', 'vat_overrides', 'exchange_rate', 'cash_register_id']) {
    assert.equal(key in put.body, false, key);
  }
  assert.equal(result.items_reclassified, false);
});

test('změna DUZP nepřepočítá zařazení položek, zakázku jde odebrat nulou', async () => {
  const client = withDraft();
  await tool('update_purchase_invoice').run(client, { id: 40, tax_date: '', project_id: 0 }, 'update_purchase_invoice');
  const body = client.calls.at(-1).body;
  assert.equal(body.tax_date, null);
  assert.equal(body.project_id, null);
  assert.equal('items' in body, false);
  assert.equal(body.vat_classification_code, '40');
});

test('změna dodavatele odvodí zařazení všech položek znovu a zachová skrytá pole', async () => {
  const client = withDraft({}, { 'GET /clients/9': { id: 9, is_vat_payer: false } });
  const result = await tool('update_purchase_invoice').run(client, { id: 40, vendor_id: 9 }, 'update_purchase_invoice');
  const body = client.calls.at(-1).body;
  assert.equal(body.vendor_id, 9);
  assert.equal(body.vendor_is_vat_payer, false);
  // Neplátce bez výslovné volby: odpočet nastaví server („none"), proto se neposílá.
  assert.equal('vat_deduction' in body, false);
  assert.equal(body.vat_classification_code, null);
  const { vat_classification_code: _a, ...first } = HIDDEN_FIRST_LINE;
  const { vat_classification_code: _b, ...second } = HIDDEN_SECOND_LINE;
  assert.deepEqual(body.items, [first, second]);
  assert.equal(result.items_reclassified, true);
});

test('změna měny se pošle jako ID z číselníku', async () => {
  const client = withDraft();
  await tool('update_purchase_invoice').run(client, { id: 40, currency: 'EUR' }, 'update_purchase_invoice');
  assert.equal(client.calls.at(-1).body.currency_id, 2);
});

test('přijatou fakturu úpravy odmítnou ještě před zápisem', async () => {
  for (const [name, args] of [
    ['update_purchase_invoice', { id: 40, due_date: '2026-10-01' }],
    ['add_purchase_invoice_item', { id: 40, description: 'x', quantity: 1, unit_price_without_vat: 1, vat_rate_id: 1 }],
    ['update_purchase_invoice_item', { id: 40, row: 1, quantity: 3 }],
    ['remove_purchase_invoice_item', { id: 40, row: 1, confirm: true }],
    ['delete_purchase_invoice', { id: 40, confirm: true }],
    ['set_purchase_invoice_exchange_rate', { id: 40, rate: 25 }],
  ]) {
    const client = withDraft({ status: 'received' });
    await assert.rejects(() => tool(name).run(client, args, name), /není koncept/, name);
    assert.equal(client.writes().length, 0, name);
  }
});

test('přidání položky pošle všechny řádky se skrytými poli a přečísluje pořadí', async () => {
  const client = withDraft();
  await tool('add_purchase_invoice_item').run(client, {
    id: 40, description: 'Doprava', quantity: 1, unit_price_without_vat: 350, vat_rate_id: 1, position: 1,
  }, 'add_purchase_invoice_item');

  const put = client.calls.at(-1);
  assert.equal(put.method, 'PUT');
  assert.equal(put.path, '/purchase-invoices/40/items');
  assert.deepEqual(put.body.items, [
    { description: 'Doprava', quantity: 1, unit: 'ks', unit_price_without_vat: 350, vat_rate_id: 1, order_index: 0 },
    { ...HIDDEN_FIRST_LINE, order_index: 1 },
    { ...HIDDEN_SECOND_LINE, order_index: 2 },
  ]);
});

test('změna ceny jedné položky zachová ostatní řádky beze změny', async () => {
  const client = withDraft();
  await tool('update_purchase_invoice_item').run(client, { id: 40, item_id: 502, unit_price_without_vat: 650 }, 'update_purchase_invoice_item');
  assert.deepEqual(client.calls.at(-1).body.items, [
    HIDDEN_FIRST_LINE,
    { ...HIDDEN_SECOND_LINE, unit_price_without_vat: 650 },
  ]);
});

test('změna sazby nechá server odvodit zařazení jen u upravené položky', async () => {
  const client = withDraft();
  await tool('update_purchase_invoice_item').run(client, { id: 40, row: 1, vat_rate_id: 2 }, 'update_purchase_invoice_item');
  const [first, second] = client.calls.at(-1).body.items;
  assert.equal(first.vat_rate_id, 2);
  assert.equal('vat_classification_code' in first, false);
  assert.equal(first.expense_account_code, '501300');
  assert.deepEqual(second, HIDDEN_SECOND_LINE);
});

test('druh nákladu na položce drží příznak majetku a časová položka přepočítá minuty', async () => {
  const client = withDraft();
  await tool('update_purchase_invoice_item').run(client, { id: 40, row: 2, expense_kind: '', quantity: 3 }, 'update_purchase_invoice_item');
  const second = client.calls.at(-1).body.items[1];
  assert.equal(second.expense_kind, null);
  assert.equal(second.is_fixed_asset, false);
  assert.equal(second.duration_minutes, 180);
});

test('ruční rekapitulace DPH zablokuje úpravu položek', async () => {
  const client = withDraft({ vat_overrides: [{ rate: 21, base: 3000, vat: 630 }] });
  await assert.rejects(
    () => tool('update_purchase_invoice_item').run(client, { id: 40, row: 1, quantity: 2 }, 'update_purchase_invoice_item'),
    /rekapitulaci/,
  );
  assert.equal(client.writes().length, 0);
});

test('odebrání položky bez potvrzení nic nezmění, s potvrzením pošle zbylé řádky', async () => {
  const client = withDraft();
  await assert.rejects(
    () => tool('remove_purchase_invoice_item').run(client, { id: 40, row: 2 }, 'remove_purchase_invoice_item'),
    /NEPROVEDENO.*FA-2026-118.*Konzultace/s,
  );
  assert.equal(client.writes().length, 0);

  await tool('remove_purchase_invoice_item').run(client, { id: 40, row: 2, confirm: true }, 'remove_purchase_invoice_item');
  assert.deepEqual(client.calls.at(-1).body.items, [HIDDEN_FIRST_LINE]);
});

test('smazání konceptu vyžaduje potvrzení a ukáže dodavatele, číslo i částku', async () => {
  const client = withDraft();
  await assert.rejects(
    () => tool('delete_purchase_invoice').run(client, { id: 40 }, 'delete_purchase_invoice'),
    /NEPROVEDENO.*Dodavatel s\.r\.o\..*FA-2026-118.*3630 CZK/s,
  );
  assert.equal(client.writes().length, 0);
  await tool('delete_purchase_invoice').run(client, { id: 40, confirm: true }, 'delete_purchase_invoice');
  assert.deepEqual(client.calls.at(-1), { method: 'DELETE', path: '/purchase-invoices/40', query: undefined, tool: 'delete_purchase_invoice' });
});

test('přijetí upozorní na automatické zaúčtování a bez potvrzení nic nezapíše', async () => {
  const client = withDraft();
  await assert.rejects(
    () => tool('receive_purchase_invoice').run(client, { id: 40 }, 'receive_purchase_invoice'),
    /NEPROVEDENO.*zaúčtuje.*FA-2026-118/s,
  );
  assert.equal(client.writes().length, 0);
  await tool('receive_purchase_invoice').run(client, { id: 40, confirm: true }, 'receive_purchase_invoice');
  const post = client.calls.at(-1);
  assert.equal(post.path, '/purchase-invoices/40/transition');
  assert.deepEqual(post.body, { target: 'received' });

  const received = withDraft({ status: 'received' });
  await assert.rejects(() => tool('receive_purchase_invoice').run(received, { id: 40, confirm: true }), /už je přijatá/);
  assert.equal(received.writes().length, 0);
});

test('úhrada a storno posílají správný cíl přechodu', async () => {
  const client = withDraft({ status: 'received' });
  await tool('mark_purchase_invoice_paid').run(client, { id: 40, paid_date: '2026-09-30' }, 'mark_purchase_invoice_paid');
  assert.deepEqual(client.calls.at(-1).body, { target: 'paid', paid_date: '2026-09-30' });

  await assert.rejects(() => tool('cancel_purchase_invoice').run(client, { id: 40 }, 'cancel_purchase_invoice'), /NEPROVEDENO.*Stornovat/s);
  assert.equal(client.writes().length, 1);
  await tool('cancel_purchase_invoice').run(client, { id: 40, confirm: true }, 'cancel_purchase_invoice');
  assert.deepEqual(client.calls.at(-1).body, { target: 'cancelled' });
});

test('zaúčtovaný doklad: druh, druh nákladu a záloha se odmítnou, zakázka projde', async () => {
  const posted = { status: 'received', booked_at: '2026-09-15 10:00:00', locked: { journal_entry_id: 88 } };
  for (const [name, args] of [
    ['set_purchase_invoice_document_kind', { id: 40, document_kind: 'receipt' }],
    ['set_purchase_invoice_expense_kinds', { id: 40, items: [{ row: 1, expense_kind: 'service' }] }],
    ['link_purchase_advance', { id: 40, advance_id: 41 }],
  ]) {
    const client = withDraft(posted);
    await assert.rejects(() => tool(name).run(client, args, name), /zaúčtovaná/, name);
    assert.equal(client.writes().length, 0, name);
  }

  const client = withDraft(posted);
  await tool('set_purchase_invoice_project').run(client, { id: 40, project_id: 0 }, 'set_purchase_invoice_project');
  assert.deepEqual(client.calls.at(-1), { method: 'POST', path: '/purchase-invoices/40/project', body: { project_id: null }, tool: 'set_purchase_invoice_project' });
});

test('nezaúčtovaný doklad: druh dokladu, druh nákladu podle pořadí a záloha', async () => {
  const client = withDraft({ status: 'received' });
  await tool('set_purchase_invoice_document_kind').run(client, { id: 40, document_kind: 'receipt' }, 'set_purchase_invoice_document_kind');
  assert.deepEqual(client.calls.at(-1).body, { document_kind: 'receipt' });

  await tool('set_purchase_invoice_expense_kinds').run(client, {
    id: 40, items: [{ row: 2, expense_kind: 'service' }, { item_id: 501, expense_kind: null }],
  }, 'set_purchase_invoice_expense_kinds');
  const put = client.calls.at(-1);
  assert.equal(put.path, '/purchase-invoices/40/expense-kinds');
  assert.deepEqual(put.body, { items: [{ id: 502, expense_kind: 'service' }, { id: 501, expense_kind: null }] });

  await tool('link_purchase_advance').run(client, { id: 40, advance_id: 41 }, 'link_purchase_advance');
  assert.deepEqual(client.calls.at(-1).body, { advance_id: 41 });
});

test('ruční kurz konceptu se váže k DUZP a jde jako uživatelský', async () => {
  const client = withDraft({ currency: 'EUR', currency_id: 2 });
  await tool('set_purchase_invoice_exchange_rate').run(client, { id: 40, rate: 24.5 }, 'set_purchase_invoice_exchange_rate');
  assert.deepEqual(client.calls.at(-1).body, { rate: 24.5, rate_date: '2026-09-09', source: 'user' });

  const czk = withDraft();
  await assert.rejects(() => tool('set_purchase_invoice_exchange_rate').run(czk, { id: 40, rate: 2 }), /korunách/);
  assert.equal(czk.writes().length, 0);
});

test('odpojení zálohy vyžaduje potvrzení', async () => {
  const client = withDraft({ status: 'received', linked_advance: { id: 41, vendor_invoice_number: 'ZF-7', total_with_vat: 1000, currency: 'CZK' } });
  await assert.rejects(() => tool('unlink_purchase_advance').run(client, { id: 40 }, 'unlink_purchase_advance'), /NEPROVEDENO.*ZF-7/s);
  assert.equal(client.writes().length, 0);
  await tool('unlink_purchase_advance').run(client, { id: 40, confirm: true }, 'unlink_purchase_advance');
  assert.equal(client.calls.at(-1).method, 'DELETE');
  assert.equal(client.calls.at(-1).path, '/purchase-invoices/40/link-advance');
});

test('změna účtu dodavatele ukáže starý a nový účet a zachová nezadaná pole', async () => {
  const client = withDraft({}, { 'PUT /purchase-invoices/40/payment-account': { ok: true, qr_data_uri: 'data:image/png;base64,AAAA', amount: 3630 } });
  await assert.rejects(
    () => tool('set_purchase_invoice_payment_account').run(client, { id: 40, iban: 'CZ6508000000192000145399' }, 'set_purchase_invoice_payment_account'),
    /NEPROVEDENO.*1000000005\/0100.*IBAN CZ6508000000192000145399/s,
  );
  assert.equal(client.writes().length, 0);

  const result = await tool('set_purchase_invoice_payment_account').run(client, {
    id: 40, iban: 'CZ6508000000192000145399', confirm: true,
  }, 'set_purchase_invoice_payment_account');
  assert.deepEqual(client.calls.at(-1).body, {
    account_number: '1000000005', bank_code: '0100', iban: 'CZ6508000000192000145399', bic: null, variable_symbol: '2026118',
  });
  assert.equal('qr_data_uri' in result, false);
  assert.equal(result.has_qr_image, true);
});

test('platební údaje vrací bez obrázku QR', async () => {
  const client = new FakeClient({ 'GET /purchase-invoices/40/payment-qr': { ok: true, qr_data_uri: null, needs_account: true } });
  const result = await tool('get_purchase_invoice_payment').run(client, { id: 40 }, 'get_purchase_invoice_payment');
  assert.deepEqual(result, { ok: true, needs_account: true, has_qr_image: false });
});

test('příkaz k úhradě se jen připraví, nikdy neoznačí faktury jako uhrazené', async () => {
  const client = new FakeClient();
  await tool('create_purchase_payment_order').run(client, {
    invoice_ids: [40, 41], payer_currency_id: 1, payment_date: '2026-10-02',
  }, 'create_purchase_payment_order');
  assert.deepEqual(client.calls.at(-1), {
    method: 'POST', path: '/purchase-invoices/payment-orders',
    body: { invoice_ids: [40, 41], payer_currency_id: 1, mark_paid: false, payment_date: '2026-10-02' },
    tool: 'create_purchase_payment_order',
  });

  await tool('mark_purchase_invoices_payment_ordered').run(client, { invoice_ids: [40] }, 'mark_purchase_invoices_payment_ordered');
  assert.deepEqual(client.calls.at(-1).body, { invoice_ids: [40], mark_paid: false });
  assert.equal(tool('create_purchase_payment_order').inputSchema.properties.mark_paid, undefined);
});

test('smazání příkazu k úhradě vyžaduje potvrzení', async () => {
  const client = new FakeClient({ 'GET /purchase-invoices/payment-orders/5': { id: 5, item_count: 2, total_amount: 4840, currency: 'CZK', payment_date: '2026-10-02' } });
  await assert.rejects(() => tool('delete_purchase_payment_order').run(client, { id: 5 }), /NEPROVEDENO.*4840 CZK/s);
  assert.equal(client.writes().length, 0);
  await tool('delete_purchase_payment_order').run(client, { id: 5, confirm: true }, 'delete_purchase_payment_order');
  assert.equal(client.calls.at(-1).method, 'DELETE');
  assert.equal(client.calls.at(-1).path, '/purchase-invoices/payment-orders/5');
});

test('čtecí nástroje volají správné cesty', async () => {
  const client = new FakeClient();
  const cases = [
    ['list_purchase_advance_candidates', { id: 40 }, '/purchase-invoices/40/advance-candidates', null],
    ['list_purchase_settlement_candidates', { id: 40 }, '/purchase-invoices/40/settlement-candidates', null],
    ['get_purchase_invoice_activity', { id: 40 }, '/purchase-invoices/40/activity', null],
    ['verify_purchase_payment_account', { id: 40 }, '/purchase-invoices/payment-orders/verify-account', { invoice_id: 40 }],
    ['list_purchase_payment_candidates', { currency: 'CZK', include_non_transfer: true, page: 2 },
      '/purchase-invoices/payment-orders/candidates', { currency: 'CZK', page: 2, include_non_transfer: 1 }],
    ['list_purchase_payment_orders', { per_page: 20 }, '/purchase-invoices/payment-orders', { per_page: 20 }],
    ['get_purchase_payment_order', { id: 5 }, '/purchase-invoices/payment-orders/5', null],
    ['list_expense_categories', { include_archived: true }, '/expense-categories', { include_archived: 1 }],
  ];
  for (const [name, args, path, query] of cases) {
    await tool(name).run(client, args, name);
    const call = client.calls.at(-1);
    assert.equal(call.method, 'GET', name);
    assert.equal(call.path, path, name);
    assert.deepEqual(call.query, query, name);
  }
  assert.equal(client.writes().length, 0);
});

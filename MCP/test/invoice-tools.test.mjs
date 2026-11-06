import assert from 'node:assert/strict';
import test from 'node:test';

// Katalog se načítá přes tools.mjs, ne přímo: invoice-tools.mjs z něj importuje
// sdílené pomocné funkce (viz hlavička modulu).
import { TOOLS_BY_NAME } from '../src/tools.mjs';

class FakeClient {
  constructor(responses = {}) {
    this.responses = responses;
    this.calls = [];
  }

  response(method, path) {
    return this.responses[`${method} ${path}`] ?? { ok: true };
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

const run = (client, name, args) => tool(name).run(client, args, name);

const NEW_TOOLS = {
  delete_invoice_draft: 'destructive',
  cancel_invoice: 'destructive',
  uncancel_invoice: 'destructive',
  clone_invoice: 'write',
  create_final_invoice_from_proforma: 'write',
  list_invoice_advance_candidates: 'read',
  list_proforma_final_candidates: 'read',
  link_invoice_advance: 'write',
  unlink_invoice_advance: 'destructive',
  remove_invoice_advance_drawdown: 'destructive',
  preview_invoice_penalty: 'read',
  create_invoice_penalty: 'write',
  add_invoice_payment: 'write',
  delete_invoice_payment: 'destructive',
  create_payment_tax_document: 'write',
  unmark_invoice_paid: 'destructive',
  set_invoice_payment_schedule: 'write',
  get_invoice_isdoc: 'read',
  get_invoice_recipients: 'read',
  send_invoice_reminders_bulk: 'destructive',
  create_recurring_invoice: 'write',
  update_recurring_invoice: 'write',
  delete_recurring_invoice: 'destructive',
  pause_recurring_invoice: 'write',
  resume_recurring_invoice: 'write',
  reschedule_recurring_invoice: 'write',
  run_recurring_invoice_now: 'destructive',
};

const issuedInvoice = (overrides = {}) => ({
  id: 42,
  varsymbol: '20260042',
  invoice_type: 'invoice',
  status: 'issued',
  client_company_name: 'Testovací odběratel s.r.o.',
  total_with_vat: 12100,
  currency: 'CZK',
  ...overrides,
});

test('nové fakturační nástroje mají správné příznaky a uzavřená schémata', () => {
  for (const [name, kind] of Object.entries(NEW_TOOLS)) {
    const entry = tool(name);
    assert.equal(entry.write, kind !== 'read', name);
    assert.equal(entry.destructive === true, kind === 'destructive', name);
    assert.equal(entry.inputSchema.additionalProperties, false, name);
    for (const required of entry.inputSchema.required) {
      assert.ok(Object.hasOwn(entry.inputSchema.properties, required), `${name}.${required}`);
    }
    if (kind === 'destructive') assert.ok(entry.inputSchema.properties.confirm, `${name}.confirm`);
  }
  // Zaúčtování je pro token zakázané a v katalogu být nesmí.
  for (const forbidden of ['book_invoice', 'unbook_invoice', 'rebuild_invoice_snapshots']) {
    assert.equal(TOOLS_BY_NAME.has(forbidden), false, forbidden);
  }
});

test('jednoduché nástroje volají správnou cestu se správným tělem', async () => {
  const cases = [
    ['clone_invoice', { id: 42, issue_date: '2026-10-01', increment_month_in_descriptions: true },
      'POST', '/invoices/42/clone', { issue_date: '2026-10-01', increment_month_in_descriptions: true }],
    ['create_final_invoice_from_proforma', { id: 7, tax_date: '2026-10-01', final_total: 50000 },
      'POST', '/invoices/7/issue-final', { tax_date: '2026-10-01', final_total: 50000 }],
    ['list_invoice_advance_candidates', { id: 42 }, 'GET', '/invoices/42/advance-candidates'],
    ['list_proforma_final_candidates', { id: 7 }, 'GET', '/invoices/7/final-candidates'],
    ['link_invoice_advance', { id: 42, advance_id: 7 }, 'POST', '/invoices/42/link-advance', { advance_id: 7 }],
    ['preview_invoice_penalty', { id: 42, as_of: '2026-10-01' }, 'GET', '/invoices/42/penalty/preview'],
    ['create_invoice_penalty', { id: 42, principal: 1000 }, 'POST', '/invoices/42/penalty', { principal: 1000 }],
    ['add_invoice_payment', { id: 42, amount: 5000, paid_on: '2026-09-30', note: 'Hotově' },
      'POST', '/invoices/42/payments', { amount: 5000, paid_on: '2026-09-30', note: 'Hotově' }],
    ['create_payment_tax_document', { id: 7, payment_id: 3 }, 'POST', '/invoices/7/payments/3/tax-document', {}],
    ['get_invoice_recipients', { id: 42, type: 'reminders' }, 'GET', '/invoices/42/recipients'],
    ['pause_recurring_invoice', { id: 5 }, 'POST', '/recurring/5/pause', {}],
    ['resume_recurring_invoice', { id: 5 }, 'POST', '/recurring/5/resume', {}],
  ];
  for (const [name, args, method, path, body] of cases) {
    const client = new FakeClient();
    await run(client, name, args);
    assert.equal(client.calls.length, 1, name);
    const [call] = client.calls;
    assert.equal(call.method, method, name);
    assert.equal(call.path, path, name);
    assert.equal(call.tool, name, name);
    if (body !== undefined) assert.deepEqual(call.body, body, name);
  }
});

test('čtecí dotazy posílají jen zadané parametry', async () => {
  const client = new FakeClient();
  await run(client, 'preview_invoice_penalty', { id: 42, as_of: '2026-10-01' });
  await run(client, 'get_invoice_recipients', { id: 42, type: 'reminders' });
  assert.deepEqual(client.calls[0].query, { as_of: '2026-10-01' });
  assert.deepEqual(client.calls[1].query, { type: 'reminders' });
});

test('nevratné kroky bez potvrzení nic nezapíšou a ukážou konkrétní doklad', async () => {
  const draft = issuedInvoice({ status: 'draft', varsymbol: null });
  const paid = issuedInvoice({ status: 'paid' });
  const cancelled = issuedInvoice({ status: 'cancelled' });
  const cases = [
    ['delete_invoice_draft', { id: 42 }, { 'GET /invoices/42': draft }, /NEPROVEDENO.*Faktura #42, Testovací odběratel s\.r\.o\., 12100\.00 CZK/s],
    ['cancel_invoice', { id: 42, mode: 'internal' }, { 'GET /invoices/42': issuedInvoice() }, /NEPROVEDENO.*Interně stornovat.*č\. 20260042/s],
    ['cancel_invoice', { id: 42, mode: 'credit_note' }, { 'GET /invoices/42': issuedInvoice() }, /NEPROVEDENO.*dobropis.*12100\.00 CZK/s],
    ['uncancel_invoice', { id: 42 }, { 'GET /invoices/42': cancelled }, /NEPROVEDENO.*20260042/s],
    ['unmark_invoice_paid', { id: 42 }, { 'GET /invoices/42': paid }, /NEPROVEDENO.*20260042/s],
    ['delete_invoice_payment', { id: 42, payment_id: 3 }, {
      'GET /invoices/42': paid,
      'GET /invoices/42/payments': { payments: [{ id: 3, amount: 12100, paid_on: '2026-09-15', source: 'manual' }] },
    }, /NEPROVEDENO.*12100\.00 CZK ze dne 2026-09-15.*20260042/s],
    ['send_invoice_reminders_bulk', { invoice_ids: [42, 43] }, {
      'GET /invoices/42': issuedInvoice(),
      'GET /invoices/43': issuedInvoice({ id: 43, varsymbol: '20260043', total_with_vat: 500 }),
    }, /NEPROVEDENO.*2 dokladům.*20260042.*20260043/s],
    ['delete_recurring_invoice', { id: 5 }, {
      'GET /recurring/5': { id: 5, name: 'Měsíční paušál', client_company_name: 'Testovací odběratel s.r.o.' },
    }, /NEPROVEDENO.*Měsíční paušál/s],
    ['run_recurring_invoice_now', { id: 5 }, {
      'GET /recurring/5': { id: 5, name: 'Paušál', auto_issue: true, auto_send_email: true },
    }, /NEPROVEDENO.*VYSTAVÍ a ODEŠLE.*Paušál/s],
  ];
  for (const [name, args, responses, message] of cases) {
    const client = new FakeClient(responses);
    await assert.rejects(run(client, name, args), message, name);
    assert.deepEqual(client.writes(), [], name);
  }
});

test('nevratné kroky s potvrzením volají správnou cestu', async () => {
  const draft = issuedInvoice({ status: 'draft', varsymbol: null });
  const cases = [
    ['delete_invoice_draft', { id: 42 }, { 'GET /invoices/42': draft }, 'DELETE', '/invoices/42'],
    ['cancel_invoice', { id: 42, mode: 'credit_note', reason: 'Vrácené zboží' }, { 'GET /invoices/42': issuedInvoice() },
      'POST', '/invoices/42/cancel', { mode: 'credit_note', reason: 'Vrácené zboží' }],
    ['uncancel_invoice', { id: 42 }, { 'GET /invoices/42': issuedInvoice({ status: 'cancelled' }) }, 'POST', '/invoices/42/uncancel', {}],
    ['unmark_invoice_paid', { id: 42 }, { 'GET /invoices/42': issuedInvoice({ status: 'paid' }) }, 'POST', '/invoices/42/unmark-paid', {}],
    ['delete_invoice_payment', { id: 42, payment_id: 3 }, {
      'GET /invoices/42/payments': { payments: [{ id: 3, amount: 100, paid_on: '2026-09-15' }] },
    }, 'DELETE', '/invoices/42/payments/3'],
    ['send_invoice_reminders_bulk', { invoice_ids: [42, 43, 42] }, {}, 'POST', '/invoices/bulk-reminder', { invoice_ids: [42, 43] }],
    ['delete_recurring_invoice', { id: 5 }, { 'GET /recurring/5': { id: 5, name: 'Paušál' } }, 'DELETE', '/recurring/5'],
    ['run_recurring_invoice_now', { id: 5, draft: true }, { 'GET /recurring/5': { id: 5, name: 'Paušál' } },
      'POST', '/recurring/5/run-now', { draft: true }],
  ];
  for (const [name, args, responses, method, path, body] of cases) {
    const client = new FakeClient(responses);
    await run(client, name, { ...args, confirm: true });
    const writes = client.writes();
    assert.equal(writes.length, 1, name);
    assert.equal(writes[0].method, method, name);
    assert.equal(writes[0].path, path, name);
    if (body !== undefined) assert.deepEqual(writes[0].body, body, name);
  }
});

test('hromadné upomínky s potvrzením zbytečně nenačítají doklady', async () => {
  const client = new FakeClient();
  await run(client, 'send_invoice_reminders_bulk', { invoice_ids: [42], confirm: true });
  assert.equal(client.calls.some((call) => call.method === 'GET'), false);
});

test('mazání a storno odmítnou doklad ve špatném stavu ještě před zápisem', async () => {
  const cases = [
    ['delete_invoice_draft', { id: 42, confirm: true }, issuedInvoice(), /není koncept.*cancel_invoice/s],
    ['cancel_invoice', { id: 42, mode: 'internal', confirm: true }, issuedInvoice({ status: 'draft' }), /jen vystavený/],
    ['cancel_invoice', { id: 42, mode: 'credit_note', confirm: true }, issuedInvoice({ invoice_type: 'credit_note' }), /pouze interně/],
    ['cancel_invoice', { id: 42, mode: 'internal', confirm: true }, issuedInvoice({ invoice_type: 'cancellation' }), /Stornovací doklad/],
    ['uncancel_invoice', { id: 42, confirm: true }, issuedInvoice(), /jen u stornované/],
    ['uncancel_invoice', { id: 42, confirm: true }, issuedInvoice({ status: 'cancelled', invoice_type: 'credit_note' }), /jen u stornované/],
    ['unmark_invoice_paid', { id: 42, confirm: true }, issuedInvoice(), /jen zaplacený/],
  ];
  for (const [name, args, invoice, message] of cases) {
    const client = new FakeClient({ 'GET /invoices/42': invoice });
    await assert.rejects(run(client, name, args), message, name);
    assert.deepEqual(client.writes(), [], name);
  }
});

test('smazání neexistující platby nic nezapíše', async () => {
  const client = new FakeClient({ 'GET /invoices/42/payments': { payments: [{ id: 4 }] } });
  await assert.rejects(run(client, 'delete_invoice_payment', { id: 42, payment_id: 3, confirm: true }), /Platba #3/);
  assert.deepEqual(client.writes(), []);
});

// ── Platební kalendář ──────────────────────────────────────────────────────

const calendarDraft = (overrides = {}) => ({
  id: 42,
  status: 'draft',
  invoice_type: 'rent_calendar',
  calendar_contract_ref: 'Nájemní smlouva 1/2026',
  client_id: 8,
  project_id: 3,
  issue_date: '2026-10-01',
  tax_date: null,
  due_date: '2026-10-15',
  currency_id: 1,
  currency: 'CZK',
  reverse_charge: false,
  prices_include_vat: false,
  language: 'cs',
  note_below_items: 'Splátky podle smlouvy',
  discount_percent: 10,
  payment_method: 'bank_transfer',
  rounding_mode: 'none',
  varsymbol: null,
  total_without_vat: 10000,
  total_vat: 2100,
  total_with_vat: 12100,
  payment_schedule: [{ id: 1, due_on: '2026-10-15', base_amount: 10000, vat_amount: 2100, total_amount: 12100, note: null }],
  items: [
    {
      id: 101, description: 'Nájem kanceláře', quantity: 12, duration_minutes: null, unit: 'měs',
      unit_price_without_vat: 1000, list_unit_price: 1200, line_discount_pct: 16.667, vat_rate_id: 1, order_index: 0, item_kind: 'standard',
      vat_classification_code: '1', stock_item_id: null, warehouse_id: null,
      small_asset_id: null, asset_id: null, accrual_from: '2026-10-01', accrual_to: '2027-09-30',
      oss_applicable: false, oss_consumer_country: null, total_with_vat: 14520,
      tracking_allocations: [{ quantity: '1.000', serial_number: 'VIN-A', lot_code: null, expires_on: null }],
    },
    { id: 102, description: 'Sleva 10 %', quantity: 1, unit: 'ks', unit_price_without_vat: -1200,
      vat_rate_id: 1, order_index: 1, item_kind: 'discount' },
  ],
  ...overrides,
});

const calendarClient = (invoice = calendarDraft()) => new FakeClient({
  'GET /invoices/42': invoice,
  'PUT /invoices/42': { id: 42, status: 'draft', total_with_vat: 12100 },
});

test('rozpis plateb pošle celou hlavičku i položky se skrytými poli', async () => {
  const client = calendarClient();
  const result = await run(client, 'set_invoice_payment_schedule', {
    invoice_id: 42,
    payment_schedule: [
      { due_on: '2026-10-15', tax_date: '2026-10-01', base_amount: 5000, vat_amount: 1050, note: ' první splátka ' },
      { due_on: '2026-11-15', base_amount: 5000, vat_amount: 1050, total_amount: 6050 },
    ],
  });

  const puts = client.writes();
  assert.equal(puts.length, 1);
  assert.equal(puts[0].method, 'PUT');
  assert.equal(puts[0].path, '/invoices/42');
  const body = puts[0].body;
  assert.equal(body.invoice_type, 'rent_calendar');
  assert.equal(body.calendar_contract_ref, 'Nájemní smlouva 1/2026');
  assert.equal(body.client_id, 8);
  assert.equal(body.project_id, 3);
  assert.equal(body.note_below_items, 'Splátky podle smlouvy');
  assert.equal(body.discount_percent, 10);
  // Slevový řádek generuje server; ostatní položky jdou zpět i se skrytými poli.
  assert.equal(body.items.length, 1);
  assert.deepEqual(body.items[0], {
    description: 'Nájem kanceláře', quantity: 12, duration_minutes: null, unit: 'měs',
    unit_price_without_vat: 1000, list_unit_price: 1200, vat_rate_id: 1, order_index: 0, item_kind: 'standard', vat_classification_code: '1',
    stock_item_id: null, warehouse_id: null, small_asset_id: null, asset_id: null,
    accrual_from: '2026-10-01', accrual_to: '2027-09-30',
    oss_applicable: false, oss_consumer_country: null,
    tracking_allocations: [{ quantity: '1.000', serial_number: 'VIN-A', lot_code: null, expires_on: null }],
  });
  assert.deepEqual(body.payment_schedule, [
    { due_on: '2026-10-15', total_amount: 6050, tax_date: '2026-10-01', base_amount: 5000, vat_amount: 1050, note: 'první splátka' },
    { due_on: '2026-11-15', total_amount: 6050, base_amount: 5000, vat_amount: 1050 },
  ]);
  assert.equal(result.invoice_id, 42);
  assert.equal(result.previous_payment_schedule.length, 1);
});

test('rozpis s nesouhlasným součtem se neuloží a řekne rozdíl', async () => {
  const client = calendarClient();
  await assert.rejects(
    run(client, 'set_invoice_payment_schedule', {
      invoice_id: 42,
      payment_schedule: [{ due_on: '2026-10-15', total_amount: 6050 }, { due_on: '2026-11-15', total_amount: 6000 }],
    }),
    /NEULOŽENO.*12050\.00 CZK.*12100\.00 CZK.*rozdíl -50\.00 CZK/s,
  );
  assert.deepEqual(client.writes(), []);
});

test('rozpis se sedící celkovou částkou, ale jiným základem nebo DPH se neuloží', async () => {
  const cases = [
    [[{ due_on: '2026-10-15', total_amount: 12100 }], /NEULOŽENO.*základ 0\.00 CZK.*10000\.00 CZK/s],
    [[{ due_on: '2026-10-15', base_amount: 10100, vat_amount: 2000 }], /NEULOŽENO.*základ 10100\.00 CZK.*rozdíl 100\.00 CZK/s],
    [[{ due_on: '2026-10-15', base_amount: 10000, vat_amount: 2000, total_amount: 12100 }], /nedává celkem/],
  ];
  for (const [schedule, message] of cases) {
    const client = calendarClient();
    await assert.rejects(run(client, 'set_invoice_payment_schedule', { invoice_id: 42, payment_schedule: schedule }), message);
    assert.deepEqual(client.writes(), []);
  }
});

test('platební kalendář DUZP splátky odmítne, bez něj rozpis uloží', async () => {
  const draft = calendarDraft({ invoice_type: 'advance_calendar', calendar_contract_ref: null });
  const rejected = calendarClient(draft);
  await assert.rejects(run(rejected, 'set_invoice_payment_schedule', {
    invoice_id: 42,
    payment_schedule: [{ due_on: '2026-10-15', tax_date: '2026-10-15', base_amount: 10000, vat_amount: 2100 }],
  }), /Splátka 1: platební kalendář DUZP nemá/);
  assert.deepEqual(rejected.writes(), []);

  const client = calendarClient(draft);
  await run(client, 'set_invoice_payment_schedule', {
    invoice_id: 42,
    payment_schedule: [{ due_on: '2026-10-15', base_amount: 10000, vat_amount: 2100 }],
  });
  assert.equal(client.writes()[0].body.invoice_type, 'advance_calendar');
  assert.deepEqual(client.writes()[0].body.payment_schedule, [
    { due_on: '2026-10-15', total_amount: 12100, base_amount: 10000, vat_amount: 2100 },
  ]);
});

test('rozpis odmítne chybné řádky i doklad, který není kalendář', async () => {
  const cases = [
    [calendarDraft(), [{ due_on: '2026-02-30', total_amount: 12100 }], /Splátka 1: datum/],
    [calendarDraft(), [{ due_on: '2026-10-15', tax_date: '2026-02-30', total_amount: 12100 }], /Splátka 1: DUZP/],
    [calendarDraft(), [{ due_on: '2026-10-15' }], /Splátka 1: chybí `total_amount`/],
    [calendarDraft(), [{ due_on: '2026-10-15', base_amount: 10000, vat_amount: 2100, total_amount: 12000 }], /nedává celkem/],
    [calendarDraft({ invoice_type: 'invoice' }), [{ due_on: '2026-10-15', total_amount: 12100 }], /jen splátkovému nebo platebnímu kalendáři/],
    [calendarDraft({ invoice_type: 'payment_calendar' }), [{ due_on: '2026-10-15', total_amount: 12100 }], /jen splátkovému nebo platebnímu kalendáři/],
    [calendarDraft({ status: 'issued', varsymbol: '20260042' }), [{ due_on: '2026-10-15', total_amount: 12100 }], /není koncept/],
  ];
  for (const [invoice, schedule, message] of cases) {
    const client = calendarClient(invoice);
    await assert.rejects(
      run(client, 'set_invoice_payment_schedule', { invoice_id: 42, payment_schedule: schedule }),
      message,
    );
    assert.deepEqual(client.writes(), []);
  }
});

test('create_invoice umí kalendář s rozpisem a upozorní na nesouhlasný součet', async () => {
  for (const name of ['create_invoice', 'update_invoice']) {
    const { properties } = tool(name).inputSchema;
    assert.ok(properties.invoice_type.enum.includes('rent_calendar'));
    assert.ok(properties.invoice_type.enum.includes('advance_calendar'));
    assert.ok(!properties.invoice_type.enum.includes('payment_calendar'));
    assert.ok(properties.calendar_contract_ref);
  }
  assert.ok(tool('create_invoice').inputSchema.properties.payment_schedule);

  const args = {
    client_id: 8,
    invoice_type: 'rent_calendar',
    calendar_contract_ref: 'Nájemní smlouva 1/2026',
    items: [{ description: 'Nájem', quantity: 1, unit_price_without_vat: 10000, vat_rate_id: 1 }],
    payment_schedule: [{ due_on: '2026-10-15', base_amount: 5000, vat_amount: 1050 }],
  };
  const client = new FakeClient({ 'POST /invoices': { id: 50, total_with_vat: 12100, currency: 'CZK' } });
  const result = await run(client, 'create_invoice', args);
  assert.deepEqual(client.calls[0].body.payment_schedule, [
    { due_on: '2026-10-15', total_amount: 6050, base_amount: 5000, vat_amount: 1050 },
  ]);
  assert.equal(client.calls[0].body.calendar_contract_ref, 'Nájemní smlouva 1/2026');
  assert.match(result.payment_schedule_warning, /6050\.00 CZK.*12100\.00 CZK/s);

  const wrongVat = new FakeClient({ 'POST /invoices': { id: 52, total_with_vat: 6050, total_without_vat: 5000, total_vat: 1000, currency: 'CZK' } });
  assert.match((await run(wrongVat, 'create_invoice', args)).payment_schedule_warning, /DPH \(1050\.00 CZK.*1000\.00 CZK/s);

  const matching = new FakeClient({ 'POST /invoices': { id: 51, total_with_vat: 6050, currency: 'CZK' } });
  assert.deepEqual(await run(matching, 'create_invoice', args), { id: 51, total_with_vat: 6050, currency: 'CZK' });
});

test('create_invoice bez rozpisu posílá zadání beze změny', async () => {
  const args = { client_id: 8, items: [{ description: 'X', quantity: 1, unit_price_without_vat: 1, vat_rate_id: 1 }] };
  const client = new FakeClient();
  await run(client, 'create_invoice', args);
  assert.equal(client.calls[0].body, args);
});

test('rozpis plateb u jiného typu než kalendáře create_invoice odmítne', async () => {
  const client = new FakeClient();
  await assert.rejects(run(client, 'create_invoice', {
    client_id: 8,
    items: [{ description: 'X', quantity: 1, unit_price_without_vat: 1, vat_rate_id: 1 }],
    payment_schedule: [{ due_on: '2026-10-15', total_amount: 1 }],
  }), /jen dokladu typu `rent_calendar` nebo `advance_calendar`/);
  assert.deepEqual(client.calls, []);
});

// ── ISDOC ──────────────────────────────────────────────────────────────────

test('ISDOC vrací XML jako text', async () => {
  const xml = '<?xml version="1.0"?><Invoice xmlns="http://isdoc.cz/namespace/2013"/>';
  const client = new FakeClient({ 'GET /invoices/42/isdoc': { raw: xml } });
  assert.deepEqual(await run(client, 'get_invoice_isdoc', { id: 42 }), { invoice_id: 42, format: 'ISDOC', xml });
  assert.equal(client.calls[0].path, '/invoices/42/isdoc');
});

// ── Pravidelná fakturace ───────────────────────────────────────────────────

const recurringTemplate = () => ({
  id: 5,
  supplier_id: 1,
  client_id: 8,
  client_company_name: 'Testovací odběratel s.r.o.',
  project_id: 3,
  branding_profile_id: null,
  name: 'Měsíční paušál',
  frequency: 'monthly',
  day_of_month: 5,
  end_of_month: false,
  anchor_date: '2026-01-05',
  end_date: null,
  next_run_date: '2026-10-05',
  last_run_date: '2026-09-05',
  invoice_type: 'invoice',
  currency_id: 1,
  currency: 'CZK',
  language: 'cs',
  payment_method: 'bank_transfer',
  reverse_charge: false,
  prices_include_vat: false,
  discount_percent: 0,
  revenue_category_id: null,
  payment_due_days: 14,
  payment_due_unit: null,
  tax_date_mode: 'same_as_issue',
  draft_open_mode: 'at_issue',
  reminder_days_before: 1,
  note_above_items: null,
  note_below_items: 'Děkujeme',
  increment_month_in_descriptions: true,
  auto_issue: false,
  auto_send_email: false,
  payment_variable_symbol: null,
  status: 'active',
  items: [{
    id: 900, template_id: 5, price_list_item_id: null, catalog_policy: 'fixed',
    description_source: 'template', description: 'Správa serveru', quantity: 1,
    duration_minutes: null, unit: 'měs', unit_price_without_vat: 5000, vat_rate_id: 1,
    vat_classification_code: '1', order_index: 0, stock_item_id: null, warehouse_id: null,
    vat_code: 'standard', vat_rate_percent: 21, oss_applicable: false, oss_consumer_country: null,
  }],
});

test('založení pravidelné fakturace posílá zadání a pořadí položek', async () => {
  const client = new FakeClient();
  await run(client, 'create_recurring_invoice', {
    client_id: 8, name: 'Paušál', frequency: 'monthly', anchor_date: '2026-11-01', currency_id: 1,
    day_of_month: 1,
    items: [
      { description: 'Správa', quantity: 1, unit_price_without_vat: 5000, vat_rate_id: 1 },
      { description: 'Hosting', quantity: 1, unit: 'měs', unit_price_without_vat: 300, vat_rate_id: 1 },
    ],
  });
  assert.equal(client.calls[0].method, 'POST');
  assert.equal(client.calls[0].path, '/recurring');
  assert.deepEqual(client.calls[0].body, {
    client_id: 8, name: 'Paušál', frequency: 'monthly', day_of_month: 1, anchor_date: '2026-11-01', currency_id: 1,
    items: [
      { unit: 'ks', description: 'Správa', quantity: 1, unit_price_without_vat: 5000, vat_rate_id: 1, order_index: 0 },
      { unit: 'měs', description: 'Hosting', quantity: 1, unit_price_without_vat: 300, vat_rate_id: 1, order_index: 1 },
    ],
  });
});

test('úprava pravidelné fakturace pošle celou šablonu a zachová položky', async () => {
  const client = new FakeClient({ 'GET /recurring/5': recurringTemplate() });
  await run(client, 'update_recurring_invoice', { id: 5, end_of_month: true, note_below_items: 'Nová poznámka' });

  const [put] = client.writes();
  assert.equal(put.method, 'PUT');
  assert.equal(put.path, '/recurring/5');
  assert.equal(put.body.client_id, 8);
  assert.equal(put.body.project_id, 3);
  assert.equal(put.body.name, 'Měsíční paušál');
  assert.equal(put.body.anchor_date, '2026-01-05');
  assert.equal(put.body.currency_id, 1);
  assert.equal(put.body.payment_due_days, 14);
  assert.equal(put.body.increment_month_in_descriptions, true);
  assert.equal(put.body.end_of_month, true);
  assert.equal(put.body.day_of_month, null, 'Den v měsíci se s koncem měsíce vylučuje.');
  assert.equal(put.body.note_below_items, 'Nová poznámka');
  assert.equal('status' in put.body, false);
  assert.equal('next_run_date' in put.body, false);
  assert.deepEqual(put.body.items, [{
    price_list_item_id: null, catalog_policy: 'fixed', description_source: 'template',
    description: 'Správa serveru', quantity: 1, duration_minutes: null, unit: 'měs',
    unit_price_without_vat: 5000, vat_rate_id: 1, vat_classification_code: '1', order_index: 0,
    oss_applicable: false, oss_consumer_country: null,
  }]);
});

test('úprava pravidelné fakturace s položkami je nahradí a zakázku jde odebrat', async () => {
  const client = new FakeClient({ 'GET /recurring/5': recurringTemplate() });
  await run(client, 'update_recurring_invoice', {
    id: 5, project_id: 0,
    items: [{ description: 'Nová položka', quantity: 2, unit_price_without_vat: 100, vat_rate_id: 1 }],
  });
  const [put] = client.writes();
  assert.equal(put.body.project_id, null);
  assert.equal(put.body.day_of_month, 5);
  assert.deepEqual(put.body.items, [
    { unit: 'ks', description: 'Nová položka', quantity: 2, unit_price_without_vat: 100, vat_rate_id: 1, order_index: 0 },
  ]);
});

test('přeplánování pošle očekávaný současný termín', async () => {
  const client = new FakeClient({ 'GET /recurring/5': recurringTemplate() });
  await run(client, 'reschedule_recurring_invoice', { id: 5, next_run_date: '2026-10-20' });
  const [post] = client.writes();
  assert.equal(post.path, '/recurring/5/reschedule');
  assert.deepEqual(post.body, { next_run_date: '2026-10-20', expected_next_run_date: '2026-10-05' });
});

test('spuštění šablony bez automatického vystavení ohlásí koncept', async () => {
  const client = new FakeClient({ 'GET /recurring/5': recurringTemplate() });
  await assert.rejects(run(client, 'run_recurring_invoice_now', { id: 5 }), /vznikne koncept.*Měsíční paušál/s);
  assert.deepEqual(client.writes(), []);
});

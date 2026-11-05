import assert from 'node:assert/strict';
import test from 'node:test';

import { TOOLS, TOOLS_BY_NAME } from '../src/tools.mjs';

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

  async postRead(path, body, tool) {
    this.calls.push({ method: 'POST_READ', path, body, tool });
    return this.response('POST_READ', path);
  }

  async put(path, body, tool) {
    this.calls.push({ method: 'PUT', path, body, tool });
    return this.response('PUT', path);
  }

  async patch(path, body, tool) {
    this.calls.push({ method: 'PATCH', path, body, tool });
    return this.response('PATCH', path);
  }

  async del(path, tool, query) {
    this.calls.push({ method: 'DELETE', path, query, tool });
    return this.response('DELETE', path);
  }
}

const tool = (name) => {
  const found = TOOLS_BY_NAME.get(name);
  assert.ok(found, `Nástroj ${name} musí existovat.`);
  return found;
};

test('katalog má unikátní názvy a nové domény', () => {
  assert.equal(new Set(TOOLS.map(({ name }) => name)).size, TOOLS.length);
  for (const name of [
    'save_project', 'project_profitability', 'get_document', 'link_document',
    'save_logbook_car', 'save_logbook_trip', 'save_logbook_fueling', 'logbook_summary',
    'list_payroll_people', 'get_payroll_person', 'change_payroll_salary',
    'list_payroll_components', 'create_payroll_input', 'get_payroll_salary_result',
    'save_payroll_time_entry', 'create_payroll_absence',
  ]) {
    assert.ok(TOOLS_BY_NAME.has(name), name);
  }
  assert.equal(tool('project_profitability').write, false);
  assert.equal(tool('logbook_summary').write, false);
  assert.equal(tool('delete_logbook_trip').destructive, true);
  assert.equal(tool('get_payroll_salary_result').write, false);
  assert.equal(tool('change_payroll_salary').write, true);
  for (const forbidden of [
    'calculate_payroll_run', 'approve_payroll_run', 'post_payroll_run',
    'prepare_payroll_payments', 'close_payroll_run', 'send_payroll_submission',
    'decide_payroll_absence', 'cancel_payroll_absence',
  ]) {
    assert.equal(TOOLS_BY_NAME.has(forbidden), false, forbidden);
  }
});

test('změna mzdy od data posílá jen povolená pole do nové verze', async () => {
  const client = new FakeClient({
    'GET /payroll/people/7': {
      person: {
        id: 7,
        employments: [{
          id: 11,
          row_version: 4,
          monthly_gross_minor: 5000000,
          terms: [{
            id: 20,
            effective_from: '2026-01-01',
            effective_to: null,
            planned_start_on: '2026-01-01',
            weekly_hours: '40.00',
            workload_basis_points: 10000,
            social_insurance_participation: 'automatic',
            health_insurance_participation: 'automatic',
            tax_regime: 'advance',
            tax_declaration_signed: true,
            is_primary: true,
          }],
        }],
      },
    },
  });

  await tool('change_payroll_salary').run(client, {
    employee_id: 7,
    employment_id: 11,
    change_kind: 'new_terms',
    effective_from: '2026-09-01',
    monthly_gross_minor: 5500000,
    reason: 'Navýšení sjednané mzdy',
  }, 'change_payroll_salary');

  assert.deepEqual(client.calls.map(({ method, path }) => [method, path]), [
    ['GET', '/payroll/people/7'],
    ['PUT', '/payroll/employments/11/terms'],
  ]);
  assert.deepEqual(client.calls[1].body, {
    change_reason: 'Navýšení sjednané mzdy',
    row_version: 4,
    monthly_gross_minor: 5500000,
    effective_from: '2026-09-01',
  });
});

test('oprava mzdy používá aktuální verzi bez data účinnosti', async () => {
  const client = new FakeClient({
    'GET /payroll/people/7': {
      person: { id: 7, employments: [{ id: 11, row_version: 4 }] },
    },
  });

  await tool('change_payroll_salary').run(client, {
    employee_id: 7,
    employment_id: 11,
    change_kind: 'correction',
    monthly_gross_minor: 5500000,
    reason: 'Oprava chybně zadané mzdy',
  }, 'change_payroll_salary');

  assert.deepEqual(client.calls.map(({ method, path }) => [method, path]), [
    ['GET', '/payroll/people/7'],
    ['PATCH', '/payroll/employments/11/terms/current'],
  ]);
  assert.deepEqual(client.calls[1].body, {
    change_reason: 'Oprava chybně zadané mzdy',
    row_version: 4,
    monthly_gross_minor: 5500000,
  });
});

test('výsledek mzdy se dohledá přes nejnovější revizi měsíce', async () => {
  const client = new FakeClient({
    'GET /payroll/runs': { runs: [{ id: 3, revision_id: 19, period_start: '2026-08-01' }] },
    'GET /payroll/revisions/19/net-results/7': { net_result: { net_pay_minor: 4123400 } },
  });

  const result = await tool('get_payroll_salary_result').run(client, {
    employee_id: 7,
    period: '2026-08',
  }, 'get_payroll_salary_result');

  assert.equal(result.net_result.net_pay_minor, 4123400);
  assert.deepEqual(client.calls[0].query, { period: '2026-08', limit: 200, offset: 0 });
  assert.equal(client.calls[1].path, '/payroll/revisions/19/net-results/7');
});

test('úprava zakázky zachová nezadaná pole úplného PUT payloadu', async () => {
  const current = {
    client_id: 8,
    name: 'Původní zakázka',
    status: 'active',
    payment_due_days: 14,
    billing_emails: [{ email: 'billing@example.test', position: 1 }],
    billing_emails_mode: 'replace',
  };
  const client = new FakeClient({ 'GET /projects/42': current });

  await tool('save_project').run(client, { id: 42, name: 'Nový název' }, 'save_project');

  assert.deepEqual(client.calls.map(({ method, path }) => [method, path]), [
    ['GET', '/projects/42'],
    ['PUT', '/projects/42'],
  ]);
  assert.deepEqual(client.calls[1].body, { ...current, name: 'Nový název' });
});

test('nová zakázka vyžaduje klienta, název a splatnost', async () => {
  const client = new FakeClient();
  await assert.rejects(
    tool('save_project').run(client, { name: 'Neúplná' }, 'save_project'),
    /client_id, payment_due_days/,
  );
  assert.equal(client.calls.length, 0);
});

test('detail dokumentu načte vytěžený text jen na výslovné vyžádání', async () => {
  const client = new FakeClient({
    'GET /documents/7': { id: 7, title: 'Smlouva' },
    'GET /documents/7/text': { content: 'Vytěžený text', has_more: false },
  });

  const result = await tool('get_document').run(client, {
    id: 7,
    include_text: true,
    text_offset: 100,
    text_max_chars: 5000,
  }, 'get_document');

  assert.equal(result.extracted_text.content, 'Vytěžený text');
  assert.deepEqual(client.calls[1], {
    method: 'GET',
    path: '/documents/7/text',
    query: { offset: 100, max_chars: 5000 },
    tool: 'get_document',
  });
});

test('odpojení dokumentu vyžaduje potvrzení a posílá vazbu v query', async () => {
  const preview = { id: 7, title: 'Smlouva' };
  const client = new FakeClient({ 'GET /documents/7': preview });
  const args = { id: 7, entity_type: 'project', entity_id: 42 };

  await assert.rejects(tool('unlink_document').run(client, args, 'unlink_document'), /NEPROVEDENO/);
  assert.equal(client.calls.some(({ method }) => method === 'DELETE'), false);

  await tool('unlink_document').run(client, { ...args, confirm: true }, 'unlink_document');
  assert.deepEqual(client.calls.at(-1), {
    method: 'DELETE',
    path: '/documents/7/links',
    query: { entity_type: 'project', entity_id: 42 },
    tool: 'unlink_document',
  });
});

test('AI nesmí založit jízdu bez výslovně vybrané kategorie', async () => {
  const client = new FakeClient();
  await assert.rejects(
    tool('save_logbook_trip').run(client, {
      car_id: 3,
      trip_date: '2026-08-24',
      distance_km: 25,
    }, 'save_logbook_trip'),
    /category_id/,
  );
  assert.equal(client.calls.length, 0);

  await tool('save_logbook_trip').run(client, {
    car_id: 3,
    trip_date: '2026-08-24',
    category_id: 2,
    distance_km: 25,
    origin: 'Praha',
    destination: 'Kolín',
  }, 'save_logbook_trip');
  assert.equal(client.calls[0].path, '/logbook/trips');
});

test('úprava tankování zachová původní hodnoty', async () => {
  const current = {
    car_id: 3,
    fueled_date: '2026-08-20',
    fuel_type: 'diesel',
    quantity: 40,
    unit: 'l',
    unit_price: 38,
    amount_with_vat: 1520,
    currency: 'CZK',
    station: 'Původní stanice',
  };
  const client = new FakeClient({ 'GET /logbook/fuelings/9': current });

  await tool('save_logbook_fueling').run(client, { id: 9, station: 'Nová stanice' }, 'save_logbook_fueling');
  assert.deepEqual(client.calls[1].body, { ...current, station: 'Nová stanice' });
});

test('filtry dodavatele a nepřiřazeného vozidla patří k tankování', async () => {
  const client = new FakeClient();
  await tool('list_logbook_fuelings').run(client, {
    vendor_id: 18,
    unassigned: true,
  }, 'list_logbook_fuelings');

  assert.equal(client.calls[0].path, '/logbook/fuelings');
  assert.equal(client.calls[0].query.vendor_id, 18);
  assert.equal(client.calls[0].query.unassigned, 1);
  assert.equal(tool('list_logbook_trips').inputSchema.properties.vendor_id, undefined);
});

test('dávkový detail zboží používá čtecí POST a omezené schéma', async () => {
  const client = new FakeClient();
  const batch = tool('get_products_batch');

  await batch.run(client, {
    ids: [11, 12],
    fields: ['sku', 'availability'],
    locales: ['cs', 'en'],
    currencies: ['CZK'],
    warehouse_ids: [3],
  }, 'get_products_batch');

  assert.equal(batch.write, false);
  assert.equal(batch.inputSchema.properties.ids.maxItems, 500);
  assert.equal(batch.inputSchema.properties.ids.uniqueItems, true);
  assert.equal(batch.inputSchema.properties.fields.minItems, 1);
  assert.equal(batch.inputSchema.properties.locales.maxItems, 20);
  assert.equal(batch.inputSchema.properties.locales.minItems, 1);
  assert.equal(batch.inputSchema.properties.locales.items.pattern, '^[a-z]{2}(?:-[A-Z]{2})?$');
  assert.equal(batch.inputSchema.properties.currencies.maxItems, 10);
  assert.equal(batch.inputSchema.properties.currencies.minItems, 1);
  assert.equal(batch.inputSchema.properties.currencies.items.pattern, '^[A-Za-z]{3}$');
  assert.equal(batch.inputSchema.properties.warehouse_ids.maxItems, 50);
  assert.equal(batch.inputSchema.properties.warehouse_ids.minItems, 1);
  assert.deepEqual(client.calls, [{
    method: 'POST_READ',
    path: '/catalog/products/batch',
    body: {
      ids: [11, 12],
      fields: ['sku', 'availability'],
      locales: ['cs', 'en'],
      currencies: ['CZK'],
      warehouse_ids: [3],
    },
    tool: 'get_products_batch',
  }]);
});

test('dávkové ceny posílají výchozí měnu CZK čtecím POSTem', async () => {
  const client = new FakeClient();

  await tool('get_product_prices_batch').run(client, {
    items: [{ id: 11, qty: '2.5' }, { id: 12, qty: '1' }],
    on_date: '2026-09-09',
  }, 'get_product_prices_batch');

  assert.equal(tool('get_product_prices_batch').write, false);
  assert.equal(tool('get_product_prices_batch').inputSchema.properties.items.maxItems, 500);
  assert.equal(
    tool('get_product_prices_batch').inputSchema.properties.items.items.properties.qty.pattern,
    '^[0-9]{1,11}(?:\\.[0-9]{1,3})?$',
  );
  assert.deepEqual(client.calls, [{
    method: 'POST_READ',
    path: '/catalog/prices/batch',
    body: {
      items: [{ id: 11, qty: '2.5' }, { id: 12, qty: '1' }],
      currency: 'CZK',
      on_date: '2026-09-09',
    },
    tool: 'get_product_prices_batch',
  }]);
});

test('katalogové filtry a průběh úlohy používají čtecí API', async () => {
  const client = new FakeClient();
  await tool('list_products').run(client, { manufacturer_id: 2, vendor_id: 3, category_id: 4, tag_ids: [5, 6], missing: ['image'], active: false }, 'list_products');
  assert.equal(client.calls[0].query.manufacturer_id, 2);
  assert.equal(client.calls[0].query.vendor_id, 3);
  assert.equal(client.calls[0].query.category_id, 4);
  assert.equal(client.calls[0].query.tag_ids, '5,6');
  assert.equal(client.calls[0].query.missing, 'image');
  assert.equal(client.calls[0].query.active, 0);
  await tool('get_catalog_facets').run(client, { manufacturer_id: 2, limit: 50 }, 'get_catalog_facets');
  assert.equal(client.calls[1].path, '/catalog/facets');
  assert.equal(client.calls[1].query.limit, 50);
  await tool('get_catalog_job').run(client, { id: 7 }, 'get_catalog_job');
  assert.equal(client.calls[2].path, '/eshop/jobs/7');
  assert.equal(tool('get_catalog_facets').write, false);
  assert.equal(tool('get_catalog_job').write, false);
});

test('cenové hladiny, balení, individuální ceny a ceník jsou jen ke čtení', () => {
  for (const name of [
    'get_product_packaging', 'get_product_customer_prices', 'get_product_price_levels',
    'quote_product_prices', 'list_price_levels', 'get_price_level', 'list_packaging_units',
    'list_sales_currencies', 'list_eshop_locales', 'list_currencies',
    'get_product_tracking', 'list_stock_locations',
    'list_sales_orders', 'get_sales_order', 'sales_order_shortages',
    'list_cycle_counts', 'get_cycle_count',
    'list_price_list_items', 'get_price_list_item', 'resolve_price_list_item',
  ]) {
    assert.equal(tool(name).write, false, name);
    assert.equal(tool(name).destructive, undefined, name);
  }
  // Odkaz z popisu jiného nástroje musí vést na existující nástroj.
  assert.match(tool('purchase_orders_create').inputSchema.properties.currency_id.description, /list_currencies/);
});

test('nacenění pro odběratele volá čtecí POST s výchozí měnou CZK', async () => {
  const client = new FakeClient();
  const quote = tool('quote_product_prices');

  await quote.run(client, {
    client_id: 8,
    date: '2026-09-13',
    lines: [{ stock_item_id: 11, quantity: '10', unit: 'KT' }],
  }, 'quote_product_prices');

  assert.equal(quote.inputSchema.properties.lines.maxItems, 500);
  assert.deepEqual(quote.inputSchema.required, ['lines']);
  assert.deepEqual(client.calls, [{
    method: 'POST_READ',
    path: '/stock/items/quote',
    body: {
      client_id: 8,
      date: '2026-09-13',
      currency: 'CZK',
      lines: [{ stock_item_id: 11, quantity: '10', unit: 'KT' }],
    },
    tool: 'quote_product_prices',
  }]);
});

test('detail cenové hladiny přidá pravidla, jen když nejsou výslovně vypnutá', async () => {
  const client = new FakeClient({
    'GET /eshop/price-levels/3': { id: 3, code: 'GOLD', name: 'Gold' },
    'GET /eshop/price-levels/3/rules': [{ id: 1, match_type: 'category', match_id: 4 }],
  });

  const withRules = await tool('get_price_level').run(client, { id: 3 }, 'get_price_level');
  assert.deepEqual(withRules.rules, [{ id: 1, match_type: 'category', match_id: 4 }]);
  assert.equal(withRules.code, 'GOLD');

  const bare = await tool('get_price_level').run(client, { id: 3, include_rules: false }, 'get_price_level');
  assert.equal(bare.rules, undefined);
  assert.deepEqual(client.calls.map(({ path }) => path), [
    '/eshop/price-levels/3', '/eshop/price-levels/3/rules', '/eshop/price-levels/3',
  ]);
});

test('skladové čtecí nástroje posílají správné cesty a filtry', async () => {
  const client = new FakeClient();
  await tool('get_product_packaging').run(client, { id: 5 }, 'get_product_packaging');
  await tool('get_product_customer_prices').run(client, { id: 5 }, 'get_product_customer_prices');
  await tool('get_product_price_levels').run(client, { id: 5 }, 'get_product_price_levels');
  await tool('list_price_levels').run(client, { active_only: true }, 'list_price_levels');
  await tool('list_sales_orders').run(client, {
    query: 'OBJ-1', fulfillment_status: 'reserved', shortage: true, limit: 20,
  }, 'list_sales_orders');
  await tool('resolve_price_list_item').run(client, {
    id: 9, currency_id: 2, client_id: 8, prices_include_vat: true,
  }, 'resolve_price_list_item');
  await tool('list_price_list_items').run(client, { currency: 'EUR', prices_include_vat: false }, 'list_price_list_items');

  assert.deepEqual(client.calls.map(({ path }) => path), [
    '/stock/items/5/packaging',
    '/stock/items/5/customer-prices',
    '/stock/items/5/price-levels',
    '/eshop/price-levels',
    '/stock/sales-orders',
    '/price-list-items/9/resolve',
    '/price-list-items',
  ]);
  assert.equal(client.calls[3].query.active, true);
  assert.deepEqual(client.calls[4].query, {
    q: 'OBJ-1',
    commercial_status: undefined,
    payment_status: undefined,
    fulfillment_status: 'reserved',
    shortage: true,
    limit: 20,
    offset: undefined,
  });
  assert.equal(client.calls[5].query.currency_id, 2);
  assert.equal(client.calls[5].query.client_id, 8);
  assert.equal(client.calls[6].query.currency, 'EUR');
  assert.equal(client.calls[6].query.prices_include_vat, 0);
});

// ── Úprava konceptu faktury (#112) ─────────────────────────────────────────

const draftInvoice = (overrides = {}) => ({
  id: 42,
  status: 'draft',
  invoice_type: 'invoice',
  client_id: 8,
  project_id: 3,
  issue_date: '2026-09-30',
  tax_date: '2026-09-30',
  due_date: '2026-10-14',
  currency_id: 1,
  currency: 'CZK',
  reverse_charge: false,
  prices_include_vat: false,
  language: 'cs',
  note_above_items: null,
  note_below_items: 'Děkujeme',
  discount_percent: 10,
  payment_method: 'bank_transfer',
  rounding_mode: 'none',
  varsymbol: null,
  vat_classification_code: '1',
  items: [
    {
      id: 101, description: 'Licence', quantity: 1, duration_minutes: null, unit: 'ks',
      unit_price_without_vat: 12000, vat_rate_id: 1, order_index: 0, item_kind: 'standard',
      vat_classification_code: '1', stock_item_id: null, warehouse_id: null,
      small_asset_id: null, asset_id: null, accrual_from: '2026-10-01', accrual_to: '2027-09-30',
      oss_applicable: false, oss_consumer_country: null, total_with_vat: 14520,
    },
    {
      id: 102, description: 'Konzultace', quantity: 2, duration_minutes: 120, unit: 'h',
      unit_price_without_vat: 1500, vat_rate_id: 1, order_index: 1, item_kind: 'standard',
      vat_classification_code: '1', stock_item_id: null, warehouse_id: null,
      small_asset_id: null, asset_id: null, accrual_from: null, accrual_to: null,
      oss_applicable: false, oss_consumer_country: null,
    },
    {
      id: 103, description: 'E-kniha', quantity: 1, duration_minutes: null, unit: 'ks',
      unit_price_without_vat: 300, vat_rate_id: 5, order_index: 2, item_kind: 'standard',
      vat_classification_code: null, stock_item_id: 9, warehouse_id: 2,
      small_asset_id: null, asset_id: null, accrual_from: null, accrual_to: null,
      oss_applicable: true, oss_consumer_country: 'DE', oss_rate_type: 'reduced',
      oss_supply_type: 'services', oss_needs_manual_review: false,
    },
    { id: 104, description: 'Sleva 10 %', quantity: 1, unit: 'ks', unit_price_without_vat: -1230,
      vat_rate_id: 1, order_index: 3, item_kind: 'discount' },
  ],
  ...overrides,
});

const draftClient = (invoice = draftInvoice()) => new FakeClient({
  'GET /invoices/42': invoice,
  'PUT /invoices/42': { id: 42, status: 'draft', total_with_vat: 9999 },
});

const putOf = (client) => {
  const puts = client.calls.filter((call) => call.method === 'PUT');
  assert.equal(puts.length, 1, 'Má proběhnout právě jeden PUT.');
  assert.equal(puts[0].path, '/invoices/42');
  return puts[0].body;
};

test('úpravy konceptu jsou zápisové a odebrání položky vyžaduje potvrzení', () => {
  for (const name of ['update_invoice', 'add_invoice_item', 'update_invoice_item', 'remove_invoice_item']) {
    assert.equal(tool(name).write, true, name);
  }
  assert.equal(tool('remove_invoice_item').destructive, true);
  assert.equal(tool('update_invoice_item').destructive, undefined);
  // Textový řádek (`kind: "text"`) potřebuje jen popis; ostatní pole hlídá nástroj sám.
  assert.deepEqual(tool('add_invoice_item').inputSchema.required, ['description']);
  assert.ok(tool('remove_invoice_item').inputSchema.properties.confirm);
});

test('změna ceny jedné položky zachová ostatní řádky i jejich skrytá pole', async () => {
  const client = draftClient();
  const result = await tool('update_invoice_item').run(client, {
    invoice_id: 42, row: 1, unit_price_without_vat: 1800,
  }, 'update_invoice_item');

  const body = putOf(client);
  // Hlavička jde celá, jinak by ji PUT přepsal výchozími hodnotami.
  assert.equal(body.client_id, 8);
  assert.equal(body.project_id, 3);
  assert.equal(body.currency_id, 1);
  assert.equal(body.note_below_items, 'Děkujeme');
  assert.equal(body.discount_percent, 10);
  assert.equal(body.exchange_rate, undefined, 'Kurz se bez pokynu neposílá, jinak by se zamkl.');
  // Slevový řádek generuje server, zpět se neposílá.
  assert.equal(body.items.length, 3);
  assert.deepEqual(body.items[0], {
    description: 'Licence', quantity: 1, duration_minutes: null, unit: 'ks',
    unit_price_without_vat: 1800, vat_rate_id: 1, order_index: 0, item_kind: 'standard', vat_classification_code: '1',
    stock_item_id: null, warehouse_id: null, small_asset_id: null, asset_id: null,
    accrual_from: '2026-10-01', accrual_to: '2027-09-30',
    oss_applicable: false, oss_consumer_country: null,
  });
  assert.equal(body.items[1].duration_minutes, 120);
  assert.equal(body.items[2].oss_consumer_country, 'DE');
  assert.equal(body.items[2].oss_rate_type, 'reduced');
  assert.equal(body.items[2].stock_item_id, 9);
  assert.equal(body.items[2].warehouse_id, 2);
  assert.equal(body.items[2].order_index, 2);
  assert.equal(result.row, 1);
  assert.equal(result.before.unit_price_without_vat, 12000);
  assert.deepEqual(result.invoice, { id: 42, status: 'draft', total_with_vat: 9999 });
});

test('změna sazby nechá server odvodit zařazení jen u upravené položky', async () => {
  const client = draftClient();
  await tool('update_invoice_item').run(client, { invoice_id: 42, item_id: 103, vat_rate_id: 1 }, 'update_invoice_item');

  const body = putOf(client);
  assert.equal(body.items[2].vat_rate_id, 1);
  assert.equal('vat_classification_code' in body.items[2], false);
  assert.equal('oss_applicable' in body.items[2], false);
  assert.equal('oss_consumer_country' in body.items[2], false);
  assert.equal(body.items[2].stock_item_id, 9);
  assert.equal(body.items[0].vat_classification_code, '1');
  assert.equal(body.items[0].oss_applicable, false);
});

test('změna množství časové položky přepočítá délku v minutách', async () => {
  const client = draftClient();
  await tool('update_invoice_item').run(client, { invoice_id: 42, row: 2, quantity: 3.5 }, 'update_invoice_item');
  const body = putOf(client);
  assert.equal(body.items[1].quantity, 3.5);
  assert.equal(body.items[1].duration_minutes, 210);
});

test('prázdný text zruší časové rozlišení položky', async () => {
  const client = draftClient();
  await tool('update_invoice_item').run(client, { invoice_id: 42, row: 1, accrual_from: '', accrual_to: '' }, 'update_invoice_item');
  const body = putOf(client);
  assert.equal(body.items[0].accrual_from, null);
  assert.equal(body.items[0].accrual_to, null);
});

test('přidání položky ji vloží na zadané místo a přečísluje pořadí', async () => {
  const client = draftClient();
  const result = await tool('add_invoice_item').run(client, {
    invoice_id: 42, description: 'Doprava', quantity: 1, unit_price_without_vat: 350, vat_rate_id: 1, position: 2,
  }, 'add_invoice_item');

  const body = putOf(client);
  assert.deepEqual(body.items.map((item) => [item.description, item.order_index]), [
    ['Licence', 0], ['Doprava', 1], ['Konzultace', 2], ['E-kniha', 3],
  ]);
  // Nová položka nemá OSS ani klasifikaci, ty odvodí server.
  assert.deepEqual(body.items[1], {
    description: 'Doprava', quantity: 1, unit: 'ks', unit_price_without_vat: 350, vat_rate_id: 1, order_index: 1,
  });
  assert.equal(body.items[0].accrual_to, '2027-09-30');
  assert.equal(result.added.row, 2);
});

test('odebrání položky bez potvrzení nic nezmění', async () => {
  const client = draftClient();
  await assert.rejects(
    tool('remove_invoice_item').run(client, { invoice_id: 42, row: 3 }, 'remove_invoice_item'),
    /NEPROVEDENO.*E-kniha/s,
  );
  assert.equal(client.calls.some((call) => call.method === 'PUT'), false);
});

test('odebrání položky s potvrzením pošle zbylé řádky beze změny', async () => {
  const client = draftClient();
  const result = await tool('remove_invoice_item').run(client, { invoice_id: 42, row: 3, confirm: true }, 'remove_invoice_item');
  const body = putOf(client);
  assert.deepEqual(body.items.map((item) => item.description), ['Licence', 'Konzultace']);
  assert.equal(body.items[0].accrual_from, '2026-10-01');
  assert.equal(result.removed.description, 'E-kniha');
});

const textRow = (overrides = {}) => ({
  id: 104, description: 'Oddíl B: montáž', quantity: 0, duration_minutes: null, unit: '',
  unit_price_without_vat: 0, vat_rate_id: 1, order_index: 3, item_kind: 'text',
  vat_classification_code: null, stock_item_id: null, warehouse_id: null,
  small_asset_id: null, asset_id: null, accrual_from: null, accrual_to: null,
  oss_applicable: false, oss_consumer_country: null, ...overrides,
});

test('textový řádek přežije úpravu jiné položky jako textový', async () => {
  const invoice = draftInvoice();
  invoice.items = [...invoice.items, textRow()];
  const client = draftClient(invoice);
  await tool('update_invoice_item').run(client, { invoice_id: 42, row: 1, unit_price_without_vat: 1800 }, 'update_invoice_item');

  const body = putOf(client);
  assert.equal(body.items.length, 4);
  assert.equal(body.items[3].item_kind, 'text');
  assert.equal(body.items[3].description, 'Oddíl B: montáž');
});

test('textový řádek se přidá jen s popisem na zadané místo', async () => {
  const client = draftClient();
  const result = await tool('add_invoice_item').run(client, {
    invoice_id: 42, kind: 'text', description: '  Materiál  ', position: 2,
  }, 'add_invoice_item');

  const body = putOf(client);
  assert.deepEqual(body.items[1], { item_kind: 'text', description: 'Materiál', order_index: 1 });
  assert.equal(body.items.length, 4);
  assert.equal(result.added.row, 2);
});

test('běžná položka bez množství, ceny nebo sazby se nepřidá', async () => {
  for (const missing of ['quantity', 'unit_price_without_vat', 'vat_rate_id']) {
    const args = { invoice_id: 42, description: 'Doprava', quantity: 1, unit_price_without_vat: 350, vat_rate_id: 1 };
    delete args[missing];
    const client = draftClient();
    await assert.rejects(tool('add_invoice_item').run(client, args, 'add_invoice_item'), new RegExp(missing === 'quantity' ? 'Množství' : missing === 'vat_rate_id' ? 'sazb' : 'cen', 'i'), missing);
    assert.equal(client.calls.some((call) => call.method === 'PUT'), false, missing);
  }
});

test('textovému řádku jde změnit jen text', async () => {
  const invoice = draftInvoice();
  invoice.items = [...invoice.items, textRow()];
  const client = draftClient(invoice);
  await assert.rejects(
    tool('update_invoice_item').run(client, { invoice_id: 42, row: 4, quantity: 2 }, 'update_invoice_item'),
    /Textový řádek/,
  );
  assert.equal(client.calls.some((call) => call.method === 'PUT'), false);

  await tool('update_invoice_item').run(client, { invoice_id: 42, row: 4, description: 'Oddíl C' }, 'update_invoice_item');
  assert.equal(putOf(client).items[3].description, 'Oddíl C');
});

test('poslední položku vedle textových řádků odebrat nejde', async () => {
  const invoice = draftInvoice();
  invoice.items = [invoice.items[0], textRow()];
  const client = draftClient(invoice);
  await assert.rejects(
    tool('remove_invoice_item').run(client, { invoice_id: 42, row: 1, confirm: true }, 'remove_invoice_item'),
    /jen tuto položku/,
  );
  assert.equal(client.calls.some((call) => call.method === 'PUT'), false);
});

test('jedinou položku odebrat nejde', async () => {
  const single = draftInvoice();
  single.items = [single.items[0]];
  const client = draftClient(single);
  await assert.rejects(
    tool('remove_invoice_item').run(client, { invoice_id: 42, row: 1, confirm: true }, 'remove_invoice_item'),
    /jen tuto položku/,
  );
  assert.equal(client.calls.some((call) => call.method === 'PUT'), false);
});

test('úprava hlavičky změní jen zadaná pole a zachová položky', async () => {
  const client = draftClient();
  const result = await tool('update_invoice').run(client, {
    invoice_id: 42, due_date: '2026-10-28', note: 'Splatnost prodloužena',
  }, 'update_invoice');

  const body = putOf(client);
  assert.equal(body.due_date, '2026-10-28');
  assert.equal(body.note_below_items, 'Splatnost prodloužena');
  assert.equal(body.issue_date, '2026-09-30');
  assert.equal(body.currency_id, 1);
  assert.equal(body.items.length, 3);
  assert.equal(body.items[0].vat_classification_code, '1');
  assert.equal(body.items[2].oss_consumer_country, 'DE');
  assert.deepEqual(result.changed, { due_date: '2026-10-28', note_below_items: 'Splatnost prodloužena' });
});

test('změna DUZP nechá server znovu odvodit zařazení všech položek', async () => {
  const client = draftClient();
  await tool('update_invoice').run(client, { invoice_id: 42, tax_date: '2027-01-05' }, 'update_invoice');
  const body = putOf(client);
  for (const item of body.items) {
    assert.equal('vat_classification_code' in item, false);
    assert.equal('oss_applicable' in item, false);
  }
  assert.equal(body.items[0].accrual_from, '2026-10-01');
});

test('změna měny se posílá kódem a zakázku jde odebrat nulou', async () => {
  const client = draftClient();
  await tool('update_invoice').run(client, { invoice_id: 42, currency: 'eur', project_id: 0 }, 'update_invoice');
  const body = putOf(client);
  assert.equal(body.currency, 'EUR');
  assert.equal('currency_id' in body, false);
  assert.equal(body.project_id, null);
});

test('vystavenou fakturu úpravy odmítnou ještě před zápisem', async () => {
  for (const [name, args] of [
    ['update_invoice', { invoice_id: 42, due_date: '2026-11-01' }],
    ['add_invoice_item', { invoice_id: 42, description: 'X', quantity: 1, unit_price_without_vat: 1, vat_rate_id: 1 }],
    ['update_invoice_item', { invoice_id: 42, row: 1, quantity: 2 }],
    ['remove_invoice_item', { invoice_id: 42, row: 1, confirm: true }],
  ]) {
    const client = draftClient(draftInvoice({ status: 'issued', varsymbol: '20260042' }));
    await assert.rejects(tool(name).run(client, args, name), /není koncept.*dobropis/s, name);
    assert.equal(client.calls.some((call) => call.method === 'PUT'), false, name);
  }
});

test('koncept podle odběratele: při více konceptech se nehádá', async () => {
  const client = new FakeClient({
    'GET /clients': { data: [{ id: 8, company_name: 'ACME s.r.o.' }] },
    'GET /invoices': { data: [{ id: 42 }, { id: 43 }] },
  });
  await assert.rejects(
    tool('update_invoice').run(client, { client: 'ACME', due_date: '2026-11-01' }, 'update_invoice'),
    /Konceptů je víc: #42, #43/,
  );
  assert.equal(client.calls.some((call) => call.method === 'PUT'), false);
});

for (const changeKind of ['new_terms', 'correction']) {
  test(`hodinový vztah odmítne měsíční změnu ${changeKind} před jakýmkoli zápisem`, async () => {
    const client = new FakeClient({ 'GET /payroll/people/7': { person: { id: 7, employments: [{ id: 11, row_version: 4, terms: [{ remuneration_type: 'hourly', hourly_rate_minor: 18050 }] }] } } });
    await assert.rejects(tool('change_payroll_salary').run(client, { employee_id: 7, employment_id: 11, change_kind: changeKind, effective_from: '2026-12-01', monthly_gross_minor: 5000000, reason: 'Syntetická změna' }, 'change_payroll_salary'), /hodinové odměňování.*pouze měsíční mzdu/s);
    assert.deepEqual(client.calls.map(({ method }) => method), ['GET']);
  });
}
for (const mode of [null, 'monthly']) {
  test(`měsíční změna zachová dosavadní režim ${mode}`, async () => {
    const client = new FakeClient({ 'GET /payroll/people/7': { person: { id: 7, employments: [{ id: 11, row_version: 4, terms: [{ remuneration_type: mode }] }] } } });
    await tool('change_payroll_salary').run(client, { employee_id: 7, employment_id: 11, change_kind: 'correction', monthly_gross_minor: 5000000, reason: 'Syntetická oprava' }, 'change_payroll_salary');
    assert.equal(client.calls[1].method, 'PATCH');
    assert.equal(Object.hasOwn(client.calls[1].body, 'remuneration_type'), false);
    assert.equal(client.calls[1].body.monthly_gross_minor, 5000000);
  });
}
test('detail zaměstnance předá nullable režim, sazbu a druh podkladu bez odvození měsíční mzdy', async () => {
  const detail = { person: { id: 7, employments: [{ id: 11, monthly_gross_minor: null, terms: [{ remuneration_type: 'hourly', hourly_rate_minor: 18050, remuneration_basis_kind: 'agreement', remuneration_basis: 'Syntetická dohoda' }] }, { id: 12, monthly_gross_minor: null, terms: [{ remuneration_type: null, hourly_rate_minor: null }] }] } };
  const client = new FakeClient({ 'GET /payroll/people/7': detail });
  assert.deepEqual(await tool('get_payroll_person').run(client, { employee_id: 7 }, 'get_payroll_person'), detail);
});
test('založení osoby nenutí měsíční režim a nepředstírá podporu hodinového založení', async () => {
  const client = new FakeClient();
  await tool('create_payroll_person').run(client, { full_name: 'Syntetická Osoba', birth_number: '9001010007', relation_type: 'dpp', planned_start_on: '2026-12-15' }, 'create_payroll_person');
  assert.equal(Object.hasOwn(client.calls[0].body, 'remuneration_type'), false);
  assert.equal(client.calls[0].body.monthly_gross, undefined);
  assert.equal(tool('create_payroll_person').inputSchema.properties.hourly_rate_minor, undefined);
  assert.match(tool('get_payroll_person').description, /NULL\/monthly\/hourly/);
});

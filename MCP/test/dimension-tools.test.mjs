import assert from 'node:assert/strict';
import test from 'node:test';
import { DIMENSION_TOOLS } from '../src/dimension-tools.mjs';
import { TOOLS_BY_NAME } from '../src/tools.mjs';
import { validateToolArguments } from '../src/tool-args.mjs';

async function call(name, args) {
  let request;
  const client = { get: async (path, query, tool) => { request = { path, query, tool }; return { data: [] }; } };
  await TOOLS_BY_NAME.get(name).run(client, args, name);
  return request;
}

test('saldo SVJ zachová oba časy, kurzor a neúplné nebo neurčité částky', async () => {
  const name = 'list_property_saldo';
  const tool = TOOLS_BY_NAME.get(name);
  const args = { as_of: '2026-10-17T12:34:56.123456+02:00', known_at: '2026-10-18T00:00:00Z', cursor: 'opaque-unit-page', limit: 25 };
  assert.equal(tool.write, false);
  assert.deepEqual(validateToolArguments(tool, args), args);
  assert.deepEqual(await call(name, args), { path: '/property/catalog/payments/saldo', query: args, tool: name });
  const result = { items: [{ unit_id: 7, remaining_cents: null }], next_cursor: 'opaque-next', coverage: { complete: false }, consistency: 'request_snapshot' };
  assert.equal(await tool.run({ get: async () => result }, args, name), result);
  for (const invalid of [{ ...args, known_at: undefined }, { ...args, as_of: '2026-10-17' }, { ...args, limit: 0 }, { ...args, limit: 101 }, { ...args, cursor: 7 }, { ...args, supplier_id: 9 }]) {
    assert.throws(() => validateToolArguments(tool, invalid));
  }
});

test('vydané předpisy SVJ mají pouze kurátorované čtecí nástroje', async () => {
  assert.equal((await call('list_property_charge_issues', {})).path, '/property/catalog/charges/issues');
  assert.equal((await call('get_property_charge_issue', { id: 42 })).path, '/property/catalog/charges/issues/42');
  for (const name of ['list_property_charge_issues', 'get_property_charge_issue']) {
    const tool = TOOLS_BY_NAME.get(name);
    assert.equal(tool.write, false);
    assert.equal(tool.inputSchema.additionalProperties, false);
  }
  const detail = TOOLS_BY_NAME.get('get_property_charge_issue');
  assert.deepEqual(validateToolArguments(detail, { id: 42 }), { id: 42 });
  assert.throws(() => validateToolArguments(detail, { id: 0 }));
  assert.throws(() => validateToolArguments(detail, { id: 42, snapshot: {} }));
});

test('sazby SVJ používají výhradně kurátorované čtecí cesty a přesné časové vstupy', async () => {
  assert.equal((await call('list_property_charge_components', {})).path, '/property/catalog/charges/components');
  assert.equal((await call('get_property_charge_version', { id: 42 })).path, '/property/catalog/charges/versions/42');
  const args = { house_id: 7, as_of: '2026-10-17T12:34:56.123456+02:00', known_at: '2026-10-18T00:00:00Z' };
  const request = await call('list_property_charge_versions', args);
  assert.equal(request.path, '/property/catalog/charges/versions');
  assert.deepEqual(request.query, args);
  const tool = TOOLS_BY_NAME.get('list_property_charge_versions');
  assert.deepEqual(validateToolArguments(tool, args), args);
  assert.throws(() => validateToolArguments(tool, { ...args, as_of: '2026-10-17' }));
  assert.throws(() => validateToolArguments(tool, { ...args, house_id: 0 }));
});

test('všechny dimenzní nástroje jsou čtecí a zapojené do společného katalogu', () => {
  for (const tool of DIMENSION_TOOLS) {
    assert.equal(tool.write, false);
    assert.equal(TOOLS_BY_NAME.get(tool.name), tool);
    assert.equal(tool.inputSchema.additionalProperties, false);
  }
});

test('registr SVJ předává přesný okamžik a kurátorovaný číselník bez zápisové cesty', async () => {
  const as_of = '2026-10-17T12:34:56.123456+02:00';
  const known_at = '2026-10-18T00:00:00Z';
  for (const [name, path] of [['property_registry_profile', 'profile'], ['property_registry_summary', 'summary']]) {
    const request = await call(name, { as_of, known_at });
    assert.equal(request.path, `/property/catalog/${path}`);
    assert.deepEqual(request.query, { as_of, known_at });
    assert.deepEqual(TOOLS_BY_NAME.get(name).inputSchema.required, ['as_of']);
  }
  for (const kind of ['houses', 'units', 'services']) {
    const request = await call('list_property_registry', { kind, as_of });
    assert.equal(request.path, `/property/catalog/${kind}`);
    assert.equal(request.query.as_of, as_of);
    assert.equal(request.query.known_at, undefined);
  }
  assert.deepEqual(TOOLS_BY_NAME.get('list_property_registry').inputSchema.required, ['kind', 'as_of']);
  const tool = TOOLS_BY_NAME.get('property_registry_profile');
  assert.deepEqual(validateToolArguments(tool, { as_of, known_at }), { as_of, known_at });
  for (const invalid of ['2026-10-17', '2026-10-17T12:34:56', '2026-10-17T12:34:56.1234567Z', `${known_at}\n`]) {
    assert.throws(() => validateToolArguments(tool, { as_of: invalid }));
  }
});

test('společné alokace poskytují čtení s přesnými kurzory a cílovým datem', async () => {
  assert.equal((await call('list_joint_allocation_keys', {})).path, '/accounting/joint-allocations/keys');
  const candidates = await call('list_joint_allocation_candidates', { target_date: '2026-10-17', from: '2026-01-01', to: '2026-09-30', search: 'synthetic', before_id: 500 });
  assert.equal(candidates.path, '/accounting/joint-allocations/candidates');
  assert.deepEqual(candidates.query, { target_date: '2026-10-17', from: '2026-01-01', to: '2026-09-30', search: 'synthetic', before_id: 500 });
  assert.deepEqual((await call('list_joint_allocations', { before_id: 100 })).query, { before_id: 100 });
  assert.equal((await call('get_joint_allocation', { id: 42 })).path, '/accounting/joint-allocations/batches/42');
});

test('daňové posouzení poskytuje pouze aktuální podklady, historii a konkrétní revizi', async () => {
  assert.equal((await call('nonprofit_tax_assessment', { period_id: 7 })).path, '/accounting/periods/7/nonprofit-tax/summary');
  assert.deepEqual(await call('nonprofit_tax_assessment_history', { period_id: 7, after: 'opaque', limit: 50 }),
    { path: '/accounting/periods/7/nonprofit-tax/history', query: { after: 'opaque', limit: 50 }, tool: 'nonprofit_tax_assessment_history' });
  assert.equal((await call('get_nonprofit_tax_assessment', { assessment_id: 42 })).path, '/accounting/nonprofit-tax-assessments/42');
  assert.deepEqual(await call('nonprofit_tax_assessment_page', { assessment_id: 42, kind: 'result', limit: 10 }),
    { path: '/accounting/nonprofit-tax-assessments/42/result', query: { after: undefined, limit: 10 }, tool: 'nonprofit_tax_assessment_page' });
  assert.equal(TOOLS_BY_NAME.get('export_nonprofit_tax_assessment'), undefined);
  for (const name of ['nonprofit_tax_assessment', 'nonprofit_tax_assessment_history', 'get_nonprofit_tax_assessment', 'nonprofit_tax_assessment_page']) {
    assert.equal(TOOLS_BY_NAME.get(name).write, false);
  }
  assert.throws(() => validateToolArguments(TOOLS_BY_NAME.get('nonprofit_tax_assessment_page'), { assessment_id: 42, kind: 'draft' }));
  assert.throws(() => validateToolArguments(TOOLS_BY_NAME.get('nonprofit_tax_assessment_history'), { period_id: 7, limit: 101 }));
});

test('statistika skupiny používá all bez kolize s kontextem firmy hosted MCP', async () => {
  assert.equal(TOOLS_BY_NAME.get('dimension_analytics').inputSchema.properties.supplier_id, undefined);
  const group = await call('dimension_analytics', { type_id: 3, year: 2026, scope: 'group' });
  assert.deepEqual(group.query, { type_id: 3, year: 2026, supplier_id: 'all' });
  const company = await call('dimension_analytics', { type_id: 3, year: 2026, scope: 'company' });
  assert.equal(company.query.supplier_id, undefined);
});

test('rozvaha, výsledovky, obratovka a hlavní kniha zachovají false pro potomky dimenze', async () => {
  for (const name of ['balance_sheet', 'income_statement', 'income_statement_by_function', 'trial_balance', 'general_ledger', 'statement_accounts', 'list_journal_entries']) {
    const tool = TOOLS_BY_NAME.get(name);
    assert.equal(tool.inputSchema.properties.dimension_value_id.minimum, 1);
    const request = await call(name, { period_id: 7, dimension_value_id: 11, dimension_descendants: false });
    assert.equal(request.query.dimension_value_id, 11);
    assert.equal(request.query.dimension_descendants, 0);
    assert.equal((await call(name, { period_id: 7 })).query.dimension_descendants, undefined);
  }
});

test('zisk a cash flow předávají rozsah, globální skupinu a rozpad', async () => {
  const profit = await call('dimension_profit', { type_id: 3, from: '2026-01-01', to: '2026-12-31', scope: 'group', value_id: 11, accounts: true, companies: true });
  assert.equal(profit.path, '/accounting/reports/dimension-profit');
  assert.equal(profit.query.scope, 'group');
  assert.equal(profit.query.value_id, 11);
  assert.equal(profit.query.accounts, 1);
  assert.equal(profit.query.companies, 1);
  const cash = await call('dimension_cash_flow', { from: '2026-01-01', to: '2026-12-31', scope: 'group', dimension_value_id: 11, dimension_descendants: false });
  assert.equal(cash.path, '/accounting/reports/dimension-cash-flow');
  assert.equal(cash.query.dimension_descendants, 0);
  assert.equal(cash.query.from, '2026-01-01');
  assert.equal(cash.query.to, '2026-12-31');
  assert.equal(cash.query.scope, 'group');
});

test('výpis účetního účtu používá povinná data místo nepodporovaného period_id', async () => {
  const tool = TOOLS_BY_NAME.get('account_statement');
  assert.deepEqual(tool.inputSchema.required, ['account_id', 'from', 'to']);
  const request = await call('account_statement', { account_id: 9, from: '2026-01-01', to: '2026-06-30', page: 2, per_page: 25, after_closing: true, counter_account_id: 12 });
  assert.equal(request.path, '/accounting/reports/account-statement/9');
  assert.deepEqual(request.query, { from: '2026-01-01', to: '2026-06-30', page: 2, per_page: 25, after_closing: 1, counter_account_id: 12 });
});

test('seznamy dokladů vracejí dimenze jen na explicitní požadavek', async () => {
  for (const name of ['list_invoices', 'list_purchase_invoices', 'list_journal_entries']) {
    const request = await call(name, { include_dimensions: true });
    const query = name === 'list_journal_entries' ? request.query : request.query.filter;
    assert.equal(query.include_dimensions, 1);
    const excluded = await call(name, { include_dimensions: false });
    assert.equal((name === 'list_journal_entries' ? excluded.query : excluded.query.filter).include_dimensions, 0);
  }
});

test('CRM přehledy předávají podporovaný počet měsíců a měnu', async () => {
  for (const name of ['revenue_monthly', 'top_clients', 'top_vendors', 'revenue_breakdown', 'expense_breakdown']) {
    const tool = TOOLS_BY_NAME.get(name);
    assert.equal(tool.inputSchema.properties.from, undefined);
    assert.equal(tool.inputSchema.properties.months.maximum, 36);
    const request = await call(name, { months: 6, currency: 'EUR', limit: 5 });
    assert.equal(request.query.months, 6);
    assert.equal(request.query.currency, 'EUR');
    assert.equal(request.query.from, undefined);
  }
  assert.deepEqual((await call('revenue_overview', {})).query, null);
});

test('peněžní deník skutečně filtruje požadovaná data', async () => {
  const request = await call('cash_journal', { from: '2026-02-01', to: '2026-03-31' });
  assert.equal(request.query.from, '2026-02-01');
  assert.equal(request.query.to, '2026-03-31');
  assert.equal(request.query.date_from, undefined);
});

test('historické neuhrazené doklady a nezaúčtované doklady zachovají filtry', async () => {
  for (const name of ['list_invoices', 'list_purchase_invoices']) {
    const request = await call(name, { unpaid_as_of: '2026-06-30', booked: false, group_by_month: false, include_vat_breakdown: true });
    assert.equal(request.query.filter.unpaid_as_of, '2026-06-30');
    assert.equal(request.query.filter.booked, 0);
    assert.equal(request.query.filter.group_by_month, 0);
    assert.equal(request.query.filter.include_vat_breakdown, 1);
  }
  const purchase = await call('list_purchase_invoices', { payment_ordered: false, paid_shortfall: true, document_kind: 'invoice,credit_note' });
  assert.equal(purchase.query.filter.payment_ordered, 0);
  assert.equal(purchase.query.filter.paid_shortfall, true);
  assert.equal(purchase.query.filter.document_kind, 'invoice,credit_note');
});

test('historie podání stránkuje skutečným offsetem', async () => {
  const request = await call('list_tax_submissions', { limit: 25, offset: 50, status: 'accepted', form_code: 'dphdp3' });
  assert.deepEqual(request.query, { limit: 25, offset: 50, status: 'accepted', form_code: 'dphdp3' });
  assert.equal(TOOLS_BY_NAME.get('list_tax_submissions').inputSchema.properties.page, undefined);
});

test('e-shopová změna karty vyžaduje a předává aktuální verzi', async () => {
  const tool = TOOLS_BY_NAME.get('update_product_card');
  assert.ok(tool.inputSchema.required.includes('row_version'));
  let request;
  await tool.run({ put: async (path, body) => { request = { path, body }; return {}; } },
    { id: 11, row_version: 7, weight_g: 150 }, tool.name);
  assert.deepEqual(request, { path: '/eshop/products/11', body: { row_version: 7, weight_g: 150 } });
});

test('pojistné vždy používá přiznání fyzické osoby', async () => {
  const tool = TOOLS_BY_NAME.get('tax_return_insurance');
  assert.equal(tool.inputSchema.properties.type, undefined);
  assert.equal((await call('tax_return_insurance', { year: 2026, type: 'po' })).path, '/tax-return/fo/2026/insurance');
});

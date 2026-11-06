import assert from 'node:assert/strict';
import test from 'node:test';
import { AUDIT_TOOLS } from '../src/audit-tools.mjs';
import { TOOLS } from '../src/tools.mjs';

class ReadClient {
  calls = [];
  async get(path, query, tool) {
    this.calls.push({ method: 'GET', path, query, tool });
    return { ok: true };
  }
  async postRead(path, body, tool) {
    this.calls.push({ method: 'POST_READ', path, body, tool });
    return { ok: true };
  }
}

const tool = (name) => {
  const found = AUDIT_TOOLS.find((entry) => entry.name === name);
  assert.ok(found, name);
  return found;
};

const routes = [
  ['accounting_fx_preview', { period_id: 5 }, '/accounting/periods/5/closing/fx-preview'],
  ['cash_flow_statement', { period_id: 5 }, '/accounting/reports/section18-statements'],
  ['balance_inventory', { period_id: 5 }, '/accounting/reports/balance-inventory'],
  ['document_completeness', {}, '/accounting/reports/document-completeness'],
  ['year_end_tax_estimate', { period_id: 5 }, '/accounting/reports/statement-accounts/tax-estimate'],
  ['portfolio_overview', {}, '/portfolio/overview'],
  ['group_dashboard', { section: 'forecast', months: 24, weeks: 12, from: '2094-03-15', to: '2094-06-10' }, '/portfolio/group-dashboard'],
  ['portfolio_monthly_check', { company_id: 11 }, '/portfolio/monthly-check/11'],
  ['automation_overview', {}, '/automation/overview'],
  ['automation_stats', {}, '/automation/stats'],
  ['automation_checklist', {}, '/automation/checklist'],
  ['automation_feed', {}, '/automation/feed'],
  ['automation_history', {}, '/automation/history'],
  ['automation_counts', {}, '/automation/counts'],
  ['automation_recommendations', {}, '/automation/recommendations'],
  ['get_tax_return', { type: 'po', year: 2026 }, '/tax-return/po/2026'],
  ['tax_return_check', { type: 'po', year: 2026 }, '/tax-return/po/2026/prefinalize-check'],
  ['tax_return_xml_preview', { type: 'fo', year: 2026 }, '/tax-return/fo/2026/xml/preview'],
  ['tax_return_insurance', { year: 2026 }, '/tax-return/fo/2026/insurance'],
  ['list_tax_advances', { type: 'po', year: 2026 }, '/tax-return/po/2026/advances'],
  ['upcoming_tax_advances', {}, '/tax-return/advances/upcoming'],
  ['tax_evidence_receivables_payables', {}, '/tax-evidence/receivables-payables'],
  ['tax_evidence_closing', { year: 2026 }, '/tax-evidence/closing/2026'],
  ['tax_evidence_transition_report', {}, '/tax-evidence/transition-report'],
  ['cnb_rate_audit', {}, '/reports/cnb-rate-audit'],
  ['invoice_series_completeness', {}, '/reports/invoice-series-completeness'],
  ['oss_return_preview', {}, '/reports/oss/preview'],
  ['oss_threshold', {}, '/reports/oss/threshold'],
  ['accounting_closing_status', { period_id: 5 }, '/accounting/periods/5/closing'],
  ['accounting_monthly_check', { period_id: 5 }, '/accounting/periods/5/monthly-check'],
  ['list_assets', {}, '/accounting/assets'],
  ['get_asset', { id: 9 }, '/accounting/assets/9'],
  ['asset_depreciation_plan', { id: 9 }, '/accounting/assets/9/depreciation-plan'],
  ['list_cash_registers', {}, '/accounting/cash-registers'],
  ['get_cash_register', { id: 9 }, '/accounting/cash-registers/9'],
  ['list_cash_documents', {}, '/accounting/cash-documents'],
  ['get_cash_document', { id: 9 }, '/accounting/cash-documents/9'],
  ['list_bank_statements', {}, '/bank-statements'],
  ['get_bank_statement', { id: 9 }, '/bank-statements/9'],
  ['bank_account_balances', {}, '/bank-statements/account-balances'],
  ['list_bank_match_suggestions', { id: 9 }, '/bank-statements/9/match-suggestions'],
  ['list_recurring_invoices', {}, '/recurring'],
  ['get_recurring_invoice', { id: 9 }, '/recurring/9'],
  ['recurring_invoice_history', { id: 9 }, '/recurring/9/invoices'],
  ['list_document_requests', {}, '/document-requests'],
  ['get_document_request', { id: 9 }, '/document-requests/9'],
  ['catalog_changes', {}, '/catalog/changes'],
  ['stock_sales_report', {}, '/stock/reports/sales'],
  ['intrastat_preview', { period: '2026-09', direction: 'arrival' }, '/stock/intrastat/preview', 'POST_READ'],
];

test('nový katalog je pouze čtecí, uzavřený a má unikátní názvy', () => {
  const names = TOOLS.map((entry) => entry.name);
  assert.equal(new Set(names).size, names.length);
  for (const entry of AUDIT_TOOLS) {
    assert.equal(entry.write, false, entry.name);
    assert.notEqual(entry.destructive, true, entry.name);
    assert.equal(entry.inputSchema.additionalProperties, false, entry.name);
    assert.ok(!Object.hasOwn(entry.inputSchema.properties, 'path'), entry.name);
    assert.ok(!Object.hasOwn(entry.inputSchema.properties, 'method'), entry.name);
    for (const required of entry.inputSchema.required) {
      assert.ok(Object.hasOwn(entry.inputSchema.properties, required), `${entry.name}.${required}`);
    }
  }
});

test('každý přidaný nástroj volá konkrétní čtecí veřejnou cestu', async () => {
  assert.equal(routes.length, AUDIT_TOOLS.length);
  for (const [name, args, path, method = 'GET'] of routes) {
    const client = new ReadClient();
    assert.deepEqual(await tool(name).run(client, args, name), { ok: true });
    assert.equal(client.calls.length, 1, name);
    assert.equal(client.calls[0].method, method, name);
    assert.equal(client.calls[0].path, path, name);
    assert.equal(client.calls[0].tool, name, name);
  }
});

test('automatizace převádí seznam firem a zachová nulové prahy', async () => {
  const client = new ReadClient();
  const args = {
    tab: 'pending', suppliers: [3, 7], source: 'rule', operation_type: 'post_invoice',
    from: '2026-01-01', to: '2026-09-29', min_confidence: 0, max_confidence: 1,
    min_amount: 0, max_amount: 5000, sort: 'confidence', direction: 'desc', page: 2, per_page: 25,
  };
  await tool('automation_feed').run(client, { ...args, confirm: true }, 'automation_feed');
  assert.deepEqual(client.calls[0].query, { ...args, suppliers: '3,7' });
  for (const name of ['automation_history', 'automation_counts', 'automation_recommendations']) {
    await tool(name).run(client, { suppliers: [3, 7], from: args.from, to: args.to }, name);
    assert.deepEqual(client.calls.at(-1).query, { suppliers: '3,7', from: args.from, to: args.to });
  }
});

test('automatizace mapuje company_id jen do výslovného filtru firmy', async () => {
  const client = new ReadClient();
  await tool('automation_stats').run(client, {
    company_id: 7, from: '2026-01-01', to: '2026-09-29', supplier_id: 999,
  }, 'automation_stats');
  assert.deepEqual(client.calls[0].query, { supplier_id: 7, from: '2026-01-01', to: '2026-09-29' });
  await tool('automation_checklist').run(client, { scope: 'vat_return' }, 'automation_checklist');
  assert.deepEqual(client.calls[1].query, { scope: 'vat_return' });
});

test('daňová přiznání podporují skutečné fo/po a verze dodatečných přiznání', async () => {
  const client = new ReadClient();
  for (const name of ['get_tax_return', 'tax_return_check', 'tax_return_xml_preview']) {
    await tool(name).run(client, { type: 'po', year: 2026, variant: 'dodatecne', seq: 0, row_version: 4 }, name);
    assert.deepEqual(client.calls.at(-1).query, { variant: 'dodatecne', seq: 0 });
    assert.deepEqual(tool(name).inputSchema.properties.type.enum, ['fo', 'po']);
  }
  await tool('list_tax_advances').run(client, { type: 'po', year: 2026, seq: 3 }, 'list_tax_advances');
  assert.equal(client.calls.at(-1).query, null);
});

test('bankovní seznam používá vnořené filtry a detail stránkuje transakce', async () => {
  const client = new ReadClient();
  const filter = {
    year: 2026, month: 9, account: '1000000005', bank_code: '0100',
    counterparty_account: '1000000005', client_id: 7, posting_status: 'unposted', amount: 0,
  };
  await tool('list_bank_statements').run(client, { ...filter, page: 3, per_page: 100 }, 'list_bank_statements');
  assert.deepEqual(client.calls[0].query, { page: 3, filter });
  assert.ok(!Object.hasOwn(tool('list_bank_statements').inputSchema.properties, 'per_page'));
  await tool('get_bank_statement').run(client, {
    id: 4, status: 'unmatched', posting_status: 'posted', page: 2, per_page: 100,
  }, 'get_bank_statement');
  assert.deepEqual(client.calls[1].query, { status: 'unmatched', posting_status: 'posted', page: 2, per_page: 100 });
});

test('prodeje skladu zachovají filtry, seskupení a podporovaný limit 500', async () => {
  const client = new ReadClient();
  const args = {
    date_from: '2026-01-01', date_to: '2026-09-29', client_id: 7, category_id: 3,
    warehouse_id: 2, stock_item_id: 11, query: 'syntetická šarže', group_by: 'client', page: 2, per_page: 500,
  };
  await tool('stock_sales_report').run(client, args, 'stock_sales_report');
  const { query, ...other } = args;
  assert.deepEqual(client.calls[0].query, { ...other, q: query });
  assert.equal(tool('stock_sales_report').inputSchema.properties.per_page.maximum, 500);
});

test('katalogový feed zachová cursor 0 a Intrastat použije čtecí POST', async () => {
  const client = new ReadClient();
  await tool('catalog_changes').run(client, { after_cursor: 0, limit: 1000 }, 'catalog_changes');
  assert.deepEqual(client.calls[0].query, { after_cursor: 0, limit: 1000 });
  const args = {
    period: '2026-09', direction: 'arrival', transaction_code: '12', transport_mode: '3',
    delivery_terms: 'K', record_type: 'ST', statistical_code: '', note_1: 'Syntetický náhled.',
  };
  await tool('intrastat_preview').run(client, { ...args, submit: true }, 'intrastat_preview');
  assert.deepEqual(client.calls[1], { method: 'POST_READ', path: '/stock/intrastat/preview', body: args, tool: 'intrastat_preview' });
});

test('pokladny a kontroly účetnictví předávají podporované parametry bez ID v query', async () => {
  const client = new ReadClient();
  await tool('list_cash_registers').run(client, { include_inactive: false }, 'list_cash_registers');
  assert.deepEqual(client.calls[0].query, { include_inactive: false });
  await tool('accounting_monthly_check').run(client, {
    period_id: 8, date_from: '2026-09-01', date_to: '2026-09-29', close: true,
  }, 'accounting_monthly_check');
  assert.deepEqual(client.calls[1].query, { date_from: '2026-09-01', date_to: '2026-09-29' });
  await tool('list_assets').run(client, { status: 'in_use', query: 'Syntetický stroj', page: 2 }, 'list_assets');
  assert.deepEqual(client.calls[2].query, { status: 'in_use', q: 'Syntetický stroj', page: 2 });
});

import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { ACCOUNTING_READ_TOOLS } from '../src/accounting-read-tools.mjs';
import { BANK_TOOLS } from '../src/bank-tools.mjs';
import { CLIENT_TOOLS } from '../src/client-tools.mjs';
import { validateToolArguments } from '../src/tool-args.mjs';
import { TOOLS } from '../src/tools.mjs';

class Client {
  constructor(responses = {}) {
    this.calls = [];
    this.responses = responses;
  }

  async #call(method, path, payload) {
    this.calls.push({ method, path, payload });
    return this.responses[`${method} ${path}`] ?? { ok: true };
  }

  get(path, query) { return this.#call('GET', path, query); }
  post(path, body) { return this.#call('POST', path, body); }
  patch(path, body) { return this.#call('PATCH', path, body); }
}

const all = [...BANK_TOOLS, ...CLIENT_TOOLS, ...ACCOUNTING_READ_TOOLS];
const tool = (name) => {
  const found = TOOLS.find((entry) => entry.name === name);
  assert.ok(found, name);
  return found;
};
const run = async (name, args, responses) => {
  const client = new Client(responses);
  const entry = tool(name);
  const result = await entry.run(client, validateToolArguments(entry, args), name);
  return { client, result };
};

test('nové moduly jsou v katalogu, mimo klientský katalog a s českým popisem', () => {
  const names = TOOLS.map((entry) => entry.name);
  assert.equal(new Set(names).size, names.length);
  for (const entry of all) {
    assert.ok(names.includes(entry.name), entry.name);
    assert.equal(entry.api, false, entry.name);
    assert.ok(entry.description.length > 60, entry.name);
    assert.equal(entry.inputSchema.additionalProperties, false, entry.name);
  }
  for (const entry of ACCOUNTING_READ_TOOLS) assert.equal(entry.write, false, entry.name);
});

test('účetní deník zůstává jen ke čtení: žádný nástroj nevolá zaúčtování ani storno', () => {
  const routes = JSON.parse(readFileSync(new URL('../src/tool-routes.generated.json', import.meta.url), 'utf8'));
  for (const entry of all) {
    for (const [method, path] of routes[entry.name].api) {
      assert.doesNotMatch(path, /\/(post|unpost|reverse|repost|approve|settle|submit|finalize)$/, `${entry.name}: ${method} ${path}`);
      if (/^\/api\/(accounting|reports|tax|tax-evidence|tax-return)\//.test(path)) assert.equal(method, 'GET', `${entry.name}: ${path}`);
    }
  }
  for (const name of ['match_bank_transaction', 'resolve_bank_match_suggestion']) {
    assert.match(tool(name).description, /NEZAPISUJE/);
    assert.match(tool(name).description, /deferred/);
  }
  assert.match(tool('unmatch_bank_transaction').description, /BEZ zápisu v účetním deníku/);
});

test('přehled a detail bankovních pohybů posílají filtry API', async () => {
  const { client } = await run('list_bank_transactions', { status: 'unmatched', posting_status: 'unposted', query: '1 234,50', year: 2026, page: 2 });
  assert.deepEqual(client.calls, [{ method: 'GET', path: '/bank-transactions', payload: {
    status: 'unmatched', posting_status: 'unposted', year: 2026, q: '1 234,50', page: 2,
  } }]);
  const detail = await run('get_bank_transaction', { id: 5 });
  assert.equal(detail.client.calls[0].path, '/bank-transactions/5');
});

test('kandidáti párování volí cestu podle režimu', async () => {
  const docs = await run('bank_transaction_match_candidates', { id: 5, search: 'FV' });
  assert.deepEqual(docs.client.calls[0], { method: 'GET', path: '/bank-transactions/5/match-candidates', payload: { search: 'FV' } });
  const split = await run('bank_transaction_match_candidates', { id: 5, mode: 'split', window: 0, max: 3 });
  assert.deepEqual(split.client.calls[0], { method: 'GET', path: '/bank-transactions/5/split-suggestions', payload: { window: 0, max: 3 } });
  await assert.rejects(run('find_bank_payments_for_document', {}), /právě jedno/);
  await assert.rejects(run('find_bank_payments_for_document', { invoice_id: 1, purchase_invoice_id: 2 }), /právě jedno/);
  const payments = await run('find_bank_payments_for_document', { purchase_invoice_id: 2 });
  assert.deepEqual(payments.client.calls[0].payload, { purchase_invoice_id: 2 });
});

test('párování posílá jediný doklad jako 1:1 a víc dokladů jako sloučenou úhradu', async () => {
  const single = await run('match_bank_transaction', { id: 9, invoice_ids: [3] });
  assert.deepEqual(single.client.calls, [{ method: 'POST', path: '/bank-transactions/9/match', payload: { invoice_id: 3 } }]);
  const split = await run('match_bank_transaction', { id: 9, purchase_invoice_ids: [3, 4] });
  assert.deepEqual(split.client.calls[0].payload, { purchase_invoice_ids: [3, 4] });
  const vs = await run('match_bank_transaction', { id: 9, varsymbol: '2026001' });
  assert.deepEqual(vs.client.calls[0].payload, { varsymbol: '2026001' });
  await assert.rejects(run('match_bank_transaction', { id: 9 }), /právě jedno/);
  await assert.rejects(run('match_bank_transaction', { id: 9, invoice_ids: [3], varsymbol: '1' }), /právě jedno/);
});

test('zrušení párování bez potvrzení jen ukáže pohyb a nic nepošle', async () => {
  const responses = { 'GET /bank-transactions/9': { id: 9, posted_at: '2026-03-01', amount: 1210, currency: 'CZK', counterparty_name: 'Syntetická s.r.o.', match_status: 'manual' } };
  await assert.rejects(run('unmatch_bank_transaction', { id: 9 }, responses), /NEPROVEDENO[\s\S]*Syntetická s\.r\.o\./);
  const { client } = await run('unmatch_bank_transaction', { id: 9, confirm: true }, responses);
  assert.deepEqual(client.calls.map((call) => `${call.method} ${call.path}`), ['GET /bank-transactions/9', 'POST /bank-transactions/9/unmatch']);
  assert.equal(tool('unmatch_bank_transaction').destructive, true);
});

test('ignorování, návrhy párování a koncept přijaté faktury volí správnou akci', async () => {
  assert.deepEqual((await run('set_bank_transaction_ignored', { id: 9, ignored: true, note: 'Převod' })).client.calls[0],
    { method: 'POST', path: '/bank-transactions/9/ignore', payload: { note: 'Převod' } });
  assert.equal((await run('set_bank_transaction_ignored', { id: 9, ignored: false })).client.calls[0].path, '/bank-transactions/9/unignore');
  assert.deepEqual((await run('resolve_bank_match_suggestion', { id: 4, decision: 'accept', candidate_index: 1 })).client.calls[0],
    { method: 'POST', path: '/bank-match-suggestions/4/accept', payload: { candidate_index: 1 } });
  assert.deepEqual((await run('resolve_bank_match_suggestion', { id: 4, decision: 'reject', reason: 'Jiná faktura' })).client.calls[0],
    { method: 'POST', path: '/bank-match-suggestions/4/reject', payload: { reason: 'Jiná faktura' } });
  assert.deepEqual((await run('create_purchase_invoice_from_bank_transaction', { id: 9, vendor_id: 2 })).client.calls[0],
    { method: 'POST', path: '/bank-transactions/9/create-purchase-invoice', payload: { vendor_id: 2 } });
});

test('karta partnera a archivace', async () => {
  for (const [name, path] of [
    ['get_client_bank_accounts', '/clients/3/bank-accounts'],
    ['check_client_vat_status', '/clients/3/vat-status'],
    ['list_client_projects', '/clients/3/projects'],
  ]) assert.equal((await run(name, { id: 3 })).client.calls[0].path, path, name);
  assert.equal((await run('list_client_duplicates', {})).client.calls[0].path, '/clients/duplicates');
  assert.equal((await run('set_client_archived', { id: 3, archived: true })).client.calls[0].path, '/clients/3/archive');
  assert.equal((await run('set_client_archived', { id: 3, archived: false })).client.calls[0].path, '/clients/3/unarchive');
});

test('dokumenty: složky, našeptávač vazeb a přesun při úpravě', async () => {
  assert.deepEqual((await run('list_document_folders', { parent_id: 4 })).client.calls[0],
    { method: 'GET', path: '/document-folders', payload: { parent_id: 4 } });
  assert.deepEqual((await run('create_document_folder', { name: 'Smlouvy' })).client.calls[0],
    { method: 'POST', path: '/document-folders', payload: { name: 'Smlouvy' } });
  assert.deepEqual((await run('document_link_search', { query: 'FV 2026', types: ['invoice', 'client'] })).client.calls[0].payload,
    { q: 'FV 2026', types: 'invoice,client' });

  const moved = await run('update_document', { id: 7, folder_id: 0 });
  assert.deepEqual(moved.client.calls, [{ method: 'POST', path: '/documents/7/move', payload: { folder_id: null } }]);
  const both = await run('update_document', { id: 7, title: 'Smlouva', folder_id: 4 });
  assert.deepEqual(both.client.calls.map((call) => [call.method, call.path, call.payload]), [
    ['PATCH', '/documents/7', { title: 'Smlouva' }],
    ['POST', '/documents/7/move', { folder_id: 4 }],
  ]);
  await assert.rejects(run('update_document', { id: 7 }), /aspoň jedno/);
});

test('požadavky na doklady: z pohybu, ručně a změna stavu', async () => {
  assert.deepEqual((await run('create_document_request', { bank_transaction_id: 9, deadline: '2026-12-01' })).client.calls[0],
    { method: 'POST', path: '/bank-transactions/9/document-request', payload: { deadline: '2026-12-01' } });
  assert.deepEqual((await run('create_document_request', { description: 'Smlouva o nájmu', amount: 5000 })).client.calls[0],
    { method: 'POST', path: '/document-requests', payload: { description: 'Smlouva o nájmu', amount: 5000 } });
  await assert.rejects(run('create_document_request', {}), /description/);
  assert.equal((await run('set_document_request_status', { id: 2, status: 'resolved' })).client.calls[0].path, '/document-requests/2/resolve');
  assert.equal((await run('set_document_request_status', { id: 2, status: 'requested' })).client.calls[0].path, '/document-requests/2/reopen');
});

test('daňové konstanty vyberou rok a chybějící rok srozumitelně odmítnou', async () => {
  const responses = { 'GET /codebooks/tax-constants': { years: [{ year: 2026, data: { a: 1 } }, { year: 2025, data: { a: 0 } }] } };
  assert.deepEqual((await run('get_tax_constants', { year: 2026 }, responses)).result, { year: 2026, data: { a: 1 } });
  await assert.rejects(run('get_tax_constants', { year: 2019 }, responses), /2026, 2025/);
  assert.equal((await run('get_tax_constants', {}, responses)).result.years.length, 2);
});

test('čtení účetnictví volá správné cesty', async () => {
  const cases = [
    ['list_manual_posting_queue', { type: 'purchase_invoice' }, '/accounting/manual-posting-queue', { type: 'purchase_invoice' }],
    ['list_bank_posting_queue', { status: 'unmatched', query: 'nájem' }, '/accounting/bank-posting-unposted', { status: 'unmatched', q: 'nájem' }],
    ['list_bank_posting_queue', { view: 'suggestions', status: 'pending' }, '/accounting/bank-posting-suggestions', { status: 'pending' }],
    ['accounting_period_check_findings', { period_id: 2, check: 'unposted_documents', date_from: '2026-01-01' }, '/accounting/periods/2/checks/unposted_documents', { date_from: '2026-01-01' }],
    ['get_journal_entry_context', { id: 5, section: 'history' }, '/accounting/journal/5/history', null],
    ['list_document_journal_entries', { source: 'purchase-invoices', id: 5 }, '/accounting/journal/for-document/purchase-invoices/5', null],
    ['open_items_listing', { as_of: '2026-12-31', account_codes: ['311', '321'] }, '/accounting/reports/open-items-list', { as_of: '2026-12-31', codes: '311,321' }],
    ['vat_clearing_status', { year: 2026, month: 3 }, '/accounting/vat-clearing', { year: 2026, month: 3 }],
    ['get_cash_register_book', { id: 1, doc_type: 'out', query: 'benzín' }, '/accounting/cash-registers/1/book', { doc_type: 'out', q: 'benzín' }],
    ['list_small_assets', { status: 'in_use' }, '/accounting/small-assets', { status: 'in_use' }],
    ['list_small_assets', { id: 4 }, '/accounting/small-assets/4', null],
    ['small_assets_report', { kind: 'movements', from: '2026-01-01' }, '/accounting/reports/small-assets/movements', { from: '2026-01-01' }],
    ['asset_purchase_candidates', {}, '/accounting/assets/purchase-candidates', null],
    ['monthly_report_preview', { year: 2026, month: 3, accrual_spread: false }, '/accounting/reports/monthly-report/preview', { year: 2026, month: 3, accrual_spread: 0 }],
    ['vat_filing_checks', { check: 'post_filing_changes', year: 2026, month: 3, period: 'quarterly', as_of: '2026-01-01' }, '/reports/dphdp3/post-filing-changes', { year: 2026, month: 3, period: 'quarterly' }],
    ['vat_filing_checks', { check: 's46_candidates', as_of: '2026-06-30', year: 2026 }, '/reports/s46/candidates', { as_of: '2026-06-30' }],
    ['vat_filing_checks', { check: 'dph_settings' }, '/reports/dphdp3/settings', null],
    ['tax_evidence_tax_estimate', { year: 2026 }, '/tax-evidence/tax-estimate', { year: 2026 }],
    ['tax_advance_overrides', { type: 'po', year: 2026 }, '/tax-return/po/2026/advances/overrides', null],
  ];
  for (const [name, args, path, payload] of cases) {
    const { client } = await run(name, args);
    assert.deepEqual(client.calls[0], { method: 'GET', path, payload }, name);
  }
  await assert.rejects(run('list_bank_posting_queue', { view: 'suggestions', status: 'manual' }), /Neplatný stav návrhu/);
  const counts = await run('list_bank_posting_queue', { view: 'counts' });
  assert.deepEqual(counts.client.calls.map((call) => call.path), ['/accounting/bank-posting-unposted/count', '/accounting/bank-posting-suggestions/count']);
});

test('otevřené položky, podání a mzdový běh skládají víc čtení', async () => {
  const open = await run('list_open_items', { account_id: 8, only_open: false, include_suggestions: true, days: 30 });
  assert.deepEqual(open.client.calls.map((call) => [call.path, call.payload]), [
    ['/accounting/open-items/8', { only_open: 0 }],
    ['/accounting/open-items/8/suggestions', { days: 30 }],
  ]);
  const submission = await run('get_tax_submission', { id: 3, include_payment: true });
  assert.deepEqual(submission.client.calls.map((call) => call.path), ['/reports/submissions/3', '/reports/submissions/3/payment']);
  const people = await run('get_payroll_run_results', { id: 6, findings_only: true, query: 'Nováková' });
  assert.deepEqual(people.client.calls.map((call) => [call.path, call.payload]), [
    ['/payroll/runs/6', null],
    ['/payroll/runs/6/people', { q: 'Nováková', findings: 1 }],
  ]);
  const person = await run('get_payroll_run_results', { id: 6, employee_id: 2 });
  assert.equal(person.client.calls[1].path, '/payroll/runs/6/people/2');
});

test('manifest rout zachytí všechny větve nástrojů s výčtem i přímou revizi mzdy', () => {
  const routes = JSON.parse(readFileSync(new URL('../src/tool-routes.generated.json', import.meta.url), 'utf8'));
  const paths = (name) => routes[name].api.map(([method, path]) => `${method} ${path}`);
  assert.ok(paths('get_payroll_salary_result').includes('GET /api/payroll/revisions/{id}/net-results/{id}'));
  assert.ok(paths('get_tax_return').includes('GET /api/tax-return/po/{id}'));
  assert.ok(paths('list_entity_documents').includes('GET /api/documents/by-entity/invoice/{id}'));
  assert.deepEqual(paths('resolve_bank_match_suggestion'), ['POST /api/bank-match-suggestions/{id}/accept', 'POST /api/bank-match-suggestions/{id}/reject']);
  assert.ok(paths('update_document').includes('POST /api/documents/{id}/move'));
  assert.ok(paths('list_small_assets').includes('GET /api/accounting/small-assets'));
});

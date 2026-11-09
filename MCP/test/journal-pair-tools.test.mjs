import assert from 'node:assert/strict';
import test from 'node:test';
import { TOOLS_BY_NAME } from '../src/tools.mjs';
import { validateToolArguments } from '../src/tool-args.mjs';

const ENTRY = {
  id: 7,
  lines: [
    { id: 1, side: 'debit', account_code: '311', amount: 1210, pair_no: 1, line_text: 'faktura' },
    { id: 2, side: 'credit', account_code: '602', amount: 1000, pair_no: 1, line_text: 'faktura' },
  ],
  pairs: [
    { pair_no: 1, debit_line_id: 1, credit_line_id: 2, debit_account_code: '311', credit_account_code: '602', amount: 1000, line_text: 'faktura', reversed_by_entry_id: null },
  ],
};

async function call(name, args, result = { items: [] }) {
  let request;
  const client = { get: async (path, query, tool) => { request = { path, query, tool }; return result; } };
  const out = await TOOLS_BY_NAME.get(name).run(client, args, name);
  return { request, out };
}

test('detail zápisu předá dvojice, formu a pair_no beze ztráty', async () => {
  const { request, out } = await call('get_journal_entry', { id: 7 }, ENTRY);
  assert.deepEqual(request, { path: '/accounting/journal/7', query: null, tool: 'get_journal_entry' });
  assert.equal(out, ENTRY);
  assert.equal(out.pairs[0].debit_account_code, '311');
  assert.equal(out.lines[0].pair_no, 1);
  const description = TOOLS_BY_NAME.get('get_journal_entry').description;
  assert.match(description, /pairs/);
  assert.match(description, /pair_no/);
  assert.match(description, /`entry_form`/);
  assert.match(description, /journal_entry_form/);
});

test('deník předá filtr pair_status a popisuje posouzení zápisu', async () => {
  const tool = TOOLS_BY_NAME.get('list_journal_entries');
  assert.deepEqual(tool.inputSchema.properties.pair_status.enum, ['composite_mn', 'ineligible']);
  assert.throws(() => validateToolArguments(tool, { pair_status: 'paired' }));
  const { request } = await call('list_journal_entries', { entry_id: 7, pair_status: 'composite_mn' });
  assert.equal(request.path, '/accounting/journal');
  assert.equal(request.query.entry_id, 7);
  assert.equal(request.query.pair_status, 'composite_mn');
  const plain = await call('list_journal_entries', { entry_id: 7 });
  assert.equal(plain.request.query.pair_status, undefined);
  assert.match(tool.description, /pair_status/);
  assert.match(tool.description, /get_journal_entry/);
});

test('opis účtu předá protiúčet dvojice beze změny a filtruje podle protiúčtu', async () => {
  const statement = { items: [{ entry_id: 7, counter_accounts: '602', counter_account_id: 21, pair_no: 1, pair_status: 'paired', pair_piece: null }], counter_totals: [{ account_id: 21, md: 1000, d: 0 }] };
  const { request, out } = await call('account_statement', { account_id: 9, from: '2027-01-01', to: '2027-12-31', counter_account_id: 21 }, statement);
  assert.equal(request.path, '/accounting/reports/account-statement/9');
  assert.equal(request.query.counter_account_id, 21);
  assert.equal(out.items[0].counter_account_id, 21);
  const description = TOOLS_BY_NAME.get('account_statement').description;
  assert.match(description, /jediný protiúčet/);
  assert.match(description, /counter_totals/);
  assert.match(description, /pair_piece/);
  assert.doesNotMatch(description, /pair_derived|pair_composite_mn/);
});

test('forma zápisu firmy je čtecí nástroj bez argumentů a volá entry-form', async () => {
  const tool = TOOLS_BY_NAME.get('journal_entry_form');
  assert.ok(tool);
  assert.equal(tool.write, false);
  assert.deepEqual(validateToolArguments(tool, {}), {});
  assert.throws(() => validateToolArguments(tool, { supplier_id: 9 }));
  const state = { entry_form: 'single', history: [{ valid_from: '2027-01-01', entry_form: 'single' }] };
  const { request, out } = await call('journal_entry_form', {}, state);
  assert.deepEqual(request, { path: '/accounting/entry-form', query: null, tool: 'journal_entry_form' });
  assert.equal(out, state);
});

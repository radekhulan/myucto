import assert from 'node:assert/strict';
import test from 'node:test';
import { runHosted } from '../src/hosted-core.mjs';

const cases = [
  { name: 'link_invoice_advance', args: { id: 7, advance_id: 8 }, method: 'POST', path: '/invoices/7/link-advance', body: { advance_id: 8 } },
  { name: 'unlink_invoice_advance', args: { id: 7, confirm: true }, method: 'DELETE', path: '/invoices/7/link-advance', load: '/invoices/7', destructive: true },
  { name: 'apply_invoice_advance_drawdown', args: { id: 7, proforma_id: 8, amount: 250 }, method: 'POST',
    path: '/invoices/7/advance-drawdown', body: { proforma_id: 8, amount: 250 } },
  { name: 'remove_invoice_advance_drawdown', args: { id: 7, confirm: true }, method: 'DELETE',
    path: '/invoices/7/advance-drawdown', load: '/invoices/7', destructive: true },
  { name: 'create_final_invoice_from_proforma', args: { id: 8, tax_date: '2026-03-01', due_date: '2026-03-15', final_total: 2000 },
    method: 'POST', path: '/invoices/8/issue-final', body: { tax_date: '2026-03-01', due_date: '2026-03-15', final_total: 2000 } },
  { name: 'create_payment_tax_document', args: { id: 8, payment_id: 9 }, method: 'POST', path: '/invoices/8/payments/9/tax-document', body: {} },
  { name: 'link_purchase_advance', args: { id: 7, advance_id: 8 }, method: 'POST',
    path: '/purchase-invoices/7/link-advance', body: { advance_id: 8 }, load: '/purchase-invoices/7' },
  { name: 'unlink_purchase_advance', args: { id: 7, confirm: true }, method: 'DELETE',
    path: '/purchase-invoices/7/link-advance', load: '/purchase-invoices/7', destructive: true },
];
const input = { scope: 'read_write', boundSupplierId: 1, lockedSupplierId: 1,
  apiUrl: 'https://portal.example.test/api/v1', token: 'synthetic-token', allowedTools: cases.map(entry => entry.name) };
const invoice = { id: 7, status: 'draft', invoice_type: 'invoice', currency: 'CZK', parent_invoice_id: 8,
  advance_paid_amount: 1000, items: [], linked_advance: { id: 8, currency: 'CZK', total_with_vat: 1000 } };

test('zálohový cyklus vyžaduje zápisový rozsah i povolený nástroj', async () => {
  const read = await runHosted({ ...input, operation: 'list', scope: 'read' });
  assert.deepEqual(read.tools, []);
  const list = await runHosted({ ...input, operation: 'list' });
  assert.deepEqual(list.tools.map(tool => tool.name).sort(), input.allowedTools.toSorted());
  for (const entry of cases) {
    const tool = list.tools.find(tool => tool.name === entry.name);
    assert.equal(tool.annotations.readOnlyHint, false);
    assert.equal(tool.annotations.destructiveHint, Boolean(entry.destructive));
    let called = false;
    const fetcher = () => { called = true; throw new Error('API se nesmí zavolat.'); };
    const call = { ...input, operation: 'call', name: entry.name, arguments: entry.args };
    assert.equal((await runHosted({ ...call, scope: 'read' }, fetcher)).isError, true);
    assert.equal((await runHosted({ ...call, allowedTools: [] }, fetcher)).isError, true);
    assert.equal((await runHosted({ ...call, arguments: { ...entry.args, supplier_id: 2 } }, fetcher)).isError, true);
    assert.equal(called, false);
  }
});

for (const entry of cases) {
  test(`${entry.name}: odešle deklarované operace ve schválené firmě`, async () => {
    const calls = [];
    const result = await runHosted({ ...input, operation: 'call', name: entry.name, arguments: entry.args }, async (url, options) => {
      const path = new URL(url).pathname;
      calls.push([options.method, path]);
      assert.equal(options.headers['X-Supplier-Id'], '1');
      assert.equal(options.headers['X-MyUcto-Tool'], entry.name);
      if (options.method === 'GET') return new Response(JSON.stringify(invoice));
      assert.equal(options.method, entry.method);
      assert.equal(path, `/api/v1${entry.path}`);
      if (entry.body) assert.deepEqual(JSON.parse(options.body), entry.body);
      return new Response(JSON.stringify({ synthetic: true, advance_paid_amount: 1000 }));
    });
    assert.equal(result.isError, undefined, JSON.stringify(result));
    assert.deepEqual(result.structuredContent, { synthetic: true, advance_paid_amount: 1000 });
    assert.deepEqual(calls, [
      ...(entry.load ? [['GET', `/api/v1${entry.load}`]] : []),
      [entry.method, `/api/v1${entry.path}`],
    ]);
  });
}

test('odpojení vazeb a odebrání čerpání bez potvrzení neprovedou zápis', async () => {
  for (const entry of cases.filter(entry => entry.destructive)) {
    const calls = [];
    const result = await runHosted({ ...input, operation: 'call', name: entry.name,
      arguments: { ...entry.args, confirm: false } }, async (url, options) => {
      calls.push(options.method);
      return new Response(JSON.stringify(invoice));
    });
    assert.equal(result.isError, true);
    assert.match(result.content[0].text, /chybí potvrzení/);
    if (entry.name === 'unlink_invoice_advance') assert.match(result.content[0].text, /1000.00 CZK.*zůstane/);
    assert.deepEqual(calls, ['GET']);
  }
});

test('potvrzení neobejde koncept, chybějící vazbu ani zaúčtovanou přijatou fakturu', async () => {
  for (const [name, row, message] of [
    ['remove_invoice_advance_drawdown', { ...invoice, status: 'issued' }, /není koncept/],
    ['unlink_invoice_advance', { ...invoice, parent_invoice_id: null }, /nemá vazbu/],
    ['unlink_purchase_advance', { ...invoice, booked_at: '2026-03-01 10:00:00' }, /zaúčtovaná/],
    ['unlink_purchase_advance', { ...invoice, locked: { journal_entry_id: 9 } }, /zaúčtovaná/],
  ]) {
    const calls = [];
    const result = await runHosted({ ...input, operation: 'call', name, arguments: { id: 7, confirm: true } }, async (url, options) => {
      calls.push(options.method);
      return new Response(JSON.stringify(row));
    });
    assert.equal(result.isError, true);
    assert.match(result.content[0].text, message);
    assert.deepEqual(calls, ['GET']);
  }
});

test('serverové odmítnutí zámku, cizího dokladu a přečerpání se předá bez opakování zápisu', async () => {
  for (const [status, code] of [[403, 'document_locked'], [404, 'not_found'], [409, 'drawdown_failed']]) {
    let calls = 0;
    const result = await runHosted({ ...input, operation: 'call', name: 'apply_invoice_advance_drawdown',
      arguments: { id: 7, proforma_id: 8, amount: 250 } }, async () => {
      calls++;
      return new Response(JSON.stringify({ error: { code, message: `Syntetické odmítnutí ${code}` } }), { status });
    });
    assert.equal(result.isError, true);
    assert.match(result.content[0].text, new RegExp(code));
    assert.equal(calls, 1);
  }
});

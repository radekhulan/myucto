import assert from 'node:assert/strict';
import test from 'node:test';
import { runHosted } from '../src/hosted-core.mjs';

// Čerpání víc záloh jednou fakturou: procento plnění, částka bez DPH a odebrání jedné zálohy.

const tools = ['apply_invoice_advance_drawdown', 'remove_invoice_advance_drawdown'];
const input = { scope: 'read_write', boundSupplierId: 1, lockedSupplierId: 1,
  apiUrl: 'https://portal.example.test/api/v1', token: 'synthetic-token', allowedTools: tools };
const invoice = { id: 7, status: 'draft', invoice_type: 'invoice', currency: 'CZK', parent_invoice_id: null, items: [] };

async function call(name, args) {
  const calls = [];
  const result = await runHosted({ ...input, operation: 'call', name, arguments: args }, async (url, options) => {
    const parsed = new URL(url);
    calls.push({ method: options.method, path: parsed.pathname, query: Object.fromEntries(parsed.searchParams),
      body: options.body ? JSON.parse(options.body) : null });
    return new Response(JSON.stringify(options.method === 'GET' ? invoice : { synthetic: true }));
  });
  return { result, calls };
}

test('apply_invoice_advance_drawdown pošle procento plnění faktury', async () => {
  const { result, calls } = await call('apply_invoice_advance_drawdown', { id: 7, proforma_id: 8, percent: 30 });
  assert.equal(result.isError, undefined, JSON.stringify(result));
  assert.deepEqual(calls.at(-1), { method: 'POST', path: '/api/v1/invoices/7/advance-drawdown', query: {}, body: { proforma_id: 8, percent: 30 } });
});

test('apply_invoice_advance_drawdown pošle částku bez DPH', async () => {
  const { calls } = await call('apply_invoice_advance_drawdown', { id: 7, proforma_id: 8, amount: 5000, basis: 'net' });
  assert.deepEqual(calls.at(-1).body, { proforma_id: 8, amount: 5000, basis: 'net' });
});

test('remove_invoice_advance_drawdown odebere jen zadanou zálohu', async () => {
  const { result, calls } = await call('remove_invoice_advance_drawdown', { id: 7, proforma_id: 8, confirm: true });
  assert.equal(result.isError, undefined, JSON.stringify(result));
  assert.deepEqual(calls.map(c => [c.method, c.path, c.query]), [
    ['GET', '/api/v1/invoices/7', {}],
    ['DELETE', '/api/v1/invoices/7/advance-drawdown', { proforma_id: '8' }],
  ]);
});

test('remove_invoice_advance_drawdown bez potvrzení nic neodebere a pojmenuje zálohu', async () => {
  const { result, calls } = await call('remove_invoice_advance_drawdown', { id: 7, proforma_id: 8 });
  assert.equal(result.isError, true);
  assert.match(result.content[0].text, /zálohy #8/);
  assert.deepEqual(calls.map(c => c.method), ['GET']);
});

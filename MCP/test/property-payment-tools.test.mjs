import assert from 'node:assert/strict';
import test from 'node:test';
import { TOOLS_BY_NAME } from '../src/tools.mjs';
import { validateToolArguments } from '../src/tool-args.mjs';

const names = ['property_receivable_card', 'list_property_payment_operations', 'property_payment_history', 'property_receivable_reconciliation'];
const as_of = '2026-10-17T12:34:56.123456+02:00';
const known_at = '2026-10-18T00:00:00Z';
async function call(name, args) {
  const tool = TOOLS_BY_NAME.get(name); let request;
  const result = { totals: { gross_receivable_cents: null }, coverage: { complete: false } };
  const client = { get: async (path, query, label) => { request = { path, query, label }; return result; } };
  assert.equal(await tool.run(client, validateToolArguments(tool, args), name), result);
  return request;
}

test('S4/S5 podporuje pouze čtyři curated GET a zachová response beze změny', async () => {
  for (const name of names) {
    const tool = TOOLS_BY_NAME.get(name);
    assert.ok(tool); assert.equal(tool.write, false); assert.equal(tool.inputSchema.additionalProperties, false);
  }
  assert.deepEqual(await call(names[0], { unit_id: 7, as_of, known_at }), {
    path: '/property/catalog/payments/card', query: { unit_id: 7, as_of, known_at }, label: names[0],
  });
  assert.deepEqual((await call(names[1], { unit_id: 7, after_id: 42, limit: 100 })).query, { unit_id: 7, after_id: 42, limit: 100 });
  assert.equal((await call(names[1], {})).path, '/property/catalog/payments/operations');
  assert.deepEqual((await call(names[2], { before_id: 42, limit: 1 })).query, { before_id: 42, limit: 1 });
  assert.equal((await call(names[2], {})).path, '/property/catalog/payments/history');
  assert.deepEqual(await call(names[3], { unit_id: 7, date: '2026-10-17' }), {
    path: '/property/catalog/payments/reconciliation', query: { unit_id: 7, date: '2026-10-17' }, label: names[3],
  });
});

test('časové scope a keyset orientace nejsou zaměnitelné a chybný vstup neprojde', () => {
  const card = TOOLS_BY_NAME.get(names[0]);
  for (const args of [{ as_of, known_at }, { unit_id: 7, as_of }, { unit_id: 0, as_of, known_at },
    { unit_id: 7, as_of: '2026-10-17', known_at }, { unit_id: 7, as_of, known_at, client_id: 1 }]) {
    assert.throws(() => validateToolArguments(card, args));
  }
  for (const [name, bad] of [[names[1], { before_id: 42 }], [names[2], { after_id: 42 }],
    [names[1], { limit: 101 }], [names[2], { limit: 0 }], [names[1], { unit_id: -1 }],
    [names[2], { before_id: -1 }], [names[3], { unit_id: 7, date: '2026-10-17', known_at }]]) {
    assert.throws(() => validateToolArguments(TOOLS_BY_NAME.get(name), bad));
  }
});

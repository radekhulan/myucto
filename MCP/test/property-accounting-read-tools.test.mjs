import assert from 'node:assert/strict';
import test from 'node:test';
import { PROPERTY_ACCOUNTING_READ_TOOLS } from '../src/property-accounting-read-tools.mjs';
import { validateToolArguments } from '../src/tool-args.mjs';
import { TOOLS_BY_NAME } from '../src/tools.mjs';
const tools = new Map(PROPERTY_ACCOUNTING_READ_TOOLS.map(t => [t.name, t]));
const cases = [
  ['list_property_service_lifecycles', { after_id: 0, limit: 100, unit_id: 2, service_id: 3 }, '/property/catalog/service-lifecycles'],
  ['get_property_service_lifecycle', { id: 9 }, '/property/catalog/service-lifecycles/9'],
  ['property_service_reconciliation', { date: '2026-12-31', unit_id: 2, service_id: 3 }, '/property/catalog/service-lifecycles/reconciliation'],
  ['list_property_fund_operations', { after_id: 0, limit: 1, house_id: 4 }, '/property/catalog/funds/operations'],
  ['property_fund_balances', { date: '2026-12-31', unit_id: 2, house_id: 4, known_at: '2026-12-31T12:00:00.123456+01:00' }, '/property/catalog/funds/balances'],
];
for (const [name, args, path] of cases) test(`${name} volá pouze správný GET a zachová neurčité částky`, async () => {
  const tool = tools.get(name);assert.equal(TOOLS_BY_NAME.get(name), tool);assert.equal(tool.write, false);assert.deepEqual(validateToolArguments(tool, args), args);
  let call;const result = { items: [{ amount_cents: null }], incomplete: true, matches: false, next_after_id: 9 };
  const out = await tool.run({ get: async (p, q, t) => { call = { path: p, query: q, tool: t };return result; } }, args, name);
  assert.equal(out, result);assert.equal(call.path, path);assert.equal(call.tool, name);
  const query = name === 'get_property_service_lifecycle' ? null : args;assert.deepEqual(call.query && Object.fromEntries(Object.entries(call.query).filter(([, v]) => v !== undefined)), query);
  assert.throws(() => validateToolArguments(tool, { ...args, supplier_id: 55 }));
  assert.throws(() => validateToolArguments(tool, { ...args, actor: 7 }));
});
test('lifecycle a S7 nenabízejí nepodporovanou historii znalosti', () => {
  for (const name of ['list_property_service_lifecycles', 'get_property_service_lifecycle', 'property_service_reconciliation']) assert.throws(() => validateToolArguments(tools.get(name), { ...(name.startsWith('get_') ? { id: 1 } : name === 'property_service_reconciliation' ? { date: '2026-12-31' } : {}), known_at: '2026-12-31T12:00:00Z' }));
});
test('stránkování má tvrdý limit100 a neakceptuje finanční mutace', () => {
  for (const name of ['list_property_service_lifecycles', 'list_property_fund_operations']) for (const input of [{ limit: 0 }, { limit: 101 }, { after_id: -1 }, { amount_cents: '1' }, { operation: 'reverse' }]) assert.throws(() => validateToolArguments(tools.get(name), input));
  assert.equal(tools.size, 5);
});

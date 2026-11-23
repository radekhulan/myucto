import assert from 'node:assert/strict';
import test from 'node:test';
import { validateToolArguments } from '../src/tool-args.mjs';
import { TOOLS_BY_NAME } from '../src/tools.mjs';

const recorder = () => {
  const calls = [];
  return {
    calls,
    client: { get: async (path, query, name) => { calls.push({ path, query, name }); return { ok: true }; } },
  };
};

test('kontrola dat po převodu je čtecí nástroj bez zápisu a bez firmy v argumentech', () => {
  const tool = TOOLS_BY_NAME.get('migration_data_checks');
  assert.ok(tool);
  assert.equal(tool.write, false);
  assert.equal(tool.inputSchema.additionalProperties, false);
  assert.deepEqual(tool.inputSchema.required, []);
  for (const valid of [{}, { check: 'tax_document_advance_link' }, { check: 'settled_without_payment', state: 'fixable', page: 2, per_page: 50 }]) {
    assert.deepEqual(validateToolArguments(tool, valid), valid);
  }
  for (const invalid of [{ check: 'Tax-Link' }, { check: '../fix' }, { state: 'fixed' }, { page: 0 }, { supplier_id: 9 }, { confirm: true }, { options: {} }]) {
    assert.throws(() => validateToolArguments(tool, invalid));
  }
});

test('bez kontroly čte přehled, s kontrolou její nálezy se stránkováním', async () => {
  const tool = TOOLS_BY_NAME.get('migration_data_checks');
  const { calls, client } = recorder();
  await tool.run(client, {}, tool.name);
  await tool.run(client, { check: 'foreign_bank_in_czk', state: 'review', page: 3, per_page: 20 }, tool.name);
  assert.deepEqual(calls, [
    { path: '/accounting/migration-checks', query: null, name: 'migration_data_checks' },
    { path: '/accounting/migration-checks/foreign_bank_in_czk', query: { state: 'review', page: 3, per_page: 20 }, name: 'migration_data_checks' },
  ]);
  assert.ok(calls.every((c) => !c.path.endsWith('/fix') && !c.path.endsWith('/preview')));
});

import assert from 'node:assert/strict';
import test from 'node:test';
import { ACCOUNTING_CONTEXT_TOOLS } from '../src/accounting-context-tools.mjs';
import { validateToolArguments } from '../src/tool-args.mjs';
import { TOOLS_BY_NAME } from '../src/tools.mjs';

test('účetní kontext je registrovaný čistě čtecí nástroj s jediným přesným datem', async () => {
  const tool = TOOLS_BY_NAME.get('get_accounting_context');
  assert.ok(tool);
  assert.equal(tool.name, ACCOUNTING_CONTEXT_TOOLS[0].name);
  assert.equal(tool.write, false);
  assert.equal(tool.inputSchema.additionalProperties, false);
  assert.deepEqual(tool.inputSchema.required, ['date']);
  const args = { date: '2026-07-01' };
  assert.deepEqual(validateToolArguments(tool, args), args);
  let request;
  const result = { complete: false, findings: [{ code: 'account_definition_not_historized' }], accounts: [{ metadata_revision: null }] };
  assert.equal(await tool.run({ get: async (path, query, name) => { request = { path, query, name }; return result; } }, args, tool.name), result);
  assert.deepEqual(request, { path: '/accounting/catalog/context', query: args, name: tool.name });
  for (const invalid of [{}, { date: 20260701 }, { date: '2026-7-1' }, { ...args, known_at: '2026-07-02T00:00:00Z' }, { ...args, supplier_id: 9 }, { ...args, payload: {} }]) {
    assert.throws(() => validateToolArguments(tool, invalid));
  }
});

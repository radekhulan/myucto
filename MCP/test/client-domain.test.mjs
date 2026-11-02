import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { runHosted } from '../src/hosted-core.mjs';
import { TOOLS_BY_NAME } from '../src/tools.mjs';

const contracts = JSON.parse(readFileSync(new URL('../src/tool-api.generated.json', import.meta.url), 'utf8'));

test('client tool contracts refer to real tools and explicit API operations', () => {
  for (const [name, { api: operations }] of Object.entries(contracts)) {
    assert.ok(TOOLS_BY_NAME.has(name), name);
    assert.ok(operations.length > 0, name);
    for (const [method, path] of operations) {
      assert.match(method, /^(GET|POST|PUT|DELETE)$/);
      assert.match(path, /^\/api\//);
    }
  }
});

test('hosted list intersects role tools with the token read scope', async () => {
  const result = await runHosted({ operation: 'list', scope: 'read', boundSupplierId: 1,
    allowedTools: ['whoami', 'list_invoices', 'create_invoice'] });
  assert.deepEqual(result.tools.map(t => t.name), ['whoami', 'list_invoices']);
});

test('a direct call cannot bypass the client tool list', async () => {
  let called = false;
  const result = await runHosted({ operation: 'call', scope: 'read_write', boundSupplierId: 1,
    allowedTools: ['whoami'], name: 'create_invoice', arguments: {} }, () => { called = true; });
  assert.equal(result.isError, true);
  assert.equal(called, false);
});

test('an empty client tool list fails closed', async () => {
  const result = await runHosted({ operation: 'list', scope: 'read_write', allowedTools: [] });
  assert.deepEqual(result.tools, []);
});

test('výkazy respektují čtecí rozsah tokenu i přímé volání', async () => {
  const allowedTools = ['get_work_report', 'add_work_report_entry', 'add_work_report_material', 'remove_work_report_entry'];
  const input = { scope: 'read', boundSupplierId: 1, allowedTools };
  const list = await runHosted({ ...input, operation: 'list' });
  assert.deepEqual(list.tools.map(t => t.name), ['get_work_report']);
  for (const name of allowedTools.slice(1)) {
    let called = false;
    const invoke = async (settings) => runHosted({ ...input, ...settings, operation: 'call', name,
      arguments: { invoice_id: 7, description: 'Syntetická práce', hours: 1 } }, () => { called = true; });
    assert.equal((await invoke({})).isError, true);
    assert.equal((await invoke({ scope: 'read_write', allowedTools: ['get_work_report'] })).isError, true);
    assert.equal(called, false);
  }
});

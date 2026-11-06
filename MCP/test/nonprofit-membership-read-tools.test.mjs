import assert from 'node:assert/strict';
import test from 'node:test';
import { NONPROFIT_MEMBERSHIP_READ_TOOLS } from '../src/nonprofit-membership-read-tools.mjs';
import { validateToolArguments } from '../src/tool-args.mjs';

const tools = new Map(NONPROFIT_MEMBERSHIP_READ_TOOLS.map((tool) => [tool.name, tool]));

test('membership tools expose only curated reads and exact query shapes', async () => {
  const cases = [
    ['list_nonprofit_membership_roles', { as_of: '2026-06-30', known_at: '2026-10-19T10:00:00Z', limit: 25 }, '/accounting/catalog/nonprofit-membership/roles'],
    ['get_nonprofit_membership_role', { member_id: 7, as_of: '2026-06-30' }, '/accounting/catalog/nonprofit-membership/roles/7'],
    ['list_nonprofit_membership_receivables', { member_id: 7, cursor: 'opaque', limit: 25 }, '/accounting/catalog/nonprofit-membership/receivables'],
    ['get_nonprofit_membership_receivable', { claim_id: 11 }, '/accounting/catalog/nonprofit-membership/receivables/11'],
  ];
  for (const [name, args, path] of cases) {
    const tool = tools.get(name);
    assert.ok(tool);
    assert.equal(tool.write, false);
    assert.equal(tool.inputSchema.additionalProperties, false);
    const valid = validateToolArguments(tool, args);
    let request;
    await tool.run({ get: async (actualPath, query, actualName) => { request = { actualPath, query, actualName }; return {}; } }, valid, name);
    assert.equal(request.actualPath, path);
    assert.equal(request.actualName, name);
    for (const forbidden of [{ supplier_id: 2 }, { evidence: 'x' }, { request_json: {} }, { idempotency_key: 'x' }, { write: true }]) {
      assert.throws(() => validateToolArguments(tool, { ...args, ...forbidden }));
    }
  }
});

test('membership role and receivable tools reject mixed temporal and write inputs', () => {
  assert.throws(() => validateToolArguments(tools.get('list_nonprofit_membership_roles'), { as_of: '2026/02/30' }));
  assert.throws(() => validateToolArguments(tools.get('list_nonprofit_membership_receivables'), { member_id: 7, as_of: '2026-01-01' }));
  assert.throws(() => validateToolArguments(tools.get('get_nonprofit_membership_receivable'), { claim_id: 0 }));
});

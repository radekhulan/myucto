import assert from 'node:assert/strict';
import test from 'node:test';
import { PROPERTY_UNIT_LEGAL_ROLE_TOOLS } from '../src/property-unit-legal-role-tools.mjs';
import { validateToolArguments } from '../src/tool-args.mjs';

const tools = new Map(PROPERTY_UNIT_LEGAL_ROLE_TOOLS.map((tool) => [tool.name, tool]));

test('property legal role tools are exact curated reads', async () => {
  const at = '2026-06-01T10:00:00Z';
  const known = '2026-10-19T10:00:00Z';
  for (const [name, args, path] of [
    ['list_property_unit_legal_roles', { as_of: at, known_at: known, limit: 25 }, '/property/catalog/unit-legal-roles'],
    ['get_property_unit_legal_role', { unit_id: 9, as_of: at, known_at: known }, '/property/catalog/unit-legal-roles/9'],
  ]) {
    const tool = tools.get(name);
    assert.ok(tool);
    assert.equal(tool.write, false);
    assert.equal(tool.inputSchema.additionalProperties, false);
    const valid = validateToolArguments(tool, args);
    let call;
    await tool.run({ get: async (actualPath, query, actualName) => { call = { actualPath, query, actualName }; return {}; } }, valid, name);
    assert.equal(call.actualPath, path);
    assert.equal(call.actualName, name);
    for (const extra of [{ supplier_id: 1 }, { title_ref: 'x' }, { evidence: 'x' }, { actor: 1 }, { write: true }]) {
      assert.throws(() => validateToolArguments(tool, { ...args, ...extra }));
    }
    assert.throws(() => validateToolArguments(tool, { ...args, known_at: undefined }));
  }
});

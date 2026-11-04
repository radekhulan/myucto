import { NOT_IN_CLIENT_CATALOG } from './api-contracts.mjs';
import { seg } from './tool-shared.mjs';

const id = { type: 'integer', minimum: 1 };
const date = { type: 'string', pattern: '^\\d{4}-\\d{2}-\\d{2}$' };
const instant = { type: 'string', pattern: '^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}(?:\\.\\d{1,6})?(?:Z|[+-]\\d{2}:\\d{2})$' };
const cursor = { type: 'string', minLength: 1, maxLength: 2048, pattern: '^[A-Za-z0-9_-]+$' };
const limit = { type: 'integer', minimum: 1, maximum: 100 };
const read = (name, title, properties, required, run) => ({
  api: false, apiReason: NOT_IN_CLIENT_CATALOG,
  name, title,
  description: 'Kurátorované čtení členského subledgeru bez jmen, členských čísel, podkladových textů, interních požadavků a idempotency metadat. Zachovejte null u neprokázané úhrady; výsledek neopravňuje k finanční mutaci.',
  inputSchema: { type: 'object', properties, required, additionalProperties: false },
  write: false,
  run,
});

export const NONPROFIT_MEMBERSHIP_READ_TOOLS = [
  read('list_nonprofit_membership_roles', 'Historické členské role', { as_of: date, known_at: instant, cursor, limit }, ['as_of'],
    (c, a, tool) => c.get('/accounting/catalog/nonprofit-membership/roles', { as_of: a.as_of, known_at: a.known_at, cursor: a.cursor, limit: a.limit }, tool)),
  read('get_nonprofit_membership_role', 'Historická členská role', { member_id: id, as_of: date, known_at: instant }, ['member_id', 'as_of'],
    (c, a, tool) => c.get(`/accounting/catalog/nonprofit-membership/roles/${seg(a.member_id)}`, { as_of: a.as_of, known_at: a.known_at }, tool)),
  read('list_nonprofit_membership_receivables', 'Členské pohledávky', { member_id: id, cursor, limit }, ['member_id'],
    (c, a, tool) => c.get('/accounting/catalog/nonprofit-membership/receivables', { member_id: a.member_id, cursor: a.cursor, limit: a.limit }, tool)),
  read('get_nonprofit_membership_receivable', 'Členská pohledávka', { claim_id: id }, ['claim_id'],
    (c, a, tool) => c.get(`/accounting/catalog/nonprofit-membership/receivables/${seg(a.claim_id)}`, {}, tool)),
];

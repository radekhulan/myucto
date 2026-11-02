import { seg } from './tool-shared.mjs';

const id = { type: 'integer', minimum: 1 };
const instant = { type: 'string', pattern: '^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}(?:\\.\\d{1,6})?(?:Z|[+-]\\d{2}:\\d{2})$' };
const cursor = { type: 'string', minLength: 1, maxLength: 2048, pattern: '^[A-Za-z0-9_-]+$' };
const limit = { type: 'integer', minimum: 1, maximum: 100 };
const read = (name, title, properties, required, run) => ({
  api: false, apiReason: 'Dosud nepřevedeno do klientského katalogu; zachovává stávající rozsah (FR #285).',
  name, title,
  description: 'Kurátorované čtení historických právních rolí jednotky bez označení jednotky, jmen, titulů, podkladů, interních požadavků, hashů a aktérů. Výsledek neopravňuje k finanční ani právní mutaci.',
  inputSchema: { type: 'object', properties, required, additionalProperties: false },
  write: false,
  run,
});

export const PROPERTY_UNIT_LEGAL_ROLE_TOOLS = [
  read('list_property_unit_legal_roles', 'Historické právní role jednotek', { as_of: instant, known_at: instant, cursor, limit }, ['as_of', 'known_at'],
    (c, a, tool) => c.get('/property/catalog/unit-legal-roles', { as_of: a.as_of, known_at: a.known_at, cursor: a.cursor, limit: a.limit }, tool)),
  read('get_property_unit_legal_role', 'Historická právní role jednotky', { unit_id: id, as_of: instant, known_at: instant }, ['unit_id', 'as_of', 'known_at'],
    (c, a, tool) => c.get(`/property/catalog/unit-legal-roles/${seg(a.unit_id)}`, { as_of: a.as_of, known_at: a.known_at }, tool)),
];

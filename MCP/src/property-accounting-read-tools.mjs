import { NOT_IN_CLIENT_CATALOG } from './api-contracts.mjs';
import { seg } from './tool-shared.mjs';

const id = (description) => ({ type: 'integer', minimum: 1, description });
const date = { type: 'string', format: 'date', description: 'Povinné skutečné účetní datum.' };
const page = { after_id: { type: 'integer', minimum: 0, description: 'next_after_id ze stejné firmy a stejných filtrů.' }, limit: { type: 'integer', minimum: 1, maximum: 100 } };
const scope = { unit_id: id('ID jednotky stejné firmy.'), service_id: id('ID služby stejné firmy.') };
const read = (name, title, description, properties, required, run) => ({
  api: false, apiReason: NOT_IN_CLIENT_CATALOG, name, title, description, inputSchema: { type: 'object', properties, required, additionalProperties: false }, write: false, run });
export const PROPERTY_ACCOUNTING_READ_TOOLS = [
  read('list_property_service_lifecycles', 'Účetní lifecycle služeb SVJ', 'Ověřené účetní případy služeb a jejich aktuální stav. Neurčené částky zůstávají null, pending a incomplete nejsou nula. Historický known_at není podporován. Kurzor patří stejné firmě a filtrům; neautorizuje účetní operaci.', { ...page, ...scope }, [],
    (c, a, tool) => c.get('/property/catalog/service-lifecycles', { after_id: a.after_id, limit: a.limit, unit_id: a.unit_id, service_id: a.service_id }, tool)),
  read('get_property_service_lifecycle', 'Konkrétní lifecycle služby SVJ', 'Aktuálně ověřený stav případu a technické identifikátory doložených událostí bez soukromých podkladů. Historický known_at není podporován.', { id: id('ID případu z list_property_service_lifecycles.') }, ['id'],
    (c, a, tool) => c.get(`/property/catalog/service-lifecycles/${seg(a.id)}`, null, tool)),
  read('property_service_reconciliation', 'Rekonciliace služeb SVJ', 'Nativní nezávislé součty subledgeru a hlavního deníku k datu podle jednotky a služby. Zachovejte signed string haléře, coverage, matches a findings. Znalost je současná, known_at není podporován. Nejde o potvrzení nebo uzávěrku.', { date, ...scope }, ['date'],
    (c, a, tool) => c.get('/property/catalog/service-lifecycles/reconciliation', { date: a.date, unit_id: a.unit_id, service_id: a.service_id }, tool)),
  read('list_property_fund_operations', 'Operace financování vlastníků', 'Ověřené operace 955, administrativních záloh a příjmů vlastníků včetně původních a stornovaných operací. Částky jsou signed string haléře, nikoli dnešní dostupná kapacita. Kurzor patří stejné firmě a filtru domu.', { ...page, house_id: id('Volitelný dům stejné firmy.') }, [],
    (c, a, tool) => c.get('/property/catalog/funds/operations', { house_id: a.house_id, after_id: a.after_id, limit: a.limit }, tool)),
  read('property_fund_balances', 'Zůstatky financování vlastníků', 'Nativní zůstatky a nezávislý ledger k datu; bez filtru zahrnou celou firmu včetně nepropojených zápisů. Zachovejte matches, coverage a rozdíly. known_at omezuje skutečné posted/recorded podklady podle native vlastníka; žádná částka neautorizuje čerpání nebo platbu.', { date, house_id: id('Volitelný dům.'), unit_id: id('Volitelná jednotka.'), known_at: { type: 'string', pattern: '^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(?:\\.[0-9]{1,6})?(?:Z|[+-][0-9]{2}:[0-9]{2})$', maxLength: 32 } }, ['date'],
    (c, a, tool) => c.get('/property/catalog/funds/balances', { date: a.date, house_id: a.house_id, unit_id: a.unit_id, known_at: a.known_at }, tool)),
];

/**
 * Dimenze (středisko, zakázka, projekt…) konceptu vydané a přijaté faktury.
 *
 * Token smí dimenze zapsat jen ke KONCEPTU (`DimensionAction::assertTokenMayTagDocument`):
 * koncept nemá řádky v deníku, takže se nic nepřerazítkuje. Vystavený, přijatý
 * a zaúčtovaný doklad se přeúčtovává v aplikaci.
 *
 * Dimenze položek ukládá server podle POŘADÍ řádku (1 = první položka), ne podle ID.
 * Po vložení nebo odebrání řádku je server u konceptu vrátí k položkám sám podle `id`,
 * které nástroje na položky posílají (`DraftItemDimensionRealign`).
 */

import { NOT_IN_CLIENT_CATALOG } from './api-contracts.mjs';
import { seg } from './tool-shared.mjs';

const int = (description, extra = {}) => ({ type: 'integer', description, ...extra });
const schema = (properties = {}, required = []) => ({
  type: 'object', properties, required, additionalProperties: false,
});

const DOCUMENTS = {
  invoices: { path: '/invoices', label: 'Vydaná faktura' },
  'purchase-invoices': { path: '/purchase-invoices', label: 'Přijatá faktura' },
};

const dimensionsPath = (doc, id) => `/accounting/dimensions/documents/${seg(doc)}/${seg(id)}`;

/** Mapa `{pořadí: {typ: hodnota}}` z odpovědi serveru; prázdné pole PHP je prázdná mapa. */
const asMap = (value) => (value && typeof value === 'object' && !Array.isArray(value) ? value : {});

const DIMENSION_LIST = {
  type: 'array',
  description: 'Dimenze: `type_id` z `list_dimensions`, `value_id` hodnota toho typu; `value_id: null` dimenzi daného typu odebere.',
  items: schema({
    type_id: int('ID typu dimenze (středisko, zakázka…), viz `list_dimensions`.', { minimum: 1 }),
    value_id: { type: ['integer', 'null'], minimum: 1, description: 'ID hodnoty; null dimenzi typu odebere.' },
  }, ['type_id', 'value_id']),
};

/** Sloučí změny `[{type_id, value_id}]` do mapy typ → hodnota; null typ odebere. */
function applyChanges(map, changes) {
  const out = { ...asMap(map) };
  for (const { type_id: type, value_id: value } of changes ?? []) {
    if (value === null) delete out[type];
    else out[type] = value;
  }
  return out;
}

/** Typ, kterému uživatel dal jedinou hodnotu, nesmí dál nést rozpad (server by vzal rozpad). */
function dropSplitTypes(splits, row, changes) {
  if (!splits[row]) return;
  const rest = { ...splits[row] };
  for (const { type_id: type } of changes ?? []) delete rest[type];
  if (Object.keys(rest).length === 0) delete splits[row];
  else splits[row] = rest;
}

/** Nástroj na dimenze konceptu jednoho druhu faktury; role se posuzují zvlášť pro vydané a přijaté. */
function setDimensionsTool(name, docType) {
  const doc = DOCUMENTS[docType];
  return {
    name,
    api: false,
    apiReason: NOT_IN_CLIENT_CATALOG,
    title: `Nastavit dimenze konceptu: ${doc.label.toLowerCase()}`,
    description:
      `Nastaví dimenze (středisko, zakázku, projekt…) hlavičce nebo položkám KONCEPTU (${doc.label.toLowerCase()}). `
      + 'Mění se jen zadané typy a zadané položky, ostatní dimenze zůstanou. Typy a hodnoty vrátí '
      + '`list_dimensions`. Bez `header` i `items` jen vrátí současné dimenze dokladu.\n\n'
      + 'Hotový doklad (vystavený, přijatý, zaúčtovaný) nástroj odmítne: změna by přerazítkovala účetní zápis, '
      + 'to dělá účetní v aplikaci. Firma musí mít dimenze zapnuté.',
    inputSchema: schema({
      id: int('ID konceptu faktury.', { minimum: 1 }),
      header: DIMENSION_LIST,
      items: {
        type: 'array',
        description: 'Dimenze jednotlivých položek. Položku určí `item_id` nebo pořadí `row` (od 1, jak je vrací detail dokladu).',
        items: schema({
          item_id: int('ID položky.', { minimum: 1 }),
          row: int('Pořadí položky od 1; alternativa k `item_id`.', { minimum: 1 }),
          dimensions: DIMENSION_LIST,
        }, ['dimensions']),
      },
    }, ['id']),
    write: true,
    run: async (c, a, tool) => {
      const invoice = await c.get(`${doc.path}/${seg(a.id)}`, null, tool);
      if ((a.header ?? []).length === 0 && (a.items ?? []).length === 0) {
        // Bez změn jen ukáže současný stav, ať model ví, co na dokladu je.
        return { id: Number(a.id), dimensions: await c.get(dimensionsPath(docType, a.id), null, tool), changed: false };
      }
      if (invoice?.status !== 'draft') {
        throw new Error(
          `${doc.label} #${a.id} není koncept (stav „${invoice?.status ?? '?'}"). Dimenze hotového dokladu `
          + 'mění účetní v aplikaci, změna by přerazítkovala účetní zápis.',
        );
      }
      const lines = (invoice.items ?? []).filter((item) => (item.item_kind ?? 'standard') !== 'discount');
      const current = await c.get(dimensionsPath(docType, a.id), null, tool);
      const items = { ...asMap(current?.items) };
      const splits = { ...asMap(current?.splits) };

      const header = applyChanges(current?.header, a.header);
      dropSplitTypes(splits, '0', a.header);
      for (const entry of a.items ?? []) {
        let row;
        if (entry.item_id !== undefined) {
          row = lines.findIndex((line) => Number(line.id) === Number(entry.item_id)) + 1;
          if (row === 0) throw new Error(`Položka #${entry.item_id} na dokladu není.`);
        } else if (entry.row !== undefined) {
          row = Number(entry.row);
          if (row > lines.length) throw new Error(`Doklad má ${lines.length} položek, položka ${row} neexistuje.`);
        } else {
          throw new Error('U položky zadejte `item_id`, nebo pořadí `row`.');
        }
        const next = applyChanges(items[row], entry.dimensions);
        if (Object.keys(next).length === 0) delete items[row];
        else items[row] = next;
        dropSplitTypes(splits, String(row), entry.dimensions);
      }
      const saved = await c.put(dimensionsPath(docType, a.id), { header, items, splits }, tool);
      return { id: Number(a.id), dimensions: saved };
    },
  };
}

export const DOCUMENT_DIMENSION_TOOLS = [
  setDimensionsTool('set_invoice_dimensions', 'invoices'),
  setDimensionsTool('set_purchase_invoice_dimensions', 'purchase-invoices'),
];

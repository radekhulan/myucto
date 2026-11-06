/**
 * Pomocníci sdílení katalogem nástrojů ({@see ./tools.mjs}) a samostatnými moduly
 * nástrojů (např. {@see ./purchase-tools.mjs}).
 *
 * Leží ve vlastním souboru schválně: moduly nástrojů importuje `tools.mjs`, takže
 * kdyby si je ony braly z `tools.mjs`, vznikl by kruhový import a `const` hodnoty
 * by při načtení ještě neexistovaly.
 */

const bool = (description) => ({ type: 'boolean', description });

/**
 * Typy záznamů, ke kterým jde připojit dokument. Zrcadlí
 * `DocumentLinkRepository::ENTITY_TYPES`; server ověří, že záznam patří téže firmě.
 */
export const DOCUMENT_ENTITY_TYPES = [
  'client', 'invoice', 'purchase_invoice', 'project', 'journal_entry', 'bank_transaction',
  'cash_document', 'other_item', 'invoice_payment', 'internal_document',
];

/**
 * Potvrzovací parametr pro mazání a další nevratné kroky.
 *
 * Zavedeno kvůli tomu, že katalog e-shopu je poprvé zapisovatelný z jazykového
 * modelu. Model si dokáže domyslet, že „ukliď staré štítky" znamená mazání,
 * ale nemá jak vědět, co na štítku visí. Vzor je stejný jako u `allow_duplicate`
 * v `create_client`: bez výslovného souhlasu se operace neprovede.
 */
export const CONFIRM = bool(
  'Potvrzení nevratné operace. Bez `true` se NIC nesmaže — nástroj jen vrátí, '
  + 'čeho by se změna týkala. Ten výpis ukaž uživateli a zavolej nástroj znovu '
  + 's `confirm: true` teprve po jeho souhlasu.',
);

/**
 * Bez potvrzení operaci zastaví a místo provedení vrátí, čeho se týká.
 *
 * @param {string} action co by se stalo, např. „Smazat se má kategorie"
 * @param {string} label  konkrétní záznam, ať uživatel nevidí jen číslo
 */
export function requireConfirm(a, action, label) {
  if (a.confirm === true) return;
  throw new Error(
    `NEPROVEDENO — chybí potvrzení. ${action}: ${label}.\n`
    + 'Operace je nevratná. Ukaž to uživateli a teprve po jeho souhlasu zavolej '
    + 'nástroj znovu s `confirm: true`.',
  );
}

/**
 * Načte dotčený záznam a bez potvrzení ho vrátí jako náhled místo provedení.
 *
 * První volání tak funguje jako suchý běh: uživatel vidí konkrétní záznam
 * z databáze, ne jen agentův odhad, co se asi smaže. Zároveň se tím ověří,
 * že záznam vůbec existuje a patří téhle firmě.
 *
 * @param {(row: any) => string} label krátký popis záznamu do hlášky
 */
export async function confirmed(c, a, tool, { path, action, label }) {
  const current = await c.get(path, null, tool);
  requireConfirm(a, action, label(current));
  return current;
}

/**
 * Jeden segment cesty API z hodnoty argumentu nástroje.
 *
 * Druhá pojistka za validací argumentů ({@see ./tool-args.mjs}): `new URL()`
 * vyhodnocuje tečkové segmenty, takže ID „../../invoices/123" by z nástroje pro
 * jeden zdroj udělalo požadavek na úplně jiný. Lomítka a další oddělovače se
 * proto kódují a samotné `.`/`..` ani prázdná hodnota se nepřipouští.
 */
export function seg(value) {
  const text = value === null || value === undefined ? '' : String(value);
  if (text === '' || text === '.' || text === '..') {
    throw new Error(`Neplatná hodnota v cestě požadavku: "${text}".`);
  }
  return encodeURIComponent(text);
}

/**
 * Tělo požadavku jen z předaných parametrů.
 *
 * Zdroje e-shopu a skladu dělají partial update (chybějící klíč = beze změny),
 * takže posílat `undefined` klíče by znamenalo rozdíl mezi „neměň" a „vynuluj"
 * setřít — a model, který chce upravit jen název, by tiše smazal EAN.
 */
export const changed = (a, keys) => Object.fromEntries(
  keys.filter((k) => a[k] !== undefined).map((k) => [k, a[k]]),
);

/** Složí úplný PUT payload: zadané hodnoty mají přednost, ostatní se převezmou. */
export const merged = (current, a, keys) => Object.fromEntries(
  keys
    .filter((k) => a[k] !== undefined || current?.[k] !== undefined)
    .map((k) => [k, a[k] !== undefined ? a[k] : current[k]]),
);

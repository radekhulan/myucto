/**
 * Číselníky pro vydané a přijaté faktury: kategorie tržeb, členění DPH, předkontace,
 * číselné řady, brandingové profily a pravidla klasifikace nákladů.
 *
 * Bez nich by model neměl odkud vzít ID a kódy pro pole dokladu (`revenue_category_id`,
 * `vat_classification_code`, `posting_rule_key`, `number_series_id`, `branding_profile_id`)
 * a hádal by je. Vše jen čte; cenové hladiny, pokladny, měny, sazby DPH a kategorie
 * nákladů mají vlastní nástroje (`list_price_levels`, `list_cash_registers`,
 * `list_currencies`, `list_vat_rates`, `list_expense_categories`).
 */

import { NOT_IN_CLIENT_CATALOG } from './api-contracts.mjs';

const str = (description, extra = {}) => ({ type: 'string', description, ...extra });
const bool = (description) => ({ type: 'boolean', description });
const schema = (properties = {}, required = []) => ({
  type: 'object', properties, required, additionalProperties: false,
});
const flag = (value) => (value ? 1 : undefined);

/** Druhy dokladů, pro které vydaná strana vede vlastní číselné řady (`NumberSeriesKinds::issuedKind`). */
const ISSUED_TYPES = ['invoice', 'proforma', 'credit_note', 'tax_document', 'penalty', 'rent_calendar', 'advance_calendar'];

/**
 * Předkontace jako seznam s klíčem. Server vrací objekt `klíč → pravidlo` a u každého
 * pravidla, jestli ho jde použít na řádku přijaté (`line_purchase`) a vydané (`line_sale`) faktury.
 */
function postingRuleList(map, side) {
  const rows = Object.entries(map && typeof map === 'object' ? map : {})
    .map(([key, rule]) => ({ key, ...rule }));
  if (side === 'sale') return rows.filter((rule) => rule.line_sale !== false);
  if (side === 'purchase') return rows.filter((rule) => rule.line_purchase !== false);
  return rows;
}

export const CODEBOOK_TOOLS = [
  {
    name: 'list_revenue_categories',
    api: false,
    apiReason: NOT_IN_CLIENT_CATALOG,
    title: 'Kategorie tržeb',
    description:
      'Číselník kategorií tržeb s ID pro `revenue_category_id` vydané faktury a šablony pravidelné '
      + 'fakturace. Kategorie může mít vlastní číselnou řadu a výnosový účet.',
    inputSchema: schema({ include_archived: bool('Vrátit i archivované kategorie.') }),
    write: false,
    run: (c, a, tool) => c.get('/revenue-categories', { include_archived: flag(a.include_archived) }, tool),
  },
  {
    name: 'list_vat_classifications',
    api: false,
    apiReason: NOT_IN_CLIENT_CATALOG,
    title: 'Členění pro DPH a kontrolní hlášení',
    description:
      'Kódy členění plnění (`vat_classification_code`) pro položky a hlavičku faktur: řádek přiznání DPH '
      + 'a oddíl kontrolního hlášení. Prodej (`sale`) má např. 1/2 tuzemsko, 1m prodej dlouhodobého majetku, '
      + '20 dodání zboží do EU, 22 služby do EU, 26 vývoz; nákup (`purchase`) 40/41 tuzemsko, 42 bez nároku, '
      + '23 pořízení zboží z EU, 24e služby z EU, 25 dovoz, 5 přenesená povinnost. Firma může mít vlastní kódy.',
    inputSchema: schema({
      direction: str('Strana: `sale` pro vydané faktury, `purchase` pro přijaté, `both` kódy obou stran. Bez zadání vše.', {
        enum: ['sale', 'purchase', 'both'],
      }),
      include_archived: bool('Vrátit i archivované kódy.'),
      include_return_lines: bool('Přidat seznam řádků přiznání DPH, které smí kód nést (pro založení vlastního kódu).'),
    }),
    write: false,
    run: async (c, a, tool) => {
      const codes = await c.get('/vat-classifications', {
        direction: a.direction, include_archived: flag(a.include_archived),
      }, tool);
      if (!a.include_return_lines) return codes;
      const lines = await c.get('/vat-classifications/lines', null, tool);
      return { classifications: codes, return_lines: lines?.lines ?? lines };
    },
  },
  {
    name: 'list_posting_rules',
    api: false,
    apiReason: NOT_IN_CLIENT_CATALOG,
    title: 'Předkontace',
    description:
      'Předkontace firmy (podvojné účetnictví): klíč (`key`) pro `posting_rule_key` na řádku faktury, '
      + 'účet MD a Dal a popis. Předkontace řádku doplní účty, které řádek sám nemá. `side` vybere ty, '
      + 'které jde použít na řádku vydané (`sale`) nebo přijaté (`purchase`) faktury. V daňové evidenci '
      + 'předkontace nejsou a server odpoví chybou.',
    inputSchema: schema({
      side: str('Jen předkontace použitelné na řádku vydané (`sale`) nebo přijaté (`purchase`) faktury.', {
        enum: ['sale', 'purchase'],
      }),
    }),
    write: false,
    run: async (c, a, tool) => postingRuleList(await c.get('/accounting/posting-rules', null, tool), a.side),
  },
  {
    name: 'list_invoice_number_series',
    api: false,
    apiReason: NOT_IN_CLIENT_CATALOG,
    title: 'Číselné řady vydaných dokladů',
    description:
      'Číselné řady, ze kterých jde vydanému dokladu přidělit číslo (`number_series_id` v `create_invoice` '
      + 'a `update_invoice`), včetně výchozí řady. Řady se liší podle typu dokladu.',
    inputSchema: schema({
      type: str('Typ dokladu, výchozí `invoice`.', { enum: ISSUED_TYPES }),
    }),
    write: false,
    run: (c, a, tool) => c.get('/invoices/number-series', { type: a.type }, tool),
  },
  {
    name: 'list_purchase_number_series',
    api: false,
    apiReason: NOT_IN_CLIENT_CATALOG,
    title: 'Číselné řady přijatých dokladů',
    description:
      'Číselné řady interního čísla přijatých faktur (`number_series_id` v `create_purchase_invoice` '
      + 'a `update_purchase_invoice`), včetně výchozí řady.',
    inputSchema: schema({}),
    write: false,
    run: (c, _a, tool) => c.get('/purchase-invoices/number-series', null, tool),
  },
  {
    name: 'list_branding_profiles',
    api: false,
    apiReason: NOT_IN_CLIENT_CATALOG,
    title: 'Brandingové profily',
    description:
      'Aktivní brandingové profily firmy (jiné logo, hlavička, bankovní účet nebo patička na PDF) s ID pro '
      + '`branding_profile_id` vydané faktury a šablony pravidelné fakturace.',
    inputSchema: schema({}),
    write: false,
    run: (c, _a, tool) => c.get('/branding-profiles', null, tool),
  },
  {
    name: 'list_expense_rules',
    api: false,
    apiReason: NOT_IN_CLIENT_CATALOG,
    title: 'Pravidla klasifikace nákladů',
    description:
      'Pravidla, podle kterých aplikace přiřazuje položkám přijatých faktur druh nákladu (služba, materiál, '
      + 'drobný a dlouhodobý majetek) a nákladový účet podle dodavatele nebo textu. Pomůže vysvětlit, proč '
      + 'položka dostala daný účet, a navrhnout `expense_kind` a `expense_account_code`.',
    inputSchema: schema({}),
    write: false,
    run: (c, _a, tool) => c.get('/accounting/expense-rules', null, tool),
  },
];

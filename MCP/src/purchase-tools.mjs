/**
 * Přijaté faktury: pořízení, úprava konceptu, stav dokladu, zálohy a příkazy k úhradě.
 *
 * Seznam a detail (`list_purchase_invoices`, `get_purchase_invoice`) zůstávají
 * v katalogu {@see ./tools.mjs}; tady je všechno, co s dokladem dělá API navíc,
 * kromě souborů (PDF, ISDOC, import).
 *
 * VĚDOMĚ CHYBÍ:
 *  - přechod na `booked` (zaúčtování je účetní úkon, účetní vrstva je pro token
 *    jen ke čtení, viz hlavička tools.mjs);
 *  - odeslání příkazu k úhradě do banky (nevratný pohyb peněz) a `mark_paid`
 *    v příkazech (obchází stavový automat dokladu);
 *  - AI návrh kontace (`ai-suggest`): ukládá návrh, platí se za něj a server
 *    ho pouští jen s právem účtovat.
 *
 * Úpravy, které by u ZAÚČTOVANÉHO dokladu rozešly doklad s deníkem (druh dokladu,
 * kurz, druh nákladu, vazba na zálohu), nástroje odmítnou ještě před zápisem.
 * Server je u části z nich pustí a deník přeúčtuje sám, jenže přeúčtování je
 * přesně ten krok, který token dělat nemá.
 */

import { NOT_IN_CLIENT_CATALOG } from './api-contracts.mjs';
import { CONFIRM, changed, merged, requireConfirm, seg } from './tool-shared.mjs';

const str = (description, extra = {}) => ({ type: 'string', description, ...extra });
const int = (description, extra = {}) => ({ type: 'integer', description, ...extra });
const num = (description, extra = {}) => ({ type: 'number', description, ...extra });
const bool = (description) => ({ type: 'boolean', description });
const date = (description) => str(description, { format: 'date' });
const id = (description) => int(description, { minimum: 1 });
const schema = (properties = {}, required = []) => ({
  type: 'object', properties, required, additionalProperties: false,
});
const PAGING = {
  page: int('Stránka od 1.', { minimum: 1 }),
  per_page: int('Záznamů na stránce (1 až 200).', { minimum: 1, maximum: 200 }),
};

const BASE = '/purchase-invoices';
const ORDERS = `${BASE}/payment-orders`;

const DOCUMENT_KINDS = ['invoice', 'receipt', 'credit_note', 'advance', 'tax_document'];
const PAYMENT_METHODS = ['bank_transfer', 'direct_debit', 'card', 'cash', 'cash_on_delivery', 'offset', 'other'];
const VAT_DEDUCTIONS = ['full', 'none', 'proportional', 'reduced'];
const EXPENSE_KINDS = ['service', 'material', 'small_asset', 'small_intangible', 'fixed_asset'];

/**
 * Sloupce hlavičky, které PUT /purchase-invoices/{id} zapisuje VŽDY
 * (PurchaseInvoiceRepository::updateDraft). Chybějící klíč by vynuloval nebo
 * vrátil na výchozí hodnotu (odpočet DPH na „full", kategorii nákladu na nic),
 * proto se posílají kompletně ze současného stavu.
 *
 * Volitelné sloupce (zakázka, interní číslo, forma úhrady, platební účet, kurz,
 * ruční rekapitulace DPH, pokladna) server bez klíče nechá být, a tak se posílají
 * jen tehdy, když je uživatel mění.
 */
const PURCHASE_HEADER_FIELDS = [
  'vendor_id', 'vendor_invoice_number', 'document_kind', 'issue_date', 'tax_date',
  'delivery_date', 'due_date', 'received_at', 'currency_id', 'reverse_charge',
  'prices_include_vat', 'language', 'note_above_items', 'note_below_items',
  'advance_paid_amount', 'payment_currency_id', 'payment_exchange_rate',
  'paid_amount_payment_ccy', 'paid_amount_invoice_ccy', 'exchange_diff_base',
  'vat_classification_code', 'vat_deduction', 'vat_deduction_percent', 'tax_deductible',
  'is_fixed_asset', 'expense_category_id',
];

/** Všechna pole řádku, která server při náhradě položek přijímá (replaceItems). */
const PURCHASE_ITEM_FIELDS = [
  'description', 'quantity', 'duration_minutes', 'unit', 'unit_price_without_vat',
  'vat_rate_id', 'order_index', 'vat_classification_code', 'is_fixed_asset',
  'expense_kind', 'expense_account_code', 'accrual_from', 'accrual_to', 'stock_item_id',
];

/**
 * Změna dodavatele nebo přenesené povinnosti mění daňové zařazení řádků (tuzemsko,
 * EU, třetí země, § 92a). Data dokladu sem záměrně NEpatří: zařazení přijaté faktury
 * na nich nezávisí a znovuodvození by zahodilo kód, který vybral uživatel nebo AI
 * (pořízení zboží 23 a dovoz 25 se ze sazby poznat nedají, odvodí se jako služba).
 */
const PURCHASE_TAX_CONTEXT = ['vendor_id', 'reverse_charge'];

const amountText = (pi) => (pi?.total_with_vat != null
  ? `, ${pi.total_with_vat} ${pi.currency ?? ''}`.trimEnd()
  : '');

/** Popis dokladu do potvrzovací hlášky: dodavatel, číslo dokladu, částka a stav. */
function purchaseLabel(pi, fallbackId) {
  const vendor = pi?.vendor_company_name || `dodavatel #${pi?.vendor_id ?? '?'}`;
  const number = pi?.vendor_invoice_number ? `doklad ${pi.vendor_invoice_number}` : `#${pi?.id ?? fallbackId}`;
  const ours = pi?.varsymbol ? `, naše č. ${pi.varsymbol}` : '';
  return `${vendor}, ${number}${ours}${amountText(pi)} (stav „${pi?.status ?? '?'}")`;
}

/** Zaúčtovaný doklad: má datum zaúčtování nebo živý zápis v deníku. */
const isPosted = (pi) => Boolean(pi?.booked_at || pi?.locked?.journal_entry_id);

const loadPurchase = (c, invoiceId, tool) => c.get(`${BASE}/${seg(invoiceId)}`, null, tool);

async function loadDraftPurchase(c, invoiceId, tool) {
  const pi = await loadPurchase(c, invoiceId, tool);
  if (pi?.status !== 'draft') {
    throw new Error(
      `Přijatá faktura ${purchaseLabel(pi, invoiceId)} není koncept, proto ji nástroj neupraví. `
      + 'Přijatý nebo zaúčtovaný doklad opravuje účetní v aplikaci (vynucená úprava s dorovnáním deníku).',
    );
  }
  return pi;
}

function refuseIfPosted(pi, invoiceId, what) {
  if (!isPosted(pi)) return;
  throw new Error(
    `Přijatá faktura ${purchaseLabel(pi, invoiceId)} je zaúčtovaná. ${what} by ji rozešla `
    + 's účetním zápisem a přeúčtování je účetní úkon, který přes API nejde. Proveďte to v aplikaci.',
  );
}

/** Načte číselník měn a vrátí ID a kód měny zadané kódem nebo ID. */
async function resolveCurrency(c, a, tool) {
  const list = await c.get('/codebooks/currencies', { include_inactive: 1 }, tool);
  const rows = Array.isArray(list) ? list : (list?.data ?? []);
  if (a.currency_id !== undefined) {
    const row = rows.find((r) => Number(r.id) === Number(a.currency_id));
    if (!row) throw new Error(`Měna #${a.currency_id} ve firmě není. Seznam vrátí \`list_currencies\`.`);
    return { id: Number(row.id), code: String(row.code).toUpperCase() };
  }
  const code = String(a.currency ?? '').trim().toUpperCase();
  const hits = rows.filter((r) => String(r.code).toUpperCase() === code);
  if (hits.length === 0) throw new Error(`Měna ${code} ve firmě není. Seznam vrátí \`list_currencies\`.`);
  const row = hits.find((r) => r.is_default) ?? hits.find((r) => r.is_active) ?? hits[0];
  return { id: Number(row.id), code };
}

/**
 * Kurz ČNB k rozhodnému dni. Rozhodný den je DUZP, jinak datum vystavení; stejné
 * pravidlo drží server v `ExchangeRateDate::forPurchase` a editor v aplikaci.
 */
async function cnbRate(c, code, taxDate, issueDate, tool) {
  const day = taxDate || issueDate;
  const res = await c.get('/codebooks/cnb-rate', { currency: code, date: day }, tool);
  if (!(Number(res?.rate) > 0)) {
    throw new Error(`Kurz ČNB pro ${code} k ${day} není k dispozici. Zadejte kurz ručně (\`exchange_rate\`).`);
  }
  return { exchange_rate: Number(res.rate), exchange_rate_date: res.rate_date ?? day, exchange_rate_source: 'cnb' };
}

/**
 * Řádek tak, jak ho vrátil server, připravený k odeslání zpět. Nese všechna skrytá
 * pole (druh nákladu, účet, časové rozlišení, sklad, pořadí). `rederive` zahodí
 * daňové zařazení, které by po změně sazby nebo dodavatele lhalo; server ho pak
 * odvodí stejně jako u nové položky.
 */
function lineForPut(item, { rederive = false } = {}) {
  const out = {};
  for (const key of PURCHASE_ITEM_FIELDS) {
    if (item[key] !== undefined) out[key] = item[key];
  }
  if (rederive) delete out.vat_classification_code;
  return out;
}

function pickLine(items, a) {
  if (a.item_id !== undefined) {
    const index = items.findIndex((item) => Number(item.id) === Number(a.item_id));
    if (index < 0) throw new Error(`Položka #${a.item_id} na dokladu není.`);
    return index;
  }
  if (a.row !== undefined) {
    const row = Number(a.row);
    if (!(row >= 1 && row <= items.length)) {
      throw new Error(`Doklad má ${items.length} položek, položka ${a.row} neexistuje.`);
    }
    return row - 1;
  }
  throw new Error('Zadejte `item_id`, nebo pořadí položky `row` (od 1). Obojí vrátí `get_purchase_invoice`.');
}

/** Koncept, jehož položky se dají bezpečně nahradit. */
async function loadEditableLines(c, invoiceId, tool) {
  const pi = await loadDraftPurchase(c, invoiceId, tool);
  if (Array.isArray(pi.vat_overrides) && pi.vat_overrides.length > 0) {
    throw new Error(
      'Doklad má ruční rekapitulaci DPH podle dokladu (§ 73). Po změně položek by nesouhlasila, '
      + 'proto položky upravte v aplikaci, kde se rekapitulace kontroluje spolu s nimi.',
    );
  }
  return { pi, items: pi.items ?? [] };
}

const saveLines = (c, invoiceId, lines, tool) => c.put(`${BASE}/${seg(invoiceId)}/items`, { items: lines }, tool);

const lineSummary = (item) => `${item.description} (${item.quantity} ${item.unit ?? ''} × ${item.unit_price_without_vat})`;

/** `expense_kind` je autoritativní, `is_fixed_asset` z něj server odvozuje; nesmí si odporovat. */
function applyExpenseKind(line, kind) {
  line.expense_kind = kind === '' ? null : kind;
  line.is_fixed_asset = line.expense_kind === 'fixed_asset';
}

/** Odpověď QR bez obrázku: base64 PNG by jen zabral kontext, soubory MCP nevrací. */
function withoutQrImage(res) {
  if (!res || typeof res !== 'object') return res;
  const { qr_data_uri: image, ...rest } = res;
  return { ...rest, has_qr_image: Boolean(image) };
}

const nullIfEmpty = (value) => (value === '' ? null : value);

const ITEM_INPUT = {
  description: str('Text položky.'),
  quantity: num('Množství. Nesmí být nula. U dobropisu stačí záporné množství NEBO záporná cena, ne obojí.'),
  unit: str('Měrná jednotka, výchozí „ks".'),
  unit_price_without_vat: num('Jednotková cena (bez DPH, u dokladu s `prices_include_vat` s DPH).'),
  vat_rate_id: id('ID sazby DPH podle dokladu, viz `list_vat_rates`. NEHÁDEJ, vezmi ji z dokladu.'),
  vat_classification_code: str(
    'Kód zařazení pro přiznání a kontrolní hlášení. Zadávej jen na výslovný pokyn; '
    + 'bez něj ho server odvodí ze sazby, země dodavatele a přenesené povinnosti.',
  ),
  expense_kind: str('Druh nákladu (podvojné účetnictví).', { enum: EXPENSE_KINDS }),
  accrual_from: date('Časové rozlišení nákladu od (RRRR-MM-DD).'),
  accrual_to: date('Časové rozlišení nákladu do (RRRR-MM-DD).'),
  stock_item_id: id('Volitelná vazba na skladovou kartu (jen pro předvyplnění příjemky).'),
};

const ITEM_PICK = {
  item_id: id('ID položky.'),
  row: int('Pořadí položky, od 1. Alternativa k `item_id`.', { minimum: 1 }),
};

/** Klíče hlavičky, které smí `update_purchase_invoice` změnit. */
const UPDATE_KEYS = [
  'vendor_id', 'vendor_invoice_number', 'document_kind', 'issue_date', 'tax_date',
  'delivery_date', 'due_date', 'received_at', 'reverse_charge', 'prices_include_vat',
  'language', 'note_above_items', 'varsymbol', 'payment_method', 'expense_category_id',
  'project_id', 'vat_deduction', 'vat_deduction_percent', 'tax_deductible',
  'parent_purchase_invoice_id',
];

/** Volitelné sloupce: zapisují se jen s klíčem v těle, proto jen při změně. */
const UPDATE_OPTIONAL = ['project_id', 'varsymbol', 'payment_method', 'parent_purchase_invoice_id'];

export const PURCHASE_TOOLS = [
  // ──────────────────────────────────────────────────────────────────────────
  // Pořízení a úprava konceptu
  // ──────────────────────────────────────────────────────────────────────────
  {
    name: 'create_purchase_invoice',
    api: [
      ['POST', '/api/purchase-invoices'],
      ['GET', '/api/codebooks/currencies'],
      ['GET', '/api/codebooks/cnb-rate'],
    ],
    title: 'Založit přijatou fakturu (koncept)',
    description:
      'Zapíše došlý doklad od dodavatele jako KONCEPT. Do evidence DPH, závazků a účetnictví '
      + 'vstoupí až přijetím (`receive_purchase_invoice`), do té doby jde koncept opravit nebo smazat.\n\n'
      + 'Dodavatele dohledej přes `search_clients` s `role: "vendors"`, nový založ `create_client`. '
      + 'Sazbu DPH (`list_vat_rates`) a DUZP NEHÁDEJ, ber je z dokladu: DUZP a datum přijetí '
      + 'rozhodují o období, ve kterém se uplatní odpočet DPH. Chybí-li DUZP na dokladu, nezadávej ho.\n\n'
      + 'U cizí měny se bez `exchange_rate` načte kurz ČNB k DUZP (jinak k datu vystavení). '
      + 'Zálohová faktura je `document_kind: "advance"`, daňový doklad k zaplacené záloze `tax_document`; '
      + 'konečnou fakturu se zálohou spáruje `link_purchase_advance`.',
    inputSchema: schema({
      vendor_id: id('ID dodavatele (karta odběratele / dodavatele).'),
      vendor_invoice_number: str('Číslo dokladu dodavatele, max 50 znaků.', { maxLength: 50 }),
      document_kind: str(
        'Druh dokladu: faktura, účtenka, dobropis (záporné částky), zálohová faktura, daňový doklad k záloze. Výchozí faktura.',
        { enum: DOCUMENT_KINDS },
      ),
      issue_date: date('Datum vystavení z dokladu.'),
      due_date: date('Datum splatnosti z dokladu.'),
      tax_date: date('DUZP z dokladu. Nehádej; chybí-li na dokladu, vynech.'),
      delivery_date: date('Datum dodání (u pořízení zboží z EU se z něj počítá DUZP). Jen je-li na dokladu.'),
      received_at: date(
        'Datum, kdy doklad skutečně dorazil. Rozhoduje o období odpočtu DPH (§ 73). '
        + 'Zadej jen skutečné datum; bez něj se použije datum vystavení.',
      ),
      currency: str('Kód měny dokladu, např. CZK nebo EUR. Alternativa k `currency_id`.', { minLength: 3, maxLength: 3 }),
      currency_id: id('ID měny z `list_currencies`. Alternativa k `currency`.'),
      exchange_rate: num('Ruční kurz místo kurzu ČNB. Jen na výslovný pokyn (pevný kurz z dokladu).', { exclusiveMinimum: 0 }),
      reverse_charge: bool('Přenesená daňová povinnost (daň přiznává odběratel).'),
      prices_include_vat: bool('Ceny položek jsou včetně DPH (DPH se dopočítá shora).'),
      vat_deduction: str('Nárok na odpočet DPH. U dodavatele neplátce server sám nastaví „none".', { enum: VAT_DEDUCTIONS }),
      vat_deduction_percent: num('Procento odpočtu u poměrného nároku (§ 75).', { minimum: 0, maximum: 100 }),
      tax_deductible: bool('Daňově uznatelný náklad pro daň z příjmů. Výchozí ano.'),
      expense_category_id: id('Kategorie nákladu (`list_expense_categories`). Bez ní se použije výchozí kategorie dodavatele.'),
      project_id: id('Zakázka, ke které náklad patří.'),
      payment_method: str('Forma úhrady. Inkaso a SIPO jsou `direct_debit`, jinak se zaplatí podruhé příkazem.', { enum: PAYMENT_METHODS }),
      parent_purchase_invoice_id: id('U dobropisu opravovaná faktura, u daňového dokladu k záloze zálohová faktura.'),
      varsymbol: str('Ruční interní číslo dokladu; jinak se přidělí při přijetí.', { maxLength: 20 }),
      language: str('Jazyk dokladu.', { enum: ['cs', 'en'] }),
      note: str('Poznámka pod položkami.'),
      note_above_items: str('Poznámka nad položkami.'),
      items: {
        type: 'array',
        description: 'Položky dokladu. Součty a DPH spočítá server.',
        minItems: 1,
        items: schema(ITEM_INPUT, ['description', 'quantity', 'unit_price_without_vat', 'vat_rate_id']),
      },
    }, ['vendor_id', 'vendor_invoice_number', 'issue_date', 'due_date', 'items']),
    write: true,
    run: async (c, a, tool) => {
      if (a.currency === undefined && a.currency_id === undefined) {
        throw new Error('Zadejte měnu dokladu (`currency`, např. CZK), nebo `currency_id`.');
      }
      for (const [i, item] of a.items.entries()) {
        if (!(Number(item.quantity) !== 0)) throw new Error(`Položka ${i + 1}: množství nesmí být nula.`);
      }
      const currency = await resolveCurrency(c, a, tool);
      const body = {
        vendor_id: a.vendor_id,
        vendor_invoice_number: String(a.vendor_invoice_number).trim(),
        issue_date: a.issue_date,
        due_date: a.due_date,
        currency_id: currency.id,
        ...changed(a, [
          'document_kind', 'tax_date', 'delivery_date', 'received_at', 'reverse_charge',
          'prices_include_vat', 'vat_deduction', 'vat_deduction_percent', 'tax_deductible',
          'expense_category_id', 'project_id', 'payment_method', 'parent_purchase_invoice_id',
          'varsymbol', 'language', 'note_above_items',
        ]),
      };
      if (a.note !== undefined) body.note_below_items = a.note;
      if (currency.code !== 'CZK') {
        Object.assign(body, a.exchange_rate !== undefined
          ? { exchange_rate: a.exchange_rate, exchange_rate_date: a.tax_date ?? a.issue_date, exchange_rate_source: 'user' }
          : await cnbRate(c, currency.code, a.tax_date, a.issue_date, tool));
      }
      body.items = a.items.map((item, index) => {
        const line = {
          description: String(item.description).trim(),
          quantity: Number(item.quantity),
          unit: String(item.unit ?? 'ks').trim() || 'ks',
          unit_price_without_vat: Number(item.unit_price_without_vat),
          vat_rate_id: item.vat_rate_id,
          order_index: index,
          ...changed(item, ['vat_classification_code', 'accrual_from', 'accrual_to', 'stock_item_id']),
        };
        if (item.expense_kind !== undefined) applyExpenseKind(line, item.expense_kind);
        return line;
      });
      return c.post(BASE, body, tool);
    },
  },
  {
    name: 'update_purchase_invoice',
    api: [
      ['GET', '/api/purchase-invoices/{id}'],
      ['GET', '/api/codebooks/currencies'],
      ['GET', '/api/clients/{id}'],
      ['PUT', '/api/purchase-invoices/{id}'],
    ],
    title: 'Upravit hlavičku konceptu přijaté faktury',
    description:
      'Změní hlavičku KONCEPTU přijaté faktury: dodavatele, číslo dokladu, data, měnu, druh dokladu, '
      + 'odpočet DPH, kategorii nákladu, zakázku, formu úhrady, poznámky. Zadaná pole se změní, '
      + 'ostatní zůstanou, položky beze změny. Na položky jsou `add_purchase_invoice_item`, '
      + '`update_purchase_invoice_item` a `remove_purchase_invoice_item`.\n\n'
      + 'POZOR na daně: DUZP a datum přijetí přesouvají odpočet DPH do jiného období, měnit je jen na výslovný pokyn. '
      + 'Po změně dodavatele nebo přenesené povinnosti server znovu odvodí zařazení všech položek pro přiznání. '
      + 'Po změně měny nebo DUZP se kurz načte znovu z ČNB (ruční kurz zůstane).\n\n'
      + 'Přijatou nebo zaúčtovanou fakturu nástroj odmítne, tu opravuje účetní v aplikaci.',
    inputSchema: schema({
      id: id('ID konceptu přijaté faktury.'),
      vendor_id: id('Jiný dodavatel.'),
      vendor_invoice_number: str('Číslo dokladu dodavatele.', { maxLength: 50 }),
      document_kind: str('Druh dokladu.', { enum: DOCUMENT_KINDS }),
      issue_date: date('Datum vystavení.'),
      due_date: date('Datum splatnosti.'),
      tax_date: str('DUZP (RRRR-MM-DD). Prázdný text DUZP smaže. Jen na výslovný pokyn.'),
      delivery_date: str('Datum dodání (RRRR-MM-DD). Prázdný text ho smaže.'),
      received_at: date('Datum přijetí dokladu. Mění období odpočtu DPH, jen na výslovný pokyn.'),
      currency: str('Kód měny, např. EUR. Alternativa k `currency_id`.', { minLength: 3, maxLength: 3 }),
      currency_id: id('ID měny z `list_currencies`.'),
      reverse_charge: bool('Přenesená daňová povinnost.'),
      prices_include_vat: bool(
        'Ceny položek jsou včetně DPH. POZOR: čísla na položkách zůstanou, změní se jejich význam '
        + 'a tím celková částka. Ověř si to s uživatelem.',
      ),
      vat_deduction: str('Nárok na odpočet DPH.', { enum: VAT_DEDUCTIONS }),
      vat_deduction_percent: num('Procento odpočtu u poměrného nároku (§ 75).', { minimum: 0, maximum: 100 }),
      tax_deductible: bool('Daňově uznatelný náklad pro daň z příjmů.'),
      expense_category_id: id('Kategorie nákladu, viz `list_expense_categories`.'),
      project_id: int('Zakázka. 0 zakázku z dokladu odebere.', { minimum: 0 }),
      payment_method: str('Forma úhrady.', { enum: PAYMENT_METHODS }),
      parent_purchase_invoice_id: int('Vazba dobropisu na fakturu, resp. daňového dokladu na zálohu. 0 vazbu zruší.', { minimum: 0 }),
      varsymbol: str('Ruční interní číslo; prázdný text = přidělí se při přijetí.', { maxLength: 20 }),
      language: str('Jazyk dokladu.', { enum: ['cs', 'en'] }),
      note: str('Poznámka pod položkami. Prázdný text ji smaže.'),
      note_above_items: str('Poznámka nad položkami. Prázdný text ji smaže.'),
    }, ['id']),
    write: true,
    run: async (c, a, tool) => {
      const changes = changed(a, UPDATE_KEYS);
      if (a.note !== undefined) changes.note_below_items = a.note;
      for (const key of ['tax_date', 'delivery_date']) {
        if (changes[key] !== undefined) changes[key] = nullIfEmpty(changes[key]);
      }
      for (const key of ['project_id', 'parent_purchase_invoice_id']) {
        if (changes[key] === 0) changes[key] = null;
      }
      const hasCurrency = a.currency !== undefined || a.currency_id !== undefined;
      if (Object.keys(changes).length === 0 && !hasCurrency) {
        throw new Error('Není co měnit. Zadejte aspoň jedno pole hlavičky.');
      }

      const pi = await loadDraftPurchase(c, a.id, tool);
      if (hasCurrency) changes.currency_id = (await resolveCurrency(c, a, tool)).id;

      const body = merged(pi, changes, PURCHASE_HEADER_FIELDS);
      for (const key of UPDATE_OPTIONAL) {
        if (changes[key] !== undefined) body[key] = changes[key];
      }

      const vendorChanged = changes.vendor_id !== undefined && Number(changes.vendor_id) !== Number(pi.vendor_id);
      if (vendorChanged) {
        // Plátcovství je na dokladu zmrazené (snapshot); bez klíče by u nového
        // dodavatele zůstal stav toho starého.
        const vendor = await c.get(`/clients/${seg(changes.vendor_id)}`, null, tool);
        if (vendor?.is_vat_payer !== undefined && vendor?.is_vat_payer !== null) {
          body.vendor_is_vat_payer = Boolean(vendor.is_vat_payer);
          if (!body.vendor_is_vat_payer && a.vat_deduction === undefined) delete body.vat_deduction;
        }
      }

      const rederive = PURCHASE_TAX_CONTEXT.some(
        (key) => changes[key] !== undefined && changes[key] !== pi[key],
      ) || vendorChanged;
      if (rederive) {
        body.vat_classification_code = null;
        body.items = (pi.items ?? []).map((item) => lineForPut(item, { rederive: true }));
      }

      const saved = await c.put(`${BASE}/${seg(a.id)}`, body, tool);
      return { purchase_invoice_id: Number(a.id), changed: changes, items_reclassified: rederive, invoice: saved };
    },
  },
  {
    name: 'add_purchase_invoice_item',
    api: [
      ['GET', '/api/purchase-invoices/{id}'],
      ['PUT', '/api/purchase-invoices/{id}/items'],
    ],
    title: 'Přidat položku na koncept přijaté faktury',
    description:
      'Přidá položku na KONCEPT přijaté faktury. Existující položky zůstanou beze změny včetně '
      + 'skrytých údajů (druh nákladu, účet, časové rozlišení, sklad, pořadí).\n\n'
      + 'Sazbu DPH NEHÁDEJ: `vat_rate_id` je povinné a patří z dokladu (`list_vat_rates`). '
      + 'Cena je bez DPH, pokud doklad nemá `prices_include_vat`.',
    inputSchema: schema({
      id: id('ID konceptu přijaté faktury.'),
      ...ITEM_INPUT,
      position: int('Pořadí, na které se položka vloží (1 = první). Výchozí je na konec.', { minimum: 1 }),
    }, ['id', 'description', 'quantity', 'unit_price_without_vat', 'vat_rate_id']),
    write: true,
    run: async (c, a, tool) => {
      const quantity = Number(a.quantity);
      if (!Number.isFinite(quantity) || quantity === 0) throw new Error('Množství nesmí být nula.');
      if (String(a.description ?? '').trim() === '') throw new Error('Zadejte text položky.');

      const { items } = await loadEditableLines(c, a.id, tool);
      const lines = items.map((item) => lineForPut(item));
      const added = {
        description: String(a.description).trim(),
        quantity,
        unit: String(a.unit ?? 'ks').trim() || 'ks',
        unit_price_without_vat: Number(a.unit_price_without_vat),
        vat_rate_id: a.vat_rate_id,
        ...changed(a, ['vat_classification_code', 'accrual_from', 'accrual_to', 'stock_item_id']),
      };
      if (a.expense_kind !== undefined) applyExpenseKind(added, a.expense_kind);
      const at = a.position !== undefined ? Math.min(Number(a.position), lines.length + 1) - 1 : lines.length;
      lines.splice(at, 0, added);
      lines.forEach((line, index) => { line.order_index = index; });

      const saved = await saveLines(c, a.id, lines, tool);
      return { purchase_invoice_id: Number(a.id), added: { ...added, row: at + 1 }, invoice: saved };
    },
  },
  {
    name: 'update_purchase_invoice_item',
    api: [
      ['GET', '/api/purchase-invoices/{id}'],
      ['PUT', '/api/purchase-invoices/{id}/items'],
    ],
    title: 'Upravit položku konceptu přijaté faktury',
    description:
      'Změní jednu položku KONCEPTU přijaté faktury, určenou `item_id` nebo pořadím `row` '
      + '(1 = první, jak je vidí uživatel; oboje vrátí `get_purchase_invoice`). Změní se jen '
      + 'zadaná pole, ostatní pole položky i ostatní položky zůstanou.\n\n'
      + 'Sazbu DPH měň jen na výslovný pokyn; zařazení řádku pro přiznání pak server odvodí znovu.',
    inputSchema: schema({
      id: id('ID konceptu přijaté faktury.'),
      ...ITEM_PICK,
      description: str('Nový text položky.'),
      quantity: num('Nové množství. Nesmí být nula.'),
      unit: str('Nová měrná jednotka.'),
      unit_price_without_vat: num('Nová jednotková cena (bez DPH, u dokladu s `prices_include_vat` s DPH).'),
      vat_rate_id: id('Nová sazba DPH, viz `list_vat_rates`. Jen na výslovný pokyn.'),
      vat_classification_code: str('Kód zařazení pro přiznání. Jen na výslovný pokyn; prázdný text = odvodit znovu.'),
      expense_kind: str('Druh nákladu; prázdný text ho zruší.', { enum: [...EXPENSE_KINDS, ''] }),
      accrual_from: str('Časové rozlišení od (RRRR-MM-DD); prázdný text ho zruší.'),
      accrual_to: str('Časové rozlišení do (RRRR-MM-DD); prázdný text ho zruší.'),
    }, ['id']),
    write: true,
    run: async (c, a, tool) => {
      const changes = changed(a, [
        'description', 'quantity', 'unit', 'unit_price_without_vat', 'vat_rate_id',
        'vat_classification_code', 'expense_kind', 'accrual_from', 'accrual_to',
      ]);
      if (Object.keys(changes).length === 0) throw new Error('Není co měnit. Zadejte aspoň jedno pole položky.');
      if (changes.quantity !== undefined && !(Number(changes.quantity) !== 0)) {
        throw new Error('Množství nesmí být nula.');
      }

      const { items } = await loadEditableLines(c, a.id, tool);
      const index = pickLine(items, a);
      const before = items[index];
      const rateChanged = changes.vat_rate_id !== undefined && Number(changes.vat_rate_id) !== Number(before.vat_rate_id);
      const lines = items.map((item, i) => lineForPut(item, {
        rederive: i === index && rateChanged && changes.vat_classification_code === undefined,
      }));
      const line = lines[index];
      for (const key of ['description', 'quantity', 'unit', 'unit_price_without_vat', 'vat_rate_id']) {
        if (changes[key] !== undefined) line[key] = changes[key];
      }
      for (const key of ['accrual_from', 'accrual_to', 'vat_classification_code']) {
        if (changes[key] !== undefined) line[key] = nullIfEmpty(changes[key]);
      }
      if (changes.expense_kind !== undefined) applyExpenseKind(line, changes.expense_kind);
      if (changes.unit !== undefined && changes.unit !== before.unit) {
        line.duration_minutes = null;
      } else if (changes.quantity !== undefined && line.duration_minutes != null) {
        // U časové položky platí délka v minutách a množství z ní server dopočítá.
        line.duration_minutes = Math.round(Number(changes.quantity) * 60);
      }

      const saved = await saveLines(c, a.id, lines, tool);
      return {
        purchase_invoice_id: Number(a.id),
        row: index + 1,
        before: {
          description: before.description, quantity: before.quantity, unit: before.unit,
          unit_price_without_vat: before.unit_price_without_vat, vat_rate_id: before.vat_rate_id,
        },
        changed: changes,
        invoice: saved,
      };
    },
  },
  {
    name: 'remove_purchase_invoice_item',
    api: [
      ['GET', '/api/purchase-invoices/{id}'],
      ['PUT', '/api/purchase-invoices/{id}/items'],
    ],
    title: 'Odebrat položku z konceptu přijaté faktury',
    description:
      'Odebere jednu položku KONCEPTU přijaté faktury, určenou `item_id` nebo pořadím `row`. '
      + 'Ostatní položky zůstanou beze změny. Bez `confirm: true` nic neodebere a jen vrátí, '
      + 'která položka by zmizela.',
    inputSchema: schema({ id: id('ID konceptu přijaté faktury.'), ...ITEM_PICK, confirm: CONFIRM }, ['id']),
    write: true,
    destructive: true,
    run: async (c, a, tool) => {
      const { pi, items } = await loadEditableLines(c, a.id, tool);
      const index = pickLine(items, a);
      const target = items[index];
      requireConfirm(a, `Z konceptu ${purchaseLabel(pi, a.id)} se má odebrat položka`,
        `${index + 1}. ${lineSummary(target)}`);

      const lines = items.filter((_, i) => i !== index).map((item) => lineForPut(item));
      const saved = await saveLines(c, a.id, lines, tool);
      return { purchase_invoice_id: Number(a.id), removed: { row: index + 1, ...lineForPut(target) }, invoice: saved };
    },
  },
  {
    name: 'delete_purchase_invoice',
    api: [
      ['GET', '/api/purchase-invoices/{id}'],
      ['DELETE', '/api/purchase-invoices/{id}'],
    ],
    title: 'Smazat koncept přijaté faktury',
    description:
      'Smaže KONCEPT přijaté faktury i s položkami. Přijatý, uhrazený nebo zaúčtovaný doklad '
      + 'smazat nejde, ten se stornuje (`cancel_purchase_invoice`). Bez `confirm: true` jen ukáže, '
      + 'který doklad by se smazal.',
    inputSchema: schema({ id: id('ID konceptu přijaté faktury.'), confirm: CONFIRM }, ['id']),
    write: true,
    destructive: true,
    run: async (c, a, tool) => {
      const pi = await loadDraftPurchase(c, a.id, tool);
      requireConfirm(a, 'Smazat se má koncept přijaté faktury', purchaseLabel(pi, a.id));
      return { deleted: purchaseLabel(pi, a.id), result: await c.del(`${BASE}/${seg(a.id)}`, tool) };
    },
  },

  // ──────────────────────────────────────────────────────────────────────────
  // Stav dokladu (bez zaúčtování)
  // ──────────────────────────────────────────────────────────────────────────
  {
    name: 'receive_purchase_invoice',
    api: [
      ['GET', '/api/purchase-invoices/{id}'],
      ['POST', '/api/purchase-invoices/{id}/transition'],
    ],
    title: 'Přijmout přijatou fakturu',
    description:
      'Převede doklad do stavu PŘIJATO. Z konceptu: přidělí interní číslo, doklad vstoupí do evidence '
      + 'DPH (odpočet v období podle DUZP a data přijetí), do závazků a do platebních příkazů. '
      + 'MÁ-LI FIRMA ZAPNUTÉ AUTOMATICKÉ ÚČTOVÁNÍ PŘIJATÝCH FAKTUR, SERVER DOKLAD ROVNOU ZAÚČTUJE; '
      + 'u hotovostní úhrady z pokladny vznikne výdajový pokladní doklad.\n\n'
      + 'Vyžaduje-li koncept schválení manažerem střediska, server ho místo přijetí odešle ke schválení '
      + '(odpověď `approval_requested: true`, doklad zůstane konceptem a přijme se sám po schválení).\n\n'
      + 'Z uhrazeného dokladu tím zrušíš označení úhrady, ze stornovaného obnovíš doklad '
      + '(vrátí se do evidence DPH). Vždy vyžaduje `confirm: true`; první volání jen ukáže dopad.',
    inputSchema: schema({ id: id('ID přijaté faktury.'), confirm: CONFIRM }, ['id']),
    write: true,
    destructive: true,
    run: async (c, a, tool) => {
      const pi = await loadPurchase(c, a.id, tool);
      const action = {
        draft: 'Přijmout se má koncept (vstoupí do evidence DPH a závazků; se zapnutým automatickým účtováním se zaúčtuje)',
        paid: 'U přijaté faktury se má zrušit označení úhrady',
        cancelled: 'Obnovit se má stornovaná přijatá faktura (vrátí se do evidence DPH a závazků)',
      }[pi?.status];
      if (!action) {
        throw new Error(`Přijatá faktura ${purchaseLabel(pi, a.id)} už je přijatá, do stavu „přijato" ji převést nejde.`);
      }
      requireConfirm(a, action, purchaseLabel(pi, a.id));
      return c.post(`${BASE}/${seg(a.id)}/transition`, { target: 'received' }, tool);
    },
  },
  {
    name: 'mark_purchase_invoice_paid',
    api: [
      ['POST', '/api/purchase-invoices/{id}/transition'],
    ],
    title: 'Označit přijatou fakturu jako uhrazenou',
    description:
      'Zaeviduje úhradu přijaté faktury. Jen tam, kde úhrada nepřijde z bankovního výpisu nebo '
      + 'pokladny (ty se párují samy). V daňové evidenci tím výdaj vstoupí do peněžního deníku '
      + 'k datu úhrady. Doklad musí být přijatý; zpět jde přes `receive_purchase_invoice`.',
    inputSchema: schema({
      id: id('ID přijaté faktury.'),
      paid_date: date('Datum úhrady (RRRR-MM-DD). Výchozí dnes.'),
    }, ['id']),
    write: true,
    run: (c, a, tool) => c.post(`${BASE}/${seg(a.id)}/transition`, {
      target: 'paid', ...changed(a, ['paid_date']),
    }, tool),
  },
  {
    name: 'cancel_purchase_invoice',
    api: [
      ['GET', '/api/purchase-invoices/{id}'],
      ['POST', '/api/purchase-invoices/{id}/transition'],
    ],
    title: 'Stornovat přijatou fakturu',
    description:
      'Stornuje přijatou fakturu: vypadne z evidence DPH a závazků, aktivní účetní zápis i automaticky '
      + 'založený pokladní doklad server stornuje. V uzavřeném období server storno odmítne. '
      + 'Koncept raději smaž (`delete_purchase_invoice`). Bez `confirm: true` jen ukáže, který doklad '
      + 'by se stornoval.',
    inputSchema: schema({ id: id('ID přijaté faktury.'), confirm: CONFIRM }, ['id']),
    write: true,
    destructive: true,
    run: async (c, a, tool) => {
      const pi = await loadPurchase(c, a.id, tool);
      if (pi?.status === 'cancelled') throw new Error(`Přijatá faktura ${purchaseLabel(pi, a.id)} už je stornovaná.`);
      requireConfirm(a, 'Stornovat se má přijatá faktura', purchaseLabel(pi, a.id));
      return c.post(`${BASE}/${seg(a.id)}/transition`, { target: 'cancelled' }, tool);
    },
  },

  // ──────────────────────────────────────────────────────────────────────────
  // Zařazení dokladu
  // ──────────────────────────────────────────────────────────────────────────
  {
    name: 'set_purchase_invoice_document_kind',
    api: false,
    apiReason: NOT_IN_CLIENT_CATALOG,
    title: 'Změnit druh přijatého dokladu',
    description:
      'Rychlá oprava druhu dokladu po importu (faktura, účtenka, dobropis). Částky se nemění. '
      + 'Na zálohu a ze zálohy jde jen v editoru (`update_purchase_invoice` u konceptu). '
      + 'Zaúčtovaný doklad nástroj odmítne.',
    inputSchema: schema({
      id: id('ID přijaté faktury.'),
      document_kind: str('Nový druh dokladu.', { enum: ['invoice', 'receipt', 'credit_note'] }),
    }, ['id', 'document_kind']),
    write: true,
    run: async (c, a, tool) => {
      const pi = await loadPurchase(c, a.id, tool);
      refuseIfPosted(pi, a.id, 'Změna druhu dokladu');
      return c.post(`${BASE}/${seg(a.id)}/document-kind`, { document_kind: a.document_kind }, tool);
    },
  },
  {
    name: 'set_purchase_invoice_project',
    api: [
      ['POST', '/api/purchase-invoices/{id}/project'],
    ],
    title: 'Zařadit přijatou fakturu k zakázce',
    description:
      'Přiřadí náklad k zakázce, nebo ho z ní vyřadí (`project_id: 0`). Jde i u zaúčtovaného dokladu: '
      + 'zakázka je jen analytický údaj a server u zápisu přepíše pouze ten, účty ani částky ne. '
      + 'Stornovaný doklad nejde.',
    inputSchema: schema({
      id: id('ID přijaté faktury.'),
      project_id: int('ID zakázky (`list_projects`); 0 zakázku odebere.', { minimum: 0 }),
    }, ['id', 'project_id']),
    write: true,
    run: (c, a, tool) => c.post(`${BASE}/${seg(a.id)}/project`, {
      project_id: Number(a.project_id) > 0 ? a.project_id : null,
    }, tool),
  },
  {
    name: 'set_purchase_invoice_expense_kinds',
    api: false,
    apiReason: NOT_IN_CLIENT_CATALOG,
    title: 'Nastavit druh nákladu po položkách',
    description:
      'Nastaví druh nákladu (služba, materiál, drobný majetek, dlouhodobý majetek…) jednotlivým '
      + 'položkám přijaté faktury, typicky po importu. Položky neuvedené v seznamu zůstanou. '
      + 'Volba je ruční rozhodnutí účetní a přebije automatickou klasifikaci. '
      + 'Zaúčtovaný doklad nástroj odmítne: změna by vyžadovala přeúčtování.',
    inputSchema: schema({
      id: id('ID přijaté faktury.'),
      items: {
        type: 'array',
        minItems: 1,
        description: 'Položky a jejich druh nákladu.',
        items: schema({
          ...ITEM_PICK,
          expense_kind: { type: ['string', 'null'], enum: [...EXPENSE_KINDS, null], description: 'Druh nákladu; null ho zruší.' },
        }, ['expense_kind']),
      },
    }, ['id', 'items']),
    write: true,
    run: async (c, a, tool) => {
      const pi = await loadPurchase(c, a.id, tool);
      if (pi?.status === 'cancelled') throw new Error('Stornovaný doklad nelze upravit.');
      refuseIfPosted(pi, a.id, 'Změna druhu nákladu');
      const items = pi.items ?? [];
      const body = a.items.map((entry) => ({
        id: Number(items[pickLine(items, entry)].id),
        expense_kind: entry.expense_kind ?? null,
      }));
      return c.put(`${BASE}/${seg(a.id)}/expense-kinds`, { items: body }, tool);
    },
  },
  {
    name: 'set_purchase_invoice_exchange_rate',
    api: false,
    apiReason: NOT_IN_CLIENT_CATALOG,
    title: 'Nastavit kurz konceptu přijaté faktury',
    description:
      'Nastaví ruční kurz KONCEPTU přijaté faktury v cizí měně (pevný kurz z dokladu), '
      + 'nebo ho zruší (`rate: null`). Ruční kurz pak automatické přenačtení z ČNB nepřepíše. '
      + 'Mění korunové částky nákladu i DPH, zadávej jen na výslovný pokyn.',
    inputSchema: schema({
      id: id('ID konceptu přijaté faktury.'),
      rate: { type: ['number', 'null'], exclusiveMinimum: 0, description: 'Kurz (Kč za jednotku měny); null kurz zruší.' },
      rate_date: date('Den, ke kterému kurz platí. Výchozí DUZP, jinak datum vystavení.'),
    }, ['id', 'rate']),
    write: true,
    run: async (c, a, tool) => {
      const pi = await loadDraftPurchase(c, a.id, tool);
      if (String(pi.currency ?? '').toUpperCase() === 'CZK') {
        throw new Error('Doklad je v korunách, kurz nemá.');
      }
      return c.post(`${BASE}/${seg(a.id)}/exchange-rate`, {
        rate: a.rate,
        rate_date: a.rate === null ? null : (a.rate_date ?? pi.tax_date ?? pi.issue_date),
        source: 'user',
      }, tool);
    },
  },

  // ──────────────────────────────────────────────────────────────────────────
  // Zálohy
  // ──────────────────────────────────────────────────────────────────────────
  {
    name: 'list_purchase_advance_candidates',
    api: [
      ['GET', '/api/purchase-invoices/{id}/advance-candidates'],
    ],
    title: 'Zálohy k vyúčtování přijaté faktury',
    description:
      'Nespárované zálohové faktury a samostatné daňové doklady k záloze od stejného dodavatele, '
      + 'které jde s konečnou fakturou spárovat. Nejbližší částka první.',
    inputSchema: schema({ id: id('ID konečné přijaté faktury.') }, ['id']),
    write: false,
    run: (c, a, tool) => c.get(`${BASE}/${seg(a.id)}/advance-candidates`, null, tool),
  },
  {
    name: 'list_purchase_settlement_candidates',
    api: [
      ['GET', '/api/purchase-invoices/{id}/settlement-candidates'],
    ],
    title: 'Faktury k vyúčtování zálohy',
    description: 'Opačný směr: z detailu zálohy nepropojené konečné faktury stejného dodavatele.',
    inputSchema: schema({ id: id('ID zálohové faktury.') }, ['id']),
    write: false,
    run: (c, a, tool) => c.get(`${BASE}/${seg(a.id)}/settlement-candidates`, null, tool),
  },
  {
    name: 'link_purchase_advance',
    api: [
      ['GET', '/api/purchase-invoices/{id}'],
      ['POST', '/api/purchase-invoices/{id}/link-advance'],
    ],
    title: 'Spárovat přijatou fakturu se zálohou',
    description:
      'Spáruje konečnou fakturu se zálohou (nebo samostatným daňovým dokladem k záloze), ať se '
      + 'náklad nepočítá dvakrát. Nemá-li faktura vyplněnou zálohu, server ji doplní uhrazenou částkou '
      + 'zálohy a sníží tím „k úhradě". Varování `advance_has_tax_document` znamená, že DPH ze zálohy už '
      + 'byla uplatněna a z konečné faktury se smí uplatnit jen doplatek. Zaúčtovaný doklad nástroj odmítne.',
    inputSchema: schema({
      id: id('ID konečné přijaté faktury.'),
      advance_id: id('ID zálohy z `list_purchase_advance_candidates`.'),
    }, ['id', 'advance_id']),
    write: true,
    run: async (c, a, tool) => {
      const pi = await loadPurchase(c, a.id, tool);
      refuseIfPosted(pi, a.id, 'Spárování se zálohou');
      return c.post(`${BASE}/${seg(a.id)}/link-advance`, { advance_id: a.advance_id }, tool);
    },
  },
  {
    name: 'unlink_purchase_advance',
    api: [
      ['GET', '/api/purchase-invoices/{id}'],
      ['DELETE', '/api/purchase-invoices/{id}/link-advance'],
    ],
    title: 'Zrušit spárování se zálohou',
    description:
      'Zruší vazbu konečné faktury na zálohu. Odečtená záloha na faktuře zůstane, upravuje se ručně. '
      + 'Zaúčtovanou fakturu server odpojit nedovolí. Bez `confirm: true` jen ukáže, která vazba by zmizela.',
    inputSchema: schema({ id: id('ID konečné přijaté faktury.'), confirm: CONFIRM }, ['id']),
    write: true,
    destructive: true,
    run: async (c, a, tool) => {
      const pi = await loadPurchase(c, a.id, tool);
      refuseIfPosted(pi, a.id, 'Zrušení vazby na zálohu');
      const advance = pi?.linked_advance;
      if (!advance) throw new Error(`Přijatá faktura ${purchaseLabel(pi, a.id)} se zálohou spárovaná není.`);
      requireConfirm(a, `Od faktury ${purchaseLabel(pi, a.id)} se má odpojit záloha`,
        `${advance.vendor_invoice_number ?? advance.varsymbol ?? `#${advance.id}`}${amountText(advance)}`);
      return c.del(`${BASE}/${seg(a.id)}/link-advance`, tool);
    },
  },

  // ──────────────────────────────────────────────────────────────────────────
  // Úhrada: účet dodavatele, QR, příkazy k úhradě (bez odeslání do banky)
  // ──────────────────────────────────────────────────────────────────────────
  {
    name: 'get_purchase_invoice_payment',
    api: [
      ['GET', '/api/purchase-invoices/{id}/payment-qr'],
    ],
    title: 'Platební údaje přijaté faktury',
    description:
      'Účet dodavatele, částka k úhradě, měna a variabilní symbol pro zaplacení přijaté faktury. '
      + 'Obrázek QR kódu se nevrací (`has_qr_image` říká, jestli by šel vygenerovat).',
    inputSchema: schema({ id: id('ID přijaté faktury.') }, ['id']),
    write: false,
    run: async (c, a, tool) => withoutQrImage(await c.get(`${BASE}/${seg(a.id)}/payment-qr`, null, tool)),
  },
  {
    name: 'set_purchase_invoice_payment_account',
    api: false,
    apiReason: NOT_IN_CLIENT_CATALOG,
    title: 'Změnit účet dodavatele na přijaté faktuře',
    description:
      'Uloží účet, na který se má přijatá faktura zaplatit (použije ho QR platba i příkaz k úhradě). '
      + 'Nezadaná pole zůstanou. Změna účtu příjemce je častý podvodný trik, proto vždy vyžaduje '
      + '`confirm: true`; první volání ukáže starý a nový účet. Účet ověří `verify_purchase_payment_account`.',
    inputSchema: schema({
      id: id('ID přijaté faktury.'),
      account_number: str('Číslo účtu (u českého účtu i s předčíslím).'),
      bank_code: str('Kód banky, 4 číslice.'),
      iban: str('IBAN.'),
      bic: str('BIC / SWIFT.'),
      variable_symbol: str('Variabilní symbol platby, liší-li se od čísla dokladu.'),
      confirm: CONFIRM,
    }, ['id']),
    write: true,
    destructive: true,
    run: async (c, a, tool) => {
      const keys = ['account_number', 'bank_code', 'iban', 'bic', 'variable_symbol'];
      if (Object.keys(changed(a, keys)).length === 0) throw new Error('Zadejte aspoň jeden údaj účtu.');
      const pi = await loadPurchase(c, a.id, tool);
      const current = Object.fromEntries(keys.map((k) => [k, pi?.[`payment_${k}`] ?? null]));
      const next = merged(current, a, keys);
      const show = (acc) => [
        acc.account_number && `${acc.account_number}/${acc.bank_code ?? '?'}`,
        acc.iban && `IBAN ${acc.iban}`,
        acc.variable_symbol && `VS ${acc.variable_symbol}`,
      ].filter(Boolean).join(', ') || 'žádný';
      requireConfirm(a, `U faktury ${purchaseLabel(pi, a.id)} se má změnit účet příjemce`,
        `${show(current)} → ${show(next)}`);
      return withoutQrImage(await c.put(`${BASE}/${seg(a.id)}/payment-account`, next, tool));
    },
  },
  {
    name: 'verify_purchase_payment_account',
    api: false,
    apiReason: NOT_IN_CLIENT_CATALOG,
    title: 'Ověřit účet dodavatele v registru plátců',
    description:
      'Zkontroluje účet na přijaté faktuře proti účtům, které dodavatel jako plátce DPH zveřejnil '
      + 'v registru. Platba na nezveřejněný účet nad limit zakládá ručení za DPH (§ 109).',
    inputSchema: schema({ id: id('ID přijaté faktury.') }, ['id']),
    write: false,
    run: (c, a, tool) => c.get(`${ORDERS}/verify-account`, { invoice_id: a.id }, tool),
  },
  {
    name: 'list_purchase_payment_candidates',
    api: false,
    apiReason: NOT_IN_CLIENT_CATALOG,
    title: 'Faktury k zaplacení příkazem',
    description:
      'Neuhrazené přijaté faktury, které jde zařadit do příkazu k úhradě, a účty firmy, ze kterých '
      + 'lze platit (`payer_accounts`; jejich `id` patří do `create_purchase_payment_order`).',
    inputSchema: schema({
      currency: str('Jen faktury v této měně, např. CZK.', { minLength: 3, maxLength: 3 }),
      include_non_transfer: bool('Ukázat i faktury hrazené jinak než převodem (inkaso, karta). Do příkazu je server stejně nepustí.'),
      ...PAGING,
    }),
    write: false,
    run: (c, a, tool) => c.get(`${ORDERS}/candidates`, {
      ...changed(a, ['currency', 'page', 'per_page']),
      ...(a.include_non_transfer === undefined ? {} : { include_non_transfer: a.include_non_transfer ? 1 : 0 }),
    }, tool),
  },
  {
    name: 'list_purchase_payment_orders',
    api: false,
    apiReason: NOT_IN_CLIENT_CATALOG,
    title: 'Příkazy k úhradě',
    description: 'Historie vytvořených příkazů k úhradě (dávek) s částkou, měnou a stavem odeslání.',
    inputSchema: schema({ ...PAGING }),
    write: false,
    run: (c, a, tool) => c.get(ORDERS, changed(a, ['page', 'per_page']), tool),
  },
  {
    name: 'get_purchase_payment_order',
    api: false,
    apiReason: NOT_IN_CLIENT_CATALOG,
    title: 'Detail příkazu k úhradě',
    description: 'Dávka příkazu k úhradě s jednotlivými platbami (příjemce, účet, částka, VS).',
    inputSchema: schema({ id: id('ID příkazu k úhradě.') }, ['id']),
    write: false,
    run: (c, a, tool) => c.get(`${ORDERS}/${seg(a.id)}`, null, tool),
  },
  {
    name: 'create_purchase_payment_order',
    api: false,
    apiReason: NOT_IN_CLIENT_CATALOG,
    title: 'Vytvořit příkaz k úhradě',
    description:
      'Připraví dávku příkazu k úhradě z vybraných přijatých faktur a označí je „Zařazeno k úhradě". '
      + 'NIC NEODESÍLÁ DO BANKY a faktury neoznačí jako uhrazené: soubor pro banku (ABO, CSV, PDF) '
      + 'stáhne a odešle uživatel v aplikaci. Faktury v jiné měně, bez účtu, hrazené inkasem nebo '
      + 'bez částky k úhradě server vynechá a vrátí v `skipped`.',
    inputSchema: schema({
      invoice_ids: {
        type: 'array', minItems: 1, maxItems: 500, uniqueItems: true,
        description: 'ID přijatých faktur k zaplacení.', items: id('ID přijaté faktury.'),
      },
      payer_currency_id: id('Účet firmy, ze kterého se platí (`payer_accounts[].id` z `list_purchase_payment_candidates`).'),
      payment_date: date('Požadované datum splatnosti příkazu. Výchozí dnes, minulé datum server posune na dnešek.'),
      constant_symbol: str('Konstantní symbol pro celou dávku.'),
      note: str('Poznámka k dávce.'),
    }, ['invoice_ids', 'payer_currency_id']),
    write: true,
    run: (c, a, tool) => c.post(ORDERS, {
      invoice_ids: a.invoice_ids,
      payer_currency_id: a.payer_currency_id,
      mark_paid: false,
      ...changed(a, ['payment_date', 'constant_symbol', 'note']),
    }, tool),
  },
  {
    name: 'mark_purchase_invoices_payment_ordered',
    api: false,
    apiReason: NOT_IN_CLIENT_CATALOG,
    title: 'Označit faktury jako zařazené k úhradě',
    description:
      'Jen označí vybrané přijaté faktury „Zařazeno k úhradě" bez vytvoření dávky, třeba když je '
      + 'uživatel zaplatil ručně v bance. Úhradu neeviduje (na to je `mark_purchase_invoice_paid`).',
    inputSchema: schema({
      invoice_ids: {
        type: 'array', minItems: 1, maxItems: 500, uniqueItems: true,
        description: 'ID přijatých faktur.', items: id('ID přijaté faktury.'),
      },
    }, ['invoice_ids']),
    write: true,
    run: (c, a, tool) => c.post(`${ORDERS}/mark`, { invoice_ids: a.invoice_ids, mark_paid: false }, tool),
  },
  {
    name: 'delete_purchase_payment_order',
    api: false,
    apiReason: NOT_IN_CLIENT_CATALOG,
    title: 'Smazat příkaz k úhradě',
    description:
      'Smaže připravenou dávku příkazu k úhradě. Příkaz, u kterého je evidovaný pokus o odeslání '
      + 'do banky, server smazat nedovolí. Bez `confirm: true` jen ukáže, která dávka by se smazala.',
    inputSchema: schema({ id: id('ID příkazu k úhradě.'), confirm: CONFIRM }, ['id']),
    write: true,
    destructive: true,
    run: async (c, a, tool) => {
      const order = await c.get(`${ORDERS}/${seg(a.id)}`, null, tool);
      requireConfirm(a, 'Smazat se má příkaz k úhradě',
        `#${a.id}, ${order?.item_count ?? '?'} plateb, ${order?.total_amount ?? '?'} ${order?.currency ?? ''}, splatnost ${order?.payment_date ?? '?'}`);
      return { deleted: Number(a.id), result: await c.del(`${ORDERS}/${seg(a.id)}`, tool) };
    },
  },

  // ──────────────────────────────────────────────────────────────────────────
  // Čtení kolem dokladu
  // ──────────────────────────────────────────────────────────────────────────
  {
    name: 'get_purchase_invoice_activity',
    api: [
      ['GET', '/api/purchase-invoices/{id}/activity'],
    ],
    title: 'Historie přijaté faktury',
    description: 'Kdo a kdy doklad založil, upravil, přijal, uhradil nebo stornoval.',
    inputSchema: schema({ id: id('ID přijaté faktury.') }, ['id']),
    write: false,
    run: (c, a, tool) => c.get(`${BASE}/${seg(a.id)}/activity`, null, tool),
  },
  {
    name: 'list_expense_categories',
    api: [
      ['GET', '/api/expense-categories'],
    ],
    title: 'Kategorie nákladů',
    description: 'Číselník kategorií nákladů s jejich ID pro `expense_category_id` přijaté faktury.',
    inputSchema: schema({ include_archived: bool('Vrátit i archivované kategorie.') }),
    write: false,
    run: (c, a, tool) => c.get('/expense-categories', a.include_archived ? { include_archived: 1 } : null, tool),
  },
];

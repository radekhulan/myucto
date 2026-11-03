/**
 * Další práce s vydanými fakturami a pravidelná fakturace.
 *
 * Doplňuje základní fakturační nástroje z `tools.mjs` (čtení, koncept, vystavení,
 * odeslání) o zbytek veřejného API dokladu: smazání konceptu, storno a dobropis,
 * kopie, vyúčtování zálohy, penále, úhrady, platební kalendář, ISDOC, příjemce
 * a hromadné upomínky, a o zápis šablon pravidelné fakturace (čtení šablon je
 * v `audit-tools.mjs`).
 *
 * Zaúčtování (`/invoices/{id}/book`) tu není a být nesmí, server ho tokenu odmítá.
 *
 * Modul se načítá VÝHRADNĚ přes `tools.mjs`: pomocné funkce konceptu faktury
 * importuje odtamtud a `tools.mjs` zase registruje tenhle katalog, takže jde
 * o kruhový import. Je bezpečný proto, že importované funkce se volají až uvnitř
 * `run()`, kdy je `tools.mjs` vyhodnocený. Hodnoty potřebné už při načtení
 * (CONFIRM) jsou v `tool-shared.mjs`, který nic dalšího neimportuje.
 */

import { NOT_IN_CLIENT_CATALOG } from './api-contracts.mjs';
import { CONFIRM, requireConfirm, confirmed, changed, merged, seg } from './tool-shared.mjs';
import {
  invoiceLines, loadDraftInvoice, lineForPut, draftPayload, draftResult,
} from './tools.mjs';

const str = (description, extra = {}) => ({ type: 'string', description, ...extra });
const int = (description, extra = {}) => ({ type: 'integer', description, ...extra });
const num = (description, extra = {}) => ({ type: 'number', description, ...extra });
const bool = (description) => ({ type: 'boolean', description });
const date = (description) => str(description, { format: 'date' });
const id = (description) => int(description, { minimum: 1 });
const schema = (properties = {}, required = []) => ({
  type: 'object', properties, required, additionalProperties: false,
});

// ────────────────────────────────────────────────────────────────────────────
// Popis dokladu do potvrzovacích hlášek
// ────────────────────────────────────────────────────────────────────────────

const TYPE_LABEL = {
  invoice: 'Faktura',
  proforma: 'Zálohová faktura',
  credit_note: 'Dobropis',
  cancellation: 'Storno',
  tax_document: 'Daňový doklad k platbě',
  penalty: 'Penalizační faktura',
  payment_calendar: 'Platební kalendář',
  rent_calendar: 'Splátkový kalendář',
  advance_calendar: 'Platební kalendář',
};

const money = (amount, currency) => `${Number(amount ?? 0).toFixed(2)} ${currency ?? ''}`.trim();

/** Číslo, odběratel a částka tak, jak doklad vidí uživatel. */
function invoiceLabel(inv, fallbackId) {
  const type = TYPE_LABEL[inv?.invoice_type] ?? 'Doklad';
  const number = inv?.varsymbol ? `č. ${inv.varsymbol}` : `#${inv?.id ?? fallbackId}`;
  const client = inv?.client_company_name ? `, ${inv.client_company_name}` : '';
  return `${type} ${number}${client}, ${money(inv?.total_with_vat, inv?.currency)} (stav „${inv?.status ?? '?'}")`;
}

const invoiceSummary = (inv) => ({
  id: inv?.id,
  varsymbol: inv?.varsymbol ?? null,
  invoice_type: inv?.invoice_type,
  status: inv?.status,
  client_company_name: inv?.client_company_name ?? null,
  total_with_vat: inv?.total_with_vat,
  currency: inv?.currency,
});

const getInvoice = (c, invoiceId, tool) => c.get(`/invoices/${seg(invoiceId)}`, null, tool);

const ISSUED = ['issued', 'sent', 'reminded', 'paid'];

// ────────────────────────────────────────────────────────────────────────────
// Rozpis plateb platebního kalendáře (§ 31 a § 31a ZDPH)
//
// Server rozpis při uložení nekontroluje, součet porovná až vystavení
// (`payment_schedule_mismatch`). Model se ale o nesouhlasu musí dozvědět hned,
// dokud má podklad před sebou, ne až když uživatel kalendář vystavuje.
// ────────────────────────────────────────────────────────────────────────────

export const PAYMENT_SCHEDULE_INPUT = {
  type: 'array',
  description:
    'Rozpis plateb platebního nebo splátkového kalendáře, jeden řádek = jedna splátka '
    + 'v měně dokladu. Součet `base_amount`, `vat_amount` i `total_amount` musí sedět na doklad.',
  minItems: 1,
  maxItems: 120,
  items: schema({
    due_on: date('Datum splatnosti splátky (RRRR-MM-DD).'),
    tax_date: date(
      'Jen `rent_calendar`: DUZP splátky podle smlouvy (RRRR-MM-DD), bez zadání den splatnosti. '
      + 'Platební kalendář (`advance_calendar`) DUZP nemá.',
    ),
    total_amount: num('Splátka celkem včetně DPH. Když chybí, dopočte se jako `base_amount` + `vat_amount`.'),
    base_amount: num('Základ daně splátky.'),
    vat_amount: num('DPH ze splátky.'),
    note: str('Poznámka ke splátce.', { maxLength: 255 }),
  }, ['due_on']),
};

const toCents = (value) => Math.round(Number(value) * 100);
const fromCents = (cents) => cents / 100;

function isIsoDate(value) {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(value)) return false;
  const parsed = new Date(`${value}T00:00:00Z`);
  return !Number.isNaN(parsed.getTime()) && parsed.toISOString().slice(0, 10) === value;
}

function optionalAmount(value, row, key) {
  if (value === undefined || value === null || value === '') return null;
  const n = Number(value);
  if (!Number.isFinite(n)) throw new Error(`Splátka ${row}: \`${key}\` není číslo.`);
  return n;
}

/**
 * Zkontroluje a sjednotí řádky rozpisu. Server řádek bez data tiše zahodí a chybějící
 * částku uloží jako nulu, takže chyba v podkladu by se jinak projevila až rozdílem
 * v součtu, bez stopy, který řádek ho způsobil.
 */
export function normalizeSchedule(rows, invoiceType) {
  if (!Array.isArray(rows) || rows.length === 0) {
    throw new Error('Rozpis plateb musí mít aspoň jednu splátku.');
  }
  return rows.map((raw, index) => {
    const row = index + 1;
    const dueOn = String(raw?.due_on ?? '').trim();
    if (!isIsoDate(dueOn)) {
      throw new Error(`Splátka ${row}: datum splatnosti „${dueOn}" není platné datum ve tvaru RRRR-MM-DD.`);
    }
    const base = optionalAmount(raw.base_amount, row, 'base_amount');
    const vat = optionalAmount(raw.vat_amount, row, 'vat_amount');
    let total = optionalAmount(raw.total_amount, row, 'total_amount');
    if (total === null) {
      if (base === null || vat === null) {
        throw new Error(`Splátka ${row}: chybí \`total_amount\` (nebo \`base_amount\` i \`vat_amount\`, ze kterých se dopočte).`);
      }
      total = fromCents(toCents(base) + toCents(vat));
    } else if (base !== null && vat !== null && Math.abs(toCents(base) + toCents(vat) - toCents(total)) >= 100) {
      // Rozdíl pod korunu je zaokrouhlení dokladu, to server nechá v poslední splátce.
      throw new Error(
        `Splátka ${row}: základ ${base} + DPH ${vat} nedává celkem ${total}. Oprav částky splátky.`,
      );
    }
    if (toCents(total) === 0) throw new Error(`Splátka ${row}: částka splátky je nulová.`);

    const out = { due_on: dueOn, total_amount: fromCents(toCents(total)) };
    const taxDate = String(raw.tax_date ?? '').trim();
    if (taxDate !== '') {
      if (invoiceType === 'advance_calendar') {
        throw new Error(`Splátka ${row}: platební kalendář DUZP nemá, daň vzniká přijetím platby. Pole \`tax_date\` vynech.`);
      }
      if (!isIsoDate(taxDate)) {
        throw new Error(`Splátka ${row}: DUZP „${taxDate}" není platné datum ve tvaru RRRR-MM-DD.`);
      }
      out.tax_date = taxDate;
    }
    if (base !== null) out.base_amount = fromCents(toCents(base));
    if (vat !== null) out.vat_amount = fromCents(toCents(vat));
    const note = String(raw.note ?? '').trim();
    if (note !== '') out.note = note.slice(0, 255);
    return out;
  });
}

const scheduleCents = (rows, column = 'total_amount') => rows.reduce((sum, row) => sum + toCents(row[column] ?? 0), 0);

/** Typy, které jdou vystavit; `payment_calendar` zůstává jen u už vystavených dokladů. */
const CALENDAR_TYPES = ['rent_calendar', 'advance_calendar'];

/** Sloupce rozpisu, které musí sedět na doklad, zrcadlo serverového `payment_schedule_mismatch`. */
const SCHEDULE_CHECKS = [
  ['total_amount', 'total_with_vat', 'celková částka'],
  ['base_amount', 'total_without_vat', 'základ'],
  ['vat_amount', 'total_vat', 'DPH'],
];

/** Tělo `create_invoice` se zkontrolovaným rozpisem; bez rozpisu beze změny. */
export function withPaymentSchedule(a) {
  if (a.payment_schedule === undefined) return a;
  if (!CALENDAR_TYPES.includes(a.invoice_type)) {
    throw new Error('Rozpis plateb (`payment_schedule`) patří jen dokladu typu `rent_calendar` nebo `advance_calendar`.');
  }
  return { ...a, payment_schedule: normalizeSchedule(a.payment_schedule, a.invoice_type) };
}

/**
 * První sloupec rozpisu, který nesedí na doklad, nebo null. Částky dokladu, které server
 * nevrátil, se neporovnávají.
 */
function scheduleMismatch(rows, doc) {
  for (const [column, header, label] of SCHEDULE_CHECKS) {
    if (doc?.[header] === undefined) continue;
    const sum = scheduleCents(rows, column);
    const expected = toCents(doc[header]);
    if (sum !== expected) return { label, sum, expected };
  }
  return null;
}

/**
 * Po založení kalendáře porovná rozpis se součty, které spočítal server. Částky před
 * založením neznáme (DPH, zaokrouhlení i sleva se počítají na serveru), proto upozornění
 * až teď. Koncept vznikne tak jako tak, oprava je levná.
 */
export function checkCreatedSchedule(body, saved) {
  if (!Array.isArray(body.payment_schedule)) return saved;
  const mismatch = scheduleMismatch(body.payment_schedule, saved);
  if (mismatch === null) return saved;
  return {
    ...saved,
    payment_schedule_warning:
      `Součet rozpisu plateb, ${mismatch.label} (${money(fromCents(mismatch.sum), saved.currency)}), nesedí `
      + `na doklad (${money(fromCents(mismatch.expected), saved.currency)}). Kalendář takhle nepůjde vystavit; `
      + 'oprav rozpis přes `set_invoice_payment_schedule`, nebo položky dokladu.',
  };
}

// ────────────────────────────────────────────────────────────────────────────
// Pravidelná fakturace
//
// PUT /recurring/{id} je úplný zápis: chybějící pole hlavičky vynuluje nebo
// nahradí výchozí hodnotou a položky nahradí celé. Úprava proto vždy skládá
// celou šablonu ze současného stavu, stejně jako `save_project`.
// ────────────────────────────────────────────────────────────────────────────

const RECURRING_FIELDS = [
  'client_id', 'project_id', 'branding_profile_id', 'name', 'frequency', 'day_of_month',
  'end_of_month', 'anchor_date', 'end_date', 'invoice_type', 'currency_id', 'language',
  'payment_method', 'reverse_charge', 'prices_include_vat', 'discount_percent',
  'revenue_category_id', 'payment_due_days', 'payment_due_unit', 'tax_date_mode',
  'draft_open_mode', 'reminder_days_before', 'note_above_items', 'note_below_items',
  'increment_month_in_descriptions', 'auto_issue', 'auto_send_email', 'payment_variable_symbol',
];

/** Pole řádku šablony, která server při náhradě položek ukládá. */
const RECURRING_ITEM_FIELDS = [
  'price_list_item_id', 'catalog_policy', 'description_source', 'catalog_price_source',
  'catalog_source_currency_code', 'catalog_source_unit_price', 'catalog_exchange_rate',
  'catalog_exchange_rate_date', 'description', 'quantity', 'duration_minutes', 'unit',
  'unit_price_without_vat', 'vat_rate_id', 'vat_classification_code', 'order_index',
  'oss_applicable', 'oss_consumer_country', 'oss_rate_type', 'oss_supply_type',
];

const recurringLine = (item) => Object.fromEntries(
  RECURRING_ITEM_FIELDS.filter((key) => item[key] !== undefined).map((key) => [key, item[key]]),
);

const RECURRING_ITEMS = {
  type: 'array',
  description:
    'Položky každé vygenerované faktury. Při úpravě se stávající položky NAHRAZUJÍ tímto '
    + 'seznamem celé; pošli všechny, ne jen změněné.',
  minItems: 1,
  items: schema({
    description: str('Text položky. Zástupné symboly období doplní aplikace při generování.'),
    quantity: num('Množství. Nesmí být nula.'),
    unit: str('Měrná jednotka, výchozí „ks".'),
    unit_price_without_vat: num('Jednotková cena (bez DPH, u šablony s `prices_include_vat` s DPH).'),
    vat_rate_id: int('ID sazby DPH, viz `list_vat_rates`. Sazbu nehádej.'),
  }, ['description', 'quantity', 'unit_price_without_vat', 'vat_rate_id']),
};

const RECURRING_INPUT = {
  client_id: int('ID odběratele (`search_clients`).'),
  name: str('Název šablony.', { maxLength: 190 }),
  frequency: str('Periodicita.', { enum: ['monthly', 'quarterly', 'semi_annually', 'annually'] }),
  anchor_date: date('Datum první faktury; od něj se počítá rozvrh.'),
  end_date: date('Poslední den platnosti šablony; bez zadání neomezeně.'),
  day_of_month: int('Den v měsíci, kdy se vystavuje (1–28).', { minimum: 1, maximum: 28 }),
  end_of_month: bool('Vystavovat poslední den měsíce (nekombinuje se s `day_of_month`).'),
  currency_id: int('ID měny, viz `list_currencies`.'),
  invoice_type: str('Typ generovaného dokladu.', { enum: ['invoice', 'proforma'] }),
  project_id: int('Zakázka; musí patřit odběrateli. 0 zakázku ze šablony odebere.', { minimum: 0 }),
  language: str('Jazyk dokladů.', { enum: ['cs', 'en'] }),
  payment_method: str('Způsob úhrady.', { enum: ['bank_transfer', 'card', 'cash', 'other'] }),
  payment_due_days: int('Splatnost ve dnech (výchozí 14).', { minimum: 0, maximum: 365 }),
  payment_due_unit: str('Jednotka splatnosti.', { enum: ['days', 'month'] }),
  tax_date_mode: str(
    'DUZP: `same_as_issue` = den vystavení, `previous_month_last_day` = poslední den předchozího měsíce.',
    { enum: ['same_as_issue', 'previous_month_last_day'] },
  ),
  draft_open_mode: str(
    'Kdy vznikne koncept: `at_issue` = v den vystavení, `period_start` = na začátku období '
    + '(jen měsíční periodicita s automatickým vystavením).',
    { enum: ['at_issue', 'period_start'] },
  ),
  reminder_days_before: int('Kolik dní předem připomenout blížící se fakturu (0 = vůbec).', { minimum: 0, maximum: 14 }),
  prices_include_vat: bool('Ceny položek jsou včetně DPH.'),
  reverse_charge: bool('Přenesená daňová povinnost.'),
  discount_percent: num('Sleva z celé faktury v procentech (0–100).', { minimum: 0, maximum: 100 }),
  revenue_category_id: int('Pevná kategorie tržby.'),
  note_above_items: str('Poznámka nad položkami.'),
  note_below_items: str('Poznámka pod položkami.'),
  payment_variable_symbol: str('Pevný variabilní symbol pro platbu (jen číslice, max 10).'),
  increment_month_in_descriptions: bool('Posouvat měsíc v textech položek podle období.'),
  auto_issue: bool(
    'Faktury se budou VYSTAVOVAT automaticky, bez kontroly konceptu. Zapínej jen na výslovný pokyn.',
  ),
  auto_send_email: bool(
    'Vystavené faktury se budou automaticky POSÍLAT e-mailem odběrateli (vyžaduje `auto_issue`). '
    + 'Zapínej jen na výslovný pokyn.',
  ),
  items: RECURRING_ITEMS,
};

function recurringLabel(tpl, fallbackId) {
  const parts = [
    tpl?.client_company_name,
    tpl?.frequency,
    tpl?.next_run_date ? `příště ${tpl.next_run_date}` : null,
    tpl?.total_with_vat !== undefined ? money(tpl.total_with_vat, tpl?.currency) : null,
  ].filter(Boolean);
  return `„${tpl?.name ?? `#${tpl?.id ?? fallbackId}`}"${parts.length ? ` (${parts.join(', ')})` : ''}`;
}

const recurringInput = (a) => {
  const body = changed(a, RECURRING_FIELDS);
  if (body.project_id === 0) body.project_id = null;
  if (a.items !== undefined) body.items = a.items.map((item, index) => ({ unit: 'ks', ...item, order_index: index }));
  return body;
};

// ────────────────────────────────────────────────────────────────────────────

export const INVOICE_TOOLS = [
  // ── Smazání, storno, dobropis ────────────────────────────────────────────
  {
    name: 'delete_invoice_draft',
    api: [
      ['GET', '/api/invoices/{id}'],
      ['DELETE', '/api/invoices/{id}'],
    ],
    title: 'Smazat koncept faktury',
    description:
      'Nevratně smaže KONCEPT vydaného dokladu i s položkami a výkazem práce. Vystavený '
      + 'doklad nástroj odmítne: ten se ruší stornem nebo dobropisem (`cancel_invoice`).\n\n'
      + 'Bez `confirm: true` nic nesmaže a jen vypíše doklad (číslo, odběratel, částka) k potvrzení.',
    inputSchema: schema({ id: id('ID konceptu.'), confirm: CONFIRM }, ['id']),
    write: true,
    destructive: true,
    run: async (c, a, tool) => {
      const invoice = await getInvoice(c, a.id, tool);
      if (invoice?.status !== 'draft') {
        throw new Error(
          `${invoiceLabel(invoice, a.id)} není koncept, proto ho nástroj nesmaže. `
          + 'Vystavený doklad se ruší stornem nebo dobropisem (`cancel_invoice`).',
        );
      }
      requireConfirm(a, 'Smazat se má koncept', invoiceLabel(invoice, a.id));
      return { deleted: invoiceSummary(invoice), result: await c.del(`/invoices/${seg(a.id)}`, tool) };
    },
  },
  {
    name: 'cancel_invoice',
    api: [
      ['GET', '/api/invoices/{id}'],
      ['POST', '/api/invoices/{id}/cancel'],
    ],
    title: 'Stornovat fakturu nebo založit dobropis',
    description:
      'Zruší VYSTAVENÝ doklad. NEVRATNÝ KROK S DAŇOVÝM DOPADEM: jen na výslovný pokyn uživatele.\n\n'
      + '`mode: "internal"` = interní storno chybně vystaveného dokladu, který odběratel '
      + 'nepřevzal. Doklad dostane stav stornovaný a vypadne z evidence DPH i tržeb, zaúčtovaný '
      + 'zápis se stornuje. V uzavřeném nebo uzamčeném období (podané DPH) ho aplikace odmítne.\n'
      + '`mode: "credit_note"` = opravný daňový doklad (dobropis) k dokladu, který odběratel už má. '
      + 'Vznikne KONCEPT dobropisu se zápornými položkami a dnešním datem; ten je potřeba '
      + 'zkontrolovat a vystavit (`issue_invoice`). Dobropis sám jde stornovat jen interně.\n\n'
      + 'Bez `confirm: true` nic neprovede a jen vypíše doklad (číslo, odběratel, částka).',
    inputSchema: schema({
      id: id('ID vystaveného dokladu.'),
      mode: str('Způsob zrušení.', { enum: ['internal', 'credit_note'] }),
      reason: str('Důvod; propíše se do poznámky stornovacího dokladu nebo dobropisu.', { maxLength: 500 }),
      confirm: CONFIRM,
    }, ['id', 'mode']),
    write: true,
    destructive: true,
    run: async (c, a, tool) => {
      const invoice = await getInvoice(c, a.id, tool);
      if (!ISSUED.includes(invoice?.status)) {
        throw new Error(
          `${invoiceLabel(invoice, a.id)}: zrušit jde jen vystavený, odeslaný nebo zaplacený doklad. `
          + 'Koncept se maže (`delete_invoice_draft`).',
        );
      }
      if (invoice.invoice_type === 'cancellation') throw new Error('Stornovací doklad nelze stornovat.');
      if (invoice.invoice_type === 'credit_note' && a.mode !== 'internal') {
        throw new Error('Dobropis lze stornovat pouze interně (`mode: "internal"`).');
      }
      requireConfirm(
        a,
        a.mode === 'internal' ? 'Interně stornovat se má' : 'K dokladu se má založit dobropis',
        invoiceLabel(invoice, a.id),
      );
      const body = { mode: a.mode };
      if (a.reason !== undefined) body.reason = a.reason;
      return { invoice: invoiceSummary(invoice), result: await c.post(`/invoices/${seg(a.id)}/cancel`, body, tool) };
    },
  },
  {
    name: 'uncancel_invoice',
    api: false,
    apiReason: NOT_IN_CLIENT_CATALOG,
    title: 'Zrušit interní storno',
    description:
      'Vrátí omylem interně stornovanou fakturu nebo zálohu do stavu před stornem. Doklad se '
      + 'vrací do evidence DPH svého původního období a podle nastavení firmy se znovu zaúčtuje. '
      + 'Nejde, když byl doklad zrušen vystaveným dobropisem, ani v uzavřeném nebo uzamčeném období.\n\n'
      + 'Daňový dopad: jen na výslovný pokyn. Bez `confirm: true` nic neprovede a jen vypíše doklad.',
    inputSchema: schema({ id: id('ID stornované faktury nebo zálohy.'), confirm: CONFIRM }, ['id']),
    write: true,
    destructive: true,
    run: async (c, a, tool) => {
      const invoice = await getInvoice(c, a.id, tool);
      if (invoice?.status !== 'cancelled' || !['invoice', 'proforma'].includes(invoice?.invoice_type)) {
        throw new Error(`${invoiceLabel(invoice, a.id)}: zrušit storno lze jen u stornované faktury nebo zálohy.`);
      }
      requireConfirm(a, 'Zrušit storno a obnovit se má', invoiceLabel(invoice, a.id));
      return { invoice: invoiceSummary(invoice), result: await c.post(`/invoices/${seg(a.id)}/uncancel`, {}, tool) };
    },
  },

  // ── Kopie a záloha ───────────────────────────────────────────────────────
  {
    name: 'clone_invoice',
    api: [
      ['POST', '/api/invoices/{id}/clone'],
    ],
    title: 'Kopie dokladu jako nový koncept',
    description:
      'Založí nový KONCEPT jako kopii existujícího dokladu (odběratel, zakázka, položky). Nic '
      + 'nevystavuje; koncept jde upravit a vystavit (`issue_invoice`). Datum vystavení je dnes, '
      + 'pokud nezadáš `issue_date`. Daňový doklad k přijaté platbě kopírovat nejde. Vrací `draft_id`.',
    inputSchema: schema({
      id: id('ID dokladu, který se má zkopírovat.'),
      issue_date: date('Datum vystavení kopie (RRRR-MM-DD).'),
      increment_month_in_descriptions: bool('Posunout měsíc v textech položek o jeden dopředu.'),
    }, ['id']),
    write: true,
    run: (c, a, tool) => c.post(
      `/invoices/${seg(a.id)}/clone`,
      changed(a, ['issue_date', 'increment_month_in_descriptions']),
      tool,
    ),
  },
  {
    name: 'create_final_invoice_from_proforma',
    api: false,
    apiReason: NOT_IN_CLIENT_CATALOG,
    title: 'Finální faktura ze zálohy',
    description:
      'Ze zaplacené (i částečně) zálohové faktury založí KONCEPT finálního daňového dokladu: '
      + 'zkopíruje položky a odečte přijatou zálohu. Koncept zkontroluj a vystav přes `issue_invoice`.\n\n'
      + '`final_total` doplní rozdílový řádek, když záloha pokrývala jen část ceny zakázky.',
    inputSchema: schema({
      id: id('ID zálohové faktury (proforma).'),
      tax_date: date('DUZP finálního dokladu; výchozí dnes.'),
      due_date: date('Splatnost finálního dokladu.'),
      advance_paid_amount: num('Odečítaná záloha, liší-li se od přijatých plateb.', { minimum: 0 }),
      final_total: num('Celková cena zakázky, je-li vyšší než záloha.', { exclusiveMinimum: 0 }),
    }, ['id']),
    write: true,
    run: (c, a, tool) => c.post(
      `/invoices/${seg(a.id)}/issue-final`,
      changed(a, ['tax_date', 'due_date', 'advance_paid_amount', 'final_total']),
      tool,
    ),
  },
  {
    name: 'list_invoice_advance_drawdown_candidates',
    api: false,
    apiReason: NOT_IN_CLIENT_CATALOG,
    title: 'Zálohy k postupnému čerpání',
    description:
      'Ke KONCEPTU faktury vrátí zálohové faktury téhož odběratele a měny, které mají vystavený '
      + 'daňový doklad k platbě, s dosud nevyčerpanou částkou (`available`, s DPH). Čerpá se přes '
      + '`apply_invoice_advance_drawdown`.',
    inputSchema: schema({ id: id('ID konceptu faktury.') }, ['id']),
    write: false,
    run: (c, a, tool) => c.get(`/invoices/${seg(a.id)}/advance-drawdown`, null, tool),
  },
  {
    name: 'apply_invoice_advance_drawdown',
    api: false,
    apiReason: NOT_IN_CLIENT_CATALOG,
    title: 'Odečíst část zálohy na faktuře',
    description:
      'Postupné čerpání zálohy (smlouva s milníky): na KONCEPT běžné faktury s vlastními položkami '
      + 'doplní záporné odpočtové řádky § 37a ZDPH na `amount` (s DPH) z dané zálohy a naváže fakturu '
      + 'na zálohu. Záloha musí mít vystavený daňový doklad k platbě. Opakované volání odpočet '
      + 'nahradí. Na rozdíl od `create_final_invoice_from_proforma` nekopíruje položky zálohy a jednu '
      + 'zálohu může čerpat víc faktur, dokud se nevyčerpá.',
    inputSchema: schema({
      id: id('ID konceptu faktury.'),
      proforma_id: id('ID zálohové faktury.'),
      amount: num('Čerpaná částka zálohy s DPH v měně dokladu.', { exclusiveMinimum: 0 }),
    }, ['id', 'proforma_id', 'amount']),
    write: true,
    run: (c, a, tool) => c.post(
      `/invoices/${seg(a.id)}/advance-drawdown`,
      { proforma_id: a.proforma_id, amount: a.amount },
      tool,
    ),
  },
  {
    name: 'remove_invoice_advance_drawdown',
    api: false,
    apiReason: NOT_IN_CLIENT_CATALOG,
    title: 'Odebrat čerpání zálohy z faktury',
    description: 'Z KONCEPTU faktury odebere odpočtové řádky zálohy i vazbu na zálohovou fakturu.',
    inputSchema: schema({ id: id('ID konceptu faktury.') }, ['id']),
    write: true,
    run: (c, a, tool) => c.del(`/invoices/${seg(a.id)}/advance-drawdown`, tool),
  },
  {
    name: 'list_invoice_advance_candidates',
    api: false,
    apiReason: NOT_IN_CLIENT_CATALOG,
    title: 'Zálohy k propojení s fakturou',
    description:
      'K faktuře vrátí nespárované zálohové faktury téhož odběratele (nejdřív stejná měna '
      + 'a nejbližší částka), se kterými ji jde propojit přes `link_invoice_advance`.',
    inputSchema: schema({ id: id('ID faktury.') }, ['id']),
    write: false,
    run: (c, a, tool) => c.get(`/invoices/${seg(a.id)}/advance-candidates`, null, tool),
  },
  {
    name: 'list_proforma_final_candidates',
    api: false,
    apiReason: NOT_IN_CLIENT_CATALOG,
    title: 'Faktury k propojení se zálohou',
    description:
      'K zálohové faktuře vrátí nepropojené faktury téhož odběratele. Propojení pak udělá '
      + '`link_invoice_advance` s `id` faktury a `advance_id` zálohy.',
    inputSchema: schema({ id: id('ID zálohové faktury.') }, ['id']),
    write: false,
    run: (c, a, tool) => c.get(`/invoices/${seg(a.id)}/final-candidates`, null, tool),
  },
  {
    name: 'link_invoice_advance',
    api: false,
    apiReason: NOT_IN_CLIENT_CATALOG,
    title: 'Propojit fakturu se zálohou',
    description:
      'Ručně propojí fakturu se zálohovou fakturou téhož odběratele a měny. Nemá-li faktura '
      + 'zadanou odečtenou zálohu, doplní se ve výši přijatých plateb zálohy a sníží se částka '
      + 'k úhradě. Stav úhrady se nemění. Zálohu s vystavenými daňovými doklady k platbě takhle '
      + 'propojit nejde, finál se pak zakládá přes `create_final_invoice_from_proforma`.',
    inputSchema: schema({
      id: id('ID faktury (daňového dokladu).'),
      advance_id: id('ID zálohové faktury.'),
    }, ['id', 'advance_id']),
    write: true,
    run: (c, a, tool) => c.post(`/invoices/${seg(a.id)}/link-advance`, { advance_id: a.advance_id }, tool),
  },
  {
    name: 'unlink_invoice_advance',
    api: false,
    apiReason: NOT_IN_CLIENT_CATALOG,
    title: 'Zrušit propojení faktury se zálohou',
    description:
      'Zruší propojení faktury se zálohovou fakturou. Odečtená záloha (`advance_paid_amount`) '
      + 'na faktuře zůstane, případně ji uprav. Nejde u daňového dokladu k platbě ani u finálu '
      + 's odpočty záloh podle § 37a ZDPH.',
    inputSchema: schema({ id: id('ID faktury.') }, ['id']),
    write: true,
    run: (c, a, tool) => c.del(`/invoices/${seg(a.id)}/link-advance`, tool),
  },

  // ── Penále ───────────────────────────────────────────────────────────────
  {
    name: 'preview_invoice_penalty',
    api: false,
    apiReason: NOT_IN_CLIENT_CATALOG,
    title: 'Výpočet úroku z prodlení',
    description:
      'Spočítá zákonný úrok z prodlení (NV č. 351/2013 Sb.) k vystavené faktuře po splatnosti: '
      + 'jistinu, dny, sazby a částku. Nic nezakládá. Dny pokryté dřívější penalizací se nepočítají.',
    inputSchema: schema({
      id: id('ID faktury po splatnosti.'),
      as_of: date('Ke kterému dni počítat; výchozí dnes, u zaplacené faktury den doplacení.'),
      principal: num('Jiná jistina než zbývající dlužná částka.', { exclusiveMinimum: 0 }),
    }, ['id']),
    write: false,
    run: (c, a, tool) => c.get(`/invoices/${seg(a.id)}/penalty/preview`, changed(a, ['as_of', 'principal']), tool),
  },
  {
    name: 'create_invoice_penalty',
    api: false,
    apiReason: NOT_IN_CLIENT_CATALOG,
    title: 'Založit penalizační fakturu',
    description:
      'Založí KONCEPT penalizační faktury na úrok z prodlení (mimo předmět DPH) k vystavené '
      + 'faktuře po splatnosti. Nejdřív ukaž výpočet z `preview_invoice_penalty`. Koncept se '
      + 'vystavuje (`issue_invoice`) a posílá zvlášť.',
    inputSchema: schema({
      id: id('ID faktury po splatnosti.'),
      as_of: date('Ke kterému dni počítat; výchozí dnes.'),
      principal: num('Jiná jistina než zbývající dlužná částka.', { exclusiveMinimum: 0 }),
    }, ['id']),
    write: true,
    run: (c, a, tool) => c.post(`/invoices/${seg(a.id)}/penalty`, changed(a, ['as_of', 'principal']), tool),
  },

  // ── Úhrady ───────────────────────────────────────────────────────────────
  {
    name: 'add_invoice_payment',
    api: [
      ['POST', '/api/invoices/{id}/payments'],
    ],
    title: 'Zaevidovat úhradu (i částečnou)',
    description:
      'Zaeviduje úhradu dokladu v jeho měně, i částečnou. Když platby pokryjí celou částku, '
      + 'doklad se označí jako zaplacený. Platby z bankovního výpisu se párují v bance; sem '
      + 'patří hotovost, zápočet nebo ručně dohledaná platba. Pro jednorázové „zaplaceno celé" '
      + 'stačí `mark_invoice_paid`.\n\n'
      + 'U zálohové faktury může podle nastavení firmy vzniknout koncept daňového dokladu k platbě. '
      + '`send_payment_thanks` pošle odběrateli děkovný e-mail (jen po doplacení), jen na výslovný pokyn.',
    inputSchema: schema({
      id: id('ID faktury.'),
      amount: num('Částka v měně dokladu.'),
      paid_on: date('Datum úhrady; výchozí dnes.'),
      variable_symbol: str('Variabilní symbol platby.'),
      bank_reference: str('Reference platby.'),
      note: str('Poznámka.'),
      send_payment_thanks: bool('Po doplacení poslat odběrateli děkovný e-mail.'),
    }, ['id', 'amount']),
    write: true,
    run: (c, a, tool) => c.post(
      `/invoices/${seg(a.id)}/payments`,
      changed(a, ['amount', 'paid_on', 'variable_symbol', 'bank_reference', 'note', 'send_payment_thanks']),
      tool,
    ),
  },
  {
    name: 'delete_invoice_payment',
    api: [
      ['GET', '/api/invoices/{id}'],
      ['DELETE', '/api/invoices/{id}/payments/{item_id}'],
      ['GET', '/api/invoices/{id}/payments'],
    ],
    title: 'Smazat úhradu',
    description:
      'Nevratně smaže evidovanou úhradu dokladu (ID z `list_invoice_payments`). Když pak doklad '
      + 'přestane být pokrytý, vrátí se ze stavu zaplaceno. Platba spárovaná z banky se ruší '
      + 'zrušením spárování ve výpisu, platba s vystaveným daňovým dokladem až po jeho stornu.\n\n'
      + 'Bez `confirm: true` nic nesmaže a jen vypíše platbu a doklad.',
    inputSchema: schema({
      id: id('ID faktury.'),
      payment_id: id('ID platby.'),
      confirm: CONFIRM,
    }, ['id', 'payment_id']),
    write: true,
    destructive: true,
    run: async (c, a, tool) => {
      const invoice = await getInvoice(c, a.id, tool);
      const listing = await c.get(`/invoices/${seg(a.id)}/payments`, null, tool);
      const payment = (listing?.payments ?? []).find((p) => Number(p.id) === Number(a.payment_id));
      if (!payment) throw new Error(`Platba #${a.payment_id} u dokladu #${a.id} není.`);
      requireConfirm(
        a,
        'Smazat se má úhrada',
        `${money(payment.amount, invoice?.currency)} ze dne ${payment.paid_on} (zdroj ${payment.source ?? '?'}) `
        + `k dokladu: ${invoiceLabel(invoice, a.id)}`,
      );
      return {
        deleted: payment,
        result: await c.del(`/invoices/${seg(a.id)}/payments/${seg(a.payment_id)}`, tool),
      };
    },
  },
  {
    name: 'create_payment_tax_document',
    api: false,
    apiReason: NOT_IN_CLIENT_CATALOG,
    title: 'Daňový doklad k přijaté platbě',
    description:
      'K přijaté platbě zálohové faktury založí KONCEPT daňového dokladu k přijaté úplatě '
      + '(§ 28 ZDPH, DUZP = den platby). Opakované volání vrátí už existující doklad. '
      + 'Daňový dopad nastane až vystavením (`issue_invoice`).',
    inputSchema: schema({
      id: id('ID zálohové faktury.'),
      payment_id: id('ID platby z `list_invoice_payments`.'),
    }, ['id', 'payment_id']),
    write: true,
    run: (c, a, tool) => c.post(`/invoices/${seg(a.id)}/payments/${seg(a.payment_id)}/tax-document`, {}, tool),
  },
  {
    name: 'unmark_invoice_paid',
    api: [
      ['GET', '/api/invoices/{id}'],
      ['POST', '/api/invoices/{id}/unmark-paid'],
    ],
    title: 'Zrušit úhradu dokladu',
    description:
      'Vrátí zaplacený doklad do stavu vystaveno nebo odesláno a SMAŽE všechny jeho evidované '
      + 'úhrady. Jen pro administrátora firmy. Nejde, je-li doklad spárovaný s bankovním pohybem '
      + '(to se ruší ve výpisu) nebo k platbě existuje daňový doklad.\n\n'
      + 'Bez `confirm: true` nic nezmění a jen vypíše doklad.',
    inputSchema: schema({ id: id('ID zaplaceného dokladu.'), confirm: CONFIRM }, ['id']),
    write: true,
    destructive: true,
    run: async (c, a, tool) => {
      const invoice = await getInvoice(c, a.id, tool);
      if (invoice?.status !== 'paid') {
        throw new Error(`${invoiceLabel(invoice, a.id)}: vrátit zpět lze jen zaplacený doklad.`);
      }
      requireConfirm(a, 'Úhrady se mají smazat u dokladu', invoiceLabel(invoice, a.id));
      return { invoice: invoiceSummary(invoice), result: await c.post(`/invoices/${seg(a.id)}/unmark-paid`, {}, tool) };
    },
  },

  // ── Platební kalendář ────────────────────────────────────────────────────
  {
    name: 'set_invoice_payment_schedule',
    api: false,
    apiReason: NOT_IN_CLIENT_CATALOG,
    title: 'Rozpis plateb kalendáře',
    description:
      'Nastaví rozpis plateb KONCEPTU kalendáře: `rent_calendar` je splátkový kalendář k nájmu '
      + '(§ 31 ZDPH, každá splátka má vlastní DUZP), `advance_calendar` platební kalendář záloh '
      + '(§ 31a ZDPH, bez DUZP); typ konceptu změní `update_invoice`. Rozpis se nahrazuje CELÝ: '
      + 'pošli všechny splátky. Hlavička a položky dokladu zůstanou beze změny.\n\n'
      + 'Řádek: `due_on` (datum splatnosti), `base_amount` a `vat_amount` (základ a DPH splátky), '
      + '`total_amount` (splátka s DPH; když chybí, dopočte se), u nájmu `tax_date` (DUZP, bez zadání '
      + 'den splatnosti) a `note`. Částky jsou v měně dokladu. Součty základu, DPH i celkové částky '
      + 'musí přesně sedět na doklad, jinak nástroj nic neuloží a vrátí rozdíl; kalendář s nesouhlasným '
      + 'součtem by nešel vystavit.\n\n'
      + 'Rozpis můžeš sestavit i z podkladu od uživatele (smlouva, tabulka splátek, PDF): přečti ho, '
      + 'splátky převeď na řádky a před uložením je uživateli ukaž ke kontrole.',
    inputSchema: schema({
      invoice_id: id('ID konceptu splátkového nebo platebního kalendáře.'),
      payment_schedule: PAYMENT_SCHEDULE_INPUT,
    }, ['invoice_id', 'payment_schedule']),
    write: true,
    run: async (c, a, tool) => {
      const invoiceId = Number(a.invoice_id);
      const invoice = await loadDraftInvoice(c, invoiceId, tool);
      if (!CALENDAR_TYPES.includes(invoice.invoice_type)) {
        throw new Error(
          `${invoiceLabel(invoice, invoiceId)}: rozpis plateb patří jen splátkovému nebo platebnímu kalendáři. `
          + 'Typ konceptu změní `update_invoice` s `invoice_type: "rent_calendar"` nebo `"advance_calendar"` '
          + '(jen na výslovný pokyn).',
        );
      }
      const rows = normalizeSchedule(a.payment_schedule, invoice.invoice_type);
      const mismatch = scheduleMismatch(rows, invoice);
      if (mismatch !== null) {
        throw new Error(
          `NEULOŽENO: součet rozpisu plateb, ${mismatch.label} ${money(fromCents(mismatch.sum), invoice.currency)}, `
          + `nesedí na doklad ${money(fromCents(mismatch.expected), invoice.currency)} `
          + `(rozdíl ${money(fromCents(mismatch.sum - mismatch.expected), invoice.currency)}). Oprav splátky, `
          + 'nebo nejdřív položky dokladu, a zavolej nástroj znovu.',
        );
      }

      // Položky jdou zpět všechny i se skrytými poli: PUT je nahrazuje celé.
      const lines = invoiceLines(invoice).map((item) => lineForPut(item));
      const saved = await c.put(
        `/invoices/${seg(invoiceId)}`,
        { ...draftPayload(invoice, {}, lines), payment_schedule: rows },
        tool,
      );
      return draftResult(invoiceId, {
        payment_schedule: rows,
        previous_payment_schedule: invoice.payment_schedule ?? [],
      }, saved);
    },
  },

  // ── Export a odeslání ────────────────────────────────────────────────────
  {
    name: 'get_invoice_isdoc',
    api: [
      ['GET', '/api/invoices/{id}/isdoc'],
    ],
    title: 'ISDOC vystavené faktury',
    description:
      'Vrátí ISDOC XML vystaveného dokladu jako text (strojově čitelný formát pro účetní '
      + 'software odběratele). Koncept ani stornovaný doklad exportovat nejde. PDF nevrací.',
    inputSchema: schema({ id: id('ID vystaveného dokladu.') }, ['id']),
    write: false,
    run: async (c, a, tool) => {
      const response = await c.get(`/invoices/${seg(a.id)}/isdoc`, null, tool);
      if (typeof response?.raw !== 'string') return response;
      return { invoice_id: a.id, format: 'ISDOC', xml: response.raw };
    },
  },
  {
    name: 'get_invoice_recipients',
    api: [
      ['GET', '/api/invoices/{id}/recipients'],
    ],
    title: 'Příjemci dokladu',
    description:
      'Komu by aplikace doklad poslala: adresy To/Cc/Bcc z kontaktů odběratele a zakázky '
      + 's původem každé adresy. Ukaž je uživateli před `send_invoice` nebo upomínkou.',
    inputSchema: schema({
      id: id('ID dokladu.'),
      type: str('Účel zprávy; výchozí `documents`.', { enum: ['documents', 'reminders', 'approvals'] }),
    }, ['id']),
    write: false,
    run: (c, a, tool) => c.get(`/invoices/${seg(a.id)}/recipients`, changed(a, ['type']), tool),
  },
  {
    name: 'send_invoice_reminders_bulk',
    api: false,
    apiReason: NOT_IN_CLIENT_CATALOG,
    title: 'Hromadně poslat upomínky',
    description:
      'Pošle upomínky k více fakturám po splatnosti naráz (nejvýš 50). Každá upomínka je e-mail '
      + 'odběrateli, který nejde vzít zpět: jen na výslovný pokyn. Faktury, které podmínky '
      + 'nesplní (zaplacená, není po splatnosti, chybí e-mail), vrátí server v `errors`.\n\n'
      + 'Bez `confirm: true` nic neodešle a jen vypíše dotčené doklady.',
    inputSchema: schema({
      invoice_ids: {
        type: 'array',
        description: 'ID faktur, nejvýš 50.',
        items: { type: 'integer', minimum: 1 },
        minItems: 1,
        maxItems: 50,
        uniqueItems: true,
      },
      confirm: CONFIRM,
    }, ['invoice_ids']),
    write: true,
    destructive: true,
    run: async (c, a, tool) => {
      const ids = [...new Set((a.invoice_ids ?? []).map(Number))];
      if (ids.length === 0 || ids.length > 50) throw new Error('Zadejte 1 až 50 ID faktur.');
      if (a.confirm !== true) {
        const invoices = [];
        for (const invoiceId of ids) invoices.push(await getInvoice(c, invoiceId, tool));
        requireConfirm(
          a,
          `Upomínka se má poslat k ${ids.length} dokladům`,
          invoices.map((inv, i) => invoiceLabel(inv, ids[i])).join('; '),
        );
      }
      return c.post('/invoices/bulk-reminder', { invoice_ids: ids }, tool);
    },
  },

  // ── Pravidelná fakturace ─────────────────────────────────────────────────
  {
    name: 'create_recurring_invoice',
    api: [
      ['POST', '/api/recurring'],
    ],
    title: 'Založit pravidelnou fakturaci',
    description:
      'Založí šablonu pravidelné fakturace: z ní aplikace podle rozvrhu generuje faktury. '
      + 'Bez `auto_issue` vznikají koncepty ke kontrole; `auto_issue` a `auto_send_email` '
      + 'zapínej jen na výslovný pokyn, protože pak se faktury vystavují a posílají samy.\n\n'
      + '`client_id` najdeš přes `search_clients`, `currency_id` přes `list_currencies`, '
      + '`vat_rate_id` přes `list_vat_rates`.',
    inputSchema: schema(RECURRING_INPUT, ['client_id', 'name', 'frequency', 'anchor_date', 'currency_id', 'items']),
    write: true,
    run: (c, a, tool) => c.post('/recurring', recurringInput(a), tool),
  },
  {
    name: 'update_recurring_invoice',
    api: [
      ['GET', '/api/recurring/{id}'],
      ['PUT', '/api/recurring/{id}'],
    ],
    title: 'Upravit pravidelnou fakturaci',
    description:
      'Změní šablonu pravidelné fakturace. Zadaná pole se změní, ostatní zůstanou; nástroj '
      + 'si šablonu načte a pošle ji celou. `items` NAHRADÍ všechny položky šablony; bez nich '
      + 'zůstanou stávající položky beze změny. Už vystavené faktury se nemění. Stav (pozastavení) '
      + 'mění `pause_recurring_invoice` a `resume_recurring_invoice`, termín příští faktury '
      + '`reschedule_recurring_invoice`.',
    inputSchema: schema({ id: id('ID šablony.'), ...RECURRING_INPUT }, ['id']),
    write: true,
    run: async (c, a, tool) => {
      const current = await c.get(`/recurring/${seg(a.id)}`, null, tool);
      const changes = recurringInput(a);
      const body = merged(current, changes, RECURRING_FIELDS);
      // Den v měsíci a „poslední den měsíce" se vylučují; platí to, co uživatel zadal.
      if (changes.end_of_month === true) body.day_of_month = null;
      if (changes.day_of_month !== undefined) body.end_of_month = false;
      body.items = changes.items ?? (current?.items ?? []).map(recurringLine);
      return c.put(`/recurring/${seg(a.id)}`, body, tool);
    },
  },
  {
    name: 'delete_recurring_invoice',
    api: [
      ['GET', '/api/recurring/{id}'],
      ['DELETE', '/api/recurring/{id}'],
    ],
    title: 'Smazat pravidelnou fakturaci',
    description:
      'Nevratně smaže šablonu pravidelné fakturace; další faktury z ní už nevzniknou. Už '
      + 'vygenerované faktury zůstanou. Pro dočasné zastavení použij `pause_recurring_invoice`.\n\n'
      + 'Bez `confirm: true` nic nesmaže a jen vypíše šablonu.',
    inputSchema: schema({ id: id('ID šablony.'), confirm: CONFIRM }, ['id']),
    write: true,
    destructive: true,
    run: async (c, a, tool) => {
      const path = `/recurring/${seg(a.id)}`;
      const template = await confirmed(c, a, tool, {
        path,
        action: 'Smazat se má pravidelná fakturace',
        label: (row) => recurringLabel(row, a.id),
      });
      return { deleted: { id: template?.id, name: template?.name }, result: await c.del(path, tool) };
    },
  },
  {
    name: 'pause_recurring_invoice',
    api: [
      ['POST', '/api/recurring/{id}/pause'],
    ],
    title: 'Pozastavit pravidelnou fakturaci',
    description: 'Pozastaví šablonu; dokud se neobnoví, žádné faktury z ní nevzniknou.',
    inputSchema: schema({ id: id('ID šablony.') }, ['id']),
    write: true,
    run: (c, a, tool) => c.post(`/recurring/${seg(a.id)}/pause`, {}, tool),
  },
  {
    name: 'resume_recurring_invoice',
    api: [
      ['POST', '/api/recurring/{id}/resume'],
    ],
    title: 'Obnovit pravidelnou fakturaci',
    description:
      'Obnoví pozastavenou šablonu. Příští faktura vznikne v den `next_run_date` ze šablony; '
      + 'je-li to po konci platnosti, aplikace obnovení odmítne.',
    inputSchema: schema({ id: id('ID šablony.') }, ['id']),
    write: true,
    run: (c, a, tool) => c.post(`/recurring/${seg(a.id)}/resume`, {}, tool),
  },
  {
    name: 'reschedule_recurring_invoice',
    api: [
      ['GET', '/api/recurring/{id}'],
      ['POST', '/api/recurring/{id}/reschedule'],
    ],
    title: 'Přeplánovat příští fakturu',
    description:
      'Posune datum příští faktury ze šablony (`next_run_date`). Datum musí být od dneška '
      + 'a v rozsahu platnosti šablony; rozvrh se pak počítá od něj.',
    inputSchema: schema({
      id: id('ID šablony.'),
      next_run_date: date('Nové datum příští faktury (RRRR-MM-DD).'),
    }, ['id', 'next_run_date']),
    write: true,
    run: async (c, a, tool) => {
      const current = await c.get(`/recurring/${seg(a.id)}`, null, tool);
      return c.post(`/recurring/${seg(a.id)}/reschedule`, {
        next_run_date: a.next_run_date,
        expected_next_run_date: current?.next_run_date ?? null,
      }, tool);
    },
  },
  {
    name: 'run_recurring_invoice_now',
    api: [
      ['GET', '/api/recurring/{id}'],
      ['POST', '/api/recurring/{id}/run-now'],
    ],
    title: 'Vygenerovat fakturu ze šablony hned',
    description:
      'Vygeneruje fakturu ze šablony hned, mimo rozvrh, a standardně posune rozvrh na další '
      + 'období. U šablony s automatickým vystavením se faktura rovnou VYSTAVÍ (a případně '
      + 'ODEŠLE e-mailem), což nejde vzít zpět; `draft: true` vytvoří jen koncept.\n\n'
      + 'Jen na výslovný pokyn. Bez `confirm: true` nic nevygeneruje a jen vypíše šablonu a co se stane.',
    inputSchema: schema({
      id: id('ID šablony.'),
      draft: bool('Vytvořit jen koncept, i když šablona vystavuje automaticky.'),
      issue_date: date('Datum vystavení; výchozí podle rozvrhu šablony.'),
      advance_schedule: bool('Posunout rozvrh na další období (výchozí ano).'),
      confirm: CONFIRM,
    }, ['id']),
    write: true,
    destructive: true,
    run: async (c, a, tool) => {
      const template = await c.get(`/recurring/${seg(a.id)}`, null, tool);
      const issues = a.draft !== true && Boolean(template?.auto_issue);
      const outcome = issues
        ? `faktura se rovnou VYSTAVÍ${template?.auto_send_email ? ' a ODEŠLE e-mailem' : ''}`
        : 'vznikne koncept';
      requireConfirm(a, `Ze šablony se má hned vygenerovat faktura (${outcome})`, recurringLabel(template, a.id));
      return c.post(`/recurring/${seg(a.id)}/run-now`, changed(a, ['draft', 'issue_date', 'advance_schedule']), tool);
    },
  },
];

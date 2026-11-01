/**
 * Ostatní pohledávky a závazky: nájem, půjčka, úvěr, leasing, kauce, pojistné,
 * poplatky. Jediná zapisovatelná část účetní vrstvy, a to jen v konceptech.
 *
 * Token smí založit, upravit a smazat KONCEPT, připojit k němu dokument
 * (smlouvu), nastavit jeho dimenze, splátkový kalendář
 * a založit nebo pozastavit opakování bez automatického účtování. Nic z toho
 * nezapisuje do deníku. Potvrzení a zaúčtování, storno, přeúčtování, párování
 * úhrad, generování opakování a zapnutí automatického účtování tu nejsou
 * a server je tokenu odmítne (ApiScopeMiddleware::BEARER_WRITE_EXCEPTIONS).
 *
 * Kontroly tvaru (součet kontace, součet a pořadí splátek, stav dokladu) běží
 * před zápisem, aby model dostal srozumitelnou chybu dřív než 422 ze serveru
 * a aby se nic nezapsalo napůl.
 */

import { CONFIRM, requireConfirm, changed, merged, seg } from './tool-shared.mjs';

const str = (description, extra = {}) => ({ type: 'string', description, ...extra });
const int = (description, extra = {}) => ({ type: 'integer', description, ...extra });
const num = (description, extra = {}) => ({ type: 'number', description, ...extra });
const date = (description) => str(description, { format: 'date' });
const id = (description) => int(description, { minimum: 1 });
const nullable = (prop) => ({ ...prop, type: [prop.type, 'null'] });
const schema = (properties = {}, required = []) => ({
  type: 'object', properties, required, additionalProperties: false,
});

const BASE = '/accounting/other-items';
const NO_POSTING = 'Nic se nezaúčtuje: potvrzení a zaúčtování provede účetní ve webovém rozhraní.';
const KINDS = ['other', 'rent', 'loan', 'deposit', 'insurance', 'fee', 'claim'];
const ACTIVE = ['draft', 'confirmed', 'posted'];

const POSTING_LINES = {
  type: 'array',
  description: 'Protiřádky kontace (protiúčty) v Kč. Součet musí přesně odpovídat `amount`. '
    + 'Pro jediný protiúčet stačí `counter_account_code`.',
  minItems: 1,
  maxItems: 50,
  items: schema({
    account_code: str('Protiúčet, např. 518 nebo 548.', { minLength: 1, maxLength: 20 }),
    amount: num('Částka protiřádku v Kč na haléře.', { exclusiveMinimum: 0 }),
  }, ['account_code', 'amount']),
};

const ITEM_INPUT = {
  side: str('receivable = pohledávka (dluží firmě někdo jiný), payable = závazek (firma dluží).', {
    enum: ['receivable', 'payable'],
  }),
  kind: str('Druh: other = ostatní, rent = nájem, loan = půjčka nebo úvěr, deposit = kauce či záloha, '
    + 'insurance = pojistné, fee = poplatek, claim = jiný nárok. Výchozí other. Skrytý druh (list_other_item_kinds, '
    + 'is_active false) nový koncept nedostane.', { enum: KINDS }),
  kind_id: nullable(id('Vlastní druh firmy (list_other_item_kinds, builtin false) se stejnou stranou a is_active. '
    + 'Má-li druh vlastní řadu, doklad dostane číslo z ní. S kind_id se `kind` uloží jako other.')),
  title: str('Popis dokladu, např. „Nájem kanceláře 10/2026".', { minLength: 1, maxLength: 255 }),
  partner_id: nullable(id('ID protistrany z adresáře (search_clients).')),
  partner_name: nullable(str('Název protistrany, když není v adresáři.', { maxLength: 190 })),
  issued_on: date('Datum vzniku pohledávky nebo závazku (YYYY-MM-DD).'),
  accounting_on: nullable(date('Datum účetního případu; bez zadání stejné jako datum vzniku.')),
  due_on: date('Splatnost (YYYY-MM-DD).'),
  amount: num('Celková částka v Kč na haléře. Agenda je jen v CZK.', { exclusiveMinimum: 0 }),
  variable_symbol: nullable(str('Variabilní symbol, jen číslice.', { pattern: '^[0-9]{1,20}$' })),
  account_code: nullable(str('Rozvahový účet pohledávky nebo závazku (např. 378, 379, 461). '
    + 'Musí být aktivní a odpovídat straně; ověří se při zaúčtování.', { maxLength: 20 })),
  counter_account_code: nullable(str('Jediný protiúčet (jednořádková kontace). Nekombinuj s posting_lines.', {
    maxLength: 20,
  })),
  posting_lines: POSTING_LINES,
  note: nullable(str('Interní poznámka.', { maxLength: 10000 })),
};

/** Pole hlavičky, která PUT přepisuje vždy celá. Kontace se skládá zvlášť. */
const HEAD_KEYS = [
  'side', 'kind', 'kind_id', 'title', 'partner_id', 'partner_name', 'issued_on', 'accounting_on',
  'due_on', 'amount', 'variable_symbol', 'account_code', 'note',
];
const ITEM_KEYS = [...HEAD_KEYS, 'counter_account_code', 'posting_lines'];

const DATE_RE = /^\d{4}-\d{2}-\d{2}$/;

function isDate(value) {
  if (typeof value !== 'string' || !DATE_RE.test(value)) return false;
  const parsed = new Date(`${value}T00:00:00Z`);
  return !Number.isNaN(parsed.getTime()) && parsed.toISOString().slice(0, 10) === value;
}

/** Částka v haléřích, nebo null, když není kladná a zadaná na haléře. */
function cents(value) {
  const n = typeof value === 'string' ? Number(value) : value;
  if (typeof n !== 'number' || !Number.isFinite(n) || n <= 0) return null;
  const scaled = Math.round(n * 100);
  return Math.abs(n * 100 - scaled) < 1e-6 ? scaled : null;
}

const czk = (c) => `${(c / 100).toFixed(2)} Kč`;

function assertPostingLines(lines, amount) {
  if (!Array.isArray(lines) || lines.length < 1 || lines.length > 50) {
    throw new Error('NEPROVEDENO: kontace musí mít 1 až 50 protiřádků.');
  }
  const total = cents(amount);
  let sum = 0;
  for (const [i, line] of lines.entries()) {
    const c = cents(line?.amount);
    if (!line?.account_code || c === null) {
      throw new Error(`NEPROVEDENO: protiřádek ${i + 1} potřebuje účet a kladnou částku na haléře.`);
    }
    sum += c;
  }
  if (total === null || sum !== total) {
    throw new Error(`NEPROVEDENO: součet protiřádků ${czk(sum)} neodpovídá částce dokladu `
      + `${total === null ? String(amount) : czk(total)}.`);
  }
}

function assertKontace(a) {
  if (a.posting_lines !== undefined && a.counter_account_code !== undefined && a.counter_account_code !== null) {
    throw new Error('NEPROVEDENO: zadej buď `counter_account_code` (jeden protiúčet), nebo `posting_lines`, ne obojí.');
  }
}

const sideLabel = (side) => (side === 'receivable' ? 'pohledávka' : 'závazek');
const itemLabel = (row) => `${row?.title ?? `#${row?.id ?? '?'}`} (${sideLabel(row?.side)} `
  + `${cents(row?.amount) !== null ? czk(cents(row.amount)) : row?.amount}, splatnost ${row?.due_on ?? '?'})`;

/** DB vrací čísla jako řetězce; do těla PUT je posíláme jako čísla. */
const numeric = (value) => (typeof value === 'string' && /^-?\d+(\.\d+)?$/.test(value) ? Number(value) : value);

/**
 * Úplné tělo PUT pro úpravu konceptu.
 *
 * PUT konceptu je úplná náhrada: chybějící volitelné pole se vynuluje a bez
 * `posting_lines` server kontaci smaže. Proto se hlavička skládá ze současného
 * stavu a kontace se přenáší výslovně. Jediný protiřádek se při změně částky
 * přepočte, u více protiřádků to nástroj nehádá.
 */
function updateBody(current, a) {
  assertKontace(a);
  const body = merged(current, a, HEAD_KEYS);
  for (const key of ['amount', 'partner_id']) body[key] = numeric(body[key]);
  if (body.kind_id !== undefined) body.kind_id = numeric(body.kind_id);
  // Jiný vestavěný druh než other bez kind_id = přepnutí z vlastního druhu (shodně se serverem).
  if (a.kind !== undefined && a.kind !== 'other' && a.kind_id === undefined) body.kind_id = null;
  if (a.issued_on !== undefined && a.accounting_on === undefined
    && current?.accounting_on && current.accounting_on === current.issued_on) {
    body.accounting_on = a.issued_on;
  }

  if (a.posting_lines !== undefined) {
    assertPostingLines(a.posting_lines, body.amount);
    body.posting_lines = a.posting_lines;
    return body;
  }
  if (a.counter_account_code !== undefined) {
    body.counter_account_code = a.counter_account_code;
    return body;
  }
  const lines = Array.isArray(current?.posting_lines) ? current.posting_lines : [];
  if (lines.length === 1) {
    body.posting_lines = [{ account_code: lines[0].account_code, amount: body.amount }];
  } else if (lines.length > 1) {
    const sum = lines.reduce((total, line) => total + (cents(line.amount) ?? 0), 0);
    if (sum !== cents(body.amount)) {
      throw new Error(`NEPROVEDENO: koncept má ${lines.length} protiřádků kontace a změnou částky by `
        + 'přestaly sedět. Pošli nové `posting_lines` se součtem rovným nové částce.');
    }
    body.posting_lines = lines.map((line) => ({ account_code: line.account_code, amount: numeric(line.amount) }));
  }
  return body;
}

async function loadDraft(c, itemId, tool, verb) {
  const current = await c.get(`${BASE}/${seg(itemId)}`, null, tool);
  if (current?.status !== 'draft') {
    throw new Error(`NEPROVEDENO: ${verb} lze jen koncept; doklad ${itemLabel(current)} je ve stavu `
      + `„${current?.status ?? '?'}". Potvrzený nebo zaúčtovaný doklad mění účetní ve webovém rozhraní.`);
  }
  return current;
}

/**
 * Kontrola splátkového kalendáře před zápisem. Pravidla zrcadlí
 * OtherItemScheduleService::setInstallments, ať model vidí chybu konkrétně
 * a ne až jako 422 po odeslání.
 */
function assertInstallments(item, rows) {
  if (!ACTIVE.includes(item?.status)) {
    throw new Error(`NEPROVEDENO: splátky nelze nastavit u dokladu ve stavu „${item?.status ?? '?'}".`);
  }
  if (Number(item?.paid_amount ?? 0) > 0) {
    throw new Error('NEPROVEDENO: doklad už má spárovanou úhradu, splátkový kalendář se nemění.');
  }
  if (rows.length < 2 || rows.length > 120) {
    throw new Error('NEPROVEDENO: kalendář musí mít 2 až 120 splátek. Celý kalendář zrušíš nástrojem '
      + 'clear_other_item_installments.');
  }
  let previous = '';
  let sum = 0;
  for (const [i, row] of rows.entries()) {
    if (!isDate(row?.due_on)) {
      throw new Error(`NEPROVEDENO: splátka ${i + 1} má neplatné datum „${row?.due_on}" (čekám YYYY-MM-DD).`);
    }
    if (row.due_on <= previous) {
      throw new Error(`NEPROVEDENO: termíny musí být vzestupné a bez opakování; splátka ${i + 1} (${row.due_on}) `
        + `nenásleduje po ${previous}.`);
    }
    if (item.issued_on && row.due_on < item.issued_on) {
      throw new Error(`NEPROVEDENO: splátka ${i + 1} (${row.due_on}) je před datem vzniku dokladu ${item.issued_on}.`);
    }
    const c = cents(row.amount);
    if (c === null) {
      throw new Error(`NEPROVEDENO: splátka ${i + 1} musí mít kladnou částku na haléře.`);
    }
    sum += c;
    previous = row.due_on;
  }
  const total = cents(item.amount);
  if (sum !== total) {
    throw new Error(`NEPROVEDENO: součet splátek ${czk(sum)} neodpovídá částce dokladu `
      + `${total === null ? String(item.amount) : czk(total)} (rozdíl ${czk((total ?? 0) - sum)}). `
      + 'U úvěru a leasingu patří do kalendáře jen jistina; úroky a poplatky nejsou součástí částky dokladu.');
  }
}

const read = (name, title, description, properties, path, query = () => null, required = []) => ({
  name, title, description, inputSchema: schema(properties, required), write: false,
  run: (c, a, tool) => c.get(path(a), query(a), tool),
});

export const OTHER_ITEM_TOOLS = [
  read('list_other_items', 'Ostatní pohledávky a závazky',
    'Ruční ostatní pohledávky a závazky (nájem, půjčky, úvěry, kauce, pojistné, poplatky) se stavem a zbývající '
    + 'částkou, k tomu odděleně převzaté předpisy ze mzdového a daňového modulu (`sources`, jen ke čtení). '
    + 'Bez filtru data vrací splatnosti od roku zpět do roku dopředu.', {
      side: str('Strana.', { enum: ['receivable', 'payable'] }),
      status: str('Stav: open = neuhrazené aktivní, all = vše.', {
        enum: ['open', 'all', 'draft', 'confirmed', 'posted', 'reversed', 'cancelled'],
      }),
      kind: str('Vestavěný druh dokladu (položky bez vlastního druhu).', { enum: KINDS }),
      kind_id: id('Vlastní druh firmy (list_other_item_kinds); má přednost před kind.'),
      from: date('Splatnost od.'),
      to: date('Splatnost do.'),
      query: str('Hledaný text v popisu, protistraně, čísle dokladu nebo VS.'),
      sort_by: str('Řazení.', { enum: ['document_no', 'title', 'partner_name', 'due_on', 'amount', 'remaining_amount', 'status'] }),
      sort_dir: str('Směr řazení.', { enum: ['asc', 'desc'] }),
      page: int('Stránka od 1.', { minimum: 1 }),
      per_page: int('Záznamů na stránce (1 až 200).', { minimum: 1, maximum: 200 }),
    }, () => BASE, (a) => {
      const q = changed(a, ['side', 'status', 'kind', 'kind_id', 'from', 'to', 'sort_by', 'sort_dir', 'page', 'per_page']);
      if (a.query !== undefined) q.q = a.query;
      return q;
    }),
  read('get_other_item', 'Detail ostatní pohledávky nebo závazku',
    'Detail dokladu včetně kontace (`posting_lines`), stavu, uhrazené a zbývající částky.',
    { id: id('ID dokladu.') }, (a) => `${BASE}/${seg(a.id)}`, () => null, ['id']),
  read('list_other_item_allocations', 'Úhrady ostatní položky',
    'Spárované bankovní a pokladní úhrady dokladu. Párování se dělá ve webovém rozhraní.',
    { id: id('ID dokladu.') }, (a) => `${BASE}/${seg(a.id)}/allocations`, () => null, ['id']),
  read('other_item_payment_candidates', 'Kandidáti úhrady ostatní položky',
    'Volné bankovní a pokladní platby v Kč, které by mohly doklad uhradit. Jen k přehledu, nic nepáruje. '
    + 'Vyžaduje právo číst banku nebo pokladnu.', {
      id: id('ID dokladu.'),
      query: str('Hledaný text v popisu platby.'),
      limit: int('Počet kandidátů (1 až 50).', { minimum: 1, maximum: 50 }),
    }, (a) => `${BASE}/${seg(a.id)}/payment-candidates`, (a) => {
      const q = changed(a, ['limit']);
      if (a.query !== undefined) q.q = a.query;
      return q;
    }, ['id']),
  read('list_other_item_kinds', 'Druhy ostatních položek',
    'Vestavěné druhy (builtin_code = hodnota `kind`) a vlastní druhy firmy po stranách, s příznakem vlastní číselné '
    + 'řady (own_series, series_prefix) a skrytí (is_active). `id` vlastního druhu se zadává jako `kind_id`, vestavěný '
    + 'druh se volí přes `kind`. Nastavení druhů se mění ve webovém rozhraní.',
    {}, () => `${BASE}/kinds`),
  read('list_other_item_schedules', 'Opakování ostatních položek',
    'Rozvrhy opakování (měsíčně, čtvrtletně, ročně) se šablonou, stavem a příznakem automatického účtování.',
    {}, () => `${BASE}/schedules`),
  read('get_other_item_schedule', 'Detail opakování ostatní položky',
    'Rozvrh opakování včetně všech vygenerovaných dokladů a jejich stavu.',
    { id: id('ID rozvrhu.') }, (a) => `${BASE}/schedules/${seg(a.id)}`, () => null, ['id']),
  read('get_other_item_installments', 'Splátkový kalendář ostatní položky',
    'Termíny a částky splátek dokladu. Prázdný seznam znamená, že doklad kalendář nemá.',
    { item_id: id('ID dokladu.') }, (a) => `${BASE}/${seg(a.item_id)}/installments`, () => null, ['item_id']),

  {
    name: 'create_other_item',
    title: 'Založit koncept ostatní pohledávky nebo závazku',
    description:
      'Založí KONCEPT ostatní pohledávky nebo závazku, např. nájem, půjčku, úvěr, leasing, kauci nebo pojistné. '
      + `${NO_POSTING} Kontaci zadej jedním protiúčtem (\`counter_account_code\`) nebo protiřádky `
      + '(`posting_lines`, součet = `amount`). Údaje může asistent vyčíst ze smlouvy. Splátky nastav potom '
      + 'nástrojem set_other_item_installments, opakování create_other_item_schedule. Smlouvu nahranou '
      + 'nástrojem upload_document připojíš rovnou přes `document_id`.',
    inputSchema: schema({
      ...ITEM_INPUT,
      document_id: id('ID dokumentu (např. smlouvy z upload_document), který se ke konceptu připojí.'),
    }, ['side', 'title', 'issued_on', 'due_on', 'amount']),
    write: true,
    run: async (c, a, tool) => {
      assertKontace(a);
      if (cents(a.amount) === null) throw new Error('NEPROVEDENO: částka musí být kladná a zadaná na haléře.');
      if (a.posting_lines !== undefined) assertPostingLines(a.posting_lines, a.amount);
      // Dokument se ověří PŘED založením, ať po chybném ID nezůstane koncept bez smlouvy.
      if (a.document_id !== undefined) await c.get(`/documents/${seg(a.document_id)}`, null, tool);
      const item = await c.post(BASE, changed(a, ITEM_KEYS), tool);
      if (a.document_id === undefined) return item;
      const link = await c.post(`/documents/${seg(a.document_id)}/links`, {
        entity_type: 'other_item',
        entity_id: item?.id,
      }, tool);
      return { ...item, document_links: link?.links ?? link };
    },
  },
  {
    name: 'set_other_item_dimensions',
    title: 'Nastavit dimenze konceptu ostatní položky',
    description:
      'Nastaví dimenze hlavičky KONCEPTU ostatní pohledávky nebo závazku (středisko, projekt, vozidlo, '
      + 'vlastní dimenze). Hodnoty a jejich typy vrací list_dimensions; dosavadní dimenze vypíše '
      + 'get_document_dimensions s `document_type: other-items`. Uložení NAHRADÍ celou sadu hlavičky: typ, '
      + 'který nepošleš, se z dokladu odebere. Potvrzený či zaúčtovaný doklad a koncept z opakování '
      + `s automatickým účtováním nástroj odmítne. ${NO_POSTING}`,
    inputSchema: schema({
      id: id('ID konceptu.'),
      dimensions: {
        type: 'array',
        description: 'Všechny dimenze hlavičky, nejvýše jedna hodnota na typ. Prázdný seznam dimenze odebere.',
        maxItems: 50,
        items: schema({
          type_id: id('ID typu dimenze z list_dimensions.'),
          value_id: id('ID hodnoty dimenze z list_dimensions.'),
        }, ['type_id', 'value_id']),
      },
    }, ['id', 'dimensions']),
    write: true,
    run: async (c, a, tool) => {
      const rows = Array.isArray(a.dimensions) ? a.dimensions : [];
      const header = {};
      for (const [i, row] of rows.entries()) {
        if (!Number.isInteger(row?.type_id) || !Number.isInteger(row?.value_id)) {
          throw new Error(`NEPROVEDENO: dimenze ${i + 1} potřebuje celočíselné type_id a value_id.`);
        }
        if (Object.hasOwn(header, String(row.type_id))) {
          throw new Error(`NEPROVEDENO: typ dimenze ${row.type_id} je zadaný víckrát; na typ patří jedna hodnota.`);
        }
        header[String(row.type_id)] = row.value_id;
      }
      await loadDraft(c, a.id, tool, 'nastavit dimenze');
      return c.put(`/accounting/dimensions/documents/other-items/${seg(a.id)}`, { header }, tool);
    },
  },
  {
    name: 'update_other_item',
    title: 'Upravit koncept ostatní pohledávky nebo závazku',
    description:
      'Upraví KONCEPT. Pošli jen pole, která se mají změnit; ostatní nástroj převezme ze současného stavu '
      + '(server přijímá jen celý doklad). Jediný protiřádek kontace se při změně částky přepočte, '
      + `u více protiřádků pošli nové \`posting_lines\`. Potvrzený či zaúčtovaný doklad nástroj odmítne. ${NO_POSTING} `
      + 'Koncept z opakování s automatickým účtováním lze upravit jen ve webovém rozhraní.',
    inputSchema: schema({ id: id('ID konceptu.'), ...ITEM_INPUT }, ['id']),
    write: true,
    run: async (c, a, tool) => {
      if (!ITEM_KEYS.some((key) => a[key] !== undefined)) {
        throw new Error('NEPROVEDENO: není co měnit; zadej aspoň jedno pole dokladu.');
      }
      const current = await loadDraft(c, a.id, tool, 'upravit');
      const body = updateBody(current, a);
      if (cents(body.amount) === null) throw new Error('NEPROVEDENO: částka musí být kladná a zadaná na haléře.');
      return c.put(`${BASE}/${seg(a.id)}`, body, tool);
    },
  },
  {
    name: 'delete_other_item',
    title: 'Smazat koncept ostatní pohledávky nebo závazku',
    description:
      'Smaže KONCEPT (koncept z opakování se zruší). Potvrzený nebo zaúčtovaný doklad smazat nejde, '
      + 'to řeší účetní stornem v aplikaci. Koncept z opakování s automatickým účtováním lze smazat '
      + `jen ve webovém rozhraní. Bez \`confirm: true\` jen vypíše, co by se smazalo. ${NO_POSTING}`,
    inputSchema: schema({ id: id('ID konceptu.'), confirm: CONFIRM }, ['id']),
    write: true,
    destructive: true,
    run: async (c, a, tool) => {
      const current = await loadDraft(c, a.id, tool, 'smazat');
      requireConfirm(a, 'Smazat se má koncept', itemLabel(current));
      return { deleted: current, result: await c.del(`${BASE}/${seg(a.id)}`, tool) };
    },
  },
  {
    name: 'set_other_item_installments',
    title: 'Nastavit splátkový kalendář',
    description:
      'Nastaví splátkový kalendář dokladu a NAHRADÍ JÍM CELÝ DOSAVADNÍ KALENDÁŘ; pošli vždy všechny splátky, '
      + 'ne jen změněné. Kalendář lze vyčíst z dokumentu (úvěrová nebo leasingová smlouva, splátkový '
      + 'kalendář banky): asistent ho z přiloženého dokumentu analyzuje a pošle jako data. Pravidla: 2 až 120 '
      + 'splátek, vzestupné termíny ne dříve než datum vzniku dokladu, kladné částky na haléře a součet přesně '
      + 'rovný částce dokladu (u úvěru jen jistina, úroky ne). Jen u neuhrazeného dokladu. Splátky slouží '
      + `k plánu cashflow a do deníku nic nezapisují. ${NO_POSTING} Zrušení kalendáře: clear_other_item_installments.`,
    inputSchema: schema({
      item_id: id('ID dokladu.'),
      installments: {
        type: 'array',
        description: 'Všechny splátky v pořadí termínů.',
        minItems: 2,
        maxItems: 120,
        items: schema({
          due_on: date('Termín splátky (YYYY-MM-DD).'),
          amount: num('Částka splátky v Kč na haléře.', { exclusiveMinimum: 0 }),
        }, ['due_on', 'amount']),
      },
    }, ['item_id', 'installments']),
    write: true,
    run: async (c, a, tool) => {
      const rows = Array.isArray(a.installments) ? a.installments : [];
      const item = await c.get(`${BASE}/${seg(a.item_id)}`, null, tool);
      assertInstallments(item, rows);
      return c.put(`${BASE}/${seg(a.item_id)}/installments`, {
        items: rows.map((row) => ({ due_on: row.due_on, amount: row.amount })),
      }, tool);
    },
  },
  {
    name: 'clear_other_item_installments',
    title: 'Zrušit splátkový kalendář',
    description:
      'Zruší celý splátkový kalendář dokladu. Bez `confirm: true` jen vypíše, kolik splátek by zmizelo. '
      + NO_POSTING,
    inputSchema: schema({ item_id: id('ID dokladu.'), confirm: CONFIRM }, ['item_id']),
    write: true,
    destructive: true,
    run: async (c, a, tool) => {
      const item = await c.get(`${BASE}/${seg(a.item_id)}`, null, tool);
      const existing = (await c.get(`${BASE}/${seg(a.item_id)}/installments`, null, tool))?.items ?? [];
      if (existing.length === 0) {
        return { cleared: false, message: 'Doklad splátkový kalendář nemá, není co rušit.' };
      }
      requireConfirm(a, `Zrušit se má splátkový kalendář (${existing.length} splátek)`, itemLabel(item));
      return { cleared: existing, result: await c.put(`${BASE}/${seg(a.item_id)}/installments`, { items: [] }, tool) };
    },
  },
  {
    name: 'create_other_item_schedule',
    title: 'Založit opakování ostatní položky',
    description:
      'Založí pravidelné opakování z existujícího dokladu (vzor se zkopíruje). Aplikace pak dopředu vytváří '
      + 'další doklady jako KONCEPTY; automatické účtování se přes API nezapíná a zůstává vypnuté. '
      + `${NO_POSTING} Doklad smí patřit jen do jednoho opakování.`,
    inputSchema: schema({
      item_id: id('ID vzorového dokladu (koncept, potvrzený nebo zaúčtovaný).'),
      frequency: str('Četnost.', { enum: ['monthly', 'quarterly', 'yearly'] }),
      ends_on: date('Poslední možné datum vzniku dokladu; bez zadání bez konce.'),
    }, ['item_id', 'frequency']),
    write: true,
    run: (c, a, tool) => c.post(`${BASE}/${seg(a.item_id)}/schedule`, {
      frequency: a.frequency,
      ...(a.ends_on !== undefined ? { ends_on: a.ends_on } : {}),
      auto_post: false,
    }, tool),
  },
  {
    name: 'set_other_item_schedule_status',
    title: 'Pozastavit nebo obnovit opakování',
    description:
      'Pozastaví (paused) nebo obnoví (active) opakování ostatní položky. Obnovit lze jen opakování bez '
      + 'automatického účtování; to s automatikou obnovuje účetní ve webovém rozhraní. Automatiku nástroj '
      + `nemění. ${NO_POSTING}`,
    inputSchema: schema({
      id: id('ID rozvrhu opakování.'),
      status: str('Nový stav.', { enum: ['active', 'paused'] }),
    }, ['id', 'status']),
    write: true,
    run: async (c, a, tool) => {
      if (a.status === 'active') {
        const schedule = await c.get(`${BASE}/schedules/${seg(a.id)}`, null, tool);
        if (schedule?.auto_post) {
          throw new Error('NEPROVEDENO: opakování má zapnuté automatické účtování; obnovit ho může jen '
            + 'účetní ve webovém rozhraní.');
        }
      }
      return c.put(`${BASE}/schedules/${seg(a.id)}/status`, { status: a.status }, tool);
    },
  },
];

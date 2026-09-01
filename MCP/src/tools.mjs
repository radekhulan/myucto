/**
 * Katalog MCP nástrojů nad veřejným API MyÚčta.
 *
 * ZÁMĚRNÝ ROZSAH: fakturace (vč. vystavování), zakázky, dokumenty, kniha jízd,
 * přehled zaplacených a nezaplacených dokladů, statistika a reporting, daně
 * a účetnictví ke čtení, e-shop se skladem.
 *
 * ÚČETNÍ A DAŇOVÁ VRSTVA JE JEDNOSMĚRNÁ — jen čtení, nikdy zápis. Odhad DPH,
 * obratovka, rozvaha, výsledovka i saldo jsou přesně ta čísla, kvůli kterým se
 * integrace staví, takže je nabízíme. Ale zaúčtování, storno zápisu, uzavření
 * období, zaevidování opravy podle § 46 / § 74b ani odeslání podání na EPO
 * v katalogu nejsou a nikdy nesmí být: jsou to úkony s daňovou odpovědností,
 * kde chyba znamená opravné podání, a model nemá jak doložit jejich správnost.
 * Vynucuje to i server ({@see \MyInvoice\Middleware\ApiScopeMiddleware}), takže
 * i kdyby sem někdo zápisový nástroj přidal, dostane 403.
 *
 * E-SHOP A SKLAD JSOU NAOPAK OBOUSMĚRNÉ — katalog zboží, číselníky, ceny,
 * dodavatelé, média, sklady, skladové doklady i inventury se dají přes API
 * i zapisovat. Zápis do skladu není daňový úkon: pohyb jde vždy dohledat ve
 * skladové knize a zaúčtovaný doklad lze stornovat protidokladem, takže se
 * chyba dá napravit v aplikaci. Účetní dopad vzniká až v účetní vrstvě, která
 * pro token zůstává jen ke čtení.
 *
 * Každý nástroj: { name, title, description, inputSchema, write, destructive,
 *                  run(client, args, toolName) }
 * `write: true`      = mění data; klient je odmítne v režimu MYUCTO_READ_ONLY.
 * `destructive: true`= maže nebo nevratně přepisuje; vyžaduje `confirm: true`
 *                      (viz {@see confirmed}) a hlásí se jako destructiveHint.
 */

const str = (description, extra = {}) => ({ type: 'string', description, ...extra });
const int = (description, extra = {}) => ({ type: 'integer', description, ...extra });
const num = (description, extra = {}) => ({ type: 'number', description, ...extra });
const bool = (description) => ({ type: 'boolean', description });
const date = (description) => ({ type: 'string', format: 'date', description });

const schema = (properties = {}, required = []) => ({
  type: 'object',
  properties,
  required,
  additionalProperties: false,
});

const PAGING = {
  page: int('Stránka, od 1.', { minimum: 1 }),
  per_page: int('Počet záznamů na stránku (5–200, výchozí 50).', { minimum: 5, maximum: 200 }),
};

/** Stránkování endpointů, které jedou na limit/offset místo page/per_page. */
const WINDOW = {
  limit: int('Počet záznamů (1–500, výchozí 100).', { minimum: 1, maximum: 500 }),
  offset: int('Kolik záznamů přeskočit (výchozí 0).', { minimum: 0 }),
};

// ────────────────────────────────────────────────────────────────────────────
// Pojistka nevratných operací
// ────────────────────────────────────────────────────────────────────────────

/**
 * Potvrzovací parametr pro mazání a další nevratné kroky.
 *
 * Zavedeno kvůli tomu, že katalog e-shopu je poprvé zapisovatelný z jazykového
 * modelu. Model si dokáže domyslet, že „ukliď staré štítky" znamená mazání,
 * ale nemá jak vědět, co na štítku visí. Vzor je stejný jako u `allow_duplicate`
 * v `create_client`: bez výslovného souhlasu se operace neprovede.
 */
const CONFIRM = bool(
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
function requireConfirm(a, action, label) {
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
async function confirmed(c, a, tool, { path, action, label }) {
  const current = await c.get(path, null, tool);
  requireConfirm(a, action, label(current));
  return current;
}

/** Popisek záznamu do potvrzovací hlášky — kód a název tak, jak je vidí uživatel. */
const nameOf = (row, fallbackId) => {
  const code = row?.code ?? row?.sku ?? row?.doc_number ?? '';
  const name = row?.name ?? row?.description ?? row?.title ?? '';
  const text = [code, name].filter(Boolean).join(' — ');
  return text || `#${row?.id ?? fallbackId ?? '?'}`;
};

/**
 * Tělo požadavku jen z předaných parametrů.
 *
 * Zdroje e-shopu a skladu dělají partial update (chybějící klíč = beze změny),
 * takže posílat `undefined` klíče by znamenalo rozdíl mezi „neměň" a „vynuluj"
 * setřít — a model, který chce upravit jen název, by tiše smazal EAN.
 */
const changed = (a, keys) => Object.fromEntries(
  keys.filter((k) => a[k] !== undefined).map((k) => [k, a[k]]),
);

/** Složí úplný PUT payload: zadané hodnoty mají přednost, ostatní se převezmou. */
const merged = (current, a, keys) => Object.fromEntries(
  keys
    .filter((k) => a[k] !== undefined || current?.[k] !== undefined)
    .map((k) => [k, a[k] !== undefined ? a[k] : current[k]]),
);

const PROJECT_FIELDS = [
  'client_id', 'name', 'status', 'currency_id', 'hourly_rate', 'payment_due_days',
  'payment_due_unit', 'default_revenue_category_id', 'project_number', 'contract_number',
  'budget_total', 'budget_yearly', 'budget_monthly', 'requires_work_report_approval',
  'note', 'billing_emails', 'billing_emails_mode',
];

const PROJECT_INPUT = {
  id: int('ID zakázky při úpravě; bez ID se založí nová.'),
  client_id: int('ID odběratele. U nové zakázky povinné; při úpravě se nemění.'),
  name: str('Název zakázky.', { maxLength: 190 }),
  status: str('Stav zakázky.', { enum: ['active', 'paused', 'closed'] }),
  currency_id: int('ID měny; bez zadání výchozí měna firmy.'),
  hourly_rate: num('Výchozí hodinová sazba.', { minimum: 0 }),
  payment_due_days: int('Splatnost 1–365 dní. U nové zakázky povinné.', { minimum: 1, maximum: 365 }),
  payment_due_unit: str('Jednotka splatnosti.', { enum: ['days', 'month'] }),
  default_revenue_category_id: int(
    'Výchozí kategorie tržby. Změna může doplnit kategorii i do dosavadních nezařazených faktur zakázky.',
  ),
  project_number: str('Interní číslo zakázky.', { maxLength: 50 }),
  contract_number: str('Číslo smlouvy.', { maxLength: 50 }),
  budget_total: num('Celkový rozpočet.', { minimum: 0 }),
  budget_yearly: num('Roční rozpočet.', { minimum: 0 }),
  budget_monthly: num('Měsíční rozpočet.', { minimum: 0 }),
  requires_work_report_approval: bool('Vyžadovat schválení výkazu práce před vystavením.'),
  note: str('Interní poznámka.'),
  billing_emails: {
    type: 'array',
    description: 'Až tři fakturační e-maily zakázky.',
    maxItems: 3,
    items: schema({
      email: str('E-mailová adresa.'),
      position: int('Pozice 1–3.', { minimum: 1, maximum: 3 }),
      label: str('Volitelný popisek, např. účetní.'),
      usages: {
        type: 'array',
        description: 'Použití e-mailu; prázdné pole znamená všechny typy zpráv.',
        items: { type: 'string', enum: ['documents', 'reminders', 'approvals'] },
      },
    }, ['email', 'position']),
  },
  billing_emails_mode: str('Kombinace s kontakty odběratele.', { enum: ['auto', 'append', 'replace'] }),
};

const LOGBOOK_CAR_FIELDS = [
  'registration', 'name', 'brand', 'model', 'vin', 'fuel_type', 'odometer_start',
  'odometer_start_date', 'is_default', 'is_archived', 'note',
];
const LOGBOOK_CAR_INPUT = {
  id: int('ID vozidla při úpravě; bez ID se založí nové.'),
  registration: str('Registrační značka. U nového vozidla povinná.', { maxLength: 20 }),
  name: str('Vlastní název vozidla.'),
  brand: str('Značka.'),
  model: str('Model.'),
  vin: str('VIN.'),
  fuel_type: str('Druh paliva.', { enum: ['diesel', 'petrol', 'lpg', 'cng', 'electric', 'hybrid', 'other'] }),
  odometer_start: int('Počáteční stav tachometru v km.', { minimum: 0 }),
  odometer_start_date: date('Datum počátečního stavu tachometru.'),
  is_default: bool('Výchozí vozidlo knihy jízd.'),
  is_archived: bool('Archivované vozidlo.'),
  note: str('Poznámka.'),
};

const LOGBOOK_TRIP_FIELDS = [
  'car_id', 'trip_date', 'time_start', 'time_end', 'odometer_start', 'odometer_end',
  'distance_km', 'category_id', 'purpose', 'origin', 'destination', 'note',
];
const LOGBOOK_TRIP_INPUT = {
  id: int('ID jízdy při úpravě; bez ID se založí nová.'),
  car_id: int('ID vozidla. U nové jízdy povinné.'),
  trip_date: date('Datum jízdy. U nové jízdy povinné.'),
  time_start: str('Čas odjezdu, např. 08:30.'),
  time_end: str('Čas příjezdu, např. 10:15.'),
  odometer_start: int('Tachometr před jízdou.', { minimum: 0 }),
  odometer_end: int('Tachometr po jízdě.', { minimum: 0 }),
  distance_km: num('Ujetá vzdálenost; bez zadání se dopočte z tachometru.', { exclusiveMinimum: 0 }),
  category_id: int('Kategorie cesty. U nové jízdy povinná — soukromou/služební povahu nikdy neodhaduj.'),
  purpose: str('Účel cesty.'),
  origin: str('Místo odjezdu.'),
  destination: str('Cíl cesty.'),
  note: str('Poznámka.'),
};

const LOGBOOK_FUELING_FIELDS = [
  'car_id', 'fueled_date', 'fueled_time', 'fuel_type', 'quantity', 'unit', 'unit_price',
  'amount_without_vat', 'amount_vat', 'amount_with_vat', 'currency', 'odometer', 'station',
  'vendor_id', 'receipt_number', 'note',
];

const payrollEmploymentFrom = (detail, employmentId) => {
  const person = detail?.person ?? detail;
  const employment = person?.employments?.find((row) => Number(row?.id) === Number(employmentId));
  if (!employment) throw new Error(`Pracovní vztah #${employmentId} u zaměstnance nebyl nalezen.`);
  return employment;
};

const LOGBOOK_FUELING_INPUT = {
  id: int('ID tankování při úpravě; bez ID se založí nové.'),
  car_id: int('ID vozidla.'),
  fueled_date: date('Datum tankování. U nového záznamu povinné.'),
  fueled_time: str('Čas tankování.'),
  fuel_type: str('Druh paliva.'),
  quantity: num('Množství paliva.', { exclusiveMinimum: 0 }),
  unit: str('Jednotka, typicky l nebo kWh.'),
  unit_price: num('Cena za jednotku.', { minimum: 0 }),
  amount_without_vat: num('Částka bez DPH.', { minimum: 0 }),
  amount_vat: num('Částka DPH.', { minimum: 0 }),
  amount_with_vat: num('Celková částka včetně DPH. U nového záznamu povinná.', { exclusiveMinimum: 0 }),
  currency: str('Kód měny, např. CZK.', { minLength: 3, maxLength: 3 }),
  odometer: int('Stav tachometru.', { minimum: 0 }),
  station: str('Čerpací stanice.'),
  vendor_id: int('ID dodavatele.'),
  receipt_number: str('Číslo účtenky.'),
  note: str('Poznámka.'),
};

// ────────────────────────────────────────────────────────────────────────────
// Pomocné funkce pro výkazy práce
// ────────────────────────────────────────────────────────────────────────────

const rows = (payload) => {
  if (Array.isArray(payload)) return payload;
  return payload?.data ?? payload?.items ?? payload?.projects ?? [];
};

/**
 * Hodinová sazba pro řádek výkazu: zakázka → odběratel → výchozí sazba firmy.
 *
 * Stejné pořadí, v jakém se sazba dědí v aplikaci. Nulová hodnota se bere jako
 * „nevyplněno" a jde se o úroveň výš — jinak by prázdná sazba na zakázce tiše
 * vyrobila výkaz za nula korun.
 */
async function resolveHourlyRate(client, { projectId, clientId }, tool) {
  const source = [];

  if (projectId) {
    const project = await client.get(`/projects/${projectId}`, null, tool);
    const rate = Number(project?.hourly_rate ?? 0);
    if (rate > 0) return { rate, from: `zakázka „${project.name ?? projectId}"` };
    source.push('zakázka nemá sazbu');
  }
  if (clientId) {
    const c = await client.get(`/clients/${clientId}`, null, tool);
    const rate = Number(c?.hourly_rate ?? 0);
    if (rate > 0) return { rate, from: `odběratel „${c.company_name ?? clientId}"` };
    source.push('odběratel nemá sazbu');
  }

  const supplier = await client.get('/settings/supplier', null, tool);
  const rate = Number(supplier?.default_hourly_rate ?? 0);
  if (rate > 0) return { rate, from: 'výchozí sazba firmy' };

  throw new Error(
    'Nepodařilo se určit hodinovou sazbu ('
    + [...source, 'firma nemá výchozí sazbu'].join(', ')
    + '). Zadejte ji parametrem `rate`, nebo ji doplňte na zakázce.',
  );
}

/**
 * Najde koncept faktury, do jehož výkazu se má zapisovat.
 *
 * Když uživatel jmenuje jen zakázku nebo odběratele („výkaz pro AVYX"), musíme
 * doklad dohledat. Při víc než jednom konceptu se ZÁMĚRNĚ nehádá — vrátí se
 * seznam kandidátů a rozhodne uživatel. Zapsat hodiny na cizí doklad je horší
 * než se doptat.
 */
async function resolveDraftInvoice(client, args, tool) {
  if (args.invoice_id) {
    return { invoiceId: Number(args.invoice_id) };
  }

  const needle = String(args.project ?? args.client ?? '').trim();
  if (needle === '') {
    throw new Error('Zadejte `invoice_id`, nebo název zakázky (`project`) či odběratele (`client`).');
  }

  let projectId = null;
  let clientId = null;

  if (args.project) {
    const found = rows(await client.get('/projects', { per_page: 200 }, tool))
      .filter((p) => String(p.name ?? '').toLowerCase().includes(needle.toLowerCase()));
    if (found.length === 0) throw new Error(`Zakázka „${needle}" nenalezena.`);
    if (found.length > 1) {
      throw new Error(
        `Názvu „${needle}" odpovídá víc zakázek: `
        + found.map((p) => `${p.name} (id ${p.id})`).join(', ')
        + '. Upřesněte prosím, o kterou jde.',
      );
    }
    projectId = found[0].id;
    clientId = found[0].client_id ?? null;
  } else {
    const found = rows(await client.get('/clients', { q: needle, per_page: 50 }, tool));
    if (found.length === 0) throw new Error(`Odběratel „${needle}" nenalezen.`);
    if (found.length > 1) {
      throw new Error(
        `Názvu „${needle}" odpovídá víc odběratelů: `
        + found.map((c) => `${c.company_name} (id ${c.id})`).join(', ')
        + '. Upřesněte prosím, o kterého jde.',
      );
    }
    clientId = found[0].id;
  }

  // Výkaz jde uložit jen do konceptu — vystavený doklad je uzamčený.
  const grouped = await client.get('/invoices', {
    per_page: 200,
    filter: { status: 'draft', client_id: clientId, project_id: projectId },
  }, tool);

  const drafts = rows(grouped).flatMap((g) => g.invoices ?? [g]);
  if (drafts.length === 0) {
    throw new Error(
      `Pro „${needle}" není žádný koncept faktury, do kterého by šlo výkaz zapsat. `
      + 'Založte koncept v aplikaci, nebo použijte `create_invoice`.',
    );
  }
  if (drafts.length > 1) {
    throw new Error(
      `Konceptů je víc: `
      + drafts.map((i) => `#${i.id}${i.varsymbol ? ` (${i.varsymbol})` : ''}`).join(', ')
      + '. Zadejte prosím `invoice_id`.',
    );
  }

  return { invoiceId: Number(drafts[0].id), projectId, clientId };
}

/** Název výkazu podle období dokladu — stejný tvar, jaký nabízí aplikace. */
function defaultReportTitle(invoice) {
  const period = String(invoice?.tax_date || invoice?.issue_date || '').slice(0, 7);
  return period ? `Výkaz práce ${period}` : 'Výkaz práce';
}

// ────────────────────────────────────────────────────────────────────────────
// Opakované tvary polí (e-shop a sklad)
//
// Několik zdrojů přijímá kolekci, kterou při zápisu NAHRAZUJÍ celou — ceny,
// dodavatele, překlady i řádky dokladu. Tvar je proto na jednom místě i s
// varováním, že vynechaný prvek znamená smazání; kdyby se popis lišil nástroj
// od nástroje, model by si u jednoho z nich domyslel, že jde o přírůstek.
// ────────────────────────────────────────────────────────────────────────────

const arrayOf = (description, properties, required = [], extra = {}) => ({
  type: 'array',
  description,
  items: {
    type: 'object',
    properties,
    required,
    additionalProperties: false,
  },
  ...extra,
});

const PRICE_ROWS = arrayOf(
  'Kompletní sada cen po měnách. Měna, která tu není, se ze zboží SMAŽE.',
  {
    currency_code: str('Kód měny podle ISO 4217, např. CZK.', { minLength: 3, maxLength: 3 }),
    price_mode: str('Režim: `markup` = přirážka k pořizovací ceně, `fixed` = pevná cena.', { enum: ['markup', 'fixed'] }),
    markup_pct: num('Přirážka v procentech. Používá se u režimu `markup`.'),
    fixed_price: num('Pevná prodejní cena. Povinná u režimu `fixed`.'),
    rounding: str('Zaokrouhlení výsledné ceny.', { enum: ['none', '0.01', '0.10', '0.50', '1', '9_ending'] }),
    is_manual_override: bool('Cenu needituje automatický přepočet.'),
  },
  ['currency_code'],
);

const VENDOR_ROWS = arrayOf(
  'Kompletní seznam dodavatelů zboží. Dodavatel, který tu není, se ze zboží SMAŽE.',
  {
    client_id: int('ID dodavatele — karta odběratele s příznakem „je dodavatel" (`search_clients` s `role: "vendors"`).'),
    vendor_sku: str('Kód zboží u dodavatele.'),
    purchase_price: num('Nákupní cena bez DPH.'),
    currency_code: str('Měna nákupní ceny (ISO 4217). Výchozí CZK.', { minLength: 3, maxLength: 3 }),
    delivery_days: int('Dodací lhůta ve dnech.', { minimum: 0 }),
    stock_qty: num('Množství, které dodavatel hlásí skladem.'),
    is_preferred: bool('Hlavní dodavatel. Nejvýš jeden v seznamu.'),
    note: str('Poznámka.'),
  },
  ['client_id'],
);

const PRODUCT_I18N_ROWS = arrayOf(
  'Kompletní sada jazykových verzí. Jazyk, který tu není, se ze zboží SMAŽE.',
  {
    locale: str('Kód jazyka, max 5 znaků — např. cs, en, de.', { maxLength: 5 }),
    name: str('Název zboží v tomto jazyce. Povinný — řádek bez názvu se přeskočí.'),
    short_desc: str('Krátký popis.'),
    description: str('Podrobný popis.'),
    seo_title: str('Titulek pro vyhledávače.'),
    seo_description: str('Popis pro vyhledávače.'),
    seo_slug: str('Část URL.'),
  },
  ['locale', 'name'],
);

const STOCK_DOC_LINES = arrayOf(
  'Řádky dokladu. Při úpravě dokladu se stávající řádky NAHRAZUJÍ tímto seznamem.',
  {
    stock_item_id: int('ID skladové karty — dohledej přes `search_products`.'),
    qty: num('Množství v měrné jednotce karty. Vždy kladné, směr pohybu určuje `doc_type`.', { exclusiveMinimum: 0 }),
    unit_cost: num('Pořizovací cena za MJ bez DPH. Jen u příjemky (`receipt`) — u výdeje a převodky si ji doplní zaúčtování.'),
    extra_cost: num('Vedlejší pořizovací náklady rozpočítané na řádek (doprava, clo). Jen u příjemky.'),
    note: str('Poznámka k řádku.'),
  },
  ['stock_item_id', 'qty'],
  { minItems: 1 },
);

// ────────────────────────────────────────────────────────────────────────────
// Číselníky e-shopu
// ────────────────────────────────────────────────────────────────────────────

/**
 * Vygeneruje pětici nástrojů (seznam / detail / založit / upravit / smazat) nad
 * plochým číselníkem e-shopu.
 *
 * Výrobci, štítky, poplatky i parametry mají na serveru stejný tvar: `active`
 * jako filtr seznamu, partial update, kolize kódu jako 409 a odmítnuté mazání
 * u referencovaného záznamu. Psát to pětkrát ručně znamená pět příležitostí,
 * jak se v jednom z nich rozejít se zbytkem — a hlavně je díky tomu levné
 * přidat další číselník, až přibude (třeba katalog dodavatelů).
 *
 * Texty se předávají celé, ne skládají z podstatného jména: popis nástroje je
 * to jediné, podle čeho se model rozhoduje, a šroubovaná čeština z generátoru
 * ho mate víc, než kolik ušetří.
 */
function codebookTools({ names, titles, descriptions, path, fields, required, listFields = {} }) {
  const idField = int(`ID záznamu v číselníku (${names.list}).`);

  return [
    {
      name: names.list,
      title: titles.list,
      description: descriptions.list,
      inputSchema: schema({
        active_only: bool('Jen aktivní (nearchivované) záznamy.'),
        ...listFields,
      }),
      write: false,
      run: (c, a, tool) => c.get(path, { active: a.active_only, ...changed(a, Object.keys(listFields)) }, tool),
    },
    {
      name: names.get,
      title: titles.get,
      description: descriptions.get,
      inputSchema: schema({ id: idField }, ['id']),
      write: false,
      run: (c, a, tool) => c.get(`${path}/${a.id}`, null, tool),
    },
    {
      name: names.create,
      title: titles.create,
      description: descriptions.create,
      inputSchema: schema(fields, required),
      write: true,
      run: (c, a, tool) => c.post(path, changed(a, Object.keys(fields)), tool),
    },
    {
      name: names.update,
      title: titles.update,
      description: descriptions.update,
      inputSchema: schema({ id: idField, ...fields }, ['id']),
      write: true,
      run: (c, a, tool) => c.put(`${path}/${a.id}`, changed(a, Object.keys(fields)), tool),
    },
    {
      name: names.delete,
      title: titles.delete,
      description: descriptions.delete,
      inputSchema: schema({ id: idField, confirm: CONFIRM }, ['id']),
      write: true,
      destructive: true,
      run: async (c, a, tool) => {
        const target = `${path}/${a.id}`;
        const was = await confirmed(c, a, tool, {
          path: target,
          action: descriptions.deleteAction,
          label: (row) => nameOf(row, a.id),
        });
        return { deleted: was, result: await c.del(target, tool) };
      },
    },
  ];
}

export const TOOLS = [
  // ──────────────────────────────────────────────────────────────────────────
  // Diagnostika
  // ──────────────────────────────────────────────────────────────────────────
  {
    name: 'whoami',
    title: 'Ověření připojení',
    description:
      'Ověří API token a vrátí, pod jakým uživatelem, rolí a firmou (supplier) se volá '
      + 'a jaký má token rozsah (čtení / čtení a zápis). Volej jako první, když si nejsi '
      + 'jistý připojením nebo když jiný nástroj vrátí chybu oprávnění.',
    inputSchema: schema(),
    write: false,
    run: (c, _a, tool) => c.get('/auth/api-me', null, tool),
  },
  {
    name: 'list_suppliers',
    title: 'Seznam firem',
    description:
      'Firmy (supplier), ke kterým má token přístup. Když je token vázaný na jednu firmu, '
      + 'vrátí jen ji. ID použij v proměnné MYUCTO_SUPPLIER_ID, pokud chceš pracovat s jinou firmou.',
    inputSchema: schema(),
    write: false,
    run: (c, _a, tool) => c.get('/suppliers', null, tool),
  },

  // ──────────────────────────────────────────────────────────────────────────
  // Mzdy - personální údaje a vstupy bez řízení mzdového běhu
  // ──────────────────────────────────────────────────────────────────────────
  {
    name: 'list_payroll_people',
    title: 'Seznam zaměstnanců',
    description:
      'Najde zaměstnance a vrátí lehký přehled jejich pracovních vztahů. '
      + 'Pro sjednanou mzdu a úplné podmínky načti následně `get_payroll_person`.',
    inputSchema: schema({
      filter: str('Filtr zaměstnanců.', { enum: ['all', 'active', 'needs_setup'] }),
      query: str('Hledání podle jména.', { maxLength: 100 }),
      limit: int('Počet záznamů, nejvýše 200.', { minimum: 1, maximum: 200 }),
      offset: int('Kolik záznamů přeskočit.', { minimum: 0 }),
    }),
    write: false,
    run: (c, a, tool) => c.get('/payroll/people', {
      filter: a.filter,
      q: a.query,
      limit: a.limit,
      offset: a.offset,
    }, tool),
  },
  {
    name: 'get_payroll_person',
    title: 'Detail zaměstnance a sjednané mzdy',
    description:
      'Vrátí osobu a všechny pracovní vztahy. `monthly_gross_minor` je aktuálně '
      + 'sjednaná měsíční hrubá mzda v haléřích; `terms` obsahují její historické podmínky.',
    inputSchema: schema({ employee_id: int('ID zaměstnance.') }, ['employee_id']),
    write: false,
    run: (c, a, tool) => c.get(`/payroll/people/${a.employee_id}`, null, tool),
  },
  {
    name: 'change_payroll_salary',
    title: 'Změnit sjednanou mzdu',
    description:
      'Změní měsíční hrubou mzdu pracovního vztahu. `new_terms` založí novou verzi '
      + 'od `effective_from`, takže starší období zachovají původní mzdu. `correction` opraví '
      + 'chybnou částku v aktuální verzi bez změny účinnosti; server ji odmítne, pokud už byla '
      + 'verze použita ve mzdovém běhu. Nástroj nesmí měnit ostatní pracovní podmínky.',
    inputSchema: schema({
      employee_id: int('ID zaměstnance.'),
      employment_id: int('ID pracovního vztahu, jehož mzda se mění.'),
      change_kind: str('`new_terms` pro změnu od data, `correction` pro opravu překlepu.', {
        enum: ['new_terms', 'correction'],
      }),
      effective_from: date('Datum účinnosti změny; povinné pro `new_terms`.'),
      monthly_gross_minor: int('Nová měsíční hrubá mzda v haléřích.', { minimum: 0 }),
      reason: str('Věcný důvod změny nebo opravy.', { minLength: 3, maxLength: 500 }),
    }, ['employee_id', 'employment_id', 'change_kind', 'monthly_gross_minor', 'reason']),
    write: true,
    run: async (c, a, tool) => {
      const detail = await c.get(`/payroll/people/${a.employee_id}`, null, tool);
      const employment = payrollEmploymentFrom(detail, a.employment_id);
      const body = {
        row_version: employment.row_version,
        monthly_gross_minor: a.monthly_gross_minor,
        change_reason: a.reason,
      };
      if (a.change_kind === 'new_terms') {
        if (!a.effective_from) throw new Error('Pro změnu mzdy je povinné effective_from.');
        return c.put(`/payroll/employments/${a.employment_id}/terms`, {
          ...body,
          effective_from: a.effective_from,
        }, tool);
      }
      return c.patch(`/payroll/employments/${a.employment_id}/terms/current`, body, tool);
    },
  },
  {
    name: 'list_payroll_components',
    title: 'Seznam mzdových složek',
    description:
      'Vrátí mzdové složky a jejich ID pro zadání odměny, srážky nebo jiného měsíčního vstupu.',
    inputSchema: schema({ effective_on: date('Datum, ke kterému mají složky platit.') }),
    write: false,
    run: (c, a, tool) => c.get('/payroll/components', { effective_on: a.effective_on }, tool),
  },
  {
    name: 'list_payroll_inputs',
    title: 'Měsíční mzdové vstupy',
    description: 'Rozpracované, schválené i uzamčené vstupy mzdy za měsíc. Nástroj je pouze čte.',
    inputSchema: schema({
      period: str('Měsíc YYYY-MM.', { pattern: '^\\d{4}-\\d{2}$' }),
      employment_id: int('Volitelně jen jeden pracovní vztah.'),
      limit: int('Počet záznamů, nejvýše 200.', { minimum: 1, maximum: 200 }),
      offset: int('Kolik záznamů přeskočit.', { minimum: 0 }),
    }, ['period']),
    write: false,
    run: (c, a, tool) => c.get('/payroll/inputs', {
      period: a.period,
      employment_id: a.employment_id,
      limit: a.limit,
      offset: a.offset,
    }, tool),
  },
  {
    name: 'create_payroll_input',
    title: 'Zadat mzdový vstup',
    description:
      'Založí odměnu, srážku nebo jinou mzdovou složku jako koncept. ID složky zjisti přes '
      + '`list_payroll_components`. Tohle vstup NESCHVÁLÍ a nespustí výpočet mzdy.',
    inputSchema: schema({
      employee_id: int('ID zaměstnance.'),
      employment_id: int('ID pracovního vztahu.'),
      component_id: int('ID mzdové složky.'),
      period: str('Měsíc YYYY-MM.', { pattern: '^\\d{4}-\\d{2}$' }),
      source_period: str('Zdrojový měsíc YYYY-MM, pokud se liší.'),
      amount_minor: int('Částka v haléřích. Znaménko určuje definice mzdové složky.'),
      quantity_milliunits: int('Množství v tisícinách jednotky.'),
      source_kind: str('Původ ručního vstupu.', { enum: ['manual', 'correction'] }),
      external_id: str('Volitelný idempotentní identifikátor externího systému.'),
    }, ['employee_id', 'employment_id', 'component_id', 'period', 'amount_minor']),
    write: true,
    run: (c, a, tool) => c.post('/payroll/inputs', {
      employee_id: a.employee_id,
      employment_id: a.employment_id,
      component_id: a.component_id,
      period: a.period,
      source_period: a.source_period ?? null,
      amount_minor: a.amount_minor,
      quantity_milliunits: a.quantity_milliunits ?? null,
      source_kind: a.source_kind ?? 'manual',
      external_id: a.external_id ?? null,
    }, tool),
  },
  {
    name: 'update_payroll_input',
    title: 'Upravit mzdový vstup',
    description:
      'Upraví existující mzdový vstup ve stavu `draft`. Po schválení nebo uzamčení ho server odmítne.',
    inputSchema: schema({
      id: int('ID mzdového vstupu.'),
      row_version: int('Aktuální verze vstupu.', { minimum: 1 }),
      employee_id: int('ID zaměstnance.'),
      employment_id: int('ID pracovního vztahu.'),
      component_id: int('ID mzdové složky.'),
      period: str('Měsíc YYYY-MM.', { pattern: '^\\d{4}-\\d{2}$' }),
      source_period: str('Zdrojový měsíc YYYY-MM, pokud se liší.'),
      amount_minor: int('Částka v haléřích.'),
      quantity_milliunits: int('Množství v tisícinách jednotky.'),
      source_kind: str('Původ vstupu.', { enum: ['manual', 'correction'] }),
      external_id: str('Volitelný idempotentní identifikátor externího systému.'),
    }, ['id', 'row_version', 'employee_id', 'employment_id', 'component_id', 'period', 'amount_minor']),
    write: true,
    run: (c, a, tool) => c.put(`/payroll/inputs/${a.id}`, {
      row_version: a.row_version,
      employee_id: a.employee_id,
      employment_id: a.employment_id,
      component_id: a.component_id,
      period: a.period,
      source_period: a.source_period ?? null,
      amount_minor: a.amount_minor,
      quantity_milliunits: a.quantity_milliunits ?? null,
      source_kind: a.source_kind ?? 'manual',
      external_id: a.external_id ?? null,
    }, tool),
  },
  {
    name: 'list_payroll_runs',
    title: 'Seznam mzdových běhů',
    description:
      'Najde mzdové běhy a jejich aktuální revize. Pouze čtení: MCP neumí běh vytvořit, '
      + 'počítat, schválit, zaúčtovat, připravit platby ani uzavřít.',
    inputSchema: schema({
      period: str('Volitelný měsíc YYYY-MM.', { pattern: '^\\d{4}-\\d{2}$' }),
      limit: int('Počet záznamů, nejvýše 200.', { minimum: 1, maximum: 200 }),
      offset: int('Kolik záznamů přeskočit.', { minimum: 0 }),
    }),
    write: false,
    run: (c, a, tool) => c.get('/payroll/runs', {
      period: a.period,
      limit: a.limit,
      offset: a.offset,
    }, tool),
  },
  {
    name: 'get_payroll_salary_result',
    title: 'Výsledek mzdy zaměstnance',
    description:
      'Vrátí vypočtený rozpad mzdy konkrétního zaměstnance. Lze zadat přímo `revision_id`, '
      + 'nebo měsíc; v tom případě nástroj vybere nejnovější dostupnou revizi měsíce. '
      + 'Jen čte již vypočtený výsledek a žádný výpočet nespouští.',
    inputSchema: schema({
      employee_id: int('ID zaměstnance.'),
      revision_id: int('ID konkrétní revize mzdového běhu.'),
      period: str('Měsíc YYYY-MM, pokud není zadáno revision_id.', { pattern: '^\\d{4}-\\d{2}$' }),
    }, ['employee_id']),
    write: false,
    run: async (c, a, tool) => {
      let revisionId = a.revision_id;
      let run = null;
      if (!revisionId) {
        if (!a.period) throw new Error('Zadej revision_id nebo period.');
        const page = await c.get('/payroll/runs', { period: a.period, limit: 200, offset: 0 }, tool);
        const runs = page?.runs ?? [];
        run = runs.find((row) => Number(row?.revision_id) > 0) ?? null;
        revisionId = run?.revision_id;
        if (!revisionId) throw new Error(`Pro období ${a.period} není dostupná vypočtená revize mzdy.`);
      }
      const result = await c.get(
        `/payroll/revisions/${revisionId}/net-results/${a.employee_id}`,
        null,
        tool,
      );
      return { ...(run ? { run } : {}), ...result };
    },
  },
  {
    name: 'get_payroll_time_month',
    title: 'Docházka za měsíc',
    description: 'Vrátí směny, zápisy docházky, součty a stav přesčasových limitů. Nic neschvaluje.',
    inputSchema: schema({
      period: str('Měsíc YYYY-MM.', { pattern: '^\\d{4}-\\d{2}$' }),
      employment_id: int('Volitelně jen jeden pracovní vztah.'),
      incomplete_only: bool('Jen neúplné vztahy.'),
      limit: int('Počet vztahů, nejvýše 200.', { minimum: 1, maximum: 200 }),
      offset: int('Kolik vztahů přeskočit.', { minimum: 0 }),
    }, ['period']),
    write: false,
    run: (c, a, tool) => c.get('/payroll/time/month', {
      period: a.period,
      employment_id: a.employment_id,
      incomplete: a.incomplete_only ? 1 : 0,
      limit: a.limit,
      offset: a.offset,
    }, tool),
  },
  {
    name: 'save_payroll_time_entry',
    title: 'Zadat docházku nebo přesčas',
    description:
      'Uloží versionovaný záznam docházky. Pro přesčas nastav `category: overtime`. '
      + 'Nový řádek používá `supersedes_id: null` a `row_version: 0`; při opravě pošli '
      + 'ID a verzi původního řádku. `month_row_version` vezmi z `get_payroll_time_month`. '
      + 'Tento nástroj měsíc NESCHVÁLÍ.',
    inputSchema: schema({
      employment_id: int('ID pracovního vztahu.'),
      category: str('Kategorie práce.', {
        enum: ['regular', 'overtime', 'night', 'weekend', 'holiday', 'difficult_environment'],
      }),
      starts_at: str('Začátek v ISO 8601 včetně časové zóny.'),
      ends_at: str('Konec v ISO 8601 včetně časové zóny.'),
      timezone: str('IANA časová zóna.', { default: 'Europe/Prague' }),
      break_minutes: int('Neplacená přestávka v minutách.', { minimum: 0 }),
      supersedes_id: int('ID nahrazovaného záznamu při opravě.'),
      row_version: int('Verze nahrazovaného záznamu, pro nový řádek 0.', { minimum: 0 }),
      month_row_version: int('Aktuální verze měsíce docházky.', { minimum: 0 }),
      difficulty_factor_count: int('Počet ztěžujících vlivů, jen pro difficult_environment.', { minimum: 1, maximum: 255 }),
    }, ['employment_id', 'category', 'starts_at', 'ends_at', 'break_minutes', 'row_version', 'month_row_version']),
    write: true,
    run: (c, a, tool) => c.post('/payroll/time/entries', {
      employment_id: a.employment_id,
      category: a.category,
      starts_at: a.starts_at,
      ends_at: a.ends_at,
      timezone: a.timezone ?? 'Europe/Prague',
      break_minutes: a.break_minutes,
      supersedes_id: a.supersedes_id ?? null,
      row_version: a.row_version,
      month_row_version: a.month_row_version,
      ...(a.difficulty_factor_count === undefined ? {} : { difficulty_factor_count: a.difficulty_factor_count }),
    }, tool),
  },
  {
    name: 'list_payroll_absences',
    title: 'Seznam absencí',
    description: 'Absence, dovolené a DPN v zadaném období.',
    inputSchema: schema({
      from: date('Začátek období.'),
      to: date('Konec období.'),
      employment_id: int('Volitelně jen jeden pracovní vztah.'),
      limit: int('Počet záznamů, nejvýše 200.', { minimum: 1, maximum: 200 }),
      offset: int('Kolik záznamů přeskočit.', { minimum: 0 }),
    }, ['from', 'to']),
    write: false,
    run: (c, a, tool) => c.get('/payroll/time/absences', {
      from: a.from,
      to: a.to,
      employment_id: a.employment_id,
      limit: a.limit,
      offset: a.offset,
    }, tool),
  },
  {
    name: 'list_payroll_average_earnings',
    title: 'Průměrné výdělky pro náhrady',
    description:
      'Vrátí snapshoty průměrného hodinového výdělku. Jejich ID je povinné pro DPN, '
      + 'dovolenou a některé překážky v práci.',
    inputSchema: schema({ employment_id: int('ID pracovního vztahu.') }, ['employment_id']),
    write: false,
    run: (c, a, tool) => c.get('/payroll/time/averages', { employment_id: a.employment_id }, tool),
  },
  {
    name: 'create_payroll_absence',
    title: 'Zadat absenci nebo neschopenku',
    description:
      'Založí absenci ve stavu `requested`. Pro neschopenku použij `dpn`. DPN, dovolená '
      + 'a překážky vyžadují `average_snapshot_id` z `list_payroll_average_earnings`. '
      + 'Samotné založení absenci neschvaluje.',
    inputSchema: schema({
      employment_id: int('ID pracovního vztahu.'),
      absence_type: str('Druh absence.', {
        enum: [
          'vacation', 'dpn', 'quarantine', 'ocr', 'long_term_care', 'ppm',
          'paternity', 'parental', 'unpaid_leave', 'employee_obstacle',
          'employer_obstacle', 'compensatory_time_off', 'unexcused', 'other',
        ],
      }),
      date_from: date('První den absence.'),
      date_to: date('Poslední den absence.'),
      timezone_name: str('IANA časová zóna.', { default: 'Europe/Prague' }),
      partial_first_minutes: int('Minuty absence v prvním dni při částečném dni.', { minimum: 1 }),
      partial_last_minutes: int('Minuty absence v posledním dni při částečném dni.', { minimum: 1 }),
      average_snapshot_id: int('ID schváleného snapshotu průměrného výdělku.'),
      note: str('Poznámka.', { maxLength: 1000 }),
    }, ['employment_id', 'absence_type', 'date_from', 'date_to']),
    write: true,
    run: (c, a, tool) => c.post('/payroll/time/absences', {
      employment_id: a.employment_id,
      absence_type: a.absence_type,
      date_from: a.date_from,
      date_to: a.date_to,
      timezone_name: a.timezone_name ?? 'Europe/Prague',
      partial_first_minutes: a.partial_first_minutes ?? null,
      partial_last_minutes: a.partial_last_minutes ?? null,
      average_snapshot_id: a.average_snapshot_id ?? null,
      note: a.note ?? null,
    }, tool),
  },
  // ──────────────────────────────────────────────────────────────────────────
  // Odběratelé
  // ──────────────────────────────────────────────────────────────────────────
  {
    name: 'search_clients',
    title: 'Hledat odběratele',
    description:
      'Najde odběratele/dodavatele podle názvu, IČO nebo e-mailu. Používej k získání '
      + '`client_id` před vystavením faktury.',
    inputSchema: schema({
      query: str('Hledaný text — název firmy, IČO, e-mail.'),
      role: str('Filtr na roli.', { enum: ['all', 'customers', 'vendors'] }),
      ...PAGING,
    }),
    write: false,
    run: (c, a, tool) => c.get('/clients', {
      q: a.query, role: a.role, page: a.page, per_page: a.per_page,
    }, tool),
  },
  {
    name: 'get_client',
    title: 'Detail odběratele',
    description: 'Kompletní karta odběratele včetně fakturačních údajů a splatnosti.',
    inputSchema: schema({ id: int('ID odběratele.') }, ['id']),
    write: false,
    run: (c, a, tool) => c.get(`/clients/${a.id}`, null, tool),
  },
  {
    name: 'lookup_company_in_ares',
    title: 'Vyhledat firmu v ARES',
    description:
      'Načte údaje firmy z rejstříku ARES podle IČO — název, adresu, DIČ, právní formu '
      + 'a registraci k DPH. Nic neukládá, jen vrací data.\n\n'
      + 'Používej k ověření údajů před založením odběratele; `create_client` si ale '
      + 'ARES zavolá sám, když mu dáš IČO.\n\n'
      + 'Pozn.: endpoint je v API řešený jako POST, takže i tenhle čtecí dotaz '
      + 'vyžaduje token s rozsahem „čtení a zápis".',
    inputSchema: schema({ ic: str('IČO — 8 číslic.') }, ['ic']),
    write: false,
    run: (c, a, tool) => c.post('/clients/lookup-ares', { ic: String(a.ic).replace(/\D/g, '') }, tool),
  },
  {
    name: 'create_client',
    title: 'Založit odběratele',
    description:
      'Založí nového odběratele (nebo dodavatele). Typické zadání: '
      + '„založ klienta podle IČO 12345678".\n\n'
      + 'KDYŽ ZADÁŠ `ic`, ÚDAJE SE DOTÁHNOU Z ARES — název, adresa, DIČ i registrace '
      + 'k DPH. Cokoli vyplníš ručně má přednost před tím, co vrátí ARES.\n\n'
      + 'Bez IČO je nutné vyplnit `company_name`, `street`, `city` a `zip`.\n\n'
      + 'Před založením se kontroluje, jestli odběratel se stejným IČO nebo DIČ už '
      + 'neexistuje — pokud ano, nic se nevytvoří a vrátí se odkaz na stávající '
      + 'kartu. Vědomý duplicitní zápis povolíš přes `allow_duplicate`.',
    inputSchema: schema({
      ic: str('IČO (8 číslic). Když je vyplněné, zbytek se dotáhne z ARES.'),
      company_name: str('Firma nebo jméno. Povinné, pokud se nedotáhne z ARES.'),
      street: str('Ulice a číslo popisné.'),
      city: str('Město.'),
      zip: str('PSČ.'),
      country_iso2: str('Kód země, dvě písmena. Výchozí CZ.', { minLength: 2, maxLength: 2 }),
      dic: str('DIČ.'),
      main_email: str('Hlavní e-mail pro zasílání dokladů.'),
      phone: str('Telefon.'),
      language: str('Jazyk dokladů.', { enum: ['cs', 'en'] }),
      hourly_rate: num('Hodinová sazba pro výkazy práce.', { minimum: 0 }),
      is_customer: bool('Je odběratel (výchozí ano).'),
      is_vendor: bool('Je i dodavatel (výchozí ne).'),
      note: str('Interní poznámka.'),
      allow_duplicate: bool('Založit i když už odběratel se stejným IČO/DIČ existuje.'),
    }),
    write: true,
    run: async (c, a, tool) => {
      const ic = String(a.ic ?? '').replace(/\D/g, '');
      if (a.ic && ic.length !== 8) {
        throw new Error(`IČO musí mít 8 číslic, dostal jsem „${a.ic}".`);
      }

      // ── ARES ────────────────────────────────────────────────────────────
      let ares = null;
      let aresNote = 'nepoužit (IČO nezadáno)';
      if (ic !== '') {
        try {
          const res = await c.post('/clients/lookup-ares', { ic }, tool);
          if (res?.found === false || !res?.data) {
            aresNote = `IČO ${ic} v ARES nenalezeno — údaje se berou jen ze zadání`;
          } else {
            ares = res.data;
            aresNote = `údaje doplněny z ARES (${ares.company_name ?? ''})`;
          }
        } catch (e) {
          // Výpadek rejstříku nesmí zablokovat založení, když má uživatel údaje sám.
          aresNote = `ARES nedostupný (${e.message}) — údaje se berou jen ze zadání`;
        }
      }

      // Ruční zadání má přednost před rejstříkem: uživatel může vědět
      // o změně, která se do ARES ještě nepropsala.
      const pick = (own, fromAres) => {
        const v = own ?? fromAres ?? '';
        return typeof v === 'string' ? v.trim() : v;
      };

      const payload = {
        company_name: pick(a.company_name, ares?.company_name),
        street: pick(a.street, ares?.street),
        city: pick(a.city, ares?.city),
        zip: pick(a.zip, ares?.zip),
        country_iso2: pick(a.country_iso2, ares?.country_iso2) || 'CZ',
        ic: ic || '',
        dic: pick(a.dic, ares?.dic),
        main_email: String(a.main_email ?? '').trim(),
        phone: a.phone ?? null,
        language: a.language ?? 'cs',
        is_customer: a.is_customer !== false,
        is_vendor: a.is_vendor === true,
        note: a.note ?? '',
      };
      if (a.hourly_rate !== undefined) payload.hourly_rate = Number(a.hourly_rate);
      // ARES je pro CZ autoritativní zdroj registrace k DPH.
      if (ares && typeof ares.is_vat_payer === 'boolean') payload.is_vat_payer = ares.is_vat_payer;

      const missing = ['company_name', 'street', 'city', 'zip'].filter((f) => payload[f] === '');
      if (missing.length > 0) {
        throw new Error(
          `Chybí povinné údaje: ${missing.join(', ')}. `
          + (ic === ''
            ? 'Zadej je, nebo předej `ic` a doplní se z ARES.'
            : `Z ARES se nedotáhly (${aresNote}), doplň je ručně.`),
        );
      }

      // ── Kontrola duplicity ──────────────────────────────────────────────
      if (!a.allow_duplicate) {
        for (const [field, value] of [['IČO', payload.ic], ['DIČ', payload.dic]]) {
          if (!value) continue;
          const found = rows(await c.get('/clients', { q: value, per_page: 10 }, tool));
          const hit = found.find((x) => String(x[field === 'IČO' ? 'ic' : 'dic'] ?? '').trim() === value);
          if (hit) {
            throw new Error(
              `Odběratel se stejným ${field} už existuje: „${hit.company_name}" (id ${hit.id}). `
              + 'Nic jsem nezakládal. Pokud má vzniknout druhá karta, zavolej znovu '
              + 's `allow_duplicate: true`.',
            );
          }
        }
      }

      const created = await c.post('/clients', payload, tool);
      return { ares: aresNote, client: created };
    },
  },
  {
    name: 'update_client',
    title: 'Upravit odběratele',
    description:
      'Změní údaje existujícího odběratele. Uveď jen to, co se má změnit — zbytek '
      + 'karty se zachová (nástroj si ji načte a pošle zpět kompletní).\n\n'
      + '`refresh_from_ares: true` znovu natáhne název, adresu a DIČ z rejstříku '
      + 'podle IČO na kartě. Ručně zadané hodnoty mají i tady přednost před tím, '
      + 'co vrátí ARES. Plátcovství DPH (`is_vat_payer`) se mění VÝHRADNĚ '
      + 'explicitním zadáním — ani ARES ho tady tiše nepřepíše.',
    inputSchema: schema({
      id: int('ID odběratele.'),
      refresh_from_ares: bool('Přenačíst údaje z ARES podle IČO na kartě.'),
      company_name: str('Firma nebo jméno.'),
      street: str('Ulice a číslo popisné.'),
      city: str('Město.'),
      zip: str('PSČ.'),
      country_iso2: str('Kód země, dvě písmena.', { minLength: 2, maxLength: 2 }),
      ic: str('IČO (8 číslic).'),
      dic: str('DIČ.'),
      is_vat_payer: bool('Plátce DPH. Bez zadání se stávající hodnota na kartě nemění.'),
      main_email: str('Hlavní e-mail pro zasílání dokladů.'),
      phone: str('Telefon.'),
      language: str('Jazyk dokladů.', { enum: ['cs', 'en'] }),
      hourly_rate: num('Hodinová sazba pro výkazy práce.', { minimum: 0 }),
      payment_due_days: int('Splatnost ve dnech.', { minimum: 0 }),
      is_customer: bool('Je odběratel.'),
      is_vendor: bool('Je dodavatel.'),
      note: str('Interní poznámka.'),
    }, ['id']),
    write: true,
    run: async (c, a, tool) => {
      const current = await c.get(`/clients/${a.id}`, null, tool);
      if (!current?.id) throw new Error(`Odběratel #${a.id} nenalezen.`);

      let ares = null;
      let aresNote = 'nepoužit';
      if (a.refresh_from_ares) {
        const ic = String(a.ic ?? current.ic ?? '').replace(/\D/g, '');
        if (ic.length !== 8) {
          throw new Error('Přenačtení z ARES vyžaduje platné IČO — karta ho nemá a nebylo zadané.');
        }
        try {
          const res = await c.post('/clients/lookup-ares', { ic }, tool);
          if (res?.found === false || !res?.data) aresNote = `IČO ${ic} v ARES nenalezeno`;
          else { ares = res.data; aresNote = 'údaje přenačteny z ARES'; }
        } catch (e) {
          throw new Error(`ARES je nedostupný (${e.message}), údaje jsem neměnil.`);
        }
      }

      // Pořadí priorit: zadání uživatele → ARES → současný stav karty.
      // Server validuje celý payload (company_name, street, city, zip jsou povinné),
      // takže neposíláme jen změněná pole, ale kompletní kartu.
      const pick = (key, aresKey = key) => a[key] ?? ares?.[aresKey] ?? current[key];

      const payload = {
        company_name: pick('company_name'),
        street: pick('street'),
        city: pick('city'),
        zip: pick('zip'),
        country_iso2: pick('country_iso2') || 'CZ',
        ic: a.ic ?? current.ic ?? '',
        dic: pick('dic'),
        main_email: a.main_email ?? current.main_email ?? '',
        phone: a.phone ?? current.phone ?? null,
        language: a.language ?? current.language ?? 'cs',
        currency_default_id: current.currency_default_id,
        is_customer: a.is_customer ?? current.is_customer,
        is_vendor: a.is_vendor ?? current.is_vendor,
        // Jen explicitně poslaná hodnota — refresh_from_ares plátcovství NEPŘEPISUJE
        // (tichá změna flagu by se propsala do nových dokladů; stávající nesou snapshot).
        is_vat_payer: a.is_vat_payer ?? current.is_vat_payer,
        note: a.note ?? current.note ?? '',
      };
      if (a.hourly_rate !== undefined) payload.hourly_rate = Number(a.hourly_rate);
      else if (current.hourly_rate !== undefined) payload.hourly_rate = current.hourly_rate;
      if (a.payment_due_days !== undefined) payload.payment_due_days = Number(a.payment_due_days);
      else if (current.payment_due_days !== undefined) payload.payment_due_days = current.payment_due_days;

      const updated = await c.put(`/clients/${a.id}`, payload, tool);
      return { ares: aresNote, client: updated };
    },
  },

  // ──────────────────────────────────────────────────────────────────────────
  // Fakturace
  // ──────────────────────────────────────────────────────────────────────────
  {
    name: 'list_invoices',
    title: 'Seznam vystavených faktur',
    description:
      'Vystavené doklady seskupené po měsících. Filtruje se přes stav, typ, odběratele, '
      + 'období a měnu. Pro dotazy typu „co nám nezaplatili" použij raději '
      + '`list_unpaid_invoices` — má správně nastavené filtry včetně záloh.',
    inputSchema: schema({
      query: str('Fulltext — variabilní symbol, odběratel, popis.'),
      status: str('Stav dokladu, více hodnot čárkou.', { examples: ['draft,issued', 'paid'] }),
      type: str('Typ dokladu, více hodnot čárkou.', { examples: ['invoice,proforma'] }),
      client_id: int('Jen doklady tohoto odběratele.'),
      project_id: int('Jen doklady této zakázky.'),
      year: int('Rok podle data zdanitelného plnění (jinak data vystavení).'),
      month: int('Měsíc 1–12; dává smysl spolu s `year`.', { minimum: 1, maximum: 12 }),
      date_from: date('Doklady od tohoto data (RRRR-MM-DD).'),
      date_to: date('Doklady do tohoto data (RRRR-MM-DD).'),
      currency: str('Kód měny, např. CZK nebo EUR.'),
      unpaid_only: bool('Jen neuhrazené doklady.'),
      overdue: bool('Jen doklady po splatnosti.'),
      ...PAGING,
    }),
    write: false,
    run: (c, a, tool) => c.get('/invoices', {
      q: a.query,
      page: a.page,
      per_page: a.per_page,
      filter: {
        status: a.status,
        type: a.type,
        client_id: a.client_id,
        project_id: a.project_id,
        year: a.year,
        month: a.month,
        date_from: a.date_from,
        date_to: a.date_to,
        currency: a.currency,
        unpaid_only: a.unpaid_only,
        overdue: a.overdue,
      },
    }, tool),
  },
  {
    name: 'list_unpaid_invoices',
    title: 'Nezaplacené faktury',
    description:
      'Přehled neuhrazených vystavených dokladů — volitelně jen ty po splatnosti. '
      + 'Tohle je správný nástroj na dotazy „kdo nám dluží", „co je po splatnosti" '
      + 'a „kolik máme v pohledávkách".',
    inputSchema: schema({
      overdue_only: bool('Jen doklady po splatnosti (výchozí ne).'),
      client_id: int('Omezit na jednoho odběratele.'),
      ...PAGING,
    }),
    write: false,
    run: (c, a, tool) => c.get('/invoices', {
      page: a.page,
      per_page: a.per_page ?? 200,
      filter: {
        unpaid_only: true,
        overdue: a.overdue_only,
        client_id: a.client_id,
      },
    }, tool),
  },
  {
    name: 'get_invoice',
    title: 'Detail faktury',
    description: 'Hlavička, položky, rozpis DPH, stav úhrady a historie dokladu.',
    inputSchema: schema({ id: int('ID faktury.') }, ['id']),
    write: false,
    run: (c, a, tool) => c.get(`/invoices/${a.id}`, null, tool),
  },
  {
    name: 'list_invoice_payments',
    title: 'Úhrady faktury',
    description: 'Zaevidované úhrady konkrétního dokladu (částka, datum, zdroj).',
    inputSchema: schema({ id: int('ID faktury.') }, ['id']),
    write: false,
    run: (c, a, tool) => c.get(`/invoices/${a.id}/payments`, null, tool),
  },
  {
    name: 'create_invoice',
    title: 'Vytvořit fakturu (koncept)',
    description:
      'Založí NOVÝ DOKLAD JAKO KONCEPT. Číslo dokladu se přiděluje až při vystavení '
      + '(`issue_invoice`), takže je pořád možné koncept zkontrolovat nebo smazat.\n\n'
      + 'Před voláním si zjisti `client_id` (`search_clients`) a `vat_rate_id` '
      + '(`list_vat_rates`). Ceny položek se zadávají BEZ DPH, pokud nenastavíš '
      + '`prices_include_vat`.',
    inputSchema: schema({
      client_id: int('ID odběratele.'),
      items: {
        type: 'array',
        description: 'Fakturované položky, alespoň jedna.',
        minItems: 1,
        items: schema({
          description: str('Text položky.'),
          quantity: num('Množství. Nesmí být nula.'),
          unit: str('Měrná jednotka, výchozí "ks".'),
          unit_price_without_vat: num('Jednotková cena bez DPH.'),
          vat_rate_id: int('ID sazby DPH — viz `list_vat_rates`.'),
          stock_item_id: int('Volitelná vazba na skladovou kartu.'),
          warehouse_id: int('Sklad k výdeji; jen spolu se `stock_item_id`.'),
        }, ['description', 'quantity', 'unit_price_without_vat', 'vat_rate_id']),
      },
      invoice_type: str('Typ dokladu, výchozí "invoice".', {
        enum: ['invoice', 'proforma', 'credit_note', 'cancellation'],
      }),
      project_id: int('Zakázka; musí patřit odběrateli.'),
      issue_date: date('Datum vystavení (výchozí dnes).'),
      due_date: date('Datum splatnosti (výchozí podle nastavení odběratele).'),
      tax_date: date('Datum zdanitelného plnění. U proformy se ignoruje.'),
      currency: str('Kód měny, např. CZK. Výchozí je měna odběratele.'),
      language: str('Jazyk dokladu.', { enum: ['cs', 'en'] }),
      prices_include_vat: bool('Ceny položek jsou včetně DPH (DPH se dopočítá shora).'),
      reverse_charge: bool('Přenesená daňová povinnost.'),
      discount_percent: num('Sleva z celé faktury v procentech (0–100). Slevovou položku NEposílej v `items`.', { minimum: 0, maximum: 100 }),
      payment_method: str('Způsob úhrady.', { enum: ['bank_transfer', 'card', 'cash', 'other'] }),
      note: str('Poznámka na dokladu.'),
      varsymbol: str('Ruční variabilní symbol; jinak se přidělí automaticky při vystavení.'),
    }, ['client_id', 'items']),
    write: true,
    run: (c, a, tool) => c.post('/invoices', a, tool),
  },
  {
    name: 'issue_invoice',
    title: 'Vystavit fakturu',
    description:
      'Vystaví koncept — přidělí číslo dokladu a uzamkne ho pro editaci. '
      + 'NEVRATNÝ KROK: vystavený doklad už nelze prostě smazat, jen stornovat nebo dobropisovat. '
      + 'Vystavuj až po potvrzení uživatelem.',
    inputSchema: schema({ id: int('ID konceptu faktury.') }, ['id']),
    write: true,
    run: (c, a, tool) => c.post(`/invoices/${a.id}/issue`, {}, tool),
  },
  {
    name: 'send_invoice',
    title: 'Odeslat fakturu e-mailem',
    description:
      'Odešle vystavenou fakturu e-mailem odběrateli. Bez `to` se použijí fakturační '
      + 'adresy z karty odběratele. ODESLANÝ E-MAIL SE NEDÁ VZÍT ZPĚT — posílej jen na výslovný pokyn.',
    inputSchema: schema({
      id: int('ID faktury.'),
      to: { type: 'array', items: { type: 'string' }, description: 'Přepis příjemců; jinak adresy odběratele.' },
      cc: { type: 'array', items: { type: 'string' }, description: 'Kopie.' },
      bcc: { type: 'array', items: { type: 'string' }, description: 'Skrytá kopie.' },
      subject_override: str('Vlastní předmět zprávy.'),
      note: str('Text doplněný do těla e-mailu.'),
    }, ['id']),
    write: true,
    run: (c, a, tool) => c.post(`/invoices/${a.id}/send`, {
      to: a.to, cc: a.cc, bcc: a.bcc, subject_override: a.subject_override, note: a.note,
    }, tool),
  },
  {
    name: 'mark_invoice_paid',
    title: 'Označit fakturu jako uhrazenou',
    description:
      'Zaeviduje úhradu dokladu. Používej jen tam, kde platba nepřijde z bankovního '
      + 'výpisu (hotovost, ruční dohledání) — spárované platby z výpisu se evidují samy.',
    inputSchema: schema({
      id: int('ID faktury.'),
      paid_at: date('Datum úhrady (RRRR-MM-DD). Výchozí dnes.'),
    }, ['id']),
    write: true,
    run: (c, a, tool) => c.post(`/invoices/${a.id}/mark-paid`, { paid_at: a.paid_at }, tool),
  },
  {
    name: 'send_invoice_reminder',
    title: 'Poslat upomínku',
    description:
      'Odešle upomínku k nezaplacené faktuře po splatnosti. Stejně jako `send_invoice` '
      + 'jde o nevratné odeslání e-mailu zákazníkovi — jen na výslovný pokyn.',
    inputSchema: schema({ id: int('ID faktury.') }, ['id']),
    write: true,
    run: (c, a, tool) => c.post(`/invoices/${a.id}/reminder`, {}, tool),
  },
  // ──────────────────────────────────────────────────────────────────────────
  // Výkazy práce a materiálu
  // ──────────────────────────────────────────────────────────────────────────
  {
    name: 'list_projects',
    title: 'Seznam zakázek',
    description:
      'Zakázky s hodinovou sazbou, měnou a splatností. Používej k dohledání '
      + '`project_id` a k ověření sazby před zápisem do výkazu práce.',
    inputSchema: schema({
      status: str('Stav zakázky.', { enum: ['active', 'paused', 'closed'] }),
      client_id: int('Jen zakázky tohoto odběratele.'),
      ...PAGING,
    }),
    write: false,
    run: (c, a, tool) => c.get('/projects', {
      page: a.page,
      per_page: a.per_page,
      filter: { status: a.status, client_id: a.client_id },
    }, tool),
  },
  {
    name: 'get_project',
    title: 'Detail zakázky',
    description:
      'Vrátí kartu zakázky včetně obratu po měsících a letech, počtu faktur '
      + 'a souhrnu nezaplacených dokladů. Použij před úpravou zakázky.',
    inputSchema: schema({ id: int('ID zakázky.') }, ['id']),
    write: false,
    run: (c, a, tool) => c.get(`/projects/${a.id}`, null, tool),
  },
  {
    name: 'project_stats',
    title: 'Statistiky zakázek',
    description:
      'Agregovaný přehled zakázek: nejlepší zakázky letos, loni a za posledních '
      + '12 měsíců, obraty po letech a rozpad podle stavu.',
    inputSchema: schema(),
    write: false,
    run: (c, _a, tool) => c.get('/projects/stats', null, tool),
  },
  {
    name: 'project_profitability',
    title: 'Ziskovost zakázek',
    description:
      'Výnosy, náklady a ziskovost všech zakázek; s `id` vrátí detail jedné zakázky. '
      + 'Jde o analytický přehled, nic se nemění.',
    inputSchema: schema({
      id: int('ID zakázky; bez ID přehled všech zakázek.'),
      date_from: date('Začátek období.'),
      date_to: date('Konec období.'),
      include_archived: bool('V přehledu zahrnout archivované zakázky.'),
    }),
    write: false,
    run: (c, a, tool) => a.id
      ? c.get(`/projects/${a.id}/profit`, { date_from: a.date_from, date_to: a.date_to }, tool)
      : c.get('/projects/profitability', {
        date_from: a.date_from,
        date_to: a.date_to,
        include_archived: a.include_archived ? 1 : undefined,
      }, tool),
  },
  {
    name: 'save_project',
    title: 'Založit nebo upravit zakázku',
    description:
      'Bez `id` založí novou zakázku; tehdy vyžaduje `client_id`, `name` '
      + 'a `payment_due_days`. S `id` načte současný stav a změní jen zadaná pole, '
      + 'aby se ostatní nastavení neztratilo.',
    inputSchema: schema(PROJECT_INPUT),
    write: true,
    run: async (c, a, tool) => {
      if (!a.id) {
        const missing = ['client_id', 'name', 'payment_due_days'].filter(
          (key) => a[key] === undefined || a[key] === '',
        );
        if (missing.length > 0) {
          throw new Error(`Pro novou zakázku chybí: ${missing.join(', ')}.`);
        }
        return c.post('/projects', changed(a, PROJECT_FIELDS), tool);
      }

      const current = await c.get(`/projects/${a.id}`, null, tool);
      return c.put(`/projects/${a.id}`, merged(current, a, PROJECT_FIELDS), tool);
    },
  },
  {
    name: 'archive_project',
    title: 'Archivovat zakázku',
    description:
      'Skryje zakázku z běžných seznamů, ale zachová její doklady a historii. '
      + 'Bez `confirm: true` pouze načte a vypíše zakázku k potvrzení.',
    inputSchema: schema({ id: int('ID zakázky.'), confirm: CONFIRM }, ['id']),
    write: true,
    destructive: true,
    run: async (c, a, tool) => {
      const project = await confirmed(c, a, tool, {
        path: `/projects/${a.id}`,
        action: 'Archivovat se má zakázka',
        label: (row) => nameOf(row, a.id),
      });
      return { archived: project, result: await c.post(`/projects/${a.id}/archive`, {}, tool) };
    },
  },
  {
    name: 'delete_project',
    title: 'Smazat nepoužitou zakázku',
    description:
      'Nevratně smaže pouze zakázku bez vystavených dokladů; zakázku s historií '
      + 'API odmítne a má se archivovat. Bez `confirm: true` nic nesmaže.',
    inputSchema: schema({ id: int('ID zakázky.'), confirm: CONFIRM }, ['id']),
    write: true,
    destructive: true,
    run: async (c, a, tool) => {
      const project = await confirmed(c, a, tool, {
        path: `/projects/${a.id}`,
        action: 'Smazat se má zakázka',
        label: (row) => nameOf(row, a.id),
      });
      return { deleted: project, result: await c.del(`/projects/${a.id}`, tool) };
    },
  },
  {
    name: 'get_work_report',
    title: 'Výkaz práce a materiálu',
    description:
      'Výkaz navázaný na konkrétní fakturu — řádky práce (popis, datum, hodiny, sazba), '
      + 'řádky materiálu a součty. Vrátí `null`, pokud faktura výkaz zatím nemá.',
    inputSchema: schema({ invoice_id: int('ID faktury.') }, ['invoice_id']),
    write: false,
    run: (c, a, tool) => c.get(`/invoices/${a.invoice_id}/work-report`, null, tool),
  },
  {
    name: 'add_work_report_entry',
    title: 'Přidat práci do výkazu',
    description:
      'Přidá řádek odvedené práce do výkazu u KONCEPTU faktury. Typické zadání: '
      + '„přidej do výkazu pro AVYX 3 hodiny práce na MCP serveru".\n\n'
      + 'Doklad určíš buď přímo `invoice_id`, nebo názvem zakázky (`project`) '
      + 'či odběratele (`client`) — nástroj si koncept dohledá sám. Když je konceptů '
      + 'víc, nehádá a vypíše je, ať vybereš.\n\n'
      + 'HODINOVOU SAZBU DOPLNÍ SÁM v pořadí zakázka → odběratel → výchozí sazba firmy. '
      + 'Parametrem `rate` ji lze přebít. Existující řádky výkazu zůstávají beze změny.\n\n'
      + 'Funguje jen na konceptu — u vystaveného dokladu vrátí server chybu.',
    inputSchema: schema({
      description: str('Co se dělalo, např. „Vývoj MCP serveru".'),
      hours: num('Počet hodin. Musí být větší než 0.', { exclusiveMinimum: 0 }),
      invoice_id: int('ID konceptu faktury. Když chybí, dohledá se podle `project` / `client`.'),
      project: str('Název zakázky — alternativa k `invoice_id`.'),
      client: str('Název odběratele — alternativa k `invoice_id`.'),
      work_date: date('Datum provedení práce (RRRR-MM-DD). Nepovinné.'),
      rate: num('Hodinová sazba. Bez zadání se převezme ze zakázky / odběratele / firmy.', { minimum: 0 }),
    }, ['description', 'hours']),
    write: true,
    run: async (c, a, tool) => {
      const hours = Number(a.hours);
      if (!(hours > 0)) throw new Error('Počet hodin musí být větší než 0.');
      if (String(a.description ?? '').trim() === '') throw new Error('Zadejte popis práce.');

      const target = await resolveDraftInvoice(c, a, tool);
      const invoiceId = target.invoiceId;

      const [invoice, report] = await Promise.all([
        c.get(`/invoices/${invoiceId}`, null, tool),
        c.get(`/invoices/${invoiceId}/work-report`, null, tool),
      ]);

      const projectId = report?.project_id ?? invoice?.project_id ?? target.projectId ?? null;
      const clientId = invoice?.client_id ?? target.clientId ?? null;

      let rate = Number(a.rate ?? 0);
      let rateFrom = 'zadáno ručně';
      if (!(rate > 0)) {
        // Existující řádky mají přednost před číselníkem: když se výkaz už jednou
        // vyplnil jinou sazbou, nová hodina má sedět s ním, ne s ceníkem.
        const previous = Number(report?.items?.at(-1)?.rate ?? 0);
        if (previous > 0) {
          rate = previous;
          rateFrom = 'poslední řádek výkazu';
        } else {
          const resolved = await resolveHourlyRate(c, { projectId, clientId }, tool);
          rate = resolved.rate;
          rateFrom = resolved.from;
        }
      }

      const items = (report?.items ?? []).map((it) => ({
        description: it.description,
        work_date: it.work_date ?? null,
        hours: Number(it.hours),
        rate: Number(it.rate),
      }));

      items.push({
        description: String(a.description).trim(),
        work_date: a.work_date ?? null,
        hours,
        rate,
      });

      const saved = await c.put(`/invoices/${invoiceId}/work-report`, {
        project_id: projectId,
        title: report?.title || defaultReportTitle(invoice),
        vat_rate_id: report?.vat_rate_id ?? null,
        items,
      }, tool);

      return {
        added: { description: String(a.description).trim(), hours, rate, work_date: a.work_date ?? null },
        rate_source: rateFrom,
        invoice_id: invoiceId,
        work_report: saved,
      };
    },
  },
  {
    name: 'add_work_report_material',
    title: 'Přidat materiál do výkazu',
    description:
      'Přidá řádek materiálu do výkazu u KONCEPTU faktury (spotřebovaný materiál, '
      + 'nakoupené díly). Doklad se určuje stejně jako u `add_work_report_entry`.\n\n'
      + 'Sazbu DPH materiálu nástroj NEHÁDÁ: převezme ji z už existujícího výkazu, '
      + 'jinak ji musíš zadat v `vat_rate_id` (seznam vrátí `list_vat_rates`). '
      + 'Špatná sazba by se propsala do přiznání k DPH.',
    inputSchema: schema({
      description: str('Název materiálu.'),
      quantity: num('Množství. Musí být větší než 0.', { exclusiveMinimum: 0 }),
      unit_price: num('Cena za jednotku bez DPH.', { minimum: 0 }),
      unit: str('Měrná jednotka, výchozí „ks".'),
      invoice_id: int('ID konceptu faktury. Když chybí, dohledá se podle `project` / `client`.'),
      project: str('Název zakázky — alternativa k `invoice_id`.'),
      client: str('Název odběratele — alternativa k `invoice_id`.'),
      vat_rate_id: int('Sazba DPH materiálu. Povinná, pokud výkaz zatím žádnou nemá.'),
    }, ['description', 'quantity', 'unit_price']),
    write: true,
    run: async (c, a, tool) => {
      const quantity = Number(a.quantity);
      if (!(quantity > 0)) throw new Error('Množství musí být větší než 0.');
      if (String(a.description ?? '').trim() === '') throw new Error('Zadejte název materiálu.');

      const target = await resolveDraftInvoice(c, a, tool);
      const invoiceId = target.invoiceId;

      const [invoice, report] = await Promise.all([
        c.get(`/invoices/${invoiceId}`, null, tool),
        c.get(`/invoices/${invoiceId}/work-report`, null, tool),
      ]);

      const vatRateId = a.vat_rate_id ?? report?.material_vat_rate_id ?? null;
      if (!vatRateId) {
        throw new Error(
          'Výkaz materiálu zatím nemá sazbu DPH. Zavolej `list_vat_rates` a předej '
          + '`vat_rate_id` — sazbu nelze odvodit automaticky.',
        );
      }

      const materials = (report?.materials ?? []).map((m) => ({
        description: m.description,
        quantity: Number(m.quantity),
        unit: m.unit,
        unit_price: Number(m.unit_price),
      }));

      materials.push({
        description: String(a.description).trim(),
        quantity,
        unit: String(a.unit ?? 'ks').trim() || 'ks',
        unit_price: Number(a.unit_price ?? 0),
      });

      const saved = await c.put(`/invoices/${invoiceId}/work-report/materials`, {
        project_id: report?.project_id ?? invoice?.project_id ?? target.projectId ?? null,
        material_title: report?.material_title || 'Materiál',
        material_vat_rate_id: vatRateId,
        materials,
      }, tool);

      return { invoice_id: invoiceId, work_report: saved };
    },
  },
  {
    name: 'remove_work_report_entry',
    title: 'Odebrat řádek z výkazu práce',
    description:
      'Smaže jeden řádek práce z výkazu podle pořadí (1 = první řádek). '
      + 'Pořadí zjistíš z `get_work_report`. Ostatní řádky zůstanou beze změny.',
    inputSchema: schema({
      invoice_id: int('ID konceptu faktury.'),
      row: int('Pořadí řádku, od 1.', { minimum: 1 }),
    }, ['invoice_id', 'row']),
    write: true,
    run: async (c, a, tool) => {
      const [invoice, report] = await Promise.all([
        c.get(`/invoices/${a.invoice_id}`, null, tool),
        c.get(`/invoices/${a.invoice_id}/work-report`, null, tool),
      ]);

      const items = report?.items ?? [];
      const row = Number(a.row);
      if (row < 1 || row > items.length) {
        throw new Error(`Výkaz má ${items.length} řádků práce, řádek ${row} neexistuje.`);
      }

      const kept = items
        .filter((_, i) => i !== row - 1)
        .map((it) => ({
          description: it.description,
          work_date: it.work_date ?? null,
          hours: Number(it.hours),
          rate: Number(it.rate),
        }));

      const saved = await c.put(`/invoices/${a.invoice_id}/work-report`, {
        project_id: report?.project_id ?? invoice?.project_id ?? null,
        title: report?.title || defaultReportTitle(invoice),
        vat_rate_id: report?.vat_rate_id ?? null,
        items: kept,
      }, tool);

      return { removed: items[row - 1], work_report: saved };
    },
  },
  {
    name: 'list_vat_rates',
    title: 'Sazby DPH',
    description:
      'Číselník sazeb DPH s jejich ID. `vat_rate_id` z tohoto seznamu se používá '
      + 'v položkách faktury.',
    inputSchema: schema(),
    write: false,
    run: (c, _a, tool) => c.get('/settings/vat-rates', null, tool),
  },

  // ──────────────────────────────────────────────────────────────────────────
  // Statistika a přehledy
  // ──────────────────────────────────────────────────────────────────────────
  {
    name: 'dashboard_summary',
    title: 'Souhrn nástěnky',
    description:
      'Rychlý přehled: tržby, pohledávky, doklady po splatnosti, stav za aktuální období. '
      + 'Dobrý první dotaz na „jak jsme na tom".',
    inputSchema: schema(),
    write: false,
    run: (c, _a, tool) => c.get('/dashboard/summary', null, tool),
  },
  {
    name: 'revenue_overview',
    title: 'Přehled tržeb a zisku',
    description: 'Souhrnné ukazatele obratu, nákladů a zisku za zvolené období.',
    inputSchema: schema({
      from: date('Období od (RRRR-MM-DD).'),
      to: date('Období do (RRRR-MM-DD).'),
    }),
    write: false,
    run: (c, a, tool) => c.get('/crm/overview', { from: a.from, to: a.to }, tool),
  },
  {
    name: 'revenue_monthly',
    title: 'Tržby po měsících',
    description: 'Časová řada obratu a zisku po měsících — pro trendy a meziroční srovnání.',
    inputSchema: schema({
      from: date('Období od (RRRR-MM-DD).'),
      to: date('Období do (RRRR-MM-DD).'),
    }),
    write: false,
    run: (c, a, tool) => c.get('/crm/monthly', { from: a.from, to: a.to }, tool),
  },
  {
    name: 'top_clients',
    title: 'Nejvýznamnější odběratelé',
    description: 'Žebříček odběratelů podle obratu za období — kdo dělá tržby.',
    inputSchema: schema({
      from: date('Období od (RRRR-MM-DD).'),
      to: date('Období do (RRRR-MM-DD).'),
      limit: int('Kolik odběratelů vrátit.', { minimum: 1, maximum: 100 }),
    }),
    write: false,
    run: (c, a, tool) => c.get('/crm/top-clients', { from: a.from, to: a.to, limit: a.limit }, tool),
  },
  {
    name: 'aging_receivables',
    title: 'Pohledávky podle stáří',
    description:
      'Rozpad neuhrazených pohledávek do pásem po splatnosti (do 30 / 30–60 / 60–90 / 90+ dní). '
      + 'Používej na otázky typu „jak staré jsou naše nedoplatky".',
    inputSchema: schema(),
    write: false,
    run: (c, _a, tool) => c.get('/crm/aging-receivables', null, tool),
  },
  {
    name: 'cash_flow_forecast',
    title: 'Výhled cash flow',
    description: 'Očekávané příjmy a výdaje podle splatností vystavených a přijatých dokladů.',
    inputSchema: schema(),
    write: false,
    run: (c, _a, tool) => c.get('/crm/cash-flow-forecast', null, tool),
  },
  {
    name: 'payment_punctuality',
    title: 'Platební morálka odběratelů',
    description: 'Jak rychle kdo platí — průměrné zpoždění a podíl včasných úhrad.',
    inputSchema: schema(),
    write: false,
    run: (c, _a, tool) => c.get('/crm/payment-punctuality', null, tool),
  },
  {
    name: 'revenue_yearly',
    title: 'Tržby po letech',
    description: 'Meziroční srovnání obratu, nákladů a zisku.',
    inputSchema: schema(),
    write: false,
    run: (c, _a, tool) => c.get('/crm/yearly', null, tool),
  },
  {
    name: 'revenue_breakdown',
    title: 'Rozpad tržeb podle kategorií',
    description: 'Z čeho se skládá obrat — podle kategorií tržeb.',
    inputSchema: schema({
      from: date('Období od (RRRR-MM-DD).'),
      to: date('Období do (RRRR-MM-DD).'),
    }),
    write: false,
    run: (c, a, tool) => c.get('/crm/revenue-breakdown', { from: a.from, to: a.to }, tool),
  },
  {
    name: 'expense_breakdown',
    title: 'Rozpad nákladů podle kategorií',
    description: 'Za co utrácíme — náklady po kategoriích. Pro dotazy „kde nám utíkají peníze".',
    inputSchema: schema({
      from: date('Období od (RRRR-MM-DD).'),
      to: date('Období do (RRRR-MM-DD).'),
    }),
    write: false,
    run: (c, a, tool) => c.get('/crm/expense-breakdown', { from: a.from, to: a.to }, tool),
  },
  {
    name: 'top_vendors',
    title: 'Největší dodavatelé',
    description: 'Žebříček dodavatelů podle objemu nákupů za období.',
    inputSchema: schema({
      from: date('Období od (RRRR-MM-DD).'),
      to: date('Období do (RRRR-MM-DD).'),
      limit: int('Kolik dodavatelů vrátit.', { minimum: 1, maximum: 100 }),
    }),
    write: false,
    run: (c, a, tool) => c.get('/crm/top-vendors', { from: a.from, to: a.to, limit: a.limit }, tool),
  },
  {
    name: 'aging_payables',
    title: 'Závazky podle stáří',
    description: 'Neuhrazené přijaté faktury v pásmech po splatnosti — komu dlužíme my.',
    inputSchema: schema(),
    write: false,
    run: (c, _a, tool) => c.get('/crm/aging-payables', null, tool),
  },
  {
    name: 'dso_dpo',
    title: 'Doba inkasa a doba splatnosti (DSO / DPO)',
    description:
      'DSO = za jak dlouho v průměru inkasujeme pohledávky, DPO = za jak dlouho platíme závazky. '
      + 'Vrací obojí najednou, protože se čtou spolu.',
    inputSchema: schema(),
    write: false,
    run: async (c, _a, tool) => ({
      dso: await c.get('/crm/dso', null, tool),
      dpo: await c.get('/crm/dpo', null, tool),
    }),
  },
  {
    name: 'client_concentration',
    title: 'Koncentrace odběratelů a dodavatelů',
    description:
      'Jak moc obrat závisí na několika málo partnerech — riziko koncentrace na obou stranách.',
    inputSchema: schema(),
    write: false,
    run: async (c, _a, tool) => ({
      clients: await c.get('/crm/concentration', null, tool),
      vendors: await c.get('/crm/vendor-concentration', null, tool),
    }),
  },
  {
    name: 'churn_risk',
    title: 'Odběratelé s rizikem odchodu',
    description: 'Zákazníci, kteří přestali objednávat oproti svému zvyku.',
    inputSchema: schema(),
    write: false,
    run: (c, _a, tool) => c.get('/crm/churn-risk', null, tool),
  },
  {
    name: 'late_payment_risk',
    title: 'Riziko pozdní úhrady',
    description: 'Odhad, které vystavené faktury se pravděpodobně zaplatí pozdě.',
    inputSchema: schema(),
    write: false,
    run: (c, _a, tool) => c.get('/crm/late-risk', null, tool),
  },
  {
    name: 'reminder_effectiveness',
    title: 'Účinnost upomínek',
    description: 'Kolik upomínek vede k úhradě a jak rychle — má smysl upomínat?',
    inputSchema: schema(),
    write: false,
    run: (c, _a, tool) => c.get('/crm/reminder-effectiveness', null, tool),
  },
  {
    name: 'payment_time_histogram',
    title: 'Rozložení doby úhrady',
    description: 'Histogram — za kolik dní od vystavení nám kdo platí.',
    inputSchema: schema(),
    write: false,
    run: (c, _a, tool) => c.get('/crm/payment-time-histogram', null, tool),
  },
  {
    name: 'action_items',
    title: 'Na co si dát pozor',
    description:
      'Automaticky odvozené položky k řešení — nezaplacené doklady, blížící se termíny, '
      + 'nesrovnalosti. Dobrý nástroj na dotaz „co bych měl dneska řešit".',
    inputSchema: schema(),
    write: false,
    run: (c, _a, tool) => c.get('/crm/action-items', null, tool),
  },
  {
    name: 'tax_calendar',
    title: 'Daňový kalendář',
    description: 'Blížící se daňové termíny a povinnosti firmy.',
    inputSchema: schema(),
    write: false,
    run: (c, _a, tool) => c.get('/crm/tax-calendar', null, tool),
  },
  {
    name: 'purchase_summary',
    title: 'Souhrn nákupů',
    description: 'Přehled přijatých faktur — objem, závazky, stav úhrad.',
    inputSchema: schema(),
    write: false,
    run: (c, _a, tool) => c.get('/dashboard/purchase-summary', null, tool),
  },

  // ──────────────────────────────────────────────────────────────────────────
  // Hledání
  // ──────────────────────────────────────────────────────────────────────────
  {
    name: 'search',
    title: 'Globální vyhledávání',
    description:
      'Prohledá najednou odběratele, vystavené faktury a přijaté faktury. '
      + 'Použij, když uživatel jmenuje doklad nebo firmu a nevíš, kde ji hledat. '
      + 'Dotaz musí mít alespoň 2 znaky.',
    inputSchema: schema({ query: str('Hledaný text — název firmy, číslo dokladu, IČO.') }, ['query']),
    write: false,
    run: (c, a, tool) => c.get('/search', { q: a.query }, tool),
  },

  // ──────────────────────────────────────────────────────────────────────────
  // Přijaté faktury (závazky)
  // ──────────────────────────────────────────────────────────────────────────
  {
    name: 'list_purchase_invoices',
    title: 'Seznam přijatých faktur',
    description: 'Přijaté faktury (závazky) s filtry na stav, dodavatele a období.',
    inputSchema: schema({
      query: str('Fulltext — dodavatel, číslo dokladu, popis.'),
      status: str('Stav dokladu, více hodnot čárkou.'),
      vendor_id: int('Jen doklady tohoto dodavatele.'),
      date_from: date('Doklady od tohoto data (RRRR-MM-DD).'),
      date_to: date('Doklady do tohoto data (RRRR-MM-DD).'),
      unpaid_only: bool('Jen neuhrazené závazky.'),
      ...PAGING,
    }),
    write: false,
    run: (c, a, tool) => c.get('/purchase-invoices', {
      q: a.query,
      page: a.page,
      per_page: a.per_page,
      filter: {
        status: a.status,
        vendor_id: a.vendor_id,
        date_from: a.date_from,
        date_to: a.date_to,
        unpaid_only: a.unpaid_only,
      },
    }, tool),
  },
  {
    name: 'get_purchase_invoice',
    title: 'Detail přijaté faktury',
    description: 'Hlavička, položky, rozpis DPH a stav úhrady přijaté faktury.',
    inputSchema: schema({ id: int('ID přijaté faktury.') }, ['id']),
    write: false,
    run: (c, a, tool) => c.get(`/purchase-invoices/${a.id}`, null, tool),
  },

  // ──────────────────────────────────────────────────────────────────────────
  // Dokumenty — metadata, vytěžený text a vazby (bez binárního upload/download)
  // ──────────────────────────────────────────────────────────────────────────
  {
    name: 'list_documents',
    title: 'Seznam dokumentů',
    description:
      'Dokumenty a podsložky ve vybrané složce DMS. Vrací metadata, ne bajty souboru. '
      + 'Pro hledání uvnitř vytěženého textu použij `search_documents`.',
    inputSchema: schema({
      folder_id: int('ID složky; bez zadání kořen.'),
      doc_type: str('Typ dokumentu.', { enum: ['pdf', 'docx', 'xlsx', 'xml', 'zfo', 'p7s', 'zip', 'image', 'other'] }),
      tag: str('Filtrovat podle tagu.'),
      scope: str('Firemní nebo osobní dokumenty.', { enum: ['company', 'user'] }),
      ...PAGING,
    }),
    write: false,
    run: (c, a, tool) => c.get('/documents', {
      folder_id: a.folder_id,
      doc_type: a.doc_type,
      tag: a.tag,
      scope: a.scope,
      page: a.page,
      per_page: a.per_page,
    }, tool),
  },
  {
    name: 'search_documents',
    title: 'Hledat v dokumentech',
    description:
      'Fulltextově hledá v názvu, popisu i vytěženém textu dokumentů. '
      + 'Vrátí bezpečně scoped metadata; obsah vybraného výsledku načti přes `get_document`.',
    inputSchema: schema({ query: str('Hledaný text, alespoň 2 znaky.', { minLength: 2 }) }, ['query']),
    write: false,
    run: (c, a, tool) => c.get('/documents/search', { q: a.query }, tool),
  },
  {
    name: 'get_document',
    title: 'Detail a text dokumentu',
    description:
      'Vrátí metadata, tagy, vazby a přílohy dokumentu. S `include_text: true` přidá '
      + 'bezpečně omezený úsek vytěženého textu; binární obsah souboru nevrací.',
    inputSchema: schema({
      id: int('ID dokumentu.'),
      include_text: bool('Připojit vytěžený text, pokud je dostupný.'),
      text_offset: int('Posun ve znacích pro pokračování dlouhého textu.', { minimum: 0 }),
      text_max_chars: int('Délka úseku 1 000–50 000 znaků, výchozí 20 000.', { minimum: 1000, maximum: 50000 }),
    }, ['id']),
    write: false,
    run: async (c, a, tool) => {
      const document = await c.get(`/documents/${a.id}`, null, tool);
      if (!a.include_text) return document;
      const extractedText = await c.get(`/documents/${a.id}/text`, {
        offset: a.text_offset,
        max_chars: a.text_max_chars,
      }, tool);
      return { document, extracted_text: extractedText };
    },
  },
  {
    name: 'list_entity_documents',
    title: 'Dokumenty připojené k záznamu',
    description:
      'Vrátí dokumenty navázané na odběratele, fakturu, přijatou fakturu, zakázku, '
      + 'účetní zápis nebo bankovní transakci.',
    inputSchema: schema({
      entity_type: str('Typ záznamu.', {
        enum: ['client', 'invoice', 'purchase_invoice', 'project', 'journal_entry', 'bank_transaction'],
      }),
      entity_id: int('ID záznamu.'),
    }, ['entity_type', 'entity_id']),
    write: false,
    run: (c, a, tool) => c.get(`/documents/by-entity/${a.entity_type}/${a.entity_id}`, null, tool),
  },
  {
    name: 'update_document',
    title: 'Upravit metadata dokumentu',
    description:
      'Změní jen zadaný název, popis nebo kompletní sadu tagů dokumentu. '
      + 'Samotný soubor ani jeho vytěžený text nemění.',
    inputSchema: schema({
      id: int('ID dokumentu.'),
      title: str('Název dokumentu.'),
      description: str('Popis dokumentu; prázdný text popis odstraní.'),
      tags: { type: 'array', items: { type: 'string' }, description: 'Kompletní sada tagů.' },
    }, ['id']),
    write: true,
    run: (c, a, tool) => c.patch(`/documents/${a.id}`, changed(a, ['title', 'description', 'tags']), tool),
  },
  {
    name: 'link_document',
    title: 'Připojit dokument k záznamu',
    description:
      'Přidá vazbu existujícího dokumentu na záznam stejné firmy. '
      + 'Nevytváří ani nepřepisuje soubor.',
    inputSchema: schema({
      id: int('ID dokumentu.'),
      entity_type: str('Typ záznamu.', {
        enum: ['client', 'invoice', 'purchase_invoice', 'project', 'journal_entry', 'bank_transaction'],
      }),
      entity_id: int('ID záznamu.'),
    }, ['id', 'entity_type', 'entity_id']),
    write: true,
    run: (c, a, tool) => c.post(`/documents/${a.id}/links`, {
      entity_type: a.entity_type,
      entity_id: a.entity_id,
    }, tool),
  },
  {
    name: 'unlink_document',
    title: 'Odpojit dokument od záznamu',
    description:
      'Odstraní jen vazbu dokumentu na záznam; dokument ani cílový záznam nemaže. '
      + 'Bez `confirm: true` vypíše aktuální dokument k potvrzení.',
    inputSchema: schema({
      id: int('ID dokumentu.'),
      entity_type: str('Typ záznamu.', {
        enum: ['client', 'invoice', 'purchase_invoice', 'project', 'journal_entry', 'bank_transaction'],
      }),
      entity_id: int('ID záznamu.'),
      confirm: CONFIRM,
    }, ['id', 'entity_type', 'entity_id']),
    write: true,
    destructive: true,
    run: async (c, a, tool) => {
      const document = await confirmed(c, a, tool, {
        path: `/documents/${a.id}`,
        action: 'Odpojit se má dokument',
        label: (row) => `${nameOf(row, a.id)} od ${a.entity_type} #${a.entity_id}`,
      });
      return {
        unlinked: document,
        result: await c.del(`/documents/${a.id}/links`, tool, {
          entity_type: a.entity_type,
          entity_id: a.entity_id,
        }),
      };
    },
  },

  // ──────────────────────────────────────────────────────────────────────────
  // Kniha jízd — evidence je zapisovatelná, daňové souhrny zůstávají jen čtecí
  // ──────────────────────────────────────────────────────────────────────────
  {
    name: 'list_logbook_cars',
    title: 'Vozidla knihy jízd',
    description: 'Seznam vozidel; s `id` vrátí detail jednoho vozidla.',
    inputSchema: schema({
      id: int('ID vozidla; bez ID seznam.'),
      include_archived: bool('Zahrnout archivovaná vozidla.'),
    }),
    write: false,
    run: (c, a, tool) => a.id
      ? c.get(`/logbook/cars/${a.id}`, null, tool)
      : c.get('/logbook/cars', { include_archived: a.include_archived ? 1 : undefined }, tool),
  },
  {
    name: 'save_logbook_car',
    title: 'Založit nebo upravit vozidlo',
    description:
      'Bez `id` založí vozidlo a vyžaduje registrační značku. S `id` načte současný '
      + 'stav a změní jen zadaná pole.',
    inputSchema: schema(LOGBOOK_CAR_INPUT),
    write: true,
    run: async (c, a, tool) => {
      if (!a.id) {
        if (!String(a.registration ?? '').trim()) throw new Error('Pro nové vozidlo chybí registration.');
        return c.post('/logbook/cars', changed(a, LOGBOOK_CAR_FIELDS), tool);
      }
      const current = await c.get(`/logbook/cars/${a.id}`, null, tool);
      return c.put(`/logbook/cars/${a.id}`, merged(current, a, LOGBOOK_CAR_FIELDS), tool);
    },
  },
  {
    name: 'delete_logbook_car',
    title: 'Smazat nepoužité vozidlo',
    description:
      'Smaže jen vozidlo bez jízd a tankování; používané vozidlo je nutné archivovat '
      + 'přes `save_logbook_car`. Bez `confirm: true` nic nesmaže.',
    inputSchema: schema({ id: int('ID vozidla.'), confirm: CONFIRM }, ['id']),
    write: true,
    destructive: true,
    run: async (c, a, tool) => {
      const car = await confirmed(c, a, tool, {
        path: `/logbook/cars/${a.id}`,
        action: 'Smazat se má vozidlo',
        label: (row) => row?.registration ?? nameOf(row, a.id),
      });
      return { deleted: car, result: await c.del(`/logbook/cars/${a.id}`, tool) };
    },
  },
  {
    name: 'list_logbook_trip_categories',
    title: 'Kategorie cest',
    description:
      'Kategorie určují mimo jiné soukromou nebo služební povahu cesty. '
      + 'Před založením jízdy vždy nech uživatele vybrat kategorii; nikdy ji neodhaduj.',
    inputSchema: schema({ include_archived: bool('Zahrnout archivované kategorie.') }),
    write: false,
    run: (c, a, tool) => c.get('/logbook/trip-categories', {
      include_archived: a.include_archived ? 1 : undefined,
    }, tool),
  },
  {
    name: 'list_logbook_trips',
    title: 'Jízdy',
    description: 'Seznam jízd s filtry; s `id` vrátí detail jedné jízdy.',
    inputSchema: schema({
      id: int('ID jízdy; bez ID seznam.'),
      car_id: int('Jen vybrané vozidlo.'),
      category_id: int('Jen vybraná kategorie.'),
      year: int('Rok.'),
      month: int('Měsíc 1–12.', { minimum: 1, maximum: 12 }),
      date_from: date('Jízdy od data.'),
      date_to: date('Jízdy do data.'),
      query: str('Fulltext účelu a míst.'),
      ...PAGING,
    }),
    write: false,
    run: (c, a, tool) => a.id
      ? c.get(`/logbook/trips/${a.id}`, null, tool)
      : c.get('/logbook/trips', {
        car_id: a.car_id,
        category_id: a.category_id,
        year: a.year,
        month: a.month,
        date_from: a.date_from,
        date_to: a.date_to,
        q: a.query,
        page: a.page,
        per_page: a.per_page,
      }, tool),
  },
  {
    name: 'save_logbook_trip',
    title: 'Přidat nebo upravit jízdu',
    description:
      'Bez `id` založí jízdu a vyžaduje vozidlo, datum a výslovně vybranou kategorii. '
      + 'Soukromou/služební povahu cesty nikdy neodhaduj. S `id` změní jen zadaná pole.',
    inputSchema: schema(LOGBOOK_TRIP_INPUT),
    write: true,
    run: async (c, a, tool) => {
      if (!a.id) {
        const missing = ['car_id', 'trip_date', 'category_id'].filter(
          (key) => a[key] === undefined || a[key] === '',
        );
        if (missing.length > 0) throw new Error(`Pro novou jízdu chybí: ${missing.join(', ')}.`);
        if (a.distance_km === undefined && (a.odometer_start === undefined || a.odometer_end === undefined)) {
          throw new Error('Zadej distance_km, nebo oba stavy tachometru.');
        }
        return c.post('/logbook/trips', changed(a, LOGBOOK_TRIP_FIELDS), tool);
      }
      const current = await c.get(`/logbook/trips/${a.id}`, null, tool);
      return c.put(`/logbook/trips/${a.id}`, merged(current, a, LOGBOOK_TRIP_FIELDS), tool);
    },
  },
  {
    name: 'delete_logbook_trip',
    title: 'Smazat jízdu',
    description: 'Nevratně smaže jízdu. Bez `confirm: true` ji pouze načte k potvrzení.',
    inputSchema: schema({ id: int('ID jízdy.'), confirm: CONFIRM }, ['id']),
    write: true,
    destructive: true,
    run: async (c, a, tool) => {
      const trip = await confirmed(c, a, tool, {
        path: `/logbook/trips/${a.id}`,
        action: 'Smazat se má jízda',
        label: (row) => `${row?.trip_date ?? '?'}: ${row?.origin ?? '?'} → ${row?.destination ?? '?'}`,
      });
      return { deleted: trip, result: await c.del(`/logbook/trips/${a.id}`, tool) };
    },
  },
  {
    name: 'list_logbook_fuelings',
    title: 'Tankování',
    description: 'Seznam tankování s filtry; s `id` vrátí detail jednoho záznamu.',
    inputSchema: schema({
      id: int('ID tankování; bez ID seznam.'),
      car_id: int('Jen vybrané vozidlo.'),
      vendor_id: int('Jen vybraný dodavatel.'),
      unassigned: bool('Jen tankování bez přiřazeného vozidla.'),
      source: str('Zdroj záznamu.', { enum: ['manual', 'invoice', 'axigon', 'axigon_ai', 'import'] }),
      year: int('Rok.'),
      month: int('Měsíc 1–12.', { minimum: 1, maximum: 12 }),
      date_from: date('Tankování od data.'),
      date_to: date('Tankování do data.'),
      ...PAGING,
    }),
    write: false,
    run: (c, a, tool) => a.id
      ? c.get(`/logbook/fuelings/${a.id}`, null, tool)
      : c.get('/logbook/fuelings', {
        car_id: a.car_id,
        vendor_id: a.vendor_id,
        unassigned: a.unassigned ? 1 : undefined,
        source: a.source,
        year: a.year,
        month: a.month,
        date_from: a.date_from,
        date_to: a.date_to,
        page: a.page,
        per_page: a.per_page,
      }, tool),
  },
  {
    name: 'save_logbook_fueling',
    title: 'Přidat nebo upravit tankování',
    description:
      'Bez `id` založí ruční tankování a vyžaduje datum a celkovou částku. '
      + 'S `id` načte současný záznam a změní jen zadaná pole.',
    inputSchema: schema(LOGBOOK_FUELING_INPUT),
    write: true,
    run: async (c, a, tool) => {
      if (!a.id) {
        const missing = ['fueled_date', 'amount_with_vat'].filter(
          (key) => a[key] === undefined || a[key] === '',
        );
        if (missing.length > 0) throw new Error(`Pro nové tankování chybí: ${missing.join(', ')}.`);
        return c.post('/logbook/fuelings', changed(a, LOGBOOK_FUELING_FIELDS), tool);
      }
      const current = await c.get(`/logbook/fuelings/${a.id}`, null, tool);
      return c.put(`/logbook/fuelings/${a.id}`, merged(current, a, LOGBOOK_FUELING_FIELDS), tool);
    },
  },
  {
    name: 'delete_logbook_fueling',
    title: 'Smazat tankování',
    description: 'Nevratně smaže záznam tankování. Bez `confirm: true` ho pouze načte k potvrzení.',
    inputSchema: schema({ id: int('ID tankování.'), confirm: CONFIRM }, ['id']),
    write: true,
    destructive: true,
    run: async (c, a, tool) => {
      const fueling = await confirmed(c, a, tool, {
        path: `/logbook/fuelings/${a.id}`,
        action: 'Smazat se má tankování',
        label: (row) => `${row?.fueled_date ?? '?'}: ${row?.amount_with_vat ?? '?'} ${row?.currency ?? ''}`,
      });
      return { deleted: fueling, result: await c.del(`/logbook/fuelings/${a.id}`, tool) };
    },
  },
  {
    name: 'logbook_summary',
    title: 'Souhrn knihy jízd',
    description:
      'Roční souhrn kilometrů, služebních a soukromých jízd, tankování, spotřeby '
      + 'a návaznosti tachometru. Jde o daňově citlivý přehled pouze ke čtení.',
    inputSchema: schema({ year: int('Rok; bez zadání aktuální.') }),
    write: false,
    run: (c, a, tool) => c.get('/logbook/summary', { year: a.year }, tool),
  },

  // ──────────────────────────────────────────────────────────────────────────
  // Daně — VÝHRADNĚ KE ČTENÍ (server zápis odmítne, viz hlavička souboru)
  // ──────────────────────────────────────────────────────────────────────────
  {
    name: 'vat_return_preview',
    title: 'Odhad DPH za období',
    description:
      'Náhled přiznání k DPH za zvolené období — jednotlivé řádky i výsledná daňová '
      + 'povinnost či nadměrný odpočet. Tohle je správný nástroj na dotazy typu '
      + '„kolik letos v červenci zaplatíme na DPH" nebo „jak vychází DPH za tenhle kvartál".\n\n'
      + 'U čtvrtletního plátce zadej `period: "quarterly"` a jako `month` libovolný '
      + 'měsíc daného kvartálu. Bez `period` se použije nastavení firmy.\n\n'
      + 'Jde o NÁHLED z aktuálních dat, ne o podané přiznání — podání se dělá v aplikaci.',
    inputSchema: schema({
      year: int('Rok, např. 2026.', { minimum: 2020, maximum: 2050 }),
      month: int('Měsíc 1–12. U čtvrtletního plátce stačí libovolný měsíc kvartálu.', { minimum: 1, maximum: 12 }),
      period: str('Zdaňovací období.', { enum: ['monthly', 'quarterly'] }),
    }),
    write: false,
    run: (c, a, tool) => c.get('/reports/dphdp3/preview', {
      year: a.year, month: a.month, period: a.period,
    }, tool),
  },
  {
    name: 'vat_trend',
    title: 'Vývoj DPH po měsících',
    description: 'Časová řada daňové povinnosti a odpočtů — kolik DPH odvádíme v čase.',
    inputSchema: schema({
      months: int('Kolik měsíců zpět (1–36, výchozí 12).', { minimum: 1, maximum: 36 }),
    }),
    write: false,
    run: (c, a, tool) => c.get('/reports/dphdp3/trend', { months: a.months }, tool),
  },
  {
    name: 'vat_drafts_prediction',
    title: 'Dopad konceptů na DPH',
    description:
      'O kolik se změní DPH, až se vystaví doklady, které jsou zatím v konceptu. '
      + 'Pro dotaz „kolik nám ještě přibude do přiznání".',
    inputSchema: schema({
      year: int('Rok.', { minimum: 2020, maximum: 2050 }),
      month: int('Měsíc 1–12.', { minimum: 1, maximum: 12 }),
    }),
    write: false,
    run: (c, a, tool) => c.get('/reports/dphdp3/drafts-prediction', { year: a.year, month: a.month }, tool),
  },
  {
    name: 'vat_control_statement_preview',
    title: 'Náhled kontrolního hlášení',
    description: 'Kontrolní hlášení za období po sekcích (A1–A5, B1–B3) — náhled, ne podání.',
    inputSchema: schema({
      year: int('Rok.', { minimum: 2020, maximum: 2050 }),
      month: int('Měsíc 1–12.', { minimum: 1, maximum: 12 }),
      period: str('Zdaňovací období.', { enum: ['monthly', 'quarterly'] }),
    }),
    write: false,
    run: (c, a, tool) => c.get('/reports/dphkh1/preview', {
      year: a.year, month: a.month, period: a.period,
    }, tool),
  },
  {
    name: 'vat_ledger',
    title: 'Záznamní evidence DPH',
    description:
      'Doklady, ze kterých se DPH za období skládá — na dohledání, proč vychází taková částka.',
    inputSchema: schema({
      year: int('Rok.', { minimum: 2020, maximum: 2050 }),
      month: int('Měsíc 1–12.', { minimum: 1, maximum: 12 }),
      period: str('Zdaňovací období.', { enum: ['monthly', 'quarterly'] }),
    }),
    write: false,
    run: (c, a, tool) => c.get('/reports/dph-book/preview', {
      year: a.year, month: a.month, period: a.period,
    }, tool),
  },
  {
    name: 'vat_summary_report_preview',
    title: 'Náhled souhrnného hlášení',
    description: 'Souhrnné hlášení k plnění do EU za období — náhled, ne podání.',
    inputSchema: schema({
      year: int('Rok.', { minimum: 2020, maximum: 2050 }),
      month: int('Měsíc 1–12.', { minimum: 1, maximum: 12 }),
      period: str('Zdaňovací období.', { enum: ['monthly', 'quarterly'] }),
    }),
    write: false,
    run: (c, a, tool) => c.get('/reports/dphshv/preview', {
      year: a.year, month: a.month, period: a.period,
    }, tool),
  },
  {
    name: 'income_tax_analysis',
    title: 'Odhad daně z příjmů',
    description:
      'Rozbor základu daně a odhad daňové povinnosti za rok, včetně záloh. '
      + 'Pro dotazy „kolik letos zaplatíme na dani z příjmů".',
    inputSchema: schema({
      year: int('Rok, výchozí aktuální.', { minimum: 2020, maximum: 2050 }),
    }),
    write: false,
    run: (c, a, tool) => c.get('/tax/analysis', { year: a.year }, tool),
  },
  {
    name: 'list_tax_submissions',
    title: 'Historie daňových podání',
    description: 'Odeslaná i připravená podání (DPH, KH, SH) s jejich stavem.',
    inputSchema: schema({ ...PAGING }),
    write: false,
    run: (c, a, tool) => c.get('/reports/submissions', { page: a.page, per_page: a.per_page }, tool),
  },

  // ──────────────────────────────────────────────────────────────────────────
  // Účetnictví — VÝHRADNĚ KE ČTENÍ (server zápis odmítne, viz hlavička souboru)
  // ──────────────────────────────────────────────────────────────────────────
  {
    name: 'list_accounting_periods',
    title: 'Účetní období',
    description:
      'Seznam účetních období s jejich ID a stavem (otevřené / uzavřené). '
      + 'Většina účetních sestav potřebuje `period_id` — začni tímhle nástrojem.',
    inputSchema: schema(),
    write: false,
    run: (c, _a, tool) => c.get('/accounting/periods', null, tool),
  },
  {
    name: 'trial_balance',
    title: 'Obratová předvaha',
    description:
      'Obratovka za období — počáteční stavy, obraty MD/D a konečné zůstatky po účtech. '
      + 'Základní pohled na to, co se v účetnictví za období stalo.',
    inputSchema: schema({
      period_id: int('ID účetního období — viz `list_accounting_periods`.'),
      analytics: bool('Rozpad na analytické účty (jinak jen syntetika).'),
      after_closing: bool('Stav po uzávěrkových operacích.'),
    }, ['period_id']),
    write: false,
    run: (c, a, tool) => c.get('/accounting/reports/trial-balance', {
      period_id: a.period_id,
      analytics: a.analytics ? 1 : undefined,
      after_closing: a.after_closing ? 1 : undefined,
    }, tool),
  },
  {
    name: 'balance_sheet',
    title: 'Rozvaha',
    description: 'Aktiva a pasiva k datu — majetek firmy a jak je financovaný.',
    inputSchema: schema({
      period_id: int('ID účetního období.'),
      as_of: date('Stav k datu (RRRR-MM-DD). Bez zadání konec období.'),
    }, ['period_id']),
    write: false,
    run: (c, a, tool) => c.get('/accounting/reports/balance-sheet', {
      period_id: a.period_id, as_of: a.as_of,
    }, tool),
  },
  {
    name: 'income_statement',
    title: 'Výsledovka',
    description: 'Výkaz zisku a ztráty — výnosy, náklady a hospodářský výsledek za období.',
    inputSchema: schema({
      period_id: int('ID účetního období.'),
      as_of: date('Stav k datu (RRRR-MM-DD). Bez zadání konec období.'),
    }, ['period_id']),
    write: false,
    run: (c, a, tool) => c.get('/accounting/reports/income-statement', {
      period_id: a.period_id, as_of: a.as_of,
    }, tool),
  },
  {
    name: 'general_ledger',
    title: 'Hlavní kniha',
    description: 'Zápisy po účtech za období — pro dohledání, z čeho se zůstatek účtu skládá.',
    inputSchema: schema({
      period_id: int('ID účetního období.'),
      analytics: bool('Rozpad na analytické účty.'),
      client: str('Filtr na odběratele.'),
      vendor: str('Filtr na dodavatele.'),
      item: str('Filtr na text položky.'),
    }, ['period_id']),
    write: false,
    run: (c, a, tool) => c.get('/accounting/reports/general-ledger', {
      period_id: a.period_id,
      analytics: a.analytics ? 1 : undefined,
      client: a.client,
      vendor: a.vendor,
      item: a.item,
    }, tool),
  },
  {
    name: 'account_statement',
    title: 'Výpis z účtu',
    description: 'Všechny pohyby na jednom účtu účtové osnovy za období.',
    inputSchema: schema({
      account_id: int('ID účtu — viz `chart_of_accounts`.'),
      period_id: int('ID účetního období.'),
    }, ['account_id', 'period_id']),
    write: false,
    run: (c, a, tool) => c.get(`/accounting/reports/account-statement/${a.account_id}`, {
      period_id: a.period_id,
    }, tool),
  },
  {
    name: 'saldo',
    title: 'Saldo (nespárované pohledávky a závazky)',
    description:
      'Saldokonto — nevyrovnané zůstatky vůči odběratelům a dodavatelům podle účetnictví. '
      + 'Doplňuje `aging_receivables`, které počítá z dokladů.',
    inputSchema: schema({
      period_id: int('ID účetního období.'),
      as_of: date('Stav k datu (RRRR-MM-DD).'),
      account: str('Omezení na účet, jinak všechny saldokontní účty.'),
      partner_id: int('Jen tento obchodní partner.'),
    }, ['period_id']),
    write: false,
    run: (c, a, tool) => c.get('/accounting/reports/saldo', {
      period_id: a.period_id, as_of: a.as_of, account: a.account, partner_id: a.partner_id,
    }, tool),
  },
  {
    name: 'chart_of_accounts',
    title: 'Účtová osnova',
    description: 'Účty firmy s jejich ID a názvy — potřeba pro `account_statement`.',
    inputSchema: schema(),
    write: false,
    run: (c, _a, tool) => c.get('/accounting/accounts', null, tool),
  },
  {
    name: 'list_journal_entries',
    title: 'Účetní deník',
    description:
      'Účetní zápisy s filtry na období, datum, zdrojový doklad a text. '
      + 'Pro dotazy „jak se zaúčtovala tahle faktura" použij `source_type` + `source_id`.',
    inputSchema: schema({
      query: str('Fulltext přes popis zápisu.'),
      period_id: int('ID účetního období.'),
      date_from: date('Zápisy od data (RRRR-MM-DD).'),
      date_to: date('Zápisy do data (RRRR-MM-DD).'),
      document_no: str('Číslo účetního dokladu.'),
      source_type: str('Typ zdrojového dokladu.', { examples: ['invoice', 'purchase_invoice'] }),
      source_id: int('ID zdrojového dokladu.'),
      posted: bool('Jen zaúčtované (true) nebo jen nezaúčtované (false).'),
      ...PAGING,
    }),
    write: false,
    run: (c, a, tool) => c.get('/accounting/journal', {
      q: a.query,
      period_id: a.period_id,
      date_from: a.date_from,
      date_to: a.date_to,
      document_no: a.document_no,
      source_type: a.source_type,
      source_id: a.source_id,
      posted: a.posted === undefined ? undefined : String(a.posted),
      page: a.page,
      per_page: a.per_page,
    }, tool),
  },
  {
    name: 'get_journal_entry',
    title: 'Detail účetního zápisu',
    description: 'Řádky zápisu (MD / D), účty, částky a vazba na zdrojový doklad.',
    inputSchema: schema({ id: int('ID účetního zápisu.') }, ['id']),
    write: false,
    run: (c, a, tool) => c.get(`/accounting/journal/${a.id}`, null, tool),
  },
  {
    name: 'cash_journal',
    title: 'Peněžní deník (daňová evidence)',
    description:
      'Peněžní deník OSVČ v daňové evidenci — příjmy a výdaje na hotovostní bázi. '
      + 'Dává smysl jen u firem, které vedou daňovou evidenci, ne účetnictví.',
    inputSchema: schema({
      year: int('Rok.', { minimum: 2020, maximum: 2050 }),
      date_from: date('Od data (RRRR-MM-DD).'),
      date_to: date('Do data (RRRR-MM-DD).'),
    }),
    write: false,
    run: (c, a, tool) => c.get('/tax-evidence/cash-journal', {
      year: a.year, date_from: a.date_from, date_to: a.date_to,
    }, tool),
  },
  {
    name: 'get_vat_status_history',
    title: 'Historie plátcovství DPH firmy',
    description:
      'Historie plátcovství DPH vlastní firmy (tabulka supplier_vat_status_history) — '
      + 'řádky {effective_from, is_vat_payer, annual_deduction_percent} vzestupně podle '
      + 'účinnosti. Stav k datu D = poslední řádek s effective_from <= D. Pole '
      + '`is_vat_payer` na firmě je jen cache dnešního stavu; pro otázky „byla firma '
      + 'plátce v roce X / k datu Y" použij tuhle historii.',
    inputSchema: schema(),
    write: false,
    run: async (c, _a, tool) => {
      const supplier = await c.get('/settings/supplier', null, tool);
      const history = Array.isArray(supplier?.vat_status_history) ? supplier.vat_status_history : [];
      return {
        is_vat_payer_today: Boolean(supplier?.is_vat_payer),
        history: [...history].sort(
          (x, y) => String(x?.effective_from ?? '').localeCompare(String(y?.effective_from ?? '')),
        ),
      };
    },
  },

  // ──────────────────────────────────────────────────────────────────────────
  // E-shop — zboží (skladová karta)
  //
  // Zboží je jedna entita ve dvou pohledech. `/stock/items` drží skladovou
  // identitu (SKU, název, MJ, sazba DPH, minimální zásoba) a je jediné místo,
  // kde karta vzniká a zaniká; `/eshop/products/{id}` je nadstavba s obsahem
  // pro e-shop. Nástroje to kopírují, aby bylo z názvu poznat, co se mění.
  // ──────────────────────────────────────────────────────────────────────────
  {
    name: 'search_products',
    title: 'Rychlé hledání zboží',
    description:
      'Našeptávač nad skladovými kartami — hledá podle názvu, kódu/SKU a EAN. '
      + 'Pro filtrování a stránkování použij `list_products`.',
    inputSchema: schema({
      query: str('Hledaný text — název, kód nebo EAN zboží.'),
      limit: int('Maximální počet výsledků (1–200, výchozí 50).', { minimum: 1, maximum: 200 }),
    }, ['query']),
    write: false,
    run: (c, a, tool) => c.get('/stock/items/search', { q: a.query, limit: a.limit }, tool),
  },
  {
    name: 'list_products',
    title: 'Seznam zboží',
    description:
      'Skladové karty s filtry. `type: "goods"` vrátí jen e-shopové zboží, '
      + '`only_below_min: true` položky pod minimální zásobou (kandidáti na doobjednání).',
    inputSchema: schema({
      query: str('Fulltext přes název, kód a EAN.'),
      type: str('Typ karty.', { enum: ['goods', 'material', 'product'] }),
      active: bool('Jen aktivní (true) nebo jen neaktivní (false) karty.'),
      only_below_min: bool('Jen položky pod minimální zásobou.'),
      ...PAGING,
    }),
    write: false,
    run: (c, a, tool) => c.get('/stock/items', {
      q: a.query,
      type: a.type,
      active: a.active === undefined ? undefined : (a.active ? 1 : 0),
      only_below_min: a.only_below_min,
      page: a.page,
      per_page: a.per_page,
    }, tool),
  },
  {
    name: 'get_product',
    title: 'Karta zboží',
    description:
      'Kompletní e-shopová karta zboží — popis, kategorie, výrobce, parametry, štítky a média. '
      + 'Skladovou identitu (SKU, MJ, sazbu DPH) vrací tenhle agregát taky, ale mění se přes '
      + '`update_product`; e-shopový obsah přes `update_product_card`.',
    inputSchema: schema({ id: int('ID zboží (skladové karty).') }, ['id']),
    write: false,
    run: (c, a, tool) => c.get(`/eshop/products/${a.id}`, null, tool),
  },
  {
    name: 'create_product',
    title: 'Založit zboží',
    description:
      'Založí novou skladovou kartu. Pro e-shopové zboží nech `item_type` na `goods`.\n\n'
      + 'Bez `sku` se kód odvodí z názvu; duplicitní SKU server odmítne (409). '
      + 'Karta vzniká prázdná — popisy, kategorie a parametry doplní `update_product_card`, '
      + 'ceny `set_product_prices`. Naskladnění je samostatný krok: '
      + '`create_stock_document` s `doc_type: "receipt"` a `post_stock_document`.',
    inputSchema: schema({
      name: str('Název zboží.', { maxLength: 255 }),
      sku: str('Kód/SKU. Bez zadání se odvodí z názvu.', { maxLength: 50 }),
      item_type: str('Typ karty. Výchozí `goods` (zboží e-shopu).', { enum: ['goods', 'material', 'product'] }),
      unit: str('Měrná jednotka. Výchozí `ks`.'),
      ean: str('Čárový kód EAN.'),
      vat_rate_id: int('ID sazby DPH — číselník vrací `list_vat_rates`.'),
      sale_price_without_vat: num('Základní prodejní cena bez DPH.', { minimum: 0 }),
      min_qty: num('Minimální zásoba pro hlídání podlimitních položek.', { minimum: 0 }),
      is_active: bool('Aktivní karta (výchozí ano).'),
      note: str('Interní poznámka.'),
    }, ['name']),
    write: true,
    run: (c, a, tool) => c.post('/stock/items', changed(a, [
      'name', 'sku', 'item_type', 'unit', 'ean', 'vat_rate_id',
      'sale_price_without_vat', 'min_qty', 'is_active', 'note',
    ]), tool),
  },
  {
    name: 'update_product',
    title: 'Upravit skladovou identitu zboží',
    description:
      'Změní SKU, název, měrnou jednotku, EAN, sazbu DPH, minimální zásobu nebo aktivitu karty. '
      + 'Uveď jen to, co se má změnit — nevyplněné údaje zůstanou beze změny.\n\n'
      + 'POZOR na `unit` a `vat_rate_id`: obojí se propisuje do nových dokladů, takže změna '
      + 'u zavedené karty mění chování fakturace. Popisky, kategorie a parametry pro e-shop '
      + 'sem NEPATŘÍ — na ty je `update_product_card`.',
    inputSchema: schema({
      id: int('ID zboží (skladové karty).'),
      name: str('Název zboží.', { maxLength: 255 }),
      sku: str('Kód/SKU.', { maxLength: 50 }),
      item_type: str('Typ karty.', { enum: ['goods', 'material', 'product'] }),
      unit: str('Měrná jednotka.'),
      ean: str('Čárový kód EAN.'),
      vat_rate_id: int('ID sazby DPH.'),
      sale_price_without_vat: num('Základní prodejní cena bez DPH.', { minimum: 0 }),
      min_qty: num('Minimální zásoba.', { minimum: 0 }),
      is_active: bool('Aktivní karta. `false` kartu skryje, aniž by přišla o historii pohybů — '
        + 'tohle je náhrada za mazání u zboží, se kterým se už obchodovalo.'),
      note: str('Interní poznámka.'),
    }, ['id']),
    write: true,
    run: (c, a, tool) => c.put(`/stock/items/${a.id}`, changed(a, [
      'name', 'sku', 'item_type', 'unit', 'ean', 'vat_rate_id',
      'sale_price_without_vat', 'min_qty', 'is_active', 'note',
    ]), tool),
  },
  {
    name: 'delete_product',
    title: 'Smazat zboží',
    description:
      'Smaže skladovou kartu i s jejím e-shopovým obsahem, cenami a médii.\n\n'
      + 'Kartu, která má jakýkoli skladový pohyb, server smazat NEDOVOLÍ (409) — a je to '
      + 'správně, historie skladu musí zůstat průkazná. V takovém případě kartu jen '
      + 'deaktivuj: `update_product` s `is_active: false`.',
    inputSchema: schema({ id: int('ID zboží (skladové karty).'), confirm: CONFIRM }, ['id']),
    write: true,
    destructive: true,
    run: async (c, a, tool) => {
      const was = await confirmed(c, a, tool, {
        path: `/stock/items/${a.id}`,
        action: 'Smazat se má skladová karta',
        label: (row) => nameOf(row, a.id),
      });
      return { deleted: was, result: await c.del(`/stock/items/${a.id}`, tool) };
    },
  },
  {
    name: 'product_movements',
    title: 'Skladová kniha zboží',
    description:
      'Pohyby jedné karty (příjmy, výdeje, převody, inventurní rozdíly) s běžnou bilancí '
      + 'po každém řádku. Odpověď na „proč máme skladem tolik kusů".',
    inputSchema: schema({
      id: int('ID zboží (skladové karty).'),
      warehouse_id: int('Jen pohyby na tomto skladu.'),
      from: date('Od data (RRRR-MM-DD).'),
      to: date('Do data (RRRR-MM-DD).'),
      ...WINDOW,
    }, ['id']),
    write: false,
    run: (c, a, tool) => c.get(`/stock/items/${a.id}/movements`, {
      warehouse_id: a.warehouse_id, from: a.from, to: a.to, limit: a.limit, offset: a.offset,
    }, tool),
  },

  // ──────────────────────────────────────────────────────────────────────────
  // E-shop — obsah karty zboží
  // ──────────────────────────────────────────────────────────────────────────
  {
    name: 'update_product_card',
    title: 'Upravit e-shopový obsah zboží',
    description:
      'Uloží e-shopovou část karty: výrobce, záruku, dodací lhůtu, hmotnost, publikaci do '
      + 'e-shopu, jazykové verze, kategorie, štítky, parametry a poplatky.\n\n'
      + 'Zapisují se JEN sekce, které pošleš — vynechaná sekce zůstane beze změny. Uvnitř '
      + 'poslané sekce jde ale o úplnou náhradu: `tag_ids: [3]` znamená, že zboží bude mít '
      + 'jediný štítek, ostatní se odeberou. Před úpravou si proto načti `get_product` '
      + 'a pošli současný obsah i s doplněnou změnou.\n\n'
      + 'Číselníky pro vazby: `list_manufacturers`, `list_categories`, `list_product_tags`, '
      + '`list_product_attributes`, `list_fee_types`.',
    inputSchema: schema({
      id: int('ID zboží (skladové karty).'),
      manufacturer_id: int('ID výrobce. `null` výrobce odebere.', { type: ['integer', 'null'] }),
      warranty_months: int('Záruka v měsících.', { minimum: 0 }),
      delivery_days: int('Obvyklá dodací lhůta ve dnech.', { minimum: 0 }),
      weight_g: int('Hmotnost v gramech (pro dopravu).', { minimum: 0 }),
      export_eshop: bool('Publikovat zboží do e-shopového exportu.'),
      is_stocked: bool('Zboží se drží skladem (jinak se objednává na zakázku).'),
      pricing_base: str(
        'Základ pro výpočet prodejní ceny: `weighted_avg` = vážený průměr skladu, '
        + '`last_purchase` = poslední nákupní cena, `manual` = ruční. Změna spustí přepočet cen.',
        { enum: ['weighted_avg', 'last_purchase', 'manual'] },
      ),
      i18n: PRODUCT_I18N_ROWS,
      categories: arrayOf(
        'Kompletní zařazení do kategorií. Kategorie, která tu není, se odebere. '
        + 'Nejvýš jedna smí mít `is_primary: true`.',
        {
          category_id: int('ID kategorie.'),
          is_primary: bool('Hlavní kategorie zboží.'),
          display_order: int('Pořadí.'),
        },
        ['category_id'],
      ),
      tag_ids: {
        type: 'array',
        items: { type: 'integer' },
        description: 'Kompletní seznam ID štítků. Štítek, který tu není, se odebere.',
      },
      attributes: arrayOf(
        'Kompletní sada hodnot parametrů. Parametr, který tu není, se odebere. '
        + 'Hodnotu vyplň podle typu parametru: `value_text` (text), `value_num` (číslo), '
        + '`value_bool` (ano/ne), `option_id` (výběr z hodnot — viz `list_attribute_options`).',
        {
          attribute_id: int('ID parametru.'),
          value_text: str('Hodnota u parametru typu `text`.'),
          value_num: num('Hodnota u parametru typu `number`.'),
          value_bool: bool('Hodnota u parametru typu `bool`.'),
          option_id: int('ID zvolené hodnoty u parametru typu `enum`.'),
          display_order: int('Pořadí.'),
        },
        ['attribute_id'],
      ),
      fees: arrayOf(
        'Kompletní sada poplatků zboží (autorský, recyklační, WEEE). Poplatek, který tu není, se odebere.',
        {
          fee_type_id: int('ID typu poplatku.'),
          amount: num('Částka poplatku.'),
          currency_code: str('Měna (ISO 4217).', { minLength: 3, maxLength: 3 }),
          vat_included: bool('Částka je včetně DPH.'),
        },
        ['fee_type_id', 'amount'],
      ),
    }, ['id']),
    write: true,
    run: (c, a, tool) => c.put(`/eshop/products/${a.id}`, changed(a, [
      'manufacturer_id', 'warranty_months', 'delivery_days', 'weight_g',
      'export_eshop', 'is_stocked', 'pricing_base',
      'i18n', 'categories', 'tag_ids', 'attributes', 'fees',
    ]), tool),
  },
  {
    name: 'get_product_i18n',
    title: 'Jazykové verze zboží',
    description:
      'Překlady karty zboží po jazycích — název, popisy a SEO. Načti je před úpravou '
      + 'textů, protože `update_product_card` sekci `i18n` nahrazuje celou.',
    inputSchema: schema({ id: int('ID zboží (skladové karty).') }, ['id']),
    write: false,
    run: (c, a, tool) => c.get(`/eshop/products/${a.id}/i18n`, null, tool),
  },

  // ──────────────────────────────────────────────────────────────────────────
  // E-shop — ceny a dodavatelé
  // ──────────────────────────────────────────────────────────────────────────
  {
    name: 'get_product_prices',
    title: 'Cenotvorba zboží',
    description:
      'Prodejní ceny zboží po měnách — režim (přirážka / pevná cena), procento přirážky, '
      + 'zaokrouhlení a spočítaná cena.',
    inputSchema: schema({ id: int('ID zboží.') }, ['id']),
    write: false,
    run: (c, a, tool) => c.get(`/eshop/products/${a.id}/prices`, null, tool),
  },
  {
    name: 'set_product_prices',
    title: 'Změnit ceny zboží',
    description:
      'Uloží cenotvorbu zboží a hned ji přepočítá. MĚNÍ PRODEJNÍ CENY V OSTRÉM E-SHOPU.\n\n'
      + 'Seznam je ÚPLNÝ: měna, která v něm chybí, se ze zboží smaže. Vždycky si tedy '
      + 'nejdřív načti `get_product_prices`, uprav jen dotčený řádek a pošli zpátky '
      + 'všechny měny. Změnu si nech potvrdit uživatelem.',
    inputSchema: schema({
      id: int('ID zboží.'),
      prices: PRICE_ROWS,
    }, ['id', 'prices']),
    write: true,
    run: (c, a, tool) => c.put(`/eshop/products/${a.id}/prices`, { prices: a.prices }, tool),
  },
  {
    name: 'recompute_product_prices',
    title: 'Přepočítat ceny zboží',
    description:
      'Vynutí přepočet prodejních cen z aktuální pořizovací ceny a nastavené přirážky. '
      + 'Nemění pravidla cenotvorby, jen dopočítá výsledek — hodí se po naskladnění za '
      + 'jinou nákupní cenu. Ceny s ručním přepisem (`is_manual_override`) zůstanou.',
    inputSchema: schema({ id: int('ID zboží.') }, ['id']),
    write: true,
    run: (c, a, tool) => c.post(`/eshop/products/${a.id}/prices/recompute`, {}, tool),
  },
  {
    name: 'get_product_vendors',
    title: 'Dodavatelé zboží',
    description:
      'Dodavatelé jednoho zboží s nákupní cenou, kódem u dodavatele, dodací lhůtou '
      + 'a jejich hlášeným stavem skladem.',
    inputSchema: schema({ id: int('ID zboží.') }, ['id']),
    write: false,
    run: (c, a, tool) => c.get(`/eshop/products/${a.id}/vendors`, null, tool),
  },
  {
    name: 'set_product_vendors',
    title: 'Změnit dodavatele zboží',
    description:
      'Uloží seznam dodavatelů zboží. Seznam je ÚPLNÝ — dodavatel, který v něm chybí, '
      + 'se ze zboží odebere; načti proto nejdřív `get_product_vendors`.\n\n'
      + 'Každý `client_id` musí být karta s příznakem „je dodavatel" (`search_clients` '
      + 's `role: "vendors"`), jinak server vrátí 422. Hlavní dodavatel smí být nejvýš '
      + 'jeden. Při cenotvorbě z nákupní ceny se po zápisu přepočtou prodejní ceny.',
    inputSchema: schema({
      id: int('ID zboží.'),
      vendors: VENDOR_ROWS,
    }, ['id', 'vendors']),
    write: true,
    run: (c, a, tool) => c.put(`/eshop/products/${a.id}/vendors`, { vendors: a.vendors }, tool),
  },

  // ──────────────────────────────────────────────────────────────────────────
  // E-shop — média zboží
  //
  // Nahrání souboru tady není: API ho bere jako multipart upload a tenhle
  // server umí posílat jen JSON. Fotky se nahrávají v aplikaci, agent s nimi
  // pak může pracovat (popisky, pořadí, hlavní obrázek, smazání).
  // ──────────────────────────────────────────────────────────────────────────
  {
    name: 'list_product_media',
    title: 'Média zboží',
    description:
      'Obrázky a přílohy karty zboží s jejich id, pořadím a příznakem hlavního obrázku.',
    inputSchema: schema({ id: int('ID zboží.') }, ['id']),
    write: false,
    run: (c, a, tool) => c.get(`/eshop/products/${a.id}/media`, null, tool),
  },
  {
    name: 'update_product_media',
    title: 'Upravit médium zboží',
    description:
      'Změní popisky média (`title`, `alt_text`), pořadí, publikaci do e-shopu, nebo ho '
      + 'nastaví jako hlavní obrázek. Hlavní obrázek je vždy jen jeden — nastavením '
      + '`is_primary: true` se ten předchozí odznačí. Id média zjistíš z `list_product_media`.',
    inputSchema: schema({
      media_id: int('ID média (z `list_product_media`).'),
      title: str('Titulek.'),
      alt_text: str('Alternativní text pro čtečky a vyhledávače.'),
      display_order: int('Pořadí v galerii.'),
      export_eshop: bool('Publikovat do e-shopu.'),
      is_primary: bool('Nastavit jako hlavní obrázek karty.'),
    }, ['media_id']),
    write: true,
    run: (c, a, tool) => c.put(`/eshop/media/${a.media_id}`, changed(a, [
      'title', 'alt_text', 'display_order', 'export_eshop', 'is_primary',
    ]), tool),
  },
  {
    name: 'reorder_product_media',
    title: 'Přeuspořádat média zboží',
    description:
      'Nastaví pořadí médií karty. Pošli id médií v pořadí, v jakém se mají zobrazovat; '
      + 'id, která ke kartě nepatří, server ignoruje.',
    inputSchema: schema({
      id: int('ID zboží.'),
      order: {
        type: 'array',
        items: { type: 'integer' },
        description: 'ID médií v cílovém pořadí.',
        minItems: 1,
      },
    }, ['id', 'order']),
    write: true,
    run: (c, a, tool) => c.put(`/eshop/products/${a.id}/media/reorder`, { order: a.order }, tool),
  },
  {
    name: 'delete_product_media',
    title: 'Smazat médium zboží',
    description:
      'Odebere obrázek nebo přílohu z karty zboží. Nevratné — soubor se z aplikace '
      + 'nedá nahrát zpět přes API, jen ručně.',
    inputSchema: schema({
      id: int('ID zboží — kontroluje se, že médium opravdu patří téhle kartě.'),
      media_id: int('ID média (z `list_product_media`).'),
      confirm: CONFIRM,
    }, ['id', 'media_id']),
    write: true,
    destructive: true,
    run: async (c, a, tool) => {
      // Médium se maže globální cestou /eshop/media/{mid}, takže příslušnost ke
      // kartě ověřujeme sami — jinak by překlep v id smazal fotku cizímu zboží.
      const media = rows(await c.get(`/eshop/products/${a.id}/media`, null, tool));
      const hit = media.find((m) => Number(m?.id) === Number(a.media_id));
      if (!hit) {
        throw new Error(
          `Zboží #${a.id} nemá médium #${a.media_id}. Dostupná média: `
          + (media.map((m) => `#${m.id} ${m.title ?? m.file_name ?? ''}`).join(', ') || 'žádná') + '.',
        );
      }
      requireConfirm(a, 'Smazat se má médium zboží', `#${hit.id} ${hit.title ?? hit.file_name ?? ''}`);
      return { deleted: hit, result: await c.del(`/eshop/media/${a.media_id}`, tool) };
    },
  },

  // ──────────────────────────────────────────────────────────────────────────
  // E-shop — kategorie
  // ──────────────────────────────────────────────────────────────────────────
  {
    name: 'list_categories',
    title: 'Kategorie e-shopu',
    description:
      'Celý strom kategorií zboží. Zanoření je v `path` a `depth`, takže se z plochého '
      + 'seznamu dá strom složit bez dalších dotazů.',
    inputSchema: schema(),
    write: false,
    run: (c, _a, tool) => c.get('/eshop/categories', null, tool),
  },
  {
    name: 'get_category',
    title: 'Detail kategorie',
    description: 'Jedna kategorie včetně jazykových verzí.',
    inputSchema: schema({ id: int('ID kategorie.') }, ['id']),
    write: false,
    run: (c, a, tool) => c.get(`/eshop/categories/${a.id}`, null, tool),
  },
  {
    name: 'create_category',
    title: 'Založit kategorii',
    description:
      'Vytvoří kategorii pod zadaným rodičem, nebo v kořeni (bez `parent_id`). '
      + 'Zanoření se dopočítá samo. Kód musí být v rámci firmy unikátní (jinak 409).',
    inputSchema: schema({
      code: str('Unikátní kód kategorie.', { maxLength: 50 }),
      name: str('Název kategorie.', { maxLength: 150 }),
      parent_id: int('ID nadřazené kategorie. Bez zadání vznikne v kořeni.'),
      display_order: int('Pořadí mezi sourozenci.'),
      export_eshop: bool('Publikovat do e-shopu (výchozí ano).'),
      archived: bool('Založit rovnou jako archivovanou.'),
    }, ['code', 'name']),
    write: true,
    run: (c, a, tool) => c.post('/eshop/categories', changed(a, [
      'code', 'name', 'parent_id', 'display_order', 'export_eshop', 'archived',
    ]), tool),
  },
  {
    name: 'update_category',
    title: 'Upravit kategorii',
    description:
      'Změní kód, název, pořadí, publikaci nebo archivaci kategorie. Uveď jen to, co se '
      + 'má změnit. Přeřazení pod jiného rodiče tímhle nástrojem NEJDE — na to je '
      + '`move_category`.',
    inputSchema: schema({
      id: int('ID kategorie.'),
      code: str('Kód kategorie.', { maxLength: 50 }),
      name: str('Název kategorie.', { maxLength: 150 }),
      display_order: int('Pořadí mezi sourozenci.'),
      export_eshop: bool('Publikovat do e-shopu.'),
      archived: bool('Archivovaná kategorie — zůstane v datech, ale nenabízí se.'),
    }, ['id']),
    write: true,
    run: (c, a, tool) => c.put(`/eshop/categories/${a.id}`, changed(a, [
      'code', 'name', 'display_order', 'export_eshop', 'archived',
    ]), tool),
  },
  {
    name: 'move_category',
    title: 'Přesunout kategorii',
    description:
      'Přeřadí kategorii pod jiného rodiče i s celým jejím podstromem. Bez `parent_id` '
      + '(nebo s `null`) se kategorie přesune do kořene. Přesun do vlastního podstromu '
      + 'server odmítne (422) — vznikl by cyklus.',
    inputSchema: schema({
      id: int('ID přesouvané kategorie.'),
      parent_id: int('ID nového rodiče. Vynech (nebo `null`) pro přesun do kořene.', { type: ['integer', 'null'] }),
    }, ['id']),
    write: true,
    run: (c, a, tool) => c.post(`/eshop/categories/${a.id}/move`, {
      parent_id: a.parent_id ?? null,
    }, tool),
  },
  {
    name: 'delete_category',
    title: 'Smazat kategorii',
    description:
      'Smaže kategorii. Zvaž místo toho archivaci (`update_category` s `archived: true`) — '
      + 'archivovaná kategorie zmizí z nabídky, ale zůstane u historických dat.',
    inputSchema: schema({ id: int('ID kategorie.'), confirm: CONFIRM }, ['id']),
    write: true,
    destructive: true,
    run: async (c, a, tool) => {
      const was = await confirmed(c, a, tool, {
        path: `/eshop/categories/${a.id}`,
        action: 'Smazat se má kategorie',
        label: (row) => nameOf(row, a.id),
      });
      return { deleted: was, result: await c.del(`/eshop/categories/${a.id}`, tool) };
    },
  },
  {
    name: 'get_category_i18n',
    title: 'Jazykové verze kategorie',
    description: 'Překlady názvu, popisu a URL kategorie po jazycích.',
    inputSchema: schema({ id: int('ID kategorie.') }, ['id']),
    write: false,
    run: (c, a, tool) => c.get(`/eshop/categories/${a.id}/i18n`, null, tool),
  },
  {
    name: 'set_category_i18n',
    title: 'Uložit jazykové verze kategorie',
    description:
      'Uloží překlady kategorie. Seznam je ÚPLNÝ — jazyk, který v něm chybí, se smaže; '
      + 'načti proto nejdřív `get_category_i18n`. Řádky bez `locale` nebo `name` server přeskočí.',
    inputSchema: schema({
      id: int('ID kategorie.'),
      translations: arrayOf(
        'Kompletní sada jazykových verzí kategorie.',
        {
          locale: str('Kód jazyka, max 5 znaků — např. cs, en, de.', { maxLength: 5 }),
          name: str('Název kategorie v tomto jazyce.'),
          description: str('Popis kategorie.'),
          seo_slug: str('Část URL.'),
        },
        ['locale', 'name'],
      ),
    }, ['id', 'translations']),
    write: true,
    run: (c, a, tool) => c.put(`/eshop/categories/${a.id}/i18n`, {
      translations: a.translations,
    }, tool),
  },

  // ──────────────────────────────────────────────────────────────────────────
  // E-shop — číselníky (výrobci, štítky, poplatky, parametry)
  // ──────────────────────────────────────────────────────────────────────────
  ...codebookTools({
    path: '/eshop/manufacturers',
    names: {
      list: 'list_manufacturers',
      get: 'get_manufacturer',
      create: 'create_manufacturer',
      update: 'update_manufacturer',
      delete: 'delete_manufacturer',
    },
    titles: {
      list: 'Výrobci / značky',
      get: 'Detail výrobce',
      create: 'Založit výrobce',
      update: 'Upravit výrobce',
      delete: 'Smazat výrobce',
    },
    descriptions: {
      list: 'Číselník výrobců a značek e-shopu. `id` se dosazuje do `manufacturer_id` v `update_product_card`.',
      get: 'Jeden výrobce i s webem a příznakem publikace.',
      create: 'Založí výrobce/značku. Kód musí být v rámci firmy unikátní (jinak 409).',
      update: 'Změní údaje výrobce. Uveď jen to, co se má změnit.',
      delete: 'Smaže výrobce. Server to odmítne (409), pokud je výrobce použitý u zboží — '
        + 'v tom případě ho archivuj (`update_manufacturer` s `archived: true`).',
      deleteAction: 'Smazat se má výrobce',
    },
    fields: {
      code: str('Unikátní kód.', { maxLength: 50 }),
      name: str('Název výrobce nebo značky.', { maxLength: 150 }),
      website: str('Web výrobce.'),
      display_order: int('Pořadí v nabídce.'),
      export_eshop: bool('Publikovat do e-shopu.'),
      archived: bool('Archivovaný — zůstane u historických dat, ale nenabízí se.'),
    },
    required: ['code', 'name'],
  }),
  ...codebookTools({
    path: '/eshop/tags',
    names: {
      list: 'list_product_tags',
      get: 'get_product_tag',
      create: 'create_product_tag',
      update: 'update_product_tag',
      delete: 'delete_product_tag',
    },
    titles: {
      list: 'Štítky zboží',
      get: 'Detail štítku',
      create: 'Založit štítek',
      update: 'Upravit štítek',
      delete: 'Smazat štítek',
    },
    descriptions: {
      list: 'Číselník štítků zboží (novinka, výprodej, doporučujeme). `id` patří do `tag_ids` v `update_product_card`.',
      get: 'Jeden štítek i s barvou.',
      create: 'Založí štítek zboží. Kód musí být v rámci firmy unikátní (jinak 409).',
      update: 'Změní název, kód, barvu nebo archivaci štítku. Uveď jen to, co se má změnit.',
      delete: 'Smaže štítek a odebere ho ze všech zboží, kde je nasazený. '
        + 'Zvaž místo toho archivaci (`update_product_tag` s `archived: true`).',
      deleteAction: 'Smazat se má štítek zboží',
    },
    fields: {
      code: str('Unikátní kód.', { maxLength: 50 }),
      name: str('Název štítku.', { maxLength: 100 }),
      color: str('Barva ve tvaru #RRGGBB.', { pattern: '^#[0-9a-fA-F]{6}$' }),
      archived: bool('Archivovaný štítek.'),
    },
    required: ['code', 'name'],
  }),
  ...codebookTools({
    path: '/eshop/fee-types',
    names: {
      list: 'list_fee_types',
      get: 'get_fee_type',
      create: 'create_fee_type',
      update: 'update_fee_type',
      delete: 'delete_fee_type',
    },
    titles: {
      list: 'Typy poplatků',
      get: 'Detail poplatku',
      create: 'Založit typ poplatku',
      update: 'Upravit typ poplatku',
      delete: 'Smazat typ poplatku',
    },
    descriptions: {
      list: 'Číselník poplatků ke zboží — autorský, recyklační, WEEE. Konkrétní částky se '
        + 'nastavují u zboží v sekci `fees` nástroje `update_product_card`.',
      get: 'Jeden typ poplatku i s navázanou sazbou DPH.',
      create: 'Založí typ poplatku. Kód musí být v rámci firmy unikátní (jinak 409).',
      update: 'Změní název, kód, sazbu DPH nebo archivaci poplatku. Uveď jen to, co se má změnit.',
      delete: 'Smaže typ poplatku. Server to odmítne (409), pokud je poplatek použitý u zboží — '
        + 'v tom případě ho archivuj (`update_fee_type` s `archived: true`).',
      deleteAction: 'Smazat se má typ poplatku',
    },
    fields: {
      code: str('Unikátní kód.', { maxLength: 30 }),
      name: str('Název poplatku.', { maxLength: 120 }),
      vat_rate_id: int('ID sazby DPH poplatku — číselník vrací `list_vat_rates`.'),
      archived: bool('Archivovaný poplatek.'),
    },
    required: ['code', 'name'],
  }),
  ...codebookTools({
    path: '/eshop/attributes',
    names: {
      list: 'list_product_attributes',
      get: 'get_product_attribute',
      create: 'create_product_attribute',
      update: 'update_product_attribute',
      delete: 'delete_product_attribute',
    },
    titles: {
      list: 'Parametry zboží',
      get: 'Detail parametru',
      create: 'Založit parametr',
      update: 'Upravit parametr',
      delete: 'Smazat parametr',
    },
    descriptions: {
      list: 'Číselník parametrů zboží (barva, velikost, výkon). U parametrů typu `enum` '
        + 'je v odpovědi i výčet hodnot. Hodnoty konkrétního zboží se nastavují v sekci '
        + '`attributes` nástroje `update_product_card`.',
      get: 'Jeden parametr včetně výčtu hodnot, jde-li o typ `enum`.',
      create: 'Založí parametr zboží. `data_type` určuje, čím se pak vyplňuje hodnota u zboží. '
        + 'Kód musí být v rámci firmy unikátní (jinak 409).',
      update: 'Změní parametr. Uveď jen to, co se má změnit. Změnu `data_type` u parametru, '
        + 'který už je u zboží vyplněný, si rozmysli — dosavadní hodnoty typu neodpovídají.',
      delete: 'Smaže parametr. Server to odmítne (409), pokud je parametr použitý u zboží — '
        + 'v tom případě ho archivuj (`update_product_attribute` s `archived: true`).',
      deleteAction: 'Smazat se má parametr zboží',
    },
    fields: {
      code: str('Unikátní kód.', { maxLength: 50 }),
      name: str('Název parametru.', { maxLength: 120 }),
      data_type: str(
        'Typ hodnoty: `text`, `number`, `bool`, nebo `enum` (výběr z připravených hodnot). Výchozí `text`.',
        { enum: ['text', 'number', 'bool', 'enum'] },
      ),
      unit: str('Jednotka hodnoty (mm, W, kg).'),
      is_filterable: bool('Podle parametru se dá v e-shopu filtrovat.'),
      is_multivalue: bool('Zboží může mít víc hodnot tohoto parametru zároveň.'),
      display_order: int('Pořadí v kartě zboží.'),
      archived: bool('Archivovaný parametr.'),
    },
    required: ['code', 'name'],
  }),
  {
    name: 'list_attribute_options',
    title: 'Hodnoty parametru',
    description:
      'Výčet hodnot parametru typu `enum` (např. u parametru „Barva" hodnoty červená, modrá). '
      + '`id` hodnoty se dosazuje do `option_id` v sekci `attributes` nástroje `update_product_card`.',
    inputSchema: schema({ attribute_id: int('ID parametru (musí být typu `enum`).') }, ['attribute_id']),
    write: false,
    run: (c, a, tool) => c.get(`/eshop/attributes/${a.attribute_id}/options`, null, tool),
  },
  {
    name: 'create_attribute_option',
    title: 'Přidat hodnotu parametru',
    description: 'Přidá další volitelnou hodnotu k parametru typu `enum`.',
    inputSchema: schema({
      attribute_id: int('ID parametru (musí být typu `enum`).'),
      code: str('Unikátní kód hodnoty.', { maxLength: 50 }),
      label: str('Zobrazovaný text hodnoty.', { maxLength: 120 }),
      display_order: int('Pořadí v nabídce.'),
    }, ['attribute_id', 'code', 'label']),
    write: true,
    run: (c, a, tool) => c.post(`/eshop/attributes/${a.attribute_id}/options`, changed(a, [
      'code', 'label', 'display_order',
    ]), tool),
  },
  {
    name: 'update_attribute_option',
    title: 'Upravit hodnotu parametru',
    description:
      'Přejmenuje hodnotu parametru. Na rozdíl od ostatních úprav vyžaduje server '
      + 'kód i text zároveň — pošli obojí, i když měníš jen jedno (současné hodnoty '
      + 'najdeš v `list_attribute_options`).',
    inputSchema: schema({
      option_id: int('ID hodnoty (z `list_attribute_options`).'),
      code: str('Kód hodnoty.', { maxLength: 50 }),
      label: str('Zobrazovaný text hodnoty.', { maxLength: 120 }),
      display_order: int('Pořadí v nabídce.'),
    }, ['option_id', 'code', 'label']),
    write: true,
    run: (c, a, tool) => c.put(`/eshop/attribute-options/${a.option_id}`, changed(a, [
      'code', 'label', 'display_order',
    ]), tool),
  },
  {
    name: 'delete_attribute_option',
    title: 'Smazat hodnotu parametru',
    description:
      'Odebere jednu volitelnou hodnotu parametru typu `enum`. Zboží, které tuhle hodnotu '
      + 'mělo nastavenou, o ni přijde.',
    inputSchema: schema({
      attribute_id: int('ID parametru — kontroluje se, že hodnota patří opravdu k němu.'),
      option_id: int('ID hodnoty (z `list_attribute_options`).'),
      confirm: CONFIRM,
    }, ['attribute_id', 'option_id']),
    write: true,
    destructive: true,
    run: async (c, a, tool) => {
      // Mazací cesta je globální (/eshop/attribute-options/{oid}), takže si
      // příslušnost k parametru ověříme sami — jinak by překlep v id smazal
      // hodnotu úplně jinému parametru.
      const options = rows(await c.get(`/eshop/attributes/${a.attribute_id}/options`, null, tool));
      const hit = options.find((o) => Number(o?.id) === Number(a.option_id));
      if (!hit) {
        throw new Error(
          `Parametr #${a.attribute_id} nemá hodnotu #${a.option_id}. Dostupné hodnoty: `
          + (options.map((o) => `#${o.id} ${o.label ?? o.code ?? ''}`).join(', ') || 'žádné') + '.',
        );
      }
      requireConfirm(a, 'Smazat se má hodnota parametru', `#${hit.id} ${hit.label ?? hit.code ?? ''}`);
      return { deleted: hit, result: await c.del(`/eshop/attribute-options/${a.option_id}`, tool) };
    },
  },

  // ──────────────────────────────────────────────────────────────────────────
  // Sklad — sklady
  // ──────────────────────────────────────────────────────────────────────────
  {
    name: 'list_warehouses',
    title: 'Seznam skladů',
    description:
      'Sklady firmy s jejich ID a aktuální hodnotou zásob. ID se používá ve filtrech '
      + 'ostatních skladových nástrojů a na skladových dokladech.',
    inputSchema: schema({ active_only: bool('Jen aktivní sklady.') }),
    write: false,
    run: (c, a, tool) => c.get('/stock/warehouses', { active: a.active_only }, tool),
  },
  {
    name: 'get_warehouse',
    title: 'Detail skladu',
    description: 'Jeden sklad včetně aktuální hodnoty zásob.',
    inputSchema: schema({ id: int('ID skladu.') }, ['id']),
    write: false,
    run: (c, a, tool) => c.get(`/stock/warehouses/${a.id}`, null, tool),
  },
  {
    name: 'create_warehouse',
    title: 'Založit sklad',
    description:
      'Vytvoří nový sklad. Kód musí být v rámci firmy unikátní (jinak 409). '
      + '`is_default: true` z něj udělá výchozí sklad pro nové doklady.',
    inputSchema: schema({
      code: str('Unikátní kód skladu.', { maxLength: 20 }),
      name: str('Název skladu.', { maxLength: 100 }),
      is_default: bool('Výchozí sklad firmy.'),
      is_active: bool('Aktivní sklad (výchozí ano).'),
      note: str('Poznámka.'),
    }, ['code', 'name']),
    write: true,
    run: (c, a, tool) => c.post('/stock/warehouses', changed(a, [
      'code', 'name', 'is_default', 'is_active', 'note',
    ]), tool),
  },
  {
    name: 'update_warehouse',
    title: 'Upravit sklad',
    description: 'Změní kód, název, výchozí příznak, aktivitu nebo poznámku skladu. Uveď jen to, co se má změnit.',
    inputSchema: schema({
      id: int('ID skladu.'),
      code: str('Kód skladu.', { maxLength: 20 }),
      name: str('Název skladu.', { maxLength: 100 }),
      is_default: bool('Výchozí sklad firmy.'),
      is_active: bool('Aktivní sklad. `false` sklad skryje, ale historie zůstane — '
        + 'tohle je náhrada za mazání u skladu, kde se už hospodařilo.'),
      note: str('Poznámka.'),
    }, ['id']),
    write: true,
    run: (c, a, tool) => c.put(`/stock/warehouses/${a.id}`, changed(a, [
      'code', 'name', 'is_default', 'is_active', 'note',
    ]), tool),
  },
  {
    name: 'delete_warehouse',
    title: 'Smazat sklad',
    description:
      'Smaže sklad. Server to dovolí jen u skladu s nulovým stavem a bez jediného pohybu '
      + '(jinak 409) — jindy sklad jen deaktivuj přes `update_warehouse` s `is_active: false`.',
    inputSchema: schema({ id: int('ID skladu.'), confirm: CONFIRM }, ['id']),
    write: true,
    destructive: true,
    run: async (c, a, tool) => {
      const was = await confirmed(c, a, tool, {
        path: `/stock/warehouses/${a.id}`,
        action: 'Smazat se má sklad',
        label: (row) => nameOf(row, a.id),
      });
      return { deleted: was, result: await c.del(`/stock/warehouses/${a.id}`, tool) };
    },
  },

  // ──────────────────────────────────────────────────────────────────────────
  // Sklad — zásoby a sestavy
  // ──────────────────────────────────────────────────────────────────────────
  {
    name: 'stock_levels',
    title: 'Stav zásob',
    description:
      'Aktuální množství po dvojicích karta × sklad, s filtrem na sklad, typ karty '
      + 'a podlimitní zásobu.',
    inputSchema: schema({
      query: str('Fulltext přes název a kód.'),
      warehouse_id: int('Jen tento sklad.'),
      item_type: str('Typ karty.', { enum: ['goods', 'material', 'product'] }),
      below_min: bool('Jen položky pod minimální zásobou.'),
      active: bool('Jen aktivní (true) nebo jen neaktivní (false) karty.'),
    }),
    write: false,
    run: (c, a, tool) => c.get('/stock/levels', {
      q: a.query,
      warehouse_id: a.warehouse_id,
      item_type: a.item_type,
      below_min: a.below_min,
      active: a.active === undefined ? undefined : (a.active ? 1 : 0),
    }, tool),
  },
  {
    name: 'stock_availability',
    title: 'Dostupnost konkrétního zboží',
    description:
      'Dostupné množství pro vyjmenované karty — na rozdíl od `stock_levels` počítá '
      + 'i s rezervacemi. Pro dotazy „můžeme to hned expedovat?".',
    inputSchema: schema({
      item_ids: {
        type: 'array',
        items: { type: 'integer' },
        description: 'ID skladových karet.',
        minItems: 1,
      },
      warehouse_id: int('Jen tento sklad.'),
    }, ['item_ids']),
    write: false,
    run: (c, a, tool) => c.get('/stock/availability', {
      item_ids: a.item_ids, warehouse_id: a.warehouse_id,
    }, tool),
  },
  {
    name: 'stock_status_report',
    title: 'Sestava stavu zásob',
    description:
      'Sestava „stav zásob" — množství a hodnota po kartách, se stejnými filtry jako '
      + '`stock_levels`, ale v podobě sestavy včetně souhrnů.',
    inputSchema: schema({
      query: str('Fulltext přes název a kód.'),
      warehouse_id: int('Jen tento sklad.'),
      item_type: str('Typ karty.', { enum: ['goods', 'material', 'product'] }),
      below_min: bool('Jen položky pod minimální zásobou.'),
      active: bool('Jen aktivní (true) nebo jen neaktivní (false) karty.'),
    }),
    write: false,
    run: (c, a, tool) => c.get('/stock/reports/status', {
      q: a.query,
      warehouse_id: a.warehouse_id,
      item_type: a.item_type,
      below_min: a.below_min,
      active: a.active === undefined ? undefined : (a.active ? 1 : 0),
    }, tool),
  },
  {
    name: 'stock_valuation',
    title: 'Ocenění skladu',
    description:
      'Hodnota zásob k datu — pro otázky „kolik máme uloženo ve skladu". Ocenění '
      + 'ke zpětnému datu se rekonstruuje z pohybů.',
    inputSchema: schema({
      warehouse_id: int('Jen tento sklad.'),
      date: date('Ocenění k datu (RRRR-MM-DD). Výchozí dnes.'),
    }),
    write: false,
    run: (c, a, tool) => c.get('/stock/reports/valuation', {
      warehouse_id: a.warehouse_id, date: a.date,
    }, tool),
  },

  // ──────────────────────────────────────────────────────────────────────────
  // Sklad — u dodavatele (nabídky dodavatelů)
  //
  // Odpovídá na „kdo to má, za kolik a kolik kusů" JEŠTĚ NEŽ se cokoli objedná.
  // Katalogová karta nemusí mít jediný skladový pohyb — `on_hand` je pak 0, ne
  // chybějící údaj, takže nabídku lze evidovat i u zboží, které firma nikdy
  // nekupovala.
  //
  // Proti `set_product_vendors` (celá sada dodavatelů jedné karty, chybějící
  // řádek = smazání) je tohle pohled „řádek = nabídka": mění se jedna dvojice
  // zboží × dodavatel a zbytek zůstává, jak byl. Obojí píše do stejných dat.
  // ──────────────────────────────────────────────────────────────────────────
  {
    name: 'vendor_offers_list',
    title: 'Nabídky dodavatelů',
    description:
      'Dvojice zboží × dodavatel: nákupní cena a měna, kód u dodavatele, dodací lhůta, '
      + 'množství hlášené dodavatelem, dostupnost, minimální objednávka a balení. '
      + 'Přidává i `on_hand` = kolik má firma sama na skladě, takže se dá rovnou '
      + 'porovnat „naše zásoba vs. co má dodavatel".\n\n'
      + '`stock_qty_updated_at` říká, kdy hlášené množství naposled přišlo. Je to jen '
      + 'informace — množství platí, dokud ho dodavatel nezmění, nic po čase '
      + 'nevyprší.',
    inputSchema: schema({
      stock_item_id: int('Jen nabídky k tomuto zboží.'),
      client_id: int('Jen nabídky tohoto dodavatele (`search_clients` s `role: "vendors"`).'),
      availability_state: str('Filtr na dostupnost u dodavatele.', {
        enum: ['in_stock', 'on_order', 'unavailable', 'unknown'],
      }),
      active: bool('Jen aktivní (true) nebo jen vyřazené (false) nabídky.'),
      preferred: bool('Jen hlavní dodavatele.'),
      query: str('Fulltext přes SKU, název zboží, kód u dodavatele a název dodavatele.'),
      ...WINDOW,
    }),
    write: false,
    run: (c, a, tool) => c.get('/stock/vendor-offers', {
      stock_item_id: a.stock_item_id,
      client_id: a.client_id,
      availability_state: a.availability_state,
      active: a.active === undefined ? undefined : (a.active ? 1 : 0),
      preferred: a.preferred === undefined ? undefined : (a.preferred ? 1 : 0),
      q: a.query,
      limit: a.limit,
      offset: a.offset,
    }, tool),
  },
  {
    name: 'vendor_offer_upsert',
    title: 'Uložit nabídku dodavatele',
    description:
      'Založí nebo upraví nabídku pro dvojici zboží × dodavatel. Jestli už dvojice '
      + 'existuje, nástroj si zjistí sám — proto „upsert": stejné volání jde použít '
      + 'na první zadání i na pozdější aktualizaci ceníku.\n\n'
      + 'Mění se JEN předaná pole; co nepošleš, zůstane, jak bylo. Vynechané pole '
      + 'tedy neznamená „vynuluj" (to je rozdíl proti `set_product_vendors`, kde '
      + 'chybějící dodavatel ze zboží zmizí).\n\n'
      + '`client_id` musí být karta s příznakem „je dodavatel" (`search_clients` '
      + 's `role: "vendors"`), jinak server vrátí 422. `is_preferred: true` odznačí '
      + 'předchozího hlavního dodavatele téhož zboží — nejvýš jeden na kartu. '
      + 'Po zápisu se přepočítají prodejní ceny, pokud je cenotvorba karty vázaná '
      + 'na nákupní cenu.',
    inputSchema: schema({
      stock_item_id: int('ID zboží (skladové karty).'),
      client_id: int('ID dodavatele — karta odběratele s příznakem „je dodavatel".'),
      vendor_sku: str('Kód zboží u dodavatele.', { maxLength: 80 }),
      purchase_price: num('Nákupní cena bez DPH.', { minimum: 0 }),
      currency_code: str('Měna nákupní ceny (ISO 4217). Výchozí CZK.', { minLength: 3, maxLength: 3 }),
      delivery_days: int('Dodací lhůta ve dnech.', { minimum: 0, maximum: 65535 }),
      stock_qty: num('Množství, které dodavatel hlásí skladem.', { minimum: 0 }),
      availability_state: str('Co dodavatel hlásí o dostupnosti.', {
        enum: ['in_stock', 'on_order', 'unavailable', 'unknown'],
      }),
      min_order_qty: num('Minimální objednací množství.', { exclusiveMinimum: 0 }),
      package_qty: num('Velikost balení — objednávka se na ni zaokrouhluje nahoru.', { exclusiveMinimum: 0 }),
      price_valid_to: date('Do kdy platí ceníková cena (RRRR-MM-DD).'),
      data_source: str('Odkud hodnoty přišly.', { enum: ['manual', 'import', 'feed'] }),
      is_active: bool('Aktivní nabídka. `false` ji skryje, ale historie zůstane.'),
      is_preferred: bool('Hlavní dodavatel zboží. Nejvýš jeden — ostatní se odznačí.'),
      note: str('Poznámka.', { maxLength: 255 }),
    }, ['stock_item_id', 'client_id']),
    write: true,
    run: async (c, a, tool) => {
      const fields = [
        'vendor_sku', 'purchase_price', 'currency_code', 'delivery_days', 'stock_qty',
        'availability_state', 'min_order_qty', 'package_qty', 'price_valid_to',
        'data_source', 'is_active', 'is_preferred', 'note',
      ];
      const existing = await c.get('/stock/vendor-offers', {
        stock_item_id: a.stock_item_id, client_id: a.client_id, limit: 1,
      }, tool);
      const current = existing?.items?.[0] ?? null;
      if (current) {
        return c.patch(`/stock/vendor-offers/${current.id}`, changed(a, fields), tool);
      }
      return c.post('/stock/vendor-offers', {
        stock_item_id: a.stock_item_id,
        client_id: a.client_id,
        ...changed(a, fields),
      }, tool);
    },
  },
  {
    name: 'vendor_offer_delete',
    title: 'Smazat nabídku dodavatele',
    description:
      'Odebere nabídku dodavatele u zboží. Většinou stačí `vendor_offer_upsert` '
      + 's `is_active: false` — vyřazená nabídka se nenabízí, ale zůstane dohledatelné, '
      + 'za kolik se kdysi nakupovalo. Mazat má smysl u překlepu, ne u ukončené '
      + 'spolupráce.',
    inputSchema: schema({ id: int('ID nabídky (z `vendor_offers_list`).'), confirm: CONFIRM }, ['id']),
    write: true,
    destructive: true,
    run: async (c, a, tool) => {
      const was = await confirmed(c, a, tool, {
        path: `/stock/vendor-offers/${a.id}`,
        action: 'Smazat se má nabídka dodavatele',
        label: (row) => `${row?.sku ?? '?'} — ${row?.client_name ?? '?'}`,
      });
      return { deleted: was, result: await c.del(`/stock/vendor-offers/${a.id}`, tool) };
    },
  },

  // ──────────────────────────────────────────────────────────────────────────
  // Sklad — tři dimenze množství
  //
  // „Kolik toho máme" má tři různé odpovědi a plete se to i lidem, takže je
  // katalog nabízí pohromadě a pojmenovaně:
  //   skladem (on_hand)      — co fyzicky leží ve skladu,
  //   rezervováno (reserved) — kusy, které drží vystavená faktura bez výdejky,
  //   na cestě (in_transit)  — objednané u dodavatele, ještě nedorazilo,
  //   u dodavatele           — co hlásí dodavatel, že má on.
  //
  // Prodat se dá `sellable` = skladem − rezervováno. „Na cestě" se do prodejní
  // dostupnosti ZÁMĚRNĚ nepočítá (zboží u dopravce nelze zákazníkovi vydat),
  // vstupuje až do `available_to_promise`, což je číslo pro plánování nákupu.
  // ──────────────────────────────────────────────────────────────────────────
  {
    name: 'stock_quantities',
    title: 'Množství ve třech dimenzích',
    description:
      'Pro každé zboží vrátí `on_hand` (skladem), `reserved` (drží vystavená faktura), '
      + '`sellable` = skladem − rezervováno, `in_transit` (objednáno a na cestě), '
      + '`at_vendor` (co hlásí dodavatelé) a `available_to_promise` = skladem − '
      + 'rezervováno + na cestě. K tomu rozpad po skladech, seznam objednávek, '
      + 'které zboží vezou, a nabídky dodavatelů.\n\n'
      + 'Zákazníkovi slibuj `sellable`, ne `on_hand` — jinak prodáš kus, který už '
      + 'někdo koupil. `available_to_promise` je interní číslo pro plánování nákupu, '
      + 'protože zahrnuje i to, co teprve dorazí; může vyjít i záporně (prodalo se '
      + 'víc, než je skladem), a to je signál k urgentnímu doobjednání.\n\n'
      + 'Karta bez jediného pohybu vrátí samé nuly, ne chybu — zboží se dá mít '
      + 'v katalogu dřív, než se poprvé nakoupí.',
    inputSchema: schema({
      item_ids: arrayOf('ID zboží. Bez nich projde celý katalog (max 500 karet).', undefined, [], {
        items: { type: 'integer' },
      }),
      warehouse_id: int('Omezit na jeden sklad.'),
    }),
    write: false,
    run: (c, a, tool) => c.get('/stock/quantities', {
      item_ids: Array.isArray(a.item_ids) && a.item_ids.length > 0 ? a.item_ids.join(',') : undefined,
      warehouse_id: a.warehouse_id,
    }, tool),
  },
  {
    name: 'stock_in_transit',
    title: 'Zboží na cestě',
    description:
      'Kolik kusů je objednáno u dodavatelů a ještě nedorazilo, s rozpadem na konkrétní '
      + 'objednávky (číslo, dodavatel, očekávaný termín) a nejbližší očekávané datum.\n\n'
      + 'Počítá se od stavu, který má firma nastavený (výchozí „odeslaná objednávka"), '
      + 'a odečítá se z něj to, co už na příjemkách dorazilo. Stornovaná příjemka vrátí '
      + 'kusy zpátky na cestu.',
    inputSchema: schema({
      item_ids: arrayOf('ID zboží. Bez nich všechno, co je na cestě.', undefined, [], {
        items: { type: 'integer' },
      }),
      warehouse_id: int('Jen zboží mířící na tento sklad.'),
    }),
    write: false,
    run: (c, a, tool) => c.get('/stock/in-transit', {
      item_ids: Array.isArray(a.item_ids) && a.item_ids.length > 0 ? a.item_ids.join(',') : undefined,
      warehouse_id: a.warehouse_id,
    }, tool),
  },
  {
    name: 'stock_reservations',
    title: 'Rezervované zboží',
    description:
      'Kusy, které jsou fyzicky skladem, ale drží je vystavená faktura bez zaúčtované '
      + 'výdejky — s rozpadem na konkrétní faktury (číslo, odběratel, množství, datum), '
      + 'aby šlo dohledat, co který kus drží.\n\n'
      + 'Zálohová faktura nerezervuje (není závazek dodat), rozpracovaná a stornovaná '
      + 'taky ne. Firmy, kde výdejka vzniká rovnou při vystavení faktury, tu budou mít '
      + 'trvale nulu — u nich žádné okno mezi závazkem a výdejem není a je to správně.',
    inputSchema: schema({
      item_ids: arrayOf('ID zboží. Bez nich všechny rezervace.', undefined, [], {
        items: { type: 'integer' },
      }),
      warehouse_id: int('Jen rezervace na tomto skladu.'),
    }),
    write: false,
    run: (c, a, tool) => c.get('/stock/reservations', {
      item_ids: Array.isArray(a.item_ids) && a.item_ids.length > 0 ? a.item_ids.join(',') : undefined,
      warehouse_id: a.warehouse_id,
    }, tool),
  },
  {
    name: 'replenishment_suggest',
    title: 'Co objednat',
    description:
      'Zboží pod minimální zásobou i s návrhem, kolik ho doobjednat:\n\n'
      + '    návrh = minimum × koeficient − skladem + rezervováno − na cestě\n\n'
      + 'Odečtení „na cestě" je celý smysl téhle sestavy: bez něj by se navrhlo '
      + 'doobjednat zboží, které už je objednané, a koupilo by se dvakrát. Rezervace '
      + 'se naopak přičítá — ty kusy sice leží ve skladu, ale jsou fakticky pryč.\n\n'
      + 'Výsledek se zaokrouhlí nahoru na balení preferovaného dodavatele a podlahuje '
      + 'se jeho minimální objednávkou. Návrh můžeš poslat rovnou do '
      + '`purchase_orders_bulk_create`, který ho rozdělí po dodavatelích.',
    inputSchema: schema({
      warehouse_id: int('Počítat zásobu jen na tomto skladu.'),
      below_min: bool('Jen zboží, které je vážně pod minimem (ne to, co jen chybí do cíle).'),
      item_ids: arrayOf('Omezit na konkrétní zboží.', undefined, [], { items: { type: 'integer' } }),
      coefficient: num('Kolikanásobek minima doplňovat. Výchozí 1.', { exclusiveMinimum: 0 }),
      ...WINDOW,
    }),
    write: false,
    run: (c, a, tool) => c.get('/stock/replenishment', {
      warehouse_id: a.warehouse_id,
      below_min: a.below_min === undefined ? undefined : (a.below_min ? 1 : 0),
      item_ids: Array.isArray(a.item_ids) && a.item_ids.length > 0 ? a.item_ids.join(',') : undefined,
      coefficient: a.coefficient,
      limit: a.limit,
      offset: a.offset,
    }, tool),
  },

  // ──────────────────────────────────────────────────────────────────────────
  // Sklad — objednávky dodavatelům
  //
  // Objednávka NENÍ účetní doklad: nepřešlo vlastnictví, nevznikl závazek,
  // nic se neúčtuje. Proto je celá agenda zapisovatelná — chyba se opraví
  // v aplikaci a nemá daňovou dohru.
  //
  // Stejně jako u skladových dokladů má dvě fáze: `draft` se dá libovolně
  // měnit i smazat a do „na cestě" nevstupuje, teprve `purchase_orders_send`
  // přidělí číslo OBJ a zboží se začne počítat jako objednané. Nástroje ty
  // fáze schválně nespojují.
  // ──────────────────────────────────────────────────────────────────────────
  {
    name: 'purchase_orders_list',
    title: 'Objednávky dodavatelům',
    description:
      'Objednávky vydané dodavatelům s dodavatelem, skladem, termínem, stavem '
      + 'a plněním (objednáno / přijato / zbývá).\n\n'
      + 'Stavy: `draft` (rozpracovaná, nepočítá se na cestě), `sent` (odeslaná), '
      + '`confirmed` (dodavatel potvrdil), `partially_received` (část dorazila), '
      + '`received` (vše dorazilo), `closed` (zbytek už nepřijde), `cancelled`. '
      + 'Pro „co ještě čekáme" použij `open: true`.',
    inputSchema: schema({
      state: str('Jen objednávky v tomto stavu.', {
        enum: ['draft', 'sent', 'confirmed', 'partially_received', 'received', 'closed', 'cancelled'],
      }),
      open: bool('Jen objednávky, ze kterých ještě něco může dorazit.'),
      vendor_id: int('Jen objednávky tomuto dodavateli.'),
      warehouse_id: int('Jen objednávky na tento sklad.'),
      stock_item_id: int('Jen objednávky obsahující tohle zboží.'),
      from: date('Objednávky od tohoto data.'),
      to: date('Objednávky do tohoto data.'),
      expected_to: date('Jen objednávky s očekávaným dodáním do tohoto data.'),
      query: str('Fulltext přes číslo objednávky, referenci dodavatele a název dodavatele.'),
      ...WINDOW,
    }),
    write: false,
    run: (c, a, tool) => c.get('/stock/purchase-orders', {
      state: a.state,
      open: a.open === undefined ? undefined : (a.open ? 1 : 0),
      vendor_id: a.vendor_id,
      warehouse_id: a.warehouse_id,
      stock_item_id: a.stock_item_id,
      from: a.from,
      to: a.to,
      expected_to: a.expected_to,
      q: a.query,
      limit: a.limit,
      offset: a.offset,
    }, tool),
  },
  {
    name: 'purchase_orders_get',
    title: 'Detail objednávky',
    description:
      'Jedna objednávka včetně řádků a plnění. U každého řádku je `qty_ordered` '
      + '(objednáno), `qty_confirmed` (co potvrdil dodavatel — má přednost), '
      + '`qty_cancelled` (uzavřený nedodaný zbytek), `qty_received` (skutečně přijato '
      + 'podle příjemek) a `qty_remaining` (kolik ještě čekáme). Navíc napárované '
      + 'přijaté faktury a vystavené příjemky.',
    inputSchema: schema({ id: int('ID objednávky.') }, ['id']),
    write: false,
    run: (c, a, tool) => c.get(`/stock/purchase-orders/${a.id}`, null, tool),
  },
  {
    name: 'purchase_orders_create',
    title: 'Založit objednávku',
    description:
      'Vytvoří objednávku jako ROZPRACOVANOU (draft): číslo zatím nedostane a do '
      + '„na cestě" se nepočítá. Odešli ji až `purchase_orders_send`.\n\n'
      + 'Sklad z hlavičky platí pro všechny řádky, jednotlivý řádek ho může přebít. '
      + 'Řádek bez `stock_item_id` je služba (doprava, balné) — započítá se do ceny, '
      + 'ale ne do skladového plnění.',
    inputSchema: schema({
      vendor_id: int('ID dodavatele (`search_clients` s `role: "vendors"`).'),
      order_date: date('Datum objednávky.'),
      warehouse_id: int('Sklad, kam se má dodat.'),
      currency_id: int('Měna objednávky (`list_currencies`).'),
      expected_date: date('Požadovaný termín dodání.'),
      exchange_rate: num('Orientační kurz. Skutečné ocenění určí až příjemka nebo faktura.', { exclusiveMinimum: 0 }),
      vendor_reference: str('Číslo objednávky u dodavatele.', { maxLength: 50 }),
      note: str('Poznámka pro dodavatele (tiskne se na objednávku).'),
      internal_note: str('Interní poznámka (netiskne se).'),
      lines: arrayOf(
        'Řádky objednávky.',
        {
          stock_item_id: int('ID zboží. Vynech u služby (doprava, balné).'),
          warehouse_id: int('Přebije sklad z hlavičky jen pro tenhle řádek.'),
          description: str('Popis položky. U zboží se doplní z karty.', { maxLength: 500 }),
          vendor_sku: str('Kód položky u dodavatele.', { maxLength: 80 }),
          unit: str('Měrná jednotka.', { maxLength: 20 }),
          qty_ordered: num('Objednané množství.', { exclusiveMinimum: 0 }),
          unit_price: num('Cena za jednotku bez DPH.', { minimum: 0 }),
          vat_rate_id: int('Sazba DPH (jen pro orientační celkovou cenu).'),
          expected_date: date('Termín pro tenhle řádek, liší-li se od hlavičky.'),
          note: str('Poznámka k řádku.', { maxLength: 255 }),
        },
        ['qty_ordered'],
        { minItems: 1 },
      ),
    }, ['vendor_id', 'order_date', 'warehouse_id', 'currency_id', 'lines']),
    write: true,
    run: (c, a, tool) => c.post('/stock/purchase-orders', changed(a, [
      'vendor_id', 'order_date', 'warehouse_id', 'currency_id', 'expected_date',
      'exchange_rate', 'vendor_reference', 'note', 'internal_note', 'lines',
    ]), tool),
  },
  {
    name: 'purchase_orders_update',
    title: 'Upravit objednávku',
    description:
      'Přepíše rozpracovanou (draft) objednávku VČETNĚ ŘÁDKŮ — pošli je všechny, '
      + 'chybějící se smaže. Načti si proto nejdřív `purchase_orders_get`.\n\n'
      + 'Odeslanou objednávku upravit nejde (409): u dodavatele už existuje. '
      + 'Změnu množství zaznamenej přes `purchase_orders_confirm`, nedodaný zbytek '
      + 'přes `purchase_orders_close`.',
    inputSchema: schema({
      id: int('ID rozpracované objednávky.'),
      vendor_id: int('ID dodavatele.'),
      order_date: date('Datum objednávky.'),
      warehouse_id: int('Sklad, kam se má dodat.'),
      currency_id: int('Měna objednávky.'),
      expected_date: date('Požadovaný termín dodání.'),
      exchange_rate: num('Orientační kurz.', { exclusiveMinimum: 0 }),
      vendor_reference: str('Číslo objednávky u dodavatele.', { maxLength: 50 }),
      note: str('Poznámka pro dodavatele.'),
      internal_note: str('Interní poznámka.'),
      lines: arrayOf(
        'ÚPLNÝ seznam řádků po úpravě.',
        {
          stock_item_id: int('ID zboží. Vynech u služby.'),
          warehouse_id: int('Přebije sklad z hlavičky.'),
          description: str('Popis položky.', { maxLength: 500 }),
          vendor_sku: str('Kód u dodavatele.', { maxLength: 80 }),
          unit: str('Měrná jednotka.', { maxLength: 20 }),
          qty_ordered: num('Objednané množství.', { exclusiveMinimum: 0 }),
          unit_price: num('Cena za jednotku bez DPH.', { minimum: 0 }),
          vat_rate_id: int('Sazba DPH.'),
          expected_date: date('Termín pro tenhle řádek.'),
          note: str('Poznámka k řádku.', { maxLength: 255 }),
        },
        ['qty_ordered'],
        { minItems: 1 },
      ),
    }, ['id', 'vendor_id', 'order_date', 'warehouse_id', 'currency_id', 'lines']),
    write: true,
    run: (c, a, tool) => c.put(`/stock/purchase-orders/${a.id}`, changed(a, [
      'vendor_id', 'order_date', 'warehouse_id', 'currency_id', 'expected_date',
      'exchange_rate', 'vendor_reference', 'note', 'internal_note', 'lines',
    ]), tool),
  },
  {
    name: 'purchase_orders_send',
    title: 'Odeslat objednávku',
    description:
      'Přepne rozpracovanou objednávku na odeslanou, přidělí jí číslo z řady OBJ '
      + 'a od té chvíle se její zboží počítá jako „na cestě" — objeví se v '
      + '`stock_in_transit` a přestane se navrhovat v `replenishment_suggest`.\n\n'
      + 'Samotný e-mail dodavateli nástroj neposílá; PDF objednávky je na '
      + '`GET /api/stock/purchase-orders/{id}/pdf`. Opakované volání číslo znovu '
      + 'nepřidělí, jen vrátí objednávku beze změny.',
    inputSchema: schema({ id: int('ID rozpracované objednávky.') }, ['id']),
    write: true,
    run: (c, a, tool) => c.post(`/stock/purchase-orders/${a.id}/send`, {}, tool),
  },
  {
    name: 'purchase_orders_confirm',
    title: 'Potvrdit objednávku dodavatelem',
    description:
      'Zaznamená, že dodavatel objednávku potvrdil — volitelně s JINÝM množstvím '
      + 'nebo termínem, než jsme chtěli.\n\n'
      + 'Potvrzené množství (`qty_confirmed`) NAHRAZUJE objednané ve všech dalších '
      + 'výpočtech: „na cestě" i „zbývá přijmout" se od té chvíle počítají z něj. '
      + 'Když dodavatel potvrdil vše beze změny, stačí zavolat nástroj bez řádků.',
    inputSchema: schema({
      id: int('ID odeslané objednávky.'),
      expected_date: date('Termín potvrzený dodavatelem pro celou objednávku.'),
      lines: arrayOf(
        'Řádky, u kterých dodavatel něco změnil. Ostatní zůstanou beze změny.',
        {
          id: int('ID řádku objednávky (z `purchase_orders_get`, NE id zboží).'),
          qty_confirmed: num('Množství potvrzené dodavatelem.', { minimum: 0 }),
          expected_date: date('Termín pro tenhle řádek.'),
        },
        ['id'],
        { minItems: 1 },
      ),
    }, ['id']),
    write: true,
    run: (c, a, tool) => c.post(`/stock/purchase-orders/${a.id}/confirm`, changed(a, [
      'expected_date', 'lines',
    ]), tool),
  },
  {
    name: 'purchase_orders_close',
    title: 'Uzavřít nedodaný zbytek',
    description:
      'Uzavře objednávku s tím, že co nedorazilo, už nedorazí: nedodaný zbytek se '
      + 'označí za stornovaný, zmizí z „na cestě" a zboží se zase začne navrhovat '
      + 'k doobjednání.\n\n'
      + 'Tohle je správný krok u částečně dodané objednávky — `purchase_orders_cancel` '
      + 'tam server odmítne, protože přijaté zboží už na skladě leží. Uzavření lze '
      + 'vzít zpět přes `purchase_orders_reopen`.',
    inputSchema: schema({
      id: int('ID objednávky.'),
      reason: str('Proč zbytek nedorazí (uloží se k objednávce).', { maxLength: 255 }),
      confirm: CONFIRM,
    }, ['id']),
    write: true,
    destructive: true,
    run: async (c, a, tool) => {
      const order = await c.get(`/stock/purchase-orders/${a.id}`, null, tool);
      requireConfirm(
        a,
        'Uzavřít se má nedodaný zbytek objednávky',
        `${order?.order_number ?? `#${a.id}`} (${order?.vendor_name ?? '?'}) — objednáno `
        + `${order?.qty_ordered_total ?? '?'}, přijato ${order?.qty_received_total ?? '?'}, `
        + `neuzavřený zbytek ${order?.qty_remaining_total ?? '?'}`,
      );
      return c.post(`/stock/purchase-orders/${a.id}/close`, { reason: a.reason }, tool);
    },
  },
  {
    name: 'purchase_orders_cancel',
    title: 'Stornovat objednávku',
    description:
      'Stornuje objednávku a vyřadí ji z „na cestě". Jde to JEN dokud se z ní nic '
      + 'nepřijalo — jinak server vrátí 409 a je potřeba `purchase_orders_close`, '
      + 'protože přijaté zboží na skladě leží a nesmí ztratit svůj původ.\n\n'
      + 'Rozpracovanou objednávku, která nikdy neodešla, můžeš rovnou smazat přes '
      + '`purchase_orders_delete`.',
    inputSchema: schema({
      id: int('ID objednávky.'),
      reason: str('Důvod storna.', { maxLength: 255 }),
      confirm: CONFIRM,
    }, ['id']),
    write: true,
    destructive: true,
    run: async (c, a, tool) => {
      const order = await c.get(`/stock/purchase-orders/${a.id}`, null, tool);
      requireConfirm(
        a,
        'Stornovat se má objednávka',
        `${order?.order_number ?? `#${a.id}`} (${order?.vendor_name ?? '?'}) `
        + `za ${order?.total_without_vat ?? '?'} bez DPH`,
      );
      return c.post(`/stock/purchase-orders/${a.id}/cancel`, { reason: a.reason }, tool);
    },
  },
  {
    name: 'purchase_orders_reopen',
    title: 'Znovu otevřít objednávku',
    description:
      'Vrátí uzavřenou objednávku zpět mezi živé: vynuluje uzavřený zbytek a stav '
      + 'dopočítá podle toho, co už reálně dorazilo. Zboží se tím vrátí do „na cestě".',
    inputSchema: schema({ id: int('ID uzavřené objednávky.') }, ['id']),
    write: true,
    run: (c, a, tool) => c.post(`/stock/purchase-orders/${a.id}/reopen`, {}, tool),
  },
  {
    name: 'purchase_orders_delete',
    title: 'Smazat objednávku',
    description:
      'Smaže objednávku i s řádky. Server to dovolí jen u ROZPRACOVANÉ (draft) '
      + 'objednávky — odeslaná se stornuje přes `purchase_orders_cancel`, ať po ní '
      + 'zůstane stopa.',
    inputSchema: schema({ id: int('ID rozpracované objednávky.'), confirm: CONFIRM }, ['id']),
    write: true,
    destructive: true,
    run: async (c, a, tool) => {
      const was = await confirmed(c, a, tool, {
        path: `/stock/purchase-orders/${a.id}`,
        action: 'Smazat se má objednávka',
        label: (row) => `${row?.order_number ?? `#${a.id}`} — ${row?.vendor_name ?? '?'}`,
      });
      return { deleted: was, result: await c.del(`/stock/purchase-orders/${a.id}`, tool) };
    },
  },
  {
    name: 'purchase_orders_receipt',
    title: 'Příjem z objednávky na sklad',
    description:
      'Založí PŘÍJEMKU z objednávky — jako DRAFT, takže se stavem skladu zatím nic '
      + 'nedělá. Pohyb provede až `post_stock_document` s vráceným `id`, a ten '
      + 'zároveň posune stav objednávky na částečně/plně přijatou.\n\n'
      + 'Bez `lines` se přijme celý zbývající zbytek. Pořizovací cena se bere '
      + 'z napárované přijaté faktury; když ještě nedorazila, použije se cena '
      + 'z objednávky jako ODHAD (odpověď to hlásí v `cost_is_estimate`) — po '
      + 'doručení faktury je pak potřeba ocenění zkontrolovat.\n\n'
      + 'Přijmout víc, než bylo objednáno, server odmítne (409); vědomou nadměrnou '
      + 'dodávku pusť přes `allow_over_delivery`. Objednané množství se tím nikdy '
      + 'nezvyšuje, jen se řádek označí.',
    inputSchema: schema({
      id: int('ID objednávky.'),
      doc_date: date('Datum příjemky.'),
      warehouse_id: int('Sklad příjmu. Výchozí je sklad z objednávky.'),
      description: str('Popis příjemky.', { maxLength: 255 }),
      allow_over_delivery: bool('Přijmout i víc, než bylo objednáno.'),
      lines: arrayOf(
        'Co se přijímá. Bez tohohle pole se přijme celý zbývající zbytek.',
        {
          purchase_order_line_id: int('ID řádku objednávky (z `purchase_orders_get`).'),
          qty: num('Přijímané množství.', { exclusiveMinimum: 0 }),
          unit_cost: num('Pořizovací cena za MJ. Bez ní se použije cena z faktury nebo odhad z objednávky.', { minimum: 0 }),
        },
        ['purchase_order_line_id', 'qty'],
        { minItems: 1 },
      ),
    }, ['id', 'doc_date']),
    write: true,
    run: async (c, a, tool) => {
      let lines = a.lines;
      if (!Array.isArray(lines) || lines.length === 0) {
        const proposal = await c.get(`/stock/purchase-orders/${a.id}/receipt`, null, tool);
        lines = rows(proposal?.lines ?? [])
          .filter((l) => Number(l?.remaining_qty ?? 0) > 0)
          .map((l) => ({ purchase_order_line_id: l.purchase_order_line_id, qty: Number(l.remaining_qty) }));
        if (lines.length === 0) {
          throw new Error(
            `NEPROVEDENO — z objednávky #${a.id} už není co přijmout. `
            + 'Zkontroluj `purchase_orders_get`, jestli není celá přijatá nebo uzavřená.',
          );
        }
      }
      return c.post(`/stock/purchase-orders/${a.id}/receipt`, {
        doc_date: a.doc_date,
        warehouse_id: a.warehouse_id,
        description: a.description,
        allow_over_delivery: a.allow_over_delivery,
        lines,
      }, tool);
    },
  },
  {
    name: 'purchase_orders_bulk_create',
    title: 'Hromadně objednat',
    description:
      'Z plochého seznamu „tohle zboží v tomhle množství" založí objednávky '
      + 'SESKUPENÉ PO DODAVATELÍCH — jedna objednávka na dodavatele. Přesně tak se '
      + 'zpracuje výstup `replenishment_suggest`.\n\n'
      + 'Dodavatel se bere z `vendor_id` u položky, jinak z hlavního dodavatele zboží '
      + '(`vendor_offers_list`). Zboží, které dodavatele nemá, se do žádné objednávky '
      + 'nedostane a vrátí se ve `skipped` — projdi ten seznam, jinak objednáš míň, '
      + 'než sis myslel.\n\n'
      + 'Objednávky vzniknou jako ROZPRACOVANÉ. Do „na cestě" se dostanou až po '
      + '`purchase_orders_send`, které je potřeba zavolat na každou zvlášť.',
    inputSchema: schema({
      order_date: date('Datum objednávek. Výchozí dnešek.'),
      expected_date: date('Požadovaný termín dodání pro všechny.'),
      warehouse_id: int('Sklad dodání. Výchozí je výchozí sklad firmy.'),
      currency_id: int('Měna objednávek. Výchozí je výchozí měna firmy.'),
      note: str('Poznámka pro dodavatele na všechny objednávky.'),
      items: arrayOf(
        'Zboží k objednání.',
        {
          stock_item_id: int('ID zboží.'),
          qty: num('Objednávané množství.', { exclusiveMinimum: 0 }),
          vendor_id: int('Dodavatel. Bez něj se vezme hlavní dodavatel zboží.'),
          warehouse_id: int('Sklad jen pro tuhle položku.'),
          unit_price: num('Nákupní cena bez DPH. Bez ní se vezme z nabídky dodavatele.', { minimum: 0 }),
        },
        ['stock_item_id', 'qty'],
        { minItems: 1 },
      ),
    }, ['items']),
    write: true,
    run: (c, a, tool) => c.post('/stock/purchase-orders/bulk', changed(a, [
      'order_date', 'expected_date', 'warehouse_id', 'currency_id', 'note', 'items',
    ]), tool),
  },

  // ──────────────────────────────────────────────────────────────────────────
  // Sklad — příjemky, výdejky, převodky
  //
  // Doklad má dvě fáze: draft se dá libovolně upravovat i smazat a se stavem
  // skladu nic nedělá, teprve `post_stock_document` pohyb provede. Nástroje ty
  // dvě fáze nespojují schválně — agent má mít možnost doklad připravit
  // a nechat si ho zkontrolovat, než se zásoby pohnou.
  // ──────────────────────────────────────────────────────────────────────────
  {
    name: 'list_stock_documents',
    title: 'Skladové doklady',
    description:
      'Příjemky, výdejky a převodky s filtry na typ, stav, původ, sklad a období.',
    inputSchema: schema({
      doc_type: str('Typ dokladu: `receipt` příjemka, `issue` výdejka, `transfer` převodka.', {
        enum: ['receipt', 'issue', 'transfer'],
      }),
      status: str('Stav: `draft` rozpracovaný, `posted` zaúčtovaný, `reversed` stornovaný.', {
        enum: ['draft', 'posted', 'reversed'],
      }),
      origin: str('Původ dokladu.', {
        enum: ['manual', 'invoice', 'credit_note', 'purchase_invoice', 'inventory'],
      }),
      warehouse_id: int('Jen tento sklad.'),
      query: str('Fulltext přes číslo dokladu a popis.'),
      from: date('Od data (RRRR-MM-DD).'),
      to: date('Do data (RRRR-MM-DD).'),
      ...WINDOW,
    }),
    write: false,
    run: (c, a, tool) => c.get('/stock/documents', {
      doc_type: a.doc_type,
      status: a.status,
      origin: a.origin,
      warehouse_id: a.warehouse_id,
      q: a.query,
      from: a.from,
      to: a.to,
      limit: a.limit,
      offset: a.offset,
    }, tool),
  },
  {
    name: 'get_stock_document',
    title: 'Detail skladového dokladu',
    description: 'Hlavička a řádky jednoho skladového dokladu včetně ocenění.',
    inputSchema: schema({ id: int('ID skladového dokladu.') }, ['id']),
    write: false,
    run: (c, a, tool) => c.get(`/stock/documents/${a.id}`, null, tool),
  },
  {
    name: 'create_stock_document',
    title: 'Založit skladový doklad',
    description:
      'Založí ROZPRACOVANÝ skladový doklad (draft). Stav zásob se tím ještě NEMĚNÍ — '
      + 'pohyb provede až `post_stock_document`.\n\n'
      + '`receipt` = naskladnění (u řádků zadej `unit_cost`, jinak se zboží naskladní za nulu), '
      + '`issue` = vyskladnění, `transfer` = převod mezi sklady (povinný `warehouse_to_id`).\n\n'
      + 'Tímhle nástrojem dělej jen ruční pohyby. Naskladnění z přijaté faktury má vlastní '
      + 'cestu v aplikaci, která doklad naváže na fakturu a převezme z ní ceny.',
    inputSchema: schema({
      doc_type: str('Typ dokladu: `receipt` příjemka, `issue` výdejka, `transfer` převodka.', {
        enum: ['receipt', 'issue', 'transfer'],
      }),
      doc_date: date('Datum dokladu (RRRR-MM-DD).'),
      description: str('Popis dokladu — proč pohyb vzniká.', { maxLength: 255 }),
      warehouse_id: int('ID skladu. U převodky sklad, ze kterého se vydává.'),
      warehouse_to_id: int('ID cílového skladu. Povinné a jen u převodky (`transfer`).'),
      partner_name: str('Protistrana (dodavatel, odběratel) pro tisk dokladu.'),
      lines: STOCK_DOC_LINES,
    }, ['doc_type', 'doc_date', 'description', 'warehouse_id', 'lines']),
    write: true,
    run: (c, a, tool) => c.post('/stock/documents', {
      ...changed(a, ['doc_type', 'doc_date', 'description', 'warehouse_id', 'warehouse_to_id', 'partner_name', 'lines']),
      origin: 'manual',
    }, tool),
  },
  {
    name: 'update_stock_document',
    title: 'Upravit skladový doklad',
    description:
      'Přepíše rozpracovaný doklad. Zaúčtovaný ani stornovaný doklad upravit NEJDE (422) — '
      + 'tam se chyba řeší stornem přes `reverse_stock_document`.\n\n'
      + 'Pošleš-li `lines`, nahradí se řádky dokladu KOMPLETNĚ tímto seznamem; '
      + 'načti si proto nejdřív `get_stock_document`.',
    inputSchema: schema({
      id: int('ID skladového dokladu (musí být ve stavu `draft`).'),
      doc_date: date('Datum dokladu (RRRR-MM-DD).'),
      description: str('Popis dokladu.', { maxLength: 255 }),
      warehouse_id: int('ID skladu.'),
      warehouse_to_id: int('ID cílového skladu u převodky.'),
      partner_name: str('Protistrana.'),
      lines: STOCK_DOC_LINES,
    }, ['id']),
    write: true,
    run: (c, a, tool) => c.put(`/stock/documents/${a.id}`, changed(a, [
      'doc_date', 'description', 'warehouse_id', 'warehouse_to_id', 'partner_name', 'lines',
    ]), tool),
  },
  {
    name: 'post_stock_document',
    title: 'Zaúčtovat skladový doklad',
    description:
      'Provede pohyb: naskladní, vyskladní, nebo převede zboží, přidělí dokladu číslo '
      + 'a uzamkne ho. TEPRVE TÍMHLE KROKEM SE MĚNÍ STAV SKLADU.\n\n'
      + 'Zaúčtovaný doklad už nejde upravit ani smazat, jen stornovat protidokladem. '
      + 'Pouštěj to až po odsouhlasení uživatelem.\n\n'
      + 'Server odmítne (409) pohyb do minusu, sklad s rozběhnutou inventurou a uzavřené '
      + 'účetní období. Opakované zavolání na už zaúčtovaném dokladu nic nezmění.',
    inputSchema: schema({ id: int('ID rozpracovaného skladového dokladu.') }, ['id']),
    write: true,
    run: (c, a, tool) => c.post(`/stock/documents/${a.id}/post`, {}, tool),
  },
  {
    name: 'reverse_stock_document',
    title: 'Stornovat skladový doklad',
    description:
      'Vystaví ke zaúčtovanému dokladu opačný protidoklad v původních cenách a rovnou ho '
      + 'zaúčtuje; původní doklad se označí jako stornovaný. Hodnotově je to neutrální, '
      + 'ale ve skladové knize zůstanou oba doklady — storno se nedá vzít zpět.\n\n'
      + 'Storno příjemky, po které se už vydávalo a sklad by šel do minusu, server odmítne (409).',
    inputSchema: schema({
      id: int('ID zaúčtovaného skladového dokladu.'),
      reason: str('Důvod storna — propíše se do popisu protidokladu.'),
      confirm: CONFIRM,
    }, ['id']),
    write: true,
    destructive: true,
    run: async (c, a, tool) => {
      const was = await confirmed(c, a, tool, {
        path: `/stock/documents/${a.id}`,
        action: 'Stornovat se má skladový doklad',
        label: (row) => `${nameOf(row, a.id)} ze dne ${row?.doc_date ?? '?'} (stav ${row?.status ?? '?'})`,
      });
      const result = await c.post(`/stock/documents/${a.id}/reverse`, { reason: a.reason }, tool);
      return { reversed: was, result };
    },
  },
  {
    name: 'delete_stock_document',
    title: 'Smazat skladový doklad',
    description:
      'Smaže ROZPRACOVANÝ doklad. Zaúčtovaný doklad smazat nejde (422) — na ten je '
      + '`reverse_stock_document`.',
    inputSchema: schema({ id: int('ID skladového dokladu ve stavu `draft`.'), confirm: CONFIRM }, ['id']),
    write: true,
    destructive: true,
    run: async (c, a, tool) => {
      const was = await confirmed(c, a, tool, {
        path: `/stock/documents/${a.id}`,
        action: 'Smazat se má rozpracovaný skladový doklad',
        label: (row) => `${nameOf(row, a.id)} ze dne ${row?.doc_date ?? '?'} (stav ${row?.status ?? '?'})`,
      });
      return { deleted: was, result: await c.del(`/stock/documents/${a.id}`, tool) };
    },
  },

  // ──────────────────────────────────────────────────────────────────────────
  // Sklad — inventury
  // ──────────────────────────────────────────────────────────────────────────
  {
    name: 'list_stock_takes',
    title: 'Seznam inventur',
    description: 'Inventury s filtrem na sklad a fázi (rozpracovaná / počítá se / uzavřená).',
    inputSchema: schema({
      warehouse_id: int('Jen tento sklad.'),
      status: str('Fáze: `draft` rozpracovaná, `counting` probíhá počítání, `closed` uzavřená.', {
        enum: ['draft', 'counting', 'closed'],
      }),
    }),
    write: false,
    run: (c, a, tool) => c.get('/stock/takes', {
      warehouse_id: a.warehouse_id, status: a.status,
    }, tool),
  },
  {
    name: 'get_stock_take',
    title: 'Detail inventury',
    description:
      'Inventura i s řádky: očekávané množství, napočítané množství a rozdíl. '
      + 'Řádky s `counted_qty: null` ještě nikdo nespočítal a při uzavření se přeskočí.',
    inputSchema: schema({ id: int('ID inventury.') }, ['id']),
    write: false,
    run: (c, a, tool) => c.get(`/stock/takes/${a.id}`, null, tool),
  },
  {
    name: 'create_stock_take',
    title: 'Založit inventuru',
    description:
      'Založí rozpracovanou inventuru skladu. Odpovědné osoby jsou povinné, protože '
      + 'se tisknou na inventurní soupis — vyplň skutečná jména, ne zástupný text.\n\n'
      + 'Na jednom skladu smí být rozpracovaná jen jedna inventura (jinak 409). '
      + 'Se stavem zásob to zatím nic nedělá.',
    inputSchema: schema({
      warehouse_id: int('ID inventarizovaného skladu.'),
      take_date: date('Datum inventury (RRRR-MM-DD).'),
      counting_method: str('Způsob zjištění: `physical_count` přepočtení, `measurement` přeměření, '
        + '`weighing` převážení, `other` jiný.', {
        enum: ['physical_count', 'measurement', 'weighing', 'other'],
      }),
      responsible_count_name: str('Jméno osoby odpovědné za zjištění skutečného stavu.', { maxLength: 255 }),
      responsible_inventory_name: str('Jméno osoby odpovědné za inventarizaci.', { maxLength: 255 }),
      note: str('Poznámka.'),
    }, ['warehouse_id', 'take_date', 'counting_method', 'responsible_count_name', 'responsible_inventory_name']),
    write: true,
    run: (c, a, tool) => c.post('/stock/takes', changed(a, [
      'warehouse_id', 'take_date', 'counting_method',
      'responsible_count_name', 'responsible_inventory_name', 'note',
    ]), tool),
  },
  {
    name: 'start_stock_take',
    title: 'Spustit inventuru',
    description:
      'Přepne inventuru do fáze počítání a udělá snímek očekávaných stavů všech aktivních '
      + 'karet skladu. Od té chvíle je sklad ZABLOKOVANÝ pro zaúčtování skladových dokladů, '
      + 'dokud se inventura neuzavře — spouštěj to až ve chvíli, kdy se opravdu počítá.',
    inputSchema: schema({ id: int('ID inventury ve stavu `draft`.') }, ['id']),
    write: true,
    run: (c, a, tool) => c.post(`/stock/takes/${a.id}/start`, {}, tool),
  },
  {
    name: 'set_stock_take_counts',
    title: 'Zapsat napočítané množství',
    description:
      'Zapíše skutečně napočítané množství na řádky inventury. Jde to jen ve fázi počítání.\n\n'
      + 'Řádky se adresují svým `id` z `get_stock_take` (NE id zboží). Posílej jen řádky, '
      + 'které se mají změnit — ostatní zůstanou, jak byly. `counted_qty: null` znamená '
      + '„nepočítáno" a při uzavření se takový řádek přeskočí.',
    inputSchema: schema({
      id: int('ID inventury ve fázi `counting`.'),
      lines: arrayOf(
        'Řádky inventury, které se mají zapsat.',
        {
          line_id: int('ID řádku inventury z `get_stock_take`.'),
          counted_qty: num('Napočítané množství. Vynech pro „nepočítáno".', { minimum: 0 }),
          surplus_unit_cost: num('Cena za MJ pro ocenění přebytku (reprodukční pořizovací cena).', { minimum: 0 }),
        },
        ['line_id'],
        { minItems: 1 },
      ),
    }, ['id', 'lines']),
    write: true,
    run: (c, a, tool) => c.put(`/stock/takes/${a.id}`, {
      lines: a.lines.map((l) => ({
        id: l.line_id,
        counted_qty: l.counted_qty ?? null,
        ...(l.surplus_unit_cost === undefined ? {} : { surplus_unit_cost: l.surplus_unit_cost }),
      })),
    }, tool),
  },
  {
    name: 'close_stock_take',
    title: 'Uzavřít inventuru',
    description:
      'Uzavře inventuru: spočítá rozdíly proti očekávání a vygeneruje rozdílové skladové '
      + 'doklady — souhrnnou příjemku na přebytky a výdejku na manka. Doklady se rovnou '
      + 'zaúčtují, takže SE TÍM MĚNÍ STAV SKLADU a vzniká účetní dopad.\n\n'
      + 'Nevratné a s daňovou dohrou (manko a přebytek se vypořádávají v účetnictví), '
      + 'takže před uzavřením ověř přes `get_stock_take`, že jsou opravdu všechny řádky '
      + 'spočítané — nespočítané se tiše přeskočí — a nech si krok potvrdit uživatelem.',
    inputSchema: schema({ id: int('ID inventury ve fázi `counting`.'), confirm: CONFIRM }, ['id']),
    write: true,
    destructive: true,
    run: async (c, a, tool) => {
      const take = await c.get(`/stock/takes/${a.id}`, null, tool);
      const lines = rows(take?.lines ?? take);
      const uncounted = lines.filter((l) => l?.counted_qty === null || l?.counted_qty === undefined).length;
      requireConfirm(
        a,
        'Uzavřít se má inventura',
        `#${a.id} na skladu ${take?.warehouse_name ?? take?.warehouse_id ?? '?'} `
        + `ze dne ${take?.take_date ?? '?'} — ${lines.length} řádků, z toho ${uncounted} nespočítaných`,
      );
      return c.post(`/stock/takes/${a.id}/close`, {}, tool);
    },
  },
];

export const TOOLS_BY_NAME = new Map(TOOLS.map((t) => [t.name, t]));

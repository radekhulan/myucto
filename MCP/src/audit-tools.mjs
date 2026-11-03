import { seg } from './tool-shared.mjs';

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
const DATES = { from: date('Období od.'), to: date('Období do.') };
const YEAR = int('Rok.', { minimum: 2020, maximum: 2050 });
const QUERY = str('Hledaný text.');
const SUPPLIERS = {
  type: 'array', description: 'ID přístupných firem. Bez zadání použije API svůj výchozí rozsah.',
  items: id('ID firmy.'), uniqueItems: true,
};
const selected = (a, keys) => Object.fromEntries(
  keys.filter((key) => a[key] !== undefined).map((key) => [key, a[key]]),
);
const query = (a, keys, aliases = {}) => Object.fromEntries(
  Object.entries(selected(a, keys)).map(([key, value]) => [aliases[key] ?? key, value]),
);
const read = (name, title, description, properties, path, keys = Object.keys(properties), required = [], aliases = {}, api = false) => ({
  api, apiReason: api === false ? 'Dosud nepřevedeno do klientského katalogu; zachovává stávající rozsah (FR #285).' : undefined,
  name, title, description, inputSchema: schema(properties, required), write: false,
  run: (c, a, tool) => c.get(typeof path === 'function' ? path(a) : path,
    keys.length ? query(a, keys, aliases) : null, tool),
});
const supplierQuery = { company_id: id('ID přístupné firmy pro přehled, jinak aktuální firma.') };
const feedProperties = {
  suppliers: SUPPLIERS,
  source: str('Zdroj návrhu.', { enum: ['rule', 'learned', 'payment_match', 'transfer', 'detector', 'schedule', 'knn', 'llm', 'ai', 'document'] }),
  operation_type: str('Typ účetní operace.'),
  ...DATES,
  min_confidence: num('Nejnižší jistota návrhu.', { minimum: 0, maximum: 1 }),
  max_confidence: num('Nejvyšší jistota návrhu.', { minimum: 0, maximum: 1 }),
  min_amount: num('Nejnižší částka v Kč.', { minimum: 0 }),
  max_amount: num('Nejvyšší částka v Kč.', { minimum: 0 }),
  sort: str('Řazení.', { enum: ['default', 'date', 'confidence', 'amount', 'operation_type', 'source'] }),
  direction: str('Směr řazení.', { enum: ['asc', 'desc'] }),
  ...PAGING,
};
const feed = (name, title, path, properties) => ({
  ...read(name, title, 'Čtecí přehled účetní automatizace v přístupných firmách. Návrhy neschvaluje ani neúčtuje.', properties, path),
  run: (c, a, tool) => {
    const q = selected(a, Object.keys(properties));
    if (q.suppliers !== undefined) q.suppliers = q.suppliers.join(',');
    return c.get(path, q, tool);
  },
});
const TAX_INPUT = {
  type: str('Typ přiznání: fo = fyzická osoba (DPFO), po = právnická osoba (DPPO).', { enum: ['fo', 'po'] }),
  year: YEAR,
};
const TAX_VARIANT = {
  variant: str('Druh přiznání.', { enum: ['radne', 'opravne', 'dodatecne'] }),
  seq: int('Pořadí dodatečného přiznání. Nula = poslední existující.', { minimum: 0 }),
};
const taxPath = (a, suffix = '') => `/tax-return/${seg(a.type)}/${seg(a.year)}${suffix}`;
const taxRead = (name, title, description, suffix, variants = false) => read(
  name, title, description, { ...TAX_INPUT, ...(variants ? TAX_VARIANT : {}) },
  (a) => taxPath(a, suffix), variants ? Object.keys(TAX_VARIANT) : [], ['type', 'year'],
);

export const AUDIT_TOOLS = [
  read('cash_flow_statement', 'Peněžní toky a změny vlastního kapitálu',
    'Oficiální účetní přehled peněžních toků a změn vlastního kapitálu podle § 18. Vrací oba výkazy a informaci o povinnosti jejich sestavení. Bez filtru dimenze.',
    { period_id: id('ID účetního období.') }, '/accounting/reports/section18-statements', ['period_id'], ['period_id']),
  read('balance_inventory', 'Inventarizace rozvahových účtů',
    'Čtecí soupis rozvahových účtů a jejich konečných zůstatků k poslednímu dni účetního období.',
    { period_id: id('ID účetního období.') }, '/accounting/reports/balance-inventory', ['period_id'], ['period_id']),
  read('document_completeness', 'Úplnost dokladů proti bance',
    'Bankovní pohyby bez dokladu a doklady po splatnosti. Čtecí kontrola, která neopravuje ani neúčtuje doklady.',
    { days: int('Práh počtu dní.', { minimum: 0, maximum: 3650 }), direction: str('Směr pohybu.', { enum: ['outgoing', 'incoming', 'all'] }) },
    '/accounting/reports/document-completeness'),
  read('year_end_tax_estimate', 'Odhad daně do konce roku',
    'Čtecí živý odhad DPPO k výsledovce po účtech, bez uzavření či podání přiznání.',
    { period_id: id('ID účetního období.') }, '/accounting/reports/statement-accounts/tax-estimate', ['period_id'], ['period_id']),
  read('portfolio_overview', 'Přehled přístupných firem',
    'Přehled účetní kanceláře: termíny, nezaúčtované doklady, nespárované platby a stav období napříč přístupnými firmami.',
    {}, '/portfolio/overview'),
  read('group_dashboard', 'Manažerský přehled všech firem',
    'Tržby, náklady, zisk, roční predikce, cashflow, zůstatky, pohledávky a rizika přístupných firem. Výchozí dokladové období je posledních 12 kalendářních měsíců do dneška; from/to určí přesné období. Roční predikce zahrnuje známé výnosy/náklady ostatních položek; splátky jistiny jsou cashflow. Native součty oddělují měny, converted_czk nabízí přepočet na CZK s evidencí chybějících kurzů. Účetní období zůstávají oddělená. Jde o manažerský součet bez eliminace vnitroskupinových obchodů; neznámé hodnoty nejsou nula. Running je kumulovaný čistý tok, nikoli zůstatek účtu. Oprávnění se ověřují pro každou firmu a zdroj.',
    { section: str('Část přehledu, výchozí overview.', { enum: ['overview', 'trends', 'forecast', 'cashflow', 'balances', 'receivables', 'risks'] }),
      months: int('Výchozí počet kalendářních měsíců bez from/to (výchozí 12).', { minimum: 1, maximum: 36 }),
      from: str('Začátek dokladového období YYYY-MM-DD, vyžaduje také to. Nejvýše 36 kalendářních měsíců.'),
      to: str('Konec dokladového období YYYY-MM-DD včetně, vyžaduje také from.'),
      weeks: int('Počet týdnů prognózy (výchozí 8).', { minimum: 1, maximum: 12 }) }, '/portfolio/group-dashboard'),
  read('portfolio_monthly_check', 'Měsíční kontrola firmy v portfoliu',
    'Souhrn měsíční účetní kontroly jedné přístupné firmy.',
    { company_id: id('ID firmy z portfolio_overview.') }, (a) => `/portfolio/monthly-check/${seg(a.company_id)}`, [], ['company_id']),
  read('automation_overview', 'Souhrn účetní automatizace',
    'Stav účetní automatizace a front v přístupné firmě.', supplierQuery, '/automation/overview', ['company_id'], [], { company_id: 'supplier_id' }),
  read('automation_stats', 'Statistika účetní automatizace',
    'Počty, úspěšnost a objemy účetní automatizace za období.', { ...supplierQuery, ...DATES },
    '/automation/stats', ['company_id', 'from', 'to'], [], { company_id: 'supplier_id' }),
  read('automation_checklist', 'Kontrolní seznam účetních úkolů',
    'Denní kontrola, kontrola konce měsíce nebo příprava DPH. Vrací nálezy, neprovádí opravy.',
    { ...supplierQuery, ...DATES, scope: str('Druh kontroly.', { enum: ['daily', 'month_end', 'vat_return'] }) },
    '/automation/checklist', ['company_id', 'from', 'to', 'scope'], [], { company_id: 'supplier_id' }),
  feed('automation_feed', 'Fronta účetní automatizace', '/automation/feed', {
    tab: str('Část fronty.', { enum: ['auto', 'pending', 'needs_input'] }), ...feedProperties,
  }),
  feed('automation_history', 'Historie účetní automatizace', '/automation/history', feedProperties),
  feed('automation_counts', 'Počty ve frontách automatizace', '/automation/counts', { suppliers: SUPPLIERS, ...DATES }),
  feed('automation_recommendations', 'Doporučení účetní automatizace', '/automation/recommendations', {
    suppliers: SUPPLIERS, ...DATES,
    type: str('Druh doporučení.', { enum: ['post_invoice', 'post_purchase', 'classify_purchase', 'bank_rule'] }),
    ...PAGING,
  }),
  taxRead('get_tax_return', 'Podklady a výpočet přiznání k dani z příjmů',
    'Čte výpočet a stav DPFO nebo DPPO. API může při prvním čtení založit pracovní koncept. Přiznání nepodává.', '', true),
  taxRead('tax_return_check', 'Kontrola přiznání před dokončením',
    'Čtecí kontrolní seznam DPFO nebo DPPO před dokončením. Přiznání neuzavírá.', '/prefinalize-check', true),
  taxRead('tax_return_xml_preview', 'Pracovní XML přiznání',
    'Vrátí aktuální pracovní XML DPFO nebo DPPO jako text. XML nikam neodesílá.', '/xml/preview', true),
  read('tax_return_insurance', 'Pojistné OSVČ z přiznání',
    'Výpočet sociálního a zdravotního pojistného z přiznání DPFO.',
    { year: YEAR }, (a) => `/tax-return/fo/${seg(a.year)}/insurance`, [], ['year']),
  taxRead('list_tax_advances', 'Předpisy záloh na daň',
    'Čte předpisy a stav záloh souvisejících s rokem přiznání; zálohy negeneruje ani nemění.', '/advances'),
  read('upcoming_tax_advances', 'Blížící se daňové zálohy',
    'Nejbližší termíny a částky záloh firmy.', {}, '/tax-return/advances/upcoming'),
  read('tax_evidence_receivables_payables', 'Pohledávky a závazky daňové evidence',
    'Stáří pohledávek a závazků po měnách, DSO/DPO a platební morálka v režimu daňové evidence.',
    {}, '/tax-evidence/receivables-payables'),
  read('tax_evidence_closing', 'Stav roční uzávěrky daňové evidence',
    'Čte roční podklady a stav uzávěrky daňové evidence. Uzávěrku nemění.',
    { year: YEAR }, (a) => `/tax-evidence/closing/${seg(a.year)}`, [], ['year']),
  read('tax_evidence_transition_report', 'Přechod mezi daňovou evidencí a účetnictvím',
    'Čtecí podklady pro změnu režimu; evidenci firmy nepřepíná.', {
      as_of: date('Rozhodný den.'),
      direction: str('Směr přechodu.', { enum: ['tax_to_accounting', 'accounting_to_tax'] }),
    }, '/tax-evidence/transition-report'),
  read('cnb_rate_audit', 'Kontrola použitých měnových kurzů',
    'Odchylky kurzů cizoměnových dokladů od ČNB. Kurzy neopravuje.', {
      ...DATES, threshold: num('Práh odchylky v procentech.', { minimum: 0, maximum: 100 }),
    }, '/reports/cnb-rate-audit'),
  read('invoice_series_completeness', 'Úplnost číselných řad faktur',
    'Mezery v číselných řadách vydaných dokladů za rok.',
    { year: int('Rok.', { minimum: 2000, maximum: 2100 }) }, '/reports/invoice-series-completeness'),
  read('oss_return_preview', 'Náhled přiznání OSS',
    'Čtecí náhled OSS za čtvrtletí. Podání nevytváří ani neodesílá.', {
      year: YEAR, quarter: int('Čtvrtletí 1 až 4.', { minimum: 1, maximum: 4 }),
    }, '/reports/oss/preview'),
  read('oss_threshold', 'Čerpání limitu pro OSS',
    'Čerpání limitu 10 000 EUR před registrací i po ní.', { year: YEAR }, '/reports/oss/threshold'),
  read('accounting_fx_preview', 'Náhled kurzových rozdílů účetní uzávěrky',
      'Čte aktuální kurzové podklady bez zápisu. Pro rámec 504 vrací doložené případy, úplné dimenze a posouzení cenných papírů: aktuální inventuru, uložené důkazy ocenění, kontrolované zápisy a nevyřešené nálezy. Volitelná diagnostika dluhové báze není finančním oprávněním; kupónové, diskontní a HTM ocenění tím není dokončeno. Rozhodnutí ani zaúčtování neprovádí.',
      { period_id: id('ID účetního období.') }, (a) => `/accounting/periods/${seg(a.period_id)}/closing/fx-preview`, [], ['period_id']),
  read('accounting_closing_status', 'Stav účetní uzávěrky',
    'Čte stav a podklady účetní uzávěrky. Nezahajuje ani neuzavírá období.',
    { period_id: id('ID účetního období.') }, (a) => `/accounting/periods/${seg(a.period_id)}/closing`, [], ['period_id']),
  read('accounting_monthly_check', 'Měsíční kontrola účetnictví',
    'Čtecí kontroly účetního období ve zvoleném rozsahu dat.', {
      period_id: id('ID účetního období.'), date_from: date('Od data.'), date_to: date('Do data.'),
    }, (a) => `/accounting/periods/${seg(a.period_id)}/monthly-check`, ['date_from', 'date_to'], ['period_id']),
  read('list_assets', 'Seznam majetku', 'Majetkové karty firmy s filtrem stavu a názvu.', {
    status: str('Stav majetku.', { enum: ['draft', 'in_use', 'disposed'] }), query: QUERY, ...PAGING,
  }, '/accounting/assets', ['status', 'query', 'page', 'per_page'], [], { query: 'q' }),
  read('get_asset', 'Detail majetku', 'Majetková karta včetně technického zhodnocení a zámků.',
    { id: id('ID majetkové karty.') }, (a) => `/accounting/assets/${seg(a.id)}`, [], ['id']),
  read('asset_depreciation_plan', 'Plán odpisů majetku', 'Čte účetní a daňový plán odpisů. Odpisy neúčtuje.',
    { id: id('ID majetkové karty.') }, (a) => `/accounting/assets/${seg(a.id)}/depreciation-plan`, [], ['id']),
  read('list_cash_registers', 'Seznam pokladen', 'Pokladny firmy včetně volitelné nabídky neaktivních.',
    { include_inactive: bool('Zahrnout neaktivní pokladny.') }, '/accounting/cash-registers'),
  read('get_cash_register', 'Detail a zůstatek pokladny', 'Čte pokladnu a zůstatek k datu.', {
    id: id('ID pokladny.'), date: date('Datum zůstatku, jinak dnešek.'),
  }, (a) => `/accounting/cash-registers/${seg(a.id)}`, ['date'], ['id']),
  read('list_cash_documents', 'Seznam pokladních dokladů', 'Pokladní doklady s filtry na pokladnu, stav a datum.', {
    register_id: id('ID pokladny.'), doc_type: str('Směr dokladu.', { enum: ['in', 'out'] }),
    purpose: str('Účel dokladu.'), status: str('Stav dokladu.'), ...DATES, query: QUERY, ...PAGING,
  }, '/accounting/cash-documents', ['register_id', 'doc_type', 'purpose', 'status', 'from', 'to', 'query', 'page', 'per_page'], [], { query: 'q' }),
  read('get_cash_document', 'Detail pokladního dokladu', 'Čte řádky, částky a vazby pokladního dokladu.',
    { id: id('ID pokladního dokladu.') }, (a) => `/accounting/cash-documents/${seg(a.id)}`, [], ['id']),
  {
    ...read('list_bank_statements', 'Seznam bankovních výpisů',
    'Bankovní výpisy a jejich stav párování. Stránka má 50 záznamů.', {
      year: int('Rok výpisu.'), month: int('Měsíc výpisu.', { minimum: 1, maximum: 12 }),
      account: str('Vlastní bankovní účet.'), bank_code: str('Kód banky.'),
      counterparty_account: str('Protiúčet transakce.'), client_id: id('ID protistrany.'),
      posting_status: str('Jen výpisy s nezaúčtovanými pohyby.', { enum: ['unposted'] }),
      amount: num('Hledaná absolutní částka transakce.', { minimum: 0 }), page: PAGING.page,
    }, '/bank-statements', [], [], {}, [['GET', '/api/bank-statements']]),
    run: (c, a, tool) => c.get('/bank-statements', {
      ...selected(a, ['page']),
      filter: selected(a, ['year', 'month', 'account', 'bank_code', 'counterparty_account', 'client_id', 'posting_status', 'amount']),
    }, tool),
  },
  read('get_bank_statement', 'Detail bankovního výpisu',
    'Hlavička výpisu a stránkované transakce, jejich párování a zaúčtování.', {
      id: id('ID výpisu.'),
      status: str('Stav párování transakcí.', { enum: ['unmatched', 'auto_exact', 'auto_partial', 'manual', 'ignored'] }),
      posting_status: str('Stav zaúčtování transakcí.', { enum: ['unposted', 'posted'] }), ...PAGING,
    }, (a) => `/bank-statements/${seg(a.id)}`, ['status', 'posting_status', 'page', 'per_page'], ['id'], {}, [['GET', '/api/bank-statements/{id}']]),
  read('bank_account_balances', 'Zůstatky bankovních účtů',
    'Zůstatky vlastních účtů ze skutečných výpisů a historie podle měn.', {}, '/bank-statements/account-balances'),
  read('list_bank_match_suggestions', 'Návrhy párování bankovního výpisu',
    'Čte návrhy párování k výpisu. Žádný návrh nepřijímá.',
    { id: id('ID bankovního výpisu.') }, (a) => `/bank-statements/${seg(a.id)}/match-suggestions`, [], ['id']),
  read('list_recurring_invoices', 'Šablony pravidelné fakturace',
    'Čte šablony pravidelné fakturace, jejich částky a příští termíny.', {
      client_id: id('ID odběratele.'), status: str('Stav šablony.'),
      sort: str('Řazení šablon.', { enum: ['client', 'next_run', 'amount_czk'] }),
      page: PAGING.page, per_page: int('Záznamů na stránce.', { minimum: 5, maximum: 200 }),
    }, '/recurring', ['client_id', 'status', 'sort', 'page', 'per_page'], [], {}, [['GET', '/api/recurring']]),
  read('get_recurring_invoice', 'Detail šablony pravidelné fakturace',
    'Čte nastavení a řádky pravidelné fakturace. Fakturu nevytváří.',
    { id: id('ID šablony.') }, (a) => `/recurring/${seg(a.id)}`, [], ['id'], {}, [['GET', '/api/recurring/{id}']]),
  read('recurring_invoice_history', 'Faktury ze šablony', 'Vystavené faktury vygenerované jednou pravidelnou šablonou.',
    { id: id('ID šablony.'), ...PAGING }, (a) => `/recurring/${seg(a.id)}/invoices`, ['page', 'per_page'], ['id']),
  read('list_document_requests', 'Požadavky na podklady',
    'Čte požadavky účetní na podklady od klienta a jejich stav.',
    { status: str('Stavy oddělené čárkou, např. requested,uploaded,resolved.') }, '/document-requests'),
  read('get_document_request', 'Detail požadavku na podklad', 'Čte požadavek, termín a stav dodání podkladu.',
    { id: id('ID požadavku.') }, (a) => `/document-requests/${seg(a.id)}`, [], ['id']),
  read('catalog_changes', 'Změny katalogu od cursoru',
    'Přírůstkový seznam změn zboží, cen, médií a dostupnosti. Cursor je oddělený pro každou firmu.', {
      after_cursor: int('Poslední uložený cursor, jinak 0.', { minimum: 0 }),
      limit: int('Záznamů (1 až 1000).', { minimum: 1, maximum: 1000 }),
    }, '/catalog/changes'),
  read('stock_sales_report', 'Prodeje skladových karet',
    'Prodeje po dokladech, odběratelích nebo kartách. Dobropisy snižují tržby; částky se sčítají odděleně po měnách.', {
      date_from: date('Od data DUZP, jinak vystavení.'), date_to: date('Do data DUZP, jinak vystavení.'),
      client_id: id('ID odběratele.'), category_id: id('Kategorie včetně podkategorií.'),
      warehouse_id: id('ID skladu.'), stock_item_id: id('ID skladové karty.'), query: QUERY,
      group_by: str('Seskupení výsledků.', { enum: ['none', 'client', 'item'] }),
      page: PAGING.page, per_page: int('Záznamů na stránce.', { minimum: 1, maximum: 500 }),
    }, '/stock/reports/sales', ['date_from', 'date_to', 'client_id', 'category_id', 'warehouse_id', 'stock_item_id', 'query', 'group_by', 'page', 'per_page'], [], { query: 'q' }),
  {
    name: 'intrastat_preview',
    api: false,
    apiReason: 'Dosud nepřevedeno do klientského katalogu; zachovává stávající rozsah (FR #285).',
    title: 'Náhled hlášení Intrastat',
    description: 'Čtecí náhled pohybů zboží mezi zeměmi EU pro InstatEvo, včetně chyb a varování. Hlášení neodesílá.',
    inputSchema: schema({
      period: str('Měsíc hlášení, nejdříve 2026-01.', { pattern: '^20[2-9][0-9]-(0[1-9]|1[0-2])$' }),
      direction: str('Směr pohybu.', { enum: ['arrival', 'dispatch'] }),
      transaction_code: str('Druh transakce.', { enum: ['11', '12'] }),
      transport_mode: str('Druh dopravy.', { enum: ['2', '3', '4', '5', '7', '8', '9'] }),
      delivery_terms: str('Dodací podmínky.', { enum: ['K', 'L', 'M', 'N'] }),
      record_type: str('Typ věty.', { enum: ['ST'] }),
      statistical_code: str('Statistický znak.', { pattern: '^(|[0-9]{2})$' }),
      note_1: str('Poznámka.', { maxLength: 256 }),
    }, ['period', 'direction']),
    write: false,
    run: (c, a, tool) => c.postRead('/stock/intrastat/preview', selected(a, [
      'period', 'direction', 'transaction_code', 'transport_mode', 'delivery_terms', 'record_type', 'statistical_code', 'note_1',
    ]), tool),
  },
];

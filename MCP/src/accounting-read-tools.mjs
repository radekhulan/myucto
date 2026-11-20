/**
 * Čtecí nástroje účetnictví, daní a mezd: fronty zaúčtování, kontext zápisu, otevřené
 * položky, vypořádání DPH, pokladní kniha, drobný majetek, kontroly před podáním DPH,
 * detail podání a výsledky mzdového běhu.
 *
 * Vše jen čte. Server účetní a daňovou vrstvu tokenu pro zápis odmítne
 * (`ApiScopeMiddleware::BEARER_READ_ONLY`), schvalování návrhů, zaúčtování, uzávěrky,
 * opravy podle § 46 a § 74b ani podání proto v katalogu nejsou.
 */
import { seg } from './tool-shared.mjs';
import {
  bool, custom, date, id, int, MONTH, PAGING, pick, read, str, YEAR,
} from './tool-schema.mjs';

const MATCH_STATUS = str('Stav párování pohybu.', { enum: ['unmatched', 'auto_exact', 'auto_partial', 'manual', 'ignored'] });
const SUGGESTION_STATUS = str('Stav návrhu: pending = čeká na schválení, needs_input = potřebuje doplnění, blocked = '
  + 'blokovaný (uzavřené období, nejistá měna), approved / auto_posted = zaúčtováno, rejected, superseded.', {
  enum: ['pending', 'needs_input', 'blocked', 'approved', 'rejected', 'auto_posted', 'superseded'],
});

const VAT_CHECKS = {
  post_filing_changes: ['/reports/dphdp3/post-filing-changes', ['year', 'month', 'period']],
  s46_candidates: ['/reports/s46/candidates', ['as_of']],
  s74b_preview: ['/reports/s74b/preview', ['year', 'month']],
  vat_coefficient: ['/reports/vat-coefficient', ['year']],
  related_parties: ['/reports/related-parties', ['from', 'to']],
  dph_settings: ['/reports/dphdp3/settings', []],
};

export const ACCOUNTING_READ_TOOLS = [
  read('list_manual_posting_queue', 'Fronta ručního zaúčtování',
    'Jen podvojné účetnictví: doklady a pohyby, které automatika nezaúčtovala a čekají na účetní (bankovní pohyby bez '
    + 'návrhu, přijaté a vydané faktury, požadavky na doklady), s důvodem a počty podle typu. Nic nezaúčtuje.', {
      type: str('Jen jeden typ položek.', { enum: ['bank_no_suggestion', 'purchase_invoice', 'sales_invoice', 'document_request'] }),
      reason: str('Jen položky s tímto důvodem (kód z odpovědi).', { maxLength: 64 }),
      ...PAGING,
    }, '/accounting/manual-posting-queue', { query: ['type', 'reason', 'page', 'per_page'] }),
  custom('list_bank_posting_queue', 'Fronta zaúčtování banky',
    'Jen podvojné účetnictví. `view: "unposted"` (výchozí) = bankovní pohyby bez zápisu v deníku; `view: "suggestions"` '
    + '= návrhy kontace z pravidel a automatiky (MD/D, jistota, poznámka) a protokol automatiky; `view: "counts"` = '
    + 'počty v obou frontách. Návrhy neschvaluje ani nezamítá, to dělá účetní v aplikaci (Automat).', {
      view: str('Která fronta.', { enum: ['unposted', 'suggestions', 'counts'] }),
      status: str('Jen unposted: stav párování (unmatched, auto_exact, auto_partial, manual, ignored); jen suggestions: '
        + 'stav návrhu (pending, needs_input, blocked, approved, rejected, auto_posted, superseded).', { maxLength: 20 }),
      year: int('Jen unposted: rok pohybu.', { minimum: 2000, maximum: 2100 }),
      query: str('Jen unposted: protistrana, VS, popis nebo částka.', { maxLength: 100 }),
      account: str('Náš bankovní účet.'),
      ...PAGING,
    }, [], false, async (c, a, tool) => {
      if (a.view === 'counts') {
        const [unposted, suggestions] = await Promise.all([
          c.get('/accounting/bank-posting-unposted/count', null, tool),
          c.get('/accounting/bank-posting-suggestions/count', null, tool),
        ]);
        return { unposted, suggestions };
      }
      if (a.view === 'suggestions') {
        if (a.status !== undefined && !SUGGESTION_STATUS.enum.includes(a.status)) {
          throw new Error(`Neplatný stav návrhu „${a.status}". Povolené: ${SUGGESTION_STATUS.enum.join(', ')}.`);
        }
        return c.get('/accounting/bank-posting-suggestions', pick(a, ['status', 'account', 'page', 'per_page']), tool);
      }
      if (a.status !== undefined && !MATCH_STATUS.enum.includes(a.status)) {
        throw new Error(`Neplatný stav párování „${a.status}". Povolené: ${MATCH_STATUS.enum.join(', ')}.`);
      }
      return c.get('/accounting/bank-posting-unposted',
        pick(a, ['status', 'year', 'query', 'account', 'page', 'per_page'], { query: 'q' }), tool);
    }),
  read('accounting_period_check_findings', 'Nálezy jedné účetní kontroly',
    'Detail jedné kontroly z accounting_monthly_check nebo accounting_closing_status: konkrétní doklady, zápisy a částky, '
    + 'které kontrolu nesplnily. `check` je kód kontroly z jejich odpovědi. Nic neopravuje.', {
      period_id: id('ID účetního období.'),
      check: str('Kód kontroly (malá písmena, číslice a podtržítka), např. z výsledku accounting_monthly_check.', { pattern: '^[a-z0-9_]+$' }),
      date_from: date('Od data (měsíční rozsah kontroly).'),
      date_to: date('Do data.'),
    }, (a) => `/accounting/periods/${seg(a.period_id)}/checks/${seg(a.check)}`, {
      query: ['date_from', 'date_to'], required: ['period_id', 'check'],
    }),
  read('get_journal_entry_context', 'Kontext účetního zápisu',
    'Doplňuje get_journal_entry: `section` history = auditní historie změn zápisu, links = připojené doklady, notes = '
    + 'poznámky, related = související doklady a úhrady i s jejich zápisy (faktury, ostatní pohledávky a závazky, '
    + 'bankovní a pokladní úhrady, zápočty), source = souhrn zdrojového dokladu. Nic nemění.', {
      id: id('ID účetního zápisu.'),
      section: str('Co načíst.', { enum: ['source', 'related', 'links', 'notes', 'history'] }),
    }, (a) => `/accounting/journal/${seg(a.id)}/${seg(a.section)}`, { required: ['id', 'section'] }),
  read('list_document_journal_entries', 'Účetní zápisy faktury',
    'Všechny zápisy vydané (`source: "invoices"`) nebo přijaté (`source: "purchase-invoices"`) faktury včetně storen '
    + 'a přeúčtování. Nic nemění.', {
      source: str('Druh dokladu.', { enum: ['invoices', 'purchase-invoices'] }),
      id: id('ID faktury.'),
    }, (a) => `/accounting/journal/for-document/${seg(a.source)}/${seg(a.id)}`, { required: ['source', 'id'] }),
  custom('list_open_items', 'Otevřené položky účtu',
    'Saldokonto jednoho účtu k datu: řádky deníku spárované do okruhů (doklad, úhrady, zůstatek). S `include_suggestions` '
    + 'přidá návrhy, které otevřené položky k sobě patří. Návrhy nepoužije ani nic nespáruje.', {
      account_id: id('ID účtu z chart_of_accounts (ne číslo účtu).'),
      as_of: date('K datu, jinak dnes.'),
      only_open: bool('Jen neuzavřené okruhy (výchozí true).'),
      include_suggestions: bool('Přidat návrhy párování.'),
      days: int('Jen návrhy: okno ± dní pro hledání protipoložky.', { minimum: 1, maximum: 366 }),
      ...PAGING,
    }, ['account_id'], false, async (c, a, tool) => {
      const path = `/accounting/open-items/${seg(a.account_id)}`;
      const query = { ...pick(a, ['as_of', 'page', 'per_page']), ...(a.only_open === undefined ? {} : { only_open: a.only_open ? 1 : 0 }) };
      const items = await c.get(path, query, tool);
      if (!a.include_suggestions) return items;
      return { items, suggestions: await c.get(`${path}/suggestions`, pick(a, ['as_of', 'days']), tool) };
    }),
  custom('open_items_listing', 'Soupis otevřených položek',
    'Soupis otevřených položek více účtů k datu (podklad inventarizace pohledávek a závazků), např. 311, 321, 314, 324. '
    + 'Nic nemění.', {
      as_of: date('K datu, jinak dnes.'),
      account_codes: { type: 'array', description: 'Čísla syntetických nebo analytických účtů, jinak výchozí sada.', items: str('Číslo účtu.', { pattern: '^[0-9]{3}[0-9.]*$' }), uniqueItems: true },
    }, [], false, (c, a, tool) => c.get('/accounting/reports/open-items-list', {
      ...pick(a, ['as_of']), ...(a.account_codes ? { codes: a.account_codes.join(',') } : {}),
    }, tool)),
  read('vat_clearing_status', 'Vypořádání DPH za měsíc',
    'Jen podvojné účetnictví: stav měsíčního vypořádání DPH na účtu 343 (částky vstupu a výstupu, zda je vypořádání '
    + 'zaúčtované). Vypořádání neprovádí.', { year: YEAR, month: MONTH }, '/accounting/vat-clearing', {
      query: ['year', 'month'], required: ['year', 'month'],
    }),
  read('get_cash_register_book', 'Pokladní kniha',
    'Pokladní kniha jedné pokladny: počáteční stav, příjmy a výdaje s průběžným zůstatkem, filtry na období, směr '
    + 'a účel. Nic nemění.', {
      id: id('ID pokladny (list_cash_registers).'),
      from: date('Od data.'), to: date('Do data.'),
      query: str('Hledaný text v popisu, partnerovi nebo čísle dokladu.', { maxLength: 100 }),
      doc_type: str('in = příjem, out = výdej.', { enum: ['in', 'out'] }),
      purpose: str('Účel dokladu.', { enum: ['sale', 'purchase', 'invoice_payment', 'purchase_payment', 'transfer', 'other'] }),
      ...PAGING,
    }, (a) => `/accounting/cash-registers/${seg(a.id)}/book`, {
      query: ['from', 'to', 'query', 'doc_type', 'purpose', 'page', 'per_page'], required: ['id'], aliases: { query: 'q' },
    }),
  custom('list_small_assets', 'Drobný majetek',
    'Karty drobného majetku (pod limitem dlouhodobého majetku): název, inventární číslo, cena, umístění, odpovědná '
    + 'osoba a stav. S `id` vrátí detail jedné karty. Nic nemění.', {
      id: id('Detail jedné karty.'),
      status: str('in_use = používá se, disposed = vyřazený, sold = prodaný.', { enum: ['in_use', 'disposed', 'sold'] }),
      query: str('Hledaný text.', { maxLength: 100 }),
      location: str('Umístění.', { maxLength: 190 }),
      year: int('Rok pořízení.', { minimum: 2000, maximum: 2100 }),
      ...PAGING,
    }, [], false, (c, a, tool) => (a.id !== undefined
      ? c.get(`/accounting/small-assets/${seg(a.id)}`, null, tool)
      : c.get('/accounting/small-assets', pick(a, ['status', 'query', 'location', 'year', 'page', 'per_page'], { query: 'q' }), tool))),
  custom('small_assets_report', 'Soupis a pohyby drobného majetku',
    '`kind: "inventory"` = soupis karet drobného majetku k datu (inventura), `kind: "movements"` = zařazení, vyřazení '
    + 'a prodeje v období. Nic nemění.', {
      kind: str('Druh sestavy.', { enum: ['inventory', 'movements'] }),
      as_of: date('Jen inventory: k datu.'),
      from: date('Jen movements: od data.'), to: date('Jen movements: do data.'),
    }, ['kind'], false, (c, a, tool) => (a.kind === 'movements'
      ? c.get('/accounting/reports/small-assets/movements', pick(a, ['from', 'to']), tool)
      : c.get('/accounting/reports/small-assets/inventory', pick(a, ['as_of']), tool))),
  read('asset_purchase_candidates', 'Přijaté faktury na majetek bez karty',
    'Přijaté faktury označené jako pořízení majetku, ke kterým ještě nevznikla majetková karta. Kartu nezakládá.',
    {}, '/accounting/assets/purchase-candidates'),
  custom('monthly_report_preview', 'Měsíční přehled pro klienta',
    'Data měsíčního přehledu, který účetní posílá klientovi: výsledovka za měsíc a od začátku roku, rozvaha, '
    + 'pohledávky a závazky po splatnosti ze saldokonta, DPH k úhradě a nadcházející daňové termíny. Jen náhled '
    + 'dat, PDF nevytváří a nic neodesílá.', {
      year: YEAR, month: MONTH,
      accrual_spread: bool('Rozpustit roční náklady a výnosy do měsíců (časové rozlišení).'),
    }, ['year', 'month'], false, (c, a, tool) => c.get('/accounting/reports/monthly-report/preview', {
      year: a.year, month: a.month, ...(a.accrual_spread === undefined ? {} : { accrual_spread: a.accrual_spread ? 1 : 0 }),
    }, tool)),
  custom('vat_filing_checks', 'Kontroly před podáním DPH',
    'Čtecí kontroly kolem přiznání k DPH: `post_filing_changes` = doklady změněné po podání (kandidáti na dodatečné '
    + 'přiznání; year, month, period), `s46_candidates` = pohledávky po splatnosti pro opravu podle § 46 (as_of), '
    + '`s74b_preview` = nezaplacené závazky pro opravu odpočtu podle § 74b (year, month), `vat_coefficient` = '
    + 'koeficient krácení nároku podle § 76 (year), `related_parties` = obchody se spojenými osobami (from, to), '
    + '`dph_settings` = zdaňovací období, finanční úřad a plátcovství. Opravy neeviduje a nic nepodává.', {
      check: str('Druh kontroly.', { enum: Object.keys(VAT_CHECKS) }),
      year: YEAR, month: MONTH,
      period: str('Zdaňovací období.', { enum: ['monthly', 'quarterly'] }),
      as_of: date('K datu.'), from: date('Od data.'), to: date('Do data.'),
    }, ['check'], false, (c, a, tool) => {
      const [path, keys] = VAT_CHECKS[a.check];
      return c.get(path, keys.length ? pick(a, keys) : null, tool);
    }),
  custom('get_tax_submission', 'Detail daňového podání',
    'Detail záznamu z list_tax_submissions včetně vygenerovaného XML a stavu podání. S `include_payment` přidá platební '
    + 'údaje k přiznání (částka, variabilní symbol, účet finančního úřadu). Nic nepodává ani neplatí.', {
      id: id('ID podání.'),
      include_payment: bool('Přidat platební údaje.'),
    }, ['id'], false, async (c, a, tool) => {
      const submission = await c.get(`/reports/submissions/${seg(a.id)}`, null, tool);
      if (!a.include_payment) return submission;
      return { submission, payment: await c.get(`/reports/submissions/${seg(a.id)}/payment`, null, tool) };
    }),
  read('tax_evidence_tax_estimate', 'Odhad daně a pojistného OSVČ',
    'Daňová evidence: průběžný odhad daně z příjmů a sociálního a zdravotního pojistného OSVČ za rok z dosavadních '
    + 'příjmů a výdajů. Přiznání nevytváří.', { year: YEAR }, '/tax-evidence/tax-estimate', { query: ['year'] }),
  read('tax_advance_overrides', 'Rozhodnutí FÚ o zálohách na daň',
    'Přehled rozhodnutí finančního úřadu podle § 174 daňového řádu, která mění výši nebo placení záloh na daň '
    + '(platnost od a do, částka, důvod). Nic nemění.', {
      type: str('fo = fyzická osoba, po = právnická osoba.', { enum: ['fo', 'po'] }),
      year: YEAR,
    }, (a) => `/tax-return/${seg(a.type)}/${seg(a.year)}/advances/overrides`, { required: ['type', 'year'] }),
  custom('get_payroll_run_results', 'Výsledky mzdového běhu',
    'Detail mzdového běhu (list_payroll_runs) a výsledky po zaměstnancích: hrubá mzda, pojistné, daň, čistá mzda, '
    + 'k výplatě a nálezy kontrol. S `employee_id` vrátí rozpad jedné osoby. Obsahuje osobní a mzdové údaje, ukazuj '
    + 'jen oprávněnému uživateli. Nic nepočítá ani neschvaluje.', {
      id: id('ID mzdového běhu.'),
      employee_id: id('Jen tento zaměstnanec.'),
      query: str('Hledání podle jména.', { maxLength: 100 }),
      findings_only: bool('Jen osoby s nálezy kontrol.'),
      sort: str('Řazení.', { enum: ['name', 'gross_minor', 'insurance_minor', 'tax_minor', 'net_minor', 'payable_minor'] }),
      dir: str('Směr řazení.', { enum: ['asc', 'desc'] }),
      limit: int('Počet osob.', { minimum: 1, maximum: 200 }),
      offset: int('Posun.', { minimum: 0 }),
    }, ['id'], false, async (c, a, tool) => {
      const run = await c.get(`/payroll/runs/${seg(a.id)}`, null, tool);
      if (a.employee_id !== undefined) {
        return { run, person: await c.get(`/payroll/runs/${seg(a.id)}/people/${seg(a.employee_id)}`, null, tool) };
      }
      return {
        run,
        people: await c.get(`/payroll/runs/${seg(a.id)}/people`, {
          ...pick(a, ['sort', 'dir', 'limit', 'offset', 'query'], { query: 'q' }),
          ...(a.findings_only ? { findings: 1 } : {}),
        }, tool),
      };
    }),
];

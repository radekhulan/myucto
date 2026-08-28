import { api } from './client'
import type { AutomationProvenance } from './automation'

/**
 * Podvojné účetnictví (Epic F1) — typovaný klient pro /api/accounting.
 * Endpointy jsou tenant-scoped přes X-Supplier-Id (přidává api/client.ts).
 * Zápisy vyžadují roli účetní|admin; změna stavu období je admin-only.
 * Chyby chodí jako { error: { code, message } } — čti e.response.data.error.
 */

// ── Účtová osnova (chart of accounts) ──────────────────────────────────────
export type AccountType = 'asset' | 'liability' | 'equity' | 'revenue' | 'expense' | 'offbalance' | 'closing'
export type NormalSide = 'debit' | 'credit'

export interface ChartAccount {
  id: number
  supplier_id: number
  account_code: string
  name: string
  account_type: AccountType
  normal_side: NormalSide | null
  is_synthetic: boolean
  parent_id: number | null
  is_active: boolean
  created_at: string
  /** Přítomné jen ve stromové variantě (?tree=1). */
  children?: ChartAccount[]
}

export interface CreateAccountPayload {
  parent_id: number
  account_code: string
  name: string
}

export interface UpdateAccountPayload {
  name?: string
  is_active?: boolean
}

/** PS / obraty / KS účtu za zvolený rozsah (kladné = MD). */
export interface AccountBalances {
  opening_balance: number
  turnover_md: number
  turnover_d: number
  closing_balance: number
  line_count: number
}

export interface AccountDetailChild extends AccountBalances {
  id: number
  code: string
  name: string
  is_active: boolean
}

/**
 * Karta účtu — kmenová data, analytiky se zůstatky a součty za rozsah.
 * Součty syntetiky zahrnují i pohyby jejích analytik (roll-up jako v hlavní knize).
 */
export interface AccountDetailReport {
  account: {
    id: number
    code: string
    name: string
    account_type: AccountType
    normal_side: NormalSide | null
    is_synthetic: boolean
    is_active: boolean
    parent_id: number | null
    created_at: string | null
  }
  parent: { id: number; code: string; name: string } | null
  period: { id: number; fiscal_year: number; starts_on: string; ends_on: string; status: string } | null
  from: string
  to: string
  after_closing: boolean
  totals: AccountBalances
  children: AccountDetailChild[]
}

// ── Účetní období (accounting periods) ─────────────────────────────────────
export type PeriodStatus = 'open' | 'closing' | 'closed'

export interface AccountingPeriod {
  id: number
  supplier_id: number
  fiscal_year: number
  starts_on: string
  ends_on: string
  status: PeriodStatus
  closed_at: string | null
  created_at: string
}

export interface CreatePeriodPayload {
  fiscal_year: number
  starts_on: string
  ends_on: string
}

// ── Účetní deník (journal) ─────────────────────────────────────────────────
export type JournalSide = 'debit' | 'credit'

export interface JournalLine {
  id: number
  entry_id: number
  supplier_id: number
  account_id: number
  side: JournalSide
  amount: number
  currency_code: string | null
  fx_rate: number | null
  amount_foreign: number | null
  cost_center: string | null
  line_no: number
  /** Obohaceno v detailu (GET /journal/{id}). */
  account_code?: string | null
  account_name?: string | null
}

export interface JournalEntry {
  id: number
  supplier_id: number
  period_id: number
  entry_date: string
  document_date: string | null
  document_no: string | null
  description: string | null
  source_type: string
  source_id: number | null
  posted_at: string | null
  posted_by: number | null
  reversed_by: number | null
  row_version: number
  created_at: string
  updated_at: string
  /** Obohaceno v listu (LEFT JOIN users na posted_by). */
  posted_by_name?: string | null
  /**
   * Obohaceno v listu (audit 2026-07). Bez filtru account_from/account_to: Σ MD
   * řádků zápisu (= Σ Dal u vyváženého zápisu). S filtrem na účet: absolutní
   * částka připadající na filtrovaný rozsah účtů v TOMTO zápisu (viz amount_side) —
   * jinak by u zápisu s víc nohama na různých účtech sloupec Částka ukazoval součet
   * celého zápisu místo částky vybraného účtu (nález „ČÁSTKA u filtru na účet").
   */
  amount?: number
  /** Strana (MD/D), na kterou `amount` připadá — jen když je aktivní filtr account_from/account_to, jinak null. */
  amount_side?: JournalSide | null
  /** Obohaceno v listu jen pro source_type='bank' — drill-down na bankovní výpis. */
  source_statement_id?: number | null
  /** Obohaceno v listu jen pro source_type='cash' — drill-down na pokladní doklad. */
  source_doc_number?: string | null
  source_register_id?: number | null
  /**
   * Obohaceno v listu pro source_type='asset'/'asset_disposal'/'depreciation' — ID a
   * název karty majetku. U 'depreciation' se dohledá přes depreciation_entries (BE join),
   * protože tam source_id je ID řádku odpisu, NE ID karty (audit 2026-07 follow-up).
   */
  source_asset_id?: number | null
  source_asset_name?: string | null
  /**
   * Obohaceno v listu pro source_type='settlement' — doklad, který zápočet vyrovnal.
   * `source_id` tam totiž nese ID ZÁPOČTU, ne faktury, takže bez tohohle by proklik
   * v deníku vedl na cizí doklad (nebo nikam).
   */
  source_settlement_doc_type?: 'invoice' | 'purchase_invoice' | null
  source_settlement_doc_id?: number | null
  /**
   * Obohaceno v listu — zápis má protějšek v grafu doklad ↔ úhrada (banka/pokladna/
   * zápočet). Podklad pro odznak ve sloupci Zdroj; obsah panelu dodá /journal/{id}/related.
   */
  has_related?: boolean
  automation?: AutomationProvenance | null
  _warnings?: Array<'entry_date_outside_document_year'>
}

/** Doklad, ke kterému se hledá zaúčtování — segment URL /journal/for-document/{source}/{id}. */
export type JournalDocumentSource = 'invoices' | 'purchase-invoices'

/** Zápis i s řádky, jak ho vrací /journal/for-document/{source}/{id}. */
export interface JournalEntryWithLines extends JournalEntry {
  lines: JournalLine[]
}

/** Jeden navržený řádek kontace — MD/DAL s názvem účtu, ať uživatel nepotvrzuje holá čísla. */
export interface PostingPreviewLine {
  account_code: string
  account_name: string | null
  side: 'debit' | 'credit'
  amount: number
  cost_center?: string | null
}

/**
 * Návrh kontace před zaúčtováním.
 *
 * `balanced` je vlastnost návrhu, ne jeho kontrola: nevyvážený návrh by server stejně
 * odmítl, ale uživatel to má vidět dřív, než klikne.
 */
export interface PostingPreview {
  source_type: 'invoice' | 'purchase_invoice'
  doc_id: number
  entry_date: string
  lines: PostingPreviewLine[]
  balanced: boolean
  /** Účet z AI klasifikace, pokud ji doklad má — návrh z ní vychází. */
  ai_override: string | null
  /** Id existujícího zápisu, když je doklad už zaúčtovaný. */
  already_posted: number | null
  /**
   * Podklad pro „příště účtovat stejně". `null` = pravidlo by se muselo hádat
   * (neznámý dodavatel nebo doklad míchá druhy výdaje) a nenabízí se.
   */
  rule_basis: { vendor_client_id: number; vendor_name: string; expense_kind: string } | null
}

export interface JournalEntryDetail extends JournalEntry {
  lines: JournalLine[]
  /** Měkké vazby na doklady (migrace 1514); chodí s detailem zápisu. */
  links?: JournalDocumentLink[]
}

// ── Měkká vazba zápisu na doklad (migrace 1514) ────────────────────────────
/**
 * Typ dokladu, na který lze zápis navázat. ZÁMĚRNĚ se nepromítá do
 * `source_type` zápisu: ta dvojice (source_type, source_id) znamená „zápis JE
 * zaúčtování dokladu" a drží na ní idempotence deníku. Vazba je informativní.
 */
export type LinkableDocType = 'invoice' | 'purchase_invoice' | 'cash' | 'bank'

export interface JournalDocumentLink {
  id: number
  entry_id: number
  doc_type: LinkableDocType
  doc_id: number
  note: string | null
  created_by: number | null
  created_by_name: string | null
  created_at: string
  /** Popis navázaného dokladu; null = doklad byl mezitím smazán. */
  document?: JournalRelatedItem | null
}

/** Kandidát našeptávače „navázat doklad". */
export interface LinkCandidate {
  doc_type: LinkableDocType
  doc_id: number
  label: string
  sublabel: string | null
  date: string | null
  amount: number | null
  currency: string
}

export interface JournalLinkPayload {
  doc_type: LinkableDocType
  doc_id: number
  note?: string
}

// ── Přílohy účetního zápisu §33a (Epic F7) ─────────────────────────────────
export interface JournalAttachment {
  id: number
  entry_id: number
  supplier_id: number
  sha256: string
  filename: string
  original_name: string | null
  mime_type: string | null
  size_bytes: number | null
  doc_type: string | null
  /** §33a popisek přílohy — inline editovatelný. */
  description: string | null
  uploaded_by: number | null
  uploaded_by_name?: string | null
  uploaded_at: string
}

// ── Poznámky k účetnímu zápisu (1:N) ───────────────────────────────────────
// Doplněk jednořádkového `description` (§35): poznámky jdou psát i u zápisů,
// kde je popis řízený zdrojovým dokladem a editovat ho nelze.
export interface JournalNote {
  id: number
  entry_id: number
  supplier_id: number
  body: string
  pinned: boolean
  created_by: number | null
  created_by_name: string | null
  created_at: string
  updated_by: number | null
  updated_by_name: string | null
  updated_at: string | null
}

// ── Náhled zdrojového dokladu pro drawer v deníku ───────────────────────────
export type SourceFieldFormat =
  | 'text' | 'number' | 'currency' | 'date' | 'percent' | 'bool' | 'doc_ref'

export interface SourceField {
  key: string
  label_key: string
  value: unknown
  format: SourceFieldFormat
}

export interface SourceColumn {
  key: string
  label_key: string
  format: SourceFieldFormat
  align?: 'left' | 'right' | 'center'
}

export interface SourceTableBlock {
  key: string
  title_key: string
  type: 'table'
  columns: SourceColumn[]
  rows: Record<string, unknown>[]
  /** Celkový počet řádků v DB — s `truncated` dovolí napsat „zobrazeno 50 ze 120". */
  total_rows: number
  truncated: boolean
  currency?: string | null
}

export interface SourceKeyValueBlock {
  key: string
  title_key: string
  type: 'keyvalue'
  items: SourceField[]
  currency?: string | null
}

export type SourceBlock = SourceTableBlock | SourceKeyValueBlock

/** Cíl prokliku. Skalární params/query, ať to jde rovnou do vue-router bez castu. */
export interface SourceRoute {
  name: string
  params?: Record<string, string | number>
  query?: Record<string, string | number>
}

export interface SourceAction {
  key: string
  /** Právo, které FE ověří přes auth.canRead() než tlačítko vykreslí. */
  permission: string
  route?: SourceRoute
  href?: string
}

// ── Protějšky zápisu: doklad ↔ úhrada (banka / pokladna / zápočet) ──────────
/**
 * Jedna položka panelu „Souvisí". `relation` říká, na které straně případu protějšek
 * stojí: 'payment' = úhrada tohoto dokladu, 'document' = doklad, který tento pohyb hradí,
 * 'linked_document' = doklad ručně navázaný na tenhle zápis a 'linked_entry' = opačný
 * směr téže ruční vazby, tedy zápis, který si tenhle doklad navázal (migrace 1514).
 */
export interface JournalRelatedItem {
  relation: 'payment' | 'document' | 'linked_document' | 'linked_entry'
  source_type: 'invoice' | 'purchase_invoice' | 'bank' | 'cash' | 'settlement' | 'journal_entry'
  source_id: number
  /** Právo, které FE ověří přes auth.canRead() než vykreslí proklik na doklad. */
  permission: string
  title: string | null
  subtitle: string | null
  date: string | null
  amount: number | null
  /** Částka alokovaná na tuhle vazbu — liší se od `amount` u splátek a souhrnných plateb. */
  allocated_amount: number | null
  currency: string | null
  route: SourceRoute | null
  /** Zaúčtování protějšku; null = doklad ještě zaúčtovaný není. */
  entry_id: number | null
  entry_date: string | null
  entry_document_no: string | null
  entry_posted: boolean
}

export interface JournalRelated {
  entry_id: number
  items: JournalRelatedItem[]
  truncated: boolean
}

export interface JournalSourceSummary {
  entry_id: number
  source_type: string
  source_id: number | null
  available: boolean
  /** Proč není náhled — 'synthetic_source_id' | 'no_source' | 'not_found' | 'no_preview'. */
  unavailable_reason?: string
  title: string | null
  subtitle: string | null
  status: { key: string; variant: string } | null
  currency: string | null
  fields: SourceField[]
  blocks: SourceBlock[]
  route: SourceRoute | null
  actions: SourceAction[]
}

/**
 * source_type zápisů, jejichž `description` LZE inline editovat (§5.4).
 * Ostatní (invoice/purchase_invoice/bank/cash/asset/…) jsou řízeny zdrojovým
 * dokladem → backend vrátí 409 `description_managed_by_source`, FE edit skryje.
 */
export const EDITABLE_DESCRIPTION_SOURCE_TYPES = ['manual', 'closing', 'opening'] as const
export function isDescriptionEditable(sourceType: string | null | undefined): boolean {
  return (EDITABLE_DESCRIPTION_SOURCE_TYPES as readonly string[]).includes(sourceType ?? '')
}

export interface JournalListResponse {
  items: JournalEntry[]
  total: number
  page: number
  per_page: number
}

// ── Historie zápisu — SYSTEM VERSIONING timeline (audit 2026-07) ───────────
export interface JournalHistoryLine {
  account_code: string | null
  account_name: string | null
  side: JournalSide
  amount: number
  cost_center: string | null
  line_no: number
}

export interface JournalHistoryHeader {
  entry_date: string
  document_date: string | null
  document_no: string | null
  description: string | null
  source_type: string
  source_id: number | null
  posted_at: string | null
  posted_by: number | null
  posted_by_name: string | null
  reversed_by: number | null
}

export interface JournalHistoryFieldDiff {
  before: unknown
  after: unknown
}

export interface JournalHistoryLineChange {
  type: 'added' | 'removed' | 'changed'
  line_no: number
  line?: JournalHistoryLine
  before?: JournalHistoryLine
  after?: JournalHistoryLine
}

export interface JournalHistoryChangedBy {
  action: string
  user_id: number | null
  user_name: string | null
  created_at: string
}

export interface JournalHistoryVersion {
  version: number
  is_current: boolean
  valid_from: string
  valid_to: string | null
  header: JournalHistoryHeader
  lines: JournalHistoryLine[]
  header_changes: Record<string, JournalHistoryFieldDiff> | null
  line_changes: JournalHistoryLineChange[] | null
  changed_by: JournalHistoryChangedBy | null
}

export interface JournalHistoryActivityEntry {
  id: number
  user_id: number | null
  user_name: string | null
  action: string
  payload: unknown
  created_at: string
}

export interface JournalHistoryResponse {
  entry_id: number
  versions: JournalHistoryVersion[]
  activity: JournalHistoryActivityEntry[]
}

export interface JournalFilters {
  document_no?: string
  period_id?: number
  date_from?: string
  date_to?: string
  source_type?: string
  source_id?: number
  /** Přesný odskok na jeden zápis (deep-link ?entry_id=), ne jen zúžení data. */
  entry_id?: number
  posted?: boolean
  automation?: 'auto' | 'approved' | 'manual'
  /** Fulltext (popis + čísla dokladů) — Featura D, audit 2026-07 follow-up. */
  q?: string
  account_from?: string
  account_to?: string
  amount_from?: number
  amount_to?: number
  /** Jen zápisy s nálezem noční kontroly integrity deníku (JournalIntegrityService). */
  integrity?: 'amount_mismatch'
  page?: number
  per_page?: number
}

export interface ManualLinePayload {
  account_code: string
  side: JournalSide
  amount: number
  cost_center?: string
}

/** Tělo zaúčtování dokladu. `lines` = kontace upravená v popupu; bez nich staví server. */
export interface PostDocumentPayload {
  entry_date?: string
  description?: string
  document_no?: string
  lines?: { account_code: string; side: JournalSide; amount: number }[]
}

/** Odpověď „Zeptat se AI na kontaci". Vrací JEN nákladový účet — protistrana je daná. */
export interface PurchaseAiSuggestion {
  suggestion_id: number
  debit_account_code: string
  reasoning: string
  confidence: number
}

export interface ManualEntryPayload {
  entry_date: string
  description?: string
  document_no?: string
  lines: ManualLinePayload[]
  /** Doklady, se kterými zápis souvisí. Neplatná vazba zápis vůbec nezaloží. */
  links?: JournalLinkPayload[]
}

// ── Šablony ručních zápisů (Fáze F, mzdový můstek, audit 2026-07) ──────────
export interface JournalTemplateLine {
  line_no: number
  label: string | null
  account_code: string
  side: JournalSide
  default_amount: number | null
  cost_center: string | null
}

export interface JournalTemplateSummary {
  id: number
  name: string
  description: string | null
  is_seeded: boolean
  line_count: number
  created_at: string
}

export interface JournalTemplateDetail {
  id: number
  supplier_id: number
  name: string
  description: string | null
  is_seeded: boolean
  created_at: string
  lines: JournalTemplateLine[]
}

export interface JournalTemplateLinePayload {
  account_code: string
  side: JournalSide
  amount?: number | null
  label?: string | null
  cost_center?: string | null
}

export interface CreateJournalTemplatePayload {
  name: string
  description?: string | null
  lines: JournalTemplateLinePayload[]
}

// ── Firemní číselník středisek ─────────────────────────────────────────────
export interface CostCenter {
  id: number
  supplier_id: number
  code: string
  name: string
  is_active: boolean
  created_at: string
  updated_at: string
}

export interface CostCenterPayload {
  code: string
  name: string
}

/** Náhled napárování CSV rekapitulace na řádky šablony — nic se nezapisuje do DB. */
export interface TemplateCsvMatchResult {
  lines: Array<{
    line_no: number
    label: string | null
    account_code: string
    side: JournalSide
    cost_center: string | null
    amount: number | null
  }>
  unmatched: Array<{ value: string; amount: number }>
  matched_count: number
}

// ── Kontační pravidla (posting rules) ──────────────────────────────────────
export interface PostingRule {
  id: number
  supplier_id: number | null
  rule_key: string
  description: string
  debit_account_code: string | null
  credit_account_code: string | null
  priority: number
  is_active: boolean
}

/** GET /posting-rules vrací objekt keyed rule_key → pravidlo (prázdné → {}). */
export type PostingRuleMap = Record<string, PostingRule>

export interface PostingRulePayload {
  debit_account_code?: string | null
  credit_account_code?: string | null
}

// ── Mzdová rekapitulace (Fáze F) ───────────────────────────────────────────
/** 521/331 zaměstnanec vs. 522/366 jednatel-společník. */
export type PayrollTaxpayerType = 'employee' | 'managing_partner'

/**
 * Pracovní poměr / dohoda o provedení práce / dohoda o pracovní činnosti /
 * smlouva o výkonu funkce (§ 59 ZOK, migrace 1302).
 *
 * `statutory_body` má SHODNÝ klíč jako `relation_type` v novějším mzdovém modulu
 * (`PayrollRelationType` v `api/payroll.ts`) — jeden právní pojem, jeden identifikátor.
 */
export type PayrollEmploymentType = 'hpp' | 'dpp' | 'dpc' | 'statutory_body'

/** Rozpad hrubé mzdy — všechny částky v celých Kč. */
export interface PayrollBreakdown {
  gross: number
  minimum_wage: number
  /** Vyměřovací základ ZP = max(hrubá, minimální mzda). */
  assessment_base: number
  employee_social: number
  employee_health: number
  /** Doplatek ZP do minimálního vyměřovacího základu — hradí zaměstnanec. */
  health_min_topup: number
  employee_deductions: number
  /** Základ pro zálohu na daň — nad 100 Kč zaokrouhlený na stokoruny nahoru (§38h odst. 1). */
  tax_base: number
  /** Měsíční hranice vyšší sazby = 3× průměrná mzda (§38h odst. 2). */
  tax_high_threshold: number
  /** Část základu nad hranicí, zdaněná 23 % — nula u běžných mezd. */
  tax_high_base: number
  /** Záloha PŘED slevami — kolik by se srazilo bez podepsaného prohlášení. */
  advance_tax: number
  credit_taxpayer: number
  credit_children: number
  credit_total: number
  /** Co se reálně srazí a odvede na FÚ (účtuje se na 342) — záloha po slevách. */
  advance_tax_withheld: number
  net: number
  employer_social: number
  employer_health: number
  employer_total: number
  /** Úhrn ZP (zaměstnanec + doplatek + zaměstnavatel) = platba na zdravotní pojišťovnu. */
  health_total: number
  /** Úhrn SP (zaměstnanec + zaměstnavatel) = platba na OSSZ. */
  social_total: number
  /** ZP + SP + sražená záloha = součet hromadného příkazu k úhradě. */
  remittance_total: number
  super_gross: number
}

export interface PayrollPreviewLine {
  account_code: string
  side: JournalSide
  amount: number
  description?: string
}

export interface PayrollPreview {
  year: number
  month: number
  /** RRRRMM — klíč idempotence zaúčtování. */
  source_id: number
  entry_date: string
  taxpayer_type: PayrollTaxpayerType
  taxpayer_credit: boolean
  child_count: number
  credits: { taxpayer: number; children: number; total: number }
  breakdown: PayrollBreakdown
  lines: PayrollPreviewLine[]
}

export interface PayrollPostResult {
  journal_entry_id: number
  source_id: number
  breakdown: PayrollBreakdown
}

export interface PayrollPayload {
  year: number
  month: number
  gross: number
  taxpayer_type: PayrollTaxpayerType
  /**
   * Volitelná vazba na zaměstnance (mzdový list, §38j). Je-li zadaná, jeho evidované
   * prohlášení a počet dětí PŘEBIJÍ `taxpayer_credit` / `child_count` níž.
   */
  employee_id?: number | null
  /** Podepsané prohlášení poplatníka (§38k) → měsíční sleva na poplatníka. Default true. */
  taxpayer_credit?: boolean
  /** Počet vyživovaných dětí pro daňové zvýhodnění (§35c). */
  child_count?: number
}

// ── Zaměstnanci pro mzdový list (§38j ZDP) ─────────────────────────────────
export interface PayrollEmployee {
  id: number
  supplier_id: number
  full_name: string
  birth_date: string | null
  /*
   * `birth_number` ani `address` tu ZÁMĚRNĚ nejsou (W1/P-02). Legacy routa je
   * nečte ani nezapisuje — je chráněná jen právem `accounting`, takže by
   * otevřené rodné číslo obešlo šifrovanou evidenci `payroll_person_identifiers`
   * i stopu o odhalení. Vyplněná hodnota v požadavku vrací 422. Rodné číslo
   * v maskovaném tvaru vydává jen mzdová karta osoby.
   */
  taxpayer_type: PayrollTaxpayerType
  tax_credit_taxpayer: boolean
  /**
   * § 38k odst. 4 ZDP — poplatník má u TOHOTO plátce podepsané prohlášení k dani.
   * Bez něj se měsíční sleva uplatnit nesmí, i když na ni jinak nárok má
   * (`tax_credit_taxpayer`); jsou to dvě různé podmínky a musí platit obě.
   */
  tax_declaration_signed: boolean
  /** Pracovněprávní vztah (migrace 1156) — řídí režim pojistného a srážkové daně (§6/4). */
  employment_type: PayrollEmploymentType
  child_count: number
  /**
   * Účet, na který se měsíčně přeúčtuje čistá mzda (migrace 1178) — typicky 365.x,
   * tedy zápočet proti účtu společníka, když se odměna reálně nevyplácí.
   * `null` = čistá mzda zůstane viset jako závazek na 331/366 (výchozí chování).
   * Peněžní účty (21x/22x/26x) backend odmítá — ty patří pokladnímu dokladu a bance.
   */
  net_settlement_account_code: string | null
  /**
   * Pravidelná měsíční hrubá mzda v celých Kč (migrace 1175). `null` = nesjednaná,
   * což je jiný stav než 0 — bez ní se `auto_post` zapnout nedá.
   */
  monthly_gross: number | null
  /** Účtovat mzdu měsíčně automaticky cronem `cron-payroll-post`. */
  auto_post: boolean
  is_active: boolean
  created_at: string
  updated_at: string
}

export type PayrollEmployeePayload = Omit<PayrollEmployee,
  'id' | 'supplier_id' | 'created_at' | 'updated_at'>

/**
 * Uložení karty. `warnings` jsou upozornění, která uložení NEBLOKUJÍ (nesourodá
 * kombinace vztahu a typu poplatníka) — chyby chodí jako 422, ne tudy.
 */
export interface PayrollEmployeeSaveResult {
  employee: PayrollEmployee
  warnings: string[]
}

// ── Účetní sestavy (Epic F2) ───────────────────────────────────────────────
export interface ReportPeriod {
  id: number
  fiscal_year: number
  starts_on: string
  ends_on: string
}

export interface LedgerReportParams {
  period_id: number
  from?: string
  to?: string
  analytics?: 0 | 1
  /** Hledání dle dodavatele (přijaté faktury) — jen General ledger. */
  vendor?: string
  /** Hledání dle odběratele (vydané faktury) — jen General ledger. */
  client?: string
  /** Hledání dle textu položky faktury (vydané i přijaté) — jen General ledger. */
  item?: string
  /**
   * Stav PO uzavření knih. Výchozí (nezadáno) je stav PŘED uzavřením — po uzavření
   * jsou rozvahové účty převedené na 702 a konečné stavy vycházejí nulové, takže
   * účetní by k rozvahovému dni neviděla žádné zůstatky.
   */
  after_closing?: 0 | 1
}

export interface GeneralLedgerAccount {
  account_id: number
  account_code: string
  name: string
  account_type: AccountType
  is_synthetic: boolean
  opening_md: number
  opening_d: number
  /** Měsíční obraty keyed 'YYYY-MM' (jen měsíce s pohybem). */
  months: Record<string, { md: number; d: number }>
  turnover_md: number
  turnover_d: number
  closing_md: number
  closing_d: number
}

export interface GeneralLedgerReport {
  period: ReportPeriod
  from: string
  to: string
  analytics: boolean
  vendor?: string | null
  client?: string | null
  item?: string | null
  draft_count: number
  months: string[]
  accounts: GeneralLedgerAccount[]
  totals: {
    opening_md: number
    opening_d: number
    turnover_md: number
    turnover_d: number
    closing_md: number
    closing_d: number
  }
}

export interface TrialBalanceRow {
  account_id: number
  account_code: string
  name: string
  account_type: AccountType
  ps_md: number
  ps_d: number
  turnover_md: number
  turnover_d: number
  ks_md: number
  ks_d: number
}

export interface TrialBalanceChecks {
  turnover_balanced: boolean
  journal_turnover_md: number
  journal_turnover_d: number
  matches_journal: boolean
  opening_balanced: boolean
}

export interface TrialBalanceReport {
  period: ReportPeriod
  from: string
  to: string
  draft_count: number
  rows: TrialBalanceRow[]
  totals: {
    ps_md: number
    ps_d: number
    turnover_md: number
    turnover_d: number
    ks_md: number
    ks_d: number
  }
  checks: TrialBalanceChecks
}

export interface BalanceInventoryRow {
  account_id: number
  account_code: string
  name: string
  account_type: AccountType
  normal_side: NormalSide | null
  ks_md: number
  ks_d: number
  documentation_hint: string
  /** EP-6: znaménkový účetní zůstatek (ks_md − ks_d) = báze pro inventurní rozdíl. */
  book_balance?: number
  /** EP-6: uložený skutečný (napočítaný) stav; null = dosud nenapočítáno. */
  counted_balance?: number | null
  /** EP-6: counted − book; null když nenapočítáno. */
  difference?: number | null
  /** EP-6: stav vyřešení rozdílu — 'resolved' potvrzeno účetní. */
  resolution?: 'open' | 'resolved'
  item_note?: string | null
  /** EP-6: účet je vyřešený (potvrzen nebo diff=0) — nevyřešené blokují uzavření knih. */
  resolved?: boolean
  /** Skutečný stav odvozen z účetního u uzavřeného období (uzávěrka už zůstatky ověřila). */
  back_filled?: boolean
}

/** EP-6: hlavička uložené inventarizace rozvahových účtů. */
export interface BalanceInventoryHeader {
  status: 'in_progress' | 'completed'
  responsible_person: string | null
  inventory_date: string | null
  protocol_ref: string | null
  note: string | null
  item_count: number
  unresolved_count: number
  completed_at?: string | null
  completed?: boolean
  can_close?: boolean
  /** Inventura byla dopočtena z účetních zůstatků uzavřeného období (žádná ruční inventura uložena). */
  back_filled?: boolean
}

/** Jeden pohyb peněžních prostředků zařazený podle protiúčtu (§ 40–43 vyhl. 500/2002). */
export interface CashFlowBreakdownRow {
  account_code: string
  name: string
  amount: number
}

/**
 * § 18 odst. 2 ZoÚ — přehled o peněžních tocích a o změnách vlastního kapitálu.
 *
 * `reconciles` není kosmetika: přehled o peněžních tocích se sestavuje PŘÍMOU
 * klasifikací pohybů, takže součet toků se rovná skutečné změně stavu peněz
 * konstrukčně. Když nesedí, je vada v datech, ne ve výkazu.
 */
export interface Section18Statements {
  cash_flow: {
    period: ReportPeriod
    opening: number
    closing: number
    net_change: number
    operating: number
    investing: number
    financing: number
    unclassified: number
    reconciles: boolean
    breakdown: {
      operating: CashFlowBreakdownRow[]
      investing: CashFlowBreakdownRow[]
      financing: CashFlowBreakdownRow[]
      unclassified: CashFlowBreakdownRow[]
    }
  }
  equity: {
    period: ReportPeriod
    rows: Array<{
      account_code: string
      name: string
      opening: number
      increase: number
      decrease: number
      closing: number
    }>
    totals: { opening: number; increase: number; decrease: number; closing: number }
    reconciles: boolean
  }
  category: string
  /** Povinnost sestavit oba přehledy (velká/střední ÚJ nebo povinný audit). */
  required: boolean
}

export interface BalanceInventoryReport {
  period: ReportPeriod
  as_of: string
  entity: { name: string; ico: string | null; address: string; prepared_at: string }
  draft_count: number
  rows: BalanceInventoryRow[]
  count: number
  totals: {
    ks_md: number
    ks_d: number
  }
  /** EP-6: přítomné jen u náhledu z uzávěrky (buildWithSaved). */
  inventory?: BalanceInventoryHeader
  row_version?: number
}

/** EP-6: tělo uložení inventarizace (POST /closing/inventory). */
export interface BalanceInventorySavePayload {
  row_version: number
  responsible_person: string | null
  inventory_date: string | null
  protocol_ref: string | null
  note: string | null
  complete: boolean
  items: Array<{
    account_id: number
    counted_balance: number | null
    resolution: 'open' | 'resolved'
    note: string | null
  }>
}

export interface BalanceInventorySaveResult {
  status: 'in_progress' | 'completed'
  unresolved_count: number
  item_count: number
  completed: boolean
  ok: boolean
  row_version: number
}

export interface AccountStatementItem {
  entry_id: number
  entry_date: string
  document_no: string | null
  description: string | null
  source_type: string
  source_id: number | null
  side: JournalSide
  amount: number
  balance: number
  /** Účet ŘÁDKU — u syntetiky je opis složený z jejích analytik. */
  account_id: number
  account_code: string
  account_name: string
  /** Obohacení pro drill-down na prvotní doklad — viz utils/journalSourceLink.ts. */
  source_statement_id: number | null
  source_doc_number: string | null
  source_register_id: number | null
  source_asset_id: number | null
  source_asset_name: string | null
  source_settlement_doc_type: string | null
  source_settlement_doc_id: number | null
}

export interface AccountStatementReport {
  account: {
    id: number
    code: string
    name: string
    type: AccountType
    normal_side: NormalSide | null
  }
  opening_balance: number
  items: AccountStatementItem[]
  total: number
  page: number
  per_page: number
  closing_balance: number
  turnover_md: number
  turnover_d: number
}

export type StatementScope = 'auto' | 'full' | 'small' | 'micro'
export type EffectiveScope = 'full' | 'small' | 'micro'

export interface StatementParams {
  period_id: number
  as_of?: string
  scope?: StatementScope
}

export interface StatementRowAccount {
  account_id: number
  account_code: string
  name: string
  amount: number
  target: 'gross' | 'correction'
}

export type StatementRowType = 'detail' | 'subtotal' | 'computed'

export interface StatementEntity {
  name: string
  ico: string | null
  address: string | null
  legal_form: string | null
  prepared_at: string
}

export interface BalanceSheetAssetRow {
  row_code: string
  display_code: string
  label: string
  level: number
  row_type: StatementRowType
  gross: number
  correction: number
  net: number
  prev_net: number
  accounts: StatementRowAccount[]
}

export interface StatementRow {
  row_code: string
  display_code: string
  label: string
  level: number
  row_type: StatementRowType
  amount: number
  prev_amount: number
  accounts: StatementRowAccount[]
}

export interface BalanceSheetReport {
  statement_type: 'balance_sheet'
  version_code: string
  as_of: string
  scope: EffectiveScope
  entity: StatementEntity
  period: ReportPeriod
  prev_period: ReportPeriod | null
  assets: BalanceSheetAssetRow[]
  liabilities: StatementRow[]
  checks: {
    assets_net: number
    liabilities_total: number
    balanced: boolean
  }
}

// ── Účelové členění VZZ (vyhl. 500/2002 Sb., př. 2 část II, § 39b) ─────────
/** Funkce, do které náklad patří — řádky A. / B. / C. účelového výkazu. */
export type StatementFunctionCode = 'cost_of_sales' | 'distribution' | 'administration'

export interface StatementFunctionMapping {
  account_prefix: string
  function_code: StatementFunctionCode
  note: string | null
  updated_at: string
}

/** Nákladový účet s obratem, kterému přiřazení funkci chybí — brání sestavení výkazu. */
export interface UnassignedExpenseAccount {
  account_code: string
  name: string
  turnover: number
}

export interface StatementFunctionMap {
  functions: StatementFunctionCode[]
  rows: StatementFunctionMapping[]
  unassigned: UnassignedExpenseAccount[]
}

export interface IncomeStatementReport {
  statement_type: 'income_statement' | 'income_statement_purpose'
  version_code: string
  as_of: string
  scope: EffectiveScope
  entity: StatementEntity
  period: ReportPeriod
  prev_period: ReportPeriod | null
  rows: StatementRow[]
  checks: {
    profit_current: number
    net_turnover: number
  }
}

// ── Saldokonto (audit 2026-07, D6/1) ───────────────────────────────────────
export interface SaldoParams {
  period_id: number
  as_of?: string
  /** Kód účtu (311/321/…) nebo 'all' pro default sadu. */
  account?: string
  partner_id?: number
}

export interface SaldoItem {
  doc_type: 'invoice' | 'purchase_invoice'
  doc_id: number
  doc_no: string
  issue_date: string
  due_date: string
  currency_code: string
  amount_foreign: number
  booked_czk: number
  paid_czk: number
  remaining_czk: number
  days_overdue: number
}

export interface SaldoPartner {
  partner_id: number
  partner_name: string
  total_remaining: number
  items: SaldoItem[]
}

export interface SaldoAccountBlock {
  account: { id: number; code: string; name: string; normal_side: NormalSide }
  gl_balance: number
  open_items_total: number
  difference: number
  matches: boolean
  partners: SaldoPartner[]
}

/** Období vč. stavu (report ReportPeriod status nenese — saldokonto ho pro UI hint potřebuje). */
export interface SaldoPeriodInfo extends ReportPeriod {
  status: string
}

export interface SaldoReport {
  as_of: string
  entity: { name: string; ico: string | null; address: string; prepared_at: string }
  period: SaldoPeriodInfo
  /** Období, do kterého as_of skutečně spadá — liší se od `period`, když si uživatel zvolí datum mimo vybrané období z dropdownu (task #3, D6/2). null = pro as_of není založené žádné období. */
  as_of_period: SaldoPeriodInfo | null
  accounts: SaldoAccountBlock[]
}

export type EntityCategoryCode = 'micro' | 'small' | 'medium' | 'large'

export interface EntityCategory {
  category: EntityCategoryCode
  raw_current: EntityCategoryCode
  raw_previous: EntityCategoryCode | null
  criteria: {
    assets_net: number
    net_turnover: number
    employees: number
  }
  thresholds: Record<string, unknown>
  scope: EffectiveScope
  scope_override: string | null
}

export interface ReportingSettings {
  avg_employees: number | null
  statement_scope_override: EffectiveScope | null
}

/**
 * Chybové kódy z PostingService/JournalAction dosažitelné při zaúčtování dokladu
 * (postInvoice/postPurchase) → mají srozumitelnou hlášku v `accounting.posting_errors.*`.
 * Neznámý kód spadne na `accounting.posting_errors.generic`.
 */
export const POSTING_ERROR_CODES = [
  'not_found', 'entry_not_found', 'document_not_postable', 'advance_payment_only', 'missing_exchange_rate',
  'no_accounting_period', 'period_not_open', 'unbalanced_entry', 'unknown_account',
  'entry_reversed', 'empty_entry',
  // Vyúčtování zálohy má víc kandidátů → nelze jednoznačně odečíst zálohovou DPH.
  'advance_settlement_ambiguous',
  // DDKP (daňový doklad k platbě) v reverse-charge režimu se automaticky neúčtuje.
  'ddkp_reverse_charge_unsupported',
] as const

/** Vrátí i18n klíč pro hlášku k chybovému kódu zaúčtování (fallback = generic). */
export function postingErrorI18nKey(code: string | null | undefined): string {
  return (POSTING_ERROR_CODES as readonly string[]).includes(code ?? '')
    ? `accounting.posting_errors.${code}`
    : 'accounting.posting_errors.generic'
}

/**
 * Souhrnný report hromadného zaúčtování (A2) — kolik dokladů se zaúčtovalo (`posted`)
 * a které selhaly (`failed`, s per-doklad strojovým kódem přeložitelným přes
 * {@see postingErrorI18nKey}). Jeden neúspěšný doklad nezablokuje zbytek dávky.
 */
export interface BulkPostReport {
  posted: number[]
  failed: Array<{ id: number; error_code: string; message: string }>
}

// ── Vzájemné zápočty (Fáze F) ──────────────────────────────────────────────
export type OffsetOpenItem = {
  doc_type: 'invoice' | 'purchase_invoice'
  doc_id: number
  doc_no: string
  issue_date: string
  due_date: string
  total: number
  paid: number
  remaining: number
}
export type OffsetOpenResult = {
  partner_id: number
  partner_name: string
  receivables: OffsetOpenItem[]
  payables: OffsetOpenItem[]
}
export type OffsetPartner = { partner_id: number; partner_name: string }
export type OffsetStatus = 'draft' | 'confirmed' | 'cancelled'
export type OffsetAgreement = {
  id: number
  supplier_id: number
  partner_id: number
  partner_name: string
  agreement_date: string
  document_no: string
  total_amount: number
  status: OffsetStatus
  journal_entry_id: number | null
  note: string | null
  created_at: string
}
export type OffsetItem = {
  id: number
  doc_type: 'invoice' | 'purchase_invoice'
  doc_id: number
  doc_no: string
  amount: number
  invoice_payment_id: number | null
}
export type OffsetDetail = {
  agreement: OffsetAgreement
  receivables: OffsetItem[]
  payables: OffsetItem[]
}
export type OffsetCreatePayload = {
  partner_id: number
  agreement_date: string
  note?: string | null
  items: { doc_type: 'invoice' | 'purchase_invoice'; doc_id: number; amount: number }[]
}

// ── Kurzový režim firmy (§24/7 ZoÚ — Fáze F) ───────────────────────────────
export type FxRateMode = 'daily' | 'fixed_monthly' | 'fixed_annual'
export type FixedRate = {
  id: number
  currency_code: string
  fiscal_year: number
  month: number
  rate: number
  source: string
  updated_at: string
}
export type FxRateSettings = { mode: FxRateMode; rates: FixedRate[] }

/** Repo sazba ČNB (číselník pro úrok z prodlení, NV 351/2013). */
export type RepoRate = {
  valid_from: string
  rate: number
  note: string | null
  updated_at: string
}

export type SettlementDocType = 'invoice' | 'purchase_invoice'

/** Úhrada faktury zápočtem proti zvolenému rozvahovému účtu (migrace 1126). */
export interface InvoiceSettlement {
  id: number
  doc_type: SettlementDocType
  doc_id: number
  settled_on: string
  amount: number
  account_id: number
  account_code: string
  account_name: string
  note: string | null
  status: 'confirmed' | 'cancelled'
  journal_entry_id: number | null
  reversal_entry_id: number | null
  created_at: string
}

/** Předvolba protiúčtu z posting rule; account_id === null = účet v osnově chybí. */
export interface SettlementDefaultAccount {
  account_id: number | null
  account_code: string
  account_name: string | null
}

export interface CreateSettlementPayload {
  doc_type: SettlementDocType
  doc_id: number
  settled_on: string
  amount: number
  account_id: number
  note?: string | null
}

export const accountingApi = {
  // Účtová osnova
  listAccounts: (opts?: { tree?: boolean; includeInactive?: boolean }) => {
    const params: Record<string, string> = {}
    if (opts?.tree) params.tree = '1'
    if (opts?.includeInactive) params.include_inactive = '1'
    return api.get<ChartAccount[]>('/accounting/accounts', { params }).then(r => r.data)
  },
  /** Karta účtu: kmen + analytiky se zůstatky + PS/obraty/KS za rozsah. */
  getAccountDetail: (id: number, params?: { from?: string; to?: string; after_closing?: 0 | 1 }) =>
    api.get<AccountDetailReport>(`/accounting/accounts/${id}`, { params }).then(r => r.data),
  createAccount: (payload: CreateAccountPayload) =>
    api.post<ChartAccount>('/accounting/accounts', payload).then(r => r.data),
  updateAccount: (id: number, payload: UpdateAccountPayload) =>
    api.patch<ChartAccount>(`/accounting/accounts/${id}`, payload).then(r => r.data),
  /** Smaže analytiku bez pohybů — kód účtu nejde přejmenovat, tohle je jediná oprava překlepu. */
  deleteAccount: (id: number) =>
    api.delete<{ deleted: boolean }>(`/accounting/accounts/${id}`).then(r => r.data),

  // Účetní období
  listPeriods: () => api.get<AccountingPeriod[]>('/accounting/periods').then(r => r.data),
  createPeriod: (payload: CreatePeriodPayload) =>
    api.post<AccountingPeriod>('/accounting/periods', payload).then(r => r.data),
  setPeriodStatus: (id: number, status: PeriodStatus) =>
    api.post<AccountingPeriod>(`/accounting/periods/${id}/status`, { status }).then(r => r.data),

  // Deník
  listJournal: (filters?: JournalFilters) => {
    const params: Record<string, string | number> = {}
    if (filters?.document_no) params.document_no = filters.document_no
    if (filters?.period_id) params.period_id = filters.period_id
    if (filters?.date_from) params.date_from = filters.date_from
    if (filters?.date_to) params.date_to = filters.date_to
    if (filters?.source_type) params.source_type = filters.source_type
    if (filters?.source_id) params.source_id = filters.source_id
    if (filters?.entry_id) params.entry_id = filters.entry_id
    if (filters?.posted !== undefined) params.posted = filters.posted ? '1' : '0'
    if (filters?.automation) params.automation = filters.automation
    if (filters?.q) params.q = filters.q
    if (filters?.account_from) params.account_from = filters.account_from
    if (filters?.account_to) params.account_to = filters.account_to
    if (filters?.amount_from !== undefined) params.amount_from = filters.amount_from
    if (filters?.amount_to !== undefined) params.amount_to = filters.amount_to
    if (filters?.integrity) params.integrity = filters.integrity
    if (filters?.page) params.page = filters.page
    if (filters?.per_page) params.per_page = filters.per_page
    return api.get<JournalListResponse>('/accounting/journal', { params }).then(r => r.data)
  },
  getEntry: (id: number) =>
    api.get<JournalEntryDetail>(`/accounting/journal/${id}`).then(r => r.data),
  createEntry: (payload: ManualEntryPayload) =>
    api.post<JournalEntryDetail>('/accounting/journal', payload).then(r => r.data),
  // Bez `body.lines` si server kontaci postaví sám (ověřená cesta). S `lines` se zaúčtuje
  // přesně to, co účetní v popupu upravila — návrh je návrh, ne verdikt.
  postInvoice: (id: number, body?: PostDocumentPayload) =>
    api.post<JournalEntryDetail>(`/accounting/journal/post-invoice/${id}`, body ?? {}).then(r => r.data),
  postPurchase: (id: number, body?: PostDocumentPayload) =>
    api.post<JournalEntryDetail>(`/accounting/journal/post-purchase/${id}`, body ?? {}).then(r => r.data),
  // „Zeptat se AI na kontaci" u přijaté faktury — protějšek bankovního ai-suggest.
  purchaseAiSuggest: (id: number, query: string) =>
    api.post<PurchaseAiSuggestion>(`/purchase-invoices/${id}/ai-suggest`, { query }).then(r => r.data),
  purchaseAiAvailability: () =>
    api.get<{ available: boolean }>('/purchase-ai-suggestion-availability').then(r => r.data),
  /**
   * Náhled kontace PŘED zaúčtováním — tatáž cesta jako post, jen bez zápisu.
   * Volá stejné buildery, takže se návrh nemůže rozejít s tím, co se opravdu zaúčtuje.
   */
  postingPreview: (source: 'invoices' | 'purchase-invoices', id: number) =>
    api.get<PostingPreview>(`/accounting/journal/posting-preview/${source}/${id}`).then(r => r.data),
  // Hromadné zaúčtování z výběru v seznamu (A2) — vrací souhrnný report ok/fail.
  postInvoicesBulk: (ids: number[]) =>
    api.post<BulkPostReport>('/accounting/journal/post-invoices-bulk', { ids }).then(r => r.data),
  postPurchasesBulk: (ids: number[]) =>
    api.post<BulkPostReport>('/accounting/journal/post-purchases-bulk', { ids }).then(r => r.data),
  reverseEntry: (id: number) =>
    api.post<JournalEntryDetail>(`/accounting/journal/${id}/reverse`).then(r => r.data),
  deleteEntry: (id: number) =>
    api.delete<{ ok: boolean }>(`/accounting/journal/${id}`).then(r => r.data),
  // Auditní historie (SYSTEM VERSIONING timeline, audit 2026-07)
  getJournalHistory: (id: number) =>
    api.get<JournalHistoryResponse>(`/accounting/journal/${id}/history`).then(r => r.data),

  // Deník — inline editace description (§35) + přílohy §33a (Epic F7)
  // rowVersion (nepovinné) → hlavička If-Match pro optimistickou konkurenci (Issue #15):
  // při neshodě backend vrátí 409 version_conflict. Vynechání = bez CAS (zpětně kompat.).
  updateJournalDescription: (id: number, description: string, rowVersion?: number) =>
    api.patch<JournalEntryDetail>(
      `/accounting/journal/${id}/description`,
      { description },
      rowVersion != null ? { headers: { 'If-Match': `"${rowVersion}"` } } : undefined,
    ).then(r => r.data),
  /**
   * Server vrací `items`, ne `attachments`. Dokud se četl neexistující klíč,
   * spolkl ho `?? []` a seznam příloh byl VŽDY prázdný — nahrání přitom
   * proběhlo a soubor se uložil, takže to vypadalo, že se příloha ztratila.
   */
  listJournalAttachments: (id: number) =>
    api.get<{ items: JournalAttachment[] }>(`/accounting/journal/${id}/attachments`)
      .then(r => r.data.items ?? []),
  uploadJournalAttachments: (id: number, files: File[], onProgress?: (pct: number) => void) => {
    const fd = new FormData()
    for (const f of files) fd.append('file[]', f, f.name)
    return api.post<{ created: number; items: JournalAttachment[] }>(`/accounting/journal/${id}/attachments`, fd, {
      headers: { 'Content-Type': 'multipart/form-data' },
      onUploadProgress: (e) => { if (onProgress && e.total) onProgress(Math.round((e.loaded / e.total) * 100)) },
    }).then(r => r.data)
  },
  updateJournalAttachmentDescription: (id: number, attId: number, description: string) =>
    api.patch<JournalAttachment>(`/accounting/journal/${id}/attachments/${attId}/description`, { description })
      .then(r => r.data),
  deleteJournalAttachment: (id: number, attId: number) =>
    api.delete<{ ok: boolean }>(`/accounting/journal/${id}/attachments/${attId}`).then(r => r.data),
  /** Přímá navigace v prohlížeči — supplier_id v query param (X-Supplier-Id header se neposílá). */
  journalAttachmentDownloadUrl: (id: number, attId: number) => {
    const s = localStorage.getItem('myinvoice.current_supplier_id')
    const qs = s && /^\d+$/.test(s) ? `?supplier_id=${s}` : ''
    return `/api/accounting/journal/${id}/attachments/${attId}${qs}`
  },

  // Poznámky k zápisu (1:N) — načítají se lazy až po rozbalení sekce.
  listJournalNotes: (id: number) =>
    api.get<{ items: JournalNote[]; total: number }>(`/accounting/journal/${id}/notes`)
      .then(r => r.data.items ?? []),
  createJournalNote: (id: number, body: string, pinned = false) =>
    api.post<JournalNote>(`/accounting/journal/${id}/notes`, { body, pinned }).then(r => r.data),
  updateJournalNote: (id: number, noteId: number, patch: { body?: string; pinned?: boolean }) =>
    api.patch<JournalNote>(`/accounting/journal/${id}/notes/${noteId}`, patch).then(r => r.data),
  deleteJournalNote: (id: number, noteId: number) =>
    api.delete<{ deleted: number }>(`/accounting/journal/${id}/notes/${noteId}`).then(r => r.data),

  /**
   * Náhled zdrojového dokladu. Klíčem je ID ZÁPISU — source_type/source_id si
   * backend bere z ověřeného řádku, aby přes ně nešlo tahat cizí doklady.
   */
  getJournalSource: (id: number) =>
    api.get<JournalSourceSummary>(`/accounting/journal/${id}/source`).then(r => r.data),

  /**
   * Zaúčtování prvotního dokladu (sekce „Zaúčtování" na detailu faktury).
   * Vrací všechny zápisy dokladu — původní i storno — i s řádky. Prázdný
   * seznam znamená „nezaúčtováno" nebo firmu mimo podvojné účetnictví; sekce
   * se v obou případech nezobrazí.
   */
  journalForDocument: (source: JournalDocumentSource, docId: number) =>
    api.get<{ items: JournalEntryWithLines[] }>(`/accounting/journal/for-document/${source}/${docId}`)
      .then(r => r.data.items ?? []),

  /**
   * Protějšky zápisu (doklad ↔ úhrada) i s jejich zaúčtováním. Klíčem je opět
   * ID ZÁPISU, ne dvojice source_type/source_id — stejná obrana proti IDOR.
   */
  getJournalRelated: (id: number) =>
    api.get<JournalRelated>(`/accounting/journal/${id}/related`).then(r => r.data),

  // Měkké vazby zápisu na doklady (migrace 1514). Mutace vracejí rovnou celý
  // aktuální seznam, aby si UI nemuselo stav skládat samo a nemohlo se rozejít.
  listJournalLinks: (id: number) =>
    api.get<{ entry_id: number; items: JournalDocumentLink[] }>(`/accounting/journal/${id}/links`)
      .then(r => r.data.items ?? []),
  createJournalLink: (id: number, payload: JournalLinkPayload) =>
    api.post<{ entry_id: number; link: JournalDocumentLink; items: JournalDocumentLink[] }>(
      `/accounting/journal/${id}/links`, payload,
    ).then(r => r.data),
  deleteJournalLink: (id: number, linkId: number) =>
    api.delete<{ entry_id: number; deleted: number; items: JournalDocumentLink[] }>(
      `/accounting/journal/${id}/links/${linkId}`,
    ).then(r => r.data),
  /** Našeptávač dokladů k navázání; server pod dva znaky nic nevrací. */
  searchLinkCandidates: (q: string, types?: LinkableDocType[]) =>
    api.get<{ query: string; items: LinkCandidate[] }>('/accounting/journal/link-candidates', {
      params: { q, ...(types?.length ? { types: types.join(',') } : {}) },
    }).then(r => r.data.items ?? []),

  // Šablony ručních zápisů (Fáze F, mzdový můstek)
  listJournalTemplates: () =>
    api.get<{ items: JournalTemplateSummary[] }>('/accounting/journal-templates').then(r => r.data.items),
  getJournalTemplate: (id: number) =>
    api.get<JournalTemplateDetail>(`/accounting/journal-templates/${id}`).then(r => r.data),
  createJournalTemplate: (payload: CreateJournalTemplatePayload) =>
    api.post<JournalTemplateDetail>('/accounting/journal-templates', payload).then(r => r.data),
  updateJournalTemplate: (id: number, payload: CreateJournalTemplatePayload) =>
    api.put<JournalTemplateDetail>(`/accounting/journal-templates/${id}`, payload).then(r => r.data),
  deleteJournalTemplate: (id: number) =>
    api.delete<{ ok: boolean }>(`/accounting/journal-templates/${id}`).then(r => r.data),
  importJournalTemplateCsv: (id: number, file: File) => {
    const fd = new FormData()
    fd.append('file', file, file.name)
    return api.post<TemplateCsvMatchResult>(`/accounting/journal-templates/${id}/import-csv`, fd, {
      headers: { 'Content-Type': 'multipart/form-data' },
    }).then(r => r.data)
  },

  // Střediska
  listCostCenters: (includeInactive = false) =>
    api.get<CostCenter[]>('/accounting/cost-centers', {
      params: includeInactive ? { include_inactive: '1' } : {},
    }).then(r => r.data),
  createCostCenter: (payload: CostCenterPayload) =>
    api.post<CostCenter>('/accounting/cost-centers', payload).then(r => r.data),
  updateCostCenter: (id: number, payload: Partial<Pick<CostCenter, 'name' | 'is_active'>>) =>
    api.patch<CostCenter>(`/accounting/cost-centers/${id}`, payload).then(r => r.data),
  deleteCostCenter: (id: number) =>
    api.delete<{ deleted: boolean }>(`/accounting/cost-centers/${id}`).then(r => r.data),

  // Kontační pravidla
  listPostingRules: () =>
    api.get<PostingRuleMap>('/accounting/posting-rules').then(r => r.data),
  putPostingRule: (ruleKey: string, payload: PostingRulePayload) =>
    api.put<PostingRule>(`/accounting/posting-rules/${encodeURIComponent(ruleKey)}`, payload).then(r => r.data),

  // Mzdová rekapitulace (Fáze F) — preview je POST kvůli vstupům v těle, ale nic nemění.
  previewPayroll: (payload: PayrollPayload) =>
    api.post<PayrollPreview>('/accounting/payroll/preview', payload).then(r => r.data),
  /** Idempotentní na (firma, RRRRMM) — opakované volání zápis přepíše, nezaloží druhý. */
  postPayroll: (payload: PayrollPayload) =>
    api.post<PayrollPostResult>('/accounting/payroll/post', payload).then(r => r.data),

  // Zaměstnanci pro mzdový list (§38j)
  listPayrollEmployees: (active?: boolean) =>
    api.get<{ items: PayrollEmployee[] }>('/accounting/payroll/employees', {
      params: active === undefined ? {} : { active: active ? '1' : '0' },
    }).then(r => r.data.items),
  // Vrací celou odpověď, ne jen kartu: `warnings` nese upozornění na nesourodou
  // kombinaci vztahu a typu poplatníka, které NEblokuje uložení — kdyby se tu
  // odstřihlo, uživatel by se o něm nedozvěděl.
  createPayrollEmployee: (payload: PayrollEmployeePayload) =>
    api.post<PayrollEmployeeSaveResult>('/accounting/payroll/employees', payload).then(r => r.data),
  updatePayrollEmployee: (id: number, payload: Partial<PayrollEmployeePayload>) =>
    api.put<PayrollEmployeeSaveResult>(`/accounting/payroll/employees/${id}`, payload).then(r => r.data),
  deletePayrollEmployee: (id: number) =>
    api.delete<{ deleted: true }>(`/accounting/payroll/employees/${id}`).then(r => r.data),

  // Účetní sestavy (Epic F2)
  getGeneralLedger: (params: LedgerReportParams) =>
    api.get<GeneralLedgerReport>('/accounting/reports/general-ledger', { params }).then(r => r.data),
  getTrialBalance: (params: LedgerReportParams) =>
    api.get<TrialBalanceReport>('/accounting/reports/trial-balance', { params }).then(r => r.data),
  getBalanceInventory: (periodId: number) =>
    api.get<BalanceInventoryReport>('/accounting/reports/balance-inventory', { params: { period_id: periodId } }).then(r => r.data),
  /** § 18 odst. 2 ZoÚ — přehled o peněžních tocích a o změnách vlastního kapitálu. */
  getSection18Statements: (periodId: number) =>
    api.get<Section18Statements>('/accounting/reports/section18-statements', { params: { period_id: periodId } }).then(r => r.data),
  // EP-6: inventarizace v rámci uzávěrky — náhled s uloženým skutečným stavem + uložení.
  getClosingInventory: (periodId: number) =>
    api.get<BalanceInventoryReport>(`/accounting/periods/${periodId}/closing/inventory`).then(r => r.data),
  saveClosingInventory: (periodId: number, payload: BalanceInventorySavePayload) =>
    api.post<BalanceInventorySaveResult>(`/accounting/periods/${periodId}/closing/inventory`, payload).then(r => r.data),
  getAccountStatement: (accountId: number, params: { from: string; to: string; page?: number; per_page?: number }) =>
    api.get<AccountStatementReport>(`/accounting/reports/account-statement/${accountId}`, { params }).then(r => r.data),
  getBalanceSheet: (params: StatementParams) =>
    api.get<BalanceSheetReport>('/accounting/reports/balance-sheet', { params }).then(r => r.data),
  getIncomeStatement: (params: StatementParams) =>
    api.get<IncomeStatementReport>('/accounting/reports/income-statement', { params }).then(r => r.data),
  /**
   * VZZ v ÚČELOVÉM členění. Bez úplné mapy funkcí backend výkaz NESESTAVÍ a vrátí
   * `function_map_incomplete` s výčtem nepřiřazených účtů — nepřiřazený náklad by z výkazu
   * tiše vypadl a nadhodnotil zisk.
   */
  getIncomeStatementByFunction: (params: StatementParams) =>
    api.get<IncomeStatementReport>('/accounting/reports/income-statement-by-function', { params }).then(r => r.data),
  /**
   * `periodId` je volitelné — s ním navíc vrátí `unassigned`, tedy nákladové účty
   * s obratem, které nepokrývá ani globální mapa, ani přiřazení funkci. Bez toho výčtu
   * by uživatel dostal jen „výkaz nelze sestavit" a musel chybějící účty dohledávat sám.
   */
  getStatementFunctionMap: (periodId?: number) =>
    api.get<StatementFunctionMap>('/accounting/reports/statement-function-map', {
      params: periodId ? { period_id: periodId } : {},
    }).then(r => r.data),
  /** Prázdný `function_code` přiřazení zruší. */
  setStatementFunctionMapping: (accountPrefix: string, functionCode: StatementFunctionCode | '') =>
    api.put('/accounting/reports/statement-function-map', {
      account_prefix: accountPrefix,
      function_code: functionCode,
    }).then(r => r.data),
  getSaldo: (params: SaldoParams) =>
    api.get<SaldoReport>('/accounting/reports/saldo', { params }).then(r => r.data),
  getEntityCategory: (periodId: number) =>
    api.get<EntityCategory>('/accounting/reports/entity-category', { params: { period_id: periodId } }).then(r => r.data),
  // Pozn.: reporting-settings klient žije v closing.ts (closingSettingsApi) —
  // duplicitní varianta odsud byla odstraněna (audit UI mezer 2026-07).
  // Vrací celou odpověď (blob) — komponenta si sestaví název souboru dle konvence.
  exportReport: (path: string, params: Record<string, unknown>) =>
    api.get<Blob>(path, { params, responseType: 'blob' }),

  // Vzájemné zápočty (Fáze F)
  listOffsets: (status?: OffsetStatus) =>
    api.get<{ items: OffsetAgreement[] }>('/accounting/offsets', { params: status ? { status } : {} }).then(r => r.data.items),
  offsetPartners: () =>
    api.get<{ items: OffsetPartner[] }>('/accounting/offsets/partners').then(r => r.data.items),
  offsetOpen: (partnerId: number) =>
    api.get<OffsetOpenResult>('/accounting/offsets/open', { params: { partner_id: partnerId } }).then(r => r.data),
  getOffset: (id: number) =>
    api.get<OffsetDetail>(`/accounting/offsets/${id}`).then(r => r.data),
  createOffset: (payload: OffsetCreatePayload) =>
    api.post<OffsetDetail>('/accounting/offsets', payload).then(r => r.data),
  confirmOffset: (id: number) =>
    api.post<OffsetDetail>(`/accounting/offsets/${id}/confirm`, {}).then(r => r.data),
  cancelOffset: (id: number) =>
    api.post<OffsetDetail>(`/accounting/offsets/${id}/cancel`, {}).then(r => r.data),
  offsetPdf: (id: number) =>
    api.get<Blob>(`/accounting/offsets/${id}/pdf`, { responseType: 'blob' }),

  // Kurzový režim firmy (§24/7 — Fáze F)
  getFxRateSettings: (fiscalYear?: number) =>
    api.get<FxRateSettings>('/accounting/fx-rate-settings', { params: fiscalYear ? { fiscal_year: fiscalYear } : {} }).then(r => r.data),
  setFxRateMode: (mode: FxRateMode) =>
    api.put<{ mode: FxRateMode }>('/accounting/fx-rate-settings', { mode }).then(r => r.data),
  upsertFixedRate: (payload: { currency: string; fiscal_year: number; month: number; rate: number }) =>
    api.put<{ rates: FixedRate[] }>('/accounting/fx-rate-settings/rates', payload).then(r => r.data.rates),
  deleteFixedRate: (id: number) =>
    api.delete(`/accounting/fx-rate-settings/rates/${id}`).then(r => r.data),
  cnbPrefillRate: (currency: string, fiscalYear: number, month: number) =>
    api.get<{ currency: string; rate: number; rate_date: string; fallback_used: boolean }>(
      '/accounting/fx-rate-settings/cnb-prefill', { params: { currency, fiscal_year: fiscalYear, month } }).then(r => r.data),

  // Repo sazba ČNB (úrok z prodlení, NV 351/2013)
  getRepoRates: () =>
    api.get<{ rates: RepoRate[] }>('/accounting/repo-rates').then(r => r.data.rates),
  upsertRepoRate: (payload: { valid_from: string; rate: number; note?: string }) =>
    api.put<{ rates: RepoRate[] }>('/accounting/repo-rates', payload).then(r => r.data.rates),
  deleteRepoRate: (validFrom: string) =>
    api.delete(`/accounting/repo-rates/${validFrom}`).then(r => r.data),

  // Úhrada faktury zápočtem proti zvolenému účtu (355/365)
  listSettlements: (docType: SettlementDocType, docId: number) =>
    api.get<{ items: InvoiceSettlement[]; default_account: SettlementDefaultAccount }>(
      '/accounting/settlements', { params: { doc_type: docType, doc_id: docId } }).then(r => r.data),
  createSettlement: (payload: CreateSettlementPayload) =>
    api.post<InvoiceSettlement>('/accounting/settlements', payload).then(r => r.data),
  cancelSettlement: (id: number) =>
    api.post<InvoiceSettlement>(`/accounting/settlements/${id}/cancel`, {}).then(r => r.data),
  /** Doúčtuje zápočet, kterému chybí účetní zápis (daňová evidence, přeúčtování deníku). */
  postSettlement: (id: number) =>
    api.post<InvoiceSettlement>(`/accounting/settlements/${id}/post`, {}).then(r => r.data),
}

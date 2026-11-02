import { requestSupplierId } from '@/workspace/supplierContext'
import { api } from './client'
import type { AutomationProvenance } from './automation'
import type { DimensionRuleWarning, DimensionSplits } from './dimensions'

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
  is_red_storno?: boolean
  amount: number
  currency_code: string | null
  fx_rate: number | null
  amount_foreign: number | null
  cost_center: string | null
  line_no: number
  /** Dimenze řádku (Firma → Dimenze): typ → hodnota; chodí v detailu zápisu. */
  dimensions?: Record<number, number>
  /** Rozpad řádku mezi víc hodnot typu: typ → [{value_id, share}]; chodí v detailu zápisu. */
  dimension_splits?: DimensionSplits
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
  /** ID zápisu, který tenhle zápis stornuje (u protizápisu). */
  reverses_entry_id?: number | null
  /** ID zdroje pro proklik; u protizápisu zděděné ze stornovaného zápisu. */
  source_link_id?: number | null
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
  /** Jen source_type='bank' — ID pohybu z banky; číslo dokladu nese řadu účtu (BCR-08). */
  source_bank_ref?: string | null
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
  /** Interní doklad, jehož zaúčtováním (posting) nebo stornem (reversal) zápis je (migrace 2193). */
  internal_document?: { id: number; document_no: string | null; status: 'draft' | 'posted' | 'reversed'; role: 'posting' | 'reversal' } | null
  automation?: AutomationProvenance | null
  posting_lines?: Array<{ side: JournalSide; amount: number; account_code: string; account_name: string | null }>
  vat_breakdown?: Array<{ rate: number; base: number; vat: number }>
  source_currency?: string | null
  dimension_labels?: string[]
  /** Připnutá, jinak nejnovější poznámka zápisu (jen s include_notes). */
  note_preview?: string | null
  note_count?: number
  /** Přílohy zápisu + navázané dokumenty z úložiště (jen s include_documents). */
  document_count?: number
  _warnings?: Array<'entry_date_outside_document_year'>
}

/** Doklad, ke kterému se hledá zaúčtování — segment URL /journal/for-document/{source}/{id}. */
export type JournalDocumentSource = 'invoices' | 'purchase-invoices'

/**
 * Doklad, jehož ZÁPIS se přeúčtovává nebo u kterého se ptáme na původ kontace.
 * Širší než {@link JournalDocumentSource}: bankovní pohyb vlastní sekci Zaúčtování
 * nemá (kontace visí na řádku výpisu), ale zápis v deníku má a opravit se dá taky.
 */
export type JournalPostingSource = JournalDocumentSource | 'bank-transactions'

/**
 * Vztah zápisu k dokladu v sekci Zaúčtování. Záloha (zálohová PF, proforma) vlastní
 * zápis nemá, její zaúčtování tvoří úhrada (314/221, 221/324) a zúčtování v konečné
 * faktuře. Takové zápisy se jen ukazují, přeúčtovávají se u svého dokladu.
 */
export type JournalEntryRelation = 'own' | 'advance_payment' | 'advance_final'

/** Zápis i s řádky, jak ho vrací /journal/for-document/{source}/{id}. */
export interface JournalEntryWithLines extends JournalEntry {
  lines: JournalLine[]
  relation?: JournalEntryRelation
}

/** Jeden navržený řádek kontace — MD/DAL s názvem účtu, ať uživatel nepotvrzuje holá čísla. */
export interface PostingPreviewLine {
  account_code: string
  account_name: string | null
  side: 'debit' | 'credit'
  is_red_storno?: boolean
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
  /** Účtotvorná dimenze: kam zaúčtování přesune výsledkové syntetiky návrhu. */
  dimension_routing?: {
    account_code: string
    side: 'debit' | 'credit'
    amount: number
    targets: { account_code: string; amount: number }[]
  }[]
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
  /** Varování pravidel dimenzí (vynucení „varovat") po zaúčtování. */
  dimension_warnings?: DimensionRuleWarning[]
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
  /** false = doklad se do deníku neúčtuje nikdy (proforma, zálohová PF); chybějící zápis není nález. */
  postable?: boolean
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
  is_red_storno?: boolean
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
  include_vat_breakdown?: boolean
  include_posting_accounts?: boolean
  include_dimensions?: boolean
  include_notes?: boolean
  include_documents?: boolean
  sort_key?: string
  sort_dir?: 'asc' | 'desc'
  document_no?: string
  period_id?: number
  date_from?: string
  date_to?: string
  source_type?: string
  source_id?: number
  /** Přesný odskok na jeden zápis (deep-link ?entry_id=), ne jen zúžení data. */
  entry_id?: number
  posted?: boolean
  /** Stav stornování: stornovaný zápis, samotný protizápis, obojí, nebo ani jedno. */
  reversal?: 'reversed' | 'reversal' | 'any' | 'none'
  automation?: 'auto' | 'approved' | 'manual'
  /** Fulltext (popis + čísla dokladů) — Featura D, audit 2026-07 follow-up. */
  q?: string
  account_from?: string
  account_to?: string
  amount_from?: number
  amount_to?: number
  /** Jen zápisy s nálezem noční kontroly integrity deníku (JournalIntegrityService). */
  integrity?: 'amount_mismatch'
  /** Hodnota dimenze; zápis projde, nese-li ji (nebo podřízenou) aspoň jeden řádek. */
  dimension_value_id?: number
  /** false = jen hodnota sama, bez podřízených (výchozí je celá větev). */
  dimension_descendants?: boolean
  page?: number
  per_page?: number
}

export interface ManualLinePayload {
  account_code: string
  side: JournalSide
  is_red_storno?: boolean
  amount: number
  cost_center?: string
  /** Dimenze řádku: typ → hodnota. */
  dimensions?: Record<number, number>
  /** Rozpad řádku mezi víc hodnot typu: typ → [{value_id, share}], součet 100 %. */
  dimension_splits?: DimensionSplits
}

/** Tělo zaúčtování dokladu. `lines` = kontace upravená v popupu; bez nich staví server. */
export interface PostDocumentPayload {
  entry_date?: string
  description?: string
  document_no?: string
  lines?: { account_code: string; side: JournalSide; amount: number; is_red_storno?: boolean }[]
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
  /** Dimenze řádku šablony: typ → hodnota. */
  dimensions?: Record<number, number>
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
  dimensions?: Record<number, number>
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

/**
 * Srovnání kontací s analytickou osnovou („Doplnit podle osnovy").
 *  - `ok`      … kód je analytika, nebo syntetika bez potomků
 *  - `auto`    … jediná analytika → engine přesměruje sám (nic se nezapisuje)
 *  - `suggest` … dvě a víc analytik → volbu potvrzuje člověk
 *  - `context` … analytiku volí kontext dokladu (211/221/343/345)
 *  - `missing` … účet z pravidla není v osnově firmy
 */
export type ChartAlignmentStatus = 'ok' | 'auto' | 'suggest' | 'context' | 'missing'

export interface ChartAlignmentCandidate {
  account_code: string
  name: string
  is_deductible: boolean
  is_dotted: boolean
}

export interface ChartAlignmentSide {
  code: string | null
  status: ChartAlignmentStatus
  /** Účet, na který se doklad reálně zaúčtuje (u `auto` je jiný než `code`). */
  effective_code: string | null
  candidates: ChartAlignmentCandidate[]
  suggested_code: string | null
}

export interface ChartAlignmentRule {
  rule_key: string
  description: string
  is_override: boolean
  status: ChartAlignmentStatus
  debit: ChartAlignmentSide
  credit: ChartAlignmentSide
}

export interface ChartAlignmentPreview {
  redirect_enabled: boolean
  counts: Record<ChartAlignmentStatus, number>
  rules: ChartAlignmentRule[]
}

export interface ChartAlignmentApplyItem {
  rule_key: string
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
  period_id?: number
  all_periods?: 1
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
  /** Filtr na hodnotu dimenze (Firma → Dimenze); bere jen řádky deníku s touto hodnotou. */
  dimension_value_id?: number
  /** 0 = jen hodnota sama, bez podřízených (výchozí je celá větev). */
  dimension_descendants?: 0 | 1
}

export interface GeneralLedgerAccount {
  account_id: number
  account_code: string
  name: string
  account_type: AccountType
  is_synthetic: boolean
  /** Syntetika, pod kterou analytika patří (jen při `analytics=1`; jinak null). */
  parent_id: number | null
  parent_code: string | null
  parent_name: string | null
  opening_md: number
  opening_d: number
  /** Měsíční obraty keyed 'YYYY-MM' (jen měsíce s pohybem). */
  months: Record<string, { md: number; d: number }>
  turnover_md: number
  turnover_d: number
  closing_md: number
  closing_d: number
}

/** Použitý filtr na dimenzi (hodnota a celá její větev). */
export interface ReportDimensionFilter {
  type_id: number
  value_id: number
  value_ids: number[]
  label?: string | null
}

export interface GeneralLedgerReport {
  period: ReportPeriod | null
  all_periods: boolean
  dimension?: ReportDimensionFilter | null
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
  dimension?: ReportDimensionFilter | null
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
  accounting_framework?: 'cz_504'
  available?: boolean
  reason?: string
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
  } | null
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
  } | null
  category?: string
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
  source_bank_ref?: string | null
  source_doc_number: string | null
  source_register_id: number | null
  source_asset_id: number | null
  source_asset_name: string | null
  source_settlement_doc_type: string | null
  source_settlement_doc_id: number | null
  line_id: number
  line_no: number
  /** Účty opačné strany téhož zápisu. */
  counter_accounts: string | null
  partner: string | null
  variable_symbol: string | null
  currency: string
  amount_foreign: number | null
  /** Okruh párování, ve kterém řádek leží. */
  pairing_id: number | null
}

export interface OpenItemLine {
  line_id: number
  line_no: number
  entry_id: number
  entry_date: string
  document_no: string | null
  description: string | null
  source_type: string
  source_id: number | null
  side: JournalSide
  amount: number
  is_red_storno?: boolean
  open_amount: number
  open_balance: number
  balance: number
  account_id: number
  account_code: string
  account_name: string
  is_reversed: boolean
  source_statement_id: number | null
  source_doc_number: string | null
  source_register_id: number | null
  source_asset_id: number | null
  source_settlement_doc_type: string | null
  source_settlement_doc_id: number | null
  counter_accounts: string | null
  partner: string | null
  variable_symbol: string | null
  currency: string
  amount_foreign: number | null
  pairing_id: number | null
}

export interface OpenItemsReport {
  account: { id: number; code: string; name: string; type: AccountType; normal_side: NormalSide | null; is_synthetic: boolean }
  as_of: string
  only_open: boolean
  items: OpenItemLine[]
  total: number
  page: number
  per_page: number
  line_count: number
  open_count: number
  open_md: number
  open_d: number
  open_total: number
  balance: number
  difference: number
}

export interface LinePairingItem {
  entry_id: number
  line_no: number
  account_id: number
  line_id: number | null
  side: JournalSide | null
  amount: number | null
  entry_date: string
  document_no: string | null
  description: string | null
  source_type: string
  source_id: number | null
  posted: boolean
}

export interface LinePairing {
  id: number
  account_id: number
  account_code: string
  account_name: string
  note: string | null
  origin: 'manual' | 'suggestion'
  created_by: number | null
  created_at: string
  items: LinePairingItem[]
  total_md: number
  total_d: number
  remainder: number
  balanced: boolean
}

export interface PairingSuggestion {
  kind: 'reversal' | 'amount'
  account_id: number
  amount: number
  days_apart: number
  lines: Array<{ line_id: number; entry_id: number; entry_date: string; document_no: string | null; description: string | null; side: JournalSide; amount: number }>
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
  /** Filtr výsledovky i rozvahy na hodnotu dimenze (řádek s rozpadem jen svým dílem). */
  dimension_value_id?: number
  dimension_descendants?: 0 | 1
  /** Průběžné časové rozlišení mimo deník (381/384 k rozvahovému dni), jen pohled výkazu. */
  accrual_spread?: 0 | 1
}

/** Souhrn průběžného časového rozlišení; ve výkazu jen při `accrual_spread=1`. */
export interface StatementAccrualSpread {
  /** false = volba se neuplatnila (výkaz za hodnotu dimenze). */
  applied: boolean
  skipped_reason: 'dimension' | null
  prepaid_expense: number
  deferred_revenue: number
  documents: number
  prev: { prepaid_expense: number; deferred_revenue: number; documents: number } | null
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

export interface Statement504Component {
  key: string
  label: string
  start?: number | null
  end?: number | null
  main?: number
  economic?: number
  total?: number
}

export interface Statement504Row {
  row_code: string
  display_code: string
  label: string
  adis_sentence: 'UR' | 'US' | 'UT'
  adis_row: number
  start?: number | null
  end?: number | null
  main?: number
  economic?: number
  total?: number
  components?: Statement504Component[]
}

export interface Statement504Report {
  accounting_framework: 'cz_504'
  statement_type: 'balance_sheet' | 'income_statement' | 'account_view'
  version_code: string
  as_of: string
  scope?: EffectiveScope
  entity: StatementEntity
  period: ReportPeriod
  dimension?: ReportDimensionFilter | null
  view_kind?: 'management'
  deferred_tax_method?: { enabled: true; version: '504-deferred-own-v1'; reference: string; disclosure: string; faithful_image_reason: string; standard_departure_reason: string; presentation_reason: string; first_adoption: boolean; first_adoption_reason?: string }
  assets?: Statement504Row[]
  liabilities?: Statement504Row[]
  rows?: Statement504Row[]
  profit_loss?: Statement504Row[]
  balances?: { account_id: number; code: string; name: string; account_type: string; opening_debit_balance: number; closing_debit_balance: number }[]
  checks: Record<string, number | boolean>
}

export function isStatement504(value: object): value is Statement504Report {
  return 'accounting_framework' in value && value.accounting_framework === 'cz_504'
}

export interface BalanceSheetReport {
  statement_type: 'balance_sheet'
  version_code: string
  as_of: string
  scope: EffectiveScope
  dimension?: ReportDimensionFilter | null
  entity: StatementEntity
  period: ReportPeriod
  prev_period: ReportPeriod | null
  assets: BalanceSheetAssetRow[]
  liabilities: StatementRow[]
  accrual_spread?: StatementAccrualSpread
  checks: {
    assets_net: number
    liabilities_total: number
    balanced: boolean
    negative_net_rows?: BalanceSheetNegativeNetRow[]
  }
}

/** Řádek aktiv se záporným netto (korekce vyšší než brutto) v běžném nebo minulém období. */
export interface BalanceSheetNegativeNetRow {
  row_code: string
  label: string
  column: 'current' | 'previous'
  gross: number
  correction: number
  net: number
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

// ── Výjimky mapování účtů do výkazů pro konkrétní firmu ────────────────────
export type StatementOverrideType = 'balance_sheet' | 'income_statement' | 'income_statement_purpose'
export type StatementBalanceCondition = 'any' | 'debit' | 'credit'
export type StatementSection = 'assets' | 'liabilities' | 'profit_loss'

export interface StatementOverride {
  id?: number
  version_id?: number
  account_prefix: string
  row_code: string
  target: 'gross' | 'correction'
  balance_condition: StatementBalanceCondition
  sign?: number
  note: string | null
  /** Korekce: účet pohledávky, jejíž řádek výkazu korekce přebírá. */
  follows_prefix?: string | null
  /** Účetní období, od kterého / do kterého výjimka platí; null = bez omezení. */
  valid_from_year?: number | null
  valid_to_year?: number | null
  updated_at?: string | null
}

export interface StatementOverrideRow {
  row_code: string
  display_code: string
  parent_row_code: string | null
  section: StatementSection
  label: string
  level: number
  row_type: StatementRowType
  value: number | null
}

/** Kam výkaz účet dnes zařadí — a podle čeho (globální mapa, mapa funkcí, výjimka firmy). */
export interface StatementOverrideAccountMapping {
  row_code: string
  target: 'gross' | 'correction'
  balance_condition: StatementBalanceCondition
  source: 'global' | 'function' | 'override'
}

export interface StatementOverrideAccount {
  account_code: string
  name: string
  account_type: string
  is_synthetic: boolean
  parent_code: string | null
  balance: number
  mappings: StatementOverrideAccountMapping[]
}

/** Doložené podání DPPO za rok období (bez XML). */
export interface StatementFiledReturn {
  submission_id: number
  year: number
  status: string
  submitted_at: string | null
  form_variant: string
}

export interface StatementOverrideOverview {
  statement_type: StatementOverrideType
  version: { id: number; statement_type: StatementOverrideType; version_code: string }
  period: { id: number; fiscal_year: number; starts_on: string; ends_on: string }
  as_of: string
  rows: StatementOverrideRow[]
  overrides: StatementOverride[]
  accounts: StatementOverrideAccount[]
  filed_return: StatementFiledReturn | null
}

export interface StatementOverridePreviewRow {
  row_code: string
  display_code: string
  label: string
  level: number
  section: StatementSection
  before: number
  after: number
  delta: number
}

export interface StatementOverridePreview {
  statement_type: StatementOverrideType
  version_id: number
  rows: StatementOverridePreviewRow[]
  balanced_before: boolean | null
  balanced_after: boolean | null
}

export interface StatementOverrideSuggestion {
  statement_type: StatementOverrideType
  version_id: number
  account_code: string
  account_name: string
  amount: number
  amount_thousands: number
  current_row_code: string
  from_row_code: string
  from_label: string
  to_row_code: string
  to_label: string
  to_is_subtotal: boolean
  balance_condition: StatementBalanceCondition
  target: 'gross' | 'correction'
  /** Korekce navázaná na pohledávku, kterou návrh přesouvá do stejného řádku. */
  follows_prefix?: string | null
  sign: number
  reason: string
  ambiguous: boolean
  /** Účty, které návrh přesouvá (u skupiny víc analytik). */
  accounts: string[]
  /** Výjimky, které návrh založí — jeden prefix, nebo účet po účtu. */
  overrides: StatementOverride[]
  /** exact = sedí obě strany, partial = jedna strana, fit = jen se vejde (nejisté). */
  confidence: 'exact' | 'partial' | 'fit'
}

export interface StatementOverrideDifference {
  sentence: 'VetaUA' | 'VetaUB' | 'VetaUD'
  c_radku: number
  row_code: string | null
  app: number
  filed: number
  diff: number
}

export interface StatementOverrideSuggestions {
  period_id: number
  year: number
  suggestions: StatementOverrideSuggestion[]
  differences: StatementOverrideDifference[]
  source: { type: 'filed_return' | 'upload'; submission_id?: number; status?: string; submitted_at?: string | null }
  /** Návrhy pro sloupec minulého období (výjimky platné do minulého roku); null = podání ho nenese. */
  prior_period?: { period_id: number; year: number; suggestions: StatementOverrideSuggestion[]; differences: StatementOverrideDifference[] } | null
}

export interface IncomeStatementReport {
  dimension?: ReportDimensionFilter | null
  statement_type: 'income_statement' | 'income_statement_purpose'
  version_code: string
  as_of: string
  scope: EffectiveScope
  entity: StatementEntity
  period: ReportPeriod
  prev_period: ReportPeriod | null
  rows: StatementRow[]
  accrual_spread?: StatementAccrualSpread
  checks: {
    profit_current: number
    net_turnover: number
  }
}

// ── Rozvaha a výsledovka po účtech ─────────────────────────────────────────
export interface StatementAccountLine {
  account_id: number
  account_code: string
  name: string
  account_type?: string
  md: number
  d: number
  analytics?: StatementAccountLine[]
}

export type StatementAccountSectionKey = 'operating' | 'financial' | 'unassigned' | 'tax' | 'transfer'

export interface StatementAccountsReport {
  version_code: string
  as_of: string
  entity: StatementEntity
  period: ReportPeriod
  closed: boolean
  dimension?: ReportDimensionFilter | null
  balance: {
    classes: { class: string; accounts: StatementAccountLine[]; md: number; d: number }[]
    md: number
    d: number
    profit: number
  }
  profit_loss: {
    sections: {
      key: StatementAccountSectionKey
      expenses: StatementAccountLine[]
      revenues: StatementAccountLine[]
      expense_total: number
      revenue_total: number
      result: number
    }[]
    operating_profit: number
    financial_profit: number
    profit_before_tax: number
    profit_after_tax: number
    profit: number
  }
  checks: {
    profit_balance: number
    profit_loss: number
    profit_matches: boolean
    technical_residual: number
    unassigned_count: number
  }
}

/** Nezaúčtovaná uzávěrková operace z projekce náhledu DPPO (ClosingProjectionCalculator). */
export interface YearEndClosingItem {
  key: string
  label_key: string
  amount: number
  sign: 1 | -1
  /** Návrh k potvrzení účetní (opravné položky, dohady) — zobrazuje se, nesčítá. */
  optional: boolean
}

export interface YearEndTaxEstimate {
  applicable: boolean
  reason: 'period_closed' | 'taxpayer_fo' | 'income_tax_posted' | 'tax_unavailable' | null
  message?: string
  period: { id: number; fiscal_year: number; starts_on: string; ends_on: string; status: string }
  return_status?: 'none' | 'draft' | 'final'
  vh_posted?: number
  closing_items?: YearEndClosingItem[]
  is_projection?: boolean
  vh_before_tax?: number
  increases?: number
  decreases?: number
  tax_base?: number
  tax?: number
  advances_paid?: number
  advances_source?: 'return' | 'schedules' | 'none'
  balance_due?: number
  vh_after_tax?: number
}

// ── Saldokonto (audit 2026-07, D6/1)───────────────────────────────────────
export interface SaldoParams {
  period_id: number
  as_of?: string
  /** Kód účtu (311/321/…) nebo 'all' pro default sadu. */
  account?: string
  partner_id?: number
  /** Stránka SEZNAMU PARTNERŮ. Bez ní přijde celá sestava — viz SaldoPartnersPagination. */
  page?: number
  /** Partnerů na stránku (default 50, max 200). Platí jen s `page`. */
  per_page?: number
}

export interface SaldoItem {
  doc_type: 'invoice' | 'purchase_invoice' | 'other_item'
  doc_id: number
  doc_no: string
  /** Druhé číslo přijaté faktury vedle doc_no; null, když není nebo je stejné. */
  other_doc_no?: string | null
  issue_date: string
  due_date: string
  currency_code: string
  amount_foreign: number
  booked_czk: number
  paid_czk: number
  remaining_czk: number
  days_overdue: number
  /** advance_pending = záloha zaplacená na saldokontní účet, čeká na konečnou fakturu. */
  kind?: 'document' | 'advance_pending'
  label?: string | null
  /** Přijatá (poskytnutá) platba zálohy — u advance_pending místo booked_czk. */
  advance_payment_czk?: number | null
  /** Daň z daňového dokladu k platbě — u advance_pending místo paid_czk. */
  advance_vat_czk?: number | null
  tax_document_id?: number | null
  /** Doklad s vlastním předpisem na účtu: jen ten jde vyrovnat rozpadem zápisu v deníku. */
  settleable?: boolean
}

export interface SaldoPartner {
  partner_id: number
  partner_name: string
  total_remaining: number
  items: SaldoItem[]
}

/**
 * Stránkování SEZNAMU PARTNERŮ. Součty v bloku účtu jsou vždy za celou sestavu,
 * ne za stránku — konfrontace se zůstatkem hlavní knihy je tvrzení o celku.
 * Bez `page` v požadavku přijde celá sestava (`pages: 1`).
 */
export interface SaldoPartnersPagination {
  page: number
  per_page: number
  total: number
  pages: number
}

export interface SaldoAccountBlock {
  account: { id: number; code: string; name: string; normal_side: NormalSide }
  gl_balance: number
  open_items_total: number
  /** Počet otevřených položek za CELOU sestavu (strop je 25 000, nad ním backend vrací 422 `too_many_rows`). */
  open_items_count: number
  difference: number
  matches: boolean
  partners: SaldoPartner[]
  partners_pagination: SaldoPartnersPagination
  /**
   * Jen 314/324: konečné faktury, jejichž čerpání záloh přesahuje stranu čerpání živého
   * zaúčtování (přeúčtování, převod) — saldokonto by zálohy uzavřelo o čerpání bez opory v deníku.
   */
  advance_deduction_issues?: SaldoAdvanceDeductionIssue[]
}

export interface SaldoAdvanceDeductionIssue {
  doc_id: number
  doc_no: string
  deductible: number
  deducted: number
  linked_share: number
  excess: number
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

// ── Vyrovnání saldokonta zápisem v deníku ─────────────────────────────────
export type SaldoSettleDocType = 'invoice' | 'purchase_invoice'

/** Řádek ručního zápisu s volnou (nerozepsanou) částkou v Kč. */
export interface SaldoSettlementLine {
  line_id: number
  entry_id: number
  line_no: number
  account_id: number
  account_code: string
  side: 'debit' | 'credit'
  entry_date: string
  document_no: string | null
  description: string | null
  line_amount: number
  free_amount: number
  links: { doc_type: SaldoSettleDocType; doc_id: number }[]
  amount_match: boolean
  doc_match: boolean
  partner_match: boolean
}

export interface SaldoSettlementAllocation {
  id: number
  entry_id: number
  line_no: number
  line_id: number | null
  account_id: number
  account_code: string
  kind: 'settlement' | 'origin'
  doc_type: SaldoSettleDocType | 'other_item'
  doc_id: number
  amount: number
  entry_date: string
  document_no: string | null
  description: string | null
}

export interface SaldoSettlementCandidates {
  document: {
    doc_type: SaldoSettleDocType
    doc_id: number
    doc_no: string
    partner_id: number
    partner_name: string
    currency_code: string
    remaining: number
    account: { id: number; code: string; name: string }
    side: 'debit' | 'credit'
    /** false = doklad v cizí měně, rozpad zápisu ho nevyrovná. */
    supported: boolean
  }
  allocations: SaldoSettlementAllocation[]
  items: SaldoSettlementLine[]
}

export type SaldoSettlementSuggestion = SaldoJournalSuggestion | SaldoAdvanceSuggestion

export interface SaldoJournalSuggestion {
  type?: 'journal'
  key: string
  reason: 'doc_no_amount' | 'doc_no_sum' | 'partner_amount' | 'partner_sum'
  account: { id: number; code: string }
  partner_name: string
  line: {
    line_id: number
    entry_id: number
    entry_date: string
    document_no: string | null
    description: string | null
    account_code: string
    free_amount: number
  }
  allocations: { doc_type: SaldoSettleDocType; doc_id: number; doc_no: string; remaining: number; amount: number }[]
}

/** Návrh čerpání zálohy (314/324) konečnou fakturou téhož partnera. */
export interface SaldoAdvanceSuggestion {
  type: 'advance'
  key: string
  reason: 'advance_doc_no' | 'advance_amount' | 'advance_sequence'
  account: { id: number; code: string }
  partner_name: string
  advance: { doc_type: SaldoSettleDocType; doc_id: number; doc_no: string; remaining: number }
  final: { doc_type: SaldoSettleDocType; doc_id: number; doc_no: string; posted_on: string; unassigned: number }
  amount: number
}

// ── Vyrovnání zálohy (314/324) čerpáním konečnou fakturou ─────────────────
/** Konečná faktura partnera s odpočtem zálohy, který zatím nečerpá žádná záloha. */
export interface SaldoAdvanceFinal {
  doc_type: SaldoSettleDocType
  doc_id: number
  doc_no: string
  issue_date: string
  /** Den zaúčtování faktury: od něj čerpání snižuje zálohu v saldokontu. */
  posted_on: string
  deductible: number
  deducted: number
  linked: boolean
  unassigned: number
  amount_match: boolean
  doc_match: boolean
  after_advance: boolean
}

export interface SaldoAdvanceDeduction {
  id: number
  doc_type: SaldoSettleDocType
  final_id: number
  final_no: string
  advance_id: number
  advance_no: string
  amount: number
  /** import = převzaté z převodu (v saldokontu se neruší), saldo = zadané v saldokontu. */
  source: 'import' | 'saldo'
  posted_on: string | null
  deletable: boolean
}

export interface SaldoAdvanceCandidates {
  mode: 'advance'
  document: {
    doc_type: SaldoSettleDocType
    doc_id: number
    doc_no: string
    partner_id: number
    partner_name: string
    currency_code: string
    issue_date: string
    remaining: number
    account: { id: number; code: string; name: string }
    /** false = záloha v cizí měně, čerpání v saldokontu nese jen Kč. */
    supported: boolean
  }
  deductions: SaldoAdvanceDeduction[]
  items: SaldoAdvanceFinal[]
}

export interface SaldoAdvanceDeductionInput {
  doc_type: SaldoSettleDocType
  advance_id: number
  final_id: number
  amount: number
}

export interface SaldoAdvanceDeductionResult {
  created: (SaldoAdvanceDeductionInput & { id: number })[]
  failed: { index: number; code: string; message: string }[]
}

export interface SaldoSettlementSuggestions {
  as_of: string
  truncated: boolean
  items: SaldoSettlementSuggestion[]
}

export interface SaldoAllocationInput {
  line_id: number
  doc_type: SaldoSettleDocType
  doc_id: number
  amount: number
}

export interface SaldoAllocationResult {
  created: (SaldoAllocationInput & { id: number; entry_id: number })[]
  /** Jen u hromadného potvrzení (`groups`): skupiny, které server odmítl. */
  failed: { index: number; code: string; message: string }[]
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
  // Odpisy majetku u hospodářského roku hlásí totéž jako `no_accounting_period`,
  // jen pod vlastním kódem (DepreciationPostingService) — bez něj spadl na `generic`.
  'period_missing',
  'entry_reversed', 'empty_entry',
  // Vyúčtování zálohy má víc kandidátů → nelze jednoznačně odečíst zálohovou DPH.
  'advance_settlement_ambiguous',
  // Vyúčtování zálohy s daňovým dokladem k platbě, který ještě není zaúčtovaný.
  'advance_tax_document_unposted',
  // Daňový doklad k platbě nese jen daň OSS — samostatně se neúčtuje.
  'advance_tax_document_oss',
  // DDKP (daňový doklad k platbě) v reverse-charge režimu se automaticky neúčtuje.
  'ddkp_reverse_charge_unsupported',
  // Přijatá faktura se samovyměřením a nárokem na odpočet „bez nároku".
  'rc_partial_deduction_unsupported',
  // Přeúčtování: datum je zamčené (§35 soft-close) resp. storno i oprava by padly
  // na jiné datum než původní zápis a uživatel to ještě nepotvrdil.
  'date_locked', 'repost_date_shift_confirmation_required', 'version_conflict',
] as const

/** Vrátí i18n klíč pro hlášku k chybovému kódu zaúčtování (fallback = generic). */
export function postingErrorI18nKey(code: string | null | undefined): string {
  return (POSTING_ERROR_CODES as readonly string[]).includes(code ?? '')
    ? `accounting.posting_errors.${code}`
    : 'accounting.posting_errors.generic'
}

/**
 * Hláška k chybě zaúčtování z odpovědi API. Neznámý kód ukáže text ze serveru —
 * obecné „nepodařilo se zaúčtovat" by uživateli zatajilo, co má na dokladu opravit.
 */
export function postingErrorMessage(t: (key: string) => string, e: any): string {
  const err = e?.response?.data?.error
  const code = typeof err?.code === 'string' ? err.code : null
  if ((POSTING_ERROR_CODES as readonly string[]).includes(code ?? '')) {
    return t(`accounting.posting_errors.${code}`)
  }
  return typeof err?.message === 'string' && err.message !== ''
    ? err.message
    : t('accounting.posting_errors.generic')
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

/**
 * Plán přeúčtování dokladu — co se stane, kdyby se přeúčtoval teď.
 *
 * `replace` přepíše existující zápis na místě (otevřené a nezamčené období),
 * `reverse` původní zápis stornuje a opravu zapíše novým zápisem (§35 — v uzavřeném
 * ani zamčeném období se zápis nemaže), `blocked` = opravu není kam zapsat.
 * `date_shifted` znamená, že storno i oprava padnou na JINÉ datum než původní zápis;
 * bez potvrzení se operace odmítne, aby se datum nikdy neposunulo potichu.
 */
export interface RepostPlan {
  entry_id: number
  entry_date: string
  document_no: string | null
  description: string | null
  period_status: string | null
  locked_until: string | null
  already_reversed: boolean
  strategy: 'replace' | 'reverse' | 'blocked'
  needs_reversal: boolean
  target_date: string | null
  date_shifted: boolean
  reason_code: 'period_not_open' | 'date_locked' | 'entry_reversed' | 'tax_neutral_rewrite' | 'posting_group' | null
  posting_entries?: Array<{ id: number; entry_date: string; row_version: number }>
  suggested_lines?: RepostPlan['lines']
  /** Zamčené datum v otevřeném roce: přesun mezi účty téže třídy bez daňového dopadu se přepíše na místě. */
  tax_neutral_available: boolean
  /** Proč opravené řádky nejdou přepsat na místě (jen u plánu počítaného nad řádky). */
  tax_neutral_violation?: RepostTaxNeutralViolation | null
  lines: Array<{ account_code: string | null; account_name: string | null; side: 'debit' | 'credit'; amount: number; is_red_storno?: boolean }>
}

export type RepostTaxNeutralViolation =
  | 'unknown_account' | 'special_account' | 'tax_account_changed'
  | 'amounts_changed' | 'account_class_changed' | 'tax_deductibility_changed'

export interface RepostResult {
  strategy: 'replace' | 'reverse'
  entry_id: number
  reversal_entry_id: number | null
  reversal_entry_ids?: number[]
  entry_date: string
  date_shifted: boolean
  items_synced: number
}

export interface RepostPayload {
  lines: Array<{ account_code: string; side: 'debit' | 'credit'; amount: number; is_red_storno?: boolean }>
  description?: string | null
  confirm_date_shift?: boolean
  expected_posting_entries?: Array<{ id: number; row_version: number }>
  /** Dimenze dokladu uložené v téže transakci (bez `items` zůstanou dimenze položek). */
  dimensions?: { header: Record<number, number>; items?: Record<number, Record<number, number>> }
}

/**
 * Předkontace (`posting_rules`), podle které kontace vznikla. `used = false` znamená,
 * že se její účty v zápisu neobjevily — zápis tedy vznikl jinak (ruční úprava, jiné
 * nastavení v době účtování) a UI to musí říct místo aby předstíralo šablonu.
 */
export interface PostingOriginPreset {
  rule_key: string
  description: string | null
  debit_account_code: string | null
  credit_account_code: string | null
  scope: 'global' | 'company' | null
  exists: boolean
  /** Proč zrovna tenhle klíč: klíč výnosu na hlavičce, druh výdaje, spárovaná platba, výchozí. */
  reason: 'revenue_key' | 'expense_kind' | 'payment_match' | 'default'
  used: boolean
}

export interface PostingOriginRule {
  type: 'expense' | 'bank'
  id: number
  name: string | null
  exists: boolean
  is_active: boolean | null
}

/**
 * Podle čeho kontace dokladu vznikla — vrstvy se liší podle druhu dokladu
 * (manuál § 43.2.1). `origin = 'manual_repost'` přebíjí všechno ostatní: za kontací
 * stojí účetní, ne šablona.
 */
export interface PostingOrigin {
  source_type: 'invoice' | 'purchase_invoice' | 'bank'
  entry_id: number | null
  posted: boolean
  origin: 'preset' | 'expense_rule' | 'item_account' | 'rule' | 'detector' | 'learned' | 'matched'
    | 'schedule' | 'ai' | 'manual' | 'manual_repost' | 'unknown'
  manual_repost: { at: string; by: string | null } | null
  presets: PostingOriginPreset[]
  rules: PostingOriginRule[]
  detector: string | null
  /** Účty z položky, produktu nebo kategorie (F1) — přebíjejí předkontaci u svých řádků. */
  item_accounts?: PostingOriginItemAccount[]
}

export interface PostingOriginItemAccount {
  account_code: string
  source: 'item' | 'product' | 'product_category'
  product_id: number | null
  product_name: string | null
  used: boolean
}

export const accountingApi = {
  // Účtová osnova
  listAccounts: (opts?: { tree?: boolean; includeInactive?: boolean; supplierId?: number }) => {
    const params: Record<string, string> = {}
    if (opts?.tree) params.tree = '1'
    if (opts?.includeInactive) params.include_inactive = '1'
    return api.get<ChartAccount[]>('/accounting/accounts', { params, ...(opts?.supplierId ? { headers: { 'X-Supplier-Id': String(opts.supplierId) } } : {}) }).then(r => r.data)
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
  /** Začátek prvního účetního období (firma vzniklá v průběhu roku, § 3 ZoÚ). */
  updatePeriodStart: (id: number, startsOn: string, rowVersion: number) =>
    api.patch<AccountingPeriod>(`/accounting/periods/${id}`, { starts_on: startsOn, row_version: rowVersion }).then(r => r.data),

  // Deník
  listJournal: (filters?: JournalFilters) => {
    const params: Record<string, string | number> = {}
    if (filters?.include_vat_breakdown) params.include_vat_breakdown = 1
    if (filters?.include_posting_accounts) params.include_posting_accounts = 1
    if (filters?.include_dimensions) params.include_dimensions = 1
    if (filters?.include_notes) params.include_notes = 1
    if (filters?.include_documents) params.include_documents = 1
    if (filters?.sort_key) params.sort_key = filters.sort_key
    if (filters?.sort_dir) params.sort_dir = filters.sort_dir
    if (filters?.document_no) params.document_no = filters.document_no
    if (filters?.period_id) params.period_id = filters.period_id
    if (filters?.date_from) params.date_from = filters.date_from
    if (filters?.date_to) params.date_to = filters.date_to
    if (filters?.source_type) params.source_type = filters.source_type
    if (filters?.source_id) params.source_id = filters.source_id
    if (filters?.entry_id) params.entry_id = filters.entry_id
    if (filters?.posted !== undefined) params.posted = filters.posted ? '1' : '0'
    if (filters?.reversal) params.reversal = filters.reversal
    if (filters?.automation) params.automation = filters.automation
    if (filters?.q) params.q = filters.q
    if (filters?.account_from) params.account_from = filters.account_from
    if (filters?.account_to) params.account_to = filters.account_to
    if (filters?.amount_from !== undefined) params.amount_from = filters.amount_from
    if (filters?.amount_to !== undefined) params.amount_to = filters.amount_to
    if (filters?.integrity) params.integrity = filters.integrity
    if (filters?.dimension_value_id) {
      params.dimension_value_id = filters.dimension_value_id
      if (filters.dimension_descendants === false) params.dimension_descendants = 0
    }
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
  // Přeúčtování už zaúčtovaného dokladu. Plán se ptá TÉŽE služby, která operaci
  // provede, takže se náhled s výsledkem nemůže rozejít.
  repostPlan: (source: JournalPostingSource, id: number) =>
    api.get<RepostPlan>(`/accounting/journal/repost-plan/${source}/${id}`).then(r => r.data),
  /** Totéž rozhodnutí nad opravenými řádky z dialogu (na místě × storno), bez zápisu. */
  repostPlanForLines: (source: JournalPostingSource, id: number, lines: RepostPayload['lines']) =>
    api.post<RepostPlan>(`/accounting/journal/repost-plan/${source}/${id}`, { lines }).then(r => r.data),
  repost:(source: JournalPostingSource, id: number, payload: RepostPayload) =>
    api.post<JournalEntryDetail & { repost: RepostResult }>(
      `/accounting/journal/repost/${source}/${id}`, payload).then(r => r.data),
  /** Podle jaké šablony kontace vznikla a kde se ta šablona opraví. */
  postingOrigin: (source: JournalPostingSource, id: number) =>
    api.get<PostingOrigin>(`/accounting/journal/posting-origin/${source}/${id}`).then(r => r.data),
  reverseEntry: (id: number) =>
    api.post<JournalEntryDetail>(`/accounting/journal/${id}/reverse`).then(r => r.data),
  /** `ackLocked` = účetní potvrdil zásah do uzamčeného období (odpověď 409 `date_locked` s `can_acknowledge`). */
  deleteEntry: (id: number, ackLocked = false) =>
    api.delete<{ ok: boolean }>(`/accounting/journal/${id}`, {
      params: ackLocked ? { ack_locked: 1 } : undefined,
    }).then(r => r.data),
  /** Smaže celou storno dvojici (zápis i jeho protizápis) v otevřeném období. */
  deleteEntryReversalPair: (id: number, ackLocked = false) =>
    api.delete<{ ok: boolean; deleted_entry_ids: number[] }>(`/accounting/journal/${id}/reversal-pair`, {
      params: ackLocked ? { ack_locked: 1 } : undefined,
    }).then(r => r.data),
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
    const s = requestSupplierId()
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
  postingRuleChartAlignment: () =>
    api.get<ChartAlignmentPreview>('/accounting/posting-rules/chart-alignment').then(r => r.data),
  applyPostingRuleChartAlignment: (items: ChartAlignmentApplyItem[]) =>
    api.post<{ applied: string[]; skipped: string[] }>(
      '/accounting/posting-rules/chart-alignment', { items },
    ).then(r => r.data),

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
  getOpenItems: (accountId: number, params: { as_of: string; only_open: 0 | 1; page?: number; per_page?: number }) =>
    api.get<OpenItemsReport>(`/accounting/open-items/${accountId}`, { params }).then(r => r.data),
  getLinePairing: (pairingId: number) =>
    api.get<LinePairing>(`/accounting/open-items/pairings/${pairingId}`).then(r => r.data),
  createLinePairing: (accountId: number, lineIds: number[], note?: string | null) =>
    api.post<LinePairing>(`/accounting/open-items/${accountId}/pairings`, { line_ids: lineIds, note: note ?? null }).then(r => r.data),
  addLinesToPairing: (pairingId: number, lineIds: number[]) =>
    api.post<LinePairing>(`/accounting/open-items/pairings/${pairingId}/lines`, { line_ids: lineIds }).then(r => r.data),
  removeLineFromPairing: (pairingId: number, entryId: number, lineNo: number) =>
    api.delete<{ pairing: LinePairing | null }>(`/accounting/open-items/pairings/${pairingId}/lines/${entryId}/${lineNo}`).then(r => r.data),
  deleteLinePairings: (pairingIds: number[]) =>
    api.post<{ deleted: number }>('/accounting/open-items/pairings/delete', { pairing_ids: pairingIds }).then(r => r.data),
  getPairingSuggestions: (accountId: number, params: { as_of: string; days: number }) =>
    api.get<{ as_of: string; days: number; items: PairingSuggestion[] }>(`/accounting/open-items/${accountId}/suggestions`, { params }).then(r => r.data),
  applyPairingSuggestions: (accountId: number, payload: { as_of: string; days: number; pairs?: number[][] }) =>
    api.post<{ created: number }>(`/accounting/open-items/${accountId}/suggestions/apply`, payload).then(r => r.data),
  getBalanceSheet: (params: StatementParams) =>
    api.get<BalanceSheetReport | Statement504Report>('/accounting/reports/balance-sheet', { params }).then(r => r.data),
  getIncomeStatement: (params: StatementParams) =>
    api.get<IncomeStatementReport | Statement504Report>('/accounting/reports/income-statement', { params }).then(r => r.data),
  /** Rozvaha a výsledovka po účtech s hospodářským výsledkem (zůstatky před uzávěrkou). */
  getStatementAccounts: (params: Omit<StatementParams, 'scope'>) =>
    api.get<StatementAccountsReport | Statement504Report>('/accounting/reports/statement-accounts', { params }).then(r => r.data),
  /** Odhad do konce otevřeného roku (náhled DPPO, uzávěrka, odpisy, zálohy) — samostatný request. */
  getYearEndTaxEstimate: (periodId: number) =>
    api.get<YearEndTaxEstimate>('/accounting/reports/statement-accounts/tax-estimate', { params: { period_id: periodId } }).then(r => r.data),
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
  /** Výjimky mapování účtů do výkazů — účty, řádky výkazu, výjimky firmy a podané DPPO roku. */
  getStatementOverrides: (periodId: number, statementType: StatementOverrideType) =>
    api.get<StatementOverrideOverview>('/accounting/reports/statement-overrides', {
      params: { period_id: periodId, statement_type: statementType },
    }).then(r => r.data),
  /** Uloží CELOU sadu výjimek verze výkazu (jedno společné Uložit editoru). */
  saveStatementOverrides: (versionId: number, overrides: StatementOverride[]) =>
    api.put<{ overrides: StatementOverride[] }>('/accounting/reports/statement-overrides', {
      version_id: versionId,
      overrides,
    }).then(r => r.data),
  /** Náhled dopadu neuložené sady — nic neukládá. */
  previewStatementOverrides: (periodId: number, statementType: StatementOverrideType, overrides: StatementOverride[]) =>
    api.post<StatementOverridePreview>('/accounting/reports/statement-overrides/preview', {
      period_id: periodId,
      statement_type: statementType,
      overrides,
    }).then(r => r.data),
  /** Návrh výjimek z podaného DPPO v evidenci — nic nezapisuje. */
  suggestStatementOverrides: (periodId: number) =>
    api.get<StatementOverrideSuggestions>('/accounting/reports/statement-overrides/suggestions', {
      params: { period_id: periodId },
    }).then(r => r.data),
  /** Návrh výjimek z nahraného XML podaného přiznání DPPDP9. */
  suggestStatementOverridesFromXml: (periodId: number, file: File) => {
    const fd = new FormData()
    fd.append('file', file, file.name)
    return api.post<StatementOverrideSuggestions>('/accounting/reports/statement-overrides/suggestions', fd, {
      params: { period_id: periodId },
      headers: { 'Content-Type': 'multipart/form-data' },
    }).then(r => r.data)
  },
  getSaldo: (params: SaldoParams) =>
    api.get<SaldoReport>('/accounting/reports/saldo', { params }).then(r => r.data),
  // Vyrovnání položky saldokonta zápisem v deníku (rozpad řádku na doklady).
  getSaldoSettlementCandidates: (params: { doc_type: SaldoSettleDocType; doc_id: number; account: string; as_of?: string }) =>
    api.get<SaldoSettlementCandidates>('/accounting/saldo-settlements/candidates', { params }).then(r => r.data),
  getSaldoResidualLines: (params: { account: string; as_of?: string; q?: string }) =>
    api.get<{ items: SaldoSettlementLine[] }>('/accounting/saldo-settlements/residual-lines', { params }).then(r => r.data.items),
  getSaldoSettlementSuggestions: (params: { period_id: number; as_of?: string; account?: string }) =>
    api.get<SaldoSettlementSuggestions>('/accounting/saldo-settlements/suggestions', { params }).then(r => r.data),
  /** `force` = správce potvrdil zápis v uzavřeném období (server jinak vrací 409 period_closed). */
  createSaldoAllocations: (payload: { allocations: SaldoAllocationInput[] } | { groups: { allocations: SaldoAllocationInput[] }[] }, force = false) =>
    api.post<SaldoAllocationResult>('/accounting/saldo-settlements', payload, { params: force ? { force: 1 } : {} }).then(r => r.data),
  deleteSaldoAllocation: (id: number, force = false) =>
    api.delete<{ deleted: number }>(`/accounting/saldo-settlements/${id}`, { params: force ? { force: 1 } : {} }).then(r => r.data),
  // Vyrovnání zálohy (314/324) čerpáním konečnou fakturou.
  getSaldoAdvanceCandidates: (params: { doc_type: SaldoSettleDocType; doc_id: number; account: string; as_of?: string }) =>
    api.get<SaldoAdvanceCandidates>('/accounting/saldo-settlements/advance-candidates', { params }).then(r => r.data),
  createSaldoAdvanceDeductions: (payload: { deductions: SaldoAdvanceDeductionInput[] } | { groups: { deductions: SaldoAdvanceDeductionInput[] }[] }, force = false) =>
    api.post<SaldoAdvanceDeductionResult>('/accounting/saldo-settlements/advance-deductions', payload, { params: force ? { force: 1 } : {} }).then(r => r.data),
  deleteSaldoAdvanceDeduction: (docType: SaldoSettleDocType, id: number, force = false) =>
    api.delete<{ deleted: number }>(`/accounting/saldo-settlements/advance-deductions/${docType}/${id}`, { params: force ? { force: 1 } : {} }).then(r => r.data),
  createSaldoOtherItem: (payload: { line_id: number; amount: number; title?: string; partner_id?: number | null; due_on?: string }, force = false) =>
    api.post<{ item: { id: number; document_no: string | null }; allocation_id: number }>(
      '/accounting/saldo-settlements/other-item', payload, { params: force ? { force: 1 } : {} }).then(r => r.data),
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
  upsertRepoRate: (payload: { valid_from: string; rate: number; note?: string | null }) =>
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

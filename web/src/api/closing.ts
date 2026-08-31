import { api } from './client'
import type { AccountingPeriod, ReportingSettings } from './accounting'

/**
 * Uzávěrka období, archiv, číselné řady a převody přes 261 (Epic F4) —
 * typovaný klient pro /api/accounting. Tenant-scoped přes X-Supplier-Id
 * (přidává api/client.ts). Mutace období nesou row_version (CAS, R4);
 * nesoulad verze vrací 409 { error: { code: 'version_conflict' } } —
 * klient má reloadnout stav. Admin-only kroky vynucuje server.
 */

// ── Období se stavem approved (F4 R2) ──────────────────────────────────────
export type ClosingPeriodStatus = 'open' | 'closing' | 'closed' | 'approved'

export interface ClosingPeriod extends Omit<AccountingPeriod, 'status'> {
  status: ClosingPeriodStatus
  row_version: number
  closed_by?: number | null
  approved_at?: string | null
  approved_by?: number | null
}

export interface PeriodStatusPayload {
  status: ClosingPeriodStatus
  row_version: number
  reason?: string
  confirm?: boolean
}

// ── Kroky průvodce (accounting_closing_steps) ──────────────────────────────
export type ClosingStepKey =
  | 'precheck'
  | 'depreciation'
  | 'fx_revaluation'
  | 'estimates'
  | 'deferrals'
  | 'provisions'
  | 'income_tax'
  | 'stock'
  | 'close_books'
  | 'open_next'

export type ClosingStepStatus = 'pending' | 'done' | 'skipped'

export type PrecheckSeverity = 'error' | 'warning' | 'info'

export interface PrecheckItem {
  key: string
  severity: PrecheckSeverity
  /** Splněno/bez nálezu — precheck i měsíční kontrola (D8) shodně. */
  ok: boolean
  value: unknown
  note?: string
}

// ── Měsíční kontrola (audit 2026-07, D8) ───────────────────────────────────
/**
 * Návrh doúčtování nálezu. `proposal` je null tam, kde z dat neplyne jednoznačné
 * účetní řešení — pak se otevře prázdný zápis místo dohadu k odklepnutí.
 */
export interface FindingRemedy {
  issue: string
  doc_type: string
  doc_id: number
  doc_no: string | null
  partner_name: string | null
  entry_date: string
  impact_czk: number
  detail: Record<string, unknown>
  proposal: {
    kind: string
    label: string
    description: string
    lines: { account_code: string; side: string; amount: number }[]
  } | null
}

export interface MonthlyCheckResult {
  period: ClosingPeriod
  range_from: string
  range_to: string
  checks: PrecheckItem[]
  ran_at: string
}

/** Zápis vytvořený asistentem (payload.entries[] kroku estimates/deferrals). */
export interface AssistedEntryRef {
  id: number
  /** Backend (ClosingService::createAssistedEntry) ukládá klíč entry_id. */
  entry_id?: number
  document_no?: string | null
  amount?: number
  description?: string | null
  rule_key?: string
}

// ── Krok zásob (SKLAD §3.4, uzávěrka způsobem B / ČÚS 015) ─────────────────
/** Trojice hodnot per typ zásoby (materiál 112/501, zboží 132/504, výrobky 123/583). */
export interface StockTotalsGroup {
  material: number
  goods: number
  product: number
}

/** Souhrny kroku zásob z {@see StockClosingValuation::totals} (uloženo v payload.totals). */
export interface StockTotals {
  /** Konečný stav (hodnota) k rozvahovému dni. */
  closing: StockTotalsGroup
  /** Konečný stav (množství). */
  closing_qty: StockTotalsGroup
  /** Inventurní manka (reklasifikace na 549). */
  shortage: StockTotalsGroup
  /** Inventurní přebytky (648). */
  surplus: StockTotalsGroup
}

/** Podklad k ověření (příjemky bez faktury, faktury bez příjemky) — payload.warnings. */
export interface StockWarning {
  key: string
  message: string
  items: Record<string, unknown>[]
}

export interface ClosingStepPayload {
  checks?: PrecheckItem[]
  entries?: (AssistedEntryRef | number)[]
  reversed?: (AssistedEntryRef | number)[]
  entry_id?: number
  fx_reversal_entry_id?: number | null
  profit?: number
  document_no?: string
  lines_count?: number
  detail?: FxDetailRow[]
  rate_info?: FxRateInfo[]
  bank_rows?: FxBankRow[]
  // Krok zásob (stock): totals + zaúčtované sloty + podklady; skipped nese jen `reason`.
  totals?: StockTotals
  entry_ids?: Record<string, number>
  warnings?: StockWarning[]
  reason?: string
}

export interface ClosingStep {
  id?: number
  step_key: ClosingStepKey
  status: ClosingStepStatus
  payload: ClosingStepPayload | null
  note: string | null
  done_at: string | null
  done_by: number | null
}

/** GET /periods/{id}/closing — období + kroky + odvozené flagy (§3.4). */
export interface ClosingState {
  period: ClosingPeriod
  steps: ClosingStep[]
  can_start?: boolean
  /** Uložený precheck snímek už neodpovídá živým kontrolám (změnila se data,
   *  typicky uzavření předchozího období) — FE vyzve k opětovnému spuštění. */
  precheck_stale?: boolean
  can_abort?: boolean
  can_close?: boolean
  can_open_next?: boolean
  can_revert_fx_revaluation?: boolean
  can_revert_stock?: boolean
  can_revert_close_books?: boolean
  can_revert_open_next?: boolean
  /** Krok je vyžadován k uzavření knih jen tehdy, dává-li v období smysl. */
  stock_step_required?: boolean
  depreciation_step_required?: boolean
}

// ── Featura I: audit spárovaných plateb banka↔faktura ──────────────────────
export type PaymentMatchAuditIssue = 'currency_mismatch' | 'amount_mismatch' | 'fx_on_czk_czk' | 'counterparty_mismatch'

export interface PaymentMatchAuditItem {
  match_kind: 'invoice' | 'purchase_invoice'
  doc_id: number
  doc_no: string | null
  partner_name: string
  bank_transaction_id: number
  tx_posted_at: string
  tx_currency: string
  doc_currency: string
  tx_amount: number
  doc_amount_to_pay: number
  issues: PaymentMatchAuditIssue[]
  impact_czk: number
  detail: Record<string, Record<string, unknown>>
}

// ── Kurzové rozdíly (R10) ──────────────────────────────────────────────────
export interface FxRateInfo {
  currency: string
  rate: number
  rate_date: string
  fallback_used: boolean
}

/** Rozpad saldokonta per doklad (§3.2 detail). */
export interface FxDetailRow {
  doc_type: 'invoice' | 'purchase_invoice'
  doc_id: number
  varsymbol: string | null
  currency_code: string
  remaining_foreign: number
  fx_rate: number
  rate_cnb: number
  diff: number
  account_code?: string
}

/** Agregovaný řádek budoucího zápisu (per účet × měna × směr). */
export interface FxAggregateLine {
  account_id?: number
  account_code?: string
  currency_code?: string
  side?: 'debit' | 'credit'
  amount?: number
  diff?: number
}

/** Editovatelný řádek banky/valutové pokladny (R10b). */
export interface FxBankRow {
  account_code: string
  currency_code: string
  foreign_balance: number | null
  ledger_czk?: number
  new_czk?: number
  diff?: number
  statement_date?: string | null
  label?: string | null
}

/** Návrh devizového zůstatku bankovního/pokladního účtu z deníku k D (R10b). */
export interface FxBankProposal {
  account_code: string
  currency_code: string
  label: string | null
  foreign_balance: number
  czk_balance: number
}

export interface FxPreview {
  rate_info: FxRateInfo[]
  saldo: { lines: FxAggregateLine[]; detail: FxDetailRow[] }
  bank: { lines: FxBankRow[] }
  proposals?: FxBankProposal[]
  totals: { loss: number; gain: number }
}

// ── Body mutací (§4.3) ─────────────────────────────────────────────────────
export interface RunStepPayload {
  row_version: number
  status?: 'done' | 'skipped'
  note?: string
  bank_rows?: { account_code: string; currency_code: string; foreign_balance: number }[]
  /** D9 provisions — účetní-potvrzený seznam OP per pohledávka. */
  items?: ProvisionInput[]
  /** D11 income_tax — částka splatné daně (591/341). */
  amount?: number
  /** §DM / Task 11 — časové rozlišení drobného majetku (deferrals): režim + volitelné %. */
  small_asset_accrual?: { mode: SmallAssetAccrualMode; pct?: number | null }
  /** §DČR / Task 12 — časové rozlišení nákladů příštích období (deferrals): trigger, částky z faktur. */
  prepaid_expense_accrual?: boolean
}

// ── §DM / Task 11: časové rozlišení drobného majetku (381 = volitelná politika) ──
export type SmallAssetAccrualMode = 'none' | 'pro_rata' | 'flat_pct'

export interface SmallAssetAccrualItem {
  small_asset_id: number
  name: string
  document_ref: string | null
  acquisition_date: string
  // EP-15: datum uvedení do užívání (kotva pro_rata) + doložená doba použitelnosti (měsíce).
  in_use_date?: string
  useful_months?: number | null
  price: number
  status: string
  fraction: number | null
  // U flat_pct je null — odklad se počítá z obratu 501.200, ne per-kartu (karta je jen evidence).
  deferred_amount: number | null
}

export interface SmallAssetAccrualPreview {
  as_of: string
  period: { id: number; fiscal_year: number; starts_on: string; ends_on: string }
  mode: SmallAssetAccrualMode
  pct: number | null
  period_days: number
  items: SmallAssetAccrualItem[]
  total: number
  // EP-15: test přiměřenosti paušálu — báze 501.200 vs zdokumentovaný limit významnosti.
  materiality: { base: number; limit: number | null; passes: boolean } | null
  cards_total: number
  breakdown_501_small_asset: number
  cards_vs_501_diff: number
  existing: { entry_id: number; mode: string | null; pct: number | null; amount: number | null } | null
}

// ── §DČR / Task 12: časové rozlišení nákladů příštích období (381 z označených faktur) ──
export interface PrepaidExpenseAccrualItem {
  item_id: number
  purchase_invoice_id: number
  vendor_invoice_number: string
  description: string
  currency_code: string
  total_without_vat: number
  total_czk: number
  credit_account: string
  accrual_from: string
  accrual_to: string
  total_days: number
  deferred_days: number
  fraction: number
  deferred_amount: number
  // EP-15: harmonogram rozpouštění po (kalendářních) obdobích; Σ = deferred_amount.
  release_schedule?: { fiscal_year: number; amount: number }[]
}

export interface PrepaidExpenseAccrualPreview {
  as_of: string
  period: { id: number; fiscal_year: number; starts_on: string; ends_on: string }
  next_period_start: string
  items: PrepaidExpenseAccrualItem[]
  documents: { purchase_invoice_id: number; vendor_invoice_number: string; deferred_amount: number }[]
  by_account: Record<string, number>
  total: number
  existing: { entry_id: number; amount: number | null } | null
}

// ── D9: opravné položky k pohledávkám ──────────────────────────────────────
export interface ProvisionItem {
  invoice_id: number
  document_no: string
  partner_id: number | null
  partner_name: string
  issue_date: string | null
  due_date: string
  days_overdue: number
  months_overdue: number
  remaining: number
  currency_code: string | null
  legal_section: '8a' | '8c' | null
  suggested_legal_pct: number
  suggested_legal_amount: number
  suggested_acct_amount: number
  potentially_time_barred: boolean
  warning: string | null
  existing: { entry_id: number; legal_amount: number; acct_amount: number } | null
}

export interface ProvisionsPreview {
  as_of: string
  period: { id: number; fiscal_year: number }
  items: ProvisionItem[]
  totals: { remaining: number; suggested_legal: number; existing_legal: number; existing_acct: number }
  rules: { legal_8a_50_months: number; legal_8a_100_months: number; legal_8c_months: number; legal_8c_limit: number; limitation_warning_months: number }
}

// ── K10: návrh dohadných položek pasivních (389) ───────────────────────────
export interface EstimateSuggestItem {
  vendor_id: number
  vendor_name: string
  rule_key: 'estimate.liability'
  months_present: number
  sample_count: number
  last_invoice_date: string
  suggested_amount: number
  counter_account: string | null
  currency_code: string
  reason: 'recurring_missing_last_month'
  description: string
}

export interface EstimatesSuggest {
  as_of: string
  period: { id: number; fiscal_year: number }
  items: EstimateSuggestItem[]
  totals: { suggested_amount: number; count: number }
  rules: { target_month: string; min_recurring_months: number; sample_size: number }
}

export interface ProvisionInput {
  invoice_id: number
  legal_amount: number
  acct_amount: number
  note?: string
  document_no?: string
}

// ── D11: splatná daň z příjmů ──────────────────────────────────────────────
export interface IncomeTaxPreview {
  as_of: string
  period: { id: number; fiscal_year: number }
  suggested_amount: number | null
  suggested_source: 'finalized_return' | 'computed_from_ledger' | null
  has_finalized_return: boolean
  taxpayer_type: string | null
  applicable: boolean
  rate_hint: number
  balance_341: number
  balance_591: number
  existing_entry_id: number | null
  existing_amount: number | null
}

// ── D10: rozdělení výsledku hospodaření ────────────────────────────────────
export interface ProfitDistributionPreview {
  approved_period: { id: number; fiscal_year: number; status: string }
  target_period: { id: number; fiscal_year: number; row_version: number; starts_on: string; ends_on: string }
  balance_431: number
  available_profit: number
  retained_profit: number
  uncovered_loss: number
  distributable_resources: number
  is_loss: boolean
  withholding_rate: number
  existing_entry_id: number | null
}

export interface ProfitDistributionAllocation {
  account_code: string
  amount: number
  kind: 'retained' | 'fund' | 'shares' | 'loss_coverage'
}

export interface ProfitDistributionPayload {
  decision_date: string
  target_row_version: number
  allocations: ProfitDistributionAllocation[]
  withholding_rate?: number
}

export interface AssistedEntryPayload {
  row_version: number
  step: 'estimates' | 'deferrals'
  rule_key: string
  amount: number
  description: string
  counter_account?: string
}

// ── Číselné řady (R13) ─────────────────────────────────────────────────────
export type SeriesCode =
  | 'closing' | 'opening' | 'fx' | 'transfer' | 'manual'
  | 'cash_in' | 'cash_out'
  | 'stock_in' | 'stock_out' | 'stock_transfer'
  | 'offset' | 'purchase_order'

/**
 * Výchozí prefixy řad = zrcadlo DocumentSeriesService::DEFAULT_PREFIXES. Řádek řady
 * v DB vzniká lazy až prvním výdejem čísla, takže UI z nich skládá dosud neexistující
 * řady, aby šly nastavit dopředu (převzetí řady z jiného systému).
 */
export const SERIES_DEFAULT_PREFIXES: Record<SeriesCode, string> = {
  closing: 'UZ',
  opening: 'OT',
  fx: 'KR',
  transfer: 'PP',
  manual: 'ID',
  cash_in: 'PPD',
  cash_out: 'VPD',
  stock_in: 'PRI',
  stock_out: 'VYD',
  stock_transfer: 'PRE',
  offset: 'ZAP',
  purchase_order: 'OBJ',
}

/**
 * Řady vázané na účetní deník = zrcadlo `DocumentSeriesService::DOUBLE_ENTRY_ONLY_SERIES`.
 * Daňová evidence je nevydává (a server je pro ni ani nevrací), pokladní / skladové /
 * objednávkové řady naopak používá.
 */
export const SERIES_DOUBLE_ENTRY_ONLY: SeriesCode[] = ['closing', 'opening', 'fx', 'transfer', 'manual', 'offset']

export interface DocumentSeries {
  id?: number
  series_code: SeriesCode
  /** L-3: 0 = společná řada firmy, >0 = vlastní řada té pokladny. */
  register_id?: number
  /** Název pokladny u vlastní řady — jen pro zobrazení (BE dopočítává). */
  register_name?: string | null
  fiscal_year: number
  prefix: string
  /** Šablona čísla ({PREFIX}/{YYYY}/{YY}/{C+}); null = vestavěné {PREFIX}-{YYYY}-{CCCC}. */
  number_format: string | null
  next_number: number
}

/** Aspoň jedna položka; number_format = '' vrátí řadu na vestavěnou šablonu. */
export interface DocumentSeriesPatch {
  register_id?: number
  prefix?: string
  number_format?: string | null
  next_number?: number
}

// ── Převod přes 261 (R14) ──────────────────────────────────────────────────
export interface TransferPayload {
  date_out: string
  date_in: string
  amount: number
  account_from: string
  account_to: string
  description?: string
  force?: boolean
}

export interface TransferResult {
  entries?: { id: number; document_no: string | null }[]
  document_no?: string
}

// ── Podklad DPPO (R19, TaxBaseReportAction) ────────────────────────────────
export interface TaxBaseDisposalRow {
  asset_id: number
  inventory_number: string
  name: string
  disposal_date: string
  disposal_type: string
  disposal_price: number | null
  tax_residual_value: number
  accounting_residual_value: number | null
  deductibility: 'full' | 'none' | 'limited'
  note: string
}

/** Výsledek zaúčtování odpisů v kroku 2 uzávěrky (shodný tvar s modulem Majetek). */
export interface DepreciationBookResult {
  booked: number
  skipped: number
  total_accounting: number
  total_tax: number
  errors: { asset_id: number; code: string }[]
}

export interface TaxBaseAdjustments {
  fiscal_year: number
  period: { id: number; starts_on: string; ends_on: string }
  depreciation: { tax_total: number; accounting_total: number; difference: number; note: string }
  disposals: TaxBaseDisposalRow[]
  info: {
    estimates_388_balance: number
    estimates_389_balance: number
    fx_revaluation_loss_563: number
    fx_revaluation_gain_663: number
  }
  note: string
}

// ── Nastavení účetnictví F4 (statutory_audit / manual_doc_series / fx_reversal_at_open) ──
export interface AccountingClosingSettings extends ReportingSettings {
  statutory_audit?: boolean | number
  manual_doc_series?: boolean | number
  fx_reversal_at_open?: boolean | number
  // §DM / Task 14 — účetní politika časového rozlišení drobného majetku na 381 (§7 ZoÚ).
  small_asset_accrual_mode?: SmallAssetAccrualMode
  small_asset_accrual_pct?: number | null
}

// ── API klienti ────────────────────────────────────────────────────────────
export const closingApi = {
  state: (periodId: number) =>
    api.get<ClosingState>(`/accounting/periods/${periodId}/closing`).then(r => r.data),
  start: (periodId: number, rowVersion: number) =>
    api.post<ClosingState>(`/accounting/periods/${periodId}/closing/start`, { row_version: rowVersion }).then(r => r.data),
  abort: (periodId: number, rowVersion: number) =>
    api.post<ClosingState>(`/accounting/periods/${periodId}/closing/abort`, { row_version: rowVersion }).then(r => r.data),
  fxPreview: (periodId: number, bankRows?: { account_code: string; currency_code: string; foreign_balance: number }[]) => {
    const params: Record<string, string> = {}
    if (bankRows) params.bank_rows = JSON.stringify(bankRows)
    return api.get<FxPreview>(`/accounting/periods/${periodId}/closing/fx-preview`, { params }).then(r => r.data)
  },
  // D9 — náhled OP k pohledávkám (aging 311 + návrh §8a/§8c).
  provisionsPreview: (periodId: number) =>
    api.get<ProvisionsPreview>(`/accounting/periods/${periodId}/closing/provisions-preview`).then(r => r.data),
  // K10 — návrh dohadných položek pasivních (opakující se náklad bez faktury k rozvahovému dni).
  estimatesSuggest: (periodId: number) =>
    api.get<EstimatesSuggest>(`/accounting/periods/${periodId}/closing/estimates-suggest`).then(r => r.data),
  // D11 — náhled podkladu splatné daně (DPPO + zůstatky 341/591).
  incomeTaxPreview: (periodId: number) =>
    api.get<IncomeTaxPreview>(`/accounting/periods/${periodId}/closing/income-tax-preview`).then(r => r.data),
  // §DM / Task 11 — náhled časového rozlišení drobného majetku (381/501) dle režimu.
  smallAssetAccrualPreview: (periodId: number, mode?: SmallAssetAccrualMode, pct?: number | null, materialityLimit?: number | null) => {
    const params: Record<string, string> = {}
    if (mode) params.mode = mode
    if (pct != null) params.pct = String(pct)
    // Limit z formuláře, ještě neuložený — bez něj náhled hlásí „limit chybí" i poté,
    // co ho účetní vyplní, a přiměřenost si nejde ověřit před zápisem.
    if (materialityLimit != null) params.materiality_limit = String(materialityLimit)
    return api.get<SmallAssetAccrualPreview>(`/accounting/periods/${periodId}/closing/small-asset-accrual-preview`, { params }).then(r => r.data)
  },
  // §DČR / Task 12 — náhled časového rozlišení nákladů příštích období (381/5xx z faktur).
  prepaidExpenseAccrualPreview: (periodId: number) =>
    api.get<PrepaidExpenseAccrualPreview>(`/accounting/periods/${periodId}/closing/prepaid-expense-accrual-preview`).then(r => r.data),
  // D10 — rozdělení výsledku hospodaření (431 → 428/429/364…).
  profitDistributionPreview: (periodId: number) =>
    api.get<ProfitDistributionPreview>(`/accounting/periods/${periodId}/profit-distribution/preview`).then(r => r.data),
  profitDistribution: (periodId: number, payload: ProfitDistributionPayload) =>
    api.post(`/accounting/periods/${periodId}/profit-distribution`, payload).then(r => r.data),
  profitDistributionRevert: (periodId: number, targetRowVersion: number) =>
    api.post(`/accounting/periods/${periodId}/profit-distribution/revert`, { target_row_version: targetRowVersion }).then(r => r.data),
  /**
   * Zaúčtování odpisů roku v kroku 2 uzávěrky. Jediná cesta, která umí zapsat odpisy do
   * období ve stavu `closing` — modul Majetek účtuje striktně do otevřeného období, takže
   * po zahájení uzávěrky tam už neprojde (`period_not_open`).
   */
  bookDepreciation: (periodId: number) =>
    api.post<DepreciationBookResult>(`/accounting/periods/${periodId}/closing/book-depreciation`, {})
      .then(r => r.data),
  runStep: (periodId: number, step: ClosingStepKey, payload: RunStepPayload) =>
    api.post(`/accounting/periods/${periodId}/closing/steps/${step}/run`, payload).then(r => r.data),
  revertStep: (periodId: number, step: ClosingStepKey, rowVersion: number) =>
    api.post(`/accounting/periods/${periodId}/closing/steps/${step}/revert`, { row_version: rowVersion }).then(r => r.data),
  createEntry: (periodId: number, payload: AssistedEntryPayload) =>
    api.post(`/accounting/periods/${periodId}/closing/entries`, payload).then(r => r.data),
  reverseEntry: (periodId: number, entryId: number, rowVersion: number) =>
    api.post(`/accounting/periods/${periodId}/closing/entries/${entryId}/reverse`, { row_version: rowVersion }).then(r => r.data),
  close: (periodId: number, rowVersion: number, override?: { override_unposted: true; override_reason: string }) =>
    api.post(`/accounting/periods/${periodId}/close`, { row_version: rowVersion, ...(override ?? {}) }).then(r => r.data),
  openNext: (periodId: number, rowVersion: number) =>
    api.post(`/accounting/periods/${periodId}/open-next`, { row_version: rowVersion }).then(r => r.data),
  // Stavový automat období (R2) — closed→approved, approved→closed, closed→open (reason ≥ 10 znaků).
  setPeriodStatus: (periodId: number, payload: PeriodStatusPayload) =>
    api.post<ClosingPeriod>(`/accounting/periods/${periodId}/status`, payload).then(r => r.data),
  // Návrh doúčtování k jednomu nálezu. Server si nález spočítá znovu — částku zápisu
  // nesmí určovat klient a mezitím vyřešený nález vrátí 404 místo návrhu.
  findingRemedy: (periodId: number, docType: string, docId: number, issue: string) =>
    api.get<FindingRemedy>(`/accounting/periods/${periodId}/finding-remedy`, {
      params: { doc_type: docType, doc_id: docId, issue },
    }).then(r => r.data),
  // Detail JEDNÉ kontroly načtený živě. Popup se nesmí plnit z uloženého snímku kroku —
  // ten je useknutý na deset položek a je z okamžiku běhu prechecku, takže po opravě
  // dokladu ukazoval už vyřešené nálezy.
  checkFindings: (periodId: number, key: string, dateFrom?: string, dateTo?: string) =>
    api.get<{ key: string; severity: string; ok: boolean; value: unknown }>(
      `/accounting/periods/${periodId}/checks/${key}`,
      { params: { date_from: dateFrom, date_to: dateTo } },
    ).then(r => r.data),
  // Měsíční kontrola (D8) — buildChecks nad libovolným rozsahem, kdykoli, bez uzávěrky.
  monthlyCheck: (periodId: number, dateFrom?: string, dateTo?: string) =>
    api.get<MonthlyCheckResult>(`/accounting/periods/${periodId}/monthly-check`, {
      params: { date_from: dateFrom, date_to: dateTo },
    }).then(r => r.data),
  // Příloha k účetní závěrce (§ 18/1/c ZoÚ, § 39/39a/39b vyhl. 500/2002) — sekce se
  // ukládají per fiskální rok, takže doplnit jde i u uzavřeného období (příloha se
  // typicky dopisuje v průběhu uzávěrky i po ní).
  statementNotes: (periodId: number) =>
    api.get<StatementNotes>(`/accounting/periods/${periodId}/statement-notes`).then(r => r.data),
  saveStatementNote: (periodId: number, section: string, content: string | null) =>
    api.put<StatementNotes>(`/accounting/periods/${periodId}/statement-notes/${section}`, { content }).then(r => r.data),
  // Převzetí loňských textů. Vědomý krok účetní, ne předvyplnění při načtení stránky —
  // loňská věta může být letos nepravdivá a příloha je součástí účetní závěrky.
  carryOverStatementNotes: (periodId: number) =>
    api.post<{ carried: string[]; notes: StatementNotes }>(
      `/accounting/periods/${periodId}/statement-notes/carry-over`,
    ).then(r => r.data),
}

// ── Příloha k účetní závěrce (§ 18/1/c) ─────────────────────────────────────
export interface StatementNotesSection {
  key: string
  label: string
  legal: string
  /** Upozornění k sekci: co aplikace nedopočítává a co proto musí doplnit účetní. */
  hint: string | null
  scope: 'all' | 'audited' | 'large'
  auto: boolean
  content: string | null
  filled: boolean
  /** Rok, ze kterého je text převzatý a účetní ho ještě nepotvrdila. */
  carried_over_from_year: number | null
}

export interface StatementNotes {
  fiscal_year: number
  category: string
  scopes: string[]
  sections: StatementNotesSection[]
  missing: string[]
  complete: boolean
  carry_over: { source_year: number; available: number }
}

// ── Měkký zámek účtování k datu (B8) ────────────────────────────────────────
export interface PeriodLock {
  locked_until: string | null
}

export const periodLockApi = {
  get: () => api.get<PeriodLock>('/accounting/period-lock').then(r => r.data),
  update: (lockedUntil: string | null, reason: string) =>
    api.put<PeriodLock>('/accounting/period-lock', { locked_until: lockedUntil, reason }).then(r => r.data),
}

export const seriesApi = {
  list: () => api.get<DocumentSeries[]>('/accounting/document-series').then(r => r.data),
  update: (code: SeriesCode, year: number, patch: DocumentSeriesPatch) =>
    api.put<DocumentSeries[]>(`/accounting/document-series/${code}/${year}`, patch).then(r => r.data),
}

export const transferApi = {
  create: (payload: TransferPayload) =>
    api.post<TransferResult>('/accounting/journal/transfer', payload).then(r => r.data),
}

export const taxBaseApi = {
  get: (fiscalYear: number) =>
    api.get<TaxBaseAdjustments>('/accounting/reports/tax-base-adjustments', { params: { fiscal_year: fiscalYear } }).then(r => r.data),
}

export const closingSettingsApi = {
  get: () => api.get<AccountingClosingSettings>('/accounting/reporting-settings').then(r => r.data),
  update: (payload: AccountingClosingSettings) =>
    api.put<AccountingClosingSettings>('/accounting/reporting-settings', payload).then(r => r.data),
}

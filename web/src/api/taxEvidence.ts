import { api } from './client'

/**
 * Daňová evidence OSVČ (Epic DE) — typovaný klient pro /api/tax-evidence.
 * READ-ONLY agregátory (peněžní deník, pohledávky/závazky) nad existujícími doklady.
 * Endpointy jsou tenant-scoped přes X-Supplier-Id (přidává api/client.ts) a dostupné
 * jen firmám v režimu supplier.accounting_mode === 'tax_evidence'.
 * Chyby chodí jako { error: { code, message } } — čti e.response.data.error.
 */

// ── Peněžní deník (cash journal) ───────────────────────────────────────────
export type CashBucket =
  | 'income_taxable' | 'income_exempt' | 'income_nontax'
  | 'expense_taxable' | 'expense_nontax'
  | 'transfer' | 'private' | 'nezarazeno'

export interface CashJournalRow {
  source_type: string
  source_id: number
  invoice_id: number | null
  purchase_invoice_id: number | null
  date: string
  doc_no: string
  partner: string
  description: string
  direction: string
  income: number | null
  expense: number | null
  running_balance: number
  bucket: string
  base: number
  vat: number
  unclassified: boolean
  blocking: boolean
  /** Cizoměnový pohyb bez kurzu ke dni úhrady — není oceněn (income/expense = 0). */
  fx_rate_missing: boolean
  /** Peněžní prostředek pohybu: pokladna, banka, GoPay, nebo úhrada bez fyzického pohybu. */
  instrument: CashJournalInstrument
  /** Bankovní výplata nebo pohyb vyúčtování GoPay — zařazení určuje vyúčtování. */
  gopay_clearing_id: number | null
}

export type CashJournalInstrument = 'cash' | 'bank' | 'gopay' | 'virtual'

export interface CashJournalBalance {
  instrument: CashJournalInstrument
  opening: number
  movement: number
  closing: number
}

export interface CashJournalTotals {
  prijem_danovy: number
  prijem_osvobozeny: number
  prijem_nedanovy: number
  vydaj_danovy: number
  vydaj_nedanovy: number
  prevody: number
  private: number
  nezarazeno: number
  net: number
}

export interface CashJournalWarning {
  source_type: string
  source_id: number
  date: string
  direction: string
  amount: number
  blocking: boolean
  message: string
  /** Přítomné jen u agregovaných varování (např. orphan_bank_payments). */
  type?: string
  count?: number
}

export interface CashJournalChecks {
  denik_prijem_danovy: number
  annual_income: number
  variance: number
  explanations: {
    partial_payments: number
    cash_sales_without_invoice: number
    virtual_leg: number
  }
  explained_total: number
  residual: number
  is_equal_assert: boolean
}

export interface CashJournalReport {
  from: string
  to: string
  year: number
  is_vat_payer: boolean
  opening_balance: number
  closing_balance: number
  balances: CashJournalBalance[]
  rows: CashJournalRow[]
  totals: CashJournalTotals
  checks: CashJournalChecks
  warnings: CashJournalWarning[]
}

export interface CashJournalParams {
  year?: number
  from?: string
  to?: string
}

export interface TaxEvidenceAdjustment {
  id?: number; adjustment_on: string; kind: string; direction: 'increase' | 'decrease' | 'neutral'
  amount: number; description: string; evidence_ref?: string | null
}
export interface TaxEvidenceClosing {
  id: number; year: number; status: 'draft' | 'final'; row_version: number
  checklist: Record<string, boolean>; opening_balances: Record<string, number>; closing_balances: Record<string, number>
  unsupported_cases: string[]; adjustments: TaxEvidenceAdjustment[]; source_hash?: string | null; finalized_at?: string | null
}

// ── Ruční klasifikační override pohybu (Epic DE, G2 — migrace 1027) ───────
/** Kbelíky, do kterých lze pohyb ručně zařadit (ENUM tax_bucket bez 'nezarazeno' — to není zapisovatelný stav). */
export type TaxBucketOverride =
  | 'income_taxable' | 'income_exempt' | 'income_nontax'
  | 'expense_taxable' | 'expense_nontax'
  | 'transfer' | 'private'

export interface MovementClassification {
  id: number
  supplier_id: number
  source_type: string
  bank_transaction_id: number | null
  cash_document_id: number | null
  tax_bucket: string
  note: string | null
  classified_by: number | null
  classified_at: string
  updated_at: string
}

// ── Pohledávky a závazky (aging) ───────────────────────────────────────────
export type AgingBucketKey = 'not_due' | '1-30' | '31-90' | '90+'

export interface AgingBucket {
  currency: string
  bucket: string
  count: number
  total: number
}

export interface KpiAvgDays {
  avg_days: number
  sample_size: number
  period_months: number
}

export interface KpiPunctuality {
  on_time: number
  late: number
  total: number
  on_time_pct: number
  period_months: number
}

export interface ReceivablesPayablesReport {
  receivables: AgingBucket[]
  payables: AgingBucket[]
  currencies: string[]
  kpis: {
    dso: KpiAvgDays
    dpo: KpiAvgDays
    punctuality: KpiPunctuality
  }
}

export const taxEvidenceApi = {
  cashJournal: (params: CashJournalParams) =>
    api.get<CashJournalReport>('/tax-evidence/cash-journal', { params }).then(r => r.data),
  closing: (year: number) => api.get<TaxEvidenceClosing>(`/tax-evidence/closing/${year}`).then(r => r.data),
  saveClosing: (year: number, data: Partial<TaxEvidenceClosing>, rowVersion: number) =>
    api.put<TaxEvidenceClosing>(`/tax-evidence/closing/${year}`, { ...data, row_version: rowVersion }).then(r => r.data),
  finalizeClosing: (year: number, rowVersion: number) =>
    api.post<TaxEvidenceClosing>(`/tax-evidence/closing/${year}/finalize`, { row_version: rowVersion }).then(r => r.data),
  reopenClosing: (year: number, rowVersion: number) =>
    api.post<TaxEvidenceClosing>(`/tax-evidence/closing/${year}/reopen`, { row_version: rowVersion }).then(r => r.data),
  receivablesPayables: () =>
    api.get<ReceivablesPayablesReport>('/tax-evidence/receivables-payables').then(r => r.data),
  // Vrací celou odpověď (blob) — komponenta si sestaví název souboru dle konvence.
  exportReport: (path: string, params: Record<string, unknown>) =>
    api.get<Blob>(path, { params, responseType: 'blob' }),
  classifyMovement: (payload: { source_type: string; source_id: number; tax_bucket: TaxBucketOverride; note?: string | null }) =>
    api.post<MovementClassification>('/tax-evidence/classification', payload).then(r => r.data),
  deleteClassification: (sourceType: string, sourceId: number) =>
    api.delete<{ deleted: boolean }>(`/tax-evidence/classification/${sourceType}/${sourceId}`).then(r => r.data),
  // Přechodový můstek § 7b ↔ § 24 ZDP (přílohy č. 2 a 3) — read-only podklad
  // pro úpravu základu daně při změně účetního režimu.
  transitionReport: (asOf: string, direction: TransitionDirection) =>
    api.get<TransitionReport>('/tax-evidence/transition-report', {
      params: { as_of: asOf, direction },
    }).then(r => r.data),
  taxEstimate: (year: number) =>
    api.get<TaxEstimate>('/tax-evidence/tax-estimate', { params: { year } }).then(r => r.data),
}

// ── Odhad daně a pojistného OSVČ během roku ─────────────────────────────────
export type ExpenseMode = 'actual' | 'pausal'

export interface TaxEstimateInsurance {
  assessment_base: number
  min_base: number
  insurance: number
  monthly_advance: number
  participates?: boolean
}

export interface TaxEstimateVariant {
  mode: ExpenseMode
  expense_rate: number
  income: number
  expenses: number
  s7_base: number
  tax_base: number
  tax_before_credits: number
  credits: number
  child_benefit: number
  tax: number
  tax_advances: number
  social: TaxEstimateInsurance
  health: TaxEstimateInsurance
  total_burden: number
  warnings: string[]
}

export interface TaxEstimateAdvance {
  paid: number
  source: 'return' | 'schedules' | 'none'
  remaining: number
}

export interface TaxEstimate {
  applicable: boolean
  reason: 'taxpayer_po' | 'missing_tax_constants' | null
  year: number
  as_of?: string
  year_closed?: boolean
  return_status?: 'none' | 'draft' | 'final'
  selected_mode?: ExpenseMode
  recommended_mode?: ExpenseMode | null
  has_activities?: boolean
  flat_tax_band?: string
  is_secondary?: boolean
  sources?: {
    income: number
    cash_journal_expenses: number | null
    confirmed_depreciation: number | null
    disposal_residuals: number | null
    pending_depreciation: number
    planned_depreciation: number | null
    increase: number
    decrease: number
  }
  pausal?: { rate: number; cap: number; cap_reached: boolean }
  variants?: Partial<Record<ExpenseMode, TaxEstimateVariant>>
  advances?: Record<'tax' | 'social' | 'health', TaxEstimateAdvance>
  settlement?: Record<'tax' | 'social' | 'health', number>
  warnings?: string[]
}

// ── Přechodový můstek § 7b ↔ § 24 ZDP ───────────────────────────────────────
export type TransitionDirection = 'tax_to_accounting' | 'accounting_to_tax'

export interface TransitionDocRow {
  id: number
  doc_no: string
  partner: string
  currency: string
  amount: number
  amount_czk: number
  issue_date: string
  due_date: string
}

export interface TransitionReport {
  as_of: string
  direction?: TransitionDirection
  receivables: TransitionDocRow[]
  payables: TransitionDocRow[]
  totals: {
    receivables_czk: number
    payables_czk: number
    advances_paid_czk?: number
    advances_received_czk?: number
    inventory_czk?: number
    net_adjustment_czk: number
  }
  inventory: { enabled: boolean; value_czk: number | null; note: string }
  valuables?: { value_czk: number | null; note: string }
  receivables_spread?: { max_years: number; annual_czk: number; note: string }
}

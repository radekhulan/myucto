import { api } from './client'

/**
 * "Náklady" (Costs) — statistiky nad přijatými fakturami (purchase invoices).
 * Zrcadlí strukturu dashboard.ts (Tržby), ale pro dodavatele a závazky.
 */

export interface PurchaseKpi {
  /** Náklady napříč měnami v CZK — protějšek `TotalCzk` v dashboard.ts, viz komentář tam. */
  total_czk?: import('./dashboard').TotalCzk
  per_currency: Array<{
    currency: string
    this_year: number
    prev_year: number
    prev_year_ytd: number
    change_pct: number | null
    this_year_invoice_count: number
    prev_year_invoice_count: number
    this_year_vendor_count: number
    prev_year_vendor_count: number
  }>
  purchase_count_ytd: number
  unpaid_count: number
  unpaid_per_currency: Array<{ currency: string; count: number; total: number }>
  overdue_count: number
  avg_payment_days: number | null
  status_counts_ytd: Record<string, number>
}

export interface PurchaseListItem {
  id: number
  varsymbol: string | null
  vendor_invoice_number: string | null
  document_kind: string
  vendor_id: number
  vendor_company_name: string
  currency: string
  issue_date: string
  due_date: string
  amount_to_pay: number
  status: string
  days_overdue: number | null
}

export interface TopVendor {
  vendor_id: number
  company_name: string
  /** CSV nativních měn — 'CZK' nebo 'CZK,EUR'. */
  currencies: string
  /** Náklady přepočtené na CZK (přes pi.exchange_rate). */
  total_czk: number
  invoice_count: number
}

export interface CostsByMonth {
  currency: string
  months: Array<{ ym: string; total: number }>
  prev_year: Array<{ ym: string; total: number }>
}

export interface CostsByYear {
  year: number
  currency: string
  total: number
  invoice_count: number
}

export interface Rolling12mCosts {
  currency: string
  total: number
  prev_period_total: number
}

export interface CashflowByCurrency {
  currency: string
  months: Array<{ ym: string; total: number }>
  prev_year: Array<{ ym: string; total: number }>
}

export interface PaymentDaysHistogram {
  buckets: Array<{ key: string; label: string; count: number }>
  total: number
  avg_days: number | null
}

export interface VatBreakdownItem {
  label: string
  base: number
  currency: string
}

export interface CashflowOutForecast {
  currency: string
  out_30: number
  out_60: number
  out_90: number
  count_30: number
  count_60: number
  count_90: number
}

export interface DueBucket {
  currency: string
  today_count: number
  today_total: number
  week_count: number
  week_total: number
  month_count: number
  month_total: number
}

export interface AgingReportRow {
  currency: string
  current: number
  b1_30: number
  b31_60: number
  b61_90: number
  b90_plus: number
  current_n: number
  b1_30_n: number
  b31_60_n: number
  b61_90_n: number
  b90_plus_n: number
}

export interface CostsForecast {
  currency: string
  ytd: number
  prev_year_ytd: number
  prev_year_remainder: number
  growth_ratio: number
  forecast: number
  prev_year_full: number
}

export interface ExpenseCategoryRow {
  category_id: number | null
  code: string | null
  label: string | null
  total_czk: number
  count: number
  percent: number
}

export interface InvoiceSizeHistogram {
  buckets: Array<{ key: string; label: string; count: number; total_czk: number }>
  total: number
}

export interface Costs30d {
  currency: string
  total: number
  invoice_count: number
}

export interface PurchaseSummary {
  kpi: PurchaseKpi
  overdue: PurchaseListItem[]
  unpaid_upcoming: PurchaseListItem[]
  top_vendors_ytd: TopVendor[]
  top_vendors_prev_year: TopVendor[]
  top_vendors_12m: TopVendor[]
  costs_by_month: CostsByMonth[]
  costs_by_year: CostsByYear[]
  rolling_12m: Rolling12mCosts[]
  cashflow_out_ytd: CashflowByCurrency[]
  payment_days_histogram: PaymentDaysHistogram
  vat_breakdown_12m: VatBreakdownItem[]
  cashflow_forecast: CashflowOutForecast[]
  due_buckets: DueBucket[]
  aging_report: AgingReportRow[]
  costs_forecast: CostsForecast[]
  expense_breakdown_12m: ExpenseCategoryRow[]
  invoice_size_histogram: InvoiceSizeHistogram
  costs_last_30d: Costs30d[]
  active_vendors_count: number
  today: string
  year: number
  prev_year: number
  is_vat_payer: boolean
}

export const purchaseStatsApi = {
  summary: () => api.get<PurchaseSummary>('/dashboard/purchase-summary').then(r => r.data),
}

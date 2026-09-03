import { api } from './client'

export interface CrmKpi {
  period?: string | null
  currency: string
  revenue: number
  revenue_net: number
  costs: number
  costs_net: number
  profit: number
  /** CZK-přepočtené hodnoty (×exchange_rate) — pro agregaci „Vše" napříč měnami. */
  revenue_czk: number
  revenue_net_czk: number
  costs_czk: number
  costs_net_czk: number
  profit_czk: number
  invoice_count: number
  purchase_count: number
  vat_output: number
  vat_input: number
}

export interface CrmOverview {
  current_month: CrmKpi[]
  last_month: CrmKpi[]
  ytd: CrmKpi[]
  /** Klouzavých 12 měsíců (vč. aktuálního) per měna. */
  last_12m: CrmKpi[]
  /** Předchozích 12 měsíců (pro trailing YoY). */
  prev_12m: CrmKpi[]
  /** Celý předchozí kalendářní rok. */
  prev_year_full: CrmKpi[]
  /** Loni do stejného měsíce (fair YTD YoY). */
  prev_year_ytd: CrmKpi[]
  /** Dopředné tržby aktuálního měsíce (koncepty + nespárované proformy), per měna. */
  current_month_pipeline: CrmPipelineRow[]
  currencies: string[]
}

/** Očekávané (zatím nevystavené) tržby aktuálního měsíce — koncepty + nespárované proformy. */
export interface CrmPipelineRow {
  currency: string
  /** Koncepty (vydané faktury draft) — net pro plátce, gross pro neplátce. */
  draft_revenue: number
  draft_revenue_czk: number
  draft_count: number
  /** Nespárované proformy — net pro plátce, gross pro neplátce. */
  proforma_revenue: number
  proforma_revenue_czk: number
  proforma_count: number
}

export interface CrmMonthlyRow extends CrmKpi {
  period: string
}

export interface CrmYearlyRow {
  year: number
  currency: string
  revenue: number
  costs: number
  profit: number
  invoice_count: number
  purchase_count: number
}

export interface TopClient {
  client_id: number
  company_name: string
  /** Obrat přepočtený na CZK přes i.exchange_rate (jediné porovnatelné pole napříč měnami). */
  revenue: number
  invoice_count: number
  /** Vždy 'CZK' — backend přepočítává všechny obraty. */
  currency: string
  /** CSV nativních měn klienta — 'CZK' nebo 'CZK,EUR'. */
  currencies?: string
  percent_share: number
}

export interface TopVendor {
  vendor_id: number
  company_name: string
  /** Náklady přepočtené na CZK přes pi.exchange_rate. */
  costs: number
  purchase_count: number
  /** Vždy 'CZK'. */
  currency: string
  /** CSV nativních měn vendora. */
  currencies?: string
  percent_share: number
}

export interface AgingBucket {
  bucket: 'not_due' | 'overdue_30' | 'overdue_60' | 'overdue_90' | 'overdue_90_plus'
  currency: string
  count: number
  total: number
}

export interface DsoResult {
  avg_days: number
  sample_size: number
  period_months: number
}

export interface PunctualityResult {
  on_time: number
  late: number
  total: number
  on_time_pct: number
  period_months: number
}

export interface ConcentrationResult {
  top1_share: number
  top3_share: number
  top5_share: number
  total_clients: number
  pareto_80_count: number
  risk_level: 'low' | 'medium' | 'high'
  currency: string
}

export interface VendorConcentrationResult {
  top1_share: number
  top3_share: number
  top5_share: number
  total_vendors: number
  pareto_80_count: number
  risk_level: 'low' | 'medium' | 'high'
  currency: string
}

export interface ExpenseCategoryRow {
  category_id: number | null
  code: string | null
  label: string | null
  total: number
  count: number
  percent: number
}

/** Rozpad tržeb po kategoriích (CZK-normalizováno). Symetrie k ExpenseCategoryRow. */
export type RevenueCategoryRow = ExpenseCategoryRow

export interface ChurnRiskClient {
  client_id: number
  company_name: string
  last_invoice_date: string
  days_since: number
  total_revenue: number
  currency: string
}

export const crmApi = {
  overview: () =>
    api.get<CrmOverview>('/crm/overview').then(r => r.data),
  monthly: (months = 12, currency?: string) =>
    api.get<CrmMonthlyRow[]>('/crm/monthly', { params: { months, currency } }).then(r => r.data),
  yearly: (currency?: string) =>
    api.get<CrmYearlyRow[]>('/crm/yearly', { params: { currency } }).then(r => r.data),
  topClients: (months = 12, limit = 10, currency?: string) =>
    api.get<TopClient[]>('/crm/top-clients', { params: { months, limit, currency } }).then(r => r.data),
  topVendors: (months = 12, limit = 10, currency?: string) =>
    api.get<TopVendor[]>('/crm/top-vendors', { params: { months, limit, currency } }).then(r => r.data),
  agingReceivables: () =>
    api.get<AgingBucket[]>('/crm/aging-receivables').then(r => r.data),
  agingPayables: () =>
    api.get<AgingBucket[]>('/crm/aging-payables').then(r => r.data),
  dso: (months = 12) =>
    api.get<DsoResult>('/crm/dso', { params: { months } }).then(r => r.data),
  punctuality: (months = 12) =>
    api.get<PunctualityResult>('/crm/payment-punctuality', { params: { months } }).then(r => r.data),
  concentration: (months = 12, currency?: string) =>
    api.get<ConcentrationResult>('/crm/concentration', { params: { months, currency } }).then(r => r.data),
  vendorConcentration: (months = 12, currency?: string) =>
    api.get<VendorConcentrationResult>('/crm/vendor-concentration', { params: { months, currency } }).then(r => r.data),
  dpo: (months = 12) =>
    api.get<DsoResult>('/crm/dpo', { params: { months } }).then(r => r.data),
  expenseBreakdown: (months = 12, currency?: string) =>
    api.get<ExpenseCategoryRow[]>('/crm/expense-breakdown', { params: { months, currency } }).then(r => r.data),
  revenueBreakdown: (months = 12, currency?: string) =>
    api.get<RevenueCategoryRow[]>('/crm/revenue-breakdown', { params: { months, currency } }).then(r => r.data),
  churnRisk: (days = 60, limit = 20) =>
    api.get<ChurnRiskClient[]>('/crm/churn-risk', { params: { days, limit } }).then(r => r.data),
  recompute: () =>
    api.post<{ ok: boolean; elapsed_ms: number }>('/crm/recompute', {}).then(r => r.data),

  // ─── Tier 1 (action + predictive) ───────────────────────────────────
  actionItems: () =>
    api.get<ActionItemsResult>('/crm/action-items').then(r => r.data),
  /**
   * Doplatek DPPO — vlastní endpoint, protože je to živá projekce celoročního
   * účetnictví (naměřeno 444 z 473 ms celého feedu). Dashboard se vykreslí bez
   * čekání na něj a dlaždice se doplní, až dopočítá. `item: null` je běžný stav
   * (daňová evidence, FO, odkliknuté, nulový doplatek), ne chyba.
   */
  actionItemTaxBalance: () =>
    api.get<{ item: ActionItem | null }>('/crm/action-items/tax-balance').then(r => r.data),
  dismissActionItem: (itemType: string, mode: 'day' | 'week' | 'forever' | 'historical') =>
    api.post<{ ok: boolean }>('/crm/action-items/dismiss', { item_type: itemType, mode }).then(r => r.data),
  restoreActionItem: (itemType: string) =>
    api.post<{ ok: boolean }>('/crm/action-items/restore', { item_type: itemType }).then(r => r.data),
  restoreAllActionItems: () =>
    api.post<{ ok: boolean; restored: number }>('/crm/action-items/restore-all', {}).then(r => r.data),
  cashFlowForecast: (weeks = 4, currency = 'CZK') =>
    api.get<CashFlowResult>('/crm/cash-flow-forecast', { params: { weeks, currency } }).then(r => r.data),
  lateRisk: (limit = 10) =>
    api.get<LateRiskClient[]>('/crm/late-risk', { params: { limit } }).then(r => r.data),
  reminderEffectiveness: (months = 12) =>
    api.get<ReminderEffectiveness>('/crm/reminder-effectiveness', { params: { months } }).then(r => r.data),
  paymentTimeHistogram: (months = 12) =>
    api.get<PaymentTimeHistogram>('/crm/payment-time-histogram', { params: { months } }).then(r => r.data),
  taxCalendar: (maxDaysAhead = 90) =>
    api.get<TaxCalendarResult>('/crm/tax-calendar', { params: { max_days_ahead: maxDaysAhead } }).then(r => r.data),
}

// ─── Tier 1 types ─────────────────────────────────────────────────────
export interface ActionItemBreakdown {
  key: string
  /**
   * Rozpad počtu (FV / PF / banka). Chybí u položek, kde druhý řádek nejsou
   * počty, ale NABÍDKA CEST — chybějící účetní období vede do dvou průvodců
   * a na seznam období, a číslo u nich nic neznamená.
   */
  count?: number
  link: string
}
export interface ActionItem {
  type: string
  severity: 'low' | 'medium' | 'high'
  title: string
  hint: string
  link: string
  count?: number
  days?: number
  breakdown?: ActionItemBreakdown[]
}
export interface ActionItemsResult {
  items: ActionItem[]
  total: number
  dismissed_count: number
}

export interface CashFlowWeek {
  week_start: string
  week_end: string
  in: number
  out: number
  net: number
  running: number
}
export interface CashFlowResult {
  currency: string
  weeks: CashFlowWeek[]
  total_in: number
  total_out: number
  total_net: number
}

export interface LateRiskClient {
  client_id: number
  company_name: string
  total_paid: number
  late_count: number
  late_rate: number
  avg_days_late: number
  score: number
  risk_level: 'low' | 'medium' | 'high'
}

export interface ReminderEffectiveness {
  total_paid: number
  no_reminder: number
  after_first: number
  after_second: number
  after_third_plus: number
  never_paid: number
  avg_reminders_to_paid: number
}

export interface TaxCalendarItem {
  type: 'tax_deadline' | 'kh_deadline' | 'shv_deadline' | 'tax_advance' | 'income_tax_deadline' | 'vat_registration_deadline'
  title: string
  deadline: string
  days: number
  link: string
  /** null u tax_advance (zálohy se "podávají" platbou — viz status). */
  submitted: boolean | null
  submitted_at?: string | null
  /** jen tax_advance */
  amount?: number
  status?: 'planned' | 'paid'
}
export interface TaxCalendarResult {
  items: TaxCalendarItem[]
  total: number
}

export interface HistogramBucket {
  label: string
  min: number
  max: number | null
  count: number
  percent: number
}
export interface PaymentTimeHistogram {
  buckets: HistogramBucket[]
  total_invoices: number
  median_days: number | null
  p90_days: number | null
}

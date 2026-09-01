import { api } from './client'
import type { PaymentMethod } from './invoices'
import type { CatalogDescriptionSource, CatalogPolicy } from './priceList'

export type Frequency = 'monthly' | 'quarterly' | 'semi_annually' | 'annually'
export type RecurringStatus = 'active' | 'paused' | 'expired'
export type RecurringSort = 'client' | 'next_run' | 'amount_czk'
export type TaxDateMode = 'same_as_issue' | 'previous_month_last_day'
export type DraftOpenMode = 'at_issue' | 'period_start'

export type OssRateType = 'standard' | 'reduced' | 'second_reduced' | 'parking'
export type OssSupplyType = 'goods' | 'services'

/**
 * OSS na položce ŠABLONY (migrace 1297) — uložené rozhodnutí člověka, ne snímek výpočtu.
 * Proti položce faktury tu chybí kurz, přepočtené částky, původní období i příznak
 * „k ručnímu posouzení": to všechno jsou vlastnosti KONKRÉTNÍHO dokladu k jeho datu
 * plnění, které dopočítá až generátor.
 *
 * Rozhodnutí má při generování přednost před odvozením — s jedinou výjimkou: nemá-li
 * dodavatel k datu plnění generovaného dokladu platnou registraci do OSS, řádek jde
 * cestou odvození (tedy do tuzemska) a dostane příznak k ručnímu posouzení.
 */
export interface RecurringTemplateItemOss {
  oss_applicable?: boolean
  oss_consumer_country?: string | null
  oss_rate_type?: OssRateType | string | null
  oss_supply_type?: OssSupplyType | null
}

export interface RecurringTemplateItem extends RecurringTemplateItemOss {
  id?: number
  template_id?: number
  description: string
  quantity: number
  unit: string
  unit_price_without_vat: number
  vat_rate_id: number
  vat_rate_percent?: number
  order_index: number
  price_list_item_id?: number | null
  price_list_item_name?: string | null
  price_list_item_code?: string | null
  price_list_item_archived?: boolean
  catalog_policy?: CatalogPolicy
  description_source?: CatalogDescriptionSource
  catalog_price_source?: string | null
  catalog_source_currency_code?: string | null
  catalog_source_unit_price?: number | null
  catalog_exchange_rate?: number | null
  catalog_exchange_rate_date?: string | null
}

export interface RecurringTemplate {
  id: number
  supplier_id: number
  client_id: number
  client_company_name?: string
  project_id: number | null
  project_name?: string | null
  branding_profile_id: number | null
  name: string

  frequency: Frequency
  day_of_month: number | null
  end_of_month: boolean
  anchor_date: string
  end_date: string | null
  next_run_date: string
  last_run_date: string | null

  invoice_type: 'invoice' | 'proforma'
  currency_id: number
  currency?: string
  language: 'cs' | 'en'
  payment_method: PaymentMethod
  reverse_charge: boolean
  /** Ceny položek zadané včetně DPH (brutto) — propíše se do generovaných faktur. */
  prices_include_vat: boolean
  discount_percent: number
  /** Pevná kategorie tržby (#119) — přebíjí fallback zakázka → zákazník; null = fallback. */
  revenue_category_id: number | null
  revenue_category_label?: string | null
  revenue_category_code?: string | null
  payment_due_days: number
  /** NULL = dědit jednotku z klienta → dodavatele (PaymentDueResolver). */
  payment_due_unit: 'days' | 'month' | null
  tax_date_mode: TaxDateMode
  draft_open_mode: DraftOpenMode
  reminder_days_before: number
  note_above_items: string | null
  note_below_items: string | null
  increment_month_in_descriptions: boolean

  auto_issue: boolean
  auto_send_email: boolean
  status: RecurringStatus

  invoices_generated_count?: number
  /** Poslední chyba (automatického) generování — banner na detailu/seznamu. */
  last_error?: string | null
  last_error_at?: string | null
  /** Součet faktury šablony (base + DPH, respektuje reverse_charge) — vrací list(). */
  total_with_vat?: number
  catalog_total_is_estimate?: boolean
  catalog_estimate_rate_date?: string | null
  catalog_state?: 'ok' | 'changed' | 'archived' | 'error'
  catalog_error?: string | null
  created_at: string
  updated_at: string

  items?: RecurringTemplateItem[]
}

export interface RecurringTemplatePayload {
  client_id: number
  project_id?: number | null
  branding_profile_id?: number | null
  name: string
  frequency: Frequency
  day_of_month?: number | null
  end_of_month: boolean
  anchor_date: string
  end_date?: string | null
  invoice_type?: 'invoice' | 'proforma'
  currency_id: number
  language?: 'cs' | 'en'
  payment_method?: PaymentMethod
  reverse_charge?: boolean
  prices_include_vat?: boolean
  discount_percent?: number
  revenue_category_id?: number | null
  payment_due_days?: number
  payment_due_unit?: 'days' | 'month' | null
  tax_date_mode?: TaxDateMode
  draft_open_mode?: DraftOpenMode
  reminder_days_before?: number
  note_above_items?: string | null
  note_below_items?: string | null
  increment_month_in_descriptions?: boolean
  auto_issue?: boolean
  auto_send_email?: boolean
  items: Array<{
    description: string
    quantity: number
    unit: string
    unit_price_without_vat: number
    vat_rate_id: number
    order_index: number
    price_list_item_id?: number | null
    catalog_policy?: CatalogPolicy
    description_source?: CatalogDescriptionSource
    catalog_price_source?: string | null
    catalog_source_currency_code?: string | null
    catalog_source_unit_price?: number | null
    catalog_exchange_rate?: number | null
    catalog_exchange_rate_date?: string | null
    accept_catalog_changes?: boolean
  } & RecurringTemplateItemOss>
}

export interface RunNowResult {
  invoice_id: number
  varsymbol: string | null
  issued: boolean
  sent_to: string[]
  new_next_run_date: string | null
  template_status: RecurringStatus
}

export interface GeneratedInvoiceRow {
  id: number
  varsymbol: string | null
  invoice_type: string
  status: string
  issue_date: string
  due_date: string
  paid_at: string | null
  total_with_vat: number
  amount_to_pay: number
  currency: string
}

export interface RecurringCurrencyTotal {
  currency: string
  total: number
}

export interface RecurringSummary {
  by_currency: RecurringCurrencyTotal[]
  /** Je ve filtru více měn? Pak se v hlavičce zobrazí total_czk. */
  multi_currency: boolean
  /** Součet všech částek převedený na CZK (dnešní ČNB kurz). */
  total_czk: number
  /** false = u některé měny nebyl dostupný kurz (CZK součet je neúplný). */
  rates_complete: boolean
}

export interface RecurringListMeta {
  total: number
  page: number
  per_page: number
  pages: number
  status_counts?: { all: number; active: number; paused: number; expired: number }
  totals_by_currency?: RecurringCurrencyTotal[]
  summary?: RecurringSummary
}

export interface RecurringListResponse {
  data: RecurringTemplate[]
  meta: RecurringListMeta
}

export interface RecurringInvoicesResponse {
  data: GeneratedInvoiceRow[]
  meta: { total: number; page: number; per_page: number; pages: number }
}

export const recurringApi = {
  list: (filters: { client_id?: number; status?: RecurringStatus; page?: number; per_page?: number; sort?: RecurringSort } = {}) => {
    const params: Record<string, string | number> = {}
    if (filters.client_id) params.client_id = filters.client_id
    if (filters.status)    params.status = filters.status
    if (filters.page)      params.page = filters.page
    if (filters.per_page)  params.per_page = filters.per_page
    if (filters.sort)      params.sort = filters.sort
    return api.get<RecurringListResponse>('/recurring', { params }).then(r => r.data)
  },
  get:    (id: number) => api.get<RecurringTemplate>(`/recurring/${id}`).then(r => r.data),
  invoices: (id: number, params: { page?: number; per_page?: number } = {}) =>
    api.get<RecurringInvoicesResponse>(`/recurring/${id}/invoices`, { params }).then(r => r.data),
  create: (payload: RecurringTemplatePayload) =>
    api.post<RecurringTemplate>('/recurring', payload).then(r => r.data),
  update: (id: number, payload: RecurringTemplatePayload) =>
    api.put<RecurringTemplate>(`/recurring/${id}`, payload).then(r => r.data),
  delete: (id: number) =>
    api.delete<{ deleted: true }>(`/recurring/${id}`).then(r => r.data),
  pause:  (id: number) => api.post<RecurringTemplate>(`/recurring/${id}/pause`).then(r => r.data),
  resume: (id: number) => api.post<RecurringTemplate>(`/recurring/${id}/resume`).then(r => r.data),
  runNow: (id: number, issueDate?: string, draft = false) =>
    api.post<RunNowResult>(`/recurring/${id}/run-now`, {
      ...(issueDate ? { issue_date: issueDate } : {}),
      ...(draft ? { draft: true } : {}),
    }).then(r => r.data),
}

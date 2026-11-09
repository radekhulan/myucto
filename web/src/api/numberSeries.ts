import { api } from './client'

/** Druh dokladu v číselníku řad (NumberSeriesKinds v PHP). */
export type NumberSeriesKind =
  | 'invoice' | 'proforma' | 'credit_note' | 'simplified' | 'purchase'
  | 'cash_in' | 'cash_out' | 'manual'
  | 'stock_in' | 'stock_out' | 'stock_transfer' | 'purchase_order'
  | 'other_receivable' | 'other_payable' | 'offset'
  | 'closing' | 'opening' | 'fx' | 'transfer' | 'bank'

/** Druhy, pro které jde založit další řadu a vybrat ji při zakládání dokladu. */
export const SELECTABLE_KINDS: NumberSeriesKind[] = ['invoice', 'proforma', 'credit_note', 'purchase', 'cash_in', 'cash_out', 'manual']

export type NumberSeriesPeriod = 'year' | 'month' | 'none'

export interface NumberSeries {
  id: number
  doc_kind: NumberSeriesKind
  group: string
  scope_type: 'company' | 'cash_register' | 'client' | 'revenue_category' | 'other_item_kind' | 'bank_account'
  scope_id: number
  scope_name: string | null
  /** legacy = převedená dosavadní řada, native = řada založená v číselníku */
  storage: 'legacy' | 'native'
  legacy_key: string | null
  name: string
  label: string
  prefix: string | null
  template: string
  period: NumberSeriesPeriod | null
  next_preview: string | null
  next_number: number | null
  is_default: boolean
  is_user_default: boolean
  valid_to: string | null
  sort_order: number
  selectable: boolean
  can_edit_template: boolean
  can_set_next: boolean
  can_end: boolean
  can_delete: boolean
  edit_target: 'invoice_numbering' | 'client' | 'revenue_category' | 'purchase_numbering' | 'document_series' | 'bank_account' | null
  /** Jen v odpovědi na založení / úpravu: řady vydaných dokladů se stejnými číslicemi. */
  warnings?: { code: 'digit_overlap'; series_id: number }[]
}

export interface NumberSeriesInput {
  doc_kind?: NumberSeriesKind
  scope_id?: number
  name?: string
  prefix?: string | null
  template?: string
  period?: NumberSeriesPeriod
  next_number?: number | null
  valid_to?: string | null
  is_default?: boolean
}

export interface NumberSeriesOption {
  id: number
  name: string
  label: string
  storage: 'legacy' | 'native'
  prefix: string | null
  template: string
  valid_to: string | null
}

export interface NumberSeriesOptions {
  kind: NumberSeriesKind
  multiple: boolean
  default_id: number | null
  series: NumberSeriesOption[]
}

export interface NumberSeriesCompletenessBucket {
  period_key: string
  used_count: number
  range_from: number
  range_to: number
  missing: number[]
  missing_total: number
  missing_truncated: boolean
  missing_preview: string[]
}

export interface NumberSeriesCompleteness {
  year: number
  series: { number_series_id: number; types: string[]; period: string; template: string; buckets: NumberSeriesCompletenessBucket[] }[]
  unassigned: { types: string[]; template_by_type: Record<string, string>; buckets: NumberSeriesCompletenessBucket[] }[]
}

/** Kde editor dokladu bere řady: modul dokladu (právo modulu) a druh. */
export type NumberSeriesScope =
  | { module: 'invoices'; type: 'invoice' | 'proforma' | 'credit_note' }
  | { module: 'purchase-invoices' }
  | { module: 'cash-documents'; docType: 'in' | 'out'; registerId: number }
  | { module: 'internal-documents' }

function optionsUrl(scope: NumberSeriesScope): string {
  switch (scope.module) {
    case 'invoices': return '/invoices/number-series'
    case 'purchase-invoices': return '/purchase-invoices/number-series'
    case 'cash-documents': return '/accounting/cash-documents/number-series'
    case 'internal-documents': return '/accounting/internal-documents/number-series'
  }
}

function optionsParams(scope: NumberSeriesScope): Record<string, string | number> {
  if (scope.module === 'invoices') return { type: scope.type }
  if (scope.module === 'cash-documents') return { doc_type: scope.docType, register_id: scope.registerId }
  return {}
}

export const numberSeriesApi = {
  list: () => api.get<NumberSeries[]>('/settings/number-series').then(r => r.data),
  create: (input: NumberSeriesInput) => api.post<NumberSeries>('/settings/number-series', input).then(r => r.data),
  update: (id: number, input: NumberSeriesInput) => api.put<NumberSeries>(`/settings/number-series/${id}`, input).then(r => r.data),
  setDefault: (id: number) => api.post<NumberSeries>(`/settings/number-series/${id}/default`, {}).then(r => r.data),
  remove: (id: number) => api.delete(`/settings/number-series/${id}`).then(r => r.data),
  completeness: (year: number) =>
    api.get<NumberSeriesCompleteness>('/settings/number-series/completeness', { params: { year } }).then(r => r.data),
  options: (scope: NumberSeriesScope) =>
    api.get<NumberSeriesOptions>(optionsUrl(scope), { params: optionsParams(scope) }).then(r => r.data),
  setUserDefault: (scope: NumberSeriesScope, seriesId: number | null) =>
    api.put<NumberSeriesOptions>(`${optionsUrl(scope)}/default`, { ...optionsParams(scope), number_series_id: seriesId }).then(r => r.data),
}

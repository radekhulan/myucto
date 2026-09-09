import { api } from './client'

export type PricingCalculationMode = 'markup' | 'target_margin'
export type PricingRounding = 'none' | '0.01' | '0.10' | '0.50' | '1' | '9_ending'
export type PricingMatchType = 'product' | 'category' | 'manufacturer' | 'vendor' | 'default'

export interface PricingProfile {
  id: number
  code: string
  name: string
  currency_code: string
  calculation_mode: PricingCalculationMode
  percentage: string
  rounding: PricingRounding
  fx_source: string
  max_rate_age_days: number
  is_active: boolean
}

export type PricingProfilePayload = Omit<PricingProfile, 'id'>

export interface PricingRule {
  id: number
  profile_id: number
  match_type: PricingMatchType
  match_id: number | null
  priority: number
  is_active: boolean
}

export type PricingRulePayload = Omit<PricingRule, 'id'>

export interface PricingExchangeRate {
  id: number
  currency_code: string
  rate_date: string
  source: string
  rate: string
}

export type PricingExchangeRatePayload = Omit<PricingExchangeRate, 'id'>

export interface PricingDeleteResult {
  deleted: true
  recompute_job_id: number | null
}

export type PriceMatrixOperation = 'lock_current' | 'set_fixed' | 'unlock_to_rules' | 'delete' | 'upsert'

export interface PriceMatrixSelection {
  all_matching: boolean
  ids: number[]
}

export interface PriceMatrixOverride {
  item_id: number
  currency_code: string
  operation: PriceMatrixOperation
  fixed_price?: string
  rounding?: PricingRounding
}

export interface PriceMatrixCell {
  currency_code: string
  price_mode: 'fixed' | PricingCalculationMode
  markup_pct: string | null
  fixed_price: string | null
  rounding: PricingRounding
  is_manual_override: boolean
  use_pricing_rules: boolean
  price: string | null
  cost_czk: string | null
  margin_pct: string | null
  rate: string | null
  rate_date: string | null
  rate_source: string | null
  profile_id: number | null
  rule_id: number | null
  cost_source: string | null
  deviation_pct?: string | null
}

export interface PriceMatrixState {
  id: number
  sku: string
  name: string
  row_version: number
  cells: Record<string, PriceMatrixCell | null>
  issues?: Record<string, Array<'missing_price' | 'manual_override' | 'deviation'>>
}

export interface PriceMatrixJobItem {
  ordinal: number
  stock_item_id: number
  expected_version: number
  status: 'pending' | 'ready' | 'applied' | 'unchanged' | 'failed' | 'conflict' | 'skipped'
  error_code: string | null
  before: PriceMatrixState | null
  after: PriceMatrixState | null
}

export interface PriceMatrixItems {
  job: import('./catalogJobs').CatalogJob
  items: PriceMatrixJobItem[]
  pagination: { page: number; limit: number; total: number; pages: number }
}

export const catalogPricingApi = {
  profiles: () =>
    api.get<PricingProfile[]>('/eshop/pricing/profiles').then(response => response.data),
  createProfile: (payload: PricingProfilePayload) =>
    api.post<{ profile: PricingProfile; recompute_job_id: number | null }>('/eshop/pricing/profiles', payload)
      .then(response => response.data),
  updateProfile: (id: number, payload: PricingProfilePayload) =>
    api.put<{ profile: PricingProfile; recompute_job_id: number | null }>(`/eshop/pricing/profiles/${id}`, payload)
      .then(response => response.data),
  deleteProfile: (id: number) =>
    api.delete<PricingDeleteResult>(`/eshop/pricing/profiles/${id}`).then(response => response.data),

  rules: () =>
    api.get<PricingRule[]>('/eshop/pricing/rules').then(response => response.data),
  createRule: (payload: PricingRulePayload) =>
    api.post<{ rule: PricingRule; recompute_job_id: number | null }>('/eshop/pricing/rules', payload)
      .then(response => response.data),
  updateRule: (id: number, payload: PricingRulePayload) =>
    api.put<{ rule: PricingRule; recompute_job_id: number | null }>(`/eshop/pricing/rules/${id}`, payload)
      .then(response => response.data),
  deleteRule: (id: number) =>
    api.delete<PricingDeleteResult>(`/eshop/pricing/rules/${id}`).then(response => response.data),

  rates: () =>
    api.get<PricingExchangeRate[]>('/eshop/pricing/exchange-rates', { params: { limit: 500 } })
      .then(response => response.data),
  saveRate: (payload: PricingExchangeRatePayload) =>
    api.put<{ exchange_rate: PricingExchangeRate; recompute_job_id: number | null }>('/eshop/pricing/exchange-rates', payload)
      .then(response => response.data),

  previewMatrix: (selection: PriceMatrixSelection, options: {
    currencies: string[]
    on_date: string
    ensure_missing: boolean
    reprice: boolean
    deviation_threshold_pct: string
    overrides: PriceMatrixOverride[]
  }) => api.post<import('./catalogJobs').CatalogJob>('/eshop/pricing/matrix/preview', { selection, options }).then(r => r.data),
  applyMatrix: (id: number) =>
    api.post<import('./catalogJobs').CatalogJob>(`/eshop/pricing/matrix/${id}/apply`).then(r => r.data),
  matrixItems: (id: number, params: { page?: number; limit?: number; status?: string; currency?: string; issue?: string } = {}) =>
    api.get<PriceMatrixItems>(`/eshop/pricing/matrix/${id}/items`, { params }).then(r => r.data),
  exportMatrix: (id: number, view: 'before' | 'after') =>
    api.get<Blob>(`/eshop/pricing/matrix/${id}/export`, { params: { view }, responseType: 'blob' }).then(r => r.data),
  importMatrix: (file: File) => {
    const data = new FormData()
    data.append('file', file)
    return api.post<import('./catalogJobs').CatalogJob>('/eshop/pricing/matrix/import/preview', data).then(r => r.data)
  },
}

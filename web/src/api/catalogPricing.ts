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
}

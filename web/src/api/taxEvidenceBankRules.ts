import { api } from './client'
import type { TaxBucketOverride } from './taxEvidence'

/** Pravidla bankovních pohybů v daňové evidenci (issue #140). */
export type BankRuleDirection = 'any' | 'incoming' | 'outgoing'

export interface TaxEvidenceBankRule {
  id: number
  name: string
  priority: number
  is_active: boolean
  direction: BankRuleDirection
  counterparty_account: string | null
  variable_symbol: string | null
  text_contains: string | null
  amount_min: number | null
  amount_max: number | null
  action_ignore: boolean
  tax_bucket: TaxBucketOverride | null
  hit_count: number
  last_hit_at: string | null
}

export type TaxEvidenceBankRulePayload = Omit<TaxEvidenceBankRule, 'id' | 'hit_count' | 'last_hit_at'>

export interface TaxEvidenceBankRulesApplyResult {
  applied: number
  ignored: number
  classified: number
}

export const taxEvidenceBankRulesApi = {
  list: () => api.get<{ data: TaxEvidenceBankRule[] }>('/tax-evidence/bank-rules').then(r => r.data.data),
  create: (payload: TaxEvidenceBankRulePayload) =>
    api.post<TaxEvidenceBankRule>('/tax-evidence/bank-rules', payload).then(r => r.data),
  update: (id: number, payload: TaxEvidenceBankRulePayload) =>
    api.put<TaxEvidenceBankRule>(`/tax-evidence/bank-rules/${id}`, payload).then(r => r.data),
  delete: (id: number) => api.delete(`/tax-evidence/bank-rules/${id}`).then(r => r.data),
  apply: () => api.post<TaxEvidenceBankRulesApplyResult>('/tax-evidence/bank-rules/apply').then(r => r.data),
}

import { api } from './client'
import { apiErrorMessage } from './errors'

// Pravidlo výnosů: účet položky vydané faktury podle kódu položky, textu nebo sazby DPH.
export interface RevenueRule {
  id: number
  name: string
  item_code: string | null
  description_contains: string | null
  vat_rate: number | null
  target_account_code: string
  priority: number
  is_active: boolean
  hit_count: number
  last_hit_at: string | null
  created_by_name?: string | null       // jen list endpoint (JOIN users)
  created_at: string
  updated_at: string
}

export type RevenueRulePayload = Omit<RevenueRule,
  'id' | 'hit_count' | 'last_hit_at' | 'created_by_name' | 'created_at' | 'updated_at'>

export const revenueRulesApi = {
  listRules: (params: { active?: boolean; page?: number; per_page?: number } = {}) =>
    api.get<{ items: RevenueRule[]; total: number; page: number; per_page: number }>('/accounting/revenue-rules', { params }).then(r => r.data),
  createRule: (p: RevenueRulePayload) =>
    api.post<{ rule: RevenueRule }>('/accounting/revenue-rules', p).then(r => r.data),
  updateRule: (id: number, p: Partial<RevenueRulePayload>) =>
    api.put<{ rule: RevenueRule }>(`/accounting/revenue-rules/${id}`, p).then(r => r.data),
  deleteRule: (id: number) =>
    api.delete<{ deleted: true }>(`/accounting/revenue-rules/${id}`).then(r => r.data),
}

const ERROR_KEYS: Record<string, string> = {
  rule_criteria_missing:  'accounting.revenue_rules.err_criteria_missing',
  invalid_priority:       'accounting.expense_rules.err_priority',
  invalid_vat_rate:       'accounting.revenue_rules.err_vat_rate',
  not_found:              'accounting.expense_rules.err_not_found',
}

export function revenueRuleErrorMessage(e: unknown, t: (k: string, p?: any) => string): string {
  const code = (e as any)?.response?.data?.error?.code
  // Neplatný cílový účet: backend hlášku formuluje konkrétně (saldokontní, mimo rozvrh…).
  return code && ERROR_KEYS[code] ? t(ERROR_KEYS[code]) : apiErrorMessage(e, t('accounting.revenue_rules.err_generic'))
}

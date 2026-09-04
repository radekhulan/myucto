import { api } from './client'
import { apiErrorMessage } from './errors'

// Druh nákladu → řídí nákladový účet: service→518, material→501,
// small_asset→501 (+karta v evidenci), fixed_asset→042 (odpisy).
export type ExpenseKind = 'service' | 'material' | 'small_asset' | 'fixed_asset'

export interface ExpenseRule {
  id: number
  name: string
  vendor_client_id: number | null
  vendor_client_name?: string | null   // jen list endpoint (JOIN clients)
  vendor_name_contains: string | null
  description_contains: string | null
  amount_min: number | null
  amount_max: number | null
  expense_kind: ExpenseKind
  target_account_code: string | null
  application_mode: 'suggest' | 'auto'
  priority: number
  is_active: boolean
  hit_count: number
  last_hit_at: string | null
  created_by_name?: string | null       // jen list endpoint (JOIN users)
  created_at: string
  updated_at: string
}

export type ExpenseRulePayload = Omit<ExpenseRule,
  'id' | 'vendor_client_name' | 'hit_count' | 'last_hit_at' | 'created_by_name' | 'created_at' | 'updated_at'>

export const expenseRulesApi = {
  listRules: (params: { expense_kind?: ExpenseKind; active?: boolean; page?: number; per_page?: number } = {}) =>
    api.get<{ items: ExpenseRule[]; total: number; page: number; per_page: number }>('/accounting/expense-rules', { params }).then(r => r.data),
  createRule: (p: ExpenseRulePayload) =>
    api.post<{ rule: ExpenseRule }>('/accounting/expense-rules', p).then(r => r.data),
  // PATCH sémantika — posílají se jen změněné klíče (vč. samostatného is_active z inline toggle).
  updateRule: (id: number, p: Partial<ExpenseRulePayload>) =>
    api.put<{ rule: ExpenseRule }>(`/accounting/expense-rules/${id}`, p).then(r => r.data),
  deleteRule: (id: number) =>
    api.delete<{ deleted: true }>(`/accounting/expense-rules/${id}`).then(r => r.data),
}

const ERROR_KEYS: Record<string, string> = {
  invalid_expense_kind:   'accounting.expense_rules.err_invalid_kind',
  rule_criteria_missing:  'accounting.expense_rules.err_criteria_missing',
  invalid_amount_band:    'accounting.expense_rules.err_amount_band',
  invalid_priority:       'accounting.expense_rules.err_priority',
  invalid_target_account: 'accounting.expense_rules.err_target_account',
  vendor_not_found:       'accounting.expense_rules.err_vendor_not_found',
  not_found:              'accounting.expense_rules.err_not_found',
}

export function expenseRuleErrorMessage(e: unknown, t: (k: string, p?: any) => string): string {
  const code = (e as any)?.response?.data?.error?.code
  return code && ERROR_KEYS[code] ? t(ERROR_KEYS[code]) : apiErrorMessage(e, t('accounting.expense_rules.err_generic'))
}

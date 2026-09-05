import { api } from './client'

export interface AutomationRecommendation {
  action?: 'create_expense_rule' | 'edit_expense_rule' | 'create_bank_rule' | 'post_document'
  rule_id?: number | null
  vendor_id?: number | null
  occurrence_count?: number
  rule_payload?: Partial<import('./expenseRules').ExpenseRulePayload & import('./bankPosting').BankPostingRulePayload> | null
  samples?: Array<{ description: string; document_id?: number; transaction_id?: number; statement_id?: number; date?: string }>
  id: string
  type: 'post_invoice' | 'post_purchase' | 'classify_purchase' | 'bank_rule'
  supplier_id: number
  supplier_name: string
  document_id: number | null
  item_id: number | null
  statement_id: number | null
  transaction_id: number | null
  date: string
  document_no: string | null
  description: string
  counterparty: string | null
  amount: number
  currency: string
  booked: boolean
  period_closed: boolean
  current_account_code: string | null
  suggested_account_code: string | null
  expense_kind: string | null
  current_expense_kind?: string | null
  preview_error?: string | null
  confidence: number | null
  reason: string
  source: string
  lines: Array<{ account_code: string; side: 'debit' | 'credit'; amount: number }>
}

export interface AutomationRecommendationsResult {
  items: AutomationRecommendation[]
  total: number
  page: number
  per_page: number
  summary: { sales: number; purchases: number; bank: number }
  snapshots: Array<{ supplier_id: number; generated_at: string | null; refresh_pending: boolean }>
}

export interface AutomationRecommendationsJob {
  id: number
  status: 'queued' | 'running' | 'completed' | 'failed'
  total_items: number | null
  processed: number
  current_step: string | null
  created_count: number
  last_error: string | null
  created_at: string
  finished_at: string | null
}

export const automationRecommendationsApi = {
  refresh: (suppliers: string) => api.post<{ queued: number }>('/automation/recommendations/refresh', null, { params: { suppliers } }).then(r => r.data),
  getJob: (supplier: number) => api.get<{ job: AutomationRecommendationsJob | null }>('/automation/recommendations/job', { params: { suppliers: supplier } }).then(r => r.data.job),
  startJob: (supplier: number) => api.post<{ job: AutomationRecommendationsJob }>('/automation/recommendations/job', null, { params: { suppliers: supplier } }).then(r => r.data.job),
  list: (params: { suppliers: string; from?: string; to?: string; type?: string; page: number; per_page: number }) =>
    api.get<AutomationRecommendationsResult>('/automation/recommendations', { params }).then(r => r.data),
}

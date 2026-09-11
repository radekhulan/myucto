export type AutomationSource = 'matched' | 'rule' | 'detector' | 'transfer' | 'document' | 'learned' | 'schedule' | 'ai'
export type AutomationMode = 'auto' | 'approved'

export interface AutomationProvenance {
  source: AutomationSource
  mode: AutomationMode
  confidence: number | null
  detector: string | null
  rule_id: number | null
  rule_name: string | null
  rule_approved_streak?: number | null
  suggestion_id: number | null
  decided_at: string
  decided_by: string | null
}

export interface AutomationCorrection {
  date: string
  from: string
  to: string
}

export const AUTOMATION_NOTE_CODES = [
  'document_not_posted',
  'period_closed',
  'rule_conflict',
  'duplicate_suspect',
  'cross_currency',
  'advance_settlement_ambiguous',
  'missing_document',
  'anomaly',
  'fee_gap',
  'liability_prescription_missing',
  'liability_prescription_short',
  'schedule_amount_differs',
  'remittance_unclassified',
  'amount_over_cap',
  'daily_limit_reached',
  'low_confidence',
  'saldo_forbidden',
  'policy_suggest',
  'already_paid_verify',
  'overpaid_verify',
] as const

export const AI_SOURCES = ['knn', 'llm'] as const

export type AutomationNoteCode = (typeof AUTOMATION_NOTE_CODES)[number]

export function normalizeAutomationSource(source: string): AutomationSource {
  if (source === 'payment_match') return 'matched'
  if (source === 'transfer') return 'transfer'
  if (source === 'knn' || source === 'llm') return 'ai'
  if (source === 'matched' || source === 'rule' || source === 'detector'
    || source === 'transfer' || source === 'document' || source === 'learned' || source === 'schedule' || source === 'ai') return source
  return 'rule'
}

import { api } from './client'

export type AutomationFeedTab = 'auto' | 'pending' | 'needs_input'

export interface AutomationFeedItem {
  id: string
  kind: 'bank_suggestion' | 'unbooked_invoice' | 'unbooked_purchase' | 'rule_disabled'
  tab: AutomationFeedTab
  supplier_id: number
  supplier_name: string
  date: string
  amount: number
  currency: string
  description: string
  counterparty: string | null
  debit_account_code: string | null
  credit_account_code: string | null
  /**
   * Kontace je konečná (prošla analytickými přepisy zaúčtování). `false` = analytiku
   * vlastního účtu se nepodařilo určit, zobrazená syntetika se při zaúčtování ještě změní.
   */
  accounts_resolved?: boolean
  source: string
  confidence: number | null
  detector: string | null
  operation_type: string | null
  rule_id: number | null
  rule_name: string | null
  rule_hit_count?: number | null
  rule_approved_streak?: number | null
  note: string | null
  snoozed_until: string | null
  snooze_reason: string | null
  journal_entry_id: number | null
  document_no: string | null
  period_closed: boolean
  can_write: boolean
  refs: {
    suggestion_id: number | null
    bank_transaction_id: number | null
    statement_id: number | null
    invoice_id: number | null
    purchase_invoice_id: number | null
  }
  source_details: {
    variable_symbol: string | null
    counterparty_account: string | null
    counterparty_bank: string | null
    signed_amount: number
  } | null
  conflict_rules: Array<{ id: number; name: string; debit_account_code: string; credit_account_code: string; hit_count: number }> | null
  duplicate_entry: { journal_entry_id: number; document_no: string | null; entry_date: string; amount: number; debit_account_code: string; credit_account_code: string } | null
}

export interface BulkPostingPreview {
  count: number
  items: Array<{ suggestion_id: number; bank_transaction_id: number; currency: string; lines: Array<{ account_code: string; side: 'debit' | 'credit'; amount: number }> }>
  accounts: Array<{ currency: string; account_code: string; debit: number; credit: number }>
  failed: Array<{ id: number; code: string }>
}

export interface BulkApproveResult {
  approved: number
  approved_ids: number[]
  failed: Array<{ id: number; code: string }>
  batch_id: string | null
}

export interface AutomationCounts {
  auto_today: number
  pending: number
  needs_input: number
  per_supplier: Array<{ supplier_id: number; supplier_name: string; auto_today: number; pending: number; needs_input: number }>
}

export interface AutomationHistoryItem {
  id: number
  supplier_id: number
  supplier_name: string
  event: 'auto_posted' | 'approved' | 'rejected' | 'superseded'
  source: string
  amount: number
  currency: string
  debit_account_code: string
  credit_account_code: string
  description: string
  counterparty: string | null
  variable_symbol: string | null
  document_no: string | null
  journal_entry_id: number | null
  bank_transaction_id: number
  statement_id: number
  transaction_date: string
  note: string | null
  occurred_at: string
  decided_by: string | null
}

export interface AutomationHistoryPage {
  items: AutomationHistoryItem[]
  total: number
  page: number
  per_page: number
}

export interface AutomationStats {
  period: { from: string; to: string }
  automation_rate: number
  trend: Array<{ month: string; rate: number }>
  auto_count: number
  approved_count: number
  rejected_count: number
  manual_bank_count: number
  top_reasons: Array<{ code: string; count: number }>
  rules_by_reject: Array<{ rule_id: number; name: string; hit_count: number; rejected_streak: number; reject_rate: number }>
  saved_seconds: number
  gl_share_pct: number
  range: { from: string; to: string }
  sources: Array<{ source: string; suggested: number; approved: number; approved_with_override: number; rejected: number; acceptance_rate: number; override_rate: number }>
  auto: { posted: number; reversed: number; accuracy: number }
  rules: { total: number; active: number; auto: number; promotion_candidates: number; promoted: number; demoted: number; mined: number }
  corrections: { total: number; by_event: Record<string, number> }
}

export interface AutomationOverview {
  detections: Array<{ key: string; policy_level: string }>
  rules: Array<Record<string, unknown>>
  learned: Array<Record<string, unknown>>
  counterparties: Array<Record<string, unknown>>
  ai: { enabled: boolean; scope: string | null; muted_sources: string[] }
  settings: { automation_level: string; automation_daily_limit_czk: number | null; automation_digest_enabled: boolean; automation_digest_hour: number }
}

export interface WizardAnalysis {
  analyzed_tx: number
  coverage_pct: number
  clusters: Array<{ key: string; tx_count: number; first_seen: string; last_seen: string; flow: WizardAccountFlow; proposal: WizardRuleProposal; sample: Array<Record<string, unknown>> }>
  locked: { tx_count: number; periods: string[] }
}

export interface WizardAccountEndpoint {
  label: string | null
  account_number: string | null
  bank_code: string | null
  own: boolean
}

export interface WizardAccountFlow {
  from: WizardAccountEndpoint
  to: WizardAccountEndpoint
  own_transfer: boolean
}

export interface WizardRuleProposal extends Record<string, unknown> {
  name: string
  debit_account_code: string | null
  credit_account_code: string | null
}

export interface WizardApplyResult {
  created: number
  backfilled: number
  locked_skipped: number
  skipped: Array<{ name: string; code: string }>
}

export function isBulkEligible(item: AutomationFeedItem): boolean {
  return item.tab === 'pending' && item.kind === 'bank_suggestion'
    && !AI_SOURCES.includes(item.source as (typeof AI_SOURCES)[number])
    && !item.period_closed && item.can_write
}

function supplierHeaders(supplierId: number) {
  return { headers: { 'X-Supplier-Id': String(supplierId) } }
}

export const automationApi = {
  feed: (params: Record<string, string | number | undefined>) => api.get<{ items: AutomationFeedItem[]; total: number; page: number; per_page: number }>('/automation/feed', { params }).then(r => r.data),
  counts: (params: Record<string, string | undefined> = {}) => api.get<AutomationCounts>('/automation/counts', { params }).then(r => r.data),
  stats: (supplierId: number, from?: string, to?: string) => api.get<AutomationStats>('/automation/stats', { params: { supplier_id: supplierId, from, to } }).then(r => r.data),
  overview: (supplierId?: number) => api.get<AutomationOverview>('/automation/overview', { params: supplierId ? { supplier_id: supplierId } : {} }).then(r => r.data),
  checklist: (scope: string, supplierId?: number, from?: string, to?: string) => api.get<{ scope: string; items: Array<{ key: string; ok: boolean; count: number; link: { route: string; query: Record<string, string> } }> }>('/automation/checklist', { params: { scope, supplier_id: supplierId, from, to } }).then(r => r.data),
  history: (params: Record<string, string | number | undefined>) => api.get<AutomationHistoryPage>('/automation/history', { params }).then(r => r.data),
  wizardAnalysis: (supplierId: number) => api.get<WizardAnalysis>('/automation/wizard/analysis', supplierHeaders(supplierId)).then(r => r.data),
  wizardApply: (supplierId: number, rules: Array<Record<string, unknown>>, backfill = true) => api.post<WizardApplyResult>('/automation/wizard/apply', { rules, backfill }, supplierHeaders(supplierId)).then(r => r.data),
  approve: (item: AutomationFeedItem, overrides: Record<string, string> = {}, selectedRuleId?: number) => api.post<{
    journal_entry_id: number
    document_no: string | null
    rule_progress: { rule_id: number; rule_name: string; approved_streak: number; promotion_candidate: boolean } | null
  }>(
    `/accounting/bank-posting-suggestions/${item.refs.suggestion_id}/approve`,
    { ...overrides, ...(selectedRuleId ? { selected_rule_id: selectedRuleId } : {}) },
    supplierHeaders(item.supplier_id),
  ).then(r => r.data),
  reject: (item: AutomationFeedItem, reason?: string) => api.post(`/accounting/bank-posting-suggestions/${item.refs.suggestion_id}/reject`, reason ? { note: reason } : {}, supplierHeaders(item.supplier_id)).then(r => r.data),
  bulkPreview: (supplierId: number, ids: number[]) => api.post<BulkPostingPreview>('/accounting/bank-posting-suggestions/bulk-preview', { ids }, supplierHeaders(supplierId)).then(r => r.data),
  bulkApprove: (supplierId: number, ids: number[]) => api.post<BulkApproveResult>('/accounting/bank-posting-suggestions/bulk-approve', { ids }, supplierHeaders(supplierId)).then(r => r.data),
  bulkReject: (supplierId: number, ids: number[], reason: string) => api.post<{ rejected: number; failed: Array<{ id: number; code: string }> }>('/accounting/bank-posting-suggestions/bulk-reject', { ids, reason }, supplierHeaders(supplierId)).then(r => r.data),
  snooze: (item: AutomationFeedItem, until: string | null, reason: string | null = null) => api.post(`/accounting/bank-posting-suggestions/${item.refs.suggestion_id}/snooze`, { until, reason }, supplierHeaders(item.supplier_id)).then(r => r.data),
  undoBatch: (supplierId: number, batchId: string) => api.post<{ reversed: number; already_reversed: number }>(`/accounting/bank-posting-suggestions/batches/${batchId}/undo`, {}, supplierHeaders(supplierId)).then(r => r.data),
  unpost: (item: AutomationFeedItem, note: string) => api.post(`/bank-transactions/${item.refs.bank_transaction_id}/unpost`, { note }, supplierHeaders(item.supplier_id)).then(r => r.data),
  bookInvoice: (item: AutomationFeedItem) => api.post(`/accounting/journal/post-invoice/${item.refs.invoice_id}`, {}, supplierHeaders(item.supplier_id)).then(r => r.data),
  bookPurchase: (item: AutomationFeedItem) => api.post(`/accounting/journal/post-purchase/${item.refs.purchase_invoice_id}`, {}, supplierHeaders(item.supplier_id)).then(r => r.data),
}

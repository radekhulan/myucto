import { api } from './client'
import type { JournalSide } from './accounting'

export type AccrualKind = 'prepaid_expense' | 'deferred_revenue' | 'complex_cost' | 'accrued_expense' | 'accrued_revenue'
/** Druhy, které agenda zakládá (381 a 384). */
export const ACCRUAL_AGENDA_KINDS = ['prepaid_expense', 'deferred_revenue'] as const
export type AccrualAgendaKind = typeof ACCRUAL_AGENDA_KINDS[number]
export type AccrualMethod = 'monthly_days' | 'monthly_equal' | 'yearly' | 'manual'
export const ACCRUAL_METHODS: AccrualMethod[] = ['monthly_days', 'monthly_equal', 'yearly', 'manual']
export type AccrualStatus = 'draft' | 'active' | 'completed' | 'terminated' | 'cancelled'
export const ACCRUAL_STATUSES: AccrualStatus[] = ['draft', 'active', 'completed', 'terminated', 'cancelled']
export type AccrualSourceType = 'invoice' | 'purchase_invoice' | 'other_item' | 'internal_document' | 'manual' | 'migration'
export const ACCRUAL_SOURCE_TYPES: AccrualSourceType[] = ['invoice', 'purchase_invoice', 'other_item', 'internal_document', 'manual', 'migration']
export type AccrualScheduleStatus = 'planned' | 'posted' | 'linked' | 'cancelled'

export interface AccrualScheduleRow {
  id: number
  kind: 'release' | 'termination' | 'adjustment'
  due_on: string
  from_on: string | null
  to_on: string | null
  days: number | null
  amount_planned: number
  status: AccrualScheduleStatus
  journal_entry_id: number | null
  entry_date: string | null
  entry_document_no: string | null
  entry_source_type: string | null
  posted_at: string | null
}

export interface AccrualLink {
  id: number
  journal_entry_id: number
  account_code: string
  schedule_id: number | null
  amount: number
  amount_normal: number
  role: string
  matched_by: string
  entry_date: string | null
  document_no: string | null
  description: string | null
  source_type: string | null
  reversed_by: number | null
  reversed_on: string | null
}

export interface AccrualCan {
  edit: boolean
  activate: boolean
  terminate: boolean
  cancel_termination: boolean
  cancel: boolean
  delete: boolean
}

export interface AccrualItem {
  id: number
  item_no: string
  title: string
  contract_ref: string | null
  note: string | null
  kind: AccrualKind
  accrual_account_code: string
  counter_account_code: string | null
  termination_account_code: string | null
  partner_id: number | null
  partner_name: string | null
  source_type: AccrualSourceType
  source_id: number | null
  source_item_id: number | null
  source_document_no: string | null
  managed_by: 'agenda' | 'closing'
  basis_amount: number
  opening_balance: number | null
  opening_as_of: string | null
  period_from: string
  period_to: string
  method: AccrualMethod
  status: AccrualStatus
  terminated_on: string | null
  termination_entry_id: number | null
  termination_reason: string | null
  cost_center: string | null
  project_id: number | null
  row_version: number
  created_at: string
  updated_at: string
  /** Seznam i detail: zůstatek a rozpuštěno k datu `as_of`. */
  balance?: number
  released?: number
  next_due_on?: string | null
}

export interface AccrualDetail extends AccrualItem {
  as_of: string
  balance: number
  released: number
  balance_after_all: number
  covered_through: string | null
  next_release: AccrualScheduleRow | null
  schedule: AccrualScheduleRow[]
  links: AccrualLink[]
  source: { type: string; id: number; document_no: string | null } | null
  can: AccrualCan
}

export interface AccrualListFilter {
  as_of?: string
  status?: string
  kind?: string
  method?: string
  source_type?: string
  managed_by?: string
  account?: string
  partner_id?: number
  ends_by?: string
  active_on?: string
  with_balance?: 1
  q?: string
  sort_by?: string
  sort_dir?: 'asc' | 'desc'
}

export interface AccrualList {
  items: AccrualItem[]
  total: number
  totals: { basis_amount: number; balance: number }
  page: number
  per_page: number
  as_of: string
}

export interface AccrualPayload {
  title: string
  kind: AccrualKind
  accrual_account_code: string
  counter_account_code: string | null
  termination_account_code?: string | null
  basis_amount: number
  period_from: string
  period_to: string
  method: AccrualMethod
  contract_ref?: string | null
  note?: string | null
  partner_id?: number | null
  partner_name?: string | null
  source_type?: AccrualSourceType | null
  source_id?: number | null
  source_item_id?: number | null
  source_document_no?: string | null
  cost_center?: string | null
  item_no?: string | null
  origin_entry_id?: number | null
  origin_amount?: number | null
  activate?: boolean
  row_version?: number
}

export interface AccrualPlanPreviewRow {
  kind: string
  due_on: string
  from_on: string | null
  to_on: string | null
  days: number | null
  amount_planned: number
}

export interface AccrualPostingLine {
  account_code: string
  side: JournalSide
  amount: number
}

export interface AccrualLock {
  postable: boolean
  closed: boolean
  closing: boolean
  date_locked: boolean
  period_status: string | null
  future_locked?: boolean
}

export interface AccrualRowPreview {
  row: AccrualScheduleRow
  entry_date: string
  lines: AccrualPostingLine[]
  lock: AccrualLock
}

export interface AccrualFutureRow {
  id: number
  due_on: string
  amount: number
  status: AccrualScheduleStatus
  journal_entry_id: number | null
  entry_date: string | null
  document_no: string | null
  imported: boolean
}

export interface AccrualTerminationPreview {
  item_id: number
  date: string
  covered_through: string | null
  balance: number
  release_amount: number
  remainder_amount: number
  total: number
  counter_account_code: string | null
  termination_account_code: string | null
  lines: AccrualPostingLine[]
  future_rows: AccrualFutureRow[]
  requires_linked_confirmation: boolean
  lock: AccrualLock
}

export interface AccrualTerminatePayload {
  date: string
  termination_account_code?: string | null
  reason?: string | null
  reverse_future?: boolean
  confirm_linked?: boolean
}

export interface AccrualReconciliationAccount {
  account_id: number
  account_code: string
  account_name: string
  kind: string
  normal_side: JournalSide
  ledger_balance: number
  items_balance: number
  items_count: number
  closing_balance: number
  difference: number
}

export interface AccrualReconciliation {
  as_of: string
  period_starts_on: string | null
  accounts: AccrualReconciliationAccount[]
  totals: { ledger_balance: number; items_balance: number; closing_balance: number; difference: number }
}

export interface AccrualUnallocatedRow {
  entry_id: number
  entry_date: string
  document_no: string | null
  description: string | null
  source_type: string | null
  source_id: number | null
  account_id: number
  account_code: string
  kind: string
  turnover: number
  allocated: number
  unallocated: number
}

export interface AccrualUnallocated {
  as_of: string
  period_starts_on: string | null
  rows: AccrualUnallocatedRow[]
  total: number
  truncated: boolean
}

export interface AccrualProjectionItem {
  managed_by: 'closing'
  kind: string
  source_type: 'invoice' | 'purchase_invoice' | 'other_item'
  source_id: number
  source_item_id: number | null
  document_no: string | null
  title: string
  period_from: string
  period_to: string
  basis_amount: number
  balance: number
}

export interface AccrualProjection {
  as_of: string
  items: AccrualProjectionItem[]
  totals: { prepaid_expense: number; deferred_revenue: number }
  truncated: boolean
}

export type AccrualDocumentSourceType = 'invoice' | 'purchase_invoice' | 'other_item' | 'internal_document'

export interface AccrualSourceCandidates {
  source_type: AccrualDocumentSourceType
  source_id: number
  document_no: string | null
  document_date: string | null
  partner_id: number | null
  partner_name: string | null
  title: string | null
  accrual_lines: Array<{ entry_id: number; entry_date: string; account_code: string; account_name: string; kind: string; amount: number }>
  periods: Array<{ item_id: number | null; from: string; to: string }>
  items: Array<{ id: number; item_no: string; title: string; status: AccrualStatus }>
}

export interface AccrualClosingRow {
  schedule_id: number
  due_on: string
  amount_planned: number
  item_id: number
  item_no: string
  title: string
  kind: AccrualKind
  method: AccrualMethod
  accrual_account_code: string
  counter_account_code: string | null
}

export interface AccrualClosingPending {
  period_id: number
  ends_on: string
  yearly: AccrualClosingRow[]
  monthly: AccrualClosingRow[]
  yearly_total: number
  monthly_total: number
}

export interface AccrualTakeoverItem {
  item_no: string
  import_key: string
  existing_id: number | null
  title: string
  kind: AccrualKind
  account_code: string
  counter_account_code: string | null
  termination_account_code: string | null
  partner_name: string | null
  source_type: string
  source_id: number | null
  source_document_no: string | null
  basis_amount: number
  period_from: string
  period_to: string
  method: AccrualMethod
  status: AccrualStatus
  terminated_on: string | null
  opening_share: number | null
  reconstructed: boolean
  releases: Array<{ entry_id: number; date: string; amount: number }>
  terminations: Array<{ entry_id: number; date: string; amount: number; document_no: string | null }>
  note: string | null
}

export interface AccrualTakeoverTermination {
  entry_id: number
  date: string
  document_no: string | null
  description: string | null
  account_code: string
  amount: number
  matched_by: string | null
  counter_account_code: string | null
  allocations: Record<string, number>
}

export interface AccrualTakeoverPreview {
  opening_date: string | null
  items: AccrualTakeoverItem[]
  opening: Array<{ account_code: string; entry_id: number | null; opening_balance: number; allocated: number; items: number; remainder: number }>
  terminations: AccrualTakeoverTermination[]
  unmatched_terminations: AccrualTakeoverTermination[]
  summary: { items: number; new: number; terminated: number; unpaired_sources: number; opening_remainder: number }
}

export interface AccrualActivity {
  id: number
  user_id: number | null
  user_name: string | null
  action: string
  payload: Record<string, unknown> | null
  created_at: string
}

const BOOK_KEYS = ['as_of', 'status', 'kind', 'method', 'source_type', 'managed_by', 'account', 'partner_id', 'ends_by', 'active_on', 'with_balance', 'q', 'sort_by', 'sort_dir'] as const

export const accrualsApi = {
  list: (params: AccrualListFilter & { page?: number; per_page?: number }) =>
    api.get<AccrualList>('/accounting/accruals', { params }).then(r => r.data),
  bookUrl: (filter: AccrualListFilter, format: 'pdf' | 'xlsx') => {
    const query = new URLSearchParams({ format })
    for (const key of BOOK_KEYS) {
      const value = filter[key]
      if (value !== undefined && value !== '' && value !== null) query.set(key, String(value))
    }
    return `/api/accounting/accruals/book?${query.toString()}`
  },
  get: (id: number, asOf?: string) =>
    api.get<AccrualDetail>(`/accounting/accruals/${id}`, { params: asOf ? { as_of: asOf } : undefined }).then(r => r.data),
  create: (payload: AccrualPayload) => api.post<AccrualDetail>('/accounting/accruals', payload).then(r => r.data),
  update: (id: number, payload: AccrualPayload) => api.put<AccrualDetail>(`/accounting/accruals/${id}`, payload).then(r => r.data),
  remove: (id: number) => api.delete(`/accounting/accruals/${id}`).then(r => r.data),
  activate: (id: number) => api.post<AccrualDetail>(`/accounting/accruals/${id}/activate`).then(r => r.data),
  cancel: (id: number) => api.post<AccrualDetail>(`/accounting/accruals/${id}/cancel`).then(r => r.data),
  planPreview: (payload: { method: AccrualMethod; basis_amount: number; period_from: string; period_to: string; opening_balance?: number | null; opening_as_of?: string | null }) =>
    api.post<{ rows: AccrualPlanPreviewRow[] }>('/accounting/accruals/plan-preview', payload).then(r => r.data),
  activity: (id: number) => api.get<AccrualActivity[]>(`/accounting/accruals/${id}/activity`).then(r => r.data),
  rowPreview: (id: number, rowId: number) =>
    api.get<AccrualRowPreview>(`/accounting/accruals/${id}/schedule/${rowId}/preview`).then(r => r.data),
  postRow: (id: number, rowId: number) =>
    api.post<AccrualDetail>(`/accounting/accruals/${id}/schedule/${rowId}/post`).then(r => r.data),
  unpostRow: (id: number, rowId: number) =>
    api.post<AccrualDetail>(`/accounting/accruals/${id}/schedule/${rowId}/unpost`).then(r => r.data),
  terminationPreview: (id: number, date: string, terminationAccountCode?: string | null) =>
    api.get<AccrualTerminationPreview>(`/accounting/accruals/${id}/termination-preview`, {
      params: { date, ...(terminationAccountCode ? { termination_account_code: terminationAccountCode } : {}) },
    }).then(r => r.data),
  terminate: (id: number, payload: AccrualTerminatePayload) =>
    api.post<AccrualDetail>(`/accounting/accruals/${id}/terminate`, payload).then(r => r.data),
  cancelTermination: (id: number) =>
    api.post<AccrualDetail>(`/accounting/accruals/${id}/cancel-termination`).then(r => r.data),
  addLink: (id: number, payload: { entry_id: number; account_code: string; amount?: number | null }) =>
    api.post<AccrualDetail>(`/accounting/accruals/${id}/links`, payload).then(r => r.data),
  removeLink: (id: number, linkId: number) =>
    api.delete<AccrualDetail>(`/accounting/accruals/${id}/links/${linkId}`).then(r => r.data),
  reconciliation: (asOf?: string) =>
    api.get<AccrualReconciliation>('/accounting/accruals/reconciliation', { params: asOf ? { as_of: asOf } : undefined }).then(r => r.data),
  unallocated: (params: { as_of?: string; account?: string }) =>
    api.get<AccrualUnallocated>('/accounting/accruals/unallocated', { params }).then(r => r.data),
  projection: (asOf?: string) =>
    api.get<AccrualProjection>('/accounting/accruals/projection', { params: asOf ? { as_of: asOf } : undefined }).then(r => r.data),
  sourceCandidates: (sourceType: AccrualDocumentSourceType, sourceId: number) =>
    api.get<AccrualSourceCandidates>('/accounting/accruals/source-candidates', { params: { source_type: sourceType, source_id: sourceId } }).then(r => r.data),
  closingPending: (periodId: number) =>
    api.get<AccrualClosingPending>('/accounting/accruals/closing', { params: { period_id: periodId } }).then(r => r.data),
  closingPost: (periodId: number) =>
    api.post<{ posted: number; total: number }>(`/accounting/accruals/closing/${periodId}/post`).then(r => r.data),
  takeoverPreview: () => api.get<AccrualTakeoverPreview>('/accounting/accruals/takeover/pohoda').then(r => r.data),
  takeoverApply: () =>
    api.post<AccrualTakeoverPreview & { created: number; updated: number }>('/accounting/accruals/takeover/pohoda').then(r => r.data),
}

/** Kód chyby agendy bez prefixu `accruals.error.` (nebo prázdný řetězec). */
export function accrualErrorCode(error: unknown): string {
  const code = (error as { response?: { data?: { error?: { code?: unknown } } } })?.response?.data?.error?.code
  return typeof code === 'string' ? code.replace(/^accruals\.error\./, '') : ''
}

/** Druh položky podle účtu časového rozlišení (381 náklady, 384 výnosy příštích období). */
export function accrualKindForAccount(code: string): AccrualAgendaKind | null {
  if (code.startsWith('381')) return 'prepaid_expense'
  if (code.startsWith('384')) return 'deferred_revenue'
  return null
}

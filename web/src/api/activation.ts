import { api } from './client'

export type ActivationState = 'none' | 'draft' | 'running' | 'completed' | 'failed'
export type BackfillJobStatus = 'queued' | 'running' | 'completed' | 'failed' | 'cancelled'
export type BackfillPhase = 'opening' | 'documents' | 'cash' | 'bank' | 'advance_settlements' | 'account_settlements'

export interface PendingBackfillCounts {
  cash_documents: number
  invoices: number
  purchase_invoices: number
  bank_transactions: number
  /** Zápočty proti účtu bez účetního zápisu (daňová evidence, hromadné přeúčtování). */
  settlements: number
  total: number
}

export interface OpeningBalanceRow {
  account_code: string
  account_name?: string
  side: 'debit' | 'credit'
  amount: number
  note?: string | null
  source?: 'manual' | 'transition_report'
}

export interface OpeningDraft {
  rows: OpeningBalanceRow[]
  totals: { debit: number; credit: number; balanced: boolean }
  hash: string
}

export interface BackfillDocumentIssue {
  source_type: 'invoice' | 'purchase_invoice' | 'cash'
  source_id: number
  document_no: string
  entry_date: string
  severity: 'skipped' | 'failed'
  error_code: string
  message: string
  /** Faktura už měla platný zápis - přeskočení znamená jen, že se zápis nepřepsal. */
  had_posting?: boolean
}

/** Bankovní pohyb, který doúčtování přeskočilo (kromě už zaúčtovaných). */
export interface BackfillBankIssue {
  tx_id: number
  statement_id: number
  posted_at: string
  amount: number
  currency: string
  counterparty_name?: string
  variable_symbol?: string
  reason: string
  message?: string
}

export interface BackfillForced {
  by: number
  at: string
  reason: string
  failed_total: number
}

export interface ForceActivationPayload {
  reason: string
}

export interface BackfillReport {
  starts_on: string
  kind: 'dry_run' | 'execute'
  phases: Record<string, any>
  skip_reasons: Record<string, number>
  document_issues?: BackfillDocumentIssue[]
  bank_issues?: BackfillBankIssue[]
  balance: { debit_cents: number; credit_cents: number; balanced: boolean }
  document_coverage?: Record<'invoice' | 'purchase_invoice', {
    expected: number
    posted_or_updated: number
    skipped: number
    failed: number
    handled: number
    already_posted?: number
    missing: number
    unexpected: number
    complete: boolean
  }>
  failed_total: number
  fatal_error?: string
  forced?: BackfillForced | null
}

export interface BackfillJob {
  id: number
  supplier_id: number
  kind: 'dry_run' | 'execute'
  status: BackfillJobStatus
  phase: BackfillPhase | null
  params: Record<string, any> | null
  total_items: number | null
  processed: number
  report_json: BackfillReport | null
  log_text: string | null
  last_error: string | null
  cancel_requested: boolean
  started_at: string | null
  finished_at: string | null
  created_at: string
}

export interface ActivationStatus {
  activation_status: ActivationState
  accounting_mode: 'tax_evidence' | 'double_entry'
  starts_on: string | null
  pending: PendingBackfillCounts
  /**
   * `editable` = otevírací zápis k datu zahájení jde (ještě) založit — cílové období je
   * otevřené a otevření nepatří uzávěrce předchozího roku. Průvodce podle toho nechává
   * krok 2 dosažitelný i po dokončené aktivaci; `blocked_reason` říká proč ne.
   */
  opening: { rows: number; balanced: boolean; posted: boolean; editable: boolean; blocked_reason: string | null }
  locked_until: string | null
  active_job: BackfillJob | null
  last_job: BackfillJob | null
}

export const activationApi = {
  status: () => api.get<ActivationStatus>('/settings/accounting-activation/status').then(r => r.data),
  start: (starts_on: string) => api.post<ActivationStatus>('/settings/accounting-activation/start', { starts_on }).then(r => r.data),
  opening: () => api.get<OpeningDraft>('/settings/accounting-activation/opening').then(r => r.data),
  saveOpening: (rows: OpeningBalanceRow[]) => api.put<OpeningDraft>('/settings/accounting-activation/opening', { rows }).then(r => r.data),
  prefillOpening: () => api.post<OpeningDraft>('/settings/accounting-activation/opening/prefill', {}).then(r => r.data),
  dryRun: () => api.post<{ job_id: number; status: BackfillJobStatus }>('/settings/accounting-activation/dry-run', {}).then(r => r.data),
  execute: (force?: ForceActivationPayload) => api.post<{ job_id: number; status: BackfillJobStatus }>(
    '/settings/accounting-activation/execute',
    force ? { force: 1, confirm: true, reason: force.reason } : {},
  ).then(r => r.data),
  jobs: (page = 1, perPage = 20) => api.get<{ items: BackfillJob[]; total: number; page: number; per_page: number }>('/settings/accounting-activation/jobs', { params: { page, per_page: perPage } }).then(r => r.data),
  job: (id: number) => api.get<BackfillJob>(`/settings/accounting-activation/jobs/${id}`).then(r => r.data),
  cancel: (id: number) => api.post(`/settings/accounting-activation/jobs/${id}/cancel`, {}).then(r => r.data),
  forceComplete: (id: number, force: ForceActivationPayload) => api.post<ActivationStatus>(
    `/settings/accounting-activation/jobs/${id}/force-complete`,
    { force: 1, confirm: true, reason: force.reason },
  ).then(r => r.data),
}

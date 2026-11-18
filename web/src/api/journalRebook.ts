import { api } from './client'

/**
 * Hromadné přeúčtování: změna účtu X → Y na zaúčtovaných zápisech v deníku podle filtru.
 *
 * Ostrý běh vždy navazuje na dokončený běh nanečisto a přeúčtuje přesně jeho výsledek
 * (bez ručně vyřazených zápisů). Ostrý běh jde vrátit samostatnou úlohou.
 */
export type JournalRebookSide = 'both' | 'debit' | 'credit'
export type JournalRebookSourceType = 'invoice' | 'purchase_invoice' | 'bank' | 'manual' | 'cash'
export type JournalRebookMode = 'rewrite' | 'rewrite_or_reverse'

export interface JournalRebookFilter {
  from_account: string
  to_account: string
  side: JournalRebookSide
  date_from: string
  date_to: string
  source_types: JournalRebookSourceType[]
  text?: string | null
  amount_min?: number | null
  amount_max?: number | null
}

export type JournalRebookJobKind = 'dry_run' | 'apply' | 'rollback'
export type JournalRebookJobStatus = 'queued' | 'running' | 'completed' | 'completed_with_warnings' | 'failed' | 'cancelled'

export interface JournalRebookJob {
  id: number
  kind: JournalRebookJobKind
  status: JournalRebookJobStatus
  total_items: number | null
  processed: number
  changed_count: number
  skipped_count: number
  failed_count: number
  current_step: string | null
  last_error: string | null
  created_at: string | null
  finished_at: string | null
  params: JournalRebookFilter & { mode: JournalRebookMode }
  dry_run_job_id: number | null
  rollback_of_job_id: number | null
  rolled_back_by_job_id: number | null
  applied_by_job_id: number | null
  can_apply: boolean
  can_rollback: boolean
  log_text?: string | null
}

export interface JournalRebookStatus {
  can_run: boolean
  limit: number
  jobs: JournalRebookJob[]
}

export interface JournalRebookCount {
  matching: number
  by_source: Record<JournalRebookSourceType, number>
  unsupported: number
  limit: number
}

export type JournalRebookItemStatus = 'would_change' | 'unchanged' | 'skipped' | 'excluded' | 'applied' | 'failed'

export interface JournalRebookItemLine {
  account_code: string | null
  side: 'debit' | 'credit'
  amount: number
  is_red_storno?: boolean
}

export interface JournalRebookItem {
  id: number
  entry_id: number
  source_type: JournalRebookSourceType
  source_id: number
  document_no: string | null
  entry_date: string
  description: string | null
  amount: number
  status: JournalRebookItemStatus
  strategy: 'replace' | 'reverse' | null
  target_date: string | null
  date_shifted: boolean
  reason_code: string | null
  message: string | null
  before: JournalRebookItemLine[]
  after: JournalRebookItemLine[]
  result_entry_id: number | null
  items_synced: number
}

export interface JournalRebookItemsPage {
  items: JournalRebookItem[]
  total: number
  counts: Record<JournalRebookItemStatus, number>
}

export type JournalRebookDryRunParams = JournalRebookFilter & { mode: JournalRebookMode; dry_run: true }

export interface JournalRebookApplyParams {
  dry_run: false
  dry_run_job_id: number
  excluded_entry_ids: number[]
}

export const JOURNAL_REBOOK_PER_PAGE = 50

export const journalRebookApi = {
  status: (): Promise<JournalRebookStatus> =>
    api.get<JournalRebookStatus>('/accounting/journal-rebook').then(r => r.data),

  count: (filter: JournalRebookFilter): Promise<JournalRebookCount> =>
    api.post<JournalRebookCount>('/accounting/journal-rebook/count', filter).then(r => r.data),

  start: (params: JournalRebookDryRunParams | JournalRebookApplyParams): Promise<{ job_id: number }> =>
    api.post<{ job_id: number }>('/accounting/journal-rebook/start', params).then(r => r.data),

  rollback: (id: number): Promise<{ job_id: number }> =>
    api.post<{ job_id: number }>(`/accounting/journal-rebook/${id}/rollback`, {}).then(r => r.data),

  cancel: (id: number): Promise<void> =>
    api.post(`/accounting/journal-rebook/${id}/cancel`, {}).then(() => undefined),

  job: (id: number): Promise<JournalRebookJob> =>
    api.get<JournalRebookJob>(`/accounting/journal-rebook/${id}`).then(r => r.data),

  /** `status` null = položky všech stavů (export). */
  items: (id: number, status: JournalRebookItemStatus | null, page: number, perPage = JOURNAL_REBOOK_PER_PAGE): Promise<JournalRebookItemsPage> =>
    api.get<JournalRebookItemsPage>(`/accounting/journal-rebook/${id}/items`, {
      params: { ...(status ? { status } : {}), page, per_page: perPage },
    }).then(r => r.data),
}

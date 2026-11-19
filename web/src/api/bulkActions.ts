import { api } from './client'
import type { JournalRebookItemLine, JournalRebookMode, JournalRebookSide } from './journalRebook'

/**
 * Hromadné akce nad výběrem ze seznamu (vydané a přijaté faktury, účetní deník): přeúčtování,
 * dimenze, předkontace a smazání. Každá akce jede jako úloha na pozadí: nejdřív nanečisto,
 * ostrý běh převezme přesně jeho výsledek bez ručně vyřazených položek a jde vrátit
 * (kromě smazání).
 */
export type BulkTarget = 'invoice' | 'purchase_invoice' | 'journal_entry' | 'bank_transaction'
export type BulkOperation = 'rebook_account' | 'set_dimensions' | 'set_posting_rule' | 'delete' | 'set_note' | 'set_variable_symbol'
export type BulkJobKind = 'dry_run' | 'apply' | 'rollback'
export type BulkJobStatus = 'queued' | 'running' | 'completed' | 'completed_with_warnings' | 'failed' | 'cancelled'
export type BulkItemStatus = 'would_change' | 'unchanged' | 'skipped' | 'excluded' | 'applied' | 'failed'

/** Ručně vybraná id z aktuálního seznamu, nebo „všech N podle filtru" (query seznamu bez stránkování). */
export type BulkSelection = { ids: number[] } | { list_query: Record<string, unknown> }

export type DimensionChangeMode = 'set' | 'fill' | 'clear'
export interface DimensionChange {
  type_id: number
  mode: DimensionChangeMode
  value_id?: number | null
}

export interface RebookAccountParams {
  from_account: string
  to_account: string
  side: JournalRebookSide
  mode: JournalRebookMode
}
export interface SetDimensionsParams {
  dimensions: DimensionChange[]
  scope?: 'document' | 'items'
  account_prefixes?: string[]
}
export interface SetPostingRuleParams {
  rule_key: string
  mode: JournalRebookMode
}
export interface DeleteParams {
  journal_fallback?: 'skip' | 'reverse'
}
/** Poznámka faktur (text nad / pod položkami); bez `include_issued` jen koncepty. */
export interface SetNoteParams {
  note_field: 'note_above_items' | 'note_below_items'
  note_mode: 'replace' | 'append' | 'clear'
  note_text?: string
  include_issued: boolean
}
/** Variabilní symbol bankovních pohybů: stálá hodnota, nebo vzor se značkami {ss}, {ks}, {vs}, {zprava}. */
export type SetVariableSymbolParams =
  | { vs_mode: 'constant'; vs_value: string }
  | { vs_mode: 'pattern'; vs_pattern: string }
export type BulkParams = RebookAccountParams | SetDimensionsParams | SetPostingRuleParams | DeleteParams
  | SetNoteParams | SetVariableSymbolParams

export interface BulkOperationInfo {
  key: BulkOperation
  available: boolean
  reason: string | null
  rollback: boolean
}

export interface BulkJob {
  id: number
  kind: BulkJobKind
  status: BulkJobStatus
  operation: BulkOperation
  target: BulkTarget
  selection_count: number
  total_items: number | null
  processed: number
  changed_count: number
  skipped_count: number
  failed_count: number
  current_step: string | null
  last_error: string | null
  created_at: string | null
  finished_at: string | null
  params: Record<string, unknown>
  dry_run_job_id: number | null
  rollback_of_job_id: number | null
  applied_by_job_id: number | null
  rolled_back_by_job_id: number | null
  can_apply: boolean
  can_rollback: boolean
  rollback_supported: boolean
  log_text?: string | null
}

export interface BulkStatus {
  can_run: boolean
  limit: number
  operations: BulkOperationInfo[]
  jobs: BulkJob[]
}

export interface DimensionSnapshotRow {
  kind: 'header' | 'item' | 'line'
  /** Hlavička 0, položky 1..n, řádky zápisu podle pořadí. */
  no: number | null
  line_id: number | null
  account_code: string | null
  side: 'debit' | 'credit' | null
  amount: number | null
  entry_date?: string | null
  account_id?: number | null
  /** typ → hodnota; prázdná mapa může přijít jako `[]`. */
  dimensions: Record<string, number> | []
  /** typ → (hodnota → podíl); prázdná mapa může přijít jako `[]`. */
  splits: Record<string, Record<string, number>> | []
}

export interface PostingRuleSnapshot {
  items: Array<{
    item_id: number
    description: string | null
    posting_rule_key: string | null
    account_code: string | null
    counter_account_code: string | null
  }>
  lines: JournalRebookItemLine[]
}

export interface DeleteBefore { status: string | null; posted: boolean }
export interface DeleteAfter { action: 'delete' | 'reverse' }

export interface BulkItem {
  id: number
  entry_id: number | null
  source_type: 'invoice' | 'purchase_invoice' | 'journal_entry' | 'bank' | 'manual' | 'cash'
  source_id: number
  document_no: string | null
  entry_date: string
  description: string | null
  amount: number
  status: BulkItemStatus
  strategy: string | null
  target_date: string | null
  date_shifted: boolean
  reason_code: string | null
  message: string | null
  before: unknown
  after: unknown
  result_entry_id: number | null
  items_synced: number
}

export interface BulkLabels {
  types: Record<string, string>
  values: Record<string, string>
}

export interface BulkItemsPage {
  items: BulkItem[]
  total: number
  counts: Partial<Record<BulkItemStatus, number>>
  labels?: BulkLabels
}

export interface BulkDryRunRequest {
  dry_run: true
  operation: BulkOperation
  target: BulkTarget
  selection: BulkSelection
  params: BulkParams
}

export interface BulkApplyRequest {
  dry_run: false
  dry_run_job_id: number
  excluded_item_ids: number[]
  /** Povinné u smazání: počet položek, které se provedou (would_change minus vyřazené). */
  confirm_count?: number
}

export const BULK_ITEMS_PER_PAGE = 50

export const bulkActionsApi = {
  status: (target: BulkTarget): Promise<BulkStatus> =>
    api.get<BulkStatus>('/accounting/bulk-actions', { params: { target } }).then(r => r.data),

  start: (body: BulkDryRunRequest | BulkApplyRequest): Promise<{ job_id: number; status?: string }> =>
    api.post<{ job_id: number; status?: string }>('/accounting/bulk-actions/start', body).then(r => r.data),

  job: (id: number): Promise<BulkJob> =>
    api.get<BulkJob>(`/accounting/bulk-actions/${id}`).then(r => r.data),

  /** `status` null = položky všech stavů (export). */
  items: (id: number, status: BulkItemStatus | null, page: number, perPage = BULK_ITEMS_PER_PAGE): Promise<BulkItemsPage> =>
    api.get<BulkItemsPage>(`/accounting/bulk-actions/${id}/items`, {
      params: { ...(status ? { status } : {}), page, per_page: perPage },
    }).then(r => r.data),

  rollback: (id: number): Promise<{ job_id: number }> =>
    api.post<{ job_id: number }>(`/accounting/bulk-actions/${id}/rollback`, {}).then(r => r.data),

  cancel: (id: number): Promise<void> =>
    api.post(`/accounting/bulk-actions/${id}/cancel`, {}).then(() => undefined),
}

import { api } from './client'
import type { JournalRebookItemStatus, JournalRebookJob } from './journalRebook'

/**
 * Hromadná práce s nespárovanými a nezaúčtovanými bankovními pohyby podle filtru:
 * zaúčtování na protiúčet (`post`) nebo spárování s jedním dokladem (`match`).
 *
 * Stejná kostra úloh jako hromadné přeúčtování: nanečisto, ostře podle běhu nanečisto
 * (bez ručně vyřazených pohybů) a vrácení ostrého běhu.
 */
export type BankBulkAction = 'post' | 'match'
export type BankBulkDirection = 'out' | 'in' | 'both'
export type BankBulkDocumentType = 'invoice' | 'purchase_invoice'

export interface BankBulkFilter {
  action: BankBulkAction
  date_from: string
  date_to: string
  direction?: BankBulkDirection
  bank_account?: string | null
  text?: string | null
  counterparty_account?: string | null
  variable_symbol?: string | null
  amount_min?: number | null
  amount_max?: number | null
  counter_account?: string
  description?: string | null
  document_type?: BankBulkDocumentType
  document_id?: number
  /** Číslo dokladu párování, doplní server do parametrů běhu. */
  document_no?: string | null
}

export type BankBulkJob = Omit<JournalRebookJob, 'params'> & { params: BankBulkFilter }

export interface BankBulkAccount {
  account_number: string
  bank_code: string | null
  label: string | null
}

export interface BankBulkStatus {
  can_run: boolean
  can_match: boolean
  limit: number
  match_limit: number
  accounts: BankBulkAccount[]
  jobs: BankBulkJob[]
}

export interface BankBulkCount {
  matching: number
  income: number
  expense: number
  total_amount: number
  limit: number
  action_limit: number
}

export type BankBulkItemStatus = JournalRebookItemStatus

export interface BankBulkPostLine {
  account_code: string | null
  side: 'debit' | 'credit'
  amount: number
}

export interface BankBulkMatchTarget {
  document_type: BankBulkDocumentType
  document_id: number
  document_no: string | null
}

export interface BankBulkItem {
  id: number
  entry_id: number | null
  source_type: 'bank'
  /** Id bankovního pohybu. */
  source_id: number
  /** Variabilní symbol pohybu. */
  document_no: string | null
  entry_date: string
  /** „protistrana · text" */
  description: string | null
  /** Se znaménkem, výdej je záporný. */
  amount: number
  status: BankBulkItemStatus
  reason_code: string | null
  message: string | null
  after: Array<BankBulkPostLine | BankBulkMatchTarget>
  result_entry_id: number | null
}

export interface BankBulkItemsPage {
  items: BankBulkItem[]
  total: number
  counts: Record<BankBulkItemStatus, number>
}

export type BankBulkDryRunParams = BankBulkFilter & { dry_run: true }

export interface BankBulkApplyParams {
  dry_run: false
  dry_run_job_id: number
  excluded_ids: number[]
}

export const BANK_BULK_PER_PAGE = 50

export const bankBulkApi = {
  status: (): Promise<BankBulkStatus> =>
    api.get<BankBulkStatus>('/accounting/bank-bulk').then(r => r.data),

  count: (filter: BankBulkFilter): Promise<BankBulkCount> =>
    api.post<BankBulkCount>('/accounting/bank-bulk/count', filter).then(r => r.data),

  start: (params: BankBulkDryRunParams | BankBulkApplyParams): Promise<{ job_id: number; status: string }> =>
    api.post<{ job_id: number; status: string }>('/accounting/bank-bulk/start', params).then(r => r.data),

  rollback: (id: number): Promise<{ job_id: number; status: string }> =>
    api.post<{ job_id: number; status: string }>(`/accounting/bank-bulk/${id}/rollback`, {}).then(r => r.data),

  cancel: (id: number): Promise<void> =>
    api.post(`/accounting/bank-bulk/${id}/cancel`, {}).then(() => undefined),

  job: (id: number): Promise<BankBulkJob> =>
    api.get<BankBulkJob>(`/accounting/bank-bulk/${id}`).then(r => r.data),

  /** `status` null = položky všech stavů (export). */
  items: (id: number, status: BankBulkItemStatus | null, page: number, perPage = BANK_BULK_PER_PAGE): Promise<BankBulkItemsPage> =>
    api.get<BankBulkItemsPage>(`/accounting/bank-bulk/${id}/items`, {
      params: { ...(status ? { status } : {}), page, per_page: perPage },
    }).then(r => r.data),
}

import { api } from './client'

/**
 * Doúčtování nezaúčtovaných dokladů — samostatná úloha, ne krok průvodce aktivací.
 *
 * Automatika účtování je háček na VZNIK dokladu (vystavení, přijetí, opakovaná
 * fakturace). Doklad, který už v systému leží — typicky naimportovaný — jí neprojde
 * nikdy, ať je zapnutá jakkoli. Tohle je operace, která ho projde.
 */
export interface PostingBackfillPending {
  cash_documents: number
  invoices: number
  purchase_invoices: number
  bank_transactions: number
  settlements: number
  total: number
}

export interface PostingBackfillJob {
  id: number
  status: 'queued' | 'running' | 'completed' | 'completed_with_warnings' | 'failed' | 'cancelled'
  total_items: number | null
  processed: number
  posted_count: number
  skipped_count: number
  failed_count: number
  current_step: string | null
  last_error: string | null
  created_at: string | null
  finished_at: string | null
  dry_run: boolean
  log_text?: string | null
}

export interface PostingBackfillStatus {
  pending: PostingBackfillPending
  jobs: PostingBackfillJob[]
}

export interface PostingBackfillStartParams {
  from?: string | null
  year?: number | null
  dry_run?: boolean
}

export const postingBackfillApi = {
  status: (): Promise<PostingBackfillStatus> =>
    api.get<PostingBackfillStatus>('/accounting/posting-backfill').then(r => r.data),

  start: (params: PostingBackfillStartParams = {}): Promise<{ job_id: number }> =>
    api.post<{ job_id: number }>('/accounting/posting-backfill/start', params).then(r => r.data),

  job: (id: number): Promise<PostingBackfillJob> =>
    api.get<PostingBackfillJob>(`/accounting/posting-backfill/${id}`).then(r => r.data),

  cancel: (id: number): Promise<void> =>
    api.post(`/accounting/posting-backfill/${id}/cancel`, {}).then(() => undefined),
}

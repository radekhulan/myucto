import { api } from './client'
import { uploadChunked, type ChunkedUploadProgress } from './chunkedUpload'
import type { MoneyS3Agenda, MoneyS3ProtocolData } from './moneyS3'

/**
 * Průvodce „Přechod z Money S3", režim Dávka: více záloh (firem) najednou pro účetní
 * kancelář. Zálohy se nahrávají po částech jako u jedné firmy (po jedné, server každou
 * zpracuje na pozadí), dávka pak běží jedním jobem; stav jobu přes společné
 * `/admin/imports/{id}`, souhrnný protokol dávky přes `batch/jobs/{id}`.
 */

const BASE = '/admin/imports/money-s3/batch'

export type CompanyAccess = 'none' | 'ok' | 'forbidden' | 'ambiguous'

export interface BatchUploadReady {
  token: string
  status: 'ready'
  file_name: string
  uploaded_at: string
  agenda: MoneyS3Agenda
  suggested_from_year: number | null
  company: { access: CompanyAccess; supplier_id: number | null; name: string | null }
}

export interface BatchUploadPending {
  token: string
  status: 'uploading' | 'processing' | 'failed'
  file_name: string
  size: number
  received?: number
  job_id: number | null
  error: string | null
}

export type BatchUpload = BatchUploadReady | BatchUploadPending

export function isBatchUploadReady(u: BatchUpload): u is BatchUploadReady {
  return u.status === 'ready' && 'agenda' in u
}

export interface BatchFiling {
  id: string
  file_name: string
  ic: string
  name: string
  year: number | null
  forma: string
  category: string | null
  audit: boolean | null
  nace: string | null
}

export type Criteria = { K1: boolean | null; K2: boolean | null; K3: boolean | null; K4: boolean | null }

export interface BatchItemSummary {
  agenda?: { name: string; ico: string; version: string; years: number[] }
  identity?: { name: string; notes: string[]; vat_payer: boolean | null; category: string | null }
  protocol_status?: string
  errors?: number
  warnings?: number
  first_error?: { step: string; code: string; text: string } | null
  years?: number[]
  criteria?: { years: Record<string, Criteria>; total: Criteria }
  closing?: { year: number; status: string }[]
  filings?: { year: number; forma: string; status: string; error?: string; input_mismatches?: number }[]
  filings_available?: number[]
  notices?: string[]
  converted?: boolean
  note?: string
  protocol?: MoneyS3ProtocolData
}

export type BatchItemStatus = 'queued' | 'running' | 'completed' | 'completed_with_warnings' | 'failed' | 'skipped' | 'cancelled'

export interface BatchItem {
  id: number
  position: number
  file_name: string | null
  agenda_ico: string | null
  agenda_name: string | null
  target_supplier_id: number | null
  target_name: string | null
  status: BatchItemStatus
  company_action: 'created' | 'existing' | 'skipped' | null
  from_year: number | null
  run_id: number | null
  summary: BatchItemSummary | null
  error: string | null
}

export interface BatchJob {
  id: number
  status: 'queued' | 'running' | 'completed' | 'completed_with_warnings' | 'failed' | 'cancelled'
  created_at: string | null
  finished_at: string | null
  current_step: string | null
  total_items: number
  processed: number
  last_error: string | null
  options: Partial<BatchStartParams>
  items: BatchItem[]
}

export interface BatchStartParams {
  tokens: string[]
  filing_ids: string[]
  mode: 'dry_run' | 'import'
  from_year: 'auto' | 'all'
  /** Poslední převáděný rok všech firem dávky, nebo auto (u každé firmy poslední rok bez vlastních zápisů). */
  to_year: 'auto' | number
  /** Z roku po posledním převáděném jen počáteční stavy. */
  opening_only_next: boolean
  existing: 'skip' | 'update'
  close_history: boolean
  disposal_year_tax: 'half' | 'none'
  group_mode: 'none' | 'current' | 'new'
  group_name: string
  related_parties: boolean
  use_registry: boolean
  take_over_filings: boolean
}

export const moneyS3BatchApi = {
  list: (): Promise<{ items: BatchUpload[]; filings: BatchFiling[]; can_create_companies: boolean; current_group_id: number | null }> =>
    api.get(`${BASE}/uploads`).then(r => r.data),
  uploadChunked: (file: File, onProgress?: ChunkedUploadProgress, onStarted?: (token: string) => void): Promise<{ token: string; job_id: number | null }> =>
    uploadChunked(BASE, file, onProgress, onStarted),
  deleteUpload: (token: string): Promise<{ ok: boolean }> =>
    api.delete(`${BASE}/uploads/${token}`).then(r => r.data),
  uploadFiling: (file: File): Promise<BatchFiling> => {
    const fd = new FormData()
    fd.append('filing', file, file.name)
    return api.post<BatchFiling>(`${BASE}/filings`, fd, { headers: { 'Content-Type': 'multipart/form-data' } }).then(r => r.data)
  },
  deleteFiling: (id: string): Promise<{ ok: boolean }> =>
    api.delete(`${BASE}/filings/${id}`).then(r => r.data),
  start: (params: BatchStartParams): Promise<{ job_id: number; status: string; mode: string; companies: number; superseded: string[] }> =>
    api.post(`${BASE}/start`, params).then(r => r.data),
  jobs: (): Promise<{ items: BatchJob[] }> =>
    api.get(`${BASE}/jobs`).then(r => r.data),
  job: (id: number): Promise<BatchJob> =>
    api.get<BatchJob>(`${BASE}/jobs/${id}`).then(r => r.data),
}

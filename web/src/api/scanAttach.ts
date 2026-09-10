import { api } from './client'

/**
 * Připojení skenů k dokladům, které už v systému jsou. Dávka se nahraje po
 * částech (ZIP nebo víc souborů), zpracuje na pozadí a výsledek se potvrzuje
 * v přehledu dávky.
 */
export type ScanTargetType = 'purchase_invoice' | 'invoice' | 'cash_document'
export type ScanBatchStatus = 'queued' | 'running' | 'completed' | 'completed_with_warnings' | 'failed' | 'cancelled'
export type ScanMatchMethod = 'barcode' | 'doc_no' | 'content'
export type ScanMatchLevel = 'certain' | 'likely' | 'candidate'

export interface ScanTargetInfo {
  type: ScanTargetType
  available: boolean
  allowed: boolean
}

export interface ScanBatchParams {
  mode: 'zip' | 'files' | null
  targets: ScanTargetType[]
  date_from: string | null
  date_to: string | null
  accept_likely: boolean
  trust_doc_no: boolean
  extract: boolean
}

export interface ScanBatch {
  id: number
  status: ScanBatchStatus
  total_items: number | null
  processed: number
  attached_count: number
  proposed_count: number
  failed_count: number
  current_step: string | null
  last_error: string | null
  cancel_requested: boolean
  created_at: string
  finished_at: string | null
  params: ScanBatchParams
  counts?: Record<string, number>
}

export interface ScanTargetLabel {
  label: string
  counterparty: string | null
  date: string | null
  total: number | null
  currency: string | null
}

export interface ScanSummary {
  company_role: string | null
  vendor_name: string | null
  vendor_ico: string | null
  buyer_name: string | null
  buyer_ico: string | null
  document_number: string | null
  variable_symbol: string | null
  issue_date: string | null
  tax_date: string | null
  total_with_vat: number | null
  currency: string | null
  barcode: string | null
  license_plate: string | null
  card_last4: string | null
}

export interface ScanMatchRow {
  match_id: number
  item_id: number
  file_name: string
  document_id: number | null
  target_type: ScanTargetType
  target_id: number
  target: ScanTargetLabel | null
  method: ScanMatchMethod
  level: ScanMatchLevel
  note: string
  state: string
  scan?: ScanSummary | null
}

export interface ScanMissingRow extends ScanTargetLabel {
  target_type: ScanTargetType
  id: number
}

export interface ScanItemRow {
  item_id: number
  file_name: string
  document_id: number | null
  outcome: string
  error: string | null
  scan: ScanSummary | null
}

export interface ScanOverview {
  targets: ScanTargetType[]
  date_from: string | null
  date_to: string | null
  counts: {
    files: number
    attached: number
    candidates: number
    missing: number
    orphans: number
    unrecognized: number
    discrepancies: number
  }
  attached: ScanMatchRow[]
  candidates: ScanMatchRow[]
  missing: ScanMissingRow[]
  orphans: ScanItemRow[]
  unrecognized: ScanItemRow[]
  discrepancies: { available: boolean; rows: unknown[] }
  list_limit: number
}

export interface ScanBatchDetail extends ScanBatch {
  log_text: string | null
  overview: ScanOverview | null
}

export interface ScanStartParams {
  mode: 'zip' | 'files'
  targets: ScanTargetType[]
  date_from?: string | null
  date_to?: string | null
  accept_likely: boolean
  trust_doc_no: boolean
  extract: boolean
}

export const scanAttachApi = {
  targets: () =>
    api.get<{ targets: ScanTargetInfo[]; default: ScanTargetType[] }>('/scan-attach/targets').then(r => r.data),

  batches: () =>
    api.get<{ batches: ScanBatch[] }>('/scan-attach/batches').then(r => r.data.batches),

  start: (params: ScanStartParams) =>
    api.post<{ job_id: number }>('/scan-attach/batches', params).then(r => r.data),

  chunkBytes: (id: number, chunk: Blob) =>
    api.post<{ size: number }>(`/scan-attach/batches/${id}/chunk-bytes`, chunk, {
      headers: { 'Content-Type': 'application/octet-stream' },
    }).then(r => r.data),

  chunkFiles: (id: number, files: File[]) => {
    const fd = new FormData()
    for (const f of files) fd.append('file[]', f, f.name)
    return api.post<{ added: number }>(`/scan-attach/batches/${id}/chunk-files`, fd, {
      headers: { 'Content-Type': 'multipart/form-data' },
    }).then(r => r.data)
  },

  finish: (id: number) =>
    api.post<{ job_id: number }>(`/scan-attach/batches/${id}/finish`, {}).then(r => r.data),

  batch: (id: number) =>
    api.get<ScanBatchDetail>(`/scan-attach/batches/${id}`).then(r => r.data),

  resume: (id: number) =>
    api.post<{ job_id: number }>(`/scan-attach/batches/${id}/resume`, {}).then(r => r.data),

  cancel: (id: number) =>
    api.post(`/scan-attach/batches/${id}/cancel`, {}).then(() => undefined),

  remove: (id: number) =>
    api.delete(`/scan-attach/batches/${id}`).then(() => undefined),

  confirm: (matchId: number) =>
    api.post(`/scan-attach/matches/${matchId}/confirm`, {}).then(() => undefined),

  reject: (matchId: number) =>
    api.post(`/scan-attach/matches/${matchId}/reject`, {}).then(() => undefined),
}

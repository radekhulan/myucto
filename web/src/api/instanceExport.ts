import { api } from './client'

/**
 * Kompletní export dat firmy (H-14).
 *
 * Endpointy jsou pod `/api/admin/`, takže je middleware pouští jen superadminovi —
 * archiv je celé účetnictví firmy včetně příloh.
 *
 * Volitelná část `restore` je verzovaný archiv pro explicitní CLI obnovu jako
 * nová firma. Ostatní části zůstávají přenositelným exportem ke čtení.
 */

export type InstanceExportPart = 'restore' | 'data' | 'documents' | 'files' | 'vat_exports' | 'closing_packages'

export type InstanceExportStatus = 'queued' | 'running' | 'completed' | 'failed' | 'cancelled'

export interface InstanceExportJob {
  id: number
  status: InstanceExportStatus
  parts: InstanceExportPart[] | null
  date_from: string | null
  date_to: string | null
  total_steps: number | null
  processed_steps: number
  current_step: string | null
  last_error: string | null
  cancel_requested: boolean
  result_name: string | null
  size_bytes: number | null
  sha256: string | null
  encrypted: boolean
  expires_at: string | null
  created_at: string
  finished_at: string | null
  downloadable: boolean
  warning_count?: number
  log_text?: string | null
  summary?: {
    entries: number | null
    tables: number
    documents: Record<string, number> | null
    files: number | null
    restore: Record<string, unknown> | null
    vat: Record<string, unknown> | null
    closing: Record<string, unknown> | null
  } | null
}

export interface InstanceExportOverview {
  parts: InstanceExportPart[]
  /** Je archiv šifrovaný (cfg cron.backup.password)? Pokud ne, UI to musí říct nahlas. */
  encrypted: boolean
  /** Za kolik dnů se hotový archiv smaže. */
  ttl_days: number
  profile?: {
    accounting_mode: 'tax_evidence' | 'double_entry'
    is_vat_payer: boolean
    vat_period: 'monthly' | 'quarterly' | null
  }
  active: InstanceExportJob | null
  items: InstanceExportJob[]
}

export interface StartInstanceExportPayload {
  parts?: InstanceExportPart[]
  date_from?: string | null
  date_to?: string | null
}

export const instanceExportApi = {
  overview: () => api.get<InstanceExportOverview>('/admin/instance-export').then(r => r.data),
  start: (payload: StartInstanceExportPayload = {}) =>
    api.post<{ id: number; status: InstanceExportStatus }>('/admin/instance-export/start', payload).then(r => r.data),
  status: (id: number) => api.get<InstanceExportJob>(`/admin/instance-export/${id}`).then(r => r.data),
  download: (id: number) =>
    api.get<Blob>(`/admin/instance-export/${id}/download`, { responseType: 'blob' }),
  cancel: (id: number) => api.post(`/admin/instance-export/${id}/cancel`, {}).then(r => r.data),
  remove: (id: number) => api.delete(`/admin/instance-export/${id}`).then(r => r.data),
}

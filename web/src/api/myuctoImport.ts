import { api } from './client'
import type { FileImportJob } from './imports'
import { uploadChunked, type ChunkedUploadProgress } from './chunkedUpload'

const BASE = '/admin/imports/myucto'
export const MYUCTO_IMPORT_MAX_BYTES = 2 * 1024 * 1024 * 1024

export interface MyuctoImportReport {
  dry_run: boolean
  supplier_id: number
  source_supplier_id: number
  created: Record<string, number>
  reused: Record<string, number>
  existing: Record<string, number>
  outside_scope: Record<string, number>
  files: number
  reconciliation: Record<string, number>
}
export interface MyuctoImportResult {
  token: string
  company_name: string
  ic: string
  report: MyuctoImportReport
}

export interface MyuctoImportRun extends FileImportJob {
  token: string
  source_name: string
  mode: 'dry_run' | 'import'
  created_at: string
  finished_at: string | null
  result: MyuctoImportResult | null
}

export const myuctoImportApi = {
  async runs(): Promise<MyuctoImportRun[]> {
    return (await api.get<{ runs: MyuctoImportRun[] }>(`${BASE}/runs`)).data.runs
  },
  async status(id: number): Promise<MyuctoImportRun> {
    return (await api.get<MyuctoImportRun>(`${BASE}/runs/${id}`)).data
  },
  upload: (file: File, onProgress?: ChunkedUploadProgress) => uploadChunked(BASE, file, onProgress),
  async run(token: string, source: string, password: string, apply = false): Promise<{ job_id: number; status: string }> {
    return (await api.post<{ job_id: number; status: string }>(`${BASE}/uploads/${token}/run`, {
      mode: apply ? 'import' : 'dry_run', source, ...(password ? { password } : {}), confirmed: apply,
    })).data
  },
}

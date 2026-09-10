import { api } from './client'
import type { CatalogJob } from './catalogJobs'

export interface OpeningImportSource {
  id: number
  original_name: string
  format: 'csv' | 'xlsx'
  size_bytes: number
  sha256: string
  created_at: string
}

export interface OpeningImportSample {
  source: OpeningImportSource
  header: string[]
  rows: string[][]
  fields: Array<'external_id' | 'sku' | 'quantity' | 'unit_cost'>
}

export interface OpeningImportConfig {
  source_key: string
  warehouse_id: number
  doc_date: string
  mapping: Record<string, string>
  reader: {
    encoding: 'UTF-8' | 'Windows-1250' | 'ISO-8859-2'
    delimiter: ';' | ',' | '\t' | '|'
    sheet: number
  }
}

export interface OpeningImportJobItems {
  items: Array<{
    ordinal: number
    source_row: number
    status: string
    error_code: string | null
    input: { values?: Record<string, string> }
    before: Record<string, unknown> | null
    after: Record<string, unknown> | null
  }>
  pagination: { page: number; limit: number; total: number; pages: number }
}

export const openingStockImportApi = {
  upload: (file: File) => {
    const data = new FormData()
    data.append('file', file)
    return api.post<OpeningImportSource>('/stock/opening-import/sources', data, {
      headers: { 'Content-Type': 'multipart/form-data' },
    }).then(r => r.data)
  },
  sample: (id: number, reader: OpeningImportConfig['reader']) =>
    api.get<OpeningImportSample>(`/stock/opening-import/sources/${id}/sample`, { params: reader }).then(r => r.data),
  preview: (sourceId: number, config: OpeningImportConfig) =>
    api.post<CatalogJob>('/stock/opening-import/preview', { source_id: sourceId, config }).then(r => r.data),
  apply: (previewId: number) => api.post<CatalogJob>(`/stock/opening-import/${previewId}/apply`).then(r => r.data),
  job: (id: number) => api.get<CatalogJob>(`/stock/opening-import/jobs/${id}`).then(r => r.data),
  items: (id: number, page = 1) => api.get<OpeningImportJobItems>(`/stock/opening-import/jobs/${id}/items`, { params: { page, limit: 50 } }).then(r => r.data),
  retry: (id: number) => api.post<CatalogJob>(`/stock/opening-import/jobs/${id}/retry`).then(r => r.data),
  cancel: (id: number) => api.post<CatalogJob>(`/stock/opening-import/jobs/${id}/cancel`).then(r => r.data),
}

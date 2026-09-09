import { api } from './client'
import type { CatalogJob } from './catalogJobs'

export type CatalogImportOperation = 'set' | 'preserve' | 'clear'
export type CatalogImportItemStatus = 'pending' | 'ready' | 'applied' | 'unchanged' | 'failed' | 'conflict' | 'skipped'

export interface CatalogImportSource {
  id: number
  original_name: string
  format: 'csv' | 'xlsx'
  size_bytes: number
  sha256: string
}

export interface CatalogImportConfig {
  identity: 'sku' | 'id' | 'external_id'
  source_key: string | null
  mode: 'create' | 'update' | 'upsert'
  mapping: Record<string, string>
  blank: 'preserve' | 'clear'
  operations: Record<string, CatalogImportOperation>
  reader: {
    encoding: 'UTF-8' | 'Windows-1250' | 'ISO-8859-2'
    delimiter: ';' | ',' | '\t' | '|'
    sheet: number
  }
}

export interface CatalogImportProfile {
  id: number
  name: string
  version: number
  config: CatalogImportConfig
}

export interface CatalogImportSample {
  source: CatalogImportSource
  header: string[]
  rows: string[][]
  fields: string[]
}

export interface CatalogImportItem {
  ordinal: number
  source_row: number | null
  stock_item_id: number | null
  status: CatalogImportItemStatus
  before: Record<string, unknown> | null
  after: Record<string, unknown> | null
  error_code: string | null
  input?: { raw?: string[] }
}

export interface CatalogImportItems {
  items: CatalogImportItem[]
  pagination: {
    page: number
    pages: number
    total: number
    limit: number
  }
}

export const catalogImportApi = {
  upload: (file: File, signal?: AbortSignal) => {
    const body = new FormData()
    body.append('file', file)
    return api.post<CatalogImportSource>('/eshop/imports/sources', body, {
      signal,
      headers: { 'Content-Type': 'multipart/form-data' },
    }).then(response => response.data)
  },
  sample: (id: number, reader: CatalogImportConfig['reader'], signal?: AbortSignal) => (
    api.get<CatalogImportSample>(`/eshop/imports/sources/${id}/sample`, {
      params: reader,
      signal,
    }).then(response => response.data)
  ),
  profiles: (signal?: AbortSignal) => (
    api.get<{ items: CatalogImportProfile[] }>('/eshop/imports/profiles', { signal })
      .then(response => response.data.items)
  ),
  createProfile: (name: string, config: CatalogImportConfig, signal?: AbortSignal) => (
    api.post<CatalogImportProfile>('/eshop/imports/profiles', { name, config }, { signal })
      .then(response => response.data)
  ),
  updateProfile: (
    id: number,
    name: string,
    config: CatalogImportConfig,
    version: number,
    signal?: AbortSignal,
  ) => (
    api.put<CatalogImportProfile>(`/eshop/imports/profiles/${id}`, { name, config, version }, { signal })
      .then(response => response.data)
  ),
  preview: (sourceId: number, config: CatalogImportConfig, signal?: AbortSignal) => (
    api.post<CatalogJob>('/eshop/imports/preview', { source_id: sourceId, config }, { signal })
      .then(response => response.data)
  ),
  apply: (id: number, signal?: AbortSignal) => (
    api.post<CatalogJob>(`/eshop/imports/${id}/apply`, undefined, { signal })
      .then(response => response.data)
  ),
  items: (id: number, page = 1, signal?: AbortSignal) => (
    api.get<CatalogImportItems>(`/eshop/jobs/${id}/items`, {
      params: { page, limit: 50 },
      signal,
    }).then(response => response.data)
  ),
}

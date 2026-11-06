import { api } from './client'
import { downloadApiFile } from '@/utils/downloadFile'
import type { CatalogJob } from './catalogJobs'
import type { OpeningImportConfig, OpeningImportJobItems, OpeningImportSource } from './openingStockImport'

export type HistoryImportField =
  | 'doc_number' | 'doc_type' | 'doc_date' | 'warehouse' | 'sku' | 'quantity'
  | 'warehouse_to' | 'unit_cost' | 'partner' | 'description' | 'invoice_number' | 'purchase_invoice_number' | 'note'

export type HistoryImportType = 'receipt' | 'issue' | 'transfer' | 'surplus' | 'shortage'

export interface HistoryImportSample {
  source: OpeningImportSource
  header: string[]
  rows: string[][]
  fields: HistoryImportField[]
  optional_fields: HistoryImportField[]
}

export interface HistoryImportConfig {
  source_key: string
  /** Datum přechodu (D); null = historie je živý stav (firma bez skladových dokladů). */
  cutover_date: string | null
  mapping: Partial<Record<HistoryImportField, string>>
  number_prefixes: Partial<Record<HistoryImportType, string>>
  fill_negative: boolean
  reader: OpeningImportConfig['reader']
}

export interface HistoryImportNegative {
  source_row: number
  doc_number: string
  doc_date: string
  warehouse: string
  sku: string
  missing_quantity: string
  unit_cost: string
}

/** Souhrn náhledu (report úlohy stock_history_import_stage). */
export interface HistoryImportUnlinked {
  source_row: number
  doc_number: string
  invoice_number: string
  /** missing: faktura od náhledu zmizela (jen při převzetí). */
  reason: 'draft' | 'type' | 'own_stock' | 'missing'
}

export interface HistoryImportReport {
  cutover_date?: string | null
  fill_negative?: boolean
  documents?: Record<HistoryImportType, number>
  lines?: number
  date_from?: string | null
  date_to?: string | null
  negative_count?: number
  negatives?: HistoryImportNegative[]
  closed_period_documents?: number
  /** Doklady s číslem faktury, které se na ni nenavážou (převezmou se bez vazby). */
  invoice_unlinked_count?: number
  invoice_unlinked?: HistoryImportUnlinked[]
  /** Vazby vynechané až při převzetí (faktura se od náhledu změnila). */
  invoice_unlinked_on_apply?: number
  invoice_unlinked_on_apply_list?: HistoryImportUnlinked[]
  documents_created?: number
  fill_documents?: number
  documents_deleted?: number
}

export interface HistoryImportStatus {
  /** 9999-12-31 = převzetí nebo vrácení bez data přechodu právě zamyká celý sklad. */
  locked_until: string | null
  first_document_date: string | null
  active: CatalogJob | null
  /** Převzatá historie bez úlohy (např. po obnově z archivu): id pro vrácení, 0 = jen zámek. */
  orphan_job_id: number | null
  max_rows: number
}

export interface HistoryImportEndStateRow {
  warehouse_code: string
  sku: string
  name: string
  unit: string
  history_qty: string
  /** Stav v MyÚčtu k datu přechodu; null bez data přechodu. */
  stock_qty: string | null
  difference: string | null
}

export interface HistoryImportEndState {
  cutover_date: string | null
  items: HistoryImportEndStateRow[]
  totals: { count: number, differences: number }
}

export const stockHistoryImportApi = {
  status: () => api.get<HistoryImportStatus>('/stock/history-import').then(r => r.data),
  upload: (file: File) => {
    const data = new FormData()
    data.append('file', file)
    return api.post<OpeningImportSource>('/stock/history-import/sources', data, {
      headers: { 'Content-Type': 'multipart/form-data' },
    }).then(r => r.data)
  },
  sample: (id: number, reader: HistoryImportConfig['reader']) =>
    api.get<HistoryImportSample>(`/stock/history-import/sources/${id}/sample`, { params: reader }).then(r => r.data),
  preview: (sourceId: number, config: HistoryImportConfig) =>
    api.post<CatalogJob>('/stock/history-import/preview', { source_id: sourceId, config }).then(r => r.data),
  apply: (previewId: number, confirmClosedPeriods: boolean) =>
    api.post<CatalogJob>(`/stock/history-import/${previewId}/apply`, { confirm_closed_periods: confirmClosedPeriods }).then(r => r.data),
  rollback: (applyId: number, confirmClosedPeriods = false) =>
    api.post<CatalogJob>(`/stock/history-import/${applyId}/rollback`, { confirm_closed_periods: confirmClosedPeriods }).then(r => r.data),
  endState: (previewId: number) => api.get<HistoryImportEndState>(`/stock/history-import/jobs/${previewId}/end-state`).then(r => r.data),
  downloadEndState: (previewId: number) =>
    downloadApiFile(`/stock/history-import/jobs/${previewId}/end-state?format=xlsx`, 'koncovy-stav-historie.xlsx'),
  job: (id: number) => api.get<CatalogJob>(`/stock/history-import/jobs/${id}`).then(r => r.data),
  items: (id: number, page = 1) => api.get<OpeningImportJobItems>(`/stock/history-import/jobs/${id}/items`, { params: { page, limit: 50 } }).then(r => r.data),
  retry: (id: number) => api.post<CatalogJob>(`/stock/history-import/jobs/${id}/retry`).then(r => r.data),
  cancel: (id: number) => api.post<CatalogJob>(`/stock/history-import/jobs/${id}/cancel`).then(r => r.data),
}

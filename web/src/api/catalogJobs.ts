import { api } from './client'

export type CatalogJobStatus = 'queued' | 'running' | 'completed' | 'failed' | 'cancelled'

export interface CatalogJob {
  apply_job_id?: number | null
  id: number
  supplier_id: number
  stock_take_id?: number | null
  kind: string
  input_version: number | null
  status: CatalogJobStatus
  checkpoint: number | null
  total: number | null
  report: Record<string, unknown> | null
  error_code: string | null
  cancel_requested: boolean
  created_at: string
  updated_at: string
  finished_at: string | null
  attempts: number
  currencies?: string[]
}

export interface ValuationJobResult {
  job_id: number
  date: string
  items: Array<{ warehouse_id: number; warehouse_code: string; warehouse_name: string; stock_item_id: number; sku: string; name: string; unit: string; qty: string; value_total: string }>
  totals: { value_total: string; count: number }
  pagination: { page: number; limit: number; total: number; pages: number }
  source_versions: Record<string, number>
}

export const catalogJobsApi = {
  list: (params: { before_id?: number; limit?: number } = {}) => api.get<CatalogJob[]>('/eshop/jobs', { params }).then(r => r.data),
  get: (id: number, signal?: AbortSignal) => api.get<CatalogJob>(`/eshop/jobs/${id}`, { signal }).then(r => r.data),
  retry: (id: number, signal?: AbortSignal) => api.post<CatalogJob>(`/eshop/jobs/${id}/retry`, undefined, { signal }).then(r => r.data),
  cancel: (id: number, signal?: AbortSignal) => api.post<CatalogJob>(`/eshop/jobs/${id}/cancel`, undefined, { signal }).then(r => r.data),
  recomputePrices: () => api.post<CatalogJob>('/eshop/jobs/prices-recompute').then(r => r.data),
  createValuation: (payload: { date: string; warehouse_id?: number }) => api.post<CatalogJob>('/stock/reports/valuation-jobs', payload).then(r => r.data),
  getValuation: (id: number, params: { page?: number; limit?: number } = {}) => api.get<ValuationJobResult>(`/stock/reports/valuation-jobs/${id}`, { params }).then(r => r.data),
  getValuationStatus: (id: number) => api.get<CatalogJob>(`/stock/reports/valuation-jobs/${id}/status`).then(r => r.data),
  cancelValuation: (id: number) => api.post<CatalogJob>(`/stock/reports/valuation-jobs/${id}/cancel`).then(r => r.data),
  cancelTakePreparation: (id: number) => api.post(`/stock/takes/${id}/cancel-preparation`).then(r => r.data),
  retryTakePreparation: (id: number) => api.post(`/stock/takes/${id}/retry-preparation`).then(r => r.data),
}

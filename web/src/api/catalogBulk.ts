import { api } from './client'
import type { StockItemAttributeFilter, StockItemType } from './stock'
import type { CatalogJob } from './catalogJobs'

export interface CatalogBulkFilters {
  type?: StockItemType
  active?: boolean
  q?: string
  only_below_min?: boolean
  warehouse_id?: number
  manufacturer_id?: number
  category_id?: number
  vendor_id?: number
  tag_ids?: number[]
  attribute_filters?: StockItemAttributeFilter[]
  missing?: Array<'manufacturer' | 'category' | 'image' | 'price' | 'ean'>
  availability?: 'in_stock' | 'out_of_stock' | 'below_min'
  qty_min?: string | number
  qty_max?: string | number
}

export type CatalogBulkSelection =
  | { all_matching: false; ids: number[] }
  | { all_matching: true; filters: CatalogBulkFilters; excluded_ids: number[] }

/** Omitted values stay unchanged. Empty category/tag lists explicitly remove all assignments. */
export interface CatalogBulkChanges {
  manufacturer_id?: number | null
  category_ids?: number[]
  tag_ids?: number[]
  is_active?: boolean
  export_eshop?: boolean
  min_qty?: string
}

export interface CatalogBulkJobItems {
  items: Array<{
    ordinal: number
    stock_item_id: number
    status: CatalogBulkItemStatus
    before: CatalogBulkItemState | null
    after: CatalogBulkItemState | null
    error_code: string | null
  }>
  pagination?: { page: number; limit: number; total: number; pages: number }
}

export interface CatalogBulkItemState {
  id: number
  sku: string
  name: string
  row_version: number
  manufacturer_id: number | null
  category_ids: number[]
  categories: CatalogBulkCategoryState[]
  tag_ids: number[]
  is_active: boolean
  export_eshop: boolean
  min_qty: string | null
}

export interface CatalogBulkCategoryState {
  category_id: number
  is_primary: boolean
  display_order: number
}

export type CatalogBulkItemStatus = 'pending' | 'ready' | 'applied' | 'unchanged' | 'failed' | 'conflict' | 'skipped'

export const CATALOG_BULK_ITEM_STATUSES: readonly CatalogBulkItemStatus[] = [
  'pending', 'ready', 'applied', 'unchanged', 'failed', 'conflict', 'skipped',
]

export const CATALOG_BULK_ERROR_CODES = [
  'unavailable', 'version_conflict', 'manufacturer_invalid', 'category_invalid', 'tag_invalid', 'write_failed',
] as const

export type CatalogBulkErrorCode = typeof CATALOG_BULK_ERROR_CODES[number]

export const catalogBulkApi = {
  preview: (selection: CatalogBulkSelection, changes: CatalogBulkChanges) => api.post<CatalogJob>('/eshop/bulk/preview', { selection, changes }).then(r => r.data),
  apply: (id: number) => api.post<CatalogJob>(`/eshop/bulk/${id}/apply`).then(r => r.data),
  restore: (id: number) => api.post<CatalogJob>(`/eshop/bulk/${id}/restore`).then(r => r.data),
  items: (id: number, params: { page?: number; limit?: number; status?: CatalogBulkItemStatus } = {}) => api.get<CatalogBulkJobItems>(`/eshop/jobs/${id}/items`, { params }).then(r => r.data),
}

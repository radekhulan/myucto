import { api } from './client'
import type { CatalogJob } from './catalogJobs'
import type { Product, ProductI18nRow } from './eshop'

export type ProductMasterStatus = 'active' | 'archived'
export type ProductRelationType = 'accessory' | 'replacement' | 'related'
export type InheritableI18nField = 'name' | 'short_desc' | 'description' | 'seo_title' | 'seo_description'

export interface ProductMasterI18nRow extends Omit<ProductI18nRow, 'seo_slug'> {}

export interface ProductMasterListItem {
  id: number
  name: string
  manufacturer_id: number | null
  status: ProductMasterStatus
  row_version: number
  variant_count: number
  created_at: string
  updated_at: string
}

export interface ProductMasterAxis {
  attribute_id: number
  code: string
  name: string
  display_order?: number
}

export type VariantI18nInheritance = Record<string, Record<InheritableI18nField, boolean>>

export interface ProductVariantInheritance {
  manufacturer: boolean
  i18n: VariantI18nInheritance
}

export interface ProductMasterVariant {
  stock_item_id: number
  sku: string
  own_name: string
  effective_name: string
  row_version: number
  link_row_version: number
  option_signature: string
  options: Array<{ attribute_id: number; option_id: number }>
  inheritance: ProductVariantInheritance
  effective?: {
    manufacturer_id: number | null
    i18n: ProductMasterI18nRow[]
  }
}

export interface ProductMaster extends ProductMasterListItem {
  supplier_id: number
  i18n: ProductMasterI18nRow[]
  axes: ProductMasterAxis[]
  variants: ProductMasterVariant[]
}

export interface ProductMasterPayload {
  name: string
  manufacturer_id: number | null
  i18n: ProductMasterI18nRow[]
  axis_attribute_ids: number[]
}

export interface ProductMasterPage {
  items: ProductMasterListItem[]
  pagination: { page: number; limit: number; total: number; pages: number }
}

export interface ProductVariantContext {
  master_id: number
  master_name?: string
  master_status?: ProductMasterStatus
  master_row_version?: number
  link_row_version: number
  inheritance: ProductVariantInheritance
  effective?: {
    manufacturer_id: number | null
    i18n: ProductMasterI18nRow[]
  }
  options?: Array<{ attribute_id: number; option_id: number }>
}

export interface ProductWithMasterContext extends Product {
  master?: ProductMasterListItem | null
  variant?: ProductVariantContext | null
  effective?: {
    manufacturer_id: number | null
    i18n: ProductMasterI18nRow[]
  } | null
}

export interface ProductRelation {
  type: ProductRelationType
  target_stock_item_id: number
  target_sku: string
  target_name: string
  display_order: number
}

export interface ProductRelations {
  row_version: number
  items: ProductRelation[]
}

export interface ContentTransferPreviewItem {
  job_id?: number
  ordinal?: number
  stock_item_id: number
  sku?: string
  name?: string
  status: 'pending' | 'ready' | 'applied' | 'unchanged' | 'conflict' | 'failed' | 'skipped'
  source_row?: { sku?: string; name?: string }
  before: Record<string, unknown> | null
  after: Record<string, unknown> | null
  expected_version: number
  message?: string
}

export interface ProductDetachPreview {
  master_id: number
  stock_item_id: number
  row_version: number
  link_row_version: number
  materialized: {
    manufacturer_id: number | null
    i18n: ProductMasterI18nRow[]
  }
}

export interface ContentTransferReport {
  items?: ContentTransferPreviewItem[]
  counts?: Record<string, number>
  [key: string]: unknown
}

function params(input: Record<string, string | number | undefined>) {
  return Object.fromEntries(Object.entries(input).filter(([, value]) => value !== undefined && value !== ''))
}

export const productMastersApi = {
  list: (filters: { status?: 'active' | 'archived' | 'all'; query?: string; page?: number; limit?: number } = {}, signal?: AbortSignal) =>
    api.get<ProductMasterPage>('/eshop/product-masters', { params: params(filters), signal }).then(r => r.data),
  get: (id: number, signal?: AbortSignal) =>
    api.get<ProductMaster>(`/eshop/product-masters/${id}`, { signal }).then(r => r.data),
  create: (payload: ProductMasterPayload, signal?: AbortSignal) =>
    api.post<ProductMaster>('/eshop/product-masters', payload, { signal }).then(r => r.data),
  update: (id: number, payload: ProductMasterPayload & { row_version: number }, signal?: AbortSignal) =>
    api.put<ProductMaster>(`/eshop/product-masters/${id}`, payload, { signal }).then(r => r.data),
  archive: (id: number, rowVersion: number, signal?: AbortSignal) =>
    api.post<ProductMaster>(`/eshop/product-masters/${id}/archive`, { row_version: rowVersion }, { signal }).then(r => r.data),
  restore: (id: number, rowVersion: number, signal?: AbortSignal) =>
    api.post<ProductMaster>(`/eshop/product-masters/${id}/restore`, { row_version: rowVersion }, { signal }).then(r => r.data),
  attachVariants: (id: number, payload: {
    master_row_version: number
    variants: Array<{
      stock_item_id: number
      row_version: number
      options: Array<{ attribute_id: number; option_id: number }>
      inheritance?: ProductVariantInheritance
    }>
  }, signal?: AbortSignal) => api.post<ProductMaster>(`/eshop/product-masters/${id}/variants`, payload, { signal }).then(r => r.data),
  updateVariant: (id: number, stockItemId: number, payload: {
    link_row_version: number
    row_version: number
    options?: Array<{ attribute_id: number; option_id: number }>
    inheritance?: ProductVariantInheritance
  }, signal?: AbortSignal) => api.put<ProductMaster>(`/eshop/product-masters/${id}/variants/${stockItemId}`, payload, { signal }).then(r => r.data),
  previewDetach: (id: number, stockItemId: number, signal?: AbortSignal) =>
    api.get<ProductDetachPreview>(`/eshop/product-masters/${id}/variants/${stockItemId}/detach-preview`, { signal }).then(r => r.data),
  detachVariant: (id: number, stockItemId: number, payload: { link_row_version: number; row_version: number }, signal?: AbortSignal) =>
    api.post<ProductDetachPreview>(`/eshop/product-masters/${id}/variants/${stockItemId}/detach`, payload, { signal }).then(r => r.data),
  previewContentTransfer: (id: number, payload: {
    master_row_version: number
    stock_item_ids: number[]
    fields: string[]
    overwrite: boolean
  }, signal?: AbortSignal) => api.post<CatalogJob>(`/eshop/product-masters/${id}/content-transfer/preview`, payload, { signal }).then(r => r.data),
  applyContentTransfer: (id: number, previewJobId: number, signal?: AbortSignal) =>
    api.post<CatalogJob>(`/eshop/product-masters/${id}/content-transfer/apply`, { preview_job_id: previewJobId }, { signal }).then(r => r.data),
  getContentTransferItems: (jobId: number, filters: { page?: number; limit?: number; status?: string } = {}, signal?: AbortSignal) =>
    api.get<{ items: ContentTransferPreviewItem[]; pagination: { page: number; limit: number; total: number; pages: number } }>(`/eshop/jobs/${jobId}/items`, { params: filters, signal }).then(r => r.data),
  getProductContext: (id: number, signal?: AbortSignal) =>
    api.get<ProductWithMasterContext>(`/eshop/products/${id}`, { signal }).then(r => r.data),
  getRelations: (id: number, signal?: AbortSignal) =>
    api.get<ProductRelations>(`/eshop/products/${id}/relations`, { signal }).then(r => r.data),
  updateRelations: (id: number, payload: { row_version: number; items: Array<Pick<ProductRelation, 'type' | 'target_stock_item_id' | 'display_order'>> }, signal?: AbortSignal) =>
    api.put<ProductRelations>(`/eshop/products/${id}/relations`, payload, { signal }).then(r => r.data),
}

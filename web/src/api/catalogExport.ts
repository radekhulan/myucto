import { api } from './client'
import type { CatalogBulkSelection } from './catalogBulk'
import type { CatalogJob } from './catalogJobs'

export const CATALOG_EXPORT_FIELDS = [
  'sku',
  'name',
  'ean',
  'item_type',
  'unit',
  'manufacturer_id',
  'vat_rate_id',
  'is_active',
  'is_stocked',
  'export_eshop',
  'min_qty',
  'weight_g',
  'warranty_months',
  'delivery_days',
  'i18n',
  'categories',
  'tag_ids',
  'attributes',
  'fees',
  'media',
  'prices',
  'availability',
] as const

export type CatalogExportField = typeof CATALOG_EXPORT_FIELDS[number]

export interface CatalogExportProjection {
  fields: CatalogExportField[]
  locales: string[]
  currencies: string[]
  warehouse_ids?: number[]
}

export interface CatalogExportRequest {
  selection: CatalogBulkSelection
  projection: CatalogExportProjection
}

export const catalogExportApi = {
  create: (payload: CatalogExportRequest) => api.post<CatalogJob>('/catalog/exports', payload).then(r => r.data),
  downloadUrl: (id: number, supplierId: number) => `/api/catalog/exports/${id}/download?supplier_id=${supplierId}`,
}

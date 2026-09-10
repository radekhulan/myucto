import { api } from './client'
import type { CatalogJob } from './catalogJobs'

export type SalesOrderCommercialStatus = 'draft' | 'confirmed' | 'cancelled' | 'completed'
export type SalesOrderPaymentStatus = 'unpaid' | 'authorized' | 'partially_paid' | 'paid' | 'refunded' | 'partially_refunded'
export type SalesOrderFulfillmentStatus = 'unfulfilled' | 'partially_reserved' | 'reserved' | 'partially_fulfilled' | 'fulfilled' | 'cancelled'

export interface SalesOrderLine {
  id: number
  line_uuid: string
  description: string
  stock_item_id: number | null
  warehouse_id: number | null
  sku_snapshot: string | null
  unit: string
  quantity: string
  unit_price: string
  discount_percent: string
  vat_rate_id: number
  vat_rate_snapshot: string
  total_without_vat: string
  total_vat: string
  total_with_vat: string
  product_snapshot: Record<string, unknown>
  component_snapshot: Array<Record<string, unknown>>
}

export interface SalesOrderReservation {
  id: number
  source_line_id: string
  stock_item_id: number
  warehouse_id: number
  qty_reserved: string
  qty_consumed: string
  qty_released: string
  remaining_qty: string
  status: string
}

export interface SalesOrder {
  id: number
  order_uuid: string
  order_number: string
  client_id: number
  client_name: string
  commercial_status: SalesOrderCommercialStatus
  payment_status: SalesOrderPaymentStatus
  fulfillment_status: SalesOrderFulfillmentStatus
  allocation_policy: 'all_or_nothing' | 'partial'
  currency_id: number
  currency_code: string
  exchange_rate: string | null
  prices_include_vat: boolean
  total_without_vat: string
  total_vat: string
  total_with_vat: string
  reservation_expires_at: string | null
  row_version: number
  invoice_id: number | null
  fulfillment_task_id?: number | null
  created_at: string
  lines?: SalesOrderLine[]
  reservations?: SalesOrderReservation[]
  returns?: Array<Record<string, unknown>>
}

export interface SalesOrderPayload {
  client_id: number
  currency_id: number
  order_number?: string
  allocation_policy: 'all_or_nothing' | 'partial'
  prices_include_vat: boolean
  exchange_rate?: string | null
  reservation_expires_at?: string | null
  row_version?: number
  lines: Array<{
    line_uuid?: string
    stock_item_id?: number | null
    warehouse_id?: number | null
    description?: string
    unit?: string
    quantity: string
    unit_price: string
    discount_percent?: string
    vat_rate_id: number
  }>
}

export interface SalesOrderListResponse { items: SalesOrder[]; total: number; limit: number; offset: number }

function operationKey(prefix: string): string {
  return `${prefix}:${crypto.randomUUID()}`
}

export const salesOrdersApi = {
  list: (params: Record<string, string | number> = {}) =>
    api.get<SalesOrderListResponse>('/stock/sales-orders', { params }).then(r => r.data),
  shortages: () => api.get<SalesOrderListResponse>('/stock/sales-orders/shortages').then(r => r.data),
  get: (id: number) => api.get<SalesOrder>(`/stock/sales-orders/${id}`).then(r => r.data),
  create: (payload: SalesOrderPayload) => api.post<SalesOrder>('/stock/sales-orders', payload).then(r => r.data),
  update: (id: number, payload: SalesOrderPayload) => api.put<SalesOrder>(`/stock/sales-orders/${id}`, payload).then(r => r.data),
  confirm: (id: number) => api.post<SalesOrder>(`/stock/sales-orders/${id}/confirm`, {}, { headers: { 'Idempotency-Key': operationKey('confirm') } }).then(r => r.data),
  reserveRemainder: (id: number) => api.post<SalesOrder>(`/stock/sales-orders/${id}/confirm`, {}, { headers: { 'Idempotency-Key': operationKey('reserve-remainder') } }).then(r => r.data),
  cancel: (id: number) => api.post<SalesOrder>(`/stock/sales-orders/${id}/cancel`, {}, { headers: { 'Idempotency-Key': operationKey('cancel') } }).then(r => r.data),
  payment: (id: number, payment_status: SalesOrderPaymentStatus, row_version: number) =>
    api.post<SalesOrder>(`/stock/sales-orders/${id}/payment-status`, { payment_status, row_version }).then(r => r.data),
  invoice: (id: number) => api.post<{ id: number }>(`/stock/sales-orders/${id}/invoice`, {}, { headers: { 'Idempotency-Key': operationKey('invoice') } }).then(r => r.data),
  enqueueExpiry: () => api.post<CatalogJob>('/stock/sales-orders/expiry-jobs').then(r => r.data),
  expiryJob: (id: number) => api.get<CatalogJob>(`/stock/sales-orders/expiry-jobs/${id}`).then(r => r.data),
  runExpiryBatch: () => api.post<CatalogJob | { status: 'idle' }>('/stock/sales-orders/expiry-jobs/run').then(r => r.data),
}

import { api } from './client'
import { requestSupplierId } from '@/workspace/supplierContext'

export const quoteStatuses = ['draft', 'sent', 'accepted', 'declined', 'expired', 'withdrawn'] as const
export type QuoteStatus = typeof quoteStatuses[number]
export type QuoteStateAction = Extract<QuoteStatus, 'accepted' | 'declined' | 'withdrawn'> | 'restore'
export const quoteSectionKinds = ['required', 'optional', 'info'] as const
export type QuoteSectionKind = typeof quoteSectionKinds[number]
export const quoteItemKinds = ['product', 'work', 'material'] as const
export type QuoteItemKind = typeof quoteItemKinds[number]
export const quoteFileModes = ['inline', 'append', 'email', 'internal'] as const
export type QuoteFileMode = typeof quoteFileModes[number]
export const quoteHistoryKinds = ['note', 'email', 'call'] as const
export type QuoteHistoryKind = typeof quoteHistoryKinds[number]
export type QuoteInvoiceType = 'invoice' | 'proforma'
type Decimal = string | number

export interface QuoteTotals { without_vat: Decimal; vat: Decimal; with_vat: Decimal }

export interface QuoteItem {
  id: string
  kind: QuoteItemKind
  description: string
  quantity: Decimal
  unit: string
  unit_price: Decimal
  discount_percent: Decimal
  vat_rate: Decimal
  vat_rate_id?: number | null
  stock_item_id?: number | null
  warehouse_id?: number | null
  duration_minutes?: number | null
  totals?: QuoteTotals
  billed_quantity?: Decimal
  draft_quantity?: Decimal
  remaining_quantity?: Decimal
}

export interface QuoteSection {
  id: string
  title: string
  kind: QuoteSectionKind
  markdown: string
  items: QuoteItem[]
  totals?: QuoteTotals
}

export interface QuoteFile { id: number; name: string; mime_type: string; mode: QuoteFileMode }
export interface QuoteHistory {
  id: number
  event: string
  created_at: string
  user_name?: string | null
  data?: Record<string, unknown>
  has_pdf?: boolean | number
  version_id?: number
  delivery_status?: string | null
}
export interface QuoteDocument { invoice_id: number; type: string; status: string; number?: string | null; amount?: Decimal; paid_total?: Decimal }
export interface QuoteBilling { billed: Decimal; draft: Decimal; remaining: Decimal; advance_requested: Decimal; advance_paid: Decimal }

export interface QuotePayload {
  title: string
  client_id: number | null
  project_id: number | null
  currency_id: number | null
  language: 'cs' | 'en'
  prices_include_vat: boolean
  valid_until: string
  sections: QuoteSection[]
  row_version?: number
}

export interface QuoteListItem {
  id: number
  number: string
  status: QuoteStatus
  revision: number
  title: string
  client_id: number
  client_name: string
  project_id: number | null
  project_name?: string | null
  currency: string
  valid_until: string
  totals: QuoteTotals
  billing?: QuoteBilling | null
}

export interface SalesQuote extends QuoteListItem {
  currency_id: number
  language: 'cs' | 'en'
  prices_include_vat: boolean
  version_id: number
  current_version_id?: number
  row_version: number
  sections: QuoteSection[]
  accepted_section_ids: string[]
  files: QuoteFile[]
  history: QuoteHistory[]
  documents: QuoteDocument[]
  billing: QuoteBilling | null
  revisions: Array<{ id: number; revision: number; created_at: string; status?: QuoteStatus }>
  order_id?: number | null
  client_snapshot?: { main_email?: string | null }
  reverse_charge?: boolean
  send_pending?: boolean
  mail_defaults?: { to: string; subject: string; message: string }
}

export interface QuoteListFilters {
  page?: number
  per_page?: number
  search?: string
  status?: QuoteStatus
  project_id?: number
  client_id?: number
}

export interface QuoteInvoiceSelection { id: string; quantity: Decimal }

function downloadUrl(path: string, versionId?: number, download = false): string {
  const query = new URLSearchParams()
  const supplier = requestSupplierId()
  if (supplier && /^\d+$/.test(supplier)) query.set('supplier_id', supplier)
  if (versionId) query.set('version_id', String(versionId))
  if (download) query.set('download', '1')
  return `/api${path}${query.size ? '?' + query.toString() : ''}`
}

export const salesQuotesApi = {
  list: (params: QuoteListFilters = {}) =>
    api.get<{ items: QuoteListItem[]; total: number }>('/sales-quotes', { params }).then(r => r.data),
  get: (id: number, versionId?: number) =>
    api.get<SalesQuote>(`/sales-quotes/${id}`, { params: { version_id: versionId } }).then(r => r.data),
  create: (payload: QuotePayload) => api.post<SalesQuote>('/sales-quotes', payload).then(r => r.data),
  update: (id: number, payload: QuotePayload) => api.put<SalesQuote>(`/sales-quotes/${id}`, payload).then(r => r.data),
  revise: (id: number) => api.post<SalesQuote>(`/sales-quotes/${id}/revise`, {}).then(r => r.data),
  setState: (id: number, status: QuoteStateAction, optionalSectionIds: string[], note: string, rowVersion: number) =>
    api.post<SalesQuote>(`/sales-quotes/${id}/state`, { status, optional_section_ids: optionalSectionIds, note, row_version: rowVersion }).then(r => r.data),
  addHistory: (id: number, kind: QuoteHistoryKind, text: string) =>
    api.post<SalesQuote>(`/sales-quotes/${id}/history`, { kind, text }).then(r => r.data),
  upload: (id: number, file: File, mode: QuoteFileMode) => {
    const body = new FormData()
    body.append('file', file, file.name)
    body.append('mode', mode)
    return api.post<SalesQuote>(`/sales-quotes/${id}/files`, body, {
      headers: { 'Content-Type': 'multipart/form-data' },
    }).then(r => r.data)
  },
  removeFile: (id: number, fileId: number) =>
    api.delete<SalesQuote>(`/sales-quotes/${id}/files/${fileId}`).then(r => r.data),
  fileUrl: (id: number, fileId: number, versionId?: number, download = false) =>
    downloadUrl(`/sales-quotes/${id}/files/${fileId}`, versionId, download),
  pdfUrl: (id: number, versionId?: number, download = false) =>
    downloadUrl(`/sales-quotes/${id}/pdf`, versionId, download),
  historyFileUrl: (id: number, eventId: number, fileId: number) =>
    downloadUrl(`/sales-quotes/${id}/history/${eventId}/files/${fileId}`, undefined, true),
  historyPdfUrl: (id: number, eventId: number) =>
    downloadUrl(`/sales-quotes/${id}/history/${eventId}/pdf`),
  send: (id: number, payload: { to: string; subject: string; message: string; idempotency_key: string; row_version: number }) =>
    api.post<SalesQuote>(`/sales-quotes/${id}/send`, payload).then(r => r.data),
  invoice: (id: number, payload: { type: QuoteInvoiceType; items: QuoteInvoiceSelection[]; idempotency_key: string; advance_percent?: number; advance_amount?: number }) =>
    api.post<{ invoice_id: number }>(`/sales-quotes/${id}/invoice`, payload).then(r => r.data),
  order: (id: number, idempotencyKey: string) =>
    api.post<{ order_id: number }>(`/sales-quotes/${id}/order`, { idempotency_key: idempotencyKey }).then(r => r.data),
}

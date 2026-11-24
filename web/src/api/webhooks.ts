import { api } from './client'

export const webhookEventTypes = [
  'invoice.issued',
  'invoice.paid',
  'invoice.payment_reversed',
  'invoice.published',
  'invoice.cancelled',
  'purchase_invoice.received',
  'purchase_invoice.approved',
  'purchase_invoice.cancelled',
  'stock_document.posted',
  'stock_document.reversed',
  'sales_order.confirmed',
] as const

export type WebhookEventType = typeof webhookEventTypes[number]

export interface WebhookEndpointInput {
  name: string
  url: string
  enabled: boolean
  event_types: WebhookEventType[]
  template_id: number | null
  include_advance: boolean
}

export interface WebhookEndpoint extends WebhookEndpointInput {
  id: number
}

export interface WebhookConfiguration {
  available: boolean
  policy: 'public' | 'allowlist' | 'disabled'
  endpoints: WebhookEndpoint[]
}

export interface WebhookDelivery {
  id: number
  event_id: string
  event_type: string
  status: 'pending' | 'processing' | 'delivered' | 'failed' | 'cancelled'
  attempts: number
  http_status: number | null
  last_error_code: string | null
  created_at: string
  next_attempt_at: string | null
}

const base = '/admin/webhooks'

export const webhooksApi = {
  list: () => api.get<WebhookConfiguration>(base).then(r => r.data),
  create: (body: WebhookEndpointInput) =>
    api.post<{ id: number; secret: string }>(base, body).then(r => r.data),
  update: (id: number, body: Partial<Omit<WebhookEndpointInput, 'url'>>) =>
    api.patch<{ id: number }>(`${base}/${id}`, body).then(r => r.data),
  remove: (id: number) => api.delete<{ deleted: true }>(`${base}/${id}`).then(r => r.data),
  rotate: (id: number) => api.post<{ secret: string }>(`${base}/${id}/rotate`).then(r => r.data),
  test: (id: number) => api.post<{ event_id: string }>(`${base}/${id}/test`).then(r => r.data),
  deliveries: (id: number, before?: number) =>
    api.get<WebhookDelivery[]>(`${base}/${id}/deliveries`, { params: { before } }).then(r => r.data),
  retry: (id: number, deliveryId: number) =>
    api.post<{ retried: true }>(`${base}/${id}/deliveries/${deliveryId}/retry`).then(r => r.data),
}

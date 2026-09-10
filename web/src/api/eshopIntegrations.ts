import { api } from './client'
import type { CatalogJob } from './catalogJobs'

export type IntegrationStatus = 'draft' | 'active' | 'paused' | 'error'

export interface IntegrationConnection {
  id: number
  connection_uuid: string
  supplier_id: number
  connector_key: string
  name: string
  status: IntegrationStatus
  mappings: Record<string, unknown>
  field_ownership: Record<string, 'local' | 'remote' | 'manual'>
  credentials_configured: boolean
  webhook_configured: boolean
  rate_limit_per_minute: number
  retention_days: number
  last_synced_at: string | null
  last_error_code: string | null
  last_error_at: string | null
  created_at: string
  updated_at: string
}

export interface IntegrationError {
  direction: 'inbox' | 'outbox'
  id: number
  entity_type: string
  entity_id: string
  event_type: string
  aggregate_version: number
  status: string
  attempts: number
  last_error_code: string | null
  payload_redacted: boolean
  occurred_at: string
}

export interface IntegrationDiagnostics {
  last_synced_at: string | null
  last_error_code: string | null
  last_error_at: string | null
  inbox: Record<string, number>
  outbox: Record<string, number>
  errors: IntegrationError[]
  jobs: CatalogJob[]
}

export interface ConnectionInput {
  connector_key: string
  name: string
  status: IntegrationStatus
  mappings: Record<string, unknown>
  field_ownership: Record<string, 'local' | 'remote' | 'manual'>
  rate_limit_per_minute: number
  retention_days: number
}

export const eshopIntegrationsApi = {
  list: () => api.get<IntegrationConnection[]>('/eshop/integrations').then(r => r.data),
  create: (input: ConnectionInput) => api.post<IntegrationConnection>('/eshop/integrations', input).then(r => r.data),
  update: (id: number, input: ConnectionInput) => api.put<IntegrationConnection>(`/eshop/integrations/${id}`, input).then(r => r.data),
  credentials: (id: number, credentials: Record<string, string>) => api.put<IntegrationConnection>(`/eshop/integrations/${id}/credentials`, { credentials }).then(r => r.data),
  rotateWebhookSecret: (id: number) => api.post<{ connection_uuid: string; secret: string }>(`/eshop/integrations/${id}/webhook-secret`).then(r => r.data),
  diagnostics: (id: number) => api.get<IntegrationDiagnostics>(`/eshop/integrations/${id}/diagnostics`).then(r => r.data),
  reconcile: (id: number) => api.post<CatalogJob>(`/eshop/integrations/${id}/reconcile`).then(r => r.data),
  retryOutbox: (id: number, eventId: number) => api.post(`/eshop/integrations/${id}/outbox/${eventId}/retry`).then(r => r.data),
}

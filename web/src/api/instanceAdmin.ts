import { api } from './client'

export interface SmtpTarget {
  id: number
  name: string
  host: string
  port: number
  encryption: 'tls' | 'ssl'
  username: string
  from_email: string
  test_recipient: string
}

export interface InstanceSmtp {
  managed: boolean
  read_only: boolean
  read_only_reason: 'managed' | 'environment' | null
  configured: boolean
  source: 'configuration' | 'approved_target'
  target_id: number | null
  password_set: boolean
  targets: SmtpTarget[]
  test_recipient: string | null
}

export interface TechnicalSupplier { supplier_id: number; name: string }
export interface TechnicalSupplierStatus extends TechnicalSupplier {
  mail_transport: 'instance' | 'supplier_profile'
  smtp_test_supported: boolean
}
export interface TechnicalAuditEntry {
  id: number
  action: string
  supplier_id: number | null
  created_at: string
  result: string
}
export interface TechnicalPage<T> { data: T[]; next_cursor: number | null }
export interface InstanceStatus {
  version: string
  php_version: string
  database: { available: boolean; version: string | null }
  extensions: Array<{ name: string; available: boolean }>
  storage: { available: boolean; free_bytes: number | null }
  managed: boolean
  smtp: { configured: boolean; read_only: boolean }
  license: { state: string; valid_until: number | null } | null
}

export const instanceAdminApi = {
  status: () => api.get<InstanceStatus>('/instance-admin/status').then(r => r.data),
  smtp: () => api.get<InstanceSmtp>('/instance-admin/smtp').then(r => r.data),
  saveSmtp: (payload: { target_id: number; password?: string }) => api.put<InstanceSmtp>('/instance-admin/smtp', payload).then(r => r.data),
  testSmtp: () => api.post<{ status: 'sent'; sent_at: string; test_recipient: string }>('/instance-admin/smtp/test', {}).then(r => r.data),
  suppliers: (afterId?: number) => api.get<TechnicalPage<TechnicalSupplier>>('/instance-admin/suppliers', { params: { after_id: afterId, limit: 50 } }).then(r => r.data),
  supplierStatus: (id: number) => api.get<TechnicalSupplierStatus>(`/instance-admin/suppliers/${id}/status`).then(r => r.data),
  testSupplierSmtp: (id: number) => api.post<{ status: 'sent'; sent_at: string; test_recipient: string }>(`/instance-admin/suppliers/${id}/smtp/test`, {}).then(r => r.data),
  audit: (afterId?: number) => api.get<TechnicalPage<TechnicalAuditEntry>>('/instance-admin/audit', { params: { after_id: afterId, limit: 50 } }).then(r => r.data),
  targets: () => api.get<SmtpTarget[]>('/admin/instance-smtp/targets').then(r => r.data),
  createTarget: (payload: Omit<SmtpTarget, 'id'>) => api.post<SmtpTarget>('/admin/instance-smtp/targets', payload).then(r => r.data),
  deleteTarget: (id: number) => api.delete(`/admin/instance-smtp/targets/${id}`),
  resetSmtpOverride: () => api.delete<InstanceSmtp>('/admin/instance-smtp/override', { data: {} }).then(r => r.data),
}

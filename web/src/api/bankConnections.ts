import { api } from './client'

export type { BankReconciliationCandidate } from '@/types/bankReconciliation'

export interface BankConnectionProvider {
  code: string
  label: string
  implemented: boolean
  bank_codes: string[]
  capabilities: { statement_import: boolean; payment_order_submission: boolean }
}

export interface BankConnection {
  id: number
  currency_id: number
  provider: string
  enabled: boolean
  has_token: boolean
  validated_at: string | null
  last_sync_at: string | null
  last_sync_status: string | null
  last_sync_error_code: string | null
  next_sync_from: string | null
  created_at: string
  updated_at: string
  account: { id: number; code: string; label: string | null; account_number: string | null; bank_code: string | null; iban: string | null }
}

export interface BankSyncResult {
  status: string
  imported_statement_id?: number | null
  import_result?: { transactions: number; matched: number; skipped_duplicates?: number; duplicate?: boolean } | null
  period: { from: string; to: string }
}

export interface BankSyncRequest {
  from?: string
  to?: string
  reconciliation_confirmations?: string[]
}

export interface BankPaymentSubmission {
  id: number
  payment_order_id: number
  connection_id: number
  status: 'accepted_awaiting_authorization' | 'rejected' | 'unknown' | 'import_started'
  provider_reference?: string | null
  accepted_count?: number | null
  rejected_count?: number | null
  submitted_at: string | null
}

export const bankConnectionsApi = {
  list: () => api.get<{ providers: BankConnectionProvider[]; connections: BankConnection[] }>('/settings/bank-connections').then(r => r.data),
  save: (currencyId: number, payload: { provider: string; enabled: boolean; token?: string; credentials?: Record<string, string> }) =>
    api.put(`/settings/bank-connections/${currencyId}`, payload).then(r => r.data),
  disconnect: (currencyId: number) => api.delete(`/settings/bank-connections/${currencyId}`),
  sync: (currencyId: number, period: BankSyncRequest) =>
    api.post<BankSyncResult>(`/settings/bank-connections/${currencyId}/sync`, period).then(r => r.data),
  submission: (orderId: number) =>
    api.get<{ submission: BankPaymentSubmission | null }>(`/purchase-invoices/payment-orders/${orderId}/submission`).then(r => r.data.submission),
  submit: (orderId: number, connectionId: number) =>
    api.post<{ submission: BankPaymentSubmission }>(`/purchase-invoices/payment-orders/${orderId}/submit`, { connection_id: connectionId }).then(r => r.data.submission),
}

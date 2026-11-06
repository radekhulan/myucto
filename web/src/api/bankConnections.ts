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

export type BankPaymentSubmissionStatus =
  | 'accepted_awaiting_authorization' | 'rejected' | 'unknown' | 'import_started'
  | 'creating' | 'awaiting_signature' | 'signed' | 'completed' | 'partially_rejected' | 'expired'

/** Stavy dávky, kterou uživatel podepisuje přesměrováním do banky (Česká spořitelna). */
export const bankSignatureStatuses: ReadonlyArray<BankPaymentSubmissionStatus> = ['awaiting_signature', 'signed']

export interface BankPaymentSubmission {
  id: number
  payment_order_id: number
  connection_id: number
  provider?: string
  status: BankPaymentSubmissionStatus
  provider_reference?: string | null
  error_code?: string | null
  accepted_count?: number | null
  rejected_count?: number | null
  submitted_at: string | null
}

/** Položka odeslání po jednotlivých platbách (ČS) ke kontrole v bankovnictví. */
export interface BankPaymentSubmissionItem {
  line_no: number
  status: 'planned' | 'sending' | 'created' | 'rejected' | 'unknown'
  amount: string
  creditor_iban: string
  creditor_account: string | null
  variable_symbol: string | null
  instruction_identification: string
  provider_sign_id: string | null
  instruction_status: string | null
}

/** Stav odeslání, u kterého může banka mít založené platby a uživatel je má zkontrolovat. */
export function needsItemReview(submission: { status: string; provider?: string } | null): boolean {
  return !!submission && submission.provider === 'csas' && ['unknown', 'creating'].includes(submission.status)
}

/** Adresa podpisu musí vést do banky (Česká spořitelna, v sandboxu portál Erste). */
export function trustedBankSigningUrl(raw: string): URL | null {
  try {
    const url = new URL(raw)
    const host = url.hostname.toLowerCase()
    const bankHost = host === 'csas.cz' || host.endsWith('.csas.cz') || host === 'webapi.developers.erstegroup.com'
    return url.protocol === 'https:' && bankHost && !url.username && !url.password && !url.port ? url : null
  } catch {
    return null
  }
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
  sign: (orderId: number) =>
    api.post<{ signing_url: string; submission: BankPaymentSubmission }>(`/purchase-invoices/payment-orders/${orderId}/submission/sign`).then(r => r.data),
  refreshFromBank: (orderId: number) =>
    api.post<{ submission: BankPaymentSubmission }>(`/purchase-invoices/payment-orders/${orderId}/submission/refresh`).then(r => r.data.submission),
  items: (orderId: number) =>
    api.get<{ items: BankPaymentSubmissionItem[] }>(`/purchase-invoices/payment-orders/${orderId}/submission/items`).then(r => r.data.items),
}

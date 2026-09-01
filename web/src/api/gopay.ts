import { api } from './client'

export interface GoPayAccountOption {
  id: number
  account_code: string
  name: string
  account_type: string
  is_synthetic: boolean
  parent_id: number | null
}

export interface GoPaySettings {
  currency: string
  gopay_account_id: number | null
  receivable_account_id: number | null
  fee_account_id: number | null
  clearing_account_id: number | null
  destination_bank_account_id: number | null
  payout_account_number: string
  payout_bank_code: string
  payout_date_tolerance_days: number
  gopay_account_code?: string
  gopay_account_name?: string
  receivable_account_code?: string
  receivable_account_name?: string
  fee_account_code?: string
  fee_account_name?: string
  clearing_account_code?: string
  clearing_account_name?: string
  destination_bank_account_code?: string
  destination_bank_account_name?: string
}

export interface GoPaySettingsResponse {
  configured: boolean
  settings: GoPaySettings
  account_options: GoPayAccountOption[]
}

export type GoPayClearingStatus = 'imported' | 'processing' | 'processed' | 'needs_review'
export type GoPayMovementStatus = 'pending' | 'posted' | 'unmatched' | 'error'

export interface GoPayClearing {
  id: number
  clearing_id: string
  account_name: string
  currency: string
  variable_symbol: string
  cleared_from: string
  cleared_to: string
  performed_on: string
  amount_gross: number
  amount_fee: number
  amount_storno: number
  amount_storno_fee: number
  amount_transfer: number
  amount_sent: number
  file_name: string
  status: GoPayClearingStatus
  movement_count: number
  posted_count: number
  issue_count: number
  payout_match_transaction_id: number | null
  bank_transaction_id: number | null
  imported_at: string
  processed_at: string | null
}

export interface GoPayMovement {
  id: number
  movement_type: 'credit' | 'storno' | 'storno_fee' | 'clearing_fee' | 'fee_credit' | 'payout'
  performed_on: string
  amount: number
  order_id: string | null
  payment_session_id: string | null
  account_movement_id: string | null
  payment_channel: string | null
  counterparty_name: string | null
  status: GoPayMovementStatus
  issue_code: string | null
  issue_message: string | null
  invoice_id: number | null
  invoice_number: string | null
  credit_note_id: number | null
  credit_note_number: string | null
  journal_entry_id: number | null
  journal_document_no: string | null
}

export interface GoPayClearingDetail extends GoPayClearing {
  has_file: boolean
  payout_issue_code: string | null
  payout_issue_message: string | null
  bank_posted_on: string | null
  bank_amount: number | null
  bank_journal_document_no: string | null
  movements: GoPayMovement[]
}

export interface GoPayImportResult {
  duplicate: boolean
  clearing: GoPayClearingDetail
}

export interface GoPayDeleteResult {
  deleted: boolean
  deleted_entry_ids: number[]
  preserved_bank_entry_id: number | null
}

export interface GoPayPayoutCandidate extends GoPayClearing {
  transaction_source: 'email_notice' | 'statement'
}

export const gopayApi = {
  settings: (currency = 'CZK') =>
    api.get<GoPaySettingsResponse>('/accounting/gopay/settings', { params: { currency } }).then(r => r.data),
  saveSettings: (settings: GoPaySettings) =>
    api.put<GoPaySettingsResponse>('/accounting/gopay/settings', settings).then(r => r.data),
  list: () =>
    api.get<{ items: GoPayClearing[] }>('/accounting/gopay/clearings').then(r => r.data.items),
  detail: (id: number) =>
    api.get<GoPayClearingDetail>(`/accounting/gopay/clearings/${id}`).then(r => r.data),
  importXml: (file: File) => {
    const body = new FormData()
    body.append('file', file)
    return api.post<GoPayImportResult>('/accounting/gopay/clearings/import', body, {
      headers: { 'Content-Type': 'multipart/form-data' },
    }).then(r => r.data)
  },
  process: (id: number) =>
    api.post<GoPayClearingDetail>(`/accounting/gopay/clearings/${id}/process`, {}).then(r => r.data),
  delete: (id: number) =>
    api.delete<GoPayDeleteResult>(`/accounting/gopay/clearings/${id}`).then(r => r.data),
  payoutCandidate: (transactionId: number) =>
    api.get<{ candidate: GoPayPayoutCandidate | null }>(`/accounting/gopay/payout-candidates/${transactionId}`)
      .then(r => r.data.candidate),
  associatePayout: (clearingId: number, transactionId: number) =>
    api.post<GoPayClearingDetail>(`/accounting/gopay/clearings/${clearingId}/payout-match`, {
      transaction_id: transactionId,
    }).then(r => r.data),
  downloadUrl: (id: number): string => {
    const base = api.defaults.baseURL ?? ''
    return `${base.replace(/\/$/, '')}/accounting/gopay/clearings/${id}/download`
  },
}

import { apiErrorCode, apiErrorMessage } from '@/api/errors'
import type { BankReconciliationCandidate } from '@/api/bankConnections'

export function bankReconciliationCandidates(error: unknown): BankReconciliationCandidate[] {
  if (apiErrorCode(error) !== 'statement_reconciliation_required') return []
  const candidates = (error as any)?.response?.data?.error?.reconciliation_candidates
  if (!Array.isArray(candidates)) return []
  return candidates.filter((candidate): candidate is BankReconciliationCandidate =>
    !!candidate
      && typeof candidate.confirmation_key === 'string'
      && /^[a-f0-9]{64}$/.test(candidate.confirmation_key)
      && typeof candidate.posted_at === 'string'
      && typeof candidate.amount === 'string'
      && typeof candidate.currency === 'string'
      && Number.isInteger(candidate.existing_transaction_id)
      && Number.isInteger(candidate.existing_statement_id)
      && typeof candidate.description === 'string'
      && typeof candidate.existing_description === 'string'
      && typeof candidate.counterparty_account === 'string'
      && typeof candidate.existing_counterparty_account === 'string'
      && typeof candidate.variable_symbol === 'string'
      && typeof candidate.existing_variable_symbol === 'string',
  )
}

export function bankConnectionErrorMessage(error: unknown, t: (key: string) => string, fallback: string): string {
  const keys: Record<string, string> = {
    certificate_invalid: 'certificate', certificate_required: 'certificate', certificate_runtime_unavailable: 'certificate_runtime',
    csob_files_pending: 'csob_pending', csob_file_failed: 'csob_pending', csob_download_timeout: 'csob_pending',
    csob_account_evidence_missing: 'csob_evidence', csob_invalid_input: 'certificate', credential_invalid: 'certificate',
    csob_soap_fault: 'csob_rejected', remote_unavailable: 'transport', remote_http_error: 'bank_http',
    invalid_response: 'bank_response', response_too_large: 'period',
    account_inactive: 'account_inactive',
    bank_dns_failed: 'dns', bank_connect_failed: 'connect', bank_timeout: 'timeout',
    bank_tls_handshake_failed: 'tls_handshake', bank_client_certificate_failed: 'client_certificate',
    bank_server_certificate_failed: 'server_certificate', bank_ca_configuration_failed: 'ca_configuration',
    bank_rate_limited: 'cooldown', rate_limited: 'cooldown', bank_connection_busy: 'busy',
    invalid_token: 'token', token_invalid: 'token', token_required: 'token',
    history_locked: 'history', history_gap: 'history',
    statement_reconciliation_required: 'reconciliation',
    encryption_key_unavailable: 'encryption', credential_unavailable: 'encryption', credential_format_invalid: 'encryption',
    statement_account_mismatch: 'account', provider_account_mismatch: 'account', account_changed_revalidation_required: 'account',
    statement_too_large: 'period', period_invalid: 'period', period_incomplete: 'period',
    payment_order_items_not_payable: 'payable', payment_order_date_expired: 'date',
  }
  const key = keys[apiErrorCode(error)]
  return key ? t(`bank_connection.error_${key}`) : apiErrorMessage(error, fallback)
}

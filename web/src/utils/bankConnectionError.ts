import { apiErrorCode, apiErrorMessage } from '@/api/errors'

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
    encryption_key_unavailable: 'encryption', credential_unavailable: 'encryption', credential_format_invalid: 'encryption',
    statement_account_mismatch: 'account', provider_account_mismatch: 'account', account_changed_revalidation_required: 'account',
    statement_too_large: 'period', period_invalid: 'period', period_incomplete: 'period',
    payment_order_items_not_payable: 'payable', payment_order_date_expired: 'date',
  }
  const key = keys[apiErrorCode(error)]
  return key ? t(`bank_connection.error_${key}`) : apiErrorMessage(error, fallback)
}

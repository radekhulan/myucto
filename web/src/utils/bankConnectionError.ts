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

/** Texty, které se u konkrétní banky liší od obecné hlášky (obecná míří na Fio token). */
const providerKeys: Record<string, Record<string, string>> = {
  kb_plus: { token: 'token_kb_plus', history: 'history_kb_plus' },
  moneta: { token: 'token_moneta', history: 'history_moneta' },
  csas: { payment_unavailable: 'csas_scope' },
}

/**
 * Doplňující text k uloženému stavu odeslání podle jeho error_code (např. vypršelý
 * podpis, ruční kontrola nejasné položky). Neznámý kód text nemá.
 */
export function bankSubmissionNote(code: string | null | undefined, t: (key: string) => string, te: (key: string) => boolean): string {
  if (!code || !/^[a-z0-9_]{1,80}$/.test(code)) return ''
  const key = `bank_connection.payment_note_${code}`
  return te(key) ? t(key) : ''
}

/** Čísla řádků duplicitních plateb z chyby `payment_order_duplicate_items` jako „1 a 3; 2 a 4“. */
function duplicateLines(error: unknown): string {
  const groups = (error as any)?.response?.data?.error?.duplicate_lines
  if (!Array.isArray(groups)) return ''
  return groups
    .filter((group): group is number[] => Array.isArray(group) && group.every(line => Number.isInteger(line) && line > 0))
    .map(group => group.join(', '))
    .join('; ')
}

export function bankConnectionErrorMessage(error: unknown, t: (key: string, params?: Record<string, unknown>) => string, fallback: string, provider = ''): string {
  if (apiErrorCode(error) === 'payment_order_duplicate_items') {
    const lines = duplicateLines(error)
    return lines ? t('bank_connection.error_duplicate_items_lines', { lines }) : t('bank_connection.error_duplicate_items')
  }
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
    payment_order_items_not_payable: 'payable', payment_order_date_expired: 'date', payment_order_no_longer_payable: 'no_longer_payable',
    payment_submission_unavailable: 'payment_unavailable',
    payment_order_not_domestic: 'not_domestic', payment_order_invalid: 'order_invalid', payment_order_changed: 'order_changed',
    payment_order_too_many_items: 'too_many_items', payment_order_account_mismatch: 'account',
    payment_execution_date_too_early: 'date', payment_execution_date_too_far: 'date_too_far',
    csas_payments_scope_missing: 'csas_scope', csas_mass_authorization_forbidden: 'csas_mass_authorization',
    payment_signature_unavailable: 'signature_unavailable', payment_signature_failed: 'signature_failed',
    payment_signature_expired: 'signature_expired', payment_status_unavailable: 'status_unavailable', app_url_invalid: 'app_url',
    kb_plus_statements_unavailable: 'kb_plus_statements', kb_plus_statement_pending: 'kb_plus_statement_pending',
    bank_sync_failed: 'server',
  }
  const key = keys[apiErrorCode(error)]
  // Bez strukturované chyby (HTML stránka IIS po timeoutu, výpadek sítě) by
  // apiErrorMessage vrátil text axiosu „Request failed with status code 500“.
  if (!key) return (error as any)?.response?.data?.error ? apiErrorMessage(error, fallback) : fallback
  return t(`bank_connection.error_${providerKeys[provider]?.[key] ?? key}`)
}

/** Záložní text načtení pohybů, když server nevrátil strukturovanou chybu. */
export function bankSyncFallback(error: unknown, t: (key: string) => string, provider = ''): string {
  if (!(error as any)?.response) return t('bank_connection.error_network')
  return t(provider === 'kb_plus' ? 'bank_connection.error_server_kb_plus' : 'bank_connection.error_server')
}

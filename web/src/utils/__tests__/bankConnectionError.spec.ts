import { describe, expect, it } from 'vitest'
import { bankConnectionErrorMessage, bankReconciliationCandidates } from '../bankConnectionError'

describe('bank connection diagnostics', () => {
  it.each([
    ['account_inactive', 'account_inactive'],
    ['csob_soap_fault', 'csob_rejected'],
    ['remote_unavailable', 'transport'],
    ['remote_http_error', 'bank_http'],
    ['invalid_response', 'bank_response'],
    ['certificate_invalid', 'certificate'],
    ['statement_reconciliation_required', 'reconciliation'],
  ])('translates %s without exposing the server response', (code, key) => {
    const error = { response: { data: { error: { code, message: 'synthetic-private-response' } } } }
    expect(bankConnectionErrorMessage(error, value => value, 'fallback')).toBe(`bank_connection.error_${key}`)
  })

  it('accepts only complete reconciliation candidates from the structured error', () => {
    const valid = {
      confirmation_key: 'a'.repeat(64), posted_at: '2026-01-12', amount: '1250.00', currency: 'CZK',
      existing_transaction_id: 901, existing_statement_id: 801,
      description: 'Syntetická platba', existing_description: 'Dřívější syntetická platba',
      counterparty_account: 'synthetic-account-a', existing_counterparty_account: 'synthetic-account-b',
      variable_symbol: '1001', existing_variable_symbol: '1002',
    }
    const error = { response: { data: { error: {
      code: 'statement_reconciliation_required',
      reconciliation_candidates: [valid, { confirmation_key: 'incomplete' }, { ...valid, confirmation_key: 'invalid' }],
    } } } }

    expect(bankReconciliationCandidates(error)).toEqual([valid])
  })
})

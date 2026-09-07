import { describe, expect, it } from 'vitest'
import { bankConnectionErrorMessage } from '../bankConnectionError'

describe('bank connection diagnostics', () => {
  it.each([
    ['account_inactive', 'account_inactive'],
    ['csob_soap_fault', 'csob_rejected'],
    ['remote_unavailable', 'transport'],
    ['remote_http_error', 'bank_http'],
    ['invalid_response', 'bank_response'],
    ['certificate_invalid', 'certificate'],
  ])('translates %s without exposing the server response', (code, key) => {
    const error = { response: { data: { error: { code, message: 'synthetic-private-response' } } } }
    expect(bankConnectionErrorMessage(error, value => value, 'fallback')).toBe(`bank_connection.error_${key}`)
  })
})

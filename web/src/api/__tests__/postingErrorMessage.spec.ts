import { describe, expect, it, vi } from 'vitest'

vi.mock('../client', () => ({ api: {} }))

import { postingErrorMessage } from '../accounting'

const t = (key: string) => `t:${key}`
const apiError = (code: string | undefined, message?: string) => ({ response: { data: { error: { code, message } } } })

/**
 * Náhled zaúčtování dřív u kódu mimo `POSTING_ERROR_CODES` ukázal jen „Doklad se nepodařilo
 * zaúčtovat." a konkrétní příčinu ze serveru zahodil (např. samovyměření s „bez nároku").
 */
describe('postingErrorMessage', () => {
  it('známý kód přeloží', () => {
    expect(postingErrorMessage(t, apiError('rc_partial_deduction_unsupported', 'server')))
      .toBe('t:accounting.posting_errors.rc_partial_deduction_unsupported')
  })

  it('neznámý kód ukáže hlášku ze serveru', () => {
    expect(postingErrorMessage(t, apiError('some_new_code', 'Konkrétní příčina.'))).toBe('Konkrétní příčina.')
  })

  it('bez hlášky spadne na obecný text', () => {
    expect(postingErrorMessage(t, apiError(undefined))).toBe('t:accounting.posting_errors.generic')
    expect(postingErrorMessage(t, new Error('network'))).toBe('t:accounting.posting_errors.generic')
  })
})

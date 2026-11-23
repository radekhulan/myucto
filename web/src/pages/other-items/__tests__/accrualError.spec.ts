import { describe, expect, it } from 'vitest'
import { ACCRUAL_ERRORS, DRAFT_ACCRUAL_ERRORS, accrualErrorKey } from '../accrualError'

const failure = (code: string) => ({ response: { data: { error: { code: `other_items.error.${code}`, message: 'Serverová hláška' } } } })

describe('accrualErrorKey', () => {
  it('přeloží jen kódy daného místa, obecné kódy jinde nechá serverové hlášce', () => {
    expect(accrualErrorKey(failure('is_draft'), ACCRUAL_ERRORS)).toBe('other_items.accrual.error.is_draft')
    expect(accrualErrorKey(failure('is_draft'), DRAFT_ACCRUAL_ERRORS)).toBeNull()
    expect(accrualErrorKey(failure('period_closed'), DRAFT_ACCRUAL_ERRORS)).toBe('other_items.accrual.error.period_closed')
    expect(accrualErrorKey(failure('has_payments'), ACCRUAL_ERRORS)).toBeNull()
    expect(accrualErrorKey(new Error('síť'), ACCRUAL_ERRORS)).toBeNull()
    // Holý kód bez jmenného prostoru ostatních položek (jiná vrstva) se nepřekládá.
    expect(accrualErrorKey({ response: { data: { error: { code: 'period_closed' } } } }, ACCRUAL_ERRORS)).toBeNull()
  })
})

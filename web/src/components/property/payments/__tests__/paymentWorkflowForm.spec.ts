import { describe, expect, it } from 'vitest'
import { paymentCents, paymentDecimal } from '../paymentWorkflowForm'

describe('property payment exact input', () => {
  it('keeps every cent at the native cash schema boundary', () => {
    expect(paymentCents('9007199254740.91')).toBe('900719925474091')
    expect(paymentDecimal('900719925474091')).toBe('9007199254740.91')
    expect(paymentCents('0,01')).toBe('1')
  })
  it('does not infer money from empty, exponential or sub-cent values', () => {
    for (const value of ['', ' ', '1e2', 'NaN', '1.001', '-1', '01.00']) expect(() => paymentCents(value)).toThrow()
  })
})

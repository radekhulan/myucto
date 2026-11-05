import { describe, expect, it } from 'vitest'
import { formatPayrollDuration, parsePayrollDuration } from '../payrollDuration'

describe('přesná pracovní doba v 1/3000 hodiny', () => {
  it.each([
    ['7:30', 22500], ['0:01', 50], ['0,001', 3], ['0.001', 3], ['7.500', 22500], ['0', 0], ['0:00', 0], [' 1,2 ', 3600],
  ])('převádí %s přesně na %i', (raw, units) => {
    expect(parsePayrollDuration(raw)).toBe(units)
  })
  it.each(['', '-1', '+1', '7:5', '7:60', '1.0001', '1e3', 'NaN', '0:01 + 0,001', '9007199254740991'])('odmítne %s', raw => {
    expect(parsePayrollDuration(raw)).toBeNull()
  })
  it('přesně formátuje minuty, tisíciny a jejich smíšený součet', () => {
    expect(formatPayrollDuration(null)).toBe('')
    expect(formatPayrollDuration(0)).toBe('0:00')
    expect(formatPayrollDuration(22500)).toBe('7:30')
    expect(formatPayrollDuration(3)).toBe('0,001')
    expect(formatPayrollDuration(53)).toBe('0:01:03.6')
    expect(parsePayrollDuration('0:01')! + parsePayrollDuration('0,001')!).toBe(53)
  })
})

import { describe, expect, it } from 'vitest'
import { fundingCents, fundingDayEnd } from '../fundingForm'
describe('funding evidence inputs', () => {
 it('keeps Czech money exact in cents and rejects rounded or negative values', () => {
  expect(fundingCents('90 000,01')).toBe('9000001')
  expect(fundingCents('9999999999999.99')).toBe('999999999999999')
  for (const value of ['-1', '0', '1.001', '1e3', '10000000000000', '12,3,4']) expect(fundingCents(value)).toBeNull()
 })
 it('uses the accounting zone including DST rather than the browser offset', () => {
  expect(fundingDayEnd('2026-01-31')).toBe('2026-01-31T23:59:59+01:00')
  expect(fundingDayEnd('2026-07-31')).toBe('2026-07-31T23:59:59+02:00')
  expect(fundingDayEnd('2026-03-29')).toBe('2026-03-29T23:59:59+02:00')
  expect(fundingDayEnd('2026-10-25')).toBe('2026-10-25T23:59:59+01:00')
 })
})

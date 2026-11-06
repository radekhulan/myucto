import { describe, expect, it } from 'vitest'
import { membershipRatePeriodEnd } from '../membershipRatePeriod'

describe('období předvyplněné sazbou', () => {
  it.each([
    ['2026-01-01', 12, '2026-12-31'], ['2026-07-01', 6, '2026-12-31'],
    ['2026-04-15', 3, '2026-07-14'], ['2024-01-31', 1, '2024-02-28'],
    ['2024-02-29', 12, '2025-02-27'], ['2026-12-01', 1, '2026-12-31'],
  ])('počítá od zvoleného počátku %s', (from, months, expected) => {
    expect(membershipRatePeriodEnd(from, months)).toBe(expected)
  })
  it('odmítne neplatné datum, délku a přesah povoleného roku', () => {
    expect(membershipRatePeriodEnd('2026-02-31', 12)).toBeNull()
    expect(membershipRatePeriodEnd('2026-01-01', 0)).toBeNull()
    expect(membershipRatePeriodEnd('9999-12-01', 12)).toBeNull()
  })
})

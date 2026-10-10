import { describe, expect, it } from 'vitest'
import { parseLocaleNumber } from './localeNumber'

describe('parseLocaleNumber', () => {
  it.each([
    ['128,5', 128.5],
    ['128.5', 128.5],
    ['1 234,50', 1234.5],
    ['1.234,50', 1234.5],
    ['1,234.50', 1234.5],
    ['1.234.567', 1234567],
    ['71 875,00 Kč', 71875],
    ['-3,5', -3.5],
    ['(12,00)', -12],
  ])('%s → %d', (input, expected) => {
    expect(parseLocaleNumber(input)).toBe(expected)
  })

  it.each(['', 'abc', '-', null, undefined])('%s → null', (input) => {
    expect(parseLocaleNumber(input)).toBeNull()
  })
})

import { afterEach, describe, expect, it } from 'vitest'
import { i18n } from '@/i18n'
import {
  formatCompactNumber,
  formatDateTime,
  formatMoneyMinor,
  formatNumber,
  formatPercent,
  formatPeriod,
  formatUtcDateTime,
} from '@/composables/useFormat'

afterEach(() => {
  i18n.global.locale.value = 'cs'
})

describe('locale-aware number formatting', () => {
  it('uses Czech decimal commas for decimal numbers and percentages', () => {
    i18n.global.locale.value = 'cs'

    expect(formatNumber(16.4, { maximumFractionDigits: 1 })).toBe('16,4')
    expect(formatPercent(42.5, 1)).toBe('42,5 %')
  })

  it('uses locale-aware compact chart labels', () => {
    i18n.global.locale.value = 'cs'
    expect(formatCompactNumber(8_000_000)).toMatch(/^8,0\s*mil\.$/)

    i18n.global.locale.value = 'en'
    expect(formatCompactNumber(8_000_000)).toBe('8.0M')
  })
})

describe('formatPeriod', () => {
  it('píše období slovy, ne strojově', () => {
    i18n.global.locale.value = 'cs'
    expect(formatPeriod('2026-08')).toBe('srpen 2026')
    expect(formatPeriod('2026-01')).toBe('leden 2026')
    expect(formatPeriod('2026-12')).toBe('prosinec 2026')

    i18n.global.locale.value = 'en'
    expect(formatPeriod('2026-08')).toBe('August 2026')
  })

  /*
   * Popisek nesmí shodit sestavu. Nesmyslný vstup se proto vrací tak, jak
   * přišel — „undefined 2026" by bylo horší než syrové `2026-13`.
   */
  it('vrací nesrozumitelný vstup beze změny a prázdný jako pomlčku', () => {
    i18n.global.locale.value = 'cs'
    expect(formatPeriod('2026-13')).toBe('2026-13')
    expect(formatPeriod('2026-00')).toBe('2026-00')
    expect(formatPeriod('nesmysl')).toBe('nesmysl')
    expect(formatPeriod(null)).toBe('—')
    expect(formatPeriod(undefined)).toBe('—')
    expect(formatPeriod('')).toBe('—')
  })
})

describe('formatUtcDateTime', () => {
  it('interpretuje databázový timestamp jako UTC okamžik', () => {
    expect(formatUtcDateTime('2026-08-26 22:06:48'))
      .toBe(formatDateTime('2026-08-26T22:06:48Z'))
  })
})

describe('formatMoneyMinor', () => {
  it('dělí setiny za volajícího a chybějící částku nevydává za nulu', () => {
    i18n.global.locale.value = 'cs'
    expect(formatMoneyMinor(123_456)).toBe('1 234,56 Kč')
    expect(formatMoneyMinor(-5)).toBe('-0,05 Kč')
    expect(formatMoneyMinor(0)).toBe('0,00 Kč')
    expect(formatMoneyMinor(null)).toBe('—')
    expect(formatMoneyMinor(undefined)).toBe('—')
  })
})

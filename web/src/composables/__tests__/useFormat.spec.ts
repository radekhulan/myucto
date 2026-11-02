import { afterEach, describe, expect, it, vi } from 'vitest'
import { i18n } from '@/i18n'
import { setOverdueIncludesToday } from '@/utils/invoiceOverdue'
import { overdueDays } from '@/utils/date'
import {
  formatCompactNumber,
  formatDateTime,
  formatMoney,
  formatMoneyMinor,
  formatMoneyWhole,
  formatNumber,
  formatPercent,
  formatPeriod,
  formatUtcDateTime,
  isOverdue,
} from '@/composables/useFormat'

afterEach(() => {
  setOverdueIncludesToday(false)
  vi.useRealTimers()
  vi.unstubAllEnvs()
  i18n.global.locale.value = 'cs'
})

describe('formatMoney', () => {
  it.each([-0, -0.001, -0.004])('nezobrazí záporné znaménko u nuly (%s)', (value) => {
    expect(formatMoney(value)).toBe('0,00 Kč')
  })

  it('přečte částku poslanou z API jako text', () => {
    expect(formatMoney('237600.60')).toBe(formatMoney(237600.6))
    expect(formatMoney('-0.00')).toBe(formatMoney(0))
    expect(formatMoney('neni-cislo')).toBe('—')
  })

  it('zápornou částku dál zobrazí se znaménkem', () => {
    expect(formatMoney(-0.01)).toContain('0,01')
    expect(formatMoney(-0.01)).not.toBe('0,01 Kč')
  })
})

describe('isOverdue', () => {
  it.each(['UTC', 'Europe/Prague', 'America/New_York', 'Asia/Tokyo'])(
    'umí zahrnout dnešní splatnost v zóně %s, aniž změní dny pro upomínku', (timezone) => {
      vi.stubEnv('TZ', timezone)
      vi.useFakeTimers()
      vi.setSystemTime(new Date('2026-09-07T12:00:00Z'))
      setOverdueIncludesToday(true)
      expect(isOverdue('2026-09-06', 'issued')).toBe(true)
      expect(isOverdue('2026-09-07', 'issued')).toBe(true)
      expect(isOverdue('2026-09-08', 'issued')).toBe(false)
      expect(isOverdue('2026-09-07', 'paid')).toBe(false)
      expect(overdueDays('2026-09-07')).toBe(0)
      setOverdueIncludesToday(false)
      expect(isOverdue('2026-09-07', 'issued')).toBe(false)
    },
  )
  it.each(['UTC', 'America/New_York', 'Asia/Tokyo'])('nezávisí na zóně prohlížeče %s', (timezone) => {
    vi.stubEnv('TZ', timezone)
    vi.useFakeTimers()
    vi.setSystemTime(new Date('2026-09-07T12:00:00Z'))
    expect(isOverdue('2026-09-07', 'issued')).toBe(false)
    expect(isOverdue('2026-09-06', 'issued')).toBe(true)
  })
  it.each(['2026-09-06T22:00:00Z', '2026-09-07T12:00:00Z', '2026-09-07T21:59:59Z'])(
    'respektuje celý den splatnosti v Praze (%s)', (now) => {
      vi.useFakeTimers()
      vi.setSystemTime(new Date(now))
      for (const status of ['issued', 'sent', 'reminded']) {
        expect(isOverdue('2026-09-06', status)).toBe(true)
        expect(isOverdue('2026-09-07', status)).toBe(false)
        expect(isOverdue('2026-09-08', status)).toBe(false)
      }
    },
  )

  it.each(['draft', 'paid', 'cancelled'])('neoznačuje stav %s jako po splatnosti', (status) => {
    vi.useFakeTimers()
    vi.setSystemTime(new Date('2026-09-07T12:00:00Z'))
    expect(isOverdue('2026-09-06', status)).toBe(false)
  })
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

describe('formatMoneyWhole', () => {
  it('zaokrouhlí na celé jednotky bez haléřů a chybějící částku nevydává za nulu', () => {
    i18n.global.locale.value = 'cs'
    expect(formatMoneyWhole(1234.56)).toBe('1 235 Kč')
    expect(formatMoneyWhole(1000)).toBe('1 000 Kč')
    expect(formatMoneyWhole(12.5, 'EUR')).toBe('13 €')
    expect(formatMoneyWhole(null)).toBe('—')
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

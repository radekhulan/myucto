import { afterEach, describe, expect, it, vi } from 'vitest'
import { pickDefaultYear } from '../periodDefaultYear'

describe('pickDefaultYear', () => {
  afterEach(() => {
    vi.useRealTimers()
  })

  function atYear(year: number) {
    vi.useFakeTimers()
    vi.setSystemTime(new Date(year, 5, 15))
  }

  it('předvolí letošek, když v něm data jsou', () => {
    atYear(2026)

    expect(pickDefaultYear([2026, 2025, 2024])).toBe(2026)
  })

  // Prázdný rok v nabídce by ukázal prázdný seznam a vypadal jako chyba.
  it('sáhne po nejnovějším roce s daty, když letos nic není', () => {
    atYear(2026)

    expect(pickDefaultYear([2025, 2024])).toBe(2025)
  })

  // Není co předvolit — filtr musí zůstat na „Vše", ne na undefined roce.
  it('bez dat nepředvolí nic', () => {
    expect(pickDefaultYear([])).toBeNull()
  })
})

// Starší instalace API seznam roků nevrací; předvolba kvůli tomu nesmí spadnout.
describe('pickDefaultYear bez seznamu', () => {
  it('chybějící seznam bere jako prázdný', () => {
    expect(pickDefaultYear(undefined)).toBeNull()
    expect(pickDefaultYear(null)).toBeNull()
  })
})

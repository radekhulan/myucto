import { describe, expect, it } from 'vitest'
import { formatMoney, formatUnitPrice } from '@/composables/useFormat'
import { hasSubCentPrice, roundDerivedPrice, roundUnitPrice, unitPriceDecimals, unitPriceText } from '@/utils/unitPrice'

describe('unitPriceText', () => {
  it('haléřovou cenu z DECIMAL(16,6) vrátí na dvě místa', () => {
    expect(unitPriceText('12.500000')).toBe('12.50')
    expect(unitPriceText('100.00')).toBe('100.00')
    expect(unitPriceText(7)).toBe('7.00')
  })

  it('cenu pod haléř vrátí na její místa, nejvýš šest', () => {
    expect(unitPriceText('0.010800')).toBe('0.0108')
    expect(unitPriceText('0.004000')).toBe('0.004')
    expect(unitPriceText(0.000001)).toBe('0.000001')
  })

  it('prázdnou a nečíselnou hodnotu nechá být', () => {
    expect(unitPriceText(null)).toBeNull()
    expect(unitPriceText('')).toBe('')
    expect(unitPriceText('abc')).toBe('abc')
  })
})

describe('roundUnitPrice', () => {
  it('zaokrouhlí na šest míst half-up i u záporné ceny', () => {
    expect(roundUnitPrice(99.99 * 0.93)).toBe(92.9907)
    expect(roundUnitPrice(0.0000005)).toBe(0.000001)
    expect(roundUnitPrice(-0.0000005)).toBe(-0.000001)
    expect(roundUnitPrice(10 / 3)).toBe(3.333333)
  })
})

describe('roundDerivedPrice', () => {
  it('vypočtenou cenu nechá v haléřích, pokud ji nezkreslí', () => {
    expect(roundDerivedPrice(400 / 3)).toBe(133.33)
    expect(roundDerivedPrice(999.99 * 0.95)).toBe(949.99)
  })

  it('levnou položku drží na šest míst', () => {
    expect(roundDerivedPrice(0.0108)).toBe(0.0108)
    expect(roundDerivedPrice(0.004 * 0.95)).toBe(0.0038)
  })
})

describe('unitPriceDecimals', () => {
  it('pozná cenu pod haléř', () => {
    expect(hasSubCentPrice(0.004)).toBe(true)
    expect(hasSubCentPrice('12.500000')).toBe(false)
    expect(unitPriceDecimals(0.0108)).toBe(4)
    expect(unitPriceDecimals(12.5)).toBe(2)
  })
})

describe('formatUnitPrice', () => {
  it('haléřovou cenu formátuje stejně jako částku', () => {
    expect(formatUnitPrice('100.000000')).toBe(formatMoney(100))
    expect(formatUnitPrice(12.5, 'EUR')).toBe(formatMoney(12.5, 'EUR'))
  })

  it('cenu pod haléř ukáže na její místa místo nuly', () => {
    expect(formatUnitPrice(0.004, 'EUR')).toContain('0,004')
    expect(formatUnitPrice('0.010800')).toContain('0,0108')
    expect(formatMoney(0.004, 'EUR')).toContain('0,00')
  })
})

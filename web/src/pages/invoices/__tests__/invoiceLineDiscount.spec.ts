import { describe, expect, it } from 'vitest'
import { applyLineDiscount, legacyListPrice, lineDiscountInputValue, lineDiscountPct, parseLineDiscountInput, quotedListPrice } from '@/pages/invoices/invoiceLineDiscount'

describe('ceníková cena a sleva řádku faktury', () => {
  it('spočítá slevu stejně jako server (tři desetinná místa, půl nahoru)', () => {
    expect(lineDiscountPct(1000, 900)).toBe(10)
    expect(lineDiscountPct('300', '100')).toBe(66.667)
    expect(lineDiscountPct('800.00', 700)).toBe(12.5)
    expect(lineDiscountPct(500, 0)).toBe(100)
    expect(lineDiscountPct(1000, 1050)).toBe(-5)
  })

  it('bez ceníku, při shodě a u záporné ceny slevu nemá', () => {
    expect(lineDiscountPct(null, 900)).toBeNull()
    expect(lineDiscountPct(undefined, 900)).toBeNull()
    expect(lineDiscountPct(0, 900)).toBeNull()
    expect(lineDiscountPct(1000, 1000)).toBeNull()
    expect(lineDiscountPct(1000, -100)).toBeNull()
    expect(lineDiscountPct(1000, '')).toBeNull()
  })

  it('ceníková cena z nacenění a z karty bez nacenění', () => {
    expect(quotedListPrice({ list_unit_price: '800.00' })).toBe(800)
    expect(quotedListPrice({ list_unit_price: null })).toBeNull()
    expect(quotedListPrice({})).toBeNull()
    expect(legacyListPrice({ sale_price_without_vat: '1000.00' })).toBe(1000)
    expect(legacyListPrice({ sale_price_without_vat: null })).toBeNull()
  })
})

describe('sleva v % zadaná u řádku', () => {
  it.each([null, 7])('zápornou cenu s existujícím ceníkem nemění (karta %s)', (stock_item_id) => {
    const row = { stock_item_id, list_unit_price: 1000, unit_price_without_vat: -1000 }
    for (const pct of [0, 10, 100]) expect(applyLineDiscount(row, pct)).toBeNull()
  })

  it('cena po slevě z ceníkové ceny, zaokrouhlená jako cena řádku', () => {
    expect(applyLineDiscount({ list_unit_price: 1000, unit_price_without_vat: 1000 }, 10)).toEqual({ list_unit_price: 1000, unit_price_without_vat: 900 })
    expect(applyLineDiscount({ list_unit_price: '999.00', unit_price_without_vat: 999 }, 12.5)).toEqual({ list_unit_price: 999, unit_price_without_vat: 874.13 })
    expect(applyLineDiscount({ list_unit_price: 1000, unit_price_without_vat: 900 }, 0)).toEqual({ list_unit_price: 1000, unit_price_without_vat: 1000 })
    expect(applyLineDiscount({ list_unit_price: 1000, unit_price_without_vat: 900 }, 100)).toEqual({ list_unit_price: 1000, unit_price_without_vat: 0 })
    // Cena po slevě má vždy dvě místa, i u časové položky.
    expect(applyLineDiscount({ list_unit_price: 333.33, unit_price_without_vat: 333.33 }, 33.33)).toEqual({ list_unit_price: 333.33, unit_price_without_vat: 222.23 })
  })

  it('zaokrouhlí přesně půl nahoru na haléře', () => {
    expect(applyLineDiscount({ list_unit_price: 4.35, unit_price_without_vat: 4.35 }, 50)?.unit_price_without_vat).toBe(2.18)
    expect(applyLineDiscount({ list_unit_price: 8.2, unit_price_without_vat: 8.2 }, 2.5)?.unit_price_without_vat).toBe(8)
    expect(applyLineDiscount({ list_unit_price: 1.05, unit_price_without_vat: 1.05 }, 50)?.unit_price_without_vat).toBe(0.53)
  })

  it('bez ceníkové ceny se jí stane aktuální cena řádku', () => {
    expect(applyLineDiscount({ list_unit_price: null, unit_price_without_vat: '500' }, 10)).toEqual({ list_unit_price: 500, unit_price_without_vat: 450 })
    expect(applyLineDiscount({ unit_price_without_vat: 500 }, 0)).toBeNull()
    expect(applyLineDiscount({ list_unit_price: null, unit_price_without_vat: 0 }, 10)).toBeNull()
    expect(applyLineDiscount({ list_unit_price: null, unit_price_without_vat: -100 }, 10)).toBeNull()
  })

  it('cena opravená na ceníkovou nebo nad ni se stane novou ceníkovou', () => {
    expect(applyLineDiscount({ list_unit_price: 1000, unit_price_without_vat: 1200 }, 10)).toEqual({ list_unit_price: 1200, unit_price_without_vat: 1080 })
    expect(applyLineDiscount({ list_unit_price: 1000, unit_price_without_vat: 1000 }, 5)).toEqual({ list_unit_price: 1000, unit_price_without_vat: 950 })
    expect(applyLineDiscount({ list_unit_price: 1000, unit_price_without_vat: 1200 }, 0)).toBeNull()
    expect(applyLineDiscount({ list_unit_price: 1000, unit_price_without_vat: 900 }, 20)).toEqual({ list_unit_price: 1000, unit_price_without_vat: 800 })
  })

  it('skladový řádek s přirážkou hladiny počítá slevu z ceníku karty', () => {
    const row = { stock_item_id: 7, list_unit_price: 1000, unit_price_without_vat: 1100 }
    expect(applyLineDiscount(row, 5)).toEqual({ list_unit_price: 1000, unit_price_without_vat: 950 })
    expect(applyLineDiscount(row, 0)).toEqual({ list_unit_price: 1000, unit_price_without_vat: 1000 })
    expect(applyLineDiscount({ ...row, stock_item_id: null }, 5)).toEqual({ list_unit_price: 1100, unit_price_without_vat: 1045 })
  })

  it('přijme i znak procenta', () => {
    expect(parseLineDiscountInput('10%')).toBe(10)
    expect(parseLineDiscountInput(' 12,5 % ')).toBe(12.5)
    expect(parseLineDiscountInput('%')).toBe(0)
    expect(parseLineDiscountInput('10%%')).toBeNull()
  })

  it('vstup 0 až 100, desetinná čárka, víc míst se zaokrouhlí na dvě', () => {
    expect(parseLineDiscountInput('10')).toBe(10)
    expect(parseLineDiscountInput(' 12,5 ')).toBe(12.5)
    expect(parseLineDiscountInput('12.345')).toBe(12.35)
    expect(parseLineDiscountInput('')).toBe(0)
    expect(parseLineDiscountInput('100')).toBe(100)
    expect(parseLineDiscountInput('100.01')).toBeNull()
    expect(parseLineDiscountInput('-5')).toBeNull()
    expect(parseLineDiscountInput('abc')).toBeNull()
  })

  it('pole ukáže slevu z ceníku na dvě místa, přirážku a shodu prázdné', () => {
    expect(lineDiscountInputValue(1000, 900)).toBe(10)
    expect(lineDiscountInputValue(300, 100)).toBe(66.67)
    expect(lineDiscountInputValue(1000, 1050)).toBeNull()
    expect(lineDiscountInputValue(1000, 1000)).toBeNull()
    expect(lineDiscountInputValue(null, 1000)).toBeNull()
  })
})

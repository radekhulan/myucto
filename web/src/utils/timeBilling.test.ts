import { describe, expect, it } from 'vitest'
import { formatDuration, isPreciseUnitPrice, isTimeItem, itemQuantity, unitPriceForAmount, parseDuration, syncCreditNoteItemSign, timeItemTotals, validateDurationInputs, workAmount, workHours, workRowTotal } from './timeBilling'

describe('time billing', () => {
  it('synchronizes exact minutes with the credit-note quantity sign', () => {
    const item = { quantity: 1 / 3, duration_minutes: 20 }
    syncCreditNoteItemSign(item, true)
    expect(item).toEqual({ quantity: -1 / 3, duration_minutes: -20 })
    syncCreditNoteItemSign(item, false)
    expect(item).toEqual({ quantity: 1 / 3, duration_minutes: 20 })
  })
  it('validates duration inputs only inside the supplied editor root', () => {
    const root = document.createElement('div')
    const own = document.createElement('input')
    own.dataset.timeDuration = ''
    root.append(own)
    const foreign = document.createElement('input')
    foreign.dataset.timeDuration = ''
    foreign.setCustomValidity('invalid in another pane')
    document.body.append(root, foreign)
    try {
      expect(validateDurationInputs(root)).toBe(true)
      expect(validateDurationInputs(document)).toBe(false)
    } finally {
      root.remove()
      foreign.remove()
    }
  })
  it('rounds exact minute amounts correctly at decimal half-cent boundaries', () => {
    for (const sign of [1, -1]) {
      for (const [minutes, rate] of [[60, 0.015], [1, 0.9]]) {
        const item = { unit: 'h', duration_minutes: sign * minutes, unit_price_without_vat: rate }
        expect(timeItemTotals(item, 0, false)).toEqual({ base: sign * 0.02, vat: 0, with: sign * 0.02 })
        expect(timeItemTotals(item, 0, true)).toEqual({ base: sign * 0.02, vat: 0, with: sign * 0.02 })
        expect(workRowTotal({ hours: minutes / 60, duration_minutes: sign * minutes, rate })).toBe(sign * 0.02)
      }
    }
  })
  it('rounds new time amounts symmetrically for credit notes', () => {
    for (const sign of [1, -1]) {
      const item = { unit: 'h', quantity: sign * 0.05, duration_minutes: sign * 3, unit_price_without_vat: 0.9 }
      expect(timeItemTotals(item, 21, false)).toEqual({ base: sign * 0.05, vat: sign * 0.01, with: sign * 0.06 })
    }
  })
  it('matches PHP rounding at floating point half-cent boundaries', () => {
    expect(timeItemTotals({ unit: 'h', duration_minutes: 196, unit_price_without_vat: 307.929681 }, 12, true))
      .toEqual({ base: 898.13, vat: 107.77, with: 1005.9 })
    expect(timeItemTotals({ unit: 'h', duration_minutes: -185, unit_price_without_vat: 913.783444 }, 21, false))
      .toEqual({ base: -2817.5, vat: -591.68, with: -3409.18 })
  })
  it('preserves legacy decimal hours and amounts', () => {
    expect(workHours({ hours: 0.33 })).toBe(0.33)
    expect(workAmount({ hours: 0.33, rate: 1000 })).toBe(330)
    expect(parseDuration('0,33')).toEqual({ hours: 0.33, duration_minutes: null })
    expect(parseDuration('0,017')).toEqual({ hours: 0.017, duration_minutes: null })
    expect(parseDuration('0,017', 2)).toBeNull()
  })
  it('uses exact minutes instead of the rounded hours projection', () => {
    expect(parseDuration('1:20')).toEqual({ hours: 80 / 60, duration_minutes: 80 })
    expect(parseDuration('1,5')).toEqual({ hours: 1.5, duration_minutes: 90 })
    expect(workAmount({ hours: 0.02, duration_minutes: 1, rate: 1000 })).toBe(1000 / 60)
    expect(itemQuantity({ quantity: 0.017, duration_minutes: 1, unit: 'hod' })).toBe(1 / 60)
    expect(formatDuration(80)).toBe('1:20')
  })
  it('keeps stock and non-time quantities unchanged', () => {
    expect(isTimeItem({ unit: 'hod', stock_item_id: 7 })).toBe(false)
    expect(itemQuantity({ quantity: 2, duration_minutes: 1, unit: 'ks' })).toBe(2)
    expect(isTimeItem({ unit: 'min' })).toBe(false)
  })
  it('treats sub-cent unit prices of bulk items as precise', () => {
    const caps = { unit: 'ks', stock_item_id: 7, quantity: 250000, unit_price_without_vat: 0.004 }
    expect(isPreciseUnitPrice(caps)).toBe(true)
    expect(timeItemTotals(caps, 21, false)).toEqual({ base: 1000, vat: 210, with: 1210 })
    expect(isPreciseUnitPrice({ unit: 'ks', quantity: 3, unit_price_without_vat: 12.5 })).toBe(false)
    expect(isPreciseUnitPrice({ unit: 'h', quantity: 1, unit_price_without_vat: 333.333333 })).toBe(true)
  })
  it('derives a unit price from the line amount without losing it', () => {
    expect(unitPriceForAmount(1000, 250000)).toBe(0.004)
    expect(unitPriceForAmount(2700, 250000)).toBe(0.0108)
    expect(unitPriceForAmount(1000, 3)).toBe(333.333333)
    expect(unitPriceForAmount(1210, 2)).toBe(605)
  })
  it('rejects invalid duration without silently rounding it', () => {
    for (const value of ['1:60', '1:2', '1:20:30', 'abc', '1e8', 'Infinity']) {
      expect(parseDuration(value)).toBeNull()
    }
    expect(parseDuration('-0:01')).toEqual({ hours: -1 / 60, duration_minutes: -1 })
    expect(formatDuration(-1)).toBe('-0:01')
  })
})

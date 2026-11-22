import { describe, expect, it } from 'vitest'
import type { QuoteItem } from '@/api/salesQuotes'
import { lineTotals } from './quotePresentation'

const work: QuoteItem = {
  id: 'test', kind: 'work', description: 'Práce', quantity: '0.017', duration_minutes: 1,
  unit: 'h', unit_price: '1200', discount_percent: '10', vat_rate: '21',
}

describe('cenový náhled nabídky', () => {
  it('použije přesné minuty, dohodnutou slevu a sazbu DPH', () => {
    expect(lineTotals(work, false)).toEqual({ without_vat: 18, vat: 3.78, with_vat: 21.78 })
  })

  it('při ceně s DPH zachová vyjednaný hrubý součet', () => {
    expect(lineTotals(work, true)).toEqual({ without_vat: 14.88, vat: 3.12, with_vat: 18 })
  })

  it('při přenesení daňové povinnosti odvodí nulovou DPH', () => {
    expect(lineTotals(work, false, true)).toEqual({ without_vat: 18, vat: 0, with_vat: 18 })
  })
})

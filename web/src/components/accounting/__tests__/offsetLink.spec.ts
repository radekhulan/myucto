import { describe, it, expect } from 'vitest'
import { balancedOffsetDocs, offsetRoute, parseOffsetDocs, parsePartnerId } from '@/components/accounting/offsetLink'

describe('offsetLink', () => {
  it('sestaví odkaz na Zápočty s partnerem a doklady, bez částky celý zbytek', () => {
    expect(offsetRoute(12, [
      { doc_type: 'invoice', doc_id: 123, amount: 1000 },
      { doc_type: 'purchase_invoice', doc_id: 456, amount: null },
    ])).toEqual({ name: 'accounting-offsets', query: { partner_id: '12', docs: 'invoice:123:1000.00,purchase_invoice:456' } })
    expect(offsetRoute(12, [])).toEqual({ name: 'accounting-offsets', query: { partner_id: '12' } })
  })

  it('z query převezme jen platné doklady a partnera', () => {
    expect(parseOffsetDocs('invoice:123:1000,purchase_invoice:456,other_item:7:5,invoice:x:1,invoice:9:-5,invoice:10:12.345'))
      .toEqual([
        { doc_type: 'invoice', doc_id: 123, amount: 1000 },
        { doc_type: 'purchase_invoice', doc_id: 456, amount: null },
        { doc_type: 'invoice', doc_id: 10, amount: 12.35 },
      ])
    expect(parseOffsetDocs(undefined)).toEqual([])
    expect(parsePartnerId('12')).toBe(12)
    expect(parsePartnerId('0')).toBeNull()
    expect(parsePartnerId(['12'])).toBeNull()
  })

  it('vyrovná obě strany na menší součet, každému dokladu nejvýš jeho zbytek', () => {
    expect(balancedOffsetDocs(
      [{ doc_id: 1, remaining: 700 }, { doc_id: 2, remaining: 500 }],
      [{ doc_id: 3, remaining: 1000 }],
    )).toEqual([
      { doc_type: 'invoice', doc_id: 1, amount: 700 },
      { doc_type: 'invoice', doc_id: 2, amount: 300 },
      { doc_type: 'purchase_invoice', doc_id: 3, amount: 1000 },
    ])
    expect(balancedOffsetDocs([{ doc_id: 1, remaining: 100 }], [])).toEqual([])
  })
})

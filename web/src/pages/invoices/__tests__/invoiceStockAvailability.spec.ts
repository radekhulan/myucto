import { describe, expect, it } from 'vitest'
import {
  groupInvoiceStockAvailability,
  invoiceStockAvailabilityKey,
} from '@/pages/invoices/invoiceStockAvailability'

describe('invoice stock availability', () => {
  it('seskupí položky podle skutečného skladu řádku a doplní výchozí sklad', () => {
    expect(groupInvoiceStockAvailability([
      { stock_item_id: 10, warehouse_id: 2 },
      { stock_item_id: 10, warehouse_id: 3 },
      { stock_item_id: 11, warehouse_id: null },
      { stock_item_id: 11, warehouse_id: null },
      { stock_item_id: null, warehouse_id: 2 },
    ], 5)).toEqual([
      { warehouseId: 2, itemIds: [10] },
      { warehouseId: 3, itemIds: [10] },
      { warehouseId: 5, itemIds: [11] },
    ])
  })

  it('oddělí klíče stejné karty v různých skladech', () => {
    expect(invoiceStockAvailabilityKey(10, 2)).toBe('2:10')
    expect(invoiceStockAvailabilityKey(10, 3)).toBe('3:10')
    expect(invoiceStockAvailabilityKey(10, null)).toBe('all:10')
  })
})

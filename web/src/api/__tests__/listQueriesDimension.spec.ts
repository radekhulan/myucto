import { describe, expect, it, vi } from 'vitest'

vi.mock('@/api/client', () => ({ api: { get: vi.fn() } }))

import { invoiceListFilterParams, purchaseListFilterParams } from '@/api/listQueries'
import { invoicesApi } from '@/api/invoices'
import { purchaseInvoicesApi } from '@/api/purchaseInvoices'

describe('listQueries – filtr dimenze seznamů faktur', () => {
  it('vydané faktury posílají hodnoty dimenze a příznak bez podřízených v filter[…]', () => {
    expect(invoiceListFilterParams({ dimension_value_id: '5,7', dimension_descendants: false })).toEqual({
      'filter[dimension_value_id]': '5,7',
      'filter[dimension_descendants]': 0,
    })
    expect(invoiceListFilterParams({ dimension_value_id: '5', dimension_descendants: true })).toEqual({
      'filter[dimension_value_id]': '5',
    })
  })

  it('přijaté faktury posílají filtr stejně', () => {
    expect(purchaseListFilterParams({ year: 2099, dimension_value_id: '9', dimension_descendants: false })).toEqual({
      'filter[year]': 2099,
      'filter[dimension_value_id]': '9',
      'filter[dimension_descendants]': 0,
    })
  })

  it('bez hodnoty dimenze se příznak podřízených neposílá', () => {
    expect(invoiceListFilterParams({ dimension_descendants: false })).toEqual({})
    expect(purchaseListFilterParams({ dimension_value_id: '', dimension_descendants: false })).toEqual({})
  })

  it('kniha faktur nese filtr dimenze v URL exportu', () => {
    const sales = new URL(invoicesApi.bookUrl({ dimension_value_id: '5,7', dimension_descendants: false }, 'pdf'), 'https://x.test')
    expect(sales.searchParams.get('filter[dimension_value_id]')).toBe('5,7')
    expect(sales.searchParams.get('filter[dimension_descendants]')).toBe('0')
    const purchases = new URL(purchaseInvoicesApi.bookUrl({ dimension_value_id: '9' }, 'xlsx'), 'https://x.test')
    expect(purchases.searchParams.get('filter[dimension_value_id]')).toBe('9')
    expect(purchases.searchParams.has('filter[dimension_descendants]')).toBe(false)
  })
})

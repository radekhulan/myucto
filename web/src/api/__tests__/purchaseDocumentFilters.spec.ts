import { beforeEach, describe, expect, it, vi } from 'vitest'
vi.mock('@/api/client', () => ({ api: { get: vi.fn() } }))
import { api } from '@/api/client'
import { purchaseInvoicesApi } from '@/api/purchaseInvoices'

describe('filtry podkladů přijatých faktur', () => {
  beforeEach(() => { vi.mocked(api.get).mockReset().mockResolvedValue({ data: {} }) })
  it.each([['0', '0'], ['0', '1'], ['1', '0'], ['1', '1']] as const)('odesílá kombinaci %s / %s včetně nuly', async (visual, structured) => {
    await purchaseInvoicesApi.listGrouped({ has_visual_document: visual, has_structured_source: structured })
    expect(api.get).toHaveBeenCalledWith('/purchase-invoices', { params: {
      'filter[has_visual_document]': visual, 'filter[has_structured_source]': structured,
    } })
  })
  it('výchozí Vše neposílá žádný filtr podkladů', async () => {
    await purchaseInvoicesApi.listGrouped()
    expect(api.get).toHaveBeenCalledWith('/purchase-invoices', { params: {} })
  })
})

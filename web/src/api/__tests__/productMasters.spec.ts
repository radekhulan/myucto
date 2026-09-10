import { beforeEach, describe, expect, it, vi } from 'vitest'
import { productMastersApi } from '../productMasters'

const calls = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), put: vi.fn() }))

vi.mock('../client', () => ({ api: calls }))

describe('productMastersApi', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    calls.get.mockResolvedValue({ data: {} })
    calls.post.mockResolvedValue({ data: {} })
    calls.put.mockResolvedValue({ data: {} })
  })

  it('sends attach with card CAS versions and option combination', async () => {
    const payload = {
      master_row_version: 7,
      variants: [{
        stock_item_id: 42,
        row_version: 11,
        options: [{ attribute_id: 3, option_id: 9 }],
        inheritance: { manufacturer: true, i18n: { cs: { name: true, short_desc: false, description: true, seo_title: false, seo_description: false } } },
      }],
    }

    await productMastersApi.attachVariants(5, payload)

    expect(calls.post).toHaveBeenCalledWith('/eshop/product-masters/5/variants', payload, { signal: undefined })
  })

  it('uses server detach preview versions for the materializing detach', async () => {
    calls.get.mockResolvedValueOnce({ data: { row_version: 12, link_row_version: 4, materialized: { manufacturer_id: 8, i18n: [] } } })
    const preview = await productMastersApi.previewDetach(5, 42)
    await productMastersApi.detachVariant(5, 42, { row_version: preview.row_version, link_row_version: preview.link_row_version })

    expect(calls.get).toHaveBeenCalledWith('/eshop/product-masters/5/variants/42/detach-preview', { signal: undefined })
    expect(calls.post).toHaveBeenCalledWith('/eshop/product-masters/5/variants/42/detach', { row_version: 12, link_row_version: 4 }, { signal: undefined })
  })

  it('reads transfer differences from paginated job items', async () => {
    await productMastersApi.getContentTransferItems(91, { page: 3, limit: 50, status: 'ready' })

    expect(calls.get).toHaveBeenCalledWith('/eshop/jobs/91/items', {
      params: { page: 3, limit: 50, status: 'ready' },
      signal: undefined,
    })
  })

  it('keeps typed relation order and source-card CAS in the write payload', async () => {
    const payload = { row_version: 15, items: [{ type: 'accessory' as const, target_stock_item_id: 77, display_order: 10 }] }
    await productMastersApi.updateRelations(42, payload)

    expect(calls.put).toHaveBeenCalledWith('/eshop/products/42/relations', payload, { signal: undefined })
  })
})

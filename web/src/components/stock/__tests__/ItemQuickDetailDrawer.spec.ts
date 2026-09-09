import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { nextTick, ref } from 'vue'
import type { StockItem } from '@/api/stock'

const m = vi.hoisted(() => ({
  getItem: vi.fn(),
  itemNeighbors: vi.fn(),
}))

vi.mock('@/api/stock', () => ({
  stockApi: {
    getItem: m.getItem,
    itemNeighbors: m.itemNeighbors,
  },
}))
vi.mock('@/composables/useFormat', () => ({ formatMoney: (value: number) => `${value} Kč` }))
vi.mock('@/components/ui/buttonStyles', () => ({ ICONS: new Proxy({}, { get: () => 'M0 0' }), btnFilled: () => '', btnOutline: () => '' }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string, params?: Record<string, unknown>) => params ? `${key}:${params.position}/${params.total}` : key }) }))

import ItemQuickDetailDrawer from '../ItemQuickDetailDrawer.vue'

const item: StockItem = {
  id: 42, supplier_id: 1, sku: 'QUICK-42', name: 'Quick item', item_type: 'goods', manufacturer_id: 7,
  unit: 'ks', ean: null, vat_rate_id: null, sale_price_without_vat: '100', min_qty: null, is_active: true,
  row_version: 1, note: null, created_at: '2026-01-01', updated_at: '2026-01-01', effective_price: '90.00', qty: '999',
}

describe('ItemQuickDetailDrawer', () => {
  beforeEach(() => vi.clearAllMocks())

  it('uses a frozen neighbor scope and never derives quantity from the item response', async () => {
    m.getItem.mockResolvedValue(item)
    m.itemNeighbors.mockResolvedValue({ previous_id: 41, next_id: 43, position: 2, total: 3 })
    const wrapper = mount(ItemQuickDetailDrawer, {
      props: {
        itemId: 42,
        initialItem: item,
        manufacturers: [{ id: 7, code: 'acme', name: 'Acme', website: null, display_order: 0, export_eshop: true, archived: false }],
        neighbors: { q: 'quick', sort: 'sku', direction: 'asc', ids: [41, 42, 43] },
      },
      global: { stubs: { Drawer: { props: ['title'], template: '<section>{{ title }}<slot /><slot name="footer" /></section>' } } },
    })
    await flushPromises()

    expect(m.itemNeighbors).toHaveBeenCalledWith(42, expect.objectContaining({ ids: [41, 42, 43], q: 'quick' }), expect.objectContaining({ signal: expect.any(AbortSignal) }))
    expect(wrapper.text()).toContain('Acme')
    expect(wrapper.text()).toContain('90 Kč')
    expect(wrapper.text()).not.toContain('999')

    await wrapper.findAll('button')[1]!.trigger('click')
    expect(wrapper.emitted('navigate')).toEqual([[43]])
  })

  it('ignores an obsolete item and neighbor response after navigation', async () => {
    let resolveFirstItem!: (value: typeof item) => void
    let resolveFirstNeighbors!: (value: { previous_id: number | null; next_id: number | null; position: number | null; total: number }) => void
    m.getItem.mockImplementationOnce(() => new Promise(resolve => { resolveFirstItem = resolve }))
    m.itemNeighbors.mockImplementationOnce(() => new Promise(resolve => { resolveFirstNeighbors = resolve }))
    m.getItem.mockResolvedValueOnce({ ...item, id: 43, name: 'New item' })
    m.itemNeighbors.mockResolvedValueOnce({ previous_id: 42, next_id: null, position: 3, total: 3 })
    const currentId = ref(42)
    const wrapper = mount({
      components: { ItemQuickDetailDrawer },
      setup: () => ({ currentId }),
      template: '<ItemQuickDetailDrawer :item-id="currentId" :initial-item="null" :manufacturers="[]" :neighbors="{ q: \'frozen\' }" />',
    }, { global: { stubs: { Drawer: { props: ['title'], template: '<section>{{ title }}<slot /><slot name="footer" /></section>' } } } })

    currentId.value = 43
    await nextTick()
    await flushPromises()
    resolveFirstItem(item)
    resolveFirstNeighbors({ previous_id: null, next_id: 99, position: 1, total: 3 })
    await flushPromises()

    expect(wrapper.text()).toContain('New item')
    expect(wrapper.text()).not.toContain('999')
  })
})

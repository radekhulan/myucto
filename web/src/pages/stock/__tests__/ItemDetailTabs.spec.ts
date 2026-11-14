import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'

const m = vi.hoisted(() => ({
  item: {} as Record<string, unknown>,
  levels: [] as Array<Record<string, unknown>>,
  context: null as Record<string, unknown> | null,
  vatRates: [{ id: 1, rate_percent: 21, is_reverse_charge: false }] as Array<Record<string, unknown>>,
  deniedWrite: new Set<string>(),
  inventory: [] as Array<Record<string, unknown>>,
}))

vi.mock('@/api/stock', () => ({
  stockApi: {
    getItem: vi.fn(async () => ({
      id: 42,
      sku: 'ITEM-42',
      name: 'Synthetic item',
      item_type: 'goods',
      unit: 'pcs',
      tracking_mode: 'none',
      lifecycle_status: 'ready',
      is_active: true,
      is_stocked: true,
      ...m.item,
    })),
    itemMovements: vi.fn(async () => ({ opening_balance: '0', items: [] })),
    itemTracking: vi.fn(async () => ({ tracking_mode: m.item.tracking_mode, base_unit: 'pcs', inventory: m.inventory })),
    itemMovementsExportUrl: vi.fn(() => ''),
    levels: vi.fn(async () => ({ data: m.levels, meta: { total: m.levels.length } })),
  },
}))
vi.mock('@/api/purchaseOrders', () => ({
  purchaseOrdersApi: { quantities: vi.fn(async () => ({ items: [] })) },
}))
vi.mock('@/api/productMasters', () => ({
  productMastersApi: { getProductContext: vi.fn(async () => m.context) },
}))
vi.mock('@/api/codebooks', () => ({
  codebooksApi: { vatRates: vi.fn(async () => m.vatRates) },
}))
vi.mock('@/api/eshop', () => ({
  eshopApi: {
    listAttributes: vi.fn(async () => [
      { id: 8, name: 'Power', data_type: 'number', unit: 'kW', display_order: 2 },
      { id: 7, name: 'Colour', data_type: 'enum', unit: null, display_order: 1 },
    ]),
    listAttributeOptions: vi.fn(async () => [{ id: 70, attribute_id: 7, label: 'Red' }, { id: 71, attribute_id: 7, label: 'Black' }]),
  },
}))
vi.mock('@/composables/useFormat', () => ({
  formatMoney: (value: number) => value.toFixed(2),
  formatUnitPrice: (value: number) => value.toFixed(2),
  formatDate: (value: string) => value,
}))
vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ canRead: () => true, canWrite: (key: string) => !m.deniedWrite.has(key) }),
}))
vi.mock('@/stores/supplier', () => ({
  useSupplierStore: () => ({ currentSupplierId: 1, currentSupplier: { id: 1, is_vat_payer: true } }),
}))
vi.mock('@/composables/useToast', () => ({
  useToast: () => ({ success: vi.fn(), error: vi.fn() }),
}))
vi.mock('vue-router', () => ({
  useRoute: () => ({ params: { id: '42' }, query: {} }),
  useRouter: () => ({ push: vi.fn() }),
  RouterLink: { template: '<a><slot /></a>' },
}))
vi.mock('vue-i18n', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-i18n')>()),
  useI18n: () => ({ t: (key: string, params?: Record<string, unknown>) => params ? `${key} ${JSON.stringify(params)}` : key }),
}))

import ItemDetail from '../ItemDetail.vue'

describe('ItemDetail overview', () => {
  it('stránkuje sériová čísla a hledá podle čísla, skladu a pozice', async () => {
    m.item = { tracking_mode: 'serial' }
    m.inventory = Array.from({ length: 24 }, (_, index) => ({
      stock_tracking_unit_id: index + 1, warehouse_id: 1, location_id: index + 1,
      serial_number: `SN-${100 + index}`, warehouse_code: 'MAIN', location_code: `BIN-${100 + index}`, quantity: '1',
    }))
    const wrapper = mount(ItemDetail, {
      global: { stubs: { ActionBar: true, EmptyState: true, ItemDuplicateDialog: true, ItemTemplatesPanel: true, ProductRelationsPanel: true, RouterLink: { template: '<a><slot /></a>' } } },
    })
    try {
      await flushPromises()
      expect(wrapper.text()).toContain('stock.tracking.mode_serial')
      expect(wrapper.text()).toContain('SN-100')
      expect(wrapper.text()).not.toContain('SN-123')
      await wrapper.findAll('button').find(button => button.text() === 'common.next')!.trigger('click')
      expect(wrapper.text()).toContain('SN-123')
      const search = wrapper.get('input[type="search"]')
      await search.setValue('sn-102 main bin-102')
      expect(wrapper.text()).toContain('SN-102')
      expect(wrapper.text()).not.toContain('SN-123')
      await search.setValue('missing')
      expect(wrapper.text()).toContain('stock.tracking.inventory_no_matches')
    } finally {
      wrapper.unmount()
      m.item = {}
      m.inventory = []
    }
  })

  it('ukáže stav po skladech, cenu s DPH, parametry, poznámku a rychlé doklady', async () => {
    m.item = { sale_price_without_vat: '1000.00', effective_price: '1000.00', vat_rate_id: 1, note: 'Synthetic note' }
    m.levels = [
      { warehouse_id: 1, warehouse_code: 'MAIN', warehouse_name: 'Main', qty: '2.000', avg_unit_cost: '500.00', value_total: '1000.00' },
      { warehouse_id: 2, warehouse_code: 'SHOW', warehouse_name: 'Showroom', qty: '1.000', avg_unit_cost: '800.00', value_total: '800.00' },
      { warehouse_id: 3, warehouse_code: 'OLD', warehouse_name: 'Old', qty: '0.000', avg_unit_cost: '0.00', value_total: '0.00' },
    ]
    m.context = { attributes: [{ attribute_id: 8, value_num: '12.500000' }, { attribute_id: 7, option_id: 71 }, { attribute_id: 7, option_id: 70 }] }
    const actionBar = { props: ['actions'], template: '<div />' }
    const wrapper = mount(ItemDetail, {
      global: {
        stubs: {
          ActionBar: actionBar, EmptyState: true, ItemDuplicateDialog: true, ItemTemplatesPanel: true,
          ProductRelationsPanel: true, RouterLink: { template: '<a><slot /></a>' },
        },
      },
    })
    await flushPromises()

    expect(wrapper.get('[data-test="sale-price-with-vat"]').text()).toBe('stock.item_detail.price_with_vat {"price":"1210.00"}')

    const levels = wrapper.get('[data-test="warehouse-levels"]')
    const rows = levels.findAll('tbody tr')
    expect(rows).toHaveLength(2)
    expect(rows[0]!.text()).toContain('Main')
    expect(rows[0]!.text()).toContain('500.00')
    expect(levels.get('tfoot').text()).toContain('3 pcs')
    expect(levels.get('tfoot').text()).toContain('600.00')
    expect(levels.get('tfoot').text()).toContain('1800.00')

    const parameters = wrapper.get('[data-test="item-parameters"]').findAll('dl > div').map(row => row.text())
    expect(parameters).toEqual(['ColourBlack, Red', 'Power12.5 kW'])
    expect(wrapper.get('[data-test="item-note"]').text()).toContain('Synthetic note')

    const actions = wrapper.findComponent(actionBar).props('actions') as Array<{ key: string; to?: { query?: Record<string, unknown> } }>
    expect(actions.find(a => a.key === 'new-receipt')?.to?.query).toEqual({ doc_type: 'receipt', stock_item_id: 42 })
    expect(actions.find(a => a.key === 'new-transfer')?.to?.query).toEqual({ doc_type: 'transfer', stock_item_id: 42 })
    wrapper.unmount()
    m.item = {}
    m.levels = []
    m.context = null
  })

  it('nenabídne příjemku ani převodku bez práva zapisovat skladové doklady', async () => {
    m.deniedWrite = new Set(['stock.documents.write'])
    const actionBar = { props: ['actions'], template: '<div />' }
    const wrapper = mount(ItemDetail, {
      global: { stubs: { ActionBar: actionBar, EmptyState: true, ItemDuplicateDialog: true, ItemTemplatesPanel: true, ProductRelationsPanel: true, RouterLink: { template: '<a><slot /></a>' } } },
    })
    await flushPromises()

    const actions = wrapper.findComponent(actionBar).props('actions') as Array<{ key: string; show?: boolean }>
    expect(actions.find(a => a.key === 'new-receipt')?.show).toBe(false)
    expect(actions.find(a => a.key === 'new-transfer')?.show).toBe(false)
    wrapper.unmount()
    m.deniedWrite = new Set()
  })

  it('u sazby s přenesenou daňovou povinností cenu s DPH neukáže', async () => {
    m.item = { sale_price_without_vat: '1000.00', effective_price: '1000.00', vat_rate_id: 2 }
    m.vatRates = [{ id: 2, rate_percent: 21, is_reverse_charge: true }]
    const wrapper = mount(ItemDetail, {
      global: { stubs: { ActionBar: true, EmptyState: true, ItemDuplicateDialog: true, ItemTemplatesPanel: true, ProductRelationsPanel: true, RouterLink: { template: '<a><slot /></a>' } } },
    })
    await flushPromises()

    expect(wrapper.find('[data-test="sale-price-with-vat"]').exists()).toBe(false)
    wrapper.unmount()
    m.item = {}
    m.vatRates = [{ id: 1, rate_percent: 21, is_reverse_charge: false }]
  })
})

describe('ItemDetail tabs', () => {
  it('přepíná záložky šipkami, Home a End a přesouvá fokus', async () => {
    const wrapper = mount(ItemDetail, {
      attachTo: document.body,
      global: {
        stubs: {
          ActionBar: true,
          EmptyState: true,
          ItemDuplicateDialog: true,
          ItemTemplatesPanel: true,
          ProductRelationsPanel: true,
          RouterLink: { template: '<a><slot /></a>' },
        },
      },
    })
    await flushPromises()

    let tabs = wrapper.findAll('button[role="tab"]')
    expect(tabs[0]!.attributes('aria-selected')).toBe('true')

    await tabs[0]!.trigger('keydown', { key: 'ArrowRight' })
    await flushPromises()
    tabs = wrapper.findAll('button[role="tab"]')
    expect(tabs[1]!.attributes('aria-selected')).toBe('true')
    expect(document.activeElement).toBe(tabs[1]!.element)

    await tabs[1]!.trigger('keydown', { key: 'Home' })
    await flushPromises()
    tabs = wrapper.findAll('button[role="tab"]')
    expect(tabs[0]!.attributes('aria-selected')).toBe('true')
    expect(document.activeElement).toBe(tabs[0]!.element)

    await tabs[0]!.trigger('keydown', { key: 'End' })
    await flushPromises()
    tabs = wrapper.findAll('button[role="tab"]')
    expect(tabs[1]!.attributes('aria-selected')).toBe('true')

    await tabs[1]!.trigger('keydown', { key: 'ArrowLeft' })
    await flushPromises()
    expect(wrapper.findAll('button[role="tab"]')[0]!.attributes('aria-selected')).toBe('true')
    wrapper.unmount()
  })
})

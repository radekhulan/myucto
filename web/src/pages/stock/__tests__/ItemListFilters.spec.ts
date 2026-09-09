import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { ref } from 'vue'

const m = vi.hoisted(() => ({
  listItems: vi.fn(),
  listWarehouses: vi.fn(),
  listManufacturers: vi.fn(),
  listCategories: vi.fn(),
  listTags: vi.fn(),
  listAttributes: vi.fn(),
  listAttributeOptions: vi.fn(),
  listClients: vi.fn(),
  canWrite: vi.fn(),
  savedConfig: undefined as any,
}))

vi.mock('@/api/stock', () => ({
  stockApi: { listItems: m.listItems, listWarehouses: m.listWarehouses },
}))
vi.mock('@/api/eshop', () => ({
  eshopApi: {
    listManufacturers: m.listManufacturers,
    listCategories: m.listCategories,
    listTags: m.listTags,
    listAttributes: m.listAttributes,
    listAttributeOptions: m.listAttributeOptions,
  },
}))
vi.mock('@/api/clients', () => ({ clientsApi: { list: m.listClients } }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ canRead: () => true, canWrite: m.canWrite }) }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ error: vi.fn() }) }))
vi.mock('@/composables/useFormat', () => ({ formatMoney: (value: string) => value }))
vi.mock('@/composables/useRowLink', () => ({ useRowLink: () => vi.fn() }))
vi.mock('@/composables/useTablePrefs', () => ({
  useTablePrefs: () => ({
    sort: ref(null), densityClass: ref(''), isVisible: () => true, toggleSort: vi.fn(),
  }),
}))
vi.mock('@/composables/useSavedFilters', () => ({
  useSavedFilters: (_key: string, config: any) => {
    m.savedConfig = config
    return { filters: ref([]), activeId: ref(null), applyDefaultIfAny: vi.fn(async () => false), clearActive: vi.fn(), apply: vi.fn() }
  },
  savedFilterTone: () => 'neutral',
}))
vi.mock('@/components/ui/buttonStyles', () => ({ ICONS: new Proxy({}, { get: () => 'M0 0' }), btnFilled: () => '', btnOutline: () => '' }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))
vi.mock('vue-router', () => ({ useRoute: () => ({ query: {} }), RouterLink: { template: '<a><slot /></a>' } }))

import ItemList from '../ItemList.vue'

const filterBar = { template: '<div><slot name="primary" /><slot /><slot name="actions" /></div>' }

describe('ItemList catalog filters', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.canWrite.mockReturnValue(false)
    m.listItems.mockResolvedValue({ data: [], meta: { total: 0, pages: 1 } })
    m.listWarehouses.mockResolvedValue([])
    m.listManufacturers.mockResolvedValue([{ id: 11, name: 'Acme', archived: false }])
    m.listCategories.mockResolvedValue([{ id: 12, name: 'Tools', path: '/12/', depth: 1, archived: false }])
    m.listTags.mockResolvedValue([{ id: 13, name: 'Sale', archived: false }])
    m.listAttributes.mockResolvedValue([{ id: 14, name: 'Colour', data_type: 'enum', is_filterable: true, archived: false }])
    m.listAttributeOptions.mockResolvedValue([{ id: 15, label: 'Blue' }])
    m.listClients.mockImplementation((params: { page?: number }) => Promise.resolve(params.page === 2
      ? { data: [{ id: 516, company_name: 'Vendor on page two' }], meta: { pages: 2 } }
      : { data: [{ id: 16, company_name: 'Vendor Ltd' }], meta: { pages: 2 } }))
  })

  it('loads codebooks and sends the selected catalog filters to the whole item list', async () => {
    const wrapper = mount(ItemList, {
      global: {
        stubs: {
          FilterBar: filterBar, SavedFiltersMenu: true, ColumnPicker: true, DensityToggle: true,
          SortableTh: true, EmptyState: true, RouterLink: { template: '<a><slot /></a>' },
        },
      },
    })
    await flushPromises()
    expect(m.listAttributeOptions).not.toHaveBeenCalled()
    expect(wrapper.text()).not.toContain('/12/')

    await wrapper.get('select[title="stock.items.filter_manufacturer"]').setValue('11')
    await wrapper.get('select[title="stock.items.filter_vendor"]').setValue('516')
    await wrapper.get('select[title="stock.items.filter_category"]').setValue('12')
    await wrapper.get('select[title="stock.items.filter_availability"]').setValue('out_of_stock')
    await wrapper.get('select[title="stock.items.filter_tags"]').setValue(['13'])
    await wrapper.get('select[title="stock.items.filter_attribute"]').setValue('14')
    await wrapper.findAll('select').find(select => select.find('option[value="15"]').exists())!.setValue('15')
    await flushPromises()

    expect(m.listItems).toHaveBeenLastCalledWith(expect.objectContaining({
      manufacturer_id: 11,
      vendor_id: 516,
      category_id: 12,
      availability: 'out_of_stock',
      tag_ids: [13],
      attribute_filters: [{ attribute_id: 14, option_id: 15 }],
      page: 1,
    }), expect.objectContaining({ signal: expect.any(AbortSignal) }))

    await wrapper.get('select[title="stock.items.filter_attribute"]').setValue('')
    await flushPromises()
    expect(m.listItems).toHaveBeenLastCalledWith(expect.objectContaining({
      attribute_filters: [],
      page: 1,
    }), expect.anything())
  })

  it('keeps an attribute value restored from a saved view and lazily loads its options', async () => {
    mount(ItemList, { global: { stubs: { FilterBar: filterBar, SavedFiltersMenu: true, ColumnPicker: true, DensityToggle: true, SortableTh: true, EmptyState: true, RouterLink: true } } })
    await flushPromises()

    m.savedConfig.applyQuery({ attribute: '14:15' })
    await flushPromises()

    expect(m.listAttributeOptions).toHaveBeenCalledWith(14)
    expect(m.listItems).toHaveBeenLastCalledWith(expect.objectContaining({
      attribute_filters: [{ attribute_id: 14, option_id: 15 }],
    }), expect.anything())
  })

  it('uses explicit all-matching selection with the current filters and resets it when a filter changes', async () => {
    m.canWrite.mockReturnValue(true)
    m.listItems.mockResolvedValue({ data: [{ id: 31, sku: 'TEST-31', name: 'Test item', item_type: 'goods', unit: 'ks', is_active: true, min_qty: null }], meta: { total: 125, pages: 3 } })
    const dialog = { props: ['selection', 'selectedCount'], template: '<div data-test="bulk-dialog" />' }
    const wrapper = mount(ItemList, { global: { stubs: { FilterBar: filterBar, SavedFiltersMenu: true, ColumnPicker: true, DensityToggle: true, SortableTh: true, EmptyState: true, RouterLink: true, CatalogBulkDialog: dialog } } })
    await flushPromises()

    const buttons = wrapper.findAll('button')
    await buttons[0]!.trigger('click')
    await buttons[1]!.trigger('click')
    await buttons[2]!.trigger('click')
    const selection = wrapper.findComponent(dialog).props('selection') as any
    expect(selection).toEqual(expect.objectContaining({ all_matching: true, excluded_ids: [], filters: expect.objectContaining({ active: true }) }))

    await wrapper.get('select[title="stock.items.filter_manufacturer"]').setValue('11')
    await flushPromises()
    expect(wrapper.findComponent(dialog).props('selectedCount')).toBe(0)
  })

  it('opens quick detail with a frozen explicit selection only when the opened item is selected', async () => {
    m.canWrite.mockReturnValue(true)
    m.listItems.mockResolvedValue({ data: [{ id: 31, sku: 'TEST-31', name: 'Test item', item_type: 'goods', unit: 'ks', is_active: true, min_qty: null }], meta: { total: 125, pages: 3 } })
    const quickDrawer = { props: ['itemId', 'neighbors'], template: '<div data-test="quick-drawer" />' }
    const wrapper = mount(ItemList, { global: { stubs: { FilterBar: filterBar, SavedFiltersMenu: true, ColumnPicker: true, DensityToggle: true, SortableTh: true, EmptyState: true, RouterLink: { template: '<a><slot /></a>' }, ItemQuickDetailDrawer: quickDrawer } } })
    await flushPromises()

    await wrapper.findAll('button').find(button => button.text().includes('stock.items.bulk.select_page'))!.trigger('click')
    await wrapper.get('button[title="stock.items.quick_detail.open"]').trigger('click')

    expect(wrapper.findComponent(quickDrawer).props('itemId')).toBe(31)
    expect(wrapper.findComponent(quickDrawer).props('neighbors')).toEqual(expect.objectContaining({ ids: [31], active: true }))
  })
})

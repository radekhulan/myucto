import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { reactive, ref } from 'vue'

const m = vi.hoisted(() => ({
  listItems: vi.fn(),
  listWarehouses: vi.fn(),
  listManufacturers: vi.fn(),
  listCategories: vi.fn(),
  listTags: vi.fn(),
  listAttributes: vi.fn(),
  listAttributeOptions: vi.fn(),
  listClients: vi.fn(),
  getClient: vi.fn(),
  applyDefault: vi.fn(),
  canWrite: vi.fn(),
  savedConfig: undefined as any,
  tableOptions: {} as Record<string, unknown>,
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
vi.mock('@/api/clients', () => ({ clientsApi: { list: m.listClients, get: m.getClient } }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ canRead: () => true, canWrite: m.canWrite }) }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ error: vi.fn() }) }))
vi.mock('@/composables/useFormat', () => ({ formatMoney: (value: string) => value }))
vi.mock('@/composables/useRowLink', () => ({ useRowLink: () => vi.fn() }))
vi.mock('@/composables/useTablePrefs', () => ({
  useTablePrefs: () => {
    const options = reactive(m.tableOptions)
    return {
      sort: ref(null), densityClass: ref(''), isVisible: () => true, toggleSort: vi.fn(),
      option: (key: string, fallback: unknown) => options[key] ?? fallback,
      setOption: (key: string, value: unknown) => { options[key] = value },
    }
  },
}))
vi.mock('@/composables/useSavedFilters', () => ({
  useSavedFilters: (_key: string, config: any) => {
    m.savedConfig = config
    return { filters: ref([]), activeId: ref(null), applyDefaultIfAny: m.applyDefault, clearActive: vi.fn(), apply: vi.fn() }
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
    for (const key of Object.keys(m.tableOptions)) delete m.tableOptions[key]
    m.canWrite.mockReturnValue(false)
    m.listItems.mockResolvedValue({ data: [], meta: { total: 0, pages: 1 } })
    m.listWarehouses.mockResolvedValue([])
    m.listManufacturers.mockResolvedValue([{ id: 11, name: 'Acme', archived: false }])
    m.listCategories.mockResolvedValue([{ id: 12, name: 'Tools', path: '/12/', depth: 1, archived: false }])
    m.listTags.mockResolvedValue([{ id: 13, name: 'Sale', archived: false }])
    m.listAttributes.mockResolvedValue([{ id: 14, name: 'Colour', data_type: 'enum', is_filterable: true, archived: false }])
    m.listAttributeOptions.mockResolvedValue([{ id: 15, label: 'Blue' }])
    m.applyDefault.mockResolvedValue(false)
    m.getClient.mockResolvedValue({ id: 516, company_name: 'Vendor on page two' })
    m.listClients.mockImplementation((params: { q?: string }) => Promise.resolve(params.q
      ? { data: [{ id: 516, company_name: 'Vendor on page two' }], meta: { pages: 1 } }
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
    const vendor = wrapper.get('input[aria-label="stock.items.filter_vendor"]')
    expect(m.listClients).not.toHaveBeenCalled()
    await vendor.trigger('focus')
    await flushPromises()
    expect(m.listClients).toHaveBeenCalledExactlyOnceWith({ q: undefined, role: 'vendors', per_page: 50, sort: 'name' })
    expect(wrapper.get('[data-test="searchable-select-truncated"]').text()).toBe('stock.items.vendor_results_truncated')
    await vendor.setValue('page two')
    await vi.waitFor(() => expect(m.listClients).toHaveBeenLastCalledWith({ q: 'page two', role: 'vendors', per_page: 50, sort: 'name' }))
    await flushPromises()
    await wrapper.get('button[role="option"]').trigger('click')
    expect(m.getClient).not.toHaveBeenCalled()
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

  it('loads the first page without waiting for codebooks or loading the vendor directory', async () => {
    m.listManufacturers.mockReturnValue(new Promise(() => {}))
    m.listAttributes.mockReturnValue(new Promise(() => {}))
    const wrapper = mount(ItemList, { global: { stubs: { FilterBar: filterBar, SavedFiltersMenu: true, ColumnPicker: true, DensityToggle: true, SortableTh: true, EmptyState: true, RouterLink: true } } })
    await flushPromises()

    expect(m.listItems).toHaveBeenCalledTimes(1)
    expect(m.listItems).toHaveBeenCalledBefore(m.listManufacturers)
    expect(m.listClients).not.toHaveBeenCalled()
    wrapper.unmount()
  })

  it('applies a pinned vendor before that vendor name loads', async () => {
    let resolveVendor!: (vendor: { id: number; company_name: string }) => void
    m.getClient.mockReturnValue(new Promise(resolve => { resolveVendor = resolve }))
    m.applyDefault.mockImplementationOnce(async () => {
      m.savedConfig.applyQuery({ vendor_id: '516' })
      return true
    })
    const wrapper = mount(ItemList, { global: { stubs: { FilterBar: filterBar, SavedFiltersMenu: true, ColumnPicker: true, DensityToggle: true, SortableTh: true, EmptyState: true, RouterLink: true } } })
    await flushPromises()

    expect(m.listItems).toHaveBeenCalledExactlyOnceWith(expect.objectContaining({ vendor_id: 516 }), expect.anything())
    expect(m.listClients).not.toHaveBeenCalled()
    expect(m.getClient).toHaveBeenCalledExactlyOnceWith(516)
    const input = wrapper.get<HTMLInputElement>('input[aria-label="stock.items.filter_vendor"]')
    expect(input.element.value).toBe('#516')
    resolveVendor({ id: 516, company_name: 'Vendor on page two' })
    await flushPromises()
    expect(input.element.value).toBe('Vendor on page two')
    wrapper.unmount()
  })

  it('shows the chosen parameter in its own column and asks the server only for that value', async () => {
    m.listAttributes.mockResolvedValue([
      { id: 14, name: 'Colour', data_type: 'enum', is_filterable: true, archived: false },
      { id: 17, name: 'Power', data_type: 'number', unit: 'kW', is_filterable: false, archived: false },
    ])
    m.listItems.mockResolvedValue({ data: [{ id: 51, sku: 'PARAM-51', name: 'Param item', item_type: 'goods', unit: 'ks', is_active: true, min_qty: null, display_attribute_value: '12.5' }], meta: { total: 1, pages: 1 } })
    const wrapper = mount(ItemList, { global: { stubs: { FilterBar: filterBar, SavedFiltersMenu: true, ColumnPicker: true, DensityToggle: true, SortableTh: true, EmptyState: true, RouterLink: { template: '<a><slot /></a>' } } } })
    await flushPromises()

    expect(m.listItems).toHaveBeenLastCalledWith(expect.objectContaining({ display_attribute_id: undefined }), expect.anything())
    const select = wrapper.get('select[data-test="display-attribute"]')
    expect(select.findAll('option').map(option => option.text())).toContain('Power')
    await select.setValue('17')
    await flushPromises()

    expect(m.tableOptions.display_attribute_id).toBe(17)
    expect(m.listItems).toHaveBeenLastCalledWith(expect.objectContaining({ display_attribute_id: 17, page: 1 }), expect.anything())
    expect(wrapper.get('[data-test="display-attribute-value"]').text()).toBe('12.5 kW')
  })

  it('translates a yes/no parameter column from the neutral API value', async () => {
    m.listAttributes.mockResolvedValue([{ id: 18, name: 'New', data_type: 'bool', is_filterable: false, archived: false }])
    m.listItems.mockResolvedValue({ data: [{ id: 52, sku: 'PARAM-52', name: 'Bool item', item_type: 'goods', unit: 'ks', is_active: true, min_qty: null, display_attribute_value: 'true, false' }], meta: { total: 1, pages: 1 } })
    m.tableOptions.display_attribute_id = 18
    const wrapper = mount(ItemList, { global: { stubs: { FilterBar: filterBar, SavedFiltersMenu: true, ColumnPicker: true, DensityToggle: true, SortableTh: true, EmptyState: true, RouterLink: { template: '<a><slot /></a>' } } } })
    await flushPromises()

    expect(wrapper.get('[data-test="display-attribute-value"]').text()).toBe('common.yes, common.no')
  })

  it('drops a remembered parameter column choice when the parameter no longer exists', async () => {
    m.tableOptions.display_attribute_id = 99
    mount(ItemList, { global: { stubs: { FilterBar: filterBar, SavedFiltersMenu: true, ColumnPicker: true, DensityToggle: true, SortableTh: true, EmptyState: true, RouterLink: true } } })
    await flushPromises()

    expect(m.tableOptions.display_attribute_id).toBeNull()
    expect(m.listItems).toHaveBeenLastCalledWith(expect.objectContaining({ display_attribute_id: undefined }), expect.anything())
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

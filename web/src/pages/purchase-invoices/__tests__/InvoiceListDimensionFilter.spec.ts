import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { ref } from 'vue'

const mocks = vi.hoisted(() => ({
  list: vi.fn(),
  apply: null as ((query: Record<string, string>) => void) | null,
  getQuery: null as (() => Record<string, string>) | null,
}))
vi.mock('@/api/purchaseInvoices', async original => {
  const actual = await original<typeof import('@/api/purchaseInvoices')>()
  return { ...actual, purchaseInvoicesApi: { ...actual.purchaseInvoicesApi, listGrouped: mocks.list, listImportBatches: async () => [], get: vi.fn() } }
})
vi.mock('@/api/clients', () => ({ clientsApi: { list: async () => ({ data: [] }) } }))
vi.mock('@/api/projects', () => ({ projectsApi: { list: async () => ({ data: [] }) } }))
vi.mock('@/api/accounting', () => ({ accountingApi: {}, postingErrorI18nKey: () => '' }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ isClientRole: false, canRead: () => true, canWrite: () => true }) }))
vi.mock('@/stores/supplier', () => ({ useSupplierStore: () => ({}) }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ error: vi.fn(), success: vi.fn() }) }))
vi.mock('@/composables/useYearOptions', () => ({ useYearOptions: () => [] }))
vi.mock('@/composables/useListKeyboard', () => ({ useListKeyboard: () => ({ activeIndex: -1 }) }))
vi.mock('@/composables/useUserPrefs', () => ({ ensurePrefsLoaded: vi.fn().mockResolvedValue(undefined), getPagePrefs: () => ref({}) }))
vi.mock('@/composables/useTablePrefs', () => ({ useTablePrefs: () => ({
  sort: ref(null), flag: () => true, isVisible: () => false,
}) }))
vi.mock('@/composables/useSavedFilters', () => ({
  useSavedFilters: (_page: string, opts: { getQuery: () => Record<string, string>, applyQuery: (query: Record<string, string>) => void }) => {
    mocks.apply = opts.applyQuery
    mocks.getQuery = opts.getQuery
    return { activeId: { value: null }, filters: { value: [] }, applyDefaultIfAny: async () => false }
  },
  savedFilterTone: () => 'neutral',
}))
// Dimenze zapnuté: hodnota 5 typu Středisko.
vi.mock('@/composables/useDimensions', () => ({
  useDimensions: () => ({
    enabled: { value: true }, load: () => Promise.resolve(),
    valueById: { value: new Map([[5, { id: 5, type_id: 1 }]]) },
    typeById: { value: new Map([[1, { id: 1, name: 'Středisko' }]]) },
    valueLabel: (id: number) => (id === 5 ? 'S5 – Dílna' : `#${id}`),
  }),
}))
vi.mock('vue-i18n', async importOriginal => ({
  ...await importOriginal<typeof import('vue-i18n')>(),
  useI18n: () => ({ t: (key: string) => key, locale: { value: 'cs' } }),
}))
import InvoiceList from '../InvoiceList.vue'
import DimensionReportFilters from '@/components/dimensions/DimensionReportFilters.vue'

async function open(query: Record<string, string> = {}, renderUi = false) {
  const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/purchase-invoices', component: { render: () => null } }, { path: '/purchase-invoices/new', component: { render: () => null } }] })
  await router.push({ path: '/purchase-invoices', query })
  const wrapper = mount(renderUi ? InvoiceList : { ...InvoiceList, render: () => null }, { global: {
    plugins: [router],
    directives: { 'column-labels': {} },
    stubs: renderUi ? {
      SavedFiltersMenu: true, ColumnPicker: true, DensityToggle: true,
      TableColorsMenu: true, SearchableSelect: true, DateInput: true,
      BulkActionBar: true, InvoiceCalendar: true, DimensionReportFilters: true,
    } : {},
  } })
  await flushPromises()
  return { wrapper, router }
}

type Vm = {
  dimensionValueIds: number[]
  dimensionDescendants: boolean
  activeFilterCount: number
  filterChips: { key: string, label?: string, value: string }[]
  clearFilter: (key: string) => void
  purchaseBookUrl: (format: 'pdf' | 'xlsx') => string
}

describe('přijaté faktury – filtr dimenze', () => {
  beforeEach(() => {
    vi.useFakeTimers()
    mocks.list.mockReset().mockResolvedValue({ data: [], meta: { total: 0, pages: 1 } })
  })
  afterEach(() => { vi.useRealTimers() })

  it('převezme dimenzi z URL, pošle ji seznamu, knize i do uložených filtrů a ukáže chip', async () => {
    const { wrapper } = await open({ year: '2099', dimension_value_id: '5', dimension_descendants: '0' })
    const vm = wrapper.vm as unknown as Vm
    expect(mocks.list).toHaveBeenCalledTimes(1)
    expect(mocks.list).toHaveBeenLastCalledWith(expect.objectContaining({ year: 2099, dimension_value_id: '5', dimension_descendants: false }))
    expect(vm.activeFilterCount).toBe(1)
    const chip = vm.filterChips.find(c => c.key === 'dimension')!
    expect(chip.label).toBe('Středisko')
    expect(chip.value).toContain('S5 – Dílna')
    expect(mocks.getQuery!()).toEqual({ year: '2099', dimension_value_id: '5', dimension_descendants: '0' })

    const book = new URL(vm.purchaseBookUrl('xlsx'), 'https://x.test')
    expect(book.searchParams.get('filter[dimension_value_id]')).toBe('5')
    expect(book.searchParams.get('filter[dimension_descendants]')).toBe('0')
    wrapper.unmount()
  })

  it('smazání chipu filtr zruší a odebere z URL', async () => {
    const { wrapper, router } = await open({ year: '2099', dimension_value_id: '5' })
    const vm = wrapper.vm as unknown as Vm
    expect(mocks.list).toHaveBeenLastCalledWith(expect.objectContaining({ dimension_value_id: '5', dimension_descendants: true }))
    vm.clearFilter('dimension')
    await flushPromises()
    expect(mocks.list).toHaveBeenLastCalledWith(expect.objectContaining({ dimension_value_id: undefined }))
    expect(router.currentRoute.value.query).toEqual({ year: '2099' })
    wrapper.unmount()
  })

  it('výběr ve filtru přenačte seznam a zapíše URL; menu bez query filtr vyčistí', async () => {
    const { wrapper, router } = await open({ year: '2099' }, true)
    const filter = wrapper.findComponent(DimensionReportFilters)
    expect(filter.exists()).toBe(true)
    expect(wrapper.text()).toContain('dimensions.document_filter_hint')
    filter.vm.$emit('update:valueIds', [5])
    await flushPromises()
    expect(mocks.list).toHaveBeenLastCalledWith(expect.objectContaining({ dimension_value_id: '5' }))
    expect(router.currentRoute.value.query).toEqual({ year: '2099', dimension_value_id: '5' })
    filter.vm.$emit('update:descendants', false)
    await flushPromises()
    expect(router.currentRoute.value.query).toEqual({ year: '2099', dimension_value_id: '5', dimension_descendants: '0' })

    await router.push('/purchase-invoices')
    await flushPromises()
    await vi.advanceTimersByTimeAsync(1)
    expect(mocks.list).toHaveBeenLastCalledWith(expect.objectContaining({ dimension_value_id: undefined }))
    expect((wrapper.vm as unknown as Vm).dimensionDescendants).toBe(true)
    wrapper.unmount()
  })
})

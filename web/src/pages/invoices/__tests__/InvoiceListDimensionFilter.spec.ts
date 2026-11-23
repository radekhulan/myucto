import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { ref } from 'vue'

const mocks = vi.hoisted(() => ({
  list: vi.fn(),
  apply: null as ((query: Record<string, string>) => void) | null,
  getQuery: null as (() => Record<string, string>) | null,
}))
vi.mock('@/api/invoices', async original => {
  const actual = await original<typeof import('@/api/invoices')>()
  return { ...actual, invoicesApi: { ...actual.invoicesApi, listGrouped: mocks.list } }
})
vi.mock('@/api/clients', () => ({ clientsApi: { list: async () => ({ data: [] }) } }))
vi.mock('@/api/codebooks', () => ({ codebooksApi: { currencies: async () => [] } }))
vi.mock('@/api/revenueCategories', () => ({ revenueCategoriesApi: { list: async () => [] } }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ canRead: () => true, canWrite: () => true }) }))
vi.mock('@/stores/supplier', () => ({ useSupplierStore: () => ({}) }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ error: vi.fn() }) }))
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
    return { applyDefaultIfAny: async () => false }
  },
  savedFilterTone: () => 'neutral',
}))
// Dimenze zapnuté: hodnota 5 typu Projekt.
vi.mock('@/composables/useDimensions', () => ({
  useDimensions: () => ({
    enabled: { value: true }, load: () => Promise.resolve(),
    valueById: { value: new Map([[5, { id: 5, type_id: 1 }]]) },
    typeById: { value: new Map([[1, { id: 1, name: 'Projekt' }]]) },
    valueLabel: (id: number) => (id === 5 ? 'P5 – Projekt pět' : `#${id}`),
  }),
}))
vi.mock('vue-i18n', async importOriginal => ({
  ...await importOriginal<typeof import('vue-i18n')>(),
  useI18n: () => ({ t: (key: string) => key }),
}))
import InvoiceList from '../InvoiceList.vue'

async function open(query: Record<string, string> = {}) {
  const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/invoices', component: { render: () => null } }] })
  await router.push({ path: '/invoices', query })
  const wrapper = mount({ ...InvoiceList, render: () => null }, { global: { plugins: [router] } })
  await flushPromises()
  return { wrapper, router }
}

type Vm = {
  dimensionValueIds: number[]
  dimensionDescendants: boolean
  activeFilterCount: number
  filterChips: { key: string, label?: string, value: string }[]
  clearFilter: (key: string) => void
  invoiceBookUrl: (format: 'pdf' | 'xlsx') => string
}

describe('vydané faktury – filtr dimenze', () => {
  beforeEach(() => {
    vi.useFakeTimers()
    mocks.list.mockReset().mockResolvedValue({ data: [], meta: { total: 0, pages: 1 } })
  })
  afterEach(() => { vi.useRealTimers() })

  it('převezme dimenzi z URL, pošle ji seznamu, knize i do uložených filtrů a ukáže chip', async () => {
    const { wrapper } = await open({ dimension_value_id: '5', dimension_descendants: '0' })
    const vm = wrapper.vm as unknown as Vm
    expect(mocks.list).toHaveBeenCalledTimes(1)
    expect(mocks.list).toHaveBeenLastCalledWith(expect.objectContaining({ dimension_value_id: '5', dimension_descendants: false }))
    expect(vm.activeFilterCount).toBe(1)
    const chip = vm.filterChips.find(c => c.key === 'dimension')!
    expect(chip.label).toBe('Projekt')
    expect(chip.value).toContain('P5 – Projekt pět')
    expect(chip.value).toContain('accounting.journal.filter_dimension_only_value')
    expect(mocks.getQuery!()).toEqual({ dimension_value_id: '5', dimension_descendants: '0' })

    const book = new URL(vm.invoiceBookUrl('pdf'), 'https://x.test')
    expect(book.searchParams.get('filter[dimension_value_id]')).toBe('5')
    expect(book.searchParams.get('filter[dimension_descendants]')).toBe('0')
    wrapper.unmount()
  })

  it('výběr hodnoty přenačte seznam a zapíše URL, smazání chipu filtr zruší', async () => {
    const { wrapper, router } = await open()
    const vm = wrapper.vm as unknown as Vm
    expect(mocks.list).toHaveBeenLastCalledWith(expect.objectContaining({ dimension_value_id: undefined }))

    vm.dimensionValueIds = [5, 7]
    await flushPromises()
    expect(mocks.list).toHaveBeenLastCalledWith(expect.objectContaining({ dimension_value_id: '5,7', dimension_descendants: true }))
    expect(router.currentRoute.value.query).toEqual({ dimension_value_id: '5,7' })

    vm.dimensionDescendants = false
    await flushPromises()
    expect(router.currentRoute.value.query).toEqual({ dimension_value_id: '5,7', dimension_descendants: '0' })
    expect(mocks.list).toHaveBeenLastCalledWith(expect.objectContaining({ dimension_value_id: '5,7', dimension_descendants: false }))

    vm.clearFilter('dimension')
    await flushPromises()
    expect(mocks.list).toHaveBeenLastCalledWith(expect.objectContaining({ dimension_value_id: undefined, dimension_descendants: undefined }))
    expect(router.currentRoute.value.query).toEqual({})
    expect(vm.dimensionDescendants).toBe(true)
    wrapper.unmount()
  })

  it('uložený filtr s dimenzí obnoví výběr', async () => {
    const { wrapper } = await open()
    mocks.apply!({ dimension_value_id: '5,abc,5,9', dimension_descendants: '0' })
    await flushPromises()
    expect((wrapper.vm as unknown as Vm).dimensionValueIds).toEqual([5, 9])
    expect(mocks.list).toHaveBeenLastCalledWith(expect.objectContaining({ dimension_value_id: '5,9', dimension_descendants: false }))
    wrapper.unmount()
  })
})

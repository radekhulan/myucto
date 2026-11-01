import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { ref } from 'vue'

const mocks = vi.hoisted(() => ({
  list: vi.fn(),
  defaultQuery: null as Record<string, string> | null,
  apply: null as ((query: Record<string, string>) => void) | null,
}))
vi.mock('@/api/purchaseInvoices', () => ({
  purchaseInvoicesApi: { listGrouped: mocks.list, listImportBatches: async () => [], get: vi.fn() },
}))
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
  useSavedFilters: (_page: string, opts: { applyQuery: (query: Record<string, string>) => void }) => {
    mocks.apply = opts.applyQuery
    return {
      activeId: { value: null },
      filters: { value: [] },
      applyDefaultIfAny: async () => {
        if (!mocks.defaultQuery) return false
        opts.applyQuery(mocks.defaultQuery)
        return true
      },
    }
  },
  savedFilterTone: () => 'neutral',
}))
vi.mock('vue-i18n', async importOriginal => ({
  ...await importOriginal<typeof import('vue-i18n')>(),
  useI18n: () => ({ t: (key: string) => key, locale: { value: 'cs' } }),
}))
import InvoiceList from '../InvoiceList.vue'

async function open(query: Record<string, string> = {}, renderUi = false) {
  const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/purchase-invoices', component: { render: () => null } }, { path: '/purchase-invoices/new', component: { render: () => null } }] })
  await router.push({ path: '/purchase-invoices', query })
  const wrapper = mount(renderUi ? InvoiceList : { ...InvoiceList, render: () => null }, { global: {
    plugins: [router],
    directives: { 'column-labels': {} },
    stubs: renderUi ? {
      SavedFiltersMenu: true, ColumnPicker: true, DensityToggle: true,
      TableColorsMenu: true, SearchableSelect: true, DateInput: true,
      BulkActionBar: true, InvoiceCalendar: true,
    } : {},
  } })
  await flushPromises()
  return { wrapper, router }
}

describe('purchase invoice list loading', () => {
  beforeEach(() => {
    vi.useFakeTimers()
    mocks.defaultQuery = null
    mocks.list.mockReset().mockResolvedValue({ data: [], meta: { total: 0, pages: 1 } })
  })
  afterEach(() => { vi.useRealTimers() })

  it.each<Record<string, string>>([{}, { year: 'all' }, { year: 'all', vendor: '7', q: 'synthetic' }])('loads the list once when opened with query %j', async query => {
    const { wrapper } = await open(query)
    await vi.advanceTimersByTimeAsync(350)
    expect(mocks.list).toHaveBeenCalledTimes(1)
    wrapper.unmount()
  })

  it('applies a saved default and another saved view once each', async () => {
    mocks.defaultQuery = { year: 'all', unpaid: '1' }
    const { wrapper } = await open()
    await vi.advanceTimersByTimeAsync(350)
    expect(mocks.list).toHaveBeenCalledTimes(1)
    mocks.apply!({ status: 'paid' })
    await flushPromises()
    await vi.advanceTimersByTimeAsync(350)
    expect(mocks.list).toHaveBeenCalledTimes(2)
    expect(mocks.list).toHaveBeenLastCalledWith(expect.objectContaining({ status: 'paid' }))
    wrapper.unmount()
  })

  it('renders both selectors and shows a clearable empty result', async () => {
    const { wrapper } = await open({ year: '2099' }, true)
    const group = wrapper.get('fieldset[data-test="document-sources-filters"]')
    expect(group.get('legend').text()).toBe('purchase_invoice.filters.document_sources')
    const visual = group.get('[data-test="has_visual_document-filter"]')
    const structured = group.get('[data-test="has_structured_source-filter"]')
    expect(visual.findAll('option').map(o => o.element.value)).toEqual(['', '1', '0'])
    expect(structured.findAll('option').map(o => o.element.value)).toEqual(['', '1', '0'])
    await visual.setValue('0')
    await structured.setValue('1')
    await flushPromises()
    expect(mocks.list).toHaveBeenLastCalledWith(expect.objectContaining({ has_visual_document: '0', has_structured_source: '1' }))
    expect(wrapper.text()).toContain('purchase_invoice.empty_filtered')
    expect(wrapper.text()).toContain('purchase_invoice.filters.has_visual_document')
    expect(wrapper.text()).toContain('purchase_invoice.filters.has_structured_source')
    wrapper.unmount()
  })

  it('keeps both document filters, year and status in URL and saved views', async () => {
    const { wrapper, router } = await open({ year: '2099', status: 'paid', has_visual_document: '0', has_structured_source: '1' })
    const vm = wrapper.vm as unknown as {
      visualDocumentFilter: string; structuredSourceFilter: string
      activeFilterCount: number; filterChips: { key: string }[]
      buildQuery: () => Record<string, string>; clearFilter: (key: string) => void; clearAllFilters: () => void
    }
    expect(mocks.list).toHaveBeenLastCalledWith(expect.objectContaining({ year: 2099, status: 'paid', has_visual_document: '0', has_structured_source: '1' }))
    expect(vm.activeFilterCount).toBe(3)
    expect(vm.filterChips.map(c => c.key)).toEqual(expect.arrayContaining(['has_visual_document', 'has_structured_source']))
    expect(vm.buildQuery()).toEqual({ year: '2099', status: 'paid', has_visual_document: '0', has_structured_source: '1' })
    vm.clearFilter('has_visual_document')
    await flushPromises()
    expect(router.currentRoute.value.query).toEqual({ year: '2099', status: 'paid', has_structured_source: '1' })
    mocks.apply!({ year: '2099', has_visual_document: '1', has_structured_source: '0' })
    await flushPromises()
    await vi.advanceTimersByTimeAsync(1)
    expect(vm.visualDocumentFilter).toBe('1')
    expect(vm.structuredSourceFilter).toBe('0')
    expect(mocks.list).toHaveBeenLastCalledWith(expect.objectContaining({ has_visual_document: '1', has_structured_source: '0' }))
    vm.clearAllFilters()
    await flushPromises()
    expect(router.currentRoute.value.query).toEqual({ year: '2099' })
    expect(mocks.list).toHaveBeenLastCalledWith(expect.objectContaining({ year: 2099, has_visual_document: undefined, has_structured_source: undefined }))
    wrapper.unmount()
  })

  it('defaults to all documents and resets document filters on menu navigation', async () => {
    const { wrapper, router } = await open({ has_visual_document: '0', has_structured_source: '1' })
    await router.push('/purchase-invoices')
    await flushPromises()
    expect(mocks.list).toHaveBeenLastCalledWith(expect.objectContaining({ has_visual_document: undefined, has_structured_source: undefined }))
    wrapper.unmount()
  })

  it('keeps the newest result when an older response arrives later', async () => {
    let resolveOld: (v: unknown) => void = () => {}
    mocks.list.mockReset()
      .mockImplementationOnce(() => new Promise(r => { resolveOld = r }))
      .mockResolvedValue({ data: [{ month: '2099-02', invoices: [] }], meta: { total: 5, pages: 1 } })
    const { wrapper } = await open()
    const vm = wrapper.vm as unknown as { statusFilter: string; total: number }
    vm.statusFilter = 'paid'
    await flushPromises()
    resolveOld({ data: [], meta: { total: 99, pages: 1 } })
    await flushPromises()
    expect(vm.total).toBe(5)
    wrapper.unmount()
  })
})

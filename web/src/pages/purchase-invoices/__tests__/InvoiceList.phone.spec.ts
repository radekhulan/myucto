import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { ref } from 'vue'

// Telefon: patička rozbalených filtrů (Zavřít / Zobrazit) a zaškrtávátko karty
// s plochou 44 px, jehož klepnutí neotevře doklad. Vše přes max-md: třídy.

const mocks = vi.hoisted(() => ({ list: vi.fn() }))
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
  sort: ref(null), flag: () => true, isVisible: () => false, setFlag: vi.fn(),
  toggleSort: vi.fn(), clearSort: vi.fn(), densityClass: ref(''), orderedColumns: ref([]),
}) }))
vi.mock('@/composables/useSavedFilters', () => ({
  useSavedFilters: () => ({
    activeId: { value: null },
    filters: { value: [] },
    applyDefaultIfAny: async () => false,
  }),
  savedFilterTone: () => 'neutral',
}))
vi.mock('vue-i18n', async importOriginal => ({
  ...await importOriginal<typeof import('vue-i18n')>(),
  useI18n: () => ({ t: (key: string, p?: Record<string, unknown>) => p && 'count' in p ? `${key}:${p.count}` : key, locale: { value: 'cs' } }),
}))
import InvoiceList from '../InvoiceList.vue'

function invoice(id: number) {
  return {
    id, varsymbol: `2099${id}`, vendor_invoice_number: `FP-${id}`, vendor_company_name: 'Velmi dlouhý název syntetického dodavatele s.r.o.',
    vendor_id: 5, document_kind: 'invoice', status: 'received', issue_date: '2099-01-02', due_date: '2099-01-20',
    total_with_vat: 1210, total_without_vat: 1000, amount_to_pay: 1210, currency: 'CZK', locked: null,
  }
}

async function open() {
  const router = createRouter({ history: createMemoryHistory(), routes: [
    { path: '/purchase-invoices', component: { render: () => null } },
    { path: '/purchase-invoices/:id', component: { render: () => null } },
  ] })
  await router.push('/purchase-invoices')
  const wrapper = mount(InvoiceList, { attachTo: document.body, global: {
    plugins: [router],
    directives: { 'column-labels': {} },
    stubs: {
      SavedFiltersMenu: true, ColumnPicker: true, DensityToggle: true,
      TableColorsMenu: true, SearchableSelect: true, DateInput: true,
      BulkActionBar: true, InvoiceCalendar: true,
    },
  } })
  await flushPromises()
  return { wrapper, router }
}

describe('seznam přijatých faktur - telefon', () => {
  beforeEach(() => {
    mocks.list.mockReset().mockResolvedValue({ data: [{ month: '2099-01', invoices: [invoice(1)] }], meta: { total: 1, pages: 1 } })
  })
  afterEach(() => { document.body.innerHTML = '' })

  it('patička filtrů je jen pro telefon, přilepená nad spodní lištou a zavře rozbalené filtry', async () => {
    const { wrapper } = await open()
    const toggle = wrapper.findAll('button[aria-expanded]').find(b => b.text().includes('common.filters'))!
    await toggle.trigger('click')
    expect(toggle.attributes('aria-expanded')).toBe('true')
    const footer = wrapper.get('[data-test="phone-filter-footer"]')
    expect(footer.classes()).toEqual(expect.arrayContaining(['hidden', 'max-md:flex', 'max-md:sticky', 'max-md:bottom-[calc(var(--app-footer-height,0px)+0.5rem)]']))
    expect(footer.get('[data-test="phone-filter-show"]').text()).toBe('purchase_invoice.filters.show_results:1')
    await footer.get('[data-test="phone-filter-show"]').trigger('click')
    expect(toggle.attributes('aria-expanded')).toBe('false')
    await toggle.trigger('click')
    await wrapper.get('[data-test="phone-filter-close"]').trigger('click')
    expect(toggle.attributes('aria-expanded')).toBe('false')
    wrapper.unmount()
  })

  it('zaškrtávátko karty má telefonní plochu 44 px a klepnutí na ni neotevře doklad', async () => {
    const { wrapper, router } = await open()
    const hit = wrapper.get('[data-test="card-select-hit"]')
    expect(hit.classes()).toEqual(expect.arrayContaining(['contents', 'max-md:w-11', 'max-md:h-11']))
    await hit.trigger('click')
    await flushPromises()
    expect(router.currentRoute.value.path).toBe('/purchase-invoices')
    expect(wrapper.get('[data-test="card-vendor-name"]').classes()).toEqual(expect.arrayContaining(['truncate', 'max-md:line-clamp-2']))
    wrapper.unmount()
  })
})

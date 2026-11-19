import { describe, expect, it, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { ref } from 'vue'

// Volitelný sloupec DIČ dodavatele: hned za IČ, výchozí skrytý, hodnota z listového API.

const mocks = vi.hoisted(() => ({
  columns: [] as Array<{ key: string; defaultHidden?: boolean }>,
  visible: new Set<string>(),
}))
vi.mock('@/api/purchaseInvoices', () => ({
  purchaseInvoicesApi: { listGrouped: async () => ({ data: [], meta: { total: 0, pages: 1 } }), listImportBatches: async () => [], get: vi.fn() },
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
vi.mock('@/composables/useTablePrefs', () => ({ useTablePrefs: (_page: string, columns: Array<{ key: string; defaultHidden?: boolean }>) => {
  mocks.columns = columns
  return { sort: ref(null), flag: () => true, isVisible: (key: string) => mocks.visible.has(key), orderedColumns: ref(columns) }
} }))
vi.mock('@/composables/useSavedFilters', () => ({
  useSavedFilters: () => ({ activeId: { value: null }, filters: { value: [] }, applyDefaultIfAny: async () => false }),
  savedFilterTone: () => 'neutral',
}))
vi.mock('vue-i18n', async importOriginal => ({
  ...await importOriginal<typeof import('vue-i18n')>(),
  useI18n: () => ({ t: (key: string) => key, locale: { value: 'cs' } }),
}))
import InvoiceList from '../InvoiceList.vue'

async function open() {
  const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/purchase-invoices', component: { render: () => null } }] })
  await router.push('/purchase-invoices')
  const wrapper = mount({ ...InvoiceList, render: () => null }, { global: { plugins: [router], directives: { 'column-labels': {} } } })
  await flushPromises()
  return wrapper
}

describe('PurchaseInvoiceList — sloupec DIČ dodavatele', () => {
  it('zařadí DIČ hned za IČ a nechá ho výchozí skrytý', async () => {
    const wrapper = await open()
    const keys = mocks.columns.map(c => c.key)
    expect(keys[keys.indexOf('vendor_ic') + 1]).toBe('vendor_dic')
    expect(mocks.columns.find(c => c.key === 'vendor_dic')?.defaultHidden).toBe(true)
    wrapper.unmount()
  })

  it('zapnutý sloupec ukáže i na kartě s hodnotou z dokladu', async () => {
    mocks.visible = new Set(['vendor_dic'])
    const wrapper = await open()
    const vm = wrapper.vm as unknown as { mobileExtraFields: (inv: Record<string, unknown>) => Array<{ key: string; value: string }> }
    expect(vm.mobileExtraFields({ vendor_dic: 'CZ12345678', currency: 'CZK' }).find(f => f.key === 'vendor_dic')?.value).toBe('CZ12345678')
    expect(vm.mobileExtraFields({ vendor_dic: null, currency: 'CZK' }).find(f => f.key === 'vendor_dic')?.value).toBe('—')
    mocks.visible = new Set()
    wrapper.unmount()
  })
})

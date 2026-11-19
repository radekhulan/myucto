import { describe, expect, it, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { ref } from 'vue'

// Volitelné sloupce IČ a DIČ odběratele: za sloupcem klienta, výchozí skryté,
// hodnoty z listového API (client_ic / client_dic).

const mocks = vi.hoisted(() => ({
  columns: [] as Array<{ key: string; defaultHidden?: boolean }>,
  visible: new Set<string>(),
}))
vi.mock('@/api/invoices', () => ({ invoicesApi: { listGrouped: async () => ({ data: [], meta: { total: 0, pages: 1 } }) } }))
vi.mock('@/api/clients', () => ({ clientsApi: { list: async () => ({ data: [] }) } }))
vi.mock('@/api/codebooks', () => ({ codebooksApi: { currencies: async () => [] } }))
vi.mock('@/api/revenueCategories', () => ({ revenueCategoriesApi: { list: async () => [] } }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ canRead: () => true }) }))
vi.mock('@/stores/supplier', () => ({ useSupplierStore: () => ({}) }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ error: vi.fn() }) }))
vi.mock('@/composables/useYearOptions', () => ({ useYearOptions: () => [] }))
vi.mock('@/composables/useListKeyboard', () => ({ useListKeyboard: () => ({ activeIndex: -1 }) }))
vi.mock('@/composables/useUserPrefs', () => ({ ensurePrefsLoaded: vi.fn().mockResolvedValue(undefined), getPagePrefs: () => ref({}) }))
vi.mock('@/composables/useTablePrefs', () => ({ useTablePrefs: (_page: string, columns: Array<{ key: string; defaultHidden?: boolean }>) => {
  mocks.columns = columns
  return { sort: ref(null), flag: () => true, isVisible: (key: string) => mocks.visible.has(key), orderedColumns: ref(columns) }
} }))
vi.mock('@/composables/useSavedFilters', () => ({
  useSavedFilters: () => ({ applyDefaultIfAny: async () => false }),
  savedFilterTone: () => 'neutral',
}))
vi.mock('vue-i18n', async importOriginal => ({
  ...await importOriginal<typeof import('vue-i18n')>(),
  useI18n: () => ({ t: (key: string) => key }),
}))
import InvoiceList from '../InvoiceList.vue'

async function open() {
  const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/invoices', component: { render: () => null } }] })
  await router.push('/invoices')
  const wrapper = mount({ ...InvoiceList, render: () => null }, { global: { plugins: [router] } })
  await flushPromises()
  return wrapper
}

describe('InvoiceList — sloupce IČ a DIČ odběratele', () => {
  it('zařadí IČ a DIČ hned za klienta a nechá je výchozí skryté', async () => {
    const wrapper = await open()
    const keys = mocks.columns.map(c => c.key)
    expect(keys.slice(keys.indexOf('client'), keys.indexOf('client') + 3)).toEqual(['client', 'client_ic', 'client_dic'])
    expect(mocks.columns.filter(c => c.key === 'client_ic' || c.key === 'client_dic').every(c => c.defaultHidden)).toBe(true)
    wrapper.unmount()
  })

  it('zapnuté sloupce ukáže i na kartě s hodnotou z dokladu', async () => {
    mocks.visible = new Set(['client_ic', 'client_dic'])
    const wrapper = await open()
    const vm = wrapper.vm as unknown as { mobileExtraFields: (inv: Record<string, unknown>) => Array<{ key: string; value: string }> }
    expect(vm.mobileExtraFields({ client_ic: '12345678', client_dic: 'CZ12345678', currency: 'CZK' })
      .filter(f => f.key.startsWith('client_'))).toEqual([
      expect.objectContaining({ key: 'client_ic', value: '12345678' }),
      expect.objectContaining({ key: 'client_dic', value: 'CZ12345678' }),
    ])
    expect(vm.mobileExtraFields({ client_ic: null, client_dic: null, currency: 'CZK' })
      .filter(f => f.key.startsWith('client_')).map(f => f.value)).toEqual(['—', '—'])
    mocks.visible = new Set()
    wrapper.unmount()
  })
})

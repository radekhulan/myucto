import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { reactive, ref } from 'vue'

const m = vi.hoisted(() => ({
  listDocuments: vi.fn(), listWarehouses: vi.fn(), canWrite: vi.fn(), replace: vi.fn(),
  route: undefined as any,
}))
vi.mock('@/api/stock', () => ({ stockApi: { listDocuments: m.listDocuments, listWarehouses: m.listWarehouses } }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ canWrite: m.canWrite }) }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ error: vi.fn() }) }))
vi.mock('@/composables/useFormat', () => ({ formatDate: (value: string) => value }))
vi.mock('@/composables/useRowLink', () => ({ useRowLink: () => vi.fn() }))
vi.mock('@/composables/useTablePrefs', () => ({
  useTablePrefs: () => ({ densityClass: ref(''), isVisible: () => true }),
}))
vi.mock('@/composables/useSavedFilters', () => ({
  useSavedFilters: () => ({ filters: ref([]), activeId: ref(null), applyDefaultIfAny: async () => false }),
  savedFilterTone: () => 'neutral',
}))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))
vi.mock('vue-router', () => ({
  useRoute: () => m.route,
  useRouter: () => ({ replace: m.replace }),
  RouterLink: { props: ['to'], template: '<a :href="typeof to === \'string\' ? to : to.path"><slot /></a>' },
}))

import DocumentList from '../DocumentList.vue'

function mountPage() {
  return mount(DocumentList, { attachTo: document.body, global: {
    directives: { 'column-labels': {} },
    stubs: {
      FilterBar: { template: '<div><slot name="primary" /><slot /><slot name="actions" /></div>' },
      SavedFiltersMenu: true, ColumnPicker: true, DensityToggle: true, EmptyState: true,
    },
  } })
}

beforeEach(() => {
  vi.clearAllMocks()
  m.route = reactive({ query: {} as Record<string, string | undefined> })
  m.replace.mockImplementation(async ({ query }) => { m.route.query = query })
  m.canWrite.mockReturnValue(true)
  m.listWarehouses.mockResolvedValue([{ id: 3, code: 'HL', name: 'Hlavní' }])
  m.listDocuments.mockResolvedValue({ items: [{ id: 1, doc_number: 'SYN-1', doc_type: 'receipt', doc_date: '2026-01-01', status: 'posted', origin: 'manual' }], total: 1, limit: 50 })
})

describe('DocumentList import tab', () => {
  it('shows import entrypoints only in the tab and preserves document filters without an import API type', async () => {
    const wrapper = mountPage()
    await flushPromises()
    expect(wrapper.find('a[href="/stock/opening-import"]').exists()).toBe(false)
    expect(wrapper.find('a[href="/stock/history-import"]').exists()).toBe(false)
    const tab = (key: string) => wrapper.findAll('button').find(button => button.text() === `stock.documents.tab_${key}`)!
    expect(wrapper.findAll('button').filter(button => button.text().startsWith('stock.documents.tab_')).map(button => button.text())).toEqual([
      'stock.documents.tab_all', 'stock.documents.tab_receipts', 'stock.documents.tab_issues', 'stock.documents.tab_transfers', 'stock.documents.tab_import',
    ])
    await tab('receipts').trigger('click')
    await wrapper.get('select[title="stock.documents.filter_warehouse"]').setValue('3')
    await wrapper.get('select[title="stock.documents.filter_status"]').setValue('posted')
    await wrapper.get('input[type="search"]').setValue('SYN')
    await flushPromises()
    const callsBeforeImport = m.listDocuments.mock.calls.length
    await tab('import').trigger('click')
    await flushPromises()
    expect(wrapper.find('input[type="search"]').isVisible()).toBe(false)
    expect(wrapper.get('a[href="/stock/opening-import"]').isVisible()).toBe(true)
    expect(wrapper.get('a[href="/stock/history-import"]').isVisible()).toBe(true)
    expect(m.listDocuments).toHaveBeenCalledTimes(callsBeforeImport)
    expect(m.route.query.type).toBe('receipt')
    await tab('receipts').trigger('click')
    await flushPromises()
    expect(wrapper.get('input[type="search"]').isVisible()).toBe(true)
    expect((wrapper.get('input[type="search"]').element as HTMLInputElement).value).toBe('SYN')
    expect(wrapper.find('a[href="/stock/opening-import"]').exists()).toBe(false)
    expect(wrapper.text()).toContain('SYN-1')
    expect(m.listDocuments).toHaveBeenLastCalledWith(expect.objectContaining({ doc_type: 'receipt', warehouse_id: 3, status: 'posted', q: 'SYN' }))
    expect(m.listDocuments.mock.calls.every(([query]) => query.doc_type !== 'import')).toBe(true)
    expect(m.replace.mock.calls.every(([navigation]) => navigation.query.type !== 'import')).toBe(true)
    wrapper.unmount()
  })

  it.each(['stock', 'stock.documents.write'])('keeps import unavailable without %s permission', async denied => {
    m.canWrite.mockImplementation((permission: string) => permission !== denied)
    const wrapper = mountPage()
    await flushPromises()
    expect(wrapper.findAll('button').some(button => button.text() === 'stock.documents.tab_import')).toBe(false)
    expect(wrapper.find('a[href="/stock/opening-import"]').exists()).toBe(false)
    expect(wrapper.find('a[href="/stock/history-import"]').exists()).toBe(false)
    wrapper.unmount()
  })
})

import { afterEach, describe, expect, it, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { ref } from 'vue'
import type { RowAction } from '@/components/ui/RowActionsMenu.vue'

// Telefon: „…" na kartě dokladu nabízí PDF, odeslání a označení úhrady jednoho
// dokladu podle stejných podmínek jako hromadné akce seznamu.

const phoneRef = ref(true)
vi.mock('@/composables/useIsPhone', () => ({ useIsPhone: () => phoneRef, installPhoneViewport: () => {} }))

const mocks = vi.hoisted(() => ({
  list: vi.fn(async () => ({ data: [], meta: { total: 0, pages: 1 } })),
  markPaid: vi.fn(async () => ({})),
  send: vi.fn(async () => ({})),
  canWrite: vi.fn((_perm: string) => true),
}))
vi.mock('@/api/invoices', () => ({ invoicesApi: {
  listGrouped: mocks.list, markPaid: mocks.markPaid, send: mocks.send,
  pdfUrl: (id: number, download = false) => `/api/invoices/${id}/pdf${download ? '?download=1' : ''}`,
} }))
vi.mock('@/api/clients', () => ({ clientsApi: { list: async () => ({ data: [] }) } }))
vi.mock('@/api/codebooks', () => ({ codebooksApi: { currencies: async () => [] } }))
vi.mock('@/api/revenueCategories', () => ({ revenueCategoriesApi: { list: async () => [] } }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ canRead: () => true, canWrite: mocks.canWrite, isClientRole: false }) }))
vi.mock('@/stores/supplier', () => ({ useSupplierStore: () => ({ currentSupplier: { payment_thanks_enabled: false } }) }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ error: vi.fn(), success: vi.fn(), warning: vi.fn() }) }))
vi.mock('@/composables/useYearOptions', () => ({ useYearOptions: () => [] }))
vi.mock('@/composables/useListKeyboard', () => ({ useListKeyboard: () => ({ activeIndex: -1 }) }))
vi.mock('@/composables/useUserPrefs', () => ({ ensurePrefsLoaded: vi.fn().mockResolvedValue(undefined), getPagePrefs: () => ref({}) }))
vi.mock('@/composables/useTablePrefs', () => ({ useTablePrefs: () => ({
  sort: ref(null), flag: () => true, isVisible: () => false,
}) }))
vi.mock('@/composables/useSavedFilters', () => ({
  useSavedFilters: () => ({ applyDefaultIfAny: async () => false }),
  savedFilterTone: () => 'neutral',
}))
vi.mock('vue-i18n', async importOriginal => ({
  ...await importOriginal<typeof import('vue-i18n')>(),
  useI18n: () => ({ t: (key: string) => key }),
}))
import InvoiceList from '../InvoiceList.vue'

type State = {
  rowMenuActions: (inv: Record<string, unknown>) => RowAction[]
  rowMarkPaid: (inv: Record<string, unknown>) => Promise<void>
  rowSend: (inv: Record<string, unknown>) => Promise<void>
}

async function open() {
  const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/invoices', component: { render: () => null } }] })
  await router.push('/invoices')
  const wrapper = mount({ ...InvoiceList, render: () => null }, { global: { plugins: [router] } })
  await flushPromises()
  return { wrapper, vm: wrapper.vm as unknown as State }
}

const issued = { id: 5, status: 'sent', invoice_type: 'invoice', varsymbol: '2610005', amount_to_pay: 1210, calendar_id: null, locked: null }
const visible = (actions: RowAction[]) => actions.filter(a => a.show === undefined || !!a.show).map(a => a.key)

describe('InvoiceList — nabídka „…" na kartě', () => {
  afterEach(() => {
    vi.restoreAllMocks()
    mocks.markPaid.mockClear()
    mocks.send.mockClear()
    mocks.canWrite.mockImplementation(() => true)
  })

  it('u odeslané faktury nabídne PDF, odeslání i označení úhrady', async () => {
    const { wrapper, vm } = await open()
    const actions = vm.rowMenuActions(issued)
    expect(visible(actions)).toEqual(['view-pdf', 'download-pdf', 'send', 'mark-paid'])
    expect(actions.find(a => a.key === 'download-pdf')?.href).toBe('/api/invoices/5/pdf?download=1')
    wrapper.unmount()
  })

  it('u konceptu ani bez práv akce nenabídne', async () => {
    const { wrapper, vm } = await open()
    expect(visible(vm.rowMenuActions({ ...issued, status: 'draft', varsymbol: null }))).toEqual([])
    mocks.canWrite.mockImplementation(() => false)
    expect(visible(vm.rowMenuActions(issued))).toEqual(['view-pdf', 'download-pdf'])
    expect(visible(vm.rowMenuActions({ ...issued, status: 'paid' }))).toEqual(['view-pdf', 'download-pdf'])
    wrapper.unmount()
  })

  it('označí jeden doklad zaplaceným až po potvrzení a nesahá na výběr', async () => {
    const { wrapper, vm } = await open()
    const confirm = vi.spyOn(window, 'confirm').mockReturnValueOnce(false).mockReturnValueOnce(true)
    await vm.rowMarkPaid(issued)
    expect(mocks.markPaid).not.toHaveBeenCalled()
    await vm.rowMarkPaid(issued)
    expect(confirm).toHaveBeenCalledTimes(2)
    expect(mocks.markPaid).toHaveBeenCalledWith(5, expect.any(String), undefined)
    wrapper.unmount()
  })

  it('odešle jeden doklad po potvrzení', async () => {
    const { wrapper, vm } = await open()
    vi.spyOn(window, 'confirm').mockReturnValue(true)
    await vm.rowSend(issued)
    expect(mocks.send).toHaveBeenCalledWith(5)
    wrapper.unmount()
  })
})

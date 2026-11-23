import { flushPromises, shallowMount } from '@vue/test-utils'
import { reactive, ref } from 'vue'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const m = vi.hoisted(() => ({
  list: vi.fn(),
  reconciliation: vi.fn(),
  unallocated: vi.fn(),
  projection: vi.fn(),
  addLink: vi.fn(),
  canWrite: vi.fn(),
  replace: vi.fn(),
  push: vi.fn(),
  navigate: vi.fn(),
  toastError: vi.fn(),
  toastSuccess: vi.fn(),
  route: null as any,
  prefs: null as any,
}))

vi.mock('@/api/accruals', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/api/accruals')>()
  return {
    ...actual,
    accrualsApi: {
      list: m.list, reconciliation: m.reconciliation, unallocated: m.unallocated, projection: m.projection,
      addLink: m.addLink, bookUrl: actual.accrualsApi.bookUrl,
    },
  }
})
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ canWrite: m.canWrite, canRead: () => true }) }))
vi.mock('@/stores/supplier', () => ({ useSupplierStore: () => ({ currentSupplierId: 1, currentSupplier: { accounting_mode: 'double_entry' } }) }))
vi.mock('@/composables/useUserPrefs', () => ({
  ensurePrefsLoaded: () => Promise.resolve(),
  getPagePrefs: () => m.prefs,
  patchPagePrefs: (_page: string, patch: Record<string, unknown>) => { m.prefs.value = { ...m.prefs.value, ...patch } },
}))
vi.mock('@/composables/useSavedFilters', () => ({
  useSavedFilters: (page: string) => {
    expect(page).toBe('accruals')
    return { activeId: { value: null }, filters: { value: [] }, apply: vi.fn(), clearActive: vi.fn(), applyDefaultIfAny: async () => false }
  },
}))
vi.mock('@/composables/useListKeyboard', () => ({ useListKeyboard: () => ({ activeIndex: ref(-1) }) }))
vi.mock('@/composables/useRowLink', () => ({ useRowLink: () => m.navigate }))
vi.mock('@/composables/useListNavigation', () => ({ useListNavigationSource: () => ({ markOpen: vi.fn() }) }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ success: m.toastSuccess, error: m.toastError }) }))
vi.mock('@/composables/useFormat', () => ({
  formatDate: (value: string) => value,
  formatMoney: (value: number) => `${value} Kč`,
  statusBadgeClass: (status: string) => `badge badge-${status}`,
}))
vi.mock('vue-router', () => ({
  useRoute: () => m.route,
  useRouter: () => ({
    replace: m.replace,
    push: m.push,
    resolve: ({ query }: { query: Record<string, string> }) => ({ fullPath: `/accounting/accruals?${new URLSearchParams(query)}` }),
  }),
  RouterLink: { template: '<a><slot /></a>' },
}))
vi.mock('vue-i18n', () => ({
  useI18n: () => ({ t: (key: string, params?: unknown) => params !== undefined ? `${key}:${JSON.stringify(params)}` : key }),
}))

import Accruals from '../Accruals.vue'

const stubs = {
  RouterLink: { props: ['to'], template: '<a :data-to="JSON.stringify(to)"><slot /></a>' },
  FilterBar: { template: '<div><slot name="primary" /><slot /><slot name="actions" /></div>' },
  Modal: { template: '<div><slot /><slot name="footer" /></div>' },
}

function item(changes: Record<string, unknown> = {}) {
  return {
    id: 5, item_no: 'CR-2099-0001', title: 'Syntetické pojištění', kind: 'prepaid_expense', accrual_account_code: '381',
    counter_account_code: '548', termination_account_code: null, partner_id: null, partner_name: 'Syntetický partner',
    source_type: 'manual', source_id: null, source_document_no: null, managed_by: 'agenda', basis_amount: 1200,
    period_from: '2099-01-01', period_to: '2099-12-31', method: 'monthly_days', status: 'active', balance: 600, released: 600,
    next_due_on: '2099-07-31', ...changes,
  }
}

function reconciliation(difference = 0) {
  return {
    as_of: '2099-06-30', period_starts_on: '2099-01-01',
    accounts: [{ account_id: 1, account_code: '381', account_name: 'Náklady příštích období', kind: 'prepaid_expense', normal_side: 'debit',
      ledger_balance: 600 + difference, items_balance: 600, items_count: 1, closing_balance: 0, difference }],
    totals: { ledger_balance: 600 + difference, items_balance: 600, closing_balance: 0, difference },
  }
}

function mountList(query: Record<string, string> = {}) {
  m.route = reactive({ name: 'accounting-accruals', path: '/accounting/accruals', fullPath: '/accounting/accruals', query })
  return shallowMount(Accruals, { global: { stubs } })
}

describe('Accruals', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.prefs = ref({})
    m.canWrite.mockReturnValue(true)
    m.list.mockResolvedValue({ items: [item()], total: 1, totals: { basis_amount: 1200, balance: 600 }, page: 1, per_page: 50, as_of: '2099-06-30' })
    m.reconciliation.mockResolvedValue(reconciliation())
    m.replace.mockImplementation(({ query }: { query: Record<string, string> }) => { m.route.query = query })
  })

  it('převezme filtry z adresy, pošle je API a vykreslí řádek se stavem', async () => {
    const wrapper = mountList({ account: '381', as_of: '2099-06-30', status: 'active', with_balance: '1' })
    await flushPromises()
    expect(m.list).toHaveBeenCalledWith(expect.objectContaining({ account: '381', as_of: '2099-06-30', status: 'active', with_balance: 1, page: 1 }))
    expect(m.reconciliation).toHaveBeenCalledWith('2099-06-30')
    expect(wrapper.findAll('[data-test="accrual-row"]')).toHaveLength(1)
    expect(wrapper.get('[data-test="status-badge"]').text()).toBe('accruals.status.active')
  })

  it('bez rozdílu proti hlavní knize řádky bez položky nenabízí', async () => {
    const wrapper = mountList()
    await flushPromises()
    expect(wrapper.find('[data-test="unallocated-toggle-381"]').exists()).toBe(false)
  })

  it('rozdíl rozbalí řádky bez položky a „Založit položku“ předvyplní editor', async () => {
    m.reconciliation.mockResolvedValue(reconciliation(250))
    m.unallocated.mockResolvedValue({
      as_of: '2099-06-30', period_starts_on: '2099-01-01', total: 250, truncated: false,
      rows: [{ entry_id: 77, entry_date: '2099-03-01', document_no: 'ID-1', description: 'Předplatné', source_type: 'internal_document', source_id: 9,
        account_id: 1, account_code: '381', kind: 'prepaid_expense', turnover: 250, allocated: 0, unallocated: 250 }],
    })
    const wrapper = mountList()
    await flushPromises()
    expect(wrapper.get('[data-test="reconciliation-difference"]').text()).toBe('250 Kč')
    await wrapper.get('[data-test="unallocated-toggle-381"]').trigger('click')
    await flushPromises()
    expect(m.unallocated).toHaveBeenCalledWith({ account: '381' })
    await wrapper.get('[data-test="unallocated-create"]').trigger('click')
    expect(m.push).toHaveBeenCalledWith({
      name: 'accounting-accrual-new',
      query: { account: '381', amount: '250', origin_entry_id: '77', origin_amount: '250', title: 'Předplatné', source_type: 'internal_document', source_id: '9' },
    })
  })

  it('„Přiřadit k položce“ nabídne položky se stejným účtem a naváže zápis', async () => {
    m.reconciliation.mockResolvedValue(reconciliation(100))
    m.unallocated.mockResolvedValue({
      as_of: '2099-06-30', period_starts_on: null, total: 100, truncated: false,
      rows: [{ entry_id: 78, entry_date: '2099-03-01', document_no: null, description: null, source_type: null, source_id: null,
        account_id: 1, account_code: '381', kind: 'prepaid_expense', turnover: 100, allocated: 0, unallocated: 100 }],
    })
    m.addLink.mockResolvedValue({})
    const wrapper = mountList()
    await flushPromises()
    await wrapper.get('[data-test="unallocated-toggle-381"]').trigger('click')
    await flushPromises()
    m.list.mockResolvedValueOnce({ items: [item(), item({ id: 6, accrual_account_code: '3811' })], total: 2, totals: { basis_amount: 0, balance: 0 }, page: 1, per_page: 200, as_of: '2099-06-30' })
    await wrapper.get('[data-test="unallocated-assign"]').trigger('click')
    await flushPromises()
    expect(m.list).toHaveBeenLastCalledWith(expect.objectContaining({ account: '381', per_page: 200 }))
    expect(wrapper.findAll('[data-test="assign-item"] option').map(o => o.text())).toEqual(['—', 'CR-2099-0001 · Syntetické pojištění'])
    await wrapper.get('form#accrual-assign').trigger('submit')
    await flushPromises()
    expect(m.addLink).toHaveBeenCalledWith(5, { entry_id: 78, account_code: '381', amount: 100 })
  })

  it('bez práva zápisu nenabízí novou položku ani převzetí', async () => {
    m.canWrite.mockReturnValue(false)
    const wrapper = mountList()
    await flushPromises()
    expect(wrapper.find('[data-test="accruals-new"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="accruals-takeover"]').exists()).toBe(false)
  })

  it('záložka Spravuje uzávěrka načte projekci a zapíše se do adresy', async () => {
    m.projection.mockResolvedValue({ as_of: '2099-06-30', truncated: false, totals: { prepaid_expense: 300, deferred_revenue: 0 },
      items: [{ managed_by: 'closing', kind: 'prepaid_expense', source_type: 'purchase_invoice', source_id: 12, source_item_id: 1,
        document_no: 'PF-1', title: 'Syntetická licence', period_from: '2099-01-01', period_to: '2099-12-31', basis_amount: 600, balance: 300 }] })
    const wrapper = mountList()
    await flushPromises()
    await wrapper.get('[data-test="tab-closing"]').trigger('click')
    await flushPromises()
    expect(m.projection).toHaveBeenCalled()
    expect(m.replace).toHaveBeenLastCalledWith({ query: { tab: 'closing' } })
    expect(wrapper.findAll('[data-test="projection-row"]')).toHaveLength(1)
  })
})

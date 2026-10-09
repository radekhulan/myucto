import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { defineComponent } from 'vue'

// #136: „Všechny pohyby" jsou přehled bankovních výpisů, ne účetnictví. Firma v daňové
// evidenci (nebo bez komerčních funkcí) záložku vidí, ale nesmí sahat na účetní
// endpointy a v řádku nemá stav zaúčtování ani kontace.

const m = vi.hoisted(() => ({
  state: { mode: 'tax_evidence' as string, commercial: true },
  listMovements: vi.fn(), listUnposted: vi.fn(), unpostedCount: vi.fn(), suggestions: vi.fn(),
}))

vi.mock('vue-router', () => ({
  useRouter: () => ({ replace: vi.fn(), push: vi.fn() }),
  useRoute: () => ({ query: {} }),
  RouterLink: { name: 'RouterLink', props: ['to'], template: '<a><slot /></a>' },
}))
vi.mock('vue-i18n', async importOriginal => ({
  ...await importOriginal<typeof import('vue-i18n')>(), useI18n: () => ({ t: (key: string) => key }),
}))
vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({
    get hasCommercialFeatures() { return m.state.commercial },
    isCompanyAdminRole: false, isDemo: false, canRead: () => false, canWrite: () => false,
  }),
}))
vi.mock('@/stores/supplier', () => ({
  useSupplierStore: () => ({ currentSupplierId: 7, get currentSupplier() { return { id: 7, accounting_mode: m.state.mode } } }),
}))
vi.mock('@/api/bankPosting', () => ({
  bankPostingApi: { listMovements: m.listMovements, listUnposted: m.listUnposted, unpostedCount: m.unpostedCount },
}))
vi.mock('@/api/bank', () => ({ bankApi: { matchSuggestions: m.suggestions } }))
vi.mock('@/composables/useBankTransactionActions', () => ({
  useBankTransactionActions: () => ({ setSuggestions: vi.fn() }),
}))
vi.mock('@/composables/useFillViewportHeight', () => ({ useFillViewportHeight: () => undefined }))
vi.mock('@/composables/useScrollLoadMore', () => ({ useScrollLoadMore: () => undefined }))

import BankPage from '../BankPage.vue'
import UnpostedTransactions from '../UnpostedTransactions.vue'

const Row = defineComponent({
  name: 'BankTransactionRow',
  props: ['isDoubleEntry', 'showCounterAccount', 'tx', 'layout', 'colspan'],
  template: '<tr class="row-stub" />',
})
const stubs = {
  StatementList: true, BankAccounts: true, BankAccountAnalytics: true, PostingSuggestions: true,
  UnpostedTransactions: true, ActionBar: true, BankTransactionDialogs: true, BankMatchModal: true,
  BankCreatePurchaseModal: true, BankRequestDocModal: true, BankTransactionSortSelect: true,
  BankTransactionRow: Row,
  SortableTh: defineComponent({ props: ['label', 'sortKey'], template: '<th :data-sort="sortKey">{{ label }}</th>' }),
}

const page = { items: [{ id: 1, statement_id: 3 }], total: 1, page: 1, per_page: 50, scope: 'all', years: [2026], accounts: [] }

function tabs(wrapper: ReturnType<typeof mount>): string[] {
  return wrapper.findAll('button').map(b => b.text().trim())
}

describe('Bankovní účty: záložka „Všechny pohyby" (#136)', () => {
  beforeEach(() => {
    vi.resetAllMocks()
    sessionStorage.clear()
    m.listMovements.mockResolvedValue(page)
    m.listUnposted.mockResolvedValue(page)
    m.unpostedCount.mockResolvedValue(0)
    m.suggestions.mockResolvedValue({ suggestions: [] })
  })

  it('je vidět v daňové evidenci, hned za Výpisy, bez účetních záložek a počítadla', async () => {
    m.state = { mode: 'tax_evidence', commercial: true }
    const wrapper = mount(BankPage, { global: { stubs } })
    await flushPromises()
    const labels = tabs(wrapper)
    expect(labels.slice(0, 2)).toEqual(['bank.title', 'bank.posting.tab_all'])
    expect(labels).not.toContain('bank.posting.tab_suggestions')
    expect(labels).not.toContain('bank.analytics.tab')
    expect(m.unpostedCount).not.toHaveBeenCalled()
  })

  it('je vidět i v podvojném účetnictví bez komerčních funkcí', async () => {
    m.state = { mode: 'double_entry', commercial: false }
    const wrapper = mount(BankPage, { global: { stubs } })
    await flushPromises()
    expect(tabs(wrapper)).toContain('bank.posting.tab_all')
    expect(tabs(wrapper)).not.toContain('bank.posting.tab_suggestions')
  })

  it('v podvojném účetnictví zůstávají i účetní záložky', async () => {
    m.state = { mode: 'double_entry', commercial: true }
    const wrapper = mount(BankPage, { global: { stubs } })
    await flushPromises()
    expect(tabs(wrapper).slice(0, 4)).toEqual(['bank.title', 'bank.posting.tab_all', 'bank.posting.tab_suggestions', 'bank.analytics.tab'])
    expect(m.unpostedCount).toHaveBeenCalled()
  })

  it('daňová evidence čte bankovní endpoint a nevidí zaúčtování ani kontace', async () => {
    m.state = { mode: 'tax_evidence', commercial: true }
    const wrapper = mount(UnpostedTransactions, { props: { scope: 'all' }, global: { stubs: { ...stubs, UnpostedTransactions: false } } })
    await flushPromises()
    expect(m.listMovements).toHaveBeenCalled()
    expect(m.listUnposted).not.toHaveBeenCalled()
    expect(m.listMovements.mock.calls[0]![0]).not.toHaveProperty('posting_status')
    const headers = wrapper.findAll('th').map(th => th.text())
    expect(headers).not.toContain('bank.posting_state')
    expect(headers).not.toContain('bank.counter_account')
    expect(headers).toContain('invoice.status_label')
    const row = wrapper.findAllComponents(Row)[0]!
    expect(row.props('isDoubleEntry')).toBe(false)
    expect(row.props('showCounterAccount')).toBe(false)
    expect(row.props('colspan')).toBe(8)
    expect(wrapper.findAll('select').length).toBe(2)
  })

  it('podvojné účetnictví má v přehledu stav zaúčtování, kontace i filtr zaúčtování', async () => {
    m.state = { mode: 'double_entry', commercial: true }
    const wrapper = mount(UnpostedTransactions, { props: { scope: 'all' }, global: { stubs: { ...stubs, UnpostedTransactions: false } } })
    await flushPromises()
    expect(m.listMovements).toHaveBeenCalled()
    const headers = wrapper.findAll('th').map(th => th.text())
    expect(headers).toContain('bank.posting_state')
    expect(headers).toContain('bank.counter_account')
    const row = wrapper.findAllComponents(Row)[0]!
    expect(row.props('isDoubleEntry')).toBe(true)
    expect(row.props('showCounterAccount')).toBe(true)
    expect(row.props('colspan')).toBe(9)
    expect(wrapper.findAll('select').length).toBe(3)
  })

  it('fronta k zaúčtování dál čte účetní endpoint', async () => {
    m.state = { mode: 'double_entry', commercial: true }
    mount(UnpostedTransactions, { props: { scope: 'unposted' }, global: { stubs: { ...stubs, UnpostedTransactions: false } } })
    await flushPromises()
    expect(m.listUnposted).toHaveBeenCalledWith(expect.objectContaining({ scope: 'unposted' }))
    expect(m.listMovements).not.toHaveBeenCalled()
  })
})

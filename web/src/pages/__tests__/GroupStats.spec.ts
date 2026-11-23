import { beforeEach, describe, expect, it, vi } from 'vitest'
import { reactive, ref } from 'vue'
import { flushPromises, mount } from '@vue/test-utils'
import type { GroupCompany, GroupDashboard, GroupSection } from '@/api/groupDashboard'

const mocks = vi.hoisted(() => ({ get: vi.fn(), push: vi.fn(), replace: vi.fn(), switchTo: vi.fn() }))
const auth = reactive({
  user: { id: 1, role: { system_key: 'admin' } }, permissions: { 'dashboard.portfolio': 1 },
  domainContext: null as null | { locked: boolean }, permissionsLoading: false,
  canRead: () => auth.permissions['dashboard.portfolio'] > 0,
})
const supplier = reactive({ currentSupplierId: 1, domainLocked: false, availableSuppliers: [{ id: 1 }, { id: 2 }] })
vi.mock('@/api/groupDashboard', () => ({ groupDashboardApi: { get: mocks.get } }))
vi.mock('@/composables/useSupplierSwitch', () => ({ useSupplierSwitch: () => ({ switching: ref(false), switchTo: mocks.switchTo }) }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => auth }))
vi.mock('@/stores/supplier', () => ({ useSupplierStore: () => supplier }))
vi.mock('@/composables/useFormat', () => ({ formatMoneyWhole: (value: number, currency: string) => `${value} ${currency}`, formatNumber: (value: number) => String(value) }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string, params?: object) => key + (params ? JSON.stringify(params) : ''), locale: ref('cs') }) }))
vi.mock('vue-router', () => ({ useRoute: () => ({ query: {} }), useRouter: () => ({ push: mocks.push, replace: mocks.replace }) }))

import GroupStats from '../GroupStats.vue'


const amounts = { currency: 'CZK', revenue: 100, costs: 60, profit: 40, previous_revenue: 50, previous_costs: 30, previous_profit: 20 }
const company = (id = 1): GroupCompany => ({
  id, name: `Ukázková firma ${id}`, accounting_mode: 'double_entry', document_basis: 'net', issues: [],
  available: { documents: true, accounting: true, bank: true, cash: true, receivables: true, payables: true, cashflow: true, cashflow_tax: true, cashflow_payroll: true, forecast: true },
})
function report(section: GroupSection): GroupDashboard {
  const one = company()
  const data: GroupDashboard = {
    section, months: 12, weeks: 8, as_of: '2094-09-30', generated_at: '2094-09-30T12:00:00Z', company_count: 1, companies: [one],
    period: { from: '2093-10-01', to: '2094-09-30', previous_from: '2092-10-01', previous_to: '2093-09-30', mode: 'rolling' },
    totals: { financial: [], accounting: [], monthly: [], bank: [], cash: [], receivables: [], payables: [], cashflow: [], forecast: [] },
  }
  if (section === 'overview') {
    one.financial = [amounts]
    one.accounting = { currency: 'CZK', from: '2094-01-01', to: '2094-09-30', revenue: 110, costs: 65, profit: 45 }
    data.totals.financial = [{ ...amounts, companies: 1, missing_values: 0 }]
    data.totals.accounting = [{ ...one.accounting, companies: 1, missing_values: 0 }]
  } else if (section === 'trends') {
    one.monthly = [{ ...amounts, period: '2094-09' }]
    data.totals.monthly = [{ ...one.monthly[0]!, companies: 1, missing_values: 0 }]
  } else if (section === 'balances') {
    one.bank = [{ id: 1, name: 'Ukázkový účet', currency: 'CZK', balance: null, date: null }]
    one.cash = []
    data.totals.bank = [{ currency: 'CZK', balance: null, companies: 1, missing_values: 1 }]
  } else if (section === 'cashflow') {
    one.available.cashflow_tax = false
    one.available.cashflow_payroll = false
    const week = { week_start: '2094-10-01', week_end: '2094-10-07', in: 100, out: 80, net: 20, running: 20 }
    one.cashflow = [{ currency: 'CZK', weeks: [week], total_in: 100, total_out: 80, total_net: 20 }]
    data.totals.cashflow = [{ ...week, currency: 'CZK', companies: 1, missing_values: 0 }]
  } else if (section === 'receivables') {
    one.receivables = [{ currency: 'EUR', bucket: 'overdue_90_plus', count: 1, total: 20 }]
    one.payables = []
    data.totals.receivables = [{ ...one.receivables[0]!, companies: 1, missing_values: 0 }]
  } else {
    one.risks = [{ currency: 'EUR', kind: 'overdue_receivables', amount: 20, count: 1, bucket: 'overdue_90_plus' }]
  }
  return data
}
const mountPage = () => mount(GroupStats, { global: { stubs: { GroupDashboardChart: true, EmptyState: { props: ['title', 'message', 'cta'], template: '<div><span>{{title}}</span><span>{{message}}</span><button @click="$emit(\'action\')">{{cta}}</button></div>' } } } })

beforeEach(() => {
  vi.clearAllMocks()
  auth.user = { id: 1, role: { system_key: 'admin' } }
  auth.permissions = { 'dashboard.portfolio': 1 }
  auth.domainContext = null
  auth.permissionsLoading = false
  supplier.currentSupplierId = 1
  mocks.get.mockImplementation(async (query: { section: GroupSection }) => report(query.section))
})

describe('All companies dashboard', () => {
  it('defaults to the CZK projection and keeps the original currency selectable', async () => {
    const data = report('overview')
    data.companies[0]!.financial = [{ ...amounts, currency: 'EUR' }]
    data.totals.financial[0]!.currency = 'EUR'
    data.converted_czk = {
      companies: [{ ...company(), financial: [{ ...amounts, revenue: 2500, costs: 1500, profit: 1000 }] }],
      totals: { ...data.totals, financial: [{ ...amounts, revenue: 2500, costs: 1500, profit: 1000, companies: 1, missing_values: 0 }] },
      as_of: data.as_of, rates: [], missing_currencies: [],
    }
    mocks.get.mockResolvedValue(data)
    const wrapper = mountPage()
    await flushPromises()
    expect((wrapper.find('[data-test="group-currency"]').element as HTMLSelectElement).value).toBe('__ALL__')
    expect(wrapper.find('[data-test="group-financial-table"]').text()).toContain('2500 CZK')
    await wrapper.find('[data-test="group-currency"]').setValue('EUR')
    expect(wrapper.find('[data-test="group-financial-table"]').text()).toContain('100 EUR')
    expect(mocks.get).toHaveBeenCalledTimes(1)
  })
  it('can leave out related parties and remembers the choice in the URL', async () => {
    const wrapper = mountPage()
    await flushPromises()
    await wrapper.find('[data-test="group-related"]').setValue('false')
    await flushPromises()
    expect(mocks.get).toHaveBeenLastCalledWith({ section: 'overview', months: 12, weeks: 8, include_related: 0 }, expect.any(AbortSignal))
    expect(mocks.replace).toHaveBeenLastCalledWith({ query: { related: '0' } })
  })
  it('applies an exact date range and can restore the rolling default', async () => {
    const wrapper = mountPage()
    await flushPromises()
    await wrapper.find('[data-test="group-from"]').setValue('2094-03-15')
    await wrapper.find('[data-test="group-to"]').setValue('2094-06-10')
    await wrapper.find('form').trigger('submit')
    await flushPromises()
    expect(mocks.get).toHaveBeenLastCalledWith({ section: 'overview', months: 12, weeks: 8, from: '2094-03-15', to: '2094-06-10' }, expect.any(AbortSignal))
    await wrapper.find('[data-test="group-reset-range"]').trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-test="group-from"]').element).toHaveProperty('value', '01. 10. 2093')
  })
  it('opens an overdue risk in its company and currency, with no year limitation', async () => {
    const data = report('risks')
    data.companies = [{ ...company(2), risks: [{ kind: 'overdue_payables', currency: 'EUR', amount: 20, bucket: 'overdue_90_plus' }] }]
    mocks.get.mockImplementation(async (query: { section: GroupSection }) => query.section === 'risks' ? data : report(query.section))
    const wrapper = mountPage()
    await flushPromises()
    await wrapper.find('[data-test="group-tab-risks"]').trigger('click')
    await flushPromises()
    await wrapper.find('[data-test="group-open-risk"]').trigger('click')
    await flushPromises()
    expect(mocks.switchTo).toHaveBeenCalledWith(2, '/purchase-invoices?currency=EUR&year=all&overdue=1')
    expect(mocks.push).not.toHaveBeenCalled()
    supplier.currentSupplierId = 2
    await flushPromises()
    await wrapper.find('[data-test="group-open-risk"]').trigger('click')
    expect(mocks.push).toHaveBeenCalledWith('/purchase-invoices?currency=EUR&year=all&overdue=1')
    wrapper.unmount()
  })
  it('fetches only the active section, caches visits and refreshes explicitly', async () => {
    const wrapper = mountPage()
    await flushPromises()
    expect(mocks.get).toHaveBeenCalledTimes(1)
    expect(mocks.get).toHaveBeenCalledWith({ section: 'overview', months: 12, weeks: 8 }, expect.any(AbortSignal))
    expect(wrapper.find('[data-test="group-financial-table"]').text()).toContain('100 CZK')
    await wrapper.find('[data-test="group-tab-trends"]').trigger('click')
    await flushPromises()
    expect(mocks.get).toHaveBeenCalledTimes(2)
    await wrapper.find('[data-test="group-tab-overview"]').trigger('click')
    await flushPromises()
    expect(mocks.get).toHaveBeenCalledTimes(2)
    await wrapper.find('[data-test="group-refresh"]').trigger('click')
    await flushPromises()
    expect(mocks.get).toHaveBeenCalledTimes(3)
    wrapper.unmount()
  })
  it('distinguishes failed, restricted and no-period companies without fabricated amounts', async () => {
    const data = report('overview')
    data.companies.push({ ...company(2), financial: null, accounting: null, issues: ['financial'] }, company(3))
    data.company_count = 3
    data.totals.financial[0]!.missing_values = 1
    data.totals.accounting.push({ currency: 'EUR', from: '2094-04-01', to: '2094-09-30', revenue: 7, costs: 2, profit: 5, companies: 1, missing_values: 0 })
    mocks.get.mockResolvedValue(data)
    const wrapper = mountPage()
    await flushPromises()
    expect(wrapper.find('[data-test="group-financial-table"]').text()).toContain('group_stats.failed_part')
    expect(wrapper.find('[data-test="group-financial-table"]').text()).toContain('group_stats.restricted')
    expect(wrapper.find('[data-test="group-accounting-table"]').text()).toContain('group_stats.no_period')
    expect(wrapper.find('[data-test="group-notices"]').text()).toContain('Ukázková firma 2')
    expect(wrapper.findAll('[data-test="group-accounting-totals"] tbody tr')).toHaveLength(2)
    expect(wrapper.text()).toContain('group_stats.partial_total')
    wrapper.unmount()
  })
  it('shows unknown bank balances rather than zeros and dates each account', async () => {
    const wrapper = mountPage()
    await flushPromises()
    await wrapper.find('[data-test="group-tab-balances"]').trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-test="group-balances-table"]').text()).toContain('group_stats.unknown_balance')
    expect(wrapper.find('[data-test="group-balances-table"]').text()).toContain('CZK')
    expect(wrapper.text()).not.toContain('0 CZK')
    expect(wrapper.text()).toContain('group_stats.partial_total')
    wrapper.unmount()
  })
  it('loads cashflow, aging and risks independently and states omitted forecast sources', async () => {
    const wrapper = mountPage()
    await flushPromises()
    await wrapper.find('[data-test="group-tab-cashflow"]').trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-test="group-cashflow-table"]').text()).toContain('20 CZK')
    expect(wrapper.find('[data-test="group-cashflow-exclusions"]').text()).toContain('group_stats.tax_excluded')
    expect(wrapper.find('[data-test="group-cashflow-exclusions"]').text()).toContain('group_stats.payroll_excluded')
    await wrapper.find('[data-test="group-tab-receivables"]').trigger('click')
    await flushPromises()
    await wrapper.find('[data-test="group-currency"]').setValue('EUR')
    expect(wrapper.find('[data-test="group-aging-totals"]').text()).toContain('20 EUR')
    await wrapper.find('[data-test="group-tab-risks"]').trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-test="group-risks-table"]').text()).toContain('20 EUR')
    expect(mocks.get.mock.calls.map(call => call[0].section)).toEqual(['overview', 'cashflow', 'receivables', 'risks'])
    wrapper.unmount()
  })
  it('drops cached data immediately when permissions change and refetches for a new scope', async () => {
    const wrapper = mountPage()
    await flushPromises()
    auth.permissions['dashboard.portfolio'] = 0
    await flushPromises()
    expect(wrapper.find('[data-test="group-financial-table"]').exists()).toBe(false)
    expect(wrapper.text()).not.toContain('100 CZK')
    auth.permissions['dashboard.portfolio'] = 1
    await flushPromises()
    expect(mocks.get).toHaveBeenCalledTimes(2)
    supplier.currentSupplierId = 2
    await flushPromises()
    expect(mocks.get).toHaveBeenCalledTimes(3)
    auth.user.role.system_key = 'accountant'
    await flushPromises()
    expect(mocks.get).toHaveBeenCalledTimes(4)
    wrapper.unmount()
  })
  it('ignores an older request finishing after the user changes tabs', async () => {
    let finish!: (data: GroupDashboard) => void
    mocks.get.mockImplementationOnce(() => new Promise(resolve => { finish = resolve }))
    const wrapper = mountPage()
    await wrapper.find('[data-test="group-tab-trends"]').trigger('click')
    await flushPromises()
    finish(report('overview'))
    await flushPromises()
    expect(wrapper.find('[data-test="group-monthly-table"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="group-financial-table"]').exists()).toBe(false)
    wrapper.unmount()
  })
  it('keeps a failed load as an error and retries the same section', async () => {
    mocks.get.mockRejectedValueOnce(new Error('offline'))
    const wrapper = mountPage()
    await flushPromises()
    expect(wrapper.find('[data-test="group-load-error"]').exists()).toBe(true)
    await wrapper.find('[data-test="group-load-error"] button').trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-test="group-financial-table"]').exists()).toBe(true)
    wrapper.unmount()
  })
})

import { describe, expect, it, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'

const mocks = vi.hoisted(() => ({
  toastError: vi.fn(),
  route: { query: {} as Record<string, string>, hash: '' },
  api: Object.fromEntries([
    'overview', 'monthly', 'yearly', 'topClients', 'topVendors', 'agingReceivables',
    'agingPayables', 'dso', 'punctuality', 'concentration', 'vendorConcentration',
    'dpo', 'expenseBreakdown', 'revenueBreakdown', 'churnRisk', 'cashFlowForecast',
    'lateRisk', 'reminderEffectiveness', 'paymentTimeHistogram',
  ].map(name => [name, vi.fn()])),
}))
vi.mock('@/api/crm', () => ({ crmApi: mocks.api }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({}) }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ error: mocks.toastError }) }))
vi.mock('vue-router', async importOriginal => ({
  ...await importOriginal<typeof import('vue-router')>(),
  useRoute: () => mocks.route,
}))
vi.mock('vue-i18n', async importOriginal => ({
  ...await importOriginal<typeof import('vue-i18n')>(),
  useI18n: () => ({ t: (key: string) => key }),
}))
import CrmDashboard from '../CrmDashboard.vue'

describe('CRM dashboard loading', () => {
  beforeEach(() => {
    mocks.route.query = {}
    mocks.route.hash = ''
    mocks.toastError.mockReset()
    for (const method of Object.values(mocks.api)) method.mockReset().mockResolvedValue([])
  })

  it.each([{ currencies: ['CZK'] }, { currencies: ['EUR'] }, { currencies: ['CZK', 'EUR'] }])('loads one batch after resolving default currency $currencies', async ({ currencies }) => {
    mocks.api.overview.mockResolvedValue({ currencies })
    const wrapper = mount({ ...CrmDashboard, render: () => null })
    await flushPromises()
    expect(mocks.api.overview).toHaveBeenCalledTimes(1)
    expect(mocks.api.monthly).toHaveBeenCalledTimes(2)
    const currency = currencies.length > 1 ? undefined : currencies[0]
    expect(mocks.api.monthly).toHaveBeenCalledWith(12, currency)
    expect(mocks.api.monthly).toHaveBeenCalledWith(24, currency)
    expect(mocks.api.topClients).toHaveBeenCalledTimes(1)
    wrapper.unmount()
  })

  it('keeps drafts disabled initially and reloads only cashflow when toggled', async () => {
    mocks.api.overview.mockResolvedValue({ currencies: ['EUR'] })
    const wrapper = mount({ ...CrmDashboard, render: () => null })
    await flushPromises()
    expect(mocks.api.cashFlowForecast).toHaveBeenCalledWith(4, 'EUR', false)
    const vm = wrapper.vm as unknown as { includeDrafts: boolean; reloadCashFlow: () => Promise<void> }
    vm.includeDrafts = true
    await vm.reloadCashFlow()
    expect(mocks.api.cashFlowForecast).toHaveBeenLastCalledWith(4, 'EUR', true)
    expect(mocks.api.overview).toHaveBeenCalledTimes(1)
    wrapper.unmount()
  })

  it('preserves the period and currency supplied by a risk drilldown', async () => {
    mocks.route.query = { months: '6', currency: 'EUR' }
    mocks.route.hash = '#client_concentration'
    mocks.api.overview.mockResolvedValue({ currencies: ['CZK', 'EUR'] })
    const wrapper = mount({ ...CrmDashboard, render: () => null })
    await flushPromises()
    expect(mocks.api.overview).toHaveBeenCalledTimes(1)
    expect(mocks.api.monthly).toHaveBeenCalledWith(6, 'EUR')
    expect(mocks.api.topClients).toHaveBeenCalledWith(6, 10, 'EUR')
    wrapper.unmount()
  })

  it('reloads once when the user changes the period', async () => {
    mocks.api.overview.mockResolvedValue({ currencies: ['CZK'] })
    const wrapper = mount({ ...CrmDashboard, render: () => null })
    await flushPromises()
    const vm = wrapper.vm as unknown as { periodMonths: number }
    vm.periodMonths = 6
    await flushPromises()
    expect(mocks.api.overview).toHaveBeenCalledTimes(2)
    expect(mocks.api.topClients).toHaveBeenLastCalledWith(6, 10, 'CZK')
    wrapper.unmount()
  })
})


const forecast = (currency: string, drafts = false) => ({ currency, weeks: [], total_in: 0, total_out: 0, total_net: 0, ...(drafts ? { total_draft_in: 200, total_outlook_net: 200 } : {}) })

describe('CRM cashflow souběh a chybové stavy', () => {
  beforeEach(() => {
    mocks.route.query = { currency: 'EUR' }
    mocks.route.hash = ''
    mocks.toastError.mockReset()
    for (const method of Object.values(mocks.api)) method.mockReset().mockResolvedValue([])
    mocks.api.overview.mockResolvedValue({ currencies: ['EUR', 'CZK'] })
    mocks.api.cashFlowForecast.mockResolvedValue(forecast('EUR'))
  })
  it('při neúspěšném zapnutí konceptů vrátí checkbox k naposledy načtené vrstvě', async () => {
    const wrapper = mount({ ...CrmDashboard, render: () => null })
    await flushPromises()
    const vm = wrapper.vm as unknown as { includeDrafts: boolean; cashFlow: ReturnType<typeof forecast>; reloadCashFlow: () => Promise<void> }
    mocks.api.cashFlowForecast.mockRejectedValueOnce(new Error('Dočasná chyba výhledu'))
    vm.includeDrafts = true
    await vm.reloadCashFlow()
    expect(vm.includeDrafts).toBe(false)
    expect(vm.cashFlow).toEqual(forecast('EUR'))
    expect(mocks.toastError).toHaveBeenCalledTimes(1)
    wrapper.unmount()
  })
  it('opožděná odpověď checkboxu nepřepíše novější měnový výhled z loadAll', async () => {
    const wrapper = mount({ ...CrmDashboard, render: () => null })
    await flushPromises()
    const vm = wrapper.vm as unknown as { includeDrafts: boolean; currencyFilter: string; cashFlow: ReturnType<typeof forecast>; reloadCashFlow: () => Promise<void> }
    let resolveOld!: (value: ReturnType<typeof forecast>) => void
    mocks.api.cashFlowForecast.mockReturnValueOnce(new Promise(resolve => { resolveOld = resolve })).mockResolvedValueOnce(forecast('CZK', true))
    vm.includeDrafts = true
    const old = vm.reloadCashFlow()
    vm.currencyFilter = 'CZK'
    await flushPromises()
    expect(vm.cashFlow.currency).toBe('CZK')
    resolveOld(forecast('EUR', true))
    await old
    expect(vm.cashFlow.currency).toBe('CZK')
    expect(vm.includeDrafts).toBe(true)
    wrapper.unmount()
  })
  it('opožděná chyba starého požadavku neodvolá úspěšně načtenou novější vrstvu', async () => {
    const wrapper = mount({ ...CrmDashboard, render: () => null })
    await flushPromises()
    const vm = wrapper.vm as unknown as { includeDrafts: boolean; currencyFilter: string; cashFlow: ReturnType<typeof forecast>; reloadCashFlow: () => Promise<void> }
    let rejectOld!: (reason: Error) => void
    mocks.api.cashFlowForecast.mockReturnValueOnce(new Promise((_resolve, reject) => { rejectOld = reject })).mockResolvedValueOnce(forecast('CZK', true))
    vm.includeDrafts = true
    const old = vm.reloadCashFlow()
    vm.currencyFilter = 'CZK'
    await flushPromises()
    rejectOld(new Error('Starý výhled selhal'))
    await old
    expect(mocks.toastError).not.toHaveBeenCalled()
    expect(vm.cashFlow.currency).toBe('CZK')
    expect(vm.includeDrafts).toBe(true)
    wrapper.unmount()
  })
})

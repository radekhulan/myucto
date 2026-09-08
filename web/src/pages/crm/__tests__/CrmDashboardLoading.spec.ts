import { describe, expect, it, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'

const mocks = vi.hoisted(() => ({
  api: Object.fromEntries([
    'overview', 'monthly', 'yearly', 'topClients', 'topVendors', 'agingReceivables',
    'agingPayables', 'dso', 'punctuality', 'concentration', 'vendorConcentration',
    'dpo', 'expenseBreakdown', 'revenueBreakdown', 'churnRisk', 'cashFlowForecast',
    'lateRisk', 'reminderEffectiveness', 'paymentTimeHistogram',
  ].map(name => [name, vi.fn()])),
}))
vi.mock('@/api/crm', () => ({ crmApi: mocks.api }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({}) }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ error: vi.fn() }) }))
vi.mock('vue-i18n', async importOriginal => ({
  ...await importOriginal<typeof import('vue-i18n')>(),
  useI18n: () => ({ t: (key: string) => key }),
}))
import CrmDashboard from '../CrmDashboard.vue'

describe('CRM dashboard loading', () => {
  beforeEach(() => {
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

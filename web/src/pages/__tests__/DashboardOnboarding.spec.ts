import { describe, expect, it, vi } from 'vitest'
import { flushPromises, shallowMount } from '@vue/test-utils'

const { summary, overview, cashflow } = vi.hoisted(() => ({ summary: vi.fn(), overview: vi.fn(), cashflow: vi.fn() }))
vi.mock('@/api/dashboard', () => ({ dashboardApi: { summary, overview, cashflow } }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ canWrite: () => true, canRead: () => true }) }))
vi.mock('@/stores/supplier', () => ({ useSupplierStore: () => ({ hasSupplier: true, currentSupplier: { company_name: 'Testovací firma' } }) }))
vi.mock('vue-router', () => ({ useRouter: () => ({ push: vi.fn() }), RouterLink: { template: '<a><slot /></a>' } }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))
vi.mock('@/composables/useFormat', () => ({ formatMoney: String, formatMoneyWhole: String, formatDate: String, formatNumber: String }))

import Dashboard from '../Dashboard.vue'

function exampleOverview(hasPurchases: boolean) {
  return {
    has_purchase_invoices: hasPurchases,
    kpi: { issued_count_ytd: 0, purchase_count_ytd: 0, per_currency: [], overdue_per_currency: [] },
    overdue: [], unpaid_upcoming: [], draft_invoices: [], top_clients_12m: [],
    revenue_by_month: [], purchase_costs_by_month: [], due_buckets: [],
  }
}

describe('dashboard onboarding', () => {
  it.each([false, true])('shows initial setup only without received invoices: has_purchase_invoices=%s', async hasPurchases => {
    const data = { ...exampleOverview(hasPurchases), cashflow_forecast: [] }
    summary.mockResolvedValue(data)
    overview.mockResolvedValue(data)
    cashflow.mockClear()
    const wrapper = shallowMount(Dashboard)
    await flushPromises()
    expect(wrapper.findComponent({ name: 'OnboardingGuide' }).exists()).toBe(!hasPurchases)
    expect(wrapper.find('h1').exists()).toBe(hasPurchases)
    if (hasPurchases) expect(wrapper.find('h1').text()).toBe('Testovací firma')
    expect(cashflow).not.toHaveBeenCalled()
    wrapper.unmount()
  })

  it('shows the overview while the forecast is pending and can retry the forecast on its own', async () => {
    overview.mockResolvedValue(exampleOverview(true))
    let fail: () => void = () => {}
    cashflow.mockImplementationOnce(() => new Promise((_, reject) => {
      fail = () => reject(new Error('Unavailable'))
    })).mockResolvedValueOnce([{ currency: 'CZK', in_30: 23756 }])

    const wrapper = shallowMount(Dashboard)
    await flushPromises()
    expect(wrapper.get('h1').text()).toBe('Testovací firma')
    expect(wrapper.get('[role="status"]').text()).toBe('dashboard.loading_data')

    fail()
    await flushPromises()
    const retry = wrapper.get('[role="status"]').element.parentElement?.querySelector('button')
    expect(retry?.textContent).toBe('common.retry')
    retry?.click()
    await flushPromises()
    expect(wrapper.get('h1').text()).toBe('Testovací firma')
    expect(wrapper.text()).toContain('23756')
    wrapper.unmount()
  })
})

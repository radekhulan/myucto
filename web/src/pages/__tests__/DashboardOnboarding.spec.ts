import { describe, expect, it, vi } from 'vitest'
import { flushPromises, shallowMount } from '@vue/test-utils'

const summary = vi.hoisted(() => vi.fn())
vi.mock('@/api/dashboard', () => ({ dashboardApi: { summary } }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ canWrite: () => true, canRead: () => true }) }))
vi.mock('@/stores/supplier', () => ({ useSupplierStore: () => ({ hasSupplier: true, currentSupplier: { company_name: 'Testovací firma' } }) }))
vi.mock('vue-router', () => ({ useRouter: () => ({ push: vi.fn() }), RouterLink: { template: '<a><slot /></a>' } }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))
vi.mock('@/composables/useFormat', () => ({ formatMoney: String, formatDate: String, formatNumber: String }))

import Dashboard from '../Dashboard.vue'

describe('dashboard onboarding', () => {
  it.each([false, true])('shows initial setup only without received invoices: has_purchase_invoices=%s', async hasPurchases => {
    summary.mockResolvedValue({
      has_purchase_invoices: hasPurchases,
      kpi: { issued_count_ytd: 0, purchase_count_ytd: 0, per_currency: [], overdue_per_currency: [] },
      overdue: [], unpaid_upcoming: [], draft_invoices: [], top_clients_ytd: [], top_clients_prev_year: [],
      top_clients_12m: [], revenue_by_month: [], revenue_by_year: [], purchase_costs_by_month: [],
      due_buckets: [], aging_report: [], cashflow_forecast: [],
    })
    const wrapper = shallowMount(Dashboard)
    await flushPromises()
    expect(wrapper.findComponent({ name: 'OnboardingGuide' }).exists()).toBe(!hasPurchases)
    expect(wrapper.find('h1').exists()).toBe(hasPurchases)
    if (hasPurchases) expect(wrapper.find('h1').text()).toBe('Testovací firma')
    wrapper.unmount()
  })
})

import { describe, expect, it, vi } from 'vitest'
import { flushPromises, mount, shallowMount } from '@vue/test-utils'

const summary = vi.hoisted(() => vi.fn())
const supplier = vi.hoisted(() => ({ company_name: 'Testovací firma', dashboard_work_reports: true }))
vi.mock('@/api/dashboard', () => ({ dashboardApi: { summary } }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ canWrite: () => true, canRead: () => true }) }))
vi.mock('@/stores/supplier', () => ({ useSupplierStore: () => ({ hasSupplier: true, currentSupplier: supplier }) }))
vi.mock('vue-router', () => ({ useRouter: () => ({ push: vi.fn() }), RouterLink: { template: '<a><slot /></a>' } }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))
vi.mock('@/composables/useFormat', () => ({ formatMoney: String, formatMoneyWhole: String, formatDate: String, formatNumber: String }))

import Dashboard from '../Dashboard.vue'

function summaryData(hasPurchases: boolean) {
  return {
    has_purchase_invoices: hasPurchases,
    kpi: { issued_count_ytd: 0, purchase_count_ytd: 0, per_currency: [], overdue_per_currency: [] },
    overdue: [], unpaid_upcoming: [], draft_invoices: [], top_clients_ytd: [], top_clients_prev_year: [],
    top_clients_12m: [], revenue_by_month: [], revenue_by_year: [], purchase_costs_by_month: [],
    due_buckets: [], aging_report: [], cashflow_forecast: [],
  }
}

describe('dashboard onboarding', () => {
  it.each([false, true])('shows initial setup only without received invoices: has_purchase_invoices=%s', async hasPurchases => {
    summary.mockResolvedValue(summaryData(hasPurchases))
    const wrapper = shallowMount(Dashboard)
    await flushPromises()
    expect(wrapper.findComponent({ name: 'OnboardingGuide' }).exists()).toBe(!hasPurchases)
    expect(wrapper.find('h1').exists()).toBe(hasPurchases)
    if (hasPurchases) expect(wrapper.find('h1').text()).toBe('Testovací firma')
    wrapper.unmount()
  })

  it.each([true, false])('respects the company setting for the default work reports block: %s', async enabled => {
    supplier.dashboard_work_reports = enabled
    summary.mockResolvedValue({
      ...summaryData(true),
      draft_invoices: [{ id: 123, client_id: 7, client_company_name: 'Syntetický odběratel', total_with_vat: 100, currency: 'CZK' }],
    })
    const wrapper = mount(Dashboard, { global: { stubs: {
      ActionItemsWidget: true, TaxCalendarWidget: true, TaxNetWidget: true, TaxAdvancesWidget: true,
      SparklineChart: true, TopClientsPieChart: true, WorkReportModal: true, DashboardLayoutToolbar: true,
    } } })
    try {
      await flushPromises()
      expect(wrapper.text().includes('dashboard.work_reports.title')).toBe(enabled)
    } finally {
      wrapper.unmount()
      supplier.dashboard_work_reports = true
    }
  })
})

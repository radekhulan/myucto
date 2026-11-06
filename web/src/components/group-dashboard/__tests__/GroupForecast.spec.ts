import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { ref } from 'vue'
import GroupForecast, { type GroupAnnualForecast, type GroupForecastReport } from '../GroupForecast.vue'

vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string, params?: object) => key + (params ? JSON.stringify(params) : ''), locale: ref('cs') }) }))
vi.mock('@/composables/useFormat', () => ({ formatMoneyWhole: (value: number, currency: string) => `${value} ${currency}` }))
const annual = (currency = 'CZK'): GroupAnnualForecast => ({
  year: 2094, currency, revenue_model: 100, costs_model: 30, revenue_current_year: 80, costs_current_year: 20,
  other_revenue: 50, other_costs: 20, other_posted: 10, other_draft: 60,
  revenue: 150, costs: 50, profit: 100, revenue_low: 120, revenue_high: 180, profit_low: 70, profit_high: 130,
})
const report = (): GroupForecastReport => ({
  as_of: '2094-09-30', companies: [{ id: 1, name: 'Synthetic forecast company', document_basis: 'net', issues: [], forecast: [annual()] }],
  totals: { forecast: [{ ...annual(), companies: 1, missing_values: 0 }] },
})
const mountForecast = (data: GroupForecastReport, currency = '') => mount(GroupForecast, {
  props: { report: data, currency }, global: { stubs: { GroupDashboardChart: true, EmptyState: true } },
})

describe('Group annual forecast', () => {
  it('shows annual model and known other-item result impact separately', () => {
    const wrapper = mountForecast(report())
    expect(wrapper.find('[data-test="forecast-revenue"]').text()).toContain('150 CZK')
    expect(wrapper.find('[data-test="forecast-costs"]').text()).toContain('50 CZK')
    expect(wrapper.find('[data-test="forecast-profit"]').text()).toContain('100 CZK')
    expect(wrapper.find('[data-test="forecast-source-table"]').text()).toContain('100 CZK')
    expect(wrapper.find('[data-test="forecast-source-table"]').text()).toContain('20 CZK')
    expect(wrapper.text()).toContain('group_stats.forecast_installments_note')
    expect(wrapper.text()).toContain('group_stats.forecast_method_note')
    wrapper.unmount()
  })
  it('keeps restricted, failed and unknown projected amounts distinct from known zeros', () => {
    const data = report()
    data.companies.push({ id: 2, name: 'Synthetic restricted company', document_basis: 'gross', issues: [] },
      { id: 3, name: 'Synthetic failed company', document_basis: 'net', issues: ['forecast'], forecast: null })
    data.companies[0]!.forecast![0]!.profit = null
    data.totals.forecast[0]!.profit = null
    data.totals.forecast[0]!.missing_values = 1
    const wrapper = mountForecast(data)
    expect(wrapper.find('[data-test="forecast-profit"]').text()).toContain('group_stats.forecast_unknown')
    expect(wrapper.find('[data-test="forecast-profit"] .text-2xl').text()).not.toBe('0 CZK')
    expect(wrapper.find('[data-test="forecast-company-table"]').text()).toContain('group_stats.restricted')
    expect(wrapper.find('[data-test="forecast-company-table"]').text()).toContain('group_stats.failed_part')
    expect(wrapper.text()).toContain('group_stats.partial_total')
    wrapper.unmount()
    const zero = report()
    zero.totals.forecast[0]!.profit = 0
    const known = mountForecast(zero)
    expect(known.find('[data-test="forecast-profit"]').text()).toContain('0 CZK')
    known.unmount()
  })
  it('filters native currencies without mixing their totals or model comparisons', () => {
    const data = report()
    data.companies[0]!.forecast!.push(annual('EUR'))
    data.totals.forecast.push({ ...annual('EUR'), companies: 1, missing_values: 0 })
    const wrapper = mountForecast(data, 'EUR')
    expect(wrapper.find('[data-test="forecast-summary"]').text()).toContain('150 EUR')
    expect(wrapper.find('[data-test="forecast-summary"]').text()).not.toContain('CZK')
    expect(wrapper.find('[data-test="forecast-company-table"]').text()).toContain('100 EUR')
    wrapper.unmount()
  })
})

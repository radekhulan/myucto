import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const m = vi.hoisted(() => ({
  annualReport: vi.fn(),
  error: vi.fn(),
}))

vi.mock('@/api/payroll', () => ({
  payrollApi: { annualReport: m.annualReport },
}))
vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ canRead: (permission: string) => permission === 'payroll.reports' }),
}))
vi.mock('@/composables/useToast', () => ({
  useToast: () => ({ error: m.error }),
}))
vi.mock('vue-i18n', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-i18n')>()),
  useI18n: () => ({ t: (key: string) => key, locale: { value: 'cs-CZ' } }),
}))

import PayrollAnnualReportPanel from '@/pages/payroll/PayrollAnnualReportPanel.vue'

describe('PayrollAnnualReportPanel', () => {
  beforeEach(() => {
    m.annualReport.mockResolvedValue({
      year: 2026,
      totals: {
        approved_revision_count: 2,
        headcount_person_months: 3,
        gross_minor: 150000,
        employer_cost_minor: 201000,
      },
      months: [{
        period: '2026-01',
        approved_revision_count: 1,
        headcount: 3,
        gross_minor: 150000,
        employer_cost_minor: 201000,
      }],
    })
  })

  it('loads only aggregate data for the selected year', async () => {
    const wrapper = mount(PayrollAnnualReportPanel, { props: { initialYear: 2026 } })
    await flushPromises()

    expect(m.annualReport).toHaveBeenCalledWith(2026)
    expect(wrapper.get('[data-test="payroll-annual-report"]').text())
      .toContain('payroll.annual_report.title')
    expect(wrapper.text()).toContain('2026-01')
    expect(wrapper.text()).not.toContain('employee_id')
    expect(wrapper.get('[data-test="annual-report-mobile-months"]').classes()).toContain('md:hidden')
    expect(wrapper.get('[data-test="annual-report-desktop-table"]').classes()).toContain('md:block')
  })

  it('reloads the aggregate when the year changes', async () => {
    const wrapper = mount(PayrollAnnualReportPanel, { props: { initialYear: 2026 } })
    await flushPromises()

    await wrapper.get('input[type="number"]').setValue(2025)
    await flushPromises()

    expect(m.annualReport).toHaveBeenLastCalledWith(2025)
  })
})

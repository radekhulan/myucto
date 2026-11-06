import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import type { PayrollRunResultPerson } from '@/api/payroll'
import PayrollPayslipHourlyBreakdown from '../PayrollPayslipHourlyBreakdown.vue'

vi.mock('vue-i18n', async (importOriginal) => ({ ...(await importOriginal<typeof import('vue-i18n')>()), useI18n: () => ({ t: (key: string, values?: Record<string, unknown>) => `${key}${values ? JSON.stringify(values) : ''}` }) }))

function person(): PayrollRunResultPerson {
  return { employee_id: 1, payslip_document: {
    schema_version: 'payroll-payslip-document.v3',
    income_lines: [{ label: 'Oprava nároku', amount_minor_units: -123, hourly_detail: {
      claim_id: 8, purpose: 'ordinary_base', work_period_start: '2026-07-01', sequence_no: 2,
      display_basis: 'delta', quantity_duration_units: 53, quantity_text: '0:01:03.6', rate_minor: 12345,
      full_payable_minor: 999, previous_payable_minor: 1122, delta_amount_minor: -123, worked_duration_delta_units: 0,
    } }],
    hourly_claims: [{ employment_id: 2, claim_id: 8, purpose: 'ordinary_base', label: 'Zmrazený základ', work_period_start: '2026-07-01', full_duration_units: 53, full_worked_duration_units: 53, raw_numerator_minor: 654285, raw_denominator: 3000, analytical_minor: 218, payable_minor: 999, rounding_adjustment_minor: 781,
      segments: [{ date_from: '2026-07-01', date_to: '2026-07-15', duration_units: 53, quantity_text: '0:01:03.6', rate_minor: 12345, raw_numerator_minor: 654285, raw_denominator: 3000, analytical_minor: 218 }] }],
  } }
}

describe('PayrollPayslipHourlyBreakdown', () => {
  it('keeps historical documents without hourly claims unchanged', () => {
    const wrapper = mount(PayrollPayslipHourlyBreakdown, { props: { people: [{ employee_id: 1, payslip_document: { schema_version: 'payroll-payslip-document.v2', income_lines: [{ label: 'Mzda', amount_minor_units: 50000 }] } }] } })
    expect(wrapper.find('[data-test="payslip-hourly-breakdown"]').exists()).toBe(false)
  })

  it('shows frozen rates, exact quantities, and delta separately from full claim without recalculation', () => {
    const wrapper = mount(PayrollPayslipHourlyBreakdown, { props: { people: [person()], personNames: { 1: 'Testovací osoba' } } })
    expect(wrapper.get('[data-test="payslip-hourly-line"]').text()).toContain('0:01:03.6')
    expect(wrapper.get('[data-test="payslip-hourly-delta"]').text()).toContain('payroll.payslip_hourly.delta')
    expect(wrapper.get('[data-test="payslip-hourly-claim"]').text()).toContain('Zmrazený základ')
    expect(wrapper.get('[data-test="payslip-hourly-claim"]').text()).toContain('9,99')
    expect(wrapper.get('[data-test="payslip-hourly-segment"]').text()).toContain('123,45')
  })

  it('explains cumulative correction rows and separately shows the signed worked-hours difference', () => {
    const data = person()
    data.payslip_document!.income_lines[0]!.hourly_detail!.worked_duration_delta_units = -53
    const wrapper = mount(PayrollPayslipHourlyBreakdown, { props: { people: [data] } })
    expect(wrapper.get('[data-test="payslip-hourly-cumulative"]').text()).toContain('payroll.payslip_hourly.cumulative')
    expect(wrapper.get('[data-test="payslip-hourly-hours-delta"]').text()).toContain('-0:01:03.6')
  })

  it('does not substitute a contractual rate for a premium with no frozen rate', () => {
    const data = person()
    data.payslip_document!.income_lines[0]!.hourly_detail!.rate_minor = null
    data.payslip_document!.hourly_claims![0]!.segments[0]!.rate_minor = null
    const wrapper = mount(PayrollPayslipHourlyBreakdown, { props: { people: [data] } })
    expect(wrapper.get('[data-test="payslip-hourly-segment"]').text()).not.toContain('payroll.hourly_preview.rate')
  })
})

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
    const detail = wrapper.get('[data-test="payslip-hourly-line"] [data-test="payslip-hourly-line-claim"]')
    expect(detail.text()).toContain('payroll.payslip_hourly.rounding')
    expect(detail.text()).toContain('7,81')
    expect(wrapper.get('[data-test="payslip-hourly-segment"]').text()).toContain('123,45')
  })

  it('nárok s vlastním řádkem pásky nevypíše podruhé a nulové nároky schová pod rozbalení', async () => {
    const data = person()
    const base = data.payslip_document!.hourly_claims![0]!
    data.payslip_document!.hourly_claims!.push(
      { ...base, claim_id: 9, purpose: 'premium_night', label: 'Příplatek za noc', payable_minor: 4500, full_duration_units: 300, segments: [] },
      { ...base, claim_id: 10, purpose: 'holiday_time_off_compensation', label: 'Náhrada za volno za práci ve svátek', payable_minor: 0, full_duration_units: 0, analytical_minor: 0, segments: [] },
      { ...base, claim_id: 11, purpose: 'premium_weekend', label: 'Příplatek za víkend', payable_minor: 0, full_duration_units: 0, analytical_minor: 0, segments: [] },
    )
    const wrapper = mount(PayrollPayslipHourlyBreakdown, { props: { people: [data] } })
    expect(wrapper.findAll('[data-test="payslip-hourly-line"]')).toHaveLength(1)
    const claims = wrapper.findAll('[data-test="payslip-hourly-claim"]')
    expect(claims).toHaveLength(1)
    expect(claims[0]!.text()).toContain('Příplatek za noc')
    expect(wrapper.text()).not.toContain('Zmrazený základ')
    const toggle = wrapper.get('[data-test="payslip-hourly-zero-toggle"]')
    expect(toggle.text()).toContain('payroll.payslip_hourly.show_zero_claims{"count":2}')
    expect(wrapper.find('[data-test="payslip-hourly-zero-claim"]').exists()).toBe(false)
    await toggle.trigger('click')
    const zero = wrapper.findAll('[data-test="payslip-hourly-zero-claim"]')
    expect(zero).toHaveLength(2)
    expect(zero[0]!.text()).toContain('Náhrada za volno za práci ve svátek')
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

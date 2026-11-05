import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import type { PayrollHourlyWagePreview } from '@/api/payroll'
import PayrollHourlyWagePreviewView from '../PayrollHourlyWagePreview.vue'

vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string, params?: Record<string, unknown>) => `${key}${params ? JSON.stringify(params) : ''}` }) }))
vi.mock('@/composables/useFormat', () => ({ formatDate: (value: string) => value, formatMoneyMinor: (value: number) => `money:${value}` }))
function preview(overrides: Partial<PayrollHourlyWagePreview> = {}): PayrollHourlyWagePreview {
  return { complete: true, blockers: [], rounding: 'ceil', evidence_hash: 'a'.repeat(64), payable_total_minor: 123400, entitlements: [], included_allocations: [], claims: [{ ready: true, worked_duration_units: 53, purpose: 'ordinary_base', component_code: 'BASE', duration_units: 53, raw_numerator_minor: 1, raw_denominator: 3, analytical_minor: 123367, payable_minor: 123400, rounding: 'ceil', segments: [{ term_id: 1, term_row_version: 2, date_from: '2026-11-01', date_to: '2026-11-30', duration_units: 53, hourly_rate_minor: 19000, monthly_gross_minor: null, raw_numerator_minor: 1, raw_denominator: 3 }] }], ...overrides }
}
describe('PayrollHourlyWagePreview', () => {
  it('shows server amounts and exact segments without recomputing money', () => {
    const wrapper = mount(PayrollHourlyWagePreviewView, { props: { value: preview() } })
    expect(wrapper.get('[data-test="hourly-preview-total"]').text()).toContain('money:123400')
    expect(wrapper.get('[data-test="hourly-preview-claim"]').text()).toContain('money:123400')
    expect(wrapper.get('[data-test="hourly-preview-segment"]').text()).toContain('0:01:03.6')
    expect(wrapper.get('[data-test="hourly-preview-segment"]').text()).toContain('money:19000')
  })
  it('distinguishes incomplete total from confirmed zero and shows concrete blockers', async () => {
    const wrapper = mount(PayrollHourlyWagePreviewView, { props: { value: preview({ complete: false, payable_total_minor: null, blockers: [{ code: 'missing', message: 'Syntetický chybějící podklad', context: {} }] }) } })
    expect(wrapper.get('[data-test="hourly-preview-total"]').text()).toContain('payroll.worked_hours.unknown')
    expect(wrapper.get('[data-test="hourly-preview-blockers"]').text()).toContain('Syntetický chybějící podklad')
    await wrapper.setProps({ value: preview({ payable_total_minor: 0 }) })
    expect(wrapper.get('[data-test="hourly-preview-total"]').text()).toContain('money:0')
  })
  it('does not present a partially evidenced premium as a complete claim', () => {
    const value = preview({ complete: false, payable_total_minor: null })
    value.claims[0] = { ...value.claims[0]!, ready: false, payable_minor: null, analytical_minor: null, raw_numerator_minor: null, raw_denominator: null }
    const wrapper = mount(PayrollHourlyWagePreviewView, { props: { value } })
    expect(wrapper.get('[data-test="hourly-preview-claim"]').text()).toContain('claim_incomplete')
    expect(wrapper.get('[data-test="hourly-preview-claim"]').text()).not.toContain('money:123400')
  })
  it('renders compensation traces without pretending they contain a contractual hourly rate', () => {
    const value = preview()
    value.claims[0] = { ...value.claims[0]!, purpose: 'holiday_time_off_compensation', segments: [{ date: '2026-11-02', duration_units: 53 }] }
    const wrapper = mount(PayrollHourlyWagePreviewView, { props: { value } })
    expect(wrapper.get('[data-test="hourly-preview-claim"]').text()).toContain('holiday_time_off_compensation')
    expect(wrapper.get('[data-test="hourly-preview-segment"]').text()).toContain('2026-11-02')
    expect(wrapper.text()).not.toContain('money:undefined')
    expect(wrapper.get('[data-test="hourly-preview-segment"]').text()).not.toContain('monthly_part')
  })
  it('limits row blocker detail while keeping all reasons in the expanded panel', () => {
    const value = preview({ blockers: [{ code: 'one', message: 'První syntetický podklad', context: {} }, { code: 'two', message: 'Druhý syntetický podklad', context: {} }] })
    const compact = mount(PayrollHourlyWagePreviewView, { props: { value, compact: true } })
    expect(compact.text()).toContain('První syntetický podklad')
    expect(compact.text()).not.toContain('Druhý syntetický podklad')
    expect(compact.text()).toContain('more_blockers')
    const expanded = mount(PayrollHourlyWagePreviewView, { props: { value } })
    expect(expanded.text()).toContain('Druhý syntetický podklad')
  })
  it('keeps compact rows small while the shared panel provides detailed rates', () => {
    const wrapper = mount(PayrollHourlyWagePreviewView, { props: { value: preview(), compact: true } })
    expect(wrapper.find('[data-test="hourly-preview-claim"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="hourly-preview-total"]').exists()).toBe(true)
  })
})

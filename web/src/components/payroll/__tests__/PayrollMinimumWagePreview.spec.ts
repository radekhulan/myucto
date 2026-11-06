import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import type { PayrollMinimumWagePreview } from '@/api/payroll'
import PayrollMinimumWagePreviewView from '../PayrollMinimumWagePreview.vue'
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string, params?: Record<string, unknown>) => params ? `${key}:${JSON.stringify(params)}` : key, te: (key: string) => key.startsWith('payroll.minimum.blockers.hourly_') }) }))
vi.mock('@/composables/useFormat', () => ({ formatDate: (value: string) => value, formatMoneyMinor: (value: number) => `money:${value}` }))
function preview(overrides: Partial<PayrollMinimumWagePreview> = {}): PayrollMinimumWagePreview {
  return { complete: true, claim: { ready: true, duration_units: 3, payable_minor: 77700 }, blockers: [], evidence_hash: 'a'.repeat(64), assessment_evidence: { assessment: 'hourly', threshold: { raw_numerator_minor: 100001, raw_denominator: 3, analytical_minor: 33334 }, eligible: { raw_numerator_minor: 100, raw_denominator: 1, analytical_minor: 100 }, ordinary_payable_minor: 100, company_rounding: 'half_up', statutory_floor_rounding: 'ceil', policy_payable_minor: 100, excluded: [{ component_code: 'SYN_EXCLUDED', reason: 'component_excluded', analytical_minor: -999 }], parts: [{ term_id: 1, date_from: '2026-11-01', date_to: '2026-11-30', regular_worked_duration_units: 3, stated_weekly_minutes: 2250, minimum_base40_minor: 13440, minimum_hourly_minor: 14340, analytical_minor: 33334 }] }, ...overrides }
}
describe('PayrollMinimumWagePreview', () => {
  it('shows server amounts unchanged, including a negative excluded correction', () => {
    const wrapper = mount(PayrollMinimumWagePreviewView, { props: { value: preview() } })
    expect(wrapper.get('[data-test="minimum-required"]').text()).toBe('money:33334')
    expect(wrapper.get('[data-test="minimum-eligible"]').text()).toBe('money:100')
    // Úmyslně nesedí na rozdíl vstupů: prohlížeč výsledek nesmí přepočítat.
    expect(wrapper.get('[data-test="minimum-top-up"]').text()).toBe('money:77700')
    expect(wrapper.get('[data-test="minimum-excluded-item"]').text()).toContain('money:-999')
    expect(wrapper.get('[data-test="minimum-part"]').text()).toContain('37:30')
    expect(wrapper.get('[data-test="minimum-part"]').text()).toContain('0,001')
  })
  it('distinguishes a verified zero without a floor from unknown evidence', async () => {
    const value = preview({ claim: { ready: true, duration_units: 0, payable_minor: 0 } })
    value.assessment_evidence.threshold = { raw_numerator_minor: 0, raw_denominator: 1, analytical_minor: 0 }
    value.assessment_evidence.eligible = { raw_numerator_minor: 0, raw_denominator: 1, analytical_minor: 0 }
    value.assessment_evidence.excluded = []
    value.assessment_evidence.parts[0] = { ...value.assessment_evidence.parts[0]!, regular_worked_duration_units: 0, minimum_hourly_minor: null, minimum_base40_minor: null, stated_weekly_minutes: null }
    const wrapper = mount(PayrollMinimumWagePreviewView, { props: { value } })
    expect(wrapper.get('[data-test="minimum-top-up"]').text()).toBe('money:0')
    expect(wrapper.find('[data-test="minimum-confirmed-zero"]').exists()).toBe(true)
    expect(wrapper.get('[data-test="minimum-part"]').text()).toContain('0:00')
    expect(wrapper.get('[data-test="minimum-part"]').text()).toContain('payroll.worked_hours.unknown')
    const unknown = preview({ complete: false, claim: { ready: false, duration_units: null, payable_minor: null } })
    unknown.assessment_evidence.threshold = null; unknown.assessment_evidence.eligible = null; unknown.assessment_evidence.excluded = []
    await wrapper.setProps({ value: unknown })
    expect(wrapper.get('[data-test="minimum-top-up"]').text()).toContain('unknown')
    expect(wrapper.get('[data-test="minimum-required"]').text()).toContain('unknown')
    expect(wrapper.find('[data-test="minimum-confirmed-zero"]').exists()).toBe(false)
    expect(wrapper.text()).toContain('excluded_unknown')
    expect(wrapper.text()).not.toContain('none_excluded')
  })
  it('does not confirm a calculated claim if the wrapper reports an additional conflict', () => {
    const wrapper = mount(PayrollMinimumWagePreviewView, { props: { value: preview({ complete: false, blockers: [{ code: 'conflict', message: 'Syntetický rozpor podkladů', context: {} }] }) } })
    expect(wrapper.get('[data-test="minimum-top-up"]').text()).toContain('unknown')
    expect(wrapper.get('[data-test="minimum-blockers"]').text()).toContain('Syntetický rozpor podkladů')
  })
  it('translates known blocker codes and the other-period exclusion instead of showing the server message', () => {
    const value = preview({ complete: false, blockers: [{ code: 'hourly_minimum_monthly_fund_missing', message: 'Česká zpráva serveru', context: {} }] })
    value.assessment_evidence.excluded = [{ component_code: 'ODMENA', reason: 'source_period_other', analytical_minor: 5000 }]
    const wrapper = mount(PayrollMinimumWagePreviewView, { props: { value } })
    expect(wrapper.get('[data-test="minimum-blockers"]').text()).toBe('payroll.minimum.blockers.hourly_minimum_monthly_fund_missing')
    expect(wrapper.get('[data-test="minimum-excluded-item"]').text()).toContain('payroll.minimum.excluded_source_period')
  })
})

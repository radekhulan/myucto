import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import type { PayrollHourlyWagePreview, PayrollMinimumWagePreview } from '@/api/payroll'
import PayrollHourlyWagePreviewView from '../PayrollHourlyWagePreview.vue'

vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string, params?: Record<string, unknown>) => `${key}${params ? JSON.stringify(params) : ''}`, te: (key: string) => key === 'payroll.hourly_preview.blockers.hourly_time_missing' }) }))
vi.mock('@/composables/useFormat', () => ({ formatDate: (value: string) => value, formatMoneyMinor: (value: number) => `money:${value}` }))
function preview(overrides: Partial<PayrollHourlyWagePreview> = {}): PayrollHourlyWagePreview {
  return { complete: true, blockers: [], rounding: 'ceil', evidence_hash: 'a'.repeat(64), payable_total_minor: 123400, entitlements: [], included_allocations: [], claims: [{ ready: true, worked_duration_units: 53, purpose: 'ordinary_base', component_code: 'BASE', duration_units: 53, raw_numerator_minor: 1, raw_denominator: 3, analytical_minor: 123367, payable_minor: 123400, rounding: 'ceil', segments: [{ term_id: 1, term_row_version: 2, date_from: '2026-11-01', date_to: '2026-11-30', duration_units: 53, hourly_rate_minor: 19000, monthly_gross_minor: null, raw_numerator_minor: 1, raw_denominator: 3 }] }], ...overrides }
}
function minimum(complete = true): PayrollMinimumWagePreview {
  return { complete, claim: { ready: complete, duration_units: complete ? 0 : null, payable_minor: complete ? 99900 : null }, blockers: [], evidence_hash: 'b'.repeat(64), assessment_evidence: { assessment: 'hourly', threshold: null, eligible: null, ordinary_payable_minor: 0, company_rounding: 'ceil', statutory_floor_rounding: 'ceil', policy_payable_minor: null, excluded: [], parts: [] } }
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
  it('translates known blocker codes and keeps the server message only as a fallback', () => {
    const wrapper = mount(PayrollHourlyWagePreviewView, { props: { value: preview({ complete: false, payable_total_minor: null, blockers: [
      { code: 'hourly_time_missing', message: 'Doložte skutečně odpracovanou dobu.', context: {} },
      { code: 'unknown_code', message: 'Syntetická záložní zpráva', context: {} },
    ] }) } })
    const text = wrapper.get('[data-test="hourly-preview-blockers"]').text()
    expect(text).toContain('payroll.hourly_preview.blockers.hourly_time_missing')
    expect(text).not.toContain('Doložte skutečně odpracovanou dobu.')
    expect(text).toContain('Syntetická záložní zpráva')
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
  it('limits row blocker detail to one summary line and keeps all reasons behind the expander', () => {
    const value = preview({ blockers: [{ code: 'one', message: 'První syntetický podklad', context: {} }, { code: 'two', message: 'Druhý syntetický podklad', context: {} }] })
    const compact = mount(PayrollHourlyWagePreviewView, { props: { value, compact: true } })
    const toggle = compact.get('[data-test="hourly-preview-blockers-toggle"]')
    expect((toggle.element as HTMLDetailsElement).open).toBe(false)
    expect(toggle.get('summary').text()).toContain('První syntetický podklad')
    expect(toggle.get('summary').text()).not.toContain('Druhý syntetický podklad')
    expect(toggle.get('summary').text()).toContain('more_blockers')
    expect(compact.get('[data-test="hourly-preview-blockers"]').text()).toContain('Druhý syntetický podklad')
    const expanded = mount(PayrollHourlyWagePreviewView, { props: { value } })
    expect(expanded.text()).toContain('Druhý syntetický podklad')
  })
  it('stejnou blokaci hlášenou po úsecích ukáže jen jednou', () => {
    const repeated = { code: 'hourly_premium_evidence_missing', message: 'Doložte příplatkové vrstvy ke každému úseku pracovní doby.', context: {} }
    const value = preview({ complete: false, payable_total_minor: null, blockers: [repeated, { ...repeated, context: { segment: 2 } }, { ...repeated, context: { segment: 3 } }, { ...repeated, context: { segment: 4 } }] })
    const expanded = mount(PayrollHourlyWagePreviewView, { props: { value } })
    expect(expanded.findAll('[data-test="hourly-preview-blockers"] li')).toHaveLength(1)
    const compact = mount(PayrollHourlyWagePreviewView, { props: { value, compact: true } })
    expect(compact.findAll('[data-test="hourly-preview-blockers"] li')).toHaveLength(1)
    expect(compact.get('summary').text()).not.toContain('more_blockers')
  })
  it('doložené nulové nároky schová pod rozbalení, nedoložené nechá vidět', async () => {
    const value = preview()
    value.claims.push(
      { ...value.claims[0]!, purpose: 'premium_holiday', duration_units: 0, payable_minor: 0, analytical_minor: 0, segments: [] },
      { ...value.claims[0]!, purpose: 'premium_night', ready: false, duration_units: null, payable_minor: null, analytical_minor: null, segments: [] },
    )
    const wrapper = mount(PayrollHourlyWagePreviewView, { props: { value } })
    const claims = wrapper.findAll('[data-test="hourly-preview-claim"]')
    expect(claims).toHaveLength(2)
    expect(claims.map(claim => claim.text()).join(' ')).not.toContain('premium_holiday')
    expect(claims[1]!.text()).toContain('claim_incomplete')
    const toggle = wrapper.get('[data-test="hourly-preview-zero-toggle"]')
    expect(toggle.text()).toContain('show_zero_claims')
    expect(wrapper.find('[data-test="hourly-preview-zero-claim"]').exists()).toBe(false)
    await toggle.trigger('click')
    expect(wrapper.get('[data-test="hourly-preview-zero-claim"]').text()).toContain('premium_holiday')
  })
  it('does not assess current minimum for settlement-only historical claims', () => {
    const wrapper = mount(PayrollHourlyWagePreviewView, { props: { value: preview({ settlement_only: true, minimum_wage: minimum() }) } })
    expect(wrapper.find('[data-test="minimum-wage-preview"]').exists()).toBe(false)
    expect(wrapper.get('[data-test="minimum-settlement-only"]').text()).toContain('settlement_only')
  })
  it('preserves the known base while suppressing an unconfirmed minimum claim', () => {
    const value = preview({ complete: false, payable_total_minor: null, minimum_wage: minimum(false) })
    value.claims.push({ ...value.claims[0]!, purpose: 'minimum_wage_top_up' })
    const wrapper = mount(PayrollHourlyWagePreviewView, { props: { value } })
    const claims = wrapper.findAll('[data-test="hourly-preview-claim"]')
    expect(claims[0]!.text()).toContain('money:123400')
    expect(claims[1]!.text()).toContain('claim_incomplete')
    expect(claims[1]!.text()).not.toContain('money:123400')
  })
  it('keeps compact rows small while the shared panel provides detailed rates', () => {
    const wrapper = mount(PayrollHourlyWagePreviewView, { props: { value: preview(), compact: true } })
    expect(wrapper.find('[data-test="hourly-preview-claim"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="hourly-preview-total"]').exists()).toBe(true)
  })
})

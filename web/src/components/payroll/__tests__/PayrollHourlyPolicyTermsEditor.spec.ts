import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import type { PayrollHourlyPolicyTerms } from '@/api/payroll'
import PayrollHourlyPolicyTermsEditor from '../PayrollHourlyPolicyTermsEditor.vue'
vi.mock('vue-i18n', () => ({ useI18n: () => ({ locale: { value: 'cs' }, t: (key: string) => key }) }))
describe('PayrollHourlyPolicyTermsEditor', () => {
  it('defaults to hourly without inventing a weekly norm or metadata', async () => {
    const wrapper = mount(PayrollHourlyPolicyTermsEditor, { props: { modelValue: null, disabled: false, allowMonthlyAssessment: false } })
    expect((wrapper.get('[data-test="minimum-assessment"]').element as HTMLSelectElement).value).toBe('hourly')
    expect(wrapper.find('[data-test="minimum-assessment"] option[value="monthly"]').exists()).toBe(false)
    expect((wrapper.get('[data-test="minimum-weekly"]').element as HTMLInputElement).value).toBe('')
    await wrapper.get('[data-test="minimum-weekly"]').setValue('37:30')
    expect(wrapper.emitted('valid')?.at(-1)).toEqual([false])
    await wrapper.get('[data-test="minimum-weekly-reference"]').setValue('Syntetický zákonný režim')
    expect(wrapper.emitted('valid')?.at(-1)).toEqual([true])
    expect(wrapper.emitted('update:modelValue')?.at(-1)?.[0]).toMatchObject({ minimum_wage: { assessment: 'hourly', statutory_weekly_minutes: 2250, statutory_weekly_reference: 'Syntetický zákonný režim', advance_proof: null } })
    await wrapper.get('[data-test="minimum-weekly"]').setValue('0,001')
    expect(wrapper.get('[data-test="minimum-policy-error"]').text()).toContain('weekly_invalid')
    expect(wrapper.emitted('valid')?.at(-1)).toEqual([false])
  })

  it('requires prior proof for monthly assessment and clears that proof on returning to hourly', async () => {
    const wrapper = mount(PayrollHourlyPolicyTermsEditor, { props: { modelValue: null, disabled: false, allowMonthlyAssessment: true } })
    await wrapper.get('[data-test="minimum-assessment"]').setValue('monthly')
    expect(wrapper.emitted('valid')?.at(-1)).toEqual([false])
    await wrapper.get('[data-test="minimum-reference"]').setValue('Syntetické sjednání')
    await wrapper.get('[data-test="minimum-proof-reference"]').setValue('Syntetické předchozí rozhodnutí')
    const dates = wrapper.findAllComponents({ name: 'DateInput' })
    dates[0]!.vm.$emit('update:modelValue', '2026-11-02')
    dates[1]!.vm.$emit('update:modelValue', '2026-11-01')
    await wrapper.vm.$nextTick()
    expect(wrapper.emitted('valid')?.at(-1)).toEqual([false])
    dates[0]!.vm.$emit('update:modelValue', '2026-10-31')
    await wrapper.vm.$nextTick()
    expect(wrapper.emitted('valid')?.at(-1)).toEqual([true])
    expect(wrapper.emitted('update:modelValue')?.at(-1)?.[0]).toMatchObject({ minimum_wage: { assessment: 'monthly', advance_proof: { decided_on: '2026-10-31', valid_from: '2026-11-01', valid_to: null } } })
    await wrapper.get('[data-test="minimum-assessment"]').setValue('hourly')
    expect(wrapper.emitted('update:modelValue')?.at(-1)?.[0]).toMatchObject({ minimum_wage: { assessment: 'hourly', advance_proof: null } })
  })

  it('preserves a stored minimum policy when editing unrelated overtime evidence', async () => {
    const minimum = { assessment: 'hourly' as const, agreement_reference: null, statutory_weekly_minutes: 2250, statutory_weekly_reference: 'Syntetický podklad stanovené doby', advance_proof: null }
    const wrapper = mount(PayrollHourlyPolicyTermsEditor, { props: { disabled: false, modelValue: { minimum_wage: minimum, included_agreement_premiums: [], included_overtime: { agreement_kind: 'contract', agreement_reference: 'Syntetická smlouva', scope_kind: 'annual_hours', calendar_year: 2026, annual_limit_duration_units: 450000, manager_proof_reference: null, opening_used_duration_units: 0, opening_as_of: '2026-01-01', opening_reference: 'Syntetická potvrzená nula' } } } })
    await wrapper.get('[data-test="hourly-policy-reference"]').setValue('Opravená syntetická reference')
    expect(wrapper.emitted('valid')?.at(-1)).toEqual([true])
    expect((wrapper.emitted('update:modelValue')?.at(-1)?.[0] as PayrollHourlyPolicyTerms).minimum_wage).toEqual(minimum)
  })

  it('requires explicit opening evidence and preserves exact annual duration', async () => {
    const wrapper = mount(PayrollHourlyPolicyTermsEditor, { props: { modelValue: null, disabled: false } })
    await wrapper.get('[data-test="hourly-policy-enabled"]').setValue(true)
    expect(wrapper.emitted('valid')?.at(-1)).toEqual([false])
    await wrapper.get('[data-test="hourly-policy-reference"]').setValue('Syntetická smlouva')
    await wrapper.get('[data-test="hourly-policy-year"]').setValue('2026')
    await wrapper.get('[data-test="hourly-policy-limit"]').setValue('150,001')
    await wrapper.get('[data-test="hourly-policy-opening"]').setValue('0')
    await wrapper.get('[data-test="hourly-policy-opening-reference"]').setValue('Syntetické potvrzení nuly')
    // DateInput zachovává vlastní masku; pro model použijeme jeho veřejný kontrakt.
    wrapper.getComponent({ name: 'DateInput' }).vm.$emit('update:modelValue', '2026-01-01')
    await wrapper.vm.$nextTick()
    expect(wrapper.emitted('valid')?.at(-1)).toEqual([true])
    expect(wrapper.emitted('update:modelValue')?.at(-1)?.[0]).toMatchObject({ included_overtime: { annual_limit_duration_units: 450003, opening_used_duration_units: 0, opening_as_of: '2026-01-01', agreement_kind: 'contract' } })
    await wrapper.get('[data-test="hourly-policy-scope"]').setValue('manager_statutory')
    expect(wrapper.emitted('valid')?.at(-1)).toEqual([false])
    await wrapper.get('[data-test="hourly-policy-manager"]').setValue('Syntetické doložení role')
    expect(wrapper.emitted('valid')?.at(-1)).toEqual([true])
  })
})

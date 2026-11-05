import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import PayrollHourlyPolicyTermsEditor from '../PayrollHourlyPolicyTermsEditor.vue'
vi.mock('vue-i18n', () => ({ useI18n: () => ({ locale: { value: 'cs' }, t: (key: string) => key }) }))
describe('PayrollHourlyPolicyTermsEditor', () => {
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

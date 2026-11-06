import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import StereoNxAccountTargetInput from '../StereoNxAccountTargetInput.vue'

vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))

const accounts = [
  { code: '501.001', name: 'Původní materiál' },
  { code: '501.002', name: 'Kancelářské potřeby' },
  { code: '501.003', name: 'Kancelářské vybavení' },
  { code: '501.004', name: 'Kancelářské neaktivní', is_active: false },
  { code: '518.001', name: 'Kancelářské služby' },
]
function picker() {
  return mount(StereoNxAccountTargetInput, { props: { modelValue: '501.001', sourceCode: '501001', accounts, label: 'Synthetic target', testId: 'target' }, global: { stubs: { teleport: true } } })
}

describe('StereoNxAccountTargetInput', () => {
  it('searches by name without case or accents, limits the source synthetic account and selects only its code', async () => {
    const wrapper = picker()
    const input = wrapper.get('input')
    await input.trigger('focus')
    await input.setValue('kANCELARSKE')
    expect(wrapper.findAll('[role="option"]').map(option => option.text())).toEqual(['501.002Kancelářské potřeby', '501.003Kancelářské vybavení'])
    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([''])
    await wrapper.findAll('[role="option"]')[0]!.trigger('click')
    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual(['501.002'])
    expect((input.element as HTMLInputElement).value).toBe('501.002')
    expect(input.attributes('aria-expanded')).toBe('false')
    wrapper.unmount()
  })

  it('supports arrow keys, Enter and Escape while retaining direct custom numeric account entry', async () => {
    const wrapper = picker()
    const input = wrapper.get('input')
    await input.trigger('focus')
    await input.setValue('Kancelářské')
    await input.trigger('keydown', { key: 'ArrowDown' })
    await input.trigger('keydown', { key: 'Enter' })
    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual(['501.003'])
    await wrapper.setProps({ modelValue: '501.003' })
    await input.trigger('focus')
    await input.setValue('501999')
    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual(['501.999'])
    await input.trigger('keydown', { key: 'Escape' })
    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual(['501.003'])
    expect(input.attributes('aria-expanded')).toBe('false')
    wrapper.unmount()
  })

  it('offers only numeric 221 analytics for banks and searches their account numbers', async () => {
    const wrapper = mount(StereoNxAccountTargetInput, { props: { modelValue: '', sourceCode: '221', bank: true, accounts: [
      { code: '221.001', name: 'Synthetic bank one' }, { code: '221.002', name: 'Synthetic bank two' },
      { code: '221.ABC', name: 'Non-numeric bank' }, { code: '221', name: 'Synthetic GL' }, { code: '343.001', name: 'VAT' },
    ], label: 'Synthetic bank target', testId: 'bank-target' }, global: { stubs: { teleport: true } } })
    const input = wrapper.get('input')
    await input.trigger('focus')
    expect(wrapper.findAll('[role="option"]')).toHaveLength(2)
    await input.setValue('221001')
    expect(wrapper.findAll('[role="option"]')).toHaveLength(1)
    await input.trigger('keydown', { key: 'Enter' })
    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual(['221.001'])
    wrapper.unmount()
  })

  it('offers only the canonical account when both raw and dotted account codes exist', async () => {
    const wrapper = mount(StereoNxAccountTargetInput, { props: { modelValue: '501.001', sourceCode: '501001', accounts: [
      { code: '501.001', name: 'Canonical name' }, { code: '501001', name: 'Legacy name' },
    ], label: 'Synthetic target', testId: 'target' }, global: { stubs: { teleport: true } } })
    await wrapper.get('input').trigger('focus')
    expect(wrapper.findAll('[role="option"]').map(option => option.text())).toEqual(['501.001Canonical name'])
    wrapper.unmount()
  })

  it('does not offer a raw legacy bank analytic as its canonical dotted target', async () => {
    const wrapper = mount(StereoNxAccountTargetInput, { props: { modelValue: '', sourceCode: '221', bank: true, accounts: [
      { code: '221100', name: 'Raw legacy bank' }, { code: '221.101', name: 'Canonical bank' },
    ], label: 'Synthetic bank target', testId: 'bank-target' }, global: { stubs: { teleport: true } } })
    await wrapper.get('input').trigger('focus')
    expect(wrapper.findAll('[role="option"]').map(option => option.text())).toEqual(['221.101Canonical bank'])
    wrapper.unmount()
  })
})

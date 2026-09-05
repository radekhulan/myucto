import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import type { ChartAccount } from '@/api/accounting'
import ChartAccountSelect from '../ChartAccountSelect.vue'

vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))

const accounts = [
  { id: 1, account_code: '518', name: 'Services', is_active: true, parent_id: null },
  { id: 2, account_code: '518.100', name: 'Hosting', is_active: true, parent_id: 1 },
  { id: 3, account_code: '518.200', name: 'Inactive service', is_active: false, parent_id: 1 },
] as ChartAccount[]

describe('ChartAccountSelect', () => {
  it('uses the native account text input with code and name suggestions', async () => {
    const wrapper = mount(ChartAccountSelect, { props: { modelValue: '', accounts }, global: { stubs: { Teleport: true } } })
    expect(wrapper.find('select').exists()).toBe(false)
    expect(wrapper.find('input').attributes('list')).toBe(wrapper.find('datalist').attributes('id'))
    const options = wrapper.findAll('datalist option')
    expect(options.map(option => option.attributes('value'))).toEqual(['518.100', '518'])
    expect(options[0]!.attributes('label')).toBe('Hosting')
    await wrapper.find('input').setValue('518.100')
    expect(wrapper.emitted('update:modelValue')).toEqual([['518.100']])
    wrapper.unmount()
  })

  it('offers active analytics before their synthetic parent and allows clearing', async () => {
    const wrapper = mount(ChartAccountSelect, { props: { modelValue: '518.100', accounts }, global: { stubs: { Teleport: true } } })
    expect(wrapper.find('p').text()).toBe('Hosting')
    expect(wrapper.findAll('datalist option').map(option => option.text())).toEqual(['518.100 / Hosting', '518 / Services'])
    await wrapper.find('input').setValue('')
    expect(wrapper.emitted('update:modelValue')).toEqual([['']])
    wrapper.unmount()
  })

  it('keeps suggestion lists distinct for MD and DAL inputs', () => {
    const wrapper = mount({ components: { ChartAccountSelect }, setup: () => ({ accounts }), template: '<div><ChartAccountSelect model-value="" :accounts="accounts" /><ChartAccountSelect model-value="" :accounts="accounts" /></div>' })
    const inputs = wrapper.findAll('input')
    expect(inputs[0]!.attributes('list')).not.toBe(inputs[1]!.attributes('list'))
    wrapper.unmount()
  })
})

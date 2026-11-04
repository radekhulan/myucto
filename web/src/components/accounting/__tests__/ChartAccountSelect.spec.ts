import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import type { ChartAccount } from '@/api/accounting'
import ChartAccountSelect from '../ChartAccountSelect.vue'

vi.mock('vue-i18n', () => ({ useI18n: () => ({ locale: { value: 'cs' }, t: (key: string) => key }) }))

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

  it('accepts an account prefix as a range bound only when allowed', async () => {
    const prefix = mount(ChartAccountSelect, { props: { modelValue: '518', accounts: accounts.filter(a => a.id !== 1), allowPrefix: true } })
    expect(prefix.find('p').text()).toBe('accounting.template.account_prefix')
    expect(prefix.find('.text-danger-500').exists()).toBe(false)
    prefix.unmount()
    const strict = mount(ChartAccountSelect, { props: { modelValue: '518', accounts: accounts.filter(a => a.id !== 1) } })
    expect(strict.find('.text-danger-500').exists()).toBe(true)
    strict.unmount()
    // Mez bez účtu v osnově (500 až 599) je platný rozsah, ne chyba.
    const none = mount(ChartAccountSelect, { props: { modelValue: '599', accounts, allowPrefix: true } })
    expect(none.find('.text-danger-500').exists()).toBe(false)
    expect(none.find('p').text()).toBe('accounting.template.account_prefix_none')
    none.unmount()
    // Dokud se osnova načítá, prázdný seznam neznamená „žádný účet nezačíná na…“.
    const loading = mount(ChartAccountSelect, { props: { modelValue: '599', accounts: [], allowPrefix: true, loading: true } })
    expect(loading.find('p').exists()).toBe(false)
    await loading.setProps({ accounts, loading: false })
    expect(loading.find('p').text()).toBe('accounting.template.account_prefix_none')
    loading.unmount()
    // Neaktivní účet se počítá i jmenuje (rozsah se týká i starých pohybů), nabídka ho neukazuje.
    const inactive = mount(ChartAccountSelect, { props: { modelValue: '518.200', accounts, allowPrefix: true } })
    expect(inactive.find('p').text()).toBe('Inactive service')
    expect(inactive.findAll('datalist option').map(o => o.attributes('value'))).not.toContain('518.200')
    inactive.unmount()
    // Velikost písmen v mezi nerozhoduje, stejně jako na serveru.
    const lower = mount(ChartAccountSelect, { props: { modelValue: '518.100', accounts: [{ id: 9, account_code: '518.100', name: 'Hosting', is_active: true, parent_id: null }, { id: 10, account_code: '221.ka', name: 'Kasa', is_active: true, parent_id: null }] as ChartAccount[], allowPrefix: true } })
    await lower.setProps({ modelValue: '221.KA' })
    expect(lower.find('p').text()).toBe('Kasa')
    lower.unmount()
  })

  it('keeps suggestion lists distinct for MD and DAL inputs', () => {
    const wrapper = mount({ components: { ChartAccountSelect }, setup: () => ({ accounts }), template: '<div><ChartAccountSelect model-value="" :accounts="accounts" /><ChartAccountSelect model-value="" :accounts="accounts" /></div>' })
    const inputs = wrapper.findAll('input')
    expect(inputs[0]!.attributes('list')).not.toBe(inputs[1]!.attributes('list'))
    wrapper.unmount()
  })
})

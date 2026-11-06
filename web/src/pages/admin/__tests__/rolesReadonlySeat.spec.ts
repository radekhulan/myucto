import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const api = vi.hoisted(() => ({ list: vi.fn(), catalog: vi.fn(), detail: vi.fn(), update: vi.fn() }))
vi.mock('@/api/roles', () => ({ rolesApi: api }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ success: vi.fn(), error: vi.fn() }) }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ locale: { value: 'cs' }, t: (key: string) => key }) }))

import Roles from '../Roles.vue'

const builtin = { id: 4, name: 'Čtecí role', system_key: 'readonly', role_type: 'staff', is_active: true, default_usage: 0, override_usage: 0 }
const custom = { ...builtin, id: 9, system_key: null }
const permissions = { invoices: 2, profile: 2, 'profile.tokens': 1, 'settings.email_profiles': 2 }

beforeEach(() => {
  vi.clearAllMocks()
  api.list.mockResolvedValue([builtin, custom])
  api.catalog.mockResolvedValue({ groups: {
    invoices: [{ key: 'invoices', label: 'Faktury', role_types: ['staff'] }],
    profile: [{ key: 'profile', label: 'Profil', role_types: ['staff'] }, { key: 'profile.tokens', label: 'API tokeny', role_types: ['staff'] }],
    settings: [{ key: 'settings.email_profiles', label: 'Odesílací profily', role_types: ['staff'] }],
  } })
  api.detail.mockImplementation(async (id: number) => ({ ...(id === builtin.id ? builtin : custom), permissions, revision: 3 }))
  api.update.mockImplementation(async (id: number, payload: unknown) => ({ ...(id === builtin.id ? builtin : custom), ...payload as object }))
})

describe('vestavěná čtecí role', () => {
  it('nabízí a odesílá zápis jen pro API tokeny, jejich výchozí čtení zachová', async () => {
    const wrapper = mount(Roles)
    await flushPromises()
    await wrapper.findAll('button.w-full')[0]!.trigger('click')
    await flushPromises()
    const form = wrapper.get('form')
    const rows = form.findAll('div.grid').filter(row => row.find('input[type="radio"]').exists())
    const row = (key: string) => rows[['invoices', 'profile', 'profile.tokens', 'settings.email_profiles'].indexOf(key)]!
    expect(row('invoices').find('input[value="2"]').exists()).toBe(false)
    expect(row('profile').find('input[value="2"]').exists()).toBe(false)
    expect(row('settings.email_profiles').findAll('input').map(input => input.attributes('value'))).toEqual(['0'])
    expect((row('profile').get('input[value="1"]').element as HTMLInputElement).checked).toBe(true)
    expect(row('profile.tokens').findAll('input').map(input => input.attributes('value'))).toEqual(['0', '1', '2'])
    expect((row('profile.tokens').get('input[value="1"]').element as HTMLInputElement).checked).toBe(true)
    await row('profile.tokens').get('input[value="2"]').setValue(true)
    await form.trigger('submit')
    await flushPromises()
    expect(api.update).toHaveBeenCalledWith(4, expect.objectContaining({ permissions: { invoices: 1, profile: 1, 'profile.tokens': 2 } }))
    wrapper.unmount()
  })

  it('vlastní roli se stejným názvem zachová možnosti i stávající zápis', async () => {
    const wrapper = mount(Roles)
    await flushPromises()
    await wrapper.findAll('button.w-full')[0]!.trigger('click')
    await flushPromises()
    await wrapper.findAll('button.w-full')[1]!.trigger('click')
    await flushPromises()
    const form = wrapper.get('form')
    const invoiceRow = form.findAll('div.grid').find(row => row.find('input[type="radio"]').exists())!
    expect(invoiceRow.findAll('input').map(input => input.attributes('value'))).toEqual(['0', '1', '2'])
    expect((invoiceRow.get('input[value="2"]').element as HTMLInputElement).checked).toBe(true)
    await form.trigger('submit')
    await flushPromises()
    expect(api.update).toHaveBeenCalledWith(9, expect.objectContaining({ permissions }))
    wrapper.unmount()
  })
})

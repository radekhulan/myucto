import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const api = vi.hoisted(() => ({ list: vi.fn(), catalog: vi.fn(), detail: vi.fn() }))
vi.mock('@/api/roles', () => ({ rolesApi: api }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ success: vi.fn(), error: vi.fn() }) }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ locale: { value: 'cs' }, t: (key: string) => key }) }))

import Roles from '../Roles.vue'

const technical = { id: 7, name: 'Testovací technická role', system_key: 'instance_admin', role_type: 'instance_admin', is_active: true, default_usage: 1, override_usage: 0 }
const custom = { id: 8, name: 'Testovací vlastní role', system_key: null, role_type: 'staff', is_active: true, default_usage: 0, override_usage: 0 }

describe('Správce instance — pevná role v editoru', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    api.list.mockResolvedValue([technical, custom])
    api.catalog.mockResolvedValue({ groups: { invoices: [{ key: 'invoices', label: 'Testovací faktury', description: 'Testovací oprávnění', role_types: ['staff'], kind: 'module' }] } })
    api.detail.mockImplementation(async (id: number) => ({ ...(id === technical.id ? technical : custom), permissions: { invoices: 2 } }))
  })

  it('technickou roli nelze upravit, deaktivovat, duplikovat ani smazat', async () => {
    const wrapper = mount(Roles)
    await flushPromises()
    const select = wrapper.findAll('button.w-full').find(button => button.text().includes(technical.name))!
    expect(select.text()).toContain('🔒')
    const actions = select.element.parentElement!
    expect(actions.textContent).not.toContain('common.edit')
    expect(actions.textContent).not.toContain('roles.duplicate')
    expect(actions.textContent).not.toContain('common.delete')

    await select.trigger('click')
    await flushPromises()
    const form = wrapper.get('form')
    expect(api.detail).toHaveBeenCalledWith(technical.id)
    expect(form.get('input').attributes('disabled')).toBeDefined()
    expect(form.find('input[type="checkbox"]').exists()).toBe(false)
    expect(form.find('input[type="radio"]').exists()).toBe(false)
    expect(form.find('button[type="submit"]').exists()).toBe(false)
    expect(form.text()).toContain('roles.system_descriptions.instance_admin')
  })

  it('vlastní roli ponechá editovatelnou včetně oprávnění a aktivity', async () => {
    const wrapper = mount(Roles)
    await flushPromises()
    const select = wrapper.findAll('button.w-full').find(button => button.text().includes(custom.name))!
    expect(select.element.parentElement!.textContent).toContain('roles.duplicate')
    await select.trigger('click')
    await flushPromises()
    const form = wrapper.get('form')
    expect(form.get('input').attributes('disabled')).toBeUndefined()
    expect(form.find('input[type="checkbox"]').exists()).toBe(true)
    expect(form.findAll('input[type="radio"]')).toHaveLength(3)
    expect(form.find('button[type="submit"]').exists()).toBe(true)
  })
})

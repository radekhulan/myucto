import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const m = vi.hoisted(() => ({ listUsers: vi.fn(), listRoles: vi.fn(), getTechnicalSuppliers: vi.fn(), setTechnicalSuppliers: vi.fn(), listUserSuppliers: vi.fn(), setUserSuppliers: vi.fn(), updateUser: vi.fn(), createUser: vi.fn() }))
vi.mock('@/api/admin', () => ({ adminApi: m }))
vi.mock('@/api/roles', () => ({ rolesApi: { list: m.listRoles } }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ success: vi.fn(), error: vi.fn(), warning: vi.fn() }) }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ newUserBlocked: 'seat_limit' }) }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ locale: { value: 'cs' }, t: (key: string) => key }) }))
import Users from '../Users.vue'

const technicalRole = { id: 7, name: 'Operator', system_key: 'instance_admin', role_type: 'instance_admin', is_active: true }
const user = { id: 42, email: 'operator@example.test', name: 'Operator', role_id: 7, role: { id: 7, name: 'Operator', system_key: 'instance_admin', type: 'instance_admin' }, locale: 'cs', is_active: true }

describe('Uživatelé — technický rozsah', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.listUsers.mockResolvedValue([user])
    m.listRoles.mockResolvedValue([technicalRole])
    m.getTechnicalSuppliers.mockResolvedValue({ all_suppliers: false, supplier_ids: [], suppliers: [] })
    m.setTechnicalSuppliers.mockResolvedValue({ all_suppliers: false, supplier_ids: [], suppliers: [] })
    m.updateUser.mockResolvedValue(user)
    m.createUser.mockResolvedValue({ ...user, invite_sent: true })
  })

  it('účet bez firem uloží i při plné licenci pouze přes technické přiřazení', async () => {
    const wrapper = mount(Users)
    await flushPromises()
    await wrapper.findAll('button').find(button => button.text() === 'common.edit')!.trigger('click')
    await flushPromises()
    expect(m.getTechnicalSuppliers).toHaveBeenCalledWith(42)
    expect(m.listUserSuppliers).not.toHaveBeenCalled()
    expect(wrapper.text()).toContain('instance_admin.technical_scope')
    expect(wrapper.text()).not.toContain('users.supplier_required')
    await wrapper.get('[data-technical-all]').setValue(true)
    await wrapper.get('form').trigger('submit')
    await flushPromises()
    expect(m.setTechnicalSuppliers).toHaveBeenCalledWith(42, { all_suppliers: true, supplier_ids: [] })
    expect(m.setUserSuppliers).not.toHaveBeenCalled()
  })

  it('při neúspěchu přiřazení znovu nezakládá už vytvořený účet', async () => {
    m.listUsers.mockResolvedValue([])
    m.setTechnicalSuppliers.mockRejectedValueOnce(new Error('synthetic failure')).mockResolvedValueOnce({})
    const wrapper = mount(Users)
    await flushPromises()
    await wrapper.findAll('button').find(button => button.text() === 'users.new')!.trigger('click')
    await wrapper.get('input[type="email"]').setValue('operator@example.test')
    const textInputs = wrapper.findAll('input').filter(input => input.attributes('type') === undefined)
    await textInputs[0]!.setValue('Operator')
    await wrapper.get('form').trigger('submit')
    await flushPromises()
    expect(m.createUser).toHaveBeenCalledTimes(1)
    await wrapper.get('form').trigger('submit')
    await flushPromises()
    expect(m.createUser).toHaveBeenCalledTimes(1)
    expect(m.updateUser).toHaveBeenCalledWith(42, expect.any(Object))
    expect(m.setTechnicalSuppliers).toHaveBeenCalledTimes(2)
  })
})

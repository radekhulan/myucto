import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const m = vi.hoisted(() => ({
  listUsers: vi.fn(), listRoles: vi.fn(),
  setupStatus: { sso_required_provider: 'entra' as string | null, sso_providers: [{ id: 'entra', strong: true }] },
}))
vi.mock('@/api/admin', () => ({ adminApi: { listUsers: m.listUsers } }))
vi.mock('@/api/roles', () => ({ rolesApi: { list: m.listRoles } }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ success: vi.fn(), error: vi.fn(), warning: vi.fn() }) }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ newUserBlocked: null, setupStatus: m.setupStatus }) }))
vi.mock('vue-i18n', () => ({
  useI18n: () => ({ locale: { value: 'cs' }, t: (key: string, params?: Record<string, unknown>) => params ? `${key} ${JSON.stringify(params)}` : key }),
}))
import Users from '../Users.vue'

const base = { role_id: 3, role: { id: 3, name: 'Účetní', system_key: 'accountant', type: 'system' }, locale: 'cs', is_active: true,
  created_at: '2026-11-01', last_login_at: null, mfa: { totp: false, passkeys: 0 } }

/**
 * Při vynuceném přihlášení přes Entra ID vidí správce u každého uživatele, jaký
 * účet organizace je napojený, nebo že napojený není a uživatel se nepřihlásí.
 */
describe('Uživatelé — napojený účet Entra ID', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.setupStatus.sso_required_provider = 'entra'
    m.listRoles.mockResolvedValue([])
    m.listUsers.mockResolvedValue([
      { ...base, id: 1, email: 'jana@example.test', name: 'Jana',
        sso_identity: { provider: 'entra', email: 'jana@tenant.example.test', display_name: 'Jana N.', last_used_at: '2026-11-06T08:15:00' } },
      { ...base, id: 2, email: 'petr@example.test', name: 'Petr', sso_identity: null },
    ])
  })

  it('ukáže napojený účet a upozorní na nepropojeného uživatele', async () => {
    const wrapper = mount(Users)
    await flushPromises()
    const badges = wrapper.findAll('[data-field="user-sso"]')
    expect(badges).toHaveLength(2)
    expect(badges[0]!.text()).toContain('jana@tenant.example.test')
    expect(badges[1]!.text()).toContain('users.sso_not_linked')
    // Silná Entra řídí i MFA, štítky místních faktorů by zbytečně varovaly.
    expect(wrapper.find('[data-field="user-mfa"]').exists()).toBe(false)
  })

  it('bez vynucení zůstává seznam beze změny', async () => {
    m.setupStatus.sso_required_provider = null
    const wrapper = mount(Users)
    await flushPromises()
    expect(wrapper.find('[data-field="user-sso"]').exists()).toBe(false)
    expect(wrapper.findAll('[data-field="user-mfa"]')).toHaveLength(2)
  })
})

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'

const m = vi.hoisted(() => ({ loginPolicy: vi.fn(), updateLoginPolicy: vi.fn() }))

vi.mock('@/api/oidc', () => ({ oidcApi: { loginPolicy: m.loginPolicy, updateLoginPolicy: m.updateLoginPolicy } }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ success: vi.fn(), error: vi.fn() }) }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))

import SupplierLoginPolicySettings from '../SupplierLoginPolicySettings.vue'

const base = {
  login_policy: 'any',
  login_sso_provider: null,
  providers: [{ id: 'entra', label: 'Microsoft Entra ID', strong: true }],
  can_manage: true,
  users_without_identity: 2,
}

describe('SupplierLoginPolicySettings', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })

  it('bez vynucení pro instalaci nabídne volbu firmy', async () => {
    m.loginPolicy.mockResolvedValue({ ...base, global_sso_provider: null })
    const wrapper = mount(SupplierLoginPolicySettings)
    await flushPromises()
    expect(wrapper.find('input[value="sso_required"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="login-policy-global"]').exists()).toBe(false)
  })

  it('vynucení pro celou instalaci jen ukáže, firma ho nemění', async () => {
    m.loginPolicy.mockResolvedValue({ ...base, global_sso_provider: 'entra' })
    const wrapper = mount(SupplierLoginPolicySettings)
    await flushPromises()
    expect(wrapper.find('[data-testid="login-policy-global"]').text()).toContain('sso.policy_global')
    expect(wrapper.find('input[type="radio"]').exists()).toBe(false)
    expect(wrapper.find('button[type="submit"]').exists()).toBe(false)
    expect(wrapper.text()).toContain('sso.policy_users_without_identity')
  })
})

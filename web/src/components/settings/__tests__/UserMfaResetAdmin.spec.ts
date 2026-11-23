import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const m = vi.hoisted(() => ({
  resetUserMfa: vi.fn(), totpStepUp: vi.fn(), success: vi.fn(), warning: vi.fn(),
  user: { id: 1, mfa_methods: ['totp'] as Array<'totp' | 'passkey'> },
}))
vi.mock('@/api/admin', () => ({ adminApi: { resetUserMfa: m.resetUserMfa } }))
vi.mock('@/api/auth', () => ({ authApi: { totpStepUp: m.totpStepUp, passkeyStepUpOptions: vi.fn(), passkeyStepUpVerify: vi.fn() } }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ user: m.user }) }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ success: m.success, warning: m.warning, error: vi.fn() }) }))
vi.mock('@/security/webauthn', () => ({ getCredential: vi.fn(), isWebAuthnAvailable: () => false, webAuthnErrorKey: () => null }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))
import UserMfaResetAdmin from '../UserMfaResetAdmin.vue'

const stubs = { SsoStepUpOption: true, 'router-link': { template: '<a><slot /></a>' } }

/** Superadmin resetuje MFA jinému uživateli a potvrzuje to vlastním MFA. */
describe('Reset MFA uživatele', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.user.id = 1
    m.user.mfa_methods = ['totp']
  })

  it('potvrdí reset kódem z aplikace, proof je vázaný na resetovaný účet', async () => {
    m.totpStepUp.mockResolvedValue('proof-token')
    m.resetUserMfa.mockResolvedValue({ ok: true, mail_sent: true })
    const wrapper = mount(UserMfaResetAdmin, { props: { userId: 7 }, global: { stubs } })
    await wrapper.get('[data-user-mfa-reset-start]').trigger('click')
    const confirm = wrapper.get('[data-user-mfa-reset-totp]')
    await wrapper.get('[data-user-mfa-reset-totp-code]').setValue('123456')
    expect(confirm.attributes('disabled')).toBeDefined()
    await wrapper.get('[data-user-mfa-reset-reason]').setValue('Ztracený telefon, ověřeno telefonicky')
    await confirm.trigger('click')
    await flushPromises()
    expect(m.totpStepUp).toHaveBeenCalledWith('user.mfa_reset:7', '123456')
    expect(m.resetUserMfa).toHaveBeenCalledWith(7, 'Ztracený telefon, ověřeno telefonicky', 'proof-token')
    expect(m.success).toHaveBeenCalledWith('users.mfa_reset_done')
    expect(wrapper.emitted('reset')).toHaveLength(1)
  })

  it('vlastní účet resetovat nenabídne', () => {
    const wrapper = mount(UserMfaResetAdmin, { props: { userId: 1 }, global: { stubs } })
    expect(wrapper.find('[data-user-mfa-reset-start]').exists()).toBe(false)
    expect(wrapper.text()).toContain('users.mfa_reset_self')
  })

  it('bez vlastního MFA vyzve k jeho zapnutí', async () => {
    m.user.mfa_methods = []
    const wrapper = mount(UserMfaResetAdmin, { props: { userId: 7 }, global: { stubs } })
    await wrapper.get('[data-user-mfa-reset-start]').trigger('click')
    expect(wrapper.find('[data-user-mfa-reset-own-required]').exists()).toBe(true)
    expect(wrapper.find('[data-user-mfa-reset-totp]').exists()).toBe(false)
  })
})

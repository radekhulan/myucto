import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

/**
 * Správce klíčů, který vrací neplatné ověření, uživatele dřív zamkl: klíč nešlo
 * odebrat ani přidat nový, protože obojí chtělo funkční klíč. Záložní kód je
 * cesta ven, server ho pro tyto operace přijímá.
 */
const m = vi.hoisted(() => ({
  load: vi.fn(), revoke: vi.fn(), recoveryCodeStatus: vi.fn(), recoveryStepUp: vi.fn(),
  passkeyStepUpOptions: vi.fn(), refresh: vi.fn(), securityRefresh: vi.fn(),
}))
vi.mock('@/api/auth', () => ({ authApi: {
  passkeys: m.load,
  passkeyRevoke: m.revoke,
  recoveryCodeStatus: m.recoveryCodeStatus,
  recoveryStepUp: m.recoveryStepUp,
  passkeyStepUpOptions: m.passkeyStepUpOptions,
} }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({
  allowedMfaMethods: ['passkey', 'totp'], requireMfa: false,
  user: { totp_enabled: false }, refresh: m.refresh,
}) }))
vi.mock('@/stores/sessionSecurity', () => ({ useSessionSecurityStore: () => ({ refresh: m.securityRefresh }) }))
vi.mock('@/security/domainLogin', () => ({
  canonicalDomainLoginSessionCsrf: () => null, advanceCanonicalDomainLoginSession: vi.fn(),
}))
vi.mock('@/security/webauthn', () => ({
  isWebAuthnAvailable: () => true, createCredential: vi.fn(), getCredential: vi.fn(),
  webAuthnErrorKey: () => null,
}))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))
vi.mock('@/components/security/RecoveryCodes.vue', () => ({ default: { template: '<div />' } }))
import Passkeys from '../Passkeys.vue'

const credential = { id: 5, label: 'Synthetic key', transports: [], created_at: null, last_used_at: null }

beforeEach(() => {
  vi.clearAllMocks()
  m.load.mockResolvedValue([credential])
  m.refresh.mockResolvedValue(true)
  m.securityRefresh.mockResolvedValue(true)
  vi.spyOn(window, 'confirm').mockReturnValue(true)
})

describe('Přístupové klíče — záložní kód místo nefunkčního klíče', () => {
  it('odebere klíč po ověření záložním kódem bez systémového dialogu klíče', async () => {
    m.recoveryCodeStatus.mockResolvedValue({ remaining: 4 })
    m.recoveryStepUp.mockResolvedValue({ step_up_token: 'recovery-token', remaining: 3 })
    const wrapper = mount(Passkeys)
    await flushPromises()

    await wrapper.get('[data-test="passkey-toggle-recovery"]').trigger('click')
    await wrapper.get('[data-test="passkey-recovery-code"]').setValue('ABCDE-FGHIJ')
    const revokeButton = wrapper.findAll('button').find(b => b.text() === 'passkeys.revoke')!
    await revokeButton.trigger('click')
    await flushPromises()

    expect(m.passkeyStepUpOptions).not.toHaveBeenCalled()
    expect(m.recoveryStepUp).toHaveBeenCalledWith('passkey.revoke:5', 'ABCDE-FGHIJ')
    expect(m.revoke).toHaveBeenCalledWith(5, 'recovery-token')
    wrapper.unmount()
  })

  it('s prázdným kódem neodešle nic a řekne proč', async () => {
    m.recoveryCodeStatus.mockResolvedValue({ remaining: 4 })
    const wrapper = mount(Passkeys)
    await flushPromises()

    await wrapper.get('[data-test="passkey-toggle-recovery"]').trigger('click')
    const revokeButton = wrapper.findAll('button').find(b => b.text() === 'passkeys.revoke')!
    await revokeButton.trigger('click')
    await flushPromises()

    expect(m.recoveryStepUp).not.toHaveBeenCalled()
    expect(m.passkeyStepUpOptions).not.toHaveBeenCalled()
    expect(wrapper.text()).toContain('auth.recovery_hint')
    wrapper.unmount()
  })

  it('bez zbývajících záložních kódů volbu nenabízí', async () => {
    m.recoveryCodeStatus.mockResolvedValue({ remaining: 0 })
    const wrapper = mount(Passkeys)
    await flushPromises()

    expect(wrapper.find('[data-test="passkey-toggle-recovery"]').exists()).toBe(false)
    wrapper.unmount()
  })
})

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { getCredential } from '@/security/webauthn'

/**
 * `/api/auth/totp/setup` teď vyžaduje čerstvé ověření, než vrátí secret:
 * heslem (nemá-li uživatel passkey), nebo step-up tokenem z passkey ceremonie
 * pro operaci `totp.enable` (má-li aspoň jednu). Test hlídá tu první větev —
 * bez passkey se nesmí zavolat `totpSetup` dřív, než uživatel zadá heslo.
 */
const m = vi.hoisted(() => ({
  totpStatus: vi.fn(),
  totpSetup: vi.fn(),
  totpEnable: vi.fn(),
  passkeyStepUpOptions: vi.fn(),
  passkeyStepUpVerify: vi.fn(),
  recoveryCodeStatus: vi.fn(),
  recoveryStepUp: vi.fn(),
  store: {
    user: { totp_enabled: false, mfa_methods: [] as string[], passkey_count: 0 } as Record<string, unknown>,
  },
}))

vi.mock('@/api/auth', () => ({
  authApi: {
    totpStatus: m.totpStatus,
    totpSetup: m.totpSetup,
    totpEnable: m.totpEnable,
    passkeyStepUpOptions: m.passkeyStepUpOptions,
    passkeyStepUpVerify: m.passkeyStepUpVerify,
    recoveryCodeStatus: m.recoveryCodeStatus,
    recoveryStepUp: m.recoveryStepUp,
  },
}))
vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ ...m.store }),
}))
vi.mock('@/composables/useToast', () => ({
  useToast: () => ({ success: vi.fn(), error: vi.fn() }),
}))
vi.mock('@/security/webauthn', () => ({
  getCredential: vi.fn(),
  webAuthnErrorKey: () => null,
}))
vi.mock('vue-i18n', () => ({
  useI18n: () => ({ locale: { value: 'cs' }, t: (key: string) => key }),
}))

import TotpSetup from '../TotpSetup.vue'

const mountPage = () => mount(TotpSetup, {
  global: { stubs: { RouterLink: true } },
})

beforeEach(() => {
  vi.clearAllMocks()
  m.store.user = { totp_enabled: false, mfa_methods: [], passkey_count: 0 }
  m.totpStatus.mockResolvedValue({ enabled: false })
  m.recoveryCodeStatus.mockResolvedValue({ remaining: 0 })
})

describe('TotpSetup — re-authentication before secret generation', () => {
  it('bez passkey nezavolá totpSetup dokud není vyplněné heslo', async () => {
    const wrapper = mountPage()
    await flushPromises()

    await wrapper.get('[data-test="totp-start"]').trigger('click')
    await flushPromises()

    expect(m.totpSetup).not.toHaveBeenCalled()
    expect(wrapper.text()).toContain('mfa_setup.password_required')
  })

  it('s heslem zavolá totpSetup s { current_password }', async () => {
    m.totpSetup.mockResolvedValue({ secret: 'S', uri: 'otpauth://x', qr_data_uri: 'data:,' })

    const wrapper = mountPage()
    await flushPromises()

    await wrapper.get('[data-test="totp-current-password"]').setValue('hunter2')
    await wrapper.get('[data-test="totp-start"]').trigger('click')
    await flushPromises()

    expect(m.totpSetup).toHaveBeenCalledWith({ current_password: 'hunter2' })
  })

  it('vykreslí hlášku pro current_password_invalid', async () => {
    m.totpSetup.mockRejectedValue({
      response: { data: { error: { code: 'current_password_invalid' } } },
    })

    const wrapper = mountPage()
    await flushPromises()

    await wrapper.get('[data-test="totp-current-password"]').setValue('wrong')
    await wrapper.get('[data-test="totp-start"]').trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain('auth.current_password_invalid')
  })

  it('má-li uživatel passkey, autorizuje se step-up tokenem místo heslem', async () => {
    m.store.user = { totp_enabled: false, mfa_methods: ['passkey'], passkey_count: 1 }
    m.passkeyStepUpOptions.mockResolvedValue({ flow_token: 'ft', public_key: {} })
    m.passkeyStepUpVerify.mockResolvedValue('step-up-token')
    m.totpSetup.mockResolvedValue({ secret: 'S', uri: 'otpauth://x', qr_data_uri: 'data:,' })
    vi.mocked(getCredential).mockResolvedValue({ id: 'cred' } as any)

    const wrapper = mountPage()
    await flushPromises()

    expect(wrapper.find('[data-test="totp-current-password"]').exists()).toBe(false)
    await wrapper.get('[data-test="totp-start"]').trigger('click')
    await flushPromises()

    expect(m.passkeyStepUpOptions).toHaveBeenCalledWith('totp.enable')
    expect(m.passkeyStepUpVerify).toHaveBeenCalledWith('ft', 'totp.enable', { id: 'cred' })
    expect(m.totpSetup).toHaveBeenCalledWith({ step_up_token: 'step-up-token' })
  })

  it('nefunkční klíč obejde záložním kódem pro totp.enable', async () => {
    m.store.user = { totp_enabled: false, mfa_methods: ['passkey'], passkey_count: 1 }
    m.recoveryCodeStatus.mockResolvedValue({ remaining: 3 })
    m.recoveryStepUp.mockResolvedValue({ step_up_token: 'recovery-token', remaining: 2 })
    m.totpSetup.mockResolvedValue({ secret: 'S', uri: 'otpauth://x', qr_data_uri: 'data:,' })

    const wrapper = mountPage()
    await flushPromises()

    await wrapper.get('[data-test="totp-toggle-recovery"]').trigger('click')
    await wrapper.get('[data-test="totp-recovery-code"]').setValue(' ABCDE-FGHIJ ')
    await wrapper.get('[data-test="totp-start"]').trigger('click')
    await flushPromises()

    expect(m.passkeyStepUpOptions).not.toHaveBeenCalled()
    expect(m.recoveryStepUp).toHaveBeenCalledWith('totp.enable', 'ABCDE-FGHIJ')
    expect(m.totpSetup).toHaveBeenCalledWith({ step_up_token: 'recovery-token' })
  })

  it('bez zbývajících záložních kódů volbu nenabízí', async () => {
    m.store.user = { totp_enabled: false, mfa_methods: ['passkey'], passkey_count: 1 }

    const wrapper = mountPage()
    await flushPromises()

    expect(m.recoveryCodeStatus).toHaveBeenCalled()
    expect(wrapper.find('[data-test="totp-toggle-recovery"]').exists()).toBe(false)
  })
})

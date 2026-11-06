import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { isPasskeyTestable } from '@/security/passkeyTest'

/**
 * Správce klíčů může registraci projít a pak vracet ověření, které server
 * odmítne. Klíč se proto hned po registraci zkušebně ověří; když neprojde,
 * server ho odvolá a uživatel se to dozví hned, ne až když bude zamčený.
 */
const m = vi.hoisted(() => ({
  load: vi.fn(), register: vi.fn(), testOptions: vi.fn(), testVerify: vi.fn(),
  getCredential: vi.fn(), refresh: vi.fn(), securityRefresh: vi.fn(),
}))
vi.mock('@/api/auth', () => ({ authApi: {
  passkeys: m.load,
  passkeyRegisterOptions: vi.fn().mockResolvedValue({ flow_token: 'synthetic-flow', public_key: {} }),
  passkeyRegisterVerify: m.register,
  passkeyTestOptions: m.testOptions,
  passkeyTestVerify: m.testVerify,
  recoveryCodeStatus: vi.fn().mockResolvedValue({ remaining: 0 }),
} }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({
  allowedMfaMethods: ['passkey'], requireMfa: false,
  user: { totp_enabled: false }, refresh: m.refresh, setSessionCsrfToken: vi.fn(),
}) }))
vi.mock('@/stores/sessionSecurity', () => ({ useSessionSecurityStore: () => ({ refresh: m.securityRefresh }) }))
vi.mock('@/security/domainLogin', () => ({
  canonicalDomainLoginSessionCsrf: () => null, advanceCanonicalDomainLoginSession: vi.fn(),
}))
vi.mock('@/security/webauthn', () => ({
  isWebAuthnAvailable: () => true,
  createCredential: vi.fn().mockResolvedValue({ id: 'synthetic' }),
  getCredential: m.getCredential,
  webAuthnErrorKey: (e: any) => (e?.name === 'NotAllowedError' ? 'passkeys.cancelled' : null),
}))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))
vi.mock('@/components/security/RecoveryCodes.vue', () => ({ default: { template: '<div />' } }))
import Passkeys from '../Passkeys.vue'

beforeEach(() => {
  vi.clearAllMocks()
  m.load.mockResolvedValue([])
  m.refresh.mockResolvedValue(true)
  m.securityRefresh.mockResolvedValue(true)
  m.register.mockResolvedValue({ credential: { id: 9 } })
  m.testOptions.mockResolvedValue({ flow_token: 'test-flow', public_key: {} })
  m.getCredential.mockResolvedValue({ id: 'assertion' })
})

async function register() {
  const wrapper = mount(Passkeys)
  await flushPromises()
  await wrapper.get('input[type="text"]').setValue('Test key')
  await wrapper.get('input[type="password"]').setValue('Synthetic-password-42')
  await wrapper.get('button').trigger('click')
  await flushPromises()
  return wrapper
}

describe('zkušební ověření klíče po registraci', () => {
  it('odvolaný klíč ohlásí a návrat z registrace neohlásí', async () => {
    m.testVerify.mockRejectedValue({ response: { data: { error: { code: 'passkey_test_failed', revoked: true } } } })
    const wrapper = await register()

    expect(m.testOptions).toHaveBeenCalledWith(9)
    expect(m.testVerify).toHaveBeenCalledWith(9, 'test-flow', { id: 'assertion' })
    expect(wrapper.text()).toContain('passkeys.test_revoked')
    expect(wrapper.emitted('registered')).toBeUndefined()
    wrapper.unmount()
  })

  it('funkční klíč potvrdí a registraci dokončí', async () => {
    m.testVerify.mockResolvedValue({ verified: true })
    const wrapper = await register()

    expect(wrapper.text()).toContain('passkeys.test_verified')
    expect(wrapper.emitted('registered')).toHaveLength(1)
    wrapper.unmount()
  })

  it('zavřený systémový dialog klíč nechá a vyzve k pozdějšímu vyzkoušení', async () => {
    m.getCredential.mockRejectedValue({ name: 'NotAllowedError' })
    const wrapper = await register()

    expect(m.testVerify).not.toHaveBeenCalled()
    expect(wrapper.text()).toContain('passkeys.test_cancelled')
    expect(wrapper.emitted('registered')).toHaveLength(1)
    wrapper.unmount()
  })
})

describe('isPasskeyTestable', () => {
  const now = Date.parse('2026-10-21T10:00:00Z')

  it('nabízí test jen čerstvému a nepoužitému klíči', () => {
    expect(isPasskeyTestable({ created_at: '2026-10-21 09:50:00.123456', last_used_at: null }, now)).toBe(true)
    expect(isPasskeyTestable({ created_at: '2026-10-21 09:40:00', last_used_at: null }, now)).toBe(false)
    expect(isPasskeyTestable({ created_at: '2026-10-21 09:50:00', last_used_at: '2026-10-21 09:55:00' }, now)).toBe(false)
    expect(isPasskeyTestable({ created_at: null, last_used_at: null }, now)).toBe(false)
  })
})

import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

/**
 * Dobrovolná nabídka MFA (`should_offer_mfa`) sdílí obrazovku s vynuceným
 * nastavením. Test hlídá to jediné, čím se liší a co se nesmí splést: tlačítko
 * „pokračovat bez dvoufázového ověření" smí existovat POUZE u nabídky. U vynucené
 * MFA (`auth.require_mfa = true` → `must_setup_mfa`) by bylo cestou kolem politiky.
 */
const m = vi.hoisted(() => ({
  dismissMfaOffer: vi.fn(),
  refresh: vi.fn(),
  logout: vi.fn(),
  replace: vi.fn(),
  store: {
    user: { totp_enabled: false } as Record<string, unknown>,
    allowedMfaMethods: ['totp'] as Array<'passkey' | 'totp'>,
    mustSetupMfa: false,
    mustSetupTotp: false,
    shouldOfferMfa: false,
  },
}))

// AppShell tahá `/styles/logo.svg` už při importu modulu, což `stubs` neodchytí —
// zaslepit se musí celý modul.
vi.mock('@/components/layout/AppShell.vue', () => ({
  default: { name: 'AppShell', template: '<div><slot /></div>' },
}))
vi.mock('@/api/auth', () => ({
  authApi: {
    dismissMfaOffer: m.dismissMfaOffer,
    totpSetup: vi.fn().mockResolvedValue({ secret: 'S', uri: 'otpauth://x', qr_data_uri: 'data:,' }),
    totpEnable: vi.fn(),
    totpStepUp: vi.fn(),
    passkeyRegisterOptions: vi.fn(),
    passkeyRegisterVerify: vi.fn(),
  },
}))
vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({
    ...m.store,
    refresh: m.refresh,
    logout: m.logout,
    setSessionCsrfToken: vi.fn(),
  }),
}))
vi.mock('@/stores/sessionSecurity', () => ({
  useSessionSecurityStore: () => ({ refresh: vi.fn(), clear: vi.fn() }),
}))
vi.mock('@/security/webauthn', () => ({
  createCredential: vi.fn(),
  isWebAuthnAvailable: () => true,
  webAuthnErrorKey: () => null,
}))
vi.mock('@/security/domainLogin', () => ({
  hasPendingCanonicalDomainLogin: () => false,
  authorizePendingDomainLogin: vi.fn(),
}))
vi.mock('vue-router', () => ({
  useRoute: () => ({ query: {} }),
  useRouter: () => ({ replace: m.replace }),
}))
vi.mock('vue-i18n', () => ({
  useI18n: () => ({ t: (key: string) => key }),
}))

import ForcedMfaSetup from '../ForcedMfaSetup.vue'

const mountPage = () => mount(ForcedMfaSetup)

beforeEach(() => {
  vi.clearAllMocks()
  m.store.user = { totp_enabled: false }
  m.store.allowedMfaMethods = ['totp']
  m.store.mustSetupMfa = false
  m.store.mustSetupTotp = false
  m.store.shouldOfferMfa = false
})

describe('ForcedMfaSetup — dobrovolná nabídka vs. vynucené MFA', () => {
  it('u vynuceného MFA nenabídne pokračování bez ověření', async () => {
    m.store.mustSetupMfa = true

    const wrapper = mountPage()
    await flushPromises()

    expect(wrapper.find('[data-test="mfa-skip"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="mfa-logout"]').exists()).toBe(true)
  })

  it('u dobrovolné nabídky zobrazí „pokračovat bez MFA" místo odhlášení', async () => {
    m.store.shouldOfferMfa = true

    const wrapper = mountPage()
    await flushPromises()

    expect(wrapper.find('[data-test="mfa-skip"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="mfa-logout"]').exists()).toBe(false)
  })

  it('odmítnutí ukládá na server a teprve pak pouští do aplikace', async () => {
    m.store.shouldOfferMfa = true
    m.dismissMfaOffer.mockResolvedValue({ dismissed: true })

    const wrapper = mountPage()
    await flushPromises()
    await wrapper.get('[data-test="mfa-skip"]').trigger('click')
    await flushPromises()

    // Bez serverového zápisu by se nabídka vrátila při dalším přihlášení.
    expect(m.dismissMfaOffer).toHaveBeenCalledTimes(1)
    expect(m.replace).toHaveBeenCalledWith('/')
  })

  it('selhání zápisu uživatele do aplikace nepustí a chybu ukáže', async () => {
    m.store.shouldOfferMfa = true
    m.dismissMfaOffer.mockRejectedValue({ response: { data: { error: { message: 'nelze' } } } })

    const wrapper = mountPage()
    await flushPromises()
    await wrapper.get('[data-test="mfa-skip"]').trigger('click')
    await flushPromises()

    expect(m.replace).not.toHaveBeenCalled()
    expect(wrapper.text()).toContain('nelze')
  })
})

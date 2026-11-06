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
  pendingDomainLogin: vi.fn(() => false),
  authorizeDomainLogin: vi.fn(),
  totpSetup: vi.fn(),
  totpEnable: vi.fn(),
  ownedCsrf: vi.fn<() => string | null>(() => null),
  advanceSession: vi.fn(),
  setSessionCsrfToken: vi.fn(),
  passkeyStepUpOptions: vi.fn(),
  passkeyStepUpVerify: vi.fn(),
  passkeyRegisterOptions: vi.fn(),
  passkeyRegisterVerify: vi.fn(),
  passkeyTestOptions: vi.fn(),
  passkeyTestVerify: vi.fn(),
  getCredential: vi.fn(),
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
    totpSetup: m.totpSetup,
    totpEnable: m.totpEnable,
    totpStepUp: vi.fn(),
    passkeyRegisterOptions: m.passkeyRegisterOptions,
    passkeyRegisterVerify: m.passkeyRegisterVerify,
    passkeyTestOptions: m.passkeyTestOptions,
    passkeyTestVerify: m.passkeyTestVerify,
    passkeyStepUpOptions: m.passkeyStepUpOptions,
    passkeyStepUpVerify: m.passkeyStepUpVerify,
  },
}))
vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({
    ...m.store,
    refresh: m.refresh,
    logout: m.logout,
    setSessionCsrfToken: m.setSessionCsrfToken,
  }),
}))
vi.mock('@/stores/sessionSecurity', () => ({
  useSessionSecurityStore: () => ({ refresh: vi.fn(), clear: vi.fn() }),
}))
vi.mock('@/security/webauthn', () => ({
  createCredential: vi.fn(),
  getCredential: m.getCredential,
  isWebAuthnAvailable: () => true,
  webAuthnErrorKey: () => null,
}))
vi.mock('@/security/domainLogin', () => ({
  canonicalDomainLoginSessionCsrf: m.ownedCsrf,
  advanceCanonicalDomainLoginSession: m.advanceSession,
  hasPendingCanonicalDomainLogin: m.pendingDomainLogin,
  authorizePendingDomainLogin: m.authorizeDomainLogin,
  isDomainLoginConfirmationRequired: (e: any) => e?.response?.data?.error?.code === 'domain_login_confirmation_required',
  pendingCanonicalDomainLoginQuery: () => ({ domain_login_request: 'r'.repeat(43), state: 's'.repeat(43) }),
}))
vi.mock('vue-router', () => ({
  useRoute: () => ({ query: {} }),
  useRouter: () => ({ replace: m.replace }),
}))
vi.mock('vue-i18n', () => ({
  useI18n: () => ({ locale: { value: 'cs' }, t: (key: string) => key }),
}))

import { authApi } from '@/api/auth'
import ForcedMfaSetup from '../ForcedMfaSetup.vue'
import { rememberTotpEnrollment } from '@/security/totpEnrollment'

const mountPage = () => mount(ForcedMfaSetup)

beforeEach(() => {
  rememberTotpEnrollment(null)
  vi.clearAllMocks()
  m.ownedCsrf.mockReturnValue(null)
  m.pendingDomainLogin.mockReturnValue(false)
  m.store.user = { totp_enabled: false }
  m.store.allowedMfaMethods = ['totp']
  m.store.mustSetupMfa = false
  m.store.mustSetupTotp = false
  m.store.shouldOfferMfa = false
  m.totpSetup.mockResolvedValue({ secret: 'S', uri: 'otpauth://x', qr_data_uri: 'data:,' })
})

describe('ForcedMfaSetup — dobrovolná nabídka vs. vynucené MFA', () => {
  it.each(['required', 'offer'])('po přihlášení použije oprávnění bez opakování hesla: %s', async (mode) => {
    m.store.user = { id: 17, totp_enabled: false }
    m.store.mustSetupMfa = mode === 'required'
    m.store.shouldOfferMfa = mode === 'offer'
    rememberTotpEnrollment('one-use-grant', 17)
    const wrapper = mountPage()
    await flushPromises()
    expect(wrapper.find('[data-test="totp-current-password"]').exists()).toBe(false)
    await wrapper.get('[data-test="totp-start"]').trigger('click')
    await flushPromises()
    expect(m.totpSetup).toHaveBeenCalledWith({ enrollment_token: 'one-use-grant' })
    expect(wrapper.text()).toContain('auth.totp_setup_step1')
    wrapper.unmount()
  })

  it('po zamítnutí oprávnění dovolí ověření heslem', async () => {
    m.store.user = { id: 17, totp_enabled: false }
    rememberTotpEnrollment('expired-grant', 17)
    m.totpSetup.mockRejectedValueOnce({ response: { data: { error: { code: 'enrollment_authorization_invalid' } } } })
    const wrapper = mountPage()
    await flushPromises()
    await wrapper.get('[data-test="totp-start"]').trigger('click')
    await flushPromises()
    await wrapper.get('[data-test="totp-current-password"]').setValue('new-password')
    await wrapper.get('[data-test="totp-start"]').trigger('click')
    await flushPromises()
    expect(m.totpSetup).toHaveBeenLastCalledWith({ current_password: 'new-password' })
    wrapper.unmount()
  })

  it('po opuštění průvodce už oprávnění nenabízí', async () => {
    m.store.user = { id: 17, totp_enabled: false }
    rememberTotpEnrollment('one-use-grant', 17)
    const first = mountPage()
    await flushPromises()
    first.unmount()
    const second = mountPage()
    await flushPromises()
    expect(second.find('[data-test="totp-current-password"]').exists()).toBe(true)
    second.unmount()
  })

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

/**
 * `/api/auth/totp/setup` vyžaduje čerstvé ověření, než vrátí secret. Bez
 * passkey (výchozí `m.store.user`) je to heslo — stejné pole, jaké už stránka
 * sbírá pro registraci passkey, jen na jiné větvi.
 */
describe('ForcedMfaSetup — TOTP vyžaduje čerstvé ověření', () => {
  it('bez hesla nezavolá totpSetup a ukáže hlášku', async () => {
    const wrapper = mountPage()
    await flushPromises()

    await wrapper.get('[data-test="totp-start"]').trigger('click')
    await flushPromises()

    expect(authApi.totpSetup).not.toHaveBeenCalled()
    expect(wrapper.text()).toContain('mfa_setup.password_required')
  })

  it('s heslem zavolá totpSetup s { current_password }', async () => {
    const wrapper = mountPage()
    await flushPromises()

    await wrapper.get('[data-test="totp-current-password"]').setValue('hunter2')
    await wrapper.get('[data-test="totp-start"]').trigger('click')
    await flushPromises()

    expect(authApi.totpSetup).toHaveBeenCalledWith({ current_password: 'hunter2' })
  })

  it('s existující passkey dokončí vynucený přechod na jedinou povolenou TOTP metodu', async () => {
    m.store.mustSetupMfa = true
    m.store.user = { totp_enabled: false, mfa_methods: ['passkey'], passkey_count: 1 }
    m.store.allowedMfaMethods = ['totp']
    m.passkeyStepUpOptions.mockResolvedValue({ flow_token: 'flow', public_key: {} })
    m.getCredential.mockResolvedValue({ id: 'credential' })
    m.passkeyStepUpVerify.mockResolvedValue('proof')

    const wrapper = mountPage()
    await flushPromises()
    await wrapper.get('[data-test="totp-start"]').trigger('click')
    await flushPromises()

    expect(m.passkeyStepUpOptions).toHaveBeenCalledWith('totp.enable')
    expect(m.passkeyStepUpVerify).toHaveBeenCalledWith('flow', 'totp.enable', { id: 'credential' })
    expect(m.totpSetup).toHaveBeenCalledWith({ step_up_token: 'proof' })
    expect(wrapper.text()).toContain('auth.totp_setup_step1')
  })

  it.each(['enrollment_stale', 'no_secret'])(
    'po chybě %s odstraní neplatný QR kód a dovolí začít znovu',
    async (code) => {
      m.totpEnable.mockRejectedValue({
        response: { data: { error: { code, message: 'Začni znovu.' } } },
      })
      const wrapper = mountPage()
      await flushPromises()

      await wrapper.get('[data-test="totp-current-password"]').setValue('hunter2')
      await wrapper.get('[data-test="totp-start"]').trigger('click')
      await flushPromises()
      const input = wrapper.find('input[autocomplete="one-time-code"]')
      await input.setValue('123456')
      await input.trigger('keydown.enter')
      await flushPromises()

      expect(wrapper.text()).toContain('Začni znovu.')
      expect(wrapper.find('input[autocomplete="one-time-code"]').exists()).toBe(false)
      expect(wrapper.find('[data-test="totp-current-password"]').exists()).toBe(true)
    },
  )

  it('při souběžně aktivovaném TOTP vyžádá nové přihlášení, pokud setup session zůstala slabá', async () => {
    m.store.mustSetupMfa = true
    m.refresh.mockResolvedValue(false)
    m.totpEnable.mockRejectedValue({
      response: { data: { error: { code: 'already_enabled' } } },
    })
    const wrapper = mountPage()
    await flushPromises()

    await wrapper.get('[data-test="totp-current-password"]').setValue('hunter2')
    await wrapper.get('[data-test="totp-start"]').trigger('click')
    await flushPromises()
    const input = wrapper.find('input[autocomplete="one-time-code"]')
    await input.setValue('123456')
    await input.trigger('keydown.enter')
    await flushPromises()

    expect(wrapper.text()).toContain('mfa_setup.already_enabled_relogin')
    expect(wrapper.find('[data-test="mfa-logout"]').exists()).toBe(true)
    expect(m.replace).not.toHaveBeenCalledWith('/')
  })
})


describe('ForcedMfaSetup — owned portal session rotation', () => {
  it('binds TOTP completion to the owned session and advances ownership before refresh', async () => {
    m.ownedCsrf.mockReturnValue('owned')
    m.totpEnable.mockResolvedValue({ enabled: true, csrf_token: 'rotated' })
    const wrapper = mountPage()
    await flushPromises()
    await wrapper.get('[data-test="totp-current-password"]').setValue('Synthetic-test-password-42')
    await wrapper.get('[data-test="totp-start"]').trigger('click')
    await flushPromises()
    const input = wrapper.get('input[autocomplete="one-time-code"]')
    await input.setValue('123456')
    await input.trigger('keydown.enter')
    await flushPromises()
    expect(m.totpEnable).toHaveBeenCalledWith('123456', 'owned')
    expect(m.advanceSession).toHaveBeenCalledWith('owned', 'rotated')
    expect(m.setSessionCsrfToken).toHaveBeenCalledWith('rotated')
    expect(m.advanceSession.mock.invocationCallOrder[0]).toBeLessThan(m.refresh.mock.invocationCallOrder.at(-1)!)
    wrapper.unmount()
  })
})


describe('ForcedMfaSetup — portal return after saving recovery codes', () => {
  it('shows cleanup failure and allows another confirmation without repeating enrollment', async () => {
    m.refresh.mockResolvedValue(true)
    m.totpEnable.mockResolvedValue({ enabled: true, recovery_codes: ['AAAA-BBBB'] })
    m.authorizeDomainLogin.mockRejectedValueOnce(new Error('offline')).mockResolvedValueOnce(true)
    const wrapper = mountPage()
    await flushPromises()
    await wrapper.get('[data-test="totp-current-password"]').setValue('Synthetic-test-password-42')
    await wrapper.get('[data-test="totp-start"]').trigger('click')
    await flushPromises()
    const input = wrapper.get('input[autocomplete="one-time-code"]')
    await input.setValue('123456')
    await input.trigger('keydown.enter')
    await flushPromises()
    m.pendingDomainLogin.mockReturnValue(true)
    await wrapper.get('[data-recovery-once-confirm]').trigger('click')
    await flushPromises()
    expect(wrapper.text()).toContain('domain_login.authorize_failed')
    expect(wrapper.get('[data-recovery-once-confirm]').attributes('disabled')).toBeUndefined()
    await wrapper.get('[data-recovery-once-confirm]').trigger('click')
    await flushPromises()
    expect(m.authorizeDomainLogin).toHaveBeenCalledTimes(2)
    expect(m.totpEnable).toHaveBeenCalledOnce()
    wrapper.unmount()
  })

  it('nepotvrzené předání přihlášení pošle na potvrzovací obrazovku přihlášení', async () => {
    m.refresh.mockResolvedValue(true)
    m.totpEnable.mockResolvedValue({ enabled: true, recovery_codes: ['AAAA-BBBB'] })
    m.authorizeDomainLogin.mockRejectedValueOnce({
      response: { status: 409, data: { error: { code: 'domain_login_confirmation_required' } } },
    })
    const wrapper = mountPage()
    await flushPromises()
    await wrapper.get('[data-test="totp-current-password"]').setValue('Synthetic-test-password-42')
    await wrapper.get('[data-test="totp-start"]').trigger('click')
    await flushPromises()
    const input = wrapper.get('input[autocomplete="one-time-code"]')
    await input.setValue('123456')
    await input.trigger('keydown.enter')
    await flushPromises()
    m.pendingDomainLogin.mockReturnValue(true)
    await wrapper.get('[data-recovery-once-confirm]').trigger('click')
    await flushPromises()
    expect(m.replace).toHaveBeenCalledWith({
      name: 'login',
      query: { domain_login_request: 'r'.repeat(43), state: 's'.repeat(43) },
    })
    expect(wrapper.text()).not.toContain('domain_login.authorize_failed')
    wrapper.unmount()
  })

  it('klíč, který po registraci neprojde ověřením, odvolá a do aplikace nepustí', async () => {
    m.store.allowedMfaMethods = ['passkey']
    m.store.mustSetupMfa = true
    m.passkeyRegisterOptions.mockResolvedValue({ flow_token: 'reg-flow', public_key: {} })
    m.passkeyRegisterVerify.mockResolvedValue({ credential: { id: 9 } })
    m.passkeyTestOptions.mockResolvedValue({ flow_token: 'test-flow', public_key: {} })
    m.getCredential.mockResolvedValue({ id: 'assertion' })
    m.passkeyTestVerify.mockRejectedValue({ response: { data: { error: { code: 'passkey_test_failed', revoked: true } } } })
    const wrapper = mountPage()
    await flushPromises()

    await wrapper.get('input[type="password"]').setValue('Synthetic-password-42')
    const create = wrapper.findAll('button').find(b => b.text() === 'mfa_setup.create_passkey')!
    await create.trigger('click')
    await flushPromises()

    expect(m.passkeyTestVerify).toHaveBeenCalledWith(9, 'test-flow', { id: 'assertion' })
    expect(wrapper.text()).toContain('passkeys.test_revoked')
    expect(m.replace).not.toHaveBeenCalled()
    wrapper.unmount()
  })

  it('po odvolaném klíči ukáže záložní kódy a po potvrzení nabídne nový pokus', async () => {
    m.store.allowedMfaMethods = ['passkey']
    m.store.mustSetupMfa = true
    m.passkeyRegisterOptions.mockResolvedValue({ flow_token: 'reg-flow', public_key: {} })
    m.passkeyRegisterVerify.mockResolvedValue({ credential: { id: 9 }, recovery_codes: ['AAAA-BBBB'] })
    m.passkeyTestOptions.mockResolvedValue({ flow_token: 'test-flow', public_key: {} })
    m.getCredential.mockResolvedValue({ id: 'assertion' })
    m.passkeyTestVerify.mockRejectedValue({ response: { data: { error: { code: 'passkey_test_failed', revoked: true } } } })
    const wrapper = mountPage()
    await flushPromises()

    await wrapper.get('input[type="password"]').setValue('Synthetic-password-42')
    await wrapper.findAll('button').find(b => b.text() === 'mfa_setup.create_passkey')!.trigger('click')
    await flushPromises()
    await wrapper.get('[data-recovery-once-confirm]').trigger('click')
    await flushPromises()

    expect(m.replace).not.toHaveBeenCalled()
    expect(wrapper.find('[data-recovery-once-confirm]').exists()).toBe(false)
    expect(wrapper.findAll('button').some(b => b.text() === 'mfa_setup.create_passkey')).toBe(true)
    expect(wrapper.text()).toContain('passkeys.test_revoked')
    wrapper.unmount()
  })
})

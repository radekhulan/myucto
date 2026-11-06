import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

/**
 * Záložka Profil → Heslo → 2FA: po aktivaci TOTP vrací API první sadu záložních
 * kódů (`recovery_codes`). Zobrazují se jen jednou — stránka je musí ukázat
 * a nechat uživatele potvrdit uložení, stejně jako ForcedMfaSetup. Dřív je
 * tahle záložka tiše zahodila (toast „aktivováno" a nic víc).
 */
const m = vi.hoisted(() => ({
  domainPending: false,
  authorizeDomain: vi.fn(),
  totpStatus: vi.fn(),
  totpSetup: vi.fn(),
  totpEnable: vi.fn(),
  ownedCsrf: vi.fn<() => string | null>(() => null),
  advanceSession: vi.fn(),
  setSessionCsrfToken: vi.fn(),
  changePassword: vi.fn(),
  refresh: vi.fn(),
  replace: vi.fn(),
  routeQuery: { tab: 'totp' as string | undefined },
  store: { user: { totp_enabled: false, mfa_methods: [], passkey_count: 0 } as any },
}))

vi.mock('@/api/auth', () => ({
  authApi: {
    totpStatus: m.totpStatus,
    totpSetup: m.totpSetup,
    totpEnable: m.totpEnable,
    changePassword: m.changePassword,
    passkeyStepUpOptions: vi.fn(),
    passkeyStepUpVerify: vi.fn(),
    sessionLockPreference: vi.fn(),
  },
}))
vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ ...m.store, refresh: m.refresh, setSessionCsrfToken: m.setSessionCsrfToken }),
}))
vi.mock('@/stores/sessionSecurity', () => ({
  useSessionSecurityStore: () => ({ refresh: vi.fn(), clear: vi.fn() }),
}))
vi.mock('@/composables/useToast', () => ({
  useToast: () => ({ success: vi.fn(), error: vi.fn() }),
}))
vi.mock('@/security/webauthn', () => ({
  getCredential: vi.fn(),
  webAuthnErrorKey: () => null,
}))
vi.mock('@/security/domainLogin', () => ({
  canonicalDomainLoginSessionCsrf: m.ownedCsrf,
  advanceCanonicalDomainLoginSession: m.advanceSession,
  hasPendingCanonicalDomainLogin: () => m.domainPending,
  authorizePendingDomainLogin: m.authorizeDomain,
  isDomainLoginConfirmationRequired: (e: any) => e?.response?.data?.error?.code === 'domain_login_confirmation_required',
  pendingCanonicalDomainLoginQuery: () => ({ domain_login_request: 'r'.repeat(43), state: 's'.repeat(43) }),
}))
vi.mock('@/pages/Passkeys.vue', () => ({ default: { name: 'Passkeys', emits: ['registered'], template: '<button data-test="register-passkey" @click="$emit(\'registered\')" />' } }))
vi.mock('@/pages/KeyboardShortcuts.vue', () => ({ default: { name: 'KeyboardShortcuts', template: '<div />' } }))
vi.mock('@/components/security/RecoveryCodesOnce.vue', () => ({
  default: {
    name: 'RecoveryCodesOnce',
    props: ['codes', 'busy'],
    emits: ['confirm'],
    template: '<div data-test="recovery-codes">{{ codes.join(",") }}<button data-test="recovery-confirm" @click="$emit(\'confirm\')" /></div>',
  },
}))
vi.mock('vue-router', async () => {
  const { reactive } = await vi.importActual<typeof import('vue')>('vue')
  const query = reactive(m.routeQuery)
  m.replace.mockImplementation((target: { query?: Record<string, unknown> }) => {
    for (const key of Object.keys(query)) delete (query as Record<string, unknown>)[key]
    for (const [key, value] of Object.entries(target.query ?? {})) {
      if (value !== undefined) (query as Record<string, unknown>)[key] = value
    }
  })
  return {
    useRoute: () => ({ query }),
    useRouter: () => ({ replace: m.replace, back: vi.fn() }),
  }
})
vi.mock('vue-i18n', () => ({
  useI18n: () => ({ locale: { value: 'cs' }, t: (key: string) => key }),
}))

import PasswordChange from '../PasswordChange.vue'

const mountPage = () => mount(PasswordChange, { global: { stubs: { RouterLink: true } } })

async function activate(wrapper: ReturnType<typeof mountPage>) {
  await wrapper.get('[data-test="totp-current-password"]').setValue('Synthetic-test-password-42')
  await wrapper.get('[data-test="totp-start"]').trigger('click')
  await flushPromises()
  await wrapper.get('[data-test="totp-code"]').setValue('123456')
  await wrapper.get('[data-test="totp-activate"]').trigger('click')
  await flushPromises()
}

beforeEach(() => {
  vi.clearAllMocks()
  m.domainPending = false
  m.authorizeDomain.mockReset().mockResolvedValue(true)
  m.ownedCsrf.mockReturnValue(null)
  m.routeQuery.tab = 'totp'
  m.store.user = { totp_enabled: false, mfa_methods: [], passkey_count: 0 }
  m.totpStatus.mockResolvedValue({ enabled: false })
  m.totpSetup.mockResolvedValue({ secret: 'S', uri: 'otpauth://x', qr_data_uri: 'data:,' })
  m.changePassword.mockResolvedValue(undefined)
  m.refresh.mockImplementation(async () => {
    m.store.user.totp_enabled = true
    return true
  })
})

describe('PasswordChange — záložní kódy po aktivaci TOTP', () => {
  it('ukáže první sadu záložních kódů z odpovědi a stav přepne až po potvrzení', async () => {
    m.totpEnable.mockResolvedValue({ enabled: true, recovery_codes: ['AAAA-BBBB', 'CCCC-DDDD'] })
    const wrapper = mountPage()
    await flushPromises()
    m.totpStatus.mockResolvedValue({ enabled: true })

    await activate(wrapper)

    expect(m.refresh).toHaveBeenCalledTimes(1)
    expect(m.store.user.totp_enabled).toBe(true)
    expect(wrapper.get('[data-test="recovery-codes"]').text()).toContain('AAAA-BBBB,CCCC-DDDD')
    expect(wrapper.text()).not.toContain('auth.totp_status_enabled')

    await wrapper.get('[data-test="recovery-confirm"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="recovery-codes"]').exists()).toBe(false)
    expect(wrapper.text()).toContain('auth.totp_status_enabled')
  })

  it('bez kódů v odpovědi (uživatel už sadu má) rovnou ukáže aktivní stav', async () => {
    m.totpEnable.mockResolvedValue({ enabled: true })
    const wrapper = mountPage()
    await flushPromises()
    m.totpStatus.mockResolvedValue({ enabled: true })

    await activate(wrapper)

    expect(m.refresh).toHaveBeenCalledTimes(1)
    expect(m.store.user.totp_enabled).toBe(true)
    expect(wrapper.find('[data-test="recovery-codes"]').exists()).toBe(false)
    expect(wrapper.text()).toContain('auth.totp_status_enabled')
  })

  it('po souběžné aktivaci obnoví stav faktoru i pro další stránky', async () => {
    m.totpEnable.mockRejectedValue({
      response: { data: { error: { code: 'already_enabled' } } },
    })
    const wrapper = mountPage()
    await flushPromises()
    m.totpStatus.mockResolvedValue({ enabled: true })

    await activate(wrapper)

    expect(m.refresh).toHaveBeenCalledTimes(1)
    expect(m.store.user.totp_enabled).toBe(true)
    expect(wrapper.text()).toContain('auth.totp_status_enabled')
  })

  it.each(['enrollment_stale', 'no_secret'])(
    'po chybě %s zahodí neplatný QR kód a nabídne nový setup',
    async (code) => {
      m.totpEnable.mockRejectedValue({
        response: { data: { error: { code, message: 'Začni znovu.' } } },
      })
      const wrapper = mountPage()
      await flushPromises()

      await activate(wrapper)

      expect(wrapper.text()).toContain('Začni znovu.')
      expect(wrapper.find('[data-test="totp-code"]').exists()).toBe(false)
      expect(wrapper.find('[data-test="totp-current-password"]').exists()).toBe(true)
    },
  )

  it('po změně hesla zahodí QR kód rozpracovaného TOTP, který server zrušil', async () => {
    const wrapper = mountPage()
    await flushPromises()

    await wrapper.get('[data-test="totp-current-password"]').setValue('Synthetic-current-password-42')
    await wrapper.get('[data-test="totp-start"]').trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-test="totp-code"]').exists()).toBe(true)

    const passwordTab = wrapper.findAll('button')
      .find(button => button.text() === 'auth.change_password_title')
    expect(passwordTab).toBeDefined()
    await passwordTab!.trigger('click')
    await flushPromises()

    const passwordInputs = wrapper.get('form').findAll('input')
    await passwordInputs[0].setValue('Synthetic-current-password-42')
    await passwordInputs[1].setValue('Synthetic-new-password-84')
    await passwordInputs[2].setValue('Synthetic-new-password-84')
    await wrapper.get('form').trigger('submit')
    await flushPromises()

    const totpTab = wrapper.findAll('button')
      .find(button => button.text() === 'auth.totp_tab')
    expect(totpTab).toBeDefined()
    await totpTab!.trigger('click')
    await flushPromises()

    expect(m.changePassword).toHaveBeenCalledTimes(1)
    expect(wrapper.find('[data-test="totp-code"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="totp-current-password"]').exists()).toBe(true)
  })
})


describe('PasswordChange — owned portal session rotation', () => {
  it('binds TOTP completion to the owned session and advances ownership before refresh', async () => {
    m.ownedCsrf.mockReturnValue('owned')
    m.totpEnable.mockResolvedValue({ enabled: true, csrf_token: 'rotated' })
    const wrapper = mountPage()
    await flushPromises()
    await activate(wrapper)
    expect(m.totpEnable).toHaveBeenCalledWith('123456', 'owned')
    expect(m.advanceSession).toHaveBeenCalledWith('owned', 'rotated')
    expect(m.setSessionCsrfToken).toHaveBeenCalledWith('rotated')
    expect(m.advanceSession.mock.invocationCallOrder[0]).toBeLessThan(m.refresh.mock.invocationCallOrder[0]!)
    wrapper.unmount()
  })
})


describe('PasswordChange — návrat po registraci klíče z portálu', () => {
  it('po přidání klíče dokončí předání a úklid dočasné relace', async () => {
    m.domainPending = true
    m.routeQuery.tab = 'passkeys'
    const wrapper = mountPage()
    await flushPromises()
    await wrapper.get('[data-test="register-passkey"]').trigger('click')
    await flushPromises()
    expect(m.authorizeDomain).toHaveBeenCalledOnce()
    wrapper.unmount()
  })

  it('při selhání návratu zobrazí chybu a umožní opakování', async () => {
    m.domainPending = true
    m.routeQuery.tab = 'passkeys'
    m.authorizeDomain.mockRejectedValueOnce(new Error('offline'))
    const wrapper = mountPage()
    await flushPromises()
    await wrapper.get('[data-test="register-passkey"]').trigger('click')
    await flushPromises()
    expect(wrapper.text()).toContain('domain_login.authorize_failed')
    const retry = wrapper.findAll('button').find(b => b.text() === 'domain_login.return_to_client')!
    await retry.trigger('click')
    await flushPromises()
    expect(m.authorizeDomain).toHaveBeenCalledTimes(2)
    wrapper.unmount()
  })

  it('samostatná správa klíčů na hlavní adrese neprovádí předání', async () => {
    m.routeQuery.tab = 'passkeys'
    const wrapper = mountPage()
    await flushPromises()
    await wrapper.get('[data-test="register-passkey"]').trigger('click')
    await flushPromises()
    expect(m.authorizeDomain).not.toHaveBeenCalled()
    wrapper.unmount()
  })
})

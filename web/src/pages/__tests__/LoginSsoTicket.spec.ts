import { beforeEach, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { parseSsoLoginTicket, resetSsoState } from '@/security/oidc'

const m = vi.hoisted(() => ({
  replace: vi.fn(), push: vi.fn(), navigate: vi.fn(),
  query: {} as Record<string, string>,
  auth: {
    fetchSetupStatus: vi.fn(), refresh: vi.fn(), login: vi.fn(), needsSetup: false,
    setupStatus: { captcha: { provider: 'none' }, passwordless_login_enabled: false,
      sso_providers: [
        { id: 'entra', label: 'Microsoft', strong: true },
        { id: 'google', label: 'Google', strong: false },
      ] },
    isAuthenticated: false, mustSetupMfa: false, mustSetupTotp: false, shouldOfferMfa: false,
    isInstanceAdmin: false, isClientRole: false, loading: false, isDemo: false,
    domainContext: { mode: 'canonical', locked: false },
  },
}))
vi.mock('@/components/layout/AppShell.vue', () => ({ default: { template: '<div><slot /></div>' } }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => m.auth }))
vi.mock('@/api/auth', () => ({ authApi: {} }))
vi.mock('@/router', () => ({ clearLoginBounces: vi.fn(), loginRedirectLoopDetected: () => false }))
vi.mock('@/composables/useTurnstile', () => ({ useTurnstile: () => ({ token: { value: '' }, reset: vi.fn() }) }))
vi.mock('@/security/webauthn', () => ({ isWebAuthnAvailable: () => false, getCredential: vi.fn(), webAuthnErrorKey: () => null, cancelActiveWebAuthnCeremony: vi.fn() }))
vi.mock('@/security/domainLogin', () => ({
  captureCanonicalDomainLogin: vi.fn(), hasPendingCanonicalDomainLogin: () => false,
  hasPendingCanonicalDomainLoginCleanup: () => false, pendingCanonicalDomainLoginHandoff: () => null,
  canonicalDomainLoginSessionCsrf: () => null, needsCanonicalDomainLoginConfirmation: () => false,
}))
vi.mock('@/workspace/fullPageNavigation', () => ({ navigateFullPage: m.navigate }))
vi.mock('vue-router', () => ({ useRoute: () => ({ query: m.query }), useRouter: () => ({ replace: m.replace, push: m.push }) }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))
import Login from '../Login.vue'

const TICKET = '0123456789abcdef'.repeat(4)

function arriveWithTicket(extra = '') {
  window.history.replaceState(null, '', `/login#sso_ticket=${TICKET}&sso_provider=google${extra}`)
}

beforeEach(() => {
  vi.clearAllMocks()
  resetSsoState()
  m.query = {}
  m.auth.isAuthenticated = false
  m.auth.refresh.mockResolvedValue(false)
  window.history.replaceState(null, '', '/login')
})

it('lístek z fragmentu přečte, z adresy hned odstraní a přihlášení dokončí se sso_ticket', async () => {
  arriveWithTicket(`&return_to=${encodeURIComponent('/invoices?x=1')}`)
  m.auth.login.mockResolvedValueOnce({ id: 1 })
  const wrapper = mount(Login)
  expect(window.location.hash).toBe('')
  expect(window.location.pathname).toBe('/login')
  await flushPromises()

  expect(m.auth.login).toHaveBeenCalledTimes(1)
  expect(m.auth.login).toHaveBeenCalledWith('', '', undefined, undefined, expect.objectContaining({ ssoTicket: TICKET }))
  expect(m.push).toHaveBeenCalledWith('/invoices?x=1')
  wrapper.unmount()
})

it('krok TOTP odešle znovu sso_ticket místo e-mailu a hesla', async () => {
  arriveWithTicket()
  m.auth.login.mockRejectedValueOnce({ response: { status: 401, data: { error: { code: 'totp_required', methods: ['totp'] } } } })
  const wrapper = mount(Login)
  await flushPromises()

  expect(wrapper.get('[data-testid="sso-ticket"]').text()).toContain('sso.ticket_notice')
  expect(wrapper.find('input[type="email"]').exists()).toBe(false)
  expect(wrapper.find('input[type="password"]').exists()).toBe(false)
  expect(wrapper.find('[data-testid="sso-login"]').exists()).toBe(false)

  m.auth.login.mockResolvedValueOnce({ id: 1 })
  await wrapper.get('input[autocomplete="one-time-code"]').setValue('123456')
  await wrapper.get('form').trigger('submit')
  await flushPromises()

  expect(m.auth.login).toHaveBeenCalledTimes(2)
  expect(m.auth.login).toHaveBeenLastCalledWith('', '', undefined, '123456', expect.objectContaining({ ssoTicket: TICKET }))
  expect(m.push).toHaveBeenCalledWith('/')
  wrapper.unmount()
})

it('prošlý lístek vrátí běžný formulář s hláškou sso_flow_invalid', async () => {
  arriveWithTicket()
  m.auth.login.mockRejectedValueOnce({ response: { status: 401, data: { error: { code: 'sso_flow_invalid' } } } })
  const wrapper = mount(Login)
  await flushPromises()

  expect(wrapper.find('[data-testid="sso-ticket"]').exists()).toBe(false)
  expect(wrapper.find('input[type="email"]').exists()).toBe(true)
  expect(wrapper.text()).toContain('sso.errors.sso_flow_invalid')
  wrapper.unmount()
})

it('zrušení lístku vrátí formulář a tlačítka poskytovatelů', async () => {
  arriveWithTicket()
  m.auth.login.mockRejectedValueOnce({ response: { status: 401, data: { error: { code: 'totp_required', methods: ['totp'] } } } })
  const wrapper = mount(Login)
  await flushPromises()
  await wrapper.get('[data-testid="sso-ticket-cancel"]').trigger('click')

  expect(wrapper.find('input[type="email"]').exists()).toBe(true)
  expect(wrapper.find('[data-testid="sso-login-google"]').exists()).toBe(true)
  expect(wrapper.find('[data-testid="sso-login-entra"]').exists()).toBe(true)
  wrapper.unmount()
})

it('bez lístku se automaticky nepřihlašuje', async () => {
  const wrapper = mount(Login)
  await flushPromises()
  expect(m.auth.login).not.toHaveBeenCalled()
  wrapper.unmount()
})

it('neplatný lístek ani cizí návratovou adresu nepřijme', () => {
  expect(parseSsoLoginTicket('#sso_ticket=abc&sso_provider=google')).toBeNull()
  expect(parseSsoLoginTicket(`#sso_ticket=${TICKET}&sso_provider=Goo gle`)).toBeNull()
  expect(parseSsoLoginTicket(`#sso_ticket=${TICKET}&sso_provider=google&return_to=${encodeURIComponent('//evil.example')}`))
    .toEqual({ ticket: TICKET, provider: 'google', returnTo: null })
  expect(parseSsoLoginTicket(`#sso_ticket=${TICKET}&sso_provider=apple&return_to=%2Fprofile`))
    .toEqual({ ticket: TICKET, provider: 'apple', returnTo: '/profile' })
})

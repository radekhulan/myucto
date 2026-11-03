import { beforeEach, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const m = vi.hoisted(() => ({
  replace: vi.fn(), navigate: vi.fn(),
  query: {} as Record<string, string>,
  auth: {
    fetchSetupStatus: vi.fn(), refresh: vi.fn(), login: vi.fn(), needsSetup: false,
    setupStatus: { captcha: { provider: 'none' }, passwordless_login_enabled: false,
      sso_providers: [{ id: 'entra', label: 'Microsoft' }] },
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
vi.mock('vue-router', () => ({ useRoute: () => ({ query: m.query }), useRouter: () => ({ replace: m.replace, push: vi.fn() }) }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))
import Login from '../Login.vue'

beforeEach(() => {
  vi.clearAllMocks()
  m.query = {}
  m.auth.isAuthenticated = false
  m.auth.refresh.mockResolvedValue(false)
})

it('tlačítko Microsoft zahájí přihlášení navigací s návratovou adresou', async () => {
  m.query = { return_to: '/invoices?x=1' }
  const wrapper = mount(Login)
  await flushPromises()
  await wrapper.get('[data-testid="sso-login-entra"]').trigger('click')
  expect(m.navigate).toHaveBeenCalledWith('/api/auth/oidc/entra/login?return_to=%2Finvoices%3Fx%3D1')
  wrapper.unmount()
})

it('chybu návratu přeloží, neznámý kód obecnou hláškou', async () => {
  m.query = { sso_error: 'sso_not_linked' }
  let wrapper = mount(Login)
  await flushPromises()
  expect(wrapper.get('[data-testid="sso-error"]').text()).toBe('sso.errors.sso_not_linked')
  wrapper.unmount()
  m.query = { sso_error: 'neco_jineho' }
  wrapper = mount(Login)
  await flushPromises()
  expect(wrapper.get('[data-testid="sso-error"]').text()).toBe('sso.errors.generic')
  wrapper.unmount()
})

it('odmítnuté heslo kvůli vynucenému přihlášení přes organizaci ukáže vysvětlení', async () => {
  m.auth.login.mockRejectedValueOnce({ response: { status: 403, data: { error: { code: 'sso_required', provider: 'entra' } } } })
  const wrapper = mount(Login)
  await flushPromises()
  expect(wrapper.find('[data-testid="sso-required"]').exists()).toBe(false)
  await wrapper.get('form').trigger('submit')
  await flushPromises()
  expect(wrapper.find('[data-testid="sso-required"]').exists()).toBe(true)
  expect(wrapper.find('.text-danger-500').exists()).toBe(false)
  wrapper.unmount()
})

it('s platným sezením a sso_required v adrese zůstane na přihlášení', async () => {
  m.query = { sso_required: 'entra', return_to: '/invoices' }
  m.auth.isAuthenticated = true
  const wrapper = mount(Login)
  await flushPromises()
  expect(m.replace).not.toHaveBeenCalled()
  expect(wrapper.find('[data-testid="sso-required"]').exists()).toBe(true)
  wrapper.unmount()
})

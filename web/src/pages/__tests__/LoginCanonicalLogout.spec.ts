import { beforeEach, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const m = vi.hoisted(() => ({
  replace: vi.fn(), authorize: vi.fn(), record: vi.fn(), verify: vi.fn(), cleanup: false,
  csrf: null as string | null, needsConfirm: false, preview: vi.fn(), confirm: vi.fn(), clear: vi.fn(),
  auth: {
    fetchSetupStatus: vi.fn(), refresh: vi.fn(), login: vi.fn(), needsSetup: false,
    setupStatus: { captcha: { provider: 'none' }, passwordless_login_enabled: true,
      demo: undefined as { enabled: boolean; auto_login: boolean; email: string; password: string } | undefined },
    isAuthenticated: false, mustSetupMfa: false, mustSetupTotp: false, shouldOfferMfa: false,
    domainContext: { mode: 'canonical', locked: false },
  },
}))
vi.mock('@/components/layout/AppShell.vue', () => ({ default: { template: '<div><slot /></div>' } }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => m.auth }))
vi.mock('@/api/auth', () => ({ authApi: {
  passkeyLoginOptions: vi.fn().mockResolvedValue({ flow_token: 'flow', public_key: {} }),
  passkeyLoginVerify: m.verify,
} }))
vi.mock('@/router', () => ({ clearLoginBounces: vi.fn(), loginRedirectLoopDetected: () => false }))
vi.mock('@/composables/useTurnstile', () => ({ useTurnstile: () => ({ token: { value: '' }, reset: vi.fn() }) }))
vi.mock('@/security/webauthn', () => ({
  isWebAuthnAvailable: () => true, getCredential: vi.fn().mockResolvedValue({ id: 'credential' }),
  webAuthnErrorKey: () => null,
}))
vi.mock('@/security/domainLogin', () => ({
  captureCanonicalDomainLogin: vi.fn(), hasPendingCanonicalDomainLogin: () => true,
  hasPendingCanonicalDomainLoginCleanup: () => m.cleanup,
  pendingCanonicalDomainLoginHandoff: () => null,
  authorizePendingDomainLogin: m.authorize, recordCanonicalDomainLoginSession: m.record,
  canonicalDomainLoginSessionCsrf: () => m.csrf,
  reconcileCanonicalDomainLoginSession: vi.fn(),
  needsCanonicalDomainLoginConfirmation: () => m.needsConfirm,
  previewPendingDomainLogin: m.preview, confirmPendingDomainLogin: m.confirm,
  cancelPendingDomainLogin: m.clear,
  isDomainLoginConfirmationRequired: (e: any) => e?.response?.data?.error?.code === 'domain_login_confirmation_required',
}))
vi.mock('vue-router', () => ({ useRoute: () => ({ query: {} }), useRouter: () => ({ replace: m.replace }) }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))
import Login from '../Login.vue'

beforeEach(() => {
  vi.clearAllMocks()
  localStorage.clear()
  m.clear.mockReset().mockResolvedValue(undefined)
  m.cleanup = false
  m.csrf = null
  m.needsConfirm = false
  m.auth.isAuthenticated = false
  m.auth.setupStatus.demo = undefined
  m.auth.refresh.mockResolvedValue(false)
  m.authorize.mockResolvedValue(true)
  m.verify.mockResolvedValue({ csrf_token: 'issued-csrf' })
  m.preview.mockResolvedValue({ target_hostname: 'portal.example.test', supplier_name: 'Firma s.r.o.' })
  m.confirm.mockImplementation(() => { m.needsConfirm = false })
})

it('existující přihlášení nepředá a vyžádá nové ověření', async () => {
  m.auth.isAuthenticated = true
  m.needsConfirm = true
  const wrapper = mount(Login)
  await flushPromises()
  expect(m.authorize).not.toHaveBeenCalled()
  expect(m.auth.refresh).not.toHaveBeenCalled()
  expect(wrapper.find('form').exists()).toBe(true)
  expect(m.record).not.toHaveBeenCalled()
  wrapper.unmount()
})

it('zrušení potvrzení požadavek zahodí a nic nepředá', async () => {
  m.csrf = 'owned'
  m.auth.isAuthenticated = true
  m.needsConfirm = true
  const wrapper = mount(Login)
  await flushPromises()
  await wrapper.get('[data-testid="domain-login-cancel"]').trigger('click')
  await flushPromises()
  expect(m.clear).toHaveBeenCalledOnce()
  expect(m.authorize).not.toHaveBeenCalled()
  expect(m.replace).toHaveBeenCalledWith('/')
  wrapper.unmount()
})

it('při chybě úklidu zrušení ponechá potvrzení a umožní opakování', async () => {
  m.csrf = 'owned'
  m.auth.isAuthenticated = true
  m.needsConfirm = true
  m.clear.mockRejectedValueOnce(new Error('offline'))
  const wrapper = mount(Login)
  await flushPromises()
  await wrapper.get('[data-testid="domain-login-cancel"]').trigger('click')
  await flushPromises()
  expect(m.replace).not.toHaveBeenCalled()
  expect(wrapper.get('[role="alert"]').text()).toBe('domain_login.cleanup_failed')
  await wrapper.get('[data-testid="domain-login-cancel"]').trigger('click')
  await flushPromises()
  expect(m.clear).toHaveBeenCalledTimes(2)
  expect(m.replace).toHaveBeenCalledWith('/')
  wrapper.unmount()
})

it('na požádání serveru zobrazí potvrzení i u přihlášení z tohoto toku', async () => {
  m.csrf = 'owned'
  m.auth.isAuthenticated = true
  m.authorize.mockRejectedValueOnce({ response: { status: 409, data: { error: { code: 'domain_login_confirmation_required' } } } })
  const wrapper = mount(Login)
  await flushPromises()
  expect(m.preview).toHaveBeenCalledOnce()
  expect(wrapper.find('[role="alert"]').exists()).toBe(false)
  await wrapper.get('[data-testid="domain-login-continue"]').trigger('click')
  await flushPromises()
  expect(m.authorize).toHaveBeenCalledTimes(2)
  wrapper.unmount()
})

it('po reloadu zopakuje úklid před refresh a při chybě nabídne opakování', async () => {
  m.cleanup = true
  m.authorize.mockRejectedValueOnce(new Error('offline'))
  const wrapper = mount(Login)
  await flushPromises()
  expect(m.auth.refresh).not.toHaveBeenCalled()
  expect(wrapper.find('form').exists()).toBe(false)
  expect(wrapper.get('[role="alert"]').text()).toBe('domain_login.cleanup_failed')
  await wrapper.get('button').trigger('click')
  await flushPromises()
  expect(m.authorize).toHaveBeenCalledTimes(2)
  expect(m.auth.refresh).not.toHaveBeenCalled()
  wrapper.unmount()
})

it.each(['passwordless', 'second-factor'])('zachytí vydanou %s passkey relaci ještě před refresh', async (mode) => {
  const wrapper = mount(Login)
  await flushPromises()
  m.auth.refresh.mockImplementation(async () => {
    expect(m.record).toHaveBeenCalledWith('issued-csrf')
    return true
  })
  if (mode === 'second-factor') {
    m.auth.login.mockRejectedValueOnce({ response: { data: { error: {
      code: 'mfa_required', flow_token: 'flow', public_key: {}, methods: ['passkey'],
    } } } })
    await wrapper.get('form').trigger('submit')
    await flushPromises()
  }
  const label = mode === 'passwordless' ? 'auth.passwordless_passkey_login' : 'auth.passkey_login'
  await wrapper.findAll('button').find(button => button.text() === label)!.trigger('click')
  await flushPromises()
  expect(m.record).toHaveBeenCalledWith('issued-csrf')
  expect(m.verify).toHaveBeenCalledWith('flow', { id: 'credential' })
  expect(m.authorize).toHaveBeenCalledOnce()
  wrapper.unmount()
})


it('nový tok ani v demu nevyplní a automaticky neodešle přihlašovací údaje', async () => {
  m.auth.setupStatus.demo = { enabled: true, auto_login: true, email: 'demo@example.test', password: 'synthetic-password' }
  const wrapper = mount(Login)
  await flushPromises()
  expect(m.auth.login).not.toHaveBeenCalled()
  expect(m.authorize).not.toHaveBeenCalled()
  expect((wrapper.get('input[type="email"]').element as HTMLInputElement).value).toBe('')
  expect((wrapper.get('input[type="password"]').element as HTMLInputElement).value).toBe('')
  wrapper.unmount()
})

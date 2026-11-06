import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { markPortalLoggedOut, isPortalLoggedOut } from '@/security/portalLogout'
const m = vi.hoisted(() => ({
  replace: vi.fn(), begin: vi.fn(), query: {} as Record<string, unknown>,
  auth: { fetchSetupStatus: vi.fn(), refresh: vi.fn(), needsSetup: false, setupStatus: null,
    isAuthenticated: false, mustSetupMfa: false, mustSetupTotp: false, shouldOfferMfa: false,
    domainContext: { mode: 'custom', locked: true } },
}))
vi.mock('@/components/layout/AppShell.vue', () => ({ default: { template: '<div><slot /></div>' } }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => m.auth }))
vi.mock('@/api/auth', () => ({ authApi: {} }))
vi.mock('@/router', () => ({ clearLoginBounces: vi.fn(), loginRedirectLoopDetected: () => false }))
vi.mock('@/composables/useTurnstile', () => ({ useTurnstile: () => ({}) }))
vi.mock('@/security/webauthn', () => ({ isWebAuthnAvailable: () => false }))
vi.mock('@/security/domainLogin', () => ({
  beginDomainLogin: m.begin, hasPendingCanonicalDomainLogin: () => false,
  canonicalDomainLoginSessionCsrf: () => null,
}))
vi.mock('vue-router', () => ({ useRoute: () => ({ query: m.query }), useRouter: () => ({ replace: m.replace }) }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))
import Login from '../Login.vue'
beforeEach(() => {
  vi.clearAllMocks(); localStorage.clear(); m.query = {}; m.auth.isAuthenticated = false
  m.begin.mockResolvedValue(undefined)
})
describe('vlastní doména po odhlášení', () => {
  it('při opakovaném mountu zůstane odhlášená bez formuláře a SSO', async () => {
    markPortalLoggedOut()
    for (let reload = 0; reload < 2; reload++) {
      const wrapper = mount(Login); await flushPromises()
      expect(wrapper.find('[role="status"]').text()).toBe('domain_login.logged_out')
      expect(wrapper.find('form').exists()).toBe(false)
      expect(wrapper.find('input').exists()).toBe(false)
      expect(m.begin).not.toHaveBeenCalled(); wrapper.unmount()
    }
    expect(m.auth.refresh).toHaveBeenCalledTimes(2)
  })
  it('kliknutím zahájí přihlášení s bezpečnou cílovou cestou', async () => {
    markPortalLoggedOut(); m.query = { return_to: '/invoices' }
    const wrapper = mount(Login); await flushPromises(); await wrapper.get('button').trigger('click')
    expect(m.begin).toHaveBeenCalledWith('/invoices', undefined)
    expect(isPortalLoggedOut()).toBe(true); wrapper.unmount()
  })
  it('po chybě umožní opakování a nebezpečný návrat nahradí portálem', async () => {
    markPortalLoggedOut(); m.query = { return_to: '//evil.example.test' }
    m.begin.mockRejectedValueOnce(new Error('offline'))
    const wrapper = mount(Login); await flushPromises()
    await wrapper.get('button').trigger('click'); await flushPromises()
    expect(wrapper.get('[role="alert"]').text()).toBe('domain_login.start_failed')
    expect(wrapper.get('button').attributes('disabled')).toBeUndefined()
    await wrapper.get('button').trigger('click'); await flushPromises()
    expect(m.begin).toHaveBeenNthCalledWith(2, '/portal', undefined)
    expect(wrapper.find('[role="alert"]').exists()).toBe(false); wrapper.unmount()
  })
  it('handoff čeká po odhlášení na kliknutí', async () => {
    markPortalLoggedOut(); m.query = { domain_handoff: '/profile/password?tab=totp' }
    const wrapper = mount(Login); await flushPromises()
    expect(m.begin).not.toHaveBeenCalled()
    await wrapper.get('button').trigger('click')
    expect(m.begin).toHaveBeenCalledWith('/portal', '/profile/password?tab=totp'); wrapper.unmount()
  })
  it('platná nezávislá relace má přednost před starým markerem', async () => {
    markPortalLoggedOut(); m.auth.isAuthenticated = true
    const wrapper = mount(Login); await flushPromises()
    expect(m.replace).toHaveBeenCalledWith('/portal'); expect(m.begin).not.toHaveBeenCalled(); wrapper.unmount()
  })
  it('první návštěva automaticky začne SSO', async () => {
    const wrapper = mount(Login); await flushPromises()
    expect(m.begin.mock.calls[0]?.[0]).toBe('/portal'); wrapper.unmount()
  })
})

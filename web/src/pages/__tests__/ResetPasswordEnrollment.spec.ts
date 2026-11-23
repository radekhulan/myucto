import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { rememberTotpEnrollment, takeTotpEnrollment } from '@/security/totpEnrollment'

const m = vi.hoisted(() => ({
  reset: vi.fn(), push: vi.fn(), refresh: vi.fn(), fetchSetupStatus: vi.fn(),
  auth: {
    isAuthenticated: true, mustSetupMfa: true, mustSetupTotp: false, shouldOfferMfa: false, user: { id: 17 },
    setupStatus: null as { sso_required_provider: string | null } | null,
  },
}))
vi.mock('@/components/layout/AppShell.vue', () => ({ default: { template: '<div><slot /></div>' } }))
vi.mock('@/api/auth', () => ({ authApi: { reset: m.reset } }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ ...m.auth, refresh: m.refresh, fetchSetupStatus: m.fetchSetupStatus }) }))
vi.mock('vue-router', () => ({ useRoute: () => ({ query: { token: 'setup-link' } }), useRouter: () => ({ push: m.push }) }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))
import ResetPassword from '../ResetPassword.vue'

beforeEach(() => {
  vi.clearAllMocks()
  rememberTotpEnrollment(null)
  m.auth.mustSetupMfa = true
  m.auth.shouldOfferMfa = false
  m.auth.setupStatus = null
  m.fetchSetupStatus.mockResolvedValue(undefined)
})

describe('vynucené přihlášení přes organizaci', () => {
  it('heslo nenastavuje a odkáže na přihlášení', async () => {
    m.auth.setupStatus = { sso_required_provider: 'entra' }
    const wrapper = mount(ResetPassword, { global: { stubs: { 'router-link': { template: '<a><slot /></a>' } } } })
    await flushPromises()
    expect(wrapper.find('[data-testid="password-login-disabled"]').text()).toContain('sso.password_login_disabled')
    expect(wrapper.find('input[type="password"]').exists()).toBe(false)
    expect(m.reset).not.toHaveBeenCalled()
    wrapper.unmount()
  })
})

describe('initial password enrollment continuation', () => {
  it.each(['required', 'offer'])('předá jednorázové oprávnění do průvodce: %s', async (mode) => {
    m.auth.mustSetupMfa = mode === 'required'
    m.auth.shouldOfferMfa = mode === 'offer'
    m.reset.mockResolvedValue({ data: { totp_enrollment_token: 'setup-grant' } })
    const wrapper = mount(ResetPassword)
    await flushPromises()
    for (const input of wrapper.findAll('input[type="password"]')) await input.setValue('Synthetic-password-42')
    await wrapper.get('form').trigger('submit')
    await flushPromises()
    expect(m.reset).toHaveBeenCalledWith('setup-link', 'Synthetic-password-42')
    expect(m.push).toHaveBeenCalledWith('/setup-mfa')
    expect(takeTotpEnrollment(17)?.token).toBe('setup-grant')
    wrapper.unmount()
  })

  it('bez oprávnění od serveru žádnou výjimku nevytvoří', async () => {
    m.reset.mockResolvedValue({ data: { ok: true } })
    const wrapper = mount(ResetPassword)
    await flushPromises()
    for (const input of wrapper.findAll('input[type="password"]')) await input.setValue('Synthetic-password-42')
    await wrapper.get('form').trigger('submit')
    await flushPromises()
    expect(takeTotpEnrollment(17)).toBeNull()
    wrapper.unmount()
  })
})

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { captureSsoFragment, rememberPendingStepUp, resetSsoState } from '@/security/oidc'

/**
 * Heslo k šifrovaným zálohám se odhaluje proti čerstvému ověření. Účet bez hesla
 * (přihlášení přes Microsoft Entra ID) se musí umět prokázat účtem organizace.
 */

const m = vi.hoisted(() => ({
  overview: vi.fn(),
  revealPassword: vi.fn(),
  identities: vi.fn(),
  stepUp: vi.fn(),
  navigate: vi.fn(),
}))

vi.mock('@/api/backups', () => ({
  backupsApi: { overview: m.overview, revealPassword: m.revealPassword, download: vi.fn() },
}))
vi.mock('@/api/oidc', () => ({ oidcApi: { identities: m.identities, stepUp: m.stepUp } }))
vi.mock('@/workspace/fullPageNavigation', () => ({ navigateFullPage: m.navigate }))
vi.mock('@/api/auth', () => ({ authApi: {} }))
vi.mock('@/api/errors', () => ({ apiErrorMessage: (e: unknown) => String(e) }))
vi.mock('@/security/webauthn', () => ({
  getCredential: vi.fn(),
  isWebAuthnAvailable: () => false,
}))
vi.mock('@/composables/useToast', () => ({
  useToast: () => ({ success: vi.fn(), error: vi.fn(), info: vi.fn(), warning: vi.fn() }),
}))
vi.mock('vue-i18n', () => ({
  useI18n: () => ({ t: (key: string) => key }),
}))
vi.mock('@/composables/useFormat', () => ({ formatDateTime: (value: string) => value }))
vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({
    user: { id: 1, totp_enabled: false, passkey_count: 0, mfa_methods: [] },
    setupStatus: { sso_providers: [{ id: 'entra' }] },
  }),
}))

import Backups from '@/pages/admin/Backups.vue'
import { invalidateSsoIdentities } from '@/composables/useSsoStepUp'

function mountPage() {
  return mount(Backups, { global: { stubs: { RouterLink: { template: '<a><slot /></a>' } } } })
}

describe('Backups.vue — heslo k zálohám ověřené přes Microsoft', () => {
  beforeEach(() => {
    resetSsoState()
    for (const fn of [m.overview, m.revealPassword, m.identities, m.stepUp, m.navigate]) fn.mockReset()
    m.overview.mockResolvedValue({
      directory: '/backups', encrypted: true, retention: '7d', kinds: [], latest_per_kind: 5,
      sections: [], total_files: 0, total_size_bytes: 0,
    })
    m.identities.mockResolvedValue([{ id: 3, provider: 'entra' }])
    invalidateSsoIdentities()
  })

  afterEach(() => {
    resetSsoState()
  })

  it('s propojenou identitou nabídne vedle hesla ověření přes Microsoft', async () => {
    m.stepUp.mockResolvedValue('https://login.microsoftonline.com/synthetic')
    const wrapper = mountPage()
    await flushPromises()
    ;(wrapper.vm as any).openPasswordModal()
    await flushPromises()

    const option = wrapper.find('[data-testid="sso-step-up"]')
    expect(option.exists()).toBe(true)
    await option.find('button').trigger('click')
    await flushPromises()
    expect(m.stepUp).toHaveBeenCalledWith('entra', 'backup.password', expect.any(String))
    expect(m.navigate).toHaveBeenCalledOnce()
  })

  it('po návratu otevře dialog a proof odešle jako step_up_token bez hesla', async () => {
    rememberPendingStepUp('backup.password', '/admin/backups')
    captureSsoFragment(
      { hash: '#sso_step_up=sso-proof&sso_operation=backup.password', pathname: '/admin/backups', search: '' },
      { state: null, replaceState: vi.fn() },
    )
    m.revealPassword.mockResolvedValue({ encrypted: true, password: 'synthetic-backup-secret' })

    const wrapper = mountPage()
    await flushPromises()
    expect(wrapper.find('[data-testid="sso-step-up-verified"]').exists()).toBe(true)
    expect(wrapper.find('input[type="password"]').exists()).toBe(false)

    const reveal = wrapper.findAll('button').find(button => button.text().includes('backups.show_password')
      && button.element.closest('.fixed') !== null)
    expect(reveal).toBeDefined()
    await reveal!.trigger('click')
    await flushPromises()

    expect(m.revealPassword).toHaveBeenCalledWith({
      password: undefined,
      totp_code: undefined,
      step_up_token: 'sso-proof',
    })
    expect(wrapper.text()).toContain('synthetic-backup-secret')
  })

  it('bez propojené identity volbu nenabídne', async () => {
    m.identities.mockResolvedValue([])
    const wrapper = mountPage()
    await flushPromises()
    ;(wrapper.vm as any).openPasswordModal()
    await flushPromises()
    expect(wrapper.find('[data-testid="sso-step-up"]').exists()).toBe(false)
  })
})

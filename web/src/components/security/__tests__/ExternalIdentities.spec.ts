import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'

/**
 * Profil → Propojené účty. Potvrzení odpojení se musí ukázat u odpojovaného
 * účtu, ne nahoře pod tlačítky propojení: jinak ho uživatel snadno přehlédne
 * a odpojení nedokončí.
 */

const m = vi.hoisted(() => ({
  identities: vi.fn(),
  unlink: vi.fn(),
  link: vi.fn(),
  stepUp: vi.fn(),
  success: vi.fn(),
}))

vi.mock('@/api/oidc', () => ({
  oidcApi: { identities: m.identities, unlink: m.unlink, link: m.link, stepUp: m.stepUp },
}))
vi.mock('@/api/auth', () => ({ authApi: {} }))
vi.mock('@/workspace/fullPageNavigation', () => ({ navigateFullPage: vi.fn() }))
vi.mock('@/security/webauthn', () => ({
  getCredential: vi.fn(),
  isWebAuthnAvailable: () => false,
  webAuthnErrorKey: () => null,
}))
vi.mock('@/composables/useToast', () => ({
  useToast: () => ({ success: m.success, error: vi.fn(), info: vi.fn(), warning: vi.fn() }),
}))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))
vi.mock('vue-router', () => ({ useRoute: () => ({ fullPath: '/profile/password?tab=sso' }) }))
vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({
    user: { id: 1, totp_enabled: false, passkey_count: 0, mfa_methods: [] },
    setupStatus: { sso_providers: [
      { id: 'entra', label: 'Microsoft Entra ID', strong: true },
      { id: 'seznam', label: 'Seznam', strong: false },
    ] },
  }),
}))

import ExternalIdentities from '@/components/security/ExternalIdentities.vue'
import { invalidateSsoIdentities } from '@/composables/useSsoStepUp'

const identities = [
  { id: 1, provider: 'entra', email: 'a@example.test', display_name: null, created_at: null, last_used_at: null },
  { id: 2, provider: 'seznam', email: 'b@example.test', display_name: null, created_at: null, last_used_at: null },
]

describe('ExternalIdentities.vue', () => {
  beforeEach(() => {
    for (const fn of Object.values(m)) fn.mockReset()
    m.identities.mockResolvedValue(identities)
    m.unlink.mockResolvedValue({ ok: true })
    invalidateSsoIdentities()
  })

  it('potvrzení odpojení ukáže pod řádkem odpojovaného účtu a odpojí heslem', async () => {
    const wrapper = mount(ExternalIdentities, { attachTo: document.body })
    await flushPromises()

    const rows = wrapper.findAll('[data-testid="sso-identity"]')
    await rows[1].findAll('button').at(-1)!.trigger('click')
    await flushPromises()

    const slot = document.querySelector('[data-sso-reauth-slot="2"]')!
    const panel = slot.querySelector('[data-testid="sso-reauth"]')
    expect(panel).not.toBeNull()
    expect(panel!.textContent).toContain('sso.reauth_unlink')
    expect(document.activeElement).toBe(panel!.querySelector('input[type="password"]'))

    const input = panel!.querySelector<HTMLInputElement>('input[type="password"]')!
    input.value = 'heslo'
    input.dispatchEvent(new Event('input'))
    panel!.querySelector('form')!.dispatchEvent(new Event('submit'))
    await flushPromises()

    expect(m.unlink).toHaveBeenCalledWith(2, { password: 'heslo', totp_code: undefined })
    expect(document.querySelector('[data-testid="sso-reauth"]')).toBeNull()
    wrapper.unmount()
  })

  it('potvrzení propojení zůstane nahoře pod tlačítky propojení', async () => {
    m.identities.mockResolvedValue([identities[0]])
    const wrapper = mount(ExternalIdentities, { attachTo: document.body })
    await flushPromises()

    await wrapper.get('[data-testid="sso-link-seznam"]').trigger('click')
    await flushPromises()

    const panel = wrapper.find('[data-testid="sso-reauth"]')
    expect(panel.exists()).toBe(true)
    expect(panel.element.closest('[data-sso-reauth-slot]')).toBeNull()
    expect(panel.text()).toContain('sso.reauth_link')
    wrapper.unmount()
  })
})

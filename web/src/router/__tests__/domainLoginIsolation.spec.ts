import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import type { RouteLocationNormalized } from 'vue-router'
vi.mock('@/i18n', () => ({ ensureNamespaces: vi.fn().mockResolvedValue(undefined), namespacesForRoute: () => [] }))
import { authorizationGuard, router } from '../index'
import { useSessionSecurityStore } from '@/stores/sessionSecurity'
import { useAuthStore } from '@/stores/auth'
import { captureCanonicalDomainLogin } from '@/security/domainLogin'
import { setDomainLoginCanonicalOrigin } from '@/security/domainLoginContext'

const request = 'r'.repeat(43)
const state = 's'.repeat(43)
const target = (path: string) => router.resolve(path) as unknown as RouteLocationNormalized
beforeEach(() => {
  sessionStorage.clear()
  setActivePinia(createPinia())
  setDomainLoginCanonicalOrigin(true)
  const auth = useAuthStore()
  auth.setupStatus = { needs_setup: false } as never
  auth.domainContext = { mode: 'canonical', locked: false } as never
})

describe('router izolovaného klientského přihlášení', () => {
  it('při příchodu nového toku zahodí starou canonical identitu ještě před načtením stránky', async () => {
    const auth = useAuthStore()
    auth.user = { id: 1, is_superadmin: true } as never
    auth.setSessionCsrfToken('canonical-csrf')
    useSessionSecurityStore().state = { session_state: 'locked' } as never
    const refresh = vi.spyOn(auth, 'refresh')
    await authorizationGuard(target(`/login?domain_login_request=${request}&state=${state}`))
    expect(auth.isAuthenticated).toBe(false)
    expect(auth.csrfToken).toBe('')
    expect(useSessionSecurityStore().state).toBeNull()
    expect(refresh).not.toHaveBeenCalled()
  })

  it.each(['/profile/password?tab=totp', '/setup-mfa'])('zánik dočasné relace na %s zachová tok i handoff', async (path) => {
    captureCanonicalDomainLogin(request, state, '/profile/password?tab=totp')
    vi.spyOn(useAuthStore(), 'refresh').mockResolvedValue(false)
    const result = await authorizationGuard(target(path))
    expect(result).toEqual({ name: 'login', query: {
      domain_login_request: request, state, domain_login_handoff: '/profile/password?tab=totp',
    } })
  })

  it('dočasná relace nemůže otevřít běžnou aplikaci', async () => {
    captureCanonicalDomainLogin(request, state)
    const result = await authorizationGuard(target('/'))
    expect(result).toEqual({ name: 'login', query: { domain_login_request: request, state } })
  })
})

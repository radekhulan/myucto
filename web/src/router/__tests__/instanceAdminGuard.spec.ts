import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import type { RouteLocationNormalized } from 'vue-router'

vi.mock('@/i18n', () => ({ ensureNamespaces: vi.fn().mockResolvedValue(undefined), namespacesForRoute: () => [] }))
const switchSupplier = vi.hoisted(() => vi.fn().mockResolvedValue(false))
vi.mock('../supplierDeepLink', () => ({ switchSupplierForDeepLink: switchSupplier }))

import { router, authorizationGuard } from '../index'
import { useAuthStore } from '@/stores/auth'
import { useSessionSecurityStore } from '@/stores/sessionSecurity'

function signIn(type = 'instance_admin') {
  const auth = useAuthStore()
  auth.setupStatus = { needs_setup: false } as never
  const superadmin = type === 'superadmin'
  const technical = type === 'instance_admin'
  auth.user = {
    id: 42, email: 'operator@example.test', name: 'Operator',
    role: { id: 8, name: 'Operator', type, system_key: type },
    is_superadmin: superadmin, is_instance_admin: technical,
  } as never
  auth.permissions = { dashboard: 2, invoices: 2, profile: 2 }
  useSessionSecurityStore().state = { session_state: 'active' } as never
  return auth
}
const resolve = (path: string) => router.resolve(path) as unknown as RouteLocationNormalized

describe('Správce instance — oddělený router', () => {
  beforeEach(() => { setActivePinia(createPinia()); sessionStorage.clear(); switchSupplier.mockClear() })

  it.each(['/', '/invoices', '/admin/users', '/portal', '/oauth/continue', '/invoice/' + 'a'.repeat(32)])('odmítne obchodní a integrační cestu %s ještě před přepnutím firmy', async path => {
    signIn()
    expect(await authorizationGuard(resolve(path))).toEqual({ name: 'instance-admin' })
    expect(switchSupplier).not.toHaveBeenCalled()
  })

  it('pustí technickou plochu bez firmy a bez obchodních oprávnění', async () => {
    const auth = signIn()
    auth.permissions = {}
    expect(await authorizationGuard(resolve('/instance-admin'))).toBe(true)
    expect(resolve('/instance-admin').matched[0]?.components?.default).not.toBe(resolve('/').matched[0]?.components?.default)
  })

  it('profil přesměruje mimo obchodní shell i při zamčené relaci', async () => {
    const auth = signIn()
    auth.lockedSession = { session_state: 'locked' } as never
    expect(await authorizationGuard(resolve('/profile/password?tab=totp'))).toEqual({ name: 'instance-admin-profile', query: { tab: 'totp' } })
  })

  it('zachová vynucené MFA a po dokončení přejde na technickou plochu', async () => {
    const auth = signIn()
    auth.user!.must_setup_mfa = true
    expect(await authorizationGuard(resolve('/instance-admin'))).toEqual({ name: 'setup-mfa' })
    expect(await authorizationGuard(resolve('/setup-mfa'))).toBe(true)
    auth.user!.must_setup_mfa = false
    expect(await authorizationGuard(resolve('/setup-mfa'))).toEqual({ name: 'instance-admin' })
  })

  it('běžná role technickou plochu neotevře, superadmin ano', async () => {
    signIn('staff')
    expect(await authorizationGuard(resolve('/instance-admin'))).toEqual({ name: 'home' })
    useAuthStore().lockedSession = { session_state: 'locked' } as never
    expect(await authorizationGuard(resolve('/instance-admin'))).toEqual({ name: 'home' })
    signIn('superadmin')
    expect(await authorizationGuard(resolve('/instance-admin'))).toBe(true)
  })
})

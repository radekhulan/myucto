import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import type { RouteLocationNormalized } from 'vue-router'

vi.mock('@/i18n', () => ({ ensureNamespaces: vi.fn().mockResolvedValue(undefined), namespacesForRoute: () => [] }))
vi.mock('../supplierDeepLink', () => ({ switchSupplierForDeepLink: vi.fn().mockResolvedValue(false) }))

import { router, authorizationGuard } from '../index'
import { useAuthStore } from '@/stores/auth'
import { useSessionSecurityStore } from '@/stores/sessionSecurity'

function signInSuperadmin(demo: boolean) {
  const auth = useAuthStore()
  auth.setupStatus = { needs_setup: false, demo: { enabled: demo } } as never
  auth.user = {
    id: 1, email: 'admin@example.test', name: 'Admin',
    role: { id: 1, name: 'Superadmin', type: 'superadmin', system_key: 'superadmin' },
    is_superadmin: true, is_instance_admin: false,
  } as never
  auth.permissions = { dashboard: 2, invoices: 2, profile: 2 }
  useSessionSecurityStore().state = { session_state: 'active' } as never
  return auth
}
const resolve = (path: string) => router.resolve(path) as unknown as RouteLocationNormalized

describe('Stránky skryté na ukázkové instanci', () => {
  beforeEach(() => { setActivePinia(createPinia()); sessionStorage.clear() })

  it('Uživatele (a s nimi Aktivní relace) superadmin v demu neotevře', async () => {
    signInSuperadmin(true)
    expect(resolve('/admin/users').meta.demoHidden).toBe(true)
    expect(await authorizationGuard(resolve('/admin/users'))).not.toBe(true)
  })

  it('mimo demo superadmin Uživatele otevře jako dřív', async () => {
    signInSuperadmin(false)
    expect(await authorizationGuard(resolve('/admin/users'))).toBe(true)
  })
})

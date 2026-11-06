import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'

vi.mock('@/i18n', () => ({
  ensureNamespaces: vi.fn().mockResolvedValue(undefined),
  namespacesForRoute: () => [],
  i18n: { global: { t: (key: string) => key } },
}))

import { authorizationGuard, router } from '../index'
import { useAuthStore } from '@/stores/auth'
import { useSessionSecurityStore } from '@/stores/sessionSecurity'
import { useSupplierStore } from '@/stores/supplier'
import { useToast } from '@/composables/useToast'

function supplier(accountingFramework: 'cz_500' | 'cz_504', entityProfile: 'nonprofit' | 'svj' | 'unknown') {
  useSupplierStore().setAvailable([{
    id: 7,
    accounting_mode: 'double_entry',
    accounting_framework: accountingFramework,
    entity_profile: entityProfile,
  }] as never, 7)
}

describe('router guard agend NNO/504 a SVJ', () => {
  beforeEach(() => {
    localStorage.clear()
    setActivePinia(createPinia())
    useToast().toasts.value.splice(0)
    const auth = useAuthStore()
    auth.setupStatus = { needs_setup: false } as never
    auth.user = {
      id: 1,
      email: 'framework-router@example.invalid',
      name: 'Framework Router',
      role: { type: 'admin' },
      is_superadmin: false,
      must_setup_mfa: false,
      must_setup_totp: false,
    } as never
    auth.permissions = { accounting: 2, property: 2 }
    useSessionSecurityStore().state = { session_state: 'active' } as never
  })

  it.each([
    ['accounting-framework-conversion', 'conversion504'],
    ['accounting-nonprofit-capital', 'nonprofit504'],
    ['property-registry', 'svj'],
    ['property-payments', 'svj'],
  ] as const)('přímý vstup běžné s.r.o. na %s přesměruje na přehled s hláškou', async (name, scope) => {
    supplier('cz_500', 'unknown')
    await expect(authorizationGuard(router.resolve({ name }) as never)).resolves.toEqual({ name: 'home' })
    expect(useToast().toasts.value.map(toast => [toast.type, toast.text])).toEqual([
      ['warning', `nav.framework_scope_denied.${scope}`],
    ])
  })

  it('pustí nevýdělečnou organizaci na převod rámce a SVJ do registru', async () => {
    supplier('cz_500', 'nonprofit')
    await expect(authorizationGuard(router.resolve({ name: 'accounting-framework-conversion' }) as never)).resolves.toBe(true)
    supplier('cz_504', 'svj')
    await expect(authorizationGuard(router.resolve({ name: 'property-registry' }) as never)).resolves.toBe(true)
    supplier('cz_500', 'svj')
    await expect(authorizationGuard(router.resolve({ name: 'accounting-framework-conversion' }) as never)).resolves.toBe(true)
    await expect(authorizationGuard(router.resolve({ name: 'accounting-nonprofit-capital' }) as never)).resolves.toEqual({ name: 'home' })
    useToast().toasts.value.splice(0)
    expect(useToast().toasts.value).toHaveLength(0)
  })
})

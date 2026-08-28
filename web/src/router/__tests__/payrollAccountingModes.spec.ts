import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'

vi.mock('@/i18n', () => ({
  ensureNamespaces: vi.fn().mockResolvedValue(undefined),
  namespacesForRoute: () => [],
}))

import { authorizationGuard, router } from '../index'
import { useAuthStore } from '@/stores/auth'
import { useSessionSecurityStore } from '@/stores/sessionSecurity'
import { useSupplierStore } from '@/stores/supplier'

describe('router guard mezd v účetních režimech', () => {
  beforeEach(() => {
    localStorage.clear()
    setActivePinia(createPinia())

    const auth = useAuthStore()
    auth.setupStatus = { needs_setup: false } as never
    auth.user = {
      id: 1,
      email: 'payroll-router@example.invalid',
      name: 'Payroll Router',
      role: { type: 'admin' },
      is_superadmin: false,
      must_setup_mfa: false,
      must_setup_totp: false,
    } as never
    auth.permissions = { payroll: 1 }
    useSessionSecurityStore().state = { session_state: 'active' } as never
  })

  it.each(['double_entry', 'tax_evidence'] as const)(
    'pustí dashboard mezd ve firmě v režimu %s',
    async (accountingMode) => {
      useSupplierStore().setAvailable([{
        id: 7,
        accounting_mode: accountingMode,
        payroll_enabled: true,
      }] as never, 7)

      await expect(authorizationGuard(router.resolve({ name: 'payroll-dashboard' }) as never)).resolves.toBe(true)
    },
  )
})

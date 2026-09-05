import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { useAuthStore } from '@/stores/auth'

const mocks = vi.hoisted(() => ({
  me: vi.fn(),
  domainContext: vi.fn(),
}))

vi.mock('@/api/auth', () => ({
  authApi: {
    me: mocks.me,
    domainContext: mocks.domainContext,
  },
}))

function session() {
  return {
    user: {
      id: 2,
      email: 'ucetni@example.test',
      name: 'Účetní',
      role: { id: 130, name: 'Účetní', type: 'staff' as const, system_key: 'ucetni' },
      is_superadmin: false,
      locale: 'cs' as const,
    },
    csrf_token: 'test-csrf',
    require_totp: false,
    require_mfa: false,
    allowed_mfa_methods: [],
    session_state: 'active' as const,
    server_time: '2026-09-02T12:00:00+02:00',
    idle_expires_at: null,
    lock_after_minutes: 30,
    current_supplier_id: 1,
    suppliers: [],
    permissions: { dashboard: 1, accounting: 2 },
    permission_catalog_version: 'test',
  }
}

describe('obnova oprávnění v auth store', () => {
  beforeEach(() => {
    localStorage.clear()
    setActivePinia(createPinia())
    mocks.me.mockReset()
    mocks.domainContext.mockReset()
    mocks.domainContext.mockResolvedValue({
      mode: 'canonical',
      hostname: 'dev.example.test',
      origin: 'https://dev.example.test',
      locked: false,
      supplier_id: null,
      purpose: null,
    })
  })

  it('při opakované pomalé nebo neúspěšné obnově zachová poslední ověřená práva', async () => {
    const store = useAuthStore()
    mocks.me.mockResolvedValueOnce(session())
    await store.refresh()
    expect(store.canRead('dashboard')).toBe(true)

    let rejectRefresh!: (reason: unknown) => void
    mocks.me.mockReturnValueOnce(new Promise((_resolve, reject) => { rejectRefresh = reject }))
    const pending = store.refresh()
    await Promise.resolve()

    expect(store.permissionsLoading).toBe(true)
    expect(store.canRead('dashboard')).toBe(true)

    rejectRefresh(new Error('offline'))
    await pending
    expect(store.canRead('dashboard')).toBe(true)
    expect(store.canWrite('accounting')).toBe(true)
  })

  it('rozpozná systémovou roli Admin Plus', () => {
    const store = useAuthStore()
    store.user = { ...session().user, role: { ...session().user.role, system_key: 'admin_plus' } }
    expect(store.isAdminPlusRole).toBe(true)

    store.user = { ...session().user, role: { ...session().user.role, system_key: 'admin' } }
    expect(store.isAdminPlusRole).toBe(false)
  })
})

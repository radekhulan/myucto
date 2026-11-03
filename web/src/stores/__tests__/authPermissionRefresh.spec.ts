import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { useAuthStore } from '@/stores/auth'
import { useSupplierStore } from '@/stores/supplier'
import { flushPromises } from '@vue/test-utils'
import { rememberTotpEnrollment, takeTotpEnrollment } from '@/security/totpEnrollment'
import { captureCanonicalDomainLogin, canonicalDomainLoginSessionCsrf } from '@/security/domainLogin'

const mocks = vi.hoisted(() => ({
  me: vi.fn(),
  login: vi.fn(),
  domainContext: vi.fn(),
  logout: vi.fn(),
  sessionStatus: vi.fn(),
}))

vi.mock('@/api/auth', () => ({
  authApi: {
    me: mocks.me,
    login: mocks.login,
    domainContext: mocks.domainContext,
    logout: mocks.logout,
    sessionStatus: mocks.sessionStatus,
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
    sessionStorage.clear()
    rememberTotpEnrollment(null)
    setActivePinia(createPinia())
    mocks.me.mockReset()
    mocks.logout.mockReset()
    mocks.logout.mockResolvedValue(undefined)
    mocks.sessionStatus.mockReset()
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

  it('vlastnictví přihlášení do portálu vezme z loginu, nikoli z následného refresh', async () => {
    captureCanonicalDomainLogin('a'.repeat(43), 'b'.repeat(43))
    mocks.login.mockResolvedValue({ ...session(), csrf_token: 'issued-for-portal' })
    mocks.me.mockImplementation(async () => {
      expect(canonicalDomainLoginSessionCsrf()).toBe('issued-for-portal')
      return { ...session(), csrf_token: 'different-refreshed-session' }
    })
    await useAuthStore().login('synthetic@example.test', 'synthetic-password')
    expect(canonicalDomainLoginSessionCsrf()).toBe('issued-for-portal')
  })

  it('přenese oprávnění pouze z právě dokončeného přihlášení, nikoli z /me', async () => {
    const store = useAuthStore()
    mocks.login.mockResolvedValue({ ...session(), totp_enrollment_token: 'login-grant' })
    mocks.me.mockResolvedValue({ ...session(), totp_enrollment_token: 'must-not-use-me' })
    await store.login('synthetic@example.test', 'synthetic-password')
    expect(takeTotpEnrollment(2)?.token).toBe('login-grant')
    await store.refresh()
    expect(takeTotpEnrollment(2)).toBeNull()
  })

  it('starší odpověď nevrátí odebraná oprávnění ani předchozí firmu', async () => {
    const auth = useAuthStore()
    let resolveOld!: (value: unknown) => void
    let resolveNew!: (value: unknown) => void
    mocks.me.mockReturnValueOnce(new Promise(resolve => { resolveOld = resolve }))
    const oldRefresh = auth.refresh()
    await flushPromises()
    mocks.me.mockReturnValueOnce(new Promise(resolve => { resolveNew = resolve }))
    const newRefresh = auth.refresh()
    await flushPromises()
    resolveNew({ ...session(), permissions: { accounting: 0 }, current_supplier_id: 2, suppliers: [{ id: 2 }] })
    await newRefresh
    expect(auth.canWrite('accounting')).toBe(false)
    expect(useSupplierStore().currentSupplierId).toBe(2)
    resolveOld({ ...session(), suppliers: [{ id: 1 }] })
    await oldRefresh
    expect(auth.canWrite('accounting')).toBe(false)
    expect(useSupplierStore().currentSupplierId).toBe(2)
  })

  it('starší chyba neodhlásí novější relaci ani neukončí její načítání', async () => {
    const auth = useAuthStore()
    let rejectOld!: (reason: unknown) => void
    let resolveNew!: (value: unknown) => void
    mocks.me.mockReturnValueOnce(new Promise((_resolve, reject) => { rejectOld = reject }))
    const oldRefresh = auth.refresh()
    await flushPromises()
    mocks.me.mockReturnValueOnce(new Promise(resolve => { resolveNew = resolve }))
    const newRefresh = auth.refresh()
    await flushPromises()
    rejectOld({ response: { status: 401 } })
    await oldRefresh
    expect(auth.permissionsLoading).toBe(true)
    resolveNew(session())
    await newRefresh
    expect(auth.isAuthenticated).toBe(true)
    expect(auth.permissionsLoading).toBe(false)
  })

  it('starší kontext domény nepřepíše novější zámek firmy ani nespustí me', async () => {
    const auth = useAuthStore()
    let resolveOld!: (value: unknown) => void
    mocks.domainContext.mockReturnValueOnce(new Promise(resolve => { resolveOld = resolve }))
    const oldRefresh = auth.refresh()
    await flushPromises()
    const context = { mode: 'portal', locked: true, supplier_id: 2, hostname: 'portal.example.test' }
    mocks.domainContext.mockResolvedValueOnce(context)
    mocks.me.mockResolvedValueOnce({ ...session(), current_supplier_id: 2, suppliers: [{ id: 2 }] })
    await auth.refresh()
    resolveOld({ ...context, supplier_id: 1 })
    await oldRefresh
    expect(auth.domainContext?.supplier_id).toBe(2)
    expect(useSupplierStore().currentSupplierId).toBe(2)
    expect(mocks.me).toHaveBeenCalledTimes(1)
  })

  it('dokončení odhlášení zneplatní čekající obnovu profilu', async () => {
    const auth = useAuthStore()
    let resolveOld!: (value: unknown) => void
    mocks.me.mockReturnValueOnce(new Promise(resolve => { resolveOld = resolve }))
    const oldRefresh = auth.refresh()
    await flushPromises()
    await auth.logout()
    resolveOld(session())
    await oldRefresh
    expect(auth.isAuthenticated).toBe(false)
    expect(auth.canRead('dashboard')).toBe(false)
    expect(useSupplierStore().currentSupplierId).toBe(0)
  })

  it('s lístkem od poskytovatele pošle jen sso_ticket a druhý faktor, bez e-mailu, hesla a captchy', async () => {
    mocks.login.mockResolvedValue(session())
    mocks.me.mockResolvedValue(session())
    const ticket = 'ab'.repeat(32)
    await useAuthStore().login('ignored@example.test', 'ignored-password', 'captcha-token', '123456', { ssoTicket: ticket })
    const payload = mocks.login.mock.calls.at(-1)![0]
    expect(payload.sso_ticket).toBe(ticket)
    expect(payload.totp).toBe('123456')
    expect(payload).not.toHaveProperty('email')
    expect(payload).not.toHaveProperty('password')
    expect(payload).not.toHaveProperty('cf_turnstile_response')
  })

})

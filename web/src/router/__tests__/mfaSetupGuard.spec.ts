import { describe, it, expect, beforeEach, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import type { RouteLocationNormalized, RouteLocationRaw } from 'vue-router'

// Router si při importu tahá i18n (import.meta.glob nad chunky překladů). Guard
// z něj nepotřebuje nic, takže ho zaslepíme — test má stát na autorizaci, ne na
// načítání překladů.
vi.mock('@/i18n', () => ({
  ensureNamespaces: vi.fn().mockResolvedValue(undefined),
  namespacesForRoute: () => [],
}))

import { authorizationGuard, canonicalInternalUrl, router } from '../index'
import { useAuthStore } from '@/stores/auth'
import { useSessionSecurityStore } from '@/stores/sessionSecurity'
import { useSupplierStore } from '@/stores/supplier'

/**
 * Regrese #5: účet s `must_setup_mfa = true` skončil v nekonečné smyčce
 * home → setup-mfa → home. Guard ho na `/setup-mfa` sice poslal, ale
 * deny-by-default kontrola tu routu neznala jako samoobslužnou a vrátila ho
 * domů — a tam ho MFA gate poslal zase zpátky. Prohlížeč jen zamrzl.
 *
 * Test proto guard NEspouští přes router.push (ta smyčka by se v testu jen
 * zacyklila stejně jako v prohlížeči), ale sám sleduje řetěz přesměrování
 * s tvrdým stropem — smyčka se tak projeví jako čitelný seznam skoků.
 */
function resolveTarget(location: RouteLocationRaw): RouteLocationNormalized {
  // `router.resolve()` samo `redirect` z route recordu nenásleduje — ten aplikuje
  // až navigace. Bez toho by /setup-totp skončil sám na sobě.
  let resolved = router.resolve(location)
  for (let hop = 0; hop < 4; hop++) {
    const redirect = resolved.matched[resolved.matched.length - 1]?.redirect
    if (!redirect) break
    const next = typeof redirect === 'function'
      ? (redirect as unknown as (to: typeof resolved) => RouteLocationRaw)(resolved)
      : redirect
    resolved = router.resolve(next as RouteLocationRaw)
  }
  return resolved as unknown as RouteLocationNormalized
}

async function followRedirects(start: RouteLocationRaw, maxHops = 8): Promise<{ path: string[]; final: string }> {
  let target = resolveTarget(start)
  const path: string[] = [String(target.name)]
  for (let hop = 0; hop < maxHops; hop++) {
    const result = await authorizationGuard(target)
    if (result === true || result === undefined) {
      return { path, final: String(target.name) }
    }
    target = resolveTarget(result as RouteLocationRaw)
    path.push(String(target.name))
  }
  return { path, final: 'SMYČKA' }
}

function signIn(overrides: Record<string, unknown> = {}) {
  const auth = useAuthStore()
  auth.setupStatus = { needs_setup: false } as never
  auth.user = {
    id: 1,
    email: 'mfa@example.invalid',
    name: 'Synthetic User',
    role: { type: 'admin' },
    is_superadmin: false,
    must_setup_mfa: false,
    must_setup_totp: false,
    should_offer_mfa: false,
    ...overrides,
  } as never
  // Bez naplněného stavu by si guard sáhl na /api/auth/session/status.
  useSessionSecurityStore().state = { session_state: 'active' } as never
  return auth
}

describe('router guard vynuceného nastavení MFA', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    sessionStorage.clear()
  })

  it('pustí účet s must_setup_mfa na setup-mfa a nevrátí ho domů', async () => {
    signIn({ must_setup_mfa: true })

    const { path, final } = await followRedirects({ name: 'home' })

    expect(final).toBe('setup-mfa')
    expect(path).toEqual(['home', 'setup-mfa'])
  })

  it.each([false, true])('obnovení dokončeného MFA vrátí portálový tok na přihlášení (cleanup=%s)', async (cleanup) => {
    const auth = signIn()
    auth.domainContext = {
      mode: 'canonical', hostname: 'app.example.test', origin: 'https://app.example.test',
      locked: false, supplier_id: null, purpose: null,
    }
    const requestToken = 'a'.repeat(43)
    const state = 'b'.repeat(43)
    sessionStorage.setItem('myinvoice.domain_login.canonical', JSON.stringify({
      requestToken, state, handoffPath: '/setup-mfa', ownedCsrf: 'portal-created-csrf',
      ...(cleanup ? { redirectUrl: 'https://portal.example.test/domain-login/callback' } : {}),
    }))
    expect(await authorizationGuard(resolveTarget({ name: 'setup-mfa' }))).toEqual({
      name: 'login', query: { domain_login_request: requestToken, state, domain_login_handoff: '/setup-mfa' },
    })
  })

  it('nechá na setup-mfa i účet, který na ni přijde přímo z URL', async () => {
    signIn({ must_setup_mfa: true })

    const { final } = await followRedirects({ name: 'setup-mfa' })

    expect(final).toBe('setup-mfa')
  })

  it('funguje i pro starší příznak must_setup_totp přes redirect /setup-totp', async () => {
    signIn({ must_setup_totp: true })

    const { final } = await followRedirects({ path: '/setup-totp' })

    expect(final).toBe('setup-mfa')
  })

  it('účet s hotovým MFA na setup-mfa nepustí — výjimka z deny-by-default ji neotevírá', async () => {
    signIn({ must_setup_mfa: false })

    const { final } = await followRedirects({ name: 'setup-mfa' })

    expect(final).not.toBe('setup-mfa')
    expect(final).toBe('home')
  })

  it('dobrovolnou nabídku na setup-mfa pustí, i když MFA není povinná', async () => {
    signIn({ must_setup_mfa: false, should_offer_mfa: true })

    const { path, final } = await followRedirects({ name: 'setup-mfa' })

    expect(final).toBe('setup-mfa')
    // Žádný skok: nabídka stránku jen otevírá, nikam nepřesměrovává.
    expect(path).toEqual(['setup-mfa'])
  })

  it('dobrovolná nabídka NIKOHO na setup-mfa neposílá — jinak by z ní bylo vynucení', async () => {
    signIn({ must_setup_mfa: false, should_offer_mfa: true })

    const { path, final } = await followRedirects({ name: 'home' })

    expect(final).toBe('home')
    expect(path).toEqual(['home'])
  })

  it('nabídka nesmí odblokovat setup-mfa tomu, kdo ji odmítl nebo faktor už má', async () => {
    signIn({ must_setup_mfa: false, should_offer_mfa: false })

    const { final } = await followRedirects({ name: 'setup-mfa' })

    expect(final).toBe('home')
  })

  it('deny-by-default drží: routa bez permission a bez výjimky končí zpět na home', async () => {
    signIn()

    const result = await authorizationGuard(resolveTarget({ name: 'admin-users' }))

    expect(result).toEqual({ name: 'home' })
  })

  it('pustí na Dodavatele roli Admin Plus, ale běžnou roli Admin odmítne', async () => {
    signIn({ role: { type: 'staff', system_key: 'admin_plus' } })
    await expect(authorizationGuard(resolveTarget({ name: 'admin-suppliers' }))).resolves.toBe(true)

    signIn({ role: { type: 'staff', system_key: 'admin' } })
    await expect(authorizationGuard(resolveTarget({ name: 'admin-suppliers' }))).resolves.toEqual({ name: 'home' })
  })

  it.each(['admin', 'admin_plus'])(
    'pustí systémovou roli %s do firemního ceníku',
    async (systemKey) => {
      const auth = signIn({ role: { type: 'staff', system_key: systemKey } })
      auth.permissions = { price_list: 2 }
      useSupplierStore().setAvailable([{
        id: 7,
        accounting_mode: 'double_entry',
        stock_enabled: false,
      }] as never, 7)

      await expect(authorizationGuard(resolveTarget({ name: 'admin-price-list' }))).resolves.toBe(true)
      await expect(authorizationGuard(resolveTarget({ name: 'admin-price-list-new' }))).resolves.toBe(true)
    },
  )

  it.each([
    ['accountant', 'staff'],
    ['readonly', 'staff'],
    ['client', 'client'],
  ])(
    'nepustí do firemního ceníku jinou systémovou roli %s',
    async (systemKey, roleType) => {
      const auth = signIn({ role: { type: roleType, system_key: systemKey } })
      auth.permissions = { 'settings.company.write': 0 }
      useSupplierStore().setAvailable([{
        id: 7,
        accounting_mode: 'double_entry',
        stock_enabled: false,
      }] as never, 7)

      await expect(authorizationGuard(resolveTarget({ name: 'admin-price-list' }))).resolves.not.toBe(true)
    },
  )

  it.each(['client', 'staff'])('šablony účtování jsou pro %s dostupné podle čtecího práva Ceníku i se skladem', async roleType => {
    const auth = signIn({ role: { type: roleType } })
    for (const stockEnabled of [false, true]) {
      useSupplierStore().setAvailable([{ id: 7, accounting_mode: 'double_entry', stock_enabled: stockEnabled }] as never, 7)
      for (const level of [0, 1, 2]) {
        auth.permissions = { price_list: level }
        const result = await authorizationGuard(resolveTarget({ name: 'admin-work-report-billing-templates' }))
        expect(result === true).toBe(level > 0)
      }
    }
  })

  it.each(['client', 'staff'])('opens operational settings for independent %s grants and denies read/revocation', async (roleType) => {
    const auth = signIn({ role: { type: roleType } })
    useSupplierStore().setAvailable([{ id: 7, accounting_mode: 'double_entry', stock_enabled: false }] as never, 7)
    for (const permission of ['settings.email_profiles', 'settings.email_templates', 'settings.branding', 'settings.payment_qr']) {
      auth.permissions = { [permission]: 2 }
      await expect(authorizationGuard(resolveTarget({ name: 'portal-company-settings' }))).resolves.toBe(true)
      auth.permissions = { [permission]: 1, 'settings.company': 2 }
      await expect(authorizationGuard(resolveTarget({ name: 'portal-company-settings' }))).resolves.not.toBe(true)
    }
    auth.permissions = { 'settings.company': 2 }
    await expect(authorizationGuard(resolveTarget({ name: 'portal-company-settings' }))).resolves.not.toBe(true)
  })

  it('oddlužení vyžaduje zároveň oprávnění k exekucím', async () => {
    const auth = signIn()
    auth.permissions = {
      'payroll.insolvency': 1,
      'payroll.enforcement': 0,
    }

    const result = await authorizationGuard(resolveTarget({ name: 'payroll-insolvency' }))

    expect(result).toEqual({ name: 'home' })
  })
})

describe('oddělení klientské a interní domény', () => {
  const customContext = {
    mode: 'custom',
    hostname: 'portal.example.test',
    origin: 'https://portal.example.test',
    locked: true,
    supplier_id: 7,
    purpose: 'portal',
    canonical_base_url: 'https://ucto.example.test/app-path',
  } as const

  it('nechá na vlastní doméně klientské obrazovky bez WebAuthn ceremony', () => {
    for (const location of [
      { name: 'portal-document-requests', query: { page: '2' } },
      { name: 'client-edit', params: { id: '7' } },
      { name: 'invoice-new' },
      { name: 'purchase-invoice-detail', params: { id: '7' } },
      { name: 'recurring-edit', params: { id: '7' } },
      { name: 'profile-password', query: { tab: 'totp' } },
    ]) {
      const target = resolveTarget(location)
      expect(canonicalInternalUrl(target, customContext), String(location.name)).toBeNull()
    }
  })

  it('pošle WebAuthn obrazovku přes domain-login handoff a zachová bezpečný klientský návrat', async () => {
    const auth = signIn()
    auth.domainContext = customContext as never
    const target = resolveTarget({ name: 'profile-password', query: { tab: 'passkeys' } })
    const from = resolveTarget({ name: 'invoice-detail', params: { id: '7' } })

    await expect(authorizationGuard(target, from)).resolves.toEqual({
      name: 'login',
      query: {
        return_to: '/invoices/7',
        domain_handoff: '/profile/password?tab=passkeys',
      },
    })
  })

  it('přímý vstup na vynucené MFA vrací po canonical nastavení na portál', async () => {
    const auth = signIn({ must_setup_mfa: true })
    auth.domainContext = customContext as never

    await expect(authorizationGuard(resolveTarget({ name: 'setup-mfa' }))).resolves.toEqual({
      name: 'login',
      query: {
        return_to: '/portal',
        domain_handoff: '/setup-mfa',
      },
    })
  })

  it('přesměruje neklientské obrazovky na canonical origin a zachová cestu', () => {
    for (const [location, expected] of [
      [{ name: 'admin-settings', query: { tab: 'company' }, hash: '#domains' }, 'https://ucto.example.test/admin/settings?tab=company#domains'],
      [{ name: 'admin-electronic-signatures' }, 'https://ucto.example.test/admin/electronic-signatures'],
      [{ name: 'purchase-invoices-payment-orders' }, 'https://ucto.example.test/purchase-invoices/payment-orders'],
      [{ name: 'profile-api-tokens' }, 'https://ucto.example.test/profile/api-tokens'],
    ] as const) {
      expect(canonicalInternalUrl(resolveTarget(location), customContext)).toBe(expected)
    }
  })
})

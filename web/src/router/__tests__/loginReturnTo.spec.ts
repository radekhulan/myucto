import { describe, it, expect, afterEach, beforeEach, vi } from 'vitest'
import { AxiosError } from 'axios'
import { createPinia, setActivePinia } from 'pinia'
import { START_LOCATION, type RouteLocationNormalized, type RouteLocationRaw } from 'vue-router'

// Router si při importu tahá i18n (import.meta.glob nad chunky překladů). Guard
// z něj nepotřebuje nic, takže ho zaslepíme.
vi.mock('@/i18n', () => ({
  ensureNamespaces: vi.fn().mockResolvedValue(undefined),
  namespacesForRoute: () => [],
}))

import { authorizationGuard, router } from '../index'
import { api } from '@/api/client'
import { useAuthStore } from '@/stores/auth'

/**
 * Kdo dostane odkaz do aplikace a nemá živou relaci, má se po přihlášení
 * dostat právě tam, ne na přehled. Bez toho si hledanou stránku musí najít
 * znovu — a u odkazu na konkrétní doklad nebo mzdový běh se tam musí
 * proklikat ručně.
 *
 * Guard adresu jen předá; kontrolu, že jde o vlastní cestu, dělá přihlašovací
 * obrazovka (viz `requestedReturnPath`), protože ta hodnota může přijít i
 * z ručně upravené adresy.
 */
async function bounce(target: RouteLocationRaw) {
  const resolved = router.resolve(target) as unknown as RouteLocationNormalized

  return await authorizationGuard(resolved)
}

describe('návrat na původní adresu po přihlášení', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    const auth = useAuthStore()
    auth.setupStatus = { needs_setup: false } as never
    auth.user = null
    // Bez živé relace: `refresh()` na síti nic nezmění, identita zůstane prázdná.
    auth.refresh = vi.fn().mockResolvedValue(false) as never
  })

  it('nese adresu chráněné stránky do přihlášení', async () => {
    const result = await bounce({ path: '/payroll/runs' })

    expect(result).toMatchObject({
      name: 'login',
      query: { return_to: '/payroll/runs' },
    })
  })

  it('zachová i parametry v adrese', async () => {
    const result = await bounce({ path: '/payroll/people', query: { person: '1' } })

    expect((result as { query: { return_to: string } }).query.return_to)
      .toBe('/payroll/people?person=1')
  })

  it('na veřejnou stránku nesahá', async () => {
    const result = await bounce({ name: 'login' })

    expect(result === true || result === undefined).toBe(true)
  })
})

describe('první načtení bez přihlášení', () => {
  const originalAdapter = api.defaults.adapter
  const originalLocation = window.location

  afterEach(() => {
    api.defaults.adapter = originalAdapter
    Object.defineProperty(window, 'location', { value: originalLocation, configurable: true, writable: true })
  })

  it('přesměruje přes router v témže dokumentu a načte kontext domény jen jednou', async () => {
    setActivePinia(createPinia())
    const location = { pathname: '/', search: '', hash: '', href: 'http://localhost:3000/' }
    Object.defineProperty(window, 'location', { value: location, configurable: true, writable: true })
    const requests: Array<string | undefined> = []
    api.defaults.adapter = async config => {
      requests.push(config.url)
      const data = config.url === '/auth/setup-status'
        ? { needs_setup: false }
        : { mode: 'canonical', locked: false }
      if (config.url === '/auth/me') {
        throw new AxiosError('unauthorized', 'ERR_BAD_REQUEST', config, null, {
          config, data: { error: { code: 'unauthenticated' } }, headers: {},
          status: 401, statusText: 'Unauthorized',
        })
      }
      return { config, data, headers: {}, status: 200, statusText: 'OK' }
    }

    const home = router.resolve('/') as RouteLocationNormalized
    const redirect = await authorizationGuard(home, START_LOCATION)
    expect(redirect).toEqual({ name: 'login', query: { return_to: '/' } })
    expect(location.href).toBe('http://localhost:3000/')
    expect(await authorizationGuard(router.resolve(redirect as RouteLocationRaw) as RouteLocationNormalized, START_LOCATION))
      .toBe(true)
    expect(requests).toEqual(['/auth/setup-status', '/auth/domain-context', '/auth/me'])
  })
})

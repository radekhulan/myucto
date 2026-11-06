import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { InternalAxiosRequestConfig } from 'axios'
import { createPinia, setActivePinia } from 'pinia'
import { api, setCsrfToken, setDomainSupplierLock } from '../client'
import { authApi } from '../auth'
import { captureInitialDomainLogin, domainLoginRequestContext, setDomainLoginCanonicalOrigin } from '@/security/domainLoginContext'
import { captureCanonicalDomainLogin } from '@/security/domainLogin'
import { useSupplierStore } from '@/stores/supplier'
import { nextTick } from 'vue'

const originalAdapter = api.defaults.adapter
const sent: InternalAxiosRequestConfig[] = []
const request = 'r'.repeat(43)
const state = 's'.repeat(43)
beforeEach(() => {
  sent.length = 0
  sessionStorage.clear()
  localStorage.clear()
  setDomainLoginCanonicalOrigin(false)
  setCsrfToken(null)
  setDomainSupplierLock(null)
  setActivePinia(createPinia())
  api.defaults.adapter = async config => {
    sent.push(config)
    return { config, data: {}, status: 200, statusText: 'OK', headers: {} }
  }
})
afterEach(() => {
  api.defaults.adapter = originalAdapter
  setDomainLoginCanonicalOrigin(false)
})

function incomingLogin() {
  captureInitialDomainLogin({ pathname: '/login', search: `?domain_login_request=${request}&state=${state}` })
}

describe('izolované přihlášení klientské domény', () => {
  it('zachytí tok před bootstrapem, ale hlavičky zapne až potvrzený canonical origin', async () => {
    incomingLogin()
    await authApi.domainContext()
    expect(domainLoginRequestContext()).toBeNull()
    expect(sent[0]?.headers.has('X-Domain-Login-Request')).toBe(false)
    setDomainLoginCanonicalOrigin(true)
    await authApi.setupStatus()
    await authApi.domainContext()
    expect(sent.slice(0, 3).every(config => !config.headers.has('X-Domain-Login-Request'))).toBe(true)
    await authApi.me()
    expect(sent[3]?.headers.get('X-Domain-Login-Request')).toBe(request)
    expect(sent[3]?.headers.get('X-Domain-Login-State')).toBe(state)
  })

  it('vlastní doména ani běžná canonical záložka kontext nepřevezme', async () => {
    incomingLogin()
    await authApi.me()
    setDomainLoginCanonicalOrigin(true)
    captureInitialDomainLogin({ pathname: '/login', search: '' })
    await authApi.me()
    expect(sent.every(config => !config.headers.has('X-Domain-Login-Request'))).toBe(true)
  })

  it('login, MFA a úklid vždy používají tentýž izolovaný kontext bez hlavní firmy', async () => {
    incomingLogin()
    setDomainLoginCanonicalOrigin(true)
    localStorage.setItem('myinvoice.current_supplier_id', '123')
    await authApi.login({ email: 'synthetic@example.test', password: 'synthetic-password' })
    await authApi.passkeyLoginOptions('synthetic@example.test')
    await authApi.passkeyLoginVerify('flow', {})
    await authApi.totpEnable('123456', 'owned')
    await authApi.logout('owned')
    expect(sent).toHaveLength(5)
    for (const config of sent) {
      expect(config.headers.get('X-Domain-Login-Request')).toBe(request)
      expect(config.headers.get('X-Domain-Login-State')).toBe(state)
      expect(config.headers.has('X-Supplier-Id')).toBe(false)
    }
    expect(localStorage.getItem('myinvoice.current_supplier_id')).toBe('123')
  })

  it('při reloadu zachová vlastnictví pouze stejného toku', () => {
    incomingLogin()
    const stored = JSON.parse(sessionStorage.getItem('myinvoice.domain_login.canonical')!)
    sessionStorage.setItem('myinvoice.domain_login.canonical', JSON.stringify({ ...stored, ownedCsrf: 'owned' }))
    incomingLogin()
    expect(JSON.parse(sessionStorage.getItem('myinvoice.domain_login.canonical')!).ownedCsrf).toBe('owned')
    captureInitialDomainLogin({ pathname: '/login', search: `?domain_login_request=${'n'.repeat(43)}&state=${state}` })
    expect(JSON.parse(sessionStorage.getItem('myinvoice.domain_login.canonical')!).ownedCsrf).toBeUndefined()
  })

  it('supplier store nečte ani nemění hlavní uloženou firmu', async () => {
    incomingLogin()
    localStorage.setItem('myinvoice.current_supplier_id', '123')
    const supplier = useSupplierStore()
    expect(supplier.currentSupplierId).toBe(0)
    setDomainLoginCanonicalOrigin(true)
    supplier.setAvailable([], 0)
    supplier.setAvailable([{ id: 42 } as never], 42)
    supplier.setSupplier(43)
    setDomainSupplierLock(42)
    await nextTick()
    expect(localStorage.getItem('myinvoice.current_supplier_id')).toBe('123')
  })

  it('401 na správě MFA zachová izolovaný tok a původní handoff při návratu na login', async () => {
    captureCanonicalDomainLogin(request, state, '/profile/password?tab=totp')
    setDomainLoginCanonicalOrigin(true)
    vi.spyOn(window.location, 'pathname', 'get').mockReturnValue('/profile/password')
    const navigate = vi.spyOn(window.location, 'href', 'set').mockImplementation(() => undefined)
    const failure = { response: { status: 401, data: { error: { code: 'unauthenticated' } } } }
    api.defaults.adapter = async () => { throw failure }
    await expect(authApi.me()).rejects.toBe(failure)
    const destination = new URL(navigate.mock.calls[0]![0], window.location.origin)
    expect(destination.pathname).toBe('/login')
    expect(destination.searchParams.get('domain_login_request')).toBe(request)
    expect(destination.searchParams.get('state')).toBe(state)
    expect(destination.searchParams.get('domain_login_handoff')).toBe('/profile/password?tab=totp')
    vi.restoreAllMocks()
  })

  it('po přechodu na jiný požadavek neposílá dřívější kontext', async () => {
    incomingLogin()
    setDomainLoginCanonicalOrigin(true)
    await authApi.me()
    captureCanonicalDomainLogin('n'.repeat(43), state)
    await authApi.me()
    expect(sent[1]?.headers.get('X-Domain-Login-Request')).toBe('n'.repeat(43))
  })
})

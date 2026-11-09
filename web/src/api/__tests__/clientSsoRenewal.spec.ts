import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { AxiosError, type AxiosAdapter } from 'axios'
import { api } from '../client'
import { resetSsoRenewal, setSsoRenewalPolicy } from '@/security/ssoRenewal'

const unauthorized = (code: string): AxiosAdapter => async (config) => {
  throw new AxiosError('unauthorized', 'ERR_BAD_REQUEST', config, null, {
    data: { error: { code, message: 'x' } },
    status: 401,
    statusText: 'Unauthorized',
    headers: {},
    config,
  })
}

describe('401 po vypršení sezení z Entra ID', () => {
  const originalLocation = window.location
  let location: { pathname: string; search: string; hash: string; href: string }

  beforeEach(() => {
    location = { pathname: '/invoices', search: '?status=unpaid', hash: '', href: '' }
    Object.defineProperty(window, 'location', { value: location, configurable: true, writable: true })
  })
  afterEach(() => {
    Object.defineProperty(window, 'location', { value: originalLocation, configurable: true, writable: true })
    resetSsoRenewal()
  })

  it('s vynuceným Entra se tiše přihlásí znovu a vrátí na stejnou stránku', async () => {
    setSsoRenewalPolicy({ sso_required_provider: 'entra', sso_providers: [{ id: 'entra', strong: true }] })
    await expect(api.get('/invoices', { adapter: unauthorized('unauthenticated') })).rejects.toBeInstanceOf(AxiosError)
    expect(location.href).toBe('/api/auth/oidc/entra/login?return_to=%2Finvoices%3Fstatus%3Dunpaid&silent=1')

    // Další 401 během odchodu k poskytovateli adresu nepřepíše.
    location.href = 'beze-změny'
    await expect(api.get('/clients', { adapter: unauthorized('unauthenticated') })).rejects.toBeInstanceOf(AxiosError)
    expect(location.href).toBe('beze-změny')
  })

  it('bez Entra beze změny na přihlašovací formulář', async () => {
    setSsoRenewalPolicy({ sso_providers: [] })
    await expect(api.get('/invoices', { adapter: unauthorized('unauthenticated') })).rejects.toBeInstanceOf(AxiosError)
    expect(location.href).toBe('/login?return_to=%2Finvoices%3Fstatus%3Dunpaid')
  })

  it('relace ukončená přihlášením jinde se tiše neobnovuje', async () => {
    setSsoRenewalPolicy({ sso_required_provider: 'entra', sso_providers: [{ id: 'entra', strong: true }] })
    await expect(api.get('/invoices', { adapter: unauthorized('session_superseded') })).rejects.toBeInstanceOf(AxiosError)
    expect(location.href).toBe('/login?return_to=%2Finvoices%3Fstatus%3Dunpaid')
  })
})

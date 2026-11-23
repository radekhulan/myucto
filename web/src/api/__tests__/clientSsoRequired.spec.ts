import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { AxiosError, type AxiosAdapter } from 'axios'
import { api } from '../client'

const ssoRequired: AxiosAdapter = async (config) => {
  throw new AxiosError('forbidden', 'ERR_BAD_REQUEST', config, null, {
    data: { error: { code: 'sso_required', message: 'x', provider: 'entra' } },
    status: 403,
    statusText: 'Forbidden',
    headers: {},
    config,
  })
}

describe('403 sso_required', () => {
  const originalLocation = window.location
  let location: { pathname: string; search: string; hash: string; href: string }

  beforeEach(() => {
    location = { pathname: '/invoices', search: '?status=unpaid', hash: '', href: '' }
    Object.defineProperty(window, 'location', { value: location, configurable: true, writable: true })
  })
  afterEach(() => {
    Object.defineProperty(window, 'location', { value: originalLocation, configurable: true, writable: true })
  })

  it('pošle na přihlášení přes organizaci a nese návratovou adresu', async () => {
    await expect(api.get('/invoices', { adapter: ssoRequired })).rejects.toBeInstanceOf(AxiosError)
    expect(location.href).toBe('/login?sso_required=entra&return_to=%2Finvoices%3Fstatus%3Dunpaid')
  })

  it('odmítnuté heslo nechá na přihlašovacím formuláři', async () => {
    await expect(api.post('/auth/login', {}, { adapter: ssoRequired })).rejects.toBeInstanceOf(AxiosError)
    await expect(api.post('/auth/webauthn/login/verify', {}, { adapter: ssoRequired })).rejects.toBeInstanceOf(AxiosError)
    expect(location.href).toBe('')
  })

  it('na přihlašovací stránce znovu nepřesměruje', async () => {
    location.pathname = '/login'
    await expect(api.get('/auth/me', { adapter: ssoRequired })).rejects.toBeInstanceOf(AxiosError)
    expect(location.href).toBe('')
  })
})

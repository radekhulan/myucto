import { afterEach, describe, expect, it } from 'vitest'
import {
  beginSsoRenewal,
  forgetSsoSession,
  rememberSsoSession,
  resetSsoRenewal,
  setSsoRenewalPolicy,
  SSO_RENEWAL_PENDING_MS,
  SSO_RENEWAL_RETRY_MS,
  ssoRenewalDue,
  ssoRenewalPending,
  ssoRenewalUrl,
} from '../ssoRenewal'

const entraStrong = { sso_providers: [{ id: 'entra', strong: true }, { id: 'google', strong: false }] }

describe('tiché obnovení přihlášení přes Entra ID', () => {
  afterEach(() => resetSsoRenewal())

  it('bez Entra se nic nemění', () => {
    setSsoRenewalPolicy({ sso_providers: [] })
    expect(beginSsoRenewal('/invoices')).toEqual({ action: 'login' })
  })

  it('vynucené Entra obnoví tiše se stejnou cestou', () => {
    setSsoRenewalPolicy({ ...entraStrong, sso_required_provider: 'entra' })
    expect(beginSsoRenewal('/invoices?status=unpaid', 1_000_000)).toEqual({
      action: 'renew',
      url: '/api/auth/oidc/entra/login?return_to=%2Finvoices%3Fstatus%3Dunpaid&silent=1',
    })
  })

  it('Entra jen jako možnost nepřesměruje, ani po posledním přihlášení přes Entra', () => {
    setSsoRenewalPolicy(entraStrong)
    rememberSsoSession({ provider: 'google', expires_at: '2026-11-09T20:00:00Z' })
    expect(beginSsoRenewal('/')).toEqual({ action: 'login' })
    rememberSsoSession({ provider: 'entra', expires_at: '2026-11-09T20:00:00Z' })
    expect(beginSsoRenewal('/')).toEqual({ action: 'login' })
  })

  it('sezení z hesla a ruční odhlášení tiché obnovení vypnou', () => {
    setSsoRenewalPolicy(entraStrong)
    rememberSsoSession({ provider: 'entra', expires_at: '2026-11-09T20:00:00Z' })
    rememberSsoSession(null)
    expect(beginSsoRenewal('/').action).toBe('login')

    setSsoRenewalPolicy({ ...entraStrong, sso_required_provider: 'entra' })
    forgetSsoSession()
    expect(beginSsoRenewal('/').action).toBe('login')
  })

  it('smyčku zastaví: během odchodu čeká, po neúspěchu běžné přihlášení, později znovu tiše', () => {
    setSsoRenewalPolicy({ ...entraStrong, sso_required_provider: 'entra' })
    const t = 5_000_000
    expect(beginSsoRenewal('/a', t).action).toBe('renew')
    expect(ssoRenewalPending(t + 1000)).toBe(true)
    expect(beginSsoRenewal('/a', t + 1000)).toEqual({ action: 'wait' })
    expect(beginSsoRenewal('/a', t + SSO_RENEWAL_PENDING_MS + 1)).toEqual({ action: 'login' })
    expect(ssoRenewalPending(t + SSO_RENEWAL_PENDING_MS + 1)).toBe(false)
    expect(beginSsoRenewal('/a', t + SSO_RENEWAL_RETRY_MS + 1).action).toBe('renew')
  })

  it('návratová cesta mimo aplikaci se nahradí kořenem', () => {
    expect(ssoRenewalUrl('entra', 'https://evil.example/')).toBe('/api/auth/oidc/entra/login?return_to=%2F&silent=1')
    expect(ssoRenewalUrl('entra', '//evil.example')).toBe('/api/auth/oidc/entra/login?return_to=%2F&silent=1')
  })

  it('preventivně obnoví deset minut před vypršením podle času serveru', () => {
    setSsoRenewalPolicy({ ...entraStrong, sso_required_provider: 'entra' })
    const now = 10_000_000
    // Hodiny prohlížeče jdou o hodinu napřed; rozhoduje zbývající čas podle serveru.
    rememberSsoSession(
      { provider: 'entra', expires_at: '2026-11-09T12:15:00Z' },
      '2026-11-09T12:00:00Z',
      now,
    )
    expect(ssoRenewalDue(now)).toBe(false)
    expect(ssoRenewalDue(now + 6 * 60 * 1000)).toBe(true)
  })

  it('bez vynucení se preventivně neobnovuje', () => {
    setSsoRenewalPolicy(entraStrong)
    const now = 10_000_000
    rememberSsoSession(
      { provider: 'entra', expires_at: '2026-11-09T12:15:00Z' },
      '2026-11-09T12:00:00Z',
      now,
    )
    expect(ssoRenewalDue(now + 6 * 60 * 1000)).toBe(false)
  })
})

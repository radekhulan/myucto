import { beforeEach, describe, expect, it, vi } from 'vitest'
import { authApi } from '@/api/auth'
import { advanceCanonicalDomainLoginSession, authorizePendingDomainLogin, cancelPendingDomainLogin, canonicalDomainLoginSessionCsrf, captureCanonicalDomainLogin, confirmPendingDomainLogin, hasPendingCanonicalDomainLogin, hasPendingCanonicalDomainLoginCleanup, isDomainLoginConfirmationRequired, needsCanonicalDomainLoginConfirmation, previewPendingDomainLogin, recordCanonicalDomainLoginSession, pendingCanonicalDomainLoginQuery } from '../domainLogin'

vi.mock('@/api/auth', () => ({ authApi: { domainLoginAuthorize: vi.fn(), domainLoginPreview: vi.fn(), logout: vi.fn(), sessionStatus: vi.fn() } }))
const request = 'r'.repeat(43)
const state = 's'.repeat(43)
const redirect = 'https://portal.example.test/domain-login/callback?code=synthetic'
let replace: ReturnType<typeof vi.spyOn>
beforeEach(() => {
  sessionStorage.clear()
  vi.restoreAllMocks()
  vi.mocked(authApi.domainLoginAuthorize).mockReset().mockResolvedValue({ redirect_url: redirect })
  vi.mocked(authApi.logout).mockReset().mockResolvedValue(undefined)
  vi.mocked(authApi.sessionStatus).mockReset().mockRejectedValue(new Error('unconfirmed status'))
  replace = vi.spyOn(window.location, 'replace').mockImplementation(() => undefined)
  captureCanonicalDomainLogin(request, state)
})

describe('portal-only canonical session ownership', () => {
  it.each([false, true])('requires fresh authentication even with confirmation %s', async confirmed => {
    if (confirmed) confirmPendingDomainLogin()
    await expect(authorizePendingDomainLogin()).rejects.toMatchObject({
      response: { status: 401, data: { error: { code: 'fresh_authentication_required' } } },
    })
    expect(authApi.domainLoginAuthorize).not.toHaveBeenCalled()
    expect(authApi.logout).not.toHaveBeenCalled()
    expect(replace).not.toHaveBeenCalled()
    expect(hasPendingCanonicalDomainLogin()).toBe(true)
  })
  it('does not carry a confirmation over to a different request', () => {
    confirmPendingDomainLogin()
    captureCanonicalDomainLogin(request, state)
    expect(needsCanonicalDomainLoginConfirmation()).toBe(false)
    captureCanonicalDomainLogin('n'.repeat(43), state)
    expect(needsCanonicalDomainLoginConfirmation()).toBe(true)
  })
  it('a login within the flow needs no confirmation', () => {
    recordCanonicalDomainLoginSession('owned')
    expect(needsCanonicalDomainLoginConfirmation()).toBe(false)
  })
  it('keeps the flow and its owned session when the server asks for confirmation', async () => {
    const failure = { response: { status: 409, data: { error: {
      code: 'domain_login_confirmation_required', message: 'Potvrďte.', target_hostname: 'portal.example.test', supplier_name: 'Firma',
    } } } }
    recordCanonicalDomainLoginSession('owned')
    expect(isDomainLoginConfirmationRequired(failure)).toBe(true)
    vi.mocked(authApi.domainLoginAuthorize).mockRejectedValueOnce(failure)
    await expect(authorizePendingDomainLogin()).rejects.toEqual(failure)
    expect(authApi.logout).not.toHaveBeenCalled()
    expect(hasPendingCanonicalDomainLogin()).toBe(true)
    expect(canonicalDomainLoginSessionCsrf()).toBe('owned')
    confirmPendingDomainLogin()
    await authorizePendingDomainLogin()
    expect(authApi.domainLoginAuthorize).toHaveBeenLastCalledWith(request, state, 'owned', true)
    expect(authApi.logout).toHaveBeenCalledWith('owned')
  })
  it('previews the pending target and drops a request the user cannot confirm', async () => {
    vi.mocked(authApi.domainLoginPreview).mockReset()
      .mockResolvedValueOnce({ target_hostname: 'portal.example.test', supplier_name: 'Firma' })
    expect(await previewPendingDomainLogin()).toEqual({ target_hostname: 'portal.example.test', supplier_name: 'Firma' })
    expect(authApi.domainLoginPreview).toHaveBeenCalledWith(request, state, undefined)
    expect(hasPendingCanonicalDomainLogin()).toBe(true)

    const forbidden = { response: { status: 403, data: { error: { code: 'forbidden_supplier', message: 'Ne.' } } } }
    vi.mocked(authApi.domainLoginPreview).mockRejectedValueOnce(forbidden)
    await expect(previewPendingDomainLogin()).rejects.toEqual(forbidden)
    expect(hasPendingCanonicalDomainLogin()).toBe(false)
    expect(authApi.domainLoginAuthorize).not.toHaveBeenCalled()
  })
  it('authorizes, revokes only this flow session, then redirects', async () => {
    recordCanonicalDomainLoginSession('owned')
    vi.mocked(authApi.logout).mockImplementation(async csrf => {
      expect(csrf).toBe('owned')
      expect(authApi.domainLoginAuthorize).toHaveBeenCalledWith(request, state, 'owned', false)
      expect(hasPendingCanonicalDomainLoginCleanup()).toBe(true)
      expect(replace).not.toHaveBeenCalled()
    })
    await authorizePendingDomainLogin()
    expect(authApi.logout).toHaveBeenCalledOnce()
    expect(replace).toHaveBeenCalledWith(redirect)
  })
  it('preserves ownership on same-query recapture', async () => {
    recordCanonicalDomainLoginSession('owned')
    captureCanonicalDomainLogin(request, state)
    expect(canonicalDomainLoginSessionCsrf()).toBe('owned')
    await authorizePendingDomainLogin()
    expect(authApi.logout).toHaveBeenCalledWith('owned')
  })
  it('does not inherit stale ownership into a different flow or standalone login', () => {
    recordCanonicalDomainLoginSession('owned')
    captureCanonicalDomainLogin('n'.repeat(43), state)
    expect(canonicalDomainLoginSessionCsrf()).toBeNull()
    recordCanonicalDomainLoginSession('new')
    captureCanonicalDomainLogin(undefined, undefined)
    expect(hasPendingCanonicalDomainLogin()).toBe(false)
    recordCanonicalDomainLoginSession('standalone')
    expect(canonicalDomainLoginSessionCsrf()).toBeNull()
  })
  it('requires a new session even after restarting a previously owned request', async () => {
    recordCanonicalDomainLoginSession('owned')
    vi.mocked(authApi.domainLoginAuthorize).mockRejectedValueOnce(new Error('offline'))
    await expect(authorizePendingDomainLogin()).rejects.toThrow('offline')
    captureCanonicalDomainLogin('n'.repeat(43), state)
    expect(canonicalDomainLoginSessionCsrf()).toBeNull()
    recordCanonicalDomainLoginSession('new')
    await authorizePendingDomainLogin()
    expect(authApi.logout).toHaveBeenCalledWith('new')
  })
  it('does not clean up an earlier session after starting a different request', async () => {
    recordCanonicalDomainLoginSession('owned')
    captureCanonicalDomainLogin('n'.repeat(43), state)
    expect(canonicalDomainLoginSessionCsrf()).toBeNull()
    confirmPendingDomainLogin()
    await expect(authorizePendingDomainLogin()).rejects.toMatchObject({ response: { status: 401 } })
    expect(authApi.logout).not.toHaveBeenCalled()
  })
  it('never acquires generic refresh ownership and returns safe continuation query', () => {
    expect(canonicalDomainLoginSessionCsrf()).toBeNull()
    captureCanonicalDomainLogin(request, state, '/profile/password?tab=passkeys')
    expect(pendingCanonicalDomainLoginQuery()).toEqual({
      domain_login_request: request, state, domain_login_handoff: '/profile/password?tab=passkeys',
    })
    captureCanonicalDomainLogin(undefined, undefined)
    expect(pendingCanonicalDomainLoginQuery()).toBeNull()
  })
  it('advances only a known owned rotation', async () => {
    advanceCanonicalDomainLoginSession('unowned', 'new')
    expect(canonicalDomainLoginSessionCsrf()).toBeNull()
    recordCanonicalDomainLoginSession('owned')
    advanceCanonicalDomainLoginSession('unrelated', 'foreign')
    expect(canonicalDomainLoginSessionCsrf()).toBe('owned')
    advanceCanonicalDomainLoginSession('owned', 'rotated')
    await authorizePendingDomainLogin()
    expect(authApi.domainLoginAuthorize).toHaveBeenCalledWith(request, state, 'rotated', false)
    expect(authApi.logout).toHaveBeenCalledWith('rotated')
  })
  it.each([
    new Error('network unavailable'),
    { response: { status: 403, data: { error: { code: 'csrf_failed' } } } },
    { response: { status: 401, data: { error: { code: 'unauthenticated' } } } },
    { response: { status: 500 } },
  ])('retains issued code and ownership after failed cleanup: %j', async failure => {
    recordCanonicalDomainLoginSession('owned')
    vi.mocked(authApi.logout).mockRejectedValueOnce(failure)
    await expect(authorizePendingDomainLogin()).rejects.toEqual(failure)
    expect(replace).not.toHaveBeenCalled()
    expect(hasPendingCanonicalDomainLoginCleanup()).toBe(true)
    captureCanonicalDomainLogin(request, state)
    expect(canonicalDomainLoginSessionCsrf()).toBe('owned')
    await authorizePendingDomainLogin()
    expect(authApi.domainLoginAuthorize).toHaveBeenCalledOnce()
    expect(authApi.logout).toHaveBeenNthCalledWith(2, 'owned')
    expect(replace).toHaveBeenCalledWith(redirect)
  })
  it.each([
    new Error('offline'), { response: { status: 500 } },
    { response: { status: 403, data: { error: { code: 'csrf_failed' } } } },
    { response: { status: 403, data: { error: { code: 'mfa_setup_required' } } } },
  ])('retains state on retryable authorize failure: %j', async failure => {
    recordCanonicalDomainLoginSession('owned')
    vi.mocked(authApi.domainLoginAuthorize).mockRejectedValueOnce(failure)
    await expect(authorizePendingDomainLogin()).rejects.toEqual(failure)
    expect(hasPendingCanonicalDomainLogin()).toBe(true)
    expect(hasPendingCanonicalDomainLoginCleanup()).toBe(false)
    expect(canonicalDomainLoginSessionCsrf()).toBe('owned')
    expect(authApi.logout).not.toHaveBeenCalled()
    expect(replace).not.toHaveBeenCalled()
    await authorizePendingDomainLogin()
    expect(authApi.logout).toHaveBeenCalledWith('owned')
  })
  it.each(['invalid_domain_login_request', 'canonical_origin_required', 'forbidden_supplier'])(
    'cleans an owned login on terminal authorization error %s before clearing the flow', async code => {
      const failure = { response: { status: 400, data: { error: { code, message: 'Restart sign-in from the company domain.' } } } }
      recordCanonicalDomainLoginSession('owned')
      vi.mocked(authApi.domainLoginAuthorize).mockRejectedValueOnce(failure)
      await expect(authorizePendingDomainLogin()).rejects.toMatchObject(failure)
      expect(authApi.logout).toHaveBeenCalledWith('owned')
      expect(hasPendingCanonicalDomainLogin()).toBe(false)
      expect(replace).not.toHaveBeenCalled()
    },
  )
  it('retries failed terminal-error cleanup without authorizing again after recapture', async () => {
    const failure = { response: { status: 400, data: { error: { code: 'invalid_domain_login_request', message: 'Expired request; restart sign-in.' } } } }
    recordCanonicalDomainLoginSession('owned')
    vi.mocked(authApi.domainLoginAuthorize).mockRejectedValueOnce(failure)
    vi.mocked(authApi.logout).mockRejectedValueOnce(new Error('offline'))
    await expect(authorizePendingDomainLogin()).rejects.toThrow('offline')
    expect(hasPendingCanonicalDomainLoginCleanup()).toBe(true)
    captureCanonicalDomainLogin(request, state)
    await expect(authorizePendingDomainLogin()).rejects.toMatchObject(failure)
    expect(authApi.domainLoginAuthorize).toHaveBeenCalledOnce()
    expect(authApi.logout).toHaveBeenCalledTimes(2)
    expect(hasPendingCanonicalDomainLogin()).toBe(false)
    expect(replace).not.toHaveBeenCalled()
  })
  it('uzavře expirovaný tok i po ztracené cookie, když logout i status potvrdí zánik relace', async () => {
    const failure = { response: { status: 400, data: { error: { code: 'invalid_domain_login_request', message: 'Expired request.' } } } }
    const absent = { response: { status: 401, data: { error: { code: 'unauthenticated' } } } }
    recordCanonicalDomainLoginSession('owned')
    vi.mocked(authApi.domainLoginAuthorize).mockRejectedValueOnce(failure)
    vi.mocked(authApi.logout).mockRejectedValueOnce(absent)
    vi.mocked(authApi.sessionStatus).mockRejectedValueOnce(absent)
    await expect(authorizePendingDomainLogin()).rejects.toMatchObject(failure)
    expect(hasPendingCanonicalDomainLogin()).toBe(false)
    expect(replace).not.toHaveBeenCalled()
  })
  it('preserves an unowned standalone login even on terminal authorization error', async () => {
    const failure = { response: { status: 400, data: { error: { code: 'invalid_domain_login_request' } } } }
    vi.mocked(authApi.domainLoginAuthorize).mockRejectedValueOnce(failure)
    confirmPendingDomainLogin()
    await expect(authorizePendingDomainLogin()).rejects.toMatchObject({ response: { status: 401 } })
    expect(authApi.domainLoginAuthorize).not.toHaveBeenCalled()
    expect(authApi.logout).not.toHaveBeenCalled()
    expect(hasPendingCanonicalDomainLogin()).toBe(true)
  })
  it('recovers when a lost cleanup response already deleted the native cookie', async () => {
    const absent = { response: { status: 401, data: { error: { code: 'unauthenticated' } } } }
    recordCanonicalDomainLoginSession('owned')
    vi.mocked(authApi.logout).mockRejectedValueOnce(new Error('lost response')).mockRejectedValueOnce(absent)
    await expect(authorizePendingDomainLogin()).rejects.toThrow('lost response')
    vi.mocked(authApi.sessionStatus).mockRejectedValueOnce(absent)
    await authorizePendingDomainLogin()
    expect(authApi.domainLoginAuthorize).toHaveBeenCalledOnce()
    expect(authApi.sessionStatus).toHaveBeenCalledOnce()
    expect(replace).toHaveBeenCalledWith(redirect)
    expect(hasPendingCanonicalDomainLogin()).toBe(false)
  })
  it.each(['active', 'locked', 'unavailable'])('does not infer cleanup from %s session status', async status => {
    const absent = { response: { status: 401, data: { error: { code: 'unauthenticated' } } } }
    recordCanonicalDomainLoginSession('owned')
    vi.mocked(authApi.logout).mockRejectedValueOnce(absent)
    if (status === 'active') vi.mocked(authApi.sessionStatus).mockResolvedValueOnce({ session_state: 'active' } as any)
    if (status === 'locked') vi.mocked(authApi.sessionStatus).mockRejectedValueOnce({ response: { status: 423 } })
    await expect(authorizePendingDomainLogin()).rejects.toEqual(absent)
    expect(canonicalDomainLoginSessionCsrf()).toBe('owned')
    expect(hasPendingCanonicalDomainLoginCleanup()).toBe(true)
    expect(replace).not.toHaveBeenCalled()
  })
  it('deduplicates concurrent continuation attempts', async () => {
    recordCanonicalDomainLoginSession('owned')
    await Promise.all([authorizePendingDomainLogin(), authorizePendingDomainLogin()])
    expect(authApi.domainLoginAuthorize).toHaveBeenCalledOnce()
    expect(authApi.logout).toHaveBeenCalledOnce()
    expect(replace).toHaveBeenCalledOnce()
  })
  it('retains completed cleanup if navigation throws', async () => {
    recordCanonicalDomainLoginSession('owned')
    replace.mockImplementationOnce(() => { throw new Error('navigation blocked') })
    await expect(authorizePendingDomainLogin()).rejects.toThrow('navigation blocked')
    expect(hasPendingCanonicalDomainLoginCleanup()).toBe(true)
    expect(canonicalDomainLoginSessionCsrf()).toBeNull()
    await authorizePendingDomainLogin()
    expect(authApi.domainLoginAuthorize).toHaveBeenCalledOnce()
    expect(authApi.logout).toHaveBeenCalledOnce()
  })
  it('never navigates to a tampered stored redirect', async () => {
    recordCanonicalDomainLoginSession('owned')
    vi.mocked(authApi.logout).mockRejectedValueOnce(new Error('offline'))
    await expect(authorizePendingDomainLogin()).rejects.toThrow('offline')
    const stored = JSON.parse(sessionStorage.getItem('myinvoice.domain_login.canonical') || '{}')
    sessionStorage.setItem('myinvoice.domain_login.canonical', JSON.stringify({ ...stored, redirectUrl: 'https://attacker.example.test/phish' }))
    vi.mocked(authApi.domainLoginAuthorize).mockResolvedValueOnce({ redirect_url: 'javascript:alert(1)' })
    await expect(authorizePendingDomainLogin()).rejects.toThrow('Invalid domain login redirect')
    expect(replace).not.toHaveBeenCalled()
    expect(hasPendingCanonicalDomainLogin()).toBe(false)
  })
  it('cancellation revokes only its isolated login before clearing the request', async () => {
    recordCanonicalDomainLoginSession('owned')
    await cancelPendingDomainLogin()
    expect(authApi.logout).toHaveBeenCalledWith('owned')
    expect(authApi.domainLoginAuthorize).not.toHaveBeenCalled()
    expect(hasPendingCanonicalDomainLogin()).toBe(false)
    expect(replace).not.toHaveBeenCalled()
  })
  it('cancellation without an isolated login preserves the standalone session', async () => {
    await cancelPendingDomainLogin()
    expect(authApi.logout).not.toHaveBeenCalled()
    expect(hasPendingCanonicalDomainLogin()).toBe(false)
  })
  it('a failed cancellation keeps ownership for a safe cleanup retry', async () => {
    recordCanonicalDomainLoginSession('owned')
    vi.mocked(authApi.logout).mockRejectedValueOnce(new Error('offline'))
    await expect(cancelPendingDomainLogin()).rejects.toThrow('offline')
    expect(canonicalDomainLoginSessionCsrf()).toBe('owned')
    await cancelPendingDomainLogin()
    expect(authApi.logout).toHaveBeenNthCalledWith(2, 'owned')
    expect(hasPendingCanonicalDomainLogin()).toBe(false)
  })
  it('does nothing without a pending request', async () => {
    sessionStorage.clear()
    expect(await authorizePendingDomainLogin()).toBe(false)
    expect(authApi.domainLoginAuthorize).not.toHaveBeenCalled()
    expect(authApi.logout).not.toHaveBeenCalled()
  })
})

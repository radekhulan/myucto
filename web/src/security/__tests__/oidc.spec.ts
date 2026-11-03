import { afterEach, describe, expect, it, vi } from 'vitest'
import {
  captureSsoFragment,
  parseSsoFragment,
  rememberPendingStepUp,
  resetSsoState,
  ssoErrorCode,
  ssoLoginUrl,
  ssoRequiredLoginPath,
  ssoReturnPath,
  takeSsoNotice,
  takeSsoStepUpProof,
  takeSsoStepUpReturn,
} from '../oidc'

function fakeLocation(hash: string) {
  return { hash, pathname: '/profile/api-tokens', search: '?x=1' }
}

describe('fragment návratu od poskytovatele identity', () => {
  afterEach(() => resetSsoState())

  it('rozpozná proof, propojení i chybu', () => {
    expect(parseSsoFragment('#sso_step_up=abc%2Bdef&sso_operation=api_token.create'))
      .toEqual({ kind: 'step_up', token: 'abc+def', operation: 'api_token.create' })
    expect(parseSsoFragment('#sso_linked=1')).toEqual({ kind: 'linked' })
    expect(parseSsoFragment('#sso_error=sso_cancelled')).toEqual({ kind: 'error', code: 'sso_cancelled' })
    expect(parseSsoFragment('#sso_step_up=abc')).toBeNull()
    expect(parseSsoFragment('#section-2')).toBeNull()
    expect(parseSsoFragment('')).toBeNull()
  })

  it('proof vydá jen pro operaci, kterou tento prohlížeč vyžádal, a jen jednou', () => {
    rememberPendingStepUp('api_token.create', '/profile/api-tokens?x=1', 1000)
    const history = { state: { k: 1 }, replaceState: vi.fn() }
    captureSsoFragment(fakeLocation('#sso_step_up=tok&sso_operation=api_token.create'), history, 2000)
    expect(history.replaceState).toHaveBeenCalledWith({ k: 1 }, '', '/profile/api-tokens?x=1')
    expect(takeSsoStepUpProof('oidc.link')).toBeNull()
    expect(takeSsoStepUpProof('api_token.create')).toBe('tok')
    expect(takeSsoStepUpProof('api_token.create')).toBeNull()
  })

  it('proof s kontextem dialogu vydá jen stejnému kontextu a vrátí upřesnění', () => {
    rememberPendingStepUp('epo.certificate', '/reports/submissions', 1000, 'tax-submissions:submit:12')
    captureSsoFragment(fakeLocation('#sso_step_up=tok&sso_operation=epo.certificate'), { state: null, replaceState: vi.fn() }, 2000)
    expect(takeSsoStepUpProof('epo.certificate')).toBeNull()
    expect(takeSsoStepUpReturn('epo.certificate', 'tax')).toBeNull()
    expect(takeSsoStepUpReturn('epo.certificate', 'payroll-signing')).toBeNull()
    expect(takeSsoStepUpReturn('epo.certificate', 'tax-submissions')).toEqual({ token: 'tok', detail: 'submit:12' })
    expect(takeSsoStepUpReturn('epo.certificate', 'tax-submissions')).toBeNull()
  })

  it('cizí nebo prošlý proof zahodí, fragment ale z adresy odstraní', () => {
    const history = { state: null, replaceState: vi.fn() }
    captureSsoFragment(fakeLocation('#sso_step_up=tok&sso_operation=api_token.create'), history, 2000)
    expect(history.replaceState).toHaveBeenCalledOnce()
    expect(takeSsoStepUpProof('api_token.create')).toBeNull()

    rememberPendingStepUp('api_token.create', '/', 0)
    captureSsoFragment(fakeLocation('#sso_step_up=tok&sso_operation=api_token.create'), history, 16 * 60 * 1000)
    expect(takeSsoStepUpProof('api_token.create')).toBeNull()
  })

  it('propojení a chybu vydá jako jednorázovou hlášku', () => {
    const history = { state: null, replaceState: vi.fn() }
    captureSsoFragment(fakeLocation('#sso_linked=1'), history)
    expect(takeSsoNotice()).toEqual({ kind: 'linked' })
    expect(takeSsoNotice()).toBeNull()
    captureSsoFragment(fakeLocation('#sso_error=sso_already_linked'), history)
    expect(takeSsoNotice()).toEqual({ kind: 'error', code: 'sso_already_linked' })
  })

  it('bez fragmentu adresu nemění', () => {
    const history = { state: null, replaceState: vi.fn() }
    expect(captureSsoFragment(fakeLocation('#top'), history)).toBeNull()
    expect(history.replaceState).not.toHaveBeenCalled()
  })
})

describe('adresy přihlášení', () => {
  it('návratová cesta je relativní, bez fragmentu a mimo API', () => {
    expect(ssoReturnPath('/invoices?x=1#a')).toBe('/invoices?x=1')
    expect(ssoReturnPath('//evil.example')).toBe('/')
    expect(ssoReturnPath('https://evil.example')).toBe('/')
    expect(ssoReturnPath('/api/auth/me')).toBe('/')
    expect(ssoReturnPath('/a\\b')).toBe('/')
    expect(ssoReturnPath(undefined, '/x')).toBe('/x')
  })

  it('sestaví zahájení přihlášení a přesměrování po odmítnutém sezení', () => {
    expect(ssoLoginUrl('entra', '/invoices?x=1')).toBe('/api/auth/oidc/entra/login?return_to=%2Finvoices%3Fx%3D1')
    expect(ssoRequiredLoginPath('entra', '/invoices')).toBe('/login?sso_required=entra&return_to=%2Finvoices')
    expect(ssoRequiredLoginPath(undefined, '/login?x=1')).toBe('/login?sso_required=entra')
  })

  it('neznámý kód chyby spadne na obecnou hlášku', () => {
    expect(ssoErrorCode('sso_cancelled')).toBe('sso_cancelled')
    expect(ssoErrorCode('<script>')).toBe('generic')
    expect(ssoErrorCode(undefined)).toBe('generic')
  })
})

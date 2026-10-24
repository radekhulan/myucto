import { authApi, type DomainLoginConfirmation } from '@/api/auth'
import { canonicalDomainLoginHandoffPath } from '@/security/clientRoutePolicy'

import { CANONICAL_DOMAIN_LOGIN_KEY as CANONICAL_KEY } from '@/security/domainLoginContext'

const TARGET_KEY = 'myinvoice.domain_login.target'
const CONFIRMATION_REQUIRED = 'domain_login_confirmation_required'
const TERMINAL_AUTHORIZATION_ERRORS = ['invalid_domain_login_request', 'canonical_origin_required', 'forbidden_supplier'] as const
type TerminalAuthorizationCode = typeof TERMINAL_AUTHORIZATION_ERRORS[number]
interface TerminalAuthorizationError { code: TerminalAuthorizationCode; message: string; status: number }

interface TargetState {
  requestToken: string
  state: string
  verifier: string
  expiresAt: number
}

interface CanonicalState {
  requestToken: string
  state: string
  handoffPath: string | null
  ownedCsrf?: string
  redirectUrl?: string
  terminalError?: TerminalAuthorizationError
  /** Uživatel předání tohoto požadavku výslovně potvrdil. */
  confirmed?: boolean
}

function base64Url(bytes: Uint8Array): string {
  let binary = ''
  for (const byte of bytes) binary += String.fromCharCode(byte)
  return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '')
}

function randomToken(): string {
  return base64Url(crypto.getRandomValues(new Uint8Array(32)))
}

async function challenge(verifier: string): Promise<string> {
  return base64Url(new Uint8Array(await crypto.subtle.digest('SHA-256', new TextEncoder().encode(verifier))))
}

export async function beginDomainLogin(returnPath = '/portal', handoffPath?: string): Promise<never> {
  const verifier = randomToken()
  const result = await authApi.domainLoginStart(await challenge(verifier), returnPath, handoffPath)
  const target: TargetState = {
    requestToken: result.request_token,
    state: result.state,
    verifier,
    expiresAt: Date.now() + result.expires_in * 1000,
  }
  sessionStorage.setItem(TARGET_KEY, JSON.stringify(target))
  window.location.replace(result.login_url)
  return new Promise<never>(() => undefined)
}

export function captureCanonicalDomainLogin(
  requestToken: unknown,
  state: unknown,
  handoffPath: unknown = null,
): boolean {
  if (typeof requestToken !== 'string' || typeof state !== 'string'
      || !/^[A-Za-z0-9_-]{43}$/.test(requestToken)
      || !/^[A-Za-z0-9_-]{43}$/.test(state)) {
    sessionStorage.removeItem(CANONICAL_KEY)
    return false
  }
  const previous = readCanonical()
  saveCanonical({
    ...(previous?.requestToken === requestToken && previous.state === state
      ? previous
      : {}),
    requestToken,
    state,
    handoffPath: canonicalDomainLoginHandoffPath(handoffPath),
  })
  return true
}

export function hasPendingCanonicalDomainLogin(): boolean {
  return readCanonical() !== null
}

export function hasPendingCanonicalDomainLoginCleanup(): boolean {
  const pending = readCanonical()
  return !!(pending?.redirectUrl || pending?.terminalError)
}

export function pendingCanonicalDomainLoginQuery(): Record<string, string> | null {
  const pending = readCanonical()
  if (!pending) return null
  return {
    domain_login_request: pending.requestToken,
    state: pending.state,
    ...(pending.handoffPath ? { domain_login_handoff: pending.handoffPath } : {}),
  }
}

export function pendingCanonicalDomainLoginHandoff(): string | null {
  return readCanonical()?.handoffPath ?? null
}

export function clearCanonicalDomainLogin(): void {
  sessionStorage.removeItem(CANONICAL_KEY)
}

/** Only a login response can establish ownership; /me and session status cannot. */
export function recordCanonicalDomainLoginSession(csrf: string): void {
  const pending = readCanonical()
  if (pending && !pending.redirectUrl && !pending.terminalError && csrf) saveCanonical({ ...pending, ownedCsrf: csrf })
}

export function canonicalDomainLoginSessionCsrf(): string | null {
  return readCanonical()?.ownedCsrf ?? null
}

/** A known same-tab rotation may advance existing ownership, never acquire it. */
export function advanceCanonicalDomainLoginSession(previousCsrf: string, csrf: string): void {
  const pending = readCanonical()
  if (pending?.ownedCsrf === previousCsrf && previousCsrf && csrf) {
    saveCanonical({ ...pending, ownedCsrf: csrf })
  }
}

/**
 * Předání existujícího přihlášení z canonical adresy potřebuje výslovné
 * potvrzení. Implicitní je jen u session, kterou vytvořilo přihlášení v rámci
 * tohoto toku (`ownedCsrf`); i tu ale server odmítne, pokud je starší než
 * požadavek, a pak se potvrzení vyžádá dodatečně.
 */
export function needsCanonicalDomainLoginConfirmation(): boolean {
  const pending = readCanonical()
  return !!pending && !pending.redirectUrl && !pending.terminalError && !pending.ownedCsrf && !pending.confirmed
}

export function confirmPendingDomainLogin(): void {
  const pending = readCanonical()
  if (pending && !pending.redirectUrl && !pending.terminalError) saveCanonical({ ...pending, confirmed: true })
}

export function isDomainLoginConfirmationRequired(error: unknown): boolean {
  return (error as { response?: { data?: { error?: { code?: string } } } })?.response?.data?.error?.code === CONFIRMATION_REQUIRED
}

/** Cílový hostname a firma čekajícího předání. Kód se tím nevydává. */
export async function previewPendingDomainLogin(): Promise<DomainLoginConfirmation | null> {
  const pending = readCanonical()
  if (!pending || pending.redirectUrl || pending.terminalError) return null
  try {
    return await authApi.domainLoginPreview(pending.requestToken, pending.state, pending.ownedCsrf)
  } catch (error: any) {
    // Neplatný nebo cizí požadavek se nedá potvrdit. Bez vlastní dočasné
    // session není co uklízet, takže se rovnou zahodí.
    if (TERMINAL_AUTHORIZATION_ERRORS.includes(error?.response?.data?.error?.code) && !pending.ownedCsrf) {
      clearCanonicalDomainLogin()
    }
    throw error
  }
}

let authorization: Promise<boolean> | null = null

export function authorizePendingDomainLogin(): Promise<boolean> {
  if (authorization) return authorization
  const attempt = authorizeAndCleanup()
  authorization = attempt
  void attempt.finally(() => {
    if (authorization === attempt) authorization = null
  }).catch(() => undefined)
  return attempt
}

async function authorizeAndCleanup(): Promise<boolean> {
  let pending = readCanonical()
  if (!pending) return false
  if (!pending.redirectUrl && !pending.terminalError) {
    // Potvrzení cíle nenahrazuje nové přihlášení svázané s tímto tokem.
    if (!pending.ownedCsrf) {
      throw { response: { status: 401, data: { error: { code: 'fresh_authentication_required' } } } }
    }
    try {
      const result = await authApi.domainLoginAuthorize(
        pending.requestToken,
        pending.state,
        pending.ownedCsrf,
        pending.confirmed === true,
      )
      pending = { ...pending, redirectUrl: result.redirect_url }
      // Issuing a code is one-shot. Persist it before cleanup so a failed logout
      // can be retried after a reload without authorizing the request twice.
      saveCanonical(pending)
    } catch (error: any) {
      const code = error?.response?.data?.error?.code
      if (!TERMINAL_AUTHORIZATION_ERRORS.includes(code)) throw error
      if (!pending.ownedCsrf) {
        clearCanonicalDomainLogin()
        throw error
      }
      // A rejected/expired portal request still must not leave its temporary
      // native login alive. Keep this phase until its bound logout succeeds.
      pending = { ...pending, terminalError: {
        code,
        message: String(error.response.data.error.message || ''),
        status: error.response.status || 400,
      } }
      saveCanonical(pending)
    }
  }
  pending = await cleanupCanonicalDomainLoginSession(pending)
  if (pending.terminalError) {
    clearCanonicalDomainLogin()
    throw { response: { status: pending.terminalError.status, data: { error: pending.terminalError } } }
  }
  if (!isDomainLoginCallbackUrl(pending.redirectUrl)) {
    clearCanonicalDomainLogin()
    throw new Error('Invalid domain login redirect')
  }
  window.location.replace(pending.redirectUrl)
  clearCanonicalDomainLogin()
  return true
}

/** Zrušení předání ukončí pouze dočasnou relaci, kterou tento tok vytvořil. */
export async function cancelPendingDomainLogin(): Promise<void> {
  const pending = readCanonical()
  if (pending) await cleanupCanonicalDomainLoginSession(pending)
  clearCanonicalDomainLogin()
}

async function cleanupCanonicalDomainLoginSession(pending: CanonicalState): Promise<CanonicalState> {
  if (pending.ownedCsrf) {
    // Explicit CSRF binds cleanup to the session created by this flow. A cookie
    // replaced by another tab must fail CSRF rather than log out that session.
    try {
      await authApi.logout(pending.ownedCsrf)
    } catch (error) {
      if (!isMissingSession(error)) throw error
      // A lost logout response may already have deleted the cookie. Only an
      // explicit unauthenticated status confirms cleanup; a different live
      // session, a lock, or a network failure must keep this attempt pending.
      let ended = false
      try {
        await authApi.sessionStatus()
      } catch (statusError) {
        ended = isMissingSession(statusError)
      }
      if (!ended) throw error
    }
    pending = { ...pending, ownedCsrf: undefined }
    saveCanonical(pending)
  }
  return pending
}

function isMissingSession(error: unknown): boolean {
  const response = (error as { response?: { status?: number; data?: { error?: { code?: string } } } })?.response
  return response?.status === 401
    && ['unauthenticated', 'session_expired', 'invalid_token'].includes(response.data?.error?.code || '')
}

function saveCanonical(value: CanonicalState): void {
  sessionStorage.setItem(CANONICAL_KEY, JSON.stringify(value))
}

export function readTargetDomainLogin(
  requestToken: unknown,
  code: unknown,
  state: unknown,
): (TargetState & { code: string }) | null {
  if (typeof requestToken !== 'string' || typeof code !== 'string' || typeof state !== 'string') return null
  try {
    const saved = JSON.parse(sessionStorage.getItem(TARGET_KEY) || 'null') as TargetState | null
    if (!saved || saved.expiresAt < Date.now()
        || saved.requestToken !== requestToken || saved.state !== state
        || !/^[A-Za-z0-9_-]{43}$/.test(code)) {
      sessionStorage.removeItem(TARGET_KEY)
      return null
    }
    return { ...saved, code }
  } catch {
    sessionStorage.removeItem(TARGET_KEY)
    return null
  }
}

export function clearTargetDomainLogin(): void {
  sessionStorage.removeItem(TARGET_KEY)
}

function readCanonical(): CanonicalState | null {
  try {
    const value = JSON.parse(sessionStorage.getItem(CANONICAL_KEY) || 'null') as CanonicalState | null
    if (!value || !/^[A-Za-z0-9_-]{43}$/.test(value.requestToken)
      || !/^[A-Za-z0-9_-]{43}$/.test(value.state)) return null
    return {
      requestToken: value.requestToken,
      state: value.state,
      handoffPath: canonicalDomainLoginHandoffPath(value.handoffPath),
      ...(typeof value.ownedCsrf === 'string' && value.ownedCsrf ? { ownedCsrf: value.ownedCsrf } : {}),
      ...(value.terminalError && TERMINAL_AUTHORIZATION_ERRORS.includes(value.terminalError.code)
        && typeof value.terminalError.message === 'string' && typeof value.terminalError.status === 'number'
        ? { terminalError: value.terminalError } : {}),
      ...(isDomainLoginCallbackUrl(value.redirectUrl) ? { redirectUrl: value.redirectUrl } : {}),
      ...(value.confirmed === true ? { confirmed: true } : {}),
    }
  } catch {
    return null
  }
}

function isDomainLoginCallbackUrl(value: unknown): value is string {
  if (typeof value !== 'string' || !value) return false
  try {
    const url = new URL(value)
    return url.protocol === 'https:' && url.pathname === '/domain-login/callback'
      && !url.username && !url.password
  } catch {
    return false
  }
}

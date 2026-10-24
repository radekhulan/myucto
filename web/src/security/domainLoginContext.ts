import { canonicalDomainLoginHandoffPath } from '@/security/clientRoutePolicy'

export const CANONICAL_DOMAIN_LOGIN_KEY = 'myinvoice.domain_login.canonical'
let canonicalOrigin = false

export interface DomainLoginRequestContext { requestToken: string; state: string }

export function setDomainLoginCanonicalOrigin(canonical: boolean): void {
  canonicalOrigin = canonical
}

/** Kontext potvrzuje veřejný domain-context; klientská doména hlavičky nikdy neposílá. */
export function domainLoginRequestContext(): DomainLoginRequestContext | null {
  return canonicalOrigin ? pendingDomainLoginRequestContext() : null
}

export function pendingDomainLoginRequestContext(): DomainLoginRequestContext | null {
  try {
    const value = JSON.parse(sessionStorage.getItem(CANONICAL_DOMAIN_LOGIN_KEY) || 'null')
    return validDomainLoginToken(value?.requestToken) && validDomainLoginToken(value?.state)
      ? { requestToken: value.requestToken, state: value.state } : null
  } catch { return null }
}

/** Návrat na login musí zachovat tok i po zániku dočasné cookie. */
export function domainLoginContinuationQuery(): Record<string, string> | null {
  const context = domainLoginRequestContext()
  if (!context) return null
  let handoffPath: string | null = null
  try {
    handoffPath = canonicalDomainLoginHandoffPath(JSON.parse(sessionStorage.getItem(CANONICAL_DOMAIN_LOGIN_KEY) || 'null')?.handoffPath)
  } catch { /* Neplatné úložiště neposkytuje pokračování. */ }
  return {
    domain_login_request: context.requestToken,
    state: context.state,
    ...(handoffPath ? { domain_login_handoff: handoffPath } : {}),
  }
}

export function validDomainLoginToken(value: unknown): value is string {
  return typeof value === 'string' && /^[A-Za-z0-9_-]{43}$/.test(value)
}

/** Zachytí příchod před importem routeru a jeho prvním načtením identity. */
export function captureInitialDomainLogin(location: Pick<Location, 'pathname' | 'search'>): void {
  if (location.pathname !== '/login') return
  const query = new URLSearchParams(location.search)
  const requestToken = query.get('domain_login_request')
  const state = query.get('state')
  if (!validDomainLoginToken(requestToken) || !validDomainLoginToken(state)) {
    sessionStorage.removeItem(CANONICAL_DOMAIN_LOGIN_KEY)
    return
  }
  const previous = pendingDomainLoginRequestContext()
  if (previous?.requestToken === requestToken && previous.state === state) return
  sessionStorage.setItem(CANONICAL_DOMAIN_LOGIN_KEY, JSON.stringify({
    requestToken, state, handoffPath: canonicalDomainLoginHandoffPath(query.get('domain_login_handoff')),
  }))
}

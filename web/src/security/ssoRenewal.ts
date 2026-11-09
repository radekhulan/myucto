/**
 * Tiché obnovení přihlášení přes poskytovatele identity, který nahrazuje celé
 * přihlášení (Entra ID).
 *
 * Sezení z takového poskytovatele má pevnou platnost (backend
 * `auth.oidc.session_lifetime_hours`), aby se uživatel zablokovaný u poskytovatele
 * po vypršení znovu nepřihlásil. Po vypršení proto aplikace nepošle uživatele na
 * formulář, ale rovnou na `/api/auth/oidc/{provider}/login?silent=1`: poskytovatel
 * ho ověří bez dotazu (`prompt=none`) a backend ho vrátí na stejnou stránku. Když
 * to bez uživatele nejde, backend pokračuje běžným přihlášením s výběrem účtu.
 *
 * Obnovuje se, když instalace přihlášení přes silného poskytovatele vynucuje, nebo
 * když se uživatel v tomto prohlížeči naposledy přihlásil přes něj. Po ručním
 * odhlášení se tiše neobnovuje. Proti smyčce chrání čas posledního pokusu
 * v sessionStorage: další tiché obnovení nejdřív po několika minutách, mezitím
 * běžné přihlášení.
 */
import { ssoReturnPath } from './oidc'

const REMEMBER_KEY = 'myucto.sso_renewal_provider'
const ATTEMPT_KEY = 'myucto.sso_renewal_attempt'
/** Hodnota v úložišti po ručním odhlášení. */
const LOGGED_OUT = '-'

/** Pokus mladší než tohle ještě běží (prohlížeč odchází k poskytovateli). */
export const SSO_RENEWAL_PENDING_MS = 30 * 1000
/** Během této doby po pokusu se tiše neobnovuje, vznikla by smyčka. */
export const SSO_RENEWAL_RETRY_MS = 5 * 60 * 1000
/** Jak dlouho před vypršením sezení se obnoví preventivně při navigaci. */
export const SSO_RENEWAL_LEAD_MS = 10 * 60 * 1000

interface RenewalStatus {
  sso_required_provider?: string | null
  sso_providers?: Array<{ id: string; strong?: boolean }>
}

let strongProviders: string[] = []
let requiredProvider: string | null = null
let sessionDeadline: number | null = null

function readStorage(storage: () => Storage, key: string): string | null {
  try { return storage().getItem(key) } catch { return null }
}

function writeStorage(storage: () => Storage, key: string, value: string | null): void {
  try {
    if (value === null) storage().removeItem(key)
    else storage().setItem(key, value)
  } catch { /* nedostupné úložiště: obnovení se pak řídí jen vynucením instalace */ }
}

/** Pravidla instalace ze stavu přihlášení (`/api/auth/setup-status`). */
export function setSsoRenewalPolicy(status: RenewalStatus | null | undefined): void {
  strongProviders = (status?.sso_providers ?? []).filter(p => p.strong === true).map(p => p.id)
  const required = status?.sso_required_provider
  requiredProvider = typeof required === 'string' && strongProviders.includes(required) ? required : null
}

/**
 * Sezení z `/api/auth/me`: poskytovatel a čas vypršení, nebo null pro sezení
 * z hesla či passkey. Čas se přepočte na hodiny prohlížeče podle `server_time`.
 */
export function rememberSsoSession(
  session: { provider: string; expires_at: string } | null | undefined,
  serverTime?: string | null,
  now = Date.now(),
): void {
  if (session === null || session === undefined || session.provider === '') {
    writeStorage(() => localStorage, REMEMBER_KEY, null)
    sessionDeadline = null
    return
  }
  writeStorage(() => localStorage, REMEMBER_KEY, session.provider)
  const expires = Date.parse(session.expires_at)
  const server = serverTime ? Date.parse(serverTime) : Number.NaN
  sessionDeadline = Number.isFinite(expires)
    ? now + (expires - (Number.isFinite(server) ? server : now))
    : null
}

/** Ruční odhlášení: tiché obnovení se vypne až do dalšího přihlášení. */
export function forgetSsoSession(): void {
  writeStorage(() => localStorage, REMEMBER_KEY, LOGGED_OUT)
  sessionDeadline = null
}

/** Poskytovatel pro tiché obnovení, nebo null (pak běžné přihlášení). */
export function ssoRenewalProvider(): string | null {
  const remembered = readStorage(() => localStorage, REMEMBER_KEY)
  if (remembered === LOGGED_OUT) return null
  if (requiredProvider !== null) return requiredProvider
  return remembered !== null && strongProviders.includes(remembered) ? remembered : null
}

export function ssoRenewalUrl(provider: string, returnTo: string | null | undefined): string {
  const params = new URLSearchParams({ return_to: ssoReturnPath(returnTo), silent: '1' })
  return `/api/auth/oidc/${encodeURIComponent(provider)}/login?${params}`
}

export type SsoRenewalDecision =
  | { action: 'renew'; url: string }
  | { action: 'wait' }
  | { action: 'login' }

/**
 * Co udělat, když sezení skončilo. `renew` zapíše pokus, takže další volání
 * během odchodu k poskytovateli vrátí `wait` a po návratu bez úspěchu `login`.
 */
export function beginSsoRenewal(returnTo: string | null | undefined, now = Date.now()): SsoRenewalDecision {
  const provider = ssoRenewalProvider()
  if (provider === null) return { action: 'login' }
  const last = Number(readStorage(() => sessionStorage, ATTEMPT_KEY) ?? '')
  if (Number.isFinite(last) && last > 0 && now - last < SSO_RENEWAL_RETRY_MS && now >= last) {
    return now - last < SSO_RENEWAL_PENDING_MS ? { action: 'wait' } : { action: 'login' }
  }
  writeStorage(() => sessionStorage, ATTEMPT_KEY, String(now))
  return { action: 'renew', url: ssoRenewalUrl(provider, returnTo) }
}

/** Prohlížeč právě odchází k poskytovateli; router už nemá přesměrovávat na přihlášení. */
export function ssoRenewalPending(now = Date.now()): boolean {
  const last = Number(readStorage(() => sessionStorage, ATTEMPT_KEY) ?? '')
  return Number.isFinite(last) && last > 0 && now >= last && now - last < SSO_RENEWAL_PENDING_MS
}

/** Sezení z poskytovatele brzy vyprší a dá se obnovit (preventivně při navigaci). */
export function ssoRenewalDue(now = Date.now()): boolean {
  return sessionDeadline !== null && sessionDeadline - now < SSO_RENEWAL_LEAD_MS && ssoRenewalProvider() !== null
}

/** Jen pro testy. */
export function resetSsoRenewal(): void {
  strongProviders = []
  requiredProvider = null
  sessionDeadline = null
  writeStorage(() => localStorage, REMEMBER_KEY, null)
  writeStorage(() => sessionStorage, ATTEMPT_KEY, null)
}

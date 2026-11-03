/**
 * Přihlášení přes poskytovatele identity (OpenID Connect) na straně SPA.
 *
 * Zahájení je vždy navigace celé stránky na `/api/auth/oidc/{provider}/…`.
 * Návrat z propojení a z ověření citlivé operace nese výsledek ve fragmentu
 * (`#sso_step_up=…`, `#sso_linked=1`, `#sso_error=…`): fragment se neposílá na
 * server, takže proof neskončí v přístupových lozích. Fragment se přečte jednou
 * při startu aplikace a hned se z adresy odstraní.
 */

export const SSO_ERROR_CODES = [
  'sso_disabled',
  'sso_flow_invalid',
  'sso_provider_error',
  'sso_provider_unavailable',
  'sso_token_invalid',
  'sso_not_linked',
  'sso_already_linked',
  'sso_invite_invalid',
  'sso_user_inactive',
  'sso_identity_mismatch',
  'sso_cancelled',
] as const

export type SsoErrorCode = typeof SSO_ERROR_CODES[number]

export type SsoFragment =
  | { kind: 'step_up'; token: string; operation: string; context?: string }
  | { kind: 'linked' }
  | { kind: 'error'; code: string }

const PENDING_KEY = 'myucto.sso_step_up_pending'
const PENDING_MAX_AGE_MS = 15 * 60 * 1000

export function isSsoErrorCode(code: unknown): code is SsoErrorCode {
  return typeof code === 'string' && (SSO_ERROR_CODES as readonly string[]).includes(code)
}

/** Kód chyby pro překlad; neznámý kód spadne na obecnou hlášku. */
export function ssoErrorCode(code: unknown): SsoErrorCode | 'generic' {
  return isSsoErrorCode(code) ? code : 'generic'
}

export function parseSsoFragment(hash: string): SsoFragment | null {
  const raw = hash.startsWith('#') ? hash.slice(1) : hash
  if (raw === '' || !raw.includes('sso_')) return null
  const params = new URLSearchParams(raw)
  const token = params.get('sso_step_up')
  if (token !== null) {
    const operation = params.get('sso_operation') ?? ''
    if (token === '' || operation === '') return null
    return { kind: 'step_up', token, operation }
  }
  if (params.get('sso_linked') === '1') return { kind: 'linked' }
  const error = params.get('sso_error')
  if (error !== null && error !== '') return { kind: 'error', code: error }
  return null
}

/**
 * Návratová cesta pro backend: relativní, bez fragmentu a mimo `/api/`.
 * Backend ji kontroluje znovu (OidcAction::safeReturnTo), tady jen nechceme
 * poslat něco, co by odmítl a vrátil uživatele na úvod.
 */
export function ssoReturnPath(path: string | null | undefined, fallback = '/'): string {
  if (typeof path !== 'string') return fallback
  const clean = path.split('#')[0] ?? ''
  if (!clean.startsWith('/') || clean.startsWith('//') || clean.includes('\\')
    || clean.startsWith('/api/') || clean.length > 512 || /[\x00-\x20\x7f]/.test(clean)) {
    return fallback
  }
  return clean
}

export function ssoLoginUrl(provider: string, returnTo?: string | null): string {
  const params = new URLSearchParams({ return_to: ssoReturnPath(returnTo) })
  return `/api/auth/oidc/${encodeURIComponent(provider)}/login?${params}`
}

/** Kam poslat sezení z hesla, které firma s vynuceným přihlášením přes organizaci odmítla. */
export function ssoRequiredLoginPath(provider: unknown, returnPath: string): string {
  const params = new URLSearchParams({ sso_required: typeof provider === 'string' && provider !== '' ? provider : 'entra' })
  const safe = ssoReturnPath(returnPath, '')
  if (safe !== '' && !safe.startsWith('/login')) params.set('return_to', safe)
  return `/login?${params}`
}

export function hasProvider(providers: Array<{ id: string }> | null | undefined, id: string): boolean {
  return Array.isArray(providers) && providers.some(p => p.id === id)
}

/** Poskytovatel smí potvrzovat citlivé operace jen tehdy, když ho backend označí jako silný. */
export function hasStrongProvider(
  providers: Array<{ id: string; strong?: boolean }> | null | undefined,
  id: string,
): boolean {
  return Array.isArray(providers) && providers.some(p => p.id === id && p.strong === true)
}

// ── Lístek z přihlášení přes poskytovatele, který je jen prvním faktorem ──

/**
 * Google, Apple a Seznam sezení nezakládají: backend vrátí na `/login` fragment
 * `#sso_ticket=…&sso_provider=…&return_to=…` a přihlášení dokončí
 * `POST /api/auth/login` se `sso_ticket` místo e-mailu a hesla.
 */
export interface SsoLoginTicket {
  ticket: string
  provider: string
  returnTo: string | null
}

let capturedTicket: SsoLoginTicket | null = null

export function parseSsoLoginTicket(hash: string): SsoLoginTicket | null {
  const raw = hash.startsWith('#') ? hash.slice(1) : hash
  if (!raw.includes('sso_ticket=')) return null
  const params = new URLSearchParams(raw)
  const ticket = params.get('sso_ticket') ?? ''
  const provider = params.get('sso_provider') ?? ''
  if (!/^[0-9a-f]{64}$/i.test(ticket) || !/^[a-z0-9_-]{1,32}$/.test(provider)) return null
  const returnTo = ssoReturnPath(params.get('return_to'), '')
  return { ticket, provider, returnTo: returnTo === '' || returnTo.startsWith('/login') ? null : returnTo }
}

/** Přečte lístek z adresy a hned ho z ní odstraní, aby nezůstal v historii ani v záložce. */
export function captureSsoLoginTicket(
  location: Pick<Location, 'hash' | 'pathname' | 'search'>,
  history: Pick<History, 'replaceState' | 'state'>,
): SsoLoginTicket | null {
  const hash = location.hash
  if (!hash.includes('sso_ticket=')) return null
  const ticket = parseSsoLoginTicket(hash)
  history.replaceState(history.state, '', `${location.pathname}${location.search}`)
  if (ticket !== null) capturedTicket = ticket
  return ticket
}

/** Lístek se vydá jen jednou. */
export function takeSsoLoginTicket(): SsoLoginTicket | null {
  const ticket = capturedTicket
  capturedTicket = null
  return ticket
}

// ── Stav mezi odchodem k poskytovateli a návratem ─────────────────────────

let captured: SsoFragment | null = null

interface PendingStepUp {
  operation: string
  path: string
  at: number
  /**
   * Který dialog na stránce o ověření požádal (např. `epo:submit:12`). Víc
   * dialogů sdílí stejnou operaci, takže podle samotné operace by se po návratu
   * nedalo poznat, který z nich otevřít.
   */
  context?: string
}

function readPending(): PendingStepUp | null {
  try {
    const raw = sessionStorage.getItem(PENDING_KEY)
    if (!raw) return null
    const parsed = JSON.parse(raw) as Partial<PendingStepUp>
    if (typeof parsed.operation !== 'string' || typeof parsed.path !== 'string' || typeof parsed.at !== 'number') return null
    if (parsed.context !== undefined && typeof parsed.context !== 'string') return null
    return parsed as PendingStepUp
  } catch {
    return null
  }
}

function clearPending(): void {
  try { sessionStorage.removeItem(PENDING_KEY) } catch { /* nedostupné úložiště */ }
}

export function rememberPendingStepUp(operation: string, path: string, now = Date.now(), context = ''): void {
  try {
    sessionStorage.setItem(PENDING_KEY, JSON.stringify({ operation, path, at: now, context }))
  } catch { /* nedostupné úložiště: proof se pak nepřijme a uživatel ověří znovu */ }
}

/**
 * Přečte výsledek návratu z fragmentu a odstraní ho z adresy. Proof ze
 * step-upu se přijme jen tehdy, když ho tento prohlížeč sám vyžádal pro stejnou
 * operaci: podvržený odkaz s cizím fragmentem tak nic nepředvyplní.
 */
export function captureSsoFragment(
  location: Pick<Location, 'hash' | 'pathname' | 'search'>,
  history: Pick<History, 'replaceState' | 'state'>,
  now = Date.now(),
): SsoFragment | null {
  const fragment = parseSsoFragment(location.hash)
  if (fragment === null) return null
  history.replaceState(history.state, '', `${location.pathname}${location.search}`)
  if (fragment.kind === 'step_up') {
    const pending = readPending()
    clearPending()
    if (pending === null || pending.operation !== fragment.operation || now - pending.at > PENDING_MAX_AGE_MS) {
      return null
    }
    captured = { ...fragment, context: pending.context ?? '' }
    return captured
  }
  if (fragment.kind === 'error') clearPending()
  captured = fragment
  return fragment
}

/**
 * Proof z ověření přes poskytovatele pro danou operaci; vydá se jen jednou.
 *
 * `scope` odpovídá kontextu, se kterým stránka ověření zahájila: vydá se jen
 * stejnému kontextu (`epo`) nebo jeho upřesnění (`epo:submit:12`), v `detail`
 * je pak zbytek za dvojtečkou. Bez kontextu se vydá jen bezkontextovému volajícímu,
 * takže dvě komponenty se stejnou operací na jedné stránce si proof nevezmou.
 */
export function takeSsoStepUpReturn(operation: string, scope = ''): { token: string; detail: string } | null {
  if (captured?.kind !== 'step_up' || captured.operation !== operation) return null
  const context = captured.context ?? ''
  let detail: string
  if (context === scope) detail = ''
  else if (scope !== '' && context.startsWith(`${scope}:`)) detail = context.slice(scope.length + 1)
  else return null
  const token = captured.token
  captured = null
  return { token, detail }
}

export function takeSsoStepUpProof(operation: string, scope = ''): string | null {
  return takeSsoStepUpReturn(operation, scope)?.token ?? null
}

/** Výsledek propojení nebo chyba návratu, pro jednorázovou hlášku. */
export function takeSsoNotice(): Exclude<SsoFragment, { kind: 'step_up' }> | null {
  if (captured === null || captured.kind === 'step_up') return null
  const notice = captured
  captured = null
  return notice
}

/** Jen pro testy. */
export function resetSsoState(): void {
  captured = null
  capturedTicket = null
  clearPending()
}

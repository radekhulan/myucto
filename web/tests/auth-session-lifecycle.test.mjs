import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'

const root = new URL('../src/', import.meta.url)
const client = await readFile(new URL('api/client.ts', root), 'utf8')
const auth = await readFile(new URL('stores/auth.ts', root), 'utf8')
const layout = await readFile(new URL('components/layout/AppLayout.vue', root), 'utf8')
const forcedSetup = await readFile(new URL('pages/ForcedMfaSetup.vue', root), 'utf8')
const sessionSecurity = await readFile(new URL('stores/sessionSecurity.ts', root), 'utf8')
const login = await readFile(new URL('pages/Login.vue', root), 'utf8')
const setup = await readFile(new URL('pages/Setup.vue', root), 'utf8')
const router = await readFile(new URL('router/index.ts', root), 'utf8')
const main = await readFile(new URL('main.ts', root), 'utf8')

test('MFA reauthentication redirects explicitly without redirecting every 401 response', () => {
  assert.match(client, /status === 401 && \[[\s\S]*'mfa_reauthentication_required'[\s\S]*\]\.includes\(code\)/)
  assert.doesNotMatch(client, /if \(status === 401\) \{\s*window\.location\.href = '\/login'/)
})

test('logout clears private state even when the server request fails and isolates its retry CSRF', () => {
  assert.match(auth, /const requestCsrfToken = logoutRetryCsrfToken \|\| csrfToken\.value/)
  assert.match(auth, /if \(requestCsrfToken\) setCsrfToken\(requestCsrfToken\)/)
  assert.match(auth, /catch \(error\) \{\s*logoutRetryCsrfToken = requestCsrfToken[\s\S]*throw error/)
  assert.match(auth, /finally \{\s*clearPrivateState\(\)/)
  assert.match(
    auth,
    /clearPrivateState[\s\S]*setCsrfToken\(null\)[\s\S]*setAvailable\(\[\], 0, domainContext\.value\?\.locked === true\)/,
  )
})

test('logout failures are surfaced by both private layout and forced MFA setup', () => {
  assert.match(layout, /catch \{[\s\S]*sessionSecurity\.error = 'logout_failed'[\s\S]*toast\.error\(t\('auth\.logout_failed'\)\)/)
  assert.match(layout, /try \{[\s\S]*sessionSecurity\.clear\(\)[\s\S]*router\.replace\('\/login'\)[\s\S]*catch \{[\s\S]*sessionSecurity\.markLocked\(\)/)
  assert.match(forcedSetup, /catch \{\s*error\.value = t\('auth\.logout_failed'\)/)
  assert.match(forcedSetup, /finally \{\s*sessionSecurity\.clear\(\)/)
})

test('manual lock is available only for a session with passkey unlock', () => {
  assert.match(layout, /canLockSession[\s\S]*unlock_methods\.includes\('passkey'\)/)
  assert.equal((layout.match(/v-if="canLockSession"/g) || []).length, 2)
  assert.match(
    sessionSecurity,
    /async function lock\(\) \{[\s\S]*!state\.value\.unlock_methods\.includes\('passkey'\)[\s\S]*return/,
  )
})

// Guard i /login musí číst TÝŽ zdroj pravdy. Guard rozhoduje podle stavu storu
// (refresh() vrací false i při výpadku sítě, kdy si identitu záměrně drží), takže
// kdyby se /login řídilo návratovou hodnotou, vznikne nekonečná smyčka: guard sem,
// my zpátky na `/`, guard sem… Celé v JS, bez reloadu — poznat je to jen jako
// opakující se `Promise.then` v call stacku.
test('login and the router guard agree on what "authenticated" means', () => {
  assert.match(router, /if \(requiresAuth && !auth\.isAuthenticated\) \{[\s\S]*?await auth\.refresh\(\)[\s\S]*?if \(!auth\.isAuthenticated\) \{[\s\S]*?name: 'login'/)
  assert.match(
    login,
    /const freshDomainLogin = hasPendingCanonicalDomainLogin\(\) && !canonicalDomainLoginSessionCsrf\(\)\s+if \(!freshDomainLogin\) await auth\.refresh\(\)\s+if \(!freshDomainLogin && auth\.isAuthenticated\) \{/,
  )
  assert.doesNotMatch(login, /const stillAuthed = await auth\.refresh\(\)/)
})

// Setup wizard uživatele nesmí poslat na `/`, když se session nechytila (prohlížeč
// zahodil cookie kvůli `__Host-`/Secure na plain HTTP) — skončil by na /login bez
// vysvětlení a instalace by vypadala jako rozbitá aplikace.
const setupContinuationBody = setup.match(/async function goToApp\(\) \{([\s\S]*?)\n\}/)?.[1]
assert.ok(setupContinuationBody)
const continueSetup = new (Object.getPrototypeOf(async function () {}).constructor)(
  'auth', 'sessionError', 'locale', 'router', 'rememberTotpEnrollment', 'window', setupContinuationBody,
)

test('setup refuses to enter the app when the session cookie did not stick', async () => {
  const sessionError = { value: '' }
  const window = { location: { href: '/setup' } }
  await continueSetup(
    { refresh: async () => true, isAuthenticated: false }, sessionError, { value: 'cs' },
    { push: () => assert.fail('Unauthenticated setup cannot navigate') },
    () => assert.fail('Unauthenticated setup cannot continue'), window,
  )
  assert.ok(sessionError.value.includes('cookie'))
  assert.equal(window.location.href, '/setup')
})

test('authenticated setup preserves enrollment authorization for required and optional MFA', async () => {
  for (const mode of ['required', 'offer', 'app']) {
    const calls = []
    const window = { location: { href: '/setup' } }
    await continueSetup({
      refresh: async () => false,
      isAuthenticated: true,
      mustSetupMfa: mode === 'required',
      shouldOfferMfa: mode === 'offer',
      fetchSetupStatus: async () => calls.push('refresh-status'),
    }, { value: '' }, { value: 'cs' },
    { push: async (path) => calls.push(path) }, () => calls.push('clear-grant'), window)
    assert.deepEqual(calls, mode === 'app' ? ['clear-grant'] : ['refresh-status', '/setup-mfa'])
    assert.equal(window.location.href, mode === 'app' ? '/' : '/setup')
  }
})

// Selhání ukázkových dat se dřív schovalo do žlutého warningu, přes který uživatel
// prošel dál a pak nechápal, proč je aplikace prázdná.
test('failed sample data generation is reported as an error, not a passing note', () => {
  assert.match(setup, /v-else-if="sampleError"[^>]*bg-danger-50/)
  assert.doesNotMatch(setup, /v-else-if="sampleError"[^>]*bg-warning-50/)
})

// Guard a /login se shodnou, ale nekonzistentní server (střídavé 200/401 na
// /api/auth/me) smyčku vyrobí i tak. Musí ji utnout pojistka — jinak nainstalovaná
// PWA zamrzne v okně bez adresního řádku, ze kterého se nedá odejít.
test('a redirect loop between / and /login is broken instead of spinning forever', () => {
  assert.match(
    router,
    /if \(!auth\.isAuthenticated\) \{[\s\S]*?if \(auth\.domainContext\?\.mode === 'canonical' && hasPendingCanonicalDomainLogin\(\)\) \{\s*return \{ name: 'login', query: pendingCanonicalDomainLoginQuery\(\) \?\? \{\} \}\s*\}\s*recordLoginBounce\(\)[\s\S]*?name: 'login'/,
  )
  assert.match(router, /export function loginRedirectLoopDetected\(\)/)
  assert.match(router, /export function clearLoginBounces\(\)/)
  // Počítadlo musí přežít tvrdý window.location redirect z api/client.ts.
  assert.match(router, /sessionStorage\.setItem\(BOUNCE_KEY/)

  assert.match(login, /if \(loginRedirectLoopDetected\(\)\) \{[\s\S]*?error\.value = t\('auth\.redirect_loop_detected'\)[\s\S]*?return/)
  // Pojistka musí stát PŘED automatickým návratem na `/`, jinak neutne nic.
  assert.ok(
    login.indexOf('loginRedirectLoopDetected()') < login.indexOf('if (!freshDomainLogin && auth.isAuthenticated) {'),
    'kontrola smyčky musí předcházet automatickému redirectu',
  )
  assert.match(
    login,
    /clearLoginBounces\(\)\s*\n\s*if \(await finishDomainLoginIfNeeded\(\)\) return\s*\n\s*router\.push/,
  )
})

// Service worker je vázaný na origin, ne na aplikaci. `http://localhost:8080` běžně
// recykluje víc projektů a cizí SW tam zůstane registrovaný — a když cachuje /api/,
// dostane klient střídavě 200 z cizí cache a 401 ze sítě.
test('a foreign service worker on the same origin is unregistered before ours registers', () => {
  assert.match(main, /navigator\.serviceWorker\.getRegistrations\(\)/)
  assert.match(main, /script !== ourScript \? registration\.unregister\(\) : undefined/)
  assert.ok(
    main.indexOf('getRegistrations()') < main.indexOf("navigator.serviceWorker.register('/service-worker.js'"),
    'cizí registrace se musí rušit dřív, než zaregistrujeme vlastní',
  )
})

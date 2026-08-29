import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'

const src = new URL('../src/', import.meta.url)
const manifest = JSON.parse(await readFile(new URL('../shared/client-route-policy.json', import.meta.url), 'utf8'))
const router = await readFile(new URL('router/index.ts', src), 'utf8')
const layout = await readFile(new URL('components/layout/AppLayout.vue', src), 'utf8')
const login = await readFile(new URL('pages/Login.vue', src), 'utf8')
const callback = await readFile(new URL('pages/DomainLoginCallback.vue', src), 'utf8')
const domainLogin = await readFile(new URL('security/domainLogin.ts', src), 'utf8')
const profile = await readFile(new URL('pages/PasswordChange.vue', src), 'utf8')
const lock = await readFile(new URL('components/SessionLockOverlay.vue', src), 'utf8')
const client = await readFile(new URL('api/client.ts', src), 'utf8')

test('shared manifest persists the audited client surface and legacy aliases', () => {
  // Přesný počet je brána proti nechtěnému rozšíření klientské plochy, ne
  // konstanta — s každou novou auditovanou routou se vědomě posouvá.
  assert.equal(manifest.routes.length, 37)
  assert.equal(new Set(manifest.routes.map(route => route.name)).size, 37)
  assert.deepEqual(
    manifest.routes.slice(-3).map(route => route.name),
    ['data-exchange', 'admin-export', 'admin-import'],
  )
})

test('navigation switches to the client experience for every locked custom domain', () => {
  assert.match(layout, /clientExperience = computed\(\(\) => usesClientNavigation\(auth\.isClientRole, auth\.domainContext\)\)/)
  assert.match(layout, /if \(clientExperience\.value\) \{\s*return filterNavigation/)
  assert.match(layout, /!clientExperience\.value && auth\.hasCommercialFeatures && auth\.canWrite\('accounting\.journal\.write'\)/)
  assert.match(layout, /!clientExperience\.value && !auth\.isDemo && auth\.canWrite\('purchase_invoices\.scan'\)/)
  assert.equal((layout.match(/v-if="!clientExperience" to="\/admin\/support"/g) || []).length, 2)
  assert.match(layout, /if \(!auth\.domainContext\?\.locked \|\| !canonicalBaseUrl\) return path/)
  assert.match(layout, /new URL\(canonicalBaseUrl\)\.origin/)
})

test('router and every login return carrier use the shared route policy', () => {
  assert.match(router, /clientDomainRedirect\(to\.name\)/)
  assert.match(router, /isClientDomainAuthenticatedPath\(to\.fullPath\)/)
  assert.match(router, /isClientDomainRouteName\(to\.name\)/)

  assert.match(login, /beginDomainLogin\(requestedDomainReturnPath\(\)\)/)
  assert.match(login, /isClientDomainAuthenticatedPath\(candidate\) \? candidate : '\/portal'/)
  assert.match(callback, /if \(!isClientDomainAuthenticatedPath\(result\.return_path\)\)/)
  assert.match(lock, /isClientDomainAuthenticatedPath\(route\.fullPath\) \? route\.fullPath : '\/portal'/)
  assert.match(client, /domainSupplierLock !== null && isClientDomainAuthenticatedPath\(returnPath\)/)
})

test('WebAuthn pages use a fixed canonical handoff and a separate custom-domain return', () => {
  const routes = new Map(manifest.routes.map(route => [route.name, route]))
  assert.equal(
    routes.get('profile-password')?.canonical_handoff?.to,
    '/profile/password?tab=passkeys',
  )
  assert.equal(routes.get('setup-mfa')?.canonical_handoff?.to, '/setup-mfa')

  assert.match(router, /clientDomainCanonicalHandoffPath\(to\.fullPath\)/)
  assert.match(router, /domain_handoff: to\.fullPath/)
  assert.match(login, /beginDomainLogin\(requestedDomainReturnPath\(\), domainHandoff\)/)
  assert.match(login, /route\.query\.domain_login_handoff/)
  assert.match(domainLogin, /canonicalDomainLoginHandoffPath\(handoffPath\)/)
  assert.match(profile, /authorizePendingDomainLogin\(\)/)
  assert.match(profile, /domain_login\.canonical_webauthn_notice/)
  assert.doesNotMatch(profile, /function setTab\([^)]*\) \{\s*tab\.value =/)
})

import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'

const root = new URL('../src/', import.meta.url)

test('passkey entry points use the shared WebAuthn capability check', async () => {
  const pages = [
    'pages/Login.vue',
    'pages/ApiTokens.vue',
    'pages/Passkeys.vue',
    'pages/ForcedMfaSetup.vue',
    'components/SessionLockOverlay.vue',
  ]

  for (const page of pages) {
    const source = await readFile(new URL(page, root), 'utf8')
    assert.match(source, /isWebAuthnAvailable/)
    assert.match(source, /passkeySupported/)
  }
})

test('login and locked-session UI expose a recovery message when WebAuthn is unavailable', async () => {
  const login = await readFile(new URL('pages/Login.vue', root), 'utf8')
  const overlay = await readFile(new URL('components/SessionLockOverlay.vue', root), 'utf8')

  assert.match(login, /passkey_unsupported_recovery/)
  assert.match(overlay, /session_lock\.unsupported/)
})

test('login clears one-time passkey flow for TOTP fallback and after failed verification', async () => {
  const login = await readFile(new URL('pages/Login.vue', root), 'utf8')
  const fallback = login.match(/function useTotpFallback\(\) \{([\s\S]*?)\n\}/)?.[1] || ''
  const verify = login.match(/async function verifyPasskey\([^)]*\) \{([\s\S]*?)\r?\n\}/)?.[1] || ''

  assert.match(fallback, /passkeyFlow\.value = null[\s\S]*totpRequired\.value = true/)
  assert.match(verify, /catch[\s\S]*passkeyFlow\.value = null[\s\S]*turnstile\.reset\(\)/)
  assert.match(login, /:disabled="[^"]*!!passkeyFlow/)
})

test('TOTP fallback survives a failed or cancelled passkey ceremony', async () => {
  const login = await readFile(new URL('pages/Login.vue', root), 'utf8')

  // Nabídnuté metody se drží mimo jednorázovou ceremony…
  assert.match(login, /const mfaMethods = ref<string\[\]>\(\[\]\)/)
  assert.match(
    login,
    /const canUseTotpFallback = computed\(\(\) => !totpRequired\.value && mfaMethods\.value\.includes\('totp'\)\)/,
  )
  // …a tlačítko na přepnutí visí na nich, ne na passkeyFlow.
  assert.match(login, /v-if="canUseTotpFallback"[\s\S]*use_totp_instead/)
  assert.doesNotMatch(login, /v-if="passkeyFlow\.methods\.includes\('totp'\)"/)
})

test('passkey MFA step keeps the captcha token instead of re-rendering the widget', async () => {
  const login = await readFile(new URL('pages/Login.vue', root), 'utf8')
  const branch = login.match(/code === 'mfa_required'\) \{([\s\S]*?)\} else if/)?.[1] || ''

  // Re-render Turnstile bere fokus dokumentu a prohlížeč pak WebAuthn dialog
  // nevykreslí — v této větvi se proto resetovat nesmí.
  assert.doesNotMatch(branch, /turnstile\.reset\(\)/)
  const fallback = login.match(/function useTotpFallback\(\) \{([\s\S]*?)\r?\n\}/)?.[1] || ''
  assert.match(fallback, /turnstile\.reset\(\)/)
  assert.match(fallback, /cancelActiveWebAuthnCeremony\(\)/)
})

test('TOTP code is required for the first passkey and hidden without TOTP', async () => {
  const passkeys = await readFile(new URL('pages/Passkeys.vue', root), 'utf8')

  // Bez TOTP se pole vůbec nevykreslí. Podmínka je nově `totpUsable`, ne `hasTotp`:
  // aktivní TOTP samo nestačí, musí ho připouštět i `auth.allowed_mfa_methods` —
  // jinak stránka nabízí ověření, které server odmítne („Nepovolený účel ověření").
  assert.match(passkeys, /v-if="totpUsable"/)
  assert.match(passkeys, /const totpUsable = computed\(\(\) => hasTotp\.value && totpAllowed\.value\)/)
  // …a s TOTP, ale bez existující passkey, je povinné (label, placeholder, disabled).
  assert.match(
    passkeys,
    /const totpRequired = computed\(\(\) => totpUsable\.value && list\.value\.length === 0\)/,
  )
  assert.match(passkeys, /totpRequired \? t\('passkeys\.totp_label_required'\)/)
  assert.match(passkeys, /:required="totpRequired"/)
  assert.match(passkeys, /totpRequired && !totpCodeValid/)
  // 409 passkey_unavailable se nesmí ukázat jako generická chyba.
  assert.match(passkeys, /code === 'passkey_unavailable'[\s\S]*totp_required_error/)
})

test('API token step-up offers both factors and keeps a passkey proof after unrelated errors', async () => {
  const tokens = await readFile(new URL('pages/ApiTokens.vue', root), 'utf8')

  assert.match(tokens, /const needsStepUp = computed\(\(\) => needsTotp\.value \|\| hasPasskey\.value\)/)
  // Passkey tlačítko i TOTP pole jsou v modalu současně; TOTP mizí až po ověření.
  assert.match(tokens, /v-if="hasPasskey"[\s\S]*verify_passkey/)
  assert.match(tokens, /v-if="needsTotp && !passkeyStepUpToken"/)
  // Proof se nezahazuje preventivně před requestem.
  assert.doesNotMatch(tokens, /const proof = passkeyStepUpToken\.value\r?\n\s*passkeyStepUpToken\.value = ''/)
  assert.match(tokens, /step_up_proof_invalid'\) \{\s*\r?\n?\s*passkeyStepUpToken\.value = ''/)
})

test('passwordless login asks for discoverable passkey options before verification', async () => {
  const login = await readFile(new URL('pages/Login.vue', root), 'utf8')
  const authApi = await readFile(new URL('api/auth.ts', root), 'utf8')
  const flow = login.match(/async function loginWithPasskey\(\) \{([\s\S]*?)\n\}/)?.[1] || ''

  assert.match(login, /auth\.setupStatus\?\.passwordless_login_enabled/)
  assert.match(login, /type="button"[\s\S]*?@click="loginWithPasskey"/)
  assert.match(flow, /authApi\.passkeyLoginOptions\(turnstile\.token\.value \|\| undefined\)/)
  assert.match(
    flow,
    /passkeyLoginOptions[\s\S]*getCredential\(flow\.public_key\)[\s\S]*passkeyLoginVerify\(flow\.flow_token, credential\)[\s\S]*auth\.refresh\(\)/,
  )
  assert.match(authApi, /post<WebAuthnFlow>\('\/auth\/webauthn\/login\/options'/)
})

test('security management renders inside the shared profile tabs', async () => {
  const profile = await readFile(new URL('pages/PasswordChange.vue', root), 'utf8')
  const router = [
    await readFile(new URL('router/index.ts', root), 'utf8'),
    await readFile(new URL('router/workspaceRoutes.ts', root), 'utf8'),
  ].join('\n')
  const authApi = await readFile(new URL('api/auth.ts', root), 'utf8')

  assert.match(profile, /type Tab = 'password' \| 'totp' \| 'passkeys' \| 'sso' \| 'session-lock' \| 'shortcuts'/)
  assert.match(profile, /<Passkeys\b[^>]*v-else-if="tab === 'passkeys'"[^>]*\/>/)
  assert.match(profile, /<KeyboardShortcuts v-else embedded \/>/)
  assert.match(profile, /sessionSecurity\.apply\(result\.session\)/)
  assert.match(profile, /<select[\s\S]*?class="md:hidden/)
  assert.match(profile, /class="hidden md:flex flex-wrap border-b/)
  assert.match(router, /profile\/passkeys[\s\S]+tab: 'passkeys'/)
  assert.match(router, /profile\/session-lock[\s\S]+tab: 'session-lock'/)
  assert.match(router, /profile\/shortcuts[\s\S]+tab: 'shortcuts'/)
  assert.match(authApi, /put<SessionLockPreferenceUpdate>\('\/auth\/session\/lock-preference'/)
})

test('passkey management fields have visible associated labels', async () => {
  const passkeys = await readFile(new URL('pages/Passkeys.vue', root), 'utf8')

  assert.match(passkeys, /const pageId = useId\(\)/)
  assert.match(passkeys, /<label :for="`\$\{pageId\}-passkey-label`"[\s\S]*?passkeys\.credential_label/)
  assert.match(passkeys, /<input :id="`\$\{pageId\}-passkey-label`"/)
  assert.match(passkeys, /<label :for="`\$\{pageId\}-passkey-current-password`"[\s\S]*?auth\.current_password/)
  assert.match(passkeys, /<input :id="`\$\{pageId\}-passkey-current-password`"/)
  assert.match(passkeys, /<label :for="`\$\{pageId\}-passkey-totp`"[\s\S]*?passkeys\.totp_label/)
  assert.match(passkeys, /<input :id="`\$\{pageId\}-passkey-totp`"/)
})

test('revoking a passkey refreshes the shared user profile and session security', async () => {
  const passkeys = await readFile(new URL('pages/Passkeys.vue', root), 'utf8')
  const revoke = passkeys.match(/async function revoke\(item: PasskeyCredential(?:, ssoProof = '')?\) \{([\s\S]*?)\r?\n\}/)?.[1] || ''

  assert.match(
    revoke,
    /passkeyRevoke[\s\S]*?await auth\.refresh\(\)[\s\S]*?await sessionSecurity\.refresh\(\{ force: true \}\)[\s\S]*?await load\(\)/,
  )
})

test('forced passkey setup uses existing TOTP as transition step-up', async () => {
  const setup = await readFile(new URL('pages/ForcedMfaSetup.vue', root), 'utf8')

  assert.match(setup, /auth\.user\?\.totp_enabled/)
  assert.match(setup, /totpStepUp\('passkey\.register'/)
  assert.match(setup, /authorization\.step_up_token/)
  assert.match(setup, /authorization\.current_password/)
  assert.match(setup, /await auth\.refresh\(\)[\s\S]*await sessionSecurity\.refresh\(\{ force: true \}\)/)
})

test('WebAuthn options are handed over as plain data, never as a reactive proxy', async () => {
  const webauthn = await readFile(new URL('security/webauthn.ts', root), 'utf8')
  const login = await readFile(new URL('pages/Login.vue', root), 'utf8')

  // Options putují mezi světy přes CustomEvent a strukturovaný klon; Proxy se
  // naklonovat nedá, detail dorazí jako null a obal správce hesel spadne.
  assert.match(webauthn, /function plainJson/)
  assert.match(webauthn, /const options = plainJson\(source\)/)
  assert.match(login, /publicKey: markRaw\(data\.public_key\)/)
})

test('forced MFA setup offers only methods the server allows', async () => {
  const setup = await readFile(new URL('pages/ForcedMfaSetup.vue', root), 'utf8')

  // Politiku bere stránka z /me, ne z optimistické hodnoty ve storu — jinak by
  // nabídla metodu, kterou API zamítne 403 mfa_method_not_allowed.
  const mounted = setup.match(/onMounted\(async \(\) => \{([\s\S]*?)\n\}\)/)?.[1] || ''
  assert.match(mounted, /await auth\.refresh\(\)/)
  assert.match(setup, /const method = ref<'passkey' \| 'totp' \| null>\(null\)/)
  assert.match(setup, /if \(!allowed\.value\.includes\(next\)\) return/)
  // Žádná metoda povolená → srozumitelná hláška, ne prázdný box.
  assert.match(setup, /method === null[\s\S]*no_method_available/)
})

test('setup wizard does not pin allowed MFA methods per instance', async () => {
  const wizard = await readFile(new URL('pages/Setup.vue', root), 'utf8')

  // Zaškrtávátka metod jsou pryč (footgun: TOTP-only config + nabídnutá passkey),
  // wizard posílá jen require_mfa a seznam metod zůstává na cfg.php.
  assert.doesNotMatch(wizard, /allowed_mfa_methods/)
  assert.doesNotMatch(wizard, /allowedMfaMethods/)
  assert.match(wizard, /require_mfa: requireMfa\.value/)
  // Politika se do storu nesmí propsat z odpovědi setupu (nese požadavek, ne config).
  assert.doesNotMatch(wizard, /auth\.setMfaPolicy\(/)
})

test('cancelling a ceremony rejects the pending promise instead of only bumping the generation', async () => {
  const webauthn = await readFile(new URL('security/webauthn.ts', root), 'utf8')
  const cancel = webauthn.match(/export function cancelActiveWebAuthnCeremony\(\): void \{([\s\S]*?)\n\}/)?.[1] || ''

  // Bez odmítnutí promise by volající po „Zrušit" visel dál na ceremonii,
  // která nikdy nedoběhne — přesně ta situace, co vypadá jako zatuhlá appka.
  assert.match(cancel, /reject\?\.\(new Error\('webauthn_cancelled'\)\)/)
  assert.match(cancel, /activeWebAuthnCeremony\.value = null/)
  assert.match(webauthn, /Promise\.race\(\[ceremony, timeout, cancelled\]\)/)
  // Úklid nesmí přebít novější ceremonii.
  assert.match(webauthn, /finally \{[\s\S]*if \(isCurrentCeremony\(generation\)\) activeWebAuthnCeremony\.value = null/)
})

test('a stalled security dialog surfaces a hint with a way out', async () => {
  const prompt = await readFile(new URL('components/WebAuthnCeremonyPrompt.vue', root), 'utf8')
  const app = await readFile(new URL('App.vue', root), 'utf8')

  assert.match(prompt, /activeWebAuthnCeremony/)
  assert.match(prompt, /cancelActiveWebAuthnCeremony\(\)/)
  assert.match(prompt, /waiting_hint_extension/)
  // Globálně, ať to platí pro login, odemčení zámku i registraci klíče.
  assert.match(app, /<WebAuthnCeremonyPrompt \/>/)
})

test('every passkey entry point explains a timed-out ceremony', async () => {
  for (const page of ['pages/Login.vue', 'pages/ForcedMfaSetup.vue', 'pages/Passkeys.vue', 'pages/ApiTokens.vue']) {
    const source = await readFile(new URL(page, root), 'utf8')
    assert.match(source, /webAuthnErrorKey/, page)
  }
})

test('passkey management honours the server MFA policy instead of guessing', async () => {
  const page = await readFile(new URL('pages/Passkeys.vue', root), 'utf8')

  // Bez čtení politiky nabízela stránka operace, které API vzápětí odmítlo:
  // registraci při vypnutých passkeys (403) i TOTP step-up při vypnutém TOTP (400).
  assert.match(page, /const passkeyAllowed = computed\(\(\) => auth\.allowedMfaMethods\.includes\('passkey'\)\)/)
  assert.match(page, /const totpAllowed = computed\(\(\) => auth\.allowedMfaMethods\.includes\('totp'\)\)/)
  assert.match(page, /const totpUsable = computed\(\(\) => hasTotp\.value && totpAllowed\.value\)/)
  assert.match(page, /v-if="passkeyAllowed"/)
  assert.match(page, /v-if="!passkeyAllowed"[\s\S]*method_not_allowed/)
  // TOTP pole jen když je TOTP reálně použitelné, ne jen aktivní.
  assert.match(page, /v-if="totpUsable"/)
})

test('revoking the last strong factor is blocked before the step-up, not after it', async () => {
  const page = await readFile(new URL('pages/Passkeys.vue', root), 'utf8')
  const blocked = page.match(/const revokeBlocked = computed\(\(\) =>([\s\S]*?)\r?\n\)/)?.[1] || ''

  // Dřív se uživatel o 409 last_mfa_factor dozvěděl až POTÉ, co prošel celou
  // ceremonií nebo spotřeboval jednorázový TOTP kód.
  assert.match(blocked, /auth\.requireMfa/)
  assert.match(blocked, /list\.value\.length === 1/)
  assert.match(blocked, /totpAllowed\.value && hasTotp\.value/)
  // Bez místní metody zbývá ověření přes Microsoft, je-li identita propojená.
  assert.match(page, /:disabled="busy \|\| revokeBlocked \|\| \(stepUpMethods\.length === 0 && !ssoAvailable\)"/)
  assert.match(page, /revoke_last_factor/)
})

test('the revoke row explains which factor confirms the removal', async () => {
  const page = await readFile(new URL('pages/Passkeys.vue', root), 'utf8')
  const cs = JSON.parse(await readFile(new URL('i18n/cs.json', root), 'utf8'))

  // Jádro stížnosti: mazání vyžadovalo step-up, TOTP kód byl použitelná alternativa,
  // a u tlačítka Odebrat o tom nestálo nic.
  assert.match(page, /revoke_hint_totp/)
  assert.match(page, /revoke_hint_passkey/)
  assert.match(page, /revoke_no_method/)
  assert.match(cs.passkeys.revoke_hint_totp, /TOTP/)
  // Pole s kódem se už netváří jako nepovinná ozdoba.
  assert.doesNotMatch(cs.passkeys.totp_label, /volitelně/i)
  assert.doesNotMatch(cs.passkeys.totp_optional, /volitelná/i)
})

test('the second-factor step hides the passwordless passkey entry point', async () => {
  const login = await readFile(new URL('pages/Login.vue', root), 'utf8')

  // Jádro stížnosti: uživatel bez passkey viděl vedle pole na TOTP kód i tlačítko
  // „přihlásit se passkey". Je to alternativa k HESLU, ne k druhému faktoru —
  // po ověření hesla nemá co nabízet.
  assert.match(
    login,
    /const secondFactorPending = computed\(\s*\r?\n?\s*\(\) => totpRequired\.value \|\| recoveryRequired\.value \|\| emailOtpRequired\.value \|\| passkeyFlow\.value !== null,/,
  )
  assert.match(
    login,
    /v-if="auth\.setupStatus\?\.passwordless_login_enabled && !secondFactorPending(?: && !ssoTicket)?"/,
  )
})

test('the login second factor offers exactly the methods the server named', async () => {
  const login = await readFile(new URL('pages/Login.vue', root), 'utf8')
  const totpBranch = login.match(/code === 'totp_required'\) \{([\s\S]*?)\} else if/)?.[1] || ''
  const invalidBranch = login.match(/code === 'invalid_totp'\) \{([\s\S]*?)\} else if/)?.[1] || ''

  // Seznam metod se nikdy neodvozuje na klientovi — přebírá se z odpovědi serveru,
  // která vzniká až po ověření hesla a jen z uloženého stavu daného účtu.
  assert.match(totpBranch, /mfaMethods\.value = Array\.isArray\(data\?\.methods\) \? data\.methods : \['totp'\]/)
  assert.match(invalidBranch, /mfaMethods\.value = Array\.isArray\(data\?\.methods\) \? data\.methods : \['totp'\]/)
  // Jediná metoda → rovnou pole na kód, žádný mezikrok s výběrem.
  assert.match(totpBranch, /totpRequired\.value = true/)
  assert.match(totpBranch, /focusField\(totpInput\)/)
  assert.match(login, /ref="totpInput"[\s\S]*?inputmode="numeric"[\s\S]*?autocomplete="one-time-code"/)
  // Záložní kód má vlastní nabídku i tehdy, když passkey ani TOTP fallback není.
  assert.match(login, /v-if="passkeyFlow \|\| canUseTotpFallback \|\| canUseRecovery"/)
})

test('a cancelled ceremony during revoke points at the TOTP alternative', async () => {
  const page = await readFile(new URL('pages/Passkeys.vue', root), 'utf8')
  const revoke = page.match(/async function revoke\(item: PasskeyCredential(?:, ssoProof = '')?\) \{([\s\S]*?)\r?\n\}/)?.[1] || ''

  // Zrušený dialog není „operace se nepodařila" — uživatel typicky klíč nemá.
  assert.match(revoke, /webAuthnErrorKey\(e\)/)
  assert.match(revoke, /revoke_totp_fallback/)
  assert.match(revoke, /revoke_needs_totp/)
  // Zapomenutý kód z minulého pokusu nesmí tiše přepnout metodu ověření.
  assert.match(page, /if \(totpCodeValid\.value && totpUsable\.value\)/)
})

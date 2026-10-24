<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import AppShell from '@/components/layout/AppShell.vue'
import RecoveryCodesOnce from '@/components/security/RecoveryCodesOnce.vue'
import { BTN_BASE, ICONS, OUTLINE } from '@/components/ui/buttonStyles'
import TotpAppRecommendation from '@/components/security/TotpAppRecommendation.vue'
import { authApi, type TotpSetup } from '@/api/auth'
import { createCredential, getCredential, isWebAuthnAvailable, webAuthnErrorKey } from '@/security/webauthn'
import { useAuthStore } from '@/stores/auth'
import { useSessionSecurityStore } from '@/stores/sessionSecurity'
import {
  advanceCanonicalDomainLoginSession,
  authorizePendingDomainLogin,
  canonicalDomainLoginSessionCsrf,
  hasPendingCanonicalDomainLogin,
  isDomainLoginConfirmationRequired,
  pendingCanonicalDomainLoginQuery,
} from '@/security/domainLogin'
import { takeTotpEnrollment } from '@/security/totpEnrollment'
import { testRegisteredPasskey } from '@/security/passkeyTest'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const sessionSecurity = useSessionSecurityStore()

const method = ref<'passkey' | 'totp' | null>(null)
const policyReady = ref(false)
const busy = ref(false)
const error = ref('')
const currentPassword = ref('')
const enrollment = ref(takeTotpEnrollment(auth.user?.id))
const passkeyLabel = ref('')
const totpSetup = ref<TotpSetup | null>(null)
// ⚠️ Sada přijde ze zapnutí TOTP a jde ukázat PRÁVĚ JEDNOU — server plaintext
// neukládá. Dokud ji uživatel nepotvrdí, setup se nedokončí.
const recoveryCodes = ref<string[] | null>(null)
// Klíč neprošel zkušebním ověřením a server ho odvolal. Po potvrzení kódů se
// pak nepokračuje do aplikace, uživatel zůstane tady a zvolí faktor znovu.
const passkeyRevoked = ref(false)
const totpCode = ref('')

async function continueAfterSetup(): Promise<void> {
  if (hasPendingCanonicalDomainLogin()) {
    try {
      await authorizePendingDomainLogin()
    } catch (e) {
      if (!isDomainLoginConfirmationRequired(e)) throw e
      // Potvrzení předání na vlastní doménu zobrazuje přihlašovací stránka.
      await router.replace({ name: 'login', query: pendingCanonicalDomainLoginQuery() ?? {} })
    }
    return
  }
  await router.replace(auth.isInstanceAdmin ? '/instance-admin' : '/')
}

// Vynucená varianta odsud nepustí jinam než odhlášením; nabídka ano. Rozhoduje
// politika ze serveru, ne to, že uživatel na stránce zrovna stojí — jinak by se
// tlačítko „pokračovat bez ověření" objevilo i tam, kde je MFA povinná.
const offerOnly = computed(() => !auth.mustSetupMfa && !auth.mustSetupTotp && auth.shouldOfferMfa)

const allowed = computed<Array<'passkey' | 'totp'>>(() => auth.allowedMfaMethods.length > 0
  ? auth.allowedMfaMethods
  : (auth.mustSetupTotp ? ['totp'] : []))
const passkeyAllowed = computed(() => allowed.value.includes('passkey'))
const totpAllowed = computed(() => allowed.value.includes('totp'))
const passkeySupported = isWebAuthnAvailable()
const passkeyRequiresTotpStepUp = computed(() => auth.user?.totp_enabled === true)
const passkeyAuthorizationReady = computed(() => passkeyRequiresTotpStepUp.value
  ? /^\d{6}$/.test(totpCode.value)
  : currentPassword.value.length > 0)

// Zrcadlo `passkeyRequiresTotpStepUp` pro opačný směr: `/auth/totp/setup` teď
// taky vyžaduje čerstvé ověření, a má-li uživatel už passkey, ověří se jí
// (step-up), ne heslem — stejný mechanismus jako u registrace nové passkey.
const totpRequiresPasskeyStepUp = computed(() =>
  auth.user?.mfa_methods?.includes('passkey') === true
  || (auth.user?.passkey_count ?? 0) > 0,
)

async function selectMethod(next: 'passkey' | 'totp') {
  if (!allowed.value.includes(next)) return
  method.value = next
  error.value = ''
}

async function completePasskey() {
  const authorization: { current_password?: string; step_up_token?: string } = {}
  if (passkeyRequiresTotpStepUp.value) {
    if (!/^\d{6}$/.test(totpCode.value)) {
      error.value = t('mfa_setup.transition_totp_required')
      return
    }
  } else {
    if (!currentPassword.value) {
      error.value = t('mfa_setup.password_required')
      return
    }
    authorization.current_password = currentPassword.value
  }
  busy.value = true
  error.value = ''
  try {
    if (passkeyRequiresTotpStepUp.value) {
      authorization.step_up_token = await authApi.totpStepUp('passkey.register', totpCode.value)
    }
    const flow = await authApi.passkeyRegisterOptions(authorization)
    const credential = await createCredential(flow.public_key)
    const previousCsrf = canonicalDomainLoginSessionCsrf()
    const result = await authApi.passkeyRegisterVerify(
      flow.flow_token,
      credential,
      passkeyLabel.value.trim() || t('mfa_setup.default_passkey_label'),
      previousCsrf ?? undefined,
    )
    if (result.csrf_token) {
      if (previousCsrf) advanceCanonicalDomainLoginSession(previousCsrf, result.csrf_token)
      auth.setSessionCsrfToken(result.csrf_token)
    }
    // Klíč, který správce uložil, ale neumí jím ověřit, by uživatele zamkl hned
    // při dalším přihlášení. Ověří se teď; když neprojde, server ho odvolá.
    const revoked = await testRegisteredPasskey(result.credential?.id) === 'revoked'
    await auth.refresh()
    await sessionSecurity.refresh({ force: true })
    passkeyRevoked.value = revoked
    if (revoked) error.value = t('passkeys.test_revoked')
    // ⚠️ Stejně jako u TOTP: kódy PŘED dokončením setupu, jinak je odnaviguje
    // pryč a uživatel skončí s klíčem, ale bez jediné cesty zpět. Patří uživateli,
    // ne klíči, takže se ukazují i po odvolání nefunkčního klíče.
    if (result.recovery_codes?.length) {
      recoveryCodes.value = result.recovery_codes
      return
    }
    if (revoked) return
    await continueAfterSetup()
  } catch (e: any) {
    const ceremonyError = webAuthnErrorKey(e)
    error.value = ceremonyError !== null
      ? t(ceremonyError)
      : e?.response?.data?.error?.message || t('auth.passkey_failed')
  } finally {
    busy.value = false
  }
}

function applyTotpSetupError(e: any) {
  const code = e?.response?.data?.error?.code
  if (code === 'enrollment_authorization_invalid') {
    error.value = t('mfa_setup.password_required')
  } else if (code === 'current_password_invalid') {
    error.value = e?.response?.data?.error?.message || t('auth.current_password_invalid')
  } else if (code === 'already_enabled') {
    error.value = e?.response?.data?.error?.message || t('auth.totp_already_enabled')
  } else if (code === 'too_many_attempts') {
    error.value = e?.response?.data?.error?.message || t('auth.too_many_attempts')
  } else if (code === 'mfa_method_not_allowed') {
    error.value = e?.response?.data?.error?.message || t('auth.mfa_method_not_allowed')
  } else {
    const ceremonyError = webAuthnErrorKey(e)
    error.value = ceremonyError !== null
      ? t(ceremonyError)
      : e?.response?.data?.error?.message || t('common.error')
  }
}

async function startTotp() {
  error.value = ''
  if (enrollment.value && enrollment.value.expiresAt <= Date.now()) enrollment.value = null
  // Passkey ověří majitele přímo, žádné heslo netřeba — stejný mechanismus
  // jako step-up při registraci nové passkey, jen s opačnou rolí faktorů.
  if (totpRequiresPasskeyStepUp.value) {
    busy.value = true
    try {
      const flow = await authApi.passkeyStepUpOptions('totp.enable')
      const credential = await getCredential(flow.public_key)
      const stepUpToken = await authApi.passkeyStepUpVerify(flow.flow_token, 'totp.enable', credential)
      totpSetup.value = await authApi.totpSetup({ step_up_token: stepUpToken })
    } catch (e: any) {
      applyTotpSetupError(e)
    } finally {
      busy.value = false
    }
    return
  }
  if (!enrollment.value && !currentPassword.value) {
    error.value = t('mfa_setup.password_required')
    return
  }
  busy.value = true
  try {
    const authorization = enrollment.value
      ? { enrollment_token: enrollment.value.token }
      : { current_password: currentPassword.value }
    enrollment.value = null
    totpSetup.value = await authApi.totpSetup(authorization)
    currentPassword.value = ''
  } catch (e: any) {
    applyTotpSetupError(e)
  } finally {
    busy.value = false
  }
}

async function completeTotp() {
  if (!/^\d{6}$/.test(totpCode.value)) {
    error.value = t('auth.totp_invalid')
    return
  }
  busy.value = true
  error.value = ''
  try {
    const previousCsrf = canonicalDomainLoginSessionCsrf()
    const result = await authApi.totpEnable(totpCode.value, previousCsrf ?? undefined)
    if (result.csrf_token) {
      if (previousCsrf) advanceCanonicalDomainLoginSession(previousCsrf, result.csrf_token)
      auth.setSessionCsrfToken(result.csrf_token)
    }
    await auth.refresh()
    // ⚠️ Kódy se ukazují PŘED dokončením setupu. Kdyby se pokračovalo rovnou,
    // odnavigovalo by je pryč a uživatel by je už nikde nenašel — druhý faktor
    // by měl zapnutý bez jakéhokoliv break-glass.
    if (result.recovery_codes?.length) {
      recoveryCodes.value = result.recovery_codes
      return
    }
    await continueAfterSetup()
  } catch (e: any) {
    const code = e?.response?.data?.error?.code
    error.value = code === 'already_enabled'
      ? e?.response?.data?.error?.message || t('auth.totp_already_enabled')
      : e?.response?.data?.error?.message || t('auth.totp_invalid')
    if (code === 'already_enabled') {
      totpSetup.value = null
      totpCode.value = ''
      let refreshed = false
      try {
        refreshed = await auth.refresh()
      } catch {
        refreshed = false
      }
      if (refreshed && !auth.mustSetupMfa && !auth.mustSetupTotp) {
        await continueAfterSetup()
        return
      }
      error.value = t('mfa_setup.already_enabled_relogin')
    } else if (code === 'enrollment_stale' || code === 'no_secret') {
      totpSetup.value = null
      totpCode.value = ''
    }
  } finally {
    busy.value = false
  }
}

async function confirmRecoveryCodes() {
  if (busy.value) return
  if (passkeyRevoked.value) {
    recoveryCodes.value = null
    passkeyRevoked.value = false
    return
  }
  busy.value = true
  error.value = ''
  try {
    await continueAfterSetup()
  } catch (e: any) {
    error.value = e?.response?.data?.error?.message || t('domain_login.authorize_failed')
  } finally {
    busy.value = false
  }
}

async function skipMfa() {
  if (busy.value || !offerOnly.value) return
  busy.value = true
  error.value = ''
  try {
    // Odmítnutí se ukládá na serveru (users.mfa_offer_dismissed_at), takže
    // nabídka nepřežije ani odhlášení. Bez `refresh()` by store dál hlásil
    // `should_offer_mfa` a router by stránku pořád držel otevřenou.
    await authApi.dismissMfaOffer()
    await auth.refresh()
    await continueAfterSetup()
  } catch (e: any) {
    error.value = e?.response?.data?.error?.message || t('common.error')
  } finally {
    busy.value = false
  }
}

async function logout() {
  if (busy.value) return
  busy.value = true
  error.value = ''
  try {
    await auth.logout()
    await router.replace('/login')
  } catch {
    error.value = t('auth.logout_failed')
  } finally {
    sessionSecurity.clear()
    busy.value = false
  }
}

onMounted(async () => {
  // Povolené metody bere stránka VÝHRADNĚ ze serveru (/me). Kdyby ve storu zůstala
  // starší/optimistická hodnota (např. z odpovědi setup wizardu), nabídli bychom
  // metodu, kterou API zamítne 403 mfa_method_not_allowed.
  await auth.refresh()
  const requested = route.query.method === 'totp' ? 'totp' : 'passkey'
  if (requested === 'passkey' && passkeyAllowed.value && passkeySupported) {
    method.value = 'passkey'
  } else if (totpAllowed.value) {
    await selectMethod('totp')
  } else if (passkeyAllowed.value) {
    // Jediná povolená metoda je passkey, kterou prohlížeč neumí — ať uživatel
    // vidí proč, ne prázdnou stránku.
    method.value = 'passkey'
  }
  policyReady.value = true
})
</script>

<template>
  <AppShell :title="offerOnly ? t('mfa_setup.offer_title') : t('mfa_setup.title')">
    <div class="w-full max-w-lg">
      <div :class="['bg-surface border rounded-lg shadow-sm p-6 space-y-5',
                    offerOnly ? 'border-primary-500/40' : 'border-warning-300']">
        <div>
          <h1 :class="['text-lg font-semibold', offerOnly ? 'text-primary-700' : 'text-warning-700']">
            {{ offerOnly ? t('mfa_setup.offer_title') : t('mfa_setup.title') }}
          </h1>
          <p class="text-sm text-neutral-600 mt-1">
            {{ offerOnly ? t('mfa_setup.offer_intro') : t('mfa_setup.intro') }}
          </p>
        </div>

        <!-- Nad zobrazenými kódy už není co vybírat — faktor je zapnutý. -->
        <div v-if="!recoveryCodes && passkeyAllowed && totpAllowed" class="grid grid-cols-2 gap-2">
          <button type="button" @click="selectMethod('passkey')"
            :class="['h-10 rounded-md border font-medium', method === 'passkey' ? 'border-primary-600 bg-primary-50 text-primary-700' : 'border-neutral-300']">
            {{ t('mfa_setup.passkey') }}
          </button>
          <button type="button" @click="selectMethod('totp')"
            :class="['h-10 rounded-md border font-medium', method === 'totp' ? 'border-primary-600 bg-primary-50 text-primary-700' : 'border-neutral-300']">
            {{ t('mfa_setup.totp') }}
          </button>
        </div>

        <div v-if="!recoveryCodes && method === 'passkey' && passkeyAllowed" class="space-y-4">
          <p class="text-sm text-neutral-600">{{ t('mfa_setup.passkey_hint') }}</p>
          <p v-if="!passkeySupported" class="rounded-md bg-warning-50 px-3 py-2 text-sm text-warning-700">
            {{ t('mfa_setup.passkey_unsupported') }}
          </p>
          <template v-else>
            <input v-model="passkeyLabel" maxlength="100"
              :placeholder="t('mfa_setup.passkey_label')"
              class="w-full h-10 px-3 border border-neutral-300 rounded-md" />
            <template v-if="passkeyRequiresTotpStepUp">
              <p class="text-sm text-neutral-600">{{ t('mfa_setup.transition_totp_hint') }}</p>
              <input v-model="totpCode" inputmode="numeric" autocomplete="one-time-code"
                maxlength="6" pattern="\d{6}" :placeholder="t('auth.totp_code')"
                class="w-full h-10 px-3 border border-neutral-300 rounded-md font-mono text-lg tracking-widest text-center"
                @keydown.enter="completePasskey" />
            </template>
            <input v-else v-model="currentPassword" type="password" autocomplete="current-password"
              :placeholder="t('auth.current_password')"
              class="w-full h-10 px-3 border border-neutral-300 rounded-md"
              @keydown.enter="completePasskey" />
            <button type="button" @click="completePasskey" :disabled="busy || !passkeyAuthorizationReady"
              class="w-full h-10 bg-primary-600 hover:bg-primary-700 disabled:bg-neutral-300 text-white font-medium rounded-md">
              {{ busy ? t('auth.passkey_verifying') : t('mfa_setup.create_passkey') }}
            </button>
          </template>
        </div>

        <!-- ⚠️ Záložní kódy překryjí všechno ostatní. Zobrazí se jen jednou
             a bez potvrzení se ze setupu nepokračuje — jinak by uživatel odešel
             se zapnutým druhým faktorem a bez jediné cesty zpět, kdyby o něj
             přišel. -->
        <RecoveryCodesOnce
          v-if="recoveryCodes"
          :codes="recoveryCodes"
          :busy="busy"
          @confirm="confirmRecoveryCodes"
        />

        <div v-else-if="method === 'totp' && totpAllowed" class="space-y-4">
          <template v-if="totpSetup">
            <TotpAppRecommendation />
            <div class="flex justify-center bg-neutral-50 rounded-md p-4">
              <img :src="totpSetup.qr_data_uri" :alt="totpSetup.uri" class="border border-neutral-200 rounded" />
            </div>
            <details class="text-xs text-neutral-500">
              <summary class="cursor-pointer">{{ t('auth.totp_setup_step1_alt') }}</summary>
              <code class="block mt-2 p-2 bg-neutral-100 rounded break-all select-all">{{ totpSetup.secret }}</code>
            </details>
            <input v-model="totpCode" inputmode="numeric" autocomplete="one-time-code"
              maxlength="6" pattern="\d{6}" placeholder="000000"
              class="w-full h-10 px-3 border border-neutral-300 rounded-md font-mono text-lg tracking-widest text-center"
              @keydown.enter="completeTotp" />
            <button type="button" @click="completeTotp" :disabled="busy || totpCode.length !== 6"
              class="w-full h-10 bg-primary-600 hover:bg-primary-700 disabled:bg-neutral-300 text-white font-medium rounded-md">
              {{ busy ? '…' : t('auth.totp_force_enable_btn') }}
            </button>
          </template>
          <!-- Stejně jako u passkey: čerstvé ověření nejdřív, secret až potom —
               bez passkey heslem, s ní step-up ceremonií pro 'totp.enable'. -->
          <template v-else>
            <input v-if="!totpRequiresPasskeyStepUp && !enrollment" v-model="currentPassword" type="password"
              autocomplete="current-password" :placeholder="t('auth.current_password')"
              data-test="totp-current-password"
              class="w-full h-10 px-3 border border-neutral-300 rounded-md"
              @keydown.enter="startTotp" />
            <button type="button" @click="startTotp" :disabled="busy" data-test="totp-start"
              class="w-full h-10 bg-primary-600 hover:bg-primary-700 disabled:bg-neutral-300 text-white font-medium rounded-md">
              {{ busy ? '…' : (totpRequiresPasskeyStepUp ? t('auth.totp_setup_verify_passkey_btn') : t('auth.totp_setup_btn')) }}
            </button>
          </template>
        </div>

        <div v-if="!policyReady" class="text-center text-sm text-neutral-500 py-4">{{ t('common.loading') }}…</div>
        <p v-else-if="method === null" class="rounded-md bg-warning-50 px-3 py-2 text-sm text-warning-700">
          {{ t('mfa_setup.no_method_available') }}
        </p>

        <p v-if="error" class="rounded-md bg-danger-50 border border-danger-500/40 px-3 py-2 text-sm text-danger-500">
          {{ error }}
        </p>

        <div class="pt-4 border-t border-neutral-200 flex flex-wrap gap-3 justify-between items-center">
          <p class="text-xs text-neutral-500 max-w-xs">
            {{ offerOnly ? t('mfa_setup.offer_skip_hint') : t('mfa_setup.logout_hint') }}
          </p>
          <button v-if="offerOnly" type="button" data-test="mfa-skip" @click="skipMfa" :disabled="busy"
                  :class="[BTN_BASE, OUTLINE.neutral]">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" />
            </svg>
            {{ t('mfa_setup.offer_skip') }}
          </button>
          <button v-else type="button" data-test="mfa-logout" @click="logout" :disabled="busy"
                  class="text-sm text-neutral-600 hover:text-neutral-800 underline disabled:opacity-60">
            {{ t('auth.logout') }}
          </button>
        </div>
      </div>
    </div>
  </AppShell>
</template>

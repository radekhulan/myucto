<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import AppShell from '@/components/layout/AppShell.vue'
import { BTN_BASE, ICONS, OUTLINE } from '@/components/ui/buttonStyles'
import { authApi, type TotpSetup } from '@/api/auth'
import { createCredential, isWebAuthnAvailable, webAuthnErrorKey } from '@/security/webauthn'
import { useAuthStore } from '@/stores/auth'
import { useSessionSecurityStore } from '@/stores/sessionSecurity'
import { authorizePendingDomainLogin, hasPendingCanonicalDomainLogin } from '@/security/domainLogin'

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
const passkeyLabel = ref('')
const totpSetup = ref<TotpSetup | null>(null)
const totpCode = ref('')

async function continueAfterSetup(): Promise<void> {
  if (hasPendingCanonicalDomainLogin()) {
    await authorizePendingDomainLogin()
    return
  }
  await router.replace('/')
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

async function selectMethod(next: 'passkey' | 'totp') {
  if (!allowed.value.includes(next)) return
  method.value = next
  error.value = ''
  if (next === 'totp' && !totpSetup.value) {
    await startTotp()
  }
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
    const result = await authApi.passkeyRegisterVerify(
      flow.flow_token,
      credential,
      passkeyLabel.value.trim() || t('mfa_setup.default_passkey_label'),
    )
    if (result.csrf_token) auth.setSessionCsrfToken(result.csrf_token)
    await auth.refresh()
    await sessionSecurity.refresh({ force: true })
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

async function startTotp() {
  busy.value = true
  error.value = ''
  try {
    totpSetup.value = await authApi.totpSetup()
  } catch (e: any) {
    error.value = e?.response?.data?.error?.message || t('common.error')
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
    const result = await authApi.totpEnable(totpCode.value)
    if (result.csrf_token) auth.setSessionCsrfToken(result.csrf_token)
    await auth.refresh()
    await continueAfterSetup()
  } catch (e: any) {
    error.value = e?.response?.data?.error?.message || t('auth.totp_invalid')
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

        <div v-if="passkeyAllowed && totpAllowed" class="grid grid-cols-2 gap-2">
          <button type="button" @click="selectMethod('passkey')"
            :class="['h-10 rounded-md border font-medium', method === 'passkey' ? 'border-primary-600 bg-primary-50 text-primary-700' : 'border-neutral-300']">
            {{ t('mfa_setup.passkey') }}
          </button>
          <button type="button" @click="selectMethod('totp')"
            :class="['h-10 rounded-md border font-medium', method === 'totp' ? 'border-primary-600 bg-primary-50 text-primary-700' : 'border-neutral-300']">
            {{ t('mfa_setup.totp') }}
          </button>
        </div>

        <div v-if="method === 'passkey' && passkeyAllowed" class="space-y-4">
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

        <div v-if="method === 'totp' && totpAllowed" class="space-y-4">
          <template v-if="totpSetup">
            <p class="text-sm text-neutral-700">{{ t('auth.totp_setup_step1') }}</p>
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
          <div v-else class="text-center text-sm text-neutral-500 py-4">{{ t('common.loading') }}…</div>
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

<script setup lang="ts">
/**
 * Profil uživatele — záložky:
 *   - Heslo  (změna hesla, self-service)
 *   - 2FA    (TOTP setup, status, aktivace)
 *   - Passkeys (registrace a správa přístupových klíčů)
 *   - Zámek aplikace (osobní interval nečinnosti)
 *   - Klávesové zkratky (osobní ovládání aplikace)
 */
import { ref, computed, onMounted, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { authApi, type SessionLockPreference, type TotpSetup } from '@/api/auth'
import { useToast } from '@/composables/useToast'
import { apiErrorMessage } from '@/api/errors'
import { useAuthStore } from '@/stores/auth'
import { useSessionSecurityStore } from '@/stores/sessionSecurity'
import Passkeys from '@/pages/Passkeys.vue'
import KeyboardShortcuts from '@/pages/KeyboardShortcuts.vue'
import { getCredential, webAuthnErrorKey } from '@/security/webauthn'
import RecoveryCodesOnce from '@/components/security/RecoveryCodesOnce.vue'
import TotpAppRecommendation from '@/components/security/TotpAppRecommendation.vue'
import {
  advanceCanonicalDomainLoginSession,
  authorizePendingDomainLogin,
  canonicalDomainLoginSessionCsrf,
  hasPendingCanonicalDomainLogin,
  isDomainLoginConfirmationRequired,
  pendingCanonicalDomainLoginQuery,
} from '@/security/domainLogin'

const { t } = useI18n()
const toast = useToast()
const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const sessionSecurity = useSessionSecurityStore()
const domainReturnPending = ref(hasPendingCanonicalDomainLogin())
const domainReturnBusy = ref(false)
const domainReturnError = ref('')

async function returnToClientDomain() {
  if (!domainReturnPending.value || domainReturnBusy.value) return
  domainReturnBusy.value = true
  domainReturnError.value = ''
  try {
    await authorizePendingDomainLogin()
  } catch (e: any) {
    if (isDomainLoginConfirmationRequired(e)) {
      // Potvrzení předání na vlastní doménu zobrazuje přihlašovací stránka.
      await router.replace({ name: 'login', query: pendingCanonicalDomainLoginQuery() ?? {} })
      return
    }
    domainReturnPending.value = hasPendingCanonicalDomainLogin()
    domainReturnError.value = e?.response?.data?.error?.message || t('domain_login.authorize_failed')
  } finally {
    domainReturnBusy.value = false
  }
}

type Tab = 'password' | 'totp' | 'passkeys' | 'session-lock' | 'shortcuts'
function tabFromQuery(value: unknown): Tab {
  return value === 'totp' || value === 'passkeys' || value === 'session-lock' || (value === 'shortcuts' && !auth.isInstanceAdmin)
    ? value
    : 'password'
}
const tab = ref<Tab>(tabFromQuery(route.query.tab))
function setTab(next: Tab) {
  void router.replace({ query: { ...route.query, tab: next === 'password' ? undefined : next } })
}
function setTabFromSelect(event: Event) {
  setTab((event.target as HTMLSelectElement).value as Tab)
}
watch(() => route.query.tab, value => {
  tab.value = tabFromQuery(value)
})

// ── Heslo ─────────────────────────────────────────────────────────────
const MIN_LEN = 12
const MAX_LEN = 128
const current = ref('')
const next = ref('')
const confirm = ref('')
const showCurrent = ref(false)
const showNext = ref(false)
const pwBusy = ref(false)
const pwError = ref('')
const nextLenOk = computed(() => next.value.length >= MIN_LEN && next.value.length <= MAX_LEN)
const matchOk = computed(() => next.value !== '' && next.value === confirm.value)
const canSubmitPw = computed(() =>
  current.value !== '' && nextLenOk.value && matchOk.value && !pwBusy.value
)

async function submitPw() {
  if (!canSubmitPw.value) return
  pwError.value = ''
  pwBusy.value = true
  try {
    await authApi.changePassword(current.value, next.value)
    toast.success(t('auth.password_changed'))
    current.value = ''
    next.value = ''
    confirm.value = ''
    cancelTotpSetup()
  } catch (e: any) {
    pwError.value = apiErrorMessage(e, t('auth.change_password_failed'))
  } finally {
    pwBusy.value = false
  }
}

// ── TOTP / 2FA ────────────────────────────────────────────────────────
const totpStatus = ref<{ enabled: boolean } | null>(null)
const totpSetup = ref<TotpSetup | null>(null)
const totpCode = ref('')
// První sada záložních kódů přijde jen jednou, v odpovědi enable — musí se
// ukázat a potvrdit dřív, než záložka přepne na „aktivní“ (jako ForcedMfaSetup).
const totpRecoveryCodes = ref<string[] | null>(null)
const totpCurrentPassword = ref('')
const totpBusy = ref(false)
const totpError = ref('')

// `/auth/totp/setup` vyžaduje čerstvé ověření: heslem (bez passkey), nebo
// step-up tokenem z passkey ceremonie pro operaci `totp.enable` (má-li
// uživatel aspoň jednu) — stejná detekce jako jinde v aplikaci (ApiTokens,
// admin/Backups, …), ať se politika nerozchází podle stránky.
const totpRequiresPasskeyStepUp = computed(() =>
  auth.user?.mfa_methods?.includes('passkey') === true
  || (auth.user?.passkey_count ?? 0) > 0,
)

async function loadTotpStatus() {
  try {
    totpStatus.value = await authApi.totpStatus()
  } catch (e: any) {
    totpError.value = apiErrorMessage(e, t('common.error'))
  }
}

function applyTotpSetupError(e: any) {
  const code = e?.response?.data?.error?.code
  if (code === 'current_password_invalid') {
    totpError.value = e?.response?.data?.error?.message || t('auth.current_password_invalid')
  } else if (code === 'already_enabled') {
    totpError.value = e?.response?.data?.error?.message || t('auth.totp_already_enabled')
  } else if (code === 'too_many_attempts') {
    totpError.value = e?.response?.data?.error?.message || t('auth.too_many_attempts')
  } else if (code === 'mfa_method_not_allowed') {
    totpError.value = e?.response?.data?.error?.message || t('auth.mfa_method_not_allowed')
  } else {
    const ceremonyError = webAuthnErrorKey(e)
    totpError.value = ceremonyError !== null
      ? t(ceremonyError)
      : e?.response?.data?.error?.message || t('common.error')
  }
}

async function startTotpSetup() {
  totpError.value = ''
  if (totpRequiresPasskeyStepUp.value) {
    totpBusy.value = true
    try {
      const flow = await authApi.passkeyStepUpOptions('totp.enable')
      const credential = await getCredential(flow.public_key)
      const stepUpToken = await authApi.passkeyStepUpVerify(flow.flow_token, 'totp.enable', credential)
      totpSetup.value = await authApi.totpSetup({ step_up_token: stepUpToken })
    } catch (e: any) {
      applyTotpSetupError(e)
    } finally {
      totpBusy.value = false
    }
    return
  }
  if (!totpCurrentPassword.value) {
    totpError.value = t('mfa_setup.password_required')
    return
  }
  totpBusy.value = true
  try {
    totpSetup.value = await authApi.totpSetup({ current_password: totpCurrentPassword.value })
    totpCurrentPassword.value = ''
  } catch (e: any) {
    applyTotpSetupError(e)
  } finally {
    totpBusy.value = false
  }
}

async function activateTotp() {
  if (!/^\d{6}$/.test(totpCode.value)) {
    totpError.value = t('auth.totp_invalid')
    return
  }
  totpBusy.value = true
  totpError.value = ''
  try {
    const previousCsrf = canonicalDomainLoginSessionCsrf()
    const result = await authApi.totpEnable(totpCode.value, previousCsrf ?? undefined)
    if (result.csrf_token) {
      if (previousCsrf) advanceCanonicalDomainLoginSession(previousCsrf, result.csrf_token)
      auth.setSessionCsrfToken(result.csrf_token)
    }
    totpSetup.value = null
    totpCode.value = ''
    if (result.recovery_codes?.length) {
      totpRecoveryCodes.value = result.recovery_codes
    }
    await auth.refresh()
    if (totpRecoveryCodes.value) return
    toast.success(t('auth.totp_enabled_done'))
    await loadTotpStatus()
  } catch (e: any) {
    const code = e?.response?.data?.error?.code
    totpError.value = code === 'already_enabled'
      ? e?.response?.data?.error?.message || t('auth.totp_already_enabled')
      : e?.response?.data?.error?.message || t('auth.totp_invalid')
    if (code === 'already_enabled') {
      cancelTotpSetup()
      await auth.refresh()
      await loadTotpStatus()
    } else if (code === 'enrollment_stale' || code === 'no_secret') {
      totpSetup.value = null
      totpCode.value = ''
    }
  } finally {
    totpBusy.value = false
  }
}

async function confirmTotpRecoveryCodes() {
  totpRecoveryCodes.value = null
  toast.success(t('auth.totp_enabled_done'))
  await loadTotpStatus()
}

function cancelTotpSetup() {
  totpSetup.value = null
  totpCode.value = ''
  totpCurrentPassword.value = ''
  totpError.value = ''
}

// ── Automatický zámek aplikace ──────────────────────────────────────
type LockMode = 'admin' | 'custom'
const lockPreference = ref<SessionLockPreference | null>(null)
const lockMode = ref<LockMode>('admin')
const lockMinutes = ref(15)
const lockBusy = ref(false)
const lockError = ref('')
const lockMaximum = computed(() => lockPreference.value?.maximum_lock_after_minutes ?? 1440)
const lockMinutesValid = computed(() =>
  Number.isInteger(lockMinutes.value)
  && lockMinutes.value >= 1
  && lockMinutes.value <= lockMaximum.value
)
const hasPasskey = computed(() => (auth.user?.passkey_count ?? 0) > 0)
// Vlastní interval server bez použitelné passkey odmítne (zamčenou session by
// nešlo odemknout) — netlačíme uživatele do 400, tlačítko rovnou zůstane vypnuté.
const canSaveLock = computed(() =>
  !lockBusy.value
  && (lockMode.value === 'admin' || (lockMinutesValid.value && hasPasskey.value))
)
const draftLockEnabled = computed(() =>
  lockMode.value === 'admin'
    ? (lockPreference.value?.admin_lock_after_minutes ?? 0) > 0
    : lockMinutesValid.value
)

function applyLockPreference(preference: SessionLockPreference) {
  lockPreference.value = preference
  lockMode.value = preference.user_lock_after_minutes === null ? 'admin' : 'custom'
  lockMinutes.value = preference.user_lock_after_minutes
    ?? Math.min(15, preference.maximum_lock_after_minutes)
}

async function loadLockPreference() {
  if (lockBusy.value) return
  lockBusy.value = true
  lockError.value = ''
  try {
    applyLockPreference(await authApi.sessionLockPreference())
  } catch (e: any) {
    lockError.value = apiErrorMessage(e, t('session_lock.preference_load_failed'))
  } finally {
    lockBusy.value = false
  }
}

async function saveLockPreference() {
  if (!canSaveLock.value) return
  lockBusy.value = true
  lockError.value = ''
  try {
    const result = await authApi.updateSessionLockPreference(
      lockMode.value === 'admin' ? null : lockMinutes.value,
    )
    applyLockPreference(result)
    sessionSecurity.apply(result.session)
    toast.success(t('session_lock.preference_saved'))
  } catch (e: any) {
    lockError.value = apiErrorMessage(e, t('session_lock.preference_save_failed'))
  } finally {
    lockBusy.value = false
  }
}

watch(tab, next => {
  if (next === 'session-lock' && lockPreference.value === null) {
    void loadLockPreference()
  }
})

onMounted(() => {
  loadTotpStatus()
  if (tab.value === 'session-lock') {
    void loadLockPreference()
  }
})
</script>

<template>
  <div class="max-w-2xl mx-auto">
    <div class="mb-4">
      <h1 class="text-2xl font-semibold">{{ t('auth.profile_title') }}</h1>
      <p class="text-sm text-neutral-500 mt-0.5">{{ t('auth.profile_subtitle') }}</p>
    </div>

    <div v-if="domainReturnPending"
         class="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-primary-300 bg-primary-50 px-4 py-3">
      <p class="min-w-0 flex-1 text-sm text-primary-800">
        {{ t('domain_login.canonical_webauthn_notice') }}
      </p>
      <button type="button" @click="returnToClientDomain" :disabled="domainReturnBusy"
              class="inline-flex h-10 items-center gap-2 whitespace-nowrap rounded-md bg-primary-600 px-4 text-sm font-medium text-white hover:bg-primary-700 disabled:bg-neutral-300">
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
          <path stroke-linecap="round" stroke-linejoin="round" d="M10 6l-6 6 6 6M4 12h16" />
        </svg>
        {{ domainReturnBusy ? t('domain_login.returning') : t('domain_login.return_to_client') }}
      </button>
    </div>
    <p v-if="domainReturnError"
       class="mb-4 rounded-md border border-danger-500/40 bg-danger-50 px-3 py-2 text-sm text-danger-500">
      {{ domainReturnError }}
    </p>

    <!-- Tabs: na mobilu a tabletu select, aby se pět názvů nemačkalo ani nescrollovalo -->
    <select :value="tab" @change="setTabFromSelect"
            class="md:hidden w-full h-10 px-3 mb-4 border border-neutral-300 rounded-md bg-surface text-sm">
      <option value="password">{{ t('auth.change_password_title') }}</option>
      <option value="totp">{{ t('auth.totp_tab') }}</option>
      <option value="passkeys">{{ t('passkeys.title') }}</option>
      <option value="session-lock">{{ t('session_lock.preference_tab') }}</option>
      <option v-if="!auth.isInstanceAdmin" value="shortcuts">{{ t('keyboard_shortcuts.title') }}</option>
    </select>

    <div class="hidden md:flex flex-wrap border-b border-neutral-200 mb-4 gap-1">
      <button type="button" @click="setTab('password')"
        class="cursor-pointer px-3 py-2 text-sm border-b-2 transition"
        :class="tab === 'password'
          ? 'border-primary-600 text-primary-700 font-medium'
          : 'border-transparent text-neutral-600 hover:text-neutral-900'">
        {{ t('auth.change_password_title') }}
      </button>
      <button type="button" @click="setTab('totp')"
        class="cursor-pointer px-3 py-2 text-sm border-b-2 transition inline-flex items-center gap-2"
        :class="tab === 'totp'
          ? 'border-primary-600 text-primary-700 font-medium'
          : 'border-transparent text-neutral-600 hover:text-neutral-900'">
        {{ t('auth.totp_tab') }}
        <span v-if="totpStatus?.enabled"
              class="text-[10px] uppercase bg-success-50 text-success-600 border border-success-500/40 px-1.5 py-0.5 rounded">
          {{ t('auth.totp_badge_on') }}
        </span>
      </button>
      <button type="button" @click="setTab('passkeys')"
        class="cursor-pointer px-3 py-2 text-sm border-b-2 transition"
        :class="tab === 'passkeys'
          ? 'border-primary-600 text-primary-700 font-medium'
          : 'border-transparent text-neutral-600 hover:text-neutral-900'">
        {{ t('passkeys.title') }}
      </button>
      <button type="button" @click="setTab('session-lock')"
        class="cursor-pointer px-3 py-2 text-sm border-b-2 transition"
        :class="tab === 'session-lock'
          ? 'border-primary-600 text-primary-700 font-medium'
          : 'border-transparent text-neutral-600 hover:text-neutral-900'">
        {{ t('session_lock.preference_tab') }}
      </button>
      <button v-if="!auth.isInstanceAdmin" type="button" @click="setTab('shortcuts')"
        class="cursor-pointer px-3 py-2 text-sm border-b-2 transition"
        :class="tab === 'shortcuts'
          ? 'border-primary-600 text-primary-700 font-medium'
          : 'border-transparent text-neutral-600 hover:text-neutral-900'">
        {{ t('keyboard_shortcuts.title') }}
      </button>
    </div>

    <!-- ── Heslo ── -->
    <form v-if="tab === 'password'" @submit.prevent="submitPw"
          class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm space-y-4">
      <div>
        <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('auth.current_password') }} *</label>
        <div class="flex gap-2">
          <input v-model="current" :type="showCurrent ? 'text' : 'password'"
                 autocomplete="current-password" required
                 class="flex-1 h-10 px-3 border border-neutral-300 rounded-md text-sm" />
          <button type="button" @click="showCurrent = !showCurrent"
                  class="cursor-pointer h-10 px-3 border border-neutral-300 rounded-md hover:bg-neutral-50 text-sm">
            {{ showCurrent ? '🙈' : '👁' }}
          </button>
        </div>
      </div>

      <div>
        <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('auth.new_password') }} *</label>
        <div class="flex gap-2">
          <input v-model="next" :type="showNext ? 'text' : 'password'"
                 autocomplete="new-password" :minlength="MIN_LEN" :maxlength="MAX_LEN" required
                 class="flex-1 h-10 px-3 border rounded-md text-sm"
                 :class="next === '' ? 'border-neutral-300' : nextLenOk ? 'border-success-500/40' : 'border-warning-500/40'" />
          <button type="button" @click="showNext = !showNext"
                  class="cursor-pointer h-10 px-3 border border-neutral-300 rounded-md hover:bg-neutral-50 text-sm">
            {{ showNext ? '🙈' : '👁' }}
          </button>
        </div>
        <p class="text-xs mt-1"
           :class="next === '' ? 'text-neutral-500' : nextLenOk ? 'text-success-600' : 'text-warning-600'">
          {{ t('auth.password_min_hint', { n: MIN_LEN }) }}
          <span v-if="next !== ''">— {{ next.length }}/{{ MAX_LEN }}</span>
        </p>
      </div>

      <div>
        <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('auth.new_password_confirm') }} *</label>
        <input v-model="confirm" :type="showNext ? 'text' : 'password'"
               autocomplete="new-password" required
               class="w-full h-10 px-3 border rounded-md text-sm"
               :class="confirm === '' ? 'border-neutral-300' : matchOk ? 'border-success-500/40' : 'border-danger-500/40'" />
        <p v-if="confirm !== '' && !matchOk" class="text-xs text-danger-500 mt-1">
          {{ t('auth.password_mismatch') }}
        </p>
      </div>

      <div v-if="pwError" class="rounded-md bg-danger-50 border border-danger-500/40 px-3 py-2 text-sm text-danger-500">
        {{ pwError }}
      </div>

      <div class="flex items-center justify-between pt-2 border-t border-neutral-100">
        <button type="button" @click="router.back()"
                class="cursor-pointer h-10 px-4 text-sm border border-neutral-300 rounded-md hover:bg-neutral-50">
          {{ t('common.back') }}
        </button>
        <button type="submit" :disabled="!canSubmitPw"
                class="cursor-pointer h-10 px-5 text-sm bg-primary-600 hover:bg-primary-700 disabled:bg-neutral-300 text-white font-medium rounded-md">
          {{ pwBusy ? t('common.saving') : t('auth.change_password_submit') }}
        </button>
      </div>

      <p class="text-xs text-neutral-500 pt-2">
        ℹ {{ t('auth.password_change_note') }}
      </p>
    </form>

    <!-- ── 2FA / TOTP ── -->
    <div v-else-if="tab === 'totp'" class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm space-y-4">
      <!-- Záložní kódy překryjí zbytek záložky: zobrazí se jen jednou a bez
           potvrzení uložení se stav nepřepne na „aktivní“. -->
      <RecoveryCodesOnce
        v-if="totpRecoveryCodes"
        :codes="totpRecoveryCodes"
        :busy="totpBusy"
        @confirm="confirmTotpRecoveryCodes"
      />
      <template v-else>
      <div v-if="totpStatus">
        <div v-if="totpStatus.enabled" class="flex items-center gap-2 text-success-600">
          <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
          <span class="font-medium">{{ t('auth.totp_status_enabled') }}</span>
        </div>
        <div v-else class="flex items-center gap-2 text-neutral-500">
          <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/></svg>
          <span>{{ t('auth.totp_status_disabled') }}</span>
        </div>
      </div>

      <p v-if="totpStatus?.enabled" class="text-xs text-neutral-500">
        {{ t('auth.totp_disable_hint') }}
      </p>

      <!-- Čerstvé ověření před vydáním secretu: bez passkey heslem, s ní
           step-up ceremonií pro operaci 'totp.enable'. -->
      <div v-if="totpStatus && !totpStatus.enabled && !totpSetup" class="space-y-3">
        <div v-if="!totpRequiresPasskeyStepUp">
          <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('auth.current_password') }}</label>
          <input
            v-model="totpCurrentPassword"
            type="password"
            autocomplete="current-password"
            data-test="totp-current-password"
            class="w-full h-10 px-3 border border-neutral-300 rounded-md"
            @keydown.enter="startTotpSetup"
          />
        </div>

        <div v-if="totpError" class="rounded-md bg-danger-50 border border-danger-500/40 px-3 py-2 text-sm text-danger-500">
          {{ totpError }}
        </div>

        <button data-test="totp-start"
                @click="startTotpSetup" :disabled="totpBusy"
                class="cursor-pointer h-10 px-4 bg-primary-600 hover:bg-primary-700 disabled:bg-neutral-300 text-white font-medium rounded-md">
          {{ totpBusy ? '…' : (totpRequiresPasskeyStepUp ? t('auth.totp_setup_verify_passkey_btn') : t('auth.totp_setup_btn')) }}
        </button>
      </div>

      <div v-if="totpSetup" class="space-y-4 pt-2 border-t border-neutral-200">
        <TotpAppRecommendation />
        <div class="flex justify-center bg-neutral-50 rounded-md p-4">
          <img :src="totpSetup.qr_data_uri" :alt="totpSetup.uri" class="border border-neutral-200 rounded" />
        </div>

        <details class="text-xs text-neutral-500">
          <summary class="cursor-pointer hover:text-neutral-700">{{ t('auth.totp_setup_step1_alt') }}</summary>
          <code class="block mt-2 p-2 bg-neutral-100 rounded font-mono text-xs break-all select-all">{{ totpSetup.secret }}</code>
        </details>

        <div class="pt-2">
          <p class="text-sm text-neutral-700 mb-2">{{ t('auth.totp_setup_step2') }}</p>
          <input v-model="totpCode" type="text" inputmode="numeric" maxlength="6"
                 pattern="\d{6}" placeholder="000000" @keydown.enter="activateTotp"
                 data-test="totp-code"
                 class="w-full h-10 px-3 border border-neutral-300 rounded-md font-mono text-lg tracking-widest text-center" />
        </div>

        <div v-if="totpError" class="rounded-md bg-danger-50 border border-danger-500/40 px-3 py-2 text-sm text-danger-500">
          {{ totpError }}
        </div>

        <div class="flex justify-end gap-2">
          <button @click="cancelTotpSetup"
                  class="cursor-pointer h-10 px-4 border border-neutral-300 rounded-md text-neutral-700 hover:bg-neutral-50">
            {{ t('common.cancel') }}
          </button>
          <button @click="activateTotp" :disabled="totpBusy || totpCode.length !== 6" data-test="totp-activate"
                  class="cursor-pointer h-10 px-4 bg-primary-600 hover:bg-primary-700 disabled:bg-neutral-300 text-white font-medium rounded-md">
            {{ totpBusy ? '…' : t('auth.totp_enable_btn') }}
          </button>
        </div>
      </div>
      </template>
    </div>

    <!-- ── Passkeys ── -->
    <Passkeys v-else-if="tab === 'passkeys'" @registered="returnToClientDomain" />

    <!-- ── Automatický zámek ── -->
    <form v-else-if="tab === 'session-lock'" @submit.prevent="saveLockPreference"
          class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm space-y-4">
      <div>
        <h2 class="font-medium text-neutral-900">{{ t('session_lock.preference_title') }}</h2>
        <p class="text-sm text-neutral-500 mt-1">{{ t('session_lock.preference_description') }}</p>
      </div>

      <div v-if="!lockPreference" class="rounded-md bg-neutral-50 border border-neutral-200 p-4 text-sm">
        <p :class="lockError ? 'text-danger-500' : 'text-neutral-500'">
          {{ lockBusy ? t('common.loading') : lockError }}
        </p>
        <button v-if="!lockBusy" type="button" @click="loadLockPreference"
                class="cursor-pointer mt-3 h-9 px-3 border border-neutral-300 rounded-md hover:bg-neutral-100">
          {{ t('session_lock.preference_retry') }}
        </button>
      </div>

      <template v-else>
        <label class="flex items-start gap-3 rounded-md border p-4 cursor-pointer transition"
               :class="lockMode === 'admin' ? 'border-primary-500 bg-primary-50/40' : 'border-neutral-200'">
          <input v-model="lockMode" type="radio" value="admin" class="mt-1" />
          <span>
            <span class="block text-sm font-medium text-neutral-800">
              {{ t('session_lock.preference_use_admin') }}
            </span>
            <span v-if="lockPreference.admin_lock_after_minutes > 0"
                  class="block text-xs text-neutral-500 mt-1">
              {{ t('session_lock.preference_admin_limit', { n: lockPreference.admin_lock_after_minutes }) }}
            </span>
            <span v-else class="block text-xs text-neutral-500 mt-1">
              {{ t('session_lock.preference_admin_disabled') }}
            </span>
          </span>
        </label>

        <label class="flex items-start gap-3 rounded-md border p-4 cursor-pointer transition"
               :class="lockMode === 'custom' ? 'border-primary-500 bg-primary-50/40' : 'border-neutral-200'">
          <input v-model="lockMode" type="radio" value="custom" class="mt-1" />
          <span class="flex-1">
            <span class="block text-sm font-medium text-neutral-800">
              {{ t('session_lock.preference_custom') }}
            </span>
            <span class="flex items-center gap-2 mt-2">
              <input v-model.number="lockMinutes" type="number" min="1"
                     :max="lockMaximum" :disabled="lockMode !== 'custom'"
                     class="w-28 h-10 px-3 border border-neutral-300 rounded-md text-sm disabled:bg-neutral-100" />
              <span class="text-sm text-neutral-600">{{ t('session_lock.preference_minutes') }}</span>
            </span>
            <span class="block text-xs mt-1"
                  :class="lockMode === 'custom' && !lockMinutesValid ? 'text-danger-500' : 'text-neutral-500'">
              {{ t('session_lock.preference_range', { n: lockMaximum }) }}
            </span>
          </span>
        </label>

        <div class="rounded-md bg-neutral-50 border border-neutral-200 px-3 py-2 text-sm text-neutral-600">
          <span v-if="lockPreference.effective_lock_after_minutes > 0">
            {{ t('session_lock.preference_effective', { n: lockPreference.effective_lock_after_minutes }) }}
          </span>
          <span v-else>{{ t('session_lock.preference_effective_disabled') }}</span>
        </div>

        <div v-if="!hasPasskey && draftLockEnabled"
             class="rounded-md bg-warning-50 border border-warning-500/40 px-3 py-2 text-sm text-warning-700">
          {{ t('session_lock.preference_no_passkey') }}
        </div>

        <div v-if="lockError"
             class="rounded-md bg-danger-50 border border-danger-500/40 px-3 py-2 text-sm text-danger-500">
          {{ lockError }}
        </div>

        <div class="flex justify-end pt-2 border-t border-neutral-100">
          <button type="submit" :disabled="!canSaveLock"
                  class="cursor-pointer h-10 px-5 text-sm bg-primary-600 hover:bg-primary-700 disabled:bg-neutral-300 text-white font-medium rounded-md">
            {{ lockBusy ? t('common.saving') : t('session_lock.preference_save') }}
          </button>
        </div>
      </template>
    </form>

    <!-- ── Klávesové zkratky ── -->
    <KeyboardShortcuts v-else embedded />
  </div>
</template>

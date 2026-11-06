<script setup lang="ts">
import { computed, ref, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import { authApi, type TotpSetup } from '@/api/auth'
import { useToast } from '@/composables/useToast'
import { useAuthStore } from '@/stores/auth'
import { getCredential, webAuthnErrorKey } from '@/security/webauthn'
import RecoveryCodesOnce from '@/components/security/RecoveryCodesOnce.vue'
import TotpAppRecommendation from '@/components/security/TotpAppRecommendation.vue'

const { t } = useI18n()
const toast = useToast()
const auth = useAuthStore()

const status = ref<{ enabled: boolean } | null>(null)
const recoveryCodes = ref<string[] | null>(null)
const setup = ref<TotpSetup | null>(null)
const code = ref('')
const currentPassword = ref('')
const busy = ref(false)
const error = ref('')
const recoveryRemaining = ref(0)
const useRecovery = ref(false)
const recoveryCode = ref('')

// `/auth/totp/setup` teď vyžaduje čerstvé ověření — heslem, nebo (má-li
// uživatel aspoň jednu passkey) step-up tokenem z passkey ceremonie. Bez toho
// by ukradená session mohla tiše přidat vlastní TOTP a zamknout majitele ven.
const hasPasskey = computed(() =>
  auth.user?.mfa_methods?.includes('passkey') === true
  || (auth.user?.passkey_count ?? 0) > 0,
)

async function loadStatus() {
  status.value = await authApi.totpStatus()
  // Záložní kód je cesta ven, když správce klíčů vrací neplatné ověření a nový
  // klíč by skončil v témže správci. Nabízí se jen tomu, komu nějaký kód zbývá.
  if (hasPasskey.value && !status.value.enabled) {
    recoveryRemaining.value = await authApi.recoveryCodeStatus()
      .then(s => s.remaining)
      .catch(() => 0)
  }
}

function applySetupError(e: any) {
  const errCode = e?.response?.data?.error?.code
  if (errCode === 'current_password_invalid') {
    error.value = e?.response?.data?.error?.message || t('auth.current_password_invalid')
  } else if (errCode === 'already_enabled') {
    error.value = e?.response?.data?.error?.message || t('auth.totp_already_enabled')
  } else if (errCode === 'too_many_attempts') {
    error.value = e?.response?.data?.error?.message || t('auth.too_many_attempts')
  } else if (errCode === 'mfa_method_not_allowed') {
    error.value = e?.response?.data?.error?.message || t('auth.mfa_method_not_allowed')
  } else {
    const ceremonyError = webAuthnErrorKey(e)
    error.value = ceremonyError !== null
      ? t(ceremonyError)
      : e?.response?.data?.error?.message || t('common.error')
  }
}

async function startSetup() {
  error.value = ''
  if (!hasPasskey.value && !currentPassword.value) {
    error.value = t('mfa_setup.password_required')
    return
  }
  if (hasPasskey.value && useRecovery.value && !recoveryCode.value.trim()) {
    error.value = t('auth.recovery_hint')
    return
  }
  busy.value = true
  try {
    if (hasPasskey.value && useRecovery.value) {
      const proof = await authApi.recoveryStepUp('totp.enable', recoveryCode.value.trim())
      recoveryRemaining.value = proof.remaining
      recoveryCode.value = ''
      setup.value = await authApi.totpSetup({ step_up_token: proof.step_up_token })
    } else if (hasPasskey.value) {
      const flow = await authApi.passkeyStepUpOptions('totp.enable')
      const credential = await getCredential(flow.public_key)
      const stepUpToken = await authApi.passkeyStepUpVerify(flow.flow_token, 'totp.enable', credential)
      setup.value = await authApi.totpSetup({ step_up_token: stepUpToken })
    } else {
      setup.value = await authApi.totpSetup({ current_password: currentPassword.value })
    }
    currentPassword.value = ''
  } catch (e: any) {
    applySetupError(e)
  } finally {
    busy.value = false
  }
}

async function activate() {
  if (!/^\d{6}$/.test(code.value)) {
    error.value = t('auth.totp_invalid')
    return
  }
  busy.value = true
  error.value = ''
  try {
    const result = await authApi.totpEnable(code.value)
    toast.success(t('auth.totp_enabled_done'))
    setup.value = null
    code.value = ''
    // ⚠️ Sada chodí PRÁVĚ JEDNOU a jen tomu, kdo ještě žádnou použitelnou
    // neměl. Kdo ji tady nezobrazí, už ji nikde nedohledá — server plaintext
    // neukládá.
    if (result.recovery_codes?.length) {
      recoveryCodes.value = result.recovery_codes
    }
    await auth.refresh()
    await loadStatus()
  } catch (e: any) {
    const errCode = e?.response?.data?.error?.code
    error.value = errCode === 'already_enabled'
      ? e?.response?.data?.error?.message || t('auth.totp_already_enabled')
      : e?.response?.data?.error?.message || t('auth.totp_invalid')
    if (errCode === 'already_enabled') {
      setup.value = null
      code.value = ''
      await auth.refresh()
      await loadStatus()
    } else if (errCode === 'enrollment_stale' || errCode === 'no_secret') {
      setup.value = null
      code.value = ''
    }
  } finally {
    busy.value = false
  }
}

onMounted(loadStatus)
</script>

<template>
  <div class="max-w-xl">
    <div class="flex items-center justify-between mb-4">
      <h1 class="text-2xl font-semibold">{{ t('auth.totp_2fa') }}</h1>
      <RouterLink to="/profile/api-tokens" class="text-sm text-primary-600 hover:underline">
        {{ t('api_tokens.title') }} →
      </RouterLink>
    </div>

    <!-- ⚠️ Nad vším ostatním: sada se ukazuje jen jednou a uživatel ji musí
         odkliknutím potvrdit, jinak odejde s druhým faktorem bez break-glass. -->
    <RecoveryCodesOnce
      v-if="recoveryCodes"
      class="mb-4"
      :codes="recoveryCodes"
      @confirm="recoveryCodes = null"
    />

    <div class="bg-surface border border-neutral-200 rounded-lg p-6 shadow-sm space-y-4">
      <div v-if="status">
        <div v-if="status.enabled" class="flex items-center gap-2 text-success-600">
          <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
          <span class="font-medium">{{ t('auth.totp_status_enabled') }}</span>
        </div>
        <div v-else class="flex items-center gap-2 text-neutral-500">
          <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/></svg>
          <span>{{ t('auth.totp_status_disabled') }}</span>
        </div>
      </div>

      <p v-if="status?.enabled" class="text-xs text-neutral-500">
        {{ t('auth.totp_disable_hint') }}
      </p>

      <div v-if="status && !status.enabled && !setup" class="space-y-3">
        <!-- Bez passkey je heslo jediný způsob, jak čerstvě ověřit majitele
             účtu před vydáním TOTP secretu. -->
        <div v-if="!hasPasskey">
          <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('auth.current_password') }}</label>
          <input
            v-model="currentPassword"
            type="password"
            autocomplete="current-password"
            data-test="totp-current-password"
            class="w-full h-10 px-3 border border-neutral-300 rounded-md"
            @keydown.enter="startSetup"
          />
        </div>

        <div v-if="hasPasskey && useRecovery">
          <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('auth.recovery_code') }}</label>
          <input
            v-model="recoveryCode"
            type="text"
            autocomplete="one-time-code"
            data-test="totp-recovery-code"
            class="w-full h-10 px-3 border border-neutral-300 rounded-md font-mono"
            @keydown.enter="startSetup"
          />
          <p class="text-xs text-neutral-500 mt-1">{{ t('auth.recovery_hint') }}</p>
        </div>

        <div v-if="error" class="rounded-md bg-danger-50 border border-danger-500/40 px-3 py-2 text-sm text-danger-500">
          {{ error }}
        </div>

        <button
          data-test="totp-start"
          @click="startSetup"
          :disabled="busy"
          class="cursor-pointer h-10 px-4 bg-primary-600 hover:bg-primary-700 disabled:bg-neutral-300 text-white font-medium rounded-md"
        >
          {{ busy ? '…' : (hasPasskey && !useRecovery ? t('auth.totp_setup_verify_passkey_btn') : t('auth.totp_setup_btn')) }}
        </button>
        <button
          v-if="hasPasskey && recoveryRemaining > 0"
          type="button"
          data-test="totp-toggle-recovery"
          class="block text-sm text-primary-600 hover:underline"
          @click="useRecovery = !useRecovery; recoveryCode = ''; error = ''"
        >
          {{ useRecovery ? t('passkeys.use_passkey_instead') : t('passkeys.use_recovery_instead') }}
        </button>
      </div>

      <div v-if="setup" class="space-y-4 pt-2 border-t border-neutral-200">
        <TotpAppRecommendation />
        <div class="flex justify-center bg-neutral-50 rounded-md p-4">
          <img :src="setup.qr_data_uri" :alt="setup.uri" class="border border-neutral-200 rounded" />
        </div>

        <details class="text-xs text-neutral-500">
          <summary class="cursor-pointer hover:text-neutral-700">{{ t('auth.totp_setup_step1_alt') }}</summary>
          <code class="block mt-2 p-2 bg-neutral-100 rounded font-mono text-xs break-all select-all">{{ setup.secret }}</code>
        </details>

        <div class="pt-2">
          <p class="text-sm text-neutral-700 mb-2">{{ t('auth.totp_setup_step2') }}</p>
          <input
            v-model="code"
            type="text"
            inputmode="numeric"
            maxlength="6"
            pattern="\d{6}"
            placeholder="000000"
            class="w-full h-10 px-3 border border-neutral-300 rounded-md font-mono text-lg tracking-widest text-center"
            @keydown.enter="activate"
          />
        </div>

        <div v-if="error" class="rounded-md bg-danger-50 border border-danger-500/40 px-3 py-2 text-sm text-danger-500">
          {{ error }}
        </div>

        <div class="flex justify-end gap-2">
          <button @click="setup = null; code = ''; error = ''"
            class="cursor-pointer h-10 px-4 border border-neutral-300 rounded-md text-neutral-700 hover:bg-neutral-50">
            {{ t('common.cancel') }}
          </button>
          <button
            @click="activate"
            :disabled="busy || code.length !== 6"
            class="cursor-pointer h-10 px-4 bg-primary-600 hover:bg-primary-700 disabled:bg-neutral-300 text-white font-medium rounded-md"
          >
            {{ busy ? '…' : t('auth.totp_enable_btn') }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

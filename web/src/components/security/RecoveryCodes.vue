<script setup lang="ts">
/**
 * Záložní jednorázové kódy — break-glass, když uživatel přijde o silný faktor.
 *
 * Server plaintext neukládá, takže vygenerovanou sadu jde zobrazit PRÁVĚ JEDNOU.
 * Komponenta z toho dělá viditelné pravidlo, ne drobné písmo: dokud si uživatel
 * neodklikne, že kódy má uložené, panel nezmizí.
 */
import { computed, onMounted, ref, useId } from 'vue'
import { useI18n } from 'vue-i18n'
import { authApi, type RecoveryCodeStatus } from '@/api/auth'
import { getCredential, isWebAuthnAvailable, webAuthnErrorKey } from '@/security/webauthn'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { takeSsoStepUpProof } from '@/security/oidc'
import SsoStepUpOption from '@/components/security/SsoStepUpOption.vue'

const { t } = useI18n()
const auth = useAuthStore()
const toast = useToast()

const status = ref<RecoveryCodeStatus | null>(null)
const codes = ref<string[] | null>(null)
const busy = ref(false)
const error = ref('')
const totpCode = ref('')
const totpInputId = `recovery-totp-${useId()}`
const passkeySupported = isWebAuthnAvailable()

const OPERATION = 'mfa.recovery_codes'

const hasTotp = computed(() => auth.user?.totp_enabled === true)
const totpUsable = computed(() => hasTotp.value && auth.allowedMfaMethods.includes('totp'))
const passkeyUsable = computed(() =>
  passkeySupported && auth.allowedMfaMethods.includes('passkey') && (auth.user?.passkey_count ?? 0) > 0
)
// Novou sadu smí vydat jen skutečný faktor. Záložním kódem to schválně nejde —
// jinak by se dal papírový kód recyklovat na nekonečnou sadu dalších.
const canGenerate = computed(() => totpUsable.value || passkeyUsable.value)
const totpCodeValid = computed(() => /^\d{6}$/.test(totpCode.value))

async function loadStatus() {
  try {
    status.value = await authApi.recoveryCodeStatus()
  } catch {
    status.value = null
  }
}

async function stepUp(): Promise<string> {
  if (totpCodeValid.value && totpUsable.value) {
    return authApi.totpStepUp(OPERATION, totpCode.value)
  }
  if (!passkeyUsable.value) throw new Error('totp_code_required')
  const flow = await authApi.passkeyStepUpOptions(OPERATION)
  const credential = await getCredential(flow.public_key)
  return authApi.passkeyStepUpVerify(flow.flow_token, OPERATION, credential)
}

async function generate(ssoProof = '') {
  if (busy.value || (!ssoProof && !canGenerate.value)) return
  if (status.value && status.value.remaining > 0
      && !window.confirm(t('recovery_codes.regenerate_confirm'))) {
    return
  }
  busy.value = true
  error.value = ''
  try {
    const result = await authApi.generateRecoveryCodes(ssoProof || await stepUp())
    codes.value = result.codes
    status.value = result
    totpCode.value = ''
  } catch (e: any) {
    const ceremonyError = webAuthnErrorKey(e)
    if (e?.message === 'totp_code_required') {
      error.value = t('recovery_codes.needs_factor')
    } else if (ceremonyError !== null) {
      error.value = totpUsable.value
        ? `${t(ceremonyError)} ${t('recovery_codes.totp_fallback')}`
        : t(ceremonyError)
    } else {
      error.value = e?.response?.data?.error?.message || t('recovery_codes.failed')
    }
  } finally {
    busy.value = false
  }
}

async function copyCodes() {
  if (!codes.value) return
  try {
    await navigator.clipboard.writeText(codes.value.join('\n'))
    toast.success(t('recovery_codes.copied'))
  } catch {
    toast.error(t('recovery_codes.copy_failed'))
  }
}

function download() {
  if (!codes.value) return
  const body = [
    t('recovery_codes.file_header', { app: 'MyÚčto.cz', email: auth.user?.email ?? '' }),
    '',
    ...codes.value,
    '',
    t('recovery_codes.file_footer'),
  ].join('\r\n')
  const url = URL.createObjectURL(new Blob([body], { type: 'text/plain;charset=utf-8' }))
  const a = document.createElement('a')
  a.href = url
  a.download = 'myucto-zalozni-kody.txt'
  document.body.appendChild(a)
  a.click()
  a.remove()
  URL.revokeObjectURL(url)
}

function dismiss() {
  if (!window.confirm(t('recovery_codes.dismiss_confirm'))) return
  codes.value = null
  void loadStatus()
}

onMounted(async () => {
  // Po návratu z ověření přes Microsoft se sada vygeneruje rovnou.
  const proof = takeSsoStepUpProof(OPERATION)
  await loadStatus()
  if (proof) await generate(proof)
})
</script>

<template>
  <div class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm space-y-3">
    <div>
      <h3 class="font-medium">{{ t('recovery_codes.title') }}</h3>
      <p class="text-sm text-neutral-500 mt-0.5">{{ t('recovery_codes.subtitle') }}</p>
    </div>

    <!-- Jediné zobrazení sady. Zůstává na obrazovce, dokud uživatel nepotvrdí, že ji má. -->
    <div v-if="codes" class="rounded-md border border-warning-300 bg-warning-50 p-4 space-y-3">
      <p class="text-sm font-medium text-warning-700">{{ t('recovery_codes.shown_once') }}</p>
      <ul class="grid grid-cols-2 gap-x-6 gap-y-1 font-mono text-sm">
        <li v-for="code in codes" :key="code">{{ code }}</li>
      </ul>
      <div class="flex flex-wrap gap-2">
        <button type="button" @click="download"
                class="h-9 px-3 bg-primary-600 hover:bg-primary-700 text-white rounded-md text-sm font-medium">
          {{ t('recovery_codes.download') }}
        </button>
        <button type="button" @click="copyCodes"
                class="h-9 px-3 border border-neutral-300 rounded-md text-sm">
          {{ t('recovery_codes.copy') }}
        </button>
        <button type="button" @click="dismiss"
                class="h-9 px-3 border border-neutral-300 rounded-md text-sm ml-auto">
          {{ t('recovery_codes.dismiss') }}
        </button>
      </div>
    </div>

    <template v-else>
      <p v-if="status && status.total > 0" class="text-sm"
         :class="status.remaining === 0 ? 'text-danger-500' : status.low ? 'text-warning-700' : 'text-neutral-600'">
        {{ status.remaining === 0
          ? t('recovery_codes.exhausted')
          : t('recovery_codes.remaining', { remaining: status.remaining, total: status.total }) }}
      </p>
      <p v-else class="text-sm text-warning-700">{{ t('recovery_codes.none_yet') }}</p>

      <div v-if="totpUsable && !passkeyUsable">
        <label :for="totpInputId" class="block text-sm font-medium text-neutral-700 mb-1">
          {{ t('recovery_codes.totp_label') }}
        </label>
        <input :id="totpInputId" v-model="totpCode" type="text" inputmode="numeric"
               autocomplete="one-time-code" maxlength="6" pattern="\d{6}"
               class="w-full h-10 px-3 border border-neutral-300 rounded-md font-mono" />
      </div>

      <button type="button" @click="generate()"
              :disabled="busy || !canGenerate || (totpUsable && !passkeyUsable && !totpCodeValid)"
              class="h-10 px-4 bg-primary-600 hover:bg-primary-700 disabled:bg-neutral-300 text-white rounded-md font-medium">
        {{ status && status.total > 0 ? t('recovery_codes.regenerate') : t('recovery_codes.generate') }}
      </button>

      <SsoStepUpOption v-if="canGenerate" :operation="OPERATION" :disabled="busy" />

      <p v-if="!canGenerate" class="text-xs text-warning-700">{{ t('recovery_codes.needs_factor') }}</p>
      <p v-else class="text-xs text-neutral-500">{{ t('recovery_codes.step_up_hint') }}</p>
    </template>

    <p v-if="error" class="rounded-md bg-danger-50 border border-danger-500/40 px-3 py-2 text-sm text-danger-500">
      {{ error }}
    </p>
  </div>
</template>

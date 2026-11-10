<script setup lang="ts">
/**
 * Správa uživatelů → reset dvoufázového ověření jiného uživatele.
 * Jen superadmin; reset potvrzuje vlastním MFA (passkey, TOTP nebo účtem
 * organizace) jednorázovým proofem vázaným na resetovaný účet.
 */
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { adminApi } from '@/api/admin'
import { authApi } from '@/api/auth'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { getCredential, isWebAuthnAvailable, webAuthnErrorKey } from '@/security/webauthn'
import { ICONS, btnFilled, btnOutline } from '@/components/ui/buttonStyles'
import SsoStepUpOption from '@/components/security/SsoStepUpOption.vue'

const props = defineProps<{ userId: number }>()
const emit = defineEmits<{ reset: [] }>()

const { t } = useI18n()
const auth = useAuthStore()
const toast = useToast()
const open = ref(false)
const reason = ref('')
const totpCode = ref('')
const busy = ref(false)
const error = ref('')

const isSelf = computed(() => auth.user?.id === props.userId)
const mfaMethods = computed(() => auth.user?.mfa_methods || [])
const canPasskey = computed(() => mfaMethods.value.includes('passkey') && isWebAuthnAvailable())
const canTotp = computed(() => mfaMethods.value.includes('totp'))
const reasonValid = computed(() => reason.value.trim().length >= 10 && reason.value.trim().length <= 500)
const operation = computed(() => `user.mfa_reset:${props.userId}`)

function start() {
  open.value = true
  reason.value = ''
  totpCode.value = ''
  error.value = ''
}

async function submit(proof: string) {
  try {
    const result = await adminApi.resetUserMfa(props.userId, reason.value.trim(), proof)
    if (result.mail_sent) toast.success(t('users.mfa_reset_done'))
    else toast.warning(t('users.mfa_reset_done_no_mail'))
    open.value = false
    emit('reset')
  } catch (e: any) {
    error.value = e?.response?.data?.error?.message || t('users.mfa_reset_failed')
  }
}

async function confirmWithPasskey() {
  if (!reasonValid.value || busy.value) return
  busy.value = true
  error.value = ''
  try {
    const flow = await authApi.passkeyStepUpOptions(operation.value)
    const credential = await getCredential(flow.public_key)
    await submit(await authApi.passkeyStepUpVerify(flow.flow_token, operation.value, credential))
  } catch (e: any) {
    const key = webAuthnErrorKey(e)
    error.value = key ? t(key) : e?.response?.data?.error?.message || t('users.mfa_reset_failed')
  } finally {
    busy.value = false
  }
}

async function confirmWithTotp() {
  if (!reasonValid.value || busy.value || !/^\d{6}$/.test(totpCode.value)) return
  busy.value = true
  error.value = ''
  try {
    await submit(await authApi.totpStepUp(operation.value, totpCode.value))
  } catch (e: any) {
    error.value = e?.response?.data?.error?.message || t('users.mfa_reset_failed')
  } finally {
    busy.value = false
  }
}

async function confirmWithSso(proof: string) {
  if (!reasonValid.value || busy.value) return
  busy.value = true
  error.value = ''
  try {
    await submit(proof)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <section class="border-t border-neutral-200 pt-4 space-y-3" data-user-mfa-reset>
    <div class="flex flex-wrap items-start justify-between gap-2">
      <h3 class="font-medium">{{ t('users.mfa_reset_title') }}</h3>
      <button v-if="!open && !isSelf" type="button" :class="[btnOutline('warning'), 'whitespace-nowrap']" data-user-mfa-reset-start @click="start">
        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.lock" /></svg>
        {{ t('users.mfa_reset_cta') }}
      </button>
    </div>
    <p v-if="isSelf" class="text-xs text-neutral-500">{{ t('users.mfa_reset_self') }}</p>
    <div v-else-if="open" class="rounded-md border border-warning-200 bg-warning-50 p-3 space-y-3 text-sm">
      <p class="text-warning-800">{{ t('users.mfa_reset_hint') }}</p>
      <label class="block">
        <span class="block font-medium mb-1">{{ t('users.mfa_reset_reason') }}</span>
        <input v-model="reason" maxlength="500" :placeholder="t('users.mfa_reset_reason_placeholder')" class="w-full h-10 px-3 border border-neutral-300 rounded-md bg-surface" data-user-mfa-reset-reason>
        <span class="mt-1 block text-xs text-neutral-500">{{ t('users.mfa_reset_reason_hint') }}</span>
      </label>
      <p class="text-neutral-700">{{ t('users.mfa_reset_confirm_hint') }}</p>
      <p v-if="!canPasskey && !canTotp" class="text-warning-800" data-user-mfa-reset-own-required>
        {{ t('users.mfa_reset_own_mfa_required') }}
        <router-link to="/profile/password?tab=totp" class="underline">{{ t('users.mfa_reset_own_mfa_link') }}</router-link>
      </p>
      <div class="flex flex-wrap items-center gap-2">
        <button v-if="canPasskey" type="button" :disabled="busy || !reasonValid" :class="[btnFilled('warning'), 'whitespace-nowrap']" data-user-mfa-reset-passkey @click="confirmWithPasskey">
          <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.lock" /></svg>
          {{ t('users.mfa_reset_passkey') }}
        </button>
        <template v-if="canTotp">
          <input v-model="totpCode" inputmode="numeric" autocomplete="one-time-code" maxlength="6" :placeholder="t('users.mfa_reset_totp_placeholder')" class="h-10 w-36 px-3 border border-neutral-300 rounded-md bg-surface" data-user-mfa-reset-totp-code>
          <button type="button" :disabled="busy || !reasonValid || !/^\d{6}$/.test(totpCode)" :class="[btnFilled('warning'), 'whitespace-nowrap']" data-user-mfa-reset-totp @click="confirmWithTotp">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.lock" /></svg>
            {{ t('users.mfa_reset_totp') }}
          </button>
        </template>
        <button type="button" :class="[btnOutline('neutral'), 'whitespace-nowrap']" :disabled="busy" @click="open = false">
          <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>
          {{ t('common.cancel') }}
        </button>
      </div>
      <SsoStepUpOption :operation="operation" :disabled="busy || !reasonValid" @verified="confirmWithSso" />
      <p v-if="error" role="alert" class="text-xs text-danger-700" data-user-mfa-reset-error>{{ error }}</p>
    </div>
  </section>
</template>

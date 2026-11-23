<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { btnOutline } from '@/components/ui/buttonStyles'
import { useSsoStepUp } from '@/composables/useSsoStepUp'
import { useSsoEnforcement } from '@/composables/useSsoEnforcement'
import { isSsoErrorCode } from '@/security/oidc'
import MicrosoftLogo from '@/components/security/MicrosoftLogo.vue'

const props = defineProps<{
  operation: string
  disabled?: boolean
  /** Upřesnění dialogu, ve kterém ověření běží (víc dialogů sdílí operaci). */
  context?: string
  /** Proof z ověření přes účet organizace už dialog drží. */
  verified?: boolean
}>()

const emit = defineEmits<{ verified: [token: string] }>()

const { t } = useI18n()
const sso = useSsoStepUp(props.operation, () => props.context ?? '', token => emit('verified', token))
const { strong: ssoEnforced } = useSsoEnforcement()
// Při vynucení silného poskytovatele je ověření účtem organizace jediná cesta;
// bez propojené identity by dialog jinak zůstal bez jakékoli možnosti potvrzení.
const unavailableNotice = computed(() => ssoEnforced.value && !sso.available.value && sso.settled.value)

const errorText = computed(() => {
  const code = sso.error.value
  if (code === '') return ''
  if (code === 'popup_blocked') return t('sso.step_up_popup_blocked')
  if (code === 'popup_unsupported') return t('sso.step_up_failed')
  return isSsoErrorCode(code) ? t(`sso.errors.${code}`) : t('sso.step_up_failed')
})
</script>

<template>
  <div v-if="verified" class="text-sm font-medium text-success-600" data-testid="sso-step-up-verified">
    ✓ {{ t('sso.step_up_verified') }}
  </div>
  <div v-else-if="sso.available.value" class="space-y-1" data-testid="sso-step-up">
    <div class="flex flex-wrap gap-2">
      <button type="button" :class="[btnOutline('primary'), 'whitespace-nowrap']"
              :disabled="disabled || sso.busy.value" @click="sso.start">
        <MicrosoftLogo />
        {{ sso.busy.value ? t('sso.redirecting') : t('sso.step_up_button') }}
      </button>
    </div>
    <p class="text-xs text-neutral-500">
      {{ sso.waiting.value ? t('sso.step_up_waiting') : t(ssoEnforced ? 'sso.step_up_hint_enforced' : 'sso.step_up_hint') }}
    </p>
    <p v-if="errorText" class="text-sm text-danger-500" data-testid="sso-step-up-error">{{ errorText }}</p>
  </div>
  <p v-else-if="unavailableNotice" role="alert" data-testid="sso-step-up-unavailable"
     class="rounded-md border border-warning-500/40 bg-warning-50 px-3 py-2 text-sm text-warning-700">
    {{ t('sso.step_up_enforced_unavailable') }}
  </p>
</template>

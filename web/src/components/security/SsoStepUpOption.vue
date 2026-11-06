<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { btnOutline } from '@/components/ui/buttonStyles'
import { useSsoStepUp } from '@/composables/useSsoStepUp'
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
      {{ sso.waiting.value ? t('sso.step_up_waiting') : t('sso.step_up_hint') }}
    </p>
    <p v-if="errorText" class="text-sm text-danger-500" data-testid="sso-step-up-error">{{ errorText }}</p>
  </div>
</template>

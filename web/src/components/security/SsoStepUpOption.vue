<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { btnOutline } from '@/components/ui/buttonStyles'
import { useSsoStepUp } from '@/composables/useSsoStepUp'
import MicrosoftLogo from '@/components/security/MicrosoftLogo.vue'

const props = defineProps<{
  operation: string
  disabled?: boolean
}>()

const { t } = useI18n()
const sso = useSsoStepUp(props.operation)
</script>

<template>
  <div v-if="sso.available.value" class="space-y-1" data-testid="sso-step-up">
    <div class="flex flex-wrap gap-2">
      <button type="button" :class="[btnOutline('primary'), 'whitespace-nowrap']"
              :disabled="disabled || sso.busy.value" @click="sso.start">
        <MicrosoftLogo />
        {{ sso.busy.value ? t('sso.redirecting') : t('sso.step_up_button') }}
      </button>
    </div>
    <p class="text-xs text-neutral-500">{{ t('sso.step_up_hint') }}</p>
    <p v-if="sso.error.value" class="text-sm text-danger-500">
      {{ sso.error.value === 'sso_not_linked' ? t('sso.errors.sso_not_linked') : t('sso.step_up_failed') }}
    </p>
  </div>
</template>

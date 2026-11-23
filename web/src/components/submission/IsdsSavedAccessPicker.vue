<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { apiErrorMessage } from '@/api/errors'
import { ICONS, btnOutlineSm } from '@/components/ui/buttonStyles'
import { useToast } from '@/composables/useToast'
import type { IsdsSavedAccessState } from './useIsdsSavedAccess'

const props = defineProps<{ access: IsdsSavedAccessState; disabled?: boolean; boxId?: string | null }>()
const { t } = useI18n()
const toast = useToast()
const choices = computed(() => props.access.state.choices.filter(choice => !props.boxId || choice.box_id === props.boxId))

async function forget() {
  const selected = props.access.selected.value
  if (!selected || props.disabled || !window.confirm(t('databox.savedAccess.forgetConfirm', { box: selected.box_id }))) return
  try {
    await props.access.forget()
  } catch (error) {
    toast.error(apiErrorMessage(error, t('databox.savedAccess.forgetFailed')))
  }
}
</script>

<template>
  <div class="space-y-3">
    <label class="block text-sm">
      <span class="font-medium">{{ t('databox.savedAccess.select') }}</span>
      <select
        v-model="access.state.selectedId"
        class="form-input mt-1 w-full"
        :disabled="disabled || access.state.removing"
        data-test="isds-saved-access-select"
      >
        <option :value="null" disabled>{{ t('databox.savedAccess.select') }}</option>
        <option v-for="choice in choices" :key="choice.id" :value="choice.id">
          {{ t('databox.savedAccess.option', {
            label: choice.label,
            box: choice.box_id,
            scope: choice.scope === 'personal' ? t('databox.savedAccess.personal') : t('databox.savedAccess.shared'),
          }) }}
        </option>
      </select>
    </label>
    <div v-if="access.selected.value" class="flex flex-wrap items-center gap-3">
      <label class="flex items-start gap-2 text-sm font-medium">
        <input
          v-model="access.state.confirmed"
          type="checkbox"
          class="mt-0.5"
          :disabled="disabled || access.state.removing"
          data-test="isds-saved-access-confirm"
        />
        <span>{{ t('databox.savedAccess.confirm', { box: access.selected.value.box_id }) }}</span>
      </label>
      <button
        v-if="access.selected.value.scope === 'personal'"
        type="button"
        :class="[btnOutlineSm('danger'), 'whitespace-nowrap']"
        :disabled="disabled || access.state.removing"
        data-test="isds-saved-access-forget"
        @click="forget"
      >
        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
          <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.trash" />
        </svg>
        {{ t('databox.savedAccess.forget') }}
      </button>
    </div>
  </div>
</template>

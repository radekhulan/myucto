<script setup lang="ts">
import { computed, useId } from 'vue'
import { useI18n } from 'vue-i18n'
import type { ChartAccount } from '@/api/accounting'
import { accountPickerOptions } from '@/utils/chartAccountOptions'

const props = defineProps<{
  modelValue: string | null | undefined
  accounts: ChartAccount[]
  inputId?: string
  ariaLabel?: string
  placeholder?: string
  disabled?: boolean
}>()
const emit = defineEmits<{ 'update:modelValue': [string] }>()
const { t } = useI18n()
const listId = `chart-account-options-${useId()}`
const options = computed(() => accountPickerOptions(props.accounts))
const selected = computed(() => options.value.find(account => account.account_code === props.modelValue))
</script>

<template>
  <div>
    <input :id="inputId" :value="modelValue || ''" :list="listId" type="text" :disabled="disabled"
      :aria-label="ariaLabel" :placeholder="placeholder || t('accounting.template.account_search')" autocomplete="off"
      class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono bg-surface"
      @input="emit('update:modelValue', ($event.target as HTMLInputElement).value)" />
    <datalist :id="listId">
      <option v-for="account in options" :key="account.account_code" :value="account.account_code" :label="account.name">{{ account.account_code }} / {{ account.name }}</option>
    </datalist>
    <p v-if="selected" class="text-xs text-neutral-500 mt-0.5 truncate">{{ selected.name }}</p>
    <p v-else-if="modelValue && !disabled" class="text-xs text-danger-500 mt-0.5">{{ t('accounting.manual.unknown_code') }}</p>
  </div>
</template>

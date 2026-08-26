<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import PayrollPersonSearchSelect from '@/components/payroll/PayrollPersonSearchSelect.vue'

export interface PayrollPersonPickerOption {
  value: number
  label: string
  secondary?: string
}

const TAB_LIMIT = 15

const props = defineProps<{
  modelValue: number | null
  options: PayrollPersonPickerOption[]
  selectorLabel: string
}>()

const emit = defineEmits<{
  'update:modelValue': [value: number | null]
}>()

const useSearch = computed(() => props.options.length > TAB_LIMIT)
const { t } = useI18n()
</script>

<template>
  <div
    v-if="useSearch"
    class="border-b border-neutral-200 bg-surface px-4 py-3 sm:px-5"
    data-test="payroll-person-picker-search"
  >
    <PayrollPersonSearchSelect
      :model-value="modelValue"
      :candidates="options"
      :limit="25"
      :label="selectorLabel"
      :clearable="false"
      :placeholder="t('payroll.runs.person_picker.placeholder')"
      @update:model-value="emit('update:modelValue', $event)"
    />
    <p class="mt-1 text-xs text-neutral-500">
      {{ t('payroll.runs.person_picker.count', options.length) }}
    </p>
  </div>

  <nav
    v-else
    class="flex gap-1 overflow-x-auto border-b border-neutral-200 px-2 sm:px-4"
    :aria-label="selectorLabel"
    data-test="payroll-person-picker-tabs"
  >
    <button
      v-for="option in options"
      :key="option.value"
      type="button"
      class="whitespace-nowrap border-b-2 px-3 py-3 text-sm font-medium transition-colors"
      :class="modelValue === option.value
        ? 'border-payroll-500 text-payroll-700'
        : 'border-transparent text-neutral-600 hover:border-neutral-300 hover:text-neutral-900'"
      @click="emit('update:modelValue', option.value)"
    >
      {{ option.label }}
    </button>
  </nav>
</template>

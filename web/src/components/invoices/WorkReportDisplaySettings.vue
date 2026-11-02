<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { WorkReportDisplayOptions } from '@/api/invoices'

const props = defineProps<{
  modelValue?: Partial<WorkReportDisplayOptions> | null
  defaults?: Partial<WorkReportDisplayOptions> | null
  inheritable?: boolean
}>()
const emit = defineEmits<{ 'update:modelValue': [value: WorkReportDisplayOptions | null] }>()
const { t } = useI18n()
const options = computed<WorkReportDisplayOptions>(() => ({
  compact: false, date: true, hours: true, rate: true, amount: true,
  ...props.defaults, ...props.modelValue,
}))
const inherited = computed(() => props.inheritable && props.modelValue == null)
function setOption(key: keyof WorkReportDisplayOptions, event: Event) {
  emit('update:modelValue', { ...options.value, [key]: (event.target as HTMLInputElement).checked })
}
</script>

<template>
  <fieldset class="rounded-md border border-neutral-200 p-3 space-y-3" data-test="work-report-display">
    <legend class="px-1 text-sm font-medium text-neutral-700">{{ t('workReportDisplay.title') }}</legend>
    <label v-if="inheritable" class="flex items-center gap-2 text-sm">
      <input type="checkbox" :checked="inherited" data-test="work-report-inherit"
        class="rounded border-neutral-300 text-primary-600"
        @change="emit('update:modelValue', ($event.target as HTMLInputElement).checked ? null : { ...options })" />
      {{ t('workReportDisplay.inherit') }}
    </label>
    <label class="flex items-center gap-2 text-sm">
      <input type="checkbox" :checked="options.compact" :disabled="inherited" data-test="work-report-compact"
        class="rounded border-neutral-300 text-primary-600" @change="setOption('compact', $event)" />
      {{ t('workReportDisplay.compact') }}
    </label>
    <div class="flex flex-wrap gap-x-5 gap-y-2 text-sm">
      <span class="text-neutral-500">{{ t('workReportDisplay.columns') }}</span>
      <label v-for="key in (['date', 'hours', 'rate', 'amount'] as const)" :key="key" class="flex items-center gap-2 whitespace-nowrap">
        <input type="checkbox" :checked="options[key]" :disabled="inherited" :data-test="`work-report-column-${key}`"
          class="rounded border-neutral-300 text-primary-600" @change="setOption(key, $event)" />
        {{ t(`workReportDisplay.${key}`) }}
      </label>
    </div>
    <p class="text-xs text-neutral-500">{{ t('workReportDisplay.hint') }}</p>
  </fieldset>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { WorkReportBillingTemplate } from '@/api/workReportBilling'
import { useWorkReportBillingTemplates } from '@/composables/useWorkReportBillingTemplates'
const model = defineModel<number | null>({ default: null })
const props = defineProps<{ currency?: string; templates?: WorkReportBillingTemplate[]; hasSnapshot?: boolean }>()
const emit = defineEmits<{ selected: [template: WorkReportBillingTemplate | null] }>()
const { t } = useI18n()
const catalog = useWorkReportBillingTemplates(() => props.templates == null)
const templates = computed(() => props.templates ?? catalog.templates.value)
const visible = computed(() => !!props.hasSnapshot || model.value != null || templates.value.length > 0)
function select(event: Event) {
  const id = Number((event.target as HTMLSelectElement).value) || null
  model.value = id
  emit('selected', templates.value.find(template => template.id === id) ?? null)
}
</script>
<template>
  <label v-if="visible" class="block text-sm font-medium text-neutral-700">{{ t('work_report_billing.template') }}
    <select :value="hasSnapshot && model == null ? 'snapshot' : model ?? ''" @change="select" class="mt-1 w-full h-10 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
      <option v-if="hasSnapshot && model == null" value="snapshot" disabled>{{ t('work_report_billing.saved_copy') }}</option>
      <option value="">{{ t('work_report_billing.legacy') }}</option>
      <option v-if="model && !templates.some(template => template.id === model)" :value="model">{{ t('work_report_billing.saved_copy') }}</option>
      <option v-for="template in templates" :key="template.id" :value="template.id" :disabled="!!props.currency && template.currency !== props.currency">{{ template.name }} ({{ template.currency }})</option>
    </select>
  </label>
  <p v-if="visible && catalog.error.value" class="text-sm text-danger-600">{{ catalog.error.value }}</p>
</template>

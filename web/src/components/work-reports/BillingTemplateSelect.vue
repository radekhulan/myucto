<script setup lang="ts">
import { ref, watch, onActivated, onBeforeUnmount } from 'vue'
import { useI18n } from 'vue-i18n'
import { workReportBillingApi, type WorkReportBillingTemplate } from '@/api/workReportBilling'
import { useSupplierStore } from '@/stores/supplier'
import { apiErrorMessage } from '@/api/errors'
const model = defineModel<number | null>({ default: null })
const props = defineProps<{ currency?: string }>()
const emit = defineEmits<{ selected: [template: WorkReportBillingTemplate | null] }>()
const { t } = useI18n()
const supplier = useSupplierStore()
const templates = ref<WorkReportBillingTemplate[]>([])
const error = ref('')
let request = 0
async function load() {
  const generation = ++request
  templates.value = []
  error.value = ''
  try { const result = await workReportBillingApi.list(); if (generation === request) templates.value = result }
  catch (e) { if (generation === request) error.value = apiErrorMessage(e, t('common.error')) }
}
watch(() => supplier.currentSupplierId, load, { immediate: true })
let activatedOnce = false
onActivated(() => {
  if (activatedOnce) void load()
  activatedOnce = true
})
onBeforeUnmount(() => { request++ })
function select(event: Event) {
  const id = Number((event.target as HTMLSelectElement).value) || null
  model.value = id
  emit('selected', templates.value.find(template => template.id === id) ?? null)
}
</script>
<template>
  <label class="block text-sm font-medium text-neutral-700">{{ t('work_report_billing.template') }}
    <select :value="model ?? ''" @change="select" class="mt-1 w-full h-10 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
      <option value="">{{ t('work_report_billing.legacy') }}</option>
      <option v-if="model && !templates.some(template => template.id === model)" :value="model">{{ t('work_report_billing.saved_copy') }}</option>
      <option v-for="template in templates" :key="template.id" :value="template.id" :disabled="!!props.currency && template.currency !== props.currency">{{ template.name }} ({{ template.currency }})</option>
    </select>
  </label>
  <p v-if="error" class="text-sm text-danger-600">{{ error }}</p>
</template>

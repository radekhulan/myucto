<script setup lang="ts">
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import type { WorkReportItem, InvoiceItem } from '@/api/invoices'
import { workReportBillingApi, type WorkReportBillingSnapshot, type WorkReportBillingTemplate, type WorkReportBillingPreview } from '@/api/workReportBilling'
import BillingTemplateSelect from './BillingTemplateSelect.vue'
import BillingSnapshotEditor from './BillingSnapshotEditor.vue'
import BillingRecap from './BillingRecap.vue'
import { apiErrorMessage } from '@/api/errors'
import { ICONS, btnOutline } from '@/components/ui/buttonStyles'
const snapshot = defineModel<WorkReportBillingSnapshot | null>('snapshot', { required: true })
const templateId = defineModel<number | null>('templateId', { required: true })
const period = defineModel<string>('period', { required: true })
const legacyItemId = defineModel<number | null>('legacyItemId', { default: null })
const props = defineProps<{ currency: string; title: string; items: WorkReportItem[]; projectName?: string; vatRateId: number | null; pricesIncludeVat?: boolean; reverseCharge?: boolean; stale?: boolean; pending?: boolean; invoiceItems?: InvoiceItem[] }>()
const emit = defineEmits<{ transfer: [preview: WorkReportBillingPreview]; changed: []; modeSelected: [] }>()
const { t } = useI18n()
const preview = ref<WorkReportBillingPreview | null>(null)
const busy = ref(false)
const error = ref('')
const expanded = ref(false)
let generation = 0
function selected(template: WorkReportBillingTemplate | null) {
  snapshot.value = template ? JSON.parse(JSON.stringify(template)) : null
  emit('modeSelected')
}
watch(() => [snapshot.value, period.value, props.items, props.title, props.currency, props.vatRateId, props.pricesIncludeVat, props.reverseCharge, props.projectName], () => {
  generation++
  preview.value = null
  emit('changed')
}, { deep: true })
async function calculate(transfer = false) {
  if (!snapshot.value || busy.value) return
  const request = ++generation
  busy.value = true
  error.value = ''
  try {
    const result = await workReportBillingApi.previewDraft({ billing_snapshot: snapshot.value, billing_period: period.value || null, title: props.title, project_name: props.projectName ?? '', currency: props.currency, items: props.items.filter(i => i.description.trim()), vat_rate_id: props.vatRateId, prices_include_vat: !!props.pricesIncludeVat, reverse_charge: !!props.reverseCharge })
    if (request !== generation) return
    preview.value = result
    if (transfer) emit('transfer', result)
  } catch (e) { if (request === generation) error.value = apiErrorMessage(e, t('common.error')) }
  finally { busy.value = false }
}
</script>
<template>
  <div class="border border-neutral-200 rounded-md p-3 space-y-3">
    <BillingTemplateSelect v-model="templateId" :currency="currency" @selected="selected" />
    <template v-if="snapshot">
      <div class="flex flex-wrap items-end gap-3">
        <label class="block text-sm font-medium text-neutral-700">{{ t('work_report_billing.period') }}<input v-model="period" type="month" class="block mt-1 h-10 px-3 border border-neutral-300 rounded-md bg-surface" /></label>
        <button type="button" :class="btnOutline('neutral')" @click="expanded = !expanded"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path :d="ICONS.edit" /></svg>{{ t('work_report_billing.override') }}</button>
      </div>
      <BillingSnapshotEditor v-if="expanded" v-model="snapshot" />
      <p class="text-xs text-neutral-500">{{ t('work_report_billing.snapshot_hint') }}</p>
      <label v-if="invoiceItems?.some(item => item.id && !item.linked_work_report_id && item.item_kind !== 'text' && !item.stock_item_id && !item.small_asset_id && !item.asset_id)" class="block text-sm text-neutral-700">{{ t('work_report_billing.replace_legacy') }}
        <select v-model="legacyItemId" class="mt-1 w-full h-10 px-3 border border-neutral-300 rounded-md bg-surface">
          <option :value="null">{{ t('work_report_billing.keep_items') }}</option>
          <template v-for="(item, index) in invoiceItems" :key="index"><option v-if="item.id && !item.linked_work_report_id && item.item_kind !== 'text' && item.item_kind !== 'discount' && !item.stock_item_id && !item.small_asset_id && !item.asset_id" :value="item.id">#{{ index + 1 }} {{ item.description }}</option></template>
        </select>
      </label>
      <p v-if="snapshot.currency !== currency" class="text-sm text-danger-600">{{ t('work_report_billing.currency_mismatch') }}</p>
      <p v-if="stale && !pending" class="text-sm text-warning-600">{{ t('work_report_billing.stale') }}</p>
      <p v-if="pending" role="status" class="rounded-md border border-success-500/40 bg-success-50 px-3 py-2 text-sm font-medium text-success-700">{{ t('work_report_billing.pending', { count: preview?.items.length ?? 0 }) }}</p>
      <div class="flex flex-wrap gap-2">
        <button type="button" :class="btnOutline('neutral')" :disabled="busy || snapshot.currency !== currency" @click="calculate()"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path :d="ICONS.eye" /></svg>{{ t('work_report_billing.preview') }}</button>
        <button type="button" data-test="billing-prepare-transfer" :class="btnOutline(pending ? 'success' : 'primary')" :disabled="busy || snapshot.currency !== currency" @click="calculate(true)"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path :d="pending ? ICONS.check : ICONS.swap" /></svg>{{ t(pending ? 'work_report_billing.ready_to_save' : 'work_report_billing.prepare_transfer') }}</button>
      </div>
      <BillingRecap v-if="preview" :preview="preview" :currency="currency" :prices-include-vat="pricesIncludeVat" />
    </template>
    <p v-if="error" class="text-sm text-danger-600">{{ error }}</p>
  </div>
</template>

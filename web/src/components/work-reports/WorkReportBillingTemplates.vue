<script setup lang="ts">
import { ref, watch, onActivated, onBeforeUnmount } from 'vue'
import { useI18n } from 'vue-i18n'
import { workReportBillingApi, newBillingSnapshot, type WorkReportBillingSnapshot, type WorkReportBillingTemplate } from '@/api/workReportBilling'
import { useSupplierStore } from '@/stores/supplier'
import { codebooksApi, type Currency } from '@/api/codebooks'
import { apiErrorMessage } from '@/api/errors'
import BillingSnapshotEditor from '@/components/work-reports/BillingSnapshotEditor.vue'
import { ICONS, btnFilled, btnOutline } from '@/components/ui/buttonStyles'
const props = defineProps<{ currency?: string; readOnly?: boolean }>()
const { t } = useI18n()
const supplier = useSupplierStore()
const list = ref<WorkReportBillingTemplate[]>([])
const defaultCurrency = ref('CZK')
const currencies = ref<Currency[]>([])
const editing = ref<WorkReportBillingSnapshot | null>(null)
const editingId = ref<number | null>(null)
const error = ref('')
const busy = ref(false)
const loading = ref(false)
let generation = 0
async function load(resetEditor = true) {
  const request = ++generation
  if (resetEditor) editing.value = null
  list.value = []
  error.value = ''
  loading.value = true
  try {
    const [result, currencyList] = await Promise.all([workReportBillingApi.list(), props.currency ? Promise.resolve([]) : codebooksApi.currencies().catch(() => [])])
    if (request === generation) { list.value = result; currencies.value = currencyList; defaultCurrency.value = currencyList.find(currency => currency.is_default)?.code || 'CZK' }
  }
  catch (e) { if (request === generation) error.value = apiErrorMessage(e, t('common.error')) }
  finally { if (request === generation) loading.value = false }
}
watch(() => supplier.currentSupplierId, () => load(), { immediate: true })
let activatedOnce = false
onActivated(() => {
  if (activatedOnce) void load(false)
  activatedOnce = true
})
onBeforeUnmount(() => { generation++ })
function edit(template?: WorkReportBillingTemplate) {
  if (props.readOnly && !template) return
  editingId.value = template?.id ?? null
  editing.value = template ? JSON.parse(JSON.stringify(template)) : newBillingSnapshot(props.currency || defaultCurrency.value, Number(supplier.currentSupplier?.default_hourly_rate) || 0)
}
async function save() {
  if (props.readOnly || !editing.value || busy.value) return
  busy.value = true
  error.value = ''
  try {
    if (editingId.value) await workReportBillingApi.update(editingId.value, editing.value)
    else await workReportBillingApi.create(editing.value)
    await load()
  } catch (e) { error.value = apiErrorMessage(e, t('common.error')) }
  finally { busy.value = false }
}
async function remove(template: WorkReportBillingTemplate) {
  if (props.readOnly) return
  if (!confirm(t('work_report_billing.delete_confirm', { name: template.name }))) return
  busy.value = true
  try { await workReportBillingApi.delete(template.id); await load() }
  catch (e) { error.value = apiErrorMessage(e, t('common.error')) }
  finally { busy.value = false }
}
</script>
<template>
  <section class="space-y-4">
    <header v-if="!readOnly" class="flex flex-wrap items-center justify-end gap-3">
      <button type="button" :class="btnOutline('primary')" @click="edit()"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path :d="ICONS.plus" /></svg>{{ t('work_report_billing.add_template') }}</button>
    </header>
    <p class="text-xs text-neutral-500">{{ t('work_report_billing.snapshot_hint') }}</p>
    <p v-if="loading" class="text-sm text-neutral-500">{{ t('common.loading') }}</p>
    <p v-else-if="!error && list.length === 0" class="rounded-md border border-dashed border-neutral-300 p-4 text-center text-sm text-neutral-500">{{ t('work_report_billing.empty') }}</p>
    <div v-for="template in list" :key="template.id" class="flex flex-wrap gap-2 items-center justify-between rounded-md border border-neutral-200 p-3">
      <span class="text-sm">{{ template.name }} <span class="text-neutral-500">({{ template.currency }})</span></span>
      <div class="flex flex-wrap gap-2">
        <button type="button" :class="btnOutline('neutral')" @click="edit(template)"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path :d="readOnly ? ICONS.eye : ICONS.edit" /></svg>{{ t(readOnly ? 'common.view' : 'common.edit') }}</button>
        <button v-if="!readOnly" type="button" :class="btnOutline('danger')" :disabled="busy" @click="remove(template)"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path :d="ICONS.trash" /></svg>{{ t('common.delete') }}</button>
      </div>
    </div>
    <div v-if="editing" class="border border-neutral-200 rounded-lg p-4 space-y-4">
      <BillingSnapshotEditor v-model="editing" :read-only="readOnly" :currencies="currencies" />
      <div class="flex flex-wrap gap-2">
        <button v-if="!readOnly" type="button" :class="btnFilled('primary')" :disabled="busy" @click="save"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path :d="ICONS.check" /></svg>{{ busy ? t('common.saving') : t('common.save') }}</button>
        <button type="button" :class="btnOutline('neutral')" @click="editing = null"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path :d="ICONS.x" /></svg>{{ t(readOnly ? 'common.close' : 'common.cancel') }}</button>
      </div>
    </div>
    <p v-if="error" class="text-sm text-danger-600">{{ error }}</p>
  </section>
</template>

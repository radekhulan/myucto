<script setup lang="ts">
import { ref, watch, onActivated, onBeforeUnmount } from 'vue'
import { useI18n } from 'vue-i18n'
import { workReportBillingApi, newBillingSnapshot, type WorkReportBillingSnapshot, type WorkReportBillingTemplate } from '@/api/workReportBilling'
import { useSupplierStore } from '@/stores/supplier'
import { apiErrorMessage } from '@/api/errors'
import BillingSnapshotEditor from '@/components/work-reports/BillingSnapshotEditor.vue'
import { ICONS, btnFilled, btnOutline } from '@/components/ui/buttonStyles'
const props = defineProps<{ currency?: string }>()
const { t } = useI18n()
const supplier = useSupplierStore()
const list = ref<WorkReportBillingTemplate[]>([])
const editing = ref<WorkReportBillingSnapshot | null>(null)
const editingId = ref<number | null>(null)
const error = ref('')
const busy = ref(false)
let generation = 0
async function load(resetEditor = true) {
  const request = ++generation
  if (resetEditor) editing.value = null
  list.value = []
  error.value = ''
  try { const result = await workReportBillingApi.list(); if (request === generation) list.value = result }
  catch (e) { if (request === generation) error.value = apiErrorMessage(e, t('common.error')) }
}
watch(() => supplier.currentSupplierId, () => load(), { immediate: true })
let activatedOnce = false
onActivated(() => {
  if (activatedOnce) void load(false)
  activatedOnce = true
})
onBeforeUnmount(() => { generation++ })
function edit(template?: WorkReportBillingTemplate) {
  editingId.value = template?.id ?? null
  editing.value = template ? JSON.parse(JSON.stringify(template)) : newBillingSnapshot(props.currency || 'CZK')
}
async function save() {
  if (!editing.value || busy.value) return
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
  if (!confirm(t('work_report_billing.delete_confirm', { name: template.name }))) return
  busy.value = true
  try { await workReportBillingApi.delete(template.id); await load() }
  catch (e) { error.value = apiErrorMessage(e, t('common.error')) }
  finally { busy.value = false }
}
</script>
<template>
  <section class="space-y-4 border-t border-neutral-200 pt-4">
    <header class="flex flex-wrap items-center justify-between gap-3">
      <h3 class="text-sm font-semibold text-neutral-700">{{ t('work_report_billing.templates') }}</h3>
      <button type="button" :class="btnOutline('primary')" @click="edit()"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path :d="ICONS.plus" /></svg>{{ t('work_report_billing.add_template') }}</button>
    </header>
    <p class="text-xs text-neutral-500">{{ t('work_report_billing.snapshot_hint') }}</p>
    <div v-for="template in list" :key="template.id" class="flex flex-wrap gap-2 items-center justify-between rounded-md border border-neutral-200 p-3">
      <span class="text-sm">{{ template.name }} <span class="text-neutral-500">({{ template.currency }})</span></span>
      <div class="flex flex-wrap gap-2">
        <button type="button" :class="btnOutline('neutral')" @click="edit(template)"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path :d="ICONS.edit" /></svg>{{ t('common.edit') }}</button>
        <button type="button" :class="btnOutline('danger')" :disabled="busy" @click="remove(template)"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path :d="ICONS.trash" /></svg>{{ t('common.delete') }}</button>
      </div>
    </div>
    <div v-if="editing" class="border border-neutral-200 rounded-lg p-4 space-y-4">
      <BillingSnapshotEditor v-model="editing" />
      <div class="flex flex-wrap gap-2">
        <button type="button" :class="btnFilled('primary')" :disabled="busy" @click="save"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path :d="ICONS.check" /></svg>{{ busy ? t('common.saving') : t('common.save') }}</button>
        <button type="button" :class="btnOutline('neutral')" @click="editing = null"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path :d="ICONS.x" /></svg>{{ t('common.cancel') }}</button>
      </div>
    </div>
    <p v-if="error" class="text-sm text-danger-600">{{ error }}</p>
  </section>
</template>

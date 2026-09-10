<script setup lang="ts">
import { computed, onActivated, onBeforeUnmount, onDeactivated, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { catalogJobsApi, type CatalogJob } from '@/api/catalogJobs'
import { productMastersApi, type ContentTransferPreviewItem, type ProductMaster } from '@/api/productMasters'
import { useSupplierStore } from '@/stores/supplier'
import { useToast } from '@/composables/useToast'
import { apiErrorMessage } from '@/api/errors'
import CatalogJobProgress from '@/components/stock/CatalogJobProgress.vue'
import { ICONS, btnFilled, btnOutline } from '@/components/ui/buttonStyles'

const props = defineProps<{ master: ProductMaster; canWrite: boolean }>()
const { t } = useI18n()
const supplier = useSupplierStore()
const toast = useToast()

const selectedIds = ref<number[]>(props.master.variants.map(v => v.stock_item_id))
const fields = ref<string[]>([])
const overwrite = ref(false)
const job = ref<CatalogJob | null>(null)
const previewJobId = ref<number | null>(null)
const previewApplied = ref(false)
const previewItems = ref<ContentTransferPreviewItem[]>([])
const itemsPage = ref(1)
const itemsPages = ref(1)
const busy = ref(false)
const cancelling = ref(false)
const active = ref(true)
let timer: ReturnType<typeof setTimeout> | null = null
let generation = 0

const availableFields = computed(() => [
  { value: 'manufacturer', label: t('eshop.masters.field_manufacturer') },
  ...props.master.i18n.flatMap(row => ['name', 'short_desc', 'description', 'seo_title', 'seo_description'].map(field => ({ value: `i18n.${row.locale}.${field}`, label: `${row.locale.toUpperCase()}: ${t(`eshop.masters.content_field.${field}`)}` }))),
])
const canApply = computed(() => props.canWrite && previewJobId.value !== null && job.value?.kind === 'product_content_transfer_preview' && job.value.status === 'completed' && !previewApplied.value && previewItems.value.some(row => row.status === 'ready'))

function stopPolling() { if (timer) clearTimeout(timer); timer = null }

async function poll(id: number) {
  stopPolling()
  const current = ++generation
  try {
    const value = await catalogJobsApi.get(id)
    if (!active.value || current !== generation) return
    job.value = value
    if (value.status === 'queued' || value.status === 'running') timer = setTimeout(() => poll(id), 1000)
    else if (value.kind === 'product_content_transfer_preview' && value.status === 'completed') await loadPreviewItems(1)
  } catch (error: any) {
    if (current === generation) toast.error(apiErrorMessage(error, t('common.error')))
  }
}

async function loadPreviewItems(page: number) {
  if (!previewJobId.value) return
  const current = generation
  try {
    const result = await productMastersApi.getContentTransferItems(previewJobId.value, { page, limit: 50 })
    if (!active.value || current !== generation) return
    previewItems.value = result.items
    itemsPage.value = result.pagination.page
    itemsPages.value = result.pagination.pages
  } catch (error: any) {
    if (current === generation) toast.error(apiErrorMessage(error, t('common.error')))
  }
}

async function preview() {
  if (!props.canWrite || busy.value || !selectedIds.value.length || !fields.value.length) return
  busy.value = true
  try {
    job.value = await productMastersApi.previewContentTransfer(props.master.id, {
      master_row_version: props.master.row_version,
      stock_item_ids: selectedIds.value,
      fields: fields.value,
      overwrite: overwrite.value,
    })
    previewJobId.value = job.value.id
    previewApplied.value = false
    previewItems.value = []
    await poll(job.value.id)
  } catch (error: any) {
    toast.error(apiErrorMessage(error, t('common.error')))
  } finally {
    busy.value = false
  }
}

async function apply() {
  if (!canApply.value || !previewJobId.value || busy.value) return
  busy.value = true
  try {
    job.value = await productMastersApi.applyContentTransfer(props.master.id, previewJobId.value)
    previewApplied.value = true
    await poll(job.value.id)
  } catch (error: any) {
    toast.error(apiErrorMessage(error, t('common.error')))
  } finally {
    busy.value = false
  }
}

async function cancel() {
  if (!job.value || cancelling.value) return
  cancelling.value = true
  try { job.value = await catalogJobsApi.cancel(job.value.id); await poll(job.value.id) }
  catch (error: any) { toast.error(apiErrorMessage(error, t('common.error'))) }
  finally { cancelling.value = false }
}

function toggleTarget(id: number) {
  selectedIds.value = selectedIds.value.includes(id) ? selectedIds.value.filter(value => value !== id) : [...selectedIds.value, id]
}

function toggleField(value: string) {
  fields.value = fields.value.includes(value) ? fields.value.filter(field => field !== value) : [...fields.value, value]
}

watch(() => supplier.currentSupplierId, () => { generation++; stopPolling(); job.value = null; previewJobId.value = null; previewItems.value = [] })
watch(() => props.master.variants.map(v => v.stock_item_id).join(','), () => { selectedIds.value = props.master.variants.map(v => v.stock_item_id) })
onActivated(() => { active.value = true; if (job.value && ['queued', 'running'].includes(job.value.status)) void poll(job.value.id) })
onDeactivated(() => { active.value = false; generation++; stopPolling() })
onBeforeUnmount(() => { active.value = false; generation++; stopPolling() })
</script>

<template>
  <section class="space-y-4 rounded-lg border border-neutral-200 bg-surface p-5 shadow-sm">
    <div><h2 class="text-lg font-semibold">{{ t('eshop.masters.transfer_title') }}</h2><p class="text-sm text-neutral-500">{{ t('eshop.masters.transfer_hint') }}</p></div>
    <div v-if="master.variants.length" class="grid grid-cols-1 gap-5 lg:grid-cols-2">
      <div><h3 class="mb-2 text-sm font-semibold">{{ t('eshop.masters.transfer_targets') }}</h3><div class="max-h-48 space-y-1 overflow-y-auto rounded-md border border-neutral-200 p-2"><label v-for="variant in master.variants" :key="variant.stock_item_id" class="flex cursor-pointer items-center gap-2 rounded px-2 py-1 text-sm hover:bg-neutral-50"><input type="checkbox" :checked="selectedIds.includes(variant.stock_item_id)" @change="toggleTarget(variant.stock_item_id)" class="rounded border-neutral-300 text-primary-600" /><span class="font-mono text-xs text-neutral-500">{{ variant.sku }}</span><span>{{ variant.effective_name }}</span></label></div></div>
      <div><h3 class="mb-2 text-sm font-semibold">{{ t('eshop.masters.transfer_fields') }}</h3><div class="max-h-48 space-y-1 overflow-y-auto rounded-md border border-neutral-200 p-2"><label v-for="field in availableFields" :key="field.value" class="flex cursor-pointer items-center gap-2 rounded px-2 py-1 text-sm hover:bg-neutral-50"><input type="checkbox" :checked="fields.includes(field.value)" @change="toggleField(field.value)" class="rounded border-neutral-300 text-primary-600" />{{ field.label }}</label></div></div>
    </div>
    <label v-if="master.variants.length" class="flex cursor-pointer items-start gap-2 text-sm"><input v-model="overwrite" type="checkbox" class="mt-0.5 rounded border-neutral-300 text-warning-600" /><span><strong>{{ t('eshop.masters.transfer_overwrite') }}</strong><span class="block text-xs text-neutral-500">{{ t('eshop.masters.transfer_overwrite_hint') }}</span></span></label>
    <div class="flex flex-wrap gap-2">
      <button v-if="canWrite" type="button" @click="preview" :disabled="busy || !selectedIds.length || !fields.length" :class="btnFilled('primary')"><svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.eye" /></svg>{{ t('eshop.masters.transfer_preview') }}</button>
      <button v-if="canApply" type="button" @click="apply" :disabled="busy" :class="btnFilled('success')"><svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.play" /></svg>{{ t('eshop.masters.transfer_apply') }}</button>
      <RouterLink to="/eshop/jobs" :class="btnOutline('neutral')"><svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.archive" /></svg>{{ t('eshop.masters.transfer_jobs') }}</RouterLink>
    </div>
    <CatalogJobProgress :job="job" :can-cancel="canWrite && !!job && ['queued', 'running'].includes(job.status)" :cancelling="cancelling" @cancel="cancel" />
    <div v-if="previewItems.length" class="overflow-hidden rounded-md border border-neutral-200"><div class="overflow-x-auto"><table class="w-full text-sm"><thead class="bg-neutral-50 text-xs uppercase text-neutral-500"><tr><th class="px-3 py-2 text-left">{{ t('eshop.masters.variant') }}</th><th class="px-3 py-2 text-left">{{ t('eshop.masters.transfer_result') }}</th><th class="px-3 py-2 text-left">{{ t('eshop.masters.transfer_difference') }}</th></tr></thead><tbody class="divide-y divide-neutral-100"><tr v-for="item in previewItems" :key="item.ordinal ?? item.stock_item_id"><td class="px-3 py-2"><span class="font-mono text-xs">{{ item.source_row?.sku ?? item.sku }}</span> {{ item.source_row?.name ?? item.name }}</td><td class="px-3 py-2"><span class="rounded px-2 py-0.5 text-xs font-medium" :class="item.status === 'ready' ? 'bg-success-50 text-success-600' : item.status === 'unchanged' ? 'bg-neutral-100 text-neutral-500' : 'bg-warning-50 text-warning-700'">{{ t(`eshop.masters.transfer_status.${item.status}`) }}</span></td><td class="px-3 py-2 font-mono text-xs"><div v-for="(value, key) in item.after ?? {}" :key="key"><span class="text-neutral-500">{{ key }}:</span> {{ value ?? '-' }}</div></td></tr></tbody></table></div><div v-if="itemsPages > 1" class="flex items-center justify-center gap-3 border-t border-neutral-200 px-3 py-2 text-sm"><button type="button" :disabled="itemsPage <= 1" @click="loadPreviewItems(itemsPage - 1)" :class="btnOutline('neutral')">{{ t('eshop.masters.previous') }}</button><span>{{ itemsPage }} / {{ itemsPages }}</span><button type="button" :disabled="itemsPage >= itemsPages" @click="loadPreviewItems(itemsPage + 1)" :class="btnOutline('neutral')">{{ t('eshop.masters.next') }}</button></div></div>
  </section>
</template>

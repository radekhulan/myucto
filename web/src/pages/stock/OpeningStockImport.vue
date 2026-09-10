<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { openingStockImportApi, type OpeningImportConfig, type OpeningImportJobItems, type OpeningImportSample, type OpeningImportSource } from '@/api/openingStockImport'
import type { CatalogJob } from '@/api/catalogJobs'
import { stockApi, type Warehouse } from '@/api/stock'
import CatalogJobProgress from '@/components/stock/CatalogJobProgress.vue'
import { btnFilled, btnOutline, ICONS } from '@/components/ui/buttonStyles'
import { useAuthStore } from '@/stores/auth'
import { useSupplierStore } from '@/stores/supplier'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const supplier = useSupplierStore()
const warehouses = ref<Warehouse[]>([])
const source = ref<OpeningImportSource | null>(null)
const sample = ref<OpeningImportSample | null>(null)
const job = ref<CatalogJob | null>(null)
const report = ref<OpeningImportJobItems | null>(null)
const reportPage = ref(1)
const reportLoading = ref(false)
const sampleLoading = ref(false)
const loading = ref(false)
const error = ref('')
let timer: ReturnType<typeof setInterval> | undefined
let generation = 0
const requestVersions = new Map<string, number>()

interface RequestContext {
  kind: string
  version: number
  generation: number
  supplierId: number | null
}

const config = ref<OpeningImportConfig>({
  source_key: '',
  warehouse_id: 0,
  doc_date: new Date().toISOString().slice(0, 10),
  mapping: {},
  reader: { encoding: 'UTF-8', delimiter: ';', sheet: 0 },
})
const counts = computed<Record<string, number>>(() => {
  const raw = job.value?.report?.counts
  return raw && typeof raw === 'object' && !Array.isArray(raw)
    ? Object.fromEntries(Object.entries(raw).map(([key, value]) => [key, Number(value) || 0])) : {}
})
const active = computed(() => !!job.value && ['queued', 'running'].includes(job.value.status))
const reportPages = computed(() => Math.max(1, report.value?.pagination.pages ?? 1))
const canApply = computed(() => job.value?.kind === 'stock_opening_import_stage'
  && job.value.status === 'completed' && !job.value.apply_job_id
  && (counts.value.ready ?? 0) > 0 && (counts.value.failed ?? 0) === 0 && (counts.value.conflict ?? 0) === 0)
const formValid = computed(() => !!source.value && config.value.warehouse_id > 0
  && /^\d{4}-\d{2}-\d{2}$/.test(config.value.doc_date)
  && /^[a-z0-9][a-z0-9_.-]{0,99}$/.test(config.value.source_key)
  && ['external_id', 'sku', 'quantity', 'unit_cost'].every(field => !!config.value.mapping[field]))

function errorMessage(e: any) {
  const code = e?.response?.data?.error?.code
  return code ? t(`stock.opening_import.errors.${code}`, code) : t('stock.opening_import.error')
}

function captureContext(kind: string): RequestContext {
  const version = (requestVersions.get(kind) ?? 0) + 1
  requestVersions.set(kind, version)
  return { kind, version, generation, supplierId: supplier.currentSupplierId }
}

function isCurrent(context: RequestContext): boolean {
  return context.generation === generation
    && context.supplierId === supplier.currentSupplierId
    && context.version === requestVersions.get(context.kind)
}

function autoMap(headers: string[]) {
  const aliases: Record<string, string[]> = {
    external_id: ['external_id', 'id', 'radek', 'row_id'], sku: ['sku', 'kod', 'code'],
    quantity: ['quantity', 'qty', 'mnozstvi'], unit_cost: ['unit_cost', 'cost', 'cena', 'nakupni_cena'],
  }
  const normalized = headers.map(header => ({ header, key: header.trim().toLocaleLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/[\s-]+/g, '_') }))
  for (const [field, names] of Object.entries(aliases)) {
    config.value.mapping[field] = normalized.find(candidate => names.includes(candidate.key))?.header ?? ''
  }
}

async function pickFile(event: Event) {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]
  input.value = ''
  if (!file || loading.value) return
  const context = captureContext('upload')
  loading.value = true
  error.value = ''
  source.value = null
  sample.value = null
  config.value.mapping = {}
  try {
    const uploaded = await openingStockImportApi.upload(file)
    if (!isCurrent(context)) return
    source.value = uploaded
    await reloadSample()
  } catch (e) { if (isCurrent(context)) error.value = errorMessage(e) }
  finally { if (isCurrent(context)) loading.value = false }
}

async function reloadSample() {
  if (!source.value) return
  const sourceId = source.value.id
  const context = captureContext('sample')
  sampleLoading.value = true
  error.value = ''
  sample.value = null
  config.value.mapping = {}
  try {
    const nextSample = await openingStockImportApi.sample(sourceId, { ...config.value.reader })
    if (!isCurrent(context) || source.value?.id !== sourceId) return
    sample.value = nextSample
    autoMap(nextSample.header)
  } catch (e) { if (isCurrent(context)) error.value = errorMessage(e) }
  finally { if (isCurrent(context)) sampleLoading.value = false }
}

async function createPreview() {
  if (!formValid.value || loading.value) return
  const sourceId = source.value!.id
  const context = captureContext('preview')
  loading.value = true
  error.value = ''
  try {
    const nextJob = await openingStockImportApi.preview(sourceId, config.value)
    if (!isCurrent(context) || source.value?.id !== sourceId) return
    job.value = nextJob
    report.value = null
    reportPage.value = 1
    await router.replace({ query: { ...route.query, opening_job: String(nextJob.id) } })
    if (!isCurrent(context) || job.value?.id !== nextJob.id) return
    startPolling()
  } catch (e) { if (isCurrent(context)) error.value = errorMessage(e) }
  finally { if (isCurrent(context)) loading.value = false }
}

async function apply() {
  if (!canApply.value || loading.value) return
  const previewId = job.value!.id
  const context = captureContext('apply')
  loading.value = true
  error.value = ''
  try {
    const nextJob = await openingStockImportApi.apply(previewId)
    if (!isCurrent(context) || job.value?.id !== previewId) return
    job.value = nextJob
    report.value = null
    reportPage.value = 1
    await router.replace({ query: { ...route.query, opening_job: String(nextJob.id) } })
    if (!isCurrent(context) || job.value?.id !== nextJob.id) return
    startPolling()
  } catch (e) { if (isCurrent(context)) error.value = errorMessage(e) }
  finally { if (isCurrent(context)) loading.value = false }
}

async function refresh() {
  if (!job.value) return
  const id = job.value.id
  const page = reportPage.value
  const context = captureContext('refresh')
  try {
    const [next, rows] = await Promise.all([openingStockImportApi.job(id), openingStockImportApi.items(id, page)])
    if (!isCurrent(context) || job.value?.id !== id) return
    job.value = next
    if (reportPage.value === page) report.value = rows
    if (!['queued', 'running'].includes(next.status)) stopPolling()
  } catch (e) { if (isCurrent(context)) error.value = errorMessage(e) }
}

async function loadReport(page: number) {
  if (!job.value || page < 1 || page > reportPages.value || reportLoading.value) return
  const id = job.value.id
  const context = captureContext('report')
  reportLoading.value = true
  try {
    const rows = await openingStockImportApi.items(id, page)
    if (!isCurrent(context) || job.value?.id !== id) return
    report.value = rows
    reportPage.value = rows.pagination.page
  } catch (e) { if (isCurrent(context)) error.value = errorMessage(e) }
  finally { if (isCurrent(context)) reportLoading.value = false }
}

function startPolling() {
  stopPolling()
  void refresh()
  if (active.value) timer = setInterval(() => void refresh(), 2000)
}
function stopPolling() { if (timer) clearInterval(timer); timer = undefined }
async function cancel() {
  if (!job.value || loading.value) return
  const id = job.value.id
  const context = captureContext('cancel')
  loading.value = true
  error.value = ''
  try {
    const nextJob = await openingStockImportApi.cancel(id)
    if (!isCurrent(context) || job.value?.id !== id) return
    job.value = nextJob
    startPolling()
  } catch (e) { if (isCurrent(context)) error.value = errorMessage(e) }
  finally { if (isCurrent(context)) loading.value = false }
}
async function retry() {
  if (!job.value || loading.value) return
  const id = job.value.id
  const context = captureContext('retry')
  loading.value = true
  error.value = ''
  try {
    const nextJob = await openingStockImportApi.retry(id)
    if (!isCurrent(context) || job.value?.id !== id) return
    job.value = nextJob
    startPolling()
  } catch (e) { if (isCurrent(context)) error.value = errorMessage(e) }
  finally { if (isCurrent(context)) loading.value = false }
}
function reset() {
  generation++
  stopPolling()
  loading.value = false; sampleLoading.value = false; reportLoading.value = false
  source.value = null; sample.value = null; job.value = null; report.value = null; reportPage.value = 1; error.value = ''
  void router.replace({ query: {} })
}

async function initialize() {
  generation++
  const context = captureContext('initialize')
  try {
    const nextWarehouses = await stockApi.listWarehouses(true)
    if (!isCurrent(context)) return
    warehouses.value = nextWarehouses
    config.value.warehouse_id = nextWarehouses.find(item => item.is_default)?.id ?? nextWarehouses[0]?.id ?? 0
    const id = Number(route.query.opening_job)
    if (Number.isInteger(id) && id > 0) {
      const nextJob = await openingStockImportApi.job(id)
      if (!isCurrent(context)) return
      job.value = nextJob
      reportPage.value = 1
      startPolling()
    }
  } catch (e) { if (isCurrent(context)) error.value = errorMessage(e) }
}
watch(() => supplier.currentSupplierId, () => { reset(); void initialize() })
watch(() => [config.value.reader.encoding, config.value.reader.delimiter, config.value.reader.sheet], () => {
  if (source.value) void reloadSample()
})
onMounted(initialize)
onBeforeUnmount(() => { generation++; stopPolling() })
</script>

<template>
  <div class="space-y-5">
    <header class="flex flex-wrap items-start justify-between gap-3">
      <div><h1 class="text-2xl font-semibold">{{ t('stock.opening_import.title') }}</h1><p class="mt-1 text-sm text-neutral-500">{{ t('stock.opening_import.subtitle') }}</p></div>
      <RouterLink to="/stock/documents" :class="btnOutline('neutral')"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m15 18-6-6 6-6" /></svg>{{ t('stock.opening_import.back') }}</RouterLink>
    </header>
    <p v-if="error" class="rounded-lg border border-danger-200 bg-danger-50 p-3 text-sm text-danger-700">{{ error }}</p>

    <section v-if="!job" class="min-w-0 space-y-5 rounded-xl border border-neutral-200 bg-surface p-5 shadow-sm">
      <div class="grid min-w-0 grid-cols-1 gap-4 md:grid-cols-3">
        <label class="block text-sm"><span class="font-medium">{{ t('stock.opening_import.warehouse') }}</span><select v-model.number="config.warehouse_id" class="input mt-1 w-full"><option :value="0">{{ t('stock.opening_import.select') }}</option><option v-for="warehouse in warehouses" :key="warehouse.id" :value="warehouse.id">{{ warehouse.code }} - {{ warehouse.name }}</option></select></label>
        <label class="block text-sm"><span class="font-medium">{{ t('stock.opening_import.date') }}</span><input v-model="config.doc_date" type="date" class="input mt-1 w-full" /></label>
        <label class="block text-sm"><span class="font-medium">{{ t('stock.opening_import.source_key') }}</span><input v-model.trim="config.source_key" class="input mt-1 w-full" placeholder="erp-2026" /></label>
      </div>
      <label class="block rounded-lg border-2 border-dashed border-neutral-300 p-6 text-center cursor-pointer">
        <svg class="mx-auto h-6 w-6 text-primary-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.upload" /></svg>
        <span class="mt-2 block text-sm font-medium">{{ source?.original_name || t('stock.opening_import.choose_file') }}</span>
        <input class="sr-only" type="file" accept=".csv,.xlsx" :disabled="loading || !auth.canWrite('stock.documents.write')" @change="pickFile" />
      </label>
      <div v-if="source" class="space-y-3 rounded-lg border border-neutral-200 bg-neutral-50 p-4">
        <div class="grid min-w-0 grid-cols-1 gap-3 sm:grid-cols-3">
          <label class="text-sm"><span class="font-medium">{{ t('stock.opening_import.encoding') }}</span><select v-model="config.reader.encoding" data-test="reader-encoding" class="input mt-1 w-full"><option value="UTF-8">UTF-8</option><option value="Windows-1250">Windows-1250</option><option value="ISO-8859-2">ISO-8859-2</option></select></label>
          <label class="text-sm"><span class="font-medium">{{ t('stock.opening_import.delimiter') }}</span><select v-model="config.reader.delimiter" data-test="reader-delimiter" class="input mt-1 w-full"><option value=";">;</option><option value=",">,</option><option :value="'\t'">{{ t('stock.opening_import.tab') }}</option><option value="|">|</option></select></label>
          <label class="text-sm"><span class="font-medium">{{ t('stock.opening_import.sheet') }}</span><input v-model.number="config.reader.sheet" data-test="reader-sheet" type="number" min="0" class="input mt-1 w-full" /></label>
        </div>
        <div class="flex flex-wrap items-center justify-between gap-3">
          <p class="text-xs text-neutral-500">{{ t('stock.opening_import.reader_hint') }}</p>
          <button type="button" data-test="reload-sample" :disabled="sampleLoading || loading" :class="btnOutline('neutral')" @click="reloadSample"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.cycle" /></svg>{{ t('stock.opening_import.reload_sample') }}</button>
        </div>
      </div>
      <div v-if="sample" class="space-y-4">
        <div class="grid min-w-0 grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4"><label v-for="field in sample.fields" :key="field" class="text-sm"><span class="font-medium">{{ t(`stock.opening_import.fields.${field}`) }}</span><select v-model="config.mapping[field]" class="input mt-1 w-full"><option value="">{{ t('stock.opening_import.select') }}</option><option v-for="header in sample.header" :key="header" :value="header">{{ header }}</option></select></label></div>
        <div class="overflow-x-auto rounded-lg border border-neutral-200"><table class="min-w-full text-xs"><thead class="bg-neutral-50"><tr><th v-for="header in sample.header" :key="header" class="px-3 py-2 text-left">{{ header }}</th></tr></thead><tbody><tr v-for="(row, index) in sample.rows" :key="index" class="border-t border-neutral-200"><td v-for="(cell, cellIndex) in row" :key="cellIndex" class="px-3 py-2">{{ cell }}</td></tr></tbody></table></div>
      </div>
      <div class="flex flex-wrap justify-end gap-2"><button type="button" :disabled="!formValid || loading" :class="btnFilled('primary')" @click="createPreview"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.eye" /></svg>{{ t('stock.opening_import.preview') }}</button></div>
    </section>

    <section v-else class="min-w-0 space-y-4 rounded-xl border border-neutral-200 bg-surface p-5 shadow-sm">
      <CatalogJobProgress :job="job" :can-cancel="active" @cancel="cancel" />
      <div class="flex flex-wrap gap-3 text-sm"><span v-for="status in ['ready','applied','unchanged','failed','conflict']" :key="status" class="rounded-full bg-neutral-100 px-3 py-1">{{ t(`stock.opening_import.status.${status}`) }}: {{ counts[status] || 0 }}</span></div>
      <div v-if="report?.items.length" class="overflow-x-auto rounded-lg border border-neutral-200"><table class="min-w-full text-sm"><thead class="bg-neutral-50"><tr><th class="px-3 py-2 text-left">{{ t('stock.opening_import.row') }}</th><th class="px-3 py-2 text-left">SKU</th><th class="px-3 py-2 text-left">{{ t('stock.opening_import.status_label') }}</th><th class="px-3 py-2 text-left">{{ t('stock.opening_import.result') }}</th></tr></thead><tbody><tr v-for="item in report.items" :key="item.ordinal" class="border-t border-neutral-200"><td class="px-3 py-2">{{ item.source_row }}</td><td class="px-3 py-2">{{ item.input.values?.sku }}</td><td class="px-3 py-2">{{ t(`stock.opening_import.status.${item.status}`) }}</td><td class="px-3 py-2 text-danger-700">{{ item.error_code ? t(`stock.opening_import.errors.${item.error_code}`, item.error_code) : '' }}</td></tr></tbody></table></div>
      <div v-if="report && reportPages > 1" class="flex flex-wrap items-center justify-between gap-3 border-t border-neutral-200 pt-3">
        <button type="button" data-test="previous-page" :disabled="reportLoading || reportPage <= 1" :class="btnOutline('neutral')" @click="loadReport(reportPage - 1)"><svg class="h-4 w-4 rotate-90" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.chevron" /></svg>{{ t('common.previous') }}</button>
        <span class="text-sm text-neutral-500">{{ t('eshop.import2.page', { page: reportPage, pages: reportPages }) }}</span>
        <button type="button" data-test="next-page" :disabled="reportLoading || reportPage >= reportPages" :class="btnOutline('neutral')" @click="loadReport(reportPage + 1)">{{ t('common.next') }}<svg class="h-4 w-4 -rotate-90" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.chevron" /></svg></button>
      </div>
      <div class="flex flex-wrap justify-end gap-2"><button type="button" :class="btnOutline('neutral')" @click="reset"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.plus" /></svg>{{ t('stock.opening_import.new_import') }}</button><button v-if="['failed','cancelled'].includes(job.status)" type="button" :class="btnOutline('primary')" @click="retry"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.cycle" /></svg>{{ t('common.retry') }}</button><button v-if="canApply" type="button" data-test="apply-import" :disabled="loading" :class="btnFilled('success')" @click="apply"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.check" /></svg>{{ t('stock.opening_import.apply') }}</button><RouterLink v-if="job.kind === 'stock_opening_import_apply' && job.status === 'completed'" to="/stock/documents?doc_type=receipt" :class="btnFilled('primary')"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.doc" /></svg>{{ t('stock.opening_import.open_documents') }}</RouterLink></div>
    </section>
  </div>
</template>

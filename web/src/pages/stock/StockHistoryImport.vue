<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import {
  stockHistoryImportApi, type HistoryImportConfig, type HistoryImportEndState, type HistoryImportField,
  type HistoryImportReport, type HistoryImportSample, type HistoryImportStatus, type HistoryImportType,
} from '@/api/stockHistoryImport'
import type { OpeningImportJobItems, OpeningImportSource } from '@/api/openingStockImport'
import type { CatalogJob } from '@/api/catalogJobs'
import CatalogJobProgress from '@/components/stock/CatalogJobProgress.vue'
import DateInput from '@/components/ui/DateInput.vue'
import { btnFilled, btnOutline, ICONS } from '@/components/ui/buttonStyles'
import { useAuthStore } from '@/stores/auth'
import { useSupplierStore } from '@/stores/supplier'
import { formatDate, formatNumber } from '@/composables/useFormat'

const STAGE_KIND = 'stock_history_import_stage'
const APPLY_KIND = 'stock_history_import_apply'
const ROLLBACK_KIND = 'stock_history_import_rollback'
const TYPES: HistoryImportType[] = ['receipt', 'issue', 'transfer', 'surplus', 'shortage']
/** Zámek celého skladu po dobu převzetí nebo vrácení bez data přechodu. */
const LOCK_RUNNING = '9999-12-31'
const END_STATE_ROWS = 100

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const supplier = useSupplierStore()
const status = ref<HistoryImportStatus | null>(null)
const source = ref<OpeningImportSource | null>(null)
const sample = ref<HistoryImportSample | null>(null)
const job = ref<CatalogJob | null>(null)
const report = ref<OpeningImportJobItems | null>(null)
const reportPage = ref(1)
const reportLoading = ref(false)
const sampleLoading = ref(false)
const loading = ref(false)
const error = ref('')
const previewForm = ref<HTMLFormElement | null>(null)
const liveMode = ref(false)
const confirmClosed = ref(false)
const applyNeedsConfirm = ref(false)
const rollbackOpen = ref(false)
/** Vrácení smaže doklady v uzavřeném období: backend chce stejné potvrzení jako převzetí. */
const rollbackNeedsConfirm = ref(false)
const rollbackConfirmClosed = ref(false)
const endState = ref<HistoryImportEndState | null>(null)
let timer: ReturnType<typeof setInterval> | undefined
let generation = 0
const requestVersions = new Map<string, number>()

interface RequestContext {
  kind: string
  version: number
  generation: number
  supplierId: number | null
}

const config = ref<HistoryImportConfig>({
  source_key: '',
  cutover_date: null,
  mapping: {},
  number_prefixes: {},
  fill_negative: false,
  reader: { encoding: 'UTF-8', delimiter: ';', sheet: 0 },
})
const summary = computed<HistoryImportReport>(() => (job.value?.report ?? {}) as HistoryImportReport)
const counts = computed<Record<string, number>>(() => {
  const raw = job.value?.report?.counts
  return raw && typeof raw === 'object' && !Array.isArray(raw)
    ? Object.fromEntries(Object.entries(raw).map(([key, value]) => [key, Number(value) || 0])) : {}
})
const running = computed(() => !!job.value && ['queued', 'running'].includes(job.value.status))
const reportPages = computed(() => Math.max(1, report.value?.pagination.pages ?? 1))
/** Firma má převzatou historii (nebo ji právě převádí): nový import až po vrácení. */
const activeImport = computed(() => status.value?.active ?? null)
const hasDocuments = computed(() => !!status.value?.first_document_date)
const negatives = computed(() => Number(summary.value.negative_count ?? 0))
// Doklady bez vazby na fakturu: v náhledu předem, ve výsledku převzetí ty, u kterých se faktura mezitím změnila.
const unlinked = computed(() => {
  if (job.value?.kind === STAGE_KIND) {
    return { test: 'invoice-unlinked', title: 'stock.history_import.invoice_unlinked_title', count: Number(summary.value.invoice_unlinked_count ?? 0), items: summary.value.invoice_unlinked ?? [] }
  }
  if (job.value?.kind === APPLY_KIND) {
    return { test: 'invoice-unlinked-on-apply', title: 'stock.history_import.invoice_unlinked_on_apply', count: Number(summary.value.invoice_unlinked_on_apply ?? 0), items: summary.value.invoice_unlinked_on_apply_list ?? [] }
  }
  return null
})
const closedPeriods = computed(() => Number(summary.value.closed_period_documents ?? 0))
const previewReady = computed(() => job.value?.kind === STAGE_KIND && job.value.status === 'completed' && !job.value.apply_job_id
  && (counts.value.ready ?? 0) > 0 && (counts.value.failed ?? 0) === 0 && (counts.value.conflict ?? 0) === 0)
/** Zápor bez volby doplnění a nepotvrzené uzavřené období aplikaci blokují (backend vrátí 409). */
const canApply = computed(() => previewReady.value && !activeImport.value
  && (negatives.value === 0 || summary.value.fill_negative === true) && (!(closedPeriods.value > 0 || applyNeedsConfirm.value) || confirmClosed.value))
/** Zadání aktivního importu (profil a rozsah dat), status ho vrací bez otisku zdroje. */
const activeInput = computed(() => (activeImport.value as (CatalogJob & { input?: { profile?: { cutover_date: string | null }, date_from?: string, date_to?: string } }) | null)?.input ?? null)
/** Převzatá historie bez úlohy (obnova z archivu): blokuje nový import, správce ji vrátí. */
const orphanId = computed(() => activeImport.value ? null : status.value?.orphan_job_id ?? null)
const rollbackTarget = computed(() => activeImport.value?.id ?? orphanId.value)
const canRollback = computed(() => auth.isCompanyAdminRole && rollbackTarget.value !== null
  && !['queued', 'running'].includes(activeImport.value?.status ?? '') && !running.value)
/** Běžící převzetí nebo vrácení zamyká celý sklad: popisuje ho samostatná věta, ne datum. */
const lockRunning = computed(() => status.value?.locked_until === LOCK_RUNNING)
const activeStatusText = computed(() => !lockRunning.value ? 'stock.history_import.active_text'
  : ['failed', 'cancelled'].includes(activeImport.value?.status ?? '') ? 'stock.history_import.active_text_stopped'
    : 'stock.history_import.active_text_running')
const today = new Date().toLocaleDateString('sv')
/** Datum přechodu je nejpozději první skladový doklad firmy a nejpozději dnes. */
const cutoverMax = computed(() => [status.value?.first_document_date, today].filter((d): d is string => !!d).sort()[0])
const periodReviewRequired = computed(() => job.value?.kind !== STAGE_KIND && job.value?.error_code === 'history_import_closed_periods_unconfirmed')
/** Kontrolu souboru jde opakovat vždy, převzetí jen to poslední (aktivní); starší úlohu backend odmítne. */
const canRetry = computed(() => !!job.value && ['failed', 'cancelled'].includes(job.value.status)
  && !periodReviewRequired.value
  && (job.value.kind === STAGE_KIND || (job.value.kind === APPLY_KIND && job.value.id === activeImport.value?.id)))
/** Zastavené převzetí bez data přechodu nechá zamčený sklad: zastavit ho smí jen správce firmy. */
const canCancel = computed(() => running.value && (job.value?.kind !== APPLY_KIND || auth.isCompanyAdminRole))
const endStateDifferences = computed(() => (endState.value?.items ?? [])
  .filter(row => row.difference !== null && Number(row.difference) !== 0))
const mappedFields = computed<HistoryImportField[]>(() => [...(sample.value?.fields ?? []), ...(sample.value?.optional_fields ?? [])])
const formValid = computed(() => !!source.value
  && (liveMode.value ? !hasDocuments.value : /^\d{4}-\d{2}-\d{2}$/.test(config.value.cutover_date ?? ''))
  && /^[a-z0-9][a-z0-9_.-]{0,99}$/.test(config.value.source_key)
  && (sample.value?.fields ?? []).every(field => !!config.value.mapping[field]))

/** Množství a ceny z API přicházejí jako desetinný řetězec („1.000“), uživatel je čte česky. */
function qty(value: string | null | undefined): string {
  return value === null || value === undefined ? '' : formatNumber(Number(value), { maximumFractionDigits: 3 })
}
function cost(value: string | null | undefined): string {
  return value === null || value === undefined ? '' : formatNumber(Number(value), { minimumFractionDigits: 2, maximumFractionDigits: 6 })
}

/** Převzatý řádek není „zaúčtovaný“ (historie se neúčtuje), ostatní stavy jako import zásob. */
function statusLabel(state: string): string {
  return state === 'applied' ? t('stock.history_import.status_applied') : t(`stock.opening_import.status.${state}`)
}
/** Chybný řádek nemá rozpoznané hodnoty, ukáže se aspoň jeho obsah ze souboru. */
function rawRow(item: { input: object }): string {
  const raw = (item.input as { raw?: unknown }).raw
  return Array.isArray(raw) ? raw.filter(cell => cell !== '' && cell !== null).join('; ') : ''
}

function errorCode(e: any): string | undefined {
  return e?.response?.data?.error?.code
}

function errorMessage(e: any) {
  const body = e?.response?.data?.error
  const code = body?.code === 'validation_failed' ? body?.message : body?.code
  return code ? t(`stock.history_import.errors.${code}`, body?.message || code) : t('stock.history_import.error')
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
  const aliases: Record<HistoryImportField, string[]> = {
    doc_number: ['doc_number', 'doklad', 'cislo', 'cislo_dokladu', 'number'], doc_type: ['doc_type', 'typ', 'type', 'druh'],
    doc_date: ['doc_date', 'datum', 'date'], warehouse: ['warehouse', 'sklad'], sku: ['sku', 'kod', 'code'],
    quantity: ['quantity', 'qty', 'mnozstvi'], warehouse_to: ['warehouse_to', 'sklad_cil', 'cilovy_sklad'],
    unit_cost: ['unit_cost', 'cost', 'cena', 'nakupni_cena'], partner: ['partner', 'firma', 'odberatel', 'dodavatel'],
    description: ['description', 'popis'], invoice_number: ['invoice_number', 'faktura_vydana', 'faktura'],
    purchase_invoice_number: ['purchase_invoice_number', 'faktura_prijata'], note: ['note', 'poznamka'],
  }
  const normalized = headers.map(header => ({ header, key: header.trim().toLocaleLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[\s-]+/g, '_') }))
  const mapping: HistoryImportConfig['mapping'] = {}
  for (const [field, names] of Object.entries(aliases) as Array<[HistoryImportField, string[]]>) {
    mapping[field] = normalized.find(candidate => names.includes(candidate.key))?.header ?? ''
  }
  config.value.mapping = mapping
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
    const uploaded = await stockHistoryImportApi.upload(file)
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
    const nextSample = await stockHistoryImportApi.sample(sourceId, { ...config.value.reader })
    if (!isCurrent(context) || source.value?.id !== sourceId) return
    sample.value = nextSample
    autoMap(nextSample.header)
  } catch (e) { if (isCurrent(context)) error.value = errorMessage(e) }
  finally { if (isCurrent(context)) sampleLoading.value = false }
}

async function showJob(nextJob: CatalogJob, context: RequestContext) {
  job.value = nextJob
  report.value = null
  endState.value = null
  reportPage.value = 1
  confirmClosed.value = false
  applyNeedsConfirm.value = false
  await router.replace({ query: { ...route.query, history_job: String(nextJob.id) } })
  if (!isCurrent(context) || job.value?.id !== nextJob.id) return
  startPolling()
}

async function createPreview() {
  if (!formValid.value || loading.value) return
  if (!previewForm.value?.reportValidity()) return
  const sourceId = source.value!.id
  const context = captureContext('preview')
  loading.value = true
  error.value = ''
  try {
    const payload: HistoryImportConfig = { ...config.value, cutover_date: liveMode.value ? null : config.value.cutover_date }
    const nextJob = await stockHistoryImportApi.preview(sourceId, payload)
    if (!isCurrent(context) || source.value?.id !== sourceId) return
    await showJob(nextJob, context)
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
    const nextJob = await stockHistoryImportApi.apply(previewId, confirmClosed.value)
    if (!isCurrent(context) || job.value?.id !== previewId) return
    await showJob(nextJob, context)
    await loadStatus()
  } catch (e) {
    if (isCurrent(context)) {
      if (errorCode(e) === 'history_import_closed_periods_unconfirmed') applyNeedsConfirm.value = true
      error.value = errorMessage(e)
    }
  }
  finally { if (isCurrent(context)) loading.value = false }
}

function openRollback() {
  rollbackNeedsConfirm.value = false
  rollbackConfirmClosed.value = false
  rollbackOpen.value = true
}

async function rollback() {
  const target = rollbackTarget.value
  if (!canRollback.value || target === null || loading.value) return
  const context = captureContext('rollback')
  loading.value = true
  error.value = ''
  try {
    const nextJob = await stockHistoryImportApi.rollback(target, rollbackConfirmClosed.value)
    if (!isCurrent(context)) return
    rollbackOpen.value = false
    await showJob(nextJob, context)
    await loadStatus()
  } catch (e) {
    if (!isCurrent(context)) return
    if (errorCode(e) === 'history_import_closed_periods_unconfirmed') rollbackNeedsConfirm.value = true
    else { rollbackOpen.value = false; error.value = errorMessage(e) }
  } finally { if (isCurrent(context)) loading.value = false }
}

async function loadEndState(id: number) {
  const context = captureContext('end-state')
  try {
    const next = await stockHistoryImportApi.endState(id)
    if (isCurrent(context) && job.value?.id === id) endState.value = next
  } catch (e) { if (isCurrent(context)) error.value = errorMessage(e) }
}

async function downloadEndState() {
  if (!job.value) return
  try { await stockHistoryImportApi.downloadEndState(job.value.id) }
  catch (e) { error.value = errorMessage(e) }
}

async function loadStatus() {
  const context = captureContext('status')
  const nextStatus = await stockHistoryImportApi.status()
  if (!isCurrent(context)) return
  status.value = nextStatus
  if (!config.value.cutover_date) config.value.cutover_date = nextStatus.first_document_date
  // Firma bez skladových dokladů typicky přechází celá: historie je současný stav.
  liveMode.value = !nextStatus.first_document_date
}

async function refresh() {
  if (!job.value) return
  const id = job.value.id
  const page = reportPage.value
  const context = captureContext('refresh')
  try {
    const [next, rows] = await Promise.all([stockHistoryImportApi.job(id), stockHistoryImportApi.items(id, page)])
    if (!isCurrent(context) || job.value?.id !== id) return
    job.value = next
    if (reportPage.value === page) report.value = rows
    if (!['queued', 'running'].includes(next.status)) {
      stopPolling()
      if (next.kind !== STAGE_KIND) await loadStatus()
      else if (next.status === 'completed' && !endState.value) await loadEndState(id)
    }
  } catch (e) { if (isCurrent(context)) error.value = errorMessage(e) }
}

async function loadReport(page: number) {
  if (!job.value || page < 1 || page > reportPages.value || reportLoading.value) return
  const id = job.value.id
  const context = captureContext('report')
  reportLoading.value = true
  try {
    const rows = await stockHistoryImportApi.items(id, page)
    if (!isCurrent(context) || job.value?.id !== id) return
    report.value = rows
    reportPage.value = rows.pagination.page
  } catch (e) { if (isCurrent(context)) error.value = errorMessage(e) }
  finally { if (isCurrent(context)) reportLoading.value = false }
}

function startPolling() {
  stopPolling()
  void refresh()
  if (running.value) timer = setInterval(() => void refresh(), 2000)
}
function stopPolling() { if (timer) clearInterval(timer); timer = undefined }
async function changeJob(operation: 'cancel' | 'retry') {
  if (!job.value || loading.value) return
  const id = job.value.id
  const context = captureContext(operation)
  loading.value = true
  error.value = ''
  try {
    const nextJob = await stockHistoryImportApi[operation](id)
    if (!isCurrent(context) || job.value?.id !== id) return
    job.value = nextJob
    startPolling()
  } catch (e) { if (isCurrent(context)) error.value = errorMessage(e) }
  finally { if (isCurrent(context)) loading.value = false }
}
async function openActive() {
  if (!activeImport.value) return
  const context = captureContext('open-active')
  await showJob(activeImport.value, context)
}
function reset() {
  generation++
  stopPolling()
  loading.value = false; sampleLoading.value = false; reportLoading.value = false
  source.value = null; sample.value = null; job.value = null; report.value = null; endState.value = null; reportPage.value = 1; error.value = ''
  confirmClosed.value = false; applyNeedsConfirm.value = false; rollbackOpen.value = false
  void router.replace({ query: {} })
  void initialize()
}

async function initialize() {
  generation++
  const context = captureContext('initialize')
  try {
    await loadStatus()
    if (!isCurrent(context)) return
    const id = Number(route.query.history_job)
    if (Number.isInteger(id) && id > 0) {
      const nextJob = await stockHistoryImportApi.job(id)
      if (!isCurrent(context)) return
      job.value = nextJob
      reportPage.value = 1
      startPolling()
    }
  } catch (e) { if (isCurrent(context)) error.value = errorMessage(e) }
}
watch(() => supplier.currentSupplierId, () => {
  generation++
  stopPolling()
  loading.value = false; sampleLoading.value = false; reportLoading.value = false
  confirmClosed.value = false; applyNeedsConfirm.value = false
  rollbackOpen.value = false; rollbackNeedsConfirm.value = false; rollbackConfirmClosed.value = false
  reportPage.value = 1; error.value = ''
  source.value = null; sample.value = null; job.value = null; report.value = null; endState.value = null; status.value = null
  config.value.cutover_date = null
  void router.replace({ query: {} })
  void initialize()
})
watch(() => [config.value.reader.encoding, config.value.reader.delimiter, config.value.reader.sheet], () => {
  if (source.value) void reloadSample()
})
onMounted(initialize)
onBeforeUnmount(() => { generation++; stopPolling() })
</script>

<template>
  <div class="space-y-5">
    <header class="flex flex-wrap items-start justify-between gap-3">
      <div><h1 class="text-2xl font-semibold">{{ t('stock.history_import.title') }}</h1><p class="mt-1 text-sm text-neutral-500">{{ t('stock.history_import.subtitle', { rows: formatNumber(status?.max_rows ?? 300000) }) }}</p></div>
      <RouterLink to="/stock/documents" :class="btnOutline('neutral')"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m15 18-6-6 6-6" /></svg>{{ t('stock.history_import.back') }}</RouterLink>
    </header>
    <p v-if="error" class="rounded-lg border border-danger-200 bg-danger-50 p-3 text-sm text-danger-700">{{ error }}</p>

    <section v-if="activeImport" data-test="active-import" class="flex min-w-0 flex-wrap items-center justify-between gap-3 rounded-xl border border-primary-200 bg-primary-50 p-4 text-sm">
      <div class="min-w-0">
        <p class="font-medium">{{ t('stock.history_import.active_title') }}</p>
        <p class="text-neutral-600">{{ t(activeStatusText, { id: activeImport.id, from: formatDate(activeInput?.date_from), to: formatDate(activeInput?.date_to), locked: formatDate(status?.locked_until) }) }}</p>
      </div>
      <div class="flex flex-wrap gap-2">
        <button v-if="job?.id !== activeImport.id" type="button" data-test="show-active" :class="btnOutline('neutral')" @click="openActive"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.eye" /></svg>{{ t('stock.history_import.show_import') }}</button>
        <button v-if="canRollback" type="button" data-test="rollback-open" :disabled="loading" :class="btnOutline('danger')" @click="openRollback"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.uturn" /></svg>{{ t('stock.history_import.rollback') }}</button>
      </div>
      <p v-if="!auth.isCompanyAdminRole" class="w-full text-xs text-neutral-500">{{ t('stock.history_import.rollback_admin_only') }}</p>
    </section>

    <section v-if="orphanId !== null" data-test="orphan-import" class="flex min-w-0 flex-wrap items-center justify-between gap-3 rounded-xl border border-warning-200 bg-warning-50 p-4 text-sm">
      <div class="min-w-0">
        <p class="font-medium">{{ t('stock.history_import.orphan_title') }}</p>
        <p class="text-neutral-600">{{ t(lockRunning ? 'stock.history_import.orphan_text_running' : 'stock.history_import.orphan_text', { locked: formatDate(status?.locked_until) }) }}</p>
      </div>
      <button v-if="canRollback" type="button" data-test="rollback-open" :disabled="loading" :class="btnOutline('danger')" @click="openRollback"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.uturn" /></svg>{{ t('stock.history_import.rollback') }}</button>
      <p v-if="!auth.isCompanyAdminRole" class="w-full text-xs text-neutral-500">{{ t('stock.history_import.rollback_admin_only') }}</p>
    </section>

    <form v-if="!job && !activeImport && orphanId === null && status" ref="previewForm" class="min-w-0 space-y-5 rounded-xl border border-neutral-200 bg-surface p-5 shadow-sm" @submit.prevent="createPreview">
      <fieldset class="space-y-2 text-sm">
        <legend class="font-medium">{{ t('stock.history_import.mode') }}</legend>
        <label class="flex items-start gap-2"><input v-model="liveMode" type="radio" :value="false" data-test="mode-cutover" class="mt-1" /><span><span class="font-medium">{{ t('stock.history_import.mode_cutover') }}</span><span class="block text-neutral-500">{{ t('stock.history_import.mode_cutover_hint') }}</span></span></label>
        <label class="flex items-start gap-2" :class="{ 'opacity-50': hasDocuments }"><input v-model="liveMode" type="radio" :value="true" data-test="mode-live" :disabled="hasDocuments" class="mt-1" /><span><span class="font-medium">{{ t('stock.history_import.mode_live') }}</span><span class="block text-neutral-500">{{ hasDocuments ? t('stock.history_import.mode_live_blocked', { date: formatDate(status.first_document_date) }) : t('stock.history_import.mode_live_hint') }}</span></span></label>
      </fieldset>
      <div class="grid min-w-0 grid-cols-1 gap-4 md:grid-cols-2">
        <label v-if="!liveMode" class="block text-sm"><span class="font-medium">{{ t('stock.history_import.cutover_date') }}</span><DateInput v-model="config.cutover_date" data-test="cutover-date" :max="cutoverMax" class="form-input mt-1 w-full" /><span class="mt-1 block text-xs text-neutral-500">{{ t('stock.history_import.cutover_hint') }}</span></label>
        <label class="block text-sm"><span class="font-medium">{{ t('stock.history_import.source_key') }}</span><input v-model.trim="config.source_key" data-test="source-key" class="form-input mt-1 w-full" placeholder="stary-sklad" /></label>
      </div>
      <label class="block rounded-lg border-2 border-dashed border-neutral-300 p-6 text-center cursor-pointer">
        <svg class="mx-auto h-6 w-6 text-primary-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.upload" /></svg>
        <span class="mt-2 block text-sm font-medium">{{ source?.original_name || t('stock.history_import.choose_file') }}</span>
        <input class="sr-only" type="file" accept=".csv,.xlsx" :disabled="loading || !auth.canWrite('stock.documents.write')" @change="pickFile" />
      </label>
      <div v-if="source" class="space-y-3 rounded-lg border border-neutral-200 bg-neutral-50 p-4">
        <div class="grid min-w-0 grid-cols-1 gap-3 sm:grid-cols-3">
          <label class="text-sm"><span class="font-medium">{{ t('stock.opening_import.encoding') }}</span><select v-model="config.reader.encoding" class="form-select mt-1 w-full"><option value="UTF-8">UTF-8</option><option value="Windows-1250">Windows-1250</option><option value="ISO-8859-2">ISO-8859-2</option></select></label>
          <label class="text-sm"><span class="font-medium">{{ t('stock.opening_import.delimiter') }}</span><select v-model="config.reader.delimiter" class="form-select mt-1 w-full"><option value=";">;</option><option value=",">,</option><option :value="'\t'">{{ t('stock.opening_import.tab') }}</option><option value="|">|</option></select></label>
          <label class="text-sm"><span class="font-medium">{{ t('stock.opening_import.sheet') }}</span><input v-model.number="config.reader.sheet" type="number" min="0" class="form-input mt-1 w-full" /></label>
        </div>
        <div class="flex flex-wrap items-center justify-between gap-3">
          <p class="text-xs text-neutral-500">{{ t('stock.opening_import.reader_hint') }}</p>
          <button type="button" :disabled="sampleLoading || loading" :class="btnOutline('neutral')" @click="reloadSample"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.cycle" /></svg>{{ t('stock.opening_import.reload_sample') }}</button>
        </div>
      </div>
      <div v-if="sample" class="space-y-4">
        <div class="grid min-w-0 grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <label v-for="field in mappedFields" :key="field" class="text-sm"><span class="font-medium">{{ t(`stock.history_import.fields.${field}`) }}<span v-if="sample.fields.includes(field)" class="text-danger-600"> *</span></span><select v-model="config.mapping[field]" :data-test="`map-${field}`" class="form-select mt-1 w-full"><option value="">{{ sample.fields.includes(field) ? t('stock.opening_import.select') : t('stock.history_import.not_mapped') }}</option><option v-for="header in sample.header" :key="header" :value="header">{{ header }}</option></select></label>
        </div>
        <div class="overflow-x-auto rounded-lg border border-neutral-200"><table class="min-w-full text-xs"><thead class="bg-neutral-50"><tr><th v-for="header in sample.header" :key="header" class="px-3 py-2 text-left">{{ header }}</th></tr></thead><tbody><tr v-for="(row, index) in sample.rows" :key="index" class="border-t border-neutral-200"><td v-for="(cell, cellIndex) in row" :key="cellIndex" class="px-3 py-2">{{ cell }}</td></tr></tbody></table></div>
        <details class="rounded-lg border border-neutral-200 p-4 text-sm">
          <summary class="cursor-pointer font-medium">{{ t('stock.history_import.prefixes') }}</summary>
          <p class="mt-2 text-xs text-neutral-500">{{ t('stock.history_import.prefixes_hint') }}</p>
          <div class="mt-3 grid min-w-0 grid-cols-2 gap-3 sm:grid-cols-5"><label v-for="type in TYPES" :key="type" class="text-xs"><span class="font-medium">{{ t(`stock.history_import.types.${type}`) }}</span><input v-model.trim="config.number_prefixes[type]" maxlength="10" class="form-input mt-1 w-full" /></label></div>
        </details>
        <label class="flex items-start gap-2 text-sm"><input v-model="config.fill_negative" type="checkbox" data-test="fill-negative" class="mt-1" /><span><span class="font-medium">{{ t('stock.history_import.fill_negative') }}</span><span class="block text-neutral-500">{{ t('stock.history_import.fill_negative_hint') }}</span></span></label>
      </div>
      <div class="flex flex-wrap justify-end gap-2"><button type="button" data-test="create-preview" :disabled="!formValid || loading" :class="btnFilled('primary')" @click="createPreview"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.eye" /></svg>{{ t('stock.history_import.preview') }}</button></div>
    </form>

    <section v-if="job" class="min-w-0 space-y-4 rounded-xl border border-neutral-200 bg-surface p-5 shadow-sm">
      <p class="text-sm font-medium">{{ t(`stock.history_import.job_kind.${job.kind === ROLLBACK_KIND ? 'rollback' : job.kind === APPLY_KIND ? 'apply' : 'preview'}`) }}</p>
      <CatalogJobProgress :job="job" :can-cancel="canCancel" @cancel="changeJob('cancel')" />

      <div v-if="summary.documents" data-test="summary" class="grid min-w-0 grid-cols-2 gap-3 text-sm sm:grid-cols-4">
        <div v-for="type in TYPES" :key="type" class="rounded-lg bg-neutral-50 p-3"><p class="text-xs text-neutral-500">{{ t(`stock.history_import.types.${type}`) }}</p><p class="font-semibold">{{ summary.documents[type] ?? 0 }}</p></div>
        <div class="rounded-lg bg-neutral-50 p-3"><p class="text-xs text-neutral-500">{{ t('stock.history_import.lines') }}</p><p class="font-semibold">{{ summary.lines ?? 0 }}</p></div>
        <div class="rounded-lg bg-neutral-50 p-3"><p class="text-xs text-neutral-500">{{ t('stock.history_import.period') }}</p><p class="font-semibold"><span class="whitespace-nowrap">{{ formatDate(summary.date_from) }}</span> – <span class="whitespace-nowrap">{{ formatDate(summary.date_to) }}</span></p></div>
        <div class="rounded-lg bg-neutral-50 p-3"><p class="text-xs text-neutral-500">{{ t('stock.history_import.cutover_date') }}</p><p class="font-semibold">{{ summary.cutover_date ? formatDate(summary.cutover_date) : t('stock.history_import.mode_live') }}</p></div>
      </div>
      <p v-if="job.kind === APPLY_KIND" data-test="apply-summary" class="text-sm">{{ t('stock.history_import.documents_created', { count: summary.documents_created ?? 0, fills: summary.fill_documents ?? 0 }) }}</p>
      <p v-if="job.kind === ROLLBACK_KIND" data-test="rollback-summary" class="text-sm">{{ t('stock.history_import.documents_deleted', { count: summary.documents_deleted ?? 0 }) }}</p>

      <div v-if="job.kind === STAGE_KIND && negatives > 0" data-test="negatives" class="space-y-2 rounded-lg border p-4 text-sm" :class="summary.fill_negative ? 'border-warning-200 bg-warning-50' : 'border-danger-200 bg-danger-50'">
        <p class="font-medium">{{ t(summary.fill_negative ? 'stock.history_import.negatives_filled' : 'stock.history_import.negatives_blocking', { count: negatives }) }}</p>
        <div class="overflow-x-auto"><table class="min-w-full whitespace-nowrap text-xs"><thead><tr><th class="px-2 py-1 text-left">{{ t('stock.opening_import.row') }}</th><th class="px-2 py-1 text-left">{{ t('stock.history_import.fields.doc_number') }}</th><th class="px-2 py-1 text-left">{{ t('stock.history_import.fields.doc_date') }}</th><th class="px-2 py-1 text-left">{{ t('stock.history_import.fields.warehouse') }}</th><th class="px-2 py-1 text-left">SKU</th><th class="px-2 py-1 text-right">{{ t('stock.history_import.missing_quantity') }}</th><th class="px-2 py-1 text-right">{{ t('stock.history_import.fill_cost') }}</th></tr></thead><tbody><tr v-for="item in summary.negatives ?? []" :key="item.source_row" class="border-t border-neutral-200"><td class="px-2 py-1">{{ item.source_row }}</td><td class="px-2 py-1">{{ item.doc_number }}</td><td class="px-2 py-1">{{ formatDate(item.doc_date) }}</td><td class="px-2 py-1">{{ item.warehouse }}</td><td class="px-2 py-1">{{ item.sku }}</td><td class="px-2 py-1 text-right">{{ qty(item.missing_quantity) }}</td><td class="px-2 py-1 text-right">{{ cost(item.unit_cost) }}</td></tr></tbody></table></div>
        <p v-if="negatives > (summary.negatives?.length ?? 0)" class="text-xs">{{ t('stock.history_import.negatives_more', { count: negatives - (summary.negatives?.length ?? 0) }) }}</p>
      </div>
      <div v-if="unlinked?.count" :data-test="unlinked.test" class="space-y-2 rounded-lg border border-warning-200 bg-warning-50 p-4 text-sm">
        <p class="font-medium">{{ t(unlinked.title, { count: unlinked.count }) }}</p>
        <div class="overflow-x-auto"><table class="min-w-full whitespace-nowrap text-xs"><thead><tr><th class="px-2 py-1 text-left">{{ t('stock.opening_import.row') }}</th><th class="px-2 py-1 text-left">{{ t('stock.history_import.fields.doc_number') }}</th><th class="px-2 py-1 text-left">{{ t('stock.history_import.fields.invoice_number') }}</th><th class="px-2 py-1 text-left">{{ t('stock.history_import.invoice_unlinked_reason_label') }}</th></tr></thead><tbody><tr v-for="item in unlinked.items" :key="item.source_row" class="border-t border-warning-200"><td class="px-2 py-1">{{ item.source_row }}</td><td class="px-2 py-1">{{ item.doc_number }}</td><td class="px-2 py-1">{{ item.invoice_number }}</td><td class="whitespace-normal px-2 py-1">{{ t(`stock.history_import.invoice_unlinked_reason.${item.reason}`) }}</td></tr></tbody></table></div>
        <p v-if="unlinked.count > unlinked.items.length" class="text-xs">{{ t('stock.history_import.invoice_unlinked_more', { count: unlinked.count - unlinked.items.length }) }}</p>
      </div>
      <div v-if="job.kind === STAGE_KIND && endState" data-test="end-state" class="min-w-0 space-y-2 rounded-lg border border-neutral-200 p-4 text-sm">
        <div class="flex flex-wrap items-center justify-between gap-2">
          <p class="font-medium">{{ t('stock.history_import.end_state_title') }}</p>
          <button type="button" data-test="end-state-download" :class="btnOutline('neutral')" @click="downloadEndState"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.download" /></svg>{{ t('stock.history_import.end_state_download') }}</button>
        </div>
        <p>{{ endState.cutover_date ? t('stock.history_import.end_state_summary', { count: endState.totals.count, differences: endState.totals.differences, date: formatDate(endState.cutover_date) }) : t('stock.history_import.end_state_summary_live', { count: endState.totals.count }) }}</p>
        <div v-if="endStateDifferences.length" class="overflow-x-auto"><table class="min-w-full whitespace-nowrap text-xs"><thead><tr><th class="px-2 py-1 text-left">{{ t('stock.history_import.fields.warehouse') }}</th><th class="px-2 py-1 text-left">SKU</th><th class="px-2 py-1 text-left">{{ t('stock.history_import.end_state_name') }}</th><th class="px-2 py-1 text-right">{{ t('stock.history_import.end_state_history') }}</th><th class="px-2 py-1 text-right">{{ t('stock.history_import.end_state_stock') }}</th><th class="px-2 py-1 text-right">{{ t('stock.history_import.end_state_difference') }}</th></tr></thead><tbody><tr v-for="row in endStateDifferences.slice(0, END_STATE_ROWS)" :key="`${row.warehouse_code}/${row.sku}`" class="border-t border-neutral-200"><td class="px-2 py-1">{{ row.warehouse_code }}</td><td class="px-2 py-1">{{ row.sku }}</td><td class="px-2 py-1">{{ row.name }}</td><td class="px-2 py-1 text-right">{{ qty(row.history_qty) }}</td><td class="px-2 py-1 text-right">{{ qty(row.stock_qty) }}</td><td class="px-2 py-1 text-right font-medium">{{ qty(row.difference) }}</td></tr></tbody></table></div>
        <p v-if="endStateDifferences.length > END_STATE_ROWS" class="text-xs">{{ t('stock.history_import.end_state_more', { count: endStateDifferences.length - END_STATE_ROWS }) }}</p>
      </div>
      <label v-if="job.kind === STAGE_KIND && (closedPeriods > 0 || applyNeedsConfirm) && previewReady" class="flex items-start gap-2 rounded-lg border border-warning-200 bg-warning-50 p-4 text-sm"><input v-model="confirmClosed" type="checkbox" data-test="confirm-closed" class="mt-1" /><span>{{ closedPeriods > 0 ? t('stock.history_import.closed_periods', { count: closedPeriods }) : t('stock.history_import.closed_periods_changed') }}</span></label>

      <div class="flex flex-wrap gap-3 text-sm"><span v-for="state in ['ready','applied','failed','skipped']" :key="state" class="rounded-full bg-neutral-100 px-3 py-1">{{ statusLabel(state) }}: {{ counts[state] || 0 }}</span></div>
      <div v-if="report?.items.length" class="overflow-x-auto rounded-lg border border-neutral-200"><table class="min-w-full whitespace-nowrap text-sm"><thead class="bg-neutral-50"><tr><th class="px-3 py-2 text-left">{{ t('stock.opening_import.row') }}</th><th class="px-3 py-2 text-left">{{ t('stock.history_import.fields.doc_number') }}</th><th class="px-3 py-2 text-left">SKU</th><th class="px-3 py-2 text-left">{{ t('stock.opening_import.status_label') }}</th><th class="px-3 py-2 text-left">{{ t('stock.opening_import.result') }}</th></tr></thead><tbody><tr v-for="item in report.items" :key="item.ordinal" class="border-t border-neutral-200"><td class="px-3 py-2">{{ item.source_row }}</td><td class="max-w-xs truncate px-3 py-2" :title="item.input.values?.doc_number ? undefined : rawRow(item)">{{ item.input.values?.doc_number ?? rawRow(item) }}</td><td class="px-3 py-2">{{ item.input.values?.sku }}</td><td class="px-3 py-2">{{ statusLabel(item.status) }}</td><td class="min-w-64 whitespace-normal px-3 py-2 text-danger-700">{{ item.error_code ? t(`stock.history_import.errors.${item.error_code}`, item.error_code) : '' }}</td></tr></tbody></table></div>
      <div v-if="report && reportPages > 1" class="flex flex-wrap items-center justify-between gap-3 border-t border-neutral-200 pt-3">
        <button type="button" :disabled="reportLoading || reportPage <= 1" :class="btnOutline('neutral')" @click="loadReport(reportPage - 1)"><svg class="h-4 w-4 rotate-90" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.chevron" /></svg>{{ t('common.previous') }}</button>
        <span class="text-sm text-neutral-500">{{ t('eshop.import2.page', { page: reportPage, pages: reportPages }) }}</span>
        <button type="button" :disabled="reportLoading || reportPage >= reportPages" :class="btnOutline('neutral')" @click="loadReport(reportPage + 1)">{{ t('common.next') }}<svg class="h-4 w-4 -rotate-90" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.chevron" /></svg></button>
      </div>
      <p v-if="job.kind !== STAGE_KIND && ['failed', 'cancelled'].includes(job.status)" data-test="retry-unsupported" class="text-sm text-neutral-600">{{ t(periodReviewRequired ? job.kind === ROLLBACK_KIND ? 'stock.history_import.rollback_period_closed' : 'stock.history_import.apply_period_closed' : job.kind === ROLLBACK_KIND ? 'stock.history_import.rollback_stopped' : canRetry ? 'stock.history_import.apply_stopped' : 'stock.history_import.apply_stale') }}</p>
      <div class="flex flex-wrap justify-end gap-2">
        <button type="button" :class="btnOutline('neutral')" @click="reset"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.x" /></svg>{{ t('stock.history_import.close') }}</button>
        <button v-if="canRetry" type="button" data-test="retry" :class="btnOutline('primary')" @click="changeJob('retry')"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.cycle" /></svg>{{ t('common.retry') }}</button>
        <button v-if="previewReady" type="button" data-test="apply-import" :disabled="!canApply || loading" :class="btnFilled('success')" @click="apply"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.check" /></svg>{{ t('stock.history_import.apply') }}</button>
        <RouterLink v-if="job.kind === APPLY_KIND && job.status === 'completed'" to="/stock/documents" :class="btnFilled('primary')"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.doc" /></svg>{{ t('stock.history_import.open_documents') }}</RouterLink>
      </div>
    </section>

    <div v-if="rollbackOpen" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="rollbackOpen = false">
      <div class="w-full max-w-md space-y-3 rounded-lg bg-surface p-5 shadow-xl">
        <h2 class="text-lg font-semibold">{{ t('stock.history_import.rollback_title') }}</h2>
        <p class="text-sm text-neutral-600">{{ t(orphanId !== null ? 'stock.history_import.rollback_text_orphan' : activeInput?.profile?.cutover_date ? 'stock.history_import.rollback_text_cutover' : 'stock.history_import.rollback_text') }}</p>
        <label v-if="rollbackNeedsConfirm" class="flex items-start gap-2 rounded-lg border border-warning-200 bg-warning-50 p-3 text-sm"><input v-model="rollbackConfirmClosed" type="checkbox" data-test="rollback-confirm-closed" class="mt-1" /><span>{{ t('stock.history_import.rollback_closed_periods') }}</span></label>
        <div class="flex justify-end gap-2">
          <button type="button" :class="btnOutline('neutral')" @click="rollbackOpen = false">{{ t('common.cancel') }}</button>
          <button type="button" data-test="rollback-confirm" :disabled="loading || (rollbackNeedsConfirm && !rollbackConfirmClosed)" :class="btnFilled('danger')" @click="rollback">{{ t('stock.history_import.rollback') }}</button>
        </div>
      </div>
    </div>
  </div>
</template>

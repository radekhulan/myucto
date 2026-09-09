<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import {
  catalogImportApi,
  type CatalogImportConfig,
  type CatalogImportItems,
  type CatalogImportProfile,
  type CatalogImportSample,
  type CatalogImportSource,
} from '@/api/catalogImport'
import { catalogJobsApi, type CatalogJob } from '@/api/catalogJobs'
import CatalogJobProgress from '@/components/stock/CatalogJobProgress.vue'
import { btnFilled, btnOutline, ICONS } from '@/components/ui/buttonStyles'
import { useAuthStore } from '@/stores/auth'
import { useSupplierStore } from '@/stores/supplier'

type Stage = 'source' | 'mapping' | 'preview'
type RequestKind = 'upload' | 'sample' | 'profiles' | 'profileSave' | 'preview' | 'apply' | 'resume' | 'job' | 'report' | 'cancel' | 'retry'

interface RequestToken {
  kind: RequestKind
  version: number
  generation: number
  supplierId: number
  controller: AbortController
}

const MAX_FILE_SIZE = 50_000_000
const POLL_INTERVAL = 2000
const ADVANCED_FIELDS = new Set(['categories', 'tag_ids', 'i18n', 'attributes', 'fees', 'prices'])
const NON_CLEARABLE_FIELDS = new Set([
  'id',
  'external_id',
  'sku',
  'name',
  'unit',
  'item_type',
  'pricing_base',
  'is_active',
  'is_stocked',
  'export_eshop',
])
const HIDDEN_DIFF_FIELDS = new Set(['id', 'row_version'])
const ITEM_STATUSES = new Set(['pending', 'ready', 'applied', 'unchanged', 'failed', 'conflict', 'skipped'])
const IMPORT_ERROR_CODES = new Set([
  'catalog_import_failed',
  'import_boolean_invalid',
  'import_cell_invalid',
  'import_csv_options_invalid',
  'import_database_validation_failed',
  'import_decimal_invalid',
  'import_duplicate_header',
  'import_duplicate_identity',
  'import_encoding_invalid',
  'import_enum_invalid',
  'import_file_empty',
  'import_file_invalid',
  'import_file_too_large',
  'import_file_unreadable',
  'import_format_invalid',
  'import_identity_required',
  'import_input_changed',
  'import_integer_invalid',
  'import_currency_invalid',
  'import_json_invalid',
  'import_json_row_invalid',
  'import_mapping_invalid',
  'import_missing_header',
  'import_mode_conflict',
  'import_operations_invalid',
  'import_price_mapping_conflict',
  'import_profile_invalid',
  'import_profile_name_taken',
  'import_profile_unknown_field',
  'import_reader_invalid',
  'import_reference_invalid',
  'import_required_value',
  'import_row_too_large',
  'import_sheet_invalid',
  'import_sheet_too_large',
  'import_source_changed',
  'import_source_key_required',
  'import_storage_failed',
  'import_storage_unavailable',
  'import_text_invalid',
  'import_too_many_columns',
  'import_too_many_rows',
  'import_translation_invalid',
  'import_validation_failed',
  'import_write_failed',
  'import_xlsx_invalid',
  'import_xlsx_too_large',
  'job_state_conflict',
  'version_conflict',
])

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const supplier = useSupplierStore()

const canWrite = computed(() => (
  auth.canWrite('eshop.write') && auth.canWrite('stock.items.write')
))

const source = ref<CatalogImportSource | null>(null)
const sample = ref<CatalogImportSample | null>(null)
const profiles = ref<CatalogImportProfile[]>([])
const selectedProfile = ref<number | ''>('')
const profileName = ref('')
const stage = ref<Stage>('source')
const error = ref('')
const job = ref<CatalogJob | null>(null)
const report = ref<CatalogImportItems | null>(null)
const reportPage = ref(1)

const uploadLoading = ref(false)
const sampleLoading = ref(false)
const profilesLoading = ref(false)
const profileSaving = ref(false)
const actionLoading = ref(false)
const resumeLoading = ref(false)
const reportLoading = ref(false)
const cancelling = ref(false)

const config = ref<CatalogImportConfig>(emptyConfig())

let disposed = false
let generation = 0
let pollTimer: ReturnType<typeof setInterval> | undefined
const requestVersions: Record<RequestKind, number> = {
  upload: 0,
  sample: 0,
  profiles: 0,
  profileSave: 0,
  preview: 0,
  apply: 0,
  resume: 0,
  job: 0,
  report: 0,
  cancel: 0,
  retry: 0,
}
const requestControllers = new Map<RequestKind, AbortController>()

const busy = computed(() => (
  uploadLoading.value
  || sampleLoading.value
  || profileSaving.value
  || actionLoading.value
  || resumeLoading.value
  || cancelling.value
))
const activeJob = computed(() => !!job.value && ['queued', 'running'].includes(job.value.status))
const counts = computed<Record<string, number>>(() => {
  const raw = job.value?.report?.counts
  if (!raw || typeof raw !== 'object' || Array.isArray(raw)) return {}
  return Object.fromEntries(Object.entries(raw).map(([key, value]) => [key, Number(value) || 0]))
})
const readyCount = computed(() => counts.value.ready ?? 0)
const canApply = computed(() => (
  canWrite.value
  && job.value?.kind === 'catalog_import_stage'
  && job.value.status === 'completed'
  && !job.value.apply_job_id
  && readyCount.value > 0
))
const canRetry = computed(() => (
  canWrite.value
  && !!job.value
  && ['failed', 'cancelled'].includes(job.value.status)
))
const availableFields = computed(() => sample.value?.fields ?? [])
const regularFields = computed(() => availableFields.value.filter(field => !ADVANCED_FIELDS.has(field)))
const advancedFields = computed(() => availableFields.value.filter(field => ADVANCED_FIELDS.has(field)))
const priceMappingConflict = computed(() => !!config.value.mapping.price && !!config.value.mapping.prices)
const sourceKeyValid = computed(() => (
  config.value.identity !== 'external_id'
  || /^[a-z0-9][a-z0-9_.-]{0,99}$/.test(config.value.source_key ?? '')
))
const mappingValid = computed(() => (
  !!config.value.mapping[config.value.identity]
  && sourceKeyValid.value
  && !priceMappingConflict.value
))
const reportPages = computed(() => Math.max(1, report.value?.pagination.pages ?? 1))

function emptyConfig(): CatalogImportConfig {
  return {
    identity: 'sku',
    source_key: null,
    mode: 'upsert',
    mapping: {},
    blank: 'preserve',
    operations: {},
    reader: { encoding: 'UTF-8', delimiter: ';', sheet: 0 },
  }
}

function cloneConfig(value: CatalogImportConfig): CatalogImportConfig {
  return JSON.parse(JSON.stringify(value)) as CatalogImportConfig
}

function beginRequest(kind: RequestKind): RequestToken {
  requestControllers.get(kind)?.abort()
  const controller = new AbortController()
  requestControllers.set(kind, controller)
  return {
    kind,
    version: ++requestVersions[kind],
    generation,
    supplierId: supplier.currentSupplierId,
    controller,
  }
}

function isCurrent(token: RequestToken): boolean {
  return !disposed
    && token.generation === generation
    && token.supplierId === supplier.currentSupplierId
    && token.version === requestVersions[token.kind]
    && requestControllers.get(token.kind) === token.controller
}

function finishRequest(token: RequestToken) {
  if (requestControllers.get(token.kind) === token.controller) {
    requestControllers.delete(token.kind)
  }
}

function stopPolling() {
  if (pollTimer) clearInterval(pollTimer)
  pollTimer = undefined
}

function invalidateRequests() {
  generation++
  stopPolling()
  for (const controller of requestControllers.values()) controller.abort()
  requestControllers.clear()
}

function resetClientState() {
  source.value = null
  sample.value = null
  profiles.value = []
  selectedProfile.value = ''
  profileName.value = ''
  config.value = emptyConfig()
  job.value = null
  report.value = null
  reportPage.value = 1
  error.value = ''
  stage.value = 'source'
  uploadLoading.value = false
  sampleLoading.value = false
  profilesLoading.value = false
  profileSaving.value = false
  actionLoading.value = false
  resumeLoading.value = false
  reportLoading.value = false
  cancelling.value = false
}

function startNewImport() {
  if (activeJob.value || busy.value) return
  invalidateRequests()
  resetClientState()
  const query = { ...route.query }
  delete query.import_job
  void router.replace({ query })
}

function isCancelled(requestError: any): boolean {
  return requestError?.code === 'ERR_CANCELED'
    || requestError?.name === 'CanceledError'
    || requestError?.name === 'AbortError'
}

function apiError(requestError: any): string {
  const code = requestError?.response?.data?.error?.code
  if (typeof code === 'string' && IMPORT_ERROR_CODES.has(code)) {
    return t(`eshop.import2.errors.${code}`)
  }
  const message = requestError?.response?.data?.error?.message
  return typeof message === 'string' && message.trim() ? message : t('eshop.import2.error')
}

function fieldLabel(field: string): string {
  return t(`eshop.import2.fields.${field === 'sale_price_without_vat' ? 'price' : field}`)
}

function itemStatusLabel(status: string): string {
  return ITEM_STATUSES.has(status)
    ? t(`eshop.import2.status.${status}`)
    : t('eshop.import2.status.unknown')
}

function itemStatusClass(status: string): string {
  if (status === 'ready' || status === 'applied') return 'border-success-500/30 bg-success-50 text-success-700'
  if (status === 'failed' || status === 'conflict') return 'border-danger-500/30 bg-danger-50 text-danger-700'
  if (status === 'pending') return 'border-primary-500/30 bg-primary-50 text-primary-700'
  return 'border-neutral-300 bg-neutral-50 text-neutral-600'
}

function itemErrorLabel(errorCode: string): string {
  return IMPORT_ERROR_CODES.has(errorCode)
    ? t(`eshop.import2.errors.${errorCode}`)
    : t('eshop.import2.errors.unknown')
}

function normalizeHeader(value: string): string {
  return value
    .trim()
    .toLocaleLowerCase()
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .replace(/[\s-]+/g, '_')
}

function applyAutomaticMapping(headers: string[]) {
  const aliases: Record<string, string[]> = {
    sku: ['sku', 'kod', 'code'],
    name: ['nazev', 'name'],
    ean: ['ean'],
    price: ['cena', 'price', 'sale_price', 'prodejni_cena'],
  }
  const normalized = headers.map(header => ({ header, key: normalizeHeader(header) }))
  for (const [field, names] of Object.entries(aliases)) {
    if (config.value.mapping[field]) continue
    const match = normalized.find(candidate => names.includes(candidate.key))
    if (match) config.value.mapping[field] = match.header
  }
}

function filePick(event: Event) {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]
  input.value = ''
  if (file) void upload(file)
}

function fileDrop(event: DragEvent) {
  if (!canWrite.value || busy.value) return
  const file = event.dataTransfer?.files?.[0]
  if (file) void upload(file)
}

function validFile(file: File): boolean {
  return file.size <= MAX_FILE_SIZE && /\.(csv|xlsx)$/i.test(file.name)
}

async function upload(file: File) {
  if (!canWrite.value || uploadLoading.value) return
  if (!validFile(file)) {
    error.value = t('eshop.import2.invalid_file')
    return
  }

  invalidateRequests()
  resetClientState()
  uploadLoading.value = true
  error.value = ''
  const token = beginRequest('upload')
  try {
    const uploaded = await catalogImportApi.upload(file, token.controller.signal)
    if (!isCurrent(token)) return
    source.value = uploaded
    stage.value = 'mapping'
    await Promise.all([loadSample(), loadProfiles()])
  } catch (requestError: any) {
    if (isCurrent(token) && !isCancelled(requestError)) error.value = apiError(requestError)
  } finally {
    if (isCurrent(token)) uploadLoading.value = false
    finishRequest(token)
  }
}

async function loadSample() {
  const expectedSourceId = source.value?.id
  if (!expectedSourceId || !canWrite.value) return
  const token = beginRequest('sample')
  sampleLoading.value = true
  error.value = ''
  try {
    const nextSample = await catalogImportApi.sample(
      expectedSourceId,
      { ...config.value.reader },
      token.controller.signal,
    )
    if (!isCurrent(token) || source.value?.id !== expectedSourceId) return
    sample.value = nextSample
    const headers = nextSample.header
    config.value.mapping = Object.fromEntries(
      Object.entries(config.value.mapping).filter(([, header]) => headers.includes(header)),
    )
    applyAutomaticMapping(headers)
  } catch (requestError: any) {
    if (isCurrent(token) && !isCancelled(requestError)) error.value = apiError(requestError)
  } finally {
    if (isCurrent(token)) sampleLoading.value = false
    finishRequest(token)
  }
}

async function loadProfiles() {
  if (!canWrite.value) return
  const token = beginRequest('profiles')
  profilesLoading.value = true
  try {
    const nextProfiles = await catalogImportApi.profiles(token.controller.signal)
    if (isCurrent(token)) profiles.value = nextProfiles
  } catch (requestError: any) {
    if (isCurrent(token) && !isCancelled(requestError)) error.value = apiError(requestError)
  } finally {
    if (isCurrent(token)) profilesLoading.value = false
    finishRequest(token)
  }
}

function selectProfile() {
  const profile = profiles.value.find(item => item.id === selectedProfile.value)
  if (!profile) return
  config.value = cloneConfig(profile.config)
  profileName.value = profile.name
  void loadSample()
}

function fieldMapping(field: string): string {
  return config.value.mapping[field] ?? ''
}

function setFieldMapping(field: string, event: Event) {
  const value = (event.target as HTMLSelectElement).value
  if (!value) {
    delete config.value.mapping[field]
    if (config.value.operations[field] === 'set') delete config.value.operations[field]
    return
  }
  config.value.mapping[field] = value
  if (!config.value.operations[field]) config.value.operations[field] = 'set'
}

function fieldOperation(field: string): CatalogImportConfig['operations'][string] {
  return config.value.operations[field] ?? 'set'
}

function setFieldOperation(field: string, event: Event) {
  const operation = (event.target as HTMLSelectElement).value as CatalogImportConfig['operations'][string]
  if (operation === 'clear' && (field === config.value.identity || NON_CLEARABLE_FIELDS.has(field))) return
  if (operation === 'set' && !config.value.mapping[field]) delete config.value.operations[field]
  else config.value.operations[field] = operation
}

function canClear(field: string): boolean {
  return field !== config.value.identity && !NON_CLEARABLE_FIELDS.has(field)
}

function mappingDisabled(field: string): boolean {
  return (field === 'price' && !!config.value.mapping.prices)
    || (field === 'prices' && !!config.value.mapping.price)
}

function submitConfig(): CatalogImportConfig {
  const result = cloneConfig(config.value)
  if (result.identity !== 'external_id') result.source_key = null
  for (const field of Object.keys(result.operations)) {
    if (result.operations[field] === 'set' && !result.mapping[field]) delete result.operations[field]
  }
  result.operations[result.identity] = 'set'
  return result
}

async function saveProfile() {
  if (!canWrite.value || profileSaving.value || !mappingValid.value || !profileName.value.trim()) return
  const current = profiles.value.find(item => item.id === selectedProfile.value)
  const token = beginRequest('profileSave')
  profileSaving.value = true
  error.value = ''
  try {
    const saved = current
      ? await catalogImportApi.updateProfile(
          current.id,
          profileName.value.trim(),
          submitConfig(),
          current.version,
          token.controller.signal,
        )
      : await catalogImportApi.createProfile(
          profileName.value.trim(),
          submitConfig(),
          token.controller.signal,
        )
    if (!isCurrent(token)) return
    const existingIndex = profiles.value.findIndex(item => item.id === saved.id)
    if (existingIndex === -1) profiles.value = [...profiles.value, saved]
    else profiles.value.splice(existingIndex, 1, saved)
    selectedProfile.value = saved.id
    profileName.value = saved.name
  } catch (requestError: any) {
    if (isCurrent(token) && !isCancelled(requestError)) error.value = apiError(requestError)
  } finally {
    if (isCurrent(token)) profileSaving.value = false
    finishRequest(token)
  }
}

function invalidateJobRequests() {
  requestControllers.get('job')?.abort()
  requestControllers.get('report')?.abort()
  requestControllers.delete('job')
  requestControllers.delete('report')
  requestVersions.job++
  requestVersions.report++
  stopPolling()
  reportLoading.value = false
}

function clearJobState() {
  invalidateJobRequests()
  job.value = null
  report.value = null
  reportPage.value = 1
}

async function preview() {
  const expectedSourceId = source.value?.id
  if (!canWrite.value || actionLoading.value || !expectedSourceId) return
  if (!mappingValid.value) {
    error.value = priceMappingConflict.value
      ? t('eshop.import2.price_mapping_conflict')
      : t('eshop.import2.mapping_required')
    return
  }
  clearJobState()
  const token = beginRequest('preview')
  actionLoading.value = true
  error.value = ''
  try {
    const nextJob = await catalogImportApi.preview(expectedSourceId, submitConfig(), token.controller.signal)
    if (!isCurrent(token) || source.value?.id !== expectedSourceId) return
    job.value = nextJob
    stage.value = 'preview'
    await acceptJob(nextJob)
  } catch (requestError: any) {
    if (isCurrent(token) && !isCancelled(requestError)) error.value = apiError(requestError)
  } finally {
    if (isCurrent(token)) actionLoading.value = false
    finishRequest(token)
  }
}

async function apply() {
  const previewJob = job.value
  if (!canApply.value || !previewJob || actionLoading.value) return
  invalidateJobRequests()
  const token = beginRequest('apply')
  actionLoading.value = true
  error.value = ''
  try {
    const nextJob = await catalogImportApi.apply(previewJob.id, token.controller.signal)
    if (!isCurrent(token) || job.value?.id !== previewJob.id) return
    report.value = null
    reportPage.value = 1
    job.value = nextJob
    await acceptJob(nextJob)
  } catch (requestError: any) {
    if (isCurrent(token) && !isCancelled(requestError)) error.value = apiError(requestError)
  } finally {
    if (isCurrent(token)) actionLoading.value = false
    finishRequest(token)
  }
}

async function acceptJob(nextJob: CatalogJob) {
  job.value = nextJob
  if (['queued', 'running'].includes(nextJob.status)) {
    startPolling()
    return
  }
  stopPolling()
  await loadReport(1, nextJob.id)
}

function startPolling() {
  stopPolling()
  if (!activeJob.value) return
  void refreshJob()
  pollTimer = setInterval(() => void refreshJob(), POLL_INTERVAL)
}

async function refreshJob() {
  const expectedJobId = job.value?.id
  if (!expectedJobId || !activeJob.value) return
  const token = beginRequest('job')
  try {
    const nextJob = await catalogJobsApi.get(expectedJobId, token.controller.signal)
    if (!isCurrent(token) || job.value?.id !== expectedJobId) return
    job.value = nextJob
    if (['queued', 'running'].includes(nextJob.status)) return
    stopPolling()
    await loadReport(1, expectedJobId)
  } catch (requestError: any) {
    if (isCurrent(token) && !isCancelled(requestError)) error.value = apiError(requestError)
  } finally {
    finishRequest(token)
  }
}

async function loadReport(page = reportPage.value, expectedJobId = job.value?.id) {
  if (!expectedJobId) return
  const token = beginRequest('report')
  reportLoading.value = true
  try {
    const nextReport = await catalogImportApi.items(expectedJobId, page, token.controller.signal)
    if (!isCurrent(token) || job.value?.id !== expectedJobId) return
    report.value = nextReport
    reportPage.value = page
  } catch (requestError: any) {
    if (isCurrent(token) && !isCancelled(requestError)) error.value = apiError(requestError)
  } finally {
    if (isCurrent(token)) reportLoading.value = false
    finishRequest(token)
  }
}

async function cancelJob() {
  const expectedJobId = job.value?.id
  if (!canWrite.value || !expectedJobId || !activeJob.value || cancelling.value) return
  invalidateJobRequests()
  const token = beginRequest('cancel')
  cancelling.value = true
  error.value = ''
  try {
    const nextJob = await catalogJobsApi.cancel(expectedJobId, token.controller.signal)
    if (!isCurrent(token) || job.value?.id !== expectedJobId) return
    await acceptJob(nextJob)
  } catch (requestError: any) {
    if (isCurrent(token) && !isCancelled(requestError)) {
      error.value = apiError(requestError)
      startPolling()
    }
  } finally {
    if (isCurrent(token)) cancelling.value = false
    finishRequest(token)
  }
}

async function retryJob() {
  const expectedJobId = job.value?.id
  if (!canRetry.value || !expectedJobId || actionLoading.value) return
  invalidateJobRequests()
  const token = beginRequest('retry')
  actionLoading.value = true
  error.value = ''
  try {
    const nextJob = await catalogJobsApi.retry(expectedJobId, token.controller.signal)
    if (!isCurrent(token) || job.value?.id !== expectedJobId) return
    report.value = null
    reportPage.value = 1
    await acceptJob(nextJob)
  } catch (requestError: any) {
    if (isCurrent(token) && !isCancelled(requestError)) error.value = apiError(requestError)
  } finally {
    if (isCurrent(token)) actionLoading.value = false
    finishRequest(token)
  }
}

function queryJobId(value: unknown): number | null {
  const candidate = Array.isArray(value) ? value[0] : value
  if (typeof candidate !== 'string' || !/^[1-9]\d{0,9}$/.test(candidate)) return null
  const id = Number(candidate)
  return Number.isSafeInteger(id) ? id : null
}

async function resumeFromQuery() {
  const expectedJobId = queryJobId(route.query.import_job)
  if (!canWrite.value || !expectedJobId) return
  const token = beginRequest('resume')
  resumeLoading.value = true
  error.value = ''
  try {
    const nextJob = await catalogJobsApi.get(expectedJobId, token.controller.signal)
    if (!isCurrent(token)) return
    if (nextJob.supplier_id !== token.supplierId
      || !['catalog_import_stage', 'catalog_import_apply'].includes(nextJob.kind)) {
      error.value = t('eshop.import2.resume_error')
      return
    }
    job.value = nextJob
    stage.value = 'preview'
    await acceptJob(nextJob)
  } catch (requestError: any) {
    if (isCurrent(token) && !isCancelled(requestError)) error.value = t('eshop.import2.resume_error')
  } finally {
    if (isCurrent(token)) resumeLoading.value = false
    finishRequest(token)
  }
}

function backToSource() {
  invalidateRequests()
  resetClientState()
}

function backToMapping() {
  if (activeJob.value) return
  clearJobState()
  error.value = ''
  stage.value = 'mapping'
}

function displayValue(value: unknown): string {
  if (value == null || value === '') return t('eshop.import2.empty_value')
  if (typeof value === 'boolean') return value ? t('common.yes') : t('common.no')
  return typeof value === 'object' ? JSON.stringify(value) : String(value)
}

function displayFieldValue(field: string, value: unknown): string {
  if (field === 'item_type' && ['goods', 'material', 'product'].includes(String(value))) return t(`stock.item_type.${value}`)
  if (field === 'pricing_base' && ['weighted_avg', 'last_purchase', 'manual'].includes(String(value))) return t(`eshop.item.pricing_${value}`)
  if (field !== 'prices' || !Array.isArray(value)) return displayValue(value)
  if (value.length === 0) return t('eshop.import2.empty_value')
  return value.map((entry) => {
    if (!entry || typeof entry !== 'object' || Array.isArray(entry)) return displayValue(entry)
    const price = entry as Record<string, unknown>
    const currency = typeof price.currency_code === 'string' ? price.currency_code : ''
    const amount = price.computed_price ?? price.fixed_price
    return amount == null ? currency : `${currency}: ${String(amount)}`
  }).filter(Boolean).join(', ')
}

function changedFields(item: CatalogImportItems['items'][number]): string[] {
  const before = item.before ?? {}
  const after = item.after ?? {}
  return Object.keys({ ...before, ...after })
    .filter(field => !HIDDEN_DIFF_FIELDS.has(field))
    .filter(field => {
      const normalize = (value: unknown) => value == null || value === '' || (Array.isArray(value) && value.length === 0) ? null : value
      return JSON.stringify(normalize(before[field])) !== JSON.stringify(normalize(after[field]))
    })
}

watch(() => supplier.currentSupplierId, () => {
  invalidateRequests()
  resetClientState()
  void resumeFromQuery()
})

watch(() => route.query.import_job, () => {
  invalidateRequests()
  resetClientState()
  void resumeFromQuery()
})

watch(canWrite, (allowed) => {
  invalidateRequests()
  resetClientState()
  if (allowed) void resumeFromQuery()
})

onMounted(() => {
  void resumeFromQuery()
})

onBeforeUnmount(() => {
  disposed = true
  invalidateRequests()
})
</script>

<template>
  <div>
    <header class="mb-5 flex flex-wrap items-center justify-between gap-3">
      <div>
        <h1 class="text-2xl font-semibold text-neutral-900">{{ t('eshop.import2.title') }}</h1>
        <p class="mt-0.5 text-sm text-neutral-500">{{ t('eshop.import2.subtitle') }}</p>
      </div>
      <button
        v-if="stage !== 'source'"
        type="button"
        :class="btnOutline('neutral')"
        :disabled="activeJob || busy"
        :title="activeJob ? t('eshop.import2.finish_or_cancel_first') : undefined"
        data-test="new-import"
        @click="startNewImport"
      >
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
          <path :d="ICONS.plus" />
        </svg>
        {{ t('eshop.import2.new_import') }}
      </button>
    </header>

    <div
      v-if="!canWrite"
      class="mb-4 rounded-lg border border-warning-500/30 bg-warning-50 px-4 py-3 text-sm text-warning-700"
      role="status"
      data-test="readonly-notice"
    >
      {{ t('eshop.import2.readonly') }}
    </div>
    <div
      v-if="error"
      class="mb-4 rounded-lg border border-danger-500/30 bg-danger-50 px-4 py-3 text-sm text-danger-700"
      role="alert"
      data-test="import-error"
    >
      {{ error }}
    </div>

    <ol class="mb-5 grid grid-cols-3 gap-2 text-sm" :aria-label="t('eshop.import2.progress')">
      <li
        v-for="(step, index) in (['source', 'mapping', 'preview'] as Stage[])"
        :key="step"
        class="flex min-w-0 items-center gap-2 rounded-lg border px-3 py-2"
        :class="stage === step ? 'border-primary-500/40 bg-primary-50 text-primary-700' : 'border-neutral-200 bg-surface text-neutral-500'"
      >
        <span
          class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full text-xs font-semibold"
          :class="stage === step ? 'bg-primary-600 text-white' : 'bg-neutral-100 text-neutral-600'"
        >
          {{ index + 1 }}
        </span>
        <span class="truncate font-medium">{{ t(`eshop.import2.step.${step}`) }}</span>
      </li>
    </ol>

    <section
      v-if="stage === 'source'"
      class="rounded-xl border border-neutral-200 bg-surface p-5 shadow-sm"
      data-test="source-step"
    >
      <label
        class="block rounded-lg border-2 border-dashed border-neutral-300 p-10 text-center transition"
        :class="canWrite && !busy ? 'cursor-pointer hover:border-primary-400 hover:bg-primary-50/30' : 'cursor-not-allowed opacity-60'"
        @dragover.prevent
        @drop.prevent="fileDrop"
      >
        <input
          class="hidden"
          type="file"
          accept=".csv,.xlsx"
          :disabled="!canWrite || busy"
          data-test="file-input"
          @change="filePick"
        >
        <svg class="mx-auto mb-3 h-9 w-9 text-neutral-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
          <path :d="ICONS.upload" />
        </svg>
        <span class="block font-medium text-neutral-800">
          {{ uploadLoading ? t('eshop.import2.uploading') : t('eshop.import2.choose_file') }}
        </span>
        <span class="mt-1 block text-xs text-neutral-500">{{ t('eshop.import2.file_hint') }}</span>
      </label>
    </section>

    <section v-else-if="stage === 'mapping'" class="space-y-4" data-test="mapping-step">
      <div class="rounded-xl border border-neutral-200 bg-surface p-4 shadow-sm">
        <div class="flex flex-wrap items-center gap-3">
          <svg class="h-5 w-5 shrink-0 text-primary-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path :d="ICONS.doc" />
          </svg>
          <strong class="min-w-0 truncate text-neutral-900">{{ source?.original_name }}</strong>
          <span class="text-xs text-neutral-500">
            {{ source?.format?.toUpperCase() }} · {{ t('eshop.import2.file_size_kb', { size: Math.round((source?.size_bytes ?? 0) / 1024) }) }}
          </span>
          <button
            type="button"
            :disabled="sampleLoading || busy"
            :class="btnOutline('neutral')"
            class="ml-auto"
            data-test="refresh-sample"
            @click="loadSample"
          >
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
              <path :d="ICONS.cycle" />
            </svg>
            {{ sampleLoading ? t('common.loading') : t('common.refresh') }}
          </button>
        </div>

        <div class="mt-4 grid gap-4 text-sm sm:grid-cols-3">
          <label>
            {{ t('eshop.import2.encoding') }}
            <select v-model="config.reader.encoding" class="mt-1 h-9 w-full rounded-md border border-neutral-300 bg-surface px-2">
              <option value="UTF-8">UTF-8</option>
              <option value="Windows-1250">Windows-1250</option>
              <option value="ISO-8859-2">ISO-8859-2</option>
            </select>
          </label>
          <label>
            {{ t('eshop.import2.delimiter') }}
            <select v-model="config.reader.delimiter" class="mt-1 h-9 w-full rounded-md border border-neutral-300 bg-surface px-2">
              <option value=";">;</option>
              <option value=",">,</option>
              <option :value="'\t'">{{ t('eshop.import2.tab') }}</option>
              <option value="|">|</option>
            </select>
          </label>
          <label>
            {{ t('eshop.import2.sheet') }}
            <input v-model.number="config.reader.sheet" min="0" type="number" class="mt-1 h-9 w-full rounded-md border border-neutral-300 bg-surface px-3">
          </label>
        </div>
        <p class="mt-2 text-xs text-neutral-500">{{ t('eshop.import2.reader_refresh_hint') }}</p>
      </div>

      <div class="rounded-xl border border-neutral-200 bg-surface p-4 shadow-sm">
        <h2 class="font-semibold text-neutral-900">{{ t('eshop.import2.import_rules') }}</h2>
        <div class="mt-3 grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-4">
          <label>
            {{ t('eshop.import2.identity') }}
            <select v-model="config.identity" class="mt-1 h-9 w-full rounded-md border border-neutral-300 bg-surface px-2" data-test="identity-select">
              <option value="sku">{{ fieldLabel('sku') }}</option>
              <option value="id">{{ fieldLabel('id') }}</option>
              <option value="external_id">{{ fieldLabel('external_id') }}</option>
            </select>
          </label>
          <label v-if="config.identity === 'external_id'">
            {{ t('eshop.import2.source_key') }}
            <input v-model.trim="config.source_key" class="mt-1 h-9 w-full rounded-md border border-neutral-300 bg-surface px-3" data-test="source-key">
            <span v-if="!sourceKeyValid" class="mt-1 block text-xs text-danger-600">{{ t('eshop.import2.source_key_invalid') }}</span>
          </label>
          <label>
            {{ t('eshop.import2.mode') }}
            <select v-model="config.mode" class="mt-1 h-9 w-full rounded-md border border-neutral-300 bg-surface px-2">
              <option value="upsert">{{ t('eshop.import2.mode_upsert') }}</option>
              <option value="create">{{ t('eshop.import2.mode_create') }}</option>
              <option value="update">{{ t('eshop.import2.mode_update') }}</option>
            </select>
          </label>
          <label>
            {{ t('eshop.import2.blank') }}
            <select v-model="config.blank" class="mt-1 h-9 w-full rounded-md border border-neutral-300 bg-surface px-2">
              <option value="preserve">{{ t('eshop.import2.blank_preserve') }}</option>
              <option value="clear">{{ t('eshop.import2.blank_clear') }}</option>
            </select>
          </label>
        </div>
      </div>

      <div class="rounded-xl border border-neutral-200 bg-surface p-4 shadow-sm">
        <h2 class="font-semibold text-neutral-900">{{ t('eshop.import2.mapping_title') }}</h2>
        <p class="mt-0.5 text-xs text-neutral-500">{{ t('eshop.import2.mapping_hint') }}</p>

        <div v-if="sampleLoading && !sample" class="py-8 text-center text-sm text-neutral-500">
          {{ t('common.loading') }}
        </div>
        <div v-else class="mt-4 grid gap-4 sm:grid-cols-2">
          <label v-for="field in regularFields" :key="field" class="text-sm" :data-test="`mapping-${field}`">
            <span class="font-medium text-neutral-800">{{ fieldLabel(field) }}</span>
            <select
              :value="fieldMapping(field)"
              class="mt-1 h-9 w-full rounded-md border border-neutral-300 bg-surface px-2"
              :disabled="mappingDisabled(field)"
              :data-test="`mapping-select-${field}`"
              @change="setFieldMapping(field, $event)"
            >
              <option value="">{{ t('eshop.import2.unmapped') }}</option>
              <option v-for="header in sample?.header" :key="header" :value="header">{{ header }}</option>
            </select>
            <select
              :value="fieldOperation(field)"
              class="mt-2 h-8 w-full rounded-md border border-neutral-300 bg-surface px-2 text-xs"
              :data-test="`operation-${field}`"
              @change="setFieldOperation(field, $event)"
            >
              <option value="set">{{ t('eshop.import2.operation_set') }}</option>
              <option value="preserve">{{ t('eshop.import2.operation_preserve') }}</option>
              <option value="clear" :disabled="!canClear(field)">
                {{ t('eshop.import2.operation_clear') }}
              </option>
            </select>
            <span v-if="mappingDisabled(field)" class="mt-1 block text-xs text-warning-700">
              {{ t('eshop.import2.price_mapping_conflict') }}
            </span>
          </label>
        </div>

        <details
          v-if="advancedFields.length"
          class="mt-5 rounded-lg border border-neutral-200 bg-neutral-50/50 p-3"
          data-test="advanced-fields"
        >
          <summary class="cursor-pointer font-medium text-neutral-800">{{ t('eshop.import2.advanced_title') }}</summary>
          <p class="mt-2 text-xs text-neutral-500">{{ t('eshop.import2.json_hint') }}</p>
          <div class="mt-4 grid gap-4 sm:grid-cols-2">
            <label v-for="field in advancedFields" :key="field" class="text-sm" :data-test="`mapping-${field}`">
              <span class="font-medium text-neutral-800">{{ fieldLabel(field) }}</span>
              <select
                :value="fieldMapping(field)"
                class="mt-1 h-9 w-full rounded-md border border-neutral-300 bg-surface px-2"
                :disabled="mappingDisabled(field)"
                :data-test="`mapping-select-${field}`"
                @change="setFieldMapping(field, $event)"
              >
                <option value="">{{ t('eshop.import2.unmapped') }}</option>
                <option v-for="header in sample?.header" :key="header" :value="header">{{ header }}</option>
              </select>
              <select
                :value="fieldOperation(field)"
                class="mt-2 h-8 w-full rounded-md border border-neutral-300 bg-surface px-2 text-xs"
                :data-test="`operation-${field}`"
                @change="setFieldOperation(field, $event)"
              >
                <option value="set">{{ t('eshop.import2.operation_set') }}</option>
                <option value="preserve">{{ t('eshop.import2.operation_preserve') }}</option>
                <option value="clear" :disabled="!canClear(field)">
                  {{ t('eshop.import2.operation_clear') }}
                </option>
              </select>
              <span v-if="mappingDisabled(field)" class="mt-1 block text-xs text-warning-700">
                {{ t('eshop.import2.price_mapping_conflict') }}
              </span>
            </label>
          </div>
        </details>

        <div class="mt-5 border-t border-neutral-200 pt-4">
          <h3 class="text-sm font-semibold text-neutral-900">{{ t('eshop.import2.profiles_title') }}</h3>
          <p class="mt-0.5 text-xs text-neutral-500">{{ t('eshop.import2.profiles_hint') }}</p>
          <div class="mt-3 flex flex-wrap items-end gap-3">
            <label class="min-w-52 flex-1 text-sm">
              {{ t('eshop.import2.profile_name') }}
              <input
                v-model.trim="profileName"
                class="mt-1 h-9 w-full rounded-md border border-neutral-300 bg-surface px-3"
                data-test="profile-name"
              >
            </label>
            <button
              type="button"
              :disabled="profileSaving || !canWrite || !profileName.trim() || !mappingValid"
              :class="btnOutline('primary')"
              data-test="save-profile"
              @click="saveProfile"
            >
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path :d="ICONS.check" />
              </svg>
              {{ profileSaving ? t('common.saving') : t('eshop.import2.save_profile') }}
            </button>
            <label class="min-w-52 flex-1 text-sm">
              {{ t('eshop.import2.load_profile') }}
              <select
                v-model="selectedProfile"
                class="mt-1 h-9 w-full rounded-md border border-neutral-300 bg-surface px-2"
                :disabled="profilesLoading"
                data-test="profile-select"
                @change="selectProfile"
              >
                <option value="">{{ profilesLoading ? t('common.loading') : t('eshop.import2.profile_none') }}</option>
                <option v-for="profile in profiles" :key="profile.id" :value="profile.id">
                  {{ profile.name }}
                </option>
              </select>
            </label>
          </div>
        </div>

        <div class="mt-5 flex flex-wrap items-center justify-between gap-3 border-t border-neutral-200 pt-4">
          <button
            type="button"
            :class="btnOutline('neutral')"
            :disabled="busy"
            data-test="back-source"
            @click="backToSource"
          >
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
              <path :d="ICONS.uturn" />
            </svg>
            {{ t('eshop.import2.back_to_source') }}
          </button>
          <div class="text-right">
            <button
              type="button"
              :disabled="busy || !canWrite || !mappingValid"
              :title="!mappingValid ? t('eshop.import2.mapping_required') : undefined"
              :class="btnFilled('primary')"
              data-test="preview-import"
              @click="preview"
            >
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path :d="ICONS.eye" />
              </svg>
              {{ actionLoading ? t('common.loading') : t('eshop.import2.preview') }}
            </button>
            <p v-if="!mappingValid" class="mt-1 text-xs text-warning-700">
              {{ priceMappingConflict ? t('eshop.import2.price_mapping_conflict') : t('eshop.import2.mapping_required') }}
            </p>
          </div>
        </div>
      </div>
    </section>

    <section v-else class="space-y-4" data-test="preview-step">
      <CatalogJobProgress
        v-if="job"
        :job="job"
        :cancelling="cancelling"
        :can-cancel="canWrite && activeJob"
        @cancel="cancelJob"
      />
      <div
        v-if="job?.error_code"
        class="rounded-lg border border-danger-500/30 bg-danger-50 px-4 py-3 text-sm text-danger-700"
      >
        {{ itemErrorLabel(job.error_code) }}
      </div>

      <div class="overflow-hidden rounded-xl border border-neutral-200 bg-surface shadow-sm">
        <header class="flex flex-wrap items-center justify-between gap-3 border-b border-neutral-200 px-4 py-3">
          <div>
            <h2 class="font-semibold text-neutral-900">{{ t(job?.kind === 'catalog_import_apply' ? 'eshop.import2.apply_report_title' : 'eshop.import2.report_title') }}</h2>
            <p class="mt-0.5 text-xs text-neutral-500">
              {{ report ? t('eshop.import2.report_summary', { total: report.pagination.total }) : t('eshop.import2.report_waiting') }}
            </p>
          </div>
          <button
            v-if="canRetry"
            type="button"
            :disabled="actionLoading"
            :class="btnOutline('warning')"
            data-test="retry-job"
            @click="retryJob"
          >
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
              <path :d="ICONS.cycle" />
            </svg>
            {{ t('common.retry') }}
          </button>
        </header>

        <div v-if="reportLoading && !report" class="py-10 text-center text-sm text-neutral-500">
          {{ t('common.loading') }}
        </div>
        <div v-else-if="report && report.items.length" class="divide-y divide-neutral-200">
          <article v-for="item in report.items" :key="item.ordinal" class="p-4 text-sm">
            <div class="flex flex-wrap items-start justify-between gap-2">
              <div class="min-w-0">
                <strong class="text-neutral-900">
                  {{ t('eshop.import2.source_row', { row: item.source_row ?? item.ordinal }) }}
                </strong>
                <p v-if="item.input?.raw?.length" class="mt-1 truncate font-mono text-xs text-neutral-500">
                  {{ item.input.raw.join(' | ') }}
                </p>
              </div>
              <span
                class="rounded-md border px-2 py-0.5 text-xs font-medium"
                :class="itemStatusClass(item.status)"
              >
                {{ itemStatusLabel(item.status) }}
              </span>
            </div>
            <div v-if="changedFields(item).length" class="mt-3 space-y-1.5">
              <div
                v-for="field in changedFields(item)"
                :key="field"
                class="grid gap-1 rounded-md bg-neutral-50 px-3 py-2 text-xs sm:grid-cols-[minmax(8rem,0.6fr)_minmax(0,1fr)_auto_minmax(0,1fr)] sm:items-center"
              >
                <span class="font-medium text-neutral-700">{{ fieldLabel(field) }}</span>
                <span class="break-all text-neutral-500">{{ displayFieldValue(field, item.before?.[field]) }}</span>
                <span class="hidden text-neutral-400 sm:inline" aria-hidden="true">→</span>
                <strong class="break-all text-neutral-900">{{ displayFieldValue(field, item.after?.[field]) }}</strong>
              </div>
            </div>
            <p v-if="item.error_code" class="mt-3 text-sm text-danger-700">
              {{ itemErrorLabel(item.error_code) }}
            </p>
          </article>
        </div>
        <p v-else-if="report" class="px-4 py-10 text-center text-sm text-neutral-500">
          {{ t('eshop.import2.report_empty') }}
        </p>

        <footer
          v-if="report && reportPages > 1"
          class="flex flex-wrap items-center justify-between gap-3 border-t border-neutral-200 px-4 py-3"
        >
          <button
            type="button"
            :disabled="reportLoading || reportPage <= 1"
            :class="btnOutline('neutral')"
            data-test="previous-page"
            @click="loadReport(reportPage - 1)"
          >
            <svg class="h-4 w-4 rotate-90" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
              <path :d="ICONS.chevron" />
            </svg>
            {{ t('common.previous') }}
          </button>
          <span class="text-sm text-neutral-500">{{ t('eshop.import2.page', { page: reportPage, pages: reportPages }) }}</span>
          <button
            type="button"
            :disabled="reportLoading || reportPage >= reportPages"
            :class="btnOutline('neutral')"
            data-test="next-page"
            @click="loadReport(reportPage + 1)"
          >
            {{ t('common.next') }}
            <svg class="h-4 w-4 -rotate-90" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
              <path :d="ICONS.chevron" />
            </svg>
          </button>
        </footer>
      </div>

      <div class="flex flex-wrap items-start justify-between gap-3">
        <button
          v-if="source"
          type="button"
          :disabled="activeJob || actionLoading"
          :title="activeJob ? t('eshop.import2.finish_or_cancel_first') : undefined"
          :class="btnOutline('neutral')"
          data-test="back-mapping"
          @click="backToMapping"
        >
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path :d="ICONS.uturn" />
          </svg>
          {{ t('eshop.import2.back_to_mapping') }}
        </button>
        <div v-if="job?.kind === 'catalog_import_stage' && job.status === 'completed'" class="text-right">
          <RouterLink v-if="job.apply_job_id" :to="{ path: '/eshop', query: { tab: 'import', import_job: String(job.apply_job_id) } }" :class="btnOutline('primary')">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.eye" /></svg>
            {{ t('eshop.import2.apply_report_title') }}
          </RouterLink>
          <button
            v-else
            type="button"
            :disabled="actionLoading || !canApply"
            :title="readyCount === 0 ? t('eshop.import2.no_ready_rows') : undefined"
            :class="btnFilled('success')"
            data-test="apply-import"
            @click="apply"
          >
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
              <path :d="ICONS.check" />
            </svg>
            {{ actionLoading ? t('common.loading') : t('eshop.import2.apply_ready', { count: readyCount }) }}
          </button>
          <p v-if="readyCount === 0" class="mt-1 text-xs text-warning-700">
            {{ t('eshop.import2.no_ready_rows') }}
          </p>
        </div>
      </div>
    </section>
  </div>
</template>

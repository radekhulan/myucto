<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import type { Category, Manufacturer, Tag } from '@/api/eshop'
import { clientsApi, type Client } from '@/api/clients'
import {
  CATALOG_BULK_ERROR_CODES,
  CATALOG_BULK_ITEM_STATUSES,
  catalogBulkApi,
  type CatalogBulkChanges,
  type CatalogBulkItemState,
  type CatalogBulkItemStatus,
  type CatalogBulkJobItems,
  type CatalogBulkSelection,
} from '@/api/catalogBulk'
import { catalogJobsApi, type CatalogJob } from '@/api/catalogJobs'
import { useToast } from '@/composables/useToast'
import { formatNumber } from '@/composables/useFormat'
import Modal from '@/components/ui/Modal.vue'
import CatalogJobProgress from '@/components/stock/CatalogJobProgress.vue'
import { btnFilled, btnOutline } from '@/components/ui/buttonStyles'

const props = defineProps<{
  selection: CatalogBulkSelection
  selectedCount: number
  manufacturers: Manufacturer[]
  categories: Category[]
  tags: Tag[]
  initialJobId?: number
}>()
const emit = defineEmits<{
  (event: 'close'): void
  (event: 'completed'): void
  (event: 'open-history'): void
}>()

const { t } = useI18n()
const toast = useToast()
const bulkText = (key: string, params?: Record<string, unknown>) => t(`stock.items.bulk.${key}`, params ?? {})

const frozenSelection: CatalogBulkSelection = props.selection.all_matching
  ? {
      all_matching: true,
      filters: JSON.parse(JSON.stringify(props.selection.filters)),
      excluded_ids: [...props.selection.excluded_ids],
    }
  : { all_matching: false, ids: [...props.selection.ids] }
const frozenSelectedCount = props.selectedCount

const manufacturerMode = ref<'keep' | 'set' | 'clear'>('keep')
const manufacturerId = ref<number | ''>('')
const categoryMode = ref<'keep' | 'set' | 'clear'>('keep')
const categoryIds = ref<number[]>([])
const tagMode = ref<'keep' | 'set' | 'clear'>('keep')
const tagIds = ref<number[]>([])
const vendorMode = ref<'keep' | 'add' | 'remove' | 'replace'>('keep')
const vendorIds = ref<number[]>([])
const vendors = ref<Client[]>([])
const vendorsLoading = ref(false)
const activeMode = ref<'keep' | 'true' | 'false'>('keep')
const exportMode = ref<'keep' | 'true' | 'false'>('keep')
const minQty = ref('')

const job = ref<CatalogJob | null>(null)
const report = ref<CatalogBulkJobItems | null>(null)
const reportPage = ref(1)
const reportStatus = ref<CatalogBulkItemStatus | ''>('')
const reportLoading = ref(false)
const reportError = ref(false)
const busy = ref(false)
const cancelling = ref(false)
const resumeLoading = ref(false)
const resumeError = ref(false)

let pollTimer: ReturnType<typeof setInterval> | undefined
let jobRequestVersion = 0
let reportRequestVersion = 0
let actionRequestVersion = 0
let vendorRequestVersion = 0
const emittedCompletions = new Set<number>()

const changes = computed<CatalogBulkChanges>(() => {
  const result: CatalogBulkChanges = {}
  if (manufacturerMode.value === 'clear') result.manufacturer_id = null
  if (manufacturerMode.value === 'set' && manufacturerId.value !== '') result.manufacturer_id = Number(manufacturerId.value)
  if (categoryMode.value === 'clear') result.category_ids = []
  if (categoryMode.value === 'set') result.category_ids = categoryIds.value.map(Number)
  if (tagMode.value === 'clear') result.tag_ids = []
  if (tagMode.value === 'set') result.tag_ids = tagIds.value.map(Number)
  if (vendorMode.value !== 'keep') {
    result.vendor_mode = vendorMode.value
    result.vendor_ids = vendorIds.value.map(Number)
  }
  if (activeMode.value !== 'keep') result.is_active = activeMode.value === 'true'
  if (exportMode.value !== 'keep') result.export_eshop = exportMode.value === 'true'
  if (String(minQty.value).trim()) result.min_qty = String(minQty.value).trim()
  return result
})

const minQtyInvalid = computed(() => {
  const value = String(minQty.value).trim()
  return value !== '' && !/^(?:0|[1-9]\d{0,10})(?:\.\d{1,3})?$/.test(value)
})
const formValid = computed(() => (
  frozenSelectedCount > 0
  && Object.keys(changes.value).length > 0
  && !minQtyInvalid.value
  && (manufacturerMode.value !== 'set' || manufacturerId.value !== '')
  && (vendorMode.value === 'keep' || vendorMode.value === 'replace' || vendorIds.value.length > 0)
))
const activeJob = computed(() => !!job.value && ['queued', 'running'].includes(job.value.status))
const resumeMode = computed(() => props.initialJobId !== undefined)
const displaySelectedCount = computed(() => resumeMode.value ? (job.value?.total ?? 0) : frozenSelectedCount)
const isPreview = computed(() => job.value?.kind === 'catalog_bulk_preview')
const counts = computed<Record<string, number>>(() => {
  const raw = job.value?.report?.counts
  if (!raw || typeof raw !== 'object' || Array.isArray(raw)) return {}
  return Object.fromEntries(Object.entries(raw).map(([key, value]) => [key, Number(value) || 0]))
})
const readyCount = computed(() => counts.value.ready ?? 0)
const appliedCount = computed(() => counts.value.applied ?? 0)
const canApply = computed(() => isPreview.value && job.value?.status === 'completed' && readyCount.value > 0)
const canRestore = computed(() => (
  job.value?.kind === 'catalog_bulk_apply'
  && job.value.status === 'completed'
  && appliedCount.value > 0
))

const knownErrorCodes = new Set<string>(CATALOG_BULK_ERROR_CODES)
const knownStatuses = new Set<string>(CATALOG_BULK_ITEM_STATUSES)
type ComparedField = 'manufacturer_id' | 'categories' | 'tag_ids' | 'vendors' | 'is_active' | 'export_eshop' | 'min_qty'
const comparedFields: ComparedField[] = [
  'manufacturer_id', 'categories', 'tag_ids', 'vendors', 'is_active', 'export_eshop', 'min_qty',
]

function stopPolling() {
  if (pollTimer) clearInterval(pollTimer)
  pollTimer = undefined
}

function startPolling() {
  stopPolling()
  if (activeJob.value) pollTimer = setInterval(() => void refreshJob(), 2000)
}

function list(kind: 'manufacturer' | 'category' | 'tag') {
  if (kind === 'manufacturer') return props.manufacturers
  return kind === 'category' ? props.categories : props.tags
}

function showId(kind: 'manufacturer' | 'category' | 'tag', id: number) {
  return list(kind).find(item => item.id === id)?.name ?? `#${id}`
}

function categoryValue(state: CatalogBulkItemState): string {
  const categories = state.categories ?? state.category_ids.map((categoryId, index) => ({
    category_id: categoryId,
    is_primary: index === 0,
    display_order: index,
  }))
  if (!categories.length) return bulkText('none')
  return categories.map(category => {
    const details = [bulkText('category_order', { order: category.display_order })]
    if (category.is_primary) details.unshift(bulkText('category_primary'))
    return `${showId('category', category.category_id)} (${details.join(', ')})`
  }).join(', ')
}

function vendorName(id: number): string {
  return vendors.value.find(vendor => vendor.id === id)?.company_name ?? `#${id}`
}

function vendorValue(state: CatalogBulkItemState): string {
  const vendorRows = state.vendors ?? []
  if (!vendorRows.length) return bulkText('none')
  return vendorRows.map(vendor => {
    const details = [vendor.currency_code]
    if (vendor.purchase_price !== null) details.push(vendor.purchase_price)
    if (vendor.is_preferred) details.push(bulkText('vendor_preferred'))
    return `${vendorName(vendor.client_id)} (${details.join(', ')})`
  }).join(', ')
}

function displayValue(state: CatalogBulkItemState | null, field: ComparedField): string {
  if (!state) return '-'
  const value = state[field]
  if (field === 'manufacturer_id') {
    return value == null ? bulkText('none') : showId('manufacturer', Number(value))
  }
  if (field === 'categories') return categoryValue(state)
  if (field === 'tag_ids') {
    return (value as number[]).map(id => showId('tag', id)).join(', ') || bulkText('none')
  }
  if (field === 'vendors') return vendorValue(state)
  if (field === 'is_active' || field === 'export_eshop') return value ? t('common.yes') : t('common.no')
  if (field === 'min_qty' && value != null) return formatNumber(Number(value), { maximumFractionDigits: 3 })
  return value == null ? bulkText('none') : String(value)
}

function changedFields(item: CatalogBulkJobItems['items'][number]): ComparedField[] {
  return comparedFields.filter(field => (
    JSON.stringify(item.before?.[field] ?? null) !== JSON.stringify(item.after?.[field] ?? null)
  ))
}

function statusLabel(status: string) {
  return knownStatuses.has(status) ? bulkText(`status.${status}`) : bulkText('status_unknown', { status })
}

function errorLabel(errorCode: string) {
  return knownErrorCodes.has(errorCode)
    ? bulkText(`error.${errorCode}`)
    : bulkText('error.unknown', { code: errorCode })
}

function markCompletedOnce(completedJob: CatalogJob) {
  if (!['catalog_bulk_apply', 'catalog_bulk_restore'].includes(completedJob.kind)) return
  if (completedJob.status !== 'completed' || emittedCompletions.has(completedJob.id)) return
  emittedCompletions.add(completedJob.id)
  emit('completed')
}

async function loadReport(page = reportPage.value, expectedJobId = job.value?.id) {
  if (!expectedJobId) return
  const requestVersion = ++reportRequestVersion
  reportLoading.value = true
  reportError.value = false
  try {
    const nextReport = await catalogBulkApi.items(expectedJobId, {
      page,
      limit: 50,
      ...(reportStatus.value ? { status: reportStatus.value } : {}),
    })
    if (requestVersion !== reportRequestVersion || job.value?.id !== expectedJobId) return
    report.value = nextReport
    reportPage.value = page
  } catch (error: any) {
    if (requestVersion !== reportRequestVersion || job.value?.id !== expectedJobId) return
    reportError.value = true
    toast.error(error?.response?.data?.error?.message || t('common.error'))
  } finally {
    if (requestVersion === reportRequestVersion && job.value?.id === expectedJobId) reportLoading.value = false
  }
}

async function acceptJob(nextJob: CatalogJob, loadRunningReport = false) {
  ++jobRequestVersion
  ++reportRequestVersion
  job.value = nextJob
  if (nextJob.status === 'queued' || nextJob.status === 'running') {
    startPolling()
    if (loadRunningReport) await loadReport(1, nextJob.id)
    return
  }
  stopPolling()
  markCompletedOnce(nextJob)
  await loadReport(1, nextJob.id)
}

async function refreshJob() {
  const currentId = job.value?.id
  if (!currentId) return
  const requestVersion = ++jobRequestVersion
  try {
    const nextJob = await catalogJobsApi.get(currentId)
    if (requestVersion !== jobRequestVersion || job.value?.id !== currentId) return
    job.value = nextJob
    if (nextJob.status === 'queued' || nextJob.status === 'running') return
    stopPolling()
    markCompletedOnce(nextJob)
    await loadReport(1, currentId)
  } catch {}
}

async function previewJob() {
  if (resumeMode.value) return
  if (busy.value || !formValid.value) return
  busy.value = true
  const requestVersion = ++actionRequestVersion
  try {
    const nextJob = await catalogBulkApi.preview(frozenSelection, changes.value)
    if (requestVersion !== actionRequestVersion) return
    await acceptJob(nextJob, true)
  } catch (error: any) {
    if (requestVersion === actionRequestVersion) {
      toast.error(error?.response?.data?.error?.message || t('common.error'))
    }
  } finally {
    if (requestVersion === actionRequestVersion) busy.value = false
  }
}

async function run(kind: 'apply' | 'restore') {
  const sourceJob = job.value
  if (busy.value || !sourceJob) return
  if (kind === 'apply' && !canApply.value) return
  if (kind === 'restore' && !canRestore.value) return
  busy.value = true
  stopPolling()
  ++jobRequestVersion
  ++reportRequestVersion
  const requestVersion = ++actionRequestVersion
  try {
    const nextJob = await catalogBulkApi[kind](sourceJob.id)
    if (requestVersion !== actionRequestVersion || job.value?.id !== sourceJob.id) return
    report.value = null
    reportPage.value = 1
    reportStatus.value = ''
    await acceptJob(nextJob)
  } catch (error: any) {
    if (requestVersion === actionRequestVersion) {
      toast.error(error?.response?.data?.error?.message || t('common.error'))
      startPolling()
    }
  } finally {
    if (requestVersion === actionRequestVersion) busy.value = false
  }
}

async function cancelJob() {
  const currentId = job.value?.id
  if (!currentId || busy.value || cancelling.value || !activeJob.value) return
  cancelling.value = true
  stopPolling()
  ++jobRequestVersion
  const requestVersion = ++actionRequestVersion
  try {
    const nextJob = await catalogJobsApi.cancel(currentId)
    if (requestVersion !== actionRequestVersion || job.value?.id !== currentId) return
    await acceptJob(nextJob)
  } catch (error: any) {
    if (requestVersion === actionRequestVersion) {
      toast.error(error?.response?.data?.error?.message || t('common.error'))
      startPolling()
    }
  } finally {
    if (requestVersion === actionRequestVersion) cancelling.value = false
  }
}

async function retryJob() {
  const currentId = job.value?.id
  if (!currentId || busy.value) return
  busy.value = true
  stopPolling()
  ++jobRequestVersion
  ++reportRequestVersion
  const requestVersion = ++actionRequestVersion
  try {
    const nextJob = await catalogJobsApi.retry(currentId)
    if (requestVersion !== actionRequestVersion || job.value?.id !== currentId) return
    report.value = null
    await acceptJob(nextJob)
  } catch (error: any) {
    if (requestVersion === actionRequestVersion) toast.error(error?.response?.data?.error?.message || t('common.error'))
  } finally {
    if (requestVersion === actionRequestVersion) busy.value = false
  }
}

async function loadVendors(): Promise<void> {
  const requestVersion = ++vendorRequestVersion
  vendorsLoading.value = true
  const loaded: Client[] = []
  try {
    for (let page = 1; ; page++) {
      const result = await clientsApi.list({ role: 'vendors', per_page: 500, page, sort: 'name' })
      if (requestVersion !== vendorRequestVersion) return
      loaded.push(...result.data)
      if (page >= result.meta.pages) break
    }
    vendors.value = loaded
  } catch {
    if (requestVersion === vendorRequestVersion) vendors.value = []
  } finally {
    if (requestVersion === vendorRequestVersion) vendorsLoading.value = false
  }
}

function changeReportStatus() {
  if (!job.value || busy.value) return
  void loadReport(1)
}

onBeforeUnmount(() => {
  stopPolling()
  ++jobRequestVersion
  ++reportRequestVersion
  ++actionRequestVersion
  ++vendorRequestVersion
})

onMounted(async () => {
  void loadVendors()
  if (!props.initialJobId) return
  resumeLoading.value = true
  const requestVersion = ++actionRequestVersion
  try {
    const nextJob = await catalogJobsApi.get(props.initialJobId)
    if (requestVersion !== actionRequestVersion || !['catalog_bulk_preview', 'catalog_bulk_apply', 'catalog_bulk_restore'].includes(nextJob.kind)) {
      resumeError.value = true
      return
    }
    await acceptJob(nextJob, true)
  } catch {
    if (requestVersion === actionRequestVersion) resumeError.value = true
  } finally {
    if (requestVersion === actionRequestVersion) resumeLoading.value = false
  }
})
</script>

<template>
  <Modal :title="bulkText('title')" width-class="max-w-4xl" @close="emit('close')">
    <p data-test="selection-count" class="text-sm text-neutral-600">
      {{ bulkText('selection_count', { count: displaySelectedCount }) }}
    </p>

    <div v-if="resumeLoading" class="mt-4 py-8 text-center text-sm text-neutral-500">{{ t('common.loading') }}</div>
    <p v-else-if="resumeError" class="mt-4 text-sm text-danger-600">{{ bulkText('resume_error') }}</p>
    <div v-else-if="!job && !resumeMode" data-test="bulk-form" class="mt-4 grid grid-cols-1 gap-4 text-sm sm:grid-cols-2">
      <label>
        {{ bulkText('manufacturer') }}
        <select v-model="manufacturerMode" class="mt-1 h-9 w-full rounded-md border border-neutral-300 bg-surface px-2">
          <option value="keep">{{ bulkText('keep') }}</option>
          <option value="set">{{ bulkText('set') }}</option>
          <option value="clear">{{ bulkText('clear') }}</option>
        </select>
        <select v-if="manufacturerMode === 'set'" v-model="manufacturerId" class="mt-2 h-9 w-full rounded-md border border-neutral-300 bg-surface px-2">
          <option value="">{{ bulkText('choose') }}</option>
          <option v-for="manufacturer in manufacturers" :key="manufacturer.id" :value="manufacturer.id">
            {{ manufacturer.name }}
          </option>
        </select>
      </label>

      <label>
        {{ bulkText('categories') }}
        <select v-model="categoryMode" class="mt-1 h-9 w-full rounded-md border border-neutral-300 bg-surface px-2">
          <option value="keep">{{ bulkText('keep') }}</option>
          <option value="set">{{ bulkText('replace') }}</option>
          <option value="clear">{{ bulkText('clear') }}</option>
        </select>
        <select v-if="categoryMode === 'set'" v-model="categoryIds" multiple class="mt-2 min-h-20 w-full rounded-md border border-neutral-300 bg-surface px-2">
          <option v-for="category in categories" :key="category.id" :value="category.id">{{ category.name }}</option>
        </select>
      </label>

      <label>
        {{ bulkText('tags') }}
        <select v-model="tagMode" class="mt-1 h-9 w-full rounded-md border border-neutral-300 bg-surface px-2">
          <option value="keep">{{ bulkText('keep') }}</option>
          <option value="set">{{ bulkText('replace') }}</option>
          <option value="clear">{{ bulkText('clear') }}</option>
        </select>
        <select v-if="tagMode === 'set'" v-model="tagIds" multiple class="mt-2 min-h-20 w-full rounded-md border border-neutral-300 bg-surface px-2">
          <option v-for="tag in tags" :key="tag.id" :value="tag.id">{{ tag.name }}</option>
        </select>
      </label>

      <label>
        {{ bulkText('vendors') }}
        <select data-test="vendor-mode" v-model="vendorMode" class="mt-1 h-9 w-full rounded-md border border-neutral-300 bg-surface px-2">
          <option value="keep">{{ bulkText('keep') }}</option>
          <option value="add">{{ bulkText('vendor_add') }}</option>
          <option value="remove">{{ bulkText('vendor_remove') }}</option>
          <option value="replace">{{ bulkText('replace') }}</option>
        </select>
        <select v-if="vendorMode !== 'keep'" data-test="vendor-ids" v-model="vendorIds" multiple :disabled="vendorsLoading" class="mt-2 min-h-20 w-full rounded-md border border-neutral-300 bg-surface px-2 disabled:opacity-50">
          <option v-for="vendor in vendors" :key="vendor.id" :value="vendor.id">{{ vendor.company_name }}</option>
        </select>
        <p v-if="vendorMode !== 'keep'" class="mt-1 text-xs text-neutral-500">{{ bulkText('vendor_hint') }}</p>
      </label>

      <label>
        {{ bulkText('active') }}
        <select v-model="activeMode" class="mt-1 h-9 w-full rounded-md border border-neutral-300 bg-surface px-2">
          <option value="keep">{{ bulkText('keep') }}</option>
          <option value="true">{{ t('common.yes') }}</option>
          <option value="false">{{ t('common.no') }}</option>
        </select>
      </label>

      <label>
        {{ bulkText('export_eshop') }}
        <select v-model="exportMode" class="mt-1 h-9 w-full rounded-md border border-neutral-300 bg-surface px-2">
          <option value="keep">{{ bulkText('keep') }}</option>
          <option value="true">{{ t('common.yes') }}</option>
          <option value="false">{{ t('common.no') }}</option>
        </select>
      </label>

      <label>
        {{ bulkText('min_qty') }}
        <input
          v-model="minQty"
          type="number"
          min="0"
          step="0.001"
          inputmode="decimal"
          class="mt-1 h-9 w-full rounded-md border border-neutral-300 bg-surface px-2"
          :class="{ 'border-danger-500': minQtyInvalid }"
        />
        <span v-if="minQtyInvalid" class="mt-1 block text-xs text-danger-600">{{ bulkText('min_qty_invalid') }}</span>
      </label>
    </div>

    <div v-else-if="job" class="mt-4 space-y-3">
      <CatalogJobProgress
        :job="job"
        :cancelling="cancelling"
        :can-cancel="activeJob && !busy"
        @cancel="cancelJob"
      />

      <div v-if="isPreview && job.status === 'completed'" class="rounded-lg border border-primary-200 bg-primary-50/60 px-3 py-2 text-sm text-primary-800">
        {{ bulkText('preview_summary', { selected: displaySelectedCount, ready: readyCount }) }}
      </div>

      <div class="flex flex-wrap items-center gap-2">
        <label class="flex items-center gap-2 text-sm">
          <span>{{ bulkText('report_status') }}</span>
          <select
            v-model="reportStatus"
            :disabled="reportLoading || busy"
            class="h-8 rounded border border-neutral-300 bg-surface px-2 disabled:opacity-50"
            @change="changeReportStatus"
          >
            <option value="">{{ t('common.all') }}</option>
            <option v-for="status in CATALOG_BULK_ITEM_STATUSES" :key="status" :value="status">
              {{ statusLabel(status) }}
            </option>
          </select>
        </label>
        <button
          v-if="reportError"
          type="button"
          :disabled="reportLoading || busy"
          :class="btnOutline('warning')"
          class="whitespace-nowrap"
          @click="loadReport()"
        >
          <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v6h6M20 20v-6h-6M5.5 15a7 7 0 0 0 11.5 2M18.5 9A7 7 0 0 0 7 7" /></svg>
          {{ bulkText('retry_report') }}
        </button>
      </div>

      <div v-if="reportLoading" class="py-6 text-center text-sm text-neutral-500">{{ t('common.loading') }}</div>
      <div v-else-if="report?.items.length" class="overflow-x-hidden rounded-lg border border-neutral-200 text-sm sm:max-h-80 sm:overflow-y-auto">
        <article v-for="item in report.items" :key="item.ordinal" data-test="report-item" class="min-w-0 border-b border-neutral-100 p-3 last:border-b-0">
          <div class="flex min-w-0 flex-wrap items-start justify-between gap-2">
            <strong class="min-w-0 break-words">
              {{ item.before?.sku ?? item.after?.sku ?? `#${item.stock_item_id}` }}
              {{ item.before?.name ?? item.after?.name ?? '' }}
            </strong>
            <span class="whitespace-nowrap rounded bg-neutral-100 px-2 py-0.5 text-xs font-medium text-neutral-700">
              {{ statusLabel(item.status) }}
            </span>
          </div>
          <div v-for="field in changedFields(item)" :key="field" class="mt-2 min-w-0 rounded-md bg-neutral-50 p-2 text-xs">
            <span class="font-medium text-neutral-600">{{ bulkText(`field.${field}`) }}</span>
            <div class="mt-1 grid min-w-0 grid-cols-[minmax(0,1fr)_auto_minmax(0,1fr)] items-start gap-2">
              <span class="min-w-0 break-words">{{ displayValue(item.before, field) }}</span>
              <span aria-hidden="true" class="text-neutral-400">→</span>
              <strong class="min-w-0 break-words">{{ displayValue(item.after, field) }}</strong>
            </div>
          </div>
          <p v-if="item.error_code" class="mt-2 break-words text-xs text-danger-700">{{ errorLabel(item.error_code) }}</p>
        </article>
      </div>
      <p v-else-if="report" class="rounded-lg border border-neutral-200 px-3 py-5 text-center text-sm text-neutral-500">
        {{ bulkText('report_empty') }}
      </p>

      <div v-if="report?.pagination" class="flex flex-wrap items-center justify-between gap-2 text-sm">
        <button type="button" :disabled="reportLoading || busy || reportPage <= 1" :class="btnOutline('neutral')" class="whitespace-nowrap" @click="loadReport(reportPage - 1)">
          <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m15 18-6-6 6-6" /></svg>
          {{ bulkText('previous') }}
        </button>
        <span>{{ bulkText('page', { page: reportPage, pages: report.pagination.pages, total: report.pagination.total }) }}</span>
        <button data-test="report-next" type="button" :disabled="reportLoading || busy || reportPage >= report.pagination.pages" :class="btnOutline('neutral')" class="whitespace-nowrap" @click="loadReport(reportPage + 1)">
          {{ bulkText('next') }}
          <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m9 18 6-6-6-6" /></svg>
        </button>
      </div>
    </div>

    <template #footer>
      <div class="flex w-full flex-wrap items-center justify-between gap-2">
        <button v-if="!resumeMode" type="button" :disabled="busy || cancelling" :class="btnOutline('neutral')" class="whitespace-nowrap" @click="emit('open-history')">
          <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 2m6-2a9 9 0 1 1-3-6.7L3 8m0 0h5M3 8V3" /></svg>
          {{ bulkText('job_history') }}
        </button>
        <div class="flex flex-wrap items-center justify-end gap-2">
          <button
            v-if="job?.status === 'failed' || job?.status === 'cancelled'"
            type="button"
            :disabled="busy || cancelling"
            :class="btnOutline('primary')"
            class="whitespace-nowrap"
            @click="retryJob"
          >
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v6h6M20 20v-6h-6M5.5 15a7 7 0 0 0 11.5 2M18.5 9A7 7 0 0 0 7 7" /></svg>
            {{ t('common.retry') }}
          </button>
          <button v-if="canRestore" data-test="restore" type="button" :disabled="busy || cancelling" :class="btnOutline('warning')" class="whitespace-nowrap" @click="run('restore')">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 12a9 9 0 1 0 3-6.7L3 8m0 0h5M3 8V3" /></svg>
            {{ bulkText('restore_count', { count: appliedCount }) }}
          </button>
          <button
            v-if="isPreview && job?.status === 'completed'"
            data-test="apply"
            type="button"
            :disabled="busy || cancelling || !canApply"
            :class="btnFilled('success')"
            class="whitespace-nowrap"
            @click="run('apply')"
          >
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m5 12 4 4L19 6" /></svg>
            {{ bulkText('apply_count', { count: readyCount }) }}
          </button>
          <button
            v-else-if="!job && !resumeMode"
            data-test="preview"
            type="button"
            :disabled="busy || cancelling || !formValid"
            :class="btnFilled('primary')"
            class="whitespace-nowrap"
            @click="previewJob"
          >
            <svg v-if="busy" class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" d="M12 3a9 9 0 1 0 9 9" /></svg>
            <svg v-else class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 12s3-6 9-6 9 6 9 6-3 6-9 6-9-6-9-6Z" /><circle cx="12" cy="12" r="2" /></svg>
            {{ bulkText('preview') }}
          </button>
        </div>
      </div>
    </template>
  </Modal>
</template>

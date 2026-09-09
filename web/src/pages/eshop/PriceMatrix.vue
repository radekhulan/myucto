<script setup lang="ts">
import { computed, onActivated, onBeforeUnmount, onDeactivated, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { catalogPricingApi, type PriceMatrixJobItem, type PriceMatrixOperation, type PriceMatrixOverride } from '@/api/catalogPricing'
import { catalogJobsApi, type CatalogJob } from '@/api/catalogJobs'
import type { CatalogBulkSelection } from '@/api/catalogBulk'
import { eshopApi, type EshopCurrency } from '@/api/eshop'
import { stockApi, type StockItemSearchResult } from '@/api/stock'
import { apiErrorMessage } from '@/api/errors'
import { useAuthStore } from '@/stores/auth'
import { useSupplierStore } from '@/stores/supplier'
import CatalogJobProgress from '@/components/stock/CatalogJobProgress.vue'
import { ICONS, btnFilled, btnOutline } from '@/components/ui/buttonStyles'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const supplierStore = useSupplierStore()
const props = withDefaults(defineProps<{
  selection?: CatalogBulkSelection
  selectedCount?: number
  embedded?: boolean
}>(), {
  selection: undefined,
  selectedCount: 0,
  embedded: false,
})
const canWrite = computed(() => auth.canWrite('eshop.write') && auth.canWrite('stock.items.write'))
const currencies = ref<EshopCurrency[]>([])
const selectedCurrencies = ref<string[]>([])
const query = ref('')
const results = ref<StockItemSearchResult[]>([])
const selected = ref<StockItemSearchResult[]>([])
const searching = ref(false)
const loading = ref(false)
const currenciesReady = ref(false)
const busy = ref(false)
const error = ref('')
const ensureMissing = ref(true)
const reprice = ref(true)
const deviationThreshold = ref('10')
const today = new Date()
const onDate = ref(`${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, '0')}-${String(today.getDate()).padStart(2, '0')}`)
const overrides = ref<PriceMatrixOverride[]>([])
const exceptionItem = ref<number | ''>('')
const exceptionCurrency = ref('')
const exceptionOperation = ref<PriceMatrixOperation>('lock_current')
const exceptionPrice = ref('')
type MatrixJob = CatalogJob & { apply_job_id?: number | null }

const job = ref<MatrixJob | null>(null)
const report = ref<PriceMatrixJobItem[]>([])
const reportPage = ref(1)
const reportPages = ref(0)
const reportCurrency = ref('')
const reportIssue = ref('')
const importing = ref(false)
const fileInput = ref<HTMLInputElement | null>(null)
let timer: ReturnType<typeof setTimeout> | undefined
let alive = true
let paneActive = true
let wasDeactivated = false
let loadVersion = 0
let searchVersion = 0
let actionVersion = 0
let jobVersion = 0
let reportVersion = 0
let routeVersion = 0
let tenantVersion = 0
let tenantResetting = false

const operations: PriceMatrixOperation[] = ['lock_current', 'set_fixed', 'unlock_to_rules', 'delete']
const MAX_CSV_BYTES = 50_000_000
const activeJob = computed(() => !!job.value && ['queued', 'running'].includes(job.value.status))
const readyCount = computed(() => Number(job.value?.report?.counts && (job.value.report.counts as Record<string, number>).ready || 0))
const externalSelection = computed(() => props.selection !== undefined)
const effectiveSelectedCount = computed(() => externalSelection.value ? props.selectedCount : selected.value.length)
const applyJobId = computed(() => {
  const id = job.value?.apply_job_id
  return typeof id === 'number' && Number.isSafeInteger(id) && id > 0 ? id : null
})
const thresholdInvalid = computed(() => !/^(?:0|[1-9]\d{0,2}|1000)(?:[.,]\d{1,3})?$/.test(deviationThreshold.value.trim()) || Number(deviationThreshold.value.replace(',', '.')) > 1000)
const canPreview = computed(() => canWrite.value && effectiveSelectedCount.value > 0 && selectedCurrencies.value.length > 0 && !thresholdInvalid.value && !busy.value && !activeJob.value)

function icon(path: string) {
  return path
}

async function load() {
  if (!canWrite.value) return
  loading.value = true
  error.value = ''
  const request = ++loadVersion
  try {
    const loaded = (await eshopApi.listCurrencies()).filter(row => !row.archived)
    if (!requestIsCurrent(request, loadVersion)) return
    currencies.value = loaded
    selectedCurrencies.value = currencies.value.filter(row => row.is_default).map(row => row.code)
    if (!selectedCurrencies.value.length && currencies.value[0]) selectedCurrencies.value = [currencies.value[0].code]
    currenciesReady.value = true
    if (!externalSelection.value) await openRouteJob(route.query.matrix_job)
  } catch (e) {
    if (requestIsCurrent(request, loadVersion)) error.value = apiErrorMessage(e, t('common.error'))
  } finally {
    if (requestIsCurrent(request, loadVersion)) loading.value = false
  }
}

function requestIsCurrent(request: number, current: number) {
  return alive && paneActive && canWrite.value && request === current
}

function routeJobId(raw: unknown): number | null {
  const value = Array.isArray(raw) ? raw[0] : raw
  if (typeof value !== 'string' || !/^[1-9]\d*$/.test(value)) return null
  const id = Number(value)
  return Number.isSafeInteger(id) ? id : null
}

async function replaceMatrixJobQuery(id: number | null) {
  if (externalSelection.value) return
  const current = routeJobId(route.query.matrix_job)
  if (current === id && (id !== null || route.query.matrix_job === undefined)) return
  const query = { ...route.query }
  if (id === null) delete query.matrix_job
  else query.matrix_job = String(id)
  await router.replace({ query })
}

async function openRouteJob(raw: unknown) {
  if (externalSelection.value || !canWrite.value) return
  const id = routeJobId(raw)
  const request = ++routeVersion
  if (id === null) {
    if (raw !== undefined) await replaceMatrixJobQuery(null)
    return
  }
  try {
    const existing = await catalogJobsApi.get(id)
    if (!requestIsCurrent(request, routeVersion)) return
    if (!['price_matrix_preview', 'price_matrix_apply'].includes(existing.kind)) {
      await replaceMatrixJobQuery(null)
      return
    }
    acceptJob(existing, false)
  } catch (e) {
    if (requestIsCurrent(request, routeVersion)) error.value = apiErrorMessage(e, t('common.error'))
  }
}

async function search() {
  if (!query.value.trim() || searching.value) return
  searching.value = true
  error.value = ''
  const request = ++searchVersion
  try {
    const found = await stockApi.searchItems(query.value.trim(), 50)
    if (!requestIsCurrent(request, searchVersion)) return
    results.value = found
  } catch (e) {
    if (requestIsCurrent(request, searchVersion)) error.value = apiErrorMessage(e, t('common.error'))
  } finally {
    if (requestIsCurrent(request, searchVersion)) searching.value = false
  }
}

function addItem(item: StockItemSearchResult) {
  if (!selected.value.some(row => row.id === item.id)) selected.value.push(item)
}

function removeItem(id: number) {
  selected.value = selected.value.filter(row => row.id !== id)
  overrides.value = overrides.value.filter(row => row.item_id !== id)
}

function addException() {
  if (exceptionItem.value === '' || !exceptionCurrency.value) return
  if (exceptionOperation.value === 'set_fixed' && !/^\d+(?:[.,]\d{1,2})?$/.test(exceptionPrice.value.trim())) return
  const next: PriceMatrixOverride = {
    item_id: Number(exceptionItem.value),
    currency_code: exceptionCurrency.value,
    operation: exceptionOperation.value,
  }
  if (exceptionOperation.value === 'set_fixed') next.fixed_price = exceptionPrice.value.replace(',', '.')
  overrides.value = overrides.value.filter(row => !(row.item_id === next.item_id && row.currency_code === next.currency_code))
  overrides.value.push(next)
}

function exceptionLabel(row: PriceMatrixOverride) {
  const item = selected.value.find(value => value.id === row.item_id)
  return `${item?.sku ?? `#${row.item_id}`} · ${row.currency_code} · ${t(`eshop.price_matrix.operation.${row.operation}`)}`
}

async function preview() {
  if (!canPreview.value) return
  busy.value = true
  error.value = ''
  const request = ++actionVersion
  try {
    const selection: CatalogBulkSelection = props.selection
      ? JSON.parse(JSON.stringify(props.selection)) as CatalogBulkSelection
      : { all_matching: false, ids: selected.value.map(row => row.id) }
    const next = await catalogPricingApi.previewMatrix(
      selection as unknown as Parameters<typeof catalogPricingApi.previewMatrix>[0],
      {
        currencies: selectedCurrencies.value,
        on_date: onDate.value,
        ensure_missing: ensureMissing.value,
        reprice: reprice.value,
        deviation_threshold_pct: deviationThreshold.value.replace(',', '.'),
        overrides: overrides.value,
      },
    )
    if (!requestIsCurrent(request, actionVersion)) return
    acceptJob(next)
  } catch (e) {
    if (requestIsCurrent(request, actionVersion)) error.value = apiErrorMessage(e, t('common.error'))
  } finally {
    if (requestIsCurrent(request, actionVersion)) busy.value = false
  }
}

function acceptJob(next: CatalogJob, syncQuery = true) {
  job.value = next
  if (next.currencies?.length) selectedCurrencies.value = next.currencies
  report.value = []
  reportPage.value = 1
  reportPages.value = 0
  stopPolling()
  if (['queued', 'running'].includes(next.status)) timer = setTimeout(() => void poll(next.id), 1500)
  else void loadReport(next.id, 1)
  if (syncQuery) void replaceMatrixJobQuery(next.id).catch(() => undefined)
}

async function poll(id: number) {
  const request = ++jobVersion
  try {
    const next = await catalogJobsApi.get(id)
    if (!requestIsCurrent(request, jobVersion) || job.value?.id !== id) return
    job.value = next
    if (['queued', 'running'].includes(next.status)) timer = setTimeout(() => void poll(id), 1500)
    else await loadReport(id, 1)
  } catch (e) {
    if (requestIsCurrent(request, jobVersion)) error.value = apiErrorMessage(e, t('common.error'))
  }
}

async function loadReport(id: number, page = reportPage.value) {
  const request = ++reportVersion
  try {
    const data = await catalogPricingApi.matrixItems(id, {
      page,
      limit: 100,
      ...(reportCurrency.value ? { currency: reportCurrency.value } : {}),
      ...(reportIssue.value ? { issue: reportIssue.value } : {}),
    })
    if (requestIsCurrent(request, reportVersion) && job.value?.id === id) {
      job.value = data.job
      if (data.job.currencies?.length) selectedCurrencies.value = data.job.currencies
      report.value = data.items
      reportPage.value = data.pagination.page
      reportPages.value = data.pagination.pages
    }
  } catch (e) {
    if (requestIsCurrent(request, reportVersion)) error.value = apiErrorMessage(e, t('common.error'))
  }
}

async function apply() {
  if (!job.value || job.value.kind !== 'price_matrix_preview' || job.value.status !== 'completed' || applyJobId.value !== null || readyCount.value < 1 || busy.value) return
  busy.value = true
  error.value = ''
  const source = job.value.id
  const request = ++actionVersion
  try {
    const next = await catalogPricingApi.applyMatrix(source)
    if (requestIsCurrent(request, actionVersion) && job.value?.id === source) acceptJob(next)
  } catch (e) {
    if (requestIsCurrent(request, actionVersion)) error.value = apiErrorMessage(e, t('common.error'))
  } finally {
    if (requestIsCurrent(request, actionVersion)) busy.value = false
  }
}

async function exportCsv(view: 'before' | 'after') {
  if (!job.value || busy.value) return
  busy.value = true
  const request = ++actionVersion
  try {
    const blob = await catalogPricingApi.exportMatrix(job.value.id, view)
    if (!requestIsCurrent(request, actionVersion)) return
    const url = URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = url
    link.download = `price-matrix-${job.value.id}-${view}.csv`
    link.click()
    URL.revokeObjectURL(url)
  } catch (e) {
    if (requestIsCurrent(request, actionVersion)) error.value = apiErrorMessage(e, t('common.error'))
  } finally {
    if (requestIsCurrent(request, actionVersion)) busy.value = false
  }
}

async function importCsv(event: Event) {
  const file = (event.target as HTMLInputElement).files?.[0]
  if (!file || importing.value) return
  if (file.size > MAX_CSV_BYTES) {
    error.value = t('eshop.price_matrix.file_too_large')
    if (fileInput.value) fileInput.value.value = ''
    return
  }
  importing.value = true
  error.value = ''
  const request = ++actionVersion
  try {
    const next = await catalogPricingApi.importMatrix(file)
    if (requestIsCurrent(request, actionVersion)) acceptJob(next)
  } catch (e) {
    if (requestIsCurrent(request, actionVersion)) error.value = apiErrorMessage(e, t('common.error'))
  } finally {
    if (requestIsCurrent(request, actionVersion)) importing.value = false
    if (fileInput.value) fileInput.value.value = ''
  }
}

function stopPolling() {
  if (timer) clearTimeout(timer)
  timer = undefined
}

function clearJobState() {
  stopPolling()
  actionVersion++
  jobVersion++
  reportVersion++
  routeVersion++
  job.value = null
  report.value = []
  reportPage.value = 1
  reportPages.value = 0
  reportCurrency.value = ''
  reportIssue.value = ''
  error.value = ''
}

async function reset() {
  clearJobState()
  await replaceMatrixJobQuery(null)
}

function pause() {
  stopPolling()
  loadVersion++
  searchVersion++
  actionVersion++
  jobVersion++
  reportVersion++
  routeVersion++
  loading.value = false
  searching.value = false
  busy.value = false
  importing.value = false
}

function clearSensitiveState() {
  pause()
  currencies.value = []
  currenciesReady.value = false
  selectedCurrencies.value = []
  selected.value = []
  results.value = []
  overrides.value = []
  exceptionItem.value = ''
  exceptionCurrency.value = ''
  clearJobState()
}

function display(value: string | number | null | undefined) {
  return value == null || value === '' ? t('eshop.price_matrix.none') : String(value)
}

onMounted(load)
onDeactivated(() => {
  wasDeactivated = true
  paneActive = false
  pause()
})
onActivated(() => {
  if (!wasDeactivated || !alive) return
  wasDeactivated = false
  paneActive = true
  if (!canWrite.value) return
  if (!currencies.value.length) {
    void load()
    return
  }
  if (!externalSelection.value) {
    const requested = routeJobId(route.query.matrix_job)
    const current = job.value?.id ?? null
    if (requested !== current || (route.query.matrix_job !== undefined && requested === null)) {
      clearJobState()
      if (route.query.matrix_job === undefined) {
        loading.value = false
      } else {
        loading.value = true
        void openRouteJob(route.query.matrix_job).finally(() => {
          if (alive && paneActive) loading.value = false
        })
      }
      return
    }
  }
  if (activeJob.value && job.value) {
    timer = setTimeout(() => void poll(job.value!.id), 1500)
  } else if (job.value?.status === 'completed') {
    void loadReport(job.value.id)
  }
})
watch(() => route.query.matrix_job, (next, previous) => {
  if (externalSelection.value || next === previous) return
  if (!currenciesReady.value) {
    routeVersion++
    return
  }
  const nextId = routeJobId(next)
  if (nextId !== null && nextId === job.value?.id) return
  loadVersion++
  clearJobState()
  if (next === undefined) {
    loading.value = false
    return
  }
  if (!paneActive) {
    loading.value = false
    return
  }
  loading.value = true
  void openRouteJob(next).finally(() => {
    if (alive && paneActive) loading.value = false
  })
})
watch(canWrite, allowed => {
  if (!allowed) {
    clearSensitiveState()
  } else if (alive && paneActive && !tenantResetting) {
    void load()
  }
})
watch(() => supplierStore.currentSupplierId, async (next, previous) => {
  if (next === previous) return
  const request = ++tenantVersion
  tenantResetting = true
  clearSensitiveState()
  try {
    await replaceMatrixJobQuery(null)
  } finally {
    if (request !== tenantVersion || supplierStore.currentSupplierId !== next) return
    tenantResetting = false
    if (alive && paneActive && canWrite.value) void load()
  }
})
onBeforeUnmount(() => {
  stopPolling()
  alive = false
  paneActive = false
  pause()
})
</script>

<template>
  <section class="space-y-4">
    <div v-if="!embedded" class="flex flex-wrap items-start justify-between gap-3">
      <div>
        <h2 class="text-xl font-semibold">{{ t('eshop.price_matrix.title') }}</h2>
        <p class="mt-1 text-sm text-neutral-500">{{ t('eshop.price_matrix.subtitle') }}</p>
      </div>
      <div v-if="canWrite" class="flex flex-wrap gap-2">
        <input ref="fileInput" type="file" accept=".csv,text/csv" class="hidden" @change="importCsv">
        <button type="button" :class="btnOutline('neutral')" :disabled="busy || importing" @click="fileInput?.click()">
          <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="icon(ICONS.upload)" /></svg>
          {{ t('eshop.price_matrix.import') }}
        </button>
        <button v-if="job" type="button" :class="btnOutline('neutral')" @click="reset">
          <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="icon(ICONS.cycle)" /></svg>
          {{ t('eshop.price_matrix.new') }}
        </button>
      </div>
    </div>

    <div v-if="!canWrite" class="rounded-lg border border-warning-300 bg-warning-50 p-4 text-sm text-warning-800">
      {{ t('eshop.price_matrix.forbidden') }}
    </div>
    <div v-else-if="loading" class="rounded-lg border border-neutral-200 bg-surface p-6 text-sm text-neutral-500">{{ t('common.loading') }}</div>
    <template v-else>
      <div v-if="error" data-test="matrix-error" class="rounded-lg border border-danger-300 bg-danger-50 p-3 text-sm text-danger-700">{{ error }}</div>

      <div v-if="!job" class="grid gap-4 xl:grid-cols-2">
        <div v-if="!externalSelection" class="rounded-lg border border-neutral-200 bg-surface p-4 space-y-4">
          <h3 class="font-medium">{{ t('eshop.price_matrix.products') }}</h3>
          <label class="block text-sm font-medium text-neutral-700">
            {{ t('eshop.price_matrix.search_label') }}
            <div class="mt-1 flex gap-2">
              <input v-model="query" data-test="matrix-search" class="min-w-0 flex-1 rounded-md border border-neutral-300 bg-surface px-3 py-2" @keyup.enter="search">
              <button type="button" :class="btnOutline('primary')" :disabled="searching || !query.trim()" @click="search">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="icon(ICONS.search)" /></svg>
                {{ t('common.search') }}
              </button>
            </div>
          </label>
          <div v-if="results.length" class="max-h-48 overflow-auto rounded-md border border-neutral-200">
            <button v-for="item in results" :key="item.id" type="button" :data-test="`matrix-result-${item.id}`" class="flex w-full items-center justify-between gap-3 border-b border-neutral-100 px-3 py-2 text-left text-sm last:border-0 hover:bg-neutral-50" @click="addItem(item)">
              <span><strong>{{ item.sku }}</strong> · {{ item.name }}</span>
              <svg class="h-4 w-4 shrink-0 text-primary-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="icon(ICONS.plus)" /></svg>
            </button>
          </div>
          <div class="flex flex-wrap gap-2">
            <span v-for="item in selected" :key="item.id" class="inline-flex items-center gap-2 rounded-full bg-primary-50 px-3 py-1 text-sm text-primary-800">
              {{ item.sku }}
              <button type="button" :aria-label="t('common.remove')" class="text-danger-600" @click="removeItem(item.id)">
                <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="icon(ICONS.x)" /></svg>
              </button>
            </span>
          </div>
        </div>

        <div v-else data-test="matrix-external-selection" class="rounded-lg border border-primary-200 bg-primary-50/60 p-4">
          <h3 class="font-medium text-primary-900">{{ t('eshop.price_matrix.products') }}</h3>
          <p class="mt-1 text-sm text-primary-800">{{ t('eshop.price_matrix.selected_count', { count: effectiveSelectedCount }) }}</p>
        </div>

        <div class="rounded-lg border border-neutral-200 bg-surface p-4 space-y-4">
          <h3 class="font-medium">{{ t('eshop.price_matrix.settings') }}</h3>
          <div>
            <span class="block text-sm font-medium text-neutral-700">{{ t('eshop.price_matrix.currencies') }}</span>
            <div class="mt-2 flex flex-wrap gap-3">
              <label v-for="currency in currencies" :key="currency.code" class="flex items-center gap-2 text-sm">
                <input v-model="selectedCurrencies" type="checkbox" :value="currency.code">
                {{ currency.code }} · {{ currency.name }}
              </label>
            </div>
          </div>
          <label class="block text-sm font-medium text-neutral-700">
            {{ t('eshop.price_matrix.on_date') }}
            <input v-model="onDate" data-test="matrix-on-date" type="date" class="mt-1 block w-full rounded-md border border-neutral-300 bg-surface px-3 py-2">
          </label>
          <label class="block text-sm font-medium text-neutral-700">
            {{ t('eshop.price_matrix.deviation_threshold') }}
            <input v-model="deviationThreshold" data-test="matrix-deviation-threshold" inputmode="decimal" class="mt-1 block w-full rounded-md border border-neutral-300 bg-surface px-3 py-2">
            <span v-if="thresholdInvalid" class="mt-1 block text-xs text-danger-600">{{ t('eshop.price_matrix.deviation_threshold_invalid') }}</span>
          </label>
          <label class="flex items-center gap-2 text-sm"><input v-model="ensureMissing" type="checkbox">{{ t('eshop.price_matrix.ensure_missing') }}</label>
          <label class="flex items-center gap-2 text-sm"><input v-model="reprice" type="checkbox">{{ t('eshop.price_matrix.reprice') }}</label>
        </div>

        <div v-if="!externalSelection" class="rounded-lg border border-neutral-200 bg-surface p-4 space-y-3 xl:col-span-2">
          <h3 class="font-medium">{{ t('eshop.price_matrix.exceptions') }}</h3>
          <div class="grid gap-3 md:grid-cols-4">
            <label class="block text-sm font-medium text-neutral-700">{{ t('eshop.price_matrix.product') }}
              <select v-model="exceptionItem" data-test="matrix-exception-item" class="mt-1 block w-full rounded-md border border-neutral-300 bg-surface px-3 py-2"><option value="">{{ t('eshop.price_matrix.choose') }}</option><option v-for="item in selected" :key="item.id" :value="item.id">{{ item.sku }} · {{ item.name }}</option></select>
            </label>
            <label class="block text-sm font-medium text-neutral-700">{{ t('eshop.price_matrix.currency') }}
              <select v-model="exceptionCurrency" data-test="matrix-exception-currency" class="mt-1 block w-full rounded-md border border-neutral-300 bg-surface px-3 py-2"><option value="">{{ t('eshop.price_matrix.choose') }}</option><option v-for="code in selectedCurrencies" :key="code">{{ code }}</option></select>
            </label>
            <label class="block text-sm font-medium text-neutral-700">{{ t('eshop.price_matrix.operation_label') }}
              <select v-model="exceptionOperation" data-test="matrix-exception-operation" class="mt-1 block w-full rounded-md border border-neutral-300 bg-surface px-3 py-2"><option v-for="operation in operations" :key="operation" :value="operation">{{ t(`eshop.price_matrix.operation.${operation}`) }}</option></select>
            </label>
            <label v-if="exceptionOperation === 'set_fixed'" class="block text-sm font-medium text-neutral-700">{{ t('eshop.price_matrix.fixed_price') }}
              <input v-model="exceptionPrice" data-test="matrix-exception-price" inputmode="decimal" class="mt-1 block w-full rounded-md border border-neutral-300 bg-surface px-3 py-2">
            </label>
          </div>
          <button data-test="matrix-add-exception" type="button" :class="btnOutline('warning')" :disabled="exceptionItem === '' || !exceptionCurrency" @click="addException">
            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="icon(ICONS.plus)" /></svg>
            {{ t('eshop.price_matrix.add_exception') }}
          </button>
          <div class="flex flex-wrap gap-2"><span v-for="row in overrides" :key="`${row.item_id}:${row.currency_code}`" class="inline-flex items-center gap-2 rounded-full bg-warning-50 px-3 py-1 text-sm text-warning-800">{{ exceptionLabel(row) }}<button type="button" class="text-danger-600" :aria-label="t('common.remove')" @click="overrides = overrides.filter(value => value !== row)"><svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="icon(ICONS.x)" /></svg></button></span></div>
        </div>

        <div class="rounded-lg border border-neutral-200 bg-surface p-4 xl:col-span-2">
          <button data-test="matrix-preview" type="button" :class="btnFilled('primary')" :disabled="!canPreview" @click="preview">
            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="icon(ICONS.eye)" /></svg>
            {{ t('eshop.price_matrix.preview') }}
          </button>
          <p v-if="effectiveSelectedCount < 1 || !selectedCurrencies.length" class="mt-2 text-xs text-warning-700">{{ t('eshop.price_matrix.preview_disabled') }}</p>
        </div>
      </div>

      <div v-else class="space-y-4">
        <div class="rounded-lg border border-neutral-200 bg-surface p-4"><CatalogJobProgress :job="job" /></div>
        <div v-if="job.status === 'completed'" class="flex flex-wrap gap-2">
          <button v-if="job.kind === 'price_matrix_preview' && applyJobId === null" data-test="matrix-apply" type="button" :class="btnFilled('success')" :disabled="busy || readyCount < 1" @click="apply"><svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="icon(ICONS.check)" /></svg>{{ t('eshop.price_matrix.apply') }}</button>
          <RouterLink v-if="job.kind === 'price_matrix_preview' && applyJobId !== null" data-test="matrix-apply-job" :to="{ path: '/eshop', query: { tab: 'price-matrix', matrix_job: String(applyJobId) } }" :class="btnOutline('primary')"><svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="icon(ICONS.eye)" /></svg>{{ t('eshop.price_matrix.open_apply_job') }}</RouterLink>
          <button type="button" :class="btnOutline('neutral')" :disabled="busy" @click="exportCsv('before')"><svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="icon(ICONS.download)" /></svg>{{ t('eshop.price_matrix.export_before') }}</button>
          <button type="button" :class="btnOutline('neutral')" :disabled="busy" @click="exportCsv('after')"><svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="icon(ICONS.download)" /></svg>{{ t('eshop.price_matrix.export_after') }}</button>
        </div>
        <div v-if="job.status === 'completed'" class="flex flex-wrap items-end gap-3 rounded-lg border border-neutral-200 bg-surface p-3">
          <label class="block text-sm font-medium text-neutral-700">{{ t('eshop.price_matrix.filter_currency') }}
            <select v-model="reportCurrency" class="mt-1 block rounded-md border border-neutral-300 bg-surface px-3 py-2" @change="loadReport(job.id, 1)"><option value="">{{ t('eshop.price_matrix.all') }}</option><option v-for="code in selectedCurrencies" :key="code">{{ code }}</option></select>
          </label>
          <label class="block text-sm font-medium text-neutral-700">{{ t('eshop.price_matrix.filter_issue') }}
            <select v-model="reportIssue" class="mt-1 block rounded-md border border-neutral-300 bg-surface px-3 py-2" @change="loadReport(job.id, 1)"><option value="">{{ t('eshop.price_matrix.all') }}</option><option value="missing_price">{{ t('eshop.price_matrix.issue.missing_price') }}</option><option value="manual_override">{{ t('eshop.price_matrix.issue.manual_override') }}</option><option value="deviation">{{ t('eshop.price_matrix.issue.deviation') }}</option></select>
          </label>
        </div>
        <div class="hidden overflow-x-auto rounded-lg border border-neutral-200 bg-surface md:block">
          <table class="min-w-full text-sm"><thead class="bg-neutral-50 text-left text-neutral-600"><tr><th class="px-3 py-2">{{ t('eshop.price_matrix.product') }}</th><th class="px-3 py-2">{{ t('eshop.price_matrix.status') }}</th><th v-for="code in selectedCurrencies" :key="code" class="px-3 py-2">{{ code }}</th></tr></thead><tbody><tr v-for="row in report" :key="row.ordinal" class="border-t border-neutral-100"><td class="px-3 py-3"><strong>{{ row.before?.sku || row.after?.sku }}</strong><br><span class="text-neutral-500">{{ row.before?.name || row.after?.name }}</span></td><td class="px-3 py-3">{{ t(`eshop.price_matrix.statuses.${row.status}`) }}<div v-if="row.error_code" class="text-xs text-danger-600">{{ t(`eshop.price_matrix.errors.${row.error_code}`) }}</div></td><td v-for="code in selectedCurrencies" :key="code" class="px-3 py-3 align-top"><div class="text-neutral-500">{{ display(row.before?.cells[code]?.price) }} →</div><div class="font-medium">{{ display(row.after?.cells[code]?.price) }}</div><div class="text-xs text-neutral-500">{{ t('eshop.price_matrix.cost') }}: {{ display(row.after?.cells[code]?.cost_czk) }} · {{ t('eshop.price_matrix.margin') }}: {{ display(row.after?.cells[code]?.margin_pct) }}{{ row.after?.cells[code]?.margin_pct == null ? '' : '%' }}</div><div class="text-xs text-neutral-500">{{ t('eshop.price_matrix.rate') }}: {{ display(row.after?.cells[code]?.rate) }} · {{ t('eshop.price_matrix.rule') }}: {{ display(row.after?.cells[code]?.rule_id) }}</div><div v-if="row.after?.cells[code]?.deviation_pct != null" class="text-xs text-neutral-500">{{ t('eshop.price_matrix.deviation') }}: {{ row.after.cells[code]?.deviation_pct }}%</div><div v-if="row.after?.issues?.[code]?.length" class="mt-1 flex flex-wrap gap-1"><span v-for="issue in row.after.issues[code]" :key="issue" class="rounded-full bg-warning-50 px-2 py-0.5 text-xs text-warning-800">{{ t(`eshop.price_matrix.issue.${issue}`) }}</span></div></td></tr></tbody></table>
        </div>
        <div class="space-y-3 md:hidden"><article v-for="row in report" :key="row.ordinal" class="rounded-lg border border-neutral-200 bg-surface p-4"><div class="flex justify-between gap-3"><strong>{{ row.before?.sku || row.after?.sku }}</strong><span>{{ t(`eshop.price_matrix.statuses.${row.status}`) }}</span></div><p class="text-sm text-neutral-500">{{ row.before?.name || row.after?.name }}</p><dl class="mt-3 grid gap-2 text-sm"><template v-for="code in selectedCurrencies" :key="code"><dt class="font-medium">{{ code }}</dt><dd>{{ display(row.before?.cells[code]?.price) }} → <strong>{{ display(row.after?.cells[code]?.price) }}</strong><br><span class="text-xs text-neutral-500">{{ t('eshop.price_matrix.cost') }} {{ display(row.after?.cells[code]?.cost_czk) }}, {{ t('eshop.price_matrix.margin') }} {{ display(row.after?.cells[code]?.margin_pct) }}{{ row.after?.cells[code]?.margin_pct == null ? '' : '%' }}, {{ t('eshop.price_matrix.deviation') }} {{ display(row.after?.cells[code]?.deviation_pct) }}%</span><div v-if="row.after?.issues?.[code]?.length" class="mt-1 flex flex-wrap gap-1"><span v-for="issue in row.after.issues[code]" :key="issue" class="rounded-full bg-warning-50 px-2 py-0.5 text-xs text-warning-800">{{ t(`eshop.price_matrix.issue.${issue}`) }}</span></div></dd></template></dl></article></div>
        <div v-if="reportPages > 1" class="flex flex-wrap items-center justify-between gap-2">
          <button type="button" :class="btnOutline('neutral')" :disabled="reportPage <= 1" @click="loadReport(job.id, reportPage - 1)"><svg class="h-4 w-4 rotate-90" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="icon(ICONS.chevron)" /></svg>{{ t('eshop.price_matrix.previous') }}</button>
          <span class="text-sm text-neutral-500">{{ reportPage }} / {{ reportPages }}</span>
          <button type="button" :class="btnOutline('neutral')" :disabled="reportPage >= reportPages" @click="loadReport(job.id, reportPage + 1)">{{ t('eshop.price_matrix.next') }}<svg class="h-4 w-4 -rotate-90" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="icon(ICONS.chevron)" /></svg></button>
        </div>
      </div>
    </template>
  </section>
</template>

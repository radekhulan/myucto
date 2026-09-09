<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import {
  CATALOG_EXPORT_FIELDS,
  catalogExportApi,
  type CatalogExportField,
} from '@/api/catalogExport'
import type { CatalogBulkSelection } from '@/api/catalogBulk'
import { catalogJobsApi, type CatalogJob } from '@/api/catalogJobs'
import CatalogJobProgress from '@/components/stock/CatalogJobProgress.vue'
import Modal from '@/components/ui/Modal.vue'
import { btnFilled, btnOutline, ICONS } from '@/components/ui/buttonStyles'

interface CodeOption {
  code: string
  label?: string
}

interface WarehouseOption {
  id: number
  code?: string
  name: string
}

const props = withDefaults(defineProps<{
  selection: CatalogBulkSelection
  selectedCount: number
  canManageJobs: boolean
  localeOptions?: readonly CodeOption[]
  currencyOptions?: readonly CodeOption[]
  warehouseOptions?: readonly WarehouseOption[]
  initialJobId?: number
}>(), {
  localeOptions: () => [],
  currencyOptions: () => [],
  warehouseOptions: () => [],
})

const emit = defineEmits<{
  (event: 'close'): void
  (event: 'created', job: CatalogJob): void
}>()

const { t } = useI18n()

const defaultFields: CatalogExportField[] = ['sku', 'name', 'ean', 'is_active']
const selectedFields = ref<CatalogExportField[]>([...defaultFields])
const localesCsv = ref('cs')
const currenciesCsv = ref('CZK')
const warehouseIds = ref<number[]>([])
const activeJob = ref<CatalogJob | null>(null)
const starting = ref(false)
const cancelling = ref(false)
const retrying = ref(false)
const requestError = ref<string | null>(null)
const resumeLoading = ref(false)
const startedScope = ref<string | null>(null)
const pollGeneration = ref(0)
const pollingGeneration = ref<number | null>(null)
const actionGeneration = ref(0)
let disposed = false
let pollTimer: ReturnType<typeof setInterval> | null = null

const isTerminal = computed(() => {
  return activeJob.value?.status === 'completed'
    || activeJob.value?.status === 'failed'
    || activeJob.value?.status === 'cancelled'
})

const selectionLabel = computed(() => {
  if (startedScope.value !== null) return startedScope.value

  return props.selection.all_matching
    ? t('stock.items.export.scope_all_matching', { count: props.selectedCount })
    : t('stock.items.export.scope_selected', { count: props.selectedCount })
})

const locales = computed(() => parseCodes(localesCsv.value))
const currencies = computed(() => parseCodes(currenciesCsv.value).map(currency => currency.toUpperCase()))
const canStart = computed(() => {
  return !configurationLocked.value
    && selectedFields.value.length > 0
    && locales.value.length > 0
    && currencies.value.length > 0
})
const configurationLocked = computed(() => {
  return starting.value || (activeJob.value !== null && !isTerminal.value)
})
const resumeMode = computed(() => props.initialJobId !== undefined)

const jobReport = computed<Record<string, unknown>>(() => activeJob.value?.report ?? {})
const reportCounts = computed(() => {
  const values = [
    { key: 'requested', value: activeJob.value?.total ?? null },
    { key: 'exported', value: readNestedReportNumber('counts', 'ready') },
    { key: 'conflicts', value: readNestedReportNumber('counts', 'conflict') },
    { key: 'failed', value: readNestedReportNumber('counts', 'failed') },
  ]

  return values.flatMap(({ key, value }) => {
    return value === null ? [] : [{ key, value }]
  })
})

watch(() => props.selection, () => {
  requestError.value = null
})

onBeforeUnmount(() => {
  disposed = true
  actionGeneration.value += 1
  invalidatePolling()
})

function parseCodes(value: string): string[] {
  return [...new Set(value.split(',').map(code => code.trim()).filter(Boolean))]
}

function toggleField(field: CatalogExportField, checked: boolean): void {
  if (checked && !selectedFields.value.includes(field)) {
    selectedFields.value = [...selectedFields.value, field]
    return
  }

  if (!checked) {
    selectedFields.value = selectedFields.value.filter(selected => selected !== field)
  }
}

function onFieldChange(field: CatalogExportField, event: Event): void {
  toggleField(field, (event.target as HTMLInputElement).checked)
}

function toggleWarehouse(id: number, checked: boolean): void {
  if (checked && !warehouseIds.value.includes(id)) {
    warehouseIds.value = [...warehouseIds.value, id]
    return
  }

  if (!checked) {
    warehouseIds.value = warehouseIds.value.filter(selected => selected !== id)
  }
}

function onWarehouseChange(id: number, event: Event): void {
  toggleWarehouse(id, (event.target as HTMLInputElement).checked)
}

async function startExport(): Promise<void> {
  if (!canStart.value || resumeMode.value) return

  const action = ++actionGeneration.value
  const scope = props.selection.all_matching
    ? t('stock.items.export.scope_all_matching', { count: props.selectedCount })
    : t('stock.items.export.scope_selected', { count: props.selectedCount })
  starting.value = true
  requestError.value = null
  invalidatePolling()

  try {
    const job = await catalogExportApi.create({
      selection: props.selection,
      projection: {
        fields: [...selectedFields.value],
        locales: locales.value,
        currencies: currencies.value,
        ...(warehouseIds.value.length > 0 ? { warehouse_ids: [...warehouseIds.value] } : {}),
      },
    })
    if (disposed || action !== actionGeneration.value) return

    activeJob.value = job
    startedScope.value = scope
    emit('created', job)
    startPolling()
  } catch {
    if (!disposed && action === actionGeneration.value) {
      requestError.value = t('stock.items.export.create_error')
    }
  } finally {
    if (!disposed && action === actionGeneration.value) {
      starting.value = false
    }
  }
}

async function refreshJob(generation: number): Promise<void> {
  if (pollingGeneration.value !== null || !activeJob.value || isTerminal.value) return

  const jobId = activeJob.value.id
  pollingGeneration.value = generation
  try {
    const job = await catalogJobsApi.get(jobId)
    if (generation !== pollGeneration.value || activeJob.value?.id !== jobId) return

    activeJob.value = job
    if (isTerminal.value) stopPolling(generation)
  } catch {
    if (generation === pollGeneration.value) {
      requestError.value = t('stock.items.export.status_error')
    }
  } finally {
    if (pollingGeneration.value === generation) {
      pollingGeneration.value = null
    }
  }
}

function startPolling(): void {
  if (disposed) return

  invalidatePolling()
  if (isTerminal.value) return

    const generation = pollGeneration.value
  void refreshJob(generation)
  pollTimer = setInterval(() => {
    void refreshJob(generation)
  }, 2_000)
}

function stopPolling(generation?: number): void {
  if (generation !== undefined && generation !== pollGeneration.value) return

  if (pollTimer !== null) {
    clearInterval(pollTimer)
    pollTimer = null
  }
}

function invalidatePolling(): void {
  pollGeneration.value += 1
  stopPolling()
}

async function cancelJob(): Promise<void> {
  if (!props.canManageJobs || !activeJob.value || cancelling.value) return

  const action = ++actionGeneration.value
  const jobId = activeJob.value.id
  cancelling.value = true
  requestError.value = null
  invalidatePolling()
  try {
    const job = await catalogJobsApi.cancel(jobId)
    if (disposed || action !== actionGeneration.value) return

    activeJob.value = job
    startPolling()
  } catch {
    if (!disposed && action === actionGeneration.value) {
      requestError.value = t('stock.items.export.cancel_error')
      startPolling()
    }
  } finally {
    if (!disposed && action === actionGeneration.value) {
      cancelling.value = false
    }
  }
}

async function retryJob(): Promise<void> {
  if (!props.canManageJobs || !activeJob.value || retrying.value || !isTerminal.value) return

  const action = ++actionGeneration.value
  const jobId = activeJob.value.id
  retrying.value = true
  requestError.value = null
  invalidatePolling()
  try {
    const job = await catalogJobsApi.retry(jobId)
    if (disposed || action !== actionGeneration.value) return

    activeJob.value = job
    startPolling()
  } catch {
    if (!disposed && action === actionGeneration.value) {
      requestError.value = t('stock.items.export.retry_error')
    }
  } finally {
    if (!disposed && action === actionGeneration.value) {
      retrying.value = false
    }
  }
}

function readNestedReportNumber(section: string, key: string): number | null {
  const value = jobReport.value[section]
  if (typeof value !== 'object' || value === null || Array.isArray(value)) return null

  const nested = (value as Record<string, unknown>)[key]
  return typeof nested === 'number' && Number.isFinite(nested) ? nested : null
}

function fieldLabel(field: CatalogExportField): string {
  return t(`stock.items.export.field.${field}`)
}

onMounted(async () => {
  if (!props.initialJobId) return
  resumeLoading.value = true
  const action = ++actionGeneration.value
  try {
    const job = await catalogJobsApi.get(props.initialJobId)
    if (disposed || action !== actionGeneration.value) return
    if (job.kind !== 'catalog_export') {
      requestError.value = t('stock.items.export.resume_error')
      return
    }
    activeJob.value = job
    startedScope.value = t('stock.items.export.scope_selected', { count: job.total ?? 0 })
    startPolling()
  } catch {
    if (!disposed && action === actionGeneration.value) requestError.value = t('stock.items.export.resume_error')
  } finally {
    if (!disposed && action === actionGeneration.value) resumeLoading.value = false
  }
})
</script>

<template>
  <Modal :title="t('stock.items.export.title')" width-class="max-w-3xl" @close="emit('close')">
    <p class="text-sm text-neutral-600">{{ selectionLabel }}</p>

    <div class="mt-5 rounded-md border border-primary-200 bg-primary-50 p-3 text-sm text-primary-900">
      <p class="font-medium">{{ t('stock.items.export.snapshot_title') }}</p>
      <p class="mt-1 leading-relaxed">{{ t('stock.items.export.snapshot_hint') }}</p>
    </div>

    <div v-if="resumeLoading" class="mt-5 py-8 text-center text-sm text-neutral-500">{{ t('common.loading') }}</div>
    <template v-else>
    <div v-if="!resumeMode" class="mt-5 grid gap-5 sm:grid-cols-2">
      <fieldset :disabled="configurationLocked">
        <legend class="text-sm font-semibold text-neutral-900">{{ t('stock.items.export.fields_title') }}</legend>
        <div class="mt-2 grid grid-cols-1 gap-2 sm:grid-cols-2">
          <label v-for="field in CATALOG_EXPORT_FIELDS" :key="field" class="flex items-center gap-2 text-sm text-neutral-700">
            <input
              type="checkbox"
              :checked="selectedFields.includes(field)"
              @change="onFieldChange(field, $event)"
            >
            <span>{{ fieldLabel(field) }}</span>
          </label>
        </div>
      </fieldset>

      <div class="space-y-4">
        <label class="block text-sm font-semibold text-neutral-900">
          {{ t('stock.items.export.locales') }}
          <input
            v-model="localesCsv"
            class="mt-1 block w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm text-neutral-900"
            type="text"
            inputmode="text"
            :disabled="configurationLocked"
            :placeholder="localeOptions.map(option => option.code).join(', ') || 'cs, en'"
          >
        </label>
        <p class="-mt-3 text-xs text-neutral-500">{{ t('stock.items.export.csv_hint') }}</p>

        <label class="block text-sm font-semibold text-neutral-900">
          {{ t('stock.items.export.currencies') }}
          <input
            v-model="currenciesCsv"
            class="mt-1 block w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm text-neutral-900"
            type="text"
            inputmode="text"
            :disabled="configurationLocked"
            :placeholder="currencyOptions.map(option => option.code).join(', ') || 'CZK, EUR'"
          >
        </label>
        <p class="-mt-3 text-xs text-neutral-500">{{ t('stock.items.export.csv_hint') }}</p>
      </div>
    </div>

    <fieldset v-if="!resumeMode && warehouseOptions.length > 0" class="mt-5" :disabled="configurationLocked">
      <legend class="text-sm font-semibold text-neutral-900">{{ t('stock.items.export.warehouses') }}</legend>
      <p class="mt-1 text-xs text-neutral-500">{{ t('stock.items.export.warehouses_hint') }}</p>
      <div class="mt-2 flex flex-wrap gap-x-4 gap-y-2">
        <label v-for="warehouse in warehouseOptions" :key="warehouse.id" class="flex items-center gap-2 text-sm text-neutral-700">
          <input
            type="checkbox"
            :checked="warehouseIds.includes(warehouse.id)"
            @change="onWarehouseChange(warehouse.id, $event)"
          >
          <span>{{ warehouse.code ? `${warehouse.code} - ${warehouse.name}` : warehouse.name }}</span>
        </label>
      </div>
    </fieldset>

    <p v-if="configurationLocked" class="mt-4 text-xs text-neutral-500">{{ t('stock.items.export.running_hint') }}</p>
    <p v-else-if="!resumeMode && !canStart" class="mt-4 text-xs text-warning-700">{{ t('stock.items.export.projection_required') }}</p>
    <p v-if="requestError" class="mt-4 text-sm text-danger-600" role="alert">{{ requestError }}</p>

    <div v-if="!resumeMode" class="mt-5 flex flex-wrap gap-2">
      <button
        type="button"
        data-test="catalog-export-start"
        :class="btnFilled('primary')"
        :disabled="!canStart"
        :title="!canStart ? t('stock.items.export.projection_required') : undefined"
        @click="startExport"
      >
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
          <path :d="ICONS.download" />
        </svg>
        {{ starting ? t('stock.items.export.starting') : t('stock.items.export.start') }}
      </button>
    </div>

    <div v-if="activeJob" class="mt-6 border-t border-neutral-200 pt-5">
      <CatalogJobProgress :job="activeJob" :cancelling="cancelling" :can-cancel="canManageJobs" @cancel="cancelJob" />

      <div v-if="reportCounts.length > 0" class="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-xs text-neutral-600">
        <span v-for="count in reportCounts" :key="count.key">
          {{ t(`stock.items.export.report.${count.key}`, { count: count.value }) }}
        </span>
      </div>
      <p class="mt-2 text-xs leading-relaxed text-neutral-500">{{ t('stock.items.export.capture_hint') }}</p>

      <div class="mt-4 flex flex-wrap gap-2">
        <a
          v-if="activeJob.status === 'completed'"
          :href="catalogExportApi.downloadUrl(activeJob.id, activeJob.supplier_id)"
          :class="btnFilled('success')"
          download
        >
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path :d="ICONS.download" />
          </svg>
          {{ t('stock.items.export.download') }}
        </a>
        <button
          v-if="canManageJobs && (activeJob.status === 'failed' || activeJob.status === 'cancelled')"
          type="button"
          :class="btnOutline('warning')"
          :disabled="retrying"
          @click="retryJob"
        >
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path :d="ICONS.cycle" />
          </svg>
          {{ retrying ? t('stock.items.export.retrying') : t('stock.items.export.retry') }}
        </button>
      </div>
      <p v-if="!canManageJobs" class="mt-3 text-xs text-neutral-500">{{ t('stock.items.export.readonly_jobs_hint') }}</p>
    </div>
    </template>
  </Modal>
</template>

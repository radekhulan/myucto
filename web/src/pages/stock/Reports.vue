<script setup lang="ts">
import { ref, reactive, computed, onMounted, onBeforeUnmount } from 'vue'
import { useI18n } from 'vue-i18n'
import { stockApi, type StockStatusReport, type StockValuationReport, type Warehouse } from '@/api/stock'
import { useToast } from '@/composables/useToast'
import { useAuthStore } from '@/stores/auth'
import { useRoute } from 'vue-router'
import { formatMoney } from '@/composables/useFormat'
import { ICONS, btnOutline } from '@/components/ui/buttonStyles'
import EmptyState from '@/components/ui/EmptyState.vue'
import { appIsoDate } from '@/utils/date'
import DateInput from '@/components/ui/DateInput.vue'
import { catalogJobsApi, type CatalogJob, type ValuationJobResult } from '@/api/catalogJobs'
import CatalogJobProgress from '@/components/stock/CatalogJobProgress.vue'

const { t } = useI18n()
const toast = useToast()
const auth = useAuthStore()
const route = useRoute()

const tab = ref<'status' | 'valuation'>('status')
const warehouses = ref<Warehouse[]>([])
const loading = ref(false)

const filters = reactive({
  warehouse_id: '' as number | '',
  date: appIsoDate(),
})

const statusReport = ref<StockStatusReport | null>(null)
const valuationReport = ref<StockValuationReport | null>(null)
const valuationJob = ref<CatalogJob | null>(null)
const valuationResult = ref<ValuationJobResult | null>(null)
const valuationPage = ref(1)
const cancellingValuation = ref(false)
let valuationTimer: ReturnType<typeof setTimeout> | undefined
let valuationGeneration = 0

async function load() {
  loading.value = true
  try {
    if (tab.value === 'status') {
      statusReport.value = await stockApi.reportStatus({ warehouse_id: filters.warehouse_id || undefined })
    } else { await startValuation() }
  } catch (e: any) {
    const code = e?.response?.data?.error?.code
    if (code === 'too_many_movements' || code === 'stock.error.too_many_movements') {
      toast.warning(t('stock.reports.too_many_movements'))
    } else {
      toast.error(e?.response?.data?.error?.message || t('common.error'))
    }
  } finally {
    loading.value = false
  }
}

function clearValuationTimer() { if (valuationTimer) clearTimeout(valuationTimer) }
async function startValuation() {
  clearValuationTimer()
  const generation = ++valuationGeneration
  valuationResult.value = null
  valuationReport.value = null
  valuationPage.value = 1
  const job = await catalogJobsApi.createValuation({ date: filters.date, warehouse_id: filters.warehouse_id || undefined })
  if (generation !== valuationGeneration) return
  valuationJob.value = job
  await pollValuation(generation)
}
async function pollValuation(generation = valuationGeneration) {
  if (!valuationJob.value || generation !== valuationGeneration) return
  try {
    const job = await catalogJobsApi.getValuationStatus(valuationJob.value.id)
    if (generation !== valuationGeneration) return
    valuationJob.value = job
    if (job.status === 'completed') { await loadValuationPage(); return }
    if (job.status === 'failed' || job.status === 'cancelled') return
    valuationTimer = setTimeout(() => { void pollValuation(generation) }, 1200)
  } catch (e: any) {
    if (generation !== valuationGeneration) return
    toast.error(e?.response?.data?.error?.message || t('common.error'))
    valuationTimer = setTimeout(() => { void pollValuation(generation) }, 5000)
  }
}
async function loadValuationPage(page = valuationPage.value) {
  if (!valuationJob.value) return
  const generation = valuationGeneration
  try {
    const result = await catalogJobsApi.getValuation(valuationJob.value.id, { page, limit: 50 })
    if (generation !== valuationGeneration) return
    valuationPage.value = page
    valuationResult.value = result
    filters.date = result.date
  } catch (e: any) { toast.error(e?.response?.data?.error?.message || t('common.error')) }
}
async function cancelValuation() {
  if (!valuationJob.value) return
  cancellingValuation.value = true
  try {
    await catalogJobsApi.cancelValuation(valuationJob.value.id)
    clearValuationTimer()
    await pollValuation()
  } catch (e: any) { toast.error(e?.response?.data?.error?.message || t('common.error')) }
  finally { cancellingValuation.value = false }
}

function selectTab(t2: 'status' | 'valuation') { clearValuationTimer(); ++valuationGeneration; tab.value = t2; void load() }

async function exportFile(format: 'pdf' | 'xlsx') {
  const url = stockApi.reportExportUrl(tab.value, format, {
    warehouse_id: filters.warehouse_id || undefined,
    date: tab.value === 'valuation' ? filters.date : undefined,
    job_id: tab.value === 'valuation' ? valuationResult.value?.job_id : undefined,
  })
  window.open(url, '_blank', 'noopener')
}

const statusRows = computed(() => statusReport.value?.items ?? [])
const valuationRows = computed(() => valuationResult.value?.items ?? valuationReport.value?.items ?? [])

onMounted(async () => {
  try { warehouses.value = await stockApi.listWarehouses(true) } catch { warehouses.value = [] }
  const jobId = Number(route.query.valuation_job)
  if (Number.isSafeInteger(jobId) && jobId > 0) {
    tab.value = 'valuation'
    try {
      valuationJob.value = await catalogJobsApi.getValuationStatus(jobId)
      await pollValuation()
    } catch (e: any) { toast.error(e?.response?.data?.error?.message || t('common.error')) }
    return
  }
  await load()
})
onBeforeUnmount(() => { clearValuationTimer(); ++valuationGeneration })
</script>

<template>
  <div>
    <div class="mb-4">
      <h1 class="text-2xl font-semibold">{{ t('stock.reports.title') }}</h1>
    </div>

    <!-- Taby -->
    <div class="flex gap-1 border-b border-neutral-200 overflow-x-auto mb-4">
      <button type="button" @click="selectTab('status')"
        class="cursor-pointer px-3 py-2 text-sm border-b-2 transition whitespace-nowrap"
        :class="tab === 'status' ? 'border-primary-600 text-primary-700 font-medium' : 'border-transparent text-neutral-600 hover:text-neutral-900'">
        {{ t('stock.reports.tab_status') }}
      </button>
      <button type="button" @click="selectTab('valuation')"
        class="cursor-pointer px-3 py-2 text-sm border-b-2 transition whitespace-nowrap"
        :class="tab === 'valuation' ? 'border-primary-600 text-primary-700 font-medium' : 'border-transparent text-neutral-600 hover:text-neutral-900'">
        {{ t('stock.reports.tab_valuation') }}
      </button>
    </div>

    <!-- Filtry -->
    <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-3 mb-4">
      <div class="flex flex-wrap items-end gap-3">
        <div>
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('stock.reports.filter_warehouse') }}</label>
          <select v-model="filters.warehouse_id" @change="load" class="h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface min-w-[10rem]">
            <option value="">{{ t('common.all') }}</option>
            <option v-for="w in warehouses" :key="w.id" :value="w.id">{{ w.name }}</option>
          </select>
        </div>
        <div v-if="tab === 'valuation'">
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('stock.reports.filter_date') }}</label>
          <DateInput v-model="filters.date" @change="load" class="h-9 px-2 border border-neutral-300 rounded-md text-sm" />
        </div>
        <div class="flex flex-wrap gap-2 ml-auto">
          <button :disabled="loading || (tab === 'valuation' && !valuationResult)" @click="exportFile('pdf')" :class="btnOutline('primary')">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.download" /></svg>
            {{ t('stock.reports.export_pdf') }}
          </button>
          <button :disabled="loading || (tab === 'valuation' && !valuationResult)" @click="exportFile('xlsx')" :class="btnOutline('primary')">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.download" /></svg>
            {{ t('stock.reports.export_xlsx') }}
          </button>
        </div>
      </div>
    </div>

    <div v-if="loading" class="text-center text-neutral-500 py-12 text-sm">{{ t('common.loading') }}</div>

    <!-- Stav zásob -->
    <template v-else-if="tab === 'status'">
      <!-- Sestava bez řádků není chyba, jen zvolené datum/sklad nic nemá —
           proto tichý neutrální tón a rada, co změnit, ne zakládací akce. -->
      <EmptyState v-if="statusRows.length === 0" boxed accent="neutral" icon="warehouse"
        :title="t('stock.reports.empty_title')" :message="t('stock.reports.empty_hint')" />
      <div v-else class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
              <tr>
                <th class="px-3 py-2 text-left font-medium">{{ t('stock.reports.col_sku') }}</th>
                <th class="px-3 py-2 text-left font-medium">{{ t('stock.reports.col_name') }}</th>
                <th class="px-3 py-2 text-left font-medium">{{ t('stock.reports.col_warehouse') }}</th>
                <th class="px-3 py-2 text-right font-medium">{{ t('stock.reports.col_qty') }}</th>
                <th class="px-3 py-2 text-right font-medium">{{ t('stock.reports.col_avg_cost') }}</th>
                <th class="px-3 py-2 text-right font-medium">{{ t('stock.reports.col_value') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="r in statusRows" :key="`${r.warehouse_id}-${r.stock_item_id}`" class="hover:bg-neutral-50" :class="{ 'bg-danger-50/40': r.min_qty != null && Number(r.qty) < Number(r.min_qty) }">
                <td class="px-3 py-2 font-mono text-xs">{{ r.sku }}</td>
                <td class="px-3 py-2">{{ r.name }}</td>
                <td class="px-3 py-2">{{ r.warehouse_name }}</td>
                <td class="px-3 py-2 text-right font-mono">{{ r.qty }}</td>
                <td class="px-3 py-2 text-right font-mono">{{ formatMoney(Number(r.avg_unit_cost)) }}</td>
                <td class="px-3 py-2 text-right font-mono">{{ formatMoney(Number(r.value_total)) }}</td>
              </tr>
            </tbody>
            <tfoot v-if="statusReport">
              <tr class="border-t-2 border-neutral-300 font-semibold bg-neutral-50">
                <td class="px-3 py-2" colspan="5">{{ t('stock.reports.totals') }} ({{ statusReport.totals.count }})</td>
                <td class="px-3 py-2 text-right font-mono">{{ formatMoney(Number(statusReport.totals.value_total)) }}</td>
              </tr>
            </tfoot>
          </table>
        </div>
      </div>
    </template>

    <!-- Ocenění -->
    <template v-else>
      <CatalogJobProgress v-if="valuationJob && ['queued', 'running'].includes(valuationJob.status)" class="mb-4" :job="valuationJob" :cancelling="cancellingValuation" :can-cancel="auth.canWrite('stock')" @cancel="cancelValuation" />
      <p v-if="valuationJob?.status === 'failed' || valuationJob?.status === 'cancelled'" class="text-sm text-danger-600 mb-3">{{ t('stock.reports.valuation_failed') }}</p><EmptyState v-if="valuationJob?.status === 'completed' && valuationRows.length === 0" boxed accent="neutral" icon="coin"
        :title="t('stock.reports.empty_title')" :message="t('stock.reports.empty_hint')" />
      <div v-else-if="valuationRows.length > 0" class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
          <table class="min-w-[44rem] w-full text-sm">
            <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
              <tr>
                <th class="px-3 py-2 text-left font-medium whitespace-nowrap">{{ t('stock.reports.col_sku') }}</th>
                <th class="px-3 py-2 text-left font-medium">{{ t('stock.reports.col_name') }}</th>
                <th class="px-3 py-2 text-left font-medium">{{ t('stock.reports.col_warehouse') }}</th>
                <th class="px-3 py-2 text-right font-medium whitespace-nowrap">{{ t('stock.reports.col_qty') }}</th>
                <th class="px-3 py-2 text-right font-medium whitespace-nowrap">{{ t('stock.reports.col_value') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="r in valuationRows" :key="`${r.warehouse_id}-${r.stock_item_id}`" class="hover:bg-neutral-50">
                <td class="px-3 py-2 font-mono text-xs whitespace-nowrap">{{ r.sku }}</td>
                <td class="px-3 py-2">{{ r.name }}</td>
                <td class="px-3 py-2">{{ r.warehouse_name }}</td>
                <td class="px-3 py-2 text-right font-mono whitespace-nowrap">{{ r.qty }}</td>
                <td class="px-3 py-2 text-right font-mono whitespace-nowrap">{{ formatMoney(Number(r.value_total)) }}</td>
              </tr>
            </tbody>
            <tfoot v-if="valuationResult || valuationReport">
              <tr class="border-t-2 border-neutral-300 font-semibold bg-neutral-50">
                <td class="px-3 py-2" colspan="4">{{ t('stock.reports.totals') }} ({{ (valuationResult ?? valuationReport)!.totals.count }})</td>
                <td class="px-3 py-2 text-right font-mono whitespace-nowrap">{{ formatMoney(Number((valuationResult ?? valuationReport)!.totals.value_total)) }}</td>
              </tr>
            </tfoot>
          </table>
        </div>
      </div>
      <div v-if="valuationResult && valuationResult.pagination.pages > 1" class="flex flex-wrap justify-between gap-2 mt-3"><button type="button" :disabled="valuationPage <= 1" :class="btnOutline('neutral')" @click="loadValuationPage(valuationPage - 1)"><svg class="w-4 h-4 rotate-90" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.chevron" /></svg>{{ t('common.previous') }}</button><span class="text-sm text-neutral-500 self-center">{{ valuationPage }} / {{ valuationResult.pagination.pages }}</span><button type="button" :disabled="valuationPage >= valuationResult.pagination.pages" :class="btnOutline('neutral')" @click="loadValuationPage(valuationPage + 1)">{{ t('common.next') }}<svg class="w-4 h-4 -rotate-90" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.chevron" /></svg></button></div>
    </template>
  </div>
</template>

<script setup lang="ts">
import { ref, reactive, computed, watch, onMounted, onBeforeUnmount } from 'vue'
import { useI18n } from 'vue-i18n'
import { stockApi, type StockStatusReport, type StockStatusColumn, type StockValuationReport, type StockValueTrendReport, type StockSalesReport, type StockSalesAmount, type Warehouse } from '@/api/stock'
import { eshopApi, type Attribute, type Category } from '@/api/eshop'
import ColumnPicker from '@/components/ui/ColumnPicker.vue'
import { useTablePrefs, type ColumnDef } from '@/composables/useTablePrefs'
import ClientSearchSelect from '@/components/ui/ClientSearchSelect.vue'
import PaginationBar from '@/components/ui/PaginationBar.vue'
import { indentedCategoryLabel } from '@/utils/categoryLabel'
import { useToast } from '@/composables/useToast'
import { useAuthStore } from '@/stores/auth'
import { useRoute, RouterLink } from 'vue-router'
import { formatMoney, formatDate, formatDateTime, formatNumber } from '@/composables/useFormat'
import { ICONS, btnFilled, btnOutline } from '@/components/ui/buttonStyles'
import EmptyState from '@/components/ui/EmptyState.vue'
import { appIsoDate } from '@/utils/date'
import DateInput from '@/components/ui/DateInput.vue'
import { catalogJobsApi, type CatalogJob, type ValuationJobResult, type ValuationGroupParams } from '@/api/catalogJobs'
import CatalogJobProgress from '@/components/stock/CatalogJobProgress.vue'

const { t } = useI18n()
const toast = useToast()
const auth = useAuthStore()
const route = useRoute()

type ReportTab = 'status' | 'valuation' | 'trend' | 'sales'
const tab = ref<ReportTab>('status')
// Prodeje ukazují odběratele a prodejní ceny, proto jen s právem číst vydané faktury (hlídá i API).
const canSeeSales = computed(() => auth.canRead('invoices'))
const warehouses = ref<Warehouse[]>([])
const loading = ref(false)

const filters = reactive({
  warehouse_id: '' as number | '',
  date: appIsoDate(),
})

const categories = ref<Category[]>([])
const attributes = ref<Attribute[]>([])

// Stav zásob: filtr kategorie (včetně podkategorií), sloupce a parametr karty.
// Viditelnost sloupců si pamatují uživatelské předvolby tabulky, jako u seznamu karet.
const STATUS_COLUMNS: ColumnDef[] = [
  { key: 'sku', labelKey: 'stock.reports.col_sku', required: true },
  { key: 'name', labelKey: 'stock.reports.col_name', required: true },
  { key: 'warehouse', labelKey: 'stock.reports.col_warehouse', required: true },
  { key: 'qty', labelKey: 'stock.reports.col_qty', required: true },
  { key: 'avg_cost', labelKey: 'stock.reports.col_avg_cost' },
  { key: 'value', labelKey: 'stock.reports.col_value' },
  { key: 'sale_price', labelKey: 'stock.reports.col_sale_price', defaultHidden: true },
  { key: 'note', labelKey: 'stock.reports.col_note', defaultHidden: true },
]
const STATUS_API_COLUMNS: Array<[string, StockStatusColumn]> = [
  ['avg_cost', 'avg_unit_cost'], ['value', 'value_total'], ['sale_price', 'sale_price'], ['note', 'note'],
]
const statusTbl = useTablePrefs('stock-report-status', STATUS_COLUMNS)
const statusFilters = reactive({
  category_id: '' as number | '',
  attribute_id: '' as number | '',
})
/** Výchozí průměrná cena + hodnota = bez parametru (odpověď beze změny), žádný sloupec = 'none'. */
const statusColumnsParam = computed<string | undefined>(() => {
  const keys = STATUS_API_COLUMNS.filter(([ui]) => statusTbl.isVisible(ui)).map(([, api]) => api)
  if (keys.join(',') === 'avg_unit_cost,value_total') return undefined
  return keys.length ? keys.join(',') : 'none'
})
function statusParams() {
  return {
    warehouse_id: filters.warehouse_id || undefined,
    category_id: statusFilters.category_id || undefined,
    columns: statusColumnsParam.value,
    attribute_id: statusFilters.attribute_id || undefined,
  }
}
/** API vrací ano/ne neutrálně ('true'/'false'), překlad je až tady. */
function statusAttributeText(value: string | null | undefined): string {
  if (value == null) return ''
  if (statusReport.value?.attribute?.data_type !== 'bool') return value
  return value.split(', ').map(v => v === 'true' ? t('common.yes') : v === 'false' ? t('common.no') : v).join(', ')
}
const statusAttributeLabel = computed(() => {
  const a = statusReport.value?.attribute
  return a ? (a.unit ? `${a.name} [${a.unit}]` : a.name) : ''
})
/** Sloupce před hodnotou (pro součtový řádek), resp. všechny sloupce, když je hodnota skrytá. */
const statusLeadColumns = computed(() => 4 + (statusReport.value?.attribute ? 1 : 0) + (statusTbl.isVisible('avg_cost') ? 1 : 0))
const statusTrailColumns = computed(() => (statusTbl.isVisible('sale_price') ? 1 : 0) + (statusTbl.isVisible('note') ? 1 : 0))
const sales = ref<StockSalesReport | null>(null)
const salesFilters = reactive({
  date_from: `${appIsoDate().slice(0, 4)}-01-01`,
  date_to: appIsoDate(),
  client_id: null as number | null,
  client_label: null as string | null,
  category_id: '' as number | '',
  stock_item_id: null as number | null,
  item_label: null as string | null,
  q: '',
  group_by: 'none' as 'none' | 'client' | 'item',
  page: 1,
})
let salesSearchTimer: ReturnType<typeof setTimeout> | undefined

function salesParams() {
  return {
    date_from: salesFilters.date_from || undefined,
    date_to: salesFilters.date_to || undefined,
    client_id: salesFilters.client_id ?? undefined,
    category_id: salesFilters.category_id || undefined,
    warehouse_id: filters.warehouse_id || undefined,
    stock_item_id: salesFilters.stock_item_id ?? undefined,
    q: salesFilters.q.trim() || undefined,
    group_by: salesFilters.group_by,
  }
}

// Hledání se posílá při psaní; pomalejší starší odpověď nesmí přepsat novější.
let salesGeneration = 0
async function loadSales() {
  const generation = ++salesGeneration
  const result = await stockApi.reportSales({ ...salesParams(), page: salesFilters.page, per_page: 50 })
  if (generation === salesGeneration) sales.value = result
}

function onWarehouseChange() {
  if (tab.value === 'sales') reloadSales()
  else if (tab.value !== 'trend') void load() // vývoj hodnoty se počítá až tlačítkem
}

function reloadSales(resetPage = true) {
  if (resetPage) salesFilters.page = 1
  void load()
}

function onSalesSearch() {
  if (salesSearchTimer) clearTimeout(salesSearchTimer)
  salesSearchTimer = setTimeout(() => reloadSales(), 350)
}

function setGroupBy(value: 'none' | 'client' | 'item') {
  salesFilters.group_by = value
  reloadSales()
}

/** Klik na souhrnný řádek zúží řádky na daného odběratele / kartu. */
function drillDown(id: number | null, label: string, code: string) {
  if (id === null) return
  if (salesFilters.group_by === 'client') {
    salesFilters.client_id = id
    salesFilters.client_label = label
  } else {
    salesFilters.stock_item_id = id
    salesFilters.item_label = code || label
  }
  salesFilters.group_by = 'none'
  reloadSales()
}

function clearItemFilter() {
  salesFilters.stock_item_id = null
  salesFilters.item_label = null
  reloadSales()
}

function amountText(amounts: StockSalesAmount[]): string[] {
  return amounts.map(a => formatMoney(Number(a.total_without_vat), a.currency))
}

/** Množství v českém formátu bez zbytečných nul (3,5 ne 3.500). */
function qtyText(qty: string): string {
  return formatNumber(Number(qty), { maximumFractionDigits: 3 })
}

const salesQty = computed(() => {
  const totals = sales.value?.totals
  if (!totals) return ''
  return totals.units.length === 1 ? `${qtyText(totals.qty)} ${totals.units[0]}` : t('stock.reports.sales.mixed_units')
})

const statusReport = ref<StockStatusReport | null>(null)
// Vývoj hodnoty: konce měsíců od data „od“ a samotné datum „do“ vedle sebe.
const trendFilters = reactive({ from: `${new Date().getFullYear() - 1}-12-31`, to: appIsoDate() })
const trendReport = ref<StockValueTrendReport | null>(null)
const trendGroup = ref<'' | number>('') // vlastní volba, aby se nepletla se seskupením ocenění
let loadSeq = 0 // novější načtení nesmí přepsat starší odpověď
const trendJob = ref<CatalogJob | null>(null)
const trendJobStarted = ref(false) // úloha založená touto stránkou; sdílenou (reused) při odchodu jen přestaneme sledovat
const trendJobOwn = computed(() => trendJob.value?.created_by != null && trendJob.value.created_by === auth.user?.id)
const trendCanCancel = computed(() => trendJobOwn.value || auth.canWrite('stock'))
const cancellingTrend = ref(false)
let trendTimer: ReturnType<typeof setTimeout> | undefined
let trendGeneration = 0
function clearTrendTimer() { if (trendTimer) clearTimeout(trendTimer) }
// Výpočet běží na pozadí (jako ocenění k datu): zadá se, sleduje se průběh a hotový výsledek se načte.
/** Rozpracovaný výpočet, který už nikdo nesleduje, se zruší (bez práva ke zrušení nebo u hotové úlohy chyba nevadí). */
async function abandonTrend() {
  clearTrendTimer()
  ++trendGeneration
  const job = trendJob.value
  if (job && trendJobStarted.value && ['queued', 'running'].includes(job.status)) await catalogJobsApi.cancelValueTrend(job.id).catch(() => {})
}
async function startTrend(seq: number) {
  await abandonTrend()
  const generation = trendGeneration
  trendReport.value = null
  trendJob.value = null
  const job = await catalogJobsApi.createValueTrend({
    from: trendFilters.from, to: trendFilters.to,
    warehouse_id: filters.warehouse_id || undefined,
    group_parent_id: trendGroup.value === '' ? undefined : trendGroup.value,
  })
  if (generation !== trendGeneration || seq !== loadSeq) return
  trendJob.value = job
  trendJobStarted.value = !job.reused
  await pollTrend(generation)
}
async function pollTrend(generation = trendGeneration) {
  if (!trendJob.value || generation !== trendGeneration) return
  try {
    const job = await catalogJobsApi.getValueTrendStatus(trendJob.value.id)
    if (generation !== trendGeneration) return
    trendJob.value = job
    if (job.status === 'completed') {
      const report = await catalogJobsApi.getValueTrend(job.id)
      if (generation === trendGeneration) trendReport.value = report
      return
    }
    if (job.status === 'queued' || job.status === 'running') trendTimer = setTimeout(() => { void pollTrend(generation) }, 1200)
  } catch (e: any) {
    if (generation !== trendGeneration) return
    if ([403, 404, 409].includes(e?.response?.status)) { toast.error(e?.response?.data?.error?.message || t('common.error')); return }
    trendTimer = setTimeout(() => { void pollTrend(generation) }, 5000)
  }
}
/** Sdílenou úlohu jiného uživatele nezrušíme, jen přestaneme sledovat. */
function stopWatchingTrend() {
  clearTrendTimer()
  ++trendGeneration
  trendJob.value = null
}
async function cancelTrend() {
  if (!trendJob.value) return
  cancellingTrend.value = true
  try {
    await catalogJobsApi.cancelValueTrend(trendJob.value.id)
    clearTrendTimer()
    await pollTrend()
  } catch { /* úloha mezitím doběhla nebo skončila: stav ukáže další dotaz */
    await pollTrend()
  } finally { cancellingTrend.value = false }
}
function trendGroupLabel(g: StockValueTrendReport['groups'][number]): string {
  const parent = trendReport.value?.parent
  if (g.kind === 'parent') return t('stock.reports.group_parent', { name: parent?.name ?? '' })
  if (g.kind === 'uncategorized') return parent ? t('stock.reports.group_outside', { name: parent.name }) : t('stock.reports.group_none')
  return g.name
}
const valuationReport = ref<StockValuationReport | null>(null)
const valuationJob = ref<CatalogJob | null>(null)
const valuationResult = ref<ValuationJobResult | null>(null)
const valuationPage = ref(1)
const cancellingValuation = ref(false)
let valuationTimer: ReturnType<typeof setTimeout> | undefined
let valuationGeneration = 0
// Seskupení ocenění: '' = žádné, 'top' = kategorie nejvyšší úrovně, číslo = přímé podkategorie.
const valuationGroup = ref<'' | 'top' | number>('')
function valuationGroupParams(): ValuationGroupParams {
  if (valuationGroup.value === '') return {}
  return valuationGroup.value === 'top' ? { group_by: 'category' } : { group_by: 'category', group_parent_id: valuationGroup.value }
}
const valuationGroups = computed(() => valuationResult.value?.category_groups ?? null)
function valuationGroupLabel(group: NonNullable<ValuationJobResult['category_groups']>['groups'][number]): string {
  const parent = valuationGroups.value?.parent
  if (group.kind === 'parent') return t('stock.reports.group_parent', { name: parent?.name ?? '' })
  if (group.kind === 'uncategorized') return parent ? t('stock.reports.group_outside', { name: parent.name }) : t('stock.reports.group_none')
  return group.name
}
/** Hotové ocenění se jen znovu načte se souhrnem, výpočet se neopakuje. */
function onValuationGroupChange() {
  if (valuationJob.value?.status === 'completed') void loadValuationPage(1)
}

async function load() {
  const seq = ++loadSeq
  loading.value = true
  try {
    if (tab.value === 'status') {
      statusReport.value = await stockApi.reportStatus(statusParams())
    } else if (tab.value === 'sales') {
      await loadSales()
    } else if (tab.value === 'trend') {
      await startTrend(seq)
    } else { await startValuation() }
  } catch (e: any) {
    if (seq !== loadSeq) return
    const code = e?.response?.data?.error?.code
    if (code === 'too_many_movements' || code === 'stock.error.too_many_movements') {
      toast.warning(t('stock.reports.too_many_movements'))
    } else {
      toast.error(e?.response?.data?.error?.message || t('common.error'))
    }
  } finally {
    if (seq === loadSeq) loading.value = false
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
    const result = await catalogJobsApi.getValuation(valuationJob.value.id, { page, limit: 50, ...valuationGroupParams() })
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

function selectTab(t2: ReportTab) { clearValuationTimer(); ++valuationGeneration; void abandonTrend(); tab.value = t2; if (t2 !== 'trend') void load() }

async function exportFile(format: 'pdf' | 'xlsx') {
  if (tab.value === 'sales') {
    window.open(stockApi.reportExportUrl('sales', 'xlsx', salesParams()), '_blank', 'noopener')
    return
  }
  if (tab.value === 'trend') {
    if (trendReport.value) window.open(stockApi.reportExportUrl('value-trend', format, { job_id: trendReport.value.job_id }), '_blank', 'noopener')
    return
  }
  const url = stockApi.reportExportUrl(tab.value, format, tab.value === 'status' ? statusParams() : {
    warehouse_id: filters.warehouse_id || undefined,
    date: filters.date,
    job_id: valuationResult.value?.job_id,
    ...valuationGroupParams(),
  })
  window.open(url, '_blank', 'noopener')
}

const statusRows = computed(() => statusReport.value?.items ?? [])
const valuationRows = computed(() => valuationResult.value?.items ?? valuationReport.value?.items ?? [])

// Změna viditelných sloupců mění obsah sestavy i exportu, proto stav zásob načte znovu.
watch(statusColumnsParam, () => { if (tab.value === 'status') void load() })

onMounted(async () => {
  try { warehouses.value = await stockApi.listWarehouses(true) } catch { warehouses.value = [] }
  // Kategorie a parametry čte jen uživatel s právem ke katalogu (jinak by šly zbytečné 403);
  // bez nich filtry kategorie, parametru a seskupení zůstanou skryté.
  if (auth.canRead('eshop')) {
    eshopApi.listCategories().then(list => { categories.value = list.filter(x => !x.archived) }).catch(() => { categories.value = [] })
    eshopApi.listAttributes().then(list => { attributes.value = list.filter(x => !x.archived) }).catch(() => { attributes.value = [] })
  }
  // Odkaz z detailu karty: /stock/reports?tab=sales&stock_item_id=…
  const itemId = Number(route.query.stock_item_id)
  if (route.query.tab === 'sales' && canSeeSales.value) {
    tab.value = 'sales'
    if (Number.isSafeInteger(itemId) && itemId > 0) {
      salesFilters.stock_item_id = itemId
      stockApi.getItem(itemId).then(item => { salesFilters.item_label = item.sku }).catch(() => {})
    }
  }
  // Odkaz ze seznamu úloh na pozadí: /stock/reports?tab=trend&trend_job=… (hotový výsledek se jen načte, nepřepočítává)
  const trendJobId = Number(route.query.trend_job)
  if (Number.isSafeInteger(trendJobId) && trendJobId > 0) {
    tab.value = 'trend'
    trendJobStarted.value = false
    try {
      trendJob.value = await catalogJobsApi.getValueTrendStatus(trendJobId)
      await pollTrend()
    } catch (e: any) { toast.error(e?.response?.data?.error?.message || t('common.error')) }
    return
  }
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
onBeforeUnmount(() => { clearValuationTimer(); ++valuationGeneration; void abandonTrend(); if (salesSearchTimer) clearTimeout(salesSearchTimer) })
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
      <button type="button" @click="selectTab('trend')" data-test="tab-trend"
        class="cursor-pointer px-3 py-2 text-sm border-b-2 transition whitespace-nowrap"
        :class="tab === 'trend' ? 'border-primary-600 text-primary-700 font-medium' : 'border-transparent text-neutral-600 hover:text-neutral-900'">
        {{ t('stock.reports.tab_trend') }}
      </button>
      <button v-if="canSeeSales" type="button" @click="selectTab('sales')"
        class="cursor-pointer px-3 py-2 text-sm border-b-2 transition whitespace-nowrap"
        :class="tab === 'sales' ? 'border-primary-600 text-primary-700 font-medium' : 'border-transparent text-neutral-600 hover:text-neutral-900'">
        {{ t('stock.reports.tab_sales') }}
      </button>
    </div>

    <!-- Filtry -->
    <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-3 mb-4">
      <div class="flex flex-wrap items-end gap-3">
        <div>
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('stock.reports.filter_warehouse') }}</label>
          <select v-model="filters.warehouse_id" @change="onWarehouseChange" class="h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface min-w-[10rem]">
            <option value="">{{ t('common.all') }}</option>
            <option v-for="w in warehouses" :key="w.id" :value="w.id">{{ w.name }}</option>
          </select>
        </div>
        <div v-if="tab === 'valuation'">
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('stock.reports.filter_date') }}</label>
          <DateInput v-model="filters.date" @change="load" class="h-9 px-2 border border-neutral-300 rounded-md text-sm" />
        </div>
        <template v-if="tab === 'trend'">
          <div>
            <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('stock.reports.trend_from') }}</label>
            <DateInput v-model="trendFilters.from" class="h-9 px-2 border border-neutral-300 rounded-md text-sm" />
          </div>
          <div>
            <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('stock.reports.trend_to') }}</label>
            <DateInput v-model="trendFilters.to" class="h-9 px-2 border border-neutral-300 rounded-md text-sm" />
          </div>
          <div v-if="categories.length">
            <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('stock.reports.trend_level') }}</label>
            <select v-model="trendGroup" data-test="trend-group" class="h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface min-w-[12rem]">
              <option value="">{{ t('stock.reports.group_top') }}</option>
              <option v-for="c in categories" :key="c.id" :value="c.id">{{ indentedCategoryLabel(c) }}</option>
            </select>
          </div>
        </template>
        <button v-if="tab === 'trend'" type="button" @click="load" :disabled="loading" data-test="trend-run" :class="btnFilled('primary')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.chart" /></svg>
          {{ t('stock.reports.trend_run') }}
        </button>
        <div v-if="tab === 'valuation' && categories.length">
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('stock.reports.filter_group') }}</label>
          <select v-model="valuationGroup" data-test="valuation-group" @change="onValuationGroupChange" class="h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface min-w-[12rem]">
            <option value="">{{ t('stock.reports.group_off') }}</option>
            <option value="top">{{ t('stock.reports.group_top') }}</option>
            <option v-for="c in categories" :key="c.id" :value="c.id">{{ indentedCategoryLabel(c) }}</option>
          </select>
        </div>
        <template v-if="tab === 'status'">
          <div v-if="categories.length">
            <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('stock.reports.filter_category') }}</label>
            <select v-model="statusFilters.category_id" data-test="status-category" @change="load" class="h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface min-w-[10rem]">
              <option value="">{{ t('common.all') }}</option>
              <option v-for="c in categories" :key="c.id" :value="c.id">{{ indentedCategoryLabel(c) }}</option>
            </select>
          </div>
          <div v-if="attributes.length">
            <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('stock.reports.filter_attribute') }}</label>
            <select v-model="statusFilters.attribute_id" data-test="status-attribute" @change="load" class="h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface min-w-[10rem]">
              <option value="">{{ t('stock.reports.no_attribute') }}</option>
              <option v-for="a in attributes" :key="a.id" :value="a.id">{{ a.name }}</option>
            </select>
          </div>
          <ColumnPicker :ctrl="statusTbl" />
        </template>
        <template v-if="tab === 'sales'">
          <div>
            <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('stock.reports.sales.filter_from') }}</label>
            <DateInput v-model="salesFilters.date_from" @change="reloadSales()" class="h-9 px-2 border border-neutral-300 rounded-md text-sm" />
          </div>
          <div>
            <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('stock.reports.sales.filter_to') }}</label>
            <DateInput v-model="salesFilters.date_to" @change="reloadSales()" class="h-9 px-2 border border-neutral-300 rounded-md text-sm" />
          </div>
          <div class="min-w-[14rem]">
            <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('stock.reports.sales.filter_client') }}</label>
            <ClientSearchSelect :model-value="salesFilters.client_id" :selected-label="salesFilters.client_label"
              :placeholder="t('stock.reports.sales.all_clients')"
              @update:model-value="(v: number | null) => { salesFilters.client_id = v; reloadSales() }"
              @selected="(client) => { salesFilters.client_label = client?.company_name ?? null }" />
          </div>
          <div>
            <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('stock.reports.sales.filter_category') }}</label>
            <select v-model="salesFilters.category_id" @change="reloadSales()" class="h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface min-w-[10rem]">
              <option value="">{{ t('common.all') }}</option>
              <option v-for="c in categories" :key="c.id" :value="c.id">{{ indentedCategoryLabel(c) }}</option>
            </select>
          </div>
          <div>
            <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('stock.reports.sales.filter_q') }}</label>
            <input v-model="salesFilters.q" type="search" @input="onSalesSearch" :placeholder="t('stock.reports.sales.filter_q_placeholder')"
              class="h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface w-56" />
          </div>
        </template>
        <div class="flex flex-wrap gap-2 ml-auto">
          <button v-if="tab === 'sales'" :disabled="loading || !sales || sales.totals.lines === 0" @click="exportFile('xlsx')" :class="btnOutline('primary')">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.download" /></svg>
            {{ t('stock.reports.export_xlsx') }}
          </button>
          <template v-else>
          <button :disabled="loading || (tab === 'valuation' && !valuationResult) || (tab === 'trend' && !trendReport)" @click="exportFile('pdf')" :class="btnOutline('primary')">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.download" /></svg>
            {{ t('stock.reports.export_pdf') }}
          </button>
          <button :disabled="loading || (tab === 'valuation' && !valuationResult) || (tab === 'trend' && !trendReport)" @click="exportFile('xlsx')" :class="btnOutline('primary')">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.download" /></svg>
            {{ t('stock.reports.export_xlsx') }}
          </button>
          </template>
        </div>
      </div>
      <!-- Aktivní filtr karty (z detailu karty nebo prokliku ze souhrnu) + seskupení. -->
      <div v-if="tab === 'sales'" class="flex flex-wrap items-center gap-2 mt-3">
        <span class="text-xs font-medium text-neutral-500">{{ t('stock.reports.sales.group_by') }}</span>
        <div class="flex w-full sm:inline-flex sm:w-auto rounded-md border border-neutral-300 overflow-hidden" role="group">
          <button v-for="g in (['none', 'client', 'item'] as const)" :key="g" type="button" @click="setGroupBy(g)"
            class="cursor-pointer flex-1 sm:flex-none px-2 sm:px-3 h-8 text-xs sm:text-sm whitespace-nowrap border-l first:border-l-0 border-neutral-300"
            :class="salesFilters.group_by === g ? 'bg-primary-50 text-primary-700 font-medium' : 'bg-surface text-neutral-600 hover:bg-neutral-50'"
            :aria-pressed="salesFilters.group_by === g">
            {{ t(`stock.reports.sales.group_${g}`) }}
          </button>
        </div>
        <button v-if="salesFilters.stock_item_id" type="button" @click="clearItemFilter"
          class="cursor-pointer inline-flex items-center gap-1 h-8 px-2 rounded-md bg-neutral-100 text-xs text-neutral-700 hover:bg-neutral-200 whitespace-nowrap">
          {{ t('stock.reports.sales.item_filter', { sku: salesFilters.item_label ?? `#${salesFilters.stock_item_id}` }) }}
          <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>
        </button>
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
                <th v-if="statusReport?.attribute" class="px-3 py-2 text-left font-medium">{{ statusAttributeLabel }}</th>
                <th class="px-3 py-2 text-left font-medium">{{ t('stock.reports.col_warehouse') }}</th>
                <th class="px-3 py-2 text-right font-medium">{{ t('stock.reports.col_qty') }}</th>
                <th v-if="statusTbl.isVisible('avg_cost')" class="px-3 py-2 text-right font-medium">{{ t('stock.reports.col_avg_cost') }}</th>
                <th v-if="statusTbl.isVisible('value')" class="px-3 py-2 text-right font-medium">{{ t('stock.reports.col_value') }}</th>
                <th v-if="statusTbl.isVisible('sale_price')" class="px-3 py-2 text-right font-medium">{{ t('stock.reports.col_sale_price') }}</th>
                <th v-if="statusTbl.isVisible('note')" class="px-3 py-2 text-left font-medium">{{ t('stock.reports.col_note') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="r in statusRows" :key="`${r.warehouse_id}-${r.stock_item_id}`" class="hover:bg-neutral-50" :class="{ 'bg-danger-50/40': r.min_qty != null && Number(r.qty) < Number(r.min_qty) }">
                <td class="px-3 py-2 font-mono text-xs">{{ r.sku }}</td>
                <td class="px-3 py-2">{{ r.name }}</td>
                <td v-if="statusReport?.attribute" class="px-3 py-2">{{ statusAttributeText(r.attribute_value) }}</td>
                <td class="px-3 py-2">{{ r.warehouse_name }}</td>
                <td class="px-3 py-2 text-right font-mono">{{ r.qty }}</td>
                <td v-if="statusTbl.isVisible('avg_cost')" class="px-3 py-2 text-right font-mono">{{ formatMoney(Number(r.avg_unit_cost)) }}</td>
                <td v-if="statusTbl.isVisible('value')" class="px-3 py-2 text-right font-mono">{{ formatMoney(Number(r.value_total)) }}</td>
                <td v-if="statusTbl.isVisible('sale_price')" class="px-3 py-2 text-right font-mono">{{ r.sale_price != null ? formatMoney(Number(r.sale_price)) : '' }}</td>
                <td v-if="statusTbl.isVisible('note')" class="px-3 py-2 text-neutral-600 whitespace-pre-line">{{ r.note ?? '' }}</td>
              </tr>
            </tbody>
            <tfoot v-if="statusReport">
              <tr class="border-t-2 border-neutral-300 font-semibold bg-neutral-50">
                <template v-if="statusTbl.isVisible('value')">
                  <td class="px-3 py-2" :colspan="statusLeadColumns">{{ t('stock.reports.totals') }} ({{ statusReport.totals.count }})</td>
                  <td class="px-3 py-2 text-right font-mono">{{ formatMoney(Number(statusReport.totals.value_total)) }}</td>
                  <td v-if="statusTrailColumns" :colspan="statusTrailColumns"></td>
                </template>
                <td v-else class="px-3 py-2" :colspan="statusLeadColumns + statusTrailColumns">{{ t('stock.reports.totals') }} ({{ statusReport.totals.count }})</td>
              </tr>
            </tfoot>
          </table>
        </div>
      </div>
    </template>

    <!-- Prodeje skladových karet -->
    <template v-else-if="tab === 'sales'">
      <template v-if="sales">
        <!-- Souhrn za celý filtr (ne jen stránku): řádky, množství, tržba po měnách. -->
        <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm mb-4 grid grid-cols-1 sm:grid-cols-3 divide-y sm:divide-y-0 sm:divide-x divide-neutral-200">
          <div class="px-4 py-3">
            <div class="text-xs font-medium text-neutral-500">{{ t('stock.reports.sales.summary_lines') }}</div>
            <div class="font-mono text-lg">{{ sales.totals.lines }}</div>
          </div>
          <div class="px-4 py-3">
            <div class="text-xs font-medium text-neutral-500">{{ t('stock.reports.sales.summary_qty') }}</div>
            <div class="font-mono text-lg">{{ salesQty }}</div>
          </div>
          <div class="px-4 py-3">
            <div class="text-xs font-medium text-neutral-500">{{ t('stock.reports.sales.summary_amount') }}</div>
            <div v-for="line in amountText(sales.totals.amounts)" :key="line" class="font-mono text-lg">{{ line }}</div>
            <div v-if="sales.totals.amounts.length === 0" class="font-mono text-lg">—</div>
          </div>
        </div>

        <EmptyState v-if="sales.totals.lines === 0" boxed accent="neutral" icon="coin"
          :title="t('stock.reports.sales.empty_title')" :message="t('stock.reports.sales.empty_hint')" />

        <!-- Souhrn po odběratelích / kartách -->
        <div v-else-if="salesFilters.group_by !== 'none'" class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
          <div class="overflow-x-auto">
            <table class="min-w-[40rem] w-full text-sm">
              <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
                <tr>
                  <th class="px-3 py-2 text-left font-medium">{{ t(salesFilters.group_by === 'client' ? 'stock.reports.sales.col_client' : 'stock.reports.sales.col_item') }}</th>
                  <th class="px-3 py-2 text-right font-medium whitespace-nowrap">{{ t('stock.reports.sales.col_documents') }}</th>
                  <th class="px-3 py-2 text-right font-medium whitespace-nowrap">{{ t('stock.reports.sales.col_lines') }}</th>
                  <th class="px-3 py-2 text-right font-medium whitespace-nowrap">{{ t('stock.reports.col_qty') }}</th>
                  <th class="px-3 py-2 text-right font-medium whitespace-nowrap">{{ t('stock.reports.sales.col_total') }}</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-neutral-100">
                <tr v-for="g in sales.groups" :key="`${g.id}`" class="hover:bg-neutral-50" :class="g.id !== null ? 'cursor-pointer' : ''"
                  :title="g.id !== null ? t('stock.reports.sales.drill_down') : undefined" :tabindex="g.id !== null ? 0 : undefined" @click="drillDown(g.id, g.label, g.code)" @keydown.enter="drillDown(g.id, g.label, g.code)">
                  <td class="px-3 py-2">
                    <span v-if="salesFilters.group_by === 'item'" class="font-mono text-xs text-neutral-500 mr-2">{{ g.code }}</span>
                    <span>{{ g.label || t('stock.reports.sales.no_client') }}</span>
                  </td>
                  <td class="px-3 py-2 text-right font-mono">{{ g.documents }}</td>
                  <td class="px-3 py-2 text-right font-mono">{{ g.lines }}</td>
                  <td class="px-3 py-2 text-right font-mono whitespace-nowrap">{{ qtyText(g.qty) }}</td>
                  <td class="px-3 py-2 text-right font-mono whitespace-nowrap">
                    <div v-for="line in amountText(g.amounts)" :key="line">{{ line }}</div>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <!-- Řádky: 1 řádek faktury = 1 řádek, proklik na doklad, odběratele i kartu -->
        <template v-else>
          <div class="hidden md:block bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
            <div class="overflow-x-auto">
              <table class="min-w-[56rem] w-full text-sm">
                <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
                  <tr>
                    <th class="px-3 py-2 text-left font-medium whitespace-nowrap">{{ t('stock.reports.sales.col_date') }}</th>
                    <th class="px-3 py-2 text-left font-medium">{{ t('stock.reports.sales.col_document') }}</th>
                    <th class="px-3 py-2 text-left font-medium">{{ t('stock.reports.sales.col_client') }}</th>
                    <th class="px-3 py-2 text-left font-medium">{{ t('stock.reports.sales.col_item') }}</th>
                    <th class="px-3 py-2 text-right font-medium whitespace-nowrap">{{ t('stock.reports.col_qty') }}</th>
                    <th class="px-3 py-2 text-right font-medium whitespace-nowrap">{{ t('stock.reports.sales.col_unit_price') }}</th>
                    <th class="px-3 py-2 text-right font-medium whitespace-nowrap">{{ t('stock.reports.sales.col_total') }}</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-neutral-100">
                  <tr v-for="r in sales.items" :key="r.invoice_item_id" class="hover:bg-neutral-50 align-top">
                    <td class="px-3 py-2 whitespace-nowrap">{{ formatDate(r.tax_date) }}</td>
                    <td class="px-3 py-2 whitespace-nowrap">
                      <RouterLink :to="`/invoices/${r.invoice_id}`" class="font-mono text-xs text-primary-600 hover:text-primary-700">{{ r.invoice_number || `#${r.invoice_id}` }}</RouterLink>
                      <span v-if="r.invoice_type === 'credit_note'" class="ml-1 text-xs px-1.5 py-0.5 rounded bg-warning-50 text-warning-700">{{ t('stock.item_detail.credit_note') }}</span>
                    </td>
                    <td class="px-3 py-2">
                      <RouterLink v-if="r.client_id" :to="`/clients/${r.client_id}`" class="hover:text-primary-700">{{ r.client_name || `#${r.client_id}` }}</RouterLink>
                      <span v-else class="text-neutral-400">{{ t('stock.reports.sales.no_client') }}</span>
                    </td>
                    <td class="px-3 py-2">
                      <RouterLink :to="`/stock/items/${r.stock_item_id}`" class="font-mono text-xs text-primary-600 hover:text-primary-700 mr-1">{{ r.sku }}</RouterLink>
                      <span>{{ r.name }}</span>
                      <div v-if="r.identifiers.length" class="mt-0.5 font-mono text-xs text-neutral-600 break-all">{{ r.identifiers.join(', ') }}</div>
                    </td>
                    <td class="px-3 py-2 text-right font-mono whitespace-nowrap" :class="Number(r.qty) < 0 ? 'text-danger-500' : ''">{{ qtyText(r.qty) }} <span class="text-xs text-neutral-400">{{ r.unit }}</span></td>
                    <td class="px-3 py-2 text-right font-mono whitespace-nowrap">{{ formatMoney(Number(r.unit_price), r.currency) }}</td>
                    <td class="px-3 py-2 text-right font-mono whitespace-nowrap" :class="Number(r.total_without_vat) < 0 ? 'text-danger-500' : ''">
                      {{ formatMoney(Number(r.total_without_vat), r.currency) }}
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
          <!-- Mobil: karty místo tabulky -->
          <div class="md:hidden space-y-2">
            <div v-for="r in sales.items" :key="`m-${r.invoice_item_id}`" class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-3">
              <div class="flex items-center justify-between gap-2 text-xs">
                <span class="flex items-center gap-1">
                  <RouterLink :to="`/invoices/${r.invoice_id}`" class="font-mono text-primary-600">{{ r.invoice_number || `#${r.invoice_id}` }}</RouterLink>
                  <span v-if="r.invoice_type === 'credit_note'" class="px-1.5 py-0.5 rounded bg-warning-50 text-warning-700">{{ t('stock.item_detail.credit_note') }}</span>
                </span>
                <span class="text-neutral-500">{{ formatDate(r.tax_date) }}</span>
              </div>
              <div class="font-medium mt-1">{{ r.name }}</div>
              <div v-if="r.identifiers.length" class="font-mono text-xs text-neutral-600 break-all">{{ r.identifiers.join(', ') }}</div>
              <div class="text-sm text-neutral-600 mt-0.5">{{ r.client_name || t('stock.reports.sales.no_client') }}</div>
              <div class="flex items-center justify-between mt-1.5 text-sm font-mono" :class="Number(r.qty) < 0 ? 'text-danger-500' : ''">
                <span>{{ qtyText(r.qty) }} {{ r.unit }}</span>
                <span>{{ formatMoney(Number(r.total_without_vat), r.currency) }}</span>
              </div>
            </div>
          </div>
          <PaginationBar v-if="sales.pagination.pages > 1" class="mt-3" :page="salesFilters.page" :per-page="sales.pagination.per_page" :total="sales.pagination.total"
            @update:page="(p: number) => { salesFilters.page = p; reloadSales(false) }" />
        </template>
      </template>
    </template>

    <!-- Vývoj hodnoty -->
    <template v-else-if="tab === 'trend'">
      <p v-if="!trendJob && !trendReport && !loading" class="text-sm text-neutral-500 mb-3" data-test="trend-hint">{{ t('stock.reports.trend_hint') }}</p>
      <CatalogJobProgress v-if="trendJob && ['queued', 'running'].includes(trendJob.status)" class="mb-2" :job="trendJob" :cancelling="cancellingTrend" :can-cancel="trendCanCancel" @cancel="cancelTrend" />
      <div v-if="trendJob && !trendJobOwn && ['queued', 'running'].includes(trendJob.status)" class="mb-4 flex items-center gap-3 text-sm text-neutral-600" data-test="trend-shared">
        <span>{{ t('stock.reports.trend_shared') }}</span>
        <button type="button" :class="btnOutline('neutral')" data-test="trend-stop-watching" @click="stopWatchingTrend">{{ t('stock.reports.trend_stop_watching') }}</button>
      </div>
      <p v-if="trendJob && ['queued', 'running'].includes(trendJob.status) && Number((trendJob.report as any)?.restarts) > 0" class="text-sm text-neutral-600 mb-3" data-test="trend-restart">{{ t('stock.reports.trend_restart') }}</p>
      <p v-if="trendJob?.status === 'cancelled'" class="text-sm text-neutral-600 mb-3" data-test="trend-cancelled">{{ t('stock.reports.trend_cancelled') }}</p>
      <p v-if="trendJob?.status === 'failed'" class="text-sm text-danger-600 mb-3" data-test="trend-failed">{{ trendJob.error_code === 'stock_value_trend_stale' ? t('stock.reports.trend_stale') : t('stock.reports.trend_failed') }}</p>
      <p v-if="trendReport?.computed_at" class="text-xs text-neutral-500 mb-2" data-test="trend-computed">{{ t('stock.reports.trend_computed', { at: formatDateTime(trendReport.computed_at) }) }}</p>
      <div v-if="trendReport" class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
          <table class="w-full text-sm table-sticky-first" data-test="trend-table">
            <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
              <tr>
                <th class="px-3 py-2 text-left font-medium">{{ t('stock.reports.col_category') }}</th>
                <th v-for="d in trendReport.dates" :key="d" class="px-3 py-2 text-right font-medium whitespace-nowrap">{{ formatDate(d) }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="g in trendReport.groups" :key="`${g.kind}-${g.category_id ?? ''}`">
                <td class="trend-sticky px-3 py-2" :class="g.kind === 'category' ? '' : 'text-neutral-600'">{{ trendGroupLabel(g) }}</td>
                <td v-for="d in trendReport.dates" :key="d" class="px-3 py-2 text-right font-mono whitespace-nowrap">{{ formatMoney(Number(g.values[d])) }}</td>
              </tr>
            </tbody>
            <tfoot class="bg-neutral-50 font-medium">
              <tr>
                <td class="sticky left-0 z-[1] bg-neutral-50 px-3 py-2">{{ t('stock.reports.total') }}</td>
                <td v-for="d in trendReport.dates" :key="d" class="px-3 py-2 text-right font-mono whitespace-nowrap">{{ formatMoney(Number(trendReport.totals[d].value_total)) }}</td>
              </tr>
            </tfoot>
          </table>
        </div>
      </div>
      <p v-if="trendReport?.groups.length" class="text-xs text-neutral-500 mt-3">{{ t('stock.reports.group_note') }}</p>
    </template>

    <!-- Ocenění -->
    <template v-else>
      <CatalogJobProgress v-if="valuationJob && ['queued', 'running'].includes(valuationJob.status)" class="mb-4" :job="valuationJob" :cancelling="cancellingValuation" :can-cancel="auth.canWrite('stock')" @cancel="cancelValuation" />
      <p v-if="valuationJob?.status === 'failed' || valuationJob?.status === 'cancelled'" class="text-sm text-danger-600 mb-3">{{ t('stock.reports.valuation_failed') }}</p>
      <!-- Souhrn podle kategorií za celé ocenění (ne jen stránku); součet = celkové ocenění. -->
      <div v-if="valuationGroups" class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden mb-4">
        <div class="overflow-x-auto">
          <table class="min-w-[28rem] w-full text-sm">
            <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
              <tr>
                <th class="px-3 py-2 text-left font-medium">{{ valuationGroups.parent ? `${t('stock.reports.col_category')}: ${valuationGroups.parent.name}` : t('stock.reports.col_category') }}</th>
                <th class="px-3 py-2 text-right font-medium whitespace-nowrap">{{ t('stock.reports.col_rows') }}</th>
                <th class="px-3 py-2 text-right font-medium whitespace-nowrap">{{ t('stock.reports.col_value') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="g in valuationGroups.groups" :key="`${g.kind}-${g.category_id ?? ''}`">
                <td class="px-3 py-2" :class="g.kind === 'category' ? '' : 'text-neutral-600'">{{ valuationGroupLabel(g) }}</td>
                <td class="px-3 py-2 text-right font-mono">{{ g.count }}</td>
                <td class="px-3 py-2 text-right font-mono whitespace-nowrap">{{ formatMoney(Number(g.value_total)) }}</td>
              </tr>
            </tbody>
            <tfoot>
              <tr class="border-t-2 border-neutral-300 font-semibold bg-neutral-50">
                <td class="px-3 py-2">{{ t('stock.reports.totals') }}</td>
                <td class="px-3 py-2 text-right font-mono">{{ valuationGroups.count }}</td>
                <td class="px-3 py-2 text-right font-mono whitespace-nowrap">{{ formatMoney(Number(valuationGroups.value_total)) }}</td>
              </tr>
            </tfoot>
          </table>
        </div>
        <p class="px-3 py-2 text-xs text-neutral-500 border-t border-neutral-100">{{ t('stock.reports.group_note') }}</p>
      </div><EmptyState v-if="valuationJob?.status === 'completed' && valuationRows.length === 0" boxed accent="neutral" icon="coin"
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

<style scoped>
/* Zebra a hover řádku jsou poloprůhledné; zafixovaný první sloupec je proto kreslí přes neprůhlednou plochu,
   jinak by pod ním při posunu doprava prosvítaly částky. */
.trend-sticky {
  --trend-tint: transparent;
  background: linear-gradient(var(--trend-tint), var(--trend-tint)), var(--color-surface);
}
tr:nth-child(even) > .trend-sticky { --trend-tint: color-mix(in oklab, var(--color-neutral-500) 4%, transparent); }
tr:hover > .trend-sticky { --trend-tint: color-mix(in oklab, var(--color-primary-500) 7%, transparent); }
</style>

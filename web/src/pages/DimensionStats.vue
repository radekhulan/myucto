<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { Chart, BarController, BarElement, CategoryScale, LinearScale, LineController, LineElement, PointElement, Tooltip, Legend } from 'chart.js'
import { dimensionsApi, type DimensionAnalyticsAmounts, type DimensionAnalyticsReport, type DimensionProfitAmounts } from '@/api/dimensions'
import { useDimensions } from '@/composables/useDimensions'
import { useToast } from '@/composables/useToast'
import { useSupplierStore } from '@/stores/supplier'
import { useChartColors } from '@/composables/useTheme'
import { formatMoney } from '@/composables/useFormat'
import { ICONS, btnFilled, btnOutline } from '@/components/ui/buttonStyles'
import EmptyState from '@/components/ui/EmptyState.vue'

Chart.register(BarController, BarElement, CategoryScale, LinearScale, LineController, LineElement, PointElement, Tooltip, Legend)

const { t, locale } = useI18n()
const route = useRoute()
const router = useRouter()
const dims = useDimensions()
const toast = useToast()
const supplier = useSupplierStore()
const colors = useChartColors()
const year = ref(Number(route.query.year) || new Date().getFullYear())
const typeId = ref(Number(route.query.type_id) || 0)
const company = ref(String(route.query.supplier_id || 'current'))
const metric = ref<'revenue' | 'cost' | 'result'>('result')
const trendValue = ref('total')
const report = ref<DimensionAnalyticsReport | null>(null)
const loading = ref(false)
const failed = ref(false)
const exporting = ref(false)
const trendCanvas = ref<HTMLCanvasElement | null>(null)
const comparisonCanvas = ref<HTMLCanvasElement | null>(null)
const cumulativeCanvas = ref<HTMLCanvasElement | null>(null)
let trendChart: Chart | null = null
let comparisonChart: Chart<'bar', number[], string> | null = null
let cumulativeChart: Chart<'line', (number | null)[], string> | null = null
let requestId = 0

const types = computed(() => dims.types.value.filter(type => type.is_active))
const selectedType = computed(() => types.value.find(type => type.id === typeId.value))
const noValues = computed(() => dims.overview.value !== null && types.value.length > 0
  && !dims.values.value.some(value => types.value.some(type => type.id === value.type_id)))
const currentName = computed(() => supplier.currentSupplier?.company_name || t('dimensions.analytics_current'))
const availableCompanies = computed(() => (report.value?.available_companies ?? []).filter(item => item.id !== supplier.currentSupplierId))
const money = (amount: number) => formatMoney(amount, 'CZK')
const zeroAmounts: DimensionAnalyticsAmounts = { revenue: 0, cost: 0, result: 0, tax_deductible_cost: 0, non_deductible_cost: 0, income_tax_cost: 0 }
const resultClass = (amount: number) => amount > 0 ? 'text-success-600' : amount < 0 ? 'text-danger-600' : ''
const margin = (amounts: DimensionProfitAmounts) => amounts.revenue === 0 ? null : amounts.result / amounts.revenue * 100
const pct = (value: number | null) => value === null ? '-' : `${new Intl.NumberFormat(locale.value, { maximumFractionDigits: 1 }).format(value)} %`

const comparisons = computed(() => {
  if (!report.value) return []
  const rows = report.value.rows.filter(row => row.depth === 0).map(row => ({
    key: String(row.value_id), label: row.code === row.name ? row.name : `${row.code} ${row.name}`, amounts: report.value!.value_totals[String(row.value_id)] ?? zeroAmounts,
  }))
  rows.push({ key: '', label: t('dimensions.profit_unassigned'), amounts: report.value.unassigned })
  return rows
    .filter(row => row.amounts.revenue !== 0 || row.amounts.cost !== 0 || row.amounts.result !== 0)
    .sort((a, b) => Math.abs(b.amounts[metric.value]) - Math.abs(a.amounts[metric.value]))
})
const selectedComparison = computed(() => trendValue.value === 'total' ? null : comparisons.value.find(row => row.key === trendValue.value) ?? null)
const selectedLabel = computed(() => selectedComparison.value?.label ?? t('dimensions.profit_total'))
const shownAmounts = computed(() => selectedComparison.value?.amounts ?? report.value?.totals ?? zeroAmounts)
const hasTaxDifference = computed(() => shownAmounts.value.non_deductible_cost !== 0 || shownAmounts.value.income_tax_cost !== 0)
const anyTaxDeductible = computed(() => (report.value?.totals.tax_deductible_cost ?? 0) !== 0)
const anyNonDeductible = computed(() => (report.value?.totals.non_deductible_cost ?? 0) !== 0)
const anyIncomeTax = computed(() => (report.value?.totals.income_tax_cost ?? 0) !== 0)
const shownCompanies = computed(() => (report.value?.companies ?? []).map(item => ({
  ...item,
  ...(trendValue.value === 'total' ? {} : report.value?.company_value_totals[String(item.id)]?.[trendValue.value] ?? zeroAmounts),
})).filter(item => item.revenue !== 0 || item.cost !== 0 || item.result !== 0))
const assignedShare = computed(() => {
  const data = report.value
  if (!data) return null
  const assigned = data.rows.filter(row => row.depth === 0).reduce((sum, row) => sum + Math.abs(row.total.revenue) + Math.abs(row.total.cost), 0)
  const missing = Math.abs(data.unassigned.revenue) + Math.abs(data.unassigned.cost)
  return assigned + missing ? assigned / (assigned + missing) * 100 : null
})
const selectedMonths = computed(() => trendValue.value === 'total'
  ? report.value?.monthly ?? []
  : report.value?.value_monthly[trendValue.value] ?? report.value?.monthly.map(month => ({ month: month.month, ...zeroAmounts })) ?? [])
const shownMonths = computed(() => selectedMonths.value.filter(month => month.revenue !== 0 || month.cost !== 0 || month.result !== 0))
const previousTotal = computed(() => (report.value?.previous_monthly ?? []).reduce((sum, month) => sum + month[metric.value], 0))
const yearChange = computed(() => {
  if (!report.value || trendValue.value !== 'total' || previousTotal.value === 0) return null
  return (report.value.totals[metric.value] - previousTotal.value) / Math.abs(previousTotal.value) * 100
})

function drawCharts() {
  trendChart?.destroy()
  comparisonChart?.destroy()
  cumulativeChart?.destroy()
  trendChart = null
  comparisonChart = null
  cumulativeChart = null
  if (!report.value || !trendCanvas.value || !comparisonCanvas.value) return
  const c = colors.value
  const months = selectedMonths.value
  const previous = report.value.previous_monthly
  trendChart = new Chart(trendCanvas.value, {
    type: 'bar',
    data: {
      labels: months.map(month => month.month.slice(5)),
      datasets: [
        { label: t('dimensions.analytics_revenue'), data: months.map(month => month.revenue), backgroundColor: c.primary, borderRadius: 3 },
        { label: t('dimensions.analytics_cost'), data: months.map(month => month.cost), backgroundColor: c.warning, borderRadius: 3 },
        { label: t('dimensions.analytics_result'), type: 'line', data: months.map(month => month.result), borderColor: c.success, backgroundColor: c.success, pointBackgroundColor: months.map(month => month.result < 0 ? c.danger : c.success), segment: { borderColor: context => (context.p0.parsed.y ?? 0) < 0 || (context.p1.parsed.y ?? 0) < 0 ? c.danger : c.success }, tension: 0.25, pointRadius: 3, borderWidth: 2.5 },
        ...(trendValue.value === 'total' ? [{ label: `${t('dimensions.analytics_previous')} ${year.value - 1}`, type: 'line' as const, data: previous.map(month => month.result), borderColor: c.neutral, backgroundColor: c.neutral, borderDash: [5, 4], tension: 0.25, pointRadius: 2 }] : []),
      ],
    },
    options: {
      responsive: true, maintainAspectRatio: false,
      interaction: { mode: 'index', intersect: false },
      plugins: { legend: { position: 'bottom', labels: { color: c.tick } }, tooltip: { backgroundColor: c.tooltipBg, callbacks: { label: context => `${context.dataset.label}: ${money(context.parsed.y ?? 0)}` } } },
      scales: { x: { ticks: { color: c.tick }, grid: { display: false } }, y: { ticks: { color: c.tick }, grid: { color: c.grid } } },
    },
  })
  const top = comparisons.value.slice(0, 12)
  comparisonChart = new Chart(comparisonCanvas.value, {
    type: 'bar',
    data: { labels: top.map(row => row.label), datasets: [{ label: t(`dimensions.analytics_${metric.value}`), data: top.map(row => row.amounts[metric.value]), backgroundColor: top.map(row => metric.value === 'result' ? row.amounts.result < 0 ? c.danger : c.success : row.key === trendValue.value ? c.primarySoft : c.primary), borderColor: top.map(row => row.key === trendValue.value ? c.primary : 'transparent'), borderWidth: 2, borderRadius: 3 }] },
    options: {
      indexAxis: 'y', responsive: true, maintainAspectRatio: false,
      plugins: { legend: { display: false }, tooltip: { backgroundColor: c.tooltipBg, callbacks: { label: context => money(context.parsed.x ?? 0) } } },
      scales: { x: { ticks: { color: c.tick }, grid: { color: c.grid } }, y: { ticks: { color: c.tick }, grid: { display: false } } },
    },
  })
  if (trendValue.value === 'total' && cumulativeCanvas.value) {
    let currentSum = 0
    let previousSum = 0
    const currentCumulative = months.map((month, index) => {
      currentSum += month.result
      return year.value === new Date().getFullYear() && index > new Date().getMonth() ? null : currentSum
    })
    const previousCumulative = previous.map(month => { previousSum += month.result; return previousSum })
    cumulativeChart = new Chart(cumulativeCanvas.value, {
      type: 'line',
      data: { labels: months.map(month => month.month.slice(5)), datasets: [
        { label: String(year.value), data: currentCumulative, borderColor: c.success, backgroundColor: c.success, pointBackgroundColor: currentCumulative.map(value => value !== null && value < 0 ? c.danger : c.success), segment: { borderColor: context => (context.p0.parsed.y ?? 0) < 0 || (context.p1.parsed.y ?? 0) < 0 ? c.danger : c.success }, tension: 0.25, pointRadius: 3 },
        { label: String(year.value - 1), data: previousCumulative, borderColor: c.neutral, backgroundColor: c.neutral, borderDash: [5, 4], tension: 0.25, pointRadius: 2 },
      ] },
      options: {
        responsive: true, maintainAspectRatio: false, interaction: { mode: 'index', intersect: false },
        plugins: { legend: { position: 'bottom', labels: { color: c.tick } }, tooltip: { backgroundColor: c.tooltipBg, callbacks: { label: context => `${context.dataset.label}: ${money(context.parsed.y ?? 0)}` } } },
        scales: { x: { ticks: { color: c.tick }, grid: { display: false } }, y: { ticks: { color: c.tick }, grid: { color: c.grid } } },
      },
    })
  }
}

async function load() {
  if (!typeId.value || year.value < 2000 || year.value > 2100) return
  const id = ++requestId
  loading.value = true
  failed.value = false
  try {
    const selected = company.value === 'current' ? undefined : company.value === 'all' ? 'all' : Number(company.value)
    const data = await dimensionsApi.analytics({ type_id: typeId.value, year: year.value, ...(selected === undefined ? {} : { supplier_id: selected }) })
    if (id !== requestId) return
    report.value = data
    loading.value = false
    if (trendValue.value !== 'total' && !(trendValue.value in data.value_monthly)) trendValue.value = 'total'
    const query: Record<string, string> = { type_id: String(typeId.value), year: String(year.value) }
    if (company.value !== 'current') query.supplier_id = company.value
    void router.replace({ query })
    await nextTick()
    drawCharts()
  } catch {
    if (id === requestId) failed.value = true
  } finally {
    if (id === requestId) loading.value = false
  }
}

async function exportTable(table: 'comparison' | 'companies' | 'monthly', format: 'xlsx' | 'pdf') {
  if (!report.value || exporting.value) return
  exporting.value = true
  try {
    const selected = company.value === 'current' ? undefined : company.value === 'all' ? 'all' : Number(company.value)
    const valueId = table === 'comparison' || trendValue.value === 'total' ? 'total' : trendValue.value === '' ? 'unassigned' : Number(trendValue.value)
    const blob = await dimensionsApi.exportAnalytics({
      type_id: typeId.value, year: year.value, ...(selected === undefined ? {} : { supplier_id: selected }),
      table, value_id: valueId, metric: metric.value, format,
    })
    const url = URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = url
    link.download = `dimenze-${table}-${year.value}.${format}`
    document.body.appendChild(link)
    link.click()
    link.remove()
    URL.revokeObjectURL(url)
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  } finally {
    exporting.value = false
  }
}

onMounted(async () => {
  await dims.load()
  if (!types.value.some(type => type.id === typeId.value)) typeId.value = (types.value.find(type => type.level === 'global') ?? types.value[0])?.id ?? 0
  if (selectedType.value?.level !== 'global') company.value = 'current'
  else if (!route.query.supplier_id) company.value = 'all'
  await load()
})
onBeforeUnmount(() => { trendChart?.destroy(); comparisonChart?.destroy(); cumulativeChart?.destroy() })
watch([metric, trendValue, locale, colors], () => { void nextTick(drawCharts) })
watch(typeId, () => { company.value = selectedType.value?.level === 'global' ? 'all' : 'current'; trendValue.value = 'total'; report.value = null; void load() })
watch(() => supplier.currentSupplierId, async () => {
  report.value = null
  await dims.load()
  if (!types.value.some(type => type.id === typeId.value)) typeId.value = (types.value.find(type => type.level === 'global') ?? types.value[0])?.id ?? 0
  company.value = selectedType.value?.level === 'global' ? 'all' : 'current'
  await load()
})
</script>

<template>
  <div>
    <div class="flex flex-wrap items-start justify-between gap-3 mb-5">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('dimensions.analytics_title') }}</h1>
        <p class="text-sm text-neutral-500 mt-1 max-w-3xl">{{ t('dimensions.analytics_subtitle') }}</p>
      </div>
      <RouterLink to="/accounting/dimension-profit" :class="btnOutline('neutral')" class="whitespace-nowrap">
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.chart" /></svg>
        {{ t('dimensions.profit_link') }}
      </RouterLink>
    </div>
    <EmptyState v-if="!dims.enabled.value" boxed icon="tag" :title="t('dimensions.disabled_title')" :message="t('dimensions.disabled_hint')" />
    <EmptyState v-else-if="noValues" boxed icon="tag" :title="t('dimensions.analytics_no_values_title')" :message="t('dimensions.analytics_no_values_hint')"
      to="/company/dimensions" :cta="t('dimensions.analytics_no_values_cta')" data-test="dimension-stats-no-values" />
    <template v-else>
      <div class="flex flex-wrap items-end gap-3 mb-5 bg-surface border border-neutral-200 rounded-lg shadow-sm p-4">
        <div>
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('dimensions.filter_type') }}</label>
          <select v-model.number="typeId" class="h-10 px-2 border border-neutral-300 rounded-md text-sm bg-surface" data-test="dimension-stats-type">
            <option v-for="type in types" :key="type.id" :value="type.id">{{ type.name }}</option>
          </select>
        </div>
        <div>
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('dimensions.analytics_company') }}</label>
          <select v-model="company" :disabled="selectedType?.level !== 'global'" class="h-10 px-2 border border-neutral-300 rounded-md text-sm bg-surface max-w-64" data-test="dimension-stats-company" @change="load">
            <option value="current">{{ currentName }}</option>
            <option v-if="selectedType?.level === 'global'" value="all">{{ t('dimensions.analytics_all_companies') }}</option>
            <option v-for="item in availableCompanies" :key="item.id" :value="String(item.id)">{{ item.company_name }}</option>
          </select>
          <p v-if="selectedType?.level !== 'global'" class="mt-1 text-xs text-neutral-500">{{ t('dimensions.analytics_company_local') }}</p>
        </div>
        <div>
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('dimensions.analytics_year') }}</label>
          <input v-model.number="year" type="number" min="2000" max="2100" class="h-10 w-24 px-2 border border-neutral-300 rounded-md text-sm bg-surface" data-test="dimension-stats-year" @change="load" />
        </div>
        <button type="button" :disabled="loading || !typeId" :class="btnFilled('primary')" class="whitespace-nowrap" @click="load">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.chart" /></svg>
          {{ t('dimensions.profit_show') }}
        </button>
      </div>
      <div v-if="loading" class="py-8 text-center text-sm text-neutral-500">{{ t('common.loading') }}</div>
      <EmptyState v-else-if="failed" variant="failed" boxed @action="load" />
      <EmptyState v-else-if="types.length === 0" boxed icon="tag" :title="t('dimensions.types_empty')" to="/company/dimensions" :cta="t('dimensions.title')" />
      <template v-else-if="report">
        <p v-if="report.hidden_companies" class="mb-3 text-xs text-warning-700">{{ t('dimensions.profit_hidden', { count: report.hidden_companies }) }}</p>
        <p v-if="report.basis === 'cash_journal'" class="mb-3 rounded-md border border-primary-200 bg-primary-50 px-3 py-2 text-xs text-primary-800" data-test="dimension-stats-cash-journal">
          {{ t('dimensions.profit_cash_journal_note') }}
        </p>
        <div v-if="selectedComparison" class="flex flex-wrap items-center gap-3 mb-4 rounded-lg border border-primary-200 bg-primary-50 px-4 py-2 text-sm" data-test="dimension-stats-selected">
          <span>{{ t('dimensions.analytics_selected') }}: <strong>{{ selectedLabel }}</strong></span>
          <button type="button" :class="btnOutline('neutral')" class="whitespace-nowrap" @click="trendValue = 'total'">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.chart" /></svg>
            {{ t('dimensions.analytics_show_all') }}
          </button>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-3 mb-5">
          <div v-for="key in (['revenue', 'cost', 'result'] as const)" :key="key" class="bg-surface border border-neutral-200 rounded-lg p-4 shadow-sm">
            <p class="text-xs text-neutral-500">{{ t(`dimensions.analytics_${key}`) }}</p>
            <p class="text-xl font-semibold tabular-nums mt-1" :class="key === 'result' ? resultClass(shownAmounts.result) : ''">{{ money(shownAmounts[key]) }}</p>
          </div>
          <div class="bg-surface border border-neutral-200 rounded-lg p-4 shadow-sm">
            <p class="text-xs text-neutral-500">{{ t('dimensions.analytics_margin') }}</p>
            <p class="text-xl font-semibold tabular-nums mt-1">{{ pct(margin(shownAmounts)) }}</p>
          </div>
        </div>
        <div v-if="hasTaxDifference" class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-3 mb-5" data-test="dimension-stats-tax">
          <div v-if="shownAmounts.tax_deductible_cost !== 0" class="bg-surface border border-neutral-200 rounded-lg p-4 shadow-sm"><p class="text-xs text-neutral-500">{{ t('dimensions.analytics_tax_deductible_cost') }}</p><p class="text-lg font-semibold tabular-nums mt-1">{{ money(shownAmounts.tax_deductible_cost) }}</p></div>
          <div v-if="shownAmounts.non_deductible_cost !== 0" class="bg-surface border border-neutral-200 rounded-lg p-4 shadow-sm"><p class="text-xs text-neutral-500">{{ t('dimensions.analytics_non_deductible_cost') }}</p><p class="text-lg font-semibold tabular-nums mt-1 text-warning-700">{{ money(shownAmounts.non_deductible_cost) }}</p></div>
          <div v-if="shownAmounts.income_tax_cost !== 0" class="bg-surface border border-neutral-200 rounded-lg p-4 shadow-sm"><p class="text-xs text-neutral-500">{{ t('dimensions.analytics_income_tax_cost') }}</p><p class="text-lg font-semibold tabular-nums mt-1">{{ money(shownAmounts.income_tax_cost) }}</p></div>
        </div>
        <div v-if="trendValue === 'total'" class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-5">
          <div class="bg-surface border border-neutral-200 rounded-lg p-4 shadow-sm"><p class="text-xs text-neutral-500">{{ t('dimensions.analytics_change') }}</p><p class="text-lg font-semibold tabular-nums">{{ pct(yearChange) }}</p></div>
          <div class="bg-surface border border-neutral-200 rounded-lg p-4 shadow-sm"><p class="text-xs text-neutral-500">{{ t('dimensions.analytics_coverage') }}</p><p class="text-lg font-semibold tabular-nums">{{ pct(assignedShare) }}</p></div>
          <div class="bg-surface border border-neutral-200 rounded-lg p-4 shadow-sm"><p class="text-xs text-neutral-500">{{ t('dimensions.analytics_unassigned_result') }}</p><p class="text-lg font-semibold tabular-nums" :class="resultClass(report.unassigned.result)">{{ money(report.unassigned.result) }}</p></div>
        </div>
        <div class="grid grid-cols-1 xl:grid-cols-2 gap-4 mb-5">
          <section class="bg-surface border border-neutral-200 rounded-lg p-4 shadow-sm">
            <div class="flex flex-wrap items-end justify-between gap-2 mb-3">
              <h2 class="font-semibold">{{ t('dimensions.analytics_trend') }}</h2>
              <div class="flex flex-wrap gap-2">
                <select v-model="trendValue" class="h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface max-w-44" data-test="dimension-stats-value">
                  <option value="total">{{ t('dimensions.profit_total') }}</option>
                  <option v-for="row in comparisons" :key="row.key" :value="row.key">{{ row.label }}</option>
                </select>
              </div>
            </div>
            <div class="h-72"><canvas ref="trendCanvas" /></div>
            <p v-if="trendValue !== 'total'" class="text-xs text-neutral-500 mt-2">{{ t('dimensions.analytics_previous_total_only') }}</p>
          </section>
          <section class="bg-surface border border-neutral-200 rounded-lg p-4 shadow-sm">
            <div class="flex flex-wrap items-center justify-between gap-2 mb-3">
              <h2 class="font-semibold">{{ t('dimensions.analytics_comparison') }}</h2>
              <div class="flex flex-wrap gap-1" role="group" :aria-label="t('dimensions.analytics_metric')" data-test="dimension-stats-metric">
                <button v-for="choice in (['revenue', 'cost', 'result'] as const)" :key="choice" type="button" :aria-pressed="metric === choice"
                        class="rounded-full border px-3 py-1.5 text-xs font-medium whitespace-nowrap transition-colors"
                        :class="metric === choice ? 'border-primary-600 bg-primary-600 text-white' : 'border-neutral-300 bg-surface text-neutral-600 hover:border-primary-400 hover:text-primary-700'"
                        @click="metric = choice">{{ t(`dimensions.analytics_${choice}`) }}</button>
              </div>
            </div>
            <div class="h-72"><canvas ref="comparisonCanvas" /></div>
            <p v-if="comparisons.length > 12" class="text-xs text-neutral-500 mt-2">{{ t('dimensions.analytics_top_twelve') }}</p>
          </section>
        </div>
        <section v-if="trendValue === 'total'" class="bg-surface border border-neutral-200 rounded-lg p-4 shadow-sm mb-5">
          <h2 class="font-semibold mb-3">{{ t('dimensions.analytics_cumulative') }}</h2>
          <div class="h-64"><canvas ref="cumulativeCanvas" /></div>
        </section>
        <section class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-x-auto mb-5">
          <div class="flex flex-wrap items-center justify-between gap-2 px-4 py-3 border-b border-neutral-200">
            <h2 class="font-semibold">{{ t('dimensions.analytics_comparison') }}</h2>
            <div class="flex flex-wrap gap-2">
              <button v-for="format in (['xlsx', 'pdf'] as const)" :key="format" type="button" :disabled="exporting" :class="btnOutline('neutral')" class="whitespace-nowrap" :data-test="`dimension-stats-export-comparison-${format}`" @click="exportTable('comparison', format)">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.download" /></svg>
                {{ format.toUpperCase() }}
              </button>
            </div>
          </div>
          <table class="w-full text-sm" data-test="dimension-stats-comparison">
            <thead class="bg-neutral-50 text-neutral-600"><tr><th class="px-4 py-3 text-left">{{ selectedType?.name }}</th><th class="px-4 py-3 text-right whitespace-nowrap">{{ t('dimensions.analytics_revenue') }}</th><th class="px-4 py-3 text-right whitespace-nowrap">{{ t('dimensions.analytics_cost') }}</th><th v-if="anyTaxDeductible && (anyNonDeductible || anyIncomeTax)" class="px-4 py-3 text-right whitespace-nowrap">{{ t('dimensions.analytics_tax_deductible_short') }}</th><th v-if="anyNonDeductible" class="px-4 py-3 text-right whitespace-nowrap">{{ t('dimensions.analytics_non_deductible_short') }}</th><th v-if="anyIncomeTax" class="px-4 py-3 text-right whitespace-nowrap">{{ t('dimensions.analytics_income_tax_short') }}</th><th class="px-4 py-3 text-right whitespace-nowrap">{{ t('dimensions.analytics_result') }}</th><th class="px-4 py-3 text-right whitespace-nowrap">{{ t('dimensions.analytics_margin') }}</th></tr></thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="row in comparisons" :key="row.key" tabindex="0" :aria-selected="trendValue === row.key" class="cursor-pointer focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary-600" :class="trendValue === row.key ? 'bg-primary-50' : 'hover:bg-neutral-50'" @click="trendValue = row.key" @keydown.enter="trendValue = row.key" @keydown.space.prevent="trendValue = row.key">
                <td class="px-4 py-2">
                  <button type="button" class="inline-flex cursor-pointer items-center gap-1.5 text-left font-medium text-primary-700 hover:underline" :aria-pressed="trendValue === row.key" :data-test="`dimension-stats-row-${row.key}`" @click="trendValue = row.key">
                    <svg class="w-3.5 h-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.chart" /></svg>
                    {{ row.label }}
                  </button>
                </td>
                <td class="px-4 py-2 text-right tabular-nums">{{ money(row.amounts.revenue) }}</td>
                <td class="px-4 py-2 text-right tabular-nums">{{ money(row.amounts.cost) }}</td>
                <td v-if="anyTaxDeductible && (anyNonDeductible || anyIncomeTax)" class="px-4 py-2 text-right tabular-nums">{{ row.amounts.tax_deductible_cost ? money(row.amounts.tax_deductible_cost) : '-' }}</td>
                <td v-if="anyNonDeductible" class="px-4 py-2 text-right tabular-nums">{{ row.amounts.non_deductible_cost ? money(row.amounts.non_deductible_cost) : '-' }}</td>
                <td v-if="anyIncomeTax" class="px-4 py-2 text-right tabular-nums">{{ row.amounts.income_tax_cost ? money(row.amounts.income_tax_cost) : '-' }}</td>
                <td class="px-4 py-2 text-right tabular-nums font-medium" :class="resultClass(row.amounts.result)">{{ money(row.amounts.result) }}</td>
                <td class="px-4 py-2 text-right tabular-nums">{{ pct(margin(row.amounts)) }}</td>
              </tr>
            </tbody>
            <tfoot class="border-t border-neutral-300 bg-neutral-50 font-semibold">
              <tr><td class="px-4 py-3">{{ t('dimensions.profit_total') }}</td><td class="px-4 py-3 text-right tabular-nums">{{ money(report.totals.revenue) }}</td><td class="px-4 py-3 text-right tabular-nums">{{ money(report.totals.cost) }}</td><td v-if="anyTaxDeductible && (anyNonDeductible || anyIncomeTax)" class="px-4 py-3 text-right tabular-nums">{{ money(report.totals.tax_deductible_cost) }}</td><td v-if="anyNonDeductible" class="px-4 py-3 text-right tabular-nums">{{ money(report.totals.non_deductible_cost) }}</td><td v-if="anyIncomeTax" class="px-4 py-3 text-right tabular-nums">{{ money(report.totals.income_tax_cost) }}</td><td class="px-4 py-3 text-right tabular-nums" :class="resultClass(report.totals.result)">{{ money(report.totals.result) }}</td><td class="px-4 py-3 text-right tabular-nums">{{ pct(margin(report.totals)) }}</td></tr>
            </tfoot>
          </table>
        </section>
        <section v-if="report.supplier_ids.length > 1" class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-x-auto mb-5">
          <div class="flex flex-wrap items-center justify-between gap-2 px-4 py-3 border-b border-neutral-200">
            <h2 class="font-semibold">{{ t('dimensions.analytics_by_company') }}: {{ selectedLabel }}</h2>
            <div class="flex flex-wrap gap-2">
              <button v-for="format in (['xlsx', 'pdf'] as const)" :key="format" type="button" :disabled="exporting" :class="btnOutline('neutral')" class="whitespace-nowrap" :data-test="`dimension-stats-export-companies-${format}`" @click="exportTable('companies', format)">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.download" /></svg>
                {{ format.toUpperCase() }}
              </button>
            </div>
          </div>
          <table class="w-full text-sm" data-test="dimension-stats-companies">
            <thead class="bg-neutral-50 text-neutral-600"><tr><th class="px-4 py-3 text-left">{{ t('dimensions.analytics_company') }}</th><th class="px-4 py-3 text-right">{{ t('dimensions.analytics_revenue') }}</th><th class="px-4 py-3 text-right">{{ t('dimensions.analytics_cost') }}</th><th class="px-4 py-3 text-right">{{ t('dimensions.analytics_result') }}</th></tr></thead>
            <tbody class="divide-y divide-neutral-100"><tr v-for="item in shownCompanies" :key="item.id"><td class="px-4 py-2">{{ item.name || (item.id === supplier.currentSupplierId ? currentName : `#${item.id}`) }}</td><td class="px-4 py-2 text-right tabular-nums">{{ money(item.revenue) }}</td><td class="px-4 py-2 text-right tabular-nums">{{ money(item.cost) }}</td><td class="px-4 py-2 text-right tabular-nums font-medium" :class="resultClass(item.result)">{{ money(item.result) }}</td></tr></tbody>
            <tfoot class="border-t border-neutral-300 bg-neutral-50 font-semibold"><tr><td class="px-4 py-3">{{ t('dimensions.profit_total') }}</td><td class="px-4 py-3 text-right tabular-nums">{{ money(shownAmounts.revenue) }}</td><td class="px-4 py-3 text-right tabular-nums">{{ money(shownAmounts.cost) }}</td><td class="px-4 py-3 text-right tabular-nums" :class="resultClass(shownAmounts.result)">{{ money(shownAmounts.result) }}</td></tr></tfoot>
          </table>
        </section>
        <section class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-x-auto">
          <div class="flex flex-wrap items-center justify-between gap-2 px-4 py-3 border-b border-neutral-200">
            <h2 class="font-semibold">{{ t('dimensions.analytics_monthly') }}: {{ selectedLabel }}</h2>
            <div class="flex flex-wrap gap-2">
              <button v-for="format in (['xlsx', 'pdf'] as const)" :key="format" type="button" :disabled="exporting" :class="btnOutline('neutral')" class="whitespace-nowrap" :data-test="`dimension-stats-export-monthly-${format}`" @click="exportTable('monthly', format)">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.download" /></svg>
                {{ format.toUpperCase() }}
              </button>
            </div>
          </div>
          <table class="w-full text-sm" data-test="dimension-stats-monthly">
            <thead class="bg-neutral-50 text-neutral-600"><tr><th class="px-4 py-3 text-left">{{ t('dimensions.analytics_month') }}</th><th class="px-4 py-3 text-right">{{ t('dimensions.analytics_revenue') }}</th><th class="px-4 py-3 text-right">{{ t('dimensions.analytics_cost') }}</th><th class="px-4 py-3 text-right">{{ t('dimensions.analytics_result') }}</th></tr></thead>
            <tbody class="divide-y divide-neutral-100"><tr v-for="month in shownMonths" :key="month.month"><td class="px-4 py-2">{{ month.month }}</td><td class="px-4 py-2 text-right tabular-nums">{{ money(month.revenue) }}</td><td class="px-4 py-2 text-right tabular-nums">{{ money(month.cost) }}</td><td class="px-4 py-2 text-right tabular-nums font-medium" :class="resultClass(month.result)">{{ money(month.result) }}</td></tr></tbody>
            <tfoot class="border-t border-neutral-300 bg-neutral-50 font-semibold"><tr><td class="px-4 py-3">{{ t('dimensions.profit_total') }}</td><td class="px-4 py-3 text-right tabular-nums">{{ money(shownAmounts.revenue) }}</td><td class="px-4 py-3 text-right tabular-nums">{{ money(shownAmounts.cost) }}</td><td class="px-4 py-3 text-right tabular-nums" :class="resultClass(shownAmounts.result)">{{ money(shownAmounts.result) }}</td></tr></tfoot>
          </table>
        </section>
        <p class="text-xs text-neutral-500 mt-4">{{ t('dimensions.analytics_method') }}</p>
      </template>
    </template>
  </div>
</template>

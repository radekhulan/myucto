<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { formatMoneyWhole } from '@/composables/useFormat'
import GroupDashboardChart, { type GroupChartSeries } from '@/components/group-dashboard/GroupDashboardChart.vue'
import EmptyState from '@/components/ui/EmptyState.vue'

export interface GroupAnnualForecast {
  year: number; currency: string
  revenue_model: number | null; costs_model: number | null
  revenue_current_year: number | null; costs_current_year: number | null
  other_revenue: number | null; other_costs: number | null
  other_posted: number | null; other_draft: number | null
  revenue: number | null; costs: number | null; profit: number | null
  revenue_low: number | null; revenue_high: number | null
  profit_low: number | null; profit_high: number | null
}
export interface GroupForecastCompany {
  id: number; name: string; document_basis: 'net' | 'gross'; issues: string[]
  forecast?: GroupAnnualForecast[] | null
}
export interface GroupForecastReport {
  as_of: string; companies: GroupForecastCompany[]
  totals: { forecast: (GroupAnnualForecast & { companies: number; missing_values: number })[] }
}
const props = withDefaults(defineProps<{ report: GroupForecastReport; currency?: string }>(), { currency: '' })
const { t } = useI18n()
const selectedCompany = ref('all')
const chosenCurrency = ref(props.currency)
const metrics = ['revenue', 'costs', 'profit'] as const
const currencies = computed(() => [...new Set([
  ...props.report.totals.forecast.map(row => row.currency),
  ...props.report.companies.flatMap(company => company.forecast?.map(row => row.currency) ?? []),
])].sort())
watch([currencies, () => props.currency], ([codes, requested]) => {
  if (requested) chosenCurrency.value = requested
  else if (!codes.includes(chosenCurrency.value)) chosenCurrency.value = codes[0] ?? ''
}, { immediate: true })
watch(() => props.report.companies, companies => {
  if (selectedCompany.value !== 'all' && !companies.some(company => String(company.id) === selectedCompany.value)) selectedCompany.value = 'all'
})
const selected = computed(() => props.report.companies.find(company => String(company.id) === selectedCompany.value))
const summary = computed(() => (selectedCompany.value === 'all'
  ? props.report.totals.forecast : (selected.value?.forecast ?? []).map(row => ({ ...row, companies: 1, missing_values: 0 })))
  .filter(row => row.currency === chosenCurrency.value))
const companyRows = computed(() => props.report.companies.map(company => ({
  company, value: company.forecast?.find(row => row.currency === chosenCurrency.value) ?? null,
})))
const chartRows = computed(() => companyRows.value.filter(row => row.value !== null))
const chartSeries = computed<GroupChartSeries[]>(() => metrics.map((metric, index) => ({
  label: t(`group_stats.${metric}`), values: chartRows.value.map(row => row.value![metric]),
  tone: (['primary', 'warning', 'success'] as const)[index]!,
})))
const money = (amount: number | null | undefined, currency: string) => amount == null ? t('group_stats.forecast_unknown') : formatMoneyWhole(amount, currency)
const resultClass = (amount: number | null | undefined) => amount == null ? 'text-neutral-400' : amount < 0 ? 'text-danger-600' : amount > 0 ? 'text-success-600' : 'text-neutral-700'
const stateText = (company: GroupForecastCompany) => company.forecast === undefined ? t('group_stats.restricted')
  : company.forecast === null ? t('group_stats.failed_part') : t('group_stats.no_currency_data')
const range = (low: number | null, high: number | null, currency: string) => `${money(low, currency)} - ${money(high, currency)}`
const missing = computed(() => props.report.companies.filter(company => company.forecast === undefined || company.forecast === null))
</script>

<template>
  <div class="space-y-6" data-test="group-forecast">
    <div class="rounded-lg border border-primary-200 bg-primary-50 p-4 text-sm text-primary-800 space-y-2">
      <p>{{ t('group_stats.forecast_annual_note') }}</p>
      <p class="text-xs">{{ t('group_stats.forecast_sources_note') }}</p>
    </div>
    <div class="flex flex-wrap items-end gap-4">
      <label v-if="currencies.length && !currency" class="space-y-1 text-sm text-neutral-600">
        <span class="block">{{ t('group_stats.currency') }}</span>
        <select v-model="chosenCurrency" class="forecast-input w-32" data-test="forecast-currency"><option v-for="code in currencies" :key="code" :value="code">{{ code }}</option></select>
      </label>
      <label class="space-y-1 text-sm text-neutral-600">
        <span class="block">{{ t('group_stats.company') }}</span>
        <select v-model="selectedCompany" class="forecast-input max-w-full sm:w-64" data-test="forecast-company"><option value="all">{{ t('group_stats.all_companies') }}</option><option v-for="company in report.companies" :key="company.id" :value="String(company.id)">{{ company.name }}</option></select>
      </label>
    </div>
    <details v-if="missing.length" class="rounded-lg border border-warning-200 bg-warning-50 px-4 py-3 text-sm text-warning-800" data-test="forecast-notices">
      <summary class="cursor-pointer font-medium">{{ t('group_stats.forecast_coverage_note', { count: missing.length }) }}</summary>
      <ul class="mt-2 space-y-1"><li v-for="company in missing" :key="company.id">{{ company.name }}: {{ stateText(company) }}</li></ul>
    </details>
    <p v-if="selected && !selected.forecast" class="text-sm text-warning-700">{{ stateText(selected) }}</p>
    <EmptyState v-else-if="!summary.length" :title="t('group_stats.no_data')" :message="t('group_stats.no_total_hint')" />
    <section v-for="row in summary" :key="`${row.year}:${row.currency}`" class="space-y-3" data-test="forecast-summary">
      <div class="flex flex-wrap items-center justify-between gap-2"><h2 class="text-lg font-semibold text-neutral-900">{{ t('group_stats.forecast_year', { year: row.year, currency: row.currency }) }}</h2>
        <p class="text-xs" :class="row.missing_values ? 'text-warning-700' : 'text-neutral-500'">{{ row.missing_values ? t('group_stats.partial_total', { companies: row.companies, missing: row.missing_values }) : t('group_stats.total_companies', { count: row.companies }) }}</p>
      </div>
      <div class="grid gap-3 sm:grid-cols-3">
        <article v-for="metric in metrics" :key="metric" class="forecast-panel" :data-test="`forecast-${metric}`">
          <p class="text-sm text-neutral-500">{{ t(`group_stats.${metric}`) }}</p>
          <p class="mt-2 text-2xl font-bold tabular-nums" :class="metric === 'profit' ? resultClass(row[metric]) : 'text-neutral-900'">{{ money(row[metric], row.currency) }}</p>
          <p v-if="metric === 'revenue'" class="mt-2 text-xs text-neutral-500">{{ t('group_stats.forecast_model_range', { range: range(row.revenue_low, row.revenue_high, row.currency) }) }}</p>
          <p v-if="metric === 'profit'" class="mt-2 text-xs text-neutral-500">{{ t('group_stats.forecast_model_range', { range: range(row.profit_low, row.profit_high, row.currency) }) }}</p>
          <p v-if="metric !== 'profit'" class="mt-2 text-xs text-neutral-500">{{ t('group_stats.forecast_model_part', { amount: money(metric === 'revenue' ? row.revenue_model : row.costs_model, row.currency) }) }}</p>
          <p v-if="metric !== 'profit'" class="mt-1 text-xs text-neutral-500">{{ t('group_stats.forecast_other_part', { amount: money(metric === 'revenue' ? row.other_revenue : row.other_costs, row.currency) }) }}</p>
        </article>
      </div>
    </section>
    <section class="forecast-panel space-y-4">
      <h2 class="text-lg font-semibold text-neutral-900">{{ t('group_stats.forecast_company_comparison') }} <span class="text-neutral-500">{{ chosenCurrency }}</span></h2>
      <GroupDashboardChart :labels="chartRows.map(row => row.company.name)" :series="chartSeries" :currency="chosenCurrency" :title="t('group_stats.forecast_company_comparison')" horizontal />
      <div class="forecast-table-scroll"><table data-test="forecast-company-table"><thead><tr><th>{{ t('group_stats.company') }}</th><th>{{ t('group_stats.basis') }}</th><th>{{ t('group_stats.revenue') }}</th><th>{{ t('group_stats.costs') }}</th><th>{{ t('group_stats.profit') }}</th><th>{{ t('group_stats.forecast_range') }}</th></tr></thead><tbody>
        <tr v-for="row in companyRows" :key="row.company.id"><td class="font-medium">{{ row.company.name }}</td><td>{{ t(`group_stats.basis_${row.company.document_basis}`) }}</td><template v-if="row.value"><td>{{ money(row.value.revenue, row.value.currency) }}</td><td>{{ money(row.value.costs, row.value.currency) }}</td><td :class="resultClass(row.value.profit)">{{ money(row.value.profit, row.value.currency) }}</td><td class="text-xs">{{ range(row.value.profit_low, row.value.profit_high, row.value.currency) }}</td></template><td v-else colspan="4" class="text-neutral-500">{{ stateText(row.company) }}</td></tr>
      </tbody></table></div>
    </section>
    <section class="forecast-panel space-y-4">
      <h2 class="text-lg font-semibold text-neutral-900">{{ t('group_stats.forecast_source_breakdown') }}</h2>
      <p class="text-sm text-neutral-500">{{ t('group_stats.forecast_installments_note') }}</p>
      <div class="forecast-table-scroll"><table data-test="forecast-source-table"><thead><tr><th>{{ t('group_stats.company') }}</th><th>{{ t('group_stats.forecast_document_revenue') }}</th><th>{{ t('group_stats.forecast_document_costs') }}</th><th>{{ t('group_stats.forecast_other_revenue') }}</th><th>{{ t('group_stats.forecast_other_costs') }}</th><th>{{ t('group_stats.forecast_known_posted') }}</th><th>{{ t('group_stats.forecast_known_draft') }}</th></tr></thead><tbody>
        <tr v-for="row in companyRows" :key="row.company.id"><td class="font-medium">{{ row.company.name }}</td><template v-if="row.value"><td>{{ money(row.value.revenue_model, row.value.currency) }}</td><td>{{ money(row.value.costs_model, row.value.currency) }}</td><td>{{ money(row.value.other_revenue, row.value.currency) }}</td><td>{{ money(row.value.other_costs, row.value.currency) }}</td><td>{{ money(row.value.other_posted, row.value.currency) }}</td><td>{{ money(row.value.other_draft, row.value.currency) }}</td></template><td v-else colspan="6" class="text-neutral-500">{{ stateText(row.company) }}</td></tr>
      </tbody></table></div>
      <p class="text-xs text-neutral-500">{{ t('group_stats.forecast_method_note') }}</p>
    </section>
  </div>
</template>

<style scoped>
@reference '../../styles/main.css';
.forecast-panel { @apply rounded-lg border border-neutral-200 bg-surface p-4 shadow-sm sm:p-5; }
.forecast-input { @apply h-10 rounded-md border border-neutral-300 bg-surface px-3 text-neutral-900 focus:border-primary-500 focus:outline-none; }
.forecast-table-scroll { @apply max-w-full overflow-x-auto; }
table { @apply w-full text-sm; }
th { @apply border-b border-neutral-200 px-3 py-3 text-left font-medium text-neutral-500; }
td { @apply border-b border-neutral-100 px-3 py-3 tabular-nums; }
th:not(:first-child), td:not(:first-child) { @apply whitespace-nowrap; }
</style>

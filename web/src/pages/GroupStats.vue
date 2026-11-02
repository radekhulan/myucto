<script setup lang="ts">
import DateInput from '@/components/ui/DateInput.vue'
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useSupplierStore } from '@/stores/supplier'
import { groupDashboardApi, type Aging, type CashWeek, type Financial, type GroupCompany, type GroupDashboard, type GroupSection, type Monthly, type Risk } from '@/api/groupDashboard'
import { GROUP_SECTIONS, SECTION_FIELDS, accountingGroupKey, groupRiskDestination, margin, metricState, yearChange, type GroupField, type MetricState } from '@/utils/groupDashboard'
import { useSupplierSwitch } from '@/composables/useSupplierSwitch'
import { formatMoneyWhole, formatNumber } from '@/composables/useFormat'
import { ICONS, btnOutline } from '@/components/ui/buttonStyles'
import GroupDashboardChart, { type GroupChartSeries } from '@/components/group-dashboard/GroupDashboardChart.vue'
import GroupForecast from '@/components/group-dashboard/GroupForecast.vue'
import EmptyState from '@/components/ui/EmptyState.vue'

const { t, locale } = useI18n()
const route = useRoute()
const router = useRouter()
const { switching, switchTo } = useSupplierSwitch()
const auth = useAuthStore()
const supplier = useSupplierStore()
const active = ref<GroupSection>(GROUP_SECTIONS.includes(route.query.section as GroupSection) ? route.query.section as GroupSection : 'overview')
const months = ref(12)
const weeks = ref(8)
const ALL_CURRENCIES = '__ALL__'
const currencyChoice = ref(ALL_CURRENCIES)
const currency = computed(() => currencyChoice.value === ALL_CURRENCIES ? 'CZK' : currencyChoice.value)
const range = ref<{ from: string; to: string } | null>(null)
const draftFrom = ref('')
const draftTo = ref('')
const selectedCompany = ref('all')
// Doklady se spojenými osobami (příznak u kontaktu) — u skupiny jde typicky o vnitroskupinové převody.
const includeRelated = ref(route.query.related !== '0')
const RELATED_SECTIONS: GroupSection[] = ['overview', 'trends', 'receivables', 'risks']
const cache = ref<Record<string, GroupDashboard>>({})
const loading = ref(false)
const error = ref('')
let controller: AbortController | null = null
let requestId = 0
const key = computed(() => `${active.value}:${months.value}:${weeks.value}:${range.value?.from ?? ''}:${range.value?.to ?? ''}:${includeRelated.value ? 1 : 0}`)
const nativeReport = computed(() => cache.value[key.value] ?? null)
const report = computed(() => {
  const data = nativeReport.value
  return data && currencyChoice.value === ALL_CURRENCIES && data.converted_czk ? { ...data, companies: data.converted_czk.companies, totals: data.converted_czk.totals } : data
})
const fields = computed(() => SECTION_FIELDS[active.value])
const money = (value: number | null | undefined, code: string) => value === null || value === undefined ? '-' : formatMoneyWhole(value, code)
const date = (value: string | null | undefined) => value ? new Intl.DateTimeFormat(locale.value).format(new Date(`${value.slice(0, 10)}T12:00:00`)) : '-'
const pct = (value: number | null | undefined) => value === null || value === undefined ? '-' : `${formatNumber(value, { maximumFractionDigits: 1 })} %`
const resultClass = (value: number | null | undefined) => value === null || value === undefined ? 'text-neutral-400' : value < 0 ? 'text-danger-600' : value > 0 ? 'text-success-600' : 'text-neutral-700'
const metrics = ['revenue', 'costs', 'profit'] as const
const buckets: Aging['bucket'][] = ['not_due', 'overdue_30', 'overdue_60', 'overdue_90', 'overdue_90_plus']
const stateKeys: Record<MetricState, string> = { available: 'group_stats.no_currency_data', restricted: 'group_stats.restricted', failed: 'group_stats.failed_part', no_period: 'group_stats.no_period' }
const stateText = (company: GroupCompany, field: GroupField) => t(stateKeys[metricState(company, field)])
const partialText = (row: { companies: number; missing_values: number }) => row.missing_values > 0
  ? t('group_stats.partial_total', { companies: row.companies, missing: row.missing_values })
  : t('group_stats.total_companies', { count: row.companies })
const selected = computed(() => report.value?.companies.find(company => String(company.id) === selectedCompany.value))
const notices = computed(() => (report.value?.companies ?? []).flatMap(company => fields.value
  .filter(field => metricState(company, field) !== 'available')
  .map(field => ({ company, field, state: metricState(company, field) }))))
const noticeCounts = computed(() => ({
  failed: notices.value.filter(notice => notice.state === 'failed').length,
  restricted: notices.value.filter(notice => notice.state === 'restricted').length,
  noPeriod: notices.value.filter(notice => notice.state === 'no_period').length,
}))
const currencies = computed(() => {
  const data = nativeReport.value
  if (!data) return []
  const codes = new Set<string>()
  for (const field of fields.value) {
    for (const company of data.companies) {
      const value = company[field]
      if (Array.isArray(value)) value.forEach(row => codes.add(row.currency))
      else if (value && 'currency' in value) codes.add(value.currency)
    }
  }
  return [...codes].sort((a, b) => a.localeCompare(b))
})
watch(currencies, codes => { if (currencyChoice.value !== ALL_CURRENCIES && !codes.includes(currencyChoice.value)) currencyChoice.value = ALL_CURRENCIES })
watch(nativeReport, data => { if (data) { draftFrom.value = data.period.from; draftTo.value = data.period.to } })
watch(report, data => { if (selectedCompany.value !== 'all' && !data?.companies.some(company => String(company.id) === selectedCompany.value)) selectedCompany.value = 'all' })

async function load(force = false) {
  const id = ++requestId
  controller?.abort()
  error.value = ''
  if (!force && cache.value[key.value]) { loading.value = false; return }
  const requestKey = key.value
  controller = new AbortController()
  loading.value = true
  try {
    const data = await groupDashboardApi.get({ section: active.value, months: months.value, weeks: weeks.value, ...(range.value ?? {}), ...(includeRelated.value ? {} : { include_related: 0 as const }) }, controller.signal)
    if (id === requestId) cache.value = { ...cache.value, [requestKey]: data }
  } catch (cause: unknown) {
    if (id !== requestId || controller?.signal.aborted) return
    const failure = cause as { response?: { data?: { error?: { message?: string } } } }
    error.value = failure.response?.data?.error?.message || t('errors.generic')
  } finally {
    if (id === requestId) loading.value = false
  }
}
watch([active, months, weeks, range, includeRelated], () => load(), { immediate: true })
watch(includeRelated, value => { void router.replace({ query: { ...route.query, related: value ? undefined : '0' } }) })
function applyRange() {
  if (!draftFrom.value || !draftTo.value || draftFrom.value > draftTo.value) return
  range.value = { from: draftFrom.value, to: draftTo.value }
}
function resetRange() {
  months.value = 12
  range.value = null
}
watch([() => auth.user, () => auth.permissions, () => auth.domainContext, () => auth.permissionsLoading, () => supplier.currentSupplierId, () => supplier.availableSuppliers, () => supplier.domainLocked], () => {
  requestId++
  controller?.abort()
  cache.value = {}
  error.value = ''
  loading.value = false
  selectedCompany.value = 'all'
  if (!auth.permissionsLoading && auth.user && auth.canRead('dashboard.portfolio')) void load()
}, { deep: true, flush: 'sync' })
onBeforeUnmount(() => { requestId++; controller?.abort() })

const financialRows = computed(() => (report.value?.companies ?? []).map(company => ({ company, value: company.financial?.find(row => row.currency === currency.value) ?? null })))
const financialSeries = computed<GroupChartSeries[]>(() => metrics.map((metric, index) => ({
  label: t(`group_stats.${metric}`), values: financialRows.value.filter(row => row.value !== null).map(row => row.value![metric]),
  tone: (['primary', 'warning', 'success'] as const)[index]!,
})))
const accountingRows = computed(() => (report.value?.companies ?? []).map(company => ({ company, value: company.accounting })))
const monthlyRows = computed<Monthly[]>(() => (selectedCompany.value === 'all'
  ? report.value?.totals.monthly ?? [] : selected.value?.monthly ?? []).filter(row => row.currency === currency.value).sort((a, b) => a.period.localeCompare(b.period)))
const monthlySeries = computed<GroupChartSeries[]>(() => metrics.map((metric, index) => ({ label: t(`group_stats.${metric}`), values: monthlyRows.value.map(row => row[metric]), tone: (['primary', 'warning', 'success'] as const)[index]! })))
const trendCompanies = computed(() => (report.value?.companies ?? []).map(company => ({
  company, rows: company.monthly?.filter(row => row.currency === currency.value) ?? null,
})))
const sumKnown = (values: (number | null)[]) => values.some(value => value === null) ? null : values.reduce<number>((sum, value) => sum + (value ?? 0), 0)
const monthlySum = (rows: Monthly[], metric: typeof metrics[number]) => sumKnown(rows.map(row => row[metric]))
const monthlyCoverage = computed(() => selectedCompany.value === 'all' ? report.value?.totals.monthly.filter(row => row.currency === currency.value) ?? [] : [])
const cashRows = computed<(CashWeek & { currency: string; companies?: number; missing_values?: number })[]>(() => {
  if (selectedCompany.value === 'all') return (report.value?.totals.cashflow ?? []).filter(row => row.currency === currency.value).sort((a, b) => a.week_start.localeCompare(b.week_start))
  return (selected.value?.cashflow?.find(row => row.currency === currency.value)?.weeks ?? []).map(row => ({ ...row, currency: currency.value }))
})
const cashflowExclusions = computed(() => (selectedCompany.value === 'all' ? report.value?.companies ?? [] : selected.value ? [selected.value] : [])
  .filter(company => company.available.cashflow && (!company.available.cashflow_tax || !company.available.cashflow_payroll)))
const cashSeries = computed<GroupChartSeries[]>(() => [
  { label: t('group_stats.inflow'), values: cashRows.value.map(row => row.in), tone: 'success' },
  { label: t('group_stats.outflow'), values: cashRows.value.map(row => row.out), tone: 'warning' },
  { label: t('group_stats.running'), values: cashRows.value.map(row => row.running), tone: 'primary' },
])
const cashTotals = computed(() => cashRows.value.length ? { in: sumKnown(cashRows.value.map(row => row.in)), out: sumKnown(cashRows.value.map(row => row.out)), net: sumKnown(cashRows.value.map(row => row.net)) } : null)
const balances = computed(() => (report.value?.companies ?? []).flatMap(company => (['bank', 'cash'] as const).flatMap(field => (company[field] ?? []).filter(value => value.currency === currency.value).map(value => ({ company, field, value })))))
const unknownBalances = computed(() => balances.value.filter(row => row.value.balance === null))
const agingRows = (field: 'receivables' | 'payables') => (report.value?.totals[field] ?? []).filter(row => row.currency === currency.value)
const agingSeries = computed<GroupChartSeries[]>(() => (['receivables', 'payables'] as const).map((field, index) => ({
  label: t(`group_stats.${field}`), values: buckets.map(bucket => agingRows(field).find(row => row.bucket === bucket)?.total ?? null), tone: index ? 'warning' : 'primary',
})))
const companyAging = (company: GroupCompany, field: 'receivables' | 'payables') => company[field]?.filter(row => row.currency === currency.value) ?? []
const riskRows = computed(() => (report.value?.companies ?? []).flatMap(company => (company.risks ?? []).filter(risk => currencyChoice.value === ALL_CURRENCIES || risk.currency === currency.value).map(risk => ({ company, risk }))))
const knownRiskCompanies = computed(() => (report.value?.companies ?? []).filter(company => metricState(company, 'risks') === 'available'))
const riskCompanies = computed(() => new Set(riskRows.value.map(row => row.company.id)).size)
const highRisks = computed(() => riskRows.value.filter(row => row.risk.level === 'high').length)
const riskSeries = computed<GroupChartSeries[]>(() => [{ label: t('group_stats.risk_count'), tone: 'warning', values: (report.value?.companies ?? []).filter(company => company.risks !== null && company.risks !== undefined).map(company => riskRows.value.filter(row => row.company.id === company.id).length) }])
const riskValue = (risk: Risk) => risk.percentage === undefined ? money(risk.amount, risk.currency) : pct(risk.percentage)
async function openRisk(company: GroupCompany, risk: Risk) {
  if (switching.value || !supplier.availableSuppliers.some(row => row.id === company.id)) return
  const destination = groupRiskDestination(risk, report.value?.period)
  if (company.id === supplier.currentSupplierId) await router.push(destination)
  else if (!supplier.domainLocked) await switchTo(company.id, destination)
}
const financialPrevious = (row: Financial, metric: typeof metrics[number]) => row[`previous_${metric}`]
const tabIcons: Record<GroupSection, string> = { overview: ICONS.chart, trends: ICONS.chart, forecast: ICONS.chart, cashflow: ICONS.calendar, balances: ICONS.warehouse, receivables: ICONS.doc, risks: ICONS.bell }
</script>

<template>
  <div class="space-y-6" data-test="group-stats">
    <header class="flex flex-wrap items-start justify-between gap-4">
      <div class="min-w-0">
        <h1 class="text-2xl font-bold text-neutral-900">{{ t('group_stats.title') }}</h1>
        <p class="mt-1 max-w-3xl text-sm text-neutral-500">{{ t('group_stats.subtitle') }}</p>
      </div>
      <div class="flex flex-wrap items-center gap-3">
        <span v-if="report" class="text-xs text-neutral-500" :title="new Date(report.generated_at).toLocaleString(locale)">
          {{ t('group_stats.company_count', { count: report.company_count }) }} · {{ t('group_stats.generated', { time: new Date(report.generated_at).toLocaleTimeString(locale, { hour: '2-digit', minute: '2-digit' }) }) }}
        </span>
        <button type="button" :class="btnOutline('neutral')" :disabled="loading" data-test="group-refresh" @click="load(true)">
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path :d="ICONS.cycle" /></svg>
          {{ t('group_stats.refresh') }}
        </button>
      </div>
    </header>
    <div class="rounded-lg border border-primary-200 bg-primary-50 px-4 py-3 text-sm text-primary-800">
      <p>{{ t('group_stats.management_note') }}</p>
      <p class="mt-1 text-xs">{{ t('group_stats.currency_note') }}</p>
    </div>
    <div class="flex flex-wrap gap-2" role="tablist" :aria-label="t('group_stats.tabs_label')">
      <button v-for="section in GROUP_SECTIONS" :id="`group-tab-${section}`" :key="section" type="button" role="tab" :aria-selected="active === section" :aria-controls="`group-panel-${section}`" :data-test="`group-tab-${section}`"
        :class="[btnOutline(active === section ? 'primary' : 'neutral'), active === section ? 'bg-primary-50 border-primary-500' : '']" @click="active = section">
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path :d="tabIcons[section]" /></svg>
        {{ t(`group_stats.tabs.${section}`) }}
      </button>
    </div>
    <div :id="`group-panel-${active}`" role="tabpanel" :aria-labelledby="`group-tab-${active}`" :aria-busy="loading" class="space-y-6">
      <div class="flex flex-wrap items-end gap-4">
        <label v-if="currencies.length" class="space-y-1 text-sm text-neutral-600">
          <span class="block">{{ t('group_stats.currency') }}</span>
          <select v-model="currencyChoice" class="input w-40" data-test="group-currency"><option :value="ALL_CURRENCIES">{{ t('group_stats.all_currencies') }}</option><option v-for="code in currencies" :key="code" :value="code">{{ code }}</option></select>
        </label>
        <form v-if="['overview', 'trends', 'risks'].includes(active)" class="flex flex-wrap items-end gap-3" @submit.prevent="applyRange">
          <label class="space-y-1 text-sm text-neutral-600"><span class="block">{{ t('group_stats.date_from') }}</span><DateInput v-model="draftFrom" required :max="draftTo || undefined" class="input w-40" data-test="group-from" /></label>
          <label class="space-y-1 text-sm text-neutral-600"><span class="block">{{ t('group_stats.date_to') }}</span><DateInput v-model="draftTo" required :min="draftFrom || undefined" class="input w-40" data-test="group-to" /></label>
          <button type="submit" :class="btnOutline('primary')" :disabled="loading || !draftFrom || !draftTo || draftFrom > draftTo" data-test="group-apply-range"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path :d="ICONS.search" /></svg>{{ t('group_stats.apply_range') }}</button>
          <button type="button" :class="btnOutline('neutral')" :disabled="loading" data-test="group-reset-range" @click="resetRange"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path :d="ICONS.calendar" /></svg>{{ t('group_stats.last_12_months') }}</button>
        </form>
        <label v-if="RELATED_SECTIONS.includes(active)" class="space-y-1 text-sm text-neutral-600">
          <span class="block">{{ t('group_stats.related_parties') }}</span>
          <select v-model="includeRelated" class="input w-48" :title="t('group_stats.related_parties_hint')" data-test="group-related">
            <option :value="true">{{ t('group_stats.related_include') }}</option>
            <option :value="false">{{ t('group_stats.related_exclude') }}</option>
          </select>
        </label>
        <label v-if="active === 'cashflow'" class="space-y-1 text-sm text-neutral-600">
          <span class="block">{{ t('group_stats.weeks') }}</span>
          <select v-model="weeks" class="input w-32" data-test="group-weeks"><option v-for="count in [4, 8, 12]" :key="count" :value="count">{{ count }}</option></select>
        </label>
        <label v-if="['trends', 'cashflow'].includes(active) && report" class="space-y-1 text-sm text-neutral-600">
          <span class="block">{{ t('group_stats.company') }}</span>
          <select v-model="selectedCompany" class="input max-w-full sm:w-64" data-test="group-company"><option value="all">{{ t('group_stats.all_companies') }}</option><option v-for="company in report.companies" :key="company.id" :value="String(company.id)">{{ company.name }}</option></select>
        </label>
      </div>
      <p v-if="currencyChoice === ALL_CURRENCIES" class="text-xs text-neutral-500" data-test="group-conversion-note">{{ t('group_stats.conversion_note') }}<span v-if="nativeReport?.converted_czk?.missing_currencies.length" class="text-warning-700"> {{ t('group_stats.missing_rates', { currencies: nativeReport.converted_czk.missing_currencies.join(', ') }) }}</span></p>
      <EmptyState v-if="!auth.canRead('dashboard.portfolio')" :title="t('group_stats.restricted')" :message="t('group_stats.empty_hint')" />
      <p v-else-if="loading" class="py-12 text-center text-neutral-500" role="status">{{ t('common.loading') }}</p>
      <EmptyState v-else-if="error" variant="failed" :message="error" :cta="t('group_stats.retry')" cta-icon="cycle" data-test="group-load-error" @action="load(true)" />
      <EmptyState v-else-if="!report?.companies.length" :title="t('group_stats.empty_title')" :message="t('group_stats.empty_hint')" />
      <template v-else>
        <details v-if="notices.length" class="rounded-lg border border-warning-200 bg-warning-50 px-4 py-3 text-sm text-warning-800" data-test="group-notices">
          <summary class="cursor-pointer font-medium">{{ t('group_stats.coverage', noticeCounts) }}</summary>
          <ul class="mt-3 space-y-1"><li v-for="notice in notices" :key="`${notice.company.id}:${notice.field}`">{{ notice.company.name }} · {{ t(`group_stats.fields.${notice.field}`) }}: {{ stateText(notice.company, notice.field) }}</li></ul>
        </details>
        <template v-if="active === 'overview'">
          <section class="space-y-3">
            <h2 class="text-lg font-semibold text-neutral-900">{{ t('group_stats.documents_period', { from: date(report.period.from), to: date(report.period.to) }) }}</h2>
            <p class="text-sm text-neutral-500">{{ t('group_stats.document_basis_note') }}</p>
            <EmptyState v-if="!report.totals.financial.length" :title="t('group_stats.no_data')" :message="t('group_stats.no_total_hint')" />
            <div v-for="total in report.totals.financial.filter(row => row.currency === currency)" :key="total.currency" class="space-y-2">
              <div class="flex flex-wrap items-center justify-between gap-2 text-xs text-neutral-500"><strong class="text-sm text-neutral-700">{{ total.currency }}</strong><span :class="total.missing_values ? 'text-warning-700' : ''">{{ partialText(total) }}</span></div>
              <div class="grid gap-3 sm:grid-cols-3">
                <article v-for="metric in metrics" :key="metric" class="rounded-lg border border-neutral-200 bg-surface p-4 shadow-sm">
                  <p class="text-sm text-neutral-500">{{ t(`group_stats.${metric}`) }}</p>
                  <p class="mt-2 text-xl font-bold tabular-nums" :class="metric === 'profit' ? resultClass(total[metric]) : 'text-neutral-900'">{{ money(total[metric], total.currency) }}</p>
                  <p class="mt-2 text-xs text-neutral-500">{{ t('group_stats.previous_period') }}: {{ money(financialPrevious(total, metric), total.currency) }}</p>
                  <p class="mt-1 text-sm tabular-nums">{{ t('group_stats.yoy') }}: {{ pct(yearChange(total[metric], financialPrevious(total, metric))) }}</p>
                </article>
              </div>
            </div>
          </section>
          <section class="panel space-y-4">
            <h2>{{ t('group_stats.company_comparison') }} <span class="text-neutral-500">{{ currency }}</span></h2>
            <GroupDashboardChart :labels="financialRows.filter(row => row.value).map(row => row.company.name)" :series="financialSeries" :currency="currency" :title="t('group_stats.company_comparison')" horizontal />
            <div class="table-scroll"><table data-test="group-financial-table"><thead><tr><th>{{ t('group_stats.company') }}</th><th>{{ t('group_stats.basis') }}</th><th>{{ t('group_stats.revenue') }}</th><th>{{ t('group_stats.costs') }}</th><th>{{ t('group_stats.profit') }}</th><th>{{ t('group_stats.margin') }}</th><th>{{ t('group_stats.revenue_yoy') }}</th></tr></thead><tbody>
              <tr v-for="row in financialRows" :key="row.company.id"><td class="font-medium">{{ row.company.name }}</td><td>{{ t(`group_stats.basis_${row.company.document_basis}`) }}</td><template v-if="row.value"><td>{{ money(row.value.revenue, currency) }}</td><td>{{ money(row.value.costs, currency) }}</td><td :class="resultClass(row.value.profit)">{{ money(row.value.profit, currency) }}</td><td>{{ pct(margin(row.value.revenue, row.value.profit)) }}</td><td>{{ pct(yearChange(row.value.revenue, row.value.previous_revenue)) }}</td></template><td v-else colspan="5" class="text-neutral-500">{{ stateText(row.company, 'financial') }}</td></tr>
            </tbody></table></div>
          </section>
          <section class="panel space-y-4">
            <h2>{{ t('group_stats.accounting_result') }}</h2><p class="text-sm text-neutral-500">{{ t('group_stats.accounting_note') }}</p>
            <div class="table-scroll"><table data-test="group-accounting-totals"><thead><tr><th>{{ t('group_stats.period') }}</th><th>{{ t('group_stats.currency') }}</th><th>{{ t('group_stats.revenue') }}</th><th>{{ t('group_stats.costs') }}</th><th>{{ t('group_stats.profit') }}</th><th>{{ t('group_stats.coverage_label') }}</th></tr></thead><tbody><tr v-for="total in report.totals.accounting" :key="accountingGroupKey(total)"><td>{{ date(total.from) }} - {{ date(total.to) }}</td><td>{{ total.currency }}</td><td>{{ money(total.revenue, total.currency) }}</td><td>{{ money(total.costs, total.currency) }}</td><td :class="resultClass(total.profit)">{{ money(total.profit, total.currency) }}</td><td class="text-xs">{{ partialText(total) }}</td></tr><tr v-if="!report.totals.accounting.length"><td colspan="6" class="text-neutral-500">{{ t('group_stats.no_total_hint') }}</td></tr></tbody></table></div>
            <div class="table-scroll"><table data-test="group-accounting-table"><thead><tr><th>{{ t('group_stats.company') }}</th><th>{{ t('group_stats.period') }}</th><th>{{ t('group_stats.revenue') }}</th><th>{{ t('group_stats.costs') }}</th><th>{{ t('group_stats.profit') }}</th></tr></thead><tbody><tr v-for="row in accountingRows" :key="row.company.id"><td class="font-medium">{{ row.company.name }}</td><template v-if="row.value"><td>{{ date(row.value.from) }} - {{ date(row.value.to) }}</td><td>{{ money(row.value.revenue, row.value.currency) }}</td><td>{{ money(row.value.costs, row.value.currency) }}</td><td :class="resultClass(row.value.profit)">{{ money(row.value.profit, row.value.currency) }}</td></template><td v-else colspan="4" class="text-neutral-500">{{ stateText(row.company, 'accounting') }}</td></tr></tbody></table></div>
          </section>
        </template>
        <template v-else-if="active === 'trends'">
          <section class="panel space-y-4">
            <h2>{{ t('group_stats.monthly_trends') }} <span class="text-neutral-500">{{ currency }}</span></h2>
            <p v-if="selected && metricState(selected, 'monthly') !== 'available'" class="text-warning-700">{{ stateText(selected, 'monthly') }}</p>
            <GroupDashboardChart v-else :labels="monthlyRows.map(row => row.period)" :series="monthlySeries" :currency="currency" :title="t('group_stats.monthly_trends')" type="line" />
            <p v-if="monthlyCoverage.some(row => row.missing_values > 0)" class="text-sm text-warning-700">{{ t('group_stats.partial_trend') }}</p>
            <div class="table-scroll"><table data-test="group-monthly-table"><thead><tr><th>{{ t('group_stats.month') }}</th><th>{{ t('group_stats.revenue') }}</th><th>{{ t('group_stats.costs') }}</th><th>{{ t('group_stats.profit') }}</th><th>{{ t('group_stats.margin') }}</th></tr></thead><tbody><tr v-for="row in monthlyRows" :key="row.period"><td>{{ row.period }}</td><td>{{ money(row.revenue, currency) }}</td><td>{{ money(row.costs, currency) }}</td><td :class="resultClass(row.profit)">{{ money(row.profit, currency) }}</td><td>{{ pct(margin(row.revenue, row.profit)) }}</td></tr></tbody></table></div>
          </section>
          <section class="panel space-y-3"><h2>{{ t('group_stats.company_period_comparison') }}</h2><p class="text-sm text-neutral-500">{{ t('group_stats.trend_comparison_hint', { from: date(report.period.from), to: date(report.period.to) }) }}</p>
            <div class="table-scroll"><table><thead><tr><th>{{ t('group_stats.company') }}</th><th>{{ t('group_stats.basis') }}</th><th>{{ t('group_stats.revenue') }}</th><th>{{ t('group_stats.costs') }}</th><th>{{ t('group_stats.profit') }}</th></tr></thead><tbody><tr v-for="row in trendCompanies" :key="row.company.id"><td class="font-medium">{{ row.company.name }}</td><td>{{ t(`group_stats.basis_${row.company.document_basis}`) }}</td><template v-if="row.rows?.length"><td>{{ money(monthlySum(row.rows, 'revenue'), currency) }}</td><td>{{ money(monthlySum(row.rows, 'costs'), currency) }}</td><td :class="resultClass(monthlySum(row.rows, 'profit'))">{{ money(monthlySum(row.rows, 'profit'), currency) }}</td></template><td v-else colspan="3" class="text-neutral-500">{{ stateText(row.company, 'monthly') }}</td></tr></tbody></table></div>
          </section>
        </template>
        <GroupForecast v-else-if="active === 'forecast'" :report="report" :currency="currency" />
        <template v-else-if="active === 'cashflow'">
          <p class="text-sm text-neutral-500">{{ t('group_stats.cashflow_note') }}</p>
          <details v-if="cashflowExclusions.length" class="rounded-lg border border-warning-200 bg-warning-50 px-4 py-3 text-sm text-warning-800" data-test="group-cashflow-exclusions">
            <summary class="cursor-pointer font-medium">{{ t('group_stats.cashflow_exclusions') }}</summary>
            <ul class="mt-2 space-y-1"><li v-for="company in cashflowExclusions" :key="company.id">{{ company.name }}: <span v-if="!company.available.cashflow_tax">{{ t('group_stats.tax_excluded') }}</span><span v-if="!company.available.cashflow_tax && !company.available.cashflow_payroll"> · </span><span v-if="!company.available.cashflow_payroll">{{ t('group_stats.payroll_excluded') }}</span></li></ul>
          </details>
          <div v-if="cashTotals" class="grid gap-3 sm:grid-cols-3"><article v-for="metric in (['in', 'out', 'net'] as const)" :key="metric" class="panel"><p class="text-sm text-neutral-500">{{ t(`group_stats.cash_${metric}`) }}</p><p class="mt-2 text-xl font-bold tabular-nums" :class="metric === 'net' ? resultClass(cashTotals[metric]) : 'text-neutral-900'">{{ money(cashTotals[metric], currency) }}</p></article></div>
          <section class="panel space-y-4"><h2>{{ t('group_stats.weekly_forecast') }} <span class="text-neutral-500">{{ currency }}</span></h2>
            <p v-if="selected && metricState(selected, 'cashflow') !== 'available'" class="text-warning-700">{{ stateText(selected, 'cashflow') }}</p>
            <GroupDashboardChart v-else :labels="cashRows.map(row => date(row.week_start))" :series="cashSeries" :currency="currency" :title="t('group_stats.weekly_forecast')" type="line" />
            <div class="table-scroll"><table data-test="group-cashflow-table"><thead><tr><th>{{ t('group_stats.week') }}</th><th>{{ t('group_stats.inflow') }}</th><th>{{ t('group_stats.outflow') }}</th><th>{{ t('group_stats.net') }}</th><th>{{ t('group_stats.running') }}</th><th v-if="selectedCompany === 'all'">{{ t('group_stats.coverage_label') }}</th></tr></thead><tbody><tr v-for="row in cashRows" :key="row.week_start"><td>{{ date(row.week_start) }} - {{ date(row.week_end) }}</td><td>{{ money(row.in, currency) }}</td><td>{{ money(row.out, currency) }}</td><td :class="resultClass(row.net)">{{ money(row.net, currency) }}</td><td :class="resultClass(row.running)">{{ money(row.running, currency) }}</td><td v-if="selectedCompany === 'all'" class="text-xs" :class="row.missing_values ? 'text-warning-700' : ''">{{ partialText({ companies: row.companies!, missing_values: row.missing_values! }) }}</td></tr></tbody></table></div>
          </section>
          <section class="panel space-y-3"><h2>{{ t('group_stats.company_comparison') }}</h2><div class="table-scroll"><table><thead><tr><th>{{ t('group_stats.company') }}</th><th>{{ t('group_stats.inflow') }}</th><th>{{ t('group_stats.outflow') }}</th><th>{{ t('group_stats.net') }}</th></tr></thead><tbody><tr v-for="company in report.companies" :key="company.id"><td class="font-medium">{{ company.name }}</td><template v-if="company.cashflow?.some(row => row.currency === currency)"><td>{{ money(company.cashflow.find(row => row.currency === currency)?.total_in, currency) }}</td><td>{{ money(company.cashflow.find(row => row.currency === currency)?.total_out, currency) }}</td><td :class="resultClass(company.cashflow.find(row => row.currency === currency)?.total_net)">{{ money(company.cashflow.find(row => row.currency === currency)?.total_net, currency) }}</td></template><td v-else colspan="3" class="text-neutral-500">{{ stateText(company, 'cashflow') }}</td></tr></tbody></table></div></section>
        </template>
        <template v-else-if="active === 'balances'">
          <p class="text-sm text-neutral-500">{{ t('group_stats.balance_note') }}</p>
          <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3"><template v-for="field in (['bank', 'cash'] as const)" :key="field"><article v-for="total in report.totals[field].filter(row => row.currency === currency)" :key="`${field}:${total.currency}`" class="panel"><p class="text-sm text-neutral-500">{{ t(`group_stats.fields.${field}`) }} · {{ total.currency }}</p><p class="mt-2 text-xl font-bold tabular-nums" :class="resultClass(total.balance)">{{ total.balance === null ? t('group_stats.unknown_balance') : money(total.balance, total.currency) }}</p><p class="mt-2 text-xs" :class="total.missing_values ? 'text-warning-700' : 'text-neutral-500'">{{ partialText(total) }}</p></article></template></div>
          <p v-if="unknownBalances.length" class="rounded border border-warning-200 bg-warning-50 px-4 py-3 text-sm text-warning-700">{{ t('group_stats.unknown_balances', { count: unknownBalances.length }) }}</p>
          <section class="panel space-y-4"><h2>{{ t('group_stats.accounts_balances') }}</h2><div class="table-scroll"><table data-test="group-balances-table"><thead><tr><th>{{ t('group_stats.company') }}</th><th>{{ t('group_stats.account') }}</th><th>{{ t('group_stats.type') }}</th><th>{{ t('group_stats.currency') }}</th><th>{{ t('group_stats.balance') }}</th><th>{{ t('group_stats.balance_date') }}</th></tr></thead><tbody><tr v-for="row in balances" :key="`${row.company.id}:${row.field}:${row.value.id}`"><td class="font-medium">{{ row.company.name }}</td><td>{{ row.value.name }}</td><td>{{ t(`group_stats.fields.${row.field}`) }}</td><td>{{ row.value.currency }}<span v-if="row.value.source_currency && row.value.source_currency !== row.value.currency" class="text-neutral-500"> ({{ row.value.source_currency }})</span></td><td :class="resultClass(row.value.balance)">{{ row.value.balance === null ? t('group_stats.unknown_balance') : money(row.value.balance, row.value.currency) }}</td><td>{{ date(row.value.date) }}</td></tr><tr v-if="!balances.length"><td colspan="6" class="text-neutral-500">{{ t('group_stats.no_data') }}</td></tr></tbody></table></div></section>
        </template>
        <template v-else-if="active === 'receivables'">
          <section class="panel space-y-4"><h2>{{ t('group_stats.aging_title') }} <span class="text-neutral-500">{{ currency }}</span></h2><p class="text-sm text-neutral-500">{{ t('group_stats.aging_note') }}</p>
            <GroupDashboardChart :labels="buckets.map(bucket => t(`group_stats.buckets.${bucket}`))" :series="agingSeries" :currency="currency" :title="t('group_stats.aging_title')" />
            <div class="table-scroll"><table data-test="group-aging-totals"><thead><tr><th>{{ t('group_stats.type') }}</th><th>{{ t('group_stats.aging_bucket') }}</th><th>{{ t('group_stats.documents') }}</th><th>{{ t('group_stats.amount') }}</th><th>{{ t('group_stats.coverage_label') }}</th></tr></thead><tbody><template v-for="field in (['receivables', 'payables'] as const)" :key="field"><tr v-for="row in agingRows(field)" :key="`${field}:${row.bucket}`"><td>{{ t(`group_stats.${field}`) }}</td><td>{{ t(`group_stats.buckets.${row.bucket}`) }}</td><td>{{ row.count }}</td><td>{{ money(row.total, row.currency) }}</td><td class="text-xs" :class="row.missing_values ? 'text-warning-700' : ''">{{ partialText(row) }}</td></tr></template></tbody></table></div>
          </section>
          <div class="grid items-start gap-4 xl:grid-cols-2"><article v-for="company in report.companies" :key="company.id" class="panel space-y-3"><h2>{{ company.name }}</h2><template v-for="field in (['receivables', 'payables'] as const)" :key="field"><h3 class="text-sm font-semibold text-neutral-700">{{ t(`group_stats.${field}`) }}</h3><p v-if="!companyAging(company, field).length" class="text-sm text-neutral-500">{{ stateText(company, field) }}</p><div v-else class="table-scroll"><table><tbody><tr v-for="row in companyAging(company, field)" :key="row.bucket"><td>{{ t(`group_stats.buckets.${row.bucket}`) }}</td><td>{{ t('group_stats.document_count', { count: row.count }) }}</td><td>{{ money(row.total, row.currency) }}</td></tr></tbody></table></div></template></article></div>
        </template>
        <template v-else-if="active === 'risks'">
          <p v-if="report.concentration_period" class="text-xs text-neutral-500">{{ t('group_stats.concentration_window', { from: date(report.concentration_period.from) }) }}</p>
          <EmptyState v-if="!knownRiskCompanies.length" :title="t('group_stats.no_data')" :message="t('group_stats.no_total_hint')" />
          <div v-else class="grid gap-3 sm:grid-cols-3"><article class="panel"><p class="text-sm text-neutral-500">{{ t('group_stats.companies_with_risks') }}</p><p class="mt-2 text-2xl font-bold text-warning-600">{{ riskCompanies }}</p></article><article class="panel"><p class="text-sm text-neutral-500">{{ t('group_stats.high_risks') }}</p><p class="mt-2 text-2xl font-bold text-danger-600">{{ highRisks }}</p></article><article class="panel"><p class="text-sm text-neutral-500">{{ t('group_stats.risk_count') }}</p><p class="mt-2 text-2xl font-bold text-neutral-900">{{ riskRows.length }}</p></article></div>
          <section class="panel space-y-4"><h2>{{ t('group_stats.risk_comparison') }}</h2><p class="text-sm text-neutral-500">{{ t('group_stats.risk_note') }}</p>
            <GroupDashboardChart :labels="report.companies.filter(company => company.risks !== null && company.risks !== undefined).map(company => company.name)" :series="riskSeries" :title="t('group_stats.risk_comparison')" horizontal />
            <div class="table-scroll"><table data-test="group-risks-table"><thead><tr><th>{{ t('group_stats.company') }}</th><th>{{ t('group_stats.risk') }}</th><th>{{ t('group_stats.aging_bucket') }}</th><th>{{ t('group_stats.amount_share') }}</th><th>{{ t('group_stats.documents') }}</th><th>{{ t('group_stats.severity') }}</th><th>{{ t('group_stats.detail') }}</th></tr></thead><tbody><tr v-for="(row, index) in riskRows" :key="`${row.company.id}:${row.risk.kind}:${index}`"><td class="font-medium">{{ row.company.name }}</td><td>{{ t(`group_stats.risk_kinds.${row.risk.kind}`) }}<span v-if="row.risk.percentage !== undefined && row.risk.source_currency" class="text-neutral-500"> ({{ row.risk.source_currency }})</span></td><td>{{ row.risk.bucket ? t(`group_stats.buckets.${row.risk.bucket}`) : '-' }}</td><td>{{ riskValue(row.risk) }}</td><td>{{ row.risk.count ?? '-' }}</td><td><span v-if="row.risk.level" class="rounded px-2 py-1 text-xs font-medium" :class="row.risk.level === 'high' ? 'bg-danger-50 text-danger-600' : 'bg-warning-50 text-warning-700'">{{ t(`group_stats.level_${row.risk.level}`) }}</span><span v-else>-</span></td><td><button type="button" :class="btnOutline('neutral')" :disabled="switching" data-test="group-open-risk" @click="openRisk(row.company, row.risk)"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path :d="ICONS.search" /></svg>{{ t('group_stats.detail') }}</button></td></tr><tr v-if="!riskRows.length && knownRiskCompanies.length"><td colspan="7" class="text-neutral-500">{{ t('group_stats.no_risks') }}</td></tr></tbody></table></div>
          </section>
        </template>
      </template>
    </div>
  </div>
</template>

<style scoped>
@reference "../styles/main.css";
.panel { @apply min-w-0 rounded-lg border border-neutral-200 bg-surface p-4 shadow-sm sm:p-5; }
h2 { @apply text-base font-semibold text-neutral-900; }
.table-scroll { @apply max-w-full overflow-x-auto; }
table { @apply w-full text-sm; }
th { @apply whitespace-nowrap border-b border-neutral-200 px-3 py-2 text-left text-xs font-medium text-neutral-500; }
td { @apply border-b border-neutral-100 px-3 py-3 align-top tabular-nums; }
td:not(:first-child) { @apply whitespace-nowrap; }
tbody tr:last-child td { @apply border-b-0; }
.input { @apply h-9 rounded-md border border-neutral-300 bg-surface px-3 text-neutral-900; }
</style>

<script setup lang="ts">
import { ref, onMounted, reactive, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import {
  accountingApi,
  type AccountingPeriod,
  type TrialBalanceReport,
  type TrialBalanceRow,
} from '@/api/accounting'
import { useToast } from '@/composables/useToast'
import { formatMoney } from '@/composables/useFormat'
import ColumnPicker from '@/components/ui/ColumnPicker.vue'
import SortableTh from '@/components/ui/SortableTh.vue'
import DensityToggle from '@/components/ui/DensityToggle.vue'
import { useTablePrefs, type ColumnDef } from '@/composables/useTablePrefs'
import { ensurePrefsLoaded } from '@/composables/useUserPrefs'
import { ICONS, btnOutline } from '@/components/ui/buttonStyles'
import ActivationBanner from '@/components/settings/activation/ActivationBanner.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { findAccountingPeriod } from '@/utils/accountingPeriod'
import DateInput from '@/components/ui/DateInput.vue'
import DimensionReportFilters from '@/components/dimensions/DimensionReportFilters.vue'
import { dimensionValueIdsParam, parseDimensionValueIds } from '@/utils/dimensionFilter'
import DimensionReportLinks from '@/components/dimensions/DimensionReportLinks.vue'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const toast = useToast()

const periods = ref<AccountingPeriod[]>([])
const report = ref<TrialBalanceReport | null>(null)
const loading = ref(false)
const rangeAdjusted = ref(false)

const filters = reactive({
  period_id: '' as number | '',
  from: '',
  to: '',
  analytics: false,
  after_closing: false,
  dimension_value_ids: [] as number[],
  dimension_descendants: true,
})

function queryParams() {
  return {
    period_id: Number(filters.period_id),
    from: filters.from || undefined,
    to: filters.to || undefined,
    analytics: filters.analytics ? (1 as const) : undefined,
    after_closing: filters.after_closing ? (1 as const) : undefined,
    ...(filters.dimension_value_ids.length > 0
      ? { dimension_value_id: dimensionValueIdsParam(filters.dimension_value_ids), dimension_descendants: (filters.dimension_descendants ? 1 : 0) as 0 | 1 }
      : {}),
  }
}

function onDimensionValues(valueIds: number[]) {
  filters.dimension_value_ids = valueIds
  void load()
}

function onDimensionDescendants(value: boolean) {
  filters.dimension_descendants = value
  void load()
}

async function load() {
  if (!filters.period_id) return
  loading.value = true
  try {
    report.value = await accountingApi.getTrialBalance(queryParams())
    void router.replace({ query: {
      period_id: String(filters.period_id),
      from: filters.from || report.value.from,
      to: filters.to || report.value.to,
      analytics: filters.analytics ? '1' : '0',
      ...(filters.after_closing ? { after_closing: '1' } : {}),
      ...(filters.dimension_value_ids.length > 0 ? {
        dimension_value_id: dimensionValueIdsParam(filters.dimension_value_ids),
        dimension_descendants: filters.dimension_descendants ? '1' : '0',
      } : {}),
    } })
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
    report.value = null
  } finally {
    loading.value = false
  }
}

function onPeriodChange() {
  rangeAdjusted.value = false
  const period = findAccountingPeriod(periods.value, filters.period_id)
  if (!period) return
  filters.from = period.starts_on
  filters.to = period.ends_on
  void load()
}

function statementLink(row: TrialBalanceRow) {
  return {
    name: 'accounting-account-statement',
    params: { accountId: row.account_id },
    query: { from: report.value?.from, to: report.value?.to },
  }
}

const exporting = ref(false)
async function exportFile(format: 'pdf' | 'xlsx') {
  if (!filters.period_id || !report.value) return
  exporting.value = true
  try {
    const r = await accountingApi.exportReport('/accounting/reports/trial-balance/export', { ...queryParams(), format })
    downloadBlob(r.data as unknown as Blob, `obratova-predvaha-${report.value.period.fiscal_year}.${format}`)
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  } finally {
    exporting.value = false
  }
}

function downloadBlob(blob: Blob, filename: string) {
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = filename
  document.body.appendChild(a); a.click(); a.remove()
  URL.revokeObjectURL(url)
}

const COLUMNS: ColumnDef[] = [
  { key: 'account', labelKey: 'accounting.trial_balance.col_account', required: true },
  { key: 'name', labelKey: 'accounting.trial_balance.col_name', required: true },
  { key: 'account_type', labelKey: 'accounting.general_ledger.col_type', defaultHidden: true },
  { key: 'ps_md', labelKey: 'accounting.trial_balance.col_ps_md' },
  { key: 'ps_d', labelKey: 'accounting.trial_balance.col_ps_d' },
  { key: 'turnover_md', labelKey: 'accounting.trial_balance.col_turnover_md' },
  { key: 'turnover_d', labelKey: 'accounting.trial_balance.col_turnover_d' },
  { key: 'ks_md', labelKey: 'accounting.trial_balance.col_ks_md' },
  { key: 'ks_d', labelKey: 'accounting.trial_balance.col_ks_d' },
]
const tbl = useTablePrefs('trial_balance', COLUMNS)
const sortedRows = computed(() => {
  const rows = report.value?.rows ?? []
  const sort = tbl.sort.value
  if (!sort) return rows
  const key = sort.key === 'account' ? 'account_code' : sort.key
  return [...rows].sort((a, b) => {
    const left = a[key as keyof TrialBalanceRow]
    const right = b[key as keyof TrialBalanceRow]
    const cmp = typeof left === 'number' && typeof right === 'number'
      ? left - right
      : String(left).localeCompare(String(right), undefined, { numeric: true })
    return (sort.dir === 'asc' ? 1 : -1) * (cmp || a.account_code.localeCompare(b.account_code))
  })
})

// Rozpad po analytikách je volba pohledu, ne filtr dat — firma, která analytiky
// vede, je chce vidět pokaždé. Bez zapamatování předvaha default zobrazí holé
// syntetiky a účetní z toho čte, že analytiky v systému nejsou.
function onAnalyticsToggle() {
  tbl.setFlag('analytics', filters.analytics)
  return load()
}

onMounted(async () => {
  await ensurePrefsLoaded()
  filters.analytics = tbl.flag('analytics')
  try { periods.value = await accountingApi.listPeriods() } catch { periods.value = [] }
  const open = periods.value.filter(p => p.status === 'open')
  const def = open.length
    ? open.reduce((a, b) => (b.fiscal_year > a.fiscal_year ? b : a))
    : periods.value[0]
  const q = route.query
  const fromQuery = typeof q.from === 'string' ? q.from : ''
  const toQuery = typeof q.to === 'string' ? q.to : ''
  const queryPeriod = periods.value.find(p => p.starts_on <= fromQuery && p.ends_on >= toQuery)
  const explicitPeriod = periods.value.find(p => p.id === Number(q.period_id))
  const selectedPeriod = explicitPeriod ?? queryPeriod
    ?? periods.value.find(p => p.starts_on <= toQuery && p.ends_on >= toQuery)
    ?? periods.value.find(p => p.starts_on <= fromQuery && p.ends_on >= fromQuery)
    ?? def
  if (selectedPeriod) {
    rangeAdjusted.value = !!fromQuery && !!toQuery && !queryPeriod
    filters.period_id = selectedPeriod.id
    if (fromQuery) filters.from = fromQuery < selectedPeriod.starts_on ? selectedPeriod.starts_on : fromQuery > selectedPeriod.ends_on ? selectedPeriod.ends_on : fromQuery
    if (toQuery) filters.to = toQuery > selectedPeriod.ends_on ? selectedPeriod.ends_on : toQuery < selectedPeriod.starts_on ? selectedPeriod.starts_on : toQuery
    filters.dimension_value_ids = parseDimensionValueIds(q.dimension_value_id)
    filters.dimension_descendants = q.dimension_descendants !== '0'
    if (q.analytics === '1' || q.analytics === '0') filters.analytics = q.analytics === '1'
    filters.after_closing = q.after_closing === '1'
    await load()
  }
})
</script>

<template>
  <div>
    <ActivationBanner />
    <div class="flex flex-wrap items-center justify-between gap-2 mb-4">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('accounting.trial_balance.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5">{{ t('accounting.trial_balance.subtitle') }}</p>
      </div>
      <div class="flex flex-wrap items-center gap-2">
        <ColumnPicker class="hidden md:block" :ctrl="tbl" />
        <DensityToggle class="hidden md:block" :ctrl="tbl" />
        <button :disabled="!report || exporting" @click="exportFile('pdf')" :class="btnOutline('primary')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.download" /></svg>
          {{ t('accounting.trial_balance.export_pdf') }}
        </button>
        <button :disabled="!report || exporting" @click="exportFile('xlsx')" :class="btnOutline('primary')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.download" /></svg>
          {{ t('accounting.trial_balance.export_xlsx') }}
        </button>
      </div>
    </div>

    <!-- Filtry -->
    <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-3 mb-4">
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
        <div>
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.trial_balance.filter_period') }}</label>
          <select v-model="filters.period_id" @change="onPeriodChange"
            class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface">
            <option v-for="p in periods" :key="p.id" :value="p.id">{{ p.fiscal_year }}</option>
          </select>
        </div>
        <div>
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.trial_balance.filter_from') }}</label>
          <DateInput v-model="filters.from" @change="rangeAdjusted = false; load()"
            class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm" />
        </div>
        <div>
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.trial_balance.filter_to') }}</label>
          <DateInput v-model="filters.to" @change="rangeAdjusted = false; load()"
            class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm" />
        </div>
        <div class="flex items-end pb-2">
          <label class="inline-flex items-center gap-2 text-sm cursor-pointer">
            <input v-model="filters.analytics" type="checkbox" @change="onAnalyticsToggle" class="rounded border-neutral-300" />
            {{ t('accounting.trial_balance.filter_analytics') }}
          </label>
        </div>
        <!-- Výchozí je stav PŘED uzavřením knih; po uzavření jsou rozvahové účty
             převedené na 702 a konečné stavy vyjdou nulové. -->
        <div class="flex items-end pb-2">
          <label class="inline-flex items-center gap-2 text-sm cursor-pointer">
            <input v-model="filters.after_closing" type="checkbox" @change="load" class="rounded border-neutral-300" />
            {{ t('accounting.reports.after_closing') }}
          </label>
        </div>
      </div>
      <DimensionReportFilters class="mt-3"
        :value-ids="filters.dimension_value_ids" :descendants="filters.dimension_descendants"
        @update:value-ids="onDimensionValues" @update:descendants="onDimensionDescendants" />
    </div>

    <DimensionReportLinks current="trial" :from="filters.from || report?.from || ''" :to="filters.to || report?.to || ''"
      :value-ids="filters.dimension_value_ids" :descendants="filters.dimension_descendants" />
    <p v-if="rangeAdjusted" class="mb-3 rounded-md border border-warning-500/30 bg-warning-50 px-3 py-2 text-xs text-warning-700" data-test="trial-range-adjusted">{{ t('dimensions.trial_range_adjusted') }}</p>

    <p v-if="report?.dimension" class="mb-4 rounded-md border border-primary-200 bg-primary-50 px-3 py-2 text-xs text-primary-800">
      {{ t('dimensions.filter_active_note') }}
    </p>

    <div v-if="report && report.draft_count > 0"
      class="mb-4 px-3 py-2 rounded-md bg-warning-50 border border-warning-500/30 text-warning-600 text-sm">
      {{ t('accounting.trial_balance.draft_warning', { n: report.draft_count }) }}
    </div>

    <!-- Kontroly -->
    <div v-if="report" class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-3 mb-4">
      <div class="text-xs text-neutral-500 uppercase tracking-wide font-medium mb-2">{{ t('accounting.trial_balance.checks_title') }}</div>
      <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 text-sm">
        <div class="flex items-center gap-2">
          <span :class="report.checks.turnover_balanced ? 'text-success-600' : 'text-danger-500'" class="font-semibold">
            {{ report.checks.turnover_balanced ? '✓' : '✗' }}
          </span>
          {{ t('accounting.trial_balance.check_turnover') }}
        </div>
        <div class="flex items-center gap-2">
          <span :class="report.checks.matches_journal ? 'text-success-600' : 'text-danger-500'" class="font-semibold">
            {{ report.checks.matches_journal ? '✓' : '✗' }}
          </span>
          <span>
            {{ t('accounting.trial_balance.check_journal') }}
            <span class="block text-xs text-neutral-500">
              {{ t('accounting.trial_balance.journal_turnover', { md: formatMoney(report.checks.journal_turnover_md), d: formatMoney(report.checks.journal_turnover_d) }) }}
            </span>
          </span>
        </div>
        <div class="flex items-center gap-2">
          <span :class="report.checks.opening_balanced ? 'text-success-600' : 'text-danger-500'" class="font-semibold">
            {{ report.checks.opening_balanced ? '✓' : '✗' }}
          </span>
          {{ t('accounting.trial_balance.check_continuity') }}
        </div>
      </div>
    </div>

    <div v-if="loading" class="text-center text-neutral-500 py-12 text-sm">{{ t('common.loading') }}</div>

    <EmptyState v-else-if="!report || report.rows.length === 0" boxed accent="neutral" icon="chart" :title="t('accounting.trial_balance.empty')" />

    <div v-else class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
      <div class="overflow-x-auto">
        <table v-column-labels="tbl" class="w-full text-sm" :class="tbl.densityClass.value">
          <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
            <tr>
              <SortableTh v-for="c in COLUMNS.filter(c => tbl.isVisible(c.key))" :key="c.key"
                :label="t(c.labelKey)" :sort-key="c.key" :sort="tbl.sort.value"
                :align="['account', 'name', 'account_type'].includes(c.key) ? 'left' : 'right'"
                @toggle="tbl.toggleSort" />
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="row in sortedRows" :key="row.account_id" class="hover:bg-neutral-50">
              <td v-if="tbl.isVisible('account')" class="px-3 py-2">
                <RouterLink :to="statementLink(row)"
                  class="font-mono text-primary-600 hover:text-primary-700 hover:underline">
                  {{ row.account_code }}
                </RouterLink>
              </td>
              <td v-if="tbl.isVisible('name')" class="px-3 py-2">{{ row.name }}</td>
              <td v-if="tbl.isVisible('account_type')" class="px-3 py-2 text-neutral-600 whitespace-nowrap">{{ t(`accounting.accounts.type.${row.account_type}`) }}</td>
              <td v-if="tbl.isVisible('ps_md')" class="px-3 py-2 text-right font-mono">{{ formatMoney(row.ps_md) }}</td>
              <td v-if="tbl.isVisible('ps_d')" class="px-3 py-2 text-right font-mono">{{ formatMoney(row.ps_d) }}</td>
              <td v-if="tbl.isVisible('turnover_md')" class="px-3 py-2 text-right font-mono">{{ formatMoney(row.turnover_md) }}</td>
              <td v-if="tbl.isVisible('turnover_d')" class="px-3 py-2 text-right font-mono">{{ formatMoney(row.turnover_d) }}</td>
              <td v-if="tbl.isVisible('ks_md')" class="px-3 py-2 text-right font-mono">{{ formatMoney(row.ks_md) }}</td>
              <td v-if="tbl.isVisible('ks_d')" class="px-3 py-2 text-right font-mono">{{ formatMoney(row.ks_d) }}</td>
            </tr>
          </tbody>
          <tfoot>
            <tr class="border-t-2 border-neutral-300 font-semibold bg-neutral-50">
              <td class="px-3 py-2" colspan="2">{{ t('accounting.trial_balance.totals') }}</td>
              <td v-if="tbl.isVisible('account_type')" class="px-3 py-2"></td>
              <td v-if="tbl.isVisible('ps_md')" class="px-3 py-2 text-right font-mono">{{ formatMoney(report.totals.ps_md) }}</td>
              <td v-if="tbl.isVisible('ps_d')" class="px-3 py-2 text-right font-mono">{{ formatMoney(report.totals.ps_d) }}</td>
              <td v-if="tbl.isVisible('turnover_md')" class="px-3 py-2 text-right font-mono">{{ formatMoney(report.totals.turnover_md) }}</td>
              <td v-if="tbl.isVisible('turnover_d')" class="px-3 py-2 text-right font-mono">{{ formatMoney(report.totals.turnover_d) }}</td>
              <td v-if="tbl.isVisible('ks_md')" class="px-3 py-2 text-right font-mono">{{ formatMoney(report.totals.ks_md) }}</td>
              <td v-if="tbl.isVisible('ks_d')" class="px-3 py-2 text-right font-mono">{{ formatMoney(report.totals.ks_d) }}</td>
            </tr>
          </tfoot>
        </table>
      </div>
    </div>
  </div>
</template>

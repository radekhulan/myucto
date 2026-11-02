<script setup lang="ts">
import { ref, onMounted, reactive, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import {
  accountingApi,
  isStatement504,
  type Statement504Report,
  type AccountingPeriod,
  type BalanceSheetReport,
  type EntityCategory,
  type StatementScope,
  type StatementRowAccount,
  type StatementAccountsReport,
  type StatementParams,
} from '@/api/accounting'
import Statement504Table from '@/components/accounting/Statement504Table.vue'
import StatementAccountsTable from '@/components/accounting/StatementAccountsTable.vue'
import { useToast } from '@/composables/useToast'
import { formatMoney } from '@/composables/useFormat'
import { ICONS, btnOutline } from '@/components/ui/buttonStyles'
import ActivationBanner from '@/components/settings/activation/ActivationBanner.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { findAccountingPeriod } from '@/utils/accountingPeriod'
import DateInput from '@/components/ui/DateInput.vue'
import DimensionReportFilter from '@/components/dimensions/DimensionReportFilter.vue'
import DimensionReportLinks from '@/components/dimensions/DimensionReportLinks.vue'
import AccrualSpreadToggle from '@/components/accounting/AccrualSpreadToggle.vue'
import AccrualSpreadNote from '@/components/accounting/AccrualSpreadNote.vue'
import { useAccrualSpread } from '@/composables/useAccrualSpread'

const { t, locale } = useI18n()
const route = useRoute()
const router = useRouter()
const toast = useToast()
const accrualSpread = useAccrualSpread()
const accrualSpreadOn = accrualSpread.enabled

function onAccrualSpread(value: boolean) {
  accrualSpread.set(value)
  void load()
}

// Klientský přepínač Kč / tis. Kč (F4 R17) — jen formátování při renderu, data beze změny.
const unit = ref<'czk' | 'thousands'>('czk')
function fm(value: number | null | undefined): string {
  if (unit.value === 'czk') return formatMoney(value)
  if (value === null || value === undefined || Number.isNaN(value)) return '—'
  if (view.value === 'accounts' ? accounts504.value : report504.value) {
    return new Intl.NumberFormat(locale.value === 'en' ? 'en-US' : 'cs-CZ', { maximumFractionDigits: 0 })
      .format(Math.sign(value) * Math.round(Math.abs(value) / 1000))
  }
  return new Intl.NumberFormat(locale.value === 'en' ? 'en-US' : 'cs-CZ', { maximumFractionDigits: 0 })
    .format(Math.round(value / 1000))
}

const periods = ref<AccountingPeriod[]>([])
const report = ref<BalanceSheetReport | null>(null)
const report504 = ref<Statement504Report | null>(null)
const accounts504 = ref<Statement504Report | null>(null)
const view = ref<'statement' | 'accounts'>('statement')
const accountsReport = ref<StatementAccountsReport | null>(null)
const hasData = computed(() => view.value === 'accounts' ? !!(accountsReport.value || accounts504.value) : !!(report.value || report504.value))
const category = ref<EntityCategory | null>(null)
const loading = ref(false)
const rangeAdjusted = ref(false)

const filters = reactive({
  period_id: '' as number | '',
  as_of: '',
  scope: 'auto' as StatementScope,
  dimension_value_id: null as number | null,
  dimension_descendants: true,
})
const selectedPeriod = computed(() => findAccountingPeriod(periods.value, filters.period_id))

function queryParams() {
  return {
    period_id: Number(filters.period_id),
    as_of: filters.as_of || undefined,
    scope: filters.scope,
    ...(filters.dimension_value_id
      ? { dimension_value_id: filters.dimension_value_id, dimension_descendants: (filters.dimension_descendants ? 1 : 0) as 0 | 1 }
      : {}),
    ...accrualSpread.param(),
  }
}

const hideZero = ref(true)
const showAnalytics = ref(true)

function accountsParams(): Omit<StatementParams, 'scope'> {
  const { period_id, as_of, dimension_value_id, dimension_descendants } = queryParams() as StatementParams
  return {
    period_id, as_of, dimension_value_id, dimension_descendants,
    columns: 1, hide_zero: hideZero.value ? 1 : 0, analytics: showAnalytics.value ? 1 : 0,
  }
}

function onHideZero(value: boolean) {
  hideZero.value = value
  void load()
}

function onShowAnalytics(value: boolean) {
  showAnalytics.value = value
  void load()
}

function switchView(next: 'statement' | 'accounts') {
  if (view.value === next) return
  view.value = next
  void load()
}

function onDimensionValue(valueId: number | null) {
  filters.dimension_value_id = valueId
  void load()
}

function onDimensionDescendants(value: boolean) {
  filters.dimension_descendants = value
  void load()
}

async function load() {
  if (!filters.period_id) return
  loading.value = true
  expandedRowCode.value = null
  try {
    if (view.value === 'accounts') {
      const result = await accountingApi.getStatementAccounts(accountsParams())
      accounts504.value = isStatement504(result) ? result : null
      accountsReport.value = isStatement504(result) ? null : result
    } else {
      const result = await accountingApi.getBalanceSheet(queryParams())
      report504.value = isStatement504(result) ? result : null
      report.value = isStatement504(result) ? null : result
    }
    if (view.value === 'statement' && filters.scope === 'auto') {
      try {
        category.value = await accountingApi.getEntityCategory(Number(filters.period_id))
      } catch {
        category.value = null
      }
    } else {
      category.value = null
    }
    void router.replace({ query: {
      period_id: String(filters.period_id),
      from: selectedPeriod.value?.starts_on ?? '',
      to: filters.as_of || selectedPeriod.value?.ends_on || '',
      ...(filters.scope !== 'auto' ? { scope: filters.scope } : {}),
      ...(view.value === 'accounts' ? { view: 'accounts' } : {}),
      ...(filters.dimension_value_id ? {
        dimension_value_id: String(filters.dimension_value_id),
        dimension_descendants: filters.dimension_descendants ? '1' : '0',
      } : {}),
    } })
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
    report504.value = null
    accounts504.value = null
    report.value = null
    accountsReport.value = null
  } finally {
    loading.value = false
  }
}

function onPeriodChange() {
  rangeAdjusted.value = false
  const period = findAccountingPeriod(periods.value, filters.period_id)
  if (!period) return
  filters.as_of = period.ends_on
  void load()
}

const expandedRowCode = ref<string | null>(null)
function toggleExpand(rowCode: string, accounts: StatementRowAccount[]) {
  if (!accounts.length) return
  expandedRowCode.value = expandedRowCode.value === rowCode ? null : rowCode
}

function accountLink(acc: StatementRowAccount) {
  return {
    name: 'accounting-account-statement',
    params: { accountId: acc.account_id },
    query: { from: report.value?.period.starts_on, to: report.value?.as_of },
  }
}

const exporting = ref(false)
async function exportFile(format: 'pdf' | 'xlsx') {
  if (!filters.period_id || !hasData.value) return
  exporting.value = true
  try {
    if (view.value === 'accounts' && (accountsReport.value || accounts504.value)) {
      const r = await accountingApi.exportReport('/accounting/reports/statement-accounts/export',
        { ...accountsParams(), part: 'balance', unit: unit.value, format })
      downloadBlob(r.data as unknown as Blob, `rozvaha-po-uctech-${(accountsReport.value || accounts504.value)!.as_of}.${format}`)
    } else if (report.value || report504.value) {
      const r = await accountingApi.exportReport('/accounting/reports/balance-sheet/export', { ...queryParams(), format })
      downloadBlob(r.data as unknown as Blob, `rozvaha-${(report.value || report504.value)!.as_of}.${format}`)
    }
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

onMounted(async () => {
  await accrualSpread.restore()
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
    if (typeof q.scope === 'string' && ['auto', 'full', 'small', 'micro'].includes(q.scope)) filters.scope = q.scope as StatementScope
    if (q.view === 'accounts') view.value = 'accounts'
    if (toQuery && toQuery >= selectedPeriod.starts_on && toQuery <= selectedPeriod.ends_on) filters.as_of = toQuery
    const valueId = Number(q.dimension_value_id)
    if (Number.isSafeInteger(valueId) && valueId > 0) filters.dimension_value_id = valueId
    filters.dimension_descendants = q.dimension_descendants !== '0'
    await load()
  }
})
</script>

<template>
  <div>
    <ActivationBanner />
    <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('accounting.balance_sheet.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5">{{ t('accounting.balance_sheet.subtitle') }}</p>
      </div>
      <div class="flex flex-wrap items-center gap-2">
        <div class="flex rounded-md border border-neutral-300 overflow-hidden text-sm font-medium">
          <button @click="unit = 'czk'"
            class="cursor-pointer h-9 px-3 whitespace-nowrap" :class="unit === 'czk' ? 'bg-primary-600 text-white' : 'hover:bg-neutral-50'">
            {{ t('reports.unit_czk') }}
          </button>
          <button @click="unit = 'thousands'"
            class="cursor-pointer h-9 px-3 whitespace-nowrap border-l border-neutral-300" :class="unit === 'thousands' ? 'bg-primary-600 text-white' : 'hover:bg-neutral-50'">
            {{ t('reports.unit_thousands') }}
          </button>
        </div>
        <button :disabled="!hasData || exporting" @click="exportFile('pdf')" :class="btnOutline('primary')" class="whitespace-nowrap">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.download" /></svg>
          {{ t('accounting.balance_sheet.export_pdf') }}
        </button>
        <button :disabled="!hasData || exporting" @click="exportFile('xlsx')" :class="btnOutline('primary')" class="whitespace-nowrap">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.download" /></svg>
          {{ t('accounting.balance_sheet.export_xlsx') }}
        </button>
      </div>
    </div>

    <div class="flex flex-wrap gap-2 border-b border-neutral-200 mb-4" role="tablist">
      <button type="button" role="tab" :aria-selected="view === 'statement'" data-test="tab-statement"
              class="px-3 py-2 text-sm font-medium border-b-2 -mb-px whitespace-nowrap"
              :class="view === 'statement' ? 'border-primary-600 text-primary-700' : 'border-transparent text-neutral-500 hover:text-neutral-700'"
              @click="switchView('statement')">
        {{ t('accounting.statement_accounts.tab_statement') }}
      </button>
      <button type="button" role="tab" :aria-selected="view === 'accounts'" data-test="tab-accounts"
              class="px-3 py-2 text-sm font-medium border-b-2 -mb-px whitespace-nowrap"
              :class="view === 'accounts' ? 'border-primary-600 text-primary-700' : 'border-transparent text-neutral-500 hover:text-neutral-700'"
              @click="switchView('accounts')">
        {{ t((view === 'accounts' ? accounts504 : report504) ? 'accounting.statement504.management' : 'accounting.statement_accounts.tab_accounts_balance') }}
      </button>
    </div>

    <!-- Filtry -->
    <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-3 mb-4">
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
        <div>
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.balance_sheet.filter_period') }}</label>
          <select v-model="filters.period_id" @change="onPeriodChange"
            class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface">
            <option v-for="p in periods" :key="p.id" :value="p.id">{{ p.fiscal_year }}</option>
          </select>
        </div>
        <div>
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.balance_sheet.filter_as_of') }}</label>
          <DateInput v-model="filters.as_of" @change="rangeAdjusted = false; load()"
            class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm" />
        </div>
        <div v-if="view === 'statement'">
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.balance_sheet.filter_scope') }}</label>
          <select v-model="filters.scope" @change="load"
            class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface">
            <option value="auto">{{ t('accounting.balance_sheet.scope_auto') }}</option>
            <option value="full">{{ t('accounting.balance_sheet.scope_full') }}</option>
            <option value="small">{{ t('accounting.balance_sheet.scope_small') }}</option>
            <option value="micro">{{ t('accounting.balance_sheet.scope_micro') }}</option>
          </select>
        </div>
        <div v-if="view === 'statement' && filters.scope === 'auto' && category" class="flex items-end pb-1">
          <span class="text-xs px-2 py-1 rounded bg-primary-50 text-primary-700 font-medium">
            {{ t(`accounting.balance_sheet.category_${category.category}`) }}
          </span>
        </div>
        <div v-if="view === 'statement' && !report504" class="flex items-end pb-2">
          <AccrualSpreadToggle :model-value="accrualSpreadOn" @update:model-value="onAccrualSpread" />
        </div>
      </div>
      <DimensionReportFilter class="mt-3"
        :value-id="filters.dimension_value_id" :descendants="filters.dimension_descendants"
        @update:value-id="onDimensionValue" @update:descendants="onDimensionDescendants" />
    </div>
    <DimensionReportLinks current="balance" :from="selectedPeriod?.starts_on ?? ''" :to="filters.as_of || selectedPeriod?.ends_on || ''"
      :value-id="filters.dimension_value_id" :descendants="filters.dimension_descendants" />
    <p v-if="rangeAdjusted" class="mb-3 rounded-md border border-warning-500/30 bg-warning-50 px-3 py-2 text-xs text-warning-700" data-test="balance-range-adjusted">{{ t('dimensions.balance_range_adjusted') }}</p>
    <p v-if="view === 'statement' && report?.dimension" class="mb-3 rounded-md border border-primary-200 bg-primary-50 px-3 py-2 text-xs text-primary-800" data-test="balance-dimension-note">
      {{ t('dimensions.balance_filter_note') }}
    </p>
    <AccrualSpreadNote v-if="view === 'statement' && report?.accrual_spread" :spread="report.accrual_spread" :format="fm" />

    <div v-if="loading" class="text-center text-neutral-500 py-12 text-sm">{{ t('common.loading') }}</div>

    <EmptyState v-else-if="!hasData" boxed accent="neutral" icon="doc" :title="t('accounting.balance_sheet.empty')" />

    <Statement504Table v-else-if="view === 'accounts' && accounts504" :report="accounts504" :format="fm" :unit="unit" part="balance" />
    <Statement504Table v-else-if="view === 'statement' && report504" :report="report504" :format="fm" :unit="unit" />
    <template v-else-if="view === 'accounts' && accountsReport">
      <div class="text-xs text-neutral-500 mb-3">
        {{ accountsReport.entity.name }}<template v-if="accountsReport.entity.ico"> · IČO {{ accountsReport.entity.ico }}</template>
        · {{ t('accounting.balance_sheet.prepared_at') }}: {{ accountsReport.entity.prepared_at }}
        <template v-if="unit === 'thousands'"> · {{ t('reports.unit_thousands_note') }}</template>
      </div>
      <StatementAccountsTable :report="accountsReport" part="balance" :format="fm"
        :hide-zero="hideZero" :show-analytics="showAnalytics"
        @update:hide-zero="onHideZero" @update:show-analytics="onShowAnalytics" />
    </template>

    <template v-else-if="report">
      <div class="text-xs text-neutral-500 mb-3">
        {{ report.entity.name }}<template v-if="report.entity.ico"> · IČO {{ report.entity.ico }}</template>
        · {{ t('accounting.balance_sheet.prepared_at') }}: {{ report.entity.prepared_at }}
        · {{ t('accounting.balance_sheet.version') }}: {{ report.version_code }}
        <template v-if="unit === 'thousands'"> · {{ t('reports.unit_thousands_note') }}</template>
      </div>

      <div v-if="report.checks.negative_net_rows?.length"
        class="bg-warning-50 border border-warning-200 rounded-lg p-3 mb-4 text-sm" data-test="negative-net-warning">
        <div class="font-semibold text-warning-800">{{ t('accounting.balance_sheet.negative_net_title') }}</div>
        <p class="text-warning-800 mt-1">{{ t('accounting.balance_sheet.negative_net_hint') }}</p>
        <ul class="mt-2 space-y-0.5 text-warning-900">
          <li v-for="r in report.checks.negative_net_rows" :key="`${r.column}-${r.row_code}`">
            <span class="font-mono">{{ r.row_code }}</span> {{ r.label }}:
            <span class="font-mono">{{ fm(r.net) }}</span>
            ({{ r.column === 'previous' ? t('accounting.balance_sheet.negative_net_previous') : t('accounting.balance_sheet.negative_net_current') }})
          </li>
        </ul>
        <RouterLink :to="{ name: 'accounting-statement-mapping', query: { period_id: String(filters.period_id) } }"
          class="inline-block mt-2 text-primary-600 hover:text-primary-700 hover:underline">
          {{ t('accounting.balance_sheet.negative_net_link') }}
        </RouterLink>
      </div>

      <!-- AKTIVA -->
      <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden mb-4">
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
              <tr>
                <th class="px-3 py-2 text-left font-medium w-24">{{ t('accounting.balance_sheet.col_code') }}</th>
                <th class="px-3 py-2 text-left font-medium">{{ t('accounting.balance_sheet.assets') }}</th>
                <th class="px-3 py-2 text-right font-medium w-32">{{ t('accounting.balance_sheet.col_gross') }}</th>
                <th class="px-3 py-2 text-right font-medium w-32">{{ t('accounting.balance_sheet.col_correction') }}</th>
                <th class="px-3 py-2 text-right font-medium w-32">{{ t('accounting.balance_sheet.col_net') }}</th>
                <th class="px-3 py-2 text-right font-medium w-32">{{ t('accounting.balance_sheet.col_prev') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <template v-for="row in report.assets" :key="row.row_code">
                <tr :class="[
                    row.accounts.length ? 'cursor-pointer hover:bg-neutral-50' : '',
                    row.row_type !== 'detail' ? 'font-semibold' : '',
                  ]"
                  @click="toggleExpand(row.row_code, row.accounts)">
                  <td class="px-3 py-1.5 whitespace-nowrap">
                    <span v-if="row.accounts.length" class="inline-block mr-1 text-neutral-400 transition-transform"
                      :class="{ 'rotate-90': expandedRowCode === row.row_code }">▸</span>
                    {{ row.display_code }}
                  </td>
                  <td class="px-3 py-1.5" :style="{ paddingLeft: `${12 + row.level * 14}px` }">{{ row.label }}</td>
                  <td class="px-3 py-1.5 text-right font-mono">{{ fm(row.gross) }}</td>
                  <td class="px-3 py-1.5 text-right font-mono">{{ fm(row.correction) }}</td>
                  <td class="px-3 py-1.5 text-right font-mono">{{ fm(row.net) }}</td>
                  <td class="px-3 py-1.5 text-right font-mono">{{ fm(row.prev_net) }}</td>
                </tr>
                <tr v-if="expandedRowCode === row.row_code">
                  <td colspan="6" class="px-3 py-3 bg-neutral-50">
                    <div class="text-xs text-neutral-500 uppercase tracking-wide font-medium mb-2">
                      {{ t('accounting.balance_sheet.accounts_detail') }}
                    </div>
                    <table class="w-full max-w-2xl text-sm">
                      <thead class="text-xs text-neutral-500 uppercase tracking-wide">
                        <tr>
                          <th class="px-2 py-1 text-left font-medium">{{ t('accounting.balance_sheet.acc_col_account') }}</th>
                          <th class="px-2 py-1 text-right font-medium w-40">{{ t('accounting.balance_sheet.acc_col_amount') }}</th>
                        </tr>
                      </thead>
                      <tbody class="divide-y divide-neutral-200">
                        <tr v-for="acc in row.accounts" :key="`${acc.account_id}-${acc.target}`">
                          <td class="px-2 py-1">
                            <RouterLink v-if="acc.account_id > 0" :to="accountLink(acc)"
                              class="font-mono text-primary-600 hover:text-primary-700 hover:underline">
                              {{ acc.account_code }}
                            </RouterLink>
                            <span class="text-neutral-600 ml-1">{{ acc.name }}</span>
                            <span v-if="acc.target === 'correction'"
                              class="ml-1 text-xs px-1.5 py-0.5 rounded bg-neutral-100 text-neutral-500">
                              {{ t('accounting.balance_sheet.target_correction') }}
                            </span>
                          </td>
                          <td class="px-2 py-1 text-right font-mono">{{ fm(acc.amount) }}</td>
                        </tr>
                      </tbody>
                    </table>
                  </td>
                </tr>
              </template>
            </tbody>
          </table>
        </div>
      </div>

      <!-- PASIVA -->
      <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden mb-4">
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
              <tr>
                <th class="px-3 py-2 text-left font-medium w-24">{{ t('accounting.balance_sheet.col_code') }}</th>
                <th class="px-3 py-2 text-left font-medium">{{ t('accounting.balance_sheet.liabilities') }}</th>
                <th class="px-3 py-2 text-right font-medium w-32">{{ t('accounting.balance_sheet.col_amount') }}</th>
                <th class="px-3 py-2 text-right font-medium w-32">{{ t('accounting.balance_sheet.col_prev') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <template v-for="row in report.liabilities" :key="row.row_code">
                <tr :class="[
                    row.accounts.length ? 'cursor-pointer hover:bg-neutral-50' : '',
                    row.row_type !== 'detail' ? 'font-semibold' : '',
                  ]"
                  @click="toggleExpand(row.row_code, row.accounts)">
                  <td class="px-3 py-1.5 whitespace-nowrap">
                    <span v-if="row.accounts.length" class="inline-block mr-1 text-neutral-400 transition-transform"
                      :class="{ 'rotate-90': expandedRowCode === row.row_code }">▸</span>
                    {{ row.display_code }}
                  </td>
                  <td class="px-3 py-1.5" :style="{ paddingLeft: `${12 + row.level * 14}px` }">{{ row.label }}</td>
                  <td class="px-3 py-1.5 text-right font-mono">{{ fm(row.amount) }}</td>
                  <td class="px-3 py-1.5 text-right font-mono">{{ fm(row.prev_amount) }}</td>
                </tr>
                <tr v-if="expandedRowCode === row.row_code">
                  <td colspan="4" class="px-3 py-3 bg-neutral-50">
                    <div class="text-xs text-neutral-500 uppercase tracking-wide font-medium mb-2">
                      {{ t('accounting.balance_sheet.accounts_detail') }}
                    </div>
                    <table class="w-full max-w-2xl text-sm">
                      <thead class="text-xs text-neutral-500 uppercase tracking-wide">
                        <tr>
                          <th class="px-2 py-1 text-left font-medium">{{ t('accounting.balance_sheet.acc_col_account') }}</th>
                          <th class="px-2 py-1 text-right font-medium w-40">{{ t('accounting.balance_sheet.acc_col_amount') }}</th>
                        </tr>
                      </thead>
                      <tbody class="divide-y divide-neutral-200">
                        <tr v-for="acc in row.accounts" :key="`${acc.account_id}-${acc.target}`">
                          <td class="px-2 py-1">
                            <RouterLink v-if="acc.account_id > 0" :to="accountLink(acc)"
                              class="font-mono text-primary-600 hover:text-primary-700 hover:underline">
                              {{ acc.account_code }}
                            </RouterLink>
                            <span class="text-neutral-600 ml-1">{{ acc.name }}</span>
                          </td>
                          <td class="px-2 py-1 text-right font-mono">{{ fm(acc.amount) }}</td>
                        </tr>
                      </tbody>
                    </table>
                  </td>
                </tr>
              </template>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Kontrola -->
      <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-3 flex flex-wrap items-center gap-x-6 gap-y-2 text-sm">
        <div class="flex items-center gap-2">
          <span :class="report.checks.balanced ? 'text-success-600' : 'text-danger-500'" class="font-semibold">
            {{ report.checks.balanced ? '✓' : '✗' }}
          </span>
          {{ t('accounting.balance_sheet.check_balanced') }}
        </div>
        <div class="text-neutral-500">
          {{ t('accounting.balance_sheet.check_assets') }}:
          <span class="font-mono text-neutral-700">{{ fm(report.checks.assets_net) }}</span>
        </div>
        <div class="text-neutral-500">
          {{ t('accounting.balance_sheet.check_liabilities') }}:
          <span class="font-mono text-neutral-700">{{ fm(report.checks.liabilities_total) }}</span>
        </div>
      </div>
    </template>
  </div>
</template>

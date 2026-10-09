<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import {
  dimensionsApi,
  type DimensionCashFlowParams,
  type DimensionCashFlowReport,
  type DimensionProfitParams,
  type DimensionProfitReport,
} from '@/api/dimensions'
import { useDimensions } from '@/composables/useDimensions'
import { useToast } from '@/composables/useToast'
import { useSupplierStore } from '@/stores/supplier'
import { formatMoney } from '@/composables/useFormat'
import { ICONS, btnOutline } from '@/components/ui/buttonStyles'
import EmptyState from '@/components/ui/EmptyState.vue'
import DimensionPicker from '@/components/dimensions/DimensionPicker.vue'
import DimensionReportFilter from '@/components/dimensions/DimensionReportFilter.vue'
import DimensionProfitMatrix from '@/components/dimensions/DimensionProfitMatrix.vue'
import DimensionCashFlowPanel from '@/components/dimensions/DimensionCashFlowPanel.vue'

/**
 * Výkazy po dimenzi (Účetnictví):
 *   • Výsledovka — hodnoty jednoho typu (strom) × výnosy, náklady, výsledek; volitelně
 *     jen větev hodnoty nebo hodnoty odpovědné osoby a rozpad po účtech.
 *   • Peněžní tok — nepřímou metodou za celou firmu nebo za hodnotu dimenze.
 * Globální typ jde sečíst za všechny firmy skupiny, ke kterým má uživatel přístup.
 * Rozvaha, předvaha a hlavní kniha po dimenzi jsou filtrem přímo u těch výkazů.
 */
const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const toast = useToast()
const dims = useDimensions()
// Daňová evidence: výsledovka z peněžního deníku (příjmy a výdaje), peněžní tok
// po dimenzi a výkazy z deníku tu nejsou.
const isTaxEvidence = computed(() => useSupplierStore().currentSupplier?.accounting_mode === 'tax_evidence')
function revenueLabel() { return isTaxEvidence.value ? t('dimensions.profit_income') : t('dimensions.profit_revenue') }
function costLabel() { return isTaxEvidence.value ? t('dimensions.profit_expense') : t('dimensions.profit_cost') }
function metricLabel(metric: 'revenue' | 'cost' | 'result') { return metric === 'revenue' ? revenueLabel() : metric === 'cost' ? costLabel() : t('dimensions.profit_result') }

type Tab = 'profit' | 'cash_flow'
const year = new Date().getFullYear()
const tab = ref<Tab>(route.query.tab === 'cash_flow' && useSupplierStore().currentSupplier?.accounting_mode !== 'tax_evidence' ? 'cash_flow' : 'profit')
const from = ref(String(route.query.from || `${year}-01-01`))
const to = ref(String(route.query.to || `${year}-12-31`))
const groupScope = ref(route.query.scope === 'group')

const profitForm = reactive({
  typeId: (Number(route.query.type_id) || null) as number | null,
  valueId: (Number(route.query.value_id) || null) as number | null,
  responsibleId: (Number(route.query.responsible_user_id) || null) as number | null,
  accounts: route.query.accounts === '1',
  companies: route.query.companies === '1',
})
const cashFlowForm = reactive({
  valueId: (Number(route.query.dimension_value_id) || null) as number | null,
  descendants: route.query.dimension_descendants !== '0',
})

const profitReport = ref<DimensionProfitReport | null>(null)
const cashFlowReport = ref<DimensionCashFlowReport | null>(null)
const loading = ref(false)
const exporting = ref(false)
const failed = ref(false)
const ready = ref(false)
let requestId = 0
const collapsed = ref<Set<number>>(new Set())
const responsibleCandidates = ref<{ id: number; name: string }[]>([])

const types = computed(() => dims.types.value.filter(ty => ty.is_active))
const selectedType = computed(() => types.value.find(ty => ty.id === profitForm.typeId) ?? null)
const cashFlowType = computed(() => {
  const value = cashFlowForm.valueId ? dims.valueById.value.get(cashFlowForm.valueId) : null
  return value ? types.value.find(ty => ty.id === value.type_id) ?? null : null
})
const groupAvailable = computed(() => (tab.value === 'profit' ? selectedType.value : cashFlowType.value)?.level === 'global')
const hasAmount = (value: number) => Math.round(Math.abs(value) * 100) !== 0
const hasActivity = (amounts: { revenue: number; cost: number }) => hasAmount(amounts.revenue) || hasAmount(amounts.cost)
const activeRowIds = computed(() => {
  const rows = profitReport.value?.rows ?? []
  const byId = new Map(rows.map(row => [row.value_id, row]))
  const ids = new Set<number>()
  for (const row of rows) {
    if (!hasActivity(row.own) && !hasActivity(row.total)) continue
    let current: typeof row | undefined = row
    while (current && !ids.has(current.value_id)) {
      ids.add(current.value_id)
      current = current.parent_id === null ? undefined : byId.get(current.parent_id)
    }
  }
  return ids
})
const topValues = computed(() => {
  const rows = profitReport.value?.rows ?? []
  return rows.filter(row => hasAmount(row.own.result))
    .sort((a, b) => Math.abs(b.own.result) - Math.abs(a.own.result))
    .slice(0, 8)
})
const topValueScale = computed(() => Math.max(1, ...topValues.value.map(row => Math.abs(row.own.result))))
const companyRows = computed(() => (profitReport.value?.companies ?? []).filter(company => hasActivity(company)))
const linkedValueId = computed(() => tab.value === 'profit' ? profitForm.valueId : cashFlowForm.valueId)
function otherReportQuery(valueId: number | null, descendants = true) { return {
  from: from.value,
  to: to.value,
  ...(valueId ? { dimension_value_id: String(valueId), dimension_descendants: descendants ? '1' : '0' } : {}),
} }
const doubleEntryReports = [
  { name: 'accounting-balance-sheet', labelKey: 'accounting.balance_sheet.title' },
  { name: 'accounting-trial-balance', labelKey: 'accounting.trial_balance.title' },
  { name: 'accounting-general-ledger', labelKey: 'accounting.general_ledger.title' },
]
const otherReports = computed(() => isTaxEvidence.value ? [] : doubleEntryReports)

function profitParams(): DimensionProfitParams | null {
  if (!profitForm.typeId) return null
  return {
    type_id: profitForm.typeId,
    from: from.value,
    to: to.value,
    ...(groupScope.value && groupAvailable.value ? { scope: 'group' as const } : {}),
    ...(profitForm.valueId ? { value_id: profitForm.valueId } : {}),
    ...(profitForm.responsibleId ? { responsible_user_id: profitForm.responsibleId } : {}),
    ...(profitForm.accounts ? { accounts: 1 as const } : {}),
    ...(groupScope.value && groupAvailable.value && profitForm.companies ? { companies: 1 as const } : {}),
  }
}

function cashFlowParams(): DimensionCashFlowParams {
  return {
    from: from.value,
    to: to.value,
    ...(cashFlowForm.valueId
      ? { dimension_value_id: cashFlowForm.valueId, dimension_descendants: (cashFlowForm.descendants ? 1 : 0) as 0 | 1 }
      : {}),
    ...(groupScope.value && groupAvailable.value && cashFlowForm.valueId ? { scope: 'group' as const } : {}),
  }
}

function syncQuery() {
  const query: Record<string, string> = { tab: tab.value, from: from.value, to: to.value }
  if (groupScope.value) query.scope = 'group'
  if (tab.value === 'profit') {
    if (profitForm.typeId) query.type_id = String(profitForm.typeId)
    if (profitForm.valueId) query.value_id = String(profitForm.valueId)
    if (profitForm.responsibleId) query.responsible_user_id = String(profitForm.responsibleId)
    if (profitForm.accounts) query.accounts = '1'
    if (groupScope.value && groupAvailable.value && profitForm.companies) query.companies = '1'
  } else if (cashFlowForm.valueId) {
    query.dimension_value_id = String(cashFlowForm.valueId)
    if (!cashFlowForm.descendants) query.dimension_descendants = '0'
  }
  void router.replace({ query })
}

async function load() {
  const id = ++requestId
  const requestedTab = tab.value
  if (!from.value || !to.value || from.value > to.value) {
    if (requestedTab === 'profit') profitReport.value = null
    else cashFlowReport.value = null
    loading.value = false
    return
  }
  const params = requestedTab === 'profit' ? profitParams() : cashFlowParams()
  if (!params) {
    profitReport.value = null
    loading.value = false
    return
  }
  loading.value = true
  failed.value = false
  try {
    if (requestedTab === 'profit') {
      const result = await dimensionsApi.profit(params as DimensionProfitParams)
      if (id !== requestId) return
      profitReport.value = result
    } else {
      const result = await dimensionsApi.cashFlow(params as DimensionCashFlowParams)
      if (id !== requestId) return
      cashFlowReport.value = result
    }
    syncQuery()
  } catch {
    if (id === requestId) failed.value = true
  } finally {
    if (id === requestId) loading.value = false
  }
}

async function exportFile(format: 'xlsx' | 'pdf') {
  exporting.value = true
  try {
    let blob: Blob
    let name: string
    if (tab.value === 'profit') {
      const params = profitParams()
      if (!params) return
      blob = await dimensionsApi.exportProfit(params, format)
      name = `vysledovka-po-dimenzi-${from.value}.${format}`
    } else {
      blob = await dimensionsApi.exportCashFlow(cashFlowParams())
      name = `penezni-tok-po-dimenzi-${from.value}.xlsx`
    }
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = name
    document.body.appendChild(a); a.click(); a.remove()
    URL.revokeObjectURL(url)
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  } finally {
    exporting.value = false
  }
}

function switchTab(next: Tab) {
  if (tab.value === next) return
  tab.value = next
  failed.value = false
  void load()
}

onMounted(async () => {
  await dims.load()
  if (!profitForm.typeId) profitForm.typeId = types.value[0]?.id ?? null
  try { responsibleCandidates.value = await dimensionsApi.responsibleCandidates() } catch { responsibleCandidates.value = [] }
  ready.value = true
  await load()
})

watch(() => profitForm.typeId, (next, prev) => {
  collapsed.value = new Set()
  if (prev !== undefined && prev !== null && next !== prev) profitForm.valueId = null
})

const visibleRows = computed(() => {
  const hidden = new Set<number>()
  return (profitReport.value?.rows ?? []).filter((row) => {
    if (!activeRowIds.value.has(row.value_id)) return false
    if (row.parent_id !== null && (hidden.has(row.parent_id) || collapsed.value.has(row.parent_id))) {
      hidden.add(row.value_id)
      return false
    }
    return true
  })
})

watch(() => [from.value, to.value, groupScope.value, profitForm.typeId, profitForm.valueId,
  profitForm.responsibleId, profitForm.accounts, profitForm.companies, cashFlowForm.valueId, cashFlowForm.descendants], () => {
  if (ready.value) void load()
}, { flush: 'post' })
const showResponsible = computed(() => visibleRows.value.some(row => !!row.responsible_user_name))

function toggle(valueId: number) {
  const next = new Set(collapsed.value)
  if (next.has(valueId)) next.delete(valueId)
  else next.add(valueId)
  collapsed.value = next
}

const report = computed(() => (tab.value === 'profit' ? profitReport.value : cashFlowReport.value))

function money(v: number) {
  return formatMoney(v, 'CZK')
}
</script>

<template>
  <div>
    <div class="flex flex-wrap items-start justify-between gap-3 mb-4">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('dimensions.reports_title') }}</h1>
        <p class="text-sm text-neutral-500 mt-1 max-w-3xl">{{ t('dimensions.reports_subtitle') }}</p>
      </div>
      <div v-if="dims.enabled.value" class="flex flex-wrap gap-2">
        <button type="button" :disabled="!report || exporting || loading" :class="btnOutline('primary')" class="whitespace-nowrap"
                data-test="dimension-export" @click="exportFile('xlsx')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.download" /></svg>
          {{ t('dimensions.export_xlsx') }}
        </button>
        <button v-if="tab === 'profit'" type="button" :disabled="!report || exporting || loading" :class="btnOutline('primary')" class="whitespace-nowrap"
                data-test="dimension-export-pdf" @click="exportFile('pdf')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.download" /></svg>
          {{ t('dimensions.export_pdf') }}
        </button>
      </div>
    </div>

    <EmptyState v-if="!dims.enabled.value" boxed icon="tag" :title="t('dimensions.disabled_title')" :message="t('dimensions.disabled_hint')" />

    <template v-else>
      <div class="flex flex-wrap gap-2 border-b border-neutral-200 mb-4" role="tablist">
        <button type="button" role="tab" :aria-selected="tab === 'profit'" data-test="tab-profit"
                class="px-3 py-2 text-sm font-medium border-b-2 -mb-px whitespace-nowrap"
                :class="tab === 'profit' ? 'border-primary-600 text-primary-700' : 'border-transparent text-neutral-500 hover:text-neutral-700'"
                @click="switchTab('profit')">
          {{ t('dimensions.tab_profit') }}
        </button>
        <button v-if="!isTaxEvidence" type="button" role="tab" :aria-selected="tab === 'cash_flow'" data-test="tab-cash-flow"
                class="px-3 py-2 text-sm font-medium border-b-2 -mb-px whitespace-nowrap"
                :class="tab === 'cash_flow' ? 'border-primary-600 text-primary-700' : 'border-transparent text-neutral-500 hover:text-neutral-700'"
                @click="switchTab('cash_flow')">
          {{ t('dimensions.tab_cash_flow') }}
        </button>
      </div>

      <div class="flex flex-wrap items-end gap-3 mb-4 bg-surface border border-neutral-200 rounded-lg shadow-sm p-4">
        <template v-if="tab === 'profit'">
          <div>
            <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('dimensions.filter_type') }}</label>
            <select v-model="profitForm.typeId" class="h-10 px-2 border border-neutral-300 rounded-md text-sm bg-surface" data-test="profit-type">
              <option v-for="type in types" :key="type.id" :value="type.id">{{ type.name }}</option>
            </select>
          </div>
          <div v-if="profitForm.typeId" class="min-w-[14rem]">
            <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('dimensions.profit_branch') }}</label>
            <DimensionPicker :type-id="profitForm.typeId" :model-value="profitForm.valueId" :placeholder="t('dimensions.profit_branch_all')"
                             @update:model-value="profitForm.valueId = $event" />
          </div>
          <div v-if="responsibleCandidates.length">
            <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('dimensions.profit_responsible') }}</label>
            <select v-model="profitForm.responsibleId" class="h-10 px-2 border border-neutral-300 rounded-md text-sm bg-surface" data-test="profit-responsible">
              <option :value="null">{{ t('dimensions.profit_responsible_all') }}</option>
              <option v-for="u in responsibleCandidates" :key="u.id" :value="u.id">{{ u.name }}</option>
            </select>
          </div>
        </template>
        <DimensionReportFilter v-else
          :value-id="cashFlowForm.valueId" :descendants="cashFlowForm.descendants"
          @update:value-id="cashFlowForm.valueId = $event" @update:descendants="cashFlowForm.descendants = $event" />
        <div>
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('dimensions.profit_from') }}</label>
          <input v-model="from" type="date" class="h-10 px-3 border border-neutral-300 rounded-md text-sm" />
        </div>
        <div>
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('dimensions.profit_to') }}</label>
          <input v-model="to" type="date" class="h-10 px-3 border border-neutral-300 rounded-md text-sm" />
        </div>
        <label v-if="tab === 'profit'" class="flex items-center gap-2 h-10 text-sm text-neutral-700 whitespace-nowrap">
          <input v-model="profitForm.accounts" type="checkbox" class="rounded border-neutral-300" data-test="profit-accounts" />
          {{ t('dimensions.profit_by_accounts') }}
        </label>
        <label v-if="groupAvailable" class="flex items-center gap-2 h-10 text-sm text-neutral-700 whitespace-nowrap">
          <input v-model="groupScope" type="checkbox" class="rounded border-neutral-300" />
          {{ t('dimensions.profit_group_scope') }}
        </label>
        <label v-if="tab === 'profit' && groupAvailable && groupScope" class="flex items-center gap-2 h-10 text-sm text-neutral-700 whitespace-nowrap">
          <input v-model="profitForm.companies" type="checkbox" class="rounded border-neutral-300" data-test="profit-companies-filter" />
          {{ t('dimensions.profit_by_companies') }}
        </label>
      </div>

      <div v-if="loading" class="py-8 text-center text-sm text-neutral-500">{{ t('common.loading') }}</div>
      <EmptyState v-else-if="failed" variant="failed" boxed @action="load" />
      <EmptyState v-else-if="tab === 'profit' && types.length === 0" boxed icon="tag" :title="t('dimensions.types_empty')" to="/company/dimensions" :cta="t('dimensions.title')" />

      <template v-else-if="tab === 'profit' && profitReport">
        <p v-if="profitReport.supplier_ids.length > 1 || profitReport.hidden_companies > 0" class="mb-3 text-sm text-neutral-600">
          {{ t('dimensions.profit_companies', { count: profitReport.supplier_ids.length }) }}
          <span v-if="profitReport.hidden_companies > 0" class="text-warning-700">{{ t('dimensions.profit_hidden', { count: profitReport.hidden_companies }) }}</span>
        </p>
        <p v-if="profitReport.basis === 'cash_journal'" class="mb-3 rounded-md border border-primary-200 bg-primary-50 px-3 py-2 text-xs text-primary-800" data-test="profit-cash-journal">
          {{ t('dimensions.profit_cash_journal_note') }}
        </p>
        <p v-if="profitReport.restricted" class="mb-3 rounded-md border border-primary-200 bg-primary-50 px-3 py-2 text-xs text-primary-800" data-test="profit-restricted">
          {{ t('dimensions.profit_restricted_note') }}
        </p>

        <div v-if="hasActivity(profitReport.totals)" class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-4" data-test="profit-summary">
          <div v-for="metric in (['revenue', 'cost', 'result'] as const)" :key="metric" class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-4">
            <p class="text-xs text-neutral-500">{{ metricLabel(metric) }}</p>
            <p class="mt-1 text-xl font-semibold tabular-nums" :class="metric === 'result' ? (profitReport.totals.result < 0 ? 'text-danger-600' : profitReport.totals.result > 0 ? 'text-success-700' : '') : ''">
              {{ money(profitReport.totals[metric]) }}
            </p>
          </div>
        </div>

        <div v-if="!profitReport.matrix && topValues.length" class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-4 mb-4" data-test="profit-comparison">
          <h2 class="font-semibold mb-3">{{ t('dimensions.profit_comparison') }}</h2>
          <p class="text-xs text-neutral-500 mb-3">{{ t('dimensions.profit_comparison_own') }}</p>
          <div class="space-y-2">
            <div v-for="row in topValues" :key="row.value_id" class="grid grid-cols-[minmax(7rem,12rem)_1fr_auto] items-center gap-3 text-sm">
              <span class="truncate" :title="row.code === row.name ? row.name : `${row.code} ${row.name}`">{{ row.code === row.name ? row.name : `${row.code} ${row.name}` }}</span>
              <div class="h-3 bg-neutral-100 rounded-full overflow-hidden">
                <div class="h-full rounded-full" :class="row.own.result < 0 ? 'bg-danger-500' : 'bg-success-500'"
                     :style="{ width: `${Math.abs(row.own.result) / topValueScale * 100}%` }" />
              </div>
              <span class="tabular-nums whitespace-nowrap" :class="row.own.result < 0 ? 'text-danger-600' : 'text-success-700'">{{ money(row.own.result) }}</span>
            </div>
          </div>
        </div>

        <DimensionProfitMatrix v-if="profitReport.matrix" :matrix="profitReport.matrix" :cash-journal="profitReport.basis === 'cash_journal'" />

        <div v-if="!profitReport.matrix && visibleRows.length === 0 && !hasActivity(profitReport.unassigned)" class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-6 text-sm text-neutral-500" data-test="profit-no-activity">{{ t('dimensions.profit_no_activity') }}</div>
        <div v-else-if="!profitReport.matrix" class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-x-auto">
          <table class="w-full text-sm" data-test="profit-table">
            <thead class="bg-neutral-50 text-neutral-600">
              <tr>
                <th class="px-4 py-3 text-left font-medium">{{ selectedType?.name }}</th>
                <th v-if="otherReports.length" class="px-4 py-3 text-left font-medium whitespace-nowrap">{{ t('dimensions.other_reports_short') }}</th>
                <th v-if="showResponsible" class="px-4 py-3 text-left font-medium whitespace-nowrap">{{ t('dimensions.profit_responsible') }}</th>
                <th class="px-4 py-3 text-right font-medium whitespace-nowrap">{{ revenueLabel() }}</th>
                <th class="px-4 py-3 text-right font-medium whitespace-nowrap">{{ costLabel() }}</th>
                <th class="px-4 py-3 text-right font-medium whitespace-nowrap">{{ t('dimensions.profit_result') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="row in visibleRows" :key="row.value_id" :class="{ 'opacity-60': !row.is_active, 'font-medium': row.has_children }">
                <td class="px-4 py-2">
                  <div class="flex items-center gap-1" :style="{ paddingLeft: `${row.depth * 1.25}rem` }">
                    <button v-if="row.has_children" type="button" class="w-5 h-5 inline-flex items-center justify-center text-neutral-500"
                            :aria-expanded="!collapsed.has(row.value_id)" @click="toggle(row.value_id)">
                      <svg class="w-3.5 h-3.5 transition-transform" :class="{ 'rotate-90': !collapsed.has(row.value_id) }" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" /></svg>
                    </button>
                    <span v-else class="w-5" />
                    <span class="font-mono text-xs text-neutral-500">{{ row.code }}</span>
                    <span v-if="row.name !== row.code" class="truncate">{{ row.name }}</span>
                  </div>
                </td>
                <td v-if="otherReports.length" class="px-4 py-2">
                  <div class="flex flex-wrap gap-x-3 gap-y-1">
                    <RouterLink v-for="other in otherReports" :key="other.name" :to="{ name: other.name, query: otherReportQuery(row.value_id) }"
                                class="text-primary-700 hover:underline cursor-pointer whitespace-nowrap" :data-test="`profit-row-report-${row.value_id}`">
                      {{ t(other.labelKey) }}
                    </RouterLink>
                  </div>
                </td>
                <td v-if="showResponsible" class="px-4 py-2 text-neutral-600 whitespace-nowrap">{{ row.responsible_user_name ?? '' }}</td>
                <td class="px-4 py-2 text-right tabular-nums whitespace-nowrap" :title="row.has_children ? t('dimensions.profit_own', { amount: money(row.own.revenue) }) : undefined">{{ hasAmount(row.total.revenue) ? money(row.total.revenue) : '–' }}</td>
                <td class="px-4 py-2 text-right tabular-nums whitespace-nowrap" :title="row.has_children ? t('dimensions.profit_own', { amount: money(row.own.cost) }) : undefined">{{ hasAmount(row.total.cost) ? money(row.total.cost) : '–' }}</td>
                <td class="px-4 py-2 text-right tabular-nums whitespace-nowrap" :class="row.total.result < 0 ? 'text-danger-600' : row.total.result > 0 ? 'text-success-700' : ''">{{ hasAmount(row.total.result) ? money(row.total.result) : '–' }}</td>
              </tr>
              <tr v-if="!profitReport.restricted && hasActivity(profitReport.unassigned)" class="text-neutral-500 italic">
                <td class="px-4 py-2">{{ t('dimensions.profit_unassigned') }}</td>
                <td v-if="otherReports.length" class="px-4 py-2" />
                <td v-if="showResponsible" class="px-4 py-2" />
                <td class="px-4 py-2 text-right tabular-nums whitespace-nowrap">{{ money(profitReport.unassigned.revenue) }}</td>
                <td class="px-4 py-2 text-right tabular-nums whitespace-nowrap">{{ money(profitReport.unassigned.cost) }}</td>
                <td class="px-4 py-2 text-right tabular-nums whitespace-nowrap" :class="profitReport.unassigned.result < 0 ? 'text-danger-600' : profitReport.unassigned.result > 0 ? 'text-success-700' : ''">{{ money(profitReport.unassigned.result) }}</td>
              </tr>
            </tbody>
            <tfoot class="bg-neutral-50 font-semibold">
              <tr>
                <td class="px-4 py-3">{{ t('dimensions.profit_total') }}</td>
                <td v-if="otherReports.length" class="px-4 py-3" />
                <td v-if="showResponsible" class="px-4 py-3" />
                <td class="px-4 py-3 text-right tabular-nums whitespace-nowrap">{{ money(profitReport.totals.revenue) }}</td>
                <td class="px-4 py-3 text-right tabular-nums whitespace-nowrap">{{ money(profitReport.totals.cost) }}</td>
                <td class="px-4 py-3 text-right tabular-nums whitespace-nowrap" :class="profitReport.totals.result < 0 ? 'text-danger-600' : profitReport.totals.result > 0 ? 'text-success-700' : ''">{{ money(profitReport.totals.result) }}</td>
              </tr>
            </tfoot>
          </table>
        </div>
        <div v-if="profitForm.companies && groupScope && companyRows.length" class="mt-4 bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-x-auto" data-test="profit-companies">
          <h2 class="px-4 py-3 font-semibold border-b border-neutral-200">{{ t('dimensions.profit_by_companies') }}</h2>
          <table class="w-full text-sm">
            <thead class="bg-neutral-50 text-neutral-600">
              <tr>
                <th class="px-4 py-3 text-left font-medium">{{ t('dimensions.analytics_company') }}</th>
                <th class="px-4 py-3 text-right font-medium">{{ revenueLabel() }}</th>
                <th class="px-4 py-3 text-right font-medium">{{ costLabel() }}</th>
                <th class="px-4 py-3 text-right font-medium">{{ t('dimensions.profit_result') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="company in companyRows" :key="company.id">
                <td class="px-4 py-2">{{ company.name }}</td>
                <td class="px-4 py-2 text-right tabular-nums whitespace-nowrap">{{ hasAmount(company.revenue) ? money(company.revenue) : '–' }}</td>
                <td class="px-4 py-2 text-right tabular-nums whitespace-nowrap">{{ hasAmount(company.cost) ? money(company.cost) : '–' }}</td>
                <td class="px-4 py-2 text-right tabular-nums whitespace-nowrap" :class="company.result < 0 ? 'text-danger-600' : company.result > 0 ? 'text-success-700' : ''">{{ hasAmount(company.result) ? money(company.result) : '–' }}</td>
              </tr>
            </tbody>
            <tfoot class="bg-neutral-50 font-semibold">
              <tr>
                <td class="px-4 py-3">{{ t('dimensions.profit_total') }}</td>
                <td class="px-4 py-3 text-right tabular-nums whitespace-nowrap">{{ money(profitReport.totals.revenue) }}</td>
                <td class="px-4 py-3 text-right tabular-nums whitespace-nowrap">{{ money(profitReport.totals.cost) }}</td>
                <td class="px-4 py-3 text-right tabular-nums whitespace-nowrap" :class="profitReport.totals.result < 0 ? 'text-danger-600' : profitReport.totals.result > 0 ? 'text-success-700' : ''">{{ money(profitReport.totals.result) }}</td>
              </tr>
            </tfoot>
          </table>
        </div>
      </template>

      <template v-else-if="tab === 'cash_flow' && cashFlowReport">
        <p v-if="cashFlowReport.supplier_ids.length > 1 || cashFlowReport.hidden_companies > 0" class="mb-3 text-sm text-neutral-600">
          {{ t('dimensions.profit_companies', { count: cashFlowReport.supplier_ids.length }) }}
          <span v-if="cashFlowReport.hidden_companies > 0" class="text-warning-700">{{ t('dimensions.profit_hidden', { count: cashFlowReport.hidden_companies }) }}</span>
        </p>
        <p class="mb-3 text-xs text-neutral-500 max-w-3xl">{{ t('dimensions.cf_method_note') }}</p>
        <DimensionCashFlowPanel :report="cashFlowReport" />
      </template>
      <div v-if="otherReports.length" class="mt-5 bg-surface border border-neutral-200 rounded-lg shadow-sm p-4">
        <h2 class="font-semibold">{{ t('dimensions.other_reports') }}</h2>
        <p class="text-xs text-neutral-500 mt-1 mb-3">{{ t('dimensions.other_reports_hint') }}</p>
        <div class="flex flex-wrap gap-2" data-test="profit-other-reports">
          <RouterLink v-for="other in otherReports" :key="other.name" :to="{ name: other.name, query: otherReportQuery(linkedValueId, tab === 'profit' || cashFlowForm.descendants) }" :class="btnOutline('neutral')" class="whitespace-nowrap">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.chart" /></svg>
            {{ t(other.labelKey) }}
          </RouterLink>
        </div>
      </div>
    </template>
  </div>
</template>

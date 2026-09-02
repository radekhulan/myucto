<script setup lang="ts">
import { ref, onMounted, reactive, computed, nextTick } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import {
  accountingApi,
  type AccountingPeriod,
  type GeneralLedgerReport,
  type GeneralLedgerAccount,
  type AccountStatementItem,
} from '@/api/accounting'
import { useToast } from '@/composables/useToast'
import { formatDate, formatMoney } from '@/composables/useFormat'
import { journalSourceLink, journalEntryLink } from '@/utils/journalSourceLink'
import SavedFiltersMenu from '@/components/ui/SavedFiltersMenu.vue'
import ColumnPicker from '@/components/ui/ColumnPicker.vue'
import DensityToggle from '@/components/ui/DensityToggle.vue'
import { useTablePrefs, type ColumnDef } from '@/composables/useTablePrefs'
import { useSavedFilters, savedFilterTone, type SavedFilterTone } from '@/composables/useSavedFilters'
import { ensurePrefsLoaded } from '@/composables/useUserPrefs'
import type { SavedFilter } from '@/api/preferences'
import { ICONS, btnOutline } from '@/components/ui/buttonStyles'
import ActivationBanner from '@/components/settings/activation/ActivationBanner.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { usePaneDom } from '@/composables/usePaneDom'
import { allAccountingPeriodsRange, findAccountingPeriod } from '@/utils/accountingPeriod'

const { t } = useI18n()
const toast = useToast()
const route = useRoute()
const router = useRouter()
const paneDom = usePaneDom()

const periods = ref<AccountingPeriod[]>([])
const report = ref<GeneralLedgerReport | null>(null)
const loading = ref(false)

const filters = reactive({
  period_id: '' as number | '',
  from: '',
  to: '',
  analytics: false,
  after_closing: false,
  // Hledání dle protistrany/položky zdrojového dokladu (§ hlavní kniha, vzor Journal.vue).
  vendor: '',
  client: '',
  item: '',
})

function queryParams() {
  return {
    ...(filters.period_id === ''
      ? { all_periods: 1 as const }
      : { period_id: Number(filters.period_id) }),
    from: filters.from || undefined,
    to: filters.to || undefined,
    analytics: filters.analytics ? (1 as const) : undefined,
    after_closing: filters.after_closing ? (1 as const) : undefined,
    vendor: filters.vendor || undefined,
    client: filters.client || undefined,
    item: filters.item || undefined,
  }
}

function resetFilters() {
  filters.vendor = ''
  filters.client = ''
  filters.item = ''
  load()
}

async function load() {
  if (periods.value.length === 0) return
  loading.value = true
  expandedId.value = null
  closeMonth()
  try {
    report.value = await accountingApi.getGeneralLedger(queryParams())
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
    report.value = null
  } finally {
    loading.value = false
  }
}

const expandedId = ref<number | null>(null)
function toggleExpand(a: GeneralLedgerAccount) {
  const next = expandedId.value === a.account_id ? null : a.account_id
  expandedId.value = next
  if (next === null) closeMonth()
}

/** Karta účtu = rozcestník (kmen, analytiky, odkazy na opis/deník). */
function accountLink(a: GeneralLedgerAccount) {
  return {
    name: 'accounting-account-detail',
    params: { accountId: a.account_id },
    query: { from: report.value?.from, to: report.value?.to },
  }
}

function statementLink(a: GeneralLedgerAccount, from?: string, to?: string) {
  return {
    name: 'accounting-account-statement',
    params: { accountId: a.account_id },
    query: { from: from ?? report.value?.from, to: to ?? report.value?.to },
  }
}

// ── Rozpad měsíce na řádky deníku ──────────────────────────────────────────
// Data bere existující opis účtu (`account-statement`) zúžený na hranice měsíce —
// tytéž pohyby, které se sčítají do měsíčního obratu, jen nezagregované. Vlastní
// endpoint by znamenal druhý výklad toho, co do obratu patří.
const MONTH_LINE_LIMIT = 200

const openMonth = ref<string | null>(null)
const monthLines = ref<AccountStatementItem[]>([])
const monthTotal = ref(0)
const monthLoading = ref(false)

function monthBounds(ym: string): { from: string; to: string } {
  const [y, m] = ym.split('-').map(Number)
  const last = new Date(Date.UTC(y, m, 0)).getUTCDate()
  return { from: `${ym}-01`, to: `${ym}-${String(last).padStart(2, '0')}` }
}

function closeMonth() {
  openMonth.value = null
  monthLines.value = []
  monthTotal.value = 0
}

async function toggleMonth(a: GeneralLedgerAccount, ym: string) {
  if (openMonth.value === ym) { closeMonth(); return }
  openMonth.value = ym
  monthLines.value = []
  monthTotal.value = 0
  monthLoading.value = true
  const { from, to } = monthBounds(ym)
  try {
    const r = await accountingApi.getAccountStatement(a.account_id, {
      from, to, page: 1, per_page: MONTH_LINE_LIMIT,
    })
    // Mezitím mohl uživatel měsíc zavřít nebo otevřít jiný — pozdní odpověď zahoď.
    if (openMonth.value !== ym) return
    monthLines.value = r.items
    monthTotal.value = r.total
  } catch (e: any) {
    if (openMonth.value === ym) closeMonth()
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  } finally {
    monthLoading.value = false
  }
}

function lineLink(it: AccountStatementItem) {
  return journalSourceLink(it) ?? journalEntryLink(it.entry_id)
}

function monthStatementLink(a: GeneralLedgerAccount, ym: string) {
  const { from, to } = monthBounds(ym)
  return statementLink(a, from, to)
}

/**
 * Proklik z karty účtu / jiné sestavy: `?account_id=` po načtení rozbalí ten účet
 * a odroluje k němu. Hlavní kniha nemá filtr na účet — rozbalený řádek je nejbližší
 * ekvivalent „knihy zúžené na účet" bez nové serverové varianty sestavy.
 */
async function focusAccountFromQuery() {
  const id = Number(route.query.account_id || 0)
  await focusAccount(id)
}

async function focusAccount(id: number) {
  if (id <= 0 || !report.value) return
  if (!report.value.accounts.some(a => a.account_id === id)) return
  expandedId.value = id
  await nextTick()
  paneDom.querySelector(`#gl-account-${id}`)?.scrollIntoView({ block: 'center', behavior: 'smooth' })
}

const exporting = ref(false)
async function exportFile(format: 'pdf' | 'xlsx') {
  if (!report.value) return
  exporting.value = true
  try {
    const r = await accountingApi.exportReport('/accounting/reports/general-ledger/export', { ...queryParams(), format })
    const label = report.value.all_periods ? 'vse' : report.value.period?.fiscal_year
    downloadBlob(r.data as unknown as Blob, `hlavni-kniha-${label}.${format}`)
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

function buildQuery(includeAfterClosing = false): Record<string, string> {
  const q: Record<string, string> = {}
  if (filters.period_id === '') q.all_periods = '1'
  else q.period_id = String(filters.period_id)
  if (filters.from) q.from = filters.from
  if (filters.to) q.to = filters.to
  if (filters.analytics) q.analytics = '1'
  if (includeAfterClosing && filters.after_closing) q.after_closing = '1'
  if (filters.vendor) q.vendor = filters.vendor
  if (filters.client) q.client = filters.client
  if (filters.item) q.item = filters.item
  return q
}

function applyQueryToPage(q: Record<string, string>) {
  filters.period_id = q.all_periods === '1' ? '' : (q.period_id ? Number(q.period_id) : '')
  filters.from = q.from ?? ''
  filters.to = q.to ?? ''
  filters.analytics = q.analytics === '1'
  filters.after_closing = q.after_closing === '1'
  filters.vendor = q.vendor ?? ''
  filters.client = q.client ?? ''
  filters.item = q.item ?? ''
  load()
}

const COLUMNS: ColumnDef[] = [
  { key: 'account', labelKey: 'accounting.general_ledger.col_account', required: true },
  { key: 'name', labelKey: 'accounting.general_ledger.col_name', required: true },
  { key: 'account_type', labelKey: 'accounting.general_ledger.col_type', defaultHidden: true },
  { key: 'synthetic', labelKey: 'accounting.general_ledger.col_synthetic', defaultHidden: true },
  { key: 'opening_md', labelKey: 'accounting.general_ledger.col_ps_md' },
  { key: 'opening_d', labelKey: 'accounting.general_ledger.col_ps_d' },
  { key: 'turnover_md', labelKey: 'accounting.general_ledger.col_turnover_md' },
  { key: 'turnover_d', labelKey: 'accounting.general_ledger.col_turnover_d' },
  { key: 'closing_md', labelKey: 'accounting.general_ledger.col_ks_md' },
  { key: 'closing_d', labelKey: 'accounting.general_ledger.col_ks_d' },
]
const tbl = useTablePrefs('general_ledger', COLUMNS)
const saved = useSavedFilters('general_ledger', { getQuery: buildQuery, applyQuery: applyQueryToPage })
const visibleColCount = computed(() => 1 + tbl.columns.filter(c => tbl.isVisible(c.key)).length)

/**
 * Řádek pohledů = uložené filtry vytažené z dropdownu do záložek nad seznamem.
 * Stejný vzor jako u vydaných faktur / deníku (InvoiceList.vue, Journal.vue).
 */
const VIEW_DOT_CLASS: Record<SavedFilterTone, string> = {
  danger:  'bg-danger-500',
  warning: 'bg-warning-500',
  success: 'bg-success-500',
  neutral: 'bg-neutral-300',
}
function viewDotClass(f: SavedFilter): string {
  return VIEW_DOT_CLASS[savedFilterTone(f.payload)]
}
function onViewClick(f: SavedFilter) {
  if (saved.activeId.value === f.id) saved.clearActive()
  else saved.apply(f)
}

// Rozpad po analytikách si stránka pamatuje mezi návštěvami (viz useTablePrefs.flag).
// Uložený filtr i `?analytics=` v adrese jsou explicitní volba a mají přednost.
function onAnalyticsToggle() {
  tbl.setFlag('analytics', filters.analytics)
  return load()
}

async function onPeriodChange() {
  const period = findAccountingPeriod(periods.value, filters.period_id)
  const allRange = allAccountingPeriodsRange(periods.value)
  const accountId = expandedId.value ?? Number(route.query.account_id || 0)
  filters.from = period?.starts_on ?? allRange.from ?? ''
  filters.to = period?.ends_on ?? allRange.to ?? ''
  await router.replace({
    query: {
      ...buildQuery(true),
      ...(accountId > 0 ? { account_id: String(accountId) } : {}),
    },
  })
  await load()
  await focusAccount(accountId)
}

onMounted(async () => {
  await ensurePrefsLoaded()
  filters.analytics = tbl.flag('analytics')
  try { periods.value = await accountingApi.listPeriods() } catch { periods.value = [] }
  if (Object.keys(route.query).length === 0 && await saved.applyDefaultIfAny()) return
  const open = periods.value.filter(p => p.status === 'open')
  const def = open.length
    ? open.reduce((a, b) => (b.fiscal_year > a.fiscal_year ? b : a))
    : periods.value[0]
  // Drill-down z karty účtu / jiné sestavy — období, rozsah i rozpad analytik
  // z URL mají přednost před výchozím otevřeným obdobím.
  const q = route.query
  const periodId: number | '' = q.all_periods === '1'
    ? ''
    : (typeof q.period_id === 'string' && q.period_id ? Number(q.period_id) : (def?.id ?? 0))
  if (periodId === 0 || periods.value.length === 0) return
  filters.period_id = periodId
  if (typeof q.from === 'string' && q.from) filters.from = q.from
  if (typeof q.to === 'string' && q.to) filters.to = q.to
  if (periodId === '') {
    const allRange = allAccountingPeriodsRange(periods.value)
    if (!filters.from) filters.from = allRange.from ?? ''
    if (!filters.to) filters.to = allRange.to ?? ''
  }
  if (q.analytics === '1') filters.analytics = true
  if (q.after_closing === '1') filters.after_closing = true
  await load()
  await focusAccountFromQuery()
})
</script>

<template>
  <div>
    <ActivationBanner />
    <div class="flex flex-wrap items-center justify-between gap-2 mb-4">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('accounting.general_ledger.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5">{{ t('accounting.general_ledger.subtitle') }}</p>
      </div>
      <div class="flex flex-wrap items-center gap-2">
        <SavedFiltersMenu :ctrl="saved" />
        <ColumnPicker class="hidden md:block" :ctrl="tbl" />
        <DensityToggle class="hidden md:block" :ctrl="tbl" />
        <button :disabled="!report || exporting" @click="exportFile('pdf')" :class="btnOutline('primary')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.download" /></svg>
          {{ t('accounting.general_ledger.export_pdf') }}
        </button>
        <button :disabled="!report || exporting" @click="exportFile('xlsx')" :class="btnOutline('primary')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.download" /></svg>
          {{ t('accounting.general_ledger.export_xlsx') }}
        </button>
      </div>
    </div>

    <!-- Řádek pohledů. Bez jediného uloženého pohledu se nevykresluje vůbec —
         osamocené „Vše" nad seznamem nic neříká a jen ubírá výšku. -->
    <div
      v-if="saved.filters.value.length"
      role="tablist"
      :aria-label="t('common.saved_views')"
      class="mb-3 flex items-center gap-1.5 overflow-x-auto pb-1"
    >
      <button
        type="button"
        role="tab"
        :aria-selected="saved.activeId.value === null"
        @click="saved.clearActive()"
        class="cursor-pointer shrink-0 h-8 px-3 inline-flex items-center rounded-full border text-sm transition-colors"
        :class="saved.activeId.value === null
          ? 'border-primary-300 bg-primary-50 text-primary-700 font-medium'
          : 'border-neutral-200 text-neutral-600 hover:bg-neutral-50'"
      >{{ t('common.saved_view_all') }}</button>

      <button
        v-for="f in saved.filters.value"
        :key="f.id"
        type="button"
        role="tab"
        :aria-selected="saved.activeId.value === f.id"
        :title="saved.activeId.value === f.id ? t('common.saved_view_clear') : f.name"
        @click="onViewClick(f)"
        class="cursor-pointer shrink-0 max-w-56 h-8 px-3 inline-flex items-center gap-1.5 rounded-full border text-sm transition-colors"
        :class="saved.activeId.value === f.id
          ? 'border-primary-300 bg-primary-50 text-primary-700 font-medium'
          : 'border-neutral-200 text-neutral-600 hover:bg-neutral-50'"
      >
        <span class="shrink-0 w-1.5 h-1.5 rounded-full" :class="viewDotClass(f)" aria-hidden="true"></span>
        <span class="truncate">{{ f.name }}</span>
      </button>
    </div>

    <!-- Filtry -->
    <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-3 mb-4">
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
        <div>
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.general_ledger.filter_period') }}</label>
          <select v-model="filters.period_id" @change="onPeriodChange"
            class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface">
            <option value="">{{ t('accounting.general_ledger.all_periods') }}</option>
            <option v-for="p in periods" :key="p.id" :value="p.id">{{ p.fiscal_year }}</option>
          </select>
        </div>
        <div>
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.general_ledger.filter_from') }}</label>
          <input v-model="filters.from" type="date" @change="load"
            class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm" />
        </div>
        <div>
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.general_ledger.filter_to') }}</label>
          <input v-model="filters.to" type="date" @change="load"
            class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm" />
        </div>
        <div class="flex items-end pb-2">
          <label class="inline-flex items-center gap-2 text-sm cursor-pointer">
            <input v-model="filters.analytics" type="checkbox" @change="onAnalyticsToggle" class="rounded border-neutral-300" />
            {{ t('accounting.general_ledger.filter_analytics') }}
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
        <div>
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.general_ledger.filter_vendor') }}</label>
          <input v-model.trim="filters.vendor" type="search" @keyup.enter="load" @search="load"
            :placeholder="t('accounting.general_ledger.filter_vendor_placeholder')"
            class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm" />
        </div>
        <div>
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.general_ledger.filter_client') }}</label>
          <input v-model.trim="filters.client" type="search" @keyup.enter="load" @search="load"
            :placeholder="t('accounting.general_ledger.filter_client_placeholder')"
            class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm" />
        </div>
        <div>
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.general_ledger.filter_item') }}</label>
          <input v-model.trim="filters.item" type="search" @keyup.enter="load" @search="load"
            :placeholder="t('accounting.general_ledger.filter_item_placeholder')"
            class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm" />
        </div>
      </div>
      <div class="flex flex-wrap items-center justify-end gap-2 mt-2">
        <button @click="resetFilters" class="cursor-pointer text-xs text-neutral-500 hover:text-neutral-700">{{ t('accounting.general_ledger.reset_filters') }}</button>
      </div>
    </div>

    <div v-if="report && report.draft_count > 0"
      class="mb-4 px-3 py-2 rounded-md bg-warning-50 border border-warning-500/30 text-warning-600 text-sm">
      {{ t('accounting.general_ledger.draft_warning', { n: report.draft_count }) }}
    </div>

    <div v-if="loading" class="text-center text-neutral-500 py-12 text-sm">{{ t('common.loading') }}</div>

    <EmptyState v-else-if="!report || report.accounts.length === 0" boxed accent="neutral" icon="chart" :title="t('accounting.general_ledger.empty')" />

    <div v-else class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
      <div class="overflow-x-auto">
        <table class="w-full text-sm" :class="tbl.densityClass.value">
          <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
            <tr>
              <th class="px-3 py-2 w-8"></th>
              <th v-if="tbl.isVisible('account')" class="px-3 py-2 text-left font-medium w-24">{{ t('accounting.general_ledger.col_account') }}</th>
              <th v-if="tbl.isVisible('name')" class="px-3 py-2 text-left font-medium">{{ t('accounting.general_ledger.col_name') }}</th>
              <th v-if="tbl.isVisible('account_type')" class="px-3 py-2 text-left font-medium w-24">{{ t('accounting.general_ledger.col_type') }}</th>
              <th v-if="tbl.isVisible('synthetic')" class="px-3 py-2 text-left font-medium w-28">{{ t('accounting.general_ledger.col_synthetic') }}</th>
              <th v-if="tbl.isVisible('opening_md')" class="px-3 py-2 text-right font-medium">{{ t('accounting.general_ledger.col_ps_md') }}</th>
              <th v-if="tbl.isVisible('opening_d')" class="px-3 py-2 text-right font-medium">{{ t('accounting.general_ledger.col_ps_d') }}</th>
              <th v-if="tbl.isVisible('turnover_md')" class="px-3 py-2 text-right font-medium">{{ t('accounting.general_ledger.col_turnover_md') }}</th>
              <th v-if="tbl.isVisible('turnover_d')" class="px-3 py-2 text-right font-medium">{{ t('accounting.general_ledger.col_turnover_d') }}</th>
              <th v-if="tbl.isVisible('closing_md')" class="px-3 py-2 text-right font-medium">{{ t('accounting.general_ledger.col_ks_md') }}</th>
              <th v-if="tbl.isVisible('closing_d')" class="px-3 py-2 text-right font-medium">{{ t('accounting.general_ledger.col_ks_d') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <template v-for="a in report.accounts" :key="a.account_id">
              <tr :id="`gl-account-${a.account_id}`" class="cursor-pointer hover:bg-neutral-50"
                :class="{ 'bg-primary-50/40': expandedId === a.account_id }" @click="toggleExpand(a)">
                <td class="px-3 py-2 text-neutral-400">
                  <span class="inline-block transition-transform" :class="{ 'rotate-90': expandedId === a.account_id }">▸</span>
                </td>
                <td v-if="tbl.isVisible('account')" class="px-3 py-2">
                  <RouterLink :to="accountLink(a)" @click.stop :title="t('accounting.general_ledger.open_account')"
                    class="row-link font-mono text-primary-600 hover:text-primary-700 hover:underline">
                    {{ a.account_code }}
                  </RouterLink>
                </td>
                <td v-if="tbl.isVisible('name')" class="px-3 py-2">{{ a.name }}</td>
                <td v-if="tbl.isVisible('account_type')" class="px-3 py-2 text-neutral-600 whitespace-nowrap">{{ t(`accounting.accounts.type.${a.account_type}`) }}</td>
                <td v-if="tbl.isVisible('synthetic')" class="px-3 py-2 text-neutral-600 whitespace-nowrap">
                  {{ a.is_synthetic ? t('accounting.general_ledger.synthetic') : t('accounting.general_ledger.analytic') }}
                </td>
                <td v-if="tbl.isVisible('opening_md')" class="px-3 py-2 text-right font-mono">{{ formatMoney(a.opening_md) }}</td>
                <td v-if="tbl.isVisible('opening_d')" class="px-3 py-2 text-right font-mono">{{ formatMoney(a.opening_d) }}</td>
                <td v-if="tbl.isVisible('turnover_md')" class="px-3 py-2 text-right font-mono">{{ formatMoney(a.turnover_md) }}</td>
                <td v-if="tbl.isVisible('turnover_d')" class="px-3 py-2 text-right font-mono">{{ formatMoney(a.turnover_d) }}</td>
                <td v-if="tbl.isVisible('closing_md')" class="px-3 py-2 text-right font-mono">{{ formatMoney(a.closing_md) }}</td>
                <td v-if="tbl.isVisible('closing_d')" class="px-3 py-2 text-right font-mono">{{ formatMoney(a.closing_d) }}</td>
              </tr>
              <tr v-if="expandedId === a.account_id">
                <td :colspan="visibleColCount" class="px-3 py-3 bg-neutral-50">
                  <div class="flex flex-wrap items-baseline justify-between gap-2 mb-2">
                    <span class="text-xs text-neutral-500 uppercase tracking-wide font-medium">
                      {{ t('accounting.general_ledger.months_detail') }}
                    </span>
                    <span class="text-xs text-neutral-400">{{ t('accounting.general_ledger.month_expand_hint') }}</span>
                  </div>
                  <!-- Bez rozbaleného měsíce je to krátký seznam (užší je čitelnější);
                       s řádky deníku uvnitř potřebuje tabulka celou šířku. -->
                  <table class="w-full text-sm" :class="openMonth ? '' : 'max-w-2xl'">
                    <thead class="text-xs text-neutral-500 uppercase tracking-wide">
                      <tr>
                        <th class="px-2 py-1 w-6"></th>
                        <th class="px-2 py-1 text-left font-medium">{{ t('accounting.general_ledger.col_month') }}</th>
                        <th class="px-2 py-1 text-right font-medium w-40">{{ t('accounting.general_ledger.col_turnover_md') }}</th>
                        <th class="px-2 py-1 text-right font-medium w-40">{{ t('accounting.general_ledger.col_turnover_d') }}</th>
                      </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-200">
                      <template v-for="m in report.months" :key="m">
                        <tr class="hover:bg-neutral-100"
                          :class="[
                            (a.months[m]?.md || a.months[m]?.d) ? 'cursor-pointer' : 'text-neutral-400',
                            openMonth === m ? 'bg-neutral-100' : '',
                          ]"
                          @click="(a.months[m]?.md || a.months[m]?.d) && toggleMonth(a, m)">
                          <td class="px-2 py-1 text-neutral-400">
                            <span v-if="a.months[m]?.md || a.months[m]?.d"
                              class="inline-block transition-transform" :class="{ 'rotate-90': openMonth === m }">▸</span>
                          </td>
                          <td class="px-2 py-1 font-mono">{{ m }}</td>
                          <td class="px-2 py-1 text-right font-mono">{{ formatMoney(a.months[m]?.md ?? 0) }}</td>
                          <td class="px-2 py-1 text-right font-mono">{{ formatMoney(a.months[m]?.d ?? 0) }}</td>
                        </tr>
                        <tr v-if="openMonth === m">
                          <td colspan="4" class="px-2 py-2">
                            <div class="bg-surface border border-neutral-200 rounded-md overflow-hidden">
                              <div class="px-2 py-1.5 bg-neutral-50 border-b border-neutral-100 flex flex-wrap items-center justify-between gap-2">
                                <span class="text-xs font-medium text-neutral-500">
                                  {{ t('accounting.general_ledger.month_lines', { month: m }) }}
                                </span>
                                <RouterLink :to="monthStatementLink(a, m)"
                                  class="text-xs text-primary-600 hover:text-primary-700 hover:underline">
                                  {{ t('accounting.account_statement.title') }}
                                </RouterLink>
                              </div>
                              <div v-if="monthLoading" class="px-2 py-4 text-center text-xs text-neutral-500">{{ t('common.loading') }}</div>
                              <div v-else-if="monthLines.length === 0" class="px-2 py-4 text-center text-xs text-neutral-500">
                                {{ t('accounting.general_ledger.month_empty') }}
                              </div>
                              <div v-else class="overflow-x-auto">
                                <table class="w-full text-sm">
                                  <thead class="text-xs text-neutral-500 uppercase tracking-wide bg-neutral-50">
                                    <tr>
                                      <th class="px-2 py-1 text-left font-medium w-24">{{ t('accounting.account_statement.col_date') }}</th>
                                      <th class="px-2 py-1 text-left font-medium w-36">{{ t('accounting.account_statement.col_document') }}</th>
                                      <th class="px-2 py-1 text-left font-medium w-24">{{ t('accounting.account_statement.col_line_account') }}</th>
                                      <th class="px-2 py-1 text-left font-medium">{{ t('accounting.account_statement.col_description') }}</th>
                                      <th class="px-2 py-1 text-right font-medium w-28">{{ t('accounting.account_statement.col_md') }}</th>
                                      <th class="px-2 py-1 text-right font-medium w-28">{{ t('accounting.account_statement.col_d') }}</th>
                                    </tr>
                                  </thead>
                                  <tbody class="divide-y divide-neutral-100">
                                    <tr v-for="(it, idx) in monthLines" :key="`${it.entry_id}-${idx}`" class="hover:bg-neutral-50">
                                      <td class="px-2 py-1 whitespace-nowrap">{{ formatDate(it.entry_date) }}</td>
                                      <td class="px-2 py-1">
                                        <RouterLink :to="lineLink(it)"
                                          class="font-mono text-xs text-primary-600 hover:text-primary-700 hover:underline">
                                          {{ it.document_no || t('accounting.account_statement.journal_link', { id: it.entry_id }) }}
                                        </RouterLink>
                                      </td>
                                      <td class="px-2 py-1 font-mono text-xs text-neutral-500">{{ it.account_code }}</td>
                                      <td class="px-2 py-1">{{ it.description || '—' }}</td>
                                      <td class="px-2 py-1 text-right font-mono">
                                        <template v-if="it.side === 'debit'">{{ formatMoney(it.amount) }}</template>
                                      </td>
                                      <td class="px-2 py-1 text-right font-mono">
                                        <template v-if="it.side === 'credit'">{{ formatMoney(it.amount) }}</template>
                                      </td>
                                    </tr>
                                  </tbody>
                                </table>
                              </div>
                              <div v-if="monthTotal > monthLines.length" class="px-2 py-1.5 border-t border-neutral-100 text-xs text-neutral-500">
                                {{ t('accounting.general_ledger.month_more', { shown: monthLines.length, total: monthTotal }) }}
                                <RouterLink :to="monthStatementLink(a, m)" class="text-primary-600 hover:underline ml-1">
                                  {{ t('accounting.account_statement.title') }}
                                </RouterLink>
                              </div>
                            </div>
                          </td>
                        </tr>
                      </template>
                    </tbody>
                  </table>
                </td>
              </tr>
            </template>
          </tbody>
          <tfoot>
            <tr class="border-t-2 border-neutral-300 font-semibold bg-neutral-50">
              <td class="px-3 py-2" colspan="3">{{ t('accounting.general_ledger.totals') }}</td>
              <td v-if="tbl.isVisible('account_type')" class="px-3 py-2"></td>
              <td v-if="tbl.isVisible('synthetic')" class="px-3 py-2"></td>
              <td v-if="tbl.isVisible('opening_md')" class="px-3 py-2 text-right font-mono">{{ formatMoney(report.totals.opening_md) }}</td>
              <td v-if="tbl.isVisible('opening_d')" class="px-3 py-2 text-right font-mono">{{ formatMoney(report.totals.opening_d) }}</td>
              <td v-if="tbl.isVisible('turnover_md')" class="px-3 py-2 text-right font-mono">{{ formatMoney(report.totals.turnover_md) }}</td>
              <td v-if="tbl.isVisible('turnover_d')" class="px-3 py-2 text-right font-mono">{{ formatMoney(report.totals.turnover_d) }}</td>
              <td v-if="tbl.isVisible('closing_md')" class="px-3 py-2 text-right font-mono">{{ formatMoney(report.totals.closing_md) }}</td>
              <td v-if="tbl.isVisible('closing_d')" class="px-3 py-2 text-right font-mono">{{ formatMoney(report.totals.closing_d) }}</td>
            </tr>
          </tfoot>
        </table>
      </div>
    </div>
  </div>
</template>

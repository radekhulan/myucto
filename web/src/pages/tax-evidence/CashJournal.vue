<script setup lang="ts">
import { ref, reactive, computed, watch, nextTick, onBeforeUnmount } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import {
  taxEvidenceApi,
  type CashJournalReport,
  type CashJournalParams,
  type CashBucket,
  type CashJournalRow,
  type CashJournalInstrument,
  type TaxBucketOverride,
} from '@/api/taxEvidence'
import { usePaneDom } from '@/composables/usePaneDom'
import { requestSupplierId } from '@/workspace/supplierContext'
import { useToast } from '@/composables/useToast'
import { formatMoney } from '@/composables/useFormat'
import FilterBar, { type FilterChip } from '@/components/ui/FilterBar.vue'
import ColumnPicker from '@/components/ui/ColumnPicker.vue'
import DensityToggle from '@/components/ui/DensityToggle.vue'
import { useTablePrefs, type ColumnDef } from '@/composables/useTablePrefs'
import { ICONS, btnOutline, btnOutlineSm } from '@/components/ui/buttonStyles'
import EmptyState from '@/components/ui/EmptyState.vue'
import DateInput from '@/components/ui/DateInput.vue'
import CashJournalMovementModal from '@/components/tax-evidence/CashJournalMovementModal.vue'
import CashJournalSourceLink from '@/components/tax-evidence/CashJournalSourceLink.vue'
import type { CashJournalNote } from '@/api/taxEvidence'

const { t } = useI18n()
const toast = useToast()
const route = useRoute()
const router = useRouter()
const paneDom = usePaneDom()
let disposed = false
onBeforeUnmount(() => { disposed = true; ++loadSeq })

const focusedSource = computed(() => {
  const type = route.query.source_type
  const id = Number(route.query.source_id)
  return typeof type === 'string' && /^[a-z_]+$/.test(type) && Number.isSafeInteger(id) && id > 0
    ? `${type}-${id}` : null
})
const focusMissing = computed(() => focusedSource.value && !loading.value && report.value
  && !report.value.rows.some(row => rowKey(row) === focusedSource.value))

const report = ref<CashJournalReport | null>(null)
const loading = ref(false)

const currentYear = new Date().getFullYear()
const years = Array.from({ length: 6 }, (_, i) => currentYear - i)

const filters = reactive({
  year: currentYear as number,
  from: '',
  to: '',
  classification: '' as CashBucket | '',
})

function queryParams(): CashJournalParams {
  const period = filters.from && filters.to
    ? { from: filters.from, to: filters.to }
    : { year: Number(filters.year) }
  return { ...period, ...(filters.classification ? { classification: filters.classification } : {}) }
}

function applyFilters() {
  const query = {
    ...route.query,
    source_type: undefined, source_id: undefined,
    year: String(filters.year), from: filters.from || undefined, to: filters.to || undefined,
    classification: filters.classification || undefined,
  }
  if (['year', 'from', 'to', 'classification', 'source_type', 'source_id'].every(key => route.query[key] === query[key as keyof typeof query])) return
  // Invalidate outstanding requests before navigation updates the route watcher.
  if (JSON.stringify(queryParams()) !== requestedParams) {
    ++loadSeq
    loading.value = true
  }
  void router.push({ query })
}

function clearFilter(key: string) {
  if (key === 'classification') filters.classification = ''
  if (key === 'year') filters.year = currentYear
  if (key === 'from') filters.from = ''
  if (key === 'to') filters.to = ''
  applyFilters()
}
function clearFilters() {
  Object.assign(filters, { year: currentYear, from: '', to: '', classification: '' })
  applyFilters()
}
function showUnclassified() {
  if (filters.classification === 'nezarazeno') return
  filters.classification = 'nezarazeno'
  applyFilters()
}

let loadSeq = 0
const loadedParams = ref<CashJournalParams | null>(null)

async function load() {
  const seq = ++loadSeq
  const supplierId = requestSupplierId()
  const params = queryParams()
  loading.value = true
  try {
    const result = await taxEvidenceApi.cashJournal(params)
    if (seq !== loadSeq || disposed || supplierId !== requestSupplierId()) return
    report.value = result
    loadedParams.value = params
  } catch (e: any) {
    if (seq !== loadSeq || disposed || supplierId !== requestSupplierId()) return
    toast.error(e?.response?.data?.error?.message || t('common.error'))
    report.value = null
  } finally {
    if (seq === loadSeq && !disposed && supplierId === requestSupplierId()) loading.value = false
  }
}

// Drill-down: řádek nese vazbu na vydanou/přijatou fakturu (kde existuje).
function docLink(row: CashJournalRow) {
  if (row.invoice_id) {
    return { name: 'invoice-detail', params: { id: row.invoice_id } }
  }
  if (row.purchase_invoice_id) {
    return { name: 'purchase-invoice-detail', params: { id: row.purchase_invoice_id } }
  }
  if (row.gopay_clearing_id) {
    return { name: 'gopay', query: { clearing: String(row.gopay_clearing_id) } }
  }
  return null
}

const CLASS_KEY: Record<string, string> = {
  income_taxable: 'tax_evidence.cash_journal.class_income_taxable',
  income_exempt: 'tax_evidence.cash_journal.class_income_exempt',
  income_nontax: 'tax_evidence.cash_journal.class_income_nontax',
  expense_taxable: 'tax_evidence.cash_journal.class_expense_taxable',
  expense_nontax: 'tax_evidence.cash_journal.class_expense_nontax',
  transfer: 'tax_evidence.cash_journal.class_transfer',
  private: 'tax_evidence.cash_journal.class_private',
  nezarazeno: 'tax_evidence.cash_journal.class_unclassified',
}
const filterChips = computed<FilterChip[]>(() => [
  ...(filters.year !== currentYear ? [{ key: 'year', label: t('tax_evidence.cash_journal.filter_year'), value: String(filters.year) }] : []),
  ...(filters.from ? [{ key: 'from', label: t('tax_evidence.cash_journal.filter_from'), value: filters.from }] : []),
  ...(filters.to ? [{ key: 'to', label: t('tax_evidence.cash_journal.filter_to'), value: filters.to }] : []),
  ...(filters.classification ? [{ key: 'classification', label: t('tax_evidence.cash_journal.col_class'), value: classLabel(filters.classification) }] : []),
])

let requestedParams = ''
watch(() => JSON.stringify([route.query.year, route.query.from, route.query.to, route.query.classification, focusedSource.value]), () => {
  const year = Number(route.query.year)
  filters.year = Number.isInteger(year) && year >= 1900 && year <= 9999 ? year : currentYear
  filters.from = typeof route.query.from === 'string' ? route.query.from : ''
  filters.to = typeof route.query.to === 'string' ? route.query.to : ''
  const classification = route.query.classification
  filters.classification = typeof classification === 'string' && Object.hasOwn(CLASS_KEY, classification)
    ? classification as CashBucket : ''
  if (focusedSource.value) {
    // Odkaz na zdroj ukáže pohyb v celém roce, aby zůstaly zachované součty deníku.
    filters.from = ''
    filters.to = ''
    filters.classification = ''
    if (route.query.from || route.query.to || route.query.classification) {
      void router.replace({ query: { ...route.query, from: undefined, to: undefined, classification: undefined } })
    }
  }
  const params = JSON.stringify(queryParams())
  if (params !== requestedParams || (!loading.value && (!report.value || params !== JSON.stringify(loadedParams.value)))) {
    requestedParams = params
    void load()
  }
}, { immediate: true })

watch([report, focusedSource, loading], async () => {
  const target = focusedSource.value
  if (!target || loading.value) return
  await nextTick()
  if (disposed || target !== focusedSource.value || loading.value) return
  const row = [...paneDom.querySelectorAll<HTMLElement>(`[data-cash-journal-source="${target}"]`)]
    .find(el => el.getClientRects().length > 0)
  row?.scrollIntoView({ block: 'nearest', inline: 'nearest', behavior: 'smooth' })
}, { flush: 'post' })

function classLabel(bucket: string): string {
  const key = CLASS_KEY[bucket]
  return key ? t(key) : bucket
}
function classBadge(row: CashJournalRow): string {
  if (row.unclassified) return 'bg-danger-50 text-danger-600'
  switch (row.bucket) {
    case 'income_taxable': return 'bg-success-50 text-success-600'
    case 'expense_taxable': return 'bg-warning-50 text-warning-600'
    case 'income_exempt': return 'bg-primary-50 text-primary-700'
    case 'transfer': return 'bg-neutral-100 text-neutral-600'
    case 'private': return 'bg-neutral-100 text-neutral-600'
    default: return 'bg-neutral-100 text-neutral-500'
  }
}

// G2 (audit 2026-07): ruční klasifikační override pohybu (1027). Kbelíky bez
// 'nezarazeno' (to není zapisovatelný stav — jen výsledek chybějící klasifikace).
const OVERRIDE_BUCKETS: TaxBucketOverride[] = [
  'income_taxable', 'income_exempt', 'income_nontax',
  'expense_taxable', 'expense_nontax', 'transfer', 'private',
]
const RESET_VALUE = '__reset__'
const classifyingKey = ref<string | null>(null)
function rowKey(row: CashJournalRow): string {
  return `${row.source_type}-${row.source_id}`
}
// Override (1027) existuje jen pro bank_transaction_id / cash_document_id (XOR
// constraint migrace) — noha C (invoice_payment/purchase_invoice, virtuální
// úhrady bez fyzického dokladu) klasifikaci nepodporuje.
function instrumentLabel(instrument: CashJournalInstrument): string {
  switch (instrument) {
    case 'cash': return t('tax_evidence.cash_journal.instrument_cash')
    case 'bank': return t('tax_evidence.cash_journal.instrument_bank')
    case 'gopay': return t('tax_evidence.cash_journal.instrument_gopay')
    default: return t('tax_evidence.cash_journal.instrument_virtual')
  }
}

// Výplatu GoPay spárovanou s vyúčtováním deník vždy vede jako převod, ruční zařazení
// by ji nezměnilo (příjem vznikl už inkasem platby přes GoPay).
function isClassifiable(row: CashJournalRow): boolean {
  return (row.source_type === 'bank' || row.source_type === 'cash') && row.gopay_clearing_id === null
}
async function onClassify(row: CashJournalRow, value: string, select: HTMLSelectElement) {
  if (!value) return
  const key = rowKey(row)
  classifyingKey.value = key
  try {
    if (value === RESET_VALUE) {
      await taxEvidenceApi.deleteClassification(row.source_type, row.source_id)
      toast.success(t('tax_evidence.cash_journal.classify_removed'))
    } else {
      await taxEvidenceApi.classifyMovement({
        source_type: row.source_type,
        source_id: row.source_id,
        tax_bucket: value as TaxBucketOverride,
      })
      toast.success(t('tax_evidence.cash_journal.classify_saved'))
    }
    await load()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  } finally {
    classifyingKey.value = null
    select.value = ''
  }
}

// R10: blokující varování (nezařazené příchozí pohyby mimo daňový základ).
const blockingWarnings = computed(() => (report.value?.warnings ?? []).filter(w => w.blocking))
const unclassifiedCount = computed(() => report.value?.blocking_unclassified_count ?? 0)

const checksOpen = ref(false)

const exporting = ref(false)
async function exportFile(format: 'pdf' | 'xlsx') {
  if (!report.value || loading.value || exporting.value || classifyingKey.value !== null || !loadedParams.value) return
  const params = { ...loadedParams.value }
  const year = report.value.year
  exporting.value = true
  try {
    const r = await taxEvidenceApi.exportReport('/tax-evidence/cash-journal/export', { ...params, format })
    downloadBlob(r.data as unknown as Blob, `penezni-denik-${year}.${format}`)
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
  { key: 'date', labelKey: 'tax_evidence.cash_journal.col_date', required: true },
  { key: 'doc', labelKey: 'tax_evidence.cash_journal.col_doc', required: true },
  { key: 'partner', labelKey: 'tax_evidence.cash_journal.col_partner' },
  { key: 'desc', labelKey: 'tax_evidence.cash_journal.col_desc' },
  { key: 'income', labelKey: 'tax_evidence.cash_journal.col_income' },
  { key: 'expense', labelKey: 'tax_evidence.cash_journal.col_expense' },
  { key: 'balance', labelKey: 'tax_evidence.cash_journal.col_balance' },
  { key: 'class', labelKey: 'tax_evidence.cash_journal.col_class' },
  { key: 'source', labelKey: 'tax_evidence.cash_journal.col_source', defaultHidden: true },
  { key: 'base', labelKey: 'tax_evidence.cash_journal.col_base', defaultHidden: true },
  { key: 'vat', labelKey: 'tax_evidence.cash_journal.col_vat', defaultHidden: true },
  { key: 'notes', labelKey: 'tax_evidence.cash_journal.col_notes' },
  { key: 'actions', labelKey: 'tax_evidence.cash_journal.col_actions', required: true },
]
const tbl = useTablePrefs('cash_journal', COLUMNS)

// Poznámky a přílohy pohybu (dialog). Po změně se aktualizuje jen řádek, ne celý deník.
const movementRow = ref<CashJournalRow | null>(null)
function onNotesChanged(notes: CashJournalNote[]) {
  if (movementRow.value) movementRow.value.notes = notes.map(n => ({ id: n.id, body: n.body, pinned: n.pinned }))
}

</script>

<template>
  <div>
    <div class="flex flex-wrap items-center justify-between gap-2 mb-4">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('tax_evidence.cash_journal.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5">{{ t('tax_evidence.cash_journal.subtitle') }}</p>
      </div>
      <div class="flex flex-wrap items-center gap-2">
        <ColumnPicker class="hidden md:block" :ctrl="tbl" />
        <DensityToggle class="hidden md:block" :ctrl="tbl" />
        <button :disabled="!report || exporting || loading || classifyingKey !== null" @click="exportFile('pdf')" :class="btnOutline('primary')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.download" /></svg>
          {{ t('tax_evidence.cash_journal.export_pdf') }}
        </button>
        <button :disabled="!report || exporting || loading || classifyingKey !== null" @click="exportFile('xlsx')" :class="btnOutline('primary')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.download" /></svg>
          {{ t('tax_evidence.cash_journal.export_xlsx') }}
        </button>
      </div>
    </div>

    <div v-if="focusMissing" role="status" class="mb-4 rounded-lg border border-primary-200 bg-primary-50 p-3 text-sm text-primary-700">
      {{ t('tax_evidence.cash_journal.source_not_found') }}
    </div>

    <FilterBar :chips="filterChips" :active-count="filterChips.length" @clear="clearFilter" @clear-all="clearFilters">
      <div>
        <label for="cash-journal-year" class="block text-xs font-medium text-neutral-500 mb-1">{{ t('tax_evidence.cash_journal.filter_year') }}</label>
        <select id="cash-journal-year" v-model="filters.year" @change="applyFilters" class="h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface">
          <option v-if="!years.includes(filters.year)" :value="filters.year">{{ filters.year }}</option>
          <option v-for="y in years" :key="y" :value="y">{{ y }}</option>
        </select>
      </div>
      <div>
        <label for="cash-journal-from" class="block text-xs font-medium text-neutral-500 mb-1">{{ t('tax_evidence.cash_journal.filter_from') }}</label>
        <DateInput id="cash-journal-from" v-model="filters.from" @change="applyFilters" class="h-9 px-2 border border-neutral-300 rounded-md text-sm" />
      </div>
      <div>
        <label for="cash-journal-to" class="block text-xs font-medium text-neutral-500 mb-1">{{ t('tax_evidence.cash_journal.filter_to') }}</label>
        <DateInput id="cash-journal-to" v-model="filters.to" @change="applyFilters" class="h-9 px-2 border border-neutral-300 rounded-md text-sm" />
      </div>
      <div>
        <label for="cash-journal-classification" class="block text-xs font-medium text-neutral-500 mb-1">{{ t('tax_evidence.cash_journal.col_class') }}</label>
        <select id="cash-journal-classification" v-model="filters.classification" @change="applyFilters" class="h-9 max-w-full px-2 border border-neutral-300 rounded-md text-sm bg-surface">
          <option value="">{{ t('tax_evidence.cash_journal.all_classifications') }}</option>
          <option v-for="(_, bucket) in CLASS_KEY" :key="bucket" :value="bucket">{{ classLabel(bucket) }}</option>
        </select>
      </div>
    </FilterBar>

    <!-- R10: blokující varování — nezařazené pohyby mimo daňový základ -->
    <div v-if="report && blockingWarnings.length > 0"
      class="mb-4 rounded-lg bg-danger-50 border border-danger-500/40 text-danger-600 p-3">
      <div class="flex items-start gap-2">
        <svg class="w-5 h-5 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.bell" /></svg>
        <div class="text-sm">
          <p class="font-semibold">{{ unclassifiedCount > 0
            ? t('tax_evidence.cash_journal.unclassified_warning', { n: unclassifiedCount })
            : t('tax_evidence.cash_journal.blocking_warning', { n: blockingWarnings.length }) }}</p>
          <button v-if="unclassifiedCount > 0" type="button" :disabled="filters.classification === 'nezarazeno'" @click="showUnclassified" :class="[btnOutlineSm('warning'), 'mt-2 whitespace-nowrap']">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.search" /></svg>
            {{ t('tax_evidence.cash_journal.show_unclassified') }}
          </button>
          <ul class="mt-1.5 space-y-0.5 text-danger-500">
            <li v-for="(w, i) in blockingWarnings" :key="i">{{ w.message }}</li>
          </ul>
        </div>
      </div>
    </div>

    <!-- Souhrn (totály) -->
    <div v-if="report" data-test="cash-journal-summary" class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-3 mb-4 transition-opacity" :class="{ 'opacity-60': loading }">
      <div class="text-xs text-neutral-500 uppercase tracking-wide font-medium mb-2">{{ t('tax_evidence.cash_journal.summary_period') }}</div>
      <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-3 text-sm">
        <div>
          <div class="text-xs text-neutral-500">{{ t('tax_evidence.cash_journal.opening_balance') }}</div>
          <div class="font-mono font-medium">{{ formatMoney(report.opening_balance) }}</div>
        </div>
        <div>
          <div class="text-xs text-neutral-500">{{ t('tax_evidence.cash_journal.totals_income_tax') }}</div>
          <div class="font-mono font-medium text-success-600">{{ formatMoney(report.totals.prijem_danovy) }}</div>
        </div>
        <div>
          <div class="text-xs text-neutral-500">{{ t('tax_evidence.cash_journal.totals_income_exempt') }}</div>
          <div class="font-mono font-medium">{{ formatMoney(report.totals.prijem_osvobozeny) }}</div>
        </div>
        <div>
          <div class="text-xs text-neutral-500">{{ t('tax_evidence.cash_journal.totals_income_nontax') }}</div>
          <div class="font-mono font-medium">{{ formatMoney(report.totals.prijem_nedanovy) }}</div>
        </div>
        <div>
          <div class="text-xs text-neutral-500">{{ t('tax_evidence.cash_journal.totals_expense_tax') }}</div>
          <div class="font-mono font-medium text-warning-600">{{ formatMoney(report.totals.vydaj_danovy) }}</div>
        </div>
        <div>
          <div class="text-xs text-neutral-500">{{ t('tax_evidence.cash_journal.totals_nontax') }}</div>
          <div class="font-mono font-medium">{{ formatMoney(report.totals.vydaj_nedanovy) }}</div>
        </div>
        <div>
          <div class="text-xs text-neutral-500">{{ t('tax_evidence.cash_journal.totals_transfers') }}</div>
          <div class="font-mono font-medium">{{ formatMoney(report.totals.prevody) }}</div>
        </div>
        <div>
          <div class="text-xs text-neutral-500">{{ t('tax_evidence.cash_journal.totals_private') }}</div>
          <div class="font-mono font-medium">{{ formatMoney(report.totals.private) }}</div>
        </div>
        <div v-if="report.totals.nezarazeno">
          <div class="text-xs text-neutral-500">{{ t('tax_evidence.cash_journal.totals_unclassified') }}</div>
          <div class="font-mono font-medium text-danger-500">{{ formatMoney(report.totals.nezarazeno) }}</div>
        </div>
        <div>
          <div class="text-xs text-neutral-500">{{ t('tax_evidence.cash_journal.closing_balance') }}</div>
          <div class="font-mono font-medium">{{ formatMoney(report.closing_balance) }}</div>
        </div>
      </div>
      <div class="mt-3 pt-3 border-t border-neutral-100 flex items-baseline justify-between">
        <span class="text-sm font-semibold">{{ t('tax_evidence.cash_journal.totals_net') }}</span>
        <span class="font-mono text-lg font-semibold text-primary-700">{{ formatMoney(report.totals.net) }}</span>
      </div>
    </div>

    <!-- Zůstatky po peněžních prostředcích (pokladna, banka, GoPay, úhrady bez pohybu) -->
    <div v-if="report && report.balances && report.balances.length > 1" class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-3 mb-4 transition-opacity" :class="{ 'opacity-60': loading }">
      <div class="text-xs text-neutral-500 uppercase tracking-wide font-medium mb-2">{{ t('tax_evidence.cash_journal.balances_title') }}</div>
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="text-left text-xs text-neutral-500">
            <tr>
              <th class="py-1 pr-3 font-medium">{{ t('tax_evidence.cash_journal.balances_instrument') }}</th>
              <th class="py-1 pr-3 font-medium text-right">{{ t('tax_evidence.cash_journal.opening_balance') }}</th>
              <th class="py-1 pr-3 font-medium text-right">{{ t('tax_evidence.cash_journal.balances_movement') }}</th>
              <th class="py-1 font-medium text-right">{{ t('tax_evidence.cash_journal.closing_balance') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="b in report.balances" :key="b.instrument">
              <td class="py-1.5 pr-3 whitespace-nowrap">{{ instrumentLabel(b.instrument) }}</td>
              <td class="py-1.5 pr-3 text-right font-mono whitespace-nowrap">{{ formatMoney(b.opening) }}</td>
              <td class="py-1.5 pr-3 text-right font-mono whitespace-nowrap">{{ formatMoney(b.movement) }}</td>
              <td class="py-1.5 text-right font-mono font-medium whitespace-nowrap">{{ formatMoney(b.closing) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
      <p v-if="report.balances.some(b => b.instrument === 'gopay')" class="mt-2 text-xs text-neutral-500">{{ t('tax_evidence.cash_journal.balances_gopay_hint') }}</p>
    </div>

    <!-- Kontrola vůči přiznanému příjmu (R5 variance) -->
    <div v-if="report && report.checks" class="bg-surface border border-neutral-200 rounded-lg shadow-sm mb-4">
      <button type="button" @click="checksOpen = !checksOpen"
        class="cursor-pointer w-full flex items-center justify-between gap-2 px-3 py-2.5 text-left">
        <span class="text-xs text-neutral-500 uppercase tracking-wide font-medium">{{ t('tax_evidence.cash_journal.checks_title') }}</span>
        <span class="flex items-center gap-2 text-sm">
          <span class="text-neutral-500">{{ t('tax_evidence.cash_journal.check_variance') }}:</span>
          <span class="font-mono font-medium" :class="Math.abs(report.checks.variance) > 0.01 ? 'text-warning-600' : 'text-success-600'">{{ formatMoney(report.checks.variance) }}</span>
          <svg class="w-4 h-4 text-neutral-400 transition" :class="{ 'rotate-180': checksOpen }" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" /></svg>
        </span>
      </button>
      <div v-if="checksOpen" class="px-3 pb-3 border-t border-neutral-100">
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-1.5 text-sm pt-3">
          <div class="flex justify-between gap-2"><span class="text-neutral-500">{{ t('tax_evidence.cash_journal.check_denik_income') }}</span><span class="font-mono">{{ formatMoney(report.checks.denik_prijem_danovy) }}</span></div>
          <div class="flex justify-between gap-2"><span class="text-neutral-500">{{ t('tax_evidence.cash_journal.check_annual_income') }}</span><span class="font-mono">{{ formatMoney(report.checks.annual_income) }}</span></div>
          <div class="flex justify-between gap-2"><span class="text-neutral-500">{{ t('tax_evidence.cash_journal.check_partial') }}</span><span class="font-mono">{{ formatMoney(report.checks.explanations.partial_payments) }}</span></div>
          <div class="flex justify-between gap-2"><span class="text-neutral-500">{{ t('tax_evidence.cash_journal.check_cash_sale') }}</span><span class="font-mono">{{ formatMoney(report.checks.explanations.cash_sales_without_invoice) }}</span></div>
          <div class="flex justify-between gap-2"><span class="text-neutral-500">{{ t('tax_evidence.cash_journal.check_virtual_leg') }}</span><span class="font-mono">{{ formatMoney(report.checks.explanations.virtual_leg) }}</span></div>
          <div class="flex justify-between gap-2"><span class="text-neutral-500">{{ t('tax_evidence.cash_journal.check_credit_note_offsets') }}</span><span class="font-mono">{{ formatMoney(report.checks.explanations.credit_note_offsets) }}</span></div>
          <div class="flex justify-between gap-2"><span class="text-neutral-500">{{ t('tax_evidence.cash_journal.check_residual') }}</span><span class="font-mono">{{ formatMoney(report.checks.residual) }}</span></div>
        </div>
        <p class="mt-2 text-xs text-neutral-400">{{ t('tax_evidence.cash_journal.check_note') }}</p>
      </div>
    </div>

    <p v-if="report && report.classification" class="mb-3 text-sm text-neutral-500" role="status">{{ t('tax_evidence.cash_journal.rows_shown', { shown: report.filtered_rows, total: report.total_rows }) }}</p>

    <div v-if="loading && !report" class="text-center text-neutral-500 py-12 text-sm">{{ t('common.loading') }}</div>

    <EmptyState v-else-if="report && report.rows.length === 0 && report.total_rows > 0 && report.classification"
      variant="filtered" :title="t('tax_evidence.cash_journal.empty_classification')"
      :cta="t('tax_evidence.cash_journal.clear_classification')" @action="clearFilter('classification')" boxed />

    <EmptyState v-else-if="!report || report.rows.length === 0"
      icon="coin" :title="t('tax_evidence.cash_journal.empty')" boxed />

    <div v-else data-test="cash-journal-table" class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden transition-opacity" :class="{ 'opacity-60': loading }">
      <div class="overflow-x-auto">
        <table v-column-labels="tbl" :aria-busy="loading" class="w-full text-sm" :class="tbl.densityClass.value">
          <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
            <tr>
              <th v-if="tbl.isVisible('date')" class="px-3 py-2 text-left font-medium w-24">{{ t('tax_evidence.cash_journal.col_date') }}</th>
              <th v-if="tbl.isVisible('doc')" class="px-3 py-2 text-left font-medium">{{ t('tax_evidence.cash_journal.col_doc') }}</th>
              <th v-if="tbl.isVisible('partner')" class="px-3 py-2 text-left font-medium">{{ t('tax_evidence.cash_journal.col_partner') }}</th>
              <th v-if="tbl.isVisible('desc')" class="px-3 py-2 text-left font-medium">{{ t('tax_evidence.cash_journal.col_desc') }}</th>
              <th v-if="tbl.isVisible('income')" class="px-3 py-2 text-right font-medium">{{ t('tax_evidence.cash_journal.col_income') }}</th>
              <th v-if="tbl.isVisible('expense')" class="px-3 py-2 text-right font-medium">{{ t('tax_evidence.cash_journal.col_expense') }}</th>
              <th v-if="tbl.isVisible('balance')" class="px-3 py-2 text-right font-medium">{{ t('tax_evidence.cash_journal.col_balance') }}</th>
              <th v-if="tbl.isVisible('class')" class="px-3 py-2 text-left font-medium">{{ t('tax_evidence.cash_journal.col_class') }}</th>
              <th v-if="tbl.isVisible('source')" class="px-3 py-2 text-left font-medium">{{ t('tax_evidence.cash_journal.col_source') }}</th>
              <th v-if="tbl.isVisible('base')" class="px-3 py-2 text-right font-medium">{{ t('tax_evidence.cash_journal.col_base') }}</th>
              <th v-if="tbl.isVisible('vat')" class="px-3 py-2 text-right font-medium">{{ t('tax_evidence.cash_journal.col_vat') }}</th>
              <th v-if="tbl.isVisible('notes')" class="px-3 py-2 text-left font-medium">{{ t('tax_evidence.cash_journal.col_notes') }}</th>
              <th v-if="tbl.isVisible('actions')" scope="col" :aria-label="t('tax_evidence.cash_journal.col_actions')" class="px-3 py-2 w-8"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="(row, i) in report.rows" :key="`${row.source_type}-${row.source_id}-${i}`"
              class="scroll-mt-4" :data-cash-journal-source="rowKey(row)"
              :aria-current="rowKey(row) === focusedSource ? 'true' : undefined"
              :class="rowKey(row) === focusedSource
                ? 'bg-primary-50 ring-2 ring-inset ring-primary-500'
                : [row.unclassified || row.fx_rate_missing ? 'bg-danger-50/40' : '', 'hover:bg-neutral-50']">
              <td v-if="tbl.isVisible('date')" class="px-3 py-2 whitespace-nowrap">{{ row.date }}</td>
              <td v-if="tbl.isVisible('doc')" class="px-3 py-2">
                <RouterLink v-if="docLink(row)" :to="docLink(row)!"
                  class="font-mono text-primary-600 hover:text-primary-700 hover:underline">
                  {{ row.doc_no || '—' }}
                </RouterLink>
                <span v-else class="font-mono text-neutral-600">{{ row.doc_no || '—' }}</span>
              </td>
              <td v-if="tbl.isVisible('partner')" class="px-3 py-2">{{ row.partner || '—' }}</td>
              <td v-if="tbl.isVisible('desc')" class="px-3 py-2 text-neutral-600">{{ row.description || '—' }}</td>
              <td v-if="tbl.isVisible('income')" class="px-3 py-2 text-right font-mono text-success-600">{{ row.income != null ? formatMoney(row.income) : '' }}</td>
              <td v-if="tbl.isVisible('expense')" class="px-3 py-2 text-right font-mono text-warning-600">{{ row.expense != null ? formatMoney(row.expense) : '' }}</td>
              <td v-if="tbl.isVisible('balance')" class="px-3 py-2 text-right font-mono">{{ formatMoney(row.running_balance) }}</td>
              <td v-if="tbl.isVisible('class')" class="px-3 py-2 whitespace-nowrap">
                <div class="flex items-center gap-1.5">
                  <span class="inline-block px-2 py-0.5 rounded-full text-xs font-medium" :class="classBadge(row)">{{ classLabel(row.bucket) }}</span>
                  <select
                    v-if="isClassifiable(row)"
                    class="h-6 text-xs border border-neutral-200 rounded px-1 bg-surface text-neutral-500 disabled:opacity-50"
                    :disabled="loading || classifyingKey !== null"
                    @change="onClassify(row, ($event.target as HTMLSelectElement).value, $event.target as HTMLSelectElement)"
                  >
                    <option value="">{{ t('tax_evidence.cash_journal.classify_placeholder') }}</option>
                    <option v-for="b in OVERRIDE_BUCKETS" :key="b" :value="b">{{ classLabel(b) }}</option>
                    <option :value="RESET_VALUE">{{ t('tax_evidence.cash_journal.classify_reset') }}</option>
                  </select>
                </div>
              </td>
              <td v-if="tbl.isVisible('source')" class="px-3 py-2 text-neutral-500 whitespace-nowrap">{{ row.source_type }}</td>
              <td v-if="tbl.isVisible('base')" class="px-3 py-2 text-right font-mono text-neutral-500">{{ formatMoney(row.base) }}</td>
              <td v-if="tbl.isVisible('vat')" class="px-3 py-2 text-right font-mono text-neutral-500">{{ formatMoney(row.vat) }}</td>
              <td v-if="tbl.isVisible('notes')" class="px-3 py-2">
                <div class="flex items-start gap-2">
                  <button type="button" :class="btnOutlineSm('neutral')" class="whitespace-nowrap" :title="t('tax_evidence.cash_journal.notes_title')"
                          :aria-label="t('tax_evidence.cash_journal.notes_title')" data-test="cash-journal-notes-open" @click="movementRow = row">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.doc" /></svg>
                    <span v-if="(row.notes?.length ?? 0) + (row.attachment_count ?? 0) > 0">{{ (row.notes?.length ?? 0) }} / {{ row.attachment_count ?? 0 }}</span>
                  </button>
                  <span v-if="row.notes?.length" class="text-xs text-neutral-600 line-clamp-2 max-w-xs whitespace-pre-wrap break-words">{{ row.notes[0]!.body }}</span>
                </div>
              </td>
              <td v-if="tbl.isVisible('actions')" class="px-3 py-2 text-right whitespace-nowrap">
                <div class="flex flex-wrap items-center justify-end gap-1"><CashJournalSourceLink :row="row" /></div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
    <CashJournalMovementModal v-if="movementRow" :row="movementRow" @changed="onNotesChanged" @close="movementRow = null; load()" />
  </div>
</template>

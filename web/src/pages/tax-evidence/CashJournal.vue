<script setup lang="ts">
import { ref, onMounted, reactive, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import {
  taxEvidenceApi,
  type CashJournalReport,
  type CashJournalRow,
  type TaxBucketOverride,
} from '@/api/taxEvidence'
import { useToast } from '@/composables/useToast'
import { formatMoney } from '@/composables/useFormat'
import ColumnPicker from '@/components/ui/ColumnPicker.vue'
import DensityToggle from '@/components/ui/DensityToggle.vue'
import { useTablePrefs, type ColumnDef } from '@/composables/useTablePrefs'
import { ICONS, btnOutline } from '@/components/ui/buttonStyles'
import EmptyState from '@/components/ui/EmptyState.vue'
import DateInput from '@/components/ui/DateInput.vue'

const { t } = useI18n()
const toast = useToast()

const report = ref<CashJournalReport | null>(null)
const loading = ref(false)

const currentYear = new Date().getFullYear()
const years = Array.from({ length: 6 }, (_, i) => currentYear - i)

const filters = reactive({
  year: currentYear as number,
  from: '',
  to: '',
})

function queryParams() {
  if (filters.from && filters.to) {
    return { from: filters.from, to: filters.to }
  }
  return { year: Number(filters.year) }
}

async function load() {
  loading.value = true
  try {
    report.value = await taxEvidenceApi.cashJournal(queryParams())
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
    report.value = null
  } finally {
    loading.value = false
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
function isClassifiable(row: CashJournalRow): boolean {
  return row.source_type === 'bank' || row.source_type === 'cash'
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
const unclassifiedCount = computed(() =>
  (report.value?.rows ?? []).filter(r => r.unclassified && r.blocking).length,
)

const checksOpen = ref(false)

const exporting = ref(false)
async function exportFile(format: 'pdf' | 'xlsx') {
  if (!report.value) return
  exporting.value = true
  try {
    const r = await taxEvidenceApi.exportReport('/tax-evidence/cash-journal/export', { ...queryParams(), format })
    downloadBlob(r.data as unknown as Blob, `penezni-denik-${report.value.year}.${format}`)
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
]
const tbl = useTablePrefs('cash_journal', COLUMNS)

onMounted(load)
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
        <button :disabled="!report || exporting" @click="exportFile('pdf')" :class="btnOutline('primary')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.download" /></svg>
          {{ t('tax_evidence.cash_journal.export_pdf') }}
        </button>
        <button :disabled="!report || exporting" @click="exportFile('xlsx')" :class="btnOutline('primary')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.download" /></svg>
          {{ t('tax_evidence.cash_journal.export_xlsx') }}
        </button>
      </div>
    </div>

    <!-- Filtry -->
    <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-3 mb-4">
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
        <div>
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('tax_evidence.cash_journal.filter_year') }}</label>
          <select v-model="filters.year" @change="load"
            class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface">
            <option v-for="y in years" :key="y" :value="y">{{ y }}</option>
          </select>
        </div>
        <div>
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('tax_evidence.cash_journal.filter_from') }}</label>
          <DateInput v-model="filters.from" @change="load"
            class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm" />
        </div>
        <div>
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('tax_evidence.cash_journal.filter_to') }}</label>
          <DateInput v-model="filters.to" @change="load"
            class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm" />
        </div>
      </div>
    </div>

    <!-- R10: blokující varování — nezařazené pohyby mimo daňový základ -->
    <div v-if="report && blockingWarnings.length > 0"
      class="mb-4 rounded-lg bg-danger-50 border border-danger-500/40 text-danger-600 p-3">
      <div class="flex items-start gap-2">
        <svg class="w-5 h-5 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.bell" /></svg>
        <div class="text-sm">
          <p class="font-semibold">{{ unclassifiedCount > 0
            ? t('tax_evidence.cash_journal.unclassified_warning', { n: unclassifiedCount })
            : t('tax_evidence.cash_journal.blocking_warning', { n: blockingWarnings.length }) }}</p>
          <ul class="mt-1.5 space-y-0.5 text-danger-500">
            <li v-for="(w, i) in blockingWarnings" :key="i">{{ w.message }}</li>
          </ul>
        </div>
      </div>
    </div>

    <!-- Souhrn (totály) -->
    <div v-if="report" class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-3 mb-4">
      <div class="text-xs text-neutral-500 uppercase tracking-wide font-medium mb-2">{{ t('tax_evidence.cash_journal.summary_title') }}</div>
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
          <div class="flex justify-between gap-2"><span class="text-neutral-500">{{ t('tax_evidence.cash_journal.check_residual') }}</span><span class="font-mono">{{ formatMoney(report.checks.residual) }}</span></div>
        </div>
        <p class="mt-2 text-xs text-neutral-400">{{ t('tax_evidence.cash_journal.check_note') }}</p>
      </div>
    </div>

    <div v-if="loading" class="text-center text-neutral-500 py-12 text-sm">{{ t('common.loading') }}</div>

    <EmptyState v-else-if="!report || report.rows.length === 0"
      icon="coin" :title="t('tax_evidence.cash_journal.empty')" boxed />

    <div v-else class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
      <div class="overflow-x-auto">
        <table class="w-full text-sm" :class="tbl.densityClass.value">
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
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="(row, i) in report.rows" :key="`${row.source_type}-${row.source_id}-${i}`"
              class="hover:bg-neutral-50" :class="row.unclassified || row.fx_rate_missing ? 'bg-danger-50/40' : ''">
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
                    :disabled="classifyingKey === rowKey(row)"
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
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>

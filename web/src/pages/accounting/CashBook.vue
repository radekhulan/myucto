<script setup lang="ts">
import { ref, onMounted, reactive, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import { cashApi, type CashRegister, type CashBookReport, type CashBookItem, type CashPurpose } from '@/api/cash'
import { cashErrorMessage } from '@/api/cashErrors'
import { useToast } from '@/composables/useToast'
import { formatDate, formatMoney } from '@/composables/useFormat'
import ColumnPicker from '@/components/ui/ColumnPicker.vue'
import DensityToggle from '@/components/ui/DensityToggle.vue'
import { useTablePrefs, type ColumnDef } from '@/composables/useTablePrefs'
import { ICONS, btnOutline } from '@/components/ui/buttonStyles'
import EmptyState from '@/components/ui/EmptyState.vue'
import PaginationBar from '@/components/ui/PaginationBar.vue'
import { appIsoDate } from '@/utils/date'
import DateInput from '@/components/ui/DateInput.vue'

const { t } = useI18n()
const toast = useToast()

const registers = ref<CashRegister[]>([])
const report = ref<CashBookReport | null>(null)
const loading = ref(false)

const registerId = ref<number | ''>('')
const page = ref(1)
const perPage = ref(50)
const totalPages = computed(() => {
  if (!report.value) return 1
  return Math.max(1, Math.ceil(report.value.total / (report.value.per_page || perPage.value)))
})

function defaultRange(): { from: string; to: string } {
  const year = new Date().getFullYear()
  return { from: `${year}-01-01`, to: appIsoDate() }
}
const filters = reactive<{ from: string; to: string; q: string; doc_type: '' | 'in' | 'out'; purpose: '' | CashPurpose }>({
  from: defaultRange().from, to: defaultRange().to, q: '', doc_type: '', purpose: '',
})
const PURPOSES: CashPurpose[] = ['sale', 'purchase', 'invoice_payment', 'purchase_payment', 'transfer', 'other']
const filtersActive = computed(() => filters.q !== '' || filters.doc_type !== '' || filters.purpose !== '')

function resetFilters() {
  const r = defaultRange()
  filters.from = r.from; filters.to = r.to
  filters.q = ''; filters.doc_type = ''; filters.purpose = ''
  applyFilters()
}

async function load() {
  if (registerId.value === '') return
  loading.value = true
  try {
    report.value = await cashApi.getBook(Number(registerId.value), {
      from: filters.from, to: filters.to,
      q: filters.q || undefined,
      doc_type: filters.doc_type || undefined,
      purpose: filters.purpose || undefined,
      page: page.value, per_page: perPage.value,
    })
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
    report.value = null
  } finally {
    loading.value = false
  }
}

function applyFilters() { page.value = 1; load() }
function goToPage(p: number) {
  const np = Math.min(Math.max(1, p), totalPages.value)
  if (np !== page.value) { page.value = np; load() }
}

function typeLabel(it: CashBookItem): string {
  if (!it.doc_type) return '—'
  return it.doc_type === 'in' ? t('cash.type.in_short') : t('cash.type.out_short')
}

function purposeLabel(it: CashBookItem): string {
  return it.purpose ? t(`cash.purpose.${it.purpose}`) : '—'
}

const COLUMNS: ColumnDef[] = [
  { key: 'date', labelKey: 'cash.col.date', required: true },
  { key: 'number', labelKey: 'cash.col.number' },
  { key: 'type', labelKey: 'cash.col.type' },
  { key: 'partner', labelKey: 'cash.col.partner' },
  { key: 'description', labelKey: 'cash.col.description' },
  { key: 'purpose', labelKey: 'cash.col.purpose', defaultHidden: true },
  { key: 'tax_date', labelKey: 'cash.col.tax_date', defaultHidden: true },
  { key: 'entry', labelKey: 'cash.col.entry', defaultHidden: true },
  { key: 'income', labelKey: 'cash.col.income' },
  { key: 'expense', labelKey: 'cash.col.expense' },
  { key: 'balance', labelKey: 'cash.col.balance', required: true },
]
const tbl = useTablePrefs('cash_book', COLUMNS)
const visibleColCount = computed(() => tbl.columns.filter(c => tbl.isVisible(c.key)).length)

function openPdf() {
  if (registerId.value === '') return
  window.open(cashApi.bookPdfUrl(Number(registerId.value), {
    from: filters.from, to: filters.to,
    q: filters.q, doc_type: filters.doc_type, purpose: filters.purpose,
  }), '_blank', 'noopener')
}

onMounted(async () => {
  // Prázdný select po síťové chybě je k nerozeznání od „žádná pokladna není" (M-15).
  try {
    registers.value = await cashApi.listRegisters(true)
  } catch (e: any) {
    registers.value = []
    toast.error(cashErrorMessage(e, t))
  }
  const first = registers.value.find(r => r.is_default) ?? registers.value[0]
  if (first) { registerId.value = first.id; await load() }
})
</script>

<template>
  <div>
    <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('cash.book_title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5">{{ t('cash.title') }}</p>
      </div>
      <div class="flex flex-wrap items-center gap-2">
        <RouterLink to="/accounting/cash" class="text-sm text-neutral-500 hover:text-neutral-700">{{ t('common.back') }}</RouterLink>
        <ColumnPicker class="hidden md:block" :ctrl="tbl" />
        <DensityToggle class="hidden md:block" :ctrl="tbl" />
        <button :disabled="!report" @click="openPdf" :class="btnOutline('primary')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.download" /></svg>
          {{ t('cash.book_pdf') }}
        </button>
      </div>
    </div>

    <!-- Filtry -->
    <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-3 mb-4">
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
        <div>
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('cash.register') }}</label>
          <select v-model="registerId" @change="applyFilters" class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface">
            <option v-for="r in registers" :key="r.id" :value="r.id">{{ r.name }} ({{ r.account_code }})</option>
          </select>
        </div>
        <div>
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('cash.col.date_from') }}</label>
          <DateInput v-model="filters.from" @change="applyFilters" class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm" />
        </div>
        <div>
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('cash.col.date_to') }}</label>
          <DateInput v-model="filters.to" @change="applyFilters" class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm" />
        </div>
        <div>
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('cash.col.type') }}</label>
          <select v-model="filters.doc_type" @change="applyFilters" class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface">
            <option value="">{{ t('common.all') }}</option>
            <option value="in">{{ t('cash.type.in_short') }}</option>
            <option value="out">{{ t('cash.type.out_short') }}</option>
          </select>
        </div>
        <div>
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('cash.col.purpose') }}</label>
          <select v-model="filters.purpose" @change="applyFilters" class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface">
            <option value="">{{ t('common.all') }}</option>
            <option v-for="p in PURPOSES" :key="p" :value="p">{{ t(`cash.purpose.${p}`) }}</option>
          </select>
        </div>
        <div>
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('cash.book_search') }}</label>
          <input v-model="filters.q" type="text" :placeholder="t('cash.book_search_hint')"
            @keyup.enter="applyFilters" @change="applyFilters"
            class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm" />
        </div>
      </div>
      <div class="flex flex-wrap items-center justify-end gap-2 mt-2">
        <button type="button" @click="resetFilters" class="cursor-pointer text-xs text-neutral-500 hover:text-neutral-700">{{ t('accounting.journal.reset_filters') }}</button>
      </div>
    </div>

    <!-- Filtr zužuje jen řádky; počáteční/konečný zůstatek a obraty zůstávají za období. -->
    <div v-if="report && filtersActive" class="mb-4 px-3 py-2 rounded-md bg-primary-50 text-primary-700 text-sm">
      {{ t('cash.book_filtered_note') }}
    </div>

    <!-- Filtrované okno se useklo na limitu — tichá kniha bez části pohybů by lhala. -->
    <div v-if="report?.truncated" class="mb-4 px-3 py-2 rounded-md bg-warning-50 text-warning-700 text-sm">
      {{ t('cash.book_truncated_note') }}
    </div>

    <!-- Warning záporný zůstatek -->
    <div v-if="report && report.balance_negative" class="mb-4 px-3 py-2 rounded-md bg-danger-50 text-danger-600 text-sm">
      {{ t('cash.warning.negative_balance') }}
    </div>

    <!-- Souhrn -->
    <div v-if="report" class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-4">
      <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-3">
        <div class="text-xs text-neutral-500">{{ t('cash.opening_balance') }}</div>
        <div class="text-lg font-semibold font-mono">{{ formatMoney(report.opening_balance) }}</div>
      </div>
      <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-3">
        <div class="text-xs text-neutral-500">{{ t('cash.income_total') }}</div>
        <div class="text-lg font-semibold font-mono text-success-600">{{ formatMoney(report.income_total) }}</div>
      </div>
      <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-3">
        <div class="text-xs text-neutral-500">{{ t('cash.expense_total') }}</div>
        <div class="text-lg font-semibold font-mono text-warning-600">{{ formatMoney(report.expense_total) }}</div>
      </div>
      <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-3">
        <div class="text-xs text-neutral-500">{{ t('cash.closing_balance') }}</div>
        <div class="text-lg font-semibold font-mono" :class="report.closing_balance < 0 ? 'text-danger-500' : ''">{{ formatMoney(report.closing_balance) }}</div>
      </div>
    </div>

    <div v-if="loading" class="text-center text-neutral-500 py-12 text-sm">{{ t('common.loading') }}</div>

    <EmptyState v-else-if="!report || report.items.length === 0" boxed accent="neutral" icon="doc" :title="t('cash.empty.book')" />

    <div v-else class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
      <div class="overflow-x-auto">
        <table class="w-full text-sm" :class="tbl.densityClass.value">
          <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
            <tr>
              <th v-if="tbl.isVisible('date')" class="px-3 py-2 text-left font-medium w-28">{{ t('cash.col.date') }}</th>
              <th v-if="tbl.isVisible('number')" class="px-3 py-2 text-left font-medium w-32">{{ t('cash.col.number') }}</th>
              <th v-if="tbl.isVisible('type')" class="px-3 py-2 text-left font-medium w-16">{{ t('cash.col.type') }}</th>
              <th v-if="tbl.isVisible('partner')" class="px-3 py-2 text-left font-medium hidden md:table-cell">{{ t('cash.col.partner') }}</th>
              <th v-if="tbl.isVisible('description')" class="px-3 py-2 text-left font-medium">{{ t('cash.col.description') }}</th>
              <th v-if="tbl.isVisible('purpose')" class="px-3 py-2 text-left font-medium w-28">{{ t('cash.col.purpose') }}</th>
              <th v-if="tbl.isVisible('tax_date')" class="px-3 py-2 text-left font-medium w-28">{{ t('cash.col.tax_date') }}</th>
              <th v-if="tbl.isVisible('entry')" class="px-3 py-2 text-left font-medium w-24">{{ t('cash.col.entry') }}</th>
              <th v-if="tbl.isVisible('income')" class="px-3 py-2 text-right font-medium w-28">{{ t('cash.col.income') }}</th>
              <th v-if="tbl.isVisible('expense')" class="px-3 py-2 text-right font-medium w-28">{{ t('cash.col.expense') }}</th>
              <th v-if="tbl.isVisible('balance')" class="px-3 py-2 text-right font-medium w-32">{{ t('cash.col.balance') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr class="bg-neutral-50/60">
              <td class="px-3 py-2" :colspan="visibleColCount - 1">{{ t('cash.opening_balance') }}</td>
              <td class="px-3 py-2 text-right font-mono">{{ formatMoney(report.opening_balance) }}</td>
            </tr>
            <tr v-for="(it, idx) in report.items" :key="`${it.entry_id}-${idx}`" class="hover:bg-neutral-50">
              <td v-if="tbl.isVisible('date')" class="px-3 py-2 whitespace-nowrap">{{ formatDate(it.date) }}</td>
              <td v-if="tbl.isVisible('number')" class="px-3 py-2 font-mono text-xs">
                <RouterLink v-if="it.document_id"
                  :to="{ path: '/accounting/cash', query: { q: it.document_no || '', from: filters.from, to: filters.to } }"
                  class="text-primary-600 hover:text-primary-700">{{ it.document_no || '—' }}</RouterLink>
                <span v-else>{{ it.document_no || '—' }}</span>
              </td>
              <td v-if="tbl.isVisible('type')" class="px-3 py-2">{{ typeLabel(it) }}</td>
              <td v-if="tbl.isVisible('partner')" class="px-3 py-2 hidden md:table-cell truncate max-w-[14rem]">{{ it.partner_name || '—' }}</td>
              <td v-if="tbl.isVisible('description')" class="px-3 py-2 truncate max-w-[20rem]">{{ it.description || '—' }}</td>
              <td v-if="tbl.isVisible('purpose')" class="px-3 py-2 whitespace-nowrap text-neutral-600">{{ purposeLabel(it) }}</td>
              <td v-if="tbl.isVisible('tax_date')" class="px-3 py-2 whitespace-nowrap">{{ it.tax_date ? formatDate(it.tax_date) : '—' }}</td>
              <td v-if="tbl.isVisible('entry')" class="px-3 py-2 font-mono text-xs">
                <RouterLink :to="{ path: '/accounting/journal', query: { entry_id: it.entry_id } }"
                  class="text-primary-600 hover:text-primary-700">#{{ it.entry_id }}</RouterLink>
              </td>
              <td v-if="tbl.isVisible('income')" class="px-3 py-2 text-right font-mono text-success-600">
                <template v-if="it.income != null">{{ formatMoney(it.income) }}</template>
              </td>
              <td v-if="tbl.isVisible('expense')" class="px-3 py-2 text-right font-mono text-warning-600">
                <template v-if="it.expense != null">{{ formatMoney(it.expense) }}</template>
              </td>
              <td v-if="tbl.isVisible('balance')" class="px-3 py-2 text-right font-mono" :class="it.balance < 0 ? 'text-danger-500' : ''">{{ formatMoney(it.balance) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <PaginationBar v-if="!loading && report" class="mt-4" :page="page"
      :per-page="report.per_page || perPage" :total="report.total" @update:page="goToPage" />
  </div>
</template>

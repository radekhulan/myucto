<script setup lang="ts">
import { ref, onMounted, reactive, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink, useRoute } from 'vue-router'
import { cashApi, type CashRegister, type CashDocument, type CashDocumentStatus } from '@/api/cash'
import { cashErrorMessage, cashWarningMessage } from '@/api/cashErrors'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { formatDate, formatMoney } from '@/composables/useFormat'
import SavedFiltersMenu from '@/components/ui/SavedFiltersMenu.vue'
import ColumnPicker from '@/components/ui/ColumnPicker.vue'
import DensityToggle from '@/components/ui/DensityToggle.vue'
import { useTablePrefs, type ColumnDef } from '@/composables/useTablePrefs'
import { useSavedFilters, savedFilterTone, type SavedFilterTone } from '@/composables/useSavedFilters'
import type { SavedFilter } from '@/api/preferences'
import CashRegisterManager from '@/components/cash/CashRegisterManager.vue'
import { ICONS, btnFilled, btnOutline, btnIconSm } from '@/components/ui/buttonStyles'
import CashDocumentDetail from '@/components/accounting/CashDocumentDetail.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import PaginationBar from '@/components/ui/PaginationBar.vue'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import DateInput from '@/components/ui/DateInput.vue'

const { t } = useI18n()
const auth = useAuthStore()
const toast = useToast()
const route = useRoute()

const registers = ref<CashRegister[]>([])
const documents = ref<CashDocument[]>([])
const loading = ref(false)
const managerOpen = ref(false)

const page = ref(1)
const total = ref(0)
const perPage = ref(50)
const totalPages = computed(() => Math.max(1, Math.ceil(total.value / perPage.value)))

function defaultRange(): { from: string; to: string } {
  const year = new Date().getFullYear()
  return { from: `${year}-01-01`, to: `${year}-12-31` }
}

const filters = reactive({
  register_id: '' as number | '',
  doc_type: '' as '' | 'in' | 'out',
  purpose: '' as '' | 'sale' | 'purchase' | 'invoice_payment' | 'purchase_payment' | 'transfer' | 'other',
  status: '' as '' | CashDocumentStatus,
  from: defaultRange().from,
  to: defaultRange().to,
  q: '',
})

const selectedRegister = computed<CashRegister | null>(() => {
  if (registers.value.length === 0) return null
  if (filters.register_id !== '') return registers.value.find(r => r.id === filters.register_id) ?? null
  return registers.value.find(r => r.is_default) ?? registers.value[0]
})

// Tichý catch tady vykresloval prázdný stav „Nejprve založte pokladnu" i po síťové
// chybě a sváděl k založení duplicitní pokladny — proto se selhání hlásí (M-15).
async function loadRegisters() {
  try {
    registers.value = await cashApi.listRegisters()
  } catch (e: any) {
    registers.value = []
    toast.error(cashErrorMessage(e, t))
  }
}

async function load() {
  loading.value = true
  try {
    const r = await cashApi.listDocuments({
      page: page.value,
      register_id: filters.register_id || undefined,
      doc_type: filters.doc_type || undefined,
      purpose: filters.purpose || undefined,
      status: filters.status || undefined,
      from: filters.from || undefined,
      to: filters.to || undefined,
      q: filters.q || undefined,
    })
    documents.value = r.items
    total.value = r.total
    perPage.value = r.per_page
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
    documents.value = []
  } finally {
    loading.value = false
  }
}

function applyFilters() { page.value = 1; load() }
function resetFilters() {
  filters.register_id = ''
  filters.doc_type = ''
  filters.purpose = ''
  filters.status = ''
  filters.from = defaultRange().from
  filters.to = defaultRange().to
  filters.q = ''
  applyFilters()
}

function goToPage(p: number) {
  const np = Math.min(Math.max(1, p), totalPages.value)
  if (np !== page.value) { page.value = np; load(); expandedId.value = null }
}

function buildQuery(): Record<string, string> {
  const q: Record<string, string> = {}
  if (filters.register_id !== '') q.register_id = String(filters.register_id)
  if (filters.doc_type) q.doc_type = filters.doc_type
  if (filters.purpose) q.purpose = filters.purpose
  if (filters.status) q.status = filters.status
  if (filters.from) q.from = filters.from
  if (filters.to) q.to = filters.to
  if (filters.q) q.q = filters.q
  return q
}
function applyQueryToPage(q: Record<string, string>) {
  filters.register_id = q.register_id ? Number(q.register_id) : ''
  filters.doc_type = (q.doc_type === 'in' || q.doc_type === 'out') ? q.doc_type : ''
  filters.purpose = ['sale', 'purchase', 'invoice_payment', 'purchase_payment', 'transfer', 'other'].includes(q.purpose ?? '')
    ? (q.purpose as typeof filters.purpose) : ''
  filters.status = (['draft', 'posted', 'reversed'] as const).includes(q.status as CashDocumentStatus)
    ? (q.status as CashDocumentStatus) : ''
  filters.from = q.from ?? defaultRange().from
  filters.to = q.to ?? defaultRange().to
  filters.q = q.q ?? ''
  applyFilters()
}

const COLUMNS: ColumnDef[] = [
  { key: 'number', labelKey: 'cash.col.number', required: true },
  { key: 'date', labelKey: 'cash.col.date' },
  { key: 'type', labelKey: 'cash.col.type' },
  { key: 'partner', labelKey: 'cash.col.partner' },
  { key: 'description', labelKey: 'cash.col.description' },
  { key: 'amount', labelKey: 'cash.col.amount', required: true },
  { key: 'link', labelKey: 'cash.col.link' },
  { key: 'status', labelKey: 'cash.col.status' },
  { key: 'tax_date', labelKey: 'cash.col.tax_date', defaultHidden: true },
  { key: 'created_at', labelKey: 'cash.col.created_at', defaultHidden: true },
  { key: 'created_by', labelKey: 'cash.col.created_by', defaultHidden: true },
]
const tbl = useTablePrefs('cash_documents', COLUMNS)
const saved = useSavedFilters('cash_documents', { getQuery: buildQuery, applyQuery: applyQueryToPage })
const visibleColCount = computed(() => 2 + tbl.columns.filter(c => tbl.isVisible(c.key)).length)

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

onMounted(async () => {
  await loadRegisters()
  // Deep-link (drill-down z pokladní knihy) má přednost před defaultním filtrem.
  const q = Object.fromEntries(Object.entries(route.query).map(([k, v]) => [k, String(v ?? '')]))
  if (Object.keys(q).some(k => ['register_id', 'doc_type', 'purpose', 'status', 'from', 'to', 'q'].includes(k))) {
    applyQueryToPage(q)
    return
  }
  if (await saved.applyDefaultIfAny()) return
  await load()
})

// ── Detail expand ────────────────────────────────────────────────────────
const expandedId = ref<number | null>(null)
function toggleExpand(d: CashDocument) {
  expandedId.value = expandedId.value === d.id ? null : d.id
}

function purposeLabel(p: string): string { return t(`cash.purpose.${p}`) }

/**
 * Znaménko se skládalo ručně jako '+' / '−' před `formatMoney` — v locale, kde
 * znaménko patří jinam (nebo se u záporu používají závorky), to vyjde špatně.
 * Číslo se proto předá se znaménkem a umístění řeší Intl. Příjem zůstává bez
 * plusu; směr už nese barva i štítek PPD/VPD.
 */
function signedAmount(d: CashDocument): number {
  return d.doc_type === 'in' ? d.total_amount : -d.total_amount
}

function linkFor(d: CashDocument): { label: string; to: any } | null {
  if (d.invoice_id) return { label: d.invoice_number || `#${d.invoice_id}`, to: { name: 'invoice-detail', params: { id: d.invoice_id } } }
  if (d.purchase_invoice_id) return { label: d.purchase_invoice_number || `#${d.purchase_invoice_id}`, to: { name: 'purchase-invoice-detail', params: { id: d.purchase_invoice_id } } }
  return null
}

function openPdf(d: CashDocument) {
  window.open(cashApi.documentPdfUrl(d.id), '_blank', 'noopener')
}

// ── Storno ────────────────────────────────────────────────────────────────
const reverseTarget = ref<CashDocument | null>(null)
const reverseReason = ref('')
const reverseDate = ref('')
const reverseSaving = ref(false)
const reverseError = ref('')

function openReverse(d: CashDocument) {
  reverseTarget.value = d
  reverseReason.value = ''
  reverseDate.value = ''
  reverseError.value = ''
}

async function submitReverse() {
  if (!reverseTarget.value) return
  if (reverseReason.value.trim().length < 3) { reverseError.value = t('cash.validation.reason'); return }
  reverseSaving.value = true
  try {
    const res = await cashApi.reverseDocument(reverseTarget.value.id, reverseReason.value.trim(), reverseDate.value || undefined)
    toast.success(t('common.saved'))
    // M-14: storno hlásí posunutý protizápis i zápornou pokladnu — bez tohohle
    // se o obojím uživatel dozvěděl až z knihy.
    for (const w of res.warnings ?? []) toast.warning(cashWarningMessage(w, t))
    reverseTarget.value = null
    await loadRegisters()
    await load()
  } catch (e: any) {
    reverseError.value = cashErrorMessage(e, t)
  } finally {
    reverseSaving.value = false
  }
}

// ── Trvalé smazání (doklad + účetní zápisy) ───────────────────────────────
const deleteTarget = ref<CashDocument | null>(null)
const deleteSaving = ref(false)
const deleteError = ref('')

function openDelete(d: CashDocument) {
  deleteTarget.value = d
  deleteError.value = ''
}

/**
 * Draft se maže měkce (`cash.document.write`), vystavený doklad ale jde přes
 * `?force=1`, na které server chce `cash.close`. Bez rozlišení viděla dedikovaná
 * role „Pokladní" tlačítko a 403 dostala až po potvrzení dialogu.
 */
function canDelete(d: CashDocument): boolean {
  return auth.canWrite(d.status === 'draft' ? 'cash.document.write' : 'cash.close')
}

/** Číselník pokladen gatuje `CashRegisterAction` na modul `cash`, ne na doklady. */
const canManageRegisters = computed(() => auth.canWrite('cash'))

/**
 * `force=1` maže doklad VČETNĚ deníkových zápisů a nechává trvalou díru v číselné
 * řadě. U rozpracovaného dokladu žádné zápisy ani číslo neexistují, takže tam
 * stačí měkké smazání draftu — posílat force vždy (jak to UI dělalo) je zbytečně
 * destruktivní cesta i pro případ, který ji nepotřebuje.
 */
async function submitDelete() {
  if (!deleteTarget.value) return
  deleteSaving.value = true
  try {
    const res = await cashApi.deleteDocument(deleteTarget.value.id, deleteTarget.value.status !== 'draft')
    toast.success(t('common.deleted'))
    // Díru v číselné řadě (`cash.warning.series_gap`) hlásí server jen u dokladu,
    // který číslo měl. Bez tohohle se odpověď zahodila a uživatel se o díře
    // nedozvěděl — přestože právě kvůli tomu warning vznikl.
    for (const w of res?.warnings ?? []) toast.warning(cashWarningMessage(w, t))
    deleteTarget.value = null
    await loadRegisters()
    await load()
  } catch (e: any) {
    deleteError.value = cashErrorMessage(e, t)
  } finally {
    deleteSaving.value = false
  }
}

function onManagerChanged() { loadRegisters() }

/**
 * Akce hlavičky přes sdílený ActionBar (konvence UI): dvě plné akce podle
 * směru dokladu, kniha jako sekundární, správa pokladen jako utility v „…".
 * Ručně skládaná pětice tlačítek se na mobilu mačkala a pořadí ani tieru
 * neodpovídala zbytku aplikace.
 */
const headerActions = computed<ActionItem[]>(() => {
  const canWrite = auth.canWrite('cash.document.write')
  const reg = selectedRegister.value
  return [
    {
      key: 'new-in', tier: 'primary', variant: 'success', icon: 'plus',
      label: t('cash.type.in_short'), show: canWrite && reg !== null,
      to: { path: '/accounting/cash/new', query: { doc_type: 'in', register_id: reg?.id } },
    },
    {
      key: 'new-out', tier: 'primary', variant: 'warning', icon: 'plus',
      label: t('cash.type.out_short'), show: canWrite && reg !== null,
      to: { path: '/accounting/cash/new', query: { doc_type: 'out', register_id: reg?.id } },
    },
    {
      key: 'book', tier: 'secondary', variant: 'neutral', icon: 'doc',
      label: t('cash.book_title'), to: '/accounting/cash/book',
    },
    {
      key: 'registers', tier: 'overflow', variant: 'neutral', icon: 'edit',
      label: t('cash.registers_manage'), show: canManageRegisters.value, run: () => { managerOpen.value = true },
    },
  ]
})

// ── Vystavení rozpracovaného dokladu ──────────────────────────────────────
const postingId = ref<number | null>(null)

async function postDraft(d: CashDocument) {
  postingId.value = d.id
  try {
    const res = await cashApi.postDocument(d.id)
    toast.success(t('cash.new_document') + ' ' + res.doc_number)
    for (const w of res.warnings) toast.warning(cashWarningMessage(w, t))
    await loadRegisters()
    await load()
  } catch (e: any) {
    toast.error(cashErrorMessage(e, t))
  } finally {
    postingId.value = null
  }
}
</script>

<template>
  <div>
    <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('cash.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5">{{ t('cash.book_title') }}</p>
      </div>
      <div class="flex flex-wrap items-center gap-2">
        <select v-if="registers.length > 1" v-model="filters.register_id" @change="applyFilters"
          class="h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface">
          <option value="">{{ t('common.all') }}</option>
          <option v-for="r in registers" :key="r.id" :value="r.id">{{ r.name }}</option>
        </select>
        <ActionBar :actions="headerActions" />
      </div>
    </div>

    <!-- Hero zůstatek -->
    <div v-if="selectedRegister" class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-4 mb-4 flex flex-wrap items-center justify-between gap-3">
      <div>
        <div class="text-xs text-neutral-500">{{ selectedRegister.name }} · {{ t('cash.balance_as_of', { date: formatDate(selectedRegister.balance_date) }) }}</div>
        <div class="text-2xl font-semibold font-mono" :class="selectedRegister.balance < 0 ? 'text-danger-500' : 'text-neutral-800'">
          {{ formatMoney(selectedRegister.balance) }}
        </div>
        <div class="text-xs text-neutral-400 font-mono mt-0.5">{{ t('cash.register_account') }} {{ selectedRegister.account_code }}</div>
      </div>
      <span v-if="selectedRegister.balance < 0" class="text-xs px-2 py-1 rounded font-medium bg-danger-50 text-danger-500">
        {{ t('cash.warning.negative_balance') }}
      </span>
    </div>

    <!-- Bez pokladny -->
    <EmptyState v-if="!loading && registers.length === 0" boxed accent="neutral" icon="coin"
      :title="t('cash.empty.registers')"
      :cta="canManageRegisters ? t('cash.register_create') : undefined"
      @action="managerOpen = true" />

    <template v-else>
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
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3">
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
            <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('cash.col.status') }}</label>
            <select v-model="filters.status" @change="applyFilters" class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface">
              <option value="">{{ t('common.all') }}</option>
              <option value="draft">{{ t('cash.status.draft') }}</option>
              <option value="posted">{{ t('cash.status.posted') }}</option>
              <option value="reversed">{{ t('cash.status.reversed') }}</option>
            </select>
          </div>
          <div>
            <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('cash.col.description') }}</label>
            <input v-model="filters.q" type="text" @keyup.enter="applyFilters" class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm" />
          </div>
        </div>
        <div class="flex flex-wrap items-center justify-end gap-2 mt-2">
          <button @click="resetFilters" class="cursor-pointer text-xs text-neutral-500 hover:text-neutral-700">{{ t('accounting.journal.reset_filters') }}</button>
          <SavedFiltersMenu :ctrl="saved" />
          <ColumnPicker class="hidden md:block" :ctrl="tbl" />
          <DensityToggle class="hidden md:block" :ctrl="tbl" />
        </div>
      </div>

      <div v-if="loading" class="text-center text-neutral-500 py-12 text-sm">{{ t('common.loading') }}</div>

      <EmptyState v-else-if="documents.length === 0" boxed accent="neutral" icon="doc" :title="t('cash.empty.documents')" />

      <div v-else class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
        <!-- Desktop: tabulka. Na mobilu se skrývá — deset sloupců znamenalo
             1100 px široký blok, kterým se na telefonu muselo vodorovně
             rolovat, zatímco všechny ostatní seznamy v aplikaci tam mají karty. -->
        <div class="hidden md:block overflow-x-auto">
          <table class="w-full text-sm" :class="tbl.densityClass.value">
            <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
              <tr>
                <th class="px-3 py-2 w-8"></th>
                <th v-if="tbl.isVisible('number')" class="px-3 py-2 text-left font-medium w-32">{{ t('cash.col.number') }}</th>
                <th v-if="tbl.isVisible('date')" class="px-3 py-2 text-left font-medium w-28">{{ t('cash.col.date') }}</th>
                <th v-if="tbl.isVisible('type')" class="px-3 py-2 text-left font-medium w-20">{{ t('cash.col.type') }}</th>
                <th v-if="tbl.isVisible('partner')" class="px-3 py-2 text-left font-medium">{{ t('cash.col.partner') }}</th>
                <th v-if="tbl.isVisible('description')" class="px-3 py-2 text-left font-medium">{{ t('cash.col.description') }}</th>
                <th v-if="tbl.isVisible('amount')" class="px-3 py-2 text-right font-medium w-32">{{ t('cash.col.amount') }}</th>
                <th v-if="tbl.isVisible('link')" class="px-3 py-2 text-left font-medium w-28">{{ t('cash.col.link') }}</th>
                <th v-if="tbl.isVisible('status')" class="px-3 py-2 text-center font-medium w-24">{{ t('cash.col.status') }}</th>
                <th v-if="tbl.isVisible('tax_date')" class="px-3 py-2 text-left font-medium w-28">{{ t('cash.col.tax_date') }}</th>
                <th v-if="tbl.isVisible('created_at')" class="px-3 py-2 text-left font-medium w-28">{{ t('cash.col.created_at') }}</th>
                <th v-if="tbl.isVisible('created_by')" class="px-3 py-2 text-left font-medium w-32">{{ t('cash.col.created_by') }}</th>
                <th class="px-3 py-2 text-right font-medium w-24"></th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <template v-for="d in documents" :key="d.id">
                <tr class="hover:bg-neutral-50 cursor-pointer" :class="{ 'opacity-60': d.status === 'reversed' }" @click="toggleExpand(d)">
                  <td class="px-3 py-2 text-neutral-400">
                    <span class="inline-block transition-transform" aria-hidden="true" :class="{ 'rotate-90': expandedId === d.id }">▸</span>
                  </td>
                  <td v-if="tbl.isVisible('number')" class="px-3 py-2 font-mono text-xs" :class="{ 'line-through': d.status === 'reversed' }">{{ d.doc_number || '—' }}</td>
                  <td v-if="tbl.isVisible('date')" class="px-3 py-2 whitespace-nowrap">{{ formatDate(d.issue_date) }}</td>
                  <td v-if="tbl.isVisible('type')" class="px-3 py-2">
                    <span class="text-xs px-2 py-0.5 rounded font-medium"
                      :class="d.doc_type === 'in' ? 'bg-success-50 text-success-600' : 'bg-warning-50 text-warning-600'">
                      {{ d.doc_type === 'in' ? t('cash.type.in_short') : t('cash.type.out_short') }}
                    </span>
                  </td>
                  <td v-if="tbl.isVisible('partner')" class="px-3 py-2 truncate max-w-[16rem]">{{ d.partner_name || '—' }}</td>
                  <td v-if="tbl.isVisible('description')" class="px-3 py-2 truncate max-w-[20rem]">{{ d.description }}</td>
                  <td v-if="tbl.isVisible('amount')" class="px-3 py-2 text-right font-mono"
                    :class="d.doc_type === 'in' ? 'text-success-600' : 'text-warning-600'">
                    {{ formatMoney(signedAmount(d)) }}
                  </td>
                  <td v-if="tbl.isVisible('link')" class="px-3 py-2">
                    <RouterLink v-if="linkFor(d)" :to="linkFor(d)!.to" @click.stop
                      class="font-mono text-xs text-primary-600 hover:text-primary-700">{{ linkFor(d)!.label }}</RouterLink>
                    <span v-else class="text-xs text-neutral-400">{{ purposeLabel(d.purpose) }}</span>
                  </td>
                  <td v-if="tbl.isVisible('status')" class="px-3 py-2 text-center">
                    <span class="text-xs px-2 py-0.5 rounded font-medium"
                      :class="d.status === 'posted' ? 'bg-success-50 text-success-600'
                        : d.status === 'draft' ? 'bg-warning-50 text-warning-600' : 'bg-neutral-100 text-neutral-500'">
                      {{ t(`cash.status.${d.status}`) }}
                    </span>
                  </td>
                  <td v-if="tbl.isVisible('tax_date')" class="px-3 py-2 whitespace-nowrap">{{ d.tax_date ? formatDate(d.tax_date) : '—' }}</td>
                  <td v-if="tbl.isVisible('created_at')" class="px-3 py-2 whitespace-nowrap">{{ formatDate(d.created_at) }}</td>
                  <td v-if="tbl.isVisible('created_by')" class="px-3 py-2 truncate max-w-[10rem]">{{ d.created_by_name || '—' }}</td>
                  <!-- Stejné akce, stejné komponenty jako mobilní karta níž. Dřív tu
                       byly textové glyfy ⭳ / ⟲ bez aria-labelu, zatímco mobil měl
                       btnIconSm + ICONS — dvě reprezentace téže akce. -->
                  <td class="px-3 py-2 text-right whitespace-nowrap" @click.stop>
                    <div class="inline-flex items-center gap-1.5">
                      <RouterLink v-if="auth.canWrite('cash.document.write') && d.status === 'draft'"
                        :to="{ name: 'accounting-cash-edit', params: { id: d.id } }"
                        :title="t('common.edit')" :aria-label="t('common.edit')" :class="btnIconSm('neutral')">
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.edit" /></svg>
                      </RouterLink>
                      <button v-if="auth.canWrite('cash.document.write') && d.status === 'draft'" type="button"
                        @click="postDraft(d)" :disabled="postingId === d.id"
                        :title="t('cash.post_document')" :aria-label="t('cash.post_document')" :class="btnIconSm('success')">
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
                      </button>
                      <button v-if="d.status !== 'draft'" type="button" @click="openPdf(d)"
                        :title="t('cash.print')" :aria-label="t('cash.print')" :class="btnIconSm('neutral')">
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.download" /></svg>
                      </button>
                      <button v-if="auth.canWrite('cash.document.write') && d.status === 'posted'" type="button"
                        @click="openReverse(d)" :title="t('cash.reverse.title')" :aria-label="t('cash.reverse.title')" :class="btnIconSm('warning')">
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.uturn" /></svg>
                      </button>
                      <button v-if="canDelete(d)" type="button" @click="openDelete(d)"
                        :title="t('cash.delete.title')" :aria-label="t('cash.delete.title')" :class="btnIconSm('danger')">
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.trash" /></svg>
                      </button>
                    </div>
                  </td>
                </tr>
                <!-- Detail -->
                <tr v-if="expandedId === d.id">
                  <td :colspan="visibleColCount" class="px-4 py-3 bg-neutral-50">
                    <CashDocumentDetail :doc="d" :purpose-label="purposeLabel" />
                  </td>
                </tr>
              </template>
            </tbody>
          </table>
        </div>

        <!-- Mobil: karty. Pořadí informací sleduje, na co se u pokladního dokladu
             kouká první — kolik a kam, pak teprve číslo a datum. -->
        <div class="md:hidden divide-y divide-neutral-100">
          <div v-for="d in documents" :key="`m-${d.id}`" class="p-3 space-y-2"
            :class="{ 'opacity-60': d.status === 'reversed' }" @click="toggleExpand(d)">
            <div class="flex items-baseline justify-between gap-2">
              <div class="font-mono text-base font-semibold whitespace-nowrap"
                :class="d.doc_type === 'in' ? 'text-success-600' : 'text-warning-600'">
                {{ formatMoney(signedAmount(d)) }}
              </div>
              <div class="flex flex-col items-end gap-1">
                <span class="text-xs px-2 py-0.5 rounded font-medium whitespace-nowrap"
                  :class="d.doc_type === 'in' ? 'bg-success-50 text-success-600' : 'bg-warning-50 text-warning-600'">
                  {{ d.doc_type === 'in' ? t('cash.type.in_short') : t('cash.type.out_short') }}
                </span>
                <span class="text-xs px-2 py-0.5 rounded font-medium whitespace-nowrap"
                  :class="d.status === 'posted' ? 'bg-success-50 text-success-600' : 'bg-neutral-100 text-neutral-500'">
                  {{ t(`cash.status.${d.status}`) }}
                </span>
              </div>
            </div>

            <div class="flex items-baseline justify-between gap-2 text-xs text-neutral-500">
              <span class="font-mono" :class="{ 'line-through': d.status === 'reversed' }">{{ d.doc_number || '—' }}</span>
              <span class="font-mono">{{ formatDate(d.issue_date) }}</span>
            </div>

            <div class="text-xs">
              <div v-if="d.partner_name" class="text-neutral-700 truncate">{{ d.partner_name }}</div>
              <div v-if="d.description" class="text-neutral-500 truncate">{{ d.description }}</div>
              <RouterLink v-if="linkFor(d)" :to="linkFor(d)!.to" @click.stop
                class="font-mono text-primary-600 hover:underline">{{ linkFor(d)!.label }}</RouterLink>
              <span v-else class="text-neutral-400">{{ purposeLabel(d.purpose) }}</span>
            </div>

            <CashDocumentDetail v-if="expandedId === d.id" :doc="d" :purpose-label="purposeLabel"
              class="pt-2 border-t border-neutral-100" />

            <div class="flex flex-wrap items-center justify-end gap-1.5 pt-1" @click.stop>
              <RouterLink v-if="auth.canWrite('cash.document.write') && d.status === 'draft'"
                :to="{ name: 'accounting-cash-edit', params: { id: d.id } }"
                :title="t('common.edit')" :aria-label="t('common.edit')" :class="btnIconSm('neutral')">
                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.edit" /></svg>
              </RouterLink>
              <button v-if="auth.canWrite('cash.document.write') && d.status === 'draft'" type="button"
                @click="postDraft(d)" :disabled="postingId === d.id"
                :title="t('cash.post_document')" :aria-label="t('cash.post_document')" :class="btnIconSm('success')">
                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
              </button>
              <button v-if="d.status !== 'draft'" type="button" @click="openPdf(d)" :title="t('cash.print')" :aria-label="t('cash.print')"
                :class="btnIconSm('neutral')">
                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.download" /></svg>
              </button>
              <button v-if="auth.canWrite('cash.document.write') && d.status === 'posted'" type="button"
                @click="openReverse(d)" :title="t('cash.reverse.title')" :aria-label="t('cash.reverse.title')"
                :class="btnIconSm('warning')">
                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.uturn" /></svg>
              </button>
              <button v-if="canDelete(d)" type="button"
                @click="openDelete(d)" :title="t('cash.delete.title')" :aria-label="t('cash.delete.title')"
                :class="btnIconSm('danger')">
                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.trash" /></svg>
              </button>
            </div>
          </div>
        </div>
      </div>

      <PaginationBar v-if="!loading" class="mt-4" :page="page" :per-page="perPage" :total="total"
        @update:page="goToPage" />
    </template>

    <!-- Modal správy pokladen -->
    <CashRegisterManager v-if="managerOpen" @close="managerOpen = false" @changed="onManagerChanged" />

    <!-- Modal storna -->
    <div v-if="reverseTarget" class="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4" @click.self="reverseTarget = null">
      <div class="bg-surface rounded-xl shadow-lg max-w-md w-full p-5">
        <h3 class="text-lg font-semibold mb-1">{{ t('cash.reverse.title') }}</h3>
        <p class="text-sm text-neutral-500 mb-3">{{ reverseTarget.doc_number }}</p>
        <div class="space-y-3">
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('cash.reverse.reason') }}</label>
            <textarea v-model="reverseReason" rows="2" class="w-full px-3 py-2 border border-neutral-300 rounded-md text-sm"></textarea>
          </div>
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('cash.col.date') }}</label>
            <DateInput v-model="reverseDate" class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm" />
          </div>
          <p class="text-xs text-neutral-500">{{ t('cash.help.reverse_after_filing') }}</p>
          <div v-if="reverseError" class="text-sm text-danger-500">{{ reverseError }}</div>
          <div class="flex justify-end gap-2 pt-1">
            <button @click="reverseTarget = null" :class="btnOutline('neutral')">{{ t('common.cancel') }}</button>
            <button @click="submitReverse" :disabled="reverseSaving" :class="btnFilled('danger')">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.uturn" /></svg>
              {{ reverseSaving ? t('common.saving') : t('cash.reverse.confirm') }}
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Modal trvalého smazání -->
    <div v-if="deleteTarget" class="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4" @click.self="deleteTarget = null">
      <div class="bg-surface rounded-xl shadow-lg max-w-md w-full p-5">
        <h3 class="text-lg font-semibold mb-1">{{ t('cash.delete.title') }}</h3>
        <p class="text-sm text-neutral-500 mb-3">{{ deleteTarget.doc_number || t('cash.status.draft') }}</p>
        <div class="space-y-3">
          <template v-if="deleteTarget.status === 'draft'">
            <p class="text-sm text-neutral-700">{{ t('cash.delete.draft_warning') }}</p>
          </template>
          <template v-else>
            <p class="text-sm text-neutral-700">{{ t('cash.delete.warning') }}</p>
            <p class="text-xs text-neutral-500">{{ t('cash.delete.hint') }}</p>
          </template>
          <div v-if="deleteError" class="text-sm text-danger-500">{{ deleteError }}</div>
          <div class="flex justify-end gap-2 pt-1">
            <button @click="deleteTarget = null" :class="btnOutline('neutral')">{{ t('common.cancel') }}</button>
            <button @click="submitDelete" :disabled="deleteSaving" :class="btnFilled('danger')">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.trash" /></svg>
              {{ deleteSaving ? t('common.saving') : t('cash.delete.confirm') }}
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, reactive, computed, watch, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter, useRoute } from 'vue-router'
import { assetsApi, type AccountSummaryCandidate, type AssetListItem, type AssetStatus, type PurchaseCandidate } from '@/api/assets'
import { postingErrorI18nKey } from '@/api/accounting'
import { useAuthStore } from '@/stores/auth'
import { useSupplierStore } from '@/stores/supplier'
import { useToast } from '@/composables/useToast'
import { formatDate, formatMoney } from '@/composables/useFormat'
import Modal from '@/components/ui/Modal.vue'
import SavedFiltersMenu from '@/components/ui/SavedFiltersMenu.vue'
import ColumnPicker from '@/components/ui/ColumnPicker.vue'
import DensityToggle from '@/components/ui/DensityToggle.vue'
import SortableTh from '@/components/ui/SortableTh.vue'
import CodebookImportDialog from '@/components/accounting/CodebookImportDialog.vue'
import { codebookTransferApi } from '@/api/codebookTransfer'
import { useTablePrefs, type ColumnDef } from '@/composables/useTablePrefs'
import { useSavedFilters, savedFilterTone, type SavedFilterTone } from '@/composables/useSavedFilters'
import type { SavedFilter } from '@/api/preferences'
import { ICONS, btnFilled, btnOutline } from '@/components/ui/buttonStyles'
import EmptyState from '@/components/ui/EmptyState.vue'

const { t } = useI18n()
const auth = useAuthStore()
const supplierStore = useSupplierStore()
// Daňová evidence: odpisy se jen potvrzují do evidence, souhrnné karty z účtů ani číselník osnovy nemá.
const isTaxEvidence = computed(() => supplierStore.currentSupplier?.accounting_mode === 'tax_evidence')
const toast = useToast()
const router = useRouter()
const route = useRoute()

const items = ref<AssetListItem[]>([])
const loading = ref(false)

const page = ref(1)
const total = ref(0)
const perPage = ref(50)
const totalPages = computed(() => Math.max(1, Math.ceil(total.value / perPage.value)))
const rangeFrom = computed(() => (total.value === 0 ? 0 : (page.value - 1) * perPage.value + 1))
const rangeTo = computed(() => Math.min(page.value * perPage.value, total.value))

const filters = reactive({
  status: '' as AssetStatus | '',
  q: '',
})

async function load() {
  loading.value = true
  try {
    const r = await assetsApi.list({
      status: filters.status || undefined,
      q: filters.q || undefined,
      page: page.value,
      // Aktivní řazení = natáhnout celý dataset (BE cap 200), jinak by se řadila jen stránka.
      per_page: tbl.sort.value ? 200 : undefined,
    })
    items.value = r.items
    total.value = r.total
    perPage.value = r.per_page
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  } finally {
    loading.value = false
  }
}

function applyFilters() {
  page.value = 1
  load()
}
function resetFilters() {
  filters.status = ''
  filters.q = ''
  applyFilters()
}
function goToPage(p: number) {
  const np = Math.min(Math.max(1, p), totalPages.value)
  if (np !== page.value) { page.value = np; load() }
}

function buildQuery(): Record<string, string> {
  const q: Record<string, string> = {}
  if (filters.status) q.status = filters.status
  if (filters.q) q.q = filters.q
  return q
}

function applyQueryToPage(q: Record<string, string>) {
  filters.status = (q.status as AssetStatus) || ''
  filters.q = q.q ?? ''
  applyFilters()
}

const COLUMNS: ColumnDef[] = [
  { key: 'inventory_number', labelKey: 'accounting.assets.col_inventory_number', required: true, sortable: true },
  { key: 'name', labelKey: 'accounting.assets.col_name', required: true, sortable: true },
  { key: 'account', labelKey: 'accounting.assets.col_account' },
  { key: 'put_into_use', labelKey: 'accounting.assets.col_put_into_use', sortable: true },
  { key: 'input_price', labelKey: 'accounting.assets.col_input_price', sortable: true },
  { key: 'tax_residual', labelKey: 'accounting.assets.col_tax_residual' },
  { key: 'acc_residual', labelKey: 'accounting.assets.col_acc_residual' },
  { key: 'status', labelKey: 'accounting.assets.col_status', sortable: true },
  { key: 'tax_method', labelKey: 'accounting.assets.col_tax_method', defaultHidden: true },
  { key: 'tax_group', labelKey: 'accounting.assets.col_tax_group', defaultHidden: true },
  { key: 'disposal_date', labelKey: 'accounting.assets.col_disposal_date', defaultHidden: true },
]
const tbl = useTablePrefs('assets', COLUMNS)
const saved = useSavedFilters('assets', { getQuery: buildQuery, applyQuery: applyQueryToPage })

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

// R10: client-side sort — jen nad KOMPLETNÍM datasetem (load při sortu táhne až 200
// položek; nad 200 karet by řazení stránky lhalo, tak se neaplikuje).
watch(() => tbl.sort.value, () => { page.value = 1; load() })

const sortedItems = computed<AssetListItem[]>(() => {
  const s = tbl.sort.value
  if (!s || total.value > items.value.length) return items.value
  const dir = s.dir === 'desc' ? -1 : 1
  const arr = items.value.slice()
  arr.sort((a, b) => {
    let av: string | number = ''
    let bv: string | number = ''
    switch (s.key) {
      case 'inventory_number': av = a.inventory_number; bv = b.inventory_number; break
      case 'name': av = a.name; bv = b.name; break
      case 'put_into_use': av = a.put_into_use_date ?? ''; bv = b.put_into_use_date ?? ''; break
      case 'input_price': av = increasedPrice(a); bv = increasedPrice(b); break
      case 'status': av = a.status; bv = b.status; break
      default: return 0
    }
    if (av < bv) return -1 * dir
    if (av > bv) return 1 * dir
    return 0
  })
  return arr
})

const importOpen = ref(false)

onMounted(async () => {
  if (Object.keys(route.query).length === 0 && await saved.applyDefaultIfAny()) return
  await load()
})

const num = (v: unknown): number => Number(v ?? 0) || 0

/** Zvýšená vstupní cena = VC + Σ TZ (počítá repository, fallback FE). */
function increasedPrice(a: AssetListItem): number {
  if (a.increased_input_price != null) return num(a.increased_input_price)
  return num(a.input_price) + num(a.improvements_total)
}
function taxResidual(a: AssetListItem): number {
  if (a.tax_residual != null) return num(a.tax_residual)
  return Math.max(0, increasedPrice(a) - num(a.opening_tax_amount) - num(a.tax_full_sum))
}
function accResidual(a: AssetListItem): number {
  if (a.acc_residual != null) return num(a.acc_residual)
  return Math.max(0, increasedPrice(a) - num(a.opening_acc_amount) - num(a.acc_amount_sum))
}

const STATUS_BADGE: Record<string, string> = {
  draft: 'bg-neutral-100 text-neutral-600',
  in_use: 'bg-success-50 text-success-600',
  disposed: 'bg-neutral-100 text-neutral-400',
}

function openDetail(a: AssetListItem) {
  router.push({ name: 'accounting-asset-detail', params: { id: a.id } })
}

// ── Modal: založení z přijaté faktury ──────────────────────────────────────
const showCandidates = ref(false)
const candidates = ref<PurchaseCandidate[]>([])
const candidatesLoading = ref(false)

async function openCandidates() {
  showCandidates.value = true
  candidatesLoading.value = true
  try {
    candidates.value = await assetsApi.purchaseCandidates()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
    showCandidates.value = false
  } finally {
    candidatesLoading.value = false
  }
}

function pickCandidate(c: PurchaseCandidate) {
  showCandidates.value = false
  router.push({ name: 'accounting-asset-new', query: { invoice_id: c.id } })
}

// ── Modal: souhrnná karta z účtu bez karet ─────────────────────────────────
const showSummary = ref(false)
const summaryCandidates = ref<AccountSummaryCandidate[]>([])
const summaryLoading = ref(false)
const summarySyncing = ref<string | null>(null)

async function openSummary() {
  showSummary.value = true
  summaryLoading.value = true
  try {
    summaryCandidates.value = await assetsApi.accountSummaryCandidates()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
    showSummary.value = false
  } finally {
    summaryLoading.value = false
  }
}

async function syncSummary(c: AccountSummaryCandidate) {
  summarySyncing.value = c.account_code
  try {
    const r = await assetsApi.syncAccountSummary(c.account_code)
    for (const w of r.warnings || []) toast.warning(w.message)
    toast.success(t(r.created ? 'accounting.assets.account_summary.created' : 'accounting.assets.account_summary.synced',
      { account: c.account_code, count: r.improvements }))
    summaryCandidates.value = await assetsApi.accountSummaryCandidates()
    await load()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  } finally {
    summarySyncing.value = null
  }
}

// ── Modal: zaúčtování odpisů roku ──────────────────────────────────────────
const showBook = ref(false)
const bookYear = ref(new Date().getFullYear() - 1)
const booking = ref(false)
const yearOptions = computed(() => {
  const y = new Date().getFullYear() - 1
  const arr: number[] = []
  for (let i = y; i >= y - 6; i--) arr.push(i)
  return arr
})

/** Popisek majetku pro chybovou hlášku — inventární číslo, ať je karta dohledatelná. */
function assetLabels(ids: number[]): string {
  return ids
    .map(id => items.value.find(a => a.id === id)?.inventory_number || `#${id}`)
    .join(', ')
}

async function runBook() {
  booking.value = true
  try {
    const r = await assetsApi.bookYear(bookYear.value)
    showBook.value = false
    if (r.errors && r.errors.length > 0) {
      // Samotný počet chyb uživateli neporadí nic — důvod (uzavřené období, chybějící
      // účet…) i dotčený majetek přitom odpověď nese. Kódy se slučují, ať u dvaceti
      // karet se stejnou příčinou nevznikne dvacet hlášek.
      const byCode = new Map<string, number[]>()
      for (const e of r.errors) {
        const list = byCode.get(e.code) ?? []
        list.push(e.asset_id)
        byCode.set(e.code, list)
      }
      const detail = [...byCode.entries()]
        .map(([code, ids]) => `${t(postingErrorI18nKey(code))} (${assetLabels(ids)})`)
        .join(' ')
      toast.warning(`${t('accounting.assets.book.result_with_errors', {
        booked: r.booked, skipped: r.skipped, errors: r.errors.length,
      })} ${detail}`)
    } else {
      toast.success(t('accounting.assets.book.result', { booked: r.booked, skipped: r.skipped }))
    }
    await load()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  } finally {
    booking.value = false
  }
}
</script>

<template>
  <div>
    <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('accounting.assets.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5">{{ t('accounting.assets.subtitle') }}</p>
      </div>
      <div class="flex flex-wrap items-center gap-2">
        <button v-if="!isTaxEvidence" @click="codebookTransferApi.download('assets')" :class="btnOutline('primary')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.download" /></svg>
          {{ t('codebookTransfer.export') }}
        </button>
        <button v-if="auth.canWrite('assets.write') && !isTaxEvidence" @click="importOpen = true" :class="btnOutline('primary')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.upload" /></svg>
          {{ t('codebookTransfer.import') }}
        </button>
        <template v-if="auth.canWrite('assets.write')">
          <button @click="showBook = true" :class="btnOutline('neutral')">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.play" /></svg>
            {{ isTaxEvidence ? t('accounting.assets.book_year_de') : t('accounting.assets.book_year') }}
          </button>
          <button v-if="!isTaxEvidence" @click="openSummary" :class="btnOutline('neutral')" class="whitespace-nowrap">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.chart" /></svg>
            {{ t('accounting.assets.account_summary.button') }}
          </button>
          <button @click="openCandidates" :class="btnOutline('primary')">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.doc" /></svg>
            {{ t('accounting.assets.from_invoice') }}
          </button>
          <RouterLink :to="{ name: 'accounting-asset-new' }" :class="btnFilled('primary')">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
            {{ t('accounting.assets.new') }}
          </RouterLink>
        </template>
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
      <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
        <div>
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.assets.filter_status') }}</label>
          <select v-model="filters.status" @change="applyFilters"
            class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface">
            <option value="">{{ t('common.all') }}</option>
            <option value="draft">{{ t('accounting.assets.status.draft') }}</option>
            <option value="in_use">{{ t('accounting.assets.status.in_use') }}</option>
            <option value="disposed">{{ t('accounting.assets.status.disposed') }}</option>
          </select>
        </div>
        <div class="sm:col-span-2">
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.assets.filter_q') }}</label>
          <input v-model="filters.q" type="text" :placeholder="t('accounting.assets.filter_q_placeholder')"
            @keyup.enter="applyFilters" @change="applyFilters"
            class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm" />
        </div>
      </div>
      <div class="flex flex-wrap items-center justify-end gap-2 mt-2">
        <button @click="resetFilters" class="cursor-pointer text-xs text-neutral-500 hover:text-neutral-700">{{ t('accounting.assets.reset_filters') }}</button>
        <SavedFiltersMenu :ctrl="saved" />
        <ColumnPicker class="hidden md:block" :ctrl="tbl" />
        <DensityToggle class="hidden md:block" :ctrl="tbl" />
      </div>
    </div>

    <div v-if="loading" class="text-center text-neutral-500 py-12 text-sm">{{ t('common.loading') }}</div>

    <EmptyState v-else-if="items.length === 0" boxed icon="box"
      :title="t('accounting.assets.empty')"
      :cta="auth.canWrite('assets.write') ? t('accounting.assets.new') : undefined"
      to="/accounting/assets/new" />

    <div v-else class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
      <!-- Desktop: tabulka. Na mobilu se skrývá — z osmi sloupců byly vidět
           čtyři a za okrajem zůstávaly zrovna ceny a stav, tedy to, kvůli čemu
           se na kartu majetku kouká. -->
      <div class="hidden md:block overflow-x-auto">
        <table v-column-labels="tbl" class="w-full text-sm" :class="tbl.densityClass.value">
          <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
            <tr>
              <SortableTh v-if="tbl.isVisible('inventory_number')" :label="t('accounting.assets.col_inventory_number')" sort-key="inventory_number" :sort="tbl.sort.value" @toggle="tbl.toggleSort" />
              <SortableTh v-if="tbl.isVisible('name')" :label="t('accounting.assets.col_name')" sort-key="name" :sort="tbl.sort.value" @toggle="tbl.toggleSort" />
              <th v-if="tbl.isVisible('account')" class="px-3 py-2 text-left font-medium w-20">{{ t('accounting.assets.col_account') }}</th>
              <SortableTh v-if="tbl.isVisible('put_into_use')" :label="t('accounting.assets.col_put_into_use')" sort-key="put_into_use" :sort="tbl.sort.value" @toggle="tbl.toggleSort" />
              <SortableTh v-if="tbl.isVisible('input_price')" :label="t('accounting.assets.col_input_price')" sort-key="input_price" :sort="tbl.sort.value" align="right" @toggle="tbl.toggleSort" />
              <th v-if="tbl.isVisible('tax_residual')" class="px-3 py-2 text-right font-medium w-32">{{ t('accounting.assets.col_tax_residual') }}</th>
              <th v-if="tbl.isVisible('acc_residual')" class="px-3 py-2 text-right font-medium w-32">{{ t('accounting.assets.col_acc_residual') }}</th>
              <SortableTh v-if="tbl.isVisible('status')" :label="t('accounting.assets.col_status')" sort-key="status" :sort="tbl.sort.value" @toggle="tbl.toggleSort" />
              <th v-if="tbl.isVisible('tax_method')" class="px-3 py-2 text-left font-medium w-32">{{ t('accounting.assets.col_tax_method') }}</th>
              <th v-if="tbl.isVisible('tax_group')" class="px-3 py-2 text-left font-medium w-28">{{ t('accounting.assets.col_tax_group') }}</th>
              <th v-if="tbl.isVisible('disposal_date')" class="px-3 py-2 text-left font-medium w-28">{{ t('accounting.assets.col_disposal_date') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="a in sortedItems" :key="a.id" class="cursor-pointer hover:bg-neutral-50" @click="openDetail(a)">
              <td v-if="tbl.isVisible('inventory_number')" class="px-3 py-2 font-mono text-xs whitespace-nowrap">
                <RouterLink class="row-link" :to="{ name: 'accounting-asset-detail', params: { id: a.id } }" @click.stop @auxclick.stop>{{ a.inventory_number }}</RouterLink>
              </td>
              <td v-if="tbl.isVisible('name')" class="px-3 py-2">{{ a.name }}</td>
              <td v-if="tbl.isVisible('account')" class="px-3 py-2 font-mono text-xs">{{ a.asset_account_code }}</td>
              <td v-if="tbl.isVisible('put_into_use')" class="px-3 py-2 whitespace-nowrap">{{ formatDate(a.put_into_use_date) }}</td>
              <td v-if="tbl.isVisible('input_price')" class="px-3 py-2 text-right font-mono whitespace-nowrap">
                {{ formatMoney(increasedPrice(a)) }}
                <div v-if="num(a.improvements_total) > 0" class="text-xs text-neutral-400">{{ t('accounting.assets.incl_improvements') }}</div>
              </td>
              <td v-if="tbl.isVisible('tax_residual')" class="px-3 py-2 text-right font-mono whitespace-nowrap">
                <template v-if="a.tax_method === 'none'">—</template>
                <template v-else>{{ formatMoney(taxResidual(a)) }}</template>
              </td>
              <td v-if="tbl.isVisible('acc_residual')" class="px-3 py-2 text-right font-mono whitespace-nowrap">
                <template v-if="!a.accumulated_account_code">—</template>
                <template v-else>{{ formatMoney(accResidual(a)) }}</template>
              </td>
              <td v-if="tbl.isVisible('status')" class="px-3 py-2 text-center">
                <span class="text-xs px-2 py-0.5 rounded font-medium" :class="STATUS_BADGE[a.status]">
                  {{ t(`accounting.assets.status.${a.status}`) }}
                </span>
              </td>
              <td v-if="tbl.isVisible('tax_method')" class="px-3 py-2 whitespace-nowrap">{{ t(`accounting.assets.method.${a.tax_method}`) }}</td>
              <td v-if="tbl.isVisible('tax_group')" class="px-3 py-2 whitespace-nowrap">{{ a.tax_group ? t(`accounting.assets.group.${a.tax_group}`) : '—' }}</td>
              <td v-if="tbl.isVisible('disposal_date')" class="px-3 py-2 whitespace-nowrap">{{ a.disposal_date ? formatDate(a.disposal_date) : '—' }}</td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Mobil: karty. Zůstatkové ceny jsou hlavní sdělení, proto stojí
           v samostatném řádku a ne schované za vodorovným rolováním. -->
      <div class="md:hidden divide-y divide-neutral-100">
        <RouterLink v-for="a in sortedItems" :key="`m-${a.id}`"
          :to="{ name: 'accounting-asset-detail', params: { id: a.id } }"
          class="block p-3 space-y-2 hover:bg-neutral-50">
          <div class="flex items-start justify-between gap-2">
            <div class="min-w-0">
              <div class="font-medium truncate">{{ a.name }}</div>
              <div class="font-mono text-xs text-neutral-500">{{ a.inventory_number }} · {{ a.asset_account_code }}</div>
            </div>
            <span class="text-xs px-2 py-0.5 rounded font-medium whitespace-nowrap shrink-0" :class="STATUS_BADGE[a.status]">
              {{ t(`accounting.assets.status.${a.status}`) }}
            </span>
          </div>

          <dl class="grid grid-cols-3 gap-2 text-xs">
            <div>
              <dt class="text-neutral-500">{{ t('accounting.assets.col_input_price') }}</dt>
              <dd class="font-mono whitespace-nowrap">{{ formatMoney(increasedPrice(a)) }}</dd>
            </div>
            <div>
              <dt class="text-neutral-500">{{ t('accounting.assets.col_tax_residual') }}</dt>
              <dd class="font-mono whitespace-nowrap">
                <template v-if="a.tax_method === 'none'">—</template>
                <template v-else>{{ formatMoney(taxResidual(a)) }}</template>
              </dd>
            </div>
            <div>
              <dt class="text-neutral-500">{{ t('accounting.assets.col_acc_residual') }}</dt>
              <dd class="font-mono whitespace-nowrap">
                <template v-if="!a.accumulated_account_code">—</template>
                <template v-else>{{ formatMoney(accResidual(a)) }}</template>
              </dd>
            </div>
          </dl>

          <div class="text-xs text-neutral-500">
            {{ t('accounting.assets.col_put_into_use') }}: {{ formatDate(a.put_into_use_date) }}
            <span v-if="a.disposal_date"> · {{ t('accounting.assets.col_disposal_date') }}: {{ formatDate(a.disposal_date) }}</span>
          </div>
        </RouterLink>
      </div>
    </div>

    <CodebookImportDialog v-model="importOpen" kind="assets" :title="t('codebookTransfer.title_assets')" @imported="load" />

    <nav v-if="!loading && total > perPage" class="mt-4 flex items-center justify-between gap-3 text-sm">
      <span class="text-neutral-500">{{ t('common.pagination_range', { from: rangeFrom, to: rangeTo, total }) }}</span>
      <div class="flex items-center gap-1">
        <button type="button" :disabled="page <= 1" @click="goToPage(page - 1)"
          class="cursor-pointer h-8 px-3 border border-neutral-300 rounded-md hover:bg-neutral-50 disabled:opacity-40 disabled:cursor-not-allowed">‹</button>
        <span class="px-2 text-neutral-600">{{ page }} / {{ totalPages }}</span>
        <button type="button" :disabled="page >= totalPages" @click="goToPage(page + 1)"
          class="cursor-pointer h-8 px-3 border border-neutral-300 rounded-md hover:bg-neutral-50 disabled:opacity-40 disabled:cursor-not-allowed">›</button>
      </div>
    </nav>

    <!-- Modal: kandidáti z přijatých faktur -->
    <Modal v-if="showCandidates" :title="t('accounting.assets.candidates.title')" widthClass="max-w-4xl" @close="showCandidates = false">
      <p class="text-sm text-neutral-500 mb-3">{{ t('accounting.assets.candidates.hint') }}</p>
      <div v-if="candidatesLoading" class="text-center text-neutral-500 py-8 text-sm">{{ t('common.loading') }}</div>
      <EmptyState v-else-if="candidates.length === 0" dense accent="neutral" icon="doc" :title="t('accounting.assets.candidates.empty')" />
      <table v-else class="w-full text-sm">
        <thead class="text-xs text-neutral-500 uppercase tracking-wide">
          <tr>
            <th class="px-2 py-1 text-left font-medium">{{ t('accounting.assets.candidates.col_number') }}</th>
            <th class="px-2 py-1 text-left font-medium">{{ t('accounting.assets.candidates.col_vendor') }}</th>
            <th class="px-2 py-1 text-left font-medium w-28">{{ t('accounting.assets.candidates.col_date') }}</th>
            <th class="px-2 py-1 text-right font-medium w-36">{{ t('accounting.assets.candidates.col_amount') }}</th>
            <th class="px-2 py-1 w-28"></th>
          </tr>
        </thead>
        <tbody class="divide-y divide-neutral-100">
          <tr v-for="c in candidates" :key="c.id" class="cursor-pointer hover:bg-neutral-50" @click="pickCandidate(c)">
            <td class="px-2 py-2 font-mono text-xs">{{ c.varsymbol || c.vendor_invoice_number || `#${c.id}` }}</td>
            <td class="px-2 py-2">{{ c.vendor || '—' }}</td>
            <td class="px-2 py-2 whitespace-nowrap">{{ formatDate(c.tax_date || c.issue_date) }}</td>
            <td class="px-2 py-2 text-right font-mono whitespace-nowrap">{{ formatMoney(num(c.total_without_vat), c.currency || 'CZK') }}</td>
            <td class="px-2 py-2 text-right">
              <span v-if="c.has_asset" class="text-xs px-2 py-0.5 rounded font-medium bg-warning-50 text-warning-600">{{ t('accounting.assets.candidates.has_asset') }}</span>
            </td>
          </tr>
        </tbody>
      </table>
    </Modal>

    <!-- Modal: souhrnná karta z účtu bez karet -->
    <Modal v-if="showSummary" :title="t('accounting.assets.account_summary.title')" widthClass="max-w-3xl" @close="showSummary = false">
      <p class="text-sm text-neutral-500 mb-3">{{ t('accounting.assets.account_summary.hint') }}</p>
      <div v-if="summaryLoading" class="text-center text-neutral-500 py-8 text-sm">{{ t('common.loading') }}</div>
      <EmptyState v-else-if="summaryCandidates.length === 0" dense accent="neutral" icon="chart" :title="t('accounting.assets.account_summary.empty')" />
      <div v-else class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="text-xs text-neutral-500 uppercase tracking-wide">
            <tr>
              <th class="px-2 py-1 text-left font-medium">{{ t('accounting.assets.account_summary.col_account') }}</th>
              <th class="px-2 py-1 text-right font-medium">{{ t('accounting.assets.account_summary.col_balance') }}</th>
              <th class="px-2 py-1 text-right font-medium">{{ t('accounting.assets.account_summary.col_card') }}</th>
              <th class="px-2 py-1"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="c in summaryCandidates" :key="c.account_code">
              <td class="px-2 py-2">
                <span class="font-mono">{{ c.account_code }}</span> {{ c.name }}
                <div class="text-xs text-neutral-400">{{ t('accounting.assets.account_summary.movements', { count: c.movements }) }}</div>
                <div v-if="!c.eligible" class="text-xs text-warning-600">{{ t('accounting.assets.account_summary.has_cards', { count: c.active_cards }) }}</div>
              </td>
              <td class="px-2 py-2 text-right font-mono whitespace-nowrap">{{ formatMoney(num(c.balance)) }}</td>
              <td class="px-2 py-2 text-right font-mono whitespace-nowrap">
                <RouterLink v-if="c.summary_asset_id" :to="{ name: 'accounting-asset-detail', params: { id: c.summary_asset_id } }"
                  class="text-primary-600 hover:underline" :class="{ 'text-warning-600': Math.abs(num(c.summary_value) - num(c.balance)) >= 0.01 }">
                  {{ formatMoney(num(c.summary_value)) }}
                </RouterLink>
                <template v-else>—</template>
              </td>
              <td class="px-2 py-2 text-right">
                <button v-if="c.eligible" :disabled="summarySyncing !== null" @click="syncSummary(c)"
                  :class="c.summary_asset_id ? btnOutline('primary') : btnFilled('primary')" class="whitespace-nowrap">
                  <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="c.summary_asset_id ? ICONS.cycle : ICONS.plus" /></svg>
                  {{ summarySyncing === c.account_code ? t('common.loading')
                    : t(c.summary_asset_id ? 'accounting.assets.account_summary.sync' : 'accounting.assets.account_summary.create') }}
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <div class="flex justify-end mt-4">
        <button @click="showSummary = false" :class="btnOutline('neutral')">{{ t('common.close') }}</button>
      </div>
    </Modal>

    <!-- Modal: zaúčtování odpisů roku -->
    <Modal v-if="showBook" :title="isTaxEvidence ? t('accounting.assets.book_year_de') : t('accounting.assets.book.title')" widthClass="max-w-md" @close="showBook = false">
      <p class="text-sm text-neutral-500 mb-3">{{ isTaxEvidence ? t('accounting.assets.book.hint_de') : t('accounting.assets.book.hint') }}</p>
      <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.assets.book.fiscal_year') }}</label>
      <select v-model.number="bookYear" class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface mb-4">
        <option v-for="y in yearOptions" :key="y" :value="y">{{ y }}</option>
      </select>
      <div class="flex justify-end gap-2">
        <button @click="showBook = false" :class="btnOutline('neutral')">{{ t('common.cancel') }}</button>
        <button :disabled="booking" @click="runBook" :class="btnFilled('success')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
          {{ booking ? t('common.loading') : t('accounting.assets.book.run') }}
        </button>
      </div>
    </Modal>
  </div>
</template>

<script setup lang="ts">
import { ref, reactive, computed, onMounted, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { stockApi, type StockDocument, type StockDocType, type StockDocStatus, type Warehouse } from '@/api/stock'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { formatDate } from '@/composables/useFormat'
import { useRowLink } from '@/composables/useRowLink'
import FilterBar, { type FilterChip } from '@/components/ui/FilterBar.vue'
import SavedFiltersMenu from '@/components/ui/SavedFiltersMenu.vue'
import ColumnPicker from '@/components/ui/ColumnPicker.vue'
import DensityToggle from '@/components/ui/DensityToggle.vue'
import { useTablePrefs, type ColumnDef } from '@/composables/useTablePrefs'
import { useSavedFilters, savedFilterTone, type SavedFilterTone } from '@/composables/useSavedFilters'
import type { SavedFilter } from '@/api/preferences'
import { ICONS, btnFilled, btnOutline } from '@/components/ui/buttonStyles'
import EmptyState from '@/components/ui/EmptyState.vue'

const { t } = useI18n()
const auth = useAuthStore()
const toast = useToast()
const route = useRoute()
const router = useRouter()
const navigate = useRowLink()

const documents = ref<StockDocument[]>([])
const warehouses = ref<Warehouse[]>([])
const loading = ref(false)

const page = ref(1)
const total = ref(0)
const perPage = ref(50)
const totalPages = computed(() => Math.max(1, Math.ceil(total.value / perPage.value)))

const TABS: Array<{ key: '' | StockDocType; labelKey: string }> = [
  { key: '', labelKey: 'stock.documents.tab_all' },
  { key: 'receipt', labelKey: 'stock.documents.tab_receipts' },
  { key: 'issue', labelKey: 'stock.documents.tab_issues' },
  { key: 'transfer', labelKey: 'stock.documents.tab_transfers' },
]

const filters = reactive({
  doc_type: '' as '' | StockDocType,
  warehouse_id: '' as number | '',
  status: '' as '' | StockDocStatus,
  q: '',
})

/**
 * Odznáček a chipy pro sbalenou lištu filtrů.
 * `doc_type` se nepočítá — ten se přepíná záložkami nad lištou (Vše / Příjemky /
 * Výdejky / Převodky), takže je vidět sám o sobě a jako chip by informaci zdvojil.
 */
const activeFilterCount = computed(() => {
  let n = 0
  if (filters.warehouse_id !== '') n++
  if (filters.status) n++
  return n
})

// Pro prázdný stav rozhoduje i hledání a záložka typu dokladu — obojí je důvod,
// proč seznam nic nevrátil, i když se do `activeFilterCount` schválně nepočítá.
const hasActiveFilters = computed(() => activeFilterCount.value > 0 || !!filters.q || !!filters.doc_type)

const filterChips = computed<FilterChip[]>(() => {
  const chips: FilterChip[] = []
  if (filters.warehouse_id !== '') {
    const w = warehouses.value.find(x => x.id === filters.warehouse_id)
    if (w) chips.push({ key: 'warehouse', value: w.name })
  }
  if (filters.status) chips.push({ key: 'status', value: t(`stock.doc_status.${filters.status}`) })
  return chips
})

function clearFilter(key: string) {
  if (key === 'warehouse') filters.warehouse_id = ''
  if (key === 'status') filters.status = ''
  applyFilters()
}

async function load() {
  loading.value = true
  try {
    const r = await stockApi.listDocuments({
      doc_type: filters.doc_type || undefined,
      warehouse_id: filters.warehouse_id || undefined,
      status: filters.status || undefined,
      q: filters.q || undefined,
      limit: perPage.value,
      offset: (page.value - 1) * perPage.value,
    })
    documents.value = r.items
    total.value = r.total
    perPage.value = r.limit
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  } finally {
    loading.value = false
  }
}

function selectTab(k: '' | StockDocType) {
  filters.doc_type = k
  page.value = 1
  router.replace({ query: { ...route.query, type: k || undefined } })
  load()
}
function applyFilters() { page.value = 1; load() }
function resetFilters() {
  filters.warehouse_id = ''
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
  if (filters.doc_type) q.type = filters.doc_type
  if (filters.warehouse_id !== '') q.warehouse_id = String(filters.warehouse_id)
  if (filters.status) q.status = filters.status
  if (filters.q) q.q = filters.q
  return q
}
function applyQueryToPage(q: Record<string, string>) {
  filters.doc_type = (q.type as StockDocType) || ''
  filters.warehouse_id = q.warehouse_id ? Number(q.warehouse_id) : ''
  filters.status = (q.status as StockDocStatus) || ''
  filters.q = q.q ?? ''
  applyFilters()
}

const COLUMNS: ColumnDef[] = [
  { key: 'number', labelKey: 'stock.documents.col_number', required: true },
  { key: 'date', labelKey: 'stock.documents.col_date', required: true },
  { key: 'type', labelKey: 'stock.documents.col_type' },
  { key: 'warehouse', labelKey: 'stock.documents.col_warehouse' },
  { key: 'partner', labelKey: 'stock.documents.col_partner', defaultHidden: true },
  { key: 'description', labelKey: 'stock.documents.col_description' },
  { key: 'origin', labelKey: 'stock.documents.col_origin' },
  { key: 'status', labelKey: 'stock.documents.col_status' },
]
const tbl = useTablePrefs('stock-documents', COLUMNS)
const saved = useSavedFilters('stock-documents', { getQuery: buildQuery, applyQuery: applyQueryToPage })

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

const STATUS_BADGE: Record<StockDocStatus, string> = {
  draft: 'bg-neutral-100 text-neutral-600',
  posted: 'bg-success-50 text-success-600',
  reversed: 'bg-danger-50 text-danger-500',
}
const TYPE_BADGE: Record<StockDocType, string> = {
  receipt: 'bg-primary-50 text-primary-700',
  issue: 'bg-warning-50 text-warning-600',
  transfer: 'bg-accent-50 text-accent-700',
}

function openDetail(d: StockDocument, e?: MouseEvent) {
  navigate(`/stock/documents/${d.id}`, e)
}

onMounted(async () => {
  try { warehouses.value = await stockApi.listWarehouses(true) } catch { warehouses.value = [] }
  const q = route.query.type
  if (typeof q === 'string' && ['receipt', 'issue', 'transfer'].includes(q)) filters.doc_type = q as StockDocType
  if (Object.keys(route.query).length === 0 && await saved.applyDefaultIfAny()) return
  await load()
})

watch(() => route.query.type, (v) => {
  const val = (typeof v === 'string' && ['receipt', 'issue', 'transfer'].includes(v)) ? v as StockDocType : ''
  if (val !== filters.doc_type) { filters.doc_type = val; page.value = 1; load() }
})
</script>

<template>
  <div>
    <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('stock.documents.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5">{{ t('stock.documents.subtitle') }}</p>
      </div>
      <div v-if="auth.canWrite('stock')" class="flex flex-wrap items-center gap-2">
        <RouterLink v-if="auth.canWrite('stock.documents.write')" to="/stock/opening-import" :class="btnOutline('neutral')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3v12m0 0 4-4m-4 4-4-4M5 19h14" /></svg>
          {{ t('stock.opening_import.action') }}
        </RouterLink>
        <RouterLink :to="{ path: '/stock/documents/new', query: { doc_type: 'receipt' } }" :class="btnOutline('primary')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
          {{ t('stock.documents.new_receipt') }}
        </RouterLink>
        <RouterLink :to="{ path: '/stock/documents/new', query: { doc_type: 'issue' } }" :class="btnFilled('primary')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
          {{ t('stock.documents.new_issue') }}
        </RouterLink>
      </div>
    </div>

    <!-- Taby -->
    <div class="flex flex-wrap gap-1 mb-4 border-b border-neutral-200">
      <button v-for="tab in TABS" :key="tab.key" type="button" @click="selectTab(tab.key)"
        class="cursor-pointer px-3 py-2 text-sm font-medium border-b-2 -mb-px"
        :class="filters.doc_type === tab.key ? 'border-primary-600 text-primary-700' : 'border-transparent text-neutral-500 hover:text-neutral-800'">
        {{ t(tab.labelKey) }}
      </button>
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

    <!-- Filtry — sjednoceno se zbytkem aplikace: hledání vpředu, ostatní filtry
         sbalené za „Filtry (N)", aktivní stav nesou chipy. -->
    <FilterBar
      :active-count="activeFilterCount"
      collapsible
      :chips="filterChips"
      @clear="clearFilter"
      @clear-all="resetFilters"
    >
      <template #primary>
        <div class="relative flex-1 min-w-56">
          <svg class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-neutral-400"
            fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M17 11a6 6 0 1 1-12 0 6 6 0 0 1 12 0z" />
          </svg>
          <input v-model="filters.q" type="search" :placeholder="t('stock.documents.filter_q')"
            @keyup.enter="applyFilters" @change="applyFilters"
            class="w-full h-9 pl-9 pr-3 border border-neutral-300 rounded-md text-sm" />
        </div>
      </template>

      <select v-model="filters.warehouse_id" @change="applyFilters" class="h-9 px-3 border border-neutral-300 rounded-md text-sm bg-surface"
        :title="t('stock.documents.filter_warehouse')">
        <option value="">{{ t('stock.documents.filter_warehouse') }}: {{ t('common.all') }}</option>
        <option v-for="w in warehouses" :key="w.id" :value="w.id">{{ w.name }}</option>
      </select>
      <select v-model="filters.status" @change="applyFilters" class="h-9 px-3 border border-neutral-300 rounded-md text-sm bg-surface"
        :title="t('stock.documents.filter_status')">
        <option value="">{{ t('stock.documents.filter_status') }}: {{ t('common.all') }}</option>
        <option value="draft">{{ t('stock.doc_status.draft') }}</option>
        <option value="posted">{{ t('stock.doc_status.posted') }}</option>
        <option value="reversed">{{ t('stock.doc_status.reversed') }}</option>
      </select>

      <template #actions>
        <SavedFiltersMenu :ctrl="saved" />
        <ColumnPicker class="hidden md:block" :ctrl="tbl" />
        <DensityToggle class="hidden md:block" :ctrl="tbl" />
      </template>
    </FilterBar>

    <div v-if="loading" class="text-center text-neutral-500 py-12 text-sm">{{ t('common.loading') }}</div>
    <!-- Zvolená záložka typu dokladu taky zužuje výběr, ale `resetFilters` ji
         nemaže (řídí ji URL) — proto se v tom případě tlačítko nenabízí. -->
    <EmptyState v-else-if="documents.length === 0 && hasActiveFilters" boxed variant="filtered"
      :cta="activeFilterCount > 0 || filters.q ? t('common.empty_state.clear_filters') : undefined"
      @action="resetFilters" />
    <EmptyState v-else-if="documents.length === 0" boxed icon="stock_documents"
      :title="t('stock.documents.empty_title')"
      :message="t('stock.documents.empty_hint')" />

    <div v-else class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
      <div class="overflow-x-auto">
        <table class="w-full text-sm" :class="tbl.densityClass.value">
          <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
            <tr>
              <th v-if="tbl.isVisible('number')" class="px-3 py-2 text-left font-medium">{{ t('stock.documents.col_number') }}</th>
              <th v-if="tbl.isVisible('date')" class="px-3 py-2 text-left font-medium w-28">{{ t('stock.documents.col_date') }}</th>
              <th v-if="tbl.isVisible('type')" class="px-3 py-2 text-left font-medium w-24">{{ t('stock.documents.col_type') }}</th>
              <th v-if="tbl.isVisible('warehouse')" class="px-3 py-2 text-left font-medium">{{ t('stock.documents.col_warehouse') }}</th>
              <th v-if="tbl.isVisible('partner')" class="px-3 py-2 text-left font-medium">{{ t('stock.documents.col_partner') }}</th>
              <th v-if="tbl.isVisible('description')" class="px-3 py-2 text-left font-medium">{{ t('stock.documents.col_description') }}</th>
              <th v-if="tbl.isVisible('origin')" class="px-3 py-2 text-left font-medium w-24">{{ t('stock.documents.col_origin') }}</th>
              <th v-if="tbl.isVisible('status')" class="px-3 py-2 text-center font-medium w-24">{{ t('stock.documents.col_status') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="d in documents" :key="d.id" class="cursor-pointer hover:bg-neutral-50" :class="{ 'opacity-60': d.status === 'reversed' }"
              @click="openDetail(d, $event)" @auxclick.prevent="openDetail(d, $event)">
              <td v-if="tbl.isVisible('number')" class="px-3 py-2 font-mono text-xs whitespace-nowrap">
                <RouterLink class="row-link" :to="`/stock/documents/${d.id}`" @click.stop @auxclick.stop>{{ d.doc_number || t('stock.doc_status.draft') }}</RouterLink>
              </td>
              <td v-if="tbl.isVisible('date')" class="px-3 py-2 whitespace-nowrap">{{ formatDate(d.doc_date) }}</td>
              <td v-if="tbl.isVisible('type')" class="px-3 py-2">
                <span class="text-xs px-2 py-0.5 rounded font-medium" :class="TYPE_BADGE[d.doc_type]">{{ t(`stock.doc_type.${d.doc_type}_short`) }}</span>
              </td>
              <td v-if="tbl.isVisible('warehouse')" class="px-3 py-2 whitespace-nowrap">
                {{ d.warehouse_code }}<span v-if="d.doc_type === 'transfer'"> → {{ d.warehouse_to_code }}</span>
              </td>
              <td v-if="tbl.isVisible('partner')" class="px-3 py-2 truncate max-w-[12rem]">{{ d.partner_name || '—' }}</td>
              <td v-if="tbl.isVisible('description')" class="px-3 py-2 truncate max-w-[20rem]">{{ d.description }}</td>
              <td v-if="tbl.isVisible('origin')" class="px-3 py-2 text-xs text-neutral-500">{{ t(`stock.origin.${d.origin}`) }}</td>
              <td v-if="tbl.isVisible('status')" class="px-3 py-2 text-center">
                <span class="text-xs px-2 py-0.5 rounded font-medium" :class="STATUS_BADGE[d.status]">{{ t(`stock.doc_status.${d.status}`) }}</span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <nav v-if="!loading && total > perPage" class="mt-4 flex items-center justify-between gap-3 text-sm">
      <span class="text-neutral-500">{{ t('common.pagination_range', { from: (page - 1) * perPage + 1, to: Math.min(page * perPage, total), total }) }}</span>
      <div class="flex items-center gap-1">
        <button type="button" :disabled="page <= 1" @click="goToPage(page - 1)"
          class="cursor-pointer h-8 px-3 border border-neutral-300 rounded-md hover:bg-neutral-50 disabled:opacity-40 disabled:cursor-not-allowed">‹</button>
        <span class="px-2 text-neutral-600">{{ page }} / {{ totalPages }}</span>
        <button type="button" :disabled="page >= totalPages" @click="goToPage(page + 1)"
          class="cursor-pointer h-8 px-3 border border-neutral-300 rounded-md hover:bg-neutral-50 disabled:opacity-40 disabled:cursor-not-allowed">›</button>
      </div>
    </nav>
  </div>
</template>

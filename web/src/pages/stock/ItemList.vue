<script setup lang="ts">
import { ref, reactive, computed, onMounted, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'
import { stockApi, type StockItem, type StockItemType, type Warehouse } from '@/api/stock'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { formatMoney } from '@/composables/useFormat'
import { useRowLink } from '@/composables/useRowLink'
import FilterBar, { type FilterChip } from '@/components/ui/FilterBar.vue'
import SavedFiltersMenu from '@/components/ui/SavedFiltersMenu.vue'
import ColumnPicker from '@/components/ui/ColumnPicker.vue'
import DensityToggle from '@/components/ui/DensityToggle.vue'
import SortableTh from '@/components/ui/SortableTh.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { useTablePrefs, type ColumnDef } from '@/composables/useTablePrefs'
import { useSavedFilters, savedFilterTone, type SavedFilterTone } from '@/composables/useSavedFilters'
import type { SavedFilter } from '@/api/preferences'
import { ICONS, btnFilled, btnOutline } from '@/components/ui/buttonStyles'

const { t } = useI18n()
const auth = useAuthStore()
const toast = useToast()
const route = useRoute()
const navigate = useRowLink()

const PER_PAGE = 50

const items = ref<StockItem[]>([])
const loading = ref(false)
const loadingMore = ref(false)
const page = ref(1)
const pages = ref(1)
const total = ref(0)
const warehouses = ref<Warehouse[]>([])
const filters = reactive({
  type: '' as StockItemType | '',
  warehouse_id: '' as number | '',
  only_below_min: false,
  active: true,
  q: '',
})

/**
 * Počet aktivních filtrů pro odznáček na tlačítku „Filtry (N)".
 * `active` se počítá jen když je VYPNUTÝ — zapnutý je výchozí stav (jen aktivní
 * karty), takže by odznáček svítil pořád a přestal by cokoliv znamenat.
 * Hledání se nepočítá, je vždy vidět v lište.
 */
const activeFilterCount = computed(() => {
  let n = 0
  if (filters.type) n++
  if (filters.warehouse_id !== '') n++
  if (filters.only_below_min) n++
  if (!filters.active) n++
  return n
})

// Hledání se do `activeFilterCount` schválně nepočítá (viz výše), pro prázdný
// stav ale rozhoduje stejně — i ono může být důvod, proč seznam nic nevrátil.
const hasActiveFilters = computed(() => activeFilterCount.value > 0 || !!filters.q)

const filterChips = computed<FilterChip[]>(() => {
  const chips: FilterChip[] = []
  if (filters.type) chips.push({ key: 'type', value: t(`stock.item_type.${filters.type}`) })
  if (filters.warehouse_id !== '') {
    const w = warehouses.value.find(x => x.id === filters.warehouse_id)
    if (w) chips.push({ key: 'warehouse', value: w.name })
  }
  if (filters.only_below_min) chips.push({ key: 'below_min', value: t('stock.items.filter_below_min') })
  if (!filters.active) chips.push({ key: 'active', value: t('stock.items.filter_inactive_included') })
  return chips
})

function clearFilter(key: string) {
  switch (key) {
    case 'type': filters.type = ''; break
    case 'warehouse': filters.warehouse_id = ''; break
    case 'below_min': filters.only_below_min = false; break
    case 'active': filters.active = true; break
  }
  applyFilters()
}

async function load(reset = true) {
  if (reset) {
    loading.value = true
    page.value = 1
  } else {
    loadingMore.value = true
    page.value++
  }
  try {
    const res = await stockApi.listItems({
      type: filters.type || undefined,
      active: filters.active || undefined,
      q: filters.q || undefined,
      only_below_min: filters.only_below_min || undefined,
      warehouse_id: filters.warehouse_id || undefined,
      sort: tbl.sort.value?.key as 'sku' | 'name' | 'type' | 'qty' | 'value' | undefined,
      direction: tbl.sort.value?.dir,
      page: page.value,
      per_page: PER_PAGE,
    })
    items.value = reset ? res.data : items.value.concat(res.data)
    total.value = res.meta.total
    pages.value = res.meta.pages ?? 1

  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  } finally {
    loading.value = false
    loadingMore.value = false
  }
}

function applyFilters() { load(true) }
function resetFilters() {
  filters.type = ''
  filters.warehouse_id = ''
  filters.only_below_min = false
  filters.active = true
  filters.q = ''
  applyFilters()
}

function buildQuery(): Record<string, string> {
  const q: Record<string, string> = {}
  if (filters.type) q.type = filters.type
  if (filters.warehouse_id !== '') q.warehouse_id = String(filters.warehouse_id)
  if (filters.only_below_min) q.only_below_min = '1'
  if (!filters.active) q.active = '0'
  if (filters.q) q.q = filters.q
  return q
}
function applyQueryToPage(q: Record<string, string>) {
  filters.type = (q.type as StockItemType) || ''
  filters.warehouse_id = q.warehouse_id ? Number(q.warehouse_id) : ''
  filters.only_below_min = q.only_below_min === '1'
  filters.active = q.active !== '0'
  filters.q = q.q ?? ''
  applyFilters()
}

const COLUMNS: ColumnDef[] = [
  { key: 'sku', labelKey: 'stock.items.col_sku', required: true, sortable: true },
  { key: 'name', labelKey: 'stock.items.col_name', required: true, sortable: true },
  { key: 'type', labelKey: 'stock.items.col_type', sortable: true },
  { key: 'unit', labelKey: 'stock.items.col_unit' },
  { key: 'qty', labelKey: 'stock.items.col_qty', sortable: true },
  { key: 'value', labelKey: 'stock.items.col_value', sortable: true },
  { key: 'avg_cost', labelKey: 'stock.items.col_avg_cost' },
  { key: 'sale_price', labelKey: 'stock.items.col_sale_price', defaultHidden: true },
  { key: 'min_qty', labelKey: 'stock.items.col_min_qty', defaultHidden: true },
  { key: 'active', labelKey: 'stock.items.col_active', defaultHidden: true },
]
const tbl = useTablePrefs('stock-items', COLUMNS)
const saved = useSavedFilters('stock-items', { getQuery: buildQuery, applyQuery: applyQueryToPage })

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

watch(() => tbl.sort.value, () => load())

function qty(i: StockItem): number { return Number(i.qty ?? 0) }
function value(i: StockItem): number { return Number(i.value_total ?? 0) }
function avgCost(i: StockItem): number { return Number(i.avg_unit_cost ?? 0) }
function belowMin(i: StockItem): boolean { return i.min_qty != null && qty(i) < Number(i.min_qty) }

const TYPE_BADGE: Record<StockItemType, string> = {
  material: 'bg-neutral-100 text-neutral-600',
  goods: 'bg-primary-50 text-primary-700',
  product: 'bg-success-50 text-success-600',
}

function openDetail(i: StockItem, e?: MouseEvent) {
  navigate(`/stock/items/${i.id}`, e)
}

onMounted(async () => {
  try { warehouses.value = await stockApi.listWarehouses(true) } catch { warehouses.value = [] }
  if (Object.keys(route.query).length === 0 && await saved.applyDefaultIfAny()) return
  await load()
})
</script>

<template>
  <div>
    <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('stock.items.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5">{{ t('stock.items.subtitle') }}</p>
      </div>
      <RouterLink v-if="auth.canWrite('stock')" to="/stock/items/new" :class="btnFilled('primary')">
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
        {{ t('stock.items.new') }}
      </RouterLink>
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

    <!-- Filtry — stejná lišta jako u faktur a banky: hledání vpředu a nejširší,
         ostatní filtry sbalené za „Filtry (N)", aktivní stav nesou chipy.
         Původní mřížka s popisky nad poli zabírala dva řádky i při nulovém
         filtrování a hledání (nejpoužívanější prvek) bylo až vpravo dole. -->
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
          <input v-model="filters.q" type="search" :placeholder="t('stock.items.filter_q_placeholder')"
            @keyup.enter="applyFilters" @change="applyFilters"
            class="w-full h-9 pl-9 pr-3 border border-neutral-300 rounded-md text-sm" />
        </div>
      </template>

      <select v-model="filters.type" @change="applyFilters" class="h-9 px-3 border border-neutral-300 rounded-md text-sm bg-surface"
        :title="t('stock.items.filter_type')">
        <option value="">{{ t('stock.items.filter_type') }}: {{ t('common.all') }}</option>
        <option value="material">{{ t('stock.item_type.material') }}</option>
        <option value="goods">{{ t('stock.item_type.goods') }}</option>
        <option value="product">{{ t('stock.item_type.product') }}</option>
      </select>
      <select v-model="filters.warehouse_id" @change="applyFilters" class="h-9 px-3 border border-neutral-300 rounded-md text-sm bg-surface"
        :title="t('stock.documents.filter_warehouse')">
        <option value="">{{ t('stock.documents.filter_warehouse') }}: {{ t('common.all') }}</option>
        <option v-for="w in warehouses" :key="w.id" :value="w.id">{{ w.name }}</option>
      </select>
      <label class="inline-flex items-center gap-2 text-sm text-neutral-700 px-2 cursor-pointer">
        <input v-model="filters.only_below_min" type="checkbox" @change="applyFilters" />
        {{ t('stock.items.filter_below_min') }}
      </label>
      <label class="inline-flex items-center gap-2 text-sm text-neutral-700 px-2 cursor-pointer">
        <input v-model="filters.active" type="checkbox" @change="applyFilters" />
        {{ t('stock.items.filter_active') }}
      </label>

      <template #actions>
        <SavedFiltersMenu :ctrl="saved" />
        <ColumnPicker class="hidden md:block" :ctrl="tbl" />
        <DensityToggle class="hidden md:block" :ctrl="tbl" />
      </template>
    </FilterBar>

    <div v-if="loading" class="text-center text-neutral-500 py-12 text-sm">{{ t('common.loading') }}</div>
    <!-- Prázdný seznam po filtrování není prázdný modul — nabízet tu „založ kartu"
         by uživatele svedlo na scestí, správná cesta ven je zrušit filtr. -->
    <EmptyState v-else-if="items.length === 0 && hasActiveFilters" boxed variant="filtered"
      :cta="t('common.empty_state.clear_filters')" @action="resetFilters" />
    <EmptyState v-else-if="items.length === 0" boxed icon="stock_items"
      :title="t('stock.items.empty_title')"
      :message="t('stock.items.empty_hint')"
      :cta="auth.canWrite('stock') ? t('stock.items.new') : undefined"
      to="/stock/items/new" />

    <!-- Desktop -->
    <div v-else class="hidden md:block bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
      <div class="overflow-x-auto">
        <table class="w-full text-sm" :class="tbl.densityClass.value">
          <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
            <tr>
              <SortableTh v-if="tbl.isVisible('sku')" :label="t('stock.items.col_sku')" sort-key="sku" :sort="tbl.sort.value" @toggle="tbl.toggleSort" />
              <SortableTh v-if="tbl.isVisible('name')" :label="t('stock.items.col_name')" sort-key="name" :sort="tbl.sort.value" @toggle="tbl.toggleSort" />
              <SortableTh v-if="tbl.isVisible('type')" :label="t('stock.items.col_type')" sort-key="type" :sort="tbl.sort.value" @toggle="tbl.toggleSort" />
              <th v-if="tbl.isVisible('unit')" class="px-3 py-2 text-left font-medium w-16">{{ t('stock.items.col_unit') }}</th>
              <SortableTh v-if="tbl.isVisible('qty')" :label="t('stock.items.col_qty')" sort-key="qty" :sort="tbl.sort.value" align="right" @toggle="tbl.toggleSort" />
              <SortableTh v-if="tbl.isVisible('value')" :label="t('stock.items.col_value')" sort-key="value" :sort="tbl.sort.value" align="right" @toggle="tbl.toggleSort" />
              <th v-if="tbl.isVisible('avg_cost')" class="px-3 py-2 text-right font-medium w-28">{{ t('stock.items.col_avg_cost') }}</th>
              <th v-if="tbl.isVisible('sale_price')" class="px-3 py-2 text-right font-medium w-28">{{ t('stock.items.col_sale_price') }}</th>
              <th v-if="tbl.isVisible('min_qty')" class="px-3 py-2 text-right font-medium w-24">{{ t('stock.items.col_min_qty') }}</th>
              <th v-if="tbl.isVisible('active')" class="px-3 py-2 text-center font-medium w-20">{{ t('stock.items.col_active') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="i in items" :key="i.id" class="cursor-pointer hover:bg-neutral-50" :class="{ 'opacity-50': !i.is_active }"
              @click="openDetail(i, $event)" @auxclick.prevent="openDetail(i, $event)">
              <td v-if="tbl.isVisible('sku')" class="px-3 py-2 font-mono text-xs whitespace-nowrap">
                <RouterLink class="row-link" :to="`/stock/items/${i.id}`" @click.stop @auxclick.stop>{{ i.sku }}</RouterLink>
              </td>
              <td v-if="tbl.isVisible('name')" class="px-3 py-2">{{ i.name }}</td>
              <td v-if="tbl.isVisible('type')" class="px-3 py-2">
                <span class="text-xs px-2 py-0.5 rounded font-medium" :class="TYPE_BADGE[i.item_type]">{{ t(`stock.item_type.${i.item_type}`) }}</span>
              </td>
              <td v-if="tbl.isVisible('unit')" class="px-3 py-2">{{ i.unit }}</td>
              <td v-if="tbl.isVisible('qty')" class="px-3 py-2 text-right font-mono whitespace-nowrap" :class="belowMin(i) ? 'text-danger-500 font-semibold' : ''">
                {{ qty(i) }}
              </td>
              <td v-if="tbl.isVisible('value')" class="px-3 py-2 text-right font-mono whitespace-nowrap">{{ formatMoney(value(i)) }}</td>
              <td v-if="tbl.isVisible('avg_cost')" class="px-3 py-2 text-right font-mono whitespace-nowrap">{{ formatMoney(avgCost(i)) }}</td>
              <!-- Platná cena = effective_price (akční cena nad cenotvorbou); původní hladina se ukáže přeškrtnutá. -->
              <td v-if="tbl.isVisible('sale_price')" class="px-3 py-2 text-right font-mono whitespace-nowrap">
                <template v-if="i.promo_price != null">
                  <span class="line-through text-neutral-400 mr-1">{{ i.sale_price_without_vat != null ? formatMoney(Number(i.sale_price_without_vat)) : '—' }}</span>
                  <span class="text-success-600 font-semibold" :title="i.promo_label ?? t('eshop.promo.title')">{{ formatMoney(Number(i.promo_price)) }}</span>
                </template>
                <template v-else>{{ i.effective_price != null ? formatMoney(Number(i.effective_price)) : (i.sale_price_without_vat != null ? formatMoney(Number(i.sale_price_without_vat)) : '—') }}</template>
              </td>
              <td v-if="tbl.isVisible('min_qty')" class="px-3 py-2 text-right font-mono whitespace-nowrap">{{ i.min_qty ?? '—' }}</td>
              <td v-if="tbl.isVisible('active')" class="px-3 py-2 text-center">
                <span class="text-xs px-2 py-0.5 rounded font-medium" :class="i.is_active ? 'bg-success-50 text-success-600' : 'bg-neutral-100 text-neutral-500'">
                  {{ i.is_active ? t('common.yes') : t('common.no') }}
                </span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Mobile card list -->
    <div v-if="!loading && items.length > 0" class="md:hidden space-y-2">
      <div v-for="i in items" :key="`m-${i.id}`" @click="openDetail(i, $event)"
        class="cursor-pointer bg-surface border border-neutral-200 rounded-lg shadow-sm p-3" :class="{ 'opacity-50': !i.is_active }">
        <div class="flex items-center justify-between gap-2">
          <RouterLink class="row-link font-mono text-xs text-neutral-500" :to="`/stock/items/${i.id}`" @click.stop @auxclick.stop>{{ i.sku }}</RouterLink>
          <span class="text-xs px-2 py-0.5 rounded font-medium" :class="TYPE_BADGE[i.item_type]">{{ t(`stock.item_type.${i.item_type}`) }}</span>
        </div>
        <div class="font-medium mt-0.5">{{ i.name }}</div>
        <div class="flex items-center justify-between mt-1.5 text-sm">
          <span :class="belowMin(i) ? 'text-danger-500 font-semibold' : 'text-neutral-600'">{{ qty(i) }} {{ i.unit }}</span>
          <span class="font-mono text-neutral-700">{{ formatMoney(value(i)) }}</span>
        </div>
      </div>
    </div>

    <div v-if="!loading && items.length > 0 && page < pages" class="text-center mt-3">
      <button type="button" @click="load(false)" :disabled="loadingMore" :class="btnOutline('neutral')">
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 14l-7 7m0 0l-7-7m7 7V3"/></svg>
        {{ loadingMore ? t('common.loading_more') : t('common.load_more') }}
      </button>
    </div>
  </div>
</template>

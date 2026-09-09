<script setup lang="ts">
import { ref, reactive, computed, onMounted, watch, onBeforeUnmount } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'
import { stockApi, type StockItem, type StockItemType, type Warehouse, type StockItemAttributeFilter, type StockItemListFilters, type StockItemNeighborsOptions } from '@/api/stock'
import { eshopApi, type Manufacturer, type Category, type Tag, type Attribute, type AttributeOption } from '@/api/eshop'
import { clientsApi, type Client } from '@/api/clients'
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
import CatalogBulkDialog from '@/components/stock/CatalogBulkDialog.vue'
import CatalogExportDialog from '@/components/stock/CatalogExportDialog.vue'
import PriceMatrixDialog from '@/components/stock/PriceMatrixDialog.vue'
import ItemQuickDetailDrawer from '@/components/stock/ItemQuickDetailDrawer.vue'
import type { CatalogBulkFilters, CatalogBulkSelection } from '@/api/catalogBulk'

const { t } = useI18n()
const bulkT = (key: string, params?: Record<string, unknown>) => t(`stock.items.bulk.${key}`, params ?? {})
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
const manufacturers = ref<Manufacturer[]>([])
const categories = ref<Category[]>([])
const tags = ref<Tag[]>([])
const attributes = ref<Attribute[]>([])
const attributeOptions = ref<Record<number, AttributeOption[]>>({})
const vendors = ref<Client[]>([])
const selectedIds = ref(new Set<number>())
const excludedIds = ref(new Set<number>())
const allMatching = ref(false)
const bulkDialogOpen = ref(false)
const exportDialogOpen = ref(false)
const priceMatrixDialogOpen = ref(false)
const quickDetailId = ref<number | null>(null)
const quickDetailInitialItem = ref<StockItem | null>(null)
const quickDetailNeighbors = ref<StockItemNeighborsOptions>({})
const filters = reactive({
  type: '' as StockItemType | '',
  warehouse_id: '' as number | '',
  manufacturer_id: '' as number | '',
  category_id: '' as number | '',
  vendor_id: '' as number | '',
  tag_ids: [] as number[],
  missing: '' as '' | 'manufacturer' | 'category' | 'image' | 'price' | 'ean',
  availability: '' as '' | 'in_stock' | 'out_of_stock' | 'below_min',
  qty_min: '',
  qty_max: '',
  attribute_id: '' as number | '',
  attribute_value: '',
  only_below_min: false,
  active: true,
  q: '',
})

let searchTimer: ReturnType<typeof setTimeout> | undefined
let requestController: AbortController | undefined
let requestVersion = 0
let attributeOptionVersion = 0

const selectedAttribute = computed(() => attributes.value.find(a => a.id === filters.attribute_id))
const selectedAttributeOptions = computed(() => filters.attribute_id === '' ? [] : (attributeOptions.value[filters.attribute_id] ?? []))
function categoryLabel(category: Category): string {
  return `${'\u00a0\u00a0'.repeat(category.depth)}${category.name}`
}
const attributeFilters = computed<StockItemAttributeFilter[]>(() => {
  const attribute = selectedAttribute.value
  if (!attribute || filters.attribute_value === '') return []
  switch (attribute.data_type) {
    case 'bool': return [{ attribute_id: attribute.id, value_bool: filters.attribute_value === 'true' }]
    case 'number': return [{ attribute_id: attribute.id, value_num_min: filters.attribute_value }]
    case 'enum': return [{ attribute_id: attribute.id, option_id: Number(filters.attribute_value) }]
    default: return [{ attribute_id: attribute.id, value_text: filters.attribute_value }]
  }
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
  if (filters.manufacturer_id !== '') n++
  if (filters.category_id !== '') n++
  if (filters.vendor_id !== '') n++
  if (filters.tag_ids.length) n++
  if (filters.missing) n++
  if (filters.availability) n++
  if (filters.qty_min !== '' || filters.qty_max !== '') n++
  if (attributeFilters.value.length) n++
  if (filters.only_below_min) n++
  if (!filters.active) n++
  return n
})

// Hledání se do `activeFilterCount` schválně nepočítá (viz výše), pro prázdný
// stav ale rozhoduje stejně — i ono může být důvod, proč seznam nic nevrátil.
const hasActiveFilters = computed(() => activeFilterCount.value > 0 || !!filters.q)
const selectedCount = computed(() => allMatching.value ? Math.max(0, total.value - excludedIds.value.size) : selectedIds.value.size)
const hasSelection = computed(() => selectedCount.value > 0)
const allCurrentPageSelected = computed(() => items.value.length > 0 && items.value.every(item => isSelected(item.id)))

const bulkFilters = computed<CatalogBulkFilters>(() => ({
  type: filters.type || undefined,
  active: filters.active || undefined,
  q: filters.q || undefined,
  only_below_min: filters.only_below_min || undefined,
  warehouse_id: filters.warehouse_id || undefined,
  manufacturer_id: filters.manufacturer_id || undefined,
  category_id: filters.category_id || undefined,
  vendor_id: filters.vendor_id || undefined,
  tag_ids: filters.tag_ids.length ? filters.tag_ids : undefined,
  missing: filters.missing ? [filters.missing] : undefined,
  availability: filters.availability || undefined,
  qty_min: filters.qty_min || undefined,
  qty_max: filters.qty_max || undefined,
  attribute_filters: attributeFilters.value.length ? attributeFilters.value : undefined,
}))
const bulkSelection = computed<CatalogBulkSelection>(() => allMatching.value
  ? { all_matching: true, filters: bulkFilters.value, excluded_ids: [...excludedIds.value] }
  : { all_matching: false, ids: [...selectedIds.value] })

function currentListFilters(): StockItemListFilters {
  return {
    type: filters.type || undefined,
    active: filters.active,
    q: filters.q || undefined,
    only_below_min: filters.only_below_min || undefined,
    warehouse_id: filters.warehouse_id || undefined,
    manufacturer_id: filters.manufacturer_id || undefined,
    category_id: filters.category_id || undefined,
    vendor_id: filters.vendor_id || undefined,
    tag_ids: [...filters.tag_ids],
    missing: filters.missing ? [filters.missing] : undefined,
    availability: filters.availability || undefined,
    qty_min: filters.qty_min || undefined,
    qty_max: filters.qty_max || undefined,
    attribute_filters: [...attributeFilters.value],
    sort: tbl.sort.value?.key as StockItemListFilters['sort'],
    direction: tbl.sort.value?.dir,
  }
}

function clearSelection() {
  selectedIds.value = new Set<number>()
  excludedIds.value = new Set<number>()
  allMatching.value = false
}
function isSelected(id: number) { return allMatching.value ? !excludedIds.value.has(id) : selectedIds.value.has(id) }
function toggleItem(id: number) {
  if (allMatching.value) {
    const next = new Set(excludedIds.value)
    next.has(id) ? next.delete(id) : next.add(id)
    excludedIds.value = next
  } else {
    const next = new Set(selectedIds.value)
    next.has(id) ? next.delete(id) : next.add(id)
    selectedIds.value = next
  }
}
function selectPage() {
  if (allMatching.value) {
    const next = new Set(excludedIds.value)
    items.value.forEach(item => next.delete(item.id))
    excludedIds.value = next
  } else selectedIds.value = new Set([...selectedIds.value, ...items.value.map(item => item.id)])
}
function togglePage() {
  if (allCurrentPageSelected.value) {
    if (allMatching.value) excludedIds.value = new Set([...excludedIds.value, ...items.value.map(item => item.id)])
    else selectedIds.value = new Set([...selectedIds.value].filter(id => !items.value.some(item => item.id === id)))
  } else selectPage()
}
function selectAllMatching() {
  allMatching.value = true
  selectedIds.value = new Set<number>()
  excludedIds.value = new Set<number>()
}

const filterChips = computed<FilterChip[]>(() => {
  const chips: FilterChip[] = []
  if (filters.type) chips.push({ key: 'type', value: t(`stock.item_type.${filters.type}`) })
  if (filters.warehouse_id !== '') {
    const w = warehouses.value.find(x => x.id === filters.warehouse_id)
    if (w) chips.push({ key: 'warehouse', value: w.name })
  }
  if (filters.manufacturer_id !== '') {
    const manufacturer = manufacturers.value.find(x => x.id === filters.manufacturer_id)
    if (manufacturer) chips.push({ key: 'manufacturer', label: t('stock.items.filter_manufacturer'), value: manufacturer.name })
  }
  if (filters.category_id !== '') {
    const category = categories.value.find(x => x.id === filters.category_id)
    if (category) chips.push({ key: 'category', label: t('stock.items.filter_category'), value: category.name })
  }
  if (filters.vendor_id !== '') {
    const vendor = vendors.value.find(x => x.id === filters.vendor_id)
    if (vendor) chips.push({ key: 'vendor', label: t('stock.items.filter_vendor'), value: vendor.company_name })
  }
  if (filters.tag_ids.length) chips.push({ key: 'tags', label: t('stock.items.filter_tags'), value: filters.tag_ids.map(id => tags.value.find(x => x.id === id)?.name).filter(Boolean).join(', ') })
  if (filters.missing) chips.push({ key: 'missing', label: t('stock.items.filter_missing'), value: t(`stock.items.missing_${filters.missing}`) })
  if (filters.availability) chips.push({ key: 'availability', label: t('stock.items.filter_availability'), value: t(`stock.items.availability_${filters.availability}`) })
  if (filters.qty_min !== '' || filters.qty_max !== '') chips.push({ key: 'quantity', label: t('stock.items.filter_quantity'), value: `${filters.qty_min || '−'} – ${filters.qty_max || '∞'}` })
  if (attributeFilters.value.length) chips.push({ key: 'attribute', label: t('stock.items.filter_attribute'), value: `${selectedAttribute.value?.name}: ${filters.attribute_value}` })
  if (filters.only_below_min) chips.push({ key: 'below_min', value: t('stock.items.filter_below_min') })
  if (!filters.active) chips.push({ key: 'active', value: t('stock.items.filter_inactive_included') })
  return chips
})

function clearFilter(key: string) {
  switch (key) {
    case 'type': filters.type = ''; break
    case 'warehouse': filters.warehouse_id = ''; break
    case 'manufacturer': filters.manufacturer_id = ''; break
    case 'category': filters.category_id = ''; break
    case 'vendor': filters.vendor_id = ''; break
    case 'tags': filters.tag_ids = []; break
    case 'missing': filters.missing = ''; break
    case 'availability': filters.availability = ''; break
    case 'quantity': filters.qty_min = ''; filters.qty_max = ''; break
    case 'attribute': filters.attribute_id = ''; filters.attribute_value = ''; break
    case 'below_min': filters.only_below_min = false; break
    case 'active': filters.active = true; break
  }
  applyFilters()
}

async function load(reset = true) {
  const version = ++requestVersion
  requestController?.abort()
  requestController = new AbortController()
  const targetPage = reset ? 1 : page.value + 1
  if (reset) {
    loading.value = true
  } else {
    loadingMore.value = true
  }
  try {
    const res = await stockApi.listItems({ ...currentListFilters(), page: targetPage, per_page: PER_PAGE }, { signal: requestController.signal })
    if (version !== requestVersion) return
    page.value = targetPage
    items.value = reset ? res.data : items.value.concat(res.data)
    total.value = res.meta.total
    pages.value = res.meta.pages ?? 1

  } catch (e: any) {
    if (e?.code === 'ERR_CANCELED' || version !== requestVersion) return
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  } finally {
    if (version === requestVersion) {
      loading.value = false
      loadingMore.value = false
    }
  }
}

function applyFilters() {
  if (searchTimer) clearTimeout(searchTimer)
  searchTimer = undefined
  clearSelection()
  void load(true)
}
function scheduleSearch() {
  if (searchTimer) clearTimeout(searchTimer)
  searchTimer = setTimeout(() => applyFilters(), 300)
}
async function ensureAttributeOptions(attributeId: number) {
  if (attributeOptions.value[attributeId]) return
  const version = ++attributeOptionVersion
  const options = await eshopApi.listAttributeOptions(attributeId).catch(() => [] as AttributeOption[])
  if (version === attributeOptionVersion) attributeOptions.value[attributeId] = options
}
function onAttributeChange() {
  filters.attribute_value = ''
  if (selectedAttribute.value?.data_type === 'enum') void ensureAttributeOptions(selectedAttribute.value.id)
  applyFilters()
}
function resetFilters() {
  filters.type = ''
  filters.warehouse_id = ''
  filters.manufacturer_id = ''
  filters.category_id = ''
  filters.vendor_id = ''
  filters.tag_ids = []
  filters.missing = ''
  filters.availability = ''
  filters.qty_min = ''
  filters.qty_max = ''
  filters.attribute_id = ''
  filters.attribute_value = ''
  filters.only_below_min = false
  filters.active = true
  filters.q = ''
  applyFilters()
}

function buildQuery(): Record<string, string> {
  const q: Record<string, string> = {}
  if (filters.type) q.type = filters.type
  if (filters.warehouse_id !== '') q.warehouse_id = String(filters.warehouse_id)
  if (filters.manufacturer_id !== '') q.manufacturer_id = String(filters.manufacturer_id)
  if (filters.category_id !== '') q.category_id = String(filters.category_id)
  if (filters.vendor_id !== '') q.vendor_id = String(filters.vendor_id)
  if (filters.tag_ids.length) q.tag_ids = filters.tag_ids.join(',')
  if (filters.missing) q.missing = filters.missing
  if (filters.availability) q.availability = filters.availability
  if (filters.qty_min !== '') q.qty_min = filters.qty_min
  if (filters.qty_max !== '') q.qty_max = filters.qty_max
  if (filters.attribute_id !== '' && filters.attribute_value !== '') q.attribute = `${filters.attribute_id}:${filters.attribute_value}`
  if (filters.only_below_min) q.only_below_min = '1'
  if (!filters.active) q.active = '0'
  if (filters.q) q.q = filters.q
  return q
}
function applyQueryToPage(q: Record<string, string>) {
  filters.type = (q.type as StockItemType) || ''
  filters.warehouse_id = q.warehouse_id ? Number(q.warehouse_id) : ''
  filters.manufacturer_id = q.manufacturer_id ? Number(q.manufacturer_id) : ''
  filters.category_id = q.category_id ? Number(q.category_id) : ''
  filters.vendor_id = q.vendor_id ? Number(q.vendor_id) : ''
  filters.tag_ids = q.tag_ids ? q.tag_ids.split(',').map(Number).filter(Number.isInteger) : []
  filters.missing = (q.missing as typeof filters.missing) || ''
  filters.availability = (q.availability as typeof filters.availability) || ''
  filters.qty_min = q.qty_min ?? ''
  filters.qty_max = q.qty_max ?? ''
  const attribute = q.attribute?.match(/^(\d+):(.*)$/)
  filters.attribute_id = attribute ? Number(attribute[1]) : ''
  filters.attribute_value = attribute ? attribute[2] : ''
  if (filters.attribute_id !== '' && selectedAttribute.value?.data_type === 'enum') void ensureAttributeOptions(filters.attribute_id)
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

watch(() => tbl.sort.value, () => void load())
watch(() => filters.q, scheduleSearch)

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

function openQuickDetail(item: StockItem) {
  const neighbors: StockItemNeighborsOptions = { ...currentListFilters() }
  if (allMatching.value && !excludedIds.value.has(item.id)) {
    neighbors.excluded_ids = [...excludedIds.value]
  } else if (selectedIds.value.has(item.id)) {
    neighbors.ids = [...selectedIds.value]
  }
  quickDetailNeighbors.value = neighbors
  quickDetailId.value = item.id
  quickDetailInitialItem.value = item
}

function navigateQuickDetail(id: number) {
  const listItem = items.value.find(item => item.id === id)
  quickDetailId.value = id
  quickDetailInitialItem.value = listItem ?? null
}

onMounted(async () => {
  const [wh, mf, cat, tg, attr] = await Promise.all([
    stockApi.listWarehouses(true).catch(() => [] as Warehouse[]),
    eshopApi.listManufacturers().catch(() => [] as Manufacturer[]),
    eshopApi.listCategories().catch(() => [] as Category[]),
    eshopApi.listTags().catch(() => [] as Tag[]),
    eshopApi.listAttributes().catch(() => [] as Attribute[]),
  ])
  warehouses.value = wh
  manufacturers.value = mf.filter(x => !x.archived)
  categories.value = cat.filter(x => !x.archived)
  tags.value = tg.filter(x => !x.archived)
  attributes.value = attr.filter(x => !x.archived && x.is_filterable)
  const loadedVendors: Client[] = []
  for (let vendorPage = 1; ; vendorPage++) {
    const result = await clientsApi.list({ role: 'vendors', per_page: 500, page: vendorPage, sort: 'name' }).catch(() => null)
    if (!result) break
    loadedVendors.push(...result.data)
    if (vendorPage >= result.meta.pages) break
  }
  vendors.value = loadedVendors
  if (Object.keys(route.query).length === 0 && await saved.applyDefaultIfAny()) return
  await load()
})

onBeforeUnmount(() => {
  if (searchTimer) clearTimeout(searchTimer)
  requestController?.abort()
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
            @keyup.enter="applyFilters"
            class="w-full h-9 pl-9 pr-3 border border-neutral-300 rounded-md text-sm" />
        </div>
        <select v-model="filters.manufacturer_id" @change="applyFilters" class="h-9 min-w-32 max-w-44 px-3 border border-neutral-300 rounded-md text-sm bg-surface"
          :title="t('stock.items.filter_manufacturer')">
          <option value="">{{ t('stock.items.filter_manufacturer') }}: {{ t('common.all') }}</option>
          <option v-for="manufacturer in manufacturers" :key="manufacturer.id" :value="manufacturer.id">{{ manufacturer.name }}</option>
        </select>
        <select v-model="filters.vendor_id" @change="applyFilters" class="h-9 min-w-32 max-w-44 px-3 border border-neutral-300 rounded-md text-sm bg-surface"
          :title="t('stock.items.filter_vendor')">
          <option value="">{{ t('stock.items.filter_vendor') }}: {{ t('common.all') }}</option>
          <option v-for="vendor in vendors" :key="vendor.id" :value="vendor.id">{{ vendor.company_name }}</option>
        </select>
        <select v-model="filters.category_id" @change="applyFilters" class="h-9 min-w-36 max-w-52 px-3 border border-neutral-300 rounded-md text-sm bg-surface"
          :title="t('stock.items.filter_category')">
          <option value="">{{ t('stock.items.filter_category') }}: {{ t('common.all') }}</option>
          <option v-for="category in categories" :key="category.id" :value="category.id">{{ categoryLabel(category) }}</option>
        </select>
        <select v-model="filters.availability" @change="applyFilters" class="h-9 min-w-32 max-w-44 px-3 border border-neutral-300 rounded-md text-sm bg-surface"
          :title="t('stock.items.filter_availability')">
          <option value="">{{ t('stock.items.filter_availability') }}: {{ t('common.all') }}</option>
          <option value="in_stock">{{ t('stock.items.availability_in_stock') }}</option>
          <option value="out_of_stock">{{ t('stock.items.availability_out_of_stock') }}</option>
          <option value="below_min">{{ t('stock.items.availability_below_min') }}</option>
        </select>
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

      <div class="basis-full border-t border-neutral-100 pt-3 mt-1 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-2 items-center">
        <fieldset class="min-w-0">
          <legend class="sr-only">{{ t('stock.items.filter_tags') }}</legend>
          <select v-model="filters.tag_ids" multiple size="1" @change="applyFilters" class="w-full h-9 px-3 border border-neutral-300 rounded-md text-sm bg-surface"
            :title="t('stock.items.filter_tags')">
            <option v-for="tag in tags" :key="tag.id" :value="tag.id">{{ tag.name }}</option>
          </select>
        </fieldset>
        <select v-model="filters.missing" @change="applyFilters" class="w-full h-9 px-3 border border-neutral-300 rounded-md text-sm bg-surface"
          :title="t('stock.items.filter_missing')">
          <option value="">{{ t('stock.items.filter_missing') }}: {{ t('common.all') }}</option>
          <option value="manufacturer">{{ t('stock.items.missing_manufacturer') }}</option>
          <option value="category">{{ t('stock.items.missing_category') }}</option>
          <option value="image">{{ t('stock.items.missing_image') }}</option>
          <option value="price">{{ t('stock.items.missing_price') }}</option>
          <option value="ean">{{ t('stock.items.missing_ean') }}</option>
        </select>
        <div class="grid grid-cols-2 gap-2">
          <input v-model="filters.qty_min" type="number" inputmode="decimal" :placeholder="t('stock.items.filter_qty_min')" @change="applyFilters"
            class="min-w-0 h-9 px-3 border border-neutral-300 rounded-md text-sm" />
          <input v-model="filters.qty_max" type="number" inputmode="decimal" :placeholder="t('stock.items.filter_qty_max')" @change="applyFilters"
            class="min-w-0 h-9 px-3 border border-neutral-300 rounded-md text-sm" />
        </div>
        <div class="grid grid-cols-2 gap-2">
          <select v-model="filters.attribute_id" @change="onAttributeChange" class="min-w-0 h-9 px-3 border border-neutral-300 rounded-md text-sm bg-surface"
            :title="t('stock.items.filter_attribute')">
            <option value="">{{ t('stock.items.filter_attribute') }}</option>
            <option v-for="attribute in attributes" :key="attribute.id" :value="attribute.id">{{ attribute.name }}</option>
          </select>
          <select v-if="selectedAttribute?.data_type === 'enum'" v-model="filters.attribute_value" @change="applyFilters" class="min-w-0 h-9 px-3 border border-neutral-300 rounded-md text-sm bg-surface">
            <option value="">{{ t('stock.items.filter_attribute_value') }}</option>
            <option v-for="option in selectedAttributeOptions" :key="option.id" :value="option.id">{{ option.label }}</option>
          </select>
          <select v-else-if="selectedAttribute?.data_type === 'bool'" v-model="filters.attribute_value" @change="applyFilters" class="min-w-0 h-9 px-3 border border-neutral-300 rounded-md text-sm bg-surface">
            <option value="">{{ t('stock.items.filter_attribute_value') }}</option>
            <option value="true">{{ t('common.yes') }}</option>
            <option value="false">{{ t('common.no') }}</option>
          </select>
          <input v-else v-model="filters.attribute_value" :disabled="!selectedAttribute" :type="selectedAttribute?.data_type === 'number' ? 'number' : 'search'"
            inputmode="decimal" :placeholder="t('stock.items.filter_attribute_value')" @change="applyFilters" class="min-w-0 h-9 px-3 border border-neutral-300 rounded-md text-sm disabled:bg-neutral-50" />
        </div>
      </div>

      <template #actions>
        <RouterLink v-if="auth.canWrite('eshop.write') && auth.canWrite('stock.items.write')" to="/eshop?tab=import" :class="btnOutline('primary')" class="whitespace-nowrap">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.upload" /></svg>
          {{ t('stock.items.quick_detail.import') }}
        </RouterLink>
        <SavedFiltersMenu :ctrl="saved" />
        <ColumnPicker class="hidden md:block" :ctrl="tbl" />
        <DensityToggle class="hidden md:block" :ctrl="tbl" />
      </template>
    </FilterBar>

    <div v-if="items.length && auth.canRead('eshop')" class="mt-3 flex flex-wrap items-center gap-2 rounded-lg border border-primary-200 bg-primary-50/60 px-3 py-2 text-sm">
      <span class="font-medium text-primary-800">{{ bulkT('selected_count', { count: selectedCount }) }}</span>
      <button type="button" :class="btnOutline('primary')" @click="selectPage"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="m5 12 4 4L19 6" /></svg>{{ bulkT('select_page') }}</button>
      <button v-if="total > items.length && !allMatching" type="button" :class="btnOutline('primary')" @click="selectAllMatching"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M3 12s3-6 9-6 9 6 9 6-3 6-9 6" /></svg>{{ bulkT('select_all_results', { count: total }) }}</button>
      <span v-if="allMatching" class="text-primary-700">{{ bulkT('all_results_selected', { count: selectedCount }) }}</span>
      <button v-if="hasSelection" type="button" :class="btnOutline('neutral')" @click="clearSelection"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M6 6l12 12M18 6 6 18" /></svg>{{ bulkT('clear_selection') }}</button>
      <button v-if="auth.canWrite('eshop.write') && auth.canWrite('stock.items.write')" type="button" :disabled="!hasSelection" :class="btnFilled('primary')" @click="bulkDialogOpen = true"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M12 5v14m-7-7h14" /></svg>{{ bulkT('action') }}</button>
      <button v-if="auth.canWrite('eshop.write') && auth.canWrite('stock.items.write')" data-test="open-price-matrix" type="button" :disabled="!hasSelection" :class="btnOutline('accent')" @click="priceMatrixDialogOpen = true"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.table" /></svg>{{ t('eshop.price_matrix.open_for_selection') }}</button>
      <button type="button" :disabled="!hasSelection" :class="btnOutline('neutral')" @click="exportDialogOpen = true"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path :d="ICONS.download" /></svg>{{ t('stock.items.export.title') }}</button>
    </div>

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
              <th class="w-10 px-3 py-2"><input type="checkbox" :checked="allCurrentPageSelected" :aria-label="bulkT('select_page')" @click.stop @change="togglePage" /></th>
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
              <td class="px-3 py-2" @click.stop><input type="checkbox" :checked="isSelected(i.id)" :aria-label="bulkT('select_item', { sku: i.sku })" @change="toggleItem(i.id)" /></td>
              <td v-if="tbl.isVisible('sku')" class="px-3 py-2 font-mono text-xs whitespace-nowrap">
                <div class="flex items-center gap-1">
                  <RouterLink class="row-link" :to="`/stock/items/${i.id}`" @click.stop @auxclick.stop>{{ i.sku }}</RouterLink>
                  <button type="button" :title="t('stock.items.quick_detail.open')" :aria-label="t('stock.items.quick_detail.open')" class="cursor-pointer rounded p-1 text-neutral-400 hover:bg-neutral-100 hover:text-primary-700" @click.stop="openQuickDetail(i)">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.eye" /></svg>
                  </button>
                </div>
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
          <div class="flex min-w-0 items-center gap-2"><input type="checkbox" :checked="isSelected(i.id)" :aria-label="bulkT('select_item', { sku: i.sku })" @click.stop @change="toggleItem(i.id)" /><RouterLink class="row-link font-mono text-xs text-neutral-500" :to="`/stock/items/${i.id}`" @click.stop @auxclick.stop>{{ i.sku }}</RouterLink></div>
          <button type="button" :title="t('stock.items.quick_detail.open')" :aria-label="t('stock.items.quick_detail.open')" class="cursor-pointer rounded p-1 text-neutral-400 hover:bg-neutral-100 hover:text-primary-700" @click.stop="openQuickDetail(i)">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.eye" /></svg>
          </button>
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
    <CatalogBulkDialog v-if="bulkDialogOpen" :selection="bulkSelection" :selected-count="selectedCount" :manufacturers="manufacturers" :categories="categories" :tags="tags" @close="bulkDialogOpen = false" @completed="void load(true)" @open-history="navigate('/eshop/jobs')" />
    <CatalogExportDialog v-if="exportDialogOpen" :selection="bulkSelection" :selected-count="selectedCount" :warehouse-options="warehouses" :can-manage-jobs="auth.canWrite('eshop.write')" @close="exportDialogOpen = false" />
    <PriceMatrixDialog v-if="priceMatrixDialogOpen" :selection="bulkSelection" :selected-count="selectedCount" @close="priceMatrixDialogOpen = false" />
    <ItemQuickDetailDrawer v-if="quickDetailId != null" :item-id="quickDetailId" :initial-item="quickDetailInitialItem" :manufacturers="manufacturers" :neighbors="quickDetailNeighbors" :can-edit="auth.canWrite('stock.items.write') && auth.canWrite('eshop.write')" @close="quickDetailId = null" @navigate="navigateQuickDetail" @open="navigate(`/stock/items/${$event}`)" @edit="navigate(`/stock/items/${$event}/edit`)" />
  </div>
</template>

<script setup lang="ts">
import { ref, reactive, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'
import {
  purchaseOrdersApi, type PurchaseOrder, type PurchaseOrderState,
} from '@/api/purchaseOrders'
import { stockApi, type Warehouse } from '@/api/stock'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { formatDate, formatMoney } from '@/composables/useFormat'
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
import DateInput from '@/components/ui/DateInput.vue'

const { t } = useI18n()
const auth = useAuthStore()
const toast = useToast()
const route = useRoute()
const navigate = useRowLink()

const orders = ref<PurchaseOrder[]>([])
const warehouses = ref<Warehouse[]>([])
const loading = ref(false)

const limit = ref(50)
const offset = ref(0)
const total = ref(0)
const page = computed(() => Math.floor(offset.value / limit.value) + 1)
const totalPages = computed(() => Math.max(1, Math.ceil(total.value / limit.value)))

const STATES: PurchaseOrderState[] = ['draft', 'sent', 'confirmed', 'partially_received', 'received', 'closed', 'cancelled']

const filters = reactive({
  state: '' as '' | PurchaseOrderState | 'open',
  warehouse_id: '' as number | '',
  q: '',
  from: '',
  to: '',
  expected_to: '',
})

const activeFilterCount = computed(() => {
  let n = 0
  if (filters.state) n++
  if (filters.warehouse_id !== '') n++
  if (filters.from) n++
  if (filters.to) n++
  if (filters.expected_to) n++
  return n
})
const hasActiveFilters = computed(() => activeFilterCount.value > 0 || !!filters.q)

const filterChips = computed<FilterChip[]>(() => {
  const chips: FilterChip[] = []
  if (filters.state === 'open') chips.push({ key: 'state', value: t('stock.orders.filter_state_open') })
  else if (filters.state) chips.push({ key: 'state', value: t(`stock.order_state.${filters.state}`) })
  if (filters.warehouse_id !== '') {
    const w = warehouses.value.find(x => x.id === filters.warehouse_id)
    if (w) chips.push({ key: 'warehouse', value: w.name })
  }
  if (filters.from) chips.push({ key: 'from', value: formatDate(filters.from) })
  if (filters.to) chips.push({ key: 'to', value: formatDate(filters.to) })
  if (filters.expected_to) chips.push({ key: 'expected_to', value: formatDate(filters.expected_to) })
  return chips
})

function clearFilter(key: string) {
  if (key === 'state') filters.state = ''
  if (key === 'warehouse') filters.warehouse_id = ''
  if (key === 'from') filters.from = ''
  if (key === 'to') filters.to = ''
  if (key === 'expected_to') filters.expected_to = ''
  applyFilters()
}

async function load() {
  loading.value = true
  try {
    const r = await purchaseOrdersApi.list({
      state: filters.state && filters.state !== 'open' ? filters.state : undefined,
      open: filters.state === 'open' ? true : undefined,
      warehouse_id: filters.warehouse_id || undefined,
      q: filters.q || undefined,
      from: filters.from || undefined,
      to: filters.to || undefined,
      expected_to: filters.expected_to || undefined,
      limit: limit.value,
      offset: offset.value,
    })
    orders.value = r.items
    total.value = r.total
    limit.value = r.limit
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  } finally {
    loading.value = false
  }
}

function applyFilters() { offset.value = 0; load() }
function resetFilters() {
  filters.state = ''
  filters.warehouse_id = ''
  filters.q = ''
  filters.from = ''
  filters.to = ''
  filters.expected_to = ''
  applyFilters()
}
function goToPage(p: number) {
  const np = Math.min(Math.max(1, p), totalPages.value)
  const newOffset = (np - 1) * limit.value
  if (newOffset !== offset.value) { offset.value = newOffset; load() }
}

function buildQuery(): Record<string, string> {
  const q: Record<string, string> = {}
  if (filters.state) q.state = filters.state
  if (filters.warehouse_id !== '') q.warehouse_id = String(filters.warehouse_id)
  if (filters.q) q.q = filters.q
  if (filters.from) q.from = filters.from
  if (filters.to) q.to = filters.to
  if (filters.expected_to) q.expected_to = filters.expected_to
  return q
}
function applyQueryToPage(q: Record<string, string>) {
  filters.state = (q.state as PurchaseOrderState | 'open') || ''
  filters.warehouse_id = q.warehouse_id ? Number(q.warehouse_id) : ''
  filters.q = q.q ?? ''
  filters.from = q.from ?? ''
  filters.to = q.to ?? ''
  filters.expected_to = q.expected_to ?? ''
  applyFilters()
}

const COLUMNS: ColumnDef[] = [
  { key: 'number', labelKey: 'stock.orders.col_number', required: true },
  { key: 'date', labelKey: 'stock.orders.col_date', required: true },
  { key: 'vendor', labelKey: 'stock.orders.col_vendor' },
  { key: 'warehouse', labelKey: 'stock.orders.col_warehouse' },
  { key: 'expected', labelKey: 'stock.orders.col_expected' },
  { key: 'qty_ordered', labelKey: 'stock.orders.col_qty_ordered', defaultHidden: true },
  { key: 'qty_received', labelKey: 'stock.orders.col_qty_received' },
  { key: 'qty_remaining', labelKey: 'stock.orders.col_qty_remaining' },
  { key: 'total', labelKey: 'stock.orders.col_total' },
  { key: 'state', labelKey: 'stock.orders.col_state', required: true },
]
const tbl = useTablePrefs('stock-purchase-orders', COLUMNS)
const saved = useSavedFilters('stock-purchase-orders', { getQuery: buildQuery, applyQuery: applyQueryToPage })

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

const STATE_BADGE: Record<PurchaseOrderState, string> = {
  draft:               'bg-neutral-100 text-neutral-600',
  sent:                'bg-primary-50 text-primary-700',
  confirmed:           'bg-accent-50 text-accent-700',
  partially_received:  'bg-warning-50 text-warning-600',
  received:            'bg-success-50 text-success-600',
  closed:              'bg-neutral-100 text-neutral-500',
  cancelled:           'bg-danger-50 text-danger-500',
}

function openDetail(o: PurchaseOrder, e?: MouseEvent) {
  navigate(`/stock/purchase-orders/${o.id}`, e)
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
        <h1 class="text-2xl font-semibold">{{ t('stock.orders.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5">{{ t('stock.orders.subtitle') }}</p>
      </div>
      <div class="flex flex-wrap items-center gap-2">
        <!-- Dřív mířilo na /stock/items?only_below_min=1 — prostý filtr `skladem < minimum`,
             který ignoruje rezervace, zboží na cestě, balení i minimum odběru, takže ukazoval
             jiná čísla než `GET /api/stock/replenishment`. -->
        <RouterLink to="/stock/replenishment" :class="btnOutline('neutral')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.stock_items" /></svg>
          {{ t('stock.orders.replenishment_cta') }}
        </RouterLink>
        <RouterLink v-if="auth.canWrite('stock.orders.write')" to="/stock/purchase-orders/new" :class="btnFilled('primary')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
          {{ t('stock.orders.new') }}
        </RouterLink>
      </div>
    </div>

    <!-- Řádek pohledů — bez jediného uloženého pohledu se nevykresluje vůbec. -->
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
          <input v-model="filters.q" type="search" :placeholder="t('stock.orders.filter_q')"
            @keyup.enter="applyFilters" @change="applyFilters"
            class="w-full h-9 pl-9 pr-3 border border-neutral-300 rounded-md text-sm" />
        </div>
      </template>

      <select v-model="filters.state" @change="applyFilters" class="h-9 px-3 border border-neutral-300 rounded-md text-sm bg-surface"
        :title="t('stock.orders.filter_state')">
        <option value="">{{ t('stock.orders.filter_state') }}: {{ t('common.all') }}</option>
        <option value="open">{{ t('stock.orders.filter_state_open') }}</option>
        <option v-for="s in STATES" :key="s" :value="s">{{ t(`stock.order_state.${s}`) }}</option>
      </select>
      <select v-model="filters.warehouse_id" @change="applyFilters" class="h-9 px-3 border border-neutral-300 rounded-md text-sm bg-surface"
        :title="t('stock.orders.filter_warehouse')">
        <option value="">{{ t('stock.orders.filter_warehouse') }}: {{ t('common.all') }}</option>
        <option v-for="w in warehouses" :key="w.id" :value="w.id">{{ w.name }}</option>
      </select>
      <label class="inline-flex items-center gap-1.5 text-sm text-neutral-600">
        {{ t('stock.orders.filter_from') }}
        <DateInput v-model="filters.from" @change="applyFilters" class="h-9 px-2 border border-neutral-300 rounded-md text-sm" />
      </label>
      <label class="inline-flex items-center gap-1.5 text-sm text-neutral-600">
        {{ t('stock.orders.filter_to') }}
        <DateInput v-model="filters.to" @change="applyFilters" class="h-9 px-2 border border-neutral-300 rounded-md text-sm" />
      </label>
      <label class="inline-flex items-center gap-1.5 text-sm text-neutral-600">
        {{ t('stock.orders.filter_expected_to') }}
        <DateInput v-model="filters.expected_to" @change="applyFilters" class="h-9 px-2 border border-neutral-300 rounded-md text-sm" />
      </label>

      <template #actions>
        <SavedFiltersMenu :ctrl="saved" />
        <ColumnPicker class="hidden md:block" :ctrl="tbl" />
        <DensityToggle class="hidden md:block" :ctrl="tbl" />
      </template>
    </FilterBar>

    <div v-if="loading" class="text-center text-neutral-500 py-12 text-sm">{{ t('common.loading') }}</div>
    <EmptyState v-else-if="orders.length === 0 && hasActiveFilters" boxed variant="filtered"
      :cta="activeFilterCount > 0 || filters.q ? t('common.empty_state.clear_filters') : undefined"
      @action="resetFilters" />
    <EmptyState v-else-if="orders.length === 0" boxed icon="send"
      :title="t('stock.orders.empty_title')"
      :message="t('stock.orders.empty_hint')" />

    <div v-else class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
      <div class="overflow-x-auto">
        <table class="w-full text-sm" :class="tbl.densityClass.value">
          <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
            <tr>
              <th v-if="tbl.isVisible('number')" class="px-3 py-2 text-left font-medium">{{ t('stock.orders.col_number') }}</th>
              <th v-if="tbl.isVisible('date')" class="px-3 py-2 text-left font-medium w-28">{{ t('stock.orders.col_date') }}</th>
              <th v-if="tbl.isVisible('vendor')" class="px-3 py-2 text-left font-medium">{{ t('stock.orders.col_vendor') }}</th>
              <th v-if="tbl.isVisible('warehouse')" class="px-3 py-2 text-left font-medium">{{ t('stock.orders.col_warehouse') }}</th>
              <th v-if="tbl.isVisible('expected')" class="px-3 py-2 text-left font-medium w-28">{{ t('stock.orders.col_expected') }}</th>
              <th v-if="tbl.isVisible('qty_ordered')" class="px-3 py-2 text-right font-medium">{{ t('stock.orders.col_qty_ordered') }}</th>
              <th v-if="tbl.isVisible('qty_received')" class="px-3 py-2 text-right font-medium">{{ t('stock.orders.col_qty_received') }}</th>
              <th v-if="tbl.isVisible('qty_remaining')" class="px-3 py-2 text-right font-medium">{{ t('stock.orders.col_qty_remaining') }}</th>
              <th v-if="tbl.isVisible('total')" class="px-3 py-2 text-right font-medium">{{ t('stock.orders.col_total') }}</th>
              <th v-if="tbl.isVisible('state')" class="px-3 py-2 text-center font-medium w-32">{{ t('stock.orders.col_state') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="o in orders" :key="o.id" class="cursor-pointer hover:bg-neutral-50" :class="{ 'opacity-60': o.state === 'cancelled' }"
              @click="openDetail(o, $event)" @auxclick.prevent="openDetail(o, $event)">
              <td v-if="tbl.isVisible('number')" class="px-3 py-2 font-mono text-xs whitespace-nowrap">
                <RouterLink class="row-link" :to="`/stock/purchase-orders/${o.id}`" @click.stop @auxclick.stop>{{ o.order_number || t('stock.orders.draft_number') }}</RouterLink>
              </td>
              <td v-if="tbl.isVisible('date')" class="px-3 py-2 whitespace-nowrap">{{ formatDate(o.order_date) }}</td>
              <td v-if="tbl.isVisible('vendor')" class="px-3 py-2 truncate max-w-[16rem]">{{ o.vendor_name || '—' }}</td>
              <td v-if="tbl.isVisible('warehouse')" class="px-3 py-2 whitespace-nowrap">{{ o.warehouse_code }}</td>
              <td v-if="tbl.isVisible('expected')" class="px-3 py-2 whitespace-nowrap">{{ o.expected_date ? formatDate(o.expected_date) : '—' }}</td>
              <td v-if="tbl.isVisible('qty_ordered')" class="px-3 py-2 text-right font-mono whitespace-nowrap">{{ o.qty_ordered_total }}</td>
              <td v-if="tbl.isVisible('qty_received')" class="px-3 py-2 text-right font-mono whitespace-nowrap">{{ o.qty_received_total }}</td>
              <td v-if="tbl.isVisible('qty_remaining')" class="px-3 py-2 text-right font-mono whitespace-nowrap">{{ o.qty_remaining_total }}</td>
              <td v-if="tbl.isVisible('total')" class="px-3 py-2 text-right font-mono whitespace-nowrap">{{ formatMoney(Number(o.total_without_vat), o.currency_code ?? 'CZK') }}</td>
              <td v-if="tbl.isVisible('state')" class="px-3 py-2 text-center">
                <span class="text-xs px-2 py-0.5 rounded font-medium" :class="STATE_BADGE[o.state]">{{ t(`stock.order_state.${o.state}`) }}</span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <nav v-if="!loading && total > limit" class="mt-4 flex items-center justify-between gap-3 text-sm">
      <span class="text-neutral-500">{{ t('common.pagination_range', { from: offset + 1, to: Math.min(offset + limit, total), total }) }}</span>
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

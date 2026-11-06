<script setup lang="ts">
import { useWindowRefresh } from '@/composables/useWindowRefresh'
import { ref, onMounted, watch, computed } from 'vue'
import { useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import { clientsApi, type Client, type ClientDuplicateGroup } from '@/api/clients'
import Modal from '@/components/ui/Modal.vue'
import { expenseCategoriesApi, type ExpenseCategory } from '@/api/expenseCategories'
import { formatMoney, formatDate, paymentDueLabel } from '@/composables/useFormat'
import { useSupplierStore } from '@/stores/supplier'
import { useRowLink } from '@/composables/useRowLink'
import { useListKeyboard } from '@/composables/useListKeyboard'
import TableSkeleton from '@/components/ui/TableSkeleton.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import SavedFiltersMenu from '@/components/ui/SavedFiltersMenu.vue'
import ColumnPicker from '@/components/ui/ColumnPicker.vue'
import DensityToggle from '@/components/ui/DensityToggle.vue'
import SortableTh from '@/components/ui/SortableTh.vue'
import { useTablePrefs, type ColumnDef } from '@/composables/useTablePrefs'
import { useSavedFilters, savedFilterTone, type SavedFilterTone } from '@/composables/useSavedFilters'
import type { SavedFilter } from '@/api/preferences'
import { ICONS, btnFilled } from '@/components/ui/buttonStyles'
import { eshopApi, type PriceLevel } from '@/api/eshop'

type RoleFilter = 'all' | 'customers' | 'vendors'

const { t } = useI18n()
const auth = useAuthStore()
const supplierStore = useSupplierStore()

const items = ref<Client[]>([])
const total = ref(0)
const page = ref(1)
const pages = ref(1)
const loading = ref(false)
const loadingMore = ref(false)
const search = ref('')
const showArchived = ref(false)
const sort = ref<'name' | 'revenue' | 'last_activity'>('name')
// Filtr na výchozí kategorii nákladu dodavatele — jen ve vendor view.
const expenseCategories = ref<ExpenseCategory[]>([])
const categoryFilter = ref<number | null>(null)
// Filtr cenové hladiny — jen se zapnutým skladem. null = vše, 0 = „Default" (bez hladiny), jinak id hladiny.
const stockEnabled = computed(() => supplierStore.currentSupplier?.stock_enabled === true)
const priceLevels = ref<PriceLevel[]>([])
const priceLevelFilter = ref<number | null>(null)
function priceLevelLabel(c: Client): string {
  return c.price_level_id != null ? (c.price_level_name ?? `#${c.price_level_id}`) : t('client.price_level_default')
}
const route = useRoute()
// Filter from ?role=vendors|all|customers (default customers).
// Watch query.role pro proklik mezi sidebar položkami Klienti ↔ Dodavatelé
// (Vue Router neremountuje komponentu při stejné route, jen mění query).
function readRoleFromQuery(): RoleFilter {
  const q = String(route.query.role ?? '')
  return q === 'vendors' || q === 'all' ? q : 'customers'
}
const roleFilter = ref<RoleFilter>(readRoleFromQuery())
watch(() => route.query.role, () => {
  roleFilter.value = readRoleFromQuery()
})
let searchTimeout: ReturnType<typeof setTimeout> | null = null

// Server-side role filter — backend respektuje cfg.php pagination.clients_per_page
// a vrací meta.role_counts pro tab badge. Frontend žádný client-side filter neaplikuje.
const filteredItems = computed(() => items.value)

// Role (Klienti/Dodavatelé) se nepočítá — tu volí záložka, ne filtr.
const hasActiveFilters = computed(() => !!search.value || showArchived.value || categoryFilter.value !== null || priceLevelFilter.value !== null)
function clearFiltersAndSearch() {
  search.value = ''
  showArchived.value = false
  categoryFilter.value = null
  priceLevelFilter.value = null
  load()
}

const roleCounts = ref<{ all: number; customers: number; vendors: number }>({
  all: 0, customers: 0, vendors: 0,
})

// FR 2 (vendor bugreport 2026-08-06) — report existujících duplicit (stejné IČO/DIČ
// po normalizaci). Nezávislé na aktuálním filtru/stránkování seznamu — počítá se
// přes všechny aktivní karty tenanta, banner se zobrazí jen když nějaké najde.
const duplicateGroups = ref<ClientDuplicateGroup[]>([])
const showDuplicatesModal = ref(false)
async function loadDuplicates() {
  try {
    const r = await clientsApi.duplicates()
    duplicateGroups.value = r.groups
  } catch {
    duplicateGroups.value = []
  }
}
function duplicateGroupLabel(g: ClientDuplicateGroup): string {
  return g.key_type === 'ic'
    ? t('client.duplicates.group_by_ic', { value: g.key_value })
    : t('client.duplicates.group_by_dic', { value: g.key_value })
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
    const r = await clientsApi.list({
      q: search.value,
      archived: showArchived.value,
      sort: sort.value,
      role: roleFilter.value,
      // Kategorie filtruje jen u dodavatelů (jinde nemá smysl).
      expense_category_id: roleFilter.value === 'vendors' ? categoryFilter.value : null,
      price_level: stockEnabled.value ? priceLevelFilter.value : null,
      page: page.value,
    })
    if (reset) {
      items.value = r.data
    } else {
      items.value.push(...r.data)
    }
    total.value = r.meta.total
    pages.value = r.meta.pages
    if (r.meta.role_counts) {
      roleCounts.value = r.meta.role_counts
    }
  } finally {
    loading.value = false
    loadingMore.value = false
  }
}

function buildQuery(): Record<string, string> {
  const q: Record<string, string> = {}
  if (search.value) q.q = search.value
  if (showArchived.value) q.archived = '1'
  if (sort.value !== 'name') q.sort = sort.value
  if (roleFilter.value === 'vendors' && categoryFilter.value !== null) q.category_id = String(categoryFilter.value)
  if (stockEnabled.value && priceLevelFilter.value !== null) q.price_level = String(priceLevelFilter.value)
  return q
}

function applyQueryToPage(q: Record<string, string>) {
  search.value = q.q ?? ''
  showArchived.value = q.archived === '1'
  sort.value = q.sort === 'revenue' || q.sort === 'last_activity' ? q.sort : 'name'
  categoryFilter.value = q.category_id ? Number(q.category_id) : null
  priceLevelFilter.value = q.price_level !== undefined && q.price_level !== '' && Number.isInteger(Number(q.price_level)) ? Number(q.price_level) : null
  load(true)
}

const COLUMNS: ColumnDef[] = [
  { key: 'company', labelKey: 'client.company', required: true, sortable: true },
  { key: 'ic', labelKey: 'common.ic' },
  { key: 'email', labelKey: 'client.email' },
  { key: 'count', labelKey: 'client.invoice_count_label' },
  { key: 'amount', labelKey: 'common.revenue', required: true, sortable: true },
  { key: 'last_activity', labelKey: 'common.last_activity', sortable: true },
  { key: 'vat_payer', labelKey: 'client.vat_payer_label' },
  { key: 'currency', labelKey: 'common.currency' },
  // Doplňkové sloupce — defaultně skryté, uživatel si je zapne přes ColumnPicker.
  { key: 'dic', labelKey: 'common.dic', defaultHidden: true },
  { key: 'payment_due', labelKey: 'client.payment_due_label', defaultHidden: true },
  // Cenová hladina existuje jen se zapnutým skladem — bez něj sloupec nejde ani zapnout.
  ...(supplierStore.currentSupplier?.stock_enabled === true ? [{ key: 'price_level', labelKey: 'client.price_level_col' }] : []),
]
const tbl = useTablePrefs('clients', COLUMNS)
const saved = useSavedFilters('clients', { getQuery: buildQuery, applyQuery: applyQueryToPage })

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

// R10: existující select i nový SortableTh píší do téhož `sort` refu — synchronizace s prefs.
watch(() => tbl.sort.value, (s) => {
  const v = s?.key
  sort.value = v === 'revenue' || v === 'last_activity' ? v : 'name'
})
watch(sort, (v) => {
  if (tbl.sort.value?.key !== v) tbl.toggleSort(v)
})
function onSortToggle(key: string) {
  tbl.toggleSort(key)
}

onMounted(async () => {
  expenseCategories.value = await expenseCategoriesApi.list(false).catch(() => [])
  if (stockEnabled.value) void eshopApi.listPriceLevels().then(rows => { priceLevels.value = rows }).catch(() => { priceLevels.value = [] })
  loadDuplicates()
  if (Object.keys(route.query).length === 0 && await saved.applyDefaultIfAny()) return
  load(true)
})
watch(showArchived, () => load(true))
watch(sort, () => load(true))
watch(roleFilter, () => load(true))
watch(categoryFilter, () => load(true))
watch(priceLevelFilter, () => load(true))
watch(search, () => {
  if (searchTimeout) clearTimeout(searchTimeout)
  searchTimeout = setTimeout(() => load(true), 300)
})

const navigateRow = useRowLink()
function openClient(c: Client, e?: MouseEvent) {
  navigateRow(`/clients/${c.id}`, e)
}

/**
 * Klávesové ovládání j/k + Enter. Seznam klientů nemá hromadný výběr, takže se
 * `toggle`/`clear` nepředávají — „x" ani Esc tu nemají co dělat.
 */
const { activeIndex } = useListKeyboard({
  count: () => filteredItems.value.length,
  open: (i) => { const c = filteredItems.value[i]; if (c) openClient(c) },
})

// Kompaktní zápis splatnosti (payment_due_default + unit) — stejně jako ClientDetail.
// Jednotku klient bez vlastní dědí z dodavatele, jinak by tu „1× měsíc" svítilo jako „1 den".
function formatPaymentDue(c: Client): string {
  return paymentDueLabel(
    c.payment_due_default,
    c.payment_due_unit,
    supplierStore.currentSupplier?.default_payment_due_unit,
    '—',
  )
}
useWindowRefresh(() => load(), () => !showDuplicatesModal.value)
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-4">
      <h1 class="text-2xl font-semibold">{{ roleFilter === 'vendors' ? t('client.title_vendors') : t('client.title') }}</h1>
      <RouterLink
        v-if="auth.canWrite('clients.create') || auth.isDemo"
        :to="roleFilter === 'vendors' ? '/clients/new?role=vendor' : '/clients/new'"
        :class="btnFilled('primary')"
      >
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
        {{ roleFilter === 'vendors' ? t('purchase_invoice.new_vendor') : t('client.new') }}
      </RouterLink>
    </div>

    <!-- FR 2 — report existujících duplicit (stejné IČO/DIČ v jiném zápisu). -->
    <div v-if="duplicateGroups.length" class="mb-4 px-4 py-2.5 bg-warning-50 border border-warning-200 rounded-lg flex flex-wrap items-center gap-2 text-sm">
      <svg class="w-4 h-4 shrink-0 text-warning-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z" />
      </svg>
      <span class="text-warning-800 font-medium">{{ t('client.duplicates.banner_title', { count: duplicateGroups.length }) }}</span>
      <span class="text-warning-700">{{ t('client.duplicates.banner_hint') }}</span>
      <button type="button" @click="showDuplicatesModal = true"
        class="cursor-pointer ml-auto shrink-0 text-warning-800 font-medium underline hover:no-underline whitespace-nowrap">
        {{ t('client.duplicates.view_link') }}
      </button>
    </div>

    <Modal v-if="showDuplicatesModal" :title="t('client.duplicates.modal_title')" @close="showDuplicatesModal = false">
      <div v-if="!duplicateGroups.length" class="text-sm text-neutral-500">{{ t('client.duplicates.no_duplicates') }}</div>
      <div v-else class="space-y-4">
        <div v-for="(g, gi) in duplicateGroups" :key="gi" class="border border-neutral-200 rounded-lg p-3">
          <div class="text-sm font-medium text-neutral-700 mb-2">{{ duplicateGroupLabel(g) }}</div>
          <ul class="space-y-1">
            <li v-for="c in g.clients" :key="c.id" class="flex items-center justify-between gap-2 text-sm">
              <span class="truncate">{{ c.company_name }}</span>
              <RouterLink :to="`/clients/${c.id}`" class="shrink-0 text-primary-600 hover:underline" @click="showDuplicatesModal = false">
                {{ t('client.duplicates.open_card') }}
              </RouterLink>
            </li>
          </ul>
        </div>
      </div>
    </Modal>

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

    <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm">
      <!-- Tabs: Klienti / Dodavatelé / Vše -->
      <div class="px-4 pt-2 border-b border-neutral-100 flex items-center gap-1">
        <button
          v-for="opt in [
            { key: 'customers' as RoleFilter, label: t('client.tab_customers') },
            { key: 'vendors' as RoleFilter,   label: t('client.tab_vendors') },
            { key: 'all' as RoleFilter,       label: t('client.tab_all') },
          ]"
          :key="opt.key"
          type="button"
          @click="roleFilter = opt.key"
          class="cursor-pointer relative px-3 py-2 text-sm border-b-2 transition"
          :class="roleFilter === opt.key
            ? 'border-primary-600 text-primary-700 font-medium'
            : 'border-transparent text-neutral-600 hover:text-neutral-900'"
        >
          {{ opt.label }}
          <span class="ml-1.5 inline-block px-1.5 py-0.5 text-xs bg-neutral-100 text-neutral-600 rounded-full">{{ roleCounts[opt.key] }}</span>
        </button>
      </div>

      <div class="px-4 py-3 border-b border-neutral-200 flex flex-col sm:flex-row sm:flex-wrap sm:items-center gap-3">
        <input
          v-model="search"
          type="search"
          :placeholder="t('common.search')"
          class="flex-1 h-9 px-3 border border-neutral-300 rounded-md text-sm focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none"
        />
        <label class="flex items-center gap-2 text-sm text-neutral-700">
          <input v-model="showArchived" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
          {{ t('client.show_archived') }}
        </label>
        <select v-if="roleFilter === 'vendors'" v-model.number="categoryFilter"
          class="h-9 px-3 border border-neutral-300 rounded-md text-sm bg-surface"
          :title="t('client.default_expense_category')">
          <option :value="null">{{ t('client.filter_category_all') }}</option>
          <option v-for="c in expenseCategories" :key="c.id" :value="c.id">{{ c.label }} ({{ c.code }})</option>
        </select>
        <select v-if="stockEnabled" v-model="priceLevelFilter" data-test="client-price-level-filter"
          class="h-9 px-3 border border-neutral-300 rounded-md text-sm bg-surface"
          :title="t('client.price_level')">
          <option :value="null">{{ t('client.filter_price_level_all') }}</option>
          <option :value="0">{{ t('client.price_level_default') }}</option>
          <option v-for="l in priceLevels.filter(pl => pl.is_active || pl.id === priceLevelFilter)" :key="l.id" :value="l.id">{{ l.name }}</option>
        </select>
        <select v-model="sort" class="h-9 px-3 border border-neutral-300 rounded-md text-sm bg-surface"
          :title="t('common.sort_by')">
          <option value="name">{{ t('common.sort_name') }}</option>
          <option value="revenue">{{ t('common.sort_revenue') }}</option>
          <option value="last_activity">{{ t('common.sort_last_activity') }}</option>
        </select>
        <div class="flex flex-wrap items-center gap-2 sm:ml-auto">
          <SavedFiltersMenu :ctrl="saved" />
          <ColumnPicker class="hidden md:block" :ctrl="tbl" />
          <DensityToggle class="hidden md:block" :ctrl="tbl" />
        </div>
      </div>

      <TableSkeleton v-if="loading" :rows="6" :cols="6" />

      <!-- Hledání/archiv/kategorie jsou jiný důvod prázdna než prázdná agenda —
           tam nabízíme zrušit filtr, ne zakládat prvního klienta. -->
      <EmptyState v-else-if="!items.length && hasActiveFilters" variant="filtered"
        :cta="t('common.empty_state.clear_filters')" @action="clearFiltersAndSearch" />
      <EmptyState v-else-if="!items.length" icon="user"
        :title="t('client.empty_title')"
        :message="t('client.empty_hint')"
        :cta="auth.canWrite('clients.create') || auth.isDemo ? t('client.create_first') : undefined"
        :to="roleFilter === 'vendors' ? '/clients/new?role=vendor' : '/clients/new'" />

      <!-- Desktop: tabulka -->
      <div v-else class="hidden md:block overflow-x-auto"><table v-column-labels="tbl" class="w-full text-sm table-sticky-first" :class="tbl.densityClass.value">
        <thead class="bg-neutral-50 text-neutral-500 text-xs uppercase tracking-wide">
          <tr>
            <SortableTh v-if="tbl.isVisible('company')" :label="t('client.company')" sort-key="name" :sort="tbl.sort.value" @toggle="onSortToggle" />
            <th v-if="tbl.isVisible('ic')" class="text-left px-4 py-2.5 font-medium">{{ t('common.ic') }}</th>
            <th v-if="tbl.isVisible('email')" class="text-left px-4 py-2.5 font-medium">{{ t('client.email') }}</th>
            <th v-if="tbl.isVisible('count')" class="text-center px-4 py-2.5 font-medium">{{ t('client.invoice_count_label') }}</th>
            <SortableTh v-if="tbl.isVisible('amount')" :label="roleFilter === 'vendors' ? t('common.costs') : t('common.revenue')" sort-key="revenue" :sort="tbl.sort.value" align="right" @toggle="onSortToggle" />
            <SortableTh v-if="tbl.isVisible('last_activity')" :label="t('common.last_activity')" sort-key="last_activity" :sort="tbl.sort.value" @toggle="onSortToggle" />
            <th v-if="roleFilter === 'vendors' && tbl.isVisible('vat_payer')" class="text-center px-4 py-2.5 font-medium">{{ t('client.vat_payer_label') }}</th>
            <th v-if="tbl.isVisible('currency')" class="text-center px-4 py-2.5 font-medium">{{ t('common.currency') }}</th>
            <th v-if="tbl.isVisible('dic')" class="text-left px-4 py-2.5 font-medium">{{ t('common.dic') }}</th>
            <th v-if="tbl.isVisible('payment_due')" class="text-center px-4 py-2.5 font-medium">{{ t('client.payment_due_label') }}</th>
            <th v-if="stockEnabled && tbl.isVisible('price_level')" class="text-left px-4 py-2.5 font-medium">{{ t('client.price_level_col') }}</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-neutral-100">
          <tr
            v-for="(c, ri) in filteredItems"
            :key="c.id"
            @click="openClient(c, $event)"
            @auxclick.prevent="openClient(c, $event)"
            class="cursor-pointer hover:bg-neutral-50"
            :data-row-active="ri === activeIndex"
          >
            <td v-if="tbl.isVisible('company')" class="px-4 py-3">
              <div class="flex items-center gap-2">
                <RouterLink class="row-link font-medium text-neutral-900" :to="`/clients/${c.id}`" @click.stop @auxclick.stop>{{ c.company_name }}</RouterLink>
                <span v-if="c.is_customer !== false && c.is_vendor === true"
                      class="inline-block px-1.5 py-0 text-[10px] bg-primary-100 text-primary-700 rounded font-medium uppercase tracking-wide"
                      :title="t('client.dual_role_tooltip')">K+D</span>
                <span v-else-if="c.is_vendor === true"
                      class="inline-block px-1.5 py-0 text-[10px] bg-warning-50 text-warning-600 rounded font-medium uppercase tracking-wide">{{ t('client.vendor_badge') }}</span>
              </div>
              <div v-if="c.archived_at" class="text-xs text-neutral-400 mt-0.5">{{ t('common.archived') }}</div>
            </td>
            <td v-if="tbl.isVisible('ic')" class="px-4 py-3 font-mono text-xs text-neutral-600">{{ c.ic || '—' }}</td>
            <td v-if="tbl.isVisible('email')" class="px-4 py-3 text-neutral-600">{{ c.main_email || '—' }}</td>
            <td v-if="tbl.isVisible('count')" class="px-4 py-3 text-center">
              <template v-if="roleFilter === 'vendors'">
                <span v-if="c.purchase_count" class="inline-block px-2 py-0.5 text-xs bg-warning-50 text-warning-700 rounded">
                  {{ c.purchase_count }}
                </span>
                <span v-else class="text-neutral-300">—</span>
              </template>
              <template v-else>
                <span v-if="c.invoice_count" class="inline-block px-2 py-0.5 text-xs bg-primary-50 text-primary-700 rounded">
                  {{ c.invoice_count }}
                </span>
                <span v-else class="text-neutral-300">—</span>
              </template>
            </td>
            <td v-if="tbl.isVisible('amount')" class="px-4 py-3 text-right font-mono">
              <template v-if="roleFilter === 'vendors'">
                <span v-if="c.costs && c.costs > 0">{{ formatMoney(c.costs, 'CZK') }}</span>
                <span v-else class="text-neutral-300">—</span>
              </template>
              <template v-else>
                <span v-if="c.revenue && c.revenue > 0">{{ formatMoney(c.revenue, c.currency_default) }}</span>
                <span v-else class="text-neutral-300">—</span>
              </template>
            </td>
            <td v-if="tbl.isVisible('last_activity')" class="px-4 py-3 text-neutral-600 text-xs">
              <template v-if="roleFilter === 'vendors'">
                <span v-if="c.last_purchase_date">{{ formatDate(c.last_purchase_date) }}</span>
                <span v-else class="text-neutral-300">—</span>
              </template>
              <template v-else>
                <span v-if="c.last_invoice_date">{{ formatDate(c.last_invoice_date) }}</span>
                <span v-else class="text-neutral-300">—</span>
              </template>
            </td>
            <td v-if="roleFilter === 'vendors' && tbl.isVisible('vat_payer')" class="px-4 py-3 text-center">
              <span v-if="c.is_vat_payer === false" class="inline-block px-2 py-0.5 text-xs bg-warning-50 text-warning-700 rounded">{{ t('common.no') }}</span>
              <span v-else class="inline-block px-2 py-0.5 text-xs bg-success-50 text-success-700 rounded">{{ t('common.yes') }}</span>
            </td>
            <td v-if="tbl.isVisible('currency')" class="px-4 py-3 text-center text-neutral-600 font-mono text-xs">{{ c.currency_default }}</td>
            <td v-if="tbl.isVisible('dic')" class="px-4 py-3 font-mono text-xs text-neutral-600">{{ c.dic || '—' }}</td>
            <td v-if="tbl.isVisible('payment_due')" class="px-4 py-3 text-center text-xs text-neutral-600">{{ formatPaymentDue(c) }}</td>
            <td v-if="stockEnabled && tbl.isVisible('price_level')" class="px-4 py-3">
              <span class="inline-block px-2 py-0.5 text-xs rounded whitespace-nowrap" :class="c.price_level_id != null ? 'bg-primary-50 text-primary-700' : 'bg-neutral-100 text-neutral-600'">{{ priceLevelLabel(c) }}</span>
            </td>
          </tr>
        </tbody>
      </table></div>

      <!-- Mobile: karty -->
      <div v-if="items.length" class="md:hidden divide-y divide-neutral-100">
        <div
          v-for="c in filteredItems"
          :key="`m-${c.id}`"
          @click="openClient(c, $event)"
          @auxclick.prevent="openClient(c, $event)"
          class="cursor-pointer hover:bg-neutral-50 transition px-4 py-3"
        >
          <div class="flex items-baseline justify-between gap-2">
            <RouterLink class="row-link font-medium text-neutral-900 truncate" :to="`/clients/${c.id}`" @click.stop @auxclick.stop>{{ c.company_name }}</RouterLink>
            <div class="font-mono text-sm whitespace-nowrap">
              <template v-if="roleFilter === 'vendors'">
                <span v-if="c.costs && c.costs > 0">{{ formatMoney(c.costs, 'CZK') }}</span>
                <span v-else class="text-neutral-300">—</span>
              </template>
              <template v-else>
                <span v-if="c.revenue && c.revenue > 0">{{ formatMoney(c.revenue, c.currency_default) }}</span>
                <span v-else class="text-neutral-300">—</span>
              </template>
            </div>
          </div>
          <div v-if="c.archived_at" class="text-xs text-neutral-400 mt-0.5">{{ t('common.archived') }}</div>
          <div class="flex items-baseline justify-between gap-2 mt-1 text-xs text-neutral-500">
            <div class="truncate">
              <span class="font-mono">{{ c.ic || '—' }}</span>
              <span v-if="c.main_email" class="text-neutral-400"> · </span>
              <span v-if="c.main_email" class="truncate">{{ c.main_email }}</span>
            </div>
            <span class="flex items-center gap-1.5 whitespace-nowrap">
              <span v-if="stockEnabled" class="px-1.5 py-0.5 rounded" :class="c.price_level_id != null ? 'bg-primary-50 text-primary-700' : 'bg-neutral-100 text-neutral-600'">{{ priceLevelLabel(c) }}</span>
              <span class="font-mono">{{ c.currency_default }}</span>
            </span>
          </div>
          <div class="flex items-center justify-between gap-2 mt-2 text-xs">
            <span class="text-neutral-600">
              <span v-if="c.last_invoice_date">{{ formatDate(c.last_invoice_date) }}</span>
              <span v-else class="text-neutral-300">—</span>
            </span>
            <span v-if="roleFilter === 'vendors' && c.purchase_count" class="px-2 py-0.5 bg-warning-50 text-warning-700 rounded">
              {{ t('client.invoice_count_label') }}: {{ c.purchase_count }}
            </span>
            <span v-else-if="c.invoice_count" class="px-2 py-0.5 bg-primary-50 text-primary-700 rounded">
              {{ t('client.invoice_count_label') }}: {{ c.invoice_count }}
            </span>
          </div>
        </div>
      </div>

      <div v-if="items.length" class="px-4 py-3 border-t border-neutral-200 flex items-center justify-between text-sm">
        <span class="text-neutral-500">{{ t('common.loaded_count', { loaded: filteredItems.length, total: total }) }}</span>
        <button v-if="page < pages" @click="load(false)" :disabled="loadingMore"
          class="cursor-pointer h-9 px-4 text-sm bg-primary-600 hover:bg-primary-700 text-white font-medium disabled:opacity-50 rounded-md inline-flex items-center gap-1.5">
          {{ loadingMore ? t('common.loading_more') : t('common.load_more') }}
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M19 14l-7 7m0 0l-7-7m7 7V3"/></svg>
        </button>
      </div>
    </div>
  </div>
</template>

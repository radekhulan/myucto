<script setup lang="ts">
import { computed, onActivated, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter, type LocationQuery } from 'vue-router'
import {
  accrualsApi, ACCRUAL_AGENDA_KINDS, ACCRUAL_METHODS, ACCRUAL_SOURCE_TYPES, ACCRUAL_STATUSES,
  type AccrualItem, type AccrualListFilter, type AccrualProjection, type AccrualReconciliation, type AccrualUnallocated,
  type AccrualUnallocatedRow,
} from '@/api/accruals'
import { useAuthStore } from '@/stores/auth'
import { useSupplierStore } from '@/stores/supplier'
import { useToast } from '@/composables/useToast'
import { formatDate, formatMoney } from '@/composables/useFormat'
import { isCalendarIsoDate } from '@/utils/date'
import { accrualStatusClass } from '@/utils/accrualStatus'
import { accrualPrefillQuery, accrualSourceRoute } from '@/utils/accrualSource'
import { useRowLink } from '@/composables/useRowLink'
import { useListNavigationSource } from '@/composables/useListNavigation'
import { useFillViewportHeight } from '@/composables/useFillViewportHeight'
import { useScrollLoadMore } from '@/composables/useScrollLoadMore'
import { useTablePrefs, type ColumnDef } from '@/composables/useTablePrefs'
import { useListKeyboard } from '@/composables/useListKeyboard'
import { useSavedFilters } from '@/composables/useSavedFilters'
import { ensurePrefsLoaded } from '@/composables/useUserPrefs'
import InvoiceBookMenu from '@/components/invoices/InvoiceBookMenu.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import FilterBar, { type FilterChip } from '@/components/ui/FilterBar.vue'
import TableSkeleton from '@/components/ui/TableSkeleton.vue'
import ListLoadingSpinner from '@/components/ui/ListLoadingSpinner.vue'
import DateInput from '@/components/ui/DateInput.vue'
import SortableTh from '@/components/ui/SortableTh.vue'
import ColumnPicker from '@/components/ui/ColumnPicker.vue'
import DensityToggle from '@/components/ui/DensityToggle.vue'
import SavedFiltersMenu from '@/components/ui/SavedFiltersMenu.vue'
import Modal from '@/components/ui/Modal.vue'
import { ICONS, btnFilled, btnOutline, btnOutlineSm } from '@/components/ui/buttonStyles'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const supplierStore = useSupplierStore()
const toast = useToast()
const navigate = useRowLink()

const canWrite = computed(() => auth.canWrite('accounting.accruals.write'))

const items = ref<AccrualItem[]>([])
const total = ref(0)
const totals = ref<{ basis_amount: number; balance: number }>({ basis_amount: 0, balance: 0 })
const page = ref(1)
const perPage = ref(50)
const loading = ref(false)
const loadingMore = ref(false)
const failed = ref(false)
const loadedAsOf = ref('')
const listBox = ref<HTMLElement | null>(null)
const loadMoreTarget = ref<HTMLElement | null>(null)
useFillViewportHeight(listBox)

// ─── Filtry (drží je URL) ───
// Výchozí je přehled dokladů, které časově rozlišuje uzávěrka: běžná firma rozlišuje fakturami
// a do uzávěrky jsou účty 381–385 nulové, takže samotné položky agendy bývají prázdné.
const tab = ref<'items' | 'closing'>('closing')
const status = ref('')
const kind = ref('')
const method = ref('')
const sourceType = ref('')
const account = ref('')
const partnerId = ref<number | null>(null)
const withBalance = ref(false)
const endsBy = ref('')
const asOf = ref('')
const search = ref('')
const searchPending = ref(false)
let searchTimer: ReturnType<typeof setTimeout> | null = null
let accountTimer: ReturnType<typeof setTimeout> | null = null
let loadRequest = 0

const pages = computed(() => Math.max(1, Math.ceil(total.value / perPage.value)))
const canLoadMore = computed(() => !loading.value && !loadingMore.value && !searchPending.value && page.value < pages.value)
useScrollLoadMore(loadMoreTarget, () => canLoadMore.value, () => load(true))

function str(value: LocationQuery[string]): string {
  return typeof value === 'string' ? value : ''
}
function csvOf(value: string, allowed: readonly string[]): string {
  return value.split(',').filter(part => allowed.includes(part)).join(',')
}
function readQuery(q: LocationQuery) {
  tab.value = str(q.tab) === 'closing' ? 'closing' : 'items'
  status.value = csvOf(str(q.status), ACCRUAL_STATUSES)
  kind.value = csvOf(str(q.kind), ACCRUAL_AGENDA_KINDS)
  method.value = csvOf(str(q.method), ACCRUAL_METHODS)
  sourceType.value = csvOf(str(q.source_type), ACCRUAL_SOURCE_TYPES)
  account.value = /^[0-9A-Za-z.]{1,20}$/.test(str(q.account)) ? str(q.account) : ''
  const partner = Number(str(q.partner))
  partnerId.value = Number.isInteger(partner) && partner > 0 ? partner : null
  withBalance.value = str(q.with_balance) === '1'
  endsBy.value = isCalendarIsoDate(str(q.ends_by)) ? str(q.ends_by) : ''
  asOf.value = isCalendarIsoDate(str(q.as_of)) ? str(q.as_of) : ''
  search.value = str(q.q)
}
function buildQuery(): Record<string, string> {
  const q: Record<string, string> = {}
  if (status.value) q.status = status.value
  if (kind.value) q.kind = kind.value
  if (method.value) q.method = method.value
  if (sourceType.value) q.source_type = sourceType.value
  if (account.value.trim()) q.account = account.value.trim()
  if (partnerId.value) q.partner = String(partnerId.value)
  if (withBalance.value) q.with_balance = '1'
  if (endsBy.value) q.ends_by = endsBy.value
  if (asOf.value) q.as_of = asOf.value
  if (search.value.trim()) q.q = search.value.trim()
  return q
}
function urlQuery(): Record<string, string> {
  return tab.value === 'closing' ? { ...buildQuery(), tab: 'closing' } : buildQuery()
}
function stringQuery(query: LocationQuery): string {
  return JSON.stringify(Object.fromEntries(Object.entries(query).filter(([, value]) => typeof value === 'string')))
}
let applyingQuery = false
function writeUrl() {
  const next = urlQuery()
  if (JSON.stringify(next) === stringQuery(route.query)) return
  void Promise.resolve(router.replace({ query: next })).catch(() => undefined)
}

function listFilter(): AccrualListFilter {
  return {
    ...(status.value ? { status: status.value } : {}),
    ...(kind.value ? { kind: kind.value } : {}),
    ...(method.value ? { method: method.value } : {}),
    ...(sourceType.value ? { source_type: sourceType.value } : {}),
    ...(account.value.trim() ? { account: account.value.trim() } : {}),
    ...(partnerId.value ? { partner_id: partnerId.value } : {}),
    ...(withBalance.value ? { with_balance: 1 as const } : {}),
    ...(endsBy.value ? { ends_by: endsBy.value } : {}),
    ...(asOf.value ? { as_of: asOf.value } : {}),
    ...(search.value.trim() ? { q: search.value.trim() } : {}),
  }
}

// ─── Sloupce a řazení ───
const COLUMNS: ColumnDef[] = [
  { key: 'item_no', labelKey: 'accruals.item_no', required: true },
  { key: 'title', labelKey: 'accruals.title_label', required: true },
  { key: 'kind', labelKey: 'accruals.kind_label' },
  { key: 'accrual_account_code', labelKey: 'accruals.account' },
  { key: 'counter_account_code', labelKey: 'accruals.counter_account', defaultHidden: true },
  { key: 'partner_name', labelKey: 'accruals.partner' },
  { key: 'source', labelKey: 'accruals.source_label', defaultHidden: true },
  { key: 'period_to', labelKey: 'accruals.period' },
  { key: 'method', labelKey: 'accruals.method_label', defaultHidden: true },
  { key: 'basis_amount', labelKey: 'accruals.basis_amount' },
  { key: 'balance', labelKey: 'accruals.balance', required: true },
  { key: 'next_due_on', labelKey: 'accruals.next_due_on' },
  { key: 'status', labelKey: 'accruals.status_label' },
]
const SORTABLE = ['item_no', 'title', 'partner_name', 'accrual_account_code', 'period_to', 'basis_amount', 'balance', 'next_due_on', 'status']
const RIGHT_ALIGNED = ['basis_amount', 'balance']
const tbl = useTablePrefs('accruals', COLUMNS)
const visibleColumns = computed(() => tbl.orderedColumns.value.filter(column => tbl.isVisible(column.key)))
const sort = computed(() => tbl.sort.value)
function onSortToggle(key: string) {
  tbl.toggleSort(key)
  void load()
}
function clearSort() {
  tbl.clearSort()
  void load()
}
function listParams(nextPage: number) {
  return { ...listFilter(), page: nextPage, ...(sort.value ? { sort_by: sort.value.key, sort_dir: sort.value.dir } : {}) }
}

// ─── Lišta filtrů ───
const activeFilterCount = computed(() => [status.value, kind.value, method.value, sourceType.value, account.value.trim(),
  partnerId.value, withBalance.value, endsBy.value, asOf.value].filter(Boolean).length)
const hasActiveFilters = computed(() => activeFilterCount.value > 0 || !!search.value.trim())
function csvLabel(value: string, prefix: string): string {
  return value.split(',').map(part => t(`${prefix}.${part}`)).join(', ')
}
const filterChips = computed<FilterChip[]>(() => {
  const chips: FilterChip[] = []
  if (status.value) chips.push({ key: 'status', label: t('accruals.status_label'), value: csvLabel(status.value, 'accruals.status') })
  if (kind.value) chips.push({ key: 'kind', label: t('accruals.kind_label'), value: csvLabel(kind.value, 'accruals.kind') })
  if (method.value) chips.push({ key: 'method', label: t('accruals.method_label'), value: csvLabel(method.value, 'accruals.method') })
  if (sourceType.value) chips.push({ key: 'source_type', label: t('accruals.source_label'), value: csvLabel(sourceType.value, 'accruals.source') })
  if (account.value.trim()) chips.push({ key: 'account', label: t('accruals.account'), value: account.value.trim() })
  if (partnerId.value) chips.push({ key: 'partner', label: t('accruals.partner'), value: partnerLabel.value || `#${partnerId.value}` })
  if (withBalance.value) chips.push({ key: 'with_balance', label: t('accruals.with_balance'), value: t('common.yes') })
  if (endsBy.value) chips.push({ key: 'ends_by', label: t('accruals.ends_by'), value: formatDate(endsBy.value) })
  if (asOf.value) chips.push({ key: 'as_of', label: t('accruals.as_of'), value: formatDate(asOf.value) })
  return chips
})
const partnerLabel = computed(() => items.value.find(item => item.partner_id === partnerId.value)?.partner_name || '')
function clearFilter(key: string) {
  if (key === 'status') status.value = ''
  if (key === 'kind') kind.value = ''
  if (key === 'method') method.value = ''
  if (key === 'source_type') sourceType.value = ''
  if (key === 'account') account.value = ''
  if (key === 'partner') partnerId.value = null
  if (key === 'with_balance') withBalance.value = false
  if (key === 'ends_by') endsBy.value = ''
  if (key === 'as_of') asOf.value = ''
}
function clearFilters() {
  applyingQuery = true
  if (searchTimer) clearTimeout(searchTimer)
  searchPending.value = false
  status.value = ''
  kind.value = ''
  method.value = ''
  sourceType.value = ''
  account.value = ''
  partnerId.value = null
  withBalance.value = false
  endsBy.value = ''
  asOf.value = ''
  search.value = ''
  void Promise.resolve().then(() => { applyingQuery = false })
  writeUrl()
  reloadAll()
}

// ─── Načtení seznamu ───
let loadedParams: Record<string, unknown> = {}
let loadedPath = route.fullPath
const listNav = useListNavigationSource('accruals', () => ({
  ids: items.value.map(item => item.id), page: page.value, pages: pages.value, params: loadedParams,
}), () => loadedPath, () => supplierStore.currentSupplierId)

function detailRoute(item: AccrualItem) {
  return { name: 'accounting-accrual-detail', params: { id: item.id } }
}
function openDetail(item: AccrualItem, event?: MouseEvent) {
  listNav.markOpen(item.id)
  navigate(detailRoute(item), event)
}

async function load(append = false) {
  if (append && !canLoadMore.value) return
  const request = ++loadRequest
  if (append) loadingMore.value = true
  else {
    loading.value = true
    loadingMore.value = false
  }
  const nextPage = append ? page.value + 1 : 1
  const { page: _page, ...requestedParams } = listParams(1)
  const requestedPath = router.resolve({ path: route.path, query: buildQuery() }).fullPath
  try {
    const result = await accrualsApi.list(listParams(nextPage))
    if (request !== loadRequest) return
    items.value = append ? [...items.value, ...result.items] : result.items
    page.value = nextPage
    total.value = result.total
    perPage.value = result.per_page || 50
    totals.value = result.totals ?? { basis_amount: 0, balance: 0 }
    loadedAsOf.value = result.as_of || ''
    if (!append) {
      loadedParams = requestedParams
      loadedPath = requestedPath
    }
    failed.value = false
  } catch (error: any) {
    if (request !== loadRequest) return
    if (!append) failed.value = true
    toast.error(error?.response?.data?.error?.message || t('common.error'))
  } finally {
    if (request === loadRequest) {
      loading.value = false
      loadingMore.value = false
    }
  }
}

// ─── Odsouhlasení s hlavní knihou ───
const reconciliation = ref<AccrualReconciliation | null>(null)
const reconciliationLoading = ref(false)
const reconciliationFailed = ref(false)
let reconciliationRequest = 0
async function loadReconciliation() {
  const request = ++reconciliationRequest
  reconciliationLoading.value = true
  try {
    const result = await accrualsApi.reconciliation(asOf.value || undefined)
    if (request !== reconciliationRequest) return
    reconciliation.value = result
    reconciliationFailed.value = false
  } catch {
    if (request !== reconciliationRequest) return
    reconciliationFailed.value = true
  } finally {
    if (request === reconciliationRequest) reconciliationLoading.value = false
  }
}
function hasDifference(value: number): boolean {
  return Math.abs(Number(value)) >= 0.005
}
// Agenda bez jediné položky: zůstatek 381–385 z převodu je „k převzetí“, ne chyba odsouhlasení.
const agendaUnused = computed(() => !!reconciliation.value && reconciliation.value.accounts.length > 0
  && reconciliation.value.accounts.every(row => Number(row.items_count) === 0 && !hasDifference(row.items_balance)))
function filterByAccount(code: string) {
  account.value = code
  tab.value = 'items'
}

// Řádky 38x bez položky (rozdíl agendy proti hlavní knize).
const unallocatedAccount = ref<string | null>(null)
const unallocated = ref<AccrualUnallocated | null>(null)
const unallocatedLoading = ref(false)
async function toggleUnallocated(code: string) {
  if (unallocatedAccount.value === code) {
    unallocatedAccount.value = null
    return
  }
  unallocatedAccount.value = code
  await loadUnallocated()
}
async function loadUnallocated() {
  const code = unallocatedAccount.value
  if (!code) return
  unallocatedLoading.value = true
  try {
    const result = await accrualsApi.unallocated({ ...(asOf.value ? { as_of: asOf.value } : {}), account: code })
    if (unallocatedAccount.value === code) unallocated.value = result
  } catch (error: any) {
    unallocated.value = null
    toast.error(error?.response?.data?.error?.message || t('common.error'))
  } finally {
    unallocatedLoading.value = false
  }
}
function createFromRow(row: AccrualUnallocatedRow) {
  void router.push({
    name: 'accounting-accrual-new',
    query: accrualPrefillQuery({
      account: row.account_code,
      amount: row.unallocated,
      origin_entry_id: row.entry_id,
      origin_amount: row.unallocated,
      title: row.description || undefined,
      ...(row.source_type && row.source_id && accrualSourceRoute(row.source_type, row.source_id)
        ? { source_type: row.source_type, source_id: row.source_id } : {}),
    }),
  })
}

// Přiřazení řádku k existující položce se stejným účtem.
const assignRow = ref<AccrualUnallocatedRow | null>(null)
const assignCandidates = ref<AccrualItem[]>([])
const assignItemId = ref<number | null>(null)
const assignAmount = ref<number | null>(null)
const assigning = ref(false)
async function openAssign(row: AccrualUnallocatedRow) {
  assignRow.value = row
  assignItemId.value = null
  assignAmount.value = row.unallocated
  assignCandidates.value = []
  try {
    const result = await accrualsApi.list({ account: row.account_code, status: 'draft,active,completed,terminated', per_page: 200 })
    assignCandidates.value = result.items.filter(item => item.accrual_account_code === row.account_code)
    if (assignCandidates.value.length === 1) assignItemId.value = assignCandidates.value[0].id
  } catch (error: any) {
    toast.error(error?.response?.data?.error?.message || t('common.error'))
  }
}
async function confirmAssign() {
  const row = assignRow.value
  if (!row || !assignItemId.value || assigning.value) return
  assigning.value = true
  try {
    await accrualsApi.addLink(assignItemId.value, {
      entry_id: row.entry_id, account_code: row.account_code,
      ...(assignAmount.value && assignAmount.value > 0 ? { amount: assignAmount.value } : {}),
    })
    toast.success(t('accruals.assign_done'))
    assignRow.value = null
    void loadReconciliation()
    void loadUnallocated()
    void load()
  } catch (error: any) {
    toast.error(error?.response?.data?.error?.message || t('common.error'))
  } finally {
    assigning.value = false
  }
}

// ─── Spravuje uzávěrka (jen zobrazení) ───
const projection = ref<AccrualProjection | null>(null)
const projectionLoading = ref(false)
async function loadProjection() {
  projectionLoading.value = true
  try {
    projection.value = await accrualsApi.projection(asOf.value || undefined)
  } catch (error: any) {
    projection.value = null
    toast.error(error?.response?.data?.error?.message || t('common.error'))
  } finally {
    projectionLoading.value = false
  }
}

// ─── Export a akce ───
const bookUrl = (format: 'pdf' | 'xlsx') => accrualsApi.bookUrl({
  ...listFilter(),
  ...(sort.value ? { sort_by: sort.value.key, sort_dir: sort.value.dir as 'asc' | 'desc' } : {}),
}, format)

function reloadAll() {
  void load()
  void loadReconciliation()
  if (unallocatedAccount.value) void loadUnallocated()
  if (tab.value === 'closing') void loadProjection()
}

function kindLabel(value: string): string {
  const key = `accruals.kind.${value}`
  return t(key) === key ? value : t(key)
}
function periodText(item: { period_from: string; period_to: string }): string {
  return `${formatDate(item.period_from)} – ${formatDate(item.period_to)}`
}

watch([status, kind, method, sourceType, partnerId, withBalance, endsBy], () => {
  if (applyingQuery) return
  writeUrl()
  void load()
})
watch(asOf, () => {
  if (applyingQuery) return
  writeUrl()
  reloadAll()
})
watch(account, () => {
  if (applyingQuery) return
  if (accountTimer) clearTimeout(accountTimer)
  accountTimer = setTimeout(() => { writeUrl(); void load() }, 300)
})
watch(search, () => {
  if (applyingQuery) return
  loadRequest++
  loadingMore.value = false
  searchPending.value = true
  if (searchTimer) clearTimeout(searchTimer)
  searchTimer = setTimeout(() => { searchPending.value = false; writeUrl(); void load() }, 300)
})
watch(tab, value => {
  if (applyingQuery) return
  writeUrl()
  if (value === 'closing' && !projection.value) void loadProjection()
})
watch(() => route.query, query => {
  if (route.name !== 'accounting-accruals') return
  if (stringQuery(query) === JSON.stringify(urlQuery())) return
  applyQuery(query)
})
function applyQuery(query: LocationQuery) {
  applyingQuery = true
  readQuery(query)
  if (searchTimer) clearTimeout(searchTimer)
  searchPending.value = false
  void Promise.resolve().then(() => { applyingQuery = false })
  reloadAll()
}
onBeforeUnmount(() => {
  loadRequest++
  if (searchTimer) clearTimeout(searchTimer)
  if (accountTimer) clearTimeout(accountTimer)
})

function applyQueryToPage(query: Record<string, string>) {
  applyQuery(query)
  writeUrl()
}
const saved = useSavedFilters('accruals', { getQuery: buildQuery, applyQuery: applyQueryToPage })

const { activeIndex } = useListKeyboard({
  count: () => items.value.length,
  open: index => { const item = items.value[index]; if (item) openDetail(item) },
})

onMounted(async () => {
  await ensurePrefsLoaded()
  if (Object.keys(route.query).length === 0 && await saved.applyDefaultIfAny()) return
  applyQuery(route.query)
})
// V KeepAlive se seznam po návratu z detailu (zaúčtování, ukončení) načte znovu.
let mounted = false
onActivated(() => { if (mounted) reloadAll(); mounted = true })
</script>

<template>
  <div>
    <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('accruals.title') }}</h1>
        <p class="mt-0.5 text-sm text-neutral-500" data-test="accruals-summary">
          <span class="max-md:hidden">{{ t('accruals.subtitle') }}</span>
          <span v-if="!loading && total > 0" class="whitespace-nowrap md:ml-2"><span class="max-md:hidden">· </span>{{ t('accruals.summary_count', total) }}</span>
        </p>
      </div>
      <div class="flex flex-wrap items-center gap-2 max-md:w-full">
        <InvoiceBookMenu
          :label="t('accruals.book')"
          :url-for="bookUrl"
          :failed-message="t('accruals.book_failed')"
          :scope-text="t('accruals.book_scope')"
          :hint="t('accruals.book_hint')"
          fallback-filename="rozpis-casoveho-rozliseni"
          class="max-md:-order-1"
        />
        <RouterLink v-if="canWrite" :to="{ name: 'accounting-accruals-takeover' }" data-test="accruals-takeover"
          :class="[btnOutline('neutral'), 'whitespace-nowrap']">
          <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.upload" /></svg>
          {{ t('accruals.takeover.open') }}
        </RouterLink>
        <RouterLink v-if="canWrite" :to="{ name: 'accounting-accrual-new' }" data-test="accruals-new"
          :class="[btnFilled('primary'), 'whitespace-nowrap max-md:order-first max-md:h-10 max-md:flex-1 max-md:justify-center']">
          <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
          {{ t('accruals.new') }}
        </RouterLink>
      </div>
    </div>

    <!-- Odsouhlasení agendy s hlavní knihou po účtech 381–385 (sjednocený box jako inventarizace). -->
    <section class="mb-4 overflow-hidden rounded-lg border border-neutral-200 bg-surface shadow-sm" data-test="accruals-reconciliation">
      <header class="flex flex-wrap items-center justify-between gap-2 border-b border-neutral-200 px-4 py-2.5">
        <h2 class="text-sm font-semibold text-neutral-700">{{ t('accruals.reconciliation.title') }}</h2>
        <span v-if="reconciliation" class="text-xs text-neutral-500">{{ t('accruals.reconciliation.as_of', { date: formatDate(reconciliation.as_of) }) }}</span>
      </header>
      <p v-if="reconciliationFailed" class="px-4 py-3 text-sm text-danger-600" role="alert">{{ t('accruals.reconciliation.failed') }}</p>
      <p v-else-if="reconciliationLoading && !reconciliation" class="px-4 py-3 text-sm text-neutral-500">{{ t('common.loading') }}</p>
      <p v-else-if="reconciliation && !reconciliation.accounts.length" class="px-4 py-3 text-sm text-neutral-500">{{ t('accruals.reconciliation.empty') }}</p>
      <div v-else-if="reconciliation" class="overflow-x-auto">
        <div v-if="agendaUnused" role="note" data-test="accruals-unused-hint"
          class="flex flex-wrap items-start justify-between gap-3 border-b border-primary-100 bg-primary-50 px-4 py-3 text-sm text-primary-900">
          <div class="min-w-0 flex-1">
            <p class="font-semibold">{{ t('accruals.unused.title') }}</p>
            <p class="mt-1">{{ t('accruals.unused.hint') }}</p>
          </div>
          <RouterLink v-if="canWrite" :to="{ name: 'accounting-accruals-takeover' }" :class="[btnOutlineSm('primary'), 'whitespace-nowrap']">
            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.upload" /></svg>
            {{ t('accruals.takeover.open') }}
          </RouterLink>
        </div>
        <table class="w-full text-sm">
          <thead class="bg-neutral-50 text-xs uppercase tracking-wide text-neutral-500">
            <tr>
              <th class="px-3 py-2 text-left font-medium">{{ t('accruals.account') }}</th>
              <th class="px-3 py-2 text-right font-medium">{{ t('accruals.reconciliation.ledger') }}</th>
              <th class="px-3 py-2 text-right font-medium">{{ t('accruals.reconciliation.items') }}</th>
              <th class="px-3 py-2 text-right font-medium">{{ t('accruals.reconciliation.closing') }}</th>
              <th class="px-3 py-2 text-right font-medium">{{ t('accruals.reconciliation.difference') }}</th>
              <th class="px-3 py-2"><span class="sr-only">{{ t('common.actions') }}</span></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <template v-for="row in reconciliation.accounts" :key="row.account_code">
              <tr class="hover:bg-neutral-50" :data-test="`reconciliation-row-${row.account_code}`">
                <td class="px-3 py-2">
                  <button type="button" class="cursor-pointer font-mono text-primary-600 hover:underline" :title="t('accruals.reconciliation.filter_hint')" @click="filterByAccount(row.account_code)">{{ row.account_code }}</button>
                  <span class="ml-2 text-neutral-600">{{ row.account_name }}</span>
                  <span class="ml-1 text-xs text-neutral-400">· {{ t('accruals.reconciliation.items_count', row.items_count) }}</span>
                </td>
                <td class="whitespace-nowrap px-3 py-2 text-right font-mono">{{ formatMoney(row.ledger_balance) }}</td>
                <td class="whitespace-nowrap px-3 py-2 text-right font-mono">{{ formatMoney(row.items_balance) }}</td>
                <td class="whitespace-nowrap px-3 py-2 text-right font-mono">{{ formatMoney(row.closing_balance) }}</td>
                <td class="whitespace-nowrap px-3 py-2 text-right font-mono" :class="!hasDifference(row.difference) ? 'text-neutral-500' : agendaUnused ? 'font-semibold text-neutral-700' : 'font-semibold text-danger-600'"
                  :title="agendaUnused && hasDifference(row.difference) ? t('accruals.unused.difference_title') : undefined">{{ formatMoney(row.difference) }}</td>
                <td class="px-3 py-2 text-right">
                  <button v-if="hasDifference(row.difference)" type="button" :data-test="`unallocated-toggle-${row.account_code}`"
                    :class="[btnOutlineSm('warning'), 'whitespace-nowrap']" :aria-expanded="unallocatedAccount === row.account_code"
                    @click="toggleUnallocated(row.account_code)">
                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.search" /></svg>
                    {{ t('accruals.unallocated.toggle', { account: row.account_code }) }}
                  </button>
                </td>
              </tr>
              <tr v-if="unallocatedAccount === row.account_code">
                <td colspan="6" class="bg-neutral-50 px-3 py-3" data-test="unallocated-panel">
                  <p v-if="unallocatedLoading && !unallocated" class="text-sm text-neutral-500">{{ t('common.loading') }}</p>
                  <p v-else-if="!unallocated || !unallocated.rows.length" class="text-sm text-neutral-500">{{ t('accruals.unallocated.empty') }}</p>
                  <template v-else>
                    <p class="mb-2 text-xs text-neutral-500">{{ t('accruals.unallocated.hint') }}</p>
                    <ul class="divide-y divide-neutral-200 rounded-md border border-neutral-200 bg-surface">
                      <li v-for="line in unallocated.rows" :key="`${line.entry_id}-${line.account_code}`" class="flex flex-wrap items-center justify-between gap-2 px-3 py-2" data-test="unallocated-row">
                        <div class="min-w-0 text-sm">
                          <RouterLink :to="{ name: 'accounting-journal', query: { entry_id: String(line.entry_id) } }" class="font-mono text-primary-600 hover:underline">{{ line.document_no || `#${line.entry_id}` }}</RouterLink>
                          <span class="ml-2 text-xs text-neutral-500">{{ formatDate(line.entry_date) }}</span>
                          <span v-if="line.description" class="ml-2 text-neutral-700">{{ line.description }}</span>
                          <div class="text-xs text-neutral-500">{{ t('accruals.unallocated.amounts', { turnover: formatMoney(line.turnover), allocated: formatMoney(line.allocated) }) }}</div>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                          <strong class="whitespace-nowrap font-mono text-sm">{{ formatMoney(line.unallocated) }}</strong>
                          <template v-if="canWrite">
                            <button type="button" data-test="unallocated-assign" :class="[btnOutlineSm('primary'), 'whitespace-nowrap']" @click="openAssign(line)">
                              <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.link" /></svg>
                              {{ t('accruals.unallocated.assign') }}
                            </button>
                            <button type="button" data-test="unallocated-create" :class="[btnOutlineSm('neutral'), 'whitespace-nowrap']" @click="createFromRow(line)">
                              <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
                              {{ t('accruals.unallocated.create') }}
                            </button>
                          </template>
                        </div>
                      </li>
                    </ul>
                    <p v-if="unallocated.truncated" class="mt-2 text-xs text-neutral-500">{{ t('accruals.unallocated.truncated') }}</p>
                  </template>
                </td>
              </tr>
            </template>
          </tbody>
          <tfoot class="border-t-2 border-neutral-300 bg-neutral-50 font-semibold">
            <tr>
              <td class="px-3 py-2">{{ t('accruals.reconciliation.total') }}</td>
              <td class="whitespace-nowrap px-3 py-2 text-right font-mono">{{ formatMoney(reconciliation.totals.ledger_balance) }}</td>
              <td class="whitespace-nowrap px-3 py-2 text-right font-mono">{{ formatMoney(reconciliation.totals.items_balance) }}</td>
              <td class="whitespace-nowrap px-3 py-2 text-right font-mono">{{ formatMoney(reconciliation.totals.closing_balance) }}</td>
              <td class="whitespace-nowrap px-3 py-2 text-right font-mono" :class="hasDifference(reconciliation.totals.difference) && !agendaUnused ? 'text-danger-600' : ''" data-test="reconciliation-difference">{{ formatMoney(reconciliation.totals.difference) }}</td>
              <td></td>
            </tr>
          </tfoot>
        </table>
      </div>
    </section>

    <div role="tablist" class="mb-4 flex gap-1 overflow-x-auto border-b border-neutral-200" :aria-label="t('accruals.title')">
      <button v-for="tt in (['closing', 'items'] as const)" :key="tt" type="button" role="tab" :aria-selected="tab === tt" :data-test="`tab-${tt}`"
        :data-active="tab === tt || undefined"
        class="inline-flex cursor-pointer items-center gap-1.5 whitespace-nowrap border-b-2 px-4 py-2 text-sm transition"
        :class="tab === tt ? 'border-primary-600 font-medium text-primary-700' : 'border-transparent text-neutral-600 hover:text-neutral-900'"
        @click="tab = tt">{{ t(tt === 'items' ? 'accruals.tab_items' : 'accruals.tab_closing') }}</button>
    </div>

    <template v-if="tab === 'items'">
      <FilterBar :active-count="activeFilterCount" collapsible :chips="filterChips" @clear="clearFilter" @clear-all="clearFilters">
        <template #primary>
          <div class="relative min-w-56 flex-1">
            <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.search" /></svg>
            <input v-model="search" type="search" :placeholder="t('accruals.search')" :aria-label="t('accruals.search')"
              data-test="accruals-search" class="h-9 w-full rounded-md border border-neutral-300 bg-surface pl-9 pr-3 text-sm" />
          </div>
        </template>
        <select v-model="status" :aria-label="t('accruals.status_label')" data-test="filter-status" class="h-9 rounded-md border border-neutral-300 bg-surface px-3 text-sm">
          <option value="">{{ t('accruals.status_label') }}: {{ t('common.all') }}</option>
          <option value="draft,active">{{ t('accruals.status_open') }}</option>
          <option v-for="value in ACCRUAL_STATUSES" :key="value" :value="value">{{ t(`accruals.status.${value}`) }}</option>
        </select>
        <select v-model="kind" :aria-label="t('accruals.kind_label')" data-test="filter-kind" class="h-9 rounded-md border border-neutral-300 bg-surface px-3 text-sm">
          <option value="">{{ t('accruals.kind_label') }}: {{ t('common.all') }}</option>
          <option v-for="value in ACCRUAL_AGENDA_KINDS" :key="value" :value="value">{{ t(`accruals.kind.${value}`) }}</option>
        </select>
        <select v-model="method" :aria-label="t('accruals.method_label')" data-test="filter-method" class="h-9 rounded-md border border-neutral-300 bg-surface px-3 text-sm">
          <option value="">{{ t('accruals.method_label') }}: {{ t('common.all') }}</option>
          <option v-for="value in ACCRUAL_METHODS" :key="value" :value="value">{{ t(`accruals.method.${value}`) }}</option>
        </select>
        <select v-model="sourceType" :aria-label="t('accruals.source_label')" data-test="filter-source" class="h-9 rounded-md border border-neutral-300 bg-surface px-3 text-sm">
          <option value="">{{ t('accruals.source_label') }}: {{ t('common.all') }}</option>
          <option v-for="value in ACCRUAL_SOURCE_TYPES" :key="value" :value="value">{{ t(`accruals.source.${value}`) }}</option>
        </select>
        <input v-model="account" type="text" inputmode="numeric" maxlength="20" :placeholder="t('accruals.account')" :aria-label="t('accruals.account')"
          data-test="filter-account" class="h-9 w-28 rounded-md border border-neutral-300 bg-surface px-3 font-mono text-sm" />
        <label class="flex items-center gap-2 whitespace-nowrap text-sm text-neutral-600">
          <input v-model="withBalance" type="checkbox" data-test="filter-with-balance" class="h-4 w-4 rounded border-neutral-300 text-primary-600" />
          {{ t('accruals.with_balance') }}
        </label>
        <label class="flex items-center gap-2 text-sm text-neutral-600">
          <span class="whitespace-nowrap">{{ t('accruals.ends_by') }}</span>
          <DateInput v-model="endsBy" class="h-9 w-40 rounded-md border border-neutral-300 px-2 text-sm" />
        </label>
        <label class="flex items-center gap-2 text-sm text-neutral-600">
          <span class="whitespace-nowrap">{{ t('accruals.as_of') }}</span>
          <DateInput v-model="asOf" data-test="filter-as-of" class="h-9 w-40 rounded-md border border-neutral-300 px-2 text-sm" />
        </label>
        <template #actions>
          <SavedFiltersMenu :ctrl="saved" />
          <ColumnPicker class="hidden md:block" :ctrl="tbl" />
          <DensityToggle class="hidden md:block" :ctrl="tbl" />
        </template>
      </FilterBar>

      <div v-if="loading && !items.length" class="overflow-hidden rounded-lg border border-neutral-200 bg-surface shadow-sm">
        <TableSkeleton :rows="6" :cols="7" />
      </div>
      <EmptyState v-else-if="failed && !items.length" boxed variant="failed" @action="load()" />
      <EmptyState v-else-if="!items.length && agendaUnused" boxed icon="doc" data-test="accruals-unused-empty" :title="t('accruals.empty_title')"
        :message="t('accruals.unused.empty_hint')" :cta="canWrite ? t('accruals.takeover.open') : undefined" to="/accounting/accruals/takeover" />
      <EmptyState v-else-if="!items.length && hasActiveFilters" boxed variant="filtered" :title="t('accruals.empty')"
        :cta="t('common.empty_state.clear_filters')" @action="clearFilters" />
      <EmptyState v-else-if="!items.length" boxed icon="doc" :title="t('accruals.empty_title')" :message="t('accruals.empty_hint')"
        :cta="canWrite ? t('accruals.new') : undefined" to="/accounting/accruals/new" />
      <template v-else>
        <div ref="listBox" class="hidden overflow-auto scrollbar-slim rounded-lg border border-neutral-200 bg-surface md:block">
          <table class="w-max min-w-full text-sm table-sticky-first singleline-list-table" :class="tbl.densityClass.value">
            <thead class="sticky top-0 z-20 bg-neutral-50 text-xs uppercase tracking-wide text-neutral-500 shadow-sm">
              <tr>
                <template v-for="column in visibleColumns" :key="column.key">
                  <SortableTh v-if="SORTABLE.includes(column.key)" :label="t(column.labelKey)" :sort-key="column.key" :sort="sort"
                    :align="RIGHT_ALIGNED.includes(column.key) ? 'right' : 'left'" @toggle="onSortToggle" />
                  <th v-else scope="col" class="px-3 py-2 text-left text-xs font-medium uppercase tracking-wide text-neutral-500">{{ t(column.labelKey) }}</th>
                </template>
                <th class="w-8 px-1 py-2 text-right">
                  <button v-if="sort" type="button" data-test="reset-sort" class="inline-flex h-6 w-6 items-center justify-center rounded text-neutral-500 hover:bg-neutral-200 hover:text-neutral-800"
                    :title="t('common.reset_sort')" :aria-label="t('common.reset_sort')" @click.stop="clearSort">×</button>
                </th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="(item, index) in items" :key="item.id" class="cursor-pointer transition hover:bg-neutral-50"
                :data-row-active="index === activeIndex" data-test="accrual-row"
                @click="openDetail(item, $event)" @auxclick.prevent="openDetail(item, $event)">
                <template v-for="column in visibleColumns" :key="column.key">
                  <td v-if="column.key === 'item_no'" class="whitespace-nowrap px-3 py-2.5 font-mono text-xs">
                    <RouterLink class="row-link" :to="detailRoute(item)" @click.stop="listNav.markOpen(item.id)">{{ item.item_no }}</RouterLink>
                  </td>
                  <td v-else-if="column.key === 'title'" class="px-3 py-2.5">
                    <div class="max-w-[16rem] truncate font-medium text-neutral-900" :title="item.title">{{ item.title }}</div>
                  </td>
                  <td v-else-if="column.key === 'kind'" class="whitespace-nowrap px-3 py-2.5 text-xs">{{ kindLabel(item.kind) }}</td>
                  <td v-else-if="column.key === 'accrual_account_code'" class="whitespace-nowrap px-3 py-2.5 font-mono text-xs">{{ item.accrual_account_code }}</td>
                  <td v-else-if="column.key === 'counter_account_code'" class="whitespace-nowrap px-3 py-2.5 font-mono text-xs">{{ item.counter_account_code || '—' }}</td>
                  <td v-else-if="column.key === 'partner_name'" class="px-3 py-2.5">
                    <div class="max-w-[12rem] truncate" :class="item.partner_name ? '' : 'text-neutral-400'">{{ item.partner_name || '—' }}</div>
                  </td>
                  <td v-else-if="column.key === 'source'" class="whitespace-nowrap px-3 py-2.5 text-xs">
                    {{ t(`accruals.source.${item.source_type}`) }}<span v-if="item.source_document_no" class="ml-1 font-mono text-neutral-500">{{ item.source_document_no }}</span>
                  </td>
                  <td v-else-if="column.key === 'period_to'" class="whitespace-nowrap px-3 py-2.5 text-xs text-neutral-600">{{ periodText(item) }}</td>
                  <td v-else-if="column.key === 'method'" class="whitespace-nowrap px-3 py-2.5 text-xs">{{ t(`accruals.method.${item.method}`) }}</td>
                  <td v-else-if="column.key === 'basis_amount'" class="whitespace-nowrap px-3 py-2.5 text-right font-mono text-xs">{{ formatMoney(item.basis_amount) }}</td>
                  <td v-else-if="column.key === 'balance'" class="whitespace-nowrap px-3 py-2.5 text-right font-mono font-semibold text-neutral-900">{{ formatMoney(item.balance ?? 0) }}</td>
                  <td v-else-if="column.key === 'next_due_on'" class="whitespace-nowrap px-3 py-2.5 text-center text-xs text-neutral-600">{{ item.next_due_on ? formatDate(item.next_due_on) : '—' }}</td>
                  <td v-else-if="column.key === 'status'" class="whitespace-nowrap px-3 py-2.5 text-center">
                    <span class="rounded px-2 py-0.5 text-xs" :class="accrualStatusClass(item.status)" data-test="status-badge">{{ t(`accruals.status.${item.status}`) }}</span>
                  </td>
                </template>
                <td></td>
              </tr>
            </tbody>
            <tfoot class="border-t-2 border-neutral-300 bg-neutral-50 text-xs font-semibold">
              <tr>
                <td :colspan="visibleColumns.length + 1" class="px-3 py-2 text-right" data-test="accruals-totals">
                  {{ t('accruals.totals', { basis: formatMoney(totals.basis_amount), balance: formatMoney(totals.balance), date: loadedAsOf ? formatDate(loadedAsOf) : '' }) }}
                </td>
              </tr>
            </tfoot>
          </table>
        </div>
        <!-- Telefon: karty v jednom boxu. -->
        <div class="divide-y divide-neutral-100 rounded-lg border border-neutral-200 bg-surface md:hidden">
          <div v-for="item in items" :key="item.id" class="cursor-pointer px-3 py-3 transition hover:bg-neutral-50" data-test="accrual-card"
            @click="openDetail(item, $event)">
            <div class="flex items-baseline justify-between gap-2">
              <div class="min-w-0 truncate font-medium text-neutral-900">{{ item.title }}</div>
              <div class="whitespace-nowrap font-mono text-sm">{{ formatMoney(item.balance ?? 0) }}</div>
            </div>
            <div class="mt-1 flex items-baseline justify-between gap-2 text-xs text-neutral-500">
              <div class="min-w-0 truncate"><span class="font-mono">{{ item.item_no }}</span> · {{ kindLabel(item.kind) }} · <span class="font-mono">{{ item.accrual_account_code }}</span></div>
              <span class="rounded px-1.5 py-0.5 text-xs" :class="accrualStatusClass(item.status)">{{ t(`accruals.status.${item.status}`) }}</span>
            </div>
            <div class="mt-1 flex items-center justify-between gap-2 text-xs text-neutral-500">
              <span class="min-w-0 truncate">{{ item.partner_name || '—' }}</span>
              <span class="whitespace-nowrap">{{ periodText(item) }}</span>
            </div>
          </div>
        </div>
        <div v-if="page < pages" ref="loadMoreTarget" class="flex flex-wrap items-center justify-center gap-3 text-sm pointer-fine-hidden">
          <button type="button" class="my-4" :class="btnOutlineSm('neutral')" :disabled="loadingMore" @click="load(true)">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
            {{ t(loadingMore ? 'common.loading' : 'common.load_more') }}
          </button>
          <span>{{ items.length }} / {{ total }}</span>
        </div>
      </template>
    </template>

    <!-- Položky spravované uzávěrkou (období na řádku dokladu): jen zobrazení. -->
    <section v-else data-test="projection">
      <p class="mb-3 text-sm text-neutral-500">{{ t('accruals.projection.hint') }}</p>
      <div v-if="projectionLoading && !projection" class="py-8 text-center text-sm text-neutral-500">{{ t('common.loading') }}</div>
      <EmptyState v-else-if="!projection || !projection.items.length" boxed dense accent="neutral" icon="doc" :title="t('accruals.projection.empty')" />
      <div v-else class="overflow-hidden rounded-lg border border-neutral-200 bg-surface shadow-sm">
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead class="bg-neutral-50 text-xs uppercase tracking-wide text-neutral-500">
              <tr>
                <th class="px-3 py-2 text-left font-medium">{{ t('accruals.projection.document') }}</th>
                <th class="px-3 py-2 text-left font-medium">{{ t('accruals.title_label') }}</th>
                <th class="px-3 py-2 text-left font-medium">{{ t('accruals.kind_label') }}</th>
                <th class="px-3 py-2 text-left font-medium">{{ t('accruals.period') }}</th>
                <th class="px-3 py-2 text-right font-medium">{{ t('accruals.basis_amount') }}</th>
                <th class="px-3 py-2 text-right font-medium">{{ t('accruals.balance') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="row in projection.items" :key="`${row.source_type}-${row.source_id}-${row.source_item_id}`" data-test="projection-row">
                <td class="whitespace-nowrap px-3 py-2">
                  <span class="text-xs text-neutral-500">{{ t(`accruals.source.${row.source_type}`) }}</span>
                  <RouterLink v-if="accrualSourceRoute(row.source_type, row.source_id)" :to="accrualSourceRoute(row.source_type, row.source_id)!"
                    class="ml-1 font-mono text-primary-600 hover:underline">{{ row.document_no || `#${row.source_id}` }}</RouterLink>
                </td>
                <td class="px-3 py-2">{{ row.title }}</td>
                <td class="whitespace-nowrap px-3 py-2 text-xs">{{ kindLabel(row.kind) }}</td>
                <td class="whitespace-nowrap px-3 py-2 text-xs text-neutral-600">{{ periodText(row) }}</td>
                <td class="whitespace-nowrap px-3 py-2 text-right font-mono">{{ formatMoney(row.basis_amount) }}</td>
                <td class="whitespace-nowrap px-3 py-2 text-right font-mono font-semibold">{{ formatMoney(row.balance) }}</td>
              </tr>
            </tbody>
            <tfoot class="border-t-2 border-neutral-300 bg-neutral-50 text-xs font-semibold">
              <tr>
                <td colspan="6" class="px-3 py-2 text-right">
                  {{ t('accruals.projection.totals', { prepaid: formatMoney(projection.totals.prepaid_expense), deferred: formatMoney(projection.totals.deferred_revenue) }) }}
                </td>
              </tr>
            </tfoot>
          </table>
        </div>
        <p v-if="projection.truncated" class="border-t border-neutral-200 px-3 py-2 text-xs text-neutral-500">{{ t('accruals.projection.truncated') }}</p>
      </div>
    </section>

    <ListLoadingSpinner :show="loadingMore || (loading && items.length > 0)" />

    <Modal v-if="assignRow" :title="t('accruals.unallocated.assign_title')" width-class="max-w-lg" @close="assignRow = null">
      <form id="accrual-assign" class="space-y-4" @submit.prevent="confirmAssign">
        <p class="text-sm text-neutral-600">{{ t('accruals.unallocated.assign_hint', { account: assignRow.account_code, document: assignRow.document_no || `#${assignRow.entry_id}` }) }}</p>
        <p v-if="!assignCandidates.length" class="rounded-md border border-warning-500/40 bg-warning-50 p-3 text-sm text-warning-700">{{ t('accruals.unallocated.no_candidates') }}</p>
        <label v-else class="block text-sm font-medium">{{ t('accruals.unallocated.item') }}
          <select v-model="assignItemId" required data-test="assign-item" class="mt-1 block h-9 w-full rounded-md border border-neutral-300 bg-surface px-2">
            <option :value="null" disabled>—</option>
            <option v-for="candidate in assignCandidates" :key="candidate.id" :value="candidate.id">{{ candidate.item_no }} · {{ candidate.title }}</option>
          </select>
        </label>
        <label class="block text-sm font-medium">{{ t('accruals.unallocated.amount') }}
          <input v-model.number="assignAmount" type="number" step="0.01" min="0" data-test="assign-amount" class="mt-1 block h-9 w-full rounded-md border border-neutral-300 bg-surface px-2 text-right tabular-nums" />
        </label>
      </form>
      <template #footer>
        <div class="flex flex-wrap justify-end gap-2">
          <button type="button" :class="[btnOutline('neutral'), 'whitespace-nowrap']" @click="assignRow = null">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>
            {{ t('common.cancel') }}
          </button>
          <button type="submit" form="accrual-assign" :disabled="assigning || !assignItemId" data-test="assign-confirm" :class="[btnFilled('primary'), 'whitespace-nowrap']">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.link" /></svg>
            {{ t('accruals.unallocated.assign') }}
          </button>
        </div>
      </template>
    </Modal>
  </div>
</template>

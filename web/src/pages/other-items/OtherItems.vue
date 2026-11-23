<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter, type LocationQuery } from 'vue-router'
import { otherItemKindsApi, otherItemsApi, type OtherItem, type OtherItemKind, type OtherItemListFilter, type OtherItemRemainingTotal, type OtherItemSide } from '@/api/otherItems'
import { kindSettingLabel, otherItemKindLabel } from '@/utils/otherItemKind'
import { isOtherItemOverdue, otherItemDisplayStatus, otherItemRemainingPayment, otherItemStatusClass } from '@/utils/otherItemStatus'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { formatDate, formatMoney } from '@/composables/useFormat'
import { isCalendarIsoDate } from '@/utils/date'
import { useRowLink } from '@/composables/useRowLink'
import { useListNavigationSource } from '@/composables/useListNavigation'
import { useSupplierStore } from '@/stores/supplier'
import { useWindowRefresh } from '@/composables/useWindowRefresh'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import InvoiceBookMenu from '@/components/invoices/InvoiceBookMenu.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import FilterBar, { type FilterChip } from '@/components/ui/FilterBar.vue'
import RowActionsMenu, { type RowAction } from '@/components/ui/RowActionsMenu.vue'
import TableSkeleton from '@/components/ui/TableSkeleton.vue'
import ListLoadingSpinner from '@/components/ui/ListLoadingSpinner.vue'
import OtherItemKindsModal from '@/components/accounting/OtherItemKindsModal.vue'
import OtherItemDocumentSettingsModal from '@/components/accounting/OtherItemDocumentSettingsModal.vue'
import DateInput from '@/components/ui/DateInput.vue'
import SortableTh from '@/components/ui/SortableTh.vue'
import ColumnDragHandle from '@/components/ui/ColumnDragHandle.vue'
import ColumnPicker from '@/components/ui/ColumnPicker.vue'
import DensityToggle from '@/components/ui/DensityToggle.vue'
import SavedFiltersMenu from '@/components/ui/SavedFiltersMenu.vue'
import PostingBadge from '@/components/ui/PostingBadge.vue'
import WorkspaceDragHandle from '@/components/workspace/WorkspaceDragHandle.vue'
import type { SavedFilter } from '@/api/preferences'
import { useTablePrefs, type ColumnDef } from '@/composables/useTablePrefs'
import { useColumnDrag } from '@/composables/useColumnDrag'
import { useListKeyboard } from '@/composables/useListKeyboard'
import { useSavedFilters, savedFilterTone, type SavedFilterTone } from '@/composables/useSavedFilters'
import { ensurePrefsLoaded } from '@/composables/useUserPrefs'
import { useFillViewportHeight } from '@/composables/useFillViewportHeight'
import { useScrollLoadMore } from '@/composables/useScrollLoadMore'
import { ICONS, btnFilled, btnOutlineSm } from '@/components/ui/buttonStyles'
import { DRAFT_ACCRUAL_ERRORS, accrualErrorKey } from './accrualError'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const toast = useToast()
const navigate = useRowLink()
const supplierStore = useSupplierStore()
const loading = ref(false)
const loadingMore = ref(false)
const refreshing = ref(false)
/** Načtení selhalo: seznam drží poslední úspěšná data a nabídne opakování. */
const failed = ref(false)
const listBox = ref<HTMLElement | null>(null)
const loadMoreTarget = ref<HTMLElement | null>(null)
useFillViewportHeight(listBox)
const removingId = ref<number | null>(null)
const showKinds = ref(route.query.kinds === '1')
const showDocumentSettings = ref(false)
const items = ref<OtherItem[]>([])
const sources = ref<OtherItem[]>([])
const totals = ref<OtherItemRemainingTotal[]>([])
const total = ref(0)
const page = ref(1)
const perPage = ref(50)
const kinds = ref<OtherItemKind[]>([])

type SourceFilter = '' | 'manual' | 'payroll' | 'tax'
const side = ref<'' | OtherItemSide>('')
const status = ref<'open' | 'all'>('open')
const source = ref<SourceFilter>('')
/** Filtr druhu `strana|kód` vestavěného druhu nebo `strana|custom:<id>` vlastního — druh patří vždy jedné straně. */
const kindFilter = ref('')
const search = ref('')
const searchPending = ref(false)
const dueFrom = ref('')
const dueTo = ref('')
let searchTimer: ReturnType<typeof setTimeout> | null = null
let loadRequest = 0

const pages = computed(() => Math.max(1, Math.ceil(total.value / perPage.value)))
const canLoadMore = computed(() => !loading.value && !loadingMore.value && !refreshing.value && !searchPending.value && page.value < pages.value)
useScrollLoadMore(loadMoreTarget, () => canLoadMore.value, () => load(true))

// ─── Sloupce, hustota a řazení (vzor přijatých faktur) ───
// Řazení drží uživatelské předvolby seznamu stejně jako u faktur, ne adresa.
// Zápis má jen podvojné účetnictví a role, která smí číst deník (stejně jako detail). Účty seznam
// neukazuje: uložené účty položky nejsou účinná kontace (rozpad, profil druhu), tu ukazuje detail.
const showsJournal = computed(() => supplierStore.currentSupplier?.accounting_mode === 'double_entry' && auth.canRead('accounting'))
/** Štítek jen u živého zápisu: storno nese původní journal_entry_id, ale zaúčtované už není. */
function showsPostingBadge(item: OtherItem): boolean {
  return showsJournal.value && item.status === 'posted' && !!item.journal_entry_id
}
/** Vestavěný druh nese směr v názvu, vlastní druh ne: u něj směr doplnit, ať je pohledávka od závazku poznat. */
function kindLabel(item: OtherItem): string {
  const label = otherItemKindLabel(t, item)
  return item.kind_name ? `${t(`other_items.side.${item.side}`)} · ${label}` : label
}
const COLUMNS: ColumnDef[] = [
  { key: 'document_no', labelKey: 'other_items.document_no', required: true },
  { key: 'title', labelKey: 'other_items.item', required: true },
  { key: 'side', labelKey: 'other_items.side_label', defaultHidden: true },
  { key: 'kind', labelKey: 'other_items.kind_label' },
  { key: 'partner_name', labelKey: 'other_items.partner' },
  { key: 'issued_on', labelKey: 'other_items.issued_on', defaultHidden: true },
  { key: 'accounting_on', labelKey: 'other_items.accounting_on', defaultHidden: true },
  { key: 'due_on', labelKey: 'other_items.due_on' },
  { key: 'amount', labelKey: 'other_items.amount', required: true },
  { key: 'paid_amount', labelKey: 'other_items.paid_amount', defaultHidden: true },
  { key: 'remaining_amount', labelKey: 'other_items.remaining' },
  { key: 'status', labelKey: 'other_items.status_label' },
  { key: 'variable_symbol', labelKey: 'other_items.variable_symbol', defaultHidden: true },
  { key: 'documents', labelKey: 'other_items.col_documents', defaultHidden: true, available: () => auth.canRead('documents') },
  { key: 'note', labelKey: 'other_items.note', defaultHidden: true },
  { key: 'posting', labelKey: 'other_items.col_posting', available: () => showsJournal.value },
]
/** Sloupce, podle kterých umí řadit API (OtherItemSort). */
// Stav ne: výpis ukazuje odvozený stav (uhrazeno, částečně), API řadí podle uloženého.
const SORTABLE = ['document_no', 'title', 'partner_name', 'due_on', 'amount', 'remaining_amount']
const RIGHT_ALIGNED = ['amount', 'paid_amount', 'remaining_amount']
const tbl = useTablePrefs('other_items', COLUMNS)
const columnDrag = useColumnDrag(tbl)
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

// ─── Filtry v URL ───
// Filtry drží query, takže odkaz, obnova stránky i návrat z detailu ukážou
// stejný výběr. Klik na položku menu (prázdné query) vrátí výchozí stav.
function str(value: LocationQuery[string]): string {
  return typeof value === 'string' ? value : ''
}
function readQuery(q: LocationQuery) {
  const qSide = str(q.side)
  side.value = qSide === 'receivable' || qSide === 'payable' ? qSide : ''
  status.value = str(q.status) === 'all' ? 'all' : 'open'
  const qSource = str(q.source)
  source.value = (['manual', 'payroll', 'tax'] as const).find(value => value === qSource) ?? ''
  const qKind = str(q.kind)
  // Druh patří jedné straně; ručně upravený odkaz s druhem druhé strany se nepoužije.
  // Id vlastního druhu nejvýš 15 číslic: delší číslo by v Number ztratilo přesnost a server ho odmítl.
  kindFilter.value = /^(receivable|payable)\|[a-z_]+(?::[1-9]\d{0,14})?$/.test(qKind)
    && (!side.value || qKind.startsWith(`${side.value}|`)) ? qKind : ''
  search.value = str(q.q)
  // Neplatné datum nebo obrácené rozmezí z ručně upravené adresy se vynechá (server by ho odmítl 422).
  const qFrom = str(q.from)
  const qTo = str(q.to)
  const validFrom = isCalendarIsoDate(qFrom)
  const validTo = isCalendarIsoDate(qTo)
  const reversed = validFrom && validTo && qFrom > qTo
  dueFrom.value = validFrom && !reversed ? qFrom : ''
  dueTo.value = validTo && !reversed ? qTo : ''
}
function buildQuery(): Record<string, string> {
  const q: Record<string, string> = {}
  if (side.value) q.side = side.value
  if (status.value !== 'open') q.status = status.value
  if (source.value) q.source = source.value
  if (kindFilter.value) q.kind = kindFilter.value
  if (search.value.trim()) q.q = search.value.trim()
  if (dueFrom.value) q.from = dueFrom.value
  if (dueTo.value) q.to = dueTo.value
  return q
}
/** Adresa = filtry + otevřený číselník druhů; uložený pohled nese jen filtry. */
function urlQuery(): Record<string, string> {
  return showKinds.value ? { ...buildQuery(), kinds: '1' } : buildQuery()
}
let applyingQuery = false
/**
 * Query, která stránka sama zapsala a jejichž `replace` se ještě nedořešil. Watcher URL je nesmí
 * vzít za navigaci zvenku a nový zápis se porovnává s posledním z nich, ne s dosavadní URL.
 */
const ownQueries: string[] = []
function stringQuery(query: LocationQuery): string {
  return JSON.stringify(Object.fromEntries(Object.entries(query).filter(([, value]) => typeof value === 'string')))
}
function syncUrl() {
  if (!applyingQuery) writeUrl()
}
function writeUrl() {
  const next = urlQuery()
  const target = ownQueries.length ? ownQueries[ownQueries.length - 1] : stringQuery(route.query)
  if (JSON.stringify(next) === target) return
  const key = JSON.stringify(next)
  ownQueries.push(key)
  // Zrušená nebo duplicitní navigace URL nezmění a watcher záznam neodebere; nechat ho viset by
  // později spolklo navigaci zvenku (Zpět) na stejnou adresu.
  void Promise.resolve(router.replace({ query: next })).catch(() => undefined).finally(() => {
    const index = ownQueries.indexOf(key)
    if (index !== -1) ownQueries.splice(index, 1)
  })
}
function closeKinds() {
  showKinds.value = false
  syncUrl()
}

function onListScroll(event: Event) {
  const el = event.currentTarget as HTMLElement
  if (canLoadMore.value && el.scrollHeight - el.scrollTop - el.clientHeight < 240) void load(true)
}
const CUSTOM_PREFIX = 'custom:'
const BUILTIN_KINDS = ['rent', 'deposit', 'loan', 'insurance', 'fee', 'claim', 'other'] as const
// Druhy po stranách se stejnými názvy jako v editoru; se zvoleným směrem jen jeho strana.
// Skryté druhy se nabízejí také, aby šly dohledat jejich starší doklady.
const kindGroups = computed(() => (side.value ? [side.value] : (['payable', 'receivable'] as OtherItemSide[])).map(groupSide => ({
  side: groupSide,
  options: [
    ...BUILTIN_KINDS.map(code => ({ value: `${groupSide}|${code}`, label: t(`other_items.kind_by_side.${groupSide}.${code}`) })),
    ...kinds.value.filter(kind => !kind.builtin && kind.side === groupSide)
      .map(kind => ({ value: `${groupSide}|${CUSTOM_PREFIX}${kind.id}`, label: kindSettingLabel(t, kind) })),
  ],
})))
const kindQuery = computed(() => {
  if (!kindFilter.value) return {}
  const [kindSide, kind] = kindFilter.value.split('|') as [OtherItemSide, string]
  return {
    side: kindSide,
    ...(kind.startsWith(CUSTOM_PREFIX) ? { kind_id: Number(kind.slice(CUSTOM_PREFIX.length)) } : { kind }),
  }
})
const kindFilterLabel = computed(() => kindGroups.value.flatMap(group => group.options)
  .find(option => option.value === kindFilter.value)?.label ?? '')
const visibleItems = computed(() => source.value === '' || source.value === 'manual' ? items.value : [])
const visibleSources = computed(() => source.value === '' ? sources.value : sources.value.filter(item =>
  source.value === 'tax'
    ? ['tax_advance', 'vat_forecast', 'dppo_forecast'].includes(item.source_kind)
    : ['payroll', 'payroll_forecast'].includes(item.source_kind) && source.value === 'payroll',
))
const visibleTotals = computed(() => source.value === '' || source.value === 'manual' ? totals.value : [])
const totalsBySide = computed(() => (['receivable', 'payable'] as OtherItemSide[])
  .map(totalSide => ({ side: totalSide, rows: visibleTotals.value.filter(row => row.side === totalSide) }))
  .filter(group => group.rows.length > 0))

// ─── Lišta filtrů ───
const activeFilterCount = computed(() => [side.value, status.value !== 'open', source.value, kindFilter.value, dueFrom.value, dueTo.value]
  .filter(Boolean).length)
const hasActiveFilters = computed(() => activeFilterCount.value > 0 || !!search.value.trim())
const filterChips = computed<FilterChip[]>(() => {
  const chips: FilterChip[] = []
  if (side.value) chips.push({ key: 'side', label: t('other_items.side_label'), value: t(`other_items.side.${side.value}`) })
  if (status.value !== 'open') chips.push({ key: 'status', label: t('other_items.status_label'), value: t('common.all') })
  if (kindFilter.value) chips.push({ key: 'kind', label: t('other_items.kind_label'), value: kindFilterLabel.value })
  if (source.value) chips.push({ key: 'source', label: t('other_items.source_label'), value: t(`other_items.source.${source.value}`) })
  if (dueFrom.value) chips.push({ key: 'from', label: t('other_items.due_from'), value: formatDate(dueFrom.value) })
  if (dueTo.value) chips.push({ key: 'to', label: t('other_items.due_to'), value: formatDate(dueTo.value) })
  return chips
})
function clearFilter(key: string) {
  if (key === 'side') side.value = ''
  if (key === 'status') status.value = 'open'
  if (key === 'kind') kindFilter.value = ''
  if (key === 'source') source.value = ''
  if (key === 'from') dueFrom.value = ''
  if (key === 'to') dueTo.value = ''
}
/** Jedno načtení: bez toho by změna filtrů načetla hned a vymazané hledání ještě jednou po prodlevě. */
function clearFilters() {
  applyingQuery = true
  if (searchTimer) clearTimeout(searchTimer)
  searchPending.value = false
  side.value = ''
  status.value = 'open'
  kindFilter.value = ''
  source.value = ''
  dueFrom.value = ''
  dueTo.value = ''
  search.value = ''
  void Promise.resolve().then(() => { applyingQuery = false })
  writeUrl()
  void load()
}

// Nová položka je samostatné plné tlačítko za lištou (vzor přijatých faktur), ostatní akce v liště.
const newItemRoute = computed(() => ({ name: 'other-item-new', query: side.value ? { side: side.value } : {} }))
const actions = computed<ActionItem[]>(() => [
  { key: 'kinds', label: t('other_items.kinds.title'), icon: 'tag', variant: 'neutral', run: () => { showKinds.value = true } },
  { key: 'documents', label: t('other_items.documents_settings.title'), icon: 'folderOpen', variant: 'neutral',
    show: auth.canRead('documents'), run: () => { showDocumentSettings.value = true } },
  { key: 'renumber', label: t('common.renumber_by_date'), icon: 'cycle', tier: 'overflow', variant: 'warning',
    show: auth.canWrite('other_items') && !auth.isClientRole,
    to: { path: '/purchase-invoices/renumber', query: { agenda: side.value === 'payable' ? 'other_payable' : 'other_receivable' } } },
])

function internalSourceUrl(item: OtherItem): string | null {
  const url = item.source_url
  return url && url.startsWith('/') && !url.startsWith('//') ? url : null
}

function detailRoute(item: OtherItem) {
  return { name: 'other-item-detail', params: { id: item.source_id } }
}

// Detail si pamatuje výběr seznamu: „Zpět“ vrátí stejné filtry a šipky listují v něm (vzor přijatých faktur).
// Parametry a adresa posledního ÚSPĚŠNÉHO načtení: řádky na obrazovce pocházejí z něj, ne z filtru,
// který se právě načítá (vzor loadedParams přijatých faktur).
let loadedParams: Record<string, unknown> = {}
/** Adresa výpisu s filtry, které se právě načítají; URL se zapisuje asynchronně a číselník druhů se nepamatuje. */
function listPath(): string {
  return router.resolve({ path: route.path, query: buildQuery() }).fullPath
}
let loadedPath = route.fullPath
const listNav = useListNavigationSource('other-items', () => ({
  ids: visibleItems.value.map(item => Number(item.source_id)), page: page.value, pages: pages.value, params: loadedParams,
}), () => loadedPath, () => supplierStore.currentSupplierId)

function openDetail(item: OtherItem, event?: MouseEvent) {
  listNav.markOpen(Number(item.source_id))
  navigate(detailRoute(item), event)
}

function statusLabel(value: string): string {
  const key = `other_items.status.${value}`
  return t(key) === key ? value.replaceAll('_', ' ') : t(key)
}

function sourceLabel(value: string): string {
  const key = `other_items.source.${value}`
  return t(key) === key ? value.replaceAll('_', ' ') : t(key)
}

function rowActions(item: OtherItem): RowAction[] {
  const draft = item.status === 'draft' && auth.canWrite('other_items')
  return [
    { key: 'edit', label: t('common.edit'), icon: 'edit', show: draft, to: { name: 'other-item-edit', params: { id: item.source_id } } },
    { key: 'delete', label: t('common.delete'), icon: 'trash', variant: 'danger', show: draft,
      disabled: removingId.value !== null, run: () => void removeItem(item) },
  ]
}

/** Výběr výpisu bez stránkování a řazení; tentýž výběr (a zvolené řazení) dostane kniha. */
function listFilter(): OtherItemListFilter {
  return {
    ...(side.value ? { side: side.value } : {}), status: status.value, ...kindQuery.value,
    ...(search.value.trim() ? { q: search.value.trim() } : {}),
    ...(dueFrom.value ? { from: dueFrom.value } : {}),
    ...(dueTo.value ? { to: dueTo.value } : {}),
  }
}
const bookUrl = (format: 'pdf' | 'xlsx') => otherItemsApi.bookUrl({
  ...listFilter(),
  ...(sort.value ? { sort_by: sort.value.key, sort_dir: sort.value.dir } : {}),
}, format)
/** Kniha obsahuje jen ruční položky; při zdroji Mzdy nebo Daně je výpis nemá, kniha by byla prázdná. */
const showsBook = computed(() => auth.canRead('other_items') && source.value !== 'payroll' && source.value !== 'tax')

function listParams(nextPage: number, pageSize?: number) {
  return {
    ...listFilter(),
    page: nextPage,
    ...(pageSize ? { per_page: pageSize } : {}),
    ...(sort.value ? { sort_by: sort.value.key, sort_dir: sort.value.dir } : {}),
  }
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
  const requestedPath = listPath()
  try {
    const result = await otherItemsApi.list(listParams(nextPage))
    if (request !== loadRequest) return
    items.value = append ? [...items.value, ...result.items] : result.items
    page.value = nextPage
    if (!append) {
      sources.value = result.sources || []
      totals.value = result.totals || []
      loadedParams = requestedParams
      loadedPath = requestedPath
    }
    total.value = result.total
    perPage.value = result.per_page || 50
    failed.value = false
  } catch (error: any) {
    if (request !== loadRequest) return
    // Data z posledního úspěšného načtení zůstávají; prázdný seznam by tvrdil, že nic není.
    if (!append) failed.value = true
    toast.error(error?.response?.data?.error?.message || t('common.error'))
  } finally {
    if (request === loadRequest) {
      loading.value = false
      loadingMore.value = false
    }
  }
}

/**
 * Tichá obnova po změně v jiném okně nebo po návratu na stránku: znovu načte všechny
 * už zobrazené stránky, obsah zůstane na obrazovce a jen se přepíše.
 */
const REFRESH_PAGE_LIMIT = 5
async function refreshLoadedPages() {
  // Běžící načtení má přednost. Obnova si čítač jen přečte (nezvyšuje ho), jinak by
  // načtení, které běželo před ní, nikdy nevrátilo `loading` zpět.
  if (loading.value || loadingMore.value || refreshing.value) return
  const request = loadRequest
  refreshing.value = true
  try {
    // Jako u faktur nejvýš pět stránek; další se dočtou posunem seznamu.
    const loaded = Math.min(REFRESH_PAGE_LIMIT, Math.max(1, page.value))
    const results = await Promise.all(Array.from({ length: loaded }, (_, index) => otherItemsApi.list(listParams(index + 1, perPage.value))))
    if (request !== loadRequest) return
    const { page: _page, ...refreshedParams } = listParams(1)
    loadedParams = refreshedParams
    loadedPath = listPath()
    page.value = loaded
    items.value = results.flatMap(result => result.items)
    sources.value = results[0].sources || []
    totals.value = results[0].totals || []
    total.value = results[0].total
    failed.value = false
  } finally {
    refreshing.value = false
  }
}
// Stránka v <KeepAlive> neběží (RouterView panelu ho nemá), návrat ji připojí znovu; o změnách
// v jiném okně se dozví přes useWindowRefresh. Běžící obnova změnu nevyřídí, počká.
useWindowRefresh(refreshLoadedPages,
  () => !loading.value && !loadingMore.value && !refreshing.value && removingId.value === null)

function reloadFirstPage() {
  syncUrl()
  void load()
}

async function removeItem(item: OtherItem) {
  const id = Number(item.source_id)
  if (item.status !== 'draft' || !auth.canWrite('other_items') || removingId.value !== null
    || !window.confirm(t('other_items.confirm_delete'))) return
  removingId.value = id
  try {
    await otherItemsApi.remove(id)
    toast.success(t('common.deleted'))
    await load()
  } catch (error: any) {
    const key = accrualErrorKey(error, DRAFT_ACCRUAL_ERRORS)
    toast.error(key ? t(key) : error?.response?.data?.error?.message || t('common.error'))
  } finally {
    removingId.value = null
  }
}

// Druh druhé strany po změně směru nedává smysl.
watch(side, () => {
  if (side.value && kindFilter.value && !kindFilter.value.startsWith(`${side.value}|`)) kindFilter.value = ''
})
watch([side, status, kindFilter, dueFrom, dueTo], () => { if (!applyingQuery) reloadFirstPage() })
// Zdroj filtruje jen zobrazení načtených dat, nový dotaz nepotřebuje.
watch(source, () => syncUrl())
watch(search, () => {
  if (applyingQuery) return
  loadRequest++
  loadingMore.value = false
  searchPending.value = true
  if (searchTimer) clearTimeout(searchTimer)
  searchTimer = setTimeout(() => { searchPending.value = false; reloadFirstPage() }, 300)
})
// Zpět/vpřed v historii nebo klik na položku menu: stav převezme z URL.
watch(() => route.query, query => {
  if (route.name !== 'other-items') return
  const current = stringQuery(query)
  const own = ownQueries.indexOf(current)
  if (own !== -1) { ownQueries.splice(0, own + 1); return }
  if (current === JSON.stringify(urlQuery())) return
  applyQuery(query)
})
function applyQuery(query: LocationQuery) {
  applyingQuery = true
  readQuery(query)
  if (searchTimer) clearTimeout(searchTimer)
  searchPending.value = false
  void Promise.resolve().then(() => { applyingQuery = false })
  void load()
}
onBeforeUnmount(() => { loadRequest++; if (searchTimer) clearTimeout(searchTimer) })
async function loadKinds() {
  try {
    kinds.value = await otherItemKindsApi.list()
  } catch {
    kinds.value = []
  }
}
// Nový nebo přejmenovaný druh se má hned objevit i ve filtru.
function onKindsChanged() {
  void loadKinds()
  reloadFirstPage()
}

// ─── Uložené pohledy (vzor přijatých faktur) ───
function applyQueryToPage(query: Record<string, string>) {
  applyQuery(query)
  writeUrl()
}
const saved = useSavedFilters('other_items', { getQuery: buildQuery, applyQuery: applyQueryToPage })
const VIEW_DOT_CLASS: Record<SavedFilterTone, string> = {
  danger: 'bg-danger-500',
  warning: 'bg-warning-500',
  success: 'bg-success-500',
  neutral: 'bg-neutral-300',
}
function viewDotClass(filter: SavedFilter): string {
  return VIEW_DOT_CLASS[savedFilterTone(filter.payload)]
}
function onViewClick(filter: SavedFilter) {
  if (saved.activeId.value === filter.id) saved.clearActive()
  else saved.apply(filter)
}

// ─── Klávesnice: j/k pohyb, Enter otevře (vzor přijatých faktur) ───
const { activeIndex } = useListKeyboard({
  count: () => visibleItems.value.length,
  open: index => { const item = visibleItems.value[index]; if (item) openDetail(item) },
})

onMounted(async () => {
  void loadKinds()
  // Řazení je v předvolbách: první načtení počká na ně, jinak by šlo dvakrát.
  await ensurePrefsLoaded()
  if (Object.keys(route.query).length === 0 && await saved.applyDefaultIfAny()) return
  applyQuery(route.query)
})
</script>

<template>
  <div>
    <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('other_items.title') }}</h1>
        <p class="mt-0.5 text-sm text-neutral-500" data-test="other-items-summary">
          <span class="max-md:hidden">{{ t('other_items.subtitle') }}</span>
          <template v-if="!loading && total > 0 && source !== 'payroll' && source !== 'tax'">
            <span class="whitespace-nowrap md:ml-2"><span class="max-md:hidden">· </span>{{ t('other_items.summary_count', total) }}</span>
            <span v-if="items.length < total" class="ml-2 whitespace-nowrap">· {{ t('common.loaded_count', { loaded: items.length, total }) }}</span>
          </template>
        </p>
      </div>
      <div class="flex flex-wrap items-center gap-2 max-md:w-full">
        <InvoiceBookMenu
          v-if="showsBook"
          :label="t('other_items.book')"
          :url-for="bookUrl"
          :failed-message="t('other_items.book_failed')"
          :scope-text="t('other_items.book_scope')"
          :hint="t('other_items.book_hint')"
          fallback-filename="kniha-ostatnich-pohledavek-a-zavazku"
          class="max-md:-order-1"
        />
        <ActionBar :actions="actions" />
        <RouterLink v-if="auth.canWrite('other_items')" :to="newItemRoute" data-test="other-items-new"
          :class="[btnFilled('primary'), 'whitespace-nowrap max-md:order-first max-md:h-10 max-md:flex-1 max-md:justify-center']">
          <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
          {{ t('other_items.new') }}
        </RouterLink>
      </div>
    </div>

    <!-- Řádek pohledů: uložené filtry jako záložky (vzor přijatých faktur); bez pohledu se nekreslí. -->
    <div v-if="saved.filters.value.length" role="tablist" :aria-label="t('common.saved_views')"
      class="mb-3 flex items-center gap-1.5 overflow-x-auto pb-1" data-test="saved-views">
      <button type="button" role="tab" :aria-selected="saved.activeId.value === null"
        class="inline-flex h-8 shrink-0 cursor-pointer items-center rounded-full border px-3 text-sm transition-colors"
        :class="saved.activeId.value === null ? 'border-primary-300 bg-primary-50 font-medium text-primary-700' : 'border-neutral-200 text-neutral-600 hover:bg-neutral-50'"
        @click="saved.clearActive()">{{ t('common.saved_view_all') }}</button>
      <button v-for="filter in saved.filters.value" :key="filter.id" type="button" role="tab"
        :aria-selected="saved.activeId.value === filter.id"
        :title="saved.activeId.value === filter.id ? t('common.saved_view_clear') : filter.name"
        class="inline-flex h-8 max-w-56 shrink-0 cursor-pointer items-center gap-1.5 rounded-full border px-3 text-sm transition-colors"
        :class="saved.activeId.value === filter.id ? 'border-primary-300 bg-primary-50 font-medium text-primary-700' : 'border-neutral-200 text-neutral-600 hover:bg-neutral-50'"
        @click="onViewClick(filter)">
        <span class="h-1.5 w-1.5 shrink-0 rounded-full" :class="viewDotClass(filter)" aria-hidden="true"></span>
        <span class="truncate">{{ filter.name }}</span>
      </button>
    </div>

    <!-- Bez `collapsible`: šest filtrů se na desktopu vejde do lišty, na telefonu jsou za tlačítkem Filtry. -->
    <FilterBar :active-count="activeFilterCount" collapsible :chips="filterChips" @clear="clearFilter" @clear-all="clearFilters">
      <template #primary>
        <div class="relative min-w-56 flex-1">
          <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M17 11a6 6 0 1 1-12 0 6 6 0 0 1 12 0z" />
          </svg>
          <input v-model="search" type="search" :placeholder="t('other_items.search')" :aria-label="t('other_items.search')"
            data-test="other-items-search" class="h-9 w-full rounded-md border border-neutral-300 bg-surface pl-9 pr-3 text-sm" />
        </div>
      </template>
      <select v-model="side" :title="t('other_items.side_label')" :aria-label="t('other_items.side_label')" data-test="filter-side"
        class="h-9 rounded-md border border-neutral-300 bg-surface px-3 text-sm">
        <option value="">{{ t('other_items.side_label') }}: {{ t('common.all') }}</option>
        <option value="receivable">{{ t('other_items.side.receivable') }}</option>
        <option value="payable">{{ t('other_items.side.payable') }}</option>
      </select>
      <select v-model="status" :title="t('other_items.status_label')" :aria-label="t('other_items.status_label')" data-test="filter-status"
        class="h-9 rounded-md border border-neutral-300 bg-surface px-3 text-sm">
        <option value="open">{{ t('other_items.status_label') }}: {{ t('other_items.open') }}</option>
        <option value="all">{{ t('other_items.status_label') }}: {{ t('common.all') }}</option>
      </select>
      <select v-model="kindFilter" :title="t('other_items.kind_label')" :aria-label="t('other_items.kind_label')" data-test="filter-kind"
        class="h-9 rounded-md border border-neutral-300 bg-surface px-3 text-sm">
        <option value="">{{ t('other_items.kind_label') }}: {{ t('common.all') }}</option>
        <optgroup v-for="group in kindGroups" :key="group.side" :label="t(`other_items.side.${group.side}`)">
          <option v-for="option in group.options" :key="option.value" :value="option.value">{{ option.label }}</option>
        </optgroup>
      </select>
      <select v-model="source" :title="t('other_items.source_label')" :aria-label="t('other_items.source_label')" data-test="filter-source"
        class="h-9 rounded-md border border-neutral-300 bg-surface px-3 text-sm">
        <option value="">{{ t('other_items.source_label') }}: {{ t('common.all') }}</option>
        <option value="manual">{{ t('other_items.source.manual') }}</option>
        <option value="payroll">{{ t('other_items.source.payroll') }}</option>
        <option value="tax">{{ t('other_items.source.tax') }}</option>
      </select>
      <label class="flex items-center gap-2 text-sm text-neutral-600">
        <span class="whitespace-nowrap">{{ t('other_items.due_from') }}</span>
        <DateInput v-model="dueFrom" class="h-9 w-40 rounded-md border border-neutral-300 px-2 text-sm" />
      </label>
      <label class="flex items-center gap-2 text-sm text-neutral-600">
        <span class="whitespace-nowrap">{{ t('other_items.due_to') }}</span>
        <DateInput v-model="dueTo" class="h-9 w-40 rounded-md border border-neutral-300 px-2 text-sm" />
      </label>
      <template #actions>
        <SavedFiltersMenu :ctrl="saved" />
        <!-- Karty na telefonu mají pevný obsah, sloupce ani hustota je nemění. -->
        <ColumnPicker class="hidden md:block" :ctrl="tbl" reorderable />
        <DensityToggle class="hidden md:block" :ctrl="tbl" />
      </template>
    </FilterBar>
    <!-- Výchozí okno splatnosti platí na serveru; bez téhle věty by výběr vypadal jako úplný.
         Je mimo sbalitelné filtry, aby zůstala vidět i se sbalenou lištou. -->
    <p v-if="!dueFrom && !dueTo && !search.trim()" class="-mt-2 mb-4 px-1 text-xs text-neutral-500" data-test="due-window-hint">
      {{ t(status === 'open' ? 'other_items.window_hint_open' : 'other_items.window_hint') }}
    </p>

    <div v-if="loading && !items.length && !sources.length" class="overflow-hidden rounded-lg border border-neutral-200 bg-surface shadow-sm">
      <TableSkeleton :rows="6" :cols="7" />
    </div>
    <EmptyState v-else-if="failed && !items.length && !sources.length" boxed variant="failed" @action="load()" />
    <EmptyState v-else-if="!visibleItems.length && !visibleSources.length && hasActiveFilters" boxed variant="filtered"
      :title="t('other_items.empty')" :cta="t('common.empty_state.clear_filters')" @action="clearFilters" />
    <EmptyState v-else-if="!visibleItems.length && !visibleSources.length" boxed icon="doc" :title="t('other_items.empty_title')"
      :message="t('other_items.empty_hint')" :cta="auth.canWrite('other_items') ? t('other_items.new') : undefined" to="/other-items/new" />
    <template v-else>
      <p v-if="failed" role="alert" class="mb-3 flex flex-wrap items-center gap-2 rounded-md border border-danger-500/40 bg-danger-50 p-3 text-sm text-danger-600">
        {{ t('other_items.load_failed_stale') }}
        <button type="button" :class="btnOutlineSm('danger')" @click="load()">
          <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.cycle" /></svg>
          {{ t('common.retry') }}
        </button>
      </p>
      <section v-if="source !== 'payroll' && source !== 'tax'">
        <header class="mb-2 flex flex-wrap items-center justify-between gap-x-4 gap-y-1">
          <h2 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('other_items.manual_section') }}</h2>
          <div v-if="totalsBySide.length" class="flex flex-wrap items-center justify-end gap-x-4 gap-y-0.5 text-xs" data-test="other-items-totals" :title="t('other_items.totals_hint')">
            <span v-for="group in totalsBySide" :key="group.side" class="font-mono">
              <span class="text-neutral-500">{{ t(`other_items.totals_by_side.${group.side}`) }}:</span>
              <span v-for="row in group.rows" :key="row.currency" class="ml-1.5 font-semibold text-neutral-900">{{ formatMoney(row.remaining_amount, row.currency) }}</span>
            </span>
            <span class="text-neutral-500" data-test="other-items-totals-scope">{{ t('other_items.totals_scope') }}</span>
          </div>
        </header>
        <EmptyState v-if="!visibleItems.length" boxed dense accent="neutral" icon="doc" :title="t('other_items.no_manual')" />
        <div v-if="visibleItems.length" ref="listBox" class="hidden overflow-auto scrollbar-slim rounded-lg border border-neutral-200 bg-surface md:block" @scroll.passive="onListScroll">
          <table v-column-labels="tbl" class="w-max min-w-full text-sm table-sticky-first singleline-list-table list-stacked-controls" :class="tbl.densityClass.value">
            <thead class="sticky top-0 z-20 bg-neutral-50 text-xs uppercase tracking-wide text-neutral-500 shadow-sm">
              <tr>
                <th class="w-8 px-2 py-2"><span class="sr-only">{{ t('workspace.drag_row') }}</span></th>
                <template v-for="column in visibleColumns" :key="column.key">
                  <SortableTh v-if="SORTABLE.includes(column.key)" v-bind="columnDrag.headerAttrs(column.key)" reorderable
                    :label="t(column.labelKey)" :sort-key="column.key" :sort="sort"
                    :align="RIGHT_ALIGNED.includes(column.key) ? 'right' : 'left'" @toggle="onSortToggle" />
                  <th v-else scope="col" v-bind="columnDrag.headerAttrs(column.key)"
                    class="py-2 text-xs font-medium uppercase tracking-wide text-neutral-500"
                    :class="RIGHT_ALIGNED.includes(column.key) ? 'px-3 text-right' : column.key === 'posting' ? 'px-2 text-center' : 'px-3 text-left'"><ColumnDragHandle /> {{ t(column.labelKey) }}</th>
                </template>
                <th class="w-8 px-1 py-2 text-right">
                  <button v-if="sort" type="button" data-test="reset-sort" class="inline-flex h-6 w-6 items-center justify-center rounded text-neutral-500 hover:bg-neutral-200 hover:text-neutral-800"
                    :title="t('common.reset_sort')" :aria-label="t('common.reset_sort')" @click.stop="clearSort">×</button>
                  <span v-else class="sr-only">{{ t('common.actions') }}</span>
                </th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="(item, index) in visibleItems" :key="item.id" draggable="true" :data-workspace-route="`/other-items/${item.source_id}`"
                class="cursor-pointer transition hover:bg-neutral-50" :class="{ 'row-flagged': isOtherItemOverdue(item) }"
                :data-row-active="index === activeIndex"
                data-test="other-item-row" @click="openDetail(item, $event)" @auxclick.prevent="openDetail(item, $event)">
                <td class="px-2 py-2.5 text-center" @click.stop><WorkspaceDragHandle /></td>
                <template v-for="column in visibleColumns" :key="column.key">
                  <td v-if="column.key === 'document_no'" class="whitespace-nowrap px-3 py-2.5 font-mono text-xs">
                    <RouterLink class="row-link" :to="detailRoute(item)" @click.stop="listNav.markOpen(Number(item.source_id))" @auxclick.stop="listNav.markOpen(Number(item.source_id))">
                      <span v-if="item.document_no">{{ item.document_no }}</span>
                      <span v-else class="text-neutral-400">{{ t('other_items.status.draft') }}</span>
                    </RouterLink>
                  </td>
                  <td v-else-if="column.key === 'title'" class="px-3 py-2.5">
                    <div class="max-w-[13rem] truncate font-medium text-neutral-900" :title="item.title">{{ item.title }}</div>
                  </td>
                  <td v-else-if="column.key === 'side'" class="whitespace-nowrap px-3 py-2.5 text-xs">{{ t(`other_items.side.${item.side}`) }}</td>
                  <td v-else-if="column.key === 'kind'" class="px-3 py-2.5 text-xs"><span class="inline-block max-w-32 truncate align-middle" :title="kindLabel(item)" data-test="kind-cell">{{ kindLabel(item) }}</span></td>
                  <td v-else-if="column.key === 'partner_name'" class="px-3 py-2.5">
                    <div class="max-w-[10rem] truncate" :class="item.partner_name ? '' : 'text-neutral-400'" :title="item.partner_name || undefined">{{ item.partner_name || t('other_items.no_partner') }}</div>
                  </td>
                  <td v-else-if="column.key === 'issued_on'" class="whitespace-nowrap px-3 py-2.5 text-center text-xs text-neutral-600">{{ item.issued_on ? formatDate(item.issued_on) : '—' }}</td>
                  <td v-else-if="column.key === 'accounting_on'" class="whitespace-nowrap px-3 py-2.5 text-center text-xs text-neutral-600">{{ item.accounting_on ? formatDate(item.accounting_on) : '—' }}</td>
                  <td v-else-if="column.key === 'due_on'" class="whitespace-nowrap px-3 py-2.5 text-center text-xs" :class="isOtherItemOverdue(item) ? 'font-medium text-danger-500' : 'text-neutral-600'">{{ item.due_on ? formatDate(item.due_on) : '—' }}</td>
                  <td v-else-if="column.key === 'amount'" class="whitespace-nowrap px-3 py-2.5 text-right font-mono font-semibold text-neutral-900">{{ formatMoney(item.amount, item.currency) }}</td>
                  <td v-else-if="column.key === 'paid_amount'" class="whitespace-nowrap px-3 py-2.5 text-right font-mono text-xs">{{ formatMoney(item.paid_amount, item.currency) }}</td>
                  <td v-else-if="column.key === 'remaining_amount'" class="whitespace-nowrap px-3 py-2.5 text-right font-mono text-xs"
                    :class="otherItemRemainingPayment(item) > 0 ? 'text-neutral-900' : 'text-neutral-400'" data-test="remaining-cell">{{ formatMoney(otherItemRemainingPayment(item), item.currency) }}</td>
                  <td v-else-if="column.key === 'status'" class="whitespace-nowrap px-3 py-2.5 text-center"><span class="rounded px-2 py-0.5 text-xs" :class="otherItemStatusClass(otherItemDisplayStatus(item))" data-test="status-badge">{{ statusLabel(otherItemDisplayStatus(item)) }}</span></td>
                  <td v-else-if="column.key === 'variable_symbol'" class="whitespace-nowrap px-3 py-2.5 font-mono text-xs"><span v-if="item.variable_symbol">{{ item.variable_symbol }}</span><span v-else class="text-neutral-300">—</span></td>
                  <td v-else-if="column.key === 'documents'" class="whitespace-nowrap px-3 py-2.5 text-xs" data-test="document-count"><span v-if="item.document_count">{{ t('other_items.document_count', item.document_count) }}</span><span v-else class="text-neutral-300">—</span></td>
                  <td v-else-if="column.key === 'note'" class="px-3 py-2.5 text-xs" :title="item.note || undefined"><span v-if="item.note" class="inline-block max-w-64 truncate align-middle">{{ item.note }}</span><span v-else class="text-neutral-300">—</span></td>
                  <td v-else-if="column.key === 'posting'" class="px-2 py-2.5 text-center">
                    <PostingBadge v-if="showsPostingBadge(item)" :booked-at="item.posted_at ?? item.accounting_on" :journal-entry-id="Number(item.journal_entry_id)" data-test="posting-badge" />
                  </td>
                </template>
                <td class="px-1 py-2.5 text-right" @click.stop>
                  <RowActionsMenu v-if="rowActions(item).some(action => action.show)" :actions="rowActions(item)" :inline-count="0" :menu-label="t('common.actions')" />
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <!-- Telefon: karty v jednom boxu jako u přijatých faktur, ťuknutí kamkoli otevře detail. -->
        <div v-if="visibleItems.length" class="divide-y divide-neutral-100 rounded-lg border border-neutral-200 bg-surface md:hidden">
          <div v-for="item in visibleItems" :key="item.id" draggable="true" :data-workspace-route="`/other-items/${item.source_id}`"
            class="cursor-pointer px-3 py-3 transition hover:bg-neutral-50" :class="{ 'row-flagged': isOtherItemOverdue(item) }"
            data-test="other-item-card" @click="openDetail(item, $event)" @auxclick.prevent="openDetail(item, $event)">
            <div class="flex items-baseline justify-between gap-2">
              <div class="min-w-0 truncate font-medium text-neutral-900 max-md:line-clamp-2 max-md:whitespace-normal max-md:break-words">{{ item.title }}</div>
              <div class="whitespace-nowrap font-mono text-sm">{{ formatMoney(item.amount, item.currency) }}</div>
            </div>
            <div class="mt-1 flex items-baseline justify-between gap-2 text-xs text-neutral-500">
              <div class="min-w-0 truncate font-mono">
                <RouterLink class="row-link" :to="detailRoute(item)" data-test="other-item-card-link" @click.stop="listNav.markOpen(Number(item.source_id))" @auxclick.stop="listNav.markOpen(Number(item.source_id))">
                  <span v-if="item.document_no">{{ item.document_no }}</span>
                  <span v-else class="text-neutral-400">{{ t('other_items.status.draft') }}</span>
                </RouterLink>
                <span class="text-neutral-400"> · </span><span class="font-sans" data-test="card-kind">{{ kindLabel(item) }}</span>
              </div>
              <span class="inline-flex items-center gap-1">
                <PostingBadge v-if="showsPostingBadge(item)" :booked-at="item.posted_at ?? item.accounting_on" :journal-entry-id="Number(item.journal_entry_id)" />
                <span class="rounded px-1.5 py-0.5 text-xs" :class="otherItemStatusClass(otherItemDisplayStatus(item))">{{ statusLabel(otherItemDisplayStatus(item)) }}</span>
                <span v-if="rowActions(item).some(action => action.show)" @click.stop>
                  <RowActionsMenu :actions="rowActions(item)" :inline-count="0" :menu-label="t('common.actions')" />
                </span>
              </span>
            </div>
            <div class="mt-1 flex items-center justify-between gap-2 text-xs text-neutral-500">
              <span class="min-w-0 truncate" :class="item.partner_name ? '' : 'text-neutral-400'">{{ item.partner_name || t('other_items.no_partner') }}</span>
              <span class="whitespace-nowrap" :class="isOtherItemOverdue(item) ? 'font-medium text-danger-500' : ''">{{ t('other_items.due_on') }}: {{ item.due_on ? formatDate(item.due_on) : '—' }}</span>
            </div>
            <div v-if="Number(item.paid_amount) > 0.005 && otherItemRemainingPayment(item) > 0.005" data-test="card-remaining" class="mt-1 flex flex-wrap items-center justify-between gap-x-2 gap-y-1 text-xs">
              <span class="text-neutral-500">{{ t('other_items.paid_amount') }}: <span class="font-mono text-neutral-700">{{ formatMoney(item.paid_amount, item.currency) }}</span></span>
              <span class="text-neutral-900">{{ t('other_items.remaining') }}: <span class="font-mono">{{ formatMoney(otherItemRemainingPayment(item), item.currency) }}</span></span>
            </div>
          </div>
        </div>
        <div v-if="visibleItems.length && page < pages" ref="loadMoreTarget" class="flex flex-wrap items-center justify-center gap-3 text-sm pointer-fine-hidden">
          <button type="button" class="my-4" :class="btnOutlineSm('neutral')" :disabled="loadingMore" @click="load(true)">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
            {{ t(loadingMore ? 'common.loading' : 'common.load_more') }}
          </button>
          <span>{{ items.length }} / {{ total }}</span>
        </div>
      </section>
      <section v-if="visibleSources.length" class="mt-6">
        <h2 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('other_items.sources_section') }}</h2>
        <p class="mb-3 mt-0.5 text-sm text-neutral-500">{{ t('other_items.sources_hint') }}</p>
        <div class="grid gap-2 md:grid-cols-2">
          <div v-for="item in visibleSources" :key="item.id" class="rounded-lg border border-neutral-200 bg-surface p-3 shadow-sm" data-test="other-item-source">
            <div class="flex flex-wrap items-start justify-between gap-2">
              <div class="min-w-0">
                <RouterLink v-if="internalSourceUrl(item)" :to="internalSourceUrl(item)!" class="font-medium text-primary-700 hover:underline">{{ item.title }}</RouterLink>
                <span v-else class="font-medium">{{ item.title }}</span>
                <div class="mt-0.5 text-xs text-neutral-500">{{ sourceLabel(item.source_kind) }} · {{ item.due_on ? formatDate(item.due_on) : '–' }}<span v-if="item.partner_name"> · {{ item.partner_name }}</span></div>
              </div>
              <div class="text-right">
                <strong class="block text-sm tabular-nums">{{ formatMoney(item.remaining_amount, item.currency) }}</strong>
                <span class="text-xs" :class="otherItemStatusClass(String(item.status))">{{ statusLabel(String(item.status)) }}</span>
              </div>
            </div>
          </div>
        </div>
      </section>
    </template>
    <ListLoadingSpinner :show="loadingMore || refreshing || (loading && (items.length > 0 || sources.length > 0))" />
    <OtherItemKindsModal v-if="showKinds" @close="closeKinds" @changed="onKindsChanged" />
    <OtherItemDocumentSettingsModal v-if="showDocumentSettings" @close="showDocumentSettings = false" />
  </div>
</template>

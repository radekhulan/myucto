<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import { RouterLink, useRouter, useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import {
  purchaseInvoicesApi,
  type PurchaseMonthGroup,
  type PurchaseInvoiceListItem,
  type PurchaseInvoiceItem,
  type PurchaseInvoiceStatus,
  type PurchaseDocumentKind,
  type ImportBatch,
} from '@/api/purchaseInvoices'
import { formatMoney, formatDate, formatMonth, formatNumber, taxDateClass } from '@/composables/useFormat'
import { useRowLink } from '@/composables/useRowLink'
import { useToast } from '@/composables/useToast'
import { apiErrorMessage } from '@/api/errors'
import { useYearOptions } from '@/composables/useYearOptions'
import TableSkeleton from '@/components/ui/TableSkeleton.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import FilterBar, { type FilterChip } from '@/components/ui/FilterBar.vue'
import BulkActionBar from '@/components/ui/BulkActionBar.vue'
import { markRowsTouched, consumeFlashedRows } from '@/composables/useRowFlash'
import { useListKeyboard } from '@/composables/useListKeyboard'
import { clientsApi, type Client } from '@/api/clients'
import { projectsApi, type Project } from '@/api/projects'
import SavedFiltersMenu from '@/components/ui/SavedFiltersMenu.vue'
import type { SavedFilter } from '@/api/preferences'
import ColumnPicker from '@/components/ui/ColumnPicker.vue'
import DensityToggle from '@/components/ui/DensityToggle.vue'
import { useTablePrefs, type ColumnDef } from '@/composables/useTablePrefs'
import { useSavedFilters, savedFilterTone, type SavedFilterTone } from '@/composables/useSavedFilters'
import { ICONS, btnFilled, btnOutline } from '@/components/ui/buttonStyles'
import PostingBadge from '@/components/ui/PostingBadge.vue'
import { useSupplierStore } from '@/stores/supplier'
import { accountingApi, postingErrorI18nKey } from '@/api/accounting'
import WorkspaceDragHandle from '@/components/workspace/WorkspaceDragHandle.vue'
import { appIsoDate } from '@/utils/date'

const { t, locale } = useI18n()
const auth = useAuthStore()
const supplierStore = useSupplierStore()
// Hromadné účtování a filtr zaúčtování jsou dostupné jen v podvojném účetnictví.
const isDoubleEntry = computed(() => auth.hasCommercialFeatures && supplierStore.currentSupplier?.accounting_mode === 'double_entry')
const router = useRouter()
const route = useRoute()
const toast = useToast()

const groups = ref<PurchaseMonthGroup[]>([])
const total = ref(0)
const page = ref(1)
const pages = ref(1)
const loading = ref(true)
const loadingMore = ref(false)
const error = ref('')
/**
 * Řádky k probliknutí po hromadné akci. Značku zapisují bulk handlery přes
 * markRowsTouched(), tady se po překreslení jednou spotřebuje — bez toho se
 * seznam po akci překreslí úplně stejně a uživatel nevidí, čeho se to týkalo.
 */
const flashedIds = ref<Set<number>>(new Set())

// Filtry
const search = ref('')
const statusFilter = ref<PurchaseInvoiceStatus | ''>('')
const kindFilter = ref<PurchaseDocumentKind | ''>('')
const yearFilter = ref<number | ''>(new Date().getFullYear())
const monthFilter = ref<number | ''>('')
const dateFrom = ref('')
const dateTo = ref('')
const overdueOnly = ref(false)
const unpaidOnly = ref(false)
// Neuhrazené K DATU X (task #4) — historický protějšek unpaidOnly (dnešní status).
// Prázdný řetězec = filtr vypnutý.
const unpaidAsOf = ref<string>('')
// „Bez párování úhrady" — doklady bez zaúčtované úhrady (banka ani pokladna).
const unmatchedOnly = ref(false)
const needsReviewOnly = ref(false)
const paymentOrderedFilter = ref<'' | '1' | '0'>('')
// Zaúčtováno/nezaúčtováno (0.9) — jen podvojné účetnictví. '' = vše, '1' = zaúčtováno, '0' = nezaúčtováno.
const bookedFilter = ref<'' | '1' | '0'>('')
const currencyFilter = ref('')
const vendorFilter = ref<number | ''>('')
const vendors = ref<Client[]>([])
// Zakázka (issue #29). 'none' = doklady bez zakázky — bez toho by nešlo dohledat,
// co do ekonomiky akcí ještě nikdo nezařadil.
const projectFilter = ref<number | '' | 'none'>('')
const projects = ref<Project[]>([])
// Filtr na dávku hromadného AI importu (#232) — proklik z obrazovky importu.
const importBatchFilter = ref('')
const importBatches = ref<ImportBatch[]>([])

// Počet aktivních filtrů pro odznáček na mobilním tlačítku „Filtry" (rok i hledání se nepočítají)
const activeFilterCount = computed(() => {
  let n = 0
  if (statusFilter.value) n++
  if (kindFilter.value) n++
  if (vendorFilter.value !== '') n++
  if (projectFilter.value !== '') n++
  if (monthFilter.value !== '') n++
  if (dateFrom.value || dateTo.value) n++
  if (overdueOnly.value) n++
  if (unpaidOnly.value) n++
  if (unpaidAsOf.value) n++
  if (unmatchedOnly.value) n++
  if (needsReviewOnly.value) n++
  if (paymentOrderedFilter.value) n++
  if (bookedFilter.value) n++
  if (importBatchFilter.value) n++
  return n
})

/**
 * Aktivní filtry jako odstranitelné chipy — stejný vzor jako u vydaných faktur.
 * Filtry jsou sbalené za tlačítko „Filtry (N)" i na desktopu, takže musí být
 * na první pohled vidět, co je zapnuté; jinak uživatel netuší, proč seznam
 * nic nevrací. Rok se nezobrazuje, má výchozí hodnotu a byl by tam pořád.
 */
const filterChips = computed<FilterChip[]>(() => {
  const chips: FilterChip[] = []
  if (statusFilter.value) chips.push({ key: 'status', value: t(`purchase_invoice.status.${statusFilter.value}`) })
  if (kindFilter.value) chips.push({ key: 'kind', value: t(`purchase_invoice.document_kind.${kindFilter.value}`) })
  if (vendorFilter.value !== '') {
    const v = vendors.value.find(x => x.id === vendorFilter.value)
    if (v) chips.push({ key: 'vendor', value: v.company_name })
  }
  if (projectFilter.value !== '') {
    const p = projectFilter.value === 'none'
      ? t('purchase_invoice.filters.project_none')
      : projects.value.find(x => x.id === projectFilter.value)?.name
    if (p) chips.push({ key: 'project', value: p })
  }
  if (currencyFilter.value) chips.push({ key: 'currency', value: currencyFilter.value })
  if (monthFilter.value !== '') {
    chips.push({ key: 'month', value: monthOptions.value[Number(monthFilter.value) - 1] ?? String(monthFilter.value) })
  }
  if (dateFrom.value || dateTo.value) {
    chips.push({ key: 'dates', value: `${dateFrom.value ? formatDate(dateFrom.value) : '…'} – ${dateTo.value ? formatDate(dateTo.value) : '…'}` })
  }
  if (overdueOnly.value) chips.push({ key: 'overdue', value: t('purchase_invoice.filters.overdue') })
  if (unpaidOnly.value) chips.push({ key: 'unpaid', value: t('purchase_invoice.filters.unpaid_only') })
  if (unpaidAsOf.value) {
    chips.push({ key: 'unpaid_as_of', value: `${t('purchase_invoice.filters.unpaid_as_of_label')}: ${formatDate(unpaidAsOf.value)}` })
  }
  if (unmatchedOnly.value) chips.push({ key: 'unmatched', value: t('purchase_invoice.filters.unmatched') })
  if (needsReviewOnly.value) chips.push({ key: 'needsReview', value: t('purchase_invoice.filters.needs_review') })
  if (paymentOrderedFilter.value) {
    chips.push({ key: 'paymentOrdered', value: t(paymentOrderedFilter.value === '1' ? 'purchase_invoice.filters.payment_ordered_yes' : 'purchase_invoice.filters.payment_ordered_no') })
  }
  if (bookedFilter.value) {
    chips.push({ key: 'booked', value: t(bookedFilter.value === '1' ? 'common.booked_badge' : 'common.unbooked_badge') })
  }
  if (importBatchFilter.value) chips.push({ key: 'importBatch', value: t('purchase_invoice.filters.import_batch') })
  return chips
})

function clearFilter(key: string) {
  switch (key) {
    case 'status': statusFilter.value = ''; break
    case 'kind': kindFilter.value = ''; break
    case 'vendor': vendorFilter.value = ''; break
    case 'project': projectFilter.value = ''; break
    case 'currency': currencyFilter.value = ''; break
    case 'month': monthFilter.value = ''; break
    case 'dates': dateFrom.value = ''; dateTo.value = ''; break
    case 'overdue': overdueOnly.value = false; break
    case 'unpaid': unpaidOnly.value = false; break
    case 'unpaid_as_of': unpaidAsOf.value = ''; break
    case 'unmatched': unmatchedOnly.value = false; break
    case 'needsReview': needsReviewOnly.value = false; break
    case 'paymentOrdered': paymentOrderedFilter.value = ''; break
    case 'booked': bookedFilter.value = ''; break
    case 'importBatch': importBatchFilter.value = ''; break
  }
}

function clearAllFilters() {
  for (const chip of filterChips.value) clearFilter(chip.key)
}

// Chipy hledání nenesou, takže se ruší zvlášť — jinak by tlačítko v prázdném
// stavu po hledání zdánlivě nic neudělalo.
function clearFiltersAndSearch() {
  clearAllFilters()
  search.value = ''
}

/**
 * Šířka mikro-baru za částkou = podíl na nejvyšším dokladu TÉŽE měny v měsíci.
 * Stejná logika jako u vydaných faktur (viz .amount-cell v styles/main.css) —
 * dá zdi čísel řád velikosti, aniž by zabrala místo. Měny se nemíchají.
 */
function amountBarWidth(inv: PurchaseInvoiceListItem, g: PurchaseMonthGroup): string {
  const value = Math.abs(inv.total_with_vat ?? 0)
  if (value === 0) return '0%'
  let max = 0
  for (const other of g.invoices) {
    if (other.currency !== inv.currency) continue
    const v = Math.abs(other.total_with_vat ?? 0)
    if (v > max) max = v
  }
  if (max === 0) return '0%'
  return `${Math.max(4, Math.round((value / max) * 100))}%`
}

// Hromadné akce
const selectedIds = ref<number[]>([])
const bulkBusy = ref(false)

/**
 * Ploché pořadí řádků napříč měsíčními skupinami — klávesnice se pohybuje po
 * seznamu tak, jak ho uživatel vidí, ne po skupinách.
 */
const flatRows = computed(() => groups.value.flatMap(g => g.invoices))
const rowIndexById = computed(() => {
  const map = new Map<number, number>()
  flatRows.value.forEach((inv, i) => map.set(inv.id, i))
  return map
})

const { activeIndex } = useListKeyboard({
  count: () => flatRows.value.length,
  open: (i) => { const inv = flatRows.value[i]; if (inv) openInvoice(inv) },
  toggle: (i) => { const inv = flatRows.value[i]; if (inv) toggleSelected(inv.id) },
  clear: () => { selectedIds.value = [] },
})

/**
 * Rozbalený náhled položek dokladu přímo v seznamu.
 *
 * Why: nejčastější důvod, proč uživatel klikne na přijatou fakturu, je „co na ní
 * vlastně je" — a pak se musí vracet zpátky do seznamu, který se mezitím
 * překreslil a odscrolloval. Položky se dotahují až na vyžádání (seznam je
 * nenese), takže rozbalení nic nestojí, dokud si o něj uživatel neřekne.
 */
const expandedId = ref<number | null>(null)
const expandedItems = ref<PurchaseInvoiceItem[] | null>(null)
const expandedLoading = ref(false)

/**
 * Šířka rozbaleného řádku = počet viditelných sloupců + zaškrtávátko + rozbalovací
 * tlačítko. Napevno zadaná hodnota by se rozešla s ColumnPickerem, kterým si
 * uživatel sloupce zapíná a vypíná.
 */
const expandedColspan = computed(() => COLUMNS.filter(c => tbl.isVisible(c.key)).length + 2)

async function toggleExpand(inv: PurchaseInvoiceListItem) {
  if (expandedId.value === inv.id) {
    expandedId.value = null
    expandedItems.value = null
    return
  }
  expandedId.value = inv.id
  expandedItems.value = null
  expandedLoading.value = true
  try {
    const full = await purchaseInvoicesApi.get(inv.id)
    expandedItems.value = full.items
  } catch {
    expandedId.value = null
  } finally {
    expandedLoading.value = false
  }
}

let searchTimeout: ReturnType<typeof setTimeout> | null = null

// Sync filtrů s URL query: filter se zapíše do ?param=value formy, abychom mohli
// detekovat menu click (= URL bez query → reset). Pro klika na menu link
// "Přijaté faktury" už když je na této stránce a má aktivní filtr (např. overdue=1)
// se URL změní zpět na čistou — watch fires reset všech ref.
const DEFAULT_YEAR = new Date().getFullYear()

const COLUMNS: ColumnDef[] = [
  { key: 'number', labelKey: 'purchase_invoice.fields.varsymbol', required: true },
  { key: 'vendor', labelKey: 'purchase_invoice.fields.vendor', required: true },
  { key: 'vendor_number', labelKey: 'purchase_invoice.fields.vendor_invoice_number' },
  { key: 'kind', labelKey: 'purchase_invoice.fields.document_kind' },
  { key: 'tax_date', labelKey: 'purchase_invoice.fields.tax_date' },
  { key: 'due_date', labelKey: 'purchase_invoice.fields.due_date' },
  { key: 'amount', labelKey: 'purchase_invoice.totals.with_vat', required: true },
  { key: 'status', labelKey: 'purchase_invoice.status.draft' },
  // Doplňkové sloupce — defaultně skryté, uživatel si je zapne přes ColumnPicker.
  { key: 'paid_at', labelKey: 'invoice.col_paid_at', defaultHidden: true },
  { key: 'booked_at', labelKey: 'invoice.col_booked_at', defaultHidden: true },
  { key: 'exchange_rate', labelKey: 'invoice.col_exchange_rate', defaultHidden: true },
  { key: 'vat_deduction', labelKey: 'purchase_invoice.col_vat_deduction', defaultHidden: true },
  { key: 'expense_category', labelKey: 'purchase_invoice.classification.expense_category', defaultHidden: true },
  { key: 'locked', labelKey: 'lock.column' },
]
const tbl = useTablePrefs('purchase_invoices', COLUMNS)

// Kurz do tabulky — 3 desetinná místa (ČNB konvence), lokalizovaný zápis.
function formatRate(rate: number): string {
  return new Intl.NumberFormat('cs-CZ', { minimumFractionDigits: 2, maximumFractionDigits: 3 }).format(rate)
}

// Kompaktní popis daňového uplatnění: plný / krácený X % / neuplatnit (+ nedaňový).
function vatDeductionLabel(inv: PurchaseInvoiceListItem): string {
  const d = inv.vat_deduction ?? 'full'
  const parts: string[] = [
    d === 'proportional'
      ? t('purchase_invoice.vat_deduction_short.proportional', { pct: inv.vat_deduction_percent ?? 100 })
      : t(`purchase_invoice.vat_deduction_short.${d}`),
  ]
  if (inv.tax_deductible === false) parts.push(t('purchase_invoice.vat_deduction_short.non_tax'))
  return parts.join(' · ')
}
const saved = useSavedFilters('purchase_invoices', { getQuery: buildQuery, applyQuery: applyQueryToPage })

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
  // Dodavatelé pro filtr (jen dodavatelé — přijaté faktury chodí od nich).
  clientsApi.list({ archived: false, per_page: 200, role: 'vendors' })
    .then(r => { vendors.value = r.data }).catch(() => {})
  // Zakázky pro filtr i hromadné zařazení (issue #29) — bez vazby na dodavatele.
  if (!auth.isClientRole) {
    projectsApi.list({ status: 'active', per_page: 200 })
      .then(r => { projects.value = r.data }).catch(() => {})
  }
  // Dávky hromadného AI importu pro „dohledat import" dropdown (#232).
  purchaseInvoicesApi.listImportBatches().then(b => { importBatches.value = b }).catch(() => {})
  if (Object.keys(route.query).length === 0 && await saved.applyDefaultIfAny()) return
  loadFiltersFromQuery(route.query)
  load()
})

function loadFiltersFromQuery(q: typeof route.query) {
  overdueOnly.value = q.overdue === '1' || q.overdue === 'true'
  unpaidOnly.value  = q.unpaid === '1' || q.unpaid === 'true'
  unpaidAsOf.value  = typeof q.unpaid_as_of === 'string' ? q.unpaid_as_of : ''
  unmatchedOnly.value = q.unmatched === '1' || q.unmatched === 'true'
  needsReviewOnly.value = q.needs_review === '1' || q.needs_review === 'true'
  paymentOrderedFilter.value = q.payment_ordered === '1' ? '1' : (q.payment_ordered === '0' ? '0' : '')
  bookedFilter.value = q.booked === '1' ? '1' : (q.booked === '0' ? '0' : '')
  statusFilter.value = typeof q.status === 'string' ? (q.status as PurchaseInvoiceStatus) : ''
  kindFilter.value   = typeof q.kind === 'string' ? (q.kind as PurchaseDocumentKind) : ''
  yearFilter.value   = typeof q.year === 'string' && q.year !== ''
    ? (q.year === 'all' ? '' : Number(q.year))
    : ((overdueOnly.value || unpaidOnly.value || unpaidAsOf.value || unmatchedOnly.value || bookedFilter.value === '0') ? '' : DEFAULT_YEAR)
  monthFilter.value  = typeof q.month === 'string' && q.month !== '' ? Number(q.month) : ''
  dateFrom.value     = typeof q.from === 'string' ? q.from : ''
  dateTo.value       = typeof q.to === 'string' ? q.to : ''
  currencyFilter.value = typeof q.currency === 'string' ? q.currency : ''
  vendorFilter.value = typeof q.vendor === 'string' && q.vendor !== '' ? Number(q.vendor) : ''
  projectFilter.value = typeof q.project === 'string' && q.project !== ''
    ? (q.project === 'none' ? 'none' : Number(q.project))
    : ''
  importBatchFilter.value = typeof q.import_batch === 'string' ? q.import_batch : ''
  // Proklik z importu obvykle přijde bez roku — přepnout na „všechny roky", ať se
  // dávka neschová kvůli defaultu na aktuální rok.
  if (importBatchFilter.value && typeof q.year !== 'string') yearFilter.value = ''
  search.value       = typeof q.q === 'string' ? q.q : ''
}

function buildQuery(): Record<string, string> {
  const q: Record<string, string> = {}
  if (statusFilter.value) q.status = statusFilter.value
  if (kindFilter.value) q.kind = kindFilter.value
  // year=DEFAULT_YEAR je default a nepatří do URL; explicit "" (Vše) ano (jako 'all').
  if (yearFilter.value === '') q.year = 'all'
  else if (yearFilter.value !== DEFAULT_YEAR) q.year = String(yearFilter.value)
  if (monthFilter.value !== '') q.month = String(monthFilter.value)
  if (dateFrom.value) q.from = dateFrom.value
  if (dateTo.value) q.to = dateTo.value
  if (currencyFilter.value) q.currency = currencyFilter.value
  if (vendorFilter.value !== '') q.vendor = String(vendorFilter.value)
  if (projectFilter.value !== '') q.project = String(projectFilter.value)
  if (overdueOnly.value) q.overdue = '1'
  if (unpaidOnly.value) q.unpaid = '1'
  if (unpaidAsOf.value) q.unpaid_as_of = unpaidAsOf.value
  if (unmatchedOnly.value) q.unmatched = '1'
  if (needsReviewOnly.value) q.needs_review = '1'
  if (paymentOrderedFilter.value) q.payment_ordered = paymentOrderedFilter.value
  if (bookedFilter.value) q.booked = bookedFilter.value
  if (importBatchFilter.value) q.import_batch = importBatchFilter.value
  if (search.value) q.q = search.value
  return q
}

let suppressUrlSync = false
function syncFiltersToUrl() {
  if (suppressUrlSync) return
  router.replace({ query: buildQuery() })
}

function applyQueryToPage(q: Record<string, string>) {
  suppressUrlSync = true
  loadFiltersFromQuery(q)
  router.replace({ query: q })
  setTimeout(() => { suppressUrlSync = false }, 0)
  load()
}

watch([statusFilter, kindFilter, yearFilter, monthFilter, dateFrom, dateTo,
       overdueOnly, unpaidOnly, unpaidAsOf, unmatchedOnly, needsReviewOnly, paymentOrderedFilter, bookedFilter,
       currencyFilter, vendorFilter, projectFilter, importBatchFilter], () => {
  syncFiltersToUrl()
  load()
})
watch(search, () => {
  if (searchTimeout) clearTimeout(searchTimeout)
  searchTimeout = setTimeout(() => { syncFiltersToUrl(); load() }, 300)
})

// Reset filtrů při menu link click (= route.query je prázdná).
watch(() => route.query, (newQ) => {
  if (Object.keys(newQ).length === 0) {
    // Reset bez triggering URL sync zpět (suppressUrlSync) — refs se nastaví, watch fires,
    // ale URL update se přeskočí. Pak ručně syncneme (no-op pro prázdné).
    suppressUrlSync = true
    statusFilter.value = ''
    kindFilter.value = ''
    yearFilter.value = DEFAULT_YEAR
    monthFilter.value = ''
    dateFrom.value = ''
    dateTo.value = ''
    overdueOnly.value = false
    unpaidOnly.value = false
    unpaidAsOf.value = ''
    unmatchedOnly.value = false
    needsReviewOnly.value = false
    paymentOrderedFilter.value = ''
    bookedFilter.value = ''
    currencyFilter.value = ''
    vendorFilter.value = ''
    projectFilter.value = ''
    importBatchFilter.value = ''
    search.value = ''
    // Uvolnit po flush (watch effects)
    setTimeout(() => { suppressUrlSync = false }, 0)
  }
})

function mergeGroups(existing: PurchaseMonthGroup[], incoming: PurchaseMonthGroup[]): PurchaseMonthGroup[] {
  const byMonth = new Map<string, PurchaseMonthGroup>()
  for (const g of existing) byMonth.set(g.month, { ...g, invoices: [...g.invoices], totals_per_currency: [...g.totals_per_currency] })
  for (const g of incoming) {
    const cur = byMonth.get(g.month)
    if (!cur) {
      byMonth.set(g.month, { ...g, invoices: [...g.invoices], totals_per_currency: [...g.totals_per_currency] })
      continue
    }
    const seenIds = new Set(cur.invoices.map(i => i.id))
    for (const inv of g.invoices) if (!seenIds.has(inv.id)) cur.invoices.push(inv)
    cur.count = cur.invoices.length
    for (const t of g.totals_per_currency) {
      const found = cur.totals_per_currency.find(x => x.currency === t.currency)
      if (found) {
        found.without_vat = (found.without_vat ?? 0) + (t.without_vat ?? 0)
        found.vat = (found.vat ?? 0) + (t.vat ?? 0)
        found.with_vat = (found.with_vat ?? 0) + (t.with_vat ?? 0)
      } else {
        cur.totals_per_currency.push({ ...t })
      }
    }
  }
  return Array.from(byMonth.values()).sort((a, b) => b.month.localeCompare(a.month))
}

async function load(reset = true) {
  if (reset) {
    loading.value = true
    page.value = 1
    selectedIds.value = []
  } else {
    loadingMore.value = true
    page.value++
  }
  error.value = ''
  try {
    const res = await purchaseInvoicesApi.listGrouped({
      status:        statusFilter.value || undefined,
      document_kind: kindFilter.value   || undefined,
      year:          yearFilter.value   || undefined,
      month:         monthFilter.value  || undefined,
      date_from:     dateFrom.value     || undefined,
      date_to:       dateTo.value       || undefined,
      currency:      currencyFilter.value || undefined,
      vendor_id:     vendorFilter.value   || undefined,
      project_id:    projectFilter.value  || undefined,
      unpaid_only:   unpaidOnly.value   || undefined,
      overdue:       overdueOnly.value  || undefined,
      unpaid_as_of:  unpaidAsOf.value   || undefined,
      unmatched:     unmatchedOnly.value || undefined,
      needs_review:  needsReviewOnly.value || undefined,
      payment_ordered: paymentOrderedFilter.value || undefined,
      booked:        bookedFilter.value  || undefined,
      import_batch_id: importBatchFilter.value || undefined,
      q:             search.value       || undefined,
      page: page.value,
    })
    if (reset) {
      groups.value = res.data
    } else {
      groups.value = mergeGroups(groups.value, res.data)
    }
    total.value = res.meta.total
    pages.value = res.meta.pages ?? 1
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    loading.value = false
    loadingMore.value = false
    flashedIds.value = consumeFlashedRows('purchase_invoice')
  }
}

const navigateRow = useRowLink()
function openInvoice(inv: PurchaseInvoiceListItem, e?: MouseEvent) {
  navigateRow(`/purchase-invoices/${inv.id}`, e)
}

// Year picker — distinct roky z `purchase_invoices` (issue #33).
const yearOptions = useYearOptions('purchase_invoices', yearFilter)
const monthOptions = computed(() => {
  const locStr = locale.value === 'en' ? 'en-US' : 'cs-CZ'
  return Array.from({ length: 12 }, (_, i) =>
    new Date(2000, i, 1).toLocaleDateString(locStr, { month: 'long' })
  )
})

const loadedCount = computed(() =>
  groups.value.reduce((sum, g) => sum + g.invoices.length, 0)
)

const isOverdue = (dueDate: string, status: PurchaseInvoiceStatus): boolean => {
  if (status !== 'received' && status !== 'booked') return false
  return new Date(dueDate) < new Date(appIsoDate())
}

// Status badge ve stejných tokenech jako Detail (sjednoceno s vystavenou)
const statusBadgeClass = (s: PurchaseInvoiceStatus): string => ({
  draft:     'bg-neutral-100 text-neutral-600 border border-neutral-200',
  received:  'bg-primary-50 text-primary-700 border border-primary-500/40',
  booked:    'bg-warning-50 text-warning-600 border border-warning-500/40',
  paid:      'bg-success-50 text-success-600 border border-success-500/40',
  cancelled: 'bg-danger-50 text-danger-500 border border-danger-500/40',
}[s])

// Row class — soft red background pro overdue, soft gray pro cancelled,
// soft yellow pro faktury s AI extraction_warning (vyžadují kontrolu)
const rowClass = (inv: PurchaseInvoiceListItem): string => {
  if (inv.status === 'cancelled') return 'opacity-60'
  if (isOverdue(inv.due_date, inv.status)) return 'bg-danger-50/30'
  if (inv.extraction_warning) return 'bg-warning-50/50'
  return ''
}

// ── Hromadné akce ─────────────────────────────────────────────────────
function goToPaymentOrder() {
  if (selectedIds.value.length === 0) return
  router.push({
    path: '/purchase-invoices/payment-orders',
    query: { preselect: selectedIds.value.join(',') },
  })
}

function toggleSelected(id: number) {
  const idx = selectedIds.value.indexOf(id)
  if (idx >= 0) selectedIds.value.splice(idx, 1)
  else selectedIds.value.push(id)
}

function allRowIds(): number[] {
  return groups.value.flatMap(g => g.invoices.map(i => i.id))
}

const allSelected = computed(() => {
  const ids = allRowIds()
  return ids.length > 0 && ids.every(id => selectedIds.value.includes(id))
})

function toggleAll() {
  if (allSelected.value) {
    selectedIds.value = []
  } else {
    selectedIds.value = allRowIds()
  }
}

// Helpers per row
function statusOf(id: number): PurchaseInvoiceStatus | null {
  for (const g of groups.value) {
    const f = g.invoices.find(i => i.id === id)
    if (f) return f.status
  }
  return null
}

// Zámek dokladu (F6) — jen z BE pole `locked`, FE nic neodvozuje.
function rowLockedForMe(inv: PurchaseInvoiceListItem): boolean {
  return auth.isClientRole && !!inv.locked?.is_locked
}

function lockedById(id: number): boolean {
  for (const g of groups.value) {
    const f = g.invoices.find(i => i.id === id)
    if (f) return rowLockedForMe(f)
  }
  return false
}

function lockTitle(inv: PurchaseInvoiceListItem): string {
  const reasons = (inv.locked?.reasons ?? []).map(r => t(`lock.reason.${r}`)).join(', ')
  return reasons ? `${t('lock.badge')}: ${reasons}` : (t('lock.badge') as string)
}

const draftsSelected     = computed(() => selectedIds.value.filter(id => statusOf(id) === 'draft' && !lockedById(id)))
const markReceivedSelected = computed(() => selectedIds.value.filter(id => statusOf(id) === 'draft' && !lockedById(id)))
const markPayableSelected = computed(() => selectedIds.value.filter(id => {
  const s = statusOf(id)
  return (s === 'received' || s === 'booked') && !lockedById(id)
}))
const cancellableSelected = computed(() => selectedIds.value.filter(id => {
  const s = statusOf(id); return s && s !== 'cancelled'
}))

async function bulkTransition(target: PurchaseInvoiceStatus, ids: number[]) {
  if (ids.length === 0 || bulkBusy.value) return
  if (target === 'cancelled' && !confirm(t('purchase_invoice.bulk.confirm_cancel', { n: ids.length }))) return
  bulkBusy.value = true
  // Probliknout jen doklady, kterým přechod opravdu prošel — u částečného
  // selhání by záblesk celého výběru tvrdil něco, co se nestalo.
  const done: number[] = []
  let fail = 0
  for (const id of ids) {
    try { await purchaseInvoicesApi.transition(id, target); done.push(id) } catch { fail++ }
  }
  bulkBusy.value = false
  if (fail === 0) toast.success(t('purchase_invoice.bulk.success', { n: done.length }))
  else            toast.error(t('purchase_invoice.bulk.partial', { ok: done.length, fail }))
  markRowsTouched('purchase_invoice', done)
  await load()
}

async function bulkDelete() {
  const ids = draftsSelected.value
  if (ids.length === 0 || bulkBusy.value) return
  if (!confirm(t('purchase_invoice.bulk.confirm_delete', { n: ids.length }))) return
  bulkBusy.value = true
  let ok = 0, fail = 0
  for (const id of ids) {
    try { await purchaseInvoicesApi.delete(id); ok++ } catch { fail++ }
  }
  bulkBusy.value = false
  if (fail === 0) toast.success(t('purchase_invoice.bulk.delete_success', { n: ok }))
  else            toast.error(t('purchase_invoice.bulk.partial', { ok, fail }))
  await load()
}

function bookedAtOf(id: number): string | null {
  for (const g of groups.value) {
    const f = g.invoices.find(i => i.id === id)
    if (f) return f.booked_at ?? null
  }
  return null
}

function documentKindOf(id: number): PurchaseDocumentKind | null {
  for (const g of groups.value) {
    const f = g.invoices.find(i => i.id === id)
    if (f) return f.document_kind
  }
  return null
}

// Hromadné zaúčtování (A2) — jen podvojné účetnictví, jen nezaúčtované (booked_at NULL)
// přijaté doklady (received/booked/paid; draft nemá co účtovat, cancelled se neúčtuje).
const postableSelected = computed(() => {
  if (!isDoubleEntry.value) return []
  return selectedIds.value.filter(id => {
    const s = statusOf(id)
    return documentKindOf(id) !== 'advance'
      && !bookedAtOf(id)
      && (s === 'received' || s === 'booked' || s === 'paid')
  })
})

async function bulkPost() {
  const ids = postableSelected.value
  if (ids.length === 0 || bulkBusy.value) {
    if (ids.length === 0) toast.warning(t('purchase_invoice.bulk.post_no_eligible'))
    return
  }
  if (!confirm(t('purchase_invoice.bulk.post_confirm', { n: ids.length }))) return
  bulkBusy.value = true
  try {
    const r = await accountingApi.postPurchasesBulk(ids)
    // `posted` vrací rovnou ID — probliknou jen doklady, které se opravdu
    // zaúčtovaly, ne celý výběr.
    markRowsTouched('purchase_invoice', r.posted.length ? r.posted : ids)
    selectedIds.value = []
    if (r.failed.length) {
      const detail = r.failed.map(f => `#${f.id}: ${t(postingErrorI18nKey(f.error_code))}`).join('\n')
      toast.warning(t('purchase_invoice.bulk.post_partial', { ok: r.posted.length, err: r.failed.length }) + '\n' + detail)
    } else {
      toast.success(t('purchase_invoice.bulk.post_success', { n: r.posted.length }))
    }
    await load()
  } catch (e: any) {
    toast.error(apiErrorMessage(e) || t('purchase_invoice.bulk.post_failed'))
  } finally {
    bulkBusy.value = false
  }
}

// Hromadné zařazení k zakázce (issue #29) — klient (cestovní kancelář) zakládá
// doklady akce průběžně a zařazuje je k akci až dodatečně, často po desítkách.
// Endpoint zvládne i zaúčtovaný doklad (zakázka je analytická dimenze).
const projectAssignableSelected = computed(() =>
  selectedIds.value.filter(id => invOf(id)?.status !== 'cancelled'))
const bulkProjectTarget = ref<number | '' | 'none'>('')
async function bulkSetProject() {
  const target = bulkProjectTarget.value
  const ids = projectAssignableSelected.value
  if (target === '' || ids.length === 0 || bulkBusy.value) { bulkProjectTarget.value = ''; return }
  const projectId = target === 'none' ? null : Number(target)
  bulkBusy.value = true
  const done: number[] = []
  let fail = 0
  for (const id of ids) {
    try { await purchaseInvoicesApi.setProject(id, projectId); done.push(id) } catch { fail++ }
  }
  bulkBusy.value = false
  bulkProjectTarget.value = ''
  if (fail === 0) toast.success(t('purchase_invoice.bulk.project_success', { n: done.length }))
  else            toast.error(t('purchase_invoice.bulk.partial', { ok: done.length, fail }))
  markRowsTouched('purchase_invoice', done)
  await load()
}

// Hromadná změna typu dokladu (#232) — po AI importu přehodit vybrané „Doklady
// o úhradě" na „Faktura" apod. Vyloučené: stornované a zálohy (settlement vazby).
function invOf(id: number): PurchaseInvoiceListItem | null {
  for (const g of groups.value) {
    const f = g.invoices.find(i => i.id === id)
    if (f) return f
  }
  return null
}
const kindEditableSelected = computed(() => selectedIds.value.filter(id => {
  const inv = invOf(id)
  return inv && inv.status !== 'cancelled' && inv.document_kind !== 'advance'
}))
const bulkKindTarget = ref<PurchaseDocumentKind | ''>('')
async function bulkSetKind() {
  const kind = bulkKindTarget.value
  const ids = kindEditableSelected.value
  if (!kind || ids.length === 0 || bulkBusy.value) { bulkKindTarget.value = ''; return }
  bulkBusy.value = true
  const done: number[] = []
  let fail = 0
  for (const id of ids) {
    try { await purchaseInvoicesApi.setDocumentKind(id, kind); done.push(id) } catch { fail++ }
  }
  bulkBusy.value = false
  bulkKindTarget.value = ''
  if (fail === 0) toast.success(t('purchase_invoice.bulk.kind_success', { n: done.length }))
  else            toast.error(t('purchase_invoice.bulk.partial', { ok: done.length, fail }))
  markRowsTouched('purchase_invoice', done)
  await load()
}
</script>

<template>
  <div>
    <!-- ═══ Topbar: title + bulk actions + new ═══ -->
    <div class="flex items-center justify-between mb-4 gap-3 flex-wrap">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('purchase_invoice.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5">{{ t('purchase_invoice.subtitle') }}</p>
      </div>

      <div class="flex items-center gap-2 flex-wrap">
        <RouterLink
          v-if="auth.canWrite('purchase_invoices.create') || auth.isDemo"
          to="/purchase-invoices/new"
          :class="btnFilled('primary')"
        >
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
          {{ t('purchase_invoice.new') }}
        </RouterLink>
      </div>
    </div>

    <!-- Hromadné akce v plovoucí liště u spodní hrany — u výběru řádků, ne
         v hlavičce mimo zorné pole (viz BulkActionBar). -->
    <BulkActionBar :count="selectedIds.length" @clear="selectedIds = []">
        <button v-if="(selectedIds.length > 0) && auth.canWrite('purchase_invoices.payment_orders') && !auth.isClientRole"
          @click="goToPaymentOrder"
          :class="btnOutline('primary')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.coin" /></svg>
          {{ t('purchase_invoice.bulk.to_payment_order', { n: selectedIds.length }) }}
        </button>
        <button v-if="(markReceivedSelected.length > 0) && auth.canWrite('purchase_invoices.transition')"
          @click="bulkTransition('received', markReceivedSelected)"
          :disabled="bulkBusy"
          :class="btnOutline('primary')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.inbox" /></svg>
          {{ bulkBusy ? '…' : t('purchase_invoice.bulk.mark_received', { n: markReceivedSelected.length }) }}
        </button>
        <button v-if="(markPayableSelected.length > 0) && auth.canWrite('purchase_invoices.transition')"
          @click="bulkTransition('paid', markPayableSelected)"
          :disabled="bulkBusy"
          :class="btnOutline('success')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.checkCircle" /></svg>
          {{ bulkBusy ? '…' : t('purchase_invoice.bulk.mark_paid', { n: markPayableSelected.length }) }}
        </button>
        <button v-if="(cancellableSelected.length > 0) && auth.canWrite('purchase_invoices.transition') && !auth.isClientRole"
          @click="bulkTransition('cancelled', cancellableSelected)"
          :disabled="bulkBusy"
          :class="btnOutline('danger')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>
          {{ bulkBusy ? '…' : t('purchase_invoice.bulk.cancel', { n: cancellableSelected.length }) }}
        </button>
        <button v-if="(postableSelected.length > 0) && auth.canWrite('accounting.journal.post') && !auth.isClientRole"
          @click="bulkPost"
          :disabled="bulkBusy"
          :class="btnOutline('primary')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.clipboardCheck" /></svg>
          {{ bulkBusy ? '…' : t('purchase_invoice.bulk.post', { n: postableSelected.length }) }}
        </button>
        <!-- Hromadné zařazení k zakázce (issue #29) -->
        <select v-if="(projectAssignableSelected.length > 0) && !auth.isClientRole && auth.canWrite('purchase_invoices')"
          v-model="bulkProjectTarget"
          @change="bulkSetProject"
          :disabled="bulkBusy"
          class="cursor-pointer h-9 max-w-56 px-2 border border-primary-500 text-primary-700 bg-surface hover:bg-primary-50 disabled:opacity-50 text-sm font-medium rounded-md">
          <option value="">{{ t('purchase_invoice.bulk.set_project', { n: projectAssignableSelected.length }) }}</option>
          <option value="none">{{ t('purchase_invoice.filters.project_none') }}</option>
          <option v-for="p in projects" :key="p.id" :value="p.id">{{ p.name }}</option>
        </select>
        <!-- Hromadná změna typu dokladu (#232) — oprava AI klasifikace po importu -->
        <select v-if="(kindEditableSelected.length > 0) && auth.canWrite('purchase_invoices.transition')"
          v-model="bulkKindTarget"
          @change="bulkSetKind"
          :disabled="bulkBusy"
          class="cursor-pointer h-9 px-2 border border-primary-500 text-primary-700 bg-surface hover:bg-primary-50 disabled:opacity-50 text-sm font-medium rounded-md">
          <option value="">{{ t('purchase_invoice.bulk.set_kind', { n: kindEditableSelected.length }) }}</option>
          <option value="invoice">{{ t('purchase_invoice.document_kind.invoice') }}</option>
          <option value="receipt">{{ t('purchase_invoice.document_kind.receipt') }}</option>
          <option value="credit_note">{{ t('purchase_invoice.document_kind.credit_note') }}</option>
        </select>
        <button v-if="(draftsSelected.length > 0) && auth.canWrite('purchase_invoices.delete')"
          @click="bulkDelete"
          :disabled="bulkBusy"
          :class="btnOutline('danger')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.trash" /></svg>
          {{ bulkBusy ? '…' : t('purchase_invoice.bulk.delete', { n: draftsSelected.length }) }}
        </button>
    </BulkActionBar>

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

    <!-- ═══ Filtry v boxu ═══ -->
    <FilterBar
      :active-count="activeFilterCount"
      collapsible
      :chips="filterChips"
      @clear="clearFilter"
      @clear-all="clearAllFilters"
    >
      <template #primary>
        <div class="relative flex-1 min-w-56">
          <svg class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-neutral-400"
            fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M17 11a6 6 0 1 1-12 0 6 6 0 0 1 12 0z" />
          </svg>
          <input
            v-model="search"
            type="search"
            :placeholder="t('purchase_invoice.filters.search_placeholder')"
            class="w-full h-9 pl-9 pr-3 border border-neutral-300 rounded-md text-sm focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none"
          />
        </div>
      </template>
        <select v-model="statusFilter" class="h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
          <option value="">{{ t('purchase_invoice.filters.all_statuses') }}</option>
          <option value="draft">{{ t('purchase_invoice.status.draft') }}</option>
          <option value="received">{{ t('purchase_invoice.status.received') }}</option>
          <option value="paid">{{ t('purchase_invoice.status.paid') }}</option>
          <option value="cancelled">{{ t('purchase_invoice.status.cancelled') }}</option>
        </select>
        <select v-model="kindFilter" class="h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
          <option value="">{{ t('purchase_invoice.filters.all_kinds') }}</option>
          <option value="invoice">{{ t('purchase_invoice.document_kind.invoice') }}</option>
          <option value="receipt">{{ t('purchase_invoice.document_kind.receipt') }}</option>
          <option value="credit_note">{{ t('purchase_invoice.document_kind.credit_note') }}</option>
          <option value="advance">{{ t('purchase_invoice.document_kind.advance') }}</option>
          <option value="tax_document">{{ t('purchase_invoice.document_kind.tax_document') }}</option>
        </select>
        <div class="min-w-48 flex-1 max-w-xs">
          <SearchableSelect
            :model-value="vendorFilter === '' ? null : vendorFilter"
            @update:model-value="(v) => vendorFilter = v === null ? '' : Number(v)"
            :options="vendors.map(c => ({ value: c.id, label: c.company_name, secondary: c.ic ?? undefined }))"
            :placeholder="t('purchase_invoice.filters.all_vendors')"
          />
        </div>
        <select v-if="!auth.isClientRole" v-model="projectFilter"
          class="h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
          <option value="">{{ t('purchase_invoice.filters.all_projects') }}</option>
          <option value="none">{{ t('purchase_invoice.filters.project_none') }}</option>
          <option v-for="p in projects" :key="p.id" :value="p.id">{{ p.name }}</option>
        </select>
        <select v-model="yearFilter" :disabled="!!dateFrom || !!dateTo"
          class="h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm disabled:opacity-50">
          <option value="">{{ t('invoice.all_years') }}</option>
          <option v-for="y in yearOptions" :key="y" :value="y">{{ y }}</option>
        </select>
        <select v-model="monthFilter" :disabled="!!dateFrom || !!dateTo || yearFilter === ''"
          class="h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm disabled:opacity-50">
          <option :value="''">{{ t('invoice.all_months') }}</option>
          <option v-for="(label, i) in monthOptions" :key="i + 1" :value="i + 1">{{ label }}</option>
        </select>
        <input v-model="dateFrom" type="date" :placeholder="t('common.from')"
          class="h-9 px-2 border border-neutral-300 rounded-md text-sm" :title="t('common.from')" />
        <input v-model="dateTo" type="date" :placeholder="t('common.to')"
          class="h-9 px-2 border border-neutral-300 rounded-md text-sm" :title="t('common.to')" />
        <button v-if="dateFrom || dateTo" @click="dateFrom = ''; dateTo = ''"
          class="cursor-pointer h-9 px-2 text-xs text-neutral-500 hover:text-neutral-700">{{ t('invoice.clear_date_filter') }}</button>
        <label class="flex items-center gap-1.5 text-sm text-neutral-700 px-2">
          <input v-model="overdueOnly" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
          {{ t('purchase_invoice.filters.overdue') }}
        </label>
        <label class="flex items-center gap-1.5 text-sm text-neutral-700 px-2">
          <input v-model="unpaidOnly" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
          {{ t('purchase_invoice.filters.unpaid_only') }}
        </label>
        <!-- Neuhrazené K DATU X (task #4) — stav úhrady k historickému dni, ne dnešní
             status. Odlišné od "unpaidOnly" výše, proto vlastní datumové pole s popiskem,
             ne další zaškrtávátko vedle stejnojmenného filtru. -->
        <label class="flex items-center gap-1.5 text-sm text-neutral-700 px-2" :title="t('purchase_invoice.filters.unpaid_as_of_hint')">
          {{ t('purchase_invoice.filters.unpaid_as_of_label') }}
          <input v-model="unpaidAsOf" type="date"
            class="h-9 px-2 border border-neutral-300 rounded-md text-sm" />
        </label>
        <button v-if="unpaidAsOf" @click="unpaidAsOf = ''"
          :title="t('purchase_invoice.filters.unpaid_as_of_clear')"
          class="cursor-pointer h-9 px-2 text-xs text-neutral-500 hover:text-neutral-700">✕</button>
        <label class="flex items-center gap-1.5 text-sm text-neutral-700 px-2"
          :title="t('purchase_invoice.filters.unmatched_hint')">
          <input v-model="unmatchedOnly" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
          {{ t('purchase_invoice.filters.unmatched') }}
        </label>
        <label class="flex items-center gap-1.5 text-sm text-warning-700 px-2">
          <input v-model="needsReviewOnly" type="checkbox" class="rounded border-neutral-300 text-warning-600" />
          {{ t('purchase_invoice.filters.needs_review') }}
        </label>
        <select v-model="paymentOrderedFilter" class="h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm"
          :title="t('purchase_invoice.filters.payment_ordered')">
          <option value="">{{ t('purchase_invoice.filters.payment_ordered_all') }}</option>
          <option value="1">{{ t('purchase_invoice.filters.payment_ordered_yes') }}</option>
          <option value="0">{{ t('purchase_invoice.filters.payment_ordered_no') }}</option>
        </select>
        <select v-if="isDoubleEntry" v-model="bookedFilter"
          class="h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm"
          :title="t('common.booked_filter_all')">
          <option value="">{{ t('common.booked_filter_all') }}</option>
          <option value="1">{{ t('common.booked_badge') }}</option>
          <option value="0">{{ t('common.unbooked_badge') }}</option>
        </select>
        <!-- Dohledat dávku hromadného AI importu (#232) -->
        <select v-if="importBatches.length || importBatchFilter" v-model="importBatchFilter"
          class="h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm max-w-[16rem]"
          :title="t('purchase_invoice.filters.import_batch')">
          <option value="">{{ t('purchase_invoice.filters.import_batch_all') }}</option>
          <option v-if="importBatchFilter && !importBatches.some(b => b.import_batch_id === importBatchFilter)"
            :value="importBatchFilter">{{ t('purchase_invoice.filters.import_batch_current') }}</option>
          <option v-for="b in importBatches" :key="b.import_batch_id" :value="b.import_batch_id">
            {{ formatDate(b.created_at) }} · {{ t('purchase_invoice.filters.import_batch_count', { n: b.count }) }}
          </option>
        </select>
      <template #actions>
        <SavedFiltersMenu :ctrl="saved" />
        <ColumnPicker class="hidden md:block" :ctrl="tbl" />
        <DensityToggle class="hidden md:block" :ctrl="tbl" />
      </template>
    </FilterBar>

    <!-- ═══ Loading / Error / Empty / Data ═══ -->
    <div v-if="loading" class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
      <TableSkeleton :rows="6" :cols="7" />
    </div>

    <div v-else-if="error" class="bg-danger-50 border border-danger-500/40 text-danger-500 rounded-md p-3 text-sm">
      {{ error }}
    </div>

    <div v-else-if="!groups.length" class="bg-surface border border-neutral-200 rounded-lg shadow-sm">
      <EmptyState v-if="search || statusFilter || kindFilter" variant="filtered"
        :title="t('purchase_invoice.empty_filtered')"
        :cta="t('common.empty_state.clear_filters')" @action="clearFiltersAndSearch" />
      <EmptyState v-else icon="inbox"
        :title="auth.isClientRole ? t('purchase_invoice.empty_client') : t('purchase_invoice.empty')"
        :cta="auth.canWrite('purchase_invoices.create') || auth.isDemo ? t('purchase_invoice.new') : undefined"
        to="/purchase-invoices/new" />
    </div>

    <div v-else>
      <div class="text-xs text-neutral-500 mb-3 flex items-center justify-between">
        <span>{{ t('purchase_invoice.summary_count', { count: total }) }}</span>
        <span v-if="loadedCount < total">{{ t('common.loaded_count', { loaded: loadedCount, total }) }}</span>
      </div>

      <!-- ═══ Skupiny po měsících ═══ -->
      <section v-for="g in groups" :key="g.month" class="mb-5">
        <!-- Měsíční rozdělovník ve stylu účetní knihy — stejný vzor jako u vydaných
             faktur: název měsíce vlevo, hairline přes volné místo, součet v mono
             vpravo. Součty se musí umět zalomit, jinak by na mobilu vytlačily stránku. -->
        <header class="sticky top-16 z-[5] flex flex-wrap items-center justify-between gap-x-4 gap-y-1 bg-neutral-50/92 backdrop-blur-md border border-neutral-200 rounded-t-lg px-4 py-2.5 mb-0">
          <div class="flex items-baseline gap-2.5 shrink-0">
            <h2 class="text-[13px] font-semibold uppercase tracking-[0.16em] text-neutral-800">{{ formatMonth(g.month) }}</h2>
            <span class="text-[11px] text-neutral-500 tabular-nums">{{ g.count }}</span>
          </div>
          <span class="hidden sm:block flex-1 h-px bg-gradient-to-r from-neutral-200 to-transparent" aria-hidden="true"></span>
          <div class="flex flex-wrap items-center justify-end gap-x-3 gap-y-0.5 min-w-0 text-xs">
            <span v-for="tc in g.totals_per_currency" :key="tc.currency" class="font-mono">
              <span class="text-neutral-500">{{ tc.currency }}:</span>
              <span class="font-semibold text-neutral-900 ml-1">{{ formatMoney(tc.with_vat, tc.currency) }}</span>
            </span>
          </div>
        </header>

        <!-- Desktop: tabulka -->
        <div class="hidden md:block bg-surface border border-t-0 border-neutral-200 rounded-b-lg overflow-hidden">
          <div class="overflow-x-auto">
            <table class="w-full text-sm table-sticky-first" :class="tbl.densityClass.value">
              <thead class="bg-neutral-50 text-neutral-500 text-xs uppercase tracking-wide">
                <tr>
                  <th class="px-2 py-2 w-10 text-center">
                    <input
                      type="checkbox"
                      :checked="allSelected"
                      @change="toggleAll"
                      :title="t('common.select_all')"
                      class="w-4 h-4 cursor-pointer rounded border-neutral-300 text-primary-600 focus:ring-2 focus:ring-primary-500/30"
                    />
                  </th>
                  <th v-if="tbl.isVisible('number')" class="text-left px-4 py-2 font-medium w-32">{{ t('purchase_invoice.fields.varsymbol') }}</th>
                  <th v-if="tbl.isVisible('vendor')" class="text-left px-4 py-2 font-medium">{{ t('purchase_invoice.fields.vendor') }}</th>
                  <th v-if="tbl.isVisible('vendor_number')" class="text-left px-4 py-2 font-medium w-32">{{ t('purchase_invoice.fields.vendor_invoice_number') }}</th>
                  <th v-if="tbl.isVisible('kind')" class="text-center px-4 py-2 font-medium">{{ t('purchase_invoice.fields.document_kind') }}</th>
                  <th v-if="tbl.isVisible('tax_date')" class="text-center px-4 py-2 font-medium">{{ t('purchase_invoice.fields.tax_date') }}</th>
                  <th v-if="tbl.isVisible('due_date')" class="text-center px-4 py-2 font-medium">{{ t('purchase_invoice.fields.due_date') }}</th>
                  <th v-if="tbl.isVisible('amount')" class="text-right px-4 py-2 font-medium">{{ t('purchase_invoice.totals.with_vat') }}</th>
                  <th v-if="tbl.isVisible('status')" class="text-center px-4 py-2 font-medium">{{ t('purchase_invoice.status.draft') }}</th>
                  <th v-if="tbl.isVisible('paid_at')" class="text-center px-4 py-2 font-medium">{{ t('invoice.col_paid_at') }}</th>
                  <th v-if="tbl.isVisible('booked_at')" class="text-center px-4 py-2 font-medium">{{ t('invoice.col_booked_at') }}</th>
                  <th v-if="tbl.isVisible('exchange_rate')" class="text-right px-4 py-2 font-medium">{{ t('invoice.col_exchange_rate') }}</th>
                  <th v-if="tbl.isVisible('vat_deduction')" class="text-center px-4 py-2 font-medium">{{ t('purchase_invoice.col_vat_deduction') }}</th>
                  <th v-if="tbl.isVisible('expense_category')" class="text-left px-4 py-2 font-medium">{{ t('purchase_invoice.classification.expense_category') }}</th>
                  <th v-if="tbl.isVisible('locked')" class="text-center px-2 py-2 font-medium w-8">
                    <span class="sr-only">{{ t('lock.column') }}</span>
                  </th>
                  <th class="px-1 py-2 w-8">
                    <span class="sr-only">{{ t('common.expand_items') }}</span>
                  </th>
                </tr>
              </thead>
              <tbody class="divide-y divide-neutral-100">
                <!-- <template v-for>, protože k jednomu dokladu patří dva řádky:
                     samotný doklad a jeho rozbalený náhled položek. -->
                <template v-for="inv in g.invoices" :key="inv.id">
                <tr
                  draggable="true"
                  :data-workspace-route="`/purchase-invoices/${inv.id}`"
                  @click="openInvoice(inv, $event)"
                  @auxclick.prevent="openInvoice(inv, $event)"
                  class="cursor-pointer hover:bg-neutral-50 transition"
                  :class="[rowClass(inv), flashedIds.has(inv.id) ? 'row-flash' : '']"
                  :data-row-active="rowIndexById.get(inv.id) === activeIndex"
                >
                  <td class="px-2 py-2.5 text-center" @click.stop>
                    <div class="flex items-center justify-center gap-1.5">
                      <WorkspaceDragHandle />
                      <input
                        type="checkbox"
                        :checked="selectedIds.includes(inv.id)"
                        @change="toggleSelected(inv.id)"
                        class="w-5 h-5 cursor-pointer rounded border-neutral-300 text-primary-600 focus:ring-2 focus:ring-primary-500/30"
                      />
                    </div>
                  </td>
                  <td v-if="tbl.isVisible('number')" class="px-4 py-2.5 font-mono text-xs">
                    <RouterLink class="row-link" :to="`/purchase-invoices/${inv.id}`" @click.stop @auxclick.stop>
                      <span v-if="inv.varsymbol">{{ inv.varsymbol }}</span>
                      <span v-else class="text-neutral-400">#{{ inv.id }}</span>
                    </RouterLink>
                  </td>
                  <td v-if="tbl.isVisible('vendor')" class="px-4 py-2.5">
                    <div class="font-medium text-neutral-900">{{ inv.vendor_company_name }}</div>
                    <div v-if="inv.vendor_ic" class="text-xs text-neutral-500 font-mono">{{ t('common.ic') }} {{ inv.vendor_ic }}</div>
                  </td>
                  <td v-if="tbl.isVisible('vendor_number')" class="px-4 py-2.5 font-mono text-xs text-neutral-600">
                    <div class="flex items-center gap-1.5">
                      <span
                        v-if="inv.extraction_warning"
                        :title="t('purchase_invoice.extraction.needs_review_tooltip')"
                        class="inline-flex items-center justify-center w-4 h-4 rounded-full bg-warning-500/20 text-warning-600 flex-shrink-0"
                      >
                        <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                          <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z"/>
                        </svg>
                      </span>
                      <span
                        v-if="inv.has_small_asset"
                        :title="t('purchase_invoice.small_asset.badge_tooltip')"
                        class="inline-flex items-center justify-center w-4 h-4 rounded-full bg-primary-500/15 text-primary-600 flex-shrink-0"
                        role="img"
                        :aria-label="t('purchase_invoice.small_asset.badge_tooltip')"
                      >
                        <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                          <path stroke-linecap="round" stroke-linejoin="round" d="M20.25 7.5l-.625 10.632a2.25 2.25 0 01-2.247 2.118H6.622a2.25 2.25 0 01-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z"/>
                        </svg>
                      </span>
                      <span>{{ inv.vendor_invoice_number }}</span>
                    </div>
                  </td>
                  <td v-if="tbl.isVisible('kind')" class="px-4 py-2.5 text-center text-xs text-neutral-600">{{ t(`purchase_invoice.document_kind.${inv.document_kind}`) }}</td>
                  <td v-if="tbl.isVisible('tax_date')" class="px-4 py-2.5 text-center text-xs">
                    <span :class="taxDateClass(inv.tax_date, inv.issue_date)">{{ formatDate(inv.tax_date || inv.issue_date) }}</span>
                  </td>
                  <td v-if="tbl.isVisible('due_date')" class="px-4 py-2.5 text-center text-xs">
                    <span :class="isOverdue(inv.due_date, inv.status) ? 'text-danger-500 font-medium' : 'text-neutral-600'">
                      {{ formatDate(inv.due_date) }}
                    </span>
                  </td>
                  <td
                    v-if="tbl.isVisible('amount')"
                    class="amount-cell px-4 py-2.5 text-right font-mono font-semibold text-neutral-900"
                    :style="{ '--bar': amountBarWidth(inv, g) }"
                  >
                    {{ formatMoney(inv.total_with_vat, inv.currency) }}
                  </td>
                  <td v-if="tbl.isVisible('status')" class="px-4 py-2.5 text-center">
                    <span class="text-xs px-2 py-0.5 rounded" :class="statusBadgeClass(inv.status)">
                      {{ t(`purchase_invoice.status.${inv.status}`) }}
                    </span>
                    <div v-if="inv.payment_ordered_at" class="mt-1">
                      <span class="text-[10px] px-1.5 py-0.5 rounded bg-teal-50 text-teal-600 border border-teal-500/30 inline-flex items-center gap-0.5"
                        :title="t('purchase_invoice.payment_ordered_at_tooltip', { date: formatDate(inv.payment_ordered_at) })">
                        <svg class="w-2.5 h-2.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                        {{ t('purchase_invoice.payment_ordered_badge') }}
                      </span>
                    </div>
                  </td>
                  <td v-if="tbl.isVisible('paid_at')" class="px-4 py-2.5 text-center text-xs text-neutral-600">
                    <span v-if="inv.paid_at">{{ formatDate(inv.paid_at) }}</span>
                    <span v-else class="text-neutral-300">—</span>
                  </td>
                  <td v-if="tbl.isVisible('booked_at')" class="px-4 py-2.5 text-center text-xs text-neutral-600">
                    <span v-if="inv.booked_at">{{ formatDate(inv.booked_at) }}</span>
                    <span v-else class="text-neutral-300">—</span>
                  </td>
                  <td v-if="tbl.isVisible('exchange_rate')" class="px-4 py-2.5 text-right font-mono text-xs text-neutral-600">
                    <span v-if="inv.currency !== 'CZK' && inv.exchange_rate">{{ formatRate(inv.exchange_rate) }}</span>
                    <span v-else class="text-neutral-300">—</span>
                  </td>
                  <td v-if="tbl.isVisible('vat_deduction')" class="px-4 py-2.5 text-center text-xs text-neutral-600">
                    {{ vatDeductionLabel(inv) }}
                  </td>
                  <td v-if="tbl.isVisible('expense_category')" class="px-4 py-2.5 text-xs text-neutral-600">
                    <span v-if="inv.expense_category_label">{{ inv.expense_category_label }}</span>
                    <span v-else class="text-neutral-300">—</span>
                  </td>
                  <td v-if="tbl.isVisible('locked')" class="px-2 py-2.5 text-center">
                    <PostingBadge v-if="inv.locked?.journal_entry_id"
                      :booked-at="inv.booked_at" :journal-entry-id="inv.locked.journal_entry_id" />
                    <svg v-else-if="inv.locked?.is_locked" class="w-4 h-4 inline-block text-neutral-400"
                      fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"
                      role="img" :aria-label="lockTitle(inv)">
                      <title>{{ lockTitle(inv) }}</title>
                      <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25z" />
                    </svg>
                  </td>
                  <td class="px-1 py-2.5 w-8 text-center" @click.stop>
                    <button type="button"
                      class="cursor-pointer inline-flex h-6 w-6 items-center justify-center rounded text-neutral-400 transition-colors hover:bg-neutral-100 hover:text-neutral-700"
                      :aria-expanded="expandedId === inv.id"
                      :title="t('common.expand_items')"
                      @click="toggleExpand(inv)">
                      <svg class="h-4 w-4 transition-transform" :class="expandedId === inv.id ? 'rotate-90' : ''"
                        fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
                      </svg>
                    </button>
                  </td>
                </tr>

                <!-- Náhled položek dokladu. Neklikatelný řádek (žádné @click), aby
                     klik do náhledu neotevřel fakturu — uživatel si tu chce jen číst. -->
                <tr v-if="expandedId === inv.id" class="bg-neutral-50/60">
                  <td :colspan="expandedColspan" class="px-6 py-3">
                    <div v-if="expandedLoading" class="text-xs text-neutral-500">{{ t('common.loading') }}</div>
                    <div v-else-if="!expandedItems || expandedItems.length === 0" class="text-xs text-neutral-500">{{ t('common.no_data') }}</div>
                    <table v-else class="w-full text-xs table-plain">
                      <thead>
                        <tr class="text-neutral-500">
                          <th class="py-1 text-left font-medium">{{ t('purchase_invoice.items.description') }}</th>
                          <th class="py-1 text-right font-medium w-24">{{ t('purchase_invoice.items.quantity') }}</th>
                          <th class="py-1 text-right font-medium w-32">{{ t('purchase_invoice.items.unit_price') }}</th>
                          <th class="py-1 text-right font-medium w-32">{{ t('purchase_invoice.items.total_with_vat') }}</th>
                        </tr>
                      </thead>
                      <tbody>
                        <tr v-for="(item, ii) in expandedItems" :key="item.id ?? ii" class="border-t border-neutral-200/70">
                          <td class="py-1 pr-3 text-neutral-700">{{ item.description }}</td>
                          <td class="py-1 text-right font-mono tabular-nums whitespace-nowrap">{{ formatNumber(item.quantity) }} {{ item.unit }}</td>
                          <td class="py-1 text-right font-mono tabular-nums whitespace-nowrap">{{ formatMoney(item.unit_price_without_vat, inv.currency) }}</td>
                          <td class="py-1 text-right font-mono tabular-nums whitespace-nowrap font-semibold text-neutral-900">{{ formatMoney(item.total_with_vat ?? item.total_without_vat ?? 0, inv.currency) }}</td>
                        </tr>
                      </tbody>
                    </table>
                  </td>
                </tr>
                </template>
              </tbody>
            </table>
          </div>
        </div>

        <!-- Mobile: karty -->
        <div class="md:hidden bg-surface border border-t-0 border-neutral-200 rounded-b-lg divide-y divide-neutral-100 overflow-hidden">
          <div
            v-for="inv in g.invoices"
            :key="`m-${inv.id}`"
            draggable="true"
            :data-workspace-route="`/purchase-invoices/${inv.id}`"
            @click="openInvoice(inv, $event)"
            @auxclick.prevent="openInvoice(inv, $event)"
            class="cursor-pointer hover:bg-neutral-50 transition px-3 py-3"
            :class="[rowClass(inv), flashedIds.has(inv.id) ? 'row-flash' : '']"
          >
            <div class="flex items-start gap-3">
              <WorkspaceDragHandle />
              <input
                type="checkbox"
                :checked="selectedIds.includes(inv.id)"
                @change="toggleSelected(inv.id)"
                @click.stop
                class="w-5 h-5 mt-0.5 cursor-pointer rounded border-neutral-300 text-primary-600 focus:ring-2 focus:ring-primary-500/30"
              />
              <div class="flex-1 min-w-0">
                <div class="flex items-baseline justify-between gap-2">
                  <div class="font-medium text-neutral-900 truncate flex items-center gap-1.5">
                    <span
                      v-if="inv.extraction_warning"
                      :title="t('purchase_invoice.extraction.needs_review_tooltip')"
                      class="inline-flex items-center justify-center w-4 h-4 rounded-full bg-warning-500/20 text-warning-600 flex-shrink-0"
                    >
                      <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z"/>
                      </svg>
                    </span>
                    <span
                      v-if="inv.has_small_asset"
                      :title="t('purchase_invoice.small_asset.badge_tooltip')"
                      class="inline-flex items-center justify-center w-4 h-4 rounded-full bg-primary-500/15 text-primary-600 flex-shrink-0"
                      role="img"
                      :aria-label="t('purchase_invoice.small_asset.badge_tooltip')"
                    >
                      <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M20.25 7.5l-.625 10.632a2.25 2.25 0 01-2.247 2.118H6.622a2.25 2.25 0 01-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z"/>
                      </svg>
                    </span>
                    <span class="truncate">{{ inv.vendor_company_name }}</span>
                  </div>
                  <div class="font-mono text-sm whitespace-nowrap">
                    {{ formatMoney(inv.total_with_vat, inv.currency) }}
                  </div>
                </div>
                <div class="flex items-baseline justify-between gap-2 mt-1 text-xs text-neutral-500">
                  <div class="font-mono truncate">
                    <RouterLink class="row-link" :to="`/purchase-invoices/${inv.id}`" @click.stop @auxclick.stop>{{ inv.varsymbol || '#' + inv.id }}</RouterLink>
                    <span class="text-neutral-400"> · </span>
                    <span>{{ inv.vendor_invoice_number }}</span>
                  </div>
                  <span class="inline-flex items-center gap-1">
                    <PostingBadge v-if="inv.locked?.journal_entry_id"
                      :booked-at="inv.booked_at" :journal-entry-id="inv.locked.journal_entry_id" />
                    <svg v-else-if="inv.locked?.is_locked" class="w-3.5 h-3.5 text-neutral-400"
                      fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"
                      role="img" :aria-label="lockTitle(inv)">
                      <title>{{ lockTitle(inv) }}</title>
                      <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25z" />
                    </svg>
                    <span class="text-xs px-1.5 py-0.5 rounded whitespace-nowrap" :class="statusBadgeClass(inv.status)">
                      {{ t(`purchase_invoice.status.${inv.status}`) }}
                    </span>
                  </span>
                </div>
                <div v-if="inv.payment_ordered_at" class="mt-1">
                  <span class="text-[10px] px-1.5 py-0.5 rounded bg-teal-50 text-teal-600 border border-teal-500/30 inline-flex items-center gap-0.5">
                    <svg class="w-2.5 h-2.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                    {{ t('purchase_invoice.payment_ordered_badge') }}
                  </span>
                </div>
                <div class="flex items-center justify-between gap-2 mt-1 text-xs text-neutral-500">
                  <span :class="taxDateClass(inv.tax_date, inv.issue_date)">{{ formatDate(inv.tax_date || inv.issue_date) }}</span>
                  <span :class="isOverdue(inv.due_date, inv.status) ? 'text-danger-500 font-medium' : ''">
                    {{ t('purchase_invoice.fields.due_date') }}: {{ formatDate(inv.due_date) }}
                  </span>
                </div>
              </div>
            </div>
          </div>
        </div>
      </section>

      <div v-if="page < pages" class="text-center mt-3">
        <button @click="load(false)" :disabled="loadingMore"
          class="cursor-pointer h-10 px-5 text-sm bg-primary-600 hover:bg-primary-700 text-white font-medium disabled:opacity-50 rounded-md inline-flex items-center gap-2 shadow-sm">
          {{ loadingMore ? t('common.loading_more') : t('common.load_more') }}
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M19 14l-7 7m0 0l-7-7m7 7V3"/></svg>
        </button>
      </div>
    </div>
  </div>
</template>

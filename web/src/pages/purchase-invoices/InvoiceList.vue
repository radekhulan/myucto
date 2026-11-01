<script setup lang="ts">
import { useWindowRefresh } from '@/composables/useWindowRefresh'
import { ref, computed, onMounted, watch } from 'vue'
import ListLoadingSpinner from '@/components/ui/ListLoadingSpinner.vue'
import { useFillViewportHeight } from '@/composables/useFillViewportHeight'
import { useAdaptiveTableRows } from '@/composables/useAdaptiveTableRows'
import { useExpandableItems } from '@/composables/useExpandableItems'
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
import { formatMoney, formatMoneyWhole, formatDate, formatMonth, formatNumber, taxDateClass } from '@/composables/useFormat'
import { useRowLink } from '@/composables/useRowLink'
import { useListNavigationSource } from '@/composables/useListNavigation'
import { useToast } from '@/composables/useToast'
import { ACCOUNTING_PERIOD_MISSING_CODES, accountingPeriodRoute, apiErrorMessage } from '@/api/errors'
import { useYearOptions } from '@/composables/useYearOptions'
import TableSkeleton from '@/components/ui/TableSkeleton.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import FilterBar, { type FilterChip } from '@/components/ui/FilterBar.vue'
import BulkActionBar from '@/components/ui/BulkActionBar.vue'
import ExtractionReviewModal from '@/components/purchase/ExtractionReviewModal.vue'
import { markRowsTouched, consumeFlashedRows } from '@/composables/useRowFlash'
import { useListKeyboard } from '@/composables/useListKeyboard'
import { clientsApi, type Client } from '@/api/clients'
import { projectsApi, type Project } from '@/api/projects'
import SavedFiltersMenu from '@/components/ui/SavedFiltersMenu.vue'
import type { SavedFilter } from '@/api/preferences'
import ColumnPicker from '@/components/ui/ColumnPicker.vue'
import TableColorsMenu from '@/components/ui/TableColorsMenu.vue'
import { useTableColors } from '@/composables/useTableColors'
import { useColumnDrag } from '@/composables/useColumnDrag'
import ColumnDragHandle from '@/components/ui/ColumnDragHandle.vue'
import SortableTh from '@/components/ui/SortableTh.vue'
import DensityToggle from '@/components/ui/DensityToggle.vue'
import { useTablePrefs, type ColumnDef } from '@/composables/useTablePrefs'
import { useDimensions } from '@/composables/useDimensions'
import { needsReview } from '@/utils/purchaseReview'
import ApprovalStatusBadge from '@/components/purchase/ApprovalStatusBadge.vue'
import { approvalErrorMessage } from '@/api/purchaseApprovals'
import { useScrollLoadMore } from '@/composables/useScrollLoadMore'
import { ensurePrefsLoaded } from '@/composables/useUserPrefs'
import { useSavedFilters, savedFilterTone, type SavedFilterTone } from '@/composables/useSavedFilters'
import { ICONS, btnFilled, btnOutline, btnOutlineSm } from '@/components/ui/buttonStyles'
import JournalSourceDrawer from '@/components/accounting/JournalSourceDrawer.vue'
import PostingBadge from '@/components/ui/PostingBadge.vue'
import VatBreakdownCell from '@/components/ui/VatBreakdownCell.vue'
import { useSupplierStore } from '@/stores/supplier'
import { accountingApi, postingErrorI18nKey } from '@/api/accounting'
import WorkspaceDragHandle from '@/components/workspace/WorkspaceDragHandle.vue'
import { isInvoiceDateOverdue } from '@/utils/invoiceOverdue'
import DateInput from '@/components/ui/DateInput.vue'

const { t, locale } = useI18n()
const auth = useAuthStore()
const supplierStore = useSupplierStore()
const dimensions = useDimensions()
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
const loadMoreTarget = ref<HTMLElement | null>(null)
useScrollLoadMore(loadMoreTarget, () => !loading.value && !loadingMore.value && page.value < pages.value, () => load(false))
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
const yearFilter = ref<number | ''>('')
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
// „Uhrazeno s rozdílem": uhrazený doklad, který evidované úhrady nepokrývají.
const paidShortfallOnly = ref(false)
const needsReviewOnly = ref(false)
// Schvalování manažerem střediska (F6): '' = vše.
const approvalFilter = ref<'' | 'pending' | 'approved' | 'rejected'>('')
const paymentOrderedFilter = ref<'' | '1' | '0'>('')
// Zaúčtováno/nezaúčtováno (0.9) — jen podvojné účetnictví. '' = vše, '1' = zaúčtováno, '0' = nezaúčtováno.
const bookedFilter = ref<'' | '1' | '0'>('')
const structuredSourceFilter = ref<'' | '1' | '0'>('')
const visualDocumentFilter = ref<'' | '1' | '0'>('')
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
  if (paidShortfallOnly.value) n++
  if (needsReviewOnly.value) n++
  if (approvalFilter.value) n++
  if (paymentOrderedFilter.value) n++
  if (bookedFilter.value) n++
  if (structuredSourceFilter.value) n++
  if (visualDocumentFilter.value) n++
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
  if (paidShortfallOnly.value) chips.push({ key: 'paidShortfall', value: t('purchase_invoice.filters.paid_shortfall') })
  if (needsReviewOnly.value) chips.push({ key: 'needsReview', value: t('purchase_invoice.filters.needs_review') })
  if (approvalFilter.value) chips.push({ key: 'approval', value: t(`purchase_approval.filter.${approvalFilter.value}`) })
  if (paymentOrderedFilter.value) {
    chips.push({ key: 'paymentOrdered', value: t(paymentOrderedFilter.value === '1' ? 'purchase_invoice.filters.payment_ordered_yes' : 'purchase_invoice.filters.payment_ordered_no') })
  }
  if (bookedFilter.value) {
    chips.push({ key: 'booked', value: t(bookedFilter.value === '1' ? 'common.booked_badge' : 'common.unbooked_badge') })
  }
  if (visualDocumentFilter.value) chips.push({ key: 'has_visual_document', value: `${t('purchase_invoice.filters.has_visual_document')}: ${t('purchase_invoice.filters.document_' + (visualDocumentFilter.value === '1' ? 'present' : 'missing'))}` })
  if (structuredSourceFilter.value) chips.push({ key: 'has_structured_source', value: `${t('purchase_invoice.filters.has_structured_source')}: ${t('purchase_invoice.filters.document_' + (structuredSourceFilter.value === '1' ? 'present' : 'missing'))}` })
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
    case 'paidShortfall': paidShortfallOnly.value = false; break
    case 'needsReview': needsReviewOnly.value = false; break
    case 'approval': approvalFilter.value = ''; break
    case 'paymentOrdered': paymentOrderedFilter.value = ''; break
    case 'booked': bookedFilter.value = ''; break
    case 'has_structured_source': structuredSourceFilter.value = ''; break
    case 'has_visual_document': visualDocumentFilter.value = ''; break
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

// Kontrola AI vytěžených dokladů faktura po faktuře — vybrané řádky, jinak vše načtené s hlášením.
const reviewIds = ref<number[] | null>(null)
const reviewableIds = computed(() => {
  const flagged = flatRows.value.filter(r => needsReview(r))
  const selected = new Set(selectedIds.value)
  const pick = selected.size ? flagged.filter(r => selected.has(r.id)) : flagged
  return pick.map(r => r.id)
})
function onReviewClosed() {
  reviewIds.value = null
  load()
}
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
const { expandedId, expandedItems, expandedLoading, toggleItems, clearExpandedItems } = useExpandableItems<PurchaseInvoiceItem>(
  id => purchaseInvoicesApi.items(id),
  () => supplierStore.currentSupplier?.id,
)
const previewInvoiceId = ref<number | null>(null)

/**
 * Šířka rozbaleného řádku = počet viditelných sloupců + zaškrtávátko + rozbalovací
 * tlačítko. Napevno zadaná hodnota by se rozešla s ColumnPickerem, kterým si
 * uživatel sloupce zapíná a vypíná.
 */
const expandedColspan = computed(() => COLUMNS.filter(c => tbl.isVisible(c.key)).length + 2)

function toggleExpand(inv: PurchaseInvoiceListItem) {
  return toggleItems(inv.id)
}

let searchTimeout: ReturnType<typeof setTimeout> | null = null

// Sync filtrů s URL query: filter se zapíše do ?param=value formy, abychom mohli
// detekovat menu click (= URL bez query → reset). Pro klika na menu link
// "Přijaté faktury" už když je na této stránce a má aktivní filtr (např. overdue=1)
// se URL změní zpět na čistou — watch fires reset všech ref.
const DEFAULT_YEAR: number | '' = ''

const COLUMNS: ColumnDef[] = [
  { key: 'number', labelKey: 'purchase_invoice.fields.varsymbol', required: true },
  { key: 'vendor', labelKey: 'purchase_invoice.fields.vendor', required: true },
  { key: 'vendor_ic', labelKey: 'common.ic' },
  { key: 'vendor_number', labelKey: 'purchase_invoice.col_vendor_number' },
  { key: 'kind', labelKey: 'purchase_invoice.fields.document_kind' },
  { key: 'issue_date', labelKey: 'purchase_invoice.fields.issue_date', defaultHidden: true },
  { key: 'tax_date', labelKey: 'purchase_invoice.fields.tax_date' },
  { key: 'due_date', labelKey: 'purchase_invoice.fields.due_date' },
  { key: 'amount', labelKey: 'purchase_invoice.totals.with_vat', required: true },
  { key: 'status', labelKey: 'purchase_invoice.list_status' },
  { key: 'paid_amount', labelKey: 'purchase_invoice.col_paid_amount', defaultHidden: true },
  { key: 'remaining_amount', labelKey: 'purchase_invoice.col_remaining_amount' },
  // Doplňkové sloupce — defaultně skryté, uživatel si je zapne přes ColumnPicker.
  { key: 'paid_at', labelKey: 'invoice.col_paid_at', defaultHidden: true },
  { key: 'booked_at', labelKey: 'invoice.col_booked_at', defaultHidden: true },
  { key: 'exchange_rate', labelKey: 'invoice.col_exchange_rate', defaultHidden: true },
  { key: 'vat_deduction', labelKey: 'purchase_invoice.col_vat_deduction', defaultHidden: true },
  { key: 'expense_category', labelKey: 'purchase_invoice.classification.expense_category', defaultHidden: true },
  { key: 'base', labelKey: 'invoice.col_base', defaultHidden: true },
  { key: 'vat', labelKey: 'invoice.col_vat', defaultHidden: true },
  { key: 'balance', labelKey: 'invoice.amount_to_pay', defaultHidden: true },
  { key: 'project', labelKey: 'invoice.col_project', defaultHidden: true },
  { key: 'received_at', labelKey: 'purchase_invoice.col_received_at', defaultHidden: true },
  { key: 'payment_ordered_at', labelKey: 'purchase_invoice.col_payment_ordered_at', defaultHidden: true },
  { key: 'vat_breakdown', labelKey: 'invoice.col_vat_breakdown', defaultHidden: true },
  { key: 'debit_accounts', labelKey: 'invoice.col_debit_accounts', defaultHidden: true, available: () => auth.canRead('accounting') },
  { key: 'credit_accounts', labelKey: 'invoice.col_credit_accounts', defaultHidden: true, available: () => auth.canRead('accounting') },
  { key: 'country', labelKey: 'invoice.col_country', defaultHidden: true },
  { key: 'vat_classification', labelKey: 'invoice.col_vat_classification', defaultHidden: true },
  { key: 'vat_return_lines', labelKey: 'invoice.col_vat_return_lines', defaultHidden: true },
  { key: 'document_tags', labelKey: 'invoice.col_document_tags', defaultHidden: true, available: () => auth.canRead('documents') },
  { key: 'note', labelKey: 'invoice.col_note', defaultHidden: true },
  { key: 'journal_notes', labelKey: 'invoice.col_journal_notes', defaultHidden: true, available: () => auth.canRead('accounting') },
  { key: 'payment_vs', labelKey: 'invoice.col_payment_vs', defaultHidden: true },
  { key: 'payment_method', labelKey: 'invoice.col_payment_method', defaultHidden: true },
  { key: 'kh', labelKey: 'invoice.col_kh', defaultHidden: true },
  { key: 'dimensions', labelKey: 'dimensions.title', defaultHidden: true, available: () => dimensions.enabled.value },
  { key: 'locked', labelKey: 'lock.column' },
]
const tbl = useTablePrefs('purchase_invoices', COLUMNS)
const colors = useTableColors('purchase_invoices')
const columnDrag = useColumnDrag(tbl)
const COLUMN_PRESETS = [
  { key: 'client', labelKey: 'common.columns_preset_client', visibleKeys: null },
  { key: 'accountant', labelKey: 'common.columns_preset_accountant', visibleKeys: [
    'number', 'vendor', 'vendor_ic', 'vendor_number', 'tax_date', 'amount', 'remaining_amount',
    'vat_deduction', 'vat_breakdown', 'debit_accounts', 'credit_accounts', 'kh', 'locked',
  ] },
  { key: 'complete', labelKey: 'common.columns_preset_complete', visibleKeys: COLUMNS.map(c => c.key) },
]
function onListScroll(event: Event) {
  const el = event.currentTarget as HTMLElement
  if (!groupByMonth.value && el.scrollTop + el.clientHeight >= el.scrollHeight - 240
    && !loading.value && !loadingMore.value && page.value < pages.value) void load(false)
}
function extraFieldValue(inv: PurchaseInvoiceListItem, key: string): string {
  return mobileExtraFields(inv).find(field => field.key === key)?.value ?? '—'
}
function mobileExtraFields(inv: PurchaseInvoiceListItem): Array<{ key: string; label: string; value: string }> {
  const values: Record<string, string> = {
    country: inv.country || '—',
    vat_classification: inv.vat_classification_codes?.join(', ') || '—',
    vat_return_lines: inv.vat_return_lines?.join(', ') || '—',
    document_tags: inv.document_tags?.join(', ') || '—',
    note: [inv.note_above_items, inv.note_below_items].filter(Boolean).join(' · ') || '—',
    journal_notes: inv.journal_notes?.join(' · ') || '—',
    payment_vs: inv.payment_variable_symbol || '—',
    payment_method: t(`payment_method.${inv.payment_method || 'bank_transfer'}`),

    paid_at: inv.paid_at ? formatDate(inv.paid_at) : '—',
    booked_at: inv.booked_at ? formatDate(inv.booked_at) : '—',
    exchange_rate: inv.currency !== 'CZK' && inv.exchange_rate ? formatRate(inv.exchange_rate) : '—',
    vat_deduction: vatDeductionLabel(inv),
    expense_category: inv.expense_category_label || '—',
    base: formatMoney(inv.total_without_vat, inv.currency),
    vat: formatMoney(inv.total_vat, inv.currency),
    balance: formatMoney(inv.amount_to_pay, inv.currency),
    project: inv.project_name || '—',
    received_at: inv.received_at ? formatDate(inv.received_at) : '—',
    payment_ordered_at: inv.payment_ordered_at ? formatDate(inv.payment_ordered_at) : '—',
    kh: inv.kh_sections?.join(', ') || '—',
    debit_accounts: inv.debit_accounts?.join(', ') || '—',
    credit_accounts: inv.credit_accounts?.join(', ') || '—',
    dimensions: inv.dimension_labels?.join(' · ') || '—',
  }
  return tbl.orderedColumns.value.filter(c => c.defaultHidden && c.key !== 'vat_breakdown' && tbl.isVisible(c.key))
    .map(c => ({ key: c.key, label: t(c.labelKey), value: values[c.key] ?? '—' }))
}
watch(() => [tbl.isVisible('kh'), tbl.isVisible('vat_classification'), tbl.isVisible('vat_return_lines'), tbl.isVisible('document_tags'), tbl.isVisible('journal_notes'), tbl.isVisible('vat_breakdown'), tbl.isVisible('debit_accounts'), tbl.isVisible('credit_accounts'), tbl.isVisible('dimensions')], () => { if (groups.value.length) load() })
const groupByMonth = computed(() => tbl.flag('group_by_month', true))
const listBoxes = ref<HTMLElement[]>([])
const adaptiveRowsEnabled = computed(() => COLUMNS.some(c => c.available?.() !== false
  && tbl.isVisible(c.key) !== (c.required === true || !c.defaultHidden)))
useAdaptiveTableRows(listBoxes, adaptiveRowsEnabled)
const listBox = computed(() => (groupByMonth.value ? null : listBoxes.value[0] ?? null))
useFillViewportHeight(listBox)
function toggleGrouping() {
  tbl.setFlag('group_by_month', !groupByMonth.value)
  load()
}
function onSortToggle(key: string) {
  tbl.toggleSort(key)
  load()
}
function clearSort() {
  tbl.clearSort()
  load()
}

// Úhrada dává smysl jen u platného dokladu, koncept a storno nic nedluží.
function showsPayment(inv: PurchaseInvoiceListItem): boolean {
  return inv.status !== 'draft' && inv.status !== 'cancelled'
}

// „Uhrazeno s rozdílem": doklad je uhrazený, ale evidované úhrady ho nepokrývají.
// Hranici (víc než koruna) drží BE, stejnou jako saldo a uzávěrková kontrola.
function hasPaidShortfall(inv: PurchaseInvoiceListItem): boolean {
  return !!inv.paid_shortfall
}

function remainingClass(inv: PurchaseInvoiceListItem): string {
  if (!showsPayment(inv)) return ''
  if (hasPaidShortfall(inv)) return 'text-warning-700 font-semibold'
  return inv.status !== 'paid' && (inv.remaining_amount ?? 0) > 0.005 ? 'text-neutral-900' : 'text-neutral-400'
}

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
  await ensurePrefsLoaded()
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
  paidShortfallOnly.value = q.paid_shortfall === '1' || q.paid_shortfall === 'true'
  needsReviewOnly.value = q.needs_review === '1' || q.needs_review === 'true'
  approvalFilter.value = q.approval_status === 'pending' || q.approval_status === 'approved' || q.approval_status === 'rejected'
    ? q.approval_status : ''
  paymentOrderedFilter.value = q.payment_ordered === '1' ? '1' : (q.payment_ordered === '0' ? '0' : '')
  bookedFilter.value = q.booked === '1' ? '1' : (q.booked === '0' ? '0' : '')
  structuredSourceFilter.value = q.has_structured_source === '1' ? '1' : (q.has_structured_source === '0' ? '0' : '')
  visualDocumentFilter.value = q.has_visual_document === '1' ? '1' : (q.has_visual_document === '0' ? '0' : '')
  statusFilter.value = typeof q.status === 'string' ? (q.status as PurchaseInvoiceStatus) : ''
  kindFilter.value   = typeof q.kind === 'string' ? (q.kind as PurchaseDocumentKind) : ''
  yearFilter.value   = typeof q.year === 'string' && q.year !== ''
    ? (q.year === 'all' ? '' : Number(q.year))
    : ((overdueOnly.value || unpaidOnly.value || unpaidAsOf.value || unmatchedOnly.value || paidShortfallOnly.value || bookedFilter.value === '0' || approvalFilter.value) ? '' : DEFAULT_YEAR)
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
  // Výchozí jsou všechny roky a do URL nepatří; starý odkaz year=all se čte dál.
  if (yearFilter.value !== DEFAULT_YEAR) q.year = String(yearFilter.value)
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
  if (paidShortfallOnly.value) q.paid_shortfall = '1'
  if (needsReviewOnly.value) q.needs_review = '1'
  if (approvalFilter.value) q.approval_status = approvalFilter.value
  if (paymentOrderedFilter.value) q.payment_ordered = paymentOrderedFilter.value
  if (bookedFilter.value) q.booked = bookedFilter.value
  if (structuredSourceFilter.value) q.has_structured_source = structuredSourceFilter.value
  if (visualDocumentFilter.value) q.has_visual_document = visualDocumentFilter.value
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
       overdueOnly, unpaidOnly, unpaidAsOf, unmatchedOnly, paidShortfallOnly, needsReviewOnly, approvalFilter, paymentOrderedFilter, bookedFilter, structuredSourceFilter, visualDocumentFilter,
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
    paidShortfallOnly.value = false
    needsReviewOnly.value = false
    approvalFilter.value = ''
    paymentOrderedFilter.value = ''
    bookedFilter.value = ''
    structuredSourceFilter.value = ''
    visualDocumentFilter.value = ''
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
  return Array.from(byMonth.values())
}

/**
 * Načtení seznamu. Víc spouštěčů v jednom okamžiku (filtry z URL při otevření stránky
 * a hned na to watcher týchž filtrů, použití uloženého filtru) = jeden požadavek.
 * Odpověď, kterou mezitím předběhl novější požadavek, se zahodí, aby starší data
 * nepřepsala novější.
 */
let loadSeq = 0
let pendingReload: Promise<void> | null = null
function load(reset = true): Promise<void> {
  if (!reset) return fetchPage(false)
  pendingReload ??= Promise.resolve().then(() => {
    pendingReload = null
    return fetchPage(true)
  })
  return pendingReload
}

async function fetchPage(reset: boolean) {
  const seq = ++loadSeq
  if (reset) {
    clearExpandedItems()
    // Hledaný text je v tomto načtení, odložené hledání z téhož textu by načítalo znovu.
    if (searchTimeout) clearTimeout(searchTimeout)
    searchTimeout = null
    loading.value = true
    page.value = 1
    selectedIds.value = []
  } else {
    loadingMore.value = true
    page.value++
  }
  error.value = ''
  try {
    const params = listParams()
    loadedParams = params
    const res = await purchaseInvoicesApi.listGrouped({ ...params, page: page.value })
    if (seq !== loadSeq) return
    if (reset) {
      groups.value = res.data
    } else {
      groups.value = mergeGroups(groups.value, res.data)
    }
    total.value = res.meta.total
    pages.value = res.meta.pages ?? 1
  } catch (e) {
    if (seq !== loadSeq) return
    if (reset) {
      error.value = apiErrorMessage(e)
    } else {
      page.value--
      toast.error(apiErrorMessage(e))
    }
  } finally {
    if (seq === loadSeq) {
      loading.value = false
      loadingMore.value = false
      flashedIds.value = consumeFlashedRows('purchase_invoice')
    }
  }
}

// Obnova po změně dat jinde: skeleton a návrat na první stránku by uživateli vzaly
// pozici v seznamu, rozbalené řádky i dočtené stránky. Bez mazání se přepíše obsah.
const REFRESH_PAGE_LIMIT = 5
async function refreshLoadedPages() {
  const seq = loadSeq
  const count = Math.min(Math.max(page.value, 1), REFRESH_PAGE_LIMIT)
  const params = listParams()
  const results = await Promise.all(Array.from({ length: count }, (_, index) => purchaseInvoicesApi.listGrouped({ ...params, page: index + 1 })))
  if (seq !== loadSeq) return
  groups.value = results.slice(1).reduce((merged, result) => mergeGroups(merged, result.data), results[0]!.data)
  page.value = count
  total.value = results[0]!.meta.total
  pages.value = results[0]!.meta.pages ?? 1
  flashedIds.value = consumeFlashedRows('purchase_invoice')
}

// Parametry dotazu seznamu bez čísla stránky. Stejné parametry použije detail
// při listování za hranici načtených řádků (useListNavigation).
type PurchaseListParams = NonNullable<Parameters<typeof purchaseInvoicesApi.listGrouped>[0]>
let loadedParams: PurchaseListParams = {}
function listParams(): PurchaseListParams {
  return {
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
    paid_shortfall: paidShortfallOnly.value || undefined,
    needs_review:  needsReviewOnly.value || undefined,
    approval_status: approvalFilter.value || undefined,
    payment_ordered: paymentOrderedFilter.value || undefined,
    booked:        bookedFilter.value  || undefined,
    has_structured_source: structuredSourceFilter.value || undefined,
    has_visual_document: visualDocumentFilter.value || undefined,
    import_batch_id: importBatchFilter.value || undefined,
    q:             search.value       || undefined,
    per_page: 50,
    sort_key: tbl.sort.value?.key,
    sort_dir: tbl.sort.value?.dir,
    group_by_month: groupByMonth.value,
    include_kh: tbl.isVisible('kh'),
    include_vat_classification: tbl.isVisible('vat_classification') || tbl.isVisible('vat_return_lines'),
    include_journal_notes: auth.canRead('accounting') && tbl.isVisible('journal_notes'),
    include_document_tags: auth.canRead('documents') && tbl.isVisible('document_tags'),
    include_vat_breakdown: tbl.isVisible('vat_breakdown'),
    include_posting_accounts: tbl.isVisible('debit_accounts') || tbl.isVisible('credit_accounts'),
    include_dimensions: tbl.isVisible('dimensions'),
    // Koncept bez povinné dimenze patří do kontroly vytěžení stejně jako doklad s hlášením.
    include_review: dimensions.enabled.value,
  }
}
const listNav = useListNavigationSource('purchase-invoices', () => ({
  ids: flatRows.value.map(inv => inv.id),
  page: page.value,
  pages: pages.value,
  params: loadedParams as Record<string, unknown>,
}), () => route.fullPath, () => supplierStore.currentSupplierId)

const navigateRow = useRowLink()
function openInvoice(inv: PurchaseInvoiceListItem, e?: MouseEvent) {
  listNav.markOpen(inv.id)
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
  return isInvoiceDateOverdue(dueDate)
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
  if (inv.status === 'cancelled') return colors.hasColors.value ? '' : 'opacity-60'
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

function isGroupSelected(group: PurchaseMonthGroup): boolean {
  return group.invoices.length > 0 && group.invoices.every(invoice => selectedIds.value.includes(invoice.id))
}

function isGroupSelectionPartial(group: PurchaseMonthGroup): boolean {
  const count = group.invoices.filter(invoice => selectedIds.value.includes(invoice.id)).length
  return count > 0 && count < group.invoices.length
}

function toggleGroupSelected(group: PurchaseMonthGroup) {
  const groupIds = group.invoices.map(invoice => invoice.id)
  const selected = new Set(selectedIds.value)
  if (groupIds.every(id => selected.has(id))) {
    selectedIds.value = selectedIds.value.filter(id => !groupIds.includes(id))
    return
  }
  for (const id of groupIds) selected.add(id)
  selectedIds.value = Array.from(selected)
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

function kindOf(id: number): string | null {
  for (const g of groups.value) {
    const f = g.invoices.find(i => i.id === id)
    if (f) return f.document_kind
  }
  return null
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
  // DDKP se neplatí, úhrada patří na zálohu.
  return (s === 'received' || s === 'booked') && !lockedById(id) && kindOf(id) !== 'tax_document'
}))
const cancellableSelected = computed(() => selectedIds.value.filter(id => {
  const s = statusOf(id); return s && s !== 'cancelled'
}))

function issueOrderKey(id: number): string {
  for (const g of groups.value) {
    const f = g.invoices.find(i => i.id === id)
    if (f) return `${f.issue_date ?? '9999-99-99'}#${String(id).padStart(12, '0')}`
  }
  return `9999-99-99#${String(id).padStart(12, '0')}`
}

async function bulkTransition(target: PurchaseInvoiceStatus, ids: number[]) {
  if (ids.length === 0 || bulkBusy.value) return
  if (target === 'cancelled' && !confirm(t('purchase_invoice.bulk.confirm_cancel', { n: ids.length }))) return
  bulkBusy.value = true
  // Probliknout jen doklady, kterým přechod opravdu prošel — u částečného
  // selhání by záblesk celého výběru tvrdil něco, co se nestalo.
  const done: number[] = []
  let fail = 0
  let sentToApproval = 0
  let lastError: unknown = null
  // Přijetí přiděluje interní číslo, takže se doklady přijímají v pořadí podle data
  // vystavení, ne podle pořadí zaškrtnutí — čísla v měsíci pak jdou chronologicky.
  const ordered = target === 'received' ? [...ids].sort((a, b) => issueOrderKey(a).localeCompare(issueOrderKey(b))) : ids
  for (const id of ordered) {
    try {
      const res = await purchaseInvoicesApi.transition(id, target)
      done.push(id)
      if (res.approval_requested === true) sentToApproval++
    } catch (e) { fail++; lastError = e }
  }
  bulkBusy.value = false
  if (fail === 0) {
    toast.success(t('purchase_invoice.bulk.success', { n: done.length - sentToApproval }))
  } else {
    toast.error(t('purchase_invoice.bulk.partial', { ok: done.length, fail }))
  }
  // Doklady, které vyžadují schválení, zůstaly konceptem; chyba `approval_no_approver` se vysvětlí jménem střediska.
  if (sentToApproval > 0) toast.info(t('purchase_approval.toast.requested') + ` (${sentToApproval})`)
  if (fail > 0 && lastError) toast.error(approvalErrorMessage(lastError, t))
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
      // Viz vydané faktury: chybějící období se řeší jinde než na seznamu dokladů,
      // takže hláška musí nést i cestu tam.
      const missingPeriod = r.failed.some(f =>
        (ACCOUNTING_PERIOD_MISSING_CODES as readonly string[]).includes(f.error_code))
      toast.warning(
        t('purchase_invoice.bulk.post_partial', { ok: r.posted.length, err: r.failed.length }) + '\n' + detail,
        missingPeriod
          ? {
              label: t('accounting.posting_errors.open_periods_action'),
              handler: () => void router.push(accountingPeriodRoute()),
            }
          : undefined,
      )
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
useWindowRefresh(refreshLoadedPages,
  () => !loading.value && !loadingMore.value && !bulkBusy.value && selectedIds.value.length === 0)
</script>

<template>
  <div>
    <!-- ═══ Topbar: title + bulk actions + new ═══ -->
    <div class="flex items-center justify-between mb-4 gap-3 flex-wrap">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('purchase_invoice.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5">
          {{ t('purchase_invoice.subtitle') }}
          <template v-if="!loading && total > 0">
            <span class="ml-2 whitespace-nowrap">· {{ t('purchase_invoice.summary_count', { count: total }) }}</span>
            <span v-if="loadedCount < total" class="ml-2 whitespace-nowrap">· {{ t('common.loaded_count', { loaded: loadedCount, total }) }}</span>
          </template>
        </p>
      </div>

      <div class="flex items-center gap-2 flex-wrap">
        <template v-if="vendorFilter !== '' && !auth.isClientRole && auth.canRead('clients')">
          <RouterLink :to="{ name: 'client-detail', params: { id: vendorFilter } }" :class="btnOutline('neutral')" data-test="filter-vendor-detail">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.eye" /></svg>
            {{ t('purchase_invoice.filters.vendor_detail') }}
          </RouterLink>
          <RouterLink v-if="auth.canWrite('clients')" :to="{ name: 'client-edit', params: { id: vendorFilter } }" :class="btnOutline('neutral')" data-test="filter-vendor-edit">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.edit" /></svg>
            {{ t('purchase_invoice.filters.vendor_edit') }}
          </RouterLink>
        </template>
        <button v-if="reviewableIds.length && auth.canWrite('purchase_invoices')" type="button"
          :class="btnOutline('warning')" class="whitespace-nowrap" @click="reviewIds = reviewableIds">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5 2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
          {{ t('purchase_invoice.extraction_review.open_count', { count: reviewableIds.length }) }}
        </button>
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
        <DateInput v-model="dateFrom" :placeholder="t('common.from')"
          class="h-9 px-2 border border-neutral-300 rounded-md text-sm" :title="t('common.from')" />
        <DateInput v-model="dateTo" :placeholder="t('common.to')"
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
          <DateInput v-model="unpaidAsOf"
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
        <label class="flex items-center gap-1.5 text-sm text-warning-700 px-2"
          :title="t('purchase_invoice.filters.paid_shortfall_hint')">
          <input v-model="paidShortfallOnly" type="checkbox" class="rounded border-neutral-300 text-warning-600" />
          {{ t('purchase_invoice.filters.paid_shortfall') }}
        </label>
        <label class="flex items-center gap-1.5 text-sm text-warning-700 px-2">
          <input v-model="needsReviewOnly" type="checkbox" class="rounded border-neutral-300 text-warning-600" />
          {{ t('purchase_invoice.filters.needs_review') }}
        </label>
        <select v-if="dimensions.enabled.value || approvalFilter" v-model="approvalFilter" data-test="approval-filter"
          class="h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm"
          :title="t('purchase_approval.filter.label')">
          <option value="">{{ t('purchase_approval.filter.all') }}</option>
          <option value="pending">{{ t('purchase_approval.filter.pending') }}</option>
          <option value="approved">{{ t('purchase_approval.filter.approved') }}</option>
          <option value="rejected">{{ t('purchase_approval.filter.rejected') }}</option>
        </select>
        <select v-model="paymentOrderedFilter" class="h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm"
          :title="t('purchase_invoice.filters.payment_ordered')">
          <option value="">{{ t('purchase_invoice.filters.payment_ordered_all') }}</option>
          <option value="1">{{ t('purchase_invoice.filters.payment_ordered_yes') }}</option>
          <option value="0">{{ t('purchase_invoice.filters.payment_ordered_no') }}</option>
        </select>
        <fieldset data-test="document-sources-filters"
          class="relative min-w-0 max-w-full rounded-md border border-neutral-200 px-2 pt-2.5 pb-1">
          <legend class="sr-only">{{ t('purchase_invoice.filters.document_sources') }}</legend>
          <span aria-hidden="true" class="pointer-events-none absolute -top-2 left-2 bg-surface px-1 text-xs leading-4 font-medium text-neutral-500">
            {{ t('purchase_invoice.filters.document_sources') }}
          </span>
          <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
            <label class="flex flex-wrap items-center gap-1.5 text-sm text-neutral-700"
              :title="t('purchase_invoice.filters.has_visual_document_hint')">
              {{ t('purchase_invoice.filters.has_visual_document') }}
              <select v-model="visualDocumentFilter" data-test="has_visual_document-filter"
                class="h-[calc(2.25rem-2px)] px-3 border border-neutral-300 rounded-md bg-surface text-sm">
                <option value="">{{ t('purchase_invoice.filters.document_all') }}</option>
                <option value="1">{{ t('purchase_invoice.filters.document_present') }}</option>
                <option value="0">{{ t('purchase_invoice.filters.document_missing') }}</option>
              </select>
            </label>
            <label class="flex flex-wrap items-center gap-1.5 text-sm text-neutral-700"
              :title="t('purchase_invoice.filters.has_structured_source_hint')">
              {{ t('purchase_invoice.filters.has_structured_source') }}
              <select v-model="structuredSourceFilter" data-test="has_structured_source-filter"
                class="h-[calc(2.25rem-2px)] px-3 border border-neutral-300 rounded-md bg-surface text-sm">
                <option value="">{{ t('purchase_invoice.filters.document_all') }}</option>
                <option value="1">{{ t('purchase_invoice.filters.document_present') }}</option>
                <option value="0">{{ t('purchase_invoice.filters.document_missing') }}</option>
              </select>
            </label>
          </div>
        </fieldset>
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
        <button type="button" :class="btnOutline('neutral')" @click="toggleGrouping">
          <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16" /></svg>
          {{ groupByMonth ? t('invoice.view_continuous') : t('invoice.view_monthly') }}
        </button>
        <ColumnPicker :ctrl="tbl" :presets="COLUMN_PRESETS" reorderable />
        <TableColorsMenu :ctrl="tbl" :colors="colors" />
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
      <EmptyState v-if="search || activeFilterCount" variant="filtered"
        :title="t('purchase_invoice.empty_filtered')"
        :cta="t('common.empty_state.clear_filters')" @action="clearFiltersAndSearch" />
      <EmptyState v-else icon="inbox"
        :title="auth.isClientRole ? t('purchase_invoice.empty_client') : t('purchase_invoice.empty')"
        :cta="auth.canWrite('purchase_invoices.create') || auth.isDemo ? t('purchase_invoice.new') : undefined"
        to="/purchase-invoices/new" />
    </div>

    <div v-else>

      <!-- ═══ Skupiny po měsících ═══ -->
      <section v-for="g in groups" :key="g.month" :class="groupByMonth ? 'mb-5' : ''">
        <!-- Měsíční rozdělovník ve stylu účetní knihy — stejný vzor jako u vydaných
             faktur: název měsíce vlevo, hairline přes volné místo, součet v mono
             vpravo. Součty se musí umět zalomit, jinak by na mobilu vytlačily stránku. -->
        <header v-if="groupByMonth" class="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 bg-neutral-50/92 border border-neutral-200 rounded-t-lg px-3 py-2.5 mb-0">
          <div class="flex items-baseline gap-2.5 shrink-0">
            <h2 class="text-[13px] font-semibold uppercase tracking-[0.16em] text-neutral-800">{{ formatMonth(g.month) }}</h2>
            <span class="text-[11px] text-neutral-500 tabular-nums">{{ g.count }}</span>
          </div>
          <span class="hidden sm:block flex-1 h-px bg-gradient-to-r from-neutral-200 to-transparent" aria-hidden="true"></span>
          <div class="flex flex-wrap items-center justify-end gap-x-3 gap-y-0.5 min-w-0 text-xs">
            <span v-for="tc in g.totals_per_currency" :key="tc.currency" class="font-mono">
              <span class="text-neutral-500">{{ tc.currency }}:</span>
              <span class="font-semibold text-neutral-900 ml-1">{{ formatMoneyWhole(tc.with_vat, tc.currency) }}</span>
            </span>
          </div>
        </header>

        <!-- Desktop: tabulka -->
        <div class="hidden md:block bg-surface border border-neutral-200" :class="groupByMonth ? 'border-t-0 rounded-b-lg' : 'rounded-lg'">
          <div ref="listBoxes" class="overflow-auto scrollbar-slim" @scroll.passive="onListScroll">
            <table v-column-labels="tbl" class="w-max min-w-full text-sm table-sticky-first singleline-list-table list-stacked-controls" :class="tbl.densityClass.value">
              <thead class="bg-neutral-50 text-neutral-500 text-xs uppercase tracking-wide" :class="groupByMonth ? '' : 'sticky top-0 z-20 shadow-sm'">
                <tr>
                  <th class="px-2 py-2 w-10 text-center">
                    <input
                      type="checkbox"
                      :checked="isGroupSelected(g)"
                      :indeterminate="isGroupSelectionPartial(g)"
                      @change="toggleGroupSelected(g)"
                      :title="groupByMonth ? t('invoice.select_month', { month: formatMonth(g.month) }) : t('common.select_all')"
                      :aria-label="groupByMonth ? t('invoice.select_month', { month: formatMonth(g.month) }) : t('common.select_all')"
                      class="w-4 h-4 cursor-pointer rounded border-neutral-300 text-primary-600 focus:ring-2 focus:ring-primary-500/30"
                    />
                  </th>
                  <template v-for="c in tbl.orderedColumns.value.filter(c => tbl.isVisible(c.key))" :key="c.key">
                    <th v-if="['kh', 'dimensions', 'vat_classification', 'vat_return_lines', 'document_tags'].includes(c.key)" scope="col" class="py-2 px-3 text-xs uppercase tracking-wide font-medium text-neutral-500 text-left" v-bind="columnDrag.headerAttrs(c.key)"><ColumnDragHandle /> {{ t(c.labelKey) }}</th>
                    <SortableTh v-else v-bind="columnDrag.headerAttrs(c.key)" reorderable :label="t(c.labelKey)" :sort-key="c.key" :sort="tbl.sort.value"
                      :align="['amount', 'exchange_rate', 'base', 'vat', 'balance', 'paid_amount', 'remaining_amount'].includes(c.key) ? 'right' : 'left'"
                      @toggle="onSortToggle" />
                  </template>
                  <th class="px-1 py-2 w-8">
                    <button v-if="tbl.sort.value" type="button" class="inline-flex h-6 w-6 items-center justify-center rounded text-neutral-500 hover:bg-neutral-200 hover:text-neutral-800"
                      :title="t('common.reset_sort')" :aria-label="t('common.reset_sort')" @click.stop="clearSort">×</button>
                    <span v-else class="sr-only">{{ t('common.expand_items') }}</span>
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
                  <template v-for="c in tbl.orderedColumns.value.filter(c => tbl.isVisible(c.key))" :key="c.key">
                <td v-if="c.key === 'number'" :data-custom-color="!!colors.color('number')" :style="colors.cellStyle('number')" class="px-3 py-2.5 font-mono text-xs">
                    <RouterLink class="row-link" :to="`/purchase-invoices/${inv.id}`" @click.stop="listNav.markOpen(inv.id)" @auxclick.stop="listNav.markOpen(inv.id)">
                      <span v-if="inv.varsymbol">{{ inv.varsymbol }}</span>
                      <span v-else class="text-neutral-400">#{{ inv.id }}</span>
                    </RouterLink>
                  </td>
                  <td v-else-if="c.key === 'vendor'" :data-custom-color="!!colors.color('vendor')" :style="colors.cellStyle('vendor')" class="px-3 py-2.5">
                    <div class="font-medium text-neutral-900 truncate max-w-[15rem]" :title="inv.vendor_company_name">{{ inv.vendor_company_name }}</div>
                  </td>
                  <td v-else-if="c.key === 'vendor_ic'" :data-custom-color="!!colors.color('vendor_ic')" :style="colors.cellStyle('vendor_ic')" class="px-3 py-2.5 font-mono text-xs text-neutral-600 whitespace-nowrap">{{ inv.vendor_ic || '—' }}</td>
                  <td v-else-if="c.key === 'vendor_number'" :data-custom-color="!!colors.color('vendor_number')" :style="colors.cellStyle('vendor_number')" class="px-3 py-2.5 font-mono text-xs text-neutral-600">
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
                  <td v-else-if="c.key === 'kind'" :data-custom-color="!!colors.color('kind')" :style="colors.cellStyle('kind')" class="px-3 py-2.5 text-center text-xs text-neutral-600">{{ t(`purchase_invoice.document_kind.${inv.document_kind}`) }}</td>
                  <td v-else-if="c.key === 'issue_date'" :data-custom-color="!!colors.color('issue_date')" :style="colors.cellStyle('issue_date')" class="px-3 py-2.5 text-center text-xs text-neutral-600">{{ formatDate(inv.issue_date) }}</td>
                  <td v-else-if="c.key === 'tax_date'" :data-custom-color="!!colors.color('tax_date')" :style="colors.cellStyle('tax_date')" class="px-3 py-2.5 text-center text-xs">
                    <span :class="taxDateClass(inv.tax_date, inv.issue_date)">{{ formatDate(inv.tax_date || inv.issue_date) }}</span>
                  </td>
                  <td v-else-if="c.key === 'due_date'" :data-custom-color="!!colors.color('due_date')" :style="colors.cellStyle('due_date')" class="px-3 py-2.5 text-center text-xs">
                    <span :class="isOverdue(inv.due_date, inv.status) ? 'text-danger-500 font-medium' : 'text-neutral-600'">
                      {{ formatDate(inv.due_date) }}
                    </span>
                  </td>
                  <td
                    v-else-if="c.key === 'amount'" :data-custom-color="!!colors.color('amount')"
                    class="amount-cell px-3 py-2.5 text-right font-mono font-semibold text-neutral-900"
                    :style="{ ...colors.cellStyle('amount'), '--bar': amountBarWidth(inv, g) }"
                  >
                    {{ formatMoney(inv.total_with_vat, inv.currency) }}
                  </td>
                  <td v-else-if="c.key === 'status'" :data-custom-color="!!colors.color('status')" :style="colors.cellStyle('status')" class="px-3 py-2.5 text-center">
                    <span class="text-xs px-2 py-0.5 rounded" :class="statusBadgeClass(inv.status)">
                      {{ t(`purchase_invoice.status.${inv.status}`) }}
                    </span>
                    <div v-if="inv.approval_status && inv.approval_status !== 'none'" class="mt-1">
                      <ApprovalStatusBadge :status="inv.approval_status" small />
                    </div>
                    <div v-if="inv.payment_ordered_at" class="mt-1">
                      <span class="text-[10px] px-1.5 py-0.5 rounded bg-teal-50 text-teal-600 border border-teal-500/30 inline-flex items-center gap-0.5"
                        :title="t('purchase_invoice.payment_ordered_at_tooltip', { date: formatDate(inv.payment_ordered_at) })">
                        <svg class="w-2.5 h-2.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                        {{ t('purchase_invoice.payment_ordered_badge') }}
                      </span>
                    </div>
                    <div v-if="hasPaidShortfall(inv)" class="mt-1">
                      <span class="text-[10px] px-1.5 py-0.5 rounded bg-warning-50 text-warning-700 border border-warning-500/30 whitespace-nowrap"
                        :title="t('purchase_invoice.paid_shortfall_tooltip', { amount: formatMoney(inv.remaining_amount ?? 0, inv.currency) })">
                        {{ t('purchase_invoice.paid_shortfall_badge') }}
                      </span>
                    </div>
                  </td>
                  <td v-else-if="c.key === 'paid_amount'" :data-custom-color="!!colors.color('paid_amount')" :style="colors.cellStyle('paid_amount')" class="px-3 py-2.5 text-right font-mono text-xs text-neutral-600">
                    <span v-if="showsPayment(inv)">{{ formatMoney(inv.paid_amount ?? 0, inv.currency) }}</span>
                    <span v-else class="text-neutral-300">—</span>
                  </td>
                  <td v-else-if="c.key === 'remaining_amount'" :data-custom-color="!!colors.color('remaining_amount')" :style="colors.cellStyle('remaining_amount')" class="px-3 py-2.5 text-right font-mono text-xs" :class="remainingClass(inv)">
                    <span v-if="showsPayment(inv)">{{ formatMoney(inv.remaining_amount ?? 0, inv.currency) }}</span>
                    <span v-else class="text-neutral-300">—</span>
                  </td>
                  <td v-else-if="c.key === 'paid_at'" :data-custom-color="!!colors.color('paid_at')" :style="colors.cellStyle('paid_at')" class="px-3 py-2.5 text-center text-xs text-neutral-600">
                    <span v-if="inv.paid_at">{{ formatDate(inv.paid_at) }}</span>
                    <span v-else class="text-neutral-300">—</span>
                  </td>
                  <td v-else-if="c.key === 'booked_at'" :data-custom-color="!!colors.color('booked_at')" :style="colors.cellStyle('booked_at')" class="px-3 py-2.5 text-center text-xs text-neutral-600">
                    <span v-if="inv.booked_at">{{ formatDate(inv.booked_at) }}</span>
                    <span v-else class="text-neutral-300">—</span>
                  </td>
                  <td v-else-if="c.key === 'exchange_rate'" :data-custom-color="!!colors.color('exchange_rate')" :style="colors.cellStyle('exchange_rate')" class="px-3 py-2.5 text-right font-mono text-xs text-neutral-600">
                    <span v-if="inv.currency !== 'CZK' && inv.exchange_rate">{{ formatRate(inv.exchange_rate) }}</span>
                    <span v-else class="text-neutral-300">—</span>
                  </td>
                  <td v-else-if="c.key === 'vat_deduction'" :data-custom-color="!!colors.color('vat_deduction')" :style="colors.cellStyle('vat_deduction')" class="px-3 py-2.5 text-center text-xs text-neutral-600">
                    {{ vatDeductionLabel(inv) }}
                  </td>
                  <td v-else-if="c.key === 'expense_category'" :data-custom-color="!!colors.color('expense_category')" :style="colors.cellStyle('expense_category')" class="px-3 py-2.5 text-xs text-neutral-600">
                    <span v-if="inv.expense_category_label">{{ inv.expense_category_label }}</span>
                    <span v-else class="text-neutral-300">—</span>
                  </td>
                  <td v-else-if="c.key === 'base'" :data-custom-color="!!colors.color('base')" :style="colors.cellStyle('base')" class="px-3 py-2.5 text-right font-mono text-xs">{{ formatMoney(inv.total_without_vat, inv.currency) }}</td>
                  <td v-else-if="c.key === 'vat'" :data-custom-color="!!colors.color('vat')" :style="colors.cellStyle('vat')" class="px-3 py-2.5 text-right font-mono text-xs">{{ formatMoney(inv.total_vat, inv.currency) }}</td>
                  <td v-else-if="c.key === 'balance'" :data-custom-color="!!colors.color('balance')" :style="colors.cellStyle('balance')" class="px-3 py-2.5 text-right font-mono text-xs">{{ formatMoney(inv.amount_to_pay, inv.currency) }}</td>
                  <td v-else-if="c.key === 'project'" :data-custom-color="!!colors.color('project')" :style="colors.cellStyle('project')" class="px-3 py-2.5 text-xs text-neutral-600">{{ inv.project_name || '—' }}</td>
                  <td v-else-if="c.key === 'received_at'" :data-custom-color="!!colors.color('received_at')" :style="colors.cellStyle('received_at')" class="px-3 py-2.5 text-center text-xs text-neutral-600">{{ inv.received_at ? formatDate(inv.received_at) : '—' }}</td>
                  <td v-else-if="c.key === 'payment_ordered_at'" :data-custom-color="!!colors.color('payment_ordered_at')" :style="colors.cellStyle('payment_ordered_at')" class="px-3 py-2.5 text-center text-xs text-neutral-600">{{ inv.payment_ordered_at ? formatDate(inv.payment_ordered_at) : '—' }}</td>
                  <td v-else-if="c.key === 'vat_breakdown'" :data-custom-color="!!colors.color('vat_breakdown')" :style="colors.cellStyle('vat_breakdown')" class="px-3 py-2.5"><VatBreakdownCell :rows="inv.vat_breakdown" :currency="inv.currency" /></td>
                  <td v-else-if="c.key === 'debit_accounts'" :data-custom-color="!!colors.color('debit_accounts')" :style="colors.cellStyle('debit_accounts')" class="px-3 py-2.5 font-mono text-xs">{{ inv.debit_accounts?.join(', ') || '—' }}</td>
                  <td v-else-if="c.key === 'credit_accounts'" :data-custom-color="!!colors.color('credit_accounts')" :style="colors.cellStyle('credit_accounts')" class="px-3 py-2.5 font-mono text-xs">{{ inv.credit_accounts?.join(', ') || '—' }}</td>
                <td v-else-if="c.key === 'country'" :data-custom-color="!!colors.color('country')" :style="colors.cellStyle('country')" class="px-3 py-2.5 text-xs" :title="extraFieldValue(inv, 'country')"><span class="inline-block align-middle max-w-64 truncate">{{ extraFieldValue(inv, 'country') }}</span></td>
                <td v-else-if="c.key === 'vat_classification'" :data-custom-color="!!colors.color('vat_classification')" :style="colors.cellStyle('vat_classification')" class="px-3 py-2.5 text-xs" :title="extraFieldValue(inv, 'vat_classification')"><span class="inline-block align-middle max-w-64 truncate">{{ extraFieldValue(inv, 'vat_classification') }}</span></td>
                <td v-else-if="c.key === 'vat_return_lines'" :data-custom-color="!!colors.color('vat_return_lines')" :style="colors.cellStyle('vat_return_lines')" class="px-3 py-2.5 text-xs" :title="extraFieldValue(inv, 'vat_return_lines')"><span class="inline-block align-middle max-w-64 truncate">{{ extraFieldValue(inv, 'vat_return_lines') }}</span></td>
                <td v-else-if="c.key === 'document_tags'" :data-custom-color="!!colors.color('document_tags')" :style="colors.cellStyle('document_tags')" class="px-3 py-2.5 text-xs" :title="extraFieldValue(inv, 'document_tags')"><span class="inline-block align-middle max-w-64 truncate">{{ extraFieldValue(inv, 'document_tags') }}</span></td>
                <td v-else-if="c.key === 'note'" :data-custom-color="!!colors.color('note')" :style="colors.cellStyle('note')" class="px-3 py-2.5 text-xs" :title="extraFieldValue(inv, 'note')"><span class="inline-block align-middle max-w-64 truncate">{{ extraFieldValue(inv, 'note') }}</span></td>
                <td v-else-if="c.key === 'journal_notes'" :data-custom-color="!!colors.color('journal_notes')" :style="colors.cellStyle('journal_notes')" class="px-3 py-2.5 text-xs" :title="extraFieldValue(inv, 'journal_notes')"><span class="inline-block align-middle max-w-64 truncate">{{ extraFieldValue(inv, 'journal_notes') }}</span></td>
                <td v-else-if="c.key === 'payment_vs'" :data-custom-color="!!colors.color('payment_vs')" :style="colors.cellStyle('payment_vs')" class="px-3 py-2.5 text-xs" :title="extraFieldValue(inv, 'payment_vs')"><span class="inline-block align-middle max-w-64 truncate">{{ extraFieldValue(inv, 'payment_vs') }}</span></td>
                <td v-else-if="c.key === 'payment_method'" :data-custom-color="!!colors.color('payment_method')" :style="colors.cellStyle('payment_method')" class="px-3 py-2.5 text-xs" :title="extraFieldValue(inv, 'payment_method')"><span class="inline-block align-middle max-w-64 truncate">{{ extraFieldValue(inv, 'payment_method') }}</span></td>
                <td v-else-if="c.key === 'kh'" :data-custom-color="!!colors.color('kh')" :style="colors.cellStyle('kh')" class="px-3 py-2.5 font-mono text-xs">{{ inv.kh_sections?.join(', ') || '—' }}</td>
                <td v-else-if="c.key === 'dimensions'" :data-custom-color="!!colors.color('dimensions')" :style="colors.cellStyle('dimensions')" class="px-3 py-2.5 text-xs max-w-64 truncate" :title="inv.dimension_labels?.join(' · ')">{{ inv.dimension_labels?.join(' · ') || '—' }}</td>
                  <td v-else-if="c.key === 'locked'" :data-custom-color="!!colors.color('locked')" :style="colors.cellStyle('locked')" class="px-2 py-2.5 text-center">
                    <PostingBadge v-if="inv.locked?.journal_entry_id"
                      :booked-at="inv.booked_at" :journal-entry-id="inv.locked.journal_entry_id" />
                    <svg v-else-if="inv.locked?.is_locked" class="w-4 h-4 inline-block text-neutral-400"
                      fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"
                      role="img" :aria-label="lockTitle(inv)">
                      <title>{{ lockTitle(inv) }}</title>
                      <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25z" />
                    </svg>
                  </td>
                </template>
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
                <tr v-if="expandedId === inv.id" class="table-detail-row bg-neutral-50/60">
                  <td :colspan="expandedColspan" class="px-6 py-3">
                    <table class="w-full text-xs table-plain">
                      <thead>
                        <tr class="text-neutral-500">
                          <th class="py-1 text-left font-medium">
                            <div class="flex flex-wrap items-center gap-2">
                              <button type="button" :class="btnOutlineSm('primary')" @click.stop="previewInvoiceId = inv.id">
                                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.eye" /></svg>
                                {{ t('accounting.journal.related.preview') }}
                              </button>
                              <span>{{ t('purchase_invoice.items.description') }}</span>
                            </div>
                          </th>
                          <th class="py-1 text-right font-medium w-24">{{ t('purchase_invoice.items.quantity') }}</th>
                          <th class="py-1 text-right font-medium w-32">{{ t('purchase_invoice.items.unit_price') }}</th>
                          <th class="py-1 text-right font-medium w-32">{{ t('purchase_invoice.items.total_with_vat') }}</th>
                        </tr>
                      </thead>
                      <tbody>
                        <tr v-if="expandedLoading"><td colspan="4" class="py-1 text-neutral-500">{{ t('common.loading') }}</td></tr>
                        <tr v-else-if="!expandedItems?.length"><td colspan="4" class="py-1 text-neutral-500">{{ t('common.no_data') }}</td></tr>
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
        <div class="md:hidden bg-surface border border-t-0 border-neutral-200 rounded-b-lg divide-y divide-neutral-100">
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
                  <div class="font-medium text-neutral-900 truncate flex items-center gap-1.5" :data-custom-color="!!colors.color('vendor')" :style="colors.cellStyle('vendor')">
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
                  <div class="font-mono text-sm whitespace-nowrap" :data-custom-color="!!colors.color('amount')" :style="colors.cellStyle('amount')">
                    {{ formatMoney(inv.total_with_vat, inv.currency) }}
                  </div>
                </div>
                <div class="flex items-baseline justify-between gap-2 mt-1 text-xs text-neutral-500">
                  <div class="font-mono truncate">
                    <RouterLink class="row-link" :data-custom-color="!!colors.color('number')" :style="colors.cellStyle('number')" :to="`/purchase-invoices/${inv.id}`" @click.stop="listNav.markOpen(inv.id)" @auxclick.stop="listNav.markOpen(inv.id)">{{ inv.varsymbol || '#' + inv.id }}</RouterLink>
                    <span class="text-neutral-400"> · </span>
                    <span :data-custom-color="!!colors.color('vendor_number')" :style="colors.cellStyle('vendor_number')">{{ inv.vendor_invoice_number }}</span>
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
                    <span class="text-xs px-1.5 py-0.5 rounded whitespace-nowrap" :class="statusBadgeClass(inv.status)" :data-custom-color="!!colors.color('status')" :style="colors.cellStyle('status')">
                      {{ t(`purchase_invoice.status.${inv.status}`) }}
                    </span>
                  </span>
                </div>
                <div v-if="inv.approval_status && inv.approval_status !== 'none'" class="mt-1">
                  <ApprovalStatusBadge :status="inv.approval_status" small />
                </div>
                <div v-if="inv.payment_ordered_at" class="mt-1">
                  <span class="text-[10px] px-1.5 py-0.5 rounded bg-teal-50 text-teal-600 border border-teal-500/30 inline-flex items-center gap-0.5">
                    <svg class="w-2.5 h-2.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                    {{ t('purchase_invoice.payment_ordered_badge') }}
                  </span>
                </div>
                <div class="flex items-center justify-between gap-2 mt-1 text-xs text-neutral-500">
                  <span :class="taxDateClass(inv.tax_date, inv.issue_date)" :data-custom-color="!!colors.color('tax_date')" :style="colors.cellStyle('tax_date')">{{ formatDate(inv.tax_date || inv.issue_date) }}</span>
                  <span :class="isOverdue(inv.due_date, inv.status) ? 'text-danger-500 font-medium' : ''" :data-custom-color="!!colors.color('due_date')" :style="colors.cellStyle('due_date')">
                    {{ t('purchase_invoice.fields.due_date') }}: {{ formatDate(inv.due_date) }}
                  </span>
                </div>
                <!-- Částečná úhrada / nedoplatek: na mobilu jinak není kde to poznat. -->
                <div v-if="showsPayment(inv) && (inv.paid_amount ?? 0) > 0.005 && (inv.remaining_amount ?? 0) > 0.005"
                  class="flex flex-wrap items-center justify-between gap-x-2 gap-y-1 mt-1 text-xs">
                  <span class="text-neutral-500" :data-custom-color="!!colors.color('paid_amount')" :style="colors.cellStyle('paid_amount')">
                    {{ t('purchase_invoice.col_paid_amount') }}: <span class="font-mono text-neutral-700">{{ formatMoney(inv.paid_amount ?? 0, inv.currency) }}</span>
                  </span>
                  <span :class="remainingClass(inv)" :data-custom-color="!!colors.color('remaining_amount')" :style="colors.cellStyle('remaining_amount')">
                    {{ t('purchase_invoice.col_remaining_amount') }}: <span class="font-mono">{{ formatMoney(inv.remaining_amount ?? 0, inv.currency) }}</span>
                    <span v-if="hasPaidShortfall(inv)"
                      class="ml-1 text-[10px] px-1.5 py-0.5 rounded bg-warning-50 text-warning-700 border border-warning-500/30 whitespace-nowrap">
                      {{ t('purchase_invoice.paid_shortfall_badge') }}
                    </span>
                  </span>
                </div>
              </div>
            </div>
            <div v-if="mobileExtraFields(inv).length || tbl.isVisible('vat_breakdown')" class="mt-3 ml-8 border-t border-neutral-200 pt-2 grid grid-cols-2 gap-x-3 gap-y-2 text-xs">
              <div v-for="field in mobileExtraFields(inv)" :key="field.key" :data-custom-color="!!colors.color(field.key)" :style="colors.cellStyle(field.key)" class="min-w-0">
                <div class="text-neutral-500">{{ field.label }}</div>
                <div class="font-medium text-neutral-800 break-words">{{ field.value }}</div>
              </div>
              <div v-if="tbl.isVisible('vat_breakdown')" class="col-span-2" :data-custom-color="!!colors.color('vat_breakdown')" :style="colors.cellStyle('vat_breakdown')">
                <div class="text-neutral-500 mb-1">{{ t('invoice.col_vat_breakdown') }}</div>
                <VatBreakdownCell :rows="inv.vat_breakdown" :currency="inv.currency" />
              </div>
            </div>
          </div>
        </div>
      </section>

      <!-- Po měsících nemá desktop vlastní scroll seznamu, další stránku spouští jen tento cíl. -->
      <div v-if="page < pages" ref="loadMoreTarget" class="text-center text-sm text-neutral-500" :class="groupByMonth ? '' : 'pointer-fine-hidden'">
      </div>
    </div>
    <ListLoadingSpinner :show="loading || loadingMore" />
    <JournalSourceDrawer v-if="previewInvoiceId" :purchase-invoice-id="previewInvoiceId" @close="previewInvoiceId = null"
      @focus-entry="id => router.push({ name: 'accounting-journal', query: { entry_id: String(id) } })" />
    <ExtractionReviewModal v-if="reviewIds" :invoice-ids="reviewIds" @close="onReviewClosed" />
  </div>
</template>

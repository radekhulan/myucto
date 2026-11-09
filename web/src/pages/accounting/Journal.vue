<script setup lang="ts">
import { ref, onMounted, reactive, computed, watch, useId, nextTick, onBeforeUnmount } from 'vue'
import ListLoadingSpinner from '@/components/ui/ListLoadingSpinner.vue'
import { useFillViewportHeight } from '@/composables/useFillViewportHeight'
import { useAdaptiveTableRows } from '@/composables/useAdaptiveTableRows'
import { useI18n } from 'vue-i18n'
import { RouterLink, useRoute, useRouter, type RouteLocationRaw } from 'vue-router'
import {
  accountingApi,
  type JournalEntry,
  type JournalEntryDetail,
  type AccountingPeriod,
  type ChartAccount,
  type JournalEntryPair,
} from '@/api/accounting'
import { usePaneDom } from '@/composables/usePaneDom'
import { requestSupplierId } from '@/workspace/supplierContext'
import { useAuthStore } from '@/stores/auth'
import { useSupplierStore } from '@/stores/supplier'
import { hasFrameworkScope } from '@/security/frameworkScope'
import { useToast } from '@/composables/useToast'
import { formatDate, formatDateTime, formatMoney } from '@/composables/useFormat'
import SavedFiltersMenu from '@/components/ui/SavedFiltersMenu.vue'
import FilterBar, { type FilterChip } from '@/components/ui/FilterBar.vue'
import ColumnPicker from '@/components/ui/ColumnPicker.vue'
import TableColorsMenu from '@/components/ui/TableColorsMenu.vue'
import { useTableColors } from '@/composables/useTableColors'
import { useColumnDrag } from '@/composables/useColumnDrag'
import ColumnDragHandle from '@/components/ui/ColumnDragHandle.vue'
import SortableTh from '@/components/ui/SortableTh.vue'
import DensityToggle from '@/components/ui/DensityToggle.vue'
import { useTablePrefs, type ColumnDef } from '@/composables/useTablePrefs'
import { useScrollLoadMore } from '@/composables/useScrollLoadMore'
import { ensurePrefsLoaded } from '@/composables/useUserPrefs'
import { useSavedFilters, savedFilterTone, type SavedFilterTone } from '@/composables/useSavedFilters'
import type { SavedFilter } from '@/api/preferences'
import { ICONS, btnFilled, btnOutline, btnOutlineSm } from '@/components/ui/buttonStyles'
import EmptyState from '@/components/ui/EmptyState.vue'
import AutomationBadge from '@/components/automation/AutomationBadge.vue'
import ActivationBanner from '@/components/settings/activation/ActivationBanner.vue'
import JournalSourceDrawer from '@/components/accounting/JournalSourceDrawer.vue'
import JournalEntryDetailPanel from '@/components/accounting/JournalEntryDetailPanel.vue'
import JournalPairsTable from '@/components/accounting/JournalPairsTable.vue'
import Modal from '@/components/ui/Modal.vue'
import ConfirmDialog from '@/components/ui/ConfirmDialog.vue'
import type { ActionItem } from '@/components/ui/ActionBar.vue'
import { apiErrorText, hasAnySingle } from '@/utils/entryForm'
import LockedPeriodAckModal from '@/components/accounting/LockedPeriodAckModal.vue'
import { useLockedPeriodAck } from '@/composables/useLockedPeriodAck'
import { journalSourceLink } from '@/utils/journalSourceLink'
import { findAccountingPeriod } from '@/utils/accountingPeriod'
import DateInput from '@/components/ui/DateInput.vue'
import DimensionReportFilters from '@/components/dimensions/DimensionReportFilters.vue'
import { dimensionValueIdsParam, parseDimensionValueIds } from '@/utils/dimensionFilter'
import VatBreakdownCell from '@/components/ui/VatBreakdownCell.vue'
import { useDimensions } from '@/composables/useDimensions'

const { t } = useI18n()
const auth = useAuthStore()
const toast = useToast()
const route = useRoute()
const router = useRouter()
const pageId = useId()
const paneDom = usePaneDom()
let initialized = false
let disposed = false
let focusSeq = 0
let focusPending = false
onBeforeUnmount(() => { disposed = true; ++focusSeq; ++loadSeq })
const dims = useDimensions()

const entries = ref<JournalEntry[]>([])
const periods = ref<AccountingPeriod[]>([])
const loading = ref(false)
const loadingMore = ref(false)
const loadMoreTarget = ref<HTMLElement | null>(null)

const page = ref(1)
const listBox = ref<HTMLElement | null>(null)
useAdaptiveTableRows(computed(() => listBox.value ? [listBox.value] : []))
useFillViewportHeight(listBox)

// Tabulka deníku je `w-max` a při užším okně se posouvá vodorovně; rozbalený detail (colspan přes
// celou tabulku) by pak byl široký jako celá tabulka a jeho pravá část (akce, částky) by zůstala
// mimo obrazovku. Detail proto drží šířku viditelné plochy a při posunu zůstává na místě.
const listBoxWidth = ref(0)
let listBoxObserver: ResizeObserver | null = null
watch(listBox, el => {
  listBoxObserver?.disconnect()
  listBoxObserver = null
  if (!el) return
  listBoxWidth.value = el.clientWidth
  if (typeof ResizeObserver === 'undefined') return
  listBoxObserver = new ResizeObserver(() => { listBoxWidth.value = el.clientWidth })
  listBoxObserver.observe(el)
})
onBeforeUnmount(() => listBoxObserver?.disconnect())
/** Šířka obsahu detailu = viditelná plocha bez vnitřního odsazení buňky (px-3). */
const detailViewportStyle = computed(() => listBoxWidth.value > 48
  ? { position: 'sticky', left: '0.75rem', width: `${listBoxWidth.value - 24}px`, minWidth: `${listBoxWidth.value - 24}px` } as const
  : undefined)
const total = ref(0)
const perPage = ref(50)
const totalPages = computed(() => Math.max(1, Math.ceil(total.value / perPage.value)))
useScrollLoadMore(loadMoreTarget, () => !loading.value && !loadingMore.value && page.value < totalPages.value, () => load(false))

const filters = reactive({
  document_no: '',
  period_id: '' as number | '',
  date_from: '',
  date_to: '',
  source_type: '' as '' | (typeof SOURCE_TYPES)[number],
  posted: '' as '' | 'posted' | 'draft',
  reversal: '' as '' | 'reversed' | 'reversal' | 'any' | 'none',
  automation: '' as '' | 'auto' | 'approved' | 'manual',
  // Featura D (audit 2026-07 follow-up) — fulltext + rozsah účtu/částky.
  q: '',
  account_from: '',
  account_to: '',
  amount_from: '' as number | '',
  amount_to: '' as number | '',
  // Nálezy noční kontroly integrity deníku (JournalIntegrityService). Backend si
  // seznam dotčených zápisů dopočítá naživo — proklik z dashboardu jinak umí
  // ukázat jen JEDEN zápis a u víc nálezů skončí na nefiltrovaném deníku.
  integrity: '' as '' | 'amount_mismatch',
  // Zápisy označené k ruční úpravě (M : N, které nejde rozdělit na dvojice); jen firmy s jednoduchou formou.
  pair_status: '' as '' | 'composite_mn',
  // Hodnota dimenze (Firma → Dimenze) včetně podřízených, stejně jako u sestav.
  dimension_value_ids: [] as number[],
  dimension_descendants: true,
})

// Účtová osnova pro našeptávání rozsahu účtu ve filtru. Datalist je stejný vzor,
// jaký používá editor zápisu — uživatel může dál psát i kód, který v osnově není
// (třeba zrušenou analytiku), filtr je textový rozsah, ne výběr z číselníku.
const accounts = ref<ChartAccount[]>([])
const activeAccounts = computed(() =>
  accounts.value.filter(a => a.is_active).sort((a, b) => a.account_code.localeCompare(b.account_code)))

function accountName(code: string): string {
  if (!code) return ''
  return accounts.value.find(a => a.account_code === code)?.name ?? ''
}

const SOURCE_TYPES = [
  'manual', 'invoice', 'purchase_invoice', 'other_item', 'bank', 'gopay', 'cash',
  'depreciation', 'payroll', 'asset', 'asset_disposal', 'unit_charge',
  'closing', 'opening', 'fx_revaluation', 'stock',
  'offset', 'settlement', 'vat_clearing', 'card_settlement', 'card_writeoff',
] as const
// Předpis SVJ vzniká jen u SVJ; proklik z URL ho přijme i jinde, výběr ho nenabízí.
const supplierStore = useSupplierStore()
const sourceTypeOptions = computed(() => SOURCE_TYPES.filter(type =>
  type !== 'unit_charge' || hasFrameworkScope(supplierStore.currentSupplier, 'svj')))

// Drill-down z detailu dokladu (FV/PF): ?source_type=&source_id= → filtruje deník na
// zápis(y) přesně tohoto dokladu. Deep-link, ne uživatelský ovladač → mimo saved filters;
// jakmile uživatel sáhne na filtry, omezení se uvolní (viz applyFilters/resetFilters).
const sourceIdFilter = ref<number | ''>('')

// Totéž pro ?entry_id= — odskok na JEDEN konkrétní zápis. Dřív se místo filtru jen
// zúžilo datum na entry_date, takže se vedle hledaného zápisu vypsal celý ten den;
// u prokliku z nálezu integrity deníku to mate, protože nesouvisející zápisy vypadají
// jako součást nálezu.
const entryIdFilter = ref<number | ''>('')

let loadSeq = 0
async function load(reset = true): Promise<boolean> {
  if (!reset && (loading.value || loadingMore.value || page.value >= totalPages.value)) return false
  const seq = ++loadSeq
  const supplierId = requestSupplierId()
  if (reset) {
    loading.value = true
    loadingMore.value = false
    page.value = 1
  } else {
    loadingMore.value = true
    page.value++
  }
  try {
    const r = await accountingApi.listJournal({
      page: page.value,
      document_no: filters.document_no || undefined,
      period_id: filters.period_id || undefined,
      date_from: filters.date_from || undefined,
      date_to: filters.date_to || undefined,
      source_type: filters.source_type || undefined,
      source_id: sourceIdFilter.value || undefined,
      entry_id: entryIdFilter.value || undefined,
      posted: filters.posted === '' ? undefined : filters.posted === 'posted',
      reversal: filters.reversal === '' ? undefined : filters.reversal,
      automation: filters.automation || undefined,
      q: filters.q || undefined,
      account_from: filters.account_from || undefined,
      account_to: filters.account_to || undefined,
      amount_from: filters.amount_from === '' ? undefined : Number(filters.amount_from),
      amount_to: filters.amount_to === '' ? undefined : Number(filters.amount_to),
      integrity: filters.integrity || undefined,
      pair_status: filters.pair_status || undefined,
      dimension_value_id: dimensionValueIdsParam(filters.dimension_value_ids),
      dimension_descendants: filters.dimension_descendants,
      sort_key: tbl.sort.value?.key,
      sort_dir: tbl.sort.value?.dir,
      include_vat_breakdown: tbl.isVisible('vat_breakdown'),
      include_posting_accounts: tbl.isVisible('debit_accounts') || tbl.isVisible('credit_accounts'),
      include_dimensions: tbl.isVisible('dimensions'),
      include_notes: tbl.isVisible('note'),
      include_documents: tbl.isVisible('documents'),
    })
    if (seq !== loadSeq || disposed || supplierId !== requestSupplierId()) return false
    entries.value = reset ? r.items : [...entries.value, ...r.items]
    total.value = r.total
    perPage.value = r.per_page
    return true
  } catch (e: any) {
    if (seq === loadSeq && !disposed && supplierId === requestSupplierId()) {
      if (!reset) page.value--
      // Neplatný filtr (např. rozsah účtů 6 až 5) vrací server jako 422 s hláškou.
      toast.error(e?.response?.data?.error?.message || t('common.error'))
    }
    return false
  } finally {
    if (seq === loadSeq && !disposed && supplierId === requestSupplierId()) {
      loading.value = false
      loadingMore.value = false
    }
  }
}

function applyFilters() {
  ++focusSeq
  focusPending = false
  // Uživatel sáhl na filtry → uvolni drill-down omezení na konkrétní doklad i zápis.
  sourceIdFilter.value = ''
  entryIdFilter.value = ''
  page.value = 1
  syncFiltersToUrl()
  load()
}

function onPeriodChange() {
  const period = findAccountingPeriod(periods.value, filters.period_id)
  if (period) {
    filters.date_from = period.starts_on
    filters.date_to = period.ends_on
  } else {
    filters.date_from = ''
    filters.date_to = ''
  }
  applyFilters()
}
function resetFilters() {
  filters.document_no = ''
  filters.period_id = ''
  filters.date_from = ''
  filters.date_to = ''
  filters.source_type = ''
  filters.posted = ''
  filters.reversal = ''
  filters.automation = ''
  filters.q = ''
  filters.account_from = ''
  filters.account_to = ''
  filters.amount_from = ''
  filters.amount_to = ''
  filters.integrity = ''
  filters.pair_status = ''
  filters.dimension_value_ids = []
  filters.dimension_descendants = true
  sourceIdFilter.value = ''
  entryIdFilter.value = ''
  applyFilters()
}

function onDimensionValues(valueIds: number[]) {
  filters.dimension_value_ids = valueIds
  applyFilters()
}
function onDimensionDescendants(value: boolean) {
  filters.dimension_descendants = value
  applyFilters()
}

function buildQuery(): Record<string, string> {
  const q: Record<string, string> = {}
  if (filters.document_no) q.document_no = filters.document_no
  if (filters.period_id !== '') q.period_id = String(filters.period_id)
  if (filters.date_from) q.date_from = filters.date_from
  if (filters.date_to) q.date_to = filters.date_to
  if (filters.source_type) q.source_type = filters.source_type
  if (filters.posted) q.posted = filters.posted
  if (filters.reversal) q.reversal = filters.reversal
  if (filters.automation) q.automation = filters.automation
  if (filters.q) q.q = filters.q
  if (filters.account_from) q.account_from = filters.account_from
  if (filters.account_to) q.account_to = filters.account_to
  if (filters.amount_from !== '') q.amount_from = String(filters.amount_from)
  if (filters.amount_to !== '') q.amount_to = String(filters.amount_to)
  if (filters.integrity) q.integrity = filters.integrity
  if (filters.pair_status) q.pair_status = filters.pair_status
  if (filters.dimension_value_ids.length > 0) {
    q.dimension_value_id = dimensionValueIdsParam(filters.dimension_value_ids) as string
    if (!filters.dimension_descendants) q.dimension_descendants = '0'
  }
  return q
}

/**
 * Aplikované filtry se zrcadlí do URL. Nejde jen o sdílitelný odkaz: bez query
 * v adrese je klik na „Účetní deník" v menu navigace na TOTOŽNOU cestu, kterou
 * router vůbec neprovede — stránka se nepřekreslí a filtry zůstanou viset.
 * S query je to skutečná změna adresy a `route.query` watcher níž filtry vyčistí.
 */
let suppressUrlSync = false
function syncFiltersToUrl() {
  if (suppressUrlSync) return
  void router.replace({ query: buildQuery() })
}

function hydrateFilters(q: Record<string, unknown>) {
  const value = (key: string) => typeof q[key] === 'string' ? q[key] : ''
  const numberValue = (key: string): number | '' => {
    const parsed = Number(value(key))
    return value(key) !== '' && Number.isFinite(parsed) ? parsed : ''
  }
  filters.document_no = value('document_no')
  const periodId = numberValue('period_id')
  filters.period_id = periodId !== '' && periodId > 0 ? periodId : ''
  filters.date_from = value('date_from')
  filters.date_to = value('date_to')
  filters.source_type = (SOURCE_TYPES as readonly string[]).includes(value('source_type'))
    ? (value('source_type') as typeof filters.source_type) : ''
  const posted = value('posted')
  filters.posted = posted === 'posted' || posted === '1'
    ? 'posted'
    : (posted === 'draft' || posted === '0' ? 'draft' : '')
  const reversal = value('reversal')
  filters.reversal = (['reversed', 'reversal', 'any', 'none'] as const)
    .includes(reversal as never) ? reversal as typeof filters.reversal : ''
  const automation = value('automation')
  filters.automation = automation === 'auto' || automation === 'approved' || automation === 'manual'
    ? automation : ''
  filters.q = value('q')
  filters.account_from = value('account_from')
  filters.account_to = value('account_to')
  filters.amount_from = numberValue('amount_from')
  filters.amount_to = numberValue('amount_to')
  filters.integrity = value('integrity') === 'amount_mismatch' ? 'amount_mismatch' : ''
  filters.pair_status = value('pair_status') === 'composite_mn' ? 'composite_mn' : ''
  filters.dimension_value_ids = parseDimensionValueIds(value('dimension_value_id'))
  filters.dimension_descendants = value('dimension_descendants') !== '0'
}

function applyQueryToPage(q: Record<string, string>) {
  // Uložený filtr přepisuje URL sám — sync i reset watcher se na ten jeden tick uspí,
  // ať se nepřepíšou navzájem (prázdný uložený filtr by se jinak vyhodnotil jako
  // „klik z menu" a hned se zase zrušil).
  suppressUrlSync = true
  setTimeout(() => { suppressUrlSync = false }, 0)
  void router.replace({ query: q })
  hydrateFilters(q)
  applyFilters()
}

/** Je nastavený aspoň jeden filtr (včetně drill-downu z jiné stránky)? */
function hasActiveFilters(): boolean {
  return Object.keys(buildQuery()).length > 0
    || sourceIdFilter.value !== '' || entryIdFilter.value !== ''
}

// Počet aktivních filtrů pro odznáček na tlačítku „Filtry" — stejný vzor jako u faktur.
const activeFilterCount = computed(() => {
  let n = 0
  if (filters.document_no) n++
  if (filters.period_id !== '') n++
  if (filters.date_from || filters.date_to) n++
  if (filters.source_type) n++
  if (filters.posted) n++
  if (filters.reversal) n++
  if (filters.automation) n++
  if (filters.q) n++
  if (filters.account_from || filters.account_to) n++
  if (filters.amount_from !== '' || filters.amount_to !== '') n++
  if (filters.integrity) n++
  if (filters.pair_status) n++
  if (filters.dimension_value_ids.length > 0) n++
  return n
})

/**
 * Aktivní filtry jako odstranitelné chipy — stejný vzor jako u vydaných i přijatých
 * faktur (FilterBar `chips`). Drill-down (`sourceIdFilter`/`entryIdFilter`) chip
 * nedostává schválně, ruší se přes „Zrušit filtry" jako dosud.
 */
const filterChips = computed<FilterChip[]>(() => {
  const chips: FilterChip[] = []
  if (filters.document_no) chips.push({ key: 'document_no', label: t('accounting.journal.filter_document_no'), value: filters.document_no })
  if (filters.period_id !== '') {
    const p = periods.value.find(x => x.id === filters.period_id)
    if (p) chips.push({ key: 'period', value: String(p.fiscal_year) })
  }
  if (filters.date_from || filters.date_to) {
    chips.push({ key: 'dates', value: `${filters.date_from ? formatDate(filters.date_from) : '…'} – ${filters.date_to ? formatDate(filters.date_to) : '…'}` })
  }
  if (filters.source_type) chips.push({ key: 'source_type', value: sourceLabel(filters.source_type) })
  if (filters.posted) chips.push({ key: 'posted', value: filters.posted === 'posted' ? t('accounting.journal.posted') : t('accounting.journal.draft') })
  if (filters.reversal) chips.push({ key: 'reversal', value: t(`accounting.journal.reversal_${filters.reversal}`) })
  if (filters.automation) chips.push({ key: 'automation', value: t(`automation.origin_${filters.automation}`) })
  if (filters.q) chips.push({ key: 'q', label: t('accounting.journal.filter_q'), value: filters.q })
  if (filters.account_from || filters.account_to) {
    chips.push({ key: 'account_range', label: t('accounting.journal.filter_account_from'), value: `${filters.account_from || '…'} – ${filters.account_to || '…'}` })
  }
  if (filters.amount_from !== '' || filters.amount_to !== '') {
    chips.push({ key: 'amount_range', label: t('accounting.journal.filter_amount_from'), value: `${filters.amount_from !== '' ? filters.amount_from : '…'} – ${filters.amount_to !== '' ? filters.amount_to : '…'}` })
  }
  if (filters.integrity) chips.push({ key: 'integrity', value: t('accounting.journal.filter_integrity') })
  if (filters.pair_status) chips.push({ key: 'pair_status', value: t('accounting.pairs.filter_mn') })
  if (filters.dimension_value_ids.length > 0) {
    const ids = filters.dimension_value_ids
    const first = dims.valueById.value.get(ids[0])
    const typeName = (id: number) => {
      const value = dims.valueById.value.get(id)
      return value ? dims.typeById.value.get(value.type_id)?.name : undefined
    }
    chips.push({
      key: 'dimension',
      label: (ids.length === 1 && first && typeName(ids[0])) || t('dimensions.filter_type'),
      value: (ids.length === 1
        ? dims.valueLabel(ids[0])
        : ids.map(id => [typeName(id), dims.valueLabel(id)].filter(Boolean).join(': ')).join(' · '))
        + (filters.dimension_descendants ? '' : ` (${t('accounting.journal.filter_dimension_only_value')})`),
    })
  }
  return chips
})

function clearFilter(key: string) {
  switch (key) {
    case 'document_no': filters.document_no = ''; break
    case 'period': filters.period_id = ''; break
    case 'dates': filters.date_from = ''; filters.date_to = ''; break
    case 'source_type': filters.source_type = ''; break
    case 'posted': filters.posted = ''; break
    case 'reversal': filters.reversal = ''; break
    case 'automation': filters.automation = ''; break
    case 'q': filters.q = ''; break
    case 'account_range': filters.account_from = ''; filters.account_to = ''; break
    case 'amount_range': filters.amount_from = ''; filters.amount_to = ''; break
    case 'integrity': filters.integrity = ''; break
    case 'pair_status': filters.pair_status = ''; break
    case 'dimension': filters.dimension_value_ids = []; filters.dimension_descendants = true; break
  }
  applyFilters()
}

// Klik na „Účetní deník" v menu vede na cestu BEZ query — a to je jediný signál,
// podle kterého se dá poznat od navigace s drill-downem (`?entry_id=`, `?source_id=`)
// nebo z uloženého filtru. Prázdná query tedy znamená „chci čistý deník".
watch(() => route.query, (q) => {
  if (suppressUrlSync || Object.keys(q).length > 0 || !hasActiveFilters()) return
  suppressUrlSync = true
  resetFilters()
  setTimeout(() => { suppressUrlSync = false }, 0)
})

/**
 * Deep-link `?entry_id=` při navigaci na TUTÉŽ routu. `onMounted` se v takovém
 * případě znovu nespustí, takže bez tohohle watcheru by odkaz mířící z deníku do
 * deníku jen přepsal adresu a jinak neudělal nic.
 */
watch(() => [route.query.entry_id, route.query.source_type, route.query.source_id], async ([raw]) => {
  const pending = focusPending
  focusPending = false
  ++focusSeq
  if (!initialized) return
  const id = Number(raw || 0)
  sourceDrawerEntryId.value = null
  if (Number.isSafeInteger(id) && id > 0) {
    if ((pending || id !== entryIdFilter.value) && !await focusEntry(id)) toast.error(t('common.error'))
    return
  }
  const sourceId = Number(route.query.source_id || 0)
  if ((Number.isSafeInteger(sourceId) && sourceId > 0) || entryIdFilter.value !== '' || sourceIdFilter.value !== '' || pending) {
    hydrateFilters(route.query)
    entryIdFilter.value = ''
    sourceIdFilter.value = Number.isSafeInteger(sourceId) && sourceId > 0 ? sourceId : ''
    collapseAll()
    await load()
  }
}, { flush: 'sync' })

const COLUMNS: ColumnDef[] = [
  { key: 'date', labelKey: 'accounting.journal.entry_date', required: true },
  { key: 'document_no', labelKey: 'accounting.journal.document_no' },
  { key: 'document_date', labelKey: 'accounting.journal.col_document_date', defaultHidden: true },
  { key: 'description', labelKey: 'accounting.journal.description', required: true },
  { key: 'source', labelKey: 'accounting.journal.source_col' },
  { key: 'amount', labelKey: 'accounting.journal.col_amount' },
  { key: 'status', labelKey: 'accounting.journal.status_col' },
  { key: 'posted_at', labelKey: 'accounting.journal.col_posted_at', defaultHidden: true },
  { key: 'posted_by', labelKey: 'accounting.journal.col_posted_by', defaultHidden: true },
  { key: 'entry_id', labelKey: 'accounting.journal.col_entry_id', defaultHidden: true },
  { key: 'created_at', labelKey: 'accounting.journal.created_at', defaultHidden: true },
  { key: 'updated_at', labelKey: 'accounting.journal.col_updated_at', defaultHidden: true },
  { key: 'vat_breakdown', labelKey: 'invoice.col_vat_breakdown', defaultHidden: true },
  { key: 'debit_accounts', labelKey: 'accounting.journal.col_debit_accounts' },
  { key: 'credit_accounts', labelKey: 'accounting.journal.col_credit_accounts' },
  { key: 'note', labelKey: 'accounting.journal.col_note', defaultHidden: true },
  { key: 'documents', labelKey: 'accounting.journal.col_documents', defaultHidden: true },
  { key: 'dimensions', labelKey: 'dimensions.title', defaultHidden: true, available: () => dims.enabled.value },
]
const tbl = useTablePrefs('journal', COLUMNS)
const colors = useTableColors('journal')
const columnDrag = useColumnDrag(tbl)
const COLUMN_PRESETS = [
  { key: 'default', labelKey: 'common.columns_preset_default', visibleKeys: null, columnLabels: false },
  { key: 'accounting', labelKey: 'common.columns_preset_accounting', visibleKeys: ['date', 'document_no', 'description', 'source', 'amount', 'status', 'entry_id', 'created_at', 'vat_breakdown', 'debit_accounts', 'credit_accounts'] },
  { key: 'complete', labelKey: 'common.columns_preset_full', visibleKeys: COLUMNS.map(c => c.key) },
]
function onListScroll(event: Event) {
  const el = event.currentTarget as HTMLElement
  if (el.scrollTop + el.clientHeight >= el.scrollHeight - 240
    && !loading.value && !loadingMore.value && page.value < totalPages.value) void load(false)
}
watch(() => [tbl.isVisible('vat_breakdown'), tbl.isVisible('debit_accounts'), tbl.isVisible('credit_accounts'), tbl.isVisible('dimensions'), tbl.isVisible('note'), tbl.isVisible('documents')], () => { if (entries.value.length) load() })
function postingAccounts(entry: JournalEntry, side: 'debit' | 'credit'): string {
  return [...new Set(entry.posting_lines?.filter(line => line.side === side).map(line => line.account_code) ?? [])].join(', ') || '—'
}
function mobileExtraFields(entry: JournalEntry): Array<{ key: string; label: string; value: string }> {
  const values: Record<string, string> = {
    document_date: entry.document_date ? formatDate(entry.document_date) : '—',
    posted_at: entry.posted_at ? formatDate(entry.posted_at) : '—',
    posted_by: entry.posted_by_name || '—',
    entry_id: String(entry.id),
    created_at: formatDateTime(entry.created_at),
    updated_at: formatDateTime(entry.updated_at),
    debit_accounts: postingAccounts(entry, 'debit'),
    credit_accounts: postingAccounts(entry, 'credit'),
    dimensions: entry.dimension_labels?.join(' · ') || '—',
    note: entry.note_preview || '—',
    documents: entry.document_count ? String(entry.document_count) : '—',
  }
  return tbl.orderedColumns.value.filter(c => c.defaultHidden && c.key !== 'vat_breakdown' && tbl.isVisible(c.key))
    .map(c => ({ key: c.key, label: t(c.labelKey), value: values[c.key] ?? '—' }))
}
function onSortToggle(key: string) {
  tbl.toggleSort(key)
  page.value = 1
  load()
}
function clearSort() {
  tbl.clearSort()
  page.value = 1
  load()
}
const saved = useSavedFilters('journal', { getQuery: buildQuery, applyQuery: applyQueryToPage })
const visibleColCount = computed(() => 2 + tbl.columns.filter(c => tbl.isVisible(c.key)).length)

/**
 * Řádek pohledů = uložené filtry vytažené z dropdownu do záložek nad seznamem.
 * Stejný vzor jako u vydaných faktur (InvoiceList.vue) — tečka barvou napovídá
 * povahu pohledu, aniž by účetní musel klikat, aby zjistil, co pohled dělá.
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

// ── Export PDF/XLSX (audit 2026-07) — respektuje AKTUÁLNĚ aplikované filtry ────
const exporting = ref(false)
function exportQueryParams(): Record<string, string | number> {
  const q: Record<string, string | number> = {}
  if (filters.document_no) q.document_no = filters.document_no
  if (filters.period_id !== '') q.period_id = filters.period_id
  if (filters.date_from) q.date_from = filters.date_from
  if (filters.date_to) q.date_to = filters.date_to
  if (filters.source_type) q.source_type = filters.source_type
  if (sourceIdFilter.value) q.source_id = sourceIdFilter.value
  if (filters.posted) q.posted = filters.posted === 'posted' ? '1' : '0'
  if (filters.reversal) q.reversal = filters.reversal
  if (filters.automation) q.automation = filters.automation
  if (filters.q) q.q = filters.q
  if (filters.account_from) q.account_from = filters.account_from
  if (filters.account_to) q.account_to = filters.account_to
  if (filters.amount_from !== '') q.amount_from = filters.amount_from
  if (filters.amount_to !== '') q.amount_to = filters.amount_to
  if (filters.integrity) q.integrity = filters.integrity
  if (filters.pair_status) q.pair_status = filters.pair_status
  if (filters.dimension_value_ids.length > 0) {
    q.dimension_value_id = dimensionValueIdsParam(filters.dimension_value_ids) as string
    if (!filters.dimension_descendants) q.dimension_descendants = 0
  }
  return q
}
async function exportFile(format: 'pdf' | 'xlsx') {
  exporting.value = true
  try {
    const r = await accountingApi.exportReport('/accounting/reports/journal/export', { ...exportQueryParams(), format })
    downloadBlob(r.data as unknown as Blob, `ucetni-denik.${format}`)
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  } finally {
    exporting.value = false
  }
}
function downloadBlob(blob: Blob, filename: string) {
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = filename
  document.body.appendChild(a); a.click(); a.remove()
  URL.revokeObjectURL(url)
}

onMounted(async () => {
  await ensurePrefsLoaded()
  try { periods.value = await accountingApi.listPeriods() } catch { periods.value = [] }
  // Prvky dvojic (filtr M : N) se ukazují jen firmě, která někdy zapnula jednoduchý zápis.
  accountingApi.getEntryForm().then(v => { hasSingleForm.value = hasAnySingle(v.history) }).catch(() => { hasSingleForm.value = false })
  // Osnova jen pro našeptávání filtru — výpadek nesmí zabránit načtení deníku.
  accountingApi.listAccounts().then(v => { accounts.value = v }).catch(() => { accounts.value = [] })
  // Číselník dimenzí pro popisek chipu filtru; při vypnutých dimenzích nic nenačte.
  dims.load().catch(() => { /* chip ukáže #id */ })
  if (disposed) return
  hydrateFilters(route.query)
  initialized = true
  const qSourceId = Number(route.query.source_id || 0)
  if (Number.isSafeInteger(qSourceId) && qSourceId > 0) sourceIdFilter.value = qSourceId
  const entryId = Number(route.query.entry_id || 0)
  // Neexistující/cizí zápis → pokračuje běžné načtení.
  if (Number.isSafeInteger(entryId) && entryId > 0 && await focusEntry(entryId)) return
  if (Object.keys(route.query).length === 0 && await saved.applyDefaultIfAny()) return
  await load()
})

const hasSingleForm = ref(false)

// ── Dvojice jednoduchého zápisu: storno a přeúčtování vybraných dvojic ─────
// Akce visí na výběru dvojic v tabulce dvojic (JournalPairsTable + ActionBar). Server rozhoduje
// o pravidlech (zámky, mzdy 409); tady se akce jen neukazují tam, kde je jisté, že nepůjdou:
// protizápis, stornovaný zápis, mzdy. U dokladů (faktury, banka, pokladna ...) jen přeúčtování,
// čisté storno dvojice jen u ručních a interních zápisů (jinak by zápis nesedel s dokladem).
const pairSelection = ref<Record<number, number[]>>({})
const pairBusy = ref(false)
const repostPairDialog = reactive({ open: false, entry: null as JournalEntryDetail | null, pair: null as JournalEntryPair | null,
  debit: '', credit: '', text: '', date: '', saving: false, error: '' })

function selectedPairs(entryId: number): number[] { return pairSelection.value[entryId] ?? [] }
function setSelectedPairs(entryId: number, nos: number[]) { pairSelection.value = { ...pairSelection.value, [entryId]: nos } }
function entryHasPairs(d: JournalEntryDetail): boolean { return (d.pairs?.length ?? 0) > 0 }
function pairsEditable(d: JournalEntryDetail): boolean {
  return auth.canWrite('accounting') && entryHasPairs(d) && !d.reversed_by && !d.reverses_entry_id
    && !d.source_type.startsWith('payroll')
}
function pairsDisabled(d: JournalEntryDetail): number[] {
  return (d.pairs ?? []).filter(p => p.reversed_by_entry_id).map(p => p.pair_no)
}
function pairActions(d: JournalEntryDetail): ActionItem[] {
  const picked = selectedPairs(d.id)
  const canPureReverse = d.source_type === 'manual' || !!d.internal_document
  return [
    {
      key: 'pair-repost', label: t('accounting.pairs.action_repost'), icon: 'edit', tier: 'primary', variant: 'warning',
      show: pairsEditable(d), disabled: picked.length !== 1 || pairBusy.value,
      disabledReason: picked.length === 1 ? undefined : t('accounting.pairs.repost_pick_one'),
      run: () => openRepostPair(d),
    },
    {
      key: 'pair-reverse', label: t('accounting.pairs.action_reverse'), icon: 'uturn', tier: 'secondary', variant: 'danger',
      show: pairsEditable(d) && canPureReverse, disabled: picked.length === 0 || pairBusy.value,
      disabledReason: picked.length === 0 ? t('accounting.pairs.reverse_pick_some') : undefined,
      run: () => reversePairs(d),
    },
  ]
}

/** Čeká na potvrzení stylovaným dialogem (místo nativního confirm()). */
const reversePairsConfirm = reactive({ open: false, entry: null as JournalEntryDetail | null, nos: [] as number[] })

function reversePairs(d: JournalEntryDetail) {
  const nos = selectedPairs(d.id)
  if (nos.length === 0) return
  Object.assign(reversePairsConfirm, { open: true, entry: d, nos })
}

async function confirmReversePairs() {
  const d = reversePairsConfirm.entry
  const nos = reversePairsConfirm.nos
  reversePairsConfirm.open = false
  if (!d || nos.length === 0) return
  pairBusy.value = true
  try {
    const created = await accountingApi.reversePairs(d.id, { pair_nos: nos })
    toast.success(t('accounting.pairs.reversed', { id: created.id }))
    pairSelection.value = { ...pairSelection.value, [d.id]: [] }
    collapseAll()
    await load()
  } catch (e) {
    toast.error(apiErrorText(e, t))
  } finally {
    pairBusy.value = false
  }
}

function openRepostPair(d: JournalEntryDetail) {
  const pair = (d.pairs ?? []).find(p => p.pair_no === selectedPairs(d.id)[0]) ?? null
  if (!pair) return
  Object.assign(repostPairDialog, {
    open: true, entry: d, pair, debit: pair.debit_account_code, credit: pair.credit_account_code,
    text: pair.line_text ?? '', date: '', saving: false, error: '',
  })
}

async function submitRepostPair() {
  const d = repostPairDialog.entry
  const pair = repostPairDialog.pair
  if (!d || !pair) return
  repostPairDialog.error = ''
  if (!repostPairDialog.debit.trim()) { repostPairDialog.error = t('accounting.error.pair_missing_debit_account'); return }
  if (!repostPairDialog.credit.trim()) { repostPairDialog.error = t('accounting.error.pair_missing_credit_account'); return }
  if (repostPairDialog.debit.trim() === repostPairDialog.credit.trim()) { repostPairDialog.error = t('accounting.error.pair_same_account'); return }
  repostPairDialog.saving = true
  try {
    const result = await accountingApi.repostPair(d.id, pair.pair_no, {
      debit_account_code: repostPairDialog.debit.trim(),
      credit_account_code: repostPairDialog.credit.trim(),
      ...(repostPairDialog.text.trim() ? { line_text: repostPairDialog.text.trim() } : {}),
      ...(repostPairDialog.date ? { entry_date: repostPairDialog.date } : {}),
    })
    toast.success(t(['in_place', 'tax_neutral', 'correction_entry'].includes(result.mode)
      ? `accounting.pairs.repost_done_${result.mode}` : 'accounting.pairs.repost_done'))
    repostPairDialog.open = false
    pairSelection.value = { ...pairSelection.value, [d.id]: [] }
    collapseAll()
    await load()
  } catch (e) {
    repostPairDialog.error = apiErrorText(e, t)
  } finally {
    repostPairDialog.saving = false
  }
}

// ── Expand / detail ────────────────────────────────────────────────────────
// Rozbalených zápisů může být VÍC najednou: účetní typicky porovnává doklad s jeho
// úhradou nebo se stornem, a akordeon, který při otevření druhého zavřel první,
// znamenal skákání nahoru a dolů. Detaily se drží per ID a při přechodu na jinou
// stránku (nebo po zásahu, který data mění) se zahodí.
const expandedIds = ref<number[]>([])
const details = ref<Record<number, JournalEntryDetail>>({})
const detailLoadingIds = ref<number[]>([])

function isExpanded(id: number): boolean { return expandedIds.value.includes(id) }
function isDetailLoading(id: number): boolean { return detailLoadingIds.value.includes(id) }

function collapseAll() {
  pairSelection.value = {}
  expandedIds.value = []
  details.value = {}
  detailLoadingIds.value = []
}

async function toggleExpand(entry: JournalEntry) {
  if (isExpanded(entry.id)) {
    expandedIds.value = expandedIds.value.filter(id => id !== entry.id)
    delete details.value[entry.id]
    return
  }
  expandedIds.value = [...expandedIds.value, entry.id]
  detailLoadingIds.value = [...detailLoadingIds.value, entry.id]
  try {
    details.value = { ...details.value, [entry.id]: await accountingApi.getEntry(entry.id) }
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
    expandedIds.value = expandedIds.value.filter(id => id !== entry.id)
  } finally {
    detailLoadingIds.value = detailLoadingIds.value.filter(id => id !== entry.id)
  }
}

// ── Drawer se zdrojovým dokladem ───────────────────────────────────────────
// Akordeon (řádky, historie, přílohy, poznámky) zůstává; drawer je navíc a visí
// na ikoně ve sloupci ZDROJ. Drží se ID ZÁPISU, ne source_type/source_id.
const sourceDrawerEntryId = ref<number | null>(null)

function openSourceDrawer(entry: JournalEntry) {
  sourceDrawerEntryId.value = entry.id
}

async function reverse(entry: JournalEntryDetail) {
  if (!confirm(t('accounting.journal.reverse_confirm', { id: entry.id }))) return
  try {
    await accountingApi.reverseEntry(entry.id)
    toast.success(t('accounting.journal.reversed'))
    collapseAll()
    await load()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  }
}

// Uzamčené datum (po podání přiznání) tlačítko neskrývá — server vrátí varování,
// které účetní potvrdí ({@link LockedPeriodAckModal}). Zavřené období ano.
const lockedAck = useLockedPeriodAck()

function canDeleteEntry(entry: JournalEntryDetail): boolean {
  if (entry.reversed_by || entry.reverses_entry_id) return false
  if (!['manual', 'invoice', 'purchase_invoice', 'bank', 'depreciation', 'vat_clearing', 'other_item'].includes(entry.source_type)) return false
  if (entry.source_type === 'other_item' && (!auth.canWrite('other_items') || !auth.canWrite('accounting.journal.post'))) return false
  if (entry.source_type !== 'manual' && !entry.source_id) return false
  return periods.value.find(period => period.id === entry.period_id)?.status === 'open'
}

async function deleteEntry(entry: JournalEntryDetail) {
  const confirmKey = entry.source_type === 'other_item'
    ? 'accounting.journal.delete_other_item_confirm'
    : entry.source_type === 'depreciation'
    ? 'accounting.journal.delete_depreciation_confirm'
    : entry.source_type === 'bank'
      ? 'accounting.journal.delete_bank_confirm'
      : entry.source_type === 'manual'
        ? 'accounting.journal.delete_manual_confirm'
        : 'accounting.journal.delete_confirm'
  if (!confirm(t(confirmKey, { id: entry.id }))) return
  try {
    if (await lockedAck.run(ack => accountingApi.deleteEntry(entry.id, ack)) === null) return
    const successKey = entry.source_type === 'depreciation'
      ? 'accounting.journal.depreciation_deleted'
      : entry.source_type === 'bank'
        ? 'accounting.journal.bank_deleted'
        : entry.source_type === 'manual'
          ? 'accounting.journal.manual_deleted'
          : 'accounting.journal.deleted'
    toast.success(t(successKey))
    collapseAll()
    await load()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  }
}

// Přeúčtování zápis přepíše na místě, nebo ho stornuje a přidá nový — v obou
// případech se seznam i rozbalené detaily musí načíst znovu.
async function onReposted() {
  collapseAll()
  await load()
}

/**
 * Stornovaný zápis i jeho protizápis jdou z deníku odstranit úplně, dokud je období
 * otevřené. Obě strany se vzájemně ruší, takže se smazáním nezmění žádný zůstatek ani
 * výkaz — v deníku jen zmizí dvojice, která tam nikdy neměla být (typicky duplicitní
 * bankovní pohyb). Období protizápisu ověřuje server; tady se řídíme obdobím zápisu,
 * protože storno vzniká k témuž datu.
 */
function canDeletePair(entry: JournalEntryDetail): boolean {
  if (!entry.reversed_by) return false
  if (!['manual', 'invoice', 'purchase_invoice', 'bank', 'vat_clearing'].includes(entry.source_type)) return false
  return periods.value.find(period => period.id === entry.period_id)?.status === 'open'
}

async function deleteEntryPair(entry: JournalEntryDetail) {
  if (!confirm(t('accounting.journal.delete_pair_confirm', { id: entry.id, reversal: entry.reversed_by ?? 0 }))) return
  try {
    if (await lockedAck.run(ack => accountingApi.deleteEntryReversalPair(entry.id, ack)) === null) return
    toast.success(t('accounting.journal.pair_deleted'))
    collapseAll()
    await load()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  }
}

/** Sync editovaného description zpět do řádku listu i do detailu (Epic F7). */
function onDescriptionUpdated(entryId: number, description: string, rowVersion: number) {
  const d = details.value[entryId]
  if (d) {
    d.description = description
    d.row_version = rowVersion
  }
  const row = entries.value.find(e => e.id === entryId)
  if (row) { row.description = description; row.row_version = rowVersion }
}

/**
 * Vazby na doklady se změnily. Panel „Souvisí" i odznak ve sloupci Zdroj z nich
 * čtou, takže se překreslí panel (bump verze v `:key`) a rovnou se rozsvítí
 * odznak — jinak by seznam tvrdil „bez vazby" u zápisu, který ji právě dostal.
 */
const relatedVersion = ref<Record<number, number>>({})
function onLinksChanged(entryId: number) {
  relatedVersion.value = { ...relatedVersion.value, [entryId]: (relatedVersion.value[entryId] ?? 0) + 1 }
  void accountingApi.getJournalRelated(entryId)
    .then(r => {
      const row = entries.value.find(e => e.id === entryId)
      if (row) row.has_related = r.items.length > 0
    })
    .catch(() => { /* odznak zůstane, jak byl — doplňková informace */ })
}

/**
 * Odskok na JEDEN konkrétní zápis v rámci téže stránky — společná cesta pro
 * deep-link `?entry_id=`, proklik na stornující zápis i proklik z panelu „Souvisí".
 *
 * Kolizní filtry se ruší schválně: hledaný zápis by jimi nemusel projít a proklik
 * by navenek „nefungoval". Omezení dělá `entry_id`, datum se nastavuje jen jako
 * viditelný kontext (uživatel hned vidí, kde v čase je), takže se vedle hledaného
 * zápisu neukážou nesouvisející zápisy z téhož dne.
 *
 * @returns false, když zápis neexistuje nebo patří jinému tenantovi; překonaný
 * požadavek vrací true, aby už nespouštěl náhradní načtení ani chybovou hlášku.
 */
async function focusEntry(entryId: number): Promise<boolean> {
  const seq = ++focusSeq
  focusPending = true
  const supplierId = requestSupplierId()
  const current = () => !disposed && seq === focusSeq && supplierId === requestSupplierId()
  ++loadSeq
  loading.value = true
  entryIdFilter.value = ''
  collapseAll()
  let d: JournalEntryDetail
  try {
    d = await accountingApi.getEntry(entryId)
  } catch {
    if (!current()) return true
    focusPending = false
    loading.value = false
    return false
  }
  if (!current()) return true
  filters.document_no = ''
  filters.period_id = ''
  filters.source_type = ''
  filters.posted = ''
  filters.reversal = ''
  filters.automation = ''
  filters.q = ''
  filters.account_from = ''
  filters.account_to = ''
  filters.amount_from = ''
  filters.amount_to = ''
  filters.integrity = ''
  filters.pair_status = ''
  filters.dimension_value_ids = []
  filters.dimension_descendants = true
  sourceIdFilter.value = ''
  entryIdFilter.value = entryId
  filters.date_from = d.entry_date
  filters.date_to = d.entry_date
  page.value = 1
  const loaded = await load()
  if (!current()) return true
  focusPending = false
  if (!loaded || !entries.value.some(entry => entry.id === entryId)) return false
  // Proklik na konkrétní zápis ho ukáže rozbalený sám, ostatní k němu nepatří.
  expandedIds.value = [entryId]
  details.value = { [entryId]: d }
  detailLoadingIds.value = []
  await nextTick()
  if (!current()) return true
  const target = [...paneDom.querySelectorAll<HTMLElement>(`[data-journal-entry="${entryId}"]`)]
    .find(el => el.getClientRects().length > 0)
  target?.scrollIntoView({ block: 'nearest', inline: 'nearest', behavior: 'smooth' })
  return true
}

/** Proklik na stornující zápis. */
async function openReversal(id: number) {
  if (!await focusEntry(id)) toast.error(t('common.error'))
}

/**
 * Proklik z panelu „Souvisí" na zaúčtování protějšku. Drawer se zavírá — jinak by
 * zůstal viset přes výsledek a odskok by nebyl vidět.
 */
async function onFocusEntry(entryId: number) {
  sourceDrawerEntryId.value = null
  const seq = focusSeq + 1
  if (!await focusEntry(entryId)) {
    toast.error(t('common.error'))
    return
  }
  if (seq !== focusSeq || disposed) return
  // Adresa musí odpovídat tomu, co je vidět (sdílitelný odkaz, zpětné tlačítko).
  void router.replace({ query: { entry_id: String(entryId) } })
}

function sourceLabel(type: string): string {
  const key = `accounting.journal.source.${type}`
  const v = t(key)
  return v === key ? type : v
}

/**
 * Cíl drill-down odkazu na zdrojový doklad. Mapování source_type → routa je sdílené
 * s opisem účtu a rozpadem měsíce v hlavní knize (utils/journalSourceLink.ts) —
 * dokud žilo jen tady, vedla z opisu účtu proklikem jen faktura.
 */
function sourceLink(entry: JournalEntry): RouteLocationRaw | null {
  if (entry.source_type === 'other_item' && !auth.canRead('other_items')) return null
  return journalSourceLink(entry)
}

function entryRange(entry: JournalEntryDetail): { from: string; to: string } {
  const period = periods.value.find(item => item.id === entry.period_id)
  const year = entry.entry_date.slice(0, 4)
  return {
    from: filters.date_from || period?.starts_on || `${year}-01-01`,
    to: filters.date_to || period?.ends_on || `${year}-12-31`,
  }
}
</script>

<template>
  <div>
    <ActivationBanner />
    <div class="flex flex-wrap items-start justify-between gap-3 mb-4">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('accounting.journal.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5">
          {{ t('accounting.journal.subtitle') }}
          <span v-if="!loading && total > 0" class="ml-2 whitespace-nowrap">· {{ t('common.loaded_count', { loaded: entries.length, total }) }}</span>
        </p>
      </div>
      <div class="flex flex-wrap items-center gap-2">
        <button type="button" :disabled="exporting" :class="btnOutline('primary')" @click="exportFile('pdf')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.download" /></svg>
          {{ t('accounting.journal.export_pdf') }}
        </button>
        <button type="button" :disabled="exporting" :class="btnOutline('primary')" @click="exportFile('xlsx')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.download" /></svg>
          {{ t('accounting.journal.export_xlsx') }}
        </button>
        <RouterLink v-if="auth.canWrite('accounting.journal.write') || auth.isDemo" to="/accounting/journal/new" :class="btnFilled('primary')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
          {{ t('accounting.journal.new') }}
        </RouterLink>
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
    <FilterBar
      :active-count="activeFilterCount"
      collapsible
      :chips="filterChips"
      @clear="clearFilter"
      @clear-all="resetFilters"
    >
      <!-- Hledání zůstává viditelné i se sbalenými filtry — je to nejpoužívanější
           prvek lišty a schovat ho za „Filtry" znamená dvě kliknutí na každé hledání. -->
      <template #primary>
        <div class="relative flex-1 min-w-56">
          <svg class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-neutral-400"
            fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M17 11a6 6 0 1 1-12 0 6 6 0 0 1 12 0z" />
          </svg>
          <input v-model.trim="filters.q" type="search" @keyup.enter="applyFilters" @search="applyFilters"
            :aria-label="t('accounting.journal.filter_q')"
            :placeholder="t('accounting.journal.filter_q_placeholder')"
            class="w-full h-9 pl-9 pr-3 border border-neutral-300 rounded-md text-sm focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none" />
        </div>
      </template>

      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-7 gap-3">
        <div>
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.journal.filter_document_no') }}</label>
          <input v-model.trim="filters.document_no" type="search" @keyup.enter="applyFilters" @search="applyFilters"
            :placeholder="t('accounting.journal.filter_document_no_placeholder')"
            class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm" />
        </div>
        <div>
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.journal.filter_period') }}</label>
          <select v-model="filters.period_id" @change="onPeriodChange"
            class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface">
            <option value="">{{ t('common.all') }}</option>
            <option v-for="p in periods" :key="p.id" :value="p.id">{{ p.fiscal_year }}</option>
          </select>
        </div>
        <div>
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.journal.filter_date_from') }}</label>
          <DateInput v-model="filters.date_from" @change="applyFilters"
            class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm" />
        </div>
        <div>
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.journal.filter_date_to') }}</label>
          <DateInput v-model="filters.date_to" @change="applyFilters"
            class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm" />
        </div>
        <div>
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.journal.filter_source') }}</label>
          <select v-model="filters.source_type" @change="applyFilters"
            class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface">
            <option value="">{{ t('common.all') }}</option>
            <option v-for="s in sourceTypeOptions" :key="s" :value="s">{{ sourceLabel(s) }}</option>
          </select>
        </div>
        <div>
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.journal.filter_posted') }}</label>
          <select v-model="filters.posted" @change="applyFilters"
            class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface">
            <option value="">{{ t('common.all') }}</option>
            <option value="posted">{{ t('accounting.journal.posted') }}</option>
            <option value="draft">{{ t('accounting.journal.draft') }}</option>
          </select>
        </div>
        <div>
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.journal.filter_reversal') }}</label>
          <select v-model="filters.reversal" @change="applyFilters"
            class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface">
            <option value="">{{ t('common.all') }}</option>
            <option value="reversed">{{ t('accounting.journal.reversal_reversed') }}</option>
            <option value="reversal">{{ t('accounting.journal.reversal_reversal') }}</option>
            <option value="any">{{ t('accounting.journal.reversal_any') }}</option>
            <option value="none">{{ t('accounting.journal.reversal_none') }}</option>
          </select>
        </div>
        <div>
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('automation.journal_origin') }}</label>
          <select v-model="filters.automation" @change="applyFilters"
            class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface">
            <option value="">{{ t('automation.origin_all') }}</option>
            <option value="auto">{{ t('automation.origin_auto') }}</option>
            <option value="approved">{{ t('automation.origin_approved') }}</option>
            <option value="manual">{{ t('automation.origin_manual') }}</option>
          </select>
        </div>
        <div>
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.journal.filter_integrity') }}</label>
          <select v-model="filters.integrity" @change="applyFilters"
            class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface">
            <option value="">{{ t('common.all') }}</option>
            <option value="amount_mismatch">{{ t('accounting.journal.filter_integrity_amount_mismatch') }}</option>
          </select>
        </div>
        <div v-if="hasSingleForm">
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.pairs.filter_status') }}</label>
          <select v-model="filters.pair_status" @change="applyFilters"
            class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface">
            <option value="">{{ t('common.all') }}</option>
            <option value="composite_mn">{{ t('accounting.pairs.filter_mn') }}</option>
          </select>
        </div>
        <div>
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.journal.filter_account_from') }}</label>
          <input v-model.trim="filters.account_from" type="text" :list="`${pageId}-journal-coa`" @change="applyFilters"
            :title="accountName(filters.account_from) || undefined"
            class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm font-mono" />
          <div v-if="accountName(filters.account_from)" class="mt-1 text-xs text-neutral-500 truncate">
            {{ accountName(filters.account_from) }}
          </div>
        </div>
        <div>
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.journal.filter_account_to') }}</label>
          <input v-model.trim="filters.account_to" type="text" :list="`${pageId}-journal-coa`" @change="applyFilters"
            :title="accountName(filters.account_to) || undefined"
            class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm font-mono" />
          <div v-if="accountName(filters.account_to)" class="mt-1 text-xs text-neutral-500 truncate">
            {{ accountName(filters.account_to) }}
          </div>
        </div>
        <div>
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.journal.filter_amount_from') }}</label>
          <input v-model.number="filters.amount_from" type="number" step="0.01" @change="applyFilters"
            class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm" />
        </div>
        <div>
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.journal.filter_amount_to') }}</label>
          <input v-model.number="filters.amount_to" type="number" step="0.01" @change="applyFilters"
            class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm" />
        </div>
      </div>
      <!-- Hodnota dimenze: jen u firmy se zapnutými dimenzemi (komponenta se jinak nevykreslí). -->
      <DimensionReportFilters class="mt-3"
        :value-ids="filters.dimension_value_ids" :descendants="filters.dimension_descendants"
        @update:value-ids="onDimensionValues" @update:descendants="onDimensionDescendants" />
      <template #actions>
        <button @click="resetFilters" class="cursor-pointer text-xs text-neutral-500 hover:text-neutral-700">{{ t('accounting.journal.reset_filters') }}</button>
        <SavedFiltersMenu :ctrl="saved" />
        <ColumnPicker :ctrl="tbl" :presets="COLUMN_PRESETS" reorderable />
        <TableColorsMenu :ctrl="tbl" :colors="colors" />
        <DensityToggle class="hidden md:block" :ctrl="tbl" />
      </template>
    </FilterBar>

    <div v-if="loading" class="text-center text-neutral-500 py-12 text-sm">{{ t('common.loading') }}</div>

    <EmptyState v-else-if="entries.length === 0" boxed
      :variant="hasActiveFilters() ? 'filtered' : 'empty'"
      :icon="hasActiveFilters() ? 'search' : 'doc'"
      :title="t('accounting.journal.empty')"
      :cta="hasActiveFilters() ? t('accounting.journal.reset_filters') : undefined"
      @action="resetFilters" />

    <div v-else class="bg-surface border border-neutral-200 rounded-lg shadow-sm">
      <!-- Desktop: tabulka. Na mobilu se jedenáct sloupců deníku nedá zúžit ani
           vodorovným posunem — rozbalený detail se schová do buňky široké jako
           obrazovka a čte se přes scrollbar. Proto stack karet. -->
      <div ref="listBox" class="hidden md:block overflow-auto scrollbar-slim" @scroll.passive="onListScroll">
        <table v-column-labels="tbl" class="w-max min-w-full text-sm singleline-list-table list-gutter-mini" :class="tbl.densityClass.value">
          <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide sticky top-0 z-20 shadow-sm">
            <tr>
              <th class="px-3 py-2 w-8"></th>
              <template v-for="c in tbl.orderedColumns.value.filter(c => tbl.isVisible(c.key))" :key="c.key">
                <th v-if="c.key === 'dimensions'" scope="col" class="px-3 py-2 text-left font-medium" v-bind="columnDrag.headerAttrs(c.key)"><ColumnDragHandle /> {{ t(c.labelKey) }}</th>
                <SortableTh v-else v-bind="columnDrag.headerAttrs(c.key)" reorderable :label="t(c.labelKey)" :sort-key="c.key" :sort="tbl.sort.value"
                  :align="c.key === 'amount' ? 'right' : 'left'" :data-adaptive-weight="c.key === 'description' ? 2 : undefined" :class="c.key === 'description' ? 'wrap-cell' : ''" @toggle="onSortToggle" />
              </template>
              <th class="px-1 py-2 w-8">
                <button v-if="tbl.sort.value" type="button" class="inline-flex h-6 w-6 items-center justify-center rounded text-neutral-500 hover:bg-neutral-200 hover:text-neutral-800"
                  :title="t('common.reset_sort')" :aria-label="t('common.reset_sort')" @click.stop="clearSort">×</button>
              </th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <template v-for="e in entries" :key="e.id">
              <!-- Rozbalený zápis je podbarvený i s hlavičkou a nese levou lištu
                   v akcentu: detail je vysoký přes celou obrazovku a bez toho se
                   při odrolování ztratí, na kterém řádku vlastně pracuju. -->
              <tr class="cursor-pointer scroll-mt-12" :data-journal-entry="e.id" :aria-current="entryIdFilter === e.id ? 'true' : undefined" :class="[
                    e.reversed_by && !colors.hasColors.value ? 'opacity-60' : '',
                    entryIdFilter === e.id ? 'ring-2 ring-inset ring-primary-500' : '',
                    isExpanded(e.id)
                      ? 'bg-primary-50/60 border-x-2 border-t-2 border-primary-500/60'
                      : entryIdFilter === e.id ? 'bg-primary-50' : 'hover:bg-neutral-50',
                  ]" @click="toggleExpand(e)">
                <td class="px-3 py-2 text-neutral-400">
                  <span class="inline-block transition-transform" :class="{ 'rotate-90': isExpanded(e.id) }">▸</span>
                </td>
                <template v-for="c in tbl.orderedColumns.value.filter(c => tbl.isVisible(c.key))" :key="c.key">
                <td v-if="c.key === 'date'" :data-custom-color="!!colors.color('date')" :style="colors.cellStyle('date')" class="px-3 py-2 whitespace-nowrap">{{ formatDate(e.entry_date) }}</td>
                <td v-else-if="c.key === 'document_no'" :data-custom-color="!!colors.color('document_no')" :style="colors.cellStyle('document_no')" class="px-3 py-2 font-mono text-xs">
                  {{ e.document_no || '—' }}
                  <div v-if="e.source_bank_ref && e.source_bank_ref !== e.document_no"
                       class="text-[10px] text-neutral-400 whitespace-nowrap"
                       :title="t('accounting.journal.bank_ref_hint', { ref: e.source_bank_ref })">{{ e.source_bank_ref }}</div>
                  <RouterLink v-if="e.internal_document" :to="{ name: 'accounting-internal-document-detail', params: { id: e.internal_document.id } }"
                    class="block whitespace-nowrap font-sans text-[10px] text-primary-700 hover:underline" data-test="journal-internal-document" @click.stop>
                    {{ t(e.internal_document.role === 'reversal' ? 'accounting.journal.internal_document_reversal_badge' : 'accounting.journal.internal_document_badge') }}
                  </RouterLink>
                </td>
                <td v-else-if="c.key === 'document_date'" :data-custom-color="!!colors.color('document_date')" :style="colors.cellStyle('document_date')" class="px-3 py-2 whitespace-nowrap">{{ e.document_date ? formatDate(e.document_date) : '—' }}</td>
                <td v-else-if="c.key === 'description'" :data-custom-color="!!colors.color('description')" :style="colors.cellStyle('description')" class="px-3 py-2 wrap-cell" :title="e.description || undefined">
                  <span class="adaptive-primary-text">{{ e.description || '—' }}</span>
                  <span v-if="e.reversed_by" class="ml-1 text-xs px-1.5 py-0.5 rounded bg-neutral-100 text-neutral-500">{{ t('accounting.journal.reversed_badge') }}</span>
                </td>
                <td v-else-if="c.key === 'source'" :data-custom-color="!!colors.color('source')" :style="colors.cellStyle('source')" class="px-3 py-2 whitespace-nowrap clip-cell">
                  <!-- Jeden řádek: `flex-wrap` lámal odznak automatu a značku
                       vazby pod odkaz a sloupec pak vypadal jako dva různé údaje. -->
                  <div class="flex items-center gap-1.5">
                    <!--
                      Tlačítko, ne obarvený text: otevírá náhledový drawer, tedy
                      DĚLÁ něco, zatímco modrý text v tabulce slibuje navigaci.
                      Účetní tak vidí doklad bez ztráty pozice v deníku; odkaz na
                      plný detail je uvnitř draweru. @click.stop, ať se nepřepne akordeon.
                    -->
                    <!-- Ikona oka, ne „otevřít v novém": tlačítko dělá totéž co
                         „Náhled" v panelu Souvisí — otevře náhledový drawer, ne
                         navigaci pryč. Stejná akce má vypadat stejně. -->
                    <button v-if="sourceLink(e)" type="button" @click.stop="openSourceDrawer(e)"
                      :class="btnOutlineSm('primary')"
                      :title="t('accounting.journal.source_drawer.open')">
                      <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round"
                          d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0z M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                      </svg>
                      {{ sourceLabel(e.source_type) }} {{ e.source_asset_name || ('#' + e.source_id) }}
                    </button>
                    <span v-else class="text-neutral-500 text-xs">{{ sourceLabel(e.source_type) }}</span>
                    <AutomationBadge v-if="e.automation?.mode === 'auto'" variant="auto" />
                    <span v-if="e.pair_status === 'composite_mn'" :title="t('accounting.pairs.badge_mn_title')"
                      class="whitespace-nowrap text-[10px] px-1.5 py-0.5 rounded bg-warning-50 text-warning-600 font-medium" data-test="pair-badge-mn">{{ t('accounting.pairs.badge_mn') }}</span>
                    <!--
                      Odznak „má protějšek" (doklad ↔ úhrada). Bez něj by účetní musel
                      rozbalit každý řádek, aby zjistil, jestli je zápis na něco navázaný;
                      obsah vazby ukáže panel Souvisí v rozbaleném detailu.
                    -->
                    <span v-if="e.has_related" :title="t('accounting.journal.related.badge_title')"
                      class="inline-flex items-center text-neutral-400">
                      <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.link" />
                      </svg>
                    </span>
                  </div>
                </td>
                <td v-else-if="c.key === 'amount'" :style="colors.cellStyle('amount')" :data-custom-color="!!colors.color('amount')" class="px-3 py-2 text-right font-mono">
                  {{ formatMoney(e.amount ?? 0) }}
                  <!-- Jen při filtru na účet (account_from/account_to) — jinak by MD/Dal
                       značka naznačovala stranu SOUČTU zápisu, který žádnou jednoznačnou
                       stranu nemá (Σ MD = Σ Dal u vyváženého zápisu). -->
                  <span v-if="e.amount_side" class="ml-1 text-xs font-sans text-neutral-400"
                    :title="t('accounting.journal.filter_account_amount_hint')">
                    {{ t(`accounting.journal.side.${e.amount_side}`) }}
                  </span>
                </td>
                <td v-else-if="c.key === 'status'" :data-custom-color="!!colors.color('status')" :style="colors.cellStyle('status')" class="px-3 py-2 text-center">
                  <span v-if="e.posted_at" class="text-xs px-2 py-0.5 rounded font-medium bg-success-50 text-success-600">{{ t('accounting.journal.posted') }}</span>
                  <span v-else class="text-xs px-2 py-0.5 rounded font-medium bg-neutral-100 text-neutral-500">{{ t('accounting.journal.draft') }}</span>
                </td>
                <td v-else-if="c.key === 'posted_at'" :data-custom-color="!!colors.color('posted_at')" :style="colors.cellStyle('posted_at')" class="px-3 py-2 whitespace-nowrap">{{ e.posted_at ? formatDate(e.posted_at) : '—' }}</td>
                <td v-else-if="c.key === 'posted_by'" :data-custom-color="!!colors.color('posted_by')" :style="colors.cellStyle('posted_by')" class="px-3 py-2 truncate max-w-[10rem]">{{ e.posted_by_name || '—' }}</td>
                <td v-else-if="c.key === 'entry_id'" :data-custom-color="!!colors.color('entry_id')" :style="colors.cellStyle('entry_id')" class="px-3 py-2 text-right font-mono text-xs">{{ e.id }}</td>
                <td v-else-if="c.key === 'created_at'" :data-custom-color="!!colors.color('created_at')" :style="colors.cellStyle('created_at')" class="px-3 py-2 whitespace-nowrap text-xs">{{ formatDateTime(e.created_at) }}</td>
                <td v-else-if="c.key === 'updated_at'" :data-custom-color="!!colors.color('updated_at')" :style="colors.cellStyle('updated_at')" class="px-3 py-2 whitespace-nowrap text-xs">{{ formatDateTime(e.updated_at) }}</td>
                <td v-else-if="c.key === 'vat_breakdown'" :data-custom-color="!!colors.color('vat_breakdown')" :style="colors.cellStyle('vat_breakdown')" class="px-3 py-2"><VatBreakdownCell :rows="e.vat_breakdown" :currency="e.source_currency || 'CZK'" /></td>
                <td v-else-if="c.key === 'debit_accounts'" :data-custom-color="!!colors.color('debit_accounts')" :style="colors.cellStyle('debit_accounts')" class="px-3 py-2 font-mono text-xs truncate max-w-[11rem]" :title="postingAccounts(e, 'debit')">{{ postingAccounts(e, 'debit') }}</td>
                <td v-else-if="c.key === 'credit_accounts'" :data-custom-color="!!colors.color('credit_accounts')" :style="colors.cellStyle('credit_accounts')" class="px-3 py-2 font-mono text-xs truncate max-w-[11rem]" :title="postingAccounts(e, 'credit')">{{ postingAccounts(e, 'credit') }}</td>
                <td v-else-if="c.key === 'note'" :data-custom-color="!!colors.color('note')" :style="colors.cellStyle('note')" class="px-3 py-2 text-xs max-w-64 truncate" :title="e.note_preview || undefined">
                  <template v-if="e.note_preview">{{ e.note_preview }}<span v-if="(e.note_count ?? 0) > 1" class="ml-1 text-neutral-400">+{{ (e.note_count ?? 0) - 1 }}</span></template>
                  <span v-else class="text-neutral-400">—</span>
                </td>
                <td v-else-if="c.key === 'documents'" :data-custom-color="!!colors.color('documents')" :style="colors.cellStyle('documents')" class="px-3 py-2 whitespace-nowrap text-xs">
                  <span v-if="e.document_count" class="inline-flex items-center gap-1 text-neutral-600" :title="t('accounting.journal.col_documents_hint', { n: e.document_count })">
                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.doc" /></svg>{{ e.document_count }}
                  </span>
                  <span v-else class="text-neutral-400">—</span>
                </td>
                <td v-else-if="c.key === 'dimensions'" :data-custom-color="!!colors.color('dimensions')" :style="colors.cellStyle('dimensions')" class="px-3 py-2 text-xs max-w-64 truncate" :title="e.dimension_labels?.join(' · ')">{{ e.dimension_labels?.join(' · ') || '—' }}</td>
                </template>
                <td class="w-8"></td>
              </tr>
              <!-- Detail (rozbalený) -->
              <tr v-if="isExpanded(e.id)" class="table-detail-row">
                <td :colspan="visibleColCount"
                  class="px-3 py-3 bg-primary-50/60 border-x-2 border-b-2 border-primary-500/60">
                  <div data-test="journal-detail-viewport" :style="detailViewportStyle">
                  <div v-if="isDetailLoading(e.id)" class="text-center text-neutral-500 py-4 text-sm">{{ t('common.loading') }}</div>
                  <template v-else-if="details[e.id]">
                    <section v-if="entryHasPairs(details[e.id]!)" class="mb-3" data-test="journal-entry-pairs">
                      <div class="mb-1 text-xs font-medium text-neutral-600">{{ t('accounting.pairs.title') }}</div>
                      <JournalPairsTable :pairs="details[e.id]!.pairs!" :selectable="pairsEditable(details[e.id]!)"
                        :model-value="selectedPairs(e.id)" :disabled-pairs="pairsDisabled(details[e.id]!)"
                        :actions="pairActions(details[e.id]!)" @update:model-value="nos => setSelectedPairs(e.id, nos)"
                        @open-entry="onFocusEntry" />
                      <p v-if="pairsEditable(details[e.id]!) && details[e.id]!.source_type !== 'manual' && !details[e.id]!.internal_document"
                        class="mt-1 text-xs text-neutral-500">{{ t('accounting.pairs.document_repost_only') }}</p>
                    </section>
                    <JournalEntryDetailPanel
                      :detail="details[e.id]!" :related-key="relatedVersion[e.id] ?? 0"
                      :can-write="auth.canWrite('accounting')" :can-delete="canDeleteEntry(details[e.id]!)"
                      :can-delete-pair="canDeletePair(details[e.id]!)"
                      :date-from="entryRange(details[e.id]!).from" :date-to="entryRange(details[e.id]!).to"
                      @preview="id => sourceDrawerEntryId = id" @focus-entry="onFocusEntry"
                      @description-updated="onDescriptionUpdated" @links-changed="onLinksChanged"
                      @reverse="reverse" @remove="deleteEntry" @remove-pair="deleteEntryPair" @open-reversal="openReversal"
                      @reposted="onReposted" />
                  </template>
                  </div>
                </td>
              </tr>
            </template>
          </tbody>
        </table>
      </div>

      <!-- Mobil: stack karet. Sloupce, které si uživatel skryl přes ColumnPicker,
           se neskrývají — picker je desktopový ovladač a na kartě jde o jiné,
           vertikální rozvržení, kde se zápis stejně vejde celý. -->
      <div class="md:hidden divide-y divide-neutral-100">
        <div v-for="e in entries" :key="`m-${e.id}`" :data-journal-entry="e.id" :aria-current="entryIdFilter === e.id ? 'true' : undefined" class="scroll-mt-12"
          :class="[isExpanded(e.id) || entryIdFilter === e.id ? 'bg-primary-50/60' : '', entryIdFilter === e.id ? 'ring-2 ring-inset ring-primary-500' : '']">
          <button type="button" class="cursor-pointer w-full text-left p-3 space-y-1.5"
            :class="e.reversed_by && !colors.hasColors.value ? 'opacity-60' : ''" @click="toggleExpand(e)">
            <div class="flex items-baseline justify-between gap-2">
              <span class="flex items-baseline gap-1.5 min-w-0">
                <span class="text-neutral-400 shrink-0 inline-block transition-transform"
                  :class="{ 'rotate-90': isExpanded(e.id) }">▸</span>
                <span class="font-mono text-xs text-neutral-600" :data-custom-color="!!colors.color('document_no')" :style="colors.cellStyle('document_no')">{{ e.document_no || '—' }}</span>
                <span v-if="e.source_bank_ref && e.source_bank_ref !== e.document_no"
                      class="font-mono text-[10px] text-neutral-400 truncate"
                      :title="t('accounting.journal.bank_ref_hint', { ref: e.source_bank_ref })">{{ e.source_bank_ref }}</span>
                <RouterLink v-if="e.internal_document" :to="{ name: 'accounting-internal-document-detail', params: { id: e.internal_document.id } }"
                  class="whitespace-nowrap text-[10px] text-primary-700 hover:underline" @click.stop>
                  {{ t(e.internal_document.role === 'reversal' ? 'accounting.journal.internal_document_reversal_badge' : 'accounting.journal.internal_document_badge') }}
                </RouterLink>
              </span>
              <span class="font-mono text-sm font-semibold whitespace-nowrap" :data-custom-color="!!colors.color('amount')" :style="colors.cellStyle('amount')">
                {{ formatMoney(e.amount ?? 0) }}
                <span v-if="e.amount_side" class="ml-1 text-xs font-sans font-normal text-neutral-400">
                  {{ t(`accounting.journal.side.${e.amount_side}`) }}
                </span>
              </span>
            </div>
            <div class="text-sm text-neutral-900" :data-custom-color="!!colors.color('description')" :style="colors.cellStyle('description')">
              {{ e.description || '—' }}
              <span v-if="e.reversed_by" class="ml-1 text-xs px-1.5 py-0.5 rounded bg-neutral-100 text-neutral-500">{{ t('accounting.journal.reversed_badge') }}</span>
            </div>
            <div class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-neutral-500">
              <span class="font-mono" :data-custom-color="!!colors.color('date')" :style="colors.cellStyle('date')">{{ formatDate(e.entry_date) }}</span>
              <span class="text-neutral-400">·</span>
              <span :data-custom-color="!!colors.color('source')" :style="colors.cellStyle('source')">{{ sourceLabel(e.source_type) }}<template v-if="e.source_asset_name || e.source_id"> {{ e.source_asset_name || ('#' + e.source_id) }}</template></span>
              <AutomationBadge v-if="e.automation?.mode === 'auto'" variant="auto" />
              <span v-if="e.pair_status === 'composite_mn'" :title="t('accounting.pairs.badge_mn_title')"
                class="whitespace-nowrap text-[10px] px-1.5 py-0.5 rounded bg-warning-50 text-warning-600 font-medium">{{ t('accounting.pairs.badge_mn') }}</span>
              <span v-if="e.has_related" :title="t('accounting.journal.related.badge_title')" class="inline-flex items-center text-neutral-400">
                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                  <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.link" />
                </svg>
              </span>
              <span v-if="e.posted_at" class="text-xs px-2 py-0.5 rounded font-medium bg-success-50 text-success-600" :data-custom-color="!!colors.color('status')" :style="colors.cellStyle('status')">{{ t('accounting.journal.posted') }}</span>
              <span v-else class="text-xs px-2 py-0.5 rounded font-medium bg-neutral-100 text-neutral-500" :data-custom-color="!!colors.color('status')" :style="colors.cellStyle('status')">{{ t('accounting.journal.draft') }}</span>
            </div>
            <div v-if="mobileExtraFields(e).length || tbl.isVisible('vat_breakdown')" class="mt-2 border-t border-neutral-200 pt-2 grid grid-cols-2 gap-x-3 gap-y-2 text-xs">
              <div v-for="field in mobileExtraFields(e)" :key="field.key" :data-custom-color="!!colors.color(field.key)" :style="colors.cellStyle(field.key)" class="min-w-0">
                <div class="text-neutral-500">{{ field.label }}</div>
                <div class="font-medium text-neutral-800 break-words">{{ field.value }}</div>
              </div>
              <div v-if="tbl.isVisible('vat_breakdown')" class="col-span-2" :data-custom-color="!!colors.color('vat_breakdown')" :style="colors.cellStyle('vat_breakdown')">
                <div class="text-neutral-500 mb-1">{{ t('invoice.col_vat_breakdown') }}</div>
                <VatBreakdownCell :rows="e.vat_breakdown" :currency="e.source_currency || 'CZK'" />
              </div>
            </div>
          </button>
          <!-- Náhled dokladu mimo rozbalovací tlačítko: vnořené tlačítko není
               platné HTML a klik by se protáhl do akordeonu. -->
          <div v-if="sourceLink(e)" class="px-3 pb-3 -mt-1">
            <button type="button" :class="btnOutlineSm('primary')" @click="openSourceDrawer(e)">
              <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round"
                  d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0z M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
              </svg>
              {{ t('accounting.journal.source_drawer.open') }}
            </button>
          </div>
          <div v-if="isExpanded(e.id)" class="px-3 pb-3 border-t border-primary-500/30">
            <div v-if="isDetailLoading(e.id)" class="text-center text-neutral-500 py-4 text-sm">{{ t('common.loading') }}</div>
            <div v-else-if="details[e.id]" class="pt-3">
              <section v-if="entryHasPairs(details[e.id]!)" class="mb-3" data-test="journal-entry-pairs">
                <div class="mb-1 text-xs font-medium text-neutral-600">{{ t('accounting.pairs.title') }}</div>
                <JournalPairsTable :pairs="details[e.id]!.pairs!" :selectable="pairsEditable(details[e.id]!)"
                  :model-value="selectedPairs(e.id)" :disabled-pairs="pairsDisabled(details[e.id]!)"
                  :actions="pairActions(details[e.id]!)" @update:model-value="nos => setSelectedPairs(e.id, nos)"
                  @open-entry="onFocusEntry" />
                <p v-if="pairsEditable(details[e.id]!) && details[e.id]!.source_type !== 'manual' && !details[e.id]!.internal_document"
                  class="mt-1 text-xs text-neutral-500">{{ t('accounting.pairs.document_repost_only') }}</p>
              </section>
              <JournalEntryDetailPanel
                :detail="details[e.id]!" :related-key="relatedVersion[e.id] ?? 0"
                :can-write="auth.canWrite('accounting')" :can-delete="canDeleteEntry(details[e.id]!)"
                :can-delete-pair="canDeletePair(details[e.id]!)"
                :date-from="entryRange(details[e.id]!).from" :date-to="entryRange(details[e.id]!).to"
                @preview="id => sourceDrawerEntryId = id" @focus-entry="onFocusEntry"
                @description-updated="onDescriptionUpdated" @links-changed="onLinksChanged"
                @reverse="reverse" @remove="deleteEntry" @remove-pair="deleteEntryPair" @open-reversal="openReversal"
                @reposted="onReposted" />
            </div>
          </div>
        </div>
      </div>
    </div>

    <div v-if="!loading && total > perPage" class="text-center text-sm">
      <div v-if="page < totalPages" ref="loadMoreTarget" class="text-center text-sm text-neutral-500 pointer-fine-hidden">
      </div>
    </div>
    <ListLoadingSpinner :show="loading || loadingMore" />

    <JournalSourceDrawer v-if="sourceDrawerEntryId" :entry-id="sourceDrawerEntryId"
      @close="sourceDrawerEntryId = null" @focus-entry="onFocusEntry" />
    <LockedPeriodAckModal :ref="(el: any) => { lockedAck.modal.value = el }" />

    <ConfirmDialog v-if="reversePairsConfirm.open"
      :title="t('accounting.pairs.action_reverse')"
      :message="t('accounting.pairs.reverse_confirm', { n: reversePairsConfirm.nos.length, nos: reversePairsConfirm.nos.join(', ') }, reversePairsConfirm.nos.length)"
      :confirm-label="t('accounting.pairs.action_reverse')" variant="danger"
      @confirm="confirmReversePairs" @cancel="reversePairsConfirm.open = false" />

    <!-- Přeúčtování jedné dvojice: nové účty MD/Dal, částka zůstává. Na místě, nebo opravným zápisem
         rozhodne server podle zámků a období. -->
    <Modal v-if="repostPairDialog.open && repostPairDialog.pair" :title="t('accounting.pairs.repost_title', { n: repostPairDialog.pair.pair_no })"
      width-class="max-w-lg" @close="repostPairDialog.open = false">
      <div class="space-y-3">
        <p class="text-sm text-neutral-600">{{ t('accounting.pairs.repost_hint', { amount: formatMoney(repostPairDialog.pair.amount) }) }}</p>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('accounting.pairs.col_debit') }}</label>
            <input v-model.trim="repostPairDialog.debit" type="text" :list="`${pageId}-journal-coa`"
              class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono" data-test="repost-debit" />
            <div v-if="accountName(repostPairDialog.debit)" class="mt-1 text-xs text-neutral-500 truncate">{{ accountName(repostPairDialog.debit) }}</div>
          </div>
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('accounting.pairs.col_credit') }}</label>
            <input v-model.trim="repostPairDialog.credit" type="text" :list="`${pageId}-journal-coa`"
              class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono" data-test="repost-credit" />
            <div v-if="accountName(repostPairDialog.credit)" class="mt-1 text-xs text-neutral-500 truncate">{{ accountName(repostPairDialog.credit) }}</div>
          </div>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('accounting.pairs.col_text') }}</label>
            <input v-model="repostPairDialog.text" type="text" maxlength="255" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
          </div>
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('accounting.pairs.repost_date') }}</label>
            <DateInput v-model="repostPairDialog.date" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
            <p class="mt-1 text-xs text-neutral-500">{{ t('accounting.pairs.repost_date_hint') }}</p>
          </div>
        </div>
        <div v-if="repostPairDialog.error" class="text-sm text-danger-500" role="alert" data-test="repost-error">{{ repostPairDialog.error }}</div>
      </div>
      <template #footer>
        <div class="flex flex-wrap justify-end gap-2">
          <button type="button" :class="btnOutline('neutral')" @click="repostPairDialog.open = false">{{ t('common.cancel') }}</button>
          <button type="button" :class="btnFilled('warning')" :disabled="repostPairDialog.saving" data-test="repost-save" @click="submitRepostPair">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
            {{ repostPairDialog.saving ? t('common.saving') : t('accounting.pairs.action_repost') }}
          </button>
        </div>
      </template>
    </Modal>

    <datalist :id="`${pageId}-journal-coa`">
      <option v-for="a in activeAccounts" :key="a.id" :value="a.account_code">
        {{ a.account_code }} - {{ a.name }}
      </option>
    </datalist>
  </div>
</template>

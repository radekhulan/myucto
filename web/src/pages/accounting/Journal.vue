<script setup lang="ts">
import { ref, onMounted, reactive, computed, watch, useId } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink, useRoute, useRouter, type RouteLocationRaw } from 'vue-router'
import {
  accountingApi,
  type JournalEntry,
  type JournalEntryDetail,
  type AccountingPeriod,
  type ChartAccount,
} from '@/api/accounting'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { formatDate, formatMoney } from '@/composables/useFormat'
import SavedFiltersMenu from '@/components/ui/SavedFiltersMenu.vue'
import FilterBar, { type FilterChip } from '@/components/ui/FilterBar.vue'
import ColumnPicker from '@/components/ui/ColumnPicker.vue'
import DensityToggle from '@/components/ui/DensityToggle.vue'
import { useTablePrefs, type ColumnDef } from '@/composables/useTablePrefs'
import { useSavedFilters, savedFilterTone, type SavedFilterTone } from '@/composables/useSavedFilters'
import type { SavedFilter } from '@/api/preferences'
import { ICONS, btnFilled, btnOutline, btnOutlineSm } from '@/components/ui/buttonStyles'
import EmptyState from '@/components/ui/EmptyState.vue'
import JournalEntryExtras from '@/components/accounting/JournalEntryExtras.vue'
import LinkedDocumentsPanel from '@/components/documents/LinkedDocumentsPanel.vue'
import AutomationBadge from '@/components/automation/AutomationBadge.vue'
import WhyPanel from '@/components/automation/WhyPanel.vue'
import ActivationBanner from '@/components/settings/activation/ActivationBanner.vue'
import JournalSourceDrawer from '@/components/accounting/JournalSourceDrawer.vue'
import JournalRelatedPanel from '@/components/accounting/JournalRelatedPanel.vue'
import JournalLinesTable from '@/components/accounting/JournalLinesTable.vue'
import { journalSourceLink } from '@/utils/journalSourceLink'

const { t } = useI18n()
const auth = useAuthStore()
const toast = useToast()
const route = useRoute()
const router = useRouter()
const pageId = useId()

const entries = ref<JournalEntry[]>([])
const periods = ref<AccountingPeriod[]>([])
const loading = ref(false)

const page = ref(1)
const total = ref(0)
const perPage = ref(50)
const totalPages = computed(() => Math.max(1, Math.ceil(total.value / perPage.value)))
const rangeFrom = computed(() => (total.value === 0 ? 0 : (page.value - 1) * perPage.value + 1))
const rangeTo = computed(() => Math.min(page.value * perPage.value, total.value))

const filters = reactive({
  document_no: '',
  period_id: '' as number | '',
  date_from: '',
  date_to: '',
  source_type: '' as '' | (typeof SOURCE_TYPES)[number],
  posted: '' as '' | 'posted' | 'draft',
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
  'manual', 'invoice', 'purchase_invoice', 'bank', 'gopay', 'cash',
  'depreciation', 'asset', 'asset_disposal',
  'closing', 'opening', 'fx_revaluation', 'stock',
  'offset', 'settlement', 'vat_clearing',
] as const

// Drill-down z detailu dokladu (FV/PF): ?source_type=&source_id= → filtruje deník na
// zápis(y) přesně tohoto dokladu. Deep-link, ne uživatelský ovladač → mimo saved filters;
// jakmile uživatel sáhne na filtry, omezení se uvolní (viz applyFilters/resetFilters).
const sourceIdFilter = ref<number | ''>('')

// Totéž pro ?entry_id= — odskok na JEDEN konkrétní zápis. Dřív se místo filtru jen
// zúžilo datum na entry_date, takže se vedle hledaného zápisu vypsal celý ten den;
// u prokliku z nálezu integrity deníku to mate, protože nesouvisející zápisy vypadají
// jako součást nálezu.
const entryIdFilter = ref<number | ''>('')

async function load() {
  loading.value = true
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
      automation: filters.automation || undefined,
      q: filters.q || undefined,
      account_from: filters.account_from || undefined,
      account_to: filters.account_to || undefined,
      amount_from: filters.amount_from === '' ? undefined : Number(filters.amount_from),
      amount_to: filters.amount_to === '' ? undefined : Number(filters.amount_to),
      integrity: filters.integrity || undefined,
    })
    entries.value = r.items
    total.value = r.total
    perPage.value = r.per_page
  } finally {
    loading.value = false
  }
}

function applyFilters() {
  // Uživatel sáhl na filtry → uvolni drill-down omezení na konkrétní doklad i zápis.
  sourceIdFilter.value = ''
  entryIdFilter.value = ''
  page.value = 1
  syncFiltersToUrl()
  load()
}
function resetFilters() {
  filters.document_no = ''
  filters.period_id = ''
  filters.date_from = ''
  filters.date_to = ''
  filters.source_type = ''
  filters.posted = ''
  filters.automation = ''
  filters.q = ''
  filters.account_from = ''
  filters.account_to = ''
  filters.amount_from = ''
  filters.amount_to = ''
  filters.integrity = ''
  sourceIdFilter.value = ''
  entryIdFilter.value = ''
  applyFilters()
}

function goToPage(p: number) {
  const np = Math.min(Math.max(1, p), totalPages.value)
  if (np !== page.value) { page.value = np; load(); collapseAll() }
}

function buildQuery(): Record<string, string> {
  const q: Record<string, string> = {}
  if (filters.document_no) q.document_no = filters.document_no
  if (filters.period_id !== '') q.period_id = String(filters.period_id)
  if (filters.date_from) q.date_from = filters.date_from
  if (filters.date_to) q.date_to = filters.date_to
  if (filters.source_type) q.source_type = filters.source_type
  if (filters.posted) q.posted = filters.posted
  if (filters.automation) q.automation = filters.automation
  if (filters.q) q.q = filters.q
  if (filters.account_from) q.account_from = filters.account_from
  if (filters.account_to) q.account_to = filters.account_to
  if (filters.amount_from !== '') q.amount_from = String(filters.amount_from)
  if (filters.amount_to !== '') q.amount_to = String(filters.amount_to)
  if (filters.integrity) q.integrity = filters.integrity
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

function applyQueryToPage(q: Record<string, string>) {
  // Uložený filtr přepisuje URL sám — sync i reset watcher se na ten jeden tick uspí,
  // ať se nepřepíšou navzájem (prázdný uložený filtr by se jinak vyhodnotil jako
  // „klik z menu" a hned se zase zrušil).
  suppressUrlSync = true
  setTimeout(() => { suppressUrlSync = false }, 0)
  void router.replace({ query: q })
  filters.document_no = q.document_no ?? ''
  filters.period_id = q.period_id ? Number(q.period_id) : ''
  filters.date_from = q.date_from ?? ''
  filters.date_to = q.date_to ?? ''
  filters.source_type = (SOURCE_TYPES as readonly string[]).includes(q.source_type ?? '')
    ? (q.source_type as typeof filters.source_type) : ''
  filters.posted = q.posted === 'posted' || q.posted === 'draft' ? q.posted : ''
  filters.automation = q.automation === 'auto' || q.automation === 'approved' || q.automation === 'manual'
    ? q.automation : ''
  filters.q = q.q ?? ''
  filters.account_from = q.account_from ?? ''
  filters.account_to = q.account_to ?? ''
  filters.amount_from = q.amount_from ? Number(q.amount_from) : ''
  filters.amount_to = q.amount_to ? Number(q.amount_to) : ''
  filters.integrity = q.integrity === 'amount_mismatch' ? 'amount_mismatch' : ''
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
  if (filters.automation) n++
  if (filters.q) n++
  if (filters.account_from || filters.account_to) n++
  if (filters.amount_from !== '' || filters.amount_to !== '') n++
  if (filters.integrity) n++
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
  if (filters.automation) chips.push({ key: 'automation', value: t(`automation.origin_${filters.automation}`) })
  if (filters.q) chips.push({ key: 'q', label: t('accounting.journal.filter_q'), value: filters.q })
  if (filters.account_from || filters.account_to) {
    chips.push({ key: 'account_range', label: t('accounting.journal.filter_account_from'), value: `${filters.account_from || '…'} – ${filters.account_to || '…'}` })
  }
  if (filters.amount_from !== '' || filters.amount_to !== '') {
    chips.push({ key: 'amount_range', label: t('accounting.journal.filter_amount_from'), value: `${filters.amount_from !== '' ? filters.amount_from : '…'} – ${filters.amount_to !== '' ? filters.amount_to : '…'}` })
  }
  if (filters.integrity) chips.push({ key: 'integrity', value: t('accounting.journal.filter_integrity') })
  return chips
})

function clearFilter(key: string) {
  switch (key) {
    case 'document_no': filters.document_no = ''; break
    case 'period': filters.period_id = ''; break
    case 'dates': filters.date_from = ''; filters.date_to = ''; break
    case 'source_type': filters.source_type = ''; break
    case 'posted': filters.posted = ''; break
    case 'automation': filters.automation = ''; break
    case 'q': filters.q = ''; break
    case 'account_range': filters.account_from = ''; filters.account_to = ''; break
    case 'amount_range': filters.amount_from = ''; filters.amount_to = ''; break
    case 'integrity': filters.integrity = ''; break
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
watch(() => route.query.entry_id, async (raw) => {
  const id = Number(raw || 0)
  if (id <= 0 || id === entryIdFilter.value) return
  sourceDrawerEntryId.value = null
  if (!await focusEntry(id)) toast.error(t('common.error'))
})

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
]
const tbl = useTablePrefs('journal', COLUMNS)
const saved = useSavedFilters('journal', { getQuery: buildQuery, applyQuery: applyQueryToPage })
const visibleColCount = computed(() => 1 + tbl.columns.filter(c => tbl.isVisible(c.key)).length)

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
  if (filters.automation) q.automation = filters.automation
  if (filters.q) q.q = filters.q
  if (filters.account_from) q.account_from = filters.account_from
  if (filters.account_to) q.account_to = filters.account_to
  if (filters.amount_from !== '') q.amount_from = filters.amount_from
  if (filters.amount_to !== '') q.amount_to = filters.amount_to
  if (filters.integrity) q.integrity = filters.integrity
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
  try { periods.value = await accountingApi.listPeriods() } catch { periods.value = [] }
  // Osnova jen pro našeptávání filtru — výpadek nesmí zabránit načtení deníku.
  accountingApi.listAccounts().then(v => { accounts.value = v }).catch(() => { accounts.value = [] })
  // Předvyplnění filtrů z query (drill-down z uzávěrky / sestav / karty účtu).
  const qPeriod = Number(route.query.period_id || 0)
  if (qPeriod > 0) filters.period_id = qPeriod
  // Rozsah účtu + datumu — proklik „Deník" z karty účtu a z opisu účtu.
  for (const key of ['account_from', 'account_to', 'date_from', 'date_to'] as const) {
    const v = route.query[key]
    if (typeof v === 'string' && v) filters[key] = v
  }
  const qSource = String(route.query.source_type || '')
  if ((SOURCE_TYPES as readonly string[]).includes(qSource)) {
    filters.source_type = qSource as typeof filters.source_type
  }
  const qSourceId = Number(route.query.source_id || 0)
  if (qSourceId > 0) sourceIdFilter.value = qSourceId
  const entryId = Number(route.query.entry_id || 0)
  // Neexistující/cizí zápis → pokračuje běžné načtení.
  if (entryId > 0 && await focusEntry(entryId)) return
  if (Object.keys(route.query).length === 0 && await saved.applyDefaultIfAny()) return
  await load()
})

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

function canDeleteEntry(entry: JournalEntryDetail): boolean {
  if (entry.reversed_by) return false
  if (!['manual', 'invoice', 'purchase_invoice', 'bank', 'depreciation'].includes(entry.source_type)) return false
  if (entry.source_type !== 'manual' && !entry.source_id) return false
  return periods.value.find(period => period.id === entry.period_id)?.status === 'open'
}

async function deleteEntry(entry: JournalEntryDetail) {
  const confirmKey = entry.source_type === 'depreciation'
    ? 'accounting.journal.delete_depreciation_confirm'
    : entry.source_type === 'bank'
      ? 'accounting.journal.delete_bank_confirm'
      : entry.source_type === 'manual'
        ? 'accounting.journal.delete_manual_confirm'
        : 'accounting.journal.delete_confirm'
  if (!confirm(t(confirmKey, { id: entry.id }))) return
  try {
    await accountingApi.deleteEntry(entry.id)
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
 * @returns false, když zápis neexistuje nebo patří jinému tenantovi
 */
async function focusEntry(entryId: number): Promise<boolean> {
  let d: JournalEntryDetail
  try {
    d = await accountingApi.getEntry(entryId)
  } catch {
    return false
  }
  filters.document_no = ''
  filters.period_id = ''
  filters.source_type = ''
  filters.posted = ''
  filters.automation = ''
  filters.q = ''
  filters.account_from = ''
  filters.account_to = ''
  filters.amount_from = ''
  filters.amount_to = ''
  filters.integrity = ''
  sourceIdFilter.value = ''
  entryIdFilter.value = entryId
  filters.date_from = d.entry_date
  filters.date_to = d.entry_date
  page.value = 1
  await load()
  // Proklik na konkrétní zápis ho ukáže rozbalený sám, ostatní k němu nepatří.
  expandedIds.value = [entryId]
  details.value = { [entryId]: d }
  detailLoadingIds.value = []
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
  if (!await focusEntry(entryId)) {
    toast.error(t('common.error'))
    return
  }
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
  return journalSourceLink(entry)
}
</script>

<template>
  <div>
    <ActivationBanner />
    <div class="flex items-center justify-between mb-4">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('accounting.journal.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5">{{ t('accounting.journal.subtitle') }}</p>
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
          <select v-model="filters.period_id" @change="applyFilters"
            class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface">
            <option value="">{{ t('common.all') }}</option>
            <option v-for="p in periods" :key="p.id" :value="p.id">{{ p.fiscal_year }}</option>
          </select>
        </div>
        <div>
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.journal.filter_date_from') }}</label>
          <input v-model="filters.date_from" type="date" @change="applyFilters"
            class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm" />
        </div>
        <div>
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.journal.filter_date_to') }}</label>
          <input v-model="filters.date_to" type="date" @change="applyFilters"
            class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm" />
        </div>
        <div>
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.journal.filter_source') }}</label>
          <select v-model="filters.source_type" @change="applyFilters"
            class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface">
            <option value="">{{ t('common.all') }}</option>
            <option v-for="s in SOURCE_TYPES" :key="s" :value="s">{{ sourceLabel(s) }}</option>
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
          <p class="text-[11px] text-neutral-500 mt-1">{{ t('accounting.journal.filter_integrity_hint') }}</p>
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
      <template #actions>
        <button @click="resetFilters" class="cursor-pointer text-xs text-neutral-500 hover:text-neutral-700">{{ t('accounting.journal.reset_filters') }}</button>
        <SavedFiltersMenu :ctrl="saved" />
        <ColumnPicker class="hidden md:block" :ctrl="tbl" />
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

    <div v-else class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
      <div class="overflow-x-auto">
        <table class="w-full text-sm" :class="tbl.densityClass.value">
          <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
            <tr>
              <th class="px-3 py-2 w-8"></th>
              <th v-if="tbl.isVisible('date')" class="px-3 py-2 text-left font-medium w-28">{{ t('accounting.journal.entry_date') }}</th>
              <th v-if="tbl.isVisible('document_no')" class="px-3 py-2 text-left font-medium w-32">{{ t('accounting.journal.document_no') }}</th>
              <th v-if="tbl.isVisible('document_date')" class="px-3 py-2 text-left font-medium w-28">{{ t('accounting.journal.col_document_date') }}</th>
              <th v-if="tbl.isVisible('description')" class="px-3 py-2 text-left font-medium">{{ t('accounting.journal.description') }}</th>
              <th v-if="tbl.isVisible('source')" class="px-3 py-2 text-left font-medium w-48">{{ t('accounting.journal.source_col') }}</th>
              <th v-if="tbl.isVisible('amount')" class="px-3 py-2 text-right font-medium w-32">{{ t('accounting.journal.col_amount') }}</th>
              <th v-if="tbl.isVisible('status')" class="px-3 py-2 text-center font-medium w-24">{{ t('accounting.journal.status_col') }}</th>
              <th v-if="tbl.isVisible('posted_at')" class="px-3 py-2 text-left font-medium w-28">{{ t('accounting.journal.col_posted_at') }}</th>
              <th v-if="tbl.isVisible('posted_by')" class="px-3 py-2 text-left font-medium w-36">{{ t('accounting.journal.col_posted_by') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <template v-for="e in entries" :key="e.id">
              <!-- Rozbalený zápis je podbarvený i s hlavičkou a nese levou lištu
                   v akcentu: detail je vysoký přes celou obrazovku a bez toho se
                   při odrolování ztratí, na kterém řádku vlastně pracuju. -->
              <tr class="cursor-pointer" :class="[
                    e.reversed_by ? 'opacity-60' : '',
                    isExpanded(e.id)
                      ? 'bg-primary-50/60 border-x-2 border-t-2 border-primary-500/60'
                      : 'hover:bg-neutral-50',
                  ]" @click="toggleExpand(e)">
                <td class="px-3 py-2 text-neutral-400">
                  <span class="inline-block transition-transform" :class="{ 'rotate-90': isExpanded(e.id) }">▸</span>
                </td>
                <td v-if="tbl.isVisible('date')" class="px-3 py-2 whitespace-nowrap">{{ formatDate(e.entry_date) }}</td>
                <td v-if="tbl.isVisible('document_no')" class="px-3 py-2 font-mono text-xs">{{ e.document_no || '—' }}</td>
                <td v-if="tbl.isVisible('document_date')" class="px-3 py-2 whitespace-nowrap">{{ e.document_date ? formatDate(e.document_date) : '—' }}</td>
                <td v-if="tbl.isVisible('description')" class="px-3 py-2">
                  {{ e.description || '—' }}
                  <span v-if="e.reversed_by" class="ml-1 text-xs px-1.5 py-0.5 rounded bg-neutral-100 text-neutral-500">{{ t('accounting.journal.reversed_badge') }}</span>
                </td>
                <td v-if="tbl.isVisible('source')" class="px-3 py-2 whitespace-nowrap">
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
                <td v-if="tbl.isVisible('amount')" class="px-3 py-2 text-right font-mono">
                  {{ formatMoney(e.amount ?? 0) }}
                  <!-- Jen při filtru na účet (account_from/account_to) — jinak by MD/Dal
                       značka naznačovala stranu SOUČTU zápisu, který žádnou jednoznačnou
                       stranu nemá (Σ MD = Σ Dal u vyváženého zápisu). -->
                  <span v-if="e.amount_side" class="ml-1 text-xs font-sans text-neutral-400"
                    :title="t('accounting.journal.filter_account_amount_hint')">
                    {{ t(`accounting.journal.side.${e.amount_side}`) }}
                  </span>
                </td>
                <td v-if="tbl.isVisible('status')" class="px-3 py-2 text-center">
                  <span v-if="e.posted_at" class="text-xs px-2 py-0.5 rounded font-medium bg-success-50 text-success-600">{{ t('accounting.journal.posted') }}</span>
                  <span v-else class="text-xs px-2 py-0.5 rounded font-medium bg-neutral-100 text-neutral-500">{{ t('accounting.journal.draft') }}</span>
                </td>
                <td v-if="tbl.isVisible('posted_at')" class="px-3 py-2 whitespace-nowrap">{{ e.posted_at ? formatDate(e.posted_at) : '—' }}</td>
                <td v-if="tbl.isVisible('posted_by')" class="px-3 py-2 truncate max-w-[10rem]">{{ e.posted_by_name || '—' }}</td>
              </tr>
              <!-- Detail (rozbalený) -->
              <tr v-if="isExpanded(e.id)">
                <td :colspan="visibleColCount"
                  class="px-3 py-3 bg-primary-50/60 border-x-2 border-b-2 border-primary-500/60">
                  <div v-if="isDetailLoading(e.id)" class="text-center text-neutral-500 py-4 text-sm">{{ t('common.loading') }}</div>
                  <div v-else-if="details[e.id]">
                    <!-- Rozpad na účty — sdílená karta, tutéž ukazuje panel Souvisí
                         u protějšku, aby je účetní poznal jako stejnou věc. -->
                    <JournalLinesTable class="mb-3" :lines="details[e.id]!.lines" />
                    <!-- Souvisí hned za kontacemi: protějšek zápisu (doklad ↔ úhrada)
                         je to první, co účetní po rozpadu na účty hledá. Dřív byl až
                         pod přílohami, poznámkami a historií, tedy o obrazovku níž. -->
                    <!-- `key` s verzí vazeb: panel si data tahá sám podle entry-id,
                         takže po přidání/zrušení vazby na doklad se jinak nepřekreslí
                         a tvrdil by starý obsah. -->
                    <JournalRelatedPanel class="mt-3 block"
                      :key="`related-${e.id}-${relatedVersion[e.id] ?? 0}`"
                      :entry-id="details[e.id]!.id" show-preview
                      @preview="id => sourceDrawerEntryId = id" @focus-entry="onFocusEntry" />
                    <WhyPanel v-if="details[e.id]!.automation" class="mt-3" :provenance="details[e.id]!.automation!" />
                    <!-- Epic F7: inline editace description (§35) + přílohy §33a -->
                    <JournalEntryExtras :entry="details[e.id]!"
                      @description-updated="(desc, rv) => onDescriptionUpdated(details[e.id]!.id, desc, rv)"
                      @links-changed="onLinksChanged(details[e.id]!.id)" />
                    <LinkedDocumentsPanel class="mt-4 block" entity-type="journal_entry" :entity-id="details[e.id]!.id" />
                    <div class="flex items-center justify-between gap-3 mt-4 pt-3 border-t border-neutral-200">
                      <div class="text-xs text-neutral-500">
                        <span v-if="details[e.id]!.created_at">{{ t('accounting.journal.created_at') }}: {{ formatDate(details[e.id]!.created_at) }}</span>
                      </div>
                      <div class="flex flex-wrap items-center gap-2">
                        <RouterLink v-if="auth.canWrite('accounting')" :to="{ path: '/accounting/journal/new', query: { copy_from: String(details[e.id]!.id) } }" :class="btnOutline('neutral')">
                          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.doc" /></svg>
                          <span class="whitespace-nowrap">{{ t('accounting.journal.copy_as_new') }}</span>
                        </RouterLink>
                        <button v-if="auth.canWrite('accounting') && !details[e.id]!.reversed_by" @click="reverse(details[e.id]!)" :class="btnOutline('danger')">
                          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.uturn" /></svg>
                          {{ t('accounting.journal.reverse') }}
                        </button>
                        <button v-if="auth.canWrite('accounting') && canDeleteEntry(details[e.id]!)" @click="deleteEntry(details[e.id]!)" :class="btnOutline('danger')">
                          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.trash" /></svg>
                          {{ t('accounting.journal.delete') }}
                        </button>
                        <button v-else-if="details[e.id]!.reversed_by" type="button" @click="openReversal(details[e.id]!.reversed_by!)"
                          class="cursor-pointer text-xs text-primary-600 hover:text-primary-700 hover:underline">
                          {{ t('accounting.journal.reversal_entry') }} #{{ details[e.id]!.reversed_by }}
                        </button>
                      </div>
                    </div>
                  </div>
                </td>
              </tr>
            </template>
          </tbody>
        </table>
      </div>
    </div>

    <nav v-if="!loading && total > perPage" class="mt-4 flex items-center justify-between gap-3 text-sm">
      <span class="text-neutral-500">{{ t('common.pagination_range', { from: rangeFrom, to: rangeTo, total }) }}</span>
      <div class="flex items-center gap-1">
        <button type="button" :disabled="page <= 1" @click="goToPage(page - 1)"
          class="cursor-pointer h-8 px-3 border border-neutral-300 rounded-md hover:bg-neutral-50 disabled:opacity-40 disabled:cursor-not-allowed">‹</button>
        <span class="px-2 text-neutral-600">{{ page }} / {{ totalPages }}</span>
        <button type="button" :disabled="page >= totalPages" @click="goToPage(page + 1)"
          class="cursor-pointer h-8 px-3 border border-neutral-300 rounded-md hover:bg-neutral-50 disabled:opacity-40 disabled:cursor-not-allowed">›</button>
      </div>
    </nav>

    <JournalSourceDrawer v-if="sourceDrawerEntryId" :entry-id="sourceDrawerEntryId"
      @close="sourceDrawerEntryId = null" @focus-entry="onFocusEntry" />

    <datalist :id="`${pageId}-journal-coa`">
      <option v-for="a in activeAccounts" :key="a.id" :value="a.account_code">
        {{ a.account_code }} — {{ a.name }}
      </option>
    </datalist>
  </div>
</template>

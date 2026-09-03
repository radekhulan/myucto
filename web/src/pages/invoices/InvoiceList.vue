<script setup lang="ts">
import { ref, reactive, computed, onMounted, watch } from 'vue'
import { useRouter, useRoute, RouterLink } from 'vue-router'
import { invoicesApi, type MonthGroup, type InvoiceListItem, type InvoiceItem,
  type OssBulkResult, type OssBulkScope, type OssBulkSet, type OssBulkFailure, type OssReviewScope } from '@/api/invoices'
import { formatMoney, formatDate, formatMonth, formatNumber, statusLabel, typeLabel, statusBadgeClass, isOverdue, invoiceRowClass, displayStatus, taxDateClass } from '@/composables/useFormat'
import { useRowLink } from '@/composables/useRowLink'
import { useToast } from '@/composables/useToast'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import { useSupplierStore } from '@/stores/supplier'
import { clientsApi, type Client } from '@/api/clients'
import { revenueCategoriesApi, type RevenueCategory } from '@/api/revenueCategories'
import { codebooksApi, type Currency, type Country } from '@/api/codebooks'
import { useYearOptions } from '@/composables/useYearOptions'
import TableSkeleton from '@/components/ui/TableSkeleton.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import MultiSelectFilter, { type MultiSelectOption } from '@/components/ui/MultiSelectFilter.vue'
import FilterBar, { type FilterChip } from '@/components/ui/FilterBar.vue'
import BulkActionBar from '@/components/ui/BulkActionBar.vue'
import { markRowsTouched, consumeFlashedRows } from '@/composables/useRowFlash'
import { useListKeyboard } from '@/composables/useListKeyboard'
import WorkReportModal from '@/components/modals/WorkReportModal.vue'
import SavedFiltersMenu from '@/components/ui/SavedFiltersMenu.vue'
import ColumnPicker from '@/components/ui/ColumnPicker.vue'
import DensityToggle from '@/components/ui/DensityToggle.vue'
import { useTablePrefs, type ColumnDef } from '@/composables/useTablePrefs'
import { useSavedFilters, savedFilterTone, type SavedFilterTone } from '@/composables/useSavedFilters'
import type { SavedFilter } from '@/api/preferences'
import { ICONS, btnFilled, btnOutline } from '@/components/ui/buttonStyles'
import PostingBadge from '@/components/ui/PostingBadge.vue'
import { accountingApi, postingErrorI18nKey } from '@/api/accounting'
import { ACCOUNTING_PERIOD_MISSING_CODES, accountingPeriodRoute } from '@/api/errors'
import WorkspaceDragHandle from '@/components/workspace/WorkspaceDragHandle.vue'
import { appIsoDate } from '@/utils/date'

const { t, tm, rt } = useI18n()
const toast = useToast()
const auth = useAuthStore()
const supplierStore = useSupplierStore()
const thanksEnabled = computed(() => supplierStore.currentSupplier?.payment_thanks_enabled ?? false)
// Účetní badge „Zaúčtováno / Nezaúčtováno" jen v podvojném účetnictví (daňová evidence doklady neúčtuje).
const isDoubleEntry = computed(() => auth.hasCommercialFeatures && supplierStore.currentSupplier?.accounting_mode === 'double_entry')

const router = useRouter()
const route = useRoute()

const groups = ref<MonthGroup[]>([])
/**
 * Postupné nabíhání řádků (.stagger-in) běží JEN u prvního vykreslení seznamu.
 * Při filtrování by animace zdržovala práci — a filtr je tady to nejpoužívanější,
 * co uživatel dělá. Vypínáme ho hned, jak dorazí první dávka dat.
 */
const staggerRows = ref(true)
/**
 * Řádky k probliknutí po hromadné akci. Značku zapisují bulk handlery přes
 * markRowsTouched(), tady se po překreslení jednou spotřebuje — bez toho se
 * seznam po akci překreslí úplně stejně a uživatel nevidí, čeho se to týkalo.
 */
const flashedIds = ref<Set<number>>(new Set())
const total = ref(0)
const page = ref(1)
const pages = ref(1)
const loading = ref(false)
const loadingMore = ref(false)
const search = ref('')
const statusFilter = ref<string>('')
const typeFilter = ref<string>('')
const clientFilter = ref<number | ''>('')
const yearFilter = ref<number | ''>(new Date().getFullYear())
const monthFilter = ref<number | ''>('')
const dateFrom = ref<string>('')
const dateTo = ref<string>('')
const overdueOnly = ref(false)
const unpaidOnly = ref(false)
// Neuhrazené K DATU X (task #4) — historický protějšek unpaidOnly (dnešní status).
// Prázdný řetězec = filtr vypnutý.
const unpaidAsOf = ref<string>('')
// Zaúčtováno/nezaúčtováno (0.9) — jen podvojné účetnictví. '' = vše, '1' = zaúčtováno, '0' = nezaúčtováno.
const bookedFilter = ref<'' | '1' | '0'>('')
/**
 * Doklady s řádkem k ručnímu posouzení místa plnění (OSS). Záměrně NENÍ podmíněné
 * zapnutým OSS: příznak vzniká i u firmy, která OSS nepoužívá (číselník členských států
 * nepotvrdí sazbu → řádek zůstane mimo OSS, ale označený). Kdyby byl filtr schovaný za
 * `oss_enabled`, byly by ty doklady u takové firmy nedohledatelné.
 *
 * Tři hodnoty místo zaškrtávátka, protože sporné řádky končí na DVOU místech a každé se
 * řeší jinak: řádek v OSS = zkontrolovat zemi a typ sazby (případně vrátit do tuzemska),
 * řádek v tuzemsku = zkontrolovat, jestli tam vůbec patří. Zaškrtávátko chytalo jen ten
 * druhý, takže si uživatel filtr vyčistil a půlka mu zůstala schovaná v náhledu podání
 * a v reportu importu, který po zavření stránky zmizí. Select (ne dva selecty ani dvě
 * zaškrtávátka) kopíruje sousední filtr „Zaúčtováno" — týž tvar „vše / A / B" — a drží
 * počet aktivních filtrů na jedné položce.
 */
const ossReviewFilter = ref<'' | OssReviewScope>('')
const currencyFilter = ref<string>('')
/**
 * Kategorie tržby — jeden výběr, dva režimy. `include` ponechá jen vybrané, `exclude`
 * je naopak schová; přesně kvůli druhému případu filtr vznikl (drobné faktury za
 * předplatné mají vlastní kategorii a v seznamu jen překáží).
 *
 * Hodnoty jsou stringy, protože seznam míchá ID číselníku se sentinelem `none`
 * (doklad bez kategorie) — ten se přes číslo vyjádřit nedá a bez něj by „bez kategorie"
 * nešlo ani vybrat, ani vyloučit.
 */
const REVENUE_CATEGORY_NONE = 'none'
const revenueCategoryMode = ref<'include' | 'exclude'>('include')
const revenueCategoryIds = ref<string[]>([])
const revenueCategories = ref<RevenueCategory[]>([])
const clients = ref<Client[]>([])
const currencies = ref<Currency[]>([])

// Archivované kategorie v nabídce ZŮSTÁVAJÍ — visí na starých fakturách, takže bez nich
// by je nešlo ani najít, ani vyloučit.
const revenueCategoryOptions = computed<MultiSelectOption[]>(() => [
  { value: REVENUE_CATEGORY_NONE, label: t('invoice.revenue_category_filter_none') },
  ...revenueCategories.value.map(c => ({
    value: String(c.id),
    label: c.label,
    secondary: c.archived ? t('invoice.revenue_category_filter_archived') : undefined,
  })),
])

function revenueCategoryLabel(value: string): string {
  if (value === REVENUE_CATEGORY_NONE) return t('invoice.revenue_category_filter_none')
  return revenueCategories.value.find(c => String(c.id) === value)?.label ?? value
}

// Počet aktivních filtrů pro odznáček na mobilním tlačítku „Filtry" (rok i hledání se nepočítají — rok má výchozí hodnotu, hledání je vždy vidět)
const activeFilterCount = computed(() => {
  let n = 0
  if (statusFilter.value) n++
  if (typeFilter.value) n++
  if (clientFilter.value !== '') n++
  if (currencyFilter.value) n++
  if (monthFilter.value !== '') n++
  if (dateFrom.value || dateTo.value) n++
  if (overdueOnly.value) n++
  if (unpaidOnly.value) n++
  if (unpaidAsOf.value) n++
  if (bookedFilter.value) n++
  if (ossReviewFilter.value) n++
  if (revenueCategoryIds.value.length) n++
  return n
})

/**
 * Aktivní filtry jako odstranitelné chipy pod lištou.
 *
 * Why: filtry jsou nově sbalené za tlačítko „Filtry (N)" i na desktopu — deset
 * trvale rozbalených selectů zabíralo dva řádky nad každým seznamem, i když
 * uživatel nefiltroval. Chipy nesou tutéž informaci čitelněji: rovnou vidíš CO
 * je zapnuté, místo abys v řadě ovládacích prvků hledal, který není na výchozí
 * hodnotě. Rok se nezobrazuje ze stejného důvodu, proč se nepočítá do
 * `activeFilterCount` — má výchozí hodnotu a byl by v chipech pořád.
 */
const filterChips = computed<FilterChip[]>(() => {
  const chips: FilterChip[] = []
  if (statusFilter.value) chips.push({ key: 'status', value: statusLabel(statusFilter.value) })
  if (typeFilter.value) chips.push({ key: 'type', value: typeLabel(typeFilter.value) })
  if (clientFilter.value !== '') {
    const c = clients.value.find(x => x.id === clientFilter.value)
    if (c) chips.push({ key: 'client', value: c.company_name })
  }
  if (currencyFilter.value) chips.push({ key: 'currency', value: currencyFilter.value })
  if (monthFilter.value !== '') {
    chips.push({ key: 'month', value: monthOptions.value[Number(monthFilter.value) - 1] ?? String(monthFilter.value) })
  }
  if (dateFrom.value || dateTo.value) {
    chips.push({ key: 'dates', value: `${dateFrom.value ? formatDate(dateFrom.value) : '…'} – ${dateTo.value ? formatDate(dateTo.value) : '…'}` })
  }
  if (overdueOnly.value) chips.push({ key: 'overdue', value: t('invoice.overdue_only') })
  if (unpaidOnly.value) chips.push({ key: 'unpaid', value: t('invoice.unpaid_only') })
  if (unpaidAsOf.value) {
    chips.push({ key: 'unpaid_as_of', value: `${t('invoice.unpaid_as_of_label')}: ${formatDate(unpaidAsOf.value)}` })
  }
  if (bookedFilter.value) {
    chips.push({ key: 'booked', value: bookedFilter.value === '1' ? t('common.booked_badge') : t('common.unbooked_badge') })
  }
  // Chip nese i ROZSAH, ne jen „filtr je zapnutý" — jinak by po přepnutí na jednu
  // z větví vypadal stejně jako „vše nejisté" a uživatel by nevěděl, co má před sebou.
  if (ossReviewFilter.value) {
    chips.push({ key: 'oss_review', value: t(`invoice.oss_review_scope.${ossReviewFilter.value}`) })
  }
  // Chip musí nést i REŽIM — „Kategorie: Předplatné" a „Kategorie mimo: Předplatné"
  // jsou opačné výsledky a bez rozlišení by chip lhal.
  if (revenueCategoryIds.value.length) {
    const names = revenueCategoryIds.value.map(revenueCategoryLabel)
    const shown = names.slice(0, 2).join(', ')
    chips.push({
      key: 'revenue_category',
      label: revenueCategoryMode.value === 'exclude'
        ? t('invoice.revenue_category_filter_excluded')
        : t('invoice.revenue_category_filter'),
      value: names.length > 2
        ? `${shown} ${t('invoice.revenue_category_filter_more', { n: names.length - 2 })}`
        : shown,
    })
  }
  return chips
})

function clearFilter(key: string) {
  switch (key) {
    case 'status': statusFilter.value = ''; break
    case 'type': typeFilter.value = ''; break
    case 'client': clientFilter.value = ''; break
    case 'currency': currencyFilter.value = ''; break
    case 'month': monthFilter.value = ''; break
    case 'dates': dateFrom.value = ''; dateTo.value = ''; break
    case 'overdue': overdueOnly.value = false; break
    case 'unpaid': unpaidOnly.value = false; break
    case 'unpaid_as_of': unpaidAsOf.value = ''; break
    case 'booked': bookedFilter.value = ''; break
    case 'oss_review': ossReviewFilter.value = ''; break
    case 'revenue_category': revenueCategoryIds.value = []; break
  }
}

function clearAllFilters() {
  for (const chip of filterChips.value) clearFilter(chip.key)
}

// Prázdný seznam po filtrování je jiný stav než prázdná agenda — nabídka
// „vystav první fakturu" by tu lhala, faktury existují, jen je schoval filtr.
const hasActiveFilters = computed(() => activeFilterCount.value > 0 || !!search.value)
function clearFiltersAndSearch() {
  clearAllFilters()
  search.value = ''
}

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
 * Why: nejčastější důvod, proč uživatel klikne na fakturu, je „co na ní vlastně
 * je" — a pak se musí vracet zpátky do seznamu, který se mezitím překreslil a
 * odscrolloval. Rozbalení odpoví na tutéž otázku bez opuštění kontextu.
 * Položky se dotahují až na vyžádání, seznam sám je nenačítá.
 */
const expandedId = ref<number | null>(null)
const expandedItems = ref<InvoiceItem[] | null>(null)
const expandedLoading = ref(false)

/**
 * Šířka rozbaleného řádku = počet viditelných sloupců + zaškrtávátko + rozbalovací
 * tlačítko. Napevno zadaná hodnota by se rozešla s ColumnPickerem, kterým si
 * uživatel sloupce zapíná a vypíná.
 */
const expandedColspan = computed(() => COLUMNS.filter(c => tbl.isVisible(c.key)).length + 2)

/** Vnořená tabulka položek nemá dědit skin datových tabulek — je to náhled uvnitř řádku. */

async function toggleExpand(inv: InvoiceListItem) {
  if (expandedId.value === inv.id) {
    expandedId.value = null
    expandedItems.value = null
    return
  }
  expandedId.value = inv.id
  expandedItems.value = null
  expandedLoading.value = true
  try {
    const full = await invoicesApi.get(inv.id)
    // Slevová položka je systémová (generuje se z discount_percent) — v náhledu
    // by mátla, protože na papírové faktuře je součástí rekapitulace, ne řádků.
    expandedItems.value = full.items.filter(i => i.item_kind !== 'discount')
  } catch {
    expandedId.value = null
  } finally {
    expandedLoading.value = false
  }
}

const selectedIds = ref<number[]>([])
const bulkBusy = ref(false)
const bulkPdfOpen = ref(false)
const bulkPdfSign = ref(false)

const selectedPdfIds = computed(() => {
  const selected = new Set(selectedIds.value)
  const visible = groups.value.flatMap(group => group.invoices)
    .map(invoice => invoice.id)
    .filter(id => selected.has(id))
  const visibleSet = new Set(visible)
  return [...visible, ...selectedIds.value.filter(id => !visibleSet.has(id))]
})

let searchTimeout: ReturnType<typeof setTimeout> | null = null

function hasPositiveAmountToPay(inv: InvoiceListItem): boolean {
  if (!['invoice', 'proforma'].includes(inv.invoice_type)) return true
  return Number(inv.amount_to_pay ?? 0) > 0
}

// Zámek dokladu (F6) — jen z BE pole `locked`, FE nic neodvozuje.
function rowLockedForMe(inv: InvoiceListItem): boolean {
  return auth.isClientRole && !!inv.locked?.is_locked
}

function lockTitle(inv: InvoiceListItem): string {
  const reasons = (inv.locked?.reasons ?? []).map(r => t(`lock.reason.${r}`)).join(', ')
  return reasons ? `${t('lock.badge')}: ${reasons}` : (t('lock.badge') as string)
}

/**
 * Šířka mikro-baru za částkou = podíl na nejvyšší faktuře TÉŽE měny v měsíci.
 *
 * Why: seznam je zeď čísel, ve které se řádově velká faktura ztrácí stejně jako
 * drobná. Proužek pod textem (viz .amount-cell v styles/main.css) dá řádu velikosti
 * tvar, aniž by zabral jediný pixel navíc.
 *
 * Měny se nemíchají — 100 EUR a 100 CZK nejsou porovnatelné, takže každá měna má
 * ve skupině vlastní maximum. Minimum 4 % drží proužek viditelný i u drobných částek.
 */
function amountBarWidth(inv: InvoiceListItem, g: MonthGroup): string {
  const value = Math.abs(inv.amount_to_pay ?? inv.total_with_vat ?? 0)
  if (value === 0) return '0%'
  let max = 0
  for (const other of g.invoices) {
    if (other.currency !== inv.currency) continue
    const v = Math.abs(other.amount_to_pay ?? other.total_with_vat ?? 0)
    if (v > max) max = v
  }
  if (max === 0) return '0%'
  return `${Math.max(4, Math.round((value / max) * 100))}%`
}

function toggleSelected(id: number) {
  const i = selectedIds.value.indexOf(id)
  if (i === -1) selectedIds.value.push(id)
  else selectedIds.value.splice(i, 1)
}

function isGroupSelected(group: MonthGroup): boolean {
  return group.invoices.length > 0 && group.invoices.every(invoice => selectedIds.value.includes(invoice.id))
}

function isGroupSelectionPartial(group: MonthGroup): boolean {
  const count = group.invoices.filter(invoice => selectedIds.value.includes(invoice.id)).length
  return count > 0 && count < group.invoices.length
}

function toggleGroupSelected(group: MonthGroup) {
  const groupIds = group.invoices.map(invoice => invoice.id)
  const selected = new Set(selectedIds.value)
  if (groupIds.every(id => selected.has(id))) {
    selectedIds.value = selectedIds.value.filter(id => !groupIds.includes(id))
    return
  }
  for (const id of groupIds) selected.add(id)
  selectedIds.value = Array.from(selected)
}

function openBulkPdfExport() {
  if (selectedPdfIds.value.length === 0) return
  bulkPdfSign.value = false
  bulkPdfOpen.value = true
}

async function responseErrorMessage(error: any, fallback: string): Promise<string> {
  const data = error?.response?.data
  if (data instanceof Blob) {
    try {
      const parsed = JSON.parse(await data.text())
      return parsed?.error?.message || fallback
    } catch {
      return fallback
    }
  }
  return data?.error?.message || fallback
}

async function bulkExportPdf() {
  const ids = selectedPdfIds.value
  if (ids.length === 0 || ids.length > 100) return
  bulkBusy.value = true
  try {
    const response = await invoicesApi.exportSelectedPdf(ids, bulkPdfSign.value)
    const disposition = response.headers['content-disposition'] || ''
    const match = disposition.match(/filename="?([^";]+)"?/)
    const filename = match?.[1] || `myucto-vybrane-faktury-${appIsoDate()}.pdf`
    const url = URL.createObjectURL(response.data)
    const link = document.createElement('a')
    link.href = url
    link.download = filename
    document.body.appendChild(link)
    link.click()
    link.remove()
    URL.revokeObjectURL(url)
    bulkPdfOpen.value = false
    toast.success(t('invoice.bulk_pdf_done', { n: ids.length }))
  } catch (error: any) {
    toast.error(await responseErrorMessage(error, t('invoice.bulk_pdf_failed') as string))
  } finally {
    bulkBusy.value = false
  }
}

async function bulkReissue() {
  if (selectedIds.value.length === 0) return
  if (!confirm(t('invoice.bulk_clone_confirm', { n: selectedIds.value.length }))) return
  bulkBusy.value = true
  try {
    const r = await invoicesApi.bulkReissue(selectedIds.value, { increment_month_in_descriptions: true })
    selectedIds.value = []
    if (r.errors.length) {
      toast.warning(t('invoice.bulk_reissue_partial', { ok: r.created.length, err: r.errors.length }))
    } else {
      toast.success(t('invoice.bulk_send_success', { n: r.created.length }))
    }
    await load()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('invoice.bulk_reissue_failed'))
  } finally {
    bulkBusy.value = false
  }
}

// ─── Hromadné nastavení OSS (OSS-7) ──────────────────────────────────────────
//
// Mění to daňové zařazení řádku (české přiznání vs. OSS podání), takže se nikdy
// nespouští naslepo: nejdřív se povinně načte náhled ze serveru, teprve potvrzený
// náhled se odesílá k provedení. Doklady, které backend odmítl (zaúčtované,
// v podaném období, stornované), zůstávají v seznamu i s důvodem — schovat je by
// budilo dojem, že se změnily.
const ossBulkOpen = ref(false)
const ossBulkPreview = ref<OssBulkResult | null>(null)
const ossBulkForm = reactive({
  scope: 'needs_review' as OssBulkScope,
  mode: 'on' as 'on' | 'off' | 'keep',
  country: '',
  rate_type: '' as '' | 'standard' | 'reduced' | 'second_reduced' | 'parking',
  supply_type: '' as '' | 'goods' | 'services',
  clear_needs_review: true,
})
const euCountries = ref<Country[]>([])

/**
 * Rozpad zastavené dávky. Backend vrací, co stihl (`bulk_update_failed`, 500) — bez
 * vypsání by uživatel u 200 dokladů nevěděl, které jsou zapsané a které se ani
 * nezkusily, a musel by akci naslepo opakovat nad celým výběrem.
 */
const ossBulkFailure = ref<(OssBulkFailure & { message: string }) | null>(null)
/** Doklady, u kterých se po úspěšném zápisu nepovedlo zahodit cache PDF. */
const ossBulkStalePdf = ref<number[]>([])

function parseOssBulkFailure(e: any): (OssBulkFailure & { message: string }) | null {
  const err = e?.response?.data?.error
  if (!err || err.code !== 'bulk_update_failed' || !err.failed_invoice) return null
  return {
    message: typeof err.message === 'string' ? err.message : t('common.error'),
    completed_invoice_ids: Array.isArray(err.completed_invoice_ids) ? err.completed_invoice_ids : [],
    failed_invoice: err.failed_invoice,
    not_attempted_invoice_ids: Array.isArray(err.not_attempted_invoice_ids) ? err.not_attempted_invoice_ids : [],
    pdf_not_invalidated: Array.isArray(err.pdf_not_invalidated) ? err.pdf_not_invalidated : [],
  }
}

/** Doklad → varsymbol z načteného seznamu; když v něm není, aspoň `#id`. */
function invoiceLabel(id: number): string {
  const inv = groups.value.flatMap(g => g.invoices).find(i => i.id === id)
  return inv?.varsymbol ? String(inv.varsymbol) : `#${id}`
}

function ossBulkSet(): OssBulkSet {
  const set: OssBulkSet = { clear_needs_review: ossBulkForm.clear_needs_review }
  if (ossBulkForm.mode === 'on') set.oss_applicable = true
  if (ossBulkForm.mode === 'off') set.oss_applicable = false
  // Vypnutí OSS ostatní pole stejně vynuluje na serveru — posílat je by jen mátlo.
  if (ossBulkForm.mode !== 'off') {
    if (ossBulkForm.country) set.oss_consumer_country = ossBulkForm.country
    if (ossBulkForm.rate_type) set.oss_rate_type = ossBulkForm.rate_type
    if (ossBulkForm.supply_type) set.oss_supply_type = ossBulkForm.supply_type
  }
  return set
}

async function openBulkOss() {
  if (selectedIds.value.length === 0) return
  ossBulkPreview.value = null
  ossBulkFailure.value = null
  ossBulkStalePdf.value = []
  ossBulkOpen.value = true
  if (euCountries.value.length === 0) {
    try {
      euCountries.value = (await codebooksApi.countries()).filter(c => c.is_eu)
    } catch { /* výběr země zůstane prázdný, ISO2 jde napsat ručně */ }
  }
}

function closeBulkOss() {
  ossBulkOpen.value = false
  ossBulkFailure.value = null
  ossBulkStalePdf.value = []
}

async function runBulkOssPreview() {
  bulkBusy.value = true
  try {
    ossBulkPreview.value = await invoicesApi.bulkOssPreview(
      selectedIds.value, ossBulkForm.scope, ossBulkSet())
  } catch (e: any) {
    ossBulkPreview.value = null
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  } finally {
    bulkBusy.value = false
  }
}

async function applyBulkOss() {
  if (!ossBulkPreview.value) return
  bulkBusy.value = true
  ossBulkFailure.value = null
  ossBulkStalePdf.value = []
  try {
    const r = await invoicesApi.bulkOssApply(selectedIds.value, ossBulkForm.scope, ossBulkSet())
    markRowsTouched('invoice', r.documents.filter(d => d.action === 'update').map(d => d.invoice_id))
    const stalePdf = r.pdf_not_invalidated ?? []
    ossBulkStalePdf.value = stalePdf
    // Zůstat v dialogu jen kvůli varování o staré PDF cache — jinak zavřít jako dosud.
    ossBulkOpen.value = stalePdf.length > 0
    ossBulkPreview.value = null
    selectedIds.value = []
    if (r.summary.documents_skipped > 0) {
      toast.warning(t('invoice.bulk_oss_partial', {
        ok: r.summary.documents_to_change, err: r.summary.documents_skipped,
      }))
    } else {
      toast.success(t('invoice.bulk_oss_done', { n: r.summary.documents_to_change }))
    }
    await load()
  } catch (e: any) {
    const failure = parseOssBulkFailure(e)
    if (failure) {
      // Rozpad dávky se do toastu nevejde a zmizel by dřív, než si ho uživatel přečte —
      // zůstane v dialogu, dokud ho sám nezavře. Zapsané doklady jdou zvýraznit v seznamu.
      ossBulkFailure.value = failure
      ossBulkPreview.value = null
      markRowsTouched('invoice', failure.completed_invoice_ids)
      // Nezpracované doklady zůstávají vybrané, ať jde akce zopakovat jen nad nimi.
      selectedIds.value = [failure.failed_invoice.invoice_id, ...failure.not_attempted_invoice_ids]
      toast.error(failure.message)
      await load()
    } else {
      toast.error(e?.response?.data?.error?.message || t('common.error'))
    }
  } finally {
    bulkBusy.value = false
  }
}

// Hromadné odeslání klientům — pouze faktury se status issued/sent/reminded/paid + ne cancellation
const sendableSelected = computed(() => {
  const ids = new Set(selectedIds.value)
  return groups.value
    .flatMap(g => g.invoices)
    .filter(inv =>
      ids.has(inv.id)
      && ['issued', 'sent', 'reminded', 'paid'].includes(inv.status)
      && inv.invoice_type !== 'cancellation'
    )
})

// Hromadné vystavení — jen drafty. Řadíme podle issue_date asc, pak id asc, aby varsymboly šly sekvenčně.
const issuableSelected = computed(() => {
  const ids = new Set(selectedIds.value)
  return groups.value
    .flatMap(g => g.invoices)
    .filter(inv => ids.has(inv.id) && inv.status === 'draft' && !rowLockedForMe(inv))
    .sort((a, b) => (a.issue_date || '').localeCompare(b.issue_date || '') || (a.id - b.id))
})

// Hromadné označení za zaplacené — jen issued/sent/reminded (ne paid, ne cancelled, ne draft, ne cancellation)
const markPayableSelected = computed(() => {
  const ids = new Set(selectedIds.value)
  return groups.value
    .flatMap(g => g.invoices)
    .filter(inv =>
      ids.has(inv.id)
      && ['issued', 'sent', 'reminded'].includes(inv.status)
      && inv.invoice_type !== 'cancellation'
      && hasPositiveAmountToPay(inv)
      && !rowLockedForMe(inv)
    )
})

// Hromadná upomínka — jen běžné faktury (ne proforma/dobropis/storno) ve stavu issued/sent/reminded,
// po splatnosti a placené bankovním převodem (kartové/hotovostní úhrady se neupomínají).
const reminderSelected = computed(() => {
  const ids = new Set(selectedIds.value)
  const today = new Date()
  today.setHours(0, 0, 0, 0)
  return groups.value
    .flatMap(g => g.invoices)
    .filter(inv => {
      if (!ids.has(inv.id)) return false
      if (inv.invoice_type !== 'invoice') return false
      if (!['issued', 'sent', 'reminded'].includes(inv.status)) return false
      if (!hasPositiveAmountToPay(inv)) return false
      if ((inv.payment_method ?? 'bank_transfer') !== 'bank_transfer') return false
      const due = new Date(inv.due_date)
      return due < today
    })
})

async function bulkSendReminders() {
  const list = reminderSelected.value
  if (list.length === 0) {
    toast.warning(t('invoice.bulk_reminder_no_eligible'))
    return
  }
  if (!confirm(t('invoice.bulk_reminder_confirm', { n: list.length }))) return
  bulkBusy.value = true
  try {
    const r = await invoicesApi.bulkSendReminders(list.map(i => i.id))
    selectedIds.value = []
    if (r.errors.length) {
      const detail = r.errors.map(e => `#${e.invoice_id}: ${e.error}`).join('\n')
      toast.warning(t('invoice.bulk_reminder_partial', { ok: r.sent.length, err: r.errors.length }) + '\n' + detail)
    } else {
      toast.success(t('invoice.bulk_reminder_success', { n: r.sent.length }))
    }
    await load()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('invoice.bulk_reminder_failed'))
  } finally {
    bulkBusy.value = false
  }
}

async function bulkMarkPaid() {
  const list = markPayableSelected.value
  if (list.length === 0) {
    toast.warning(t('invoice.bulk_mark_paid_no_eligible'))
    return
  }
  if (!confirm(t('invoice.bulk_mark_paid_confirm', { n: list.length }))) return
  // Volitelně i poděkování za úhradu (issue #57) — jen pokud má dodavatel funkci zapnutou.
  const sendThanks = thanksEnabled.value && confirm(t('invoice.bulk_send_thanks_confirm', { n: list.length }))
  const today = appIsoDate()
  bulkBusy.value = true
  let okCount = 0
  let thanksSent = 0
  let thanksFailed = 0
  const errors: string[] = []
  try {
    for (const inv of list) {
      try {
        const updated = await invoicesApi.markPaid(inv.id, today, sendThanks ? { sendThanks: true, thanksTrigger: 'bulk' } : undefined)
        okCount++
        const pt = updated.payment_thanks
        if (pt?.status === 'sent') thanksSent++
        else if (pt?.status === 'failed') thanksFailed++
      } catch (e: any) {
        errors.push(`${inv.varsymbol || `#${inv.id}`}: ${e?.response?.data?.error?.message || 'chyba'}`)
      }
    }
    markRowsTouched('invoice', list.map(i => i.id))
    selectedIds.value = []
    let msg = errors.length
      ? t('invoice.bulk_mark_paid_partial', { ok: okCount, err: errors.length })
      : t('invoice.bulk_mark_paid_success', { n: okCount })
    if (sendThanks) {
      msg += '\n' + t('invoice.bulk_thanks_summary', { sent: thanksSent, failed: thanksFailed })
    }
    if (errors.length) {
      toast.warning(msg + '\n' + errors.join('\n'))
    } else {
      toast.success(msg)
    }
    await load()
  } finally {
    bulkBusy.value = false
  }
}

async function bulkIssue() {
  const list = issuableSelected.value
  if (list.length === 0) {
    toast.warning(t('invoice.bulk_issue_no_eligible'))
    return
  }
  if (!confirm(t('invoice.bulk_issue_confirm', { n: list.length }))) return
  bulkBusy.value = true
  let okCount = 0
  const errors: string[] = []
  try {
    for (const inv of list) {
      try {
        await invoicesApi.issue(inv.id)
        okCount++
      } catch (e: any) {
        errors.push(`#${inv.id}: ${e?.response?.data?.error?.message || 'chyba'}`)
      }
    }
    markRowsTouched('invoice', list.map(i => i.id))
    selectedIds.value = []
    if (errors.length) {
      toast.warning(t('invoice.bulk_issue_partial', { ok: okCount, err: errors.length }) + '\n' + errors.join('\n'))
    } else {
      toast.success(t('invoice.bulk_issue_success', { n: okCount }))
    }
    await load()
  } finally {
    bulkBusy.value = false
  }
}

// Hromadné zaúčtování (A2) — jen podvojné účetnictví, jen nezaúčtované (booked_at NULL)
// vystavené doklady (drafty/storna nemají co účtovat). Report ok/fail řeší bulkPost.
const postableSelected = computed(() => {
  if (!isDoubleEntry.value) return []
  const ids = new Set(selectedIds.value)
  return groups.value
    .flatMap(g => g.invoices)
    .filter(inv =>
      ids.has(inv.id)
      && !inv.booked_at
      && ['issued', 'sent', 'reminded', 'paid'].includes(inv.status)
      && inv.invoice_type !== 'cancellation'
    )
})

async function bulkPost() {
  const list = postableSelected.value
  if (list.length === 0) {
    toast.warning(t('invoice.bulk_post_no_eligible'))
    return
  }
  if (!confirm(t('invoice.bulk_post_confirm', { n: list.length }))) return
  bulkBusy.value = true
  try {
    const r = await accountingApi.postInvoicesBulk(list.map(i => i.id))
    // `posted` vrací rovnou ID, ne objekty — probliknou jen doklady, které se
    // opravdu zaúčtovaly, ne celý výběr.
    markRowsTouched('invoice', r.posted.length ? r.posted : list.map(i => i.id))
    selectedIds.value = []
    if (r.failed.length) {
      const detail = r.failed.map(f => `#${f.id}: ${t(postingErrorI18nKey(f.error_code))}`).join('\n')
      // Propadlo-li to na chybějící účetní období, přidej proklik na Uzávěrku —
      // jinak uživatel ví, co se stalo, ale ne kam jít (nejčastěji import historie).
      const missingPeriod = r.failed.some(f =>
        (ACCOUNTING_PERIOD_MISSING_CODES as readonly string[]).includes(f.error_code))
      toast.warning(
        t('invoice.bulk_post_partial', { ok: r.posted.length, err: r.failed.length }) + '\n' + detail,
        missingPeriod
          ? {
              label: t('accounting.posting_errors.open_periods_action'),
              handler: () => void router.push(accountingPeriodRoute()),
            }
          : undefined,
      )
    } else {
      toast.success(t('invoice.bulk_post_success', { n: r.posted.length }))
    }
    await load()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('invoice.bulk_post_failed'))
  } finally {
    bulkBusy.value = false
  }
}

async function bulkSend() {
  const list = sendableSelected.value
  if (list.length === 0) {
    toast.warning(t('invoice.bulk_send_no_eligible'))
    return
  }
  if (!confirm(t('invoice.bulk_send_confirm', { n: list.length }))) return
  bulkBusy.value = true
  let okCount = 0
  const errors: string[] = []
  try {
    for (const inv of list) {
      try {
        await invoicesApi.send(inv.id)
        okCount++
      } catch (e: any) {
        errors.push(`${inv.varsymbol || `#${inv.id}`}: ${e?.response?.data?.error?.message || 'chyba'}`)
      }
    }
    selectedIds.value = []
    if (errors.length) {
      toast.warning(t('invoice.bulk_send_partial', { ok: okCount, err: errors.length }) + '\n' + errors.join('\n'))
    } else {
      toast.success(t('invoice.bulk_send_success', { n: okCount }))
    }
    await load()
  } finally {
    bulkBusy.value = false
  }
}

function mergeGroups(existing: MonthGroup[], incoming: MonthGroup[]): MonthGroup[] {
  const byMonth = new Map<string, MonthGroup>()
  for (const g of existing) byMonth.set(g.month, g)
  for (const g of incoming) {
    const cur = byMonth.get(g.month)
    if (!cur) {
      byMonth.set(g.month, g)
      continue
    }
    cur.invoices.push(...g.invoices)
    cur.count += g.count
    // Merge totals_per_currency
    for (const t of g.totals_per_currency) {
      const found = cur.totals_per_currency.find(x => x.currency === t.currency)
      if (found) {
        found.without_vat = Math.round((found.without_vat + t.without_vat) * 100) / 100
        found.vat         = Math.round((found.vat         + t.vat)         * 100) / 100
        found.with_vat    = Math.round((found.with_vat    + t.with_vat)    * 100) / 100
        found.draft_without_vat = Math.round((found.draft_without_vat + t.draft_without_vat) * 100) / 100
        found.draft_vat         = Math.round((found.draft_vat         + t.draft_vat)         * 100) / 100
        found.draft_with_vat    = Math.round((found.draft_with_vat    + t.draft_with_vat)    * 100) / 100
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
  } else {
    loadingMore.value = true
    page.value++
  }
  try {
    const result = await invoicesApi.listGrouped({
      q: search.value || undefined,
      status: statusFilter.value || undefined,
      type: typeFilter.value || undefined,
      client_id: clientFilter.value === '' ? undefined : Number(clientFilter.value),
      year: dateFrom.value || dateTo.value ? undefined : (yearFilter.value === '' ? undefined : Number(yearFilter.value)),
      month: dateFrom.value || dateTo.value || yearFilter.value === '' || monthFilter.value === '' ? undefined : Number(monthFilter.value),
      date_from: dateFrom.value || undefined,
      date_to:   dateTo.value || undefined,
      currency:  currencyFilter.value || undefined,
      overdue: overdueOnly.value || undefined,
      unpaid_only: unpaidOnly.value || undefined,
      unpaid_as_of: unpaidAsOf.value || undefined,
      booked: bookedFilter.value || undefined,
      oss_review: ossReviewFilter.value || undefined,
      revenue_category_id: revenueCategoryMode.value === 'include' && revenueCategoryIds.value.length
        ? revenueCategoryIds.value : undefined,
      revenue_category_exclude: revenueCategoryMode.value === 'exclude' && revenueCategoryIds.value.length
        ? revenueCategoryIds.value : undefined,
      page: page.value,
    })
    if (reset) {
      groups.value = result.data
    } else {
      groups.value = mergeGroups(groups.value, result.data)
    }
    total.value = result.meta.total
    pages.value = result.meta.pages ?? 1
  } finally {
    loading.value = false
    loadingMore.value = false
    flashedIds.value = consumeFlashedRows('invoice')
    // Po první dávce dat je stagger odbytý — další načtení (filtr, stránkování,
    // „načíst další") už musí být okamžité.
    if (staggerRows.value) {
      window.setTimeout(() => { staggerRows.value = false }, 600)
    }
  }
}

// Sync filtrů s URL query (stejný pattern jako PurchaseInvoiceList) — detekuje menu
// link click přes route.query change z !empty na empty → reset.
const DEFAULT_YEAR = new Date().getFullYear()

const COLUMNS: ColumnDef[] = [
  { key: 'number', labelKey: 'invoice.varsymbol', required: true },
  { key: 'client', labelKey: 'invoice.client_project' },
  { key: 'type', labelKey: 'invoice.type' },
  { key: 'issued', labelKey: 'invoice.tax_date' },
  { key: 'due', labelKey: 'invoice.due_date' },
  { key: 'amount', labelKey: 'invoice.amount_to_pay', required: true },
  { key: 'status', labelKey: 'invoice.status_label' },
  // Doplňkové sloupce — defaultně skryté, uživatel si je zapne přes ColumnPicker.
  { key: 'paid_at', labelKey: 'invoice.col_paid_at', defaultHidden: true },
  { key: 'payment_method', labelKey: 'payment_method.label', defaultHidden: true },
  { key: 'booked_at', labelKey: 'invoice.col_booked_at', defaultHidden: true },
  { key: 'exchange_rate', labelKey: 'invoice.col_exchange_rate', defaultHidden: true },
  { key: 'amount_czk', labelKey: 'invoice.col_amount_czk', defaultHidden: true },
  { key: 'locked', labelKey: 'lock.column' },
]
const tbl = useTablePrefs('invoices', COLUMNS)

// Kurz do tabulky — 3 desetinná místa (ČNB konvence), lokalizovaný zápis.
function formatRate(rate: number): string {
  return new Intl.NumberFormat('cs-CZ', { minimumFractionDigits: 2, maximumFractionDigits: 3 }).format(rate)
}
const saved = useSavedFilters('invoices', { getQuery: buildQuery, applyQuery: applyQueryToPage })

/**
 * Pohledy = uložené filtry vytažené z dropdownu do záložek nad seznamem.
 *
 * Why bez počtů: /invoices nemá agregační endpoint, který by vrátil počty pro víc
 * filtrů najednou — číslo u každého pohledu by znamenalo jeden dotaz navíc na
 * pohled při každém načtení seznamu. Radši žádné číslo než N dotazů (a odhad
 * z načtené stránky by lhal, protože seznam je stránkovaný).
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
  // Načti seznam klientů + měn pro select (paralelně s prvním load)
  clientsApi.list({ archived: false, per_page: 200, role: 'customers' }).then(r => { clients.value = r.data }).catch(() => {})
  codebooksApi.currencies().then(r => {
    const seen = new Set<string>()
    currencies.value = r.filter(c => c.is_active && !seen.has(c.code) && seen.add(c.code))
  }).catch(() => {})
  // Včetně archivovaných — visí na starých fakturách (viz revenueCategoryOptions).
  revenueCategoriesApi.list(true).then(r => { revenueCategories.value = r }).catch(() => {})
  if (Object.keys(route.query).length === 0 && await saved.applyDefaultIfAny()) return
  loadFiltersFromQuery(route.query)
  await load(true)
})

function loadFiltersFromQuery(q: typeof route.query) {
  statusFilter.value = typeof q.status === 'string' ? q.status : ''
  typeFilter.value   = typeof q.type === 'string' ? q.type : ''
  clientFilter.value = typeof q.client_id === 'string' && q.client_id !== '' ? Number(q.client_id) : ''
  overdueOnly.value  = q.overdue === '1' || q.overdue === 'true'
  unpaidOnly.value   = q.unpaid === '1' || q.unpaid === 'true'
  unpaidAsOf.value   = typeof q.unpaid_as_of === 'string' ? q.unpaid_as_of : ''
  bookedFilter.value = q.booked === '1' ? '1' : (q.booked === '0' ? '0' : '')
  // `1` / `true` = uložené filtry a starší odkazy, kdy filtr rozsah neuměl. Mapují se
  // na „vše nejisté", tedy na ROZŠÍŘENÍ původního významu — nic se nesmí schovat.
  ossReviewFilter.value = q.oss_review === 'oss' || q.oss_review === 'domestic' || q.oss_review === 'any'
    ? q.oss_review
    : (q.oss_review === '1' || q.oss_review === 'true' ? 'any' : '')
  // Nejisté řádky jsou typicky staré (přišly importem nebo cronem kdykoli v minulosti),
  // takže výchozí rok by je schoval a filtr by vypadal, že žádné nejsou — stejný důvod
  // jako u „nezaúčtováno".
  yearFilter.value   = typeof q.year === 'string' && q.year !== ''
    ? (q.year === 'all' ? '' : Number(q.year))
    : ((overdueOnly.value || unpaidOnly.value || unpaidAsOf.value || bookedFilter.value === '0' || ossReviewFilter.value) ? '' : DEFAULT_YEAR)
  monthFilter.value  = typeof q.month === 'string' && q.month !== '' ? Number(q.month) : ''
  dateFrom.value     = typeof q.from === 'string' ? q.from : ''
  dateTo.value       = typeof q.to === 'string' ? q.to : ''
  currencyFilter.value = typeof q.currency === 'string' ? q.currency : ''
  // Režim se pozná podle toho, KTERÝ klíč v query je — uložený pohled je jen JSON
  // s query stringem, takže se tím obnoví i on. Přijdou-li (ručně sestavenou URL) oba,
  // vyhrává exclude: UI umí ukázat jen jeden režim a schovat něco navíc je menší zlo
  // než tvrdit, že se nefiltruje.
  const rcExclude = typeof q.revenue_category_exclude === 'string' ? q.revenue_category_exclude : ''
  const rcInclude = typeof q.revenue_category === 'string' ? q.revenue_category : ''
  revenueCategoryMode.value = rcExclude !== '' ? 'exclude' : 'include'
  const rcRaw = rcExclude !== '' ? rcExclude : rcInclude
  revenueCategoryIds.value = rcRaw ? rcRaw.split(',').map(v => v.trim()).filter(v => v !== '') : []
  search.value       = typeof q.q === 'string' ? q.q : ''
}

function buildQuery(): Record<string, string> {
  const q: Record<string, string> = {}
  if (statusFilter.value) q.status = statusFilter.value
  if (typeFilter.value) q.type = typeFilter.value
  if (clientFilter.value !== '') q.client_id = String(clientFilter.value)
  if (yearFilter.value === '') q.year = 'all'
  else if (yearFilter.value !== DEFAULT_YEAR) q.year = String(yearFilter.value)
  if (monthFilter.value !== '') q.month = String(monthFilter.value)
  if (dateFrom.value) q.from = dateFrom.value
  if (dateTo.value) q.to = dateTo.value
  if (currencyFilter.value) q.currency = currencyFilter.value
  if (overdueOnly.value) q.overdue = '1'
  if (unpaidOnly.value) q.unpaid = '1'
  if (unpaidAsOf.value) q.unpaid_as_of = unpaidAsOf.value
  if (bookedFilter.value) q.booked = bookedFilter.value
  if (ossReviewFilter.value) q.oss_review = ossReviewFilter.value
  if (revenueCategoryIds.value.length) {
    const key = revenueCategoryMode.value === 'exclude' ? 'revenue_category_exclude' : 'revenue_category'
    q[key] = revenueCategoryIds.value.join(',')
  }
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
  load(true)
}

watch([statusFilter, typeFilter, clientFilter, yearFilter, monthFilter, dateFrom, dateTo,
       overdueOnly, unpaidOnly, unpaidAsOf, bookedFilter, ossReviewFilter, currencyFilter,
       revenueCategoryIds, revenueCategoryMode], () => {
  syncFiltersToUrl()
  load(true)
})
// Když se vyčistí rok (vše/range), automaticky zrušit i měsíční filtr.
watch(yearFilter, (y) => { if (y === '') monthFilter.value = '' })
watch([dateFrom, dateTo], ([f, to]) => { if (f || to) monthFilter.value = '' })
watch(search, () => {
  if (searchTimeout) clearTimeout(searchTimeout)
  searchTimeout = setTimeout(() => { syncFiltersToUrl(); load(true) }, 300)
})

// Reset filtrů při menu link click (route.query je prázdná).
watch(() => route.query, (newQ) => {
  if (Object.keys(newQ).length === 0) {
    suppressUrlSync = true
    statusFilter.value = ''
    typeFilter.value = ''
    clientFilter.value = ''
    yearFilter.value = DEFAULT_YEAR
    monthFilter.value = ''
    dateFrom.value = ''
    dateTo.value = ''
    overdueOnly.value = false
    unpaidOnly.value = false
    unpaidAsOf.value = ''
    bookedFilter.value = ''
    ossReviewFilter.value = ''
    currencyFilter.value = ''
    revenueCategoryIds.value = []
    revenueCategoryMode.value = 'include'
    search.value = ''
    setTimeout(() => { suppressUrlSync = false }, 0)
  }
})

const loadedCount = computed(() => groups.value.reduce((s, g) => s + g.count, 0))

const navigateRow = useRowLink()
function openInvoice(inv: InvoiceListItem, e?: MouseEvent) {
  navigateRow(`/invoices/${inv.id}`, e)
}

// Work Report modal: otevíráno z buttonu "Výkaz" v sloupci Stav.
const wrModalOpen = ref(false)
const wrModalInvoiceId = ref(0)
function openWorkReport(id: number) {
  wrModalInvoiceId.value = id
  wrModalOpen.value = true
}

// Year dropdown — distinct roky z `invoices` aktuálního supplier (issue #33).
// Composable doplňuje aktuální + minulý rok + aktuálně zvolený rok z URL.
const yearOptions = useYearOptions('invoices', yearFilter)

// `tm()` vrací raw translation message (pole), kdežto `t()` na poli vrátí stringified verzi.
// `rt()` zformátuje jednotlivé položky pole (pro případnou interpolaci).
const monthOptions = computed(() => (tm('common.months_short') as unknown as string[]).map(m => rt(m)))
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-4 gap-3 flex-wrap">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('invoice.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5">{{ t('invoice.subtitle_grouping') }}</p>
      </div>
      <div class="flex items-center gap-2 flex-wrap justify-end">
        <RouterLink
          v-if="auth.canWrite('invoices.create') || auth.isDemo"
          to="/invoices/new"
          :class="btnFilled('primary')"
        >
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
          {{ t('invoice.new') }}
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

    <!-- Hromadné akce jedou v plovoucí liště u spodní hrany: uživatel zaškrtává
         řádky dole v tabulce, kdežto v hlavičce byly akce mimo zorné pole a
         navíc při každém výběru odsouvaly tlačítko „Nová faktura". -->
    <BulkActionBar :count="selectedIds.length" @clear="selectedIds = []">
        <button v-if="selectedIds.length > 0 && auth.canRead('utilities.export')"
          @click="openBulkPdfExport"
          :disabled="bulkBusy"
          :class="btnOutline('primary')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 16V4m0 12l-4-4m4 4l4-4M4 20h16" /></svg>
          {{ t('invoice.bulk_pdf', { n: selectedIds.length }) }}
        </button>
        <button v-if="(issuableSelected.length > 0) && auth.canWrite('invoices.issue')"
          @click="bulkIssue"
          :disabled="bulkBusy"
          :class="btnFilled('success')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
          {{ bulkBusy ? '…' : t('invoice.bulk_issue', { n: issuableSelected.length }) }}
        </button>
        <button v-if="(selectedIds.length > 0) && auth.canWrite('invoices.issue') && !auth.isClientRole"
          @click="bulkReissue"
          :disabled="bulkBusy"
          :class="btnOutline('primary')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.copy" /></svg>
          {{ bulkBusy ? '…' : t('invoice.bulk_reissue', { n: selectedIds.length }) }}
        </button>
        <button v-if="(markPayableSelected.length > 0) && auth.canWrite('invoices.mark_paid')"
          @click="bulkMarkPaid"
          :disabled="bulkBusy"
          :class="btnOutline('success')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.checkCircle" /></svg>
          {{ bulkBusy ? '…' : t('invoice.bulk_mark_paid', { n: markPayableSelected.length }) }}
        </button>
        <button v-if="(sendableSelected.length > 0) && auth.canWrite('invoices.send')"
          @click="bulkSend"
          :disabled="bulkBusy"
          :class="btnOutline('primary')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.send" /></svg>
          {{ bulkBusy ? '…' : t('invoice.bulk_send', { n: sendableSelected.length }) }}
        </button>
        <button v-if="(reminderSelected.length > 0) && auth.canWrite('invoices.reminder') && !auth.isClientRole"
          @click="bulkSendReminders"
          :disabled="bulkBusy"
          :class="btnOutline('warning')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.bell" /></svg>
          {{ bulkBusy ? '…' : t('invoice.bulk_reminder', { n: reminderSelected.length }) }}
        </button>
        <button v-if="(selectedIds.length > 0) && auth.canWrite('invoices.create') && !auth.isClientRole"
          @click="openBulkOss"
          :disabled="bulkBusy"
          :class="btnOutline('warning')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3.6 9h16.8M3.6 15h16.8M12 3a15 15 0 010 18M12 3a15 15 0 000 18M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
          {{ t('invoice.bulk_oss', { n: selectedIds.length }) }}
        </button>
        <button v-if="(postableSelected.length > 0) && auth.canWrite('accounting.journal.post') && !auth.isClientRole"
          @click="bulkPost"
          :disabled="bulkBusy"
          :class="btnOutline('primary')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.clipboardCheck" /></svg>
          {{ bulkBusy ? '…' : t('invoice.bulk_post', { n: postableSelected.length }) }}
        </button>
    </BulkActionBar>

    <!-- Filtry -->
    <FilterBar
      :active-count="activeFilterCount"
      collapsible
      :chips="filterChips"
      @clear="clearFilter"
      @clear-all="clearAllFilters"
    >
      <template #primary>
        <!-- Hledání je nejpoužívanější prvek lišty, takže dostává nejvíc místa
             a ikonu lupy uvnitř — dřív bylo nejmenší z deseti ovládacích prvků. -->
        <div class="relative flex-1 min-w-56">
          <svg class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-neutral-400"
            fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M17 11a6 6 0 1 1-12 0 6 6 0 0 1 12 0z" />
          </svg>
          <input
            v-model="search"
            type="search"
            :placeholder="t('invoice.search_placeholder')"
            class="w-full h-9 pl-9 pr-3 border border-neutral-300 rounded-md text-sm focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none"
          />
        </div>
      </template>
        <select v-model="statusFilter" class="h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
          <option value="">{{ t('invoice.all_statuses') }}</option>
          <option value="draft">{{ t('status.draft') }}</option>
          <option value="issued">{{ t('status.issued') }}</option>
          <option value="sent">{{ t('status.sent') }}</option>
          <option value="reminded">{{ t('status.reminded') }}</option>
          <option value="paid">{{ t('status.paid') }}</option>
          <option value="cancelled">{{ t('status.cancelled') }}</option>
        </select>
        <select v-model="typeFilter" class="h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
          <option value="">{{ t('invoice.all_types') }}</option>
          <option value="invoice">{{ t('type.invoice') }}</option>
          <option value="proforma">{{ t('type.proforma') }}</option>
          <option value="credit_note">{{ t('type.credit_note') }}</option>
        </select>
        <div class="min-w-48 flex-1 max-w-xs">
          <SearchableSelect
            :model-value="clientFilter === '' ? null : clientFilter"
            @update:model-value="(v) => clientFilter = v === null ? '' : v"
            :options="clients.map(c => ({ value: c.id, label: c.company_name, secondary: c.ic ?? undefined }))"
            :placeholder="t('project.all_clients')"
          />
        </div>
        <select v-model="currencyFilter" class="h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
          <option value="">{{ t('invoice.all_currencies') }}</option>
          <option v-for="c in currencies" :key="c.id" :value="c.code">{{ c.code }}</option>
        </select>
        <!-- Kategorie tržby: výběr 1:N + přepínač, jestli se vybrané mají ukázat, nebo
             naopak schovat. Přepínač se objeví až s výběrem — bez něj nemá co přepínat
             a lišta by měla o jeden trvale zbytečný select víc. -->
        <MultiSelectFilter
          v-model="revenueCategoryIds"
          :options="revenueCategoryOptions"
          :label="t('invoice.revenue_category_filter_all')"
          :active-label="revenueCategoryMode === 'exclude'
            ? t('invoice.revenue_category_filter_excluded')
            : t('invoice.revenue_category_filter')"
          :title="t('invoice.revenue_category_filter_hint')"
          :tone="revenueCategoryMode === 'exclude' ? 'warning' : 'primary'"
        />
        <select v-if="revenueCategoryIds.length" v-model="revenueCategoryMode"
          class="h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm"
          :title="t('invoice.revenue_category_filter_hint')">
          <option value="include">{{ t('invoice.revenue_category_filter_mode_include') }}</option>
          <option value="exclude">{{ t('invoice.revenue_category_filter_mode_exclude') }}</option>
        </select>
        <select v-model="yearFilter" :disabled="!!dateFrom || !!dateTo"
          class="h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm disabled:opacity-50">
          <option value="">{{ t('invoice.all_years') }}</option>
          <option v-for="y in yearOptions" :key="y" :value="y">{{ y }}</option>
        </select>
        <select v-model="monthFilter" :disabled="!!dateFrom || !!dateTo || yearFilter === ''"
          class="h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm disabled:opacity-50"
          :title="t('invoice.month_filter')">
          <option :value="''">{{ t('invoice.all_months') }}</option>
          <option v-for="(label, i) in monthOptions" :key="i + 1" :value="i + 1">{{ label }}</option>
        </select>
        <input v-model="dateFrom" type="date" placeholder="Od"
          class="h-9 px-2 border border-neutral-300 rounded-md text-sm" title="Datum od" />
        <input v-model="dateTo" type="date" placeholder="Do"
          class="h-9 px-2 border border-neutral-300 rounded-md text-sm" title="Datum do" />
        <button v-if="dateFrom || dateTo" @click="dateFrom = ''; dateTo = ''"
          class="cursor-pointer h-9 px-2 text-xs text-neutral-500 hover:text-neutral-700">{{ t('invoice.clear_date_filter') }}</button>
        <label class="flex items-center gap-1.5 text-sm text-neutral-700 px-2">
          <input v-model="overdueOnly" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
          {{ t('invoice.overdue_only') }}
        </label>
        <label class="flex items-center gap-1.5 text-sm text-neutral-700 px-2">
          <input v-model="unpaidOnly" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
          {{ t('invoice.unpaid_only') }}
        </label>
        <!-- Neuhrazené K DATU X (task #4) — stav úhrady k historickému dni, ne dnešní
             status. Odlišné od "unpaidOnly" výše, proto vlastní datumové pole s popiskem,
             ne další zaškrtávátko vedle stejnojmenného filtru. -->
        <label class="flex items-center gap-1.5 text-sm text-neutral-700 px-2" :title="t('invoice.unpaid_as_of_hint')">
          {{ t('invoice.unpaid_as_of_label') }}
          <input v-model="unpaidAsOf" type="date"
            class="h-9 px-2 border border-neutral-300 rounded-md text-sm" />
        </label>
        <button v-if="unpaidAsOf" @click="unpaidAsOf = ''"
          :title="t('invoice.unpaid_as_of_clear')"
          class="cursor-pointer h-9 px-2 text-xs text-neutral-500 hover:text-neutral-700">✕</button>
        <select v-if="isDoubleEntry" v-model="bookedFilter"
          class="h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm"
          :title="t('common.booked_filter_all')">
          <option value="">{{ t('common.booked_filter_all') }}</option>
          <option value="1">{{ t('common.booked_badge') }}</option>
          <option value="0">{{ t('common.unbooked_badge') }}</option>
        </select>
        <select v-model="ossReviewFilter"
          class="h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm"
          :title="t('invoice.oss_review_only_hint')">
          <option value="">{{ t('invoice.oss_review_scope.off') }}</option>
          <option value="any">{{ t('invoice.oss_review_scope.any') }}</option>
          <option value="oss">{{ t('invoice.oss_review_scope.oss') }}</option>
          <option value="domestic">{{ t('invoice.oss_review_scope.domestic') }}</option>
        </select>
      <template #actions>
        <SavedFiltersMenu :ctrl="saved" />
        <ColumnPicker class="hidden md:block" :ctrl="tbl" />
        <DensityToggle class="hidden md:block" :ctrl="tbl" />
      </template>
    </FilterBar>

    <div v-if="loading" class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
      <TableSkeleton :rows="8" :cols="7" />
    </div>

    <div v-else-if="!groups.length" class="bg-surface border border-neutral-200 rounded-lg shadow-sm">
      <EmptyState v-if="hasActiveFilters" variant="filtered"
        :cta="t('common.empty_state.clear_filters')" @action="clearFiltersAndSearch" />
      <EmptyState v-else icon="doc"
        :title="auth.isClientRole ? t('invoice.empty_client') : t('invoice.empty_title')"
        :message="auth.isClientRole ? undefined : t('invoice.empty_hint')"
        :cta="auth.canWrite('invoices.create') || auth.isDemo ? t('invoice.issue_first') : undefined"
        to="/invoices/new" />
    </div>

    <div v-else>
      <div class="text-xs text-neutral-500 mb-3 flex items-center justify-between">
        <span>{{ t('invoice.summary_count', { n: total, m: groups.length }) }}</span>
        <span v-if="total > loadedCount">{{ t('common.loaded_count', { loaded: loadedCount, total }) }}</span>
      </div>

      <!-- Skupiny po měsících -->
      <section v-for="g in groups" :key="g.month" class="mb-5">
        <!-- Měsíční rozdělovník ve stylu účetní knihy: název měsíce vlevo, součet
             v mono vpravo, mezi tím vzduch. Sticky, protože při dvaceti řádcích
             na obrazovce je „ve kterém měsíci jsem" ta nejčastější otázka. -->
        <header class="sticky top-16 z-[5] flex flex-wrap items-center justify-between gap-x-4 gap-y-1 bg-neutral-50/92 backdrop-blur-md border border-neutral-200 rounded-t-lg px-4 py-2.5 mb-0">
          <div class="flex items-baseline gap-2.5 shrink-0">
            <h2 class="text-[13px] font-semibold uppercase tracking-[0.16em] text-neutral-800">{{ formatMonth(g.month) }}</h2>
            <span class="text-[11px] text-neutral-500 tabular-nums">{{ g.count }} {{ g.count === 1 ? t('invoice.doc_1') : (g.count < 5 ? t('invoice.doc_2_4') : t('invoice.doc_5plus')) }}</span>
          </div>
          <span class="hidden sm:block flex-1 h-px bg-gradient-to-r from-neutral-200 to-transparent" aria-hidden="true"></span>
          <!-- Na mobilu se součty musí umět zalomit — `shrink-0` by je vytlačilo
               za pravou hranu a vyrobilo vodorovný scroll celé stránky. -->
          <div class="flex flex-wrap items-center justify-end gap-x-3 gap-y-0.5 min-w-0 text-xs">
            <span v-for="tot in g.totals_per_currency" :key="tot.currency" class="font-mono">
              <span class="text-neutral-500">{{ tot.currency }}:</span>
              <span class="font-semibold text-neutral-900 ml-1">{{ formatMoney(tot.with_vat, tot.currency) }}</span>
              <span v-if="tot.draft_with_vat !== 0" class="ml-1 text-primary-600"
                :title="t('invoice.prediction_hint', { amount: formatMoney(tot.draft_with_vat, tot.currency) })">
                → {{ formatMoney(tot.with_vat + tot.draft_with_vat, tot.currency) }}
                <span class="text-[10px] uppercase tracking-wide text-primary-500">{{ t('invoice.prediction') }}</span>
              </span>
            </span>
          </div>
        </header>

        <!-- Desktop: tabulka -->
        <div class="hidden md:block bg-surface border border-t-0 border-neutral-200 rounded-b-lg overflow-hidden">
          <div class="overflow-x-auto">
          <table class="w-full text-sm table-sticky-first" :class="tbl.densityClass.value">
            <thead class="bg-neutral-50/70 text-neutral-500 text-[11px] uppercase tracking-[0.11em] border-b border-neutral-200">
              <tr>
                <th class="px-2 py-2 w-10 text-center">
                  <input
                    type="checkbox"
                    :checked="isGroupSelected(g)"
                    :indeterminate="isGroupSelectionPartial(g)"
                    @change="toggleGroupSelected(g)"
                    :aria-label="t('invoice.select_month', { month: formatMonth(g.month) })"
                    :title="t('invoice.select_month', { month: formatMonth(g.month) })"
                    class="w-5 h-5 cursor-pointer rounded border-neutral-300 text-primary-600 focus:ring-2 focus:ring-primary-500/30"
                  />
                </th>
                <th v-if="tbl.isVisible('number')" class="text-left px-4 py-2 font-medium w-32">Var. symbol</th>
                <th v-if="tbl.isVisible('client')" class="text-left px-4 py-2 font-medium">{{ t('invoice.client_project') }}</th>
                <th v-if="tbl.isVisible('type')" class="text-center px-4 py-2 font-medium">Typ</th>
                <th v-if="tbl.isVisible('issued')" class="text-center px-4 py-2 font-medium">DUZP / Vystaveno</th>
                <th v-if="tbl.isVisible('due')" class="text-center px-4 py-2 font-medium">Splatnost</th>
                <th v-if="tbl.isVisible('amount')" class="text-right px-4 py-2 font-medium">{{ t('invoice.amount_to_pay') }}</th>
                <th v-if="tbl.isVisible('status')" class="text-center px-4 py-2 font-medium">Stav</th>
                <th v-if="tbl.isVisible('paid_at')" class="text-center px-4 py-2 font-medium">{{ t('invoice.col_paid_at') }}</th>
                <th v-if="tbl.isVisible('payment_method')" class="text-center px-4 py-2 font-medium">{{ t('payment_method.label') }}</th>
                <th v-if="tbl.isVisible('booked_at')" class="text-center px-4 py-2 font-medium">{{ t('invoice.col_booked_at') }}</th>
                <th v-if="tbl.isVisible('exchange_rate')" class="text-right px-4 py-2 font-medium">{{ t('invoice.col_exchange_rate') }}</th>
                <th v-if="tbl.isVisible('amount_czk')" class="text-right px-4 py-2 font-medium">{{ t('invoice.col_amount_czk') }}</th>
                <th v-if="tbl.isVisible('locked')" class="text-center px-2 py-2 font-medium w-8">
                  <span class="sr-only">{{ t('lock.column') }}</span>
                </th>
                <th class="px-1 py-2 w-8">
                  <span class="sr-only">{{ t('common.expand_items') }}</span>
                </th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100" :class="staggerRows ? 'stagger-in' : ''">
              <!-- <template v-for>, protože k jednomu dokladu patří dva řádky:
                   samotný doklad a jeho rozbalený náhled položek. -->
              <template v-for="(inv, ri) in g.invoices" :key="inv.id">
              <tr
                draggable="true"
                :data-workspace-route="`/invoices/${inv.id}`"
                @click="openInvoice(inv, $event)"
                @auxclick.prevent="openInvoice(inv, $event)"
                class="cursor-pointer hover:bg-neutral-50 transition"
                :class="[invoiceRowClass(inv.due_date, inv.status), flashedIds.has(inv.id) ? 'row-flash' : '']"
                :data-row-active="rowIndexById.get(inv.id) === activeIndex"
                :style="staggerRows ? { '--i': ri } : undefined"
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
                  <RouterLink class="row-link" :to="`/invoices/${inv.id}`" @click.stop @auxclick.stop>
                    <span v-if="inv.varsymbol">{{ inv.varsymbol }}</span>
                    <span v-else class="text-neutral-400">{{ t('invoice.draft_id_short', { id: inv.id }) }}</span>
                  </RouterLink>
                  <!-- Štítek říká, KTERÝ konec nejistoty doklad nese. Bez něj by filtr
                       „vše nejisté" byl nerozlišený seznam dvou různých otázek. -->
                  <span v-if="inv.oss_review_oss" class="ml-1 px-1 py-0.5 rounded bg-warning-50 text-warning-700 text-[10px] font-sans font-semibold whitespace-nowrap"
                    :title="t('invoice.oss_review_badge.oss_hint')">{{ t('invoice.oss_review_badge.oss') }}</span>
                  <span v-if="inv.oss_review_domestic" class="ml-1 px-1 py-0.5 rounded bg-warning-50 text-warning-700 text-[10px] font-sans font-semibold whitespace-nowrap"
                    :title="t('invoice.oss_review_badge.domestic_hint')">{{ t('invoice.oss_review_badge.domestic') }}</span>
                </td>
                <td v-if="tbl.isVisible('client')" class="px-4 py-2.5">
                  <div class="font-medium text-neutral-900">{{ inv.client_company_name }}</div>
                  <div v-if="inv.project_name" class="text-xs text-neutral-500 truncate max-w-md">{{ inv.project_name }}</div>
                </td>
                <td v-if="tbl.isVisible('type')" class="px-4 py-2.5 text-center text-xs text-neutral-600">{{ typeLabel(inv.invoice_type) }}</td>
                <td v-if="tbl.isVisible('issued')" class="px-4 py-2.5 text-center text-xs">
                  <span :class="taxDateClass(inv.tax_date, inv.issue_date)">{{ formatDate(inv.tax_date || inv.issue_date) }}</span>
                </td>
                <td v-if="tbl.isVisible('due')" class="px-4 py-2.5 text-center text-xs">
                  <span :class="isOverdue(inv.due_date, inv.status) ? 'text-danger-500 font-medium' : 'text-neutral-600'">
                    {{ formatDate(inv.due_date) }}
                  </span>
                </td>
                <td
                  v-if="tbl.isVisible('amount')"
                  class="amount-cell px-4 py-2.5 text-right font-mono font-semibold text-neutral-900"
                  :style="{ '--bar': amountBarWidth(inv, g) }"
                >
                  {{ formatMoney(inv.amount_to_pay ?? inv.total_with_vat, inv.currency) }}
                </td>
                <td v-if="tbl.isVisible('status')" class="px-4 py-2.5 text-center" @click.stop>
                  <!-- Pro koncepty (s právem editace) zobraz tlačítko "Výkaz" místo "KONCEPT" badge — rychlý přístup k modalu. -->
                  <button v-if="inv.status === 'draft' && inv.invoice_type !== 'tax_document' && auth.canWrite('invoices')"
                    @click="openWorkReport(inv.id)"
                    class="cursor-pointer text-xs px-2 py-0.5 rounded border border-primary-500/40 text-primary-700 hover:bg-primary-50 inline-flex items-center gap-1"
                    :title="t('invoice.wr_btn')">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 17v-6m3 6v-4m3 4v-2"/></svg>
                    {{ t('invoice.wr_btn') }}
                  </button>
                  <span v-else class="text-xs px-2 py-0.5 rounded" :class="statusBadgeClass(displayStatus(inv.status, inv.payment_status))">
                    {{ statusLabel(displayStatus(inv.status, inv.payment_status)) }}
                  </span>
                  <span v-if="inv.sent_at" class="ml-1 text-xs px-1 py-0.5 rounded bg-success-50 text-success-600"
                    :title="t('invoice.sent_at', { date: formatDate(inv.sent_at) })">✉</span>
                  <span v-if="inv.reminder_count > 0" class="ml-1 text-xs px-1 py-0.5 rounded bg-warning-50 text-warning-600 font-semibold"
                    :title="t('invoice.reminder_at', { count: inv.reminder_count, date: formatDate(inv.last_reminder_at) })">⚠ {{ inv.reminder_count }}</span>
                </td>
                <td v-if="tbl.isVisible('paid_at')" class="px-4 py-2.5 text-center text-xs text-neutral-600">
                  <span v-if="inv.paid_at">{{ formatDate(inv.paid_at) }}</span>
                  <span v-else class="text-neutral-300">—</span>
                </td>
                <td v-if="tbl.isVisible('payment_method')" class="px-4 py-2.5 text-center text-xs text-neutral-600">
                  {{ t(`payment_method.${inv.payment_method || 'bank_transfer'}`) }}
                </td>
                <td v-if="tbl.isVisible('booked_at')" class="px-4 py-2.5 text-center text-xs text-neutral-600">
                  <span v-if="inv.booked_at">{{ formatDate(inv.booked_at) }}</span>
                  <span v-else class="text-neutral-300">—</span>
                </td>
                <td v-if="tbl.isVisible('exchange_rate')" class="px-4 py-2.5 text-right font-mono text-xs text-neutral-600">
                  <span v-if="inv.currency !== 'CZK' && inv.exchange_rate">{{ formatRate(inv.exchange_rate) }}</span>
                  <span v-else class="text-neutral-300">—</span>
                </td>
                <td v-if="tbl.isVisible('amount_czk')" class="px-4 py-2.5 text-right font-mono text-xs text-neutral-600">
                  <!-- BE konvence: CZK dokladům kurz vždy 1 (i kdyby byl v datech); bez kurzu nepočítat -->
                  <span v-if="inv.currency === 'CZK'">{{ formatMoney(inv.total_with_vat, 'CZK') }}</span>
                  <span v-else-if="inv.exchange_rate">{{ formatMoney(inv.total_with_vat * inv.exchange_rate, 'CZK') }}</span>
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
                        <th class="py-1 text-left font-medium">{{ t('invoice.items_table.description') }}</th>
                        <th class="py-1 text-right font-medium w-24">{{ t('invoice.items_table.qty') }}</th>
                        <th class="py-1 text-right font-medium w-32">{{ t('invoice.items_table.unit_price') }}</th>
                        <th class="py-1 text-right font-medium w-32">{{ t('invoice.items_table.total_incl_vat') }}</th>
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
            :data-workspace-route="`/invoices/${inv.id}`"
            @click="openInvoice(inv, $event)"
            @auxclick.prevent="openInvoice(inv, $event)"
            class="cursor-pointer hover:bg-neutral-50 transition px-3 py-3"
            :class="[invoiceRowClass(inv.due_date, inv.status), flashedIds.has(inv.id) ? 'row-flash' : '']"
          >
            <div class="flex items-start gap-3">
              <WorkspaceDragHandle />
              <input
                type="checkbox"
                :checked="selectedIds.includes(inv.id)"
                @change="toggleSelected(inv.id)"
                @click.stop
                class="mt-0.5 w-5 h-5 cursor-pointer rounded border-neutral-300 text-primary-600 focus:ring-2 focus:ring-primary-500/30"
              />
              <div class="flex-1 min-w-0">
                <div class="flex items-baseline justify-between gap-2">
                  <div class="font-medium text-neutral-900 truncate">{{ inv.client_company_name }}</div>
                  <div class="font-mono text-sm font-semibold whitespace-nowrap">
                    {{ formatMoney(inv.amount_to_pay ?? inv.total_with_vat, inv.currency) }}
                  </div>
                </div>
                <div class="flex items-baseline justify-between gap-2 mt-0.5 text-xs text-neutral-500">
                  <div class="truncate">
                    <RouterLink class="row-link font-mono" :to="`/invoices/${inv.id}`" @click.stop @auxclick.stop>
                      <span v-if="inv.varsymbol">{{ inv.varsymbol }}</span>
                      <span v-else class="text-neutral-400">{{ t('invoice.draft_id_short', { id: inv.id }) }}</span>
                    </RouterLink>
                    <span class="text-neutral-400"> · </span>
                    <span>{{ typeLabel(inv.invoice_type) }}</span>
                    <span v-if="inv.project_name" class="text-neutral-400"> · </span>
                    <span v-if="inv.project_name" class="truncate">{{ inv.project_name }}</span>
                    <span v-if="inv.oss_review_oss" class="ml-1 px-1 py-0.5 rounded bg-warning-50 text-warning-700 text-[10px] font-semibold whitespace-nowrap"
                      :title="t('invoice.oss_review_badge.oss_hint')">{{ t('invoice.oss_review_badge.oss') }}</span>
                    <span v-if="inv.oss_review_domestic" class="ml-1 px-1 py-0.5 rounded bg-warning-50 text-warning-700 text-[10px] font-semibold whitespace-nowrap"
                      :title="t('invoice.oss_review_badge.domestic_hint')">{{ t('invoice.oss_review_badge.domestic') }}</span>
                  </div>
                </div>
                <div class="flex items-center justify-between gap-2 mt-2">
                  <div class="text-xs text-neutral-600 whitespace-nowrap">
                    <span :class="taxDateClass(inv.tax_date, inv.issue_date)">{{ formatDate(inv.tax_date || inv.issue_date) }}</span>
                    <span class="text-neutral-400"> → </span>
                    <span :class="isOverdue(inv.due_date, inv.status) ? 'text-danger-500 font-medium' : ''">
                      {{ formatDate(inv.due_date) }}
                    </span>
                  </div>
                  <div class="flex items-center gap-1 flex-wrap justify-end" @click.stop>
                    <PostingBadge v-if="inv.locked?.journal_entry_id"
                      :booked-at="inv.booked_at" :journal-entry-id="inv.locked.journal_entry_id" />
                    <svg v-else-if="inv.locked?.is_locked" class="w-3.5 h-3.5 text-neutral-400"
                      fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"
                      role="img" :aria-label="lockTitle(inv)">
                      <title>{{ lockTitle(inv) }}</title>
                      <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25z" />
                    </svg>
                    <span v-if="inv.sent_at" class="text-xs px-1 py-0.5 rounded bg-success-50 text-success-600"
                      :title="t('invoice.sent_at', { date: formatDate(inv.sent_at) })">✉</span>
                    <span v-if="inv.reminder_count > 0" class="text-xs px-1 py-0.5 rounded bg-warning-50 text-warning-600 font-semibold"
                      :title="t('invoice.reminder_at', { count: inv.reminder_count, date: formatDate(inv.last_reminder_at) })">⚠ {{ inv.reminder_count }}</span>
                    <!-- Pro koncepty (s právem editace) zobraz tlačítko "Výkaz" místo "KONCEPT" badge — stejně jako v desktop tabulce. -->
                    <button v-if="inv.status === 'draft' && inv.invoice_type !== 'tax_document' && auth.canWrite('invoices')"
                      @click="openWorkReport(inv.id)"
                      class="cursor-pointer text-xs px-2 py-0.5 rounded border border-primary-500/40 text-primary-700 hover:bg-primary-50 inline-flex items-center gap-1"
                      :title="t('invoice.wr_btn')">
                      <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 17v-6m3 6v-4m3 4v-2"/></svg>
                      {{ t('invoice.wr_btn') }}
                    </button>
                    <span v-else class="text-xs px-2 py-0.5 rounded" :class="statusBadgeClass(inv.status)">
                      {{ statusLabel(inv.status) }}
                    </span>
                  </div>
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

    <div v-if="bulkPdfOpen" class="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4" @click.self="bulkPdfOpen = false">
      <div class="bg-surface rounded-lg shadow-xl w-full max-w-md p-5 space-y-4">
        <div>
          <h2 class="text-lg font-semibold">{{ t('invoice.bulk_pdf_title') }}</h2>
          <p class="text-sm text-neutral-500 mt-1">{{ t('invoice.bulk_pdf_hint', { n: selectedPdfIds.length }) }}</p>
        </div>
        <label class="flex items-start gap-3 cursor-pointer rounded-md border border-neutral-200 bg-neutral-50 p-3">
          <input v-model="bulkPdfSign" type="checkbox"
            class="mt-0.5 w-4 h-4 rounded border-neutral-300 text-primary-600 focus:ring-primary-500" />
          <span>
            <span class="block text-sm font-medium text-neutral-800">{{ t('invoice.bulk_pdf_sign') }}</span>
            <span class="block text-xs text-neutral-500 mt-0.5">{{ t('invoice.bulk_pdf_sign_hint') }}</span>
          </span>
        </label>
        <p v-if="selectedPdfIds.length > 100" class="text-sm text-danger-500">
          {{ t('invoice.bulk_pdf_limit') }}
        </p>
        <div class="flex flex-wrap justify-end gap-2 pt-1">
          <button type="button" @click="bulkPdfOpen = false" :disabled="bulkBusy"
            :class="btnOutline('neutral')">
            {{ t('common.cancel') }}
          </button>
          <button type="button" @click="bulkExportPdf" :disabled="bulkBusy || selectedPdfIds.length > 100"
            :class="btnFilled('primary')">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 16V4m0 12l-4-4m4 4l4-4M4 20h16" /></svg>
            {{ bulkBusy ? t('common.loading') : t('invoice.bulk_pdf_download') }}
          </button>
        </div>
      </div>
    </div>

    <!-- Hromadné nastavení OSS — dvoufázové: náhled, teprve pak provedení (OSS-7) -->
    <div v-if="ossBulkOpen" class="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4"
      @click.self="closeBulkOss">
      <div class="bg-surface rounded-lg shadow-xl w-full max-w-3xl max-h-[90vh] overflow-y-auto p-5 space-y-4">
        <div>
          <h2 class="text-lg font-semibold">{{ t('invoice.bulk_oss_title') }}</h2>
          <p class="text-sm text-neutral-500 mt-1">{{ t('invoice.bulk_oss_hint', { n: selectedIds.length }) }}</p>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <div>
            <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('invoice.bulk_oss_scope') }}</label>
            <select v-model="ossBulkForm.scope" @change="ossBulkPreview = null"
              class="w-full h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
              <option value="needs_review">{{ t('invoice.bulk_oss_scope_needs_review') }}</option>
              <option value="missing_rate_type">{{ t('invoice.bulk_oss_scope_missing_rate_type') }}</option>
              <option value="oss">{{ t('invoice.bulk_oss_scope_oss') }}</option>
              <option value="all">{{ t('invoice.bulk_oss_scope_all') }}</option>
            </select>
          </div>
          <div>
            <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('invoice.bulk_oss_mode') }}</label>
            <select v-model="ossBulkForm.mode" @change="ossBulkPreview = null"
              class="w-full h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
              <option value="on">{{ t('invoice.bulk_oss_mode_on') }}</option>
              <option value="off">{{ t('invoice.bulk_oss_mode_off') }}</option>
              <option value="keep">{{ t('invoice.bulk_oss_mode_keep') }}</option>
            </select>
          </div>
        </div>

        <div v-if="ossBulkForm.mode !== 'off'" class="grid grid-cols-1 sm:grid-cols-3 gap-3">
          <div>
            <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('invoice.bulk_oss_country') }}</label>
            <select v-model="ossBulkForm.country" @change="ossBulkPreview = null"
              class="w-full h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
              <option value="">{{ t('invoice.bulk_oss_keep_value') }}</option>
              <option v-for="c in euCountries" :key="c.id" :value="c.iso2">{{ c.iso2 }} — {{ c.name_cs }}</option>
            </select>
          </div>
          <div>
            <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('invoice.bulk_oss_rate_type') }}</label>
            <select v-model="ossBulkForm.rate_type" @change="ossBulkPreview = null"
              class="w-full h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
              <option value="">{{ t('invoice.bulk_oss_keep_value') }}</option>
              <option value="standard">{{ t('oss_rates.type_standard') }}</option>
              <option value="reduced">{{ t('oss_rates.type_reduced') }}</option>
              <option value="second_reduced">{{ t('oss_rates.type_second_reduced') }}</option>
              <option value="parking">{{ t('oss_rates.type_parking') }}</option>
            </select>
          </div>
          <div>
            <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('invoice.bulk_oss_supply_type') }}</label>
            <select v-model="ossBulkForm.supply_type" @change="ossBulkPreview = null"
              class="w-full h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
              <option value="">{{ t('invoice.bulk_oss_keep_value') }}</option>
              <option value="goods">{{ t('invoice.bulk_oss_supply_goods') }}</option>
              <option value="services">{{ t('invoice.bulk_oss_supply_services') }}</option>
            </select>
          </div>
        </div>

        <label class="flex items-start gap-3 cursor-pointer rounded-md border border-neutral-200 bg-neutral-50 p-3">
          <input v-model="ossBulkForm.clear_needs_review" type="checkbox" @change="ossBulkPreview = null"
            class="mt-0.5 w-4 h-4 rounded border-neutral-300 text-primary-600 focus:ring-primary-500" />
          <span>
            <span class="block text-sm font-medium text-neutral-800">{{ t('invoice.bulk_oss_clear_review') }}</span>
            <span class="block text-xs text-neutral-500 mt-0.5">{{ t('invoice.bulk_oss_clear_review_hint') }}</span>
          </span>
        </label>

        <!-- Rozpad dávky — backend se zastavil u prvního selhání a vrátil, co stihl. -->
        <div v-if="ossBulkFailure" class="rounded-md border border-danger-500/40 bg-danger-50 p-3 space-y-2 text-sm">
          <p class="font-medium text-danger-700">{{ t('invoice.bulk_oss_failure_title') }}</p>
          <p class="text-danger-700">{{ ossBulkFailure.message }}</p>
          <dl class="space-y-1.5 text-xs text-neutral-700">
            <div>
              <dt class="font-medium text-success-700">
                {{ t('invoice.bulk_oss_failure_completed', { n: ossBulkFailure.completed_invoice_ids.length }) }}
              </dt>
              <dd v-if="ossBulkFailure.completed_invoice_ids.length" class="font-mono break-words">
                {{ ossBulkFailure.completed_invoice_ids.map(invoiceLabel).join(', ') }}
              </dd>
            </div>
            <div>
              <dt class="font-medium text-danger-700">{{ t('invoice.bulk_oss_failure_failed_at') }}</dt>
              <dd class="font-mono">
                {{ ossBulkFailure.failed_invoice.varsymbol ?? ('#' + ossBulkFailure.failed_invoice.invoice_id) }}
              </dd>
            </div>
            <div>
              <dt class="font-medium text-warning-700">
                {{ t('invoice.bulk_oss_failure_not_attempted', { n: ossBulkFailure.not_attempted_invoice_ids.length }) }}
              </dt>
              <dd v-if="ossBulkFailure.not_attempted_invoice_ids.length" class="font-mono break-words">
                {{ ossBulkFailure.not_attempted_invoice_ids.map(invoiceLabel).join(', ') }}
              </dd>
            </div>
            <div v-if="ossBulkFailure.pdf_not_invalidated.length">
              <dt class="font-medium text-warning-700">
                {{ t('invoice.bulk_oss_failure_pdf_stale', { n: ossBulkFailure.pdf_not_invalidated.length }) }}
              </dt>
              <dd class="font-mono break-words">
                {{ ossBulkFailure.pdf_not_invalidated.map(invoiceLabel).join(', ') }}
              </dd>
            </div>
          </dl>
          <p class="text-xs text-neutral-600">{{ t('invoice.bulk_oss_failure_retry_hint') }}</p>
        </div>

        <!-- Zápis prošel, ale u části dokladů zůstala v cache stará PDF. -->
        <div v-if="ossBulkStalePdf.length" class="rounded-md border border-warning-500/40 bg-warning-50 p-3 space-y-1 text-sm">
          <p class="font-medium text-warning-700">
            {{ t('invoice.bulk_oss_failure_pdf_stale', { n: ossBulkStalePdf.length }) }}
          </p>
          <p class="font-mono text-xs break-words">{{ ossBulkStalePdf.map(invoiceLabel).join(', ') }}</p>
          <p class="text-xs text-neutral-600">{{ t('invoice.bulk_oss_pdf_stale_hint') }}</p>
        </div>

        <!-- Náhled -->
        <div v-if="ossBulkPreview" class="space-y-3">
          <div class="flex flex-wrap gap-4 text-sm rounded-md border border-neutral-200 bg-neutral-50 p-3">
            <span><strong>{{ ossBulkPreview.summary.documents_to_change }}</strong> {{ t('invoice.bulk_oss_will_change') }}</span>
            <span><strong>{{ ossBulkPreview.summary.items_to_change }}</strong> {{ t('invoice.bulk_oss_items') }}</span>
            <span :class="ossBulkPreview.summary.documents_skipped > 0 ? 'text-warning-600' : ''">
              <strong>{{ ossBulkPreview.summary.documents_skipped }}</strong> {{ t('invoice.bulk_oss_skipped') }}
            </span>
            <span v-if="ossBulkPreview.summary.warnings > 0" class="text-warning-600">
              <strong>{{ ossBulkPreview.summary.warnings }}</strong> {{ t('invoice.bulk_oss_warnings') }}
            </span>
          </div>

          <div class="border border-neutral-200 rounded-md overflow-x-auto max-h-72 overflow-y-auto">
            <table class="w-full text-sm">
              <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide sticky top-0">
                <tr>
                  <th class="px-3 py-2 text-left font-medium">{{ t('invoice.varsymbol') }}</th>
                  <th class="px-3 py-2 text-left font-medium w-28">{{ t('invoice.tax_date') }}</th>
                  <th class="px-3 py-2 text-left font-medium">{{ t('invoice.bulk_oss_result') }}</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-neutral-100">
                <tr v-for="d in ossBulkPreview.documents" :key="d.invoice_id"
                  :class="d.action === 'skip' ? 'bg-warning-50/40' : ''">
                  <td class="px-3 py-2 font-mono text-xs">{{ d.varsymbol ?? ('#' + d.invoice_id) }}</td>
                  <td class="px-3 py-2 font-mono text-xs">{{ d.tax_date ?? '—' }}</td>
                  <td class="px-3 py-2 text-xs">
                    <template v-if="d.action === 'update'">
                      <span class="text-success-600">{{ t('invoice.bulk_oss_change_count', { n: d.changes.length }) }}</span>
                      <div v-for="(w, i) in d.warnings" :key="i" class="text-warning-600 mt-0.5">{{ w }}</div>
                    </template>
                    <span v-else class="text-warning-600">{{ d.skip_detail }}</span>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <div class="flex flex-wrap justify-end gap-2 pt-1 border-t border-neutral-200">
          <button type="button" @click="closeBulkOss" :disabled="bulkBusy" :class="btnOutline('neutral')">
            {{ ossBulkFailure || ossBulkStalePdf.length ? t('common.close') : t('common.cancel') }}
          </button>
          <button type="button" @click="runBulkOssPreview" :disabled="bulkBusy" :class="btnOutline('primary')">
            {{ bulkBusy ? t('common.loading') : t('invoice.bulk_oss_preview') }}
          </button>
          <button type="button" @click="applyBulkOss"
            :disabled="bulkBusy || !ossBulkPreview || ossBulkPreview.summary.documents_to_change === 0"
            :class="btnFilled('primary')">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
            {{ t('invoice.bulk_oss_apply') }}
          </button>
        </div>
      </div>
    </div>

    <!-- Work report modal — otevřený z buttonu "Výkaz" v sloupci Stav. -->
    <WorkReportModal v-if="wrModalInvoiceId > 0"
      v-model="wrModalOpen"
      :invoice-id="wrModalInvoiceId"
      @saved="load(true)" />
  </div>
</template>

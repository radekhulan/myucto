<script setup lang="ts">
import { ref, onMounted, reactive, computed, watch, defineAsyncComponent } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink, useRoute } from 'vue-router'
import {
  accountingApi,
  type AccountingPeriod,
  type SaldoReport,
  type SaldoAccountBlock,
  type SaldoItem,
} from '@/api/accounting'
import type { SortPref } from '@/api/preferences'
import { useToast } from '@/composables/useToast'
import { useTablePrefs } from '@/composables/useTablePrefs'
import { ensurePrefsLoaded } from '@/composables/useUserPrefs'
import { formatMoney, formatDate } from '@/composables/useFormat'
import { ICONS, btnOutline, btnFilled, btnOutlineSm } from '@/components/ui/buttonStyles'
import EmptyState from '@/components/ui/EmptyState.vue'
import SortableTh from '@/components/ui/SortableTh.vue'
import DateInput from '@/components/ui/DateInput.vue'
import SaldoAdvanceInfo from '@/components/accounting/SaldoAdvanceInfo.vue'
import { useAuthStore } from '@/stores/auth'
import { ADVANCE_ACCOUNTS, SUGGEST_ACCOUNTS, isAdvanceAccount, isOffsetable, isSettleable, type SaldoSettleTarget } from '@/components/accounting/saldoSettle'
import { balancedOffsetDocs, offsetRoute } from '@/components/accounting/offsetLink'
import SaldoAdvanceIssues from '@/components/accounting/SaldoAdvanceIssues.vue'

const SaldoSettleDialog = defineAsyncComponent(() => import('@/components/accounting/SaldoSettleDialog.vue'))
const SaldoAdvanceSettleDialog = defineAsyncComponent(() => import('@/components/accounting/SaldoAdvanceSettleDialog.vue'))
const SaldoResidualDialog = defineAsyncComponent(() => import('@/components/accounting/SaldoResidualDialog.vue'))
const SaldoSuggestionsDialog = defineAsyncComponent(() => import('@/components/accounting/SaldoSuggestionsDialog.vue'))

const { t } = useI18n()
const toast = useToast()
const route = useRoute()
const auth = useAuthStore()

const periods = ref<AccountingPeriod[]>([])
const report = ref<SaldoReport | null>(null)
const loading = ref(false)

const ACCOUNT_OPTIONS = ['all', '311', '321', '314', '324', '315', '325']

const filters = reactive({
  period_id: '' as number | '',
  as_of: '',
  account: 'all',
})

function queryParams() {
  return {
    period_id: Number(filters.period_id),
    as_of: filters.as_of || undefined,
    account: filters.account || 'all',
  }
}

// ── Task #2: přepínač "podle partnera" / "podle dokladů" (plochý seznam) ────
type ViewMode = 'partner' | 'flat'
const viewMode = ref<ViewMode>('flat')

/**
 * Strana salda podle normální strany účtu: 311/314 (debet) = co dluží nám,
 * 321/324 (kredit) = co dlužíme my. Plochý pohled dřív míchal obojí do jedné
 * tabulky řazené dle splatnosti, takže mezi nezaplacenými fakturami odběratelů
 * seděla přijatá faktura od dodavatele — účetně opačná věc, kterou od sebe
 * odlišoval jen tříznakový kód účtu v prvním sloupci.
 */
type SaldoSide = 'receivable' | 'payable'

const SIDE_ORDER: SaldoSide[] = ['receivable', 'payable']

function sideOfAccount(normalSide: string): SaldoSide {
  return normalSide === 'credit' ? 'payable' : 'receivable'
}

interface FlatRow extends SaldoItem {
  account_code: string
  partner_id: number
  partner_name: string
  side: SaldoSide
}

/** Plochý rozpad accounts→partners→items, beze seskupení — zdroj pro flat pohled i export. */
const flatRows = computed<FlatRow[]>(() => {
  if (!report.value) return []
  const rows: FlatRow[] = []
  for (const acc of report.value.accounts) {
    const side = sideOfAccount(acc.account.normal_side)
    for (const p of acc.partners) {
      for (const it of p.items) {
        rows.push({ ...it, account_code: acc.account.code, partner_id: p.partner_id, partner_name: p.partner_name, side })
      }
    }
  }
  return rows
})

const partnerOptions = computed(() => {
  const seen = new Map<number, string>()
  for (const r of flatRows.value) seen.set(r.partner_id, r.partner_name)
  return Array.from(seen.entries())
    .map(([id, name]) => ({ id, name }))
    .sort((a, b) => a.name.localeCompare(b.name, 'cs'))
})

const flatFilters = reactive({
  partner_id: '' as number | '',
  min_overdue_days: '' as number | '',
})

// Vybraný partner v odjeté sestavě zmizel (jiné období/účet) → filtr by tiše
// vracel prázdno bez vysvětlení; reset je srozumitelnější než mrtvý filtr.
watch(partnerOptions, (opts) => {
  if (flatFilters.partner_id !== '' && !opts.some(o => o.id === flatFilters.partner_id)) {
    flatFilters.partner_id = ''
  }
})

const sort = ref<SortPref>({ key: 'due_date', dir: 'asc' })
function toggleSort(key: string) {
  sort.value = sort.value.key === key
    ? { key, dir: sort.value.dir === 'asc' ? 'desc' : 'asc' }
    : { key, dir: 'asc' }
}

const filteredFlatRows = computed<FlatRow[]>(() => {
  let rows = flatRows.value
  if (flatFilters.partner_id !== '') {
    rows = rows.filter(r => r.partner_id === flatFilters.partner_id)
  }
  const minDays = Number(flatFilters.min_overdue_days) || 0
  if (minDays > 0) {
    rows = rows.filter(r => r.days_overdue >= minDays)
  }
  const key = sort.value.key as keyof FlatRow
  const dir = sort.value.dir === 'asc' ? 1 : -1
  return [...rows].sort((a, b) => {
    const av = a[key]
    const bv = b[key]
    if (typeof av === 'number' && typeof bv === 'number') return (av - bv) * dir
    return String(av ?? '').localeCompare(String(bv ?? ''), 'cs') * dir
  })
})

/**
 * Sestava rozdělená na pohledávky a závazky — bloky konfrontace i řádky dokladů
 * dostane každá strana vlastní. Strana bez účtu v sestavě se vůbec nevykreslí.
 */
/**
 * Kolik řádků se vykreslí najednou. Saldokonto je kumulativní sestava „k datu" bez
 * dolní hranice, takže na zavedené firmě jde o tisíce otevřených položek — na
 * produkci 3 170 řádků v jedné tabulce, což prohlížeč vykresloval přes tři sekundy.
 * Server vrací celou sestavu schválně (součty i export musí sedět na haléř), omezuje
 * se tedy jen to, co je NAMALOVANÉ; `total` níž se dál počítá ze VŠECH řádků.
 */
const ROW_CHUNK = 200
const shown = reactive<Record<string, number>>({})

const sections = computed(() => {
  const r = report.value
  if (!r) return []
  return SIDE_ORDER
    .map(side => {
      const rows = filteredFlatRows.value.filter(x => x.side === side)
      return {
        side,
        blocks: r.accounts.filter(b => sideOfAccount(b.account.normal_side) === side),
        rows,
        visible: rows.slice(0, shown[side] ?? ROW_CHUNK),
        hidden: Math.max(0, rows.length - (shown[side] ?? ROW_CHUNK)),
        // Σ ze VŠECH řádků strany, ne jen z vykreslených — jinak by inventarizační
        // sestava ukazovala součet, který neodpovídá zůstatku hlavní knihy.
        total: Math.round(rows.reduce((s, x) => s + x.remaining_czk, 0) * 100) / 100,
      }
    })
    .filter(s => s.blocks.length > 0)
})

function showMore(side: string, hidden: number) {
  shown[side] = (shown[side] ?? ROW_CHUNK) + Math.min(hidden, ROW_CHUNK)
}

// Nová data nebo jiný filtr = zase od začátku; jinak by po zúžení filtru zůstalo
// rozbalené okno z předchozí sestavy.
watch([report, filteredFlatRows], () => {
  for (const k of Object.keys(shown)) delete shown[k]
})
watch(report, () => { selectedRows.value = new Set() })

// ── Task #3: as_of napříč obdobími — UI upozornění, když se liší od výběru ──
const asOfPeriodNote = computed(() => {
  const r = report.value
  if (!r) return null
  if (r.as_of_period === null) return { kind: 'missing' as const }
  if (r.as_of_period.id !== r.period.id) return { kind: 'different' as const, period: r.as_of_period }
  return null
})

function switchToAsOfPeriod() {
  const note = asOfPeriodNote.value
  if (note?.kind !== 'different') return
  filters.period_id = note.period.id
  load()
}

/**
 * Všechny účty se načítají souběžně po jednom: server počítá každý účet zvlášť a na
 * velké firmě trvá jeden účet i sekundu, takže sestava naráz by čekala na součet.
 * Skládá se stejně jako na serveru: pořadí účtů a vynechané účty bez zůstatku i položek.
 */
async function fetchReport(): Promise<SaldoReport> {
  const params = queryParams()
  if (params.account !== 'all') return accountingApi.getSaldo(params)
  const parts = await Promise.all(
    ACCOUNT_OPTIONS.filter(a => a !== 'all').map(account => accountingApi.getSaldo({ ...params, account })),
  )
  return {
    ...parts[0],
    accounts: parts
      .flatMap(p => p.accounts)
      .filter(b => Math.round(b.gl_balance * 100) !== 0 || b.partners.length > 0 || (b.advance_deduction_issues?.length ?? 0) > 0),
  }
}

async function load() {
  if (!filters.period_id) return
  loading.value = true
  try {
    report.value = await fetchReport()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
    report.value = null
  } finally {
    loading.value = false
  }
}

// Expand/collapse per partner (klíč = "accCode:partnerId").
const expanded = reactive<Record<string, boolean>>({})
function partnerKey(accCode: string, partnerId: number) {
  return `${accCode}:${partnerId}`
}
function toggle(accCode: string, partnerId: number) {
  const k = partnerKey(accCode, partnerId)
  expanded[k] = !expanded[k]
}

/**
 * Záloha čekající na vyúčtování (kind = advance_pending) nemá „částku a úhradu" jako
 * faktura: Částka = přijatá/poskytnutá platba, Uhrazeno = daň z daňového dokladu
 * k platbě. Zbývá zůstává se znaménkem (základ zálohy), aby součet seděl na hlavní knihu.
 */
function amountOf(it: SaldoItem): number {
  return it.kind === 'advance_pending' ? (it.advance_payment_czk ?? 0) : it.booked_czk
}
function paidOf(it: SaldoItem): number {
  return it.kind === 'advance_pending' ? (it.advance_vat_czk ?? 0) : it.paid_czk
}
function paidTitle(it: SaldoItem): string | undefined {
  return it.kind === 'advance_pending' ? t('accounting.saldo.advance_vat_tooltip') : undefined
}

function docLink(it: SaldoItem) {
  if (it.doc_type === 'other_item') {
    return { name: 'other-item-detail', params: { id: it.doc_id } }
  }
  return it.doc_type === 'purchase_invoice'
    ? { name: 'purchase-invoice-detail', params: { id: it.doc_id } }
    : { name: 'invoice-detail', params: { id: it.doc_id } }
}

// ── Vyrovnání položky zápisem v deníku ─────────────────────────────────────
// Vyrovnání je vždy navázané na řádek zápisu (rozpad řádku na doklady, nebo ostatní
// položka ze zůstatku bez dokladu), aby konfrontace s hlavní knihou dál platila.
const canSettle = computed(() => auth.canWrite('accounting.journal.write'))
const canResidual = computed(() => canSettle.value && auth.canWrite('other_items'))
const settleTargets = ref<SaldoSettleTarget[] | null>(null)
const residualFor = ref<{ code: string; name: string } | null>(null)
const suggestionsOpen = ref(false)

function settleTarget(it: SaldoItem, accountCode: string, partnerId: number, partnerName: string): SaldoSettleTarget {
  return {
    doc_type: it.doc_type as SaldoSettleTarget['doc_type'],
    doc_id: it.doc_id,
    doc_no: it.doc_no,
    partner_id: partnerId,
    partner_name: partnerName,
    account_code: accountCode,
    currency_code: it.currency_code,
    remaining_czk: it.remaining_czk,
  }
}

function rowKey(it: { account_code: string; doc_type: string; doc_id: number }) {
  return `${it.account_code}-${it.doc_type}-${it.doc_id}`
}

/** Označené položky plochého pohledu; hromadně jde vyrovnat jen jeden partner a jeden účet. */
const selectedRows = ref<Set<string>>(new Set())
const selectedItems = computed(() => flatRows.value.filter(r => selectedRows.value.has(rowKey(r))))
const bulkValid = computed(() => {
  const rows = selectedItems.value
  return rows.length > 0
    && rows.every(r => r.partner_id === rows[0].partner_id && r.account_code === rows[0].account_code)
})

function toggleRow(row: FlatRow) {
  const next = new Set(selectedRows.value)
  const key = rowKey(row)
  if (next.has(key)) next.delete(key)
  else next.add(key)
  selectedRows.value = next
}

function openSettle(rows: FlatRow[]) {
  settleTargets.value = rows.map(r => settleTarget(r, r.account_code, r.partner_id, r.partner_name))
}

function openSettlePartner(it: SaldoItem, b: SaldoAccountBlock, partnerId: number, partnerName: string) {
  settleTargets.value = [settleTarget(it, b.account.code, partnerId, partnerName)]
}

function canSettleItem(it: SaldoItem, accountCode: string) {
  return canSettle.value && isSettleable(it, accountCode)
}

// ── Vzájemný zápočet z výběru (pohledávky i závazky jednoho partnera) ───────
const canMutualOffset = computed(() => auth.canWrite('accounting.offsets'))
/** Výběr řádků nabízí vyrovnání zápisem i zápočet; zaškrtávátko stačí jedno z práv. */
const canPick = computed(() => canSettle.value || canMutualOffset.value)

function pickable(it: FlatRow): boolean {
  return (canSettle.value && isSettleable(it, it.account_code)) || (canMutualOffset.value && isOffsetable(it, it.account_code))
}

const mutualOffsetLink = computed(() => {
  if (!canMutualOffset.value) return null
  const rows = selectedItems.value
  if (rows.length === 0 || !rows.every(r => r.partner_id === rows[0].partner_id && isOffsetable(r, r.account_code))) return null
  const receivables = rows.filter(r => r.doc_type === 'invoice').map(r => ({ doc_id: r.doc_id, remaining: r.remaining_czk }))
  const payables = rows.filter(r => r.doc_type === 'purchase_invoice').map(r => ({ doc_id: r.doc_id, remaining: r.remaining_czk }))
  if (receivables.length === 0 || payables.length === 0) return null
  return offsetRoute(rows[0].partner_id, balancedOffsetDocs(receivables, payables))
})

function onSettleDone() {
  settleTargets.value = null
  residualFor.value = null
  suggestionsOpen.value = false
  selectedRows.value = new Set()
  load()
}

/** Záloha na 314/324 se vyrovnává čerpáním konečnou fakturou, ne rozpadem zápisu. */
const settleAdvance = computed(() => !!settleTargets.value?.length && isAdvanceAccount(settleTargets.value[0].account_code))

const suggestAccount = computed(() => SUGGEST_ACCOUNTS.includes(filters.account) ? filters.account : 'all')
const showSuggest = computed(() => canSettle.value && (filters.account === 'all' || SUGGEST_ACCOUNTS.includes(filters.account)))

// ── Podklad pro banky: PDF s členěním podle doby po splatnosti ───────────────
// Volba i hranice se pamatují per uživatel (každá banka chce jiné rozmezí, ale účetní
// je nastaví jednou). Hranice validuje i server, tady jen kvůli okamžité odezvě.
const tbl = useTablePrefs('saldo', [])
const AGING_DEFAULT = [30, 60, 120]
const AGING_MAX = 6
interface AgingPref { enabled: boolean; boundaries: number[]; detail: boolean }
const aging = reactive<AgingPref>({ enabled: false, boundaries: [...AGING_DEFAULT], detail: true })
const agingText = ref(AGING_DEFAULT.join(', '))
let agingLoaded = false

const agingBoundaries = computed<number[] | null>(() => {
  const parts = agingText.value.split(/[,;\s]+/).filter(p => p !== '')
  if (parts.length === 0 || parts.length > AGING_MAX || parts.some(p => !/^\d{1,4}$/.test(p))) return null
  const days = [...new Set(parts.map(Number))].sort((a, b) => a - b)
  return days.every(d => d >= 1 && d <= 3650) ? days : null
})

watch([() => aging.enabled, () => aging.detail, agingBoundaries], () => {
  if (!agingLoaded) return
  if (agingBoundaries.value) aging.boundaries = agingBoundaries.value
  tbl.setOption('pdf_aging', { enabled: aging.enabled, boundaries: aging.boundaries, detail: aging.detail })
})

function restoreAging() {
  const saved = tbl.option<Partial<AgingPref> | null>('pdf_aging', null)
  if (saved) {
    aging.enabled = saved.enabled === true
    aging.detail = saved.detail !== false
    if (Array.isArray(saved.boundaries) && saved.boundaries.length > 0) aging.boundaries = saved.boundaries.map(Number)
  }
  agingText.value = aging.boundaries.join(', ')
  agingLoaded = true
}

const exporting = ref(false)
async function exportFile(format: 'pdf' | 'xlsx') {
  if (!filters.period_id || !report.value) return
  if (format === 'pdf' && aging.enabled && !agingBoundaries.value) {
    toast.error(t('accounting.saldo.pdf_aging_invalid'))
    return
  }
  exporting.value = true
  try {
    // Task #2: PDF zůstává vždy grouped (zákonný inventarizační protokol) —
    // view ovlivňuje jen pracovní XLSX export dle aktuálního přepínače.
    const view = viewMode.value === 'flat' ? 'flat' : 'grouped'
    const agingParams: Record<string, string> = format === 'pdf' && aging.enabled && agingBoundaries.value
      ? { aging: agingBoundaries.value.join(','), detail: aging.detail ? '1' : '0' }
      : {}
    const r = await accountingApi.exportReport('/accounting/reports/saldo/export', { ...queryParams(), format, view, ...agingParams })
    const suffix = format === 'xlsx' && view === 'flat' ? '-doklady' : (agingParams.aging ? '-po-splatnosti' : '')
    downloadBlob(r.data as unknown as Blob, `saldokonto${suffix}-${report.value.as_of}.${format}`)
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

function accountBlockLabel(b: SaldoAccountBlock) {
  return `${b.account.code} - ${b.account.name}`
}

/**
 * Deep-link `?period_id=&as_of=&account=&view=&partner_id=&min_overdue_days=`.
 * Bez toho odkaz na konkrétní saldokonto (typicky „účet 311 k rozvahovému dni")
 * otevřel stránku ve výchozím nastavení a query tiše zahodil — příjemce viděl
 * jiná čísla, než co mu odesílatel poslal.
 */
function applyQuery(): { periodId: number | null } {
  const q = route.query
  const str = (v: unknown): string => (typeof v === 'string' ? v : '')
  if (/^\d{4}-\d{2}-\d{2}$/.test(str(q.as_of))) filters.as_of = str(q.as_of)
  if (ACCOUNT_OPTIONS.includes(str(q.account))) filters.account = str(q.account)
  if (str(q.view) === 'partner' || str(q.view) === 'flat') viewMode.value = str(q.view) as ViewMode
  const partnerId = Number(str(q.partner_id))
  if (Number.isInteger(partnerId) && partnerId > 0) flatFilters.partner_id = partnerId
  const minDays = Number(str(q.min_overdue_days))
  if (Number.isInteger(minDays) && minDays > 0) flatFilters.min_overdue_days = minDays
  const periodId = Number(str(q.period_id))
  return { periodId: Number.isInteger(periodId) && periodId > 0 ? periodId : null }
}

onMounted(async () => {
  void ensurePrefsLoaded().then(restoreAging)
  try { periods.value = await accountingApi.listPeriods() } catch { periods.value = [] }
  const { periodId } = applyQuery()
  const open = periods.value.filter(p => p.status === 'open')
  const def = open.length
    ? open.reduce((a, b) => (b.fiscal_year > a.fiscal_year ? b : a))
    : periods.value[0]
  // Období z adresy jen tehdy, když opravdu existuje — jinak by překlep v odkazu
  // shodil načtení na 404 místo toho, aby stránka ukázala výchozí rok.
  const fromQuery = periodId !== null && periods.value.some(p => p.id === periodId) ? periodId : null
  const use = fromQuery ?? def?.id ?? null
  if (use !== null) {
    filters.period_id = use
    await load()
  }
})
</script>

<template>
  <div>
    <div class="flex flex-wrap items-center justify-between gap-2 mb-4">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('accounting.saldo.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5">{{ t('accounting.saldo.subtitle') }}</p>
      </div>
      <div class="flex flex-wrap items-center gap-2">
        <div class="flex items-center gap-1.5" role="group" :aria-label="t('accounting.saldo.view_group_label')">
          <button type="button" @click="viewMode = 'flat'"
            :class="viewMode === 'flat' ? btnFilled('neutral') : btnOutline('neutral')">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.doc" /></svg>
            {{ t('accounting.saldo.view_by_document') }}
          </button>
          <button type="button" @click="viewMode = 'partner'"
            :class="viewMode === 'partner' ? btnFilled('neutral') : btnOutline('neutral')">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.user" /></svg>
            {{ t('accounting.saldo.view_by_partner') }}
          </button>
        </div>
        <button v-if="showSuggest" type="button" :disabled="!report || loading" @click="suggestionsOpen = true"
          :class="[btnOutline('success'), 'whitespace-nowrap']" data-testid="saldo-suggest">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.link" /></svg>
          {{ t('accounting.saldo_settle.suggest') }}
        </button>
        <button :disabled="!report || exporting" @click="exportFile('pdf')" :class="btnOutline('primary')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.download" /></svg>
          {{ t('accounting.saldo.export_pdf') }}
        </button>
        <button :disabled="!report || exporting" @click="exportFile('xlsx')" :class="btnOutline('primary')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.download" /></svg>
          {{ t('accounting.saldo.export_xlsx') }}
        </button>
      </div>
    </div>

    <!-- Filtry -->
    <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-3 mb-4">
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
        <div>
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.saldo.filter_period') }}</label>
          <select v-model="filters.period_id" @change="load"
            class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface">
            <option v-for="p in periods" :key="p.id" :value="p.id">{{ p.fiscal_year }}</option>
          </select>
        </div>
        <div>
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.saldo.filter_as_of') }}</label>
          <DateInput v-model="filters.as_of" @change="load"
            class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm" />
        </div>
        <div>
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.saldo.filter_account') }}</label>
          <select v-model="filters.account" @change="load"
            class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface">
            <option v-for="a in ACCOUNT_OPTIONS" :key="a" :value="a">
              {{ a === 'all' ? t('accounting.saldo.account_all') : t(`accounting.saldo.account_${a}`) }}
            </option>
          </select>
        </div>
        <div v-if="viewMode === 'flat'">
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.saldo.filter_partner') }}</label>
          <select v-model="flatFilters.partner_id" class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface">
            <option value="">{{ t('accounting.saldo.filter_partner_all') }}</option>
            <option v-for="p in partnerOptions" :key="p.id" :value="p.id">{{ p.name }}</option>
          </select>
        </div>
        <div v-if="viewMode === 'flat'">
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.saldo.filter_overdue_min') }}</label>
          <input v-model="flatFilters.min_overdue_days" type="number" min="0" placeholder="0"
            class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm" />
        </div>
      </div>
      <div class="mt-3 pt-3 border-t border-neutral-100 flex flex-wrap items-center gap-x-4 gap-y-2 text-sm">
        <label class="inline-flex items-center gap-2 cursor-pointer">
          <input v-model="aging.enabled" type="checkbox" class="rounded border-neutral-300" data-testid="saldo-pdf-aging" />
          <span>{{ t('accounting.saldo.pdf_aging_label') }}</span>
        </label>
        <template v-if="aging.enabled">
          <label class="inline-flex items-center gap-2">
            <span class="text-xs text-neutral-500 whitespace-nowrap">{{ t('accounting.saldo.pdf_aging_boundaries') }}</span>
            <input v-model="agingText" type="text" inputmode="numeric" data-testid="saldo-pdf-aging-boundaries"
              class="w-36 h-8 px-2 border rounded-md text-sm"
              :class="agingBoundaries ? 'border-neutral-300' : 'border-danger-500'" />
          </label>
          <label class="inline-flex items-center gap-2 cursor-pointer">
            <input v-model="aging.detail" type="checkbox" class="rounded border-neutral-300" />
            <span>{{ t('accounting.saldo.pdf_aging_detail') }}</span>
          </label>
          <span class="text-xs" :class="agingBoundaries ? 'text-neutral-500' : 'text-danger-600'">
            {{ agingBoundaries ? t('accounting.saldo.pdf_aging_hint') : t('accounting.saldo.pdf_aging_invalid') }}
          </span>
        </template>
      </div>
      <div v-if="asOfPeriodNote" class="mt-3 flex flex-wrap items-center gap-2 text-xs px-3 py-2 rounded-md bg-warning-50 text-warning-700 border border-warning-500/30">
        <span v-if="asOfPeriodNote.kind === 'different'">
          {{ t('accounting.saldo.period_mismatch_hint', { fiscal_year: asOfPeriodNote.period.fiscal_year }) }}
        </span>
        <span v-else>{{ t('accounting.saldo.period_missing_hint') }}</span>
        <button v-if="asOfPeriodNote.kind === 'different'" type="button" @click="switchToAsOfPeriod"
          :class="[btnOutline('warning'), 'h-7 px-2 text-xs']">
          {{ t('accounting.saldo.period_mismatch_switch', { fiscal_year: asOfPeriodNote.period.fiscal_year }) }}
        </button>
      </div>
    </div>

    <div v-if="loading" class="text-center text-neutral-500 py-12 text-sm">{{ t('common.loading') }}</div>

    <EmptyState v-else-if="!report || report.accounts.length === 0" boxed accent="neutral" icon="coin" :title="t('accounting.saldo.empty')" />

    <!-- Task #2: plochý seznam dokladů — samostatně za pohledávky a za závazky -->
    <template v-else-if="viewMode === 'flat'">
      <div v-for="s in sections" :key="s.side" class="mb-6 last:mb-0">
        <div class="flex flex-wrap items-baseline gap-x-2 gap-y-0.5 mb-2">
          <h2 class="text-base font-semibold">{{ t(`accounting.saldo.side_${s.side}`) }}</h2>
          <span class="text-xs text-neutral-500">{{ t(`accounting.saldo.side_${s.side}_hint`) }}</span>
        </div>

        <!-- Konfrontace se zůstatkem hlavní knihy patří do OBOU pohledů. Dokud byla
             jen v pohledu podle partnera, četl uživatel seznam dokladů jako úplný,
             i když se na účtu lišil o statisíce (ruční zápisy, nezaúčtované úhrady). -->
        <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden mb-3">
          <div v-for="b in s.blocks" :key="b.account.id" class="border-b border-neutral-100 last:border-b-0">
            <div class="px-4 py-3 grid grid-cols-1 sm:grid-cols-4 gap-3 text-sm">
              <div>
                <div class="text-xs text-neutral-500 uppercase tracking-wide">{{ t('accounting.saldo.col_account') }}</div>
                <div class="font-semibold">{{ accountBlockLabel(b) }}</div>
              </div>
              <div>
                <div class="text-xs text-neutral-500 uppercase tracking-wide">{{ t('accounting.saldo.gl_balance') }}</div>
                <div class="font-mono font-semibold">{{ formatMoney(b.gl_balance) }}</div>
              </div>
              <div>
                <div class="text-xs text-neutral-500 uppercase tracking-wide">{{ t('accounting.saldo.open_items_total') }}</div>
                <div class="font-mono font-semibold">{{ formatMoney(b.open_items_total) }}</div>
              </div>
              <div>
                <div class="text-xs text-neutral-500 uppercase tracking-wide">{{ t('accounting.saldo.difference') }}</div>
                <div class="font-mono font-semibold flex items-center gap-1"
                  :class="b.matches ? 'text-success-600' : 'text-danger-500'">
                  <span>{{ b.matches ? '✓' : '✗' }}</span>
                  <span>{{ formatMoney(b.difference) }}</span>
                </div>
              </div>
            </div>
            <div v-if="!b.matches" class="px-4 py-2 text-xs text-danger-600 bg-danger-50 border-t border-danger-500/20 flex flex-wrap items-center gap-2">
              <span class="flex-1 min-w-[12rem]">{{ t('accounting.saldo.difference_hint') }}</span>
              <button v-if="canResidual" type="button" :class="[btnOutline('primary'), 'h-7 px-2 text-xs whitespace-nowrap']"
                @click="residualFor = { code: b.account.code, name: b.account.name }">
                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
                {{ t('accounting.saldo_settle.residual_open') }}
              </button>
            </div>
            <SaldoAdvanceIssues v-if="b.advance_deduction_issues?.length && ADVANCE_ACCOUNTS[b.account.code]"
              :issues="b.advance_deduction_issues" :doc-type="ADVANCE_ACCOUNTS[b.account.code]" />
          </div>
        </div>

        <EmptyState v-if="s.rows.length === 0" boxed accent="neutral" icon="search" :title="t('accounting.saldo.flat_empty')" />
        <div v-if="canPick && selectedItems.some(r => r.side === s.side)" class="flex flex-wrap items-center gap-2 mb-2 text-sm">
          <span>{{ t('accounting.saldo_settle.selected', { count: selectedItems.length }) }}</span>
          <button v-if="canSettle" type="button" :disabled="!bulkValid" :class="[btnFilled('success'), 'whitespace-nowrap']" @click="openSettle(selectedItems)">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
            {{ t('accounting.saldo_settle.settle_selected') }}
          </button>
          <span v-if="canSettle && !bulkValid" class="text-xs text-neutral-500">{{ t('accounting.saldo_settle.selected_mixed') }}</span>
          <RouterLink v-if="mutualOffsetLink" :to="mutualOffsetLink" :class="[btnOutline('primary'), 'whitespace-nowrap']" data-testid="saldo-mutual-offset">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.swap" /></svg>
            {{ t('accounting.saldo_settle.mutual_offset') }}
          </RouterLink>
          <button type="button" :class="[btnOutline('neutral'), 'whitespace-nowrap']" @click="selectedRows = new Set()">{{ t('accounting.saldo_settle.clear_selection') }}</button>
        </div>
        <div v-if="s.rows.length > 0" class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
          <div class="overflow-x-auto">
            <table class="w-full text-sm">
              <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
                <tr>
                  <th v-if="canPick" class="px-3 py-2 w-8"><span class="sr-only">{{ t('accounting.saldo_settle.col_pick') }}</span></th>
                  <SortableTh :label="t('accounting.saldo.col_account')" sort-key="account_code" :sort="sort" @toggle="toggleSort" />
                  <SortableTh :label="t('accounting.saldo.col_partner')" sort-key="partner_name" :sort="sort" @toggle="toggleSort" />
                  <SortableTh :label="t('accounting.saldo.col_doc')" sort-key="doc_no" :sort="sort" @toggle="toggleSort" />
                  <SortableTh :label="t('accounting.saldo.col_issue')" sort-key="issue_date" :sort="sort" @toggle="toggleSort" />
                  <SortableTh :label="t('accounting.saldo.col_due')" sort-key="due_date" :sort="sort" @toggle="toggleSort" />
                  <SortableTh :label="t('accounting.saldo.col_overdue')" sort-key="days_overdue" :sort="sort" align="right" @toggle="toggleSort" />
                  <SortableTh :label="t('accounting.saldo.col_amount')" sort-key="booked_czk" :sort="sort" align="right" @toggle="toggleSort" />
                  <SortableTh :label="t('accounting.saldo.col_paid')" sort-key="paid_czk" :sort="sort" align="right" @toggle="toggleSort" />
                  <SortableTh :label="t('accounting.saldo.col_remaining')" sort-key="remaining_czk" :sort="sort" align="right" @toggle="toggleSort" />
                  <th v-if="canSettle" class="px-3 py-2"><span class="sr-only">{{ t('accounting.saldo_settle.col_actions') }}</span></th>
                </tr>
              </thead>
              <tbody class="divide-y divide-neutral-100">
                <tr v-for="it in s.visible" :key="`${it.account_code}-${it.doc_type}-${it.doc_id}`" class="hover:bg-neutral-50">
                  <td v-if="canPick" class="px-3 py-2">
                    <input v-if="pickable(it)" type="checkbox" class="rounded border-neutral-300"
                      :checked="selectedRows.has(rowKey(it))" :aria-label="t('accounting.saldo_settle.select_aria', { doc: it.doc_no })" @change="toggleRow(it)" />
                  </td>
                  <td class="px-3 py-2 font-mono text-xs whitespace-nowrap">{{ it.account_code }}</td>
                  <td class="px-3 py-2">{{ it.partner_name }}</td>
                  <td class="px-3 py-2">
                    <RouterLink :to="docLink(it)" class="text-primary-600 hover:text-primary-700 hover:underline font-mono">
                      {{ it.doc_no }}
                    </RouterLink>
                    <span v-if="it.other_doc_no" class="ml-1 font-mono text-xs text-neutral-500 whitespace-nowrap" data-testid="saldo-other-doc-no">· {{ it.other_doc_no }}</span>
                    <SaldoAdvanceInfo v-if="it.kind === 'advance_pending'" :item="it" :side="it.side" />
                  </td>
                  <td class="px-3 py-2 whitespace-nowrap">{{ formatDate(it.issue_date) }}</td>
                  <td class="px-3 py-2 whitespace-nowrap">{{ formatDate(it.due_date) }}</td>
                  <td class="px-3 py-2 text-right" :class="it.days_overdue > 0 ? 'text-danger-500 font-medium' : 'text-neutral-400'">
                    {{ it.days_overdue > 0 ? it.days_overdue : '—' }}
                  </td>
                  <td class="px-3 py-2 text-right font-mono">
                    {{ formatMoney(amountOf(it)) }}
                    <span v-if="it.currency_code !== 'CZK' && it.kind !== 'advance_pending'" class="block text-xs text-neutral-400">
                      {{ formatMoney(it.amount_foreign) }} {{ it.currency_code }}
                    </span>
                  </td>
                  <td class="px-3 py-2 text-right font-mono" :title="paidTitle(it)">
                    {{ formatMoney(paidOf(it)) }}
                    <span v-if="it.kind === 'advance_pending' && paidOf(it) !== 0" class="block text-xs text-neutral-400 font-sans">
                      {{ t('accounting.saldo.advance_vat_hint') }}
                    </span>
                  </td>
                  <td class="px-3 py-2 text-right font-mono">{{ formatMoney(it.remaining_czk) }}</td>
                  <td v-if="canSettle" class="px-3 py-2 text-right">
                    <button v-if="canSettleItem(it, it.account_code)" type="button" :class="[btnOutlineSm('success'), 'whitespace-nowrap']"
                      :aria-label="t('accounting.saldo_settle.settle_aria', { doc: it.doc_no })" @click="openSettle([it])">
                      <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
                      {{ t('accounting.saldo_settle.settle') }}
                    </button>
                  </td>
                </tr>
              </tbody>
              <tfoot class="bg-neutral-50 text-sm font-semibold">
                <tr v-if="s.hidden > 0" class="font-normal">
                  <td class="px-3 py-2 text-center" :colspan="9 + (canPick ? 1 : 0) + (canSettle ? 1 : 0)">
                    <button type="button" :class="btnOutlineSm" @click="showMore(s.side, s.hidden)">
                      {{ t('accounting.saldo.show_more', { count: Math.min(s.hidden, ROW_CHUNK) }) }}
                    </button>
                    <span class="ml-3 text-xs text-neutral-500">
                      {{ t('accounting.saldo.shown_of', { shown: s.visible.length, total: s.rows.length }) }}
                    </span>
                  </td>
                </tr>
                <tr>
                  <td class="px-3 py-2" :colspan="canPick ? 9 : 8">{{ t(`accounting.saldo.side_${s.side}_total`) }}</td>
                  <td class="px-3 py-2 text-right font-mono">{{ formatMoney(s.total) }}</td>
                  <td v-if="canSettle"></td>
                </tr>
              </tfoot>
            </table>
          </div>
        </div>
      </div>
    </template>

    <!-- Task #2: pohled "podle partnera" — účty seskupené po stranách salda -->
    <div v-else-if="viewMode === 'partner'" class="space-y-8">
      <div v-for="s in sections" :key="s.side" class="space-y-3">
        <div class="flex flex-wrap items-baseline gap-x-2 gap-y-0.5">
          <h2 class="text-base font-semibold">{{ t(`accounting.saldo.side_${s.side}`) }}</h2>
          <span class="text-xs text-neutral-500">{{ t(`accounting.saldo.side_${s.side}_hint`) }}</span>
        </div>
        <div v-for="b in s.blocks" :key="b.account.id"
          class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
        <div class="px-4 py-3 border-b border-neutral-200">
          <h3 class="text-sm font-semibold">{{ accountBlockLabel(b) }}</h3>
        </div>

        <!-- Konfrontace -->
        <div class="px-4 py-3 grid grid-cols-1 sm:grid-cols-3 gap-3 text-sm border-b border-neutral-100 bg-neutral-50">
          <div>
            <div class="text-xs text-neutral-500 uppercase tracking-wide">{{ t('accounting.saldo.gl_balance') }}</div>
            <div class="font-mono font-semibold">{{ formatMoney(b.gl_balance) }}</div>
          </div>
          <div>
            <div class="text-xs text-neutral-500 uppercase tracking-wide">{{ t('accounting.saldo.open_items_total') }}</div>
            <div class="font-mono font-semibold">{{ formatMoney(b.open_items_total) }}</div>
          </div>
          <div>
            <div class="text-xs text-neutral-500 uppercase tracking-wide">{{ t('accounting.saldo.difference') }}</div>
            <div class="font-mono font-semibold flex items-center gap-1"
              :class="b.matches ? 'text-success-600' : 'text-danger-500'">
              <span>{{ b.matches ? '✓' : '✗' }}</span>
              <span>{{ formatMoney(b.difference) }}</span>
            </div>
          </div>
        </div>

        <div v-if="!b.matches" class="px-4 py-2 text-xs text-danger-600 bg-danger-50 border-b border-danger-500/20 flex flex-wrap items-center gap-2">
          <span class="flex-1 min-w-[12rem]">{{ t('accounting.saldo.difference_hint') }}</span>
          <button v-if="canResidual" type="button" :class="[btnOutline('primary'), 'h-7 px-2 text-xs whitespace-nowrap']"
            @click="residualFor = { code: b.account.code, name: b.account.name }">
            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
            {{ t('accounting.saldo_settle.residual_open') }}
          </button>
        </div>
        <SaldoAdvanceIssues v-if="b.advance_deduction_issues?.length && ADVANCE_ACCOUNTS[b.account.code]"
          :issues="b.advance_deduction_issues" :doc-type="ADVANCE_ACCOUNTS[b.account.code]" />

        <EmptyState v-if="b.partners.length === 0" dense accent="success" icon="checkCircle" :title="t('accounting.saldo.no_open_items')" />

        <div v-else class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
              <tr>
                <th class="px-3 py-2 text-left font-medium w-8"></th>
                <th class="px-3 py-2 text-left font-medium">{{ t('accounting.saldo.col_partner') }}</th>
                <th class="px-3 py-2 text-left font-medium">{{ t('accounting.saldo.col_doc') }}</th>
                <th class="px-3 py-2 text-left font-medium">{{ t('accounting.saldo.col_issue') }}</th>
                <th class="px-3 py-2 text-left font-medium">{{ t('accounting.saldo.col_due') }}</th>
                <th class="px-3 py-2 text-right font-medium">{{ t('accounting.saldo.col_overdue') }}</th>
                <th class="px-3 py-2 text-right font-medium">{{ t('accounting.saldo.col_amount') }}</th>
                <th class="px-3 py-2 text-right font-medium">{{ t('accounting.saldo.col_paid') }}</th>
                <th class="px-3 py-2 text-right font-medium">{{ t('accounting.saldo.col_remaining') }}</th>
                <th v-if="canSettle" class="px-3 py-2"><span class="sr-only">{{ t('accounting.saldo_settle.col_actions') }}</span></th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <template v-for="p in b.partners" :key="p.partner_id">
                <tr class="cursor-pointer hover:bg-neutral-50 font-medium bg-neutral-50/50"
                  @click="toggle(b.account.code, p.partner_id)">
                  <td class="px-3 py-2">
                    <span class="inline-block transition-transform" :class="{ 'rotate-90': expanded[partnerKey(b.account.code, p.partner_id)] }">▸</span>
                  </td>
                  <td class="px-3 py-2" colspan="7">{{ p.partner_name }}</td>
                  <td class="px-3 py-2 text-right font-mono">{{ formatMoney(p.total_remaining) }}</td>
                  <td v-if="canSettle"></td>
                </tr>
                <template v-if="expanded[partnerKey(b.account.code, p.partner_id)]">
                  <tr v-for="it in p.items" :key="`${it.doc_type}-${it.doc_id}`" class="hover:bg-neutral-50">
                    <td class="px-3 py-2"></td>
                    <td class="px-3 py-2"></td>
                    <td class="px-3 py-2">
                      <RouterLink :to="docLink(it)" class="text-primary-600 hover:text-primary-700 hover:underline font-mono">
                        {{ it.doc_no }}
                      </RouterLink>
                      <span v-if="it.other_doc_no" class="ml-1 font-mono text-xs text-neutral-500 whitespace-nowrap" data-testid="saldo-other-doc-no">· {{ it.other_doc_no }}</span>
                      <SaldoAdvanceInfo v-if="it.kind === 'advance_pending'" :item="it" :side="sideOfAccount(b.account.normal_side)" />
                    </td>
                    <td class="px-3 py-2 whitespace-nowrap">{{ formatDate(it.issue_date) }}</td>
                    <td class="px-3 py-2 whitespace-nowrap">{{ formatDate(it.due_date) }}</td>
                    <td class="px-3 py-2 text-right" :class="it.days_overdue > 0 ? 'text-danger-500 font-medium' : 'text-neutral-400'">
                      {{ it.days_overdue > 0 ? it.days_overdue : '—' }}
                    </td>
                    <td class="px-3 py-2 text-right font-mono">
                      {{ formatMoney(amountOf(it)) }}
                      <span v-if="it.currency_code !== 'CZK' && it.kind !== 'advance_pending'" class="block text-xs text-neutral-400">
                        {{ formatMoney(it.amount_foreign) }} {{ it.currency_code }}
                      </span>
                    </td>
                    <td class="px-3 py-2 text-right font-mono" :title="paidTitle(it)">
                      {{ formatMoney(paidOf(it)) }}
                      <span v-if="it.kind === 'advance_pending' && paidOf(it) !== 0" class="block text-xs text-neutral-400 font-sans">
                        {{ t('accounting.saldo.advance_vat_hint') }}
                      </span>
                    </td>
                    <td class="px-3 py-2 text-right font-mono">{{ formatMoney(it.remaining_czk) }}</td>
                    <td v-if="canSettle" class="px-3 py-2 text-right">
                      <button v-if="canSettleItem(it, b.account.code)" type="button" :class="[btnOutlineSm('success'), 'whitespace-nowrap']"
                        :aria-label="t('accounting.saldo_settle.settle_aria', { doc: it.doc_no })" @click="openSettlePartner(it, b, p.partner_id, p.partner_name)">
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
                        {{ t('accounting.saldo_settle.settle') }}
                      </button>
                    </td>
                  </tr>
                </template>
              </template>
            </tbody>
          </table>
        </div>
        </div>
      </div>
    </div>

    <SaldoAdvanceSettleDialog v-if="settleTargets && report && settleAdvance" :items="settleTargets" :as-of="report.as_of"
      @close="settleTargets = null" @done="onSettleDone" />
    <SaldoSettleDialog v-else-if="settleTargets && report" :items="settleTargets" :as-of="report.as_of"
      @close="settleTargets = null" @done="onSettleDone" />
    <SaldoResidualDialog v-if="residualFor && report" :account-code="residualFor.code" :account-name="residualFor.name" :as-of="report.as_of"
      @close="residualFor = null" @done="onSettleDone" />
    <SaldoSuggestionsDialog v-if="suggestionsOpen && report" :period-id="Number(filters.period_id)" :as-of="report.as_of" :account="suggestAccount"
      @close="suggestionsOpen = false" @done="onSettleDone" />
  </div>
</template>

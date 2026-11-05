<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { personalNumberLabel } from './employmentLifecycleUi'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import {
  payrollApi,
  type PayrollQuickInputFailure,
  type PayrollQuickInputRef,
  type PayrollQuickInputRow,
  type PayrollQuickInputSavePayload,
  type PayrollQuickSurchargeKind,
  type PayrollQuickSurchargeState,
  type PayrollQuickComponentCell,
  type PayrollQuickComponentColumn,
  type PayrollQuickInputTotals,
  type PayrollRecurringMaterialization,
} from '@/api/payroll'
import { apiErrorMessage } from '@/api/errors'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { btnFilled, btnOutline, disabledTitle, BTN_DISABLED_NOTE, ICONS } from '@/components/ui/buttonStyles'
import EmptyState from '@/components/ui/EmptyState.vue'
import PaginationBar from '@/components/ui/PaginationBar.vue'
import PayrollFocusNotice from '@/components/payroll/PayrollFocusNotice.vue'
import PayrollPeriodScopePicker from '@/components/payroll/PayrollPeriodScopePicker.vue'
import PayrollInputHistory from '@/components/payroll/PayrollInputHistory.vue'
import {
  payrollPeriodRange,
  payrollPeriodScopeFromQuery,
  payrollPeriodScopeToQuery,
  type PayrollPeriodScope,
} from '@/pages/payroll/payrollPeriodScope'
import PayrollQuickFieldState from '@/components/payroll/PayrollQuickFieldState.vue'
import PayrollQuickColumnMenu from '@/components/payroll/PayrollQuickColumnMenu.vue'
import type { PayrollQuickColumnGroup } from '@/components/payroll/payrollQuickColumns'
import { payrollQueryId } from '@/pages/payroll/payrollAgendaLinks'
import ColumnPicker from '@/components/ui/ColumnPicker.vue'
import DensityToggle from '@/components/ui/DensityToggle.vue'
import { useTablePrefs, type ColumnDef } from '@/composables/useTablePrefs'
import {
  parsePayrollAmountToMinor,
  parsePayrollHoursToMilli,
  payrollInputEditable,
  payrollMinorToInput,
  payrollQueryPeriod,
} from '@/pages/payroll/payrollComponentsUi'
// Formátování je sdílené (useFormat) — místní kopie se rozcházely v locale i tvaru.
import { formatMoneyMinor, formatPeriod } from '@/composables/useFormat'

interface UiRow extends PayrollQuickInputRow {
  baseAmount: string
  overtimeHours: string
  overtimeAmount: string
  bonusAmount: string
  /** Rozepsané hodiny a počty vlivů příplatků, klíč = druh. */
  surchargeHours: Record<PayrollQuickSurchargeKind, string>
  surchargeFactors: Record<PayrollQuickSurchargeKind, string>
  /** Rozepsané částky buněk sloupců mzdových složek, klíč = kód složky. */
  componentAmounts: Record<string, string>
  /** Buňky, na které uživatel sáhl — jen ty odcházejí na server. */
  componentDirty: Record<string, boolean>
  /** Buňky, u kterých uživatel požádal o návrat na hodnotu z importu. */
  componentRevert: Record<string, boolean>
}

/**
 * Pořadí sloupců příplatků. Věcné, ne podle čísla paragrafu: noční a víkend
 * vyplňuje směnný provoz každý měsíc, svátek přijde párkrát do roka a ztížené
 * prostředí se týká menšiny pracovišť. Musí odpovídat serveru
 * (`PayrollSurchargeKind::quickManualEntry()`).
 */
const SURCHARGE_KINDS: PayrollQuickSurchargeKind[] = [
  'night',
  'weekend',
  'holiday',
  'difficult_environment',
]

/**
 * Přepínač příplatkových sloupců si pamatujeme na uživateli.
 *
 * Směnný provoz ho má trvale zapnutý, běžná kancelář ho nikdy neuvidí. Kdyby se
 * stav neukládal, mzdová účetní by ho odklikávala každý měsíc znovu — a to je
 * přesně ten druh drobného tření, kvůli kterému se pak příplatky „radši" zadají
 * jako volná odměna a zákonný rozpad se ztratí.
 *
 * Stav se ukládá do preferencí tabulky (`table.payroll-quick-inputs`, příznak
 * `surcharges`), stejně jako volba sloupců složek. Samostatný klíč
 * `payroll.quick_inputs.surcharges` server nikdy nepřijal — není mezi
 * povolenými klíči preferencí — takže se přepínač ve skutečnosti nepamatoval.
 */
const SURCHARGE_FLAG = 'surcharges'

type ValidationCode =
  | 'amount_required'
  | 'amount_format'
  | 'amount_non_negative'
  | 'amount_limit'
  | 'hours_required'
  | 'hours_format'
  | 'hours_non_negative'
  | 'hours_limit'
  | 'surcharge_hours_limit'
  | 'factors_required'
  | 'factors_range'

const MAX_AMOUNT_MINOR = 1_000_000_000_000
const MAX_OVERTIME_HOURS_MILLI = 1_000_000
/**
 * Strop hodin jednoho příplatku za měsíc — 744 h je nejdelší kalendářní měsíc.
 * Shodný s `PayrollQuickSurchargeCalculator::MAX_HOURS_MILLI`; větší číslo je
 * vždycky překlep, ne směna.
 */
const MAX_SURCHARGE_HOURS_MILLI = 744_000
const { t, locale } = useI18n()
const auth = useAuthStore()
const toast = useToast()
const route = useRoute()
const router = useRouter()
const period = ref(payrollQueryPeriod(route.query))
const loading = ref(false)
/*
 * Selhalo načtení? Pak o obsahu nevíme NIC — a to je něco jiného než „nic tu
 * není". Toast s chybou za pár vteřin zmizí a bez tohohle příznaku by na
 * obrazovce zůstal prázdný stav, který lže.
 */
const loadFailed = ref(false)
const saving = ref(false)
const rows = ref<UiRow[]>([])
const loadedPeriod = ref<string | null>(null)
const saveError = ref<string | null>(null)
const saveConflict = ref(false)
let loadGeneration = 0

/*
 * Rozepsané řádky napříč stránkami.
 *
 * `rows` drží jen zobrazenou stránku, takže „Uložit vše" ukládalo dvacetinu
 * práce a u 500 lidí to bylo dvacet uložení a dvacet šancí na konflikt verzí.
 * Editované řádky proto přežívají přelistování tady a odcházejí spolu s tou
 * stránkou, na které uživatel právě stojí.
 *
 * Posílají se JEN skutečně změněné řádky (plus aktuální stránka) — nasypat
 * serveru všech 500 vztahů při každém uložení by z toho udělalo jiný problém.
 */
const pending = ref(new Map<number, UiRow>())
/** Co server odmítl uložit, klíč `employment_id:pole`. */
const fieldErrors = ref<Record<string, string>>({})
/** Sloupce mzdových složek, které server v měsíci nabízí, a součty za celé období. */
const componentColumns = ref<PayrollQuickComponentColumn[]>([])
const periodTotals = ref<PayrollQuickInputTotals | null>(null)
/*
 * Měsíc před prvním mzdovým obdobím firmy.
 *
 * Po převodu mezd z předchozího programu se tu dá listovat i do měsíců, které
 * MyÚčto vůbec nepočítá. Rychlé zadání je tam k ničemu, ale zakázat ho nemá
 * smysl — stačí, aby to uživatel věděl dřív, než začne vyplňovat.
 */
const historicalMonth = ref(false)
const payrollStartPeriod = ref<string | null>(null)
const columnsMenuOpen = ref(false)
/** Strop jedné dávky na serveru (PayrollQuickInputValidator). */
const SAVE_CHUNK = 500

const COLUMNS: ColumnDef[] = [
  { key: 'person', labelKey: 'payroll.quick_inputs.person', required: true },
  { key: 'income_amount', labelKey: 'payroll.quick_inputs.income_amount', required: true },
  { key: 'overtime', labelKey: 'payroll.quick_inputs.overtime' },
  { key: 'bonus_amount', labelKey: 'payroll.quick_inputs.bonus_amount' },
  { key: 'gross_preview', labelKey: 'payroll.quick_inputs.gross_preview' },
]
const tbl = useTablePrefs('payroll-quick-inputs', COLUMNS)

const pageSize = 25
const total = ref(0)
const offset = ref(0)
const currentPage = computed(() => Math.floor(offset.value / pageSize) + 1)

/**
 * Zúžení na jeden vztah z odkazu na kartě zaměstnance (`?employment=12`).
 *
 * Zužuje SERVER (`quick-inputs?employment_id=`), a to do téhož dotazu jako
 * stránkování — dokud filtroval prohlížeč nad načtenou stránkou, vztah z jiné
 * strany se tiše neprojevil. Ukládání dostává zúžení taky: `payload()` posílá
 * právě to, co je v `rows`, takže nezúžená odpověď by po uložení nasypala do
 * formuláře lidi, které uživatel nevidí.
 */
const focusEmploymentId = ref<number | null>(payrollQueryId(route.query, 'employment'))

/*
 * Rozsah období — jen při zúžení na jeden vztah.
 *
 * Mřížka je ze své podstaty MĚSÍČNÍ: ukládá se `period` + řádky a server na ni
 * váže zámek měsíce i kontroly. Rozsah ji proto nerozšiřuje, ale VYSTŘÍDÁ
 * historií, která je ke čtení. Kdo přijde z karty zaměstnance, se totiž
 * neptá „co zadám za srpen", ale „co tenhle člověk bral" — a na to měsíční
 * mřížka odpovědět neumí, ani kdyby se v ní listovalo měsíc po měsíci.
 */
const scope = ref<PayrollPeriodScope>(
  payrollQueryId(route.query, 'employment') === null
    ? 'month'
    : payrollPeriodScopeFromQuery(route.query),
)
const scopeRange = computed(() => payrollPeriodRange(scope.value, period.value))
const historyMode = computed(() =>
  focusEmploymentId.value !== null && scopeRange.value !== null)

function changeScope(next: PayrollPeriodScope): void {
  if (scope.value === next) return
  scope.value = next
  void router.replace({ query: payrollPeriodScopeToQuery(route.query, next) })
}

/** Proklik z historie zpět do editovatelného měsíce. */
function openHistoryPeriod(next: string): void {
  period.value = next
  scope.value = 'month'
  void router.replace({
    query: { ...payrollPeriodScopeToQuery(route.query, 'month'), period: next },
  })
  offset.value = 0
  void load()
}

/*
 * Lišta se zúžením musí být vidět i tehdy, když zúžení nedalo nic. Bez ní zůstane
 * prázdná tabulka a uživatel nemá jak poznat, že se dívá na zúžený seznam — ani
 * jak se ze zúžení dostat.
 *
 * Slepé zúžení jméno neunese: zužuje se na VZTAH a jeho jméno chodí jen se
 * řádky, které tentokrát nepřišly. Dotáhnout ho zvlášť by stálo dva požadavky
 * (vztah → osoba), takže lišta radši mluví obecně, než aby ukazovala id.
 */
const focusName = computed(() => {
  if (focusEmploymentId.value === null) return null
  return rows.value.length > 0
    ? rows.value[0].full_name
    : t('payroll.agendas.focus.unknown_person')
})
/**
 * Server zúžení uplatnil a nezbylo nic — prázdno se musí pojmenovat, ne mlčet.
 *
 * V historii se ale mlčí schválně: ta se ptá na rozsah, ne na vybraný měsíc,
 * a „v tomhle měsíci nic není" by pod tabulkou plnou měsíců znělo jako chyba.
 */
const focusMissing = computed(() =>
  focusEmploymentId.value !== null && !loading.value && !loadFailed.value
  && !historyMode.value
  && loadedPeriod.value === period.value && rows.value.length === 0)

function clearFocus(): void {
  focusEmploymentId.value = null
  // Historie bez zúžení nedává smysl — byla by to historie celé firmy.
  scope.value = 'month'
  const query = payrollPeriodScopeToQuery(route.query, 'month')
  delete query.employment
  void router.replace({ query })
  reload()
}

/*
 * Hledání podle jména nebo osobního čísla.
 *
 * Stránka je stránkovaná SERVEREM, takže hledat v načtených 25 řádcích by
 * našlo jen toho, kdo je náhodou na zobrazené stránce. Hledá proto server,
 * v celém měsíci a se stránkováním i počtem za výsledek. Rozepsané změny
 * hledání neruší: `pending` drží řádky napříč stránkami i výsledky hledání
 * a uložení je pošle, i když je aktuální hledání nevrací.
 */
const SEARCH_DEBOUNCE_MS = 300
const initialSearch = typeof route.query.q === 'string' ? route.query.q.trim() : ''
const searchQuery = ref(initialSearch)
const appliedSearch = ref(initialSearch)
let searchTimer: ReturnType<typeof setTimeout> | null = null

function cancelSearchTimer(): void {
  if (searchTimer !== null) {
    clearTimeout(searchTimer)
    searchTimer = null
  }
}

function onSearchInput(): void {
  cancelSearchTimer()
  searchTimer = setTimeout(applySearch, SEARCH_DEBOUNCE_MS)
}

function applySearch(): void {
  cancelSearchTimer()
  const next = searchQuery.value.trim()
  if (next === appliedSearch.value) return
  appliedSearch.value = next
  const query = { ...route.query }
  if (next === '') delete query.q
  else query.q = next
  void router.replace({ query })
  reload()
}

function clearSearch(): void {
  searchQuery.value = ''
  applySearch()
}

onBeforeUnmount(cancelSearchTimer)

function goToPage(nextPage: number): void {
  offset.value = Math.max(0, (nextPage - 1) * pageSize)
  void load()
}

/** Změna období mění obsah seznamu, takže stránka musí zpět na začátek. */
function reload(): void {
  offset.value = 0
  void load()
}

/*
 * Jiný měsíc = jiná data. Rozepsané řádky se zahazují spolu s ním, jinak by se
 * do nového období přelilo, co uživatel psal do starého.
 */
function changePeriod(): void {
  pending.value.clear()
  fieldErrors.value = {}
  reload()
}

const canWrite = computed(() => auth.canWrite('payroll.inputs.write'))
/*
 * Právo schvalovat mění to, co uložení znamená: server takovému uživateli
 * uloží vstup rovnou jako schválený, takže mzdový běh nedrží blokátor
 * `draft_inputs_present` a schvalovat na jiné obrazovce se nemusí nic.
 */
const canApprove = computed(() => auth.canWrite('payroll.approve'))
/*
 * Konflikt s jiným vstupem NEBLOKUJE uložení celé stránky.
 *
 * Jeden zaměstnanec s duplicitně zadaným základem dřív zamkl tlačítko a s ním
 * i dalších 24 vyplněných řádků. Server dnes ukládá po polích a vrací, co
 * neuložil a proč — vadné pole se obarví, zbytek se uloží.
 */
const conflictRowCount = computed(() => rows.value.filter(row =>
  row.base_conflict || row.overtime_conflict || row.bonus_conflict).length)

function fieldErrorKey(employmentId: number, field: string): string {
  return `${employmentId}:${field}`
}

function serverError(row: UiRow, field: string): string | null {
  return fieldErrors.value[fieldErrorKey(row.employment_id, field)] ?? null
}

/** Zapamatuje si rozepsaný řádek, ať přežije přelistování na jinou stránku. */
function markDirty(row: UiRow): void {
  pending.value.set(row.employment_id, row)
  clearSaveError()
  for (const field of [
    'row',
    'base',
    'overtime',
    'bonus',
    ...SURCHARGE_KINDS.map(kind => `surcharge_${kind}`),
  ]) {
    delete fieldErrors.value[fieldErrorKey(row.employment_id, field)]
  }
  for (const code of Object.keys(row.componentAmounts)) {
    delete fieldErrors.value[fieldErrorKey(row.employment_id, `component:${code}`)]
  }
}

/** Rozepsané řádky mimo právě zobrazenou stránku. */
const pendingElsewhere = computed(() => {
  const onPage = new Set(rows.value.map(row => row.employment_id))
  return Array.from(pending.value.values()).filter(row => !onPage.has(row.employment_id))
})

function emptyByKind(): Record<PayrollQuickSurchargeKind, string> {
  return { night: '', weekend: '', holiday: '', difficult_environment: '' }
}

function toUi(row: PayrollQuickInputRow): UiRow {
  const surchargeHours = emptyByKind()
  const surchargeFactors = emptyByKind()
  for (const kind of SURCHARGE_KINDS) {
    const state = row.surcharges?.[kind]
    surchargeHours[kind] = state?.hours_milli == null ? '' : String(state.hours_milli / 1000)
    surchargeFactors[kind] = state?.factors == null ? '' : String(state.factors)
  }
  return {
    ...row,
    overtime_mode: row.overtime_hours_relation_supported ? row.overtime_mode : 'amount',
    baseAmount: row.base_requires_entry ? '' : payrollMinorToInput(row.base_amount_minor),
    overtimeHours: row.overtime_hours_milli === null
      ? ''
      : String(row.overtime_hours_milli / 1000),
    overtimeAmount: payrollMinorToInput(row.overtime_amount_minor),
    bonusAmount: payrollMinorToInput(row.bonus_amount_minor),
    surchargeHours,
    surchargeFactors,
    componentAmounts: Object.fromEntries(Object.entries(row.components ?? {})
      .map(([code, cell]) => [code, payrollMinorToInput(cell.amount_minor)])),
    componentDirty: {},
    componentRevert: {},
  }
}

function surchargeState(row: UiRow, kind: PayrollQuickSurchargeKind): PayrollQuickSurchargeState | null {
  return row.surcharges?.[kind] ?? null
}

/** Editovatelné je pole jen tehdy, když to dovolí SERVER. Neodvozujeme to tu. */
/** Mzda vztahu za měsíc je uzavřená: řádek je jen ke čtení a neposílá se. */
function rowClosed(row: PayrollQuickInputRow): boolean {
  return row.closed_run != null
}

function surchargeEditable(row: UiRow, kind: PayrollQuickSurchargeKind): boolean {
  if (rowClosed(row)) return false
  const state = surchargeState(row, kind)
  if (state === null || !state.entry_available) return false
  return state.status === null || state.status === 'draft'
    || (state.status === 'approved' && canApprove.value)
}

function surchargeHoursError(row: UiRow, kind: PayrollQuickSurchargeKind): ValidationCode | null {
  if (!surchargeEditable(row, kind)) return null
  const value = row.surchargeHours[kind].trim()
  // Prázdné pole je legitimní stav („tenhle příplatek neřeším"), ne chyba.
  if (value === '') return null
  if (value.startsWith('-')) return 'hours_non_negative'
  const parsed = parsePayrollHoursToMilli(value)
  if (parsed === null) return 'hours_format'
  if (parsed > MAX_SURCHARGE_HOURS_MILLI) return 'surcharge_hours_limit'
  return null
}

function surchargeFactorsError(row: UiRow, kind: PayrollQuickSurchargeKind): ValidationCode | null {
  const state = surchargeState(row, kind)
  if (state === null || !state.requires_factors || !surchargeEditable(row, kind)) return null
  const hours = row.surchargeHours[kind].trim()
  if (hours === '' || hours === '0') return null
  const value = row.surchargeFactors[kind].trim()
  // § 117 přiznává příplatek ZA KAŽDÝ ztěžující vliv, takže bez jejich počtu
  // není co počítat. Odhadnout jedničku by byl tichý nedoplatek.
  if (value === '') return 'factors_required'
  const parsed = Number(value)
  if (!Number.isInteger(parsed) || parsed < 1 || parsed > 255) return 'factors_range'
  return null
}

/**
 * Dopočtená částka vedle pole — účetní musí vidět, co z hodin vyjde, ještě než
 * uloží. Počítá se TÝMŽ zlomkem jako server (jeden podíl, žádné mezikroky):
 * `základ × sazba × milihodiny × vlivy / (10 000 × 1 000)`.
 */
function surchargePreview(row: UiRow, kind: PayrollQuickSurchargeKind): number {
  const state = surchargeState(row, kind)
  if (state === null) return 0
  const hours = parsePayrollHoursToMilli(row.surchargeHours[kind].trim())
  if (hours === null || hours <= 0 || hours > MAX_SURCHARGE_HOURS_MILLI) {
    return state.hours_milli === null ? 0 : state.amount_minor
  }
  // Beze změny hodin i vlivů ukazujeme ULOŽENOU částku, ne přepočet: server ji
  // taky nepřepočítává, aby se už zadaná mzda neměnila podle toho, co se
  // mezitím stalo s průměrným výdělkem.
  const factors = state.requires_factors
    ? Number(row.surchargeFactors[kind].trim() || state.default_factors || 0)
    : 1
  if (state.hours_milli === hours
    && (!state.requires_factors || state.factors === factors)) {
    return state.amount_minor
  }
  if (!state.basis_hourly_minor || !state.rate_basis_points || factors < 1) return 0
  return Math.round(
    (state.basis_hourly_minor * state.rate_basis_points * hours * factors) / 10_000_000,
  )
}

function surchargeTotal(row: UiRow): number {
  return SURCHARGE_KINDS.reduce(
    (sum, kind) => sum + surchargePreview(row, kind)
      + (surchargeState(row, kind)?.managed_amount_minor ?? 0),
    0,
  )
}

/*
 * ── Sloupce mzdových složek ─────────────────────────────────────────────────
 *
 * Co s buňkou jde udělat (`mode`, `entry_available`), rozhoduje server. Prohlížeč
 * přidává jen to, co server předem neví: jestli přihlášený smí měnit schválený
 * vstup. Buňka z importu se upravuje RUČNÍM PŘEPISEM — importní hodnota zůstává
 * jako doklad a lze se k ní vrátit.
 */
function componentCell(row: UiRow, code: string): PayrollQuickComponentCell | null {
  return row.components?.[code] ?? null
}

function componentEditable(row: UiRow, column: PayrollQuickComponentColumn): boolean {
  if (!canWrite.value || rowClosed(row)) return false
  const cell = componentCell(row, column.code)
  if (cell === null) return column.editable_in_quick
  if (!cell.entry_available) return false
  return payrollInputEditable(cell.status, canApprove.value)
}

function componentServerAmount(row: UiRow, code: string): number {
  return componentCell(row, code)?.amount_minor ?? 0
}

type ComponentValidationCode = ValidationCode | 'component_amount_required'

function componentError(row: UiRow, column: PayrollQuickComponentColumn): ComponentValidationCode | null {
  const code = column.code
  if (!row.componentDirty[code] || !componentEditable(row, column)) return null
  const value = (row.componentAmounts[code] ?? '').trim()
  if (value === '') {
    // Importovanou hodnotu prázdné pole nesmaže — nula je výslovná a návrat
    // k importu má vlastní tlačítko.
    const mode = componentCell(row, code)?.mode
    return mode === 'import' || mode === 'override' ? 'component_amount_required' : null
  }
  const parsed = parsedAmount(value)
  if (parsed === null) return 'amount_format'
  if (parsed < 0) return 'amount_non_negative'
  if (parsed > MAX_AMOUNT_MINOR) return 'amount_limit'
  return null
}

function componentErrorMessage(code: ComponentValidationCode | null): string {
  if (code === 'component_amount_required') {
    return t('payroll.quick_inputs.component_validation.amount_required')
  }
  return validationMessage(code)
}

function componentTouchedCodes(row: UiRow): string[] {
  return [...new Set([
    ...Object.keys(row.componentDirty).filter(code => row.componentDirty[code]),
    ...Object.keys(row.componentRevert).filter(code => row.componentRevert[code]),
  ])]
}

/** Částka, se kterou buňka po uložení počítá (rozepsaná, vrácená, nebo uložená). */
function componentPreview(row: UiRow, code: string): number {
  const cell = componentCell(row, code)
  if (row.componentRevert[code] && cell?.override_of) return cell.override_of.amount_minor
  if (!row.componentDirty[code]) return componentServerAmount(row, code)
  return validAmount(row.componentAmounts[code] ?? '')
}

const componentColumnByCode = computed(() =>
  new Map(componentColumns.value.map(column => [column.code, column])))

/**
 * O kolik rozepsané buňky řádku posouvají hrubý náhled. Hodnota složky už je
 * v uloženém náhledu (v poli nebo v „dalších vstupech"), takže se přičítá jen
 * rozdíl proti uložené částce — a jen u složky, kterou hrubý náhled sčítá.
 */
function componentGrossDelta(row: UiRow): number {
  let delta = 0
  for (const code of componentTouchedCodes(row)) {
    if (componentColumnByCode.value.get(code)?.counts_in_gross === false) continue
    delta += componentPreview(row, code) - componentServerAmount(row, code)
  }
  return delta
}

function onComponentInput(row: UiRow, code: string): void {
  row.componentDirty[code] = true
  row.componentRevert[code] = false
  markDirty(row)
}

/** Návrat k importu se provede při uložení, stejně jako každá jiná změna v tabulce. */
function requestRevert(row: UiRow, code: string): void {
  const cell = componentCell(row, code)
  if (!cell?.override_of) return
  row.componentRevert[code] = true
  row.componentDirty[code] = false
  row.componentAmounts[code] = payrollMinorToInput(cell.override_of.amount_minor)
  markDirty(row)
}

function cancelRevert(row: UiRow, code: string): void {
  row.componentRevert[code] = false
  row.componentAmounts[code] = payrollMinorToInput(componentServerAmount(row, code))
  markDirty(row)
}

/** Popisek ikony zdroje: odkud hodnota je a proč s ní jde, nebo nejde, hýbat. */
function componentSourceTitle(row: UiRow, column: PayrollQuickComponentColumn): string {
  const cell = componentCell(row, column.code)
  if (cell === null) {
    return column.editable_in_quick ? '' : t('payroll.quick_inputs.component_cell.not_enterable')
  }
  if (cell.mode === 'override' && cell.override_of) {
    return t('payroll.quick_inputs.component_cell.override_title', {
      import: formatMoney(cell.override_of.amount_minor),
      value: formatMoney(cell.amount_minor),
    })
  }
  if (cell.status === 'locked') return t('payroll.quick_inputs.component_cell.locked')
  if (cell.mode === 'managed') return t('payroll.quick_inputs.component_cell.managed')
  if (cell.status === 'approved' && !canApprove.value) {
    return t('payroll.quick_inputs.component_cell.approved')
  }
  if (cell.mode === 'import') return t('payroll.quick_inputs.component_cell.import_hint')
  return t(`payroll.quick_inputs.component_cell.source.${cell.source}`)
}

/** Import ⇩, ruční přepis ✎, jiný vstup 🔗; vlastní zadání ikonu nepotřebuje. */
function componentSourceIcon(row: UiRow, code: string): string | null {
  const mode = componentCell(row, code)?.mode
  if (mode === 'override') return ICONS.edit
  if (mode === 'import') return ICONS.download
  if (mode === 'managed') return ICONS.link
  return null
}

function componentDisplay(row: UiRow, code: string): string {
  const cell = componentCell(row, code)
  return cell === null ? '—' : formatMoney(cell.amount_minor)
}

function componentRevertable(row: UiRow, column: PayrollQuickComponentColumn): boolean {
  return componentCell(row, column.code)?.mode === 'override'
    && componentEditable(row, column)
    && !row.componentRevert[column.code]
}

/*
 * Příplatky § 115–118 jsou ve výchozím stavu zapnuté (tak to bylo vždy), sloupce
 * složek vypnuté. Volba je per uživatel v preferencích tabulky pod klíči
 * `surcharge:<druh>` a `component:<KÓD>`.
 */
function surchargeColumnKey(kind: PayrollQuickSurchargeKind): string {
  return `surcharge:${kind}`
}

function componentColumnKey(code: string): string {
  return `component:${code}`
}

const selectedSurchargeKinds = computed(() =>
  SURCHARGE_KINDS.filter(kind => tbl.isDynamicShown(surchargeColumnKey(kind), true)))
const selectedComponentColumns = computed(() => componentColumns.value
  .filter(column => tbl.isDynamicShown(componentColumnKey(column.code), false)))
const visibleSurchargeKinds = computed(() => surchargesVisible.value ? selectedSurchargeKinds.value : [])
const visibleComponentColumns = computed(() => surchargesVisible.value ? selectedComponentColumns.value : [])
const selectedColumnKeys = computed(() => [
  ...selectedSurchargeKinds.value.map(surchargeColumnKey),
  ...selectedComponentColumns.value.map(column => componentColumnKey(column.code)),
])

const columnGroups = computed<PayrollQuickColumnGroup[]>(() => {
  const groups: PayrollQuickColumnGroup[] = [{
    key: 'surcharges',
    label: t('payroll.quick_inputs.columns_menu.group_surcharges'),
    options: SURCHARGE_KINDS.map(kind => ({
      key: surchargeColumnKey(kind),
      label: `${t(`payroll.quick_inputs.surcharges.kinds.${kind}`)} ${t(`payroll.quick_inputs.surcharges.sections.${kind}`)}`,
      rowsWithValue: 0,
      summaryOnly: false,
    })),
  }]
  const byKind = new Map<string, PayrollQuickColumnGroup>()
  for (const column of componentColumns.value) {
    let group = byKind.get(column.kind)
    if (!group) {
      group = {
        key: column.kind,
        label: t(`payroll.quick_inputs.component_kinds.${column.kind}`),
        options: [],
      }
      byKind.set(column.kind, group)
      groups.push(group)
    }
    group.options.push({
      key: componentColumnKey(column.code),
      label: column.name,
      code: column.code,
      rowsWithValue: column.rows_with_value,
      summaryOnly: !column.editable_in_quick,
    })
  }
  return groups
})

function onColumnChange(key: string, checked: boolean): void {
  tbl.setDynamicShown({ [key]: checked })
  if (checked && !surchargesVisible.value) setSurchargesVisible(true)
}

/**
 * „Kompaktní" = výchozí stav (jen zákonné příplatky), „Kompletní přehled" =
 * příplatky a každá složka, která má v období hodnotu.
 */
function applyColumnPreset(name: 'compact' | 'full'): void {
  const changes: Record<string, boolean> = {}
  for (const kind of SURCHARGE_KINDS) changes[surchargeColumnKey(kind)] = true
  for (const column of componentColumns.value) {
    changes[componentColumnKey(column.code)] = name === 'full' && column.rows_with_value > 0
  }
  tbl.setDynamicShown(changes)
  if (!surchargesVisible.value) setSurchargesVisible(true)
}

/*
 * Součtový řádek je za CELÉ období (server), posunutý o rozepsané změny ze
 * všech stránek. Kontrolní součet, který by se měnil s listováním, nic
 * nekontroluje.
 */
const totalsView = computed(() => {
  const totals = periodTotals.value
  if (totals === null) return null
  const pendingRows = Array.from(pending.value.values())
  const sumPending = (delta: (row: UiRow) => number): number =>
    pendingRows.reduce((sum, row) => sum + delta(row), 0)
  const surcharges = {} as Record<PayrollQuickSurchargeKind, number>
  for (const kind of SURCHARGE_KINDS) {
    surcharges[kind] = (totals.surcharges[kind] ?? 0)
      + sumPending(row => surchargePreview(row, kind) - (surchargeState(row, kind)?.amount_minor ?? 0))
  }
  const components: Record<string, number> = {}
  for (const column of componentColumns.value) {
    components[column.code] = (totals.components[column.code]?.amount_minor ?? 0)
      + sumPending(row => componentPreview(row, column.code) - componentServerAmount(row, column.code))
  }
  return {
    rows: totals.rows,
    base: totals.base_amount_minor
      + sumPending(row => validAmount(row.baseAmount) - row.base_amount_minor),
    overtime: totals.overtime_amount_minor
      + sumPending(row => overtimePreview(row) - row.overtime_amount_minor),
    bonus: totals.bonus_amount_minor
      + sumPending(row => validAmount(row.bonusAmount) - row.bonus_amount_minor),
    surcharges,
    components,
    gross: totals.gross_preview_minor
      + sumPending(row => grossPreview(row) - row.gross_preview_minor),
  }
})

/**
 * Proč druh příplatku u řádku nejde zadat — klíč do
 * `payroll.quick_inputs.surcharges.unavailable.*`, nebo `null`, když jde.
 *
 * Chybějící stav se čte jako `basis_missing`: server ho posílá u každého řádku,
 * takže jeho nepřítomnost znamená „podklad nemáme", ne „všechno je v pořádku".
 */
function surchargeUnavailableKey(row: UiRow, kind: PayrollQuickSurchargeKind): string | null {
  const state = surchargeState(row, kind)
  if (state?.entry_available && !state.clear_only) return null
  return state?.clear_only ? 'clear_only' : (state?.unavailable_reason ?? 'basis_missing')
}

function surchargeUnavailableText(row: UiRow, kind: PayrollQuickSurchargeKind): string {
  const key = surchargeUnavailableKey(row, kind)
  return key === null ? '' : t(`payroll.quick_inputs.surcharges.unavailable.${key}`)
}

/**
 * Vysvětlení, které platí pro CELÝ sloupec, se vytáhne nad tabulku.
 *
 * Tentýž odstavec o § 115 nebo o chybějícím průměrném výdělku se u dvaceti
 * zaměstnanců lišil jenom tím, kolikrát se opakoval — a nafoukl řádek přes
 * 200 px. Vypíšeme ho jednou; v buňce zůstane kompaktní značka. Vytahuje se až
 * od dvou řádků: u jediného zablokovaného řádku není co deduplikovat a věta
 * patří k němu.
 */
const surchargeColumnNotes = computed<Partial<Record<PayrollQuickSurchargeKind, string>>>(() => {
  const notes: Partial<Record<PayrollQuickSurchargeKind, string>> = {}
  for (const kind of SURCHARGE_KINDS) {
    const counts = new Map<string, number>()
    for (const row of rows.value) {
      const key = surchargeUnavailableKey(row, kind)
      if (key !== null) counts.set(key, (counts.get(key) ?? 0) + 1)
    }
    let best = 1
    for (const [key, count] of counts) {
      if (count > best) {
        best = count
        notes[kind] = key
      }
    }
  }
  return notes
})

/**
 * Nedostupné pole říká „Nedostupné" placeholderem, ne značkou pod sebou —
 * řádek pak zůstane na výšku jednoho pole. Důvod nese `title` a `sr-only`.
 */
function surchargePlaceholder(row: UiRow, kind: PayrollQuickSurchargeKind): string | undefined {
  return surchargeUnavailableKey(row, kind) === null
    ? undefined
    : t('payroll.quick_inputs.surcharges.unavailable_placeholder')
}

/** Sloupce, ke kterým se nad tabulkou vypisuje společné vysvětlení. */
const surchargeNoteColumns = computed(() => SURCHARGE_KINDS
  .filter(kind => surchargeColumnNotes.value[kind] !== undefined)
  .map(kind => ({
    kind,
    label: t(`payroll.quick_inputs.surcharges.kinds.${kind}`),
    section: t(`payroll.quick_inputs.surcharges.sections.${kind}`),
    text: t(`payroll.quick_inputs.surcharges.unavailable.${surchargeColumnNotes.value[kind]}`),
  })))

function formatMoney(value: number): string {
  return formatMoneyMinor(value)
}

/** Pravidlo žije v `payrollComponentsUi.ts` — sdílí ho i karty zaměstnanců. */
function editable(input: PayrollQuickInputRef | null): boolean {
  return payrollInputEditable(input?.status ?? null, canApprove.value)
}

/**
 * Rozpad přesčasu na dosaženou mzdu a příplatek (§ 114 odst. 1 ZP).
 *
 * Ve formuláři je pole jedno, ale mzdový list musí doložit, KTERÝ zákonný
 * nárok byl uspokojen (§ 142 odst. 5 ZP) — dosažená mzda za odpracovanou
 * hodinu a příplatek nejméně 25 % průměrného výdělku jsou dva různé nároky.
 * Při náhradním volnu je příplatek nula a dosažená mzda se platí; i to je
 * informace, kterou musí být z pásky vidět, takže se rozpad ukáže i tehdy.
 *
 * Nulová dvojice se neukazuje: řádek bez přesčasu nemá co dokládat.
 */
function overtimeSplit(row: UiRow): { wage: number; premium: number } | null {
  const wage = row.overtime_wage_minor
  const premium = row.overtime_premium_minor
  if (typeof wage !== 'number' || typeof premium !== 'number') return null
  if (wage === 0 && premium === 0) return null

  return { wage, premium }
}

function relationLabel(row: UiRow): string {
  return t(`payroll.people.relations.${row.relation_type}`)
}

function incomeLabel(row: UiRow): string {
  if (row.relation_type === 'statutory_body') {
    return t('payroll.quick_inputs.income_labels.statutory_body')
  }
  if (row.relation_type === 'partner_dependent') {
    return t('payroll.quick_inputs.income_labels.partner_dependent')
  }
  if (row.relation_type === 'dpp' || row.relation_type === 'dpc') {
    return t('payroll.quick_inputs.income_labels.agreement')
  }
  return t('payroll.quick_inputs.income_labels.employment')
}

/**
 * Doklad ke krácení měsíční mzdy za absence. Vidět musí být fond i nahrazené
 * hodiny — bez nich je v poli jen nižší číslo, ke kterému účetní nedohledá,
 * proč je nižší.
 */
function prorationHint(row: UiRow): string | null {
  const proration = row.base_proration
  if (!proration || proration.replaced_minutes <= 0) return null
  return t('payroll.quick_inputs.base_proration', {
    replaced: minutesToHours(proration.replaced_minutes),
    fund: minutesToHours(proration.fund_minutes),
  })
}

function minutesToHours(minutes: number): string {
  return (minutes / 60).toLocaleString(locale.value, { maximumFractionDigits: 2 })
}

function additionalIncomeLabel(row: UiRow): string {
  return row.overtime_hours_relation_supported
    ? t('payroll.quick_inputs.overtime')
    : t('payroll.quick_inputs.additional_reward')
}

function employmentStatusLabel(row: UiRow): string {
  if (row.suspended_in_month) {
    return t('payroll.quick_inputs.suspended_in_month')
  }
  return t(`payroll.people.employment_status.${row.effective_status}`)
}

function fieldState(
  row: UiRow,
  kind: 'base' | 'overtime' | 'bonus',
): 'draft' | 'approved' | 'locked' | 'managed' | null {
  const managed = kind === 'base'
    ? row.base_managed_elsewhere
    : kind === 'overtime'
      ? row.overtime_managed_elsewhere
      : row.bonus_managed_elsewhere
  if (managed) return 'managed'
  const input = row.inputs[kind]
  if (input?.status === 'draft' || input?.status === 'approved' || input?.status === 'locked') {
    return input.status
  }
  return null
}

function fieldStateMessage(row: UiRow, kind: 'base' | 'overtime' | 'bonus'): string {
  const state = fieldState(row, kind)
  return state === null ? '' : t(`payroll.quick_inputs.field_state.${state}`)
}

function fieldStateShort(row: UiRow, kind: 'base' | 'overtime' | 'bonus'): string {
  const state = fieldState(row, kind)
  return state === null ? '' : t(`payroll.quick_inputs.field_state_short.${state}`)
}

function fieldManaged(row: UiRow, kind: 'base' | 'overtime' | 'bonus'): boolean {
  return fieldState(row, kind) === 'managed'
}

/** Předpis spravovaného pole bydlí v Mzdových složkách u téhož vztahu. */
function componentsLink(row: UiRow): { name: string; query: Record<string, string> } {
  return { name: 'payroll-components', query: { employment: String(row.employment_id) } }
}

/** Hodnota spravovaného pole jen ke čtení — tentýž tvar, jaký by stál v poli. */
function managedDisplay(row: UiRow, kind: 'base' | 'overtime' | 'bonus'): string {
  const value = kind === 'base'
    ? row.baseAmount
    : kind === 'bonus'
      ? row.bonusAmount
      : row.overtime_mode === 'hours' && row.overtimeHours !== ''
        ? `${row.overtimeHours} h`
        : row.overtimeAmount
  return value === '' ? '—' : value
}

/*
 * „Spravuje jiný vstup" je vlastnost POLE, ne člověka.
 *
 * Blokátor `*_managed_elsewhere` stál v buňce Osoba a tatáž věta ještě jednou
 * pod polem; po importu docházky to měl skoro každý řádek. U pole zůstává
 * ikona se zdrojem a nad tabulkou jeden souhrn, v buňce Osoba jen to, co se
 * konkrétního pole netýká (částečný měsíc, ruční kontrola jiné složky…).
 */
const MANAGED_BLOCKER_SUFFIX = '_managed_elsewhere'

function personBlockers(row: UiRow): string[] {
  return row.blockers.filter(blocker => !blocker.endsWith(MANAGED_BLOCKER_SUFFIX))
}

function rowManagedElsewhere(row: UiRow): boolean {
  return row.base_managed_elsewhere || row.overtime_managed_elsewhere
    || row.bonus_managed_elsewhere
    || row.blockers.some(blocker => blocker.endsWith(MANAGED_BLOCKER_SUFFIX))
}

const managedRowCount = computed(() => rows.value.filter(rowManagedElsewhere).length)

const managedInputsLink = computed(() => ({
  name: 'payroll-components',
  query: { tab: 'inputs', period: loadedPeriod.value ?? period.value, status: 'draft' },
}))

/** Chybějící rodné číslo se hlásí jednou nad tabulkou, ne v každém řádku. */
const IDENTIFIER_SUMMARY_LIMIT = 8
const missingIdentifierRows = computed(() => rows.value.filter(row => row.birth_number_masked === null))

/** Vztahy, u kterých by šel přesčas v hodinách, kdyby byl schválený průměr. */
const hoursUnavailableCount = computed(() => rows.value.filter(row =>
  row.overtime_hours_relation_supported && !row.overtime_hours_available
  && !row.overtime_managed_elsewhere).length)

const hasRowNotes = computed(() => managedRowCount.value > 0
  || missingIdentifierRows.value.length > 0 || hoursUnavailableCount.value > 0)

/** Běžný pracovní poměr je výchozí stav; štítek nese jen vztah, který se liší. */
function showRelationBadge(row: UiRow): boolean {
  return row.relation_type !== 'employment'
}

/** Popisek u pole jen tam, kde se liší od hlavičky sloupce (jiný druh příjmu). */
function incomeLabelDiffers(row: UiRow): boolean {
  return row.relation_type !== 'employment' && row.relation_type !== 'small_scale_employment'
}

function personMeta(row: UiRow): string {
  return [personalNumberLabel(t, row.employment_code), row.birth_number_masked]
    .filter((part): part is string => typeof part === 'string' && part !== '')
    .join(' · ')
}

function parsedAmount(value: string): number | null {
  return parsePayrollAmountToMinor(value)
}

function parsedHoursMilli(row: UiRow): number | null {
  return parsePayrollHoursToMilli(row.overtimeHours)
}

function amountError(value: string): ValidationCode | null {
  if (value.trim() === '') return 'amount_required'
  const parsed = parsedAmount(value)
  if (parsed === null) return 'amount_format'
  if (parsed < 0) return 'amount_non_negative'
  if (parsed > MAX_AMOUNT_MINOR) return 'amount_limit'
  return null
}

function hoursError(value: string): ValidationCode | null {
  const normalized = value.trim()
  if (normalized === '') return 'hours_required'
  if (normalized.startsWith('-')) return 'hours_non_negative'
  const parsed = parsePayrollHoursToMilli(normalized)
  if (parsed === null) return 'hours_format'
  if (parsed > MAX_OVERTIME_HOURS_MILLI) return 'hours_limit'
  return null
}

// U základní mzdy je prázdné pole legitimní stav, ne chyba: znamená „základ
// v tomto měsíci neřeším". Zadaná nula je něco jiného — ta se uloží jako nulový
// základ a v částečném nebo přerušeném měsíci je to plnohodnotný údaj. Kdyby
// prázdné pole zůstalo chybou, uživatel by nulu neměl jak od nevyplnění odlišit.
function baseError(row: UiRow): ValidationCode | null {
  if (row.base_managed_elsewhere || !editable(row.inputs.base)) return null
  if (baseIsBlank(row)) return null
  return amountError(row.baseAmount)
}

function baseIsBlank(row: UiRow): boolean {
  return row.baseAmount.trim() === ''
}

function bonusError(row: UiRow): ValidationCode | null {
  return row.bonus_managed_elsewhere || !editable(row.inputs.bonus)
    ? null
    : amountError(row.bonusAmount)
}

function overtimeError(row: UiRow): ValidationCode | null {
  if (row.overtime_managed_elsewhere || !editable(row.inputs.overtime)) return null
  return row.overtime_mode === 'hours'
    ? hoursError(row.overtimeHours)
    : amountError(row.overtimeAmount)
}

function rowInvalid(row: UiRow): boolean {
  return baseError(row) !== null
    || overtimeError(row) !== null
    || bonusError(row) !== null
    || SURCHARGE_KINDS.some(kind => surchargeHoursError(row, kind) !== null
      || surchargeFactorsError(row, kind) !== null)
    || componentColumns.value.some(column => componentError(row, column) !== null)
}

/*
 * Příplatkové sloupce se odkrývají pro CELOU TABULKU jedním přepínačem, ne
 * rozbalováním u řádku.
 *
 * Rozbalovací sekce u jednotlivce vypadá úsporně, ale při 500 zaměstnancích je
 * to 500 rozkliknutí — přesně ten vzorec, kvůli kterému rychlý měsíční vstup
 * vznikl. Sloupec navíc dovolí vyplňovat sloupcově a tabulátorem, což je jak
 * mzdová účetní doopravdy pracuje.
 */
const surchargesVisible = ref(false)
/*
 * Uložená volba se přečte, až budou preference tabulky načtené. Do té doby se
 * přepínač neukládá — přepsal by uloženou volbu výchozím stavem.
 */
watch(() => tbl.ready.value, (ready) => {
  if (ready && tbl.flag(SURCHARGE_FLAG)) surchargesVisible.value = true
}, { immediate: true })

/** Řádek, který příplatek už drží (zadaný ručně i promítnutý z docházky). */
function rowHasSurcharge(row: PayrollQuickInputRow): boolean {
  return SURCHARGE_KINDS.some((kind) => {
    const state = row.surcharges?.[kind]
    return state != null
      && (state.hours_milli !== null || state.amount_minor !== 0
        || state.managed_amount_minor !== 0)
  })
}

function setSurchargesVisible(visible: boolean): void {
  surchargesVisible.value = visible
  if (tbl.ready.value) tbl.setFlag(SURCHARGE_FLAG, visible)
}

function toggleSurcharges(): void {
  const next = !surchargesVisible.value
  setSurchargesVisible(next)
  // Odkrytí rovnou nabídne i další mzdové složky: výchozí sloupce příplatků se
  // ukážou hned a zaškrtávátka pro ostatní jsou po ruce.
  columnsMenuOpen.value = next
}

const hasInvalidRows = computed(() => rows.value.some(rowInvalid))

/*
 * Co se pošle na server: celá zobrazená stránka plus rozepsané řádky odjinud.
 *
 * Řádek, který je vadný už v prohlížeči (nepřečitatelná částka), se neposílá —
 * server by kvůli němu odmítl celý požadavek. Zůstane červený na místě a
 * ostatní se uloží.
 */
const savableRows = computed(() => [...rows.value, ...pendingElsewhere.value]
  .filter(row => !rowInvalid(row) && !rowClosed(row)))

/** Řádky stránky s uzavřenou mzdou za měsíc. */
const closedRows = computed(() => rows.value.filter(rowClosed))

/*
 * Proč nejde „Uložit vše". Blokací je několik a liší se tím, co má uživatel
 * udělat, takže obecné „akce není dostupná" by mu nepomohlo ani jednou.
 * Pořadí odpovídá tomu, co musí vyřešit dřív.
 *
 * Vadný ani konfliktní řádek už tlačítko nezamyká — jen se neuloží on. Věta
 * pod tlačítkem proto říká, kolik řádků zůstane stranou, ne „nejde uložit".
 */
const saveBlockedReason = computed<string | null>(() => {
  if (loading.value || loadedPeriod.value !== period.value) {
    return t('payroll.quick_inputs.save_blocked_loading')
  }
  if (rows.value.length === 0) return t('payroll.quick_inputs.save_blocked_empty')
  if (closedRows.value.length === rows.value.length && pendingElsewhere.value.length === 0) {
    return t('payroll.quick_inputs.closed_run.save_blocked')
  }
  if (savableRows.value.length === 0) return t('payroll.quick_inputs.save_blocked_invalid')
  return null
})

/** Poznámka pod tlačítkem: co se tímhle uložením NEuloží a proč. */
const savePartialNote = computed<string | null>(() => {
  if (saveBlockedReason.value !== null) return null
  const skipped = rows.value.filter(rowInvalid).length
  if (skipped > 0) return t('payroll.quick_inputs.save_partial_invalid', { count: skipped })
  if (conflictRowCount.value > 0) {
    return t('payroll.quick_inputs.save_partial_conflict', { count: conflictRowCount.value })
  }
  if (pendingElsewhere.value.length > 0) {
    return t('payroll.quick_inputs.save_pending_pages', { count: pendingElsewhere.value.length })
  }
  return null
})
const invalidFieldCount = computed(() => rows.value.reduce(
  (count, row) => count
    + Number(baseError(row) !== null)
    + Number(overtimeError(row) !== null)
    + Number(bonusError(row) !== null)
    + SURCHARGE_KINDS.reduce(
      (sum, kind) => sum
        + Number(surchargeHoursError(row, kind) !== null)
        + Number(surchargeFactorsError(row, kind) !== null),
      0,
    )
    + componentColumns.value.reduce(
      (sum, column) => sum + Number(componentError(row, column) !== null),
      0,
    ),
  0,
))

function validationMessage(code: ValidationCode | null): string {
  return code === null ? '' : t(`payroll.quick_inputs.validation.${code}`)
}

/** `compact` = tabulka: nízký řádek je u 500 lidí rozdíl mezi přehledem a scrollováním. */
function fieldClass(error: ValidationCode | null, alignRight = false, compact = false): string[] {
  return [
    compact ? 'h-8 px-2' : 'h-10 px-3',
    'rounded-md border bg-surface text-sm text-neutral-900 outline-none',
    'focus:ring-2 disabled:cursor-not-allowed disabled:bg-neutral-50 disabled:text-neutral-500',
    alignRight ? 'text-right tabular-nums' : '',
    error === null
      ? 'border-neutral-300 focus:border-payroll-500 focus:ring-payroll-500/20'
      : 'border-danger-400 focus:border-danger-500 focus:ring-danger-500/20',
  ]
}

function modeButtonClass(active: boolean): string[] {
  return [
    'h-9 cursor-pointer rounded-md border px-3 text-xs font-medium transition-colors',
    'disabled:cursor-not-allowed disabled:opacity-50',
    active
      ? 'border-payroll-600 bg-payroll-50 text-payroll-700'
      : 'border-neutral-300 bg-surface text-neutral-600 hover:border-payroll-300 hover:text-payroll-700',
  ]
}

/** Segment „h | Kč" u přesčasu v tabulce — dvě plná tlačítka by řádek zdvojila. */
function modeSegmentClass(active: boolean): string[] {
  return [
    'h-8 min-w-8 cursor-pointer px-2 text-xs font-medium transition-colors',
    'disabled:cursor-not-allowed disabled:opacity-40',
    active
      ? 'bg-payroll-600 text-white'
      : 'bg-surface text-neutral-600 hover:bg-payroll-50 hover:text-payroll-700',
  ]
}

/** Hodnota, kterou spravuje jiný vstup: vypadá jako pole, ale nejde do ní psát. */
const READONLY_VALUE_CLASS = 'inline-flex h-8 items-center justify-end rounded-md border border-dashed border-neutral-300 bg-neutral-50 px-2 text-sm tabular-nums text-neutral-600'

/*
 * Základní mzda předvyplněná ze sjednaných podmínek, za kterou v měsíci ještě
 * žádný vstup neexistuje. Pole vypadalo stejně jako uložená hodnota, ale běh
 * ho nevidí a hlásí „chybí schválená mzdová složka".
 */
const SUGGESTED_FIELD_CLASS = 'border-dashed border-warning-500 bg-warning-50'

function baseSuggested(row: UiRow): boolean {
  return !row.hourly_remuneration_in_period
    && !row.base_managed_elsewhere
    && !row.base_requires_entry
    && row.inputs.base === null
    && row.base_amount_minor > 0
    && !pending.value.has(row.employment_id)
}

const unsavedSuggestions = ref<{ employments: number; amount_minor: number }>({ employments: 0, amount_minor: 0 })

function validAmount(value: string): number {
  const parsed = parsedAmount(value)
  return parsed !== null && parsed >= 0 && parsed <= MAX_AMOUNT_MINOR ? parsed : 0
}

function overtimePreview(row: UiRow): number {
  if (row.overtime_mode === 'amount') return validAmount(row.overtimeAmount)
  const hours = parsedHoursMilli(row)
  if (hours === null || hours > MAX_OVERTIME_HOURS_MILLI) return 0
  if (row.inputs.overtime && row.inputs.overtime.quantity_milliunits === hours) {
    return row.inputs.overtime.amount_minor
  }
  if (!row.overtime_hourly_rate_minor) return 0
  return Math.round(row.overtime_hourly_rate_minor * ((hours ?? 0) / 1000) * 1.25)
}

function grossPreview(row: UiRow): number {
  return validAmount(row.baseAmount)
    + overtimePreview(row)
    + validAmount(row.bonusAmount)
    + surchargeTotal(row)
    + row.other_amount_minor
    + componentGrossDelta(row)
}

/**
 * Čerstvý řádek ze serveru, přes který se přetáhne rozepsaná hodnota.
 *
 * Verze (a příznaky „spravuje jinde") se berou ze serveru, ne z paměti: jsou
 * to právě ta data, na kterých stojí optimistický zámek. Zapamatovaná zůstává
 * jen ta část, kterou napsal uživatel.
 */
function applyPending(row: PayrollQuickInputRow): UiRow {
  const ui = toUi(row)
  const kept = pending.value.get(row.employment_id)
  if (kept) {
    ui.baseAmount = kept.baseAmount
    ui.overtimeHours = kept.overtimeHours
    ui.overtimeAmount = kept.overtimeAmount
    ui.bonusAmount = kept.bonusAmount
    ui.overtime_mode = kept.overtime_mode
    ui.surchargeHours = { ...kept.surchargeHours }
    ui.surchargeFactors = { ...kept.surchargeFactors }
    // Z buněk složek se přenáší jen to, na co uživatel sáhl; ostatní bere
    // čerstvě ze serveru, stejně jako verze.
    for (const code of componentTouchedCodes(kept)) {
      ui.componentAmounts[code] = kept.componentAmounts[code] ?? ''
      ui.componentDirty[code] = kept.componentDirty[code] === true
      ui.componentRevert[code] = kept.componentRevert[code] === true
    }
    pending.value.set(row.employment_id, ui)
  }
  return ui
}

async function load(): Promise<void> {
  const requestedPeriod = period.value
  const generation = ++loadGeneration
  loading.value = true
  loadFailed.value = false
  rows.value = []
  loadedPeriod.value = null
  saveError.value = null
  saveConflict.value = false
  try {
    const month = await payrollApi.quickInputs(
      requestedPeriod,
      { limit: pageSize, offset: offset.value },
      focusEmploymentId.value ?? undefined,
      appliedSearch.value || undefined,
    )
    if (generation !== loadGeneration || period.value !== requestedPeriod
      || month.period !== requestedPeriod) return
    rows.value = month.items.map(applyPending)
    // `total` už je zúžené serverem, takže pager mluví o tom, co tabulka ukazuje.
    total.value = month.total
    componentColumns.value = month.columns ?? []
    periodTotals.value = month.totals ?? null
    recurringPending.value = month.recurring_pending ?? { employments: 0, assignments: 0 }
    unsavedSuggestions.value = month.unsaved_suggestions ?? { employments: 0, amount_minor: 0 }
    // Měsíc, který zpracoval předchozí program. Zadávat se v něm dá dál, jen
    // se to nikde nepoužije — mzdový běh za takové období nejde založit.
    historicalMonth.value = month.historical === true
    payrollStartPeriod.value = month.payroll_start_period ?? null
    loadedPeriod.value = requestedPeriod
    // Skrytá sekce se otevře sama, drží-li nějaký řádek příplatek. Data, která
    // v měsíci jsou, se nesmí uživateli ztratit z očí jen proto, že přepínač
    // zůstal z minula vypnutý.
    if (month.items.some(rowHasSurcharge)) surchargesVisible.value = true
  } catch (error) {
    if (generation === loadGeneration) {
      // Řádky se tady vyčistily už PŘED požadavkem (kvůli přepnutí období),
      // takže po selhání zbyde prázdná tabulka. Bez příznaku by tvrdila, že
      // v období nikdo není — proto ho musíme zvednout.
      loadFailed.value = true
      toast.error(apiErrorMessage(error, t('payroll.quick_inputs.load_failed')))
    }
  } finally {
    if (generation === loadGeneration) loading.value = false
  }
}

function payload(batch: UiRow[]): PayrollQuickInputSavePayload {
  return {
    period: period.value,
    rows: batch.map(row => ({
      employment_id: row.employment_id,
      employment_row_version: row.employment_row_version,
      base_amount_minor: baseIsBlank(row) ? null : parsedAmount(row.baseAmount),
      overtime_mode: row.overtime_mode,
      overtime_hours_milli: row.overtime_mode === 'hours' ? parsedHoursMilli(row) : null,
      overtime_amount_minor: row.overtime_mode === 'amount'
        ? parsedAmount(row.overtimeAmount)
        : null,
      overtime_average_snapshot_id: row.overtime_mode === 'hours'
        ? row.overtime_average_snapshot_id
        : null,
      overtime_average_snapshot_version: row.overtime_mode === 'hours'
        ? row.overtime_average_snapshot_version
        : null,
      bonus_amount_minor: parsedAmount(row.bonusAmount) as number,
      ...surchargePayload(row),
      ...componentPayload(row),
      versions: {
        base: row.inputs.base?.row_version ?? null,
        overtime: row.inputs.overtime?.row_version ?? null,
        bonus: row.inputs.bonus?.row_version ?? null,
        surcharges: Object.fromEntries(SURCHARGE_KINDS
          .filter(kind => surchargeEditable(row, kind))
          .map(kind => [kind, surchargeState(row, kind)?.row_version ?? null])),
      },
    })),
  }
}

/**
 * Posílají se JEN druhy, které uživatel opravdu smí měnit.
 *
 * Druh, který v požadavku není, server nechá být. Kdyby se posílaly všechny,
 * uložení z obrazovky se skrytou sekcí by zrušilo příplatky, o kterých
 * uživatel ani nevěděl — a ztráta zákonného nároku bez jediné hlášky je to
 * nejhorší, co tenhle formulář může udělat.
 */
function surchargePayload(row: UiRow): Pick<
  PayrollQuickInputSavePayload['rows'][number], 'surcharges'
> {
  const surcharges: NonNullable<PayrollQuickInputSavePayload['rows'][number]['surcharges']> = {}
  for (const kind of SURCHARGE_KINDS) {
    if (!surchargeEditable(row, kind)) continue
    const raw = row.surchargeHours[kind].trim()
    const hours = raw === '' ? null : parsePayrollHoursToMilli(raw)
    const factors = row.surchargeFactors[kind].trim()
    surcharges[kind] = {
      hours_milli: hours,
      factors: surchargeState(row, kind)?.requires_factors && factors !== ''
        ? Number(factors)
        : null,
    }
  }
  return { surcharges }
}

/**
 * Buňky sloupců mzdových složek: posílají se JEN ty, na které uživatel sáhl,
 * a jen tam, kde je smí měnit. Složka, která v požadavku není, se nemění —
 * uložení tak nikdy nesáhne na hodnotu z importu, kterou nikdo neupravoval.
 */
function componentPayload(row: UiRow): Pick<
  PayrollQuickInputSavePayload['rows'][number], 'components'
> {
  const components: NonNullable<PayrollQuickInputSavePayload['rows'][number]['components']> = {}
  for (const code of componentTouchedCodes(row)) {
    const column = componentColumnByCode.value.get(code)
    if (column === undefined || !componentEditable(row, column)) continue
    const version = componentCell(row, code)?.row_version ?? null
    if (row.componentRevert[code]) {
      components[code] = { revert: true, row_version: version }
      continue
    }
    const raw = (row.componentAmounts[code] ?? '').trim()
    components[code] = { amount_minor: raw === '' ? null : parsedAmount(raw), row_version: version }
  }
  return Object.keys(components).length === 0 ? {} : { components }
}

/**
 * Řádky ostatních stránek téhož zúžení, jak je server právě spočítal.
 *
 * „Uložit měsíční podklady" slibuje celý měsíc (souhrn pod tabulkou mluví
 * o všech vztazích včetně dalších stránek), jenže ukládalo jen zobrazenou
 * stránku a rozepsané řádky. U firmy s osmi stránkami to bylo osm uložení
 * a přelistování. Nepřečtené stránky se proto dotáhnou těsně před uložením
 * (se stejným zúžením a hledáním) a jdou s ním.
 */
async function otherPageRows(requestedPeriod: string): Promise<UiRow[]> {
  if (total.value <= rows.value.length) return []
  const onPage = new Set(rows.value.map(row => row.employment_id))
  const collected: UiRow[] = []
  for (let from = 0; from < total.value; from += FULL_MONTH_PAGE) {
    const month = await payrollApi.quickInputs(
      requestedPeriod,
      { limit: FULL_MONTH_PAGE, offset: from },
      focusEmploymentId.value ?? undefined,
      appliedSearch.value || undefined,
    )
    for (const row of month.items) {
      if (!onPage.has(row.employment_id)) collected.push(applyPending(row))
    }
    if (month.items.length === 0) break
  }
  return collected
}
/** Strop jedné stránky seznamu na serveru (PayrollQuickInputRepository::LIST_MAX_LIMIT). */
const FULL_MONTH_PAGE = 200

async function save(): Promise<void> {
  if (loadedPeriod.value !== period.value || rows.value.length === 0) {
    return
  }
  const requestedPeriod = period.value
  const generation = loadGeneration
  saving.value = true
  saveError.value = null
  saveConflict.value = false
  fieldErrors.value = {}
  let batch: UiRow[]
  try {
    const others = await otherPageRows(requestedPeriod)
    const onPage = new Set(rows.value.map(row => row.employment_id))
    const pendingIds = new Set(pendingElsewhere.value.map(row => row.employment_id))
    batch = [
      ...savableRows.value,
      ...others.filter(row => !onPage.has(row.employment_id) && !pendingIds.has(row.employment_id)
        && !rowInvalid(row) && !rowClosed(row)),
    ]
  } catch (error) {
    saving.value = false
    saveError.value = apiErrorMessage(error, t('payroll.quick_inputs.save_failed'))
    toast.error(saveError.value)
    return
  }
  if (batch.length === 0) {
    saving.value = false
    toast.error(t('payroll.quick_inputs.validation_failed'))
    return
  }
  try {
    // Server bere nejvýše 500 vztahů na požadavek. U větší firmy se dávka
    // rozdělí, ale zůstává to JEDNO uložení z pohledu uživatele — ne dvacet
    // ručních uložení stránku po stránce, kvůli kterým to celé vzniklo.
    const failures: PayrollQuickInputFailure[] = []
    let last: Awaited<ReturnType<typeof payrollApi.saveQuickInputs>> | null = null
    for (let from = 0; from < batch.length; from += SAVE_CHUNK) {
      const chunk = batch.slice(from, from + SAVE_CHUNK)
      last = await payrollApi.saveQuickInputs(
        payload(chunk),
        { limit: pageSize, offset: offset.value },
        focusEmploymentId.value ?? undefined,
        appliedSearch.value || undefined,
      )
      failures.push(...last.failures)
    }
    if (last === null) return
    if (generation !== loadGeneration || period.value !== requestedPeriod
      || last.month.period !== requestedPeriod) return

    const failed = new Set(failures.map(failure => failure.employment_id))
    for (const row of batch) {
      if (!failed.has(row.employment_id)) pending.value.delete(row.employment_id)
    }
    fieldErrors.value = Object.fromEntries(failures.map(failure =>
      [fieldErrorKey(failure.employment_id, failure.field), failure.message]))

    // Uložení dostalo v query tentýž limit/offset, takže vrací TU stránku,
    // kterou měl uživatel před sebou — jinak by mu tabulka skočila na začátek.
    componentColumns.value = last.month.columns ?? componentColumns.value
    periodTotals.value = last.month.totals ?? null
    unsavedSuggestions.value = last.month.unsaved_suggestions ?? { employments: 0, amount_minor: 0 }
    rows.value = last.month.items.map(applyPending)
    total.value = last.month.total
    if (failures.length === 0) {
      toast.success(t('payroll.quick_inputs.saved'))
      return
    }
    // Částečný výsledek se nesmí tvářit ani jako úspěch, ani jako selhání:
    // uživatel musí vědět, kolik řádků prošlo a kolik na něj ještě čeká.
    saveError.value = t('payroll.quick_inputs.saved_partially', {
      saved: batch.length - failed.size,
      failed: failed.size,
    })
    saveConflict.value = failures.some(failure =>
      failure.code === 'employment_row_version_conflict'
      || failure.code === 'row_version_conflict')
    toast.error(saveError.value)
  } catch (error) {
    saveError.value = apiErrorMessage(error, t('payroll.quick_inputs.save_failed'))
    saveConflict.value = errorCode(error) === 'employment_row_version_conflict'
      || errorCode(error) === 'row_version_conflict'
    toast.error(saveError.value)
  } finally {
    saving.value = false
  }
}

function setOvertimeMode(row: UiRow, mode: 'hours' | 'amount'): void {
  if (mode === 'hours'
    && (!row.overtime_hours_relation_supported || !row.overtime_hours_available)) return
  row.overtime_mode = mode
  markDirty(row)
}

function clearSaveError(): void {
  saveError.value = null
  saveConflict.value = false
}

function errorCode(error: unknown): string {
  return (error as { response?: { data?: { error?: { code?: string } } } })
    ?.response?.data?.error?.code ?? ''
}

/*
 * Pravidelné složky, ze kterých za měsíc ještě nevznikl vstup.
 *
 * Typicky převzatá měsíční mzda z předchozího programu: řádek ji ukazuje jako
 * hodnotu, kterou „spravuje jiný vstup", jenže mzdový běh počítá jen ze
 * schválených vstupů a předpis sám nevidí. Dokud se vstupy nevytvořily, stál
 * běh na „nemá žádnou schválenou mzdovou složku" a cesta z blokátoru vedla do
 * prázdného seznamu. Akce vytvoří vstupy za celý měsíc a s právem schvalovat
 * je rovnou schválí — tatáž práce, kterou jinak dělají dvě jiné obrazovky.
 */
const recurringPending = ref<{ employments: number; assignments: number }>({ employments: 0, assignments: 0 })
const materializingRecurring = ref(false)
const RECURRING_APPROVE_CHUNK = 200
const recurringManualReview = ref<PayrollRecurringMaterialization['manual_review']>([])

async function materializeRecurring(): Promise<void> {
  if (materializingRecurring.value || !canWrite.value) return
  materializingRecurring.value = true
  recurringManualReview.value = []
  try {
    const result = await payrollApi.materializeRecurringComponents(period.value)
    recurringManualReview.value = result.manual_review
    const ids = [...result.created, ...result.replayed].map(item => item.input_id)
    let approved = 0
    if (canApprove.value) {
      for (let from = 0; from < ids.length; from += RECURRING_APPROVE_CHUNK) {
        const batch = await payrollApi.approveInputsBatch({ ids: ids.slice(from, from + RECURRING_APPROVE_CHUNK) })
        approved += batch.approved.length
      }
    }
    toast.success(t('payroll.quick_inputs.recurring_pending.done', {
      created: result.created_count + result.replayed_count,
      approved,
      manual: result.manual_review_count,
    }))
    await load()
  } catch (error) {
    toast.error(apiErrorMessage(error, t('payroll.quick_inputs.recurring_pending.failed')))
  } finally {
    materializingRecurring.value = false
  }
}

onMounted(() => {
  void load()
})
</script>

<template>
  <div class="space-y-6">
    <header class="flex flex-wrap items-end justify-between gap-4">
      <div>
        <h1 class="text-2xl font-semibold text-neutral-900">{{ t('payroll.quick_inputs.title') }}</h1>
        <p class="mt-1 max-w-3xl text-sm text-neutral-500">{{ t('payroll.quick_inputs.subtitle') }}</p>
      </div>
      <div class="flex flex-wrap items-end gap-2">
        <!-- Rozsah se nabízí jen u jednoho člověka; nad celou firmou by „vše"
             znamenalo vypsat roky práce všech zaměstnanců najednou. -->
        <label v-if="focusEmploymentId !== null" class="block">
          <span class="mb-1 block text-xs font-medium text-neutral-600">{{ t('payroll.agendas.scope.label') }}</span>
          <PayrollPeriodScopePicker
            :model-value="scope"
            :year="period.slice(0, 4)"
            :disabled="loading || saving"
            @update:model-value="changeScope"
          />
        </label>
        <label class="block">
          <span class="mb-1 block text-xs font-medium text-neutral-600">{{ t('payroll.quick_inputs.period') }}</span>
          <input
            data-testid="quick-payroll-period"
            v-model="period"
            type="month"
            class="h-10 rounded-md border border-neutral-300 bg-surface px-3 text-sm"
            :disabled="loading || saving"
            @change="changePeriod"
          >
        </label>
        <button :class="btnOutline('neutral')" :disabled="loading || saving" @click="load">
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.cycle" /></svg>
          {{ t('common.refresh') }}
        </button>
      </div>
    </header>

    <!--
      Měsíc, který zpracoval předchozí program. Vstupy se tu neschovávají,
      jen se říká, proč je nemá smysl dodělávat.
    -->
    <div
      v-if="historicalMonth"
      data-testid="quick-inputs-historical-notice"
      class="rounded-xl border border-neutral-200 bg-neutral-50 p-4 text-sm text-neutral-700"
    >
      <p class="font-medium text-neutral-900">{{ t('payroll.historical.title') }}</p>
      <p class="mt-1">
        {{ t('payroll.historical.quick_inputs', { period: formatPeriod(payrollStartPeriod ?? '') }) }}
      </p>
    </div>

    <!--
      Mzda za měsíc je u některých vztahů uzavřená. Jejich řádky jsou jen ke
      čtení a neposílají se; změna jde jen opravou mzdového běhu.
    -->
    <div
      v-if="!historyMode && closedRows.length > 0"
      data-testid="quick-closed-run-notice"
      class="rounded-xl border border-warning-200 bg-warning-50 p-4 text-sm text-neutral-700"
      role="status"
    >
      <p class="font-medium text-warning-900">
        {{ closedRows.length === rows.length
          ? t('payroll.quick_inputs.closed_run.title_all', { period: formatPeriod(period) })
          : t('payroll.quick_inputs.closed_run.title_some', { period: formatPeriod(period), count: closedRows.length }) }}
      </p>
      <p class="mt-1">{{ t('payroll.quick_inputs.closed_run.hint') }}</p>
      <div class="mt-3 flex flex-wrap items-center gap-2">
        <RouterLink
          :to="{ name: 'payroll-runs', query: { period } }"
          :class="[btnOutline('warning'), 'whitespace-nowrap']"
          data-testid="quick-closed-run-open"
        >
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.lock" /></svg>
          {{ t('payroll.quick_inputs.closed_run.open_runs') }}
        </RouterLink>
      </div>
    </div>

    <div v-if="!historyMode" class="rounded-xl border border-payroll-500/30 bg-payroll-50 p-4 text-sm text-neutral-700">
      <p>{{ t('payroll.quick_inputs.info') }}</p>
      <p class="mt-1 font-medium text-payroll-800">{{ t('payroll.quick_inputs.gross_preview_hint') }}</p>
    </div>

    <div
      v-if="!historyMode && !historicalMonth && unsavedSuggestions.employments > 0"
      data-testid="quick-unsaved-suggestions"
      class="rounded-xl border border-warning-200 bg-warning-50 p-4 text-sm text-neutral-700"
      role="status"
    >
      <p class="font-medium text-warning-900">
        {{ t('payroll.quick_inputs.unsaved.title', {
          count: unsavedSuggestions.employments,
          amount: formatMoney(unsavedSuggestions.amount_minor),
        }) }}
      </p>
      <p class="mt-1">
        {{ t(canApprove ? 'payroll.quick_inputs.unsaved.hint_approve' : 'payroll.quick_inputs.unsaved.hint_draft') }}
      </p>
      <div v-if="canWrite" class="mt-3 flex flex-wrap items-center gap-2">
        <button
          type="button"
          :class="[btnFilled('success'), 'whitespace-nowrap']"
          :disabled="loading || saving || rows.length === 0"
          data-testid="quick-unsaved-save"
          @click="save"
        >
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.check" /></svg>
          {{ t(canApprove ? 'payroll.quick_inputs.unsaved.action_approve' : 'payroll.quick_inputs.unsaved.action_draft', { count: unsavedSuggestions.employments }) }}
        </button>
      </div>
    </div>

    <div
      v-if="!historyMode && !historicalMonth && recurringPending.employments > 0"
      data-testid="quick-recurring-pending"
      class="rounded-xl border border-warning-200 bg-warning-50 p-4 text-sm text-neutral-700"
      role="status"
    >
      <p class="font-medium text-warning-900">
        {{ t('payroll.quick_inputs.recurring_pending.title', { count: recurringPending.employments }) }}
      </p>
      <p class="mt-1">
        {{ t(canApprove ? 'payroll.quick_inputs.recurring_pending.hint_approve' : 'payroll.quick_inputs.recurring_pending.hint_draft') }}
      </p>
      <div class="mt-3 flex flex-wrap items-center gap-2">
        <button
          v-if="canWrite"
          type="button"
          :class="[btnFilled('success'), 'whitespace-nowrap']"
          :disabled="loading || saving || materializingRecurring"
          data-testid="quick-recurring-materialize"
          @click="materializeRecurring"
        >
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.check" /></svg>
          <!-- Akce jde vždy přes celý měsíc; u zúžení na jednoho člověka by počet za něj klamal. -->
          {{ focusEmploymentId !== null
            ? t(canApprove ? 'payroll.quick_inputs.recurring_pending.action_approve_month' : 'payroll.quick_inputs.recurring_pending.action_draft_month')
            : t(canApprove ? 'payroll.quick_inputs.recurring_pending.action_approve' : 'payroll.quick_inputs.recurring_pending.action_draft', { count: recurringPending.assignments }) }}
        </button>
        <RouterLink
          :to="{ name: 'payroll-components', query: { tab: 'recurring', period } }"
          :class="[btnOutline('neutral'), 'whitespace-nowrap']"
          data-testid="quick-recurring-open"
        >
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.link" /></svg>
          {{ t('payroll.quick_inputs.recurring_pending.open') }}
        </RouterLink>
      </div>
    </div>

    <div
      v-if="recurringManualReview.length > 0"
      data-testid="quick-recurring-manual-review"
      class="rounded-xl border border-danger-200 bg-danger-50 p-4 text-sm text-danger-800"
      role="alert"
    >
      <p class="font-medium">{{ t('payroll.quick_inputs.recurring_pending.manual_title', { count: recurringManualReview.length }) }}</p>
      <ul class="mt-2 space-y-1">
        <li v-for="item in recurringManualReview" :key="item.recurring_component_id">
          <RouterLink
            v-if="item.employee_id"
            :to="{ name: 'payroll-people', query: { person: String(item.employee_id) } }"
            class="font-medium underline decoration-dotted underline-offset-2"
          >{{ item.full_name }}</RouterLink>
          <span v-else class="font-medium">{{ t('payroll.quick_inputs.recurring_pending.unknown_person') }}</span>:
          {{ item.reason }}
        </li>
      </ul>
    </div>

    <div
      v-if="!canWrite"
      class="rounded-xl border border-neutral-200 bg-neutral-50 p-4 text-sm text-neutral-600"
    >
      {{ t('payroll.quick_inputs.readonly_hint') }}
    </div>

    <div
      v-if="saveError"
      class="rounded-xl border border-danger-200 bg-danger-50 p-4"
      role="alert"
      data-testid="quick-payroll-save-error"
    >
      <p class="font-medium text-danger-800">{{ t('payroll.quick_inputs.save_error_title') }}</p>
      <p class="mt-1 text-sm text-danger-700">{{ saveError }}</p>
      <button
        v-if="saveConflict"
        type="button"
        :class="[btnOutline('danger'), 'mt-3']"
        :disabled="loading || saving"
        data-testid="quick-payroll-conflict-refresh"
        @click="load"
      >
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.cycle" /></svg>
        {{ t('payroll.quick_inputs.conflict_refresh') }}
      </button>
    </div>

    <div
      v-if="rows.length && hasInvalidRows && !historyMode"
      class="rounded-xl border border-danger-200 bg-danger-50 p-4 text-sm text-danger-800"
      role="alert"
      data-testid="quick-payroll-validation-summary"
    >
      {{ t('payroll.quick_inputs.validation_summary', { count: invalidFieldCount }) }}
    </div>

    <PayrollFocusNotice
      v-if="focusMissing"
      :name="focusName ?? t('payroll.agendas.focus.unknown_person')"
      missing
      named
      @clear="clearFocus"
    />
    <PayrollFocusNotice
      v-else-if="focusName"
      :name="focusName"
      @clear="clearFocus"
    />

    <!--
      Historie VYSTŘÍDÁ mřížku, neschovává se pod ni: mřížka je editace jednoho
      měsíce, historie je čtení mnoha měsíců. Obojí naráz by na obrazovce
      nechalo dvě různá období vedle sebe a částku by šlo zapsat do jiného,
      než na které se člověk dívá.
    -->
    <PayrollInputHistory
      v-if="historyMode && scopeRange && focusEmploymentId !== null"
      :employment-id="focusEmploymentId"
      :range="scopeRange"
      @open="openHistoryPeriod"
    />

    <!--
      Prázdno po zúžení pojmenovává už lišta nad seznamem. Generický prázdný
      stav pod ní by totéž řekl podruhé, a ještě obecněji.
    -->
    <section v-else-if="!focusMissing" class="overflow-hidden rounded-xl border border-neutral-200 bg-surface shadow-sm">
      <!--
        Lišta stojí MIMO přepínání načítání a prázdna: pole hledání se nesmí
        při každém dotazu zničit a vzít uživateli kurzor uprostřed psaní.
        Jeden přepínač odkryje příplatkové sloupce pro VŠECHNY řádky naráz;
        rozbalování u jednotlivce by při 500 lidech znamenalo 500 kliknutí.
      -->
      <div class="flex flex-wrap items-center justify-between gap-2 border-b border-neutral-200 px-4 py-2">
        <div class="flex flex-wrap items-center gap-2">
          <label class="relative block max-w-full">
            <span class="sr-only">{{ t('payroll.quick_inputs.search_label') }}</span>
            <svg class="pointer-events-none absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.search" /></svg>
            <input
              v-model="searchQuery"
              data-testid="quick-payroll-search"
              type="search"
              autocomplete="off"
              maxlength="120"
              :placeholder="t('payroll.quick_inputs.search_placeholder')"
              class="h-9 w-64 max-w-full rounded-md border border-neutral-300 bg-surface pl-8 pr-3 text-sm outline-none focus:border-payroll-500 focus:ring-2 focus:ring-payroll-500/20 disabled:cursor-not-allowed disabled:bg-neutral-50"
              :disabled="saving"
              @input="onSearchInput"
              @keydown.enter.prevent="applySearch"
            >
          </label>
          <button
            type="button"
            data-testid="quick-surcharges-toggle"
            data-quick-columns-anchor
            class="whitespace-nowrap"
            :class="surchargesVisible ? btnOutline('primary') : btnFilled('primary')"
            :aria-pressed="surchargesVisible"
            aria-controls="quick-surcharge-columns"
            :title="t('payroll.quick_inputs.surcharges.toggle')"
            @click="toggleSurcharges"
          >
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="surchargesVisible ? ICONS.x : ICONS.coin" /></svg>
            {{ t(surchargesVisible ? 'payroll.quick_inputs.surcharges.toggle_hide' : 'payroll.quick_inputs.surcharges.toggle_show') }}
          </button>
          <!--
            Nabídka dalších mzdových složek. Otevře se i sama po „Zadat i
            příplatky"; výchozí sloupce jsou zákonné příplatky jako dřív.
          -->
          <div class="relative">
            <button
              type="button"
              data-testid="quick-columns-menu-toggle"
              data-quick-columns-anchor
              class="whitespace-nowrap"
              :class="btnOutline('neutral')"
              :aria-expanded="columnsMenuOpen"
              aria-haspopup="dialog"
              @click="columnsMenuOpen = !columnsMenuOpen"
            >
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16M9 4v16" /></svg>
              {{ t('payroll.quick_inputs.columns_menu.button') }}
              <span
                v-if="selectedComponentColumns.length"
                class="rounded-full bg-payroll-50 px-1.5 text-[11px] font-medium text-payroll-700"
              >{{ selectedComponentColumns.length }}</span>
            </button>
            <PayrollQuickColumnMenu
              v-if="columnsMenuOpen"
              :groups="columnGroups"
              :selected="selectedColumnKeys"
              @change="onColumnChange"
              @preset="applyColumnPreset"
              @close="columnsMenuOpen = false"
            />
          </div>
        </div>
        <div class="hidden flex-wrap items-center gap-2 lg:flex">
          <ColumnPicker :ctrl="tbl" />
          <DensityToggle :ctrl="tbl" />
        </div>
      </div>
      <div v-if="loading" class="p-8 text-center text-sm text-neutral-500">{{ t('common.loading') }}</div>
      <EmptyState
        v-else-if="loadFailed"
        variant="failed"
        dense
        data-test="load-failed"
        :message="t('payroll.quick_inputs.load_failed_hint')"
        @action="load"
      />
      <!-- Prázdné hledání není prázdný měsíc: jiná věta a cesta zpět. -->
      <div
        v-else-if="rows.length === 0 && appliedSearch"
        class="p-8 text-center"
        data-testid="quick-payroll-search-empty"
      >
        <p class="text-sm text-neutral-600">{{ t('payroll.quick_inputs.search_empty', { q: appliedSearch }) }}</p>
        <button
          type="button"
          :class="[btnOutline('neutral'), 'mt-3']"
          data-testid="quick-payroll-search-clear"
          @click="clearSearch"
        >
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.x" /></svg>
          {{ t('payroll.quick_inputs.search_clear') }}
        </button>
      </div>
      <div v-else-if="rows.length === 0" class="p-8 text-center">
        <h2 class="font-semibold text-neutral-900">{{ t('payroll.quick_inputs.empty') }}</h2>
        <p class="mt-1 text-sm text-neutral-500">{{ t('payroll.quick_inputs.empty_hint') }}</p>
      </div>
      <template v-else>
        <!--
          Co se opakuje u mnoha řádků, stojí JEDNOU tady: spravované hodnoty,
          chybějící rodné číslo a přesčas bez schváleného průměru. V řádku z nich
          zbude ikona u pole s plným vysvětlením v `title` a `sr-only`.
        -->
        <div
          v-if="hasRowNotes"
          data-testid="quick-payroll-row-notes"
          class="space-y-1 border-b border-warning-200 bg-warning-50/70 px-4 py-2 text-xs text-neutral-700"
        >
          <p
            v-if="managedRowCount > 0"
            data-testid="quick-managed-summary"
            class="flex flex-wrap items-center gap-x-2 gap-y-1"
          >
            <svg class="h-4 w-4 shrink-0 text-warning-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.link" /></svg>
            <span>{{ t('payroll.quick_inputs.managed_summary', { count: managedRowCount }) }}</span>
            <RouterLink
              :to="managedInputsLink"
              data-test="quick-managed-summary-link"
              class="whitespace-nowrap font-medium text-warning-800 underline decoration-dotted underline-offset-2 hover:text-warning-900"
            >
              {{ t('payroll.quick_inputs.managed_summary_link') }}
            </RouterLink>
          </p>
          <p
            v-if="missingIdentifierRows.length > 0"
            data-testid="quick-identifier-missing-summary"
            class="flex flex-wrap items-center gap-x-1.5 gap-y-1"
          >
            <svg class="h-4 w-4 shrink-0 text-warning-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.user" /></svg>
            <span class="font-medium text-warning-800">
              {{ t('payroll.quick_inputs.identifier_missing_summary', { count: missingIdentifierRows.length }) }}
            </span>
            <template v-for="(row, index) in missingIdentifierRows.slice(0, IDENTIFIER_SUMMARY_LIMIT)" :key="row.employment_id">
              <RouterLink
                :to="{ name: 'payroll-people', query: { person: String(row.employee_id) } }"
                :data-test="`quick-identifier-missing-link-${row.employment_id}`"
                class="underline decoration-dotted underline-offset-2 hover:text-warning-900"
              >{{ row.full_name }}</RouterLink><span v-if="index < Math.min(missingIdentifierRows.length, IDENTIFIER_SUMMARY_LIMIT) - 1" aria-hidden="true">,</span>
            </template>
            <span v-if="missingIdentifierRows.length > IDENTIFIER_SUMMARY_LIMIT">
              {{ t('payroll.quick_inputs.identifier_missing_more', { count: missingIdentifierRows.length - IDENTIFIER_SUMMARY_LIMIT }) }}
            </span>
          </p>
          <!-- Na desktopu stojí tahle věta v hlavičce sloupce Přesčas. -->
          <p
            v-if="hoursUnavailableCount > 0"
            data-testid="quick-hours-unavailable-mobile"
            class="lg:hidden"
          >
            {{ t('payroll.quick_inputs.hours_unavailable_note', { count: hoursUnavailableCount }) }}.
            {{ t('payroll.quick_inputs.hours_unavailable') }}
          </p>
        </div>
        <p
          v-if="surchargesVisible"
          class="border-b border-neutral-200 bg-payroll-50/60 px-4 py-2 text-xs text-neutral-600"
        >
          {{ t('payroll.quick_inputs.surcharges.hint') }}
        </p>
        <!--
          Vysvětlení, které platí pro celý sloupec, stojí JEDNOU tady, ne
          v každé buňce. Nedostupné pole je v řádku jen neaktivní s
          placeholderem „Nedostupné"; důvod nese `title` a `sr-only`. Pruh je
          vidět i na mobilu, kde `title` nejde vyvolat.
        -->
        <div
          v-if="surchargesVisible && surchargeNoteColumns.length"
          data-testid="quick-surcharge-column-notes"
          class="border-b border-warning-200 bg-warning-50/70 px-4 py-2"
        >
          <p class="text-xs font-medium text-warning-800">
            {{ t('payroll.quick_inputs.surcharges.unavailable_note_title') }}
          </p>
          <ul class="mt-1 space-y-0.5">
            <li
              v-for="note in surchargeNoteColumns"
              :key="note.kind"
              :data-testid="`quick-surcharge-column-note-${note.kind}`"
              class="text-xs text-neutral-700"
            >
              <span class="font-medium text-neutral-900">{{ note.label }}</span>
              <span class="text-neutral-500"> ({{ note.section }})</span>
              <span> - {{ note.text }}</span>
            </li>
          </ul>
        </div>
        <!--
          Posuv je jen VODOROVNÝ, kvůli `min-w-[1120px]` a zmrazenému sloupci se
          jménem (`sticky left-0`). Svislý strop tu být nesmí: stránka má jediný
          posuvník, ne tabulku skrolující uvnitř sebe pod vlastní patičkou.
        -->
        <div id="quick-surcharge-columns" data-layout="desktop" class="hidden overflow-x-auto lg:block">
          <table v-column-labels="tbl" class="min-w-[1120px] w-full divide-y divide-neutral-200 text-sm" :class="tbl.densityClass.value">
            <thead class="sticky top-0 z-20 bg-surface">
              <tr class="text-left text-xs uppercase tracking-wide text-neutral-500">
                <th v-if="tbl.isVisible('person')" class="sticky left-0 z-30 w-64 bg-surface px-3 py-2 align-bottom">{{ t('payroll.quick_inputs.person') }}</th>
                <th v-if="tbl.isVisible('income_amount')" class="px-3 py-2 align-bottom">{{ t('payroll.quick_inputs.income_amount') }}</th>
                <!--
                  Proč nejdou hodiny, platí pro všechny řádky bez schváleného
                  průměru stejně — stojí proto jednou v hlavičce, ne v buňkách.
                -->
                <th v-if="tbl.isVisible('overtime')" class="px-3 py-2 align-bottom">
                  <span class="block">{{ t('payroll.quick_inputs.overtime') }}</span>
                  <span
                    v-if="hoursUnavailableCount > 0"
                    data-testid="quick-hours-unavailable-note"
                    :title="t('payroll.quick_inputs.hours_unavailable')"
                    class="block max-w-56 font-normal normal-case text-warning-700"
                  >
                    {{ t('payroll.quick_inputs.hours_unavailable_note', { count: hoursUnavailableCount }) }}
                    <span class="sr-only">. {{ t('payroll.quick_inputs.hours_unavailable') }}</span>
                  </span>
                </th>
                <th v-if="tbl.isVisible('bonus_amount')" class="px-3 py-2 align-bottom">{{ t('payroll.quick_inputs.bonus_amount') }}</th>
                <!--
                  Paragraf je druhý řádek hlavičky, ne pokračování názvu:
                  `whitespace-nowrap` ho drží pohromadě, aby se „§ 116"
                  nezlomilo mezi značku a číslo.
                -->
                <th
                  v-for="kind in visibleSurchargeKinds"
                  :key="kind"
                  class="px-3 py-2 align-bottom"
                  :data-testid="`quick-surcharge-head-${kind}`"
                >
                  <span class="block">{{ t(`payroll.quick_inputs.surcharges.kinds.${kind}`) }}</span>
                  <span class="block whitespace-nowrap font-normal normal-case text-neutral-400">
                    {{ t(`payroll.quick_inputs.surcharges.sections.${kind}`) }} · {{ t('payroll.quick_inputs.surcharges.hours_short') }}<template v-if="kind === 'difficult_environment'"> × {{ t('payroll.quick_inputs.surcharges.factors_short') }}</template>
                  </span>
                </th>
                <th
                  v-for="column in visibleComponentColumns"
                  :key="`component-${column.code}`"
                  class="px-3 py-2 align-bottom"
                  :data-testid="`quick-component-head-${column.code}`"
                >
                  <span class="block max-w-40 truncate" :title="column.name">{{ column.name }}</span>
                  <span class="block whitespace-nowrap font-normal normal-case text-neutral-400">
                    {{ column.code }}<template v-if="!column.editable_in_quick"> · {{ t('payroll.quick_inputs.columns_menu.summary_only') }}</template>
                  </span>
                </th>
                <!--
                  Věta „hrubé podklady před odvody" platí pro celý sloupec,
                  takže stojí v hlavičce. V každém řádku to bylo totéž N-krát.
                -->
                <th v-if="tbl.isVisible('gross_preview')" class="px-4 py-3 text-right align-bottom">
                  <span class="block">{{ t('payroll.quick_inputs.gross_preview') }}</span>
                  <span class="ml-auto block max-w-52 font-normal normal-case text-neutral-400">
                    {{ t('payroll.quick_inputs.gross_preview_short_hint') }}
                  </span>
                </th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="row in rows" :key="row.employment_id" class="align-top">
                <!--
                  Sloupec s člověkem má pevnou preferovanou šířku a text se
                  v něm láme. Dlouhé jméno nebo kód vztahu jinak roztáhne
                  celou tabulku a vodorovný posun se objeví i tam, kde nemá.
                -->
                <td v-if="tbl.isVisible('person')" class="sticky left-0 z-10 w-64 bg-surface px-3 py-2">
                  <div class="flex flex-wrap items-center gap-x-1.5 gap-y-0.5">
                    <span class="break-words font-semibold text-neutral-900">{{ row.full_name }}</span>
                    <span
                      v-if="showRelationBadge(row)"
                      :data-testid="`quick-relation-${row.employment_id}`"
                      class="inline-flex rounded-full bg-payroll-50 px-1.5 py-0.5 text-[11px] font-medium text-payroll-700"
                    >
                      {{ relationLabel(row) }}
                    </span>
                    <span
                      v-if="row.effective_status !== 'active' || row.suspended_in_month"
                      :data-testid="`quick-status-${row.employment_id}`"
                      class="inline-flex rounded-full bg-warning-50 px-1.5 py-0.5 text-[11px] font-medium text-warning-700"
                    >
                      {{ employmentStatusLabel(row) }}
                    </span>
                    <span
                      v-if="rowClosed(row)"
                      :data-testid="`quick-closed-${row.employment_id}`"
                      class="inline-flex items-center gap-0.5 rounded-full bg-neutral-100 px-1.5 py-0.5 text-[11px] font-medium text-neutral-700"
                    >
                      <svg class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.lock" /></svg>
                      {{ t('payroll.quick_inputs.closed_run.badge') }}
                    </span>
                  </div>
                  <p v-if="personMeta(row)" class="mt-0.5 break-words text-xs text-neutral-500">{{ personMeta(row) }}</p>
                  <p v-for="blocker in personBlockers(row)" :key="blocker" class="mt-1 text-xs text-warning-700">
                    {{ t(`payroll.quick_inputs.blockers.${blocker}`) }}
                  </p>
                </td>
                <td v-if="tbl.isVisible('income_amount')" class="px-3 py-2">
                  <p
                    v-if="incomeLabelDiffers(row)"
                    :data-testid="`quick-income-label-${row.employment_id}`"
                    class="mb-0.5 text-xs font-medium text-neutral-600"
                  >
                    {{ incomeLabel(row) }}
                  </p>
                  <!--
                    Spravované pole je jen ke čtení a ikona vede tam, kde se
                    předpis mění. U firmy s pevnou měsíční mzdou je to stav
                    každý měsíc, takže cesta dál musí být přímo u hodnoty.
                  -->
                  <div class="flex items-center gap-1.5">
                    <span
                      v-if="row.base_managed_elsewhere"
                      :data-testid="`quick-base-readonly-${row.employment_id}`"
                      :class="[READONLY_VALUE_CLASS, 'w-32']"
                    >{{ managedDisplay(row, 'base') }}</span>
                    <input
                      v-else
                      :data-testid="`quick-base-${row.employment_id}`"
                      v-model="row.baseAmount"
                      type="text"
                      inputmode="decimal"
                      autocomplete="off"
                      :aria-label="incomeLabel(row)"
                      :aria-invalid="baseError(row) !== null"
                      :aria-describedby="baseError(row) ? `quick-base-error-${row.employment_id}` : undefined"
                      :class="[fieldClass(baseError(row), true, true), 'w-32', baseSuggested(row) ? SUGGESTED_FIELD_CLASS : '']"
                      :disabled="loading || saving || !canWrite || rowClosed(row) || row.hourly_remuneration_in_period || !editable(row.inputs.base)"
                      @input="markDirty(row)"
                    >
                    <PayrollQuickFieldState
                      v-if="fieldState(row, 'base')"
                      :data-testid="`quick-base-state-${row.employment_id}`"
                      :data-test="fieldManaged(row, 'base') ? `quick-base-managed-link-${row.employment_id}` : undefined"
                      :state="fieldState(row, 'base')!"
                      :message="fieldStateMessage(row, 'base')"
                      :to="fieldManaged(row, 'base') ? componentsLink(row) : null"
                      :link-hint="fieldManaged(row, 'base') ? t('payroll.quick_inputs.blockers.base_managed_elsewhere_link') : null"
                    />
                  </div>
                  <p
                    v-if="baseSuggested(row)"
                    :data-testid="`quick-base-suggested-${row.employment_id}`"
                    class="mt-1 max-w-40 text-xs text-warning-700"
                  >
                    {{ t('payroll.quick_inputs.unsaved.cell') }}
                  </p>
                  <p
                    v-if="baseError(row)"
                    :id="`quick-base-error-${row.employment_id}`"
                    class="mt-1 max-w-40 text-xs text-danger-700"
                  >
                    {{ validationMessage(baseError(row)) }}
                  </p>
                  <p
                    v-if="serverError(row, 'base') || serverError(row, 'row')"
                    :data-testid="`quick-base-server-error-${row.employment_id}`"
                    class="mt-1 max-w-48 text-xs font-medium text-danger-700"
                  >
                    {{ serverError(row, 'base') ?? serverError(row, 'row') }}
                  </p>
                  <p
                    v-if="prorationHint(row)"
                    :data-testid="`quick-base-proration-${row.employment_id}`"
                    class="mt-1 max-w-48 text-xs text-neutral-600"
                  >
                    {{ prorationHint(row) }}
                  </p>
                </td>
                <td v-if="tbl.isVisible('overtime')" class="px-3 py-2">
                  <!--
                    U vztahu bez hodinového přesčasu (DPP, DPČ, funkce…) jde
                    o jinou věc než přesčas, takže popisek nese jen tenhle řádek.
                  -->
                  <p
                    v-if="!row.overtime_hours_relation_supported"
                    :title="t('payroll.quick_inputs.amount_only_relation_hint')"
                    class="mb-0.5 text-xs font-medium text-neutral-600"
                  >
                    {{ additionalIncomeLabel(row) }}<span class="sr-only"> ({{ t('payroll.quick_inputs.amount_only_relation_hint') }})</span>
                  </p>
                  <div class="flex items-center gap-1.5">
                    <span
                      v-if="row.overtime_managed_elsewhere"
                      :data-testid="`quick-overtime-readonly-${row.employment_id}`"
                      :class="[READONLY_VALUE_CLASS, 'w-32']"
                    >{{ managedDisplay(row, 'overtime') }}</span>
                    <template v-else>
                      <!--
                        Režim zůstává u řádku: přepnout ho za celý sloupec by
                        měnilo ukládaná data i u řádků, na které uživatel
                        nesáhl (uložená částka by se změnila na prázdné hodiny).
                        Proč nejdou hodiny, říká hlavička sloupce.
                      -->
                      <div
                        v-if="row.overtime_hours_relation_supported"
                        class="inline-flex shrink-0 divide-x divide-neutral-300 overflow-hidden rounded-md border border-neutral-300"
                        role="group"
                        :aria-label="t('payroll.quick_inputs.overtime_mode')"
                      >
                        <button
                          :data-testid="`overtime-mode-hours-${row.employment_id}`"
                          type="button"
                          :class="modeSegmentClass(row.overtime_mode === 'hours')"
                          :aria-pressed="row.overtime_mode === 'hours'"
                          :aria-label="t('payroll.quick_inputs.hours')"
                          :title="row.overtime_hours_available ? t('payroll.quick_inputs.hours') : t('payroll.quick_inputs.hours_unavailable')"
                          :disabled="loading || saving || !canWrite || !row.overtime_hours_available || !editable(row.inputs.overtime)"
                          @click="setOvertimeMode(row, 'hours')"
                        >{{ t('payroll.quick_inputs.mode_hours_short') }}</button>
                        <button
                          :data-testid="`overtime-mode-amount-${row.employment_id}`"
                          type="button"
                          :class="modeSegmentClass(row.overtime_mode === 'amount')"
                          :aria-pressed="row.overtime_mode === 'amount'"
                          :aria-label="t('payroll.quick_inputs.total_amount')"
                          :title="t('payroll.quick_inputs.total_amount')"
                          :disabled="loading || saving || !canWrite || rowClosed(row) || !editable(row.inputs.overtime)"
                          @click="setOvertimeMode(row, 'amount')"
                        >{{ t('payroll.quick_inputs.mode_amount_short') }}</button>
                      </div>
                      <input
                        v-if="row.overtime_hours_relation_supported && row.overtime_mode === 'hours'"
                        v-model="row.overtimeHours"
                        type="text"
                        inputmode="decimal"
                        autocomplete="off"
                        :aria-label="t('payroll.quick_inputs.overtime_hours')"
                        :aria-invalid="overtimeError(row) !== null"
                        :aria-describedby="overtimeError(row) ? `quick-overtime-error-${row.employment_id}` : undefined"
                        :class="[fieldClass(overtimeError(row), true, true), 'w-24']"
                        :disabled="loading || saving || !canWrite || rowClosed(row) || !editable(row.inputs.overtime)"
                        @input="markDirty(row)"
                      >
                      <input
                        v-else
                        v-model="row.overtimeAmount"
                        type="text"
                        inputmode="decimal"
                        autocomplete="off"
                        :aria-label="additionalIncomeLabel(row)"
                        :aria-invalid="overtimeError(row) !== null"
                        :aria-describedby="overtimeError(row) ? `quick-overtime-error-${row.employment_id}` : undefined"
                        :class="[fieldClass(overtimeError(row), true, true), row.overtime_hours_relation_supported ? 'w-24' : 'w-32']"
                        :disabled="loading || saving || !canWrite || rowClosed(row) || !editable(row.inputs.overtime)"
                        @input="markDirty(row)"
                      >
                    </template>
                    <PayrollQuickFieldState
                      v-if="fieldState(row, 'overtime')"
                      :data-testid="`quick-overtime-state-${row.employment_id}`"
                      :data-test="fieldManaged(row, 'overtime') ? `quick-overtime-managed-link-${row.employment_id}` : undefined"
                      :state="fieldState(row, 'overtime')!"
                      :message="fieldStateMessage(row, 'overtime')"
                      :to="fieldManaged(row, 'overtime') ? componentsLink(row) : null"
                      :link-hint="fieldManaged(row, 'overtime') ? t('payroll.quick_inputs.blockers.base_managed_elsewhere_link') : null"
                    />
                  </div>
                  <p
                    v-if="overtimeError(row)"
                    :id="`quick-overtime-error-${row.employment_id}`"
                    class="mt-1 max-w-56 text-xs text-danger-700"
                  >
                    {{ validationMessage(overtimeError(row)) }}
                  </p>
                  <p v-if="!row.overtime_managed_elsewhere && row.overtime_hours_relation_supported && row.overtime_mode === 'hours' && row.overtime_hourly_rate_minor" class="mt-0.5 text-xs text-neutral-500">
                    {{ t('payroll.quick_inputs.rate_hint', { rate: formatMoney(row.overtime_hourly_rate_minor) }) }}
                  </p>
                  <p
                    v-if="serverError(row, 'overtime')"
                    :data-testid="`quick-overtime-server-error-${row.employment_id}`"
                    class="mt-1 max-w-56 text-xs font-medium text-danger-700"
                  >
                    {{ serverError(row, 'overtime') }}
                  </p>
                  <p
                    v-if="overtimeSplit(row)"
                    :data-testid="`quick-overtime-split-${row.employment_id}`"
                    class="mt-1 max-w-56 text-xs text-neutral-500"
                  >
                    {{ t('payroll.quick_inputs.overtime_split', {
                      wage: formatMoney(overtimeSplit(row)!.wage),
                      premium: formatMoney(overtimeSplit(row)!.premium),
                    }) }}
                  </p>
                </td>
                <td v-if="tbl.isVisible('bonus_amount')" class="px-3 py-2">
                  <div class="flex items-center gap-1.5">
                    <span
                      v-if="row.bonus_managed_elsewhere"
                      :data-testid="`quick-bonus-readonly-${row.employment_id}`"
                      :class="[READONLY_VALUE_CLASS, 'w-32']"
                    >{{ managedDisplay(row, 'bonus') }}</span>
                    <input
                      v-else
                      v-model="row.bonusAmount"
                      type="text"
                      inputmode="decimal"
                      autocomplete="off"
                      :data-testid="`quick-bonus-${row.employment_id}`"
                      :aria-label="t('payroll.quick_inputs.bonus_amount')"
                      :aria-invalid="bonusError(row) !== null"
                      :aria-describedby="bonusError(row) ? `quick-bonus-error-${row.employment_id}` : undefined"
                      :class="[fieldClass(bonusError(row), true, true), 'w-32']"
                      :disabled="loading || saving || !canWrite || rowClosed(row) || !editable(row.inputs.bonus)"
                      @input="markDirty(row)"
                    >
                    <PayrollQuickFieldState
                      v-if="fieldState(row, 'bonus')"
                      :data-testid="`quick-bonus-state-${row.employment_id}`"
                      :data-test="fieldManaged(row, 'bonus') ? `quick-bonus-managed-link-${row.employment_id}` : undefined"
                      :state="fieldState(row, 'bonus')!"
                      :message="fieldStateMessage(row, 'bonus')"
                      :to="fieldManaged(row, 'bonus') ? componentsLink(row) : null"
                      :link-hint="fieldManaged(row, 'bonus') ? t('payroll.quick_inputs.blockers.base_managed_elsewhere_link') : null"
                    />
                  </div>
                  <p
                    v-if="bonusError(row)"
                    :id="`quick-bonus-error-${row.employment_id}`"
                    class="mt-1 max-w-40 text-xs text-danger-700"
                  >
                    {{ validationMessage(bonusError(row)) }}
                  </p>
                  <p
                    v-if="serverError(row, 'bonus')"
                    :data-testid="`quick-bonus-server-error-${row.employment_id}`"
                    class="mt-1 max-w-48 text-xs font-medium text-danger-700"
                  >
                    {{ serverError(row, 'bonus') }}
                  </p>
                </td>
                <td
                  v-for="kind in visibleSurchargeKinds"
                  :key="kind"
                  class="px-3 py-2"
                >
                  <!--
                    Hodiny a počet vlivů jsou dvojice vedle sebe. Co které pole
                    znamená, říká hlavička sloupce; popisek nad polem v každém
                    řádku byl tentýž text N-krát. Pole mají `aria-label`.
                  -->
                  <div class="flex items-center gap-1.5">
                    <label class="block">
                      <input
                        :data-testid="`quick-surcharge-${kind}-${row.employment_id}`"
                        v-model="row.surchargeHours[kind]"
                        type="text"
                        inputmode="decimal"
                        autocomplete="off"
                        :aria-label="t('payroll.quick_inputs.surcharges.hours_label', {
                          kind: t(`payroll.quick_inputs.surcharges.kinds.${kind}`),
                        })"
                        :aria-invalid="surchargeHoursError(row, kind) !== null"
                        :aria-describedby="surchargeUnavailableKey(row, kind) ? `quick-surcharge-reason-${kind}-${row.employment_id}` : undefined"
                        :placeholder="surchargePlaceholder(row, kind)"
                        :title="surchargeUnavailableText(row, kind) || undefined"
                        :class="[fieldClass(surchargeHoursError(row, kind), true, true), 'w-24 placeholder:text-xs placeholder:text-neutral-400']"
                        :disabled="loading || saving || !canWrite || !surchargeEditable(row, kind)"
                        @input="markDirty(row)"
                      >
                    </label>
                    <!--
                      § 117 přiznává příplatek ZA KAŽDÝ ztěžující vliv, takže
                      počet vlivů je součást zadání, ne detail.
                    -->
                    <label v-if="surchargeState(row, kind)?.requires_factors" class="flex items-center gap-1">
                      <span class="text-xs text-neutral-400" aria-hidden="true">×</span>
                      <input
                        :data-testid="`quick-surcharge-factors-${kind}-${row.employment_id}`"
                        v-model="row.surchargeFactors[kind]"
                        type="text"
                        inputmode="numeric"
                        autocomplete="off"
                        :aria-label="t('payroll.quick_inputs.surcharges.factors')"
                        :aria-invalid="surchargeFactorsError(row, kind) !== null"
                        :class="[fieldClass(surchargeFactorsError(row, kind), true, true), 'w-14']"
                        :disabled="loading || saving || !canWrite || !surchargeEditable(row, kind)"
                        @input="markDirty(row)"
                      >
                    </label>
                  </div>
                  <p
                    v-if="surchargeHoursError(row, kind) || surchargeFactorsError(row, kind)"
                    class="mt-1 max-w-40 text-xs text-danger-700"
                  >
                    {{ validationMessage(surchargeHoursError(row, kind)
                      ?? surchargeFactorsError(row, kind)) }}
                  </p>
                  <!-- Dopočtená částka u pole: účetní musí vidět, co z hodin vyjde. -->
                  <p
                    v-else-if="surchargePreview(row, kind)"
                    :data-testid="`quick-surcharge-preview-${kind}-${row.employment_id}`"
                    class="mt-1 text-xs font-medium tabular-nums text-payroll-700"
                  >
                    {{ formatMoney(surchargePreview(row, kind)) }}
                  </p>
                  <p
                    v-if="serverError(row, `surcharge_${kind}`)"
                    :data-testid="`quick-surcharge-server-error-${kind}-${row.employment_id}`"
                    class="mt-1 max-w-48 text-xs font-medium text-danger-700"
                  >
                    {{ serverError(row, `surcharge_${kind}`) }}
                  </p>
                  <!--
                    Nedostupné pole nemá pod sebou žádnou značku: placeholder
                    „Nedostupné" stačí a důvod je v `title` pole a tady pro
                    odečítače. Společný důvod sloupce stojí jednou nad tabulkou.
                  -->
                  <span
                    v-if="surchargeUnavailableKey(row, kind)"
                    :id="`quick-surcharge-reason-${kind}-${row.employment_id}`"
                    :data-testid="`quick-surcharge-blocked-${kind}-${row.employment_id}`"
                    class="sr-only"
                  >{{ surchargeUnavailableText(row, kind) }}</span>
                </td>
                <!--
                  Buňka složky: částka + ikona zdroje. Hodnota z importu se
                  upravuje ručním přepisem (import zůstane jako doklad), přepis
                  jde vrátit. Co s buňkou jde, rozhoduje server (`mode`).
                -->
                <td
                  v-for="column in visibleComponentColumns"
                  :key="`component-${column.code}`"
                  class="px-3 py-2"
                >
                  <div class="flex items-center gap-1.5">
                    <input
                      v-if="componentEditable(row, column)"
                      v-model="row.componentAmounts[column.code]"
                      :data-testid="`quick-component-${column.code}-${row.employment_id}`"
                      type="text"
                      inputmode="decimal"
                      autocomplete="off"
                      :aria-label="t('payroll.quick_inputs.component_cell.label', { name: column.name })"
                      :aria-invalid="componentError(row, column) !== null"
                      :title="componentSourceTitle(row, column) || undefined"
                      :class="[
                        fieldClass(componentError(row, column) === null ? null : 'amount_format', true, true),
                        'w-28',
                        row.componentRevert[column.code] ? 'text-neutral-400 line-through' : '',
                      ]"
                      :disabled="loading || saving"
                      @input="onComponentInput(row, column.code)"
                    >
                    <span
                      v-else
                      :data-testid="`quick-component-readonly-${column.code}-${row.employment_id}`"
                      :title="componentSourceTitle(row, column) || undefined"
                      :class="[READONLY_VALUE_CLASS, 'w-28']"
                    >{{ componentDisplay(row, column.code) }}</span>
                    <span
                      v-if="componentSourceIcon(row, column.code)"
                      :data-testid="`quick-component-source-${column.code}-${row.employment_id}`"
                      :data-source="componentCell(row, column.code)?.mode"
                      :title="componentSourceTitle(row, column)"
                      class="inline-flex shrink-0"
                      :class="componentCell(row, column.code)?.mode === 'override' ? 'text-warning-700' : 'text-neutral-400'"
                    >
                      <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="componentSourceIcon(row, column.code)!" /></svg>
                      <span class="sr-only">{{ componentSourceTitle(row, column) }}</span>
                    </span>
                    <button
                      v-if="componentRevertable(row, column)"
                      type="button"
                      :data-testid="`quick-component-revert-${column.code}-${row.employment_id}`"
                      class="inline-flex h-8 w-8 shrink-0 cursor-pointer items-center justify-center rounded-md border border-warning-300 bg-surface text-warning-700 hover:bg-warning-50 disabled:cursor-not-allowed disabled:opacity-50"
                      :title="t('payroll.quick_inputs.component_cell.revert')"
                      :aria-label="t('payroll.quick_inputs.component_cell.revert')"
                      :disabled="loading || saving"
                      @click="requestRevert(row, column.code)"
                    >
                      <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.cycle" /></svg>
                    </button>
                  </div>
                  <p
                    v-if="row.componentRevert[column.code]"
                    :data-testid="`quick-component-revert-pending-${column.code}-${row.employment_id}`"
                    class="mt-1 max-w-48 text-xs text-warning-700"
                  >
                    {{ t('payroll.quick_inputs.component_cell.revert_pending', {
                      value: formatMoney(componentCell(row, column.code)?.override_of?.amount_minor ?? 0),
                    }) }}
                    <button
                      type="button"
                      class="cursor-pointer font-medium underline decoration-dotted underline-offset-2"
                      @click="cancelRevert(row, column.code)"
                    >{{ t('payroll.quick_inputs.component_cell.revert_cancel') }}</button>
                  </p>
                  <p v-if="componentError(row, column)" class="mt-1 max-w-48 text-xs text-danger-700">
                    {{ componentErrorMessage(componentError(row, column)) }}
                  </p>
                  <p
                    v-if="serverError(row, `component:${column.code}`)"
                    :data-testid="`quick-component-server-error-${column.code}-${row.employment_id}`"
                    class="mt-1 max-w-48 text-xs font-medium text-danger-700"
                  >
                    {{ serverError(row, `component:${column.code}`) }}
                  </p>
                </td>
                <td v-if="tbl.isVisible('gross_preview')" class="px-3 py-2 text-right">
                  <p class="font-semibold tabular-nums text-neutral-900">{{ formatMoney(grossPreview(row)) }}</p>
                  <p v-if="row.other_amount_minor" class="mt-1 text-xs text-neutral-500">
                    {{ t('payroll.quick_inputs.other_inputs', { amount: formatMoney(row.other_amount_minor) }) }}
                  </p>
                  <p v-if="row.non_monetary_amount_minor" class="mt-1 text-xs text-neutral-500">
                    {{ t('payroll.quick_inputs.non_monetary_inputs', { amount: formatMoney(row.non_monetary_amount_minor) }) }}
                  </p>
                  <p v-if="row.excluded_from_gross_amount_minor" class="mt-1 text-xs text-neutral-500">
                    {{ t('payroll.quick_inputs.excluded_inputs', { amount: formatMoney(row.excluded_from_gross_amount_minor) }) }}
                  </p>
                </td>
              </tr>
            </tbody>
            <!--
              Součet za CELÉ období (server), posunutý o rozepsané změny ze
              všech stránek — kontrolní součet proti podkladu.
            -->
            <tfoot
              v-if="totalsView"
              data-testid="quick-totals-row"
              class="sticky bottom-0 z-20 bg-neutral-50 text-sm font-semibold text-neutral-900 shadow-[0_-1px_0_0_rgba(0,0,0,0.08)]"
            >
              <tr>
                <td v-if="tbl.isVisible('person')" class="sticky left-0 z-30 w-64 bg-neutral-50 px-3 py-2">
                  <span class="block">{{ t('payroll.quick_inputs.totals.label') }}</span>
                  <span class="block text-xs font-normal text-neutral-500">
                    {{ t('payroll.quick_inputs.totals.hint', { count: totalsView.rows }) }}
                  </span>
                </td>
                <td v-if="tbl.isVisible('income_amount')" data-testid="quick-total-base" class="px-3 py-2 tabular-nums">{{ formatMoney(totalsView.base) }}</td>
                <td v-if="tbl.isVisible('overtime')" data-testid="quick-total-overtime" class="px-3 py-2 tabular-nums">{{ formatMoney(totalsView.overtime) }}</td>
                <td v-if="tbl.isVisible('bonus_amount')" data-testid="quick-total-bonus" class="px-3 py-2 tabular-nums">{{ formatMoney(totalsView.bonus) }}</td>
                <td
                  v-for="kind in visibleSurchargeKinds"
                  :key="`total-${kind}`"
                  :data-testid="`quick-total-surcharge-${kind}`"
                  class="px-3 py-2 tabular-nums"
                >{{ formatMoney(totalsView.surcharges[kind]) }}</td>
                <td
                  v-for="column in visibleComponentColumns"
                  :key="`total-${column.code}`"
                  :data-testid="`quick-total-component-${column.code}`"
                  class="px-3 py-2 tabular-nums"
                >{{ formatMoney(totalsView.components[column.code] ?? 0) }}</td>
                <td v-if="tbl.isVisible('gross_preview')" data-testid="quick-total-gross" class="px-3 py-2 text-right tabular-nums">{{ formatMoney(totalsView.gross) }}</td>
              </tr>
            </tfoot>
          </table>
        </div>

        <div data-layout="mobile" class="space-y-4 p-4 lg:hidden">
          <article v-for="row in rows" :key="row.employment_id" class="rounded-xl border border-neutral-200 p-4">
            <div class="flex flex-wrap items-start justify-between gap-2">
              <div>
                <h2 class="font-semibold text-neutral-900">{{ row.full_name }}</h2>
                <p v-if="personMeta(row)" class="text-xs text-neutral-500">{{ personMeta(row) }}</p>
                <div
                  v-if="showRelationBadge(row) || row.effective_status !== 'active' || row.suspended_in_month"
                  class="mt-1 flex flex-wrap gap-1"
                >
                  <span
                    v-if="showRelationBadge(row)"
                    :data-testid="`quick-relation-mobile-${row.employment_id}`"
                    class="inline-flex rounded-full bg-payroll-50 px-2 py-0.5 text-xs font-medium text-payroll-700"
                  >
                    {{ relationLabel(row) }}
                  </span>
                  <span
                    v-if="row.effective_status !== 'active' || row.suspended_in_month"
                    class="inline-flex rounded-full bg-warning-50 px-2 py-0.5 text-xs font-medium text-warning-700"
                  >
                    {{ employmentStatusLabel(row) }}
                  </span>
                </div>
              </div>
              <div class="text-right">
                <strong class="text-payroll-700">{{ formatMoney(grossPreview(row)) }}</strong>
                <p v-if="row.other_amount_minor" class="mt-1 text-xs text-neutral-500">
                  {{ t('payroll.quick_inputs.other_inputs', { amount: formatMoney(row.other_amount_minor) }) }}
                </p>
                <p v-if="row.non_monetary_amount_minor" class="mt-1 text-xs text-neutral-500">
                  {{ t('payroll.quick_inputs.non_monetary_inputs', { amount: formatMoney(row.non_monetary_amount_minor) }) }}
                </p>
                <p v-if="row.excluded_from_gross_amount_minor" class="mt-1 text-xs text-neutral-500">
                  {{ t('payroll.quick_inputs.excluded_inputs', { amount: formatMoney(row.excluded_from_gross_amount_minor) }) }}
                </p>
              </div>
            </div>
            <p v-for="blocker in personBlockers(row)" :key="blocker" class="mt-2 text-xs text-warning-700">
              {{ t(`payroll.quick_inputs.blockers.${blocker}`) }}
            </p>
            <!--
              Na mobilu `title` nejde vyvolat, takže stav pole nese vedle ikony
              krátký štítek. Spravované pole je odkaz tam, kde se předpis mění.
            -->
            <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
              <div>
                <div class="mb-1 flex items-center justify-between gap-2">
                  <span
                    :id="`quick-income-label-mobile-${row.employment_id}`"
                    :data-testid="`quick-income-label-mobile-${row.employment_id}`"
                    class="text-xs font-medium text-neutral-600"
                  >
                    {{ incomeLabel(row) }}
                  </span>
                  <PayrollQuickFieldState
                    v-if="fieldState(row, 'base')"
                    :data-test="fieldManaged(row, 'base') ? `quick-base-managed-link-mobile-${row.employment_id}` : undefined"
                    :state="fieldState(row, 'base')!"
                    :message="fieldStateMessage(row, 'base')"
                    :label="fieldStateShort(row, 'base')"
                    :to="fieldManaged(row, 'base') ? componentsLink(row) : null"
                    :link-hint="fieldManaged(row, 'base') ? t('payroll.quick_inputs.blockers.base_managed_elsewhere_link') : null"
                  />
                </div>
                <span
                  v-if="row.base_managed_elsewhere"
                  :class="[READONLY_VALUE_CLASS, 'h-10 w-full']"
                >{{ managedDisplay(row, 'base') }}</span>
                <input
                  v-else
                  :data-testid="`quick-base-mobile-${row.employment_id}`"
                  v-model="row.baseAmount"
                  type="text"
                  inputmode="decimal"
                  autocomplete="off"
                  :aria-labelledby="`quick-income-label-mobile-${row.employment_id}`"
                  :aria-invalid="baseError(row) !== null"
                  :class="[fieldClass(baseError(row)), 'w-full', baseSuggested(row) ? SUGGESTED_FIELD_CLASS : '']"
                  :disabled="loading || saving || !canWrite || rowClosed(row) || row.hourly_remuneration_in_period || !editable(row.inputs.base)"
                  @input="markDirty(row)"
                >
                <span v-if="baseSuggested(row)" class="mt-1 block text-xs text-warning-700">
                  {{ t('payroll.quick_inputs.unsaved.cell') }}
                </span>
                <span v-if="baseError(row)" class="mt-1 block text-xs text-danger-700">
                  {{ validationMessage(baseError(row)) }}
                </span>
                <span v-if="serverError(row, 'base') || serverError(row, 'row')" class="mt-1 block text-xs font-medium text-danger-700">
                  {{ serverError(row, 'base') ?? serverError(row, 'row') }}
                </span>
                <span
                  v-if="prorationHint(row)"
                  :data-testid="`quick-base-proration-mobile-${row.employment_id}`"
                  class="mt-1 block text-xs text-neutral-600"
                >
                  {{ prorationHint(row) }}
                </span>
              </div>
              <div>
                <div class="mb-1 flex items-center justify-between gap-2">
                  <span :id="`quick-bonus-label-mobile-${row.employment_id}`" class="text-xs font-medium text-neutral-600">{{ t('payroll.quick_inputs.bonus_amount') }}</span>
                  <PayrollQuickFieldState
                    v-if="fieldState(row, 'bonus')"
                    :state="fieldState(row, 'bonus')!"
                    :message="fieldStateMessage(row, 'bonus')"
                    :label="fieldStateShort(row, 'bonus')"
                    :to="fieldManaged(row, 'bonus') ? componentsLink(row) : null"
                    :link-hint="fieldManaged(row, 'bonus') ? t('payroll.quick_inputs.blockers.base_managed_elsewhere_link') : null"
                  />
                </div>
                <span
                  v-if="row.bonus_managed_elsewhere"
                  :class="[READONLY_VALUE_CLASS, 'h-10 w-full']"
                >{{ managedDisplay(row, 'bonus') }}</span>
                <input
                  v-else
                  v-model="row.bonusAmount"
                  type="text"
                  inputmode="decimal"
                  autocomplete="off"
                  :aria-labelledby="`quick-bonus-label-mobile-${row.employment_id}`"
                  :aria-invalid="bonusError(row) !== null"
                  :class="[fieldClass(bonusError(row)), 'w-full']"
                  :disabled="loading || saving || !canWrite || rowClosed(row) || !editable(row.inputs.bonus)"
                  @input="markDirty(row)"
                >
                <span v-if="bonusError(row)" class="mt-1 block text-xs text-danger-700">
                  {{ validationMessage(bonusError(row)) }}
                </span>
                <span v-if="serverError(row, 'bonus')" class="mt-1 block text-xs font-medium text-danger-700">
                  {{ serverError(row, 'bonus') }}
                </span>
              </div>
              <div class="sm:col-span-2">
                <div class="mb-1 flex items-center justify-between gap-2">
                  <span class="text-xs font-medium text-neutral-600">{{ additionalIncomeLabel(row) }}</span>
                  <PayrollQuickFieldState
                    v-if="fieldState(row, 'overtime')"
                    :state="fieldState(row, 'overtime')!"
                    :message="fieldStateMessage(row, 'overtime')"
                    :label="fieldStateShort(row, 'overtime')"
                    :to="fieldManaged(row, 'overtime') ? componentsLink(row) : null"
                    :link-hint="fieldManaged(row, 'overtime') ? t('payroll.quick_inputs.blockers.base_managed_elsewhere_link') : null"
                  />
                </div>
                <span
                  v-if="row.overtime_managed_elsewhere"
                  :class="[READONLY_VALUE_CLASS, 'h-10 w-full']"
                >{{ managedDisplay(row, 'overtime') }}</span>
                <div v-else class="flex flex-wrap gap-2" role="group" :aria-label="t('payroll.quick_inputs.overtime_mode')">
                  <template v-if="row.overtime_hours_relation_supported">
                    <button type="button" class="cursor-pointer"
  :class="modeButtonClass(row.overtime_mode === 'hours')" :aria-pressed="row.overtime_mode === 'hours'" :disabled="loading || saving || !canWrite || !row.overtime_hours_available || !editable(row.inputs.overtime)" @click="setOvertimeMode(row, 'hours')">{{ t('payroll.quick_inputs.hours') }}</button>
                    <button type="button" class="cursor-pointer"
  :class="modeButtonClass(row.overtime_mode === 'amount')" :aria-pressed="row.overtime_mode === 'amount'" :disabled="loading || saving || !canWrite || rowClosed(row) || !editable(row.inputs.overtime)" @click="setOvertimeMode(row, 'amount')">{{ t('payroll.quick_inputs.total_amount') }}</button>
                  </template>
                  <input
                    v-if="row.overtime_hours_relation_supported && row.overtime_mode === 'hours'"
                    v-model="row.overtimeHours"
                    type="text"
                    inputmode="decimal"
                    autocomplete="off"
                    :aria-label="t('payroll.quick_inputs.overtime_hours')"
                    :aria-invalid="overtimeError(row) !== null"
                    :class="[fieldClass(overtimeError(row)), 'min-w-0 flex-1']"
                    :disabled="loading || saving || !canWrite || rowClosed(row) || !editable(row.inputs.overtime)"
                    @input="markDirty(row)"
                  >
                  <input
                    v-else
                    v-model="row.overtimeAmount"
                    type="text"
                    inputmode="decimal"
                    autocomplete="off"
                    :aria-label="additionalIncomeLabel(row)"
                    :aria-invalid="overtimeError(row) !== null"
                    :class="[fieldClass(overtimeError(row)), 'min-w-0 flex-1']"
                    :disabled="loading || saving || !canWrite || rowClosed(row) || !editable(row.inputs.overtime)"
                    @input="markDirty(row)"
                  >
                </div>
                <p v-if="overtimeError(row)" class="mt-1 text-xs text-danger-700">
                  {{ validationMessage(overtimeError(row)) }}
                </p>
                <p v-if="serverError(row, 'overtime')" class="mt-1 text-xs font-medium text-danger-700">
                  {{ serverError(row, 'overtime') }}
                </p>
                <p v-else-if="!row.overtime_managed_elsewhere && row.overtime_mode === 'hours' && row.overtime_hourly_rate_minor" class="mt-1 text-xs text-neutral-500">
                  {{ t('payroll.quick_inputs.rate_hint', { rate: formatMoney(row.overtime_hourly_rate_minor) }) }}
                </p>
                <p v-if="!row.overtime_hours_relation_supported" class="mt-1 text-xs text-neutral-500">{{ t('payroll.quick_inputs.amount_only_relation_hint') }}</p>
                <p
                  v-if="overtimeSplit(row)"
                  :data-testid="`quick-overtime-split-mobile-${row.employment_id}`"
                  class="mt-1 text-xs text-neutral-500"
                >
                  {{ t('payroll.quick_inputs.overtime_split', {
                    wage: formatMoney(overtimeSplit(row)!.wage),
                    premium: formatMoney(overtimeSplit(row)!.premium),
                  }) }}
                </p>
              </div>
              <!--
                Na mobilu jsou příplatky PODSEKCÍ karty, ne dalšími sloupci.
                Čtyři sloupce navíc by tabulku poslaly do vodorovného scrollu
                a v něm se vyplňují mzdy mizerně; svislý seznam v kartě je
                stejná informace bez posouvání.
              -->
              <fieldset
                v-if="surchargesVisible"
                class="sm:col-span-2 rounded-lg border border-neutral-200 p-3"
                data-testid="quick-surcharges-mobile"
              >
                <legend class="px-1 text-xs font-medium text-neutral-600">
                  {{ t('payroll.quick_inputs.surcharges.toggle') }}
                </legend>
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                  <label v-for="kind in visibleSurchargeKinds" :key="kind" class="block">
                    <span class="mb-1 block text-xs font-medium text-neutral-600">
                      {{ t(`payroll.quick_inputs.surcharges.kinds.${kind}`) }}
                      <span class="text-neutral-400">{{ t(`payroll.quick_inputs.surcharges.sections.${kind}`) }}</span>
                    </span>
                    <input
                      :data-testid="`quick-surcharge-mobile-${kind}-${row.employment_id}`"
                      v-model="row.surchargeHours[kind]"
                      type="text"
                      inputmode="decimal"
                      autocomplete="off"
                      :aria-label="t('payroll.quick_inputs.surcharges.hours_label', {
                        kind: t(`payroll.quick_inputs.surcharges.kinds.${kind}`),
                      })"
                      :aria-invalid="surchargeHoursError(row, kind) !== null"
                      :aria-describedby="surchargeUnavailableKey(row, kind) ? `quick-surcharge-reason-mobile-${kind}-${row.employment_id}` : undefined"
                      :title="surchargeUnavailableText(row, kind) || undefined"
                      :class="[fieldClass(surchargeHoursError(row, kind)), 'w-full']"
                      :placeholder="surchargePlaceholder(row, kind) ?? t('payroll.quick_inputs.surcharges.hours_placeholder')"
                      :disabled="loading || saving || !canWrite || !surchargeEditable(row, kind)"
                      @input="markDirty(row)"
                    >
                    <span
                      v-if="surchargeState(row, kind)?.requires_factors"
                      class="mt-2 flex items-center justify-between gap-2 text-xs text-neutral-500"
                    >
                      {{ t('payroll.quick_inputs.surcharges.factors') }}
                      <input
                        v-model="row.surchargeFactors[kind]"
                        type="text"
                        inputmode="numeric"
                        autocomplete="off"
                        :aria-label="t('payroll.quick_inputs.surcharges.factors')"
                        :aria-invalid="surchargeFactorsError(row, kind) !== null"
                        :class="[fieldClass(surchargeFactorsError(row, kind), true), 'w-16 shrink-0']"
                        :disabled="loading || saving || !canWrite || !surchargeEditable(row, kind)"
                        @input="markDirty(row)"
                      >
                    </span>
                    <span
                      v-if="surchargeHoursError(row, kind) || surchargeFactorsError(row, kind)"
                      class="mt-1 block text-xs text-danger-700"
                    >
                      {{ validationMessage(surchargeHoursError(row, kind)
                        ?? surchargeFactorsError(row, kind)) }}
                    </span>
                    <span
                      v-else-if="surchargePreview(row, kind)"
                      class="mt-1 block text-xs font-medium text-payroll-700"
                    >
                      {{ formatMoney(surchargePreview(row, kind)) }}
                    </span>
                    <span
                      v-if="serverError(row, `surcharge_${kind}`)"
                      class="mt-1 block text-xs font-medium text-danger-700"
                    >
                      {{ serverError(row, `surcharge_${kind}`) }}
                    </span>
                    <!-- Společný důvod je v pruhu nad kartami, u pole jen pro odečítače. -->
                    <span
                      v-if="surchargeUnavailableKey(row, kind)"
                      :id="`quick-surcharge-reason-mobile-${kind}-${row.employment_id}`"
                      class="sr-only"
                    >{{ surchargeUnavailableText(row, kind) }}</span>
                  </label>
                </div>
              </fieldset>
              <!-- Na mobilu jsou zvolené složky podsekcí karty, stejně jako příplatky. -->
              <fieldset
                v-if="visibleComponentColumns.length"
                class="sm:col-span-2 rounded-lg border border-neutral-200 p-3"
                data-testid="quick-components-mobile"
              >
                <legend class="px-1 text-xs font-medium text-neutral-600">
                  {{ t('payroll.quick_inputs.columns_menu.button') }}
                </legend>
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                  <div v-for="column in visibleComponentColumns" :key="column.code">
                    <div class="mb-1 flex items-center justify-between gap-2">
                      <span class="truncate text-xs font-medium text-neutral-600">{{ column.name }}</span>
                      <span
                        v-if="componentSourceIcon(row, column.code)"
                        :title="componentSourceTitle(row, column)"
                        class="inline-flex shrink-0 items-center gap-1 text-xs"
                        :class="componentCell(row, column.code)?.mode === 'override' ? 'text-warning-700' : 'text-neutral-500'"
                      >
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="componentSourceIcon(row, column.code)!" /></svg>
                        {{ t(`payroll.quick_inputs.component_cell.source.${componentCell(row, column.code)?.source ?? 'manual'}`) }}
                      </span>
                    </div>
                    <input
                      v-if="componentEditable(row, column)"
                      v-model="row.componentAmounts[column.code]"
                      :data-testid="`quick-component-mobile-${column.code}-${row.employment_id}`"
                      type="text"
                      inputmode="decimal"
                      autocomplete="off"
                      :aria-label="t('payroll.quick_inputs.component_cell.label', { name: column.name })"
                      :aria-invalid="componentError(row, column) !== null"
                      :class="[fieldClass(componentError(row, column) === null ? null : 'amount_format'), 'w-full']"
                      :disabled="loading || saving"
                      @input="onComponentInput(row, column.code)"
                    >
                    <span v-else :class="[READONLY_VALUE_CLASS, 'h-10 w-full']">{{ componentDisplay(row, column.code) }}</span>
                    <span v-if="componentSourceTitle(row, column) && componentCell(row, column.code)?.mode === 'override'" class="mt-1 block text-xs text-neutral-600">
                      {{ componentSourceTitle(row, column) }}
                    </span>
                    <button
                      v-if="componentRevertable(row, column)"
                      type="button"
                      :class="[btnOutline('warning'), 'mt-2 w-full']"
                      :disabled="loading || saving"
                      @click="requestRevert(row, column.code)"
                    >
                      <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.cycle" /></svg>
                      {{ t('payroll.quick_inputs.component_cell.revert') }}
                    </button>
                    <span v-if="row.componentRevert[column.code]" class="mt-1 block text-xs text-warning-700">
                      {{ t('payroll.quick_inputs.component_cell.revert_pending', {
                        value: formatMoney(componentCell(row, column.code)?.override_of?.amount_minor ?? 0),
                      }) }}
                    </span>
                    <span v-if="componentError(row, column)" class="mt-1 block text-xs text-danger-700">
                      {{ componentErrorMessage(componentError(row, column)) }}
                    </span>
                    <span v-if="serverError(row, `component:${column.code}`)" class="mt-1 block text-xs font-medium text-danger-700">
                      {{ serverError(row, `component:${column.code}`) }}
                    </span>
                  </div>
                </div>
              </fieldset>
            </div>
          </article>
        </div>

        <!-- Zúžení mění i `total`, takže pager mluví o zúženém seznamu. -->
        <PaginationBar
          embedded
          :page="currentPage"
          :per-page="pageSize"
          :total="total"
          @update:page="goToPage"
        />
      </template>
    </section>

    <div v-if="rows.length && !historyMode" class="flex flex-wrap justify-end gap-2 lg:sticky lg:bottom-4">
      <RouterLink
        :to="{ name: 'payroll-runs' }"
        :class="[btnOutline('primary'), 'w-full sm:w-auto']"
        data-testid="quick-payroll-runs"
      >
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.chart" /></svg>
        {{ t('payroll.quick_inputs.continue_to_runs') }}
      </RouterLink>
      <!--
        Věta nad tlačítky, ne pod nimi: v přilepené liště je „Uložit vše"
        poslední, co uživatel na obrazovce vidí, takže vysvětlení musí přijít
        dřív než ono. `order-first` + `basis-full` ji drží na vlastním řádku.
      -->
      <p v-if="canWrite && saveBlockedReason" :class="[BTN_DISABLED_NOTE, 'order-first basis-full sm:text-right']" data-testid="quick-payroll-save-blocked">
        {{ saveBlockedReason }}
      </p>
      <!--
        Ne blokace, ale upozornění: uloží se to, co jde, a tohle je zbytek.
        Bez téhle věty by uživatel čekal, že „Uložit vše" uloží úplně všechno.
      -->
      <p v-else-if="canWrite && savePartialNote" :class="[BTN_DISABLED_NOTE, 'order-first basis-full sm:text-right']" data-testid="quick-payroll-save-partial">
        {{ savePartialNote }}
      </p>
      <button v-if="canWrite" data-testid="quick-payroll-save" :class="[btnFilled('primary'), 'w-full sm:w-auto']" :disabled="saving || loading || loadedPeriod !== period || rows.length === 0 || savableRows.length === 0 || saveBlockedReason !== null" :title="disabledTitle(saveBlockedReason !== null, saveBlockedReason)" @click="save">
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.check" /></svg>
        {{ t('payroll.quick_inputs.save_all') }}
      </button>
    </div>
  </div>
</template>

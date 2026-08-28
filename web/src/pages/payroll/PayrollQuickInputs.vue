<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import {
  payrollApi,
  type PayrollQuickInputFailure,
  type PayrollQuickInputRef,
  type PayrollQuickInputRow,
  type PayrollQuickInputSavePayload,
} from '@/api/payroll'
import { apiErrorMessage } from '@/api/errors'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { btnFilled, btnOutline, disabledTitle, BTN_DISABLED_NOTE, ICONS } from '@/components/ui/buttonStyles'
import EmptyState from '@/components/ui/EmptyState.vue'
import PaginationBar from '@/components/ui/PaginationBar.vue'
import PayrollFocusNotice from '@/components/payroll/PayrollFocusNotice.vue'
import { payrollQueryId } from '@/pages/payroll/payrollAgendaLinks'
import ColumnPicker from '@/components/ui/ColumnPicker.vue'
import DensityToggle from '@/components/ui/DensityToggle.vue'
import { useTablePrefs, type ColumnDef } from '@/composables/useTablePrefs'
import {
  localPayrollPeriod,
  parsePayrollAmountToMinor,
  parsePayrollHoursToMilli,
  payrollMinorToInput,
} from '@/pages/payroll/payrollComponentsUi'
// Formátování je sdílené (useFormat) — místní kopie se rozcházely v locale i tvaru.
import { formatMoneyMinor } from '@/composables/useFormat'

interface UiRow extends PayrollQuickInputRow {
  baseAmount: string
  overtimeHours: string
  overtimeAmount: string
  bonusAmount: string
}

type ValidationCode =
  | 'amount_required'
  | 'amount_format'
  | 'amount_non_negative'
  | 'amount_limit'
  | 'hours_required'
  | 'hours_format'
  | 'hours_non_negative'
  | 'hours_limit'

const MAX_AMOUNT_MINOR = 1_000_000_000_000
const MAX_OVERTIME_HOURS_MILLI = 1_000_000
const { t } = useI18n()
const auth = useAuthStore()
const toast = useToast()
const route = useRoute()
const router = useRouter()
const period = ref(localPayrollPeriod())
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
 * Lišta se zúžením musí být vidět i tehdy, když zúžení nedalo nic. Bez ní zůstane
 * prázdná tabulka a uživatel nemá jak poznat, že se dívá na zúžený seznam — ani
 * jak se ze zúžení dostat.
 */
const focusName = computed(() => {
  if (focusEmploymentId.value === null) return null
  return rows.value.length > 0
    ? rows.value[0].full_name
    : t('payroll.agendas.focus.unknown_person')
})
/** Server zúžení uplatnil a nezbylo nic — prázdno se musí pojmenovat, ne mlčet. */
const focusMissing = computed(() =>
  focusEmploymentId.value !== null && !loading.value && !loadFailed.value
  && loadedPeriod.value === period.value && rows.value.length === 0)

function clearFocus(): void {
  focusEmploymentId.value = null
  const query = { ...route.query }
  delete query.employment
  void router.replace({ query })
  reload()
}

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

function serverError(row: UiRow, field: 'row' | 'base' | 'overtime' | 'bonus'): string | null {
  return fieldErrors.value[fieldErrorKey(row.employment_id, field)] ?? null
}

/** Zapamatuje si rozepsaný řádek, ať přežije přelistování na jinou stránku. */
function markDirty(row: UiRow): void {
  pending.value.set(row.employment_id, row)
  clearSaveError()
  for (const field of ['row', 'base', 'overtime', 'bonus']) {
    delete fieldErrors.value[fieldErrorKey(row.employment_id, field)]
  }
}

/** Rozepsané řádky mimo právě zobrazenou stránku. */
const pendingElsewhere = computed(() => {
  const onPage = new Set(rows.value.map(row => row.employment_id))
  return Array.from(pending.value.values()).filter(row => !onPage.has(row.employment_id))
})

function toUi(row: PayrollQuickInputRow): UiRow {
  return {
    ...row,
    overtime_mode: row.overtime_hours_relation_supported ? row.overtime_mode : 'amount',
    baseAmount: row.base_requires_entry ? '' : payrollMinorToInput(row.base_amount_minor),
    overtimeHours: row.overtime_hours_milli === null
      ? ''
      : String(row.overtime_hours_milli / 1000),
    overtimeAmount: payrollMinorToInput(row.overtime_amount_minor),
    bonusAmount: payrollMinorToInput(row.bonus_amount_minor),
  }
}

function formatMoney(value: number): string {
  return formatMoneyMinor(value)
}

/*
 * Kdo smí schvalovat, ukládá rovnou schválené vstupy — a musel by si tím
 * první uloženou částkou zabetonovat vlastní řádek, kdyby schválené pole
 * zůstalo zamčené. Dokud vstup nepohltil mzdový běh (`locked`), jde ho
 * opravit; server ho na tu dobu vrátí do konceptu a schválí znovu.
 *
 * Bez práva schvalovat platí původní pravidlo: upravit jde jen koncept.
 */
function editable(input: PayrollQuickInputRef | null): boolean {
  if (input === null || input.status === 'draft') return true
  return input.status === 'approved' && canApprove.value
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

function fieldStateClass(state: ReturnType<typeof fieldState>): string {
  if (state === 'draft') return 'text-payroll-700'
  if (state === 'managed') return 'text-warning-700'
  return 'text-neutral-500'
}

function fieldStateMessage(row: UiRow, kind: 'base' | 'overtime' | 'bonus'): string {
  const state = fieldState(row, kind)
  return state === null ? '' : t(`payroll.quick_inputs.field_state.${state}`)
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
  .filter(row => !rowInvalid(row)))

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
    + Number(bonusError(row) !== null),
  0,
))

function validationMessage(code: ValidationCode | null): string {
  return code === null ? '' : t(`payroll.quick_inputs.validation.${code}`)
}

function fieldClass(error: ValidationCode | null, alignRight = false): string[] {
  return [
    'h-10 rounded-md border bg-surface px-3 text-sm text-neutral-900 outline-none',
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
    + row.other_amount_minor
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
    )
    if (generation !== loadGeneration || period.value !== requestedPeriod
      || month.period !== requestedPeriod) return
    rows.value = month.items.map(applyPending)
    // `total` už je zúžené serverem, takže pager mluví o tom, co tabulka ukazuje.
    total.value = month.total
    loadedPeriod.value = requestedPeriod
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
      versions: {
        base: row.inputs.base?.row_version ?? null,
        overtime: row.inputs.overtime?.row_version ?? null,
        bonus: row.inputs.bonus?.row_version ?? null,
      },
    })),
  }
}

async function save(): Promise<void> {
  if (loadedPeriod.value !== period.value || rows.value.length === 0) {
    return
  }
  const batch = savableRows.value
  if (batch.length === 0) {
    toast.error(t('payroll.quick_inputs.validation_failed'))
    return
  }
  const requestedPeriod = period.value
  const generation = loadGeneration
  saving.value = true
  saveError.value = null
  saveConflict.value = false
  fieldErrors.value = {}
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

onMounted(load)
</script>

<template>
  <div class="space-y-6">
    <header class="flex flex-wrap items-end justify-between gap-4">
      <div>
        <h1 class="text-2xl font-semibold text-neutral-900">{{ t('payroll.quick_inputs.title') }}</h1>
        <p class="mt-1 max-w-3xl text-sm text-neutral-500">{{ t('payroll.quick_inputs.subtitle') }}</p>
      </div>
      <div class="flex flex-wrap items-end gap-2">
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

    <div class="rounded-xl border border-payroll-500/30 bg-payroll-50 p-4 text-sm text-neutral-700">
      <p>{{ t('payroll.quick_inputs.info') }}</p>
      <p class="mt-1 font-medium text-payroll-800">{{ t('payroll.quick_inputs.gross_preview_hint') }}</p>
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
      v-if="rows.length && hasInvalidRows"
      class="rounded-xl border border-danger-200 bg-danger-50 p-4 text-sm text-danger-800"
      role="alert"
      data-testid="quick-payroll-validation-summary"
    >
      {{ t('payroll.quick_inputs.validation_summary', { count: invalidFieldCount }) }}
    </div>

    <PayrollFocusNotice
      v-if="focusMissing"
      :name="String(focusEmploymentId)"
      missing
      @clear="clearFocus"
    />
    <PayrollFocusNotice
      v-else-if="focusName"
      :name="focusName"
      @clear="clearFocus"
    />

    <!--
      Prázdno po zúžení pojmenovává už lišta nad seznamem. Generický prázdný
      stav pod ní by totéž řekl podruhé, a ještě obecněji.
    -->
    <section v-if="!focusMissing" class="overflow-hidden rounded-xl border border-neutral-200 bg-surface shadow-sm">
      <div v-if="loading" class="p-8 text-center text-sm text-neutral-500">{{ t('common.loading') }}</div>
      <EmptyState
        v-else-if="loadFailed"
        variant="failed"
        dense
        data-test="load-failed"
        :message="t('payroll.quick_inputs.load_failed_hint')"
        @action="load"
      />
      <div v-else-if="rows.length === 0" class="p-8 text-center">
        <h2 class="font-semibold text-neutral-900">{{ t('payroll.quick_inputs.empty') }}</h2>
        <p class="mt-1 text-sm text-neutral-500">{{ t('payroll.quick_inputs.empty_hint') }}</p>
      </div>
      <template v-else>
        <div class="hidden flex-wrap items-center justify-end gap-2 border-b border-neutral-200 px-4 py-2 lg:flex">
          <ColumnPicker :ctrl="tbl" />
          <DensityToggle :ctrl="tbl" />
        </div>
        <div data-layout="desktop" class="hidden overflow-x-auto lg:block">
          <table class="min-w-[1120px] w-full divide-y divide-neutral-200 text-sm" :class="tbl.densityClass.value">
            <thead>
              <tr class="text-left text-xs uppercase tracking-wide text-neutral-500">
                <th v-if="tbl.isVisible('person')" class="px-4 py-3">{{ t('payroll.quick_inputs.person') }}</th>
                <th v-if="tbl.isVisible('income_amount')" class="px-4 py-3">{{ t('payroll.quick_inputs.income_amount') }}</th>
                <th v-if="tbl.isVisible('overtime')" class="px-4 py-3">{{ t('payroll.quick_inputs.overtime') }}</th>
                <th v-if="tbl.isVisible('bonus_amount')" class="px-4 py-3">{{ t('payroll.quick_inputs.bonus_amount') }}</th>
                <th v-if="tbl.isVisible('gross_preview')" class="px-4 py-3 text-right">{{ t('payroll.quick_inputs.gross_preview') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="row in rows" :key="row.employment_id" class="align-top">
                <td v-if="tbl.isVisible('person')" class="px-4 py-4">
                  <p class="font-semibold text-neutral-900">{{ row.full_name }}</p>
                  <p class="mt-0.5 text-xs text-neutral-500">{{ row.birth_number_masked ?? t('payroll.quick_inputs.identifier_missing') }}</p>
                  <p class="mt-1 text-xs text-neutral-500">{{ row.employment_code }}</p>
                  <span
                    :data-testid="`quick-relation-${row.employment_id}`"
                    class="mt-2 inline-flex rounded-full bg-payroll-50 px-2 py-1 text-xs font-medium text-payroll-700"
                  >
                    {{ relationLabel(row) }}
                  </span>
                  <span
                    v-if="row.effective_status !== 'active' || row.suspended_in_month"
                    :data-testid="`quick-status-${row.employment_id}`"
                    class="ml-1 mt-2 inline-flex rounded-full bg-warning-50 px-2 py-1 text-xs font-medium text-warning-700"
                  >
                    {{ employmentStatusLabel(row) }}
                  </span>
                  <p v-for="blocker in row.blockers" :key="blocker" class="mt-2 max-w-xs text-xs text-warning-700">
                    {{ t(`payroll.quick_inputs.blockers.${blocker}`) }}
                  </p>
                </td>
                <td v-if="tbl.isVisible('income_amount')" class="px-4 py-4">
                  <p
                    :data-testid="`quick-income-label-${row.employment_id}`"
                    class="mb-1 text-xs font-medium text-neutral-600"
                  >
                    {{ incomeLabel(row) }}
                  </p>
                  <input
                    :data-testid="`quick-base-${row.employment_id}`"
                    v-model="row.baseAmount"
                    type="text"
                    inputmode="decimal"
                    autocomplete="off"
                    :aria-label="incomeLabel(row)"
                    :aria-invalid="baseError(row) !== null"
                    :aria-describedby="baseError(row) ? `quick-base-error-${row.employment_id}` : undefined"
                    :class="[fieldClass(baseError(row), true), 'w-36']"
                    :disabled="loading || saving || !canWrite || row.base_managed_elsewhere || !editable(row.inputs.base)"
                    @input="markDirty(row)"
                  >
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
                    v-if="fieldState(row, 'base')"
                    :data-testid="`quick-base-state-${row.employment_id}`"
                    :class="['mt-1 max-w-48 text-xs', fieldStateClass(fieldState(row, 'base'))]"
                  >
                    {{ fieldStateMessage(row, 'base') }}
                  </p>
                </td>
                <td v-if="tbl.isVisible('overtime')" class="px-4 py-4">
                  <p class="mb-1 text-xs font-medium text-neutral-600">{{ additionalIncomeLabel(row) }}</p>
                  <div
                    v-if="row.overtime_hours_relation_supported"
                    class="flex flex-wrap gap-1"
                    role="group"
                    :aria-label="t('payroll.quick_inputs.overtime_mode')"
                  >
                    <button
                      :data-testid="`overtime-mode-hours-${row.employment_id}`"
                      type="button"
                      :class="modeButtonClass(row.overtime_mode === 'hours')"
                      :aria-pressed="row.overtime_mode === 'hours'"
                      :disabled="loading || saving || !canWrite || row.overtime_managed_elsewhere || !row.overtime_hours_available || !editable(row.inputs.overtime)"
                      @click="setOvertimeMode(row, 'hours')"
                    >{{ t('payroll.quick_inputs.hours') }}</button>
                    <button
                      type="button"
                      :class="modeButtonClass(row.overtime_mode === 'amount')"
                      :aria-pressed="row.overtime_mode === 'amount'"
                      :disabled="loading || saving || !canWrite || row.overtime_managed_elsewhere || !editable(row.inputs.overtime)"
                      @click="setOvertimeMode(row, 'amount')"
                    >{{ t('payroll.quick_inputs.total_amount') }}</button>
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
                    :class="[fieldClass(overtimeError(row), true), 'mt-2 w-36']"
                    :disabled="loading || saving || !canWrite || row.overtime_managed_elsewhere || !editable(row.inputs.overtime)"
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
                    :class="[fieldClass(overtimeError(row), true), 'mt-2 w-36']"
                    :disabled="loading || saving || !canWrite || row.overtime_managed_elsewhere || !editable(row.inputs.overtime)"
                    @input="markDirty(row)"
                  >
                  <p
                    v-if="overtimeError(row)"
                    :id="`quick-overtime-error-${row.employment_id}`"
                    class="mt-1 max-w-56 text-xs text-danger-700"
                  >
                    {{ validationMessage(overtimeError(row)) }}
                  </p>
                  <p v-if="row.overtime_hours_relation_supported && row.overtime_mode === 'hours' && row.overtime_hourly_rate_minor" class="mt-1 text-xs text-neutral-500">
                    {{ t('payroll.quick_inputs.rate_hint', { rate: formatMoney(row.overtime_hourly_rate_minor) }) }}
                  </p>
                  <p v-else-if="row.overtime_hours_relation_supported && !row.overtime_hours_available" class="mt-1 max-w-xs text-xs text-warning-700">
                    {{ t('payroll.quick_inputs.hours_unavailable') }}
                  </p>
                  <p v-else-if="!row.overtime_hours_relation_supported" class="mt-1 max-w-xs text-xs text-neutral-500">
                    {{ t('payroll.quick_inputs.amount_only_relation_hint') }}
                  </p>
                  <p
                    v-if="serverError(row, 'overtime')"
                    :data-testid="`quick-overtime-server-error-${row.employment_id}`"
                    class="mt-1 max-w-56 text-xs font-medium text-danger-700"
                  >
                    {{ serverError(row, 'overtime') }}
                  </p>
                  <p
                    v-if="fieldState(row, 'overtime')"
                    :data-testid="`quick-overtime-state-${row.employment_id}`"
                    :class="['mt-1 max-w-56 text-xs', fieldStateClass(fieldState(row, 'overtime'))]"
                  >
                    {{ fieldStateMessage(row, 'overtime') }}
                  </p>
                </td>
                <td v-if="tbl.isVisible('bonus_amount')" class="px-4 py-4">
                  <input
                    v-model="row.bonusAmount"
                    type="text"
                    inputmode="decimal"
                    autocomplete="off"
                    :data-testid="`quick-bonus-${row.employment_id}`"
                    :aria-label="t('payroll.quick_inputs.bonus_amount')"
                    :aria-invalid="bonusError(row) !== null"
                    :aria-describedby="bonusError(row) ? `quick-bonus-error-${row.employment_id}` : undefined"
                    :class="[fieldClass(bonusError(row), true), 'w-36']"
                    :disabled="loading || saving || !canWrite || row.bonus_managed_elsewhere || !editable(row.inputs.bonus)"
                    @input="markDirty(row)"
                  >
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
                  <p
                    v-if="fieldState(row, 'bonus')"
                    :data-testid="`quick-bonus-state-${row.employment_id}`"
                    :class="['mt-1 max-w-48 text-xs', fieldStateClass(fieldState(row, 'bonus'))]"
                  >
                    {{ fieldStateMessage(row, 'bonus') }}
                  </p>
                </td>
                <td v-if="tbl.isVisible('gross_preview')" class="px-4 py-4 text-right">
                  <p class="text-base font-semibold text-neutral-900">{{ formatMoney(grossPreview(row)) }}</p>
                  <p v-if="row.other_amount_minor" class="mt-1 text-xs text-neutral-500">
                    {{ t('payroll.quick_inputs.other_inputs', { amount: formatMoney(row.other_amount_minor) }) }}
                  </p>
                  <p v-if="row.non_monetary_amount_minor" class="mt-1 text-xs text-neutral-500">
                    {{ t('payroll.quick_inputs.non_monetary_inputs', { amount: formatMoney(row.non_monetary_amount_minor) }) }}
                  </p>
                  <p v-if="row.excluded_from_gross_amount_minor" class="mt-1 text-xs text-neutral-500">
                    {{ t('payroll.quick_inputs.excluded_inputs', { amount: formatMoney(row.excluded_from_gross_amount_minor) }) }}
                  </p>
                  <p class="mt-1 max-w-52 text-xs text-neutral-500">{{ t('payroll.quick_inputs.gross_preview_short_hint') }}</p>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <div data-layout="mobile" class="space-y-4 p-4 lg:hidden">
          <article v-for="row in rows" :key="row.employment_id" class="rounded-xl border border-neutral-200 p-4">
            <div class="flex flex-wrap items-start justify-between gap-2">
              <div>
                <h2 class="font-semibold text-neutral-900">{{ row.full_name }}</h2>
                <p class="text-xs text-neutral-500">{{ row.birth_number_masked ?? t('payroll.quick_inputs.identifier_missing') }} · {{ row.employment_code }}</p>
                <span
                  :data-testid="`quick-relation-mobile-${row.employment_id}`"
                  class="mt-2 inline-flex rounded-full bg-payroll-50 px-2 py-1 text-xs font-medium text-payroll-700"
                >
                  {{ relationLabel(row) }}
                </span>
                <span
                  v-if="row.effective_status !== 'active' || row.suspended_in_month"
                  class="ml-1 mt-2 inline-flex rounded-full bg-warning-50 px-2 py-1 text-xs font-medium text-warning-700"
                >
                  {{ employmentStatusLabel(row) }}
                </span>
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
                <p class="mt-1 max-w-48 text-xs text-neutral-500">{{ t('payroll.quick_inputs.gross_preview_short_hint') }}</p>
              </div>
            </div>
            <p v-for="blocker in row.blockers" :key="blocker" class="mt-2 text-xs text-warning-700">
              {{ t(`payroll.quick_inputs.blockers.${blocker}`) }}
            </p>
            <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
              <label class="block">
                <span
                  :data-testid="`quick-income-label-mobile-${row.employment_id}`"
                  class="mb-1 block text-xs font-medium text-neutral-600"
                >
                  {{ incomeLabel(row) }}
                </span>
                <input
                  :data-testid="`quick-base-mobile-${row.employment_id}`"
                  v-model="row.baseAmount"
                  type="text"
                  inputmode="decimal"
                  autocomplete="off"
                  :aria-invalid="baseError(row) !== null"
                  :class="[fieldClass(baseError(row)), 'w-full']"
                  :disabled="loading || saving || !canWrite || row.base_managed_elsewhere || !editable(row.inputs.base)"
                  @input="markDirty(row)"
                >
                <span v-if="baseError(row)" class="mt-1 block text-xs text-danger-700">
                  {{ validationMessage(baseError(row)) }}
                </span>
                <span v-if="serverError(row, 'base') || serverError(row, 'row')" class="mt-1 block text-xs font-medium text-danger-700">
                  {{ serverError(row, 'base') ?? serverError(row, 'row') }}
                </span>
                <span
                  v-if="fieldState(row, 'base')"
                  :class="['mt-1 block text-xs', fieldStateClass(fieldState(row, 'base'))]"
                >
                  {{ fieldStateMessage(row, 'base') }}
                </span>
              </label>
              <label class="block">
                <span class="mb-1 block text-xs font-medium text-neutral-600">{{ t('payroll.quick_inputs.bonus_amount') }}</span>
                <input
                  v-model="row.bonusAmount"
                  type="text"
                  inputmode="decimal"
                  autocomplete="off"
                  :aria-invalid="bonusError(row) !== null"
                  :class="[fieldClass(bonusError(row)), 'w-full']"
                  :disabled="loading || saving || !canWrite || row.bonus_managed_elsewhere || !editable(row.inputs.bonus)"
                  @input="markDirty(row)"
                >
                <span v-if="bonusError(row)" class="mt-1 block text-xs text-danger-700">
                  {{ validationMessage(bonusError(row)) }}
                </span>
                <span v-if="serverError(row, 'bonus')" class="mt-1 block text-xs font-medium text-danger-700">
                  {{ serverError(row, 'bonus') }}
                </span>
                <span
                  v-if="fieldState(row, 'bonus')"
                  :class="['mt-1 block text-xs', fieldStateClass(fieldState(row, 'bonus'))]"
                >
                  {{ fieldStateMessage(row, 'bonus') }}
                </span>
              </label>
              <div class="sm:col-span-2">
                <span class="mb-1 block text-xs font-medium text-neutral-600">{{ additionalIncomeLabel(row) }}</span>
                <div class="flex flex-wrap gap-2" role="group" :aria-label="t('payroll.quick_inputs.overtime_mode')">
                  <template v-if="row.overtime_hours_relation_supported">
                    <button type="button" :class="modeButtonClass(row.overtime_mode === 'hours')" :aria-pressed="row.overtime_mode === 'hours'" :disabled="loading || saving || !canWrite || row.overtime_managed_elsewhere || !row.overtime_hours_available || !editable(row.inputs.overtime)" @click="setOvertimeMode(row, 'hours')">{{ t('payroll.quick_inputs.hours') }}</button>
                    <button type="button" :class="modeButtonClass(row.overtime_mode === 'amount')" :aria-pressed="row.overtime_mode === 'amount'" :disabled="loading || saving || !canWrite || row.overtime_managed_elsewhere || !editable(row.inputs.overtime)" @click="setOvertimeMode(row, 'amount')">{{ t('payroll.quick_inputs.total_amount') }}</button>
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
                    :disabled="loading || saving || !canWrite || row.overtime_managed_elsewhere || !editable(row.inputs.overtime)"
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
                    :disabled="loading || saving || !canWrite || row.overtime_managed_elsewhere || !editable(row.inputs.overtime)"
                    @input="markDirty(row)"
                  >
                </div>
                <p v-if="overtimeError(row)" class="mt-1 text-xs text-danger-700">
                  {{ validationMessage(overtimeError(row)) }}
                </p>
                <p v-if="serverError(row, 'overtime')" class="mt-1 text-xs font-medium text-danger-700">
                  {{ serverError(row, 'overtime') }}
                </p>
                <p v-else-if="row.overtime_mode === 'hours' && row.overtime_hourly_rate_minor" class="mt-1 text-xs text-neutral-500">
                  {{ t('payroll.quick_inputs.rate_hint', { rate: formatMoney(row.overtime_hourly_rate_minor) }) }}
                </p>
                <p v-if="row.overtime_hours_relation_supported && !row.overtime_hours_available" class="mt-1 text-xs text-warning-700">{{ t('payroll.quick_inputs.hours_unavailable') }}</p>
                <p v-else-if="!row.overtime_hours_relation_supported" class="mt-1 text-xs text-neutral-500">{{ t('payroll.quick_inputs.amount_only_relation_hint') }}</p>
                <p
                  v-if="fieldState(row, 'overtime')"
                  :class="['mt-1 text-xs', fieldStateClass(fieldState(row, 'overtime'))]"
                >
                  {{ fieldStateMessage(row, 'overtime') }}
                </p>
              </div>
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

    <div v-if="rows.length" class="flex flex-wrap justify-end gap-2 lg:sticky lg:bottom-4">
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
      <button v-if="canWrite" data-testid="quick-payroll-save" :class="[btnFilled('primary'), 'w-full sm:w-auto']" :disabled="saving || loading || loadedPeriod !== period || rows.length === 0 || savableRows.length === 0" :title="disabledTitle(saveBlockedReason !== null, saveBlockedReason)" @click="save">
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.check" /></svg>
        {{ t('payroll.quick_inputs.save_all') }}
      </button>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { btnFilled, btnOutline, disabledTitle, BTN_DISABLED_NOTE, ICONS } from '@/components/ui/buttonStyles'
// Formátování je sdílené (useFormat) — místní kopie se rozcházely v locale i tvaru.
import { formatMoneyMinor as money } from '@/composables/useFormat'
import PayrollPersonSearchSelect from '@/components/payroll/PayrollPersonSearchSelect.vue'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import PaginationBar from '@/components/ui/PaginationBar.vue'
import { apiErrorMessage } from '@/api/errors'
import {
  payrollAbsenceApi,
  type AbsencePayload,
  type AbsenceType,
  type AverageSnapshot,
  type LeaveEntry,
  type LeaveEntitlementCandidate,
  type PayrollAbsence,
  type PayrollAbsenceEmployment,
} from '@/api/payrollAbsences'

const { t } = useI18n()
const route = useRoute()
const toast = useToast()
const auth = useAuthStore()
const today = new Date()
const year = today.getFullYear()
const month = String(today.getMonth() + 1).padStart(2, '0')
const monthStart = `${year}-${month}-01`
function localDate(date: Date) {
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`
}
const monthEnd = localDate(new Date(year, today.getMonth() + 1, 0))
const applicationQuarter = Math.floor(today.getMonth() / 3) + 1
const applicationQuarterStartMonth = (applicationQuarter - 1) * 3
const decisiveFrom = localDate(new Date(year, applicationQuarterStartMonth - 3, 1))
const decisiveTo = localDate(new Date(year, applicationQuarterStartMonth, 0))

const loading = ref(true)
/*
 * Selhalo načtení? Pak o obsahu nevíme NIC — a to je něco jiného než „nic tu
 * není". Toast s chybou za pár vteřin zmizí a bez tohohle příznaku by na
 * obrazovce zůstal prázdný stav, který lže.
 */
const loadFailed = ref(false)
const saving = ref(false)
const tab = ref<'absences' | 'averages' | 'leave'>('absences')
const absenceError = ref('')
const averageError = ref('')
const entitlementError = ref('')
const entryError = ref('')
const employments = ref<PayrollAbsenceEmployment[]>([])
const absences = ref<PayrollAbsence[]>([])
const absenceTotal = ref(0)
const absencePageSize = 12
const absenceOffset = ref(0)
const currentAbsencePage = computed(() => Math.floor(absenceOffset.value / absencePageSize) + 1)
const averages = ref<AverageSnapshot[]>([])
const leaveEntries = ref<LeaveEntry[]>([])
const leaveBalance = ref(0)
const leaveCandidates = ref<LeaveEntitlementCandidate[]>([])
const leaveCandidateTotal = ref(0)
const leaveCandidateOffset = ref(0)
const leaveCandidatePageSize = 25
const leaveCandidateLoading = ref(false)
const leaveCandidateError = ref('')
const selectedLeaveCandidates = ref<number[]>([])
const selectedEmployeeId = ref<number | null>(null)
const selectedEmploymentId = ref<number | null>(null)
const filterFrom = ref(monthStart)
const filterTo = ref(monthEnd)
const leaveYear = ref(year)
const minimumFormYear = year - 5
const maximumFormYear = year + 2
const canWrite = computed(() => auth.canWrite('payroll.time.write'))
const leaveCandidatePage = computed(() => Math.floor(
  leaveCandidateOffset.value / leaveCandidatePageSize,
) + 1)
const leaveThrough = computed(() => leaveYear.value === year
  ? localDate(today)
  : `${leaveYear.value}-12-31`)
const selectedReadyCandidates = computed(() => leaveCandidates.value.filter(candidate =>
  candidate.ready && selectedLeaveCandidates.value.includes(candidate.employment_id)))
const fieldClass = 'mt-1 h-10 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm text-neutral-900 outline-none focus:border-payroll-500 focus:ring-2 focus:ring-payroll-500/20'
const textareaClass = 'mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm text-neutral-900 outline-none focus:border-payroll-500 focus:ring-2 focus:ring-payroll-500/20'
const absenceTypes: AbsenceType[] = [
  'vacation', 'dpn', 'quarantine', 'ocr', 'long_term_care', 'ppm',
  'paternity', 'parental', 'unpaid_leave', 'employee_obstacle',
  'employer_obstacle', 'compensatory_time_off', 'other',
]
const leaveEntryTypes = ['carryover', 'adjustment', 'shortening', 'overdrawn', 'payout']

const absenceForm = reactive({
  employment_id: 0,
  absence_type: 'vacation',
  date_from: monthStart,
  date_to: monthStart,
  timezone_name: 'Europe/Prague',
  partial_first_hours: null as number | null,
  partial_last_hours: null as number | null,
  average_snapshot_id: null,
  note: null,
})
const averageForm = reactive({
  employment_id: 0,
  applicable_year: year,
  applicable_quarter: applicationQuarter,
  decisive_from: decisiveFrom,
  decisive_to: decisiveTo,
  gross_earnings_czk: 0,
  longer_period_allocated_czk: 0,
  worked_hours: 0,
  worked_days: 0,
  probable_hourly_czk: null as number | null,
  rationale: '',
})
const entitlementForm = reactive({
  employment_id: 0,
  leave_year: year,
  weekly_hours: 40,
  entitlement_weeks: 4,
  continuous_calendar_days: 365,
  worked_equivalent_hours: 2080,
  rationale: '',
})
const entryForm = reactive({
  employment_id: 0,
  leave_year: year,
  effective_date: `${year}-01-01`,
  entry_type: 'adjustment',
  hours_delta: 1,
  reason: '',
})
const dpnReviews = reactive<Record<number, {
  firstDayFullyWorked: boolean
  insuranceConfirmed: boolean
  noConflictingBenefit: boolean
}>>({})

const approvedAverages = computed(() => averages.value.filter(item => item.status === 'approved'))
/*
 * Firma bez jediného pracovního vztahu. Celá stránka stojí na výběru
 * zaměstnance, takže filtr ani záložky nemají co ukazovat — místo prázdných
 * ovládacích prvků se vykreslí rozcestník do evidence osob.
 */
const hasNoEmployments = computed(() => !loading.value && !loadFailed.value && employments.value.length === 0)
const personOptions = computed(() => Array.from(
  new Map(employments.value.map(item => [item.employee_id, {
    value: item.employee_id,
    label: item.full_name,
  }])).values(),
))
const employmentOptions = computed(() => employments.value
  .filter(item => item.employee_id === selectedEmployeeId.value)
  .map(item => ({
    value: item.id,
    label: t(`payroll.people.relations.${item.relation_type}`),
    secondary: item.code,
  })))
const absenceTypeOptions = computed(() => absenceTypes.map(type => ({
  value: type,
  label: t(`payroll_absence.types.${type}`),
})))
const averageOptions = computed(() => approvedAverages.value.map(item => ({
  value: item.id,
  label: `${item.applicable_year}/Q${item.applicable_quarter}`,
  secondary: money(item.average_hourly_minor),
})))
const leaveEntryTypeOptions = computed(() => leaveEntryTypes.map(type => ({
  value: type,
  label: t(`payroll_absence.leave.types.${type}`),
})))
const needsAverage = computed(() =>
  ['vacation', 'dpn', 'quarantine', 'employee_obstacle', 'employer_obstacle']
    .includes(absenceForm.absence_type),
)
/*
 * Proč „Vytvořit" nejde zmáčknout. Dovolená, DPN a překážky se počítají
 * z průměrného výdělku, takže bez vybraného průměru nemá server z čeho počítat.
 * Rozlišujeme dva různé stavy: průměr existuje a jen není vybraný (uživatel ho
 * doplní tady) versus pro vztah žádný spočítaný není (musí se nejdřív spočítat
 * na záložce Průměry — bez odkazu tam uživatel netrefil).
 */
const missingAverage = computed(() =>
  needsAverage.value && absenceForm.average_snapshot_id === null)
const noAverageAvailable = computed(() =>
  missingAverage.value && averageOptions.value.length === 0)
const absenceBlockedReason = computed<string | null>(() => {
  if (!missingAverage.value) return null
  return noAverageAvailable.value
    ? t('payroll_absence.absences.average_missing_for_relation')
    : t('payroll_absence.absences.average_required_hint')
})

const canCreateAbsence = computed(() =>
  !saving.value && (!needsAverage.value || absenceForm.average_snapshot_id !== null),
)

function exactError(error: any, fallbackKey: string) {
  return apiErrorMessage(error, t(fallbackKey))
}

function validatedNumber(value: unknown, options: {
  nullable?: boolean
  positive?: boolean
  nonZero?: boolean
  signed?: boolean
} = {}): number | null {
  if ((value === null || value === '') && options.nullable) return null
  const number = Number(value)
  if (!Number.isFinite(number)
    || (!options.signed && (options.positive ? number <= 0 : number < 0))
    || (options.nonZero && number === 0)
  ) {
    throw new Error(t('payroll_absence.validation.number'))
  }
  return number
}

function toMinor(value: unknown, nullable = false, positive = false): number | null {
  const number = validatedNumber(value, { nullable, positive })
  if (number === null) return null
  const minor = Math.round(number * 100)
  if (Math.abs((number * 100) - minor) > 1e-7) {
    throw new Error(t('payroll_absence.validation.money_precision'))
  }
  return minor
}

function hoursToMinutes(
  value: unknown,
  options: { nullable?: boolean; positive?: boolean; nonZero?: boolean; signed?: boolean } = {},
): number | null {
  const number = validatedNumber(value, options)
  if (number === null) return null
  const minutes = Math.round(number * 60)
  if (Math.abs((number * 60) - minutes) > 1e-7) {
    throw new Error(t('payroll_absence.validation.hour_precision'))
  }
  return minutes
}

function wholeNumber(value: unknown, positive = false): number {
  const number = validatedNumber(value, { positive })
  if (number === null || !Number.isInteger(number)) {
    throw new Error(t('payroll_absence.validation.whole_number'))
  }
  return number
}

/**
 * Předvýběr z odkazu (`/payroll/absences?employment=12&type=vacation`).
 *
 * Why: na kartu zaměstnance patří tlačítko „Dovolená", ale druhá evidence
 * nepřítomností by byla chyba — odkaz proto míří sem a jen předvyplní vztah
 * a typ. Neplatná / cizí hodnota se tiše ignoruje, ať odkaz z bookmarku
 * stránku nerozbije.
 */
function queryParam(name: string): string | null {
  const value = route.query[name]
  const raw = Array.isArray(value) ? value[0] : value
  return typeof raw === 'string' && raw !== '' ? raw : null
}

function preselectedEmploymentId(): number | null {
  const raw = queryParam('employment')
  if (raw === null) return null
  const id = Number(raw)
  return employments.value.some(item => item.id === id) ? id : null
}

function preselectedAbsenceType(): AbsenceType | null {
  const raw = queryParam('type')
  return raw !== null && (absenceTypes as string[]).includes(raw)
    ? raw as AbsenceType
    : null
}

/**
 * Průměrný výdělek nemá vlastní routu — je to záložka tady, protože se z něj
 * počítá náhrada mzdy. Karta zaměstnance na něj proto odkazuje přes `?tab=`.
 */
const absenceTabs = ['absences', 'averages', 'leave'] as const

function preselectedTab(): (typeof absenceTabs)[number] | null {
  const raw = queryParam('tab')
  return raw !== null && (absenceTabs as readonly string[]).includes(raw)
    ? raw as (typeof absenceTabs)[number]
    : null
}

async function loadContext() {
  employments.value = await payrollAbsenceApi.context()
  const requestedTab = preselectedTab()
  if (requestedTab !== null) tab.value = requestedTab
  if (employments.value.length === 0 || selectedEmploymentId.value !== null) return
  const requestedEmploymentId = preselectedEmploymentId()
  const selectedEmployment = employments.value.find(
    item => item.id === requestedEmploymentId,
  ) ?? employments.value[0]
  selectedEmployeeId.value = selectedEmployment.employee_id
  selectedEmploymentId.value = selectedEmployment.id
  const type = preselectedAbsenceType()
  if (type !== null) {
    absenceForm.absence_type = type
    tab.value = 'absences'
  }
}

async function loadData() {
  if (selectedEmploymentId.value === null) {
    // Bez pracovního vztahu není co načítat — ale `loading` se musí shodit,
    // jinak na stránce natrvalo zůstanou skeletony a vypadá to jako zaseknuté
    // načítání. Firma bez zaměstnanců je legitimní stav, ne chyba.
    loading.value = false
    return
  }
  loading.value = true
  loadFailed.value = false
  try {
    const employmentId = selectedEmploymentId.value
    const [absencePage, averageData, leaveData] = await Promise.all([
      payrollAbsenceApi.absencesPage(filterFrom.value, filterTo.value, employmentId, {
        limit: absencePageSize,
        offset: absenceOffset.value,
      }),
      payrollAbsenceApi.averages(employmentId),
      payrollAbsenceApi.leaveLedger(employmentId, leaveYear.value),
    ])
    absences.value = absencePage.absences
    absenceTotal.value = absencePage.total
    for (const item of absencePage.absences) {
      if (['dpn', 'quarantine'].includes(item.absence_type) && !dpnReviews[item.id]) {
        dpnReviews[item.id] = {
          firstDayFullyWorked: false,
          insuranceConfirmed: false,
          noConflictingBenefit: false,
        }
      }
    }
    averages.value = averageData
    leaveEntries.value = leaveData.entries
    leaveBalance.value = leaveData.balance_minutes
    absenceForm.employment_id = employmentId
    averageForm.employment_id = employmentId
    entitlementForm.employment_id = employmentId
    entryForm.employment_id = employmentId
  } catch (error: any) {
    // Nepřítomnosti, průměry ani nárok se nemažou. Prázdný seznam by tu byl
    // obzvlášť zrádný: „žádná dovolená" a „nevíme" vedou k opačnému jednání.
    loadFailed.value = true
    toast.error(error?.response?.data?.error?.message || t('payroll_absence.messages.load_failed'))
  } finally {
    loading.value = false
  }
}

// Stránkuje sdílená `PaginationBar` (číslo stránky od jedné); server zná offset.
function goToAbsencePage(nextPage: number) {
  absenceOffset.value = Math.max(0, (nextPage - 1) * absencePageSize)
  void loadData()
}

async function createAbsence() {
  if (!canCreateAbsence.value) {
    toast.error(t('payroll_absence.messages.average_required'))
    return
  }
  absenceError.value = ''
  saving.value = true
  try {
    const payload: AbsencePayload = {
      employment_id: absenceForm.employment_id,
      absence_type: absenceForm.absence_type as AbsenceType,
      date_from: absenceForm.date_from,
      date_to: absenceForm.date_to,
      timezone_name: absenceForm.timezone_name,
      partial_first_minutes: hoursToMinutes(absenceForm.partial_first_hours, {
        nullable: true,
        positive: true,
      }),
      partial_last_minutes: hoursToMinutes(absenceForm.partial_last_hours, {
        nullable: true,
        positive: true,
      }),
      average_snapshot_id: needsAverage.value ? absenceForm.average_snapshot_id : null,
      note: absenceForm.note,
    }
    await payrollAbsenceApi.createAbsence(payload)
    toast.success(t('payroll_absence.messages.absence_created'))
    await loadData()
  } catch (error: any) {
    absenceError.value = error instanceof Error && !(error as any)?.response
      ? error.message
      : exactError(error, 'payroll_absence.messages.save_failed')
  } finally {
    saving.value = false
  }
}

/**
 * Přečerpání dovolené se NEPTÁ DOPŘEDU.
 *
 * Poskytnout dovolenou nad rámec zůstatku zaměstnavatel smí, ale je to
 * rozhodnutí — a drtivá většina schválení žádné přečerpání neřeší. Zaškrtávátko
 * „vím, že přečerpávám" u každé žádosti by tedy bylo pole, které při 500
 * zaměstnancích nikdo nečte a všichni odklikávají. Proto se schvaluje normálně
 * a teprve 409 `leave_overdraw_confirmation_required` ze serveru otevře dotaz
 * s konkrétními čísly, na který stačí jedno kliknutí.
 */
const overdrawPrompt = ref<{
  absenceId: number
  balanceMinutes: number
  requestedMinutes: number
} | null>(null)

async function decide(
  item: PayrollAbsence,
  decision: 'approved' | 'rejected',
  overdrawConfirmed = false,
) {
  const review = dpnReviews[item.id]
  saving.value = true
  try {
    await payrollAbsenceApi.decide(item.id, {
      row_version: item.row_version,
      decision,
      first_day_fully_worked: review?.firstDayFullyWorked ?? false,
      insurance_eligibility_confirmed: review?.insuranceConfirmed ?? false,
      conflicting_benefit_excluded: review?.noConflictingBenefit ?? false,
      ...(overdrawConfirmed ? { overdraw_confirmed: true } : {}),
    })
    overdrawPrompt.value = null
    toast.success(t(`payroll_absence.messages.${decision}`))
    await loadData()
  } catch (error: any) {
    const payload = error?.response?.data?.error
    if (payload?.code === 'leave_overdraw_confirmation_required'
      && typeof payload.balance_minutes === 'number'
      && typeof payload.requested_minutes === 'number') {
      overdrawPrompt.value = {
        absenceId: item.id,
        balanceMinutes: payload.balance_minutes,
        requestedMinutes: payload.requested_minutes,
      }
      return
    }
    overdrawPrompt.value = null
    toast.error(payload?.message || t('payroll_absence.messages.save_failed'))
  } finally {
    saving.value = false
  }
}

async function cancel(item: PayrollAbsence) {
  if (!window.confirm(t('payroll_absence.absences.cancel_confirm'))) return
  saving.value = true
  try {
    await payrollAbsenceApi.cancel(item.id, item.row_version)
    toast.success(t('payroll_absence.messages.cancelled'))
    await loadData()
  } catch (error: any) {
    toast.error(error?.response?.data?.error?.message || t('payroll_absence.messages.save_failed'))
  } finally {
    saving.value = false
  }
}

async function createAverage() {
  averageError.value = ''
  saving.value = true
  try {
    await payrollAbsenceApi.createAverage({
      employment_id: averageForm.employment_id,
      applicable_year: wholeNumber(averageForm.applicable_year, true),
      applicable_quarter: wholeNumber(averageForm.applicable_quarter, true),
      decisive_from: averageForm.decisive_from,
      decisive_to: averageForm.decisive_to,
      gross_earnings_minor: toMinor(averageForm.gross_earnings_czk),
      longer_period_allocated_minor: toMinor(averageForm.longer_period_allocated_czk),
      worked_minutes: hoursToMinutes(averageForm.worked_hours),
      worked_days: wholeNumber(averageForm.worked_days),
      probable_hourly_minor: toMinor(averageForm.probable_hourly_czk, true, true),
      rationale: averageForm.rationale || null,
    })
    toast.success(t('payroll_absence.messages.average_created'))
    await loadData()
  } catch (error: any) {
    averageError.value = error instanceof Error && !(error as any)?.response
      ? error.message
      : exactError(error, 'payroll_absence.messages.save_failed')
  } finally {
    saving.value = false
  }
}

async function approveAverage(item: AverageSnapshot) {
  saving.value = true
  try {
    await payrollAbsenceApi.approveAverage(item.id, item.row_version)
    toast.success(t('payroll_absence.messages.average_approved'))
    await loadData()
  } catch (error: any) {
    toast.error(error?.response?.data?.error?.message || t('payroll_absence.messages.save_failed'))
  } finally {
    saving.value = false
  }
}

async function createEntitlement() {
  entitlementError.value = ''
  saving.value = true
  try {
    await payrollAbsenceApi.createEntitlement({
      employment_id: entitlementForm.employment_id,
      leave_year: wholeNumber(entitlementForm.leave_year, true),
      weekly_minutes: hoursToMinutes(entitlementForm.weekly_hours, { positive: true }),
      entitlement_weeks: wholeNumber(entitlementForm.entitlement_weeks, true),
      continuous_calendar_days: wholeNumber(entitlementForm.continuous_calendar_days, true),
      worked_equivalent_minutes: hoursToMinutes(entitlementForm.worked_equivalent_hours, {
        positive: true,
      }),
      rationale: entitlementForm.rationale,
    })
    toast.success(t('payroll_absence.messages.entitlement_created'))
    await loadData()
  } catch (error: any) {
    entitlementError.value = error instanceof Error && !(error as any)?.response
      ? error.message
      : exactError(error, 'payroll_absence.messages.save_failed')
  } finally {
    saving.value = false
  }
}

async function loadLeaveCandidates() {
  leaveCandidateLoading.value = true
  leaveCandidateError.value = ''
  try {
    const page = await payrollAbsenceApi.leaveEntitlementCandidates(
      leaveYear.value,
      leaveThrough.value,
      { limit: leaveCandidatePageSize, offset: leaveCandidateOffset.value },
    )
    leaveCandidates.value = page.items
    leaveCandidateTotal.value = page.total
    selectedLeaveCandidates.value = selectedLeaveCandidates.value.filter(id =>
      page.items.some(candidate => candidate.ready && candidate.employment_id === id))
  } catch (error: any) {
    leaveCandidateError.value = exactError(error, 'payroll_absence.leave.automatic_load_failed')
  } finally {
    leaveCandidateLoading.value = false
  }
}

function goToLeaveCandidatePage(nextPage: number) {
  leaveCandidateOffset.value = Math.max(0, (nextPage - 1) * leaveCandidatePageSize)
  selectedLeaveCandidates.value = []
  void loadLeaveCandidates()
}

function selectAllReadyCandidates() {
  selectedLeaveCandidates.value = leaveCandidates.value
    .filter(candidate => candidate.ready)
    .map(candidate => candidate.employment_id)
}

async function createAutomaticEntitlements() {
  if (selectedReadyCandidates.value.length === 0) return
  saving.value = true
  leaveCandidateError.value = ''
  try {
    await payrollAbsenceApi.createAutomaticEntitlements({
      year: leaveYear.value,
      through: leaveThrough.value,
      items: selectedReadyCandidates.value.map(candidate => ({
        employment_id: candidate.employment_id,
        input_version: candidate.input_version,
      })),
    })
    toast.success(t('payroll_absence.leave.automatic_created', {
      count: selectedReadyCandidates.value.length,
    }))
    selectedLeaveCandidates.value = []
    await Promise.all([loadLeaveCandidates(), loadData()])
  } catch (error: any) {
    leaveCandidateError.value = exactError(error, 'payroll_absence.messages.save_failed')
  } finally {
    saving.value = false
  }
}

async function createEntry() {
  entryError.value = ''
  saving.value = true
  try {
    await payrollAbsenceApi.createLeaveEntry({
      employment_id: entryForm.employment_id,
      leave_year: wholeNumber(entryForm.leave_year, true),
      effective_date: entryForm.effective_date,
      entry_type: entryForm.entry_type,
      minutes_delta: hoursToMinutes(entryForm.hours_delta, { nonZero: true, signed: true }),
      reason: entryForm.reason,
    })
    toast.success(t('payroll_absence.messages.entry_created'))
    await loadData()
  } catch (error: any) {
    entryError.value = error instanceof Error && !(error as any)?.response
      ? error.message
      : exactError(error, 'payroll_absence.messages.save_failed')
  } finally {
    saving.value = false
  }
}

function minutes(value: number) {
  const sign = value < 0 ? '−' : ''
  const absolute = Math.abs(value)
  return `${sign}${Math.floor(absolute / 60)}:${String(absolute % 60).padStart(2, '0')}`
}

watch(selectedEmployeeId, employeeId => {
  const available = employments.value.filter(item => item.employee_id === employeeId)
  if (!available.some(item => item.id === selectedEmploymentId.value)) {
    selectedEmploymentId.value = available[0]?.id ?? null
  }
})
watch(selectedEmploymentId, () => {
  absenceForm.average_snapshot_id = null
  absenceError.value = ''
  averageError.value = ''
  entitlementError.value = ''
  entryError.value = ''
  // Jiný vztah = jiná množina nepřítomností; třetí stránka by ukázala prázdno.
  absenceOffset.value = 0
  void loadData()
})
// Rozsah dat se načítá až tlačítkem Načíst znovu, ale zúžený filtr nesmí
// uživatele nechat stát na stránce, která už neexistuje.
watch([filterFrom, filterTo], () => {
  absenceOffset.value = 0
})
watch(leaveYear, (selectedYear, previousYear) => {
  entitlementForm.leave_year = selectedYear
  entryForm.leave_year = selectedYear
  if (entryForm.effective_date === `${previousYear}-01-01`) {
    entryForm.effective_date = `${selectedYear}-01-01`
  }
  leaveCandidateOffset.value = 0
  selectedLeaveCandidates.value = []
  void loadData()
  void loadLeaveCandidates()
})
watch(
  [() => averageForm.applicable_year, () => averageForm.applicable_quarter],
  ([selectedYear, selectedQuarter]) => {
    if (!Number.isInteger(selectedYear) || !Number.isInteger(selectedQuarter)
      || selectedQuarter < 1 || selectedQuarter > 4) return
    const startMonth = (selectedQuarter - 1) * 3
    averageForm.decisive_from = localDate(new Date(selectedYear, startMonth - 3, 1))
    averageForm.decisive_to = localDate(new Date(selectedYear, startMonth, 0))
  },
)
onMounted(async () => {
  try {
    await loadContext()
    await Promise.all([loadData(), loadLeaveCandidates()])
  } catch (error: any) {
    toast.error(error?.response?.data?.error?.message || t('payroll_absence.messages.load_failed'))
    loading.value = false
  }
})
</script>

<template>
  <div class="space-y-6">
    <header class="flex flex-wrap items-start justify-between gap-4">
      <div>
        <h1 class="text-2xl font-semibold text-neutral-900">{{ t('payroll_absence.title') }}</h1>
        <p class="mt-1 max-w-3xl text-sm text-neutral-500">{{ t('payroll_absence.subtitle') }}</p>
      </div>
      <span class="rounded-full bg-warning-50 px-3 py-1 text-xs font-medium text-warning-700">
        {{ t('payroll_absence.manual_review') }}
      </span>
    </header>

    <section class="rounded-xl border border-warning-200 bg-warning-50 p-4 text-sm text-warning-800">
      {{ t('payroll_absence.review_notice') }}
    </section>

    <EmptyState
      v-if="hasNoEmployments"
      boxed
      icon="user"
      cta-icon="user"
      data-test="no-employments"
      :title="t('payroll_absence.empty.no_employments_title')"
      :message="t('payroll_absence.empty.no_employments_message')"
      :cta="t('payroll_absence.empty.no_employments_cta')"
      to="/payroll/people"
    />

    <template v-else>
    <section class="rounded-xl border border-neutral-200 bg-surface p-4 shadow-sm">
      <div class="grid gap-4 md:grid-cols-4">
        <div>
          <span class="mb-1 block text-xs font-medium text-neutral-600">{{ t('payroll_absence.employee') }}</span>
          <PayrollPersonSearchSelect
            v-model="selectedEmployeeId"
            data-test="absence-person"
            :candidates="personOptions"
            :label="t('payroll_absence.employee')"
            :clearable="false"
          />
        </div>
        <div>
          <span class="mb-1 block text-xs font-medium text-neutral-600">{{ t('payroll_absence.employment') }}</span>
          <SearchableSelect
            v-model="selectedEmploymentId"
            :options="employmentOptions"
            :clearable="false"
            accent="payroll"
            data-test="absence-employment"
            :aria-label="t('payroll_absence.employment')"
          />
        </div>
        <label>
          <span class="mb-1 block text-xs font-medium text-neutral-600">{{ t('payroll_absence.from') }}</span>
          <input v-model="filterFrom" type="date" :class="fieldClass">
        </label>
        <label>
          <span class="mb-1 block text-xs font-medium text-neutral-600">{{ t('payroll_absence.to') }}</span>
          <input v-model="filterTo" type="date" :class="fieldClass">
        </label>
      </div>
      <div class="mt-3 flex flex-wrap justify-end">
        <button :class="btnOutline('neutral')" :disabled="loading" @click="loadData">
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path :d="ICONS.cycle" />
          </svg>
          {{ t('common.refresh') }}
        </button>
      </div>
    </section>

    <nav class="mb-5 flex flex-wrap gap-1 border-b border-neutral-200" :aria-label="t('payroll_absence.tabs.label')">
      <button
        v-for="name in (['absences', 'averages', 'leave'] as const)"
        :key="name"
        type="button"
        :data-test="`tab-${name}`"
        class="-mb-px cursor-pointer whitespace-nowrap border-b-2 px-4 py-2 text-sm font-medium transition-colors"
        :class="tab === name
          ? 'border-payroll-600 text-payroll-600'
          : 'border-transparent text-neutral-600 hover:border-neutral-300 hover:text-neutral-900'"
        @click="tab = name"
      >
        {{ t(`payroll_absence.tabs.${name}`) }}
      </button>
    </nav>

    <div v-if="loading" class="grid gap-4 md:grid-cols-2">
      <div v-for="index in 4" :key="index" class="h-40 animate-pulse rounded-xl bg-neutral-100" />
    </div>

    <EmptyState
      v-else-if="loadFailed"
      variant="failed"
      boxed
      data-test="load-failed"
      :message="t('payroll_absence.messages.load_failed_hint')"
      @action="loadData"
    />

    <template v-else-if="tab === 'absences'">
      <section v-if="canWrite" class="rounded-xl border border-neutral-200 bg-surface p-4 shadow-sm sm:p-6">
        <h2 class="text-lg font-semibold text-neutral-900">{{ t('payroll_absence.absences.new') }}</h2>
        <form data-test="absence-form" class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4" @submit.prevent="createAbsence">
          <div>
            <span class="mb-1 block text-xs font-medium text-neutral-600">{{ t('payroll_absence.absences.type') }}</span>
            <SearchableSelect
              v-model="absenceForm.absence_type"
              data-test="absence-type"
              :options="absenceTypeOptions"
              :clearable="false"
              accent="payroll"
              :aria-label="t('payroll_absence.absences.type')"
            />
          </div>
          <label>
            <span class="mb-1 block text-xs font-medium text-neutral-600">{{ t('payroll_absence.from') }}</span>
            <input v-model="absenceForm.date_from" required type="date" :class="fieldClass">
          </label>
          <label>
            <span class="mb-1 block text-xs font-medium text-neutral-600">{{ t('payroll_absence.to') }}</span>
            <input v-model="absenceForm.date_to" required type="date" :class="fieldClass">
          </label>
          <div v-if="needsAverage">
            <span class="mb-1 block text-xs font-medium text-neutral-600">{{ t('payroll_absence.absences.average') }}</span>
            <SearchableSelect
              v-model="absenceForm.average_snapshot_id"
              data-test="absence-average"
              :options="averageOptions"
              :placeholder="t('payroll_absence.select')"
              accent="payroll"
              :aria-label="t('payroll_absence.absences.average')"
            />
            <!--
              Prázdný výběr sám o sobě neřekne, kam jít. Průměry se počítají na
              vlastní záložce a odkaz tam dosud nikde nebyl.
            -->
            <button
              v-if="noAverageAvailable"
              type="button"
              :class="[btnOutline('primary'), 'mt-2']"
              data-test="go-to-averages"
              @click="tab = 'averages'"
            >
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path :d="ICONS.chart" />
              </svg>
              {{ t('payroll_absence.absences.go_to_averages') }}
            </button>
          </div>
          <label>
            <span class="mb-1 block text-xs font-medium text-neutral-600">{{ t('payroll_absence.absences.partial_first') }}</span>
            <input
              v-model.number="absenceForm.partial_first_hours"
              data-test="absence-partial-first-hours"
              min="0.25"
              step="0.25"
              type="number"
              :class="fieldClass"
            >
          </label>
          <label>
            <span class="mb-1 block text-xs font-medium text-neutral-600">{{ t('payroll_absence.absences.partial_last') }}</span>
            <input
              v-model.number="absenceForm.partial_last_hours"
              data-test="absence-partial-last-hours"
              min="0.25"
              step="0.25"
              type="number"
              :class="fieldClass"
            >
          </label>
          <label class="sm:col-span-2">
            <span class="mb-1 block text-xs font-medium text-neutral-600">{{ t('payroll_absence.note') }}</span>
            <input v-model="absenceForm.note" maxlength="1000" type="text" :class="fieldClass">
          </label>
          <div class="flex flex-col items-end gap-1.5 sm:col-span-2 lg:col-span-4">
            <button
              :class="btnFilled('primary')"
              :disabled="!canCreateAbsence"
              :title="disabledTitle(!canCreateAbsence, absenceBlockedReason)"
              data-test="absence-create"
            >
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path :d="ICONS.plus" />
              </svg>
              {{ t('payroll_absence.absences.create') }}
            </button>
            <p v-if="absenceBlockedReason" :class="BTN_DISABLED_NOTE" data-test="absence-create-blocked">
              {{ absenceBlockedReason }}
            </p>
          </div>
          <p
            v-if="absenceError"
            data-test="absence-error"
            role="alert"
            class="rounded-lg border border-danger-200 bg-danger-50 p-3 text-sm text-danger-700 sm:col-span-2 lg:col-span-4"
          >
            {{ absenceError }}
          </p>
        </form>
      </section>

      <section>
        <h2 class="mb-3 text-lg font-semibold text-neutral-900">{{ t('payroll_absence.absences.list') }}</h2>
        <p v-if="absences.length === 0" class="rounded-xl border border-dashed border-neutral-300 p-8 text-center text-sm text-neutral-500">
          {{ t('payroll_absence.absences.empty') }}
        </p>
        <div v-else class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
          <article v-for="item in absences" :key="item.id" class="rounded-xl border border-neutral-200 bg-surface p-4 shadow-sm">
            <div class="flex items-start justify-between gap-3">
              <div>
                <h3 class="font-semibold text-neutral-900">{{ t(`payroll_absence.types.${item.absence_type}`) }}</h3>
                <p class="mt-0.5 text-sm text-neutral-500">{{ item.full_name }} · {{ item.employment_code }}</p>
              </div>
              <span class="rounded-full px-2 py-1 text-xs font-medium" :class="{
                'bg-warning-50 text-warning-700': item.status === 'requested',
                'bg-success-50 text-success-700': item.status === 'approved',
                'bg-danger-50 text-danger-700': item.status === 'rejected',
                'bg-neutral-100 text-neutral-600': item.status === 'cancelled',
              }">
                {{ t(`payroll_absence.status.${item.status}`) }}
              </span>
            </div>
            <dl class="mt-4 grid grid-cols-2 gap-3 text-sm">
              <div><dt class="text-neutral-500">{{ t('payroll_absence.period') }}</dt><dd class="font-medium text-neutral-900">{{ item.date_from }} – {{ item.date_to }}</dd></div>
              <div><dt class="text-neutral-500">{{ t('payroll_absence.absences.average') }}</dt><dd class="font-medium text-neutral-900">{{ money(item.average_hourly_minor) }}</dd></div>
            </dl>
            <p v-if="item.note" class="mt-3 text-sm text-neutral-600">{{ item.note }}</p>
            <div v-if="item.correction_pending" class="mt-3 rounded-lg bg-warning-50 p-2 text-xs text-warning-800">
              {{ t('payroll_absence.absences.correction_pending') }}
            </div>
            <div
              v-if="item.status === 'requested' && ['dpn', 'quarantine'].includes(item.absence_type)"
              class="mt-4 space-y-2 rounded-lg border border-warning-200 bg-warning-50 p-3 text-xs text-warning-900"
            >
              <label class="flex gap-2"><input v-model="dpnReviews[item.id].insuranceConfirmed" type="checkbox"> {{ t('payroll_absence.dpn.insurance') }}</label>
              <label class="flex gap-2"><input v-model="dpnReviews[item.id].noConflictingBenefit" type="checkbox"> {{ t('payroll_absence.dpn.no_conflict') }}</label>
              <label class="flex gap-2"><input v-model="dpnReviews[item.id].firstDayFullyWorked" type="checkbox"> {{ t('payroll_absence.dpn.first_day_worked') }}</label>
            </div>
            <div
              v-if="overdrawPrompt && overdrawPrompt.absenceId === item.id"
              data-test="leave-overdraw-prompt"
              class="mt-4 rounded-lg border border-warning-200 bg-warning-50 p-3 text-sm text-warning-900"
            >
              <p>
                {{ t('payroll_absence.leave.overdraw_question', {
                  balance: minutes(overdrawPrompt.balanceMinutes),
                  requested: minutes(overdrawPrompt.requestedMinutes),
                }) }}
              </p>
              <div class="mt-3 flex flex-wrap gap-2">
                <button :class="btnFilled('warning')" data-test="leave-overdraw-confirm" :disabled="saving" @click="decide(item, 'approved', true)">
                  <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.check" /></svg>
                  {{ t('payroll_absence.leave.overdraw_confirm') }}
                </button>
                <button :class="btnOutline('neutral')" data-test="leave-overdraw-cancel" :disabled="saving" @click="overdrawPrompt = null">
                  {{ t('common.cancel') }}
                </button>
              </div>
            </div>
            <div v-if="canWrite && item.status === 'requested'" class="mt-4 flex flex-wrap gap-2">
              <button :class="btnFilled('success')" :disabled="saving" @click="decide(item, 'approved')">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.check" /></svg>
                {{ t('payroll_absence.actions.approve') }}
              </button>
              <button :class="btnOutline('danger')" :disabled="saving" @click="decide(item, 'rejected')">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.x" /></svg>
                {{ t('payroll_absence.actions.reject') }}
              </button>
            </div>
            <div v-else-if="canWrite && item.status === 'approved'" class="mt-4 flex flex-wrap">
              <button :class="btnOutline('warning')" :disabled="saving" @click="cancel(item)">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.uturn" /></svg>
                {{ t('payroll_absence.actions.cancel') }}
              </button>
            </div>
          </article>
        </div>
        <PaginationBar
          class="mt-4"
          data-test="absence-pagination"
          :page="currentAbsencePage"
          :per-page="absencePageSize"
          :total="absenceTotal"
          @update:page="goToAbsencePage"
        />
      </section>
    </template>

    <template v-else-if="tab === 'averages'">
      <section v-if="canWrite" class="rounded-xl border border-neutral-200 bg-surface p-4 shadow-sm sm:p-6">
        <h2 class="text-lg font-semibold text-neutral-900">{{ t('payroll_absence.averages.new') }}</h2>
        <form data-test="average-form" class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4" @submit.prevent="createAverage">
          <label><span class="form-label">{{ t('payroll_absence.averages.year') }}</span><input v-model.number="averageForm.applicable_year" data-test="average-year" :min="minimumFormYear" :max="maximumFormYear" type="number" :class="fieldClass"></label>
          <label><span class="form-label">{{ t('payroll_absence.averages.quarter') }}</span><input v-model.number="averageForm.applicable_quarter" min="1" max="4" type="number" :class="fieldClass"></label>
          <label><span class="form-label">{{ t('payroll_absence.averages.decisive_from') }}</span><input v-model="averageForm.decisive_from" type="date" :class="fieldClass"></label>
          <label><span class="form-label">{{ t('payroll_absence.averages.decisive_to') }}</span><input v-model="averageForm.decisive_to" type="date" :class="fieldClass"></label>
          <label><span class="form-label">{{ t('payroll_absence.averages.gross_minor') }}</span><input v-model.number="averageForm.gross_earnings_czk" data-test="average-gross-czk" min="0" step="0.01" type="number" :class="fieldClass"></label>
          <label><span class="form-label">{{ t('payroll_absence.averages.allocated_minor') }}</span><input v-model.number="averageForm.longer_period_allocated_czk" data-test="average-allocated-czk" min="0" step="0.01" type="number" :class="fieldClass"></label>
          <label><span class="form-label">{{ t('payroll_absence.averages.worked_minutes') }}</span><input v-model.number="averageForm.worked_hours" data-test="average-worked-hours" min="0" step="0.25" type="number" :class="fieldClass"></label>
          <label><span class="form-label">{{ t('payroll_absence.averages.worked_days') }}</span><input v-model.number="averageForm.worked_days" data-test="average-worked-days" min="0" step="1" type="number" :class="fieldClass"></label>
          <label><span class="form-label">{{ t('payroll_absence.averages.probable_minor') }}</span><input v-model.number="averageForm.probable_hourly_czk" data-test="average-probable-czk" min="0.01" step="0.01" type="number" :class="fieldClass"></label>
          <label class="sm:col-span-2 lg:col-span-3"><span class="form-label">{{ t('payroll_absence.averages.rationale') }}</span><input v-model="averageForm.rationale" maxlength="1000" type="text" :class="fieldClass"></label>
          <div class="flex flex-wrap justify-end sm:col-span-2 lg:col-span-4">
            <button :class="btnFilled('primary')" :disabled="saving"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.plus" /></svg>{{ t('payroll_absence.averages.create') }}</button>
          </div>
          <p
            v-if="averageError"
            data-test="average-error"
            role="alert"
            class="rounded-lg border border-danger-200 bg-danger-50 p-3 text-sm text-danger-700 sm:col-span-2 lg:col-span-4"
          >
            {{ averageError }}
          </p>
        </form>
      </section>
      <section class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        <article v-for="item in averages" :key="item.id" class="rounded-xl border border-neutral-200 bg-surface p-4 shadow-sm">
          <div class="flex justify-between gap-3"><h3 class="font-semibold text-neutral-900">{{ item.applicable_year }}/Q{{ item.applicable_quarter }}</h3><span class="text-xs text-neutral-500">{{ t(`payroll_absence.average_source.${item.source_kind}`) }}</span></div>
          <p class="mt-3 text-2xl font-semibold text-payroll-600">{{ money(item.average_hourly_minor) }}</p>
          <p class="mt-1 text-sm text-neutral-500">{{ t(`payroll_absence.average_status.${item.status}`) }}</p>
          <button v-if="canWrite && item.status === 'manual_review'" :class="btnFilled('success')" class="mt-4" :disabled="saving" @click="approveAverage(item)"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.check" /></svg>{{ t('payroll_absence.actions.approve') }}</button>
        </article>
      </section>
    </template>

    <template v-else>
      <section class="rounded-xl border border-neutral-200 bg-surface p-4 shadow-sm sm:p-6">
        <div class="flex flex-wrap items-end justify-between gap-4">
          <div><h2 class="text-lg font-semibold text-neutral-900">{{ t('payroll_absence.leave.balance') }}</h2><p class="mt-1 text-3xl font-semibold text-payroll-600">{{ minutes(leaveBalance) }}</p></div>
          <label><span class="form-label">{{ t('payroll_absence.leave.year') }}</span><input v-model.number="leaveYear" data-test="leave-year" :min="minimumFormYear" :max="maximumFormYear" type="number" :class="[fieldClass, 'w-32']"></label>
        </div>
      </section>
      <section class="rounded-xl border border-neutral-200 bg-surface p-4 shadow-sm sm:p-6" data-test="automatic-leave-entitlements">
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div>
            <h2 class="font-semibold text-neutral-900">{{ t('payroll_absence.leave.automatic_title') }}</h2>
            <p class="mt-1 text-sm text-neutral-500">{{ t('payroll_absence.leave.automatic_hint', { through: leaveThrough }) }}</p>
          </div>
          <div v-if="canWrite" class="flex flex-wrap gap-2">
            <button type="button" :class="btnOutline('neutral')" :disabled="leaveCandidateLoading" @click="selectAllReadyCandidates">
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.check" /></svg>
              {{ t('payroll_absence.leave.select_ready') }}
            </button>
            <button type="button" :class="btnFilled('primary')" :disabled="saving || selectedReadyCandidates.length === 0" @click="createAutomaticEntitlements">
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.cycle" /></svg>
              {{ t('payroll_absence.leave.calculate_selected', { count: selectedReadyCandidates.length }) }}
            </button>
          </div>
        </div>
        <p v-if="leaveCandidateError" class="mt-3 rounded-lg border border-danger-200 bg-danger-50 p-3 text-sm text-danger-700" role="alert">{{ leaveCandidateError }}</p>
        <p v-if="leaveCandidateLoading" class="mt-4 text-sm text-neutral-500">{{ t('common.loading') }}</p>
        <div v-else class="mt-4 divide-y divide-neutral-200 rounded-lg border border-neutral-200">
          <label v-for="candidate in leaveCandidates" :key="candidate.employment_id" class="flex items-start gap-3 p-3" :class="candidate.ready ? 'cursor-pointer' : 'bg-neutral-50'">
            <input v-if="canWrite" v-model="selectedLeaveCandidates" type="checkbox" :value="candidate.employment_id" :disabled="!candidate.ready" class="mt-1 h-4 w-4 rounded border-neutral-300 text-payroll-600 focus:ring-payroll-500">
            <span class="min-w-0 flex-1">
              <span class="block font-medium text-neutral-900">{{ candidate.employee_name }} · {{ candidate.employment_code }}</span>
              <span v-if="candidate.ready" class="mt-1 block text-xs text-neutral-500">
                {{ t('payroll_absence.leave.automatic_summary', {
                  hours: minutes(candidate.weekly_minutes ?? 0),
                  weeks: candidate.entitlement_weeks,
                  worked: minutes(candidate.worked_equivalent_minutes),
                }) }}
              </span>
              <span v-else class="mt-1 block text-xs text-warning-700">
                {{ candidate.blockers.map(blocker => t(`payroll_absence.leave.blockers.${blocker}`)).join(' · ') }}
              </span>
            </span>
            <span class="rounded-full px-2 py-1 text-xs font-medium" :class="candidate.ready ? 'bg-success-50 text-success-700' : 'bg-warning-50 text-warning-700'">
              {{ t(candidate.ready ? 'payroll_absence.leave.ready' : 'payroll_absence.leave.needs_attention') }}
            </span>
          </label>
          <p v-if="leaveCandidates.length === 0" class="p-6 text-center text-sm text-neutral-500">{{ t('payroll_absence.leave.automatic_empty') }}</p>
        </div>
        <PaginationBar class="mt-4" :page="leaveCandidatePage" :per-page="leaveCandidatePageSize" :total="leaveCandidateTotal" @update:page="goToLeaveCandidatePage" />
      </section>
      <details v-if="canWrite" class="group rounded-xl border border-neutral-200 bg-surface shadow-sm">
        <summary class="flex cursor-pointer list-none items-center gap-2 p-4 sm:p-6">
          <svg class="h-4 w-4 text-neutral-500 transition-transform group-open:rotate-180" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m6 9 6 6 6-6" /></svg>
          <span><strong class="text-neutral-900">{{ t('payroll_absence.leave.manual_tools') }}</strong><span class="ml-2 text-sm text-neutral-500">{{ t('payroll_absence.leave.manual_tools_hint') }}</span></span>
        </summary>
        <div class="grid gap-4 border-t border-neutral-200 p-4 sm:p-6 xl:grid-cols-2">
        <section class="rounded-xl border border-neutral-200 bg-surface p-4 shadow-sm">
          <h2 class="font-semibold text-neutral-900">{{ t('payroll_absence.leave.entitlement') }}</h2>
          <form data-test="leave-entitlement-form" class="mt-4 grid gap-3 sm:grid-cols-2" @submit.prevent="createEntitlement">
            <label><span class="form-label">{{ t('payroll_absence.leave.weekly_minutes') }}</span><input v-model.number="entitlementForm.weekly_hours" data-test="leave-weekly-hours" min="0.25" step="0.25" type="number" :class="fieldClass"></label>
            <label><span class="form-label">{{ t('payroll_absence.leave.weeks') }}</span><input v-model.number="entitlementForm.entitlement_weeks" min="1" type="number" :class="fieldClass"></label>
            <label><span class="form-label">{{ t('payroll_absence.leave.duration_days') }}</span><input v-model.number="entitlementForm.continuous_calendar_days" min="1" type="number" :class="fieldClass"></label>
            <label><span class="form-label">{{ t('payroll_absence.leave.worked_equivalent') }}</span><input v-model.number="entitlementForm.worked_equivalent_hours" data-test="leave-worked-hours" min="0.25" step="0.25" type="number" :class="fieldClass"></label>
            <label class="sm:col-span-2"><span class="form-label">{{ t('payroll_absence.leave.rationale') }}</span><textarea v-model="entitlementForm.rationale" data-test="leave-rationale" required maxlength="1000" :class="textareaClass" rows="2" /></label>
            <div class="flex flex-wrap justify-end sm:col-span-2"><button :class="btnFilled('primary')" :disabled="saving"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.plus" /></svg>{{ t('payroll_absence.leave.calculate') }}</button></div>
            <p v-if="entitlementError" data-test="entitlement-error" role="alert" class="rounded-lg border border-danger-200 bg-danger-50 p-3 text-sm text-danger-700 sm:col-span-2">{{ entitlementError }}</p>
          </form>
        </section>
        <section class="rounded-xl border border-neutral-200 bg-surface p-4 shadow-sm">
          <h2 class="font-semibold text-neutral-900">{{ t('payroll_absence.leave.manual_entry') }}</h2>
          <form data-test="leave-entry-form" class="mt-4 grid gap-3 sm:grid-cols-2" @submit.prevent="createEntry">
            <div><span class="form-label">{{ t('payroll_absence.leave.entry_type') }}</span><SearchableSelect v-model="entryForm.entry_type" :options="leaveEntryTypeOptions" :clearable="false" accent="payroll" :aria-label="t('payroll_absence.leave.entry_type')" /></div>
            <label><span class="form-label">{{ t('payroll_absence.leave.effective_date') }}</span><input v-model="entryForm.effective_date" type="date" :class="fieldClass"></label>
            <label><span class="form-label">{{ t('payroll_absence.leave.minutes_delta') }}</span><input v-model.number="entryForm.hours_delta" data-test="leave-entry-hours" step="0.25" type="number" :class="fieldClass"></label>
            <label><span class="form-label">{{ t('payroll_absence.leave.reason') }}</span><input v-model="entryForm.reason" data-test="leave-entry-reason" required maxlength="1000" :class="fieldClass"></label>
            <div class="flex flex-wrap justify-end sm:col-span-2"><button :class="btnFilled('primary')" :disabled="saving"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.plus" /></svg>{{ t('payroll_absence.leave.add') }}</button></div>
            <p v-if="entryError" data-test="entry-error" role="alert" class="rounded-lg border border-danger-200 bg-danger-50 p-3 text-sm text-danger-700 sm:col-span-2">{{ entryError }}</p>
          </form>
        </section>
        </div>
      </details>
      <section>
        <h2 class="mb-3 text-lg font-semibold text-neutral-900">{{ t('payroll_absence.leave.ledger') }}</h2>
        <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
          <article v-for="entry in leaveEntries" :key="entry.id" class="rounded-xl border border-neutral-200 bg-surface p-4 shadow-sm">
            <div class="flex justify-between gap-3"><h3 class="font-medium text-neutral-900">{{ t(`payroll_absence.leave.types.${entry.entry_type}`) }}</h3><strong :class="entry.minutes_delta < 0 ? 'text-danger-600' : 'text-success-600'">{{ minutes(entry.minutes_delta) }}</strong></div>
            <p class="mt-2 text-xs text-neutral-500">{{ entry.effective_date }}</p><p class="mt-2 text-sm text-neutral-600">{{ entry.reason }}</p>
          </article>
        </div>
      </section>
    </template>
    </template>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { personalNumberLabel } from './employmentLifecycleUi'
import { useRoute } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { btnFilled, btnOutline, disabledTitle, BTN_DISABLED_NOTE, ICONS } from '@/components/ui/buttonStyles'
// Formátování je sdílené (useFormat) — místní kopie se rozcházely v locale i tvaru.
import { formatDate, formatMoneyMinor as money } from '@/composables/useFormat'
import PayrollPersonSearchSelect from '@/components/payroll/PayrollPersonSearchSelect.vue'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import Modal from '@/components/ui/Modal.vue'
import PaginationBar from '@/components/ui/PaginationBar.vue'
import { apiErrorCode, apiErrorMessage } from '@/api/errors'
import { usePayrollYearClosedToast } from '@/composables/usePayrollYearClosedToast'
import { localPayrollPeriod, payrollQueryPeriod } from '@/pages/payroll/payrollComponentsUi'
import {
  payrollAbsenceApi,
  type AbsencePayload,
  type AbsenceType,
  type AverageEarningCandidate,
  type AverageEarningSuggestion,
  type AverageSnapshot,
  type LeaveEntry,
  type LeaveAbsenceDecision,
  type LeaveEntitlementCandidate,
  type ObstacleKind,
  type PayrollAbsence,
  type PayrollAbsenceEmployment,
  type PayrollObstacleKindRule,
} from '@/api/payrollAbsences'
import {
  formatPercent,
  obstacleRateLabel,
  obstacleRateReasonRequired,
  percentToBasisPoints,
} from './absenceObstacleUi'
import type { PayrollAbsenceSicknessCaseOutcome } from '@/api/payrollSicknessCases'
import DateInput from '@/components/ui/DateInput.vue'
import { usePayrollServerMessage } from './payrollServerMessage'

const { t } = useI18n()
const { reasonText } = usePayrollServerMessage()
const route = useRoute()
const toast = useToast()
/*
 * Uzavřená roční uzávěrka blokuje zápis i tady, přestože o ní tahle obrazovka
 * nic neví. Chybová hláška proto nese proklik rovnou na uzávěrku s tím rokem,
 * o který šlo — jinak je to slepá ulička.
 */
const showPayrollError = usePayrollYearClosedToast()
const auth = useAuthStore()
const today = new Date()
const year = today.getFullYear()
function localDate(date: Date) {
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`
}
/*
 * Filtr i nová nepřítomnost se otevírají na měsíci Z ODKAZU, když v něm je
 * (`/payroll/absences?period=2026-08`), jinak na dnešním. Kdo sem přijde
 * z přípravy mzdového běhu za srpen, musí vidět srpen — obrazovka, která
 * období z odkazu zahodí, ho tiše přepne jinam a zapsaná absence pak sedí
 * na cizí měsíc.
 */
const linkedPeriod = payrollQueryPeriod(route.query, localPayrollPeriod(today))
const periodYear = Number(linkedPeriod.slice(0, 4))
const periodMonthIndex = Number(linkedPeriod.slice(5, 7)) - 1
const monthStart = `${linkedPeriod}-01`
const monthEnd = localDate(new Date(periodYear, periodMonthIndex + 1, 0))
/*
 * Průměr se předvyplňuje na ČTVRTLETÍ OTEVŘENÉHO OBDOBÍ, ne na dnešní.
 * Kdo sem přijde ze srpnového běhu v listopadu, potřebuje průměr pro Q3;
 * formulář mu ale nabízel Q4, ona ho tak uložila — a srpnová dovolená ten
 * průměr nesměla použít (kontrola žádá shodné čtvrtletí), takže musela počítat
 * znovu. Rok i čtvrtletí jdou pořád přepsat ručně.
 */
const applicationQuarter = Math.floor(periodMonthIndex / 3) + 1
const applicationQuarterStartMonth = (applicationQuarter - 1) * 3
const decisiveFrom = localDate(new Date(periodYear, applicationQuarterStartMonth - 3, 1))
const decisiveTo = localDate(new Date(periodYear, applicationQuarterStartMonth, 0))

const loading = ref(true)
let dataLoadSequence = 0
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
const averageCandidates = ref<AverageEarningCandidate[]>([])
const averageCandidateTotal = ref(0)
const averageCandidateOffset = ref(0)
const averageCandidatePageSize = 25
const averageCandidateLoading = ref(false)
const averageCandidateError = ref('')
const selectedAverageCandidates = ref<number[]>([])
const selectedAbsenceIds = ref<number[]>([])
const selectedEmployeeId = ref<number | null>(null)
const selectedEmploymentId = ref<number | null>(null)
const filterFrom = ref(monthStart)
const filterTo = ref(monthEnd)
const leaveYear = ref(periodYear)
const minimumFormYear = year - 5
const maximumFormYear = year + 2
const canWrite = computed(() => auth.canWrite('payroll.time.write'))
const leaveCandidatePage = computed(() => Math.floor(
  leaveCandidateOffset.value / leaveCandidatePageSize,
) + 1)
const leaveThrough = computed(() => leaveYear.value === year
  ? localDate(today)
  : `${leaveYear.value}-12-31`)
/**
 * Hromadné posouzení jiných absencí (§ 216 odst. 2, § 348 odst. 1 ZP): účetní
 * rozhodne jednou za druh absence, jestli se do odpracované doby započítá.
 * Vztah, kterému chybělo jen toto posouzení, se pak dá spočítat.
 */
const absenceDecisions = ref<Record<string, LeaveAbsenceDecision>>({})

function assessmentTypes(candidate: LeaveEntitlementCandidate): string[] {
  return [...new Set((candidate.assessment_absences ?? []).map(absence => absence.absence_type))]
}

function onlyAssessmentMissing(candidate: LeaveEntitlementCandidate): boolean {
  return !candidate.ready
    && !candidate.takeover
    && candidate.blockers.length > 0
    && candidate.blockers.every(blocker => blocker === 'absence_legal_assessment_required')
}

function isLeaveCandidateSelectable(candidate: LeaveEntitlementCandidate): boolean {
  if (candidate.ready) return true
  return onlyAssessmentMissing(candidate)
    && assessmentTypes(candidate).every(type => absenceDecisions.value[type] !== undefined)
}

const leaveAssessmentGroups = computed(() => {
  const groups = new Map<string, { type: string, people: number, absences: number }>()
  for (const candidate of leaveCandidates.value) {
    if (!candidate.blockers.includes('absence_legal_assessment_required')) continue
    for (const type of assessmentTypes(candidate)) {
      const group = groups.get(type) ?? { type, people: 0, absences: 0 }
      group.people++
      group.absences += (candidate.assessment_absences ?? []).filter(absence => absence.absence_type === type).length
      groups.set(type, group)
    }
  }
  return [...groups.values()].sort((a, b) => b.people - a.people)
})

const ASSESSMENT_HINTS = ['dpn', 'quarantine', 'ppm', 'parental', 'unpaid_leave']

function assessmentHint(type: string): string {
  return t(`payroll_absence.leave.assessment.hint_by_type.${ASSESSMENT_HINTS.includes(type) ? type : 'other'}`)
}

function setAbsenceDecision(type: string, decision: LeaveAbsenceDecision) {
  absenceDecisions.value = { ...absenceDecisions.value, [type]: decision }
}

function scrollToLeaveAssessment() {
  document.getElementById('leave-assessment')?.scrollIntoView({ behavior: 'smooth', block: 'start' })
}

function leaveCandidateTarget(candidate: LeaveEntitlementCandidate) {
  return {
    name: 'payroll-people',
    query: {
      ...(candidate.employee_id ? { person: String(candidate.employee_id) } : {}),
      employment: String(candidate.employment_id),
    },
  }
}

const selectedReadyCandidates = computed(() => leaveCandidates.value.filter(candidate =>
  isLeaveCandidateSelectable(candidate) && selectedLeaveCandidates.value.includes(candidate.employment_id)))
const averageCandidatePage = computed(() => Math.floor(
  averageCandidateOffset.value / averageCandidatePageSize,
) + 1)
const selectedReadyAverageCandidates = computed(() => averageCandidates.value.filter(candidate =>
  candidate.ready && selectedAverageCandidates.value.includes(candidate.employment_id)))
const fieldClass = 'mt-1 h-10 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm text-neutral-900 outline-none focus:border-payroll-500 focus:ring-2 focus:ring-payroll-500/20'
const textareaClass = 'mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm text-neutral-900 outline-none focus:border-payroll-500 focus:ring-2 focus:ring-payroll-500/20'
const absenceTypes: AbsenceType[] = [
  'vacation', 'dpn', 'quarantine', 'ocr', 'long_term_care', 'ppm',
  'paternity', 'parental', 'unpaid_leave', 'employee_obstacle',
  'employer_obstacle', 'compensatory_time_off', 'unexcused',
  'public_function', 'employee_obstacle_unpaid', 'invalid_termination', 'other',
]
/*
 * Druhy, u kterých formulář vysvětlí, co se s nepřítomností stane: náhrada
 * mzdy se nepočítá a hodiny jdou do hlášení jen jako neodpracované.
 */
const UNPAID_EXCUSED_TYPES: readonly string[] = ['public_function', 'employee_obstacle_unpaid']
const manualLeaveEntryTypes = ['carryover', 'adjustment', 'shortening', 'overdrawn', 'payout']
/*
 * Období, od kterého firma vede mzdy v MyÚčtu. Čerpání (`taken`) jde zapsat
 * ručně jen PŘED ním — od něj vzniká schválením nepřítomnosti s rozvrženými
 * směnami. Bez známé hranice se typ vůbec nenabízí; server by ho stejně odmítl.
 */
const payrollStartPeriod = ref<string | null>(null)
const leaveEntryTypes = computed(() => payrollStartPeriod.value
  ? [...manualLeaveEntryTypes, 'taken']
  : manualLeaveEntryTypes)

const absenceForm = reactive({
  employment_id: 0,
  absence_type: 'vacation',
  date_from: monthStart,
  date_to: monthStart,
  expected_childbirth_date: '',
  childbirth_date: '',
  lone_carer: false,
  timezone_name: 'Europe/Prague',
  partial_first_hours: null as number | null,
  partial_last_hours: null as number | null,
  average_snapshot_id: null,
  note: null,
  obstacle_kind: null as ObstacleKind | null,
  obstacle_rate_percent: null as number | null,
  obstacle_rate_reason: '',
})
/*
 * Tabulka druhů placených překážek a jejich sazeb přichází se seznamem vztahů
 * ze serveru — formulář z ní jen předvyplní sazbu a ukáže meze, rozhoduje
 * validace na serveru.
 */
const obstacleRules = ref<PayrollObstacleKindRule[]>([])
const averageForm = reactive({
  employment_id: 0,
  applicable_year: periodYear,
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
/*
 * Návrh vstupů průměru z uzavřených běhů. Formulář ho jen PŘEDVYPLNÍ — účetní
 * čísla vidí, smí je přepsat a teprve jejím odesláním průměr vznikne. Proto se
 * vedle návrhu drží i to, co se doopravdy předvyplnilo (`averagePrefill`):
 * podle něj se pozná, které pole už člověk ručně změnil, a poznámka „odvozeno
 * z běhů" u něj přestane platit.
 */
/*
 * Rok a čtvrtletí hromadného výpočtu za celou firmu — nezávislé na
 * `averageForm`, ten patří jednomu vybranému vztahu.
 */
const bulkAverageYear = ref(periodYear)
const bulkAverageQuarter = ref(applicationQuarter)
const averageSuggestion = ref<AverageEarningSuggestion | null>(null)
const averageSuggestionLoading = ref(false)
const averageSuggestionError = ref('')
const averagePrefill = ref<{ gross: number, hours: number, days: number } | null>(null)
const entitlementForm = reactive({
  employment_id: 0,
  leave_year: periodYear,
  weekly_hours: 40,
  entitlement_weeks: 4,
  continuous_calendar_days: 365,
  worked_equivalent_hours: 2080,
  rationale: '',
})
const entryForm = reactive({
  employment_id: 0,
  leave_year: periodYear,
  effective_date: `${periodYear}-01-01`,
  entry_type: 'adjustment',
  hours_delta: 1,
  reason: '',
  source_reference: '',
})
type DpnReduction = 'none' | 'half_192_4' | 'reduced_192_5'
const DPN_REDUCTIONS: DpnReduction[] = ['none', 'half_192_4', 'reduced_192_5']
const dpnReviews = reactive<Record<number, {
  firstDayFullyWorked: boolean
  insuranceConfirmed: boolean
  noConflictingBenefit: boolean
  notEligible: boolean
  reduction: DpnReduction
  reductionMode: 'percent' | 'amount'
  reductionValue: string
  reductionReason: string
}>>({})

const approvedAverages = computed(() => averages.value.filter(item => item.status === 'approved'))
/*
 * Poznámka „odvozeno z uzavřených běhů" smí u čísla stát jen do chvíle, než ho
 * účetní přepíše. Pak už neplatí a musí to být vidět — jinak by potvrzovala
 * původ, který ta hodnota nemá.
 */
const averageSuggestionEdited = computed(() => {
  const prefill = averagePrefill.value
  if (prefill === null) return false
  return prefill.gross !== averageForm.gross_earnings_czk
    || prefill.hours !== averageForm.worked_hours
    || prefill.days !== averageForm.worked_days
})
const averageSuggestionBlockers = computed(() => (averageSuggestion.value?.blockers ?? [])
  .map(code => t(`payroll_absence.averages.blockers.${code}`)))
/*
 * Pravděpodobný výdělek (§ 355 ZP) se nabízí JEN tam, kde skutečný průměr
 * vzniknout nemůže. U běžného vztahu se počítá z uzavřených běhů sám a pole
 * navíc by běžnou cestu jen prodloužilo o otázku, na kterou se nemá odpovídat.
 */
const averageProbableApplies = computed(() => {
  const suggestion = averageSuggestion.value
  if (suggestion === null) return averageForm.probable_hourly_czk !== null
  return suggestion.source_kind === 'probable'
    || suggestion.blockers.includes('probable_earning_not_recorded')
})
/*
 * Firma bez jediného pracovního vztahu. Celá stránka stojí na výběru
 * zaměstnance, takže filtr ani záložky nemají co ukazovat — místo prázdných
 * ovládacích prvků se vykreslí rozcestník do evidence osob.
 */
const hasNoEmployments = computed(() => !loading.value && !loadFailed.value && employments.value.length === 0)
/*
 * Firemní přehled. Bez vybraného vztahu se seznam nepřítomností načte za celou
 * firmu — u pěti set zaměstnanců je proklikat po jednom jediný způsob, jak
 * zjistit, co čeká na rozhodnutí, a to není přehled. Průměry a kniha dovolené
 * se ale vedou k jednomu vztahu, takže ty v tomhle režimu nemají co ukázat.
 */
const allEmployees = computed(() => selectedEmploymentId.value === null)
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
const leaveEntryTypeOptions = computed(() => leaveEntryTypes.value.map(type => ({
  value: type,
  label: type === 'taken'
    ? t('payroll_absence.leave.historic_taken_option')
    : t(`payroll_absence.leave.types.${type}`),
  ...(type === 'taken'
    ? { secondary: t('payroll_absence.leave.historic_taken_option_note', { period: payrollStartPeriod.value ?? '' }) }
    : {}),
})))
const isHistoricTakenEntry = computed(() => entryForm.entry_type === 'taken')
const needsAverage = computed(() =>
  ['vacation', 'dpn', 'quarantine', 'employee_obstacle', 'employer_obstacle']
    .includes(absenceForm.absence_type),
)
/*
 * Peněžitá pomoc v mateřství je vyloučenou dobou evidenčního listu jen před
 * porodem. Očekávaný den porodu je proto povinný hned při zápisu, skutečný se
 * doplní, až porod nastane, i u schválené nepřítomnosti.
 */
const isMaternity = computed(() => absenceForm.absence_type === 'ppm')
const isUnpaidExcused = computed(() => UNPAID_EXCUSED_TYPES.includes(absenceForm.absence_type))
const isInvalidTermination = computed(() => absenceForm.absence_type === 'invalid_termination')
/*
 * Placená překážka: druh určuje, jaká náhrada mzdy přísluší (100 % u překážek
 * zaměstnance, 80/60/100 % u zaměstnavatele) a do které kolonky měsíčního
 * hlášení patří. Bez druhu server nepřítomnost neuloží.
 */
const isPaidObstacle = computed(() =>
  absenceForm.absence_type === 'employee_obstacle' || absenceForm.absence_type === 'employer_obstacle')
const obstacleKindOptions = computed(() => obstacleRules.value
  .filter(rule => rule.absence_type === absenceForm.absence_type)
  .map(rule => ({
    value: rule.kind,
    label: t(`payroll_absence.obstacle.kinds.${rule.kind}`),
    secondary: obstacleRateLabel(rule),
  })))
const selectedObstacleRule = computed(() =>
  obstacleRules.value.find(rule => rule.kind === absenceForm.obstacle_kind
    && rule.absence_type === absenceForm.absence_type) ?? null)
const obstacleRateFixed = computed(() => selectedObstacleRule.value !== null
  && selectedObstacleRule.value.min_rate_basis_points === selectedObstacleRule.value.max_rate_basis_points)
const obstacleReasonRequired = computed(() => obstacleRateReasonRequired(
  selectedObstacleRule.value,
  absenceForm.obstacle_rate_percent,
))
watch(() => absenceForm.absence_type, () => {
  if (selectedObstacleRule.value === null) {
    absenceForm.obstacle_kind = null
    absenceForm.obstacle_rate_percent = null
  }
})
watch(() => absenceForm.obstacle_kind, () => {
  const rule = selectedObstacleRule.value
  absenceForm.obstacle_rate_percent = rule === null ? null : rule.default_rate_basis_points / 100
})
const childbirthEditing = ref<number | null>(null)
const childbirthDraft = ref('')

function canRecordChildbirth(item: PayrollAbsence) {
  return item.absence_type === 'ppm'
    && !item.childbirth_date
    && (item.status === 'requested' || item.status === 'approved')
}

function openChildbirth(item: PayrollAbsence) {
  childbirthEditing.value = item.id
  childbirthDraft.value = ''
}

async function recordChildbirth(item: PayrollAbsence) {
  if (!childbirthDraft.value) return
  saving.value = true
  try {
    await payrollAbsenceApi.recordChildbirth(item.id, item.row_version, childbirthDraft.value)
    childbirthEditing.value = null
    toast.success(t('payroll_absence.absences.childbirth_recorded'))
    await loadData()
  } catch (error: any) {
    showPayrollError(error, t('payroll_absence.messages.save_failed'))
  } finally {
    saving.value = false
  }
}

/*
 * Dny okna náhrady mzdy (§ 192 ZP) vyčerpané u DPN/karantény ještě PŘED
 * touhle nepřítomností — u předchozího zaměstnavatele nebo předchozího
 * mzdového programu. Editovatelné u obou stavů, ve kterých okno ještě má
 * smysl počítat (schválení náhradu teprve spočítá); server sám odmítne
 * absenci, ke které je náhrada už spočítaná, i uzavřený rok.
 */
const sicknessWindowCarriedEditing = ref<number | null>(null)
const sicknessWindowCarriedDraft = ref('')

function canEditSicknessWindowCarried(item: PayrollAbsence) {
  return ['dpn', 'quarantine'].includes(item.absence_type)
}

function openSicknessWindowCarried(item: PayrollAbsence) {
  sicknessWindowCarriedEditing.value = item.id
  sicknessWindowCarriedDraft.value = String(item.sickness_window_carried_days)
}

async function saveSicknessWindowCarried(item: PayrollAbsence) {
  const days = Number(sicknessWindowCarriedDraft.value)
  if (!Number.isInteger(days) || days < 0) return
  saving.value = true
  try {
    await payrollAbsenceApi.setSicknessWindowCarried(item.id, item.row_version, days)
    sicknessWindowCarriedEditing.value = null
    toast.success(t('payroll_absence.absences.sickness_window_carried_saved'))
    await loadData()
  } catch (error: any) {
    showPayrollError(error, t('payroll_absence.messages.save_failed'))
  } finally {
    saving.value = false
  }
}
/*
 * Co bude chybět při SCHVÁLENÍ. Dovolená, DPN a překážky se počítají
 * z průměrného výdělku, takže bez něj nemá server z čeho počítat náhradu —
 * uložit nepřítomnost to ale nebrání, jen ji nepůjde schválit. Rozlišujeme dva
 * různé stavy: průměr existuje a jen není vybraný (uživatel ho doplní tady)
 * versus pro vztah žádný spočítaný není (musí se nejdřív spočítat na záložce
 * Průměry — bez odkazu tam uživatel netrefil).
 */
const missingAverage = computed(() =>
  needsAverage.value && absenceForm.average_snapshot_id === null)
const noAverageAvailable = computed(() =>
  missingAverage.value && averageOptions.value.length === 0)
/*
 * Spočítaný průměr čekající na schválení není totéž co žádný průměr.
 *
 * Nabídka bere jen schválené, takže hláška tvrdila „pro tento vztah není
 * spočítaný žádný průměrný výdělek" i nad průměrem, který na Průměrech leží
 * v ručním posouzení. Účetní ho pak počítala znovu a založila duplicitu místo
 * toho, aby ten existující schválila.
 */
const unapprovedAverageWaiting = computed(() =>
  noAverageAvailable.value && averages.value.some(item => item.status === 'manual_review'))
const absenceBlockedReason = computed<string | null>(() => {
  if (!missingAverage.value) return null
  if (unapprovedAverageWaiting.value) {
    return t('payroll_absence.absences.average_awaiting_approval')
  }
  return noAverageAvailable.value
    ? t('payroll_absence.absences.average_missing_for_relation')
    : t('payroll_absence.absences.average_required_hint')
})

/*
 * Chybějící průměr UŽ NEBLOKUJE ULOŽENÍ. Nepřítomnost je evidence: účetní se
 * o dovolené nebo neschopence dozví dřív, než je spočítaný a schválený
 * čtvrtletní průměr, a obrazovka, která ji do té doby nepustí nic uložit, ji
 * nutí držet papír na stole a pamatovat si ho. Kontrola se posunula tam, kde
 * z ní vznikají peníze — na SCHVÁLENÍ absence (server ji tam odmítne s hláškou,
 * co doplnit). Věta pod tlačítkem zůstává, ale je to upozornění, ne závora.
 */
const canCreateAbsence = computed(() => !saving.value)

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

function applyQuerySelection() {
  const requestedTab = preselectedTab()
  if (requestedTab !== null) tab.value = requestedTab
  if (requestedTab === 'averages') {
    const selectedYear = Number(queryParam('year'))
    const selectedQuarter = Number(queryParam('quarter'))
    if (Number.isInteger(selectedYear) && selectedYear >= minimumFormYear && selectedYear <= maximumFormYear
      && Number.isInteger(selectedQuarter) && selectedQuarter >= 1 && selectedQuarter <= 4) {
      averageForm.applicable_year = selectedYear
      averageForm.applicable_quarter = selectedQuarter
      bulkAverageYear.value = selectedYear
      bulkAverageQuarter.value = selectedQuarter
    }
  }
  const requestedPeriod = queryParam('period')
  if (requestedTab === 'absences' && requestedPeriod && /^\d{4}-(0[1-9]|1[0-2])$/.test(requestedPeriod)) {
    absenceOffset.value = 0
    filterFrom.value = `${requestedPeriod}-01`
    const [requestedYear, requestedMonth] = requestedPeriod.split('-').map(Number)
    filterTo.value = localDate(new Date(requestedYear!, requestedMonth!, 0))
  }
  if (employments.value.length === 0) return
  const requestedEmploymentId = preselectedEmploymentId()
  if (requestedEmploymentId === null && selectedEmploymentId.value !== null) return
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

async function loadContext() {
  const context = await payrollAbsenceApi.absenceContext()
  employments.value = context.employments
  obstacleRules.value = context.obstacleKinds
  applyQuerySelection()
}

watch(() => [route.query.employment, route.query.tab, route.query.year, route.query.quarter, route.query.period], () => {
  if (employments.value.length === 0) return
  const previousEmployment = selectedEmploymentId.value
  applyQuerySelection()
  if (selectedEmploymentId.value === previousEmployment) void loadData()
})

async function loadData() {
  const sequence = ++dataLoadSequence
  if (employments.value.length === 0) {
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
    // `employment_id` je na serveru nepovinné; bez něj vrátí stránku napříč
    // firmou a řádek nese jméno i kód vztahu, takže se nedohledává druhým dotazem.
    const [absencePage, averageData, leaveData] = await Promise.all([
      payrollAbsenceApi.absencesPage(filterFrom.value, filterTo.value, employmentId ?? undefined, {
        limit: absencePageSize,
        offset: absenceOffset.value,
      }),
      employmentId === null
        ? Promise.resolve<AverageSnapshot[]>([])
        : payrollAbsenceApi.averages(employmentId),
      employmentId === null
        ? Promise.resolve(null)
        : payrollAbsenceApi.leaveLedger(employmentId, leaveYear.value),
    ])
    if (sequence !== dataLoadSequence || employmentId !== selectedEmploymentId.value) return
    absences.value = absencePage.absences
    absenceTotal.value = absencePage.total
    selectedAbsenceIds.value = selectedAbsenceIds.value.filter(id =>
      absencePage.absences.some(item => item.id === id && item.status === 'requested'))
    for (const item of absencePage.absences) {
      if (['dpn', 'quarantine'].includes(item.absence_type) && !dpnReviews[item.id]) {
        dpnReviews[item.id] = {
          firstDayFullyWorked: false,
          insuranceConfirmed: false,
          noConflictingBenefit: false,
          notEligible: false,
          reduction: 'none',
          reductionMode: 'percent',
          reductionValue: '',
          reductionReason: '',
        }
      }
    }
    averages.value = averageData
    leaveEntries.value = leaveData?.entries ?? []
    leaveBalance.value = leaveData?.balance_minutes ?? 0
    payrollStartPeriod.value = leaveData?.payroll_start_period ?? null
    if (entryForm.entry_type === 'taken' && payrollStartPeriod.value === null) {
      entryForm.entry_type = 'adjustment'
    }
  } catch (error: any) {
    if (sequence !== dataLoadSequence) return
    // Nepřítomnosti, průměry ani nárok se nemažou. Prázdný seznam by tu byl
    // obzvlášť zrádný: „žádná dovolená" a „nevíme" vedou k opačnému jednání.
    loadFailed.value = true
    toast.error(error?.response?.data?.error?.message || t('payroll_absence.messages.load_failed'))
  } finally {
    if (sequence === dataLoadSequence) loading.value = false
  }
}

// Stránkuje sdílená `PaginationBar` (číslo stránky od jedné); server zná offset.
// Platí i pro firemní přehled — strop drží server, `total` je jediné, z čeho se
// pozná, že další nepřítomnosti existují.
function goToAbsencePage(nextPage: number) {
  absenceOffset.value = Math.max(0, (nextPage - 1) * absencePageSize)
  // Výběr se vztahuje k řádkům na stránce; jinak by dávka schválila i to,
  // co uživatel na obrazovce nevidí.
  selectedAbsenceIds.value = []
  void loadData()
}

function showAllEmployees() {
  selectedEmployeeId.value = null
  selectedEmploymentId.value = null
}

async function createAbsence() {
  absenceError.value = ''
  saving.value = true
  try {
    const payload: AbsencePayload = {
      employment_id: absenceForm.employment_id,
      absence_type: absenceForm.absence_type as AbsenceType,
      date_from: absenceForm.date_from,
      date_to: absenceForm.date_to,
      expected_childbirth_date: isMaternity.value ? (absenceForm.expected_childbirth_date || null) : null,
      childbirth_date: isMaternity.value ? (absenceForm.childbirth_date || null) : null,
      lone_carer: absenceForm.absence_type === 'ocr' && absenceForm.lone_carer,
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
      ...(isPaidObstacle.value
        ? {
            obstacle_kind: absenceForm.obstacle_kind,
            compensation_rate_basis_points: percentToBasisPoints(absenceForm.obstacle_rate_percent),
            compensation_rate_reason: absenceForm.obstacle_rate_reason.trim() || null,
          }
        : {}),
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

/**
 * Co se schválením nebo zrušením nepřítomnosti stalo s případem dávky.
 *
 * Lhůta NEMPRI běží od události (§ 97 zák. č. 187/2006 Sb.), proto schválená
 * neschopnost, OČR nebo mateřská rovnou založí případ. Účetní to musí vidět
 * i s odkazem, kde případ doplní — a když případ nevznikl, proč.
 */
const sicknessNotice = ref<{ text: string, warning: boolean } | null>(null)
const shiftsMissing = ref<{ name: string, employmentId: number, period: string, message: string } | null>(null)
/** Co po schválení opravit jinde: docházka přes dny nepřítomnosti, schválený běh (C-22). */
const approvalWarnings = ref<Array<{ name: string, code: string, message: string, path: string }>>([])

function collectApprovalWarnings(item: PayrollAbsence, result: { warnings?: Array<{ code: string, message: string, path: string }> } | null | undefined) {
  return (result?.warnings ?? []).map(warning => ({ name: item.full_name, ...warning }))
}

function showSicknessNotice(outcome: PayrollAbsenceSicknessCaseOutcome | null | undefined): void {
  if (!outcome) {
    sicknessNotice.value = null
    return
  }
  const kind = outcome.benefit_kind
    ? t(`payroll.sicknessCases.benefitKinds.${outcome.benefit_kind}`)
    : ''
  const message = reasonText(outcome.reason_code, outcome.message)
  // Založený případ, kterému chybí údaj (hodiny prvního dne), hlásí výzvu
  // k doplnění; bez ní by NEMPRI z případu neprošlo.
  // Událost z doby předchozího programu: podal-li podání on, rozhodne účetní.
  const predecessorPeriod = outcome.outcome === 'created' && outcome.reason_code === 'sickness_case_predecessor_period'
  const incomplete = outcome.outcome === 'created' && Boolean(outcome.reason_code) && !predecessorPeriod
  let text = outcome.outcome === 'created'
    ? (outcome.nempri_due_on
        ? t('payroll.sicknessCases.absenceNotice.created', { kind, due: formatDate(outcome.nempri_due_on) })
        : t('payroll.sicknessCases.absenceNotice.createdNoDue', { kind }))
    : t(`payroll.sicknessCases.absenceNotice.${outcome.outcome}`, { kind, message })
  if (incomplete) {
    text += ' ' + t('payroll.sicknessCases.absenceNotice.incomplete', { kind, message })
  }
  if (predecessorPeriod) {
    text += ' ' + t('payroll.sicknessCases.absenceNotice.predecessorPeriod', { kind, message })
  }
  sicknessNotice.value = {
    text,
    warning: outcome.outcome === 'skipped' || outcome.outcome === 'kept' || incomplete || predecessorPeriod,
  }
}

/*
 * DPN bez nároku je VÝSLOVNÁ volba, ne nezaškrtnuté potvrzení účasti: server
 * nezaškrtnuté políčko odmítne, aby se neschopnost tiše neschválila bez náhrady.
 * Snížení náhrady se posílá jen tehdy, když ho účetní zvolila.
 */
type DpnDecisionExtras = {
  insurance_eligibility?: 'not_eligible'
  compensation_reduction?: DpnReduction
  compensation_reduction_reason?: string
  compensation_reduction_basis_points?: number
  compensation_reduction_minor?: number
}

function dpnDecisionExtras(review: (typeof dpnReviews)[number] | undefined): DpnDecisionExtras {
  if (!review) return {}
  if (review.notEligible) return { insurance_eligibility: 'not_eligible' }
  if (review.reduction === 'none') return {}
  const extras: DpnDecisionExtras = {
    compensation_reduction: review.reduction,
    compensation_reduction_reason: review.reductionReason,
  }
  if (review.reduction === 'reduced_192_5') {
    const value = Number(review.reductionValue.replace(',', '.'))
    if (review.reductionMode === 'percent') extras.compensation_reduction_basis_points = Math.round(value * 100)
    else extras.compensation_reduction_minor = Math.round(value * 100)
  }
  return extras
}

async function decide(
  item: PayrollAbsence,
  decision: 'approved' | 'rejected',
  overdrawConfirmed = false,
) {
  const review = dpnReviews[item.id]
  saving.value = true
  shiftsMissing.value = null
  try {
    const result = await payrollAbsenceApi.decide(item.id, {
      row_version: item.row_version,
      decision,
      first_day_fully_worked: review?.firstDayFullyWorked ?? false,
      insurance_eligibility_confirmed: review?.notEligible ? false : (review?.insuranceConfirmed ?? false),
      conflicting_benefit_excluded: review?.notEligible ? false : (review?.noConflictingBenefit ?? false),
      ...(decision === 'approved' ? dpnDecisionExtras(review) : {}),
      ...(overdrawConfirmed ? { overdraw_confirmed: true } : {}),
    })
    showSicknessNotice(result?.sickness_case)
    approvalWarnings.value = collectApprovalWarnings(item, result)
    overdrawPrompt.value = null
    toast.success(t(`payroll_absence.messages.${decision}`))
    if (result.calculation?.warning === 'obstacle_without_published_shifts') {
      toast.warning(t('payroll_absence.obstacle.without_shifts', { name: item.full_name }))
    }
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
    // DPN bez rozvrhu směn: hláška s proklikem zůstane stát, toast by zmizel
    // dřív, než uživatel pochopí, kde se směny zakládají.
    if (payload?.code === 'absence_shifts_missing' && typeof payload.employment_id === 'number') {
      shiftsMissing.value = {
        name: item.full_name,
        employmentId: payload.employment_id,
        period: typeof payload.period === 'string' ? payload.period : item.date_from.slice(0, 7),
        message: payload.message,
      }
      return
    }
    toast.error(payload?.message || t('payroll_absence.messages.save_failed'))
  } finally {
    saving.value = false
  }
}

/**
 * Hromadné schválení nad vybranými řádky.
 *
 * Why: rozhodnout několik set žádostí po jedné kartě je práce na celý den.
 * Dávka ale nesmí rozhodnout nic, co rozhodnutí teprve vyžaduje: u DPN
 * a karantény server schválení odmítne, dokud není potvrzena účast na pojištění
 * a vyloučen souběh dávky, a to je posouzení konkrétního případu. Takové řádky
 * se z dávky vyřadí — ale NEZAHODÍ: vypíšou se jménem i důvodem, ať je vidět,
 * koho zbývá odbavit ručně.
 */
type BulkExclusion = { absenceId: number; name: string; reason: string }
type ApproveFailure = { absenceId: number; name: string; message: string }

const bulkApprovalOpen = ref(false)
const approveFailures = ref<ApproveFailure[]>([])

const selectableAbsences = computed(() =>
  absences.value.filter(item => item.status === 'requested'))
const bulkSelectedItems = computed(() =>
  selectableAbsences.value.filter(item => selectedAbsenceIds.value.includes(item.id)))
const allRequestedSelected = computed(() => selectableAbsences.value.length > 0
  && selectableAbsences.value.every(item => selectedAbsenceIds.value.includes(item.id)))

function bulkExclusionReason(item: PayrollAbsence): string | null {
  if (['dpn', 'quarantine'].includes(item.absence_type)) {
    return t('payroll_absence.bulk.excluded.sickness_checklist')
  }
  return null
}

const bulkCandidates = computed(() =>
  bulkSelectedItems.value.filter(item => bulkExclusionReason(item) === null))
const bulkExclusions = computed<BulkExclusion[]>(() => bulkSelectedItems.value
  .map(item => ({ item, reason: bulkExclusionReason(item) }))
  .filter((row): row is { item: PayrollAbsence; reason: string } => row.reason !== null)
  .map(row => ({
    absenceId: row.item.id,
    name: row.item.full_name,
    reason: row.reason,
  })))
const bulkBlockedReason = computed<string | null>(() => bulkCandidates.value.length === 0
  ? t('payroll_absence.bulk.blocked_no_candidates')
  : null)

function toggleAbsenceSelection(id: number) {
  selectedAbsenceIds.value = selectedAbsenceIds.value.includes(id)
    ? selectedAbsenceIds.value.filter(selected => selected !== id)
    : [...selectedAbsenceIds.value, id]
}

function toggleAllRequested() {
  selectedAbsenceIds.value = allRequestedSelected.value
    ? []
    : selectableAbsences.value.map(item => item.id)
}

function openBulkApproval() {
  if (bulkSelectedItems.value.length === 0) return
  approveFailures.value = []
  bulkApprovalOpen.value = true
}

function closeBulkApproval() {
  bulkApprovalOpen.value = false
}

async function approveSelected() {
  const items = bulkCandidates.value
  if (items.length === 0) return
  saving.value = true
  // Nesbírá se PRVNÍ chyba, ale VŠECHNY: „nepodařilo se schválit 3 žádosti"
  // znamená otevřít tři karty a hádat, která na čem spadla. Přečerpaná dovolená
  // sem dopadne jako chyba se serverovou větou — potvrdit ji smí jen člověk,
  // který vidí konkrétní čísla, ne dávka.
  const failures: ApproveFailure[] = []
  let approved = 0
  const withoutShifts: string[] = []
  const warnings: typeof approvalWarnings.value = []
  for (const item of items) {
    try {
      const result = await payrollAbsenceApi.decide(item.id, {
        row_version: item.row_version,
        decision: 'approved',
      })
      approved += 1
      warnings.push(...collectApprovalWarnings(item, result))
      if (result.calculation?.warning === 'obstacle_without_published_shifts') {
        withoutShifts.push(item.full_name)
      }
    } catch (error: any) {
      failures.push({
        absenceId: item.id,
        name: item.full_name,
        message: error?.response?.data?.error?.message
          ?? t('payroll_absence.messages.save_failed'),
      })
    }
  }
  approveFailures.value = failures
  approvalWarnings.value = warnings
  if (approved > 0) toast.success(t('payroll_absence.bulk.approved', { count: approved }))
  if (withoutShifts.length > 0) {
    toast.warning(t('payroll_absence.obstacle.without_shifts', { name: withoutShifts.join(', ') }))
  }
  bulkApprovalOpen.value = false
  selectedAbsenceIds.value = []
  await loadData()
  saving.value = false
}

function clearApproveFailures() {
  approveFailures.value = []
}

/**
 * Zrušení nepřítomnosti se ptá, ale POJMENUJE, čeho se ptá.
 *
 * Undo toast tu nejde: zrušenou nepřítomnost server neumí vrátit a založit ji
 * znovu by z rozhodnuté udělalo znovu žádanou — jiný stav, ne návrat.
 * Zůstává tedy dotaz, ale s koho a čeho se týká: „Opravdu zrušit?" nad
 * seznamem třiceti řádků neříká vůbec nic.
 */
async function cancel(item: PayrollAbsence) {
  if (!window.confirm(t('payroll_absence.absences.cancel_confirm', {
    name: item.full_name,
    type: t(`payroll_absence.types.${item.absence_type}`),
    from: item.date_from,
    to: item.date_to,
  }))) return
  saving.value = true
  try {
    const result = await payrollAbsenceApi.cancel(item.id, item.row_version)
    showSicknessNotice(result?.sickness_case)
    toast.success(t('payroll_absence.messages.cancelled'))
    await loadData()
  } catch (error: any) {
    showPayrollError(error, t('payroll_absence.messages.save_failed'))
  } finally {
    saving.value = false
  }
}

/**
 * Načte návrh vstupů průměru pro právě vybraný vztah a čtvrtletí.
 *
 * Odpověď na jiný vztah nebo jiné čtvrtletí, než na které se uživatel mezitím
 * přepnul, se ZAHODÍ. Bez toho by pomalejší dřívější požadavek přepsal
 * formulář čísly cizího zaměstnance — a průměrný výdělek jsou peníze
 * a údaj do hlášení ČSSZ, takže tichá záměna je tady to nejhorší, co se může stát.
 */
async function loadAverageSuggestion() {
  const employmentId = averageForm.employment_id
  const requestedYear = averageForm.applicable_year
  const requestedQuarter = averageForm.applicable_quarter
  averageSuggestion.value = null
  averagePrefill.value = null
  averageSuggestionError.value = ''
  if (!employmentId
    || !Number.isInteger(requestedYear)
    || !Number.isInteger(requestedQuarter)
    || requestedQuarter < 1 || requestedQuarter > 4
  ) return
  averageSuggestionLoading.value = true
  try {
    const suggestion = await payrollAbsenceApi.averageSuggestion(
      employmentId,
      requestedYear,
      requestedQuarter,
    )
    if (averageForm.employment_id !== employmentId
      || averageForm.applicable_year !== requestedYear
      || averageForm.applicable_quarter !== requestedQuarter
    ) return
    averageSuggestion.value = suggestion
    applyAverageSuggestion(suggestion)
  } catch (error: any) {
    averageSuggestionError.value = exactError(error, 'payroll_absence.averages.suggestion_failed')
  } finally {
    averageSuggestionLoading.value = false
  }
}

/*
 * Nedá-li se odvodit, formulář se VYPRÁZDNÍ (nuly, tedy výchozí stav) — nechat
 * v něm čísla z předchozího vztahu nebo čtvrtletí by bylo horší než prázdno.
 */
function applyAverageSuggestion(suggestion: AverageEarningSuggestion) {
  averageForm.decisive_from = suggestion.decisive_from
  averageForm.decisive_to = suggestion.decisive_to
  const gross = (suggestion.gross_earnings_minor ?? 0) / 100
  const hours = (suggestion.worked_minutes ?? 0) / 60
  const days = suggestion.worked_days ?? 0
  averageForm.gross_earnings_czk = gross
  averageForm.worked_hours = hours
  averageForm.worked_days = days
  // § 355 ZP — skutečný průměr nevznikne, průměr se STANOVUJE z pravděpodobného
  // výdělku zmrazeného v podmínkách vztahu. Účetní ho tady nepřepisuje, jen
  // potvrzuje; přepsat ho jde na kartě vztahu, kde ho i odůvodnila.
  averageForm.probable_hourly_czk = suggestion.source_kind === 'probable'
    ? (suggestion.probable_hourly_minor ?? 0) / 100
    : null
  if (suggestion.source_kind === 'probable' && suggestion.probable_rationale !== null) {
    averageForm.rationale = suggestion.probable_rationale
  }
  averagePrefill.value = suggestion.ready ? { gross, hours, days } : null
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
    showPayrollError(error, t('payroll_absence.messages.save_failed'))
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
      page.items.some(candidate => isLeaveCandidateSelectable(candidate) && candidate.employment_id === id))
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
    .filter(isLeaveCandidateSelectable)
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
      absence_decisions: Object.fromEntries(
        [...new Set(selectedReadyCandidates.value.flatMap(assessmentTypes))]
          .filter(type => absenceDecisions.value[type] !== undefined)
          .map(type => [type, absenceDecisions.value[type]]),
      ),
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

async function loadAverageCandidates() {
  if (!Number.isInteger(bulkAverageYear.value)
    || !Number.isInteger(bulkAverageQuarter.value)
    || bulkAverageQuarter.value < 1 || bulkAverageQuarter.value > 4
  ) return
  averageCandidateLoading.value = true
  averageCandidateError.value = ''
  try {
    const page = await payrollAbsenceApi.averageCandidates(
      bulkAverageYear.value,
      bulkAverageQuarter.value,
      { limit: averageCandidatePageSize, offset: averageCandidateOffset.value },
    )
    averageCandidates.value = page.items
    averageCandidateTotal.value = page.total
    selectedAverageCandidates.value = selectedAverageCandidates.value.filter(id =>
      page.items.some(candidate => candidate.ready && candidate.employment_id === id))
  } catch (error: any) {
    averageCandidateError.value = exactError(error, 'payroll_absence.averages.bulk_load_failed')
  } finally {
    averageCandidateLoading.value = false
  }
}

function goToAverageCandidatePage(nextPage: number) {
  averageCandidateOffset.value = Math.max(0, (nextPage - 1) * averageCandidatePageSize)
  selectedAverageCandidates.value = []
  void loadAverageCandidates()
}

function selectAllReadyAverageCandidates() {
  selectedAverageCandidates.value = averageCandidates.value
    .filter(candidate => candidate.ready)
    .map(candidate => candidate.employment_id)
}

async function createAveragesBulk() {
  if (selectedReadyAverageCandidates.value.length === 0) return
  saving.value = true
  averageCandidateError.value = ''
  try {
    const created = await payrollAbsenceApi.createAveragesBulk({
      year: bulkAverageYear.value,
      quarter: bulkAverageQuarter.value,
      items: selectedReadyAverageCandidates.value.map(candidate => ({
        employment_id: candidate.employment_id,
        input_version: candidate.input_version,
      })),
    })
    toast.success(t('payroll_absence.averages.bulk_created', { count: created.length }))
    selectedAverageCandidates.value = []
    await loadAverageCandidates()
  } catch (error: any) {
    // Podklady se mezitím změnily (nový/opravený běh) — návrh už neplatí,
    // znovunačtení ukáže aktuální stav místo tichého založení na starých číslech.
    // Zpráva se nastaví AŽ PO reloadu — `loadAverageCandidates` si chybu na
    // začátku maže, takže dřívější pořadí by ji hned smazalo.
    if (apiErrorCode(error) === 'average_inputs_changed') {
      await loadAverageCandidates()
      averageCandidateError.value = t('payroll_absence.averages.bulk_inputs_changed')
    } else {
      averageCandidateError.value = exactError(error, 'payroll_absence.messages.save_failed')
    }
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
      // Doložení původu patří jen k převzatému čerpání; u ostatních typů ho
      // server odmítne, protože se nemá k čemu vztáhnout.
      ...(isHistoricTakenEntry.value ? { source_reference: entryForm.source_reference } : {}),
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

/*
 * Převzaté čerpání zůstatek snižuje, takže se hodiny předvyplní záporně.
 * Je to PŘEDVYPLNĚNÍ, ne tichá úprava odesílané hodnoty — uživatel znaménko
 * vidí a smí ho přepsat; server kladnou hodnotu odmítne.
 */
watch(() => entryForm.entry_type, (type, previous) => {
  if (type === previous) return
  if (type === 'taken') {
    entryForm.hours_delta = -Math.abs(entryForm.hours_delta || 8)
  } else {
    entryForm.source_reference = ''
  }
})
watch(selectedEmployeeId, employeeId => {
  const available = employments.value.filter(item => item.employee_id === employeeId)
  if (!available.some(item => item.id === selectedEmploymentId.value)) {
    selectedEmploymentId.value = available[0]?.id ?? null
  }
})
watch(selectedEmploymentId, employmentId => {
  dataLoadSequence++
  absenceForm.employment_id = employmentId ?? 0
  averageForm.employment_id = employmentId ?? 0
  entitlementForm.employment_id = employmentId ?? 0
  entryForm.employment_id = employmentId ?? 0
  absenceForm.average_snapshot_id = null
  averages.value = []
  leaveEntries.value = []
  leaveBalance.value = 0
}, { flush: 'sync' })
watch(selectedEmploymentId, () => {
  absenceForm.average_snapshot_id = null
  absenceError.value = ''
  averageError.value = ''
  entitlementError.value = ''
  entryError.value = ''
  // Jiný vztah = jiná množina nepřítomností; třetí stránka by ukázala prázdno.
  absenceOffset.value = 0
  selectedAbsenceIds.value = []
  approveFailures.value = []
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
    void loadAverageSuggestion()
  },
)
watch(() => averageForm.employment_id, () => {
  void loadAverageSuggestion()
})
watch([bulkAverageYear, bulkAverageQuarter], () => {
  averageCandidateOffset.value = 0
  selectedAverageCandidates.value = []
  void loadAverageCandidates()
})
onMounted(async () => {
  try {
    await loadContext()
    await Promise.all([loadData(), loadLeaveCandidates(), loadAverageCandidates()])
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

    <section
      v-if="sicknessNotice"
      class="flex flex-wrap items-center gap-2 rounded-xl border p-4 text-sm"
      :class="sicknessNotice.warning
        ? 'border-warning-200 bg-warning-50 text-warning-800'
        : 'border-info-200 bg-info-50 text-info-800'"
      data-test="absence-sickness-case-notice"
    >
      <span>{{ sicknessNotice.text }}</span>
      <RouterLink
        to="/payroll/submissions/sickness"
        class="font-semibold underline"
        data-test="absence-sickness-case-link"
      >
        {{ t('payroll.sicknessCases.absenceNotice.open') }}
      </RouterLink>
    </section>

    <section
      v-for="(warning, index) in approvalWarnings"
      :key="`${warning.code}-${index}`"
      class="flex flex-wrap items-center gap-2 rounded-xl border border-warning-200 bg-warning-50 p-4 text-sm text-warning-800"
      role="alert"
      :data-test="`absence-approval-warning-${warning.code}`"
    >
      <span class="min-w-0 flex-1">
        <strong>{{ warning.name }}:</strong> {{ warning.message }}
      </span>
      <RouterLink :to="warning.path" :class="[btnOutline('warning'), 'whitespace-nowrap']">
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.edit" /></svg>
        {{ t('payroll_absence.approval_warning_open') }}
      </RouterLink>
    </section>

    <section
      v-if="shiftsMissing"
      class="flex flex-wrap items-center gap-2 rounded-xl border border-warning-200 bg-warning-50 p-4 text-sm text-warning-800"
      role="alert"
      data-test="absence-shifts-missing"
    >
      <span class="min-w-0 flex-1">
        <strong>{{ shiftsMissing.name }}:</strong> {{ shiftsMissing.message }}
      </span>
      <RouterLink
        :to="{ path: '/payroll/time', query: { employment: String(shiftsMissing.employmentId), period: shiftsMissing.period } }"
        :class="[btnFilled('primary'), 'whitespace-nowrap']"
        data-test="absence-shifts-missing-link"
      >
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.calendar" /></svg>
        {{ t('payroll_absence.shifts_missing.open') }}
      </RouterLink>
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
          <!--
            Zrušený výběr = firemní přehled. Bez něj se nepřítomnosti daly číst
            jen po jednom člověku, což u větší firmy znamená proklikat všechny.
          -->
          <PayrollPersonSearchSelect
            v-model="selectedEmployeeId"
            data-test="absence-person"
            :candidates="personOptions"
            :label="t('payroll_absence.employee')"
            :placeholder="t('payroll_absence.all_employees')"
          />
        </div>
        <div>
          <span class="mb-1 block text-xs font-medium text-neutral-600">{{ t('payroll_absence.employment') }}</span>
          <SearchableSelect
            v-model="selectedEmploymentId"
            :options="employmentOptions"
            :clearable="false"
            :placeholder="t('payroll_absence.all_employees')"
            accent="payroll"
            data-test="absence-employment"
            :aria-label="t('payroll_absence.employment')"
          />
        </div>
        <label>
          <span class="mb-1 block text-xs font-medium text-neutral-600">{{ t('payroll_absence.from') }}</span>
          <DateInput v-model="filterFrom" :class="fieldClass" />
        </label>
        <label>
          <span class="mb-1 block text-xs font-medium text-neutral-600">{{ t('payroll_absence.to') }}</span>
          <DateInput v-model="filterTo" :class="fieldClass" />
        </label>
      </div>
      <div class="mt-3 flex flex-wrap items-center justify-end gap-2">
        <button
          type="button"
          :class="btnOutline(allEmployees ? 'primary' : 'neutral')"
          data-test="absence-all-employees"
          :disabled="loading || allEmployees"
          @click="showAllEmployees"
        >
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path :d="ICONS.user" />
          </svg>
          {{ t('payroll_absence.all_employees') }}
        </button>
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
      <p
        v-if="canWrite && allEmployees"
        data-test="absence-create-needs-person"
        class="rounded-xl border border-dashed border-neutral-300 p-4 text-sm text-neutral-600"
      >
        {{ t('payroll_absence.absences.new_needs_person') }}
      </p>
      <section v-if="canWrite && !allEmployees" class="rounded-xl border border-neutral-200 bg-surface p-4 shadow-sm sm:p-6">
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
            <p
              v-if="isUnpaidExcused"
              data-test="absence-unpaid-excused-hint"
              class="mt-1 text-xs text-neutral-500"
            >
              {{ t('payroll_absence.absences.unpaid_excused_hint') }}
            </p>
            <p
              v-if="isInvalidTermination"
              data-test="absence-invalid-termination-hint"
              class="mt-1 text-xs text-neutral-500"
            >
              {{ t('payroll_absence.absences.invalid_termination_hint') }}
            </p>
          </div>
          <template v-if="isPaidObstacle">
            <div class="sm:col-span-2" data-test="absence-obstacle">
              <span class="mb-1 block text-xs font-medium text-neutral-600">{{ t('payroll_absence.obstacle.kind') }}</span>
              <SearchableSelect
                v-model="absenceForm.obstacle_kind"
                data-test="absence-obstacle-kind"
                :options="obstacleKindOptions"
                :placeholder="t('payroll_absence.select')"
                accent="payroll"
                :aria-label="t('payroll_absence.obstacle.kind')"
              />
              <p class="mt-1 text-xs text-neutral-500" data-test="absence-obstacle-hint">
                {{ absenceForm.obstacle_kind
                  ? t(`payroll_absence.obstacle.hints.${absenceForm.obstacle_kind}`)
                  : t(`payroll_absence.obstacle.pick_hint.${absenceForm.absence_type}`) }}
              </p>
            </div>
            <label v-if="selectedObstacleRule">
              <span class="mb-1 block text-xs font-medium text-neutral-600">{{ t('payroll_absence.obstacle.rate') }}</span>
              <input
                v-model.number="absenceForm.obstacle_rate_percent"
                data-test="absence-obstacle-rate"
                type="number"
                step="0.01"
                :min="selectedObstacleRule.min_rate_basis_points / 100"
                :max="selectedObstacleRule.max_rate_basis_points / 100"
                :disabled="obstacleRateFixed"
                :class="fieldClass"
              >
              <span class="mt-1 block text-xs text-neutral-500">
                {{ obstacleRateFixed
                  ? t('payroll_absence.obstacle.rate_fixed')
                  : t('payroll_absence.obstacle.rate_range', {
                    min: selectedObstacleRule.min_rate_basis_points / 100,
                    max: selectedObstacleRule.max_rate_basis_points / 100,
                  }) }}
              </span>
            </label>
            <label v-if="selectedObstacleRule && (obstacleReasonRequired || !obstacleRateFixed)">
              <span class="mb-1 block text-xs font-medium text-neutral-600">
                {{ t('payroll_absence.obstacle.reason') }}<template v-if="obstacleReasonRequired"> *</template>
              </span>
              <input
                v-model="absenceForm.obstacle_rate_reason"
                data-test="absence-obstacle-reason"
                type="text"
                maxlength="500"
                :required="obstacleReasonRequired"
                :class="fieldClass"
              >
              <span class="mt-1 block text-xs text-neutral-500">
                {{ absenceForm.obstacle_kind === 'partial_unemployment'
                  ? t('payroll_absence.obstacle.reason_hint_partial_unemployment')
                  : t('payroll_absence.obstacle.reason_hint') }}
              </span>
            </label>
          </template>
          <label>
            <span class="mb-1 block text-xs font-medium text-neutral-600">{{ t('payroll_absence.from') }}</span>
            <DateInput v-model="absenceForm.date_from" required :class="fieldClass" />
          </label>
          <label>
            <span class="mb-1 block text-xs font-medium text-neutral-600">{{ t('payroll_absence.to') }}</span>
            <DateInput v-model="absenceForm.date_to" required :class="fieldClass" />
          </label>
          <template v-if="isMaternity">
            <label>
              <span class="mb-1 block text-xs font-medium text-neutral-600">{{ t('payroll_absence.absences.expected_childbirth') }}</span>
              <DateInput
                v-model="absenceForm.expected_childbirth_date"
                data-test="absence-expected-childbirth"
                required
                :class="fieldClass"
              />
            </label>
            <label>
              <span class="mb-1 block text-xs font-medium text-neutral-600">{{ t('payroll_absence.absences.childbirth') }}</span>
              <DateInput
                v-model="absenceForm.childbirth_date"
                data-test="absence-childbirth"
                :class="fieldClass"
              />
            </label>
            <p class="text-xs text-neutral-500 sm:col-span-2" data-test="absence-childbirth-hint">
              {{ t('payroll_absence.absences.childbirth_hint') }}
            </p>
          </template>
          <label v-if="absenceForm.absence_type === 'ocr'" class="flex items-start gap-2 sm:col-span-2">
            <input
              v-model="absenceForm.lone_carer"
              data-test="absence-lone-carer"
              type="checkbox"
              class="mt-0.5 h-4 w-4 rounded border-neutral-300"
            >
            <span class="text-sm text-neutral-700">
              {{ t('payroll_absence.absences.lone_carer') }}
              <span class="block text-xs text-neutral-500">{{ t('payroll_absence.absences.lone_carer_hint') }}</span>
            </span>
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
              :title="disabledTitle(!canCreateAbsence, null)"
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
        <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
          <h2 class="text-lg font-semibold text-neutral-900">{{ t('payroll_absence.absences.list') }}</h2>
          <div v-if="canWrite && selectableAbsences.length > 0" class="flex flex-wrap gap-2">
            <button
              type="button"
              :class="btnOutline('neutral')"
              data-test="absence-select-all"
              @click="toggleAllRequested"
            >
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.check" /></svg>
              {{ t(allRequestedSelected ? 'payroll_absence.bulk.clear_selection' : 'payroll_absence.bulk.select_all') }}
            </button>
            <button
              v-if="selectedAbsenceIds.length > 0"
              type="button"
              :class="btnFilled('success')"
              data-test="bulk-approve-open"
              :disabled="saving"
              @click="openBulkApproval"
            >
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.badgeCheck" /></svg>
              {{ t('payroll_absence.bulk.approve', { count: selectedAbsenceIds.length }) }}
            </button>
          </div>
        </div>

        <!--
          Neúspěch dávky patří na obrazovku, ne do toastu, který za pár vteřin
          zmizí: účetní musí vidět KOHO se to týká a PROČ to neprošlo.
        -->
        <section
          v-if="approveFailures.length"
          data-test="absence-approve-error"
          class="mb-4 rounded-xl border border-danger-500/40 bg-danger-50 p-4 text-sm text-danger-700"
        >
          <div class="flex flex-wrap items-start justify-between gap-3">
            <p class="font-semibold">{{ t('payroll_absence.bulk.failed', { count: approveFailures.length }) }}</p>
            <button :class="btnOutline('neutral')" @click="clearApproveFailures">
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.x" /></svg>
              {{ t('common.close') }}
            </button>
          </div>
          <ul class="mt-2 space-y-2">
            <li
              v-for="failure in approveFailures"
              :key="failure.absenceId"
              data-test="absence-approve-error-row"
              class="border-t border-danger-500/20 pt-2 first:border-t-0 first:pt-0"
            >
              <p class="font-medium">{{ failure.name }}</p>
              <p class="mt-0.5 max-w-prose leading-snug">{{ failure.message }}</p>
            </li>
          </ul>
        </section>

        <p v-if="absences.length === 0" class="rounded-xl border border-dashed border-neutral-300 p-8 text-center text-sm text-neutral-500">
          {{ t('payroll_absence.absences.empty') }}
        </p>
        <div v-else class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
          <article v-for="item in absences" :key="item.id" class="rounded-xl border border-neutral-200 bg-surface p-4 shadow-sm">
            <div class="flex items-start justify-between gap-3">
              <div class="flex items-start gap-3">
                <input
                  v-if="canWrite && item.status === 'requested'"
                  type="checkbox"
                  class="mt-1 h-4 w-4 rounded border-neutral-300 text-payroll-600 focus:ring-payroll-500"
                  data-test="absence-select"
                  :checked="selectedAbsenceIds.includes(item.id)"
                  :aria-label="t('payroll_absence.bulk.select', { name: item.full_name })"
                  @change="toggleAbsenceSelection(item.id)"
                >
                <div>
                  <h3 class="font-semibold text-neutral-900">{{ t(`payroll_absence.types.${item.absence_type}`) }}</h3>
                  <p class="mt-0.5 text-sm text-neutral-500">{{ item.full_name }}<template v-if="personalNumberLabel(t, item.employment_code)"> · {{ personalNumberLabel(t, item.employment_code) }}</template></p>
                </div>
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
              <div><dt class="text-neutral-500">{{ t('payroll_absence.period') }}</dt><dd class="font-medium text-neutral-900">{{ formatDate(item.date_from) }} – {{ formatDate(item.date_to) }}</dd></div>
              <div><dt class="text-neutral-500">{{ t('payroll_absence.absences.average') }}</dt><dd class="font-medium text-neutral-900">{{ money(item.average_hourly_minor) }}</dd></div>
              <template v-if="item.absence_type === 'ppm'">
                <div>
                  <dt class="text-neutral-500">{{ t('payroll_absence.absences.expected_childbirth') }}</dt>
                  <dd class="font-medium text-neutral-900">
                    {{ item.expected_childbirth_date ? formatDate(item.expected_childbirth_date) : t('payroll_absence.absences.childbirth_missing') }}
                  </dd>
                </div>
                <div data-test="absence-childbirth-value">
                  <dt class="text-neutral-500">{{ t('payroll_absence.absences.childbirth') }}</dt>
                  <dd class="font-medium text-neutral-900">
                    {{ item.childbirth_date ? formatDate(item.childbirth_date) : t('payroll_absence.absences.childbirth_missing') }}
                  </dd>
                </div>
              </template>
              <div v-if="item.obstacle_kind" class="col-span-2" data-test="absence-obstacle-value">
                <dt class="text-neutral-500">{{ t('payroll_absence.obstacle.kind') }}</dt>
                <dd class="font-medium text-neutral-900">
                  {{ t(`payroll_absence.obstacle.kinds.${item.obstacle_kind}`) }}
                  · {{ t('payroll_absence.obstacle.rate_value', { rate: formatPercent(item.compensation_rate_basis_points ?? 10000) }) }}
                </dd>
                <dd v-if="item.compensation_rate_reason" class="text-xs text-neutral-500">{{ item.compensation_rate_reason }}</dd>
              </div>
              <div
                v-else-if="['employee_obstacle', 'employer_obstacle'].includes(item.absence_type) && item.status === 'requested'"
                class="col-span-2 rounded-lg bg-warning-50 p-2 text-xs text-warning-800"
                data-test="absence-obstacle-missing"
              >
                {{ t('payroll_absence.obstacle.kind_missing') }}
              </div>
              <div v-if="['dpn', 'quarantine'].includes(item.absence_type)" data-test="absence-sickness-window-carried-value">
                <dt class="text-neutral-500">{{ t('payroll_absence.absences.sickness_window_carried_days') }}</dt>
                <dd class="font-medium text-neutral-900">{{ item.sickness_window_carried_days }}</dd>
              </div>
            </dl>
            <p v-if="item.note" class="mt-3 text-sm text-neutral-600">{{ item.note }}</p>
            <div v-if="item.correction_pending" class="mt-3 rounded-lg bg-warning-50 p-2 text-xs text-warning-800">
              {{ t('payroll_absence.absences.correction_pending') }}
            </div>
            <div
              v-if="item.status === 'requested' && ['dpn', 'quarantine'].includes(item.absence_type)"
              data-test="dpn-review"
              class="mt-4 space-y-2 rounded-lg border border-warning-200 bg-warning-50 p-3 text-xs text-warning-900"
            >
              <label class="flex gap-2"><input v-model="dpnReviews[item.id].insuranceConfirmed" type="checkbox" :disabled="dpnReviews[item.id].notEligible"> {{ t('payroll_absence.dpn.insurance') }}</label>
              <label class="flex gap-2"><input v-model="dpnReviews[item.id].noConflictingBenefit" type="checkbox" :disabled="dpnReviews[item.id].notEligible"> {{ t('payroll_absence.dpn.no_conflict') }}</label>
              <label class="flex gap-2"><input v-model="dpnReviews[item.id].firstDayFullyWorked" type="checkbox"> {{ t('payroll_absence.dpn.first_day_worked') }}</label>
              <label class="flex gap-2"><input v-model="dpnReviews[item.id].notEligible" type="checkbox" data-test="dpn-not-eligible"> {{ t('payroll_absence.dpn.not_eligible') }}</label>
              <p
                v-if="dpnReviews[item.id].notEligible || ['dpp', 'dpc'].includes(item.relation_type ?? '')"
                class="text-warning-800"
                data-test="dpn-not-eligible-hint"
              >{{ t('payroll_absence.dpn.not_eligible_hint') }}</p>
              <div v-if="!dpnReviews[item.id].notEligible" class="flex flex-wrap items-end gap-2 border-t border-warning-200 pt-2" data-test="dpn-reduction">
                <label class="min-w-0">
                  <span class="mb-1 block font-medium">{{ t('payroll_absence.dpn.reduction') }}</span>
                  <select v-model="dpnReviews[item.id].reduction" :class="fieldClass" data-test="dpn-reduction-kind">
                    <option v-for="kind in DPN_REDUCTIONS" :key="kind" :value="kind">{{ t(`payroll_absence.dpn.reduction_kinds.${kind}`) }}</option>
                  </select>
                </label>
                <template v-if="dpnReviews[item.id].reduction === 'reduced_192_5'">
                  <label class="min-w-0">
                    <span class="mb-1 block font-medium">{{ t('payroll_absence.dpn.reduction_mode') }}</span>
                    <select v-model="dpnReviews[item.id].reductionMode" :class="fieldClass" data-test="dpn-reduction-mode">
                      <option value="percent">{{ t('payroll_absence.dpn.reduction_modes.percent') }}</option>
                      <option value="amount">{{ t('payroll_absence.dpn.reduction_modes.amount') }}</option>
                    </select>
                  </label>
                  <label class="min-w-0">
                    <span class="mb-1 block font-medium">{{ t('payroll_absence.dpn.reduction_value') }}</span>
                    <input v-model="dpnReviews[item.id].reductionValue" type="text" inputmode="decimal" :class="fieldClass" data-test="dpn-reduction-value">
                  </label>
                </template>
                <label v-if="dpnReviews[item.id].reduction !== 'none'" class="min-w-0 flex-1">
                  <span class="mb-1 block font-medium">{{ t('payroll_absence.dpn.reduction_reason') }}</span>
                  <input v-model="dpnReviews[item.id].reductionReason" type="text" maxlength="500" :class="fieldClass" data-test="dpn-reduction-reason">
                </label>
                <p v-if="dpnReviews[item.id].reduction !== 'none'" class="w-full">{{ t('payroll_absence.dpn.reduction_hint') }}</p>
              </div>
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
            <div v-if="canWrite && canRecordChildbirth(item)" class="mt-4" data-test="childbirth-record">
              <div v-if="childbirthEditing === item.id" class="flex flex-wrap items-end gap-2">
                <label class="min-w-0 flex-1">
                  <span class="mb-1 block text-xs font-medium text-neutral-600">{{ t('payroll_absence.absences.childbirth') }}</span>
                  <DateInput v-model="childbirthDraft" data-test="childbirth-date" required :class="fieldClass" />
                </label>
                <button
                  type="button"
                  :class="btnFilled('primary')"
                  data-test="childbirth-save"
                  :disabled="saving || !childbirthDraft"
                  @click="recordChildbirth(item)"
                >
                  <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.check" /></svg>
                  {{ t('payroll_absence.absences.record_childbirth_save') }}
                </button>
                <button type="button" :class="btnOutline('neutral')" :disabled="saving" @click="childbirthEditing = null">
                  <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.x" /></svg>
                  {{ t('common.cancel') }}
                </button>
                <p class="w-full text-xs text-neutral-500">{{ t('payroll_absence.absences.record_childbirth_once') }}</p>
              </div>
              <button
                v-else
                type="button"
                :class="btnOutline('primary')"
                data-test="childbirth-open"
                :disabled="saving"
                @click="openChildbirth(item)"
              >
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.calendar" /></svg>
                {{ t('payroll_absence.absences.record_childbirth') }}
              </button>
            </div>
            <div v-if="canWrite && canEditSicknessWindowCarried(item)" class="mt-4" data-test="sickness-window-carried">
              <div v-if="sicknessWindowCarriedEditing === item.id" class="flex flex-wrap items-end gap-2">
                <label class="min-w-0 flex-1">
                  <span class="mb-1 block text-xs font-medium text-neutral-600">{{ t('payroll_absence.absences.sickness_window_carried_days') }}</span>
                  <input
                    v-model="sicknessWindowCarriedDraft"
                    type="number"
                    min="0"
                    step="1"
                    data-test="sickness-window-carried-input"
                    :class="fieldClass"
                  >
                </label>
                <button
                  type="button"
                  :class="btnFilled('primary')"
                  data-test="sickness-window-carried-save"
                  :disabled="saving || sicknessWindowCarriedDraft === ''"
                  @click="saveSicknessWindowCarried(item)"
                >
                  <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.check" /></svg>
                  {{ t('common.save') }}
                </button>
                <button type="button" :class="btnOutline('neutral')" :disabled="saving" @click="sicknessWindowCarriedEditing = null">
                  <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.x" /></svg>
                  {{ t('common.cancel') }}
                </button>
                <p class="w-full text-xs text-neutral-500">{{ t('payroll_absence.absences.sickness_window_carried_hint') }}</p>
              </div>
              <button
                v-else
                type="button"
                :class="btnOutline('primary')"
                data-test="sickness-window-carried-open"
                :disabled="saving"
                @click="openSicknessWindowCarried(item)"
              >
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.edit" /></svg>
                {{ t('payroll_absence.absences.sickness_window_carried_edit') }}
              </button>
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

    <!--
      Průměrný výdělek se počítá z rozhodného období JEDNOHO vztahu, kniha
      dovolené vede zůstatek JEDNOHO vztahu. Ve firemním přehledu proto nejde
      ukázat prázdný seznam — vypadal by jako „nic tu není", i když správná
      odpověď je „vyberte osobu".
    -->
    <template v-else-if="tab === 'averages' && allEmployees">
      <section class="rounded-xl border border-neutral-200 bg-surface p-4 shadow-sm sm:p-6" data-test="bulk-average-earnings">
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div>
            <h2 class="font-semibold text-neutral-900">{{ t('payroll_absence.averages.bulk_title') }}</h2>
            <p class="mt-1 text-sm text-neutral-500">{{ t('payroll_absence.averages.bulk_hint') }}</p>
          </div>
          <div class="flex flex-wrap items-end gap-2">
            <label>
              <span class="mb-1 block text-xs font-medium text-neutral-600">{{ t('payroll_absence.averages.year') }}</span>
              <input
                v-model.number="bulkAverageYear"
                data-test="bulk-average-year"
                :min="minimumFormYear"
                :max="maximumFormYear"
                type="number"
                :class="[fieldClass, 'w-28']"
              >
            </label>
            <label>
              <span class="mb-1 block text-xs font-medium text-neutral-600">{{ t('payroll_absence.averages.quarter') }}</span>
              <select v-model.number="bulkAverageQuarter" data-test="bulk-average-quarter" :class="[fieldClass, 'w-24']">
                <option v-for="quarterOption in [1, 2, 3, 4]" :key="quarterOption" :value="quarterOption">Q{{ quarterOption }}</option>
              </select>
            </label>
            <div v-if="canWrite" class="flex flex-wrap gap-2">
              <button type="button" :class="btnOutline('neutral')" :disabled="averageCandidateLoading" @click="selectAllReadyAverageCandidates">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.check" /></svg>
                {{ t('payroll_absence.leave.select_ready') }}
              </button>
              <button
                type="button"
                :class="btnFilled('success')"
                :disabled="saving || selectedReadyAverageCandidates.length === 0"
                data-test="bulk-average-create"
                @click="createAveragesBulk"
              >
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.check" /></svg>
                {{ t('payroll_absence.averages.bulk_create', { count: selectedReadyAverageCandidates.length }) }}
              </button>
            </div>
          </div>
        </div>
        <p v-if="averageCandidateError" class="mt-3 rounded-lg border border-danger-200 bg-danger-50 p-3 text-sm text-danger-700" role="alert">{{ averageCandidateError }}</p>
        <p v-if="averageCandidateLoading" class="mt-4 text-sm text-neutral-500">{{ t('common.loading') }}</p>
        <div v-else class="mt-4 divide-y divide-neutral-200 rounded-lg border border-neutral-200">
          <label
            v-for="candidate in averageCandidates"
            :key="candidate.employment_id"
            class="flex items-start gap-3 p-3"
            :class="candidate.ready ? 'cursor-pointer' : 'bg-neutral-50'"
          >
            <input
              v-if="canWrite"
              v-model="selectedAverageCandidates"
              type="checkbox"
              :value="candidate.employment_id"
              :disabled="!candidate.ready"
              class="mt-1 h-4 w-4 rounded border-neutral-300 text-payroll-600 focus:ring-payroll-500"
            >
            <span class="min-w-0 flex-1">
              <span class="block font-medium text-neutral-900">
                {{ candidate.employee_name }}<template v-if="personalNumberLabel(t, candidate.employment_code)"> · {{ personalNumberLabel(t, candidate.employment_code) }}</template>
              </span>
              <span v-if="candidate.existing" class="mt-1 block text-xs text-neutral-500">
                {{ t('payroll_absence.averages.bulk_existing', {
                  amount: money(candidate.existing.average_hourly_minor),
                  status: t(`payroll_absence.average_status.${candidate.existing.status}`),
                }) }}
              </span>
              <span v-if="candidate.existing && candidate.existing_outdated" class="mt-1 block text-xs text-warning-700">
                {{ t('payroll_absence.averages.bulk_existing_outdated') }}
              </span>
              <span v-else-if="candidate.ready && candidate.source_kind === 'actual'" class="mt-1 block text-xs text-neutral-500">
                {{ t('payroll_absence.averages.bulk_actual_summary', {
                  gross: money(candidate.gross_earnings_minor ?? 0),
                  hours: minutes(candidate.worked_minutes ?? 0),
                  days: candidate.worked_days ?? 0,
                }) }}
              </span>
              <span v-else-if="candidate.ready && candidate.source_kind === 'probable'" class="mt-1 block text-xs text-neutral-500">
                {{ t('payroll_absence.averages.bulk_probable_summary', {
                  amount: money(candidate.probable_hourly_minor ?? 0),
                  source: t(`payroll_absence.averages.probable_source.${candidate.probable_source}`),
                }) }}
              </span>
              <span v-else class="mt-1 block text-xs text-warning-700">
                {{ candidate.blockers.map(blocker => t(`payroll_absence.averages.blockers.${blocker}`)).join(' · ') }}
              </span>
            </span>
            <span
              class="rounded-full px-2 py-1 text-xs font-medium"
              :class="candidate.ready ? 'bg-success-50 text-success-700' : 'bg-warning-50 text-warning-700'"
            >
              {{ t(candidate.ready ? 'payroll_absence.leave.ready' : 'payroll_absence.leave.needs_attention') }}
            </span>
          </label>
          <p v-if="averageCandidates.length === 0" class="p-6 text-center text-sm text-neutral-500">{{ t('payroll_absence.averages.bulk_empty') }}</p>
        </div>
        <PaginationBar
          class="mt-4"
          :page="averageCandidatePage"
          :per-page="averageCandidatePageSize"
          :total="averageCandidateTotal"
          @update:page="goToAverageCandidatePage"
        />
        <p class="mt-4 text-xs text-neutral-500">{{ t('payroll_absence.averages.bulk_longer_period_note') }}</p>
      </section>
    </template>

    <template v-else-if="tab === 'leave' && allEmployees">
      <EmptyState
        boxed
        icon="user"
        accent="accent"
        data-test="leave-person-required"
        :title="t('payroll_absence.person_required.title')"
        :message="t('payroll_absence.person_required.leave')"
      />
    </template>

    <template v-else-if="tab === 'averages'">
      <section v-if="canWrite" class="rounded-xl border border-neutral-200 bg-surface p-4 shadow-sm sm:p-6">
        <h2 class="text-lg font-semibold text-neutral-900">{{ t('payroll_absence.averages.new') }}</h2>
        <p
          v-if="averageSuggestionLoading"
          data-test="average-suggestion-loading"
          class="mt-3 text-sm text-neutral-500"
        >
          {{ t('payroll_absence.averages.suggestion_loading') }}
        </p>
        <p
          v-else-if="averageSuggestionError"
          data-test="average-suggestion-error"
          role="alert"
          class="mt-3 rounded-lg border border-danger-200 bg-danger-50 p-3 text-sm text-danger-700"
        >
          {{ averageSuggestionError }}
        </p>
        <div
          v-else-if="averageSuggestion?.ready"
          data-test="average-suggestion-ready"
          class="mt-3 rounded-lg border border-success-200 bg-success-50 p-3 text-sm text-success-800"
        >
          <p>
            {{ t('payroll_absence.averages.suggestion_ready', {
              from: averageSuggestion.decisive_from,
              to: averageSuggestion.decisive_to,
            }) }}
          </p>
          <p
            v-if="averageSuggestion.source_kind === 'probable'"
            data-test="average-suggestion-probable"
            class="mt-1 text-xs text-success-700"
          >
            {{ t('payroll_absence.averages.suggestion_probable') }}
          </p>
          <p v-else class="mt-1 text-xs text-success-700">{{ t('payroll_absence.averages.suggestion_sources') }}</p>
          <!--
            Převzatá hrubá mzda není započitatelná mzda § 354 ZP — náhrady mzdy
            (dovolená, svátek) do průměru nepatří. Účetní to musí vidět dřív,
            než návrh potvrdí.
          -->
          <p
            v-if="(averageSuggestion.takeover_periods ?? []).length > 0"
            data-test="average-suggestion-takeover"
            class="mt-2 rounded-md bg-warning-50 px-2 py-1 text-xs font-medium text-warning-800"
          >
            {{ t('payroll_absence.averages.suggestion_takeover', {
              periods: (averageSuggestion.takeover_periods ?? []).join(', '),
            }) }}
          </p>
          <p class="mt-1 text-xs text-success-700">{{ t('payroll_absence.averages.suggestion_confirm') }}</p>
          <p
            v-if="averageSuggestionEdited"
            data-test="average-suggestion-edited"
            class="mt-2 font-medium text-warning-800"
          >
            {{ t('payroll_absence.averages.suggestion_edited') }}
          </p>
          <button
            type="button"
            :class="btnOutline('neutral')"
            class="mt-3"
            data-test="average-suggestion-reload"
            @click="loadAverageSuggestion"
          >
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.cycle" /></svg>
            {{ t('payroll_absence.averages.suggestion_reload') }}
          </button>
        </div>
        <div
          v-else-if="averageSuggestion"
          data-test="average-suggestion-blocked"
          class="mt-3 rounded-lg border border-warning-200 bg-warning-50 p-3 text-sm text-warning-800"
        >
          <p>{{ t('payroll_absence.averages.suggestion_blocked') }}</p>
          <ul class="mt-2 list-disc space-y-1 pl-5 text-xs">
            <li v-for="(reason, index) in averageSuggestionBlockers" :key="index">{{ reason }}</li>
          </ul>
        </div>
        <form data-test="average-form" class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4" @submit.prevent="createAverage">
          <label><span class="form-label">{{ t('payroll_absence.averages.year') }}</span><input v-model.number="averageForm.applicable_year" data-test="average-year" :min="minimumFormYear" :max="maximumFormYear" type="number" :class="fieldClass"></label>
          <label><span class="form-label">{{ t('payroll_absence.averages.quarter') }}</span><input v-model.number="averageForm.applicable_quarter" data-test="average-quarter" min="1" max="4" type="number" :class="fieldClass"></label>
          <label><span class="form-label">{{ t('payroll_absence.averages.decisive_from') }}</span><DateInput v-model="averageForm.decisive_from" :class="fieldClass" /></label>
          <label><span class="form-label">{{ t('payroll_absence.averages.decisive_to') }}</span><DateInput v-model="averageForm.decisive_to" :class="fieldClass" /></label>
          <label>
            <span class="form-label">{{ t('payroll_absence.averages.gross_minor') }}</span>
            <input v-model.number="averageForm.gross_earnings_czk" data-test="average-gross-czk" min="0" step="0.01" type="number" :class="fieldClass">
            <span v-if="averagePrefill" class="mt-1 block text-xs text-neutral-500">{{ t('payroll_absence.averages.source_gross') }}</span>
          </label>
          <label>
            <span class="form-label">{{ t('payroll_absence.averages.allocated_minor') }}</span>
            <input v-model.number="averageForm.longer_period_allocated_czk" data-test="average-allocated-czk" min="0" step="0.01" type="number" :class="fieldClass">
            <span class="mt-1 block text-xs text-neutral-500">{{ t('payroll_absence.averages.source_allocated') }}</span>
          </label>
          <label>
            <span class="form-label">{{ t('payroll_absence.averages.worked_minutes') }}</span>
            <input v-model.number="averageForm.worked_hours" data-test="average-worked-hours" min="0" step="0.25" type="number" :class="fieldClass">
            <span v-if="averagePrefill" class="mt-1 block text-xs text-neutral-500">{{ t('payroll_absence.averages.source_worked_hours') }}</span>
          </label>
          <label>
            <span class="form-label">{{ t('payroll_absence.averages.worked_days') }}</span>
            <input v-model.number="averageForm.worked_days" data-test="average-worked-days" min="0" step="1" type="number" :class="fieldClass">
            <span v-if="averagePrefill" class="mt-1 block text-xs text-neutral-500">{{ t('payroll_absence.averages.source_worked_days') }}</span>
          </label>
          <label v-if="averageProbableApplies" data-test="average-probable-field">
            <span class="form-label">{{ t('payroll_absence.averages.probable_minor') }}</span>
            <input v-model.number="averageForm.probable_hourly_czk" data-test="average-probable-czk" min="0.01" step="0.01" type="number" :class="fieldClass">
            <span class="mt-1 block text-xs text-neutral-500">{{ t('payroll_absence.averages.source_probable') }}</span>
          </label>
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
        <div
          v-if="!leaveCandidateLoading && leaveAssessmentGroups.length > 0"
          id="leave-assessment"
          class="mt-4 rounded-lg border border-warning-200 bg-warning-50/50 p-4"
          data-test="leave-assessment"
        >
          <h3 class="font-semibold text-neutral-900">{{ t('payroll_absence.leave.assessment.title') }}</h3>
          <p class="mt-1 max-w-3xl text-sm text-neutral-600">{{ t('payroll_absence.leave.assessment.intro') }}</p>
          <ul class="mt-3 space-y-3">
            <li
              v-for="group in leaveAssessmentGroups"
              :key="group.type"
              class="flex flex-col gap-2 rounded-lg border border-neutral-200 bg-surface p-3 sm:flex-row sm:items-start sm:justify-between"
              :data-test="`leave-assessment-${group.type}`"
            >
              <div class="min-w-0 text-sm">
                <p class="font-medium text-neutral-900">
                  {{ t(`payroll_absence.types.${group.type}`) }}
                  <span class="font-normal text-neutral-500">· {{ t('payroll_absence.leave.assessment.count', { people: group.people, absences: group.absences }) }}</span>
                </p>
                <p class="mt-1 max-w-2xl text-xs text-neutral-600">{{ assessmentHint(group.type) }}</p>
              </div>
              <div v-if="canWrite" class="flex flex-wrap gap-2">
                <button
                  type="button"
                  :class="[absenceDecisions[group.type] === 'include' ? btnFilled('success') : btnOutline('success'), 'whitespace-nowrap']"
                  :data-test="`leave-assessment-include-${group.type}`"
                  @click="setAbsenceDecision(group.type, 'include')"
                >
                  <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.check" /></svg>
                  {{ t('payroll_absence.leave.assessment.include') }}
                </button>
                <button
                  type="button"
                  :class="[absenceDecisions[group.type] === 'exclude' ? btnFilled('neutral') : btnOutline('neutral'), 'whitespace-nowrap']"
                  :data-test="`leave-assessment-exclude-${group.type}`"
                  @click="setAbsenceDecision(group.type, 'exclude')"
                >
                  <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.x" /></svg>
                  {{ t('payroll_absence.leave.assessment.exclude') }}
                </button>
              </div>
            </li>
          </ul>
          <p class="mt-3 text-xs text-neutral-500">{{ t('payroll_absence.leave.assessment.after') }}</p>
        </div>
        <p v-if="leaveCandidateLoading" class="mt-4 text-sm text-neutral-500">{{ t('common.loading') }}</p>
        <div v-else class="mt-4 divide-y divide-neutral-200 rounded-lg border border-neutral-200">
          <div
            v-for="candidate in leaveCandidates"
            :key="candidate.employment_id"
            class="flex items-start gap-3 p-3"
            :class="isLeaveCandidateSelectable(candidate) ? '' : 'bg-neutral-50'"
            :data-test="`leave-candidate-${candidate.employment_id}`"
          >
            <input
              v-if="canWrite"
              :id="`leave-candidate-select-${candidate.employment_id}`"
              v-model="selectedLeaveCandidates"
              type="checkbox"
              :value="candidate.employment_id"
              :disabled="!isLeaveCandidateSelectable(candidate)"
              :aria-label="candidate.employee_name"
              class="mt-1 h-4 w-4 rounded border-neutral-300 text-payroll-600 focus:ring-payroll-500"
            >
            <span class="min-w-0 flex-1">
              <label :for="`leave-candidate-select-${candidate.employment_id}`" class="block font-medium text-neutral-900">{{ candidate.employee_name }}<template v-if="personalNumberLabel(t, candidate.employment_code)"> · {{ personalNumberLabel(t, candidate.employment_code) }}</template></label>
              <span v-if="candidate.takeover" class="mt-1 block text-xs text-neutral-600" data-test="leave-candidate-takeover">
                {{ t('payroll_absence.leave.takeover_summary', {
                  hours: minutes(candidate.takeover.minutes),
                  date: formatDate(candidate.takeover.effective_date),
                }) }}
              </span>
              <span v-else-if="candidate.ready" class="mt-1 block text-xs text-neutral-500">
                {{ t('payroll_absence.leave.automatic_summary', {
                  hours: minutes(candidate.weekly_minutes ?? 0),
                  weeks: candidate.entitlement_weeks,
                  worked: minutes(candidate.worked_equivalent_minutes),
                }) }}
              </span>
              <span v-else class="mt-1 block text-xs text-warning-700">
                {{ candidate.blockers.map(blocker => t(`payroll_absence.leave.blockers.${blocker}`)).join(' · ') }}
              </span>
              <span
                v-if="(candidate.assessment_absences ?? []).length > 0"
                class="mt-1 flex flex-wrap gap-1"
                data-test="leave-candidate-assessment-absences"
              >
                <span
                  v-for="absence in candidate.assessment_absences"
                  :key="absence.id"
                  class="inline-flex whitespace-nowrap rounded-full bg-neutral-100 px-2 py-0.5 text-xs text-neutral-700"
                >
                  {{ t(`payroll_absence.types.${absence.absence_type}`) }} {{ formatDate(absence.date_from) }} – {{ formatDate(absence.date_to) }}
                  <template v-if="absenceDecisions[absence.absence_type]">
                    · {{ t(`payroll_absence.leave.assessment.decided_${absenceDecisions[absence.absence_type]}`) }}
                  </template>
                </span>
              </span>
              <span v-if="(candidate.predecessor_absences ?? 0) > 0" class="mt-1 block text-xs text-neutral-500">
                {{ t('payroll_absence.leave.predecessor_absences', { count: candidate.predecessor_absences }) }}
              </span>
            </span>
            <span
              v-if="candidate.takeover"
              class="whitespace-nowrap rounded-full bg-success-50 px-2 py-1 text-xs font-medium text-success-700"
            >
              {{ t('payroll_absence.leave.takeover') }}
            </span>
            <span
              v-else-if="isLeaveCandidateSelectable(candidate)"
              class="whitespace-nowrap rounded-full bg-success-50 px-2 py-1 text-xs font-medium text-success-700"
            >
              {{ t('payroll_absence.leave.ready') }}
            </span>
            <button
              v-else-if="candidate.blockers.includes('absence_legal_assessment_required')"
              type="button"
              class="whitespace-nowrap rounded-full bg-warning-50 px-2 py-1 text-xs font-medium text-warning-700 underline-offset-2 hover:underline"
              :title="t('payroll_absence.leave.open_assessment')"
              :data-test="`leave-candidate-fix-${candidate.employment_id}`"
              @click="scrollToLeaveAssessment"
            >
              {{ t('payroll_absence.leave.needs_attention') }} →
            </button>
            <RouterLink
              v-else
              :to="leaveCandidateTarget(candidate)"
              class="whitespace-nowrap rounded-full bg-warning-50 px-2 py-1 text-xs font-medium text-warning-700 underline-offset-2 hover:underline"
              :title="t('payroll_absence.leave.open_employment')"
              :data-test="`leave-candidate-fix-${candidate.employment_id}`"
            >
              {{ t('payroll_absence.leave.needs_attention') }} →
            </RouterLink>
          </div>
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
            <label><span class="form-label">{{ t('payroll_absence.leave.effective_date') }}</span><DateInput v-model="entryForm.effective_date" :class="fieldClass" /></label>
            <label><span class="form-label">{{ t('payroll_absence.leave.minutes_delta') }}</span><input v-model.number="entryForm.hours_delta" data-test="leave-entry-hours" step="0.25" type="number" :class="fieldClass"></label>
            <label><span class="form-label">{{ t('payroll_absence.leave.reason') }}</span><input v-model="entryForm.reason" data-test="leave-entry-reason" required maxlength="1000" :class="fieldClass"></label>
            <template v-if="isHistoricTakenEntry">
              <p
                data-test="leave-historic-taken-hint"
                class="rounded-lg border border-payroll-200 bg-payroll-50 p-3 text-sm text-neutral-700 sm:col-span-2"
              >
                {{ t('payroll_absence.leave.historic_taken_hint', { period: payrollStartPeriod ?? '' }) }}
              </p>
              <label class="sm:col-span-2">
                <span class="form-label">{{ t('payroll_absence.leave.source_reference') }}</span>
                <input
                  v-model="entryForm.source_reference"
                  data-test="leave-entry-source-reference"
                  required
                  maxlength="200"
                  :placeholder="t('payroll_absence.leave.source_reference_placeholder')"
                  :class="fieldClass"
                >
                <span class="mt-1 block text-xs text-neutral-500">{{ t('payroll_absence.leave.source_reference_hint') }}</span>
              </label>
            </template>
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
            <p class="mt-2 text-xs text-neutral-500">{{ formatDate(entry.effective_date) }}</p><p class="mt-2 text-sm text-neutral-600">{{ entry.reason }}</p>
          </article>
        </div>
      </section>
    </template>
    </template>

    <Modal
      v-if="bulkApprovalOpen"
      :title="t('payroll_absence.bulk.title')"
      width-class="max-w-xl"
      @close="closeBulkApproval"
    >
      <form data-test="bulk-approve-form" class="space-y-4" @submit.prevent="approveSelected">
        <p class="max-w-prose text-sm text-neutral-600">
          {{ t('payroll_absence.bulk.hint', { count: bulkCandidates.length }) }}
        </p>

        <section
          v-if="bulkExclusions.length"
          data-test="bulk-approve-excluded"
          class="rounded-lg border border-warning-500/40 bg-warning-50 p-3 text-sm text-warning-700"
        >
          <p class="font-medium">{{ t('payroll_absence.bulk.excluded.title', { count: bulkExclusions.length }) }}</p>
          <ul class="mt-2 space-y-1">
            <li v-for="row in bulkExclusions" :key="row.absenceId" data-test="bulk-approve-excluded-row">
              <span class="font-medium">{{ row.name }}</span> - {{ row.reason }}
            </li>
          </ul>
        </section>

        <div class="flex flex-wrap justify-end gap-2">
          <button type="button" :class="btnOutline('neutral')" :disabled="saving" @click="closeBulkApproval">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.x" /></svg>
            {{ t('common.cancel') }}
          </button>
          <button
            type="submit"
            data-test="bulk-approve-confirm"
            :class="btnFilled('success')"
            :disabled="saving || Boolean(bulkBlockedReason)"
            :title="disabledTitle(Boolean(bulkBlockedReason), bulkBlockedReason)"
          >
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.badgeCheck" /></svg>
            {{ t('payroll_absence.bulk.confirm', { count: bulkCandidates.length }) }}
          </button>
        </div>
        <p v-if="bulkBlockedReason" :class="BTN_DISABLED_NOTE" data-test="bulk-approve-blocked">
          {{ bulkBlockedReason }}
        </p>
      </form>
    </Modal>
  </div>
</template>

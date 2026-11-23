<script setup lang="ts">
/*
 * Případy dávek nemocenského pojištění — NEMPRI a HZUPN.
 *
 * Obrazovka existuje proto, že § 97 zák. č. 187/2006 Sb. ukládá zaměstnavateli
 * DVĚ samostatné povinnosti s vlastními lhůtami:
 *
 *   odst. 1 a 2 — oznámit žádost o dávku a předat podklady pro výpočet;
 *                 u nemocenského „neprodleně po uplynutí prvních 14 dnů trvání
 *                 dočasné pracovní neschopnosti" (tiskopis NEMPRI),
 *   odst. 3     — neprodleně oznámit skutečnosti, které mohou mít vliv na
 *                 výplatu dávek, tedy i skončení neschopnosti (tiskopis HZUPN).
 *
 * Případ se proto eviduje SAMOSTATNĚ a žije i tehdy, když z něj nikdo podání
 * nepřipraví — lhůta běží tak jako tak a nesplnění je přestupek podle
 * § 130 odst. 1 písm. c) a d).
 *
 * Editor je vícesekční (případ, potvrzení zaměstnavatele, ukončení, dny práce)
 * a má proto JEDNO společné Uložit v liště dole. Tlačítko u každé sekce by
 * znamenalo, že se dá uložit půlka případu — a půlka případu je datová věta,
 * kterou ČSSZ odmítne.
 *
 * ODESÍLÁ SE TADY, a to schválně. Obrazovka „Stav odeslání" patří kanálu
 * VREP/APEP, kterým NEMPRI ani HZUPN odeslat nejde (Podávací a dotazovací
 * protokol v1.47 pro ně neuvádí identifikátor třídy podání), takže tam tahle
 * podání nikdy nebyla — a panel přesto účetní psal „Odešlete ho ve Stavu
 * odeslání". Doložený kanál je datová schránka, takže tlačítko stojí přímo
 * u připraveného podání.
 */
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { navigateFullPage } from '@/workspace/fullPageNavigation'
import { personalNumberLabel } from './employmentLifecycleUi'
import { usePayrollServerMessage } from './payrollServerMessage'
import {
  sicknessErrorFix,
  sicknessErrorProblems,
  type SicknessErrorFix,
  type SicknessErrorFixLink,
  type SicknessErrorProblem,
} from './sicknessErrorFix'
import {
  payrollSicknessCasesApi,
  type PayrollSicknessBenefitKind,
  type PayrollSicknessCareReason,
  type PayrollSicknessCase,
  type PayrollSicknessCaseInput,
  type PayrollSicknessDispatched,
  type PayrollSicknessDocumentKind,
  type PayrollSicknessDocumentStatus,
  type PayrollSicknessReadySubmission,
  type PayrollSicknessTransferReason,
  type PayrollSicknessTransport,
  type PayrollSicknessWorkInterval,
} from '@/api/payrollSicknessCases'
import {
  payrollApi,
  type PayrollDependant,
  type PayrollEmployment,
  type PayrollRegzelEnvironment,
} from '@/api/payroll'
import { dataBoxApi, type GatewayStart } from '@/api/dataBox'
import { useAuthStore } from '@/stores/auth'
import PayrollPersonSearchSelect from '@/components/payroll/PayrollPersonSearchSelect.vue'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import IsdsSendMethods from '@/components/submission/IsdsSendMethods.vue'
import DateInput from '@/components/ui/DateInput.vue'
import EnvironmentSwitch from '@/components/ui/EnvironmentSwitch.vue'
import ProductionSendConfirmDialog from '@/components/payroll/ProductionSendConfirmDialog.vue'
import { useProductionSendConfirm } from '@/composables/useProductionSendConfirm'
import {
  MATERNITY_CARE_REASONS,
  PATERNITY_REASONS,
  PENSION_KINDS,
  isOutsideCodebook,
  relationshipCodebook,
} from './nempriCodebooks'

const { t } = useI18n()
const { errorMessage: serverErrorMessage, reasonText } = usePayrollServerMessage()
const auth = useAuthStore()
const {
  request: sendConfirmRequest,
  confirmProductionSend,
  settle: settleSendConfirm,
} = useProductionSendConfirm()

const loading = ref(true)
const creating = ref(false)
const saving = ref(false)
const busyId = ref<number | null>(null)
const items = ref<PayrollSicknessCase[]>([])
const environment = defineModel<PayrollRegzelEnvironment>('environment', {
  default: 'production',
})

const personId = ref<number | null>(null)
const employments = ref<PayrollEmployment[]>([])
const newEmploymentId = ref<number | null>(null)
const newBenefitKind = ref<PayrollSicknessBenefitKind>('NEM')
const newIncapacityFrom = ref('')

const editingId = ref<number | null>(null)
const draft = ref<PayrollSicknessCaseInput>({})
const draftWorkDays = ref<PayrollSicknessWorkInterval[]>([])
const draftCareDays = ref<PayrollSicknessWorkInterval[]>([])
/** Ručně doplněné měsíce rozhodného období; příjem se edituje v Kč. */
const draftDecisiveMonths = ref<{ period: string, income_czk: string, excluded_days: number }[]>([])
/** Příjem z malého rozsahu se zadává v Kč; server ho drží v haléřích. */
const draftSmallScopeIncomeCzk = ref('')
/** Vyživované osoby zaměstnance — z nich se vybírá dítě nebo ošetřovaná osoba. */
const dependants = ref<PayrollDependant[]>([])
const previewXml = ref<{ id: number, document: string, xml: string } | null>(null)
/*
 * Den doručení a důvod odmítnutí se zapisují ke KONKRÉTNÍMU podání
 * (klíč `případ:tiskopis`). NEMPRI a HZUPN mají vlastní lhůty (§ 97 odst. 1–3),
 * přijaté NEMPRI proto nesmí uzavřít HZUPN.
 */
const receiptDate = ref<Record<string, string>>({})
const receiptReason = ref<Record<string, string>>({})
/** Podklady pro výplatu DLO: období volna a rozvrh směn. */
const draftDloLeave = ref<PayrollSicknessWorkInterval[]>([])
const draftDloShifts = ref<PayrollSicknessWorkInterval[]>([])
const error = ref('')
const errorSection = ref<HTMLElement | null>(null)
const success = ref('')

/**
 * Dostupnost datovky POČÍTÁ SERVER a je jedna pro celou firmu. Kdyby si ji
 * frontend odhadoval, tvrdil by o kanálu něco, co neví — a přesně tak vznikl
 * text „Odešlete ho ve Stavu odeslání", který ukazoval na obrazovku bez těchhle
 * podání.
 */
const transport = ref<PayrollSicknessTransport | null>(null)
/** Připravená podání a jejich stav ve frontě, klíčované ID podání. */
const readyBySubmission = ref<Record<number, PayrollSicknessReadySubmission>>({})
const dispatched = ref<Record<string, PayrollSicknessDispatched>>({})
const gateways = ref<Record<string, GatewayStart>>({})
const mobileKeySent = ref<Record<string, boolean>>({})
const dispatchingKey = ref<string | null>(null)

const canWrite = computed(() => auth.canWrite('payroll.submissions'))

/**
 * Druhy dávky, jejichž věta nese i žádost o dávku. Zaměstnavatel ji podle
 * § 97 odst. 1 zákona o nemocenském pojištění přijímá a předává — údaje opisuje
 * z žádosti, kterou mu zaměstnanec předal.
 */
const APPLICATION_KINDS: PayrollSicknessBenefitKind[] = ['OSE', 'DLO', 'OPP', 'PPM']
/** Ošetřovné a dlouhodobé ošetřovné nesou akce vznik / trvání / ukončení. */
const ACTION_KINDS: PayrollSicknessBenefitKind[] = ['OSE', 'DLO']
/** HZUPN se podává jen při ukončení pracovní neschopnosti, tedy u nemocenského. */
const HZUPN_KINDS: PayrollSicknessBenefitKind[] = ['NEM']

const careReasons: PayrollSicknessCareReason[] = [
  'ill', 'quarantine', 'cannot_care', 'school_closed',
]

/** Důvody převedení na jinou práci podle § 19 odst. 6 zákona o nemocenském pojištění. */
const transferReasons: PayrollSicknessTransferReason[] = ['pregnancy', 'maternity', 'breastfeeding']
/** Nástup na PPM a den narození nese potvrzení zaměstnavatele jen u NEM, VPM a PPM. */
const MATERNITY_KINDS: PayrollSicknessBenefitKind[] = ['NEM', 'VPM', 'PPM']

const benefitKinds: PayrollSicknessBenefitKind[] = [
  'NEM', 'VPM', 'OPP', 'PPM', 'OSE', 'DLO',
]

const employmentOptions = computed(() =>
  employments.value.map(employment => ({
    value: employment.id,
    label: employment.end_date
      ? `${personalNumberLabel(t, employment.code) || '—'} (${employment.start_date ?? '?'} – ${employment.end_date})`
      : `${personalNumberLabel(t, employment.code) || '—'} (${employment.start_date ?? '?'})`,
  })))

const canCreate = computed(() =>
  canWrite.value
  && newEmploymentId.value !== null
  && newIncapacityFrom.value !== '')

const editing = computed(() =>
  items.value.find(item => item.id === editingId.value) ?? null)

const draftKind = computed(() => editing.value?.benefit_kind ?? null)

/** Neplacené volno má prvek u nemocenského a ošetřovného, ne u VPM, PPM a otcovské. */
const draftHasUnpaidLeaveSection = computed(() =>
  draftKind.value === 'NEM' || draftKind.value === 'OSE' || draftKind.value === 'DLO')
const draftHasApplication = computed(() =>
  draftKind.value !== null && APPLICATION_KINDS.includes(draftKind.value))
const draftHasActions = computed(() =>
  draftKind.value !== null && ACTION_KINDS.includes(draftKind.value))
const draftHasHzupn = computed(() =>
  draftKind.value !== null && HZUPN_KINDS.includes(draftKind.value))
/** Studium (`jeStudentem`, `spadaDoPrazdnin`) potvrzení nemá u PPM a otcovské. */
const draftHasStudentSection = computed(() =>
  draftKind.value !== null && draftKind.value !== 'PPM' && draftKind.value !== 'OPP')
const draftHasMaternity = computed(() =>
  draftKind.value !== null && MATERNITY_KINDS.includes(draftKind.value))
/** Převedení na jinou práci potvrzení nemá jen u otcovské. */
const draftHasTransfer = computed(() =>
  draftKind.value !== null && draftKind.value !== 'OPP')

interface CodeOption { value: string, label: string, invalid?: boolean }

/**
 * Nabídka z číselníku ČSSZ. Uložený kód mimo číselník (dřív se kódy psaly
 * ručně) se v nabídce ponechá s upozorněním, aby ho select tiše nezahodil
 * a účetní viděla, co je potřeba vybrat znovu.
 */
function codeOptions(
  codes: readonly string[],
  labelGroup: string,
  current: string | null | undefined,
): CodeOption[] {
  const options: CodeOption[] = codes.map(code => ({
    value: code,
    label: t(`payroll.sicknessCases.codebooks.${labelGroup}.${code}`),
  }))
  if (isOutsideCodebook(current, codes)) {
    options.unshift({
      value: current,
      label: t('payroll.sicknessCases.codebooks.invalidValue', { code: current }),
      invalid: true,
    })
  }
  return options
}

const relationshipOptions = computed<CodeOption[]>(() => {
  const codebook = relationshipCodebook(draftKind.value)
  return codebook === null
    ? []
    : codeOptions(codebook.codes, codebook.labelGroup, draft.value.relationship_code)
})
const paternityReasonOptions = computed<CodeOption[]>(() =>
  codeOptions(PATERNITY_REASONS, 'paternityReasons', draft.value.paternity_reason))
const maternityCareReasonOptions = computed<CodeOption[]>(() =>
  codeOptions(MATERNITY_CARE_REASONS, 'maternityCareReasons', draft.value.maternity_care_reason))
const pensionKindOptions = computed<CodeOption[]>(() =>
  codeOptions(PENSION_KINDS, 'pensionKinds', draft.value.pension_kind))

/** Odmítnutí dlouhodobé péče musí nést důvod (§ 191a ZP). */
const longTermCareRefused = computed(() => draft.value.long_term_care_consent === 'refused')

const dependantOptions = computed(() =>
  dependants.value.map(dependant => ({
    value: dependant.id,
    label: `${dependant.full_name} (${dependant.birth_date})`,
  })))

const caredDependantId = computed<number | null>({
  get: () => draft.value.cared_dependant_id ?? null,
  set: value => {
    draft.value.cared_dependant_id = value
  },
})

/**
 * „Vrátil se do práce“ má tři stavy: neuvedeno, ano, ne. Select pracuje
 * s textem, případ s číslem nebo `null`.
 */
const returnedToWorkChoice = computed<string>({
  get: () => draft.value.returned_to_work === null || draft.value.returned_to_work === undefined
    ? ''
    : String(draft.value.returned_to_work),
  set: value => {
    draft.value.returned_to_work = value === '' ? null : Number(value)
  },
})

/** Návrh pravděpodobného příjmu: sjednaná měsíční hrubá mzda v celých Kč. */
const probableIncomeSuggestion = computed(() => {
  const minor = editing.value?.probable_income_suggestion_minor
  return typeof minor === 'number' && minor > 0 ? Math.round(minor / 100) : null
})

function suggestProbableIncome(): void {
  if (probableIncomeSuggestion.value !== null) {
    draft.value.probable_income_czk = probableIncomeSuggestion.value
  }
}

/** Kč z textového pole („12 345,50“) na haléře; prázdné = `null`. */
function czkToMinor(value: string): number | null {
  const normalized = value.replace(/\s+/g, '').replace(',', '.')
  if (normalized === '') return null
  const amount = Number(normalized)
  return Number.isFinite(amount) ? Math.round(amount * 100) : null
}

function minorToCzk(value: number | null | undefined): string {
  return typeof value === 'number' ? String(value / 100) : ''
}

/** Chyby posledního volání serveru a případ, u kterého vznikly — pro proklik. */
const errorProblems = ref<SicknessErrorProblem[]>([])
const errorCase = ref<PayrollSicknessCase | null>(null)

/*
 * Chyba serveru: `{ error: { code, message, problems } }`. Dřív se četlo
 * `data.error` jako text, takže se místo věty ukázalo „[object Object]". V jiném
 * jazyce se místo české věty serveru ukáže překlad kódu. Chyby si panel drží
 * pro proklik na místo, kde se každá opraví.
 */
function message(err: unknown): string {
  errorProblems.value = sicknessErrorProblems(err)
  return serverErrorMessage(err, t('payroll.sicknessCases.errors.generic'))
}

/** Kam s chybami: karta osoby, podmínky vztahu, mzdové běhy, kontrola převodu, nastavení, nebo editor případu. */
const errorFix = computed<(SicknessErrorFix & { item: PayrollSicknessCase }) | null>(() => {
  const item = errorCase.value
  if (item === null) return null
  const fix = sicknessErrorFix(errorProblems.value, item)
  return fix === null ? null : { ...fix, item }
})

const ERROR_FIX_LABELS: Record<SicknessErrorFixLink['kind'], string> = {
  person: 'payroll.sicknessCases.errorFix.personCard',
  employment_terms: 'payroll.sicknessCases.errorFix.employmentTerms',
  runs: 'payroll.sicknessCases.errorFix.payrollRuns',
  reconciliation: 'payroll.sicknessCases.errorFix.reconciliation',
  settings: 'payroll.sicknessCases.errorFix.employerSettings',
}

async function load(): Promise<void> {
  loading.value = true
  error.value = ''
  try {
    const result = await payrollSicknessCasesApi.list(environment.value)
    items.value = result.items
    transport.value = result.transport
    readyBySubmission.value = Object.fromEntries(
      result.ready_submissions.map(ready => [ready.submission_id, ready]),
    )
  } catch (err) {
    // Stav zůstává NEZNÁMÝ, ne „nejde to": kdyby se `transport` po selhání
    // načtení tvářil jako `manual_upload`, přečetla by to účetní jako doložený
    // stav kanálu a odeslala podání ručně podruhé.
    transport.value = null
    readyBySubmission.value = {}
    error.value = message(err)
  } finally {
    loading.value = false
  }
}

async function loadEmployments(id: number): Promise<void> {
  employments.value = []
  newEmploymentId.value = null
  try {
    const person = await payrollApi.person(id)
    employments.value = person.employments
    if (employments.value.length === 1) {
      newEmploymentId.value = employments.value[0].id
    }
  } catch (err) {
    error.value = message(err)
  }
}

async function create(): Promise<void> {
  if (!canCreate.value || newEmploymentId.value === null) return
  creating.value = true
  error.value = ''
  success.value = ''
  try {
    await payrollSicknessCasesApi.create(environment.value, {
      employment_id: newEmploymentId.value,
      benefit_kind: newBenefitKind.value,
      incapacity_from: newIncapacityFrom.value,
    })
    newIncapacityFrom.value = ''
    success.value = t('payroll.sicknessCases.created')
    await load()
  } catch (err) {
    error.value = message(err)
  } finally {
    creating.value = false
  }
}

function edit(item: PayrollSicknessCase): void {
  editingId.value = item.id
  // Stará zelená „Případ uložen." nad novou rozdělanou úpravou tvrdila,
  // že se uložilo i to, co se ještě neodeslalo.
  success.value = ''
  draft.value = {
    ossz_code: item.ossz_code,
    decision_number: item.decision_number,
    correction: item.correction,
    foreign_case: item.foreign_case,
    slovak_case: item.slovak_case,
    incapacity_from: item.incapacity_from,
    incapacity_to: item.incapacity_to,
    issued_on: item.issued_on,
    payroll_payment_date: item.payroll_payment_date,
    worked_on_decisive_day: item.worked_on_decisive_day,
    hours_worked: item.hours_worked,
    daily_working_hours: item.daily_working_hours,
    receives_pension: item.receives_pension,
    pension_kind: item.pension_kind,
    is_student: item.is_student,
    within_school_holidays: item.within_school_holidays ?? 0,
    first_employment_free_time: item.first_employment_free_time,
    unpaid_leave: item.unpaid_leave,
    unpaid_leave_from: item.unpaid_leave_from,
    unpaid_leave_to: item.unpaid_leave_to,
    starts_maternity: item.starts_maternity ?? 0,
    child_birth_date: item.child_birth_date,
    transferred_other_work: item.transferred_other_work,
    transferred_on: item.transferred_on,
    transfer_reason: item.transfer_reason ?? null,
    dlo_has_leave: item.dlo_has_leave ?? 0,
    enforcement: item.enforcement,
    insolvency: item.insolvency,
    returned_to_work: item.returned_to_work,
    return_reason: item.return_reason,
    returned_on: item.returned_on,
    hours_worked_last_day: item.hours_worked_last_day,
    shift_hours_last_day: item.shift_hours_last_day,
    additional_note: item.additional_note,
    action_start: item.action_start ?? 1,
    action_continuation: item.action_continuation ?? 0,
    action_end: item.action_end ?? 0,
    application_from: item.application_from ?? null,
    application_to: item.application_to ?? null,
    cared_dependant_id: item.cared_dependant_id ?? null,
    cared_first_name: item.cared_first_name ?? null,
    cared_last_name: item.cared_last_name ?? null,
    cared_birth_date: item.cared_birth_date ?? null,
    care_reason: item.care_reason ?? null,
    school_name: item.school_name ?? null,
    school_business_id: item.school_business_id ?? null,
    shared_household: item.shared_household ?? 0,
    lone_caregiver: item.lone_caregiver ?? 0,
    child_under_16: item.child_under_16 ?? 0,
    other_maternity_claim: item.other_maternity_claim ?? 0,
    cared_personally: item.cared_personally ?? 0,
    relationship_code: item.relationship_code ?? null,
    alternation: item.alternation ?? 0,
    paternity_reason: item.paternity_reason ?? null,
    maternity_care_reason: item.maternity_care_reason ?? null,
    child_order: item.child_order ?? null,
    worked_last_day: item.worked_last_day ?? 0,
    planned_shifts: item.planned_shifts ?? 0,
    planned_shifts_worked: item.planned_shifts_worked ?? 0,
    // Návrh (sjednaná měsíční hrubá mzda) se předvyplní rovnou. Server ho
    // použije jen tehdy, když je rozhodné období kratší než 30 dnů, jinak
    // ho do věty nedá.
    probable_income_czk: item.probable_income_czk
      ?? (typeof item.probable_income_suggestion_minor === 'number' && item.probable_income_suggestion_minor > 0
        ? Math.round(item.probable_income_suggestion_minor / 100)
        : null),
    contact_worker_name: item.contact_worker_name ?? null,
    contact_worker_phone: item.contact_worker_phone ?? null,
    contact_worker_email: item.contact_worker_email ?? null,
    long_term_care_consent: item.long_term_care_consent ?? null,
    long_term_care_consent_on: item.long_term_care_consent_on ?? null,
    long_term_care_refusal_reason: item.long_term_care_refusal_reason ?? null,
  }
  draftWorkDays.value = item.work_days.map(interval => ({ ...interval }))
  draftCareDays.value = (item.care_days ?? []).map(interval => ({ ...interval }))
  draftDloLeave.value = (item.dlo_leave_periods ?? []).map(interval => ({ ...interval }))
  draftDloShifts.value = (item.dlo_shift_schedule ?? []).map(interval => ({ ...interval }))
  draftDecisiveMonths.value = (item.decisive_months ?? []).map(month => ({
    period: month.period,
    income_czk: minorToCzk(month.income_minor),
    excluded_days: month.excluded_days,
  }))
  draftSmallScopeIncomeCzk.value = minorToCzk(item.small_scope_income_minor)
  dependants.value = []
  if (APPLICATION_KINDS.includes(item.benefit_kind)) {
    void loadDependants(item.employee_id)
  }
}

/**
 * Vyživované osoby pro výběr dítěte nebo ošetřované osoby. Selhání načtení
 * formulář nezablokuje: osobu jde zadat i ručně jménem a datem narození.
 */
async function loadDependants(employeeId: number): Promise<void> {
  try {
    dependants.value = (await payrollApi.personDependants(employeeId)).dependants
  } catch {
    dependants.value = []
  }
}

function cancelEdit(): void {
  editingId.value = null
  draft.value = {}
  draftWorkDays.value = []
  draftCareDays.value = []
  draftDloLeave.value = []
  draftDloShifts.value = []
  draftDecisiveMonths.value = []
  draftSmallScopeIncomeCzk.value = ''
}

function addInterval(list: PayrollSicknessWorkInterval[]): void {
  list.push({ from: '', to: '' })
}

function removeInterval(list: PayrollSicknessWorkInterval[], index: number): void {
  list.splice(index, 1)
}

function addWorkInterval(): void {
  draftWorkDays.value.push({ from: '', to: '' })
}

function removeWorkInterval(index: number): void {
  draftWorkDays.value.splice(index, 1)
}

function addCareInterval(): void {
  draftCareDays.value.push({ from: '', to: '' })
}

function removeCareInterval(index: number): void {
  draftCareDays.value.splice(index, 1)
}

function addDecisiveMonth(): void {
  draftDecisiveMonths.value.push({ period: '', income_czk: '', excluded_days: 0 })
}

function removeDecisiveMonth(index: number): void {
  draftDecisiveMonths.value.splice(index, 1)
}

function completeIntervals(intervals: PayrollSicknessWorkInterval[]): PayrollSicknessWorkInterval[] {
  return intervals.filter(interval => interval.from !== '' && interval.to !== '')
}

/** Jediné Uložit pro celý editor — sekce se neukládají po částech. */
async function save(): Promise<void> {
  const item = editing.value
  if (!item || !canWrite.value) return
  saving.value = true
  error.value = ''
  success.value = ''
  try {
    await payrollSicknessCasesApi.update(
      environment.value,
      item.id,
      item.row_version,
      {
        ...draft.value,
        // Bez rozhodnutí nemá den ani důvod smysl a důvod patří jen
        // k odmítnutí — server by jinak odmítl celé uložení.
        long_term_care_consent_on: draft.value.long_term_care_consent
          ? (draft.value.long_term_care_consent_on ?? null)
          : null,
        long_term_care_refusal_reason: longTermCareRefused.value
          ? (draft.value.long_term_care_refusal_reason ?? null)
          : null,
        small_scope_income_minor: czkToMinor(draftSmallScopeIncomeCzk.value),
        // Podřízené údaje jdou jen s nadřazeným příznakem — jinak by věta
        // nesla prvek, který DV NEMPRI25 bez něj zakazuje.
        pension_kind: draft.value.receives_pension ? (draft.value.pension_kind || null) : null,
        within_school_holidays: draft.value.is_student
          ? (draft.value.within_school_holidays ? 1 : 0)
          : null,
        unpaid_leave_from: draft.value.unpaid_leave ? (draft.value.unpaid_leave_from ?? null) : null,
        unpaid_leave_to: draft.value.unpaid_leave ? (draft.value.unpaid_leave_to ?? null) : null,
        starts_maternity: draftHasMaternity.value && draft.value.starts_maternity ? 1 : null,
        child_birth_date: draftHasMaternity.value && draft.value.starts_maternity
          ? (draft.value.child_birth_date ?? null)
          : null,
        transferred_on: draft.value.transferred_other_work ? (draft.value.transferred_on ?? null) : null,
        transfer_reason: draft.value.transferred_other_work ? (draft.value.transfer_reason ?? null) : null,
        ...(draftKind.value === 'DLO'
          ? {
              dlo_has_leave: draft.value.dlo_has_leave ? 1 : 0,
              dlo_leave_periods: draft.value.dlo_has_leave ? completeIntervals(draftDloLeave.value) : null,
              dlo_shift_schedule: draft.value.planned_shifts ? completeIntervals(draftDloShifts.value) : null,
            }
          : { dlo_has_leave: null }),
        work_days: completeIntervals(draftWorkDays.value),
        care_days: completeIntervals(draftCareDays.value),
        decisive_months: draftDecisiveMonths.value
          .filter(month => month.period !== '')
          .map(month => ({
            period: month.period,
            income_minor: czkToMinor(month.income_czk) ?? 0,
            excluded_days: Number(month.excluded_days) || 0,
          })),
      },
    )
    success.value = t('payroll.sicknessCases.saved')
    cancelEdit()
    await load()
  } catch (err) {
    error.value = message(err)
    errorCase.value = item
  } finally {
    saving.value = false
  }
}

async function preview(
  item: PayrollSicknessCase,
  document: PayrollSicknessDocumentKind,
): Promise<void> {
  busyId.value = item.id
  error.value = ''
  try {
    const result = await payrollSicknessCasesApi.preview(
      environment.value,
      item.id,
      document,
    )
    previewXml.value = { id: item.id, document, xml: result.xml }
  } catch (err) {
    previewXml.value = null
    error.value = message(err)
    errorCase.value = item
    void nextTick(() => errorSection.value?.scrollIntoView?.({ behavior: 'smooth', block: 'start' }))
  } finally {
    busyId.value = null
  }
}

async function prepare(
  item: PayrollSicknessCase,
  document: PayrollSicknessDocumentKind,
): Promise<void> {
  busyId.value = item.id
  error.value = ''
  success.value = ''
  try {
    await payrollSicknessCasesApi.prepare(environment.value, item.id, document)
    success.value = t('payroll.sicknessCases.prepared')
    await load()
  } catch (err) {
    error.value = message(err)
    errorCase.value = item
  } finally {
    busyId.value = null
  }
}

/** Klíč řádku odesílací lišty: jeden případ nese dvě samostatná podání. */
function dispatchKey(
  item: PayrollSicknessCase,
  document: PayrollSicknessDocumentKind,
): string {
  return `${item.id}:${document}`
}

/** Přípona klíčů akcí podle tiskopisu (`prepareNempriTransfer` …). */
const DOCUMENT_SUFFIX: Record<PayrollSicknessDocumentKind, string> = {
  nempri: 'Nempri',
  nempri_transfer: 'NempriTransfer',
  hzupn: 'Hzupn',
}

function submissionId(
  item: PayrollSicknessCase,
  document: PayrollSicknessDocumentKind,
): number | null {
  if (document === 'nempri') return item.nempri_submission_id
  if (document === 'nempri_transfer') return item.nempri_transfer_submission_id ?? null
  return item.hzupn_submission_id
}

/** Den doručení a důvod odmítnutí jednoho tiskopisu. */
function documentReceipt(
  item: PayrollSicknessCase,
  document: PayrollSicknessDocumentKind,
): { acceptedOn: string | null, reason: string | null } {
  if (document === 'nempri') return { acceptedOn: item.nempri_accepted_on, reason: item.nempri_rejection_reason }
  if (document === 'nempri_transfer') {
    return { acceptedOn: item.nempri_transfer_accepted_on ?? null, reason: item.nempri_transfer_rejection_reason ?? null }
  }
  return { acceptedOn: item.hzupn_accepted_on, reason: item.hzupn_rejection_reason }
}

/** Nese případ druhé oznámení NEMPRI ke dni převedení (§ 19 odst. 6)? */
function hasTransferNotice(item: PayrollSicknessCase): boolean {
  return (item.nempri_transfer_status ?? null) !== null
}

/** Je podání připravené a zároveň ještě nezařazené do fronty? */
function readyFor(
  item: PayrollSicknessCase,
  document: PayrollSicknessDocumentKind,
): PayrollSicknessReadySubmission | null {
  const id = submissionId(item, document)
  return id === null ? null : (readyBySubmission.value[id] ?? null)
}

/**
 * Zařadí podání do fronty a hned navíže tou cestou, která je k dispozici:
 * u brány přesměrováním do perimetru ISDS, u Mobilního klíče tlačítkem
 * s potvrzením v mobilu, jinak odkazem do fronty ke stažení přílohy.
 */
async function dispatch(
  item: PayrollSicknessCase,
  document: PayrollSicknessDocumentKind,
): Promise<void> {
  const key = dispatchKey(item, document)
  if (!canWrite.value || dispatchingKey.value !== null) return
  const confirmed = await confirmProductionSend(
    environment.value,
    t('payroll.production_send.sickness', { document: t(`payroll.sicknessCases.documents.${document}`) }),
  )
  if (!confirmed || dispatchingKey.value !== null) return
  dispatchingKey.value = key
  error.value = ''
  success.value = ''
  try {
    const queued = await payrollSicknessCasesApi.dispatch(
      environment.value,
      item.id,
      document,
    )
    dispatched.value = { ...dispatched.value, [key]: queued }
    if (queued.transport.automatic) {
      try {
        gateways.value = {
          ...gateways.value,
          [key]: await dataBoxApi.gatewayStartPayroll(queued.outbox_id),
        }
      } catch (err) {
        error.value = message(err)
      }
    }
    success.value = queued.created
      ? t('payroll.sicknessCases.dispatch.queued', { id: queued.outbox_id })
      : t('payroll.sicknessCases.dispatch.alreadyQueued', { id: queued.outbox_id })
    await load()
  } catch (err) {
    error.value = message(err)
  } finally {
    dispatchingKey.value = null
  }
}

function continueGateway(key: string): void {
  const gateway = gateways.value[key]
  if (gateway) navigateFullPage(gateway.redirect_url)
}

function markMobileKeySent(key: string): void {
  mobileKeySent.value = { ...mobileKeySent.value, [key]: true }
}

/**
 * Jedna věta o tom, co se s připraveným podáním stane — právě jedna ze tří
 * možností. Zamlčet rozdíl mezi „appka odešle" a „odešlete ho sami" znamená,
 * že účetní neví, jestli má ještě něco udělat.
 */
function transportNote(): string {
  const state = transport.value
  if (state === null) return t('payroll.sicknessCases.dispatch.transportUnknown')
  if (state.automatic) return t('payroll.sicknessCases.dispatch.transportGateway')
  if (state.channel === 'mobile_key') {
    return t('payroll.sicknessCases.dispatch.transportMobileKey')
  }
  return t('payroll.sicknessCases.dispatch.transportManual')
}

/** Klíč vstupů výsledku podání: jeden případ nese dvě samostatná podání. */
function receiptKey(item: PayrollSicknessCase, document: PayrollSicknessDocumentKind): string {
  return `${item.id}:${document}`
}

async function recordReceipt(
  item: PayrollSicknessCase,
  document: PayrollSicknessDocumentKind,
  outcome: 'accepted' | 'rejected' | 'predecessor' | 'pending',
): Promise<void> {
  const key = receiptKey(item, document)
  busyId.value = item.id
  error.value = ''
  success.value = ''
  try {
    await payrollSicknessCasesApi.recordReceipt(environment.value, item.id, {
      outcome,
      document,
      accepted_on: receiptDate.value[key] || null,
      reason: outcome === 'rejected' ? (receiptReason.value[key] || null) : null,
    })
    receiptDate.value = { ...receiptDate.value, [key]: '' }
    receiptReason.value = { ...receiptReason.value, [key]: '' }
    success.value = t('payroll.sicknessCases.receiptRecorded')
    await load()
  } catch (err) {
    error.value = message(err)
    errorCase.value = item
  } finally {
    busyId.value = null
  }
}

/**
 * Podání, která případ nese: NEMPRI vždy, druhé oznámení ke dni převedení jen
 * u převedené zaměstnankyně, HZUPN jen u nemocenského.
 */
function documentsFor(item: PayrollSicknessCase): PayrollSicknessDocumentKind[] {
  const documents: PayrollSicknessDocumentKind[] = ['nempri']
  if (hasTransferNotice(item)) documents.push('nempri_transfer')
  if (HZUPN_KINDS.includes(item.benefit_kind)) documents.push('hzupn')
  return documents
}

function documentStatus(
  item: PayrollSicknessCase,
  document: PayrollSicknessDocumentKind,
): PayrollSicknessDocumentStatus {
  if (document === 'nempri') return item.nempri_status ?? 'pending'
  if (document === 'nempri_transfer') return item.nempri_transfer_status ?? 'pending'
  return item.hzupn_status ?? 'pending'
}

function documentSettled(item: PayrollSicknessCase, document: PayrollSicknessDocumentKind): boolean {
  const status = documentStatus(item, document)
  return status === 'accepted' || status === 'predecessor'
}

/** Jedna věta o stavu podání, např. „NEMPRI: přijato ČSSZ 2026-06-24". */
function documentStatusText(item: PayrollSicknessCase, document: PayrollSicknessDocumentKind): string {
  const status = documentStatus(item, document)
  const label = t(`payroll.sicknessCases.documents.${document}`)
  if (status === 'accepted') {
    const date = documentReceipt(item, document).acceptedOn
    return `${label}: ${t('payroll.sicknessCases.documentStatuses.accepted', { date: date ?? '' })}`
  }
  if (status === 'rejected') {
    const reason = documentReceipt(item, document).reason
    return `${label}: ${t('payroll.sicknessCases.documentStatuses.rejected', { reason: reason ?? '' })}`
  }
  if (status === 'pending' && submissionId(item, document) !== null) {
    return `${label}: ${t('payroll.sicknessCases.documentStatuses.prepared')}`
  }
  return `${label}: ${t(`payroll.sicknessCases.documentStatuses.${status}`)}`
}

/**
 * Připravit jde podání, které ještě není připravené, nebo to, které ČSSZ
 * odmítla; vyřízené jen jako opravné podání. Podání předchozího programu
 * MyÚčto nepřipravuje vůbec.
 */
function canPrepare(item: PayrollSicknessCase, document: PayrollSicknessDocumentKind): boolean {
  const status = documentStatus(item, document)
  if (status === 'predecessor' || item.status === 'cancelled') return false
  if (status === 'rejected') return true
  if (status === 'accepted') return Boolean(item.correction)
  return submissionId(item, document) === null
}

function prepareDisabledReason(item: PayrollSicknessCase, document: PayrollSicknessDocumentKind): string {
  const status = documentStatus(item, document)
  if (!canWrite.value) return t('payroll.sicknessCases.hints.readOnly')
  if (status === 'predecessor') return t('payroll.sicknessCases.hints.documentByPredecessor')
  if (status === 'accepted') return t('payroll.sicknessCases.hints.documentSettled')
  return t('payroll.sicknessCases.hints.alreadyPrepared')
}

/** Zápis výsledku jednoho podání z protokolu (přijetí, odmítnutí, předchozí program). */
function receiptActions(item: PayrollSicknessCase, document: PayrollSicknessDocumentKind): ActionItem[] {
  const key = receiptKey(item, document)
  const suffix = DOCUMENT_SUFFIX[document]
  const status = documentStatus(item, document)
  const open = item.status !== 'cancelled'
  return [
    {
      key: `accept-${document}`,
      label: t(`payroll.sicknessCases.actions.recordAccepted${suffix}`),
      icon: 'check',
      variant: 'success',
      show: open && status !== 'predecessor' && (status !== 'accepted' || Boolean(item.correction)),
      disabled: !canWrite.value || !receiptDate.value[key],
      disabledReason: canWrite.value
        ? t('payroll.sicknessCases.hints.receiptDateRequired')
        : t('payroll.sicknessCases.hints.readOnly'),
      run: () => void recordReceipt(item, document, 'accepted'),
    },
    {
      key: `reject-${document}`,
      label: t(`payroll.sicknessCases.actions.recordRejected${suffix}`),
      icon: 'x',
      variant: 'danger',
      show: open && !documentSettled(item, document),
      disabled: !canWrite.value || !receiptReason.value[key],
      disabledReason: canWrite.value
        ? t('payroll.sicknessCases.hints.rejectionReasonRequired')
        : t('payroll.sicknessCases.hints.readOnly'),
      run: () => void recordReceipt(item, document, 'rejected'),
    },
    {
      key: `predecessor-${document}`,
      label: t(`payroll.sicknessCases.actions.recordPredecessor${suffix}`),
      icon: 'archive',
      variant: 'neutral',
      show: open && item.source === 'predecessor' && !documentSettled(item, document),
      disabled: !canWrite.value,
      disabledReason: t('payroll.sicknessCases.hints.readOnly'),
      run: () => void recordReceipt(item, document, 'predecessor'),
    },
    {
      // Převod i účetní mohly podání předchozímu programu přisoudit omylem;
      // bez vrácení by se povinnost přestala hlídat a nešla by připravit.
      key: `reopen-${document}`,
      label: t(`payroll.sicknessCases.actions.reopenPredecessor${suffix}`),
      icon: 'uturn',
      variant: 'warning',
      show: open && status === 'predecessor',
      disabled: !canWrite.value,
      disabledReason: t('payroll.sicknessCases.hints.readOnly'),
      run: () => void recordReceipt(item, document, 'pending'),
    },
  ]
}

/**
 * Odesílací akce jednoho tiskopisu.
 *
 * Nabízí se teprve tehdy, když podání EXISTUJE a ještě není ve frontě —
 * druhé zařazení sice nic nezdvojí (fronta je idempotentní), ale tlačítko,
 * které nic nedělá, se čte jako „neodešlo to".
 */
function dispatchAction(
  item: PayrollSicknessCase,
  document: PayrollSicknessDocumentKind,
): ActionItem {
  const ready = readyFor(item, document)
  const key = dispatchKey(item, document)

  return {
    key: `dispatch-${document}`,
    label: t(`payroll.sicknessCases.actions.dispatch${DOCUMENT_SUFFIX[document]}`),
    icon: 'send',
    variant: 'primary',
    show: ready !== null && ready.outbox_id === null,
    disabled: !canWrite.value || dispatchingKey.value !== null,
    disabledReason: t('payroll.sicknessCases.hints.readOnly'),
    loading: dispatchingKey.value === key,
    run: () => void dispatch(item, document),
  }
}

/**
 * Akce řádku. NEMPRI jde sestavit u každého druhu dávky; chybí-li v žádosti
 * údaj, řekne to server konkrétní větou při náhledu nebo přípravě. HZUPN se
 * nabízí jen u nemocenského — jiná dávka hlášení při ukončení neschopnosti
 * nemá, a tak by akce tvrdila povinnost, která neexistuje.
 */
function actionsFor(item: PayrollSicknessCase): ActionItem[] {
  const hzupn = HZUPN_KINDS.includes(item.benefit_kind)
  // Případ jde upravit, dokud není zrušený: vyřízené podání zamyká jen svoje
  // údaje (server to hlídá), HZUPN se po přijetí NEMPRI teprve doplňuje.
  const editable = item.status !== 'cancelled'

  return [
    {
      key: 'edit',
      label: t('payroll.sicknessCases.actions.edit'),
      icon: 'edit',
      tier: 'primary',
      variant: 'primary',
      show: editable && editingId.value !== item.id,
      disabled: !canWrite.value,
      disabledReason: t('payroll.sicknessCases.hints.readOnly'),
      run: () => edit(item),
    },
    {
      key: 'preview-nempri',
      label: t('payroll.sicknessCases.actions.previewNempri'),
      icon: 'eye',
      show: documentStatus(item, 'nempri') !== 'predecessor',
      loading: busyId.value === item.id,
      run: () => void preview(item, 'nempri'),
    },
    {
      key: 'prepare-nempri',
      label: t('payroll.sicknessCases.actions.prepareNempri'),
      icon: 'check',
      show: documentStatus(item, 'nempri') !== 'predecessor',
      disabled: !canWrite.value || !canPrepare(item, 'nempri'),
      disabledReason: prepareDisabledReason(item, 'nempri'),
      loading: busyId.value === item.id,
      run: () => void prepare(item, 'nempri'),
    },
    dispatchAction(item, 'nempri'),
    // Druhé oznámení ke dni převedení (§ 19 odst. 6, Všeobecné zásady NEMPRI):
    // podává se současně s prvním a jen u převedené zaměstnankyně.
    {
      key: 'preview-nempri_transfer',
      label: t('payroll.sicknessCases.actions.previewNempriTransfer'),
      icon: 'eye',
      show: hasTransferNotice(item) && documentStatus(item, 'nempri_transfer') !== 'predecessor',
      loading: busyId.value === item.id,
      run: () => void preview(item, 'nempri_transfer'),
    },
    {
      key: 'prepare-nempri_transfer',
      label: t('payroll.sicknessCases.actions.prepareNempriTransfer'),
      icon: 'check',
      show: hasTransferNotice(item) && documentStatus(item, 'nempri_transfer') !== 'predecessor',
      disabled: !canWrite.value || !canPrepare(item, 'nempri_transfer'),
      disabledReason: prepareDisabledReason(item, 'nempri_transfer'),
      loading: busyId.value === item.id,
      run: () => void prepare(item, 'nempri_transfer'),
    },
    dispatchAction(item, 'nempri_transfer'),
    {
      key: 'preview-hzupn',
      label: t('payroll.sicknessCases.actions.previewHzupn'),
      icon: 'eye',
      show: hzupn && documentStatus(item, 'hzupn') !== 'predecessor',
      disabled: item.incapacity_to === null,
      disabledReason: t('payroll.sicknessCases.hints.incapacityEndRequired'),
      loading: busyId.value === item.id,
      run: () => void preview(item, 'hzupn'),
    },
    {
      key: 'prepare-hzupn',
      label: t('payroll.sicknessCases.actions.prepareHzupn'),
      icon: 'check',
      show: hzupn && documentStatus(item, 'hzupn') !== 'predecessor',
      disabled: !canWrite.value
        || item.incapacity_to === null
        || !canPrepare(item, 'hzupn'),
      disabledReason: item.incapacity_to === null
        ? t('payroll.sicknessCases.hints.incapacityEndRequired')
        : prepareDisabledReason(item, 'hzupn'),
      loading: busyId.value === item.id,
      run: () => void prepare(item, 'hzupn'),
    },
    dispatchAction(item, 'hzupn'),
  ]
}

const saveActions = computed<ActionItem[]>(() => [
  {
    key: 'save',
    label: t('payroll.sicknessCases.actions.save'),
    icon: 'check',
    tier: 'primary',
    variant: 'primary',
    loading: saving.value,
    disabled: !canWrite.value,
    disabledReason: t('payroll.sicknessCases.hints.readOnly'),
    run: () => void save(),
  },
  {
    key: 'cancel',
    label: t('payroll.sicknessCases.actions.cancel'),
    run: () => cancelEdit(),
  },
])

watch(personId, value => {
  if (value !== null) {
    void loadEmployments(value)
  } else {
    employments.value = []
    newEmploymentId.value = null
  }
})
watch(environment, () => {
  cancelEdit()
  void load()
})
onMounted(() => void load())
</script>

<template>
  <div class="space-y-4" data-test="sickness-cases-panel">
    <div class="rounded-xl border border-neutral-200 bg-surface p-4">
      <div class="mb-1 flex flex-wrap items-center justify-between gap-2">
        <h3 class="text-sm font-semibold text-neutral-900">
          {{ t('payroll.sicknessCases.title') }}
        </h3>
        <!--
          Případy dávek se vedou zvlášť pro ostrý a testovací provoz. Přepínač
          je tu stejně jako u registrací: ve vývojové instalaci jde zvolit Test,
          jinde se nevykreslí nic.
        -->
        <EnvironmentSwitch
          v-model="environment"
          size="sm"
          data-test="sickness-case-environment"
          :aria-label="t('payroll.regzel.environment.label')"
        />
      </div>
      <p class="mb-3 text-xs text-neutral-600">
        {{ t('payroll.sicknessCases.intro') }}
      </p>

      <div class="grid gap-3 md:grid-cols-4">
        <label class="block text-sm">
          <span class="mb-1 block font-medium text-neutral-700">
            {{ t('payroll.sicknessCases.person') }}
          </span>
          <PayrollPersonSearchSelect
            v-model="personId"
            data-test="sickness-case-person"
            :label="t('payroll.sicknessCases.person')"
            :clearable="false"
          />
        </label>
        <label class="block text-sm">
          <span class="mb-1 block font-medium text-neutral-700">
            {{ t('payroll.sicknessCases.employment') }}
          </span>
          <SearchableSelect v-model="newEmploymentId" :options="employmentOptions" />
        </label>
        <label class="block text-sm">
          <span class="mb-1 block font-medium text-neutral-700">
            {{ t('payroll.sicknessCases.benefitKind') }}
          </span>
          <select
            v-model="newBenefitKind"
            class="w-full rounded-lg border border-neutral-300 bg-surface p-2 text-sm"
            data-test="sickness-case-benefit-kind"
          >
            <option v-for="kind in benefitKinds" :key="kind" :value="kind">
              {{ t(`payroll.sicknessCases.benefitKinds.${kind}`) }}
            </option>
          </select>
        </label>
        <label class="block text-sm">
          <span class="mb-1 block font-medium text-neutral-700">
            {{ t('payroll.sicknessCases.incapacityFrom') }}
          </span>
          <DateInput
            v-model="newIncapacityFrom"
            class="w-full rounded-lg border border-neutral-300 bg-surface p-2 text-sm"
            data-test="sickness-case-incapacity-from" />
        </label>
      </div>

      <ActionBar
        class="mt-3"
        :actions="[{
          key: 'create',
          label: t('payroll.sicknessCases.actions.create'),
          icon: 'plus',
          tier: 'primary',
          variant: 'primary',
          loading: creating,
          disabled: !canCreate,
          disabledReason: t('payroll.sicknessCases.hints.createRequirements'),
          run: () => void create(),
        }]"
      />
    </div>

    <div v-if="error" ref="errorSection" class="scroll-mt-24 rounded-lg bg-red-50 p-3 text-sm text-red-700" data-test="sickness-case-error-box">
      <p class="whitespace-pre-line" data-test="sickness-case-error">{{ error }}</p>
      <div v-if="errorFix" class="mt-2 flex flex-wrap items-center gap-2" data-test="sickness-case-error-fix">
        <span class="text-xs">
          {{ t('payroll.sicknessCases.errorFix.where', { name: errorFix.item.full_name }) }}
        </span>
        <RouterLink
          v-for="(link, index) in errorFix.links"
          :key="`${link.kind}-${index}`"
          :to="link.to"
          class="font-semibold underline"
          :data-test="`sickness-case-error-fix-${link.kind}`"
        >
          {{ t(ERROR_FIX_LABELS[link.kind]) }}
        </RouterLink>
        <ActionBar
          v-if="errorFix.edit"
          :actions="[{
            key: 'error-fix-edit',
            label: t('payroll.sicknessCases.errorFix.editCase'),
            icon: 'edit',
            variant: 'primary',
            show: editingId !== errorFix.item.id,
            disabled: !canWrite,
            disabledReason: t('payroll.sicknessCases.hints.readOnly'),
            run: () => edit(errorFix!.item),
          }]"
        />
      </div>
    </div>
    <p v-if="success" class="rounded-lg bg-emerald-50 p-3 text-sm text-emerald-700" data-test="sickness-case-success">
      {{ success }}
    </p>

    <div v-if="loading" class="h-48 animate-pulse rounded-xl bg-neutral-100" />

    <div
      v-else-if="!items.length"
      class="rounded-xl border border-neutral-200 bg-surface p-4 text-sm text-neutral-600"
    >
      {{ t('payroll.sicknessCases.empty') }}
    </div>

    <ul v-else class="space-y-3" data-test="sickness-cases-list">
      <li
        v-for="item in items"
        :key="item.id"
        class="rounded-xl border border-neutral-200 bg-surface p-4 text-sm"
        :data-test="`sickness-case-${item.id}`"
      >
        <div class="flex flex-wrap items-baseline justify-between gap-2">
          <div>
            <span class="font-semibold text-neutral-900">{{ item.full_name }}</span>
            <span class="ml-2 text-neutral-600">
              {{ t(`payroll.sicknessCases.benefitKinds.${item.benefit_kind}`) }}
            </span>
          </div>
          <span class="text-xs text-neutral-500">
            {{ item.incapacity_from }} – {{ item.incapacity_to || '…' }}
            · {{ t(`payroll.sicknessCases.statuses.${item.status}`) }}
          </span>
        </div>
        <p
          v-if="item.status !== 'cancelled'"
          class="mt-1 text-xs text-neutral-600"
          :data-test="`sickness-case-documents-${item.id}`"
        >
          {{ documentsFor(item).map(document => documentStatusText(item, document)).join(' · ') }}
        </p>
        <p
          v-if="item.source === 'predecessor'"
          class="mt-1 text-xs text-neutral-500"
          :data-test="`sickness-case-origin-predecessor-${item.id}`"
        >
          {{ t('payroll.sicknessCases.origin.predecessor') }}
        </p>
        <p
          v-if="item.protection_period?.status === 'protection_period'"
          class="mt-2 rounded-lg bg-info-50 p-2 text-xs text-info-800"
          :data-test="`sickness-case-protection-${item.id}`"
        >
          {{ t('payroll.sicknessCases.protectionPeriod.within', {
            end: item.protection_period.employment_end ?? '',
            until: item.protection_period.protection_until ?? '',
          }) }}
        </p>
        <div
          v-else-if="item.protection_period?.status === 'outside'"
          class="mt-2 flex flex-wrap items-center gap-2 rounded-lg bg-warning-50 p-2 text-xs text-warning-800"
          :data-test="`sickness-case-protection-outside-${item.id}`"
        >
          <span>
            {{ t('payroll.sicknessCases.protectionPeriod.outside', {
              message: reasonText(item.protection_period.reason_code, item.protection_period.message),
            }) }}
            {{ t('payroll.sicknessCases.protectionPeriod.fix') }}
          </span>
          <RouterLink
            :to="{ name: 'payroll-people', query: { person: String(item.employee_id) } }"
            class="font-semibold underline"
          >
            {{ t('payroll.sicknessCases.errorFix.personCard') }}
          </RouterLink>
        </div>
        <p
          v-if="item.benefit_kind === 'DLO' && item.long_term_care_consent === 'refused'"
          class="mt-2 rounded-lg bg-warning-50 p-2 text-xs text-warning-800"
          :data-test="`sickness-case-ltc-refused-${item.id}`"
        >
          {{ t('payroll.sicknessCases.longTermCare.refusedBadge', { date: item.long_term_care_consent_on ?? '' }) }}
        </p>
        <p
          v-if="item.absence_id"
          class="mt-1 flex flex-wrap items-center gap-2 text-xs text-neutral-500"
          :data-test="`sickness-case-origin-${item.id}`"
        >
          <span>{{ t('payroll.sicknessCases.origin.fromAbsence') }}</span>
          <RouterLink :to="{ name: 'payroll-absences' }" class="underline">
            {{ t('payroll.sicknessCases.origin.openAbsences') }}
          </RouterLink>
        </p>

        <div
          v-if="editingId === item.id"
          class="mt-3 space-y-4"
          :data-test="`sickness-case-editor-${item.id}`"
        >
          <section>
            <h4 class="mb-2 text-xs font-semibold uppercase text-neutral-500">
              {{ t('payroll.sicknessCases.sections.case') }}
            </h4>
            <div class="grid gap-3 md:grid-cols-3">
              <label class="block text-sm">
                <span class="mb-1 block text-neutral-700">{{ t('payroll.sicknessCases.osszCode') }}</span>
                <input v-model.number="draft.ossz_code" type="number" min="100" max="999" class="w-full rounded-lg border border-neutral-300 p-2 text-sm">
              </label>
              <label class="block text-sm">
                <span class="mb-1 block text-neutral-700">{{ t('payroll.sicknessCases.decisionNumber') }}</span>
                <input v-model="draft.decision_number" type="text" maxlength="18" class="w-full rounded-lg border border-neutral-300 p-2 text-sm" data-test="sickness-case-decision-number">
              </label>
              <label class="block text-sm">
                <span class="mb-1 block text-neutral-700">{{ t('payroll.sicknessCases.incapacityTo') }}</span>
                <DateInput v-model="draft.incapacity_to" class="w-full rounded-lg border border-neutral-300 p-2 text-sm" data-test="sickness-case-incapacity-to" />
              </label>
              <label class="flex items-center gap-2 text-sm">
                <input v-model.number="draft.correction" type="checkbox" :true-value="1" :false-value="0" data-test="sickness-case-correction">
                {{ t('payroll.sicknessCases.form.correction') }}
              </label>
              <label class="flex items-center gap-2 text-sm">
                <input v-model.number="draft.foreign_case" type="checkbox" :true-value="1" :false-value="0" data-test="sickness-case-foreign">
                {{ t('payroll.sicknessCases.form.foreignCase') }}
              </label>
              <label class="flex items-center gap-2 text-sm">
                <input v-model.number="draft.slovak_case" type="checkbox" :true-value="1" :false-value="0" data-test="sickness-case-slovak">
                {{ t('payroll.sicknessCases.form.slovakCase') }}
              </label>
              <label class="block text-sm">
                <span class="mb-1 block text-neutral-700">{{ t('payroll.sicknessCases.form.additionalNote') }}</span>
                <input v-model="draft.additional_note" type="text" maxlength="200" class="w-full rounded-lg border border-neutral-300 p-2 text-sm">
              </label>
            </div>
            <p class="mt-2 text-xs text-neutral-500">
              {{ t('payroll.sicknessCases.form.decisionNumberHint') }}
            </p>
          </section>

          <section>
            <h4 class="mb-2 text-xs font-semibold uppercase text-neutral-500">
              {{ t('payroll.sicknessCases.sections.employerConfirmation') }}
            </h4>
            <div class="grid gap-3 md:grid-cols-3">
              <label class="flex items-center gap-2 text-sm">
                <input v-model.number="draft.worked_on_decisive_day" type="checkbox" :true-value="1" :false-value="0">
                {{ t('payroll.sicknessCases.workedOnDecisiveDay') }}
              </label>
              <label class="block text-sm">
                <span class="mb-1 block text-neutral-700">{{ t('payroll.sicknessCases.dailyWorkingHours') }}</span>
                <input v-model="draft.daily_working_hours" type="text" inputmode="decimal" class="w-full rounded-lg border border-neutral-300 p-2 text-sm">
              </label>
              <label class="block text-sm">
                <span class="mb-1 block text-neutral-700">{{ t('payroll.sicknessCases.hoursWorked') }}</span>
                <input v-model="draft.hours_worked" type="text" inputmode="decimal" class="w-full rounded-lg border border-neutral-300 p-2 text-sm">
              </label>
              <label class="flex items-center gap-2 text-sm">
                <input v-model.number="draft.receives_pension" type="checkbox" :true-value="1" :false-value="0" data-test="sickness-case-receives-pension">
                {{ t('payroll.sicknessCases.receivesPension') }}
              </label>
              <label v-if="draft.receives_pension" class="block text-sm">
                <span class="mb-1 block text-neutral-700">{{ t('payroll.sicknessCases.form.pensionKind') }}</span>
                <select v-model="draft.pension_kind" class="w-full rounded-lg border border-neutral-300 bg-surface p-2 text-sm" data-test="sickness-case-pension-kind">
                  <option :value="null">{{ t('payroll.sicknessCases.codebooks.none') }}</option>
                  <option v-for="option in pensionKindOptions" :key="option.value" :value="option.value">
                    {{ option.label }}
                  </option>
                </select>
              </label>
              <label v-if="draftHasStudentSection" class="flex items-center gap-2 text-sm">
                <input v-model.number="draft.is_student" type="checkbox" :true-value="1" :false-value="0" data-test="sickness-case-is-student">
                {{ t('payroll.sicknessCases.isStudent') }}
              </label>
              <label v-if="draftHasStudentSection && draft.is_student" class="flex items-center gap-2 text-sm">
                <input v-model.number="draft.within_school_holidays" type="checkbox" :true-value="1" :false-value="0" data-test="sickness-case-school-holidays">
                {{ t('payroll.sicknessCases.form.withinSchoolHolidays') }}
              </label>
              <label class="flex items-center gap-2 text-sm">
                <input v-model.number="draft.enforcement" type="checkbox" :true-value="1" :false-value="0">
                {{ t('payroll.sicknessCases.enforcement') }}
              </label>
              <label class="flex items-center gap-2 text-sm">
                <input v-model.number="draft.insolvency" type="checkbox" :true-value="1" :false-value="0">
                {{ t('payroll.sicknessCases.insolvency') }}
              </label>
              <label v-if="draftHasUnpaidLeaveSection" class="flex items-center gap-2 text-sm">
                <input v-model.number="draft.unpaid_leave" type="checkbox" :true-value="1" :false-value="0" data-test="sickness-case-unpaid-leave">
                {{ t('payroll.sicknessCases.unpaidLeave') }}
              </label>
              <label v-if="draftHasUnpaidLeaveSection && draft.unpaid_leave" class="block text-sm">
                <span class="mb-1 block text-neutral-700">{{ t('payroll.sicknessCases.unpaidLeaveFrom') }}</span>
                <DateInput v-model="draft.unpaid_leave_from" class="w-full rounded-lg border border-neutral-300 p-2 text-sm" data-test="sickness-case-unpaid-leave-from" />
              </label>
              <label v-if="draftHasUnpaidLeaveSection && draft.unpaid_leave" class="block text-sm">
                <span class="mb-1 block text-neutral-700">{{ t('payroll.sicknessCases.unpaidLeaveTo') }}</span>
                <DateInput v-model="draft.unpaid_leave_to" class="w-full rounded-lg border border-neutral-300 p-2 text-sm" data-test="sickness-case-unpaid-leave-to" />
              </label>
              <label v-if="draftHasMaternity" class="flex items-center gap-2 text-sm">
                <input v-model.number="draft.starts_maternity" type="checkbox" :true-value="1" :false-value="0" data-test="sickness-case-starts-maternity">
                {{ t('payroll.sicknessCases.form.startsMaternity') }}
              </label>
              <label v-if="draftHasMaternity && draft.starts_maternity" class="block text-sm">
                <span class="mb-1 block text-neutral-700">{{ t('payroll.sicknessCases.form.childBirthDate') }}</span>
                <DateInput v-model="draft.child_birth_date" class="w-full rounded-lg border border-neutral-300 p-2 text-sm" data-test="sickness-case-child-birth-date" />
              </label>
              <label v-if="draftHasTransfer" class="flex items-center gap-2 text-sm">
                <input v-model.number="draft.transferred_other_work" type="checkbox" :true-value="1" :false-value="0" data-test="sickness-case-transferred">
                {{ t('payroll.sicknessCases.form.transferredOtherWork') }}
              </label>
              <label v-if="draftHasTransfer && draft.transferred_other_work" class="block text-sm">
                <span class="mb-1 block text-neutral-700">{{ t('payroll.sicknessCases.form.transferredOn') }}</span>
                <DateInput v-model="draft.transferred_on" class="w-full rounded-lg border border-neutral-300 p-2 text-sm" data-test="sickness-case-transferred-on" />
              </label>
              <label v-if="draftHasTransfer && draft.transferred_other_work" class="block text-sm">
                <span class="mb-1 block text-neutral-700">{{ t('payroll.sicknessCases.form.transferReason') }}</span>
                <select v-model="draft.transfer_reason" class="w-full rounded-lg border border-neutral-300 bg-surface p-2 text-sm" data-test="sickness-case-transfer-reason">
                  <option :value="null">{{ t('payroll.sicknessCases.codebooks.none') }}</option>
                  <option v-for="reason in transferReasons" :key="reason" :value="reason">
                    {{ t(`payroll.sicknessCases.form.transferReasons.${reason}`) }}
                  </option>
                </select>
              </label>
              <label class="block text-sm">
                <span class="mb-1 block text-neutral-700">{{ t('payroll.sicknessCases.form.smallScopeIncome') }}</span>
                <input v-model="draftSmallScopeIncomeCzk" type="text" inputmode="decimal" class="w-full rounded-lg border border-neutral-300 p-2 text-sm" data-test="sickness-case-small-scope-income">
              </label>
            </div>
            <p class="mt-2 text-xs text-neutral-500">
              {{ t('payroll.sicknessCases.form.smallScopeIncomeHint') }}
            </p>
            <p v-if="draft.receives_pension" class="mt-1 text-xs text-neutral-500">
              {{ t('payroll.sicknessCases.form.pensionKindHint') }}
            </p>
            <p v-if="draftHasTransfer && draft.transferred_other_work" class="mt-1 text-xs text-neutral-500">
              {{ t('payroll.sicknessCases.form.transferReasonHint') }}
            </p>
          </section>

          <section v-if="draftHasApplication" data-test="sickness-case-application">
            <h4 class="mb-2 text-xs font-semibold uppercase text-neutral-500">
              {{ t('payroll.sicknessCases.sections.application') }}
            </h4>
            <p class="mb-2 text-xs text-neutral-500">
              {{ t('payroll.sicknessCases.form.applicationHint') }}
            </p>
            <div v-if="draftHasActions" class="mb-3 flex flex-wrap gap-4" data-test="sickness-case-actions">
              <label class="flex items-center gap-2 text-sm">
                <input v-model.number="draft.action_start" type="checkbox" :true-value="1" :false-value="0" data-test="sickness-case-action-start">
                {{ t('payroll.sicknessCases.form.actionStart') }}
              </label>
              <label class="flex items-center gap-2 text-sm">
                <input v-model.number="draft.action_continuation" type="checkbox" :true-value="1" :false-value="0" data-test="sickness-case-action-continuation">
                {{ t('payroll.sicknessCases.form.actionContinuation') }}
              </label>
              <label class="flex items-center gap-2 text-sm">
                <input v-model.number="draft.action_end" type="checkbox" :true-value="1" :false-value="0" data-test="sickness-case-action-end">
                {{ t('payroll.sicknessCases.form.actionEnd') }}
              </label>
              <p class="w-full text-xs text-neutral-500">
                {{ t('payroll.sicknessCases.form.actionsHint') }}
              </p>
            </div>
            <div class="grid gap-3 md:grid-cols-3">
              <label class="block text-sm">
                <span class="mb-1 block text-neutral-700">{{ t('payroll.sicknessCases.form.applicationFrom') }}</span>
                <DateInput v-model="draft.application_from" class="w-full rounded-lg border border-neutral-300 p-2 text-sm" data-test="sickness-case-application-from" />
              </label>
              <label v-if="draftKind !== 'OPP' && draftKind !== 'PPM'" class="block text-sm">
                <span class="mb-1 block text-neutral-700">{{ t('payroll.sicknessCases.form.applicationTo') }}</span>
                <DateInput v-model="draft.application_to" class="w-full rounded-lg border border-neutral-300 p-2 text-sm" />
              </label>
              <label class="block text-sm">
                <span class="mb-1 block text-neutral-700">
                  {{ draftKind === 'OPP' || draftKind === 'PPM'
                    ? t('payroll.sicknessCases.form.child')
                    : t('payroll.sicknessCases.form.caredPerson') }}
                </span>
                <SearchableSelect
                  v-model="caredDependantId"
                  :options="dependantOptions"
                  data-test="sickness-case-cared-dependant"
                />
              </label>
            </div>
            <p class="mt-1 text-xs text-neutral-500">
              {{ t('payroll.sicknessCases.form.caredPersonHint') }}
            </p>
            <p v-if="draftKind === 'PPM'" class="mt-1 text-xs text-neutral-500" data-test="sickness-case-maternity-child-hint">
              {{ t('payroll.sicknessCases.form.maternityChildHint') }}
            </p>
            <div v-if="!draft.cared_dependant_id" class="mt-2 grid gap-3 md:grid-cols-3" data-test="sickness-case-cared-manual">
              <label class="block text-sm">
                <span class="mb-1 block text-neutral-700">{{ t('payroll.sicknessCases.form.caredFirstName') }}</span>
                <input v-model="draft.cared_first_name" type="text" maxlength="100" class="w-full rounded-lg border border-neutral-300 p-2 text-sm" data-test="sickness-case-cared-first-name">
              </label>
              <label class="block text-sm">
                <span class="mb-1 block text-neutral-700">{{ t('payroll.sicknessCases.form.caredLastName') }}</span>
                <input v-model="draft.cared_last_name" type="text" maxlength="100" class="w-full rounded-lg border border-neutral-300 p-2 text-sm" data-test="sickness-case-cared-last-name">
              </label>
              <label class="block text-sm">
                <span class="mb-1 block text-neutral-700">{{ t('payroll.sicknessCases.form.caredBirthDate') }}</span>
                <DateInput v-model="draft.cared_birth_date" class="w-full rounded-lg border border-neutral-300 p-2 text-sm" />
              </label>
            </div>

            <div v-if="draftKind === 'OSE'" class="mt-3 grid gap-3 md:grid-cols-3">
              <label class="block text-sm">
                <span class="mb-1 block text-neutral-700">{{ t('payroll.sicknessCases.form.careReason') }}</span>
                <select v-model="draft.care_reason" class="w-full rounded-lg border border-neutral-300 bg-surface p-2 text-sm" data-test="sickness-case-care-reason">
                  <option :value="null">—</option>
                  <option v-for="reason in careReasons" :key="reason" :value="reason">
                    {{ t(`payroll.sicknessCases.careReasons.${reason}`) }}
                  </option>
                </select>
              </label>
              <label v-if="draft.care_reason === 'school_closed'" class="block text-sm">
                <span class="mb-1 block text-neutral-700">{{ t('payroll.sicknessCases.form.schoolName') }}</span>
                <input v-model="draft.school_name" type="text" maxlength="200" class="w-full rounded-lg border border-neutral-300 p-2 text-sm">
              </label>
              <label v-if="draft.care_reason === 'school_closed'" class="block text-sm">
                <span class="mb-1 block text-neutral-700">{{ t('payroll.sicknessCases.form.schoolBusinessId') }}</span>
                <input v-model="draft.school_business_id" type="text" maxlength="35" class="w-full rounded-lg border border-neutral-300 p-2 text-sm">
              </label>
            </div>

            <div class="mt-3 grid gap-3 md:grid-cols-3">
              <label v-if="draftHasActions" class="block text-sm">
                <span class="mb-1 block text-neutral-700">{{ t('payroll.sicknessCases.form.relationshipCode') }}</span>
                <select v-model="draft.relationship_code" class="w-full rounded-lg border border-neutral-300 bg-surface p-2 text-sm" data-test="sickness-case-relationship-code">
                  <option :value="null">{{ t('payroll.sicknessCases.codebooks.none') }}</option>
                  <option v-for="option in relationshipOptions" :key="option.value" :value="option.value">
                    {{ option.label }}
                  </option>
                </select>
              </label>
              <label v-if="draftKind === 'OPP'" class="block text-sm">
                <span class="mb-1 block text-neutral-700">{{ t('payroll.sicknessCases.form.paternityReason') }}</span>
                <select v-model="draft.paternity_reason" class="w-full rounded-lg border border-neutral-300 bg-surface p-2 text-sm" data-test="sickness-case-paternity-reason">
                  <option :value="null">{{ t('payroll.sicknessCases.codebooks.none') }}</option>
                  <option v-for="option in paternityReasonOptions" :key="option.value" :value="option.value">
                    {{ option.label }}
                  </option>
                </select>
              </label>
              <label v-if="draftKind === 'PPM'" class="block text-sm">
                <span class="mb-1 block text-neutral-700">{{ t('payroll.sicknessCases.form.maternityCareReason') }}</span>
                <select v-model="draft.maternity_care_reason" class="w-full rounded-lg border border-neutral-300 bg-surface p-2 text-sm" data-test="sickness-case-maternity-care-reason">
                  <option :value="null">{{ t('payroll.sicknessCases.codebooks.none') }}</option>
                  <option v-for="option in maternityCareReasonOptions" :key="option.value" :value="option.value">
                    {{ option.label }}
                  </option>
                </select>
              </label>
              <label v-if="draftKind === 'PPM'" class="block text-sm">
                <span class="mb-1 block text-neutral-700">{{ t('payroll.sicknessCases.form.childOrder') }}</span>
                <input v-model.number="draft.child_order" type="number" min="1" max="10" class="w-full rounded-lg border border-neutral-300 p-2 text-sm">
              </label>
            </div>
            <p v-if="draftHasActions || draftKind === 'OPP' || draftKind === 'PPM'" class="mt-1 text-xs text-neutral-500">
              {{ t('payroll.sicknessCases.form.codebookHint') }}
            </p>

            <div v-if="draftHasActions" class="mt-3 grid gap-3 md:grid-cols-3" data-test="sickness-case-declarations">
              <label class="flex items-center gap-2 text-sm">
                <input v-model.number="draft.shared_household" type="checkbox" :true-value="1" :false-value="0">
                {{ t('payroll.sicknessCases.form.sharedHousehold') }}
              </label>
              <label v-if="draftKind === 'OSE'" class="flex items-center gap-2 text-sm">
                <input v-model.number="draft.lone_caregiver" type="checkbox" :true-value="1" :false-value="0">
                {{ t('payroll.sicknessCases.form.loneCaregiver') }}
              </label>
              <label v-if="draftKind === 'OSE'" class="flex items-center gap-2 text-sm">
                <input v-model.number="draft.child_under_16" type="checkbox" :true-value="1" :false-value="0">
                {{ t('payroll.sicknessCases.form.childUnder16') }}
              </label>
              <label class="flex items-center gap-2 text-sm">
                <input v-model.number="draft.other_maternity_claim" type="checkbox" :true-value="1" :false-value="0">
                {{ t('payroll.sicknessCases.form.otherMaternityClaim') }}
              </label>
              <label class="flex items-center gap-2 text-sm">
                <input v-model.number="draft.cared_personally" type="checkbox" :true-value="1" :false-value="0">
                {{ t('payroll.sicknessCases.form.caredPersonally') }}
              </label>
              <label v-if="draftKind === 'DLO'" class="flex items-center gap-2 text-sm">
                <input v-model.number="draft.alternation" type="checkbox" :true-value="1" :false-value="0">
                {{ t('payroll.sicknessCases.form.alternation') }}
              </label>
              <p class="text-xs text-neutral-500 md:col-span-3">
                {{ t('payroll.sicknessCases.form.declarationsHint') }}
              </p>
            </div>

            <div v-if="draftKind === 'DLO'" class="mt-3 rounded-lg border border-neutral-200 p-3" data-test="sickness-case-long-term-care-consent">
              <span class="mb-1 block text-sm font-medium text-neutral-800">{{ t('payroll.sicknessCases.longTermCare.title') }}</span>
              <p class="mb-2 text-xs text-neutral-500">{{ t('payroll.sicknessCases.longTermCare.hint') }}</p>
              <div class="grid gap-3 md:grid-cols-3">
                <label class="block text-sm">
                  <span class="mb-1 block text-neutral-700">{{ t('payroll.sicknessCases.longTermCare.decision') }}</span>
                  <select v-model="draft.long_term_care_consent" class="w-full rounded-lg border border-neutral-300 bg-surface p-2 text-sm" data-test="sickness-case-ltc-consent">
                    <option :value="null">{{ t('payroll.sicknessCases.codebooks.none') }}</option>
                    <option value="granted">{{ t('payroll.sicknessCases.longTermCare.granted') }}</option>
                    <option value="refused">{{ t('payroll.sicknessCases.longTermCare.refused') }}</option>
                  </select>
                </label>
                <label v-if="draft.long_term_care_consent" class="block text-sm">
                  <span class="mb-1 block text-neutral-700">{{ t('payroll.sicknessCases.longTermCare.decidedOn') }}</span>
                  <DateInput v-model="draft.long_term_care_consent_on" class="w-full rounded-lg border border-neutral-300 p-2 text-sm" data-test="sickness-case-ltc-consent-on" />
                </label>
                <label v-if="longTermCareRefused" class="block text-sm md:col-span-3">
                  <span class="mb-1 block text-neutral-700">{{ t('payroll.sicknessCases.longTermCare.refusalReason') }}</span>
                  <textarea v-model="draft.long_term_care_refusal_reason" maxlength="500" rows="2" class="w-full rounded-lg border border-neutral-300 p-2 text-sm" data-test="sickness-case-ltc-refusal-reason" />
                </label>
              </div>
            </div>

            <div v-if="draftHasActions" class="mt-3" data-test="sickness-case-care-days">
              <span class="mb-1 block text-sm text-neutral-700">{{ t('payroll.sicknessCases.form.careDays') }}</span>
              <div
                v-for="(interval, index) in draftCareDays"
                :key="index"
                class="mb-2 flex flex-wrap items-end gap-2"
              >
                <label class="block text-sm">
                  <span class="mb-1 block text-neutral-700">{{ t('payroll.sicknessCases.form.careFrom') }}</span>
                  <DateInput v-model="interval.from" class="rounded-lg border border-neutral-300 p-2 text-sm" />
                </label>
                <label class="block text-sm">
                  <span class="mb-1 block text-neutral-700">{{ t('payroll.sicknessCases.form.careTo') }}</span>
                  <DateInput v-model="interval.to" class="rounded-lg border border-neutral-300 p-2 text-sm" />
                </label>
                <ActionBar :actions="[{
                  key: `care-remove-${index}`,
                  label: t('payroll.sicknessCases.actions.removeWorkInterval'),
                  icon: 'trash',
                  variant: 'danger',
                  run: () => removeCareInterval(index),
                }]" />
              </div>
              <ActionBar :actions="[{
                key: 'care-add',
                label: t('payroll.sicknessCases.actions.addCareInterval'),
                icon: 'plus',
                variant: 'neutral',
                run: () => addCareInterval(),
              }]" />
            </div>

            <div v-if="draftKind !== 'PPM'" class="mt-3 grid gap-3 md:grid-cols-3" data-test="sickness-case-payout-basis">
              <label v-if="draftKind === 'OSE'" class="flex items-center gap-2 text-sm">
                <input v-model.number="draft.worked_last_day" type="checkbox" :true-value="1" :false-value="0">
                {{ t('payroll.sicknessCases.form.workedLastDay') }}
              </label>
              <label class="block text-sm">
                <span class="mb-1 block text-neutral-700">{{ t('payroll.sicknessCases.shiftHoursLastDay') }}</span>
                <input v-model="draft.shift_hours_last_day" type="text" inputmode="decimal" class="w-full rounded-lg border border-neutral-300 p-2 text-sm">
              </label>
              <label class="block text-sm">
                <span class="mb-1 block text-neutral-700">{{ t('payroll.sicknessCases.hoursWorkedLastDay') }}</span>
                <input v-model="draft.hours_worked_last_day" type="text" inputmode="decimal" class="w-full rounded-lg border border-neutral-300 p-2 text-sm">
              </label>
              <label class="flex items-center gap-2 text-sm">
                <input v-model.number="draft.planned_shifts" type="checkbox" :true-value="1" :false-value="0" data-test="sickness-case-planned-shifts">
                {{ t('payroll.sicknessCases.form.plannedShifts') }}
              </label>
              <label v-if="draftKind !== 'DLO'" class="flex items-center gap-2 text-sm">
                <input v-model.number="draft.planned_shifts_worked" type="checkbox" :true-value="1" :false-value="0">
                {{ t('payroll.sicknessCases.form.plannedShiftsWorked') }}
              </label>
            </div>

            <!--
              Podklady pro výplatu DLO (DV NEMPRI25): u trvání a ukončení
              „má volno" a jeho období, při rozvržených směnách jejich rozvrh.
            -->
            <div v-if="draftKind === 'DLO'" class="mt-3 rounded-lg border border-neutral-200 p-3" data-test="sickness-case-dlo-basis">
              <span class="mb-1 block text-sm font-medium text-neutral-800">{{ t('payroll.sicknessCases.form.dloBasisTitle') }}</span>
              <p class="mb-2 text-xs text-neutral-500">{{ t('payroll.sicknessCases.form.dloBasisHint') }}</p>
              <label class="mb-2 flex items-center gap-2 text-sm">
                <input v-model.number="draft.dlo_has_leave" type="checkbox" :true-value="1" :false-value="0" data-test="sickness-case-dlo-has-leave">
                {{ t('payroll.sicknessCases.form.dloHasLeave') }}
              </label>
              <div v-if="draft.dlo_has_leave" class="mb-3" data-test="sickness-case-dlo-leave-periods">
                <span class="mb-1 block text-sm text-neutral-700">{{ t('payroll.sicknessCases.form.dloLeavePeriods') }}</span>
                <div v-for="(interval, index) in draftDloLeave" :key="index" class="mb-2 flex flex-wrap items-end gap-2">
                  <DateInput v-model="interval.from" class="rounded-lg border border-neutral-300 p-2 text-sm" />
                  <DateInput v-model="interval.to" class="rounded-lg border border-neutral-300 p-2 text-sm" />
                  <ActionBar :actions="[{
                    key: `dlo-leave-remove-${index}`,
                    label: t('payroll.sicknessCases.actions.removeWorkInterval'),
                    icon: 'trash',
                    variant: 'danger',
                    run: () => removeInterval(draftDloLeave, index),
                  }]" />
                </div>
                <ActionBar :actions="[{
                  key: 'dlo-leave-add',
                  label: t('payroll.sicknessCases.actions.addWorkInterval'),
                  icon: 'plus',
                  variant: 'neutral',
                  run: () => addInterval(draftDloLeave),
                }]" />
              </div>
              <div v-if="draft.planned_shifts" data-test="sickness-case-dlo-shift-schedule">
                <span class="mb-1 block text-sm text-neutral-700">{{ t('payroll.sicknessCases.form.dloShiftSchedule') }}</span>
                <div v-for="(interval, index) in draftDloShifts" :key="index" class="mb-2 flex flex-wrap items-end gap-2">
                  <DateInput v-model="interval.from" class="rounded-lg border border-neutral-300 p-2 text-sm" />
                  <DateInput v-model="interval.to" class="rounded-lg border border-neutral-300 p-2 text-sm" />
                  <ActionBar :actions="[{
                    key: `dlo-shift-remove-${index}`,
                    label: t('payroll.sicknessCases.actions.removeWorkInterval'),
                    icon: 'trash',
                    variant: 'danger',
                    run: () => removeInterval(draftDloShifts, index),
                  }]" />
                </div>
                <ActionBar :actions="[{
                  key: 'dlo-shift-add',
                  label: t('payroll.sicknessCases.actions.addWorkInterval'),
                  icon: 'plus',
                  variant: 'neutral',
                  run: () => addInterval(draftDloShifts),
                }]" />
              </div>
            </div>
          </section>

          <section data-test="sickness-case-decisive-period">
            <h4 class="mb-2 text-xs font-semibold uppercase text-neutral-500">
              {{ t('payroll.sicknessCases.sections.decisivePeriod') }}
            </h4>
            <p class="mb-2 text-xs text-neutral-500">
              {{ t('payroll.sicknessCases.form.decisivePeriodHint') }}
            </p>
            <div class="mb-3 flex flex-wrap items-end gap-2">
              <label class="block text-sm">
                <span class="mb-1 block text-neutral-700">{{ t('payroll.sicknessCases.form.probableIncome') }}</span>
                <input v-model.number="draft.probable_income_czk" type="number" min="0" step="1" class="w-40 rounded-lg border border-neutral-300 p-2 text-sm" data-test="sickness-case-probable-income">
              </label>
              <ActionBar :actions="[{
                key: 'suggest-probable-income',
                label: probableIncomeSuggestion === null
                  ? t('payroll.sicknessCases.actions.suggestProbableIncome')
                  : t('payroll.sicknessCases.actions.suggestProbableIncomeValue', { amount: probableIncomeSuggestion }),
                icon: 'coin',
                variant: 'neutral',
                disabled: probableIncomeSuggestion === null,
                disabledReason: t('payroll.sicknessCases.hints.probableIncomeNoSuggestion'),
                run: () => suggestProbableIncome(),
              }]" />
            </div>
            <p class="mb-2 text-xs text-neutral-500">
              {{ t('payroll.sicknessCases.form.probableIncomeHint') }}
            </p>
            <div
              v-for="(month, index) in draftDecisiveMonths"
              :key="index"
              class="mb-2 flex flex-wrap items-end gap-2"
              :data-test="`sickness-case-decisive-month-${index}`"
            >
              <label class="block text-sm">
                <span class="mb-1 block text-neutral-700">{{ t('payroll.sicknessCases.form.decisiveMonth') }}</span>
                <input v-model="month.period" type="month" class="rounded-lg border border-neutral-300 p-2 text-sm">
              </label>
              <label class="block text-sm">
                <span class="mb-1 block text-neutral-700">{{ t('payroll.sicknessCases.form.countableIncome') }}</span>
                <input v-model="month.income_czk" type="text" inputmode="decimal" class="w-36 rounded-lg border border-neutral-300 p-2 text-sm">
              </label>
              <label class="block text-sm">
                <span class="mb-1 block text-neutral-700">{{ t('payroll.sicknessCases.form.excludedDays') }}</span>
                <input v-model.number="month.excluded_days" type="number" min="0" max="31" class="w-24 rounded-lg border border-neutral-300 p-2 text-sm">
              </label>
              <ActionBar :actions="[{
                key: `decisive-remove-${index}`,
                label: t('payroll.sicknessCases.actions.removeWorkInterval'),
                icon: 'trash',
                variant: 'danger',
                run: () => removeDecisiveMonth(index),
              }]" />
            </div>
            <ActionBar :actions="[{
              key: 'decisive-add',
              label: t('payroll.sicknessCases.actions.addDecisiveMonth'),
              icon: 'plus',
              variant: 'neutral',
              run: () => addDecisiveMonth(),
            }]" />
          </section>

          <section data-test="sickness-case-contact">
            <h4 class="mb-2 text-xs font-semibold uppercase text-neutral-500">
              {{ t('payroll.sicknessCases.sections.contact') }}
            </h4>
            <div class="grid gap-3 md:grid-cols-3">
              <label class="block text-sm">
                <span class="mb-1 block text-neutral-700">{{ t('payroll.sicknessCases.form.contactName') }}</span>
                <input v-model="draft.contact_worker_name" type="text" maxlength="100" class="w-full rounded-lg border border-neutral-300 p-2 text-sm" data-test="sickness-case-contact-name">
              </label>
              <label class="block text-sm">
                <span class="mb-1 block text-neutral-700">{{ t('payroll.sicknessCases.form.contactPhone') }}</span>
                <input v-model="draft.contact_worker_phone" type="tel" maxlength="33" class="w-full rounded-lg border border-neutral-300 p-2 text-sm">
              </label>
              <label class="block text-sm">
                <span class="mb-1 block text-neutral-700">{{ t('payroll.sicknessCases.form.contactEmail') }}</span>
                <input v-model="draft.contact_worker_email" type="email" maxlength="250" class="w-full rounded-lg border border-neutral-300 p-2 text-sm">
              </label>
            </div>
            <p class="mt-2 text-xs text-neutral-500">
              {{ t('payroll.sicknessCases.form.paymentConnectionHint') }}
            </p>
          </section>

          <section v-if="draftHasHzupn">
            <h4 class="mb-2 text-xs font-semibold uppercase text-neutral-500">
              {{ t('payroll.sicknessCases.sections.endOfIncapacity') }}
            </h4>
            <p class="mb-2 text-xs text-neutral-500">
              {{ t('payroll.sicknessCases.hints.endOfIncapacity') }}
            </p>
            <div class="grid gap-3 md:grid-cols-3">
              <label class="block text-sm">
                <span class="mb-1 block text-neutral-700">{{ t('payroll.sicknessCases.issuedOn') }}</span>
                <DateInput v-model="draft.issued_on" class="w-full rounded-lg border border-neutral-300 p-2 text-sm" data-test="sickness-case-issued-on" />
              </label>
              <label class="block text-sm">
                <span class="mb-1 block text-neutral-700">{{ t('payroll.sicknessCases.returnedToWork') }}</span>
                <select v-model="returnedToWorkChoice" class="w-full rounded-lg border border-neutral-300 bg-surface p-2 text-sm" data-test="sickness-case-returned-to-work">
                  <option value="">—</option>
                  <option value="1">{{ t('payroll.sicknessCases.form.returnedYes') }}</option>
                  <option value="0">{{ t('payroll.sicknessCases.form.returnedNo') }}</option>
                </select>
              </label>
              <label v-if="returnedToWorkChoice === '0'" class="block text-sm">
                <span class="mb-1 block text-neutral-700">{{ t('payroll.sicknessCases.form.returnReason') }}</span>
                <input v-model="draft.return_reason" type="text" maxlength="200" class="w-full rounded-lg border border-neutral-300 p-2 text-sm" data-test="sickness-case-return-reason">
              </label>
              <label class="block text-sm">
                <span class="mb-1 block text-neutral-700">
                  {{ returnedToWorkChoice === '0'
                    ? t('payroll.sicknessCases.form.returnReasonDate')
                    : t('payroll.sicknessCases.returnedOn') }}
                </span>
                <DateInput v-model="draft.returned_on" class="w-full rounded-lg border border-neutral-300 p-2 text-sm" data-test="sickness-case-returned-on" />
              </label>
              <label class="block text-sm">
                <span class="mb-1 block text-neutral-700">{{ t('payroll.sicknessCases.hoursWorkedLastDay') }}</span>
                <input v-model="draft.hours_worked_last_day" type="text" inputmode="decimal" class="w-full rounded-lg border border-neutral-300 p-2 text-sm">
              </label>
              <label class="block text-sm">
                <span class="mb-1 block text-neutral-700">{{ t('payroll.sicknessCases.shiftHoursLastDay') }}</span>
                <input v-model="draft.shift_hours_last_day" type="text" inputmode="decimal" class="w-full rounded-lg border border-neutral-300 p-2 text-sm">
              </label>
            </div>
          </section>

          <section>
            <h4 class="mb-2 text-xs font-semibold uppercase text-neutral-500">
              {{ draftHasHzupn
                ? t('payroll.sicknessCases.sections.workDays')
                : t('payroll.sicknessCases.sections.workDaysBenefit') }}
            </h4>
            <p class="mb-2 text-xs text-neutral-500">
              {{ t('payroll.sicknessCases.hints.workDays') }}
            </p>
            <div
              v-for="(interval, index) in draftWorkDays"
              :key="index"
              class="mb-2 flex flex-wrap items-end gap-2"
              :data-test="`sickness-case-work-day-${index}`"
            >
              <label class="block text-sm">
                <span class="mb-1 block text-neutral-700">{{ t('payroll.sicknessCases.workedFrom') }}</span>
                <DateInput v-model="interval.from" class="rounded-lg border border-neutral-300 p-2 text-sm" />
              </label>
              <label class="block text-sm">
                <span class="mb-1 block text-neutral-700">{{ t('payroll.sicknessCases.workedTo') }}</span>
                <DateInput v-model="interval.to" class="rounded-lg border border-neutral-300 p-2 text-sm" />
              </label>
              <button
                type="button"
                class="cursor-pointer rounded-lg border border-neutral-300 px-3 py-2 text-sm text-neutral-700"
                :data-test="`sickness-case-work-day-remove-${index}`"
                @click="removeWorkInterval(index)"
              >
                {{ t('payroll.sicknessCases.actions.removeWorkInterval') }}
              </button>
            </div>
            <button
              type="button"
              class="cursor-pointer rounded-lg border border-neutral-300 px-3 py-2 text-sm text-neutral-700"
              data-test="sickness-case-work-day-add"
              @click="addWorkInterval()"
            >
              {{ t('payroll.sicknessCases.actions.addWorkInterval') }}
            </button>
          </section>

          <!--
            Jedno společné Uložit pro celý editor. Tlačítko u každé sekce by
            znamenalo, že se dá uložit půlka případu.
          -->
          <div
            class="sticky bottom-[var(--app-footer-height,0px)] -mx-4 mt-4 border-t border-neutral-200 bg-surface px-4 py-3"
            data-test="sickness-case-save-bar"
          >
            <ActionBar :actions="saveActions" />
          </div>
        </div>

        <!--
          Výsledek z protokolu ČSSZ se zapisuje ke každému podání zvlášť:
          NEMPRI a HZUPN mají vlastní lhůty a přijetí jednoho neuzavírá druhé.
        -->
        <div v-else-if="item.status !== 'cancelled'" class="mt-3 space-y-3">
          <template v-for="document in documentsFor(item)" :key="document">
            <div
              v-if="!documentSettled(item, document) || item.correction || documentStatus(item, document) === 'predecessor'"
              class="rounded-lg border border-neutral-200 p-3"
              :data-test="`sickness-case-receipt-${item.id}-${document}`"
            >
              <p class="mb-2 text-xs font-semibold uppercase text-neutral-500">
                {{ t('payroll.sicknessCases.receipt.title', { document: t(`payroll.sicknessCases.documents.${document}`) }) }}
              </p>
              <p
                v-if="documentStatus(item, document) === 'predecessor'"
                class="text-xs text-neutral-500"
                :data-test="`sickness-case-reopen-hint-${item.id}-${document}`"
              >
                {{ t('payroll.sicknessCases.receipt.reopenHint', { document: t(`payroll.sicknessCases.documents.${document}`) }) }}
              </p>
              <div v-else class="grid gap-3 md:grid-cols-2">
                <label class="block text-sm">
                  <span class="mb-1 block text-neutral-700">{{ t('payroll.sicknessCases.acceptedOn') }}</span>
                  <DateInput
                    v-model="receiptDate[receiptKey(item, document)]"
                    class="w-full rounded-lg border border-neutral-300 p-2 text-sm"
                    :data-test="`sickness-case-accepted-on-${item.id}-${document}`" />
                </label>
                <label v-if="!documentSettled(item, document)" class="block text-sm">
                  <span class="mb-1 block text-neutral-700">{{ t('payroll.sicknessCases.rejectionReason') }}</span>
                  <input
                    v-model="receiptReason[receiptKey(item, document)]"
                    type="text"
                    maxlength="190"
                    class="w-full rounded-lg border border-neutral-300 p-2 text-sm"
                    :data-test="`sickness-case-rejection-reason-${item.id}-${document}`"
                  >
                </label>
              </div>
              <p v-if="item.source === 'predecessor' && !documentSettled(item, document)" class="mt-2 text-xs text-neutral-500">
                {{ t('payroll.sicknessCases.receipt.predecessorHint') }}
              </p>
              <ActionBar class="mt-2" :actions="receiptActions(item, document)" />
            </div>
          </template>
        </div>

        <ActionBar class="mt-3" :actions="actionsFor(item)" />

        <!--
          Odesílací lišta připraveného podání. Právě jedna ze tří vět: appka
          odešle (brána), odešle po potvrzení v mobilu, nebo si přílohu stáhnete
          a odešlete ze své schránky. Žádné „odešlete ho jinde" bez adresy.
        -->
        <div
          v-for="document in documentsFor(item)"
          :key="document"
        >
          <div
            v-if="readyFor(item, document)"
            class="mt-3 rounded-lg border border-payroll-500/30 bg-payroll-50 p-3 text-xs text-neutral-700"
            :data-test="`sickness-case-dispatch-${item.id}-${document}`"
          >
            <p class="font-semibold text-neutral-900">
              {{ t(`payroll.sicknessCases.dispatch.title.${document}`) }}
            </p>
            <p class="mt-1">{{ transportNote() }}</p>

            <p
              v-if="readyFor(item, document)!.outbox_id !== null"
              class="mt-2"
              :data-test="`sickness-case-outbox-${item.id}-${document}`"
            >
              {{ t('payroll.sicknessCases.dispatch.inOutbox', {
                id: readyFor(item, document)!.outbox_id,
              }) }}
              <a href="/admin/databox?tab=outbox" class="font-semibold underline">
                {{ t('payroll.sicknessCases.dispatch.openOutbox') }}
              </a>
            </p>

            <div
              v-if="dispatched[dispatchKey(item, document)]"
              class="mt-2 space-y-1"
              :data-test="`sickness-case-dispatched-${item.id}-${document}`"
            >
              <p>
                {{ t('payroll.sicknessCases.dispatch.recipient', {
                  name: dispatched[dispatchKey(item, document)]!.recipient.name,
                  id: dispatched[dispatchKey(item, document)]!.recipient.box_id,
                }) }}
              </p>
              <p>
                {{ t('payroll.sicknessCases.dispatch.senderIdent', {
                  value: dispatched[dispatchKey(item, document)]!.sender_ident,
                }) }}
              </p>
              <p v-if="mobileKeySent[dispatchKey(item, document)]" class="font-semibold">
                {{ t('databox.outbox.mobileKey.sent') }}
              </p>
              <IsdsSendMethods
                v-else-if="!dispatched[dispatchKey(item, document)]!.transport.automatic"
                class="mt-1 text-neutral-900"
                :outbox-id="dispatched[dispatchKey(item, document)]!.outbox_id"
                :environment="environment"
                :transport="dispatched[dispatchKey(item, document)]!.transport"
                :gateway-start="dataBoxApi.gatewayStartPayroll"
                @sent="(sent: boolean) => { if (sent) markMobileKeySent(dispatchKey(item, document)) }"
              />
              <button
                v-else-if="gateways[dispatchKey(item, document)]"
                type="button"
                class="mt-1 cursor-pointer rounded-lg border border-primary-500/40 px-3 py-2 text-sm font-semibold text-primary-700"
                :data-test="`sickness-case-gateway-${item.id}-${document}`"
                @click="continueGateway(dispatchKey(item, document))"
              >
                {{ t('payroll.sicknessCases.dispatch.continueGateway') }}
              </button>
            </div>
          </div>
        </div>

        <pre
          v-if="previewXml && previewXml.id === item.id"
          class="mt-3 max-h-72 overflow-auto rounded-lg bg-neutral-900 p-3 text-xs text-neutral-100"
          :data-test="`sickness-case-preview-${item.id}`"
        >{{ previewXml.xml }}</pre>
      </li>
    </ul>
    <ProductionSendConfirmDialog
      v-if="sendConfirmRequest"
      :message="sendConfirmRequest.message"
      @confirm="settleSendConfirm(true)"
      @cancel="settleSendConfirm(false)"
    />
  </div>
</template>

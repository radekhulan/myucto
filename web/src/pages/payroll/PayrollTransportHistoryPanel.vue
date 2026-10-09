<script setup lang="ts">
/**
 * Co jsme odeslali na ČSSZ a jak to dopadlo.
 *
 * Odesílací cesta i ledger pokusů existovaly dřív než tahle obrazovka, takže
 * odpověď na otázku „co jsem podal a v jakém je to stavu" žila jen v databázi.
 * Historické pokusy se tu jen čtou a doptávají. Výjimkou jsou přesně určená
 * zmrazená podání ve stavu `ready`, která ještě nemají žádný pokus: ta se tu
 * dají odeslat podle vlastního ID, aby opravné či stornovací podání po přípravě
 * nezmizelo a UI omylem nehledalo jiné podání za stejné období.
 *
 * Tři rozlišení, na kterých celá obrazovka stojí:
 *
 *  * `awaiting_protocol` NENÍ přijaté podání. ČSSZ potvrzuje převzetí hned
 *    a o výsledku rozhoduje až potom; kdo si to splete, přestane výsledek
 *    sledovat. Hotovo je teprve `completed`.
 *  * Neúspěšné pokusy jsou to hlavní, kvůli čemu se sem uživatel podívá —
 *    kód i hláška chyby jsou proto vidět rovnou, ne po rozkliknutí.
 *  * Ledger je přírůstkový. Několik pokusů k jednomu podání je doklad o tom,
 *    co se dělo, takže se seskupují a pořadí se zachovává.
 *
 * Čtvrté rozlišení přibylo s načítáním protokolů: NE VŠECHNO, co firma podala,
 * odešlo naší cestou. Kdo přechází od jiného softwaru, má podání u ČSSZ a
 * protokol v datové schránce, ale ledger prázdný — a prázdná obrazovka se čte
 * jako „nic neodešlo". Načtené protokoly proto stojí v témž chronologickém
 * přehledu jako naše pokusy, ale VŽDY označené zdrojem: u načteného protokolu
 * aplikace nezná datovou větu, nemůže se doptat na stav ani uzavřít transakci,
 * a tvářit se, že ano, by bylo horší než ho neukázat.
 */
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { apiErrorMessage } from '@/api/errors'
import { dataBoxApi, type GatewayStart } from '@/api/dataBox'
import {
  payrollApi,
  type PayrollJmhzContentCorrectionForm,
  type PayrollJmhzCorrectableComponent,
  type PayrollJmhzXmlDryRunBlocker,
  type PayrollJmhzContentCorrectionPreparation,
  type PayrollJmhzImportedProtocol,
  type PayrollJmhzIsdsEnqueueResult,
  type PayrollJmhzProtocolError,
  type PayrollJmhzProtocolReverification,
  type PayrollJmhzDispatchedSubmission,
  type PayrollJmhzReadySubmission,
  type PayrollJmhzTransportAttempt,
  type PayrollJmhzTransportEnvironment,
  type PayrollJmhzTransportPoll,
  type PayrollJmhzTransportStatus,
  type PayrollSubmissionManualAcceptance as ManualAcceptanceSummary,
} from '@/api/payroll'
import { useAuthStore } from '@/stores/auth'
import PaginationBar from '@/components/ui/PaginationBar.vue'
import PeriodFilterBar from '@/components/ui/PeriodFilterBar.vue'
import { pickDefaultYear } from '@/utils/periodDefaultYear'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import { btnFilled, btnOutline, btnOutlineSm, ICONS } from '@/components/ui/buttonStyles'
// Ledger vrací syrové ISO tvary („2026-07-31", „2026-07-31 09:12:04"). Účetní
// čte přehled vedle dokladů, kde je všude „31.07.2026" — dvojí tvar na jedné
// stránce se čte jako dvě různá data.
import { formatDate, formatDateTime, formatPeriod, formatUtcDateTime } from '@/composables/useFormat'
import ProductionSendConfirmDialog from '@/components/payroll/ProductionSendConfirmDialog.vue'
import PayrollSubmissionManualAcceptance from '@/components/payroll/PayrollSubmissionManualAcceptance.vue'
import PayrollPossiblyDeliveredNotice from '@/components/payroll/PayrollPossiblyDeliveredNotice.vue'
import { useProductionSendConfirm } from '@/composables/useProductionSendConfirm'
import { isTestEnvironmentRejection, useSubmissionEnvironment } from '@/composables/useSubmissionEnvironment'
import { jmhzBlockerLabel } from './jmhzBlockerRemediation'
import JmhzProtocolErrorRemediation from './JmhzProtocolErrorRemediation.vue'

const { t, te } = useI18n()
const auth = useAuthStore()
const {
  request: sendConfirmRequest,
  confirmProductionSend,
  settle: settleSendConfirm,
} = useProductionSendConfirm()

const ENVIRONMENTS: PayrollJmhzTransportEnvironment[] = ['production', 'test']

const environment = defineModel<PayrollJmhzTransportEnvironment>('environment', {
  default: 'production',
})
/*
 * Mimo vývojovou instalaci existuje jen produkce. Vlastní přepínač panelu tu
 * dřív nebyl na politice závislý: klik na Test poslal dotaz do testu, server ho
 * odmítl a obrazovka ukázala prázdný seznam s chybou načtení, přestože podání
 * v produkci byla.
 */
const { testAllowed: submissionTestAllowed } = useSubmissionEnvironment(environment)
const requestEnvironment = computed<PayrollJmhzTransportEnvironment>(() =>
  submissionTestAllowed.value ? environment.value : 'production')
const loading = ref(false)
const attempts = ref<PayrollJmhzTransportAttempt[]>([])
const readySubmissions = ref<PayrollJmhzReadySubmission[]>([])
const dispatchedSubmissions = ref<PayrollJmhzDispatchedSubmission[]>([])
const imported = ref<PayrollJmhzImportedProtocol[]>([])
/** Poslední ruční potvrzení přijetí podle podání — štítek „Přijato ručně". */
const manualAcceptances = ref<Record<number, ManualAcceptanceSummary>>({})
const importing = ref(false)
const fileInput = ref<HTMLInputElement | null>(null)

/**
 * Chyba načtení se drží ve stavu a NIKDY se nepřevádí na prázdný seznam.
 * „Zatím nic neodesláno" u požadavku, který selhal, je horší než chybová
 * hláška: uživatel z něj usoudí, že podání neodešlo, a odešle ho podruhé.
 */
const loadError = ref('')
const actionError = ref('')
const success = ref('')

const pollingId = ref<number | null>(null)
const closingId = ref<number | null>(null)
const reverifyingId = ref<number | null>(null)
/** Výsledky znovu ověření protokolu, klíčované ID pokusu — přežijí znovunačtení. */
const reverifications = ref<Record<number, PayrollJmhzProtocolReverification>>({})
const copiedId = ref<number | null>(null)
/** Pokus, u kterého schránka odmítla zápis — tlačítko o tom musí říct nahlas. */
const copyFailedId = ref<number | null>(null)
/** Podání, u kterého uživatel právě potvrzuje storno. Storno je nevratné. */
const cancellingId = ref<number | null>(null)
const cancelPendingId = ref<number | null>(null)
const correctingId = ref<number | null>(null)
const correctionPendingId = ref<number | null>(null)
const correctionLoadingId = ref<number | null>(null)
const correctionPreparationLoadingId = ref<number | null>(null)
const correctableComponents = ref<PayrollJmhzContentCorrectionForm[]>([])
const correctionPreparations = ref<PayrollJmhzContentCorrectionPreparation[]>([])
const selectedCorrectionGuids = ref<string[]>([])
const correctionPreparationId = ref<number | null>(null)
const correctionCandidatesLoaded = ref(false)
const correctionQuery = ref('')
const correctionImpactConfirmed = ref(false)
/** Vztahy s nálezem, které se teď opravit nedají; opravu ostatních nezastaví. */
const correctionBlocked = ref<PayrollJmhzXmlDryRunBlocker[]>([])
const readyDispatchPending = ref<{ id: number; channel: 'isds' | 'vrep' } | null>(null)
const readyIsdsResults = ref<Record<number, PayrollJmhzIsdsEnqueueResult>>({})
const readyGateways = ref<Record<number, GatewayStart>>({})

/** Výsledky doptání, klíčované ID pokusu — zůstávají do dalšího načtení. */
const polls = ref<Record<number, PayrollJmhzTransportPoll>>({})

/*
 * Vysvětlené chyby načtených protokolů. Seznam je nenese — počítají se z
 * uloženého XML, takže se dotahují až pro řádek, který uživatel rozbalí, a pak
 * si je pamatujeme: druhé rozbalení téhož protokolu už na server nechodí.
 */
const protocolErrors = ref<Record<number, PayrollJmhzProtocolError[]>>({})
const protocolErrorsOpen = ref<Record<number, boolean>>({})
const protocolErrorsLoading = ref<Record<number, boolean>>({})
const protocolErrorsFailed = ref<Record<number, boolean>>({})
/** `false` = uložený originál se nepodařilo znovu přečíst, detail neexistuje. */
const protocolDetailAvailable = ref<Record<number, boolean>>({})

// Rychlý filtr podle OBDOBÍ hlášení — podle toho, čím je karta nadepsaná.
const filterYear = ref<number | null>(null)
const filterMonth = ref<number | null>(null)
const filterYears = ref<number[]>([])

/** Aby se předvolení roku stalo právě jednou, ne po každém načtení. */
const defaultYearApplied = ref(false)

/**
 * Předvolí rok, jakmile je z čeho vybírat, a vrátí, jestli se něco změnilo.
 *
 * Samotný měsíc je matoucí — „leden" neřekne který. Bere se dnešní rok; když
 * v datech není (firma letos ještě nepodávala), tak nejnovější, který v nich
 * je, ať filtr nezačne prázdnou obrazovkou. Roky zná až odpověď serveru, takže
 * se předvolba dá udělat nejdřív po prvním načtení — a to se pak musí načíst
 * znovu, jinak by rok svítil ve výběru, ale na seznam by neplatil.
 */
function ensureDefaultYear(): boolean {
  if (defaultYearApplied.value) return false
  defaultYearApplied.value = true
  if (filterYear.value !== null) return false
  const preset = pickDefaultYear(filterYears.value)
  if (preset === null) return false
  filterYear.value = preset
  return true
}

async function applyPeriodFilter(next: { year?: number | null; month?: number | null }) {
  if (next.year !== undefined) filterYear.value = next.year
  if (next.month !== undefined) filterMonth.value = next.month
  // Jiný filtr má vlastní počet řádků; zůstat na páté stránce by ukázalo prázdno.
  attemptsOffset.value = 0
  await load()
}

const attemptsPageSize = 25
const attemptsTotal = ref(0)
const attemptsOffset = ref(0)
const attemptsPage = computed(() =>
  Math.floor(attemptsOffset.value / attemptsPageSize) + 1)

const importedPageSize = 25
const importedTotal = ref(0)
const importedOffset = ref(0)
const importedPage = computed(() =>
  Math.floor(importedOffset.value / importedPageSize) + 1)

function goToAttemptsPage(nextPage: number) {
  attemptsOffset.value = Math.max(0, (nextPage - 1) * attemptsPageSize)
  void load()
}

function goToImportedPage(nextPage: number) {
  importedOffset.value = Math.max(0, (nextPage - 1) * importedPageSize)
  void load()
}

function resetProtocolErrors() {
  protocolErrors.value = {}
  protocolErrorsOpen.value = {}
  protocolErrorsLoading.value = {}
  protocolErrorsFailed.value = {}
  protocolDetailAvailable.value = {}
}

async function toggleProtocolErrors(protocol: PayrollJmhzImportedProtocol) {
  const id = protocol.id
  if (protocolErrorsOpen.value[id]) {
    protocolErrorsOpen.value = { ...protocolErrorsOpen.value, [id]: false }
    return
  }
  protocolErrorsOpen.value = { ...protocolErrorsOpen.value, [id]: true }
  if (protocolErrors.value[id] !== undefined || protocolErrorsLoading.value[id]) return
  protocolErrorsLoading.value = { ...protocolErrorsLoading.value, [id]: true }
  protocolErrorsFailed.value = { ...protocolErrorsFailed.value, [id]: false }
  try {
    const detail = await payrollApi.jmhzImportedProtocolErrors(id, requestEnvironment.value)
    protocolErrors.value = { ...protocolErrors.value, [id]: detail.errors }
    protocolDetailAvailable.value = {
      ...protocolDetailAvailable.value,
      [id]: detail.detail_available,
    }
  } catch {
    protocolErrorsFailed.value = { ...protocolErrorsFailed.value, [id]: true }
  } finally {
    const { [id]: _pending, ...rest } = protocolErrorsLoading.value
    protocolErrorsLoading.value = rest
  }
}

const variableSymbol = ref('')
const variableSymbolTouched = ref(false)
/** Variabilní symboly z nastavení zaměstnavatele — kandidáti, ne jistota. */
const variableSymbolOptions = ref<Array<{ value: string; label: string }>>([])
/**
 * Ostrý a testovací VS ČSSZ podle účtárny. ČSSZ přiděluje oba odděleně a
 * testovací prostředí přijímá VÝHRADNĚ svůj vlastní — podání pod ostrým
 * symbolem zamítne s hláškou, která na záměnu VS vůbec neukazuje. Obojí se
 * drží zvlášť, aby šlo poznat, kdy uživatel v testu omylem zadal ostrý VS.
 */
const knownProductionVariableSymbols = ref<Set<string>>(new Set())
const knownTestVariableSymbols = ref<Set<string>>(new Set())

/**
 * ČSSZ podání pod cizím VS v testu jen zamítne, nezablokujeme ho tedy tady —
 * je to varování, ne validace. Svítí, když je zadaný symbol prokazatelně
 * ostrý, nebo když máme uložený jiný testovací symbol a uživatel zadal ještě
 * jiný.
 */
const testEnvironmentVariableSymbolWarning = computed(() => {
  if (requestEnvironment.value !== 'test') return false
  const value = variableSymbol.value.trim()
  if (!variableSymbolValid.value) return false
  if (knownProductionVariableSymbols.value.has(value)) return true
  return knownTestVariableSymbols.value.size > 0 && !knownTestVariableSymbols.value.has(value)
})

const canWrite = computed(() => auth.canWrite('payroll.submissions'))
const busy = computed(() =>
  loading.value
  || importing.value
  || pollingId.value !== null
  || closingId.value !== null
  || reverifyingId.value !== null
  || cancelPendingId.value !== null
  || correctionPendingId.value !== null
  || correctionLoadingId.value !== null
  || correctionPreparationLoadingId.value !== null
  || readyDispatchPending.value !== null,
)

const variableSymbolValid = computed(() =>
  /^[0-9]{1,10}$/.test(variableSymbol.value.trim()),
)

interface AttemptGroup {
  submissionId: number
  /** Období hlášení; nese ho každý řádek ledgeru, uvnitř skupiny je stejné. */
  periodStart: string | null
  periodEnd: string | null
  submissionKind: string | null
  submissionStatus: string | null
  correctsSubmissionId: number | null
  attempts: PayrollJmhzTransportAttempt[]
  /**
   * Odchozí zpráva datové schránky, pokud podání odešlo tudy. Skupina pak
   * může obsahovat i dřívější pokusy o odeslání přes VREP.
   */
  dispatched: PayrollJmhzDispatchedSubmission | null
}

/**
 * Seskupení podle podání se zachovaným pořadím: první výskyt určí místo
 * skupiny, pokusy uvnitř zůstanou tak, jak přišly ze serveru (od nejnovějšího).
 *
 * Za pokusy se přidávají hlášení odeslaná DATOVKOU. Ta žádný pokus nemají,
 * takže by se do přehledu jinak nedostala vůbec — a s nimi ani storno a oprava.
 * Podání, které má obojí (zkusilo se VREP a odešlo datovkou), zůstává jednou
 * kartou s historií obou přenosových kanálů.
 */
const groups = computed<AttemptGroup[]>(() => {
  const byId = new Map<number, AttemptGroup>()
  const ordered: AttemptGroup[] = []
  for (const attempt of attempts.value) {
    let group = byId.get(attempt.submission_id)
    if (!group) {
      group = {
        submissionId: attempt.submission_id,
        periodStart: attempt.period_start,
        periodEnd: attempt.period_end,
        submissionKind: attempt.submission_kind,
        submissionStatus: attempt.submission_status,
        correctsSubmissionId: attempt.corrects_submission_id,
        attempts: [],
        dispatched: null,
      }
      byId.set(attempt.submission_id, group)
      ordered.push(group)
    }
    group.attempts.push(attempt)
  }
  for (const sent of dispatchedSubmissions.value) {
    const existing = byId.get(sent.submission_id)
    if (existing) {
      existing.dispatched = sent
      existing.submissionStatus = sent.submission_status
      continue
    }
    const group: AttemptGroup = {
      submissionId: sent.submission_id,
      periodStart: sent.period_start,
      periodEnd: sent.period_end,
      submissionKind: sent.submission_kind,
      submissionStatus: sent.submission_status,
      correctsSubmissionId: sent.corrects_submission_id,
      attempts: [],
      dispatched: sent,
    }
    byId.set(sent.submission_id, group)
    ordered.push(group)
  }
  return ordered
})

const correctionGroup = computed(() =>
  groups.value.find(group => group.submissionId === correctingId.value) ?? null,
)

function protocolErrorMatchesComponent(
  error: PayrollJmhzProtocolError,
  component: PayrollJmhzContentCorrectionForm,
): boolean {
  if (error.id_ppv) return error.id_ppv === component.employment_external_identifier
  if (error.ik_mpsv) return error.ik_mpsv === component.person_external_identifier
  return false
}

/**
 * Které pracovní vztahy protokol vytkl.
 *
 * Dva zdroje schválně. `protocol_error_count` chodí ze serveru z uložených
 * výsledků formulářů a funguje pro každý protokol, tedy i pro hlášení odeslané
 * datovkou, kde žádný dotazovací pokus neexistuje a dřív tu proto nesvítilo
 * nic. Report z právě provedeného doptání na VREP se přidává navrch, aby se
 * označení objevilo hned po dotazu, ještě než se seznam znovu načte.
 */
const protocolErrorComponentGuids = computed(() => {
  const matched = new Set<string>()
  const group = correctionGroup.value

  for (const component of correctableComponents.value) {
    if ((component.protocol_error_count ?? 0) > 0) {
      matched.add(component.employment_external_identifier)
    }
  }
  if (!group) return matched

  const errors = group.attempts.flatMap(attempt => polls.value[attempt.id]?.report?.errors ?? [])
  for (const component of correctableComponents.value) {
    if (errors.some(error => protocolErrorMatchesComponent(error, component))) {
      matched.add(component.employment_external_identifier)
    }
  }
  return matched
})

const visibleCorrectionComponents = computed(() => {
  const query = correctionQuery.value.trim().toLocaleLowerCase()
  const rows = query === ''
    ? correctableComponents.value
    : correctableComponents.value.filter(component => [
      component.employee_name ?? '',
      component.employment_external_identifier,
      component.person_external_identifier,
    ].some(value => value.toLocaleLowerCase().includes(query)))

  return [...rows].sort((left, right) => {
    const leftHasError = protocolErrorComponentGuids.value.has(left.employment_external_identifier) ? 0 : 1
    const rightHasError = protocolErrorComponentGuids.value.has(right.employment_external_identifier) ? 0 : 1
    if (leftHasError !== rightHasError) return leftHasError - rightHasError
    return (left.employee_name ?? left.employment_external_identifier).localeCompare(
      right.employee_name ?? right.employment_external_identifier,
      'cs',
      { numeric: true },
    )
  })
})

const correctionPreparationOptions = computed(() => correctionPreparations.value.map(preparation => ({
  value: preparation.id,
  label: t('payroll.submissions.transport.correction.preparation_option', {
    revision: preparation.revision_no,
    created: formatDateTime(preparation.created_at),
  }),
  secondary: t('payroll.submissions.transport.correction.preparation_period', {
    period: formatPeriod(preparation.period_start.slice(0, 7)),
  }),
})))

watch(selectedCorrectionGuids, () => {
  correctionImpactConfirmed.value = false
}, { deep: true })

/** Proč podání přestalo být platným stavem období. */
interface Replacement {
  by: number
  reason: 'corrected' | 'cancelled' | 'resubmitted'
}

type TimelineZone = 'current' | 'history'

type TimelineEntry =
  | { source: 'period'; key: string; periodKey: string; label: string }
  | { source: 'history-toggle'; key: string; periodKey: string; count: number }
  | {
    source: 'app'
    key: string
    periodKey: string
    zone: TimelineZone
    group: AttemptGroup
    replacement: Replacement | null
  }
  | {
    source: 'imported'
    key: string
    periodKey: string
    zone: TimelineZone
    protocol: PayrollJmhzImportedProtocol
    /** Podání, ke kterému protokol patří; `null` = nespárovaný doklad. */
    attachedTo: number | null
  }

const RESULT_STATUSES = ['accepted', 'partially_accepted']

function protocolPeriodKey(protocol: PayrollJmhzImportedProtocol): string {
  return protocol.period_year && protocol.period_month
    ? `${protocol.period_year}-${String(protocol.period_month).padStart(2, '0')}`
    : ''
}

function groupPeriodKey(group: AttemptGroup): string {
  return group.periodStart ? group.periodStart.slice(0, 7) : ''
}

function chainRoot(group: AttemptGroup): number {
  return group.correctsSubmissionId ?? group.submissionId
}

/**
 * Které podání období už neplatí a čím bylo nahrazené.
 *
 * Opravné nebo stornovací podání se váže na řádné (`corrects_submission_id`),
 * takže řetězec drží kořen. Nahrazuje jen podání, které ČSSZ PŘIJALA: odmítnutá
 * oprava na platnosti původního hlášení nic nemění. Odmítnuté řádné hlášení
 * nahrazuje pozdější přijaté podání za totéž období (nový GUID, jiný řetězec).
 */
function replacementsFor(periodGroups: AttemptGroup[]): Map<number, Replacement> {
  const result = new Map<number, Replacement>()
  for (const group of periodGroups) {
    const later = periodGroups
      .filter(other => other.submissionId > group.submissionId
        && RESULT_STATUSES.includes(other.submissionStatus ?? ''))
      .sort((a, b) => b.submissionId - a.submissionId)
    const inChain = later.find(other => other.submissionKind !== 'regular'
      && chainRoot(other) === chainRoot(group))
    if (inChain) {
      result.set(group.submissionId, {
        by: inChain.submissionId,
        reason: inChain.submissionKind === 'cancellation' ? 'cancelled' : 'corrected',
      })
      continue
    }
    if (group.submissionStatus === 'rejected' && later.length > 0) {
      result.set(group.submissionId, { by: later[0]!.submissionId, reason: 'resubmitted' })
    }
  }
  return result
}

const PROTOCOL_SUBMISSION_STATUS: Record<string, string> = {
  ProcessedAndComplete: 'accepted',
  ContainsPassableErrors: 'partially_accepted',
  PartiallyAccepted: 'partially_accepted',
  Rejected: 'rejected',
  NotAccepted: 'rejected',
}

function sentDates(group: AttemptGroup): string[] {
  const values = group.attempts.map(attempt => attempt.sent_at)
  values.push(group.dispatched?.outbox_sent_at ?? null)
  return values.filter((value): value is string => typeof value === 'string' && value !== '')
}

/**
 * K jakému podání načtený protokol patří.
 *
 * Protokol nenese číslo našeho podání a `idPodani` je společné celému řetězci
 * řádného, opravného i stornovacího podání. Páruje se proto postupně podle
 * spisové značky (CorrelationID), dne podání, výsledku a nakonec podle toho,
 * že je v období jediné podání. Co nejde určit jednoznačně, zůstane
 * samostatným dokladem: přiřadit protokol špatnému podání by byla horší lež
 * než ho ukázat zvlášť.
 */
function protocolOwner(
  protocol: PayrollJmhzImportedProtocol,
  candidates: AttemptGroup[],
): number | null {
  if (candidates.length === 0) return null
  const reference = protocol.correlation_reference ?? ''
  if (reference !== '') {
    const byReference = candidates.filter(group =>
      group.attempts.some(attempt => attempt.correlation_reference === reference)
      || group.dispatched?.outbox_correlation_reference === reference)
    if (byReference.length === 1) return byReference[0]!.submissionId
  }
  const submittedDay = (protocol.submitted_at ?? '').slice(0, 10)
  if (submittedDay !== '') {
    const byDay = candidates.filter(group =>
      sentDates(group).some(value => value.slice(0, 10) === submittedDay))
    if (byDay.length === 1) return byDay[0]!.submissionId
  }
  const status = PROTOCOL_SUBMISSION_STATUS[protocol.status_name]
  if (status) {
    const byStatus = candidates.filter(group => group.submissionStatus === status)
    if (byStatus.length === 1) return byStatus[0]!.submissionId
  }
  return candidates.length === 1 ? candidates[0]!.submissionId : null
}

function periodHeading(periodKey: string): string {
  if (periodKey === '') return t('payroll.submissions.transport.imported.period_unknown')
  const [year, month] = periodKey.split('-')
  return t('payroll.submissions.transport.imported.period', {
    month: Number(month),
    year: Number(year),
  })
}

const historyOpen = ref<Record<string, boolean>>({})

function toggleHistory(periodKey: string) {
  historyOpen.value = { ...historyOpen.value, [periodKey]: !historyOpen.value[periodKey] }
}

/** Podání nahrazená opravným nebo stornovacím podáním, klíčovaná číslem. */
const replacements = computed(() => {
  const byPeriod = new Map<string, AttemptGroup[]>()
  for (const group of groups.value) {
    const key = groupPeriodKey(group)
    byPeriod.set(key, [...(byPeriod.get(key) ?? []), group])
  }
  const all = new Map<number, Replacement>()
  for (const periodGroups of byPeriod.values()) {
    for (const [id, replacement] of replacementsFor(periodGroups)) all.set(id, replacement)
  }
  return all
})

/**
 * Přehled „co jsem podal" seskupený podle OBDOBÍ hlášení, od nejnovějšího.
 *
 * Uživatel hledá „srpen", ne „to, co jsem načetl naposled". V rámci období
 * stojí nahoře platný stav (poslední přijaté podání), pod ním sbalená historie
 * nahrazených podání. Načtený protokol se připojí ke svému podání; samostatně
 * stojí jen ten, který spárovat nejde. Období, které se nepodařilo zjistit,
 * jde na konec.
 */
const timeline = computed<TimelineEntry[]>(() => {
  const periods = new Map<string, { groups: AttemptGroup[]; protocols: PayrollJmhzImportedProtocol[] }>()
  const bucket = (key: string) => {
    let value = periods.get(key)
    if (!value) {
      value = { groups: [], protocols: [] }
      periods.set(key, value)
    }
    return value
  }
  for (const group of groups.value) bucket(groupPeriodKey(group)).groups.push(group)
  for (const protocol of imported.value) bucket(protocolPeriodKey(protocol)).protocols.push(protocol)

  const keys = [...periods.keys()].sort((a, b) => {
    if (a === b) return 0
    if (a === '') return 1
    if (b === '') return -1
    return a < b ? 1 : -1
  })

  const entries: TimelineEntry[] = []
  for (const periodKey of keys) {
    const period = periods.get(periodKey)!
    const ordered = [...period.groups].sort((a, b) => b.submissionId - a.submissionId)
    const attached = new Map<number, PayrollJmhzImportedProtocol[]>()
    const orphans: PayrollJmhzImportedProtocol[] = []
    for (const protocol of period.protocols) {
      const owner = periodKey === '' ? null : protocolOwner(protocol, ordered)
      if (owner === null) orphans.push(protocol)
      else attached.set(owner, [...(attached.get(owner) ?? []), protocol])
    }

    entries.push({ source: 'period', key: `period-${periodKey}`, periodKey, label: periodHeading(periodKey) })
    const push = (group: AttemptGroup, zone: TimelineZone) => {
      entries.push({
        source: 'app',
        key: `app-${group.submissionId}`,
        periodKey,
        zone,
        group,
        replacement: replacements.value.get(group.submissionId) ?? null,
      })
      for (const protocol of attached.get(group.submissionId) ?? []) {
        entries.push({
          source: 'imported',
          key: `imported-${protocol.id}`,
          periodKey,
          zone,
          protocol,
          attachedTo: group.submissionId,
        })
      }
    }
    const current = ordered.filter(group => !replacements.value.has(group.submissionId))
    const history = ordered.filter(group => replacements.value.has(group.submissionId))
    for (const group of current) push(group, 'current')
    for (const protocol of orphans) {
      entries.push({
        source: 'imported',
        key: `imported-${protocol.id}`,
        periodKey,
        zone: 'current',
        protocol,
        attachedTo: null,
      })
    }
    if (history.length > 0) {
      const count = history.reduce(
        (total, group) => total + 1 + (attached.get(group.submissionId)?.length ?? 0),
        0,
      )
      entries.push({ source: 'history-toggle', key: `history-${periodKey}`, periodKey, count })
      for (const group of history) push(group, 'history')
    }
  }
  return entries
})

const visibleTimeline = computed(() => timeline.value.filter(entry =>
  !('zone' in entry) || entry.zone === 'current' || historyOpen.value[entry.periodKey] === true))

/** Výsledek podání čitelný bez rozklikávání: z protokolu, ne z přenosu. */
function resultKey(group: AttemptGroup): 'accepted' | 'partially_accepted' | 'rejected' | 'pending' {
  const status = group.submissionStatus ?? ''
  if (status === 'accepted' || status === 'partially_accepted' || status === 'rejected') return status
  return 'pending'
}

const RESULT_TONES: Record<string, string> = {
  accepted: 'bg-success-100 text-success-700',
  partially_accepted: 'bg-warning-100 text-warning-800',
  rejected: 'bg-danger-100 text-danger-700',
  pending: 'bg-payroll-100 text-payroll-800',
}

function replacementLabel(replacement: Replacement): string {
  return t(`payroll.submissions.transport.history.replaced_${replacement.reason}`, { id: replacement.by })
}

function importedPeriodLabel(protocol: PayrollJmhzImportedProtocol): string {
  if (!protocol.period_year || !protocol.period_month) {
    return t('payroll.submissions.transport.imported.period_unknown')
  }
  return t('payroll.submissions.transport.imported.period', {
    month: protocol.period_month,
    year: protocol.period_year,
  })
}

const STATUS_TONES: Record<PayrollJmhzTransportStatus, string> = {
  prepared: 'bg-neutral-100 text-neutral-700',
  sent: 'bg-payroll-100 text-payroll-800',
  // Převzato, ale nerozhodnuto — proto výstražná, ne zelená.
  awaiting_protocol: 'bg-warning-100 text-warning-800',
  completed: 'bg-success-100 text-success-700',
  failed: 'bg-danger-100 text-danger-700',
  expired: 'bg-danger-100 text-danger-700',
  // Nevíme, jestli ČSSZ zprávu má; rozhoduje člověk, proto výstražná.
  possibly_delivered: 'bg-warning-100 text-warning-800',
}

function statusTone(status: PayrollJmhzTransportStatus): string {
  return STATUS_TONES[status] ?? 'bg-neutral-100 text-neutral-700'
}

/**
 * Barva stavu z protokolu. „Částečně přijato" a „obsahuje propustné chyby"
 * jsou výstražné, ne zelené: hlášení sice prošlo, ale něco v něm zůstalo
 * nedořešené a zelená by to zavřela jako hotové.
 */
const PROTOCOL_TONES: Record<string, string> = {
  ProcessedAndComplete: 'bg-success-100 text-success-700',
  ContainsPassableErrors: 'bg-warning-100 text-warning-800',
  PartiallyAccepted: 'bg-warning-100 text-warning-800',
  Processing: 'bg-payroll-100 text-payroll-800',
  Rejected: 'bg-danger-100 text-danger-700',
  NotAccepted: 'bg-danger-100 text-danger-700',
}

function protocolTone(status: string): string {
  return PROTOCOL_TONES[status] ?? 'bg-neutral-100 text-neutral-700'
}

/** Doptat se jde jen tam, kde brána přidělila CorrelationID. */
function hasCorrelation(attempt: PayrollJmhzTransportAttempt): boolean {
  return (attempt.correlation_reference ?? '') !== ''
}

/**
 * Uzavřený pokus (protokol dotažen, nebo ho automatika vzdala) výsledek už
 * má a ledger ho znovu neotevře; dotaz by skončil jen chybou.
 */
function canPoll(attempt: PayrollJmhzTransportAttempt): boolean {
  return hasCorrelation(attempt) && !['completed', 'expired'].includes(attempt.status)
}

/**
 * Smazat jde jen pokus, který úřad nikdy nepřevzal. Rozhoduje server stejným
 * pravidlem, jakým smazání sám hlídá; bez jeho odpovědi se nenabízí.
 */
function canDelete(attempt: PayrollJmhzTransportAttempt): boolean {
  return canWrite.value && attempt.can_delete === true
}

/**
 * Uzavřít se smí až po dotažení protokolu. Dřív by se výsledek ztratil, a to
 * je nevratné — proto se tlačítko u ostatních stavů vůbec nenabízí.
 *
 * Uzavřenou transakci nabízet znovu nemá smysl: automatika ji uzavírá sama a
 * druhé uzavření by u ČSSZ byl dotaz na transakci, která už neexistuje.
 */
function canClose(attempt: PayrollJmhzTransportAttempt): boolean {
  return attempt.status === 'completed' && hasCorrelation(attempt) && !attempt.closed_at
}

/**
 * Protokol dotažený pokusem leží v evidenci neověřený (podpis se při dotažení
 * neověřil) a ověřený dvojník zatím neexistuje. Server ho ověří stejnou cestou
 * jako čerstvě dotažený; stav podání se hne jen při úspěchu.
 */
function canReverify(attempt: PayrollJmhzTransportAttempt): boolean {
  return (attempt.unverified_receipt_id ?? null) !== null
}

function reverifyMessage(result: PayrollJmhzProtocolReverification): string {
  const status = t(`payroll.submissions.overview.status.${result.submission_status}`)
  if (result.outcome === 'verified') {
    return t('payroll.submissions.transport.reverify.verified', { status })
  }
  if (result.outcome === 'already_verified') {
    return t('payroll.submissions.transport.reverify.already', { status })
  }
  return t('payroll.submissions.transport.reverify.failed', { message: result.message ?? '' })
}

/**
 * Stornovat lze jen hlášení, které DOLOŽITELNĚ odešlo. Podání, které nikdy
 * neopustilo aplikaci, u ČSSZ neexistuje a rušit se u něj nemá co.
 */
function canCancel(group: AttemptGroup): boolean {
  const target = actionTargetGroup(group)
  return canWrite.value
    // Nahrazené (opravené nebo stornované) podání už není platný stav období.
    && !replacements.value.has(group.submissionId)
    && target !== null
    && RESULT_STATUSES.includes(group.submissionStatus ?? '')
    && RESULT_STATUSES.includes(target.submissionStatus ?? '')
    // Odeslání dokládá buď pokus VREP, nebo odchozí zpráva datové schránky.
    // Hlášení poslané datovkou žádný pokus nemá a stornovat ho jde stejně.
    && (target.attempts.some(attempt => attempt.sent_at !== null)
      || target.dispatched !== null)
}

/**
 * Oprava i storno se na serveru vážou vždy na ŘÁDNÉ podání (kořen řetězce).
 * Platný stav období ale ukazuje karta posledního přijatého opravného podání,
 * takže akce nabízí ona a míří na své řádné podání.
 */
function actionTargetGroup(group: AttemptGroup): AttemptGroup | null {
  if (group.submissionKind === 'regular') return group
  if (group.submissionKind !== 'correction' || group.correctsSubmissionId === null) return null
  const root = groups.value.find(candidate => candidate.submissionId === group.correctsSubmissionId)
  return root && root.submissionKind === 'regular' ? root : null
}

function actionTarget(group: AttemptGroup): number {
  return actionTargetGroup(group)?.submissionId ?? group.submissionId
}

/** Formulář opravy a storna patří pod platnou kartu, ne pod nahrazenou v historii. */
function canCorrectFormHost(entry: { replacement: Replacement | null }): boolean {
  return entry.replacement === null
}

/**
 * Druh O smí navázat až na konečný protokol. Samotné převzetí zprávy branou
 * nic neříká o tom, které součásti ČSSZ přijala, a výběr před výsledkem by byl
 * jen odhad. Definitivní způsobilost ještě ověří server nad stavem podání.
 *
 * Konečný protokol pozná stav PODÁNÍ, ne uzavřený pokus o odeslání. Dřív se tu
 * čekalo na `attempt.status === 'completed'`, jenže to platí jen pro VREP —
 * u hlášení odeslaného datovou schránkou protokol přijde do schránky a žádný
 * dotazovací pokus se nikdy neuzavře. Podání za 08/2026 tak bylo „částečně
 * přijato", nabízelo storno (to na pokus nečeká), a opravu ne. Stav
 * `accepted`/`partially_accepted` přitom server zapíše výhradně z ověřeného
 * protokolu, takže je to silnější podmínka než uzavřený pokus.
 */
function canCorrect(group: AttemptGroup): boolean {
  const target = actionTargetGroup(group)
  if (
    !canWrite.value
    || replacements.value.has(group.submissionId)
    || target === null
    || !RESULT_STATUSES.includes(group.submissionStatus ?? '')
    || !RESULT_STATUSES.includes(target.submissionStatus ?? '')
  ) {
    return false
  }
  const latestKnownReport = group.attempts
    .map(attempt => polls.value[attempt.id]?.report)
    .find(report => report !== null && report !== undefined)
  if (!latestKnownReport) return true

  return [
    'ProcessedAndComplete',
    'ContainsPassableErrors',
    'PartiallyAccepted',
  ].includes(latestKnownReport.status)
}

/**
 * Období podání je to, co uživatel hledá jako první („co jsem poslal za
 * červenec"). Nese ho rovnou ledger, takže se na něj nikde nedoptáváme; když
 * u pokusu chybí (podání už v evidenci není), zůstane jen odkaz na podání —
 * chybějící období není důvod neukázat stavy.
 */
function periodLabel(group: AttemptGroup): string {
  if (!group.periodStart || !group.periodEnd) {
    return t('payroll.submissions.transport.group.period_unknown')
  }
  return t('payroll.submissions.transport.group.period', {
    start: formatDate(group.periodStart),
    end: formatDate(group.periodEnd),
  })
}

function errorLocation(error: PayrollJmhzProtocolError): string[] {
  const parts: string[] = []
  if (error.ik_mpsv) {
    parts.push(t('payroll.submissions.transport.report.ik_mpsv', { value: error.ik_mpsv }))
  }
  if (error.id_ppv) {
    parts.push(t('payroll.submissions.transport.report.id_ppv', { value: error.id_ppv }))
  }
  if (error.form_guid) {
    parts.push(t('payroll.submissions.transport.report.form_guid', { value: error.form_guid }))
  }
  return parts
}

async function copyCorrelation(attempt: PayrollJmhzTransportAttempt) {
  const value = attempt.correlation_reference
  if (!value) return
  copyFailedId.value = null
  try {
    await navigator.clipboard.writeText(value)
    copiedId.value = attempt.id
  } catch {
    // Schránka může být zakázaná politikou prohlížeče. Tlačítko, po kterém se
    // mlčky nic nestane, vypadá jako rozbitá aplikace — a uživatel pak vloží
    // do dotazu na ČSSZ to, co měl ve schránce předtím. Proto se řekne nahlas,
    // že se nezkopírovalo a že jde text označit ručně.
    copiedId.value = null
    copyFailedId.value = attempt.id
  }
}

/**
 * Nastavení zaměstnavatele zná variabilní symboly pracovišť — jinak se ptáme.
 *
 * Nabídka i předvyplnění se řídí ZVOLENÝM prostředím: produkce a test mají u
 * ČSSZ oddělené symboly a přepnutí prostředí bez přepnutí nabídky by tiše
 * nabízelo ostrý VS v testu (a naopak).
 */
async function loadVariableSymbols() {
  try {
    const settings = await payrollApi.employerSettings()
    const production = new Map<string, string>()
    const test = new Map<string, string>()
    for (const office of settings.offices ?? []) {
      if (!office.is_active) continue
      const label = `${office.code} - ${office.name}`
      const productionSymbol = (office.social_security_variable_symbol ?? '').trim()
      if (productionSymbol !== '' && !production.has(productionSymbol)) {
        production.set(productionSymbol, label)
      }
      const testSymbol = (office.test_social_security_variable_symbol ?? '').trim()
      if (testSymbol !== '' && !test.has(testSymbol)) {
        test.set(testSymbol, label)
      }
    }
    knownProductionVariableSymbols.value = new Set(production.keys())
    knownTestVariableSymbols.value = new Set(test.keys())
    const relevant = requestEnvironment.value === 'test' ? test : production
    variableSymbolOptions.value = [...relevant].map(([value, label]) => ({ value, label }))
    // Předvyplní se jen jednoznačný případ. Víc různých symbolů znamená volbu,
    // a hádat ji za uživatele by znamenalo ptát se ČSSZ pod cizím symbolem.
    // Dokud uživatel do pole nesáhl, drží se to i po přepnutí prostředí —
    // jinak by po přechodu produkce → test zůstal v poli tiše ostrý VS.
    if (!variableSymbolTouched.value && variableSymbolOptions.value.length === 1) {
      variableSymbol.value = variableSymbolOptions.value[0]!.value
    }
  } catch {
    // Nabídka je pohodlí, ne podmínka — pole na symbol zůstane k vyplnění ručně.
    variableSymbolOptions.value = []
    knownProductionVariableSymbols.value = new Set()
    knownTestVariableSymbols.value = new Set()
  }
}

function useVariableSymbol(value: string) {
  variableSymbol.value = value
  variableSymbolTouched.value = true
}

/*
 * Pořadové číslo posledního načtení. Odpověď na starší dotaz (typicky za
 * prostředí, ze kterého se mezitím odešlo) se zahodí: jinak by pozdě došlá
 * chyba přepsala už načtený seznam a obrazovka by tvrdila, že stav neznáme.
 */
let loadSequence = 0

async function load() {
  const sequence = ++loadSequence
  const requestedEnvironment = requestEnvironment.value
  loading.value = true
  loadError.value = ''
  actionError.value = ''
  success.value = ''
  // Zapamatovaný rozpad chyb platí pro protokoly, které právě mizí z obrazovky
  // — po znovunačtení (jiná stránka, nový import) by mohl patřit něčemu jinému.
  resetProtocolErrors()
  try {
    // Obě strany přehledu se načítají naráz a SELHÁNÍ KTERÉKOLI Z NICH je
    // selhání celku. Ukázat jen jednu polovinu a druhou tiše vynechat by
    // znamenalo přehled, který zamlčuje podání — a přesně kvůli tomu se sem
    // uživatel dívá.
    const [history, protocols] = await Promise.all([
      payrollApi.jmhzTransportHistory(
        requestedEnvironment,
        { limit: attemptsPageSize, offset: attemptsOffset.value },
        { year: filterYear.value, month: filterMonth.value },
      ),
      payrollApi.jmhzImportedProtocols(
        requestedEnvironment,
        { limit: importedPageSize, offset: importedOffset.value },
        { year: filterYear.value, month: filterMonth.value },
      ),
    ])
    if (sequence !== loadSequence) return
    attempts.value = history.attempts ?? []
    readySubmissions.value = history.ready_submissions ?? []
    dispatchedSubmissions.value = history.dispatched_submissions ?? []
    manualAcceptances.value = Object.fromEntries(
      (history.manual_acceptances ?? []).map(entry => [entry.submission_id, entry]),
    )
    attemptsTotal.value = history.total ?? 0
    filterYears.value = history.years ?? []
    imported.value = protocols.protocols ?? []
    importedTotal.value = protocols.total ?? 0
  } catch (exception: unknown) {
    if (sequence !== loadSequence) return
    // Odmítnutý test (server je mimo vývoj, i když klient měl starý stav) není
    // selhání načtení, jen dotaz do prostředí, které tu neexistuje. Platí
    // produkce, takže se načte ta.
    // Seznam načte znovu sledování prostředí níže.
    if (requestedEnvironment === 'test' && isTestEnvironmentRejection(exception)) {
      environment.value = 'production'
      return
    }
    // Stav zůstává NEZNÁMÝ, ne prázdný — šablona podle `loadError` skryje
    // prázdný stav i seznam, aby se selhání nedalo přečíst jako „nic neodešlo".
    attempts.value = []
    readySubmissions.value = []
    dispatchedSubmissions.value = []
    manualAcceptances.value = {}
    attemptsTotal.value = 0
    imported.value = []
    importedTotal.value = 0
    loadError.value = apiErrorMessage(
      exception,
      t('payroll.submissions.transport.load_failed'),
    )
  } finally {
    if (sequence === loadSequence) loading.value = false
  }
  if (sequence !== loadSequence) return
  // Roky zná až odpověď serveru, takže předvolba přijde po prvním načtení
  // a seznam se pro ni musí načíst znovu. Vlajka uvnitř hlídá, že se to
  // stane právě jednou — jinak by to bylo nekonečné kolo.
  if (ensureDefaultYear()) {
    await load()
  }
}

function pickProtocolFile() {
  if (busy.value || !canWrite.value) return
  fileInput.value?.click()
}

/**
 * Načtení protokolu z datové schránky.
 *
 * Vstup se po každém pokusu čistí, aby šel tentýž soubor načíst znovu — po
 * neúspěchu je druhý pokus s týmž souborem to první, co člověk zkusí.
 */
async function importProtocol(event: Event) {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]
  input.value = ''
  if (!file || importing.value) return
  importing.value = true
  actionError.value = ''
  success.value = ''
  try {
    const result = await payrollApi.importJmhzProtocol(file, requestEnvironment.value)
    await load()
    success.value = result.created
      ? t('payroll.submissions.transport.imported.added', {
        status: t(`payroll.submissions.transport.protocol_status.${result.protocol.status_name}`),
      })
      : t('payroll.submissions.transport.imported.replaced')
  } catch (exception: unknown) {
    actionError.value = apiErrorMessage(
      exception,
      t('payroll.submissions.transport.imported.failed'),
    )
  } finally {
    importing.value = false
  }
}

async function switchEnvironment(next: PayrollJmhzTransportEnvironment) {
  if (next === environment.value || busy.value) return
  cancellingId.value = null
  closeCorrection()
  environment.value = next
  polls.value = {}
  reverifications.value = {}
  readyIsdsResults.value = {}
  readyGateways.value = {}
  // Jiné prostředí = jiné seznamy, takže stránky musí zpět na začátek.
  attemptsOffset.value = 0
  importedOffset.value = 0
  await load()
}

/**
 * Období nese jen přehled, ne odpověď na doptání — ta vrací holý řádek ledgeru.
 * Převezme se proto z nahrazovaného pokusu, jinak by hlavička skupiny po
 * doptání spadla na „období neznámé", aniž by se cokoli stalo.
 */
function replaceAttempt(updated: PayrollJmhzTransportAttempt) {
  attempts.value = attempts.value.map(
    attempt => (attempt.id === updated.id
      ? {
        ...updated,
        period_start: updated.period_start ?? attempt.period_start,
        period_end: updated.period_end ?? attempt.period_end,
        submission_kind: updated.submission_kind ?? attempt.submission_kind,
        submission_status: updated.submission_status ?? attempt.submission_status,
        corrects_submission_id:
          updated.corrects_submission_id ?? attempt.corrects_submission_id,
        unverified_receipt_id:
          updated.unverified_receipt_id ?? attempt.unverified_receipt_id,
      }
      : attempt),
  )
}

async function poll(attempt: PayrollJmhzTransportAttempt) {
  if (!variableSymbolValid.value || busy.value) return
  pollingId.value = attempt.id
  actionError.value = ''
  success.value = ''
  try {
    const result = await payrollApi.pollJmhzTransportAttempt(
      attempt.id,
      variableSymbol.value.trim(),
      requestEnvironment.value,
    )
    polls.value = { ...polls.value, [attempt.id]: result }
    if (result.attempt) replaceAttempt(result.attempt)
  } catch (exception: unknown) {
    actionError.value = apiErrorMessage(
      exception,
      t('payroll.submissions.transport.poll_failed'),
    )
  } finally {
    pollingId.value = null
  }
}

/**
 * Trvale smaže pokus z historie.
 *
 * Ledger pokusů je jinak append-only a běžná cesta ven je zahození, po kterém
 * řádek zůstane i s odpovědí úřadu. Tohle je pro pokus, který NIC nedokládá —
 * server pustí jen ten bez dodejky a bez protokolu; jinak odpoví 409. Ptáme se
 * proto jednou navíc: po smazání po řádku nezůstane nic než auditní zápis.
 */
const deletingId = ref<number | null>(null)

async function deleteAttempt(attempt: PayrollJmhzTransportAttempt) {
  if (!canWrite.value || busy.value) return
  if (!window.confirm(t('payroll.submissions.transport.delete_confirm', {
    no: attempt.attempt_no,
  }))) return

  deletingId.value = attempt.id
  actionError.value = ''
  success.value = ''
  try {
    await payrollApi.deleteJmhzTransportAttempt(
      attempt.id,
      attempt.row_version,
      requestEnvironment.value,
    )
    success.value = t('payroll.submissions.transport.delete_done', { no: attempt.attempt_no })
    await load()
  } catch (exception: unknown) {
    actionError.value = apiErrorMessage(
      exception,
      t('payroll.submissions.transport.delete_failed'),
    )
  } finally {
    deletingId.value = null
  }
}

/*
 * Storno vybraných vztahů. Celé podání se ruší zřídka; častější je, že do
 * přijatého hlášení omylem padl vztah, který tam nepatří (duplicitní nebo
 * ukončený). Opravné hlášení pak nese jen stornující formuláře vybraných
 * vztahů, ostatní formuláře zůstávají u ČSSZ platné.
 */
const cancelMode = ref<'whole' | 'components'>('whole')
const cancelComponents = ref<(PayrollJmhzCorrectableComponent & { employee_name?: string | null })[]>([])
const cancelComponentsLoading = ref(false)
const cancelComponentsError = ref('')
const selectedCancelGuids = ref<string[]>([])
const cancelComponentsConfirmed = ref(false)

async function chooseCancelMode(submissionId: number, mode: 'whole' | 'components') {
  cancelMode.value = mode
  selectedCancelGuids.value = []
  cancelComponentsConfirmed.value = false
  cancelComponentsError.value = ''
  if (mode !== 'components' || cancelComponents.value.length > 0) return
  cancelComponentsLoading.value = true
  try {
    const result = await payrollApi.jmhzCorrectableComponents(submissionId, requestEnvironment.value)
    if (cancellingId.value !== submissionId) return
    cancelComponents.value = result.components
  } catch (exception: unknown) {
    cancelComponentsError.value = apiErrorMessage(
      exception,
      t('payroll.jmhz_gate.cancel_components.load_failed'),
    )
  } finally {
    cancelComponentsLoading.value = false
  }
}

async function confirmCancelComponents(submissionId: number) {
  if (!canWrite.value || busy.value || selectedCancelGuids.value.length === 0
    || !cancelComponentsConfirmed.value
  ) return
  cancelPendingId.value = submissionId
  actionError.value = ''
  success.value = ''
  try {
    const result = await payrollApi.cancelJmhzSubmissionComponents(
      submissionId,
      requestEnvironment.value,
      [...new Set(selectedCancelGuids.value)],
    )
    cancellingId.value = null
    await load()
    success.value = result.created
      ? t('payroll.jmhz_gate.cancel_components.frozen', { id: result.submission_id })
      : t('payroll.jmhz_gate.cancel_components.already', { id: result.submission_id })
  } catch (exception: unknown) {
    actionError.value = apiErrorMessage(
      exception,
      t('payroll.jmhz_gate.cancel_components.failed'),
    )
  } finally {
    cancelPendingId.value = null
  }
}

function askToCancel(submissionId: number) {
  cancelMode.value = 'whole'
  cancelComponents.value = []
  selectedCancelGuids.value = []
  cancelComponentsConfirmed.value = false
  cancelComponentsError.value = ''
  if (busy.value) return
  closeCorrection()
  cancellingId.value = submissionId
  actionError.value = ''
  success.value = ''
}

async function askToCorrect(submissionId: number) {
  if (!canWrite.value || busy.value) return
  cancellingId.value = null
  correctingId.value = submissionId
  correctableComponents.value = []
  correctionPreparations.value = []
  selectedCorrectionGuids.value = []
  correctionPreparationId.value = null
  correctionCandidatesLoaded.value = false
  correctionQuery.value = ''
  correctionImpactConfirmed.value = false
  actionError.value = ''
  success.value = ''
  correctionPreparationLoadingId.value = submissionId
  let autoSelected: number | null = null
  try {
    const result = await payrollApi.jmhzContentCorrectionPreparations(
      submissionId,
      requestEnvironment.value,
    )
    if (correctingId.value !== submissionId) return
    correctionPreparations.value = result.preparations
    correctionPreparationId.value = result.auto_selected_preparation_id
    autoSelected = result.auto_selected_preparation_id
  } catch (exception: unknown) {
    correctingId.value = null
    actionError.value = apiErrorMessage(
      exception,
      t('payroll.submissions.transport.correction.preparation_load_failed'),
    )
  } finally {
    correctionPreparationLoadingId.value = null
  }
  if (autoSelected !== null && correctingId.value === submissionId) {
    await loadContentCorrectionCandidates(submissionId)
  }
}

async function loadContentCorrectionCandidates(submissionId: number) {
  const preparationId = correctionPreparationId.value
  if (preparationId === null || !Number.isInteger(preparationId) || preparationId <= 0 || busy.value) return
  correctionLoadingId.value = submissionId
  correctionCandidatesLoaded.value = false
  try {
    const result = await payrollApi.jmhzContentCorrectionCandidates(
      submissionId,
      preparationId,
      requestEnvironment.value,
    )
    correctableComponents.value = result.forms
    correctionBlocked.value = result.blocked_forms ?? []
    // Předvybrané jsou jen formuláře, které se proti přijatému podání změnily.
    selectedCorrectionGuids.value = result.forms
      .filter(component => component.changed === true)
      .map(component => component.employment_external_identifier)
    correctionCandidatesLoaded.value = true
  } catch (exception: unknown) {
    correctingId.value = null
    actionError.value = apiErrorMessage(
      exception,
      t('payroll.submissions.transport.correction.load_failed'),
    )
  } finally {
    correctionLoadingId.value = null
  }
}

function closeCorrection() {
  correctingId.value = null
  correctableComponents.value = []
  correctionBlocked.value = []
  correctionPreparations.value = []
  selectedCorrectionGuids.value = []
  correctionQuery.value = ''
  correctionImpactConfirmed.value = false
  correctionPreparationId.value = null
  correctionCandidatesLoaded.value = false
}

function selectProtocolErrors() {
  selectedCorrectionGuids.value = [...protocolErrorComponentGuids.value]
}

async function confirmCorrection(submissionId: number) {
  if (
    !canWrite.value
    || busy.value
    || selectedCorrectionGuids.value.length === 0
    || !correctionImpactConfirmed.value
  ) return
  const employmentIdentifiers = [...new Set(selectedCorrectionGuids.value)]
  const preparationId = correctionPreparationId.value
  if (employmentIdentifiers.length === 0 || preparationId === null
    || !Number.isInteger(preparationId) || preparationId <= 0
  ) return
  correctionPendingId.value = submissionId
  actionError.value = ''
  success.value = ''
  try {
    const result = await payrollApi.freezeJmhzContentCorrection(
      submissionId,
      preparationId,
      requestEnvironment.value,
      employmentIdentifiers,
    )
    closeCorrection()
    await load()
    success.value = result.created
      ? t('payroll.submissions.transport.correction.frozen', { id: result.submission_id })
      : t('payroll.submissions.transport.correction.already', { id: result.submission_id })
  } catch (exception: unknown) {
    actionError.value = apiErrorMessage(
      exception,
      t('payroll.submissions.transport.correction.failed'),
    )
  } finally {
    correctionPendingId.value = null
  }
}

/**
 * Storno se jen PŘIPRAVÍ. Odesílá se pak stejnou cestou jako řádné hlášení —
 * sloučit obojí do jednoho kliknutí by znamenalo, že se při chybě odeslání
 * nedá poznat, jestli storno vzniklo, a druhý pokus by ho založil znovu.
 */
async function confirmCancel(submissionId: number) {
  if (!canWrite.value || busy.value) return
  cancelPendingId.value = submissionId
  actionError.value = ''
  success.value = ''
  try {
    const result = await payrollApi.cancelJmhzSubmission(submissionId, requestEnvironment.value)
    cancellingId.value = null
    await load()
    success.value = result.created
      ? t('payroll.submissions.transport.storno.frozen', { id: result.submission_id })
      : t('payroll.submissions.transport.storno.already', { id: result.submission_id })
  } catch (exception: unknown) {
    actionError.value = apiErrorMessage(
      exception,
      t('payroll.submissions.transport.storno.failed'),
    )
  } finally {
    cancelPendingId.value = null
  }
}

function readyPeriodLabel(submission: PayrollJmhzReadySubmission): string {
  return t('payroll.submissions.transport.group.period', {
    start: formatDate(submission.period_start),
    end: formatDate(submission.period_end),
  })
}

function isSplitSubmission(submission: PayrollJmhzReadySubmission): boolean {
  return (submission.package_count ?? 0) > 1
}

async function dispatchReady(
  submission: PayrollJmhzReadySubmission,
  channel: 'isds' | 'vrep',
) {
  if (
    !canWrite.value
    || busy.value
    || submission.outbox_id !== null
    || (channel === 'vrep' && !variableSymbolValid.value)
  ) return
  const confirmed = await confirmProductionSend(
    requestEnvironment.value,
    t(
      channel === 'vrep'
        ? 'payroll.production_send.jmhz_vrep'
        : 'payroll.production_send.jmhz_isds',
      { id: submission.submission_id },
    ),
  )
  if (!confirmed || busy.value) return

  readyDispatchPending.value = { id: submission.submission_id, channel }
  actionError.value = ''
  success.value = ''
  try {
    if (channel === 'vrep') {
      await payrollApi.sendJmhzTransport(
        submission.submission_id,
        variableSymbol.value.trim(),
        requestEnvironment.value,
        crypto.randomUUID(),
      )
      await load()
      success.value = t('payroll.submissions.transport.ready.vrep_started', {
        id: submission.submission_id,
      })
      return
    }

    const queued = await payrollApi.enqueueJmhzIsds(
      submission.submission_id,
      requestEnvironment.value,
    )
    readyIsdsResults.value = {
      ...readyIsdsResults.value,
      [submission.submission_id]: queued,
    }
    if (queued.transport.automatic) {
      try {
        const gateway = await dataBoxApi.gatewayStartPayroll(queued.outbox_id)
        readyGateways.value = {
          ...readyGateways.value,
          [submission.submission_id]: gateway,
        }
      } catch (exception: unknown) {
        actionError.value = apiErrorMessage(
          exception,
          t('payroll.submissions.transport.ready.gateway_start_failed'),
        )
      }
    }
    success.value = queued.created
      ? t('payroll.submissions.transport.ready.isds_queued', { id: queued.outbox_id })
      : t('payroll.submissions.transport.ready.isds_already_queued', { id: queued.outbox_id })
  } catch (exception: unknown) {
    const message = apiErrorMessage(
      exception,
      t('payroll.submissions.transport.ready.dispatch_failed'),
    )
    // Rozdělené hlášení mohlo část balíků odeslat, než odeslání spadlo.
    // Bez znovunačtení by obrazovka dál ukazovala starý průběh balíků.
    if (channel === 'vrep' && isSplitSubmission(submission)) await load()
    actionError.value = message
  } finally {
    readyDispatchPending.value = null
  }
}

function continueReadyGateway(submissionId: number) {
  const gateway = readyGateways.value[submissionId]
  if (gateway) window.location.assign(gateway.redirect_url)
}

async function close(attempt: PayrollJmhzTransportAttempt) {
  if (!canWrite.value || !variableSymbolValid.value || busy.value) return
  closingId.value = attempt.id
  actionError.value = ''
  success.value = ''
  try {
    const result = await payrollApi.closeJmhzTransportAttempt(
      attempt.id,
      variableSymbol.value.trim(),
      requestEnvironment.value,
    )
    // Potvrzení až po znovunačtení: `load()` hlášky čistí, takže nastavené
    // dřív by zmizelo dřív, než by ho někdo stihl přečíst.
    await load()
    success.value = result.already_closed
      ? t('payroll.submissions.transport.closed_already')
      : t('payroll.submissions.transport.closed', { id: attempt.id })
  } catch (exception: unknown) {
    actionError.value = apiErrorMessage(
      exception,
      t('payroll.submissions.transport.close_failed'),
    )
  } finally {
    closingId.value = null
  }
}

async function reverify(attempt: PayrollJmhzTransportAttempt) {
  const receiptId = attempt.unverified_receipt_id ?? null
  if (receiptId === null || busy.value) return
  reverifyingId.value = attempt.id
  actionError.value = ''
  success.value = ''
  try {
    const result = await payrollApi.reverifyJmhzProtocol(
      attempt.submission_id,
      receiptId,
      requestEnvironment.value,
    )
    // Po úspěchu se stav podání změnil, takže přehled musí přijít znovu.
    // Výsledek se zapisuje až potom: `load()` hlášky čistí.
    if (result.verified) await load()
    reverifications.value = { ...reverifications.value, [attempt.id]: result }
  } catch (exception: unknown) {
    actionError.value = apiErrorMessage(
      exception,
      t('payroll.submissions.transport.reverify.request_failed'),
    )
  } finally {
    reverifyingId.value = null
  }
}

/*
 * Přepnutí prostředí je jiný seznam, ne jiný pohled na týž.
 * Bez tohohle sledování zůstala na obrazovce data z prostředí, ze kterého se
 * odcházelo — a protože v tom druhém typicky nic není, prázdný stav tvrdil
 * „zatím nebylo nic odesláno" nad prostředím, kam se právě odeslalo. Uživatel
 * to poznal jedině tím, že sám klikl na Obnovit. Stránkování se resetuje spolu
 * s tím: offset patřil k jinému seznamu.
 */
watch(environment, () => {
  attemptsOffset.value = 0
  importedOffset.value = 0
  void load()
  void loadVariableSymbols()
})

onMounted(load)
onMounted(loadVariableSymbols)
</script>

<template>
  <section class="space-y-4" data-test="payroll-transport-history">
    <div class="rounded-xl border border-neutral-200 bg-surface p-4 shadow-sm sm:p-6">
      <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="max-w-3xl">
          <h2 class="text-lg font-semibold text-neutral-900">
            {{ t('payroll.submissions.transport.title') }}
          </h2>
          <p class="mt-1 text-sm text-neutral-500">
            {{ t('payroll.submissions.transport.description') }}
          </p>
        </div>
        <div class="flex flex-wrap justify-end gap-2">
          <button
            v-if="canWrite"
            type="button"
            data-test="transport-import-protocol"
            :class="btnOutline('primary')"
            :disabled="busy"
            @click="pickProtocolFile"
          >
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
              <path :d="ICONS.upload" />
            </svg>
            {{ importing
              ? t('payroll.submissions.transport.imported.importing')
              : t('payroll.submissions.transport.imported.action') }}
          </button>
          <input
            ref="fileInput"
            data-test="transport-import-input"
            type="file"
            accept=".xml,text/xml,application/xml"
            class="hidden"
            @change="importProtocol"
          >
          <button
            type="button"
            data-test="transport-reload"
            :class="btnOutline('neutral')"
            :disabled="busy"
            @click="load"
          >
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
              <path :d="ICONS.cycle" />
            </svg>
            {{ t('common.refresh') }}
          </button>
        </div>
      </div>
      <p v-if="canWrite" class="mt-3 max-w-3xl text-sm text-neutral-600" data-test="transport-import-hint">
        {{ t('payroll.submissions.transport.imported.hint') }}
      </p>

      <div v-if="submissionTestAllowed" class="mt-5" data-test="transport-environment">
        <span class="mb-1 block text-sm font-medium text-neutral-700">
          {{ t('payroll.submissions.transport.environment.label') }}
        </span>
        <div
          class="inline-flex flex-wrap gap-1 rounded-lg border border-neutral-200 bg-neutral-50 p-1"
          role="group"
          :aria-label="t('payroll.submissions.transport.environment.label')"
        >
          <button
            v-for="option in ENVIRONMENTS"
            :key="option"
            type="button"
            :data-test="`transport-environment-${option}`"
            :aria-pressed="environment === option"
            class="cursor-pointer whitespace-nowrap rounded-md px-3 py-1.5 text-sm font-medium transition-colors disabled:cursor-not-allowed disabled:opacity-50"
            :class="environment === option
              ? (option === 'production'
                ? 'bg-warning-500 text-white shadow-sm'
                : 'bg-payroll-600 text-white shadow-sm')
              : 'text-neutral-600 hover:text-neutral-900'"
            :disabled="busy"
            @click="switchEnvironment(option)"
          >
            {{ t(`payroll.submissions.transport.environment.${option}`) }}
          </button>
        </div>
        <p
          class="mt-3 rounded-lg border p-3 text-sm"
          :class="environment === 'production'
            ? 'border-warning-500/40 bg-warning-50 text-warning-800'
            : 'border-payroll-500/30 bg-payroll-50 text-neutral-700'"
          data-test="transport-environment-note"
        >
          {{ t(`payroll.submissions.transport.environment.${environment}_note`) }}
        </p>
      </div>

      <div class="mt-5 rounded-lg border border-neutral-200 p-4">
        <label class="block max-w-xs">
          <span class="mb-1 block text-sm font-medium text-neutral-700">
            {{ t('payroll.submissions.transport.vs.label') }}
          </span>
          <input
            v-model="variableSymbol"
            data-test="transport-variable-symbol"
            type="text"
            inputmode="numeric"
            autocomplete="off"
            maxlength="10"
            class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-2 font-mono text-sm"
            @input="variableSymbolTouched = true"
          >
        </label>
        <p class="mt-2 max-w-3xl text-xs text-neutral-500">
          {{ t('payroll.submissions.transport.vs.hint') }}
        </p>
        <div
          v-if="variableSymbolOptions.length > 1"
          class="mt-3 flex flex-wrap items-center gap-2"
          data-test="transport-vs-options"
        >
          <span class="text-xs text-neutral-500">
            {{ t('payroll.submissions.transport.vs.pick') }}
          </span>
          <button
            v-for="option in variableSymbolOptions"
            :key="option.value"
            type="button"
            :class="btnOutlineSm('neutral')"
            @click="useVariableSymbol(option.value)"
          >
            {{ option.value }} - {{ option.label }}
          </button>
        </div>
        <p
          v-else-if="variableSymbolOptions.length === 0"
          class="mt-3 text-xs text-warning-700"
          data-test="transport-vs-missing"
        >
          {{ t('payroll.submissions.transport.vs.missing') }}
        </p>
        <p
          v-if="!variableSymbolValid"
          class="mt-3 text-xs text-neutral-600"
          data-test="transport-vs-required"
        >
          {{ t('payroll.submissions.transport.vs.required') }}
        </p>
        <p
          v-if="testEnvironmentVariableSymbolWarning"
          class="mt-3 rounded-lg border border-warning-500/40 bg-warning-50 p-3 text-xs text-warning-800"
          data-test="transport-vs-test-mismatch-warning"
        >
          {{ t('payroll.submissions.transport.vs.test_mismatch_warning') }}
        </p>
      </div>
    </div>

    <div
      v-if="loadError"
      data-test="transport-load-error"
      class="rounded-xl border border-danger-500/30 bg-danger-50 p-4 text-sm text-danger-700"
      role="alert"
    >
      <p class="font-medium">{{ loadError }}</p>
      <p class="mt-1">{{ t('payroll.submissions.transport.state_unknown') }}</p>
    </div>

    <div
      v-else-if="loading"
      data-test="transport-loading"
      class="h-64 animate-pulse rounded-xl bg-neutral-100"
    />

    <template v-else>
      <!--
        Filtr míří na OBDOBÍ hlášení, ne na den odeslání: karta je nadepsaná
        obdobím a uživatel hledá „co jsem poslal za červenec".
      -->
      <!--
        Bez počtu záměrně: přehled slévá tři nezávislé zdroje (pokusy, podání
        odeslaná datovkou a načtené protokoly) a `attemptsTotal` je jen první
        z nich. Číslo u filtru by tedy tvrdilo něco jiného, než je na obrazovce.
      -->
      <PeriodFilterBar
        :year="filterYear"
        :month="filterMonth"
        :years="filterYears"
        @update:year="applyPeriodFilter({ year: $event })"
        @update:month="applyPeriodFilter({ month: $event })"
      />
      <p
        v-if="actionError"
        data-test="transport-error"
        class="rounded-xl border border-danger-500/30 bg-danger-50 p-4 text-sm text-danger-700"
        role="alert"
      >
        {{ actionError }}
      </p>
      <p
        v-if="success"
        data-test="transport-success"
        class="rounded-xl border border-success-500/30 bg-success-50 p-4 text-sm text-success-700"
        role="status"
      >
        {{ success }}
      </p>

      <section
        v-if="readySubmissions.length > 0"
        class="overflow-hidden rounded-xl border border-payroll-500/30 bg-payroll-50"
        data-test="transport-ready-submissions"
      >
        <div class="border-b border-payroll-500/20 p-4 sm:p-6">
          <h3 class="text-base font-semibold text-neutral-900">
            {{ t('payroll.submissions.transport.ready.title') }}
          </h3>
          <p class="mt-1 max-w-3xl text-sm text-neutral-600">
            {{ t('payroll.submissions.transport.ready.description') }}
          </p>
        </div>
        <div class="divide-y divide-payroll-500/20">
          <article
            v-for="submission in readySubmissions"
            :key="submission.submission_id"
            class="p-4 sm:p-6"
            :data-test="`transport-ready-${submission.submission_id}`"
          >
            <div class="flex flex-wrap items-start justify-between gap-3">
              <div>
                <div class="flex flex-wrap items-center gap-2">
                  <h4 class="font-semibold text-neutral-900">
                    {{ readyPeriodLabel(submission) }}
                  </h4>
                  <span class="rounded-full bg-payroll-100 px-2.5 py-1 text-xs font-medium text-payroll-800">
                    {{ t(`payroll.submissions.transport.ready.kind.${submission.submission_kind}`) }}
                  </span>
                </div>
                <p class="mt-1 text-xs text-neutral-600">
                  {{ t('payroll.submissions.transport.ready.submission', {
                    id: submission.submission_id,
                  }) }}
                  <template v-if="submission.corrects_submission_id">
                    · {{ t('payroll.submissions.transport.ready.corrects', {
                      id: submission.corrects_submission_id,
                    }) }}
                  </template>
                </p>
              </div>
              <div v-if="canWrite" class="flex flex-wrap justify-end gap-2">
                <button
                  type="button"
                  :data-test="`transport-ready-vrep-${submission.submission_id}`"
                  :class="btnOutline('neutral')"
                  :disabled="busy || !variableSymbolValid || submission.outbox_id !== null"
                  @click="dispatchReady(submission, 'vrep')"
                >
                  <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path :d="ICONS.cycle" />
                  </svg>
                  {{ readyDispatchPending?.id === submission.submission_id
                    && readyDispatchPending.channel === 'vrep'
                    ? t('payroll.submissions.transport.ready.sending')
                    : (submission.packages_sent ?? 0) > 0
                      ? t('payroll.submissions.transport.ready.packages.send_remaining')
                      : t('payroll.submissions.transport.ready.send_vrep') }}
                </button>
                <button
                  v-if="!isSplitSubmission(submission)"
                  type="button"
                  :data-test="`transport-ready-isds-${submission.submission_id}`"
                  :class="btnFilled('primary')"
                  :disabled="busy || submission.outbox_id !== null"
                  @click="dispatchReady(submission, 'isds')"
                >
                  <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path :d="ICONS.send" />
                  </svg>
                  {{ readyDispatchPending?.id === submission.submission_id
                    && readyDispatchPending.channel === 'isds'
                    ? t('payroll.submissions.transport.ready.sending')
                    : t('payroll.submissions.transport.ready.send_isds') }}
                </button>
              </div>
            </div>
            <div
              v-if="isSplitSubmission(submission)"
              class="mt-3 rounded-lg border border-info-500/30 bg-info-50 p-3 text-sm text-neutral-700"
              :data-test="`transport-ready-packages-${submission.submission_id}`"
            >
              <p class="font-medium text-neutral-900">
                {{ t('payroll.submissions.transport.ready.packages.progress', {
                  sent: submission.packages_sent ?? 0,
                  count: submission.package_count ?? 0,
                }) }}
              </p>
              <p v-if="(submission.packages_sent ?? 0) > 0" class="mt-1">
                {{ t('payroll.submissions.transport.ready.packages.interrupted') }}
              </p>
              <p class="mt-1 text-xs text-neutral-600">
                {{ t('payroll.submissions.transport.ready.packages.vrep_only') }}
              </p>
            </div>
            <p class="mt-3 text-xs text-neutral-600">
              {{ t('payroll.submissions.transport.ready.user_action_note') }}
            </p>
            <div
              v-if="submission.outbox_id !== null
                && !readyIsdsResults[submission.submission_id]"
              class="mt-3 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-info-500/30 bg-info-50 p-3 text-sm text-neutral-700"
              :data-test="`transport-ready-existing-outbox-${submission.submission_id}`"
            >
              <p>
                {{ t('payroll.submissions.transport.ready.existing_outbox', {
                  id: submission.outbox_id,
                  state: t(`payroll.submissions.transport.ready.outbox_state.${submission.outbox_dispatch_state ?? 'ready'}`),
                }) }}
              </p>
              <a
                href="/admin/databox?tab=outbox"
                :class="btnOutline('neutral')"
              >
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                  <path :d="ICONS.send" />
                </svg>
                {{ t('payroll.submissions.transport.ready.open_outbox') }}
              </a>
            </div>
            <div
              v-if="readyIsdsResults[submission.submission_id]"
              class="mt-3 rounded-lg border border-payroll-500/30 bg-surface p-3 text-sm text-neutral-700"
              :data-test="`transport-ready-isds-result-${submission.submission_id}`"
            >
              <p>
                {{ t('payroll.submissions.transport.ready.outbox', {
                  id: readyIsdsResults[submission.submission_id]!.outbox_id,
                }) }}
              </p>
              <button
                v-if="readyGateways[submission.submission_id]"
                type="button"
                class="cursor-pointer mt-3"
                :class="btnFilled('primary')"
                :data-test="`transport-ready-gateway-${submission.submission_id}`"
                @click="continueReadyGateway(submission.submission_id)"
              >
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                  <path :d="ICONS.send" />
                </svg>
                {{ t('payroll.submissions.transport.ready.continue_isds') }}
              </button>
            </div>
          </article>
        </div>
      </section>

      <div
        v-if="timeline.length === 0 && readySubmissions.length === 0"
        data-test="transport-empty"
        class="rounded-xl border border-dashed border-neutral-300 bg-surface p-6 text-sm text-neutral-600"
      >
        <p class="font-medium text-neutral-800">
          {{ t('payroll.submissions.transport.empty.title') }}
        </p>
        <p class="mt-1">{{ t('payroll.submissions.transport.empty.description') }}</p>
        <p class="mt-2">{{ t('payroll.submissions.transport.empty.import_hint') }}</p>
      </div>

      <template v-else>
        <template v-for="entry in visibleTimeline" :key="entry.key">
        <h3
          v-if="entry.source === 'period'"
          class="pt-2 text-sm font-semibold uppercase tracking-wide text-neutral-500"
          :data-test="`transport-period-${entry.periodKey}`"
        >
          {{ entry.label }}
        </h3>

        <button
          v-else-if="entry.source === 'history-toggle'"
          type="button"
          :class="btnOutlineSm('neutral')"
          :aria-expanded="historyOpen[entry.periodKey] === true"
          :data-test="`transport-history-toggle-${entry.periodKey}`"
          @click="toggleHistory(entry.periodKey)"
        >
          <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path :d="ICONS.chevron" />
          </svg>
          {{ t('payroll.submissions.transport.history.toggle', { count: entry.count }) }}
        </button>

        <section
          v-else-if="entry.source === 'app'"
          :data-test="`transport-group-${entry.group.submissionId}`"
          :data-zone="entry.zone"
          class="rounded-xl border bg-surface shadow-sm"
          :class="entry.zone === 'history' ? 'border-neutral-200 opacity-80' : 'border-neutral-200'"
        >
          <div class="flex flex-wrap items-start justify-between gap-3 border-b border-neutral-200 p-4 sm:p-6">
            <div>
              <h3 class="text-base font-semibold text-neutral-900">
                {{ periodLabel(entry.group) }}
              </h3>
              <p class="mt-1 text-xs text-neutral-500">
                {{ t('payroll.submissions.transport.group.submission', {
                  id: entry.group.submissionId,
                }) }}
                <template v-if="entry.group.submissionKind && te(`payroll.submissions.transport.ready.kind.${entry.group.submissionKind}`)">
                  · {{ t(`payroll.submissions.transport.ready.kind.${entry.group.submissionKind}`) }}
                </template>
              </p>
            </div>
            <div class="flex flex-wrap items-center justify-end gap-2">
              <span
                class="rounded-full px-2.5 py-1 text-xs font-semibold"
                :class="RESULT_TONES[resultKey(entry.group)]"
                :data-test="`transport-result-${entry.group.submissionId}`"
              >
                {{ t(`payroll.submissions.transport.result.${resultKey(entry.group)}`) }}
              </span>
              <PayrollSubmissionManualAcceptance
                :environment="requestEnvironment"
                :submission-id="entry.group.submissionId"
                :submission-status="entry.group.submissionStatus"
                :summary="manualAcceptances[entry.group.submissionId] ?? null"
                :can-write="canWrite"
                @accepted="load"
              />
              <span
                v-if="entry.replacement"
                class="rounded-full bg-neutral-100 px-2.5 py-1 text-xs font-medium text-neutral-700"
                :data-test="`transport-replaced-${entry.group.submissionId}`"
              >
                {{ replacementLabel(entry.replacement) }}
              </span>
              <span
                v-if="entry.group.dispatched && entry.group.attempts.length === 0"
                class="rounded-full bg-payroll-100 px-2.5 py-1 text-xs font-medium text-payroll-800"
                :data-test="`transport-source-app-${entry.group.submissionId}`"
              >
                {{ t('payroll.submissions.transport.source.databox', {
                  date: entry.group.dispatched.outbox_sent_at
                    ? formatDate(entry.group.dispatched.outbox_sent_at)
                    : '—',
                }) }}
              </span>
              <template v-else>
                <span
                  class="rounded-full bg-payroll-100 px-2.5 py-1 text-xs font-medium text-payroll-800"
                  :data-test="`transport-source-app-${entry.group.submissionId}`"
                >
                  {{ t('payroll.submissions.transport.source.app') }}
                </span>
                <span class="rounded-full bg-neutral-100 px-2.5 py-1 text-xs font-medium text-neutral-700">
                  {{ t('payroll.submissions.transport.group.attempts', {
                    total: entry.group.attempts.length,
                  }) }}
                </span>
              </template>
              <button
                v-if="canCorrect(entry.group) && correctingId !== actionTarget(entry.group)"
                type="button"
                :data-test="`transport-correct-${actionTarget(entry.group)}`"
                :class="btnOutlineSm('warning')"
                :disabled="busy"
                @click="askToCorrect(actionTarget(entry.group))"
              >
                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                  <path :d="ICONS.edit" />
                </svg>
                {{ t('payroll.submissions.transport.correction.action') }}
              </button>
              <button
                v-if="canCancel(entry.group) && cancellingId !== actionTarget(entry.group)"
                type="button"
                :data-test="`transport-cancel-${actionTarget(entry.group)}`"
                :class="btnOutlineSm('danger')"
                :disabled="busy"
                @click="askToCancel(actionTarget(entry.group))"
              >
                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                  <path :d="ICONS.x" />
                </svg>
                {{ t('payroll.submissions.transport.storno.action') }}
              </button>
            </div>
          </div>

          <div
            v-if="correctingId === actionTarget(entry.group) && canCorrectFormHost(entry)"
            :data-test="`transport-correct-form-${actionTarget(entry.group)}`"
            class="border-b border-warning-500/30 bg-warning-50 p-4 sm:p-6"
          >
            <p class="text-sm font-semibold text-warning-800">
              {{ t('payroll.submissions.transport.correction.title', {
                period: periodLabel(entry.group),
              }) }}
            </p>
            <p class="mt-1 text-sm text-warning-800">
              {{ t('payroll.submissions.transport.correction.description') }}
            </p>
            <p class="mt-1 text-xs text-warning-700" data-test="transport-correction-deadline-hint">
              {{ t('payroll.submissions.transport.correction.deadline_hint') }}
            </p>
            <div
              v-if="correctionPreparationLoadingId === actionTarget(entry.group)"
              data-test="transport-correct-preparation-loading"
              class="mt-4 rounded-lg border border-warning-500/30 bg-surface p-4 text-sm text-neutral-600"
              role="status"
            >
              {{ t('payroll.submissions.transport.correction.preparation_loading') }}
            </div>
            <div
              v-else-if="correctionPreparations.length === 0"
              data-test="transport-correct-preparation-empty"
              class="mt-4 rounded-lg border border-warning-500/30 bg-surface p-4 text-sm text-neutral-700"
            >
              {{ t('payroll.submissions.transport.correction.preparation_empty') }}
            </div>
            <div
              v-else-if="correctionPreparations.length > 1"
              class="mt-4 flex flex-wrap items-end gap-3"
            >
              <label class="min-w-64 flex-1 text-sm font-medium text-neutral-800">
                {{ t('payroll.submissions.transport.correction.preparation_label') }}
                <SearchableSelect
                  v-model="correctionPreparationId"
                  :options="correctionPreparationOptions"
                  :clearable="false"
                  accent="payroll"
                  data-test="transport-correct-preparation-select"
                  class="mt-1"
                  :placeholder="t('payroll.submissions.transport.correction.preparation_placeholder')"
                  :no-results-label="t('payroll.submissions.transport.correction.preparation_no_results')"
                />
              </label>
              <button
                type="button"
                :class="btnOutline('warning')"
                :disabled="busy || correctionPreparationId === null"
                data-test="transport-correct-load"
                @click="loadContentCorrectionCandidates(actionTarget(entry.group))"
              >
                {{ t('payroll.submissions.transport.correction.load') }}
              </button>
            </div>
            <p
              v-else
              data-test="transport-correct-preparation-auto"
              class="mt-4 rounded-lg border border-warning-500/30 bg-surface p-3 text-sm text-neutral-700"
            >
              {{ t('payroll.submissions.transport.correction.preparation_auto', {
                preparation: correctionPreparationOptions[0]?.label ?? '',
              }) }}
            </p>
            <div
              v-if="correctionLoadingId === actionTarget(entry.group)"
              data-test="transport-correct-loading"
              class="mt-4 rounded-lg border border-warning-500/30 bg-surface p-4 text-sm text-neutral-600"
              role="status"
            >
              {{ t('payroll.submissions.transport.correction.loading') }}
            </div>
            <div
              v-else-if="correctionCandidatesLoaded && correctableComponents.length === 0"
              data-test="transport-correct-empty"
              class="mt-4 rounded-lg border border-warning-500/30 bg-surface p-4 text-sm text-neutral-700"
            >
              {{ t('payroll.submissions.transport.correction.empty') }}
            </div>
            <template v-else-if="correctableComponents.length > 0">
              <div class="mt-4 flex flex-wrap items-end justify-between gap-3">
                <label class="min-w-64 flex-1 text-sm font-medium text-neutral-800">
                  {{ t('payroll.submissions.transport.correction.search_label') }}
                  <input
                    v-model="correctionQuery"
                    type="search"
                    autocomplete="off"
                    data-test="transport-correct-search"
                    class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm text-neutral-900"
                    :placeholder="t('payroll.submissions.transport.correction.search_placeholder')"
                  >
                </label>
                <p class="pb-2 text-xs text-neutral-600" data-test="transport-correct-count">
                  {{ t('payroll.submissions.transport.correction.selection_count', {
                    selected: selectedCorrectionGuids.length,
                    total: correctableComponents.length,
                  }) }}
                </p>
              </div>

              <div
                v-if="protocolErrorComponentGuids.size > 0"
                data-test="transport-correct-protocol-hint"
                class="mt-3 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-danger-500/30 bg-danger-50 p-3"
              >
                <p class="text-sm text-danger-700">
                  {{ t('payroll.submissions.transport.correction.protocol_errors', {
                    count: protocolErrorComponentGuids.size,
                  }) }}
                </p>
                <button
                  type="button"
                  :class="btnOutlineSm('danger')"
                  :disabled="busy"
                  data-test="transport-correct-select-errors"
                  @click="selectProtocolErrors"
                >
                  {{ t('payroll.submissions.transport.correction.select_errors') }}
                </button>
              </div>

              <div
                v-if="visibleCorrectionComponents.length > 0"
                class="mt-3 max-h-96 space-y-2 overflow-y-auto pr-1"
              >
                <label
                  v-for="component in visibleCorrectionComponents"
                  :key="component.employment_external_identifier"
                  :data-test="`transport-correct-component-${component.employment_external_identifier}`"
                  class="flex cursor-pointer items-start gap-3 rounded-lg border border-warning-500/30 bg-surface p-3"
                >
                  <input
                    v-model="selectedCorrectionGuids"
                    type="checkbox"
                    :value="component.employment_external_identifier"
                    class="mt-0.5 h-4 w-4 rounded border-neutral-300 text-warning-700 focus:ring-warning-500"
                  >
                  <span class="min-w-0 flex-1">
                    <span class="flex flex-wrap items-center gap-2">
                      <span class="text-sm font-medium text-neutral-900">
                        {{ component.employee_name
                          ?? t('payroll.submissions.transport.correction.employee_unknown') }}
                      </span>
                      <span
                        v-if="protocolErrorComponentGuids.has(component.employment_external_identifier)"
                        class="rounded-full bg-danger-100 px-2 py-0.5 text-xs font-medium text-danger-700"
                      >
                        {{ t('payroll.submissions.transport.correction.flagged_by_protocol') }}
                      </span>
                      <span class="rounded-full bg-info-100 px-2 py-0.5 text-xs font-medium text-info-700">
                        {{ t(`payroll.submissions.transport.correction.action_kind.${component.action}`) }}
                      </span>
                      <span
                        v-if="component.changed === true"
                        :data-test="`transport-correct-changed-${component.employment_external_identifier}`"
                        class="rounded-full bg-warning-100 px-2 py-0.5 text-xs font-medium text-warning-700"
                      >
                        {{ t('payroll.submissions.transport.correction.changed') }}
                      </span>
                      <span
                        v-else-if="component.changed === false"
                        class="rounded-full bg-neutral-100 px-2 py-0.5 text-xs font-medium text-neutral-600"
                      >
                        {{ t('payroll.submissions.transport.correction.unchanged') }}
                      </span>
                    </span>
                    <span class="mt-0.5 block text-xs text-neutral-600">
                      {{ t('payroll.submissions.transport.correction.technical_identity', {
                        employment: component.employment_external_identifier,
                        person: component.person_external_identifier,
                      }) }}
                    </span>
                  </span>
                </label>
              </div>
              <p
                v-else
                data-test="transport-correct-no-results"
                class="mt-3 rounded-lg border border-neutral-200 bg-surface p-4 text-sm text-neutral-600"
              >
                {{ t('payroll.submissions.transport.correction.no_results') }}
              </p>

              <div
                v-if="correctionBlocked.length"
                class="mt-3 rounded-lg border border-neutral-300 bg-surface p-3 text-sm text-neutral-700"
                data-test="transport-correct-blocked"
              >
                <p class="font-medium">
                  {{ t('payroll.jmhz_gate.correction_blocked.title', { count: correctionBlocked.length }) }}
                </p>
                <ul class="mt-1 list-disc pl-4 text-xs">
                  <li v-for="(blocker, index) in correctionBlocked" :key="`${blocker.code}-${blocker.entity_type}-${blocker.entity_id ?? index}`">
                    {{ jmhzBlockerLabel(t, te, blocker) }}
                  </li>
                </ul>
                <p class="mt-1 text-xs">{{ t('payroll.jmhz_gate.correction_blocked.hint') }}</p>
              </div>

              <label
                class="mt-4 flex cursor-pointer items-start gap-3 rounded-lg border border-warning-500/40 bg-surface p-4"
              >
                <input
                  v-model="correctionImpactConfirmed"
                  type="checkbox"
                  data-test="transport-correct-impact"
                  class="mt-0.5 h-4 w-4 rounded border-neutral-300 text-warning-700 focus:ring-warning-500"
                >
                <span class="text-sm text-neutral-800">
                  {{ t('payroll.submissions.transport.correction.impact_confirmation') }}
                </span>
              </label>
            </template>
            <div class="mt-4 flex flex-wrap gap-2 border-t border-warning-500/30 pt-4">
              <button
                type="button"
                :data-test="`transport-correct-submit-${actionTarget(entry.group)}`"
                :class="btnFilled('warning')"
                :disabled="busy
                  || correctionPreparationId === null
                  || selectedCorrectionGuids.length === 0
                  || !correctionImpactConfirmed"
                @click="confirmCorrection(actionTarget(entry.group))"
              >
                {{ t('payroll.submissions.transport.correction.confirm') }}
              </button>
              <button
                type="button"
                :data-test="`transport-correct-abort-${actionTarget(entry.group)}`"
                :class="btnOutline('neutral')"
                :disabled="busy"
                @click="closeCorrection"
              >
                {{ t('payroll.submissions.transport.correction.cancel') }}
              </button>
            </div>
          </div>

          <!-- Storno ruší u ČSSZ všechna hlášení za období a je nevratné,
               takže se nespouští jedním kliknutím. -->
          <div
            v-if="cancellingId === actionTarget(entry.group) && canCorrectFormHost(entry)"
            :data-test="`transport-cancel-confirm-${actionTarget(entry.group)}`"
            class="border-b border-danger-500/30 bg-danger-50 p-4 sm:p-6"
            role="alert"
          >
            <p class="text-sm font-semibold text-danger-700">
              {{ t('payroll.submissions.transport.storno.confirm_title', {
                period: periodLabel(entry.group),
              }) }}
            </p>
            <fieldset class="mt-2 flex flex-wrap gap-x-6 gap-y-2 text-sm text-danger-800">
              <legend class="sr-only">{{ t('payroll.jmhz_gate.cancel_components.mode_label') }}</legend>
              <label class="flex cursor-pointer items-center gap-2">
                <input
                  type="radio"
                  name="jmhz-cancel-mode"
                  value="whole"
                  :checked="cancelMode === 'whole'"
                  :data-test="`transport-cancel-mode-whole-${actionTarget(entry.group)}`"
                  @change="chooseCancelMode(actionTarget(entry.group), 'whole')"
                >
                {{ t('payroll.jmhz_gate.cancel_components.mode_whole') }}
              </label>
              <label class="flex cursor-pointer items-center gap-2">
                <input
                  type="radio"
                  name="jmhz-cancel-mode"
                  value="components"
                  :checked="cancelMode === 'components'"
                  :data-test="`transport-cancel-mode-components-${actionTarget(entry.group)}`"
                  @change="chooseCancelMode(actionTarget(entry.group), 'components')"
                >
                {{ t('payroll.jmhz_gate.cancel_components.mode_components') }}
              </label>
            </fieldset>
            <p v-if="cancelMode === 'whole'" class="mt-1 text-sm text-danger-700">
              {{ t('payroll.submissions.transport.storno.confirm_text') }}
            </p>
            <div v-else class="mt-2" :data-test="`transport-cancel-components-${actionTarget(entry.group)}`">
              <p class="text-sm text-danger-700">{{ t('payroll.jmhz_gate.cancel_components.description') }}</p>
              <p v-if="cancelComponentsLoading" class="mt-2 text-sm text-neutral-600" role="status">
                {{ t('common.loading') }}
              </p>
              <p v-if="cancelComponentsError" class="mt-2 rounded-lg border border-danger-500/30 bg-surface p-2 text-sm text-danger-700">
                {{ cancelComponentsError }}
              </p>
              <div v-if="cancelComponents.length" class="mt-2 max-h-80 space-y-2 overflow-y-auto pr-1">
                <label
                  v-for="component in cancelComponents"
                  :key="component.form_guid"
                  class="flex cursor-pointer items-start gap-3 rounded-lg border border-danger-500/30 bg-surface p-3"
                  :data-test="`transport-cancel-component-${component.employment_external_identifier}`"
                >
                  <input
                    v-model="selectedCancelGuids"
                    type="checkbox"
                    :value="component.form_guid"
                    class="mt-0.5 h-4 w-4 rounded border-neutral-300 text-danger-700 focus:ring-danger-500"
                  >
                  <span class="min-w-0 flex-1">
                    <span class="block text-sm font-medium text-neutral-900">
                      {{ component.employee_name ?? t('payroll.submissions.transport.correction.employee_unknown') }}
                    </span>
                    <span class="mt-0.5 block text-xs text-neutral-600">
                      {{ t('payroll.submissions.transport.correction.technical_identity', {
                        employment: component.employment_external_identifier,
                        person: component.person_external_identifier,
                      }) }}
                    </span>
                  </span>
                </label>
              </div>
              <label class="mt-3 flex cursor-pointer items-start gap-3 rounded-lg border border-danger-500/40 bg-surface p-3">
                <input
                  v-model="cancelComponentsConfirmed"
                  type="checkbox"
                  :data-test="`transport-cancel-components-impact-${actionTarget(entry.group)}`"
                  class="mt-0.5 h-4 w-4 rounded border-neutral-300 text-danger-700 focus:ring-danger-500"
                >
                <span class="text-sm text-neutral-800">{{ t('payroll.jmhz_gate.cancel_components.impact') }}</span>
              </label>
              <p
                v-if="selectedCancelGuids.length === 0 || !cancelComponentsConfirmed"
                class="mt-1 text-xs text-danger-700"
              >
                {{ t('payroll.jmhz_gate.cancel_components.disabled_reason') }}
              </p>
            </div>
            <div class="mt-3 flex flex-wrap gap-2">
              <button
                v-if="cancelMode === 'whole'"
                type="button"
                :data-test="`transport-cancel-submit-${actionTarget(entry.group)}`"
                :class="btnFilled('danger')"
                :disabled="busy"
                @click="confirmCancel(actionTarget(entry.group))"
              >
                {{ t('payroll.submissions.transport.storno.confirm') }}
              </button>
              <button
                v-else
                type="button"
                :data-test="`transport-cancel-components-submit-${actionTarget(entry.group)}`"
                :class="btnFilled('danger')"
                :disabled="busy || selectedCancelGuids.length === 0 || !cancelComponentsConfirmed"
                @click="confirmCancelComponents(actionTarget(entry.group))"
              >
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                  <path :d="ICONS.x" />
                </svg>
                {{ t('payroll.jmhz_gate.cancel_components.confirm', { count: selectedCancelGuids.length }) }}
              </button>
              <button
                type="button"
                :data-test="`transport-cancel-abort-${actionTarget(entry.group)}`"
                :class="btnOutline('neutral')"
                :disabled="busy"
                @click="cancellingId = null"
              >
                {{ t('payroll.submissions.transport.storno.cancel') }}
              </button>
            </div>
          </div>

          <div
            v-if="entry.group.dispatched"
            :data-test="`transport-dispatched-${entry.group.submissionId}`"
            class="border-b border-neutral-100 p-4 sm:p-6"
          >
            <p class="text-sm text-neutral-700">
              {{ t('payroll.submissions.transport.dispatched.note') }}
            </p>
            <dl class="mt-3 grid gap-x-6 gap-y-2 sm:grid-cols-2">
              <div>
                <dt class="text-xs uppercase tracking-wide text-neutral-500">
                  {{ t('payroll.submissions.transport.dispatched.recipient') }}
                </dt>
                <dd class="font-mono text-sm text-neutral-800">
                  {{ entry.group.dispatched.outbox_recipient_box_id ?? '—' }}
                </dd>
              </div>
              <div>
                <dt class="text-xs uppercase tracking-wide text-neutral-500">
                  {{ t('payroll.submissions.transport.dispatched.message_id') }}
                </dt>
                <dd class="font-mono text-sm text-neutral-800">
                  {{ entry.group.dispatched.outbox_external_message_id ?? '—' }}
                </dd>
              </div>
              <div>
                <dt class="text-xs uppercase tracking-wide text-neutral-500">
                  {{ t('payroll.submissions.transport.dispatched.sent_at') }}
                </dt>
                <dd class="text-sm text-neutral-800">
                  {{ entry.group.dispatched.outbox_sent_at
                    ? formatDateTime(entry.group.dispatched.outbox_sent_at)
                    : t('payroll.submissions.transport.not_sent_yet') }}
                </dd>
              </div>
              <div>
                <dt class="text-xs uppercase tracking-wide text-neutral-500">
                  {{ t('payroll.submissions.transport.dispatched.reference') }}
                </dt>
                <dd class="font-mono text-sm text-neutral-800">
                  {{ entry.group.dispatched.outbox_correlation_reference }}
                </dd>
              </div>
            </dl>
          </div>

          <div class="divide-y divide-neutral-100">
            <article
              v-for="attempt in entry.group.attempts"
              :key="attempt.id"
              :data-test="`transport-attempt-${attempt.id}`"
              class="p-4 sm:p-6"
            >
              <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="flex flex-wrap items-center gap-2">
                  <span
                    class="rounded-full px-2.5 py-1 text-xs font-semibold"
                    :class="statusTone(attempt.status)"
                    :data-test="`transport-status-${attempt.id}`"
                  >
                    {{ t(`payroll.submissions.transport.status.${attempt.status}`) }}
                  </span>
                  <span class="rounded-full bg-neutral-100 px-2.5 py-1 text-xs font-medium text-neutral-700">
                    {{ t('payroll.submissions.transport.attempt_no', { no: attempt.attempt_no }) }}
                  </span>
                  <span
                    v-if="attempt.package_ordinal && (attempt.package_count ?? 0) > 1"
                    class="rounded-full bg-info-50 px-2.5 py-1 text-xs font-medium text-info-700"
                    :data-test="`transport-attempt-package-${attempt.id}`"
                  >
                    {{ t('payroll.submissions.transport.attempt_package', {
                      ordinal: attempt.package_ordinal,
                      count: attempt.package_count,
                    }) }}
                  </span>
                  <span class="text-xs text-neutral-500">
                    {{ attempt.sent_at
                      ? t('payroll.submissions.transport.sent_at', { at: formatUtcDateTime(attempt.sent_at) })
                      : t('payroll.submissions.transport.not_sent_yet') }}
                  </span>
                </div>
                <div class="flex flex-wrap justify-end gap-2">
                  <button
                    v-if="canPoll(attempt)"
                    type="button"
                    :data-test="`transport-poll-${attempt.id}`"
                    :class="btnOutlineSm('primary')"
                    :disabled="busy || !variableSymbolValid"
                    @click="poll(attempt)"
                  >
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                      <path :d="ICONS.cycle" />
                    </svg>
                    {{ pollingId === attempt.id
                      ? t('payroll.submissions.transport.polling')
                      : t('payroll.submissions.transport.poll') }}
                  </button>
                  <button
                    v-if="canReverify(attempt)"
                    type="button"
                    :data-test="`transport-reverify-${attempt.id}`"
                    :class="btnOutlineSm('warning')"
                    :disabled="busy"
                    :title="t('payroll.submissions.transport.reverify.hint')"
                    @click="reverify(attempt)"
                  >
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                      <path :d="ICONS.badgeCheck" />
                    </svg>
                    {{ reverifyingId === attempt.id
                      ? t('payroll.submissions.transport.reverify.running')
                      : t('payroll.submissions.transport.reverify.action') }}
                  </button>
                  <button
                    v-if="canDelete(attempt)"
                    type="button"
                    :data-test="`transport-delete-${attempt.id}`"
                    :class="btnOutlineSm('danger')"
                    :disabled="busy"
                    :title="t('payroll.submissions.transport.delete_hint')"
                    @click="deleteAttempt(attempt)"
                  >
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                      <path :d="ICONS.trash" />
                    </svg>
                    {{ deletingId === attempt.id
                      ? t('payroll.submissions.transport.deleting')
                      : t('payroll.submissions.transport.delete') }}
                  </button>
                  <button
                    v-if="canWrite && canClose(attempt)"
                    type="button"
                    :data-test="`transport-close-${attempt.id}`"
                    :class="btnOutlineSm('neutral')"
                    :disabled="busy || !variableSymbolValid"
                    @click="close(attempt)"
                  >
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                      <path :d="ICONS.archive" />
                    </svg>
                    {{ closingId === attempt.id
                      ? t('payroll.submissions.transport.closing')
                      : t('payroll.submissions.transport.close') }}
                  </button>
                </div>
              </div>

              <p
                v-if="attempt.status === 'awaiting_protocol'"
                class="mt-3 rounded-lg border border-warning-500/30 bg-warning-50 p-3 text-sm text-warning-800"
                :data-test="`transport-awaiting-note-${attempt.id}`"
              >
                {{ t('payroll.submissions.transport.awaiting_note') }}
              </p>
              <p
                v-else-if="attempt.status === 'completed' && !attempt.closed_at"
                class="mt-3 text-sm text-neutral-600"
                :data-test="`transport-close-note-${attempt.id}`"
              >
                {{ t('payroll.submissions.transport.close_note') }}
              </p>
              <p
                v-else-if="attempt.status === 'expired'"
                class="mt-3 rounded-lg border border-danger-500/30 bg-danger-50 p-3 text-sm text-danger-700"
                :data-test="`transport-expired-note-${attempt.id}`"
                role="alert"
              >
                {{ t('payroll.submissions.transport.automation.expired_note') }}
              </p>

              <!-- Co dělá automatika. Bez tohohle by uživatel nevěděl, jestli
                   se aplikace ptá sama, nebo jestli na něj podání čeká. -->
              <div
                v-if="attempt.status === 'awaiting_protocol' || attempt.status === 'completed'"
                :data-test="`transport-automation-${attempt.id}`"
                class="mt-3 rounded-lg border border-neutral-200 bg-neutral-50 p-3 text-sm text-neutral-700"
              >
                <p class="font-medium text-neutral-900">
                  {{ t('payroll.submissions.transport.automation.title') }}
                </p>
                <p class="mt-1">
                  {{ t('payroll.submissions.transport.automation.description') }}
                </p>
                <ul class="mt-2 space-y-1 text-xs text-neutral-600">
                  <li v-if="attempt.status === 'awaiting_protocol'">
                    {{ attempt.next_retry_at
                      ? t('payroll.submissions.transport.automation.next_poll', {
                        at: formatUtcDateTime(attempt.next_retry_at),
                      })
                      : t('payroll.submissions.transport.automation.next_poll_unknown') }}
                  </li>
                  <li>
                    {{ t('payroll.submissions.transport.automation.polls', {
                      count: attempt.poll_count,
                    }) }}
                    <template v-if="attempt.last_polled_at">
                      {{ t('payroll.submissions.transport.automation.last_polled', {
                        at: formatUtcDateTime(attempt.last_polled_at),
                      }) }}
                    </template>
                  </li>
                  <li v-if="attempt.closed_at" :data-test="`transport-closed-${attempt.id}`">
                    {{ t('payroll.submissions.transport.automation.closed', {
                      at: formatUtcDateTime(attempt.closed_at),
                    }) }}
                  </li>
                  <li v-else-if="attempt.status === 'completed'">
                    {{ t('payroll.submissions.transport.automation.close_pending') }}
                  </li>
                </ul>
                <p
                  v-if="attempt.last_poll_error"
                  class="mt-2 text-xs text-warning-700"
                  :data-test="`transport-poll-error-${attempt.id}`"
                >
                  {{ t('payroll.submissions.transport.automation.last_error', {
                    message: attempt.last_poll_error,
                  }) }}
                </p>
                <p
                  v-if="attempt.close_error && !attempt.closed_at"
                  class="mt-2 text-xs text-warning-700"
                  :data-test="`transport-close-error-${attempt.id}`"
                >
                  {{ t('payroll.submissions.transport.automation.close_error', {
                    message: attempt.close_error,
                  }) }}
                </p>
              </div>

              <div
                v-if="attempt.error_code || attempt.error_message"
                :data-test="`transport-failure-${attempt.id}`"
                class="mt-3 rounded-lg border border-danger-500/30 bg-danger-50 p-3 text-sm text-danger-700"
                role="alert"
              >
                <p v-if="attempt.error_code" class="font-mono text-xs font-semibold">
                  {{ attempt.error_code }}
                </p>
                <p v-if="attempt.error_message" class="mt-1">{{ attempt.error_message }}</p>
              </div>

              <!--
                Požadavek odešel, odpověď nepřišla. Postup je tady celý: kde
                protokol hledat, jak ho načíst a teprve pak vědomé potvrzení
                opakování se stejným GUID.
              -->
              <PayrollPossiblyDeliveredNotice
                v-if="attempt.status === 'possibly_delivered'"
                class="mt-3"
                :environment="environment"
                :submission-id="attempt.submission_id"
                :error-code="attempt.error_code"
                :correlation-reference="attempt.correlation_reference"
                :can-write="canWrite"
                @import-protocol="pickProtocolFile"
                @confirmed="load()"
              />

              <p
                v-if="canReverify(attempt)"
                class="mt-3 rounded-lg border border-warning-500/30 bg-warning-50 p-3 text-sm text-warning-800"
                :data-test="`transport-unverified-note-${attempt.id}`"
              >
                {{ t('payroll.submissions.transport.reverify.note') }}
              </p>
              <div
                v-if="reverifications[attempt.id]"
                :data-test="`transport-reverify-result-${attempt.id}`"
                role="status"
                class="mt-3 rounded-lg border p-3 text-sm"
                :class="reverifications[attempt.id]!.verified
                  ? 'border-success-500/30 bg-success-50 text-success-700'
                  : 'border-danger-500/30 bg-danger-50 text-danger-700'"
              >
                <p>{{ reverifyMessage(reverifications[attempt.id]!) }}</p>
                <p
                  v-if="!reverifications[attempt.id]!.verified && reverifications[attempt.id]!.code"
                  class="mt-1 font-mono text-xs font-semibold"
                >
                  {{ reverifications[attempt.id]!.code }}
                </p>
              </div>

              <dl class="mt-3 grid grid-cols-1 gap-3 text-sm sm:grid-cols-2">
                <div class="sm:col-span-2">
                  <dt class="text-xs uppercase tracking-wide text-neutral-500">
                    {{ t('payroll.submissions.transport.correlation') }}
                  </dt>
                  <dd class="mt-0.5 flex flex-wrap items-center gap-2">
                    <span
                      class="break-all font-mono text-xs text-neutral-800"
                      :data-test="`transport-correlation-${attempt.id}`"
                    >
                      {{ attempt.correlation_reference
                        ?? t('payroll.submissions.transport.correlation_missing') }}
                    </span>
                    <button
                      v-if="attempt.correlation_reference"
                      type="button"
                      :data-test="`transport-copy-${attempt.id}`"
                      :class="btnOutlineSm('neutral')"
                      @click="copyCorrelation(attempt)"
                    >
                      <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path :d="ICONS.copy" />
                      </svg>
                      {{ copiedId === attempt.id
                        ? t('payroll.submissions.transport.copied')
                        : t('payroll.submissions.transport.copy') }}
                    </button>
                    <span
                      v-if="copyFailedId === attempt.id"
                      class="text-xs text-warning-700"
                      :data-test="`transport-copy-failed-${attempt.id}`"
                      role="status"
                    >
                      {{ t('payroll.submissions.transport.copy_failed') }}
                    </span>
                  </dd>
                </div>
                <div>
                  <dt class="text-xs uppercase tracking-wide text-neutral-500">
                    {{ t('payroll.submissions.transport.http_status') }}
                  </dt>
                  <dd class="mt-0.5 text-neutral-800">
                    {{ attempt.response_http_status ?? '—' }}
                  </dd>
                </div>
                <div>
                  <dt class="text-xs uppercase tracking-wide text-neutral-500">
                    {{ t('payroll.submissions.transport.completed_at') }}
                  </dt>
                  <dd class="mt-0.5 text-neutral-800">{{ formatUtcDateTime(attempt.completed_at) }}</dd>
                </div>
              </dl>

              <div
                v-if="polls[attempt.id]"
                :data-test="`transport-poll-result-${attempt.id}`"
                class="mt-4 space-y-3"
              >
                <p
                  v-if="polls[attempt.id]!.acknowledgement"
                  :data-test="`transport-acknowledgement-${attempt.id}`"
                  class="rounded-lg border border-warning-500/30 bg-warning-50 p-3 text-sm text-warning-800"
                >
                  {{ t('payroll.submissions.transport.acknowledged', {
                    seconds: polls[attempt.id]!.acknowledgement!.poll_interval_seconds ?? 0,
                  }) }}
                </p>

                <div
                  v-if="polls[attempt.id]!.report"
                  :data-test="`transport-report-${attempt.id}`"
                  class="rounded-lg border border-neutral-200 p-3"
                >
                  <p class="text-sm font-medium text-neutral-900">
                    {{ t(`payroll.submissions.transport.protocol_status.${polls[attempt.id]!.report!.status}`) }}
                  </p>
                  <p
                    v-if="polls[attempt.id]!.report!.errors.length === 0"
                    class="mt-2 text-sm text-neutral-600"
                  >
                    {{ t('payroll.submissions.transport.report.no_errors') }}
                  </p>
                  <ul v-else class="mt-3 space-y-3">
                    <li
                      v-for="(error, index) in polls[attempt.id]!.report!.errors"
                      :key="`${error.code}-${index}`"
                      :data-test="`transport-report-error-${attempt.id}-${index}`"
                      class="rounded-lg border border-danger-500/30 bg-danger-50 p-3 text-sm text-danger-700"
                    >
                      <div class="flex flex-wrap items-center gap-2">
                        <span class="font-mono text-xs font-semibold">{{ error.code }}</span>
                        <span class="rounded-full bg-neutral-100 px-2 py-0.5 text-xs font-medium text-neutral-700">
                          {{ t(`payroll.submissions.transport.origin.${error.origin}`) }}
                        </span>
                      </div>
                      <p class="mt-1 font-medium">{{ error.message }}</p>
                      <p
                        v-if="error.original_at_cssz"
                        class="mt-2 rounded-md border border-warning-300 bg-warning-50 p-2 text-xs text-warning-900"
                        :data-test="`transport-report-original-${attempt.id}-${index}`"
                      >
                        {{ t('payroll.transport_delivery.protocol_original_at_cssz') }}
                      </p>
                      <template v-if="error.control">
                        <p class="mt-2 text-neutral-800">{{ error.control.name }}</p>
                        <p v-if="error.control.detail" class="mt-1 text-xs text-neutral-600">
                          {{ error.control.detail }}
                        </p>
                        <p v-if="error.control.area" class="mt-1 text-xs text-neutral-600">
                          {{ t('payroll.submissions.transport.report.area', {
                            area: error.control.area,
                          }) }}
                        </p>
                        <div
                          v-if="error.control.attribute_ids.length"
                          class="mt-2 flex flex-wrap items-center gap-1"
                          :data-test="`transport-report-attributes-${attempt.id}-${index}`"
                        >
                          <span class="text-xs text-neutral-600">
                            {{ t('payroll.submissions.transport.report.attributes') }}
                          </span>
                          <span
                            v-for="attributeId in error.control.attribute_ids"
                            :key="attributeId"
                            class="rounded-full bg-neutral-100 px-2 py-0.5 font-mono text-xs text-neutral-700"
                          >
                            {{ attributeId }}
                          </span>
                        </div>
                      </template>
                      <p
                        v-else
                        class="mt-2 text-xs text-neutral-600"
                        :data-test="`transport-report-uncatalogued-${attempt.id}-${index}`"
                      >
                        {{ t('payroll.submissions.transport.report.control_unknown') }}
                      </p>
                      <JmhzProtocolErrorRemediation
                        v-if="error.remediation"
                        :remediation="error.remediation"
                        :test-id="`transport-report-remediation-${attempt.id}-${index}`"
                      />
                      <p v-if="errorLocation(error).length" class="mt-2 text-xs text-neutral-600">
                        {{ errorLocation(error).join(' · ') }}
                      </p>
                    </li>
                  </ul>
                </div>

                <p
                  v-else-if="!polls[attempt.id]!.acknowledgement"
                  class="rounded-lg border border-neutral-200 p-3 text-sm text-neutral-600"
                  :data-test="`transport-poll-inconclusive-${attempt.id}`"
                >
                  {{ t('payroll.submissions.transport.poll_inconclusive') }}
                </p>
              </div>
            </article>
          </div>
        </section>

        <section
          v-else-if="entry.source === 'imported'"
          :data-test="`transport-imported-${entry.protocol.id}`"
          :data-zone="entry.zone"
          :data-attached-to="entry.attachedTo ?? undefined"
          class="rounded-xl border border-neutral-200 bg-surface shadow-sm"
          :class="[
            entry.attachedTo !== null ? 'ml-4 sm:ml-8' : '',
            entry.zone === 'history' ? 'opacity-80' : '',
          ]"
        >
          <div class="flex flex-wrap items-start justify-between gap-3 border-b border-neutral-200 p-4 sm:p-6">
            <div>
              <h3 class="text-base font-semibold text-neutral-900">
                {{ entry.attachedTo !== null
                  ? t('payroll.submissions.transport.imported.attached', { id: entry.attachedTo })
                  : importedPeriodLabel(entry.protocol) }}
              </h3>
              <p class="mt-1 text-xs text-neutral-500">
                {{ t(`payroll.submissions.transport.imported.kind.${entry.protocol.protocol_kind}`) }}
                <template v-if="entry.protocol.source_filename">
                  · {{ entry.protocol.source_filename }}
                </template>
              </p>
            </div>
            <div class="flex flex-wrap items-center justify-end gap-2">
              <!-- Zdroj je vidět vždy: u načteného protokolu aplikace nezná
                   datovou větu, takže se nedá doptat na stav ani uzavřít
                   transakci — a tvářit se opačně by bylo horší než mlčet. -->
              <span
                class="rounded-full bg-neutral-100 px-2.5 py-1 text-xs font-medium text-neutral-700"
                :data-test="`transport-source-imported-${entry.protocol.id}`"
              >
                {{ t('payroll.submissions.transport.source.imported') }}
              </span>
              <span
                class="rounded-full px-2.5 py-1 text-xs font-semibold"
                :class="protocolTone(entry.protocol.status_name)"
                :data-test="`transport-imported-status-${entry.protocol.id}`"
              >
                {{ t(`payroll.submissions.transport.protocol_status.${entry.protocol.status_name}`) }}
              </span>
            </div>
          </div>

          <div class="p-4 sm:p-6">
            <p class="text-sm text-neutral-600" :data-test="`transport-imported-note-${entry.protocol.id}`">
              {{ t('payroll.submissions.transport.imported.note') }}
            </p>

            <dl class="mt-3 grid grid-cols-1 gap-3 text-sm sm:grid-cols-2">
              <div class="sm:col-span-2">
                <dt class="text-xs uppercase tracking-wide text-neutral-500">
                  {{ t('payroll.submissions.transport.imported.guid') }}
                </dt>
                <dd
                  class="mt-0.5 break-all font-mono text-xs text-neutral-800"
                  :data-test="`transport-imported-guid-${entry.protocol.id}`"
                >
                  {{ entry.protocol.submission_guid
                    ?? t('payroll.submissions.transport.imported.guid_missing') }}
                </dd>
              </div>
              <div>
                <dt class="text-xs uppercase tracking-wide text-neutral-500">
                  {{ t('payroll.submissions.transport.correlation') }}
                </dt>
                <dd class="mt-0.5 break-all font-mono text-xs text-neutral-800">
                  {{ entry.protocol.correlation_reference
                    ?? t('payroll.submissions.transport.correlation_missing') }}
                </dd>
              </div>
              <div>
                <dt class="text-xs uppercase tracking-wide text-neutral-500">
                  {{ t('payroll.submissions.transport.imported.status_code') }}
                </dt>
                <dd class="mt-0.5 text-neutral-800">{{ entry.protocol.status_code }}</dd>
              </div>
              <div>
                <dt class="text-xs uppercase tracking-wide text-neutral-500">
                  {{ t('payroll.submissions.transport.imported.protocol_dated_at') }}
                </dt>
                <dd class="mt-0.5 text-neutral-800">
                  {{ formatDateTime(entry.protocol.protocol_dated_at) }}
                </dd>
              </div>
              <div>
                <dt class="text-xs uppercase tracking-wide text-neutral-500">
                  {{ t('payroll.submissions.transport.imported.submitted_at') }}
                </dt>
                <dd class="mt-0.5 text-neutral-800">
                  {{ formatDateTime(entry.protocol.submitted_at) }}
                </dd>
              </div>
              <div>
                <dt class="text-xs uppercase tracking-wide text-neutral-500">
                  {{ t('payroll.submissions.transport.imported.error_count') }}
                </dt>
                <dd
                  class="mt-0.5 text-neutral-800"
                  :data-test="`transport-imported-error-count-${entry.protocol.id}`"
                >
                  {{ entry.protocol.error_count }}
                </dd>
              </div>
            </dl>

            <p
              v-if="entry.protocol.detail_available === false
                || protocolDetailAvailable[entry.protocol.id] === false"
              class="mt-3 rounded-lg border border-warning-500/30 bg-warning-50 p-3 text-sm text-warning-800"
              :data-test="`transport-imported-detail-missing-${entry.protocol.id}`"
            >
              {{ t('payroll.submissions.transport.imported.detail_unavailable', {
                total: entry.protocol.error_count,
              }) }}
            </p>

            <p
              v-else-if="entry.protocol.error_count === 0"
              class="mt-3 text-sm text-neutral-600"
              :data-test="`transport-imported-clean-${entry.protocol.id}`"
            >
              {{ t('payroll.submissions.transport.report.no_errors') }}
            </p>

            <template v-else>
              <button
                type="button"
                :class="[btnOutlineSm('neutral'), 'mt-3']"
                :disabled="protocolErrorsLoading[entry.protocol.id]"
                :data-test="`transport-imported-errors-toggle-${entry.protocol.id}`"
                @click="toggleProtocolErrors(entry.protocol)"
              >
                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                  <path :d="ICONS.search" />
                </svg>
                {{
                  protocolErrorsLoading[entry.protocol.id]
                    ? t('payroll.submissions.transport.imported.errors_loading')
                    : protocolErrorsOpen[entry.protocol.id]
                      ? t('payroll.submissions.transport.imported.errors_hide')
                      : t('payroll.submissions.transport.imported.errors_show', {
                        total: entry.protocol.error_count,
                      })
                }}
              </button>

              <p
                v-if="protocolErrorsFailed[entry.protocol.id]"
                class="mt-3 rounded-lg border border-danger-500/30 bg-danger-50 p-3 text-sm text-danger-700"
                role="alert"
                :data-test="`transport-imported-errors-failed-${entry.protocol.id}`"
              >
                {{ t('payroll.submissions.transport.imported.errors_failed') }}
              </p>

              <ul
                v-else-if="protocolErrorsOpen[entry.protocol.id]"
                class="mt-3 space-y-3"
              >
              <li
                v-for="(error, index) in protocolErrors[entry.protocol.id] ?? []"
                :key="`${error.code}-${index}`"
                :data-test="`transport-imported-error-${entry.protocol.id}-${index}`"
                class="rounded-lg border border-danger-500/30 bg-danger-50 p-3 text-sm text-danger-700"
              >
                <div class="flex flex-wrap items-center gap-2">
                  <span class="font-mono text-xs font-semibold">{{ error.code }}</span>
                  <span class="rounded-full bg-neutral-100 px-2 py-0.5 text-xs font-medium text-neutral-700">
                    {{ t(`payroll.submissions.transport.origin.${error.origin}`) }}
                  </span>
                </div>
                <p class="mt-1 font-medium">{{ error.message }}</p>
                <p
                  v-if="error.original_at_cssz"
                  class="mt-2 rounded-md border border-warning-300 bg-warning-50 p-2 text-xs text-warning-900"
                  :data-test="`transport-imported-original-${entry.protocol.id}-${index}`"
                >
                  {{ t('payroll.transport_delivery.protocol_original_at_cssz') }}
                </p>
                <template v-if="error.control">
                  <p class="mt-2 text-neutral-800">{{ error.control.name }}</p>
                  <p v-if="error.control.detail" class="mt-1 text-xs text-neutral-600">
                    {{ error.control.detail }}
                  </p>
                  <p v-if="error.control.area" class="mt-1 text-xs text-neutral-600">
                    {{ t('payroll.submissions.transport.report.area', {
                      area: error.control.area,
                    }) }}
                  </p>
                  <div
                    v-if="error.control.attribute_ids.length"
                    class="mt-2 flex flex-wrap items-center gap-1"
                  >
                    <span class="text-xs text-neutral-600">
                      {{ t('payroll.submissions.transport.report.attributes') }}
                    </span>
                    <span
                      v-for="attributeId in error.control.attribute_ids"
                      :key="attributeId"
                      class="rounded-full bg-neutral-100 px-2 py-0.5 font-mono text-xs text-neutral-700"
                    >
                      {{ attributeId }}
                    </span>
                  </div>
                </template>
                <p v-else class="mt-2 text-xs text-neutral-600">
                  {{ t('payroll.submissions.transport.report.control_unknown') }}
                </p>
                <JmhzProtocolErrorRemediation
                  v-if="error.remediation"
                  :remediation="error.remediation"
                  :test-id="`transport-imported-remediation-${entry.protocol.id}-${index}`"
                />
                <p v-if="errorLocation(error).length" class="mt-2 text-xs text-neutral-600">
                  {{ errorLocation(error).join(' · ') }}
                </p>
              </li>
              </ul>
            </template>
          </div>
        </section>
        </template>
      </template>

      <!--
        Dvě lišty, protože přehled slévá dva nezávislé seznamy: pokusy naší
        aplikace a protokoly načtené odjinud. Jeden společný stránkovač by
        musel lhát aspoň jednomu z nich.
      -->
      <div v-if="attemptsTotal > attemptsPageSize" class="space-y-1">
        <p class="text-xs font-medium uppercase tracking-wide text-neutral-500">
          {{ t('payroll.submissions.transport.source.app') }}
        </p>
        <PaginationBar
          :page="attemptsPage"
          :per-page="attemptsPageSize"
          :total="attemptsTotal"
          @update:page="goToAttemptsPage"
        />
      </div>
      <div v-if="importedTotal > importedPageSize" class="space-y-1">
        <p class="text-xs font-medium uppercase tracking-wide text-neutral-500">
          {{ t('payroll.submissions.transport.source.imported') }}
        </p>
        <PaginationBar
          :page="importedPage"
          :per-page="importedPageSize"
          :total="importedTotal"
          @update:page="goToImportedPage"
        />
      </div>
    </template>
    <ProductionSendConfirmDialog
      v-if="sendConfirmRequest"
      :message="sendConfirmRequest.message"
      @confirm="settleSendConfirm(true)"
      @cancel="settleSendConfirm(false)"
    />
  </section>
</template>

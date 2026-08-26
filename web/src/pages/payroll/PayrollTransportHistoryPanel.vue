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
  type PayrollJmhzContentCorrectionPreparation,
  type PayrollJmhzImportedProtocol,
  type PayrollJmhzIsdsEnqueueResult,
  type PayrollJmhzProtocolError,
  type PayrollJmhzReadySubmission,
  type PayrollJmhzTransportAttempt,
  type PayrollJmhzTransportEnvironment,
  type PayrollJmhzTransportPoll,
  type PayrollJmhzTransportStatus,
} from '@/api/payroll'
import { useAuthStore } from '@/stores/auth'
import PaginationBar from '@/components/ui/PaginationBar.vue'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import { btnFilled, btnOutline, btnOutlineSm, ICONS } from '@/components/ui/buttonStyles'

const { t } = useI18n()
const auth = useAuthStore()

const ENVIRONMENTS: PayrollJmhzTransportEnvironment[] = ['production', 'test']

const environment = defineModel<PayrollJmhzTransportEnvironment>('environment', {
  default: 'production',
})
const loading = ref(false)
const attempts = ref<PayrollJmhzTransportAttempt[]>([])
const readySubmissions = ref<PayrollJmhzReadySubmission[]>([])
const imported = ref<PayrollJmhzImportedProtocol[]>([])
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
const copiedId = ref<number | null>(null)
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
    const detail = await payrollApi.jmhzImportedProtocolErrors(id, environment.value)
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

const canWrite = computed(() => auth.canWrite('payroll.submissions'))
const busy = computed(() =>
  loading.value
  || importing.value
  || pollingId.value !== null
  || closingId.value !== null
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
}

/**
 * Seskupení podle podání se zachovaným pořadím: první výskyt určí místo
 * skupiny, pokusy uvnitř zůstanou tak, jak přišly ze serveru (od nejnovějšího).
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
      }
      byId.set(attempt.submission_id, group)
      ordered.push(group)
    }
    group.attempts.push(attempt)
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

const protocolErrorComponentGuids = computed(() => {
  const matched = new Set<string>()
  const group = correctionGroup.value
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
    created: preparation.created_at,
  }),
  secondary: t('payroll.submissions.transport.correction.preparation_period', {
    period: preparation.period_start,
  }),
})))

watch(selectedCorrectionGuids, () => {
  correctionImpactConfirmed.value = false
}, { deep: true })

type TimelineEntry =
  | { source: 'app'; key: string; sortKey: string; group: AttemptGroup }
  | { source: 'imported'; key: string; sortKey: string; protocol: PayrollJmhzImportedProtocol }

/**
 * Jeden chronologický přehled „co jsem podal", ať to odešlo odsud nebo odjinud.
 *
 * Řadí se podle OBDOBÍ hlášení, ne podle času založení řádku: uživatel hledá
 * „červenec", ne „to, co jsem načetl naposled". Období, které se nepodařilo
 * zjistit, jde na konec — ne nahoru, kde by vytlačilo to, co je vidět jasně.
 */
const timeline = computed<TimelineEntry[]>(() => {
  const entries: TimelineEntry[] = groups.value.map(group => ({
    source: 'app' as const,
    key: `app-${group.submissionId}`,
    sortKey: group.periodStart ?? '',
    group,
  }))
  for (const protocol of imported.value) {
    entries.push({
      source: 'imported' as const,
      key: `imported-${protocol.id}`,
      sortKey: protocol.period_year && protocol.period_month
        ? `${protocol.period_year}-${String(protocol.period_month).padStart(2, '0')}-01`
        : '',
      protocol,
    })
  }
  return entries.sort((a, b) => {
    if (a.sortKey === b.sortKey) return a.key < b.key ? 1 : -1
    if (a.sortKey === '') return 1
    if (b.sortKey === '') return -1
    return a.sortKey < b.sortKey ? 1 : -1
  })
})

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
function canPoll(attempt: PayrollJmhzTransportAttempt): boolean {
  return (attempt.correlation_reference ?? '') !== ''
}

/**
 * Uzavřít se smí až po dotažení protokolu. Dřív by se výsledek ztratil, a to
 * je nevratné — proto se tlačítko u ostatních stavů vůbec nenabízí.
 *
 * Uzavřenou transakci nabízet znovu nemá smysl: automatika ji uzavírá sama a
 * druhé uzavření by u ČSSZ byl dotaz na transakci, která už neexistuje.
 */
function canClose(attempt: PayrollJmhzTransportAttempt): boolean {
  return attempt.status === 'completed' && canPoll(attempt) && !attempt.closed_at
}

/**
 * Stornovat lze jen hlášení, které DOLOŽITELNĚ odešlo. Podání, které nikdy
 * neopustilo aplikaci, u ČSSZ neexistuje a rušit se u něj nemá co.
 */
function canCancel(group: AttemptGroup): boolean {
  return canWrite.value
    && group.submissionKind === 'regular'
    && ['accepted', 'partially_accepted'].includes(group.submissionStatus ?? '')
    && group.attempts.some(attempt => attempt.sent_at !== null)
}

/**
 * Druh O smí navázat až na konečný protokol. Samotné převzetí zprávy branou
 * nic neříká o tom, které součásti ČSSZ přijala, a výběr před výsledkem by byl
 * jen odhad. Definitivní způsobilost ještě ověří server nad stavem podání.
 */
function canCorrect(group: AttemptGroup): boolean {
  if (
    !canWrite.value
    || group.submissionKind !== 'regular'
    || !['accepted', 'partially_accepted'].includes(group.submissionStatus ?? '')
    || !group.attempts.some(attempt => attempt.status === 'completed')
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
    start: group.periodStart,
    end: group.periodEnd,
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
  try {
    await navigator.clipboard.writeText(value)
    copiedId.value = attempt.id
  } catch {
    // Schránka může být zakázaná politikou prohlížeče; text je vidět i tak.
    copiedId.value = null
  }
}

/** Nastavení zaměstnavatele zná variabilní symboly pracovišť — jinak se ptáme. */
async function loadVariableSymbols() {
  try {
    const settings = await payrollApi.employerSettings()
    const seen = new Map<string, string>()
    for (const office of settings.offices ?? []) {
      const symbol = (office.social_security_variable_symbol ?? '').trim()
      if (!office.is_active || symbol === '') continue
      if (!seen.has(symbol)) seen.set(symbol, `${office.code} — ${office.name}`)
    }
    variableSymbolOptions.value = [...seen].map(([value, label]) => ({ value, label }))
    // Předvyplní se jen jednoznačný případ. Víc různých symbolů znamená volbu,
    // a hádat ji za uživatele by znamenalo ptát se ČSSZ pod cizím symbolem.
    if (
      !variableSymbolTouched.value
      && variableSymbol.value === ''
      && variableSymbolOptions.value.length === 1
    ) {
      variableSymbol.value = variableSymbolOptions.value[0]!.value
    }
  } catch {
    // Nabídka je pohodlí, ne podmínka — pole na symbol zůstane k vyplnění ručně.
    variableSymbolOptions.value = []
  }
}

function useVariableSymbol(value: string) {
  variableSymbol.value = value
  variableSymbolTouched.value = true
}

async function load() {
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
      payrollApi.jmhzTransportHistory(environment.value, {
        limit: attemptsPageSize,
        offset: attemptsOffset.value,
      }),
      payrollApi.jmhzImportedProtocols(environment.value, {
        limit: importedPageSize,
        offset: importedOffset.value,
      }),
    ])
    attempts.value = history.attempts ?? []
    readySubmissions.value = history.ready_submissions ?? []
    attemptsTotal.value = history.total ?? 0
    imported.value = protocols.protocols ?? []
    importedTotal.value = protocols.total ?? 0
  } catch (exception: unknown) {
    // Stav zůstává NEZNÁMÝ, ne prázdný — šablona podle `loadError` skryje
    // prázdný stav i seznam, aby se selhání nedalo přečíst jako „nic neodešlo".
    attempts.value = []
    readySubmissions.value = []
    attemptsTotal.value = 0
    imported.value = []
    importedTotal.value = 0
    loadError.value = apiErrorMessage(
      exception,
      t('payroll.submissions.transport.load_failed'),
    )
  } finally {
    loading.value = false
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
    const result = await payrollApi.importJmhzProtocol(file, environment.value)
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
      environment.value,
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

function askToCancel(submissionId: number) {
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
      environment.value,
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
      environment.value,
    )
    correctableComponents.value = result.forms
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
      environment.value,
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
    const result = await payrollApi.cancelJmhzSubmission(submissionId, environment.value)
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
    start: submission.period_start,
    end: submission.period_end,
  })
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

  readyDispatchPending.value = { id: submission.submission_id, channel }
  actionError.value = ''
  success.value = ''
  try {
    if (channel === 'vrep') {
      await payrollApi.sendJmhzTransport(
        submission.submission_id,
        variableSymbol.value.trim(),
        environment.value,
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
      environment.value,
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
    actionError.value = apiErrorMessage(
      exception,
      t('payroll.submissions.transport.ready.dispatch_failed'),
    )
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
      environment.value,
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

      <div class="mt-5">
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
            {{ option.value }} — {{ option.label }}
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
                    : t('payroll.submissions.transport.ready.send_vrep') }}
                </button>
                <button
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
                class="mt-3"
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
        <template v-for="entry in timeline" :key="entry.key">
        <section
          v-if="entry.source === 'app'"
          :data-test="`transport-group-${entry.group.submissionId}`"
          class="rounded-xl border border-neutral-200 bg-surface shadow-sm"
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
              </p>
            </div>
            <div class="flex flex-wrap items-center justify-end gap-2">
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
              <button
                v-if="canCorrect(entry.group) && correctingId !== entry.group.submissionId"
                type="button"
                :data-test="`transport-correct-${entry.group.submissionId}`"
                :class="btnOutlineSm('warning')"
                :disabled="busy"
                @click="askToCorrect(entry.group.submissionId)"
              >
                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                  <path :d="ICONS.edit" />
                </svg>
                {{ t('payroll.submissions.transport.correction.action') }}
              </button>
              <button
                v-if="canCancel(entry.group) && cancellingId !== entry.group.submissionId"
                type="button"
                :data-test="`transport-cancel-${entry.group.submissionId}`"
                :class="btnOutlineSm('danger')"
                :disabled="busy"
                @click="askToCancel(entry.group.submissionId)"
              >
                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                  <path :d="ICONS.x" />
                </svg>
                {{ t('payroll.submissions.transport.storno.action') }}
              </button>
            </div>
          </div>

          <div
            v-if="correctingId === entry.group.submissionId"
            :data-test="`transport-correct-form-${entry.group.submissionId}`"
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
            <div
              v-if="correctionPreparationLoadingId === entry.group.submissionId"
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
                @click="loadContentCorrectionCandidates(entry.group.submissionId)"
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
              v-if="correctionLoadingId === entry.group.submissionId"
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
                :data-test="`transport-correct-submit-${entry.group.submissionId}`"
                :class="btnFilled('warning')"
                :disabled="busy
                  || correctionPreparationId === null
                  || selectedCorrectionGuids.length === 0
                  || !correctionImpactConfirmed"
                @click="confirmCorrection(entry.group.submissionId)"
              >
                {{ t('payroll.submissions.transport.correction.confirm') }}
              </button>
              <button
                type="button"
                :data-test="`transport-correct-abort-${entry.group.submissionId}`"
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
            v-if="cancellingId === entry.group.submissionId"
            :data-test="`transport-cancel-confirm-${entry.group.submissionId}`"
            class="border-b border-danger-500/30 bg-danger-50 p-4 sm:p-6"
            role="alert"
          >
            <p class="text-sm font-semibold text-danger-700">
              {{ t('payroll.submissions.transport.storno.confirm_title', {
                period: periodLabel(entry.group),
              }) }}
            </p>
            <p class="mt-1 text-sm text-danger-700">
              {{ t('payroll.submissions.transport.storno.confirm_text') }}
            </p>
            <div class="mt-3 flex flex-wrap gap-2">
              <button
                type="button"
                :data-test="`transport-cancel-submit-${entry.group.submissionId}`"
                :class="btnFilled('danger')"
                :disabled="busy"
                @click="confirmCancel(entry.group.submissionId)"
              >
                {{ t('payroll.submissions.transport.storno.confirm') }}
              </button>
              <button
                type="button"
                :data-test="`transport-cancel-abort-${entry.group.submissionId}`"
                :class="btnOutline('neutral')"
                :disabled="busy"
                @click="cancellingId = null"
              >
                {{ t('payroll.submissions.transport.storno.cancel') }}
              </button>
            </div>
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
                  <span class="text-xs text-neutral-500">
                    {{ attempt.sent_at
                      ? t('payroll.submissions.transport.sent_at', { at: attempt.sent_at })
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
                        at: attempt.next_retry_at,
                      })
                      : t('payroll.submissions.transport.automation.next_poll_unknown') }}
                  </li>
                  <li>
                    {{ t('payroll.submissions.transport.automation.polls', {
                      count: attempt.poll_count,
                    }) }}
                    <template v-if="attempt.last_polled_at">
                      {{ t('payroll.submissions.transport.automation.last_polled', {
                        at: attempt.last_polled_at,
                      }) }}
                    </template>
                  </li>
                  <li v-if="attempt.closed_at" :data-test="`transport-closed-${attempt.id}`">
                    {{ t('payroll.submissions.transport.automation.closed', {
                      at: attempt.closed_at,
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
                  <dd class="mt-0.5 text-neutral-800">{{ attempt.completed_at ?? '—' }}</dd>
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
          v-else
          :data-test="`transport-imported-${entry.protocol.id}`"
          class="rounded-xl border border-neutral-200 bg-surface shadow-sm"
        >
          <div class="flex flex-wrap items-start justify-between gap-3 border-b border-neutral-200 p-4 sm:p-6">
            <div>
              <h3 class="text-base font-semibold text-neutral-900">
                {{ importedPeriodLabel(entry.protocol) }}
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
                  {{ entry.protocol.protocol_dated_at ?? '—' }}
                </dd>
              </div>
              <div>
                <dt class="text-xs uppercase tracking-wide text-neutral-500">
                  {{ t('payroll.submissions.transport.imported.submitted_at') }}
                </dt>
                <dd class="mt-0.5 text-neutral-800">
                  {{ entry.protocol.submitted_at ?? '—' }}
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
  </section>
</template>

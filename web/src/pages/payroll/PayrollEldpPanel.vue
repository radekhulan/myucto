<script setup lang="ts">
/*
 * Evidenční list důchodového pojištění — VÝJIMKA, ne roční rutina.
 *
 * Od roku 2026 zaměstnavatel evidenční list nevyhotovuje ani nepředkládá:
 * údaje pro důchodové pojištění sděluje jednotným měsíčním hlášením a list
 * z nich sestaví ČSSZ (§ 38 odst. 1 a 2 zákona č. 582/1991 Sb. ve znění
 * zák. č. 360/2025 Sb.); zaměstnanci je dostupný na ePortálu (§ 39 odst. 1).
 * Panel proto NEVEDE nikoho k tomu, aby „odbavil ELDP za loňský rok" —
 * přípustnost si vyžádá od serveru dřív, než dá vyplnit potvrzení, a když
 * povinnost nevznikla, řekne to a přípravu nedovolí.
 *
 * Žádné tlačítko tady neodesílá — lokální podání se zastaví ve stavu
 * „připraveno" a nabízí jen kontrolní XML. Člověk podání dokončí v oficiálním
 * rozhraní ČSSZ a tady následně uloží jen doložený výsledek z firemního DMS.
 */
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import { eldpRemediation, type EldpBlocker } from './payrollRemediation'
import { isAxiosError } from 'axios'
import { useI18n } from 'vue-i18n'
import { personalNumberLabel } from './employmentLifecycleUi'
import { documentsApi, type DocItem } from '@/api/documents'
import {
  payrollApi,
  type PayrollEldpAuthorityStatus,
  type PayrollEldpEligibility,
  type PayrollEldpManualCompletionOverview,
  type PayrollEldpPrepared,
  type PayrollEldpStatement,
  type PayrollEmployment,
  type PayrollPensionRequest,
  type PayrollRegzelEnvironment,
} from '@/api/payroll'
import { useAuthStore } from '@/stores/auth'
import PayrollPersonSearchSelect from '@/components/payroll/PayrollPersonSearchSelect.vue'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import EnvironmentSwitch from '@/components/ui/EnvironmentSwitch.vue'
import { useSubmissionEnvironment } from '@/composables/useSubmissionEnvironment'
import { btnFilled, btnOutline, ICONS } from '@/components/ui/buttonStyles'
import { formatDate } from '@/composables/useFormat'
import DateInput from '@/components/ui/DateInput.vue'

const { t } = useI18n()
const auth = useAuthStore()
const route = useRoute()

const preparing = ref(false)
const downloading = ref(false)
const employments = ref<PayrollEmployment[]>([])
const personId = ref<number | null>(null)
const employmentId = ref<number | null>(null)
/*
 * Poslední rok, za který zaměstnavatel evidenční list vyhotovoval za celý
 * kalendářní rok. Zrcadlí `EldpDeadlinePolicy::LAST_ANNUAL_YEAR` — server je
 * jediná autorita, ale výchozí rok se musí zvolit dřív, než dorazí odpověď.
 * Bez téhle meze by panel od roku 2027 sám předvyplňoval „loni" a znovu tak
 * nabízel roční povinnost, která od roku 2026 neexistuje.
 */
const LAST_ANNUAL_ELDP_YEAR = 2025
const year = ref<number>(
  Math.min(new Date().getFullYear() - 1, LAST_ANNUAL_ELDP_YEAR),
)
const environment = defineModel<PayrollRegzelEnvironment>('environment', {
  default: 'production',
})
const { testAllowed: submissionTestAllowed } = useSubmissionEnvironment(environment)
const excludedDaysConfirmed = ref(false)
const deathOn = ref('')
const requestedByAuthority = ref(false)
const authorityRequestReceivedOn = ref('')
const authorityRequestDueOn = ref('')
/*
 * Od roku 2027 lhůtu pro list na výzvu neurčuje zákon, ale výzva. Zrcadlí
 * `EldpDeadlinePolicy` (LAST_ANNUAL_YEAR + 2); server je autorita a pole bez
 * hodnoty odmítne srozumitelnou chybou.
 */
const authorityDueOnRequired = computed(() =>
  requestedByAuthority.value && year.value >= LAST_ANNUAL_ELDP_YEAR + 2)
/*
 * Důchodové údaje zmrazená revize nenese, a přitom na nich stojí kód ELDP
 * (D od dovršení důchodového věku nebo předčasného důchodu) i to, zda se list
 * za poživatele plného starobního důchodu vůbec vede. Potvrzení je výslovné
 * i tehdy, když nic z toho nenastalo.
 */
const pensionConfirmed = ref(false)
const pensionAgeReachedOn = ref('')
const earlyPensionFrom = ref('')
const fullPensionPaidFrom = ref('')
const foreignInsurance = ref(false)
const note = ref('')
/*
 * Opravný evidenční list. Zmrazený list se nepřepisuje; změněný podklad jde
 * jako nový list s odkazem na opravovaný. Nabízí se jen tam, kde už nějaký
 * list za rok a vztah zmrazený je — jinak není co opravovat.
 */
const correction = ref(false)
const preparedOn = ref('')
const statement = ref<PayrollEldpStatement | null>(null)
const eligibility = ref<PayrollEldpEligibility | null>(null)
const prepared = ref<PayrollEldpPrepared | null>(null)
const blockers = ref<EldpBlocker[]>([])
const error = ref('')
const success = ref('')
const downloadError = ref('')
const manualCompletion = ref<PayrollEldpManualCompletionOverview | null>(null)
const authorityStatus = ref<PayrollEldpAuthorityStatus>('submitted')
const confirmationDocumentQuery = ref('')
const confirmationDocuments = ref<DocItem[]>([])
const confirmationDocument = ref<DocItem | null>(null)
const authorityReference = ref('')
const confirmedOn = ref(new Date().toLocaleDateString('sv-SE'))
const completing = ref(false)
const completionError = ref('')
const completionSuccess = ref('')
/*
 * Výzva z evidence výzev u osoby (`?pension_request=`). Údaje výzvy (žadatel,
 * doručení, lhůta, úmrtí) se do formuláře převezmou a server je při přípravě
 * stejně vezme ze zapsané výzvy, aby list a výzva měly tentýž termín.
 */
const pensionRequest = ref<PayrollPensionRequest | null>(null)
let employmentsLoad: Promise<void> = Promise.resolve()

const canWrite = computed(() => auth.canWrite('payroll.submissions'))
const canReadDocuments = computed(() => auth.canRead('documents'))
const hasAcceptedEvidence = computed(() => manualCompletion.value?.evidence
  .some(item => item.authority_status === 'accepted') ?? false)
const hasSelectedStatusEvidence = computed(() => manualCompletion.value?.evidence
  .some(item => item.authority_status === authorityStatus.value) ?? false)
const canComplete = computed(() =>
  canWrite.value
  && canReadDocuments.value
  && !completing.value
  && statement.value !== null
  && manualCompletion.value !== null
  && confirmationDocument.value !== null
  && authorityReference.value.trim().length >= 1
  && authorityReference.value.trim().length <= 190
  && /^\d{4}-\d{2}-\d{2}$/.test(confirmedOn.value)
  && !hasAcceptedEvidence.value
  && !hasSelectedStatusEvidence.value)
const employmentOptions = computed(() =>
  employments.value.map(employment => ({
    value: employment.id,
    label: employment.end_date
      ? `${personalNumberLabel(t, employment.code) || '—'} (${employment.start_date ?? '?'} – ${employment.end_date})`
      : `${personalNumberLabel(t, employment.code) || '—'} (${employment.start_date ?? '?'})`,
  })))
const yearOptions = computed(() => {
  const current = new Date().getFullYear()
  return Array.from({ length: 6 }, (_, index) => current - index)
    .map(value => ({ value, label: String(value) }))
})
/*
 * Fail-closed: dokud server nepotvrdí, že samostatný evidenční list za tenhle
 * rozsah vůbec vzniká, příprava se nenabízí. Jedinou cestou přes zákaz je
 * výzva ČSSZ/ÚSSZ (§ 38a odst. 2 a 3), a tu uživatel dokládá datem doručení —
 * není to odklikávací výjimka, ale skutečná událost.
 */
const standaloneAllowed = computed(() =>
  eligibility.value !== null
  && (eligibility.value.allowed || requestedByAuthority.value))
/* Roční rutina existuje jen pro období před rokem 2026; jinde je to výjimka. */
const isRoutineYear = computed(() => eligibility.value?.routine === true)
/*
 * Obě potvrzení musí padnout výslovně. Vyloučené doby mění osobní vyměřovací
 * základ a odečítané doby po dosažení důchodového věku modul neumí odvodit —
 * proto je nula podmíněná potvrzením, ne výpočtem.
 */
const canPrepare = computed(() =>
  canWrite.value
  && !preparing.value
  && employmentId.value !== null
  && standaloneAllowed.value
  && excludedDaysConfirmed.value
  && pensionConfirmed.value
  && (!requestedByAuthority.value || authorityRequestReceivedOn.value !== '')
  && (!authorityDueOnRequired.value || authorityRequestDueOn.value !== '')
  && note.value.trim().length <= 500)

/**
 * Co ještě chybí, aby šlo připravit.
 *
 * Tlačítko viselo zhasnuté nad formulářem o šesti polích a neřeklo které.
 * Přípustnost (`standaloneAllowed`) tady schválně není — ta má vlastní panel
 * nad formulářem a opakovat ji u tlačítka by znamenalo říct dvakrát totéž.
 *
 * Poznámka NENÍ podmínka. ČSSZ ji nepřijímá, do XML se nedostane a byla to
 * jen naše evidence — držet kvůli ní zhasnuté tlačítko znamenalo blokovat
 * zákonnou povinnost kvůli internímu zápisu. Předvyplňuje se z důvodu
 * přípustnosti (viz `noteSuggestion`), takže doložení výjimky nezmizí, jen
 * přestalo být překážkou. Zbývá horní mez, aby se text vešel do sloupce.
 */
const prepareBlockers = computed<string[]>(() => {
  if (!canWrite.value) return [t('payroll.eldp.blockers.readOnly')]
  const missing: string[] = []
  if (employmentId.value === null) missing.push(t('payroll.eldp.blockers.employment'))
  if (!excludedDaysConfirmed.value) missing.push(t('payroll.eldp.blockers.excluded'))
  if (!pensionConfirmed.value) missing.push(t('payroll.eldp.blockers.pension'))
  if (requestedByAuthority.value && authorityRequestReceivedOn.value === '') {
    missing.push(t('payroll.eldp.blockers.authorityDate'))
  }
  if (authorityDueOnRequired.value && authorityRequestDueOn.value === '') {
    missing.push(t('payroll.eldp.blockers.authorityDueOn'))
  }
  if (note.value.trim().length > 500) missing.push(t('payroll.eldp.blockers.noteTooLong'))
  return missing
})

/** Totéž pro doložení výsledku — i tam viselo tlačítko zhasnuté beze slova. */
const completeBlockers = computed<string[]>(() => {
  if (!canWrite.value || !canReadDocuments.value) {
    return [t('payroll.eldp.manual.permissionRequired')]
  }
  const missing: string[] = []
  if (confirmationDocument.value === null) missing.push(t('payroll.eldp.blockers.document'))
  const reference = authorityReference.value.trim()
  if (reference === '') missing.push(t('payroll.eldp.blockers.reference'))
  else if (reference.length > 190) missing.push(t('payroll.eldp.blockers.referenceTooLong'))
  if (!/^\d{4}-\d{2}-\d{2}$/.test(confirmedOn.value)) {
    missing.push(t('payroll.eldp.blockers.confirmedOn'))
  }
  if (hasSelectedStatusEvidence.value) missing.push(t('payroll.eldp.blockers.alreadyRecorded'))
  return missing
})

async function loadEmployments(id: number): Promise<void> {
  employments.value = []
  employmentId.value = null
  try {
    const person = await payrollApi.person(id)
    employments.value = person.employments
    if (employments.value.length === 1) {
      employmentId.value = employments.value[0].id
    }
  } catch {
    error.value = t('payroll.eldp.errors.loadFailed')
  }
}

/**
 * Důvod, proč list vzniká, zná server (`eligibility.reason`) dřív, než ho
 * účetní stihne opsat. Poznámka se proto předvyplní z něj — je to jediná
 * hodnota, kterou by účetní stejně jen přepsala z panelu o kus výš.
 *
 * Přepisuje se jen prázdné pole a dřívější návrh; jakmile do poznámky někdo
 * napsal vlastní text, další načtení mu ho nesmí přemazat.
 */
const suggestedNote = ref('')

/*
 * Rok přechodu z jiného mzdového programu. Převzatý měsíc není výsledek
 * výpočtu MyÚčta, a evidenční list jde na ČSSZ — takže se to nesmí schovat
 * do souhrnu. Panel vypisuje, které měsíce jsou převzaté, odkud, a s jakým
 * otiskem řádku jdou do zmrazeného podkladu.
 */
interface EldpTakeoverSource {
  period_start: string
  source: string
  row_sha256: string
}

function isTakeoverSource(value: unknown): value is EldpTakeoverSource {
  const item = value as Partial<EldpTakeoverSource> | null
  return typeof item?.period_start === 'string'
    && typeof item.source === 'string'
    && typeof item.row_sha256 === 'string'
}

const takeoverSources = computed<EldpTakeoverSource[]>(() => {
  const value = statement.value?.payload?.source_takeovers
  return Array.isArray(value) ? value.filter(isTakeoverSource) : []
})
const takeoverOverriddenPeriods = computed<string[]>(() => {
  const value = statement.value?.payload?.takeover_overridden_periods
  return Array.isArray(value)
    ? value.filter((item): item is string => typeof item === 'string').map(monthLabel)
    : []
})

function monthLabel(periodStart: string): string {
  return periodStart.slice(0, 7)
}

/*
 * Údaje tiskopisu, které kontrolní XML (`eldpType` JMHZ) nenese: typ listu,
 * „zaměstnán od", datum vyhotovení a měsíce „X". Starší zmrazené listy je ve
 * snapshotu nemají, a pak se přehled neukazuje.
 */
interface EldpFormSheet {
  eldp_type: string
  employed_from: string
  prepared_on: string
  corrects: { statement_id: number } | null
}

interface EldpFormSection {
  code: string
  valid_from: string | null
  valid_to: string | null
  insurance_days: number
  assessment_base_czk: number
  months_without_insurance: number[]
  whole_year_without_insurance?: boolean
  small_scale?: boolean
}

const formSheet = computed<EldpFormSheet | null>(() => {
  const value = statement.value?.payload?.form as Partial<EldpFormSheet> | undefined
  return typeof value?.eldp_type === 'string'
    && typeof value.employed_from === 'string'
    && typeof value.prepared_on === 'string'
    ? value as EldpFormSheet
    : null
})

const formSections = computed<EldpFormSection[]>(() => {
  const value = statement.value?.payload?.eldp_sections
  return Array.isArray(value)
    ? value.filter((item): item is EldpFormSection =>
      typeof (item as Partial<EldpFormSection> | null)?.code === 'string')
    : []
})

function sectionPeriod(section: EldpFormSection): string {
  if (section.valid_from === null || section.valid_to === null) {
    return t('payroll.eldp.formSheet.postTermination')
  }
  return `${formatDate(section.valid_from)} – ${formatDate(section.valid_to)}`
}

function monthsX(section: EldpFormSection): string {
  // Celý rok bez pojištění se vyznačuje X ve třináctém prostoru „1-12", ne dvanácti X.
  if (section.whole_year_without_insurance === true) return '1-12'
  const months = Array.isArray(section.months_without_insurance)
    ? section.months_without_insurance
    : []
  return months.length ? months.join(', ') : t('payroll.eldp.formSheet.none')
}

function takeoverSourceLabel(code: string): string {
  return t(`payroll.migration_reconciliation.source_name.${code}`)
}

function applyNoteSuggestion(): void {
  const reason = eligibility.value?.reason?.trim() ?? ''
  if (reason === '') return
  const suggestion = reason.slice(0, 500)
  if (note.value.trim() !== '' && note.value !== suggestedNote.value) return
  note.value = suggestion
  suggestedNote.value = suggestion
}

async function loadStatement(): Promise<void> {
  statement.value = null
  eligibility.value = null
  error.value = ''
  if (employmentId.value === null) {
    return
  }
  try {
    const response = await payrollApi.eldpStatement({
      employment_id: employmentId.value,
      year: year.value,
      environment: environment.value,
    })
    statement.value = response.statement
    // Fail-closed i proti starší odpovědi bez přípustnosti: bez ní se příprava
    // nenabízí, protože bychom nevěděli, jestli povinnost vůbec vznikla.
    eligibility.value = response.eligibility ?? null
    manualCompletion.value = response.manual_completion
    applyNoteSuggestion()
  } catch (exception) {
    // Fail-closed ANO, ale ne mlčky. Dokud se chyba zahazovala, obrazovka po
    // výběru vztahu jen zhasla: žádný přehled, tlačítko Připravit zhasnuté,
    // nikde ani slovo proč. Účetní z toho četla „za tenhle rok nic není",
    // což je u nedoručeného evidenčního listu nejdražší možný omyl.
    statement.value = null
    eligibility.value = null
    manualCompletion.value = null
    error.value = isAxiosError(exception)
      && typeof exception.response?.data?.error?.message === 'string'
      ? exception.response.data.error.message
      : t('payroll.eldp.errors.statementLoadFailed')
  }
}

async function searchConfirmationDocuments(): Promise<void> {
  const query = confirmationDocumentQuery.value.trim()
  if (query.length < 2) {
    confirmationDocuments.value = []
    return
  }
  completionError.value = ''
  try {
    confirmationDocuments.value = (await documentsApi.search(query))
      .filter(document => document.scope !== 'user' && document.deleted_at === null)
  } catch {
    confirmationDocuments.value = []
    completionError.value = t('payroll.eldp.manual.errors.documentSearchFailed')
  }
}

function chooseConfirmationDocument(document: DocItem): void {
  confirmationDocument.value = document
  confirmationDocumentQuery.value = document.title
  confirmationDocuments.value = []
}

function clearConfirmationDocument(): void {
  confirmationDocument.value = null
  confirmationDocumentQuery.value = ''
  confirmationDocuments.value = []
}

async function completeManually(): Promise<void> {
  if (!canComplete.value || statement.value === null || manualCompletion.value === null
    || confirmationDocument.value === null
  ) return
  /*
   * Dialog tady zůstává: doložením se tvrdí, co se stalo VENKU, u ČSSZ.
   * Aplikace to nemůže vzít zpět — evidenci nelze smazat a nepravdivé doložení
   * by prohlásilo povinnost za splněnou. Musí ale říct, čeho se týká: panel se
   * přepíná mezi vztahy i roky a doložení u špatného ELDP vypadá při obecné
   * otázce stejně jako u správného.
   */
  const employment = employmentOptions.value
    .find(option => option.value === employmentId.value)
  if (!window.confirm(t('payroll.eldp.manual.confirmFor', {
    question: t(`payroll.eldp.manual.confirm.${authorityStatus.value}`),
    employment: employment?.label ?? t('payroll.eldp.manual.employmentUnknown'),
    year: year.value,
  }))) return

  completing.value = true
  completionError.value = ''
  completionSuccess.value = ''
  try {
    const result = await payrollApi.completeEldp(statement.value.id, {
      environment: environment.value,
      expected_obligation_row_version: manualCompletion.value.obligation_row_version,
      authority_status: authorityStatus.value,
      confirmation_document_id: confirmationDocument.value.id,
      authority_reference: authorityReference.value.trim(),
      confirmed_on: confirmedOn.value,
      idempotency_key: `eldp-manual:${environment.value}:${statement.value.id}:${authorityStatus.value}`,
    })
    completionSuccess.value = t(`payroll.eldp.manual.saved.${result.authority_status}`)
    clearConfirmationDocument()
    authorityReference.value = ''
    await loadStatement()
  } catch (exception) {
    if (isAxiosError(exception)) {
      const payload = exception.response?.data?.error
      completionError.value = typeof payload?.message === 'string'
        ? payload.message
        : t('payroll.eldp.manual.errors.saveFailed')
    } else {
      completionError.value = t('payroll.eldp.manual.errors.saveFailed')
    }
  } finally {
    completing.value = false
  }
}

async function prepare(): Promise<void> {
  if (employmentId.value === null || !canPrepare.value) {
    return
  }
  preparing.value = true
  error.value = ''
  success.value = ''
  blockers.value = []
  try {
    prepared.value = await payrollApi.prepareEldp({
      employment_id: employmentId.value,
      year: year.value,
      environment: environment.value,
      excluded_days_confirmed: excludedDaysConfirmed.value,
      death_on: deathOn.value !== '' ? deathOn.value : null,
      requested_by_authority: requestedByAuthority.value,
      authority_request_received_on: requestedByAuthority.value
        ? authorityRequestReceivedOn.value
        : null,
      authority_request_due_on: requestedByAuthority.value && authorityRequestDueOn.value !== ''
        ? authorityRequestDueOn.value
        : null,
      pension_status: {
        pension_age_reached_on: pensionAgeReachedOn.value || null,
        early_pension_from: earlyPensionFrom.value || null,
        full_pension_paid_from: fullPensionPaidFrom.value || null,
        foreign_insurance: foreignInsurance.value,
      },
      note: note.value.trim(),
      // Opravný list je nový požadavek: pod klíčem řádného listu by ho server
      // odmítl jako jiné potvrzení téhož požadavku.
      idempotency_key: correction.value && statement.value
        ? `eldp-correction:${environment.value}:${employmentId.value}:${year.value}:${statement.value.id}`
        : `eldp:${environment.value}:${employmentId.value}:${year.value}`,
      correction: correction.value && statement.value !== null,
      prepared_on: preparedOn.value !== '' ? preparedOn.value : null,
      pension_request_id: pensionRequest.value !== null
        && pensionRequest.value.employment_id === employmentId.value
        && pensionRequest.value.period_year === year.value
        ? pensionRequest.value.id
        : null,
    })
    success.value = prepared.value.created
      ? (prepared.value.corrects_statement_id
        ? t('payroll.eldp.correction.created')
        : t('payroll.eldp.preparedCreated'))
      : t('payroll.eldp.preparedReplayed')
    correction.value = false
    await loadStatement()
  } catch (exception) {
    if (isAxiosError(exception)) {
      const payload = exception.response?.data?.error
      error.value = t('payroll.eldp.errors.prepareFailed')
      blockers.value = Array.isArray(payload?.blockers) && payload.blockers.length
        ? payload.blockers
        : [{ code: typeof payload?.code === 'string' ? payload.code : 'unknown', message: typeof payload?.message === 'string' ? payload.message : '' }]
    } else {
      error.value = t('payroll.eldp.errors.prepareFailed')
    }
  } finally {
    preparing.value = false
  }
}

const copyDownloading = ref(false)
const copyError = ref('')

async function downloadCopy(): Promise<void> {
  if (employmentId.value === null || copyDownloading.value) return
  copyDownloading.value = true
  copyError.value = ''
  try {
    await payrollApi.downloadEldpCopy({
      employment_id: employmentId.value,
      year: year.value,
      environment: environment.value,
    })
  } catch {
    copyError.value = t('payroll.eldp.copy.failed')
  } finally {
    copyDownloading.value = false
  }
}

async function downloadControlXml(): Promise<void> {
  if (!prepared.value || downloading.value) return
  downloading.value = true
  downloadError.value = ''
  try {
    const detail = await payrollApi.submissionDetail(prepared.value.submission_id)
    const artifact = detail.artifacts.find(item => item.id === prepared.value?.artifact_id)
    if (!artifact) {
      throw new Error('ELDP artifact is missing.')
    }
    await payrollApi.downloadSubmissionArtifact(prepared.value.submission_id, artifact)
  } catch {
    downloadError.value = t('payroll.eldp.errors.downloadFailed')
  } finally {
    downloading.value = false
  }
}

watch(personId, value => {
  if (value !== null) {
    employmentsLoad = loadEmployments(value)
  } else {
    employments.value = []
    employmentId.value = null
  }
})
watch([employmentId, year, environment], () => {
  prepared.value = null
  correction.value = false
  preparedOn.value = ''
  deathOn.value = ''
  manualCompletion.value = null
  completionError.value = ''
  completionSuccess.value = ''
  clearConfirmationDocument()
  blockers.value = []
  void loadStatement()
})
/** Předvyplnění z prokliku z evidence výzev nebo z nápravy blokátoru. */
async function applyRouteQuery(): Promise<void> {
  const query = route?.query ?? {}
  const person = Number(query.person)
  const employment = Number(query.employment)
  const queryYear = Number(query.year)
  const requestId = Number(query.pension_request)
  if (Number.isInteger(person) && person > 0) {
    personId.value = person
    await nextTick()
    await employmentsLoad
  }
  if (Number.isInteger(employment) && employment > 0) employmentId.value = employment
  if (Number.isInteger(queryYear) && queryYear >= 2000) year.value = queryYear
  await nextTick()
  if (!Number.isInteger(requestId) || requestId <= 0 || personId.value === null) return
  try {
    const found = (await payrollApi.pensionRequests(personId.value))
      .find(request => request.id === requestId && request.request_kind === 'eldp') ?? null
    pensionRequest.value = found
    if (found === null) return
    requestedByAuthority.value = found.requester === 'cssz' || found.requester === 'ossz'
    authorityRequestReceivedOn.value = requestedByAuthority.value ? found.received_on : ''
    authorityRequestDueOn.value = found.stated_due_on ?? ''
    deathOn.value = found.death_on ?? ''
  } catch {
    pensionRequest.value = null
  }
}

onMounted(() => { void applyRouteQuery() })

watch(requestedByAuthority, value => {
  if (value && authorityRequestReceivedOn.value === '') {
    const today = new Date()
    authorityRequestReceivedOn.value = [
      today.getFullYear(),
      String(today.getMonth() + 1).padStart(2, '0'),
      String(today.getDate()).padStart(2, '0'),
    ].join('-')
  }
})
</script>

<template>
  <div class="space-y-4" data-test="eldp-panel">
    <div class="rounded-xl border border-neutral-200 bg-surface p-4 text-sm text-neutral-700">
      <h3 class="text-base font-semibold text-neutral-900">
        {{ t('payroll.eldp.title') }}
      </h3>
      <p class="mt-1 max-w-prose">
        {{ t('payroll.eldp.intro') }}
      </p>
      <ul class="mt-2 max-w-prose list-disc space-y-1 pl-5 text-sm">
        <li>{{ t('payroll.eldp.exceptions.beforeTwentySix') }}</li>
        <li>{{ t('payroll.eldp.exceptions.endedBeforeApril') }}</li>
        <li>{{ t('payroll.eldp.exceptions.authorityRequest') }}</li>
      </ul>
      <p class="mt-2 max-w-prose text-xs text-neutral-500">
        {{ t('payroll.eldp.legalBasis') }}
      </p>
    </div>

    <!--
      Stav přípustnosti stojí nad formulářem, ne pod tlačítkem: kdyby se
      obsluha dozvěděla až z chyby po vyplnění potvrzení, že povinnost
      nevznikla, naučí se hlášku odklikávat jako překážku místo číst ji
      jako pravidlo.
    -->
    <div
      v-if="eligibility && !standaloneAllowed"
      data-test="eldp-not-applicable"
      class="rounded-xl border border-warning-500/30 bg-warning-50 p-4 text-sm text-warning-800"
      role="status"
    >
      <p class="font-medium">{{ t('payroll.eldp.notApplicable.title') }}</p>
      <p class="mt-1 max-w-prose">{{ eligibility.reason }}</p>
      <p v-if="eligibility.authority_request_available" class="mt-2 max-w-prose">
        {{ t('payroll.eldp.notApplicable.authorityHint') }}
      </p>
    </div>
    <div
      v-else-if="eligibility && !isRoutineYear"
      data-test="eldp-exception"
      class="rounded-xl border border-neutral-200 bg-neutral-50 p-4 text-sm text-neutral-700"
      role="status"
    >
      <p class="font-medium">{{ t('payroll.eldp.exceptionOnly.title') }}</p>
      <p class="mt-1 max-w-prose">{{ eligibility.reason }}</p>
    </div>

    <div
      v-if="error"
      data-test="eldp-error"
      class="rounded-xl border border-danger-500/30 bg-danger-50 p-4 text-sm text-danger-700"
      role="alert"
    >
      {{ error }}
      <ul v-if="blockers.length" class="mt-2 list-disc space-y-1 pl-5">
        <li v-for="blocker in blockers" :key="blocker.code" data-test="eldp-blocker">
          <div data-test="eldp-guidance">
            <p class="font-medium">{{ t(eldpRemediation(blocker, employmentId, year).problemKey) }}</p>
            <p class="mt-1">{{ t(eldpRemediation(blocker, employmentId, year).stepKey) }}</p>
            <p class="mt-1 text-xs">{{ t('payroll.remediation.eldp.period', { period: eldpRemediation(blocker, employmentId, year).period ?? String(year) }) }}</p>
            <RouterLink
              v-if="eldpRemediation(blocker, employmentId, year).path !== null"
              :to="eldpRemediation(blocker, employmentId, year).path!"
              :class="[btnOutline('warning'), 'mt-2 whitespace-nowrap']"
            >
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.edit" /></svg>
              {{ t(eldpRemediation(blocker, employmentId, year).actionKey) }}
            </RouterLink>
          </div>
          <details class="mt-2 text-xs"><summary>{{ t('payroll.remediation.technical') }}</summary><p>{{ blocker.code }}</p><p>{{ blocker.message }}</p></details>
        </li>
      </ul>
    </div>

    <div
      v-if="success"
      data-test="eldp-success"
      class="rounded-xl border border-success-500/30 bg-success-50 p-4 text-sm text-success-700"
      role="status"
    >
      {{ success }}
    </div>

    <div
      v-if="pensionRequest"
      data-test="eldp-pension-request"
      class="rounded-xl border border-primary-500/30 bg-primary-50 p-4 text-sm text-primary-800"
      role="status"
    >
      <p class="font-medium">{{ t('payroll.eldp.fromRequest.title') }}</p>
      <p class="mt-1 max-w-prose">
        {{ t('payroll.eldp.fromRequest.body', {
          requester: t(`payroll.people.pension_requests.requester.${pensionRequest.requester}`),
          received: formatDate(pensionRequest.received_on),
          due: formatDate(pensionRequest.due_on),
        }) }}
        <template v-if="pensionRequest.requester_reference"> ({{ pensionRequest.requester_reference }})</template>
      </p>
      <p class="mt-1 max-w-prose text-xs">{{ t('payroll.eldp.fromRequest.hint') }}</p>
    </div>

    <div class="space-y-4 rounded-xl border border-neutral-200 bg-surface p-4">
      <div class="grid gap-4 sm:grid-cols-2">
        <div class="block text-sm">
          <span class="mb-1 block font-medium text-neutral-700">
            {{ t('payroll.eldp.person') }}
          </span>
          <PayrollPersonSearchSelect
            v-model="personId"
            data-test="eldp-person"
            :label="t('payroll.eldp.person')"
            :clearable="false"
          />
        </div>
        <label class="block text-sm">
          <span class="mb-1 block font-medium text-neutral-700">
            {{ t('payroll.eldp.employment') }}
          </span>
          <SearchableSelect v-model="employmentId" :options="employmentOptions" />
        </label>
        <label class="block text-sm">
          <span class="mb-1 block font-medium text-neutral-700">
            {{ t('payroll.eldp.year') }}
          </span>
          <SearchableSelect v-model="year" :options="yearOptions" />
        </label>
        <div v-if="submissionTestAllowed" class="block text-sm">
          <span class="mb-1 block font-medium text-neutral-700">
            {{ t('payroll.regzel.environment.label') }}
          </span>
          <EnvironmentSwitch
            v-model="environment"
            :aria-label="t('payroll.regzel.environment.label')"
            data-test="eldp-environment"
          />
        </div>
      </div>

      <div class="space-y-2 border-t border-neutral-200 pt-4">
        <label class="flex items-start gap-2 text-sm text-neutral-700">
          <input
            v-model="excludedDaysConfirmed"
            type="checkbox"
            class="mt-0.5"
            data-test="eldp-excluded-confirm"
          >
          <span>{{ t('payroll.eldp.confirmExcluded') }}</span>
        </label>
        <p class="max-w-prose text-xs text-neutral-500" data-test="eldp-deducted-hint">
          {{ t('payroll.eldp.deductedDerivedHint') }}
        </p>
        <fieldset
          class="space-y-3 rounded-lg border border-neutral-200 p-3"
          data-test="eldp-pension"
        >
          <legend class="px-1 text-sm font-medium text-neutral-700">
            {{ t('payroll.eldp.pension.title') }}
          </legend>
          <p class="max-w-prose text-xs text-neutral-500">
            {{ t('payroll.eldp.pension.description') }}
          </p>
          <div class="grid gap-3 sm:grid-cols-3">
            <label class="block text-sm">
              <span class="mb-1 block font-medium text-neutral-700">
                {{ t('payroll.eldp.pension.ageReachedOn') }}
              </span>
              <DateInput
                v-model="pensionAgeReachedOn"
                class="h-10 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm outline-none focus:border-payroll-500 focus:ring-2 focus:ring-payroll-500/20"
                data-test="eldp-pension-age" />
              <span class="mt-1 block text-xs text-neutral-500">
                {{ t('payroll.eldp.pension.ageReachedOnHint') }}
              </span>
            </label>
            <label class="block text-sm">
              <span class="mb-1 block font-medium text-neutral-700">
                {{ t('payroll.eldp.pension.earlyPensionFrom') }}
              </span>
              <DateInput
                v-model="earlyPensionFrom"
                class="h-10 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm outline-none focus:border-payroll-500 focus:ring-2 focus:ring-payroll-500/20"
                data-test="eldp-pension-early" />
              <span class="mt-1 block text-xs text-neutral-500">
                {{ t('payroll.eldp.pension.earlyPensionFromHint') }}
              </span>
            </label>
            <label class="block text-sm">
              <span class="mb-1 block font-medium text-neutral-700">
                {{ t('payroll.eldp.pension.fullPensionPaidFrom') }}
              </span>
              <input
                v-model="fullPensionPaidFrom"
                type="month"
                class="h-10 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm outline-none focus:border-payroll-500 focus:ring-2 focus:ring-payroll-500/20"
                data-test="eldp-pension-full"
              >
              <span class="mt-1 block text-xs text-neutral-500">
                {{ t('payroll.eldp.pension.fullPensionPaidFromHint') }}
              </span>
            </label>
          </div>
          <label class="flex items-start gap-2 text-sm text-neutral-700">
            <input
              v-model="foreignInsurance"
              type="checkbox"
              class="mt-0.5"
              data-test="eldp-pension-foreign"
            >
            <span>{{ t('payroll.eldp.pension.foreignInsurance') }}</span>
          </label>
          <label class="flex items-start gap-2 text-sm font-medium text-neutral-700">
            <input
              v-model="pensionConfirmed"
              type="checkbox"
              class="mt-0.5"
              data-test="eldp-pension-confirm"
            >
            <span>{{ t('payroll.eldp.pension.confirm') }}</span>
          </label>
        </fieldset>
        <label class="flex items-start gap-2 text-sm text-neutral-700">
          <input
            v-model="requestedByAuthority"
            type="checkbox"
            class="mt-0.5"
            data-test="eldp-authority-request"
          >
          <span>{{ t('payroll.eldp.requestedByAuthority') }}</span>
        </label>
        <label v-if="requestedByAuthority" class="block max-w-sm text-sm">
          <span class="mb-1 block font-medium text-neutral-700">
            {{ t('payroll.eldp.authorityRequestReceivedOn') }}
          </span>
          <DateInput
            v-model="authorityRequestReceivedOn"
            class="h-10 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm outline-none focus:border-payroll-500 focus:ring-2 focus:ring-payroll-500/20"
            data-test="eldp-authority-request-date" />
          <span class="mt-1 block text-xs text-neutral-500">
            {{ t('payroll.eldp.authorityRequestReceivedOnHint') }}
          </span>
        </label>
        <label v-if="authorityDueOnRequired" class="block max-w-sm text-sm">
          <span class="mb-1 block font-medium text-neutral-700">
            {{ t('payroll.eldp.authorityRequestDueOn') }}
          </span>
          <DateInput
            v-model="authorityRequestDueOn"
            class="h-10 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm outline-none focus:border-payroll-500 focus:ring-2 focus:ring-payroll-500/20"
            data-test="eldp-authority-request-due-on" />
          <span class="mt-1 block text-xs text-neutral-500">
            {{ t('payroll.eldp.authorityRequestDueOnHint') }}
          </span>
        </label>
        <label class="block max-w-sm text-sm">
          <span class="mb-1 block font-medium text-neutral-700">
            {{ t('payroll.eldp.deathOn') }}
          </span>
          <DateInput
            v-model="deathOn"
            class="h-10 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm outline-none focus:border-payroll-500 focus:ring-2 focus:ring-payroll-500/20"
            data-test="eldp-death-on" />
          <span class="mt-1 block text-xs text-neutral-500">
            {{ t('payroll.eldp.deathOnHint') }}
          </span>
        </label>
        <label class="block text-sm">
          <span class="mb-1 block font-medium text-neutral-700">
            {{ t('payroll.eldp.note') }}
          </span>
          <textarea
            v-model="note"
            rows="2"
            maxlength="500"
            class="w-full rounded-lg border border-neutral-300 bg-surface p-2 text-sm text-neutral-900"
            data-test="eldp-note"
          />
          <span class="mt-1 block text-xs text-neutral-500" data-test="eldp-note-hint">
            {{ t('payroll.eldp.noteOptionalHint') }}
          </span>
        </label>
        <label class="block max-w-sm text-sm">
          <span class="mb-1 block font-medium text-neutral-700">
            {{ t('payroll.eldp.correction.preparedOn') }}
          </span>
          <DateInput
            v-model="preparedOn"
            class="h-10 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm outline-none focus:border-payroll-500 focus:ring-2 focus:ring-payroll-500/20"
            data-test="eldp-prepared-on" />
          <span class="mt-1 block text-xs text-neutral-500">
            {{ t('payroll.eldp.correction.preparedOnHint') }}
          </span>
        </label>
        <label v-if="statement" class="flex items-start gap-2 text-sm text-neutral-700">
          <input
            v-model="correction"
            type="checkbox"
            class="mt-0.5"
            data-test="eldp-correction"
          >
          <span>
            <span class="font-medium">{{ t('payroll.eldp.correction.label') }}</span>
            <span class="mt-0.5 block text-xs text-neutral-500">{{ t('payroll.eldp.correction.hint') }}</span>
          </span>
        </label>
      </div>

      <div
        v-if="statement"
        class="rounded-lg border border-neutral-200 bg-neutral-50 p-3 text-sm"
        data-test="eldp-summary"
      >
        <dl class="grid gap-2 sm:grid-cols-3">
          <div>
            <dt class="text-xs text-neutral-500">{{ t('payroll.eldp.summary.period') }}</dt>
            <dd class="font-medium">{{ formatDate(statement.period_from) }} – {{ formatDate(statement.period_to) }}</dd>
          </div>
          <div>
            <dt class="text-xs text-neutral-500">{{ t('payroll.eldp.summary.insuranceDays') }}</dt>
            <dd class="font-medium">{{ statement.insurance_days }}</dd>
          </div>
          <div>
            <dt class="text-xs text-neutral-500">{{ t('payroll.eldp.summary.excludedDays') }}</dt>
            <dd class="font-medium">{{ statement.excluded_days_total }}</dd>
          </div>
          <div>
            <dt class="text-xs text-neutral-500">{{ t('payroll.eldp.summary.kind') }}</dt>
            <dd class="font-medium">
              {{ t(`payroll.eldp.kind.${statement.statement_kind}`) }}
            </dd>
          </div>
          <div>
            <dt class="text-xs text-neutral-500">{{ t('payroll.eldp.summary.dueOn') }}</dt>
            <dd class="font-medium">{{ formatDate(statement.due_on) }}</dd>
          </div>
          <div>
            <dt class="text-xs text-neutral-500">{{ t('payroll.eldp.summary.sections') }}</dt>
            <dd class="font-medium">{{ statement.section_count }}</dd>
          </div>
        </dl>
      </div>

      <!--
        Údaje tiskopisu, které kontrolní XML nenese. Účetní je opisuje do
        oficiálního rozhraní ČSSZ spolu s řádky listu.
      -->
      <div
        v-if="statement && formSheet"
        class="rounded-lg border border-neutral-200 bg-surface p-3 text-sm"
        data-test="eldp-form-sheet"
      >
        <p class="font-medium text-neutral-900">{{ t('payroll.eldp.formSheet.title') }}</p>
        <p class="mt-1 max-w-prose text-xs text-neutral-600">{{ t('payroll.eldp.formSheet.description') }}</p>
        <dl class="mt-2 grid gap-2 sm:grid-cols-3">
          <div>
            <dt class="text-xs text-neutral-500">{{ t('payroll.eldp.formSheet.type') }}</dt>
            <dd class="font-medium" data-test="eldp-form-type">{{ t(`payroll.eldp.formSheet.types.${formSheet.eldp_type}`) }}</dd>
          </div>
          <div>
            <dt class="text-xs text-neutral-500">{{ t('payroll.eldp.formSheet.employedFrom') }}</dt>
            <dd class="font-medium">{{ formatDate(formSheet.employed_from) }}</dd>
          </div>
          <div>
            <dt class="text-xs text-neutral-500">{{ t('payroll.eldp.formSheet.preparedOn') }}</dt>
            <dd class="font-medium">{{ formatDate(formSheet.prepared_on) }}</dd>
          </div>
        </dl>
        <p v-if="formSheet.corrects" class="mt-2 text-xs font-medium text-warning-700" data-test="eldp-form-corrects">
          {{ t('payroll.eldp.formSheet.corrects', { id: formSheet.corrects.statement_id }) }}
        </p>
        <div class="mt-2 overflow-x-auto">
          <table class="w-full text-left text-xs">
            <thead class="text-neutral-500">
              <tr>
                <th class="py-1 pr-3 font-medium">{{ t('payroll.eldp.formSheet.code') }}</th>
                <th class="py-1 pr-3 font-medium" :title="t('payroll.eldp.formSheet.smallScaleTitle')">{{ t('payroll.eldp.formSheet.smallScale') }}</th>
                <th class="py-1 pr-3 font-medium">{{ t('payroll.eldp.formSheet.period') }}</th>
                <th class="py-1 pr-3 font-medium">{{ t('payroll.eldp.formSheet.days') }}</th>
                <th class="py-1 pr-3 font-medium">{{ t('payroll.eldp.formSheet.base') }}</th>
                <th class="py-1 font-medium">{{ t('payroll.eldp.formSheet.monthsX') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="(section, index) in formSections"
                :key="`${section.code}-${index}`"
                class="border-t border-neutral-100"
                data-test="eldp-form-section"
              >
                <td class="py-1 pr-3 font-medium">{{ section.code }}</td>
                <td class="py-1 pr-3" data-test="eldp-form-small-scale">{{ section.small_scale === true ? 'A' : 'N' }}</td>
                <td class="py-1 pr-3">{{ sectionPeriod(section) }}</td>
                <td class="py-1 pr-3">{{ section.insurance_days }}</td>
                <td class="py-1 pr-3">{{ section.assessment_base_czk }}</td>
                <td class="py-1">{{ monthsX(section) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
        <!-- Stejnopis pro zaměstnance (§ 38 odst. 5 ve znění do 31. 12. 2025) ze zmrazeného listu. -->
        <div class="mt-3 flex flex-wrap items-center gap-2">
          <button
            type="button"
            :class="[btnOutline('neutral'), 'whitespace-nowrap']"
            :disabled="copyDownloading"
            data-test="eldp-copy"
            @click="downloadCopy"
          >
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
              <path :d="ICONS.download" />
            </svg>
            {{ t('payroll.eldp.copy.download') }}
          </button>
          <span class="text-xs text-neutral-500">{{ t('payroll.eldp.copy.hint') }}</span>
        </div>
        <p v-if="copyError" class="mt-2 text-xs text-danger-700" role="alert" data-test="eldp-copy-error">{{ copyError }}</p>
      </div>

      <!--
        Převzatá část roku přechodu. Stojí hned pod souhrnem, protože účetní
        musí vědět, že část zákonné evidence nespočítalo MyÚčto, ještě než
        stáhne kontrolní XML a půjde list podat.
      -->
      <div
        v-if="statement && (takeoverSources.length || takeoverOverriddenPeriods.length)"
        class="rounded-lg border border-warning-500/30 bg-warning-50 p-3 text-sm text-warning-800"
        data-test="eldp-takeover"
        role="status"
      >
        <p class="font-medium">{{ t('payroll.eldp.takeover.title') }}</p>
        <p class="mt-1 max-w-prose text-xs">{{ t('payroll.eldp.takeover.description') }}</p>
        <ul v-if="takeoverSources.length" class="mt-2 space-y-1 text-xs">
          <li
            v-for="item in takeoverSources"
            :key="item.period_start"
            data-test="eldp-takeover-month"
          >
            <span class="font-medium">
              {{ t('payroll.eldp.takeover.month', {
                period: monthLabel(item.period_start),
                source: takeoverSourceLabel(item.source),
              }) }}
            </span>
            <span class="block break-all text-warning-700">
              {{ t('payroll.eldp.takeover.fingerprint', { hash: item.row_sha256 }) }}
            </span>
          </li>
        </ul>
        <p
          v-if="takeoverOverriddenPeriods.length"
          class="mt-2 max-w-prose text-xs"
          data-test="eldp-takeover-overridden"
        >
          {{ t('payroll.eldp.takeover.overridden', {
            periods: takeoverOverriddenPeriods.join(', '),
          }) }}
        </p>
      </div>

      <p class="max-w-prose text-xs text-warning-700">
        {{ t('payroll.eldp.noSendNotice') }}
      </p>

      <div v-if="prepared" class="rounded-lg border border-primary-200 bg-primary-50 p-3 text-sm text-neutral-700">
        <p class="max-w-prose">{{ t('payroll.eldp.manualCompletionNotice') }}</p>
        <p v-if="downloadError" class="mt-2 text-danger-700" role="alert">{{ downloadError }}</p>
        <button
          type="button"
          :class="btnOutline('neutral')"
          :disabled="downloading"
          data-test="eldp-download"
          class="mt-3"
          @click="downloadControlXml"
        >
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path :d="ICONS.download" />
          </svg>
          {{ downloading ? t('payroll.eldp.downloading') : t('payroll.eldp.downloadControlXml') }}
        </button>
      </div>

      <section
        v-if="statement && manualCompletion"
        class="space-y-4 rounded-lg border border-neutral-200 bg-surface p-4"
        data-test="eldp-manual-completion"
      >
        <div>
          <h4 class="font-semibold text-neutral-900">
            {{ t('payroll.eldp.manual.title') }}
          </h4>
          <p class="mt-1 max-w-prose text-xs text-neutral-600">
            {{ t('payroll.eldp.manual.description') }}
          </p>
          <p class="mt-1 max-w-prose text-xs font-medium text-warning-700">
            {{ t('payroll.eldp.manual.controlXmlStaysPrepared') }}
          </p>
        </div>

        <div v-if="manualCompletion.evidence.length" class="space-y-2" data-test="eldp-manual-history">
          <article
            v-for="evidence in manualCompletion.evidence"
            :key="evidence.id"
            class="rounded-md border border-neutral-200 bg-neutral-50 p-3 text-xs text-neutral-700"
            :data-test="`eldp-evidence-${evidence.authority_status}`"
          >
            <div class="flex flex-wrap items-center justify-between gap-2">
              <span
                class="rounded-full px-2 py-0.5 font-semibold"
                :class="evidence.authority_status === 'accepted'
                  ? 'bg-success-100 text-success-700'
                  : 'bg-warning-100 text-warning-700'"
              >
                {{ t(`payroll.eldp.manual.status.${evidence.authority_status}`) }}
              </span>
              <span>{{ formatDate(evidence.confirmed_on) }}</span>
            </div>
            <p class="mt-2 font-medium text-neutral-900">{{ evidence.authority_reference }}</p>
            <a
              :href="`/documents/${evidence.confirmation_document_id}`"
              class="mt-1 inline-flex text-primary-700 hover:underline"
            >
              {{ t('payroll.eldp.manual.documentLink', { id: evidence.confirmation_document_id }) }}
            </a>
            <p class="mt-1 break-all text-neutral-500">
              {{ t('payroll.eldp.manual.hashLine', {
                hash: evidence.confirmation_sha256,
                bytes: evidence.confirmation_byte_size,
              }) }}
            </p>
          </article>
        </div>

        <p
          v-if="hasAcceptedEvidence"
          class="rounded-md border border-success-500/30 bg-success-50 p-3 text-sm text-success-700"
          data-test="eldp-fulfilled"
        >
          {{ t('payroll.eldp.manual.fulfilled') }}
        </p>

        <div v-else-if="canWrite && canReadDocuments" class="space-y-3 border-t border-neutral-200 pt-4">
          <label class="block text-sm font-medium text-neutral-700">
            {{ t('payroll.eldp.manual.resultLabel') }}
            <select
              v-model="authorityStatus"
              data-test="eldp-authority-status"
              class="mt-1 h-10 w-full rounded-md border border-neutral-300 bg-surface px-3 text-neutral-900 sm:max-w-md"
            >
              <option value="submitted" :disabled="manualCompletion.evidence.some(item => item.authority_status === 'submitted')">
                {{ t('payroll.eldp.manual.status.submitted') }}
              </option>
              <option value="accepted">
                {{ t('payroll.eldp.manual.status.accepted') }}
              </option>
            </select>
          </label>
          <p
            class="max-w-prose rounded-md p-3 text-xs"
            :class="authorityStatus === 'accepted'
              ? 'bg-success-50 text-success-700'
              : 'bg-warning-50 text-warning-700'"
            data-test="eldp-authority-status-explanation"
          >
            {{ t(`payroll.eldp.manual.statusExplanation.${authorityStatus}`) }}
          </p>

          <div class="relative">
            <label class="block text-sm font-medium text-neutral-700">
              {{ t('payroll.eldp.manual.documentLabel') }}
              <span class="mt-1 flex flex-wrap gap-2">
                <input
                  v-model="confirmationDocumentQuery"
                  type="search"
                  :readonly="confirmationDocument !== null"
                  :placeholder="t('payroll.eldp.manual.documentPlaceholder')"
                  class="h-10 min-w-64 flex-1 rounded-md border border-neutral-300 bg-surface px-3 text-sm text-neutral-900"
                  data-test="eldp-document-query"
                  @keyup.enter.prevent="searchConfirmationDocuments"
                >
                <button
                  v-if="confirmationDocument === null"
                  type="button"
                  :class="btnOutline('neutral')"
                  data-test="eldp-document-search"
                  @click="searchConfirmationDocuments"
                >
                  <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path :d="ICONS.search" />
                  </svg>
                  {{ t('common.search') }}
                </button>
                <button v-else type="button" :class="btnOutline('neutral')" @click="clearConfirmationDocument">
                  <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path :d="ICONS.edit" />
                  </svg>
                  {{ t('common.edit') }}
                </button>
              </span>
            </label>
            <div
              v-if="confirmationDocuments.length"
              class="absolute z-20 mt-1 max-h-52 w-full overflow-auto rounded-md border border-neutral-200 bg-surface shadow-lg"
            >
              <button
                v-for="document in confirmationDocuments"
                :key="document.id"
                type="button"
                class="cursor-pointer block w-full px-3 py-2 text-left text-sm text-neutral-900 hover:bg-neutral-100"
                data-test="eldp-document-option"
                @click="chooseConfirmationDocument(document)"
              >
                <span class="font-medium">{{ document.title }}</span>
                <span class="ml-2 text-xs text-neutral-500">{{ document.original_name }}</span>
              </button>
            </div>
            <p class="mt-1 text-xs text-neutral-500">{{ t('payroll.eldp.manual.serverHashNotice') }}</p>
          </div>

          <div class="grid gap-3 sm:grid-cols-2">
            <label class="text-sm font-medium text-neutral-700">
              {{ t('payroll.eldp.manual.referenceLabel') }}
              <input
                v-model="authorityReference"
                maxlength="190"
                class="mt-1 h-10 w-full rounded-md border border-neutral-300 bg-surface px-3 text-neutral-900"
                data-test="eldp-authority-reference"
              >
            </label>
            <label class="text-sm font-medium text-neutral-700">
              {{ t('payroll.eldp.manual.confirmedOnLabel') }}
              <DateInput
                v-model="confirmedOn"
                class="mt-1 h-10 w-full rounded-md border border-neutral-300 bg-surface px-3 text-neutral-900"
                data-test="eldp-confirmed-on" />
            </label>
          </div>

          <p v-if="completionError" class="text-sm text-danger-700" role="alert" data-test="eldp-completion-error">
            {{ completionError }}
          </p>
          <p v-if="completionSuccess" class="text-sm text-success-700" role="status" data-test="eldp-completion-success">
            {{ completionSuccess }}
          </p>
          <div class="flex flex-wrap items-center justify-end gap-3">
            <p
              v-if="completeBlockers.length"
              class="flex-1 text-sm text-neutral-600"
              data-test="eldp-complete-blockers"
            >
              {{ t('payroll.eldp.blockers.title', { list: completeBlockers.join(', ') }) }}
            </p>
            <button
              type="button"
              :class="btnFilled(authorityStatus === 'accepted' ? 'success' : 'primary')"
              :disabled="!canComplete"
              data-test="eldp-complete"
              @click="completeManually"
            >
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path :d="ICONS.check" />
              </svg>
              {{ completing ? t('common.saving') : t('payroll.eldp.manual.save') }}
            </button>
          </div>
        </div>

        <p v-else class="text-xs text-neutral-500">
          {{ t('payroll.eldp.manual.permissionRequired') }}
        </p>
      </section>

      <div class="flex flex-wrap items-center justify-end gap-3 border-t border-neutral-200 pt-4">
        <p
          v-if="prepareBlockers.length && standaloneAllowed"
          class="flex-1 text-sm text-neutral-600"
          data-test="eldp-prepare-blockers"
        >
          {{ t('payroll.eldp.blockers.title', { list: prepareBlockers.join(', ') }) }}
        </p>
        <button
          type="button"
          :class="btnFilled('primary')"
          :disabled="!canPrepare"
          data-test="eldp-prepare"
          @click="prepare"
        >
          <svg
            class="h-4 w-4"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            stroke-width="2"
            aria-hidden="true"
          >
            <path :d="ICONS.clipboardCheck" />
          </svg>
          {{ t('payroll.eldp.prepare') }}
        </button>
      </div>
    </div>
  </div>
</template>

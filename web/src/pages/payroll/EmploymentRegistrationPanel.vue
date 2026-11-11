<script setup lang="ts">
import { requestSupplierId } from '@/workspace/supplierContext'
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { apiErrorCode } from '@/api/errors'
import { usePayrollServerMessage } from './payrollServerMessage'
import {
  payrollApi,
  type PayrollJmhzTransportAttempt,
  type PayrollJmhzTransportPoll,
  type PayrollJmhzTransportEnvironment,
  type PayrollRegistrationPreview,
  type PayrollRegistrationMode,
  type PayrollRegistrationEvent,
  type PayrollRegistrationEventInput,
  type PayrollRegistrationEventInteraction,
  type PayrollRegistrationSubmission,
  type PayrollRegistrationCurrent,
  type PayrollRegistrationA1Address,
  type PayrollRegistrationA1Draft,
  type PayrollRegistrationA1ForeignInsurance,
  type PayrollRegistrationA1Problem,
  type PayrollRegistrationA1Profile,
  type PayrollRegistrationA1ProfilePayload,
  type PayrollRegistrationChangeDetection,
  type PayrollRegistrationChangeProposal,
  type PayrollRegistrationWarning,
  type PayrollEmploymentJmhzEvidenceOptions,
  type PayrollJmhzMunicipalityOption,
  type PayrollTerminationA2Prefill,
  type PayrollRegistrationMissingItem,
  type PayrollRegistrationProblemTarget,
} from '@/api/payroll'
import {
  registrationItemLabel,
  registrationMissingItems,
} from './registrationMissingItems'
import { registrationA1FieldLabel } from './registrationA1FieldLabels'
import { registrationRemediation } from './registrationRemediation'
import { usePersonCardSaveSection } from './personCardSave'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import { btnFilled, btnOutline, ICONS } from '@/components/ui/buttonStyles'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import CzIscoPicker from '@/components/payroll/CzIscoPicker.vue'
// Formátování je sdílené (useFormat) — místní kopie se rozcházely v locale i tvaru.
import { formatDate, formatDateTime } from '@/composables/useFormat'
import { loadPayrollJmhzOptions } from '@/composables/usePayrollJmhzOptions'
import { healthInsurerOptions, isHealthInsurerCode } from '@/utils/healthInsurers'
import { fieldSelector, revealField } from '@/utils/revealField'
import DateInput from '@/components/ui/DateInput.vue'
import ProductionSendConfirmDialog from '@/components/payroll/ProductionSendConfirmDialog.vue'
import { useProductionSendConfirm } from '@/composables/useProductionSendConfirm'
import { useSubmissionEnvironment } from '@/composables/useSubmissionEnvironment'

const props = defineProps<{
  employmentId: number
  personId?: number
  canWrite: boolean
  /** Podklady A2 odvozené ze záznamu „Skončení vztahu" na kartě vztahu. */
  a2Prefill?: PayrollTerminationA2Prefill | null
  /** Den skončení vztahu z karty (předvyplní skutečné datum skončení v A2). */
  terminationEndDate?: string | null
  /** Zvýší se po každém uložení kmenových dat osoby nebo vztahu (UI-26). */
  masterDataVersion?: number
}>()

const { t, te, locale } = useI18n()
const { errorMessage: serverErrorMessage } = usePayrollServerMessage()
const {
  request: sendConfirmRequest,
  confirmProductionSend,
  settle: settleSendConfirm,
} = useProductionSendConfirm()
const busy = ref(false)
const error = ref('')
const errorCode = ref('')
/**
 * Všechny chybějící údaje z odmítnuté přípravy — server je posílá najednou,
 * každý s adresou pole. Seznam nahrazuje dřívější jednu větu, po jejímž
 * vyřešení vyskočila další.
 */
const errorProblems = ref<PayrollRegistrationMissingItem[]>([])
/**
 * Český zaměstnanec před nástupem: výchozí je částečné přihlášení (P1), plnou
 * registraci A1 volí účetní výslovně. Volba se ukáže, až server řekne, že
 * pro vztah přichází v úvahu (`before_start_choice` v náhledu).
 */
const registrationMode = ref<PayrollRegistrationMode>('auto')
const beforeStartChoice = ref(false)
/**
 * Zaměstnanec začal pracovat bez ohlášení: lhůta přihlášení je osm dnů od
 * prvního plnění (§ 19 odst. 1 písm. b) zákona č. 323/2025 Sb.). Volba se
 * nabídne až u přímé plné registrace po nástupu.
 */
const startNotKnownInAdvance = ref(false)
const preview = ref<PayrollRegistrationPreview | null>(null)
const submission = ref<PayrollRegistrationSubmission | null>(null)
const showXml = ref(false)
/*
 * Výchozí je vždy produkce a volba se schválně NEpamatuje (rozhodnutí
 * uživatele): po obnovení stránky se vrací produkce. Ručně zvolený Test je
 * zato zřetelně vidět u tlačítek i v oznámení pod hlavičkou.
 */
const environment = ref<PayrollJmhzTransportEnvironment>('production')
const { testAllowed: submissionTestAllowed } = useSubmissionEnvironment(environment)
const transport =ref<PayrollJmhzTransportPoll | null>(null)
const transportBusy = ref<'send' | 'poll' | 'close' | 'reprepare' | null>(null)
/** Odeslání odmítlo zastaralý formát věty (REGZEC25 od 23. 12. 2026). */
const staleSendRejected = ref(false)
const transportMessage = ref('')
const changeDetection = ref<PayrollRegistrationChangeDetection | null>(null)
const changesBusy = ref(false)
const changeError = ref('')
const proposalBusy = ref<number | null>(null)
const dismissOpenFor = ref<number | null>(null)
const dismissNotes = ref<Record<number, string>>({})
/** Platnost změny podle návrhu; účetní ji před ohlášením smí přepsat. */
const fileEffectiveOn = ref<Record<number, string>>({})
const events = ref<PayrollRegistrationEvent[]>([])
const eventsBusy = ref(false)
const eventSaving = ref(false)
const eventError = ref('')
const eventErrorCode = ref('')
const selectedEventId = ref<number | null>(null)
const eventFormOpen = ref(false)
const eventInteraction = ref<PayrollRegistrationEventInteraction>('termination')
const effectiveOn = ref('')
const sourceReference = ref('')
const sourceSubmissionId = ref<number | null>(null)
const discoveredOn = ref('')
/** A3: den, kdy se zaměstnavatel o změně dozvěděl; prázdné = den platnosti. */
const learnedOn = ref('')
const newVariableSymbol = ref('')
const deltaField = ref('title_prefix')
const deltaValue = ref('')
const addressStreet = ref('')
const addressHouseNumber = ref('')
const addressOrientationNumber = ref('')
const addressPostalCode = ref('')
const addressCity = ref('')
const addressCountryCode = ref('CZ')
const addressRuianPoint = ref('')
const residencyCountryCode = ref('')
const residencyChangedOn = ref('')
const foreignName = ref('')
const foreignCountryCode = ref('')
const foreignIdentifier = ref('')
const foreignStreet = ref('')
const foreignHouseNumber = ref('')
const foreignOrientationNumber = ref('')
const foreignPostalCode = ref('')
const foreignCity = ref('')
const foreignSector = ref('')
const endedByDeath = ref<'omit' | 'yes' | 'no'>('omit')
const unemploymentMode = ref<
  'omit' | 'spec_early' | 'not_provided_2' | 'not_provided_3' | 'provided'
>('omit')
const earlyTerminationReason = ref('')
const averageNetEarnings = ref('')
const pensionPeriods = ref([{ from: '', to: '' }])
const employmentType = ref<'omit' | '1' | '2' | '3' | '4'>('omit')
const terminationReason = ref('')
const entitlement = ref<'omit' | 'yes' | 'no'>('omit')
const paidInFull = ref<'yes' | 'no'>('no')
const settlementAmountKind = ref('replacement')
const settlementAmount = ref('')
const notStartedConfirmed = ref(false)
/**
 * Důvod storna A8. Nenastoupení má osmidenní lhůtu a obejde se bez přílohy;
 * jiný důvod (chybný variabilní symbol, druh činnosti…) lhůtu nemá, ale ČSSZ
 * ho zpracuje jen s písemným zdůvodněním v příloze.
 */
const cancellationReason = ref<'not_started' | 'other'>('not_started')
const cancellationAttachment = ref<{ name: string, data_base64: string } | null>(null)
const cancellationAttachmentDescription = ref('')
/** A2 u zaměstnance z ONZ: ručně zapsané OIČ/ID PPV ověřená v Seznamu zaměstnanců ČSSZ. */
const identifiersVerifiedInCsszList = ref(false)
const a1ProfileOpen = ref(false)
const a1ProfileLoading = ref(false)
const a1ProfileSaving = ref(false)
const a1Checking = ref(false)
/**
 * Výsledek poslední kontroly. Drží se, dokud nedoběhne další kontrola nebo
 * uložení — červené značky u polí by jinak zmizely při prvním úhozu do
 * klávesnice, tedy dřív, než účetní stihne projít zbytek seznamu.
 */
const a1Problems = ref<PayrollRegistrationA1Problem[]>([])
/** Varování, která podání neblokují — překryv vztahů se stejným druhem činnosti. */
const a1Warnings = ref<PayrollRegistrationWarning[]>([])
const a1Checked = ref(false)
const a1ProfileError = ref('')
const a1ProfileErrorCode = ref('')
const a1ProfileMessage = ref('')
const a1ShowPayload = ref(false)
const a1Draft = ref<PayrollRegistrationA1Draft | null>(null)
const a1Stored = ref<PayrollRegistrationA1Profile | null>(null)
const a1Form = ref<PayrollRegistrationA1ProfilePayload>(emptyA1Profile())
const a1Baseline = ref('')
const a1LocalDraft = ref<A1LocalDraft | null>(null)
const a1LocalDraftArmed = ref(false)
const a1ErrorPanel = ref<HTMLElement | null>(null)
const jmhzOptions = ref<PayrollEmploymentJmhzEvidenceOptions | null>(null)
const jmhzOptionsFailed = ref(false)
const municipalityOptions = ref<PayrollJmhzMunicipalityOption[]>([])
const municipalitiesLoading = ref(false)

function emptyA1Address(): PayrollRegistrationA1Address {
  return {
    street: null,
    house_number: null,
    orientation_number: null,
    city: null,
    postal_code: null,
    country_code: null,
    ruian_point: null,
  }
}

/** Číselník CIS Sektor (účel zahraničního pojištění). */
const FOREIGN_INSURANCE_SECTORS = ['01', '02', '03', '04', '05', '06', '07', '08'] as const
/** Lhůta A2/A8 zkrácená o oznámení úřadu práce u cizince s povolením nebo kartou (§ 88 ZoZ). */
const FOREIGN_PERMIT_RULESET_ID = 'cz-regzec-foreign-permit-labour-office-2026-07.v1'

function emptyA1ForeignInsurance(): PayrollRegistrationA1ForeignInsurance {
  return {
    current: null,
    name: null,
    street: null,
    house_number: null,
    orientation_number: null,
    postal_code: null,
    city: null,
    country_code: null,
    identifier: null,
    sector: null,
  }
}

/**
 * Oddíl cizozemského nositele pojištění existuje ve formuláři vždy u varianty
 * OST (u druhu činnosti „N" je povinný, jinde nepovinný). Server klíč vrací jen
 * u vyplněného oddílu, takže se prázdný oddíl dopočítá — před výpočtem
 * výchozího stavu formuláře, ať ho nezaložení nemarkuje jako změnu.
 */
function withForeignInsuranceSection(
  payload: PayrollRegistrationA1ProfilePayload,
): PayrollRegistrationA1ProfilePayload {
  if (a1IsFull.value && !payload.foreign_insurance) {
    payload.foreign_insurance = emptyA1ForeignInsurance()
  }
  return payload
}

function emptyA1Profile(): PayrollRegistrationA1ProfilePayload {
  return {
    effective_on: '',
    row_version: 0,
    permanent_address: emptyA1Address(),
    tax_residency: {
      country_code: null,
      identifier_type: null,
      identifier: null,
      residence_address: null,
    },
    employment: {
      activity_code: null,
      relationship_detail_code: null,
      actual_start_on: '',
      contract_start_on: null,
      small_scale: null,
      employment_status_code: null,
      work_mode_code: null,
      continuous_operation: null,
      prevailing_workplace_code: null,
      expected_workplaces: null,
      contract_workplace: null,
      workplace_city: null,
      workplace_municipality_code: null,
      profession_code: null,
      required_education_code: null,
      position_name: null,
      leadership: null,
    },
    pension: {
      type_code: null,
      received_from: null,
      early_retirement: false,
      reduced_retirement_age: false,
    },
    health_insurance_code: null,
    facts: {
      highest_education_code: null,
      disability_card: false,
      health_restrictions: [],
    },
    foreign_legislation: { applies: false, country_code: null },
    proof_identity: null,
    foreign_worker: null,
    czech_residence_address: null,
    contact_address: null,
    attachments: [],
  }
}

function editableA1Profile(profile: PayrollRegistrationA1Profile): PayrollRegistrationA1ProfilePayload {
  const {
    reference_hash: _referenceHash,
    created_at: _createdAt,
    created: _created,
    status: _status,
    problems: _problems,
    ...editable
  } = profile
  return editable
}

// Prázdný řetězec z <input> není „nevyplněno" — server ho odmítne jako
// neplatnou hodnotu, zatímco null bere jako chybějící volitelný údaj.
function blankToNull<T>(value: T): T {
  if (typeof value === 'string') {
    return (value.trim() === '' ? null : value.trim()) as T
  }
  if (Array.isArray(value)) {
    return value.map(item => blankToNull(item)) as T
  }
  if (value !== null && typeof value === 'object') {
    return Object.fromEntries(
      Object.entries(value as Record<string, unknown>)
        .map(([key, item]) => [key, blankToNull(item)]),
    ) as T
  }
  return value
}

interface A1LocalDraft {
  saved_at: string
  payload: PayrollRegistrationA1ProfilePayload
}

/**
 * Rozepsaný profil A1 v prohlížeči.
 *
 * Uložení je všechno nebo nic: server jednu chybějící položku odmítne celou
 * a formulář má přes stovku polí. Karta vztahu se přitom odmontuje pokaždé,
 * když účetní sbalí osobu, přepne se jinam nebo obnoví stránku — a právě to
 * udělá, když jde chybějící údaj doplnit na kartu osoby. Bez téhle zálohy by
 * se hodinová práce ztratila mezi odmítnutím a opravou.
 *
 * Klíč nese i dodavatele: `employmentId` je unikátní jen v rámci firmy, takže
 * bez něj by se rozpracovaný profil nabídl u cizího vztahu v jiné účtárně.
 */
const a1DraftStorageKey = computed(() => {
  let supplier = '0'
  try {
    supplier = requestSupplierId() ?? '0'
  } catch {
    supplier = '0'
  }
  return `myinvoice.payroll.a1-draft.${supplier}.${props.employmentId}`
})

function readA1LocalDraft(): A1LocalDraft | null {
  let raw: string | null = null
  try {
    raw = localStorage.getItem(a1DraftStorageKey.value)
  } catch {
    return null
  }
  if (raw === null) return null
  try {
    const parsed = JSON.parse(raw) as Partial<A1LocalDraft>
    if (typeof parsed?.saved_at !== 'string' || typeof parsed?.payload !== 'object') return null
    return { saved_at: parsed.saved_at, payload: parsed.payload as PayrollRegistrationA1ProfilePayload }
  } catch {
    // Poškozený zápis je totéž jako žádný — nesmí shodit celý panel.
    return null
  }
}

function writeA1LocalDraft(payload: PayrollRegistrationA1ProfilePayload): void {
  try {
    localStorage.setItem(a1DraftStorageKey.value, JSON.stringify({
      saved_at: new Date().toISOString(),
      payload,
    } satisfies A1LocalDraft))
  } catch {
    /* soukromý režim nebo plná kvóta — záloha je bonus, ne podmínka uložení */
  }
}

function clearA1LocalDraft(): void {
  a1LocalDraft.value = null
  a1LocalDraftArmed.value = false
  try {
    localStorage.removeItem(a1DraftStorageKey.value)
  } catch {
    /* viz writeA1LocalDraft */
  }
}

function discardA1LocalDraft(): void {
  clearA1LocalDraft()
  a1ProfileMessage.value = ''
}

/**
 * Verze a datum účinnosti se berou z ČERSTVÉHO návrhu, ne ze zálohy: záloha
 * může být dny stará a se starým `row_version` by server uložení odmítl na
 * konflikt — tedy přesně tou chybou, které se tohle snaží předejít.
 */
function restoreA1LocalDraft(): void {
  const draft = a1LocalDraft.value
  if (draft === null) return
  a1Form.value = withForeignInsuranceSection(
    JSON.parse(JSON.stringify(draft.payload)) as PayrollRegistrationA1ProfilePayload,
  )
  if (a1Draft.value !== null) {
    a1Form.value.effective_on = a1Draft.value.effective_on
    a1Form.value.row_version = a1Draft.value.row_version
  }
  a1LocalDraft.value = null
  a1LocalDraftArmed.value = true
  a1ProfileMessage.value = t('payroll.people.registration.a1.draft_restored')
}

const a1Variant = computed(() => a1Draft.value?.variant ?? null)
const a1IsFull = computed(() => a1Variant.value === 'OST')
const a1IsLimited = computed(() => a1Variant.value === '10')
const a1IsForeigner = computed(() => a1Draft.value?.foreigner === true)
/**
 * Odešla registrace na ČSSZ? Dokud ne, je profil rozepsaná práce: server
 * pracovní řádek při uložení přepisuje, historie žádná nevzniká, a číslo verze
 * ani upozornění na rozdíl proti nahlášenému stavu proto nemá co ukazovat.
 */
const a1Submitted = computed(() => a1Draft.value?.submitted === true)
const a1SavedVersion = computed(() => a1Stored.value?.row_version ?? 0)
/** Čas posledního uložení — u rozpracovaného profilu místo čísla verze. */
const a1SavedAt = computed(() => {
  const raw = a1Stored.value?.created_at ?? null
  if (raw === null || raw === '') return null
  // Server ukládá čas v UTC bez značky zóny; bez `Z` by ho prohlížeč vzal
  // jako místní a účetní by viděla uložení o dvě hodiny dřív.
  const parsed = new Date(raw.includes('T') ? raw : `${raw.replace(' ', 'T')}Z`)
  return Number.isNaN(parsed.getTime())
    ? null
    : parsed.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' })
})

function a1Source(path: string): string | null {
  return a1Draft.value?.sources[path] ?? null
}

const a1MissingByField = computed(() => {
  const map: Record<string, string> = {}
  for (const gap of a1Draft.value?.missing ?? []) map[gap.field] = gap.message
  return map
})

function a1Missing(path: string): string | null {
  return a1MissingByField.value[path] ?? null
}

/**
 * Hláška problému pole A1 v jazyce UI. Česká věta serveru je bohatší (říká
 * i kde se údaj vyplňuje), proto zůstává v češtině; v jiném jazyce se hláška
 * složí z jazykově nezávislého klíče a parametrů. Bez klíče zůstane věta serveru.
 */
function a1ProblemText(problem: PayrollRegistrationA1Problem): string {
  const key = problem.message_key
    ? `payroll.people.registration.a1.problem.${problem.message_key}`
    : ''
  if (locale.value === 'cs' || key === '' || !te(key)) return problem.message
  return t(key, {
    field: problem.field ? registrationA1FieldLabel(problem.field, t) : '',
    ...(problem.params ?? {}),
  })
}

const a1ProblemsByField = computed(() => {
  const map: Record<string, string> = {}
  for (const problem of a1Problems.value) {
    if (problem.field !== null) map[problem.field] ??= a1ProblemText(problem)
  }
  return map
})

function a1Problem(path: string): string | null {
  return a1ProblemsByField.value[path] ?? null
}

/** Vady bez pole (rozpor mezi sekcemi) se nemají kam připnout, patří do souhrnu. */
const a1LooseProblems = computed(
  () => a1Problems.value.filter(problem => problem.field === null),
)

// Nález kontroly přebíjí chybějící kmenový údaj a ten zase popisek zdroje:
// účetní řeší nejdřív to, co brání podání.
function a1NoteText(path: string): string {
  return a1Problem(path) ?? a1Missing(path) ?? a1Source(path) ?? ''
}

function a1NoteClass(path: string): string {
  if (a1Problem(path) !== null) return 'mt-1 block text-xs font-medium text-danger-700'
  return a1Missing(path) === null
    ? 'mt-1 block text-xs text-neutral-500'
    : 'mt-1 block text-xs text-warning-700'
}

/** Červený rám u vstupu — seznam v panelu neřekne, kam se má kliknout. */
function a1FieldClass(path: string): string {
  return a1Problem(path) === null
    ? a1InputClass
    : `${a1InputClass} border-danger-500 ring-1 ring-danger-500/30`
}

/**
 * Vstup i jeho adresa pro doskok.
 *
 * `data-a1-field` je jediný způsob, jak z hlášky „tenhle údaj chybí" trefit
 * konkrétní ovladač: `data-test` se jmenuje pokaždé jinak a hledat podle
 * `v-model` z DOMu nejde. Drží se proto na každém poli, ne jen na těch,
 * na která dnes něco odkazuje — jinak by další hláška zase mířila naprázdno.
 */
function a1FieldAttrs(path: string): Record<string, string> {
  return { class: a1FieldClass(path), 'data-a1-field': path }
}

/** Totéž pro komponenty, které si vlastní třídu vstupu řídí samy. */
function a1FieldRing(path: string): string {
  return a1Problem(path) === null
    ? ''
    : 'rounded-md ring-1 ring-danger-500'
}

const a1Busy = computed(
  () => a1ProfileLoading.value || a1ProfileSaving.value || a1Checking.value
    || !props.canWrite,
)

const a1LabelClass = 'block text-xs font-medium text-neutral-700'
const a1InputClass = 'mt-1 w-full rounded-md border border-neutral-300 bg-surface'
  + ' px-2 py-1.5 text-sm text-neutral-900 focus:border-primary-500'
  + ' focus:outline-none focus:ring-2 focus:ring-primary-500/20'
  + ' disabled:bg-neutral-100'
const a1SectionClass = 'rounded-md border border-neutral-200 p-3'
const a1GridClass = 'mt-2 grid gap-3 sm:grid-cols-2 lg:grid-cols-3'

/**
 * Nabídka zdravotních pojišťoven pro REGZEC.
 *
 * Číselník má jen platné pojišťovny, jenže v datech můžou být kódy zaniklých
 * (nebo prostě přepsaných) institucí. Kdyby nabídka uměla jen číselník,
 * našeptávač by uloženou hodnotu ukázal jako prázdno a první uložení karty by
 * ji tiše zahodilo. Legacy kód se proto do nabídky přidá navíc a je označený,
 * aby bylo poznat, že v číselníku není.
 */
const a1InsurerOptions = computed(() => {
  const options = healthInsurerOptions()
  const current = a1Form.value.health_insurance_code?.trim() ?? ''
  if (current !== '' && !isHealthInsurerCode(current)) {
    options.push({
      value: current,
      label: t('payroll.people.registration.a1.health_insurance_unknown', { code: current }),
    })
  }
  return options
})

/**
 * Postavení v zaměstnání — jen čtyřmístné kódy NKPZ (ČSSZ kratší odmítá).
 * U druhu činnosti 15 a 16 číselník podle EDV zužuje na 1341 a 1342. Uložený
 * kód mimo nabídku (dřív se ukládaly dva znaky) se ukáže označený, aby ho
 * první uložení tiše nesmazalo. Nástup od 1. 1. 2027 bere nabídku `from_2027`
 * (REGZEC 2027); podle EDV 1.5.0.3 je shodná s dřívější (list 2119).
 */
const a1EmploymentStatusOptions = computed(() => {
  const activity = a1Form.value.employment.activity_code
  const restricted = activity === '15' || activity === '16' ? ['1341', '1342'] : null
  const source = a1FromNextVintage.value
    ? jmhzOptions.value?.from_2027?.employment_status_codes
    : jmhzOptions.value?.employment_status_codes
  const options = (source ?? [])
    .filter(option => restricted === null || restricted.includes(option.code))
    .map(option => ({ code: option.code, label: `${option.code} · ${option.label}` }))
  const current = a1Form.value.employment.employment_status_code?.trim() ?? ''
  if (current !== '' && !options.some(option => option.code === current)) {
    options.unshift({
      code: current,
      label: t('payroll.people.registration.a1.code_unknown', { code: current }),
    })
  }
  return options
})

/**
 * Bližší určení PPV: vybírat mezi 1–3 jde jen u druhu činnosti 1 až 9, jinde
 * je „1 (žádné)" a u druhu 10 se neuvádí vůbec.
 */
const a1RelationshipDetailOptions = computed(() => {
  const all = jmhzOptions.value?.relationship_detail_codes ?? []
  const activity = a1Form.value.employment.activity_code
  if (activity === null || activity === '') return all
  if (activity === '10') return []
  return /^[1-9]$/.test(activity) ? all : all.filter(option => option.code === '1')
})

/**
 * „Práce probíhá převážně" (10258) patří jen zaměstnavateli na chráněném trhu
 * práce a zaměstnanci se zdravotním omezením; jinde ji ČSSZ nepřijme.
 */
const a1PrevailingWorkplaceApplies = computed(
  () => a1Draft.value?.protected_labor_market === true
    && (a1Form.value.facts?.health_restrictions.length ?? 0) > 0,
)

const a1AddressFields: {
  key: keyof PayrollRegistrationA1Address
  label: string
}[] = [
  { key: 'street', label: 'payroll.people.registration.a1.address.street' },
  { key: 'house_number', label: 'payroll.people.registration.a1.address.house_number' },
  { key: 'orientation_number', label: 'payroll.people.registration.a1.address.orientation_number' },
  { key: 'city', label: 'payroll.people.registration.a1.address.city' },
  { key: 'postal_code', label: 'payroll.people.registration.a1.address.postal_code' },
  { key: 'country_code', label: 'payroll.people.registration.a1.address.country_code' },
  { key: 'ruian_point', label: 'payroll.people.registration.a1.address.ruian_point' },
]

/**
 * „Co odesíláme" jako čitelný seznam, ne syrový JSON.
 *
 * Náhled ukazoval celý objekt formuláře (`"attachments": [], "employment":
 * {"activity_code": "1" …`) — účetní z něj nepoznala, co je vyplněné, a klíče
 * neznala. Teď jsou tu jen vyplněné položky s TÝMIŽ popisky jako u polí.
 */
const a1PayloadRows = computed(() => {
  const rows: { path: string, label: string, value: string }[] = []
  const visit = (value: unknown, path: string): void => {
    if (value === null || value === undefined) return
    if (Array.isArray(value)) {
      if (path === 'attachments') {
        for (const attachment of value as { name?: string }[]) {
          rows.push({ path, label: registrationA1FieldLabel(path, t), value: attachment.name ?? '—' })
        }
        return
      }
      if (value.length > 0) {
        rows.push({ path, label: registrationA1FieldLabel(path, t), value: String(value.length) })
      }
      return
    }
    if (typeof value === 'object') {
      for (const [key, item] of Object.entries(value as Record<string, unknown>)) {
        visit(item, path === '' ? key : `${path}.${key}`)
      }
      return
    }
    if (typeof value === 'string' && value.trim() === '') return
    rows.push({
      path,
      label: registrationA1FieldLabel(path, t),
      value: typeof value === 'boolean'
        ? t(value ? 'common.yes' : 'common.no')
        : String(value),
    })
  }
  const { effective_on: _effectiveOn, row_version: _rowVersion, ...payload } = blankToNull(a1Form.value)
  visit(payload, '')
  return rows
})

/**
 * Adresa pobytu v ČR má stát předvyplněný na CZ — „v ČR" je přímo v názvu
 * sekce a ruční výběr Česka je tam jen práce navíc.
 *
 * U trvalého pobytu a kontaktní adresy se stát NEPŘEDVYPLŇUJE: obojí bývá
 * u cizince v cizině a předvyplněné CZ by se tiše odeslalo do A1 jako
 * potvrzený fakt. Přepsat jde v obou případech.
 */
function a1EnsureAddress(
  key: 'czech_residence_address' | 'contact_address',
  present: boolean,
): void {
  if (!present) {
    a1Form.value[key] = null
    return
  }
  const address = a1Form.value[key] ?? emptyA1Address()
  if (key === 'czech_residence_address' && address.country_code === null) {
    address.country_code = 'CZ'
  }
  a1Form.value[key] = address
}

function a1EnsureResidenceAddress(present: boolean): void {
  const residency = a1Form.value.tax_residency
  if (residency === null) return
  residency.residence_address = present
    ? (residency.residence_address ?? emptyA1Address())
    : null
}

function a1AddRestriction(): void {
  a1Form.value.facts?.health_restrictions.push({
    type_code: null,
    from: null,
    to: null,
  })
}

function a1RemoveRestriction(index: number): void {
  a1Form.value.facts?.health_restrictions.splice(index, 1)
}

function a1RemoveAttachment(index: number): void {
  a1Form.value.attachments.splice(index, 1)
}

/**
 * Malé uzavřené číselníky REGZEC A1 (typ identifikátoru, vzdělání, důchod…)
 * doplňují uloženou hodnotu, i když v číselníku není — přesně jako
 * {@link a1InsurerOptions}. Bez toho by první uložení karty tiše zahodilo
 * historický kód, který si účetní kdysi napsal do volného textu.
 */
function a1CodeOptions(
  list: Array<{ code: string; label: string }> | undefined,
  current: string | null | undefined,
): Array<{ value: string; label: string }> {
  const options = (list ?? []).map(option => ({
    value: option.code,
    label: `${option.code} - ${option.label}`,
  }))
  const trimmed = current?.trim() ?? ''
  if (trimmed !== '' && !options.some(option => option.value === trimmed)) {
    options.push({
      value: trimmed,
      label: t('payroll.people.registration.a1.code_unknown', { code: trimmed }),
    })
  }
  return options
}

/** První den, od kterého ČSSZ přijímá hodnoty číselníků EDV 1.5.0.3 (REGZEC27). */
const FIRST_2027_EVENT_DATE = '2027-01-01'
/** Státní zaměstnanci vykonávají od tohoto dne práci v pracovním poměru (zákon č. 195/2026 Sb.). */
const STATE_EMPLOYEES_EMPLOYMENT_FROM = '2026-11-01'

function isIsoDate(value: string | null | undefined): value is string {
  return typeof value === 'string' && /^\d{4}-\d{2}-\d{2}$/u.test(value)
}

/** Nástup z formuláře A1 rozhoduje o ročníku věty, a tím o nabídce číselníků. */
const a1FromNextVintage = computed(() => {
  const start = a1Form.value.employment.actual_start_on
  return isIsoDate(start) && start >= FIRST_2027_EVENT_DATE
})

/** Důvody volného přístupu: od 1. 1. 2027 přibývají 22 a 23. */
const freeAccessReasonOptions = computed(() => [
  ...(jmhzOptions.value?.foreign_worker_free_access_reason_codes ?? []),
  ...(a1FromNextVintage.value ? jmhzOptions.value?.from_2027?.free_access_reason_codes ?? [] : []),
])

/** Skončení od 1. 1. 2027 zná i služební poměr podle zákonů č. 361/2003 a 221/1999 Sb. */
const a2FromNextVintage = computed(
  () => isIsoDate(effectiveOn.value) && effectiveOn.value >= FIRST_2027_EVENT_DATE,
)

const a2ServiceReasonOptions = computed(() =>
  (jmhzOptions.value?.from_2027?.service_termination_reasons ?? [])
    .filter(option => option.employment_types.includes(employmentType.value))
    .map(option => ({ code: option.code, label: option.label })),
)

/**
 * Státní zaměstnanci od 1. 11. 2026 (zákon č. 195/2026 Sb.): měkké upozornění
 * podle zvoleného druhu zaměstnání. Nikdy nic nezakazuje, režim vztahu z dat
 * nepoznáme.
 */
const stateEmployeeWarning = computed<'service_1_11' | 'service_after' | null>(() => {
  if (unemploymentMode.value !== 'provided' || employmentType.value !== '2') return null
  if (!isIsoDate(effectiveOn.value) || effectiveOn.value < STATE_EMPLOYEES_EMPLOYMENT_FROM) return null
  if (effectiveOn.value === STATE_EMPLOYEES_EMPLOYMENT_FROM) {
    return terminationReason.value === '5' ? null : 'service_1_11'
  }
  return 'service_after'
})

const selectedMunicipality = computed(() => {
  const code = a1Form.value.employment.workplace_municipality_code
  const label = a1Form.value.employment.workplace_city
  return code && label ? { value: code, label, secondary: code } : null
})

async function searchMunicipalities(query: string): Promise<void> {
  if (query.trim().length < 2) {
    municipalityOptions.value = []
    return
  }
  municipalitiesLoading.value = true
  try {
    municipalityOptions.value = await payrollApi.searchJmhzMunicipalities(query)
  } catch {
    municipalityOptions.value = []
  } finally {
    municipalitiesLoading.value = false
  }
}

/**
 * Obec pracoviště z kódu obce, který přišel ze vztahu.
 *
 * Návrh A1 přebíral ze sjednaných podmínek jen kód obce, název ne — pole
 * „Obec pracoviště" zůstalo prázdné s hláškou „aplikace tento údaj nevede"
 * a výběr kódu obce neměl popisek, takže hledání „Praha" se hned po první
 * odpovědi samo vynulovalo. Název se dohledá v TÉMŽ číselníku obcí, ze
 * kterého se vybírá na kartě vztahu; když se nenajde, pole zůstane prázdné.
 */
async function fillWorkplaceCityFromCode(): Promise<void> {
  const employment = a1Form.value.employment
  const code = employment.workplace_municipality_code?.trim() ?? ''
  if (code === '' || (employment.workplace_city ?? '').trim() !== '') return
  try {
    const found = (await payrollApi.searchJmhzMunicipalities(code, 5))
      .find(option => option.code === code)
    if (found === undefined) return
    employment.workplace_city = found.label
    municipalityOptions.value = [found]
  } catch {
    /* číselník nedostupný — pole zůstane k vyplnění ručně */
  }
}

function selectMunicipality(code: string | null): void {
  const selected = municipalityOptions.value.find(option => option.code === code)
  a1Form.value.employment.workplace_municipality_code = selected?.code ?? code
  if (selected) a1Form.value.employment.workplace_city = selected.label
}

async function a1AddAttachment(event: Event): Promise<void> {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]
  if (!file) return
  const buffer = new Uint8Array(await file.arrayBuffer())
  let binary = ''
  for (const byte of buffer) binary += String.fromCharCode(byte)
  a1Form.value.attachments.push({
    name: file.name,
    description: null,
    data_base64: btoa(binary),
  })
  input.value = ''
}

/**
 * Otisk formuláře pro porovnání „změnil se od načtení?".
 *
 * `effective_on` a `row_version` jdou stranou — jsou to údaje serveru, ne
 * uživatelův vstup, a záloha z minulého týdne by jinak vypadala jako změna.
 * `blankToNull` srovná vypsané a zase smazané pole s nevyplněným.
 */
function a1Comparable(payload: PayrollRegistrationA1ProfilePayload): string {
  const {
    effective_on: _effectiveOn,
    row_version: _rowVersion,
    ...rest
  } = blankToNull(payload)
  return JSON.stringify(rest)
}

async function loadA1Profile(): Promise<void> {
  a1ProfileLoading.value = true
  a1ProfileError.value = ''
  a1ProfileErrorCode.value = ''
  try {
    const view = await payrollApi.employmentRegistrationA1Profile(props.employmentId)
    a1Draft.value = view.draft
    a1Stored.value = view.profile
    a1Warnings.value = view.warnings ?? []
    // Formulář dostane VLASTNÍ kopii: psaní do sdílené odpovědi přepisovalo
    // i návrh z kmenových dat, na který se vrací „Vrátit návrh z kmenových dat".
    a1Form.value = JSON.parse(JSON.stringify(
      view.profile === null ? view.draft.suggested : editableA1Profile(view.profile),
    )) as PayrollRegistrationA1ProfilePayload
    a1Form.value.effective_on = view.draft.effective_on
    a1Form.value.row_version = view.draft.row_version
    withForeignInsuranceSection(a1Form.value)
    await fillWorkplaceCityFromCode()
    a1Baseline.value = a1Comparable(a1Form.value)
    a1LocalDraftArmed.value = false
    const local = readA1LocalDraft()
    a1LocalDraft.value = local !== null
      && a1Comparable(withForeignInsuranceSection(
        JSON.parse(JSON.stringify(local.payload)) as PayrollRegistrationA1ProfilePayload,
      )) !== a1Baseline.value
      ? local
      : null
  } catch (exception) {
    a1ProfileError.value = serverErrorMessage(
      exception,
      t('payroll.people.registration.a1.load_failed'),
    )
  } finally {
    a1ProfileLoading.value = false
  }
}

function a1ResetToSuggestion(): void {
  const draft = a1Draft.value
  if (draft === null) return
  a1Form.value = withForeignInsuranceSection(blankToNull(JSON.parse(
    JSON.stringify(draft.suggested),
  ) as PayrollRegistrationA1ProfilePayload))
  a1ProfileMessage.value = ''
}

async function saveA1Profile(): Promise<boolean> {
  if (!props.canWrite || a1ProfileSaving.value) return false
  let succeeded = false
  a1ProfileSaving.value = true
  a1ProfileError.value = ''
  a1ProfileErrorCode.value = ''
  a1ProfileMessage.value = ''
  try {
    const profile = await payrollApi.saveEmploymentRegistrationA1Profile(
      props.employmentId,
      blankToNull(a1Form.value),
    )
    a1Stored.value = profile
    a1Form.value = editableA1Profile(profile)
    clearA1LocalDraft()
    // Uložení běží přes tutéž kontrolu jako podání, takže rovnou obnoví
    // značky u polí — účetní nemusí po uložení mačkat ještě Kontrolu.
    a1Problems.value = profile.problems ?? []
    a1Checked.value = true
    /*
     * Číslo verze se neukazuje. U rozpracovaného profilu žádná historie
     * nevzniká (server pracovní řádek přepisuje) a „uložená verze 7" účetní
     * jen mátla — seznam verzí nikde není a udělat se s ním nedalo nic.
     */
    a1ProfileMessage.value = profile.status === 'draft'
      ? t('payroll.people.registration.a1.saved_draft', {
        count: a1Problems.value.length,
      }, a1Problems.value.length)
      : t('payroll.people.registration.a1.saved')
    resetPreparedFiling()
    await loadA1Profile()
    /*
     * Zápis do kmenových dat patří k uložení, ne do jiného panelu.
     *
     * Účetní tady opravuje údaj, který zná z personálního podkladu, a čeká, že
     * ho tím opravila i v kartě osoby. Když se to nestane, karta dál drží
     * starou hodnotu a formulář ji donekonečna hlásí jako rozdíl proti
     * snímku — rozdíl, se kterým odsud nešlo nic udělat.
     *
     * Zapisuje se jen to, co se OPRAVDU liší a co má v kmenových datech kam
     * jít; když se neliší nic, neděje se nic. Výsledek se vypíše, nikdy
     * potichu — zápis do evidence osoby se zamlčet nesmí.
     */
    if (a1WriteMasterData.value) {
      const writable = a1WritebackWritable.value.map(item => item.field)
      if (writable.length > 0) await writeA1MasterData(writable)
    }
    succeeded = true
  } catch (exception) {
    a1ProfileErrorCode.value = apiErrorCode(exception)
    a1ProfileError.value = serverErrorMessage(
      exception,
      t('payroll.people.registration.a1.save_failed'),
    )
    // Odmítnuté uložení je přesně ten okamžik, kdy účetní odchází chybějící
    // údaj doplnit jinam — záloha musí být na disku dřív, než kartu opustí.
    a1LocalDraftArmed.value = true
    writeA1LocalDraft(blankToNull(a1Form.value))
    // Tlačítko Uložit je na konci stovky polí; bez doskočení by hlášení
    // zůstalo mimo obrazovku a klik by vypadal, že se nestalo nic.
    await nextTick()
    a1ErrorPanel.value?.scrollIntoView?.({ behavior: 'smooth', block: 'center' })
  } finally {
    a1ProfileSaving.value = false
  }
  return succeeded
}

/*
 * Na kartě osoby se profil A1 ukládá společnou lištou dole jako každá jiná
 * sekce; Kontrola a Vrátit návrh zůstávají u formuláře, protože nic neukládají.
 */
const { managed: a1Managed } = usePersonCardSaveSection({
  label: () => t('payroll.people.card_save.a1_profile'),
  dirty: () => props.canWrite
    && a1ProfileOpen.value
    && !a1ProfileLoading.value
    && a1Baseline.value !== ''
    && a1Comparable(a1Form.value) !== a1Baseline.value,
  save: saveA1Profile,
  discard: () => { void loadA1Profile() },
  focus: () => a1ErrorPanel.value?.scrollIntoView?.({ behavior: 'smooth', block: 'center' }),
})

/**
 * Kontrola úplnosti. Nic neukládá a nic neblokuje — jen označí pole, na
 * kterých by přísné sestavení A1 padlo. Pravidla drží server: druhá kopie
 * v JS by se od té, na které padá podání, dřív nebo později rozešla.
 */
async function checkA1Profile(): Promise<void> {
  if (!props.canWrite || a1Checking.value) return
  a1Checking.value = true
  a1ProfileError.value = ''
  a1ProfileErrorCode.value = ''
  a1ProfileMessage.value = ''
  try {
    const result = await payrollApi.checkEmploymentRegistrationA1Profile(
      props.employmentId,
      blankToNull(a1Form.value),
    )
    a1Problems.value = result.problems
    a1Warnings.value = result.warnings ?? a1Warnings.value
    a1Checked.value = true
    a1ProfileMessage.value = result.complete
      ? t('payroll.people.registration.a1.check_complete')
      : t('payroll.people.registration.a1.check_incomplete', {
        count: result.problems.length,
      }, result.problems.length)
  } catch (exception) {
    a1ProfileErrorCode.value = apiErrorCode(exception)
    a1ProfileError.value = serverErrorMessage(
      exception,
      t('payroll.people.registration.a1.check_failed'),
    )
  } finally {
    a1Checking.value = false
  }
}

/**
 * Zálohu zakládá až rozdíl proti tomu, co přišlo ze serveru — nezměněný profil
 * by se zapisoval po každém otevření karty a nabídka „pokračovat
 * v rozpracovaném" by pak svítila pořád.
 */
watch(a1Form, () => {
  if (a1ProfileLoading.value) return
  if (a1Comparable(a1Form.value) === a1Baseline.value) {
    if (a1LocalDraftArmed.value) clearA1LocalDraft()
    return
  }
  a1LocalDraftArmed.value = true
  writeA1LocalDraft(blankToNull(a1Form.value))
}, { deep: true })

/**
 * Chybějící osobní údaje na tomhle formuláři nejsou — bere je z osobní
 * evidence. Odkaz na kartu osoby se proto nabízí jen u chyb, které tam míří;
 * u konfliktu verzí nebo výpadku sítě by účetní posílal na špatné místo.
 */
const A1_PERSON_DATA_ERRORS = [
  'registration_regzec_a1_required_field_missing',
  'registration_regzec_a1_foreign_data_missing',
]

const a1PersonCardTarget = computed(() => {
  if (!A1_PERSON_DATA_ERRORS.includes(a1ProfileErrorCode.value)) return null
  const query: Record<string, string> = {
    employment: String(props.employmentId),
    panel: 'statutory_evidence',
  }
  if (props.personId !== undefined) query.person = String(props.personId)
  return { name: 'payroll-people', query }
})

/**
 * „Co aplikace o osobě nevede" ukazuje jen to, co JEŠTĚ CHYBÍ.
 *
 * PROČ: seznam vzniká při sestavení návrhu a říká, co se nedá předvyplnit
 * z kmenových dat. Jenže se vypisoval dál i potom, co uživatel všechna ta
 * pole vyplnil a uložil — takže vedle sebe svítilo jedenáct „chybí" a zelené
 * „Kontrola prošla, 0 položek brání podání". Obojí byla pravda o něčem
 * jiném a dohromady to nedávalo smysl: první mluvilo o předvyplnění, druhé
 * o podání. Zůstat má jen to, s čím má uživatel ještě něco udělat.
 */
function a1ValueAt(path: string): unknown {
  let node: unknown = a1Form.value
  for (const key of path.split('.')) {
    if (node === null || typeof node !== 'object') return undefined
    node = (node as Record<string, unknown>)[key]
  }

  return node
}

/**
 * Pole, u kterých je „nevyplněno" legitimní odpověď, takže z hodnoty nejde
 * poznat, jestli je člověk potvrdil. U nich je potvrzením uložení verze —
 * přesně to po uživateli hláška chce („Potvrďte je ručně").
 */
const A1_CONFIRMED_BY_SAVING = new Set(['pension', 'facts.disability_card'])

function a1GapOutstanding(field: string): boolean {
  if (A1_CONFIRMED_BY_SAVING.has(field)) return a1SavedVersion.value === 0
  const value = a1ValueAt(field)
  if (value === undefined) return true
  if (value === null) return true

  return typeof value === 'string' ? value.trim() === '' : false
}

const a1Gaps = computed(
  () => (a1Draft.value?.missing ?? []).filter(gap => a1GapOutstanding(gap.field)),
)

/**
 * Doskok na položku z „Co aplikace o osobě nevede".
 *
 * PROČ TAKHLE: dřív tu byly dvě varianty — odkaz na kartu osoby, nebo text
 * „vyplňuje se zde". Ten text nebyl klikací, takže vypadal jako akce a nedělal
 * nic; a odkaz doručil účetní na kartu, kde pak hledala očima. Obojí je pro
 * uživatele totéž jako žádná navigace.
 *
 * Teď je tlačítko jedno a rozhoduje se AŽ PŘI KLIKNUTÍ, ne při vykreslení:
 * pole jako `foreign_worker.*` nebo `proof_identity.*` se na formuláři objevují
 * a mizí podle vyplněných hodnot, takže cokoli rozhodnutého dopředu by chvíli
 * po překreslení lhalo. Když položka na tomhle formuláři je, skočí se na ni
 * rovnou; když není, teprve pak se otevře karta osoby — s povelem, který
 * konkrétní pole tam vysvítit.
 */
const router = useRouter()

/**
 * Kam skočit, když gap nemá pole se stejnou adresou.
 *
 * `pension` je celý blok (aplikace důchodové údaje nevede vůbec) — kotvou je
 * jeho první pole; kdyby se skákalo na blok, kurzor by nedostal nikdo.
 */
const A1_GAP_ALIAS: Record<string, string> = {
  pension: 'pension.type_code',
  foreign_worker: 'foreign_worker.permit_type_code',
  proof_identity: 'proof_identity.type_code',
  tax_residency: 'tax_residency.identifier_type',
  employment: 'employment.activity_code',
  facts: 'facts.highest_education_code',
  permanent_address: 'permanent_address.street',
}

/** Sjednané podmínky vztahu — tam se mění druh činnosti. */
function warningEmploymentTarget(employmentId: number) {
  const query: Record<string, string> = {
    employment: String(employmentId),
    panel: 'employment_terms',
  }
  if (props.personId !== undefined) query.person = String(props.personId)

  return { name: 'payroll-people', query }
}

/** Panel karty osoby pro položky, které na tomhle formuláři nejsou. */
function a1GapPanel(field: string): string | null {
  if (field === 'identity' || field.startsWith('identity.')) {
    return 'registration_identity'
  }
  if (field === 'permanent_address' || field.startsWith('permanent_address.')) {
    return 'addresses'
  }
  if (field === 'tax_residency.country_code' || field === 'health_insurance_code') {
    return 'statutory_evidence'
  }
  if (field === 'foreign_worker.permit') return 'foreign_permit'

  return null
}

function a1GapTarget(field: string) {
  const panel = a1GapPanel(field)
  if (panel === null) return null
  const query: Record<string, string> = {
    employment: String(props.employmentId),
    panel,
    field,
  }
  if (props.personId !== undefined) query.person = String(props.personId)

  return { name: 'payroll-people', query }
}

/**
 * Hláška, když se doskočit nepodařilo. Tiché nic je horší než přiznání —
 * účetní by klikala dál a myslela si, že je rozbité tlačítko.
 */
const a1GapFailure = ref<string | null>(null)

async function focusA1Gap(field: string): Promise<void> {
  a1GapFailure.value = null
  const local = A1_GAP_ALIAS[field] ?? field
  await nextTick()
  if (revealField(fieldSelector(local))) return

  const target = a1GapTarget(field)
  if (target !== null) {
    await router.push(target)

    return
  }
  a1GapFailure.value = t('payroll.people.registration.a1.gap_unreachable', { field })
}

/*
 * Zápis zpátky do kmenových dat. Je to akce NAVÍC, ne podmínka uložení
 * profilu: `Uložit` projde vždycky a tenhle zápis jen srovná evidenci osoby
 * a pracovního vztahu s tím, co účetní opravila ve formuláři.
 */
/**
 * „Zapsat i do kmenových dat" u tlačítka Uložit.
 *
 * Zapnuté je schválně: uživatel opakovaně říkal, že když něco opraví, chce to
 * mít opravené i v kartě osoby a nechápe, k čemu je rozdíl mezi poslední verzí
 * a kmenovými daty. Vypnout to jde pro případ, kdy formulář nese hodnotu
 * platnou jen k datu registrace a kmen se měnit nemá.
 */
const a1WriteMasterData = ref(true)
const a1WritebackBusy = ref(false)
const a1WritebackMessage = ref('')
const a1WritebackProblems = ref<{ label: string, reason: string }[]>([])

const a1Writeback = computed(() => a1Draft.value?.writeback ?? [])
const a1WritebackWritable = computed(
  () => a1Writeback.value.filter(item => item.writable),
)

async function writeA1MasterData(fields: string[]): Promise<void> {
  if (!props.canWrite || a1WritebackBusy.value || fields.length === 0) return
  a1WritebackBusy.value = true
  a1WritebackMessage.value = ''
  a1WritebackProblems.value = []
  try {
    const result = await payrollApi.writeEmploymentRegistrationA1MasterData(
      props.employmentId,
      fields,
    )
    // Seznam se musí přepočítat, jinak by u zapsaného údaje zůstalo tlačítko.
    a1Draft.value = result.view.draft
    a1WritebackProblems.value = result.skipped.map(item => ({
      label: item.label,
      reason: item.reason,
    }))
    a1WritebackMessage.value = result.written.length === 0
      ? t('payroll.people.registration.a1.master_data_none')
      : t('payroll.people.registration.a1.master_data_written', {
        count: result.written.length,
        fields: result.written.map(item => item.label).join(', '),
      })
  } catch (exception) {
    a1WritebackProblems.value = [{
      label: t('payroll.people.registration.a1.master_data_title'),
      reason: serverErrorMessage(
        exception,
        t('payroll.people.registration.a1.master_data_failed'),
      ),
    }]
  } finally {
    a1WritebackBusy.value = false
  }
}

const deltaFieldOptions = computed(() => eventInteraction.value === 'correction'
  ? ['title_prefix', 'tax_residency', 'relationship_detail_code', 'highest_education_code', 'actual_start_on']
  : [
      'title_prefix', 'contact_address', 'tax_residency', 'relationship_detail_code',
      'health_insurance_code', 'highest_education_code',
    ])

/**
 * Rozsah změny A3. `single` = jeden změněný údaj (formulář níž), `full` a
 * `minimal` = dohlášení údajů zaměstnance přihlášeného dřív přes ONZ — skládá
 * se na serveru z uloženého profilu A1, formulář nic dalšího nevyplňuje.
 * Do 10009 jde den odeslání, proto se datum předvyplní dneškem.
 */
const changeScope = ref<'single' | 'full' | 'minimal'>('single')
const isCompletion = computed(
  () => eventInteraction.value === 'change' && changeScope.value !== 'single',
)

function localToday(): string {
  const now = new Date()
  const month = String(now.getMonth() + 1).padStart(2, '0')
  const day = String(now.getDate()).padStart(2, '0')
  return `${now.getFullYear()}-${month}-${day}`
}

async function openCompletion(): Promise<void> {
  eventInteraction.value = 'change'
  // Změna druhu události formulář vynuluje (watch níž) — předvyplnit až po něm.
  await nextTick()
  resetEventForm()
  changeScope.value = 'full'
  effectiveOn.value = localToday()
  eventFormOpen.value = true
}

const selectedEvent = computed(() => events.value.find(
  event => event.id === selectedEventId.value,
) ?? null)

const sourceReferenceRequired = computed(
  () => eventInteraction.value !== 'termination' && !isCompletion.value,
)

const deltaValueReady = computed(() => {
  if (deltaField.value === 'contact_address') {
    return addressStreet.value.trim() !== ''
      && addressHouseNumber.value.trim() !== ''
      && addressPostalCode.value.trim() !== ''
      && addressCity.value.trim() !== ''
      && /^[A-Z]{2}$/u.test(addressCountryCode.value)
  }
  if (deltaField.value === 'tax_residency') {
    return /^[A-Z]{2}$/u.test(residencyCountryCode.value)
      && residencyChangedOn.value !== ''
  }
  return deltaValue.value.trim() !== ''
})

const settlementNeeded = computed(() => ['4', '5'].includes(terminationReason.value))

const a2Ready = computed(() => {
  if (unemploymentMode.value === 'spec_early') {
    return /^[0-9]$/u.test(earlyTerminationReason.value)
  }
  if (['not_provided_3', 'provided'].includes(unemploymentMode.value)) {
    const periodsReady = pensionPeriods.value.length > 0
      && pensionPeriods.value.every(period => period.from !== '' && period.to !== '')
    if (!/^[0-9]{1,10}$/u.test(averageNetEarnings.value) || !periodsReady) return false
  }
  if (unemploymentMode.value === 'provided' && employmentType.value !== 'omit') {
    if (!/^[0-9]{1,3}$/u.test(terminationReason.value)) return false
    if (settlementNeeded.value) {
      if (entitlement.value === 'omit') return false
      if (entitlement.value === 'yes' && !/^[0-9]{1,10}$/u.test(settlementAmount.value)) {
        return false
      }
    }
  }
  return true
})

const eventCanSave = computed(() => {
  if (!props.canWrite || eventSaving.value || busy.value || submission.value !== null
    || effectiveOn.value === ''
  ) return false
  if (sourceReferenceRequired.value && sourceReference.value.trim() === '') return false
  if (eventInteraction.value === 'termination') return a2Ready.value
  if (isCompletion.value) return true
  if (eventInteraction.value === 'change') return deltaValueReady.value
  if (eventInteraction.value === 'correction') {
    return deltaValueReady.value
      && (!correctionNeedsAttachment.value || cancellationAttachment.value !== null)
      && discoveredOn.value !== ''
      && sourceSubmissionId.value !== null
      && sourceSubmissionId.value > 0
  }
  if (eventInteraction.value === 'variable_symbol_transfer') {
    return /^[0-9]{8,10}$/u.test(newVariableSymbol.value)
  }
  if (eventInteraction.value === 'czech_legislation_start') {
    return foreignName.value.trim() !== '' && /^[A-Z]{2}$/u.test(foreignCountryCode.value)
  }
  if (eventInteraction.value === 'czech_legislation_end') {
    return foreignName.value.trim() !== ''
      && /^[A-Z]{2}$/u.test(foreignCountryCode.value)
      && foreignIdentifier.value.trim() !== ''
  }
  if (eventInteraction.value === 'cancellation') {
    return sourceSubmissionId.value !== null
      && sourceSubmissionId.value > 0
      && (cancellationReason.value === 'not_started'
        ? notStartedConfirmed.value
        : cancellationAttachment.value !== null)
  }
  return true
})

/**
 * Jedna plná primární akce podle stavu: dokud není náhled, je hlavní krok
 * „zjistit, co se podá"; potom „připravit podání". Dvě plná tlačítka vedle
 * sebe by nutila uživatele hádat, které z nich je to úřední.
 */
const primaryAction = computed<'preview' | 'prepare' | 'done'>(() => {
  if (submission.value) return 'done'
  return preview.value ? 'prepare' : 'preview'
})

/**
 * Živá přihláška vztahu načtená se kartou (UI-23). Bez ní karta po obnovení
 * stránky nevěděla, že podání už čeká ve frontě, a nabízela ho připravit znovu.
 */
const currentRegistration = ref<PayrollRegistrationCurrent | null>(null)
const QUEUE_ROUTE = { name: 'payroll-submissions-tab', params: { tab: 'queue' } } as const

async function loadCurrentRegistration(): Promise<void> {
  try {
    currentRegistration.value = await payrollApi.currentEmploymentRegistration(
      props.employmentId,
      environment.value,
    )
  } catch {
    currentRegistration.value = null
  }
}

/** Připravená přihláška čeká ve frontě: nová příprava se nenabízí. */
const registrationInQueue = computed(() => submission.value === null
  && selectedEventId.value === null
  && currentRegistration.value !== null
  && !currentRegistration.value.sent)

const currentRegistrationStatus = computed(() => {
  const status = currentRegistration.value?.status ?? ''
  const key = `payroll.submissions.overview.status.${status}`
  return te(key) ? t(key) : status
})

function todayIso(): string {
  const now = new Date()
  const pad = (value: number) => String(value).padStart(2, '0')
  return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`
}

/** Lhůta už uplynula a nic zatím neodešlo (UI-27). */
const deadlineOverdue = computed(() => {
  const value = deadline.value
  if (!value || value.statutory === false || value.derived === false) return false
  if (transportAttempt.value !== null) return false
  return typeof value.due_on === 'string' && value.due_on < todayIso()
})

const agendaLabel = computed(() => {
  const agenda = submission.value?.agenda_code ?? preview.value?.agenda_code
  return agenda ? t(`payroll.people.registration.agenda.${agenda}`) : ''
})

const interactionLabel = computed(() => {
  const key = submission.value?.interaction ?? preview.value?.interaction
  return key ? t(`payroll.people.registration.interaction.${key}`) : ''
})

const deadline = computed(
  () => submission.value?.deadline ?? preview.value?.deadline ?? null,
)

const transportAttempt = computed<PayrollJmhzTransportAttempt | null>(
  () => transport.value?.attempt ?? null,
)

const canPoll = computed(
  () => transportAttempt.value?.status === 'awaiting_protocol',
)

const canClose = computed(
  () => transport.value?.settled === true
    && transportAttempt.value?.closed_at == null,
)

const canSend = computed(() => transportAttempt.value === null || [
  'prepared',
  'failed',
  'expired',
].includes(transportAttempt.value.status))

/**
 * Změna nebo oprava připravená ve formátu REGZEC25, kterou ČSSZ od 23. 12. 2026
 * nepřijme: místo odeslání se nabízí Připravit znovu.
 */
const vintageExpired = computed(() => submission.value !== null
  && transportAttempt.value === null
  && (submission.value.vintage_expired === true || staleSendRejected.value))

const transportActions = computed<ActionItem[]>(() => {
  if (!submission.value) return []

  return [
    {
      key: 'registration-reprepare',
      label: t('payroll.people.registration.reprepare'),
      icon: 'cycle',
      tier: 'primary',
      variant: 'warning',
      show: vintageExpired.value,
      disabled: !props.canWrite || transportBusy.value !== null,
      disabledReason: !props.canWrite
        ? t('payroll.people.registration.write_required')
        : undefined,
      loading: transportBusy.value === 'reprepare',
      run: reprepare,
    },
    {
      key: 'registration-send',
      label: t(`payroll.people.registration.send_${environment.value}`),
      icon: 'send',
      tier: 'primary',
      variant: 'primary',
      show: canSend.value && !vintageExpired.value,
      disabled: !props.canWrite || transportBusy.value !== null,
      disabledReason: !props.canWrite
        ? t('payroll.people.registration.write_required')
        : undefined,
      loading: transportBusy.value === 'send',
      run: send,
    },
    {
      key: 'registration-poll',
      label: t('payroll.people.registration.poll'),
      icon: 'cycle',
      tier: 'primary',
      variant: 'primary',
      show: canPoll.value,
      disabled: !props.canWrite || transportBusy.value !== null,
      disabledReason: !props.canWrite
        ? t('payroll.people.registration.write_required')
        : undefined,
      loading: transportBusy.value === 'poll',
      run: poll,
    },
    {
      key: 'registration-close',
      label: t('payroll.people.registration.close'),
      icon: 'check',
      tier: 'primary',
      variant: 'success',
      show: canClose.value,
      disabled: !props.canWrite || transportBusy.value !== null,
      disabledReason: !props.canWrite
        ? t('payroll.people.registration.write_required')
        : undefined,
      loading: transportBusy.value === 'close',
      run: close,
    },
  ]
})

function resetPreparedFiling(): void {
  preview.value = null
  submission.value = null
  staleSendRejected.value = false
  transport.value = null
  transportMessage.value = ''
  showXml.value = false
}

function resetEventForm(): void {
  effectiveOn.value = ''
  sourceReference.value = ''
  sourceSubmissionId.value = null
  discoveredOn.value = ''
  learnedOn.value = ''
  newVariableSymbol.value = ''
  deltaField.value = eventInteraction.value === 'correction'
    ? 'title_prefix'
    : 'title_prefix'
  deltaValue.value = ''
  changeScope.value = 'single'
  addressStreet.value = ''
  addressHouseNumber.value = ''
  addressOrientationNumber.value = ''
  addressPostalCode.value = ''
  addressCity.value = ''
  addressCountryCode.value = 'CZ'
  addressRuianPoint.value = ''
  residencyCountryCode.value = ''
  residencyChangedOn.value = ''
  foreignName.value = ''
  foreignCountryCode.value = ''
  foreignIdentifier.value = ''
  foreignStreet.value = ''
  foreignHouseNumber.value = ''
  foreignOrientationNumber.value = ''
  foreignPostalCode.value = ''
  foreignCity.value = ''
  foreignSector.value = ''
  endedByDeath.value = 'omit'
  unemploymentMode.value = 'omit'
  earlyTerminationReason.value = ''
  averageNetEarnings.value = ''
  pensionPeriods.value = [{ from: '', to: '' }]
  employmentType.value = 'omit'
  terminationReason.value = ''
  entitlement.value = 'omit'
  paidInFull.value = 'no'
  settlementAmountKind.value = 'replacement'
  settlementAmount.value = ''
  notStartedConfirmed.value = false
  cancellationReason.value = 'not_started'
  cancellationAttachment.value = null
  cancellationAttachmentDescription.value = ''
  identifiersVerifiedInCsszList.value = false
}

async function addCancellationAttachment(event: Event): Promise<void> {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]
  if (!file) return
  const buffer = new Uint8Array(await file.arrayBuffer())
  let binary = ''
  for (const byte of buffer) binary += String.fromCharCode(byte)
  cancellationAttachment.value = { name: file.name, data_base64: btoa(binary) }
  input.value = ''
}

function eventOptionLabel(event: PayrollRegistrationEvent): string {
  const used = event.consumed
    ? ` · ${t('payroll.people.registration.event.consumed')}`
    : ''
  return `A${event.action_code} · ${formatDate(event.effective_on)} · ${t(`payroll.people.registration.interaction.${event.interaction}`)}${used}`
}

async function loadEvents(): Promise<void> {
  eventsBusy.value = true
  eventError.value = ''
  try {
    events.value = await payrollApi.employmentRegistrationEvents(
      props.employmentId,
      environment.value,
    )
    if (selectedEventId.value !== null
      && !events.value.some(event => event.id === selectedEventId.value)
    ) {
      selectedEventId.value = null
    }
  } catch (exception) {
    eventError.value = serverErrorMessage(
      exception,
      t('payroll.people.registration.event.load_failed'),
    )
  } finally {
    eventsBusy.value = false
  }
}

function addPensionPeriod(): void {
  pensionPeriods.value.push({ from: '', to: '' })
}

function removePensionPeriod(index: number): void {
  if (pensionPeriods.value.length > 1) pensionPeriods.value.splice(index, 1)
}

function optionalText(value: string): string | undefined {
  const trimmed = value.trim()
  return trimmed === '' ? undefined : trimmed
}

function deltaPayload(): Record<string, unknown> {
  if (deltaField.value === 'contact_address') {
    return {
      contact_address: {
        street: addressStreet.value.trim(),
        house_number: addressHouseNumber.value.trim(),
        postal_code: addressPostalCode.value.trim(),
        city: addressCity.value.trim(),
        country_code: addressCountryCode.value.trim().toUpperCase(),
        ...(optionalText(addressOrientationNumber.value) === undefined
          ? {}
          : { orientation_number: addressOrientationNumber.value.trim() }),
        ...(optionalText(addressRuianPoint.value) === undefined
          ? {}
          : { ruian_point: addressRuianPoint.value.trim() }),
      },
    }
  }
  if (deltaField.value === 'tax_residency') {
    return {
      tax_residency: {
        country_code: residencyCountryCode.value.trim().toUpperCase(),
        changed_on: residencyChangedOn.value,
      },
    }
  }
  if (deltaField.value === 'actual_start_on') {
    return { employment: { actual_start_on: deltaValue.value.trim() } }
  }
  return { [deltaField.value]: deltaValue.value.trim() }
}

/** Oprava skutečného dne nástupu (A4) jde na ČSSZ jen s průvodním dopisem v příloze. */
const correctionNeedsAttachment = computed(
  () => eventInteraction.value === 'correction' && deltaField.value === 'actual_start_on',
)

/** Proč se čistý průměr nepředvyplnil (typicky daňové zvýhodnění na dítě). */
const a2NetAverageNote = ref('')

/**
 * Varianta odhlášky podle druhu činnosti, zrcadlo
 * `PayrollRegistrationBusinessMatrix::variantFor()`. Bez vyplněného druhu
 * činnosti (převzatý vztah bez profilu A1) je to běžná varianta OST.
 */
function a2VariantIsOst(activityCode: string | null | undefined, detailCode: string | null | undefined): boolean {
  if (!activityCode) return true
  if (activityCode === '10') return false
  if (['11', '12', '13', '14', 'M'].includes(activityCode)) return false
  return !(/^[1-9]$/.test(activityCode) && detailCode === '2')
}

/**
 * Předvyplnění odhlášky A2 ze záznamu o skončení na kartě vztahu. Důvod
 * skončení se tak zadává jen jednou; schválení A2 jiný kód stejně odmítne.
 * Doby důchodového pojištění a „vyplaceno v plné výši" zůstávají na účetní.
 */
async function applyA2Prefill(): Promise<void> {
  const prefill = props.a2Prefill
  if (!prefill) return
  // Den skončení je týž jako na kartě vztahu; dřív ho účetní opisovala (C-19).
  if (props.terminationEndDate) effectiveOn.value = props.terminationEndDate
  // Příznak úmrtí se posílá jen u varianty A2-OST (povinně ano/ne); jinde musí
  // zůstat prázdný. Dřív se předvyplnilo jen „ano", takže u běžné odhlášky
  // zůstalo „Nevyplňovat" a schválení ho pak vyžadovalo.
  if (prefill.ended_by_death) endedByDeath.value = 'yes'
  else if (a2VariantIsOst(a1Form.value.employment.activity_code, a1Form.value.employment.relationship_detail_code)) {
    endedByDeath.value = 'no'
  }
  a2NetAverageNote.value = prefill.average_net_note ?? ''
  const unemployment = prefill.unemployment
  if (unemployment === null) {
    unemploymentMode.value = 'omit'
    return
  }
  unemploymentMode.value = 'provided'
  employmentType.value = unemployment.employment_type
  // Změna druhu zaměstnání přes watch přepíná druh plnění — až po něm.
  await nextTick()
  terminationReason.value = unemployment.termination_reason
  if (unemployment.average_net_earnings !== null) averageNetEarnings.value = unemployment.average_net_earnings
  if (unemployment.entitlement === undefined) {
    entitlement.value = 'omit'
    return
  }
  entitlement.value = unemployment.entitlement ? 'yes' : 'no'
  // Druh (odstupné / náhrada § 271ca) je jistý hned ze záznamu, částka až po
  // výpočtu nebo zúčtování — bez ní ji účetní doplní, nárok ale nezmizí.
  if (unemployment.entitlement && unemployment.settlement_kind) {
    settlementAmountKind.value = unemployment.settlement_kind
    if (unemployment.settlement_amount) settlementAmount.value = unemployment.settlement_amount
  }
}

function a2Payload(): Pick<PayrollRegistrationEventInput, 'ended_by_death' | 'unemployment'> {
  const result: Pick<PayrollRegistrationEventInput, 'ended_by_death' | 'unemployment'> = {}
  if (endedByDeath.value !== 'omit') result.ended_by_death = endedByDeath.value === 'yes'
  if (unemploymentMode.value === 'omit') return result
  if (unemploymentMode.value === 'spec_early') {
    result.unemployment = { early_termination_reason: earlyTerminationReason.value }
    return result
  }
  result.unemployment = { mode: unemploymentMode.value }
  if (unemploymentMode.value === 'not_provided_2') return result
  result.unemployment.average_net_earnings = averageNetEarnings.value
  result.unemployment.pension_periods = pensionPeriods.value.map(period => ({ ...period }))
  if (unemploymentMode.value !== 'provided' || employmentType.value === 'omit') return result
  result.unemployment.employment_type = employmentType.value
  if (employmentType.value === '1') {
    result.unemployment.termination_reason = terminationReason.value
  } else {
    result.unemployment.service_termination_reason = terminationReason.value
  }
  if (!settlementNeeded.value) return result
  result.unemployment.entitlement = entitlement.value === 'yes'
  if (entitlement.value !== 'yes') return result
  result.unemployment.paid_in_full = paidInFull.value === 'yes'
  result.unemployment[settlementAmountKind.value as 'replacement'] = settlementAmount.value
  return result
}

function foreignInsurancePayload(): NonNullable<PayrollRegistrationEventInput['foreign_insurance']> {
  const current = eventInteraction.value === 'czech_legislation_start' ? 'P' : 'S'
  return {
    current,
    name: foreignName.value.trim(),
    country_code: foreignCountryCode.value.trim().toUpperCase(),
    ...(optionalText(foreignIdentifier.value) === undefined
      ? {}
      : { identifier: foreignIdentifier.value.trim() }),
    ...(optionalText(foreignStreet.value) === undefined ? {} : { street: foreignStreet.value.trim() }),
    ...(optionalText(foreignHouseNumber.value) === undefined ? {} : { house_number: foreignHouseNumber.value.trim() }),
    ...(optionalText(foreignOrientationNumber.value) === undefined ? {} : { orientation_number: foreignOrientationNumber.value.trim() }),
    ...(optionalText(foreignPostalCode.value) === undefined ? {} : { postal_code: foreignPostalCode.value.trim() }),
    ...(optionalText(foreignCity.value) === undefined ? {} : { city: foreignCity.value.trim() }),
    ...(optionalText(foreignSector.value) === undefined ? {} : { sector: foreignSector.value.trim() }),
  }
}

function eventPayload(): PayrollRegistrationEventInput {
  const payload: PayrollRegistrationEventInput = {
    environment: environment.value,
    interaction: eventInteraction.value,
    effective_on: effectiveOn.value,
  }
  if (sourceReferenceRequired.value) payload.source_reference = sourceReference.value.trim()
  if (eventInteraction.value === 'termination') Object.assign(payload, a2Payload())
  if (isCompletion.value) {
    // Stejný klíč jako u hromadného dohlášení — opakované schválení téhož
    // dne vrátí tutéž událost místo duplicity.
    payload.source_reference = `dohlaseni:${changeScope.value}:${effectiveOn.value}`
    payload.completion = changeScope.value as 'full' | 'minimal'
  } else if (eventInteraction.value === 'change') {
    payload.changes = deltaPayload()
    if (learnedOn.value !== '') payload.learned_on = learnedOn.value
  }
  if (eventInteraction.value === 'correction') {
    payload.corrections = deltaPayload()
    payload.discovered_on = discoveredOn.value
    payload.source_submission_id = sourceSubmissionId.value ?? undefined
    if (correctionNeedsAttachment.value && cancellationAttachment.value !== null) {
      payload.explanation_attachment = {
        ...cancellationAttachment.value,
        description: optionalText(cancellationAttachmentDescription.value) ?? null,
      }
    }
  }
  if (eventInteraction.value === 'variable_symbol_transfer') {
    payload.new_variable_symbol = newVariableSymbol.value
  }
  if (['czech_legislation_start', 'czech_legislation_end'].includes(eventInteraction.value)) {
    payload.foreign_insurance = foreignInsurancePayload()
  }
  if (eventInteraction.value === 'cancellation') {
    payload.source_submission_id = sourceSubmissionId.value ?? undefined
    if (cancellationReason.value === 'not_started') {
      payload.not_started = true
    } else {
      payload.not_started = false
      if (cancellationAttachment.value !== null) {
        payload.explanation_attachment = {
          ...cancellationAttachment.value,
          description: optionalText(cancellationAttachmentDescription.value) ?? null,
        }
      }
    }
  }
  if (eventInteraction.value === 'termination' && identifiersVerifiedInCsszList.value) {
    payload.identifiers_verified_in_cssz_list = true
  }
  return payload
}

async function saveEvent(): Promise<void> {
  if (!eventCanSave.value) return
  eventSaving.value = true
  eventError.value = ''
  eventErrorCode.value = ''
  try {
    const event = await payrollApi.approveEmploymentRegistrationEvent(
      props.employmentId,
      eventPayload(),
    )
    events.value = [event, ...events.value.filter(existing => existing.id !== event.id)]
    selectedEventId.value = event.id
    eventFormOpen.value = false
    resetEventForm()
    await run('preview')
  } catch (exception) {
    eventErrorCode.value = apiErrorCode(exception)
    eventError.value = serverErrorMessage(
      exception,
      t('payroll.people.registration.event.save_failed'),
    )
  } finally {
    eventSaving.value = false
  }
}

/**
 * Detekce změn hlásitelných do registru pojištěnců.
 *
 * Přepočet běží při otevření karty a po přepnutí prostředí. Není to jen čtení:
 * zakládá návrhy povinností s běžící osmidenní lhůtou, což je přesně ten
 * okamžik, kdy se zaměstnavatel o změně dozvídá (§ 19 odst. 5 zákona
 * č. 323/2025 Sb.).
 */
async function loadChangeDetection(): Promise<void> {
  if (!props.canWrite) return
  changesBusy.value = true
  changeError.value = ''
  try {
    changeDetection.value = await payrollApi.detectEmploymentRegistrationChanges(
      props.employmentId,
      environment.value,
    )
    for (const proposal of changeDetection.value.proposals) {
      if (proposal.effective_on && fileEffectiveOn.value[proposal.id] === undefined) {
        fileEffectiveOn.value[proposal.id] = proposal.effective_on
      }
    }
  } catch (exception) {
    changeError.value = serverErrorMessage(
      exception,
      t('payroll.people.registration.changes.load_failed'),
    )
  } finally {
    changesBusy.value = false
  }
}

async function fileProposal(proposalId: number): Promise<void> {
  proposalBusy.value = proposalId
  changeError.value = ''
  try {
    await payrollApi.fileEmploymentRegistrationChange(
      props.employmentId,
      proposalId,
      environment.value,
      fileEffectiveOn.value[proposalId] ?? null,
    )
    await Promise.all([loadChangeDetection(), loadEvents()])
  } catch (exception) {
    changeError.value = serverErrorMessage(
      exception,
      t('payroll.people.registration.changes.file_failed'),
    )
  } finally {
    proposalBusy.value = null
  }
}

async function dismissProposal(proposalId: number): Promise<void> {
  const note = (dismissNotes.value[proposalId] ?? '').trim()
  if (note === '') return
  proposalBusy.value = proposalId
  changeError.value = ''
  try {
    await payrollApi.dismissEmploymentRegistrationChange(
      props.employmentId,
      proposalId,
      note,
      environment.value,
    )
    delete dismissNotes.value[proposalId]
    dismissOpenFor.value = null
    await loadChangeDetection()
  } catch (exception) {
    changeError.value = serverErrorMessage(
      exception,
      t('payroll.people.registration.changes.dismiss_failed'),
    )
  } finally {
    proposalBusy.value = null
  }
}

function proposalTitle(proposal: PayrollRegistrationChangeProposal): string {
  return proposal.duty_kind === 'health_insurer_change'
    ? t('payroll.people.registration.changes.duty.health_insurer_change')
    : t('payroll.people.registration.changes.duty.regzec_change', {
        action: `A${proposal.action_code ?? 3}`,
      })
}

/** Souhrn „co se změnilo" — konkrétní položky, ne jen „něco". */
function proposalSummary(proposal: PayrollRegistrationChangeProposal): string {
  const groups = [...new Set(proposal.findings.map(finding => finding.group))]
  return groups
    .map(group => t(`payroll.people.registration.changes.group.${group}`))
    .join(', ')
}

/**
 * Nálezy, které podání A3 blokují jen proto, že údaj chybí v profilu A1:
 * kmenová data vedou adresu jedním řádkem (bez čísla popisného zvlášť)
 * a u povolení cizince jen označení, ne číslo rozhodnutí. Proklik otevře
 * profil přímo u pole; po uložení detekce navrhne podání znovu.
 */
const PROFILE_GAP_FIELDS: Record<string, string> = {
  registration_change_permanent_address_incomplete: 'permanent_address.house_number',
  registration_change_foreign_permit_incomplete: 'foreign_worker.permit_identifier',
  registration_change_czech_residence_address_incomplete: 'czech_residence_address.house_number',
  registration_change_tax_residence_address_incomplete: 'tax_residency.residence_address.house_number',
  registration_change_proof_identity_incomplete: 'proof_identity.number',
}

function proposalProfileGaps(
  proposal: PayrollRegistrationChangeProposal,
): { reason_code: string, field: string }[] {
  const seen = new Set<string>()
  const gaps: { reason_code: string, field: string }[] = []
  for (const item of proposal.unsupported) {
    const field = PROFILE_GAP_FIELDS[item.reason_code]
    if (field === undefined || seen.has(item.reason_code)) continue
    seen.add(item.reason_code)
    gaps.push({ reason_code: item.reason_code, field })
  }
  return gaps
}

async function openProfileGap(field: string): Promise<void> {
  a1ProfileOpen.value = true
  await nextTick()
  await focusA1Gap(field)
}

function proposalActions(
  proposal: PayrollRegistrationChangeProposal,
): ActionItem[] {
  return [
    {
      key: `registration-change-file-${proposal.id}`,
      label: t('payroll.people.registration.changes.file'),
      icon: 'send',
      tier: 'primary',
      variant: 'primary',
      show: proposal.fileable,
      disabled: !props.canWrite || proposalBusy.value !== null,
      loading: proposalBusy.value === proposal.id,
      run: () => { void fileProposal(proposal.id) },
    },
    {
      key: `registration-change-dismiss-${proposal.id}`,
      label: t('payroll.people.registration.changes.dismiss'),
      icon: 'check',
      tier: 'secondary',
      disabled: !props.canWrite || proposalBusy.value !== null,
      run: () => {
        dismissOpenFor.value = dismissOpenFor.value === proposal.id
          ? null
          : proposal.id
      },
    },
  ]
}

watch(selectedEventId, resetPreparedFiling)
watch(registrationMode, resetPreparedFiling)
watch(startNotKnownInAdvance, resetPreparedFiling)
const unknownStartApplicable = computed(() =>
  selectedEventId.value === null
  && (startNotKnownInAdvance.value
    || (preview.value?.interaction === 'direct_full_registration'
      && preview.value.before_start_choice !== true)))
watch(eventInteraction, () => {
  resetEventForm()
  deltaField.value = deltaFieldOptions.value[0] ?? 'title_prefix'
})
watch(employmentType, value => {
  settlementAmountKind.value = ['2', '3', '4'].includes(value) ? 'severance_pay' : 'replacement'
})
// UI-26: po uložení kmenových dat na kartě osoby (občanství, rodné příjmení,
// podmínky vztahu) se profil A1 načte znovu. Dřív držel stav z načtení karty
// a doplněné údaje hlásil jako chybějící až do obnovení stránky. Neuložené
// úpravy formuláře nezmizí: zůstávají v lokálním konceptu, který se nabídne.
watch(() => props.masterDataVersion ?? 0, (version, previous) => {
  if (version !== previous) void loadA1Profile()
})
watch(environment, async () => {
  selectedEventId.value = null
  resetPreparedFiling()
  await Promise.all([loadEvents(), loadChangeDetection(), loadCurrentRegistration()])
})
onMounted(() => {
  void loadPayrollJmhzOptions().then((loaded) => {
    jmhzOptions.value = loaded
    jmhzOptionsFailed.value = loaded === null
  })
  return Promise.all([
    loadEvents(),
    loadA1Profile(),
    loadChangeDetection(),
    loadCurrentRegistration(),
  ])
})

/**
 * Proklik z chybějícího údaje: údaje osoby vedou na kartu osoby s povelem
 * `?panel=&field=` (rozbalí „Úplnou osobní evidenci" i sbalenou sekci
 * a zaostří pole), údaje zaměstnavatele do Nastavení mezd.
 */
function problemTarget(
  target: PayrollRegistrationProblemTarget,
  panel: string | null,
  field: string,
) {
  if (target === 'employer_settings') {
    return {
      name: 'payroll-settings',
      query: { tab: 'employer' },
      hash: '#payroll-employer-offices',
    }
  }
  if (panel === null) return null
  const query: Record<string, string> = {
    employment: String(props.employmentId),
    panel,
    field,
  }
  if (props.personId !== undefined) query.person = String(props.personId)

  return { name: 'payroll-people', query }
}

/** Proklik k nápravě blokátoru podle kódu chyby, viz `registrationRemediation.ts`. */
function remediationTarget(code: string) {
  const remediation = registrationRemediation(code)
  if (remediation === null || remediation.kind === 'a1_profile') return null
  return remediation.kind === 'employer_settings'
    ? problemTarget('employer_settings', null, 'employer_variable_symbol')
    : problemTarget('person', remediation.panel, remediation.field)
}

/**
 * Do kterého prostředí ČSSZ se podává. Dokud nic neodešlo, musí to být vidět
 * rovnou u tlačítek — výchozí je produkce a zkouška z testovací instance by
 * jinak skončila v ostrém registru jen proto, že si nikdo nevšiml přepínače.
 */
const nothingSentYet = computed(() => transportAttempt.value === null)

/** UI-25: chybějící profil A1 se opravuje tady na kartě, ne v podmínkách vztahu. */
async function openA1ProfileFromError(): Promise<void> {
  a1ProfileOpen.value = true
  await nextTick()
  document.querySelector('[data-test="registration-a1-profile"]')
    ?.scrollIntoView({ behavior: 'smooth', block: 'start' })
}

async function run(action: 'preview' | 'prepare'): Promise<void> {
  busy.value = true
  error.value = ''
  errorCode.value = ''
  errorProblems.value = []
  staleSendRejected.value = false
  try {
    if (action === 'preview') {
      submission.value = null
      transport.value = null
      transportMessage.value = ''
      preview.value = selectedEventId.value === null
        ? await (startNotKnownInAdvance.value
          ? payrollApi.previewEmploymentRegistration(
              props.employmentId,
              environment.value,
              null,
              registrationMode.value,
              { startNotKnownInAdvance: true },
            )
          : registrationMode.value === 'full'
            ? payrollApi.previewEmploymentRegistration(
                props.employmentId,
                environment.value,
                null,
                'full',
              )
            : payrollApi.previewEmploymentRegistration(
                props.employmentId,
                environment.value,
              ))
        : await payrollApi.previewEmploymentRegistration(
            props.employmentId,
            environment.value,
            selectedEventId.value,
          )
      if (preview.value.before_start_choice === true) beforeStartChoice.value = true
    } else {
      submission.value = selectedEventId.value === null
        ? await (startNotKnownInAdvance.value
          ? payrollApi.prepareEmploymentRegistration(
              props.employmentId,
              environment.value,
              null,
              registrationMode.value,
              { startNotKnownInAdvance: true },
            )
          : registrationMode.value === 'full'
            ? payrollApi.prepareEmploymentRegistration(
                props.employmentId,
                environment.value,
                null,
                'full',
              )
            : payrollApi.prepareEmploymentRegistration(
                props.employmentId,
                environment.value,
              ))
        : await payrollApi.prepareEmploymentRegistration(
            props.employmentId,
            environment.value,
            selectedEventId.value,
          )
      const status = await payrollApi.employmentRegistrationTransportStatus(
        submission.value.submission_id,
        environment.value,
      )
      transport.value = status.attempt === null ? null : {
        attempt: status.attempt,
        acknowledgement: null,
        settled: status.attempt.status === 'completed',
        report: null,
      }
    }
  } catch (exception) {
    // Hláška ze serveru jmenuje konkrétní chybějící údaj — nesmí ji přebít
    // obecný text, jinak uživatel neví, co doplnit.
    errorProblems.value = registrationMissingItems(exception)
    const code = apiErrorCode(exception)
    errorCode.value = code
    if (code === 'registration_already_prepared' || code === 'registration_already_filed') {
      void loadCurrentRegistration()
    }
    error.value = errorProblems.value.length > 0
      ? t('payroll.people.registration.missing.title', errorProblems.value.length)
      : serverErrorMessage(
        exception,
        t('payroll.people.registration.failed'),
      )
  } finally {
    busy.value = false
  }
}

async function send(): Promise<void> {
  if (!submission.value || !props.canWrite) return
  const confirmed = await confirmProductionSend(
    environment.value,
    t('payroll.production_send.registration', { agenda: submission.value.agenda_code }),
  )
  if (!confirmed || !submission.value || transportBusy.value !== null) return
  transportBusy.value = 'send'
  error.value = ''
  transportMessage.value = ''
  try {
    const result = await payrollApi.sendEmploymentRegistrationTransport(
      submission.value.submission_id,
      environment.value,
      crypto.randomUUID(),
    )
    transport.value = {
      attempt: result.attempt,
      acknowledgement: result.acknowledgement,
      settled: result.settled,
      report: null,
    }
  } catch (exception) {
    if (apiErrorCode(exception) === 'registration_regzec25_change_stale') {
      staleSendRejected.value = true
    }
    error.value = serverErrorMessage(
      exception,
      t('payroll.people.registration.send_failed'),
    )
  } finally {
    transportBusy.value = null
  }
}

/** Nová věta REGZEC27 ze stejné události; zastaralé podání server zruší. */
async function reprepare(): Promise<void> {
  if (!submission.value || !props.canWrite || selectedEventId.value === null) return
  transportBusy.value = 'reprepare'
  error.value = ''
  transportMessage.value = ''
  try {
    submission.value = await payrollApi.prepareEmploymentRegistration(
      props.employmentId,
      environment.value,
      selectedEventId.value,
      'auto',
      { replacesSubmissionId: submission.value.submission_id },
    )
    staleSendRejected.value = false
    transport.value = null
  } catch (exception) {
    error.value = serverErrorMessage(
      exception,
      t('payroll.people.registration.reprepare_failed'),
    )
  } finally {
    transportBusy.value = null
  }
}

async function poll(): Promise<void> {
  if (!transportAttempt.value || !props.canWrite) return
  transportBusy.value = 'poll'
  error.value = ''
  transportMessage.value = ''
  try {
    transport.value = await payrollApi.pollEmploymentRegistrationTransportAttempt(
      transportAttempt.value.id,
      environment.value,
    )
  } catch (exception) {
    error.value = serverErrorMessage(
      exception,
      t('payroll.people.registration.poll_failed'),
    )
  } finally {
    transportBusy.value = null
  }
}

async function close(): Promise<void> {
  if (!transportAttempt.value || !props.canWrite) return
  transportBusy.value = 'close'
  error.value = ''
  transportMessage.value = ''
  try {
    const result = await payrollApi.closeEmploymentRegistrationTransportAttempt(
      transportAttempt.value.id,
      environment.value,
    )
    transport.value = {
      ...transport.value!,
      attempt: result.attempt,
    }
    transportMessage.value = t('payroll.people.registration.closed', {
      id: result.attempt.id,
    })
  } catch (exception) {
    error.value = serverErrorMessage(
      exception,
      t('payroll.people.registration.close_failed'),
    )
  } finally {
    transportBusy.value = null
  }
}

async function copyXml(): Promise<void> {
  if (preview.value?.xml) await navigator.clipboard.writeText(preview.value.xml)
}
</script>

<template>
  <section
    class="mt-4 border-t border-neutral-200 pt-4"
    data-test="employment-registration"
  >
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div>
        <h4 class="text-sm font-semibold text-neutral-900">
          {{ t('payroll.people.registration.title') }}
        </h4>
        <p class="mt-0.5 text-xs text-neutral-500">
          {{ t('payroll.people.registration.description') }}
        </p>
      </div>
      <div class="flex flex-wrap items-center gap-2">
        <label v-if="submissionTestAllowed" class="flex items-center gap-2 text-xs text-neutral-600">
          <span>{{ t('payroll.people.registration.environment_label') }}</span>
          <select
            v-model="environment"
            class="rounded-md border px-2 py-1.5 text-xs focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/20"
            :class="environment === 'test'
              ? 'border-warning-500 bg-warning-50 font-semibold text-warning-800'
              : 'border-neutral-300 bg-surface text-neutral-900'"
            :disabled="busy || submission !== null"
            data-test="registration-environment"
          >
            <option value="production">{{ t('payroll.people.registration.environment.production') }}</option>
            <option value="test">{{ t('payroll.people.registration.environment.test') }}</option>
          </select>
        </label>
        <label
          v-if="beforeStartChoice && selectedEventId === null"
          class="flex items-center gap-2 text-xs text-neutral-600"
          :title="t('payroll.people.registration.before_start.hint')"
        >
          <span>{{ t('payroll.people.registration.before_start.label') }}</span>
          <select
            v-model="registrationMode"
            class="rounded-md border border-neutral-300 bg-surface px-2 py-1.5 text-xs text-neutral-900 focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/20"
            :disabled="busy || submission !== null"
            data-test="registration-mode"
          >
            <option value="auto">{{ t('payroll.people.registration.before_start.partial') }}</option>
            <option value="full">{{ t('payroll.people.registration.before_start.full') }}</option>
          </select>
        </label>
        <label
          v-if="unknownStartApplicable"
          class="flex items-center gap-2 text-xs text-neutral-600"
          :title="t('payroll.people.registration.unknown_start.hint')"
        >
          <input
            v-model="startNotKnownInAdvance"
            type="checkbox"
            class="rounded border-neutral-300 text-primary-600 focus:ring-primary-500/30"
            :disabled="busy || submission !== null"
            data-test="registration-unknown-start"
          >
          <span>{{ t('payroll.people.registration.unknown_start.label') }}</span>
        </label>
        <!--
          Náhled a příprava jsou dvě samostatná tlačítka na stálém místě (UI-27):
          dřív jedno tlačítko měnilo význam „zjistit → připravit", takže dvojklik
          rovnou připravil úřední podání.
        -->
        <button
          type="button"
          :class="primaryAction === 'preview' ? btnFilled('primary') : btnOutline('neutral')"
          :disabled="busy"
          data-test="registration-preview"
          @click="run('preview')"
        >
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path :d="ICONS.eye" />
          </svg>
          {{ busy
            ? t('common.loading')
            : t(primaryAction === 'preview'
              ? 'payroll.people.registration.action_preview'
              : 'payroll.people.registration.preview') }}
        </button>
        <RouterLink
          v-if="registrationInQueue && currentRegistration"
          :to="QUEUE_ROUTE"
          :class="btnFilled('primary')"
          data-test="registration-open-queue"
        >
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path :d="ICONS.link" />
          </svg>
          {{ t('payroll.people.registration.filing_state.open_queue') }}
        </RouterLink>
        <button
          v-else-if="primaryAction !== 'done'"
          type="button"
          :class="primaryAction === 'prepare' ? btnFilled('primary') : btnOutline('primary')"
          :disabled="busy || primaryAction !== 'prepare' || !canWrite"
          :title="primaryAction !== 'prepare' ? t('payroll.people.registration.filing_state.prepare_needs_preview') : undefined"
          data-test="registration-prepare"
          @click="run('prepare')"
        >
          <svg
            class="h-4 w-4"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            stroke-width="2"
            aria-hidden="true"
          >
            <path :d="ICONS.check" />
          </svg>
          {{ t('payroll.people.registration.action_prepare') }}
        </button>
      </div>
    </div>
    <p
      v-if="beforeStartChoice && selectedEventId === null"
      class="mt-2 text-xs text-neutral-500"
      data-test="registration-mode-hint"
    >
      {{ t('payroll.people.registration.before_start.hint') }}
    </p>

    <p
      v-if="nothingSentYet && submissionTestAllowed"
      class="mt-3 rounded-md border px-3 py-2 text-xs"
      :class="environment === 'production'
        ? 'border-warning-300 bg-warning-50 text-warning-800'
        : 'border-primary-200 bg-primary-50 text-primary-800'"
      role="status"
      :data-test="`registration-environment-notice-${environment}`"
    >
      {{ environment === 'production'
        ? t('payroll.people.registration.environment_notice.production')
        : t('payroll.people.registration.environment_notice.test') }}
    </p>

    <div
      v-if="currentRegistration && submission === null && selectedEventId === null"
      class="mt-3 flex flex-wrap items-center justify-between gap-2 rounded-md border px-3 py-2 text-xs"
      :class="currentRegistration.sent
        ? 'border-success-500/30 bg-success-50 text-success-800'
        : 'border-primary-200 bg-primary-50 text-primary-800'"
      role="status"
      :data-test="`registration-existing-${currentRegistration.sent ? 'sent' : 'ready'}`"
    >
      <span>
        {{ t(`payroll.people.registration.filing_state.existing_${currentRegistration.sent ? 'sent' : 'ready'}`, {
          id: currentRegistration.submission_id,
          status: currentRegistrationStatus,
        }) }}
      </span>
      <RouterLink
        v-if="currentRegistration.sent"
        :to="QUEUE_ROUTE"
        :class="btnOutline('neutral')"
        class="whitespace-nowrap"
        data-test="registration-existing-link"
      >
        {{ t('payroll.people.registration.filing_state.open_queue') }}
      </RouterLink>
    </div>

    <div class="mt-4 rounded-lg border border-neutral-200 bg-surface p-3" data-test="registration-a1-profile">
      <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h5 class="text-sm font-semibold text-neutral-900">
            {{ t('payroll.people.registration.a1.title') }}
          </h5>
          <p class="mt-1 text-xs text-neutral-500">
            {{ t('payroll.people.registration.a1.description') }}
          </p>
        </div>
        <!--
          Plné tlačítko schválně: doplnit profil je hlavní práce na téhle
          obrazovce, bez něj se registrace nepodá. Obrysové splývalo
          s okolím a vypadalo jako druhořadá volba.
        -->
        <button
          type="button"
          :class="a1ProfileOpen ? btnOutline('neutral') : btnFilled('success')"
          :disabled="a1ProfileLoading"
          data-test="registration-a1-toggle"
          @click="a1ProfileOpen = !a1ProfileOpen"
        >
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path :d="a1ProfileOpen ? ICONS.x : ICONS.eye" />
          </svg>
          {{ t(a1ProfileOpen
            ? 'payroll.people.registration.a1.hide'
            : 'payroll.people.registration.a1.show') }}
        </button>
      </div>
      <div v-if="a1ProfileOpen" class="mt-3 space-y-3">
        <div
          v-if="a1ProfileError"
          ref="a1ErrorPanel"
          class="rounded-md border border-danger-300 bg-danger-50 p-3"
          role="alert"
          data-test="registration-a1-error"
        >
          <h6 class="text-sm font-semibold text-danger-800">
            {{ t('payroll.people.registration.a1.error_title') }}
          </h6>
          <p class="mt-1 text-xs text-danger-800" data-test="registration-a1-error-message">
            {{ a1ProfileError }}
          </p>
          <p class="mt-2 text-xs text-danger-800">
            {{ t('payroll.people.registration.a1.error_kept') }}
          </p>
          <!-- Kam jít, říká hláška serveru: ta míří na konkrétní pole. Druhá
               věta natvrdo v šabloně jmenovala jiné sekce a odporovala jí. -->
          <template v-if="a1PersonCardTarget !== null">
            <RouterLink
              :to="a1PersonCardTarget"
              :class="[btnOutline('danger'), 'mt-2']"
              data-test="registration-a1-person-link"
            >
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path :d="ICONS.eye" />
              </svg>
              {{ t('payroll.people.registration.a1.error_person_link') }}
            </RouterLink>
          </template>
        </div>

        <div
          v-if="a1Warnings.length > 0"
          class="rounded-md border border-warning-300 bg-warning-50 p-3"
          role="status"
          data-test="registration-warnings"
        >
          <h6 class="text-sm font-semibold text-warning-800">
            {{ t('payroll.people.registration.warnings.title') }}
          </h6>
          <ul class="mt-1 space-y-2">
            <li
              v-for="warning in a1Warnings"
              :key="`${warning.code}-${warning.employment_id}`"
              class="text-xs text-warning-800"
              :data-test="`registration-warning-${warning.code}`"
            >
              <p>{{ warning.message }}</p>
              <div class="mt-1 flex flex-wrap gap-2">
                <RouterLink
                  :to="warningEmploymentTarget(warning.employment_id)"
                  :class="btnOutline('warning')"
                  class="whitespace-nowrap"
                  data-test="registration-warning-open-other"
                >
                  <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path :d="ICONS.eye" />
                  </svg>
                  {{ t('payroll.people.registration.warnings.open_other') }}
                </RouterLink>
                <RouterLink
                  :to="warningEmploymentTarget(employmentId)"
                  :class="btnOutline('neutral')"
                  class="whitespace-nowrap"
                  data-test="registration-warning-open-own"
                >
                  <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path :d="ICONS.eye" />
                  </svg>
                  {{ t('payroll.people.registration.warnings.open_own') }}
                </RouterLink>
              </div>
            </li>
          </ul>
        </div>

        <div
          v-if="canWrite && a1LocalDraft !== null"
          class="rounded-md border border-primary-300 bg-primary-50 p-3"
          data-test="registration-a1-local-draft"
        >
          <h6 class="text-sm font-semibold text-primary-800">
            {{ t('payroll.people.registration.a1.draft_title') }}
          </h6>
          <p class="mt-1 text-xs text-primary-800">
            {{ t('payroll.people.registration.a1.draft_hint', {
              saved: formatDateTime(a1LocalDraft.saved_at),
            }) }}
          </p>
          <div class="mt-2 flex flex-wrap gap-2">
            <button
              type="button"
              :class="btnFilled('primary')"
              data-test="registration-a1-draft-restore"
              @click="restoreA1LocalDraft"
            >
              {{ t('payroll.people.registration.a1.draft_restore') }}
            </button>
            <button
              type="button"
              :class="btnOutline('neutral')"
              data-test="registration-a1-draft-discard"
              @click="discardA1LocalDraft"
            >
              {{ t('payroll.people.registration.a1.draft_discard') }}
            </button>
          </div>
        </div>

        <p class="text-xs text-neutral-500">
          {{ t('payroll.people.registration.a1.warning') }}
        </p>

        <p
          v-if="a1Draft?.variant_error"
          class="rounded-md bg-warning-50 p-2 text-xs text-warning-800"
          data-test="registration-a1-variant-error"
        >
          {{ a1Draft.variant_error }}
        </p>
        <p v-else-if="a1Variant" class="text-xs text-neutral-500">
          {{ t('payroll.people.registration.a1.variant', { variant: a1Variant }) }}
        </p>

        <div
          v-if="a1Gaps.length > 0"
          class="rounded-md border border-warning-200 bg-warning-50 p-3"
          data-test="registration-a1-missing"
        >
          <h6 class="text-xs font-semibold text-warning-800">
            {{ t('payroll.people.registration.a1.missing_title', { count: a1Gaps.length }) }}
          </h6>
          <!--
            Bez téhle věty se seznam četl jako jedenáct chyb, zatímco o kus
            níž svítilo „Kontrola prošla, 0 položek brání podání". Musí být
            napsané, čí je to seznam a že nic neblokuje.
          -->
          <p class="mt-1 text-xs text-warning-800">
            {{ t('payroll.people.registration.a1.missing_hint') }}
          </p>
          <ul class="mt-2 space-y-1.5 text-xs text-warning-800">
            <li v-for="gap in a1Gaps" :key="gap.field">
              <span class="font-medium" :data-test="`registration-a1-gap-label-${gap.field}`">{{ registrationA1FieldLabel(gap.field, t) }}</span> - {{ gap.message }}
              <button
                type="button"
                class="ml-1 whitespace-nowrap rounded-full bg-warning-100 px-2 py-0.5 font-medium underline underline-offset-2 hover:bg-warning-200 hover:text-warning-900 focus:outline-none focus:ring-2 focus:ring-warning-500/40"
                :data-test="`registration-a1-gap-link-${gap.field}`"
                @click="focusA1Gap(gap.field)"
              >
                {{ t('payroll.people.registration.a1.gap_open') }}
              </button>
            </li>
          </ul>
          <p
            v-if="a1GapFailure !== null"
            class="mt-2 text-xs font-medium text-danger-700"
            data-test="registration-a1-gap-failure"
          >
            {{ a1GapFailure }}
          </p>
        </div>

        <!-- Výsledek tlačítka „Kontrola". Souhrn je jen rozcestník: co brání
             podání, svítí červeně přímo u pole. -->
        <div
          v-if="a1Checked && a1Problems.length > 0"
          class="rounded-md border border-danger-300 bg-danger-50 p-3"
          data-test="registration-a1-problems"
        >
          <h6 class="text-xs font-semibold text-danger-800">
            {{ t('payroll.people.registration.a1.problems_title', {
              count: a1Problems.length,
            }) }}
          </h6>
          <p class="mt-1 text-xs text-danger-800">
            {{ t('payroll.people.registration.a1.problems_hint') }}
          </p>
          <ul class="mt-1 space-y-1 text-xs text-danger-800">
            <li v-for="(problem, index) in a1Problems" :key="`${problem.field ?? ''}-${index}`">
              <span v-if="problem.field" class="font-medium">{{ registrationA1FieldLabel(problem.field, t) }}</span>
              <span v-if="problem.field"> — </span><span :data-test="`registration-a1-problem-text-${index}`">{{ a1ProblemText(problem) }}</span>
              <button
                v-if="problem.field"
                type="button"
                class="ml-1 whitespace-nowrap rounded-full bg-danger-100 px-2 py-0.5 font-medium underline underline-offset-2 hover:bg-danger-200 hover:text-danger-900 focus:outline-none focus:ring-2 focus:ring-danger-500/40"
                :data-test="`registration-a1-problem-link-${problem.field}`"
                @click="focusA1Gap(problem.field)"
              >
                {{ t('payroll.people.registration.a1.gap_open') }}
              </button>
            </li>
          </ul>
        </div>
        <p
          v-else-if="a1Checked && a1LooseProblems.length === 0"
          class="rounded-md border border-success-300 bg-success-50 p-3 text-xs text-success-800"
          data-test="registration-a1-problems-none"
        >
          {{ t('payroll.people.registration.a1.check_complete') }}
        </p>

        <!-- Co má formulář jinak než kmenová data. NENÍ to výtka: u každé
             položky se dá hodnota přenést zpátky do evidence osoby nebo
             pracovního vztahu. Varování „úřad má jinou hodnotu" se přidává
             jen tam, kde registrace opravdu odešla — u rozpracovaného profilu
             žádný nahlášený stav neexistuje. -->
        <div
          v-if="a1Writeback.length > 0"
          class="rounded-md border border-primary-200 bg-primary-50 p-3"
          data-test="registration-a1-diverged"
        >
          <h6 class="text-xs font-semibold text-primary-800">
            {{ t('payroll.people.registration.a1.master_data_title') }}
          </h6>
          <p class="mt-1 text-xs text-primary-800">
            {{ t('payroll.people.registration.a1.master_data_hint') }}
          </p>
          <p
            v-if="a1Submitted"
            class="mt-1 text-xs font-medium text-primary-900"
            data-test="registration-a1-submitted-note"
          >
            {{ t('payroll.people.registration.a1.master_data_submitted_note') }}
          </p>
          <ul class="mt-1 space-y-1 text-xs text-primary-800">
            <li v-for="item in a1Writeback" :key="item.field">
              <span class="font-medium">{{ item.label }}</span>:
              {{ t('payroll.people.registration.a1.diverged_pair', {
                stored: item.stored ?? '—',
                suggested: item.suggested ?? '—',
              }) }}
              <button
                type="button"
                class="ml-1 whitespace-nowrap rounded-full bg-primary-100 px-2 py-0.5 font-medium underline underline-offset-2 hover:bg-primary-200 hover:text-primary-900 focus:outline-none focus:ring-2 focus:ring-primary-500/40"
                :data-test="`registration-a1-diverged-link-${item.field}`"
                @click="focusA1Gap(item.field)"
              >
                {{ t('payroll.people.registration.a1.gap_open') }}
              </button>
              <button
                v-if="item.writable && canWrite"
                type="button"
                class="ml-1 whitespace-nowrap rounded-full bg-primary-100 px-2 py-0.5 font-medium underline underline-offset-2 hover:bg-primary-200 hover:text-primary-900 focus:outline-none focus:ring-2 focus:ring-primary-500/40 disabled:opacity-50"
                :disabled="a1WritebackBusy"
                :data-test="`registration-a1-master-data-${item.field}`"
                @click="writeA1MasterData([item.field])"
              >
                {{ t('payroll.people.registration.a1.master_data_write') }}
              </button>
              <!-- Údaj, který kmenová data nevedou, tlačítko nedostane —
                   místo něj věta proč, ať to nevypadá jako chybějící akce. -->
              <span v-else-if="!item.writable" class="ml-1 italic">
                {{ item.reason }}
              </span>
            </li>
          </ul>
          <div
            v-if="canWrite && a1WritebackWritable.length > 1"
            class="mt-2"
          >
            <button
              type="button"
              :class="btnOutline('primary')"
              :disabled="a1WritebackBusy"
              data-test="registration-a1-master-data-all"
              @click="writeA1MasterData(a1WritebackWritable.map(item => item.field))"
            >
              {{ a1WritebackBusy
                ? t('common.loading')
                : t('payroll.people.registration.a1.master_data_write_all', {
                  count: a1WritebackWritable.length,
                }) }}
            </button>
          </div>
        </div>
        <p
          v-if="a1WritebackMessage"
          class="rounded-md border border-success-300 bg-success-50 p-3 text-xs text-success-800"
          data-test="registration-a1-master-data-saved"
        >
          {{ a1WritebackMessage }}
        </p>
        <ul
          v-if="a1WritebackProblems.length > 0"
          class="rounded-md border border-warning-300 bg-warning-50 p-3 text-xs text-warning-800 space-y-1"
          data-test="registration-a1-master-data-skipped"
        >
          <li v-for="(item, index) in a1WritebackProblems" :key="index">
            <span class="font-medium">{{ item.label }}</span>: {{ item.reason }}
          </li>
        </ul>

        <div :class="a1SectionClass">
          <h6 class="text-sm font-semibold text-neutral-900">
            {{ t('payroll.people.registration.a1.section.permanent_address') }}
          </h6>
          <p class="mt-1 text-xs text-neutral-500">
            {{ t('payroll.people.registration.a1.permanent_address_hint') }}
          </p>
          <div :class="a1GridClass">
            <label v-for="field in a1AddressFields" :key="field.key" class="block">
              <span :class="a1LabelClass">{{ t(field.label) }}</span>
              <SearchableSelect
                v-if="field.key === 'country_code'"
                :class="['mt-1', a1FieldRing('permanent_address.country_code')]"
                :data-a1-field="'permanent_address.country_code'"
                :model-value="a1Form.permanent_address.country_code"
                :options="a1CodeOptions(jmhzOptions?.countries, a1Form.permanent_address.country_code)"
                :placeholder="t('payroll.people.registration.a1.country_select')"
                :no-results-label="t('payroll.people.registration.a1.country_no_results')"
                :disabled="a1Busy"
                accent="payroll"
                data-test="a1-permanent-country_code"
                @update:model-value="a1Form.permanent_address.country_code = $event"
              />
              <input
                v-else
                v-model="a1Form.permanent_address[field.key]"
                type="text"
                v-bind="a1FieldAttrs(`permanent_address.${field.key}`)"
                :disabled="a1Busy"
                :data-test="`a1-permanent-${field.key}`"
              >
              <span
                v-if="a1NoteText(`permanent_address.${field.key}`)"
                :class="a1NoteClass(`permanent_address.${field.key}`)"
              >
                {{ a1NoteText(`permanent_address.${field.key}`) }}
              </span>
            </label>
          </div>
        </div>

        <div :class="a1SectionClass">
          <label class="flex items-center gap-2 text-sm font-semibold text-neutral-900">
            <input
              type="checkbox"
              :checked="a1Form.czech_residence_address !== null"
              :disabled="a1Busy"
              data-test="a1-czech-residence-toggle"
              @change="a1EnsureAddress(
                'czech_residence_address',
                ($event.target as HTMLInputElement).checked,
              )"
            >
            {{ t('payroll.people.registration.a1.section.czech_residence_address') }}
          </label>
          <p class="mt-1 text-xs text-neutral-500" data-test="a1-czech-residence-hint">
            {{ t('payroll.people.registration.a1.czech_residence_hint') }}
          </p>
          <span
            v-if="a1NoteText('czech_residence_address')"
            :class="a1NoteClass('czech_residence_address')"
          >
            {{ a1NoteText('czech_residence_address') }}
          </span>
          <div v-if="a1Form.czech_residence_address !== null" :class="a1GridClass">
            <label v-for="field in a1AddressFields" :key="field.key" class="block">
              <span :class="a1LabelClass">{{ t(field.label) }}</span>
              <SearchableSelect
                v-if="field.key === 'country_code'"
                :class="['mt-1', a1FieldRing('czech_residence_address.country_code')]"
                :data-a1-field="'czech_residence_address.country_code'"
                :model-value="a1Form.czech_residence_address.country_code"
                :options="a1CodeOptions(jmhzOptions?.countries, a1Form.czech_residence_address.country_code)"
                :placeholder="t('payroll.people.registration.a1.country_select')"
                :no-results-label="t('payroll.people.registration.a1.country_no_results')"
                :disabled="a1Busy"
                accent="payroll"
                data-test="a1-czech-residence-country_code"
                @update:model-value="a1Form.czech_residence_address.country_code = $event"
              />
              <input
                v-else
                v-model="a1Form.czech_residence_address[field.key]"
                type="text"
                v-bind="a1FieldAttrs(`czech_residence_address.${field.key}`)"
                :disabled="a1Busy"
                :data-test="`a1-czech-residence-${field.key}`"
              >
            </label>
          </div>
        </div>

        <div v-if="a1IsFull" :class="a1SectionClass">
          <label class="flex items-center gap-2 text-sm font-semibold text-neutral-900">
            <input
              type="checkbox"
              :checked="a1Form.contact_address !== null"
              :disabled="a1Busy"
              data-test="a1-contact-toggle"
              @change="a1EnsureAddress(
                'contact_address',
                ($event.target as HTMLInputElement).checked,
              )"
            >
            {{ t('payroll.people.registration.a1.section.contact_address') }}
          </label>
          <p class="mt-1 text-xs text-neutral-500" data-test="a1-contact-hint">
            {{ t('payroll.people.registration.a1.contact_address_hint') }}
          </p>
          <div v-if="a1Form.contact_address !== null" :class="a1GridClass">
            <label v-for="field in a1AddressFields" :key="field.key" class="block">
              <span :class="a1LabelClass">{{ t(field.label) }}</span>
              <SearchableSelect
                v-if="field.key === 'country_code'"
                :class="['mt-1', a1FieldRing('contact_address.country_code')]"
                :data-a1-field="'contact_address.country_code'"
                :model-value="a1Form.contact_address.country_code"
                :options="a1CodeOptions(jmhzOptions?.countries, a1Form.contact_address.country_code)"
                :placeholder="t('payroll.people.registration.a1.country_select')"
                :no-results-label="t('payroll.people.registration.a1.country_no_results')"
                :disabled="a1Busy"
                accent="payroll"
                data-test="a1-contact-country_code"
                @update:model-value="a1Form.contact_address.country_code = $event"
              />
              <input
                v-else
                v-model="a1Form.contact_address[field.key]"
                type="text"
                v-bind="a1FieldAttrs(`contact_address.${field.key}`)"
                :disabled="a1Busy"
                :data-test="`a1-contact-${field.key}`"
              >
              <span
                v-if="a1NoteText(`contact_address.${field.key}`)"
                :class="a1NoteClass(`contact_address.${field.key}`)"
              >
                {{ a1NoteText(`contact_address.${field.key}`) }}
              </span>
            </label>
          </div>
        </div>

        <div v-if="!a1IsLimited && a1Form.tax_residency" :class="a1SectionClass">
          <h6 class="text-sm font-semibold text-neutral-900">
            {{ t('payroll.people.registration.a1.section.tax_residency') }}
          </h6>
          <div :class="a1GridClass">
            <label class="block">
              <span :class="a1LabelClass">
                {{ t('payroll.people.registration.a1.tax_residency.country_code') }}
              </span>
              <SearchableSelect
                :class="['mt-1', a1FieldRing('tax_residency.country_code')]"
                :data-a1-field="'tax_residency.country_code'"
                :model-value="a1Form.tax_residency.country_code"
                :options="a1CodeOptions(jmhzOptions?.countries, a1Form.tax_residency.country_code)"
                :placeholder="t('payroll.people.registration.a1.country_select')"
                :no-results-label="t('payroll.people.registration.a1.country_no_results')"
                :disabled="a1Busy"
                accent="payroll"
                data-test="a1-tax-residency-country"
                @update:model-value="a1Form.tax_residency.country_code = $event"
              />
              <span
                v-if="a1NoteText('tax_residency.country_code')"
                :class="a1NoteClass('tax_residency.country_code')"
              >
                {{ a1NoteText('tax_residency.country_code') }}
              </span>
            </label>
            <label class="block">
              <span :class="a1LabelClass">
                {{ t('payroll.people.registration.a1.tax_residency.identifier_type') }}
              </span>
              <select
                v-model="a1Form.tax_residency.identifier_type"
                v-bind="a1FieldAttrs('tax_residency.identifier_type')"
                :disabled="a1Busy"
                data-test="a1-tax-residency-identifier-type"
              >
                <option :value="null">{{ t('payroll.people.registration.a1.unset') }}</option>
                <option
                  v-for="option in jmhzOptions?.tax_identifier_types ?? []"
                  :key="option.code"
                  :value="option.code"
                >{{ option.code }} · {{ option.label }}</option>
              </select>
              <span
                v-if="a1NoteText('tax_residency.identifier_type')"
                :class="a1NoteClass('tax_residency.identifier_type')"
              >
                {{ a1NoteText('tax_residency.identifier_type') }}
              </span>
            </label>
            <label class="block">
              <span :class="a1LabelClass">
                {{ t('payroll.people.registration.a1.tax_residency.identifier') }}
              </span>
              <input
                v-model="a1Form.tax_residency.identifier"
                type="text"
                v-bind="a1FieldAttrs('tax_residency.identifier')"
                :disabled="a1Busy"
                data-test="a1-tax-residency-identifier"
              >
              <span
                v-if="a1NoteText('tax_residency.identifier')"
                :class="a1NoteClass('tax_residency.identifier')"
              >
                {{ a1NoteText('tax_residency.identifier') }}
              </span>
            </label>
          </div>
          <label class="mt-3 flex items-center gap-2 text-xs font-medium text-neutral-700">
            <input
              type="checkbox"
              :checked="a1Form.tax_residency.residence_address !== null"
              :disabled="a1Busy"
              data-test="a1-tax-residence-address-toggle"
              @change="a1EnsureResidenceAddress(
                ($event.target as HTMLInputElement).checked,
              )"
            >
            {{ t('payroll.people.registration.a1.tax_residency.residence_address') }}
          </label>
          <p class="mt-1 text-xs text-neutral-500" data-test="a1-tax-residence-address-hint">
            {{ t('payroll.people.registration.a1.tax_residency.residence_address_hint') }}
          </p>
          <span
            v-if="a1NoteText('tax_residency.residence_address')"
            :class="a1NoteClass('tax_residency.residence_address')"
          >
            {{ a1NoteText('tax_residency.residence_address') }}
          </span>
          <div v-if="a1Form.tax_residency.residence_address !== null" :class="a1GridClass">
            <label v-for="field in a1AddressFields" :key="field.key" class="block">
              <span :class="a1LabelClass">{{ t(field.label) }}</span>
              <SearchableSelect
                v-if="field.key === 'country_code'"
                :class="['mt-1', a1FieldRing('tax_residency.residence_address.country_code')]"
                :data-a1-field="'tax_residency.residence_address.country_code'"
                :model-value="a1Form.tax_residency.residence_address.country_code"
                :options="a1CodeOptions(jmhzOptions?.countries, a1Form.tax_residency.residence_address.country_code)"
                :placeholder="t('payroll.people.registration.a1.country_select')"
                :no-results-label="t('payroll.people.registration.a1.country_no_results')"
                :disabled="a1Busy"
                accent="payroll"
                data-test="a1-tax-residence-address-country_code"
                @update:model-value="a1Form.tax_residency.residence_address.country_code = $event"
              />
              <input
                v-else
                v-model="a1Form.tax_residency.residence_address[field.key]"
                type="text"
                v-bind="a1FieldAttrs(`tax_residency.residence_address.${field.key}`)"
                :disabled="a1Busy"
                :data-test="`a1-tax-residence-address-${field.key}`"
              >
            </label>
          </div>
        </div>

        <div :class="a1SectionClass">
          <h6 class="text-sm font-semibold text-neutral-900">
            {{ t('payroll.people.registration.a1.section.employment') }}
          </h6>
          <div :class="a1GridClass">
            <label class="block">
              <span :class="a1LabelClass">
                {{ t('payroll.people.registration.a1.employment.activity_code') }}
              </span>
              <select
                v-model="a1Form.employment.activity_code"
                v-bind="a1FieldAttrs('employment.activity_code')"
                :disabled="a1Busy"
                data-test="a1-employment-activity-code"
              >
                <option :value="null">{{ t('payroll.people.registration.a1.unset') }}</option>
                <option
                  v-for="option in jmhzOptions?.activity_codes ?? []"
                  :key="option.code"
                  :value="option.code"
                >{{ option.code }} · {{ option.label }}</option>
              </select>
              <span
                v-if="a1NoteText('employment.activity_code')"
                :class="a1NoteClass('employment.activity_code')"
              >
                {{ a1NoteText('employment.activity_code') }}
              </span>
              <span
                v-if="a1Form.employment.activity_code === '13'"
                class="mt-1 block text-xs text-neutral-500"
                data-test="a1-election-commission-hint"
              >
                {{ t('payroll.people.registration.a1.employment.election_commission_hint') }}
              </span>
            </label>
            <label class="block">
              <span :class="a1LabelClass">
                {{ t('payroll.people.registration.a1.employment.relationship_detail_code') }}
              </span>
              <select
                v-model="a1Form.employment.relationship_detail_code"
                v-bind="a1FieldAttrs('employment.relationship_detail_code')"
                :disabled="a1Busy"
                data-test="a1-employment-relationship-detail-code"
              >
                <option :value="null">{{ t('payroll.people.registration.a1.unset') }}</option>
                <option
                  v-for="option in a1RelationshipDetailOptions"
                  :key="option.code"
                  :value="option.code"
                >{{ option.code }} · {{ option.label }}</option>
              </select>
              <span
                v-if="a1NoteText('employment.relationship_detail_code')"
                :class="a1NoteClass('employment.relationship_detail_code')"
              >
                {{ a1NoteText('employment.relationship_detail_code') }}
              </span>
            </label>
            <label class="block">
              <span :class="a1LabelClass">
                {{ t('payroll.people.registration.a1.employment.actual_start_on') }}
              </span>
              <DateInput
                v-model="a1Form.employment.actual_start_on"
                v-bind="a1FieldAttrs('employment.actual_start_on')"
                disabled
                data-test="a1-employment-actual-start-on" />
              <span
                v-if="a1NoteText('employment.actual_start_on')"
                :class="a1NoteClass('employment.actual_start_on')"
              >
                {{ a1NoteText('employment.actual_start_on') }}
              </span>
            </label>
            <label class="block">
              <span :class="a1LabelClass">
                {{ t('payroll.people.registration.a1.employment.contract_start_on') }}
              </span>
              <DateInput
                v-model="a1Form.employment.contract_start_on"
                v-bind="a1FieldAttrs('employment.contract_start_on')"
                :disabled="a1Busy"
                data-test="a1-employment-contract-start-on" />
              <span
                v-if="a1NoteText('employment.contract_start_on')"
                :class="a1NoteClass('employment.contract_start_on')"
              >
                {{ a1NoteText('employment.contract_start_on') }}
              </span>
            </label>
            <label class="block">
              <span :class="a1LabelClass">
                {{ t('payroll.people.registration.a1.employment.small_scale') }}
              </span>
              <select
                v-model="a1Form.employment.small_scale"
                v-bind="a1FieldAttrs('employment.small_scale')"
                :disabled="a1Busy"
                data-test="a1-employment-small-scale"
              >
                <option :value="null">{{ t('payroll.people.registration.a1.unset') }}</option>
                <option :value="true">{{ t('common.yes') }}</option>
                <option :value="false">{{ t('common.no') }}</option>
              </select>
              <span
                v-if="a1NoteText('employment.small_scale')"
                :class="a1NoteClass('employment.small_scale')"
              >
                {{ a1NoteText('employment.small_scale') }}
              </span>
            </label>
            <label class="block">
              <span :class="a1LabelClass">
                {{ t('payroll.people.registration.a1.employment.employment_status_code') }}
              </span>
              <select
                v-model="a1Form.employment.employment_status_code"
                v-bind="a1FieldAttrs('employment.employment_status_code')"
                :disabled="a1Busy"
                data-test="a1-employment-status-code"
              >
                <option :value="null">{{ t('payroll.people.registration.a1.unset') }}</option>
                <option
                  v-for="option in a1EmploymentStatusOptions"
                  :key="option.code"
                  :value="option.code"
                >{{ option.label }}</option>
              </select>
              <span class="mt-1 block text-xs text-neutral-500">
                {{ t('payroll.people.registration.a1.employment.employment_status_code_hint') }}
              </span>
              <span
                v-if="a1NoteText('employment.employment_status_code')"
                :class="a1NoteClass('employment.employment_status_code')"
              >
                {{ a1NoteText('employment.employment_status_code') }}
              </span>
            </label>
            <label class="block">
              <span :class="a1LabelClass">
                {{ t('payroll.people.registration.a1.employment.work_mode_code') }}
              </span>
              <select
                v-model="a1Form.employment.work_mode_code"
                v-bind="a1FieldAttrs('employment.work_mode_code')"
                :disabled="a1Busy"
                data-test="a1-employment-work-mode-code"
              >
                <option :value="null">{{ t('payroll.people.registration.a1.unset') }}</option>
                <option
                  v-for="option in jmhzOptions?.work_mode_codes ?? []"
                  :key="option.code"
                  :value="option.code"
                >{{ option.code }} · {{ option.label }}</option>
              </select>
              <span
                v-if="a1NoteText('employment.work_mode_code')"
                :class="a1NoteClass('employment.work_mode_code')"
              >
                {{ a1NoteText('employment.work_mode_code') }}
              </span>
            </label>
            <label class="block">
              <span :class="a1LabelClass">
                {{ t('payroll.people.registration.a1.employment.continuous_operation') }}
              </span>
              <select
                v-model="a1Form.employment.continuous_operation"
                v-bind="a1FieldAttrs('employment.continuous_operation')"
                :disabled="a1Busy"
                data-test="a1-employment-continuous-operation"
              >
                <option :value="null">{{ t('payroll.people.registration.a1.unset') }}</option>
                <option :value="true">{{ t('common.yes') }}</option>
                <option :value="false">{{ t('common.no') }}</option>
              </select>
              <span
                v-if="a1NoteText('employment.continuous_operation')"
                :class="a1NoteClass('employment.continuous_operation')"
              >
                {{ a1NoteText('employment.continuous_operation') }}
              </span>
            </label>
            <label v-if="a1PrevailingWorkplaceApplies" class="block">
              <span :class="a1LabelClass">
                {{ t('payroll.people.registration.a1.employment.prevailing_workplace_code') }}
              </span>
              <select
                v-model="a1Form.employment.prevailing_workplace_code"
                v-bind="a1FieldAttrs('employment.prevailing_workplace_code')"
                :disabled="a1Busy"
                data-test="a1-employment-prevailing-workplace-code"
              >
                <option :value="null">{{ t('payroll.people.registration.a1.unset') }}</option>
                <option
                  v-for="option in jmhzOptions?.workplace_progress_codes ?? []"
                  :key="option.code"
                  :value="option.code"
                >{{ option.code }} · {{ option.label }}</option>
              </select>
              <span
                v-if="a1NoteText('employment.prevailing_workplace_code')"
                :class="a1NoteClass('employment.prevailing_workplace_code')"
              >
                {{ a1NoteText('employment.prevailing_workplace_code') }}
              </span>
            </label>
            <label class="block">
              <span :class="a1LabelClass">
                {{ t('payroll.people.registration.a1.employment.expected_workplaces') }}
              </span>
              <input
                v-model="a1Form.employment.expected_workplaces"
                type="text"
                v-bind="a1FieldAttrs('employment.expected_workplaces')"
                :disabled="a1Busy"
                data-test="a1-employment-expected-workplaces"
              >
            </label>
            <label class="block">
              <span :class="a1LabelClass">
                {{ t('payroll.people.registration.a1.employment.contract_workplace') }}
              </span>
              <input
                v-model="a1Form.employment.contract_workplace"
                type="text"
                v-bind="a1FieldAttrs('employment.contract_workplace')"
                :disabled="a1Busy"
                data-test="a1-employment-contract-workplace"
              >
              <span
                v-if="a1NoteText('employment.contract_workplace')"
                :class="a1NoteClass('employment.contract_workplace')"
              >
                {{ a1NoteText('employment.contract_workplace') }}
              </span>
            </label>
            <label class="block">
              <span :class="a1LabelClass">
                {{ t('payroll.people.registration.a1.employment.workplace_city') }}
              </span>
              <input
                v-model="a1Form.employment.workplace_city"
                type="text"
                v-bind="a1FieldAttrs('employment.workplace_city')"
                :disabled="a1Busy"
                data-test="a1-employment-workplace-city"
              >
              <span
                v-if="a1NoteText('employment.workplace_city')"
                :class="a1NoteClass('employment.workplace_city')"
              >
                {{ a1NoteText('employment.workplace_city') }}
              </span>
            </label>
            <label class="block">
              <span :class="a1LabelClass">
                {{ t('payroll.people.registration.a1.employment.workplace_municipality_code') }}
              </span>
              <SearchableSelect
                :class="['mt-1', a1FieldRing('employment.workplace_municipality_code')]"
                :data-a1-field="'employment.workplace_municipality_code'"
                :model-value="a1Form.employment.workplace_municipality_code"
                :options="municipalityOptions.map(option => ({ value: option.code, label: option.label, secondary: option.code }))"
                :selected-option="selectedMunicipality"
                remote
                :disabled="a1Busy"
                :loading="municipalitiesLoading"
                :loading-label="t('payroll.people.jmhz_evidence.searching_municipality')"
                :no-results-label="t('payroll.people.jmhz_evidence.no_municipality')"
                :placeholder="t('payroll.people.jmhz_evidence.search_municipality')"
                accent="payroll"
                data-test="a1-employment-workplace-municipality-code"
                @search="searchMunicipalities"
                @update:model-value="selectMunicipality"
              />
              <span
                v-if="a1NoteText('employment.workplace_municipality_code')"
                :class="a1NoteClass('employment.workplace_municipality_code')"
              >
                {{ a1NoteText('employment.workplace_municipality_code') }}
              </span>
            </label>
            <label class="block">
              <span :class="a1LabelClass">
                {{ t('payroll.people.registration.a1.employment.profession_code') }}
              </span>
              <CzIscoPicker
                v-model="a1Form.employment.profession_code"
                :class="a1FieldRing('employment.profession_code')"
                :data-a1-field="'employment.profession_code'"
                :disabled="a1Busy"
                data-test="a1-employment-profession-code"
              />
              <span
                v-if="a1NoteText('employment.profession_code')"
                :class="a1NoteClass('employment.profession_code')"
              >
                {{ a1NoteText('employment.profession_code') }}
              </span>
            </label>
            <label class="block">
              <span :class="a1LabelClass">
                {{ t('payroll.people.registration.a1.employment.required_education_code') }}
              </span>
              <select
                v-model="a1Form.employment.required_education_code"
                v-bind="a1FieldAttrs('employment.required_education_code')"
                :disabled="a1Busy"
                data-test="a1-employment-required-education-code"
              >
                <option :value="null">{{ t('payroll.people.registration.a1.unset') }}</option>
                <option
                  v-for="option in jmhzOptions?.education_levels ?? []"
                  :key="option.code"
                  :value="option.code"
                >{{ option.code }} · {{ option.label }}</option>
              </select>
            </label>
            <label class="block">
              <span :class="a1LabelClass">
                {{ t('payroll.people.registration.a1.employment.position_name') }}
              </span>
              <input
                v-model="a1Form.employment.position_name"
                type="text"
                v-bind="a1FieldAttrs('employment.position_name')"
                :disabled="a1Busy"
                data-test="a1-employment-position-name"
              >
              <span
                v-if="a1NoteText('employment.position_name')"
                :class="a1NoteClass('employment.position_name')"
              >
                {{ a1NoteText('employment.position_name') }}
              </span>
            </label>
            <label class="block">
              <span :class="a1LabelClass">
                {{ t('payroll.people.registration.a1.employment.leadership') }}
              </span>
              <select
                v-model="a1Form.employment.leadership"
                v-bind="a1FieldAttrs('employment.leadership')"
                :disabled="a1Busy"
                data-test="a1-employment-leadership"
              >
                <option :value="null">{{ t('payroll.people.registration.a1.unset') }}</option>
                <option :value="true">{{ t('common.yes') }}</option>
                <option :value="false">{{ t('common.no') }}</option>
              </select>
              <span
                v-if="a1NoteText('employment.leadership')"
                :class="a1NoteClass('employment.leadership')"
              >
                {{ a1NoteText('employment.leadership') }}
              </span>
            </label>
          </div>
        </div>

        <div v-if="!a1IsLimited" :class="a1SectionClass">
          <h6 class="text-sm font-semibold text-neutral-900">
            {{ t('payroll.people.registration.a1.section.health_and_facts') }}
          </h6>
          <div :class="a1GridClass">
            <label class="block">
              <span :class="a1LabelClass">
                {{ t('payroll.people.registration.a1.health_insurance_code') }}
              </span>
              <SearchableSelect
                :class="['mt-1', a1FieldRing('health_insurance_code')]"
                :data-a1-field="'health_insurance_code'"
                :model-value="a1Form.health_insurance_code || null"
                :options="a1InsurerOptions"
                :placeholder="t('payroll.people.registration.a1.health_insurance_select')"
                :no-results-label="t('payroll.people.registration.a1.health_insurance_no_results')"
                :disabled="a1Busy"
                accent="payroll"
                data-test="a1-health-insurance-code"
                @update:model-value="a1Form.health_insurance_code = $event"
              />
              <span
                v-if="a1NoteText('health_insurance_code')"
                :class="a1NoteClass('health_insurance_code')"
              >
                {{ a1NoteText('health_insurance_code') }}
              </span>
            </label>
            <label v-if="a1Form.facts && a1IsFull" class="block">
              <span :class="a1LabelClass">
                {{ t('payroll.people.registration.a1.facts.highest_education_code') }}
              </span>
              <select
                v-model="a1Form.facts.highest_education_code"
                v-bind="a1FieldAttrs('facts.highest_education_code')"
                :disabled="a1Busy"
                data-test="a1-facts-highest-education-code"
              >
                <option :value="null">{{ t('payroll.people.registration.a1.unset') }}</option>
                <option
                  v-for="option in jmhzOptions?.education_levels ?? []"
                  :key="option.code"
                  :value="option.code"
                >{{ option.code }} · {{ option.label }}</option>
              </select>
              <span
                v-if="a1NoteText('facts.highest_education_code')"
                :class="a1NoteClass('facts.highest_education_code')"
              >
                {{ a1NoteText('facts.highest_education_code') }}
              </span>
            </label>
            <label v-if="a1Form.facts" class="block">
              <span :class="a1LabelClass">
                {{ t('payroll.people.registration.a1.facts.disability_card') }}
              </span>
              <select
                v-model="a1Form.facts.disability_card"
                v-bind="a1FieldAttrs('facts.disability_card')"
                :disabled="a1Busy"
                data-test="a1-facts-disability-card"
              >
                <option :value="false">{{ t('common.no') }}</option>
                <option :value="true">{{ t('common.yes') }}</option>
              </select>
              <span
                v-if="a1NoteText('facts.disability_card')"
                :class="a1NoteClass('facts.disability_card')"
              >
                {{ a1NoteText('facts.disability_card') }}
              </span>
            </label>
          </div>
          <div v-if="a1Form.facts" class="mt-3">
            <span :class="a1LabelClass">
              {{ t('payroll.people.registration.a1.facts.health_restrictions') }}
            </span>
            <div
              v-for="(restriction, index) in a1Form.facts.health_restrictions"
              :key="index"
              class="mt-2 grid gap-2 sm:grid-cols-4"
            >
              <select
                v-model="restriction.type_code"
                :class="a1InputClass"
                :disabled="a1Busy"
                :data-test="`a1-restriction-type-${index}`"
              >
                <option :value="null">{{ t('payroll.people.registration.a1.facts.restriction_type') }}</option>
                <option
                  v-for="option in jmhzOptions?.health_restriction_type_codes ?? []"
                  :key="option.code"
                  :value="option.code"
                >{{ option.code }} · {{ option.label }}</option>
              </select>
              <DateInput
                v-model="restriction.from"
                :class="a1InputClass"
                :disabled="a1Busy"
                :data-test="`a1-restriction-from-${index}`" />
              <DateInput
                v-model="restriction.to"
                :class="a1InputClass"
                :disabled="a1Busy"
                :data-test="`a1-restriction-to-${index}`" />
              <button
                type="button"
                :class="btnOutline('danger')"
                :disabled="a1Busy"
                :data-test="`a1-restriction-remove-${index}`"
                @click="a1RemoveRestriction(index)"
              >
                {{ t('common.remove') }}
              </button>
            </div>
            <button
              type="button"
              class="cursor-pointer mt-2"
              :class="btnOutline('neutral')"
              :disabled="a1Busy"
              data-test="a1-restriction-add"
              @click="a1AddRestriction"
            >
              {{ t('payroll.people.registration.a1.facts.add_restriction') }}
            </button>
          </div>
        </div>

        <div v-if="a1IsFull && a1Form.pension" :class="a1SectionClass">
          <h6 class="text-sm font-semibold text-neutral-900">
            {{ t('payroll.people.registration.a1.section.pension') }}
          </h6>
          <span v-if="a1NoteText('pension')" :class="a1NoteClass('pension')">
            {{ a1NoteText('pension') }}
          </span>
          <div :class="a1GridClass">
            <label class="block">
              <span :class="a1LabelClass">
                {{ t('payroll.people.registration.a1.pension.type_code') }}
              </span>
              <select
                v-model="a1Form.pension.type_code"
                v-bind="a1FieldAttrs('pension.type_code')"
                :disabled="a1Busy"
                data-test="a1-pension-type-code"
              >
                <option :value="null">{{ t('payroll.people.registration.a1.unset') }}</option>
                <option
                  v-for="option in jmhzOptions?.pension_type_codes ?? []"
                  :key="option.code"
                  :value="option.code"
                >{{ option.code }} · {{ option.label }}</option>
              </select>
            </label>
            <label class="block">
              <span :class="a1LabelClass">
                {{ t('payroll.people.registration.a1.pension.received_from') }}
              </span>
              <DateInput
                v-model="a1Form.pension.received_from"
                v-bind="a1FieldAttrs('pension.received_from')"
                :disabled="a1Busy"
                data-test="a1-pension-received-from" />
            </label>
            <label class="block">
              <span :class="a1LabelClass">
                {{ t('payroll.people.registration.a1.pension.early_retirement') }}
              </span>
              <select
                v-model="a1Form.pension.early_retirement"
                v-bind="a1FieldAttrs('pension.early_retirement')"
                :disabled="a1Busy"
                data-test="a1-pension-early-retirement"
              >
                <option :value="false">{{ t('common.no') }}</option>
                <option :value="true">{{ t('common.yes') }}</option>
              </select>
            </label>
            <label class="block">
              <span :class="a1LabelClass">
                {{ t('payroll.people.registration.a1.pension.reduced_retirement_age') }}
              </span>
              <select
                v-model="a1Form.pension.reduced_retirement_age"
                v-bind="a1FieldAttrs('pension.reduced_retirement_age')"
                :disabled="a1Busy"
                data-test="a1-pension-reduced-retirement-age"
              >
                <option :value="false">{{ t('common.no') }}</option>
                <option :value="true">{{ t('common.yes') }}</option>
              </select>
            </label>
          </div>
        </div>

        <div v-if="a1IsFull && a1Form.foreign_legislation" :class="a1SectionClass">
          <h6 class="text-sm font-semibold text-neutral-900">
            {{ t('payroll.people.registration.a1.section.foreign_legislation') }}
          </h6>
          <div :class="a1GridClass">
            <label class="block">
              <span :class="a1LabelClass">
                {{ t('payroll.people.registration.a1.foreign_legislation.applies') }}
              </span>
              <select
                v-model="a1Form.foreign_legislation.applies"
                v-bind="a1FieldAttrs('foreign_legislation.applies')"
                :disabled="a1Busy"
                data-test="a1-foreign-legislation-applies"
              >
                <option :value="false">{{ t('common.no') }}</option>
                <option :value="true">{{ t('common.yes') }}</option>
              </select>
              <span
                v-if="a1NoteText('foreign_legislation.applies')"
                :class="a1NoteClass('foreign_legislation.applies')"
              >
                {{ a1NoteText('foreign_legislation.applies') }}
              </span>
            </label>
            <label class="block">
              <span :class="a1LabelClass">
                {{ t('payroll.people.registration.a1.foreign_legislation.country_code') }}
              </span>
              <SearchableSelect
                :class="['mt-1', a1FieldRing('foreign_legislation.country_code')]"
                :data-a1-field="'foreign_legislation.country_code'"
                :model-value="a1Form.foreign_legislation.country_code"
                :options="a1CodeOptions(jmhzOptions?.countries, a1Form.foreign_legislation.country_code)"
                :placeholder="t('payroll.people.registration.a1.country_select')"
                :no-results-label="t('payroll.people.registration.a1.country_no_results')"
                :disabled="a1Busy"
                accent="payroll"
                data-test="a1-foreign-legislation-country-code"
                @update:model-value="a1Form.foreign_legislation.country_code = $event"
              />
              <span
                v-if="a1NoteText('foreign_legislation.country_code')"
                :class="a1NoteClass('foreign_legislation.country_code')"
              >
                {{ a1NoteText('foreign_legislation.country_code') }}
              </span>
            </label>
          </div>
        </div>

        <div v-if="a1IsFull && a1Form.foreign_insurance" :class="a1SectionClass">
          <h6 class="text-sm font-semibold text-neutral-900">
            {{ t('payroll.people.registration.a1.section.foreign_insurance') }}
          </h6>
          <p class="mt-1 text-xs text-neutral-500">
            {{ t('payroll.people.registration.a1.foreign_insurance.hint') }}
          </p>
          <div :class="a1GridClass">
            <label class="block">
              <span :class="a1LabelClass">
                {{ t('payroll.people.registration.a1.foreign_insurance.current') }}
              </span>
              <select
                v-model="a1Form.foreign_insurance.current"
                v-bind="a1FieldAttrs('foreign_insurance.current')"
                :disabled="a1Busy"
                data-test="a1-foreign-insurance-current"
              >
                <option :value="null">{{ t('payroll.people.registration.a1.unset') }}</option>
                <option value="P">{{ t('payroll.people.registration.a1.foreign_insurance.current_last') }}</option>
                <option value="S">{{ t('payroll.people.registration.a1.foreign_insurance.current_present') }}</option>
              </select>
              <span
                v-if="a1NoteText('foreign_insurance.current')"
                :class="a1NoteClass('foreign_insurance.current')"
              >
                {{ a1NoteText('foreign_insurance.current') }}
              </span>
            </label>
            <label class="block">
              <span :class="a1LabelClass">
                {{ t('payroll.people.registration.a1.foreign_insurance.country_code') }}
              </span>
              <SearchableSelect
                :class="['mt-1', a1FieldRing('foreign_insurance.country_code')]"
                :data-a1-field="'foreign_insurance.country_code'"
                :model-value="a1Form.foreign_insurance.country_code"
                :options="a1CodeOptions(jmhzOptions?.countries, a1Form.foreign_insurance.country_code)"
                :placeholder="t('payroll.people.registration.a1.country_select')"
                :no-results-label="t('payroll.people.registration.a1.country_no_results')"
                :disabled="a1Busy"
                accent="payroll"
                data-test="a1-foreign-insurance-country-code"
                @update:model-value="a1Form.foreign_insurance.country_code = $event"
              />
              <span
                v-if="a1NoteText('foreign_insurance.country_code')"
                :class="a1NoteClass('foreign_insurance.country_code')"
              >
                {{ a1NoteText('foreign_insurance.country_code') }}
              </span>
            </label>
            <label class="block">
              <span :class="a1LabelClass">
                {{ t('payroll.people.registration.a1.foreign_insurance.name') }}
              </span>
              <input
                v-model="a1Form.foreign_insurance.name"
                type="text"
                v-bind="a1FieldAttrs('foreign_insurance.name')"
                :disabled="a1Busy"
                data-test="a1-foreign-insurance-name"
              >
            </label>
            <label class="block">
              <span :class="a1LabelClass">
                {{ t('payroll.people.registration.a1.foreign_insurance.identifier') }}
              </span>
              <input
                v-model="a1Form.foreign_insurance.identifier"
                type="text"
                v-bind="a1FieldAttrs('foreign_insurance.identifier')"
                :disabled="a1Busy"
                data-test="a1-foreign-insurance-identifier"
              >
            </label>
            <label class="block">
              <span :class="a1LabelClass">
                {{ t('payroll.people.registration.a1.foreign_insurance.sector') }}
              </span>
              <select
                v-model="a1Form.foreign_insurance.sector"
                v-bind="a1FieldAttrs('foreign_insurance.sector')"
                :disabled="a1Busy"
                data-test="a1-foreign-insurance-sector"
              >
                <option :value="null">{{ t('payroll.people.registration.a1.unset') }}</option>
                <option v-for="code in FOREIGN_INSURANCE_SECTORS" :key="code" :value="code">
                  {{ code }} · {{ t(`payroll.people.registration.a1.foreign_insurance.sector_option.${code}`) }}
                </option>
              </select>
              <span
                v-if="a1NoteText('foreign_insurance.sector')"
                :class="a1NoteClass('foreign_insurance.sector')"
              >
                {{ a1NoteText('foreign_insurance.sector') }}
              </span>
            </label>
            <label class="block">
              <span :class="a1LabelClass">
                {{ t('payroll.people.registration.a1.foreign_insurance.street') }}
              </span>
              <input
                v-model="a1Form.foreign_insurance.street"
                type="text"
                v-bind="a1FieldAttrs('foreign_insurance.street')"
                :disabled="a1Busy"
                data-test="a1-foreign-insurance-street"
              >
            </label>
            <label class="block">
              <span :class="a1LabelClass">
                {{ t('payroll.people.registration.a1.foreign_insurance.house_number') }}
              </span>
              <input
                v-model="a1Form.foreign_insurance.house_number"
                type="text"
                v-bind="a1FieldAttrs('foreign_insurance.house_number')"
                :disabled="a1Busy"
                data-test="a1-foreign-insurance-house-number"
              >
              <span
                v-if="a1NoteText('foreign_insurance.house_number')"
                :class="a1NoteClass('foreign_insurance.house_number')"
              >
                {{ a1NoteText('foreign_insurance.house_number') }}
              </span>
            </label>
            <label class="block">
              <span :class="a1LabelClass">
                {{ t('payroll.people.registration.a1.foreign_insurance.orientation_number') }}
              </span>
              <input
                v-model="a1Form.foreign_insurance.orientation_number"
                type="text"
                v-bind="a1FieldAttrs('foreign_insurance.orientation_number')"
                :disabled="a1Busy"
                data-test="a1-foreign-insurance-orientation-number"
              >
            </label>
            <label class="block">
              <span :class="a1LabelClass">
                {{ t('payroll.people.registration.a1.foreign_insurance.postal_code') }}
              </span>
              <input
                v-model="a1Form.foreign_insurance.postal_code"
                type="text"
                v-bind="a1FieldAttrs('foreign_insurance.postal_code')"
                :disabled="a1Busy"
                data-test="a1-foreign-insurance-postal-code"
              >
              <span
                v-if="a1NoteText('foreign_insurance.postal_code')"
                :class="a1NoteClass('foreign_insurance.postal_code')"
              >
                {{ a1NoteText('foreign_insurance.postal_code') }}
              </span>
            </label>
            <label class="block">
              <span :class="a1LabelClass">
                {{ t('payroll.people.registration.a1.foreign_insurance.city') }}
              </span>
              <input
                v-model="a1Form.foreign_insurance.city"
                type="text"
                v-bind="a1FieldAttrs('foreign_insurance.city')"
                :disabled="a1Busy"
                data-test="a1-foreign-insurance-city"
              >
              <span
                v-if="a1NoteText('foreign_insurance.city')"
                :class="a1NoteClass('foreign_insurance.city')"
              >
                {{ a1NoteText('foreign_insurance.city') }}
              </span>
            </label>
          </div>
        </div>

        <div v-if="a1IsForeigner && a1Form.proof_identity" :class="a1SectionClass">
          <h6 class="text-sm font-semibold text-neutral-900">
            {{ t('payroll.people.registration.a1.section.proof_identity') }}
          </h6>
          <div :class="a1GridClass">
            <label class="block">
              <span :class="a1LabelClass">
                {{ t('payroll.people.registration.a1.proof_identity.type_code') }}
              </span>
              <select
                v-model="a1Form.proof_identity.type_code"
                v-bind="a1FieldAttrs('proof_identity.type_code')"
                :disabled="a1Busy"
                data-test="a1-proof-identity-type-code"
              >
                <option :value="null">{{ t('payroll.people.registration.a1.unset') }}</option>
                <option
                  v-for="option in jmhzOptions?.proof_identity_type_codes ?? []"
                  :key="option.code"
                  :value="option.code"
                >{{ option.code }} · {{ option.label }}</option>
              </select>
              <span
                v-if="a1NoteText('proof_identity.type_code')"
                :class="a1NoteClass('proof_identity.type_code')"
              >
                {{ a1NoteText('proof_identity.type_code') }}
              </span>
            </label>
            <label class="block">
              <span :class="a1LabelClass">
                {{ t('payroll.people.registration.a1.proof_identity.number') }}
              </span>
              <input
                v-model="a1Form.proof_identity.number"
                type="text"
                v-bind="a1FieldAttrs('proof_identity.number')"
                :disabled="a1Busy"
                data-test="a1-proof-identity-number"
              >
              <span
                v-if="a1NoteText('proof_identity.number')"
                :class="a1NoteClass('proof_identity.number')"
              >
                {{ a1NoteText('proof_identity.number') }}
              </span>
            </label>
            <label class="block">
              <span :class="a1LabelClass">
                {{ t('payroll.people.registration.a1.proof_identity.foreign_issuer') }}
              </span>
              <input
                v-model="a1Form.proof_identity.foreign_issuer"
                type="text"
                v-bind="a1FieldAttrs('proof_identity.foreign_issuer')"
                :disabled="a1Busy"
                data-test="a1-proof-identity-foreign-issuer"
              >
            </label>
            <label class="block">
              <span :class="a1LabelClass">
                {{ t('payroll.people.registration.a1.proof_identity.country_code') }}
              </span>
              <SearchableSelect
                :class="['mt-1', a1FieldRing('proof_identity.country_code')]"
                :data-a1-field="'proof_identity.country_code'"
                :model-value="a1Form.proof_identity.country_code"
                :options="a1CodeOptions(jmhzOptions?.countries, a1Form.proof_identity.country_code)"
                :placeholder="t('payroll.people.registration.a1.country_select')"
                :no-results-label="t('payroll.people.registration.a1.country_no_results')"
                :disabled="a1Busy"
                accent="payroll"
                data-test="a1-proof-identity-country-code"
                @update:model-value="a1Form.proof_identity.country_code = $event"
              />
              <span
                v-if="a1NoteText('proof_identity.country_code')"
                :class="a1NoteClass('proof_identity.country_code')"
              >
                {{ a1NoteText('proof_identity.country_code') }}
              </span>
            </label>
          </div>
        </div>

        <div v-if="a1IsForeigner && a1Form.foreign_worker" :class="a1SectionClass">
          <h6 class="text-sm font-semibold text-neutral-900">
            {{ t('payroll.people.registration.a1.section.foreign_worker') }}
          </h6>
          <span v-if="a1NoteText('foreign_worker.permit')" :class="a1NoteClass('foreign_worker.permit')">
            {{ a1NoteText('foreign_worker.permit') }}
          </span>
          <div :class="a1GridClass">
            <label class="block">
              <span :class="a1LabelClass">
                {{ t('payroll.people.registration.a1.foreign_worker.free_access') }}
              </span>
              <select
                v-model="a1Form.foreign_worker.free_access"
                v-bind="a1FieldAttrs('foreign_worker.free_access')"
                :disabled="a1Busy"
                data-test="a1-foreign-worker-free-access"
              >
                <option :value="null">{{ t('payroll.people.registration.a1.unset') }}</option>
                <option :value="true">{{ t('common.yes') }}</option>
                <option :value="false">{{ t('common.no') }}</option>
              </select>
              <span
                v-if="a1NoteText('foreign_worker.free_access')"
                :class="a1NoteClass('foreign_worker.free_access')"
              >
                {{ a1NoteText('foreign_worker.free_access') }}
              </span>
            </label>
            <label class="block">
              <span :class="a1LabelClass">
                {{ t('payroll.people.registration.a1.foreign_worker.free_access_reason_code') }}
              </span>
              <SearchableSelect
                :class="['mt-1', a1FieldRing('foreign_worker.free_access_reason_code')]"
                :data-a1-field="'foreign_worker.free_access_reason_code'"
                :model-value="a1Form.foreign_worker.free_access_reason_code"
                :options="a1CodeOptions(
                  freeAccessReasonOptions,
                  a1Form.foreign_worker.free_access_reason_code,
                )"
                :placeholder="t('payroll.people.registration.a1.foreign_worker.free_access_reason_select')"
                :no-results-label="t('payroll.people.registration.a1.no_results')"
                :disabled="a1Busy"
                accent="payroll"
                data-test="a1-foreign-worker-free-access-reason-code"
                @update:model-value="a1Form.foreign_worker.free_access_reason_code = $event"
              />
            </label>
            <label class="block">
              <span :class="a1LabelClass">
                {{ t('payroll.people.registration.a1.foreign_worker.permit_type_code') }}
              </span>
              <select
                v-model="a1Form.foreign_worker.permit_type_code"
                v-bind="a1FieldAttrs('foreign_worker.permit_type_code')"
                :disabled="a1Busy"
                data-test="a1-foreign-worker-permit-type-code"
              >
                <option :value="null">{{ t('payroll.people.registration.a1.unset') }}</option>
                <option
                  v-for="option in jmhzOptions?.foreign_worker_permit_type_codes ?? []"
                  :key="option.code"
                  :value="option.code"
                >{{ option.code }} · {{ option.label }}</option>
              </select>
            </label>
            <label class="block">
              <span :class="a1LabelClass">
                {{ t('payroll.people.registration.a1.foreign_worker.issuing_labour_office_code') }}
              </span>
              <select
                v-model="a1Form.foreign_worker.issuing_labour_office_code"
                v-bind="a1FieldAttrs('foreign_worker.issuing_labour_office_code')"
                :disabled="a1Busy"
                data-test="a1-foreign-worker-issuing-labour-office-code"
              >
                <option :value="null">{{ t('payroll.people.registration.a1.unset') }}</option>
                <option
                  v-for="option in jmhzOptions?.labour_office_codes ?? []"
                  :key="option.code"
                  :value="option.code"
                >{{ option.code }} · {{ option.label }}</option>
              </select>
            </label>
            <label class="block">
              <span :class="a1LabelClass">
                {{ t('payroll.people.registration.a1.foreign_worker.permit_identifier') }}
              </span>
              <input
                v-model="a1Form.foreign_worker.permit_identifier"
                type="text"
                v-bind="a1FieldAttrs('foreign_worker.permit_identifier')"
                :disabled="a1Busy"
                data-test="a1-foreign-worker-permit-identifier"
              >
              <span
                v-if="a1NoteText('foreign_worker.permit_identifier')"
                :class="a1NoteClass('foreign_worker.permit_identifier')"
              >
                {{ a1NoteText('foreign_worker.permit_identifier') }}
              </span>
            </label>
            <label class="block">
              <span :class="a1LabelClass">
                {{ t('payroll.people.registration.a1.foreign_worker.permit_from') }}
              </span>
              <DateInput
                v-model="a1Form.foreign_worker.permit_from"
                v-bind="a1FieldAttrs('foreign_worker.permit_from')"
                :disabled="a1Busy"
                data-test="a1-foreign-worker-permit-from" />
              <span
                v-if="a1NoteText('foreign_worker.permit_from')"
                :class="a1NoteClass('foreign_worker.permit_from')"
              >
                {{ a1NoteText('foreign_worker.permit_from') }}
              </span>
            </label>
            <label class="block">
              <span :class="a1LabelClass">
                {{ t('payroll.people.registration.a1.foreign_worker.permit_to') }}
              </span>
              <DateInput
                v-model="a1Form.foreign_worker.permit_to"
                v-bind="a1FieldAttrs('foreign_worker.permit_to')"
                :disabled="a1Busy"
                data-test="a1-foreign-worker-permit-to" />
            </label>
            <label class="block">
              <span :class="a1LabelClass">
                {{ t('payroll.people.registration.a1.foreign_worker.stay_valid_from') }}
              </span>
              <DateInput
                v-model="a1Form.foreign_worker.stay_valid_from"
                v-bind="a1FieldAttrs('foreign_worker.stay_valid_from')"
                :disabled="a1Busy"
                data-test="a1-foreign-worker-stay-valid-from" />
              <span
                v-if="a1NoteText('foreign_worker.stay_valid_from')"
                :class="a1NoteClass('foreign_worker.stay_valid_from')"
              >
                {{ a1NoteText('foreign_worker.stay_valid_from') }}
              </span>
            </label>
            <label class="block">
              <span :class="a1LabelClass">
                {{ t('payroll.people.registration.a1.foreign_worker.stay_valid_to') }}
              </span>
              <DateInput
                v-model="a1Form.foreign_worker.stay_valid_to"
                v-bind="a1FieldAttrs('foreign_worker.stay_valid_to')"
                :disabled="a1Busy"
                data-test="a1-foreign-worker-stay-valid-to" />
            </label>
          </div>
        </div>

        <div :class="a1SectionClass">
          <h6 class="text-sm font-semibold text-neutral-900">
            {{ t('payroll.people.registration.a1.section.attachments') }}
          </h6>
          <ul class="mt-2 space-y-1">
            <li
              v-for="(attachment, index) in a1Form.attachments"
              :key="index"
              class="flex items-center gap-2 text-xs text-neutral-700"
            >
              <span class="font-medium">{{ attachment.name }}</span>
              <input
                v-model="attachment.description"
                type="text"
                :placeholder="t('payroll.people.registration.a1.attachment_description')"
                :class="a1InputClass"
                :disabled="a1Busy"
                :data-test="`a1-attachment-description-${index}`"
              >
              <button
                type="button"
                :class="btnOutline('danger')"
                :disabled="a1Busy"
                :data-test="`a1-attachment-remove-${index}`"
                @click="a1RemoveAttachment(index)"
              >
                {{ t('common.remove') }}
              </button>
            </li>
          </ul>
          <input
            type="file"
            class="mt-2 text-xs"
            :disabled="a1Busy || a1Form.attachments.length >= 9"
            data-test="a1-attachment-add"
            @change="a1AddAttachment"
          >
        </div>

        <div :class="a1SectionClass">
          <button
            type="button"
            :class="btnOutline('neutral')"
            data-test="registration-a1-payload-toggle"
            @click="a1ShowPayload = !a1ShowPayload"
          >
            {{ t(a1ShowPayload
              ? 'payroll.people.registration.a1.payload_hide'
              : 'payroll.people.registration.a1.payload_show') }}
          </button>
          <dl
            v-if="a1ShowPayload"
            class="mt-2 grid max-h-96 grid-cols-1 gap-x-4 gap-y-1 overflow-auto rounded-md border border-neutral-200 bg-neutral-50 p-3 text-xs sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]"
            data-test="registration-a1-payload"
          >
            <template v-for="(row, index) in a1PayloadRows" :key="`${row.path}-${index}`">
              <dt class="text-neutral-500">{{ row.label }}</dt>
              <dd class="break-words font-medium text-neutral-900">{{ row.value }}</dd>
            </template>
          </dl>
        </div>

        <!-- Uložení se pouští z lišty dole, ale výsledek hlásí panel nahoře:
             formulář má přes stovku polí a jednořádková chyba u tlačítka
             zapadla. Tady zůstává jen ukazatel, ať klik nevyzní naprázdno. -->
        <p
          v-if="a1ProfileError"
          class="rounded-md border border-danger-300 bg-danger-50 px-3 py-2 text-xs font-medium text-danger-800"
          data-test="registration-a1-error-inline"
        >
          {{ t('payroll.people.registration.a1.error_above') }}
        </p>

        <!-- Jedno společné Uložit pro celý profil: server bere celý cílový
             stav jedním zápisem, takže tlačítko u každé sekce by slibovalo
             dílčí uložení, které neexistuje. -->
        <div
          v-if="canWrite"
          class="-mx-3 -mb-3 flex flex-wrap items-center justify-end gap-2 border-t border-neutral-200 bg-surface px-3 py-2"
          :class="a1Managed ? '' : 'sticky bottom-[var(--app-footer-height,0px)]'"
        >
          <span v-if="a1ProfileMessage" class="mr-auto text-xs text-success-700" data-test="registration-a1-saved">
            {{ a1ProfileMessage }}
          </span>
          <!-- Číslo verze patří jen k ODESLANÉ registraci. U rozpracovaného
               profilu žádná historie nevzniká, takže by to bylo číslo bez
               seznamu, ke kterému se účetní nemá jak dostat — místo něj čas
               posledního uložení. -->
          <span
            v-else-if="a1SavedVersion > 0 && a1Submitted"
            class="mr-auto text-xs text-neutral-500"
          >
            {{ t('payroll.people.registration.a1.stored_version', { version: a1SavedVersion }) }}
          </span>
          <span
            v-else-if="a1SavedAt !== null"
            class="mr-auto text-xs text-neutral-500"
            data-test="registration-a1-stored-draft"
          >
            {{ t('payroll.people.registration.a1.stored_draft', { time: a1SavedAt }) }}
          </span>
          <!-- Zápis do kmenových dat patří k ukládání, ne do jiného panelu:
               kdo tu opraví adresu, čeká, že ji tím opravil i v kartě osoby.
               Zapisuje se jen to, co se opravdu liší a má kam jít. -->
          <label
            v-if="a1WritebackWritable.length > 0"
            class="flex items-center gap-2 text-xs text-neutral-700"
            :title="t('payroll.people.registration.a1.write_master_data_hint')"
          >
            <input
              v-model="a1WriteMasterData"
              type="checkbox"
              class="rounded border-neutral-300"
              :disabled="a1Busy"
              data-test="registration-a1-write-master-data"
            >
            {{ t('payroll.people.registration.a1.write_master_data', {
              count: a1WritebackWritable.length,
            }) }}
          </label>
          <button
            type="button"
            :class="btnOutline('neutral')"
            :disabled="a1Busy || a1Draft === null"
            data-test="registration-a1-reset"
            @click="a1ResetToSuggestion"
          >
            {{ t('payroll.people.registration.a1.reset') }}
          </button>
          <!-- Kontrola nic neukládá a nic neblokuje: jen označí pole, na
               kterých by padlo podání. Uložit projde vždycky. -->
          <button
            type="button"
            :class="btnOutline('danger')"
            :disabled="a1Busy"
            data-test="registration-a1-check"
            @click="checkA1Profile"
          >
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
              <path :d="ICONS.eye" />
            </svg>
            {{ a1Checking
              ? t('common.loading')
              : t('payroll.people.registration.a1.check') }}
          </button>
          <button
            v-if="!a1Managed"
            type="button"
            :class="btnFilled('success')"
            :disabled="a1Busy"
            data-test="registration-a1-save"
            @click="saveA1Profile"
          >
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
              <path :d="ICONS.check" />
            </svg>
            {{ a1ProfileSaving
              ? t('common.saving')
              : t('payroll.people.registration.a1.save') }}
          </button>
        </div>
      </div>
    </div>

    <div
      v-if="changeDetection && (changeDetection.proposals.length > 0 || changeError)"
      class="mt-4 rounded-lg border border-warning-300 bg-warning-50 p-3"
      data-test="registration-changes"
    >
      <h4 class="text-sm font-semibold text-neutral-900">
        {{ t('payroll.people.registration.changes.title') }}
      </h4>
      <p class="mt-1 text-xs text-neutral-700">
        {{ t('payroll.people.registration.changes.description') }}
      </p>
      <ul class="mt-3 space-y-3">
        <li
          v-for="proposal in changeDetection.proposals"
          :key="proposal.id"
          class="rounded-md border border-neutral-200 bg-surface p-3"
          :data-test="`registration-change-${proposal.id}`"
        >
          <div class="flex flex-wrap items-baseline justify-between gap-2">
            <span class="text-sm font-medium text-neutral-900">
              {{ proposalTitle(proposal) }}
            </span>
            <span class="text-xs text-neutral-600">
              {{ t('payroll.people.registration.changes.due', {
                date: formatDate(proposal.due_on),
              }) }}
            </span>
          </div>
          <p class="mt-1 text-xs text-neutral-700" data-test="registration-change-summary">
            {{ t('payroll.people.registration.changes.changed', {
              fields: proposalSummary(proposal),
            }) }}
          </p>
          <p class="mt-1 text-xs text-neutral-500">
            {{ proposal.deadline_source }}
          </p>
          <div
            v-if="proposal.fileable && fileEffectiveOn[proposal.id] !== undefined"
            class="mt-2 flex flex-wrap items-center gap-2"
            data-test="registration-change-effective"
          >
            <label
              class="text-xs font-medium text-neutral-700"
              :for="`registration-change-effective-on-${proposal.id}`"
            >
              {{ t('payroll.people.registration.changes.effective_on') }}
            </label>
            <DateInput
              :input-id="`registration-change-effective-on-${proposal.id}`"
              v-model="fileEffectiveOn[proposal.id]"
              class="rounded-md border border-neutral-300 bg-surface px-2 py-1.5 text-sm text-neutral-900"
              :data-test="`registration-change-effective-on-${proposal.id}`" />
            <span class="text-xs text-neutral-500">
              {{ t('payroll.people.registration.changes.effective_hint', {
                date: formatDate(proposal.detected_on),
              }) }}
            </span>
          </div>
          <div
            v-if="proposal.duty_kind === 'health_insurer_change'"
            class="mt-1 flex flex-wrap items-center gap-2 text-xs text-neutral-700"
            data-test="registration-change-health-hoz"
          >
            <span>{{ t('payroll.people.registration.changes.health_insurer_hoz') }}</span>
            <RouterLink
              :to="{ path: '/payroll/submissions/health', query: { period: proposal.detected_on.slice(0, 7) } }"
              class="font-semibold underline"
              data-test="registration-change-open-hoz"
            >
              {{ t('payroll.people.registration.changes.open_hoz', { period: proposal.detected_on.slice(0, 7) }) }}
            </RouterLink>
          </div>
          <template v-else-if="!proposal.fileable">
            <ul
              v-if="proposalProfileGaps(proposal).length > 0"
              class="mt-1 space-y-1 text-xs text-warning-800"
              data-test="registration-change-profile-gaps"
            >
              <li v-for="gap in proposalProfileGaps(proposal)" :key="gap.reason_code">
                {{ t(`payroll.people.registration.changes.gap.${gap.reason_code}`) }}
                <button
                  type="button"
                  class="ml-1 whitespace-nowrap rounded-full bg-warning-100 px-2 py-0.5 font-medium underline underline-offset-2 hover:bg-warning-200 hover:text-warning-900 focus:outline-none focus:ring-2 focus:ring-warning-500/40"
                  :data-test="`registration-change-open-profile-${gap.reason_code}`"
                  @click="openProfileGap(gap.field)"
                >
                  {{ t('payroll.people.registration.changes.open_profile') }}
                </button>
              </li>
            </ul>
            <p
              v-else
              class="mt-1 text-xs text-warning-800"
              data-test="registration-change-manual"
            >
              {{ t('payroll.people.registration.changes.manual_only') }}
            </p>
          </template>
          <ActionBar :actions="proposalActions(proposal)" />
          <div v-if="dismissOpenFor === proposal.id" class="mt-2 flex flex-wrap gap-2">
            <input
              v-model="dismissNotes[proposal.id]"
              type="text"
              maxlength="500"
              class="min-w-0 flex-1 rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm"
              :placeholder="t('payroll.people.registration.changes.dismiss_note')"
              :data-test="`registration-change-note-${proposal.id}`"
            >
            <button
              type="button"
              :class="btnFilled('primary')"
              :disabled="!(dismissNotes[proposal.id] ?? '').trim() || proposalBusy !== null"
              :data-test="`registration-change-dismiss-confirm-${proposal.id}`"
              @click="dismissProposal(proposal.id)"
            >
              {{ t('payroll.people.registration.changes.dismiss_confirm') }}
            </button>
          </div>
        </li>
      </ul>
      <p v-if="changesBusy" class="mt-2 text-xs text-neutral-500">
        {{ t('common.loading') }}
      </p>
      <p v-if="changeError" class="mt-2 text-xs text-danger-700" data-test="registration-changes-error">
        {{ changeError }}
      </p>
    </div>

    <div class="mt-4 rounded-lg border border-neutral-200 bg-neutral-50 p-3" data-test="registration-events">
      <div class="flex flex-wrap items-end gap-3">
        <label class="min-w-0 flex-1 text-xs font-medium text-neutral-700">
          {{ t('payroll.people.registration.event.select') }}
          <select
            v-model="selectedEventId"
            class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm text-neutral-900"
            :disabled="eventsBusy || busy || submission !== null"
            data-test="registration-event-select"
          >
            <option :value="null">{{ t('payroll.people.registration.event.automatic') }}</option>
            <option v-for="event in events" :key="event.id" :value="event.id">
              {{ eventOptionLabel(event) }}
            </option>
          </select>
        </label>
        <button
          type="button"
          :class="btnOutline('primary')"
          :disabled="!canWrite || busy || submission !== null"
          data-test="registration-event-new"
          @click="eventFormOpen = !eventFormOpen"
        >
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path :d="eventFormOpen ? ICONS.x : ICONS.plus" />
          </svg>
          {{ eventFormOpen
            ? t('payroll.people.registration.event.cancel_new')
            : t('payroll.people.registration.event.new') }}
        </button>
        <button
          v-if="!eventFormOpen"
          type="button"
          :class="btnOutline('success')"
          class="whitespace-nowrap"
          :disabled="!canWrite || busy || submission !== null"
          data-test="registration-completion-open"
          @click="openCompletion"
        >
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path :d="ICONS.plus" />
          </svg>
          {{ t('payroll.people.registration.completion.action') }}
        </button>
      </div>
      <p v-if="selectedEvent" class="mt-2 text-xs text-neutral-600" data-test="registration-event-selected">
        {{ t('payroll.people.registration.event.selected', {
          action: `A${selectedEvent.action_code}`,
          reference: selectedEvent.source_reference,
        }) }}
      </p>
      <p v-if="eventsBusy" class="mt-2 text-xs text-neutral-500">
        {{ t('common.loading') }}
      </p>

      <div
        v-if="eventFormOpen"
        class="mt-4 border-t border-neutral-200 pt-4"
        data-test="registration-event-form"
      >
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <label class="text-xs font-medium text-neutral-700">
            {{ t('payroll.people.registration.event.interaction') }}
            <select
              v-model="eventInteraction"
              class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm text-neutral-900"
              data-test="registration-event-interaction"
            >
              <option value="termination">A2 · {{ t('payroll.people.registration.interaction.termination') }}</option>
              <option value="change">A3 · {{ t('payroll.people.registration.interaction.change') }}</option>
              <option value="correction">A4 · {{ t('payroll.people.registration.interaction.correction') }}</option>
              <option value="variable_symbol_transfer">A5 · {{ t('payroll.people.registration.interaction.variable_symbol_transfer') }}</option>
              <option value="czech_legislation_start">A6 · {{ t('payroll.people.registration.interaction.czech_legislation_start') }}</option>
              <option value="czech_legislation_end">A7 · {{ t('payroll.people.registration.interaction.czech_legislation_end') }}</option>
              <option value="cancellation">A8 · {{ t('payroll.people.registration.interaction.cancellation') }}</option>
            </select>
          </label>
          <label class="text-xs font-medium text-neutral-700">
            {{ t(`payroll.people.registration.event.effective_on.${eventInteraction}`) }}
            <DateInput
              v-model="effectiveOn"
              required
              class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm text-neutral-900"
              data-test="registration-event-effective-on" />
          </label>
          <label v-if="sourceReferenceRequired" class="text-xs font-medium text-neutral-700 sm:col-span-2">
            {{ t('payroll.people.registration.event.source_reference') }}
            <input
              v-model="sourceReference"
              type="text"
              required
              maxlength="191"
              class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm text-neutral-900"
              :placeholder="t('payroll.people.registration.event.source_reference_hint')"
              data-test="registration-event-source-reference"
            />
          </label>
        </div>

        <div v-if="eventInteraction === 'termination'" class="mt-4 space-y-4" data-test="registration-event-a2">
          <p class="rounded-md border border-primary-200 bg-primary-50 p-3 text-xs text-primary-800">
            {{ t('payroll.people.registration.event.a2_hint') }}
          </p>
          <div class="flex flex-wrap items-center gap-2" data-test="registration-a2-prefill">
            <button
              type="button"
              :class="btnOutline('primary')"
              class="whitespace-nowrap"
              :disabled="!a2Prefill"
              data-test="registration-a2-prefill-button"
              @click="applyA2Prefill"
            >
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.copy" /></svg>
              {{ t('payroll.people.termination.a2_prefill') }}
            </button>
            <span class="text-xs text-neutral-500">
              {{ a2Prefill ? t('payroll.people.termination.a2_prefill_hint') : t('payroll.people.termination.a2_prefill_missing') }}
            </span>
          </div>
          <div class="rounded-md border border-neutral-200 p-3" data-test="registration-a2-identifiers-verified-box">
            <label class="flex items-start gap-2 text-xs text-neutral-700">
              <input
                v-model="identifiersVerifiedInCsszList"
                type="checkbox"
                class="mt-0.5 rounded border-neutral-300"
                data-test="registration-a2-identifiers-verified"
              />
              <span>{{ t('payroll.people.registration.event.identifiers_verified') }}</span>
            </label>
            <p class="mt-1 text-xs text-neutral-500">
              {{ t('payroll.people.registration.event.identifiers_verified_hint') }}
            </p>
          </div>
          <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <label class="text-xs font-medium text-neutral-700">
              {{ t('payroll.people.registration.event.ended_by_death') }}
              <select v-model="endedByDeath" data-test="registration-a2-ended-by-death" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm text-neutral-900">
                <option value="omit">{{ t('payroll.people.registration.event.not_applicable') }}</option>
                <option value="no">{{ t('common.no') }}</option>
                <option value="yes">{{ t('common.yes') }}</option>
              </select>
            </label>
            <label class="text-xs font-medium text-neutral-700">
              {{ t('payroll.people.registration.event.unemployment_mode') }}
              <select v-model="unemploymentMode" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm text-neutral-900">
                <option value="omit">{{ t('payroll.people.registration.event.unemployment.omit') }}</option>
                <option value="spec_early">{{ t('payroll.people.registration.event.unemployment.spec_early') }}</option>
                <option value="not_provided_2">{{ t('payroll.people.registration.event.unemployment.not_provided_2') }}</option>
                <option value="not_provided_3">{{ t('payroll.people.registration.event.unemployment.not_provided_3') }}</option>
                <option value="provided">{{ t('payroll.people.registration.event.unemployment.provided') }}</option>
              </select>
            </label>
          </div>
          <label v-if="unemploymentMode === 'spec_early'" class="block text-xs font-medium text-neutral-700">
            {{ t('payroll.people.registration.event.early_termination_reason') }}
            <input v-model="earlyTerminationReason" inputmode="numeric" maxlength="1" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm text-neutral-900 sm:max-w-xs" />
          </label>
          <template v-if="unemploymentMode === 'not_provided_3' || unemploymentMode === 'provided'">
            <label class="block text-xs font-medium text-neutral-700">
              {{ t('payroll.people.registration.event.average_net_earnings') }}
              <input v-model="averageNetEarnings" inputmode="numeric" maxlength="10" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm text-neutral-900 sm:max-w-xs" />
              <span
                v-if="a2NetAverageNote && averageNetEarnings === ''"
                class="mt-1 block font-normal text-warning-700"
                data-test="registration-a2-net-average-note"
              >{{ t('payroll.people.termination.a2_net_average_missing', { reason: a2NetAverageNote }) }}</span>
            </label>
            <fieldset class="rounded-lg border border-neutral-200 bg-surface p-3">
              <legend class="px-1 text-xs font-medium text-neutral-700">
                {{ t('payroll.people.registration.event.pension_periods') }}
              </legend>
              <div v-for="(period, index) in pensionPeriods" :key="index" class="mt-2 flex flex-wrap items-end gap-2">
                <label class="min-w-36 flex-1 text-xs text-neutral-600">
                  {{ t('common.from') }}
                  <DateInput v-model="period.from" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm text-neutral-900" />
                </label>
                <label class="min-w-36 flex-1 text-xs text-neutral-600">
                  {{ t('common.to') }}
                  <DateInput v-model="period.to" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm text-neutral-900" />
                </label>
                <button type="button" :class="btnOutline('danger')" :disabled="pensionPeriods.length === 1" @click="removePensionPeriod(index)">
                  <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.x" /></svg>
                  {{ t('common.remove') }}
                </button>
              </div>
              <button type="button" class="cursor-pointer mt-3" :class="btnOutline('neutral')" @click="addPensionPeriod">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.plus" /></svg>
                {{ t('payroll.people.registration.event.add_period') }}
              </button>
            </fieldset>
          </template>
          <div v-if="unemploymentMode === 'provided'" class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <label class="text-xs font-medium text-neutral-700">
              {{ t('payroll.people.registration.event.employment_type') }}
              <select v-model="employmentType" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm text-neutral-900" data-test="registration-a2-employment-type">
                <option value="omit">{{ t('payroll.people.registration.event.not_applicable') }}</option>
                <option value="1">{{ t('payroll.people.registration.event.employment_type_1') }}</option>
                <option value="2">{{ t('payroll.people.registration.event.employment_type_2') }}</option>
                <template v-if="a2FromNextVintage">
                  <option
                    v-for="option in jmhzOptions?.from_2027?.employment_types ?? []"
                    :key="option.code"
                    :value="option.code"
                  >{{ option.label }}</option>
                </template>
              </select>
              <span class="mt-1 block text-xs font-normal text-neutral-500" data-test="registration-a2-state-employee-hint">
                {{ t('payroll.people.registration.event.state_employee_hint') }}
              </span>
              <span
                v-if="stateEmployeeWarning"
                class="mt-1 block rounded-md border border-warning-200 bg-warning-50 p-2 text-xs font-normal text-warning-800"
                data-test="registration-a2-state-employee-warning"
              >
                {{ stateEmployeeWarning === 'service_1_11'
                  ? t('payroll.people.registration.event.state_employee_warning_service_1_11')
                  : t('payroll.people.registration.event.state_employee_warning_service_after') }}
              </span>
            </label>
            <label v-if="employmentType !== 'omit'" class="text-xs font-medium text-neutral-700">
              {{ t('payroll.people.registration.event.termination_reason') }}
              <select
                v-if="a2FromNextVintage && employmentType !== '1'"
                v-model="terminationReason"
                class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm text-neutral-900"
                data-test="registration-a2-termination-reason"
              >
                <option value="">{{ t('payroll.people.registration.event.select_placeholder') }}</option>
                <option
                  v-for="option in a2ServiceReasonOptions"
                  :key="option.code"
                  :value="option.code"
                >{{ option.code }} - {{ option.label }}</option>
              </select>
              <input v-else v-model="terminationReason" inputmode="numeric" maxlength="3" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm text-neutral-900" data-test="registration-a2-termination-reason" />
            </label>
            <label v-if="employmentType !== 'omit' && settlementNeeded" class="text-xs font-medium text-neutral-700">
              {{ t('payroll.people.registration.event.entitlement') }}
              <select v-model="entitlement" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm text-neutral-900">
                <option value="omit">{{ t('payroll.people.registration.event.select_placeholder') }}</option>
                <option value="no">{{ t('common.no') }}</option>
                <option value="yes">{{ t('common.yes') }}</option>
              </select>
            </label>
            <label v-if="entitlement === 'yes'" class="text-xs font-medium text-neutral-700">
              {{ t('payroll.people.registration.event.paid_in_full') }}
              <select v-model="paidInFull" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm text-neutral-900">
                <option value="no">{{ t('common.no') }}</option>
                <option value="yes">{{ t('common.yes') }}</option>
              </select>
            </label>
            <label v-if="entitlement === 'yes'" class="text-xs font-medium text-neutral-700">
              {{ t('payroll.people.registration.event.settlement_kind') }}
              <select v-model="settlementAmountKind" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm text-neutral-900" data-test="registration-a2-settlement-kind">
                <template v-if="employmentType === '1'">
                  <option value="replacement">{{ t('payroll.people.registration.event.settlement.replacement') }}</option>
                  <option value="golden_handshake">{{ t('payroll.people.registration.event.settlement.golden_handshake') }}</option>
                </template>
                <template v-else>
                  <option value="severance_pay">{{ t('payroll.people.registration.event.settlement.severance_pay') }}</option>
                  <option value="disposal">{{ t('payroll.people.registration.event.settlement.disposal') }}</option>
                </template>
              </select>
            </label>
            <label v-if="entitlement === 'yes'" class="text-xs font-medium text-neutral-700">
              {{ t('payroll.people.registration.event.settlement_amount') }}
              <input v-model="settlementAmount" inputmode="numeric" maxlength="10" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm text-neutral-900" data-test="registration-a2-settlement-amount" />
            </label>
          </div>
        </div>

        <div v-if="eventInteraction === 'change'" class="mt-4 space-y-2" data-test="registration-event-change-scope-box">
          <label class="block text-xs font-medium text-neutral-700">
            {{ t('payroll.people.registration.completion.scope_label') }}
            <select
              v-model="changeScope"
              class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm text-neutral-900 sm:max-w-md"
              data-test="registration-event-change-scope"
            >
              <option value="single">{{ t('payroll.people.registration.completion.scope_single') }}</option>
              <option value="full">{{ t('payroll.people.registration.completion.scope_full') }}</option>
              <option value="minimal">{{ t('payroll.people.registration.completion.scope_minimal') }}</option>
            </select>
          </label>
          <p
            v-if="isCompletion"
            class="rounded-md border border-primary-200 bg-primary-50 p-3 text-xs text-primary-800"
            data-test="registration-completion-hint"
          >
            {{ t('payroll.people.registration.completion.hint') }}
          </p>
        </div>

        <div v-if="(eventInteraction === 'change' && !isCompletion) || eventInteraction === 'correction'" class="mt-4 space-y-4" data-test="registration-event-delta">
          <div v-if="eventInteraction === 'correction'" class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <label class="text-xs font-medium text-neutral-700">
              {{ t('payroll.people.registration.event.source_submission_id') }}
              <input v-model.number="sourceSubmissionId" type="number" min="1" required class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm text-neutral-900" data-test="registration-event-source-submission-id" />
            </label>
            <label class="text-xs font-medium text-neutral-700">
              {{ t('payroll.people.registration.event.discovered_on') }}
              <DateInput v-model="discoveredOn" required class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm text-neutral-900" data-test="registration-event-discovered-on" />
            </label>
          </div>
          <label v-if="eventInteraction === 'change'" class="block text-xs font-medium text-neutral-700 sm:max-w-md">
            {{ t('payroll.people.registration.event.learned_on') }}
            <DateInput v-model="learnedOn" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm text-neutral-900" data-test="registration-event-learned-on" />
          </label>
          <label class="block text-xs font-medium text-neutral-700">
            {{ t('payroll.people.registration.event.delta_field') }}
            <select v-model="deltaField" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm text-neutral-900 sm:max-w-md" data-test="registration-event-delta-field">
              <option v-for="field in deltaFieldOptions" :key="field" :value="field">
                {{ t(`payroll.people.registration.event.delta.${field}`) }}
              </option>
            </select>
          </label>
          <div v-if="deltaField === 'contact_address'" class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <label class="text-xs font-medium text-neutral-700">{{ t('payroll.people.registration.event.address.street') }}<input v-model="addressStreet" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm text-neutral-900" /></label>
            <label class="text-xs font-medium text-neutral-700">{{ t('payroll.people.registration.event.address.house_number') }}<input v-model="addressHouseNumber" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm text-neutral-900" /></label>
            <label class="text-xs font-medium text-neutral-700">{{ t('payroll.people.registration.event.address.orientation_number') }}<input v-model="addressOrientationNumber" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm text-neutral-900" /></label>
            <label class="text-xs font-medium text-neutral-700">{{ t('payroll.people.registration.event.address.postal_code') }}<input v-model="addressPostalCode" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm text-neutral-900" /></label>
            <label class="text-xs font-medium text-neutral-700">{{ t('payroll.people.registration.event.address.city') }}<input v-model="addressCity" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm text-neutral-900" /></label>
            <label class="text-xs font-medium text-neutral-700">{{ t('payroll.people.registration.event.address.country_code') }}<input v-model="addressCountryCode" maxlength="2" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm uppercase text-neutral-900" /></label>
            <label class="text-xs font-medium text-neutral-700 sm:col-span-2">{{ t('payroll.people.registration.event.ruian_point') }}<input v-model="addressRuianPoint" maxlength="12" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm text-neutral-900" /></label>
          </div>
          <div v-else-if="deltaField === 'tax_residency'">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <label class="text-xs font-medium text-neutral-700">{{ t('payroll.people.registration.event.address.country_code') }}<input v-model="residencyCountryCode" maxlength="2" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm uppercase text-neutral-900" /></label>
              <label class="text-xs font-medium text-neutral-700">{{ t('payroll.people.registration.event.residency_changed_on') }}<DateInput v-model="residencyChangedOn" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm text-neutral-900" /></label>
            </div>
            <p class="mt-2 text-xs text-neutral-500" data-test="registration-event-tax-residency-two-step-hint">
              {{ t('payroll.people.registration.event.tax_residency_two_step_hint') }}
            </p>
            <p class="mt-1 text-xs text-warning-700" data-test="registration-event-tax-residency-latency-hint">
              {{ t('payroll.people.registration.event.tax_residency_latency_hint') }}
            </p>
          </div>
          <label v-else-if="deltaField === 'highest_education_code'" class="block text-xs font-medium text-neutral-700">
            {{ t('payroll.people.registration.event.delta.highest_education_code') }}
            <select
              v-model="deltaValue"
              class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm text-neutral-900 sm:max-w-md"
              data-test="registration-event-delta-education"
            >
              <option value="">{{ t('payroll.people.registration.event.select_placeholder') }}</option>
              <option
                v-for="option in jmhzOptions?.education_levels ?? []"
                :key="option.code"
                :value="option.code"
              >{{ option.code }} · {{ option.label }}</option>
            </select>
          </label>
          <template v-else-if="deltaField === 'actual_start_on'">
            <label class="block text-xs font-medium text-neutral-700">
              {{ t('payroll.people.registration.event.delta.actual_start_on') }}
              <DateInput v-model="deltaValue" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm text-neutral-900 sm:max-w-md" data-test="registration-event-delta-actual-start" />
            </label>
            <p class="text-xs text-neutral-600" data-test="registration-event-a4-start-hint">
              {{ t('payroll.people.registration.event.a4_start_hint') }}
            </p>
            <div class="flex flex-wrap items-center gap-2">
              <label :class="btnOutline('primary')" class="cursor-pointer whitespace-nowrap">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.plus" /></svg>
                {{ t('payroll.people.registration.event.a4_attachment') }}
                <input type="file" class="sr-only" data-test="registration-event-a4-attachment" @change="addCancellationAttachment" />
              </label>
              <span v-if="cancellationAttachment" class="text-xs text-neutral-700" data-test="registration-event-a4-attachment-name">
                {{ cancellationAttachment.name }}
              </span>
              <span v-else class="text-xs text-warning-700">
                {{ t('payroll.people.registration.event.a4_attachment_missing') }}
              </span>
            </div>
            <label class="block text-xs font-medium text-neutral-700">
              {{ t('payroll.people.registration.event.a8_attachment_description') }}
              <input v-model="cancellationAttachmentDescription" maxlength="255" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm text-neutral-900 sm:max-w-md" />
            </label>
          </template>
          <label v-else class="block text-xs font-medium text-neutral-700">
            {{ t(`payroll.people.registration.event.delta.${deltaField}`) }}
            <input v-model="deltaValue" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm text-neutral-900 sm:max-w-md" />
          </label>
        </div>

        <label v-if="eventInteraction === 'variable_symbol_transfer'" class="mt-4 block text-xs font-medium text-neutral-700">
          {{ t('payroll.people.registration.event.new_variable_symbol') }}
          <input v-model="newVariableSymbol" inputmode="numeric" maxlength="10" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm text-neutral-900 sm:max-w-md" data-test="registration-event-new-variable-symbol" />
        </label>

        <div v-if="eventInteraction === 'czech_legislation_start' || eventInteraction === 'czech_legislation_end'" class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2" data-test="registration-event-foreign-insurance">
          <label class="text-xs font-medium text-neutral-700 sm:col-span-2">{{ t('payroll.people.registration.event.foreign_name') }}<input v-model="foreignName" maxlength="100" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm text-neutral-900" /></label>
          <label class="text-xs font-medium text-neutral-700">{{ t('payroll.people.registration.event.address.country_code') }}<input v-model="foreignCountryCode" maxlength="2" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm uppercase text-neutral-900" /></label>
          <label class="text-xs font-medium text-neutral-700">{{ t('payroll.people.registration.event.foreign_identifier') }}<input v-model="foreignIdentifier" maxlength="50" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm text-neutral-900" /></label>
          <label class="text-xs font-medium text-neutral-700">{{ t('payroll.people.registration.event.address.street') }}<input v-model="foreignStreet" maxlength="50" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm text-neutral-900" /></label>
          <label class="text-xs font-medium text-neutral-700">{{ t('payroll.people.registration.event.address.house_number') }}<input v-model="foreignHouseNumber" maxlength="50" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm text-neutral-900" /></label>
          <label class="text-xs font-medium text-neutral-700">{{ t('payroll.people.registration.event.address.orientation_number') }}<input v-model="foreignOrientationNumber" maxlength="50" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm text-neutral-900" /></label>
          <label class="text-xs font-medium text-neutral-700">{{ t('payroll.people.registration.event.address.postal_code') }}<input v-model="foreignPostalCode" maxlength="50" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm text-neutral-900" /></label>
          <label class="text-xs font-medium text-neutral-700">{{ t('payroll.people.registration.event.address.city') }}<input v-model="foreignCity" maxlength="50" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm text-neutral-900" /></label>
          <label class="text-xs font-medium text-neutral-700">{{ t('payroll.people.registration.event.foreign_sector') }}<select v-model="foreignSector" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm text-neutral-900" data-test="registration-event-foreign-sector">
            <option value="">{{ t('payroll.people.registration.a1.unset') }}</option>
            <option v-for="code in FOREIGN_INSURANCE_SECTORS" :key="code" :value="code">
              {{ code }} · {{ t(`payroll.people.registration.a1.foreign_insurance.sector_option.${code}`) }}
            </option>
          </select></label>
        </div>

        <div v-if="eventInteraction === 'cancellation'" class="mt-4 space-y-3" data-test="registration-event-a8">
          <label class="block text-xs font-medium text-neutral-700">
            {{ t('payroll.people.registration.event.source_submission_id') }}
            <input v-model.number="sourceSubmissionId" type="number" min="1" required class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm text-neutral-900 sm:max-w-md" data-test="registration-event-source-submission-id" />
          </label>
          <label class="block text-xs font-medium text-neutral-700">
            {{ t('payroll.people.registration.event.a8_reason') }}
            <select
              v-model="cancellationReason"
              class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm text-neutral-900 sm:max-w-md"
              data-test="registration-event-a8-reason"
            >
              <option value="not_started">{{ t('payroll.people.registration.event.a8_reason_not_started') }}</option>
              <option value="other">{{ t('payroll.people.registration.event.a8_reason_other') }}</option>
            </select>
          </label>
          <template v-if="cancellationReason === 'not_started'">
            <label class="flex items-start gap-2 text-xs text-neutral-700">
              <input v-model="notStartedConfirmed" type="checkbox" class="mt-0.5 rounded border-neutral-300" data-test="registration-event-not-started" />
              <span>{{ t('payroll.people.registration.event.not_started_confirmation') }}</span>
            </label>
            <p class="text-xs text-warning-700">{{ t('payroll.people.registration.event.a8_hint') }}</p>
          </template>
          <template v-else>
            <p class="text-xs text-neutral-600" data-test="registration-event-a8-other-hint">
              {{ t('payroll.people.registration.event.a8_other_hint') }}
            </p>
            <div class="flex flex-wrap items-center gap-2">
              <label :class="btnOutline('primary')" class="cursor-pointer whitespace-nowrap">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.plus" /></svg>
                {{ t('payroll.people.registration.event.a8_attachment') }}
                <input type="file" class="sr-only" data-test="registration-event-a8-attachment" @change="addCancellationAttachment" />
              </label>
              <span v-if="cancellationAttachment" class="text-xs text-neutral-700" data-test="registration-event-a8-attachment-name">
                {{ cancellationAttachment.name }}
              </span>
              <span v-else class="text-xs text-warning-700">
                {{ t('payroll.people.registration.event.a8_attachment_missing') }}
              </span>
            </div>
            <label class="block text-xs font-medium text-neutral-700">
              {{ t('payroll.people.registration.event.a8_attachment_description') }}
              <input v-model="cancellationAttachmentDescription" maxlength="255" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm text-neutral-900 sm:max-w-md" />
            </label>
          </template>
        </div>

        <div class="mt-5 flex flex-wrap justify-end gap-2">
          <button type="button" :class="btnOutline('neutral')" :disabled="eventSaving" @click="eventFormOpen = false">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.x" /></svg>
            {{ t('common.cancel') }}
          </button>
          <button type="button" :class="btnFilled('primary')" :disabled="!eventCanSave" data-test="registration-event-save" @click="saveEvent">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.check" /></svg>
            {{ eventSaving ? t('common.loading') : t('payroll.people.registration.event.save_and_preview') }}
          </button>
        </div>
      </div>

      <div v-if="eventError" class="mt-3 rounded-lg border border-danger-500/30 bg-danger-50 p-3 text-sm text-danger-700" role="alert" data-test="registration-event-error">
        <p>{{ eventError }}</p>
        <div v-if="registrationRemediation(eventErrorCode) !== null" class="mt-2 flex flex-wrap gap-2">
          <button
            v-if="registrationRemediation(eventErrorCode)?.kind === 'a1_profile'"
            type="button"
            :class="btnFilled('success')"
            class="whitespace-nowrap"
            data-test="registration-event-error-open-a1"
            @click="openA1ProfileFromError"
          >
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
              <path :d="ICONS.eye" />
            </svg>
            {{ t('payroll.people.registration.a1.show') }}
          </button>
          <RouterLink
            v-else-if="remediationTarget(eventErrorCode) !== null"
            :to="remediationTarget(eventErrorCode)!"
            :class="btnOutline('danger')"
            class="whitespace-nowrap"
            data-test="registration-event-error-open-remediation"
          >
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
              <path :d="ICONS.edit" />
            </svg>
            {{ registrationRemediation(eventErrorCode)?.kind === 'employer_settings'
              ? t('payroll.people.registration.missing.open_settings')
              : t('payroll.people.registration.missing.open') }}
          </RouterLink>
        </div>
      </div>
    </div>

    <div
      v-if="deadline"
      class="mt-3 rounded-lg border border-neutral-200 bg-neutral-50 p-3 text-xs text-neutral-700"
      data-test="registration-deadline"
    >
      <p class="font-medium text-neutral-900">
        {{ agendaLabel }} · {{ interactionLabel }}
      </p>
      <p v-if="deadline.statutory === false" class="mt-1" data-test="registration-deadline-not-statutory">
        {{ t('payroll.people.registration.registration_window.not_statutory', {
          milestone: formatDate(deadline.due_on),
        }) }}
      </p>
      <p v-else-if="deadline.derived === false" class="mt-1 text-warning-700" data-test="registration-deadline-not-derived">
        {{ t('payroll.people.registration.registration_window.not_derived', {
          start: formatDate(deadline.due_on),
        }) }}
      </p>
      <p v-else class="mt-1">
        {{ t('payroll.people.registration.window', {
          from: formatDate(deadline.earliest_registration_on),
          to: formatDate(deadline.due_on),
        }) }}
      </p>
      <p
        v-if="deadline.ruleset_id === FOREIGN_PERMIT_RULESET_ID"
        class="mt-1 text-warning-700"
        data-test="registration-deadline-foreign-permit"
      >
        {{ t('payroll.people.registration.registration_window.foreign_permit') }}
      </p>
      <p
        v-if="deadlineOverdue"
        class="mt-2 rounded-md border border-danger-500/30 bg-danger-50 px-2 py-1 font-medium text-danger-700"
        role="alert"
        data-test="registration-deadline-overdue"
      >
        {{ t('payroll.people.registration.filing_state.deadline_overdue', { due: formatDate(deadline.due_on) }) }}
      </p>
      <p
        v-if="preview?.employer_registration"
        class="mt-1 text-warning-700"
        data-test="registration-employer-deadline"
      >
        {{ t('payroll.people.registration.employer_window', {
          to: formatDate(preview.employer_registration.due_on),
        }) }}
      </p>
    </div>

    <div
      v-if="submission"
      class="mt-3 rounded-lg border border-success-500/30 bg-success-50 p-3"
      data-test="registration-prepared"
    >
      <p class="text-sm font-medium text-success-700">
        {{ t('payroll.people.registration.prepared', {
          agenda: agendaLabel,
        }) }}
      </p>
      <!--
        Záměrně NE „zaměstnanec je přihlášený": podání je připravené k odeslání
        a potvrzení od ČSSZ zatím žádné není.
      -->
      <p class="mt-1 text-xs text-success-700">
        {{ t('payroll.people.registration.not_sent_yet') }}
      </p>
      <p class="mt-1 break-all font-mono text-xs text-success-700">
        {{ submission.artifact_sha256.slice(0, 16) }}…
      </p>
      <p
        v-if="vintageExpired"
        class="mt-2 rounded-md border border-warning-300 bg-warning-50 px-2 py-1 text-xs text-warning-800"
        role="alert"
        data-test="registration-vintage-expired"
      >
        {{ t('payroll.people.registration.vintage_expired') }}
      </p>

      <div class="mt-3 border-t border-success-500/20 pt-3" data-test="registration-transport-actions">
        <ActionBar :actions="transportActions" />
      </div>
    </div>

    <div
      v-if="transportAttempt"
      class="mt-3 rounded-lg border border-primary-200 bg-primary-50 p-3 text-sm text-primary-900"
      data-test="registration-transport-result"
    >
      <p class="font-medium">
        {{ t('payroll.people.registration.attempt', {
          id: transportAttempt.id,
          status: t(`payroll.submissions.transport.status.${transportAttempt.status}`),
        }) }}
      </p>
      <p v-if="transportAttempt.status === 'awaiting_protocol'" class="mt-1 text-xs">
        {{ t('payroll.people.registration.awaiting_protocol') }}
      </p>
      <p v-if="transport?.report" class="mt-1 text-xs">
        {{ t('payroll.people.registration.protocol', {
          status: t(`payroll.submissions.transport.protocol_status.${transport.report.status}`),
          errors: transport.report.errors.length,
        }) }}
      </p>
      <p v-if="transportMessage" class="mt-1 text-xs text-success-800">
        {{ transportMessage }}
      </p>
    </div>

    <div
      v-if="(preview?.warnings ?? []).length > 0 && !submission"
      class="mt-3 rounded-lg border border-warning-300 bg-warning-50 p-3 text-xs text-warning-800"
      role="status"
      data-test="registration-preview-warnings"
    >
      <p class="font-semibold">{{ t('payroll.people.registration.warnings.title') }}</p>
      <ul class="mt-1 space-y-2">
        <li
          v-for="warning in preview?.warnings ?? []"
          :key="warning.code"
          :data-test="`registration-preview-warning-${warning.code}`"
        >
          <p>{{ warning.code === 'employer_variable_symbol_placeholder'
            ? t('payroll.people.registration.missing.variable_symbol_placeholder')
            : warning.message }}</p>
          <RouterLink
            v-if="problemTarget(warning.target, null, warning.field) !== null"
            :to="problemTarget(warning.target, null, warning.field)!"
            :class="btnOutline('warning')"
            class="mt-1 whitespace-nowrap"
            data-test="registration-preview-warning-link"
          >
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
              <path :d="ICONS.edit" />
            </svg>
            {{ t('payroll.people.registration.missing.open_settings') }}
          </RouterLink>
        </li>
      </ul>
    </div>

    <div v-if="preview && !submission" class="mt-3">
      <div class="flex flex-wrap gap-2">
        <button
          type="button"
          :class="btnOutline('neutral')"
          data-test="registration-toggle-xml"
          @click="showXml = !showXml"
        >
          {{ showXml
            ? t('payroll.people.registration.hide_xml')
            : t('payroll.people.registration.show_xml') }}
        </button>
        <button
          type="button"
          :class="btnOutline('neutral')"
          @click="copyXml"
        >
          {{ t('payroll.people.registration.copy_xml') }}
        </button>
      </div>
      <pre
        v-if="showXml"
        class="mt-3 max-h-80 overflow-auto rounded-lg bg-neutral-900 p-3 text-xs leading-relaxed text-neutral-100"
      >{{ preview.xml }}</pre>
      <p class="mt-2 text-xs text-neutral-500">
        {{ preview.official_submission.reason }}
      </p>
    </div>

    <div
      v-if="error"
      class="mt-3 rounded-lg border border-danger-500/30 bg-danger-50 p-3 text-sm text-danger-700"
      role="alert"
      data-test="registration-error"
    >
      <p>{{ error }}</p>
      <button
        v-if="registrationRemediation(errorCode)?.kind === 'a1_profile'"
        type="button"
        :class="[btnFilled('success'), 'mt-2']"
        data-test="registration-error-open-a1"
        @click="openA1ProfileFromError"
      >
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
          <path :d="ICONS.eye" />
        </svg>
        {{ t('payroll.people.registration.a1.show') }}
      </button>
      <RouterLink
        v-else-if="remediationTarget(errorCode) !== null"
        :to="remediationTarget(errorCode)!"
        :class="[btnOutline('danger'), 'mt-2 whitespace-nowrap']"
        data-test="registration-error-open-remediation"
      >
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
          <path :d="ICONS.edit" />
        </svg>
        {{ registrationRemediation(errorCode)?.kind === 'employer_settings'
          ? t('payroll.people.registration.missing.open_settings')
          : t('payroll.people.registration.missing.open') }}
      </RouterLink>
      <ul v-if="errorProblems.length > 0" class="mt-2 space-y-1.5" data-test="registration-missing-list">
        <li
          v-for="problem in errorProblems"
          :key="problem.field"
          class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs"
          :data-test="`registration-missing-${problem.field}`"
        >
          <span class="font-medium">{{ registrationItemLabel(problem, t) }}</span>
          <span class="text-danger-600">
            {{ problem.target === 'employer_settings'
              ? t('payroll.people.registration.missing.where_employer')
              : t('payroll.people.registration.missing.where_person') }}
          </span>
          <RouterLink
            v-if="problemTarget(problem.target, problem.panel, problem.field) !== null"
            :to="problemTarget(problem.target, problem.panel, problem.field)!"
            :class="btnOutline('danger')"
            class="whitespace-nowrap"
            :data-test="`registration-missing-link-${problem.field}`"
          >
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
              <path :d="ICONS.edit" />
            </svg>
            {{ t('payroll.people.registration.missing.open') }}
          </RouterLink>
        </li>
      </ul>
    </div>
    <ProductionSendConfirmDialog
      v-if="sendConfirmRequest"
      :message="sendConfirmRequest.message"
      @confirm="settleSendConfirm(true)"
      @cancel="settleSendConfirm(false)"
    />
  </section>
</template>

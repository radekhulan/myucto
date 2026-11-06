<script setup lang="ts">
/**
 * Roční zúčtování záloh a daňového zvýhodnění (§ 38ch ZDP).
 *
 * Obrazovka má jeden úkol: ukázat, co u koho chybí, a teprve když nechybí nic,
 * nechat zúčtování provést. Proto je rozdělená na dvě části — vlevo seznam lidí
 * se stavem, vpravo evidence podkladů a výpočet vybraného zaměstnance.
 *
 * Dvě věci, které se tu záměrně nedělají:
 *  - Tlačítko „Provést" NEZMIZÍ, když něco chybí. Zůstane zašedlé i s větou,
 *    proč. Zmizelé tlačítko vypadá jako chyba aplikace, ne jako chybějící
 *    podklad.
 *  - Odmítnuté zúčtování se nezobrazuje jako selhání. Vrací se z API jako
 *    normální odpověď a vypíše se seznam vět „co k tomu chybí".
 */
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink, useRoute } from 'vue-router'
import {
  payrollApi,
  type PayrollAnnualDocumentBatch,
  type PayrollAnnualDocumentBatchItem,
  type PayrollAnnualClaimsSet,
  type PayrollAnnualClaimsView,
  type PayrollAnnualDependant,
  type PayrollAnnualDeductionKind,
  type PayrollAnnualPreschoolFacilityKind,
  type PayrollAnnualSettlementAnnualClaims,
  type PayrollAnnualSettlementCertificate,
  type PayrollAnnualSettlementCaregiverStatus,
  type PayrollAnnualSettlementFilingObligation,
  type PayrollAnnualSettlementList,
  type PayrollAnnualSettlementListItem,
  type PayrollAnnualSettlementListState,
  type PayrollAnnualSettlementPreview,
  type PayrollAnnualSettlementPriorEmployers,
  type PayrollAnnualSettlementRequestStatus,
  type PayrollDocument,
} from '@/api/payroll'
import { apiErrorMessage } from '@/api/errors'
import PayrollJmhzReturnBar from '@/pages/payroll/PayrollJmhzReturnBar.vue'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { btnFilledSm, btnOutline, btnOutlineSm, disabledTitle, BTN_DISABLED_NOTE, ICONS } from '@/components/ui/buttonStyles'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import PaginationBar from '@/components/ui/PaginationBar.vue'
import SavedFiltersMenu from '@/components/ui/SavedFiltersMenu.vue'
import { useSavedFilters } from '@/composables/useSavedFilters'
import { payrollQueryId } from '@/pages/payroll/payrollAgendaLinks'
import DateInput from '@/components/ui/DateInput.vue'

const { t, locale } = useI18n()
const auth = useAuthStore()
const toast = useToast()

/**
 * Zúčtování se provádí ZA UPLYNULÉ zdaňovací období, takže výchozí rok je
 * loňský — ne letošní, který ještě neskončil.
 */
// Rok z odkazu (hlídač termínů vede rovnou na zúčtování konkrétního roku).
const queryYear = Number(useRoute().query.year)
const hasQueryYear = Number.isInteger(queryYear) && queryYear >= 2000 && queryYear <= 2199
const year = ref(hasQueryYear ? queryYear : new Date().getFullYear() - 1)
/** Rok z odkazu se respektuje; výchozí se jednou srovná na začátek vedení mezd. */
let defaultYearResolved = hasQueryYear
const data = ref<PayrollAnnualSettlementList | null>(null)
const selectedEmployeeId = ref<number | null>(null)
const preview = ref<PayrollAnnualSettlementPreview | null>(null)
const document = ref<PayrollDocument | null>(null)
const loading = ref(true)
const loadFailed = ref(false)
/**
 * Seznam lidí stránkuje SERVER.
 *
 * Why: firma se stovkami zaměstnanců dostala v jedné odpovědi všechny a
 * obrazovka je všechny vykreslila. Zúžení podle jména a stavu proto taky patří
 * na server — kdyby filtroval prohlížeč, hledal by jen v načtené stránce
 * a nenalezení by vypadalo jako „ten člověk tu není".
 *
 * Sloupcové vybavení tu záměrně NENÍ: levý seznam je výběr osoby (`<ul>`),
 * ne datová tabulka, a jediná `<table>` na stránce je pevný dvousloupcový
 * výpočet podle § 38ch — skrýt v něm sloupec znamená skrýt polovinu rovnice.
 */
const pageSize = 25
const total = ref(0)
const offset = ref(0)
const search = ref('')
const state = ref<PayrollAnnualSettlementListState>('all')
const currentPage = computed(() => Math.floor(offset.value / pageSize) + 1)
const previewLoading = ref(false)
const saving = ref(false)
const settling = ref(false)
let loadSequence = 0
/**
 * Osoba z odkazu na kartě zaměstnance. Uplatní se JEDNOU, po prvním načtení
 * seznamu — kdyby se držela trvale, přepínání roku by pořád skákalo zpět na
 * člověka, kterého uživatel dávno opustil.
 */
let pendingFocusPersonId: number | null = payrollQueryId(useRoute().query, 'person')

const form = ref({
  request_status: 'unknown' as PayrollAnnualSettlementRequestStatus,
  requested_on: '',
  request_evidence_reference: '',
  prior_employers: 'unknown' as PayrollAnnualSettlementPriorEmployers,
  prior_documents_received_on: '',
  filing_obligation: 'unknown' as PayrollAnnualSettlementFilingObligation,
  filing_obligation_reason: '',
  annual_claims: 'unknown' as PayrollAnnualSettlementAnnualClaims,
  annual_claims_note: '',
  other_household_caregiver_status: 'unknown' as PayrollAnnualSettlementCaregiverStatus,
  other_household_caregivers: [] as CaregiverForm[],
  note: '',
  row_version: 0,
})

/**
 * Formulářový řádek potvrzení. Částky jsou řetězce v korunách, protože prázdné
 * pole musí zůstat prázdné — kdyby se držely jako čísla, prázdno by spadlo na
 * nulu a nula je podle § 38ch odst. 3 doložený údaj, ne chybějící.
 */
interface CertificateForm {
  certificate_reference: string
  payer_name: string
  payer_tax_identification: string
  received_on: string
  /**
   * Tiskopisové „za období od–do". Prázdno = „nevíme", ne „souběh nebyl":
   * překryv dvou vyplněných období zvedne blokátor `must_file_tax_return`
   * (§ 38g odst. 2), ale historická potvrzení období nenesou, takže se
   * nevyžadují.
   */
  employment_from: string
  employment_to: string
  gross_income: string
  advance_base: string
  advance_tax: string
  credit_35ba: string
  credit_35c: string
  tax_bonus: string
  evidence_status: 'unverified' | 'verified'
  evidence_reference: string
  missing: string[]
}

interface CaregiverForm {
  given_name: string
  family_name: string
  birth_date: string
  months: number[]
}

const certificates = ref<CertificateForm[]>([])
const savingCertificates = ref(false)

/**
 * Ročně uplatňované položky (nezdanitelné části § 15, sleva na manžela,
 * zastavená exekuce). Částky jsou řetězce v korunách ze stejného důvodu jako
 * u potvrzení: prázdné pole nesmí spadnout na nulu.
 */
interface DeductionForm {
  kind: PayrollAnnualDeductionKind
  amount: string
  quantity: string
  months: number[]
  housing_acquired_before_2021: boolean
  counterparty_name: string
  evidence_status: 'unverified' | 'verified'
  evidence_reference: string
  note: string
}

interface SpouseForm {
  spouse_dependant_id: number | null
  child_dependant_id: number | null
  months: number[]
  ztp_p_months: number[]
  spouse_income: string
  evidence_status: 'unverified' | 'verified'
  evidence_reference: string
  note: string
}

/** Sleva za umístění dítěte (§ 35bd ZDP, od roku 2027) — jeden řádek na dítě. */
interface PreschoolForm {
  child_dependant_id: number | null
  facility_kind: PayrollAnnualPreschoolFacilityKind
  facility_name: string
  expenses: string
  households_count: number
  agreed_cap: string
  claimed_by_other_taxpayer: boolean
  claimed_as_business_expense: boolean
  grandchild_care_replaces_parents: boolean
  other_supporting_taxpayer: boolean
  other_taxpayer_name: string
  other_taxpayer_claimed: string
  other_taxpayer_confirmation_reference: string
  agreement_reference: string
  evidence_status: 'unverified' | 'verified'
  evidence_reference: string
  note: string
}

const PRESCHOOL_FACILITY_KINDS: readonly PayrollAnnualPreschoolFacilityKind[] = [
  'kindergarten',
  'children_group',
  'licensed_care',
]
const PRESCHOOL_FAILURE_CODES: readonly string[] = [
  'statute_not_effective',
  'minimum_wage_unavailable',
  'child_relation',
  'child_not_in_household',
  'claimed_by_other_taxpayer',
  'claimed_as_business_expense',
  'agreement_without_shared_care',
  'agreed_cap_over_minimum_wage',
  'agreement_document_missing',
  'other_taxpayer_confirmation_missing',
  'combined_credit_over_minimum_wage',
]

const deductions = ref<DeductionForm[]>([])
const spouse = ref<SpouseForm | null>(null)
const preschool = ref<PreschoolForm[]>([])
const preschoolAvailable = ref(false)
const dependants = ref<PayrollAnnualDependant[]>([])
const newDeductionKind = ref<PayrollAnnualDeductionKind>('donation')
const savingClaims = ref(false)

const DEDUCTION_KINDS: readonly PayrollAnnualDeductionKind[] = [
  'donation',
  'blood_donation',
  'organ_donation',
  'stem_cell_donation',
  'housing_interest',
  'pension_savings',
  'life_insurance',
  'long_term_investment',
  'long_term_care_insurance',
  'enforcement_stop',
]
/**
 * Odběry se oceňují pevnou částkou za odběr (§ 15 odst. 1 ZDP), částku nezadává
 * uživatel. Částky posílá server ze zákonné tabulky ročníku, natvrdo tu nejsou.
 */
const quantityRates = ref<Partial<Record<PayrollAnnualDeductionKind, number>>>({})
const SPOUSE_FAILURE_CODES: readonly string[] = [
  'spouse_relation',
  'child_relation',
  'spouse_income_missing',
  'spouse_income_over_limit',
  'ztp_p_month_outside_claim',
  'ztp_p_not_in_evidence',
  'child_not_under_three',
  'spouse_not_in_evidence',
]

/** Pořadí sloupců odpovídá § 38ch odst. 3 a řádkům tiskopisu 25 5460. */
const CERTIFICATE_AMOUNTS = [
  'gross_income',
  'advance_base',
  'advance_tax',
  'credit_35ba',
  'credit_35c',
  'tax_bonus',
] as const
const MONTHS = Array.from({ length: 12 }, (_, index) => index + 1)

const canWrite = computed(() => auth.canWrite('payroll.documents'))
const canSettle = computed(() => auth.canWrite('payroll.approve'))
/**
 * Zadání potvrzení je pod `payroll.approve`, ne pod `payroll.documents`: ta
 * čísla jdou přímo do úhrnu, ze kterého vychází přeplatek.
 */
const canEditCertificates = canSettle
const items = computed<PayrollAnnualSettlementListItem[]>(() => data.value?.items ?? [])
const result = computed(() => preview.value?.result ?? null)
const blockers = computed(() => result.value?.blockers ?? [])
const nonTaxable = computed(() => result.value?.trace?.non_taxable ?? null)
const spouseCreditFailures = computed<string[]>(() =>
  (result.value?.trace?.spouse_credit_failures ?? [])
    .filter(code => SPOUSE_FAILURE_CODES.includes(code)),
)
const spouseCandidates = computed(() =>
  dependants.value.filter(row => row.relation === 'spouse'),
)
const childCandidates = computed(() =>
  dependants.value.filter(row => row.relation.startsWith('child_')),
)
/** § 35bd odst. 5: vnuk jen v péči nahrazující péči rodičů — o tom rozhoduje příznak. */
const preschoolChildCandidates = computed(() =>
  dependants.value.filter(row => row.relation.startsWith('child_') || row.relation === 'grandchild'),
)
const preschoolCreditFailures = computed<Array<{ child: string, codes: string[] }>>(() => {
  const failures = result.value?.trace?.preschool_credit_failures ?? {}
  return Object.entries(failures).map(([reference, codes]) => {
    const id = Number.parseInt(reference.replace('dependant-', ''), 10)
    const person = dependants.value.find(row => row.id === id)
    return {
      child: person?.full_name ?? reference,
      codes: codes.filter(code => PRESCHOOL_FAILURE_CODES.includes(code)),
    }
  })
})
const selectedSpouseHasZtpP = computed(() =>
  spouseCandidates.value.some(row =>
    row.id === spouse.value?.spouse_dependant_id && row.ztp_p),
)
const performed = computed(() => result.value?.performed === true)
const annualBonusEligibilityReason = computed(() => {
  if (result.value?.annual_bonus_eligibility_reason) {
    return result.value.annual_bonus_eligibility_reason
  }
  return result.value?.annual_bonus_threshold_met
    ? 'eligible'
    : 'income_below_threshold'
})
const annualBonusEligible = computed(() =>
  result.value?.annual_bonus_eligible
    ?? annualBonusEligibilityReason.value === 'eligible',
)

/*
 * Kam se jde překážka doplnit.
 *
 * Seznam pod „Co brání provedení" končil větou typu „Doplňte odpověď" — jenže
 * formulář má nad sebou dvanáct polí a účetní hledala, které z nich to je.
 * U překážek, jejichž pole je na TÉTO obrazovce, je proto položka klikací a
 * pole se otevře a vysvítí. U ostatních (karta zaměstnance, karta dítěte)
 * zůstává věta, protože cíl leží mimo tenhle formulář.
 */
const requestedOnField = ref<HTMLInputElement | null>(null)
const priorDocumentsField = ref<HTMLInputElement | null>(null)
const filingObligationField = ref<HTMLSelectElement | null>(null)
const annualClaimsField = ref<HTMLSelectElement | null>(null)
const caregiverStatusField = ref<HTMLSelectElement | null>(null)

const BLOCKER_FIELDS: Record<string, () => HTMLElement | null> = {
  request_date_missing: () => requestedOnField.value,
  not_requested: () => requestedOnField.value,
  prior_documents_date_missing: () => priorDocumentsField.value,
  prior_employer_documents_missing: () => priorDocumentsField.value,
  filing_obligation_unknown: () => filingObligationField.value,
  must_file_tax_return: () => filingObligationField.value,
  annual_only_claims_unknown: () => annualClaimsField.value,
  annual_only_claims_unsupported: () => annualClaimsField.value,
  annual_claims_inconsistent: () => annualClaimsField.value,
  child_jmhz_evidence_incomplete: () => caregiverStatusField.value,
}

/** Překážky, které se opravují v kartě zaměstnance (evidence dětí). */
const CARD_BLOCKERS: readonly string[] = ['takeover_child_claim_missing']

function blockerHasField(code: string): boolean {
  return BLOCKER_FIELDS[code] !== undefined
}

function focusBlockerField(code: string): void {
  const element = BLOCKER_FIELDS[code]?.() ?? null
  if (element === null) return
  element.scrollIntoView({ behavior: 'smooth', block: 'center' })
  element.focus()
  /*
   * Samotný fokus je na `<select>` v některých prohlížečích sotva vidět —
   * prstenec proto chvíli drží navíc, aby účetní poznala, kam ji odkaz
   * poslal, i když se mezitím podívala jinam.
   */
  element.classList.add('ring-2', 'ring-payroll-500', 'ring-offset-1')
  window.setTimeout(
    () => element.classList.remove('ring-2', 'ring-payroll-500', 'ring-offset-1'),
    2000,
  )
}

/**
 * Proč je „Provést" zašedlé. Věta musí odpovídat TÉŽE podmínce, která tlačítko
 * vypnula — do teď chybějící oprávnění hlásilo „zaměstnanec o zúčtování
 * nepožádal", což je věc, kterou účetní marně hledala v podkladech.
 *
 * Poslední větev pokrývá `performed === false` bez jediné překážky: náhled
 * zúčtování neprovedl, ale důvod neposlal. Bez ní zůstalo tlačítko zašedlé
 * úplně mlčky.
 */
const settleDisabledReason = computed(() => {
  if (!canSettle.value) return t('payroll.annual_settlement.settle_read_only')
  if (preview.value === null) return t('payroll.annual_settlement.select_employee')
  if (blockers.value.length > 0) {
    return t(`payroll.annual_settlement.blocker.${blockers.value[0]}`)
  }
  if (!performed.value) return t('payroll.annual_settlement.settle_unavailable')
  return undefined
})

const actions = computed<ActionItem[]>(() => [
  {
    key: 'settle',
    label: t(settling.value
      ? 'payroll.annual_settlement.settling'
      : 'payroll.annual_settlement.settle'),
    icon: 'checkCircle',
    tier: 'primary',
    variant: 'primary',
    disabled: !canSettle.value
      || preview.value === null
      || !performed.value
      || settling.value,
    disabledReason: settleDisabledReason.value,
    loading: settling.value,
    run: settle,
  },
  {
    key: 'settle-all',
    label: t(bulkEnqueuing.value || bulkOpen.value
      ? 'payroll.annual_settlement.bulk.running'
      : 'payroll.annual_settlement.bulk.action'),
    icon: 'play',
    tier: 'primary',
    variant: 'success',
    disabled: !canSettle.value || bulkEnqueuing.value || bulkOpen.value,
    disabledReason: !canSettle.value
      ? t('payroll.annual_settlement.settle_read_only')
      : bulkOpen.value
        ? t('payroll.annual_settlement.bulk.running_hint')
        : undefined,
    loading: bulkEnqueuing.value || bulkOpen.value,
    run: settleAllRequesters,
  },
  {
    key: 'reload',
    label: t('payroll.annual_settlement.reload'),
    icon: 'cycle',
    tier: 'secondary',
    variant: 'neutral',
    disabled: loading.value,
    run: () => void load(),
  },
])

function money(minorUnits: number | null | undefined): string {
  const value = (minorUnits ?? 0) / 100
  return new Intl.NumberFormat(locale.value, {
    style: 'currency',
    currency: 'CZK',
    minimumFractionDigits: 2,
  }).format(value)
}

function formatDate(value: string | null): string {
  if (!value) return '—'
  const date = new Date(`${value}T00:00:00`)
  return Number.isNaN(date.getTime())
    ? value
    : new Intl.DateTimeFormat(locale.value, { dateStyle: 'medium' }).format(date)
}

function formatMonth(value: string | undefined): string {
  if (!value) return '—'
  const date = new Date(`${value}-01T00:00:00`)
  return Number.isNaN(date.getTime())
    ? value
    : new Intl.DateTimeFormat(locale.value, { month: 'long', year: 'numeric' }).format(date)
}

function monthLabel(month: number): string {
  return new Intl.DateTimeFormat(locale.value, { month: 'short' }).format(
    new Date(2024, month - 1, 1),
  )
}

function maskToMonths(mask: string): number[] {
  return [...mask].flatMap((value, index) => value === 'A' ? [index + 1] : [])
}

function monthsToMask(months: number[]): string {
  const selected = new Set(months)
  return MONTHS.map(month => selected.has(month) ? 'A' : 'N').join('')
}

function addCaregiver(): void {
  form.value.other_household_caregivers = [
    ...form.value.other_household_caregivers,
    { given_name: '', family_name: '', birth_date: '', months: [] },
  ]
}

function removeCaregiver(index: number): void {
  form.value.other_household_caregivers = form.value.other_household_caregivers
    .filter((_, position) => position !== index)
}

function stateLabel(item: PayrollAnnualSettlementListItem): string {
  return t(
    `payroll.annual_settlement.request_status_options.${item.request_status ?? 'unknown'}`,
  )
}

async function load(): Promise<void> {
  const sequence = ++loadSequence
  loading.value = true
  try {
    const response = await payrollApi.listAnnualSettlements(
      year.value,
      { limit: pageSize, offset: offset.value },
      { search: search.value.trim(), state: state.value },
    )
    if (sequence !== loadSequence) return
    // Výchozí rok nesmí ležet před začátkem vedení mezd v MyÚčtu (Q8-43):
    // firma převedená z jiného programu jinak viděla roční zúčtování roku,
    // který zpracoval předchozí program, a u všech lidí „Nezpracováno".
    if (!defaultYearResolved) {
      defaultYearResolved = true
      const startYear = Number((response.payroll_start_period ?? '').slice(0, 4))
      if (Number.isInteger(startYear) && startYear > year.value) {
        year.value = startYear
        return
      }
    }
    data.value = response
    total.value = response.total
    loadFailed.value = false
    // Odkaz z karty zaměstnance (`?person=7`) rozklikne rovnou jeho evidenci.
    // Se stránkováním se členství v seznamu neověřuje — člověk může ležet na
    // jiné straně a zamlčený odkaz by vypadal jako rozbité tlačítko. Neplatné
    // id skončí na hlášce z náhledu, ne mlčením.
    if (pendingFocusPersonId !== null) {
      const focused = pendingFocusPersonId
      pendingFocusPersonId = null
      void select(focused)
    }
  } catch (error) {
    if (sequence !== loadSequence) return
    // Poslední úspěšně načtená data zůstávají — prázdno by tvrdilo,
    // že za rok nikdo nepožádal, což o selhaném načtení nevíme.
    loadFailed.value = true
    toast.error(apiErrorMessage(error, t('payroll.annual_settlement.load_failed')))
  } finally {
    if (sequence === loadSequence) loading.value = false
  }
}

async function select(employeeId: number): Promise<void> {
  selectedEmployeeId.value = employeeId
  document.value = null
  previewLoading.value = true
  try {
    const response = await payrollApi.previewAnnualSettlement(year.value, employeeId)
    preview.value = response
    form.value = {
      request_status: response.request.request_status,
      requested_on: response.request.requested_on ?? '',
      request_evidence_reference: response.request.request_evidence_reference ?? '',
      prior_employers: response.request.prior_employers,
      prior_documents_received_on: response.request.prior_documents_received_on ?? '',
      filing_obligation: response.request.filing_obligation,
      filing_obligation_reason: response.request.filing_obligation_reason ?? '',
      annual_claims: response.request.annual_claims,
      annual_claims_note: response.request.annual_claims_note ?? '',
      other_household_caregiver_status:
        response.request.other_household_caregiver_status ?? 'unknown',
      other_household_caregivers: (response.request.other_household_caregivers ?? []).map(row => ({
        given_name: row.given_name,
        family_name: row.family_name,
        birth_date: row.birth_date,
        months: maskToMonths(row.months_mask),
      })),
      note: response.request.note ?? '',
      row_version: response.request.row_version,
    }
    // `?? []` schválně: chybějící seznam nesmí shodit celou obrazovku, protože
    // pak by uživatel neviděl ani výpočet, ani překážky.
    certificates.value = (response.certificates ?? []).map(certificateForm)
    applyClaims(response.annual_claims)
  } catch (error) {
    preview.value = null
    certificates.value = []
    applyClaims(undefined)
    toast.error(apiErrorMessage(error, t('payroll.annual_settlement.preview_failed')))
  } finally {
    previewLoading.value = false
  }
}

function maskBitsToMonths(mask: number | null | undefined): number[] {
  const value = mask ?? 0
  return MONTHS.filter(month => (value & (1 << (month - 1))) !== 0)
}

function monthsToMaskBits(months: number[]): number {
  return months.reduce((mask, month) => mask | (1 << (month - 1)), 0)
}

function deductionForm(row: PayrollAnnualClaimsSet['deductions'][number]): DeductionForm {
  return {
    kind: row.kind,
    amount: amountToInput(row.amount_minor_units ?? null),
    quantity: row.quantity === null || row.quantity === undefined ? '' : String(row.quantity),
    months: maskBitsToMonths(row.months_mask),
    housing_acquired_before_2021: row.housing_acquired_before_2021 === true,
    counterparty_name: row.counterparty_name ?? '',
    evidence_status: row.evidence_status,
    evidence_reference: row.evidence_reference ?? '',
    note: row.note ?? '',
  }
}

function spouseForm(row: NonNullable<PayrollAnnualClaimsSet['spouse']>): SpouseForm {
  return {
    spouse_dependant_id: row.spouse_dependant_id,
    child_dependant_id: row.child_dependant_id,
    months: maskBitsToMonths(row.months_mask),
    ztp_p_months: maskBitsToMonths(row.ztp_p_months_mask),
    spouse_income: amountToInput(row.spouse_income_minor_units ?? null),
    evidence_status: row.evidence_status,
    evidence_reference: row.evidence_reference ?? '',
    note: row.note ?? '',
  }
}

function preschoolForm(row: PayrollAnnualClaimsSet['preschool'][number]): PreschoolForm {
  return {
    child_dependant_id: row.child_dependant_id,
    facility_kind: row.facility_kind,
    facility_name: row.facility_name ?? '',
    expenses: amountToInput(row.expenses_minor_units),
    households_count: row.households_count,
    agreed_cap: amountToInput(row.agreed_cap_minor_units ?? null),
    claimed_by_other_taxpayer: row.claimed_by_other_taxpayer,
    claimed_as_business_expense: row.claimed_as_business_expense,
    grandchild_care_replaces_parents: row.grandchild_care_replaces_parents,
    other_supporting_taxpayer: row.other_supporting_taxpayer,
    other_taxpayer_name: row.other_taxpayer_name ?? '',
    other_taxpayer_claimed: amountToInput(row.other_taxpayer_claimed_minor_units ?? null),
    other_taxpayer_confirmation_reference: row.other_taxpayer_confirmation_reference ?? '',
    agreement_reference: row.agreement_reference ?? '',
    evidence_status: row.evidence_status,
    evidence_reference: row.evidence_reference ?? '',
    note: row.note ?? '',
  }
}

/** § 38l odst. 2 písm. g) bod 2: totéž dítě vyživuje další poplatník. */
function preschoolNeedsOtherConfirmation(row: PreschoolForm): boolean {
  return row.households_count > 1 || row.other_supporting_taxpayer
}

function addPreschool(): void {
  const used = new Set(preschool.value.map(row => row.child_dependant_id))
  preschool.value = [...preschool.value, {
    child_dependant_id: preschoolChildCandidates.value.find(row => !used.has(row.id))?.id ?? null,
    facility_kind: 'kindergarten',
    facility_name: '',
    expenses: '',
    households_count: 1,
    agreed_cap: '',
    claimed_by_other_taxpayer: false,
    claimed_as_business_expense: false,
    grandchild_care_replaces_parents: false,
    other_supporting_taxpayer: false,
    other_taxpayer_name: '',
    other_taxpayer_claimed: '',
    other_taxpayer_confirmation_reference: '',
    agreement_reference: '',
    evidence_status: 'unverified',
    evidence_reference: '',
    note: '',
  }]
}

function removePreschool(index: number): void {
  preschool.value = preschool.value.filter((_, position) => position !== index)
}

function isGrandchild(id: number | null): boolean {
  return dependants.value.some(row => row.id === id && row.relation === 'grandchild')
}

/** `?? []` schválně: chybějící blok nesmí shodit obrazovku (viz potvrzení). */
function applyClaims(
  claims: (Omit<PayrollAnnualClaimsSet, 'preschool'> & Partial<Pick<PayrollAnnualClaimsSet, 'preschool'>>
    & Partial<Pick<PayrollAnnualClaimsView, 'dependants' | 'statute' | 'preschool_available'>>) | undefined,
): void {
  deductions.value = (claims?.deductions ?? []).map(deductionForm)
  spouse.value = claims?.spouse ? spouseForm(claims.spouse) : null
  preschool.value = (claims?.preschool ?? []).map(preschoolForm)
  if (claims?.preschool_available !== undefined) {
    preschoolAvailable.value = claims.preschool_available
  }
  dependants.value = claims?.dependants ?? []
  if (claims?.statute) {
    quantityRates.value = claims.statute.counted_donation_values
  }
}

function addDeduction(): void {
  deductions.value = [...deductions.value, {
    kind: newDeductionKind.value,
    amount: '',
    quantity: '',
    months: [],
    housing_acquired_before_2021: false,
    counterparty_name: '',
    evidence_status: 'unverified',
    evidence_reference: '',
    note: '',
  }]
}

function removeDeduction(index: number): void {
  deductions.value = deductions.value.filter((_, position) => position !== index)
}

function toggleMonth(list: number[], month: number): number[] {
  return list.includes(month) ? list.filter(value => value !== month) : [...list, month]
}

function addSpouse(): void {
  spouse.value = {
    spouse_dependant_id: spouseCandidates.value[0]?.id ?? null,
    child_dependant_id: childCandidates.value[0]?.id ?? null,
    months: [],
    ztp_p_months: [],
    spouse_income: '',
    evidence_status: 'unverified',
    evidence_reference: '',
    note: '',
  }
}

function quantityValue(row: DeductionForm): number | null {
  const parsed = Number.parseInt(row.quantity.trim(), 10)
  return Number.isFinite(parsed) && parsed >= 0 ? parsed : null
}

function quantityEvaluation(row: DeductionForm): number {
  return (quantityValue(row) ?? 0) * (quantityRates.value[row.kind] ?? 0)
}

/** Sestaví tělo PUT. Částka u odběrů, měsíce mimo úroky a id se neposílají. */
function buildClaimsPayload(): PayrollAnnualClaimsSet {
  const claim = spouse.value
  return {
    deductions: deductions.value.map((row) => {
      const isQuantity = quantityRates.value[row.kind] !== undefined
      const isHousing = row.kind === 'housing_interest'
      return {
        kind: row.kind,
        amount_minor_units: isQuantity ? null : inputToAmount(row.amount),
        quantity: isQuantity ? quantityValue(row) : null,
        months_mask: isHousing ? monthsToMaskBits(row.months) : null,
        housing_acquired_before_2021: isHousing && row.housing_acquired_before_2021,
        counterparty_name: row.counterparty_name.trim() || null,
        evidence_status: row.evidence_status,
        evidence_reference: row.evidence_reference.trim() || null,
        note: row.note.trim() || null,
      }
    }),
    spouse: claim === null || claim.spouse_dependant_id === null || claim.child_dependant_id === null
      ? null
      : {
          spouse_dependant_id: claim.spouse_dependant_id,
          child_dependant_id: claim.child_dependant_id,
          months_mask: monthsToMaskBits(claim.months),
          ztp_p_months_mask: selectedSpouseHasZtpP.value ? monthsToMaskBits(claim.ztp_p_months) : 0,
          spouse_income_minor_units: inputToAmount(claim.spouse_income),
          evidence_status: claim.evidence_status,
          evidence_reference: claim.evidence_reference.trim() || null,
          note: claim.note.trim() || null,
        },
    preschool: preschool.value
      .filter(row => row.child_dependant_id !== null)
      .map(row => ({
        child_dependant_id: row.child_dependant_id as number,
        facility_kind: row.facility_kind,
        facility_name: row.facility_name.trim() || null,
        expenses_minor_units: inputToAmount(row.expenses) ?? 0,
        households_count: row.households_count,
        agreed_cap_minor_units: row.households_count > 1 ? inputToAmount(row.agreed_cap) : null,
        claimed_by_other_taxpayer: row.claimed_by_other_taxpayer,
        claimed_as_business_expense: row.claimed_as_business_expense,
        grandchild_care_replaces_parents: isGrandchild(row.child_dependant_id)
          && row.grandchild_care_replaces_parents,
        other_supporting_taxpayer: row.other_supporting_taxpayer,
        other_taxpayer_name: preschoolNeedsOtherConfirmation(row)
          ? row.other_taxpayer_name.trim() || null
          : null,
        other_taxpayer_claimed_minor_units: preschoolNeedsOtherConfirmation(row)
          ? inputToAmount(row.other_taxpayer_claimed)
          : null,
        other_taxpayer_confirmation_reference: preschoolNeedsOtherConfirmation(row)
          ? row.other_taxpayer_confirmation_reference.trim() || null
          : null,
        agreement_reference: row.households_count > 1 && row.agreed_cap.trim() !== ''
          ? row.agreement_reference.trim() || null
          : null,
        evidence_status: row.evidence_status,
        evidence_reference: row.evidence_reference.trim() || null,
        note: row.note.trim() || null,
      })),
  }
}

/** Ukládá se CELÁ sada za rok, stejně jako potvrzení. */
async function saveClaims(): Promise<void> {
  if (selectedEmployeeId.value === null || savingClaims.value) return
  savingClaims.value = true
  try {
    const saved = await payrollApi.saveAnnualSettlementAnnualClaims(
      year.value,
      selectedEmployeeId.value,
      buildClaimsPayload(),
    )
    applyClaims({ ...saved, dependants: dependants.value })
    toast.success(t('payroll.annual_settlement.claims.saved'))
    // Přepočet — položky mění základ daně i slevy.
    await select(selectedEmployeeId.value)
  } catch (error) {
    toast.error(apiErrorMessage(error, t('payroll.annual_settlement.claims.save_failed')))
  } finally {
    savingClaims.value = false
  }
}

/** Minor units → koruny jako text. `null` zůstává prázdné pole. */
function amountToInput(value: number | null): string {
  return value === null ? '' : (value / 100).toFixed(2)
}

/**
 * Koruny jako text → minor units. Prázdné pole je `null`, ne nula — a to je
 * celý rozdíl mezi „na potvrzení to není" a „na potvrzení je nula".
 */
function inputToAmount(value: string): number | null {
  const trimmed = value.trim().replace(',', '.')
  if (trimmed === '') return null
  const parsed = Number(trimmed)
  return Number.isFinite(parsed) ? Math.round(parsed * 100) : null
}

function certificateForm(certificate: PayrollAnnualSettlementCertificate): CertificateForm {
  return {
    certificate_reference: certificate.certificate_reference,
    payer_name: certificate.payer_name ?? '',
    payer_tax_identification: certificate.payer_tax_identification ?? '',
    received_on: certificate.received_on ?? '',
    employment_from: certificate.employment_from ?? '',
    employment_to: certificate.employment_to ?? '',
    gross_income: amountToInput(certificate.gross_income_minor_units),
    advance_base: amountToInput(certificate.advance_base_minor_units),
    advance_tax: amountToInput(certificate.advance_tax_minor_units),
    credit_35ba: amountToInput(certificate.non_refundable_credit_minor_units),
    credit_35c: amountToInput(certificate.child_credit_minor_units),
    tax_bonus: amountToInput(certificate.tax_bonus_minor_units),
    evidence_status: certificate.evidence_status,
    evidence_reference: certificate.evidence_reference ?? '',
    missing: certificate.missing_statutory_fields,
  }
}

function addCertificate(): void {
  certificates.value = [...certificates.value, {
    certificate_reference: '',
    payer_name: '',
    payer_tax_identification: '',
    received_on: '',
    employment_from: '',
    employment_to: '',
    gross_income: '',
    advance_base: '',
    advance_tax: '',
    credit_35ba: '',
    credit_35c: '',
    tax_bonus: '',
    evidence_status: 'unverified',
    evidence_reference: '',
    missing: [...CERTIFICATE_AMOUNTS],
  }]
}

function removeCertificate(index: number): void {
  certificates.value = certificates.value.filter((_, position) => position !== index)
}

/**
 * Ukládá se CELÝ seznam, ne jednotlivý řádek. Doklady dávají smysl jen jako
 * úplná sada od všech předchozích plátců (§ 38ch odst. 3), takže i tlačítko je
 * jedno pro celou sekci.
 */
async function saveCertificates(): Promise<void> {
  if (selectedEmployeeId.value === null || savingCertificates.value) return
  savingCertificates.value = true
  try {
    const saved = await payrollApi.saveAnnualSettlementCertificates(
      year.value,
      selectedEmployeeId.value,
      certificates.value.map(row => ({
        certificate_reference: row.certificate_reference.trim(),
        payer_name: row.payer_name.trim() || null,
        payer_tax_identification: row.payer_tax_identification.trim() || null,
        received_on: row.received_on || null,
        // Prázdné období jde na server jako `null` — „nevíme". Nula ani
        // dopočtený rok tu nedávají smysl: souběh se pak jen neprokáže.
        employment_from: row.employment_from || null,
        employment_to: row.employment_to || null,
        gross_income_minor_units: inputToAmount(row.gross_income),
        advance_base_minor_units: inputToAmount(row.advance_base),
        advance_tax_minor_units: inputToAmount(row.advance_tax),
        non_refundable_credit_minor_units: inputToAmount(row.credit_35ba),
        child_credit_minor_units: inputToAmount(row.credit_35c),
        tax_bonus_minor_units: inputToAmount(row.tax_bonus),
        evidence_status: row.evidence_status,
        evidence_reference: row.evidence_reference.trim() || null,
      })),
    )
    certificates.value = saved.map(certificateForm)
    toast.success(t('payroll.annual_settlement.certificates_saved'))
    // Přepočet — potvrzení mění úhrn, takže i výsledek na obrazovce.
    await select(selectedEmployeeId.value)
  } catch (error) {
    toast.error(apiErrorMessage(error, t('payroll.annual_settlement.certificates_save_failed')))
  } finally {
    savingCertificates.value = false
  }
}

async function saveRequest(): Promise<void> {
  if (selectedEmployeeId.value === null || saving.value) return
  saving.value = true
  try {
    await payrollApi.saveAnnualSettlementRequest(year.value, selectedEmployeeId.value, {
      request_status: form.value.request_status,
      requested_on: form.value.requested_on || null,
      request_evidence_reference: form.value.request_evidence_reference || null,
      prior_employers: form.value.prior_employers,
      prior_documents_received_on: form.value.prior_documents_received_on || null,
      filing_obligation: form.value.filing_obligation,
      filing_obligation_reason: form.value.filing_obligation_reason || null,
      annual_claims: form.value.annual_claims,
      annual_claims_note: form.value.annual_claims_note || null,
      other_household_caregiver_status: form.value.other_household_caregiver_status,
      other_household_caregivers: form.value.other_household_caregiver_status === 'present'
        ? form.value.other_household_caregivers.map(row => ({
            given_name: row.given_name.trim(),
            family_name: row.family_name.trim(),
            birth_date: row.birth_date,
            months_mask: monthsToMask(row.months),
          }))
        : [],
      note: form.value.note || null,
      ...(form.value.row_version > 0 ? { row_version: form.value.row_version } : {}),
    })
    toast.success(t('payroll.annual_settlement.request_saved'))
    await load()
    await select(selectedEmployeeId.value)
  } catch (error) {
    toast.error(apiErrorMessage(error, t('payroll.annual_settlement.request_save_failed')))
  } finally {
    saving.value = false
  }
}

async function settle(): Promise<void> {
  if (selectedEmployeeId.value === null || settling.value) return
  settling.value = true
  try {
    const response = await payrollApi.settleAnnualSettlement(
      year.value,
      selectedEmployeeId.value,
    )
    if (!response.performed) {
      // Není to chyba serveru — jsou to nesplněné podmínky § 38ch.
      preview.value = preview.value === null
        ? preview.value
        : { ...preview.value, result: response.result }
      toast.warning(t('payroll.annual_settlement.settle_refused'))
      return
    }
    toast.success(t('payroll.annual_settlement.settled'))
    await load()
    await select(selectedEmployeeId.value)
    // Až po znovunačtení: `select()` doklad schválně nuluje (jiný člověk =
    // jiný doklad), takže přiřazení před ním by odkaz na stažení zase smazalo.
    document.value = response.document ?? null
  } catch (error) {
    toast.error(apiErrorMessage(error, t('payroll.annual_settlement.settle_failed')))
  } finally {
    settling.value = false
  }
}

/**
 * Hromadné nastavení stavu žádosti (typicky „nepožádal" u stovek brigádníků,
 * u kterých měsíční JMHZ potřebuje odpověď). Výběr drží ID napříč stránkami;
 * mění se jen stav žádosti, ostatní evidence zůstává.
 */
const bulkSelected = ref<Set<number>>(new Set())
const bulkRequestSaving = ref(false)
const allOnPageSelected = computed(() =>
  items.value.length > 0 && items.value.every(item => bulkSelected.value.has(item.employee_id)))

function toggleBulkPerson(employeeId: number): void {
  const next = new Set(bulkSelected.value)
  if (!next.delete(employeeId)) next.add(employeeId)
  bulkSelected.value = next
}

function toggleBulkPage(): void {
  const next = new Set(bulkSelected.value)
  const everySelected = allOnPageSelected.value
  for (const item of items.value) {
    if (everySelected) next.delete(item.employee_id)
    else next.add(item.employee_id)
  }
  bulkSelected.value = next
}

async function bulkSetRequestStatus(status: 'requested' | 'not_requested'): Promise<void> {
  if (bulkRequestSaving.value || bulkSelected.value.size === 0) return
  const count = bulkSelected.value.size
  const label = t(`payroll.annual_settlement.request_status_options.${status}`)
  if (!window.confirm(t('payroll.annual_settlement.bulk_request.confirm', {
    count,
    status: label,
    year: year.value,
  }))) return
  bulkRequestSaving.value = true
  try {
    const response = await payrollApi.bulkSetAnnualSettlementRequestStatus(
      year.value,
      [...bulkSelected.value],
      status,
    )
    toast.success(t('payroll.annual_settlement.bulk_request.done', {
      created: response.created,
      updated: response.updated,
    }))
    bulkSelected.value = new Set()
    await load()
    if (selectedEmployeeId.value !== null) await select(selectedEmployeeId.value)
  } catch (error) {
    toast.error(apiErrorMessage(error, t('payroll.annual_settlement.bulk_request.failed')))
  } finally {
    bulkRequestSaving.value = false
  }
}

/**
 * Hromadné roční zúčtování všech žadatelů.
 *
 * Nejde po jednom z prohlížeče, ale přes serverovou frontu ročních dokumentů:
 * firma se stovkami žadatelů by jinak klikala stokrát nebo čekala na stovky
 * požadavků, které by zavřením záložky skončily v půlce. Prohlížeč jen sleduje
 * průběh. Kdo podmínky nesplňuje, se přeskočí s výčtem překážek; není to
 * selhání a opakovat se nemá smysl, dokud se podklady nedoplní.
 */
const bulkBatch = ref<PayrollAnnualDocumentBatch | null>(null)
const bulkItems = ref<PayrollAnnualDocumentBatchItem[]>([])
const bulkEnqueuing = ref(false)
const bulkRetryingItemId = ref<number | null>(null)
let bulkPollTimer: ReturnType<typeof setTimeout> | null = null

const bulkOpen = computed(() =>
  bulkBatch.value !== null && bulkBatch.value.status !== 'completed')
const bulkDone = computed(() => {
  const batch = bulkBatch.value
  return batch === null ? 0 : batch.succeeded_count + batch.failed_count + batch.skipped_count
})
const bulkTotal = computed(() => bulkBatch.value?.item_count ?? 0)
const bulkBlocked = computed(() =>
  bulkItems.value
    .filter(item => item.status === 'skipped' && item.last_error_code === 'annual_settlement_blocked')
    .map(item => ({
      id: item.id,
      employeeId: item.employee_id,
      name: item.employee_name,
      reasons: bulkBlockerReasons(item.last_error_message),
    })))
const bulkAlreadySettled = computed(() =>
  bulkItems.value
    .filter(item => item.status === 'skipped' && item.last_error_code !== 'annual_settlement_blocked')
    .map(item => item.employee_name))
const bulkFailures = computed(() =>
  bulkItems.value
    .filter(item => item.status === 'failed' || item.status === 'retry_wait')
    .map(item => ({
      id: item.id,
      name: item.employee_name,
      technical: [item.last_error_code, item.last_error_message].filter(Boolean).join(': '),
    })))

/**
 * Server posílá kódy překážek za dvojtečkou (stejné jako v detailu osoby);
 * přeloží se týmiž větami. Nečitelnou zprávu ukážeme tak, jak přišla.
 */
function bulkBlockerReasons(message: string | null): string[] {
  if (!message) return []
  const separator = message.lastIndexOf(': ')
  const codes = separator < 0
    ? []
    : message.slice(separator + 2).split(',').map(code => code.trim())
  if (codes.length === 0 || codes.some(code => !/^[a-z_]+$/.test(code))) return [message]
  return codes.map(code => t(`payroll.annual_settlement.blocker.${code}`))
}

function clearBulkPoll(): void {
  if (bulkPollTimer !== null) clearTimeout(bulkPollTimer)
  bulkPollTimer = null
}

function dismissBulk(): void {
  clearBulkPoll()
  bulkBatch.value = null
  bulkItems.value = []
}

async function loadBulkItems(batchId: number): Promise<void> {
  const loaded: PayrollAnnualDocumentBatchItem[] = []
  let nextOffset = 0
  let itemTotal = 1
  while (nextOffset < itemTotal) {
    const page = await payrollApi.annualDocumentBatchItems(batchId, {
      limit: 100,
      offset: nextOffset,
    })
    loaded.push(...page.items)
    itemTotal = page.total
    nextOffset += page.items.length
    if (page.items.length === 0) break
  }
  if (bulkBatch.value?.id === batchId) bulkItems.value = loaded
}

async function pollBulk(loadItems = false): Promise<void> {
  const batchId = bulkBatch.value?.id
  if (!batchId) return
  clearBulkPoll()
  try {
    const previous = bulkBatch.value
    const current = await payrollApi.annualDocumentBatch(batchId)
    if (bulkBatch.value?.id !== batchId) return
    bulkBatch.value = current
    const changed = previous === null
      || previous.status !== current.status
      || previous.succeeded_count !== current.succeeded_count
      || previous.failed_count !== current.failed_count
      || previous.skipped_count !== current.skipped_count
    if (loadItems || changed) await loadBulkItems(batchId)
    if (current.status === 'completed') {
      await load()
      if (selectedEmployeeId.value !== null) await select(selectedEmployeeId.value)
      return
    }
    bulkPollTimer = setTimeout(() => void pollBulk(), 2500)
  } catch (error) {
    toast.error(apiErrorMessage(error, t('payroll.annual_settlement.bulk.poll_failed')))
  }
}

async function settleAllRequesters(): Promise<void> {
  if (bulkEnqueuing.value || bulkOpen.value) return
  if (!window.confirm(t('payroll.annual_settlement.bulk.confirm', { year: year.value }))) return
  clearBulkPoll()
  bulkItems.value = []
  bulkEnqueuing.value = true
  try {
    bulkBatch.value = await payrollApi.enqueueAnnualDocumentBatch(
      'annual_settlement_result',
      year.value,
      'all',
      null,
    )
    toast.success(t('payroll.annual_settlement.bulk.queued', {
      count: bulkBatch.value.item_count,
    }))
    await pollBulk(true)
  } catch (error) {
    bulkBatch.value = null
    toast.error(apiErrorMessage(error, t('payroll.annual_settlement.bulk.enqueue_failed')))
  } finally {
    bulkEnqueuing.value = false
  }
}

async function retryBulkItem(itemId: number): Promise<void> {
  const batchId = bulkBatch.value?.id
  if (!batchId || bulkRetryingItemId.value !== null) return
  bulkRetryingItemId.value = itemId
  try {
    await payrollApi.retryAnnualDocumentBatchItem(batchId, itemId)
    toast.success(t('payroll.annual_settlement.bulk.retry_queued'))
    await pollBulk(true)
  } catch (error) {
    toast.error(apiErrorMessage(error, t('payroll.annual_settlement.bulk.retry_failed')))
  } finally {
    bulkRetryingItemId.value = null
  }
}

onBeforeUnmount(clearBulkPoll)

async function download(): Promise<void> {
  if (document.value === null) return
  try {
    await payrollApi.downloadDocument(document.value)
  } catch (error) {
    toast.error(apiErrorMessage(error, t('payroll.documents.download_failed')))
  }
}

watch(year, () => {
  selectedEmployeeId.value = null
  preview.value = null
  certificates.value = []
  document.value = null
  offset.value = 0
  bulkSelected.value = new Set()
  dismissBulk()
  void load()
})

// Změna zúžení mění obsah seznamu, takže stránka musí na začátek — jinak by
// uživatel po zúžení skončil na prázdné páté straně.
function applyFilters(): void {
  offset.value = 0
  void load()
}

function goToPage(nextPage: number): void {
  offset.value = Math.max(0, (nextPage - 1) * pageSize)
  void load()
}

/**
 * Uložené pohledy. Rok do payloadu NEPATŘÍ — pohled „požádali a nemají
 * zúčtováno" má platit pro rok, který má uživatel otevřený, ne odskočit
 * o rok zpět pokaždé, když ho použije v dalším období.
 */
function buildQuery(): Record<string, string> {
  const query: Record<string, string> = {}
  if (search.value.trim() !== '') query.search = search.value.trim()
  if (state.value !== 'all') query.state = state.value
  return query
}

function applyQueryToPage(query: Record<string, string>): void {
  search.value = query.search ?? ''
  state.value = (query.state as PayrollAnnualSettlementListState | undefined) ?? 'all'
  applyFilters()
}

const saved = useSavedFilters('payroll-annual-settlement', {
  getQuery: buildQuery,
  applyQuery: applyQueryToPage,
})

onMounted(async () => {
  if (await saved.applyDefaultIfAny()) return
  await load()
})
</script>

<template>
  <div class="space-y-6">
    <PayrollJmhzReturnBar />
    <header class="flex flex-wrap items-start justify-between gap-4">
      <div>
        <h1 class="text-2xl font-semibold text-neutral-900">
          {{ t('payroll.annual_settlement.title') }}
        </h1>
        <p class="mt-1 max-w-3xl text-sm text-neutral-500">
          {{ t('payroll.annual_settlement.subtitle') }}
        </p>
      </div>
      <div class="flex flex-wrap items-end gap-2">
        <label class="block">
          <span class="mb-1 block text-xs font-medium text-neutral-600">
            {{ t('payroll.annual_settlement.year') }}
          </span>
          <input
            v-model.number="year"
            type="number"
            min="2000"
            max="2199"
            data-test="annual-settlement-year"
            class="h-9 w-28 rounded-md border border-neutral-300 bg-surface px-3 text-sm text-neutral-900 focus:border-payroll-500 focus:ring-payroll-500/20"
          >
        </label>
      </div>
    </header>

    <ActionBar :actions="actions" />

    <!--
      Průběh hromadného zúčtování. Zdrojem je serverová fronta, takže „zavřít"
      jen schová zprávu; dávka na serveru doběhne.
    -->
    <section
      v-if="bulkBatch"
      data-test="annual-settlement-bulk-report"
      class="rounded-lg border p-4"
      :class="bulkBatch.status === 'completed'
        ? (bulkBatch.failed_count > 0
          ? 'border-danger-500/30 bg-danger-50'
          : 'border-success-500/30 bg-success-50')
        : 'border-payroll-500/30 bg-payroll-50'"
      role="status"
    >
      <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
          <p class="text-sm font-medium text-neutral-900">
            {{ t('payroll.annual_settlement.bulk.progress', { done: bulkDone, total: bulkTotal }) }}
          </p>
          <p class="mt-1 text-xs text-neutral-600">
            {{ t('payroll.annual_settlement.bulk.summary', {
              succeeded: bulkBatch.succeeded_count,
              skipped: bulkBatch.skipped_count,
              failed: bulkBatch.failed_count,
            }) }}
            <template v-if="bulkOpen">
              · {{ t('payroll.annual_settlement.bulk.server_hint') }}
            </template>
          </p>
        </div>
        <button
          type="button"
          data-test="annual-settlement-bulk-dismiss"
          :class="[btnOutline('neutral'), 'whitespace-nowrap']"
          @click="dismissBulk"
        >
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path :d="ICONS.x" />
          </svg>
          {{ t('payroll.annual_settlement.bulk.dismiss') }}
        </button>
      </div>
      <div
        class="mt-3 h-2 overflow-hidden rounded-full bg-neutral-200"
        role="progressbar"
        :aria-valuemin="0"
        :aria-valuemax="bulkTotal"
        :aria-valuenow="bulkDone"
      >
        <div
          class="h-full bg-payroll-500 transition-all"
          :style="{ width: `${bulkTotal ? Math.round(bulkDone * 100 / bulkTotal) : 0}%` }"
        />
      </div>
      <div v-if="bulkBlocked.length" class="mt-3 text-sm text-neutral-700" data-test="annual-settlement-bulk-blocked">
        <p class="font-medium">{{ t('payroll.annual_settlement.bulk.blocked_title', { count: bulkBlocked.length }) }}</p>
        <ul class="mt-1 space-y-1">
          <li v-for="row in bulkBlocked" :key="row.id">
            <button
              type="button"
              class="font-medium text-payroll-700 hover:underline"
              @click="select(row.employeeId)"
            >
              {{ row.name }}
            </button>
            <span class="text-neutral-600">: {{ row.reasons.join(' ') }}</span>
          </li>
        </ul>
        <p class="mt-1 text-xs text-neutral-500">{{ t('payroll.annual_settlement.bulk.blocked_hint') }}</p>
      </div>
      <div v-if="bulkAlreadySettled.length" class="mt-3 text-sm text-neutral-700" data-test="annual-settlement-bulk-settled">
        <p class="font-medium">{{ t('payroll.annual_settlement.bulk.settled_title', { count: bulkAlreadySettled.length }) }}</p>
        <p class="mt-1 leading-snug">{{ bulkAlreadySettled.join(', ') }}</p>
      </div>
      <div v-if="bulkFailures.length" class="mt-3 text-sm text-danger-700" data-test="annual-settlement-bulk-failures">
        <p class="font-medium">{{ t('payroll.annual_settlement.bulk.failed_title', { count: bulkFailures.length }) }}</p>
        <ul class="mt-1 space-y-1">
          <li v-for="row in bulkFailures" :key="row.id" class="flex flex-wrap items-center justify-between gap-2">
            <div class="min-w-0">
              <p class="font-medium">{{ row.name }}</p>
              <details v-if="row.technical" class="mt-1 text-xs">
                <summary class="cursor-pointer">{{ t('payroll.documents.worker_failure_technical') }}</summary>
                <p class="mt-1 break-words font-mono">{{ row.technical }}</p>
              </details>
            </div>
            <button
              type="button"
              data-test="annual-settlement-bulk-retry"
              :class="[btnOutline('warning'), 'whitespace-nowrap']"
              :disabled="bulkRetryingItemId !== null || !canSettle"
              @click="retryBulkItem(row.id)"
            >
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path :d="ICONS.cycle" />
              </svg>
              {{ t('payroll.annual_settlement.bulk.retry') }}
            </button>
          </li>
        </ul>
      </div>
    </section>

    <p v-if="data" class="rounded-md bg-neutral-50 px-4 py-3 text-sm text-neutral-600">
      {{
        t('payroll.annual_settlement.deadlines', {
          request: formatDate(data.request_deadline),
          settlement: formatDate(data.settlement_deadline),
          payout: formatMonth(data.payout_period),
        })
      }}
    </p>

    <div class="rounded-lg border border-neutral-200 bg-surface p-3">
      <div class="flex flex-wrap items-end gap-3">
        <label class="min-w-48 flex-1">
          <span class="mb-1 block text-xs font-medium text-neutral-600">
            {{ t('payroll.annual_settlement.filter_search') }}
          </span>
          <input
            v-model="search"
            type="search"
            data-test="annual-settlement-search"
            :placeholder="t('payroll.annual_settlement.filter_search_placeholder')"
            class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm"
            @keyup.enter="applyFilters"
            @change="applyFilters"
          >
        </label>
        <label class="min-w-44">
          <span class="mb-1 block text-xs font-medium text-neutral-600">
            {{ t('payroll.annual_settlement.filter_state') }}
          </span>
          <select
            v-model="state"
            data-test="annual-settlement-state"
            class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-2 text-sm"
            @change="applyFilters"
          >
            <option value="all">{{ t('payroll.annual_settlement.states.all') }}</option>
            <option value="requested">{{ t('payroll.annual_settlement.states.requested') }}</option>
            <option value="unsettled">{{ t('payroll.annual_settlement.states.unsettled') }}</option>
            <option value="settled">{{ t('payroll.annual_settlement.states.settled') }}</option>
          </select>
        </label>
        <div class="flex flex-wrap items-center gap-2 pb-0.5">
          <SavedFiltersMenu :ctrl="saved" />
        </div>
      </div>
    </div>

    <div class="grid gap-6 lg:grid-cols-[minmax(0,22rem)_minmax(0,1fr)]">
      <section class="rounded-lg border border-neutral-200 bg-surface">
        <div
          v-if="!loading && items.length > 0 && canWrite"
          class="flex flex-wrap items-center gap-2 border-b border-neutral-200 px-4 py-2"
          data-test="annual-settlement-bulk-request-bar"
        >
          <label class="inline-flex items-center gap-2 text-xs text-neutral-600">
            <input
              type="checkbox"
              :checked="allOnPageSelected"
              data-test="annual-settlement-select-all"
              @change="toggleBulkPage"
            >
            {{ t('payroll.annual_settlement.bulk_request.select_page') }}
          </label>
          <template v-if="bulkSelected.size > 0">
            <span class="text-xs font-medium text-neutral-700" data-test="annual-settlement-bulk-request-count">
              {{ t('payroll.annual_settlement.bulk_request.selected', { count: bulkSelected.size }) }}
            </span>
            <button
              type="button"
              data-test="annual-settlement-bulk-not-requested"
              :class="[btnFilledSm('warning'), 'whitespace-nowrap']"
              :disabled="bulkRequestSaving"
              @click="bulkSetRequestStatus('not_requested')"
            >
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path :d="ICONS.x" />
              </svg>
              {{ t('payroll.annual_settlement.bulk_request.set_not_requested') }}
            </button>
            <button
              type="button"
              data-test="annual-settlement-bulk-requested"
              :class="[btnFilledSm('success'), 'whitespace-nowrap']"
              :disabled="bulkRequestSaving"
              @click="bulkSetRequestStatus('requested')"
            >
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path :d="ICONS.check" />
              </svg>
              {{ t('payroll.annual_settlement.bulk_request.set_requested') }}
            </button>
            <button
              type="button"
              :class="[btnOutlineSm('neutral'), 'whitespace-nowrap']"
              @click="bulkSelected = new Set()"
            >
              {{ t('payroll.annual_settlement.bulk_request.clear') }}
            </button>
          </template>
        </div>
        <div v-if="loading" class="p-6 text-sm text-neutral-500">…</div>
        <EmptyState
          v-else-if="loadFailed && items.length === 0"
          variant="failed"
          accent="primary"
          :cta="t('payroll.annual_settlement.reload')"
          @action="load"
        />
        <EmptyState
          v-else-if="items.length === 0"
          variant="empty"
          icon="user"
          accent="primary"
          :title="t('payroll.annual_settlement.no_employees_title')"
          :message="t('payroll.annual_settlement.no_employees_description')"
        />
        <ul v-else class="divide-y divide-neutral-200">
          <li v-for="item in items" :key="item.employee_id" class="flex items-center">
            <input
              v-if="canWrite"
              type="checkbox"
              class="ml-4 shrink-0"
              :checked="bulkSelected.has(item.employee_id)"
              :aria-label="t('payroll.annual_settlement.bulk_request.select_person', { name: item.employee_name })"
              data-test="annual-settlement-person-check"
              @change="toggleBulkPerson(item.employee_id)"
            >
            <button
              type="button"
              data-test="annual-settlement-person"
              class="cursor-pointer flex min-w-0 flex-1 flex-wrap items-center justify-between gap-2 px-4 py-3 text-left transition-colors hover:bg-neutral-50"
              :class="item.employee_id === selectedEmployeeId ? 'bg-primary-50' : ''"
              @click="select(item.employee_id)"
            >
              <span class="min-w-0">
                <span class="block truncate text-sm font-medium text-neutral-900">
                  {{ item.employee_name }}
                </span>
                <span class="block text-xs text-neutral-500">{{ stateLabel(item) }}</span>
              </span>
              <span class="shrink-0 text-right">
                <span v-if="item.outcome" class="block text-xs font-medium text-neutral-700">
                  {{ t(`payroll.annual_settlement.outcome.${item.outcome}`) }}
                </span>
                <span v-if="item.outcome" class="block text-xs text-neutral-500">
                  {{ money(item.payable_minor) }}
                </span>
                <span v-else class="block text-xs text-neutral-400">
                  {{ t('payroll.annual_settlement.not_settled') }}
                </span>
              </span>
            </button>
          </li>
        </ul>
        <PaginationBar
          v-if="!loading"
          embedded
          :page="currentPage"
          :per-page="pageSize"
          :total="total"
          @update:page="goToPage"
        />
      </section>

      <section class="space-y-6">
        <div
          v-if="selectedEmployeeId === null"
          class="rounded-lg border border-dashed border-neutral-300 bg-surface p-6 text-sm text-neutral-500"
        >
          {{ t('payroll.annual_settlement.select_employee') }}
        </div>

        <template v-else>
          <div class="rounded-lg border border-neutral-200 bg-surface p-4">
            <h2 class="text-base font-semibold text-neutral-900">
              {{ t('payroll.annual_settlement.request_section') }}
            </h2>
            <div class="mt-4 grid gap-4 sm:grid-cols-2">
              <label class="block">
                <span class="mb-1 block text-xs font-medium text-neutral-600">
                  {{ t('payroll.annual_settlement.request_status') }}
                </span>
                <select
                  v-model="form.request_status"
                  data-test="annual-settlement-request-status"
                  class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm text-neutral-900"
                >
                  <option
                    v-for="option in ['unknown', 'requested', 'not_requested', 'withdrawn']"
                    :key="option"
                    :value="option"
                  >
                    {{ t(`payroll.annual_settlement.request_status_options.${option}`) }}
                  </option>
                </select>
              </label>

              <label v-if="form.request_status === 'requested'" class="block">
                <span class="mb-1 block text-xs font-medium text-neutral-600">
                  {{ t('payroll.annual_settlement.requested_on') }}
                </span>
                <DateInput
                  ref="requestedOnField"
                  v-model="form.requested_on"
                  data-test="annual-settlement-requested-on"
                  class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm text-neutral-900" />
              </label>

              <label v-if="form.request_status === 'requested'" class="block sm:col-span-2">
                <span class="mb-1 block text-xs font-medium text-neutral-600">
                  {{ t('payroll.annual_settlement.request_evidence') }}
                </span>
                <input
                  v-model="form.request_evidence_reference"
                  type="text"
                  maxlength="500"
                  :placeholder="t('payroll.annual_settlement.request_evidence_placeholder')"
                  class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm text-neutral-900"
                >
              </label>

              <label class="block">
                <span class="mb-1 block text-xs font-medium text-neutral-600">
                  {{ t('payroll.annual_settlement.prior_employers') }}
                </span>
                <select
                  v-model="form.prior_employers"
                  class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm text-neutral-900"
                >
                  <option
                    v-for="option in ['unknown', 'none', 'all_documented', 'missing']"
                    :key="option"
                    :value="option"
                  >
                    {{ t(`payroll.annual_settlement.prior_employers_options.${option}`) }}
                  </option>
                </select>
              </label>

              <label v-if="form.prior_employers === 'all_documented'" class="block">
                <span class="mb-1 block text-xs font-medium text-neutral-600">
                  {{ t('payroll.annual_settlement.prior_documents_received_on') }}
                </span>
                <DateInput
                  ref="priorDocumentsField"
                  v-model="form.prior_documents_received_on"
                  data-test="annual-settlement-prior-documents-on"
                  class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm text-neutral-900" />
              </label>

              <label class="block">
                <span class="mb-1 block text-xs font-medium text-neutral-600">
                  {{ t('payroll.annual_settlement.filing_obligation') }}
                </span>
                <select
                  ref="filingObligationField"
                  v-model="form.filing_obligation"
                  data-test="annual-settlement-filing-obligation"
                  class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm text-neutral-900"
                >
                  <option
                    v-for="option in ['unknown', 'none', 'required']"
                    :key="option"
                    :value="option"
                  >
                    {{ t(`payroll.annual_settlement.filing_obligation_options.${option}`) }}
                  </option>
                </select>
              </label>

              <label v-if="form.filing_obligation === 'required'" class="block">
                <span class="mb-1 block text-xs font-medium text-neutral-600">
                  {{ t('payroll.annual_settlement.filing_obligation_reason') }}
                </span>
                <input
                  v-model="form.filing_obligation_reason"
                  type="text"
                  maxlength="500"
                  :placeholder="t('payroll.annual_settlement.filing_obligation_reason_placeholder')"
                  class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm text-neutral-900"
                >
              </label>

              <label class="block sm:col-span-2">
                <span class="mb-1 block text-xs font-medium text-neutral-600">
                  {{ t('payroll.annual_settlement.annual_claims') }}
                </span>
                <select
                  ref="annualClaimsField"
                  v-model="form.annual_claims"
                  data-test="annual-settlement-annual-claims"
                  class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm text-neutral-900"
                >
                  <option
                    v-for="option in ['unknown', 'none', 'present', 'present_unsupported']"
                    :key="option"
                    :value="option"
                  >
                    {{ t(`payroll.annual_settlement.annual_claims_options.${option}`) }}
                  </option>
                </select>
                <span class="mt-1 block text-xs text-neutral-500">
                  {{ t('payroll.annual_settlement.annual_claims_hint') }}
                </span>
              </label>

              <label v-if="form.annual_claims === 'present_unsupported'" class="block sm:col-span-2">
                <span class="mb-1 block text-xs font-medium text-neutral-600">
                  {{ t('payroll.annual_settlement.annual_claims_note') }}
                </span>
                <input
                  v-model="form.annual_claims_note"
                  type="text"
                  maxlength="500"
                  :placeholder="t('payroll.annual_settlement.annual_claims_note_placeholder')"
                  class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm text-neutral-900"
                >
              </label>

              <fieldset
                :disabled="!canWrite"
                class="sm:col-span-2 rounded-md border border-neutral-200 p-3"
                data-test="annual-settlement-caregiver-fields"
              >
                <label class="block">
                  <span class="mb-1 block text-xs font-medium text-neutral-600">
                    {{ t('payroll.annual_settlement.other_caregiver_status') }}
                  </span>
                  <select
                    ref="caregiverStatusField"
                    v-model="form.other_household_caregiver_status"
                    data-test="annual-settlement-caregiver-status"
                    class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm text-neutral-900"
                  >
                    <option
                      v-for="option in ['unknown', 'none', 'present']"
                      :key="option"
                      :value="option"
                    >
                      {{ t(`payroll.annual_settlement.other_caregiver_options.${option}`) }}
                    </option>
                  </select>
                  <span class="mt-1 block text-xs text-neutral-500">
                    {{ t('payroll.annual_settlement.other_caregiver_hint') }}
                  </span>
                </label>

                <template v-if="form.other_household_caregiver_status === 'present'">
                  <div
                    v-for="(caregiver, caregiverIndex) in form.other_household_caregivers"
                    :key="caregiverIndex"
                    class="mt-3 rounded-md border border-neutral-200 p-3"
                    data-test="annual-settlement-caregiver"
                  >
                    <div class="grid gap-3 sm:grid-cols-3">
                      <label class="block">
                        <span class="mb-1 block text-xs font-medium text-neutral-600">
                          {{ t('payroll.annual_settlement.caregiver_given_name') }}
                        </span>
                        <input
                          v-model="caregiver.given_name"
                          type="text"
                          maxlength="100"
                          class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm text-neutral-900"
                        >
                      </label>
                      <label class="block">
                        <span class="mb-1 block text-xs font-medium text-neutral-600">
                          {{ t('payroll.annual_settlement.caregiver_family_name') }}
                        </span>
                        <input
                          v-model="caregiver.family_name"
                          type="text"
                          maxlength="100"
                          class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm text-neutral-900"
                        >
                      </label>
                      <label class="block">
                        <span class="mb-1 block text-xs font-medium text-neutral-600">
                          {{ t('payroll.annual_settlement.caregiver_birth_date') }}
                        </span>
                        <DateInput
                          v-model="caregiver.birth_date"
                          class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm text-neutral-900" />
                      </label>
                    </div>
                    <fieldset class="mt-3">
                      <legend class="text-xs font-medium text-neutral-600">
                        {{ t('payroll.annual_settlement.caregiver_months') }}
                      </legend>
                      <div class="mt-2 flex flex-wrap gap-2">
                        <label
                          v-for="month in MONTHS"
                          :key="month"
                          class="inline-flex items-center gap-1 rounded-md border border-neutral-200 px-2 py-1 text-xs text-neutral-700"
                        >
                          <input
                            v-model="caregiver.months"
                            type="checkbox"
                            :value="month"
                          >
                          {{ monthLabel(month) }}
                        </label>
                      </div>
                    </fieldset>
                    <div class="mt-3 flex justify-end">
                      <button
                        type="button"
                        :class="btnOutline('danger')"
                        data-test="annual-settlement-caregiver-remove"
                        @click="removeCaregiver(caregiverIndex)"
                      >
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                          <path :d="ICONS.trash" />
                        </svg>
                        {{ t('payroll.annual_settlement.caregiver_remove') }}
                      </button>
                    </div>
                  </div>
                  <button
                    type="button"
                    :class="btnOutline('neutral')"
                    class="mt-3"
                    data-test="annual-settlement-caregiver-add"
                    @click="addCaregiver"
                  >
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                      <path :d="ICONS.plus" />
                    </svg>
                    {{ t('payroll.annual_settlement.caregiver_add') }}
                  </button>
                </template>
              </fieldset>

              <label class="block sm:col-span-2">
                <span class="mb-1 block text-xs font-medium text-neutral-600">
                  {{ t('payroll.annual_settlement.note') }}
                </span>
                <input
                  v-model="form.note"
                  type="text"
                  maxlength="1000"
                  class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm text-neutral-900"
                >
              </label>
            </div>

            <div class="mt-4 flex flex-wrap items-center justify-end gap-2">
              <p
                v-if="!canWrite"
                :class="BTN_DISABLED_NOTE"
                data-test="annual-settlement-save-request-blocked"
              >
                {{ t('payroll.annual_settlement.save_request_read_only') }}
              </p>
              <button
                type="button"
                data-test="annual-settlement-save-request"
                :class="btnOutline('primary')"
                :disabled="!canWrite || saving"
                :title="disabledTitle(!canWrite, t('payroll.annual_settlement.save_request_read_only'))"
                @click="saveRequest"
              >
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                  <path :d="ICONS.check" />
                </svg>
                {{ t('payroll.annual_settlement.save_request') }}
              </button>
            </div>
          </div>

          <!--
            Potvrzení od předchozích plátců (§ 38ch odst. 3).

            Prázdná částka NENÍ nula. Prázdné pole znamená „na potvrzení to
            není" a zúčtování se neprovede; nula znamená „na potvrzení je
            nula" a počítá se s ní. Kdyby se prázdno četlo jako nula, vyšel by
            z porovnání podle § 35d odst. 7 přeplatek, který poplatníkovi
            nenáleží.
          -->
          <div
            class="rounded-lg border border-neutral-200 bg-surface p-4"
            data-test="annual-settlement-certificates"
          >
            <h2 class="text-base font-semibold text-neutral-900">
              {{ t('payroll.annual_settlement.certificates_section') }}
            </h2>
            <p class="mt-1 text-sm text-neutral-500">
              {{ t('payroll.annual_settlement.certificates_hint') }}
            </p>

            <p
              v-if="certificates.length === 0"
              class="mt-4 rounded-md bg-neutral-50 px-4 py-3 text-sm text-neutral-600"
            >
              {{ t('payroll.annual_settlement.certificates_empty') }}
            </p>

            <div
              v-for="(certificate, index) in certificates"
              :key="index"
              class="mt-4 rounded-md border border-neutral-200 p-3"
              data-test="annual-settlement-certificate"
            >
              <div class="grid gap-3 sm:grid-cols-2">
                <label class="block">
                  <span class="mb-1 block text-xs font-medium text-neutral-600">
                    {{ t('payroll.annual_settlement.certificate_reference') }}
                  </span>
                  <input
                    v-model="certificate.certificate_reference"
                    type="text"
                    maxlength="200"
                    :disabled="!canEditCertificates"
                    class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm text-neutral-900"
                  >
                </label>
                <label class="block">
                  <span class="mb-1 block text-xs font-medium text-neutral-600">
                    {{ t('payroll.annual_settlement.certificate_payer_name') }}
                  </span>
                  <input
                    v-model="certificate.payer_name"
                    type="text"
                    maxlength="255"
                    :disabled="!canEditCertificates"
                    class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm text-neutral-900"
                  >
                </label>
                <label class="block">
                  <span class="mb-1 block text-xs font-medium text-neutral-600">
                    {{ t('payroll.annual_settlement.certificate_payer_tax_id') }}
                  </span>
                  <input
                    v-model="certificate.payer_tax_identification"
                    type="text"
                    maxlength="30"
                    :disabled="!canEditCertificates"
                    class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm text-neutral-900"
                  >
                </label>
                <label class="block">
                  <span class="mb-1 block text-xs font-medium text-neutral-600">
                    {{ t('payroll.annual_settlement.certificate_received_on') }}
                  </span>
                  <DateInput
                    v-model="certificate.received_on"
                    :disabled="!canEditCertificates"
                    class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm text-neutral-900" />
                </label>
                <!--
                  Tiskopisové „za období od–do". Rozhoduje o tom, jestli poplatník
                  pobíral mzdu POSTUPNĚ, nebo od dvou plátců SOUČASNĚ — překryv
                  zvedne blokátor přiznání (§ 38g odst. 2). Nepovinné schválně:
                  historická potvrzení období nenesou a prázdno znamená „nevíme",
                  ne „souběh nebyl".
                -->
                <label class="block">
                  <span class="mb-1 block text-xs font-medium text-neutral-600">
                    {{ t('payroll.annual_settlement.certificate_employment_from') }}
                  </span>
                  <DateInput
                    v-model="certificate.employment_from"
                    data-test="certificate-employment-from"
                    :disabled="!canEditCertificates"
                    class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm text-neutral-900" />
                </label>
                <label class="block">
                  <span class="mb-1 block text-xs font-medium text-neutral-600">
                    {{ t('payroll.annual_settlement.certificate_employment_to') }}
                  </span>
                  <DateInput
                    v-model="certificate.employment_to"
                    data-test="certificate-employment-to"
                    :disabled="!canEditCertificates"
                    class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm text-neutral-900" />
                </label>
                <p class="text-xs text-neutral-500 sm:col-span-2">
                  {{ t('payroll.annual_settlement.certificate_employment_period_hint') }}
                </p>

                <label
                  v-for="field in CERTIFICATE_AMOUNTS"
                  :key="field"
                  class="block"
                >
                  <span class="mb-1 block text-xs font-medium text-neutral-600">
                    {{ t(`payroll.annual_settlement.certificate.field.${field}`) }}
                  </span>
                  <input
                    v-model="certificate[field]"
                    type="text"
                    inputmode="decimal"
                    :disabled="!canEditCertificates"
                    :placeholder="t('payroll.annual_settlement.certificate_amount_placeholder')"
                    :data-test="`annual-settlement-certificate-${field}`"
                    class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-right text-sm tabular-nums text-neutral-900"
                  >
                </label>

                <label class="block">
                  <span class="mb-1 block text-xs font-medium text-neutral-600">
                    {{ t('payroll.annual_settlement.certificate_evidence_status') }}
                  </span>
                  <select
                    v-model="certificate.evidence_status"
                    :disabled="!canEditCertificates"
                    class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm text-neutral-900"
                  >
                    <option
                      v-for="option in ['unverified', 'verified']"
                      :key="option"
                      :value="option"
                    >
                      {{ t(`payroll.annual_settlement.certificate_evidence_options.${option}`) }}
                    </option>
                  </select>
                </label>
                <label class="block">
                  <span class="mb-1 block text-xs font-medium text-neutral-600">
                    {{ t('payroll.annual_settlement.certificate_evidence_reference') }}
                  </span>
                  <input
                    v-model="certificate.evidence_reference"
                    type="text"
                    maxlength="500"
                    :disabled="!canEditCertificates"
                    class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm text-neutral-900"
                  >
                </label>
              </div>

              <p
                v-if="certificate.missing.length > 0"
                class="mt-3 rounded-md border border-warning-500/40 bg-warning-50 px-3 py-2 text-sm text-neutral-700"
                data-test="annual-settlement-certificate-missing"
              >
                {{
                  t('payroll.annual_settlement.certificate_missing', {
                    fields: certificate.missing
                      .map(field => t(`payroll.annual_settlement.certificate.field.${field}`))
                      .join(', '),
                  })
                }}
              </p>

              <div class="mt-3 flex justify-end">
                <button
                  type="button"
                  :class="btnOutline('danger')"
                  :disabled="!canEditCertificates || savingCertificates"
                  data-test="annual-settlement-certificate-remove"
                  @click="removeCertificate(index)"
                >
                  {{ t('payroll.annual_settlement.certificate_remove') }}
                </button>
              </div>
            </div>

            <div class="mt-4 flex flex-wrap justify-end gap-2">
              <button
                type="button"
                :class="btnOutline('neutral')"
                :disabled="!canEditCertificates || savingCertificates"
                data-test="annual-settlement-certificate-add"
                @click="addCertificate"
              >
                {{ t('payroll.annual_settlement.certificate_add') }}
              </button>
              <button
                type="button"
                data-test="annual-settlement-save-certificates"
                :class="btnOutline('primary')"
                :disabled="!canEditCertificates || savingCertificates"
                @click="saveCertificates"
              >
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                  <path :d="ICONS.check" />
                </svg>
                {{ t('payroll.annual_settlement.save_certificates') }}
              </button>
            </div>
          </div>

          <!--
            Ročně uplatňované položky: nezdanitelné části (§ 15), sleva na manžela
            (§ 35ba) a sleva za zastavenou exekuci. Vzor i ukládání jako u potvrzení
            výše: ukládá se celá sada za rok jedním tlačítkem a výsledek se přepočte.
          -->
          <div
            class="rounded-lg border border-neutral-200 bg-surface p-4"
            data-test="annual-settlement-claims"
          >
            <h2 class="text-base font-semibold text-neutral-900">
              {{ t('payroll.annual_settlement.claims.section') }}
            </h2>
            <p class="mt-1 text-sm text-neutral-500">
              {{ t('payroll.annual_settlement.claims.hint') }}
            </p>

            <p
              v-if="deductions.length === 0"
              class="mt-4 rounded-md bg-neutral-50 px-4 py-3 text-sm text-neutral-600"
            >
              {{ t('payroll.annual_settlement.claims.empty') }}
            </p>

            <div
              v-for="(row, index) in deductions"
              :key="index"
              class="mt-4 rounded-md border border-neutral-200 p-3"
              data-test="annual-settlement-deduction"
            >
              <p class="text-sm font-medium text-neutral-900">
                {{ t(`payroll.annual_settlement.claims.kind.${row.kind}`) }}
              </p>
              <p class="mt-1 text-xs text-neutral-500">
                {{ t(`payroll.annual_settlement.claims.kind_hint.${row.kind}`) }}
              </p>

              <div class="mt-3 grid gap-3 sm:grid-cols-2">
                <label v-if="quantityRates[row.kind] === undefined" class="block">
                  <span class="mb-1 block text-xs font-medium text-neutral-600">
                    {{ t('payroll.annual_settlement.claims.amount') }}
                  </span>
                  <input
                    v-model="row.amount"
                    type="text"
                    inputmode="decimal"
                    :disabled="!canEditCertificates"
                    :placeholder="t('payroll.annual_settlement.certificate_amount_placeholder')"
                    data-test="annual-settlement-deduction-amount"
                    class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-right text-sm tabular-nums text-neutral-900"
                  >
                </label>
                <label v-else class="block">
                  <span class="mb-1 block text-xs font-medium text-neutral-600">
                    {{ t('payroll.annual_settlement.claims.quantity') }}
                  </span>
                  <input
                    v-model="row.quantity"
                    type="text"
                    inputmode="numeric"
                    :disabled="!canEditCertificates"
                    data-test="annual-settlement-deduction-quantity"
                    class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-right text-sm tabular-nums text-neutral-900"
                  >
                  <span class="mt-1 block text-xs text-neutral-500" data-test="annual-settlement-deduction-evaluation">
                    {{ t('payroll.annual_settlement.claims.quantity_evaluation', {
                      rate: money(quantityRates[row.kind]),
                      total: money(quantityEvaluation(row)),
                    }) }}
                  </span>
                </label>

                <label class="block">
                  <span class="mb-1 block text-xs font-medium text-neutral-600">
                    {{ t(`payroll.annual_settlement.claims.counterparty.${row.kind}`) }}
                  </span>
                  <input
                    v-model="row.counterparty_name"
                    type="text"
                    maxlength="255"
                    :disabled="!canEditCertificates"
                    class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm text-neutral-900"
                  >
                </label>

                <fieldset v-if="row.kind === 'housing_interest'" class="sm:col-span-2">
                  <legend class="mb-1 text-xs font-medium text-neutral-600">
                    {{ t('payroll.annual_settlement.claims.months') }}
                  </legend>
                  <div class="flex flex-wrap gap-x-3 gap-y-1">
                    <label
                      v-for="month in MONTHS"
                      :key="month"
                      class="inline-flex items-center gap-1 text-sm text-neutral-700"
                    >
                      <input
                        type="checkbox"
                        :checked="row.months.includes(month)"
                        :disabled="!canEditCertificates"
                        data-test="annual-settlement-deduction-month"
                        @change="row.months = toggleMonth(row.months, month)"
                      >
                      {{ monthLabel(month) }}
                    </label>
                  </div>
                  <label class="mt-2 inline-flex items-center gap-2 text-sm text-neutral-700">
                    <input
                      v-model="row.housing_acquired_before_2021"
                      type="checkbox"
                      :disabled="!canEditCertificates"
                      data-test="annual-settlement-deduction-before-2021"
                    >
                    {{ t('payroll.annual_settlement.claims.housing_before_2021') }}
                  </label>
                </fieldset>

                <label class="block">
                  <span class="mb-1 block text-xs font-medium text-neutral-600">
                    {{ t('payroll.annual_settlement.certificate_evidence_status') }}
                  </span>
                  <select
                    v-model="row.evidence_status"
                    :disabled="!canEditCertificates"
                    class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm text-neutral-900"
                  >
                    <option v-for="option in ['unverified', 'verified']" :key="option" :value="option">
                      {{ t(`payroll.annual_settlement.certificate_evidence_options.${option}`) }}
                    </option>
                  </select>
                </label>
                <label class="block">
                  <span class="mb-1 block text-xs font-medium text-neutral-600">
                    {{ t('payroll.annual_settlement.claims.evidence_reference') }}
                  </span>
                  <input
                    v-model="row.evidence_reference"
                    type="text"
                    maxlength="500"
                    :disabled="!canEditCertificates"
                    class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm text-neutral-900"
                  >
                </label>
                <label class="block sm:col-span-2">
                  <span class="mb-1 block text-xs font-medium text-neutral-600">
                    {{ t('payroll.annual_settlement.claims.note') }}
                  </span>
                  <input
                    v-model="row.note"
                    type="text"
                    maxlength="500"
                    :disabled="!canEditCertificates"
                    class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm text-neutral-900"
                  >
                </label>
              </div>

              <div class="mt-3 flex flex-wrap justify-end gap-2">
                <button
                  type="button"
                  :class="[btnOutline('danger'), 'whitespace-nowrap']"
                  :disabled="!canEditCertificates || savingClaims"
                  data-test="annual-settlement-deduction-remove"
                  @click="removeDeduction(index)"
                >
                  <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.trash" /></svg>
                  {{ t('payroll.annual_settlement.certificate_remove') }}
                </button>
              </div>
            </div>

            <div class="mt-4 flex flex-wrap items-end gap-2">
              <label class="block min-w-0 grow sm:grow-0">
                <span class="mb-1 block text-xs font-medium text-neutral-600">
                  {{ t('payroll.annual_settlement.claims.new_kind') }}
                </span>
                <select
                  v-model="newDeductionKind"
                  :disabled="!canEditCertificates"
                  data-test="annual-settlement-new-deduction-kind"
                  class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm text-neutral-900"
                >
                  <option v-for="kind in DEDUCTION_KINDS" :key="kind" :value="kind">
                    {{ t(`payroll.annual_settlement.claims.kind.${kind}`) }}
                  </option>
                </select>
              </label>
              <button
                type="button"
                :class="[btnOutline('neutral'), 'whitespace-nowrap']"
                :disabled="!canEditCertificates || savingClaims"
                data-test="annual-settlement-deduction-add"
                @click="addDeduction"
              >
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.plus" /></svg>
                {{ t('payroll.annual_settlement.claims.add') }}
              </button>
            </div>

            <div class="mt-6 border-t border-neutral-200 pt-4" data-test="annual-settlement-spouse">
              <h3 class="text-sm font-semibold text-neutral-900">
                {{ t('payroll.annual_settlement.claims.spouse_section') }}
              </h3>
              <p class="mt-1 text-xs text-neutral-500">
                {{ t('payroll.annual_settlement.claims.spouse_hint') }}
              </p>

              <p
                v-if="spouseCandidates.length === 0"
                class="mt-3 rounded-md bg-neutral-50 px-4 py-3 text-sm text-neutral-600"
                data-test="annual-settlement-spouse-missing"
              >
                {{ t('payroll.annual_settlement.claims.spouse_missing') }}
                <RouterLink
                  v-if="selectedEmployeeId !== null"
                  :to="{ name: 'payroll-person', params: { id: selectedEmployeeId } }"
                  :class="[btnOutline('warning'), 'ml-2 whitespace-nowrap']"
                >
                  <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.edit" /></svg>
                  {{ t('payroll.annual_settlement.open_employee_card') }}
                </RouterLink>
              </p>

              <template v-else>
                <div v-if="spouse === null" class="mt-3 flex flex-wrap gap-2">
                  <button
                    type="button"
                    :class="[btnOutline('neutral'), 'whitespace-nowrap']"
                    :disabled="!canEditCertificates"
                    data-test="annual-settlement-spouse-add"
                    @click="addSpouse"
                  >
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.plus" /></svg>
                    {{ t('payroll.annual_settlement.claims.spouse_add') }}
                  </button>
                </div>

                <div v-else class="mt-3 grid gap-3 sm:grid-cols-2" data-test="annual-settlement-spouse-form">
                  <label class="block">
                    <span class="mb-1 block text-xs font-medium text-neutral-600">
                      {{ t('payroll.annual_settlement.claims.spouse_person') }}
                    </span>
                    <select
                      v-model="spouse.spouse_dependant_id"
                      :disabled="!canEditCertificates"
                      data-test="annual-settlement-spouse-person"
                      class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm text-neutral-900"
                    >
                      <option v-for="row in spouseCandidates" :key="row.id" :value="row.id">
                        {{ row.full_name }}
                      </option>
                    </select>
                  </label>
                  <label class="block">
                    <span class="mb-1 block text-xs font-medium text-neutral-600">
                      {{ t('payroll.annual_settlement.claims.spouse_child') }}
                    </span>
                    <select
                      v-model="spouse.child_dependant_id"
                      :disabled="!canEditCertificates"
                      data-test="annual-settlement-spouse-child"
                      class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm text-neutral-900"
                    >
                      <option v-for="row in childCandidates" :key="row.id" :value="row.id">
                        {{ row.full_name }} ({{ formatDate(row.birth_date) }})
                      </option>
                    </select>
                  </label>

                  <fieldset class="sm:col-span-2">
                    <legend class="mb-1 text-xs font-medium text-neutral-600">
                      {{ t('payroll.annual_settlement.claims.spouse_months') }}
                    </legend>
                    <div class="flex flex-wrap gap-x-3 gap-y-1">
                      <label
                        v-for="month in MONTHS"
                        :key="month"
                        class="inline-flex items-center gap-1 text-sm text-neutral-700"
                      >
                        <input
                          type="checkbox"
                          :checked="spouse.months.includes(month)"
                          :disabled="!canEditCertificates"
                          data-test="annual-settlement-spouse-month"
                          @change="spouse.months = toggleMonth(spouse.months, month)"
                        >
                        {{ monthLabel(month) }}
                      </label>
                    </div>
                  </fieldset>
                  <fieldset v-if="selectedSpouseHasZtpP" class="sm:col-span-2" data-test="annual-settlement-spouse-ztp-p">
                    <legend class="mb-1 text-xs font-medium text-neutral-600">
                      {{ t('payroll.annual_settlement.claims.spouse_ztp_p_months') }}
                    </legend>
                    <div class="flex flex-wrap gap-x-3 gap-y-1">
                      <label
                        v-for="month in MONTHS"
                        :key="month"
                        class="inline-flex items-center gap-1 text-sm text-neutral-700"
                      >
                        <input
                          type="checkbox"
                          :checked="spouse.ztp_p_months.includes(month)"
                          :disabled="!canEditCertificates"
                          data-test="annual-settlement-spouse-ztp-p-month"
                          @change="spouse.ztp_p_months = toggleMonth(spouse.ztp_p_months, month)"
                        >
                        {{ monthLabel(month) }}
                      </label>
                    </div>
                  </fieldset>

                  <label class="block">
                    <span class="mb-1 block text-xs font-medium text-neutral-600">
                      {{ t('payroll.annual_settlement.claims.spouse_income') }}
                    </span>
                    <input
                      v-model="spouse.spouse_income"
                      type="text"
                      inputmode="decimal"
                      :disabled="!canEditCertificates"
                      :placeholder="t('payroll.annual_settlement.certificate_amount_placeholder')"
                      data-test="annual-settlement-spouse-income"
                      class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-right text-sm tabular-nums text-neutral-900"
                    >
                    <span class="mt-1 block text-xs text-neutral-500">
                      {{ t('payroll.annual_settlement.claims.spouse_income_hint') }}
                    </span>
                  </label>
                  <label class="block">
                    <span class="mb-1 block text-xs font-medium text-neutral-600">
                      {{ t('payroll.annual_settlement.certificate_evidence_status') }}
                    </span>
                    <select
                      v-model="spouse.evidence_status"
                      :disabled="!canEditCertificates"
                      class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm text-neutral-900"
                    >
                      <option v-for="option in ['unverified', 'verified']" :key="option" :value="option">
                        {{ t(`payroll.annual_settlement.certificate_evidence_options.${option}`) }}
                      </option>
                    </select>
                  </label>
                  <label class="block">
                    <span class="mb-1 block text-xs font-medium text-neutral-600">
                      {{ t('payroll.annual_settlement.claims.evidence_reference') }}
                    </span>
                    <input
                      v-model="spouse.evidence_reference"
                      type="text"
                      maxlength="500"
                      :disabled="!canEditCertificates"
                      class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm text-neutral-900"
                    >
                  </label>
                  <label class="block">
                    <span class="mb-1 block text-xs font-medium text-neutral-600">
                      {{ t('payroll.annual_settlement.claims.note') }}
                    </span>
                    <input
                      v-model="spouse.note"
                      type="text"
                      maxlength="500"
                      :disabled="!canEditCertificates"
                      class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm text-neutral-900"
                    >
                  </label>

                  <div class="flex flex-wrap justify-end gap-2 sm:col-span-2">
                    <button
                      type="button"
                      :class="[btnOutline('danger'), 'whitespace-nowrap']"
                      :disabled="!canEditCertificates || savingClaims"
                      data-test="annual-settlement-spouse-remove"
                      @click="spouse = null"
                    >
                      <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.trash" /></svg>
                      {{ t('payroll.annual_settlement.claims.spouse_remove') }}
                    </button>
                  </div>
                </div>
              </template>
            </div>

            <div
              v-if="preschoolAvailable || preschool.length > 0"
              class="mt-6 border-t border-neutral-200 pt-4"
              data-test="annual-settlement-preschool"
            >
              <h3 class="text-sm font-semibold text-neutral-900">
                {{ t('payroll.annual_settlement.claims.preschool_section') }}
              </h3>
              <p class="mt-1 text-xs text-neutral-500">
                {{ t('payroll.annual_settlement.claims.preschool_hint') }}
              </p>

              <p
                v-if="preschoolChildCandidates.length === 0"
                class="mt-3 rounded-md bg-neutral-50 px-4 py-3 text-sm text-neutral-600"
                data-test="annual-settlement-preschool-missing"
              >
                {{ t('payroll.annual_settlement.claims.preschool_missing') }}
                <RouterLink
                  v-if="selectedEmployeeId !== null"
                  :to="{ name: 'payroll-person', params: { id: selectedEmployeeId } }"
                  :class="[btnOutline('warning'), 'ml-2 whitespace-nowrap']"
                >
                  <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.edit" /></svg>
                  {{ t('payroll.annual_settlement.open_employee_card') }}
                </RouterLink>
              </p>

              <template v-else>
                <div
                  v-for="(row, index) in preschool"
                  :key="index"
                  class="mt-3 grid gap-3 rounded-md border border-neutral-200 p-3 sm:grid-cols-2"
                  data-test="annual-settlement-preschool-row"
                >
                  <label class="block">
                    <span class="mb-1 block text-xs font-medium text-neutral-600">
                      {{ t('payroll.annual_settlement.claims.preschool_child') }}
                    </span>
                    <select
                      v-model="row.child_dependant_id"
                      :disabled="!canEditCertificates"
                      data-test="annual-settlement-preschool-child"
                      class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm text-neutral-900"
                    >
                      <option v-for="child in preschoolChildCandidates" :key="child.id" :value="child.id">
                        {{ child.full_name }} ({{ formatDate(child.birth_date) }})
                      </option>
                    </select>
                  </label>
                  <label class="block">
                    <span class="mb-1 block text-xs font-medium text-neutral-600">
                      {{ t('payroll.annual_settlement.claims.preschool_facility_kind') }}
                    </span>
                    <select
                      v-model="row.facility_kind"
                      :disabled="!canEditCertificates"
                      data-test="annual-settlement-preschool-kind"
                      class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm text-neutral-900"
                    >
                      <option v-for="kind in PRESCHOOL_FACILITY_KINDS" :key="kind" :value="kind">
                        {{ t(`payroll.annual_settlement.claims.preschool_facility.${kind}`) }}
                      </option>
                    </select>
                  </label>
                  <label class="block">
                    <span class="mb-1 block text-xs font-medium text-neutral-600">
                      {{ t('payroll.annual_settlement.claims.preschool_facility_name') }}
                    </span>
                    <input
                      v-model="row.facility_name"
                      type="text"
                      maxlength="191"
                      :disabled="!canEditCertificates"
                      class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm text-neutral-900"
                    >
                  </label>
                  <label class="block">
                    <span class="mb-1 block text-xs font-medium text-neutral-600">
                      {{ t('payroll.annual_settlement.claims.preschool_expenses') }}
                    </span>
                    <input
                      v-model="row.expenses"
                      type="text"
                      inputmode="decimal"
                      :disabled="!canEditCertificates"
                      :placeholder="t('payroll.annual_settlement.certificate_amount_placeholder')"
                      data-test="annual-settlement-preschool-expenses"
                      class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-right text-sm tabular-nums text-neutral-900"
                    >
                    <span class="mt-1 block text-xs text-neutral-500">
                      {{ t('payroll.annual_settlement.claims.preschool_expenses_hint') }}
                    </span>
                  </label>
                  <label class="block">
                    <span class="mb-1 block text-xs font-medium text-neutral-600">
                      {{ t('payroll.annual_settlement.claims.preschool_households') }}
                    </span>
                    <input
                      v-model.number="row.households_count"
                      type="number"
                      min="1"
                      max="9"
                      :disabled="!canEditCertificates"
                      data-test="annual-settlement-preschool-households"
                      class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-right text-sm tabular-nums text-neutral-900"
                    >
                    <span class="mt-1 block text-xs text-neutral-500">
                      {{ t('payroll.annual_settlement.claims.preschool_households_hint') }}
                    </span>
                  </label>
                  <label v-if="row.households_count > 1" class="block">
                    <span class="mb-1 block text-xs font-medium text-neutral-600">
                      {{ t('payroll.annual_settlement.claims.preschool_agreed_cap') }}
                    </span>
                    <input
                      v-model="row.agreed_cap"
                      type="text"
                      inputmode="decimal"
                      :disabled="!canEditCertificates"
                      :placeholder="t('payroll.annual_settlement.certificate_amount_placeholder')"
                      class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-right text-sm tabular-nums text-neutral-900"
                    >
                    <span class="mt-1 block text-xs text-neutral-500">
                      {{ t('payroll.annual_settlement.claims.preschool_agreed_cap_hint') }}
                    </span>
                  </label>
                  <label v-if="row.households_count > 1 && row.agreed_cap.trim() !== ''" class="block">
                    <span class="mb-1 block text-xs font-medium text-neutral-600">
                      {{ t('payroll.annual_settlement.claims.preschool_agreement_reference') }}
                    </span>
                    <input
                      v-model="row.agreement_reference"
                      type="text"
                      maxlength="500"
                      :disabled="!canEditCertificates"
                      data-test="annual-settlement-preschool-agreement-reference"
                      :placeholder="t('payroll.annual_settlement.claims.preschool_agreement_reference_placeholder')"
                      class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm text-neutral-900"
                    >
                  </label>
                  <div class="space-y-1 sm:col-span-2">
                    <label class="flex items-center gap-2 text-sm text-neutral-700">
                      <input v-model="row.claimed_by_other_taxpayer" type="checkbox" :disabled="!canEditCertificates">
                      {{ t('payroll.annual_settlement.claims.preschool_other_taxpayer') }}
                    </label>
                    <label class="flex items-center gap-2 text-sm text-neutral-700">
                      <input
                        v-model="row.other_supporting_taxpayer"
                        type="checkbox"
                        :disabled="!canEditCertificates"
                        data-test="annual-settlement-preschool-other-supporting"
                      >
                      {{ t('payroll.annual_settlement.claims.preschool_other_supporting') }}
                    </label>
                    <label class="flex items-center gap-2 text-sm text-neutral-700">
                      <input v-model="row.claimed_as_business_expense" type="checkbox" :disabled="!canEditCertificates">
                      {{ t('payroll.annual_settlement.claims.preschool_business_expense') }}
                    </label>
                    <label v-if="isGrandchild(row.child_dependant_id)" class="flex items-center gap-2 text-sm text-neutral-700">
                      <input v-model="row.grandchild_care_replaces_parents" type="checkbox" :disabled="!canEditCertificates">
                      {{ t('payroll.annual_settlement.claims.preschool_grandchild_care') }}
                    </label>
                  </div>
                  <div
                    v-if="preschoolNeedsOtherConfirmation(row)"
                    class="grid gap-3 rounded-md border border-neutral-200 p-3 sm:col-span-2 sm:grid-cols-3"
                    data-test="annual-settlement-preschool-other-confirmation"
                  >
                    <p class="text-xs text-neutral-500 sm:col-span-3">
                      {{ t('payroll.annual_settlement.claims.preschool_other_confirmation_hint') }}
                    </p>
                    <label class="block">
                      <span class="mb-1 block text-xs font-medium text-neutral-600">
                        {{ t('payroll.annual_settlement.claims.preschool_other_taxpayer_name') }}
                      </span>
                      <input
                        v-model="row.other_taxpayer_name"
                        type="text"
                        maxlength="191"
                        :disabled="!canEditCertificates"
                        class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm text-neutral-900"
                      >
                    </label>
                    <label class="block">
                      <span class="mb-1 block text-xs font-medium text-neutral-600">
                        {{ t('payroll.annual_settlement.claims.preschool_other_taxpayer_claimed') }}
                      </span>
                      <input
                        v-model="row.other_taxpayer_claimed"
                        type="text"
                        inputmode="decimal"
                        :disabled="!canEditCertificates"
                        :placeholder="t('payroll.annual_settlement.certificate_amount_placeholder')"
                        class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-right text-sm tabular-nums text-neutral-900"
                      >
                    </label>
                    <label class="block">
                      <span class="mb-1 block text-xs font-medium text-neutral-600">
                        {{ t('payroll.annual_settlement.claims.preschool_other_confirmation_reference') }}
                      </span>
                      <input
                        v-model="row.other_taxpayer_confirmation_reference"
                        type="text"
                        maxlength="500"
                        :disabled="!canEditCertificates"
                        :placeholder="t('payroll.annual_settlement.claims.preschool_other_confirmation_placeholder')"
                        class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm text-neutral-900"
                      >
                    </label>
                  </div>
                  <label class="block">
                    <span class="mb-1 block text-xs font-medium text-neutral-600">
                      {{ t('payroll.annual_settlement.certificate_evidence_status') }}
                    </span>
                    <select
                      v-model="row.evidence_status"
                      :disabled="!canEditCertificates"
                      class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm text-neutral-900"
                    >
                      <option v-for="option in ['unverified', 'verified']" :key="option" :value="option">
                        {{ t(`payroll.annual_settlement.certificate_evidence_options.${option}`) }}
                      </option>
                    </select>
                  </label>
                  <label class="block">
                    <span class="mb-1 block text-xs font-medium text-neutral-600">
                      {{ t('payroll.annual_settlement.claims.evidence_reference') }}
                    </span>
                    <input
                      v-model="row.evidence_reference"
                      type="text"
                      maxlength="500"
                      :disabled="!canEditCertificates"
                      :placeholder="t('payroll.annual_settlement.claims.preschool_evidence_placeholder')"
                      class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm text-neutral-900"
                    >
                  </label>
                  <label class="block sm:col-span-2">
                    <span class="mb-1 block text-xs font-medium text-neutral-600">
                      {{ t('payroll.annual_settlement.claims.note') }}
                    </span>
                    <input
                      v-model="row.note"
                      type="text"
                      maxlength="500"
                      :disabled="!canEditCertificates"
                      class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm text-neutral-900"
                    >
                  </label>
                  <div class="flex flex-wrap justify-end gap-2 sm:col-span-2">
                    <button
                      type="button"
                      :class="[btnOutline('danger'), 'whitespace-nowrap']"
                      :disabled="!canEditCertificates || savingClaims"
                      data-test="annual-settlement-preschool-remove"
                      @click="removePreschool(index)"
                    >
                      <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.trash" /></svg>
                      {{ t('payroll.annual_settlement.claims.preschool_remove') }}
                    </button>
                  </div>
                </div>

                <div class="mt-3 flex flex-wrap gap-2">
                  <button
                    type="button"
                    :class="[btnOutline('neutral'), 'whitespace-nowrap']"
                    :disabled="!canEditCertificates || preschool.length >= preschoolChildCandidates.length"
                    data-test="annual-settlement-preschool-add"
                    @click="addPreschool"
                  >
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.plus" /></svg>
                    {{ t('payroll.annual_settlement.claims.preschool_add') }}
                  </button>
                </div>
              </template>
            </div>

            <div class="mt-4 flex flex-wrap justify-end gap-2">
              <button
                type="button"
                data-test="annual-settlement-save-claims"
                :class="[btnOutline('primary'), 'whitespace-nowrap']"
                :disabled="!canEditCertificates || savingClaims"
                @click="saveClaims"
              >
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                  <path :d="ICONS.check" />
                </svg>
                {{ t('payroll.annual_settlement.claims.save') }}
              </button>
            </div>
          </div>

          <div class="rounded-lg border border-neutral-200 bg-surface p-4">
            <h2 class="text-base font-semibold text-neutral-900">
              {{ t('payroll.annual_settlement.preview_section') }}
            </h2>

            <p v-if="previewLoading" class="mt-3 text-sm text-neutral-500">…</p>

            <template v-else-if="result">
              <div
                v-if="blockers.length > 0"
                class="mt-4 rounded-md border border-warning-500/40 bg-warning-50 p-4"
                data-test="annual-settlement-blockers"
              >
                <p class="text-sm font-medium text-neutral-900">
                  {{ t('payroll.annual_settlement.blockers_title') }}
                </p>
                <ul class="mt-2 space-y-1.5 text-sm text-neutral-700">
                  <li v-for="code in blockers" :key="code" class="flex flex-wrap items-start gap-2">
                    <span aria-hidden="true">•</span>
                    <button
                      v-if="blockerHasField(code)"
                      type="button"
                      class="text-left underline decoration-dotted underline-offset-2 hover:text-payroll-700"
                      :data-test="`annual-settlement-blocker-link-${code}`"
                      @click="focusBlockerField(code)"
                    >
                      {{ t(`payroll.annual_settlement.blocker.${code}`) }}
                    </button>
                    <span v-else>{{ t(`payroll.annual_settlement.blocker.${code}`) }}</span>
                    <ul
                      v-if="code === 'spouse_credit_conditions_not_met' && spouseCreditFailures.length > 0"
                      class="basis-full space-y-1 pl-6 text-neutral-600"
                      data-test="annual-settlement-spouse-failures"
                    >
                      <li v-for="failure in spouseCreditFailures" :key="failure">
                        {{ t(`payroll.annual_settlement.spouse_failure.${failure}`) }}
                      </li>
                    </ul>
                    <ul
                      v-if="code === 'preschool_credit_conditions_not_met' && preschoolCreditFailures.length > 0"
                      class="basis-full space-y-1 pl-6 text-neutral-600"
                      data-test="annual-settlement-preschool-failures"
                    >
                      <template v-for="entry in preschoolCreditFailures" :key="entry.child">
                        <li v-for="failure in entry.codes" :key="`${entry.child}-${failure}`">
                          {{ entry.child }}: {{ t(`payroll.annual_settlement.preschool_failure.${failure}`) }}
                        </li>
                      </template>
                    </ul>
                    <!--
                      Nárok na dítě se zadává v kartě zaměstnance, ne tady —
                      proklik vede rovnou tam, kde se doplní zpětně od ledna.
                    -->
                    <RouterLink
                      v-if="CARD_BLOCKERS.includes(code) && selectedEmployeeId !== null"
                      :to="{ name: 'payroll-person', params: { id: selectedEmployeeId } }"
                      :class="[btnOutline('warning'), 'shrink-0 whitespace-nowrap']"
                      :data-test="`annual-settlement-blocker-card-${code}`"
                    >
                      <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.edit" /></svg>
                      {{ t('payroll.annual_settlement.open_employee_card') }}
                    </RouterLink>
                  </li>
                </ul>
              </div>

              <div
                v-if="blockers.length === 0 && result.bonus_minimum_income_minor_units !== undefined"
                data-test="annual-tax-bonus-eligibility"
                class="mt-4 rounded-md border p-4"
                :class="annualBonusEligible
                  ? 'border-success-500/40 bg-success-50'
                  : 'border-warning-500/40 bg-warning-50'"
              >
                <p class="text-sm font-medium text-neutral-900">
                  {{ t(`payroll.annual_settlement.bonus_eligibility.${annualBonusEligibilityReason}`, {
                    income: money(result.bonus_qualifying_income_minor_units),
                    threshold: money(result.bonus_minimum_income_minor_units),
                    candidate: money(result.annual_bonus_candidate_minor_units),
                    minimum: money(result.bonus_minimum_amount_minor_units),
                  }) }}
                </p>
                <p class="mt-1 text-xs text-neutral-600">
                  {{ t('payroll.annual_settlement.bonus_threshold_hint') }}
                </p>
                <dl class="mt-3 grid gap-2 text-sm sm:grid-cols-2 xl:grid-cols-4">
                  <div data-test="annual-tax-bonus-income">
                    <dt class="text-xs text-neutral-500">{{ t('payroll.annual_settlement.bonus_income') }}</dt>
                    <dd class="mt-0.5 font-medium tabular-nums text-neutral-900">
                      {{ money(result.bonus_qualifying_income_minor_units) }}
                    </dd>
                  </div>
                  <div data-test="annual-tax-bonus-income-threshold">
                    <dt class="text-xs text-neutral-500">{{ t('payroll.annual_settlement.bonus_income_threshold') }}</dt>
                    <dd class="mt-0.5 font-medium tabular-nums text-neutral-900">
                      {{ money(result.bonus_minimum_income_minor_units) }}
                    </dd>
                  </div>
                  <div>
                    <dt class="text-xs text-neutral-500">{{ t('payroll.annual_settlement.bonus_amount_minimum') }}</dt>
                    <dd class="mt-0.5 font-medium tabular-nums text-neutral-900">
                      {{ money(result.bonus_minimum_amount_minor_units) }}
                    </dd>
                  </div>
                  <div data-test="annual-tax-bonus-candidate">
                    <dt class="text-xs text-neutral-500">{{ t('payroll.annual_settlement.bonus_candidate') }}</dt>
                    <dd class="mt-0.5 font-medium tabular-nums text-neutral-900">
                      {{ money(result.annual_bonus_candidate_minor_units) }}
                    </dd>
                  </div>
                </dl>
              </div>

              <table
                v-if="blockers.length === 0"
                class="mt-4 w-full text-sm"
                data-test="annual-settlement-result"
              >
                <tbody class="divide-y divide-neutral-100">
                  <template v-if="result.tax_base_before_deductions_minor_units !== undefined">
                    <tr data-test="annual-settlement-base-before-deductions">
                      <td class="py-2 text-neutral-600">{{ t('payroll.annual_settlement.row_base_before_deductions') }}</td>
                      <td class="py-2 text-right tabular-nums text-neutral-900">
                        {{ money(result.tax_base_before_deductions_minor_units) }}
                      </td>
                    </tr>
                    <tr data-test="annual-settlement-non-taxable">
                      <td class="py-2 text-neutral-600">{{ t('payroll.annual_settlement.row_non_taxable') }}</td>
                      <td class="py-2 text-right tabular-nums text-neutral-900">
                        −{{ money(result.non_taxable_deductions_minor_units ?? 0) }}
                      </td>
                    </tr>
                    <tr v-if="nonTaxable?.donation" data-test="annual-settlement-trace-donation">
                      <td class="py-2 pl-4 text-neutral-500">
                        {{ t('payroll.annual_settlement.trace.donation', {
                          claimed: money(nonTaxable.donation.claimed_minor_units),
                          cap: money(nonTaxable.donation.cap_minor_units),
                        }) }}
                        <span v-if="!nonTaxable.donation.threshold_met">
                          · {{ t('payroll.annual_settlement.trace.threshold_not_met') }}
                        </span>
                      </td>
                      <td class="py-2 text-right tabular-nums text-neutral-700">
                        −{{ money(nonTaxable.donation.deducted_minor_units) }}
                      </td>
                    </tr>
                    <tr v-if="nonTaxable?.housing_interest" data-test="annual-settlement-trace-housing">
                      <td class="py-2 pl-4 text-neutral-500">
                        {{ t('payroll.annual_settlement.trace.housing_interest', {
                          claimed: money(nonTaxable.housing_interest.claimed_minor_units),
                          months: nonTaxable.housing_interest.months,
                          cap: money(nonTaxable.housing_interest.cap_minor_units),
                        }) }}
                      </td>
                      <td class="py-2 text-right tabular-nums text-neutral-700">
                        −{{ money(nonTaxable.housing_interest.deducted_minor_units) }}
                      </td>
                    </tr>
                    <tr v-if="nonTaxable?.retirement_and_care" data-test="annual-settlement-trace-retirement">
                      <td class="py-2 pl-4 text-neutral-500">
                        {{ t('payroll.annual_settlement.trace.retirement_and_care', {
                          claimed: money(nonTaxable.retirement_and_care.claimed_minor_units),
                          cap: money(nonTaxable.retirement_and_care.cap_minor_units),
                        }) }}
                      </td>
                      <td class="py-2 text-right tabular-nums text-neutral-700">
                        −{{ money(nonTaxable.retirement_and_care.deducted_minor_units) }}
                      </td>
                    </tr>
                  </template>
                  <tr>
                    <td class="py-2 text-neutral-600">{{ t('payroll.annual_settlement.row_rounded_base') }}</td>
                    <td class="py-2 text-right tabular-nums text-neutral-900">
                      {{ money(result.rounded_tax_base_minor_units) }}
                    </td>
                  </tr>
                  <tr>
                    <td class="py-2 text-neutral-600">{{ t('payroll.annual_settlement.row_tax') }}</td>
                    <td class="py-2 text-right tabular-nums text-neutral-900">
                      {{ money(result.tax_before_credits_minor_units) }}
                    </td>
                  </tr>
                  <tr
                    v-if="(result.enforcement_credit_minor_units ?? 0) > 0"
                    data-test="annual-settlement-trace-enforcement"
                  >
                    <td class="py-2 pl-4 text-neutral-500">{{ t('payroll.annual_settlement.row_enforcement_credit') }}</td>
                    <td class="py-2 text-right tabular-nums text-neutral-700">
                      −{{ money(result.enforcement_credit_minor_units) }}
                    </td>
                  </tr>
                  <tr v-for="row in preview?.credit_rows ?? []" :key="row.label">
                    <td class="py-2 pl-4 text-neutral-500">{{ row.label }}</td>
                    <td class="py-2 text-right tabular-nums text-neutral-700">
                      −{{ money(row.amount_minor_units) }}
                    </td>
                  </tr>
                  <tr v-for="row in preview?.child_rows ?? []" :key="row.label">
                    <td class="py-2 pl-4 text-neutral-500">
                      {{ row.label }} · {{ t('payroll.annual_settlement.months', { count: row.months }) }}
                    </td>
                    <td class="py-2 text-right tabular-nums text-neutral-700">
                      −{{ money(row.amount_minor_units) }}
                    </td>
                  </tr>
                  <tr>
                    <td class="py-2 text-neutral-600">{{ t('payroll.annual_settlement.row_tax_after') }}</td>
                    <td class="py-2 text-right tabular-nums text-neutral-900">
                      {{ money(result.tax_after_all_credits_minor_units) }}
                    </td>
                  </tr>
                  <tr>
                    <td class="py-2 text-neutral-600">{{ t('payroll.annual_settlement.row_tax_difference') }}</td>
                    <td class="py-2 text-right tabular-nums text-neutral-900">
                      {{ money(result.tax_difference_minor_units) }}
                    </td>
                  </tr>
                  <tr
                    v-if="result.bonus_minimum_income_minor_units !== undefined"
                    data-test="annual-tax-bonus-entitlement"
                  >
                    <td class="py-2 text-neutral-600">{{ t('payroll.annual_settlement.row_annual_tax_bonus') }}</td>
                    <td class="py-2 text-right tabular-nums text-neutral-900">
                      {{ money(result.annual_tax_bonus_minor_units) }}
                    </td>
                  </tr>
                  <tr
                    v-if="result.monthly_tax_bonus_minor_units !== undefined"
                    data-test="annual-tax-bonus-paid-monthly"
                  >
                    <td class="py-2 text-neutral-600">{{ t('payroll.annual_settlement.row_monthly_tax_bonus') }}</td>
                    <td class="py-2 text-right tabular-nums text-neutral-900">
                      −{{ money(result.monthly_tax_bonus_minor_units) }}
                    </td>
                  </tr>
                  <tr>
                    <td class="py-2 text-neutral-600">{{ t('payroll.annual_settlement.row_bonus_difference') }}</td>
                    <td class="py-2 text-right tabular-nums text-neutral-900">
                      {{ money(result.bonus_difference_minor_units) }}
                    </td>
                  </tr>
                  <tr class="font-semibold">
                    <td class="py-2 text-neutral-900">{{ t('payroll.annual_settlement.row_payable') }}</td>
                    <td class="py-2 text-right tabular-nums text-neutral-900">
                      {{ money(result.payable_minor_units) }}
                    </td>
                  </tr>
                </tbody>
              </table>

              <p
                v-if="result.outcome === 'overpayment'"
                class="mt-3 text-sm text-neutral-600"
              >
                {{ t('payroll.annual_settlement.payout_note', { payout: formatMonth(data?.payout_period) }) }}
              </p>
              <p
                v-else-if="result.outcome === 'overpayment_below_threshold'"
                class="mt-3 text-sm text-neutral-600"
              >
                {{ t('payroll.annual_settlement.below_threshold_note', {
                  threshold: money(data?.payout_threshold_minor),
                }) }}
              </p>
              <p
                v-else-if="result.outcome === 'underpayment_not_withheld'"
                class="mt-3 text-sm text-neutral-600"
              >
                {{ t('payroll.annual_settlement.no_payout_note') }}
              </p>

              <p
                v-if="preview?.already_settled"
                class="mt-3 text-sm text-neutral-600"
                data-test="annual-settlement-already"
              >
                {{ t('payroll.annual_settlement.already_settled', {
                  year: preview.already_settled.tax_year,
                  date: formatDate(preview.already_settled.settled_on),
                }) }}
              </p>

              <p
                v-if="preview?.already_settled?.payout_period_start"
                class="mt-1 text-sm text-neutral-600"
                data-test="annual-settlement-paid-out"
              >
                {{ t('payroll.annual_settlement.paid_out_note', {
                  period: formatMonth(String(preview.already_settled.payout_period_start).slice(0, 7)),
                }) }}
              </p>
              <p
                v-else-if="preview?.already_settled && preview.already_settled.payable_minor > 0"
                class="mt-1 text-sm text-neutral-600"
                data-test="annual-settlement-pending-payout"
              >
                {{ t('payroll.annual_settlement.pending_payout_note') }}
              </p>

              <button
                v-if="document"
                type="button"
                :class="`mt-4 ${btnOutline('primary')}`"
                data-test="annual-settlement-download"
                @click="download"
              >
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                  <path :d="ICONS.download" />
                </svg>
                {{ t('payroll.annual_settlement.document') }}
              </button>
            </template>
          </div>
        </template>
      </section>
    </div>
  </div>
</template>

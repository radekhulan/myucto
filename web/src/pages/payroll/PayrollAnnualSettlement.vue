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
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'
import {
  payrollApi,
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
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { btnOutline, ICONS } from '@/components/ui/buttonStyles'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import PaginationBar from '@/components/ui/PaginationBar.vue'
import SavedFiltersMenu from '@/components/ui/SavedFiltersMenu.vue'
import { useSavedFilters } from '@/composables/useSavedFilters'
import { payrollQueryId } from '@/pages/payroll/payrollAgendaLinks'

const { t, locale } = useI18n()
const auth = useAuthStore()
const toast = useToast()

/**
 * Zúčtování se provádí ZA UPLYNULÉ zdaňovací období, takže výchozí rok je
 * loňský — ne letošní, který ještě neskončil.
 */
const year = ref(new Date().getFullYear() - 1)
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
const performed = computed(() => result.value?.performed === true)

const settleDisabledReason = computed(() => {
  if (!canSettle.value) return t('payroll.annual_settlement.blocker.not_requested')
  if (preview.value === null) return t('payroll.annual_settlement.select_employee')
  if (blockers.value.length > 0) {
    return t(`payroll.annual_settlement.blocker.${blockers.value[0]}`)
  }
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
  } catch (error) {
    preview.value = null
    certificates.value = []
    toast.error(apiErrorMessage(error, t('payroll.annual_settlement.preview_failed')))
  } finally {
    previewLoading.value = false
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
          <li v-for="item in items" :key="item.employee_id">
            <button
              type="button"
              data-test="annual-settlement-person"
              class="flex w-full flex-wrap items-center justify-between gap-2 px-4 py-3 text-left transition-colors hover:bg-neutral-50"
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
                <input
                  v-model="form.requested_on"
                  type="date"
                  class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm text-neutral-900"
                >
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
                <input
                  v-model="form.prior_documents_received_on"
                  type="date"
                  class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm text-neutral-900"
                >
              </label>

              <label class="block">
                <span class="mb-1 block text-xs font-medium text-neutral-600">
                  {{ t('payroll.annual_settlement.filing_obligation') }}
                </span>
                <select
                  v-model="form.filing_obligation"
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
                  v-model="form.annual_claims"
                  class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm text-neutral-900"
                >
                  <option
                    v-for="option in ['unknown', 'none', 'present_unsupported']"
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
                        <input
                          v-model="caregiver.birth_date"
                          type="date"
                          class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm text-neutral-900"
                        >
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

            <div class="mt-4 flex flex-wrap justify-end gap-2">
              <button
                type="button"
                data-test="annual-settlement-save-request"
                :class="btnOutline('primary')"
                :disabled="!canWrite || saving"
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
                  <input
                    v-model="certificate.received_on"
                    type="date"
                    :disabled="!canEditCertificates"
                    class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm text-neutral-900"
                  >
                </label>

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
                  <li v-for="code in blockers" :key="code" class="flex gap-2">
                    <span aria-hidden="true">•</span>
                    <span>{{ t(`payroll.annual_settlement.blocker.${code}`) }}</span>
                  </li>
                </ul>
              </div>

              <div
                v-if="blockers.length === 0 && result.bonus_minimum_income_minor_units !== undefined"
                data-test="annual-tax-bonus-eligibility"
                class="mt-4 rounded-md border p-4"
                :class="result.annual_bonus_threshold_met
                  ? 'border-success-500/40 bg-success-50'
                  : 'border-warning-500/40 bg-warning-50'"
              >
                <p class="text-sm font-medium text-neutral-900">
                  {{ t(result.annual_bonus_threshold_met
                    ? 'payroll.annual_settlement.bonus_threshold_met'
                    : 'payroll.annual_settlement.bonus_threshold_not_met', {
                    income: money(result.bonus_qualifying_income_minor_units),
                    threshold: money(result.bonus_minimum_income_minor_units),
                    minimum: money(result.bonus_minimum_amount_minor_units),
                  }) }}
                </p>
                <p class="mt-1 text-xs text-neutral-600">
                  {{ t('payroll.annual_settlement.bonus_threshold_hint') }}
                </p>
                <dl class="mt-3 grid gap-2 text-sm sm:grid-cols-3">
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
                </dl>
              </div>

              <table
                v-if="blockers.length === 0"
                class="mt-4 w-full text-sm"
                data-test="annual-settlement-result"
              >
                <tbody class="divide-y divide-neutral-100">
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

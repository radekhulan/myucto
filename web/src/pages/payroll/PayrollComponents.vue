<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import {
  payrollApi,
  type PayrollAccountOption,
  type PayrollComponent,
  type PayrollComponentJmhzMappingState,
  type PayrollComponentJmhzTarget,
  type PayrollComponentFrequency,
  type PayrollBenefitExemptionBasket,
  type PayrollExemptionBasis,
  type PayrollComponentInclusion,
  type PayrollComponentKind,
  type PayrollComponentPayload,
  type PayrollComponentTaxTreatment,
  type PayrollComponentValueKind,
  type PayrollInput,
  type PayrollInputImportPayload,
  type PayrollInputImportPreview,
  type PayrollInputImportResult,
  type PayrollInputPayload,
  type PayrollInputPreview,
  type PayrollRecurringAllocationRule,
  type PayrollRecurringCalculationKind,
  type PayrollRecurringComponent,
  type PayrollRecurringComponentPayload,
} from '@/api/payroll'
import { payrollAbsenceApi } from '@/api/payrollAbsences'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { apiErrorMessage } from '@/api/errors'
import PayrollFileDropzone, {
  type PayrollFileRejectReason,
} from '@/components/payroll/PayrollFileDropzone.vue'
import { btnFilled, btnOutline, btnOutlineSm, disabledTitle, BTN_DISABLED_NOTE, ICONS } from '@/components/ui/buttonStyles'
import CodeNameFields from '@/components/ui/CodeNameFields.vue'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import PayrollPersonSearchSelect from '@/components/payroll/PayrollPersonSearchSelect.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import PaginationBar from '@/components/ui/PaginationBar.vue'
import PayrollFocusNotice from '@/components/payroll/PayrollFocusNotice.vue'
import PayrollRiskySavingsPanel from '@/components/payroll/PayrollRiskySavingsPanel.vue'
import { payrollQueryId, payrollQueryValue } from '@/pages/payroll/payrollAgendaLinks'
import ColumnPicker from '@/components/ui/ColumnPicker.vue'
import DensityToggle from '@/components/ui/DensityToggle.vue'
import { useTablePrefs, type ColumnDef } from '@/composables/useTablePrefs'
import {
  canApplyPayrollImport,
  localPayrollPeriod,
  monthStart,
  parsePayrollAmountToMinor,
  payrollEmploymentOptionsFromContext,
  payrollImportFingerprint,
  payrollImportIssues,
  payrollMinorToInput,
  type PayrollEmploymentOption,
} from '@/pages/payroll/payrollComponentsUi'
// Formátování je sdílené (useFormat) — místní kopie se rozcházely v locale i tvaru.
import { formatMoneyMinor } from '@/composables/useFormat'

type Tab = 'catalog' | 'recurring' | 'inputs' | 'risky_savings' | 'import'

interface ComponentForm extends Omit<PayrollComponentPayload, 'annual_limit_minor'> {
  annual_limit: string
}

interface RecurringForm {
  employment_id: number | null
  component_id: number | null
  calculation_kind: PayrollRecurringCalculationKind
  amount: string
  rate_percent: string
  valid_from: string
  valid_to: string
  allocation_rule: PayrollRecurringAllocationRule
  maximum_amount: string
  note: string
  is_active: boolean
}

interface InputForm {
  employee_id: number | null
  employment_id: number | null
  component_id: number | null
  source_period: string
  amount: string
  quantity: string
  external_id: string
}

const { t } = useI18n()
const auth = useAuthStore()
const toast = useToast()
const route = useRoute()
const router = useRouter()
const TABS: readonly Tab[] = ['catalog', 'recurring', 'inputs', 'risky_savings', 'import']
const requestedTab = payrollQueryValue(route.query, 'tab')
const activeTab = ref<Tab>(
  requestedTab !== null && (TABS as readonly string[]).includes(requestedTab)
    ? requestedTab as Tab
    : 'inputs',
)
const period = ref(localPayrollPeriod())
const loading = ref(false)
/*
 * Selhalo načtení? Pak o obsahu nevíme NIC — a to je něco jiného než „nic tu
 * není". Toast s chybou za pár vteřin zmizí a bez tohohle příznaku by na
 * obrazovce zůstal prázdný stav, který lže.
 */
const loadFailed = ref(false)
const saving = ref(false)
const components = ref<PayrollComponent[]>([])
const recurring = ref<PayrollRecurringComponent[]>([])
const RECURRING_COLUMNS: ColumnDef[] = [
  { key: 'employment', labelKey: 'payroll.components.fields.employment', required: true },
  { key: 'component', labelKey: 'payroll.components.fields.component' },
  { key: 'calculation', labelKey: 'payroll.components.fields.calculation', required: true },
  { key: 'validity', labelKey: 'payroll.components.fields.validity' },
  { key: 'status', labelKey: 'payroll.components.fields.status' },
  { key: 'actions', labelKey: 'payroll.components.fields.actions', required: true },
]
const recurringTbl = useTablePrefs('payroll-recurring-components', RECURRING_COLUMNS)
const recurringPageSize = 25
const recurringTotal = ref(0)
const recurringOffset = ref(0)
const recurringPage = computed(() =>
  Math.floor(recurringOffset.value / recurringPageSize) + 1)
const inputs = ref<PayrollInput[]>([])
const INPUT_COLUMNS: ColumnDef[] = [
  { key: 'employment', labelKey: 'payroll.components.fields.employment', required: true },
  { key: 'component', labelKey: 'payroll.components.fields.component', required: true },
  { key: 'amount', labelKey: 'payroll.components.fields.amount', required: true },
  { key: 'source', labelKey: 'payroll.components.fields.source' },
  { key: 'status', labelKey: 'payroll.components.fields.status' },
  { key: 'external_id', labelKey: 'payroll.components.fields.external_id', defaultHidden: true },
  { key: 'actions', labelKey: 'payroll.components.fields.actions', required: true },
]
const inputsTbl = useTablePrefs('payroll-inputs', INPUT_COLUMNS)
const inputsPageSize = 25
const inputsTotal = ref(0)
const inputsOffset = ref(0)
const inputsPage = computed(() =>
  Math.floor(inputsOffset.value / inputsPageSize) + 1)
const employments = ref<PayrollEmploymentOption[]>([])
/**
 * Zúžení na jeden vztah z odkazu na kartě zaměstnance (`?employment=12`),
 * volitelně i s výchozí záložkou (`?tab=recurring`).
 *
 * Zužuje SERVER — opakované složky (`recurring-components?employment_id=`)
 * i mzdové vstupy za období (`inputs?employment_id=`), v obou případech ve
 * stejném dotazu jako stránkování. Dokud vstupy filtroval prohlížeč nad načtenou
 * stránkou, vztah z jiné strany se tiše neprojevil. Neplatné id nic nezúží:
 * odkaz z bookmarku je slepý, ne rozbitý.
 */
const focusEmploymentId = ref<number | null>(payrollQueryId(route.query, 'employment'))
const focusName = computed(() => {
  const id = focusEmploymentId.value
  if (id === null) return null
  const employment = employments.value.find(item => item.employment_id === id)
  return employment === undefined
    ? t('payroll.agendas.focus.unknown_person')
    : `${employment.full_name} · ${employment.code}`
})
/**
 * Server zúžení uplatnil a nezbylo nic — ani opakovaná složka, ani vstup.
 * Tiché prázdno by tvrdilo „ten člověk tu nic nemá", i když je zúžení jen slepé.
 */
const focusMissing = computed(() =>
  focusEmploymentId.value !== null && !loading.value && !loadFailed.value
  && (
    (activeTab.value === 'inputs' && inputsTotal.value === 0)
    || (activeTab.value === 'recurring' && recurringTotal.value === 0)
  ))
const chartAccounts = ref<PayrollAccountOption[]>([])
const componentError = ref('')
const jmhzError = ref('')
const recurringError = ref('')
const inputError = ref('')
const importApiError = ref('')

const componentEditorOpen = ref(false)
const jmhzEditorOpen = ref(false)
const editingComponent = ref<PayrollComponent | null>(null)
const editingJmhzComponent = ref<PayrollComponent | null>(null)
const jmhzTargets = ref<PayrollComponentJmhzTarget[]>([])
const jmhzMappings = ref<Record<number, PayrollComponentJmhzMappingState>>({})
const jmhzTargetId = ref<string | null>(null)
const jmhzLoading = ref(true)
const componentForm = ref<ComponentForm>(newComponentForm())
/** Obsazené kódy pro automatické odvození kódu z názvu (kolize → `_2`, `_3`). */
const takenComponentCodes = computed(() => components.value
  .filter(item => item.id !== editingComponent.value?.id)
  .map(item => item.code))
const recurringEditorOpen = ref(false)
const editingRecurring = ref<PayrollRecurringComponent | null>(null)
const recurringEmployeeId = ref<number | null>(null)
const recurringForm = ref<RecurringForm>(newRecurringForm())
const inputEditorOpen = ref(false)
const editingInput = ref<PayrollInput | null>(null)
const inputForm = ref<InputForm>(newInputForm())
const inputPreview = ref<PayrollInputPreview | null>(null)
const inputPreviewFingerprint = ref<string | null>(null)

const importName = ref('')
const importFormat = ref<'csv' | 'xlsx'>('csv')
const importContent = ref('')
const importFileError = ref('')
const importPreview = ref<PayrollInputImportPreview | null>(null)
const importPreviewFingerprint = ref<string | null>(null)
const importResult = ref<PayrollInputImportResult | null>(null)

const canWrite = computed(() => auth.canWrite('payroll.inputs.write'))
const canApprove = computed(() => auth.canWrite('payroll.approve'))
const activeRegularComponents = computed(() =>
  components.value.filter(component => component.is_active && component.frequency_kind === 'regular'),
)
const activeOneOffComponents = computed(() =>
  components.value.filter(component => component.is_active && component.frequency_kind === 'one_off'),
)
const personOptions = computed(() => Array.from(
  new Map(employments.value.map(item => [item.employee_id, {
    value: item.employee_id,
    label: item.full_name,
  }])).values(),
))
function employmentOptionsFor(employeeId: number | null) {
  return employments.value
    .filter(item => item.employee_id === employeeId)
    .map(item => ({
      value: item.employment_id,
      label: relationLabel(item.relation_type),
      secondary: item.code,
    }))
}
const recurringEmploymentOptions = computed(() =>
  employmentOptionsFor(recurringEmployeeId.value))
const inputEmploymentOptions = computed(() =>
  employmentOptionsFor(inputForm.value.employee_id))
const regularComponentOptions = computed(() => activeRegularComponents.value.map(item => ({
  value: item.id,
  label: item.name,
  secondary: item.code,
})))
const oneOffComponentOptions = computed(() => activeOneOffComponents.value.map(item => ({
  value: item.id,
  label: item.name,
  secondary: item.code,
})))
const debitAccountOptions = computed(() => accountOptions('expense'))
const creditAccountOptions = computed(() => accountOptions('liability'))
const importPayload = computed<PayrollInputImportPayload>(() => ({
  period: period.value,
  format: importFormat.value,
  source_name: importName.value,
  content_base64: importContent.value,
}))
const importFingerprint = computed(() => payrollImportFingerprint(importPayload.value))
const importCanApply = computed(() =>
  importResult.value === null
  && canApplyPayrollImport(importPreview.value, importPreviewFingerprint.value, importFingerprint.value),
)
const importIssues = computed(() => payrollImportIssues(importPreview.value))
const manualInputPayload = computed<PayrollInputPayload | null>(() => {
  const amountMinor = parsePayrollAmountToMinor(inputForm.value.amount)
  const quantityMilliunits = parseScaledDecimal(inputForm.value.quantity, 1000)
  if (
    inputForm.value.employee_id === null
    || inputForm.value.employment_id === null
    || inputForm.value.component_id === null
    || amountMinor === null
    || inputForm.value.quantity.trim() !== '' && quantityMilliunits === null
  ) return null
  return {
    employee_id: inputForm.value.employee_id,
    employment_id: inputForm.value.employment_id,
    component_id: inputForm.value.component_id,
    period: period.value,
    source_period: inputForm.value.source_period || null,
    amount_minor: amountMinor,
    quantity_milliunits: quantityMilliunits,
    source_kind: 'manual',
    external_id: inputForm.value.external_id.trim() || null,
  }
})
const manualInputFingerprint = computed(() => JSON.stringify(manualInputPayload.value))
const mealEntitlement = computed(() =>
  inputPreview.value?.meal_entitlement
  ?? inputPreview.value?.exemption_basket?.entitlement
  ?? null,
)
const mealEvidenceIncomplete = computed(() => mealEntitlement.value?.complete === false)
const canSaveInput = computed(() =>
  manualInputPayload.value !== null
  && inputPreview.value !== null
  && inputPreviewFingerprint.value === manualInputFingerprint.value
  && inputPreview.value.support_status === 'supported'
  && !inputPreview.value.annual_limit_exceeded,
)

const componentKinds: PayrollComponentKind[] = [
  'base_wage', 'hourly_wage', 'task_wage', 'bonus', 'premium', 'commission',
  'allowance', 'compensation', 'severance', 'competitive_clause', 'backpay',
  'non_cash', 'benefit_meal', 'benefit_vehicle', 'benefit_pension', 'benefit_care',
  'benefit_education', 'benefit_recreation', 'benefit_health', 'benefit_accommodation',
  'risky_savings', 'travel_reimbursement', 'other',
]
const valueKinds: PayrollComponentValueKind[] = ['monetary', 'non_monetary']
const frequencies: PayrollComponentFrequency[] = ['regular', 'one_off']
const taxTreatments: PayrollComponentTaxTreatment[] = ['included', 'exempt', 'withholding_candidate', 'manual_review']
const inclusionTreatments: PayrollComponentInclusion[] = ['included', 'excluded', 'manual_review']
const exemptionBaskets: PayrollBenefitExemptionBasket[] = [
  'non_cash_health', 'non_cash_leisure', 'old_age_savings',
  'meal_per_shift', 'temporary_accommodation',
]
const exemptionBases: PayrollExemptionBasis[] = [
  'not_subject_to_tax', 'statutory_exempt', 'benefit_basket', 'periodic_benefit_limit',
]
const calculationKinds: PayrollRecurringCalculationKind[] = ['fixed_amount', 'employment_gross_basis_points', 'manual_review']
const allocationRules: PayrollRecurringAllocationRule[] = ['full_month', 'calendar_days', 'working_days', 'hours', 'manual_review']

function selectOptions<T extends string>(values: T[], prefix: string) {
  return values.map(value => ({ value, label: t(`${prefix}.${value}`) }))
}

const componentKindOptions = computed(() => selectOptions(componentKinds, 'payroll.components.kind'))
const valueKindOptions = computed(() => selectOptions(valueKinds, 'payroll.components.value_kind'))
const frequencyOptions = computed(() => selectOptions(frequencies, 'payroll.components.frequency'))
const taxTreatmentOptions = computed(() => selectOptions(taxTreatments, 'payroll.components.tax'))
const exemptionBasketOptions = computed(() => exemptionBaskets.map(value => ({
  value,
  label: t(`payroll.components.exemption_basket.${value}`),
})))
const exemptionBasisOptions = computed(() => exemptionBases.map(value => ({
  value,
  label: t(`payroll.components.exemption_basis.${value}`),
})))
const inclusionTreatmentOptions = computed(() => selectOptions(inclusionTreatments, 'payroll.components.inclusion'))
/*
 * Proč nejde uložit mapování na JMHZ. Obě překážky mají jasné vyústění:
 * buď chybí cílový atribut (vyberte ho v poli nad tlačítkem), nebo jde
 * o mapování ze starší verze balíčku, které se nedá přepsat.
 */
const jmhzSaveBlockedReason = computed<string | null>(() => {
  const component = editingJmhzComponent.value
  if (component === null) return null
  const mapping = jmhzState(component).mapping
  if (mapping?.is_active && !mapping.is_current_package) {
    return t('payroll.components.jmhz.legacy_mapping')
  }
  if (!jmhzTargetId.value) return t('payroll.components.jmhz.target_required')
  return null
})

const jmhzTargetOptions = computed(() => jmhzTargets.value.map(target => ({
  value: target.attribute_id,
  label: `${target.attribute_id} · ${target.name}`,
  secondary: t(`payroll.components.jmhz.role.${target.aggregation_role}`),
})))
const selectedJmhzTarget = computed(() =>
  jmhzTargets.value.find(target => target.attribute_id === jmhzTargetId.value) ?? null,
)
const calculationKindOptions = computed(() => selectOptions(calculationKinds, 'payroll.components.calculation'))
const allocationRuleOptions = computed(() => selectOptions(allocationRules, 'payroll.components.allocation'))

function parseScaledDecimal(value: string, scale: number): number | null {
  const normalized = value.trim().replace(',', '.')
  if (normalized === '') return null
  if (!/^-?(?:\d+|\d*\.\d+)$/.test(normalized)) return null
  const result = Number(normalized) * scale
  const rounded = Math.round(result)
  if (
    !Number.isFinite(result)
    || !Number.isSafeInteger(rounded)
    || Math.abs(result - rounded) > 1e-8
  ) return null
  return rounded
}

function scaledDecimalToInput(value: number | null, scale: number): string {
  return value === null ? '' : String(value / scale)
}

function accountOptions(type: PayrollAccountOption['account_type']) {
  return chartAccounts.value
    .filter(account => account.is_active && account.account_type === type)
    .sort((left, right) => left.account_code.localeCompare(right.account_code))
    .map(account => ({
      value: account.account_code.trim().toUpperCase(),
      label: account.account_code.trim().toUpperCase(),
      secondary: account.name,
    }))
}

function selectedAccountOption(code: string | null) {
  if (!code) return null
  const normalized = code.trim().toUpperCase()
  const account = chartAccounts.value.find(item => item.account_code.trim().toUpperCase() === normalized)
  return {
    value: normalized,
    label: normalized,
    secondary: account?.name,
  }
}

function newComponentForm(): ComponentForm {
  return {
    code: '',
    name: '',
    component_kind: 'bonus',
    value_kind: 'monetary',
    frequency_kind: 'one_off',
    tax_treatment: 'included',
    social_participation_treatment: 'included',
    social_treatment: 'included',
    health_participation_treatment: 'included',
    health_treatment: 'included',
    average_earning_treatment: 'included',
    enforcement_treatment: 'included',
    jmhz_treatment: 'included',
    statistics_treatment: 'included',
    accounting_debit_code: null,
    accounting_credit_code: null,
    annual_limit: '',
    exemption_basket: null,
    exemption_basis: null,
    valid_from: monthStart(period.value),
    valid_to: null,
    is_active: true,
  }
}

/**
 * Předvybraný vztah z odkazu na kartě zaměstnance má přednost před prvním
 * v nabídce — jinak by odkaz „Opakované složky u Nováka" nabídl formulář
 * na někoho jiného.
 */
function defaultEmployment(): PayrollEmploymentOption | undefined {
  const focused = focusEmploymentId.value === null
    ? undefined
    : employments.value.find(item => item.employment_id === focusEmploymentId.value)
  return focused ?? employments.value[0]
}

function newRecurringForm(): RecurringForm {
  return {
    employment_id: defaultEmployment()?.employment_id ?? null,
    component_id: components.value.find(component =>
      component.is_active && component.frequency_kind === 'regular')?.id ?? null,
    calculation_kind: 'fixed_amount',
    amount: '',
    rate_percent: '',
    valid_from: monthStart(period.value),
    valid_to: '',
    allocation_rule: 'full_month',
    maximum_amount: '',
    note: '',
    is_active: true,
  }
}

function newInputForm(): InputForm {
  const employment = defaultEmployment()
  return {
    employee_id: employment?.employee_id ?? null,
    employment_id: employment?.employment_id ?? null,
    component_id: components.value.find(component =>
      component.is_active && component.frequency_kind === 'one_off')?.id ?? null,
    source_period: '',
    amount: '',
    quantity: '',
    external_id: '',
  }
}

function formatMoney(value: number | null): string {
  return formatMoneyMinor(value)
}

// Hlavní údaj je název vztahu, ne jeho technický kód. Dva vztahy téhož člověka
// se jinak v seznamu lišily jen řetězci typu „legacy" a „ZAM-2".
function relationLabel(type: string): string {
  return t(`payroll.people.relations.${type}`)
}

function selectedEmploymentChanged(target: InputForm | RecurringForm) {
  if (!('employee_id' in target)) return
  const selected = employments.value.find(item => item.employment_id === target.employment_id)
  target.employee_id = selected?.employee_id ?? null
}

function selectInputEmployment(value: number | null) {
  inputForm.value.employment_id = value
  selectedEmploymentChanged(inputForm.value)
}

function selectRecurringEmployee(value: number | null) {
  recurringEmployeeId.value = value
  const available = employments.value.filter(item => item.employee_id === value)
  if (!available.some(item => item.employment_id === recurringForm.value.employment_id)) {
    recurringForm.value.employment_id = available[0]?.employment_id ?? null
  }
}

function selectInputEmployee(value: number | null) {
  inputForm.value.employee_id = value
  const available = employments.value.filter(item => item.employee_id === value)
  if (!available.some(item => item.employment_id === inputForm.value.employment_id)) {
    inputForm.value.employment_id = available[0]?.employment_id ?? null
  }
  inputPreview.value = null
}

function setInclusionTreatment(
  field: 'social_participation_treatment' | 'social_treatment'
    | 'health_participation_treatment' | 'health_treatment'
    | 'average_earning_treatment' | 'enforcement_treatment'
    | 'jmhz_treatment' | 'statistics_treatment',
  value: PayrollComponentInclusion | null,
) {
  if (value !== null) componentForm.value[field] = value
}

// Jeden požadavek na celou nabídku vztahů. Dřív jich bylo 1 + počet zaměstnanců,
// protože se ke každé osobě dotahoval její detail — u padesáti lidí padesát jedna
// požadavků při každém otevření stránky, a to jen kvůli názvu a kódu vztahu.
async function loadEmploymentOptions() {
  employments.value = payrollEmploymentOptionsFromContext(await payrollAbsenceApi.context())
}

async function loadJmhzConfiguration() {
  jmhzLoading.value = true
  try {
    const [targets, mappings] = await Promise.all([
      payrollApi.componentJmhzTargets(),
      payrollApi.componentJmhzMappings(),
    ])
    jmhzTargets.value = targets.targets
    setJmhzMappings(mappings)
  } catch (error: any) {
    toast.error(apiErrorMessage(error, t('payroll.components.jmhz.load_failed')))
  } finally {
    jmhzLoading.value = false
  }
}

async function load() {
  loading.value = true
  loadFailed.value = false
  try {
    if (activeTab.value === 'catalog') {
      // Nečekáme na volitelný katalog JMHZ: běžná správa složek musí zůstat
      // použitelná i při dočasně nedostupném podkladu pro mapování.
      void loadJmhzConfiguration()
      const catalog = await payrollApi.components()
      components.value = catalog
    } else if (activeTab.value === 'recurring') {
      const [catalog] = await Promise.all([
        payrollApi.components(),
        loadEmploymentOptions(),
        loadRecurringPage(),
      ])
      components.value = catalog
    } else if (activeTab.value === 'inputs') {
      const [catalog] = await Promise.all([
        payrollApi.components(),
        loadEmploymentOptions(),
        loadInputsPage(),
      ])
      components.value = catalog
    } else if (activeTab.value === 'risky_savings') {
      await loadEmploymentOptions()
    }
  } catch (error: any) {
    // Aktivní obsah se nemaže — po výpadku sítě by prázdný stav lhal, že v
    // právě otevřené agendě nic není.
    loadFailed.value = true
    toast.error(apiErrorMessage(error, t('payroll.components.load_failed')))
  } finally {
    loading.value = false
  }
}

function setJmhzMappings(states: PayrollComponentJmhzMappingState[]) {
  jmhzMappings.value = Object.fromEntries(states.map(state => [state.component_id, state]))
}

function jmhzState(component: PayrollComponent): PayrollComponentJmhzMappingState {
  return jmhzMappings.value[component.id] ?? {
    component_id: component.id,
    jmhz_treatment: component.jmhz_treatment,
    status: component.jmhz_treatment === 'included'
      ? 'missing'
      : component.jmhz_treatment === 'manual_review' ? 'manual_review' : 'excluded',
    mapping: null,
  }
}

function jmhzBadgeClass(component: PayrollComponent): string {
  return {
    configured: 'bg-success-50 text-success-600',
    missing: 'bg-warning-50 text-warning-700',
    excluded: 'bg-neutral-100 text-neutral-600',
    manual_review: 'bg-warning-50 text-warning-700',
  }[jmhzState(component).status]
}

function openJmhzMapping(component: PayrollComponent) {
  editingJmhzComponent.value = component
  jmhzTargetId.value = jmhzState(component).mapping?.is_active
    ? jmhzState(component).mapping?.target_attribute_id ?? null
    : null
  jmhzError.value = ''
  jmhzEditorOpen.value = true
}

async function saveJmhzMapping() {
  const component = editingJmhzComponent.value
  const current = component ? jmhzState(component).mapping : null
  if (current?.is_active && !current.is_current_package) {
    jmhzError.value = t('payroll.components.jmhz.legacy_mapping')
    return
  }
  if (!component || !jmhzTargetId.value) {
    jmhzError.value = t('payroll.components.jmhz.target_required')
    return
  }
  saving.value = true
  jmhzError.value = ''
  try {
    const state = await payrollApi.saveComponentJmhzMapping(
      component.id,
      jmhzTargetId.value,
      current?.row_version ?? null,
    )
    jmhzMappings.value = { ...jmhzMappings.value, [component.id]: state }
    jmhzEditorOpen.value = false
    toast.success(t('payroll.components.jmhz.saved'))
  } catch (error: any) {
    jmhzError.value = apiErrorMessage(error, t('payroll.components.jmhz.save_failed'))
  } finally {
    saving.value = false
  }
}

async function removeJmhzMapping() {
  const component = editingJmhzComponent.value
  const mapping = component ? jmhzState(component).mapping : null
  if (!component || !mapping?.is_active) return
  if (!window.confirm(t('payroll.components.jmhz.remove_confirm'))) return
  saving.value = true
  jmhzError.value = ''
  try {
    await payrollApi.removeComponentJmhzMapping(component.id, mapping.row_version)
    setJmhzMappings(await payrollApi.componentJmhzMappings())
    jmhzEditorOpen.value = false
    toast.success(t('payroll.components.jmhz.removed'))
  } catch (error: any) {
    jmhzError.value = apiErrorMessage(error, t('payroll.components.jmhz.remove_failed'))
  } finally {
    saving.value = false
  }
}

async function reloadPeriod() {
  loading.value = true
  resetImport()
  inputPreview.value = null
  // Jiné období = jiný seznam, takže stránkování musí zpátky na začátek.
  inputsOffset.value = 0
  try {
    await loadInputsPage()
  } catch (error: any) {
    toast.error(apiErrorMessage(error, t('payroll.components.load_failed')))
  } finally {
    loading.value = false
  }
}

async function openNewComponent() {
  try {
    chartAccounts.value = await payrollApi.accountOptions()
  } catch (error: any) {
    toast.error(apiErrorMessage(error, t('payroll.components.load_failed')))
    return
  }
  editingComponent.value = null
  componentForm.value = newComponentForm()
  componentError.value = ''
  componentEditorOpen.value = true
}

async function editComponent(component: PayrollComponent) {
  try {
    chartAccounts.value = await payrollApi.accountOptions()
  } catch (error: any) {
    toast.error(apiErrorMessage(error, t('payroll.components.load_failed')))
    return
  }
  editingComponent.value = component
  componentForm.value = {
    code: component.code,
    name: component.name,
    component_kind: component.component_kind,
    value_kind: component.value_kind,
    frequency_kind: component.frequency_kind,
    tax_treatment: component.tax_treatment,
    social_participation_treatment: component.social_participation_treatment,
    social_treatment: component.social_treatment,
    health_participation_treatment: component.health_participation_treatment,
    health_treatment: component.health_treatment,
    average_earning_treatment: component.average_earning_treatment,
    enforcement_treatment: component.enforcement_treatment,
    jmhz_treatment: component.jmhz_treatment,
    statistics_treatment: component.statistics_treatment,
    accounting_debit_code: component.accounting_debit_code,
    accounting_credit_code: component.accounting_credit_code,
    annual_limit: payrollMinorToInput(component.annual_limit_minor),
    exemption_basket: component.exemption_basket,
    exemption_basis: component.exemption_basis,
    valid_from: component.valid_from,
    valid_to: component.valid_to,
    is_active: component.is_active,
  }
  componentError.value = ''
  componentEditorOpen.value = true
}

function componentPayload(): PayrollComponentPayload | null {
  const limit = componentForm.value.annual_limit === ''
    ? null
    : parsePayrollAmountToMinor(componentForm.value.annual_limit)
  // Osvobození bez uvedeného podkladu neprojde mzdovým během — ať to uživatel
  // zjistí tady, ne až při uzávěrce měsíce.
  const basisMissing = componentForm.value.tax_treatment === 'exempt'
    && componentForm.value.exemption_basis === null
  // Podklad, který stojí na zmrazeném rozpadu koše, bez zařazení do koše nedává
  // smysl — a backend by ho stejně odmítl.
  const basketMissing = ['benefit_basket', 'periodic_benefit_limit']
    .includes(componentForm.value.exemption_basis ?? '')
    && componentForm.value.exemption_basket === null
  if (
    !componentForm.value.code.trim()
    || !componentForm.value.name.trim()
    || componentForm.value.annual_limit !== '' && (limit === null || limit <= 0)
    || basisMissing
    || basketMissing
  ) {
    return null
  }
  return {
    code: componentForm.value.code.trim().toUpperCase(),
    name: componentForm.value.name.trim(),
    component_kind: componentForm.value.component_kind,
    value_kind: componentForm.value.value_kind,
    frequency_kind: componentForm.value.frequency_kind,
    tax_treatment: componentForm.value.tax_treatment,
    social_participation_treatment:
      componentForm.value.social_participation_treatment,
    social_treatment: componentForm.value.social_treatment,
    health_participation_treatment:
      componentForm.value.health_participation_treatment,
    health_treatment: componentForm.value.health_treatment,
    average_earning_treatment: componentForm.value.average_earning_treatment,
    enforcement_treatment: componentForm.value.enforcement_treatment,
    jmhz_treatment: componentForm.value.jmhz_treatment,
    statistics_treatment: componentForm.value.statistics_treatment,
    accounting_debit_code: componentForm.value.accounting_debit_code?.trim() || null,
    accounting_credit_code: componentForm.value.accounting_credit_code?.trim() || null,
    annual_limit_minor: limit,
    exemption_basket: componentForm.value.exemption_basket,
    exemption_basis: componentForm.value.tax_treatment === 'exempt'
      ? componentForm.value.exemption_basis
      : null,
    valid_from: componentForm.value.valid_from,
    valid_to: componentForm.value.valid_to || null,
    is_active: componentForm.value.is_active,
  }
}

async function saveComponent() {
  const payload = componentPayload()
  if (!payload) {
    componentError.value = t('payroll.components.validation_failed')
    return
  }
  componentError.value = ''
  saving.value = true
  try {
    if (editingComponent.value) {
      await payrollApi.updateComponent(editingComponent.value.id, editingComponent.value.row_version, payload)
    } else {
      await payrollApi.createComponent(payload)
    }
    components.value = await payrollApi.components()
    setJmhzMappings(await payrollApi.componentJmhzMappings())
    componentEditorOpen.value = false
    toast.success(t('payroll.components.catalog.saved'))
  } catch (error: any) {
    componentError.value = apiErrorMessage(error, t('payroll.components.save_failed'))
  } finally {
    saving.value = false
  }
}

async function deleteComponent(component: PayrollComponent) {
  if (!window.confirm(t('payroll.components.catalog.delete_confirm', {
    name: component.name,
  }))) return
  saving.value = true
  try {
    await payrollApi.deleteComponent(component.id, component.row_version)
    components.value = components.value.filter(item => item.id !== component.id)
    const mappings = { ...jmhzMappings.value }
    delete mappings[component.id]
    jmhzMappings.value = mappings
    if (editingComponent.value?.id === component.id) componentEditorOpen.value = false
    if (editingJmhzComponent.value?.id === component.id) jmhzEditorOpen.value = false
    toast.success(t('payroll.components.catalog.deleted'))
  } catch (error: any) {
    toast.error(apiErrorMessage(error, t('payroll.components.catalog.delete_failed')))
  } finally {
    saving.value = false
  }
}

function openNewRecurring() {
  editingRecurring.value = null
  recurringForm.value = newRecurringForm()
  recurringEmployeeId.value = employments.value.find(
    item => item.employment_id === recurringForm.value.employment_id,
  )?.employee_id ?? null
  recurringError.value = ''
  recurringEditorOpen.value = true
}

function editRecurring(item: PayrollRecurringComponent) {
  editingRecurring.value = item
  recurringEmployeeId.value = item.employee_id
  recurringForm.value = {
    employment_id: item.employment_id,
    component_id: item.component_id,
    calculation_kind: item.calculation_kind,
    amount: payrollMinorToInput(item.amount_minor),
    rate_percent: scaledDecimalToInput(item.rate_basis_points, 100),
    valid_from: item.valid_from,
    valid_to: item.valid_to ?? '',
    allocation_rule: item.allocation_rule,
    maximum_amount: payrollMinorToInput(item.maximum_amount_minor),
    note: item.note ?? '',
    is_active: item.is_active,
  }
  recurringError.value = ''
  recurringEditorOpen.value = true
}

function recurringPayload(): PayrollRecurringComponentPayload | null {
  const form = recurringForm.value
  if (form.employment_id === null || form.component_id === null) return null
  const amount = form.calculation_kind === 'fixed_amount'
    ? parsePayrollAmountToMinor(form.amount)
    : null
  const maximum = form.maximum_amount === '' ? null : parsePayrollAmountToMinor(form.maximum_amount)
  const rateBasisPoints = parseScaledDecimal(form.rate_percent, 100)
  if (
    form.calculation_kind === 'fixed_amount' && amount === null
    || form.calculation_kind === 'employment_gross_basis_points' && (rateBasisPoints === null || rateBasisPoints < 1 || rateBasisPoints > 10000)
    || maximum !== null && maximum <= 0
    || form.maximum_amount !== '' && maximum === null
  ) return null
  return {
    employment_id: form.employment_id,
    component_id: form.component_id,
    calculation_kind: form.calculation_kind,
    amount_minor: amount,
    rate_basis_points: form.calculation_kind === 'employment_gross_basis_points' ? rateBasisPoints : null,
    valid_from: form.valid_from,
    valid_to: form.valid_to || null,
    allocation_rule: form.allocation_rule,
    maximum_amount_minor: maximum,
    note: form.note.trim() || null,
    is_active: form.is_active,
  }
}

async function loadRecurringPage() {
  // Zúžení se posílá i při listování stránkami — bez něj se po prvním kliknutí
  // na pager tiše rozšířil seznam zpátky na celou firmu.
  let page = await payrollApi.recurringComponents(
    focusEmploymentId.value ?? undefined,
    { limit: recurringPageSize, offset: recurringOffset.value },
  )
  if (page.recurring_components.length === 0
    && page.total > 0
    && recurringOffset.value >= page.total
  ) {
    recurringOffset.value = Math.max(
      0,
      (Math.ceil(page.total / recurringPageSize) - 1) * recurringPageSize,
    )
    page = await payrollApi.recurringComponents(
      focusEmploymentId.value ?? undefined,
      { limit: recurringPageSize, offset: recurringOffset.value },
    )
  }
  recurring.value = page.recurring_components
  recurringTotal.value = page.total
}

async function loadInputsPage() {
  const focused = focusEmploymentId.value ?? undefined
  let page = await payrollApi.inputs(
    period.value,
    { limit: inputsPageSize, offset: inputsOffset.value },
    focused,
  )
  // Zrušení posledního vstupu na poslední straně by jinak nechalo uživatele
  // stát na straně, která už neexistuje — prázdná tabulka a pager bez cesty zpět.
  if (page.items.length === 0 && page.total > 0 && inputsOffset.value >= page.total) {
    inputsOffset.value = Math.max(
      0,
      (Math.ceil(page.total / inputsPageSize) - 1) * inputsPageSize,
    )
    page = await payrollApi.inputs(
      period.value,
      { limit: inputsPageSize, offset: inputsOffset.value },
      focused,
    )
  }
  inputs.value = page.items
  inputsTotal.value = page.total
}

function goToInputsPage(nextPage: number) {
  inputsOffset.value = Math.max(0, (nextPage - 1) * inputsPageSize)
  void loadInputsPage()
}

function goToRecurringPage(nextPage: number) {
  recurringOffset.value = Math.max(0, (nextPage - 1) * recurringPageSize)
  void loadRecurringPage()
}

async function saveRecurring() {
  const payload = recurringPayload()
  if (!payload) {
    recurringError.value = t('payroll.components.validation_failed')
    return
  }
  recurringError.value = ''
  saving.value = true
  try {
    if (editingRecurring.value) {
      await payrollApi.updateRecurringComponent(editingRecurring.value.id, editingRecurring.value.row_version, payload)
    } else {
      await payrollApi.createRecurringComponent(payload)
    }
    await loadRecurringPage()
    recurringEditorOpen.value = false
    toast.success(t('payroll.components.recurring.saved'))
  } catch (error: any) {
    recurringError.value = apiErrorMessage(error, t('payroll.components.save_failed'))
  } finally {
    saving.value = false
  }
}

async function deleteRecurring(item: PayrollRecurringComponent) {
  if (!window.confirm(t('payroll.components.recurring.delete_confirm', {
    component: item.component_name,
    employee: item.employee_name,
  }))) return
  saving.value = true
  recurringError.value = ''
  try {
    await payrollApi.deleteRecurringComponent(item.id, item.row_version)
    await loadRecurringPage()
    if (editingRecurring.value?.id === item.id) recurringEditorOpen.value = false
    toast.success(t('payroll.components.recurring.deleted'))
  } catch (error: any) {
    recurringError.value = apiErrorMessage(
      error,
      t('payroll.components.recurring.delete_failed'),
    )
  } finally {
    saving.value = false
  }
}

async function materializeRecurring() {
  recurringError.value = ''
  saving.value = true
  try {
    const result = await payrollApi.materializeRecurringComponents(period.value)
    toast.success(t('payroll.components.recurring.materialized', {
      created_count: result.created_count,
      replayed_count: result.replayed_count,
      manual_review_count: result.manual_review_count,
    }))
    await loadInputsPage()
    activeTab.value = 'inputs'
  } catch (error: any) {
    recurringError.value = apiErrorMessage(error, t('payroll.components.recurring.materialize_failed'))
  } finally {
    saving.value = false
  }
}

function openNewInput() {
  editingInput.value = null
  inputForm.value = newInputForm()
  inputPreview.value = null
  inputError.value = ''
  inputEditorOpen.value = true
}

function editInput(input: PayrollInput) {
  editingInput.value = input
  inputForm.value = {
    employee_id: input.employee_id,
    employment_id: input.employment_id,
    component_id: input.component_id,
    source_period: input.source_period_start?.slice(0, 7) ?? '',
    amount: payrollMinorToInput(input.amount_minor),
    quantity: scaledDecimalToInput(input.quantity_milliunits, 1000),
    external_id: input.external_id ?? '',
  }
  inputPreview.value = null
  inputError.value = ''
  inputEditorOpen.value = true
}

async function previewManualInput() {
  if (!manualInputPayload.value) {
    inputError.value = t('payroll.components.validation_failed')
    return
  }
  inputError.value = ''
  saving.value = true
  try {
    inputPreview.value = await payrollApi.previewInput(manualInputPayload.value)
    inputPreviewFingerprint.value = manualInputFingerprint.value
  } catch (error: any) {
    inputError.value = apiErrorMessage(error, t('payroll.components.inputs.preview_failed'))
  } finally {
    saving.value = false
  }
}

async function saveInput() {
  if (!manualInputPayload.value || !canSaveInput.value) return
  inputError.value = ''
  saving.value = true
  try {
    if (editingInput.value) {
      await payrollApi.updateInput(editingInput.value.id, editingInput.value.row_version, manualInputPayload.value)
    } else {
      await payrollApi.createInput(manualInputPayload.value)
    }
    await loadInputsPage()
    inputEditorOpen.value = false
    toast.success(t('payroll.components.inputs.saved'))
  } catch (error: any) {
    inputError.value = apiErrorMessage(error, t('payroll.components.save_failed'))
  } finally {
    saving.value = false
  }
}

async function approveInput(input: PayrollInput) {
  inputError.value = ''
  saving.value = true
  try {
    await payrollApi.approveInput(input.id, input.row_version)
    await loadInputsPage()
    toast.success(t('payroll.components.inputs.approved'))
  } catch (error: any) {
    inputError.value = apiErrorMessage(error, t('payroll.components.inputs.approve_failed'))
  } finally {
    saving.value = false
  }
}

/*
 * Kolik konceptů drží mzdový běh. Počítá se ze zobrazené stránky, ale
 * schvaluje se celé období — proto se v tlačítku ukazuje počet ze serveru
 * až po akci, ne dopředná domněnka o zbytku seznamu.
 */
const draftInputCount = computed(() =>
  inputs.value.filter(input => input.status === 'draft').length)

/**
 * Schválit všechny koncepty období najednou.
 *
 * Po jednom to je při 500 zaměstnancích zhruba tisíc kliknutí na obrazovce,
 * kam uživatel přišel jen kvůli blokátoru `draft_inputs_present`. Server si
 * dávku poskládá sám ze všech konceptů měsíce — ne jen z právě zobrazené
 * stránky, protože blokuje běh celý měsíc, ne jedna stránka.
 */
async function approveAllInputs() {
  inputError.value = ''
  saving.value = true
  try {
    const result = await payrollApi.approveInputsBatch({ period: period.value })
    await loadInputsPage()
    if (result.failed.length > 0) {
      // Konkrétní důvod, ne „nepodařilo se": u benefitů to bývá překročený
      // roční limit nebo neuzavřená docházka a uživatel s tím musí něco udělat.
      inputError.value = t('payroll.components.inputs.approve_all_partial', {
        approved: result.approved.length,
        failed: result.failed.length,
        reason: result.failed[0].message,
      })
      return
    }
    toast.success(t('payroll.components.inputs.approve_all_done', {
      count: result.approved.length,
    }))
  } catch (error: any) {
    inputError.value = apiErrorMessage(error, t('payroll.components.inputs.approve_failed'))
  } finally {
    saving.value = false
  }
}

/**
 * Zrušení vstupu POJMENUJE, koho a čeho se týká.
 *
 * Undo toast tu nejde: zrušený vstup server neobnoví a založit ho znovu by
 * narazilo na `external_id` původního řádku, který v evidenci zůstává.
 * Dotaz proto zůstává, ale s osobou, složkou a obdobím — nad seznamem
 * padesáti vstupů „Opravdu zrušit?" neříká nic.
 */
async function cancelInput(input: PayrollInput) {
  if (!window.confirm(t('payroll.components.inputs.cancel_confirm', {
    name: input.employee_name,
    component: input.component_name,
    period: input.period_start.slice(0, 7),
  }))) return
  inputError.value = ''
  saving.value = true
  try {
    await payrollApi.cancelInput(input.id, input.row_version)
    await loadInputsPage()
    if (editingInput.value?.id === input.id) {
      inputEditorOpen.value = false
      editingInput.value = null
    }
    toast.success(t('payroll.components.inputs.cancelled'))
  } catch (error: any) {
    inputError.value = apiErrorMessage(error, t('payroll.components.inputs.cancel_failed'))
  } finally {
    saving.value = false
  }
}

/** Čerpá schválený vstup roční koš osvobození § 6 odst. 9 ZDP? Jen ten jde stornovat. */
function canReverseBenefit(input: PayrollInput): boolean {
  return input.status === 'approved' && !!input.benefit_basket
}

async function reverseBenefitInput(input: PayrollInput) {
  const reason = window.prompt(t('payroll.components.inputs.reverse_benefit_reason'))
  if (reason === null || reason.trim() === '') return
  inputError.value = ''
  saving.value = true
  try {
    await payrollApi.reverseBenefitInput(input.id, input.row_version, reason.trim())
    await loadInputsPage()
    toast.success(t('payroll.components.inputs.benefit_reversed'))
  } catch (error: any) {
    inputError.value = apiErrorMessage(
      error,
      t('payroll.components.inputs.reverse_benefit_failed'),
    )
  } finally {
    saving.value = false
  }
}

function resetImport() {
  importPreview.value = null
  importPreviewFingerprint.value = null
  importResult.value = null
  importApiError.value = ''
}

function clearImportSelection() {
  importName.value = ''
  importContent.value = ''
  resetImport()
}

async function fileAsBase64(file: File): Promise<string> {
  return await new Promise((resolve, reject) => {
    const reader = new FileReader()
    reader.onerror = () => reject(reader.error ?? new Error('file_read_failed'))
    reader.onload = () => {
      const result = String(reader.result ?? '')
      const separator = result.indexOf(',')
      resolve(separator >= 0 ? result.slice(separator + 1) : result)
    }
    reader.readAsDataURL(file)
  })
}

async function loadImportFile(file: File) {
  const fileName = file.name.toLowerCase()
  importFileError.value = ''
  importName.value = file.name
  importFormat.value = fileName.endsWith('.xlsx') ? 'xlsx' : 'csv'
  importContent.value = ''
  resetImport()
  try {
    importContent.value = await fileAsBase64(file)
  } catch {
    clearImportSelection()
    importFileError.value = t('payroll.components.import.read_failed')
    toast.error(importFileError.value)
  }
}

function rejectImportFile(reason: PayrollFileRejectReason) {
  clearImportSelection()
  importFileError.value = t(`payroll.components.import.${reason}`)
  toast.error(importFileError.value)
}

async function previewImport() {
  importApiError.value = ''
  saving.value = true
  try {
    const fingerprint = importFingerprint.value
    importPreview.value = await payrollApi.previewInputImport(importPayload.value)
    importPreviewFingerprint.value = fingerprint
    importResult.value = null
  } catch (error: any) {
    importApiError.value = apiErrorMessage(error, t('payroll.components.import.preview_failed'))
  } finally {
    saving.value = false
  }
}

async function applyImport() {
  if (!importCanApply.value) return
  importApiError.value = ''
  saving.value = true
  try {
    importResult.value = await payrollApi.applyInputImport(importPayload.value)
    toast.success(t('payroll.components.import.applied', importResult.value))
    await loadInputsPage()
  } catch (error: any) {
    importApiError.value = apiErrorMessage(error, t('payroll.components.import.apply_failed'))
  } finally {
    saving.value = false
  }
}

watch(manualInputFingerprint, () => {
  if (inputPreviewFingerprint.value !== manualInputFingerprint.value) inputPreview.value = null
})

watch(activeTab, () => {
  void load()
})

function clearFocus() {
  focusEmploymentId.value = null
  // Obojí se zúžením mění obsah, takže obě stránky musí zpět na začátek.
  recurringOffset.value = 0
  inputsOffset.value = 0
  const query = { ...route.query }
  delete query.employment
  void router.replace({ query })
  void load()
}

onMounted(load)
</script>

<template>
  <div class="space-y-6">
    <header class="flex flex-wrap items-start justify-between gap-3">
      <div>
        <h1 class="text-2xl font-semibold text-neutral-900">{{ t('payroll.components.title') }}</h1>
        <p class="mt-1 max-w-3xl text-sm text-neutral-500">{{ t('payroll.components.subtitle') }}</p>
      </div>
      <div class="flex flex-wrap items-end gap-2">
        <label class="block">
          <span class="mb-1 block text-xs font-medium text-neutral-600">{{ t('payroll.components.period') }}</span>
          <input v-model="period" type="month" class="h-9 rounded-md border border-neutral-300 bg-surface px-3 text-sm" @change="reloadPeriod">
        </label>
        <button :class="btnOutline('neutral')" :disabled="loading" @click="load">
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.cycle" /></svg>
          {{ t('payroll.components.reload') }}
        </button>
      </div>
    </header>

    <PayrollFocusNotice
      v-if="focusMissing"
      :name="String(focusEmploymentId)"
      missing
      @clear="clearFocus"
    />
    <PayrollFocusNotice v-else-if="focusName" :name="focusName" @clear="clearFocus" />

    <nav
      class="mb-5 flex flex-wrap gap-1 border-b border-neutral-200"
      :aria-label="t('payroll.components.tabs.label')"
    >
      <button
        v-for="tab in TABS"
        :key="tab"
        type="button"
        class="-mb-px cursor-pointer whitespace-nowrap border-b-2 px-4 py-2 text-sm font-medium transition-colors"
        :class="activeTab === tab
          ? 'border-payroll-600 text-payroll-600'
          : 'border-transparent text-neutral-600 hover:border-neutral-300 hover:text-neutral-900'"
        @click="activeTab = tab"
      >
        {{ t(`payroll.components.tabs.${tab}`) }}
      </button>
    </nav>

    <div v-if="loading" class="space-y-3">
      <div v-for="index in 4" :key="index" class="h-24 animate-pulse rounded-xl bg-neutral-100" />
    </div>

    <EmptyState
      v-else-if="loadFailed"
      variant="failed"
      boxed
      data-test="load-failed"
      :message="t('payroll.components.load_failed_hint')"
      @action="load"
    />

    <template v-else>
      <section v-if="activeTab === 'catalog'" class="space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
          <div>
            <h2 class="text-lg font-semibold text-neutral-900">{{ t('payroll.components.catalog.title') }}</h2>
            <p class="text-sm text-neutral-500">{{ t('payroll.components.catalog.hint') }}</p>
          </div>
          <button v-if="canWrite" :class="btnFilled('primary')" @click="openNewComponent">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.plus" /></svg>
            {{ t('payroll.components.catalog.add') }}
          </button>
        </div>

        <section v-if="componentEditorOpen" data-testid="payroll-component-editor" class="rounded-xl border border-payroll-500/30 bg-payroll-50 p-4 sm:p-6">
          <div class="flex flex-wrap items-start justify-between gap-3">
            <h3 class="font-semibold text-neutral-900">{{ t(editingComponent ? 'payroll.components.catalog.edit' : 'payroll.components.catalog.new') }}</h3>
            <button :class="btnOutline('neutral')" @click="componentEditorOpen = false">
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.x" /></svg>
              {{ t('common.cancel') }}
            </button>
          </div>
          <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <CodeNameFields
              :code="componentForm.code"
              :name="componentForm.name"
              :code-label="t('payroll.components.fields.code')"
              :name-label="t('payroll.components.fields.name')"
              :editing="!!editingComponent"
              :code-disabled="!!editingComponent"
              :code-maxlength="64"
              :name-maxlength="255"
              code-mode="code"
              :taken-codes="takenComponentCodes"
              :code-hint="editingComponent ? undefined : t('payroll.components.fields.code_hint')"
              name-container-class="sm:col-span-2"
              code-testid="payroll-component-code"
              name-testid="payroll-component-name"
              @update:code="componentForm.code = $event.toUpperCase()"
              @update:name="componentForm.name = $event"
            />
            <label class="block"><span class="mb-1 block text-xs text-neutral-600">{{ t('payroll.components.fields.kind') }}</span><SearchableSelect :model-value="componentForm.component_kind" :options="componentKindOptions" :clearable="false" :no-results-label="t('payroll.components.no_results')" accent="payroll" @update:model-value="componentForm.component_kind = $event ?? 'bonus'" /></label>
            <label class="block"><span class="mb-1 block text-xs text-neutral-600">{{ t('payroll.components.fields.value_kind') }}</span><SearchableSelect :model-value="componentForm.value_kind" :options="valueKindOptions" :clearable="false" :no-results-label="t('payroll.components.no_results')" accent="payroll" @update:model-value="componentForm.value_kind = $event ?? 'monetary'" /></label>
            <label class="block"><span class="mb-1 block text-xs text-neutral-600">{{ t('payroll.components.fields.frequency') }}</span><SearchableSelect :model-value="componentForm.frequency_kind" :options="frequencyOptions" :clearable="false" :no-results-label="t('payroll.components.no_results')" accent="payroll" @update:model-value="componentForm.frequency_kind = $event ?? 'one_off'" /></label>
            <label class="block"><span class="mb-1 block text-xs text-neutral-600">{{ t('payroll.components.fields.tax') }}</span><SearchableSelect :model-value="componentForm.tax_treatment" :options="taxTreatmentOptions" :clearable="false" :no-results-label="t('payroll.components.no_results')" accent="payroll" @update:model-value="componentForm.tax_treatment = $event ?? 'included'" /></label>
            <label v-for="field in (['social_participation_treatment','social_treatment','health_participation_treatment','health_treatment','average_earning_treatment','enforcement_treatment','jmhz_treatment','statistics_treatment'] as const)" :key="field" class="block">
              <span class="mb-1 block text-xs text-neutral-600">{{ t(`payroll.components.fields.${field}`) }}</span>
              <SearchableSelect :model-value="componentForm[field]" :options="inclusionTreatmentOptions" :clearable="false" :no-results-label="t('payroll.components.no_results')" accent="payroll" @update:model-value="setInclusionTreatment(field, $event)" />
            </label>
            <label class="block"><span class="mb-1 block text-xs text-neutral-600">{{ t('payroll.components.fields.debit') }}</span><SearchableSelect data-testid="payroll-component-debit" :model-value="componentForm.accounting_debit_code" :options="debitAccountOptions" :selected-option="selectedAccountOption(componentForm.accounting_debit_code)" :placeholder="t('payroll.components.account_placeholder')" :no-results-label="t('payroll.components.no_results')" accent="payroll" input-class="font-mono" @update:model-value="componentForm.accounting_debit_code = $event" /></label>
            <label class="block"><span class="mb-1 block text-xs text-neutral-600">{{ t('payroll.components.fields.credit') }}</span><SearchableSelect data-testid="payroll-component-credit" :model-value="componentForm.accounting_credit_code" :options="creditAccountOptions" :selected-option="selectedAccountOption(componentForm.accounting_credit_code)" :placeholder="t('payroll.components.account_placeholder')" :no-results-label="t('payroll.components.no_results')" accent="payroll" input-class="font-mono" @update:model-value="componentForm.accounting_credit_code = $event" /></label>
            <label class="block"><span class="mb-1 block text-xs text-neutral-600">{{ t('payroll.components.fields.annual_limit') }}</span><input v-model="componentForm.annual_limit" inputmode="decimal" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm"><span class="mt-1 block text-[11px] text-neutral-500">{{ t('payroll.components.fields.annual_limit_hint') }}</span></label>
            <label class="block"><span class="mb-1 block text-xs text-neutral-600">{{ t('payroll.components.fields.exemption_basket') }}</span><SearchableSelect data-testid="payroll-component-basket" :model-value="componentForm.exemption_basket" :options="exemptionBasketOptions" :placeholder="t('payroll.components.exemption_basket.none')" :no-results-label="t('payroll.components.no_results')" accent="payroll" @update:model-value="componentForm.exemption_basket = $event" /><span class="mt-1 block text-[11px] text-neutral-500">{{ t('payroll.components.fields.exemption_basket_hint') }}</span></label>
            <label v-if="componentForm.tax_treatment === 'exempt'" class="block"><span class="mb-1 block text-xs text-neutral-600">{{ t('payroll.components.fields.exemption_basis') }}</span><SearchableSelect data-testid="payroll-component-exemption-basis" :model-value="componentForm.exemption_basis" :options="exemptionBasisOptions" :placeholder="t('payroll.components.exemption_basis.none')" :no-results-label="t('payroll.components.no_results')" accent="payroll" @update:model-value="componentForm.exemption_basis = $event" /><span class="mt-1 block text-[11px] text-neutral-500">{{ t('payroll.components.fields.exemption_basis_hint') }}</span></label>
            <label class="block"><span class="mb-1 block text-xs text-neutral-600">{{ t('payroll.components.fields.valid_from') }}</span><input v-model="componentForm.valid_from" type="date" :disabled="!!editingComponent" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm disabled:bg-neutral-100"></label>
            <label class="block"><span class="mb-1 block text-xs text-neutral-600">{{ t('payroll.components.fields.valid_to') }}</span><input v-model="componentForm.valid_to" type="date" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm"></label>
            <label class="inline-flex items-center gap-2 self-end text-sm text-neutral-700"><input v-model="componentForm.is_active" type="checkbox" class="rounded border-neutral-300 text-payroll-600"> {{ t('payroll.components.fields.active') }}</label>
          </div>
          <p v-if="componentError" role="alert" class="mt-4 rounded-lg border border-danger-500/30 bg-danger-50 px-4 py-3 text-sm text-danger-700">{{ componentError }}</p>
          <div class="mt-5 flex flex-wrap justify-end gap-2">
            <button :class="btnFilled('primary')" :disabled="saving" @click="saveComponent"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.check" /></svg>{{ t('common.save') }}</button>
          </div>
        </section>

        <section v-if="jmhzEditorOpen && editingJmhzComponent" data-testid="payroll-jmhz-mapping-editor" class="rounded-xl border border-payroll-500/30 bg-payroll-50 p-4 sm:p-6">
          <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
              <h3 class="font-semibold text-neutral-900">{{ t('payroll.components.jmhz.title') }}</h3>
              <p class="mt-1 text-sm text-neutral-600">{{ editingJmhzComponent.code }} · {{ editingJmhzComponent.name }}</p>
            </div>
            <button :class="btnOutline('neutral')" @click="jmhzEditorOpen = false">
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.x" /></svg>
              {{ t('common.cancel') }}
            </button>
          </div>
          <div class="mt-4 max-w-3xl">
            <label class="block">
              <span class="mb-1 block text-xs text-neutral-600">{{ t('payroll.components.jmhz.target') }}</span>
              <SearchableSelect :model-value="jmhzTargetId" :options="jmhzTargetOptions" :clearable="false" :no-results-label="t('payroll.components.no_results')" accent="payroll" @update:model-value="jmhzTargetId = $event" />
            </label>
            <p v-if="selectedJmhzTarget" class="mt-2 break-all font-mono text-xs text-neutral-500">{{ selectedJmhzTarget.xsd_mapping }}</p>
            <p v-if="selectedJmhzTarget?.aggregation_role === 'catch_all_total'" class="mt-3 rounded-lg border border-warning-500/30 bg-warning-50 px-4 py-3 text-sm text-warning-700">{{ t('payroll.components.jmhz.catch_all_warning') }}</p>
            <p v-if="jmhzState(editingJmhzComponent).mapping?.is_active && !jmhzState(editingJmhzComponent).mapping?.is_current_package" class="mt-3 rounded-lg border border-warning-500/30 bg-warning-50 px-4 py-3 text-sm text-warning-700">{{ t('payroll.components.jmhz.legacy_mapping') }}</p>
          </div>
          <p v-if="jmhzError" role="alert" class="mt-4 rounded-lg border border-danger-500/30 bg-danger-50 px-4 py-3 text-sm text-danger-700">{{ jmhzError }}</p>
          <div class="mt-5 flex flex-wrap justify-end gap-2">
            <button v-if="jmhzState(editingJmhzComponent).mapping?.is_active" :class="btnOutline('danger')" :disabled="saving" @click="removeJmhzMapping">
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.trash" /></svg>
              {{ t('payroll.components.jmhz.remove') }}
            </button>
            <button :class="btnFilled('primary')" data-testid="payroll-jmhz-save" :disabled="saving || !jmhzTargetId || (jmhzState(editingJmhzComponent).mapping?.is_active && !jmhzState(editingJmhzComponent).mapping?.is_current_package)" :title="disabledTitle(jmhzSaveBlockedReason !== null, jmhzSaveBlockedReason)" @click="saveJmhzMapping">
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.link" /></svg>
              {{ t('common.save') }}
            </button>
          </div>
          <p v-if="jmhzSaveBlockedReason" :class="[BTN_DISABLED_NOTE, 'mt-2 text-right']" data-testid="payroll-jmhz-save-blocked">
            {{ jmhzSaveBlockedReason }}
          </p>
        </section>

        <section class="rounded-xl border border-neutral-200 bg-surface shadow-sm">
          <div data-layout="desktop" class="hidden overflow-x-auto md:block">
            <table class="min-w-full divide-y divide-neutral-200 text-sm">
              <thead><tr class="text-left text-xs uppercase tracking-wide text-neutral-500"><th class="px-4 py-3">{{ t('payroll.components.fields.code') }}</th><th class="px-4 py-3">{{ t('payroll.components.fields.name') }}</th><th class="px-4 py-3">{{ t('payroll.components.fields.kind') }}</th><th class="px-4 py-3">{{ t('payroll.components.fields.frequency') }}</th><th class="px-4 py-3">{{ t('payroll.components.fields.jmhz_treatment') }}</th><th class="px-4 py-3">{{ t('payroll.components.fields.validity') }}</th><th class="px-4 py-3">{{ t('payroll.components.fields.status') }}</th><th class="px-4 py-3 text-right">{{ t('payroll.components.fields.actions') }}</th></tr></thead>
              <tbody class="divide-y divide-neutral-100"><tr v-for="component in components" :key="component.id"><td class="px-4 py-3 font-mono text-xs font-semibold text-neutral-900">{{ component.code }}</td><td class="px-4 py-3">{{ component.name }}</td><td class="px-4 py-3">{{ t(`payroll.components.kind.${component.component_kind}`) }}</td><td class="px-4 py-3">{{ t(`payroll.components.frequency.${component.frequency_kind}`) }}</td><td class="px-4 py-3"><span class="rounded-full px-2 py-1 text-xs font-medium" :class="jmhzBadgeClass(component)">{{ t(`payroll.components.jmhz.status.${jmhzState(component).status}`) }}</span><p v-if="jmhzState(component).mapping?.is_active" class="mt-1 font-mono text-xs text-neutral-500">{{ jmhzState(component).mapping?.target_attribute_id }}</p></td><td class="px-4 py-3 text-xs">{{ component.valid_from }} – {{ component.valid_to ?? t('payroll.components.open_ended') }}</td><td class="px-4 py-3"><span class="rounded-full px-2 py-1 text-xs font-medium" :class="component.is_active ? 'bg-success-50 text-success-600' : 'bg-neutral-100 text-neutral-600'">{{ t(component.is_active ? 'payroll.components.active' : 'payroll.components.inactive') }}</span></td><td class="px-4 py-3"><div class="flex flex-wrap justify-end gap-2"><button v-if="canWrite && component.jmhz_treatment === 'included'" :class="btnOutlineSm(jmhzState(component).status === 'missing' ? 'warning' : 'neutral')" :disabled="jmhzLoading" @click="openJmhzMapping(component)"><svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.link" /></svg>{{ t('payroll.components.jmhz.configure') }}</button><button v-if="canWrite" :class="btnOutlineSm('neutral')" @click="editComponent(component)"><svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.edit" /></svg>{{ t('common.edit') }}</button><button v-if="canWrite" data-testid="payroll-component-delete" :class="btnOutlineSm('danger')" :disabled="saving" @click="deleteComponent(component)"><svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.trash" /></svg>{{ t('payroll.components.catalog.delete') }}</button></div></td></tr></tbody>
            </table>
          </div>
          <div data-layout="mobile" class="space-y-3 p-4 md:hidden">
            <article v-for="component in components" :key="component.id" class="rounded-lg border border-neutral-200 p-4">
              <div class="flex flex-wrap items-start justify-between gap-2"><div><p class="font-mono text-xs font-semibold text-payroll-700">{{ component.code }}</p><h3 class="mt-1 font-semibold text-neutral-900">{{ component.name }}</h3></div><span class="rounded-full px-2 py-1 text-xs font-medium" :class="component.is_active ? 'bg-success-50 text-success-600' : 'bg-neutral-100 text-neutral-600'">{{ t(component.is_active ? 'payroll.components.active' : 'payroll.components.inactive') }}</span></div>
              <dl class="mt-3 grid grid-cols-2 gap-3 text-sm"><div><dt class="text-xs text-neutral-500">{{ t('payroll.components.fields.kind') }}</dt><dd>{{ t(`payroll.components.kind.${component.component_kind}`) }}</dd></div><div><dt class="text-xs text-neutral-500">{{ t('payroll.components.fields.frequency') }}</dt><dd>{{ t(`payroll.components.frequency.${component.frequency_kind}`) }}</dd></div><div><dt class="text-xs text-neutral-500">{{ t('payroll.components.fields.jmhz_treatment') }}</dt><dd><span class="rounded-full px-2 py-1 text-xs font-medium" :class="jmhzBadgeClass(component)">{{ t(`payroll.components.jmhz.status.${jmhzState(component).status}`) }}</span><span v-if="jmhzState(component).mapping?.is_active" class="ml-2 font-mono text-xs">{{ jmhzState(component).mapping?.target_attribute_id }}</span></dd></div><div><dt class="text-xs text-neutral-500">{{ t('payroll.components.fields.validity') }}</dt><dd>{{ component.valid_from }} – {{ component.valid_to ?? t('payroll.components.open_ended') }}</dd></div></dl>
              <div v-if="canWrite" class="mt-4 flex flex-wrap gap-2"><button v-if="component.jmhz_treatment === 'included'" :class="btnOutlineSm(jmhzState(component).status === 'missing' ? 'warning' : 'neutral')" :disabled="jmhzLoading" @click="openJmhzMapping(component)"><svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.link" /></svg>{{ t('payroll.components.jmhz.configure') }}</button><button :class="btnOutlineSm('neutral')" @click="editComponent(component)"><svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.edit" /></svg>{{ t('common.edit') }}</button><button data-testid="payroll-component-delete" :class="btnOutlineSm('danger')" :disabled="saving" @click="deleteComponent(component)"><svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.trash" /></svg>{{ t('payroll.components.catalog.delete') }}</button></div>
            </article>
          </div>
        </section>
      </section>

      <section v-if="activeTab === 'recurring'" class="space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
          <div><h2 class="text-lg font-semibold text-neutral-900">{{ t('payroll.components.recurring.title') }}</h2><p class="text-sm text-neutral-500">{{ t('payroll.components.recurring.hint') }}</p></div>
          <div class="flex flex-wrap gap-2">
            <button v-if="canWrite" :class="btnOutline('success')" :disabled="saving" @click="materializeRecurring"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.play" /></svg>{{ t('payroll.components.recurring.materialize') }}</button>
            <button v-if="canWrite" :class="btnFilled('primary')" @click="openNewRecurring"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.plus" /></svg>{{ t('payroll.components.recurring.add') }}</button>
          </div>
        </div>
        <p v-if="recurringError" role="alert" class="rounded-lg border border-danger-500/30 bg-danger-50 px-4 py-3 text-sm text-danger-700">{{ recurringError }}</p>

        <section v-if="recurringEditorOpen" class="rounded-xl border border-payroll-500/30 bg-payroll-50 p-4 sm:p-6">
          <div class="flex flex-wrap items-start justify-between gap-3"><h3 class="font-semibold text-neutral-900">{{ t(editingRecurring ? 'payroll.components.recurring.edit' : 'payroll.components.recurring.new') }}</h3><button :class="btnOutline('neutral')" @click="recurringEditorOpen = false"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.x" /></svg>{{ t('common.cancel') }}</button></div>
          <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <div class="block"><span class="mb-1 block text-xs text-neutral-600">{{ t('payroll.components.fields.employee') }}</span><PayrollPersonSearchSelect :model-value="recurringEmployeeId" data-test="payroll-recurring-person" :candidates="personOptions" :label="t('payroll.components.fields.employee')" :clearable="false" @update:model-value="selectRecurringEmployee" /></div>
            <label class="block"><span class="mb-1 block text-xs text-neutral-600">{{ t('payroll.components.fields.employment') }}</span><SearchableSelect :model-value="recurringForm.employment_id" data-test="payroll-recurring-employment" :options="recurringEmploymentOptions" :clearable="false" :no-results-label="t('payroll.components.no_results')" accent="payroll" @update:model-value="recurringForm.employment_id = $event" /></label>
            <label class="block sm:col-span-2"><span class="mb-1 block text-xs text-neutral-600">{{ t('payroll.components.fields.component') }}</span><SearchableSelect :model-value="recurringForm.component_id" :options="regularComponentOptions" :clearable="false" :no-results-label="t('payroll.components.no_results')" accent="payroll" @update:model-value="recurringForm.component_id = $event" /></label>
            <label class="block"><span class="mb-1 block text-xs text-neutral-600">{{ t('payroll.components.fields.calculation') }}</span><SearchableSelect data-testid="payroll-recurring-calculation" :model-value="recurringForm.calculation_kind" :options="calculationKindOptions" :clearable="false" :no-results-label="t('payroll.components.no_results')" accent="payroll" @update:model-value="recurringForm.calculation_kind = $event ?? 'fixed_amount'" /></label>
            <label v-if="recurringForm.calculation_kind === 'fixed_amount'" class="block"><span class="mb-1 block text-xs text-neutral-600">{{ t('payroll.components.fields.amount') }}</span><input v-model="recurringForm.amount" inputmode="decimal" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm"></label>
            <label v-if="recurringForm.calculation_kind === 'employment_gross_basis_points'" class="block"><span class="mb-1 block text-xs text-neutral-600">{{ t('payroll.components.fields.rate_percent') }}</span><input v-model="recurringForm.rate_percent" data-testid="payroll-recurring-rate" inputmode="decimal" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm"></label>
            <label class="block"><span class="mb-1 block text-xs text-neutral-600">{{ t('payroll.components.fields.allocation') }}</span><SearchableSelect :model-value="recurringForm.allocation_rule" :options="allocationRuleOptions" :clearable="false" :no-results-label="t('payroll.components.no_results')" accent="payroll" @update:model-value="recurringForm.allocation_rule = $event ?? 'full_month'" /></label>
            <label class="block"><span class="mb-1 block text-xs text-neutral-600">{{ t('payroll.components.fields.maximum') }}</span><input v-model="recurringForm.maximum_amount" inputmode="decimal" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm"></label>
            <label class="block"><span class="mb-1 block text-xs text-neutral-600">{{ t('payroll.components.fields.valid_from') }}</span><input v-model="recurringForm.valid_from" type="date" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm"></label>
            <label class="block"><span class="mb-1 block text-xs text-neutral-600">{{ t('payroll.components.fields.valid_to') }}</span><input v-model="recurringForm.valid_to" type="date" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm"></label>
            <label class="block sm:col-span-2"><span class="mb-1 block text-xs text-neutral-600">{{ t('payroll.components.fields.note') }}</span><input v-model="recurringForm.note" maxlength="500" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm"></label>
            <label class="inline-flex items-center gap-2 self-end text-sm text-neutral-700"><input v-model="recurringForm.is_active" type="checkbox" class="rounded border-neutral-300 text-payroll-600"> {{ t('payroll.components.fields.active') }}</label>
          </div>
          <div class="mt-5 flex flex-wrap justify-end gap-2"><button :class="btnFilled('primary')" :disabled="saving" @click="saveRecurring"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.check" /></svg>{{ t('common.save') }}</button></div>
        </section>

        <section class="rounded-xl border border-neutral-200 bg-surface shadow-sm">
          <div class="hidden flex-wrap items-center justify-end gap-2 border-b border-neutral-200 px-4 py-2 md:flex"><ColumnPicker :ctrl="recurringTbl" /><DensityToggle :ctrl="recurringTbl" /></div>
          <div data-layout="desktop" class="hidden overflow-x-auto md:block"><table class="min-w-full divide-y divide-neutral-200 text-sm" :class="recurringTbl.densityClass.value"><thead><tr class="text-left text-xs uppercase tracking-wide text-neutral-500"><th v-if="recurringTbl.isVisible('employment')" class="px-4 py-3">{{ t('payroll.components.fields.employment') }}</th><th v-if="recurringTbl.isVisible('component')" class="px-4 py-3">{{ t('payroll.components.fields.component') }}</th><th v-if="recurringTbl.isVisible('calculation')" class="px-4 py-3">{{ t('payroll.components.fields.calculation') }}</th><th v-if="recurringTbl.isVisible('validity')" class="px-4 py-3">{{ t('payroll.components.fields.validity') }}</th><th v-if="recurringTbl.isVisible('status')" class="px-4 py-3">{{ t('payroll.components.fields.status') }}</th><th v-if="recurringTbl.isVisible('actions')" class="px-4 py-3 text-right">{{ t('payroll.components.fields.actions') }}</th></tr></thead><tbody class="divide-y divide-neutral-100"><tr v-for="item in recurring" :key="item.id"><td v-if="recurringTbl.isVisible('employment')" class="px-4 py-3"><p class="font-medium text-neutral-900">{{ item.employee_name }}</p><p class="text-xs text-neutral-500">{{ item.employment_code }}</p></td><td v-if="recurringTbl.isVisible('component')" class="px-4 py-3"><p>{{ item.component_name }}</p><p class="font-mono text-xs text-neutral-500">{{ item.component_code }}</p></td><td v-if="recurringTbl.isVisible('calculation')" class="px-4 py-3"><p>{{ t(`payroll.components.calculation.${item.calculation_kind}`) }}</p><p class="text-xs text-neutral-500">{{ item.amount_minor !== null ? formatMoney(item.amount_minor) : item.rate_basis_points !== null ? `${item.rate_basis_points / 100} %` : '—' }}</p></td><td v-if="recurringTbl.isVisible('validity')" class="px-4 py-3 text-xs">{{ item.valid_from }} – {{ item.valid_to ?? t('payroll.components.open_ended') }}</td><td v-if="recurringTbl.isVisible('status')" class="px-4 py-3"><span class="rounded-full px-2 py-1 text-xs font-medium" :class="item.is_active ? 'bg-success-50 text-success-600' : 'bg-neutral-100 text-neutral-600'">{{ t(item.is_active ? 'payroll.components.active' : 'payroll.components.inactive') }}</span></td><td v-if="recurringTbl.isVisible('actions')" class="px-4 py-3"><div class="flex flex-wrap justify-end gap-2"><button v-if="canWrite" :class="btnOutlineSm('neutral')" @click="editRecurring(item)"><svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.edit" /></svg>{{ t('common.edit') }}</button><button v-if="canWrite" data-testid="payroll-recurring-delete" :class="btnOutlineSm('danger')" :disabled="saving" @click="deleteRecurring(item)"><svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.trash" /></svg>{{ t('payroll.components.recurring.delete') }}</button></div></td></tr></tbody></table></div>
          <div data-layout="mobile" class="space-y-3 p-4 md:hidden"><article v-for="item in recurring" :key="item.id" class="rounded-lg border border-neutral-200 p-4"><div class="flex flex-wrap items-start justify-between gap-2"><div><h3 class="font-semibold text-neutral-900">{{ item.employee_name }}</h3><p class="text-xs text-neutral-500">{{ item.employment_code }} · {{ item.component_code }}</p></div><span class="rounded-full px-2 py-1 text-xs font-medium" :class="item.is_active ? 'bg-success-50 text-success-600' : 'bg-neutral-100 text-neutral-600'">{{ t(item.is_active ? 'payroll.components.active' : 'payroll.components.inactive') }}</span></div><dl class="mt-3 grid grid-cols-2 gap-3 text-sm"><div><dt class="text-xs text-neutral-500">{{ t('payroll.components.fields.component') }}</dt><dd>{{ item.component_name }}</dd></div><div><dt class="text-xs text-neutral-500">{{ t('payroll.components.fields.amount') }}</dt><dd>{{ item.amount_minor !== null ? formatMoney(item.amount_minor) : item.rate_basis_points !== null ? `${item.rate_basis_points / 100} %` : '—' }}</dd></div><div class="col-span-2"><dt class="text-xs text-neutral-500">{{ t('payroll.components.fields.validity') }}</dt><dd>{{ item.valid_from }} – {{ item.valid_to ?? t('payroll.components.open_ended') }}</dd></div></dl><div v-if="canWrite" class="mt-4 flex flex-wrap gap-2"><button :class="btnOutlineSm('neutral')" @click="editRecurring(item)"><svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.edit" /></svg>{{ t('common.edit') }}</button><button data-testid="payroll-recurring-delete" :class="btnOutlineSm('danger')" :disabled="saving" @click="deleteRecurring(item)"><svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.trash" /></svg>{{ t('payroll.components.recurring.delete') }}</button></div></article></div>
          <PaginationBar
            embedded
            :page="recurringPage"
            :per-page="recurringPageSize"
            :total="recurringTotal"
            @update:page="goToRecurringPage"
          />
        </section>
      </section>

      <section v-if="activeTab === 'risky_savings'">
        <PayrollRiskySavingsPanel :period="period" :employments="employments" />
      </section>

      <section v-if="activeTab === 'inputs'" class="space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-3"><div><h2 class="text-lg font-semibold text-neutral-900">{{ t('payroll.components.inputs.title') }}</h2><p class="text-sm text-neutral-500">{{ t('payroll.components.inputs.hint') }}</p></div><button v-if="canWrite" :class="btnFilled('primary')" @click="openNewInput"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.plus" /></svg>{{ t('payroll.components.inputs.add') }}</button></div>
        <p v-if="inputError" role="alert" class="rounded-lg border border-danger-500/30 bg-danger-50 px-4 py-3 text-sm text-danger-700">{{ inputError }}</p>

        <section v-if="inputEditorOpen" class="rounded-xl border border-payroll-500/30 bg-payroll-50 p-4 sm:p-6">
          <div class="flex flex-wrap items-start justify-between gap-3"><div><h3 class="font-semibold text-neutral-900">{{ t(editingInput ? 'payroll.components.inputs.edit' : 'payroll.components.inputs.new') }}</h3><p class="mt-1 text-xs text-neutral-600">{{ t('payroll.components.inputs.preview_hint') }}</p></div><button :class="btnOutline('neutral')" @click="inputEditorOpen = false"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.x" /></svg>{{ t('common.cancel') }}</button></div>
          <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <div class="block"><span class="mb-1 block text-xs text-neutral-600">{{ t('payroll.components.fields.employee') }}</span><PayrollPersonSearchSelect :model-value="inputForm.employee_id" data-test="payroll-input-person" :candidates="personOptions" :label="t('payroll.components.fields.employee')" :clearable="false" @update:model-value="selectInputEmployee" /></div>
            <label class="block"><span class="mb-1 block text-xs text-neutral-600">{{ t('payroll.components.fields.employment') }}</span><SearchableSelect :model-value="inputForm.employment_id" data-test="payroll-input-employment" :options="inputEmploymentOptions" :clearable="false" :no-results-label="t('payroll.components.no_results')" accent="payroll" @update:model-value="selectInputEmployment($event)" /></label>
            <label class="block sm:col-span-2"><span class="mb-1 block text-xs text-neutral-600">{{ t('payroll.components.fields.component') }}</span><SearchableSelect :model-value="inputForm.component_id" :options="oneOffComponentOptions" :clearable="false" :no-results-label="t('payroll.components.no_results')" accent="payroll" @update:model-value="inputForm.component_id = $event" /></label>
            <label class="block"><span class="mb-1 block text-xs text-neutral-600">{{ t('payroll.components.fields.amount') }}</span><input v-model="inputForm.amount" data-testid="payroll-input-amount" inputmode="decimal" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm"></label>
            <label class="block"><span class="mb-1 block text-xs text-neutral-600">{{ t('payroll.components.fields.quantity') }}</span><input v-model="inputForm.quantity" data-testid="payroll-input-quantity" inputmode="decimal" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm"></label>
            <label class="block"><span class="mb-1 block text-xs text-neutral-600">{{ t('payroll.components.fields.source_period') }}</span><input v-model="inputForm.source_period" type="month" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm"></label>
            <label class="block"><span class="mb-1 block text-xs text-neutral-600">{{ t('payroll.components.fields.external_id') }}</span><input v-model="inputForm.external_id" maxlength="190" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 font-mono text-sm"></label>
          </div>
          <div
            v-if="inputPreview"
            data-testid="payroll-input-preview"
            class="mt-4 rounded-lg border p-4 text-sm"
            :class="inputPreview.support_status === 'supported'
              && !inputPreview.annual_limit_exceeded
              && !mealEvidenceIncomplete
              ? 'border-success-500/30 bg-success-50 text-success-700'
              : 'border-warning-500/40 bg-warning-50 text-warning-700'"
          >
            <p class="font-medium">{{ t(`payroll.components.inputs.preview_status.${inputPreview.support_status}`) }}</p>
            <p v-if="inputPreview.blocker" class="mt-1">{{ inputPreview.blocker }}</p>
            <p v-if="inputPreview.annual_limit_minor !== null" class="mt-1">{{ t('payroll.components.inputs.annual_limit', { used: formatMoney(inputPreview.annual_used_minor), after: formatMoney(inputPreview.annual_after_minor), limit: formatMoney(inputPreview.annual_limit_minor) }) }}</p>
            <template v-if="inputPreview.exemption_basket">
              <p data-testid="payroll-input-basket" class="mt-2 font-medium">{{ t('payroll.components.inputs.basket_usage', { basket: t(`payroll.components.exemption_basket.${inputPreview.exemption_basket.basket}`), statute: inputPreview.exemption_basket.statute, used: formatMoney(inputPreview.exemption_basket.used_after_minor), limit: formatMoney(inputPreview.exemption_basket.limit_minor), remaining: formatMoney(inputPreview.exemption_basket.remaining_minor) }) }}</p>
              <p v-if="inputPreview.exemption_basket.limit_exceeded" data-testid="payroll-input-basket-over" class="mt-1">{{ t('payroll.components.inputs.basket_over_limit', { exempt: formatMoney(inputPreview.exemption_basket.exempt_minor), taxable: formatMoney(inputPreview.exemption_basket.taxable_minor) }) }}</p>
              <p
                v-if="inputPreview.exemption_basket.allocation?.mode === 'uniform_per_entitlement'"
                class="mt-1"
                data-testid="payroll-input-meal-allocation"
              >{{ t('payroll.components.inputs.meal_allocation_uniform', {
                count: inputPreview.exemption_basket.allocation.entitlement_count,
                amount: formatMoney(inputPreview.exemption_basket.allocation.amount_per_entitlement_minor),
                limit: formatMoney(inputPreview.exemption_basket.allocation.limit_per_entitlement_minor),
                exempt: formatMoney(inputPreview.exemption_basket.allocation.exempt_per_entitlement_minor),
                taxable: formatMoney(inputPreview.exemption_basket.allocation.taxable_per_entitlement_minor),
              }) }}</p>
            </template>
            <div
              v-if="mealEntitlement"
              data-testid="payroll-input-meal-entitlement"
              :data-basis="mealEntitlement.basis"
              class="mt-2 rounded-md border border-current/20 p-3"
            >
              <p class="font-medium">
                {{ t('payroll.components.inputs.meal_entitlement_summary', {
                  count: mealEntitlement.count,
                  qualifying: mealEntitlement.qualifying_count,
                  second: mealEntitlement.second_contribution_count,
                }) }}
              </p>
              <p class="mt-1">
                {{ t('payroll.components.inputs.meal_basis_label', {
                  basis: t(`payroll.components.inputs.meal_basis.${mealEntitlement.basis}`),
                }) }}
              </p>
              <p v-if="mealEntitlement.complete" class="mt-1">
                {{ t('payroll.components.inputs.meal_evidence_complete') }}
              </p>
              <template v-else>
                <p class="mt-1 font-medium">
                  {{ t('payroll.components.inputs.meal_evidence_incomplete') }}
                </p>
                <ul class="mt-1 list-disc space-y-1 pl-5">
                  <li
                    v-for="reason in mealEntitlement.missing"
                    :key="reason"
                  >
                    {{ t(`payroll.components.inputs.meal_missing.${reason}`) }}
                  </li>
                </ul>
              </template>
            </div>
          </div>
          <div class="mt-5 flex flex-wrap justify-end gap-2"><button :class="btnOutline('neutral')" :disabled="saving || !manualInputPayload" @click="previewManualInput"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.search" /></svg>{{ t('payroll.components.inputs.preview') }}</button><button :class="btnFilled('primary')" :disabled="saving || !canSaveInput" @click="saveInput"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.check" /></svg>{{ t('common.save') }}</button></div>
        </section>

        <section class="rounded-xl border border-neutral-200 bg-surface shadow-sm">
          <div v-if="inputs.length === 0" class="p-8 text-center"><h3 class="font-semibold text-neutral-900">{{ t('payroll.components.inputs.empty') }}</h3><p class="mt-1 text-sm text-neutral-500">{{ t('payroll.components.inputs.empty_hint') }}</p></div>
          <template v-else>
            <div class="flex flex-wrap items-center justify-end gap-2 border-b border-neutral-200 px-4 py-2">
              <!--
                Hromadné schválení stojí nad seznamem, ne u jednotlivých řádků:
                týká se celého období, ne zobrazené stránky.
              -->
              <button
                v-if="canApprove && draftInputCount > 0"
                type="button"
                data-testid="payroll-inputs-approve-all"
                :class="btnOutline('success')"
                :disabled="saving"
                @click="approveAllInputs"
              >
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.badgeCheck" /></svg>
                {{ t('payroll.components.inputs.approve_all', { count: draftInputCount }) }}
              </button>
              <span class="hidden items-center gap-2 md:inline-flex"><ColumnPicker :ctrl="inputsTbl" /><DensityToggle :ctrl="inputsTbl" /></span>
            </div>
            <div data-layout="desktop" class="hidden overflow-x-auto md:block"><table class="min-w-full divide-y divide-neutral-200 text-sm" :class="inputsTbl.densityClass.value"><thead><tr class="text-left text-xs uppercase tracking-wide text-neutral-500"><th class="px-4 py-3">{{ t('payroll.components.fields.employment') }}</th><th class="px-4 py-3">{{ t('payroll.components.fields.component') }}</th><th class="px-4 py-3">{{ t('payroll.components.fields.amount') }}</th><th v-if="inputsTbl.isVisible('source')" class="px-4 py-3">{{ t('payroll.components.fields.source') }}</th><th v-if="inputsTbl.isVisible('status')" class="px-4 py-3">{{ t('payroll.components.fields.status') }}</th><th v-if="inputsTbl.isVisible('external_id')" class="px-4 py-3">{{ t('payroll.components.fields.external_id') }}</th><th class="px-4 py-3 text-right">{{ t('payroll.components.fields.actions') }}</th></tr></thead><tbody class="divide-y divide-neutral-100"><tr v-for="input in inputs" :key="input.id"><td class="px-4 py-3"><p class="font-medium text-neutral-900">{{ input.employee_name }}</p><p class="text-xs text-neutral-500">{{ relationLabel(input.relation_type) }}</p><p class="font-mono text-[11px] text-neutral-400">{{ input.employment_code }}</p></td><td class="px-4 py-3"><p>{{ input.component_name }}</p><p class="font-mono text-xs text-neutral-500">{{ input.component_code }}</p></td><td class="px-4 py-3 font-medium">{{ formatMoney(input.amount_minor) }}</td><td v-if="inputsTbl.isVisible('source')" class="px-4 py-3">{{ t(`payroll.components.source.${input.source_kind}`) }}</td><td v-if="inputsTbl.isVisible('status')" class="px-4 py-3"><span class="rounded-full px-2 py-1 text-xs font-medium" :class="input.status === 'approved' || input.status === 'locked' ? 'bg-success-50 text-success-600' : input.status === 'cancelled' ? 'bg-neutral-100 text-neutral-500' : 'bg-payroll-50 text-payroll-700'">{{ t(`payroll.components.input_status.${input.status}`) }}</span></td><td v-if="inputsTbl.isVisible('external_id')" class="px-4 py-3 break-all font-mono text-xs text-neutral-500">{{ input.external_id ?? '—' }}</td><td class="px-4 py-3"><div class="flex flex-wrap justify-end gap-2"><button v-if="canWrite && input.status === 'draft' && input.source_kind === 'manual'" :class="btnOutlineSm('neutral')" @click="editInput(input)"><svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.edit" /></svg>{{ t('common.edit') }}</button><button v-if="canWrite && input.status === 'draft' && input.source_kind === 'manual'" data-testid="payroll-input-cancel" :class="btnOutlineSm('danger')" :disabled="saving" @click="cancelInput(input)"><svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.trash" /></svg>{{ t('payroll.components.inputs.cancel') }}</button><button v-if="canApprove && input.status === 'draft'" :class="btnOutlineSm('success')" :disabled="saving" @click="approveInput(input)"><svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.badgeCheck" /></svg>{{ t('payroll.components.inputs.approve') }}</button><button v-if="canApprove && canReverseBenefit(input)" data-testid="payroll-input-reverse-benefit" :class="btnOutlineSm('warning')" :disabled="saving" @click="reverseBenefitInput(input)"><svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.uturn" /></svg>{{ t('payroll.components.inputs.reverse_benefit') }}</button></div></td></tr></tbody></table></div>
            <div data-layout="mobile" class="space-y-3 p-4 md:hidden"><article v-for="input in inputs" :key="input.id" class="rounded-lg border border-neutral-200 p-4"><div class="flex flex-wrap items-start justify-between gap-2"><div><h3 class="font-semibold text-neutral-900">{{ input.employee_name }}</h3><p class="text-xs text-neutral-500">{{ relationLabel(input.relation_type) }} · {{ input.component_code }}</p><p class="font-mono text-[11px] text-neutral-400">{{ input.employment_code }}</p></div><span class="rounded-full px-2 py-1 text-xs font-medium" :class="input.status === 'approved' || input.status === 'locked' ? 'bg-success-50 text-success-600' : input.status === 'cancelled' ? 'bg-neutral-100 text-neutral-500' : 'bg-payroll-50 text-payroll-700'">{{ t(`payroll.components.input_status.${input.status}`) }}</span></div><dl class="mt-3 grid grid-cols-2 gap-3 text-sm"><div><dt class="text-xs text-neutral-500">{{ t('payroll.components.fields.component') }}</dt><dd>{{ input.component_name }}</dd></div><div><dt class="text-xs text-neutral-500">{{ t('payroll.components.fields.amount') }}</dt><dd class="font-semibold">{{ formatMoney(input.amount_minor) }}</dd></div><div><dt class="text-xs text-neutral-500">{{ t('payroll.components.fields.source') }}</dt><dd>{{ t(`payroll.components.source.${input.source_kind}`) }}</dd></div><div><dt class="text-xs text-neutral-500">{{ t('payroll.components.fields.external_id') }}</dt><dd class="break-all font-mono text-xs">{{ input.external_id ?? '—' }}</dd></div></dl><div class="mt-4 flex flex-wrap gap-2"><button v-if="canWrite && input.status === 'draft' && input.source_kind === 'manual'" :class="btnOutlineSm('neutral')" @click="editInput(input)"><svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.edit" /></svg>{{ t('common.edit') }}</button><button v-if="canWrite && input.status === 'draft' && input.source_kind === 'manual'" data-testid="payroll-input-cancel" :class="btnOutlineSm('danger')" :disabled="saving" @click="cancelInput(input)"><svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.trash" /></svg>{{ t('payroll.components.inputs.cancel') }}</button><button v-if="canApprove && input.status === 'draft'" :class="btnOutlineSm('success')" :disabled="saving" @click="approveInput(input)"><svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.badgeCheck" /></svg>{{ t('payroll.components.inputs.approve') }}</button><button v-if="canApprove && canReverseBenefit(input)" data-testid="payroll-input-reverse-benefit" :class="btnOutlineSm('warning')" :disabled="saving" @click="reverseBenefitInput(input)"><svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.uturn" /></svg>{{ t('payroll.components.inputs.reverse_benefit') }}</button></div></article></div>
            <PaginationBar
              embedded
              :page="inputsPage"
              :per-page="inputsPageSize"
              :total="inputsTotal"
              @update:page="goToInputsPage"
            />
          </template>
        </section>
      </section>

      <section v-if="activeTab === 'import'" class="space-y-4">
        <div><h2 class="text-lg font-semibold text-neutral-900">{{ t('payroll.components.import.title') }}</h2><p class="mt-1 max-w-3xl text-sm text-neutral-500">{{ t('payroll.components.import.hint') }}</p></div>
        <section class="rounded-xl border border-neutral-200 bg-surface p-4 shadow-sm sm:p-6">
          <div v-if="canWrite" class="space-y-4">
            <PayrollFileDropzone
              dropzone-test-id="payroll-import-dropzone"
              input-test-id="payroll-import-file"
              selected-test-id="payroll-import-selected"
              :disabled="saving"
              :selected-file-name="importName"
              :error="importFileError"
              :drop-hint="t('payroll.components.import.drop_hint')"
              :drop-active-hint="t('payroll.components.import.drop_active')"
              :file-hint="t('payroll.components.import.file_limit')"
              :choose-file-text="t('payroll.components.import.choose_file')"
              :selected-text="importName ? t('payroll.components.import.selected_file', { name: importName }) : ''"
              @selected="loadImportFile"
              @rejected="rejectImportFile"
            />
            <div class="flex flex-wrap items-center gap-3">
            <button :class="btnOutline('neutral')" :disabled="saving || !importContent" @click="previewImport"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.search" /></svg>{{ t('payroll.components.import.preview') }}</button>
            <button data-testid="payroll-import-apply" :class="btnFilled('primary')" :disabled="saving || !importCanApply" @click="applyImport"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.upload" /></svg>{{ t('payroll.components.import.apply') }}</button>
            </div>
            <p v-if="importApiError" role="alert" class="rounded-lg border border-danger-500/30 bg-danger-50 px-4 py-3 text-sm text-danger-700">{{ importApiError }}</p>
          </div>
          <p class="mt-3 text-xs text-neutral-500">{{ t('payroll.components.import.columns_hint') }}</p>
          <div v-if="importPreview" class="mt-4 space-y-4">
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
              <article class="rounded-lg bg-neutral-50 p-3"><p class="text-xs text-neutral-500">{{ t('payroll.components.import.rows') }}</p><p class="mt-1 text-lg font-semibold">{{ importPreview.row_count }}</p></article>
              <article class="rounded-lg bg-success-50 p-3"><p class="text-xs text-success-700">{{ t('payroll.components.import.accepted') }}</p><p class="mt-1 text-lg font-semibold text-success-700">{{ importPreview.accepted_count }}</p></article>
              <article class="rounded-lg bg-danger-50 p-3"><p class="text-xs text-danger-600">{{ t('payroll.components.import.rejected') }}</p><p class="mt-1 text-lg font-semibold text-danger-600">{{ importPreview.rejected_count }}</p></article>
              <article class="rounded-lg bg-warning-50 p-3"><p class="text-xs text-warning-700">{{ t('payroll.components.import.duplicates') }}</p><p class="mt-1 text-lg font-semibold text-warning-700">{{ importPreview.duplicate_count }}</p></article>
            </div>
            <div v-if="importIssues.length" class="overflow-hidden rounded-lg border border-neutral-200">
              <div data-layout="desktop" class="hidden overflow-x-auto md:block"><table class="min-w-full divide-y divide-neutral-200 text-sm"><thead><tr class="text-left text-xs uppercase tracking-wide text-neutral-500"><th class="px-3 py-2">{{ t('payroll.components.import.row') }}</th><th class="px-3 py-2">{{ t('payroll.components.import.issue_type') }}</th><th class="px-3 py-2">{{ t('payroll.components.import.field') }}</th><th class="px-3 py-2">{{ t('payroll.components.import.message') }}</th></tr></thead><tbody class="divide-y divide-neutral-100"><tr v-for="issue in importIssues" :key="`${issue.kind}-${issue.row_number}-${issue.error_code}`"><td class="px-3 py-2">{{ issue.row_number }}</td><td class="px-3 py-2"><span class="rounded-full px-2 py-1 text-xs font-medium" :class="issue.kind === 'duplicate' ? 'bg-warning-50 text-warning-700' : 'bg-danger-50 text-danger-600'">{{ t(`payroll.components.import.issue.${issue.kind}`) }}</span></td><td class="px-3 py-2 font-mono text-xs">{{ issue.field_name ?? '—' }}</td><td class="px-3 py-2">{{ issue.error_message }}</td></tr></tbody></table></div>
              <div data-layout="mobile" class="space-y-2 p-3 md:hidden"><article v-for="issue in importIssues" :key="`${issue.kind}-${issue.row_number}-${issue.error_code}`" class="rounded-md bg-neutral-50 p-3 text-sm"><div class="flex flex-wrap items-center justify-between gap-2"><strong>{{ t('payroll.components.import.row_number', { row: issue.row_number }) }}</strong><span class="rounded-full px-2 py-1 text-xs font-medium" :class="issue.kind === 'duplicate' ? 'bg-warning-50 text-warning-700' : 'bg-danger-50 text-danger-600'">{{ t(`payroll.components.import.issue.${issue.kind}`) }}</span></div><p class="mt-2">{{ issue.error_message }}</p><p v-if="issue.field_name" class="mt-1 font-mono text-xs text-neutral-500">{{ issue.field_name }}</p></article></div>
            </div>
          </div>
          <div v-if="importResult" class="mt-4 rounded-lg border border-success-500/30 bg-success-50 p-4 text-sm text-success-700"><p class="font-medium">{{ t('payroll.components.import.result_title') }}</p><p class="mt-1">{{ t('payroll.components.import.result_summary', importResult) }}</p><p v-if="importResult.replayed" class="mt-1">{{ t('payroll.components.import.replayed') }}</p></div>
        </section>
      </section>
    </template>
  </div>
</template>

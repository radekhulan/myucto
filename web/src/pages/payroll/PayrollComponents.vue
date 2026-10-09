<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { personalNumberLabel, todayIso } from './employmentLifecycleUi'
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
  type PayrollInputFacets,
  type PayrollInputGroup,
  type PayrollInputsSummary,
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
import PayrollPeriodScopePicker from '@/components/payroll/PayrollPeriodScopePicker.vue'
import PayrollRiskySavingsPanel from '@/components/payroll/PayrollRiskySavingsPanel.vue'
import PayrollInputRowActions from '@/components/payroll/PayrollInputRowActions.vue'
import MultiSelectFilter from '@/components/ui/MultiSelectFilter.vue'
import {
  emptyPayrollInputFilters,
  groupPayrollInputFailures,
  payrollInputFilterParams,
  payrollInputFiltersActive,
  payrollInputFiltersFromQuery,
  payrollInputFiltersToQuery,
  runPayrollInputBatch,
  PAYROLL_INPUT_FILTER_STATUSES,
  PAYROLL_INPUT_SOURCE_KINDS,
  type PayrollInputBatchPass,
  type PayrollInputBatchTotal,
  type PayrollInputFilterState,
} from '@/pages/payroll/payrollInputFilters'
import { payrollQueryId, payrollQueryValue } from '@/pages/payroll/payrollAgendaLinks'
import {
  payrollPeriodRange,
  payrollPeriodScopeFromQuery,
  payrollPeriodScopeToQuery,
  type PayrollPeriodScope,
} from '@/pages/payroll/payrollPeriodScope'
import ColumnPicker from '@/components/ui/ColumnPicker.vue'
import DensityToggle from '@/components/ui/DensityToggle.vue'
import { useTablePrefs, type ColumnDef } from '@/composables/useTablePrefs'
import {
  canApplyPayrollImport,
  payrollQueryPeriod,
  monthStart,
  parsePayrollAmountToMinor,
  payrollEmploymentOptionsFromContext,
  payrollImportFingerprint,
  payrollImportIssues,
  payrollMinorToInput,
  type PayrollEmploymentOption,
} from '@/pages/payroll/payrollComponentsUi'
// Formátování je sdílené (useFormat) — místní kopie se rozcházely v locale i tvaru.
import { formatMoneyMinor, formatPeriod } from '@/composables/useFormat'
import DateInput from '@/components/ui/DateInput.vue'

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
// Období z adresy: odkaz z blokátoru běhu i z importu docházky míří na
// konkrétní měsíc a stránka ho nesmí tiše přepnout na zpracovávaný.
const period = ref(payrollQueryPeriod(route.query))
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
/*
 * Sloupec s obdobím do výběru sloupců schválně NEPATŘÍ.
 *
 * V měsíčním pohledu by jen opakoval hodnotu z hlavičky stránky, takže by se
 * v nabídce válel jako mrtvá volba. Nad rozsahem je naopak jediné, čím se řádky
 * od sebe liší — vypnout ho by znamenalo rozbít právě to, kvůli čemu se rozsah
 * zapíná. Zobrazuje se proto podle `rangeMode`, ne podle preferencí.
 */
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
// Stovka na stránku: po importu docházky má měsíc stovky vstupů a listovat
// jimi po pětadvaceti je přesně to proklikávání, které filtr odstraňuje.
const inputsPageSize = 100
/** Kolik vstupů ukáže rozbalená skupina; zbytek otevře „Otevřít v seznamu". */
const GROUP_ITEMS_LIMIT = 200
const inputFilters = ref<PayrollInputFilterState>(payrollInputFiltersFromQuery(route.query))
const inputFilterParams = computed(() => payrollInputFilterParams(inputFilters.value))
const inputFiltersOn = computed(() => payrollInputFiltersActive(inputFilters.value))
const inputSummary = ref<PayrollInputsSummary | null>(null)
/*
 * Zobrazený výpis celý předchází prvnímu mzdovému období firmy.
 *
 * Převod mezd naimportuje vstupy i za měsíce, které MyÚčto nepočítá. Koncepty
 * za ně tu visely jako nedodělek, přestože mzdový běh za takové období nejde
 * založit. Značka je proto na výpisu, ne na datech: řádky i souhrn zůstávají.
 */
const inputsHistorical = ref(false)
const inputsStartPeriod = ref<string | null>(null)
const inputFacets = ref<PayrollInputFacets>({ components: [], imports: [] })
const inputGroups = ref<PayrollInputGroup[]>([])
const inputGroupTotal = ref(0)
/** Rozbalené skupiny: `null` = načítá se. */
const expandedGroups = ref<Record<number, PayrollInput[] | null>>({})
const selectedInputIds = ref<number[]>([])
const inputBatchFailures = ref<Array<{ message: string, count: number }>>([])
/** Kolik už hromadná akce zpracovala; `null` = neběží. */
const batchProgress = ref<number | null>(null)
/** Právě stahovaný export; mezitím nejde spustit další. */
const exportingInputs = ref<'xlsx' | 'pdf' | null>(null)

/**
 * Export stáhne celý filtr včetně zúžení na vztah, ne zobrazenou stránku —
 * a při rozsahu i celý rozsah měsíců. Server `period_to` přijímá a promítá ho
 * do hlavičky sestavy i do názvu souboru, takže stažené se jmenuje tím, co
 * bylo na obrazovce.
 */
async function exportInputs(format: 'xlsx' | 'pdf') {
  if (exportingInputs.value !== null) return
  exportingInputs.value = format
  const periodTo = listPeriodTo.value
  try {
    await payrollApi.exportInputs(
      format,
      periodTo === undefined ? period.value : listPeriod.value,
      focusEmploymentId.value ?? undefined,
      periodTo === undefined
        ? inputFilterParams.value
        : { ...inputFilterParams.value, period_to: periodTo },
    )
  } catch (error: any) {
    toast.error(apiErrorMessage(error, t('payroll.components.inputs.export_failed')))
  } finally {
    exportingInputs.value = null
  }
}

/*
 * Export z hlavičky stránky. Tlačítka v souhrnu vstupů vidí jen ten, kdo
 * je na záložce vstupů; odjinud export přepne na vstupy (ať je vidět, co se
 * stahuje) a stáhne ho se stejným filtrem.
 */
const headerExportOpen = ref(false)

async function exportInputsFromHeader(format: 'xlsx' | 'pdf') {
  headerExportOpen.value = false
  if (activeTab.value !== 'inputs') activeTab.value = 'inputs'
  await exportInputs(format)
}
const componentFilterOptions = computed(() => {
  const options = inputFacets.value.components.map(item => ({
    value: String(item.id),
    label: item.name,
    secondary: item.code,
  }))
  // Složka z adresy, která v měsíci zrovna nemá vstup, musí jít odškrtnout.
  for (const id of inputFilters.value.componentIds) {
    if (!options.some(option => option.value === String(id))) {
      options.push({ value: String(id), label: `#${id}`, secondary: '' })
    }
  }
  return options
})
const statusFilterOptions = computed(() => PAYROLL_INPUT_FILTER_STATUSES.map(status => ({
  value: status,
  label: t(`payroll.components.input_status.${status}`),
})))
const inputListEmpty = computed(() => effectiveGroupBy.value === null
  ? inputs.value.length === 0
  : inputGroups.value.length === 0)
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
/*
 * Rozsah období — jen na záložce vstupů a jen při zúžení na jeden vztah.
 *
 * Nad celou firmou by „vše" znamenalo vypsat roky práce všech zaměstnanců.
 * Nad jedním člověkem je to naopak ta otázka, se kterou účetní na jeho kartu
 * chodí („co bral loni"), a klikat se k odpovědi měsíc po měsíci je to samé
 * jako ji nemít. Vybraný MĚSÍC (`period`) zůstává rozsahem nedotčený: visí na
 * něm zakládání vstupů i „Převést do měsíce" na sousední záložce.
 */
const scope = ref<PayrollPeriodScope>(
  payrollQueryId(route.query, 'employment') === null
    ? 'month'
    : payrollPeriodScopeFromQuery(route.query),
)
const scopeRange = computed(() => payrollPeriodRange(scope.value, period.value))
/** Seznam vstupů čte rozsah měsíců, ne vybraný měsíc. */
const rangeMode = computed(() =>
  focusEmploymentId.value !== null && scopeRange.value !== null)
/** Období, od kterého se čte (a které jde do exportu). */
const listPeriod = computed(() => scopeRange.value?.from ?? period.value)
/** Konec rozsahu pro server; `undefined` = jeden měsíc jako dosud. */
const listPeriodTo = computed(() => rangeMode.value ? scopeRange.value?.to : undefined)

/**
 * Vztah zúžení poznaný ze SKUTEČNĚ NAČTENÝCH řádků.
 *
 * Nabídka vztahů se plní z `payrollAbsenceApi.context()`, která vynechává
 * archivované a nenastoupivší vztahy. Dokud se jméno bralo jen z ní, vypadal
 * odkaz z karty zaměstnance na takový vztah jako slepý — i když jeho předpisy
 * seznam právě vypisoval. Řádky nesou jméno i osobní číslo a jejich existence
 * zároveň dokazuje, že vztah k téhle firmě patří.
 */
const focusRow = computed(() => {
  const id = focusEmploymentId.value
  if (id === null) return null
  return recurring.value.find(item => item.employment_id === id)
    ?? inputs.value.find(item => item.employment_id === id)
    ?? null
})
const focusName = computed(() => {
  const id = focusEmploymentId.value
  if (id === null) return null
  const employment = employments.value.find(item => item.employment_id === id)
  if (employment !== undefined) {
    return [employment.full_name, personalNumberLabel(t, employment.code)]
      .filter(part => part !== '').join(' · ')
  }
  const row = focusRow.value
  if (row !== null) {
    return [row.employee_name, personalNumberLabel(t, row.employment_code)]
      .filter(part => part !== '').join(' · ')
  }
  return t('payroll.agendas.focus.unknown_person')
})
/** Server zúžení uplatnil a nezbylo nic — ani opakovaná složka, ani vstup. */
const focusEmpty = computed(() =>
  focusEmploymentId.value !== null && !loading.value && !loadFailed.value
  && (
    (activeTab.value === 'inputs' && inputsTotal.value === 0 && !inputFiltersOn.value)
    || (activeTab.value === 'recurring' && recurringTotal.value === 0)
  ))
/**
 * Slepý odkaz se pozná jen tehdy, když se vztah nenašel NIKDE — ani v nabídce,
 * ani v načtených řádcích.
 *
 * Prázdný seznam sám o sobě to neznamená: předpisy nemá každý a vstup v jednom
 * měsíci taky ne. Věta „vztah ani osoba k firmě nepatří, nebo odkaz zestaral"
 * o platném odkazu lže a uživatele pošle hledat chybu tam, kde žádná není.
 */
const focusMissing = computed(() => focusEmpty.value
  && !employments.value.some(item => item.employment_id === focusEmploymentId.value)
  && focusRow.value === null)

/**
 * Seskupení, se kterým se opravdu jede.
 *
 * „Po obdobích" má smysl jen nad rozsahem — nad jedním měsícem by vyrobilo
 * jedinou skupinu. Z adresy (`group=period`) ale přijít může, takže se mimo
 * rozsah tiše vrací na běžný seznam.
 */
const effectiveGroupBy = computed(() =>
  inputFilters.value.groupBy === 'period' && !rangeMode.value
    ? null
    : inputFilters.value.groupBy)

/**
 * Hromadné akce (schválit/zrušit výběr i celý filtr) se nad rozsahem NENABÍZEJÍ.
 *
 * Server je nad rozsahem odmítá schválně (422): jedou přes všechny koncepty
 * filtru, takže by jedno kliknutí sáhlo i na měsíce, které má uživatel na
 * obrazovce jen jako historii. Tlačítka se proto skrývají, ne zašeďují —
 * zašedlé tlačítko bez důvodu jen vyvolá otázku. Důvod říká jedna věta
 * v souhrnu a cesta zpět je přepnout rozsah na Měsíc.
 */
const batchDisabledInRange = computed(() => rangeMode.value)

function changeScope(next: PayrollPeriodScope): void {
  if (scope.value === next) return
  scope.value = next
  // Výběr i rozbalené skupiny patří k předchozímu výřezu; nad jiným rozsahem
  // by ukazovaly na řádky, které tam nejsou.
  selectedInputIds.value = []
  expandedGroups.value = {}
  inputBatchFailures.value = []
  inputsOffset.value = 0
  void router.replace({ query: payrollPeriodScopeToQuery(route.query, next) })
  loadInputsPage().catch((error: any) => {
    toast.error(apiErrorMessage(error, t('payroll.components.load_failed')))
  })
}

/** Proklik z měsíční skupiny zpátky do editovatelného měsíce. */
function openPeriodGroup(period_: string): void {
  period.value = period_
  scope.value = 'month'
  inputsOffset.value = 0
  selectedInputIds.value = []
  expandedGroups.value = {}
  void router.replace({
    query: { ...payrollPeriodScopeToQuery(route.query, 'month'), period: period_ },
  })
  loadInputsPage().catch((error: any) => {
    toast.error(apiErrorMessage(error, t('payroll.components.load_failed')))
  })
}
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
/** Importovaný koncept se opravuje jen v částce a množství; identita zůstává ze zdroje. */
const editingImported = computed(() =>
  editingInput.value !== null && editingInput.value.source_kind !== 'manual')
// Import nese i pravidelné složky (docházka), které nabídka jednorázových nemá.
const inputComponentOptions = computed(() => {
  const current = editingInput.value
  if (current === null || oneOffComponentOptions.value.some(option => option.value === current.component_id)) {
    return oneOffComponentOptions.value
  }
  return [
    ...oneOffComponentOptions.value,
    { value: current.component_id, label: current.component_name, secondary: current.component_code },
  ]
})
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
    // Oprava importovaného konceptu nese jeho původ; server ho zachová
    // i s `external_id`, formulář ho jen nesmí přepsat na ruční vstup.
    source_kind: editingInput.value?.source_kind ?? 'manual',
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

/**
 * Období, které se právě čte — u rozsahu obě meze, ne jen jeho začátek.
 *
 * „Vše" se na server posílá jako dostatečně široký rozsah, protože `period` je
 * povinné. Ty meze jsou ale technický detail: „leden 1990 – prosinec 2099"
 * uživateli neřekne nic a vypadá jako rozbitá data. Pojmenuje se proto slovem.
 */
const listPeriodLabel = computed(() => {
  if (!rangeMode.value) return formatPeriod(period.value)
  if (scope.value === 'all') return t('payroll.agendas.scope.all_periods')
  const range = scopeRange.value
  return range === null
    ? formatPeriod(period.value)
    : `${formatPeriod(range.from)} – ${formatPeriod(range.to)}`
})

/**
 * Stav pravidelného předpisu k dnešku.
 *
 * Seznam předpisů je celá historie vztahu, takže samotný příznak `is_active`
 * nestačí: ukončený předpis s `is_active = 1` se v něm tvářil stejně jako ten,
 * podle kterého se dnes počítá mzda. Naplánovaný, platný a ukončený jsou tři
 * různé věci; vypnutý je nad nimi.
 */
type RecurringState = 'inactive' | 'scheduled' | 'expired' | 'current'

function recurringState(item: PayrollRecurringComponent): RecurringState {
  if (!item.is_active) return 'inactive'
  const today = todayIso()
  if (item.valid_from > today) return 'scheduled'
  if (item.valid_to !== null && item.valid_to < today) return 'expired'
  return 'current'
}

function recurringStateClass(item: PayrollRecurringComponent): string {
  return {
    current: 'bg-success-50 text-success-600',
    scheduled: 'bg-payroll-50 text-payroll-700',
    expired: 'bg-warning-50 text-warning-700',
    inactive: 'bg-neutral-100 text-neutral-600',
  }[recurringState(item)]
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
    outside_breakdown: 'bg-neutral-100 text-neutral-600',
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
  // Importní dávka patří k měsíci; v jiném by filtr tiše vrátil prázdno.
  inputFilters.value = { ...inputFilters.value, importId: null }
  selectedInputIds.value = []
  expandedGroups.value = {}
  void router.replace({
    query: { ...payrollInputFiltersToQuery(route.query, inputFilters.value), period: period.value },
  })
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

/**
 * Které pole editor katalogu drží — klíč do `payroll.components.fields.*`.
 *
 * Editor má přes dvacet polí ve čtyřech sloupcích. Hláška „Zkontrolujte povinná
 * pole" v něm znamenala projít je všechny očima; pojmenované pole je jedno
 * hledání. Pořadí odpovídá tomu, co uživatel vyplňuje dřív.
 */
const componentMissingField = computed<string | null>(() => {
  const form = componentForm.value
  if (!form.code.trim()) return 'code'
  if (!form.name.trim()) return 'name'
  const limit = form.annual_limit === '' ? null : parsePayrollAmountToMinor(form.annual_limit)
  if (form.annual_limit !== '' && (limit === null || limit <= 0)) return 'annual_limit'
  // Osvobození bez uvedeného podkladu neprojde mzdovým během — ať to uživatel
  // zjistí tady, ne až při uzávěrce měsíce.
  if (form.tax_treatment === 'exempt' && form.exemption_basis === null) return 'exemption_basis'
  // Podklad, který stojí na zmrazeném rozpadu koše, bez zařazení do koše nedává
  // smysl — a backend by ho stejně odmítl.
  if (['benefit_basket', 'periodic_benefit_limit'].includes(form.exemption_basis ?? '')
    && form.exemption_basket === null) {
    return 'exemption_basket'
  }
  return null
})

function componentPayload(): PayrollComponentPayload | null {
  if (componentMissingField.value !== null) return null
  const limit = componentForm.value.annual_limit === ''
    ? null
    : parsePayrollAmountToMinor(componentForm.value.annual_limit)
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
    const missing = componentMissingField.value
    componentError.value = missing === null
      ? t('payroll.components.validation_failed')
      : t('payroll.components.validation_field', {
        field: t(`payroll.components.fields.${missing}`),
      })
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

async function fetchInputsPage() {
  const focused = focusEmploymentId.value ?? undefined
  const filters = inputFilterParams.value
  const groupBy = effectiveGroupBy.value
  const periodTo = listPeriodTo.value
  // Bez rozsahu se posílá přesně to, co dosud — `period_to` do dotazu vůbec
  // nevstupuje, takže se měsíční pohled nemá jak změnit.
  const request = () => periodTo === undefined
    ? payrollApi.inputs(
      period.value,
      { limit: inputsPageSize, offset: inputsOffset.value },
      focused,
      filters,
      groupBy,
    )
    : payrollApi.inputs(
      listPeriod.value,
      { limit: inputsPageSize, offset: inputsOffset.value },
      focused,
      filters,
      groupBy,
      periodTo,
    )
  let page = await request()
  const count = groupBy === null ? page.total : (page.group_total ?? 0)
  const shown = groupBy === null ? page.items.length : (page.groups?.length ?? 0)
  // Zrušení posledního vstupu na poslední straně by jinak nechalo uživatele
  // stát na straně, která už neexistuje — prázdná tabulka a pager bez cesty zpět.
  if (shown === 0 && count > 0 && inputsOffset.value >= count) {
    inputsOffset.value = Math.max(
      0,
      (Math.ceil(count / inputsPageSize) - 1) * inputsPageSize,
    )
    page = await request()
  }
  inputs.value = page.items
  inputsTotal.value = page.total
  inputGroups.value = page.groups ?? []
  inputGroupTotal.value = page.group_total ?? 0
  inputSummary.value = page.summary ?? null
  // Výpis za období, které vedl předchozí program. Koncepty se nemažou ani
  // neschovávají, jen přestávají vypadat jako práce k dodělání.
  inputsHistorical.value = page.historical === true
  inputsStartPeriod.value = page.payroll_start_period ?? null
  if (page.facets) inputFacets.value = page.facets
  if (groupBy === null) {
    const visible = new Set(page.items.map(item => item.id))
    selectedInputIds.value = selectedInputIds.value.filter(id => visible.has(id))
  }
}

/** Stránka i rozbalené skupiny — po každé akci, ať neukazují starý stav. */
async function loadInputsPage() {
  const expanded = Object.keys(expandedGroups.value).map(Number)
  await fetchInputsPage()
  expandedGroups.value = {}
  for (const key of expanded) {
    const group = inputGroups.value.find(item => item.key === key)
    if (group !== undefined) await toggleGroup(group)
  }
}

function goToInputsPage(nextPage: number) {
  inputsOffset.value = Math.max(0, (nextPage - 1) * inputsPageSize)
  expandedGroups.value = {}
  void loadInputsPage()
}

let inputSearchTimer: ReturnType<typeof setTimeout> | null = null

/** Nový výřez: stránkování na začátek, výběr pryč, filtr do adresy. */
function applyInputFilters() {
  if (inputSearchTimer !== null) {
    clearTimeout(inputSearchTimer)
    inputSearchTimer = null
  }
  inputsOffset.value = 0
  selectedInputIds.value = []
  expandedGroups.value = {}
  inputBatchFailures.value = []
  void router.replace({ query: payrollInputFiltersToQuery(route.query, inputFilters.value) })
  loadInputsPage().catch((error: any) => {
    toast.error(apiErrorMessage(error, t('payroll.components.load_failed')))
  })
}

function setInputFilters(patch: Partial<PayrollInputFilterState>) {
  inputFilters.value = { ...inputFilters.value, ...patch }
  applyInputFilters()
}

function onInputSearch(value: string) {
  inputFilters.value = { ...inputFilters.value, q: value }
  if (inputSearchTimer !== null) clearTimeout(inputSearchTimer)
  inputSearchTimer = setTimeout(applyInputFilters, 350)
}

function setComponentFilter(values: string[]) {
  setInputFilters({
    componentIds: values.map(Number).filter(id => Number.isInteger(id) && id > 0),
  })
}

function setStatusFilter(values: string[]) {
  setInputFilters({ statuses: PAYROLL_INPUT_FILTER_STATUSES.filter(status => values.includes(status)) })
}

function setSourceFilter(event: Event) {
  const value = (event.target as HTMLSelectElement).value
  setInputFilters({ sourceKind: PAYROLL_INPUT_SOURCE_KINDS.find(kind => kind === value) ?? null })
}

function setImportFilter(event: Event) {
  const id = Number((event.target as HTMLSelectElement).value)
  setInputFilters({ importId: Number.isInteger(id) && id > 0 ? id : null })
}

function setGroupBy(event: Event) {
  const value = (event.target as HTMLSelectElement).value
  setInputFilters({
    groupBy: value === 'employee' || value === 'component' || value === 'period'
      ? value
      : null,
  })
}

function clearInputFilters() {
  inputFilters.value = { ...emptyPayrollInputFilters(), groupBy: inputFilters.value.groupBy }
  applyInputFilters()
}

async function toggleGroup(group: PayrollInputGroup) {
  const key = group.key
  if (key in expandedGroups.value) {
    const next = { ...expandedGroups.value }
    delete next[key]
    expandedGroups.value = next
    return
  }
  expandedGroups.value = { ...expandedGroups.value, [key]: null }
  try {
    const groupBy = effectiveGroupBy.value
    // Měsíční skupina se rozpadá na svůj měsíc, ne na rozsah — jinak by
    // rozbalený srpen ukázal i červenec.
    const narrowing: Record<string, string | number> = groupBy === 'employee'
      ? { employee_id: key }
      : groupBy === 'component' ? { component_id: String(key) } : {}
    const page = await payrollApi.inputs(
      groupBy === 'period' ? group.label : period.value,
      { limit: GROUP_ITEMS_LIMIT, offset: 0 },
      focusEmploymentId.value ?? undefined,
      { ...inputFilterParams.value, ...narrowing },
    )
    if (key in expandedGroups.value) {
      expandedGroups.value = { ...expandedGroups.value, [key]: page.items }
    }
  } catch (error: any) {
    const next = { ...expandedGroups.value }
    delete next[key]
    expandedGroups.value = next
    toast.error(apiErrorMessage(error, t('payroll.components.load_failed')))
  }
}

/** Skupina jako filtr: celý výčet člověka nebo složky v běžném seznamu. */
function openGroupAsFilter(group: PayrollInputGroup) {
  // Měsíc není filtr, je to období — otevře se jako editovatelný měsíc.
  if (effectiveGroupBy.value === 'period') {
    openPeriodGroup(group.label)
    return
  }
  inputFilters.value = inputFilters.value.groupBy === 'employee'
    ? { ...inputFilters.value, groupBy: null, employeeId: group.key }
    : { ...inputFilters.value, groupBy: null, componentIds: [group.key] }
  applyInputFilters()
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
 * Kolik konceptů drží filtr — počítá server za CELÝ filtr. Dřív se to bralo
 * ze zobrazené stránky a na straně bez konceptu tlačítko zmizelo, i když měsíc
 * držel stovky konceptů a mzdový běh na nich stál.
 */
const matchingDraftCount = computed(() => inputSummary.value?.draft_total
  ?? inputs.value.filter(input => input.status === 'draft').length)
const summaryTotal = computed(() => inputSummary.value?.total ?? inputsTotal.value)
const summaryAmount = computed(() => inputSummary.value?.amount_total_minor
  ?? inputs.value.reduce((sum, input) => sum + input.amount_minor, 0))

/** Výřez hromadné akce: tentýž, jaký je vidět, včetně zúžení z karty zaměstnance. */
const batchFilter = computed<Record<string, string | number>>(() => ({
  ...inputFilterParams.value,
  ...(focusEmploymentId.value !== null ? { employment_id: focusEmploymentId.value } : {}),
}))

const pageDraftIds = computed(() =>
  inputs.value.filter(input => input.status === 'draft').map(input => input.id))
const allPageDraftsSelected = computed(() => pageDraftIds.value.length > 0
  && pageDraftIds.value.every(id => selectedInputIds.value.includes(id)))

function isInputSelected(id: number): boolean {
  return selectedInputIds.value.includes(id)
}

function toggleInputSelection(id: number) {
  selectedInputIds.value = isInputSelected(id)
    ? selectedInputIds.value.filter(item => item !== id)
    : [...selectedInputIds.value, id]
}

function togglePageSelection() {
  selectedInputIds.value = allPageDraftsSelected.value
    ? selectedInputIds.value.filter(id => !pageDraftIds.value.includes(id))
    : Array.from(new Set([...selectedInputIds.value, ...pageDraftIds.value]))
}

function inputStatusClass(status: PayrollInput['status']): string {
  if (status === 'approved' || status === 'locked') return 'bg-success-50 text-success-600'
  return status === 'cancelled' ? 'bg-neutral-100 text-neutral-500' : 'bg-payroll-50 text-payroll-700'
}

function reportInputBatch(result: PayrollInputBatchTotal, kind: 'approve' | 'cancel') {
  inputBatchFailures.value = groupPayrollInputFailures(result.failed)
  if (result.failed.length > 0) {
    // Konkrétní důvod, ne „nepodařilo se": u benefitů to bývá překročený
    // roční limit nebo neuzavřená docházka a uživatel s tím musí něco udělat.
    // Všechny důvody zůstávají seskupené pod lištou, ne jen první v hlášce.
    inputError.value = kind === 'approve'
      ? t('payroll.components.inputs.approve_all_partial', {
        approved: result.done,
        failed: result.failed.length,
        reason: result.failed[0].message,
      })
      : t('payroll.components.inputs.cancel_all_partial', {
        cancelled: result.done,
        failed: result.failed.length,
        reason: result.failed[0].message,
      })
    return
  }
  if (!result.complete) {
    inputError.value = t('payroll.components.inputs.batch_incomplete', { remaining: result.remaining })
    return
  }
  toast.success(kind === 'approve'
    ? t('payroll.components.inputs.approve_all_done', { count: result.done })
    : t('payroll.components.inputs.cancel_all_done', { count: result.done }))
}

/**
 * Hromadné schválení nebo zrušení — výběrem (`ids`), nebo celým filtrem.
 *
 * Filtrem jde server po dávkách bez stropu 500 a na velkém měsíci vrací
 * kurzor, od kterého se pokračuje. Výběr se posílá po pěti stech, protože
 * víc server v jednom výčtu nevezme.
 */
async function runInputBatch(kind: 'approve' | 'cancel', ids: number[] | null) {
  // Pojistka k tomu, že se tlačítka nad rozsahem nezobrazují: hromadná akce
  // jede vždy nad JEDNÍM obdobím (`period`), takže spuštěná nad rozsahem by
  // buď sáhla na jiný měsíc, než je vidět, nebo by ji server odmítl.
  if (batchDisabledInRange.value) return
  inputError.value = ''
  inputBatchFailures.value = []
  saving.value = true
  batchProgress.value = 0
  const chunks: number[][] = []
  for (let index = 0; ids !== null && index < ids.length; index += 500) {
    chunks.push(ids.slice(index, index + 500))
  }
  let chunk = 0
  const send = async (afterId: number): Promise<PayrollInputBatchPass> => {
    const payload = ids === null
      ? { period: period.value, filter: batchFilter.value, after_id: afterId }
      : { ids: chunks[chunk] ?? [] }
    const pass = kind === 'approve'
      ? await payrollApi.approveInputsBatch(payload).then(result => ({ ...result, done: result.approved }))
      : await payrollApi.cancelInputsBatch(payload).then(result => ({ ...result, done: result.cancelled }))
    if (ids === null) return pass
    chunk++
    return { ...pass, complete: chunk >= chunks.length, next_after_id: chunk }
  }
  try {
    const result = await runPayrollInputBatch(send, (done) => { batchProgress.value = done })
    selectedInputIds.value = []
    await loadInputsPage()
    reportInputBatch(result, kind)
  } catch (error: any) {
    inputError.value = apiErrorMessage(error, t(kind === 'approve'
      ? 'payroll.components.inputs.approve_failed'
      : 'payroll.components.inputs.cancel_failed'))
  } finally {
    saving.value = false
    batchProgress.value = null
  }
}

/**
 * Schválit všechny koncepty odpovídající filtru.
 *
 * Po jednom to je při 500 zaměstnancích zhruba tisíc kliknutí na obrazovce,
 * kam uživatel přišel jen kvůli blokátoru `draft_inputs_present`.
 */
async function approveMatchingInputs() {
  await runInputBatch('approve', null)
}

async function cancelMatchingInputs() {
  if (!window.confirm(t('payroll.components.inputs.cancel_matching_confirm', {
    count: matchingDraftCount.value,
  }))) return
  await runInputBatch('cancel', null)
}

async function approveSelectedInputs() {
  await runInputBatch('approve', [...selectedInputIds.value])
}

async function cancelSelectedInputs() {
  if (!window.confirm(t('payroll.components.inputs.cancel_selected_confirm', {
    count: selectedInputIds.value.length,
  }))) return
  await runInputBatch('cancel', [...selectedInputIds.value])
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
  // Rozsah bez zúžení nedává smysl — byla by to historie celé firmy.
  scope.value = 'month'
  // Obojí se zúžením mění obsah, takže obě stránky musí zpět na začátek.
  recurringOffset.value = 0
  inputsOffset.value = 0
  selectedInputIds.value = []
  expandedGroups.value = {}
  const query = payrollPeriodScopeToQuery(route.query, 'month')
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
        <!--
          Rozsah se nabízí jen na záložce vstupů a jen při zúžení na jeden
          vztah. Nad celou firmou by „Vše" znamenalo vypsat roky práce všech
          zaměstnanců; u jednoho člověka je to naopak ta otázka, se kterou se
          na jeho kartu chodí.
        -->
        <div v-if="activeTab === 'inputs' && focusEmploymentId !== null" class="block">
          <span class="mb-1 block text-xs font-medium text-neutral-600">{{ t('payroll.agendas.scope.label') }}</span>
          <PayrollPeriodScopePicker
            :model-value="scope"
            :year="period.slice(0, 4)"
            :disabled="loading"
            @update:model-value="changeScope"
          />
        </div>
        <button :class="btnOutline('neutral')" :disabled="loading" @click="load">
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.cycle" /></svg>
          {{ t('payroll.components.reload') }}
        </button>
        <div class="relative">
          <button
            type="button"
            data-testid="payroll-header-export"
            :class="[btnOutline('neutral'), 'whitespace-nowrap']"
            aria-haspopup="menu"
            :aria-expanded="headerExportOpen"
            :aria-busy="exportingInputs !== null"
            :disabled="exportingInputs !== null"
            :title="t('payroll.components.inputs.export_inputs_hint')"
            @click="headerExportOpen = !headerExportOpen"
          >
            <svg v-if="exportingInputs !== null" class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" /><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8v4a4 4 0 0 0-4 4H4z" /></svg>
            <svg v-else class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.download" /></svg>
            {{ t('payroll.components.inputs.export_inputs') }}
            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.chevron" /></svg>
          </button>
          <template v-if="headerExportOpen">
            <div class="fixed inset-0 z-[60]" aria-hidden="true" @click="headerExportOpen = false" />
            <div
              role="menu"
              data-testid="payroll-header-export-menu"
              class="absolute right-0 z-[61] mt-1 w-64 max-w-[calc(100vw-2rem)] rounded-lg border border-neutral-200 bg-surface py-1 text-sm shadow-xl"
              @keydown.esc="headerExportOpen = false"
            >
              <p class="border-b border-neutral-100 px-3 py-2 text-xs text-neutral-500">
                {{ t('payroll.components.inputs.export_inputs_period', { period: listPeriodLabel }) }}
              </p>
              <button type="button" role="menuitem" data-testid="payroll-header-export-xlsx" class="flex w-full cursor-pointer items-center gap-2.5 px-3 py-2 text-left text-neutral-700 hover:bg-neutral-50" @click="exportInputsFromHeader('xlsx')">
                <svg class="h-4 w-4 shrink-0 text-neutral-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path :d="ICONS.table" /></svg>
                {{ t('payroll.components.inputs.export_xlsx') }}
              </button>
              <button type="button" role="menuitem" data-testid="payroll-header-export-pdf" class="flex w-full cursor-pointer items-center gap-2.5 px-3 py-2 text-left text-neutral-700 hover:bg-neutral-50" @click="exportInputsFromHeader('pdf')">
                <svg class="h-4 w-4 shrink-0 text-neutral-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path :d="ICONS.download" /></svg>
                {{ t('payroll.components.inputs.export_pdf') }}
              </button>
            </div>
          </template>
        </div>
      </div>
    </header>

    <PayrollFocusNotice
      v-if="focusMissing"
      :name="String(focusEmploymentId)"
      missing
      @clear="clearFocus"
    />
    <PayrollFocusNotice v-else-if="focusName" :name="focusName" :empty="focusEmpty" @clear="clearFocus" />

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
            <label class="block"><span class="mb-1 block text-xs text-neutral-600">{{ t('payroll.components.fields.valid_from') }}</span><DateInput v-model="componentForm.valid_from" :disabled="!!editingComponent" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm disabled:bg-neutral-100" /></label>
            <label class="block"><span class="mb-1 block text-xs text-neutral-600">{{ t('payroll.components.fields.valid_to') }}</span><DateInput v-model="componentForm.valid_to" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm" /></label>
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
            <p v-if="jmhzState(editingJmhzComponent).review_hint" class="mt-3 rounded-lg border border-warning-500/30 bg-warning-50 px-4 py-3 text-sm text-warning-700" data-testid="payroll-jmhz-review-hint-detail">{{ t('payroll.components.jmhz.review_hint') }}</p>
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
              <tbody class="divide-y divide-neutral-100"><tr v-for="component in components" :key="component.id"><td class="px-4 py-3 font-mono text-xs font-semibold text-neutral-900">{{ component.code }}</td><td class="px-4 py-3">{{ component.name }}</td><td class="px-4 py-3">{{ t(`payroll.components.kind.${component.component_kind}`) }}</td><td class="px-4 py-3">{{ t(`payroll.components.frequency.${component.frequency_kind}`) }}</td><td class="px-4 py-3"><span class="rounded-full px-2 py-1 text-xs font-medium" :class="jmhzBadgeClass(component)">{{ t(`payroll.components.jmhz.status.${jmhzState(component).status}`) }}</span><span v-if="jmhzState(component).review_hint" class="mt-1 block text-xs text-warning-700" data-testid="payroll-jmhz-review-hint">{{ t('payroll.components.jmhz.review_hint_short') }}</span><p v-if="jmhzState(component).mapping?.is_active" class="mt-1 font-mono text-xs text-neutral-500">{{ jmhzState(component).mapping?.target_attribute_id }}</p></td><td class="px-4 py-3 text-xs">{{ component.valid_from }} – {{ component.valid_to ?? t('payroll.components.open_ended') }}</td><td class="px-4 py-3"><span class="rounded-full px-2 py-1 text-xs font-medium" :class="component.is_active ? 'bg-success-50 text-success-600' : 'bg-neutral-100 text-neutral-600'">{{ t(component.is_active ? 'payroll.components.active' : 'payroll.components.inactive') }}</span></td><td class="px-4 py-3"><div class="flex flex-wrap justify-end gap-2"><button v-if="canWrite && component.jmhz_treatment === 'included'" :class="btnOutlineSm(jmhzState(component).status === 'missing' || jmhzState(component).review_hint ? 'warning' : 'neutral')" :disabled="jmhzLoading" @click="openJmhzMapping(component)"><svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.link" /></svg>{{ t('payroll.components.jmhz.configure') }}</button><button v-if="canWrite" :class="btnOutlineSm('neutral')" @click="editComponent(component)"><svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.edit" /></svg>{{ t('common.edit') }}</button><button v-if="canWrite" data-testid="payroll-component-delete" :class="btnOutlineSm('danger')" :disabled="saving" @click="deleteComponent(component)"><svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.trash" /></svg>{{ t('payroll.components.catalog.delete') }}</button></div></td></tr></tbody>
            </table>
          </div>
          <div data-layout="mobile" class="space-y-3 p-4 md:hidden">
            <article v-for="component in components" :key="component.id" class="rounded-lg border border-neutral-200 p-4">
              <div class="flex flex-wrap items-start justify-between gap-2"><div><p class="font-mono text-xs font-semibold text-payroll-700">{{ component.code }}</p><h3 class="mt-1 font-semibold text-neutral-900">{{ component.name }}</h3></div><span class="rounded-full px-2 py-1 text-xs font-medium" :class="component.is_active ? 'bg-success-50 text-success-600' : 'bg-neutral-100 text-neutral-600'">{{ t(component.is_active ? 'payroll.components.active' : 'payroll.components.inactive') }}</span></div>
              <dl class="mt-3 grid grid-cols-2 gap-3 text-sm"><div><dt class="text-xs text-neutral-500">{{ t('payroll.components.fields.kind') }}</dt><dd>{{ t(`payroll.components.kind.${component.component_kind}`) }}</dd></div><div><dt class="text-xs text-neutral-500">{{ t('payroll.components.fields.frequency') }}</dt><dd>{{ t(`payroll.components.frequency.${component.frequency_kind}`) }}</dd></div><div><dt class="text-xs text-neutral-500">{{ t('payroll.components.fields.jmhz_treatment') }}</dt><dd><span class="rounded-full px-2 py-1 text-xs font-medium" :class="jmhzBadgeClass(component)">{{ t(`payroll.components.jmhz.status.${jmhzState(component).status}`) }}</span><span v-if="jmhzState(component).review_hint" class="mt-1 block text-xs text-warning-700" data-testid="payroll-jmhz-review-hint">{{ t('payroll.components.jmhz.review_hint_short') }}</span><span v-if="jmhzState(component).mapping?.is_active" class="ml-2 font-mono text-xs">{{ jmhzState(component).mapping?.target_attribute_id }}</span></dd></div><div><dt class="text-xs text-neutral-500">{{ t('payroll.components.fields.validity') }}</dt><dd>{{ component.valid_from }} – {{ component.valid_to ?? t('payroll.components.open_ended') }}</dd></div></dl>
              <div v-if="canWrite" class="mt-4 flex flex-wrap gap-2"><button v-if="component.jmhz_treatment === 'included'" :class="btnOutlineSm(jmhzState(component).status === 'missing' || jmhzState(component).review_hint ? 'warning' : 'neutral')" :disabled="jmhzLoading" @click="openJmhzMapping(component)"><svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.link" /></svg>{{ t('payroll.components.jmhz.configure') }}</button><button :class="btnOutlineSm('neutral')" @click="editComponent(component)"><svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.edit" /></svg>{{ t('common.edit') }}</button><button data-testid="payroll-component-delete" :class="btnOutlineSm('danger')" :disabled="saving" @click="deleteComponent(component)"><svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.trash" /></svg>{{ t('payroll.components.catalog.delete') }}</button></div>
            </article>
          </div>
        </section>
      </section>

      <section v-if="activeTab === 'recurring'" class="space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
          <!--
            Bez přepínače rozsahu schválně: server předpisy podle období
            NEFILTRUJE, vrací celou historii vztahu. Ovládací prvek, který nic
            nemění, je horší než žádný — místo něj to říká podnadpis.
          -->
          <div>
            <h2 class="text-lg font-semibold text-neutral-900">{{ t('payroll.components.recurring.title') }}</h2>
            <p class="text-sm text-neutral-500">{{ t('payroll.components.recurring.hint') }}</p>
            <p class="text-sm text-neutral-500" data-testid="payroll-recurring-all-periods">{{ t('payroll.components.recurring.all_periods') }}</p>
          </div>
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
            <label class="block"><span class="mb-1 block text-xs text-neutral-600">{{ t('payroll.components.fields.valid_from') }}</span><DateInput v-model="recurringForm.valid_from" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm" /></label>
            <label class="block"><span class="mb-1 block text-xs text-neutral-600">{{ t('payroll.components.fields.valid_to') }}</span><DateInput v-model="recurringForm.valid_to" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm" /></label>
            <label class="block sm:col-span-2"><span class="mb-1 block text-xs text-neutral-600">{{ t('payroll.components.fields.note') }}</span><input v-model="recurringForm.note" maxlength="500" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm"></label>
            <label class="inline-flex items-center gap-2 self-end text-sm text-neutral-700"><input v-model="recurringForm.is_active" type="checkbox" class="rounded border-neutral-300 text-payroll-600"> {{ t('payroll.components.fields.active') }}</label>
          </div>
          <div class="mt-5 flex flex-wrap justify-end gap-2"><button :class="btnFilled('primary')" :disabled="saving" @click="saveRecurring"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.check" /></svg>{{ t('common.save') }}</button></div>
        </section>

        <section class="rounded-xl border border-neutral-200 bg-surface shadow-sm">
          <div class="hidden flex-wrap items-center justify-end gap-2 border-b border-neutral-200 px-4 py-2 md:flex"><ColumnPicker :ctrl="recurringTbl" /><DensityToggle :ctrl="recurringTbl" /></div>
          <!--
            Prázdno se musí pojmenovat. Dokud tu nic nebylo, vypadal vztah bez
            předpisů stejně jako neplatný odkaz — a lišta nahoře o něm tvrdila,
            že „odkaz zestaral".
          -->
          <EmptyState
            v-if="recurring.length === 0"
            class="m-4"
            data-testid="payroll-recurring-empty"
            :title="t(focusEmploymentId === null
              ? 'payroll.components.recurring.empty'
              : 'payroll.components.recurring.empty_focused')"
            :message="t('payroll.components.recurring.empty_hint')"
          />
          <div v-if="recurring.length > 0" data-layout="desktop" class="hidden overflow-x-auto md:block"><table v-column-labels="recurringTbl" class="min-w-full divide-y divide-neutral-200 text-sm" :class="recurringTbl.densityClass.value"><thead><tr class="text-left text-xs uppercase tracking-wide text-neutral-500"><th v-if="recurringTbl.isVisible('employment')" class="px-4 py-3">{{ t('payroll.components.fields.employment') }}</th><th v-if="recurringTbl.isVisible('component')" class="px-4 py-3">{{ t('payroll.components.fields.component') }}</th><th v-if="recurringTbl.isVisible('calculation')" class="px-4 py-3">{{ t('payroll.components.fields.calculation') }}</th><th v-if="recurringTbl.isVisible('validity')" class="px-4 py-3">{{ t('payroll.components.fields.validity') }}</th><th v-if="recurringTbl.isVisible('status')" class="px-4 py-3">{{ t('payroll.components.fields.status') }}</th><th v-if="recurringTbl.isVisible('actions')" class="px-4 py-3 text-right">{{ t('payroll.components.fields.actions') }}</th></tr></thead><tbody class="divide-y divide-neutral-100"><tr v-for="item in recurring" :key="item.id"><td v-if="recurringTbl.isVisible('employment')" class="px-4 py-3"><p class="font-medium text-neutral-900">{{ item.employee_name }}</p><p class="text-xs text-neutral-500">{{ item.employment_code }}</p></td><td v-if="recurringTbl.isVisible('component')" class="px-4 py-3"><p>{{ item.component_name }}</p><p class="font-mono text-xs text-neutral-500">{{ item.component_code }}</p></td><td v-if="recurringTbl.isVisible('calculation')" class="px-4 py-3"><p>{{ t(`payroll.components.calculation.${item.calculation_kind}`) }}</p><p class="text-xs text-neutral-500">{{ item.amount_minor !== null ? formatMoney(item.amount_minor) : item.rate_basis_points !== null ? `${item.rate_basis_points / 100} %` : '—' }}</p></td><td v-if="recurringTbl.isVisible('validity')" class="px-4 py-3 text-xs">{{ item.valid_from }} – {{ item.valid_to ?? t('payroll.components.open_ended') }}</td><td v-if="recurringTbl.isVisible('status')" class="px-4 py-3"><span class="rounded-full px-2 py-1 text-xs font-medium" :class="recurringStateClass(item)" :data-state="recurringState(item)">{{ t(`payroll.components.recurring.state.${recurringState(item)}`) }}</span></td><td v-if="recurringTbl.isVisible('actions')" class="px-4 py-3"><div class="flex flex-wrap justify-end gap-2"><button v-if="canWrite" :class="btnOutlineSm('neutral')" @click="editRecurring(item)"><svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.edit" /></svg>{{ t('common.edit') }}</button><button v-if="canWrite" data-testid="payroll-recurring-delete" :class="btnOutlineSm('danger')" :disabled="saving" @click="deleteRecurring(item)"><svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.trash" /></svg>{{ t('payroll.components.recurring.delete') }}</button></div></td></tr></tbody></table></div>
          <div v-if="recurring.length > 0" data-layout="mobile" class="space-y-3 p-4 md:hidden"><article v-for="item in recurring" :key="item.id" class="rounded-lg border border-neutral-200 p-4"><div class="flex flex-wrap items-start justify-between gap-2"><div><h3 class="font-semibold text-neutral-900">{{ item.employee_name }}</h3><p class="text-xs text-neutral-500">{{ item.employment_code }} · {{ item.component_code }}</p></div><span class="rounded-full px-2 py-1 text-xs font-medium" :class="recurringStateClass(item)" :data-state="recurringState(item)">{{ t(`payroll.components.recurring.state.${recurringState(item)}`) }}</span></div><dl class="mt-3 grid grid-cols-2 gap-3 text-sm"><div><dt class="text-xs text-neutral-500">{{ t('payroll.components.fields.component') }}</dt><dd>{{ item.component_name }}</dd></div><div><dt class="text-xs text-neutral-500">{{ t('payroll.components.fields.amount') }}</dt><dd>{{ item.amount_minor !== null ? formatMoney(item.amount_minor) : item.rate_basis_points !== null ? `${item.rate_basis_points / 100} %` : '—' }}</dd></div><div class="col-span-2"><dt class="text-xs text-neutral-500">{{ t('payroll.components.fields.validity') }}</dt><dd>{{ item.valid_from }} – {{ item.valid_to ?? t('payroll.components.open_ended') }}</dd></div></dl><div v-if="canWrite" class="mt-4 flex flex-wrap gap-2"><button :class="btnOutlineSm('neutral')" @click="editRecurring(item)"><svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.edit" /></svg>{{ t('common.edit') }}</button><button data-testid="payroll-recurring-delete" :class="btnOutlineSm('danger')" :disabled="saving" @click="deleteRecurring(item)"><svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.trash" /></svg>{{ t('payroll.components.recurring.delete') }}</button></div></article></div>
          <PaginationBar
            v-if="recurring.length > 0"
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
          <p v-if="editingImported" class="mt-3 text-xs text-neutral-600" data-testid="payroll-input-origin-hint">{{ t('payroll.components.inputs.edit_origin_hint') }}</p>
          <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <div class="block"><span class="mb-1 block text-xs text-neutral-600">{{ t('payroll.components.fields.employee') }}</span><PayrollPersonSearchSelect :model-value="inputForm.employee_id" data-test="payroll-input-person" :candidates="personOptions" :label="t('payroll.components.fields.employee')" :clearable="false" :disabled="editingImported" @update:model-value="selectInputEmployee" /></div>
            <label class="block"><span class="mb-1 block text-xs text-neutral-600">{{ t('payroll.components.fields.employment') }}</span><SearchableSelect :model-value="inputForm.employment_id" data-test="payroll-input-employment" :options="inputEmploymentOptions" :clearable="false" :disabled="editingImported" :no-results-label="t('payroll.components.no_results')" accent="payroll" @update:model-value="selectInputEmployment($event)" /></label>
            <label class="block sm:col-span-2"><span class="mb-1 block text-xs text-neutral-600">{{ t('payroll.components.fields.component') }}</span><SearchableSelect :model-value="inputForm.component_id" :options="inputComponentOptions" :clearable="false" :disabled="editingImported" :no-results-label="t('payroll.components.no_results')" accent="payroll" @update:model-value="inputForm.component_id = $event" /></label>
            <label class="block"><span class="mb-1 block text-xs text-neutral-600">{{ t('payroll.components.fields.amount') }}</span><input v-model="inputForm.amount" data-testid="payroll-input-amount" inputmode="decimal" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm"></label>
            <label class="block"><span class="mb-1 block text-xs text-neutral-600">{{ t('payroll.components.fields.quantity') }}</span><input v-model="inputForm.quantity" data-testid="payroll-input-quantity" inputmode="decimal" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm"></label>
            <label class="block"><span class="mb-1 block text-xs text-neutral-600">{{ t('payroll.components.fields.source_period') }}</span><input v-model="inputForm.source_period" type="month" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm"></label>
            <label class="block"><span class="mb-1 block text-xs text-neutral-600">{{ t('payroll.components.fields.external_id') }}</span><input v-model="inputForm.external_id" maxlength="190" :disabled="editingImported" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 font-mono text-sm disabled:bg-neutral-100"></label>
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

        <section class="rounded-xl border border-neutral-200 bg-surface shadow-sm" data-testid="payroll-inputs-list">
          <!--
            Filtr žije v adrese (sdílený odkaz, obnovení stránky) a stejný výřez
            dostává hromadné schválení i zrušení — nemůžou se rozejít.
          -->
          <div class="flex flex-wrap items-end gap-2 border-b border-neutral-200 px-4 py-3" data-testid="payroll-inputs-filters">
            <label class="block w-full sm:w-56">
              <span class="mb-1 block text-xs text-neutral-600">{{ t('payroll.components.inputs.filters.search') }}</span>
              <input :value="inputFilters.q" type="search" maxlength="100" data-testid="payroll-inputs-filter-q" :placeholder="t('payroll.components.inputs.filters.search_placeholder')" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm" @input="onInputSearch(($event.target as HTMLInputElement).value)">
            </label>
            <div class="block w-full sm:w-60">
              <span class="mb-1 block text-xs text-neutral-600">{{ t('payroll.components.fields.employee') }}</span>
              <PayrollPersonSearchSelect :model-value="inputFilters.employeeId" data-test="payroll-inputs-filter-employee" :candidates="personOptions" :label="t('payroll.components.fields.employee')" :placeholder="t('payroll.components.inputs.filters.all_people')" @update:model-value="setInputFilters({ employeeId: $event })" />
            </div>
            <MultiSelectFilter data-testid="payroll-inputs-filter-component" :model-value="inputFilters.componentIds.map(String)" :options="componentFilterOptions" :label="t('payroll.components.inputs.filters.component_all')" :active-label="t('payroll.components.inputs.filters.component')" @update:model-value="setComponentFilter" />
            <MultiSelectFilter data-testid="payroll-inputs-filter-status" :model-value="inputFilters.statuses" :options="statusFilterOptions" :label="t('payroll.components.inputs.filters.status_all')" :active-label="t('payroll.components.inputs.filters.status')" @update:model-value="setStatusFilter" />
            <select :value="inputFilters.sourceKind ?? ''" data-testid="payroll-inputs-filter-source" :aria-label="t('payroll.components.inputs.filters.source')" class="h-9 rounded-md border border-neutral-300 bg-surface px-2 text-sm" @change="setSourceFilter">
              <option value="">{{ t('payroll.components.inputs.filters.source_all') }}</option>
              <option v-for="kind in PAYROLL_INPUT_SOURCE_KINDS" :key="kind" :value="kind">{{ t(`payroll.components.source.${kind}`) }}</option>
            </select>
            <select v-if="inputFacets.imports.length > 0 || inputFilters.importId !== null" :value="inputFilters.importId ?? ''" data-testid="payroll-inputs-filter-import" :aria-label="t('payroll.components.inputs.filters.import')" class="h-9 max-w-full rounded-md border border-neutral-300 bg-surface px-2 text-sm" @change="setImportFilter">
              <option value="">{{ t('payroll.components.inputs.filters.import_all') }}</option>
              <option v-if="inputFilters.importId !== null && !inputFacets.imports.some(batch => batch.id === inputFilters.importId)" :value="inputFilters.importId">#{{ inputFilters.importId }}</option>
              <option v-for="batch in inputFacets.imports" :key="batch.id" :value="batch.id">{{ t('payroll.components.inputs.filters.import_option', { name: batch.source_name, date: batch.created_at.slice(0, 16), count: batch.count }) }}</option>
            </select>
            <select :value="effectiveGroupBy ?? ''" data-testid="payroll-inputs-group-by" :aria-label="t('payroll.components.inputs.filters.group_by')" class="h-9 rounded-md border border-neutral-300 bg-surface px-2 text-sm" @change="setGroupBy">
              <option value="">{{ t('payroll.components.inputs.filters.group_none') }}</option>
              <option value="employee">{{ t('payroll.components.inputs.filters.group_employee') }}</option>
              <option value="component">{{ t('payroll.components.inputs.filters.group_component') }}</option>
              <!-- Řádek na měsíc dává smysl jen nad rozsahem; nad jedním obdobím by to byla jediná skupina. -->
              <option v-if="rangeMode" value="period">{{ t('payroll.components.inputs.filters.group_period') }}</option>
            </select>
            <button v-if="inputFiltersOn" type="button" data-testid="payroll-inputs-filter-clear" :class="[btnOutline('neutral'), 'whitespace-nowrap']" @click="clearInputFilters">
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.x" /></svg>
              {{ t('payroll.components.inputs.filters.clear') }}
            </button>
            <span v-if="effectiveGroupBy === null" class="ml-auto hidden items-center gap-2 md:inline-flex"><ColumnPicker :ctrl="inputsTbl" /><DensityToggle :ctrl="inputsTbl" /></span>
          </div>

          <!--
            Souhrn a hromadné akce jsou za CELÝ filtr, ne za stránku — tlačítko
            zůstává, dokud filtr drží aspoň jeden koncept.

            Nad rozsahem měsíců se hromadné akce nenabízejí vůbec: jedou přes
            všechny koncepty filtru nad JEDNÍM obdobím, takže by jedno kliknutí
            sáhlo na měsíce, které jsou na obrazovce jen jako historie. Radši
            tlačítko schovat a říct proč, než ho zašedit bez vysvětlení.
          -->
          <div class="flex flex-wrap items-center justify-between gap-3 border-b border-neutral-200 bg-neutral-50 px-4 py-2" data-testid="payroll-inputs-summary">
            <p class="text-sm text-neutral-700">
              {{ t('payroll.components.inputs.summary', { total: summaryTotal, amount: formatMoney(summaryAmount), drafts: matchingDraftCount }) }}
              <span v-if="rangeMode" class="block text-xs text-neutral-500">{{ t('payroll.components.inputs.range_summary', { period: listPeriodLabel }) }}</span>
              <span v-if="batchDisabledInRange && matchingDraftCount > 0" :class="[BTN_DISABLED_NOTE, 'block']" data-testid="payroll-inputs-batch-range-note">{{ t('payroll.components.inputs.batch_range_blocked') }}</span>
              <!--
                Období, které vedl předchozí program. Koncepty zůstávají ve
                výpisu i v souhrnu — schvalovat je ale není proč.
              -->
              <span v-if="inputsHistorical" class="mt-1 block text-xs text-neutral-500" data-testid="payroll-inputs-historical-notice">
                <span class="mr-1 rounded-full bg-neutral-200 px-2 py-0.5 font-medium text-neutral-700">{{ t('payroll.historical.badge') }}</span>
                {{ t('payroll.historical.inputs', { period: formatPeriod(inputsStartPeriod ?? '') }) }}
              </span>
            </p>
            <div class="flex flex-wrap items-center gap-2">
              <button type="button" data-testid="payroll-inputs-export-xlsx" :class="[btnOutline('neutral'), 'whitespace-nowrap']" :disabled="exportingInputs !== null || summaryTotal === 0" :aria-busy="exportingInputs === 'xlsx'" :title="t('payroll.components.inputs.export_hint')" @click="exportInputs('xlsx')">
                <svg v-if="exportingInputs === 'xlsx'" class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" /><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8v4a4 4 0 0 0-4 4H4z" /></svg>
                <svg v-else class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.table" /></svg>
                {{ t('payroll.components.inputs.export_xlsx') }}
              </button>
              <button type="button" data-testid="payroll-inputs-export-pdf" :class="[btnOutline('neutral'), 'whitespace-nowrap']" :disabled="exportingInputs !== null || summaryTotal === 0" :aria-busy="exportingInputs === 'pdf'" :title="t('payroll.components.inputs.export_hint')" @click="exportInputs('pdf')">
                <svg v-if="exportingInputs === 'pdf'" class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" /><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8v4a4 4 0 0 0-4 4H4z" /></svg>
                <svg v-else class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.download" /></svg>
                {{ t('payroll.components.inputs.export_pdf') }}
              </button>
              <span v-if="batchProgress !== null" role="status" class="text-xs text-neutral-500">{{ t('payroll.components.inputs.batch_progress', { done: batchProgress }) }}</span>
              <button v-if="canWrite && matchingDraftCount > 0 && !batchDisabledInRange" type="button" data-testid="payroll-inputs-cancel-matching" :class="[btnOutline('danger'), 'whitespace-nowrap']" :disabled="saving" @click="cancelMatchingInputs">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.trash" /></svg>
                {{ t('payroll.components.inputs.cancel_matching', { count: matchingDraftCount }) }}
              </button>
              <button v-if="canApprove && matchingDraftCount > 0 && !batchDisabledInRange" type="button" data-testid="payroll-inputs-approve-all" :class="[btnFilled('success'), 'whitespace-nowrap']" :disabled="saving" @click="approveMatchingInputs">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.badgeCheck" /></svg>
                {{ t('payroll.components.inputs.approve_matching', { count: matchingDraftCount }) }}
              </button>
            </div>
          </div>

          <div v-if="selectedInputIds.length > 0 && !batchDisabledInRange" class="flex flex-wrap items-center gap-2 border-b border-payroll-200 bg-payroll-50 px-4 py-2" data-testid="payroll-inputs-selection">
            <span class="text-sm font-medium text-payroll-700">{{ t('payroll.components.inputs.selection_count', { count: selectedInputIds.length }) }}</span>
            <button v-if="canApprove" type="button" data-testid="payroll-inputs-approve-selected" :class="[btnOutline('success'), 'whitespace-nowrap']" :disabled="saving" @click="approveSelectedInputs">
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.badgeCheck" /></svg>
              {{ t('payroll.components.inputs.approve_selected') }}
            </button>
            <button v-if="canWrite" type="button" data-testid="payroll-inputs-cancel-selected" :class="[btnOutline('danger'), 'whitespace-nowrap']" :disabled="saving" @click="cancelSelectedInputs">
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.trash" /></svg>
              {{ t('payroll.components.inputs.cancel_selected') }}
            </button>
            <button type="button" :class="[btnOutline('neutral'), 'whitespace-nowrap']" @click="selectedInputIds = []">
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.x" /></svg>
              {{ t('payroll.components.inputs.clear_selection') }}
            </button>
          </div>

          <!-- Co hromadná akce nezpracovala, zůstává tady — seskupené po důvodu. -->
          <div v-if="inputBatchFailures.length > 0" class="border-b border-danger-200 bg-danger-50 px-4 py-3 text-xs text-danger-700" data-testid="payroll-inputs-batch-failures">
            <p class="font-medium">{{ t('payroll.components.inputs.batch_failed_title') }}</p>
            <ul class="mt-1 space-y-0.5">
              <li v-for="failure in inputBatchFailures" :key="failure.message">{{ failure.count > 1 ? t('payroll.components.inputs.batch_failed_row', { count: failure.count, reason: failure.message }) : failure.message }}</li>
            </ul>
            <button type="button" :class="[btnOutlineSm('neutral'), 'mt-2 inline-flex whitespace-nowrap']" @click="inputBatchFailures = []">
              <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.x" /></svg>
              {{ t('payroll.components.inputs.batch_failed_dismiss') }}
            </button>
          </div>

          <div v-if="inputListEmpty" class="p-8 text-center">
            <template v-if="inputFiltersOn">
              <h3 class="font-semibold text-neutral-900">{{ t('payroll.components.inputs.empty_filtered') }}</h3>
              <p class="mt-1 text-sm text-neutral-500">{{ t('payroll.components.inputs.empty_filtered_hint') }}</p>
              <button type="button" :class="[btnOutline('neutral'), 'mt-3 whitespace-nowrap']" @click="clearInputFilters">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.x" /></svg>
                {{ t('payroll.components.inputs.filters.clear') }}
              </button>
            </template>
            <template v-else>
              <h3 class="font-semibold text-neutral-900">{{ t('payroll.components.inputs.empty') }}</h3>
              <p class="mt-1 text-sm text-neutral-500">{{ t('payroll.components.inputs.empty_hint') }}</p>
            </template>
          </div>
          <template v-else-if="effectiveGroupBy === null">
            <div data-layout="desktop" class="hidden overflow-x-auto md:block"><table v-column-labels="inputsTbl" class="min-w-full divide-y divide-neutral-200 text-sm" :class="inputsTbl.densityClass.value"><thead><tr class="text-left text-xs uppercase tracking-wide text-neutral-500"><th class="w-10 px-4 py-3"><input v-if="pageDraftIds.length > 0 && !batchDisabledInRange" type="checkbox" class="rounded border-neutral-300 text-payroll-600" data-testid="payroll-inputs-select-page" :checked="allPageDraftsSelected" :aria-label="t('payroll.components.inputs.select_page')" @change="togglePageSelection"></th><th v-if="rangeMode" class="px-4 py-3">{{ t('payroll.components.fields.period') }}</th><th class="px-4 py-3">{{ t('payroll.components.fields.employment') }}</th><th class="px-4 py-3">{{ t('payroll.components.fields.component') }}</th><th class="px-4 py-3">{{ t('payroll.components.fields.amount') }}</th><th v-if="inputsTbl.isVisible('source')" class="px-4 py-3">{{ t('payroll.components.fields.source') }}</th><th v-if="inputsTbl.isVisible('status')" class="px-4 py-3">{{ t('payroll.components.fields.status') }}</th><th v-if="inputsTbl.isVisible('external_id')" class="px-4 py-3">{{ t('payroll.components.fields.external_id') }}</th><th class="px-4 py-3 text-right">{{ t('payroll.components.fields.actions') }}</th></tr></thead><tbody class="divide-y divide-neutral-100"><tr v-for="input in inputs" :key="input.id" :class="isInputSelected(input.id) ? 'bg-payroll-50/60' : ''"><td class="px-4 py-3"><input v-if="input.status === 'draft' && !batchDisabledInRange" type="checkbox" class="rounded border-neutral-300 text-payroll-600" :data-testid="`payroll-input-select-${input.id}`" :checked="isInputSelected(input.id)" :aria-label="t('payroll.components.inputs.select_row')" @change="toggleInputSelection(input.id)"></td><td v-if="rangeMode" class="px-4 py-3 whitespace-nowrap font-medium tabular-nums text-neutral-900" :data-testid="`payroll-input-period-${input.id}`">{{ formatPeriod(input.period_start) }}</td><td class="px-4 py-3"><p class="font-medium text-neutral-900">{{ input.employee_name }}</p><p class="text-xs text-neutral-500">{{ relationLabel(input.relation_type) }}</p><p class="font-mono text-[11px] text-neutral-400">{{ input.employment_code }}</p></td><td class="px-4 py-3"><p>{{ input.component_name }}</p><p class="font-mono text-xs text-neutral-500">{{ input.component_code }}</p></td><td class="px-4 py-3 font-medium">{{ formatMoney(input.amount_minor) }}</td><td v-if="inputsTbl.isVisible('source')" class="px-4 py-3">{{ t(`payroll.components.source.${input.source_kind}`) }}</td><td v-if="inputsTbl.isVisible('status')" class="px-4 py-3"><span class="rounded-full px-2 py-1 text-xs font-medium" :class="inputStatusClass(input.status)">{{ t(`payroll.components.input_status.${input.status}`) }}</span></td><td v-if="inputsTbl.isVisible('external_id')" class="px-4 py-3 break-all font-mono text-xs text-neutral-500">{{ input.external_id ?? '—' }}</td><td class="px-4 py-3"><PayrollInputRowActions class="justify-end" :input="input" :can-write="canWrite" :can-approve="canApprove" :saving="saving" @edit="editInput" @cancel="cancelInput" @approve="approveInput" @reverse-benefit="reverseBenefitInput" /></td></tr></tbody></table></div>
            <div data-layout="mobile" class="space-y-3 p-4 md:hidden"><article v-for="input in inputs" :key="input.id" class="rounded-lg border border-neutral-200 p-4" :class="isInputSelected(input.id) ? 'border-payroll-300 bg-payroll-50/60' : ''"><div class="flex flex-wrap items-start justify-between gap-2"><label class="flex items-start gap-2"><input v-if="input.status === 'draft' && !batchDisabledInRange" type="checkbox" class="mt-1 rounded border-neutral-300 text-payroll-600" :checked="isInputSelected(input.id)" :aria-label="t('payroll.components.inputs.select_row')" @change="toggleInputSelection(input.id)"><span><span class="block font-semibold text-neutral-900">{{ input.employee_name }}</span><span class="block text-xs text-neutral-500">{{ relationLabel(input.relation_type) }} · {{ input.component_code }}</span><span class="block font-mono text-[11px] text-neutral-400">{{ input.employment_code }}</span></span></label><span class="rounded-full px-2 py-1 text-xs font-medium" :class="inputStatusClass(input.status)">{{ t(`payroll.components.input_status.${input.status}`) }}</span></div><dl class="mt-3 grid grid-cols-2 gap-3 text-sm"><div v-if="rangeMode"><dt class="text-xs text-neutral-500">{{ t('payroll.components.fields.period') }}</dt><dd class="font-medium tabular-nums">{{ formatPeriod(input.period_start) }}</dd></div><div><dt class="text-xs text-neutral-500">{{ t('payroll.components.fields.component') }}</dt><dd>{{ input.component_name }}</dd></div><div><dt class="text-xs text-neutral-500">{{ t('payroll.components.fields.amount') }}</dt><dd class="font-semibold">{{ formatMoney(input.amount_minor) }}</dd></div><div><dt class="text-xs text-neutral-500">{{ t('payroll.components.fields.source') }}</dt><dd>{{ t(`payroll.components.source.${input.source_kind}`) }}</dd></div><div><dt class="text-xs text-neutral-500">{{ t('payroll.components.fields.external_id') }}</dt><dd class="break-all font-mono text-xs">{{ input.external_id ?? '—' }}</dd></div></dl><PayrollInputRowActions class="mt-4" :input="input" :can-write="canWrite" :can-approve="canApprove" :saving="saving" @edit="editInput" @cancel="cancelInput" @approve="approveInput" @reverse-benefit="reverseBenefitInput" /></article></div>
          </template>
          <!--
            Seskupení: jeden řádek na člověka nebo složku se součty. U pěti set
            lidí se tak měsíc projde bez listování; rozbalí se jen to, co je
            potřeba řešit.
          -->
          <ul v-else data-layout="groups" class="divide-y divide-neutral-100">
            <li v-for="group in inputGroups" :key="group.key" :data-testid="`payroll-inputs-group-${group.key}`">
              <div class="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                <button type="button" class="flex min-w-0 cursor-pointer items-start gap-2 text-left" :aria-expanded="group.key in expandedGroups" @click="toggleGroup(group)">
                  <svg class="mt-0.5 h-4 w-4 shrink-0 text-neutral-400 transition-transform" :class="group.key in expandedGroups ? '' : '-rotate-90'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.chevron" /></svg>
                  <span class="min-w-0">
                    <span class="block font-medium text-neutral-900">{{ effectiveGroupBy === 'period' ? formatPeriod(group.label) : group.label }}</span>
                    <span v-if="group.secondary" class="block font-mono text-[11px] text-neutral-400">{{ group.secondary }}</span>
                  </span>
                </button>
                <div class="flex flex-wrap items-center gap-3 text-sm">
                  <span class="text-neutral-600">{{ t('payroll.components.inputs.group_count', { count: group.count }) }}</span>
                  <span v-if="group.draft_count > 0" class="rounded-full bg-payroll-50 px-2 py-1 text-xs font-medium text-payroll-700">{{ t('payroll.components.inputs.group_drafts', { count: group.draft_count }) }}</span>
                  <span v-else class="rounded-full bg-success-50 px-2 py-1 text-xs font-medium text-success-600">{{ t('payroll.components.inputs.group_all_approved') }}</span>
                  <span class="font-semibold text-neutral-900">{{ formatMoney(group.amount_minor) }}</span>
                  <!-- Měsíc se neotevírá jako filtr, ale jako editovatelné období. -->
                  <button type="button" :class="[btnOutlineSm('neutral'), 'whitespace-nowrap']" :data-testid="`payroll-inputs-group-open-${group.key}`" @click="openGroupAsFilter(group)">
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="effectiveGroupBy === 'period' ? ICONS.calendar : ICONS.funnel" /></svg>
                    {{ effectiveGroupBy === 'period' ? t('payroll.agendas.history.open_month') : t('payroll.components.inputs.group_open') }}
                  </button>
                </div>
              </div>
              <div v-if="group.key in expandedGroups" class="border-t border-neutral-100 bg-neutral-50 px-4 py-2">
                <p v-if="expandedGroups[group.key] === null" class="py-2 text-sm text-neutral-500">{{ t('payroll.components.inputs.group_loading') }}</p>
                <template v-else>
                  <div v-for="input in expandedGroups[group.key] ?? []" :key="input.id" class="flex flex-wrap items-center justify-between gap-3 border-b border-neutral-200 py-2 last:border-b-0">
                    <label class="flex min-w-0 items-center gap-2 text-sm">
                      <input v-if="input.status === 'draft' && !batchDisabledInRange" type="checkbox" class="rounded border-neutral-300 text-payroll-600" :checked="isInputSelected(input.id)" :aria-label="t('payroll.components.inputs.select_row')" @change="toggleInputSelection(input.id)">
                      <span class="min-w-0">
                        <span class="block text-neutral-900">{{ effectiveGroupBy === 'employee' ? input.component_name : input.employee_name }}</span>
                        <span class="block font-mono text-[11px] text-neutral-400">{{ effectiveGroupBy === 'employee' ? input.component_code : input.employment_code }} · {{ t(`payroll.components.source.${input.source_kind}`) }}</span>
                      </span>
                    </label>
                    <div class="flex flex-wrap items-center gap-3">
                      <span class="text-sm font-medium">{{ formatMoney(input.amount_minor) }}</span>
                      <span class="rounded-full px-2 py-1 text-xs font-medium" :class="inputStatusClass(input.status)">{{ t(`payroll.components.input_status.${input.status}`) }}</span>
                      <PayrollInputRowActions :input="input" :can-write="canWrite" :can-approve="canApprove" :saving="saving" @edit="editInput" @cancel="cancelInput" @approve="approveInput" @reverse-benefit="reverseBenefitInput" />
                    </div>
                  </div>
                  <p v-if="(expandedGroups[group.key]?.length ?? 0) < group.count" class="py-2 text-xs text-neutral-500">{{ t('payroll.components.inputs.group_truncated', { shown: expandedGroups[group.key]?.length ?? 0, total: group.count }) }}</p>
                </template>
              </div>
            </li>
          </ul>
          <PaginationBar
            v-if="!inputListEmpty"
            embedded
            :page="inputsPage"
            :per-page="inputsPageSize"
            :total="effectiveGroupBy === null ? inputsTotal : inputGroupTotal"
            @update:page="goToInputsPage"
          />
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

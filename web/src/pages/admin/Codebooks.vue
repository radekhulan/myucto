<script setup lang="ts">
import { ref, onMounted, onBeforeUnmount, reactive, watch, computed } from 'vue'
import { useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { settingsApi, type VatRate, type Country, type Unit } from '@/api/settings'
import { expenseCategoriesApi, type ExpenseCategory } from '@/api/expenseCategories'
import { revenueCategoriesApi, type RevenueCategory } from '@/api/revenueCategories'
import { vatClassificationsApi, type VatClassification } from '@/api/vatClassifications'
import { ossRatesApi, type OssMemberStateRate, type OssRateType } from '@/api/ossRates'
import { taxConstantsApi, type TaxConstantsYear } from '@/api/taxConstants'
import type { TaxConstantsData } from '@/api/tax'
import { useAuthStore } from '@/stores/auth'
import { useHotkey } from '@/composables/useHotkey'
import { useToast } from '@/composables/useToast'
import { useAutoSlug } from '@/composables/useAutoSlug'
import { ICONS, btnFilled, btnOutline, btnOutlineSm } from '@/components/ui/buttonStyles'
import { renderVarsymbolTemplate, hasCounterPlaceholder } from '@/utils/varsymbol'
import { formatMonth } from '@/composables/useFormat'
import EmptyState from '@/components/ui/EmptyState.vue'
import { appIsoDate } from '@/utils/date'

const { t } = useI18n()
const route = useRoute()
const toast = useToast()
const auth = useAuthStore()
const canManageCompanyCodebooks = computed(() => auth.isDemo || auth.canWrite('settings.company.write'))
const props = withDefaults(defineProps<{
  taxConstantsOnly?: boolean
}>(), {
  taxConstantsOnly: false,
})

// Firma → Číselníky (?scope=company): jen firemní číselníky (kategorie CRM rozpadu).
// Globální nastavení → Sazby a číselníky (?scope=global): sdílené systémové číselníky.
// Dodavatelé (multi-tenant firmy) mají od Fáze F vlastní stránku /admin/suppliers.
type Tab = 'currencies' | 'vat' | 'countries' | 'units' | 'expense_categories' | 'revenue_categories' | 'vat_classifications' | 'oss_rates' | 'tax_constants'
type Scope = 'company' | 'global'
const COMPANY_TABS: Tab[] = ['expense_categories', 'revenue_categories']
const GLOBAL_TABS: Tab[] = ['vat', 'vat_classifications', 'oss_rates', 'countries', 'units']
const scope = computed<Scope>(() => route.query.scope === 'company' ? 'company' : 'global')
const visibleTabs = computed<Tab[]>(() => scope.value === 'company' ? COMPANY_TABS : GLOBAL_TABS)
const tab = ref<Tab>('vat')
const vatRates   = ref<VatRate[]>([])
const countries  = ref<Country[]>([])
const units      = ref<Unit[]>([])
const loading    = ref(false)

async function loadAll() {
  loading.value = true
  try {
    [vatRates.value, countries.value, units.value] = await Promise.all([
      settingsApi.listVatRates(),
      settingsApi.listCountries(),
      settingsApi.listUnits(),
    ])
  } finally { loading.value = false }
}
const TABS: Tab[] = ['currencies', 'vat', 'countries', 'units', 'expense_categories', 'revenue_categories', 'vat_classifications', 'oss_rates']

function resolveTab() {
  if (props.taxConstantsOnly) {
    tab.value = 'tax_constants'
    return
  }
  const requestedTab = route.query.tab
  if (typeof requestedTab === 'string' && (TABS as string[]).includes(requestedTab) && visibleTabs.value.includes(requestedTab as Tab)) {
    tab.value = requestedTab as Tab
    return
  }
  tab.value = visibleTabs.value[0]
}
watch(scope, resolveTab)

onMounted(async () => {
  if (props.taxConstantsOnly) {
    tab.value = 'tax_constants'
    loading.value = true
    try {
      await loadTaxConstants()
    } finally {
      loading.value = false
    }
    return
  }
  await loadAll()
  // Menu (Firma / Globální nastavení) odkazuje na konkrétní tab přes ?scope=&tab=...
  resolveTab()
})

// ─── VAT rates ────────────────────────────────────────────
const vatDraft = reactive<Partial<VatRate> & { _new?: boolean }>({})
const vatOpen = ref(false)

// Platná sazba = dnešek spadá do intervalu valid_from..valid_to
function isVatValid(v: VatRate): boolean {
  const today = appIsoDate()
  if (v.valid_from && v.valid_from > today) return false
  if (v.valid_to && v.valid_to < today) return false
  return true
}
// Nejdřív platné sazby, pak ostatní (stabilní řazení v rámci skupin)
const sortedVatRates = computed(() =>
  [...vatRates.value].sort((a, b) => (isVatValid(a) ? 0 : 1) - (isVatValid(b) ? 0 : 1))
)
function newVat() {
  Object.assign(vatDraft, {
    id: undefined, code: '', rate_percent: 21, country: 'CZ',
    label_cs: '', label_en: '', is_default: false, is_reverse_charge: false,
    valid_from: appIsoDate(), valid_to: null, _new: true,
  })
  vatOpen.value = true
}
function editVat(v: VatRate) {
  Object.assign(vatDraft, { ...v, _new: false })
  vatOpen.value = true
}
async function saveVat() {
  try {
    if (vatDraft._new) await settingsApi.createVatRate(vatDraft)
    else if (vatDraft.id) await settingsApi.updateVatRate(vatDraft.id, vatDraft)
    vatOpen.value = false
    toast.success(t('common.saved'))
    await loadAll()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  }
}
async function deleteVat(v: VatRate) {
  if (!confirm(`Smazat sazbu ${v.code} (${v.rate_percent} %)?`)) return
  try {
    await settingsApi.deleteVatRate(v.id)
    toast.success(t('common.deleted'))
    await loadAll()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  }
}

// ─── Countries ────────────────────────────────────────────
const countryDraft = reactive<Partial<Country> & { _new?: boolean }>({})
const countryOpen = ref(false)

useHotkey('escape', () => {
  if (vatOpen.value) vatOpen.value = false
  else if (countryOpen.value) countryOpen.value = false
  else if (unitOpen.value) unitOpen.value = false
})

// ─── Units ─────────────────────────────────────────────────
const unitDraft = reactive<Partial<Unit> & { _new?: boolean }>({})
const unitOpen = ref(false)
function newUnit() {
  Object.assign(unitDraft, {
    id: undefined, code: '', label_cs: '', label_en: '',
    is_default: false, display_order: 0, _new: true,
  })
  unitOpen.value = true
}
function editUnit(u: Unit) {
  Object.assign(unitDraft, { ...u, _new: false })
  unitOpen.value = true
}
async function saveUnit() {
  try {
    if (unitDraft._new) await settingsApi.createUnit(unitDraft)
    else if (unitDraft.id) await settingsApi.updateUnit(unitDraft.id, unitDraft)
    unitOpen.value = false
    toast.success(t('common.saved'))
    await loadAll()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  }
}
async function deleteUnit(u: Unit) {
  if (!confirm(`Smazat jednotku ${u.code}?`)) return
  try {
    await settingsApi.deleteUnit(u.id)
    toast.success(t('common.deleted'))
    await loadAll()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  }
}
function newCountry() {
  Object.assign(countryDraft, { id: undefined, iso2: '', iso3: '', name_cs: '', name_en: '', is_eu: false, _new: true })
  countryOpen.value = true
}
function editCountry(c: Country) {
  Object.assign(countryDraft, { ...c, _new: false })
  countryOpen.value = true
}
async function saveCountry() {
  try {
    if (countryDraft._new) await settingsApi.createCountry(countryDraft)
    else if (countryDraft.id) await settingsApi.updateCountry(countryDraft.id, countryDraft)
    countryOpen.value = false
    toast.success(t('common.saved'))
    await loadAll()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  }
}
async function deleteCountry(c: Country) {
  if (!confirm(`Smazat zemi ${c.iso2} – ${c.name_cs}?`)) return
  try {
    await settingsApi.deleteCountry(c.id)
    toast.success(t('common.deleted'))
    await loadAll()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  }
}

// ─── Expense categories (kategorie nákladů pro CRM rozpad) ─────────────
const expenseCategories = ref<ExpenseCategory[]>([])
const expenseDraft = reactive({
  id: 0,
  code: '',
  label: '',
  fixed_or_var: 'variable' as 'fixed' | 'variable',
  display_order: 0,
  archived: false,
})
const expenseOpen = ref(false)
const expenseSlug = useAutoSlug((s) => { expenseDraft.code = s }, { maxLen: 20 })

async function loadExpenseCategories() {
  expenseCategories.value = await expenseCategoriesApi.list(true)
}

function newExpense() {
  Object.assign(expenseDraft, { id: 0, code: '', label: '', fixed_or_var: 'variable', display_order: 0, archived: false })
  expenseSlug.init('', false)
  expenseOpen.value = true
}

function editExpense(c: ExpenseCategory) {
  Object.assign(expenseDraft, c)
  expenseSlug.init(expenseDraft.code, true)
  expenseOpen.value = true
}

async function saveExpense() {
  try {
    if (expenseDraft.id) {
      await expenseCategoriesApi.update(expenseDraft.id, {
        code: expenseDraft.code,
        label: expenseDraft.label,
        fixed_or_var: expenseDraft.fixed_or_var,
        display_order: expenseDraft.display_order,
        archived: expenseDraft.archived,
      })
    } else {
      await expenseCategoriesApi.create({
        code: expenseDraft.code,
        label: expenseDraft.label,
        fixed_or_var: expenseDraft.fixed_or_var,
        display_order: expenseDraft.display_order,
      })
    }
    expenseOpen.value = false
    toast.success(t('common.saved'))
    await loadExpenseCategories()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  }
}

async function removeExpense(c: ExpenseCategory) {
  if (!confirm(t('expense_categories.delete_confirm', { label: c.label }))) return
  try {
    const r = await expenseCategoriesApi.delete(c.id)
    toast.success(r.deleted ? t('common.deleted') : t('expense_categories.archived_due_to_usage'))
    await loadExpenseCategories()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  }
}

// ─── Revenue categories (kategorie tržeb pro CRM/Stats rozpad) ─────────
type RevenueNumberingField = 'invoice_number_format' | 'proforma_number_format' | 'credit_note_number_format'

const revenueCategories = ref<RevenueCategory[]>([])
const emptyRevenueDraft = () => ({
  id: 0,
  code: '',
  label: '',
  display_order: 0,
  archived: false,
  // Vlastní číselná řada (migrace 1333) — prázdné = dědí se z dodavatele.
  invoice_number_format: '' as string,
  proforma_number_format: '' as string,
  credit_note_number_format: '' as string,
  invoice_number_period: null as 'year' | 'month' | 'none' | null,
})
const revenueDraft = reactive(emptyRevenueDraft())
const revenueOpen = ref(false)
const revenueSlug = useAutoSlug((s) => { revenueDraft.code = s }, { maxLen: 20 })

const revenueHasNumbering = computed(() =>
  !!(revenueDraft.invoice_number_format || revenueDraft.proforma_number_format || revenueDraft.credit_note_number_format))

/**
 * Živý náhled řady kategorie. Counter úmyslně 1 — skutečnou hodnotu zná až DB
 * (a při vystavení ji dodá /api/invoices/preview-varsymbol); tady jde o kontrolu
 * tvaru šablony, ne o predikci konkrétního čísla.
 */
function revenueNumberingPreview(field: RevenueNumberingField): string {
  return renderVarsymbolTemplate(revenueDraft[field], new Date(), 1)
}

function revenueNumberingWarning(field: RevenueNumberingField): string {
  const tpl = revenueDraft[field]
  if (!tpl || hasCounterPlaceholder(tpl)) return ''
  return t('revenue_categories.numbering_must_have_counter')
}

async function loadRevenueCategories() {
  revenueCategories.value = await revenueCategoriesApi.list(true)
}

function newRevenue() {
  Object.assign(revenueDraft, emptyRevenueDraft())
  revenueSlug.init('', false)
  revenueOpen.value = true
}

function editRevenue(c: RevenueCategory) {
  Object.assign(revenueDraft, emptyRevenueDraft(), c, {
    invoice_number_format: c.invoice_number_format ?? '',
    proforma_number_format: c.proforma_number_format ?? '',
    credit_note_number_format: c.credit_note_number_format ?? '',
    invoice_number_period: c.invoice_number_period ?? null,
  })
  revenueSlug.init(revenueDraft.code, true)
  revenueOpen.value = true
}

async function saveRevenue() {
  const numbering = {
    invoice_number_format: revenueDraft.invoice_number_format || null,
    proforma_number_format: revenueDraft.proforma_number_format || null,
    credit_note_number_format: revenueDraft.credit_note_number_format || null,
    invoice_number_period: revenueDraft.invoice_number_period,
  }
  try {
    if (revenueDraft.id) {
      await revenueCategoriesApi.update(revenueDraft.id, {
        code: revenueDraft.code,
        label: revenueDraft.label,
        display_order: revenueDraft.display_order,
        archived: revenueDraft.archived,
        ...numbering,
      })
    } else {
      await revenueCategoriesApi.create({
        code: revenueDraft.code,
        label: revenueDraft.label,
        display_order: revenueDraft.display_order,
        ...numbering,
      })
    }
    revenueOpen.value = false
    toast.success(t('common.saved'))
    await loadRevenueCategories()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  }
}

async function removeRevenue(c: RevenueCategory) {
  if (!confirm(t('revenue_categories.delete_confirm', { label: c.label }))) return
  try {
    const r = await revenueCategoriesApi.delete(c.id)
    toast.success(r.deleted ? t('common.deleted') : t('revenue_categories.archived_due_to_usage'))
    await loadRevenueCategories()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  }
}

// ─── VAT classifications (kódy DPHDP3 + KH) ──────────────────────────
const vatClassifications = ref<VatClassification[]>([])
const vatClsDraft = reactive({
  id: 0,
  code: '',
  label: '',
  direction: 'both' as 'sale' | 'purchase' | 'both',
  dphdp3_line: '',
  kh_section: '',
  vat_rate: null as number | null,
  is_reverse_charge: false,
  kh_regime_code: '' as '' | '0' | '1' | '2',
  kh_bad_debt: '' as '' | 'N' | 'P',
  kod_pred_pl: '',
  display_order: 100,
  archived: false,
})
const vatClsOpen = ref(false)
const vatClsEditMode = ref<'create' | 'edit'>('create')
const vatClsSlug = useAutoSlug((s) => { vatClsDraft.code = s }, { maxLen: 8 })

/**
 * Escape zavírá dialogy číselníků.
 *
 * Dialogy jsou tu ručně psané (ne sdílený Modal.vue, který Escape umí), takže
 * jediný způsob, jak je zavřít, bylo trefit křížek nebo Zrušit — z formuláře
 * uprostřed obrazovky to působí jako past. Přepisovat všech šest na Modal.vue
 * by přestavělo jejich strukturu a riskovalo vizuální regrese, tak jen chybějící
 * chování doplňujeme.
 */
// Deklarace patří sem, ne k sekci OSS níž — seznam dialogů se vyhodnocuje hned
// a `const` z pozdější sekce by v něm skončil v temporal dead zone.
const ossOpen = ref(false)
const codebookDialogs = [vatOpen, countryOpen, unitOpen, expenseOpen, revenueOpen, vatClsOpen, ossOpen]

function onDialogEscape(e: KeyboardEvent) {
  if (e.key !== 'Escape') return
  const open = codebookDialogs.find(d => d.value)
  if (!open) return
  e.stopPropagation()
  open.value = false
}
onMounted(() => document.addEventListener('keydown', onDialogEscape))
onBeforeUnmount(() => document.removeEventListener('keydown', onDialogEscape))

async function loadVatClassifications() {
  vatClassifications.value = await vatClassificationsApi.list(undefined, true)
}

function newVatCls() {
  Object.assign(vatClsDraft, { id: 0, code: '', label: '', direction: 'both',
    dphdp3_line: '', kh_section: '', vat_rate: null, is_reverse_charge: false,
    kh_regime_code: '', kh_bad_debt: '', kod_pred_pl: '',
    display_order: 100, archived: false })
  vatClsEditMode.value = 'create'
  vatClsSlug.init('', false)
  vatClsOpen.value = true
}

function editVatCls(c: VatClassification) {
  Object.assign(vatClsDraft, {
    id: c.id, code: c.code, label: c.label, direction: c.direction,
    dphdp3_line: c.dphdp3_line || '', kh_section: c.kh_section || '',
    vat_rate: c.vat_rate, is_reverse_charge: c.is_reverse_charge,
    kh_regime_code: c.kh_regime_code || '', kh_bad_debt: c.kh_bad_debt || '',
    kod_pred_pl: c.kod_pred_pl || '',
    display_order: c.display_order, archived: c.archived,
  })
  vatClsEditMode.value = 'edit'
  vatClsSlug.init(vatClsDraft.code, true)
  vatClsOpen.value = true
}

async function saveVatCls() {
  try {
    if (vatClsEditMode.value === 'edit') {
      await vatClassificationsApi.update(vatClsDraft.id, {
        label: vatClsDraft.label,
        direction: vatClsDraft.direction,
        dphdp3_line: vatClsDraft.dphdp3_line || null,
        kh_section: vatClsDraft.kh_section || null,
        vat_rate: vatClsDraft.vat_rate,
        is_reverse_charge: vatClsDraft.is_reverse_charge,
        kh_regime_code: vatClsDraft.kh_regime_code || null,
        kh_bad_debt: vatClsDraft.kh_bad_debt || null,
        kod_pred_pl: vatClsDraft.kod_pred_pl || null,
        display_order: vatClsDraft.display_order,
        archived: vatClsDraft.archived,
      })
    } else {
      await vatClassificationsApi.create({
        code: vatClsDraft.code,
        label: vatClsDraft.label,
        direction: vatClsDraft.direction,
        dphdp3_line: vatClsDraft.dphdp3_line || null,
        kh_section: vatClsDraft.kh_section || null,
        vat_rate: vatClsDraft.vat_rate,
        is_reverse_charge: vatClsDraft.is_reverse_charge,
        kh_regime_code: vatClsDraft.kh_regime_code || null,
        kh_bad_debt: vatClsDraft.kh_bad_debt || null,
        kod_pred_pl: vatClsDraft.kod_pred_pl || null,
        display_order: vatClsDraft.display_order,
      })
    }
    vatClsOpen.value = false
    toast.success(t('common.saved'))
    await loadVatClassifications()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  }
}

async function removeVatCls(c: VatClassification) {
  if (c.supplier_id === null) {
    toast.error(t('vat_classifications.cannot_delete_global'))
    return
  }
  if (!confirm(t('vat_classifications.delete_confirm', { code: c.code }))) return
  try {
    await vatClassificationsApi.delete(c.id)
    toast.success(t('common.deleted'))
    await loadVatClassifications()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  }
}

// ─── Sazby DPH členských států pro OSS (OSS-9) ──────────────────────────
//
// Číselník je GLOBÁLNÍ a je to autorita, proti které se ověřuje sazba na dokladu —
// proto ho čte kdokoli s přístupem k číselníkům, ale mění jen správce instance
// (`can_write` z API). Seedované řádky se needitují ani nemažou: jejich identitu
// používá migrace k idempotenci, takže jdou jen zkrátit v platnosti nebo vyřadit.
const ossRates = ref<OssMemberStateRate[]>([])
const ossAvailable = ref(true)
const ossManageable = ref(true)
const ossCoverageGaps = ref<string[]>([])
const ossCanWrite = ref(false)
const ossRateTypes = ref<OssRateType[]>(['standard', 'reduced', 'second_reduced', 'parking'])
const ossCountryFilter = ref('')
const ossShowDisabled = ref(false)
const ossEditMode = ref<'create' | 'edit' | 'override'>('create')
const ossDraft = reactive({
  id: 0,
  country: '',
  rate_type: 'standard' as OssRateType,
  rate_percent: null as number | null,
  valid_from: '',
  valid_to: '',
  valid_to_override: '',
  note: '',
})

const ossRatesFiltered = computed(() => {
  const needle = ossCountryFilter.value.trim().toUpperCase()
  return ossRates.value.filter(r =>
    (ossShowDisabled.value || !r.disabled) && (needle === '' || r.country.startsWith(needle)))
})

async function loadOssRates() {
  try {
    const r = await ossRatesApi.list()
    ossRates.value = r.rates
    ossAvailable.value = r.available
    ossManageable.value = r.manageable
    ossCoverageGaps.value = r.coverage_gaps ?? []
    ossCanWrite.value = r.can_write
    if (r.rate_types.length > 0) ossRateTypes.value = r.rate_types
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  }
}

function newOssRate() {
  Object.assign(ossDraft, { id: 0, country: ossCountryFilter.value.trim().toUpperCase(),
    rate_type: 'standard', rate_percent: null, valid_from: '', valid_to: '',
    valid_to_override: '', note: '' })
  ossEditMode.value = 'create'
  ossOpen.value = true
}

function editOssRate(r: OssMemberStateRate) {
  Object.assign(ossDraft, {
    id: r.id, country: r.country, rate_type: r.rate_type, rate_percent: r.rate_percent,
    valid_from: r.valid_from, valid_to: r.valid_to ?? '',
    valid_to_override: r.valid_to_override ?? '', note: r.note ?? '',
  })
  // Seed má vlastní sloupce nedotknutelné — dialog nabídne jen překryv platnosti.
  ossEditMode.value = r.is_custom ? 'edit' : 'override'
  ossOpen.value = true
}

async function saveOssRate() {
  try {
    if (ossEditMode.value === 'create') {
      await ossRatesApi.create({
        country: ossDraft.country,
        rate_type: ossDraft.rate_type,
        rate_percent: Number(ossDraft.rate_percent ?? 0),
        valid_from: ossDraft.valid_from,
        valid_to: ossDraft.valid_to || null,
        note: ossDraft.note || null,
      })
    } else if (ossEditMode.value === 'edit') {
      await ossRatesApi.update(ossDraft.id, {
        country: ossDraft.country,
        rate_type: ossDraft.rate_type,
        rate_percent: Number(ossDraft.rate_percent ?? 0),
        valid_from: ossDraft.valid_from,
        valid_to: ossDraft.valid_to || null,
        note: ossDraft.note || null,
      })
    } else {
      await ossRatesApi.update(ossDraft.id, { valid_to_override: ossDraft.valid_to_override || null })
    }
    ossOpen.value = false
    toast.success(t('common.saved'))
    await loadOssRates()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  }
}

async function toggleOssRateDisabled(r: OssMemberStateRate) {
  try {
    await ossRatesApi.update(r.id, { disabled: !r.disabled })
    toast.success(t('common.saved'))
    await loadOssRates()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  }
}

async function removeOssRate(r: OssMemberStateRate) {
  if (!confirm(t('oss_rates.delete_confirm', { country: r.country, rate: r.rate_percent }))) return
  try {
    await ossRatesApi.remove(r.id)
    toast.success(t('common.deleted'))
    await loadOssRates()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  }
}

// ─── Daňové konstanty (číselník — override defaultů z backendu) ──────────
const taxYears = ref<TaxConstantsYear[]>([])
const taxYear = ref<number>(0)
const taxModel = ref<TaxConstantsData | null>(null)
const taxConstantsTab = ref<'fo' | 'osvc' | 'payroll' | 'po' | 'vat' | 'assets' | 'reporting'>('fo')
const taxConstantTabs = ['fo', 'osvc', 'payroll', 'po', 'vat', 'assets', 'reporting'] as const
const taxScalarFields: Record<typeof taxConstantTabs[number], readonly string[]> = {
  fo: ['tax_rate_low', 'tax_rate_high', 'tax_high_threshold', 'credit_taxpayer', 'credit_spouse',
    'spouse_income_limit', 'spouse_child_max_age', 'minimum_wage', 'child_bonus_min',
    'child_bonus_min_income', 'mortgage_cap', 'mortgage_cap_pre2021', 'pension_cap',
    'donation_min_fo', 'donation_min_fo_pct', 'donation_cap_fo_pct', 'rounding_base_fo'],
  osvc: ['social_rate', 'health_rate', 'social_assessment_pct', 'health_assessment_pct',
    'social_min_base_main', 'social_min_base_secondary', 'social_max_base',
    'social_secondary_participation_threshold', 'health_min_base', 'sickness_rate',
    'sickness_min_monthly_base'],
  // Měsíční hranice §38h odst. 2; samotné sazby jsou vnořené v `payroll` a mají vlastní blok.
  payroll: ['advance_tax_high_threshold'],
  po: ['corporate_tax_rate', 'donation_min_po', 'donation_cap_po_pct', 'disabled_employee_credit',
    'disabled_employee_credit_severe', 'advance_threshold_low', 'advance_threshold_high',
    'advance_semiannual_rate', 'advance_quarterly_rate', 'advance_rounding_step', 'rounding_base_po'],
  vat: ['vat_rate_standard', 'vat_rate_reduced', 'vat_limit_low', 'vat_limit_high',
    'kh_item_threshold', 'vat_coefficient_full_threshold_pct'],
  assets: ['fixed_asset_limit', 'm1_depreciation_limit'],
  reporting: ['transition_receivables_max_years', 'tax_loss_carry_years'],
}
const taxIsOverride = ref(false)
const taxIsNew = ref(false)   // nově přidaný, ještě neuložený rok
const taxSaving = ref(false)

/** Nejvyšší přidatelný rok = aktuální kalendářní rok + 1 (jeden rok dopředu, ne dál). */
const maxAddableTaxYear = computed(() => new Date().getFullYear() + 1)
const canAddTaxYear = computed(() =>
  taxYears.value.length > 0 &&
  Math.max(...taxYears.value.map(y => y.year)) < maxAddableTaxYear.value,
)
const taxRates = [30, 40, 60, 80] as const
const taxBands = ['band1', 'band2', 'band3'] as const

/**
 * Mzdové sazby (PayrollCalculator) jsou vnořené v `payroll`, takže je generický
 * blok skalárů nevykreslí — mají vlastní kartu. Zadávají se jako desetinný podíl
 * (0,071 = 7,1 %), stejně jako je drží backend.
 */
const payrollRateFields = ['employee_social', 'employee_health', 'employer_social',
  'employer_health', 'health_total', 'advance_tax', 'advance_tax_high'] as const

async function loadTaxConstants() {
  taxYears.value = await taxConstantsApi.list()
  if (taxYears.value.length && !taxYears.value.find(y => y.year === taxYear.value)) {
    selectTaxYear(taxYears.value[0].year)
  }
}
function selectTaxYear(year: number) {
  const row = taxYears.value.find(y => y.year === year)
  if (!row) return
  taxYear.value = year
  taxIsOverride.value = row.is_override
  taxIsNew.value = false
  taxModel.value = JSON.parse(JSON.stringify(row.data)) // hluboká kopie pro editaci
  normalizePausalMonthly(year)
}

/**
 * Paušální daň se edituje po MĚSÍČNÍCH zálohách — sazba se může změnit uprostřed
 * roku (2026: 1. pásmo 9 984 → 9 162 Kč od 1. 7.). Roční částka je odvozená,
 * backend ji přepočítá a neukládá. Starší override bez rozvrhu dopočítáme z roční.
 */
function normalizePausalMonthly(year: number) {
  const m = taxModel.value
  if (!m) return
  const segs = Array.isArray(m.pausal_monthly) ? m.pausal_monthly : []
  if (!segs.length) {
    const a = m.pausal_annual || {}
    m.pausal_monthly = [{
      from: `${year}-01-01`,
      band1: Math.round(((a.band1 ?? 0) / 12) * 100) / 100,
      band2: Math.round(((a.band2 ?? 0) / 12) * 100) / 100,
      band3: Math.round(((a.band3 ?? 0) / 12) * 100) / 100,
    }]
    return
  }
  // Ukotvi k editovanému roku (klonovaný rok nese data předchozího) a setřiď.
  m.pausal_monthly = segs
    .map(s => ({ ...s, from: `${year}-${(s.from || '').slice(5, 7) || '01'}-01` }))
    .sort((a, b) => a.from.localeCompare(b.from))
  m.pausal_monthly[0].from = `${year}-01-01`
}

const pausalMonthOptions = computed(() =>
  Array.from({ length: 12 }, (_, i) => ({
    value: `${taxYear.value}-${String(i + 1).padStart(2, '0')}-01`,
    label: formatMonth(`${taxYear.value}-${String(i + 1).padStart(2, '0')}`),
  })))

/** Roční částka = součet 12 měsíčních záloh (zrcadlo PausalSchedule::annual). */
const pausalAnnualPreview = computed<Record<string, number>>(() => {
  const segs = taxModel.value?.pausal_monthly ?? []
  const out: Record<string, number> = { band1: 0, band2: 0, band3: 0 }
  if (!segs.length) return out
  for (let mo = 1; mo <= 12; mo++) {
    const key = `${taxYear.value}-${String(mo).padStart(2, '0')}-01`
    let cur = segs[0]
    for (const s of segs) if (s.from <= key) cur = s
    for (const b of taxBands) out[b] += Number((cur as any)[b] || 0)
  }
  return out
})

function addPausalSegment() {
  const segs = taxModel.value?.pausal_monthly
  if (!segs?.length) return
  const last = segs[segs.length - 1]
  const nextMonth = Number(last.from.slice(5, 7)) + 1
  if (nextMonth > 12) return
  segs.push({ ...last, from: `${taxYear.value}-${String(nextMonth).padStart(2, '0')}-01` })
}
function removePausalSegment(i: number) {
  if (i > 0) taxModel.value?.pausal_monthly.splice(i, 1)
}
/** Po ruční změně data drž období vzestupně (backend jinak zápis odmítne). */
function sortPausalSegments() {
  taxModel.value?.pausal_monthly.sort((a, b) => a.from.localeCompare(b.from))
}
function pausalMonthTaken(value: string, i: number): boolean {
  return (taxModel.value?.pausal_monthly ?? []).some((s, j) => j !== i && s.from === value)
}

/** Přidá další rok (nejnovější + 1) předvyplněný hodnotami nejnovějšího roku a rovnou ho uloží do DB,
 *  aby přežil reload. Hodnoty lze následně upravit a uložit znovu.
 *  Strop: lze přidat maximálně aktuální kalendářní rok + 1 (jeden rok dopředu). */
async function addTaxYear() {
  if (!taxYears.value.length) return
  const maxYear = Math.max(...taxYears.value.map(y => y.year))
  const newYear = maxYear + 1
  if (newYear > maxAddableTaxYear.value) return
  if (taxYears.value.some(y => y.year === newYear)) { selectTaxYear(newYear); return }
  const base = taxYears.value.find(y => y.year === maxYear)
  if (!base) return
  const cloned = JSON.parse(JSON.stringify(base.data))
  cloned.year = newYear
  // Rozvrh paušálních záloh je vázaný na rok: do nového roku přebíráme poslední
  // platnou sazbu jako jediné období od 1. 1. (změnu si admin doplní sám).
  const baseSegs = Array.isArray(cloned.pausal_monthly) ? cloned.pausal_monthly : []
  if (baseSegs.length) {
    cloned.pausal_monthly = [{ ...baseSegs[baseSegs.length - 1], from: `${newYear}-01-01` }]
  }
  taxSaving.value = true
  try {
    const saved = await taxConstantsApi.save(newYear, cloned)
    taxYears.value.unshift(saved)
    taxYears.value.sort((a, b) => b.year - a.year)
    taxYear.value = newYear
    taxIsOverride.value = saved.is_override
    taxIsNew.value = false
    taxModel.value = JSON.parse(JSON.stringify(saved.data))
    toast.success(t('codebooks.tax_year_added', { year: newYear }))
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  } finally { taxSaving.value = false }
}
async function saveTaxConstants() {
  if (!taxModel.value) return
  taxSaving.value = true
  try {
    const updated = await taxConstantsApi.save(taxYear.value, taxModel.value)
    const i = taxYears.value.findIndex(y => y.year === taxYear.value)
    if (i >= 0) taxYears.value[i] = updated
    taxIsOverride.value = updated.is_override
    taxIsNew.value = false
    toast.success(t('codebooks.tax_saved'))
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  } finally { taxSaving.value = false }
}
async function resetTaxConstants() {
  if (!confirm(t('codebooks.tax_reset_confirm'))) return
  try {
    const updated = await taxConstantsApi.reset(taxYear.value)
    const i = taxYears.value.findIndex(y => y.year === taxYear.value)
    if (i >= 0) taxYears.value[i] = updated
    taxIsOverride.value = false
    taxModel.value = JSON.parse(JSON.stringify(updated.data))
    toast.success(t('codebooks.tax_reset_done'))
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  }
}

// Načti při přepnutí na firemní kategorie nebo klasifikace DPH.
watch(tab, (newTab) => {
  if (newTab === 'expense_categories') loadExpenseCategories()
  if (newTab === 'revenue_categories') loadRevenueCategories()
  if (newTab === 'vat_classifications') loadVatClassifications()
  if (newTab === 'oss_rates') loadOssRates()
})
</script>

<template>
  <div>
    <div class="mb-4">
      <h1 class="text-2xl font-semibold">{{ props.taxConstantsOnly ? t('codebooks.tab_tax_constants') : scope === 'company' ? t('nav.codebooks') : t('nav.codebooks_global') }}</h1>
      <p class="text-sm text-neutral-500 mt-0.5">{{ props.taxConstantsOnly ? t('codebooks.subtitle_tax_constants') : scope === 'company' ? t('codebooks.subtitle_company') : t('codebooks.subtitle_global') }}</p>
    </div>

    <!-- Taby podle scope (?scope=company / ?scope=global) — Firma vidí jen firemní
         číselníky (kategorie), Globální nastavení jen sdílené systémové číselníky. -->
    <div v-if="!props.taxConstantsOnly" class="border-b border-neutral-200 mb-4 flex gap-1 overflow-x-auto">
      <button v-for="tt in visibleTabs" :key="tt"
        @click="tab = tt"
        class="cursor-pointer px-4 py-2 text-sm border-b-2 transition whitespace-nowrap"
        :class="tab === tt
          ? 'border-primary-600 text-primary-700 font-medium'
          : 'border-transparent text-neutral-600 hover:text-neutral-900'">
        {{ tt === 'vat' ? t('codebooks.tab_vat')
          : tt === 'vat_classifications' ? t('codebooks.tab_vat_classifications')
          : tt === 'oss_rates' ? t('codebooks.tab_oss_rates')
          : tt === 'expense_categories' ? t('codebooks.tab_expense_categories')
          : tt === 'revenue_categories' ? t('codebooks.tab_revenue_categories')
          : tt === 'countries' ? t('codebooks.tab_countries')
          : tt === 'units' ? t('codebooks.tab_units')
          : t('codebooks.tab_tax_constants') }}
      </button>
    </div>

    <div v-if="loading" class="text-center text-neutral-500 py-12 text-sm">{{ t('common.loading') }}</div>

    <!-- ====== VAT RATES ====== -->
    <section v-else-if="tab === 'vat'">
      <div class="flex justify-end mb-3">
        <button @click="newVat" :class="btnFilled('primary')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
          {{ t('codebooks.new_vat') }}
        </button>
      </div>
      <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
        <!-- Desktop: tabulka -->
        <div class="hidden md:block overflow-x-auto">
        <table class="w-full text-sm table-sticky-first">
          <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
            <tr>
              <th class="px-3 py-2 text-center font-medium">{{ t('codebooks.country') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('codebooks.code') }}</th>
              <th class="px-3 py-2 text-right font-medium">%</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('codebooks.name_cs') }}</th>
              <th class="px-3 py-2 text-center font-medium">{{ t('codebooks.is_default') }}</th>
              <th class="px-3 py-2 text-center font-medium">{{ t('codebooks.is_reverse_charge') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('codebooks.valid') }}</th>
              <th class="px-3 py-2 w-32"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="v in sortedVatRates" :key="v.id" :class="isVatValid(v) ? 'font-semibold' : 'text-neutral-400'">
              <td class="px-3 py-2 text-center font-mono">{{ v.country }}</td>
              <td class="px-3 py-2 font-mono text-xs">{{ v.code }}</td>
              <td class="px-3 py-2 text-right font-mono">{{ v.rate_percent }} %</td>
              <td class="px-3 py-2">{{ v.label_cs }}</td>
              <td class="px-3 py-2 text-center"><span v-if="v.is_default" class="text-primary-600">✓</span></td>
              <td class="px-3 py-2 text-center"><span v-if="v.is_reverse_charge" class="text-warning-600">⇄</span></td>
              <td class="px-3 py-2 text-xs text-neutral-500">{{ v.valid_from }}<span v-if="v.valid_to"> – {{ v.valid_to }}</span></td>
              <td class="px-3 py-2 text-right whitespace-nowrap">
                <div class="flex items-center justify-end gap-1.5">
                  <button @click="editVat(v)" :class="btnOutlineSm('primary')">{{ t('common.edit') }}</button>
                  <button @click="deleteVat(v)" :disabled="(v.items_count ?? 0) > 0"
                    :class="btnOutlineSm('danger')"
                    :title="(v.items_count ?? 0) > 0 ? t('codebooks.in_use_vat', { n: v.items_count }) : t('common.delete')">
                    {{ t('common.delete') }}
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
        </div>

        <!-- Mobile: karty -->
        <div class="md:hidden divide-y divide-neutral-100">
          <div v-for="v in sortedVatRates" :key="`m-${v.id}`" class="p-3 space-y-1.5" :class="{ 'opacity-50': !isVatValid(v) }">
            <div class="flex items-baseline justify-between gap-2">
              <div class="flex items-baseline gap-2">
                <span class="font-mono text-xs">{{ v.country }}</span>
                <span class="font-mono text-sm font-semibold">{{ v.code }}</span>
                <span class="text-sm text-neutral-700">{{ v.label_cs }}</span>
              </div>
              <span class="font-mono font-semibold">{{ v.rate_percent }} %</span>
            </div>
            <div class="flex items-center justify-between gap-2 text-xs">
              <span class="text-neutral-500">
                <span v-if="v.is_default" class="text-primary-600">✓ {{ t('codebooks.is_default') }}</span>
                <span v-if="v.is_default && v.is_reverse_charge" class="text-neutral-400 mx-1.5">·</span>
                <span v-if="v.is_reverse_charge" class="text-warning-600">⇄ RC</span>
              </span>
              <span class="text-neutral-500">{{ v.valid_from }}<span v-if="v.valid_to"> – {{ v.valid_to }}</span></span>
            </div>
            <div class="flex justify-end gap-2">
              <button @click="editVat(v)" :class="btnOutline('primary')">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.edit" /></svg>
                {{ t('common.edit') }}</button>
              <button @click="deleteVat(v)" :disabled="(v.items_count ?? 0) > 0"
                :class="btnOutline('danger')"
                :title="(v.items_count ?? 0) > 0 ? t('codebooks.in_use_vat', { n: v.items_count }) : t('common.delete')">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.trash" /></svg>
                {{ t('common.delete') }}
              </button>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- ====== COUNTRIES ====== -->
    <section v-else-if="tab === 'countries'">
      <div class="flex justify-end mb-3">
        <button @click="newCountry" :class="btnFilled('primary')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
          {{ t('codebooks.new_country') }}
        </button>
      </div>
      <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
        <!-- Desktop: tabulka -->
        <div class="hidden md:block overflow-x-auto">
        <table class="w-full text-sm table-sticky-first">
          <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
            <tr>
              <th class="px-3 py-2 text-center font-medium">{{ t('codebooks.iso2') }}</th>
              <th class="px-3 py-2 text-center font-medium">{{ t('codebooks.iso3') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('codebooks.name_cs') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('codebooks.name_en') }}</th>
              <th class="px-3 py-2 text-center font-medium">{{ t('codebooks.is_eu') }}</th>
              <th class="px-3 py-2 w-32"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="c in countries" :key="c.id">
              <td class="px-3 py-2 text-center font-mono">{{ c.iso2 }}</td>
              <td class="px-3 py-2 text-center font-mono text-xs">{{ c.iso3 }}</td>
              <td class="px-3 py-2">{{ c.name_cs }}</td>
              <td class="px-3 py-2 text-neutral-500">{{ c.name_en }}</td>
              <td class="px-3 py-2 text-center"><span v-if="c.is_eu" class="text-primary-600">EU</span></td>
              <td class="px-3 py-2 text-right whitespace-nowrap">
                <div class="flex items-center justify-end gap-1.5">
                  <button @click="editCountry(c)" :class="btnOutlineSm('primary')">{{ t('common.edit') }}</button>
                  <button @click="deleteCountry(c)" :disabled="(c.uses_count ?? 0) > 0"
                    :class="btnOutlineSm('danger')"
                    :title="(c.uses_count ?? 0) > 0 ? t('codebooks.in_use_country', { n: c.uses_count }) : t('common.delete')">
                    {{ t('common.delete') }}
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
        </div>

        <!-- Mobile: karty -->
        <div class="md:hidden divide-y divide-neutral-100">
          <div v-for="c in countries" :key="`m-${c.id}`" class="p-3 space-y-1.5">
            <div class="flex items-baseline justify-between gap-2">
              <div class="flex items-baseline gap-2">
                <span class="font-mono font-semibold">{{ c.iso2 }}</span>
                <span class="font-mono text-xs text-neutral-500">{{ c.iso3 }}</span>
                <span class="text-sm">{{ c.name_cs }}</span>
              </div>
              <span v-if="c.is_eu" class="text-xs px-2 py-0.5 rounded bg-primary-100 text-primary-700">EU</span>
            </div>
            <div class="flex items-center justify-between gap-2">
              <span class="text-xs text-neutral-500 truncate">{{ c.name_en }}</span>
              <div class="flex gap-2">
                <button @click="editCountry(c)" :class="btnOutline('primary')">
                  <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.edit" /></svg>
                  {{ t('common.edit') }}</button>
                <button @click="deleteCountry(c)" :disabled="(c.uses_count ?? 0) > 0"
                  :class="btnOutline('danger')"
                  :title="(c.uses_count ?? 0) > 0 ? t('codebooks.in_use_country', { n: c.uses_count }) : t('common.delete')">
                  <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.trash" /></svg>
                  {{ t('common.delete') }}
                </button>
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- ====== UNITS ====== -->
    <section v-else-if="tab === 'units'">
      <div class="flex justify-end mb-3">
        <button @click="newUnit" :class="btnFilled('primary')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
          {{ t('codebooks.new_unit') }}
        </button>
      </div>
      <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
        <!-- Desktop: tabulka -->
        <div class="hidden md:block overflow-x-auto">
        <table class="w-full text-sm table-sticky-first">
          <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
            <tr>
              <th class="px-3 py-2 text-left font-medium">{{ t('codebooks.code') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('codebooks.name_cs') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('codebooks.name_en') }}</th>
              <th class="px-3 py-2 text-center font-medium">{{ t('codebooks.is_default') }}</th>
              <th class="px-3 py-2 text-center font-medium">{{ t('codebooks.display_order') }}</th>
              <th class="px-3 py-2 w-32"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="u in units" :key="u.id">
              <td class="px-3 py-2 font-mono">{{ u.code }}</td>
              <td class="px-3 py-2">{{ u.label_cs }}</td>
              <td class="px-3 py-2 text-neutral-500">{{ u.label_en }}</td>
              <td class="px-3 py-2 text-center"><span v-if="u.is_default" class="text-primary-600">✓</span></td>
              <td class="px-3 py-2 text-center font-mono text-xs">{{ u.display_order }}</td>
              <td class="px-3 py-2 text-right whitespace-nowrap">
                <div class="flex items-center justify-end gap-1.5">
                  <button @click="editUnit(u)" :class="btnOutlineSm('primary')">{{ t('common.edit') }}</button>
                  <button @click="deleteUnit(u)" :disabled="(u.items_count ?? 0) > 0"
                    :class="btnOutlineSm('danger')"
                    :title="(u.items_count ?? 0) > 0 ? t('codebooks.in_use_unit', { n: u.items_count }) : t('common.delete')">
                    {{ t('common.delete') }}
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
        </div>

        <!-- Mobile: karty -->
        <div class="md:hidden divide-y divide-neutral-100">
          <div v-for="u in units" :key="`m-${u.id}`" class="p-3 space-y-1.5">
            <div class="flex items-baseline justify-between gap-2">
              <div class="flex items-baseline gap-2">
                <span class="font-mono font-semibold">{{ u.code }}</span>
                <span class="text-sm text-neutral-700">{{ u.label_cs }}</span>
                <span class="text-xs text-neutral-500">· {{ u.label_en }}</span>
              </div>
              <span v-if="u.is_default" class="text-primary-600 text-xs">✓ {{ t('codebooks.is_default') }}</span>
            </div>
            <div class="flex justify-end gap-2">
              <button @click="editUnit(u)" :class="btnOutline('primary')">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.edit" /></svg>
                {{ t('common.edit') }}</button>
              <button @click="deleteUnit(u)" :disabled="(u.items_count ?? 0) > 0"
                :class="btnOutline('danger')"
                :title="(u.items_count ?? 0) > 0 ? t('codebooks.in_use_unit', { n: u.items_count }) : t('common.delete')">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.trash" /></svg>
                {{ t('common.delete') }}
              </button>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- ====== EXPENSE CATEGORIES ====== -->
    <section v-else-if="tab === 'expense_categories'">
      <div class="flex flex-col sm:flex-row sm:justify-between sm:items-center mb-3 gap-2">
        <p class="text-sm text-neutral-500">{{ t('expense_categories.hint') }}</p>
        <button v-if="canManageCompanyCodebooks" @click="newExpense" :class="[btnFilled('primary'), 'shrink-0 self-start']">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
          {{ t('expense_categories.new') }}
        </button>
      </div>

      <EmptyState v-if="expenseCategories.length === 0" boxed icon="tag" :title="t('expense_categories.empty')" />

      <div v-else class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
        <table class="w-full text-sm">
          <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
            <tr>
              <th class="px-3 py-2 text-left font-medium w-24">{{ t('expense_categories.code') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('expense_categories.label') }}</th>
              <th class="px-3 py-2 text-center font-medium w-24">{{ t('expense_categories.fixed_or_var') }}</th>
              <th class="px-3 py-2 text-right font-medium w-24">{{ t('expense_categories.usage') }}</th>
              <th class="px-3 py-2 w-40"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="c in expenseCategories" :key="c.id" :class="['hover:bg-neutral-50', c.archived ? 'opacity-50' : '']">
              <td class="px-3 py-2 font-mono text-xs">{{ c.code }}</td>
              <td class="px-3 py-2">
                {{ c.label }}
                <span v-if="c.archived" class="ml-2 text-xs px-1.5 py-0.5 rounded bg-neutral-100 text-neutral-500">{{ t('expense_categories.archived') }}</span>
              </td>
              <td class="px-3 py-2 text-center text-xs">
                <span :class="c.fixed_or_var === 'fixed' ? 'text-primary-700' : 'text-warning-600'">
                  {{ c.fixed_or_var === 'fixed' ? t('expense_categories.fixed') : t('expense_categories.variable') }}
                </span>
              </td>
              <td class="px-3 py-2 text-right font-mono text-xs text-neutral-600">{{ c.purchases_count || 0 }}</td>
              <td class="px-3 py-2 text-right whitespace-nowrap">
                <div v-if="canManageCompanyCodebooks" class="flex items-center justify-end gap-1.5">
                  <button @click="editExpense(c)" :class="btnOutlineSm('primary')">
                    {{ t('common.edit') }}
                  </button>
                  <button @click="removeExpense(c)" :class="btnOutlineSm('danger')">
                    {{ t('common.delete') }}
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <!-- ====== REVENUE CATEGORIES (kategorie tržeb) ====== -->
    <section v-else-if="tab === 'revenue_categories'">
      <div class="flex flex-col sm:flex-row sm:justify-between sm:items-center mb-3 gap-2">
        <p class="text-sm text-neutral-500">{{ t('revenue_categories.hint') }}</p>
        <button v-if="canManageCompanyCodebooks" @click="newRevenue" :class="[btnFilled('primary'), 'shrink-0 self-start']">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
          {{ t('revenue_categories.new') }}
        </button>
      </div>

      <EmptyState v-if="revenueCategories.length === 0" boxed icon="coin" :title="t('revenue_categories.empty')" />

      <div v-else class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
        <table class="w-full text-sm">
          <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
            <tr>
              <th class="px-3 py-2 text-left font-medium w-24">{{ t('revenue_categories.code') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('revenue_categories.label') }}</th>
              <th class="px-3 py-2 text-right font-medium w-24">{{ t('revenue_categories.usage') }}</th>
              <th class="px-3 py-2 w-40"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="c in revenueCategories" :key="c.id" :class="['hover:bg-neutral-50', c.archived ? 'opacity-50' : '']">
              <td class="px-3 py-2 font-mono text-xs">{{ c.code }}</td>
              <td class="px-3 py-2">
                {{ c.label }}
                <span v-if="c.archived" class="ml-2 text-xs px-1.5 py-0.5 rounded bg-neutral-100 text-neutral-500">{{ t('revenue_categories.archived') }}</span>
              </td>
              <td class="px-3 py-2 text-right font-mono text-xs text-neutral-600">{{ c.invoices_count || 0 }}</td>
              <td class="px-3 py-2 text-right whitespace-nowrap">
                <div v-if="canManageCompanyCodebooks" class="flex items-center justify-end gap-1.5">
                  <button @click="editRevenue(c)" :class="btnOutlineSm('primary')">
                    {{ t('common.edit') }}
                  </button>
                  <button @click="removeRevenue(c)" :class="btnOutlineSm('danger')">
                    {{ t('common.delete') }}
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <!-- ====== VAT CLASSIFICATIONS ====== -->
    <section v-else-if="tab === 'vat_classifications'">
      <div class="flex flex-col sm:flex-row sm:justify-between sm:items-center mb-3 gap-2">
        <p class="text-sm text-neutral-500">{{ t('vat_classifications.hint') }}</p>
        <button @click="newVatCls" :class="[btnFilled('primary'), 'shrink-0 self-start']">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
          {{ t('vat_classifications.new') }}
        </button>
      </div>

      <EmptyState v-if="vatClassifications.length === 0" boxed icon="clipboardCheck" :title="t('vat_classifications.empty')" />

      <div v-else class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
        <table class="w-full text-sm">
          <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
            <tr>
              <th class="px-3 py-2 text-left font-medium w-20">{{ t('vat_classifications.code') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('vat_classifications.label') }}</th>
              <th class="px-3 py-2 text-center font-medium w-24">{{ t('vat_classifications.direction') }}</th>
              <th class="px-3 py-2 text-center font-medium w-20">{{ t('vat_classifications.dphdp3_line') }}</th>
              <th class="px-3 py-2 text-center font-medium w-20">{{ t('vat_classifications.kh_section') }}</th>
              <th class="px-3 py-2 text-right font-medium w-16">{{ t('vat_classifications.vat_rate') }}</th>
              <th class="px-3 py-2 w-32"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="c in vatClassifications" :key="c.id" :class="['hover:bg-neutral-50', c.archived ? 'opacity-50' : '']">
              <td class="px-3 py-2 font-mono text-xs font-medium">
                {{ c.code }}
                <span v-if="c.is_reverse_charge" class="ml-1 text-xs px-1 py-0.5 rounded bg-warning-50 text-warning-600">RC</span>
              </td>
              <td class="px-3 py-2 text-xs">
                {{ c.label.length > 80 ? c.label.slice(0, 80) + '…' : c.label }}
                <span v-if="c.supplier_id === null" class="ml-2 text-xs px-1.5 py-0.5 rounded bg-primary-50 text-primary-700">{{ t('vat_classifications.global') }}</span>
                <span v-if="c.archived" class="ml-2 text-xs px-1.5 py-0.5 rounded bg-neutral-100 text-neutral-500">{{ t('vat_classifications.archived') }}</span>
              </td>
              <td class="px-3 py-2 text-center text-xs">
                <span :class="c.direction === 'sale' ? 'text-success-600' : c.direction === 'purchase' ? 'text-warning-600' : 'text-neutral-600'">
                  {{ t('vat_classifications.direction_' + c.direction) }}
                </span>
              </td>
              <td class="px-3 py-2 text-center font-mono text-xs">{{ c.dphdp3_line ?? '—' }}</td>
              <td class="px-3 py-2 text-center font-mono text-xs">{{ c.kh_section ?? '—' }}</td>
              <td class="px-3 py-2 text-right font-mono text-xs">{{ c.vat_rate !== null ? c.vat_rate.toFixed(0) + '%' : '—' }}</td>
              <td class="px-3 py-2 text-right whitespace-nowrap">
                <div class="flex items-center justify-end gap-1.5">
                  <button @click="editVatCls(c)" :disabled="c.supplier_id === null"
                    :title="c.supplier_id === null ? t('vat_classifications.global_readonly') : t('common.edit')"
                    :class="btnOutlineSm('primary')">
                    {{ t('common.edit') }}
                  </button>
                  <button @click="removeVatCls(c)" :disabled="c.supplier_id === null"
                    :title="c.supplier_id === null ? t('vat_classifications.global_readonly') : t('common.delete')"
                    :class="btnOutlineSm('danger')">
                    {{ t('common.delete') }}
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <!-- ====== OSS — sazby DPH členských států ====== -->
    <section v-else-if="tab === 'oss_rates'">
      <div class="flex flex-col gap-3 mb-3">
        <p class="text-sm text-neutral-500">{{ t('oss_rates.hint') }}</p>

        <div v-if="!ossAvailable" class="rounded-md border border-danger-200 bg-danger-50 p-3 text-sm text-danger-600">
          {{ t('oss_rates.missing_migration') }}
        </div>
        <div v-else-if="!ossManageable" class="rounded-md border border-warning-200 bg-warning-50 p-3 text-sm text-warning-600">
          {{ t('oss_rates.missing_management_migration') }}
        </div>
        <div v-else-if="!ossCanWrite" class="rounded-md border border-neutral-200 bg-neutral-50 p-3 text-sm text-neutral-600">
          {{ t('oss_rates.readonly_for_role') }}
        </div>
        <div v-if="ossAvailable && ossCoverageGaps.length > 0"
          class="rounded-md border border-warning-200 bg-warning-50 p-3 text-sm text-warning-600">
          {{ t('oss_rates.coverage_gap', { countries: ossCoverageGaps.join(', ') }) }}
        </div>

        <div class="flex flex-wrap items-center gap-2">
          <input v-model="ossCountryFilter" maxlength="2" :placeholder="t('oss_rates.filter_country')"
            class="h-9 w-28 px-3 border border-neutral-300 rounded-md bg-surface text-sm uppercase" />
          <label class="inline-flex items-center gap-2 text-sm text-neutral-600 cursor-pointer">
            <input v-model="ossShowDisabled" type="checkbox"
              class="w-4 h-4 rounded border-neutral-300 text-primary-600 focus:ring-primary-500" />
            {{ t('oss_rates.show_disabled') }}
          </label>
          <span class="text-xs text-neutral-500">{{ t('oss_rates.count', { n: ossRatesFiltered.length }) }}</span>
          <button v-if="ossCanWrite && ossManageable" @click="newOssRate"
            :class="[btnFilled('primary'), 'ml-auto shrink-0 whitespace-nowrap']">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
            {{ t('oss_rates.new') }}
          </button>
        </div>
      </div>

      <EmptyState v-if="ossRatesFiltered.length === 0" boxed icon="clipboardCheck" :title="t('oss_rates.empty')" />

      <div v-else class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
              <tr>
                <th class="px-3 py-2 text-left font-medium w-16">{{ t('oss_rates.country') }}</th>
                <th class="px-3 py-2 text-left font-medium w-36">{{ t('oss_rates.rate_type') }}</th>
                <th class="px-3 py-2 text-right font-medium w-20">{{ t('oss_rates.rate_percent') }}</th>
                <th class="px-3 py-2 text-center font-medium w-28">{{ t('oss_rates.valid_from') }}</th>
                <th class="px-3 py-2 text-center font-medium w-28">{{ t('oss_rates.valid_to') }}</th>
                <th class="px-3 py-2 text-left font-medium">{{ t('oss_rates.origin') }}</th>
                <th class="px-3 py-2 w-56"></th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="r in ossRatesFiltered" :key="r.id" :class="['hover:bg-neutral-50', r.disabled ? 'opacity-50' : '']">
                <td class="px-3 py-2 font-mono text-xs font-medium">{{ r.country }}</td>
                <td class="px-3 py-2 text-xs">{{ t('oss_rates.type_' + r.rate_type) }}</td>
                <td class="px-3 py-2 text-right font-mono text-xs">{{ r.rate_percent }} %</td>
                <td class="px-3 py-2 text-center font-mono text-xs">{{ r.valid_from }}</td>
                <td class="px-3 py-2 text-center font-mono text-xs">
                  {{ r.effective_valid_to ?? '—' }}
                  <span v-if="r.valid_to_override" class="ml-1 text-xs px-1 py-0.5 rounded bg-warning-50 text-warning-600">
                    {{ t('oss_rates.shortened') }}
                  </span>
                </td>
                <td class="px-3 py-2 text-xs">
                  <span v-if="r.is_custom" class="text-xs px-1.5 py-0.5 rounded bg-primary-50 text-primary-700">{{ t('oss_rates.custom') }}</span>
                  <span v-else class="text-xs px-1.5 py-0.5 rounded bg-neutral-100 text-neutral-600">{{ t('oss_rates.seeded') }}</span>
                  <span v-if="r.disabled" class="ml-2 text-xs px-1.5 py-0.5 rounded bg-danger-50 text-danger-600">{{ t('oss_rates.disabled') }}</span>
                  <span v-if="r.note" class="ml-2 text-neutral-500">{{ r.note }}</span>
                </td>
                <td class="px-3 py-2 text-right whitespace-nowrap">
                  <div class="flex items-center justify-end gap-1.5 flex-wrap">
                    <button @click="editOssRate(r)" :disabled="!ossCanWrite || !ossManageable"
                      :title="r.is_custom ? t('common.edit') : t('oss_rates.shorten_title')"
                      :class="btnOutlineSm('primary')">
                      {{ r.is_custom ? t('common.edit') : t('oss_rates.shorten') }}
                    </button>
                    <button @click="toggleOssRateDisabled(r)" :disabled="!ossCanWrite || !ossManageable"
                      :class="btnOutlineSm('warning')">
                      {{ r.disabled ? t('oss_rates.enable') : t('oss_rates.disable') }}
                    </button>
                    <button v-if="r.is_custom" @click="removeOssRate(r)" :disabled="!ossCanWrite || !ossManageable"
                      :class="btnOutlineSm('danger')">
                      {{ t('common.delete') }}
                    </button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </section>

    <!-- ====== TAX CONSTANTS (roční daňové konstanty) ====== -->
    <section v-else-if="tab === 'tax_constants'">
      <div class="flex flex-wrap items-center gap-3 mb-3">
        <label class="text-sm text-neutral-600">{{ t('codebooks.tax_year') }}:</label>
        <select v-model.number="taxYear" @change="selectTaxYear(taxYear)"
          class="h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
          <option v-for="y in taxYears" :key="y.year" :value="y.year">{{ y.year }}</option>
        </select>
        <button v-if="auth.isSuperadmin" @click="addTaxYear" type="button"
          :disabled="!canAddTaxYear"
          :title="canAddTaxYear ? '' : t('codebooks.tax_add_year_max', { year: maxAddableTaxYear })"
          :class="btnOutline('primary')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
          {{ t('codebooks.tax_add_year') }}
        </button>
        <span v-if="taxIsNew" class="text-xs px-2 py-0.5 rounded bg-primary-50 text-primary-700 font-medium">{{ t('codebooks.tax_year_new') }}</span>
        <span v-else-if="taxIsOverride" class="text-xs px-2 py-0.5 rounded bg-warning-50 text-warning-600 font-medium">{{ t('codebooks.tax_overridden') }}</span>
        <span v-else class="text-xs px-2 py-0.5 rounded bg-neutral-100 text-neutral-500">{{ t('codebooks.tax_default') }}</span>
        <div class="ml-auto flex items-center gap-2" v-if="auth.isSuperadmin">
          <button v-if="taxIsOverride" @click="resetTaxConstants" type="button"
            :class="btnOutline('neutral')">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.uturn" /></svg>
            {{ t('codebooks.tax_reset') }}</button>
          <button @click="saveTaxConstants" :disabled="taxSaving" type="button"
            :class="btnFilled('primary')">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
            {{ t('common.save') }}</button>
        </div>
      </div>
      <p class="text-xs text-neutral-500 mb-4 max-w-3xl">{{ t('codebooks.tax_hint') }}</p>

      <div v-if="taxModel">
        <nav class="flex flex-wrap gap-1 border-b border-neutral-200 mb-4" :aria-label="t('codebooks.tax_sections')">
          <button v-for="item in taxConstantTabs" :key="item" type="button" @click="taxConstantsTab = item"
            class="px-3 py-2 text-sm font-medium border-b-2 -mb-px whitespace-nowrap transition-colors cursor-pointer"
            :class="taxConstantsTab === item ? 'border-primary-600 text-primary-700' : 'border-transparent text-neutral-500 hover:text-neutral-800'">
            {{ t(`codebooks.tax_tab_${item}`) }}
          </button>
        </nav>

        <div class="space-y-4">
          <div class="bg-surface border border-neutral-200 rounded-lg p-4 shadow-sm">
            <h3 class="text-xs font-semibold uppercase tracking-wide text-neutral-500 mb-3">{{ t(`codebooks.tax_tab_${taxConstantsTab}`) }}</h3>
            <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-3">
              <label v-for="key in taxScalarFields[taxConstantsTab]" :key="key" class="block">
                <span class="text-xs text-neutral-500">{{ t(`codebooks.tax_f_${key}`) }}</span>
                <input v-model.number="taxModel[key]" type="number" step="any" class="mt-0.5 h-9 w-full px-2 border border-neutral-300 rounded text-sm font-mono" />
              </label>
            </div>
          </div>

          <template v-if="taxConstantsTab === 'fo'">
            <div class="grid lg:grid-cols-2 gap-4">
              <div class="bg-surface border border-neutral-200 rounded-lg p-4 shadow-sm">
                <h3 class="text-xs font-semibold uppercase tracking-wide text-neutral-500 mb-3">{{ t('codebooks.tax_g_credits') }}</h3>
                <div class="grid grid-cols-3 gap-3">
                  <label v-for="i in 3" :key="i"><span class="text-xs text-neutral-500">{{ t('codebooks.tax_f_child', { n: i }) }}</span>
                    <input v-model.number="taxModel.child_credits[i - 1]" type="number" class="mt-0.5 h-9 w-full px-2 border border-neutral-300 rounded text-sm font-mono" /></label>
                </div>
              </div>
              <div class="bg-surface border border-neutral-200 rounded-lg p-4 shadow-sm">
                <h3 class="text-xs font-semibold uppercase tracking-wide text-neutral-500 mb-3">{{ t('codebooks.tax_g_dates') }}</h3>
                <label><span class="text-xs text-neutral-500">{{ t('codebooks.tax_f_mortgage_pre2021_cutoff') }}</span>
                  <input v-model="taxModel.mortgage_pre2021_cutoff" type="date" class="mt-0.5 h-9 w-full px-2 border border-neutral-300 rounded text-sm font-mono" /></label>
              </div>
            </div>
          </template>

          <!-- `payroll` doplňuje merge z defaultů, ale u ručně založeného roku může chybět. -->
          <template v-if="taxConstantsTab === 'payroll' && taxModel.payroll">
            <div class="bg-surface border border-neutral-200 rounded-lg p-4 shadow-sm">
              <h3 class="text-xs font-semibold uppercase tracking-wide text-neutral-500 mb-3">{{ t('codebooks.tax_g_payroll_rates') }}</h3>
              <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-3">
                <label v-for="key in payrollRateFields" :key="key" class="block">
                  <span class="text-xs text-neutral-500">{{ t(`codebooks.tax_f_payroll_${key}`) }}</span>
                  <input v-model.number="taxModel.payroll[key]" type="number" step="any" min="0" max="1"
                    class="mt-0.5 h-9 w-full px-2 border border-neutral-300 rounded text-sm font-mono" />
                </label>
              </div>
              <p class="text-xs text-neutral-500 mt-3">{{ t('codebooks.tax_g_payroll_hint') }}</p>
            </div>
          </template>

          <template v-if="taxConstantsTab === 'osvc'">
            <!-- Paušální daň — rozvrh měsíčních záloh (sazba se může měnit uprostřed roku) -->
            <div class="bg-surface border border-neutral-200 rounded-lg p-4 shadow-sm">
              <h3 class="text-xs font-semibold uppercase tracking-wide text-neutral-500 mb-3">{{ t('codebooks.tax_g_pausal') }}</h3>
              <p class="text-[11px] text-neutral-400 mb-3">{{ t('codebooks.tax_pausal_hint') }}</p>

              <div v-for="(seg, i) in taxModel.pausal_monthly" :key="seg.from" class="grid grid-cols-[1fr_repeat(3,minmax(0,1fr))_auto] gap-2 items-end mb-2">
                <label class="block">
                  <span class="text-xs text-neutral-500">{{ t('codebooks.tax_f_pausal_from') }}</span>
                  <select v-if="i > 0" v-model="seg.from" @change="sortPausalSegments"
                    class="mt-0.5 h-8 w-full px-2 border border-neutral-300 rounded text-sm bg-surface">
                    <option v-for="o in pausalMonthOptions" :key="o.value" :value="o.value" :disabled="pausalMonthTaken(o.value, i)">{{ o.label }}</option>
                  </select>
                  <div v-else class="mt-0.5 h-8 flex items-center px-2 text-sm text-neutral-500">{{ pausalMonthOptions[0]?.label }}</div>
                </label>
                <label v-for="(b, bi) in taxBands" :key="b" class="block">
                  <span class="text-xs text-neutral-500">{{ t('codebooks.tax_f_band', { n: bi + 1 }) }}</span>
                  <input v-model.number="seg[b]" type="number" min="0" class="mt-0.5 h-8 w-full px-2 border border-neutral-300 rounded text-sm font-mono" />
                </label>
                <button v-if="i > 0" type="button" @click="removePausalSegment(i)"
                  class="h-8 px-2 text-danger-600 hover:bg-danger-50 rounded text-sm" :title="t('common.delete')">✕</button>
                <span v-else class="h-8 w-8"></span>
              </div>

              <div class="flex items-center justify-between gap-3 mt-3 pt-3 border-t border-neutral-100">
                <button type="button" @click="addPausalSegment"
                  class="text-xs text-primary-600 hover:text-primary-700 font-medium">+ {{ t('codebooks.tax_pausal_add_period') }}</button>
                <div class="text-xs text-neutral-500">
                  {{ t('codebooks.tax_pausal_annual_derived') }}
                  <span class="font-mono text-neutral-700 ml-1">{{ taxBands.map(b => pausalAnnualPreview[b].toLocaleString('cs-CZ')).join(' · ') }}</span>
                </div>
              </div>
            </div>

            <div class="grid lg:grid-cols-2 gap-4">
              <div class="bg-surface border border-neutral-200 rounded-lg p-4 shadow-sm">
                <h3 class="text-xs font-semibold uppercase tracking-wide text-neutral-500 mb-3">{{ t('codebooks.tax_g_expense_caps') }}</h3>
                <div class="grid grid-cols-4 gap-3"><label v-for="r in taxRates" :key="r"><span class="text-xs text-neutral-500">{{ t('codebooks.tax_f_cap', { rate: r }) }}</span>
                  <input v-model.number="taxModel.expense_caps[r]" type="number" class="mt-0.5 h-9 w-full px-2 border border-neutral-300 rounded text-sm font-mono" /></label></div>
              </div>
            </div>
            <div class="bg-surface border border-neutral-200 rounded-lg p-4 shadow-sm overflow-x-auto">
              <h3 class="text-xs font-semibold uppercase tracking-wide text-neutral-500 mb-3">{{ t('codebooks.tax_g_ceilings') }}</h3>
              <table class="text-sm"><thead><tr class="text-xs text-neutral-500"><th class="text-left pr-3">{{ t('codebooks.tax_f_rate') }}</th><th v-for="(b, i) in taxBands" :key="b" class="px-2">{{ t('codebooks.tax_f_band', { n: i + 1 }) }}</th></tr></thead>
                <tbody><tr v-for="r in taxRates" :key="r"><td class="pr-3 py-1 font-mono">{{ r }} %</td><td v-for="b in taxBands" :key="b" class="px-2 py-1"><input v-model.number="taxModel.band_ceilings[r][b]" type="number" class="h-9 w-32 px-2 border border-neutral-300 rounded text-sm font-mono" /></td></tr></tbody></table>
            </div>
          </template>

          <div v-if="taxConstantsTab === 'po'" class="bg-surface border border-neutral-200 rounded-lg p-4 shadow-sm">
            <h3 class="text-xs font-semibold uppercase tracking-wide text-neutral-500 mb-3">{{ t('codebooks.tax_g_advance_months') }}</h3>
            <div class="grid sm:grid-cols-2 gap-4">
              <label v-for="kind in ['semiannual', 'quarterly']" :key="kind"><span class="text-xs text-neutral-500">{{ t(`codebooks.tax_f_advance_${kind}_months`) }}</span>
                <div class="flex flex-wrap gap-2 mt-1"><input v-for="(_, i) in taxModel[`advance_${kind}_months`]" :key="i" v-model.number="taxModel[`advance_${kind}_months`][i]" type="number" min="1" max="12" class="h-9 w-16 px-2 border border-neutral-300 rounded text-sm font-mono" /></div></label>
            </div>
          </div>

          <template v-if="taxConstantsTab === 'assets'">
            <div class="bg-surface border border-neutral-200 rounded-lg p-4 shadow-sm">
              <h3 class="text-xs font-semibold uppercase tracking-wide text-neutral-500 mb-3">{{ t('codebooks.tax_g_extraordinary') }}</h3>
              <div class="grid sm:grid-cols-2 lg:grid-cols-5 gap-3"><label v-for="key in ['eligible_from', 'eligible_to', 'total_months', 'phase1_months', 'phase1_share']" :key="key"><span class="text-xs text-neutral-500">{{ t(`codebooks.tax_f_extra_${key}`) }}</span>
                <input v-if="key.startsWith('eligible_')" v-model="taxModel.extraordinary_depreciation[key]" type="date" class="mt-0.5 h-9 w-full px-2 border border-neutral-300 rounded text-sm font-mono" />
                <input v-else v-model.number="taxModel.extraordinary_depreciation[key]" type="number" step="any" class="mt-0.5 h-9 w-full px-2 border border-neutral-300 rounded text-sm font-mono" /></label></div>
            </div>
            <div class="bg-surface border border-neutral-200 rounded-lg p-4 shadow-sm overflow-x-auto">
              <h3 class="text-xs font-semibold uppercase tracking-wide text-neutral-500 mb-3">{{ t('codebooks.tax_g_depreciation_straight') }}</h3>
              <table class="text-sm"><thead><tr class="text-xs text-neutral-500"><th class="text-left pr-3">{{ t('codebooks.tax_f_variant') }}</th><th>{{ t('codebooks.tax_f_group') }}</th><th v-for="i in 3" :key="i" class="px-2">{{ t(`codebooks.tax_f_depr_rate_${i}`) }}</th></tr></thead>
                <tbody v-for="variant in ['basic', 'p20', 'p15', 'p10']" :key="variant"><tr v-for="(rates, group) in taxModel.depreciation_straight_rates[variant]" :key="group"><td class="pr-3 font-mono">{{ variant }}</td><td class="px-2 font-mono">{{ group }}</td><td v-for="(_, i) in rates" :key="i" class="px-2 py-1"><input v-model.number="rates[i]" type="number" step="any" class="h-9 w-24 px-2 border border-neutral-300 rounded text-sm font-mono" /></td></tr></tbody></table>
            </div>
            <div class="bg-surface border border-neutral-200 rounded-lg p-4 shadow-sm overflow-x-auto">
              <h3 class="text-xs font-semibold uppercase tracking-wide text-neutral-500 mb-3">{{ t('codebooks.tax_g_depreciation_accelerated') }}</h3>
              <table class="text-sm"><tbody><tr v-for="(coefficients, group) in taxModel.depreciation_accelerated_coefficients" :key="group"><td class="pr-3 font-mono">{{ t('codebooks.tax_f_group') }} {{ group }}</td><td v-for="(_, i) in coefficients" :key="i" class="px-2 py-1"><input v-model.number="coefficients[i]" type="number" step="any" class="h-9 w-24 px-2 border border-neutral-300 rounded text-sm font-mono" /></td></tr></tbody></table>
            </div>
          </template>

          <template v-if="taxConstantsTab === 'reporting'">
            <div class="bg-surface border border-neutral-200 rounded-lg p-4 shadow-sm overflow-x-auto">
              <h3 class="text-xs font-semibold uppercase tracking-wide text-neutral-500 mb-3">{{ t('codebooks.tax_g_entity_thresholds') }}</h3>
              <table class="text-sm"><thead><tr class="text-xs text-neutral-500"><th class="text-left pr-3">{{ t('codebooks.tax_f_category') }}</th><th v-for="key in ['assets_net', 'net_turnover', 'employees']" :key="key" class="px-2">{{ t(`codebooks.tax_f_${key}`) }}</th></tr></thead>
                <tbody><tr v-for="category in ['micro', 'small', 'medium']" :key="category"><td class="pr-3">{{ t(`codebooks.tax_category_${category}`) }}</td><td v-for="key in ['assets_net', 'net_turnover', 'employees']" :key="key" class="px-2 py-1"><input v-model.number="taxModel.entity_category_thresholds[category][key]" type="number" class="h-9 w-36 px-2 border border-neutral-300 rounded text-sm font-mono" /></td></tr></tbody></table>
            </div>
            <div class="bg-surface border border-neutral-200 rounded-lg p-4 shadow-sm">
              <h3 class="text-xs font-semibold uppercase tracking-wide text-neutral-500 mb-3">{{ t('codebooks.tax_g_deadlines') }}</h3>
              <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-3"><label v-for="key in ['dpfo_paper', 'dpfo_electronic', 'advisor', 'insurance_electronic', 'insurance_advisor']" :key="key"><span class="text-xs text-neutral-500">{{ t(`codebooks.tax_f_${key}`) }}</span><input v-model="taxModel.filing_deadlines[key]" placeholder="MM-DD" class="mt-0.5 h-9 w-full px-2 border border-neutral-300 rounded text-sm font-mono" /></label>
                <label v-for="key in ['health_advance_day', 'tax_advance_day']" :key="key"><span class="text-xs text-neutral-500">{{ t(`codebooks.tax_f_${key}`) }}</span><input v-model.number="taxModel.filing_deadlines[key]" type="number" min="1" max="31" class="mt-0.5 h-9 w-full px-2 border border-neutral-300 rounded text-sm font-mono" /></label></div>
            </div>
          </template>
        </div>
      </div>
    </section>

    <!-- ====== Modals ====== -->

    <!-- VAT classification modal -->
    <div v-if="vatClsOpen" class="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
      <div class="bg-surface rounded-xl shadow-lg max-w-xl w-full p-5">
        <h3 class="text-lg font-semibold mb-3">
          {{ vatClsEditMode === 'edit' ? t('vat_classifications.edit_title') : t('vat_classifications.new_title') }}
        </h3>
        <div class="space-y-3">
          <div class="grid grid-cols-3 gap-3">
            <div>
              <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('vat_classifications.code') }} *</label>
              <input v-model="vatClsDraft.code" type="text" maxlength="8"
                :disabled="vatClsEditMode === 'edit'"
                @input="vatClsSlug.markManual(($event.target as HTMLInputElement).value)"
                class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono disabled:bg-neutral-100" />
            </div>
            <div>
              <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('vat_classifications.direction') }}</label>
              <select v-model="vatClsDraft.direction" class="w-full h-10 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
                <option value="sale">{{ t('vat_classifications.direction_sale') }}</option>
                <option value="purchase">{{ t('vat_classifications.direction_purchase') }}</option>
                <option value="both">{{ t('vat_classifications.direction_both') }}</option>
              </select>
            </div>
            <div>
              <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('vat_classifications.vat_rate') }}</label>
              <input v-model.number="vatClsDraft.vat_rate" type="number" step="0.1" placeholder="21"
                class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
            </div>
          </div>
          <div>
            <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('vat_classifications.label') }} *</label>
            <input v-model="vatClsDraft.label" type="text" maxlength="150"
              @input="vatClsSlug.fromName(($event.target as HTMLInputElement).value)"
              class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
          </div>
          <div class="grid grid-cols-3 gap-3">
            <div>
              <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('vat_classifications.dphdp3_line') }}</label>
              <input v-model="vatClsDraft.dphdp3_line" type="text" maxlength="10" placeholder="1"
                class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
            </div>
            <div>
              <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('vat_classifications.kh_section') }}</label>
              <input v-model="vatClsDraft.kh_section" type="text" maxlength="8" placeholder="A.4"
                class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
            </div>
            <div>
              <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('vat_classifications.display_order') }}</label>
              <input v-model.number="vatClsDraft.display_order" type="number" step="1"
                class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
            </div>
          </div>
          <label class="flex items-center gap-2 text-sm">
            <input v-model="vatClsDraft.is_reverse_charge" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
            {{ t('vat_classifications.is_reverse_charge') }}
          </label>
          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('vat_classifications.kh_regime_code') }}</label>
              <select v-model="vatClsDraft.kh_regime_code" class="w-full h-10 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
                <option value="">{{ t('vat_classifications.kh_default') }}</option>
                <option value="0">0 — {{ t('vat_classifications.kh_regime_standard') }}</option>
                <option value="1">1 — {{ t('vat_classifications.kh_regime_travel') }}</option>
                <option value="2">2 — {{ t('vat_classifications.kh_regime_used_goods') }}</option>
              </select>
            </div>
            <div>
              <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('vat_classifications.kh_bad_debt') }}</label>
              <select v-model="vatClsDraft.kh_bad_debt" class="w-full h-10 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
                <option value="">{{ t('vat_classifications.kh_default') }}</option>
                <option value="N">N — {{ t('vat_classifications.kh_bad_debt_no') }}</option>
                <option value="P">P — {{ t('vat_classifications.kh_bad_debt_yes') }}</option>
              </select>
            </div>
          </div>
          <div v-if="vatClsDraft.is_reverse_charge">
            <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('vat_classifications.kod_pred_pl') }}</label>
            <input v-model="vatClsDraft.kod_pred_pl" type="text" inputmode="numeric" maxlength="3"
              class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
            <p class="mt-1 text-xs text-neutral-500">{{ t('vat_classifications.kod_pred_pl_hint') }}</p>
          </div>
          <label v-if="vatClsEditMode === 'edit'" class="flex items-center gap-2 text-sm">
            <input v-model="vatClsDraft.archived" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
            {{ t('vat_classifications.archive') }}
          </label>
        </div>
        <div class="flex justify-end gap-2 pt-4 mt-3 border-t border-neutral-200">
          <button @click="vatClsOpen = false" :class="btnOutline('neutral')">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>
            {{ t('common.cancel') }}</button>
          <button @click="saveVatCls" :class="btnFilled('primary')">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
            {{ t('common.save') }}</button>
        </div>
      </div>
    </div>

    <!-- OSS member-state rate modal — u seedu jen překryv platnosti, viz migrace 1296 -->
    <div v-if="ossOpen" class="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
      <div class="bg-surface rounded-xl shadow-lg max-w-lg w-full p-5">
        <h3 class="text-lg font-semibold mb-1">
          {{ ossEditMode === 'override' ? t('oss_rates.shorten_title')
            : ossEditMode === 'edit' ? t('oss_rates.edit_title') : t('oss_rates.new_title') }}
        </h3>
        <p class="text-sm text-neutral-500 mb-3">
          {{ ossEditMode === 'override' ? t('oss_rates.shorten_hint') : t('oss_rates.new_hint') }}
        </p>

        <div v-if="ossEditMode === 'override'" class="space-y-3">
          <div class="rounded-md border border-neutral-200 bg-neutral-50 p-3 text-sm text-neutral-600">
            {{ ossDraft.country }} · {{ t('oss_rates.type_' + ossDraft.rate_type) }} ·
            {{ ossDraft.rate_percent }} % · {{ t('oss_rates.valid_from') }} {{ ossDraft.valid_from }}
          </div>
          <div>
            <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('oss_rates.valid_to_override') }}</label>
            <input v-model="ossDraft.valid_to_override" type="date"
              class="w-full h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm" />
          </div>
        </div>

        <div v-else class="space-y-3">
          <div class="grid grid-cols-3 gap-3">
            <div>
              <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('oss_rates.country') }} *</label>
              <input v-model="ossDraft.country" maxlength="2"
                class="w-full h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm uppercase" />
            </div>
            <div>
              <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('oss_rates.rate_type') }} *</label>
              <select v-model="ossDraft.rate_type"
                class="w-full h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
                <option v-for="rt in ossRateTypes" :key="rt" :value="rt">{{ t('oss_rates.type_' + rt) }}</option>
              </select>
            </div>
            <div>
              <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('oss_rates.rate_percent') }} *</label>
              <input v-model.number="ossDraft.rate_percent" type="number" step="0.01" min="0" max="99.99"
                class="w-full h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm" />
            </div>
          </div>
          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('oss_rates.valid_from') }} *</label>
              <input v-model="ossDraft.valid_from" type="date"
                class="w-full h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm" />
            </div>
            <div>
              <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('oss_rates.valid_to') }}</label>
              <input v-model="ossDraft.valid_to" type="date"
                class="w-full h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm" />
            </div>
          </div>
          <div>
            <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('oss_rates.note') }}</label>
            <input v-model="ossDraft.note" maxlength="190"
              class="w-full h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm" />
          </div>
        </div>

        <div class="flex justify-end gap-2 pt-4 mt-3 border-t border-neutral-200">
          <button @click="ossOpen = false" :class="btnOutline('neutral')">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>
            {{ t('common.cancel') }}</button>
          <button @click="saveOssRate" :class="btnFilled('primary')">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
            {{ t('common.save') }}</button>
        </div>
      </div>
    </div>

    <!-- Expense category modal -->
    <div v-if="canManageCompanyCodebooks && expenseOpen" class="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
      <div class="bg-surface rounded-xl shadow-lg max-w-md w-full p-5">
        <h3 class="text-lg font-semibold mb-3">
          {{ expenseDraft.id ? t('expense_categories.edit_title') : t('expense_categories.new_title') }}
        </h3>
        <div class="space-y-3">
          <!-- Název je první: kód se z něj generuje slugifikací, takže začínat
               kódem znamenalo vyplnit pole, které si systém stejně přepíše. -->
          <div>
            <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('expense_categories.label') }} *</label>
            <input v-model="expenseDraft.label" type="text" maxlength="100" placeholder="Hosting a domény"
              @input="expenseSlug.fromName(($event.target as HTMLInputElement).value)"
              class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
          </div>
          <div>
            <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('expense_categories.code') }} *</label>
            <input v-model="expenseDraft.code" type="text" maxlength="20" placeholder="hosting"
              @input="expenseSlug.markManual(($event.target as HTMLInputElement).value)"
              class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
            <p class="text-xs text-neutral-500 mt-1">{{ t('expense_categories.code_hint') }}</p>
          </div>
          <div>
            <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('expense_categories.fixed_or_var') }}</label>
            <select v-model="expenseDraft.fixed_or_var" class="w-full h-10 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
              <option value="variable">{{ t('expense_categories.variable') }}</option>
              <option value="fixed">{{ t('expense_categories.fixed') }}</option>
            </select>
          </div>
          <div>
            <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('expense_categories.display_order') }}</label>
            <input v-model.number="expenseDraft.display_order" type="number" step="1"
              class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
          </div>
          <label v-if="expenseDraft.id" class="flex items-center gap-2 text-sm">
            <input v-model="expenseDraft.archived" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
            {{ t('expense_categories.archive') }}
          </label>
        </div>
        <div class="flex justify-end gap-2 pt-4 mt-3 border-t border-neutral-200">
          <button @click="expenseOpen = false" :class="btnOutline('neutral')">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>
            {{ t('common.cancel') }}</button>
          <button @click="saveExpense" :class="btnFilled('primary')">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
            {{ t('common.save') }}</button>
        </div>
      </div>
    </div>

    <!-- Revenue category modal -->
    <div v-if="canManageCompanyCodebooks && revenueOpen" class="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
      <div class="bg-surface rounded-xl shadow-lg max-w-lg w-full p-5 max-h-[90vh] overflow-y-auto">
        <h3 class="text-lg font-semibold mb-3">
          {{ revenueDraft.id ? t('revenue_categories.edit_title') : t('revenue_categories.new_title') }}
        </h3>
        <div class="space-y-3">
          <!-- Název první, kód se z něj slugifikuje — viz kategorie nákladů. -->
          <div>
            <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('revenue_categories.label') }} *</label>
            <input v-model="revenueDraft.label" type="text" maxlength="100" placeholder="Konzultace a poradenství"
              @input="revenueSlug.fromName(($event.target as HTMLInputElement).value)"
              class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
          </div>
          <div>
            <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('revenue_categories.code') }} *</label>
            <input v-model="revenueDraft.code" type="text" maxlength="20" placeholder="konzultace"
              @input="revenueSlug.markManual(($event.target as HTMLInputElement).value)"
              class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
            <p class="text-xs text-neutral-500 mt-1">{{ t('revenue_categories.code_hint') }}</p>
          </div>
          <div>
            <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('revenue_categories.display_order') }}</label>
            <input v-model.number="revenueDraft.display_order" type="number" step="1"
              class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
          </div>
          <label v-if="revenueDraft.id" class="flex items-center gap-2 text-sm">
            <input v-model="revenueDraft.archived" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
            {{ t('revenue_categories.archive') }}
          </label>

          <!-- Vlastní číselná řada kategorie (volitelná) — vzor viz ClientForm.vue -->
          <details class="pt-3 border-t border-neutral-100" :open="revenueHasNumbering">
            <summary class="cursor-pointer text-sm font-medium text-neutral-700">
              {{ t('revenue_categories.numbering_section') }}
            </summary>
            <p class="text-xs text-neutral-500 mt-1 mb-3">{{ t('revenue_categories.numbering_hint') }}</p>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
              <div v-for="field in (['invoice_number_format', 'proforma_number_format', 'credit_note_number_format'] as const)" :key="field">
                <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t(`revenue_categories.${field}`) }}</label>
                <input v-model="revenueDraft[field]" type="text" maxlength="60"
                  :placeholder="field === 'proforma_number_format' ? '9{YY}{CCCC}' : '{YY}{CCCC}'"
                  class="w-full h-10 px-3 border border-neutral-300 rounded-md font-mono text-sm focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none" />
                <p v-if="revenueNumberingWarning(field)" class="text-xs text-warning-600 mt-1">
                  {{ revenueNumberingWarning(field) }}
                </p>
                <p v-else-if="revenueDraft[field]" class="text-xs text-neutral-500 mt-1 font-mono">
                  {{ t('revenue_categories.numbering_preview') }}: {{ revenueNumberingPreview(field) }}
                </p>
              </div>
              <div>
                <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('revenue_categories.invoice_number_period') }}</label>
                <select v-model="revenueDraft.invoice_number_period"
                  class="w-full h-10 px-3 border border-neutral-300 rounded-md bg-surface focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none">
                  <option :value="null">{{ t('revenue_categories.numbering_period_inherit') }}</option>
                  <option value="year">{{ t('revenue_categories.numbering_period_year') }}</option>
                  <option value="month">{{ t('revenue_categories.numbering_period_month') }}</option>
                  <option value="none">{{ t('revenue_categories.numbering_period_none') }}</option>
                </select>
              </div>
            </div>
            <p class="text-xs text-neutral-500 mt-2">{{ t('revenue_categories.numbering_placeholders_hint') }}</p>
          </details>
        </div>
        <div class="flex justify-end gap-2 pt-4 mt-3 border-t border-neutral-200">
          <button @click="revenueOpen = false" :class="btnOutline('neutral')">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>
            {{ t('common.cancel') }}</button>
          <button @click="saveRevenue" :class="btnFilled('primary')">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
            {{ t('common.save') }}</button>
        </div>
      </div>
    </div>

    <div v-if="vatOpen" class="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
      <div class="bg-surface rounded-xl shadow-lg max-w-md w-full p-5">
        <h3 class="text-lg font-semibold mb-3">{{ vatDraft._new ? t('codebooks.new_vat') : vatDraft.code }}</h3>
        <div class="space-y-3">
          <div class="grid grid-cols-3 gap-3">
            <div><label class="block text-sm font-medium mb-1">{{ t('codebooks.country') }}</label>
              <input v-model="vatDraft.country" type="text" maxlength="2" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono uppercase" /></div>
            <div><label class="block text-sm font-medium mb-1">{{ t('codebooks.code') }} *</label>
              <input v-model="vatDraft.code" type="text" placeholder="STD" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono" /></div>
            <div><label class="block text-sm font-medium mb-1">% *</label>
              <input v-model.number="vatDraft.rate_percent" type="number" step="0.01" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono" /></div>
          </div>
          <div class="grid grid-cols-2 gap-3">
            <div><label class="block text-sm font-medium mb-1">{{ t('codebooks.name_cs') }}</label>
              <input v-model="vatDraft.label_cs" type="text" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" /></div>
            <div><label class="block text-sm font-medium mb-1">{{ t('codebooks.name_en') }}</label>
              <input v-model="vatDraft.label_en" type="text" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" /></div>
          </div>
          <div class="grid grid-cols-2 gap-3">
            <div><label class="block text-sm font-medium mb-1">{{ t('codebooks.valid_from') }}</label>
              <input v-model="vatDraft.valid_from" type="date" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" /></div>
            <div><label class="block text-sm font-medium mb-1">{{ t('codebooks.valid_to') }}</label>
              <input v-model="vatDraft.valid_to" type="date" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" /></div>
          </div>
          <label class="flex items-center gap-2 text-sm">
            <input v-model="vatDraft.is_default" type="checkbox" class="rounded border-neutral-300 text-primary-600" /> {{ t('codebooks.is_default_for_country') }}
          </label>
          <label class="flex items-center gap-2 text-sm">
            <input v-model="vatDraft.is_reverse_charge" type="checkbox" class="rounded border-neutral-300 text-primary-600" /> {{ t('codebooks.is_reverse_charge_label') }}
          </label>
          <div class="flex justify-end gap-2 pt-2">
            <button @click="vatOpen = false" :class="btnOutline('neutral')">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>
              {{ t('common.cancel') }}</button>
            <button @click="saveVat" :class="btnFilled('primary')">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
              {{ t('common.save') }}</button>
          </div>
        </div>
      </div>
    </div>

    <div v-if="unitOpen" class="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
      <div class="bg-surface rounded-xl shadow-lg max-w-md w-full p-5">
        <h3 class="text-lg font-semibold mb-3">{{ unitDraft._new ? t('codebooks.new_unit') : unitDraft.code }}</h3>
        <div class="space-y-3">
          <div>
            <label class="block text-sm font-medium mb-1">{{ t('codebooks.code') }} *</label>
            <input v-model="unitDraft.code" :disabled="!unitDraft._new" type="text" maxlength="20" placeholder="ks"
              class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono disabled:bg-neutral-50" />
          </div>
          <div class="grid grid-cols-2 gap-3">
            <div><label class="block text-sm font-medium mb-1">{{ t('codebooks.name_cs') }}</label>
              <input v-model="unitDraft.label_cs" type="text" placeholder="kus" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" /></div>
            <div><label class="block text-sm font-medium mb-1">{{ t('codebooks.name_en') }}</label>
              <input v-model="unitDraft.label_en" type="text" placeholder="piece" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" /></div>
          </div>
          <div>
            <label class="block text-sm font-medium mb-1">{{ t('codebooks.display_order') }}</label>
            <input v-model.number="unitDraft.display_order" type="number" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
          </div>
          <label class="flex items-center gap-2 text-sm">
            <input v-model="unitDraft.is_default" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
            {{ t('codebooks.is_default_unit_hint') }}
          </label>
          <div class="flex justify-end gap-2 pt-2">
            <button @click="unitOpen = false" :class="btnOutline('neutral')">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>
              {{ t('common.cancel') }}</button>
            <button @click="saveUnit" :class="btnFilled('primary')">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
              {{ t('common.save') }}</button>
          </div>
        </div>
      </div>
    </div>

    <div v-if="countryOpen" class="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
      <div class="bg-surface rounded-xl shadow-lg max-w-md w-full p-5">
        <h3 class="text-lg font-semibold mb-3">{{ countryDraft._new ? t('codebooks.new_country') : countryDraft.iso2 }}</h3>
        <div class="space-y-3">
          <div class="grid grid-cols-2 gap-3">
            <div><label class="block text-sm font-medium mb-1">{{ t('codebooks.iso2') }} *</label>
              <input v-model="countryDraft.iso2" :disabled="!countryDraft._new" type="text" maxlength="2" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono uppercase disabled:bg-neutral-50" /></div>
            <div><label class="block text-sm font-medium mb-1">{{ t('codebooks.iso3') }}</label>
              <input v-model="countryDraft.iso3" type="text" maxlength="3" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono uppercase" /></div>
          </div>
          <div><label class="block text-sm font-medium mb-1">{{ t('codebooks.name_cs') }}</label>
            <input v-model="countryDraft.name_cs" type="text" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" /></div>
          <div><label class="block text-sm font-medium mb-1">{{ t('codebooks.name_en') }}</label>
            <input v-model="countryDraft.name_en" type="text" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" /></div>
          <label class="flex items-center gap-2 text-sm">
            <input v-model="countryDraft.is_eu" type="checkbox" class="rounded border-neutral-300 text-primary-600" /> {{ t('codebooks.is_eu_label') }}
          </label>
          <div class="flex justify-end gap-2 pt-2">
            <button @click="countryOpen = false" :class="btnOutline('neutral')">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>
              {{ t('common.cancel') }}</button>
            <button @click="saveCountry" :class="btnFilled('primary')">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
              {{ t('common.save') }}</button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

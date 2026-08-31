<script setup lang="ts">
import { ref, reactive, onMounted, watch, computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { taxReturnApi, type TaxpayerType, type TaxReturnVariant, type TaxReturnState, type InsuranceSummary, type AdvanceSchedule, type AdvanceOverride, type AdvancePeriodicity, type AdvanceKind, type TaxReturnProjection, type TaxReturnAddbackSuggestion, type TaxReturnDeductionSuggestion, type ReconcileResult, type TaxReturnBankAccount } from '@/api/taxReturn'
import { apiErrorMessage } from '@/api/errors'
import { formatMoney, formatDate } from '@/composables/useFormat'
import { useYearOptions } from '@/composables/useYearOptions'
import { accountingApi, type AccountingPeriod } from '@/api/accounting'
import { useSupplierStore } from '@/stores/supplier'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import { useAuthStore } from '@/stores/auth'
import EmptyState from '@/components/ui/EmptyState.vue'
import { taxApi, type TaxProfile, type TaxActivity, type TaxChild, type SpouseClaim, type OsvcMonth } from '@/api/tax'
import { taxEvidenceApi, type TaxEvidenceClosing, type TaxEvidenceAdjustment } from '@/api/taxEvidence'
import { downloadApiFile } from '@/utils/downloadFile'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const supplierStore = useSupplierStore()
const auth = useAuthStore()

const now = new Date()
const year = ref(now.getFullYear() - 1)
const yearOptions = useYearOptions('combined', year)

// Účetní období (pro DPPO nabízíme reálná období vč. hospodářského roku, label 2025/2026).
// Rozlišovacím znakem hospodářského roku (§3/2 ZoÚ) není „začíná 1. ledna?", ale zda období
// končí 31. 12.: hospodářský rok začíná 1. dnem měsíce ≠ leden, takže vždy končí posledním dnem
// předcházejícího měsíce, nikdy 31. 12. Zkrácené první období (vznik firmy → 31. 12.) je naopak
// pořád kalendářní rok, jen kratší. Shodné kritérium jako back-end — FiscalCalendar::isFiscalYearShape()
// a TaxReturnService (typ_zo='a').
const periods = ref<AccountingPeriod[]>([])
function periodLabel(p: AccountingPeriod): string {
  const isCalendar = p.ends_on.slice(5) === '12-31'
  return isCalendar ? String(p.fiscal_year) : `${p.fiscal_year}/${p.fiscal_year + 1}`
}
const yearSelectOptions = computed<{ value: number; label: string }[]>(() => {
  if (type.value === 'po' && periods.value.length > 0) {
    return [...periods.value]
      .sort((a, b) => b.fiscal_year - a.fiscal_year)
      .map(p => ({ value: p.fiscal_year, label: periodLabel(p) }))
  }
  return yearOptions.value.map(y => ({ value: y, label: String(y) }))
})

const supplierType: TaxpayerType = supplierStore.currentSupplier?.taxpayer_type === 'po' ? 'po' : 'fo'
const type = ref<TaxpayerType>(supplierType)
const variant = ref<TaxReturnVariant>('radne')
// Pořadí dodatečného přiznání (E8): 0 = auto (poslední existující / založí č. 1).
const dodatecneSeq = ref(0)
function seqParam(): number | undefined {
  return variant.value === 'dodatecne' ? dodatecneSeq.value : undefined
}

const state = ref<TaxReturnState | null>(null)
const loading = ref(false)

// #13: zdaňovací období, za které se náhled skutečně počítá (starts_on..ends_on z podkladů).
// Výběr roku ukazuje jen „2025", ale VH se sčítá za konkrétní účetní období — u firmy
// vzniklé v průběhu roku (zkrácené první období) nebo hospodářského roku by se rok v hlavě
// dal snadno splést s jiným obdobím. Datumový rozsah dělá zobrazený rok jednoznačným.
const podkladyPeriod = computed<{ starts_on: string; ends_on: string } | null>(() => {
  const p = (state.value?.podklady as Record<string, unknown> | undefined)?.period as
    { starts_on: string; ends_on: string } | null | undefined
  return p ?? null
})
const saving = ref(false)
const error = ref('')

// Editovatelná kopie ručních vstupů (draft) + dirty tracking.
const inputs = reactive<Record<string, any>>({})
const dirty = ref(false)
const profile = reactive<Partial<TaxProfile>>({})
const profileDirty = ref(false)
const closing = ref<TaxEvidenceClosing | null>(null)
const closingBusy = ref(false)
const closingChecks = ['cash_journal_reviewed', 'non_cash_reviewed', 'fixed_assets_inventoried',
  'inventory_inventoried', 'receivables_inventoried', 'liabilities_inventoried',
  'high_value_purchases_reviewed', 'transition_reviewed', 'foreign_exchange_reviewed']
const closingBalanceKeys = ['fixed_assets', 'cash', 'bank', 'inventory', 'receivables',
  'other_assets', 'liabilities', 'reserves', 'depreciation']

// Zálohy a rozhodnutí FÚ mají vlastní záložky — schované v Exportu je nikdo nenašel.
const TABS = ['podklady', 'upravy', 'nahled', 'export', 'zalohy', 'rozhodnuti'] as const
type TabKey = typeof TABS[number]
function normalizeTab(v: unknown): TabKey {
  return TABS.includes(v as TabKey) ? (v as TabKey) : 'podklady'
}
const activeTab = ref<TabKey>(normalizeTab(route.query.tab))
watch(() => route.query.tab, v => { activeTab.value = normalizeTab(v) })
// Rozhodnutí FÚ o zálohách podle §174 DŘ je jen věc právnických osob.
const visibleTabs = computed<readonly TabKey[]>(() =>
  type.value === 'po' ? TABS : TABS.filter(k => k !== 'rozhodnuti'),
)

function switchTab(tab: TabKey) {
  router.replace({ query: { ...route.query, tab } })
}

const isFinal = computed(() => state.value?.return.status === 'final')
const computed_ = computed(() => state.value?.computed)
const warnings = computed(() => state.value?.warnings ?? [])
const taxLosses = computed(() => (state.value as any)?.tax_losses ?? { losses: [], available_total: 0, suggested: 0, carry_years: 5 })

// Feature 1 — projekce závěrkových operací do VH (jen DPPO; null, když nic nezaúčtovaného nečeká).
const projection = computed<TaxReturnProjection | null>(() => (computed_.value as any)?.projection ?? null)
// Feature 2 — auto-návrhy připočitatelných / odečitatelných položek k ověření účetní.
const addbackSuggestions = computed<TaxReturnAddbackSuggestion[]>(() =>
  ((state.value?.podklady as any)?.suggestions?.addbacks as TaxReturnAddbackSuggestion[]) ?? [])
const deductionSuggestions = computed<TaxReturnDeductionSuggestion[]>(() =>
  ((state.value?.podklady as any)?.suggestions?.deductions as TaxReturnDeductionSuggestion[]) ?? [])
const hasSuggestions = computed(() =>
  type.value === 'po' && (addbackSuggestions.value.length > 0 || deductionSuggestions.value.length > 0 || (taxLosses.value.suggested ?? 0) > 0))

// Volba bankovního účtu pro vrácení přeplatku (VetaNP) — appka dřív vybírala za
// poplatníka (první aktivní CZK účet); `bank_accounts` je nabídka, `bank_account` je
// efektivně použitý účet (výchozí, nebo dřívější `inputs.bank_account_id`).
const bankAccountOptions = computed<TaxReturnBankAccount[]>(() =>
  ((state.value?.podklady as any)?.bank_accounts as TaxReturnBankAccount[]) ?? [])
const effectiveBankAccount = computed<TaxReturnBankAccount | null>(() =>
  ((state.value?.podklady as any)?.bank_account as TaxReturnBankAccount | null) ?? null)
function bankAccountLabel(acc: TaxReturnBankAccount): string {
  const parts = [acc.account_number, acc.bank_code].filter(Boolean).join('/')
  return acc.bank_name ? `${parts} — ${acc.bank_name}` : parts
}

// E10 — předfinalizační kontrolní checklist.
const prefinalize = computed(() => state.value?.prefinalize_check ?? null)
function checkTone(c: { ok: boolean; severity: string; na?: boolean }): string {
  if (c.na) return 'bg-neutral-50 text-neutral-500 border-neutral-200'
  if (c.ok) return 'bg-success-50 text-success-700 border-success-500/30'
  if (c.severity === 'blocker') return 'bg-danger-50 text-danger-600 border-danger-500/40'
  return 'bg-warning-50 text-warning-700 border-warning-500/40'
}
function checkStatusLabel(c: { ok: boolean; severity: string; na?: boolean }): string {
  if (c.na) return t('taxReturn.check_na')
  if (c.ok) return t('taxReturn.check_ok')
  return c.severity === 'blocker' ? t('taxReturn.check_blocker') : t('taxReturn.check_warning')
}

function applyLossSuggestion() {
  if (isFinal.value) return
  inputs.loss_carryforward = taxLosses.value.suggested || 0
  dirty.value = true
}

function blankInputs(): Record<string, any> {
  const common = { last_known_tax: null, d_zjist: '', amend_reason: '' }
  if (type.value === 'po') {
    return {
      manual_increase_items: [], manual_decrease_items: [], donation_items: [],
      loss_carryforward: 0, donations: 0,
      // § 34/4 — odečty na VaV (ř. 242) a odborné vzdělávání (ř. 243).
      rnd_deduction: 0, education_deduction: 0,
      disabled_employees_avg: 0, disabled_employees_severe_avg: 0,
      tax_paid_advances: 0, filing_deadline: '', notes: '',
      // Volba účtu pro vrácení přeplatku (null = automaticky, viz bankAccountOptions)
      // a žádost o předání Přílohy do sbírky listin (výchozí ANO, lze vypnout).
      bank_account_id: null, puz_to_registry: true,
      ...common,
    }
  }
  return {
    s6_employment: { income: 0, withholding: 0 },
    s8_capital: { base: 0 },
    s9_rental: { income: 0, expenses: 0, expense_mode: 'actual' },
    s10_other: { income: 0, expenses: 0 },
    // § 16a — samostatný základ daně (zahraniční podíly na zisku, sazba 15 %).
    s16a_separate_base: 0,
    social_paid_advances: 0, health_paid_advances: 0,
    loss_carryforward: 0,
    tax_paid_advances: 0, notes: '',
    ...common,
  }
}

function hydrateInputs(loaded: Record<string, unknown>, suggestedTax: number | null = null) {
  const base = blankInputs()
  const merged: Record<string, any> = { ...base }
  for (const k of Object.keys(base)) {
    if (loaded[k] !== undefined && loaded[k] !== null) merged[k] = loaded[k]
  }
  if (variant.value === 'dodatecne' && merged.last_known_tax == null && suggestedTax != null) {
    merged.last_known_tax = suggestedTax
  }
  for (const k of Object.keys(inputs)) delete inputs[k]
  Object.assign(inputs, merged)
  dirty.value = false
}

function hydrateProfile(loaded: Record<string, any>) {
  for (const key of Object.keys(profile)) delete (profile as any)[key]
  Object.assign(profile, loaded)
  if (!Array.isArray(profile.activities)) profile.activities = []
  if (!Array.isArray(profile.children)) profile.children = []
  if (!Array.isArray(profile.osvc_months) || profile.osvc_months.length === 0) {
    profile.osvc_months = Array.from({ length: 12 }, (_, i): OsvcMonth => ({
      month: i + 1, activity_status: 'main', social_participates: true,
      health_minimum_applies: true, state_insured: false, employed: false, new_osvc: false,
    }))
  }
  profileDirty.value = false
}

async function loadClosing() {
  if (type.value !== 'fo' || state.value?.podklady.accounting_mode !== 'tax_evidence') { closing.value = null; return }
  try { closing.value = await taxEvidenceApi.closing(year.value) } catch { closing.value = null }
}

async function load() {
  loading.value = true
  error.value = ''
  try {
    const s = await taxReturnApi.get(type.value, year.value, variant.value, seqParam())
    state.value = s
    // Sync skutečně načteného pořadí dodatečného (bez retriggeru — ref není watchovaný).
    if (variant.value === 'dodatecne') dodatecneSeq.value = s.return.variant_seq
    hydrateInputs(s.return.inputs, s.last_known_tax_suggested)
    hydrateProfile((s.podklady.profile as Record<string, any>) ?? {})
    await loadClosing()
  } catch (e) {
    error.value = apiErrorMessage(e)
    state.value = null
  } finally {
    loading.value = false
  }
}

// Existující dodatečná přiznání za období (pro sub-výběr č. N + časovou osu).
const dodatecneList = computed(() =>
  (state.value?.available_variants ?? []).filter(v => v.variant === 'dodatecne')
    .sort((a, b) => a.variant_seq - b.variant_seq))
const maxDodatecneSeq = computed(() => dodatecneList.value.reduce((m, v) => Math.max(m, v.variant_seq), 0))

function selectVariant(v: TaxReturnVariant) {
  if (v === 'dodatecne') dodatecneSeq.value = 0 // auto → poslední existující / č. 1
  variant.value = v
}
async function selectDodatecne(seq: number) {
  dodatecneSeq.value = seq
  await load()
}
async function newDodatecne() {
  dodatecneSeq.value = maxDodatecneSeq.value + 1
  await load()
}

async function save() {
  if (!state.value || isFinal.value) return
  saving.value = true
  error.value = ''
  try {
    if (type.value === 'fo' && profileDirty.value) {
      const savedProfile = await taxApi.saveProfile({ ...(profile as TaxProfile), year: year.value })
      hydrateProfile(savedProfile as unknown as Record<string, any>)
    }
    const s = await taxReturnApi.saveInputs(type.value, year.value, { ...inputs }, state.value.return.row_version, variant.value, seqParam())
    state.value = s
    hydrateInputs(s.return.inputs, s.last_known_tax_suggested)
  } catch (e: any) {
    error.value = apiErrorMessage(e)
    if (e?.response?.status === 409) await load()
  } finally {
    saving.value = false
  }
}

async function finalize() {
  if (!state.value) return
  if (dirty.value) await save()
  if (error.value) return
  saving.value = true
  try {
    state.value = await taxReturnApi.finalize(type.value, year.value, state.value!.return.row_version, variant.value, seqParam())
    hydrateInputs(state.value.return.inputs, state.value.last_known_tax_suggested)
  } catch (e: any) {
    error.value = apiErrorMessage(e)
    if (e?.response?.status === 409) await load()
  } finally {
    saving.value = false
  }
}

async function reopen() {
  if (!state.value) return
  saving.value = true
  try {
    state.value = await taxReturnApi.reopen(type.value, year.value, state.value.return.row_version, variant.value, seqParam())
    hydrateInputs(state.value.return.inputs, state.value.last_known_tax_suggested)
  } catch (e: any) {
    error.value = apiErrorMessage(e)
    if (e?.response?.status === 409) await load()
  } finally {
    saving.value = false
  }
}

async function downloadXml() {
  const url = !isFinal.value && type.value === 'fo'
    ? taxReturnApi.previewXmlUrl(year.value, variant.value, seqParam())
    : taxReturnApi.xmlUrl(type.value, year.value, variant.value, seqParam())
  try {
    await downloadApiFile(url)
    if (type.value === 'po' || isFinal.value) {
      await router.push('/reports/submissions')
    }
  } catch (e) {
    error.value = apiErrorMessage(e)
  }
}

function addActivity() {
  profile.activities!.push({ name: '', nace_code: '', expense_mode: 'pausal', expense_rate: 60,
    income: 0, expenses: 0, active_months: 12 } as TaxActivity)
}
function addChild() {
  profile.children!.push({ first_name: '', last_name: '', birth_number: '', birth_date: null,
    shared_household_proved: false, other_parent_not_claimed_proved: false,
    months: Array.from({ length: 12 }, (_, i) => ({ month: i + 1, order: 1, ztpp: false, claimed: true })) } as TaxChild)
}
function enableSpouse() {
  profile.spouse_claim = { first_name: '', last_name: '', birth_number: '', birth_date: null,
    eligible_months: 12, ztpp: false, own_income: 0, income_proved: false,
    shared_household_proved: false, child_under_three_proved: false } as SpouseClaim
}
function addS10Item() {
  if (!Array.isArray(inputs.s10_items)) inputs.s10_items = []
  inputs.s10_items.push({ text: '', income: 0, expenses: 0 })
}
function addClosingAdjustment() {
  closing.value?.adjustments.push({ adjustment_on: `${year.value}-12-31`, kind: 'section23_other',
    direction: 'neutral', amount: 0, description: '' } as TaxEvidenceAdjustment)
}
async function saveClosing() {
  if (!closing.value) return
  closingBusy.value = true
  try { closing.value = await taxEvidenceApi.saveClosing(year.value, closing.value, closing.value.row_version); await load() }
  catch (e) { error.value = apiErrorMessage(e) } finally { closingBusy.value = false }
}
async function finalizeClosing() {
  if (!closing.value) return
  closingBusy.value = true
  try { closing.value = await taxEvidenceApi.finalizeClosing(year.value, closing.value.row_version); await load() }
  catch (e) { error.value = apiErrorMessage(e) } finally { closingBusy.value = false }
}
async function reopenClosing() {
  if (!closing.value) return
  closingBusy.value = true
  try { closing.value = await taxEvidenceApi.reopenClosing(year.value, closing.value.row_version); await load() }
  catch (e) { error.value = apiErrorMessage(e) } finally { closingBusy.value = false }
}

// ── Pojistné (FO) ──────────────────────────────────────────────────────────
const insurance = ref<InsuranceSummary | null>(null)
const insuranceLoading = ref(false)
async function loadInsurance() {
  if (type.value !== 'fo') return
  insuranceLoading.value = true
  try {
    insurance.value = await taxReturnApi.insurance(year.value)
  } catch { insurance.value = null } finally { insuranceLoading.value = false }
}
function downloadInsurancePdf() {
  window.open(taxReturnApi.insurancePdfUrl(year.value), '_blank')
}
function downloadCsszXml() {
  window.open(taxReturnApi.csszXmlUrl(year.value), '_blank')
}
function downloadHealthPdf() {
  window.open(taxReturnApi.healthPdfUrl(year.value), '_blank')
}

// ── Zálohy na daň a pojistné (E9) ──────────────────────────────────────────
const advances = ref<AdvanceSchedule[]>([])
const advancesLoading = ref(false)
const advancesBusy = ref(false)
const advancesMsg = ref('')
async function loadAdvances() {
  advancesLoading.value = true
  advancesMsg.value = ''
  try {
    const res = await taxReturnApi.advances(type.value, year.value)
    advances.value = res.schedules
    await loadOverridesOverview()
  } catch { advances.value = [] } finally { advancesLoading.value = false }
}
async function generateAdvances() {
  advancesBusy.value = true
  advancesMsg.value = ''
  try {
    await taxReturnApi.generateAdvances(type.value, year.value)
    await loadAdvances()
  } catch (e) { advancesMsg.value = (e as Error).message } finally { advancesBusy.value = false }
}
async function matchAdvances() {
  advancesBusy.value = true
  advancesMsg.value = ''
  try {
    const res = await taxReturnApi.matchAdvances(type.value, year.value)
    const parts = [t('taxReturn.advances_matched', { n: res.matched })]
    // F3: existující ruční hodnota se NEpřepisuje — návrh z banky se jen nabídne k ověření.
    if (res.skipped_existing.length) {
      const suggestions = res.skipped_existing
        .map(k => `${advanceKindLabel(k)}: ${formatMoney(res.totals[k], 'CZK')}`)
        .join(', ')
      parts.push(t('taxReturn.advances_suggestion_kept', { list: suggestions }))
    }
    if (res.details.some(d => d.amount_mismatch)) {
      parts.push(t('taxReturn.advances_amount_mismatch'))
    }
    advancesMsg.value = parts.join(' ')
    await loadAdvances()
    if (res.return_prefilled) await load()
  } catch (e) { advancesMsg.value = (e as Error).message } finally { advancesBusy.value = false }
}
function advanceKindLabel(k: string): string {
  return t(`taxReturn.advance_kind_${k}`)
}

// ── #43/#46 — rozhodnutí FÚ o výši záloh (§174) s rozsahem OD-DO, GLOBÁLNĚ napříč roky ──
const overridesList = ref<AdvanceOverride[]>([])
const allSchedules = ref<AdvanceSchedule[]>([])
const overridesLoading = ref(false)
const overridesBusy = ref(false)
const overridesMsg = ref('')
// null = žádná editace; 0 = zakládá se nové rozhodnutí; >0 = upravuje se existující dle id.
const editingOverrideId = ref<number | null>(null)
const overrideForm = reactive<{ amount: number; periodicity: AdvancePeriodicity; effective_from: string; effective_to: string; note: string }>({
  amount: 0, periodicity: 'quarterly', effective_from: '', effective_to: '', note: '',
})
async function loadOverridesOverview() {
  if (type.value !== 'po') { overridesList.value = []; allSchedules.value = []; return }
  overridesLoading.value = true
  try {
    const res = await taxReturnApi.advanceOverrides(type.value, year.value)
    overridesList.value = res.overrides
    allSchedules.value = res.schedules
  } catch { overridesList.value = []; allSchedules.value = [] } finally { overridesLoading.value = false }
}
function openNewOverride() {
  editingOverrideId.value = 0
  overrideForm.amount = 0
  overrideForm.periodicity = 'quarterly'
  overrideForm.effective_from = `${year.value}-01-01`
  overrideForm.effective_to = ''
  overrideForm.note = ''
}
function openEditOverride(o: AdvanceOverride) {
  editingOverrideId.value = o.id
  overrideForm.amount = o.amount
  overrideForm.periodicity = o.periodicity
  overrideForm.effective_from = o.effective_from
  overrideForm.effective_to = o.effective_to ?? ''
  overrideForm.note = o.note ?? ''
}
async function saveOverrideEntry() {
  overridesBusy.value = true
  overridesMsg.value = ''
  try {
    const body = {
      amount: overrideForm.amount,
      periodicity: overrideForm.periodicity,
      effective_from: overrideForm.effective_from,
      effective_to: overrideForm.effective_to || null,
      note: overrideForm.note,
      source: 'fu_decision' as const,
    }
    const res = editingOverrideId.value && editingOverrideId.value > 0
      ? await taxReturnApi.updateAdvanceOverrideEntry(type.value, year.value, editingOverrideId.value, body)
      : await taxReturnApi.createAdvanceOverride(type.value, year.value, body)
    overridesList.value = res.overrides
    allSchedules.value = res.schedules
    editingOverrideId.value = null
    overridesMsg.value = t('taxReturn.advances_override_saved')
    await loadAdvances()
  } catch (e) { overridesMsg.value = apiErrorMessage(e) } finally { overridesBusy.value = false }
}
async function deleteOverrideEntry(o: AdvanceOverride) {
  overridesBusy.value = true
  overridesMsg.value = ''
  try {
    const res = await taxReturnApi.deleteAdvanceOverrideEntry(type.value, year.value, o.id)
    overridesList.value = res.overrides
    allSchedules.value = res.schedules
    overridesMsg.value = t('taxReturn.advances_override_deleted')
    await loadAdvances()
  } catch (e) { overridesMsg.value = apiErrorMessage(e) } finally { overridesBusy.value = false }
}
function scheduleStatusLabel(a: AdvanceSchedule): string {
  if (a.status === 'paid') return t('taxReturn.advance_paid_status')
  if (a.is_overdue) return t('taxReturn.advance_overdue')
  return t('taxReturn.advance_planned')
}

// #42 — vygenerovat předpisy PRO tento rok (z draftu min. roku / z rozhodnutí FÚ).
async function generateAdvancesForPeriod() {
  advancesBusy.value = true
  advancesMsg.value = ''
  try {
    await taxReturnApi.generateAdvancesForPeriod(type.value, year.value)
    await loadAdvances()
    advancesMsg.value = t('taxReturn.advances_generated_period')
  } catch (e) { advancesMsg.value = apiErrorMessage(e) } finally { advancesBusy.value = false }
}

// Ruční úprava výše + potvrzení úhrad.
const editingAmountId = ref<number | null>(null)
const editAmountValue = ref(0)
function startEditAmount(a: AdvanceSchedule) {
  editingAmountId.value = a.id
  editAmountValue.value = a.amount
}
async function saveEditAmount(a: AdvanceSchedule) {
  advancesBusy.value = true
  try {
    const res = await taxReturnApi.updateAdvanceAmount(type.value, year.value, a.id, editAmountValue.value)
    advances.value = res.schedules
    editingAmountId.value = null
  } catch (e) { advancesMsg.value = apiErrorMessage(e) } finally { advancesBusy.value = false }
}
async function confirmAdvance(a: AdvanceSchedule) {
  advancesBusy.value = true
  advancesMsg.value = ''
  try {
    const res = await taxReturnApi.confirmAdvance(type.value, year.value, a.id)
    advances.value = res.schedules
    if (res.return_prefilled) await load()
  } catch (e) { advancesMsg.value = apiErrorMessage(e) } finally { advancesBusy.value = false }
}
async function unconfirmAdvance(a: AdvanceSchedule) {
  advancesBusy.value = true
  try {
    const res = await taxReturnApi.unconfirmAdvance(type.value, year.value, a.id)
    advances.value = res.schedules
  } catch (e) { advancesMsg.value = apiErrorMessage(e) } finally { advancesBusy.value = false }
}
async function confirmAllAdvances() {
  advancesBusy.value = true
  advancesMsg.value = ''
  try {
    const kind: AdvanceKind | undefined = type.value === 'po' ? 'tax' : undefined
    const res = await taxReturnApi.confirmAllAdvances(type.value, year.value, kind)
    advances.value = res.schedules
    advancesMsg.value = t('taxReturn.advances_confirmed_all', { n: res.confirmed })
    if (res.return_prefilled) await load()
  } catch (e) { advancesMsg.value = apiErrorMessage(e) } finally { advancesBusy.value = false }
}

// ── Featura A — rekonciliace proti PODANÉMU přiznání (upload EPO XML DPPDP9) ──
const reconcileFile = ref<File | null>(null)
const reconcileResult = ref<ReconcileResult | null>(null)
const reconcileLoading = ref(false)
const reconcileError = ref('')
function onReconcileFileChange(e: Event) {
  const input = e.target as HTMLInputElement
  reconcileFile.value = input.files?.[0] ?? null
}
async function runReconcile() {
  if (!reconcileFile.value) return
  reconcileLoading.value = true
  reconcileError.value = ''
  try {
    reconcileResult.value = await taxReturnApi.reconcile(year.value, reconcileFile.value, variant.value, seqParam())
  } catch (e) {
    reconcileError.value = apiErrorMessage(e)
    reconcileResult.value = null
  } finally {
    reconcileLoading.value = false
  }
}
function extraEntries(extra: Record<string, { value: number; label: string }>): Array<{ key: string; value: number; label: string }> {
  return Object.entries(extra).map(([key, v]) => ({ key, value: v.value, label: v.label }))
}

// Sledování změn ve vstupech → dirty.
watch(inputs, () => { if (state.value) dirty.value = true }, { deep: true })
watch(profile, () => { if (state.value) profileDirty.value = true }, { deep: true })
// Když aktuální rok není mezi nabízenými obdobími (DPPO hospodářský rok), přepni na nejnovější.
watch(yearSelectOptions, (opts) => {
  if (opts.length > 0 && !opts.some(o => o.value === year.value)) year.value = opts[0].value
})
watch([type, year, variant], async () => { insurance.value = null; reconcileResult.value = null; reconcileError.value = ''; await load(); await loadOverridesOverview() })
onMounted(async () => {
  try { periods.value = await accountingApi.listPeriods() } catch { /* účetní období nemusí být dostupná (tax_evidence) */ }
  await load()
  await loadOverridesOverview()
})

type ItemList = 'manual_increase_items' | 'manual_decrease_items' | 'donation_items'
function addItem(list: ItemList) {
  if (!Array.isArray(inputs[list])) inputs[list] = []
  // `kind` nese explicitní druh položky §23 (paušál na dopravu §24/2/zt); u darů se nepoužívá.
  inputs[list].push(list === 'donation_items' ? { text: '', amount: 0 } : { text: '', amount: 0, kind: '' })
}
function removeItem(list: ItemList, i: number | string) {
  inputs[list].splice(Number(i), 1)
}

const actions = computed<ActionItem[]>(() => {
  const a: ActionItem[] = []
  if (!isFinal.value) {
    a.push({ key: 'save', label: t('taxReturn.save'), icon: 'check', tier: 'primary', variant: 'primary',
      show: auth.canWrite('reports'), disabled: (!dirty.value && !profileDirty.value) || saving.value, loading: saving.value, run: save })
    a.push({ key: 'finalize', label: t('taxReturn.finalize'), icon: 'clipboardCheck', tier: 'secondary', variant: 'success',
      show: auth.canWrite('reports.finalize'), disabled: saving.value || prefinalize.value?.can_finalize === false, run: finalize })
  } else {
    a.push({ key: 'reopen', label: t('taxReturn.reopen'), icon: 'doc', tier: 'secondary', variant: 'warning',
      show: auth.canWrite('reports.reopen'), disabled: saving.value, run: reopen })
  }
  a.push({ key: 'xml', label: t(isFinal.value ? 'taxReturn.download_xml' : 'taxReturn.preview_xml'), icon: 'download', tier: 'secondary', variant: 'neutral', show: auth.canRead('reports.export'), run: downloadXml })
  return a
})

function tabLabel(k: TabKey): string { return t('taxReturn.tab_' + k) }
</script>

<template>
  <div class="max-w-5xl">
    <!-- Topbar -->
    <div class="flex items-center justify-between mb-4 gap-3 flex-wrap">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('taxReturn.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5">{{ t('taxReturn.subtitle') }}</p>
      </div>
      <div class="flex items-center gap-2">
        <div class="inline-flex rounded-md border border-neutral-300 overflow-hidden text-sm">
          <button type="button" @click="type = 'fo'" :disabled="supplierType !== 'fo'" :class="type === 'fo' ? 'bg-primary-600 text-white' : 'bg-surface text-neutral-700'" class="px-3 h-9 disabled:opacity-40">DPFO</button>
          <button type="button" @click="type = 'po'" :disabled="supplierType !== 'po'" :class="type === 'po' ? 'bg-primary-600 text-white' : 'bg-surface text-neutral-700'" class="px-3 h-9 border-l border-neutral-300 disabled:opacity-40">DPPO</button>
        </div>
        <div class="inline-flex rounded-md border border-neutral-300 overflow-hidden text-sm">
          <button type="button" @click="selectVariant('radne')" :class="variant === 'radne' ? 'bg-primary-600 text-white' : 'bg-surface text-neutral-700'" class="px-3 h-9">{{ t('taxReturn.variant_radne') }}</button>
          <button type="button" @click="selectVariant('opravne')" :class="variant === 'opravne' ? 'bg-primary-600 text-white' : 'bg-surface text-neutral-700'" class="px-3 h-9 border-l border-neutral-300">{{ t('taxReturn.variant_opravne') }}</button>
          <button type="button" @click="selectVariant('dodatecne')" :class="variant === 'dodatecne' ? 'bg-primary-600 text-white' : 'bg-surface text-neutral-700'" class="px-3 h-9 border-l border-neutral-300">{{ t('taxReturn.variant_dodatecne') }}</button>
        </div>
        <select v-model.number="year" class="h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
          <option v-for="o in yearSelectOptions" :key="o.value" :value="o.value">{{ o.label }}</option>
        </select>
        <span v-if="podkladyPeriod" class="px-3 h-9 inline-flex items-center rounded-md text-xs text-neutral-600 bg-neutral-100 dark:bg-neutral-800 dark:text-neutral-300 whitespace-nowrap"
          :title="t('taxReturn.period_range_hint')">
          {{ formatDate(podkladyPeriod.starts_on) }} – {{ formatDate(podkladyPeriod.ends_on) }}
        </span>
        <span v-if="state" class="px-3 h-9 inline-flex items-center rounded-md text-xs font-semibold"
          :class="isFinal ? 'bg-success-100 text-success-700 dark:bg-success-500/15 dark:text-success-300' : 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300'">
          {{ isFinal ? t('taxReturn.status_final') : t('taxReturn.status_draft') }}
        </span>
      </div>
    </div>

    <!-- Dodatečné přiznání: výběr pořadí č. N + časová osa podání za období (E8) -->
    <div v-if="state && variant === 'dodatecne'" class="bg-surface border border-neutral-200 rounded-lg p-3 mb-4">
      <div class="flex items-center gap-2 flex-wrap">
        <span class="text-xs uppercase text-neutral-500 mr-1">{{ t('taxReturn.dodatecne_seq_label') }}</span>
        <button v-for="d in dodatecneList" :key="d.variant_seq" type="button" @click="selectDodatecne(d.variant_seq)"
          class="px-2.5 h-8 rounded-md text-sm border whitespace-nowrap"
          :class="d.variant_seq === (state.return.variant_seq) ? 'bg-primary-600 text-white border-primary-600' : 'bg-surface text-neutral-700 border-neutral-300'">
          {{ t('taxReturn.dodatecne_nth', { n: d.variant_seq }) }}
          <span v-if="d.status === 'final'" class="ml-1 text-[10px] opacity-80">✓</span>
        </button>
        <button type="button" @click="newDodatecne" class="px-2.5 h-8 rounded-md text-sm border border-dashed border-primary-400 text-primary-600 whitespace-nowrap">
          + {{ t('taxReturn.dodatecne_new') }}
        </button>
      </div>
      <ol v-if="dodatecneList.length" class="mt-3 border-t border-neutral-100 pt-2 space-y-1 text-xs text-neutral-500">
        <li v-for="d in dodatecneList" :key="'tl'+d.variant_seq" class="flex items-center gap-2 flex-wrap">
          <span class="font-mono">{{ t('taxReturn.dodatecne_nth', { n: d.variant_seq }) }}</span>
          <span class="px-1.5 py-0.5 rounded" :class="d.status === 'final' ? 'bg-success-50 text-success-700' : 'bg-amber-100 text-amber-700'">
            {{ d.status === 'final' ? t('taxReturn.status_final') : t('taxReturn.status_draft') }}
          </span>
          <span v-if="d.submitted_at">· {{ t('taxReturn.dodatecne_submitted_at') }}: {{ d.submitted_at }}</span>
          <span v-else-if="d.updated_at">· {{ t('taxReturn.dodatecne_updated_at') }}: {{ d.updated_at }}</span>
        </li>
      </ol>
    </div>

    <ActionBar v-if="state" :actions="actions" class="mb-4" />

    <!-- E10 — předfinalizační kontrolní checklist („závěrková kontrola účetní") -->
    <div v-if="state && prefinalize" class="bg-surface border rounded-lg p-4 mb-4"
      :class="prefinalize.summary.blocker > 0 ? 'border-danger-500/40' : (prefinalize.summary.warning > 0 ? 'border-warning-500/40' : 'border-neutral-200')">
      <div class="flex items-center justify-between mb-3 flex-wrap gap-2">
        <span class="text-sm font-semibold">{{ t('taxReturn.prefinalize_title') }}</span>
        <span class="text-xs">
          <span class="text-success-700">{{ prefinalize.summary.ok }} {{ t('taxReturn.check_ok') }}</span>
          <span v-if="prefinalize.summary.warning" class="text-warning-700 ml-2">{{ prefinalize.summary.warning }} {{ t('taxReturn.check_warning') }}</span>
          <span v-if="prefinalize.summary.blocker" class="text-danger-600 ml-2">{{ prefinalize.summary.blocker }} {{ t('taxReturn.check_blocker') }}</span>
        </span>
      </div>
      <ul class="space-y-2">
        <li v-for="c in prefinalize.checks" :key="c.key" class="border rounded-md p-2.5 text-sm" :class="checkTone(c)">
          <div class="flex items-center justify-between gap-2">
            <span class="font-medium">{{ t('taxReturn.check_' + c.key) }}</span>
            <span class="text-xs font-semibold uppercase">{{ checkStatusLabel(c) }}</span>
          </div>
          <div class="text-xs mt-1 opacity-90">
            <template v-if="c.key === 'period_status'">
              {{ t('taxReturn.check_period_' + ((c.value?.status as string) || 'na')) }}
            </template>
            <template v-else-if="c.key === 'depreciation_551' && !c.na">
              551: {{ formatMoney(Number(c.value?.turnover ?? 0), 'CZK') }} · {{ t('taxReturn.check_dep_entries') }}: {{ formatMoney(Number(c.value?.accounting_entries ?? 0), 'CZK') }}
              <span v-if="!c.ok"> · {{ t('taxReturn.check_diff') }}: {{ formatMoney(Number(c.value?.diff ?? 0), 'CZK') }}</span>
            </template>
            <template v-else-if="c.key === 'donations_543' && !c.na">
              543: {{ formatMoney(Number(c.value?.turnover ?? 0), 'CZK') }} · {{ t('taxReturn.donations') }}: {{ formatMoney(Number(c.value?.donations_input ?? 0), 'CZK') }}
              <span v-if="!c.ok"> · {{ t('taxReturn.check_diff') }}: {{ formatMoney(Number(c.value?.diff ?? 0), 'CZK') }}</span>
            </template>
            <template v-else-if="c.key === 'vh_vs_statement' && !c.na">
              {{ t('taxReturn.po_vh') }}: {{ formatMoney(Number(c.value?.return_vh ?? 0), 'CZK') }} · {{ t('taxReturn.check_statement_vh') }}: {{ formatMoney(Number(c.value?.statement_vh ?? 0), 'CZK') }}
            </template>
            <template v-else-if="c.key === 'non_deductible_accounts' && !c.na">
              <div>{{ t('taxReturn.check_total') }}: {{ formatMoney(Number(c.value?.total ?? 0), 'CZK') }}</div>
              <ul class="mt-1 space-y-0.5">
                <li v-for="a in ((c.value?.accounts as any[]) || [])" :key="a.account_id" class="font-mono flex justify-between">
                  <span>{{ a.account_code }} — {{ a.name }}</span><span>{{ formatMoney(a.turnover, 'CZK') }}</span>
                </li>
              </ul>
            </template>
            <template v-else-if="c.key === 'vat_returns_filed'">
              <span v-if="c.na">{{ t('taxReturn.check_vat_not_payer') }}</span>
              <span v-else>{{ t('taxReturn.check_vat_' + (c.value?.frequency as string)) }} ·
                {{ t('taxReturn.check_vat_submitted', { n: ((c.value?.submitted as any[]) || []).length, total: c.value?.expected }) }}
                <span v-if="!c.ok"> · {{ t('taxReturn.check_vat_missing') }}: {{ ((c.value?.missing as any[]) || []).join(', ') }}</span>
              </span>
            </template>
          </div>
        </li>
      </ul>
    </div>

    <!-- Decentní nota (nahrazuje MVP mega-disclaimer) -->
    <div class="bg-neutral-50 border border-neutral-200 rounded-md p-3 mb-4 text-xs text-neutral-500">
      {{ t('taxReturn.advisory_note') }}
    </div>

    <div v-if="loading" class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-8 text-center text-neutral-400">{{ t('common.loading') }}…</div>
    <div v-else-if="error" class="bg-danger-50 border border-danger-500/40 text-danger-500 rounded-md p-3 text-sm mb-4">{{ error }}</div>

    <template v-if="state && !loading">
      <!-- Taby -->
      <div class="flex gap-1 border-b border-neutral-200 overflow-x-auto overflow-y-hidden mb-4">
        <button v-for="tk in visibleTabs" :key="tk" type="button" @click="switchTab(tk)"
          class="px-4 py-2 text-sm border-b-2 -mb-px whitespace-nowrap"
          :class="activeTab === tk ? 'border-primary-600 text-primary-700 font-medium' : 'border-transparent text-neutral-500 hover:text-neutral-700'">
          {{ tabLabel(tk) }}
        </button>
      </div>

      <!-- Warnings (společné) -->
      <div v-if="warnings.length" class="bg-warning-50 border border-warning-500/40 rounded-md p-3 text-sm text-warning-700 mb-4">
        <strong>{{ t('taxReturn.warnings') }}:</strong>
        <ul class="mt-1 list-disc list-inside">
          <li v-for="w in warnings" :key="w">{{ w }}</li>
        </ul>
      </div>

      <!-- ── Tab: Podklady ── -->
      <section v-show="activeTab === 'podklady'" class="space-y-4">
        <template v-if="type === 'po'">
          <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div class="bg-surface border border-neutral-200 rounded-lg p-4">
              <div class="text-xs uppercase text-neutral-500 mb-1">{{ t('taxReturn.po_vh') }}</div>
              <div class="text-lg font-bold font-mono">{{ formatMoney(Number(state.podklady.vh ?? 0), 'CZK') }}</div>
            </div>
            <div class="bg-surface border border-neutral-200 rounded-lg p-4">
              <div class="text-xs uppercase text-neutral-500 mb-1">{{ t('taxReturn.po_non_deductible') }}</div>
              <div class="text-lg font-bold font-mono">{{ formatMoney(Number(state.podklady.non_deductible_costs ?? 0), 'CZK') }}</div>
            </div>
            <div class="bg-surface border border-neutral-200 rounded-lg p-4">
              <div class="text-xs uppercase text-neutral-500 mb-1">{{ t('taxReturn.po_depreciation') }}</div>
              <div class="text-sm font-mono">
                {{ t('taxReturn.po_dep_tax') }}: {{ formatMoney(Number((state.podklady.depreciation as any)?.tax ?? 0), 'CZK') }}<br>
                {{ t('taxReturn.po_dep_acc') }}: {{ formatMoney(Number((state.podklady.depreciation as any)?.accounting ?? 0), 'CZK') }}
              </div>
            </div>
          </div>
          <div v-if="((state.podklady.disposals as any[]) || []).length" class="bg-surface border border-neutral-200 rounded-lg p-4">
            <div class="text-sm font-semibold mb-2">{{ t('taxReturn.po_disposals') }}</div>
            <table class="w-full text-sm">
              <thead><tr class="text-left text-neutral-500 text-xs">
                <th class="py-1">{{ t('taxReturn.inv') }}</th><th>{{ t('taxReturn.name') }}</th><th class="text-right">{{ t('taxReturn.tax_residual') }}</th><th>{{ t('taxReturn.deductibility') }}</th>
              </tr></thead>
              <tbody>
                <tr v-for="d in (state.podklady.disposals as any[])" :key="d.asset_id" class="border-t border-neutral-100">
                  <td class="py-1">{{ d.inventory_number }}</td><td>{{ d.name }}</td>
                  <td class="text-right font-mono">{{ formatMoney(d.tax_residual_value, 'CZK') }}</td><td>{{ d.deductibility }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </template>
        <template v-else>
          <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div class="bg-surface border border-neutral-200 rounded-lg p-4">
              <div class="text-xs uppercase text-neutral-500 mb-1">{{ t('taxReturn.fo_s7_income') }}</div>
              <div class="text-lg font-bold font-mono">{{ formatMoney(Number(state.podklady.s7_income ?? 0), 'CZK') }}</div>
            </div>
            <div class="bg-surface border border-neutral-200 rounded-lg p-4">
              <div class="text-xs uppercase text-neutral-500 mb-1">{{ t('taxReturn.fo_s7_expenses') }} ({{ state.podklady.expense_mode === 'pausal' ? t('taxReturn.pausal') + ' ' + state.podklady.expense_rate + '%' : t('taxReturn.actual') }})</div>
              <div class="text-lg font-bold font-mono">{{ formatMoney(Number(state.podklady.s7_expenses ?? 0), 'CZK') }}</div>
            </div>
            <div class="bg-surface border border-neutral-200 rounded-lg p-4">
              <div class="text-xs uppercase text-neutral-500 mb-1">{{ t('taxReturn.fo_s7_base') }}</div>
              <div class="text-lg font-bold font-mono">{{ formatMoney(Number(state.podklady.s7_base ?? 0), 'CZK') }}</div>
            </div>
          </div>
          <p class="text-xs text-neutral-500">
            <router-link to="/tax" class="text-primary-600 hover:underline">{{ t('taxReturn.fo_optimizer_link') }}</router-link>
          </p>
        </template>
      </section>

      <!-- ── Tab: Úpravy a odpočty ── -->
      <section v-show="activeTab === 'upravy'" class="space-y-4">
        <div v-if="isFinal" class="text-sm text-neutral-500">{{ t('taxReturn.locked_hint') }}</div>
        <fieldset :disabled="isFinal" class="space-y-4">
          <!-- Evidence daňových ztrát §34 (FO i PO) -->
          <div class="bg-surface border border-neutral-200 rounded-lg p-4">
            <div class="flex items-center justify-between mb-2">
              <span class="text-sm font-semibold">{{ t('taxReturn.tax_losses_title') }}</span>
              <span class="text-xs text-neutral-500">{{ t('taxReturn.tax_losses_carry', { n: taxLosses.carry_years }) }}</span>
            </div>
            <EmptyState v-if="!taxLosses.losses.length" dense accent="neutral" icon="archive"
              :title="t('taxReturn.tax_losses_empty')" />
            <div v-else class="overflow-x-auto">
              <table class="w-full text-sm">
                <thead>
                  <tr class="text-left text-xs uppercase text-neutral-500">
                    <th class="py-1 pr-3">{{ t('taxReturn.tax_losses_origin_year') }}</th>
                    <th class="py-1 pr-3 text-right">{{ t('taxReturn.tax_losses_amount') }}</th>
                    <th class="py-1 pr-3 text-right">{{ t('taxReturn.tax_losses_applied') }}</th>
                    <th class="py-1 pr-3 text-right">{{ t('taxReturn.tax_losses_remaining') }}</th>
                    <th class="py-1 pr-3 text-right">{{ t('taxReturn.tax_losses_expires') }}</th>
                    <th class="py-1"></th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="l in taxLosses.losses" :key="l.origin_year" class="border-t border-neutral-100">
                    <td class="py-1 pr-3">{{ l.origin_year }}</td>
                    <td class="py-1 pr-3 text-right font-mono">{{ formatMoney(l.amount, 'CZK') }}</td>
                    <td class="py-1 pr-3 text-right font-mono text-neutral-500">{{ formatMoney(l.applied, 'CZK') }}</td>
                    <td class="py-1 pr-3 text-right font-mono font-semibold">{{ formatMoney(l.remaining, 'CZK') }}</td>
                    <td class="py-1 pr-3 text-right">{{ l.expires_year }}</td>
                    <td class="py-1">
                      <span v-if="l.applicable" class="text-[11px] px-1.5 py-0.5 rounded bg-success-50 text-success-700">{{ t('taxReturn.tax_losses_applicable') }}</span>
                      <span v-else class="text-[11px] px-1.5 py-0.5 rounded bg-neutral-100 text-neutral-500">{{ t('taxReturn.tax_losses_not_applicable') }}</span>
                    </td>
                  </tr>
                </tbody>
              </table>
              <div v-if="taxLosses.suggested > 0" class="flex items-center justify-between mt-3 pt-2 border-t border-neutral-100">
                <span class="text-sm">{{ t('taxReturn.tax_losses_suggested') }}:
                  <strong class="font-mono">{{ formatMoney(taxLosses.suggested, 'CZK') }}</strong></span>
                <button type="button" @click="applyLossSuggestion" :disabled="isFinal"
                  class="text-xs text-primary-600 disabled:text-neutral-400">{{ t('taxReturn.tax_losses_apply') }}</button>
              </div>
            </div>
          </div>
          <template v-if="type === 'po'">
            <div class="bg-surface border border-neutral-200 rounded-lg p-4 space-y-3">
              <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                <label class="text-sm">{{ t('taxReturn.loss_carryforward') }}
                  <input type="number" v-model.number="inputs.loss_carryforward" class="mt-1 w-full h-9 px-2 border border-neutral-300 rounded-md" /></label>
                <label class="text-sm">{{ t('taxReturn.donations') }}
                  <input type="number" v-model.number="inputs.donations" class="mt-1 w-full h-9 px-2 border border-neutral-300 rounded-md" :disabled="(inputs.donation_items || []).length > 0" />
                  <span class="block text-[11px] text-neutral-400 mt-0.5">{{ t('taxReturn.donations_hint_po') }}</span></label>
                <label class="text-sm">{{ t('taxReturn.rnd_deduction') }}
                  <input type="number" v-model.number="inputs.rnd_deduction" class="mt-1 w-full h-9 px-2 border border-neutral-300 rounded-md" />
                  <span class="block text-[11px] text-neutral-400 mt-0.5">{{ t('taxReturn.rnd_deduction_hint') }}</span></label>
                <label class="text-sm">{{ t('taxReturn.education_deduction') }}
                  <input type="number" v-model.number="inputs.education_deduction" class="mt-1 w-full h-9 px-2 border border-neutral-300 rounded-md" />
                  <span class="block text-[11px] text-neutral-400 mt-0.5">{{ t('taxReturn.education_deduction_hint') }}</span></label>
                <label class="text-sm">{{ t('taxReturn.disabled_avg') }}
                  <input type="number" step="0.01" v-model.number="inputs.disabled_employees_avg" class="mt-1 w-full h-9 px-2 border border-neutral-300 rounded-md" /></label>
                <label class="text-sm">{{ t('taxReturn.disabled_severe_avg') }}
                  <input type="number" step="0.01" v-model.number="inputs.disabled_employees_severe_avg" class="mt-1 w-full h-9 px-2 border border-neutral-300 rounded-md" /></label>
                <label class="text-sm">{{ t('taxReturn.tax_paid_advances') }}
                  <input type="number" v-model.number="inputs.tax_paid_advances" class="mt-1 w-full h-9 px-2 border border-neutral-300 rounded-md" /></label>
                <label class="text-sm">{{ t('taxReturn.filing_deadline') }}
                  <input type="date" v-model="inputs.filing_deadline" class="mt-1 w-full h-9 px-2 border border-neutral-300 rounded-md" />
                  <span class="block text-[11px] text-neutral-400 mt-0.5">{{ t('taxReturn.filing_deadline_hint') }}</span></label>
                <label class="text-sm">{{ t('taxReturn.bank_account') }}
                  <select v-model.number="inputs.bank_account_id" class="mt-1 w-full h-9 px-2 border border-neutral-300 rounded-md bg-surface">
                    <option :value="null">
                      {{ effectiveBankAccount ? t('taxReturn.bank_account_auto', { account: bankAccountLabel(effectiveBankAccount) }) : t('taxReturn.bank_account_auto_none') }}
                    </option>
                    <option v-for="acc in bankAccountOptions" :key="acc.id" :value="acc.id">{{ bankAccountLabel(acc) }}</option>
                  </select>
                  <span class="block text-[11px] text-neutral-400 mt-0.5">{{ t('taxReturn.bank_account_hint') }}</span></label>
              </div>
              <label class="flex items-start gap-2 text-sm mt-3 cursor-pointer">
                <input type="checkbox" v-model="inputs.puz_to_registry" class="mt-0.5" />
                <span>{{ t('taxReturn.puz_to_registry') }}
                  <span class="block text-[11px] text-neutral-400">{{ t('taxReturn.puz_to_registry_hint') }}</span></span>
              </label>
            </div>
            <div class="bg-surface border border-neutral-200 rounded-lg p-4">
              <div class="flex items-center justify-between mb-2">
                <span class="text-sm font-semibold">{{ t('taxReturn.donation_items') }}</span>
                <button type="button" @click="addItem('donation_items')" class="text-xs text-primary-600">+ {{ t('taxReturn.add_item') }}</button>
              </div>
              <p class="text-[11px] text-neutral-400 mb-2">{{ t('taxReturn.donation_items_hint') }}</p>
              <div v-for="(it, i) in inputs.donation_items" :key="'don'+i" class="flex gap-2 mb-2">
                <input v-model="it.text" :placeholder="t('taxReturn.item_text')" class="flex-1 h-9 px-2 border border-neutral-300 rounded-md text-sm" />
                <input type="number" v-model.number="it.amount" class="w-40 h-9 px-2 border border-neutral-300 rounded-md text-sm" />
                <button type="button" @click="removeItem('donation_items', i)" class="text-danger-500 text-sm px-2">×</button>
              </div>
            </div>
            <div class="bg-surface border border-neutral-200 rounded-lg p-4">
              <div class="flex items-center justify-between mb-2">
                <span class="text-sm font-semibold">{{ t('taxReturn.manual_increase') }}</span>
                <button type="button" @click="addItem('manual_increase_items')" class="text-xs text-primary-600">+ {{ t('taxReturn.add_item') }}</button>
              </div>
              <div v-for="(it, i) in inputs.manual_increase_items" :key="'inc'+i" class="flex flex-wrap items-center gap-2 mb-2">
                <input v-model="it.text" :placeholder="t('taxReturn.item_text')" class="flex-1 min-w-[12rem] h-9 px-2 border border-neutral-300 rounded-md text-sm" />
                <input type="number" v-model.number="it.amount" class="w-40 h-9 px-2 border border-neutral-300 rounded-md text-sm" />
                <label class="flex items-center gap-1 text-[11px] text-neutral-500 whitespace-nowrap" :title="t('taxReturn.item_flat_rate_travel_hint')">
                  <input type="checkbox" v-model="it.kind" true-value="flat_rate_travel" false-value="" />
                  {{ t('taxReturn.item_flat_rate_travel') }}
                </label>
                <button type="button" @click="removeItem('manual_increase_items', i)" class="text-danger-500 text-sm px-2">×</button>
              </div>
            </div>
            <div class="bg-surface border border-neutral-200 rounded-lg p-4">
              <div class="flex items-center justify-between mb-2">
                <span class="text-sm font-semibold">{{ t('taxReturn.manual_decrease') }}</span>
                <button type="button" @click="addItem('manual_decrease_items')" class="text-xs text-primary-600">+ {{ t('taxReturn.add_item') }}</button>
              </div>
              <div v-for="(it, i) in inputs.manual_decrease_items" :key="'dec'+i" class="flex flex-wrap items-center gap-2 mb-2">
                <input v-model="it.text" :placeholder="t('taxReturn.item_text')" class="flex-1 min-w-[12rem] h-9 px-2 border border-neutral-300 rounded-md text-sm" />
                <input type="number" v-model.number="it.amount" class="w-40 h-9 px-2 border border-neutral-300 rounded-md text-sm" />
                <label class="flex items-center gap-1 text-[11px] text-neutral-500 whitespace-nowrap" :title="t('taxReturn.item_flat_rate_travel_hint')">
                  <input type="checkbox" v-model="it.kind" true-value="flat_rate_travel" false-value="" />
                  {{ t('taxReturn.item_flat_rate_travel') }}
                </label>
                <button type="button" @click="removeItem('manual_decrease_items', i)" class="text-danger-500 text-sm px-2">×</button>
              </div>
            </div>
          </template>
          <template v-else>
            <div class="bg-surface border border-neutral-200 rounded-lg p-4 grid grid-cols-1 md:grid-cols-2 gap-3">
              <label class="text-sm">{{ t('taxReturn.s6_income') }}
                <input type="number" v-model.number="inputs.s6_employment.income" class="mt-1 w-full h-9 px-2 border border-neutral-300 rounded-md" /></label>
              <label class="text-sm">{{ t('taxReturn.s6_withholding') }}
                <input type="number" v-model.number="inputs.s6_employment.withholding" class="mt-1 w-full h-9 px-2 border border-neutral-300 rounded-md" /></label>
              <label class="text-sm">{{ t('taxReturn.s8_base') }}
                <input type="number" v-model.number="inputs.s8_capital.base" class="mt-1 w-full h-9 px-2 border border-neutral-300 rounded-md" /></label>
              <div></div>
              <label class="text-sm">{{ t('taxReturn.s9_income') }}
                <input type="number" v-model.number="inputs.s9_rental.income" class="mt-1 w-full h-9 px-2 border border-neutral-300 rounded-md" /></label>
              <label class="text-sm">{{ t('taxReturn.s9_expenses') }}
                <input type="number" v-model.number="inputs.s9_rental.expenses" :disabled="inputs.s9_rental.expense_mode === 'pausal'"
                  class="mt-1 w-full h-9 px-2 border border-neutral-300 rounded-md disabled:bg-neutral-50 disabled:text-neutral-500" /></label>
              <label class="text-sm">{{ t('taxReturn.s9_expense_mode') }}
                <select v-model="inputs.s9_rental.expense_mode" class="mt-1 w-full h-9 px-2 border border-neutral-300 rounded-md">
                  <option value="actual">{{ t('taxReturn.s9_expense_mode_actual') }}</option>
                  <option value="pausal">{{ t('taxReturn.s9_expense_mode_pausal') }}</option>
                </select></label>
              <label class="text-sm">{{ t('taxReturn.s10_income') }}
                <input type="number" v-model.number="inputs.s10_other.income" class="mt-1 w-full h-9 px-2 border border-neutral-300 rounded-md" /></label>
              <label class="text-sm">{{ t('taxReturn.s10_expenses') }}
                <input type="number" v-model.number="inputs.s10_other.expenses" class="mt-1 w-full h-9 px-2 border border-neutral-300 rounded-md" /></label>
              <label class="text-sm">{{ t('taxReturn.s16a_separate_base') }}
                <input type="number" v-model.number="inputs.s16a_separate_base" class="mt-1 w-full h-9 px-2 border border-neutral-300 rounded-md" />
                <span class="block text-[11px] text-neutral-400 mt-0.5">{{ t('taxReturn.s16a_separate_base_hint') }}</span></label>
              <div></div>
              <label class="text-sm">{{ t('taxReturn.loss_carryforward') }}
                <input type="number" v-model.number="inputs.loss_carryforward" class="mt-1 w-full h-9 px-2 border border-neutral-300 rounded-md" />
                <span class="block text-[11px] text-neutral-400 mt-0.5">{{ t('taxReturn.loss_carryforward_hint_fo') }}</span></label>
              <label class="text-sm">{{ t('taxReturn.tax_paid_advances') }}
                <input type="number" v-model.number="inputs.tax_paid_advances" class="mt-1 w-full h-9 px-2 border border-neutral-300 rounded-md" /></label>
            </div>
            <div class="bg-surface border border-neutral-200 rounded-lg p-4 grid grid-cols-1 md:grid-cols-2 gap-3">
              <label class="text-sm">{{ t('taxReturn.social_paid_advances') }}
                <input type="number" v-model.number="inputs.social_paid_advances" class="mt-1 w-full h-9 px-2 border border-neutral-300 rounded-md" /></label>
              <label class="text-sm">{{ t('taxReturn.health_paid_advances') }}
                <input type="number" v-model.number="inputs.health_paid_advances" class="mt-1 w-full h-9 px-2 border border-neutral-300 rounded-md" /></label>
            </div>

            <div class="bg-surface border border-neutral-200 rounded-lg p-4 space-y-3">
              <div class="flex flex-wrap items-center justify-between gap-2">
                <div><div class="text-sm font-semibold">{{ t('taxReturn.activities_title') }}</div><p class="text-xs text-neutral-500">{{ t('taxReturn.activities_hint') }}</p></div>
                <button type="button" @click="addActivity" class="h-9 px-3 rounded-md bg-primary-600 text-white text-sm whitespace-nowrap"><span aria-hidden="true">＋</span> {{ t('taxReturn.add_activity') }}</button>
              </div>
              <div v-for="(activity, index) in profile.activities" :key="index" class="border border-neutral-200 rounded-md p-3 grid grid-cols-1 md:grid-cols-4 gap-2">
                <label class="text-xs md:col-span-2">{{ t('taxReturn.activity_name') }}<input v-model="activity.name" class="mt-1 w-full h-9 px-2 border border-neutral-300 rounded-md text-sm" /></label>
                <label class="text-xs">CZ-NACE<input v-model="activity.nace_code" class="mt-1 w-full h-9 px-2 border border-neutral-300 rounded-md text-sm" /></label>
                <label class="text-xs">{{ t('taxReturn.active_months') }}<input type="number" min="0" max="12" v-model.number="activity.active_months" class="mt-1 w-full h-9 px-2 border border-neutral-300 rounded-md text-sm" /></label>
                <label class="text-xs">{{ t('taxReturn.expense_mode') }}<select v-model="activity.expense_mode" class="mt-1 w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface"><option value="pausal">{{ t('taxReturn.pausal') }}</option><option value="actual">{{ t('taxReturn.actual') }}</option></select></label>
                <label v-if="activity.expense_mode === 'pausal'" class="text-xs">{{ t('taxReturn.expense_rate') }}<select v-model.number="activity.expense_rate" class="mt-1 w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface"><option v-for="rate in [30,40,60,80]" :key="rate" :value="rate">{{ rate }} %</option></select></label>
                <label class="text-xs">{{ t('taxReturn.activity_income') }}<input type="number" v-model.number="activity.income" class="mt-1 w-full h-9 px-2 border border-neutral-300 rounded-md text-sm" /></label>
                <label v-if="activity.expense_mode === 'actual'" class="text-xs">{{ t('taxReturn.activity_expenses') }}<input type="number" v-model.number="activity.expenses" class="mt-1 w-full h-9 px-2 border border-neutral-300 rounded-md text-sm" /></label>
                <button type="button" @click="profile.activities!.splice(index, 1)" class="self-end h-9 px-3 rounded-md border border-danger-500 text-danger-600 text-sm whitespace-nowrap"><span aria-hidden="true">×</span> {{ t('common.delete') }}</button>
              </div>
            </div>

            <div class="bg-surface border border-neutral-200 rounded-lg p-4 space-y-3">
              <div class="flex flex-wrap items-center justify-between gap-2"><div class="text-sm font-semibold">{{ t('taxReturn.s10_items_title') }}</div>
                <button type="button" @click="addS10Item" class="h-9 px-3 rounded-md bg-primary-600 text-white text-sm whitespace-nowrap"><span aria-hidden="true">＋</span> {{ t('taxReturn.add_item') }}</button></div>
              <div v-for="(item, index) in (inputs.s10_items || [])" :key="index" class="grid grid-cols-1 md:grid-cols-4 gap-2">
                <input v-model="item.text" :placeholder="t('taxReturn.s10_kind')" class="h-9 px-2 border border-neutral-300 rounded-md text-sm" />
                <input type="number" v-model.number="item.income" :placeholder="t('taxReturn.s10_income')" class="h-9 px-2 border border-neutral-300 rounded-md text-sm" />
                <input type="number" v-model.number="item.expenses" :placeholder="t('taxReturn.s10_expenses')" class="h-9 px-2 border border-neutral-300 rounded-md text-sm" />
                <button type="button" @click="inputs.s10_items.splice(index, 1)" class="h-9 px-3 rounded-md border border-danger-500 text-danger-600 text-sm"><span aria-hidden="true">×</span> {{ t('common.delete') }}</button>
              </div>
            </div>

            <div class="bg-surface border border-neutral-200 rounded-lg p-4 space-y-3">
              <div class="text-sm font-semibold">{{ t('taxReturn.deductions_credits_title') }}</div>
              <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
                <label class="text-xs">{{ t('taxReturn.mortgage_interest') }}<input type="number" v-model.number="profile.mortgage_interest" class="mt-1 w-full h-9 px-2 border border-neutral-300 rounded-md" /></label>
                <label class="text-xs">{{ t('taxReturn.mortgage_months') }}<input type="number" min="0" max="12" v-model.number="profile.mortgage_months" class="mt-1 w-full h-9 px-2 border border-neutral-300 rounded-md" /></label>
                <label class="text-xs" :title="t('taxReturn.pension_confirmed_hint')">{{ t('taxReturn.pension_confirmed') }}<input type="number" v-model.number="profile.pension_contrib" class="mt-1 w-full h-9 px-2 border border-neutral-300 rounded-md" /></label>
                <label class="text-xs">{{ t('taxReturn.life_insurance') }}<input type="number" v-model.number="profile.life_insurance" class="mt-1 w-full h-9 px-2 border border-neutral-300 rounded-md" /></label>
                <label class="text-xs">DIP<input type="number" v-model.number="profile.dip_contrib" class="mt-1 w-full h-9 px-2 border border-neutral-300 rounded-md" /></label>
                <label class="text-xs">{{ t('taxReturn.long_term_care') }}<input type="number" v-model.number="profile.long_term_care" class="mt-1 w-full h-9 px-2 border border-neutral-300 rounded-md" /></label>
                <label class="text-xs">{{ t('taxReturn.donations') }}<input type="number" v-model.number="profile.donations" class="mt-1 w-full h-9 px-2 border border-neutral-300 rounded-md" /></label>
                <label class="text-xs">{{ t('taxReturn.disability12_months') }}<input type="number" min="0" max="12" v-model.number="profile.disability_12_months" class="mt-1 w-full h-9 px-2 border border-neutral-300 rounded-md" /></label>
                <label class="text-xs">{{ t('taxReturn.disability3_months') }}<input type="number" min="0" max="12" v-model.number="profile.disability_3_months" class="mt-1 w-full h-9 px-2 border border-neutral-300 rounded-md" /></label>
                <label class="text-xs">{{ t('taxReturn.ztpp_months') }}<input type="number" min="0" max="12" v-model.number="profile.ztpp_months" class="mt-1 w-full h-9 px-2 border border-neutral-300 rounded-md" /></label>
              </div>
            </div>

            <div class="bg-surface border border-neutral-200 rounded-lg p-4 space-y-3">
              <div class="flex flex-wrap items-center justify-between gap-2"><div class="text-sm font-semibold">{{ t('taxReturn.family_claims_title') }}</div>
                <div class="flex flex-wrap gap-2"><button type="button" @click="addChild" class="h-9 px-3 rounded-md bg-primary-600 text-white text-sm whitespace-nowrap"><span aria-hidden="true">＋</span> {{ t('taxReturn.add_child') }}</button><button v-if="!profile.spouse_claim" type="button" @click="enableSpouse" class="h-9 px-3 rounded-md border border-primary-600 text-primary-700 text-sm whitespace-nowrap"><span aria-hidden="true">＋</span> {{ t('taxReturn.add_spouse') }}</button></div>
              </div>
              <div v-for="(child, childIndex) in profile.children" :key="childIndex" class="border border-neutral-200 rounded-md p-3 space-y-2">
                <div class="grid grid-cols-1 md:grid-cols-5 gap-2"><input v-model="child.first_name" :placeholder="t('taxReturn.first_name')" class="h-9 px-2 border border-neutral-300 rounded-md text-sm" /><input v-model="child.last_name" :placeholder="t('taxReturn.last_name')" class="h-9 px-2 border border-neutral-300 rounded-md text-sm" /><input v-model="child.birth_number" :placeholder="t('taxReturn.birth_number')" class="h-9 px-2 border border-neutral-300 rounded-md text-sm" /><input type="date" v-model="child.birth_date" class="h-9 px-2 border border-neutral-300 rounded-md text-sm" /><button type="button" @click="profile.children!.splice(childIndex, 1)" class="h-9 px-3 rounded-md border border-danger-500 text-danger-600 text-sm"><span aria-hidden="true">×</span> {{ t('common.delete') }}</button></div>
                <div class="flex flex-wrap gap-4 text-xs"><label><input type="checkbox" v-model="child.shared_household_proved" /> {{ t('taxReturn.shared_household_proved') }}</label><label><input type="checkbox" v-model="child.other_parent_not_claimed_proved" /> {{ t('taxReturn.other_parent_proved') }}</label></div>
                <div class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-6 gap-1"><label v-for="month in child.months" :key="month.month" class="border border-neutral-200 rounded p-1 text-[11px]"><input type="checkbox" v-model="month.claimed" /> {{ month.month }}. <select v-model.number="month.order" class="ml-1 border rounded bg-surface"><option :value="1">1.</option><option :value="2">2.</option><option :value="3">3.+</option></select><label class="ml-1"><input type="checkbox" v-model="month.ztpp" /> ZTP/P</label></label></div>
              </div>
              <div v-if="profile.spouse_claim" class="border border-neutral-200 rounded-md p-3 space-y-2">
                <div class="grid grid-cols-1 md:grid-cols-5 gap-2"><input v-model="profile.spouse_claim.first_name" :placeholder="t('taxReturn.first_name')" class="h-9 px-2 border border-neutral-300 rounded-md text-sm" /><input v-model="profile.spouse_claim.last_name" :placeholder="t('taxReturn.last_name')" class="h-9 px-2 border border-neutral-300 rounded-md text-sm" /><input v-model="profile.spouse_claim.birth_number" :placeholder="t('taxReturn.birth_number')" class="h-9 px-2 border border-neutral-300 rounded-md text-sm" /><input type="number" min="0" max="12" v-model.number="profile.spouse_claim.eligible_months" :title="t('taxReturn.eligible_months')" class="h-9 px-2 border border-neutral-300 rounded-md text-sm" /><button type="button" @click="profile.spouse_claim = null" class="h-9 px-3 rounded-md border border-danger-500 text-danger-600 text-sm"><span aria-hidden="true">×</span> {{ t('common.delete') }}</button></div>
                <label class="text-xs">{{ t('taxReturn.spouse_income') }}<input type="number" v-model.number="profile.spouse_claim.own_income" class="ml-2 h-8 px-2 border border-neutral-300 rounded-md" /></label>
                <div class="flex flex-wrap gap-4 text-xs"><label><input type="checkbox" v-model="profile.spouse_claim.income_proved" /> {{ t('taxReturn.income_proved') }}</label><label><input type="checkbox" v-model="profile.spouse_claim.shared_household_proved" /> {{ t('taxReturn.shared_household_proved') }}</label><label><input type="checkbox" v-model="profile.spouse_claim.child_under_three_proved" /> {{ t('taxReturn.child_under_three_proved') }}</label><label><input type="checkbox" v-model="profile.spouse_claim.ztpp" /> ZTP/P</label></div>
              </div>
            </div>

            <div class="bg-surface border border-neutral-200 rounded-lg p-4 space-y-3 overflow-x-auto">
              <div class="text-sm font-semibold">{{ t('taxReturn.osvc_months_title') }}</div>
              <table class="w-full min-w-[760px] text-xs"><thead><tr><th class="text-left">{{ t('taxReturn.month') }}</th><th>{{ t('taxReturn.activity_status') }}</th><th>{{ t('taxReturn.social_participates') }}</th><th>{{ t('taxReturn.health_minimum') }}</th><th>{{ t('taxReturn.state_insured') }}</th><th>{{ t('taxReturn.employed') }}</th><th>{{ t('taxReturn.new_osvc') }}</th></tr></thead><tbody><tr v-for="month in profile.osvc_months" :key="month.month" class="border-t border-neutral-100"><td>{{ month.month }}.</td><td><select v-model="month.activity_status" class="h-8 border border-neutral-300 rounded bg-surface"><option value="inactive">{{ t('taxReturn.inactive') }}</option><option value="main">{{ t('taxReturn.main') }}</option><option value="secondary">{{ t('taxReturn.secondary') }}</option></select></td><td class="text-center"><input type="checkbox" v-model="month.social_participates" /></td><td class="text-center"><input type="checkbox" v-model="month.health_minimum_applies" /></td><td class="text-center"><input type="checkbox" v-model="month.state_insured" /></td><td class="text-center"><input type="checkbox" v-model="month.employed" /></td><td class="text-center"><input type="checkbox" v-model="month.new_osvc" /></td></tr></tbody></table>
            </div>

            <div v-if="closing" class="bg-surface border border-neutral-200 rounded-lg p-4 space-y-4">
              <div class="flex flex-wrap items-center justify-between gap-2"><div><div class="text-sm font-semibold">{{ t('taxReturn.de_closing_title') }}</div><div class="text-xs text-neutral-500">{{ closing.status === 'final' ? t('taxReturn.status_final') : t('taxReturn.status_draft') }}<span v-if="closing.source_hash"> · {{ closing.source_hash.slice(0, 12) }}…</span></div></div><div class="flex flex-wrap gap-2"><button v-if="closing.status === 'draft'" type="button" @click="saveClosing" :disabled="closingBusy" class="h-9 px-3 rounded-md border border-primary-600 text-primary-700 text-sm"><span aria-hidden="true">✓</span> {{ t('taxReturn.save_closing') }}</button><button v-if="closing.status === 'draft'" type="button" @click="finalizeClosing" :disabled="closingBusy" class="h-9 px-3 rounded-md bg-success-600 text-white text-sm"><span aria-hidden="true">✓</span> {{ t('taxReturn.finalize_closing') }}</button><button v-else type="button" @click="reopenClosing" :disabled="closingBusy" class="h-9 px-3 rounded-md border border-warning-500 text-warning-700 text-sm"><span aria-hidden="true">↶</span> {{ t('taxReturn.reopen_closing') }}</button></div></div>
              <fieldset :disabled="closing.status === 'final'" class="space-y-4"><div class="grid grid-cols-1 md:grid-cols-2 gap-2"><label v-for="key in closingChecks" :key="key" class="text-xs"><input type="checkbox" v-model="closing.checklist[key]" /> {{ t('taxReturn.closing_check_' + key) }}</label></div>
                <div class="overflow-x-auto"><table class="w-full min-w-[620px] text-xs"><thead><tr><th class="text-left">{{ t('taxReturn.closing_balance_kind') }}</th><th>{{ t('taxReturn.opening_balance') }}</th><th>{{ t('taxReturn.closing_balance') }}</th></tr></thead><tbody><tr v-for="key in closingBalanceKeys" :key="key" class="border-t border-neutral-100"><td>{{ t('taxReturn.closing_balance_' + key) }}</td><td><input type="number" v-model.number="closing.opening_balances[key]" class="h-8 w-full px-2 border border-neutral-300 rounded-md" /></td><td><input type="number" v-model.number="closing.closing_balances[key]" class="h-8 w-full px-2 border border-neutral-300 rounded-md" /></td></tr></tbody></table></div>
                <div><div class="flex flex-wrap items-center justify-between gap-2 mb-2"><span class="text-sm font-medium">{{ t('taxReturn.non_cash_adjustments') }}</span><button type="button" @click="addClosingAdjustment" class="h-8 px-2 rounded-md border border-primary-600 text-primary-700 text-xs"><span aria-hidden="true">＋</span> {{ t('taxReturn.add_item') }}</button></div><div v-for="(adjustment, index) in closing.adjustments" :key="index" class="grid grid-cols-1 md:grid-cols-6 gap-2 mb-2"><input type="date" v-model="adjustment.adjustment_on" class="h-9 px-2 border border-neutral-300 rounded-md text-xs" /><select v-model="adjustment.kind" class="h-9 px-2 border border-neutral-300 rounded-md text-xs bg-surface"><option v-for="kind in ['setoff','barter','in_kind_income','debt_forgiveness','private_use','shortage','damage','inventory','receivable','payable','section23_other']" :key="kind" :value="kind">{{ t('taxReturn.adjustment_' + kind) }}</option></select><select v-model="adjustment.direction" class="h-9 px-2 border border-neutral-300 rounded-md text-xs bg-surface"><option value="increase">{{ t('taxReturn.increase') }}</option><option value="decrease">{{ t('taxReturn.decrease') }}</option><option value="neutral">{{ t('taxReturn.neutral') }}</option></select><input type="number" v-model.number="adjustment.amount" class="h-9 px-2 border border-neutral-300 rounded-md text-xs" /><input v-model="adjustment.description" :placeholder="t('taxReturn.description')" class="h-9 px-2 border border-neutral-300 rounded-md text-xs" /><button type="button" @click="closing.adjustments.splice(index, 1)" class="h-9 px-2 border border-danger-500 text-danger-600 rounded-md text-xs"><span aria-hidden="true">×</span></button></div></div>
              </fieldset>
            </div>
            <p class="text-xs text-neutral-500">{{ t('taxReturn.fo_deductions_hint') }}</p>
          </template>
          <div v-if="variant === 'dodatecne'" class="bg-surface border border-neutral-200 rounded-lg p-4 grid grid-cols-1 md:grid-cols-2 gap-3">
            <label class="text-sm">{{ t('taxReturn.last_known_tax') }}
              <input type="number" v-model.number="inputs.last_known_tax" class="mt-1 w-full h-9 px-2 border border-neutral-300 rounded-md" /></label>
            <label class="text-sm">{{ t('taxReturn.d_zjist') }}
              <input type="date" v-model="inputs.d_zjist" class="mt-1 w-full h-9 px-2 border border-neutral-300 rounded-md" /></label>
            <label class="block text-sm md:col-span-2">{{ t('taxReturn.amend_reason') }}
              <textarea v-model="inputs.amend_reason" rows="2" class="mt-1 w-full px-2 py-1 border border-neutral-300 rounded-md"></textarea></label>
          </div>
          <div v-else-if="variant === 'opravne'" class="bg-surface border border-neutral-200 rounded-lg p-4">
            <label class="block text-sm">{{ t('taxReturn.amend_reason') }}
              <textarea v-model="inputs.amend_reason" rows="2" class="mt-1 w-full px-2 py-1 border border-neutral-300 rounded-md"></textarea></label>
          </div>
          <label class="block text-sm">{{ t('taxReturn.notes') }}
            <textarea v-model="inputs.notes" rows="2" class="mt-1 w-full px-2 py-1 border border-neutral-300 rounded-md"></textarea></label>
        </fieldset>
      </section>

      <!-- ── Tab: Náhled ── -->
      <section v-show="activeTab === 'nahled'" class="space-y-4">
        <!-- Feature 2 — auto-návrhy připočitatelných (§25) / odečitatelných (§20/§34) položek k ověření. -->
        <div v-if="hasSuggestions" class="bg-warning-50 border border-warning-500/40 rounded-lg p-4">
          <div class="text-sm font-semibold text-warning-800 mb-1">{{ t('taxReturn.suggest_title') }}</div>
          <p class="text-xs text-warning-700/90 mb-3">{{ t('taxReturn.suggest_hint') }}</p>
          <div v-if="addbackSuggestions.length" class="mb-3">
            <div class="text-xs uppercase text-neutral-500 mb-1">{{ t('taxReturn.suggest_addbacks') }}</div>
            <ul class="space-y-1.5 text-sm">
              <li v-for="a in addbackSuggestions" :key="a.account_code" class="flex justify-between gap-3">
                <span>
                  <span class="font-mono">{{ a.account_code }}</span> — {{ a.name }}
                  <span v-if="a.already_non_deductible" class="ml-1 text-[10px] px-1 py-0.5 rounded bg-success-100 text-success-700">{{ t('taxReturn.suggest_already') }}</span>
                  <span class="block text-xs text-neutral-500">{{ t(a.hint_key) }}</span>
                </span>
                <span class="font-mono whitespace-nowrap">{{ formatMoney(a.amount, 'CZK') }}</span>
              </li>
            </ul>
          </div>
          <div v-if="deductionSuggestions.length || (taxLosses.suggested ?? 0) > 0">
            <div class="text-xs uppercase text-neutral-500 mb-1">{{ t('taxReturn.suggest_deductions') }}</div>
            <ul class="space-y-1.5 text-sm">
              <li v-for="d in deductionSuggestions" :key="d.key" class="flex justify-between gap-3">
                <span>{{ t(d.hint_key) }} <span class="font-mono text-neutral-400">({{ d.account_code }})</span></span>
                <span class="font-mono whitespace-nowrap">{{ formatMoney(d.amount, 'CZK') }}</span>
              </li>
              <li v-if="(taxLosses.suggested ?? 0) > 0" class="flex justify-between gap-3">
                <span>{{ t('taxReturn.suggest_deduct_loss') }}</span>
                <span class="font-mono whitespace-nowrap">{{ formatMoney(taxLosses.suggested, 'CZK') }}</span>
              </li>
            </ul>
          </div>
        </div>

        <div class="bg-surface border border-neutral-200 rounded-lg overflow-hidden">
          <table class="w-full text-sm">
            <thead><tr class="text-left text-neutral-500 text-xs bg-neutral-50">
              <th class="px-3 py-2 w-16">{{ t('taxReturn.line') }}</th><th>{{ t('taxReturn.description') }}</th>
              <th class="px-3 text-right whitespace-nowrap">{{ t('taxReturn.value') }}</th><th class="hidden md:table-cell px-3">{{ t('taxReturn.source') }}</th>
            </tr></thead>
            <tbody>
              <tr v-for="l in (computed_?.lines ?? [])" :key="String(l.line)" class="border-t border-neutral-100">
                <td class="px-3 py-1.5 text-neutral-500 font-mono">{{ l.code }}</td>
                <td>{{ l.label }}</td>
                <td class="px-3 text-right font-mono whitespace-nowrap">{{ formatMoney(l.value, 'CZK') }}</td>
                <td class="hidden md:table-cell px-3 text-xs text-neutral-400">{{ l.source }}</td>
              </tr>
            </tbody>
          </table>
        </div>
        <p class="text-xs text-neutral-500">{{ t('taxReturn.vh_realtime_note') }}</p>

        <!-- Feature 1 — projekce dosud NEzaúčtovaných závěrkových operací do VH (posted → projektovaný). -->
        <div v-if="projection?.is_projection" class="bg-surface border border-primary-500/40 rounded-lg p-4">
          <div class="text-sm font-semibold mb-1">{{ t('taxReturn.projection_title') }}</div>
          <p class="text-xs text-neutral-500 mb-3">{{ t('taxReturn.projection_note') }}</p>
          <div class="space-y-1 text-sm max-w-md">
            <div class="flex justify-between">
              <span>{{ t('taxReturn.projection_vh_posted') }}</span>
              <span class="font-mono">{{ formatMoney(projection.vh_posted, 'CZK') }}</span>
            </div>
            <div v-for="it in projection.items" :key="it.key" class="flex justify-between"
              :class="it.optional ? 'text-neutral-400' : 'text-neutral-700'">
              <span>
                {{ it.sign >= 0 ? '+' : '−' }} {{ t(it.label_key) }}
                <span v-if="it.optional" class="text-[10px] uppercase ml-1">· {{ t('taxReturn.projection_optional') }}</span>
              </span>
              <span class="font-mono whitespace-nowrap">{{ it.sign >= 0 ? '+' : '−' }}{{ formatMoney(it.amount, 'CZK') }}</span>
            </div>
            <div class="flex justify-between border-t border-neutral-200 pt-1 font-semibold">
              <span>{{ t('taxReturn.projection_vh_projected') }}</span>
              <span class="font-mono">{{ formatMoney(projection.vh_projected, 'CZK') }}</span>
            </div>
            <div class="flex justify-between">
              <span>{{ t('taxReturn.projection_tax') }}</span>
              <span class="font-mono">{{ formatMoney(projection.projected_tax, 'CZK') }}</span>
            </div>
          </div>
        </div>

        <div class="flex flex-wrap gap-4">
          <div class="bg-surface border border-neutral-200 rounded-lg p-4 flex-1 min-w-[180px]">
            <div class="text-xs uppercase text-neutral-500">{{ t('taxReturn.total_tax') }}</div>
            <div class="text-2xl font-bold font-mono">{{ formatMoney(Number(computed_?.tax ?? 0), 'CZK') }}</div>
            <div v-if="projection?.is_projection" class="text-xs text-primary-600 mt-1">
              {{ t('taxReturn.projection_after_close') }}: <span class="font-mono font-semibold">{{ formatMoney(projection.projected_tax, 'CZK') }}</span>
            </div>
          </div>
          <div class="bg-surface border border-neutral-200 rounded-lg p-4 flex-1 min-w-[180px]">
            <div class="text-xs uppercase text-neutral-500">{{ t('taxReturn.balance_due') }}</div>
            <div class="text-2xl font-bold font-mono" :class="Number(computed_?.balance_due ?? 0) >= 0 ? 'text-danger-500' : 'text-success-600'">
              {{ formatMoney(Math.abs(Number(computed_?.balance_due ?? 0)), 'CZK') }}
            </div>
            <div class="text-xs text-neutral-500">{{ Number(computed_?.balance_due ?? 0) >= 0 ? t('taxReturn.to_pay') : t('taxReturn.overpaid') }}</div>
          </div>
          <div v-if="computed_?.next_advances && computed_.next_advances.regime !== 'none'" class="bg-surface border border-neutral-200 rounded-lg p-4 flex-1 min-w-[180px]">
            <div class="text-xs uppercase text-neutral-500">{{ t('taxReturn.next_advances') }}</div>
            <div class="text-lg font-bold font-mono">{{ computed_.next_advances.count }}× {{ formatMoney(computed_.next_advances.amount, 'CZK') }}</div>
            <div class="text-xs text-neutral-500">{{ computed_.next_advances.note }}</div>
          </div>
        </div>
        <div v-if="computed_?.amendment" class="bg-surface border border-neutral-200 rounded-lg p-4">
          <div class="text-sm font-semibold mb-2">{{ t('taxReturn.amendment_title') }}</div>
          <div class="grid grid-cols-1 md:grid-cols-3 gap-4 text-sm">
            <div>
              <div class="text-xs uppercase text-neutral-500">{{ t('taxReturn.amendment_new_tax') }}</div>
              <div class="font-mono font-semibold">{{ formatMoney(computed_.amendment.new_tax, 'CZK') }}</div>
            </div>
            <div>
              <div class="text-xs uppercase text-neutral-500">{{ t('taxReturn.amendment_last_known_tax') }}</div>
              <div class="font-mono font-semibold">{{ formatMoney(computed_.amendment.last_known_tax, 'CZK') }}</div>
            </div>
            <div>
              <div class="text-xs uppercase text-neutral-500">{{ t('taxReturn.amendment_difference') }}</div>
              <div class="font-mono font-semibold" :class="computed_.amendment.tax_difference < 0 ? 'text-danger-500' : 'text-success-600'">
                {{ computed_.amendment.tax_difference >= 0 ? '+' : '' }}{{ formatMoney(computed_.amendment.tax_difference, 'CZK') }}
              </div>
            </div>
          </div>
        </div>
      </section>

      <!-- ── Tab: Export ── -->
      <section v-show="activeTab === 'export'" class="space-y-4">
        <div class="bg-surface border border-neutral-200 rounded-lg p-5">
          <h3 class="text-sm font-semibold mb-1">{{ t('taxReturn.export_xml_title') }}</h3>
          <p class="text-xs text-neutral-500 mb-3">{{ t('taxReturn.export_xml_hint') }}</p>
          <button type="button" @click="downloadXml" class="inline-flex items-center gap-2 h-9 px-4 rounded-md bg-primary-600 text-white text-sm">
            {{ t('taxReturn.download_xml') }}
          </button>
        </div>

        <!-- Featura A — rekonciliace proti PODANÉMU přiznání (jen DPPO) -->
        <div v-if="type === 'po'" class="bg-surface border border-neutral-200 rounded-lg p-5">
          <h3 class="text-sm font-semibold mb-1">{{ t('taxReturn.reconcile_title') }}</h3>
          <p class="text-xs text-neutral-500 mb-3">{{ t('taxReturn.reconcile_hint') }}</p>
          <div class="flex items-center gap-2 flex-wrap">
            <input type="file" accept=".xml,text/xml,application/xml" @change="onReconcileFileChange"
              class="text-sm file:mr-3 file:h-8 file:px-3 file:rounded-md file:border-0 file:bg-neutral-100 file:text-neutral-700 dark:file:bg-neutral-800 dark:file:text-neutral-200" />
            <button type="button" @click="runReconcile" :disabled="!reconcileFile || reconcileLoading"
              class="inline-flex items-center gap-2 h-9 px-4 rounded-md bg-primary-600 text-white text-sm disabled:opacity-50">
              {{ reconcileLoading ? t('taxReturn.reconcile_running') : t('taxReturn.reconcile_run') }}
            </button>
          </div>
          <div v-if="reconcileError" class="bg-danger-50 border border-danger-500/40 text-danger-500 rounded-md p-3 text-sm mt-3">{{ reconcileError }}</div>

          <template v-if="reconcileResult">
            <div class="mt-4 bg-neutral-50 dark:bg-neutral-800/60 border border-neutral-200 dark:border-neutral-700 rounded-md p-3 text-sm font-medium flex flex-wrap gap-x-4 gap-y-1">
              <span>{{ t('taxReturn.reconcile_filing_info', {
                forma: reconcileResult.filing.dapdpp_forma, verze: reconcileResult.filing.verze_pis,
                od: reconcileResult.filing.zdobd_od || '?', do: reconcileResult.filing.zdobd_do || '?' }) }}</span>
              <span>{{ t('taxReturn.reconcile_filing_company', {
                name: reconcileResult.filing.supplier.name || '?',
                ic: reconcileResult.filing.supplier.ic || '?',
                dic: reconcileResult.filing.supplier.dic || '?' }) }}</span>
            </div>
            <ul v-if="reconcileResult.warnings.length" class="bg-warning-50 border border-warning-500/40 rounded-md p-3 text-sm text-warning-700 mt-3 list-disc list-inside">
              <li v-for="w in reconcileResult.warnings" :key="w">{{ w }}</li>
            </ul>

            <div class="mt-3 flex items-center gap-3 text-sm">
              <span v-if="reconcileResult.diff.mismatched === 0" class="text-success-700 font-medium">{{ t('taxReturn.reconcile_all_match') }}</span>
              <span v-else class="text-danger-600 font-medium">
                {{ t('taxReturn.reconcile_summary', { matched: reconcileResult.diff.matched, mismatched: reconcileResult.diff.mismatched }) }}
              </span>
            </div>

            <div class="mt-2 overflow-x-auto border border-neutral-200 rounded-lg">
              <table class="w-full text-sm">
                <thead><tr class="text-left text-neutral-500 text-xs bg-neutral-50">
                  <th class="px-3 py-2 w-16">{{ t('taxReturn.reconcile_col_line') }}</th>
                  <th>{{ t('taxReturn.reconcile_col_label') }}</th>
                  <th class="px-3 text-right whitespace-nowrap">{{ t('taxReturn.reconcile_col_our') }}</th>
                  <th class="px-3 text-right whitespace-nowrap">{{ t('taxReturn.reconcile_col_filed') }}</th>
                  <th class="px-3 text-right whitespace-nowrap">{{ t('taxReturn.reconcile_col_diff') }}</th>
                </tr></thead>
                <tbody>
                  <tr v-for="row in reconcileResult.diff.rows" :key="row.line" class="border-t border-neutral-100"
                    :class="row.match ? '' : 'bg-danger-50/60 dark:bg-danger-500/10'">
                    <td class="px-3 py-1.5 text-neutral-500 font-mono">{{ row.code }}</td>
                    <td>{{ row.label }}</td>
                    <td class="px-3 text-right font-mono whitespace-nowrap">{{ formatMoney(row.our_value, 'CZK') }}</td>
                    <td class="px-3 text-right font-mono whitespace-nowrap">
                      {{ formatMoney(row.filed_value, 'CZK') }}
                      <span v-if="!row.filed_present" class="text-[10px] text-neutral-400 ml-1">({{ t('taxReturn.reconcile_not_filed') }})</span>
                    </td>
                    <td class="px-3 text-right font-mono whitespace-nowrap" :class="row.match ? 'text-success-600' : 'text-danger-600 font-semibold'">
                      {{ row.diff >= 0 ? '+' : '' }}{{ formatMoney(row.diff, 'CZK') }}
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>

            <div v-if="extraEntries(reconcileResult.extra).length" class="mt-4">
              <div class="text-xs font-semibold text-neutral-600 mb-1">{{ t('taxReturn.reconcile_extra_title') }}</div>
              <ul class="text-xs text-neutral-500 space-y-0.5">
                <li v-for="e in extraEntries(reconcileResult.extra)" :key="e.key" class="flex justify-between gap-3">
                  <span>{{ e.label }} <span class="font-mono text-neutral-400">({{ e.key }})</span></span>
                  <span class="font-mono whitespace-nowrap">{{ formatMoney(e.value, 'CZK') }}</span>
                </li>
              </ul>
            </div>

            <div v-if="reconcileResult.amendment.kc_dppiv1 !== null" class="mt-4 border-t border-neutral-100 pt-3">
              <div class="text-xs font-semibold text-neutral-600 mb-1">{{ t('taxReturn.amendment_title') }}</div>
              <div class="grid grid-cols-1 md:grid-cols-3 gap-4 text-sm">
                <div>
                  <div class="text-xs uppercase text-neutral-500">{{ t('taxReturn.amendment_new_tax') }}</div>
                  <div class="font-mono font-semibold">{{ formatMoney(reconcileResult.amendment.kc_dppiv1 ?? 0, 'CZK') }}</div>
                </div>
                <div>
                  <div class="text-xs uppercase text-neutral-500">{{ t('taxReturn.amendment_last_known_tax') }}</div>
                  <div class="font-mono font-semibold">{{ formatMoney(reconcileResult.amendment.kc_dppiv2 ?? 0, 'CZK') }}</div>
                </div>
                <div>
                  <div class="text-xs uppercase text-neutral-500">{{ t('taxReturn.amendment_difference') }}</div>
                  <div class="font-mono font-semibold">{{ formatMoney(reconcileResult.amendment.kc_dppiv3 ?? 0, 'CZK') }}</div>
                </div>
              </div>
            </div>
          </template>
        </div>

        <div v-if="type === 'fo'" class="bg-surface border border-neutral-200 rounded-lg p-5">
          <div class="flex items-center justify-between mb-3">
            <h3 class="text-sm font-semibold">{{ t('taxReturn.insurance_title') }}</h3>
            <button type="button" @click="loadInsurance" :disabled="insuranceLoading"
              class="inline-flex items-center gap-1.5 h-8 px-3 rounded-md text-sm bg-primary-600 text-white disabled:opacity-50">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h5M20 20v-5h-5M4 9a8 8 0 0 1 14-3M20 15a8 8 0 0 1-14 3" /></svg>
              {{ insurance ? t('taxReturn.reload') : t('taxReturn.compute') }}
            </button>
          </div>
          <div v-if="insuranceLoading" class="text-sm text-neutral-400">{{ t('common.loading') }}…</div>
          <div v-else-if="insurance" class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div class="border border-neutral-200 rounded-md p-3">
              <div class="text-xs font-semibold text-neutral-600 mb-2">{{ t('taxReturn.social') }}</div>
              <div class="text-sm space-y-1">
                <div class="flex justify-between"><span>{{ t('taxReturn.assessment_base') }}</span><span class="font-mono">{{ formatMoney(insurance.social.assessment_base, 'CZK') }}</span></div>
                <div class="flex justify-between font-semibold"><span>{{ t('taxReturn.insurance') }}</span><span class="font-mono">{{ formatMoney(insurance.social.insurance, 'CZK') }}</span></div>
                <div class="flex justify-between"><span>{{ t('taxReturn.balance_due') }}</span><span class="font-mono">{{ formatMoney(insurance.social.balance_due, 'CZK') }}</span></div>
                <div class="flex justify-between"><span>{{ t('taxReturn.monthly_advance') }}</span><span class="font-mono">{{ formatMoney(insurance.social.monthly_advance, 'CZK') }}</span></div>
                <div v-if="insurance.social.sickness?.insured" class="flex justify-between text-neutral-500"><span>{{ t('taxReturn.sickness') }}</span><span class="font-mono">{{ formatMoney(insurance.social.sickness.monthly_premium, 'CZK') }}/{{ t('taxReturn.month') }}</span></div>
              </div>
            </div>
            <div class="border border-neutral-200 rounded-md p-3">
              <div class="text-xs font-semibold text-neutral-600 mb-2">{{ t('taxReturn.health') }}</div>
              <div class="text-sm space-y-1">
                <div class="flex justify-between"><span>{{ t('taxReturn.assessment_base') }}</span><span class="font-mono">{{ formatMoney(insurance.health.assessment_base, 'CZK') }}</span></div>
                <div class="flex justify-between font-semibold"><span>{{ t('taxReturn.insurance') }}</span><span class="font-mono">{{ formatMoney(insurance.health.insurance, 'CZK') }}</span></div>
                <div class="flex justify-between"><span>{{ t('taxReturn.balance_due') }}</span><span class="font-mono">{{ formatMoney(insurance.health.balance_due, 'CZK') }}</span></div>
                <div class="flex justify-between"><span>{{ t('taxReturn.monthly_advance') }}</span><span class="font-mono">{{ formatMoney(insurance.health.monthly_advance, 'CZK') }}</span></div>
              </div>
            </div>
            <div class="md:col-span-2 text-xs text-neutral-500">{{ insurance.deadlines.note }}</div>
            <div class="md:col-span-2 flex gap-2">
              <button type="button" @click="downloadInsurancePdf" class="inline-flex items-center gap-2 h-9 px-4 rounded-md border border-neutral-300 text-sm">
                {{ t('taxReturn.download_insurance_pdf') }}
              </button>
              <button type="button" @click="downloadCsszXml" class="inline-flex items-center gap-2 h-9 px-4 rounded-md border border-neutral-300 text-sm">
                {{ t('taxReturn.cssz_xml') }}
              </button>
              <button type="button" @click="downloadHealthPdf" class="inline-flex items-center gap-2 h-9 px-4 rounded-md border border-neutral-300 text-sm">
                {{ t('taxReturn.health_pdf') }}
              </button>
            </div>
          </div>
        </div>

      </section>

      <!-- Zálohy na daň a pojistné (E9) — vlastní záložka: v Exportu je nikdo nehledal. -->
      <section v-show="activeTab === 'zalohy'" class="space-y-4">
        <!-- ── Zálohy na daň a pojistné (E9) ── -->
        <div class="bg-surface border border-neutral-200 rounded-lg p-5">
          <div class="flex items-center justify-between mb-1 flex-wrap gap-2">
            <h3 class="text-sm font-semibold">{{ t('taxReturn.advances_title') }}</h3>
            <div class="flex gap-2 flex-wrap">
              <button type="button" @click="loadAdvances" :disabled="advancesLoading"
                class="inline-flex items-center gap-1.5 h-8 px-3 rounded-md text-sm border border-neutral-300 disabled:opacity-50 whitespace-nowrap">
                {{ advances.length ? t('taxReturn.reload') : t('taxReturn.compute') }}
              </button>
              <button type="button" @click="generateAdvances" :disabled="advancesBusy || !isFinal"
                class="inline-flex items-center gap-1.5 h-8 px-3 rounded-md text-sm border border-neutral-300 disabled:opacity-50 whitespace-nowrap">
                {{ t('taxReturn.advances_generate') }}
              </button>
              <button type="button" @click="generateAdvancesForPeriod" :disabled="advancesBusy"
                class="inline-flex items-center gap-1.5 h-8 px-3 rounded-md text-sm border border-neutral-300 disabled:opacity-50 whitespace-nowrap"
                :title="t('taxReturn.advances_generate_period_hint')">
                {{ t('taxReturn.advances_generate_period') }}
              </button>
              <button type="button" @click="matchAdvances" :disabled="advancesBusy"
                class="inline-flex items-center gap-1.5 h-8 px-3 rounded-md text-sm bg-primary-600 text-white disabled:opacity-50 whitespace-nowrap">
                {{ t('taxReturn.advances_match') }}
              </button>
            </div>
          </div>
          <p class="text-xs text-neutral-500 mb-3">{{ t('taxReturn.advances_hint') }}</p>
          <p v-if="advancesMsg" class="text-xs text-primary-600 mb-2">{{ advancesMsg }}</p>

          <div v-if="advancesLoading" class="text-sm text-neutral-400">{{ t('common.loading') }}…</div>
          <div v-else-if="advances.length" class="overflow-x-auto">
            <div class="flex justify-end mb-2">
              <button type="button" @click="confirmAllAdvances" :disabled="advancesBusy"
                class="h-7 px-2.5 rounded-md text-xs border border-success-300 text-success-700 disabled:opacity-50">
                {{ t('taxReturn.advances_confirm_all') }}
              </button>
            </div>
            <table class="w-full text-sm">
              <thead>
                <tr class="text-left text-xs text-neutral-500 border-b border-neutral-200">
                  <th class="py-1.5 pr-2">{{ t('taxReturn.advance_kind') }}</th>
                  <th class="py-1.5 pr-2">{{ t('taxReturn.advance_due') }}</th>
                  <th class="py-1.5 pr-2 text-right">{{ t('taxReturn.advance_amount') }}</th>
                  <th class="py-1.5 pr-2">{{ t('taxReturn.advance_vs') }}</th>
                  <th class="py-1.5 pr-2">{{ t('taxReturn.advance_status') }}</th>
                  <th class="py-1.5 pr-2 text-right">{{ t('taxReturn.advance_paid') }}</th>
                  <th class="py-1.5 pr-2 text-right">{{ t('taxReturn.advance_actions') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="a in advances" :key="a.id" class="border-b border-neutral-100">
                  <td class="py-1.5 pr-2">{{ advanceKindLabel(a.advance_kind) }}</td>
                  <td class="py-1.5 pr-2 font-mono">{{ a.due_date }}</td>
                  <td class="py-1.5 pr-2 text-right font-mono">
                    <template v-if="editingAmountId === a.id">
                      <input type="number" v-model.number="editAmountValue"
                        class="w-28 h-7 px-2 border border-neutral-300 rounded-md text-sm text-right" />
                      <button type="button" @click="saveEditAmount(a)" :disabled="advancesBusy"
                        class="ml-1 text-xs text-primary-600">{{ t('common.save') }}</button>
                      <button type="button" @click="editingAmountId = null" class="ml-1 text-xs text-neutral-400">×</button>
                    </template>
                    <template v-else>
                      {{ formatMoney(a.amount, 'CZK') }}
                      <button v-if="a.status === 'planned'" type="button" @click="startEditAmount(a)"
                        class="ml-1 text-xs text-neutral-400 hover:text-primary-600">✎</button>
                    </template>
                  </td>
                  <td class="py-1.5 pr-2 font-mono text-neutral-500">{{ a.variable_symbol || '—' }}</td>
                  <td class="py-1.5 pr-2">
                    <span v-if="a.status === 'paid'" class="text-success-600">
                      {{ t('taxReturn.advance_paid_status') }}
                      <span v-if="a.paid_source === 'manual'" class="text-[10px] text-neutral-400">({{ t('taxReturn.advance_paid_manual') }})</span>
                    </span>
                    <span v-else-if="a.is_overdue" class="text-danger-500">{{ t('taxReturn.advance_overdue') }}</span>
                    <span v-else class="text-neutral-500">{{ t('taxReturn.advance_planned') }}</span>
                  </td>
                  <td class="py-1.5 pr-2 text-right font-mono">{{ a.status === 'paid' ? formatMoney(a.paid_amount, 'CZK') : '—' }}</td>
                  <td class="py-1.5 pr-2 text-right whitespace-nowrap">
                    <button v-if="a.status === 'planned'" type="button" @click="confirmAdvance(a)" :disabled="advancesBusy"
                      class="text-xs text-success-700 hover:underline">{{ t('taxReturn.advance_confirm') }}</button>
                    <button v-else-if="a.paid_source === 'manual'" type="button" @click="unconfirmAdvance(a)" :disabled="advancesBusy"
                      class="text-xs text-neutral-500 hover:underline">{{ t('taxReturn.advance_unconfirm') }}</button>
                    <span v-else class="text-xs text-neutral-300">—</span>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
          <EmptyState v-else dense accent="neutral" icon="coin" :title="t('taxReturn.advances_empty')" />
        </div>
      </section>

      <!-- #46 — rozhodnutí FÚ o zálohách (§174 DŘ): taky vlastní záložka, jen pro PO. -->
      <section v-show="activeTab === 'rozhodnuti'" class="space-y-4">

        <!-- ── #46 — rozhodnutí FÚ o zálohách (§174) + předpis placení záloh NAPŘÍČ ROKY (jen daň §38a, PO) ── -->
        <div v-if="type === 'po'" class="bg-surface border border-neutral-200 rounded-lg p-5">
          <div class="flex items-center justify-between mb-1 flex-wrap gap-2">
            <h3 class="text-sm font-semibold">{{ t('taxReturn.overrides_section_title') }}</h3>
            <button type="button" @click="openNewOverride" :disabled="overridesBusy || editingOverrideId === 0"
              class="inline-flex items-center gap-1.5 h-8 px-3 rounded-md text-sm border border-neutral-300 disabled:opacity-50 whitespace-nowrap">
              {{ t('taxReturn.overrides_add') }}
            </button>
          </div>
          <p class="text-xs text-neutral-500 mb-3">{{ t('taxReturn.overrides_section_hint') }}</p>
          <p v-if="overridesMsg" class="text-xs text-primary-600 mb-2">{{ overridesMsg }}</p>

          <!-- Editor rozhodnutí (nové / úprava) -->
          <div v-if="editingOverrideId !== null" class="mb-3 rounded-md border border-neutral-200 bg-neutral-50 p-3 grid grid-cols-2 md:grid-cols-6 gap-2 items-end">
            <label class="text-xs">{{ t('taxReturn.overrides_from') }}
              <input type="date" v-model="overrideForm.effective_from" class="mt-1 w-full h-8 px-2 border border-neutral-300 rounded-md text-sm" /></label>
            <label class="text-xs">{{ t('taxReturn.overrides_to') }}
              <input type="date" v-model="overrideForm.effective_to" :placeholder="t('taxReturn.overrides_to_open')"
                class="mt-1 w-full h-8 px-2 border border-neutral-300 rounded-md text-sm" /></label>
            <label class="text-xs">{{ t('taxReturn.advances_periodicity') }}
              <select v-model="overrideForm.periodicity" class="mt-1 w-full h-8 px-2 border border-neutral-300 rounded-md text-sm">
                <option value="quarterly">{{ t('taxReturn.advances_periodicity_quarterly') }}</option>
                <option value="semiannual">{{ t('taxReturn.advances_periodicity_semiannual') }}</option>
                <option value="annual">{{ t('taxReturn.advances_periodicity_annual') }}</option>
                <option value="none">{{ t('taxReturn.advances_periodicity_none') }}</option>
              </select>
            </label>
            <label class="text-xs">{{ t('taxReturn.advance_amount') }}
              <input type="number" v-model.number="overrideForm.amount" class="mt-1 w-full h-8 px-2 border border-neutral-300 rounded-md text-sm" /></label>
            <label class="text-xs md:col-span-2">{{ t('taxReturn.advances_override_note') }}
              <input type="text" v-model="overrideForm.note" maxlength="255" class="mt-1 w-full h-8 px-2 border border-neutral-300 rounded-md text-sm" /></label>
            <div class="col-span-2 md:col-span-6 flex gap-2">
              <button type="button" @click="saveOverrideEntry" :disabled="overridesBusy"
                class="h-8 px-3 rounded-md text-sm bg-primary-600 text-white disabled:opacity-50">{{ t('common.save') }}</button>
              <button type="button" @click="editingOverrideId = null" :disabled="overridesBusy"
                class="h-8 px-3 rounded-md text-sm border border-neutral-300 disabled:opacity-50">{{ t('common.cancel') }}</button>
            </div>
          </div>

          <!-- Tabulka rozhodnutí FÚ napříč roky -->
          <div v-if="overridesList.length" class="overflow-x-auto mb-5">
            <table class="w-full text-sm">
              <thead>
                <tr class="text-left text-xs text-neutral-500 border-b border-neutral-200">
                  <th class="py-1.5 pr-2">{{ t('taxReturn.overrides_from') }}</th>
                  <th class="py-1.5 pr-2">{{ t('taxReturn.overrides_to') }}</th>
                  <th class="py-1.5 pr-2">{{ t('taxReturn.advances_periodicity') }}</th>
                  <th class="py-1.5 pr-2 text-right">{{ t('taxReturn.advance_amount') }}</th>
                  <th class="py-1.5 pr-2">{{ t('taxReturn.advances_override_note') }}</th>
                  <th class="py-1.5 pr-2 text-right">{{ t('taxReturn.advance_actions') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="o in overridesList" :key="o.id" class="border-b border-neutral-100">
                  <td class="py-1.5 pr-2 font-mono">{{ o.effective_from }}</td>
                  <td class="py-1.5 pr-2 font-mono">{{ o.effective_to || t('taxReturn.overrides_to_open') }}</td>
                  <td class="py-1.5 pr-2">{{ t('taxReturn.advances_periodicity_' + o.periodicity) }}</td>
                  <td class="py-1.5 pr-2 text-right font-mono">{{ formatMoney(o.amount, 'CZK') }}</td>
                  <td class="py-1.5 pr-2 text-neutral-500">{{ o.note || '—' }}</td>
                  <td class="py-1.5 pr-2 text-right whitespace-nowrap">
                    <button type="button" @click="openEditOverride(o)" :disabled="overridesBusy"
                      class="text-xs text-primary-600 hover:underline">{{ t('common.edit') }}</button>
                    <button type="button" @click="deleteOverrideEntry(o)" :disabled="overridesBusy"
                      class="ml-2 text-xs text-danger-600 hover:underline">{{ t('common.delete') }}</button>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
          <EmptyState v-else dense accent="neutral" icon="doc" class="mb-5" :title="t('taxReturn.overrides_empty')" />

          <!-- Předpis placení záloh napříč roky (stav auto-párováním #46) -->
          <h4 class="text-sm font-semibold mb-1">{{ t('taxReturn.overrides_schedule_title') }}</h4>
          <p class="text-xs text-neutral-500 mb-2">{{ t('taxReturn.overrides_schedule_hint') }}</p>
          <div v-if="overridesLoading" class="text-sm text-neutral-400">{{ t('common.loading') }}…</div>
          <div v-else-if="allSchedules.length" class="overflow-x-auto">
            <table class="w-full text-sm">
              <thead>
                <tr class="text-left text-xs text-neutral-500 border-b border-neutral-200">
                  <th class="py-1.5 pr-2">{{ t('taxReturn.advance_due') }}</th>
                  <th class="py-1.5 pr-2 text-right">{{ t('taxReturn.advance_amount') }}</th>
                  <th class="py-1.5 pr-2">{{ t('taxReturn.advance_status') }}</th>
                  <th class="py-1.5 pr-2 text-right">{{ t('taxReturn.advance_paid') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="a in allSchedules" :key="a.id" class="border-b border-neutral-100">
                  <td class="py-1.5 pr-2 font-mono">{{ a.due_date }}</td>
                  <td class="py-1.5 pr-2 text-right font-mono">{{ formatMoney(a.amount, 'CZK') }}</td>
                  <td class="py-1.5 pr-2">
                    <span :class="a.status === 'paid' ? 'text-success-600' : a.is_overdue ? 'text-danger-500' : 'text-neutral-500'">
                      {{ scheduleStatusLabel(a) }}
                      <span v-if="a.status === 'paid' && a.paid_source === 'manual'" class="text-[10px] text-neutral-400">({{ t('taxReturn.advance_paid_manual') }})</span>
                    </span>
                  </td>
                  <td class="py-1.5 pr-2 text-right font-mono">{{ a.status === 'paid' ? formatMoney(a.paid_amount, 'CZK') : '—' }}</td>
                </tr>
              </tbody>
            </table>
          </div>
          <EmptyState v-else dense accent="neutral" icon="coin" :title="t('taxReturn.advances_empty')" />
        </div>
      </section>
    </template>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'
import { documentsApi, type DocItem } from '@/api/documents'
import { payrollQueryId } from '@/pages/payroll/payrollAgendaLinks'
import {
  payrollApi,
  type PayrollInstitutionAccount,
} from '@/api/payroll'
import {
  payrollEnforcementApi,
  pensionEvidenceValues,
  type EnforcementCaseCommand,
  type EnforcementCaseDetail,
  type EnforcementCaseKind,
  type EnforcementCaseStatus,
  type EnforcementCaseSummary,
  type EnforcementClaimCategory,
  type EnforcementClaimPayload,
  type EnforcementDependant,
  type EnforcementMonthEvidence,
  type EnforcementEvidenceScope,
  type EnforcementEvidenceSourceValue,
} from '@/api/payrollEnforcement'
import { eligibleAllowances, evidenceScope } from '@/pages/payroll/enforcementEvidenceScope'
import { btnFilled, btnOutline, btnOutlineSm, disabledTitle, BTN_DISABLED_NOTE, ICONS } from '@/components/ui/buttonStyles'
import EmptyState from '@/components/ui/EmptyState.vue'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import PaginationBar from '@/components/ui/PaginationBar.vue'
import PayrollPersonSearchSelect from '@/components/payroll/PayrollPersonSearchSelect.vue'
// Formátování je sdílené (useFormat) — místní kopie se rozcházely v locale i tvaru.
import { formatMoneyMinor as money } from '@/composables/useFormat'
import { useToast } from '@/composables/useToast'
import { useAuthStore } from '@/stores/auth'
import ColumnPicker from '@/components/ui/ColumnPicker.vue'
import DensityToggle from '@/components/ui/DensityToggle.vue'
import { useTablePrefs, type ColumnDef } from '@/composables/useTablePrefs'
import { appIsoDate } from '@/utils/date'

const { t } = useI18n()
const auth = useAuthStore()
const toast = useToast()
const loading = ref(true)
/*
 * Selhalo načtení? Pak o obsahu nevíme NIC — a to je něco jiného než „nic tu
 * není". Toast s chybou za pár vteřin zmizí a bez tohohle příznaku by na
 * obrazovce zůstal prázdný stav, který lže.
 */
const loadFailed = ref(false)
/*
 * Účty příjemců jsou doplněk formuláře, ne podmínka výpisu — proto se načítají
 * „měkce". Když ale selžou, zůstane prázdný výběr příjemce a uživatel nemá jak
 * zjistit, že za tím není konfigurace, ale výpadek.
 */
const supportFailed = ref(false)
const saving = ref(false)
const cases = ref<EnforcementCaseSummary[]>([])
const total = ref(0)
const pageSize = 20
const offset = ref(0)
const currentPage = computed(() => Math.floor(offset.value / pageSize) + 1)
/**
 * Předvýběr z odkazu na kartě zaměstnance (`/payroll/enforcement?person=7`).
 *
 * Sedí do stávajícího filtru osob, takže je zúžení vidět a jde ho zrušit tam,
 * kde ho uživatel čeká. Neplatné id nic nezúží.
 */
const employeeFilter = ref<number | null>(payrollQueryId(useRoute().query, 'person'))
const statusFilter = ref<EnforcementCaseStatus | ''>('')
const detail = ref<EnforcementCaseDetail | null>(null)
const expandedId = ref<number | null>(null)
const showCreate = ref(false)
const showClaim = ref(false)
const pendingCommand = ref<EnforcementCaseCommand | null>(null)
const transitionReason = ref('')
const documentQuery = ref('')
const documentCandidates = ref<DocItem[]>([])
const selectedDocument = ref<DocItem | null>(null)
const canWrite = computed(() => auth.canWrite('payroll.enforcement'))
const canReadDocuments = computed(() => auth.canRead('documents'))
const canReadPeople = computed(() => auth.canRead('payroll'))
const canManageInsolvency = computed(() => auth.canWrite('payroll.insolvency'))
const canReadPayrollSettings = computed(() => auth.canRead('payroll.settings'))
const recipientAccounts = ref<PayrollInstitutionAccount[]>([])

const COLUMNS: ColumnDef[] = [
  { key: 'employee', labelKey: 'payroll.enforcement.employee', required: true },
  { key: 'status', labelKey: 'payroll.enforcement.status_label' },
  { key: 'case_kind', labelKey: 'payroll.enforcement.case_kind' },
  { key: 'claims', labelKey: 'payroll.enforcement.claims', defaultHidden: true },
  { key: 'balance', labelKey: 'payroll.enforcement.balance' },
  { key: 'actions', labelKey: 'common.detail', required: true },
]
const tbl = useTablePrefs('payroll-enforcement', COLUMNS)
const recipientOptions = computed(() => {
  const seen = new Map<number, PayrollInstitutionAccount>()
  for (const account of recipientAccounts.value) {
    if (account.institution_type !== 'other_recipient') continue
    if (!seen.has(account.institution_id)) seen.set(account.institution_id, account)
  }
  return [...seen.values()]
})
const today = appIsoDate()
const evidencePeriod = ref(today.slice(0, 7))
const monthEvidence = ref<EnforcementMonthEvidence | null>(null)
const dependants = ref<EnforcementDependant[]>([])
/*
 * Měsíční evidence je vedená na OSOBU, ne na případ, a rozsah rejstříku
 * pohledávek závisí na tom, jestli má osoba vůbec z čeho srážet. Filtrovaná
 * stránka seznamu na to neodpoví — u člověka se dvěma exekucemi by při filtru
 * na jeden stav ukázala jen jednu. Proto vlastní, nefiltrovaný dotaz na případy
 * té osoby; `personCasesComplete = false` (výpadek nebo useknutý seznam) drží
 * rozsah otevřený, ať se nezešedne něco, co dokládat je.
 */
const personCases = ref<EnforcementCaseSummary[]>([])
const personCasesComplete = ref(false)
const protectedOverrideCzk = ref('')
const courtAmountCzk = ref('')
const newDependant = ref<{
  dependant_kind: 'dependant' | 'spouse_partner'
  valid_from: string
  valid_to: string | null
  eligibility_verified: boolean
  excluded_for_maintenance: boolean
}>({
  dependant_kind: 'dependant',
  valid_from: today,
  valid_to: null,
  eligibility_verified: false,
  excluded_for_maintenance: false,
})
const newCase = ref<{
  employee_id: number | null
  case_kind: EnforcementCaseKind
  effective_from: string
  // Zúžení z odkazu předplní i nový případ — kdo přišel „zavést exekuci
  // Novákovi", ho nechce vybírat znovu.
}>({ employee_id: employeeFilter.value, case_kind: 'enforcement', effective_from: today })
function emptyClaim(): EnforcementClaimPayload {
  return {
    legal_basis: 'statutory',
    category: 'non_priority',
    outstanding_minor_units: 0,
    maintenance_weight_minor_units: null,
    priority_date: null,
    order_issued_on: null,
    legal_title_verified: false,
    order_or_notice_delivered: false,
    priority_classification_verified: false,
    agreement_verified: false,
    due_monetary_claim_verified: false,
    same_order_as_claim_id: null,
  }
}
const newClaim = ref<EnforcementClaimPayload>(emptyClaim())
const claimAmountCzk = ref('')
const maintenanceWeightCzk = ref('')
const caseKinds: EnforcementCaseKind[] = ['enforcement', 'voluntary_agreement']
const caseStatuses: EnforcementCaseStatus[] = [
  'received', 'withhold_and_hold', 'remit', 'deferred_no_withholding',
  'deferred_hold', 'paid', 'stopped',
]
const statutoryClaimCategories: EnforcementClaimCategory[] = [
  'current_maintenance', 'maintenance_arrears', 'substitute_maintenance',
  'other_priority', 'non_priority',
]
const claimCategories = computed<EnforcementClaimCategory[]>(() =>
  detail.value?.case_kind === 'voluntary_agreement'
    ? ['non_priority']
    : statutoryClaimCategories,
)
const commandByStatus: Partial<Record<EnforcementCaseStatus, EnforcementCaseCommand[]>> = {
  received: ['mark_final', 'stop'],
  withhold_and_hold: ['authorize_remittance', 'defer_no_withholding', 'defer_hold', 'stop'],
  deferred_no_withholding: ['resume_holding', 'resume_remittance', 'stop'],
  deferred_hold: ['resume_holding', 'resume_remittance', 'stop'],
  remit: ['defer_no_withholding', 'defer_hold', 'stop'],
}
const documentCommands = new Set<EnforcementCaseCommand>([
  'mark_final',
  'authorize_remittance',
  'defer_no_withholding',
  'defer_hold',
  'resume_holding',
  'resume_remittance',
  'stop',
])
const reasonCommands = new Set<EnforcementCaseCommand>([
  'defer_no_withholding',
  'defer_hold',
  'stop',
])
const deleteBlockerTranslations: Record<string, string> = {
  claim_exists: 'payroll.enforcement.delete_blocked.claim_exists',
  event_exists: 'payroll.enforcement.delete_blocked.event_exists',
  document_exists: 'payroll.enforcement.delete_blocked.document_exists',
  allocation_exists: 'payroll.enforcement.delete_blocked.allocation_exists',
  ledger_exists: 'payroll.enforcement.delete_blocked.ledger_exists',
  payment_footprint_exists: 'payroll.enforcement.delete_blocked.payment_footprint_exists',
  case_started: 'payroll.enforcement.delete_blocked.case_started',
  concurrent_footprint_exists: 'payroll.enforcement.delete_blocked.concurrent_footprint_exists',
}
let documentSearchTimer: ReturnType<typeof setTimeout> | null = null
let detailRequestSequence = 0
let documentRequestSequence = 0

const transitionCanSubmit = computed(() => {
  const command = pendingCommand.value
  if (!command) return false
  if (documentCommands.has(command) && !selectedDocument.value) return false
  return !reasonCommands.has(command) || transitionReason.value.trim().length > 0
})

const canDeleteUnusedCase = computed(() => {
  const current = detail.value
  return current !== null
    && current.status === 'received'
    && current.claim_count === 0
    && current.claims.length === 0
    && current.events.length === 0
    && current.ledger.length === 0
})

const detailActions = computed<ActionItem[]>(() => [{
  key: 'delete',
  label: t('payroll.enforcement.delete_case'),
  icon: 'trash',
  tier: 'overflow',
  variant: 'danger',
  show: canWrite.value && canDeleteUnusedCase.value,
  disabled: saving.value,
  loading: saving.value,
  run: deleteCase,
}])

/*
 * Proč nejde přechod potvrdit. Obě podmínky mají konkrétní nápravu hned
 * v témž formuláři — obecné „akce není dostupná" by uživateli neřeklo, které
 * z polí nad tlačítkem má doplnit.
 */
const transitionBlockedReason = computed<string | null>(() => {
  const command = pendingCommand.value
  if (!command) return null
  if (documentCommands.has(command) && !selectedDocument.value) {
    return t('payroll.enforcement.transition_blocked_document')
  }
  if (reasonCommands.has(command) && transitionReason.value.trim().length === 0) {
    return t('payroll.enforcement.transition_blocked_reason')
  }
  return null
})

watch(documentQuery, (query) => {
  if (documentSearchTimer) clearTimeout(documentSearchTimer)
  if (query.trim().length < 2 || selectedDocument.value) {
    documentCandidates.value = []
    return
  }
  documentSearchTimer = setTimeout(async () => {
    const sequence = ++documentRequestSequence
    try {
      const candidates = await documentsApi.search(query.trim())
      if (sequence === documentRequestSequence && query === documentQuery.value) {
        documentCandidates.value = candidates
      }
    } catch {
      if (sequence === documentRequestSequence) documentCandidates.value = []
    }
  }, 220)
})

function minorUnits(value: string, required = true): number | null {
  const normalized = value.trim().replace(/\s/g, '').replace(',', '.')
  if (!normalized && !required) return null
  if (!/^\d+(?:\.\d{1,2})?$/.test(normalized)) {
    throw new Error(t('payroll.enforcement.validation.amount'))
  }
  return Math.round(Number(normalized) * 100)
}

function statusClass(status: EnforcementCaseStatus): string {
  if (status === 'paid') return 'bg-success-50 text-success-600'
  if (status === 'stopped') return 'bg-neutral-100 text-neutral-600'
  if (status.startsWith('deferred')) return 'bg-warning-50 text-warning-700'
  if (status === 'remit') return 'bg-primary-50 text-primary-700'
  return 'bg-payroll-50 text-payroll-600'
}

async function load() {
  loading.value = true
  loadFailed.value = false
  supportFailed.value = false
  try {
    const page = await payrollEnforcementApi.casesPage({
      ...(employeeFilter.value ? { employee_id: employeeFilter.value } : {}),
      ...(statusFilter.value ? { status: statusFilter.value } : {}),
      limit: pageSize,
      offset: offset.value,
    })
    cases.value = page.cases
    total.value = page.total
    if (canReadPayrollSettings.value) {
      try {
        recipientAccounts.value = await payrollApi.institutionAccounts()
      } catch {
        recipientAccounts.value = []
        supportFailed.value = true
      }
    }
  } catch {
    loadFailed.value = true
    toast.error(t('payroll.enforcement.load_failed'))
  } finally {
    loading.value = false
  }
}

/*
 * Rozbalený panel patří ke konkrétnímu řádku seznamu. Po přestránkování ani po
 * přefiltrování ten řádek na obrazovce být nemusí — otevřený detail by pak
 * ukazoval případ, který v seznamu nikdo nevidí.
 */
function collapseDetail() {
  ++detailRequestSequence
  closeTransition()
  showClaim.value = false
  expandedId.value = null
  detail.value = null
  monthEvidence.value = null
  dependants.value = []
  personCases.value = []
  personCasesComplete.value = false
}

// Stránkuje sdílená `PaginationBar` (číslo stránky od jedné); server zná offset.
function goToPage(nextPage: number) {
  offset.value = Math.max(0, (nextPage - 1) * pageSize)
  collapseDetail()
  void load()
}

async function selectCase(item: EnforcementCaseSummary) {
  if (expandedId.value === item.id) {
    collapseDetail()
    return
  }
  const sequence = ++detailRequestSequence
  closeTransition()
  showClaim.value = false
  newClaim.value = emptyClaim()
  claimAmountCzk.value = ''
  maintenanceWeightCzk.value = ''
  monthEvidence.value = null
  dependants.value = []
  personCases.value = []
  personCasesComplete.value = false
  expandedId.value = item.id
  detail.value = null
  try {
    const loaded = await payrollEnforcementApi.detail(item.id)
    if (sequence !== detailRequestSequence || expandedId.value !== item.id) return
    detail.value = loaded
    await loadMonthlyEvidence(loaded.employee_id, sequence)
  } catch {
    if (sequence !== detailRequestSequence) return
    expandedId.value = null
    toast.error(t('payroll.enforcement.detail_failed'))
  }
}

async function loadMonthlyEvidence(employeeId: number, sequence = detailRequestSequence) {
  if (!auth.canRead('payroll.insolvency')) return
  try {
    const [evidence, loadedDependants, loadedCases] = await Promise.all([
      payrollEnforcementApi.monthEvidence(employeeId, evidencePeriod.value),
      payrollEnforcementApi.dependants(employeeId),
      // Serverový strop je 100; při jeho dosažení se rozsah radši nezužuje.
      payrollEnforcementApi.casesPage({ employee_id: employeeId, limit: 100, offset: 0 })
        .catch(() => null),
    ])
    if (sequence !== detailRequestSequence || detail.value?.employee_id !== employeeId) return
    personCases.value = loadedCases?.cases ?? []
    personCasesComplete.value = loadedCases !== null
      && loadedCases.cases.length >= loadedCases.total
    monthEvidence.value = evidence
    protectedOverrideCzk.value = evidence.protected_amount_override_minor_units === null
      ? ''
      : String(evidence.protected_amount_override_minor_units / 100)
    courtAmountCzk.value = evidence.court_determined_amount_minor_units === null
      ? ''
      : String(evidence.court_determined_amount_minor_units / 100)
    dependants.value = loadedDependants
  } catch {
    if (sequence === detailRequestSequence) {
      toast.error(t('payroll.enforcement.month_evidence_load_failed'))
    }
  }
}

/*
 * Tři měsíční potvrzení, tři různá pravidla rozsahu — proto se kreslí smyčkou
 * nad rozsahem, ne třemi ručně opsanými checkboxy, které by se rozešly.
 */
const MONTH_EVIDENCE_ROWS = [
  { key: 'claim_register', field: 'claim_register_evidence_complete' },
  { key: 'dependants', field: 'dependants_evidence_complete' },
  { key: 'spouse', field: 'spouse_evidence_complete' },
] as const satisfies readonly {
  key: keyof EnforcementEvidenceScope
  field: 'claim_register_evidence_complete'
    | 'dependants_evidence_complete'
    | 'spouse_evidence_complete'
}[]

const monthEvidenceAllowances = computed(() =>
  eligibleAllowances(dependants.value, evidencePeriod.value))

const monthEvidenceScope = computed<EnforcementEvidenceScope | null>(() => {
  const evidence = monthEvidence.value
  if (!evidence) return null
  return evidenceScope({
    period: evidencePeriod.value,
    cases: personCases.value,
    casesComplete: personCasesComplete.value,
    dependants: dependants.value,
    evidence,
  })
})

/**
 * Má se checkbox nabízet k vyplnění? Jen `not_applicable` znamená „doklad by
 * nedokládal nic". `nothing_withheld` zůstává aktivní schválně: doložením se
 * otevře strop dobrovolné dohody o srážkách, takže tam je co dělat.
 */
function evidenceActionable(source: EnforcementEvidenceSourceValue | undefined): boolean {
  return source !== 'not_applicable'
}

/**
 * Věta pod checkboxem. U `declared` i `missing` mlčí — tam stav říká sám
 * checkbox a další text by jen šuměl.
 */
function evidenceScopeNote(key: keyof EnforcementEvidenceScope): string | null {
  const source = monthEvidenceScope.value?.[key]
  const prefix = 'payroll.enforcement.month_evidence.scope.'
  if (source === 'nothing_withheld') return t(`${prefix}nothing_withheld`)
  if (source !== 'not_applicable') return null
  if (key === 'claim_register') return t(`${prefix}claim_register_idle`)
  // Uplatněný nárok, který přebilo rozhodnutí soudu, je jiný důvod než nárok,
  // který nikdo neuplatnil — náprava se u nich liší.
  const claimed = key === 'spouse'
    ? monthEvidenceAllowances.value.spouse
    : monthEvidenceAllowances.value.dependants > 0
  return t(`${prefix}${claimed ? 'allowance_multiple_payers' : 'allowance_not_claimed'}`)
}

async function createCase() {
  if (!newCase.value.employee_id) return
  saving.value = true
  try {
    const created = await payrollEnforcementApi.create({
      employee_id: newCase.value.employee_id,
      case_kind: newCase.value.case_kind,
      effective_from: newCase.value.effective_from,
    })
    showCreate.value = false
    await load()
    const summary = cases.value.find(item => item.id === created.id)
    if (summary) await selectCase(summary)
    toast.success(t('payroll.enforcement.case_created'))
  } catch (error: any) {
    toast.error(error?.response?.data?.error?.message || t('payroll.enforcement.save_failed'))
  } finally {
    saving.value = false
  }
}

async function deleteCase() {
  const current = detail.value
  if (!current || !canDeleteUnusedCase.value) return
  if (!window.confirm(t('payroll.enforcement.delete_confirm'))) return
  saving.value = true
  try {
    await payrollEnforcementApi.deleteCase(current.id, current.row_version)
    collapseDetail()
    await load()
    toast.success(t('payroll.enforcement.case_deleted'))
  } catch (error: any) {
    await handleMutationError(error)
  } finally {
    saving.value = false
  }
}

function updateSummary(updated: EnforcementCaseDetail) {
  const index = cases.value.findIndex(item => item.id === updated.id)
  if (index >= 0) cases.value[index] = updated
}

async function handleMutationError(error: any) {
  const apiError = error?.response?.data?.error
  if (apiError?.code === 'row_version_conflict' && expandedId.value) {
    const refreshed = await payrollEnforcementApi.detail(expandedId.value)
    detail.value = refreshed
    updateSummary(refreshed)
    toast.warning(t('payroll.enforcement.conflict'))
  } else if (apiError?.code === 'enforcement_case_delete_blocked') {
    const translation = deleteBlockerTranslations[String(apiError.blocker ?? '')]
      ?? 'payroll.enforcement.delete_blocked.other'
    toast.error(t(translation))
  } else {
    toast.error(apiError?.message || t('payroll.enforcement.save_failed'))
  }
}

async function saveEvidence() {
  const current = detail.value
  if (!current) return
  saving.value = true
  try {
    const updated = await payrollEnforcementApi.updateEvidence(current.id, {
      evidence_complete: current.evidence_complete,
      recipient_verified: current.recipient_verified,
      row_version: current.row_version,
      recipient_institution_id: current.recipient_institution_id,
    })
    detail.value = updated
    updateSummary(updated)
    toast.success(t('payroll.enforcement.evidence_saved'))
  } catch (error: any) {
    await handleMutationError(error)
  } finally {
    saving.value = false
  }
}

function closeTransition() {
  ++documentRequestSequence
  pendingCommand.value = null
  transitionReason.value = ''
  documentQuery.value = ''
  documentCandidates.value = []
  selectedDocument.value = null
}

async function saveMonthEvidence() {
  const current = detail.value
  const evidence = monthEvidence.value
  if (!current || !evidence) return
  saving.value = true
  try {
    const {
      id: _id,
      employee_id: _employeeId,
      period_start: _periodStart,
      ...payload
    } = evidence
    payload.protected_amount_override_minor_units = minorUnits(
      protectedOverrideCzk.value,
      false,
    )
    payload.court_determined_amount_minor_units = minorUnits(
      courtAmountCzk.value,
      false,
    )
    monthEvidence.value = await payrollEnforcementApi.saveMonthEvidence(
      current.employee_id,
      evidencePeriod.value,
      payload,
    )
    toast.success(t('payroll.enforcement.month_evidence_saved'))
  } catch (error: any) {
    toast.error(error?.response?.data?.error?.message || t('payroll.enforcement.save_failed'))
  } finally {
    saving.value = false
  }
}

async function addDependant() {
  const current = detail.value
  if (!current) return
  saving.value = true
  try {
    await payrollEnforcementApi.addDependant(current.employee_id, newDependant.value)
    dependants.value = await payrollEnforcementApi.dependants(current.employee_id)
    newDependant.value = {
      dependant_kind: 'dependant',
      valid_from: today,
      valid_to: null,
      eligibility_verified: false,
      excluded_for_maintenance: false,
    }
    toast.success(t('payroll.enforcement.dependant_created'))
  } catch (error: any) {
    toast.error(error?.response?.data?.error?.message || t('payroll.enforcement.save_failed'))
  } finally {
    saving.value = false
  }
}

function selectDecisionDocument(document: DocItem) {
  selectedDocument.value = document
  documentQuery.value = document.title
  documentCandidates.value = []
}

async function openTransition(command: EnforcementCaseCommand) {
  if (!documentCommands.has(command) && !reasonCommands.has(command)) {
    if (!window.confirm(t(`payroll.enforcement.commands.confirm.${command}`))) return
    await transition(command)
    return
  }
  closeTransition()
  pendingCommand.value = command
}

async function transition(command = pendingCommand.value) {
  const current = detail.value
  if (!current || !command) return
  saving.value = true
  try {
    const updated = await payrollEnforcementApi.transition(current.id, command, {
      row_version: current.row_version,
      reason: reasonCommands.has(command) ? transitionReason.value.trim() : null,
      decision_document_id: documentCommands.has(command)
        ? selectedDocument.value?.id ?? null
        : null,
    })
    detail.value = updated
    updateSummary(updated)
    closeTransition()
    toast.success(t('payroll.enforcement.transition_saved'))
  } catch (error: any) {
    await handleMutationError(error)
  } finally {
    saving.value = false
  }
}

function commandVariant(command: EnforcementCaseCommand) {
  if (command === 'stop') return btnOutline('danger')
  if (command.startsWith('defer')) return btnOutline('warning')
  if (command === 'authorize_remittance' || command === 'resume_remittance') {
    return btnOutline('success')
  }
  return btnOutline('primary')
}

async function addClaim() {
  const current = detail.value
  if (!current) return
  saving.value = true
  try {
    const amount = minorUnits(claimAmountCzk.value)
    if (amount === null) return
    await payrollEnforcementApi.addClaim(current.id, {
      ...newClaim.value,
      legal_basis: current.case_kind === 'voluntary_agreement'
        ? 'voluntary_agreement'
        : 'statutory',
      outstanding_minor_units: amount,
      maintenance_weight_minor_units: minorUnits(maintenanceWeightCzk.value, false),
    })
    const updated = await payrollEnforcementApi.detail(current.id)
    detail.value = updated
    updateSummary(updated)
    showClaim.value = false
    claimAmountCzk.value = ''
    maintenanceWeightCzk.value = ''
    newClaim.value = emptyClaim()
    toast.success(t('payroll.enforcement.claim_created'))
  } catch (error: any) {
    toast.error(error?.response?.data?.error?.message || error?.message || t('payroll.enforcement.save_failed'))
  } finally {
    saving.value = false
  }
}

function claimVerified(claim: EnforcementCaseDetail['claims'][number]): boolean {
  if (!claim.priority_classification_verified || !claim.priority_date) return false
  if (claim.legal_basis === 'voluntary_agreement') return claim.agreement_verified
  return claim.legal_title_verified
    && claim.order_or_notice_delivered
    && claim.due_monetary_claim_verified
    && Boolean(claim.order_issued_on)
}

watch(evidencePeriod, () => {
  if (detail.value) void loadMonthlyEvidence(detail.value.employee_id)
})

watch([employeeFilter, statusFilter], () => {
  // Zúžený výběr má míň stránek; třetí stránka by po přefiltrování ukázala prázdno.
  offset.value = 0
  collapseDetail()
  void load()
})

onMounted(load)
</script>

<template>
  <div class="space-y-6">
    <header class="flex flex-wrap items-start justify-between gap-3">
      <div>
        <h1 class="text-2xl font-semibold text-neutral-900">{{ t('payroll.enforcement.title') }}</h1>
        <p class="mt-1 max-w-3xl text-sm text-neutral-500">{{ t('payroll.enforcement.subtitle') }}</p>
      </div>
      <button v-if="canWrite && canReadPeople" :class="btnFilled('primary')" :aria-expanded="showCreate" @click="showCreate = !showCreate">
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.plus" /></svg>
        {{ t('payroll.enforcement.add_case') }}
      </button>
    </header>

    <section class="rounded-xl border border-payroll-500/30 bg-payroll-50 p-4 text-sm text-neutral-700">
      {{ t('payroll.enforcement.security_hint') }}
    </section>

    <!--
      Lidé a účty se načítají „měkce" (chyba nepotopí výpis případů). Když ale
      selžou, zůstane výběr zaměstnance prázdný a bez téhle věty to vypadá jako
      chybějící nastavení, ne jako výpadek.
    -->
    <p
      v-if="showCreate && supportFailed"
      class="rounded-xl border border-warning-500/40 bg-warning-50 p-3 text-sm text-warning-800"
      role="alert"
      data-test="support-failed"
    >
      {{ t('payroll.enforcement.support_failed') }}
    </p>

    <form v-if="showCreate" class="grid grid-cols-1 gap-4 rounded-xl border border-neutral-200 bg-surface p-4 shadow-sm sm:grid-cols-2 lg:grid-cols-4" @submit.prevent="createCase">
      <label class="text-xs font-medium text-neutral-600">{{ t('payroll.enforcement.employee') }}
        <PayrollPersonSearchSelect
          v-model="newCase.employee_id"
          class="mt-1"
          :label="t('payroll.enforcement.employee')"
          :placeholder="t('payroll.enforcement.select_employee')"
          :clearable="false"
          required
        />
      </label>
      <label class="text-xs font-medium text-neutral-600">{{ t('payroll.enforcement.case_kind') }}
        <select v-model="newCase.case_kind" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm">
          <option v-for="kind in caseKinds" :key="kind" :value="kind">{{ t(`payroll.enforcement.kinds.${kind}`) }}</option>
        </select>
      </label>
      <label class="text-xs font-medium text-neutral-600">{{ t('payroll.enforcement.effective_from') }}
        <input v-model="newCase.effective_from" required type="date" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm">
      </label>
      <div class="flex flex-wrap items-end justify-end gap-2">
        <button type="button" :class="btnOutline('neutral')" @click="showCreate = false"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.x" /></svg>{{ t('common.cancel') }}</button>
        <button type="submit" :class="btnOutline('success')" :disabled="saving || !newCase.employee_id"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.check" /></svg>{{ t('common.save') }}</button>
      </div>
    </form>

    <!--
      Seznam je stránkovaný, takže zúžení musí jít na server: v prohlížeči by
      filtr hledal jen v načtené stránce a případ ze druhé by prohlásil za
      neexistující.
    -->
    <div class="flex flex-wrap items-end gap-3">
      <label v-if="canReadPeople" class="text-xs font-medium text-neutral-600">
        {{ t('payroll.enforcement.employee') }}
        <PayrollPersonSearchSelect
          v-model="employeeFilter"
          data-test="enforcement-employee-filter"
          class="mt-1 min-w-64"
          :label="t('payroll.enforcement.employee')"
          :placeholder="t('payroll.enforcement.all_employees')"
        />
      </label>
      <label class="text-xs font-medium text-neutral-600">
        {{ t('payroll.enforcement.status_label') }}
        <select v-model="statusFilter" data-test="enforcement-status-filter" class="mt-1 block w-full min-w-40 rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm">
          <option value="">{{ t('common.all') }}</option>
          <option v-for="status in caseStatuses" :key="status" :value="status">{{ t(`payroll.enforcement.status.${status}`) }}</option>
        </select>
      </label>
    </div>

    <section class="rounded-xl border border-neutral-200 bg-surface shadow-sm">
      <div v-if="loading" class="space-y-3 p-4 sm:p-6"><div v-for="index in 4" :key="index" class="h-16 animate-pulse rounded-lg bg-neutral-100" /></div>
      <EmptyState
        v-else-if="loadFailed"
        variant="failed"
        dense
        data-test="load-failed"
        :message="t('payroll.enforcement.load_failed_hint')"
        @action="load"
      />
      <div v-else-if="cases.length === 0" class="p-8 text-center">
        <h2 class="font-semibold text-neutral-900">{{ t('payroll.enforcement.empty_title') }}</h2>
        <p class="mt-1 text-sm text-neutral-500">{{ t('payroll.enforcement.empty_description') }}</p>
      </div>
      <template v-else>
        <div class="hidden md:block">
          <div class="flex flex-wrap items-center justify-end gap-2 border-b border-neutral-200 px-4 py-2">
            <ColumnPicker class="hidden md:block" :ctrl="tbl" />
            <DensityToggle class="hidden md:block" :ctrl="tbl" />
          </div>
          <div class="overflow-x-auto">
          <table class="min-w-full divide-y divide-neutral-200 text-sm" :class="tbl.densityClass.value">
            <thead><tr class="text-left text-xs uppercase tracking-wide text-neutral-500"><th v-if="tbl.isVisible('employee')" class="px-4 py-3">{{ t('payroll.enforcement.employee') }}</th><th v-if="tbl.isVisible('status')" class="px-4 py-3">{{ t('payroll.enforcement.status_label') }}</th><th v-if="tbl.isVisible('case_kind')" class="px-4 py-3">{{ t('payroll.enforcement.case_kind') }}</th><th v-if="tbl.isVisible('claims')" class="px-4 py-3 text-right">{{ t('payroll.enforcement.claims') }}</th><th v-if="tbl.isVisible('balance')" class="px-4 py-3 text-right">{{ t('payroll.enforcement.balance') }}</th><th v-if="tbl.isVisible('actions')" class="px-4 py-3"><span class="sr-only">{{ t('common.detail') }}</span></th></tr></thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="item in cases" :key="item.id" :class="expandedId === item.id ? 'bg-payroll-50/50' : ''">
                <td v-if="tbl.isVisible('employee')" class="px-4 py-3 font-medium text-neutral-900">{{ item.full_name }}</td>
                <td v-if="tbl.isVisible('status')" class="px-4 py-3"><span class="rounded-full px-2 py-1 text-xs font-medium" :class="statusClass(item.status)">{{ t(`payroll.enforcement.status.${item.status}`) }}</span></td>
                <td v-if="tbl.isVisible('case_kind')" class="px-4 py-3 text-neutral-600">{{ t(`payroll.enforcement.kinds.${item.case_kind}`) }}</td>
                <td v-if="tbl.isVisible('claims')" class="px-4 py-3 text-right">{{ item.claim_count }}</td>
                <td v-if="tbl.isVisible('balance')" class="px-4 py-3 text-right font-medium">{{ money(item.outstanding_minor_units) }}</td>
                <td v-if="tbl.isVisible('actions')" class="px-4 py-3 text-right"><button :class="btnOutlineSm('neutral')" :data-test="`enforcement-detail-${item.id}`" @click="selectCase(item)"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.doc" /></svg>{{ t(expandedId === item.id ? 'common.close' : 'common.detail') }}</button></td>
              </tr>
            </tbody>
          </table>
          </div>
        </div>
        <div class="space-y-3 p-4 md:hidden">
          <article v-for="item in cases" :key="item.id" class="rounded-lg border border-neutral-200 p-4" :class="expandedId === item.id ? 'bg-payroll-50/50' : ''">
            <div class="flex flex-wrap items-start justify-between gap-2"><h2 class="font-semibold text-neutral-900">{{ item.full_name }}</h2><span class="rounded-full px-2 py-1 text-xs font-medium" :class="statusClass(item.status)">{{ t(`payroll.enforcement.status.${item.status}`) }}</span></div>
            <dl class="mt-3 grid grid-cols-2 gap-3 text-sm"><div><dt class="text-xs text-neutral-500">{{ t('payroll.enforcement.case_kind') }}</dt><dd>{{ t(`payroll.enforcement.kinds.${item.case_kind}`) }}</dd></div><div><dt class="text-xs text-neutral-500">{{ t('payroll.enforcement.balance') }}</dt><dd class="font-medium">{{ money(item.outstanding_minor_units) }}</dd></div></dl>
            <button :class="[btnOutline('neutral'), 'mt-4']" @click="selectCase(item)"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.doc" /></svg>{{ t(expandedId === item.id ? 'common.close' : 'common.detail') }}</button>
          </article>
        </div>
      </template>
      <PaginationBar
        v-if="!loading && !loadFailed"
        data-test="enforcement-pagination"
        embedded
        :page="currentPage"
        :per-page="pageSize"
        :total="total"
        @update:page="goToPage"
      />
    </section>

    <section v-if="expandedId" data-test="enforcement-detail-panel" class="rounded-xl border border-neutral-200 bg-neutral-50 p-4 shadow-sm sm:p-6">
      <div v-if="!detail" class="h-28 animate-pulse rounded-lg bg-neutral-100" />
      <div v-else class="space-y-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div>
            <div class="flex flex-wrap items-center gap-2"><h2 class="text-lg font-semibold text-neutral-900">{{ detail.full_name }}</h2><span class="rounded-full px-2 py-1 text-xs font-medium" :class="statusClass(detail.status)">{{ t(`payroll.enforcement.status.${detail.status}`) }}</span></div>
            <p class="mt-1 text-xs text-neutral-500">{{ t('payroll.enforcement.case_number', { id: detail.id }) }} · {{ detail.effective_from }}</p>
          </div>
          <div v-if="canWrite" class="flex flex-wrap gap-2">
            <button v-for="command in (commandByStatus[detail.status] || [])" :key="command" :class="commandVariant(command)" :disabled="saving || (documentCommands.has(command) && !canReadDocuments)" :title="documentCommands.has(command) && !canReadDocuments ? t('payroll.enforcement.document_permission_required') : undefined" @click="openTransition(command)">
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="command === 'stop' ? ICONS.x : ICONS.cycle" /></svg>{{ t(`payroll.enforcement.commands.${command}`) }}
            </button>
            <ActionBar :actions="detailActions" />
          </div>
        </div>

        <form v-if="pendingCommand" class="rounded-lg border border-payroll-500/30 bg-surface p-4 shadow-sm" @submit.prevent="transition()">
          <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
              <h3 class="font-medium text-neutral-900">{{ t(`payroll.enforcement.commands.${pendingCommand}`) }}</h3>
              <p class="mt-1 text-xs text-neutral-500">{{ t(`payroll.enforcement.commands.confirm.${pendingCommand}`) }}</p>
            </div>
            <button type="button" :class="btnOutlineSm('neutral')" @click="closeTransition"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.x" /></svg>{{ t('common.close') }}</button>
          </div>
          <div class="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-2">
            <label v-if="documentCommands.has(pendingCommand)" class="relative text-xs font-medium text-neutral-600">
              {{ t('payroll.enforcement.decision_document') }}
              <input v-model="documentQuery" :readonly="selectedDocument !== null" required type="search" autocomplete="off" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm" :placeholder="t('payroll.enforcement.decision_document_search')">
              <button v-if="selectedDocument" type="button" class="absolute right-2 top-7 rounded p-1 text-neutral-400 hover:text-danger-600" :title="t('common.remove')" @click="selectedDocument = null; documentQuery = ''"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.x" /></svg></button>
              <ul v-if="documentCandidates.length" class="absolute z-20 mt-1 max-h-56 w-full overflow-auto rounded-md border border-neutral-200 bg-surface shadow-lg">
                <li v-for="document in documentCandidates" :key="document.id"><button type="button" class="flex w-full items-center justify-between gap-3 px-3 py-2 text-left text-sm hover:bg-payroll-50" @click="selectDecisionDocument(document)"><span class="truncate">{{ document.title }}</span><span class="shrink-0 text-xs uppercase text-neutral-400">{{ document.doc_type }}</span></button></li>
              </ul>
            </label>
            <label v-if="reasonCommands.has(pendingCommand)" class="text-xs font-medium text-neutral-600">
              {{ t('payroll.enforcement.transition_reason') }}
              <textarea v-model="transitionReason" required rows="3" maxlength="500" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm" />
            </label>
          </div>
          <div class="mt-4 flex flex-wrap justify-end gap-2">
            <button type="button" :class="btnOutline('neutral')" @click="closeTransition"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.x" /></svg>{{ t('common.cancel') }}</button>
            <button type="submit" data-test="transition-apply" :class="pendingCommand === 'stop' ? btnFilled('danger') : btnFilled('primary')" :disabled="saving || !transitionCanSubmit" :title="disabledTitle(transitionBlockedReason !== null, transitionBlockedReason)"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.check" /></svg>{{ t('payroll.enforcement.apply_transition') }}</button>
            <p v-if="transitionBlockedReason" :class="[BTN_DISABLED_NOTE, 'w-full text-right']" data-test="transition-apply-blocked">{{ transitionBlockedReason }}</p>
          </div>
        </form>

        <div class="grid grid-cols-1 gap-4 xl:grid-cols-2">
          <section class="rounded-lg border border-neutral-200 bg-surface p-4">
            <div class="flex flex-wrap items-start justify-between gap-3"><div><h3 class="font-medium text-neutral-900">{{ t('payroll.enforcement.evidence_title') }}</h3><p class="mt-1 text-xs text-neutral-500">{{ t('payroll.enforcement.evidence_hint') }}</p></div><button v-if="canWrite" :class="btnOutline('success')" :disabled="saving" @click="saveEvidence"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.check" /></svg>{{ t('common.save') }}</button></div>
            <div class="mt-3 flex flex-wrap gap-x-6 gap-y-3"><label class="flex items-center gap-2 text-sm text-neutral-700"><input v-model="detail.evidence_complete" :disabled="!canWrite" type="checkbox" class="rounded border-neutral-300 text-payroll-600">{{ t('payroll.enforcement.evidence_complete') }}</label><label class="flex items-center gap-2 text-sm text-neutral-700"><input v-model="detail.recipient_verified" :disabled="!canWrite" type="checkbox" class="rounded border-neutral-300 text-payroll-600">{{ t('payroll.enforcement.recipient_verified') }}</label></div>
            <label v-if="canReadPayrollSettings" class="mt-3 block text-xs font-medium text-neutral-600">
              {{ t('payroll.enforcement.recipient_account') }}
              <select v-model="detail.recipient_institution_id" :disabled="!canWrite" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm">
                <option :value="null">{{ t('payroll.enforcement.recipient_account_none') }}</option>
                <option v-for="account in recipientOptions" :key="account.institution_id" :value="account.institution_id">{{ account.institution_name }} · {{ account.bank_account_masked }}</option>
              </select>
              <span class="mt-1 block text-xs font-normal text-neutral-500">{{ t('payroll.enforcement.recipient_account_hint') }}</span>
            </label>
          </section>
          <section class="rounded-lg border border-neutral-200 bg-surface p-4">
            <h3 class="font-medium text-neutral-900">{{ t('payroll.enforcement.ledger') }}</h3>
            <dl v-if="detail.ledger.length" class="mt-3 space-y-2 text-sm"><div v-for="entry in detail.ledger" :key="entry.id" class="flex flex-wrap justify-between gap-2 border-b border-neutral-100 pb-2"><dt class="text-neutral-600">{{ t(`payroll.enforcement.ledger_kind.${entry.entry_kind}`) }}</dt><dd class="font-medium text-neutral-900">{{ money(entry.amount_minor_units) }}</dd></div></dl>
            <p v-else class="mt-3 text-sm text-neutral-500">{{ t('payroll.enforcement.no_ledger') }}</p>
          </section>
        </div>

        <section v-if="detail.settlement" class="rounded-lg border border-neutral-200 bg-surface p-4">
          <div>
            <h3 class="font-medium text-neutral-900">{{ t('payroll.enforcement.settlement.title') }}</h3>
            <p class="mt-1 text-xs text-neutral-500">{{ t('payroll.enforcement.settlement.hint') }}</p>
          </div>
          <dl class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-5">
            <div class="rounded-md border border-neutral-200 px-3 py-2"><dt class="text-xs text-neutral-500">{{ t('payroll.enforcement.settlement.withheld') }}</dt><dd class="mt-1 font-medium text-neutral-900">{{ money(detail.settlement.withheld_minor) }}</dd></div>
            <div class="rounded-md border border-neutral-200 px-3 py-2"><dt class="text-xs text-neutral-500">{{ t('payroll.enforcement.settlement.held') }}</dt><dd class="mt-1 font-medium text-warning-700">{{ money(detail.settlement.held_minor) }}</dd></div>
            <div class="rounded-md border border-neutral-200 px-3 py-2"><dt class="text-xs text-neutral-500">{{ t('payroll.enforcement.settlement.liability') }}</dt><dd class="mt-1 font-medium text-neutral-900">{{ money(detail.settlement.liability_minor) }}</dd></div>
            <div class="rounded-md border border-neutral-200 px-3 py-2"><dt class="text-xs text-neutral-500">{{ t('payroll.enforcement.settlement.remitted') }}</dt><dd class="mt-1 font-medium text-success-700">{{ money(detail.settlement.settled_minor) }}</dd></div>
            <div class="rounded-md border border-neutral-200 px-3 py-2"><dt class="text-xs text-neutral-500">{{ t('payroll.enforcement.settlement.remaining') }}</dt><dd class="mt-1 font-medium text-neutral-900">{{ money(detail.settlement.remaining_minor) }}</dd></div>
          </dl>
          <p v-if="detail.settlement.held_minor > 0" class="mt-3 text-xs text-warning-700">{{ t('payroll.enforcement.settlement.held_hint') }}</p>
          <h4 class="mt-4 text-sm font-medium text-neutral-900">{{ t('payroll.enforcement.settlement.per_claim') }}</h4>
          <ul v-if="detail.settlement.claims.length" class="mt-2 space-y-2">
            <li v-for="claim in detail.settlement.claims" :key="claim.claim_id" class="rounded-md border border-neutral-200 px-3 py-2">
              <div class="flex flex-wrap items-baseline justify-between gap-2">
                <span class="text-sm font-medium text-neutral-900">{{ t(`payroll.enforcement.categories.${claim.category}`) }}</span>
                <span class="text-xs text-neutral-500">{{ claim.priority_date || '—' }}<span v-if="!claim.is_active"> · {{ t('payroll.enforcement.settlement.inactive') }}</span></span>
              </div>
              <dl class="mt-2 grid grid-cols-2 gap-2 text-xs sm:grid-cols-4">
                <div><dt class="text-neutral-500">{{ t('payroll.enforcement.settlement.withheld') }}</dt><dd class="font-medium text-neutral-900">{{ money(claim.withheld_minor) }}</dd></div>
                <div><dt class="text-neutral-500">{{ t('payroll.enforcement.settlement.held') }}</dt><dd class="font-medium text-warning-700">{{ money(claim.held_minor) }}</dd></div>
                <div><dt class="text-neutral-500">{{ t('payroll.enforcement.settlement.remitted') }}</dt><dd class="font-medium text-success-700">{{ money(claim.settled_minor) }}</dd></div>
                <div><dt class="text-neutral-500">{{ t('payroll.enforcement.settlement.remaining') }}</dt><dd class="font-medium text-neutral-900">{{ money(claim.remaining_minor) }}</dd></div>
              </dl>
            </li>
          </ul>
          <p v-else class="mt-2 text-sm text-neutral-500">{{ t('payroll.enforcement.settlement.empty') }}</p>
        </section>

        <section v-if="canManageInsolvency && monthEvidence" class="rounded-lg border border-neutral-200 bg-surface p-4">
          <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
              <h3 class="font-medium text-neutral-900">{{ t('payroll.enforcement.month_evidence_title') }}</h3>
              <p class="mt-1 text-xs text-neutral-500">{{ t('payroll.enforcement.month_evidence_hint') }}</p>
            </div>
            <div class="flex flex-wrap items-end gap-2">
              <label class="text-xs text-neutral-600">{{ t('payroll.enforcement.period') }}<input v-model="evidencePeriod" type="month" class="mt-1 block rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm"></label>
              <button :class="btnOutline('success')" :disabled="saving" @click="saveMonthEvidence"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.check" /></svg>{{ t('common.save') }}</button>
            </div>
          </div>
          <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
            <!--
              Rozsah zrcadlí GarnishmentCalculator::evidenceScope(). Potvrzení,
              které v tomto měsíci nic nedokládá, se zešedne a vypne — pobízet
              k němu znamenalo u firmy o tisíci lidech 12 000 zápisů ročně.
            -->
            <div class="space-y-2 text-sm">
              <div v-for="row in MONTH_EVIDENCE_ROWS" :key="row.key">
                <label class="flex items-center gap-2" :class="evidenceActionable(monthEvidenceScope?.[row.key]) ? '' : 'text-neutral-400'">
                  <input
                    v-model="monthEvidence[row.field]"
                    :disabled="!evidenceActionable(monthEvidenceScope?.[row.key])"
                    type="checkbox"
                    class="rounded border-neutral-300 text-payroll-600 disabled:cursor-not-allowed disabled:opacity-50"
                    :data-test="`month-evidence-${row.key}`"
                  >
                  {{ t(`payroll.enforcement.month_evidence.${row.key}`) }}
                </label>
                <p v-if="evidenceScopeNote(row.key)" class="mt-0.5 pl-6 text-xs text-neutral-500" :data-test="`month-evidence-${row.key}-note`">
                  {{ evidenceScopeNote(row.key) }}
                </p>
              </div>
              <label class="flex items-center gap-2"><input v-model="monthEvidence.has_multiple_payers" type="checkbox" class="rounded border-neutral-300 text-payroll-600" data-test="month-evidence-multiple-payers">{{ t('payroll.enforcement.month_evidence.multiple_payers') }}</label>
            </div>
            <div class="space-y-3">
              <label class="block text-xs text-neutral-600">{{ t('payroll.enforcement.month_evidence.pension') }}<select v-model="monthEvidence.pension_evidence" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm"><option v-for="value in pensionEvidenceValues" :key="value" :value="value">{{ t(`payroll.enforcement.month_evidence.pension_${value === 'verified' ? 'receives' : value}`) }}</option></select></label>
              <label class="block text-xs text-neutral-600">{{ t('payroll.enforcement.month_evidence.protected_override_czk') }}<input v-model="protectedOverrideCzk" inputmode="decimal" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm"></label>
              <label class="flex items-center gap-2 text-sm"><input v-model="monthEvidence.protected_amount_override_verified" type="checkbox" class="rounded border-neutral-300 text-payroll-600">{{ t('payroll.enforcement.month_evidence.protected_override_verified') }}</label>
            </div>
            <div class="space-y-3">
              <label class="block text-xs text-neutral-600">{{ t('payroll.enforcement.month_evidence.insolvency_mode') }}<select v-model="monthEvidence.insolvency_mode" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm"><option value="none">{{ t('payroll.enforcement.month_evidence.insolvency_none') }}</option><option value="alert_only">{{ t('payroll.enforcement.month_evidence.insolvency_alert') }}</option><option value="approved_standard">{{ t('payroll.enforcement.month_evidence.insolvency_standard') }}</option><option value="court_determined_amount">{{ t('payroll.enforcement.month_evidence.insolvency_court') }}</option></select></label>
              <label v-if="monthEvidence.insolvency_mode === 'court_determined_amount'" class="block text-xs text-neutral-600">{{ t('payroll.enforcement.month_evidence.court_amount_czk') }}<input v-model="courtAmountCzk" inputmode="decimal" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm"></label>
              <label class="flex items-center gap-2 text-sm"><input v-model="monthEvidence.insolvency_decision_verified" type="checkbox" class="rounded border-neutral-300 text-payroll-600">{{ t('payroll.enforcement.month_evidence.insolvency_decision') }}</label>
              <label class="flex items-center gap-2 text-sm"><input v-model="monthEvidence.insolvency_recipient_verified" type="checkbox" class="rounded border-neutral-300 text-payroll-600">{{ t('payroll.enforcement.month_evidence.insolvency_recipient') }}</label>
            </div>
          </div>

          <div class="mt-5 border-t border-neutral-200 pt-4">
            <h4 class="font-medium text-neutral-900">{{ t('payroll.enforcement.dependants_title') }}</h4>
            <div v-if="dependants.length" class="mt-3 grid grid-cols-1 gap-2 md:grid-cols-2">
              <div v-for="dependant in dependants" :key="dependant.id" class="rounded-md border border-neutral-200 p-3 text-sm">
                <div class="flex flex-wrap justify-between gap-2"><span class="font-medium">{{ t(`payroll.enforcement.dependant_kind.${dependant.dependant_kind}`) }}</span><span :class="dependant.eligibility_verified ? 'text-success-600' : 'text-warning-600'">{{ t(dependant.eligibility_verified ? 'payroll.enforcement.verified' : 'payroll.enforcement.incomplete') }}</span></div>
                <p class="mt-1 text-xs text-neutral-500">{{ dependant.valid_from }} – {{ dependant.valid_to || '∞' }}</p>
              </div>
            </div>
            <form class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-5" @submit.prevent="addDependant">
              <label class="text-xs text-neutral-600">{{ t('payroll.enforcement.dependant_type') }}<select v-model="newDependant.dependant_kind" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm"><option value="dependant">{{ t('payroll.enforcement.dependant_kind.dependant') }}</option><option value="spouse_partner">{{ t('payroll.enforcement.dependant_kind.spouse_partner') }}</option></select></label>
              <label class="text-xs text-neutral-600">{{ t('payroll.enforcement.valid_from') }}<input v-model="newDependant.valid_from" required type="date" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm"></label>
              <label class="text-xs text-neutral-600">{{ t('payroll.enforcement.valid_to') }}<input v-model="newDependant.valid_to" type="date" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm"></label>
              <div class="space-y-2 pt-5 text-sm"><label class="flex items-center gap-2"><input v-model="newDependant.eligibility_verified" type="checkbox" class="rounded border-neutral-300 text-payroll-600">{{ t('payroll.enforcement.eligible_verified') }}</label><label class="flex items-center gap-2"><input v-model="newDependant.excluded_for_maintenance" type="checkbox" class="rounded border-neutral-300 text-payroll-600">{{ t('payroll.enforcement.excluded_for_maintenance') }}</label></div>
              <div class="flex items-end justify-end"><button type="submit" :class="btnFilled('primary')" :disabled="saving"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.plus" /></svg>{{ t('payroll.enforcement.add_dependant') }}</button></div>
            </form>
          </div>
        </section>

        <section class="rounded-lg border border-neutral-200 bg-surface p-4">
          <div class="flex flex-wrap items-center justify-between gap-3"><h3 class="font-medium text-neutral-900">{{ t('payroll.enforcement.claims') }}</h3><button v-if="canWrite && detail.status === 'received'" :class="btnFilled('primary')" @click="showClaim = !showClaim"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.plus" /></svg>{{ t('payroll.enforcement.add_claim') }}</button></div>
          <form v-if="showClaim" class="mt-4 grid grid-cols-1 gap-3 rounded-lg bg-neutral-50 p-4 sm:grid-cols-2 lg:grid-cols-4" @submit.prevent="addClaim">
            <label class="text-xs text-neutral-600">{{ t('payroll.enforcement.claim_category') }}<select v-model="newClaim.category" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm"><option v-for="category in claimCategories" :key="category" :value="category">{{ t(`payroll.enforcement.categories.${category}`) }}</option></select></label>
            <label class="text-xs text-neutral-600">{{ t('payroll.enforcement.outstanding_czk') }}<input v-model="claimAmountCzk" required inputmode="decimal" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm"></label>
            <label class="text-xs text-neutral-600">{{ t('payroll.enforcement.priority_date') }}<input v-model="newClaim.priority_date" type="date" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm"></label>
            <label class="text-xs text-neutral-600">{{ t('payroll.enforcement.order_issued_on') }}<input v-model="newClaim.order_issued_on" type="date" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm"></label>
            <label v-if="detail.claims.length" class="text-xs text-neutral-600">{{ t('payroll.enforcement.same_order_as') }}<select v-model="newClaim.same_order_as_claim_id" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm"><option :value="null">{{ t('payroll.enforcement.new_order') }}</option><option v-for="claim in detail.claims" :key="claim.id" :value="claim.id">{{ t(`payroll.enforcement.categories.${claim.category}`) }} · {{ claim.priority_date || '—' }}</option></select></label>
            <label v-if="newClaim.category.includes('maintenance')" class="text-xs text-neutral-600">{{ t('payroll.enforcement.maintenance_weight_czk') }}<input v-model="maintenanceWeightCzk" required inputmode="decimal" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm"></label>
            <div class="space-y-2 text-sm sm:col-span-2 lg:col-span-3"><label v-if="detail.case_kind !== 'voluntary_agreement'" class="flex items-center gap-2"><input v-model="newClaim.legal_title_verified" type="checkbox" class="rounded border-neutral-300 text-payroll-600">{{ t('payroll.enforcement.verification.legal_title') }}</label><label v-if="detail.case_kind !== 'voluntary_agreement'" class="flex items-center gap-2"><input v-model="newClaim.order_or_notice_delivered" type="checkbox" class="rounded border-neutral-300 text-payroll-600">{{ t('payroll.enforcement.verification.delivered') }}</label><label class="flex items-center gap-2"><input v-model="newClaim.priority_classification_verified" type="checkbox" class="rounded border-neutral-300 text-payroll-600">{{ t('payroll.enforcement.verification.priority') }}</label><label v-if="detail.case_kind === 'voluntary_agreement'" class="flex items-center gap-2"><input v-model="newClaim.agreement_verified" type="checkbox" class="rounded border-neutral-300 text-payroll-600">{{ t('payroll.enforcement.verification.agreement') }}</label><label v-if="detail.case_kind !== 'voluntary_agreement'" class="flex items-center gap-2"><input v-model="newClaim.due_monetary_claim_verified" type="checkbox" class="rounded border-neutral-300 text-payroll-600">{{ t('payroll.enforcement.verification.due_claim') }}</label></div>
            <div class="flex flex-wrap items-end justify-end gap-2 sm:col-span-2 lg:col-span-4"><button type="button" :class="btnOutline('neutral')" @click="showClaim = false"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.x" /></svg>{{ t('common.cancel') }}</button><button type="submit" :class="btnFilled('primary')" :disabled="saving"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.check" /></svg>{{ t('common.save') }}</button></div>
          </form>
          <div v-if="detail.claims.length" class="mt-4 overflow-x-auto"><table class="min-w-full divide-y divide-neutral-200 text-sm"><thead><tr class="text-left text-xs uppercase tracking-wide text-neutral-500"><th class="px-3 py-2">{{ t('payroll.enforcement.claim_category') }}</th><th class="px-3 py-2">{{ t('payroll.enforcement.priority_date') }}</th><th class="px-3 py-2 text-right">{{ t('payroll.enforcement.balance') }}</th><th class="px-3 py-2">{{ t('payroll.enforcement.verification.title') }}</th></tr></thead><tbody class="divide-y divide-neutral-100"><tr v-for="claim in detail.claims" :key="claim.id"><td class="px-3 py-2">{{ t(`payroll.enforcement.categories.${claim.category}`) }}</td><td class="px-3 py-2 text-neutral-600">{{ claim.priority_date || '—' }}</td><td class="px-3 py-2 text-right font-medium">{{ money(claim.outstanding_minor_units) }}</td><td class="px-3 py-2"><span :class="claimVerified(claim) ? 'text-success-600' : 'text-warning-600'">{{ t(claimVerified(claim) ? 'payroll.enforcement.verified' : 'payroll.enforcement.incomplete') }}</span></td></tr></tbody></table></div>
          <p v-else class="mt-3 text-sm text-neutral-500">{{ t('payroll.enforcement.no_claims') }}</p>
        </section>

        <section class="rounded-lg border border-neutral-200 bg-surface p-4">
          <h3 class="font-medium text-neutral-900">{{ t('payroll.enforcement.timeline') }}</h3>
          <ol v-if="detail.events.length" class="mt-3 grid grid-cols-1 gap-3 lg:grid-cols-2"><li v-for="event in detail.events" :key="event.id" class="min-w-0 border-l-2 border-payroll-500/30 pl-3 text-sm"><p class="font-medium text-neutral-800">{{ t(`payroll.enforcement.commands.${event.command_name}`) }}</p><p class="text-xs text-neutral-500">{{ event.created_at }}</p><p v-if="event.reason" class="mt-1 break-words text-neutral-600">{{ event.reason }}</p><RouterLink v-if="event.decision_document_id" class="mt-1 inline-flex items-center gap-1 text-xs text-primary-600 hover:text-primary-700" :to="{ name: 'document-detail', params: { id: event.decision_document_id } }"><svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.doc" /></svg>{{ t('payroll.enforcement.open_decision_document') }}</RouterLink></li></ol>
          <p v-else class="mt-3 text-sm text-neutral-500">{{ t('payroll.enforcement.no_events') }}</p>
        </section>
      </div>
    </section>
  </div>
</template>

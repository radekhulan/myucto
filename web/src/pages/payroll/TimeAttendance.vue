<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink, useRoute, useRouter, type RouteLocationRaw } from 'vue-router'
import {
  payrollApi,
  type PayrollOvertimeAveragingBasis,
  type PayrollOvertimeAveragingPeriod,
  type PayrollOvertimeProtectionKind,
  type PayrollTimeCategory,
  type PayrollTimeEntry,
  type PayrollTimeImportPreview,
  type PayrollTimeOverview,
  type PayrollTimeOverviewItem,
} from '@/api/payroll'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import {
  buildPayrollGridBatch,
  formatPayrollGridHours,
  isWorkedCategory,
  payrollDayPlans,
  payrollGridCellKey,
  payrollGridCellState,
  payrollGridFlags,
  payrollGridNextPosition,
  payrollGridWorkedMinutes,
  payrollMonthDays,
  type PayrollGridCellProblem,
  type PayrollGridMoveKey,
} from '@/pages/payroll/payrollTimeGrid'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import PayrollFocusNotice from '@/components/payroll/PayrollFocusNotice.vue'
import { payrollQueryId } from '@/pages/payroll/payrollAgendaLinks'
import PayrollFileDropzone, {
  type PayrollFileRejectReason,
} from '@/components/payroll/PayrollFileDropzone.vue'
import Modal from '@/components/ui/Modal.vue'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import { btnFilled, btnOutline, disabledTitle, BTN_DISABLED_NOTE, ICONS } from '@/components/ui/buttonStyles'
import EmptyState from '@/components/ui/EmptyState.vue'
import {
  formatPayrollMinutes,
  payrollWallTimeToIso,
} from '@/pages/payroll/payrollTime'
import { localPayrollPeriod } from '@/pages/payroll/payrollComponentsUi'
import PaginationBar from '@/components/ui/PaginationBar.vue'
import ColumnPicker from '@/components/ui/ColumnPicker.vue'
import DensityToggle from '@/components/ui/DensityToggle.vue'
import { useTablePrefs, type ColumnDef } from '@/composables/useTablePrefs'

const { t } = useI18n()
const auth = useAuthStore()
const toast = useToast()
const route = useRoute()
const router = useRouter()
const period = ref(localPayrollPeriod())
const incompleteOnly = ref(false)
const loading = ref(false)
/*
 * Selhalo načtení? Pak o obsahu nevíme NIC — a to je něco jiného než „nic tu
 * není". Toast s chybou za pár vteřin zmizí a bez tohohle příznaku by na
 * obrazovce zůstal prázdný stav, který lže.
 */
const loadFailed = ref(false)
const saving = ref(false)
const overview = ref<PayrollTimeOverview | null>(null)
const COLUMNS: ColumnDef[] = [
  { key: 'select', labelKey: 'payroll.time.bulk.select_all', required: true },
  { key: 'employee', labelKey: 'payroll.time.columns.employee', required: true },
  { key: 'fund', labelKey: 'payroll.time.columns.fund' },
  { key: 'plan', labelKey: 'payroll.time.columns.plan' },
  { key: 'actual', labelKey: 'payroll.time.columns.actual' },
  { key: 'difference', labelKey: 'payroll.time.columns.difference' },
  { key: 'status', labelKey: 'payroll.time.columns.status' },
  { key: 'actions', labelKey: 'payroll.time.columns.actions', required: true },
]
const tbl = useTablePrefs('payroll-time', COLUMNS)
// Detailní řádek přesčasů se roztahuje pod zbytek tabulky, takže colspan musí
// dopočítat skryté sloupce — natvrdo zapsané číslo by se po skrytí sloupce
// rozjelo o buňku.
const detailColspan = computed(
  () => COLUMNS.filter(column => column.key !== 'select' && tbl.isVisible(column.key)).length,
)
const pageSize = 25
const total = ref(0)
const offset = ref(0)
const currentPage = computed(() => Math.floor(offset.value / pageSize) + 1)
const editorOpen = ref(false)
const importOpen = ref(false)
const recordType = ref<'entry' | 'shift'>('entry')
const employmentId = ref<number | null>(null)
const category = ref<PayrollTimeCategory>('regular')
/*
 * Proč nejde uložit záznam docházky, resp. použít import. Obojí vrací `null`,
 * když akce jde spustit — zašedlé tlačítko bez věty je slepá ulička.
 */
const recordBlockedReason = computed<string | null>(() => {
  if (!selected.value) return t('payroll.time.editor.blocked_no_employment')
  if (!startsAt.value || !endsAt.value) return t('payroll.time.editor.blocked_no_range')
  return null
})
const importBlockedReason = computed<string | null>(() =>
  importPreview.value && !importPreview.value.supported
    ? t('payroll.time.import.blocked_unsupported')
    : null)

const startsAt = ref('')
const endsAt = ref('')
const breakMinutes = ref(0)
const remoteWork = ref(false)
const standbyMinutes = ref(0)
const publish = ref(true)
const timezone = ref(Intl.DateTimeFormat().resolvedOptions().timeZone || 'Europe/Prague')
const importName = ref('')
const importFormat = ref<'csv' | 'xlsx'>('csv')
const importContent = ref('')
const importFileError = ref('')
const importPreview = ref<PayrollTimeImportPreview | null>(null)
const IMPORT_MAX_BYTES = 5_000_000
const selectedEmploymentIds = ref<number[]>([])
const approvalItem = ref<PayrollTimeOverviewItem | null>(null)
const approvalStandardFund = ref('')
const approvalAgreedFund = ref('')
const approvalWeeklyWork = ref('')
const approvalWorked = ref('')
const approvalUnworkedOccurred = ref<boolean | null>(null)
const approvalObstaclesOccurred = ref<boolean | null>(null)
const approvalUnworkedTotal = ref('')
const approvalUnworkedPaid = ref('')
const approvalDpnWithoutCompensation = ref('')
const approvalDpnWithCompensation = ref('')
const approvalVacation = ref('')
const approvalCare = ref('')
const approvalEmployeeObstacle = ref('')
const approvalEmployerObstacle = ref('')
const approvalNote = ref('')
const reopenItem = ref<PayrollTimeOverviewItem | null>(null)
const reopenReason = ref('')
const reopenError = ref('')

const canWrite = computed(() => auth.canWrite('payroll.time.write'))
const canApprove = computed(() => auth.canWrite('payroll.approve'))
const canReopen = computed(() => auth.canWrite('payroll.reopen'))
const selected = computed(() =>
  overview.value?.items.find(item => item.employment.id === employmentId.value) ?? null,
)
/**
 * Zúžení na jeden vztah z odkazu na kartě zaměstnance (`?employment=12`).
 *
 * Why: bez toho vedlo tlačítko „Docházka" na docházku celé firmy a uživatel
 * v ní člověka hledal znovu.
 *
 * Zúžení dělá SERVER (`employment_id`), ne prohlížeč. Dokud filtroval prohlížeč
 * nad načtenou stránkou, vztah ležící na jiné straně se tiše neprojevil: seznam
 * zůstal celý a lišta zmizela, což vypadá jako prázdný výsledek, ne jako
 * nefunkční filtr. Cizí ani neexistující id teď nedá řádek — a je to vidět
 * větou, ne prázdnem.
 */
const focusEmploymentId = ref<number | null>(payrollQueryId(route.query, 'employment'))
const visibleItems = computed(() => overview.value?.items ?? [])
const employmentOptions = computed(() => visibleItems.value.map(item => ({
  value: item.employment.id,
  label: item.employment.full_name,
  secondary: `${relationLabel(item.employment.relation_type)} · ${item.employment.code}`,
})))
const focusMissing = computed(() =>
  focusEmploymentId.value !== null
  && overview.value !== null
  && overview.value.employment_id === focusEmploymentId.value
  && overview.value.total === 0,
)
const focusName = computed(() =>
  focusEmploymentId.value === null || visibleItems.value.length !== 1
    ? null
    : visibleItems.value[0].employment.full_name,
)
function clearFocus() {
  focusEmploymentId.value = null
  const query = { ...route.query }
  delete query.employment
  void router.replace({ query })
  offset.value = 0
  void load()
}
// Hromadné schválení pracuje s tím, co je na obrazovce — se zúžením tedy
// s jedním člověkem, ne se všemi, které schovává filtr.
const selectableItems = computed(() =>
  visibleItems.value.filter(item => item.month.status === 'open'),
)
const allSelectableSelected = computed(() =>
  selectableItems.value.length > 0
  && selectableItems.value.every(item => selectedEmploymentIds.value.includes(item.employment.id)),
)
const approvalConditionalComplete = computed(() =>
  approvalUnworkedOccurred.value !== null
  && approvalObstaclesOccurred.value !== null
  && (!approvalUnworkedOccurred.value || Boolean(approvalUnworkedTotal.value.trim()))
  && (!approvalObstaclesOccurred.value
    || Boolean(
      approvalEmployeeObstacle.value.trim()
      || approvalEmployerObstacle.value.trim(),
    )),
)

const categories: PayrollTimeCategory[] = [
  'regular',
  'overtime',
  'night',
  'weekend',
  'holiday',
  'difficult_environment',
]

// V docházce se vypisuje název vztahu, ne jeho technický kód — dva vztahy téhož
// člověka se jinak lišily jen řetězci typu „legacy" a „ZAM-2".
function relationLabel(type: string): string {
  return t(`payroll.people.relations.${type}`)
}

async function load() {
  loading.value = true
  loadFailed.value = false
  try {
    overview.value = await payrollApi.timeMonth(
      period.value,
      incompleteOnly.value,
      { limit: pageSize, offset: offset.value },
      focusEmploymentId.value,
    )
    total.value = overview.value.total
    selectedEmploymentIds.value = []
    // Předvybraný vztah má přednost před prvním v seznamu — jinak by odkaz
    // z karty zúžil tabulku, ale editor otevřel někoho jiného.
    const focused = focusEmploymentId.value !== null
      && overview.value.items.some(item => item.employment.id === focusEmploymentId.value)
      ? focusEmploymentId.value
      : null
    if (focused !== null) {
      employmentId.value = focused
    } else if (!employmentId.value && overview.value.items.length > 0) {
      employmentId.value = overview.value.items[0].employment.id
    }
  } catch (error: any) {
    // `overview` zůstává, jak bylo — prázdná docházka a nenačtená docházka
    // vypadaly na obrazovce stejně.
    loadFailed.value = true
    toast.error(error?.response?.data?.error?.message || t('payroll.time.load_failed'))
  } finally {
    loading.value = false
  }
}

function goToPage(nextPage: number) {
  offset.value = Math.max(0, (nextPage - 1) * pageSize)
  void load()
}

// Změna období nebo zúžení mění obsah seznamu, takže stránka musí na začátek —
// jinak by se uživatel po přepnutí měsíce ocitl na prázdné páté straně.
function reload() {
  offset.value = 0
  void load()
}

/*
 * Editor si drží POSLEDNÍ zadaný den, ne první den měsíce.
 *
 * Why: dokud se datum po každém otevření přepsalo na `${period}-01`, znamenal
 * zápis dvanácti dnů čtyřiadvacet ručních přepsání data. Běžný případ teď řeší
 * mřížka; tenhle editor zůstává na výjimky (směna přes půlnoc, přestávky,
 * příplatkové kategorie), ale ani u výjimek nemá smysl datum zahazovat.
 */
const lastEditorTimes = ref<{ date: string; start: string; end: string } | null>(null)

function setDefaultTimes() {
  const remembered = lastEditorTimes.value
  const day = remembered && remembered.date.startsWith(`${period.value}-`)
    ? remembered.date
    : `${period.value}-01`
  startsAt.value = `${day}T${remembered?.start ?? '08:00'}`
  endsAt.value = `${day}T${remembered?.end ?? '16:30'}`
}

function openEditor(item?: PayrollTimeOverviewItem) {
  if (item) employmentId.value = item.employment.id
  setDefaultTimes()
  editorOpen.value = true
}

/** Posun na následující den v témže měsíci; poslední den zůstane, kde je. */
function nextEditorDay(date: string): string {
  const parsed = new Date(`${date}T00:00:00Z`)
  if (Number.isNaN(parsed.getTime())) return date
  const next = new Date(parsed.getTime() + 86_400_000)
  const iso = next.toISOString().slice(0, 10)
  return iso.startsWith(`${period.value}-`) ? iso : date
}

/**
 * „Uložit a další den" — zápis se uloží, editor zůstane otevřený a posune se
 * o den. Bez toho stálo pět výjimek v měsíci pět otevření editoru.
 */
async function saveRecordAndContinue() {
  const before = startsAt.value.slice(0, 10)
  await saveRecord(true)
  if (editorOpen.value) {
    const day = nextEditorDay(before)
    startsAt.value = `${day}T${startsAt.value.slice(11)}`
    endsAt.value = `${day}T${endsAt.value.slice(11)}`
  }
}

async function saveRecord(keepOpen = false) {
  if (!selected.value) return
  const common = {
    employment_id: selected.value.employment.id,
    starts_at: payrollWallTimeToIso(startsAt.value, timezone.value),
    ends_at: payrollWallTimeToIso(endsAt.value, timezone.value),
    timezone: timezone.value,
    break_minutes: breakMinutes.value,
    row_version: 0,
    month_row_version: selected.value.month.row_version,
    supersedes_id: null,
  }
  saving.value = true
  try {
    if (recordType.value === 'shift') {
      await payrollApi.saveShift({
        ...common,
        calendar_id: selected.value.calendar?.id ?? null,
        remote_work: remoteWork.value,
        standby_minutes: standbyMinutes.value,
        publish: publish.value,
      })
    } else {
      await payrollApi.saveTimeEntry({ ...common, category: category.value })
    }
    toast.success(t('payroll.time.saved'))
    lastEditorTimes.value = {
      date: startsAt.value.slice(0, 10),
      start: startsAt.value.slice(11, 16),
      end: endsAt.value.slice(11, 16),
    }
    if (!keepOpen) editorOpen.value = false
    await load()
  } catch (error: any) {
    toast.error(error?.response?.data?.error?.message || t('payroll.time.save_failed'))
  } finally {
    saving.value = false
  }
}

async function createCalendar(item: PayrollTimeOverviewItem) {
  saving.value = true
  try {
    await payrollApi.saveTimeCalendar(item.employment.id, {
      name: t('payroll.time.calendar.default_name'),
      timezone: 'Europe/Prague',
      schedule_type: 'regular',
      valid_from: `${period.value}-01`,
      valid_to: null,
      row_version: item.calendar?.row_version ?? 0,
      month_row_version: item.month.row_version,
      week_pattern: { 1: 480, 2: 480, 3: 480, 4: 480, 5: 480, 6: 0, 7: 0 },
      days: [],
    })
    toast.success(t('payroll.time.calendar.saved'))
    await load()
  } catch (error: any) {
    toast.error(error?.response?.data?.error?.message || t('payroll.time.calendar.failed'))
  } finally {
    saving.value = false
  }
}

function openApproval(item: PayrollTimeOverviewItem) {
  const suggestions = item.jmhz_work_summary.preview?.suggestions
  approvalItem.value = item
  approvalStandardFund.value = suggestions?.standard_fund_hours ?? ''
  approvalAgreedFund.value = suggestions?.agreed_fund_hours ?? ''
  approvalWeeklyWork.value = suggestions?.weekly_work_hours ?? ''
  approvalWorked.value = suggestions?.worked_hours ?? ''
  approvalUnworkedOccurred.value = null
  approvalObstaclesOccurred.value = null
  clearConditionalValues()
  approvalNote.value = ''
}

function clearConditionalValues() {
  approvalUnworkedTotal.value = ''
  approvalUnworkedPaid.value = ''
  approvalDpnWithoutCompensation.value = ''
  approvalDpnWithCompensation.value = ''
  approvalVacation.value = ''
  approvalCare.value = ''
  approvalEmployeeObstacle.value = ''
  approvalEmployerObstacle.value = ''
}

function setUnworkedOccurred(value: boolean) {
  const previous = approvalUnworkedOccurred.value
  approvalUnworkedOccurred.value = value
  if (!value) {
    approvalObstaclesOccurred.value = false
    clearConditionalValues()
  } else if (previous === false) {
    approvalObstaclesOccurred.value = null
  }
}

function setObstaclesOccurred(value: boolean) {
  approvalObstaclesOccurred.value = value
  if (!value) {
    approvalEmployeeObstacle.value = ''
    approvalEmployerObstacle.value = ''
  }
}

function optionalHours(value: string): string | null {
  const normalized = value.trim()
  return normalized === '' ? null : normalized
}

/*
 * ─── Limity přesčasu podle § 93 zákoníku práce ──────────────────────────────
 *
 * Věty pro uživatele skládá backend (`overtime_limits.findings[].message`),
 * protože nesou konkrétní čísla a odkaz na odstavec zákona — překládat je přes
 * `t()` by znamenalo držet právní text na dvou místech. Tady zůstává jen rámec
 * kolem nich: nadpis, souhrn čerpání a evidence souhlasu.
 */
const consentItem = ref<PayrollTimeOverviewItem | null>(null)
const consentValidFrom = ref('')
const consentValidTo = ref('')
const consentReference = ref('')
const consentNote = ref('')
const consentError = ref('')

const consentBlockedReason = computed<string | null>(() =>
  consentValidFrom.value === '' ? t('payroll.time.overtime.consent_blocked_no_date') : null,
)

function overtimeWarnings(item: PayrollTimeOverviewItem) {
  return item.overtime_limits?.findings.filter(finding => finding.severity === 'warning') ?? []
}

/*
 * Porušený zákaz (mladistvý, těhotenství, péče o dítě do 1 roku, kratší
 * pracovní doba) není totéž co překročený limit — bez ruční výjimky se běh
 * neschválí, takže se panel obarvuje jinak než u pouhého varování.
 */
function overtimeProhibitions(item: PayrollTimeOverviewItem) {
  return item.overtime_limits?.findings.filter(finding => finding.requires_override) ?? []
}

function overtimePanelClass(item: PayrollTimeOverviewItem): string {
  if (overtimeProhibitions(item).length) {
    return 'border-danger-500/50 bg-danger-50 text-danger-700'
  }
  if (overtimeWarnings(item).length) {
    return 'border-warning-500/40 bg-warning-50 text-warning-700'
  }
  return 'border-neutral-200 bg-neutral-50 text-neutral-600'
}

function overtimeVisible(item: PayrollTimeOverviewItem): boolean {
  const limits = item.overtime_limits
  if (!limits) return false
  return limits.findings.length > 0
    || limits.ordered_year_minutes > 0
    || limits.agreed_year_minutes > 0
}

/** § 93 odst. 4 a 5 — stav klouzavého okna včetně vyňatého náhradního volna. */
function overtimeAveragingSummary(item: PayrollTimeOverviewItem): string {
  const limits = item.overtime_limits
  if (!limits || limits.averaging_from === null || limits.averaging_to === null) return ''
  const parts = [t('payroll.time.overtime.averaging_summary', {
    weeks: limits.averaging_weeks,
    from: limits.averaging_from,
    to: limits.averaging_to,
    used: formatPayrollMinutes(limits.averaging_minutes),
    limit: formatPayrollMinutes(limits.averaging_limit_minutes),
  })]
  if (limits.averaging_compensated_minutes > 0) {
    parts.push(t('payroll.time.overtime.averaging_compensated', {
      minutes: formatPayrollMinutes(limits.averaging_compensated_minutes),
    }))
  }
  parts.push(limits.averaging_basis === 'collective_agreement'
    ? t('payroll.time.overtime.averaging_collective', {
      reference: limits.averaging_reference ?? '',
    })
    : t('payroll.time.overtime.averaging_statutory'))
  return parts.join(' ')
}

function overtimeYearSummary(item: PayrollTimeOverviewItem): string {
  const limits = item.overtime_limits
  if (!limits) return ''
  return t('payroll.time.overtime.year_summary', {
    used: formatPayrollMinutes(limits.ordered_year_minutes),
    limit: formatPayrollMinutes(limits.ordered_year_limit_minutes),
  })
}

function overtimeConsentSummary(item: PayrollTimeOverviewItem): string {
  const consents = item.overtime_consents
  if (!consents.length) return t('payroll.time.overtime.consent_missing')
  const open = consents.find(consent => consent.valid_to === null)
  return open
    ? t('payroll.time.overtime.consent_open', { from: open.valid_from })
    : t('payroll.time.overtime.consent_until', {
      from: consents[consents.length - 1].valid_from,
      to: consents[consents.length - 1].valid_to ?? '',
    })
}

function openConsent(item: PayrollTimeOverviewItem) {
  consentItem.value = item
  consentValidFrom.value = `${period.value}-01`
  consentValidTo.value = ''
  consentReference.value = ''
  consentNote.value = ''
  consentError.value = ''
}

function closeConsent() {
  consentItem.value = null
  consentError.value = ''
}

async function saveConsent() {
  const item = consentItem.value
  if (!item || consentBlockedReason.value) return
  saving.value = true
  consentError.value = ''
  try {
    await payrollApi.saveOvertimeConsent({
      employment_id: item.employment.id,
      valid_from: consentValidFrom.value,
      valid_to: consentValidTo.value === '' ? null : consentValidTo.value,
      document_reference: consentReference.value.trim() === '' ? null : consentReference.value.trim(),
      note: consentNote.value.trim() === '' ? null : consentNote.value.trim(),
      row_version: 0,
    })
    toast.success(t('payroll.time.overtime.consent_saved'))
    closeConsent()
    await load()
  } catch (error: any) {
    consentError.value = error?.response?.data?.error?.message
      || t('payroll.time.overtime.consent_failed')
  } finally {
    saving.value = false
  }
}

/*
 * ─── Zákazy práce přesčas (§ 240 odst. 3) ───────────────────────────────────
 *
 * Mladistvost se nezapisuje — plyne z data narození (§ 350 odst. 2). Zapisuje
 * se jen to, co modul odjinud nezná: těhotenství a péče o dítě mladší 1 roku.
 */
const protectionItem = ref<PayrollTimeOverviewItem | null>(null)
const protectionKind = ref<PayrollOvertimeProtectionKind>('pregnancy')
const protectionValidFrom = ref('')
const protectionValidTo = ref('')
const protectionReference = ref('')
const protectionNote = ref('')
const protectionError = ref('')

const protectionBlockedReason = computed<string | null>(() =>
  protectionValidFrom.value === '' ? t('payroll.time.overtime.protection_blocked_no_date') : null,
)

function openProtection(item: PayrollTimeOverviewItem) {
  protectionItem.value = item
  protectionKind.value = 'pregnancy'
  protectionValidFrom.value = `${period.value}-01`
  protectionValidTo.value = ''
  protectionReference.value = ''
  protectionNote.value = ''
  protectionError.value = ''
}

function closeProtection() {
  protectionItem.value = null
  protectionError.value = ''
}

async function saveProtection() {
  const item = protectionItem.value
  if (!item || protectionBlockedReason.value) return
  saving.value = true
  protectionError.value = ''
  try {
    await payrollApi.saveOvertimeProtection({
      employment_id: item.employment.id,
      protection: protectionKind.value,
      valid_from: protectionValidFrom.value,
      valid_to: protectionValidTo.value === '' ? null : protectionValidTo.value,
      document_reference: protectionReference.value.trim() === '' ? null : protectionReference.value.trim(),
      note: protectionNote.value.trim() === '' ? null : protectionNote.value.trim(),
      row_version: 0,
    })
    toast.success(t('payroll.time.overtime.protection_saved'))
    closeProtection()
    await load()
  } catch (error: any) {
    protectionError.value = error?.response?.data?.error?.message
      || t('payroll.time.overtime.protection_failed')
  } finally {
    saving.value = false
  }
}

/*
 * ─── Náhradní volno za přesčas (§ 93 odst. 5) ───────────────────────────────
 *
 * Klíčem je den PŘESČASU, ne den čerpání volna — z vyrovnávacího okna vypadává
 * odpracovaný přesčas, ne volno.
 */
const compensationItem = ref<PayrollTimeOverviewItem | null>(null)
const compensationDate = ref('')
const compensationMinutes = ref('')
const compensationGrantedOn = ref('')
const compensationReference = ref('')
const compensationNote = ref('')
const compensationError = ref('')

const compensationBlockedReason = computed<string | null>(() => {
  if (compensationDate.value === '') return t('payroll.time.overtime.compensation_blocked_no_date')
  const minutes = Number(compensationMinutes.value)
  if (!Number.isInteger(minutes) || minutes <= 0) {
    return t('payroll.time.overtime.compensation_blocked_no_minutes')
  }
  return null
})

function openCompensation(item: PayrollTimeOverviewItem) {
  compensationItem.value = item
  compensationDate.value = `${period.value}-01`
  compensationMinutes.value = ''
  compensationGrantedOn.value = ''
  compensationReference.value = ''
  compensationNote.value = ''
  compensationError.value = ''
}

function closeCompensation() {
  compensationItem.value = null
  compensationError.value = ''
}

async function saveCompensation() {
  const item = compensationItem.value
  if (!item || compensationBlockedReason.value) return
  saving.value = true
  compensationError.value = ''
  try {
    await payrollApi.saveOvertimeCompensation({
      employment_id: item.employment.id,
      overtime_date: compensationDate.value,
      minutes: Number(compensationMinutes.value),
      granted_on: compensationGrantedOn.value === '' ? null : compensationGrantedOn.value,
      document_reference: compensationReference.value.trim() === '' ? null : compensationReference.value.trim(),
      note: compensationNote.value.trim() === '' ? null : compensationNote.value.trim(),
      row_version: 0,
    })
    toast.success(t('payroll.time.overtime.compensation_saved'))
    closeCompensation()
    await load()
  } catch (error: any) {
    compensationError.value = error?.response?.data?.error?.message
      || t('payroll.time.overtime.compensation_failed')
  } finally {
    saving.value = false
  }
}

/*
 * ─── Vyrovnávací období (§ 93 odst. 4) ──────────────────────────────────────
 *
 * Firemní údaj, ne konstanta: nad 26 týdnů se smí jít „jen kolektivní
 * smlouvou", proto se u prodloužení vyžaduje odkaz na ni.
 */
const averagingOpen = ref(false)
const averagingPeriods = ref<PayrollOvertimeAveragingPeriod[]>([])
const averagingValidFrom = ref('')
const averagingValidTo = ref('')
const averagingWeeks = ref('26')
const averagingBasis = ref<PayrollOvertimeAveragingBasis>('statutory')
const averagingReference = ref('')
const averagingNote = ref('')
const averagingError = ref('')

const averagingBlockedReason = computed<string | null>(() => {
  if (averagingValidFrom.value === '') return t('payroll.time.overtime.averaging_blocked_no_date')
  const weeks = Number(averagingWeeks.value)
  if (!Number.isInteger(weeks) || weeks < 1) {
    return t('payroll.time.overtime.averaging_blocked_no_weeks')
  }
  if (averagingBasis.value === 'statutory' && weeks > 26) {
    return t('payroll.time.overtime.averaging_blocked_statutory_max')
  }
  if (averagingBasis.value === 'collective_agreement') {
    if (weeks > 52) return t('payroll.time.overtime.averaging_blocked_collective_max')
    if (averagingReference.value.trim() === '') {
      return t('payroll.time.overtime.averaging_blocked_no_reference')
    }
  }
  return null
})

async function openAveraging() {
  averagingOpen.value = true
  averagingError.value = ''
  averagingValidFrom.value = `${period.value}-01`
  averagingValidTo.value = ''
  averagingWeeks.value = '26'
  averagingBasis.value = 'statutory'
  averagingReference.value = ''
  averagingNote.value = ''
  try {
    averagingPeriods.value = await payrollApi.listOvertimeAveragingPeriods()
  } catch {
    averagingPeriods.value = []
    averagingError.value = t('payroll.time.overtime.averaging_load_failed')
  }
}

function closeAveraging() {
  averagingOpen.value = false
  averagingError.value = ''
}

async function saveAveraging() {
  if (averagingBlockedReason.value) return
  saving.value = true
  averagingError.value = ''
  try {
    await payrollApi.saveOvertimeAveragingPeriod({
      valid_from: averagingValidFrom.value,
      valid_to: averagingValidTo.value === '' ? null : averagingValidTo.value,
      weeks: Number(averagingWeeks.value),
      basis: averagingBasis.value,
      collective_agreement_reference: averagingBasis.value === 'collective_agreement'
        ? averagingReference.value.trim()
        : null,
      note: averagingNote.value.trim() === '' ? null : averagingNote.value.trim(),
      row_version: 0,
    })
    toast.success(t('payroll.time.overtime.averaging_saved'))
    closeAveraging()
    await load()
  } catch (error: any) {
    averagingError.value = error?.response?.data?.error?.message
      || t('payroll.time.overtime.averaging_failed')
  } finally {
    saving.value = false
  }
}

function closeApproval() {
  approvalItem.value = null
  approvalStandardFund.value = ''
  approvalAgreedFund.value = ''
  approvalWeeklyWork.value = ''
  approvalWorked.value = ''
  approvalUnworkedOccurred.value = null
  approvalObstaclesOccurred.value = null
  clearConditionalValues()
  approvalNote.value = ''
}

async function approve() {
  const item = approvalItem.value
  const preview = item?.jmhz_work_summary.preview
  if (!item || !preview) return
  if (!approvalConditionalComplete.value
    || approvalUnworkedOccurred.value === null
    || approvalObstaclesOccurred.value === null
  ) return
  saving.value = true
  try {
    await payrollApi.approveTimeMonth(period.value, {
      employment_id: item.employment.id,
      row_version: item.month.row_version,
      jmhz_work_summary: {
        source_snapshot_sha256: preview.source_snapshot_sha256,
        standard_fund_hours: approvalStandardFund.value.trim(),
        agreed_fund_hours: approvalAgreedFund.value.trim(),
        weekly_work_hours: approvalWeeklyWork.value.trim(),
        worked_hours: approvalWorked.value.trim(),
        unworked_hours_occurred: approvalUnworkedOccurred.value,
        work_obstacles_occurred: approvalObstaclesOccurred.value,
        unworked_total_hours: optionalHours(approvalUnworkedTotal.value),
        unworked_paid_hours: optionalHours(approvalUnworkedPaid.value),
        dpn_without_employer_compensation_hours:
          optionalHours(approvalDpnWithoutCompensation.value),
        dpn_with_employer_compensation_hours:
          optionalHours(approvalDpnWithCompensation.value),
        vacation_hours: optionalHours(approvalVacation.value),
        care_hours: optionalHours(approvalCare.value),
        employee_obstacle_paid_hours: optionalHours(approvalEmployeeObstacle.value),
        employer_obstacle_hours: optionalHours(approvalEmployerObstacle.value),
        confirmation_note: approvalNote.value.trim(),
      },
    })
    toast.success(t('payroll.time.approved'))
    approveError.value = null
    closeApproval()
    await load()
  } catch (error: any) {
    approveError.value = describeApproveFailure(error, item)
    toast.error(approveError.value.message)
  } finally {
    saving.value = false
  }
}

/**
 * Schválení materializuje příplatky, takže selhává i na chybějícím PODKLADU,
 * ne jen na verzi. Kód z odpovědi se překlopí na větu „co chybí" a na cíl, kde
 * se to doplní — jinak uživatel vidí jen 409 a neví, kam jít.
 */
function describeApproveFailure(error: any, item: PayrollTimeOverviewItem) {
  const raw = error?.response?.data?.error?.code
  const code = APPROVE_ERROR_CODES.find(known => known === raw) ?? null
  return {
    code,
    message: code === null
      ? (error?.response?.data?.error?.message || t('payroll.time.approve_failed'))
      : t(`payroll.time.approve_errors.${code}`, { name: item.employment.full_name }),
    name: item.employment.full_name,
    employmentId: item.employment.id,
  }
}

function toggleSelection(employmentId: number) {
  selectedEmploymentIds.value = selectedEmploymentIds.value.includes(employmentId)
    ? selectedEmploymentIds.value.filter(id => id !== employmentId)
    : [...selectedEmploymentIds.value, employmentId]
}

function toggleAllVisible() {
  selectedEmploymentIds.value = allSelectableSelected.value
    ? []
    : selectableItems.value.map(item => item.employment.id)
}

async function approveSelected() {
  const items = selectableItems.value.filter(item =>
    selectedEmploymentIds.value.includes(item.employment.id),
  )
  if (items.length === 0) return
  saving.value = true
  let approved = 0
  let failed = 0
  for (const item of items) {
    try {
      await payrollApi.approveTimeMonth(period.value, {
        employment_id: item.employment.id,
        row_version: item.month.row_version,
      })
      approved += 1
    } catch (error: any) {
      failed += 1
      // Z hromadného schválení si necháme PRVNÍ konkrétní důvod i s odkazem,
      // kam pro chybějící podklad jít. „Nepodařilo se schválit 3 vztahy" bez
      // toho znamená otevřít tři řádky a hádat, který na čem spadl.
      approveError.value ??= describeApproveFailure(error, item)
    }
  }
  if (approved > 0) toast.success(t('payroll.time.bulk.approved', { count: approved }))
  if (failed > 0) toast.error(t('payroll.time.bulk.failed', { count: failed }))
  await load()
  saving.value = false
}

function openReopen(item: PayrollTimeOverviewItem) {
  reopenItem.value = item
  reopenReason.value = ''
  reopenError.value = ''
}

function closeReopen() {
  reopenItem.value = null
  reopenReason.value = ''
  reopenError.value = ''
}

async function reopen() {
  const item = reopenItem.value
  const reason = reopenReason.value.trim()
  if (!item || !reason) return
  reopenError.value = ''
  saving.value = true
  try {
    await payrollApi.reopenTimeMonth(period.value, {
      employment_id: item.employment.id,
      row_version: item.month.row_version,
      reason,
    })
    toast.success(t('payroll.time.reopened'))
    closeReopen()
    await load()
  } catch (error: any) {
    reopenError.value = error?.response?.data?.error?.message || t('payroll.time.reopen_failed')
  } finally {
    saving.value = false
  }
}

function clearImportSelection() {
  importName.value = ''
  importContent.value = ''
  importPreview.value = null
}

async function loadImportFile(file: File) {
  if (file.size > IMPORT_MAX_BYTES) {
    rejectImportFile('file_too_large')
    return
  }
  importFileError.value = ''
  importName.value = file.name
  importFormat.value = file.name.toLowerCase().endsWith('.xlsx') ? 'xlsx' : 'csv'
  importContent.value = ''
  importPreview.value = null
  try {
    if (importFormat.value === 'csv') {
      importContent.value = await file.text()
    } else {
      const buffer = await new Promise<ArrayBuffer>((resolve, reject) => {
        const reader = new FileReader()
        reader.onerror = () => reject(reader.error ?? new Error('file_read_failed'))
        reader.onload = () => {
          if (reader.result instanceof ArrayBuffer) resolve(reader.result)
          else reject(new Error('file_read_failed'))
        }
        reader.readAsArrayBuffer(file)
      })
      const bytes = new Uint8Array(buffer)
      const chunks: string[] = []
      for (let offset = 0; offset < bytes.length; offset += 32_768) {
        chunks.push(String.fromCharCode(...bytes.subarray(offset, offset + 32_768)))
      }
      importContent.value = btoa(chunks.join(''))
    }
  } catch {
    clearImportSelection()
    importFileError.value = t('payroll.time.import.read_failed')
    toast.error(importFileError.value)
  }
}

function rejectImportFile(reason: PayrollFileRejectReason) {
  clearImportSelection()
  importFileError.value = t(`payroll.time.import.${reason}`)
  toast.error(importFileError.value)
}

async function previewImport() {
  saving.value = true
  try {
    importPreview.value = await payrollApi.previewTimeImport({
      period: period.value,
      format: importFormat.value,
      original_name: importName.value,
      content: importContent.value,
    })
  } catch (error: any) {
    toast.error(error?.response?.data?.error?.message || t('payroll.time.import.preview_failed'))
  } finally {
    saving.value = false
  }
}

async function applyImport() {
  saving.value = true
  try {
    await payrollApi.importTime({
      period: period.value,
      format: importFormat.value,
      original_name: importName.value,
      content: importContent.value,
    })
    toast.success(t('payroll.time.import.saved'))
    importOpen.value = false
    importPreview.value = null
    await load()
  } catch (error: any) {
    toast.error(error?.response?.data?.error?.message || t('payroll.time.import.failed'))
  } finally {
    saving.value = false
  }
}

/*
 * ─── Měsíční mřížka: zaměstnanci × dny ──────────────────────────────────────
 *
 * Why: docházku šlo dosud zadat jen po JEDNOM intervalu v editoru, který se po
 * uložení zavíral. Dvacet lidí za měsíc = několik tisíc úkonů, pět set lidí je
 * mimo lidské možnosti — a jediná průchodná cesta (import CSV/XLSX) nebyla na
 * stránce nikde vidět.
 *
 * Mřížka edituje VŽDY JEDNU kategorii. Není to zjednodušení: `night`,
 * `weekend`, `holiday` a `difficult_environment` jsou příznaky nad týmiž
 * hodinami, ne hodiny navíc (odpracovaná doba = `regular + overtime`). Kdyby
 * mřížka nabídla „součet všech kategorií", měsíc s nočními by tvrdil dvakrát
 * tolik. Sloupec „odpracováno" proto sčítá jen dvě hodinové kategorie a
 * příznaky se v buňce ukazují jen jako značka.
 */
const gridCategory = ref<PayrollTimeCategory>('regular')
const gridStartTime = ref('08:00')
const gridBreakMinutes = ref(0)
/** Jen políčka, kterých se uživatel dotkl. Klíč je `employmentId|YYYY-MM-DD`. */
const gridDrafts = ref<Record<string, string>>({})
/** Věta, PROČ se konkrétní buňka neuložila. Nezmizí sama, na rozdíl od toastu. */
const gridCellErrors = ref<Record<string, string>>({})
const gridSaving = ref(false)
const gridSaveError = ref<string | null>(null)
const gridNote = ref<string | null>(null)
const gridBody = ref<HTMLElement | null>(null)
const GRID_FALLBACK_MINUTES = 480
/** Musí sedět na `PayrollTimeService::BATCH_MAX_CELLS` — server víc nevezme. */
const GRID_BATCH_MAX = 500

const gridDays = computed(() => payrollMonthDays(period.value))
const gridEditableCategory = computed(() => isWorkedCategory(gridCategory.value))
/*
 * Stav buněk se počítá JEDNOU na načtení, ne při každém překreslení.
 *
 * Why: stránka mřížky má 775 buněk a každý stisk klávesy překreslí celou
 * komponentu. Kdyby si buňka svůj stav filtrovala ze seznamu zápisů sama, stálo
 * by jedno písmeno 775 × (počet zápisů měsíce) průchodů polem — u plného měsíce
 * dvacet tisíc. Takhle je to O(1) lookup v mapě.
 */
const gridRows = computed(() => visibleItems.value.map(item => {
  const entries = item.entries ?? []
  const cells = new Map<string, {
    minutes: number
    locked: boolean
    worked: number
    flags: string
    holidayName: string | null
    workday: boolean
  }>()
  const plans = payrollDayPlans(item, gridDays.value, GRID_FALLBACK_MINUTES)
  for (const day of gridDays.value) {
    const state = payrollGridCellState(entries, day.date, gridCategory.value)
    const plan = plans.get(day.date)
    cells.set(day.date, {
      minutes: state.minutes,
      locked: state.locked,
      worked: payrollGridWorkedMinutes(entries, day.date),
      flags: payrollGridFlags(entries, day.date)
        .map(flag => t(`payroll.time.category.${flag}`))
        .join(', '),
      holidayName: plan?.holidayName ?? null,
      workday: plan?.kind === 'workday',
    })
  }
  return {
    item,
    entries,
    plans,
    cells,
    plannedMinutes: (date: string) => plans.get(date)?.plannedMinutes ?? 0,
    workedTotal: gridDays.value.reduce(
      (sum, day) => sum + (cells.get(day.date)?.worked ?? 0),
      0,
    ),
  }
}))
type PayrollGridRow = (typeof gridRows)['value'][number]
const gridDirtyKeys = computed(() => Object.keys(gridDrafts.value))
const gridDirtyCount = computed(() => gridDirtyKeys.value.length)
const gridBlockedReason = computed<string | null>(() => {
  if (!canWrite.value) return t('payroll.time.grid.blocked_no_permission')
  if (gridDirtyCount.value === 0) return t('payroll.time.grid.blocked_nothing_changed')
  return null
})
const gridFillBlockedReason = computed<string | null>(() => {
  if (!canWrite.value) return t('payroll.time.grid.blocked_no_permission')
  if (!gridEditableCategory.value) return t('payroll.time.grid.blocked_flag_category')
  return null
})

function gridKey(employmentId: number, date: string): string {
  return payrollGridCellKey(employmentId, date)
}

/**
 * Rozepsané buňky patří k JEDNÉ kategorii a k JEDNOMU období.
 *
 * Klíč je `vztah|den`, takže bez tohohle úklidu by se osm hodin napsaných do
 * běžné práce po přepnutí na přesčas ukázalo jako osm hodin přesčasu — a při
 * uložení by se tak i zapsalo.
 */
function resetGridDrafts() {
  gridDrafts.value = {}
  gridCellErrors.value = {}
  gridSaveError.value = null
  gridNote.value = null
}

watch([gridCategory, period], resetGridDrafts)

/** Co je v políčku vidět: rozepsaná hodnota, jinak to, co je uložené. */
function gridValue(row: PayrollGridRow, date: string): string {
  const draft = gridDrafts.value[gridKey(row.item.employment.id, date)]
  if (draft !== undefined) return draft
  return formatPayrollGridHours(row.cells.get(date)?.minutes ?? 0)
}

function gridCellLocked(row: PayrollGridRow, date: string): boolean {
  return row.cells.get(date)?.locked ?? false
}

function gridCellFlags(row: PayrollGridRow, date: string): string {
  return row.cells.get(date)?.flags ?? ''
}

function setGridValue(employmentId: number, date: string, value: string) {
  const key = gridKey(employmentId, date)
  gridDrafts.value = { ...gridDrafts.value, [key]: value }
  if (gridCellErrors.value[key]) {
    const next = { ...gridCellErrors.value }
    delete next[key]
    gridCellErrors.value = next
  }
}

/**
 * Tooltip buňky. Skládá se ze všeho, co o dni víme a co by jinak zůstalo
 * neviditelné: chyba uložení, důvod zamčení, svátek a příznaky nad hodinami.
 */
function gridCellTitle(row: PayrollGridRow, date: string): string {
  const cell = row.cells.get(date)
  const parts: string[] = []
  const error = gridCellErrors.value[gridKey(row.item.employment.id, date)]
  if (error) parts.push(error)
  if (row.item.month.status !== 'open') parts.push(t('payroll.time.grid.month_approved'))
  if (cell?.locked) parts.push(t('payroll.time.grid.problems.locked'))
  if (cell?.holidayName) parts.push(cell.holidayName)
  if (cell?.flags) parts.push(t('payroll.time.grid.cell_flags', { flags: cell.flags }))
  return parts.join(' ')
}

/** Prvních deset vadných buněk s větou — víc by z panelu udělalo výpis. */
const gridCellErrorList = computed(() => Object.entries(gridCellErrors.value)
  .slice(0, 10)
  .map(([key, message]) => {
    const [employmentId, date] = key.split('|')
    const row = gridRows.value.find(candidate =>
      candidate.item.employment.id === Number(employmentId))
    return { key, date, name: row?.item.employment.full_name ?? employmentId, message }
  }))
const gridCellErrorCount = computed(() => Object.keys(gridCellErrors.value).length)

function gridCellClass(row: PayrollGridRow, date: string): string {
  const key = gridKey(row.item.employment.id, date)
  if (gridCellErrors.value[key]) return 'border-danger-500 bg-danger-50'
  if (gridDrafts.value[key] !== undefined) return 'border-payroll-500 bg-payroll-50'
  return 'border-neutral-200'
}

/*
 * Pohyb po mřížce klávesnicí.
 *
 * Enter skáče o řádek níž (ne „uložit"): u sedmi set políček by odeslání
 * formuláře prostředním Enterem uložilo rozdělanou práci. Uložení má
 * Ctrl+Enter a tlačítko. Šipky vlevo/vpravo se chovají jako v tabulkovém
 * procesoru — přeskočí na sousední buňku jen tehdy, když je kurzor na kraji
 * textu; jinak by se v políčku nedalo opravit prostřední znak.
 */
function onGridKeydown(event: KeyboardEvent, rowIndex: number, columnIndex: number) {
  if (event.key === 'Enter' && (event.ctrlKey || event.metaKey)) {
    event.preventDefault()
    void saveGrid()
    return
  }
  const input = event.target as HTMLInputElement
  if (event.key === 'ArrowLeft' && (input.selectionStart ?? 0) > 0) return
  if (event.key === 'ArrowRight' && (input.selectionEnd ?? 0) < input.value.length) return
  const keys = ['ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight', 'Enter', 'Home', 'End']
  if (!keys.includes(event.key)) return
  const next = payrollGridNextPosition(
    { row: rowIndex, column: columnIndex },
    event.key as PayrollGridMoveKey,
    gridRows.value.length,
    gridDays.value.length,
  )
  event.preventDefault()
  if (next) focusGridCell(next.row, next.column)
}

function focusGridCell(row: number, column: number) {
  const target = gridBody.value?.querySelector<HTMLInputElement>(
    `[data-grid-pos="${row}-${column}"]`,
  )
  if (!target) return
  target.focus({ preventScroll: false })
  target.select()
}

/**
 * Vyplnit pracovní dny podle kalendáře vztahu.
 *
 * Přepisují se JEN prázdné buňky. Kdo si den ručně zkrátil, o to kliknutím na
 * hromadnou akci nepřijde — a svátky ani víkendy se neplní vůbec, protože je
 * kalendář (a v něm `CzechHolidayCalendar`) označuje jako nepracovní.
 */
function fillWorkdays() {
  if (gridFillBlockedReason.value) return
  let filled = 0
  let withoutCalendar = 0
  const drafts = { ...gridDrafts.value }
  for (const row of gridRows.value) {
    if (row.item.month.status !== 'open') continue
    if (!row.item.calendar) withoutCalendar += 1
    for (const day of gridDays.value) {
      const cell = row.cells.get(day.date)
      if (!cell?.workday) continue
      const key = gridKey(row.item.employment.id, day.date)
      const current = drafts[key] ?? formatPayrollGridHours(cell.minutes)
      if (current !== '') continue
      drafts[key] = formatPayrollGridHours(row.plannedMinutes(day.date) || GRID_FALLBACK_MINUTES)
      filled += 1
    }
  }
  gridDrafts.value = drafts
  gridNote.value = withoutCalendar > 0
    ? t('payroll.time.grid.filled_without_calendar', { count: filled, people: withoutCalendar })
    : t('payroll.time.grid.filled', { count: filled })
}

function previousPeriod(value: string): string {
  const match = /^(\d{4})-(\d{2})$/.exec(value)
  if (!match) return value
  const year = Number(match[1])
  const month = Number(match[2])
  const previous = month === 1 ? { year: year - 1, month: 12 } : { year, month: month - 1 }
  return `${previous.year}-${String(previous.month).padStart(2, '0')}`
}

/**
 * Zkopírovat minulý měsíc.
 *
 * Minulý měsíc se stránkuje jinak než tenhle (někdo nastoupil, někdo odešel),
 * takže se `offset` použít nedá — dohledává se podle `employment_id`, po
 * stránkách po dvou stech a nejvýše pěti požadavcích. Kdo se ve stropě nevešel,
 * se neututlá: řekne se to větou i s počtem.
 */
async function copyPreviousMonth() {
  if (!canWrite.value) return
  const needed = new Set(gridRows.value
    .filter(row => row.item.month.status === 'open')
    .map(row => row.item.employment.id))
  if (needed.size === 0) return
  gridSaving.value = true
  gridNote.value = null
  gridSaveError.value = null
  const source = previousPeriod(period.value)
  try {
    const found = new Map<number, PayrollTimeEntry[]>()
    let cursor = 0
    let known = Number.POSITIVE_INFINITY
    for (let page = 0; page < 5 && cursor < known && found.size < needed.size; page += 1) {
      const previous = await payrollApi.timeMonth(source, false, { limit: 200, offset: cursor })
      known = previous.total
      for (const item of previous.items) {
        if (needed.has(item.employment.id)) found.set(item.employment.id, item.entries ?? [])
      }
      cursor += previous.items.length
      if (previous.items.length === 0) break
    }
    const sourceDays = payrollMonthDays(source)
    const drafts = { ...gridDrafts.value }
    let copied = 0
    let skippedDays = 0
    for (const row of gridRows.value) {
      const entries = found.get(row.item.employment.id)
      if (!entries) continue
      for (const day of sourceDays) {
        const minutes = payrollGridCellState(entries, day.date, gridCategory.value).minutes
        if (minutes === 0) continue
        const target = gridDays.value.find(candidate => candidate.day === day.day)
        if (!target) {
          skippedDays += 1
          continue
        }
        const key = gridKey(row.item.employment.id, target.date)
        const current = drafts[key]
          ?? formatPayrollGridHours(row.cells.get(target.date)?.minutes ?? 0)
        if (current !== '') continue
        drafts[key] = formatPayrollGridHours(minutes)
        copied += 1
      }
    }
    gridDrafts.value = drafts
    gridNote.value = t('payroll.time.grid.copied', {
      count: copied,
      people: found.size,
      period: source,
      missing: needed.size - found.size,
      skipped: skippedDays,
    })
  } catch (error: any) {
    gridSaveError.value = error?.response?.data?.error?.message
      || t('payroll.time.grid.copy_failed')
  } finally {
    gridSaving.value = false
  }
}

function gridProblemMessage(problem: PayrollGridCellProblem): string {
  return t(`payroll.time.grid.problems.${problem}`)
}

/**
 * Uložení mřížky.
 *
 * Jeden požadavek na dávku, ne jeden na buňku — a dávka se skládá ZNOVU po
 * každém kole, protože každý uložený den zvedne verzi měsíce a druhá dávka by
 * s původními verzemi celá spadla na optimistický zámek. Odpověď nese už
 * přenačtenou TÚTÉŽ stránku přehledu, takže uložení stojí přesně tolik
 * požadavků, kolik je dávek.
 */
async function saveGrid() {
  if (gridBlockedReason.value) return
  gridSaving.value = true
  gridSaveError.value = null
  gridNote.value = null
  const errors: Record<string, string> = {}
  const remaining = new Set(gridDirtyKeys.value)
  let saved = 0
  let failed = 0
  try {
    for (let round = 0; round < 5 && remaining.size > 0; round += 1) {
      const context = new Map(gridRows.value.map(row => [row.item.employment.id, {
        entries: row.entries,
        monthRowVersion: row.item.month.row_version,
        open: row.item.month.status === 'open',
      }]))
      const drafts = [...remaining].flatMap(key => {
        const [employmentId, date] = key.split('|')
        return context.has(Number(employmentId))
          ? [{ employmentId: Number(employmentId), date, raw: gridDrafts.value[key] ?? '' }]
          : []
      })
      const build = buildPayrollGridBatch({
        drafts,
        category: gridCategory.value,
        startTime: gridStartTime.value,
        breakMinutes: gridBreakMinutes.value,
        timezone: timezone.value,
        context,
      })
      // Buňka, která se neposílá, je vyřízená v tomhle kole: buď je vadná
      // (a zůstane rozepsaná i s větou proč), nebo se od uloženého stavu neliší.
      const sent = new Set(build.keys)
      const nextDrafts = { ...gridDrafts.value }
      for (const key of [...remaining]) {
        if (sent.has(key)) continue
        remaining.delete(key)
        const problem = build.problems.get(key)
        if (problem) {
          errors[key] = gridProblemMessage(problem)
          failed += 1
        } else {
          delete nextDrafts[key]
        }
      }
      gridDrafts.value = nextDrafts
      if (build.cells.length === 0) break

      const chunk = build.cells.slice(0, GRID_BATCH_MAX)
      const chunkKeys = build.keys.slice(0, GRID_BATCH_MAX)
      const result = await payrollApi.saveTimeEntryBatch(
        { period: period.value, timezone: timezone.value, cells: chunk },
        { limit: pageSize, offset: offset.value },
        focusEmploymentId.value,
        incompleteOnly.value,
      )
      overview.value = result.month
      total.value = result.month.total
      const failures = new Map(result.failures.map(failure => [failure.index, failure]))
      const afterRound = { ...gridDrafts.value }
      chunkKeys.forEach((key, index) => {
        remaining.delete(key)
        const failure = failures.get(index)
        if (failure) {
          errors[key] = failure.message
          failed += 1
          return
        }
        delete afterRound[key]
        saved += 1
      })
      gridDrafts.value = afterRound
    }
    gridCellErrors.value = errors
    if (failed === 0) {
      toast.success(t('payroll.time.grid.saved', { count: saved }))
      return
    }
    // Částečný výsledek se nesmí tvářit ani jako úspěch, ani jako selhání —
    // uživatel musí vědět, kolik dnů prošlo a kolik na něj ještě čeká.
    gridSaveError.value = t('payroll.time.grid.saved_partially', { saved, failed })
    toast.error(gridSaveError.value)
  } catch (error: any) {
    gridCellErrors.value = errors
    const message: string = error?.response?.data?.error?.message
      || t('payroll.time.grid.save_failed')
    gridSaveError.value = message
    toast.error(message)
  } finally {
    gridSaving.value = false
  }
}

const gridActions = computed<ActionItem[]>(() => [
  {
    key: 'fill',
    label: t('payroll.time.grid.fill_workdays'),
    icon: 'calendar',
    tier: 'primary',
    variant: 'primary',
    show: canWrite.value,
    disabled: gridFillBlockedReason.value !== null || gridSaving.value,
    disabledReason: gridFillBlockedReason.value ?? undefined,
    run: fillWorkdays,
  },
  {
    key: 'copy',
    label: t('payroll.time.grid.copy_previous'),
    icon: 'cycle',
    tier: 'secondary',
    show: canWrite.value,
    disabled: gridSaving.value,
    run: () => void copyPreviousMonth(),
  },
  {
    key: 'import',
    label: t('payroll.time.grid.import'),
    icon: 'upload',
    tier: 'secondary',
    show: canWrite.value,
    run: () => { importOpen.value = true },
  },
])

/*
 * Schválení měsíce dnes materializuje zákonné příplatky ve stejné transakci,
 * takže může spadnout na chybějícím podkladu. Toast, který za pět vteřin
 * zmizí, je u téhle chyby k ničemu: uživatel musí vidět, CO chybí a KAM pro to
 * jít. Odtud panel s odkazem místo pouhé hlášky.
 */
type PayrollApproveErrorCode =
  | 'holiday_arrangement_missing'
  | 'difficulty_factors_missing'
  | 'average_earning_missing'
  | 'inputs_locked'
  | 'overtime_conflict'

const APPROVE_ERROR_CODES: PayrollApproveErrorCode[] = [
  'holiday_arrangement_missing',
  'difficulty_factors_missing',
  'average_earning_missing',
  'inputs_locked',
  'overtime_conflict',
]

const approveError = ref<{
  code: PayrollApproveErrorCode | null
  message: string
  name: string
  employmentId: number
} | null>(null)

const approveErrorTarget = computed<RouteLocationRaw | null>(() => {
  const failure = approveError.value
  if (!failure || failure.code === null) return null
  const employment = String(failure.employmentId)
  switch (failure.code) {
    case 'holiday_arrangement_missing':
    case 'difficulty_factors_missing':
      // Zásada příplatků visí na pracovním vztahu, tedy na kartě zaměstnance.
      return { name: 'payroll-people', query: { employment } }
    case 'average_earning_missing':
      return { name: 'payroll-absences', query: { employment, tab: 'averages' } }
    case 'overtime_conflict':
      return { name: 'payroll-quick-inputs', query: { employment } }
    case 'inputs_locked':
      return { name: 'payroll-runs' }
  }
})

function clearApproveError() {
  approveError.value = null
}

onMounted(() => {
  void load()
})
</script>

<template>
  <div class="space-y-6">
    <header class="flex flex-wrap items-start justify-between gap-3">
      <div>
        <h1 class="text-2xl font-semibold text-neutral-900">{{ t('payroll.time.title') }}</h1>
        <p class="mt-1 max-w-3xl text-sm text-neutral-500">{{ t('payroll.time.subtitle') }}</p>
      </div>
      <div class="flex flex-wrap items-center gap-2">
        <button v-if="canWrite" data-test="overtime-averaging-open" :class="btnOutline('neutral')" @click="openAveraging()">
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.cycle" /></svg>
          {{ t('payroll.time.overtime.averaging_action') }}
        </button>
        <button v-if="canWrite" :class="btnOutline('neutral')" @click="importOpen = !importOpen">
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.upload" /></svg>
          {{ t('payroll.time.import.button') }}
        </button>
        <button v-if="canWrite" :class="btnFilled('primary')" @click="openEditor()">
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.plus" /></svg>
          {{ t('payroll.time.add') }}
        </button>
      </div>
    </header>

    <section class="rounded-xl border border-neutral-200 bg-surface p-4 shadow-sm">
      <div class="flex flex-wrap items-end gap-4">
        <label class="block">
          <span class="mb-1 block text-xs font-medium text-neutral-600">{{ t('payroll.time.period') }}</span>
          <input v-model="period" type="month" class="h-9 rounded-md border border-neutral-300 bg-surface px-3 text-sm" @change="reload">
        </label>
        <label class="inline-flex h-9 items-center gap-2 text-sm text-neutral-700">
          <input v-model="incompleteOnly" type="checkbox" class="rounded border-neutral-300 text-payroll-600" @change="reload">
          {{ t('payroll.time.incomplete_only') }}
        </label>
        <button :class="btnOutline('neutral')" :disabled="loading" @click="load">
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.cycle" /></svg>
          {{ t('payroll.time.reload') }}
        </button>
        <button
          v-if="canApprove && selectedEmploymentIds.length > 0"
          :class="btnFilled('success')"
          :disabled="saving"
          @click="approveSelected"
        >
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.badgeCheck" /></svg>
          {{ t('payroll.time.bulk.approve', { count: selectedEmploymentIds.length }) }}
        </button>
      </div>
    </section>

    <!--
      Schválení materializuje zákonné příplatky, takže spadne i na chybějícím
      podkladu. Panel místo toastu: uživatel musí vidět, CO chybí a KAM jít.
    -->
    <section
      v-if="approveError"
      class="rounded-xl border border-danger-500/40 bg-danger-50 p-4 text-sm text-danger-700"
      data-test="approve-error"
    >
      <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
          <p class="font-semibold">{{ t('payroll.time.approve_failed') }}</p>
          <p class="mt-1 max-w-prose leading-snug">{{ approveError.message }}</p>
          <RouterLink
            v-if="approveErrorTarget"
            :to="approveErrorTarget"
            class="mt-2 inline-flex text-sm font-medium underline"
            data-test="approve-error-link"
          >{{ t(`payroll.time.approve_errors.link.${approveError.code}`) }}</RouterLink>
        </div>
        <button :class="btnOutline('neutral')" @click="clearApproveError">
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.x" /></svg>
          {{ t('common.close') }}
        </button>
      </div>
    </section>

    <section v-if="editorOpen" class="rounded-xl border border-payroll-500/30 bg-payroll-50 p-4 shadow-sm sm:p-6">
      <div class="flex flex-wrap items-start justify-between gap-3">
        <h2 class="text-lg font-semibold text-neutral-900">{{ t('payroll.time.editor.title') }}</h2>
        <button :class="btnOutline('neutral')" @click="editorOpen = false">
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.x" /></svg>
          {{ t('common.cancel') }}
        </button>
      </div>
      <!--
        Editor je `<form>` schválně: dokud jím nebyl, Enter v poli nedělal nic
        a záznam se dal uložit jen myší. Enter teď formulář odešle.
      -->
      <form data-test="time-record-form" @submit.prevent="saveRecord()">
      <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <label class="block">
          <span class="mb-1 block text-xs font-medium text-neutral-600">{{ t('payroll.time.editor.employment') }}</span>
          <SearchableSelect
            v-model="employmentId"
            :options="employmentOptions"
            :clearable="false"
            accent="payroll"
            data-test="payroll-time-employment"
            :aria-label="t('payroll.time.editor.employment')"
          />
        </label>
        <label class="block">
          <span class="mb-1 block text-xs font-medium text-neutral-600">{{ t('payroll.time.editor.type') }}</span>
          <select v-model="recordType" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm">
            <option value="entry">{{ t('payroll.time.editor.actual') }}</option>
            <option value="shift">{{ t('payroll.time.editor.shift') }}</option>
          </select>
        </label>
        <label v-if="recordType === 'entry'" class="block">
          <span class="mb-1 block text-xs font-medium text-neutral-600">{{ t('payroll.time.editor.category') }}</span>
          <select v-model="category" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm">
            <option v-for="item in categories" :key="item" :value="item">{{ t(`payroll.time.category.${item}`) }}</option>
          </select>
        </label>
        <label class="block">
          <span class="mb-1 block text-xs font-medium text-neutral-600">{{ t('payroll.time.editor.timezone') }}</span>
          <input v-model="timezone" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm">
        </label>
        <label class="block">
          <span class="mb-1 block text-xs font-medium text-neutral-600">{{ t('payroll.time.editor.starts') }}</span>
          <input v-model="startsAt" type="datetime-local" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm">
        </label>
        <label class="block">
          <span class="mb-1 block text-xs font-medium text-neutral-600">{{ t('payroll.time.editor.ends') }}</span>
          <input v-model="endsAt" type="datetime-local" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm">
        </label>
        <label class="block">
          <span class="mb-1 block text-xs font-medium text-neutral-600">{{ t('payroll.time.editor.break') }}</span>
          <input v-model.number="breakMinutes" type="number" min="0" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm">
        </label>
        <label v-if="recordType === 'shift'" class="block">
          <span class="mb-1 block text-xs font-medium text-neutral-600">{{ t('payroll.time.editor.standby') }}</span>
          <input v-model.number="standbyMinutes" type="number" min="0" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm">
        </label>
      </div>
      <div v-if="recordType === 'shift'" class="mt-4 flex flex-wrap gap-5">
        <label class="inline-flex items-center gap-2 text-sm"><input v-model="remoteWork" type="checkbox"> {{ t('payroll.time.editor.remote') }}</label>
        <label class="inline-flex items-center gap-2 text-sm"><input v-model="publish" type="checkbox"> {{ t('payroll.time.editor.publish') }}</label>
      </div>
      <div class="mt-5 flex flex-wrap justify-end gap-2">
        <div class="flex flex-col items-end gap-1.5">
          <div class="flex flex-wrap justify-end gap-2">
            <button
              type="button"
              :class="btnOutline('neutral')"
              :disabled="saving || !selected || !startsAt || !endsAt"
              :title="disabledTitle(recordBlockedReason !== null, recordBlockedReason)"
              data-test="time-record-save-next"
              @click="saveRecordAndContinue"
            >
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.plus" /></svg>
              {{ t('payroll.time.editor.save_and_next') }}
            </button>
            <button
              type="submit"
              :class="btnFilled('primary')"
              :disabled="saving || !selected || !startsAt || !endsAt"
              :title="disabledTitle(recordBlockedReason !== null, recordBlockedReason)"
              data-test="time-record-save"
            >
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.check" /></svg>
              {{ t('common.save') }}
            </button>
          </div>
          <p v-if="recordBlockedReason" :class="BTN_DISABLED_NOTE" data-test="time-record-save-blocked">
            {{ recordBlockedReason }}
          </p>
        </div>
      </div>
      </form>
    </section>

    <section v-if="importOpen" class="rounded-xl border border-neutral-200 bg-surface p-4 shadow-sm sm:p-6">
      <h2 class="text-lg font-semibold text-neutral-900">{{ t('payroll.time.import.title') }}</h2>
      <p class="mt-1 text-sm text-neutral-500">{{ t('payroll.time.import.hint') }}</p>
      <div class="mt-4 space-y-4">
        <PayrollFileDropzone
          dropzone-test-id="payroll-time-import-dropzone"
          input-test-id="payroll-time-import-file"
          selected-test-id="payroll-time-import-selected"
          :disabled="saving"
          :selected-file-name="importName"
          :error="importFileError"
          :drop-hint="t('payroll.time.import.drop_hint')"
          :drop-active-hint="t('payroll.time.import.drop_active')"
          :file-hint="t('payroll.time.import.file_limit')"
          :choose-file-text="t('payroll.time.import.choose_file')"
          :selected-text="importName ? t('payroll.time.import.selected_file', { name: importName }) : ''"
          @selected="loadImportFile"
          @rejected="rejectImportFile"
        />
        <div class="flex flex-wrap items-center gap-3">
          <button :class="btnOutline('neutral')" :disabled="saving || !importContent" @click="previewImport">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.search" /></svg>
            {{ t('payroll.time.import.preview') }}
          </button>
          <div v-if="importPreview" class="flex flex-col gap-1.5">
            <button
              :class="btnFilled('primary')"
              :disabled="saving || !importPreview.supported"
              :title="disabledTitle(importBlockedReason !== null, importBlockedReason)"
              data-test="time-import-apply"
              @click="applyImport"
            >
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.upload" /></svg>
              {{ t('payroll.time.import.apply') }}
            </button>
            <p v-if="importBlockedReason" :class="BTN_DISABLED_NOTE" data-test="time-import-apply-blocked">
              {{ importBlockedReason }}
            </p>
          </div>
        </div>
      </div>
      <div v-if="importPreview" class="mt-4 rounded-lg bg-neutral-50 p-4 text-sm">
        <p>{{ t('payroll.time.import.summary', importPreview) }}</p>
        <p v-if="importFormat === 'xlsx'" class="mt-2 text-neutral-600">{{ t('payroll.time.import.xlsx_security') }}</p>
        <ul v-if="importPreview.errors.length" class="mt-3 space-y-1 text-danger-600">
          <li v-for="error in importPreview.errors" :key="`${error.row_number}-${error.error_code}`">
            {{ t('payroll.time.import.row_error', { row: error.row_number, message: error.error_message }) }}
          </li>
        </ul>
      </div>
    </section>

    <!--
      Zúžení dělá server, takže lišta už nemá co „ořezávat" — buď vztah v období
      docházku má, nebo ho seznam nemá vůbec a řekne to větou.
    -->
    <PayrollFocusNotice
      v-if="focusName"
      :name="focusName"
      @clear="clearFocus"
    />
    <PayrollFocusNotice
      v-else-if="focusMissing"
      :name="String(focusEmploymentId)"
      missing
      @clear="clearFocus"
    />

    <!--
      ─── Měsíční mřížka ────────────────────────────────────────────────────
      Od tabletu výš. Jedenatřicet sloupců se na telefon nevejde ani ve scrollu:
      buňka by byla užší než prst a zadání dne by skončilo překlepem, který se
      pozná až na výplatní pásce. Na mobilu proto zůstává řádkové zadání
      (editor + karty níž) a mřížka se nabídne větou.
    -->
    <section
      v-if="!loading && !loadFailed && gridRows.length > 0"
      class="hidden rounded-xl border border-neutral-200 bg-surface shadow-sm md:block"
      data-test="payroll-time-grid"
    >
      <div class="flex flex-wrap items-start justify-between gap-3 border-b border-neutral-200 p-4">
        <div>
          <h2 class="font-semibold text-neutral-900">{{ t('payroll.time.grid.title') }}</h2>
          <p class="mt-1 max-w-prose text-sm text-neutral-500">{{ t('payroll.time.grid.subtitle') }}</p>
        </div>
        <ActionBar :actions="gridActions" />
      </div>

      <div class="flex flex-wrap items-end gap-4 border-b border-neutral-200 px-4 py-3">
        <label class="block">
          <span class="mb-1 block text-xs font-medium text-neutral-600">{{ t('payroll.time.grid.category') }}</span>
          <select
            v-model="gridCategory"
            class="h-9 rounded-md border border-neutral-300 bg-surface px-3 text-sm"
            data-test="grid-category"
          >
            <option v-for="item in categories" :key="item" :value="item">{{ t(`payroll.time.category.${item}`) }}</option>
          </select>
        </label>
        <label class="block">
          <span class="mb-1 block text-xs font-medium text-neutral-600">{{ t('payroll.time.grid.start_time') }}</span>
          <input v-model="gridStartTime" type="time" class="h-9 rounded-md border border-neutral-300 bg-surface px-3 text-sm" data-test="grid-start-time">
        </label>
        <label class="block">
          <span class="mb-1 block text-xs font-medium text-neutral-600">{{ t('payroll.time.grid.break') }}</span>
          <input v-model.number="gridBreakMinutes" type="number" min="0" class="h-9 w-24 rounded-md border border-neutral-300 bg-surface px-3 text-sm" data-test="grid-break">
        </label>
        <p class="max-w-prose text-xs text-neutral-500">{{ t('payroll.time.grid.hours_hint') }}</p>
      </div>

      <!--
        Příznaky nad hodinami se nesčítají do odpracované doby. Věta tu není
        pro parádu: bez ní by uživatel čekal, že „noční 8h" přidá osm hodin.
      -->
      <p
        v-if="!gridEditableCategory"
        class="border-b border-warning-200 bg-warning-50 px-4 py-2 text-xs text-warning-800"
        data-test="grid-flag-notice"
      >{{ t('payroll.time.grid.flag_notice') }}</p>

      <p v-if="gridNote" class="border-b border-neutral-200 bg-neutral-50 px-4 py-2 text-sm text-neutral-700" data-test="grid-note">
        {{ gridNote }}
      </p>
      <div
        v-if="gridSaveError"
        class="border-b border-danger-200 bg-danger-50 px-4 py-3 text-sm text-danger-700"
        data-test="grid-save-error"
      >
        <p class="font-medium">{{ gridSaveError }}</p>
        <ul v-if="gridCellErrorList.length" class="mt-2 space-y-1 text-xs">
          <li v-for="failure in gridCellErrorList" :key="failure.key">
            {{ failure.name }} · {{ failure.date }} — {{ failure.message }}
          </li>
        </ul>
        <p v-if="gridCellErrorCount > gridCellErrorList.length" class="mt-1 text-xs">
          {{ t('payroll.time.grid.more_errors', { count: gridCellErrorCount - gridCellErrorList.length }) }}
        </p>
      </div>

      <div ref="gridBody" class="overflow-x-auto">
        <table class="min-w-full border-separate border-spacing-0 text-xs">
          <thead>
            <tr class="text-neutral-500">
              <th scope="col" class="sticky left-0 z-10 bg-surface px-3 py-2 text-left font-medium">
                {{ t('payroll.time.columns.employee') }}
              </th>
              <th
                v-for="day in gridDays"
                :key="day.date"
                scope="col"
                class="px-1 py-2 text-center font-medium"
                :class="day.weekend ? 'bg-neutral-50 text-neutral-400' : ''"
              >
                <span class="block">{{ day.day }}</span>
                <span class="block text-[10px] uppercase">{{ t(`payroll.time.grid.weekdays.${day.weekday}`) }}</span>
              </th>
              <th scope="col" class="px-3 py-2 text-right font-medium">{{ t('payroll.time.grid.worked') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(row, rowIndex) in gridRows" :key="row.item.employment.id" class="border-t border-neutral-100">
              <th scope="row" class="sticky left-0 z-10 max-w-[16rem] bg-surface px-3 py-1 text-left font-normal">
                <span class="block truncate font-medium text-neutral-900">{{ row.item.employment.full_name }}</span>
                <span class="block font-mono text-[10px] text-neutral-400">{{ row.item.employment.code }}</span>
              </th>
              <td
                v-for="(day, dayIndex) in gridDays"
                :key="day.date"
                class="px-0.5 py-1 text-center"
                :class="!row.cells.get(day.date)?.workday ? 'bg-neutral-50' : ''"
              >
                <input
                  :data-grid-pos="`${rowIndex}-${dayIndex}`"
                  :data-test="`grid-cell-${row.item.employment.id}-${day.date}`"
                  :value="gridValue(row, day.date)"
                  :disabled="!canWrite || row.item.month.status !== 'open' || gridCellLocked(row, day.date) || gridSaving"
                  :title="gridCellTitle(row, day.date)"
                  :aria-label="t('payroll.time.grid.cell_label', { name: row.item.employment.full_name, date: day.date })"
                  :aria-invalid="Boolean(gridCellErrors[gridKey(row.item.employment.id, day.date)])"
                  class="h-8 w-12 rounded border bg-surface px-1 text-center disabled:bg-neutral-100 disabled:text-neutral-400"
                  :class="gridCellClass(row, day.date)"
                  inputmode="decimal"
                  autocomplete="off"
                  @input="setGridValue(row.item.employment.id, day.date, ($event.target as HTMLInputElement).value)"
                  @keydown="onGridKeydown($event, rowIndex, dayIndex)"
                >
                <span
                  v-if="gridCellFlags(row, day.date)"
                  class="mt-0.5 block text-[9px] leading-none text-payroll-600"
                  aria-hidden="true"
                >•</span>
              </td>
              <td class="px-3 py-1 text-right font-medium text-neutral-700">
                {{ formatPayrollMinutes(row.workedTotal) }}
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!--
        Jedno společné Uložit dole, ne tlačítko u každého řádku — mřížka je
        jeden formulář o sedmi stech políčkách, ne dvacet pět formulářů.
      -->
      <div class="sticky bottom-0 flex flex-wrap items-center justify-end gap-3 border-t border-neutral-200 bg-surface/95 px-4 py-3">
        <p class="mr-auto text-xs text-neutral-500">{{ t('payroll.time.grid.keyboard_hint') }}</p>
        <div class="flex flex-col items-end gap-1.5">
          <button
            :class="btnFilled('primary')"
            :disabled="gridBlockedReason !== null || gridSaving"
            :title="disabledTitle(gridBlockedReason !== null, gridBlockedReason)"
            data-test="grid-save"
            @click="saveGrid"
          >
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.check" /></svg>
            {{ t('payroll.time.grid.save', { count: gridDirtyCount }) }}
          </button>
          <p v-if="gridBlockedReason" :class="BTN_DISABLED_NOTE" data-test="grid-save-blocked">
            {{ gridBlockedReason }}
          </p>
        </div>
      </div>
    </section>
    <p
      v-if="!loading && !loadFailed && gridRows.length > 0"
      class="rounded-lg border border-neutral-200 bg-neutral-50 px-4 py-3 text-sm text-neutral-600 md:hidden"
      data-test="grid-mobile-note"
    >{{ t('payroll.time.grid.mobile_note') }}</p>

    <div v-if="loading" class="space-y-3">
      <div v-for="index in 4" :key="index" class="h-28 animate-pulse rounded-xl bg-neutral-100" />
    </div>
    <EmptyState
      v-else-if="loadFailed"
      variant="failed"
      boxed
      data-test="load-failed"
      :message="t('payroll.time.load_failed_hint')"
      @action="load"
    />
    <section v-else-if="!overview?.items.length" class="rounded-xl border border-neutral-200 bg-surface p-8 text-center">
      <h2 class="font-semibold text-neutral-900">{{ t('payroll.time.empty') }}</h2>
      <p class="mt-1 text-sm text-neutral-500">{{ t('payroll.time.empty_hint') }}</p>
    </section>
    <section v-else class="rounded-xl border border-neutral-200 bg-surface shadow-sm">
      <div class="hidden flex-wrap items-center justify-end gap-2 border-b border-neutral-200 px-4 py-2 md:flex">
        <ColumnPicker :ctrl="tbl" />
        <DensityToggle :ctrl="tbl" />
      </div>
      <div class="hidden overflow-x-auto md:block">
        <table data-test="payroll-time-summary" class="min-w-full divide-y divide-neutral-200 text-sm" :class="tbl.densityClass.value">
          <thead><tr class="text-left text-xs uppercase tracking-wide text-neutral-500">
            <th class="w-10 px-4 py-3">
              <input
                type="checkbox"
                :checked="allSelectableSelected"
                :aria-label="t('payroll.time.bulk.select_all')"
                @change="toggleAllVisible"
              >
            </th>
            <th class="px-4 py-3">{{ t('payroll.time.columns.employee') }}</th>
            <th v-if="tbl.isVisible('fund')" class="px-4 py-3">{{ t('payroll.time.columns.fund') }}</th>
            <th v-if="tbl.isVisible('plan')" class="px-4 py-3">{{ t('payroll.time.columns.plan') }}</th>
            <th v-if="tbl.isVisible('actual')" class="px-4 py-3">{{ t('payroll.time.columns.actual') }}</th>
            <th v-if="tbl.isVisible('difference')" class="px-4 py-3">{{ t('payroll.time.columns.difference') }}</th>
            <th v-if="tbl.isVisible('status')" class="px-4 py-3">{{ t('payroll.time.columns.status') }}</th>
            <th class="px-4 py-3 text-right">{{ t('payroll.time.columns.actions') }}</th>
          </tr></thead>
          <tbody class="divide-y divide-neutral-100">
            <template v-for="item in visibleItems" :key="item.employment.id">
            <tr>
              <td class="px-4 py-3">
                <input
                  v-if="item.month.status === 'open'"
                  type="checkbox"
                  :checked="selectedEmploymentIds.includes(item.employment.id)"
                  :aria-label="t('payroll.time.bulk.select', { name: item.employment.full_name })"
                  @change="toggleSelection(item.employment.id)"
                >
              </td>
              <td class="px-4 py-3"><p class="font-medium text-neutral-900">{{ item.employment.full_name }}</p><p class="text-xs text-neutral-500">{{ relationLabel(item.employment.relation_type) }}</p><p class="font-mono text-[11px] text-neutral-400">{{ item.employment.code }}</p></td>
              <td v-if="tbl.isVisible('fund')" class="px-4 py-3">{{ formatPayrollMinutes(item.summary.fund_minutes) }}</td>
              <td v-if="tbl.isVisible('plan')" class="px-4 py-3">{{ formatPayrollMinutes(item.summary.planned_minutes) }}</td>
              <td v-if="tbl.isVisible('actual')" class="px-4 py-3">{{ formatPayrollMinutes(item.summary.actual_minutes) }}</td>
              <td v-if="tbl.isVisible('difference')" class="px-4 py-3" :class="item.summary.difference_minutes === 0 ? 'text-success-600' : 'text-warning-700'">{{ formatPayrollMinutes(item.summary.difference_minutes) }}</td>
              <td v-if="tbl.isVisible('status')" class="px-4 py-3"><span class="rounded-full px-2 py-1 text-xs font-medium" :class="item.month.status === 'approved' ? 'bg-success-50 text-success-600' : item.summary.incomplete ? 'bg-warning-50 text-warning-700' : 'bg-payroll-50 text-payroll-600'">{{ t(`payroll.time.status.${item.month.status === 'approved' ? 'approved' : item.summary.incomplete ? 'incomplete' : 'open'}`) }}</span></td>
              <td class="px-4 py-3"><div class="flex flex-wrap justify-end gap-2">
                <button v-if="canWrite && item.month.status === 'open'" :class="btnOutline('neutral')" @click="openEditor(item)"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.plus" /></svg>{{ t('payroll.time.add') }}</button>
                <button v-if="canWrite && item.month.status === 'open'" :class="btnOutline('neutral')" :disabled="saving" @click="createCalendar(item)"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.cycle" /></svg>{{ t(item.calendar ? 'payroll.time.calendar.new_version' : 'payroll.time.calendar.create') }}</button>
                <button v-if="canApprove && item.month.status === 'open'" :class="btnOutline('success')" :disabled="saving" @click="openApproval(item)"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.badgeCheck" /></svg>{{ t('payroll.time.approve') }}</button>
                <button v-if="canWrite" :class="btnOutline('neutral')" :disabled="saving" @click="openConsent(item)"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.doc" /></svg>{{ t('payroll.time.overtime.consent_action') }}</button>
                <button v-if="canReopen && item.month.status === 'approved'" :class="btnOutline('warning')" :disabled="saving" @click="openReopen(item)"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.uturn" /></svg>{{ t('payroll.time.reopen') }}</button>
              </div></td>
            </tr>
            <tr v-if="overtimeVisible(item)" :data-test="`overtime-limits-${item.employment.id}`">
              <td />
              <td :colspan="detailColspan" class="px-4 pb-4">
                <div class="rounded-lg border px-3 py-2 text-sm" :class="overtimePanelClass(item)">
                  <p class="text-xs font-semibold uppercase tracking-wide">{{ t('payroll.time.overtime.title') }}</p>
                  <p
                    v-if="overtimeProhibitions(item).length"
                    data-test="overtime-prohibition-banner"
                    class="mt-1 max-w-prose text-xs font-semibold leading-snug"
                  >{{ t('payroll.time.overtime.prohibition_banner') }}</p>
                  <p
                    v-for="finding in item.overtime_limits?.findings ?? []"
                    :key="finding.code + finding.scope_from"
                    class="mt-1 max-w-prose leading-snug"
                    :data-test="`overtime-finding-${finding.code}`"
                  >
                    <span class="mr-2 rounded bg-white/70 px-1.5 py-0.5 text-[11px] font-medium">{{ finding.provision }}</span>
                    {{ finding.message }}
                  </p>
                  <p class="mt-1 text-xs">{{ overtimeYearSummary(item) }} {{ overtimeConsentSummary(item) }}</p>
                  <p
                    v-if="overtimeAveragingSummary(item)"
                    class="mt-1 text-xs"
                    :data-test="`overtime-averaging-${item.employment.id}`"
                  >{{ overtimeAveragingSummary(item) }}</p>
                  <!--
                    Náhradní volno se eviduje na dvou místech (absence = den
                    čerpání, kompenzace = den přesčasu). Jednostranný zápis je
                    tichá vada, tak se pojmenuje místo aby se nechal být.
                  -->
                  <p
                    v-for="code in item.compensatory_time_off_check?.findings ?? []"
                    :key="code"
                    class="mt-1 max-w-prose text-xs font-medium leading-snug text-warning-800"
                    :data-test="`compensatory-time-off-${code}`"
                  >{{ t(`payroll.time.overtime.compensatory_check.${code}`) }}</p>
                  <div v-if="canWrite" class="mt-2 flex flex-wrap gap-2">
                    <button :class="btnOutline('neutral')" :disabled="saving" @click="openProtection(item)"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.lock" /></svg>{{ t('payroll.time.overtime.protection_action') }}</button>
                    <button :class="btnOutline('neutral')" :disabled="saving" @click="openCompensation(item)"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.cycle" /></svg>{{ t('payroll.time.overtime.compensation_action') }}</button>
                  </div>
                </div>
              </td>
            </tr>
            </template>
          </tbody>
        </table>
      </div>
      <div class="space-y-3 p-4 md:hidden">
        <article v-for="item in visibleItems" :key="item.employment.id" class="rounded-lg border border-neutral-200 p-4">
          <div class="flex flex-wrap items-start justify-between gap-2"><div class="flex items-start gap-3"><input v-if="item.month.status === 'open'" type="checkbox" class="mt-1" :checked="selectedEmploymentIds.includes(item.employment.id)" :aria-label="t('payroll.time.bulk.select', { name: item.employment.full_name })" @change="toggleSelection(item.employment.id)"><div><h2 class="font-semibold text-neutral-900">{{ item.employment.full_name }}</h2><p class="text-xs text-neutral-500">{{ relationLabel(item.employment.relation_type) }}</p><p class="font-mono text-[11px] text-neutral-400">{{ item.employment.code }}</p></div></div><span class="rounded-full px-2 py-1 text-xs font-medium" :class="item.month.status === 'approved' ? 'bg-success-50 text-success-600' : item.summary.incomplete ? 'bg-warning-50 text-warning-700' : 'bg-payroll-50 text-payroll-600'">{{ t(`payroll.time.status.${item.month.status === 'approved' ? 'approved' : item.summary.incomplete ? 'incomplete' : 'open'}`) }}</span></div>
          <dl class="mt-4 grid grid-cols-2 gap-3 text-sm"><div><dt class="text-xs text-neutral-500">{{ t('payroll.time.columns.fund') }}</dt><dd>{{ formatPayrollMinutes(item.summary.fund_minutes) }}</dd></div><div><dt class="text-xs text-neutral-500">{{ t('payroll.time.columns.plan') }}</dt><dd>{{ formatPayrollMinutes(item.summary.planned_minutes) }}</dd></div><div><dt class="text-xs text-neutral-500">{{ t('payroll.time.columns.actual') }}</dt><dd>{{ formatPayrollMinutes(item.summary.actual_minutes) }}</dd></div><div><dt class="text-xs text-neutral-500">{{ t('payroll.time.columns.difference') }}</dt><dd>{{ formatPayrollMinutes(item.summary.difference_minutes) }}</dd></div></dl>
          <div
            v-if="overtimeVisible(item)"
            class="mt-4 rounded-lg border px-3 py-2 text-sm"
            :class="overtimePanelClass(item)"
          >
            <p class="text-xs font-semibold uppercase tracking-wide">{{ t('payroll.time.overtime.title') }}</p>
            <p
              v-if="overtimeProhibitions(item).length"
              class="mt-1 text-xs font-semibold leading-snug"
            >{{ t('payroll.time.overtime.prohibition_banner') }}</p>
            <p
              v-for="finding in item.overtime_limits?.findings ?? []"
              :key="finding.code + finding.scope_from"
              class="mt-1 leading-snug"
            >
              <span class="mr-2 rounded bg-white/70 px-1.5 py-0.5 text-[11px] font-medium">{{ finding.provision }}</span>
              {{ finding.message }}
            </p>
            <p class="mt-1 text-xs">{{ overtimeYearSummary(item) }} {{ overtimeConsentSummary(item) }}</p>
            <p v-if="overtimeAveragingSummary(item)" class="mt-1 text-xs">{{ overtimeAveragingSummary(item) }}</p>
          </div>
          <div class="mt-4 flex flex-wrap gap-2">
            <button v-if="canWrite" :class="btnOutline('neutral')" :disabled="saving" @click="openConsent(item)"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.doc" /></svg>{{ t('payroll.time.overtime.consent_action') }}</button>
            <button v-if="canWrite" :class="btnOutline('neutral')" :disabled="saving" @click="openProtection(item)"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.lock" /></svg>{{ t('payroll.time.overtime.protection_action') }}</button>
            <button v-if="canWrite" :class="btnOutline('neutral')" :disabled="saving" @click="openCompensation(item)"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.cycle" /></svg>{{ t('payroll.time.overtime.compensation_action') }}</button>
            <button v-if="canWrite && item.month.status === 'open'" :class="btnOutline('neutral')" @click="openEditor(item)"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.plus" /></svg>{{ t('payroll.time.add') }}</button>
            <button v-if="canWrite && item.month.status === 'open'" :class="btnOutline('neutral')" :disabled="saving" @click="createCalendar(item)"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.cycle" /></svg>{{ t(item.calendar ? 'payroll.time.calendar.new_version' : 'payroll.time.calendar.create') }}</button>
            <button v-if="canApprove && item.month.status === 'open'" :class="btnOutline('success')" @click="openApproval(item)"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.badgeCheck" /></svg>{{ t('payroll.time.approve') }}</button>
            <button v-if="canReopen && item.month.status === 'approved'" :class="btnOutline('warning')" @click="openReopen(item)"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.uturn" /></svg>{{ t('payroll.time.reopen') }}</button>
          </div>
        </article>
      </div>
      <PaginationBar
        embedded
        :page="currentPage"
        :per-page="pageSize"
        :total="total"
        @update:page="goToPage"
      />
    </section>

    <Modal
      v-if="approvalItem"
      :title="t('payroll.time.jmhz.title')"
      width-class="max-w-2xl"
      @close="closeApproval"
    >
      <form data-test="jmhz-work-summary-form" class="space-y-4" @submit.prevent="approve">
        <p class="text-sm text-neutral-600">
          {{ approvalItem.employment.full_name }} · {{ approvalItem.employment.code }}
        </p>
        <p class="rounded-lg border border-payroll-200 bg-payroll-50 p-3 text-sm text-payroll-800">
          {{ t('payroll.time.jmhz.hint') }}
        </p>
        <ul
          v-if="approvalItem.jmhz_work_summary.preview?.issues.length"
          class="space-y-1 rounded-lg border border-danger-200 bg-danger-50 p-3 text-sm text-danger-700"
        >
          <li v-for="issue in approvalItem.jmhz_work_summary.preview.issues" :key="issue.code">
            {{ issue.message }}
          </li>
        </ul>
        <p
          v-if="approvalItem.jmhz_work_summary.preview?.requires_unworked_hours_followup"
          class="rounded-lg border border-warning-200 bg-warning-50 p-3 text-sm text-warning-700"
        >
          {{ t('payroll.time.jmhz.unworked_evidence_hint') }}
        </p>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <label class="block">
            <span class="mb-1 block text-sm font-medium text-neutral-700">{{ t('payroll.time.jmhz.standard_fund') }}</span>
            <input v-model="approvalStandardFund" data-test="jmhz-standard-fund" inputmode="decimal" required class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm">
          </label>
          <label class="block">
            <span class="mb-1 block text-sm font-medium text-neutral-700">{{ t('payroll.time.jmhz.agreed_fund') }}</span>
            <input v-model="approvalAgreedFund" data-test="jmhz-agreed-fund" inputmode="decimal" required class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm">
          </label>
          <label class="block">
            <span class="mb-1 block text-sm font-medium text-neutral-700">{{ t('payroll.time.jmhz.weekly_work') }}</span>
            <input v-model="approvalWeeklyWork" data-test="jmhz-weekly-work" inputmode="decimal" required class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm">
          </label>
          <label class="block">
            <span class="mb-1 block text-sm font-medium text-neutral-700">{{ t('payroll.time.jmhz.worked') }}</span>
            <input v-model="approvalWorked" data-test="jmhz-worked" inputmode="decimal" required class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm">
          </label>
        </div>
        <p class="text-sm text-neutral-600">
          {{ t('payroll.time.jmhz.evidence_days', { count: approvalItem.jmhz_work_summary.preview?.suggestions.evidence_days ?? 0 }) }}
        </p>
        <fieldset class="space-y-2 rounded-lg border border-neutral-200 p-3">
          <legend class="px-1 text-sm font-medium text-neutral-700">
            {{ t('payroll.time.jmhz.unworked_occurred') }}
          </legend>
          <div class="flex flex-wrap gap-5">
            <label class="inline-flex items-center gap-2 text-sm">
              <input data-test="jmhz-unworked-yes" type="radio" name="jmhz-unworked" :checked="approvalUnworkedOccurred === true" @change="setUnworkedOccurred(true)">
              {{ t('common.yes') }}
            </label>
            <label class="inline-flex items-center gap-2 text-sm">
              <input data-test="jmhz-unworked-no" type="radio" name="jmhz-unworked" :checked="approvalUnworkedOccurred === false" @change="setUnworkedOccurred(false)">
              {{ t('common.no') }}
            </label>
          </div>
        </fieldset>
        <div v-if="approvalUnworkedOccurred === true" class="grid grid-cols-1 gap-4 rounded-lg border border-neutral-200 p-3 sm:grid-cols-2">
          <label class="block sm:col-span-2">
            <span class="mb-1 block text-sm font-medium text-neutral-700">{{ t('payroll.time.jmhz.unworked_total') }}</span>
            <input v-model="approvalUnworkedTotal" data-test="jmhz-unworked-total" inputmode="decimal" required class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm">
          </label>
          <label class="block">
            <span class="mb-1 block text-sm font-medium text-neutral-700">{{ t('payroll.time.jmhz.unworked_paid') }}</span>
            <input v-model="approvalUnworkedPaid" data-test="jmhz-unworked-paid" inputmode="decimal" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm">
          </label>
          <label class="block">
            <span class="mb-1 block text-sm font-medium text-neutral-700">{{ t('payroll.time.jmhz.dpn_without_compensation') }}</span>
            <input v-model="approvalDpnWithoutCompensation" data-test="jmhz-dpn-without-compensation" inputmode="decimal" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm">
          </label>
          <label class="block">
            <span class="mb-1 block text-sm font-medium text-neutral-700">{{ t('payroll.time.jmhz.dpn_with_compensation') }}</span>
            <input v-model="approvalDpnWithCompensation" data-test="jmhz-dpn-with-compensation" inputmode="decimal" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm">
          </label>
          <label class="block">
            <span class="mb-1 block text-sm font-medium text-neutral-700">{{ t('payroll.time.jmhz.vacation') }}</span>
            <input v-model="approvalVacation" data-test="jmhz-vacation" inputmode="decimal" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm">
          </label>
          <label class="block">
            <span class="mb-1 block text-sm font-medium text-neutral-700">{{ t('payroll.time.jmhz.care') }}</span>
            <input v-model="approvalCare" data-test="jmhz-care" inputmode="decimal" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm">
          </label>
        </div>
        <fieldset class="space-y-2 rounded-lg border border-neutral-200 p-3">
          <legend class="px-1 text-sm font-medium text-neutral-700">
            {{ t('payroll.time.jmhz.obstacles_occurred') }}
          </legend>
          <div class="flex flex-wrap gap-5">
            <label class="inline-flex items-center gap-2 text-sm">
              <input data-test="jmhz-obstacles-yes" type="radio" name="jmhz-obstacles" :disabled="approvalUnworkedOccurred !== true" :checked="approvalObstaclesOccurred === true" @change="setObstaclesOccurred(true)">
              {{ t('common.yes') }}
            </label>
            <label class="inline-flex items-center gap-2 text-sm">
              <input data-test="jmhz-obstacles-no" type="radio" name="jmhz-obstacles" :checked="approvalObstaclesOccurred === false" @change="setObstaclesOccurred(false)">
              {{ t('common.no') }}
            </label>
          </div>
        </fieldset>
        <div v-if="approvalObstaclesOccurred === true" class="grid grid-cols-1 gap-4 rounded-lg border border-neutral-200 p-3 sm:grid-cols-2">
          <label class="block">
            <span class="mb-1 block text-sm font-medium text-neutral-700">{{ t('payroll.time.jmhz.employee_obstacle') }}</span>
            <input v-model="approvalEmployeeObstacle" data-test="jmhz-employee-obstacle" inputmode="decimal" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm">
          </label>
          <label class="block">
            <span class="mb-1 block text-sm font-medium text-neutral-700">{{ t('payroll.time.jmhz.employer_obstacle') }}</span>
            <input v-model="approvalEmployerObstacle" data-test="jmhz-employer-obstacle" inputmode="decimal" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm">
          </label>
        </div>
        <label class="block">
          <span class="mb-1 block text-sm font-medium text-neutral-700">{{ t('payroll.time.jmhz.note') }}</span>
          <textarea v-model="approvalNote" data-test="jmhz-note" maxlength="500" rows="3" class="w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm" />
        </label>
        <div class="flex flex-wrap justify-end gap-2">
          <button type="button" :class="btnOutline('neutral')" :disabled="saving" @click="closeApproval">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.x" /></svg>
            {{ t('common.cancel') }}
          </button>
          <button
            type="submit"
            :class="btnFilled('success')"
            :disabled="saving || !approvalConditionalComplete || Boolean(approvalItem.jmhz_work_summary.preview?.issues.length)"
          >
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.badgeCheck" /></svg>
            {{ t('payroll.time.jmhz.confirm') }}
          </button>
        </div>
      </form>
    </Modal>

    <Modal
      v-if="consentItem"
      :title="t('payroll.time.overtime.consent_title')"
      width-class="max-w-lg"
      @close="closeConsent"
    >
      <div data-test="overtime-consent-modal">
        <p class="mb-2 text-sm text-neutral-600">
          {{ consentItem.employment.full_name }} · {{ consentItem.employment.code }}
        </p>
        <p class="mb-4 max-w-prose text-sm text-neutral-600">
          {{ t('payroll.time.overtime.consent_hint') }}
        </p>
        <form data-test="overtime-consent-form" class="space-y-4" @submit.prevent="saveConsent">
          <div class="grid gap-4 sm:grid-cols-2">
            <label class="block">
              <span class="mb-1 block text-sm font-medium text-neutral-700">{{ t('payroll.time.overtime.consent_valid_from') }}</span>
              <input v-model="consentValidFrom" data-test="overtime-consent-valid-from" type="date" required class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm">
            </label>
            <label class="block">
              <span class="mb-1 block text-sm font-medium text-neutral-700">{{ t('payroll.time.overtime.consent_valid_to') }}</span>
              <input v-model="consentValidTo" data-test="overtime-consent-valid-to" type="date" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm">
            </label>
          </div>
          <label class="block">
            <span class="mb-1 block text-sm font-medium text-neutral-700">{{ t('payroll.time.overtime.consent_reference') }}</span>
            <input v-model="consentReference" data-test="overtime-consent-reference" maxlength="191" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm">
          </label>
          <label class="block">
            <span class="mb-1 block text-sm font-medium text-neutral-700">{{ t('payroll.time.overtime.consent_note') }}</span>
            <textarea v-model="consentNote" data-test="overtime-consent-note" maxlength="500" rows="3" class="w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm" />
          </label>
          <p v-if="consentError" data-test="overtime-consent-error" class="text-sm text-danger-500">{{ consentError }}</p>
          <div class="flex flex-wrap justify-end gap-2">
            <button type="button" :class="btnOutline('neutral')" :disabled="saving" @click="closeConsent">
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.x" /></svg>
              {{ t('common.cancel') }}
            </button>
            <button
              type="submit"
              data-test="overtime-consent-save"
              :class="btnFilled('primary')"
              :disabled="saving || Boolean(consentBlockedReason)"
              :title="disabledTitle(Boolean(consentBlockedReason), consentBlockedReason)"
            >
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.check" /></svg>
              {{ t('common.save') }}
            </button>
          </div>
          <p v-if="consentBlockedReason" :class="BTN_DISABLED_NOTE" data-test="overtime-consent-save-blocked">
            {{ consentBlockedReason }}
          </p>
        </form>
      </div>
    </Modal>

    <Modal
      v-if="protectionItem"
      :title="t('payroll.time.overtime.protection_title')"
      width-class="max-w-lg"
      @close="closeProtection"
    >
      <div data-test="overtime-protection-modal">
        <p class="mb-2 text-sm text-neutral-600">
          {{ protectionItem.employment.full_name }} · {{ protectionItem.employment.code }}
        </p>
        <p class="mb-4 max-w-prose text-sm text-neutral-600">
          {{ t('payroll.time.overtime.protection_hint') }}
        </p>
        <form data-test="overtime-protection-form" class="space-y-4" @submit.prevent="saveProtection">
          <label class="block">
            <span class="mb-1 block text-sm font-medium text-neutral-700">{{ t('payroll.time.overtime.protection_kind') }}</span>
            <select v-model="protectionKind" data-test="overtime-protection-kind" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm">
              <option value="pregnancy">{{ t('payroll.time.overtime.protection_pregnancy') }}</option>
              <option value="child_under_one">{{ t('payroll.time.overtime.protection_child_under_one') }}</option>
            </select>
          </label>
          <div class="grid gap-4 sm:grid-cols-2">
            <label class="block">
              <span class="mb-1 block text-sm font-medium text-neutral-700">{{ t('payroll.time.overtime.consent_valid_from') }}</span>
              <input v-model="protectionValidFrom" data-test="overtime-protection-valid-from" type="date" required class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm">
            </label>
            <label class="block">
              <span class="mb-1 block text-sm font-medium text-neutral-700">{{ t('payroll.time.overtime.consent_valid_to') }}</span>
              <input v-model="protectionValidTo" data-test="overtime-protection-valid-to" type="date" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm">
            </label>
          </div>
          <label class="block">
            <span class="mb-1 block text-sm font-medium text-neutral-700">{{ t('payroll.time.overtime.consent_reference') }}</span>
            <input v-model="protectionReference" data-test="overtime-protection-reference" maxlength="191" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm">
          </label>
          <label class="block">
            <span class="mb-1 block text-sm font-medium text-neutral-700">{{ t('payroll.time.overtime.consent_note') }}</span>
            <textarea v-model="protectionNote" data-test="overtime-protection-note" maxlength="500" rows="3" class="w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm" />
          </label>
          <p v-if="protectionError" data-test="overtime-protection-error" class="text-sm text-danger-500">{{ protectionError }}</p>
          <div class="flex flex-wrap justify-end gap-2">
            <button type="button" :class="btnOutline('neutral')" :disabled="saving" @click="closeProtection">
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.x" /></svg>
              {{ t('common.cancel') }}
            </button>
            <button
              type="submit"
              data-test="overtime-protection-save"
              :class="btnFilled('primary')"
              :disabled="saving || Boolean(protectionBlockedReason)"
              :title="disabledTitle(Boolean(protectionBlockedReason), protectionBlockedReason)"
            >
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.check" /></svg>
              {{ t('common.save') }}
            </button>
          </div>
          <p v-if="protectionBlockedReason" :class="BTN_DISABLED_NOTE" data-test="overtime-protection-save-blocked">
            {{ protectionBlockedReason }}
          </p>
        </form>
      </div>
    </Modal>

    <Modal
      v-if="compensationItem"
      :title="t('payroll.time.overtime.compensation_title')"
      width-class="max-w-lg"
      @close="closeCompensation"
    >
      <div data-test="overtime-compensation-modal">
        <p class="mb-2 text-sm text-neutral-600">
          {{ compensationItem.employment.full_name }} · {{ compensationItem.employment.code }}
        </p>
        <p class="mb-4 max-w-prose text-sm text-neutral-600">
          {{ t('payroll.time.overtime.compensation_hint') }}
        </p>
        <form data-test="overtime-compensation-form" class="space-y-4" @submit.prevent="saveCompensation">
          <div class="grid gap-4 sm:grid-cols-2">
            <label class="block">
              <span class="mb-1 block text-sm font-medium text-neutral-700">{{ t('payroll.time.overtime.compensation_overtime_date') }}</span>
              <input v-model="compensationDate" data-test="overtime-compensation-date" type="date" required class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm">
            </label>
            <label class="block">
              <span class="mb-1 block text-sm font-medium text-neutral-700">{{ t('payroll.time.overtime.compensation_minutes') }}</span>
              <input v-model="compensationMinutes" data-test="overtime-compensation-minutes" type="number" min="1" max="1440" step="1" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm">
            </label>
          </div>
          <label class="block">
            <span class="mb-1 block text-sm font-medium text-neutral-700">{{ t('payroll.time.overtime.compensation_granted_on') }}</span>
            <input v-model="compensationGrantedOn" data-test="overtime-compensation-granted-on" type="date" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm">
          </label>
          <label class="block">
            <span class="mb-1 block text-sm font-medium text-neutral-700">{{ t('payroll.time.overtime.consent_reference') }}</span>
            <input v-model="compensationReference" data-test="overtime-compensation-reference" maxlength="191" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm">
          </label>
          <label class="block">
            <span class="mb-1 block text-sm font-medium text-neutral-700">{{ t('payroll.time.overtime.consent_note') }}</span>
            <textarea v-model="compensationNote" data-test="overtime-compensation-note" maxlength="500" rows="3" class="w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm" />
          </label>
          <p v-if="compensationError" data-test="overtime-compensation-error" class="text-sm text-danger-500">{{ compensationError }}</p>
          <div class="flex flex-wrap justify-end gap-2">
            <button type="button" :class="btnOutline('neutral')" :disabled="saving" @click="closeCompensation">
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.x" /></svg>
              {{ t('common.cancel') }}
            </button>
            <button
              type="submit"
              data-test="overtime-compensation-save"
              :class="btnFilled('primary')"
              :disabled="saving || Boolean(compensationBlockedReason)"
              :title="disabledTitle(Boolean(compensationBlockedReason), compensationBlockedReason)"
            >
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.check" /></svg>
              {{ t('common.save') }}
            </button>
          </div>
          <p v-if="compensationBlockedReason" :class="BTN_DISABLED_NOTE" data-test="overtime-compensation-save-blocked">
            {{ compensationBlockedReason }}
          </p>
        </form>
      </div>
    </Modal>

    <Modal
      v-if="averagingOpen"
      :title="t('payroll.time.overtime.averaging_title')"
      width-class="max-w-2xl"
      @close="closeAveraging"
    >
      <div data-test="overtime-averaging-modal">
        <p class="mb-4 max-w-prose text-sm text-neutral-600">
          {{ t('payroll.time.overtime.averaging_hint') }}
        </p>
        <ul v-if="averagingPeriods.length" class="mb-4 space-y-1 text-sm text-neutral-600" data-test="overtime-averaging-list">
          <li v-for="row in averagingPeriods" :key="row.id">
            {{ t('payroll.time.overtime.averaging_row', {
              from: row.valid_from,
              to: row.valid_to ?? '—',
              weeks: row.weeks,
              basis: row.basis === 'collective_agreement'
                ? (row.collective_agreement_reference ?? '')
                : t('payroll.time.overtime.averaging_statutory'),
            }) }}
          </li>
        </ul>
        <form data-test="overtime-averaging-form" class="space-y-4" @submit.prevent="saveAveraging">
          <div class="grid gap-4 sm:grid-cols-2">
            <label class="block">
              <span class="mb-1 block text-sm font-medium text-neutral-700">{{ t('payroll.time.overtime.consent_valid_from') }}</span>
              <input v-model="averagingValidFrom" data-test="overtime-averaging-valid-from" type="date" required class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm">
            </label>
            <label class="block">
              <span class="mb-1 block text-sm font-medium text-neutral-700">{{ t('payroll.time.overtime.consent_valid_to') }}</span>
              <input v-model="averagingValidTo" data-test="overtime-averaging-valid-to" type="date" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm">
            </label>
            <label class="block">
              <span class="mb-1 block text-sm font-medium text-neutral-700">{{ t('payroll.time.overtime.averaging_weeks') }}</span>
              <input v-model="averagingWeeks" data-test="overtime-averaging-weeks" type="number" min="1" max="52" step="1" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm">
            </label>
            <label class="block">
              <span class="mb-1 block text-sm font-medium text-neutral-700">{{ t('payroll.time.overtime.averaging_basis') }}</span>
              <select v-model="averagingBasis" data-test="overtime-averaging-basis" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm">
                <option value="statutory">{{ t('payroll.time.overtime.averaging_statutory') }}</option>
                <option value="collective_agreement">{{ t('payroll.time.overtime.averaging_collective_option') }}</option>
              </select>
            </label>
          </div>
          <label v-if="averagingBasis === 'collective_agreement'" class="block">
            <span class="mb-1 block text-sm font-medium text-neutral-700">{{ t('payroll.time.overtime.averaging_reference') }}</span>
            <input v-model="averagingReference" data-test="overtime-averaging-reference" maxlength="255" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm">
          </label>
          <label class="block">
            <span class="mb-1 block text-sm font-medium text-neutral-700">{{ t('payroll.time.overtime.consent_note') }}</span>
            <textarea v-model="averagingNote" data-test="overtime-averaging-note" maxlength="500" rows="3" class="w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm" />
          </label>
          <p v-if="averagingError" data-test="overtime-averaging-error" class="text-sm text-danger-500">{{ averagingError }}</p>
          <div class="flex flex-wrap justify-end gap-2">
            <button type="button" :class="btnOutline('neutral')" :disabled="saving" @click="closeAveraging">
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.x" /></svg>
              {{ t('common.cancel') }}
            </button>
            <button
              type="submit"
              data-test="overtime-averaging-save"
              :class="btnFilled('primary')"
              :disabled="saving || Boolean(averagingBlockedReason)"
              :title="disabledTitle(Boolean(averagingBlockedReason), averagingBlockedReason)"
            >
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.check" /></svg>
              {{ t('common.save') }}
            </button>
          </div>
          <p v-if="averagingBlockedReason" :class="BTN_DISABLED_NOTE" data-test="overtime-averaging-save-blocked">
            {{ averagingBlockedReason }}
          </p>
        </form>
      </div>
    </Modal>

    <Modal
      v-if="reopenItem"
      :title="t('payroll.time.reopen')"
      width-class="max-w-lg"
      @close="closeReopen"
    >
      <div data-test="reopen-modal">
        <p data-test="reopen-employee" class="mb-4 text-sm text-neutral-600">
          {{ reopenItem.employment.full_name }} · {{ reopenItem.employment.code }}
        </p>
        <form data-test="reopen-form" class="space-y-4" @submit.prevent="reopen">
          <label class="block">
            <span class="mb-1 block text-sm font-medium text-neutral-700">
              {{ t('payroll.time.reopen_reason') }}
            </span>
            <textarea
              v-model="reopenReason"
              data-test="reopen-reason"
              required
              maxlength="1000"
              rows="4"
              class="w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm text-neutral-900 outline-none focus:border-payroll-500 focus:ring-2 focus:ring-payroll-500/20"
            />
          </label>
          <p
            v-if="reopenError"
            data-test="reopen-error"
            role="alert"
            class="rounded-lg border border-danger-200 bg-danger-50 p-3 text-sm text-danger-700"
          >
            {{ reopenError }}
          </p>
          <div class="flex flex-wrap justify-end gap-2">
            <button type="button" :class="btnOutline('neutral')" :disabled="saving" @click="closeReopen">
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.x" /></svg>
              {{ t('common.cancel') }}
            </button>
            <button type="submit" :class="btnFilled('warning')" :disabled="saving || !reopenReason.trim()">
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.uturn" /></svg>
              {{ t('payroll.time.reopen') }}
            </button>
          </div>
        </form>
      </div>
    </Modal>
  </div>
</template>

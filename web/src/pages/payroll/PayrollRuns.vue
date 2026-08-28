<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import {
  payrollApi,
  type PayrollRun,
  type PayrollRunCommand,
  type PayrollRunHistory,
  type PayrollRunHistoryEvent,
  type PayrollRunHistoryTotalDiff,
  type PayrollRunHistoryTotalKey,
  type PayrollRunRevisionHistory,
  type PayrollRunResultPerson,
  type PayrollRunValidation,
} from '@/api/payroll'
import { apiErrorMessage } from '@/api/errors'
import PayrollIncomeTaxBreakdown from '@/components/payroll/PayrollIncomeTaxBreakdown.vue'
import PayrollInsuranceBreakdown from '@/components/payroll/PayrollInsuranceBreakdown.vue'
import PayrollNetPayBreakdown from '@/components/payroll/PayrollNetPayBreakdown.vue'
import { btnFilled, btnOutline, btnOutlineSm, disabledTitle, BTN_DISABLED_NOTE, ICONS } from '@/components/ui/buttonStyles'
// Formátování je sdílené (useFormat) — místní kopie se rozcházely v locale i tvaru.
import { formatDateTime, formatMoneyMinor as money, formatPeriod } from '@/composables/useFormat'
import Modal from '@/components/ui/Modal.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import PaginationBar from '@/components/ui/PaginationBar.vue'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { localPayrollPeriod } from '@/pages/payroll/payrollComponentsUi'

const { t } = useI18n()
const router = useRouter()
const auth = useAuthStore()
const toast = useToast()
const loading = ref(false)
/*
 * Selhalo načtení? Pak o obsahu nevíme NIC — a to je něco jiného než „nic tu
 * není". Toast s chybou za pár vteřin zmizí a bez tohohle příznaku by na
 * obrazovce zůstal prázdný stav, který lže.
 */
const loadFailed = ref(false)
const saving = ref(false)
const period = ref(localPayrollPeriod())
const paymentDate = ref(defaultPaymentDate(period.value))
const runs = ref<PayrollRun[]>([])
const personNames = ref<Record<number, string>>({})
/**
 * Osobní rozpad běhu (`result_snapshot.people`) je ta objemná část výsledku a
 * seznam ho úmyslně neposílá — server by ho jinak musel načíst pro všechny běhy
 * firmy najednou. Drží se proto stranou a dotahuje se pro jeden rozbalený běh.
 */
const breakdowns = ref<Record<number, PayrollRunResultPerson[]>>({})
const breakdownLoading = ref<Record<number, boolean>>({})
const histories = ref<Record<number, PayrollRunHistory>>({})
const historyOpen = ref<Record<number, boolean>>({})
const historyLoading = ref<Record<number, boolean>>({})
const historyFailed = ref<Record<number, boolean>>({})
const total = ref(0)
const pageSize = 12
const offset = ref(0)
const currentPage = computed(() => Math.floor(offset.value / pageSize) + 1)
const pendingCommand = ref<{ run: PayrollRun, command: PayrollRunCommand } | null>(null)
const pendingDelete = ref<PayrollRun | null>(null)
const commandReason = ref('')
const commandError = ref('')
const commandBlockers = ref<Record<number, string>>({})
const pendingOverride = ref<{ run: PayrollRun, validation: PayrollRunValidation } | null>(null)
const overrideReason = ref('')
const overrideError = ref('')

const canWrite = computed(() => auth.canWrite('payroll.inputs.write'))
/*
 * Schválení výjimky je věcně část schválení mzdy („vím o vadě a přesto se
 * vyplácí"), proto stejné právo jako u příkazu `approve` — server to vynucuje
 * stejně, tohle je jen to, aby se nenabízelo tlačítko, které skončí 403.
 */
const canOverride = computed(() => auth.canWrite('payroll.approve'))

type DisplayValidation = PayrollRunValidation & {
  group_key: string
  display_message: string
  entity_labels: string[]
}

const GROUPED_VALIDATION_CODES = new Set([
  'draft_inputs_present',
  'enforcement_manual_review',
])
const ENFORCEMENT_NET_PAY_ISSUE = 'income:net_pay_result_missing_or_unverified'

/** Starší revize mohou nést technický kód z doby před serverovým překladačem. */
function containsInternalIssueCode(message: string): boolean {
  return /(?:[a-z][a-z0-9]*[_-]){2,}[a-z0-9_-]+|(?:employee|employment):\d+|[a-z_]+:[a-z0-9_-]+:/iu.test(message)
}

function legacyEnforcementIssues(message: string): { netPay: boolean, other: boolean } {
  const netPay = message.includes(ENFORCEMENT_NET_PAY_ISSUE)
  const remainder = message.replaceAll(ENFORCEMENT_NET_PAY_ISSUE, '')
  return { netPay, other: containsInternalIssueCode(remainder) }
}

function validationGroupingKey(validation: PayrollRunValidation): string {
  if (validation.code === 'draft_inputs_present') return validation.code
  if (validation.code !== 'enforcement_manual_review') return `validation-${validation.id}`
  if (!containsInternalIssueCode(validation.message)) {
    return `${validation.code}:message:${validation.message}`
  }
  const issues = legacyEnforcementIssues(validation.message)
  return `${validation.code}:legacy:${issues.netPay ? 'net-pay' : 'no-net-pay'}:${issues.other ? 'other' : 'only'}`
}

function validationDisplayMessage(validation: PayrollRunValidation, count = 1): string {
  if (validation.code === 'draft_inputs_present') {
    return t(
      `payroll.runs.validation.draft_inputs_${count === 1 ? 'one' : 'many'}`,
      { count },
    )
  }
  if (validation.code === 'enforcement_manual_review') {
    if (!containsInternalIssueCode(validation.message)) return validation.message
    const issues = legacyEnforcementIssues(validation.message)
    const parts: string[] = []
    if (issues.netPay) {
      parts.push(t(
        `payroll.runs.validation.enforcement_net_pay_${count === 1 ? 'one' : 'many'}`,
        { count },
      ))
    }
    if (issues.other || !issues.netPay) {
      parts.push(t('payroll.runs.validation.requires_attention'))
    }
    return parts.join(' ')
  }
  if (validation.code === 'statutory_calculation_manual_review'
    && containsInternalIssueCode(validation.message)) {
    return t('payroll.runs.validation.statutory_incomplete')
  }
  return containsInternalIssueCode(validation.message)
    ? t('payroll.runs.validation.requires_attention')
    : validation.message
}

function validationGroups(validations: PayrollRunValidation[]): DisplayValidation[] {
  const groups: Array<{ primary: PayrollRunValidation, items: PayrollRunValidation[] }> = []
  const grouped = new Map<string, { primary: PayrollRunValidation, items: PayrollRunValidation[] }>()

  for (const validation of validations) {
    const canGroup = !validation.requires_override
      && GROUPED_VALIDATION_CODES.has(validation.code)
    const key = canGroup ? validationGroupingKey(validation) : `validation-${validation.id}`
    let group = grouped.get(key)
    if (!group) {
      group = { primary: validation, items: [] }
      grouped.set(key, group)
      groups.push(group)
    }
    group.items.push(validation)
  }

  return groups.map(({ primary, items }) => {
    const entityLabels = Array.from(new Set(items.flatMap((item) => {
      if (item.entity_type === 'employee' && item.entity_id !== null) {
        return personNames.value[item.entity_id] ? [personNames.value[item.entity_id]] : []
      }
      const namedEmployment = item.message.match(/^(.+?): pracovní vztah/u)
      return namedEmployment?.[1] ? [namedEmployment[1]] : []
    })))

    const displayMessage = validationDisplayMessage(primary, items.length)

    return {
      ...primary,
      group_key: GROUPED_VALIDATION_CODES.has(primary.code)
        ? primary.code
        : `validation-${primary.id}`,
      display_message: displayMessage,
      entity_labels: entityLabels,
    }
  })
}

function entityLabelSummary(labels: string[]): string {
  const visible = labels.slice(0, 5).join(', ')
  if (labels.length <= 5) return visible
  return `${visible} · ${t('payroll.runs.validation.and_more', { count: labels.length - 5 })}`
}

/** Stavy, ve kterých se s výjimkou ještě smí hýbat — po schválení běhu už ne. */
const OVERRIDE_EDITABLE_STATUSES: PayrollRun['status'][] = [
  'inputs_locked',
  'calculated',
  'reviewed',
  'reopened',
]

function overrideEditable(run: PayrollRun): boolean {
  return OVERRIDE_EDITABLE_STATUSES.includes(run.status)
}

/** Varování, na které se čeká: bez rozhodnutí člověka běh dál nepostoupí. */
function awaitsOverride(validation: PayrollRunValidation): boolean {
  return validation.requires_override && validation.overridden_at === null
}

function validationClass(validation: PayrollRunValidation): string {
  if (validation.severity === 'blocker') {
    return 'border-danger-500/30 bg-danger-50 text-danger-700'
  }
  if (validation.severity === 'info') {
    return 'border-neutral-200 bg-neutral-50 text-neutral-700'
  }
  return 'border-warning-200 bg-warning-50 text-warning-800'
}

function overrideAuthorLabel(validation: PayrollRunValidation): string {
  return t('payroll.runs.override.granted_by', {
    name: validation.overridden_by_name ?? t('payroll.runs.override.unknown_author'),
    at: formatDateTime(validation.overridden_at),
  })
}

function defaultPaymentDate(value: string): string {
  const [year, month] = value.split('-').map(Number)
  const date = new Date(Date.UTC(year, month, 15))
  return date.toISOString().slice(0, 10)
}

function statusClass(status: PayrollRun['status']): string {
  if (status === 'approved' || status === 'closed' || status === 'paid') {
    return 'bg-success-50 text-success-600'
  }
  if (status === 'cancelled' || status === 'correction_pending') {
    return 'bg-warning-50 text-warning-600'
  }
  if (status === 'calculated' || status === 'reviewed') {
    return 'bg-payroll-50 text-payroll-600'
  }
  return 'bg-neutral-100 text-neutral-600'
}

/**
 * Jediná plná (primární) akce podle stavu — zbytek běhu je odbočka, ne
 * rovnocenná volba. Uživatel má v každém stavu vidět jedno „co teď".
 */
const PRIMARY_COMMAND: Partial<Record<PayrollRun['status'], PayrollRunCommand>> = {
  draft: 'lock_inputs',
  inputs_locked: 'calculate',
  reopened: 'calculate',
  calculated: 'review',
  reviewed: 'approve',
  approved: 'post',
  posted: 'prepare_payments',
  payment_ready: 'mark_paid',
  paid: 'close',
  correction_pending: 'reopen',
  cancelled: 'reopen',
}

const KNOWN_COMMANDS: PayrollRunCommand[] = [
  'lock_inputs',
  'calculate',
  'review',
  'approve',
  'post',
  'prepare_payments',
  'mark_paid',
  'request_correction',
  'reopen',
  'cancel',
  'close',
]

function commandLabel(command: PayrollRunCommand, run?: PayrollRun): string {
  if (command === 'reopen' && run?.status === 'cancelled') {
    return t('payroll.runs.commands.reopen_cancelled')
  }
  return t(`payroll.runs.commands.${command}`)
}

function commandClass(run: PayrollRun, command: PayrollRunCommand): string {
  if (PRIMARY_COMMAND[run.status] === command) {
    return btnFilled(command === 'approve' ? 'success' : 'primary')
  }
  if (command === 'cancel') return btnOutline('danger')
  if (command === 'request_correction' || command === 'reopen') {
    return btnOutline('warning')
  }
  return btnOutline('neutral')
}

function commandIcon(command: PayrollRunCommand): string {
  if (command === 'lock_inputs') return ICONS.lock
  if (command === 'calculate') return ICONS.cycle
  if (command === 'post') return ICONS.doc
  if (command === 'prepare_payments') return ICONS.coin
  if (command === 'mark_paid') return ICONS.checkCircle
  if (command === 'review' || command === 'approve' || command === 'close') {
    return ICONS.check
  }
  if (command === 'cancel') return ICONS.x
  return ICONS.uturn
}

function visibleCommands(run: PayrollRun): PayrollRunCommand[] {
  return run.available_commands.filter(command => {
    if (!KNOWN_COMMANDS.includes(command)) return false
    if (command === 'calculate') return auth.canWrite('payroll.calculate')
    if (command === 'review' || command === 'request_correction') {
      return auth.canWrite('payroll.review')
    }
    if (command === 'approve') return auth.canWrite('payroll.approve')
    if (command === 'reopen') return auth.canWrite('payroll.reopen')
    if (command === 'post') return auth.canWrite('payroll.post')
    if (command === 'prepare_payments' || command === 'mark_paid') {
      return auth.canWrite('payroll.payments')
    }
    return canWrite.value
  })
}

/*
 * Proč nejde založit běh. Období i datum výplaty jsou povinné vstupy formuláře
 * hned vedle tlačítka — bez věty ale nebylo poznat, které z nich chybí.
 */
const createBlockedReason = computed<string | null>(() => {
  if (!period.value) return t('payroll.runs.create_blocked_period')
  if (!paymentDate.value) return t('payroll.runs.create_blocked_payment_date')
  return null
})

async function load() {
  loading.value = true
  loadFailed.value = false
  try {
    const [page, people] = await Promise.all([
      payrollApi.runsPage(period.value, { limit: pageSize, offset: offset.value }),
      payrollApi.peopleOptions().catch(() => null),
    ])
    runs.value = page.runs
    total.value = page.total
    // Rozpad patří ke konkrétní revizi; po přenačtení seznamu už nemusí platit.
    breakdowns.value = {}
    histories.value = {}
    historyOpen.value = {}
    historyFailed.value = {}
    if (people !== null) {
      personNames.value = Object.fromEntries(
        people.map(person => [person.id, person.full_name]),
      )
    }
  } catch {
    // Seznam běhů se nechává být: „za období nebyl spuštěn žádný běh" je
    // závěr, na který po výpadku sítě nemáme právo.
    loadFailed.value = true
    toast.error(t('payroll.runs.load_failed'))
  } finally {
    loading.value = false
  }
}

// Stránkuje sdílená `PaginationBar` (číslo stránky od jedné); server zná offset.
function goToPage(nextPage: number) {
  offset.value = Math.max(0, (nextPage - 1) * pageSize)
  void load()
}

/**
 * Dotáhne osobní rozpad jednoho běhu. Opakované kliknutí rozpad schová, aby si
 * uživatel mohl seznam zase zpřehlednit; jednou stažená data se drží v paměti.
 */
async function toggleBreakdown(run: PayrollRun) {
  if (breakdowns.value[run.id] !== undefined) {
    const { [run.id]: _removed, ...rest } = breakdowns.value
    breakdowns.value = rest
    return
  }
  breakdownLoading.value = { ...breakdownLoading.value, [run.id]: true }
  try {
    const detail = await payrollApi.run(run.id)
    breakdowns.value = {
      ...breakdowns.value,
      [run.id]: detail.result_snapshot?.people ?? [],
    }
  } catch {
    toast.error(t('payroll.runs.breakdown_failed'))
  } finally {
    const { [run.id]: _pending, ...rest } = breakdownLoading.value
    breakdownLoading.value = rest
  }
}

const HISTORY_TOTAL_KEYS: PayrollRunHistoryTotalKey[] = [
  'cash_payable_minor',
  'enforcement_withheld_minor',
  'payable_after_enforcement_minor',
]

function historyTotalDiffs(revision: PayrollRunRevisionHistory): Array<{
  key: PayrollRunHistoryTotalKey
  diff: PayrollRunHistoryTotalDiff
}> {
  if (revision.diff_from_previous === null) return []
  return HISTORY_TOTAL_KEYS.flatMap((key) => {
    const diff = revision.diff_from_previous?.totals[key]
    return diff === undefined ? [] : [{ key, diff }]
  })
}

function historyEventLabel(event: PayrollRunHistoryEvent): string {
  const known = new Set([
    'created',
    ...KNOWN_COMMANDS,
    'validation_override',
    'validation_override_revoked',
  ])
  return known.has(event.event_type)
    ? t(`payroll.runs.history.event.${event.event_type}`)
    : t('payroll.runs.history.event.unknown')
}

async function loadHistory(runId: number) {
  historyLoading.value = { ...historyLoading.value, [runId]: true }
  historyFailed.value = { ...historyFailed.value, [runId]: false }
  try {
    const history = await payrollApi.runHistory(runId)
    histories.value = { ...histories.value, [runId]: history }
  } catch {
    historyFailed.value = { ...historyFailed.value, [runId]: true }
  } finally {
    const { [runId]: _pending, ...rest } = historyLoading.value
    historyLoading.value = rest
  }
}

async function toggleHistory(run: PayrollRun) {
  const opening = !historyOpen.value[run.id]
  historyOpen.value = { ...historyOpen.value, [run.id]: opening }
  if (opening && histories.value[run.id] === undefined) {
    await loadHistory(run.id)
  }
}

async function createRun() {
  if (!canWrite.value) return
  saving.value = true
  try {
    await payrollApi.createRun({
      period_start: `${period.value}-01`,
      payment_date: paymentDate.value,
      office_id: null,
    })
    toast.success(t('payroll.runs.created'))
    await load()
  } catch (error: any) {
    toast.error(error?.response?.data?.error?.message || t('payroll.runs.save_failed'))
  } finally {
    saving.value = false
  }
}

async function runCommand(run: PayrollRun, command: PayrollRunCommand) {
  if (['request_correction', 'reopen', 'cancel'].includes(command)) {
    pendingCommand.value = { run, command }
    commandReason.value = ''
    commandError.value = ''
    return
  }
  await submitCommand(run, command)
}

async function submitCommand(
  run: PayrollRun,
  command: PayrollRunCommand,
  reason?: string,
) {
  saving.value = true
  delete commandBlockers.value[run.id]
  try {
    const response = await payrollApi.commandRun(
      run.id,
      command,
      { row_version: run.row_version, ...(reason ? { reason } : {}) },
      crypto.randomUUID(),
    )
    const outcome = response.outcome?.outcome ?? null
    toast.success(
      outcome === null
        ? t('payroll.runs.command_done')
        : t(`payroll.runs.outcome.${outcome}`),
    )
    pendingCommand.value = null
    commandReason.value = ''
    commandError.value = ''
    await load()
    if (command === 'prepare_payments'
      && outcome !== 'payments_not_applicable'
    ) {
      void router.push({
        name: 'payroll-payments',
        query: {
          period: run.period_start.slice(0, 7),
          run: String(run.id),
          focus: 'bank-order',
        },
      })
    }
  } catch (error: any) {
    const failure = error?.response?.data?.error
    const paymentFailureKey = failure?.code === 'payroll_payments_unsettled'
      ? 'payroll.runs.payments_unsettled'
      : failure?.code === 'payroll_incoming_refund_unresolved'
        ? 'payroll.runs.incoming_refund_unresolved'
        : null
    const message = command === 'mark_paid' && paymentFailureKey !== null
      ? t(paymentFailureKey)
      : failure?.message || t('payroll.runs.command_failed')
    if (pendingCommand.value) commandError.value = message
    // Blokující důvod u zaúčtování a plateb je celá věta („komu chybí výplatní
    // pravidlo", „kolik zbývá uhradit"). V toastu se ztratí dřív, než se podle
    // ní dá jednat — proto zůstane viset u konkrétního běhu.
    else if (['post', 'prepare_payments', 'mark_paid'].includes(command)) {
      commandBlockers.value = { ...commandBlockers.value, [run.id]: message }
    } else toast.error(message)
    if (error?.response?.status === 409) await load()
  } finally {
    saving.value = false
  }
}

function dismissBlocker(runId: number) {
  const next = { ...commandBlockers.value }
  delete next[runId]
  commandBlockers.value = next
}

async function confirmCommand() {
  if (!pendingCommand.value) return
  const reason = commandReason.value.trim()
  if (!reason) {
    commandError.value = t('payroll.runs.reason_required')
    return
  }
  await submitCommand(
    pendingCommand.value.run,
    pendingCommand.value.command,
    reason,
  )
}

/**
 * Schválit rovnou z obrazovky běhu všechny mzdové vstupy, které ho drží.
 *
 * Blokátor `draft_inputs_present` dosud jen odkázal na jinou stránku, kde se
 * schvalovalo řádek po řádku — u 500 zaměstnanců zhruba tisíc kliknutí. Odkaz
 * zůstává (koncept může být potřeba nejdřív opravit), tohle je zkratka pro
 * případ, kdy je vstupů jen moc.
 */
async function approveDraftInputs(run: PayrollRun) {
  if (!canOverride.value) return
  saving.value = true
  try {
    const result = await payrollApi.approveInputsBatch({
      period: run.period_start.slice(0, 7),
    })
    if (result.failed.length > 0) {
      toast.error(t('payroll.runs.validation.draft_inputs_approve_partial', {
        approved: result.approved.length,
        failed: result.failed.length,
        reason: result.failed[0].message,
      }))
    } else {
      toast.success(t('payroll.runs.validation.draft_inputs_approved', {
        count: result.approved.length,
      }))
    }
    await load()
  } catch (error: any) {
    toast.error(apiErrorMessage(
      error,
      t('payroll.runs.validation.draft_inputs_approve_failed'),
    ))
  } finally {
    saving.value = false
  }
}

function askOverride(run: PayrollRun, validation: PayrollRunValidation) {
  if (!canOverride.value || !overrideEditable(run)) return
  pendingOverride.value = { run, validation }
  overrideReason.value = ''
  overrideError.value = ''
}

async function confirmOverride() {
  const pending = pendingOverride.value
  if (!pending) return
  const reason = overrideReason.value.trim()
  if (!reason) {
    overrideError.value = t('payroll.runs.override.reason_required')
    return
  }
  saving.value = true
  try {
    await payrollApi.overrideRunValidation(
      pending.run.id,
      pending.validation.id,
      { row_version: pending.run.row_version, reason },
      crypto.randomUUID(),
    )
    toast.success(t('payroll.runs.override.granted'))
    pendingOverride.value = null
    overrideReason.value = ''
    await load()
  } catch (error: any) {
    // Server má na odůvodnění vlastní minimum a jeho věta je konkrétnější než
    // cokoli, co bychom si tady vymysleli — proto se ukazuje v dialogu.
    overrideError.value = error?.response?.data?.error?.message
      || t('payroll.runs.override.failed')
    if (error?.response?.status === 409) await load()
  } finally {
    saving.value = false
  }
}

async function revokeOverride(run: PayrollRun, validation: PayrollRunValidation) {
  if (!canOverride.value || !overrideEditable(run)) return
  saving.value = true
  try {
    await payrollApi.revokeRunValidationOverride(
      run.id,
      validation.id,
      { row_version: run.row_version },
      crypto.randomUUID(),
    )
    toast.success(t('payroll.runs.override.revoked'))
    await load()
  } catch (error: any) {
    toast.error(
      error?.response?.data?.error?.message
        || t('payroll.runs.override.revoke_failed'),
    )
    if (error?.response?.status === 409) await load()
  } finally {
    saving.value = false
  }
}

function askDeleteRun(run: PayrollRun) {
  if (!canWrite.value || !run.can_delete) return
  pendingDelete.value = run
}

async function deleteRun() {
  const run = pendingDelete.value
  if (!run) return
  saving.value = true
  try {
    await payrollApi.deleteRun(run.id, run.row_version)
    toast.success(t('payroll.runs.deleted'))
    pendingDelete.value = null
    await load()
  } catch (error: any) {
    toast.error(error?.response?.data?.error?.message || t('payroll.runs.delete_failed'))
    if (error?.response?.status === 409) await load()
  } finally {
    saving.value = false
  }
}

function changePeriod() {
  paymentDate.value = defaultPaymentDate(period.value)
  // Jiné období = jiná množina běhů; zůstat na třetí stránce by ukázalo prázdno.
  offset.value = 0
  void load()
}

onMounted(load)
</script>

<template>
  <div class="space-y-6">
    <header class="flex flex-wrap items-end justify-between gap-4">
      <div>
        <h1 class="text-2xl font-semibold text-neutral-900">{{ t('payroll.runs.title') }}</h1>
        <p class="mt-1 max-w-3xl text-sm text-neutral-500">{{ t('payroll.runs.subtitle') }}</p>
      </div>
      <div class="flex flex-wrap items-end gap-3">
        <label class="block">
          <span class="mb-1 block text-xs font-medium text-neutral-600">{{ t('payroll.runs.period') }}</span>
          <input
            v-model="period"
            type="month"
            class="h-9 rounded-md border border-neutral-300 bg-surface px-3 text-sm"
            @change="changePeriod"
          >
        </label>
        <label class="block">
          <span class="mb-1 block text-xs font-medium text-neutral-600">{{ t('payroll.runs.payment_date') }}</span>
          <input
            v-model="paymentDate"
            type="date"
            class="h-9 rounded-md border border-neutral-300 bg-surface px-3 text-sm"
          >
        </label>
        <RouterLink
          :to="{ name: 'payroll-quick-inputs' }"
          :class="btnOutline('primary')"
        >
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path :d="ICONS.coin" />
          </svg>
          {{ t('payroll.runs.quick_inputs') }}
        </RouterLink>
        <div v-if="canWrite" class="flex flex-col items-start gap-1.5">
          <button
            :class="btnFilled('primary')"
            :disabled="saving || !period || !paymentDate"
            :title="disabledTitle(createBlockedReason !== null, createBlockedReason)"
            data-test="run-create"
            @click="createRun"
          >
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path :d="ICONS.plus" />
            </svg>
            {{ t('payroll.runs.create') }}
          </button>
          <p v-if="createBlockedReason" :class="BTN_DISABLED_NOTE" data-test="run-create-blocked">
            {{ createBlockedReason }}
          </p>
        </div>
      </div>
    </header>

    <div v-if="loading" class="space-y-3">
      <div v-for="index in 2" :key="index" class="h-40 animate-pulse rounded-xl bg-neutral-100" />
    </div>

    <EmptyState
      v-else-if="loadFailed"
      variant="failed"
      boxed
      data-test="load-failed"
      :message="t('payroll.runs.load_failed_hint')"
      @action="load"
    />

    <section
      v-else-if="runs.length === 0"
      class="rounded-xl border border-dashed border-neutral-300 bg-surface p-8 text-center"
    >
      <h2 class="font-semibold text-neutral-900">{{ t('payroll.runs.empty') }}</h2>
      <p class="mt-1 text-sm text-neutral-500">{{ t('payroll.runs.empty_hint') }}</p>
      <RouterLink
        :to="{ name: 'payroll-quick-inputs' }"
        :class="[btnOutline('primary'), 'mt-4']"
      >
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
          <path :d="ICONS.coin" />
        </svg>
        {{ t('payroll.runs.quick_inputs') }}
      </RouterLink>
    </section>

    <section v-else class="space-y-4">
      <article
        v-for="run in runs"
        :key="run.id"
        class="rounded-xl border border-neutral-200 bg-surface p-4 shadow-sm sm:p-5"
      >
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div>
            <div class="flex flex-wrap items-center gap-2">
              <h2 class="text-lg font-semibold text-neutral-900">
                {{ t('payroll.runs.run_label', { period: formatPeriod(run.period_start.slice(0, 7)) }) }}
              </h2>
              <span class="rounded-full px-2.5 py-1 text-xs font-medium" :class="statusClass(run.status)">
                {{ t(`payroll.runs.status.${run.status}`) }}
              </span>
            </div>
            <p class="mt-1 text-sm text-neutral-500">
              {{ t('payroll.runs.payment_date_value', { date: run.payment_date }) }}
              · {{ t('payroll.runs.revision', { revision: run.revision_no ?? 0 }) }}
            </p>
          </div>
          <div
            v-if="visibleCommands(run).length || (canWrite && run.can_delete)"
            class="flex flex-wrap justify-end gap-2"
          >
            <button
              v-for="command in visibleCommands(run)"
              :key="command"
              :data-testid="`payroll-run-${run.id}-${command}`"
              :class="commandClass(run, command)"
              :disabled="saving"
              @click="runCommand(run, command)"
            >
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path :d="commandIcon(command)" />
              </svg>
              {{ commandLabel(command, run) }}
            </button>
            <button
              v-if="canWrite && run.can_delete"
              :data-testid="`delete-payroll-run-${run.id}`"
              :class="btnOutline('danger')"
              :disabled="saving"
              @click="askDeleteRun(run)"
            >
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path :d="ICONS.trash" />
              </svg>
              {{ t('payroll.runs.delete') }}
            </button>
          </div>
        </div>

        <div
          v-if="commandBlockers[run.id]"
          :data-testid="`payroll-run-${run.id}-blocker`"
          class="mt-4 flex items-start gap-3 rounded-lg border border-warning-200 bg-warning-50 p-3 text-sm text-warning-700"
        >
          <svg class="mt-0.5 h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path :d="ICONS.bell" />
          </svg>
          <p class="flex-1">{{ commandBlockers[run.id] }}</p>
          <button
            type="button"
            class="shrink-0 rounded p-1 text-warning-600 hover:bg-warning-100"
            :aria-label="t('common.close')"
            @click="dismissBlocker(run.id)"
          >
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path :d="ICONS.x" />
            </svg>
          </button>
        </div>

        <dl v-if="run.result_snapshot?.totals" class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-3">
          <div class="rounded-lg bg-neutral-50 p-3">
            <dt class="text-xs text-neutral-500">{{ t('payroll.runs.cash_before') }}</dt>
            <dd class="mt-1 font-semibold text-neutral-900">
              {{ money(run.result_snapshot.totals.cash_payable_minor) }}
            </dd>
          </div>
          <div class="rounded-lg bg-payroll-50 p-3">
            <dt class="text-xs text-payroll-700">{{ t('payroll.runs.enforcement_withheld') }}</dt>
            <dd class="mt-1 font-semibold text-payroll-700">
              {{ money(run.result_snapshot.totals.enforcement_withheld_minor) }}
            </dd>
          </div>
          <div class="rounded-lg bg-success-50 p-3">
            <dt class="text-xs text-success-700">{{ t('payroll.runs.payable_after') }}</dt>
            <dd class="mt-1 font-semibold text-success-700">
              {{ money(run.result_snapshot.totals.payable_after_enforcement_minor) }}
            </dd>
          </div>
        </dl>

        <div class="mt-4 flex flex-wrap gap-2">
          <button
            v-if="run.result_snapshot"
            type="button"
            :data-testid="`payroll-run-${run.id}-breakdown-toggle`"
            :class="btnOutline('neutral')"
            :disabled="breakdownLoading[run.id]"
            @click="toggleBreakdown(run)"
          >
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
              <path :d="ICONS.chart" />
            </svg>
            {{
              breakdownLoading[run.id]
                ? t('common.loading')
                : breakdowns[run.id]
                  ? t('payroll.runs.breakdown_hide')
                  : t('payroll.runs.breakdown_show')
            }}
          </button>
          <button
            type="button"
            :data-testid="`payroll-run-${run.id}-history-toggle`"
            :class="btnOutline('neutral')"
            :disabled="historyLoading[run.id]"
            @click="toggleHistory(run)"
          >
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
              <path :d="ICONS.cycle" />
            </svg>
            {{
              historyLoading[run.id]
                ? t('common.loading')
                : historyOpen[run.id]
                  ? t('payroll.runs.history.hide')
                  : t('payroll.runs.history.show')
            }}
          </button>
        </div>

        <section
          v-if="historyOpen[run.id]"
          :data-testid="`payroll-run-${run.id}-history`"
          class="mt-4 rounded-xl border border-neutral-200 bg-neutral-50 p-4"
        >
          <div class="flex flex-wrap items-start justify-between gap-2">
            <div>
              <h3 class="font-semibold text-neutral-900">{{ t('payroll.runs.history.title') }}</h3>
              <p class="mt-1 text-xs leading-relaxed text-neutral-500">
                {{ t('payroll.runs.history.hint') }}
              </p>
            </div>
          </div>

          <div
            v-if="historyFailed[run.id]"
            :data-testid="`payroll-run-${run.id}-history-failed`"
            class="mt-3 rounded-lg border border-danger-500/30 bg-danger-50 p-3 text-sm text-danger-700"
            role="alert"
          >
            <p>{{ t('payroll.runs.history.load_failed') }}</p>
            <button
              type="button"
              :data-testid="`payroll-run-${run.id}-history-retry`"
              :class="[btnOutlineSm('danger'), 'mt-2']"
              :disabled="historyLoading[run.id]"
              @click="loadHistory(run.id)"
            >
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path :d="ICONS.cycle" />
              </svg>
              {{ t('payroll.runs.history.retry') }}
            </button>
          </div>

          <p
            v-else-if="histories[run.id] && histories[run.id].revisions.length === 0 && histories[run.id].events.length === 0"
            class="mt-3 text-sm text-neutral-500"
          >
            {{ t('payroll.runs.history.empty') }}
          </p>

          <div v-else-if="histories[run.id]" class="mt-4 grid gap-5 xl:grid-cols-2">
            <div>
              <h4 class="text-sm font-semibold text-neutral-800">{{ t('payroll.runs.history.revisions') }}</h4>
              <ol class="mt-3 space-y-3">
                <li
                  v-for="revision in [...histories[run.id].revisions].reverse()"
                  :key="revision.id"
                  class="rounded-lg border border-neutral-200 bg-surface p-3"
                >
                  <div class="flex flex-wrap items-center justify-between gap-2">
                    <div class="flex flex-wrap items-center gap-2">
                      <span class="font-medium text-neutral-900">
                        {{ t('payroll.runs.history.revision_label', { revision: revision.revision_no }) }}
                      </span>
                      <span class="rounded-full bg-neutral-100 px-2 py-0.5 text-xs text-neutral-600">
                        {{ t(`payroll.runs.history.kind.${revision.revision_kind}`) }}
                      </span>
                    </div>
                    <time class="text-xs text-neutral-500">{{ formatDateTime(revision.created_at) }}</time>
                  </div>

                  <div v-if="revision.diff_from_previous" class="mt-3 space-y-2">
                    <div class="flex flex-wrap gap-1.5 text-xs">
                      <span class="rounded-full bg-neutral-100 px-2 py-1 text-neutral-700">
                        {{ t(`payroll.runs.history.${revision.diff_from_previous.input_changed ? 'input_changed' : 'input_unchanged'}`) }}
                      </span>
                      <span class="rounded-full bg-neutral-100 px-2 py-1 text-neutral-700">
                        {{ t(`payroll.runs.history.${revision.diff_from_previous.ruleset_changed ? 'ruleset_changed' : 'ruleset_unchanged'}`) }}
                      </span>
                      <span class="rounded-full bg-neutral-100 px-2 py-1 text-neutral-700">
                        {{ t(`payroll.runs.history.${revision.diff_from_previous.result_changed ? 'result_changed' : 'result_unchanged'}`) }}
                      </span>
                    </div>
                    <dl v-if="historyTotalDiffs(revision).length" class="space-y-1.5">
                      <div
                        v-for="item in historyTotalDiffs(revision)"
                        :key="item.key"
                        class="flex flex-wrap items-baseline justify-between gap-2 text-xs"
                      >
                        <dt class="text-neutral-600">{{ t(`payroll.runs.history.total.${item.key}`) }}</dt>
                        <dd class="font-medium text-neutral-800">
                          {{ money(item.diff.before) }} → {{ money(item.diff.after) }}
                          <span class="ml-1 text-primary-700">
                            {{ t('payroll.runs.history.delta', { value: money(item.diff.delta) }) }}
                          </span>
                        </dd>
                      </div>
                    </dl>
                  </div>
                  <p v-else class="mt-2 text-xs text-neutral-500">
                    {{ t('payroll.runs.history.first_revision') }}
                  </p>
                </li>
              </ol>
            </div>

            <div>
              <h4 class="text-sm font-semibold text-neutral-800">{{ t('payroll.runs.history.events') }}</h4>
              <ol class="mt-3 border-l border-neutral-300 pl-4">
                <li
                  v-for="event in [...histories[run.id].events].reverse()"
                  :key="event.id"
                  class="relative pb-4 last:pb-0"
                >
                  <span class="absolute -left-[1.18rem] top-1.5 h-2 w-2 rounded-full bg-primary-500" aria-hidden="true" />
                  <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <p class="text-sm font-medium text-neutral-800">{{ historyEventLabel(event) }}</p>
                    <time class="text-xs text-neutral-500">{{ formatDateTime(event.created_at) }}</time>
                  </div>
                  <p v-if="event.from_status && event.to_status" class="mt-0.5 text-xs text-neutral-500">
                    {{ t(`payroll.runs.status.${event.from_status}`) }} → {{ t(`payroll.runs.status.${event.to_status}`) }}
                  </p>
                  <p v-if="event.actor_name" class="mt-0.5 text-xs text-neutral-500">
                    {{ t('payroll.runs.history.actor', { name: event.actor_name }) }}
                  </p>
                  <p v-if="event.reason" class="mt-1 text-sm leading-relaxed text-neutral-700">
                    {{ event.reason }}
                  </p>
                </li>
              </ol>
            </div>
          </div>
        </section>

        <PayrollIncomeTaxBreakdown
          v-if="breakdowns[run.id]?.length"
          :people="breakdowns[run.id]"
          :person-names="personNames"
        />

        <PayrollInsuranceBreakdown
          v-if="breakdowns[run.id]?.length"
          :revision-id="run.revision_id"
          :people="breakdowns[run.id]"
          :person-names="personNames"
        />

        <PayrollNetPayBreakdown
          v-if="breakdowns[run.id]?.length"
          :revision-id="run.revision_id"
          :approved="run.revision_status === 'approved'"
          :people="breakdowns[run.id]"
          :person-names="personNames"
        />

        <div v-if="run.validations.length" class="mt-4 space-y-2">
          <p class="text-sm font-medium text-warning-700">{{ t('payroll.runs.validations') }}</p>
          <div
            v-for="validation in validationGroups(run.validations)"
            :key="validation.id"
            :data-testid="`payroll-validation-${validation.id}`"
            :data-test="`payroll-validation-group-${validation.group_key}`"
            class="rounded-lg border px-3 py-2 text-sm"
            :class="validationClass(validation)"
          >
            <p>{{ validation.display_message }}</p>
            <p
              v-if="validation.entity_labels.length"
              class="mt-1 text-xs font-medium"
            >
              {{ entityLabelSummary(validation.entity_labels) }}
            </p>
            <a
              v-if="validation.remediation_path"
              :href="validation.remediation_path"
              data-test="payroll-validation-remediation"
              :class="[btnOutlineSm('neutral'), 'mt-2 inline-flex']"
            >
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path :d="ICONS.link" />
              </svg>
              {{ t('payroll.runs.validation.open_remediation') }}
            </a>
            <!--
              Zkratka přímo z běhu: odkaz výš vede tam, kde se koncepty
              schvalují po jednom, a to je u větší firmy stovky kliknutí.
            -->
            <button
              v-if="validation.code === 'draft_inputs_present' && canOverride"
              type="button"
              :data-testid="`payroll-validation-${validation.id}-approve-inputs`"
              :class="[btnOutlineSm('success'), 'mt-2 ml-2 inline-flex']"
              :disabled="saving"
              @click="approveDraftInputs(run)"
            >
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path :d="ICONS.badgeCheck" />
              </svg>
              {{ t('payroll.runs.validation.draft_inputs_approve_all') }}
            </button>

            <!--
              Varování, které čeká na člověka. Bez téhle věty uživatel vidí jen
              nálepku a netuší, že právě ona drží celý běh.
            -->
            <div
              v-if="awaitsOverride(validation)"
              class="mt-2 flex flex-wrap items-center gap-2"
              :data-testid="`payroll-validation-${validation.id}-awaiting`"
            >
              <p class="flex-1 text-xs leading-snug">
                {{ t('payroll.runs.override.awaiting') }}
              </p>
              <button
                v-if="canOverride && overrideEditable(run)"
                type="button"
                :data-testid="`payroll-validation-${validation.id}-override`"
                :class="btnOutlineSm('warning')"
                :disabled="saving"
                @click="askOverride(run, validation)"
              >
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                  <path :d="ICONS.badgeCheck" />
                </svg>
                {{ t('payroll.runs.override.grant') }}
              </button>
              <p
                v-else-if="!canOverride"
                :class="BTN_DISABLED_NOTE"
                :data-testid="`payroll-validation-${validation.id}-no-permission`"
              >
                {{ t('payroll.runs.override.no_permission') }}
              </p>
            </div>

            <!-- Vyřešené varování: kdo a s jakým odůvodněním. -->
            <div
              v-else-if="validation.overridden_at"
              class="mt-2 flex flex-wrap items-start gap-2 rounded-md bg-surface/70 px-2.5 py-2"
              :data-testid="`payroll-validation-${validation.id}-resolved`"
            >
              <div class="flex-1 space-y-0.5">
                <p class="text-xs font-medium">{{ overrideAuthorLabel(validation) }}</p>
                <p class="text-xs leading-snug">
                  {{ t('payroll.runs.override.reason_label', { reason: validation.override_reason }) }}
                </p>
              </div>
              <button
                v-if="canOverride && overrideEditable(run)"
                type="button"
                :data-testid="`payroll-validation-${validation.id}-revoke`"
                :class="btnOutlineSm('neutral')"
                :disabled="saving"
                @click="revokeOverride(run, validation)"
              >
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                  <path :d="ICONS.uturn" />
                </svg>
                {{ t('payroll.runs.override.revoke') }}
              </button>
              <p
                v-else-if="canOverride"
                :class="BTN_DISABLED_NOTE"
                :data-testid="`payroll-validation-${validation.id}-locked`"
              >
                {{ t('payroll.runs.override.locked_after_approval') }}
              </p>
            </div>
          </div>
        </div>
      </article>

      <PaginationBar
        data-testid="payroll-runs-pagination"
        :page="currentPage"
        :per-page="pageSize"
        :total="total"
        @update:page="goToPage"
      />
    </section>

    <Modal
      v-if="pendingCommand"
      :title="commandLabel(pendingCommand.command, pendingCommand.run)"
      width-class="max-w-lg"
      @close="pendingCommand = null"
    >
      <form class="space-y-4" data-test="run-command-dialog" @submit.prevent="confirmCommand">
        <label class="block text-sm font-medium text-neutral-700">
          {{ t('payroll.runs.reason_prompt') }}
          <textarea
            v-model="commandReason"
            class="mt-1 min-h-24 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm"
            required
            autofocus
            data-test="run-command-reason"
          />
        </label>
        <p
          v-if="commandError"
          class="rounded-lg border border-danger-500/30 bg-danger-50 p-3 text-sm text-danger-700"
          role="alert"
          data-test="run-command-error"
        >
          {{ commandError }}
        </p>
        <div class="flex flex-wrap justify-end gap-2">
          <button type="button" :class="btnOutline('neutral')" @click="pendingCommand = null">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.x" /></svg>
            {{ t('common.cancel') }}
          </button>
          <button
            type="submit"
            :class="commandClass(pendingCommand.run, pendingCommand.command)"
            :disabled="saving"
          >
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="commandIcon(pendingCommand.command)" /></svg>
            {{ commandLabel(pendingCommand.command, pendingCommand.run) }}
          </button>
        </div>
      </form>
    </Modal>

    <Modal
      v-if="pendingOverride"
      :title="t('payroll.runs.override.grant')"
      width-class="max-w-xl"
      @close="pendingOverride = null"
    >
      <form class="space-y-4" data-test="run-override-dialog" @submit.prevent="confirmOverride">
        <p class="rounded-lg border border-warning-200 bg-warning-50 p-3 text-sm text-warning-800">
          {{ validationDisplayMessage(pendingOverride.validation) }}
        </p>
        <label class="block text-sm font-medium text-neutral-700">
          {{ t('payroll.runs.override.reason_prompt') }}
          <textarea
            v-model="overrideReason"
            class="mt-1 min-h-24 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm"
            required
            autofocus
            data-test="run-override-reason"
          />
        </label>
        <p class="text-xs leading-snug text-neutral-500" data-test="run-override-hint">
          {{ t('payroll.runs.override.reason_hint') }}
        </p>
        <p
          v-if="overrideError"
          class="rounded-lg border border-danger-500/30 bg-danger-50 p-3 text-sm text-danger-700"
          role="alert"
          data-test="run-override-error"
        >
          {{ overrideError }}
        </p>
        <div class="flex flex-wrap justify-end gap-2">
          <button type="button" :class="btnOutline('neutral')" @click="pendingOverride = null">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.x" /></svg>
            {{ t('common.cancel') }}
          </button>
          <button
            type="submit"
            :class="btnFilled('warning')"
            :disabled="saving"
            data-test="confirm-run-override"
          >
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.badgeCheck" /></svg>
            {{ t('payroll.runs.override.grant') }}
          </button>
        </div>
      </form>
    </Modal>

    <Modal
      v-if="pendingDelete"
      :title="t('payroll.runs.delete')"
      width-class="max-w-lg"
      @close="pendingDelete = null"
    >
      <p class="text-sm text-neutral-700">{{ t('payroll.runs.delete_confirm') }}</p>
      <div class="mt-5 flex flex-wrap justify-end gap-2">
        <button type="button" :class="btnOutline('neutral')" @click="pendingDelete = null">
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.x" /></svg>
          {{ t('common.cancel') }}
        </button>
        <button
          type="button"
          :class="btnFilled('danger')"
          :disabled="saving"
          data-test="confirm-delete-run"
          @click="deleteRun"
        >
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.trash" /></svg>
          {{ t('payroll.runs.delete') }}
        </button>
      </div>
    </Modal>
  </div>
</template>

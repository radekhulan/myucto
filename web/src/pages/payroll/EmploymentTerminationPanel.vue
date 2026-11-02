<script setup lang="ts">
import DateInput from '@/components/ui/DateInput.vue'
import { computed, onMounted, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import { useI18n } from 'vue-i18n'
import {
  payrollApi,
  type PayrollTerminationGround,
  type PayrollTerminationIssue,
  type PayrollTerminationMethod,
  type PayrollTerminationOverview,
  type PayrollTerminationSurvivorRelationship,
  type PayrollWorkInjuryCompensationPayer,
} from '@/api/payroll'
import { apiErrorMessage } from '@/api/errors'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import { btnOutlineSm, ICONS } from '@/components/ui/buttonStyles'
import { formatDate, formatMoneyMinor } from '@/composables/useFormat'
import { useToast } from '@/composables/useToast'
import { averageEarningsTarget } from './payrollRemediation'

/**
 * Skončení pracovního vztahu na kartě vztahu — jediné místo, kde se zadává
 * způsob a důvod skončení. Z něj se předvyplní odhláška A2 i potvrzení pro
 * Úřad práce, vyrovnání dovolené a návrh odstupného se odsud zakládají jako
 * vstupy posledního běhu a u úmrtí se tu vedou osoby blízké (§ 328 ZP).
 */
const props = defineProps<{
  employmentId: number
  canWrite: boolean
}>()
const emit = defineEmits<{
  loaded: [overview: PayrollTerminationOverview]
}>()

const { t } = useI18n()
const toast = useToast()
const loading = ref(true)
const busy = ref(false)
const loadError = ref('')
const formError = ref('')
const data = ref<PayrollTerminationOverview | null>(null)

const method = ref<PayrollTerminationMethod | ''>('')
const ground = ref<PayrollTerminationGround>('none')
const statedReason = ref('')
const overrideMultiple = ref('')
const overrideReason = ref('')
const workingTimeAccount = ref(false)
// § 299 odst. 4 o. s. ř. — srážky z odstupného po násobcích a jiný příjem
// povinného v době poskytování odstupného.
const garnishmentMultiple = ref('')
const otherIncomeFrom = ref('')
const otherPayerConfirmed = ref(false)
// § 271ca ZP — kdo jednorázovou náhradu vyplácí a kdy.
const workInjuryPayer = ref<PayrollWorkInjuryCompensationPayer | ''>('')
const workInjuryPaidOn = ref('')
const taxAssessment = ref('')
const survivorName = ref('')
const survivorRelationship = ref<PayrollTerminationSurvivorRelationship>('spouse_partner')
const survivorHousehold = ref(true)
const survivorAccount = ref('')

const METHODS: PayrollTerminationMethod[] = [
  'employer_notice', 'agreement', 'employee_notice', 'employer_immediate',
  'employee_immediate', 'probation_employer', 'probation_employee',
  'fixed_term_expiry', 'death', 'foreigner_permit', 'other',
]

const allowedGrounds = computed<PayrollTerminationGround[]>(() => {
  if (method.value === '') return []
  return data.value?.options.allowed_grounds[method.value] ?? ['none']
})
const groundRequired = computed(() =>
  allowedGrounds.value.length > 0 && !allowedGrounds.value.includes('none'))
const groundVisible = computed(() =>
  allowedGrounds.value.length > 1 || groundRequired.value)
const statedReasonVisible = computed(() =>
  method.value === 'employee_notice' || (method.value === 'agreement' && ground.value === 'none'))
const severanceGround = computed(() =>
  (method.value === 'employer_notice' || method.value === 'agreement')
  && ['organizational', 'max_exposure', 'health_work_injury'].includes(ground.value))

watch(method, () => {
  if (!allowedGrounds.value.includes(ground.value)) {
    ground.value = allowedGrounds.value.includes('none') ? 'none' : (allowedGrounds.value[0] ?? 'none')
  }
})

const dirty = computed(() => {
  const record = data.value?.termination
  if (!record) return method.value !== ''
  return record.termination_method !== method.value
    || record.legal_ground !== ground.value
    || (record.employee_stated_reason ?? '') !== statedReason.value.trim()
    || String(record.severance_multiple_override ?? '') !== overrideMultiple.value.trim()
    || (record.severance_override_reason ?? '') !== overrideReason.value.trim()
    || record.working_time_account_applies !== workingTimeAccount.value
    || (record.other_income_from ?? '') !== otherIncomeFrom.value
    || (record.other_payer_applies_protected_amount ?? false) !== otherPayerConfirmed.value
})

const formBlockReason = computed(() => {
  if (method.value === '') return t('payroll.people.termination.blocked.method')
  if (groundRequired.value && !allowedGrounds.value.includes(ground.value)) {
    return t('payroll.people.termination.blocked.ground')
  }
  if (overrideMultiple.value.trim() !== '' && overrideReason.value.trim() === '') {
    return t('payroll.people.termination.blocked.override_reason')
  }
  if (otherPayerConfirmed.value && otherIncomeFrom.value === '') {
    return t('payroll.people.termination.blocked.other_payer_date')
  }
  return ''
})

// `type="number"` vrací přes v-model číslo, prázdné pole řetězec.
const garnishmentMultipleValid = computed(() => /^\d+$/.test(String(garnishmentMultiple.value).trim())
  && Number(garnishmentMultiple.value) >= 1 && Number(garnishmentMultiple.value) <= 36)
const workInjuryReady = computed(() => workInjuryPayer.value !== '' && workInjuryPaidOn.value !== '')

function applyOverview(overview: PayrollTerminationOverview): void {
  data.value = overview
  const record = overview.termination
  method.value = record?.termination_method ?? ''
  ground.value = record?.legal_ground ?? 'none'
  statedReason.value = record?.employee_stated_reason ?? ''
  overrideMultiple.value = record?.severance_multiple_override === null || record === null
    ? ''
    : String(record.severance_multiple_override)
  overrideReason.value = record?.severance_override_reason ?? ''
  workingTimeAccount.value = record?.working_time_account_applies ?? false
  otherIncomeFrom.value = record?.other_income_from ?? ''
  otherPayerConfirmed.value = record?.other_payer_applies_protected_amount ?? false
  garnishmentMultiple.value = overview.severance.garnishment_multiple === null
    ? ''
    : String(overview.severance.garnishment_multiple)
  workInjuryPaidOn.value = overview.severance.work_injury_paid_on ?? overview.employment.end_date
  taxAssessment.value = record?.death_tax_assessment ?? ''
  emit('loaded', overview)
}

async function load(): Promise<void> {
  loading.value = true
  loadError.value = ''
  try {
    applyOverview(await payrollApi.employmentTermination(props.employmentId))
  } catch (error) {
    loadError.value = apiErrorMessage(error, t('payroll.people.termination.load_failed'))
  } finally {
    loading.value = false
  }
}

async function mutate(operation: () => Promise<PayrollTerminationOverview>, success: string): Promise<void> {
  if (busy.value) return
  busy.value = true
  formError.value = ''
  try {
    applyOverview(await operation())
    toast.success(t(success))
  } catch (error) {
    formError.value = apiErrorMessage(error, t('payroll.people.termination.action_failed'))
    toast.error(formError.value)
  } finally {
    busy.value = false
  }
}

function save(): Promise<void> {
  if (formBlockReason.value !== '' || method.value === '') return Promise.resolve()
  const selected = method.value
  return mutate(() => payrollApi.saveEmploymentTermination(props.employmentId, {
    termination_method: selected,
    legal_ground: ground.value,
    employee_stated_reason: statedReasonVisible.value ? (statedReason.value.trim() || null) : null,
    severance_multiple_override: severanceGround.value && overrideMultiple.value.trim() !== ''
      ? Number(overrideMultiple.value)
      : null,
    severance_override_reason: severanceGround.value && overrideMultiple.value.trim() !== ''
      ? overrideReason.value.trim()
      : null,
    working_time_account_applies: severanceGround.value && ground.value === 'organizational' && workingTimeAccount.value,
    other_income_from: otherIncomeFrom.value || null,
    other_payer_applies_protected_amount: otherIncomeFrom.value !== '' && otherPayerConfirmed.value,
    ...(data.value?.termination ? { row_version: data.value.termination.row_version } : {}),
  }), 'payroll.people.termination.saved')
}

function reverseLeave(): void {
  if (!window.confirm(t('payroll.people.termination.leave.confirm_reverse'))) return
  void mutate(() => payrollApi.reverseTerminationLeave(props.employmentId), 'payroll.people.termination.leave.reversed')
}

function addSurvivor(): void {
  const name = survivorName.value.trim()
  if (name === '') return
  void mutate(() => payrollApi.addTerminationSurvivor(props.employmentId, {
    full_name: name,
    relationship: survivorRelationship.value,
    shared_household: survivorHousehold.value,
    bank_account: survivorAccount.value.trim() || null,
    note: null,
  }), 'payroll.people.termination.death.survivor_added').then(() => {
    survivorName.value = ''
    survivorAccount.value = ''
  })
}

function removeSurvivor(survivorId: number): void {
  void mutate(() => payrollApi.removeTerminationSurvivor(props.employmentId, survivorId), 'payroll.people.termination.death.survivor_removed')
}

function saveTaxAssessment(): void {
  const record = data.value?.termination
  if (!record || taxAssessment.value.trim() === '') return
  void mutate(
    () => payrollApi.assessTerminationDeathTax(props.employmentId, taxAssessment.value.trim(), record.row_version),
    'payroll.people.termination.death.tax_saved',
  )
}

const leave = computed(() => data.value?.leave_settlement ?? null)
const severance = computed(() => data.value?.severance ?? null)
const death = computed(() => data.value?.death ?? null)
const hasRecord = computed(() => data.value?.termination !== null && data.value?.termination !== undefined)

function hours(minutes: number): string {
  const abs = Math.abs(minutes)
  const h = Math.floor(abs / 60)
  const m = abs % 60
  return m === 0 ? `${h}` : `${h}:${String(m).padStart(2, '0')}`
}

const actions = computed<ActionItem[]>(() => [
  {
    key: 'termination-save',
    label: t('payroll.people.termination.save'),
    icon: 'check',
    tier: 'primary',
    variant: 'primary',
    show: props.canWrite && (dirty.value || !hasRecord.value),
    disabled: busy.value || formBlockReason.value !== '',
    disabledReason: formBlockReason.value,
    run: () => void save(),
  },
  {
    key: 'termination-leave-payout',
    label: t('payroll.people.termination.leave.settle_payout'),
    icon: 'coin',
    tier: hasRecord.value && !dirty.value ? 'primary' : 'secondary',
    variant: 'success',
    show: props.canWrite && leave.value?.state === 'payout',
    disabled: busy.value || dirty.value,
    disabledReason: dirty.value ? t('payroll.people.termination.blocked.unsaved') : undefined,
    run: () => void mutate(() => payrollApi.settleTerminationLeave(props.employmentId), 'payroll.people.termination.leave.settled'),
  },
  {
    key: 'termination-leave-overdraft',
    label: t('payroll.people.termination.leave.settle_overdraft'),
    icon: 'coin',
    tier: 'secondary',
    variant: 'warning',
    show: props.canWrite && leave.value?.state === 'overdraft',
    disabled: busy.value || dirty.value,
    disabledReason: dirty.value ? t('payroll.people.termination.blocked.unsaved') : undefined,
    run: () => void mutate(() => payrollApi.settleTerminationLeave(props.employmentId), 'payroll.people.termination.leave.settled'),
  },
  {
    key: 'termination-severance',
    label: t('payroll.people.termination.severance.create'),
    icon: 'plus',
    tier: 'secondary',
    variant: 'success',
    show: props.canWrite && severance.value?.state === 'ready' && severance.value.kind === 'severance',
    disabled: busy.value || dirty.value || !garnishmentMultipleValid.value,
    disabledReason: dirty.value
      ? t('payroll.people.termination.blocked.unsaved')
      : !garnishmentMultipleValid.value ? t('payroll.people.termination.blocked.garnishment_multiple') : undefined,
    run: () => void mutate(
      () => payrollApi.createTerminationSeverance(props.employmentId, Number(garnishmentMultiple.value)),
      'payroll.people.termination.severance.created_toast',
    ),
  },
  {
    key: 'termination-work-injury',
    label: t('payroll.people.termination.work_injury.create'),
    icon: 'plus',
    tier: 'secondary',
    variant: 'success',
    show: props.canWrite && severance.value?.state === 'ready' && severance.value.kind === 'work_injury_compensation',
    disabled: busy.value || dirty.value || !workInjuryReady.value,
    disabledReason: dirty.value
      ? t('payroll.people.termination.blocked.unsaved')
      : !workInjuryReady.value ? t('payroll.people.termination.blocked.work_injury_input') : undefined,
    run: () => void mutate(
      () => payrollApi.createTerminationWorkInjuryCompensation(props.employmentId, {
        payer: workInjuryPayer.value as PayrollWorkInjuryCompensationPayer,
        paid_on: workInjuryPaidOn.value,
      }),
      'payroll.people.termination.work_injury.created_toast',
    ),
  },
  {
    key: 'termination-leave-reverse',
    label: t('payroll.people.termination.leave.reverse'),
    icon: 'uturn',
    tier: 'advanced',
    variant: 'warning',
    show: props.canWrite && leave.value?.state === 'settled',
    disabled: busy.value,
    run: () => reverseLeave(),
  },
  {
    key: 'termination-reload',
    label: t('common.refresh'),
    icon: 'cycle',
    tier: 'overflow',
    variant: 'neutral',
    disabled: loading.value || busy.value,
    run: () => void load(),
  },
])

/*
 * Každá překážka říká CO chybí, U KOHO (tenhle vztah) a KDE se to napraví —
 * s proklikem. Text nese i18n podle kódu, parametry dodává server.
 */
function issueText(issue: PayrollTerminationIssue): string {
  const params: Record<string, string | number | null> = {}
  for (const [key, value] of Object.entries(issue.params)) {
    params[key] = typeof value === 'number' && key.endsWith('_minor')
      ? formatMoneyMinor(value)
      : key === 'minutes' && typeof value === 'number' ? hours(value) : value
  }
  return t(`payroll.people.termination.issues.${issue.code}`, params)
}

function issueLink(issue: PayrollTerminationIssue): { to: string, label: string } | null {
  const employmentId = props.employmentId
  switch (issue.code) {
    case 'average_missing':
    case 'average_not_supported':
    case 'average_conversion_failed':
      return {
        to: averageEarningsTarget(employmentId, Number(issue.params.year ?? data.value?.average.year), Number(issue.params.quarter ?? data.value?.average.quarter)),
        label: t('payroll.remediation.actions.average'),
      }
    case 'leave_entitlement_missing':
    case 'leave_previous_year_balance':
      return { to: `/payroll/absences?employment=${employmentId}&tab=leave`, label: t('payroll.people.termination.links.leave') }
    case 'death_enforcement_active':
      return { to: '/payroll/enforcement', label: t('payroll.people.termination.links.enforcement') }
    case 'death_deduction_agreements_active':
      return { to: '/payroll/deduction-agreements', label: t('payroll.people.termination.links.deductions') }
    case 'severance_garnishment_multiple_missing':
      return { to: `/payroll/quick-inputs?employment=${employmentId}`, label: t('payroll.people.termination.links.inputs') }
    default:
      return null
  }
}

const issueClass: Record<PayrollTerminationIssue['severity'], string> = {
  blocker: 'border-danger-500/30 bg-danger-50 text-danger-800',
  warning: 'border-warning-500/30 bg-warning-50 text-warning-800',
  info: 'border-primary-200 bg-primary-50 text-primary-800',
}

const FIELD = 'block min-w-0 text-xs font-medium text-neutral-600'
const INPUT = 'mt-1 h-9 w-full min-w-0 rounded-md border border-neutral-300 bg-surface px-3 text-sm text-neutral-900 focus:border-payroll-500 focus:outline-none focus:ring-2 focus:ring-payroll-500/20 disabled:cursor-not-allowed disabled:bg-neutral-100'
const HINT = 'mt-1 block text-xs font-normal text-neutral-500'

onMounted(() => void load())
</script>

<template>
  <section class="mt-5 rounded-lg border border-neutral-200 bg-neutral-50/60 p-3 sm:p-4" data-test="employment-termination">
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div class="min-w-0">
        <h4 class="text-sm font-semibold text-neutral-900">{{ t('payroll.people.termination.title') }}</h4>
        <p class="mt-1 text-xs text-neutral-500">{{ t('payroll.people.termination.subtitle') }}</p>
      </div>
    </div>

    <div v-if="loading" class="mt-3 h-20 animate-pulse rounded-lg bg-neutral-100" />
    <p v-else-if="loadError" class="mt-3 rounded-lg border border-danger-500/30 bg-danger-50 p-3 text-sm text-danger-700" role="alert">
      {{ loadError }}
    </p>

    <template v-else-if="data">
      <ul v-if="data.issues.length" class="mt-3 space-y-2" data-test="termination-issues">
        <li
          v-for="issue in data.issues"
          :key="issue.code"
          class="flex flex-wrap items-center justify-between gap-2 rounded-md border px-3 py-2 text-xs"
          :class="issueClass[issue.severity]"
          :data-test="`termination-issue-${issue.code}`"
        >
          <span class="min-w-0">{{ issueText(issue) }}</span>
          <RouterLink v-if="issueLink(issue)" :to="issueLink(issue)!.to" :class="[btnOutlineSm('warning'), 'whitespace-nowrap']">
            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.link" /></svg>
            {{ issueLink(issue)!.label }}
          </RouterLink>
        </li>
      </ul>

      <!-- Způsob a důvod skončení -->
      <div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
        <label :class="FIELD">
          {{ t('payroll.people.termination.method_label') }}
          <select v-model="method" :class="INPUT" :disabled="!canWrite || busy" data-test="termination-method">
            <option value="" disabled>{{ t('payroll.people.termination.select_placeholder') }}</option>
            <option v-for="value in METHODS" :key="value" :value="value">{{ t(`payroll.people.termination.methods.${value}`) }}</option>
          </select>
          <span :class="HINT">{{ t('payroll.people.termination.method_hint') }}</span>
        </label>
        <label v-if="groundVisible" :class="FIELD">
          {{ t('payroll.people.termination.ground_label') }}
          <select v-model="ground" :class="INPUT" :disabled="!canWrite || busy" data-test="termination-ground">
            <option v-for="value in allowedGrounds" :key="value" :value="value">{{ t(`payroll.people.termination.grounds.${value}`) }}</option>
          </select>
          <span :class="HINT">{{ t('payroll.people.termination.ground_hint') }}</span>
        </label>
        <label v-if="statedReasonVisible" :class="FIELD">
          {{ t('payroll.people.termination.stated_reason_label') }}
          <input v-model="statedReason" :class="INPUT" :disabled="!canWrite || busy" maxlength="1000" data-test="termination-stated-reason">
          <span :class="HINT">{{ t('payroll.people.termination.stated_reason_hint') }}</span>
        </label>
        <template v-if="severanceGround">
          <label :class="FIELD">
            {{ t('payroll.people.termination.override_label') }}
            <input v-model="overrideMultiple" type="number" min="1" max="36" :class="INPUT" :disabled="!canWrite || busy" data-test="termination-override">
            <span :class="HINT">{{ t('payroll.people.termination.override_hint') }}</span>
          </label>
          <label v-if="overrideMultiple.trim() !== ''" :class="FIELD">
            {{ t('payroll.people.termination.override_reason_label') }}
            <input v-model="overrideReason" :class="INPUT" :disabled="!canWrite || busy" maxlength="500" data-test="termination-override-reason">
          </label>
          <label v-if="ground === 'organizational'" class="flex items-start gap-2 text-xs text-neutral-700 sm:col-span-2 lg:col-span-3">
            <input v-model="workingTimeAccount" type="checkbox" class="mt-0.5 rounded border-neutral-300 text-payroll-600" :disabled="!canWrite || busy">
            <span>{{ t('payroll.people.termination.working_time_account_label') }}</span>
          </label>
        </template>
      </div>

      <p v-if="data.derived && !dirty" class="mt-3 rounded-md border border-primary-200 bg-primary-50 px-3 py-2 text-xs text-primary-800" data-test="termination-derived">
        {{ t('payroll.people.termination.derived', {
          code: data.derived.regzec_reason_code,
          kind: t(`payroll.people.exit_documents.termination_reasons.${data.derived.unemployment_office_kind}`),
        }) }}
      </p>

      <!-- Vyrovnání dovolené -->
      <div v-if="leave" class="mt-4 rounded-md border border-neutral-200 bg-surface p-3 text-sm" data-test="termination-leave">
        <p class="text-xs font-semibold uppercase tracking-wide text-neutral-500">{{ t('payroll.people.termination.leave.title', { year: leave.year }) }}</p>
        <p class="mt-1 text-neutral-800">
          {{ t(`payroll.people.termination.leave.states.${leave.state === 'settled' ? `settled_${leave.settlement}` : leave.state}`, {
            hours: hours(leave.minutes),
            amount: formatMoneyMinor(Math.abs(leave.amount_minor)),
            hourly: leave.average_hourly_minor === null ? '—' : formatMoneyMinor(leave.average_hourly_minor),
          }) }}
        </p>
      </div>

      <!-- Odstupné -->
      <div v-if="severance && severance.kind" class="mt-3 rounded-md border border-neutral-200 bg-surface p-3 text-sm" data-test="termination-severance">
        <p class="text-xs font-semibold uppercase tracking-wide text-neutral-500">{{ t(`payroll.people.termination.severance.kind.${severance.kind}`) }}</p>
        <p class="mt-1 text-neutral-800">
          {{ t('payroll.people.termination.severance.proposal', {
            multiple: severance.multiple,
            statutory: severance.statutory_multiple,
            average: severance.monthly_average_minor === null ? '—' : formatMoneyMinor(severance.monthly_average_minor),
            amount: formatMoneyMinor(severance.amount_minor),
          }) }}
        </p>
        <p v-if="severance.tenure_start" class="mt-1 text-xs text-neutral-500">
          {{ t('payroll.people.termination.severance.tenure', { date: formatDate(severance.tenure_start) }) }}
        </p>
        <p v-if="severance.input" class="mt-1 text-xs text-success-700">
          {{ t('payroll.people.termination.severance.created', { period: severance.input.period_start.slice(0, 7), amount: formatMoneyMinor(severance.input.amount_minor) }) }}
        </p>
        <p v-if="severance.state === 'insurer'" class="mt-1 text-xs text-success-700" data-test="termination-work-injury-insurer">
          {{ t('payroll.people.termination.work_injury.insurer_state', { date: formatDate(severance.work_injury_paid_on ?? '') }) }}
        </p>
        <p v-else-if="severance.work_injury_payer === 'employer'" class="mt-1 text-xs text-neutral-600">
          {{ t('payroll.people.termination.work_injury.employer_state', { date: formatDate(severance.work_injury_paid_on ?? '') }) }}
        </p>
        <p v-if="severance.state === 'created' && severance.garnishment_multiple !== null && severance.garnishment_period_to" class="mt-1 text-xs text-neutral-600" data-test="termination-severance-garnishment">
          {{ t('payroll.people.termination.severance.garnishment_info', { multiple: severance.garnishment_multiple, date: formatDate(severance.garnishment_period_to) }) }}
        </p>

        <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
          <label v-if="severance.state === 'ready' && severance.kind === 'severance'" :class="FIELD">
            {{ t('payroll.people.termination.severance.garnishment_multiple_label') }}
            <input v-model="garnishmentMultiple" type="number" min="1" max="36" :class="INPUT" :disabled="!canWrite || busy" data-test="termination-garnishment-multiple">
            <span :class="HINT">{{ t('payroll.people.termination.severance.garnishment_multiple_hint') }}</span>
          </label>
          <template v-if="severance.state === 'ready' && severance.kind === 'work_injury_compensation'">
            <label :class="FIELD">
              {{ t('payroll.people.termination.work_injury.payer_label') }}
              <select v-model="workInjuryPayer" :class="INPUT" :disabled="!canWrite || busy" data-test="termination-work-injury-payer">
                <option value="" disabled>{{ t('payroll.people.termination.work_injury.payer_placeholder') }}</option>
                <option v-for="value in (['employer', 'insurer'] as const)" :key="value" :value="value">{{ t(`payroll.people.termination.work_injury.payers.${value}`) }}</option>
              </select>
            </label>
            <label :class="FIELD">
              {{ t('payroll.people.termination.work_injury.paid_on_label') }}
              <DateInput v-model="workInjuryPaidOn" :min="data.employment.end_date" :class="INPUT" :disabled="!canWrite || busy" data-test="termination-work-injury-paid-on" />
              <span :class="HINT">{{ t('payroll.people.termination.work_injury.paid_on_hint') }}</span>
            </label>
          </template>
          <label :class="FIELD">
            {{ t('payroll.people.termination.severance.other_income_label') }}
            <DateInput v-model="otherIncomeFrom" :class="INPUT" :disabled="!canWrite || busy" data-test="termination-other-income-from" />
            <span :class="HINT">{{ t('payroll.people.termination.severance.other_income_hint') }}</span>
          </label>
          <label v-if="otherIncomeFrom !== ''" class="flex items-start gap-2 text-xs text-neutral-700 sm:col-span-2">
            <input v-model="otherPayerConfirmed" type="checkbox" class="mt-0.5 rounded border-neutral-300 text-payroll-600" :disabled="!canWrite || busy" data-test="termination-other-payer">
            <span>
              {{ t('payroll.people.termination.severance.other_payer_label') }}
              <span :class="HINT">{{ t('payroll.people.termination.severance.other_payer_hint') }}</span>
            </span>
          </label>
        </div>
      </div>

      <!-- Úmrtí (§ 328 ZP) -->
      <div v-if="death" class="mt-3 rounded-md border border-neutral-200 bg-surface p-3 text-sm" data-test="termination-death">
        <p class="text-xs font-semibold uppercase tracking-wide text-neutral-500">{{ t('payroll.people.termination.death.title') }}</p>
        <p class="mt-1 text-xs text-neutral-600">
          {{ t('payroll.people.termination.death.limit', { amount: death.limit_minor === null ? '—' : formatMoneyMinor(death.limit_minor) }) }}
        </p>
        <ul class="mt-2 space-y-1">
          <li v-for="survivor in death.survivors" :key="survivor.id" class="flex flex-wrap items-center justify-between gap-2 rounded bg-neutral-50 px-2 py-1 text-xs">
            <span class="min-w-0">
              {{ survivor.full_name }} · {{ t(`payroll.people.termination.death.relationships.${survivor.relationship}`) }}
              · {{ survivor.shared_household ? t('payroll.people.termination.death.household_yes') : t('payroll.people.termination.death.household_no') }}
              <strong v-if="survivor.entitled" class="text-success-700">
                · {{ t('payroll.people.termination.death.entitled', { amount: formatMoneyMinor(survivor.limit_share_minor) }) }}
              </strong>
            </span>
            <button
              v-if="canWrite"
              type="button"
              :class="btnOutlineSm('danger')"
              :disabled="busy"
              @click="removeSurvivor(survivor.id)"
            >
              <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.trash" /></svg>
              {{ t('common.remove') }}
            </button>
          </li>
        </ul>
        <form v-if="canWrite" class="mt-3 grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-4" data-test="termination-survivor-form" @submit.prevent="addSurvivor">
          <label :class="FIELD">{{ t('payroll.people.termination.death.name') }}<input v-model="survivorName" :class="INPUT" maxlength="255" required></label>
          <label :class="FIELD">
            {{ t('payroll.people.termination.death.relationship') }}
            <select v-model="survivorRelationship" :class="INPUT">
              <option v-for="value in (['spouse_partner', 'child', 'parent'] as const)" :key="value" :value="value">{{ t(`payroll.people.termination.death.relationships.${value}`) }}</option>
            </select>
          </label>
          <label :class="FIELD">{{ t('payroll.people.termination.death.bank_account') }}<input v-model="survivorAccount" :class="INPUT" maxlength="64"></label>
          <div class="flex flex-wrap items-end gap-2">
            <label class="flex items-center gap-2 text-xs text-neutral-700 whitespace-nowrap">
              <input v-model="survivorHousehold" type="checkbox" class="rounded border-neutral-300 text-payroll-600">
              {{ t('payroll.people.termination.death.household') }}
            </label>
            <button type="submit" :class="btnOutlineSm('primary')" :disabled="busy || survivorName.trim() === ''" class="whitespace-nowrap">
              <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.plus" /></svg>
              {{ t('payroll.people.termination.death.add') }}
            </button>
          </div>
        </form>
        <div class="mt-3">
          <label :class="FIELD">
            {{ t('payroll.people.termination.death.tax_label') }}
            <textarea v-model="taxAssessment" rows="2" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm" :disabled="!canWrite || busy" maxlength="1000" data-test="termination-tax-assessment"></textarea>
            <span :class="HINT">{{ t('payroll.people.termination.death.tax_hint') }}</span>
          </label>
          <button
            v-if="canWrite"
            type="button"
            :class="btnOutlineSm('warning')"
            class="mt-2 whitespace-nowrap"
            :disabled="busy || taxAssessment.trim() === '' || taxAssessment.trim() === (data.termination?.death_tax_assessment ?? '')"
            data-test="termination-tax-save"
            @click="saveTaxAssessment"
          >
            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.check" /></svg>
            {{ t('payroll.people.termination.death.tax_save') }}
          </button>
        </div>
      </div>

      <p v-if="formError" class="mt-3 rounded-lg border border-danger-500/30 bg-danger-50 p-3 text-sm text-danger-700" role="alert">{{ formError }}</p>
      <ActionBar :actions="actions" class="mt-4" />
    </template>
  </section>
</template>

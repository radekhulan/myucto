<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink, useRoute, useRouter, type RouteLocationRaw } from 'vue-router'
import {
  payrollApi,
  type PayrollMonthGuideResponse,
  type PayrollMonthGuideStep,
  type PayrollRunReadinessBulkKind,
} from '@/api/payroll'
import { apiErrorMessage } from '@/api/errors'
import { btnFilled, btnOutline, btnOutlineSm, ICONS } from '@/components/ui/buttonStyles'
import { formatDate, formatPeriodNumeric } from '@/composables/useFormat'
import { useWindowRefresh } from '@/composables/useWindowRefresh'
import { payrollQueryPeriod } from './payrollComponentsUi'
import { annualSettlementRequestTarget } from './jmhzBlockerRemediation'

interface GuideLink {
  key: string
  label: string
  to: RouteLocationRaw
}

const { t, te } = useI18n()
const route = useRoute()
const router = useRouter()

const period = ref(payrollQueryPeriod(route.query))
const environment = computed(() => (route.query.environment === 'test' ? 'test' : 'production'))
const guide = ref<PayrollMonthGuideResponse | null>(null)
const loading = ref(true)
const error = ref('')
let sequence = 0

const steps = computed(() => guide.value?.steps ?? [])
const countable = computed(() => steps.value.filter(step => step.state !== 'unavailable'))
const doneCount = computed(() => countable.value.filter(step => step.state === 'done').length)
const allDone = computed(() => countable.value.length > 0 && doneCount.value === countable.value.length)

const STEP_ICONS: Record<PayrollMonthGuideStep['key'], string> = {
  inputs: ICONS.calendar,
  people: ICONS.user,
  registrations: ICONS.clipboardCheck,
  check: ICONS.funnel,
  run: ICONS.play,
  submissions: ICONS.send,
  payments: ICONS.coin,
}

async function load(options: { silent?: boolean } = {}): Promise<void> {
  const current = ++sequence
  if (!options.silent) loading.value = true
  error.value = ''
  try {
    const data = await payrollApi.monthGuide(period.value, environment.value)
    if (current === sequence) guide.value = data
  } catch (exception) {
    if (current === sequence) error.value = apiErrorMessage(exception, t('payroll.month_guide.load_failed'))
  } finally {
    if (current === sequence) loading.value = false
  }
}

function changePeriod(): void {
  if (!/^\d{4}-(0[1-9]|1[0-2])$/.test(period.value)) return
  void router.replace({ query: { ...route.query, period: period.value } })
  void load()
}

function periodQuery() {
  return { period: period.value }
}

function primaryTarget(step: PayrollMonthGuideStep): RouteLocationRaw | null {
  switch (step.action) {
    case 'time': return { name: 'payroll-time', query: periodQuery() }
    case 'attendance_import': return { name: 'payroll-imports', query: { tab: 'attendance' } }
    case 'absences': return { name: 'payroll-absences', query: periodQuery() }
    case 'quick_inputs': return { name: 'payroll-quick-inputs', query: periodQuery() }
    case 'people_gaps': return { name: 'payroll-people', query: { filter: 'blocking_data' } }
    case 'people': return { name: 'payroll-people' }
    case 'registration_a1': return { name: 'payroll-submissions-tab', params: { tab: 'registration_a1' }, query: { ...periodQuery(), environment: environment.value } }
    case 'health': return { name: 'payroll-submissions-tab', params: { tab: 'health' }, query: { environment: environment.value } }
    case 'runs':
    case 'create_run': return { name: 'payroll-runs', query: periodQuery() }
    case 'monthly_checklist': return { name: 'payroll-submissions-tab', params: { tab: 'monthly' }, query: { ...periodQuery(), environment: environment.value } }
    case 'payments': return { name: 'payroll-payments', query: periodQuery() }
    default: return null
  }
}

function primaryLabel(step: PayrollMonthGuideStep): string {
  if (step.key === 'run' && step.action === 'runs') return t('payroll.month_guide.action.run')
  return step.action ? t(`payroll.month_guide.action.${step.action}`) : ''
}

function bulkTarget(kind: PayrollRunReadinessBulkKind, employeeIds: number[] | undefined): RouteLocationRaw {
  if (kind === 'annual_settlement_request' || kind === 'annual_settlement_request_safe') {
    return annualSettlementRequestTarget(period.value, employeeIds, true)
  }
  const employees = employeeIds?.length ? { employees: employeeIds.join(',') } : {}
  return { name: 'payroll-people', query: { bulk: kind, period: period.value, ...employees, back: route.fullPath } }
}

function secondaryLinks(step: PayrollMonthGuideStep): GuideLink[] {
  const facts = step.facts
  switch (step.key) {
    case 'inputs':
      return [
        { key: 'quick_inputs', label: t('payroll.month_guide.secondary.quick_inputs'), to: { name: 'payroll-quick-inputs', query: periodQuery() } },
        { key: 'attendance_import', label: t('payroll.month_guide.secondary.attendance_import'), to: { name: 'payroll-imports', query: { tab: 'attendance' } } },
      ].filter(link => link.key !== step.action)
    case 'people':
      return [{ key: 'people_import', label: t('payroll.month_guide.secondary.people_import'), to: { name: 'payroll-imports', query: { tab: 'attendance' } } }]
    case 'registrations': {
      const links: GuideLink[] = [
        { key: 'queue', label: t('payroll.month_guide.secondary.queue'), to: { name: 'payroll-submissions-tab', params: { tab: 'queue' }, query: { environment: environment.value } } },
      ]
      const external = facts?.unprepared_overdue ?? 0
      if (external > 0) {
        links.push({ key: 'external', label: t('payroll.month_guide.secondary.external', { count: external }), to: { name: 'payroll-dashboard', hash: '#payroll-deadlines' } })
      }
      return links
    }
    case 'check':
      return (facts?.bulk_actions ?? []).map(action => ({
        key: `bulk-${action.kind}`,
        label: t(`payroll.runs.readiness.bulk.${action.kind}`, { count: action.count, period: formatPeriodNumeric(period.value) }),
        to: bulkTarget(action.kind, action.employee_ids),
      }))
    case 'submissions':
      return [{ key: 'jmhz', label: t('payroll.month_guide.secondary.jmhz'), to: { name: 'payroll-submissions-tab', params: { tab: 'jmhz' }, query: { ...periodQuery(), environment: environment.value } } }]
    case 'payments':
      return [{ key: 'documents', label: t('payroll.month_guide.secondary.documents'), to: { name: 'payroll-documents', query: periodQuery() } }]
    default:
      return []
  }
}

function codeLabel(code: string): string {
  const key = `payroll.month_guide.code.${code}`
  return te(key) ? t(key) : t('payroll.month_guide.code.other')
}

function factLines(step: PayrollMonthGuideStep): string[] {
  const facts = step.facts
  if (facts === null) return []
  const lines: string[] = []
  switch (step.key) {
    case 'inputs':
      if (facts.attendance_batches) {
        lines.push(facts.attendance_batches.count > 0
          ? t('payroll.month_guide.facts.batches', { count: facts.attendance_batches.count, persons: facts.attendance_batches.persons })
          : t('payroll.month_guide.facts.no_batches'))
      }
      if ((facts.stopping ?? 0) > 0) lines.push(t('payroll.month_guide.facts.stopping', { count: facts.stopping }))
      break
    case 'people':
      if ((facts.stopping ?? 0) > 0) lines.push(t('payroll.month_guide.facts.stopping', { count: facts.stopping }))
      break
    case 'registrations':
      if (facts.registration_a1) {
        const a1 = facts.registration_a1
        const range = { from: formatDate(a1.from), to: formatDate(a1.to) }
        lines.push(t('payroll.month_guide.facts.a1', { ...a1, ...range }))
      }
      if (facts.hoz_open !== null && facts.hoz_open !== undefined) {
        lines.push(t('payroll.month_guide.facts.hoz', { count: facts.hoz_open }))
      }
      break
    case 'check':
      if (facts.source === 'run') {
        lines.push(t('payroll.month_guide.facts.run_blockers', { count: step.remaining }))
      } else if (facts.by_impact) {
        lines.push(t('payroll.month_guide.facts.by_impact', {
          stop: facts.by_impact.blocking + facts.by_impact.approval,
          revision: facts.by_impact.revision,
          anytime: facts.by_impact.anytime,
        }))
      } else {
        lines.push(t('payroll.month_guide.facts.readiness_unknown'))
      }
      break
    case 'run':
      lines.push(facts.status
        ? t('payroll.month_guide.facts.status', { status: t(`payroll.runs.status.${facts.status}`) })
        : t('payroll.month_guide.facts.no_run'))
      break
    case 'submissions':
      if (facts.summary) {
        lines.push(t('payroll.month_guide.facts.checklist', {
          done: facts.summary.done,
          total: facts.summary.total,
          send: facts.summary.send,
          generate: facts.summary.generate,
          manual: facts.summary.manual,
          waiting: facts.summary.await,
        }))
      }
      if (facts.jmhz && facts.jmhz.total > 0) lines.push(t('payroll.month_guide.facts.jmhz', facts.jmhz))
      break
    case 'payments':
      if (facts.coverage) {
        lines.push(t('payroll.month_guide.facts.coverage', {
          settled: facts.coverage.liability_count - facts.coverage.uncovered_count,
          total: facts.coverage.liability_count,
        }))
      }
      if (facts.documents_total !== null && facts.documents_total !== undefined) {
        lines.push(t('payroll.month_guide.facts.documents', { count: facts.documents_total }))
      }
      break
  }
  return lines
}

function stateLabel(step: PayrollMonthGuideStep): string {
  if (step.state === 'todo') {
    return step.remaining > 0
      ? t('payroll.month_guide.state.todo', { count: step.remaining }, step.remaining)
      : t('payroll.month_guide.state.todo_plain')
  }
  return t(`payroll.month_guide.state.${step.state}`)
}

function stepHint(step: PayrollMonthGuideStep): string {
  if (step.state === 'unavailable') return t('payroll.month_guide.unavailable_hint')
  const waitingKey = `payroll.month_guide.steps.${step.key}.waiting`
  if (step.state === 'waiting' && te(waitingKey)) return t(waitingKey)
  return t(`payroll.month_guide.steps.${step.key}.hint`)
}

const STATE_BADGE: Record<PayrollMonthGuideStep['state'], string> = {
  done: 'bg-success-50 text-success-700 ring-success-500/30',
  todo: 'bg-warning-50 text-warning-800 ring-warning-500/30',
  waiting: 'bg-neutral-100 text-neutral-600 ring-neutral-300',
  unavailable: 'bg-neutral-100 text-neutral-500 ring-neutral-300',
}

useWindowRefresh(() => load({ silent: true }), () => !loading.value)

onMounted(() => { void load() })
</script>

<template>
  <div class="space-y-6" data-test="payroll-month-guide">
    <header class="flex flex-wrap items-end justify-between gap-4">
      <div class="min-w-0">
        <h1 class="text-2xl font-semibold text-neutral-900">{{ t('payroll.month_guide.title') }}</h1>
        <p class="mt-1 max-w-3xl text-sm text-neutral-500">{{ t('payroll.month_guide.subtitle') }}</p>
      </div>
      <div class="flex flex-wrap items-end gap-3">
        <label class="block">
          <span class="mb-1 block text-xs font-medium text-neutral-600">{{ t('payroll.month_guide.period') }}</span>
          <input
            v-model="period"
            type="month"
            class="h-9 rounded-md border border-neutral-300 bg-surface px-3 text-sm"
            data-test="month-guide-period"
            @change="changePeriod"
          >
        </label>
        <button type="button" :class="btnOutline('neutral')" :disabled="loading" data-test="month-guide-refresh" @click="load()">
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.cycle" /></svg>
          {{ t('payroll.month_guide.refresh') }}
        </button>
      </div>
    </header>

    <div v-if="error" class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-danger-500/30 bg-danger-50 p-4 text-sm text-danger-700" data-test="month-guide-error">
      <span>{{ error }}</span>
      <button type="button" :class="btnOutlineSm('danger')" @click="load()">{{ t('payroll.month_guide.retry') }}</button>
    </div>

    <p v-if="loading && guide === null" class="text-sm text-neutral-500" data-test="month-guide-loading">{{ t('payroll.month_guide.loading') }}</p>

    <template v-if="guide">
      <div class="flex flex-wrap items-center gap-3 rounded-xl border border-payroll-500/30 bg-payroll-50 p-4 text-sm" data-test="month-guide-progress">
        <span class="font-medium text-neutral-900">{{ t('payroll.month_guide.progress', { done: doneCount, total: countable.length }) }}</span>
        <span v-if="allDone" class="text-success-700" data-test="month-guide-all-done">{{ t('payroll.month_guide.all_done') }}</span>
      </div>

      <ol class="space-y-3">
        <li
          v-for="(step, index) in steps"
          :key="step.key"
          class="rounded-xl border bg-surface p-4 shadow-sm sm:p-5"
          :class="guide.current_step === step.key ? 'border-payroll-500/60 ring-1 ring-payroll-500/30' : 'border-neutral-200'"
          :data-test="`month-guide-step-${step.key}`"
          :data-state="step.state"
        >
          <div class="flex flex-wrap items-start gap-3">
            <div
              class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg"
              :class="step.state === 'done' ? 'bg-success-500/15 text-success-600' : 'bg-payroll-500/10 text-payroll-600'"
            >
              <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" :d="step.state === 'done' ? ICONS.check : STEP_ICONS[step.key]" />
              </svg>
            </div>
            <div class="min-w-0 flex-1">
              <div class="flex flex-wrap items-center gap-2">
                <h2 class="font-semibold text-neutral-900">{{ index + 1 }}. {{ t(`payroll.month_guide.steps.${step.key}.title`) }}</h2>
                <span
                  class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset"
                  :class="STATE_BADGE[step.state]"
                  :data-test="`month-guide-state-${step.key}`"
                >{{ stateLabel(step) }}</span>
                <span v-if="guide.current_step === step.key" class="text-xs font-semibold uppercase tracking-wide text-payroll-600">{{ t('payroll.month_guide.current') }}</span>
              </div>
              <p class="mt-1 text-sm text-neutral-500">{{ stepHint(step) }}</p>

              <ul v-if="step.facts?.codes?.length" class="mt-2 flex flex-wrap gap-1.5" :data-test="`month-guide-codes-${step.key}`">
                <li v-for="code in step.facts.codes" :key="code.code" class="rounded-md bg-neutral-100 px-2 py-0.5 text-xs text-neutral-700">
                  {{ codeLabel(code.code) }}: <span class="font-semibold tabular-nums">{{ code.count }}</span>
                </li>
              </ul>
              <ul v-if="step.key === 'check' && step.facts?.findings?.length" class="mt-2 space-y-1 text-sm text-danger-700" data-test="month-guide-check-findings">
                <li v-for="finding in step.facts.findings" :key="finding.code" class="break-words">
                  <span v-if="finding.count > 1" class="font-semibold">{{ finding.count }}× </span>{{ finding.message }}
                </li>
              </ul>
              <p v-for="(line, lineIndex) in factLines(step)" :key="lineIndex" class="mt-1 text-sm text-neutral-700 break-words" :data-test="`month-guide-fact-${step.key}`">{{ line }}</p>

              <div v-if="step.state !== 'unavailable'" class="mt-3 flex flex-wrap items-center gap-2">
                <RouterLink
                  v-if="primaryTarget(step)"
                  :to="primaryTarget(step)!"
                  :class="[step.state === 'todo' ? btnFilled('primary') : btnOutline('primary'), 'max-w-full']"
                  :data-test="`month-guide-action-${step.key}`"
                >
                  {{ primaryLabel(step) }}
                </RouterLink>
                <RouterLink
                  v-for="link in secondaryLinks(step)"
                  :key="link.key"
                  :to="link.to"
                  :class="[btnOutlineSm('neutral'), 'max-w-full whitespace-normal text-left']"
                  :data-test="`month-guide-link-${step.key}-${link.key}`"
                >
                  {{ link.label }}
                </RouterLink>
              </div>
            </div>
          </div>
        </li>
      </ol>
    </template>
  </div>
</template>

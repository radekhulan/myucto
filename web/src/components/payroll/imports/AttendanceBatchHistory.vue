<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { personalNumberLabel } from '@/pages/payroll/employmentLifecycleUi'
import {
  payrollImportsApi,
  type AttendanceBatch,
  type AttendanceBatchComparison,
  type AttendanceBatchComparisonRow,
  type AttendanceBatchRow,
} from '@/api/payrollImports'
import { payrollAttendanceApprovalApi, type AttendanceTimeApproval } from '@/api/payrollAttendanceApproval'
import { apiErrorMessage } from '@/api/errors'
import { useToast } from '@/composables/useToast'
import { formatDateTime, formatMoneyMinor, formatPeriodNumeric } from '@/composables/useFormat'
import { btnOutline, btnOutlineSm, ICONS } from '@/components/ui/buttonStyles'
import AttendanceTimeApprovalResult from './AttendanceTimeApprovalResult.vue'
import { formatHours } from './importHelpers'

const props = defineProps<{
  period: string
  /** Schválení pracovních měsíců (`payroll.approve`). */
  canApprove?: boolean
}>()

const { t, te, locale } = useI18n()
const toast = useToast()

// Dodatečné schválení čistých měsíců z už použité dávky, výsledek pod řádkem dávky.
const approving = ref<number | null>(null)
const approvals = ref<Record<number, AttendanceTimeApproval>>({})
const approveErrors = ref<Record<number, string>>({})

async function approve(batch: AttendanceBatch) {
  if (!props.canApprove || approving.value !== null) return
  approving.value = batch.id
  approveErrors.value = Object.fromEntries(
    Object.entries(approveErrors.value).filter(([id]) => Number(id) !== batch.id),
  )
  try {
    const approval = await payrollAttendanceApprovalApi.approveCleanTimeMonths(batch.id)
    approvals.value = { ...approvals.value, [batch.id]: approval }
    const params = { approved: approval.approved, exceptions: approval.exceptions.length }
    if (approval.exceptions.length) toast.warning(t('payroll_imports.attendance_time.approved_toast', params))
    else toast.success(t('payroll_imports.attendance_time.approved_toast', params))
  } catch (err) {
    approveErrors.value = { ...approveErrors.value, [batch.id]: apiErrorMessage(err, t('payroll_imports.attendance_time.approve_failed')) }
  } finally {
    approving.value = null
  }
}

// Kontrola proti mzdovému exportu v podkladech: hrubá a čistá z výpočtu běhu.
const comparing = ref<number | null>(null)
const comparisons = ref<Record<number, AttendanceBatchComparison>>({})
const compareErrors = ref<Record<number, string>>({})

async function compare(batch: AttendanceBatch) {
  if (comparing.value !== null) return
  comparing.value = batch.id
  compareErrors.value = Object.fromEntries(
    Object.entries(compareErrors.value).filter(([id]) => Number(id) !== batch.id),
  )
  try {
    comparisons.value = { ...comparisons.value, [batch.id]: await payrollImportsApi.attendanceBatchComparison(batch.id) }
  } catch (err) {
    compareErrors.value = { ...compareErrors.value, [batch.id]: apiErrorMessage(err, t('payroll_imports.attendance.history.compare_failed')) }
  } finally {
    comparing.value = null
  }
}

function money(minor: number | null): string {
  return minor === null ? '—' : formatMoneyMinor(minor)
}

function diffClass(diff: number | null): string {
  if (diff === null) return 'text-neutral-400'
  return Math.abs(diff) > 100 ? 'font-medium text-danger-600' : 'text-neutral-500'
}

function statusClass(status: AttendanceBatchComparisonRow['status']): string {
  return { match: 'bg-success-50 text-success-700', diff: 'bg-danger-50 text-danger-600', missing: 'bg-warning-50 text-warning-700' }[status]
}

const batches = ref<AttendanceBatch[]>([])
const loading = ref(false)
const error = ref('')
const onlyPeriod = ref(false)
const expanded = ref<number | null>(null)
const detail = ref<Record<number, AttendanceBatchRow[]>>({})
const detailLoading = ref<number | null>(null)
const detailError = ref('')

async function reload() {
  loading.value = true
  error.value = ''
  try {
    batches.value = await payrollImportsApi.attendanceBatches(onlyPeriod.value ? props.period : null)
  } catch (err) {
    error.value = apiErrorMessage(err, t('payroll_imports.attendance.history.load_failed'))
  } finally {
    loading.value = false
  }
}

async function toggle(batch: AttendanceBatch) {
  if (expanded.value === batch.id) {
    expanded.value = null
    return
  }
  expanded.value = batch.id
  detailError.value = ''
  if (detail.value[batch.id]) return
  detailLoading.value = batch.id
  try {
    const response = await payrollImportsApi.attendanceBatch(batch.id)
    detail.value[batch.id] = response.rows
  } catch (err) {
    detailError.value = apiErrorMessage(err, t('payroll_imports.attendance.history.detail_failed'))
  } finally {
    detailLoading.value = null
  }
}

function meaningLabel(meaning: string): string {
  const key = `payroll_imports.meanings.${meaning}`
  return te(key) ? t(key) : meaning
}

watch(onlyPeriod, () => { void reload() })
watch(() => props.period, () => { if (onlyPeriod.value) void reload() })

onMounted(reload)
defineExpose({ reload })
</script>

<template>
  <section class="rounded-xl border border-neutral-200 bg-surface shadow-sm" data-testid="attendance-history">
    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-neutral-100 px-4 py-3">
      <div>
        <h3 class="font-semibold text-neutral-900">{{ t('payroll_imports.attendance.history.title') }}</h3>
        <p class="text-xs text-neutral-500">{{ t('payroll_imports.attendance.history.hint') }}</p>
      </div>
      <div class="flex flex-wrap items-center gap-3">
        <label class="inline-flex items-center gap-2 text-sm text-neutral-700">
          <input v-model="onlyPeriod" type="checkbox" class="rounded border-neutral-300 text-payroll-600">
          {{ t('payroll_imports.attendance.history.only_period', { period: formatPeriodNumeric(period) }) }}
        </label>
        <button type="button" :class="btnOutline('neutral')" :disabled="loading" @click="reload">
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.cycle" /></svg>
          {{ t('payroll_imports.common.reload') }}
        </button>
      </div>
    </div>

    <p v-if="error" role="alert" class="m-4 rounded-lg border border-danger-500/30 bg-danger-50 px-4 py-3 text-sm text-danger-700">{{ error }}</p>
    <div v-else-if="loading && batches.length === 0" class="space-y-2 p-4">
      <div v-for="index in 3" :key="index" class="h-10 animate-pulse rounded-lg bg-neutral-100" />
    </div>
    <p v-else-if="batches.length === 0" class="px-4 py-6 text-sm text-neutral-500">{{ t('payroll_imports.attendance.history.empty') }}</p>

    <ul v-else class="divide-y divide-neutral-100">
      <li v-for="batch in batches" :key="batch.id">
        <div class="flex flex-wrap items-center justify-between gap-3 px-4 py-3 text-sm">
          <div class="min-w-0">
            <p class="font-medium text-neutral-900">
              {{ t('payroll_imports.attendance.history.batch_title', { id: batch.id, period: formatPeriodNumeric(batch.period) }) }}
            </p>
            <p class="text-xs text-neutral-500">
              {{ formatDateTime(batch.created_at) }}<template v-if="batch.created_by_name"> · {{ batch.created_by_name }}</template>
            </p>
            <p class="truncate text-xs text-neutral-400" :title="batch.files.map(file => file.name).join(', ')">{{ batch.files.map(file => file.name).join(', ') }}</p>
          </div>
          <div class="flex flex-wrap items-center gap-2">
            <span class="rounded-full bg-neutral-100 px-2 py-0.5 text-xs text-neutral-600">{{ t('payroll_imports.attendance.history.persons', { count: batch.person_count }) }}</span>
            <span class="rounded-full bg-neutral-100 px-2 py-0.5 text-xs text-neutral-600">{{ t('payroll_imports.attendance.history.metrics', { count: batch.metric_count }) }}</span>
            <span class="rounded-full bg-payroll-50 px-2 py-0.5 text-xs text-payroll-700">{{ t('payroll_imports.attendance.history.inputs', { count: batch.input_count }) }}</span>
            <button v-if="canApprove" type="button" :class="btnOutlineSm('success')" :disabled="approving !== null"
              :title="t('payroll_imports.attendance_time.approve_hint')" data-testid="attendance-history-approve-time" @click="approve(batch)">
              <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.checkCircle" /></svg>
              {{ approving === batch.id ? t('payroll_imports.attendance_time.approving') : t('payroll_imports.attendance_time.approve') }}
            </button>
            <button type="button" :class="btnOutlineSm('primary')" :disabled="comparing !== null"
              :title="t('payroll_imports.attendance.history.compare_hint')" data-testid="attendance-history-compare" @click="compare(batch)">
              <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.chart" /></svg>
              {{ comparing === batch.id ? t('payroll_imports.common.working') : t('payroll_imports.attendance.history.compare') }}
            </button>
            <button type="button" :class="btnOutlineSm('neutral')" :aria-expanded="expanded === batch.id" @click="toggle(batch)">
              <svg class="h-3.5 w-3.5 transition-transform" :class="expanded === batch.id ? 'rotate-180' : ''" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.chevron" /></svg>
              {{ t(expanded === batch.id ? 'payroll_imports.attendance.history.hide' : 'payroll_imports.attendance.history.show') }}
            </button>
          </div>
        </div>

        <div v-if="approveErrors[batch.id] || approvals[batch.id]" class="px-4 pb-3" data-testid="attendance-history-approval">
          <p v-if="approveErrors[batch.id]" role="alert" class="rounded-lg border border-danger-500/30 bg-danger-50 px-3 py-2 text-sm text-danger-700">{{ approveErrors[batch.id] }}</p>
          <AttendanceTimeApprovalResult v-else-if="approvals[batch.id]" :approval="approvals[batch.id]" :period="batch.period" />
        </div>

        <div v-if="compareErrors[batch.id] || comparisons[batch.id]" class="px-4 pb-3" data-testid="attendance-history-comparison">
          <p v-if="compareErrors[batch.id]" role="alert" class="rounded-lg border border-danger-500/30 bg-danger-50 px-3 py-2 text-sm text-danger-700">{{ compareErrors[batch.id] }}</p>
          <template v-else-if="comparisons[batch.id]">
            <p v-if="comparisons[batch.id].rows.length === 0" class="text-sm text-neutral-500">{{ t('payroll_imports.attendance.history.compare_no_reference') }}</p>
            <template v-else>
              <p v-if="comparisons[batch.id].summary.missing === comparisons[batch.id].rows.length" class="mb-2 text-sm text-warning-700">{{ t('payroll_imports.attendance.history.compare_no_run') }}</p>
              <p class="mb-2 text-xs text-neutral-600">{{ t('payroll_imports.attendance.history.compare_summary', comparisons[batch.id].summary) }}</p>
              <div class="max-h-96 overflow-auto rounded-lg border border-neutral-200 bg-surface">
                <table class="min-w-full divide-y divide-neutral-200 text-sm">
                  <thead>
                    <tr class="text-left text-xs uppercase tracking-wide text-neutral-500">
                      <th class="sticky top-0 bg-surface px-3 py-2">{{ t('payroll_imports.attendance.history.columns.employee') }}</th>
                      <th class="sticky top-0 bg-surface px-3 py-2 text-right">{{ t('payroll_imports.attendance.history.compare_columns.reference_gross') }}</th>
                      <th class="sticky top-0 bg-surface px-3 py-2 text-right">{{ t('payroll_imports.attendance.history.compare_columns.computed_gross') }}</th>
                      <th class="sticky top-0 bg-surface px-3 py-2 text-right">{{ t('payroll_imports.attendance.history.compare_columns.diff') }}</th>
                      <th class="sticky top-0 bg-surface px-3 py-2 text-right">{{ t('payroll_imports.attendance.history.compare_columns.reference_net') }}</th>
                      <th class="sticky top-0 bg-surface px-3 py-2 text-right">{{ t('payroll_imports.attendance.history.compare_columns.computed_net') }}</th>
                      <th class="sticky top-0 bg-surface px-3 py-2 text-right">{{ t('payroll_imports.attendance.history.compare_columns.diff') }}</th>
                    </tr>
                  </thead>
                  <tbody class="divide-y divide-neutral-100">
                    <tr v-for="row in comparisons[batch.id].rows" :key="row.employment_id">
                      <td class="px-3 py-1.5">
                        <span class="font-medium">{{ row.employee_name }}</span> <span class="text-xs text-neutral-500">{{ personalNumberLabel(t, row.employment_code) }}</span>
                        <span class="ml-1 whitespace-nowrap rounded-full px-2 py-0.5 text-[11px] font-medium" :class="statusClass(row.status)">{{ t(`payroll_imports.attendance.history.compare_status.${row.status}`) }}</span>
                      </td>
                      <td class="whitespace-nowrap px-3 py-1.5 text-right tabular-nums text-neutral-600">{{ money(row.reference_gross_minor) }}</td>
                      <td class="whitespace-nowrap px-3 py-1.5 text-right tabular-nums">{{ money(row.computed_gross_minor) }}</td>
                      <td class="whitespace-nowrap px-3 py-1.5 text-right tabular-nums" :class="diffClass(row.gross_diff_minor)">{{ money(row.gross_diff_minor) }}</td>
                      <td class="whitespace-nowrap px-3 py-1.5 text-right tabular-nums text-neutral-600">{{ money(row.reference_net_minor) }}</td>
                      <td class="whitespace-nowrap px-3 py-1.5 text-right tabular-nums">
                        <span v-if="row.net_shared" class="text-xs text-neutral-500">{{ t('payroll_imports.attendance.history.compare_net_shared') }}</span>
                        <template v-else>{{ money(row.computed_net_minor) }}</template>
                      </td>
                      <td class="whitespace-nowrap px-3 py-1.5 text-right tabular-nums" :class="diffClass(row.net_diff_minor)">{{ money(row.net_diff_minor) }}</td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </template>
          </template>
        </div>

        <div v-if="expanded === batch.id" class="border-t border-neutral-100 bg-neutral-50/60 px-4 py-3">
          <p v-if="detailLoading === batch.id" class="text-sm text-neutral-500">{{ t('payroll_imports.common.working') }}</p>
          <p v-else-if="detailError" role="alert" class="rounded-lg border border-danger-500/30 bg-danger-50 px-3 py-2 text-sm text-danger-700">{{ detailError }}</p>
          <p v-else-if="(detail[batch.id] ?? []).length === 0" class="text-sm text-neutral-500">{{ t('payroll_imports.attendance.history.no_rows') }}</p>
          <div v-else class="max-h-96 overflow-auto rounded-lg border border-neutral-200 bg-surface">
            <table class="min-w-full divide-y divide-neutral-200 text-sm">
              <thead>
                <tr class="text-left text-xs uppercase tracking-wide text-neutral-500">
                  <th class="sticky top-0 bg-surface px-3 py-2">{{ t('payroll_imports.attendance.history.columns.employee') }}</th>
                  <th class="sticky top-0 bg-surface px-3 py-2">{{ t('payroll_imports.attendance.history.columns.meaning') }}</th>
                  <th class="sticky top-0 bg-surface px-3 py-2 text-right">{{ t('payroll_imports.attendance.history.columns.value') }}</th>
                  <th class="sticky top-0 bg-surface px-3 py-2">{{ t('payroll_imports.attendance.history.columns.source') }}</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-neutral-100">
                <tr v-for="row in detail[batch.id]" :key="`${row.employment_id}-${row.meaning}`">
                  <td class="px-3 py-1.5"><span class="font-medium">{{ row.employee_name }}</span> <span class="text-xs text-neutral-500">{{ personalNumberLabel(t, row.employment_code) }}</span></td>
                  <td class="px-3 py-1.5">{{ meaningLabel(row.meaning) }}</td>
                  <td class="whitespace-nowrap px-3 py-1.5 text-right tabular-nums">{{ row.meaning === 'worked_days' && row.hours !== null ? Math.round(Number(row.hours)) : row.hours !== null ? formatHours(row.hours, locale) : formatMoneyMinor(row.amount_minor) }}</td>
                  <td class="max-w-64 truncate px-3 py-1.5 font-mono text-xs text-neutral-500" :title="row.source">{{ row.source }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </li>
    </ul>
  </section>
</template>

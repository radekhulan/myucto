<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { payrollApi, type PayrollOperationalHealth } from '@/api/payroll'

const { t, locale } = useI18n()
const health = ref<PayrollOperationalHealth | null>(null)
const unavailable = ref(false)

async function load() {
  try {
    health.value = await payrollApi.operationalHealth()
    unavailable.value = false
  } catch {
    health.value = null
    unavailable.value = true
  }
}

function formatAge(seconds: number | null): string {
  if (seconds === null) return t('payroll.dashboard.operational_health.never_pending')
  if (seconds < 3_600) {
    return t('payroll.dashboard.operational_health.age_minutes', {
      count: Math.max(1, Math.floor(seconds / 60)),
    })
  }
  if (seconds < 86_400) {
    return t('payroll.dashboard.operational_health.age_hours', {
      count: Math.floor(seconds / 3_600),
    })
  }
  return t('payroll.dashboard.operational_health.age_days', {
    count: Math.floor(seconds / 86_400),
  })
}

function formatCompletedAt(value: string | null): string {
  if (!value) return t('payroll.dashboard.operational_health.never_completed')
  return new Intl.DateTimeFormat(locale.value, {
    dateStyle: 'short',
    timeStyle: 'short',
  }).format(new Date(value))
}

function formatBytes(value: number | null): string {
  if (value === null) return t('payroll.dashboard.operational_health.archive_measurement_failed')
  const units = ['B', 'KiB', 'MiB', 'GiB', 'TiB']
  let amount = value
  let unit = 0
  while (amount >= 1024 && unit < units.length - 1) {
    amount /= 1024
    unit += 1
  }
  return new Intl.NumberFormat(locale.value, {
    maximumFractionDigits: amount >= 10 || unit === 0 ? 0 : 1,
  }).format(amount) + ' ' + units[unit]
}

onMounted(load)
</script>

<template>
  <section
    v-if="health"
    class="rounded-xl border border-neutral-200 bg-surface p-4 shadow-sm sm:p-6"
    data-test="operational-health"
  >
    <div>
      <h2 class="text-lg font-semibold text-neutral-900">
        {{ t('payroll.dashboard.operational_health.title') }}
      </h2>
      <p class="mt-1 text-sm text-neutral-500">
        {{ t('payroll.dashboard.operational_health.description') }}
      </p>
    </div>

    <div class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-7">
      <div class="rounded-lg bg-neutral-50 p-3">
        <h3 class="text-sm font-medium text-neutral-800">
          {{ t('payroll.dashboard.operational_health.document_batches') }}
        </h3>
        <dl class="mt-2 grid grid-cols-2 gap-x-3 gap-y-1 text-sm">
          <dt>{{ t('payroll.dashboard.operational_health.queued') }}</dt>
          <dd class="text-right font-semibold" data-test="document-queued">{{ health.document_batches.queued }}</dd>
          <dt>{{ t('payroll.dashboard.operational_health.running') }}</dt>
          <dd class="text-right font-semibold">{{ health.document_batches.running }}</dd>
          <dt>{{ t('payroll.dashboard.operational_health.retry_wait') }}</dt>
          <dd class="text-right font-semibold">{{ health.document_batches.retry_wait }}</dd>
          <dt>{{ t('payroll.dashboard.operational_health.failed') }}</dt>
          <dd class="text-right font-semibold text-danger-700" data-test="document-failed">{{ health.document_batches.failed }}</dd>
        </dl>
        <dl class="mt-3 space-y-1 border-t border-neutral-200 pt-2 text-xs text-neutral-600">
          <div class="flex items-start justify-between gap-2">
            <dt>{{ t('payroll.dashboard.operational_health.oldest_pending') }}</dt>
            <dd class="text-right font-medium text-neutral-800" data-test="document-oldest-age">
              {{ formatAge(health.document_batches.oldest_pending_age_seconds) }}
            </dd>
          </div>
          <div class="flex items-start justify-between gap-2">
            <dt>{{ t('payroll.dashboard.operational_health.last_completed') }}</dt>
            <dd class="text-right font-medium text-neutral-800" data-test="document-last-completed">
              {{ formatCompletedAt(health.document_batches.last_completed_at) }}
            </dd>
          </div>
        </dl>
      </div>

      <div class="rounded-lg bg-neutral-50 p-3">
        <h3 class="text-sm font-medium text-neutral-800">
          {{ t('payroll.dashboard.operational_health.period_exports') }}
        </h3>
        <dl class="mt-2 grid grid-cols-2 gap-x-3 gap-y-1 text-sm">
          <dt>{{ t('payroll.dashboard.operational_health.queued') }}</dt>
          <dd class="text-right font-semibold" data-test="period-export-queued">{{ health.period_export_jobs.queued }}</dd>
          <dt>{{ t('payroll.dashboard.operational_health.processing') }}</dt>
          <dd class="text-right font-semibold">{{ health.period_export_jobs.processing }}</dd>
          <dt>{{ t('payroll.dashboard.operational_health.retry_wait') }}</dt>
          <dd class="text-right font-semibold">{{ health.period_export_jobs.retry_wait }}</dd>
          <dt>{{ t('payroll.dashboard.operational_health.failed') }}</dt>
          <dd class="text-right font-semibold text-danger-700" data-test="period-export-failed">{{ health.period_export_jobs.failed }}</dd>
        </dl>
        <dl class="mt-3 space-y-1 border-t border-neutral-200 pt-2 text-xs text-neutral-600">
          <div class="flex items-start justify-between gap-2">
            <dt>{{ t('payroll.dashboard.operational_health.oldest_pending') }}</dt>
            <dd class="text-right font-medium text-neutral-800" data-test="period-export-oldest-age">
              {{ formatAge(health.period_export_jobs.oldest_pending_age_seconds) }}
            </dd>
          </div>
          <div class="flex items-start justify-between gap-2">
            <dt>{{ t('payroll.dashboard.operational_health.last_completed') }}</dt>
            <dd class="text-right font-medium text-neutral-800" data-test="period-export-last-completed">
              {{ formatCompletedAt(health.period_export_jobs.last_completed_at) }}
            </dd>
          </div>
        </dl>
      </div>

      <div class="rounded-lg bg-neutral-50 p-3">
        <h3 class="text-sm font-medium text-neutral-800">
          {{ t('payroll.dashboard.operational_health.submissions') }}
        </h3>
        <dl class="mt-2 grid grid-cols-2 gap-x-3 gap-y-1 text-sm">
          <dt>{{ t('payroll.dashboard.operational_health.rejected') }}</dt>
          <dd class="text-right font-semibold text-danger-700" data-test="submission-rejected">{{ health.submissions.rejected }}</dd>
          <dt>{{ t('payroll.dashboard.operational_health.correction_required') }}</dt>
          <dd class="text-right font-semibold text-warning-700">{{ health.submissions.correction_required }}</dd>
          <dt>{{ t('payroll.dashboard.operational_health.open_issues') }}</dt>
          <dd class="text-right font-semibold text-danger-700" data-test="submission-issues">{{ health.submissions.open_blocker_or_error_issues }}</dd>
        </dl>
      </div>

      <div class="rounded-lg bg-neutral-50 p-3">
        <h3 class="text-sm font-medium text-neutral-800">
          {{ t('payroll.dashboard.operational_health.isds_outbox') }}
        </h3>
        <dl class="mt-2 grid grid-cols-2 gap-x-3 gap-y-1 text-sm">
          <dt>{{ t('payroll.dashboard.operational_health.failed') }}</dt>
          <dd class="text-right font-semibold text-danger-700">{{ health.isds_outbox.failed }}</dd>
          <dt>{{ t('payroll.dashboard.operational_health.send_uncertain') }}</dt>
          <dd class="text-right font-semibold text-warning-700" data-test="outbox-uncertain">{{ health.isds_outbox.send_uncertain }}</dd>
          <dt>{{ t('payroll.dashboard.operational_health.rejected') }}</dt>
          <dd class="text-right font-semibold text-danger-700">{{ health.isds_outbox.rejected }}</dd>
        </dl>
      </div>

      <div
        class="rounded-lg p-3"
        :class="health.reconciliation.open > 0 ? 'bg-warning-50' : 'bg-success-50'"
        data-test="reconciliation-card"
      >
        <h3 class="text-sm font-medium text-neutral-800">
          {{ t('payroll.dashboard.operational_health.reconciliation') }}
        </h3>
        <dl class="mt-2 grid grid-cols-2 gap-x-3 gap-y-1 text-sm">
          <dt>{{ t('payroll.dashboard.operational_health.reconciliation_open') }}</dt>
          <dd
            class="text-right font-semibold"
            :class="health.reconciliation.open > 0 ? 'text-warning-800' : 'text-success-800'"
            data-test="reconciliation-open"
          >
            {{ health.reconciliation.open }}
          </dd>
          <dt>{{ t('payroll.dashboard.operational_health.reconciliation_diff') }}</dt>
          <dd class="text-right font-semibold text-warning-800" data-test="reconciliation-diff">
            {{ health.reconciliation.diff }}
          </dd>
          <dt>{{ t('payroll.dashboard.operational_health.reconciliation_blocked') }}</dt>
          <dd class="text-right font-semibold text-danger-700" data-test="reconciliation-blocked">
            {{ health.reconciliation.blocked }}
          </dd>
          <dt>{{ t('payroll.dashboard.operational_health.reconciliation_not_materialized') }}</dt>
          <dd class="text-right font-semibold">{{ health.reconciliation.not_materialized }}</dd>
          <dt>{{ t('payroll.dashboard.operational_health.reconciliation_periods') }}</dt>
          <dd class="text-right font-semibold">{{ health.reconciliation.periods }}</dd>
        </dl>
      </div>

      <div
        class="rounded-lg p-3"
        :class="health.archive_capacity.measured ? 'bg-neutral-50' : 'bg-warning-50'"
        data-test="archive-capacity-card"
      >
        <h3 class="text-sm font-medium text-neutral-800">
          {{ t('payroll.dashboard.operational_health.archive_capacity') }}
        </h3>
        <p class="mt-2 text-2xl font-semibold text-neutral-900" data-test="archive-capacity-bytes">
          {{ formatBytes(health.archive_capacity.content_bytes) }}
        </p>
        <p
          v-if="health.archive_capacity.measured"
          class="mt-1 text-sm text-neutral-600"
          data-test="archive-capacity-objects"
        >
          {{ t('payroll.dashboard.operational_health.archive_objects', { count: health.archive_capacity.object_count }) }}
        </p>
        <p class="mt-2 text-xs text-neutral-500">
          {{ t('payroll.dashboard.operational_health.archive_capacity_hint') }}
        </p>
      </div>

      <div
        class="rounded-lg p-3"
        :class="health.overdue_unpaid_liabilities > 0 ? 'bg-warning-50' : 'bg-success-50'"
        data-test="liabilities-card"
      >
        <h3 class="text-sm font-medium text-neutral-800">
          {{ t('payroll.dashboard.operational_health.liabilities') }}
        </h3>
        <p class="mt-2 text-sm text-neutral-600">
          {{ t('payroll.dashboard.operational_health.overdue_unpaid') }}
        </p>
        <p
          class="mt-1 text-2xl font-semibold"
          :class="health.overdue_unpaid_liabilities > 0 ? 'text-warning-800' : 'text-success-800'"
          data-test="liabilities-overdue"
        >
          {{ health.overdue_unpaid_liabilities }}
        </p>
      </div>
    </div>
  </section>

  <section
    v-else-if="unavailable"
    class="rounded-xl border border-warning-200 bg-warning-50 p-4 shadow-sm sm:p-6"
    data-test="operational-health-unavailable"
  >
    <div class="flex items-center justify-between gap-4">
      <div class="flex items-center gap-3 text-warning-900">
        <svg class="h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
          <path d="M12 9v4m0 4h.01M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z" />
        </svg>
        <p class="text-sm font-medium">
          {{ t('payroll.dashboard.operational_health.unavailable') }}
        </p>
      </div>
      <button type="button" class="cursor-pointer shrink-0" data-test="operational-health-retry" @click="load">
        {{ t('payroll.dashboard.operational_health.retry') }}
      </button>
    </div>
  </section>
</template>

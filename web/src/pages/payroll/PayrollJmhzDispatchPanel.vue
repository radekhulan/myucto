<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { apiErrorMessage } from '@/api/errors'
import { dataBoxApi, type GatewayStart } from '@/api/dataBox'
import {
  payrollApi,
  type PayrollJmhzIsdsEnqueueResult,
  type PayrollJmhzPvpojPreview,
  type PayrollJmhzTransportPoll,
  type PayrollRegzelEnvironment,
  type PayrollSubmissionOverviewItem,
} from '@/api/payroll'
import { btnFilled, btnOutline, ICONS } from '@/components/ui/buttonStyles'
import { useAuthStore } from '@/stores/auth'

const props = defineProps<{
  environment: PayrollRegzelEnvironment
  previews: PayrollJmhzPvpojPreview[]
  obligations: PayrollSubmissionOverviewItem[]
}>()

const emit = defineEmits<{ refresh: [] }>()
const { t } = useI18n()
const auth = useAuthStore()
const canWrite = computed(() => auth.canWrite('payroll.submissions'))

interface DispatchState {
  busy: 'isds' | 'vrep' | null
  error: string
  isds: PayrollJmhzIsdsEnqueueResult | null
  vrep: PayrollJmhzTransportPoll | null
  gateway: GatewayStart | null
}

const states = ref<Record<string, DispatchState>>({})

function key(preview: PayrollJmhzPvpojPreview): string {
  return `${preview.revision_id}:${preview.office.office_id}`
}

function state(preview: PayrollJmhzPvpojPreview): DispatchState {
  return states.value[key(preview)] ?? {
    busy: null,
    error: '',
    isds: null,
    vrep: null,
    gateway: null,
  }
}

function setState(preview: PayrollJmhzPvpojPreview, next: DispatchState) {
  states.value = { ...states.value, [key(preview)]: next }
}

function obligation(preview: PayrollJmhzPvpojPreview): PayrollSubmissionOverviewItem | null {
  const officeReference = `payroll_run:${preview.run_id}:office:${preview.office.office_id}`
  const runReference = `payroll_run:${preview.run_id}`

  return props.obligations.find(item =>
    item.agenda_code.toUpperCase().startsWith('JMHZ')
      && item.subject_reference === officeReference,
  ) ?? props.obligations.find(item =>
    item.agenda_code.toUpperCase().startsWith('JMHZ')
      && item.subject_reference === runReference,
  ) ?? null
}

function unavailableReason(preview: PayrollJmhzPvpojPreview): string | null {
  const item = obligation(preview)
  if (!item) return null
  if (props.environment === 'production' && item.deadline.phase === 'not_open') {
    return t('payroll.submissions.overview.jmhz_dispatch_not_open', {
      date: item.earliest_submission_on,
    })
  }
  if (item.latest_submission && item.latest_submission.status !== 'ready') {
    return t('payroll.submissions.overview.jmhz_dispatch_already_started', {
      status: item.latest_submission.status,
    })
  }

  return null
}

async function submissionId(preview: PayrollJmhzPvpojPreview): Promise<number> {
  const item = obligation(preview)
  if (item?.latest_submission?.status === 'ready') return item.latest_submission.id

  const preparation = await payrollApi.freezeJmhzPreparation(
    preview.revision_id,
    crypto.randomUUID(),
    props.environment,
  )
  const frozen = await payrollApi.freezeJmhzSubmission(
    preparation.id,
    item?.id ?? null,
    props.environment,
    preview.office.office_id,
  )

  return frozen.submission_id
}

async function dispatch(preview: PayrollJmhzPvpojPreview, channel: 'isds' | 'vrep') {
  if (!canWrite.value || unavailableReason(preview)) return
  const current = state(preview)
  setState(preview, { ...current, busy: channel, error: '', gateway: null })

  try {
    const id = await submissionId(preview)
    if (channel === 'vrep') {
      const result = await payrollApi.sendJmhzTransport(
        id,
        preview.office.variable_symbol,
        props.environment,
        crypto.randomUUID(),
      )
      setState(preview, { ...state(preview), busy: null, vrep: result })
      emit('refresh')
      return
    }

    const queued = await payrollApi.enqueueJmhzIsds(id, props.environment)
    setState(preview, { ...state(preview), busy: null, isds: queued })
    if (queued.transport.automatic) {
      try {
        const gateway = await dataBoxApi.gatewayStartPayroll(queued.outbox_id)
        setState(preview, { ...state(preview), gateway })
      } catch (exception) {
        setState(preview, {
          ...state(preview),
          error: apiErrorMessage(
            exception,
            t('payroll.submissions.overview.jmhz_gateway_start_failed'),
          ),
        })
      }
    }
    emit('refresh')
  } catch (exception) {
    setState(preview, {
      ...state(preview),
      busy: null,
      error: apiErrorMessage(
        exception,
        t('payroll.submissions.overview.jmhz_dispatch_failed'),
      ),
    })
  }
}

function continueGateway(preview: PayrollJmhzPvpojPreview) {
  const gateway = state(preview).gateway
  if (gateway) window.location.assign(gateway.redirect_url)
}
</script>

<template>
  <section
    class="overflow-hidden rounded-xl border border-neutral-200 bg-surface shadow-sm"
    data-test="jmhz-dispatch"
  >
    <div class="border-b border-neutral-200 p-4 sm:p-6">
      <h2 class="text-lg font-semibold text-neutral-900">
        {{ t('payroll.submissions.overview.jmhz_dispatch_title') }}
      </h2>
      <p class="mt-1 text-sm text-neutral-500">
        {{ t('payroll.submissions.overview.jmhz_dispatch_description') }}
      </p>
    </div>

    <p v-if="previews.length === 0" class="p-6 text-sm text-neutral-500">
      {{ t('payroll.submissions.overview.jmhz_dispatch_empty') }}
    </p>

    <div v-else class="space-y-4 p-4">
      <article
        v-for="preview in previews"
        :key="key(preview)"
        class="rounded-lg border border-neutral-200 p-4"
        :data-test="`jmhz-dispatch-${key(preview)}`"
      >
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div>
            <h3 class="font-semibold text-neutral-900">
              {{ preview.office.code }} · {{ preview.office.name }}
            </h3>
            <p class="mt-1 text-xs text-neutral-500">
              {{ t('payroll.submissions.overview.jmhz_dispatch_reference', {
                period: preview.period,
                symbol: preview.office.variable_symbol,
              }) }}
            </p>
          </div>
          <div class="flex flex-wrap gap-2">
            <button
              type="button"
              :class="btnFilled('primary')"
              :disabled="!canWrite || state(preview).busy !== null
                || state(preview).isds !== null || state(preview).vrep !== null
                || unavailableReason(preview) !== null"
              :data-test="`jmhz-dispatch-isds-${key(preview)}`"
              @click="dispatch(preview, 'isds')"
            >
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path :d="ICONS.send" />
              </svg>
              {{ state(preview).busy === 'isds'
                ? t('common.loading')
                : t('payroll.submissions.overview.jmhz_dispatch_isds') }}
            </button>
            <button
              type="button"
              :class="btnOutline('neutral')"
              :disabled="!canWrite || state(preview).busy !== null
                || state(preview).isds !== null || state(preview).vrep !== null
                || unavailableReason(preview) !== null"
              :data-test="`jmhz-dispatch-vrep-${key(preview)}`"
              @click="dispatch(preview, 'vrep')"
            >
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path :d="ICONS.send" />
              </svg>
              {{ state(preview).busy === 'vrep'
                ? t('common.loading')
                : t('payroll.submissions.overview.jmhz_dispatch_vrep') }}
            </button>
          </div>
        </div>

        <p
          v-if="unavailableReason(preview)"
          class="mt-3 rounded-lg border border-warning-500/30 bg-warning-50 p-3 text-sm text-warning-700"
        >
          {{ unavailableReason(preview) }}
        </p>
        <p
          v-if="state(preview).error"
          class="mt-3 rounded-lg border border-danger-500/30 bg-danger-50 p-3 text-sm text-danger-700"
          role="alert"
        >
          {{ state(preview).error }}
        </p>

        <div
          v-if="state(preview).vrep"
          class="mt-3 rounded-lg border border-success-500/30 bg-success-50 p-3 text-sm text-success-700"
          data-test="jmhz-dispatch-vrep-result"
        >
          {{ t('payroll.submissions.overview.jmhz_dispatch_vrep_started', {
            id: state(preview).vrep!.attempt.id,
            status: state(preview).vrep!.attempt.status,
          }) }}
        </div>

        <div
          v-if="state(preview).isds"
          class="mt-3 rounded-lg border border-success-500/30 bg-success-50 p-3 text-sm text-success-800"
          data-test="jmhz-dispatch-isds-result"
        >
          <p class="font-medium">
            {{ t('payroll.submissions.overview.jmhz_dispatch_isds_queued', {
              box: state(preview).isds!.recipient.box_id,
              name: state(preview).isds!.recipient.name,
            }) }}
          </p>
          <p class="mt-1 text-xs">
            {{ state(preview).isds!.attachment.filename }} · {{ state(preview).isds!.subject }}
          </p>
          <p v-if="!state(preview).isds!.transport.automatic" class="mt-2 text-xs">
            {{ t('payroll.submissions.overview.jmhz_dispatch_manual') }}
          </p>
          <a
            href="/admin/databox?tab=outbox"
            :class="[btnOutline('neutral'), 'mt-3 inline-flex']"
          >
            {{ t('payroll.submissions.overview.jmhz_dispatch_open_outbox') }}
          </a>
        </div>

        <div
          v-if="state(preview).gateway"
          class="mt-3 rounded-lg border border-primary-200 bg-primary-50 p-3 text-sm text-primary-900"
          data-test="jmhz-dispatch-gateway"
        >
          <p class="font-medium">
            {{ t('payroll.submissions.overview.jmhz_gateway_title') }}
          </p>
          <p class="mt-1">{{ state(preview).gateway!.login_guidance }}</p>
          <p class="mt-2 text-xs">
            {{ t('payroll.submissions.overview.jmhz_gateway_credentials') }}
          </p>
          <p v-if="!state(preview).gateway!.login_policy_documented" class="mt-2 text-xs text-warning-800">
            {{ t('payroll.submissions.overview.jmhz_gateway_methods') }}
          </p>
          <button
            type="button"
            :class="[btnFilled('primary'), 'mt-3']"
            @click="continueGateway(preview)"
          >
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
              <path :d="ICONS.send" />
            </svg>
            {{ t('payroll.submissions.overview.jmhz_gateway_continue') }}
          </button>
        </div>
      </article>
    </div>
  </section>
</template>

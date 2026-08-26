<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { apiErrorMessage } from '@/api/errors'
import {
  payrollApi,
  type PayrollJmhzTransportAttempt,
  type PayrollJmhzTransportPoll,
  type PayrollJmhzTransportEnvironment,
  type PayrollRegistrationPreview,
  type PayrollRegistrationSubmission,
} from '@/api/payroll'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import { btnFilled, btnOutline, ICONS } from '@/components/ui/buttonStyles'
// Formátování je sdílené (useFormat) — místní kopie se rozcházely v locale i tvaru.
import { formatDate } from '@/composables/useFormat'

const props = defineProps<{
  employmentId: number
  canWrite: boolean
}>()

const { t } = useI18n()
const busy = ref(false)
const error = ref('')
const preview = ref<PayrollRegistrationPreview | null>(null)
const submission = ref<PayrollRegistrationSubmission | null>(null)
const showXml = ref(false)
const environment = ref<PayrollJmhzTransportEnvironment>('test')
const transport = ref<PayrollJmhzTransportPoll | null>(null)
const transportBusy = ref<'send' | 'poll' | 'close' | null>(null)
const transportMessage = ref('')

/**
 * Jedna plná primární akce podle stavu: dokud není náhled, je hlavní krok
 * „zjistit, co se podá"; potom „připravit podání". Dvě plná tlačítka vedle
 * sebe by nutila uživatele hádat, které z nich je to úřední.
 */
const primaryAction = computed<'preview' | 'prepare' | 'done'>(() => {
  if (submission.value) return 'done'
  return preview.value ? 'prepare' : 'preview'
})

const agendaLabel = computed(() => {
  const agenda = submission.value?.agenda_code ?? preview.value?.agenda_code
  return agenda ? t(`payroll.people.registration.agenda.${agenda}`) : ''
})

const interactionLabel = computed(() => {
  const key = submission.value?.interaction ?? preview.value?.interaction
  return key ? t(`payroll.people.registration.interaction.${key}`) : ''
})

const deadline = computed(
  () => submission.value?.deadline ?? preview.value?.deadline ?? null,
)

const transportAttempt = computed<PayrollJmhzTransportAttempt | null>(
  () => transport.value?.attempt ?? null,
)

const canPoll = computed(
  () => transportAttempt.value?.status === 'awaiting_protocol',
)

const canClose = computed(
  () => transport.value?.settled === true
    && transportAttempt.value?.closed_at == null,
)

const canSend = computed(() => transportAttempt.value === null || [
  'prepared',
  'failed',
  'expired',
].includes(transportAttempt.value.status))

const transportActions = computed<ActionItem[]>(() => {
  if (!submission.value) return []

  return [
    {
      key: 'registration-send',
      label: t(`payroll.people.registration.send_${environment.value}`),
      icon: 'send',
      tier: 'primary',
      variant: 'primary',
      show: canSend.value,
      disabled: !props.canWrite || transportBusy.value !== null,
      disabledReason: !props.canWrite
        ? t('payroll.people.registration.write_required')
        : undefined,
      loading: transportBusy.value === 'send',
      run: send,
    },
    {
      key: 'registration-poll',
      label: t('payroll.people.registration.poll'),
      icon: 'cycle',
      tier: 'primary',
      variant: 'primary',
      show: canPoll.value,
      disabled: !props.canWrite || transportBusy.value !== null,
      disabledReason: !props.canWrite
        ? t('payroll.people.registration.write_required')
        : undefined,
      loading: transportBusy.value === 'poll',
      run: poll,
    },
    {
      key: 'registration-close',
      label: t('payroll.people.registration.close'),
      icon: 'check',
      tier: 'primary',
      variant: 'success',
      show: canClose.value,
      disabled: !props.canWrite || transportBusy.value !== null,
      disabledReason: !props.canWrite
        ? t('payroll.people.registration.write_required')
        : undefined,
      loading: transportBusy.value === 'close',
      run: close,
    },
  ]
})

async function run(action: 'preview' | 'prepare'): Promise<void> {
  busy.value = true
  error.value = ''
  try {
    if (action === 'preview') {
      submission.value = null
      transport.value = null
      transportMessage.value = ''
      preview.value = await payrollApi.previewEmploymentRegistration(
        props.employmentId,
        environment.value,
      )
    } else {
      submission.value = await payrollApi.prepareEmploymentRegistration(
        props.employmentId,
        environment.value,
      )
      const status = await payrollApi.employmentRegistrationTransportStatus(
        submission.value.submission_id,
        environment.value,
      )
      transport.value = status.attempt === null ? null : {
        attempt: status.attempt,
        acknowledgement: null,
        settled: status.attempt.status === 'completed',
        report: null,
      }
    }
  } catch (exception) {
    // Hláška ze serveru jmenuje konkrétní chybějící údaj — nesmí ji přebít
    // obecný text, jinak uživatel neví, co doplnit.
    error.value = apiErrorMessage(
      exception,
      t('payroll.people.registration.failed'),
    )
  } finally {
    busy.value = false
  }
}

async function send(): Promise<void> {
  if (!submission.value || !props.canWrite) return
  transportBusy.value = 'send'
  error.value = ''
  transportMessage.value = ''
  try {
    const result = await payrollApi.sendEmploymentRegistrationTransport(
      submission.value.submission_id,
      environment.value,
      crypto.randomUUID(),
    )
    transport.value = {
      attempt: result.attempt,
      acknowledgement: result.acknowledgement,
      settled: result.settled,
      report: null,
    }
  } catch (exception) {
    error.value = apiErrorMessage(
      exception,
      t('payroll.people.registration.send_failed'),
    )
  } finally {
    transportBusy.value = null
  }
}

async function poll(): Promise<void> {
  if (!transportAttempt.value || !props.canWrite) return
  transportBusy.value = 'poll'
  error.value = ''
  transportMessage.value = ''
  try {
    transport.value = await payrollApi.pollEmploymentRegistrationTransportAttempt(
      transportAttempt.value.id,
      environment.value,
    )
  } catch (exception) {
    error.value = apiErrorMessage(
      exception,
      t('payroll.people.registration.poll_failed'),
    )
  } finally {
    transportBusy.value = null
  }
}

async function close(): Promise<void> {
  if (!transportAttempt.value || !props.canWrite) return
  transportBusy.value = 'close'
  error.value = ''
  transportMessage.value = ''
  try {
    const result = await payrollApi.closeEmploymentRegistrationTransportAttempt(
      transportAttempt.value.id,
      environment.value,
    )
    transport.value = {
      ...transport.value!,
      attempt: result.attempt,
    }
    transportMessage.value = t('payroll.people.registration.closed', {
      id: result.attempt.id,
    })
  } catch (exception) {
    error.value = apiErrorMessage(
      exception,
      t('payroll.people.registration.close_failed'),
    )
  } finally {
    transportBusy.value = null
  }
}

async function copyXml(): Promise<void> {
  if (preview.value?.xml) await navigator.clipboard.writeText(preview.value.xml)
}
</script>

<template>
  <section
    class="mt-4 border-t border-neutral-200 pt-4"
    data-test="employment-registration"
  >
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div>
        <h4 class="text-sm font-semibold text-neutral-900">
          {{ t('payroll.people.registration.title') }}
        </h4>
        <p class="mt-0.5 text-xs text-neutral-500">
          {{ t('payroll.people.registration.description') }}
        </p>
      </div>
      <div class="flex flex-wrap items-center gap-2">
        <label class="flex items-center gap-2 text-xs text-neutral-600">
          <span>{{ t('payroll.people.registration.environment_label') }}</span>
          <select
            v-model="environment"
            class="rounded-md border border-neutral-300 bg-surface px-2 py-1.5 text-xs text-neutral-900 focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/20"
            :disabled="busy || submission !== null"
            data-test="registration-environment"
          >
            <option value="test">{{ t('payroll.people.registration.environment.test') }}</option>
            <option value="production">{{ t('payroll.people.registration.environment.production') }}</option>
          </select>
        </label>
        <button
          v-if="primaryAction !== 'preview'"
          type="button"
          :class="btnOutline('neutral')"
          :disabled="busy"
          data-test="registration-preview"
          @click="run('preview')"
        >
          {{ t('payroll.people.registration.preview') }}
        </button>
        <button
          v-if="primaryAction !== 'done'"
          type="button"
          :class="btnFilled('primary')"
          :disabled="busy || (primaryAction !== 'preview' && !canWrite)"
          :data-test="`registration-${primaryAction === 'preview' ? 'preview' : 'prepare'}`"
          @click="run(primaryAction === 'preview' ? 'preview' : 'prepare')"
        >
          <svg
            class="h-4 w-4"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            stroke-width="2"
            aria-hidden="true"
          >
            <path :d="ICONS.check" />
          </svg>
          {{ busy
            ? t('common.loading')
            : t(`payroll.people.registration.action_${primaryAction}`) }}
        </button>
      </div>
    </div>

    <div
      v-if="deadline"
      class="mt-3 rounded-lg border border-neutral-200 bg-neutral-50 p-3 text-xs text-neutral-700"
      data-test="registration-deadline"
    >
      <p class="font-medium text-neutral-900">
        {{ agendaLabel }} · {{ interactionLabel }}
      </p>
      <p class="mt-1">
        {{ t('payroll.people.registration.window', {
          from: formatDate(deadline.earliest_registration_on),
          to: formatDate(deadline.due_on),
        }) }}
      </p>
      <p
        v-if="preview?.employer_registration"
        class="mt-1 text-warning-700"
        data-test="registration-employer-deadline"
      >
        {{ t('payroll.people.registration.employer_window', {
          to: formatDate(preview.employer_registration.due_on),
        }) }}
      </p>
    </div>

    <div
      v-if="submission"
      class="mt-3 rounded-lg border border-success-500/30 bg-success-50 p-3"
      data-test="registration-prepared"
    >
      <p class="text-sm font-medium text-success-700">
        {{ t('payroll.people.registration.prepared', {
          agenda: agendaLabel,
        }) }}
      </p>
      <!--
        Záměrně NE „zaměstnanec je přihlášený": podání je připravené k odeslání
        a potvrzení od ČSSZ zatím žádné není.
      -->
      <p class="mt-1 text-xs text-success-700">
        {{ t('payroll.people.registration.not_sent_yet') }}
      </p>
      <p class="mt-1 break-all font-mono text-xs text-success-700">
        {{ submission.artifact_sha256.slice(0, 16) }}…
      </p>

      <div class="mt-3 border-t border-success-500/20 pt-3" data-test="registration-transport-actions">
        <ActionBar :actions="transportActions" />
      </div>
    </div>

    <div
      v-if="transportAttempt"
      class="mt-3 rounded-lg border border-primary-200 bg-primary-50 p-3 text-sm text-primary-900"
      data-test="registration-transport-result"
    >
      <p class="font-medium">
        {{ t('payroll.people.registration.attempt', {
          id: transportAttempt.id,
          status: t(`payroll.submissions.transport.status.${transportAttempt.status}`),
        }) }}
      </p>
      <p v-if="transportAttempt.status === 'awaiting_protocol'" class="mt-1 text-xs">
        {{ t('payroll.people.registration.awaiting_protocol') }}
      </p>
      <p v-if="transport?.report" class="mt-1 text-xs">
        {{ t('payroll.people.registration.protocol', {
          status: t(`payroll.submissions.transport.protocol_status.${transport.report.status}`),
          errors: transport.report.errors.length,
        }) }}
      </p>
      <p v-if="transportMessage" class="mt-1 text-xs text-success-800">
        {{ transportMessage }}
      </p>
    </div>

    <div v-if="preview && !submission" class="mt-3">
      <div class="flex flex-wrap gap-2">
        <button
          type="button"
          :class="btnOutline('neutral')"
          data-test="registration-toggle-xml"
          @click="showXml = !showXml"
        >
          {{ showXml
            ? t('payroll.people.registration.hide_xml')
            : t('payroll.people.registration.show_xml') }}
        </button>
        <button
          type="button"
          :class="btnOutline('neutral')"
          @click="copyXml"
        >
          {{ t('payroll.people.registration.copy_xml') }}
        </button>
      </div>
      <pre
        v-if="showXml"
        class="mt-3 max-h-80 overflow-auto rounded-lg bg-neutral-900 p-3 text-xs leading-relaxed text-neutral-100"
      >{{ preview.xml }}</pre>
      <p class="mt-2 text-xs text-neutral-500">
        {{ preview.official_submission.reason }}
      </p>
    </div>

    <p
      v-if="error"
      class="mt-3 rounded-lg border border-danger-500/30 bg-danger-50 p-3 text-sm text-danger-700"
      role="alert"
      data-test="registration-error"
    >
      {{ error }}
    </p>
  </section>
</template>

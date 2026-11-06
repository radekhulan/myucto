<script setup lang="ts">
import { computed, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { useI18n } from 'vue-i18n'
import {
  payrollApi,
  type PayrollJmhzPost20Guide,
  type PayrollJmhzPost20Situation,
  type PayrollJmhzTransportEnvironment,
} from '@/api/payroll'
import { apiErrorMessage } from '@/api/errors'
import { btnFilled, btnOutlineSm, ICONS } from '@/components/ui/buttonStyles'
import { formatPeriod } from '@/composables/useFormat'
import { useSubmissionEnvironment } from '@/composables/useSubmissionEnvironment'
import { jmhzBlockerLabel } from './jmhzBlockerRemediation'

/**
 * Průvodce postupem po 20. dni (Pravidla podání JMHZ, „Postup po 20. dni"):
 * storno součásti už nejde, nápravu tvoří REGZEC akce a opravná měsíční
 * hlášení za dotčené měsíce. Panel jen ukazuje, co k situaci patří a v jakém
 * je to stavu; podání se zakládají na svých místech (registrace na kartě
 * vztahu, anulace měsíce v historii odeslání hlášení).
 */
const props = defineProps<{
  employmentId: number
}>()

const { t, te } = useI18n()
const SITUATIONS: PayrollJmhzPost20Situation[] = ['late_termination', 'date_change', 'invalid_employment']

const environment = ref<PayrollJmhzTransportEnvironment>('production')
const { testAllowed } = useSubmissionEnvironment(environment)
const situation = ref<PayrollJmhzPost20Situation | ''>('')
const guide = ref<PayrollJmhzPost20Guide | null>(null)
const loading = ref(false)
const error = ref('')

const statusClass = computed(() => ({
  open: 'bg-warning-50 text-warning-700 border-warning-500/30',
  partially_done: 'bg-primary-50 text-primary-700 border-primary-500/30',
  complete: 'bg-success-50 text-success-700 border-success-500/30',
}[guide.value?.status ?? 'open']))

async function load() {
  if (situation.value === '') return
  loading.value = true
  error.value = ''
  try {
    guide.value = await payrollApi.jmhzPost20Guide(props.employmentId, situation.value, environment.value)
  } catch (exception: unknown) {
    guide.value = null
    error.value = apiErrorMessage(exception, t('payroll.people.post20.load_failed'))
  } finally {
    loading.value = false
  }
}

const situationLabels = computed<Record<PayrollJmhzPost20Situation, string>>(() => ({
  late_termination: t('payroll.people.post20.situations.late_termination'),
  date_change: t('payroll.people.post20.situations.date_change'),
  invalid_employment: t('payroll.people.post20.situations.invalid_employment'),
}))
const situationHints = computed<Record<PayrollJmhzPost20Situation, string>>(() => ({
  late_termination: t('payroll.people.post20.situation_hints.late_termination'),
  date_change: t('payroll.people.post20.situation_hints.date_change'),
  invalid_employment: t('payroll.people.post20.situation_hints.invalid_employment'),
}))
const statusLabels = computed<Record<PayrollJmhzPost20Guide['status'], string>>(() => ({
  open: t('payroll.people.post20.status.open'),
  partially_done: t('payroll.people.post20.status.partially_done'),
  complete: t('payroll.people.post20.status.complete'),
}))
const orderLabels = computed<Record<PayrollJmhzPost20Guide['order'], string>>(() => ({
  together: t('payroll.people.post20.order.together'),
  amendments_first: t('payroll.people.post20.order.amendments_first'),
}))
const actionLabels = computed<Record<string, string>>(() => ({
  annul: t('payroll.people.post20.actions.annul'),
  amended: t('payroll.people.post20.actions.amended'),
  review: t('payroll.people.post20.actions.review'),
  manual: t('payroll.people.post20.actions.manual'),
  choose_variant: t('payroll.people.post20.actions.choose_variant'),
}))

function actionLabel(action: string): string {
  return actionLabels.value[action] ?? action
}

function codeLabel(code: string): string {
  return jmhzBlockerLabel(t, te, { code, entity_type: 'employment', entity_id: props.employmentId, attribute_ids: [] })
}
</script>

<template>
  <section class="mt-5 rounded-lg border border-neutral-200 bg-neutral-50/60 p-3 sm:p-4" data-test="employment-post20">
    <h4 class="text-sm font-semibold text-neutral-900">{{ t('payroll.people.post20.title') }}</h4>
    <p class="mt-1 text-xs text-neutral-500">{{ t('payroll.people.post20.subtitle') }}</p>

    <div class="mt-3 flex flex-wrap items-end gap-3">
      <label class="flex min-w-0 flex-col gap-1 text-xs font-medium text-neutral-700">
        {{ t('payroll.people.post20.situation_label') }}
        <select
          v-model="situation"
          class="rounded-md border border-neutral-300 bg-surface px-2 py-1.5 text-sm"
          data-test="post20-situation"
        >
          <option value="" disabled>{{ t('payroll.people.post20.situation_placeholder') }}</option>
          <option v-for="value in SITUATIONS" :key="value" :value="value">
            {{ situationLabels[value] }}
          </option>
        </select>
      </label>
      <label v-if="testAllowed" class="flex flex-col gap-1 text-xs font-medium text-neutral-700">
        {{ t('payroll.people.post20.environment_label') }}
        <select v-model="environment" class="rounded-md border border-neutral-300 bg-surface px-2 py-1.5 text-sm">
          <option value="production">{{ t('payroll.people.post20.environments.production') }}</option>
          <option value="test">{{ t('payroll.people.post20.environments.test') }}</option>
        </select>
      </label>
      <button
        type="button"
        :class="[btnFilled('primary'), 'whitespace-nowrap']"
        :disabled="situation === '' || loading"
        data-test="post20-load"
        @click="load"
      >
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
          <path :d="ICONS.search" />
        </svg>
        {{ t('payroll.people.post20.load') }}
      </button>
    </div>

    <p v-if="situation" class="mt-2 text-xs text-neutral-600">
      {{ situationHints[situation] }}
    </p>
    <p v-if="error" class="mt-3 rounded-lg border border-danger-500/30 bg-danger-50 p-3 text-sm text-danger-700" role="alert">
      {{ error }}
    </p>
    <div v-if="loading" class="mt-3 h-16 animate-pulse rounded-lg bg-neutral-100" />

    <div v-else-if="guide" class="mt-4 space-y-4" data-test="post20-guide">
      <div class="flex flex-wrap items-center gap-2">
        <span class="rounded-full border px-2 py-0.5 text-xs font-medium" :class="statusClass" data-test="post20-status">
          {{ statusLabels[guide.status] }}
        </span>
        <span class="text-xs text-neutral-600">{{ orderLabels[guide.order] }}</span>
      </div>

      <ul v-if="guide.warnings.length" class="space-y-1">
        <li
          v-for="code in guide.warnings"
          :key="code"
          class="rounded-md border border-warning-500/30 bg-warning-50 px-3 py-2 text-xs text-warning-800"
        >
          {{ codeLabel(code) }}
        </li>
      </ul>

      <div>
        <h5 class="text-xs font-semibold uppercase tracking-wide text-neutral-500">
          {{ t('payroll.people.post20.registration_title', { actions: guide.regzec_actions.join(', ') }) }}
        </h5>
        <p v-if="guide.registration.events.length === 0" class="mt-1 text-sm text-neutral-700" data-test="post20-registration-missing">
          {{ t('payroll.people.post20.registration_missing') }}
        </p>
        <ul v-else class="mt-1 space-y-1 text-sm text-neutral-800">
          <li v-for="event in guide.registration.events" :key="event.id">
            {{ t('payroll.people.post20.registration_event', {
              action: event.action_code,
              date: event.effective_on,
              state: t(event.submitted ? 'payroll.people.post20.registration_submitted' : 'payroll.people.post20.registration_prepared'),
            }) }}
          </li>
        </ul>
      </div>

      <div>
        <h5 class="text-xs font-semibold uppercase tracking-wide text-neutral-500">
          {{ t('payroll.people.post20.months_title', { months: guide.lookback_months }) }}
        </h5>
        <p v-if="guide.months.length === 0" class="mt-1 text-sm text-neutral-700" data-test="post20-months-empty">
          {{ t('payroll.people.post20.months_empty') }}
        </p>
        <ul v-else class="mt-1 space-y-2">
          <li
            v-for="month in guide.months"
            :key="`${month.submission_id}-${month.form_guid}`"
            class="flex flex-wrap items-center justify-between gap-2 rounded-md border border-neutral-200 bg-surface px-3 py-2"
            :data-test="`post20-month-${month.period}`"
          >
            <span class="min-w-0 text-sm">
              <span class="font-medium text-neutral-900">{{ formatPeriod(month.period) }}</span>
              <span class="ml-2 text-neutral-700">{{ actionLabel(month.action) }}</span>
              <span
                v-if="month.blocker && month.action !== 'amended' && month.action !== 'choose_variant'"
                class="mt-0.5 block text-xs text-neutral-600"
              >
                {{ codeLabel(month.blocker.code) }}
              </span>
              <span
                v-if="month.action === 'choose_variant'"
                class="mt-1 block space-y-1 text-xs text-neutral-700"
                :data-test="`post20-variants-${month.period}`"
              >
                <span class="block">{{ t('payroll.people.post20.variants_title') }}</span>
                <span v-for="variant in month.variants ?? []" :key="variant.key" class="block">
                  {{ t(`payroll.people.post20.variants.${variant.key}`) }}
                </span>
                <span class="block text-neutral-600">{{ t('payroll.people.post20.variants_note') }}</span>
              </span>
            </span>
            <RouterLink
              v-if="month.action === 'annul' || month.action === 'review' || month.action === 'choose_variant'"
              :to="{ name: 'payroll-submissions-tab', params: { tab: 'transport' } }"
              :class="[btnOutlineSm('warning'), 'whitespace-nowrap']"
            >
              <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path :d="ICONS.link" />
              </svg>
              {{ t('payroll.people.post20.open_submissions') }}
            </RouterLink>
          </li>
        </ul>
      </div>
    </div>
  </section>
</template>

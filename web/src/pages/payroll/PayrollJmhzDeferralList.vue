<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import {
  payrollApi,
  type PayrollJmhzDeferral,
  type PayrollJmhzDeferralList,
  type PayrollJmhzDeferralState,
} from '@/api/payroll'
import { btnFilled, btnOutline, ICONS } from '@/components/ui/buttonStyles'
import { formatDate } from '@/composables/useFormat'
import { jmhzBlockerLabel, jmhzErrorMessage } from './jmhzBlockerRemediation'

/**
 * Odložené vztahy z řádného měsíčního hlášení za jeden mzdový běh.
 *
 * Odložený vztah je NESPLNĚNÁ POVINNOST: řádné hlášení ho vynechalo a ČSSZ
 * ho vyzve k doplnění. Seznam proto drží lhůtu, stav a jediný krok, který
 * vede ven: doplnit formulář opravným hlášením, jakmile jsou data v pořádku.
 */
const props = withDefaults(defineProps<{
  revisionId: number
  environment: 'test' | 'production'
  canWrite: boolean
  /** Zvýšením si rodič vyžádá nové načtení (po odložení z testu hlášení). */
  refreshKey?: number
  /** Příprava aktuální revize, do které lze převzít trvající odložení. */
  preparationId?: number | null
  officeId?: number | null
}>(), { refreshKey: 0, preparationId: null, officeId: null })

const emit = defineEmits<{ changed: [] }>()
const { t, te, locale } = useI18n()

const list = ref<PayrollJmhzDeferralList | null>(null)
const loading = ref(false)
const loadError = ref('')
const busyId = ref<number | null>(null)
const actionError = ref('')
const success = ref<{ kind: 'completed' | 'revoked' | 'carried' | 'completed_all', id: number } | null>(null)
const bulkBusy = ref<'carry' | 'complete' | null>(null)
const revokingId = ref<number | null>(null)
const revokeReason = ref('')
const showRevokeValidation = ref(false)

async function load() {
  loading.value = true
  loadError.value = ''
  try {
    list.value = await payrollApi.jmhzDeferrals(props.revisionId, props.environment)
  } catch (exception) {
    loadError.value = jmhzErrorMessage(t, te, locale.value, exception, 'payroll.jmhz_gate.deferral.load_failed')
  } finally {
    loading.value = false
  }
}

watch(() => [props.revisionId, props.environment, props.refreshKey], load, { immediate: true })

const rows = computed(() => list.value?.deferrals ?? [])
const openRows = computed(() => rows.value.filter(row => !['revoked', 'completed', 'stale'].includes(row.state)))

const STATE_TONE: Record<PayrollJmhzDeferralState, string> = {
  pending: 'bg-warning-50 text-warning-700 border-warning-500/30',
  omitted: 'bg-warning-50 text-warning-700 border-warning-500/30',
  to_complete: 'bg-danger-50 text-danger-700 border-danger-500/30',
  completing: 'bg-primary-50 text-primary-700 border-primary-500/30',
  completed: 'bg-success-50 text-success-700 border-success-500/30',
  revoked: 'bg-neutral-50 text-neutral-600 border-neutral-300',
  stale: 'bg-neutral-50 text-neutral-600 border-neutral-300',
}

function codeLabel(code: string): string {
  return jmhzBlockerLabel(t, te, { code, entity_type: 'employment', entity_id: null, attribute_ids: [] })
}

function askRevoke(row: PayrollJmhzDeferral) {
  revokingId.value = row.id
  revokeReason.value = ''
  showRevokeValidation.value = false
  actionError.value = ''
  success.value = null
}

const revokeReasonValid = computed(() => revokeReason.value.trim().length >= 3)

async function confirmRevoke(row: PayrollJmhzDeferral) {
  if (!props.canWrite || busyId.value !== null) return
  if (!revokeReasonValid.value) {
    showRevokeValidation.value = true
    return
  }
  busyId.value = row.id
  actionError.value = ''
  try {
    await payrollApi.revokeJmhzDeferral(row.id, row.row_version, revokeReason.value.trim())
    revokingId.value = null
    success.value = { kind: 'revoked', id: row.id }
    await load()
    emit('changed')
  } catch (exception) {
    actionError.value = jmhzErrorMessage(t, te, locale.value, exception, 'payroll.jmhz_gate.deferral.revoke_failed')
  } finally {
    busyId.value = null
  }
}

const carryOverCount = computed(() => list.value?.carry_over_count ?? 0)
const completableCount = computed(() => list.value?.completable_count ?? rows.value.filter(row => row.can_complete).length)

async function carryOver() {
  if (!props.canWrite || props.preparationId === null || bulkBusy.value !== null) return
  bulkBusy.value = 'carry'
  actionError.value = ''
  success.value = null
  try {
    const result = await payrollApi.carryOverJmhzDeferrals({
      environment: props.environment,
      preparationId: props.preparationId,
      officeId: props.officeId,
    })
    success.value = { kind: 'carried', id: result.employment_ids.length }
    await load()
    emit('changed')
  } catch (exception) {
    actionError.value = jmhzErrorMessage(t, te, locale.value, exception, 'payroll.jmhz_gate.deferral.carry_failed')
  } finally {
    bulkBusy.value = null
  }
}

async function completeAll() {
  if (!props.canWrite || bulkBusy.value !== null) return
  bulkBusy.value = 'complete'
  actionError.value = ''
  success.value = null
  try {
    const result = await payrollApi.completeAllJmhzDeferrals(props.revisionId, props.environment)
    success.value = { kind: 'completed_all', id: result.completed_employment_ids.length }
    await load()
    emit('changed')
  } catch (exception) {
    actionError.value = jmhzErrorMessage(t, te, locale.value, exception, 'payroll.jmhz_gate.deferral.complete_failed')
  } finally {
    bulkBusy.value = null
  }
}

async function complete(row: PayrollJmhzDeferral) {
  if (!props.canWrite || busyId.value !== null) return
  busyId.value = row.id
  actionError.value = ''
  success.value = null
  try {
    const result = await payrollApi.completeJmhzDeferral(row.id, props.environment)
    success.value = { kind: 'completed', id: result.submission_id }
    await load()
    emit('changed')
  } catch (exception) {
    actionError.value = jmhzErrorMessage(t, te, locale.value, exception, 'payroll.jmhz_gate.deferral.complete_failed')
  } finally {
    busyId.value = null
  }
}
</script>

<template>
  <section
    v-if="loading || loadError || rows.length > 0"
    class="mt-3 rounded-lg border border-neutral-200 p-3"
    data-test="jmhz-deferral-list"
  >
    <div class="flex flex-wrap items-center justify-between gap-2">
      <h4 class="text-sm font-semibold text-neutral-900">
        {{ t('payroll.jmhz_gate.deferral.list_title') }}
      </h4>
      <span
        v-if="list"
        class="whitespace-nowrap text-xs text-neutral-600"
        data-test="jmhz-deferral-due"
      >
        {{ t('payroll.jmhz_gate.deferral.due', { date: formatDate(list.due_on) }) }}
      </span>
    </div>
    <p v-if="loading && !list" class="mt-2 text-sm text-neutral-500">{{ t('common.loading') }}</p>
    <p v-if="loadError" class="mt-2 rounded-lg border border-danger-500/30 bg-danger-50 p-2 text-sm text-danger-700" role="alert">
      {{ loadError }}
    </p>

    <p
      v-if="list && openRows.length > 0"
      class="mt-2 rounded-lg border p-2 text-sm"
      :class="list.overdue
        ? 'border-danger-500/30 bg-danger-50 text-danger-700'
        : 'border-warning-500/30 bg-warning-50 text-warning-800'"
      data-test="jmhz-deferral-obligation"
    >
      {{ list.overdue
        ? t('payroll.jmhz_gate.deferral.obligation_overdue', { count: openRows.length, date: formatDate(list.due_on) })
        : t('payroll.jmhz_gate.deferral.obligation', { count: openRows.length, date: formatDate(list.due_on) }) }}
    </p>

    <div v-if="canWrite && (carryOverCount > 0 && preparationId !== null || completableCount > 1)" class="mt-2 flex flex-wrap gap-2">
      <button
        v-if="carryOverCount > 0 && preparationId !== null"
        type="button"
        :class="btnOutline('warning')"
        :disabled="bulkBusy !== null || busyId !== null"
        data-test="jmhz-deferral-carry-over"
        @click="carryOver"
      >
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
          <path :d="ICONS.cycle" />
        </svg>
        {{ bulkBusy === 'carry' ? t('common.loading') : t('payroll.jmhz_gate.deferral.carry_over', { count: carryOverCount }) }}
      </button>
      <button
        v-if="completableCount > 1"
        type="button"
        :class="btnFilled('primary')"
        :disabled="bulkBusy !== null || busyId !== null"
        data-test="jmhz-deferral-complete-all"
        @click="completeAll"
      >
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
          <path :d="ICONS.send" />
        </svg>
        {{ bulkBusy === 'complete' ? t('common.loading') : t('payroll.jmhz_gate.deferral.complete_all', { count: completableCount }) }}
      </button>
    </div>

    <ul class="mt-2 space-y-2">
      <li
        v-for="row in rows"
        :key="row.id"
        class="rounded-lg border border-neutral-200 p-3 text-sm"
        :data-test="`jmhz-deferral-${row.id}`"
      >
        <div class="flex flex-wrap items-start justify-between gap-2">
          <div class="min-w-0 flex-1">
            <p class="font-medium text-neutral-900">
              {{ row.employee_name ?? t('payroll.jmhz_gate.deferral.unnamed', { id: row.employee_id }) }}
            </p>
            <p class="mt-0.5 text-xs text-neutral-600">
              {{ t('payroll.jmhz_gate.deferral.reason', { reason: row.reason }) }}
            </p>
            <ul v-if="row.blocker_codes.length" class="mt-1 list-disc pl-4 text-xs text-neutral-600">
              <li v-for="code in row.blocker_codes" :key="code">{{ codeLabel(code) }}</li>
            </ul>
            <p class="mt-1 text-xs text-neutral-500">
              {{ t(`payroll.jmhz_gate.deferral.state_hint.${row.state}`) }}
            </p>
          </div>
          <span
            class="whitespace-nowrap rounded-full border px-2 py-0.5 text-xs font-medium"
            :class="STATE_TONE[row.state]"
            :data-test="`jmhz-deferral-state-${row.id}`"
          >
            {{ t(`payroll.jmhz_gate.deferral.state.${row.state}`) }}
          </span>
        </div>

        <div v-if="canWrite && (row.can_complete || row.can_revoke)" class="mt-2 flex flex-wrap gap-2">
          <button
            v-if="row.can_complete"
            type="button"
            :class="btnFilled('primary')"
            :disabled="busyId !== null"
            :data-test="`jmhz-deferral-complete-${row.id}`"
            @click="complete(row)"
          >
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
              <path :d="ICONS.send" />
            </svg>
            {{ busyId === row.id ? t('common.loading') : t('payroll.jmhz_gate.deferral.complete') }}
          </button>
          <button
            v-if="row.can_revoke && revokingId !== row.id"
            type="button"
            :class="btnOutline('neutral')"
            :disabled="busyId !== null"
            :data-test="`jmhz-deferral-revoke-${row.id}`"
            @click="askRevoke(row)"
          >
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
              <path :d="ICONS.uturn" />
            </svg>
            {{ t('payroll.jmhz_gate.deferral.revoke') }}
          </button>
        </div>

        <div
          v-if="revokingId === row.id"
          class="mt-2 rounded-lg border border-neutral-300 bg-neutral-50 p-3"
          :data-test="`jmhz-deferral-revoke-form-${row.id}`"
        >
          <label class="block text-xs font-medium text-neutral-700" :for="`jmhz-deferral-revoke-reason-${row.id}`">
            {{ t('payroll.jmhz_gate.deferral.revoke_reason') }}
          </label>
          <textarea
            :id="`jmhz-deferral-revoke-reason-${row.id}`"
            v-model="revokeReason"
            rows="2"
            maxlength="500"
            class="mt-1 w-full rounded-lg border border-neutral-300 bg-surface px-2 py-1 text-sm"
            :data-test="`jmhz-deferral-revoke-input-${row.id}`"
          />
          <p v-if="showRevokeValidation && !revokeReasonValid" class="mt-1 text-xs text-danger-700">
            {{ t('payroll.jmhz_gate.deferral.reason_required') }}
          </p>
          <div class="mt-2 flex flex-wrap gap-2">
            <button
              type="button"
              :class="btnFilled('warning')"
              :disabled="busyId !== null"
              :data-test="`jmhz-deferral-revoke-confirm-${row.id}`"
              @click="confirmRevoke(row)"
            >
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path :d="ICONS.check" />
              </svg>
              {{ t('payroll.jmhz_gate.deferral.revoke_confirm') }}
            </button>
            <button type="button" :class="btnOutline('neutral')" @click="revokingId = null">
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path :d="ICONS.x" />
              </svg>
              {{ t('common.cancel') }}
            </button>
          </div>
        </div>

        <RouterLink
          v-if="row.correction_submission_id !== null"
          :to="{ name: 'payroll-submissions-tab', params: { tab: 'transport' } }"
          class="mt-2 inline-flex text-xs font-medium text-primary-700 underline decoration-dotted underline-offset-4"
        >
          {{ t('payroll.jmhz_gate.deferral.open_correction', { id: row.correction_submission_id }) }}
        </RouterLink>
      </li>
    </ul>

    <p
      v-if="success"
      class="mt-2 rounded-lg border border-success-500/30 bg-success-50 p-2 text-sm text-success-700"
      role="status"
      data-test="jmhz-deferral-success"
    >
      {{ success.kind === 'completed'
        ? t('payroll.jmhz_gate.deferral.completed', { id: success.id })
        : success.kind === 'carried'
          ? t('payroll.jmhz_gate.deferral.carried', { count: success.id })
          : success.kind === 'completed_all'
            ? t('payroll.jmhz_gate.deferral.completed_all', { count: success.id })
            : t('payroll.jmhz_gate.deferral.revoked') }}
    </p>
    <p
      v-if="actionError"
      class="mt-2 rounded-lg border border-danger-500/30 bg-danger-50 p-2 text-sm text-danger-700"
      role="alert"
      data-test="jmhz-deferral-error"
    >
      {{ actionError }}
    </p>
  </section>
</template>

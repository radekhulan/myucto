<script setup lang="ts">
/**
 * Zahození mzdové dávky nebo změna jejího data úhrady.
 *
 * Změna data je zahození + nová dávka ze stejných závazků (dávka je neměnný
 * doklad). Dávka, kterou si účetní už stáhla nebo předala bance, jde zahodit
 * jen s potvrzením, že ji v bankovnictví zrušila - jinak hrozí dvojí platba.
 * Stejný vzor mají příkazy přijatých faktur.
 */
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import Modal from '@/components/ui/Modal.vue'
import { btnFilled, btnOutline, ICONS } from '@/components/ui/buttonStyles'
import {
  payrollPaymentsApi,
  type PayrollPaymentBatch,
  type PayrollPaymentBatchDiscardResult,
  type PayrollPaymentBatchHandoverState,
  type PayrollPaymentBatchRescheduleResult,
} from '@/api/payrollPayments'
import { apiErrorCode, apiErrorMessage } from '@/api/errors'
import PayrollPaymentDateChoice from './PayrollPaymentDateChoice.vue'
import {
  batchDescription,
  effectivePaymentDate,
  isLatePaymentDate,
  todayIso,
  type PaymentDateChoice,
} from './payrollPaymentDate'

const props = defineProps<{
  batch: PayrollPaymentBatch
  mode: 'discard' | 'reschedule'
  today?: string
}>()

const emit = defineEmits<{
  close: []
  discarded: [result: PayrollPaymentBatchDiscardResult]
  rescheduled: [result: PayrollPaymentBatchRescheduleResult]
}>()

const { t } = useI18n()
const today = computed(() => props.today ?? todayIso())
const handover = ref<PayrollPaymentBatchHandoverState>(props.batch.handover_state ?? 'none')
const bankCancellationConfirmed = ref(false)
const choice = ref<PaymentDateChoice>({ mode: 'today', customDate: '' })
const lateConfirmed = ref(false)
const busy = ref(false)
const error = ref('')

const defaultDate = computed(() => props.batch.default_payment_date ?? null)
const newDate = computed(() => effectivePaymentDate(choice.value, defaultDate.value, today.value))
const late = computed(() => isLatePaymentDate(newDate.value, defaultDate.value))
const sameDate = computed(() => newDate.value === props.batch.planned_payment_date)

const blockedReason = computed<string | null>(() => {
  if (handover.value !== 'none' && !bankCancellationConfirmed.value) {
    return t('payroll.payments.batch_change.confirm_required')
  }
  if (props.mode === 'reschedule') {
    if (newDate.value === null || newDate.value < today.value) {
      return t('payroll.payments.payment_date.past')
    }
    if (sameDate.value) return t('payroll.payments.batch_change.same_date')
    if (late.value && !lateConfirmed.value) {
      return t('payroll.payments.payment_date.late_confirm_required')
    }
  }
  return null
})

async function submit(): Promise<void> {
  if (busy.value || blockedReason.value !== null) return
  busy.value = true
  error.value = ''
  try {
    if (props.mode === 'discard') {
      emit('discarded', await payrollPaymentsApi.discardBatch(props.batch.id, {
        confirm_bank_cancellation: bankCancellationConfirmed.value,
      }))
    } else {
      emit('rescheduled', await payrollPaymentsApi.rescheduleBatch(props.batch.id, {
        payment_date: newDate.value as string,
        accept_late_payment: late.value && lateConfirmed.value,
        confirm_bank_cancellation: bankCancellationConfirmed.value,
      }))
    }
  } catch (caught) {
    const code = apiErrorCode(caught)
    if (code === 'payment_batch_handover_unconfirmed') {
      // Dávku mezitím někdo stáhl nebo předal bance - dialog to teď ukáže.
      const state = (caught as { response?: { data?: { error?: { handover_state?: string } } } })
        .response?.data?.error?.handover_state
      handover.value = state === 'submitted' ? 'submitted' : 'downloaded'
      bankCancellationConfirmed.value = false
    }
    error.value = apiErrorMessage(caught, t(
      props.mode === 'discard'
        ? 'payroll.payments.batch_change.discard_failed'
        : 'payroll.payments.batch_change.reschedule_failed',
    ))
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <Modal
    :title="t(mode === 'discard'
      ? 'payroll.payments.batch_change.discard_title'
      : 'payroll.payments.batch_change.reschedule_title')"
    width-class="max-w-2xl"
    @close="emit('close')"
  >
    <div class="space-y-4 text-sm" data-test="batch-change-dialog">
      <p class="font-medium text-neutral-900" data-test="batch-change-subject">
        {{ batchDescription(batch, t) }}
      </p>
      <p class="text-neutral-600">
        {{ t(mode === 'discard'
          ? 'payroll.payments.batch_change.discard_body'
          : 'payroll.payments.batch_change.reschedule_body') }}
      </p>
      <PayrollPaymentDateChoice
        v-if="mode === 'reschedule'"
        v-model="choice"
        v-model:late-confirmed="lateConfirmed"
        :default-date="defaultDate"
        :today="today"
        :disabled="busy"
        test-id="reschedule-date-choice"
      />
      <div
        v-if="handover !== 'none'"
        class="rounded-lg border border-warning-300 bg-warning-50 p-3 text-warning-900"
        role="alert"
        data-test="batch-change-handover"
      >
        <p class="font-semibold">
          {{ t(handover === 'submitted'
            ? 'payroll.payments.batch_change.handover_submitted'
            : 'payroll.payments.batch_change.handover_downloaded') }}
        </p>
        <p class="mt-1">{{ t('payroll.payments.batch_change.handover_body') }}</p>
        <p v-if="handover === 'submitted'" class="mt-1" data-test="batch-change-csas">{{ t('payroll.payments.batch_change.handover_csas') }}</p>
        <label class="mt-2 flex cursor-pointer items-start gap-2">
          <input
            v-model="bankCancellationConfirmed"
            type="checkbox"
            class="mt-0.5 accent-warning-600"
            :disabled="busy"
            data-test="batch-change-bank-confirm"
          >
          <span>{{ t('payroll.payments.batch_change.handover_confirm') }}</span>
        </label>
      </div>
      <p v-if="error" class="text-danger-700" role="alert" data-test="batch-change-error">{{ error }}</p>
    </div>
    <template #footer>
      <div class="flex flex-wrap items-center justify-end gap-2">
        <span
          v-if="blockedReason"
          class="mr-auto text-xs text-neutral-500"
          data-test="batch-change-blocked"
        >{{ blockedReason }}</span>
        <button
          type="button"
          class="whitespace-nowrap"
          :class="btnOutline('neutral')"
          :disabled="busy"
          @click="emit('close')"
        >
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.x" /></svg>
          {{ t('common.cancel') }}
        </button>
        <button
          type="button"
          class="whitespace-nowrap"
          :class="btnFilled(mode === 'discard' ? 'danger' : 'primary')"
          :disabled="busy || blockedReason !== null"
          data-test="batch-change-submit"
          @click="submit"
        >
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path :d="mode === 'discard' ? ICONS.trash : ICONS.calendar" />
          </svg>
          {{ busy
            ? t('common.loading')
            : t(mode === 'discard'
              ? 'payroll.payments.batch_change.discard_submit'
              : 'payroll.payments.batch_change.reschedule_submit') }}
        </button>
      </div>
    </template>
  </Modal>
</template>

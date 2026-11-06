<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { propertyPaymentOperationsApi, type Reservation } from '@/api/propertyPaymentOperations'
import { bankApi, type BankStatement } from '@/api/bank'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import { useWriteFlow } from './flows'
import { moneyText } from './format'
import { inputClass, subBoxClass } from './ui'
const props = defineProps<{ supplier: number; reservation: Reservation; mode: 'cancel' | 'submission' | 'release'; canManage: boolean }>()
const emit = defineEmits<{ changed: []; close: [] }>()
const { t } = useI18n()
const reason = ref('')
const evidence = ref('')
const statementId = ref(0)
const statements = ref<BankStatement[]>([])
const form = ref<HTMLFormElement | null>(null)
// Uvolnění odeslaného příkazu dokládá výpis účtu, ze kterého příkaz odešel; server ověří účet, datum i to, že vratka na výpisu není.
onMounted(async () => {
  if (props.mode !== 'release') return
  try { statements.value = (await bankApi.list({ page: 1 })).items } catch { statements.value = [] }
})
const flow = useWriteFlow({
  supplier: () => props.supplier, validate: () => form.value?.reportValidity() ?? false, canWrite: () => props.canManage,
  build: () => ({ reason: reason.value, evidence: evidence.value, statement: statementId.value, version: props.reservation.row_version }),
  write: (supplier, request, token) => props.mode === 'cancel'
    ? propertyPaymentOperationsApi.cancelReservation(supplier, props.reservation.id, request.version, request.reason, token)
    : props.mode === 'submission'
      ? propertyPaymentOperationsApi.cancelSubmission(supplier, props.reservation.id, request.version, request.reason, request.evidence, token)
      : propertyPaymentOperationsApi.releaseUnexecuted(supplier, props.reservation.id, request.version, request.statement, request.evidence, token),
  onDone: () => { emit('changed'); emit('close') },
})
const unknown = () => t('propertyPayments.unknown')
const help = () => props.mode === 'release'
  ? t('propertyPayments.reservation.releaseHelp', { amount: moneyText(props.reservation.remaining_cents, unknown()) })
  : props.mode === 'submission'
    ? t('propertyPayments.reservation.submissionHelp', { amount: moneyText(props.reservation.remaining_cents, unknown()) })
    : props.reservation.paid_cents !== '0'
      ? t('propertyPayments.reservation.partialCancelHelp', { amount: moneyText(props.reservation.remaining_cents, unknown()) })
      : t('propertyPayments.reservation.cancelHelp')
const submitLabel = () => t(props.mode === 'cancel' ? 'propertyPayments.reservation.cancelSubmit' : props.mode === 'submission' ? 'propertyPayments.reservation.submissionSubmit' : 'propertyPayments.reservation.releaseSubmit')
const statementLabel = (s: BankStatement) => [s.statement_number ? t('propertyPayments.reservation.statementNumber', { number: s.statement_number }) : null, s.statement_date, s.account_label ?? s.account_number].filter(Boolean).join(' · ')
const actions = () => [{ key: 'cancel-submit', label: submitLabel(), icon: props.mode === 'release' ? 'uturn' : 'x', variant: props.mode === 'release' ? 'warning' : 'danger', tier: 'primary', disabled: flow.busy.value || !props.canManage, run: () => { void flow.submit() } }] as ActionItem[]
</script>

<template>
  <form ref="form" :class="subBoxClass" :data-section="mode === 'cancel' ? 'reservation-cancel' : mode === 'submission' ? 'submission-cancel' : 'submission-release'" @submit.prevent="flow.submit">
    <fieldset :disabled="flow.busy.value || !canManage" class="space-y-2">
      <p class="text-sm">{{ help() }}</p>
      <label v-if="mode !== 'release'" class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyPayments.reason') }}<input v-model="reason" required :class="inputClass" data-field="cancel-reason"></label>
      <label v-if="mode === 'release'" class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyPayments.reservation.releaseStatement') }}
        <select v-model="statementId" required :class="inputClass" data-field="release-statement">
          <option :value="0" disabled>{{ t('propertyPayments.choose') }}</option>
          <option v-for="s in statements" :key="s.id" :value="s.id">{{ statementLabel(s) }}</option>
        </select>
      </label>
      <label v-if="mode !== 'cancel'" class="flex min-w-0 flex-col gap-1 text-sm">{{ t(mode === 'release' ? 'propertyPayments.reservation.releaseEvidence' : 'propertyPayments.reservation.submissionEvidence') }}<input v-model="evidence" required :class="inputClass" data-field="cancel-evidence"></label>
    </fieldset>
    <p v-if="flow.error.value" role="alert" class="text-danger-600">{{ flow.error.value }}</p>
    <ActionBar :actions="actions()" />
  </form>
</template>

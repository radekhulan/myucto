<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import { useDemoMode } from '@/composables/useDemoMode'
import type { PayrollPaymentBatch } from '@/api/payrollPayments'
import { payrollBankSubmissionsApi, type PayrollBankSubmissionState } from '@/api/payrollBankSubmissions'
import { bankConnectionErrorMessage } from '@/utils/bankConnectionError'
import { apiErrorCode } from '@/api/errors'
import { formatDate, formatDateTime, formatMoneyMinor } from '@/composables/useFormat'
import { ICONS, btnFilled, btnOutline } from '@/components/ui/buttonStyles'

const props = defineProps<{ batches: PayrollPaymentBatch[]; canWrite: boolean }>()
const { t } = useI18n()
const auth = useAuthStore()
const { blockDemoMutation } = useDemoMode()
const canRead = computed(() => auth.canRead('payroll.payments') && auth.canRead('settings.bank_accounts'))
const maySubmit = computed(() => props.canWrite && auth.canWrite('payroll.payments') && auth.canWrite('settings.bank_accounts'))
const eligible = computed(() => props.batches.filter(batch => batch.channel === 'bank' && batch.currency_code === 'CZK' && batch.export_format === 'abo'))
const selectedId = ref<number | null>(null)
const selectedConnectionId = ref<number | null>(null)
const batch = computed(() => eligible.value.find(item => item.id === selectedId.value))
const state = ref<PayrollBankSubmissionState | null>(null)
const connection = computed(() => state.value?.connections.find(item => item.id === selectedConnectionId.value))
const uncertain = ref(new Set<number>())
const loading = ref(false)
const submitting = ref(false)
const error = ref('')
let version = 0
const blocked = computed(() => {
  if (!batch.value) return t('payroll_bank.choose')
  if (!maySubmit.value) return t('bank_connection.payment_permissions')
  if (uncertain.value.has(batch.value.id)) return t('bank_connection.payment_unknown')
  if (!state.value) return t('bank_connection.payment_status_required')
  if (state.value.submission) return t(`bank_connection.payment_status_${state.value.submission.status}`)
  const reason = state.value.blocked_reason
  if (reason) return t(reason === 'payment_order_date_in_past' ? 'bank_connection.error_date' : reason === 'payment_order_no_longer_payable' ? 'bank_connection.error_payable' : reason === 'payment_no_connection' ? 'bank_connection.payment_no_connection' : 'payroll_bank.unavailable')
  if (!connection.value) return t('bank_connection.payment_no_connection')
  return ''
})
async function load() {
  const current = ++version
  const id = selectedId.value
  state.value = null
  selectedConnectionId.value = null
  error.value = ''
  loading.value = false
  if (!id || !canRead.value) return
  loading.value = true
  try {
    const result = await payrollBankSubmissionsApi.status(id)
    if (current !== version) return
    state.value = result
    selectedConnectionId.value = result.connections[0]?.id ?? null
  } catch {
    if (current === version) error.value = t('bank_connection.load_failed')
  } finally {
    if (current === version) loading.value = false
  }
}
watch([selectedId, canRead], () => { void load() })
watch(eligible, items => {
  if (!items.some(item => item.id === selectedId.value)) selectedId.value = null
})
async function submit() {
  if (blocked.value || loading.value || submitting.value || blockDemoMutation()) return
  const current = batch.value
  const target = connection.value
  if (!current || !target || !window.confirm(t('payroll_bank.confirm', { reference: current.batch_reference, amount: formatMoneyMinor(current.declared_total_minor, current.currency_code), count: current.declared_item_count, provider: target.label }))) return
  submitting.value = true
  error.value = ''
  uncertain.value.add(current.id)
  try {
    const result = await payrollBankSubmissionsApi.submit(current.id, target.id)
    if (selectedId.value === current.id && state.value) state.value.submission = result.submission
  } catch (caught) {
    if (['bank_rate_limited', 'bank_connection_busy'].includes(apiErrorCode(caught))) uncertain.value.delete(current.id)
    const safeError = { response: { data: { error: { code: apiErrorCode(caught) } } } }
    error.value = bankConnectionErrorMessage(safeError, t, t('bank_connection.payment_unknown'))
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <section v-if="canRead && eligible.length" class="mb-4 space-y-3 rounded-xl border border-neutral-200 bg-surface p-4 shadow-sm">
    <h3 class="text-sm font-semibold">{{ t('payroll_bank.title') }}</h3>
    <p class="text-sm text-neutral-600">{{ t('payroll_bank.hint') }}</p>
    <label class="block text-sm">{{ t('payroll_bank.batch') }}
      <select v-model="selectedId" :disabled="submitting" class="mt-1 h-9 w-full rounded-md border border-neutral-300 bg-surface px-3" data-test="batch">
        <option :value="null">{{ t('payroll_bank.choose') }}</option>
        <option v-for="item in eligible" :key="item.id" :value="item.id">{{ item.batch_reference }} · {{ formatDate(item.planned_payment_date) }} · {{ formatMoneyMinor(item.declared_total_minor, item.currency_code) }}</option>
      </select>
    </label>
    <label v-if="state && state.connections.length" class="block text-sm">{{ t('bank_connection.payment_connection') }}
      <select v-model="selectedConnectionId" :disabled="loading || submitting || !!state.submission" class="mt-1 h-9 w-full rounded-md border border-neutral-300 bg-surface px-3">
        <option v-for="item in state.connections" :key="item.id" :value="item.id">{{ item.label }}</option>
      </select>
    </label>
    <div v-if="state?.submission" class="space-y-1 rounded-md border border-neutral-200 bg-neutral-50 px-3 py-2 text-sm" role="status">
      <p>{{ t(`bank_connection.payment_status_${state.submission.status}`) }}</p>
      <p v-if="state.submission.accepted_count != null">{{ t('bank_connection.payment_accepted_count', { count: state.submission.accepted_count }) }}</p>
      <p v-if="state.submission.rejected_count != null">{{ t('bank_connection.payment_rejected_count', { count: state.submission.rejected_count }) }}</p>
      <p v-if="state.submission.submitted_at">{{ formatDateTime(state.submission.submitted_at) }}</p>
    </div>
    <div class="flex flex-wrap gap-2">
      <button v-if="maySubmit && !state?.submission" type="button" :class="btnFilled('primary')" class="whitespace-nowrap" :disabled="!!blocked || loading || submitting" data-test="submit" @click="submit"><svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path :d="ICONS.send" /></svg>{{ t('bank_connection.payment_submit') }}</button>
      <button v-if="selectedId" type="button" :class="btnOutline('neutral')" class="whitespace-nowrap" :disabled="loading || submitting" data-test="refresh" @click="load"><svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path :d="ICONS.cycle" /></svg>{{ t('bank_connection.payment_refresh') }}</button>
    </div>
    <p v-if="blocked && !state?.submission" class="text-xs text-warning-700">{{ blocked }}</p>
    <p v-if="error" class="text-sm text-danger-600" role="alert">{{ error }}</p>
  </section>
</template>

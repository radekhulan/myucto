<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import { useDemoMode } from '@/composables/useDemoMode'
import { bankConnectionsApi, type BankConnection, type BankPaymentSubmission } from '@/api/bankConnections'
import type { PaymentOrderListItem } from '@/api/paymentOrders'
import { apiErrorCode, apiErrorMessage } from '@/api/errors'
import { bankConnectionErrorMessage } from '@/utils/bankConnectionError'
import { sameBankConnectionAccount } from '@/utils/bankConnectionAccount'
import { formatDate, formatDateTime, formatMoney } from '@/composables/useFormat'
import { ICONS, btnFilled, btnOutline } from '@/components/ui/buttonStyles'

const props = defineProps<{ orders: PaymentOrderListItem[]; modelValue: number | null }>()
const emit = defineEmits<{ 'update:modelValue': [value: number | null] }>()
const { t } = useI18n()
const auth = useAuthStore()
const { blockDemoMutation } = useDemoMode()
const selectedId = computed({ get: () => props.modelValue, set: value => emit('update:modelValue', value) })
const order = computed(() => props.orders.find(item => item.id === selectedId.value) ?? null)
const connections = ref<BankConnection[]>([])
const submission = ref<BankPaymentSubmission | null>(null)
const selectedConnectionId = ref<number | null>(null)
const loading = ref(false)
const submitting = ref(false)
const loaded = ref(false)
const uncertainOrders = ref(new Set<number>())
const error = ref('')
let loadVersion = 0
const canRead = computed(() => auth.canRead('settings.bank_accounts') && auth.canRead('purchase_invoices.payment_orders'))
const canSubmit = computed(() => auth.canWrite('settings.bank_accounts') && auth.canWrite('purchase_invoices.payment_orders'))
const matchingConnections = computed(() => {
  const current = order.value
  if (!current || current.currency !== 'CZK') return []
  return connections.value.filter(connection => connection.enabled && connection.has_token && connection.validated_at && ['2010', '5500', '0300', '0100', '2250'].includes(connection.account.bank_code || '') && connection.account.code === 'CZK' && sameBankConnectionAccount(connection.account, {
    account_number: current.payer_account_number, bank_code: current.payer_bank_code, iban: current.payer_iban,
  }))
})
const selectedConnection = computed(() => matchingConnections.value.find(c => c.id === selectedConnectionId.value) ?? null)
const disabledReason = computed(() => {
  if (!order.value) return t('bank_connection.payment_choose')
  if (order.value.currency !== 'CZK') return t('bank_connection.payment_czk_only')
  if (order.value.mark_paid) return t('bank_connection.payment_marked_paid')
  if (!canSubmit.value) return t('bank_connection.payment_permissions')
  if (uncertainOrders.value.has(order.value.id)) return t('bank_connection.payment_unknown')
  if (submission.value) return t(`bank_connection.payment_status_${submission.value.status}`)
  if (!loaded.value) return t('bank_connection.payment_status_required')
  if (!selectedConnection.value) return t('bank_connection.payment_no_connection')
  return ''
})
async function load() {
  const id = selectedId.value
  const version = ++loadVersion
  submission.value = null
  selectedConnectionId.value = null
  loaded.value = false
  error.value = ''
  loading.value = false
  if (!id || !canRead.value) return
  loading.value = true
  try {
    const [existing, available] = await Promise.all([bankConnectionsApi.submission(id), bankConnectionsApi.list()])
    if (version !== loadVersion) return
    submission.value = existing
    connections.value = available.connections.filter(connection => available.providers.some(provider => provider.code === connection.provider && provider.implemented && provider.capabilities.payment_order_submission))
    selectedConnectionId.value = matchingConnections.value[0]?.id ?? null
    loaded.value = true
  } catch (e) {
    if (version === loadVersion) error.value = apiErrorMessage(e, t('bank_connection.load_failed'))
  } finally {
    if (version === loadVersion) loading.value = false
  }
}
watch(selectedId, () => { void load() }, { immediate: true })
async function submit() {
  if (disabledReason.value || submitting.value || loading.value || blockDemoMutation()) return
  const current = order.value
  const connection = selectedConnection.value
  if (!current || !connection || !window.confirm(t('bank_connection.payment_confirm', {
    id: current.id, amount: formatMoney(current.total_amount, current.currency), count: current.item_count,
    account: `${current.payer_account_number || current.payer_iban || ''}${current.payer_bank_code ? ` / ${current.payer_bank_code}` : ''}`,
  }))) return
  submitting.value = true
  error.value = ''
  try {
    submission.value = await bankConnectionsApi.submit(current.id, connection.id)
  } catch (e) {
    if (!['bank_rate_limited', 'bank_connection_busy'].includes(apiErrorCode(e))) uncertainOrders.value.add(current.id)
    error.value = bankConnectionErrorMessage(e, t, t('bank_connection.payment_unknown'))
    try {
      submission.value = await bankConnectionsApi.submission(current.id)
      if (submission.value) error.value = ''
    } catch {
      loaded.value = false
    }
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <section v-if="canRead && orders.length" class="mb-4 p-4 space-y-3 bg-surface border border-neutral-200 rounded-lg shadow-sm">
    <h3 class="font-semibold text-sm">{{ t('bank_connection.payment_title') }}</h3>
    <p class="text-sm text-neutral-600">{{ t('bank_connection.payment_hint') }}</p>
    <label class="block text-sm">{{ t('bank_connection.payment_order') }}
      <select v-model="selectedId" :disabled="submitting" class="mt-1 w-full h-9 px-3 border border-neutral-300 rounded-md bg-surface">
        <option :value="null">{{ t('bank_connection.payment_choose') }}</option>
        <option v-for="item in orders" :key="item.id" :value="item.id">#{{ item.id }} · {{ formatDate(item.payment_date) }} · {{ formatMoney(item.total_amount, item.currency) }} · {{ item.payer_account_label || item.payer_account_number || item.payer_iban }}</option>
      </select>
    </label>
    <label v-if="matchingConnections.length > 1" class="block text-sm">{{ t('bank_connection.payment_connection') }}
      <select v-model="selectedConnectionId" :disabled="loading || submitting || !!submission" class="mt-1 w-full h-9 px-3 border border-neutral-300 rounded-md bg-surface">
        <option v-for="connection in matchingConnections" :key="connection.id" :value="connection.id">{{ connection.account.label || connection.provider }} ({{ connection.account.code }})</option>
      </select>
    </label>
    <div v-if="submission" class="rounded-md px-3 py-2 border text-sm space-y-1" :class="submission.status === 'accepted_awaiting_authorization' ? 'bg-warning-50 border-warning-500/40 text-warning-700' : 'bg-neutral-50 border-neutral-200 text-neutral-700'" role="status">
      <p>{{ t(`bank_connection.payment_status_${submission.status}`) }}</p>
      <p v-if="submission.accepted_count != null">{{ t('bank_connection.payment_accepted_count', { count: submission.accepted_count }) }}</p>
      <p v-if="submission.rejected_count != null">{{ t('bank_connection.payment_rejected_count', { count: submission.rejected_count }) }}</p>
      <p v-if="submission.submitted_at">{{ formatDateTime(submission.submitted_at) }}</p>
      <p v-if="submission.provider_reference" class="break-all">{{ t('bank_connection.payment_reference', { reference: submission.provider_reference }) }}</p>
    </div>
    <div class="flex flex-wrap gap-2">
      <button v-if="canSubmit && !submission" type="button" :class="btnFilled('primary')" :disabled="!!disabledReason || loading || submitting" @click="submit"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path :d="ICONS.send" /></svg>{{ submitting ? t('common.loading') : t('bank_connection.payment_submit') }}</button>
      <button v-if="selectedId" type="button" :class="btnOutline('neutral')" :disabled="loading || submitting" @click="load"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path :d="ICONS.cycle" /></svg>{{ t('bank_connection.payment_refresh') }}</button>
    </div>
    <p v-if="disabledReason && !submission" class="text-xs text-warning-700">{{ disabledReason }}</p>
    <p v-if="error" class="text-sm text-danger-600" role="alert">{{ error }}</p>
  </section>
</template>

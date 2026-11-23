<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import type { BankPaymentSubmissionItem } from '@/api/bankConnections'
import { formatMoney } from '@/composables/useFormat'
import { bankProviderReason } from '@/utils/bankConnectionError'

/**
 * Platby, které banka u nejasného nebo přerušeného odeslání založila nebo mohla založit.
 * Uživatel je má zkontrolovat nebo zrušit v George dřív, než připraví nový příkaz.
 * U odmítnutého odeslání (`rejected`) ukazuje odpověď banky ke každé platbě včetně důvodu odmítnutí.
 */
const props = defineProps<{ load: () => Promise<BankPaymentSubmissionItem[]>; rejected?: boolean }>()
const { t, te } = useI18n()
const items = ref<BankPaymentSubmissionItem[]>([])
const failed = ref(false)
const tone = computed(() => props.rejected ? 'border-danger-500/40 bg-danger-50 text-danger-700' : 'border-warning-500/40 bg-warning-50 text-warning-700')
onMounted(async () => {
  try {
    items.value = (await props.load()).filter(item => item.status !== 'planned' && (props.rejected || item.status !== 'rejected'))
  } catch {
    failed.value = true
  }
})
function itemState(item: BankPaymentSubmissionItem): string {
  if (item.status === 'rejected') {
    const reason = bankProviderReason(item.provider_error, t, te)
    return reason ? t('bank_connection.payment_item_rejected', { reason }) : t('bank_connection.payment_item_rejected_unknown')
  }
  if (!item.provider_sign_id) return t('bank_connection.payment_item_unconfirmed')
  const id = t('bank_connection.payment_item_bank_id', { id: item.provider_sign_id })
  return props.rejected ? `${id} · ${t('bank_connection.payment_item_accepted_unsigned')}` : id
}
</script>

<template>
  <div class="rounded-md border px-3 py-2 text-sm space-y-2" :class="tone" role="alert" data-test="submission-items">
    <p class="font-medium">{{ rejected ? t('bank_connection.payment_items_result') : t('bank_connection.payment_items_review') }}</p>
    <p v-if="failed">{{ t('bank_connection.payment_items_failed') }}</p>
    <p v-else-if="!items.length">{{ t('bank_connection.payment_items_none') }}</p>
    <ul v-else class="divide-y divide-warning-500/20">
      <li v-for="item in items" :key="item.line_no" class="py-1 flex flex-wrap gap-x-3 gap-y-0.5" :data-status="item.status" data-test="submission-item">
        <span class="font-mono">#{{ item.line_no }}</span>
        <span class="whitespace-nowrap">{{ formatMoney(Number(item.amount), 'CZK') }}</span>
        <span class="font-mono break-all">{{ item.creditor_account || item.creditor_iban }}</span>
        <span v-if="item.variable_symbol" class="whitespace-nowrap">{{ t('bank_connection.payment_item_vs', { vs: item.variable_symbol }) }}</span>
        <span class="break-all" data-test="submission-item-state">{{ itemState(item) }}</span>
      </li>
    </ul>
  </div>
</template>

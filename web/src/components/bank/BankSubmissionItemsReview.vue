<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import type { BankPaymentSubmissionItem } from '@/api/bankConnections'
import { formatMoney } from '@/composables/useFormat'

/**
 * Platby, které banka u nejasného nebo přerušeného odeslání založila nebo mohla založit.
 * Uživatel je má zkontrolovat nebo zrušit v George dřív, než připraví nový příkaz.
 */
const props = defineProps<{ load: () => Promise<BankPaymentSubmissionItem[]> }>()
const { t } = useI18n()
const items = ref<BankPaymentSubmissionItem[]>([])
const failed = ref(false)
onMounted(async () => {
  try {
    items.value = (await props.load()).filter(item => item.status !== 'planned' && item.status !== 'rejected')
  } catch {
    failed.value = true
  }
})
</script>

<template>
  <div class="rounded-md border border-warning-500/40 bg-warning-50 px-3 py-2 text-sm text-warning-700 space-y-2" role="alert" data-test="submission-items">
    <p class="font-medium">{{ t('bank_connection.payment_items_review') }}</p>
    <p v-if="failed">{{ t('bank_connection.payment_items_failed') }}</p>
    <p v-else-if="!items.length">{{ t('bank_connection.payment_items_none') }}</p>
    <ul v-else class="divide-y divide-warning-500/20">
      <li v-for="item in items" :key="item.line_no" class="py-1 flex flex-wrap gap-x-3 gap-y-0.5" data-test="submission-item">
        <span class="font-mono">#{{ item.line_no }}</span>
        <span class="whitespace-nowrap">{{ formatMoney(Number(item.amount), 'CZK') }}</span>
        <span class="font-mono break-all">{{ item.creditor_account || item.creditor_iban }}</span>
        <span v-if="item.variable_symbol" class="whitespace-nowrap">{{ t('bank_connection.payment_item_vs', { vs: item.variable_symbol }) }}</span>
        <span class="break-all">{{ item.provider_sign_id ? t('bank_connection.payment_item_bank_id', { id: item.provider_sign_id }) : t('bank_connection.payment_item_unconfirmed') }}</span>
      </li>
    </ul>
  </div>
</template>

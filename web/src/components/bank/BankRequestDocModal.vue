<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { formatMoney, formatDate } from '@/composables/useFormat'
import type { BankTransactionActions } from '@/composables/useBankTransactionActions'
import DateInput from '@/components/ui/DateInput.vue'

const props = defineProps<{ actions: BankTransactionActions }>()
const { t } = useI18n()

const { requestDocTx, requestDocDeadline, requestingDoc, submitRequestDoc, closeRequestDoc } = props.actions
</script>

<template>
  <div v-if="requestDocTx" class="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
    <div class="bg-surface rounded-xl shadow-lg max-w-md w-full p-5">
      <h3 class="text-lg font-semibold mb-1">{{ t('bank.document_request.title') }}</h3>
      <p class="text-xs text-neutral-500 mb-3">
        {{ formatMoney(Math.abs(requestDocTx.amount), requestDocTx.currency ?? 'CZK') }} ·
        {{ formatDate(requestDocTx.posted_at) }}
        <span v-if="requestDocTx.counterparty_name"> · {{ requestDocTx.counterparty_name }}</span>
      </p>
      <p class="text-sm text-neutral-600 mb-3">{{ t('bank.document_request.hint') }}</p>
      <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('document_requests.deadline') }}</label>
      <DateInput v-model="requestDocDeadline" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm mb-4" />
      <div class="flex justify-end gap-2">
        <button @click="closeRequestDoc" class="cursor-pointer px-3 h-9 text-sm border border-neutral-300 rounded-md hover:bg-neutral-50">{{ t('common.cancel') }}</button>
        <button @click="submitRequestDoc" :disabled="requestingDoc"
          class="cursor-pointer px-4 h-9 text-sm bg-primary-600 hover:bg-primary-700 disabled:bg-neutral-300 text-white font-medium rounded-md">
          {{ requestingDoc ? '…' : t('bank.document_request.submit') }}
        </button>
      </div>
    </div>
  </div>
</template>

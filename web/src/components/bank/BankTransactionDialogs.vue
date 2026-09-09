<script setup lang="ts">
import { computed } from 'vue'
import { RouterLink } from 'vue-router'
import { useI18n } from 'vue-i18n'
import Modal from '@/components/ui/Modal.vue'
import { formatMoney, formatDate } from '@/composables/useFormat'
import { formatAccountNumber } from '@/utils/bankAccount'
import { BTN_BASE, FILLED, OUTLINE, ICONS } from '@/components/ui/buttonStyles'
import type { BankTransactionActions } from '@/composables/useBankTransactionActions'
import type { BankTransaction } from '@/api/bank'
const props = defineProps<{ actions: BankTransactionActions; fallbackCurrency?: string | null; ownAccount?: string | null; ownBankCode?: string | null }>()
const { t } = useI18n()
const { textDetail, ignoreTarget, ignoreNote, ignoring, ignoreError, closeIgnore, confirmIgnore,
  unmatchTarget, unmatching, unmatchError, closeUnmatch, confirmUnmatch } = props.actions
function statusLabel(status: string) { return t(`bank.match_status.${status}`) }
function statusBadge(status: string) {
  return status === 'unmatched' || status === 'ignored' ? 'bg-neutral-100 text-neutral-600' : 'bg-success-50 text-success-600'
}
const transactionDetailFields = computed(() => {
  const tx = textDetail.value as (BankTransaction & { account_number?: string; bank_code?: string | null }) | null
  if (!tx) return []
  return [
    { label: t('bank.transaction_date'), value: formatDate(tx.posted_at) },
    { label: t('bank.counterparty'), value: tx.counterparty_name },
    { label: t('bank.counterparty_account'), value: formatAccountNumber(tx.counterparty_account, tx.counterparty_bank) },
    { label: t('bank.own_account'), value: formatAccountNumber(tx.account_number ?? props.ownAccount, tx.bank_code ?? props.ownBankCode) },
    { label: t('bank.variable_symbol'), value: tx.variable_symbol },
    { label: t('bank.constant_symbol'), value: tx.constant_symbol },
    { label: t('bank.specific_symbol'), value: tx.specific_symbol },
    { label: t('bank.bank_reference'), value: tx.bank_ref },
    { label: t('bank.transaction_balance'), value: tx.balance != null ? formatMoney(tx.balance, tx.currency ?? props.fallbackCurrency ?? 'CZK') : null },
  ].filter(field => field.value != null && field.value !== '')
})
</script>
<template>
    <Modal v-if="textDetail" :title="t('bank.show_transaction_text')" width-class="max-w-xl" @close="textDetail = null">
      <div class="flex flex-wrap items-center justify-between gap-3 mb-5">
        <p class="text-xl font-semibold font-mono" :class="textDetail.amount > 0 ? 'text-success-600' : 'text-danger-500'">
          {{ textDetail.amount > 0 ? '+' : '' }}{{ formatMoney(textDetail.amount, textDetail.currency ?? fallbackCurrency ?? 'CZK') }}
        </p>
        <span class="text-xs px-2 py-0.5 rounded font-medium" :class="statusBadge(textDetail.match_status)">
          {{ statusLabel(textDetail.match_status) }}
        </span>
      </div>
      <dl class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm mb-5">
        <div v-for="field in transactionDetailFields" :key="field.label" class="min-w-0">
          <dt class="text-neutral-500 mb-1">{{ field.label }}</dt>
          <dd class="text-neutral-700 whitespace-pre-wrap break-words">{{ field.value }}</dd>
        </div>
      </dl>
      <dl class="space-y-4 text-sm">
        <div v-if="textDetail.matched_invoices?.length || textDetail.matched_invoice_id || textDetail.matched_purchase_invoice_id">
          <dt class="font-medium mb-1">{{ t('bank.invoice') }}</dt>
          <dd class="space-y-1 break-words">
            <template v-if="textDetail.matched_invoices?.length">
              <div v-for="invoice in textDetail.matched_invoices" :key="invoice.invoice_id">
                <RouterLink :to="`/invoices/${invoice.invoice_id}`" class="text-primary-600 hover:underline">
                  {{ invoice.varsymbol || `#${invoice.invoice_id}` }}
                </RouterLink>
                <span v-if="invoice.client_name" class="text-neutral-500"> · {{ invoice.client_name }}</span>
              </div>
            </template>
            <div v-else-if="textDetail.matched_invoice_id">
              <RouterLink :to="`/invoices/${textDetail.matched_invoice_id}`" class="text-primary-600 hover:underline">
                {{ textDetail.matched_varsymbol || `#${textDetail.matched_invoice_id}` }}
              </RouterLink>
              <span v-if="textDetail.matched_client_name" class="text-neutral-500"> · {{ textDetail.matched_client_name }}</span>
            </div>
            <div v-if="textDetail.matched_purchase_invoice_id">
              <RouterLink :to="`/purchase-invoices/${textDetail.matched_purchase_invoice_id}`" class="text-primary-600 hover:underline">
                {{ textDetail.matched_purchase_ref || `#${textDetail.matched_purchase_invoice_id}` }}
              </RouterLink>
              <span v-if="textDetail.matched_vendor_name" class="text-neutral-500"> · {{ textDetail.matched_vendor_name }}</span>
            </div>
          </dd>
        </div>
        <div v-if="textDetail.description">
          <dt class="font-medium mb-1">{{ t('bank.transaction_description') }}</dt>
          <dd class="text-neutral-700 whitespace-pre-wrap break-words">{{ textDetail.description }}</dd>
        </div>
        <div v-if="textDetail.match_status === 'ignored' && textDetail.ignore_note">
          <dt class="font-medium mb-1">{{ t('bank.ignore_note_label') }}</dt>
          <dd class="text-neutral-700 whitespace-pre-wrap break-words">{{ textDetail.ignore_note }}</dd>
        </div>
      </dl>
      <template #footer>
        <div class="flex flex-wrap justify-end gap-2">
        <button type="button" @click="textDetail = null"
          :class="[BTN_BASE, OUTLINE.neutral]">
          <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>{{ t('common.close') }}</button>
        </div>
      </template>
    </Modal>

    <Modal v-if="unmatchTarget" :title="t(unmatchTarget.match_status === 'ignored' ? 'bank.unignore' : 'bank.unmatch')" width-class="max-w-md" @close="closeUnmatch">
      <p class="text-sm mb-3">{{ t(unmatchTarget.match_status === 'ignored' ? 'bank.unignore_confirm' : 'bank.unmatch_confirm') }}</p>
      <p class="text-xs text-neutral-500">
        {{ formatDate(unmatchTarget.posted_at) }} · {{ formatMoney(unmatchTarget.amount, unmatchTarget.currency ?? fallbackCurrency ?? 'CZK') }}
        <span v-if="unmatchTarget.counterparty_name"> · {{ unmatchTarget.counterparty_name }}</span>
      </p>
      <div v-if="unmatchTarget.ignore_note" class="mt-4 text-sm">
        <p class="font-medium mb-1">{{ t('bank.ignore_note_label') }}</p>
        <p class="text-neutral-700 whitespace-pre-wrap break-words">{{ unmatchTarget.ignore_note }}</p>
        <p class="text-neutral-500 mt-2">{{ t('bank.unmatch_note_removed') }}</p>
      </div>
      <p v-if="unmatchError" role="alert" class="text-sm text-danger-600 mt-2">{{ unmatchError }}</p>
      <template #footer>
        <div class="flex flex-wrap justify-end gap-2">
        <button type="button" :disabled="unmatching" @click="closeUnmatch"
          :class="[BTN_BASE, OUTLINE.neutral]">
          <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>{{ t('common.cancel') }}</button>
        <button type="button" :disabled="unmatching" @click="confirmUnmatch"
          :class="[BTN_BASE, FILLED.danger]">
          <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.uturn" /></svg>
          {{ unmatching ? t('common.saving') : t(unmatchTarget.match_status === 'ignored' ? 'bank.unignore' : 'bank.unmatch') }}
        </button>
        </div>
      </template>
    </Modal>

    <Modal v-if="ignoreTarget" :title="t('bank.ignore')" width-class="max-w-md" @close="closeIgnore">
      <form id="ignore-transaction" @submit.prevent="confirmIgnore">
        <p class="text-sm mb-3">{{ t('bank.ignore_confirm') }}</p>
        <p class="text-xs text-neutral-500 mb-4">
          {{ formatDate(ignoreTarget.posted_at) }} · {{ formatMoney(ignoreTarget.amount, ignoreTarget.currency ?? fallbackCurrency ?? 'CZK') }}
          <span v-if="ignoreTarget.counterparty_name"> · {{ ignoreTarget.counterparty_name }}</span>
        </p>
        <label for="ignore-note" class="block text-sm font-medium mb-1">{{ t('bank.ignore_note') }}</label>
        <textarea id="ignore-note" v-model="ignoreNote" :disabled="ignoring" maxlength="1000" rows="3"
          class="w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm" />
        <p v-if="ignoreError" role="alert" class="text-sm text-danger-600 mt-2">{{ ignoreError }}</p>
      </form>
      <template #footer>
        <div class="flex flex-wrap justify-end gap-2">
        <button type="button" :disabled="ignoring" @click="closeIgnore"
          :class="[BTN_BASE, OUTLINE.neutral]">
          <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>{{ t('common.cancel') }}</button>
        <button type="submit" form="ignore-transaction" :disabled="ignoring"
          :class="[BTN_BASE, FILLED.primary]">
          <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>
          {{ ignoring ? t('common.saving') : t('bank.ignore') }}
        </button>
        </div>
      </template>
    </Modal>

</template>

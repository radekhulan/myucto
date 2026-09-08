<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import type { BankReconciliationCandidate } from '@/types/bankReconciliation'
import { formatDate, formatMoney } from '@/composables/useFormat'
import { ICONS, btnFilled } from '@/components/ui/buttonStyles'

withDefaults(defineProps<{
  candidates: BankReconciliationCandidate[]
  busy: boolean
  retrySeconds?: number
}>(), { retrySeconds: 0 })
defineEmits<{ confirm: [] }>()
const { t } = useI18n()
</script>

<template>
  <section class="rounded-md border border-warning-300 bg-warning-50 p-3 space-y-3" data-testid="reconciliation-panel">
    <div class="space-y-1">
      <h4 class="font-medium text-warning-900">{{ t('bank_connection.reconciliation_title') }}</h4>
      <p class="text-sm text-warning-800">{{ t('bank_connection.reconciliation_intro') }}</p>
    </div>
    <ul class="space-y-2">
      <li v-for="candidate in candidates" :key="candidate.confirmation_key" class="flex flex-wrap items-center justify-between gap-2 rounded border border-warning-200 bg-surface px-3 py-2 text-sm">
        <div class="min-w-0 space-y-1">
          <p>{{ formatDate(candidate.posted_at) }} · <strong class="font-mono">{{ formatMoney(Number(candidate.amount), candidate.currency) }}</strong></p>
          <dl class="grid grid-cols-[auto_1fr] gap-x-2 text-xs text-neutral-600">
            <dt>{{ t('bank_connection.reconciliation_new_description') }}</dt><dd class="break-words">{{ candidate.description || t('bank_connection.reconciliation_not_provided') }}</dd>
            <dt>{{ t('bank_connection.reconciliation_existing_description') }}</dt><dd class="break-words">{{ candidate.existing_description || t('bank_connection.reconciliation_not_provided') }}</dd>
            <template v-if="candidate.variable_symbol || candidate.existing_variable_symbol">
              <dt>{{ t('bank_connection.reconciliation_variable_symbols') }}</dt><dd class="font-mono break-all">{{ candidate.variable_symbol || t('bank_connection.reconciliation_not_provided') }} / {{ candidate.existing_variable_symbol || t('bank_connection.reconciliation_not_provided') }}</dd>
            </template>
            <template v-if="candidate.counterparty_account || candidate.existing_counterparty_account">
              <dt>{{ t('bank_connection.reconciliation_accounts') }}</dt><dd class="font-mono break-all">{{ candidate.counterparty_account || t('bank_connection.reconciliation_not_provided') }} / {{ candidate.existing_counterparty_account || t('bank_connection.reconciliation_not_provided') }}</dd>
            </template>
          </dl>
        </div>
        <RouterLink :to="{ name: 'bank-detail', params: { id: candidate.existing_statement_id }, query: { tx: String(candidate.existing_transaction_id) } }" target="_blank" rel="noopener noreferrer" class="font-medium text-primary-600 underline">
          {{ t('bank_connection.reconciliation_open_statement') }}
        </RouterLink>
      </li>
    </ul>
    <p v-if="retrySeconds > 0" class="text-xs text-warning-800">
      {{ t('bank_connection.reconciliation_wait', { seconds: retrySeconds }) }}
    </p>
    <button type="button" :class="btnFilled('success')" :disabled="busy || retrySeconds > 0" data-testid="confirm-reconciliation" @click="$emit('confirm')">
      <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path :d="ICONS.check" /></svg>
      {{ t('bank_connection.reconciliation_confirm', { count: candidates.length }) }}
    </button>
  </section>
</template>

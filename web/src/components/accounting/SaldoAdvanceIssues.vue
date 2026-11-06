<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import type { SaldoAdvanceDeductionIssue } from '@/api/accounting'
import { formatMoney } from '@/composables/useFormat'

/**
 * Varování bloku zálohového účtu: konečné faktury, jejichž čerpání záloh přesahuje stranu
 * čerpání v deníku (faktura přeúčtovaná s menším odpočtem, převod nad rámec zápisu).
 */
defineProps<{
  issues: SaldoAdvanceDeductionIssue[]
  docType: 'invoice' | 'purchase_invoice'
}>()

const { t } = useI18n()
</script>

<template>
  <div class="px-4 py-2 text-xs text-warning-700 bg-warning-50 border-t border-warning-500/30 space-y-1" data-testid="saldo-advance-issues">
    <p>{{ t('accounting.saldo_settle.advance_issues_hint') }}</p>
    <ul class="flex flex-wrap gap-x-3 gap-y-1">
      <li v-for="i in issues" :key="i.doc_id">
        <RouterLink :to="{ name: docType === 'purchase_invoice' ? 'purchase-invoice-detail' : 'invoice-detail', params: { id: i.doc_id } }"
          class="font-mono text-primary-600 hover:underline">{{ i.doc_no }}</RouterLink>
        {{ t('accounting.saldo_settle.advance_issue_excess', { amount: formatMoney(i.excess) }) }}
      </li>
    </ul>
  </div>
</template>

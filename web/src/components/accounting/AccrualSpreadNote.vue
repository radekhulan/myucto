<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import type { StatementAccrualSpread } from '@/api/accounting'

defineProps<{ spread: StatementAccrualSpread; format: (value: number) => string }>()
const { t } = useI18n()
</script>

<template>
  <p v-if="spread.applied" class="mb-3 rounded-md border border-warning-500/30 bg-warning-50 px-3 py-2 text-xs text-warning-800"
    data-test="accrual-spread-note">
    <strong>{{ t('accounting.accrual_spread.note_title') }}</strong>
    {{ t('accounting.accrual_spread.note', {
      prepaid: format(spread.prepaid_expense),
      deferred: format(spread.deferred_revenue),
      documents: spread.documents,
    }) }}
  </p>
  <p v-else class="mb-3 rounded-md border border-neutral-200 bg-neutral-50 px-3 py-2 text-xs text-neutral-600"
    data-test="accrual-spread-skipped">
    {{ t('accounting.accrual_spread.skipped_dimension') }}
  </p>
</template>

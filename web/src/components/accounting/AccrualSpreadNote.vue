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
      depreciation: format(spread.depreciation ?? 0),
      assets: spread.depreciation_assets ?? 0,
    }) }}
    <template v-if="(spread.later_dated?.entries ?? 0) > 0">
      <span data-test="accrual-spread-later-dated">{{ t('accounting.accrual_spread.note_later_dated', {
        entries: spread.later_dated!.entries,
        deferred: format(spread.later_dated!.deferred_revenue),
        prepaid: format(spread.later_dated!.prepaid_expense),
        depreciation: format(spread.later_dated!.depreciation),
      }) }}</span>
    </template>
    <span v-if="spread.vh_effect !== undefined" data-test="accrual-spread-vh-effect">
      {{ t('accounting.accrual_spread.note_vh_effect', { amount: format(spread.vh_effect) }) }}
    </span>
  </p>
  <p v-else class="mb-3 rounded-md border border-neutral-200 bg-neutral-50 px-3 py-2 text-xs text-neutral-600"
    data-test="accrual-spread-skipped">
    {{ t('accounting.accrual_spread.skipped_dimension') }}
  </p>
</template>

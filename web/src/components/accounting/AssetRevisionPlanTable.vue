<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import type { DepreciationPlan } from '@/api/assets'
import { formatMoney, formatMonth } from '@/composables/useFormat'

const props = defineProps<{ plan: DepreciationPlan }>()
const { t } = useI18n()
const showMonths = ref(false)
const kinds = ['tax', 'accounting'] as const
const months = computed(() => props.plan.accounting.flatMap(row => (row.months ?? []).map(month => ({ ...month, fiscal_year: row.fiscal_year }))))
</script>

<template>
  <div class="space-y-4">
    <p v-if="plan.asset_summary.effective_acc_useful_life_months != null" class="text-sm">{{ t('accounting.assets.revision.total_life', { months: plan.asset_summary.effective_acc_useful_life_months }) }}</p>
    <div v-for="kind in kinds" :key="kind" class="overflow-x-auto rounded-lg border border-neutral-200">
      <table class="min-w-full text-sm">
        <caption class="px-3 py-2 text-left font-medium">{{ t(`accounting.assets.revision.annual_${kind}`) }}</caption>
        <thead class="bg-neutral-50 text-xs text-neutral-500"><tr>
          <th scope="col" class="px-3 py-2 text-left">{{ t('accounting.assets.revision.year') }}</th>
          <th scope="col" class="px-3 py-2 text-right">{{ t('accounting.assets.revision.amount') }}</th>
          <th scope="col" class="px-3 py-2 text-right">{{ t('accounting.assets.revision.residual') }}</th>
        </tr></thead>
        <tbody class="divide-y divide-neutral-100"><tr v-for="row in plan[kind]" :key="row.fiscal_year" :data-year="row.fiscal_year">
          <th scope="row" class="px-3 py-2 text-left font-medium">{{ row.fiscal_year }}</th>
          <td class="px-3 py-2 text-right tabular-nums whitespace-nowrap">{{ formatMoney(row.amount) }}</td>
          <td class="px-3 py-2 text-right tabular-nums whitespace-nowrap">{{ formatMoney(row.residual_end) }}</td>
        </tr></tbody>
      </table>
    </div>
    <details v-if="months.length" @toggle="showMonths = ($event.target as HTMLDetailsElement).open">
      <summary data-testid="revision-monthly-toggle" class="cursor-pointer text-sm font-medium text-primary-700">{{ t('accounting.assets.revision.monthly') }}</summary>
      <div v-if="showMonths" class="mt-2 overflow-x-auto rounded-lg border border-neutral-200">
        <table class="min-w-full text-sm">
          <thead class="bg-neutral-50 text-xs text-neutral-500"><tr>
            <th scope="col" class="px-3 py-2 text-left">{{ t('accounting.assets.revision.year') }}</th>
            <th scope="col" class="px-3 py-2 text-left">{{ t('accounting.assets.revision.month') }}</th>
            <th scope="col" class="px-3 py-2 text-right">{{ t('accounting.assets.revision.amount') }}</th>
          </tr></thead>
          <tbody class="divide-y divide-neutral-100"><tr v-for="row in months" :key="row.month" :data-month="row.month">
            <td class="px-3 py-2">{{ row.fiscal_year }}</td>
            <th scope="row" class="px-3 py-2 text-left font-medium whitespace-nowrap">{{ formatMonth(row.month) }}</th>
            <td class="px-3 py-2 text-right tabular-nums whitespace-nowrap">{{ formatMoney(row.amount) }}</td>
          </tr></tbody>
        </table>
      </div>
    </details>
  </div>
</template>

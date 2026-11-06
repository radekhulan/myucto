<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import type { ImportedDepreciationPlan } from '@/api/assets'
import { formatDateTime, formatMoneyMinor, formatMonth } from '@/composables/useFormat'

const props = defineProps<{ plan: ImportedDepreciationPlan; originalUsefulLife: number | null }>()
const { t } = useI18n()
const showOrigin = ref(false)
</script>

<template>
  <div class="mb-4 space-y-4">
    <section v-if="props.plan.revisions?.length" data-testid="asset-plan-revisions" class="rounded-lg border border-neutral-200 bg-surface">
      <h2 class="px-4 py-3 text-sm font-semibold">{{ t('accounting.assets.revision.history') }}</h2>
      <div class="overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead class="bg-neutral-50 text-xs text-neutral-500"><tr>
            <th scope="col" class="px-3 py-2 text-left">{{ t('accounting.assets.revision.number') }}</th>
            <th scope="col" class="px-3 py-2 text-left">{{ t('accounting.assets.revision.effective_from') }}</th>
            <th scope="col" class="px-3 py-2 text-right">{{ t('accounting.assets.revision.remaining_months') }}</th>
            <th scope="col" class="px-3 py-2 text-right">{{ t('accounting.assets.revision.remaining_amount') }}</th>
            <th scope="col" class="px-3 py-2 text-left">{{ t('accounting.assets.revision.reason') }}</th>
            <th scope="col" class="px-3 py-2 text-left">{{ t('accounting.assets.revision.author') }}</th>
            <th scope="col" class="px-3 py-2 text-left">{{ t('accounting.assets.revision.created_at') }}</th>
          </tr></thead>
          <tbody class="divide-y divide-neutral-100"><tr v-for="revision in props.plan.revisions" :key="revision.revision">
            <th scope="row" class="px-3 py-2 text-left font-medium" :title="t('accounting.assets.revision.fiscal_start', { month: revision.fiscal_start_month })">{{ revision.revision }}</th>
            <td class="px-3 py-2 whitespace-nowrap">{{ formatMonth(revision.effective_from) }}</td>
            <td class="px-3 py-2 text-right tabular-nums">{{ revision.remaining_months }}</td>
            <td class="px-3 py-2 text-right tabular-nums whitespace-nowrap">{{ formatMoneyMinor(revision.remaining_amount_cents) }}</td>
            <td class="min-w-48 px-3 py-2 whitespace-pre-wrap">{{ revision.reason }}</td>
            <td class="px-3 py-2 whitespace-nowrap">{{ t('accounting.assets.revision.user', { id: revision.user_id }) }}</td>
            <td class="px-3 py-2 whitespace-nowrap">{{ formatDateTime(revision.created_at) }}</td>
          </tr></tbody>
        </table>
      </div>
    </section>
    <details data-testid="asset-plan-origin" class="rounded-lg border border-neutral-200 bg-surface p-4" @toggle="showOrigin = ($event.target as HTMLDetailsElement).open">
      <summary class="cursor-pointer text-sm font-semibold">{{ t('accounting.assets.revision.origin') }}</summary>
      <div v-if="showOrigin" class="mt-3 grid gap-4 md:grid-cols-2">
        <p class="text-sm text-neutral-500 md:col-span-2">{{ t('accounting.assets.revision.origin_hint') }}</p>
        <p v-if="originalUsefulLife !== null" class="text-sm md:col-span-2">{{ t('accounting.assets.revision.original_life', { months: originalUsefulLife }) }}</p>
        <div class="overflow-x-auto rounded-lg border border-neutral-200">
          <table class="min-w-full text-sm">
            <caption class="px-3 py-2 text-left font-medium">{{ t('accounting.assets.revision.monthly') }}</caption>
            <thead class="bg-neutral-50 text-xs text-neutral-500"><tr><th scope="col" class="px-3 py-2 text-left">{{ t('accounting.assets.revision.month') }}</th><th scope="col" class="px-3 py-2 text-right">{{ t('accounting.assets.revision.amount') }}</th></tr></thead>
            <tbody class="divide-y divide-neutral-100"><tr v-for="row in props.plan.accounting" :key="row.month"><th scope="row" class="px-3 py-2 text-left font-medium">{{ formatMonth(row.month) }}</th><td class="px-3 py-2 text-right tabular-nums whitespace-nowrap">{{ formatMoneyMinor(row.amount_cents) }}</td></tr></tbody>
          </table>
        </div>
        <div class="overflow-x-auto rounded-lg border border-neutral-200">
          <table class="min-w-full text-sm">
            <caption class="px-3 py-2 text-left font-medium">{{ t('accounting.assets.revision.annual_tax') }}</caption>
            <thead class="bg-neutral-50 text-xs text-neutral-500"><tr><th scope="col" class="px-3 py-2 text-left">{{ t('accounting.assets.revision.year') }}</th><th scope="col" class="px-3 py-2 text-right">{{ t('accounting.assets.revision.amount') }}</th></tr></thead>
            <tbody class="divide-y divide-neutral-100"><tr v-for="row in props.plan.tax" :key="row.fiscal_year"><th scope="row" class="px-3 py-2 text-left font-medium">{{ row.fiscal_year }}</th><td class="px-3 py-2 text-right tabular-nums whitespace-nowrap">{{ formatMoneyMinor(row.amount_cents) }}</td></tr></tbody>
          </table>
        </div>
      </div>
    </details>
  </div>
</template>

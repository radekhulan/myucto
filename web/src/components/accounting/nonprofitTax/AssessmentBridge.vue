<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import type { TaxResult } from '@/api/nonprofitTax'
import { activityName, centsText } from '@/utils/nonprofitTaxDraft'
defineProps<{ result: TaxResult }>()
const { t } = useI18n()
const totals = ['accounting_result_cents', 'excluded_revenue_cents', 'cost_addbacks_cents', 'assessed_result_cents'] as const
</script>
<template>
  <section class="space-y-4">
    <h2 class="text-lg font-semibold">{{ t('nonprofitTax.bridge') }}</h2>
    <p class="text-sm text-neutral-600">{{ t('nonprofitTax.scopeHint') }}</p>
    <p v-if="result.assessment_method === 'documented_manual'" data-test="manual-result" class="rounded-lg border border-warning-300 bg-warning-50 p-4 text-sm">{{ t('nonprofitTax.manual.resultNotice') }}</p>
    <p class="text-sm text-neutral-600">{{ t('nonprofitTax.deductibleAfterFunding') }}</p>
    <dl class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4"><div v-for="key in totals" :key="key" class="rounded-lg border border-neutral-200 bg-surface p-4"><dt class="text-sm text-neutral-600">{{ t(`nonprofitTax.totals.${key}`) }}</dt><dd class="mt-2 font-mono text-xl font-semibold tabular-nums" :data-test="key">{{ centsText(result[key]) }} {{ t('nonprofitTax.czk') }}</dd></div></dl>
    <div v-if="result.assessment_method !== 'documented_manual' && result.activities.length" class="overflow-x-auto rounded-lg border border-neutral-200"><table class="w-full text-sm"><thead class="bg-neutral-50"><tr><th class="p-3 text-left">{{ t('nonprofitTax.activity') }}</th><th class="p-3 text-left">{{ t('nonprofitTax.cohort') }}</th><th class="p-3 text-left">{{ t('nonprofitTax.finalRegime') }}</th><th class="p-3 text-right">{{ t('nonprofitTax.revenue') }}</th><th class="p-3 text-right">{{ t('nonprofitTax.cost') }}</th><th class="p-3 text-right">{{ t('nonprofitTax.contribution') }}</th></tr></thead><tbody><tr v-for="row in result.activities" :key="row.activity_kind_id + row.cohort" class="border-t border-neutral-200"><td class="p-3">{{ activityName(result.rows.find(source => String(source.activity_kind_id) === row.activity_kind_id), row.activity_kind_id) }}</td><td class="p-3">{{ row.cohort }}</td><td class="p-3">{{ t(`nonprofitTax.regimes.${row.regime}`) }}</td><td class="p-3 text-right font-mono">{{ centsText(row.revenue_cents) }}</td><td class="p-3 text-right font-mono">{{ centsText(row.cost_cents) }}</td><td class="p-3 text-right font-mono">{{ centsText(row.contribution_cents) }}</td></tr></tbody></table></div>
    <div v-for="usage in result.funding_usage" :key="usage.anchor" class="rounded-lg border border-neutral-200 p-4 text-sm"><h3 class="font-semibold">{{ t('nonprofitTax.fundingSource') }}: {{ usage.anchor }}</h3><dl class="mt-2 grid gap-2 sm:grid-cols-4"><div v-for="key in (['net_source_cents', 'prior_drawdown_cents', 'drawdown_cents', 'remaining_cents'] as const)" :key="key"><dt>{{ t(`nonprofitTax.usage.${key}`) }}</dt><dd class="font-mono">{{ centsText(usage[key]) }} {{ t('nonprofitTax.czk') }}</dd></div></dl></div>
    <section v-if="result.external_adjustments.length" class="rounded-lg border border-warning-300 bg-warning-50 p-4 space-y-3"><h3 class="font-semibold">{{ t('nonprofitTax.externalAdjustments') }}</h3><p class="text-sm">{{ t('nonprofitTax.externalHint') }}</p><article v-for="item in result.external_adjustments" :key="item.funding_anchor" class="text-sm"><p class="font-semibold">{{ item.funding_anchor }} · {{ centsText(item.adjustment_cents) }} {{ t('nonprofitTax.czk') }}</p><p>{{ item.legal_basis }}</p><p class="whitespace-pre-wrap">{{ item.evidence }}</p></article></section>
  </section>
</template>

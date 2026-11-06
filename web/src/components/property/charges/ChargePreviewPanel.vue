<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import type { ChargePreview } from '@/api/propertyCharges'
import { chargeMoney } from './chargeMoney'
defineProps<{ preview: ChargePreview }>()
const { t } = useI18n()
function label(preview: ChargePreview, id: number) { return preview.registry.units.find(u => u.id === id)?.unit_no ?? preview.previous_units.find(u => u.id === id)?.unit_no ?? t('propertyCharges.previousUnit') }
</script>
<template>
  <section class="space-y-3 rounded-lg border border-neutral-200 p-4">
    <h2 class="text-lg font-semibold">{{ t('propertyCharges.previewTitle') }}: {{ chargeMoney(preview.calculation.total_cents) }} {{ t('propertyCharges.currency') }}</h2>
    <p class="text-sm">{{ t('propertyCharges.frequency') }}: {{ t(`propertyCharges.frequencies.${preview.request.frequency}`) }}</p>
    <p class="text-sm text-neutral-500">{{ t('propertyCharges.roundingRule') }}</p>
    <div class="overflow-x-auto"><table class="w-full text-sm"><thead><tr><th>{{ t('propertyCharges.unit') }}</th><th>{{ t('propertyCharges.before') }}</th><th>{{ t('propertyCharges.after') }}</th><th>{{ t('propertyCharges.difference') }}</th></tr></thead><tbody><tr v-for="row in preview.previous_frozen_differences" :key="row.unit_id"><td>{{ label(preview, row.unit_id) }}</td><td class="text-right tabular-nums">{{ chargeMoney(row.before_cents) }}</td><td class="text-right tabular-nums">{{ chargeMoney(row.after_cents) }}</td><td class="text-right tabular-nums">{{ chargeMoney(row.delta_cents) }}</td></tr></tbody></table></div>
    <div v-if="preview.overlapping_versions.length" class="rounded border border-warning-300 p-3 text-sm"><p>{{ t('propertyCharges.overlapping') }}</p><p v-for="v in preview.overlapping_versions" :key="v.version_id">{{ t('propertyCharges.version', { number: v.revision }) }}: {{ v.decision_ref }} ({{ v.from }} / {{ v.to ?? t('propertyCharges.noEnd') }})</p></div>
    <details v-if="preview.same_basis_differences"><summary>{{ t('propertyCharges.sameBasis') }}</summary><div v-for="row in preview.same_basis_differences" :key="row.unit_id" class="flex flex-wrap justify-between gap-2 text-sm"><span>{{ label(preview, row.unit_id) }}</span><span>{{ chargeMoney(row.delta_cents) }}</span></div></details>
    <p v-if="preview.same_basis_unavailable" class="text-warning-600">{{ t('propertyCharges.comparisonUnavailable') }}</p>
    <details v-for="row in preview.calculation.rates" :key="row.component.id"><summary>{{ row.component.name }}: {{ chargeMoney(row.calculation.total_cents) }}</summary><div class="overflow-x-auto"><table class="w-full text-sm"><thead><tr><th>{{ t('propertyCharges.unit') }}</th><th>{{ t('propertyCharges.basis') }}</th><th>{{ t('propertyCharges.exactCents') }}</th><th>{{ t('propertyCharges.amount') }}</th></tr></thead><tbody><tr v-for="line in row.calculation.lines" :key="line.unit_id"><td>{{ label(preview, line.unit_id) }}</td><td>{{ line.basis.num }}/{{ line.basis.den }}</td><td>{{ line.exact_cents.num }}/{{ line.exact_cents.den }}</td><td>{{ chargeMoney(line.amount_cents) }} <span v-if="line.residual_cent" class="text-primary-600">{{ t('propertyCharges.residual') }}</span></td></tr></tbody></table></div></details>
    <div class="rounded bg-warning-50 p-3 text-sm text-warning-700"><p>{{ t('propertyCharges.noClaim') }}</p><ul class="list-disc pl-5"><li v-for="(fact, i) in preview.calculation.issuance_facts" :key="i">{{ t(`propertyCharges.facts.${fact.code}`) }}</li></ul></div>
  </section>
</template>

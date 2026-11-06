<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import type { ChargeComponent, ChargeRate, ChargeUnit } from '@/api/propertyCharges'
import PropertyButton from '../PropertyButton.vue'
import { propertyInput as input } from '../propertyFormStyles'
const rate = defineModel<ChargeRate>({ required: true })
const props = defineProps<{ components: ChargeComponent[]; units: ChargeUnit[] }>()
defineEmits<{ remove: [] }>()
const { t } = useI18n()
const methods = ['unit', 'floor_area', 'share', 'person', 'equal']
const bases = ['management_share', 'declaration_override', 'own_administration_equal', 'service_decision', 'other_documented', 'historical_documented']
function selectAll() { rate.value.unit_ids = props.units.map(u => u.id) }
</script>
<template>
  <fieldset class="rounded-lg border border-neutral-200 p-4 space-y-3">
    <div class="flex flex-wrap items-end gap-3">
      <label class="min-w-44 flex-1 flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyCharges.component') }} *<select v-model.number="rate.component_id" :class="input"><option :value="0">{{ t('propertyCharges.choose') }}</option><option v-for="c in components" :key="c.id" :value="c.id">{{ c.name }}</option></select></label>
      <label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyCharges.method') }}<select v-model="rate.method" :class="input"><option v-for="m in methods" :key="m" :value="m">{{ t(`propertyCharges.methods.${m}`) }}</option></select></label>
      <label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyCharges.mode') }}<select v-model="rate.mode" :class="input"><option value="rate_per_basis">{{ t('propertyCharges.rateMode') }}</option><option value="total_budget">{{ t('propertyCharges.budgetMode') }}</option></select></label>
      <label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyCharges.amount') }} *<input v-model="rate.amount" data-amount :class="input" inputmode="decimal" /></label>
      <PropertyButton icon="trash" variant="danger" @click="$emit('remove')">{{ t('propertyCharges.remove') }}</PropertyButton>
    </div>
    <label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyCharges.legalBasis') }} *<select v-model="rate.legal_basis" :class="input"><option value="">{{ t('propertyCharges.choose') }}</option><option v-for="b in bases" :key="b" :value="b">{{ t(`propertyCharges.bases.${b}`) }}</option></select></label>
    <label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyCharges.scopeRef') }} *<input v-model="rate.scope_ref" :class="input" /></label>
    <div class="flex flex-wrap gap-3 items-center"><PropertyButton icon="check" @click="selectAll">{{ t('propertyCharges.selectAll') }}</PropertyButton><span class="text-sm text-neutral-500">{{ t('propertyCharges.explicitScope') }}</span></div>
    <div class="grid gap-2 sm:grid-cols-3"><label v-for="u in units" :key="u.id" class="flex items-center gap-2"><input v-model="rate.unit_ids" type="checkbox" :value="u.id" />{{ u.unit_no }}</label></div>
    <label class="flex items-center gap-2"><input v-model="rate.correction" type="checkbox" />{{ t('propertyCharges.correction') }}</label>
    <label v-if="rate.correction" class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyCharges.correctionReason') }}<input v-model="rate.correction_reason" :class="input" /></label>
  </fieldset>
</template>

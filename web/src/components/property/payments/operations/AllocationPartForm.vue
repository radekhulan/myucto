<script setup lang="ts">
import { reactive } from 'vue'
import { useI18n } from 'vue-i18n'
import type { PropertyObject } from '@/api/propertyRegistry'
import type { DebtDetail } from '@/api/propertyPaymentOperations'
import PropertyButton from '@/components/property/PropertyButton.vue'
import DebtPicker from './DebtPicker.vue'
import PaymentFactsFields from './PaymentFactsFields.vue'
import type { AllocationPartState } from './partForms'
import { propertyUnitLabel } from './format'
import { inputClass } from './ui'
const props = defineProps<{ supplier: number; part: AllocationPartState; units: PropertyObject[]; cells: Array<{ key: string }>; sourceDate: string | null }>()
const emit = defineEmits<{ remove: [] }>()
const { t } = useI18n()
const details = reactive<Record<number, DebtDetail>>({})
const unitText = (u: PropertyObject) => propertyUnitLabel(u, number => t('propertyPayments.unitNumber', { number }))
</script>

<template>
  <div class="space-y-3 rounded border border-neutral-200 p-3" data-part="allocation">
    <div class="grid gap-3 sm:grid-cols-2">
      <label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyPayments.amount') }}<input v-model="part.amount" required inputmode="decimal" :class="inputClass" data-field="alloc-amount"></label>
      <label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyPayments.alloc.unitFilter') }}<select v-model="part.unit_id" :class="inputClass" data-field="alloc-unit"><option :value="0">{{ t('propertyPayments.alloc.allUnits') }}</option><option v-for="u in units" :key="u.id" :value="u.id">{{ unitText(u) }}</option></select></label>
      <label class="sm:col-span-2 flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyPayments.reason') }}<input v-model="part.reason" required :class="inputClass" data-field="alloc-reason"></label>
    </div>
    <div class="space-y-1"><p class="text-sm font-medium">{{ t('propertyPayments.alloc.debts') }}</p><p class="text-xs text-neutral-500">{{ t('propertyPayments.alloc.debtsHelp') }}</p>
      <DebtPicker :supplier="supplier" :unit-id="part.unit_id" :model-value="part.debts" @update:model-value="part.debts = $event" @detail="details[$event.id] = $event" /></div>
    <PaymentFactsFields :supplier="supplier" :state="part.facts" :debts="part.debts" :details="details" :source-date="sourceDate" />
    <div v-if="cells.length > 1" class="flex flex-wrap gap-3"><label v-for="(cell, cellIndex) in cells" :key="cell.key" class="w-full sm:w-40 flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyPayments.consumeCell', { number: cellIndex + 1 }) }}<input v-model="part.selection[cell.key]" inputmode="decimal" :class="inputClass"></label></div>
    <PropertyButton icon="trash" variant="danger" @click="emit('remove')">{{ t('propertyPayments.removePart') }}</PropertyButton>
  </div>
</template>

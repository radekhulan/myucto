<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { propertyPaymentOperationsApi, type Debt, type DebtDetail } from '@/api/propertyPaymentOperations'
import PagedCollection from './PagedCollection.vue'
import { moneyText } from './format'
const props = defineProps<{ supplier: number; modelValue: number[]; unitId: number }>()
const emit = defineEmits<{ 'update:modelValue': [value: number[]]; detail: [value: DebtDetail] }>()
const { t } = useI18n()
const requested = new Set<number>()
async function toggle(debt: Debt, checked: boolean) {
  emit('update:modelValue', checked ? [...props.modelValue, debt.id] : props.modelValue.filter(id => id !== debt.id))
  if (!checked || requested.has(debt.id)) return
  requested.add(debt.id)
  const supplier = props.supplier
  try { const detail = await propertyPaymentOperationsApi.detail<DebtDetail>(supplier, 'debts', debt.id); if (supplier === props.supplier) emit('detail', detail) } catch { requested.delete(debt.id) }
}
</script>

<template>
  <PagedCollection :supplier="supplier" collection="debts" :unit-id="unitId" :filter="(d: Debt) => d.remaining_cents !== '0' || modelValue.includes(d.id)">
    <template #row="{ item }">
      <label class="flex flex-wrap items-center gap-2 border-t border-neutral-200 py-1 text-sm">
        <input type="checkbox" data-field="debt-select" :checked="modelValue.includes(item.id)" @change="toggle(item as Debt, ($event.target as HTMLInputElement).checked)">
        <span>{{ item.unit_label }} · {{ item.title_ref }} · {{ t('propertyPayments.dueShort') }} {{ item.due_date }}</span>
        <span class="ml-auto">{{ t('propertyPayments.remainingDebt') }}: {{ moneyText(item.remaining_cents, t('propertyPayments.unknown')) }}</span>
      </label>
    </template>
  </PagedCollection>
</template>

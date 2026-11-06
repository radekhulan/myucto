<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import type { DebtDetail } from '@/api/propertyPaymentOperations'
import AccessoryBindingForm from './AccessoryBindingForm.vue'
import { moneyText, partyNames } from './format'
defineProps<{ supplier: number; detail: DebtDetail; canManage: boolean }>()
const emit = defineEmits<{ changed: [] }>()
const { t } = useI18n()
const unknown = () => t('propertyPayments.unknown')
</script>

<template>
  <div class="space-y-2 rounded border border-neutral-200 p-3 text-sm" data-section="debt-detail">
    <p class="font-medium">{{ detail.unit_label }} · {{ detail.title_ref }}</p>
    <p>{{ t('propertyPayments.debt.legal') }}: {{ t(`propertyPayments.debt.basis.${detail.legal.basis}`) }}<template v-if="detail.legal.legal_act_date"> ({{ detail.legal.legal_act_date }})</template> · {{ t(`propertyPayments.debt.regime.${detail.legal.regime}`) }} · {{ detail.legal.evidence }}</p>
    <p>{{ t('propertyPayments.debt.security') }}: {{ t(`propertyPayments.security.${detail.security.kind}`) }} · {{ detail.security.evidence }}</p>
    <p class="text-xs text-neutral-500" data-flag="liability-as-of">{{ t('propertyPayments.debt.liabilityAsOf', { date: detail.liability_as_of ?? unknown() }) }}</p>
    <p v-if="!detail.liability_complete" class="text-xs text-warning-600" data-flag="liability-incomplete">{{ t('propertyPayments.debt.liabilityIncomplete') }}</p>
    <div v-for="group in detail.groups" :key="group.group_key" data-group>
      <p>{{ t(`propertyPayments.role.${group.role}`) }}: {{ partyNames(group.members) }} · {{ t('propertyPayments.debt.cap') }}: {{ moneyText(group.remaining_cap_cents, unknown()) }}</p>
      <p v-if="group.members.length > 1" class="text-xs text-neutral-500">{{ t('propertyPayments.groupOnce') }}</p>
    </div>
    <ul class="list-disc pl-5"><li v-for="line in detail.lines" :key="line.id">{{ line.label }}: {{ moneyText(line.amount_cents, unknown()) }} ({{ t('propertyPayments.remainingDebt') }}: {{ moneyText(line.remaining_cents, unknown()) }})</li></ul>
    <ul v-if="detail.accessories.length" class="list-disc pl-5"><li v-for="a in detail.accessories" :key="a.id">{{ t(`propertyPayments.component.${a.component}`) }} · {{ a.label }}: {{ moneyText(a.amount_cents, unknown()) }} ({{ t('propertyPayments.remainingDebt') }}: {{ moneyText(a.remaining_cents, unknown()) }})</li></ul>
    <AccessoryBindingForm v-if="canManage" :supplier="supplier" :detail="detail" :can-manage="canManage" @changed="emit('changed')" />
  </div>
</template>

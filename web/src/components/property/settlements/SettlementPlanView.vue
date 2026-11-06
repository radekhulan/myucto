<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { SettlementPlan } from '@/api/propertySettlements'
import { settlementMoney } from './settlementForm'
const props = defineProps<{ plan: SettlementPlan | Pick<SettlementPlan, 'lines'> }>()
const values = computed(() => 'totals' in props.plan ? props.plan.totals : null)
const { t } = useI18n()
const totals = ['advance_applied_cents', 'new_claim_cents', 'reduction_cents', 'cancelled_unpaid_cents', 'released_allocations_cents', 'opening_paid_credit_cents', 'new_credit_cents', 'remaining_old_claim_cents', 'retained_paid_cents']
</script>
<template>
 <div class="space-y-3" data-section="settlement-plan"><dl v-if="values" class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3"><div v-for="key in totals" :key="key" class="rounded border p-2"><dt class="text-sm text-neutral-500">{{ t(`propertySettlements.amounts.${key}`) }}</dt><dd class="font-semibold">{{ settlementMoney(values[key] || '0') }}</dd></div></dl><details><summary>{{ t('propertySettlements.journal') }}</summary><div v-for="(line, i) in plan.lines" :key="i" class="flex flex-wrap gap-3 border-t py-1"><span>{{ line.account_code }}</span><span>{{ t(`propertyOpening.${line.side}`) }}</span><span>{{ line.amount }}</span></div></details></div>
</template>

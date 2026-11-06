<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import type { SettlementEntitlement } from '@/api/propertySettlements'
import type { ChartAccount } from '@/api/accounting'
import PropertyPartyPicker from '@/components/property/PropertyPartyPicker.vue'
import PropertyButton from '@/components/property/PropertyButton.vue'
import DateInput from '@/components/ui/DateInput.vue'
import ChartAccountSelect from '@/components/accounting/ChartAccountSelect.vue'
import { settlementInput as input } from './settlementForm'
const props = defineProps<{ supplier: number; value: SettlementEntitlement; accounts: ChartAccount[] }>()
const { t } = useI18n()
const selected = ref<number | null>(null), names = ref<Record<number, string>>({})
function add(p: { id: number; name: string }) { if (!props.value.party_ids.includes(p.id)) props.value.party_ids.push(p.id); names.value[p.id] = p.name; selected.value = null }
</script>
<template>
 <div class="space-y-2 rounded border border-neutral-200 p-3">
  <p class="text-sm text-neutral-500">{{ t('propertySettlements.entitlementHelp') }}</p>
  <div class="flex flex-wrap gap-2"><span v-for="id in value.party_ids" :key="id" class="flex items-center gap-2 rounded bg-neutral-100 px-2 py-1 text-sm">{{ names[id] || t('propertySettlements.selectedPerson') }}<PropertyButton icon="trash" variant="danger" @click="value.party_ids.splice(value.party_ids.indexOf(id), 1)">{{ t('propertySettlements.remove') }}</PropertyButton></span></div>
  <PropertyPartyPicker v-model="selected" :supplier-id="supplier" @selected="add" />
  <div class="grid gap-3 sm:grid-cols-2"><label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertySettlements.dueDate') }}<DateInput :class="input" v-model="value.due_date" required /></label><label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertySettlements.account') }}<ChartAccountSelect v-model="value.account_code" :accounts="accounts" /></label><label class="sm:col-span-2 flex min-w-0 flex-col gap-1 text-sm">{{ t('propertySettlements.entitlementEvidence') }}<input v-model="value.evidence" required :class="input" /></label></div>
 </div>
</template>

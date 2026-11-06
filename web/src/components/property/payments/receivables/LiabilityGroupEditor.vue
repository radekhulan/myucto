<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import type { LiabilityGroup } from '@/api/propertyReceivables'
import PropertyPartyPicker from '@/components/property/PropertyPartyPicker.vue'
import PropertyInstantInput from '@/components/property/PropertyInstantInput.vue'
import PropertyButton from '@/components/property/PropertyButton.vue'
import { inputClass } from '../operations/ui'
const props = defineProps<{ supplier: number; group: LiabilityGroup }>()
const emit = defineEmits<{ remove: [] }>()
const { t } = useI18n()
function member(index: number, id: number | null) { props.group.members[index] = id ?? 0 }
</script>
<template>
  <div class="grid gap-3 rounded border border-neutral-200 p-3 sm:grid-cols-2">
    <label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertySaldo.groupKey') }}<input v-model="group.group_key" required :class="inputClass" /></label>
    <label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertySaldo.role') }}<select v-model="group.role" :class="inputClass"><option v-for="v in ['debtor', 'guarantor']" :key="v" :value="v">{{ t(`propertyPayments.role.${v}`) }}</option></select></label>
    <label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertySaldo.mode') }}<select v-model="group.mode" :class="inputClass"><option v-for="v in ['individual', 'solidary']" :key="v" :value="v">{{ t(`propertySaldo.modes.${v}`) }}</option></select></label>
    <label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertySaldo.basis') }}<select v-model="group.basis" :class="inputClass"><option v-for="v in ['individual_title', 'sjm_713', 'joint_act_1127', 'documented_title']" :key="v" :value="v">{{ t(`propertySaldo.bases.${v}`) }}</option></select></label>
    <div class="sm:col-span-2 space-y-2"><span>{{ t('propertySaldo.members') }}</span><div v-for="(id, i) in group.members" :key="i" class="flex flex-wrap gap-2"><div class="min-w-60 flex-1"><PropertyPartyPicker :supplier-id="supplier" :model-value="id || null" @update:model-value="member(i, $event)" /></div><PropertyButton icon="trash" variant="danger" :disabled="group.members.length === 1" @click="group.members.splice(i, 1)">{{ t('propertyPayments.remove') }}</PropertyButton></div><PropertyButton icon="plus" @click="group.members.push(0)">{{ t('propertyPayments.addPerson') }}</PropertyButton></div>
    <label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertySaldo.numerator') }}<input v-model="group.extent.num" required pattern="[1-9][0-9]*" :class="inputClass" /></label>
    <label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertySaldo.denominator') }}<input v-model="group.extent.den" required pattern="[1-9][0-9]*" :class="inputClass" /></label>
    <label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertySaldo.legalReason') }}<input v-model="group.legal_reason" required maxlength="2000" :class="inputClass" /></label>
    <label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertySaldo.evidence') }}<input v-model="group.evidence_ref" required maxlength="500" :class="inputClass" /></label>
    <PropertyInstantInput v-model="group.valid_from" :label="t('propertySaldo.validFrom')" required />
    <PropertyInstantInput :model-value="group.valid_to ?? ''" :label="t('propertySaldo.validTo')" @update:model-value="group.valid_to = $event || null" />
    <PropertyButton icon="trash" variant="danger" @click="emit('remove')">{{ t('propertyPayments.remove') }}</PropertyButton>
  </div>
</template>

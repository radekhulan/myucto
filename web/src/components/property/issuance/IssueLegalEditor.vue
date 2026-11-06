<script setup lang="ts">
import { computed, reactive } from 'vue'
import { useI18n } from 'vue-i18n'
import type { IssueContext, IssueUnit, LiabilityGroup } from '@/api/propertyChargeIssuance'
import type { UnitState } from '@/api/propertyRegistry'
import PropertyButton from '@/components/property/PropertyButton.vue'
import PropertyPartyPicker from '@/components/property/PropertyPartyPicker.vue'
import PropertyInstantInput from '@/components/property/PropertyInstantInput.vue'
import IssueNoticeEditor from './IssueNoticeEditor.vue'
import { emptyLiability } from './issuanceModel'
import { propertyControl as control, propertyInput as input, propertyTextarea as textarea } from '@/components/property/propertyFormStyles'
const props = defineProps<{ unit: IssueUnit; context: IssueContext; supplierId: number; at: string; readonly?: boolean }>()
const { t } = useI18n()
const selected = reactive<Record<string, number | null>>({})
const names = reactive<Record<number, string>>({})
const state = computed(() => props.context.units.find(v => v.id === props.unit.unit_id)?.revision?.state as UnitState | undefined)
function person(id: number) { const p = props.context.parties.find(v => v.id === id); return names[id] || p?.company_name || [p?.first_name, p?.last_name].filter(Boolean).join(' ') || t('propertyIssuance.unknownPerson') }
function addMember(group: LiabilityGroup, party: { id: number; name: string }) { if (!group.members.includes(party.id)) group.members.push(party.id); names[party.id] = party.name; selected[group.group_key] = null }
</script>
<template>
  <section class="rounded-lg border border-neutral-200 p-3 space-y-3" data-testid="issue-legal-unit">
    <h3 class="font-semibold">{{ state?.unit_no }}</h3>
    <p class="text-sm text-neutral-600">{{ t('propertyIssuance.noInferredDebtor') }}</p>
    <details class="text-sm"><summary>{{ t('propertyIssuance.registryFacts') }}</summary>
      <p v-for="ownership in state?.ownerships" :key="ownership.group_key">{{ ownership.members.map(person).join(', ') }} · {{ ownership.share.num }}/{{ ownership.share.den }} · {{ ownership.title_ref }}</p>
    </details>
    <fieldset :disabled="readonly" class="space-y-3">
      <div v-for="(group, index) in unit.liability_groups" :key="group.group_key" class="rounded border border-neutral-200 p-3 space-y-3" data-testid="liability-group">
        <div class="flex flex-wrap items-end gap-3">
          <label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyIssuance.liabilityRole') }}<select v-model="group.role" :class="input"><option value="">{{ t('propertyIssuance.choose') }}</option><option v-for="role in ['debtor', 'guarantor']" :key="role" :value="role">{{ t(`propertyIssuance.roles.${role}`) }}</option></select></label>
          <label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyIssuance.liabilityMode') }}<select v-model="group.mode" :class="input"><option value="">{{ t('propertyIssuance.choose') }}</option><option v-for="mode in ['individual', 'solidary']" :key="mode" :value="mode">{{ t(`propertyIssuance.modes.${mode}`) }}</option></select></label>
          <label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyIssuance.liabilityBasis') }}<select v-model="group.basis" :class="input"><option value="">{{ t('propertyIssuance.choose') }}</option><option v-for="basis in ['individual_title', 'sjm_713', 'joint_act_1127', 'documented_title']" :key="basis" :value="basis">{{ t(`propertyIssuance.bases.${basis}`) }}</option></select></label>
          <PropertyButton icon="trash" variant="danger" @click="unit.liability_groups.splice(index, 1)">{{ t('propertyIssuance.remove') }}</PropertyButton>
        </div>
        <div class="flex flex-wrap items-center gap-2"><span v-for="(id, member) in group.members" :key="id" class="inline-flex gap-2 items-center rounded bg-neutral-100 px-2 py-1">{{ person(id) }}<PropertyButton icon="trash" variant="danger" @click="group.members.splice(member, 1)">{{ t('propertyIssuance.remove') }}</PropertyButton></span></div>
        <PropertyPartyPicker v-model="selected[group.group_key]" :supplier-id="supplierId" @selected="addMember(group, $event)" />
        <fieldset><legend>{{ t('propertyIssuance.extent') }}</legend><div class="flex flex-wrap items-center gap-2"><input v-model="group.extent.num" inputmode="numeric" maxlength="30" :class="[control, 'w-28']" :aria-label="t('propertyRegistry.numerator')"><span>/</span><input v-model="group.extent.den" inputmode="numeric" maxlength="30" :class="[control, 'w-28']" :aria-label="t('propertyRegistry.denominator')"></div></fieldset>
        <div class="grid gap-3 md:grid-cols-2"><PropertyInstantInput v-model="group.valid_from" :label="t('propertyIssuance.liabilityFrom')" /><PropertyInstantInput :model-value="group.valid_to ?? ''" :label="t('propertyIssuance.liabilityTo')" @update:model-value="group.valid_to = $event || null" /></div>
        <label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyIssuance.evidence') }}<input v-model="group.evidence_ref" :class="input"></label>
        <label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyIssuance.legalReason') }}<textarea v-model="group.legal_reason" :class="textarea" /></label>
      </div>
      <div class="flex flex-wrap gap-2"><PropertyButton icon="plus" @click="unit.liability_groups.push(emptyLiability(at))">{{ t('propertyIssuance.addLiability') }}</PropertyButton></div>
    </fieldset>
    <IssueNoticeEditor v-for="notice in unit.notices" :key="notice.component_id" :entry="notice" :context="context" :unit-id="unit.unit_id" :supplier-id="supplierId" />
  </section>
</template>

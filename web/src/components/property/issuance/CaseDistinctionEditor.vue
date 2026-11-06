<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { CaseCandidate, IssueContext, IssueUnit } from '@/api/propertyChargeIssuance'
import type { UnitState } from '@/api/propertyRegistry'
import PropertyButton from '@/components/property/PropertyButton.vue'
import CaseCandidateList from './CaseCandidateList.vue'
import { distinctionComplete } from './issuanceModel'
import { propertyTextarea as textarea } from '@/components/property/propertyFormStyles'
const props = defineProps<{ unit: IssueUnit; context: IssueContext; candidates?: CaseCandidate[]; readonly?: boolean }>()
const { t } = useI18n()
const name = computed(() => (props.context.units.find(u => u.id === props.unit.unit_id)?.revision?.state as UnitState | undefined)?.unit_no || String(props.unit.unit_id))
const unitName = (id: number) => (props.context.units.find(u => u.id === id)?.revision?.state as UnitState | undefined)?.unit_no || String(id)
const complete = computed(() => !!props.unit.case_distinction && distinctionComplete(props.unit.case_distinction, props.candidates))
function toggle(key: string, on: boolean) {
  const keys = props.unit.case_distinction!.against_source_keys
  const at = keys.indexOf(key)
  if (on && at < 0) keys.push(key)
  if (!on && at >= 0) keys.splice(at, 1)
}
</script>
<template>
  <section v-if="unit.case_distinction || candidates?.length" class="rounded-lg border border-warning-300 p-3 space-y-3" data-testid="case-distinction">
    <h3 class="font-semibold">{{ t('propertyIssuance.case.distinctionTitle') }} · {{ name }}</h3>
    <p class="text-sm text-neutral-600">{{ t('propertyIssuance.case.distinctionHelp') }}</p>
    <CaseCandidateList v-if="candidates?.length" :candidates="candidates" :unit-name="unitName" />
    <fieldset v-if="unit.case_distinction" :disabled="readonly" class="space-y-3">
      <div v-if="candidates?.length" class="flex flex-wrap gap-3">
        <label v-for="candidate in candidates" :key="candidate.source_key" class="inline-flex items-center gap-2"><input type="checkbox" data-against :checked="unit.case_distinction.against_source_keys.includes(candidate.source_key)" @change="toggle(candidate.source_key, ($event.target as HTMLInputElement).checked)">{{ candidate.case_identity?.case_ref || t('propertyIssuance.case.legacyFamily') }}</label>
      </div>
      <label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyIssuance.case.legalReason') }}<textarea v-model="unit.case_distinction.legal_reason" data-distinction="legal_reason" maxlength="4000" :class="textarea" /></label>
      <label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyIssuance.case.fields.evidence_ref') }}<textarea v-model="unit.case_distinction.evidence_ref" data-distinction="evidence_ref" maxlength="4000" :class="textarea" /></label>
      <p v-if="!complete && !readonly" class="text-sm text-warning-700" data-testid="distinction-incomplete">{{ t('propertyIssuance.case.distinctionIncomplete') }}</p>
      <div v-if="!readonly && !candidates?.length" class="flex flex-wrap gap-2"><PropertyButton icon="x" @click="delete unit.case_distinction">{{ t('propertyIssuance.case.removeDistinction') }}</PropertyButton></div>
    </fieldset>
  </section>
</template>

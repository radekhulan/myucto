<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { IssueContext } from '@/api/propertyChargeIssuance'
import type { UnitState } from '@/api/propertyRegistry'
import { caseHeadline, familyKey } from './issuanceModel'
const props = defineProps<{ context: IssueContext; choice: string; disabled?: boolean }>()
const emit = defineEmits<{ choose: [key: string] }>()
const { t } = useI18n()
const families = computed(() => props.context.case_families ?? [])
const unitName = (id: number) => (props.context.units.find(u => u.id === id)?.revision?.state as UnitState | undefined)?.unit_no || String(id)
</script>
<template>
  <fieldset :disabled="disabled" class="rounded-lg border border-neutral-200 p-3 space-y-2" data-testid="case-picker">
    <legend class="font-semibold">{{ t('propertyIssuance.case.chooseTitle') }}</legend>
    <p class="text-sm text-neutral-600">{{ t('propertyIssuance.case.chooseHelp') }}</p>
    <label v-for="family in families" :key="familyKey(family)" class="flex items-start gap-2" data-case-family>
      <input type="radio" name="case-choice" :value="familyKey(family)" :checked="choice === familyKey(family)" @change="emit('choose', familyKey(family))">
      <span><strong>{{ caseHeadline(family.case_identity) || t('propertyIssuance.case.legacyFamily') }}</strong> · {{ unitName(family.unit_id) }} · {{ t(`propertyIssuance.statuses.${family.status}`) }} · {{ t('propertyIssuance.case.revision', { n: family.latest_revision }) }}</span>
    </label>
    <label class="flex items-start gap-2" data-case-new>
      <input type="radio" name="case-choice" value="new" :checked="choice === 'new'" @change="emit('choose', 'new')">
      <span><strong>{{ t('propertyIssuance.case.newCase') }}</strong> · {{ t('propertyIssuance.case.newCaseHelp') }}</span>
    </label>
  </fieldset>
</template>

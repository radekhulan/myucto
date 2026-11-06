<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import type { CaseCandidate } from '@/api/propertyChargeIssuance'
import { caseHeadline } from './issuanceModel'
defineProps<{ candidates: CaseCandidate[]; unitName?: (unitId: number) => string }>()
const { t } = useI18n()
</script>
<template>
  <ul class="space-y-2 text-sm" data-testid="case-candidates">
    <li v-for="candidate in candidates" :key="`${candidate.unit_id}|${candidate.source_key}`" class="rounded border border-neutral-200 p-2" data-testid="case-candidate">
      <strong>{{ caseHeadline(candidate.case_identity) || t('propertyIssuance.case.legacyFamily') }}</strong>
      <span v-if="unitName"> · {{ unitName(candidate.unit_id) }}</span>
      <span> · {{ t(`propertyIssuance.statuses.${candidate.status}`) }} · {{ t('propertyIssuance.case.revision', { n: candidate.latest_revision }) }}</span>
      <span class="block text-neutral-600">{{ candidate.reasons.map(r => t(`propertyIssuance.case.reasons.${r}`)).join(', ') }}</span>
    </li>
  </ul>
</template>

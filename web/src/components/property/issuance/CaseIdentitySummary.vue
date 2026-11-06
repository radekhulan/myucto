<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import type { CaseCandidate, CaseDistinction, CaseIdentity } from '@/api/propertyChargeIssuance'
import CaseCandidateList from './CaseCandidateList.vue'
defineProps<{ identity?: CaseIdentity | null; distinction?: CaseDistinction | null; candidates?: CaseCandidate[]; unitName?: (unitId: number) => string }>()
const { t } = useI18n()
</script>
<template>
  <section class="rounded border border-neutral-200 p-3 space-y-2 text-sm" data-testid="case-summary">
    <template v-if="identity">
      <p><strong>{{ identity.case_ref }}</strong> · {{ identity.occurrence_ref }} · {{ identity.title_ref }}</p>
      <p>{{ identity.decision_ref }} · {{ identity.decided_on }}</p>
      <p>{{ identity.evidence_ref }}</p>
      <p class="text-neutral-600">{{ t('propertyIssuance.case.assessmentHelp') }}</p>
    </template>
    <p v-else class="text-neutral-600" data-testid="case-legacy">{{ t('propertyIssuance.case.legacyFamily') }}</p>
    <div v-if="distinction" data-testid="case-distinction-summary"><p>{{ t('propertyIssuance.case.distinctionTitle') }}: {{ distinction.legal_reason }}</p><p>{{ distinction.evidence_ref }}</p></div>
    <CaseCandidateList v-if="candidates?.length" :candidates="candidates" :unit-name="unitName" />
  </section>
</template>

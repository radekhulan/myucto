<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import type { CaseIdentity } from '@/api/propertyChargeIssuance'
import DateInput from '@/components/ui/DateInput.vue'
import { propertyDateInput as dateInput, propertyInput as input, propertyTextarea as textarea } from '@/components/property/propertyFormStyles'
defineProps<{ identity: CaseIdentity; readonly?: boolean }>()
const { t } = useI18n()
const refs = [['case_ref', 160], ['occurrence_ref', 160], ['title_ref', 500], ['decision_ref', 500]] as const
</script>
<template>
  <section class="rounded-lg border border-neutral-200 p-3 space-y-3" data-testid="case-identity">
    <h3 class="font-semibold">{{ t('propertyIssuance.case.identityTitle') }}</h3>
    <p class="text-sm text-neutral-600">{{ t(readonly ? 'propertyIssuance.case.immutableHelp' : 'propertyIssuance.case.assessmentHelp') }}</p>
    <fieldset :disabled="readonly" class="space-y-3">
      <div class="grid gap-3 md:grid-cols-2">
        <label class="flex min-w-0 flex-col gap-1 text-sm" v-for="[field, max] in refs" :key="field">{{ t(`propertyIssuance.case.fields.${field}`) }}<input v-model="identity[field]" :data-case="field" :maxlength="max" :readonly="readonly" :class="input"></label>
      </div>
      <label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyIssuance.case.fields.decided_on') }}<DateInput v-model="identity.decided_on" data-case="decided_on" :readonly="readonly" :class="[dateInput, 'sm:w-40']" /></label>
      <label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyIssuance.case.fields.evidence_ref') }}<textarea v-model="identity.evidence_ref" data-case="evidence_ref" maxlength="4000" :readonly="readonly" :class="textarea" /></label>
    </fieldset>
  </section>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { PayrollMinimumWagePreview } from '@/api/payroll'
import { formatDate, formatMoneyMinor } from '@/composables/useFormat'
import { formatPayrollDuration } from '@/pages/payroll/payrollDuration'
import { uniqueBy } from '@/pages/payroll/payrollFieldFeedback'
const props = defineProps<{ value: PayrollMinimumWagePreview }>()
const { t, te } = useI18n()
function amount(minor: number | null | undefined): string { return minor == null ? t('payroll.worked_hours.unknown') : formatMoneyMinor(minor) }
function exclusionReason(reason: string): string {
  if (reason === 'embedded_agreement_premium') return t('payroll.minimum.excluded_embedded')
  return t(reason === 'source_period_other' ? 'payroll.minimum.excluded_source_period' : 'payroll.minimum.excluded_component')
}
function blockerText(blocker: { code: string, message: string }): string {
  const key = `payroll.minimum.blockers.${blocker.code}`
  return te(key) ? t(key) : blocker.message
}
/** Stejná věta za každý úsek pracovní doby je pro účetní pořád jedna věc k vyřízení. */
const blockerMessages = computed(() => uniqueBy(props.value.blockers.map(blockerText), message => message))
</script>

<template>
  <section class="mt-3 space-y-2 rounded-md border border-neutral-200 bg-surface p-3" data-test="minimum-wage-preview">
    <p class="font-medium">{{ t('payroll.minimum.policy_title') }} · {{ t(value.assessment_evidence.assessment === 'monthly' ? 'payroll.minimum.monthly' : 'payroll.minimum.hourly') }}</p>
    <dl class="grid gap-3 sm:grid-cols-3">
      <div><dt class="text-neutral-500">{{ t('payroll.minimum.required') }}</dt><dd class="font-medium" data-test="minimum-required">{{ value.complete ? amount(value.assessment_evidence.threshold?.analytical_minor) : t('payroll.worked_hours.unknown') }}</dd></div>
      <div><dt class="text-neutral-500">{{ t('payroll.minimum.eligible') }}</dt><dd class="font-medium" data-test="minimum-eligible">{{ value.complete ? amount(value.assessment_evidence.eligible?.analytical_minor) : t('payroll.worked_hours.unknown') }}</dd></div>
      <div><dt class="text-neutral-500">{{ t('payroll.minimum.top_up') }}</dt><dd class="font-medium" data-test="minimum-top-up">{{ value.complete && value.claim.ready ? amount(value.claim.payable_minor) : t('payroll.worked_hours.unknown') }}</dd></div>
    </dl>
    <p class="text-neutral-500">{{ t('payroll.minimum.rounding_hint') }}</p>
    <p v-if="value.complete && value.claim.duration_units === 0" class="text-neutral-500" data-test="minimum-confirmed-zero">{{ t('payroll.minimum.confirmed_zero') }}</p>
    <p v-if="!value.complete" class="text-warning-700" data-test="minimum-incomplete">{{ t('payroll.minimum.incomplete') }}</p>
    <div v-if="value.assessment_evidence.excluded.length" class="space-y-1 border-t border-neutral-200 pt-2">
      <p class="font-medium">{{ t('payroll.minimum.excluded') }}</p>
      <p v-for="(item, index) in value.assessment_evidence.excluded" :key="index" class="text-neutral-600" data-test="minimum-excluded-item">{{ item.component_code ?? t('payroll.minimum.embedded_amount') }} · {{ amount(item.analytical_minor) }} · {{ exclusionReason(item.reason) }}</p>
    </div>
    <p v-else class="text-neutral-500">{{ t(value.complete ? 'payroll.minimum.none_excluded' : 'payroll.minimum.excluded_unknown') }}</p>
    <details v-if="value.assessment_evidence.parts.length" class="border-t border-neutral-200 pt-2">
      <summary class="cursor-pointer font-medium">{{ t('payroll.minimum.parts') }}</summary>
      <p v-for="(part, index) in value.assessment_evidence.parts" :key="index" class="mt-1 text-neutral-600" data-test="minimum-part">{{ formatDate(part.date_from) }} – {{ formatDate(part.date_to) }} · {{ formatPayrollDuration(part.regular_worked_duration_units) }} · {{ t('payroll.minimum.statutory_weekly') }}: {{ part.stated_weekly_minutes === null ? t('payroll.worked_hours.unknown') : formatPayrollDuration(part.stated_weekly_minutes * 50) }} · {{ t('payroll.minimum.hourly_rate', { amount: amount(part.minimum_hourly_minor) }) }} · {{ amount(part.analytical_minor) }}</p>
    </details>
    <ul v-if="blockerMessages.length" class="list-disc space-y-1 pl-4 text-warning-700" data-test="minimum-blockers"><li v-for="message in blockerMessages" :key="message">{{ message }}</li></ul>
  </section>
</template>

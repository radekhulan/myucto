<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import type { PayrollHourlyPolicyTerms } from '@/api/payroll'
import { formatPayrollDuration } from '@/pages/payroll/payrollDuration'
import { formatMoneyMinor } from '@/composables/useFormat'
defineProps<{ value: PayrollHourlyPolicyTerms }>()
const { t } = useI18n()
</script>
<template>
  <div class="mt-2 space-y-1 rounded-md bg-neutral-50 p-2 text-xs text-neutral-600" data-test="hourly-policy-summary">
    <div v-if="value.minimum_wage" class="space-y-1" data-test="minimum-policy-summary">
      <p>{{ t('payroll.minimum.policy_title') }}: {{ t(value.minimum_wage.assessment === 'monthly' ? 'payroll.minimum.monthly' : 'payroll.minimum.hourly') }}<span v-if="value.minimum_wage.agreement_reference"> · {{ value.minimum_wage.agreement_reference }}</span></p>
      <p v-if="value.minimum_wage.statutory_weekly_minutes != null">{{ t('payroll.minimum.statutory_weekly') }}: {{ formatPayrollDuration(value.minimum_wage.statutory_weekly_minutes * 50) }} · {{ value.minimum_wage.statutory_weekly_reference }}</p>
      <p v-if="value.minimum_wage.advance_proof">{{ t('payroll.minimum.advance_reference') }}: {{ value.minimum_wage.advance_proof.reference }} · {{ value.minimum_wage.advance_proof.decided_on }} · {{ value.minimum_wage.advance_proof.valid_from }} – {{ value.minimum_wage.advance_proof.valid_to ?? t('payroll.people.remuneration.open_ended') }}</p>
    </div>
    <p v-if="value.included_overtime">
      {{ t('payroll.hourly_policy.included_overtime') }} · {{ value.included_overtime.calendar_year }} · {{ formatPayrollDuration(value.included_overtime.annual_limit_duration_units) }} · {{ value.included_overtime.agreement_reference }}
      · {{ t('payroll.hourly_policy.opening') }}: {{ formatPayrollDuration(value.included_overtime.opening_used_duration_units) }} ({{ value.included_overtime.opening_as_of }}, {{ value.included_overtime.opening_reference }})
      <span v-if="value.included_overtime.manager_proof_reference"> · {{ value.included_overtime.manager_proof_reference }}</span>
    </p>
    <p v-for="(premium, index) in value.included_agreement_premiums" :key="index">
      {{ t(`payroll.people.surcharge_policy.kinds.${premium.kind}`) }} · {{ premium.date_from }} – {{ premium.date_to }} · {{ formatPayrollDuration(premium.scope_duration_units) }} · {{ formatMoneyMinor(premium.included_hourly_minor) }}/h · {{ premium.agreement_reference }}
      · {{ t('payroll.hourly_policy.opening') }}: {{ formatPayrollDuration(premium.opening_used_duration_units) }} ({{ premium.opening_as_of }}, {{ premium.opening_reference }})
    </p>
  </div>
</template>

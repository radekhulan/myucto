<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import type { PayrollHourlyWagePreview } from '@/api/payroll'
import { formatDate, formatMoneyMinor } from '@/composables/useFormat'
import { formatPayrollDuration } from '@/pages/payroll/payrollDuration'

defineProps<{ value: PayrollHourlyWagePreview; compact?: boolean }>()
const { t } = useI18n()
const purposes: Record<string, string> = {
  ordinary_base: 'ordinary_base', achieved_overtime: 'achieved_overtime', premium_overtime: 'premium_overtime',
  premium_holiday: 'premium_holiday', premium_night: 'premium_night', premium_weekend: 'premium_weekend',
  premium_difficult_environment: 'premium_difficult_environment', holiday_nonworked_compensation: 'holiday_nonworked_compensation',
  holiday_time_off_compensation: 'holiday_time_off_compensation',
}
function purposeLabel(purpose: string): string {
  return t(`payroll.hourly_preview.${purposes[purpose] ?? 'other_claim'}`)
}
</script>

<template>
  <section class="mt-3 rounded-md border border-neutral-200 bg-neutral-50 p-3 text-xs" data-test="hourly-wage-preview">
    <p class="font-medium text-neutral-900">{{ t(value.settlement_only ? 'payroll.hourly_preview.settlement_title' : 'payroll.hourly_preview.title') }}</p>
    <p v-if="!compact" class="mt-1 text-neutral-500">{{ t('payroll.hourly_preview.saved_evidence') }}</p>
    <p class="mt-2 font-medium" data-test="hourly-preview-total">
      {{ t('payroll.hourly_preview.total') }}:
      {{ value.payable_total_minor === null ? t('payroll.worked_hours.unknown') : formatMoneyMinor(value.payable_total_minor) }}
    </p>
    <template v-if="!compact">
      <div v-for="(claim, index) in value.claims" :key="`${claim.component_code}-${index}`" class="mt-3 border-t border-neutral-200 pt-2" data-test="hourly-preview-claim">
        <p class="font-medium">{{ purposeLabel(claim.purpose) }} · <template v-if="claim.ready">{{ formatPayrollDuration(claim.duration_units) }} · {{ claim.payable_minor === null ? t('payroll.worked_hours.unknown') : formatMoneyMinor(claim.payable_minor) }}</template><template v-else>{{ t('payroll.hourly_preview.claim_incomplete') }}</template></p>
        <p v-for="(segment, segmentIndex) in claim.segments" :key="segmentIndex" class="mt-1 text-neutral-600" data-test="hourly-preview-segment">
          <template v-if="segment.date_from">{{ formatDate(segment.date_from) }}<template v-if="segment.date_to"> – {{ formatDate(segment.date_to) }}</template> · </template>
          <template v-else-if="segment.date || segment.granted_on">{{ formatDate((segment.date || segment.granted_on)!) }} · </template>
          {{ formatPayrollDuration(segment.duration_units) }}
          <template v-if="segment.hourly_rate_minor != null"> · {{ t('payroll.hourly_preview.rate', { amount: formatMoneyMinor(segment.hourly_rate_minor) }) }}</template>
          <template v-else-if="segment.monthly_gross_minor != null"> · {{ t('payroll.hourly_preview.monthly_part') }}</template>
        </p>
      </div>
      <div v-for="(allocation, index) in value.included_allocations" :key="index" class="mt-2 text-neutral-600" data-test="hourly-preview-included">
        {{ t('payroll.hourly_preview.included') }} · {{ purposeLabel(allocation.purpose) }} · {{ allocation.date_from }} – {{ allocation.date_to }} · {{ allocation.ready ? formatPayrollDuration(allocation.duration_units) : t('payroll.hourly_preview.claim_incomplete') }}
      </div>
      <div v-for="(entitlement, index) in value.entitlements" :key="index" class="mt-2 text-neutral-600" data-test="hourly-preview-entitlement">
        {{ t(entitlement.kind === 'overtime_time_off_accrual' ? 'payroll.hourly_preview.overtime_time_off' : entitlement.kind === 'holiday_time_off_allocation' ? 'payroll.hourly_preview.holiday_time_off_allocation' : 'payroll.hourly_preview.holiday_time_off') }} · {{ entitlement.work_date }} · {{ entitlement.ready ? formatPayrollDuration(entitlement.duration_units) : t('payroll.hourly_preview.claim_incomplete') }}
        <span v-if="entitlement.due_on"> · {{ t('payroll.hourly_preview.due_on', { date: formatDate(entitlement.due_on) }) }}</span>
        <span v-if="entitlement.granted_on"> · {{ formatDate(entitlement.granted_on) }}</span>
      </div>
      <p class="mt-2 text-neutral-500">{{ t(value.rounding === 'ceil' ? 'payroll.hourly_preview.rounding_ceil' : 'payroll.hourly_preview.rounding_half_up') }}</p>
    </template>
    <ul v-if="value.blockers.length" class="mt-2 list-disc space-y-1 pl-4 text-warning-700" data-test="hourly-preview-blockers">
      <li v-for="(blocker, index) in compact ? value.blockers.slice(0, 1) : value.blockers" :key="`${blocker.code}-${index}`">{{ blocker.message }}</li>
    </ul>
    <p v-if="compact && value.blockers.length > 1" class="mt-1 text-warning-700">{{ t('payroll.hourly_preview.more_blockers', { count: value.blockers.length - 1 }) }}</p>
  </section>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import type { PayrollRunResultPerson } from '@/api/payroll'
import { formatDate, formatMoneyMinor } from '@/composables/useFormat'
import { formatPayrollDuration } from '@/pages/payroll/payrollDuration'
import PayrollPersonPicker from './PayrollPersonPicker.vue'

const props = withDefaults(defineProps<{ people: PayrollRunResultPerson[]; personNames?: Record<number, string> }>(), { personNames: () => ({}) })
const { t } = useI18n()
const selectedEmployeeId = ref<number | null>(null)
const peopleWithDetails = computed(() => props.people.filter(person => person.payslip_document?.hourly_claims?.length))
watch(peopleWithDetails, people => {
  if (!people.some(person => person.employee_id === selectedEmployeeId.value)) selectedEmployeeId.value = people[0]?.employee_id ?? null
}, { immediate: true })
const selected = computed(() => peopleWithDetails.value.find(person => person.employee_id === selectedEmployeeId.value)?.payslip_document)
const isCorrection = computed(() => selected.value?.income_lines.some(line => line.hourly_detail?.display_basis === 'delta') ?? false)
function signedDuration(units: number): string {
  return `${units < 0 ? '-' : ''}${formatPayrollDuration(Math.abs(units))}`
}
const options = computed(() => peopleWithDetails.value.map(person => ({ value: person.employee_id, label: props.personNames[person.employee_id] || t('payroll.runs.net.person_fallback', { id: person.employee_id }) })))
</script>

<template>
  <section v-if="peopleWithDetails.length" class="mt-5 overflow-hidden rounded-xl border border-payroll-200 bg-surface" data-test="payslip-hourly-breakdown">
    <div class="border-b border-payroll-100 bg-payroll-50/50 px-4 py-4 sm:px-5">
      <h3 class="font-semibold text-neutral-900">{{ t('payroll.payslip_hourly.title') }}</h3>
      <p class="mt-1 text-sm text-neutral-600">{{ t('payroll.payslip_hourly.frozen') }}</p>
    </div>
    <PayrollPersonPicker v-model="selectedEmployeeId" :options="options" :selector-label="t('payroll.runs.net.people_tabs')" />
    <div v-if="selected" class="space-y-4 p-4 sm:p-5">
      <p v-if="isCorrection" class="rounded-lg border border-payroll-200 bg-payroll-50 p-3 text-sm text-neutral-700" data-test="payslip-hourly-cumulative">{{ t('payroll.payslip_hourly.cumulative') }}</p>
      <div v-for="(line, index) in selected.income_lines.filter(row => row.hourly_detail)" :key="index" class="rounded-lg border border-neutral-200 p-3" data-test="payslip-hourly-line">
        <div class="flex flex-wrap justify-between gap-2 font-medium"><span>{{ line.label }}</span><span>{{ formatMoneyMinor(line.amount_minor_units) }}</span></div>
        <template v-if="line.hourly_detail">
          <p class="mt-1 text-sm text-neutral-600">{{ formatDate(line.hourly_detail.work_period_start) }} · {{ line.hourly_detail.quantity_text ?? (line.hourly_detail.quantity_duration_units === null ? t('payroll.worked_hours.unknown') : formatPayrollDuration(line.hourly_detail.quantity_duration_units)) }}<template v-if="line.hourly_detail.rate_minor !== null"> · {{ t('payroll.hourly_preview.rate', { amount: formatMoneyMinor(line.hourly_detail.rate_minor) }) }}</template></p>
          <p v-if="line.hourly_detail.display_basis === 'delta'" class="mt-1 text-sm text-neutral-500" data-test="payslip-hourly-delta">{{ t('payroll.payslip_hourly.delta', { full: formatMoneyMinor(line.hourly_detail.full_payable_minor), previous: formatMoneyMinor(line.hourly_detail.previous_payable_minor), delta: formatMoneyMinor(line.hourly_detail.delta_amount_minor) }) }}</p>
          <p v-if="line.hourly_detail.display_basis === 'delta'" class="mt-1 text-sm text-neutral-500" data-test="payslip-hourly-hours-delta">{{ t('payroll.payslip_hourly.hours_delta', { hours: signedDuration(line.hourly_detail.worked_duration_delta_units) }) }}</p>
        </template>
      </div>
      <details v-for="claim in selected.hourly_claims" :key="`${claim.employment_id}-${claim.claim_id}`" class="rounded-lg border border-neutral-200 p-3" data-test="payslip-hourly-claim">
        <summary class="cursor-pointer text-sm font-medium">{{ claim.label }} · {{ formatDate(claim.work_period_start) }} · {{ claim.quantity_text ?? formatPayrollDuration(claim.full_duration_units) }} · {{ formatMoneyMinor(claim.payable_minor) }}</summary>
        <p v-if="claim.segment_role === 'minimum_threshold'" class="mt-2 text-xs text-neutral-500">{{ t('payroll.payslip_hourly.minimum_segments') }}</p>
        <p class="mt-2 text-xs text-neutral-500">{{ t('payroll.payslip_hourly.rounding', { amount: formatMoneyMinor(claim.rounding_adjustment_minor) }) }}</p>
        <p v-if="claim.segment_display_adjustment_minor != null && claim.segment_display_adjustment_minor !== 0" class="mt-1 text-xs text-neutral-500">{{ t('payroll.payslip_hourly.segment_rounding', { amount: formatMoneyMinor(claim.segment_display_adjustment_minor) }) }}</p>
        <p v-for="(segment, index) in claim.segments" :key="index" class="mt-2 text-sm text-neutral-600" data-test="payslip-hourly-segment">{{ formatDate(segment.date_from) }} – {{ formatDate(segment.date_to) }} · {{ segment.quantity_text ?? formatPayrollDuration(segment.duration_units) }}<template v-if="segment.rate_minor !== null"> · {{ t('payroll.hourly_preview.rate', { amount: formatMoneyMinor(segment.rate_minor) }) }}</template><template v-if="segment.rate_label"> · {{ segment.rate_label }}</template> · {{ formatMoneyMinor(segment.analytical_minor) }}</p>
      </details>
    </div>
  </section>
</template>

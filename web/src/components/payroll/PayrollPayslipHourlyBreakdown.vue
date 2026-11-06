<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import type { PayrollPayslipHourlyClaim, PayrollRunResultPerson } from '@/api/payroll'
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

/*
 * Nárok, který má na pásce vlastní řádek (typicky „Mzda za odpracovanou
 * dobu"), se nevypisuje podruhé v seznamu nároků — jeho rozpis úseků
 * a zaokrouhlení se rozbalí přímo u řádku. Zbylé nároky bez řádku jsou
 * většinou nulové (svátek ani noc v měsíci nebyly); ty se schovají pod
 * rozbalení, aby mezi nimi nezapadl ten, který se skutečně vyplácí.
 */
const incomeLines = computed(() => (selected.value?.income_lines ?? []).filter(line => line.hourly_detail))
const claimsById = computed(() => new Map((selected.value?.hourly_claims ?? []).map(claim => [claim.claim_id, claim])))
const lineClaimIds = computed(() => new Set(incomeLines.value.map(line => line.hourly_detail!.claim_id)))
function lineClaim(claimId: number): PayrollPayslipHourlyClaim | null {
  return claimsById.value.get(claimId) ?? null
}
function zeroClaim(claim: PayrollPayslipHourlyClaim): boolean {
  // Nulový je nárok bez peněz; doplatek do minima nese hodiny celého měsíce i při nule.
  return claim.payable_minor === 0 && (claim.delta_amount_minor ?? 0) === 0
}
const otherClaims = computed(() => (selected.value?.hourly_claims ?? []).filter(claim => !lineClaimIds.value.has(claim.claim_id)))
const visibleClaims = computed(() => otherClaims.value.filter(claim => !zeroClaim(claim)))
const zeroClaims = computed(() => otherClaims.value.filter(zeroClaim))
const zeroClaimsOpen = ref(false)
watch(selectedEmployeeId, () => { zeroClaimsOpen.value = false })
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
      <div v-for="(line, index) in incomeLines" :key="index" class="rounded-lg border border-neutral-200 p-3" data-test="payslip-hourly-line">
        <div class="flex flex-wrap justify-between gap-2 font-medium"><span>{{ line.label }}</span><span>{{ formatMoneyMinor(line.amount_minor_units) }}</span></div>
        <template v-if="line.hourly_detail">
          <p class="mt-1 text-sm text-neutral-600">{{ formatDate(line.hourly_detail.work_period_start) }} · {{ line.hourly_detail.quantity_text ?? (line.hourly_detail.quantity_duration_units === null ? t('payroll.worked_hours.unknown') : formatPayrollDuration(line.hourly_detail.quantity_duration_units)) }}<template v-if="line.hourly_detail.rate_minor !== null"> · {{ t('payroll.hourly_preview.rate', { amount: formatMoneyMinor(line.hourly_detail.rate_minor) }) }}</template></p>
          <p v-if="line.hourly_detail.display_basis === 'delta'" class="mt-1 text-sm text-neutral-500" data-test="payslip-hourly-delta">{{ t('payroll.payslip_hourly.delta', { full: formatMoneyMinor(line.hourly_detail.full_payable_minor), previous: formatMoneyMinor(line.hourly_detail.previous_payable_minor), delta: formatMoneyMinor(line.hourly_detail.delta_amount_minor) }) }}</p>
          <p v-if="line.hourly_detail.display_basis === 'delta'" class="mt-1 text-sm text-neutral-500" data-test="payslip-hourly-hours-delta">{{ t('payroll.payslip_hourly.hours_delta', { hours: signedDuration(line.hourly_detail.worked_duration_delta_units) }) }}</p>
          <details v-if="lineClaim(line.hourly_detail.claim_id)" class="mt-2" data-test="payslip-hourly-line-claim">
            <summary class="cursor-pointer text-xs font-medium text-payroll-700">{{ t('payroll.payslip_hourly.line_detail') }}</summary>
            <template v-for="claim in [lineClaim(line.hourly_detail.claim_id)!]" :key="claim.claim_id">
              <p v-if="claim.segment_role === 'minimum_threshold'" class="mt-2 text-xs text-neutral-500">{{ t('payroll.payslip_hourly.minimum_segments') }}</p>
              <p class="mt-2 text-xs text-neutral-500">{{ t('payroll.payslip_hourly.rounding', { amount: formatMoneyMinor(claim.rounding_adjustment_minor) }) }}</p>
              <p v-if="claim.segment_display_adjustment_minor != null && claim.segment_display_adjustment_minor !== 0" class="mt-1 text-xs text-neutral-500">{{ t('payroll.payslip_hourly.segment_rounding', { amount: formatMoneyMinor(claim.segment_display_adjustment_minor) }) }}</p>
              <p v-for="(segment, segmentIndex) in claim.segments" :key="segmentIndex" class="mt-2 text-sm text-neutral-600" data-test="payslip-hourly-segment">{{ formatDate(segment.date_from) }} – {{ formatDate(segment.date_to) }} · {{ segment.quantity_text ?? formatPayrollDuration(segment.duration_units) }}<template v-if="segment.rate_minor !== null"> · {{ t('payroll.hourly_preview.rate', { amount: formatMoneyMinor(segment.rate_minor) }) }}</template><template v-if="segment.rate_label"> · {{ segment.rate_label }}</template> · {{ formatMoneyMinor(segment.analytical_minor) }}</p>
            </template>
          </details>
        </template>
      </div>
      <details v-for="claim in visibleClaims" :key="`${claim.employment_id}-${claim.claim_id}`" class="rounded-lg border border-neutral-200 p-3" data-test="payslip-hourly-claim">
        <summary class="cursor-pointer text-sm font-medium">{{ claim.label }} · {{ formatDate(claim.work_period_start) }} · {{ claim.quantity_text ?? formatPayrollDuration(claim.full_duration_units) }} · {{ formatMoneyMinor(claim.payable_minor) }}</summary>
        <p v-if="claim.segment_role === 'minimum_threshold'" class="mt-2 text-xs text-neutral-500">{{ t('payroll.payslip_hourly.minimum_segments') }}</p>
        <p class="mt-2 text-xs text-neutral-500">{{ t('payroll.payslip_hourly.rounding', { amount: formatMoneyMinor(claim.rounding_adjustment_minor) }) }}</p>
        <p v-if="claim.segment_display_adjustment_minor != null && claim.segment_display_adjustment_minor !== 0" class="mt-1 text-xs text-neutral-500">{{ t('payroll.payslip_hourly.segment_rounding', { amount: formatMoneyMinor(claim.segment_display_adjustment_minor) }) }}</p>
        <p v-for="(segment, index) in claim.segments" :key="index" class="mt-2 text-sm text-neutral-600" data-test="payslip-hourly-segment">{{ formatDate(segment.date_from) }} – {{ formatDate(segment.date_to) }} · {{ segment.quantity_text ?? formatPayrollDuration(segment.duration_units) }}<template v-if="segment.rate_minor !== null"> · {{ t('payroll.hourly_preview.rate', { amount: formatMoneyMinor(segment.rate_minor) }) }}</template><template v-if="segment.rate_label"> · {{ segment.rate_label }}</template> · {{ formatMoneyMinor(segment.analytical_minor) }}</p>
      </details>
      <div v-if="zeroClaims.length > 0" data-test="payslip-hourly-zero-claims">
        <button type="button" class="inline-flex items-center gap-1 text-xs font-medium text-payroll-700 hover:underline" :aria-expanded="zeroClaimsOpen" data-test="payslip-hourly-zero-toggle" @click="zeroClaimsOpen = !zeroClaimsOpen">
          <svg class="h-3.5 w-3.5 transition-transform" :class="zeroClaimsOpen ? 'rotate-180' : ''" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m6 9 6 6 6-6" /></svg>
          {{ t(zeroClaimsOpen ? 'payroll.payslip_hourly.hide_zero_claims' : 'payroll.payslip_hourly.show_zero_claims', { count: zeroClaims.length }) }}
        </button>
        <ul v-if="zeroClaimsOpen" class="mt-2 space-y-1 text-sm text-neutral-500">
          <li v-for="claim in zeroClaims" :key="`${claim.employment_id}-${claim.claim_id}`" data-test="payslip-hourly-zero-claim">{{ claim.label }} · {{ formatDate(claim.work_period_start) }} · {{ claim.quantity_text ?? formatPayrollDuration(claim.full_duration_units) }} · {{ formatMoneyMinor(claim.payable_minor) }}</li>
        </ul>
      </div>
    </div>
  </section>
</template>

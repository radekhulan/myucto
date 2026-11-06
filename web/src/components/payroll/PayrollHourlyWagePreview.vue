<script setup lang="ts">
import { computed, ref } from 'vue'
import PayrollMinimumWagePreviewView from './PayrollMinimumWagePreview.vue'
import { useI18n } from 'vue-i18n'
import type { PayrollHourlyWagePreview } from '@/api/payroll'
import { formatDate, formatMoneyMinor } from '@/composables/useFormat'
import { formatPayrollDuration } from '@/pages/payroll/payrollDuration'
import { uniqueBy } from '@/pages/payroll/payrollFieldFeedback'

const props = defineProps<{ value: PayrollHourlyWagePreview; compact?: boolean }>()
const { t, te } = useI18n()
/** Text blokace podle kódu v jazyce aplikace; serverová zpráva je jen záloha. */
function blockerMessage(blocker: { code: string; message: string }): string {
  const key = `payroll.hourly_preview.blockers.${blocker.code}`
  return te(key) ? t(key) : blocker.message
}
/*
 * Server hlásí blokaci po úsecích pracovní doby — „doložte příplatkové
 * vrstvy" tak přišla čtyřikrát pro čtyři úseky. Pro člověka je to jedna
 * věc, kterou má udělat, proto se stejná věta ukazuje jen jednou.
 */
const blockerMessages = computed(() => uniqueBy(props.value.blockers.map(blockerMessage), message => message))
const purposes: Record<string, string> = {
  minimum_wage_top_up: 'minimum_wage_top_up',
  ordinary_base: 'ordinary_base', achieved_overtime: 'achieved_overtime', premium_overtime: 'premium_overtime',
  premium_holiday: 'premium_holiday', premium_night: 'premium_night', premium_weekend: 'premium_weekend',
  premium_difficult_environment: 'premium_difficult_environment', holiday_nonworked_compensation: 'holiday_nonworked_compensation',
  holiday_time_off_compensation: 'holiday_time_off_compensation',
}
function purposeLabel(purpose: string): string {
  return t(`payroll.hourly_preview.${purposes[purpose] ?? 'other_claim'}`)
}
/*
 * Doložený nulový nárok (svátek ani noc v měsíci nebyly) je správný výsledek,
 * ale mezi nároky, které se vyplácejí, jen překáží. Schová se pod rozbalení;
 * nedoložený nárok zůstává vidět vždy, protože na něj má účetní reagovat.
 */
type Claim = PayrollHourlyWagePreview['claims'][number]
function zeroClaim(claim: Claim): boolean {
  if (claim.purpose === 'minimum_wage_top_up' && props.value.minimum_wage?.complete === false) return false
  return claim.ready && claim.payable_minor === 0 && (claim.duration_units ?? 0) === 0
}
const visibleClaims = computed(() => props.value.claims.filter(claim => !zeroClaim(claim)))
const zeroClaims = computed(() => props.value.claims.filter(zeroClaim))
const zeroClaimsOpen = ref(false)
const minimumTopUp = computed(() => {
  const minimum = props.value.minimum_wage
  if (!minimum) return null
  return minimum.complete && minimum.claim.ready && minimum.claim.payable_minor !== null
    ? formatMoneyMinor(minimum.claim.payable_minor)
    : t('payroll.worked_hours.unknown')
})
</script>

<template>
  <!--
    Kompaktní řádek pro rychlý měsíční vstup: jedna řádka souhrnu,
    důvody blokace až po rozbalení. Úplný rozpis je v panelu podkladů hodin.
  -->
  <section v-if="compact" class="flex min-w-0 flex-wrap items-center gap-x-3 gap-y-1 text-xs" data-test="hourly-wage-preview">
    <span class="font-medium text-neutral-900">{{ t(value.settlement_only ? 'payroll.hourly_preview.settlement_title' : 'payroll.hourly_preview.title') }}</span>
    <span class="font-medium tabular-nums" data-test="hourly-preview-total">
      {{ t('payroll.hourly_preview.total') }}:
      {{ value.payable_total_minor === null ? t('payroll.worked_hours.unknown') : formatMoneyMinor(value.payable_total_minor) }}
    </span>
    <span v-if="value.settlement_only" class="text-neutral-500" data-test="minimum-settlement-only">{{ t('payroll.minimum.settlement_only') }}</span>
    <span v-else-if="minimumTopUp !== null" class="text-neutral-600 tabular-nums" data-test="hourly-preview-minimum">{{ t('payroll.minimum.top_up') }}: {{ minimumTopUp }}</span>
    <details v-if="blockerMessages.length" class="group min-w-0 basis-full" data-test="hourly-preview-blockers-toggle">
      <summary class="flex cursor-pointer list-none items-start gap-1 text-warning-700">
        <svg class="mt-px h-3.5 w-3.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 9v4m0 4h.01M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z" /></svg>
        <span class="min-w-0">{{ blockerMessages[0] }}<template v-if="blockerMessages.length > 1"> · {{ t('payroll.hourly_preview.more_blockers', { count: blockerMessages.length - 1 }) }}</template></span>
      </summary>
      <ul class="mt-1 list-disc space-y-1 pl-5 text-warning-700" data-test="hourly-preview-blockers">
        <li v-for="message in blockerMessages" :key="message">{{ message }}</li>
      </ul>
    </details>
  </section>
  <section v-else class="mt-3 rounded-md border border-neutral-200 bg-neutral-50 p-3 text-xs" data-test="hourly-wage-preview">
    <p class="font-medium text-neutral-900">{{ t(value.settlement_only ? 'payroll.hourly_preview.settlement_title' : 'payroll.hourly_preview.title') }}</p>
    <p class="mt-1 text-neutral-500">{{ t('payroll.hourly_preview.saved_evidence') }}</p>
    <p class="mt-2 font-medium" data-test="hourly-preview-total">
      {{ t('payroll.hourly_preview.total') }}:
      {{ value.payable_total_minor === null ? t('payroll.worked_hours.unknown') : formatMoneyMinor(value.payable_total_minor) }}
    </p>
    <p v-if="value.settlement_only" class="mt-2 text-neutral-500" data-test="minimum-settlement-only">{{ t('payroll.minimum.settlement_only') }}</p>
    <PayrollMinimumWagePreviewView v-else-if="value.minimum_wage" :value="value.minimum_wage" />
    <div v-for="(claim, index) in visibleClaims" :key="`${claim.component_code}-${index}`" class="mt-3 border-t border-neutral-200 pt-2" data-test="hourly-preview-claim">
      <p class="font-medium">{{ purposeLabel(claim.purpose) }} · <template v-if="claim.ready && (claim.purpose !== 'minimum_wage_top_up' || value.minimum_wage?.complete !== false)">{{ claim.duration_units === null ? t('payroll.worked_hours.unknown') : formatPayrollDuration(claim.duration_units) }} · {{ claim.payable_minor === null ? t('payroll.worked_hours.unknown') : formatMoneyMinor(claim.payable_minor) }}</template><template v-else>{{ t('payroll.hourly_preview.claim_incomplete') }}</template></p>
      <p v-for="(segment, segmentIndex) in claim.segments" :key="segmentIndex" class="mt-1 text-neutral-600" data-test="hourly-preview-segment">
        <template v-if="segment.date_from">{{ formatDate(segment.date_from) }}<template v-if="segment.date_to"> – {{ formatDate(segment.date_to) }}</template> · </template>
        <template v-else-if="segment.date || segment.granted_on">{{ formatDate((segment.date || segment.granted_on)!) }} · </template>
        <template v-if="segment.duration_units != null || segment.regular_worked_duration_units != null">{{ formatPayrollDuration(segment.duration_units ?? segment.regular_worked_duration_units ?? null) }}</template>
        <template v-if="segment.minimum_hourly_minor != null"> · {{ t('payroll.minimum.hourly_rate', { amount: formatMoneyMinor(segment.minimum_hourly_minor) }) }}</template>
        <template v-else-if="segment.hourly_rate_minor != null"> · {{ t('payroll.hourly_preview.rate', { amount: formatMoneyMinor(segment.hourly_rate_minor) }) }}</template>
        <template v-else-if="segment.monthly_gross_minor != null"> · {{ t('payroll.hourly_preview.monthly_part') }}</template>
      </p>
    </div>
    <div v-if="zeroClaims.length" class="mt-3 border-t border-neutral-200 pt-2" data-test="hourly-preview-zero-claims">
      <button type="button" class="inline-flex items-center gap-1 font-medium text-payroll-700 hover:underline" :aria-expanded="zeroClaimsOpen" data-test="hourly-preview-zero-toggle" @click="zeroClaimsOpen = !zeroClaimsOpen">
        <svg class="h-3.5 w-3.5 transition-transform" :class="zeroClaimsOpen ? 'rotate-180' : ''" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m6 9 6 6 6-6" /></svg>
        {{ t(zeroClaimsOpen ? 'payroll.hourly_preview.hide_zero_claims' : 'payroll.hourly_preview.show_zero_claims', { count: zeroClaims.length }) }}
      </button>
      <ul v-if="zeroClaimsOpen" class="mt-1 space-y-1 text-neutral-500">
        <li v-for="(claim, index) in zeroClaims" :key="`${claim.component_code}-zero-${index}`" data-test="hourly-preview-zero-claim">{{ purposeLabel(claim.purpose) }} · {{ formatPayrollDuration(0) }} · {{ formatMoneyMinor(0) }}</li>
      </ul>
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
    <ul v-if="blockerMessages.length" class="mt-2 list-disc space-y-1 pl-4 text-warning-700" data-test="hourly-preview-blockers">
      <li v-for="message in blockerMessages" :key="message">{{ message }}</li>
    </ul>
  </section>
</template>

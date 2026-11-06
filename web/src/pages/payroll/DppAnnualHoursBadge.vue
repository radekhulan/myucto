<script setup lang="ts">
/**
 * Značka ročních hodin na DPP proti limitu 300 h (§ 75 zákoníku práce).
 *
 * Účetní u stovek brigádníků potřebuje vidět, kdo se k limitu blíží, dřív než
 * ho překročí. Součet i zařazení počítá server (PayrollAgreementAnnualHours);
 * tady se jen zobrazuje, aby seznam a varování ve mzdách tvrdily totéž.
 */
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { PayrollAgreementAnnualHours } from '@/api/payroll'

const props = defineProps<{
  hours: PayrollAgreementAnnualHours
  testId: string
}>()

const { t, locale } = useI18n()

function format(minutes: number): string {
  return new Intl.NumberFormat(locale.value, { maximumFractionDigits: 2 }).format(minutes / 60)
}

const tone = computed(() => {
  if (props.hours.status === 'exceeded') return 'bg-danger-50 text-danger-700'
  if (props.hours.status === 'approaching') return 'bg-warning-50 text-warning-700'
  return 'bg-neutral-100 text-neutral-600'
})

const title = computed(() => {
  const params = {
    year: props.hours.year,
    hours: format(props.hours.total_minutes),
    limit: format(props.hours.limit_minutes),
    remaining: format(props.hours.remaining_minutes),
  }
  const main = t(`payroll.people.dpp_hours.title_${props.hours.status}`, params)
  return props.hours.takeover_minutes > 0
    ? `${main} ${t('payroll.people.dpp_hours.takeover', { hours: format(props.hours.takeover_minutes) })}`
    : main
})
</script>

<template>
  <span
    class="inline-flex items-center gap-1 whitespace-nowrap rounded-full px-2 py-1 text-xs font-medium"
    :class="tone"
    :title="title"
    :aria-label="title"
    :data-test="testId"
    :data-status="hours.status"
  >
    <svg
      v-if="hours.status !== 'ok'"
      class="h-3.5 w-3.5"
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      stroke-width="2"
      aria-hidden="true"
    >
      <path d="M12 9v4m0 4h.01M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z" />
    </svg>
    {{ t('payroll.people.dpp_hours.label', { hours: format(hours.total_minutes), limit: format(hours.limit_minutes) }) }}
  </span>
</template>

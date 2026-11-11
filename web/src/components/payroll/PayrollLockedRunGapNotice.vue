<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { PayrollLockedRunGap } from '@/api/payroll'
import { btnOutlineSm, ICONS } from '@/components/ui/buttonStyles'
import { formatDate, formatPeriod } from '@/composables/useFormat'

/**
 * Vztah patří do měsíce, který už má schválený mzdový běh, ale v běhu chybí.
 *
 * Zaměstnanec založený až po schválení měsíce (nebo s nástupem posunutým
 * dozadu) dřív zůstal bez mzdy i bez měsíčního hlášení a nikde to nesvítilo.
 * Schválený běh se sám nezmění, proto upozornění vede rovnou na běh daného
 * období, kde jde vyžádat oprava.
 */
const props = defineProps<{
  gaps: PayrollLockedRunGap[]
  startDate: string | null
}>()

const { t } = useI18n()
const periods = computed(() => props.gaps.map(gap => gap.period))
</script>

<template>
  <div
    v-if="gaps.length > 0"
    class="mt-3 flex items-start gap-3 rounded-lg border border-warning-200 bg-warning-50 p-3 text-sm text-warning-800"
    role="alert"
    data-test="employment-locked-run-gap"
  >
    <svg class="mt-0.5 h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
      <path :d="ICONS.bell" />
    </svg>
    <div class="min-w-0 flex-1 space-y-2">
      <p class="font-medium">
        {{ startDate
          ? t('payroll.people.locked_run_gap.title_with_start', { date: formatDate(startDate) }, periods.length)
          : t('payroll.people.locked_run_gap.title', periods.length) }}
      </p>
      <p>{{ t('payroll.people.locked_run_gap.body') }}</p>
      <div class="flex flex-wrap gap-2">
        <RouterLink
          v-for="gap in gaps"
          :key="gap.run_id"
          :to="{ name: 'payroll-runs', query: { period: gap.period } }"
          :class="[btnOutlineSm('warning'), 'whitespace-nowrap']"
          :data-test="`employment-locked-run-gap-link-${gap.period}`"
        >
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.uturn" /></svg>
          {{ t('payroll.people.locked_run_gap.open_run', { period: formatPeriod(gap.period) }) }}
        </RouterLink>
      </div>
    </div>
  </div>
</template>

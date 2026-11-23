<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import type { PayrollManualTimeSummary, PayrollTimeWorkSource } from '@/api/payroll'
import { formatDate } from '@/composables/useFormat'
import { formatPayrollDuration } from '@/pages/payroll/payrollDuration'
import { btnOutlineSm, ICONS } from '@/components/ui/buttonStyles'
import PayrollSwitchToEntriesButton from '@/components/payroll/PayrollSwitchToEntriesButton.vue'

defineProps<{
  source: PayrollTimeWorkSource
  manualSummary?: PayrollManualTimeSummary | null
  employmentId: number
  period: string
  /** Verze otevřeného měsíce; bez ní (nebo bez práva) se „Počítat ze směn" nenabízí. */
  monthRowVersion?: number | null
  canSwitch?: boolean
  name?: string | null
  /** Publikované směny měsíce, které jde při přepnutí převzít jako odpracovaný čas. */
  publishedShiftCount?: number
}>()
const emit = defineEmits<{ switched: [] }>()
const { t } = useI18n()
</script>

<template>
  <div class="mt-2 space-y-1 text-xs" data-test="time-selected-source">
    <p class="font-medium text-payroll-700">{{ t(source === 'manual_summary' ? 'payroll.worked_hours.source_manual' : 'payroll.worked_hours.source_import') }}</p>
    <p class="text-neutral-500">{{ t('payroll.worked_hours.selected_source_hint') }}</p>
    <details v-if="manualSummary" class="rounded-md border border-neutral-200 bg-surface p-2" data-test="time-manual-segments">
      <summary class="cursor-pointer font-medium text-neutral-700">{{ t('payroll.worked_hours.revision', { number: manualSummary.time_month_revision_no }) }}</summary>
      <p class="mt-2 break-words">{{ manualSummary.reason }}</p>
      <p v-for="segment in manualSummary.segments" :key="`${segment.date_from}:${segment.date_to}`" class="mt-1">
        {{ formatDate(segment.date_from) }} — {{ formatDate(segment.date_to) }}: {{ formatPayrollDuration(segment.worked_duration_units) }}
      </p>
    </details>
    <div class="flex flex-wrap gap-2">
      <RouterLink :to="{ name: 'payroll-quick-inputs', query: { employment: String(employmentId), period, hours: '1' } }" :class="[btnOutlineSm('neutral'), 'whitespace-nowrap']" data-test="time-worked-source-link"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.table" /></svg>{{ t('payroll.worked_hours.open') }}</RouterLink>
      <PayrollSwitchToEntriesButton
        v-if="source === 'import_summary' && canSwitch && monthRowVersion != null"
        :employment-id="employmentId"
        :period="period"
        :month-row-version="monthRowVersion"
        :name="name"
        :published-shift-count="publishedShiftCount ?? 0"
        :entry-count="0"
        @switched="emit('switched')"
      />
    </div>
  </div>
</template>

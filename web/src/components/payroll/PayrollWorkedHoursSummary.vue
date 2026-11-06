<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import type { PayrollWorkedTime } from '@/api/payroll'
import { formatPayrollDuration } from '@/pages/payroll/payrollDuration'
import { btnOutlineSm, ICONS } from '@/components/ui/buttonStyles'

/** `inline` = jedna řádka pro řádek tabulky rychlého vstupu (tlačítko na začátku). */
defineProps<{ value: PayrollWorkedTime; settlementOnly?: boolean; inline?: boolean }>()
defineEmits<{ open: [] }>()
const { t } = useI18n()
</script>

<template>
  <div :class="inline ? 'flex flex-wrap items-center gap-x-3 gap-y-1 text-xs' : 'mt-2 space-y-1 text-xs'" data-test="worked-hours-summary">
    <button v-if="inline" type="button" :class="[btnOutlineSm('neutral'), 'whitespace-nowrap']" data-test="worked-hours-open" @click="$emit('open')">
      <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.table" /></svg>
      {{ t(settlementOnly ? 'payroll.hourly_preview.open_settlement' : 'payroll.worked_hours.open') }}
    </button>
    <template v-if="!settlementOnly">
    <component :is="inline ? 'span' : 'p'" class="font-medium text-neutral-900">
      {{ t('payroll.worked_hours.worked') }}:
      {{ value.worked_duration_units === null ? t('payroll.worked_hours.unknown') : formatPayrollDuration(value.worked_duration_units) }}
    </component>
    <component :is="inline ? 'span' : 'p'" class="text-neutral-500">
      {{ t(!value.month?.id ? 'payroll.worked_hours.source_none' : value.month.work_source === 'manual_summary' ? 'payroll.worked_hours.source_manual' : value.month.work_source === 'import_summary' ? 'payroll.worked_hours.source_import' : 'payroll.worked_hours.source_attendance') }} ·
      {{ t(value.month?.status === 'approved' ? 'payroll.worked_hours.approved' : 'payroll.worked_hours.unapproved') }}
      <span v-if="value.month"> · {{ t('payroll.worked_hours.revision', { number: value.month.revision_no }) }}</span>
    </component>
    <component :is="inline ? 'span' : 'p'" v-if="value.month?.status === 'approved' && !value.jmhz_confirmed" class="text-warning-700">{{ t('payroll.worked_hours.jmhz_pending') }}</component>
    </template>
    <button v-if="!inline" type="button" :class="[btnOutlineSm('neutral'), 'whitespace-nowrap']" data-test="worked-hours-open" @click="$emit('open')">
      <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.table" /></svg>
      {{ t(settlementOnly ? 'payroll.hourly_preview.open_settlement' : 'payroll.worked_hours.open') }}
    </button>
  </div>
</template>

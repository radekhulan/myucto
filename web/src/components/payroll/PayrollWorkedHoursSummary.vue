<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import type { PayrollWorkedTime } from '@/api/payroll'
import { formatPayrollDuration } from '@/pages/payroll/payrollDuration'
import { btnOutlineSm, ICONS } from '@/components/ui/buttonStyles'

defineProps<{ value: PayrollWorkedTime }>()
defineEmits<{ open: [] }>()
const { t } = useI18n()
</script>

<template>
  <div class="mt-2 space-y-1 text-xs" data-test="worked-hours-summary">
    <p class="font-medium text-neutral-900">
      {{ t('payroll.worked_hours.worked') }}:
      {{ value.worked_duration_units === null ? t('payroll.worked_hours.unknown') : formatPayrollDuration(value.worked_duration_units) }}
    </p>
    <p class="text-neutral-500">
      {{ t(!value.month?.id ? 'payroll.worked_hours.source_none' : value.month.work_source === 'manual_summary' ? 'payroll.worked_hours.source_manual' : value.month.work_source === 'import_summary' ? 'payroll.worked_hours.source_import' : 'payroll.worked_hours.source_attendance') }} ·
      {{ t(value.month?.status === 'approved' ? 'payroll.worked_hours.approved' : 'payroll.worked_hours.unapproved') }}
      <span v-if="value.month"> · {{ t('payroll.worked_hours.revision', { number: value.month.revision_no }) }}</span>
    </p>
    <p v-if="value.month?.status === 'approved' && !value.jmhz_confirmed" class="text-warning-700">{{ t('payroll.worked_hours.jmhz_pending') }}</p>
    <button type="button" :class="[btnOutlineSm('neutral'), 'whitespace-nowrap']" data-test="worked-hours-open" @click="$emit('open')">
      <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.table" /></svg>
      {{ t('payroll.worked_hours.open') }}
    </button>
  </div>
</template>

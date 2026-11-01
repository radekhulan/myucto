<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import { btnOutlineSm, ICONS } from '@/components/ui/buttonStyles'
import { formatMoneyMinor, formatPeriod } from '@/composables/useFormat'
import type { TakeoverJmhzFormDifference } from './takeoverMonths'

/**
 * Přijaté hlášení JMHZ předchozího programu nese za osobu a měsíc jiné údaje
 * než převzatá (konečná) mzda — hlášení odešlo před opravou mzdy a opravné
 * nepřišlo. Převzetí je správné, upozornění je podnět k opravnému hlášení.
 * U každého nálezu je karta osoby, převzatá mzda a importované hlášení.
 */
defineProps<{ rows: TakeoverJmhzFormDifference[] }>()

const { t } = useI18n()
</script>

<template>
  <ul class="space-y-3" data-test="takeover-jmhz-form-findings">
    <li
      v-for="row in rows"
      :key="`${row.employee_id}-${row.period}`"
      class="rounded-lg border border-warning-500/30 bg-surface p-3"
      :data-test="`takeover-jmhz-form-${row.employee_id}-${row.period}`"
    >
      <p class="font-medium text-neutral-800">
        {{ t(row.takeover_incomplete ? 'payroll.takeover_check.jmhz_form_incomplete_person' : 'payroll.takeover_check.jmhz_form_person', { name: row.employee_name, period: formatPeriod(row.period) }) }}
      </p>
      <div class="mt-2 overflow-x-auto">
        <table class="min-w-full text-xs">
          <thead class="border-b border-neutral-200 text-left text-neutral-500">
            <tr>
              <th class="px-2 py-1 font-medium">{{ t('payroll.takeover_check.metric_column') }}</th>
              <th class="px-2 py-1 text-right font-medium">{{ t('payroll.takeover_check.jmhz_form_reported') }}</th>
              <th class="px-2 py-1 text-right font-medium">{{ t('payroll.takeover_check.takeover') }}</th>
              <th class="px-2 py-1 text-right font-medium">{{ t('payroll.takeover_check.difference') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="difference in row.differences"
              :key="difference.metric"
              class="border-b border-neutral-100"
              :data-test="`takeover-jmhz-form-${row.employee_id}-${row.period}-${difference.metric}`"
            >
              <td class="px-2 py-1 text-neutral-700">{{ t(`payroll.takeover_check.jmhz_form_metric.${difference.metric}`) }}</td>
              <td class="px-2 py-1 text-right tabular-nums">{{ formatMoneyMinor(difference.jmhz_minor) }}</td>
              <td class="px-2 py-1 text-right tabular-nums">{{ formatMoneyMinor(difference.takeover_minor) }}</td>
              <td class="px-2 py-1 text-right font-semibold tabular-nums text-warning-700">{{ formatMoneyMinor(difference.difference_minor) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
      <div class="mt-2 flex flex-wrap gap-2">
        <RouterLink
          :to="{ name: 'payroll-person', params: { id: row.employee_id } }"
          :class="[btnOutlineSm('neutral'), 'whitespace-nowrap']"
          :data-test="`takeover-jmhz-form-person-${row.employee_id}-${row.period}`"
        >
          <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path :d="ICONS.user" />
          </svg>
          {{ t('payroll.takeover_check.open_person') }}
        </RouterLink>
        <RouterLink
          :to="{ name: 'payroll-imports', query: { tab: 'takeover', employee: String(row.employee_id) } }"
          :class="[btnOutlineSm('neutral'), 'whitespace-nowrap']"
          :data-test="`takeover-jmhz-form-wage-${row.employee_id}-${row.period}`"
        >
          <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path :d="ICONS.coin" />
          </svg>
          {{ t('payroll.takeover_check.jmhz_form_open_wage') }}
        </RouterLink>
        <RouterLink
          :to="{ name: 'payroll-submissions-tab', params: { tab: 'jmhz' }, query: { external: String(row.submission_id) }, hash: '#external-submissions' }"
          :class="[btnOutlineSm('primary'), 'whitespace-nowrap']"
          :data-test="`takeover-jmhz-form-submission-${row.employee_id}-${row.period}`"
        >
          <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path :d="ICONS.doc" />
          </svg>
          {{ t('payroll.takeover_check.jmhz_form_open_submission') }}
        </RouterLink>
      </div>
    </li>
  </ul>
</template>

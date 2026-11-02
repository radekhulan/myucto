<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import type { StatementAccountsReport, StatementColumnAccount, StatementColumnAmounts } from '@/api/accounting'
import { formatDate } from '@/composables/useFormat'

const props = defineProps<{
  report: StatementAccountsReport
  part: 'balance' | 'profit_loss'
  format: (value: number | null | undefined) => string
  hideZero: boolean
  showAnalytics: boolean
}>()

const emit = defineEmits<{
  (e: 'update:hideZero', value: boolean): void
  (e: 'update:showAnalytics', value: boolean): void
}>()

const { t } = useI18n()

const sides = ['left', 'right'] as const
const amountKeys = ['ps', 'month', 'ytd', 'ks'] as const

const columns = computed(() => props.report.columns ?? null)
const lines = computed(() => columns.value?.[props.part].lines ?? [])

const monthLabel = computed(() => {
  const from = columns.value?.month_from ?? ''
  return t('accounting.statement_accounts.col_month', { month: `${from.slice(5, 7)}/${from.slice(0, 4)}` })
})
const ytdLabel = computed(() => t('accounting.statement_accounts.col_ytd', { date: formatDate(columns.value?.ytd_from ?? '') }))

function sideTitle(side: 'left' | 'right'): string {
  const key = props.part === 'balance'
    ? (side === 'left' ? 'side_assets' : 'side_liabilities')
    : (side === 'left' ? 'side_expenses' : 'side_revenues')
  return t(`accounting.statement_accounts.${key}`)
}

function totalLabel(key: string, side: 'left' | 'right'): string {
  if (key === 'section') return side === 'left' ? t('accounting.statement_accounts.total_expenses') : t('accounting.statement_accounts.total_revenues')
  if (side === 'left') return t('accounting.statement_accounts.total_assets')
  return key === 'balance_with_result' ? t('accounting.statement_accounts.total_liabilities_result') : t('accounting.statement_accounts.total_liabilities')
}

/** Proklik vede na inventuru účtu, odtud je opis, otevřené položky i protokol. */
function accountLink(cell: StatementColumnAccount) {
  return {
    name: 'accounting-account-statement',
    params: { accountId: cell.account_id },
    query: { from: props.report.period.starts_on, to: props.report.as_of, mode: 'inventory' },
  }
}

function amountClass(cell: StatementColumnAccount, key: keyof StatementColumnAmounts): string {
  return (key === 'ps' && cell.ps_unusual) || (key === 'ks' && cell.ks_unusual) ? 'text-danger-600 font-semibold' : ''
}

const closedAtYearEnd = computed(() => props.report.closed && props.report.as_of === props.report.period.ends_on)
</script>

<template>
  <div>
    <div class="flex flex-wrap items-center gap-x-4 gap-y-2 mb-3 text-xs text-neutral-500">
      <span>{{ t('accounting.statement_accounts.note_before_closing') }}</span>
      <span v-if="closedAtYearEnd" class="px-2 py-0.5 rounded bg-neutral-100 text-neutral-600" data-test="accounts-closed-note">
        {{ t('accounting.statement_accounts.closed_note') }}
      </span>
      <div class="flex flex-wrap items-center gap-x-4 gap-y-2 ml-auto">
        <label class="inline-flex items-center gap-1.5 cursor-pointer whitespace-nowrap">
          <input :checked="hideZero" type="checkbox" class="rounded border-neutral-300" data-test="accounts-zero-toggle"
            @change="emit('update:hideZero', ($event.target as HTMLInputElement).checked)">
          {{ t('accounting.statement_accounts.hide_zero') }}
        </label>
        <label class="inline-flex items-center gap-1.5 cursor-pointer whitespace-nowrap">
          <input :checked="showAnalytics" type="checkbox" class="rounded border-neutral-300" data-test="accounts-analytics-toggle"
            @change="emit('update:showAnalytics', ($event.target as HTMLInputElement).checked)">
          {{ t('accounting.statement_accounts.show_analytics') }}
        </label>
      </div>
    </div>

    <div v-if="!report.checks.profit_matches && report.dimension"
      class="bg-warning-50 border border-warning-200 rounded-lg p-3 mb-4 text-sm text-warning-800" data-test="accounts-profit-mismatch-dimension">
      {{ t('accounting.statement_accounts.profit_mismatch_dimension', {
        balance: format(report.checks.profit_balance),
        pl: format(report.checks.profit_loss),
      }) }}
    </div>
    <div v-else-if="!report.checks.profit_matches"
      class="bg-danger-50 border border-danger-200 rounded-lg p-3 mb-4 text-sm text-danger-700" data-test="accounts-profit-mismatch">
      {{ t('accounting.statement_accounts.profit_mismatch', {
        balance: format(report.checks.profit_balance),
        pl: format(report.checks.profit_loss),
      }) }}
    </div>
    <div v-if="part === 'profit_loss' && report.checks.unassigned_count > 0"
      class="bg-warning-50 border border-warning-200 rounded-lg p-3 mb-4 text-sm text-warning-800" data-test="accounts-unassigned">
      {{ t('accounting.statement_accounts.unassigned_hint', { count: report.checks.unassigned_count }) }}
    </div>

    <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden mb-4">
      <div class="overflow-x-auto">
        <table class="w-full min-w-[1180px] text-sm">
          <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
            <tr>
              <th v-for="side in sides" :key="side" colspan="6"
                class="px-3 py-2 text-left font-semibold text-neutral-700" :class="side === 'right' ? 'border-l border-neutral-300' : ''"
                :data-test="`accounts-side-${side}`">
                {{ sideTitle(side) }}
              </th>
            </tr>
            <tr>
              <template v-for="side in sides" :key="side">
                <th class="px-2 py-2 text-left font-medium w-20" :class="side === 'right' ? 'border-l border-neutral-300' : ''">{{ t('accounting.statement_accounts.col_account') }}</th>
                <th class="px-2 py-2 text-left font-medium">{{ t('accounting.statement_accounts.col_name') }}</th>
                <th class="px-2 py-2 text-right font-medium w-24">{{ t('accounting.statement_accounts.col_ps') }}</th>
                <th class="px-2 py-2 text-right font-medium w-24">{{ monthLabel }}</th>
                <th class="px-2 py-2 text-right font-medium w-24">{{ ytdLabel }}</th>
                <th class="px-2 py-2 text-right font-medium w-24">{{ t('accounting.statement_accounts.col_ks') }}</th>
              </template>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <template v-for="(line, i) in lines" :key="i">
              <tr v-if="line.kind === 'section'" class="bg-neutral-100 font-semibold" data-test="accounts-section">
                <td colspan="12" class="px-2 py-1.5">{{ t(`accounting.statement_accounts.section_${line.key}`) }}</td>
              </tr>

              <tr v-else-if="line.kind === 'cells'" class="hover:bg-neutral-50" data-test="accounts-line">
                <template v-for="side in sides" :key="side">
                  <template v-if="line[side]?.kind === 'class'">
                    <td colspan="2" class="px-2 py-1 bg-neutral-50 font-semibold text-xs" :class="side === 'right' ? 'border-l border-neutral-300' : ''">
                      {{ t('accounting.statement_accounts.class_label', { cls: line[side].class }) }} · {{ t(`accounting.statement_accounts.class_${line[side].class}`) }}
                    </td>
                    <td v-for="k in amountKeys" :key="k" class="px-2 py-1 bg-neutral-50 font-semibold text-xs text-right font-mono whitespace-nowrap">{{ format(line[side][k]) }}</td>
                  </template>
                  <template v-else-if="line[side]">
                    <td class="px-2 whitespace-nowrap"
                      :class="[side === 'right' ? 'border-l border-neutral-300' : '', line[side].kind === 'analytic' ? 'py-1 pl-5 text-xs' : 'py-1.5']">
                      <RouterLink :to="accountLink(line[side])" :title="t('accounting.statement_accounts.open_inventory')"
                        class="font-mono text-primary-600 hover:text-primary-700 hover:underline">
                        {{ line[side].account_code }}
                      </RouterLink>
                    </td>
                    <td class="px-2" :class="line[side].kind === 'analytic' ? 'py-1 text-xs text-neutral-600' : 'py-1.5'">{{ line[side].name }}</td>
                    <td v-for="k in amountKeys" :key="k" class="px-2 text-right font-mono whitespace-nowrap"
                      :class="[line[side].kind === 'analytic' ? 'py-1 text-xs text-neutral-600' : 'py-1.5', amountClass(line[side], k)]"
                      :data-test="`amount-${line[side].account_code}-${k}`">
                      {{ format(line[side][k]) }}
                    </td>
                  </template>
                  <td v-else colspan="6" :class="side === 'right' ? 'border-l border-neutral-300' : ''"></td>
                </template>
              </tr>

              <tr v-else-if="line.kind === 'total'" class="font-semibold bg-neutral-50 border-t border-neutral-300" data-test="accounts-total">
                <template v-for="side in sides" :key="side">
                  <td colspan="2" class="px-2 py-1.5" :class="side === 'right' ? 'border-l border-neutral-300' : ''">{{ totalLabel(line.key, side) }}</td>
                  <td v-for="k in amountKeys" :key="k" class="px-2 py-1.5 text-right font-mono whitespace-nowrap">{{ format(line[side][k]) }}</td>
                </template>
              </tr>

              <tr v-else-if="line.kind === 'result'" class="font-semibold"
                :class="line.key === 'profit_row' ? (line.amounts.ks >= 0 ? 'bg-success-50 text-success-800' : 'bg-danger-50 text-danger-700') : 'bg-primary-50/40'"
                :data-test="line.key === 'profit_row' ? 'accounts-profit-row' : undefined">
                <td colspan="6"></td>
                <td colspan="2" class="px-2 py-1.5 border-l border-neutral-300">
                  {{ t(`accounting.statement_accounts.${line.key}`) }}
                  <template v-if="line.key === 'profit_row'">
                    ({{ line.amounts.ks >= 0 ? t('accounting.statement_accounts.profit') : t('accounting.statement_accounts.loss') }})
                  </template>
                </td>
                <td v-for="k in amountKeys" :key="k" class="px-2 py-1.5 text-right font-mono whitespace-nowrap">{{ format(line.amounts[k]) }}</td>
              </tr>
            </template>
          </tbody>
        </table>
      </div>
    </div>
    <p class="text-xs text-neutral-500 mb-4">{{ t('accounting.statement_accounts.sign_hint') }}</p>
  </div>
</template>

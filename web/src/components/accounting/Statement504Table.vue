<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { Statement504Report, Statement504Row } from '@/api/accounting'
import { formatDate } from '@/composables/useFormat'

const props = defineProps<{ report: Statement504Report; format: (value: number | null | undefined) => string; part?: 'balance' | 'profit_loss'; unit?: 'czk' | 'thousands' }>()
const { t } = useI18n()
function statementRows(rows: Statement504Row[], columns: ('main' | 'economic' | 'total' | 'start' | 'end')[]) {
  const round = (value: number) => Math.sign(value) * Math.round(Math.abs(value) / 1000)
  return rows.flatMap(row => {
    const parent = { code: row.display_code, label: row.label, values: columns.map(column => row[column]) }
    const parts = (row.components ?? []).map(part => ({ code: '', label: part.label, values: columns.map(column => part[column]) }))
    if (props.unit === 'thousands' && parts.length) {
      for (let col = 0; col < columns.length; col++) {
        const rounded = parts.map(part => round(part.values[col] ?? 0))
        rounded[0] = (rounded[0] ?? 0) + round(parent.values[col] ?? 0) - rounded.reduce((sum, value) => sum + value, 0)
        parts.forEach((part, i) => { part.values[col] = (rounded[i] ?? 0) * 1000 })
      }
    }
    return [parent, ...parts]
  })
}
const tables = computed(() => {
  const r = props.report
  if (r.statement_type === 'income_statement' || (r.statement_type === 'account_view' && props.part === 'profit_loss')) {
    return [{ title: t('accounting.statement504.income'), columns: ['main', 'economic', 'total'],
      rows: statementRows(r.rows ?? r.profit_loss ?? [], ['main', 'economic', 'total']) }]
  }
  if (r.statement_type === 'account_view') return [{ title: t('accounting.statement504.management'), columns: ['opening_debit', 'closing_debit'],
    rows: (r.balances ?? []).map(row => ({ code: row.code, label: row.name, values: [row.opening_debit_balance, row.closing_debit_balance] })) }]
  return [
    { title: t('accounting.statement504.assets'), columns: ['start', 'end'], rows: statementRows(r.assets ?? [], ['start', 'end']) },
    { title: t('accounting.statement504.liabilities'), columns: ['start', 'end'], rows: statementRows(r.liabilities ?? [], ['start', 'end']) },
  ]
})
</script>

<template>
  <section data-test="statement-504" class="space-y-4">
    <div class="flex flex-wrap items-center gap-2 text-sm">
      <span class="rounded-md bg-primary-50 px-2 py-1 font-medium text-primary-700">{{ t('accounting.statement504.framework') }}</span>
      <span v-if="report.scope" class="rounded-md bg-neutral-100 px-2 py-1 text-neutral-600">{{ t(report.scope === 'full' ? 'accounting.statement504.full' : 'accounting.statement504.short') }}</span>
      <span>{{ formatDate(report.period.starts_on) }} - {{ formatDate(report.period.ends_on) }}</span>
      <span>{{ t('accounting.statement504.as_of', { date: formatDate(report.as_of) }) }}</span>
    </div>
    <p class="text-sm text-neutral-600">{{ report.entity.name }}<span v-if="report.entity.ico"> · {{ report.entity.ico }}</span></p>
    <p v-if="report.dimension" class="rounded-md border border-primary-200 bg-primary-50 p-3 text-sm text-primary-800">{{ t('dimensions.filter_active_note') }} {{ report.dimension.label }}</p>
    <p v-if="report.view_kind === 'management'" class="rounded-md border border-neutral-200 bg-neutral-50 p-3 text-sm">{{ t('accounting.statement504.management_hint') }}</p>
    <p v-if="report.checks.unresolved_pending_result" class="rounded-md border border-warning-500/30 bg-warning-50 p-3 text-sm text-warning-700">
      {{ t('accounting.statement504.pending_result', { amount: format(Number(report.checks.unresolved_pending_result)) }) }}
    </p>
    <p v-if="report.checks.balanced === false || report.checks.reconciliation_difference" class="rounded-md border border-danger-500/30 bg-danger-50 p-3 text-sm text-danger-600">{{ t('accounting.statement504.unbalanced') }}</p>
    <div v-for="table in tables" :key="table.title" class="overflow-hidden rounded-lg border border-neutral-200 bg-surface shadow-sm">
      <h2 class="border-b border-neutral-200 px-4 py-3 font-semibold">{{ table.title }}</h2>
      <div class="overflow-x-auto"><table class="w-full text-sm">
        <thead class="bg-neutral-50 text-xs text-neutral-500"><tr>
          <th class="px-3 py-2 text-left">{{ t('accounting.income_statement.col_code') }}</th>
          <th class="px-3 py-2 text-left">{{ t('accounting.statement504.item') }}</th>
          <th v-for="column in table.columns" :key="column" class="whitespace-nowrap px-3 py-2 text-right">{{ t('accounting.statement504.' + column) }}</th>
        </tr></thead>
        <tbody><tr v-for="(row, index) in table.rows" :key="index" class="border-t border-neutral-100">
          <td class="whitespace-nowrap px-3 py-2 font-mono text-xs">{{ row.code }}</td><td class="px-3 py-2">{{ row.label }}</td>
          <td v-for="(value, col) in row.values" :key="col" class="whitespace-nowrap px-3 py-2 text-right font-mono" :class="(value ?? 0) < 0 ? 'text-danger-600' : ''">{{ value === null ? '' : format(value) }}</td>
        </tr></tbody>
      </table></div>
    </div>
    <div v-if="report.deferred_tax_method" class="space-y-1 rounded-md border border-neutral-200 bg-neutral-50 p-3 text-sm text-neutral-700">
      <p>{{ report.deferred_tax_method.disclosure }}</p>
      <p>{{ report.deferred_tax_method.reference }}</p>
      <p>{{ report.deferred_tax_method.faithful_image_reason }}</p>
      <p>{{ report.deferred_tax_method.standard_departure_reason }}</p>
      <p>{{ report.deferred_tax_method.presentation_reason }}</p>
      <p v-if="report.deferred_tax_method.first_adoption">{{ report.deferred_tax_method.first_adoption_reason }}</p>
    </div>
  </section>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { DimensionProfitMatrix } from '@/api/dimensions'
import { formatMoney } from '@/composables/useFormat'

/**
 * Výsledovka po dimenzi v rozpadu po účtech: řádky = syntetické účty (výnosy, pak
 * náklady), sloupce = kořeny sestavy (hodnoty nejvyšší úrovně, větev nebo hodnoty
 * odpovědné osoby) a případně „bez hodnoty". Poslední řádek je výsledek sloupce.
 */
const props = defineProps<{
  matrix: DimensionProfitMatrix
  /** Daňová evidence: řádky jsou příjmy a výdaje peněžního deníku, ne účty. */
  cashJournal?: boolean
}>()

const { t } = useI18n()
const hasAmount = (value: number) => Math.round(Math.abs(value) * 100) !== 0
const visibleColumns = computed(() => props.matrix.columns.map((col, index) => ({ ...col, index }))
  .filter(col => props.matrix.rows.some(row => hasAmount(row.cells[col.index] ?? 0))))
const visibleRows = computed(() => props.matrix.rows.filter(row => visibleColumns.value.some(col => hasAmount(row.cells[col.index] ?? 0))))

const sections = computed(() => {
  const revenue = visibleRows.value.filter(r => r.account_type === 'revenue')
  const expense = visibleRows.value.filter(r => r.account_type !== 'revenue')
  return [
    { key: 'revenue', label: props.cashJournal ? t('dimensions.profit_income') : t('dimensions.profit_revenue'), rows: revenue },
    { key: 'expense', label: props.cashJournal ? t('dimensions.profit_expense') : t('dimensions.profit_cost'), rows: expense },
  ].filter(s => s.rows.length > 0)
})

function columnLabel(col: DimensionProfitMatrix['columns'][number]): string {
  return col.value_id === null ? t('dimensions.profit_unassigned') : (col.code === col.name ? col.code : `${col.code} ${col.name ?? ''}`.trim())
}

function money(v: number) {
  return formatMoney(v, 'CZK')
}
</script>

<template>
  <div v-if="visibleRows.length === 0" class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-6 text-sm text-neutral-500" data-test="profit-matrix-empty">{{ t('dimensions.profit_no_activity') }}</div>
  <div v-else class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-x-auto" data-test="profit-matrix">
    <table class="w-full text-sm">
      <thead class="bg-neutral-50 text-neutral-600">
        <tr>
          <th class="px-3 py-3 text-left font-medium sticky left-0 bg-neutral-50">{{ t('dimensions.matrix_account') }}</th>
          <th v-for="col in visibleColumns" :key="col.key" class="px-3 py-3 text-right font-medium whitespace-nowrap"
              :class="{ 'italic text-neutral-500': col.value_id === null }">{{ columnLabel(col) }}</th>
          <th class="px-3 py-3 text-right font-medium whitespace-nowrap">{{ t('dimensions.profit_total') }}</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-neutral-100">
        <template v-for="section in sections" :key="section.key">
          <tr class="bg-neutral-50/60">
            <td :colspan="visibleColumns.length + 2" class="px-3 py-1.5 text-xs font-semibold uppercase tracking-wide text-neutral-500">{{ section.label }}</td>
          </tr>
          <tr v-for="row in section.rows" :key="row.code" data-test="matrix-row">
            <td class="px-3 py-1.5 sticky left-0 bg-surface">
              <span class="font-mono text-xs text-neutral-500 mr-1">{{ row.code }}</span>
              <span v-if="row.name !== row.code">{{ row.name }}</span>
            </td>
            <td v-for="col in visibleColumns" :key="col.key" class="px-3 py-1.5 text-right tabular-nums whitespace-nowrap"
                :class="{ 'text-neutral-400': !hasAmount(row.cells[col.index] ?? 0) }">{{ hasAmount(row.cells[col.index] ?? 0) ? money(row.cells[col.index] ?? 0) : '–' }}</td>
            <td class="px-3 py-1.5 text-right tabular-nums whitespace-nowrap font-medium">{{ money(row.total) }}</td>
          </tr>
        </template>
      </tbody>
      <tfoot class="bg-neutral-50 font-semibold">
        <tr data-test="matrix-results">
          <td class="px-3 py-3 sticky left-0 bg-neutral-50">{{ t('dimensions.profit_result') }}</td>
          <td v-for="col in visibleColumns" :key="col.key" class="px-3 py-3 text-right tabular-nums whitespace-nowrap"
              :class="(matrix.results[col.index] ?? 0) < 0 ? 'text-danger-600' : (matrix.results[col.index] ?? 0) > 0 ? 'text-success-700' : ''">{{ money(matrix.results[col.index] ?? 0) }}</td>
          <td class="px-3 py-3 text-right tabular-nums whitespace-nowrap" :class="matrix.total_result < 0 ? 'text-danger-600' : matrix.total_result > 0 ? 'text-success-700' : ''">{{ money(matrix.total_result) }}</td>
        </tr>
      </tfoot>
    </table>
  </div>
</template>

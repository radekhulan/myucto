<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import type { MoneyS3Step } from '@/api/moneyS3'

const props = defineProps<{ steps: MoneyS3Step[] }>()
const { t, te } = useI18n()
const families = ['assets', 'small_assets', 'employees', 'historical_payroll', 'warehouses', 'stock_items', 'cars', 'route_templates', 'children', 'tax_items', 'leave_records', 'average_records'] as const
const columns = ['source', 'ready', 'skipped', 'review'] as const
const showAll = ref(false)
const summary = computed(() => {
  const source = props.steps.find(step => step.key === 'source_summary')?.counts ?? {}
  const used = new Set<string>()
  const rows: { key: string; cells: { key: string; value: number | undefined }[] }[] = []
  for (const family of families) {
    const cells = columns.map(column => {
      const key = `${family}_${column}`
      const value = column !== 'review' ? source[key] : undefined
      if (value !== undefined) used.add(key)
      return { key, value }
    })
    if (cells.some(cell => cell.value !== undefined)) rows.push({ key: family, cells })
  }
  for (const [key, countKey] of [['documents_review', 'requires_draft'], ['movements_source_review', 'requires_review'], ['movements_review', 'requires_movement_review']] as const) {
    if (source[countKey] === undefined) continue
    used.add(countKey)
    rows.push({ key, cells: columns.map(column => ({ key: column === 'review' ? countKey : `${key}_${column}`, value: column === 'review' ? source[countKey] : undefined })) })
  }
  const metrics = props.steps.flatMap(step => Object.entries(step.counts)
    .filter(([key]) => step.key !== 'source_summary' || !used.has(key))
    .map(([key, value]) => ({ step: step.key, key, value })))
  return {
    rows: showAll.value ? rows : rows.filter(row => row.cells.some(cell => cell.value !== undefined && cell.value !== 0)),
    metrics: showAll.value ? metrics : metrics.filter(metric => metric.value !== 0),
  }
})
function countLabel(key: string): string {
  const own = `stereo_nx.counts.${key}`
  if (te(own)) return t(own)
  const shared = `premier.counts.${key}`
  return te(shared) ? t(shared) : key
}
function statusClass(status: string): string {
  return status === 'ok' ? 'bg-success-50 text-success-600'
    : status === 'warning' ? 'bg-warning-50 text-warning-700'
      : status === 'error' ? 'bg-danger-50 text-danger-600' : 'bg-neutral-100 text-neutral-600'
}
</script>

<template>
  <section data-testid="stereo-counts-summary" class="space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <h4 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('stereo_nx.protocol.steps_title') }}</h4>
      <label class="flex cursor-pointer items-center gap-2 text-sm"><input v-model="showAll" data-testid="stereo-counts-show-all" type="checkbox" />{{ t('stereo_nx.summary.show_all') }}</label>
    </div>
    <div class="flex flex-wrap gap-3 text-sm">
      <div v-for="step in steps" :key="step.key" class="flex flex-wrap items-center gap-2">
        <span class="font-medium">{{ t(`stereo_nx.steps.${step.key}`) }}</span>
        <span class="rounded-full px-2 py-0.5 text-xs font-medium whitespace-nowrap" :class="statusClass(step.status)">{{ t(`stereo_nx.step_status.${step.status}`) }}</span>
      </div>
    </div>
    <div v-if="summary.rows.length" class="overflow-x-auto rounded-lg border border-neutral-200">
      <table class="min-w-full text-sm">
        <caption class="px-3 py-2 text-left font-medium">{{ t('stereo_nx.summary.agendas_title') }}</caption>
        <thead class="bg-neutral-50 text-left text-xs text-neutral-500">
          <tr><th scope="col" class="px-3 py-2">{{ t('stereo_nx.summary.agenda') }}</th><th v-for="column in columns" :key="column" scope="col" class="px-3 py-2 text-right whitespace-nowrap">{{ t(`stereo_nx.summary.${column}`) }}</th></tr>
        </thead>
        <tbody class="divide-y divide-neutral-100">
          <tr v-for="row in summary.rows" :key="row.key" :data-agenda="row.key">
            <th scope="row" class="px-3 py-2 text-left font-medium">{{ t(`stereo_nx.summary.agendas.${row.key}`) }}</th>
            <td v-for="cell in row.cells" :key="cell.key" :data-count-key="cell.value === undefined ? undefined : cell.key" class="px-3 py-2 text-right tabular-nums whitespace-nowrap" :class="cell.value === undefined ? 'text-neutral-400' : (cell.key.endsWith('_skipped') || row.key.endsWith('_review')) && cell.value > 0 ? 'bg-warning-50 font-semibold text-warning-700' : ''">{{ cell.value ?? '—' }}</td>
          </tr>
        </tbody>
      </table>
    </div>
    <p v-if="summary.rows.length" class="text-xs text-neutral-500">{{ t('stereo_nx.summary.hint') }}</p>
    <div v-if="summary.metrics.length" data-testid="stereo-counts-details" class="overflow-x-auto rounded-lg border border-neutral-200">
      <table class="min-w-full text-sm">
        <caption class="px-3 py-2 text-left font-medium">{{ t('stereo_nx.summary.details_title') }}</caption>
        <thead class="bg-neutral-50 text-left text-xs text-neutral-500">
          <tr><th scope="col" class="px-3 py-2">{{ t('stereo_nx.summary.stage') }}</th><th scope="col" class="px-3 py-2">{{ t('stereo_nx.summary.metric') }}</th><th scope="col" class="px-3 py-2 text-right">{{ t('stereo_nx.summary.count') }}</th></tr>
        </thead>
        <tbody class="divide-y divide-neutral-100">
          <tr v-for="metric in summary.metrics" :key="`${metric.step}:${metric.key}`" :data-count-key="metric.key">
            <td class="px-3 py-2 whitespace-nowrap text-neutral-500">{{ t(`stereo_nx.steps.${metric.step}`) }}</td>
            <td class="px-3 py-2">{{ countLabel(metric.key) }}</td>
            <td class="px-3 py-2 text-right tabular-nums whitespace-nowrap">{{ metric.value }}</td>
          </tr>
        </tbody>
      </table>
    </div>
  </section>
</template>

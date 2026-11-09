<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { reportsApi, type InvoiceSeriesCompletenessResult, type InvoiceSeriesGroup } from '@/api/reports'
import { apiErrorMessage } from '@/api/errors'
import { useYearOptions } from '@/composables/useYearOptions'
import EmptyState from '@/components/ui/EmptyState.vue'

const { t } = useI18n()

const now = new Date()
const year = ref(now.getFullYear())

const result = ref<InvoiceSeriesCompletenessResult | null>(null)
const loading = ref(false)
const error = ref('')

const yearOptions = useYearOptions('invoices', year)

async function load() {
  loading.value = true
  error.value = ''
  try {
    result.value = await reportsApi.invoiceSeriesCompleteness(year.value)
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    loading.value = false
  }
}

function typeLabel(type: string): string {
  if (type === 'simplified') return t('reports.series_completeness.type_simplified')
  return type === 'credit_note' ? t('reports.series_completeness.type_credit_note') : t('reports.series_completeness.type_invoice')
}

function groupLabel(g: InvoiceSeriesGroup): string {
  const types = g.types.map(typeLabel).join(' + ')
  if (g.inferred) {
    return t('reports.series_completeness.series_inferred', {
      pattern: Object.values(g.template_by_type)[0] || '', types,
    })
  }
  let scope: string
  if (g.series_name) {
    scope = t('reports.series_completeness.series_codebook', { name: g.series_name })
  } else if (g.client_id !== 0) {
    scope = t('reports.series_completeness.series_client', { name: g.client_name || `#${g.client_id}` })
  } else if (g.revenue_category_id !== 0) {
    scope = t('reports.series_completeness.series_revenue_category', {
      name: g.revenue_category_name || `#${g.revenue_category_id}`,
    })
  } else {
    scope = t('reports.series_completeness.series_supplier')
  }
  return `${scope} - ${types}`
}

function periodLabel(g: InvoiceSeriesGroup, periodKey: string): string {
  if (g.period === 'month') return `${periodKey.slice(4, 6)}/${periodKey.slice(0, 4)}`
  if (g.period === 'year') return periodKey
  return t('reports.series_completeness.period_none')
}

const totalMissing = computed(() => result.value?.total_missing ?? 0)

watch(year, load)
onMounted(load)
</script>

<template>
  <div class="max-w-full">
    <div class="flex items-center justify-between mb-4 gap-3 flex-wrap">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('reports.series_completeness.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5">{{ t('reports.series_completeness.subtitle') }}</p>
      </div>
      <div class="flex items-center gap-2 flex-wrap">
        <label class="text-sm text-neutral-600 flex items-center gap-1">
          {{ t('reports.series_completeness.year_label') }}
          <select v-model.number="year" class="h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
            <option v-for="y in yearOptions" :key="y" :value="y">{{ y }}</option>
          </select>
        </label>
      </div>
    </div>

    <div v-if="loading" class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-8 text-center text-neutral-400">
      {{ t('common.loading') }}…
    </div>
    <div v-else-if="error" class="bg-danger-50 border border-danger-500/40 text-danger-500 rounded-md p-3 text-sm">
      {{ error }}
    </div>

    <div v-else-if="result" class="space-y-4">
      <div class="flex gap-3 flex-wrap text-sm">
        <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm px-4 py-3">
          <div class="text-xs uppercase tracking-wide text-neutral-500 font-medium">
            {{ t('reports.series_completeness.gaps_found', { count: totalMissing }) }}
          </div>
          <div class="text-lg font-semibold" :class="totalMissing > 0 ? 'text-danger-500' : 'text-emerald-600'">
            {{ totalMissing }}
          </div>
        </div>
      </div>

      <EmptyState v-if="result.series.length === 0" boxed accent="neutral" icon="checkCircle"
        :title="t('reports.series_completeness.no_series')" />

      <div v-for="(group, gi) in result.series" :key="gi" class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
        <div class="px-4 py-3 border-b border-neutral-200 bg-neutral-50">
          <div class="font-medium text-neutral-800">{{ groupLabel(group) }}</div>
          <div v-if="group.inferred" class="text-xs text-neutral-500 mt-0.5">
            {{ t('reports.series_completeness.inferred_note') }}
          </div>
          <div v-if="group.types.length > 1" class="text-xs text-neutral-400 mt-0.5">
            {{ t('reports.series_completeness.shared_note') }}
          </div>
        </div>

        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead>
              <tr class="border-b border-neutral-200 text-left text-xs uppercase tracking-wide text-neutral-500">
                <th class="px-4 py-2 font-medium">{{ t('reports.series_completeness.col_period') }}</th>
                <th class="px-4 py-2 font-medium">{{ t('reports.series_completeness.col_range') }}</th>
                <th class="px-4 py-2 font-medium text-right">{{ t('reports.series_completeness.col_used') }}</th>
                <th class="px-4 py-2 font-medium">{{ t('reports.series_completeness.col_missing') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="bucket in group.buckets" :key="bucket.period_key" class="border-b border-neutral-100 last:border-0">
                <td class="px-4 py-2 whitespace-nowrap font-mono">{{ periodLabel(group, bucket.period_key) }}</td>
                <td class="px-4 py-2 whitespace-nowrap font-mono text-neutral-500">{{ bucket.range_from }}–{{ bucket.range_to }}</td>
                <td class="px-4 py-2 text-right font-mono">{{ bucket.used_count }}</td>
                <td class="px-4 py-2">
                  <span v-if="bucket.missing.length === 0" class="text-emerald-600 text-xs">
                    {{ t('reports.series_completeness.no_gaps') }}
                  </span>
                  <span v-else-if="bucket.missing_truncated" class="text-danger-500 font-mono text-xs">
                    {{ bucket.missing_preview.join(', ') }}
                    <span class="block mt-1 font-sans text-neutral-500 not-italic">
                      {{ t('reports.series_completeness.missing_truncated', {
                        shown: bucket.missing.length, total: bucket.missing_total,
                      }) }}
                    </span>
                  </span>
                  <span v-else class="text-danger-500 font-mono text-xs">
                    {{ bucket.missing_preview.join(', ') }}
                  </span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</template>

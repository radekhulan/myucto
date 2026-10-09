<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import { taxEvidenceApi, type TaxEstimate, type ExpenseMode, type TaxEstimateVariant } from '@/api/taxEvidence'
import { formatMoney, formatDate } from '@/composables/useFormat'
import { useYearOptions } from '@/composables/useYearOptions'
import { useAuthStore } from '@/stores/auth'
import { ICONS, btnOutline } from '@/components/ui/buttonStyles'
import EmptyState from '@/components/ui/EmptyState.vue'

const { t } = useI18n()
const auth = useAuthStore()

const year = ref<number>(new Date().getFullYear())
const yearOptions = useYearOptions('combined', year)
const estimate = ref<TaxEstimate | null>(null)
const loading = ref(false)
const failed = ref('')

let requestSeq = 0
async function load() {
  const seq = ++requestSeq
  loading.value = true
  failed.value = ''
  try {
    const data = await taxEvidenceApi.taxEstimate(year.value)
    if (seq === requestSeq) estimate.value = data
  } catch (e: any) {
    if (seq === requestSeq) {
      estimate.value = null
      failed.value = e?.response?.data?.error?.message || t('tax_evidence.tax_estimate.error')
    }
  } finally {
    if (seq === requestSeq) loading.value = false
  }
}
watch(year, () => { void load() }, { immediate: true })

const MODES: ExpenseMode[] = ['actual', 'pausal']
const variants = computed(() => MODES
  .map(mode => ({ mode, v: estimate.value?.variants?.[mode] }))
  .filter((x): x is { mode: ExpenseMode; v: TaxEstimateVariant } => x.v !== undefined))
const current = computed(() => {
  const mode = estimate.value?.selected_mode
  return mode ? estimate.value?.variants?.[mode] ?? null : null
})

function modeLabel(mode: ExpenseMode): string {
  return mode === 'actual'
    ? t('tax_evidence.tax_estimate.mode_actual')
    : t('tax_evidence.tax_estimate.mode_pausal', { rate: estimate.value?.pausal?.rate ?? 0 })
}

type Row = { key: string; label: string; value: (v: TaxEstimateVariant) => number; strong?: boolean; sub?: boolean }
const ROWS = computed<Row[]>(() => [
  { key: 'income', label: t('tax_evidence.tax_estimate.row_income'), value: v => v.income },
  { key: 'expenses', label: t('tax_evidence.tax_estimate.row_expenses'), value: v => v.expenses },
  { key: 's7_base', label: t('tax_evidence.tax_estimate.row_s7_base'), value: v => v.s7_base, strong: true },
  { key: 'tax_base', label: t('tax_evidence.tax_estimate.row_tax_base'), value: v => v.tax_base, sub: true },
  { key: 'tax_before', label: t('tax_evidence.tax_estimate.row_tax_before'), value: v => v.tax_before_credits, sub: true },
  { key: 'credits', label: t('tax_evidence.tax_estimate.row_credits'), value: v => -v.credits, sub: true },
  { key: 'child', label: t('tax_evidence.tax_estimate.row_child'), value: v => -v.child_benefit, sub: true },
  { key: 'tax', label: t('tax_evidence.tax_estimate.row_tax'), value: v => v.tax },
  { key: 'social', label: t('tax_evidence.tax_estimate.row_social'), value: v => v.social.insurance },
  { key: 'health', label: t('tax_evidence.tax_estimate.row_health'), value: v => v.health.insurance },
  { key: 'total', label: t('tax_evidence.tax_estimate.row_total'), value: v => v.total_burden, strong: true },
])

const sourceRows = computed(() => {
  const s = estimate.value?.sources
  if (!s) return []
  return [
    { key: 'income', label: t('tax_evidence.tax_estimate.src_income'), value: s.income },
    { key: 'cash', label: t('tax_evidence.tax_estimate.src_cash_expenses'), value: s.cash_journal_expenses },
    { key: 'confirmed', label: t('tax_evidence.tax_estimate.src_confirmed_dep'), value: s.confirmed_depreciation },
    { key: 'pending', label: t('tax_evidence.tax_estimate.src_pending_dep'), value: s.pending_depreciation },
    { key: 'residuals', label: t('tax_evidence.tax_estimate.src_residuals'), value: s.disposal_residuals },
    { key: 'increase', label: t('tax_evidence.tax_estimate.src_increase'), value: s.increase },
    { key: 'decrease', label: t('tax_evidence.tax_estimate.src_decrease'), value: s.decrease },
  ].filter(r => r.value !== null && (r.key === 'income' || r.key === 'cash' || r.value !== 0))
})

const KINDS = ['tax', 'social', 'health'] as const
function kindTotal(kind: typeof KINDS[number]): number {
  const v = current.value
  if (!v) return 0
  return kind === 'tax' ? v.tax : kind === 'social' ? v.social.insurance : v.health.insurance
}

const reasonText = computed(() => {
  const r = estimate.value?.reason
  return r ? t(`tax_evidence.tax_estimate.reason_${r}`, { year: year.value }) : ''
})

const returnLink = computed(() => ({ name: 'reports-income-tax', query: { year: String(year.value), tab: 'nahled' } }))
</script>

<template>
  <div>
    <div class="flex flex-wrap items-center justify-between gap-2 mb-4">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('tax_evidence.tax_estimate.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5">{{ t('tax_evidence.tax_estimate.subtitle') }}</p>
      </div>
      <div class="flex flex-wrap items-center gap-2">
        <label class="sr-only" for="tax-estimate-year">{{ t('tax_evidence.tax_estimate.year') }}</label>
        <select id="tax-estimate-year" v-model.number="year" data-test="estimate-year"
          class="h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface">
          <option v-for="y in yearOptions" :key="y" :value="y">{{ y }}</option>
        </select>
        <RouterLink v-if="auth.canRead('reports')" :to="returnLink" :class="btnOutline('primary')" class="whitespace-nowrap">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.doc" /></svg>
          {{ t('tax_evidence.tax_estimate.open_return') }}
        </RouterLink>
      </div>
    </div>

    <div v-if="loading" class="text-center text-neutral-500 py-12 text-sm">{{ t('common.loading') }}</div>
    <div v-else-if="failed" class="bg-danger-50 border border-danger-100 rounded-lg p-3 text-sm text-danger-600" data-test="estimate-error">{{ failed }}</div>
    <EmptyState v-else-if="estimate && !estimate.applicable" icon="coin" :title="reasonText" boxed data-test="estimate-unavailable" />

    <template v-else-if="estimate && current">
      <p class="mb-3 px-3 py-2 rounded-md bg-warning-50 border border-warning-500/30 text-warning-700 text-xs" data-test="estimate-note">
        {{ t('tax_evidence.tax_estimate.note', { date: formatDate(estimate.as_of ?? '') }) }}
        <template v-if="estimate.year_closed"> {{ t('tax_evidence.tax_estimate.year_closed') }}</template>
      </p>

      <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-4">
        <!-- Podklady § 7 -->
        <section class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden" data-test="estimate-sources">
          <h2 class="px-3 py-2 text-sm font-semibold text-neutral-700 bg-neutral-50 border-b border-neutral-200">{{ t('tax_evidence.tax_estimate.sources_title') }}</h2>
          <table class="w-full text-sm">
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="r in sourceRows" :key="r.key" :data-test="`source-${r.key}`">
                <td class="px-3 py-1.5">{{ r.label }}</td>
                <td class="px-3 py-1.5 text-right font-mono whitespace-nowrap">{{ formatMoney(r.value ?? 0) }}</td>
              </tr>
            </tbody>
          </table>
        </section>

        <!-- Srovnání skutečných výdajů a paušálu -->
        <section class="lg:col-span-2 bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden" data-test="estimate-compare">
          <h2 class="px-3 py-2 text-sm font-semibold text-neutral-700 bg-neutral-50 border-b border-neutral-200">{{ t('tax_evidence.tax_estimate.compare_title') }}</h2>
          <div class="overflow-x-auto">
            <table class="w-full text-sm">
              <thead class="text-xs text-neutral-500 uppercase tracking-wide">
                <tr>
                  <th class="px-3 py-2 text-left font-medium">{{ t('tax_evidence.tax_estimate.col_item') }}</th>
                  <th v-for="x in variants" :key="x.mode" class="px-3 py-2 text-right font-medium whitespace-nowrap" :data-test="`col-${x.mode}`">
                    {{ modeLabel(x.mode) }}
                    <div class="flex flex-wrap justify-end gap-1 mt-0.5 normal-case tracking-normal">
                      <span v-if="estimate.selected_mode === x.mode" class="text-[10px] font-semibold px-1.5 py-0.5 rounded bg-primary-100 text-primary-700">{{ t('tax_evidence.tax_estimate.selected') }}</span>
                      <span v-if="estimate.recommended_mode === x.mode" class="text-[10px] font-semibold px-1.5 py-0.5 rounded bg-success-100 text-success-700" :data-test="`recommended-${x.mode}`">{{ t('tax_evidence.tax_estimate.recommended') }}</span>
                    </div>
                  </th>
                </tr>
              </thead>
              <tbody class="divide-y divide-neutral-100">
                <tr v-for="row in ROWS" :key="row.key" :class="row.strong ? 'font-semibold' : ''" :data-test="`row-${row.key}`">
                  <td class="px-3 py-1.5" :class="row.sub ? 'pl-6 text-neutral-500' : ''">{{ row.label }}</td>
                  <td v-for="x in variants" :key="x.mode" class="px-3 py-1.5 text-right font-mono whitespace-nowrap"
                    :class="row.sub ? 'text-neutral-500' : ''">{{ formatMoney(row.value(x.v)) }}</td>
                </tr>
              </tbody>
            </table>
          </div>
          <p v-if="estimate.pausal?.cap_reached" class="px-3 py-2 text-xs text-warning-700 border-t border-neutral-100" data-test="estimate-cap">
            {{ t('tax_evidence.tax_estimate.cap_reached', { cap: formatMoney(estimate.pausal.cap) }) }}
          </p>
          <p v-if="estimate.has_activities" class="px-3 py-2 text-xs text-neutral-500 border-t border-neutral-100">{{ t('tax_evidence.tax_estimate.activities_note') }}</p>
          <p v-else-if="estimate.recommended_mode && estimate.recommended_mode !== estimate.selected_mode"
            class="px-3 py-2 text-xs text-neutral-600 border-t border-neutral-100" data-test="estimate-recommend-hint">
            {{ t('tax_evidence.tax_estimate.recommend_hint', { mode: modeLabel(estimate.recommended_mode) }) }}
          </p>
        </section>
      </div>

      <!-- Zálohy a vypořádání -->
      <section class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden mb-4" data-test="estimate-advances">
        <h2 class="px-3 py-2 text-sm font-semibold text-neutral-700 bg-neutral-50 border-b border-neutral-200">
          {{ t('tax_evidence.tax_estimate.advances_title', { mode: modeLabel(estimate.selected_mode!) }) }}
        </h2>
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead class="text-xs text-neutral-500 uppercase tracking-wide">
              <tr>
                <th class="px-3 py-2 text-left font-medium">{{ t('tax_evidence.tax_estimate.col_kind') }}</th>
                <th class="px-3 py-2 text-right font-medium">{{ t('tax_evidence.tax_estimate.col_due') }}</th>
                <th class="px-3 py-2 text-right font-medium">{{ t('tax_evidence.tax_estimate.col_paid') }}</th>
                <th class="px-3 py-2 text-right font-medium">{{ t('tax_evidence.tax_estimate.col_remaining') }}</th>
                <th class="px-3 py-2 text-right font-medium">{{ t('tax_evidence.tax_estimate.col_settlement') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="kind in KINDS" :key="kind" :data-test="`advance-${kind}`">
                <td class="px-3 py-1.5">
                  {{ t(`tax_evidence.tax_estimate.kind_${kind}`) }}
                  <span v-if="estimate.advances?.[kind].source !== 'none'" class="text-xs text-neutral-500">
                    ({{ t(`tax_evidence.tax_estimate.source_${estimate.advances?.[kind].source}`) }})
                  </span>
                </td>
                <td class="px-3 py-1.5 text-right font-mono whitespace-nowrap">{{ formatMoney(kindTotal(kind)) }}</td>
                <td class="px-3 py-1.5 text-right font-mono whitespace-nowrap">{{ formatMoney(kind === 'tax' ? current.tax_advances : estimate.advances?.[kind].paid ?? 0) }}</td>
                <td class="px-3 py-1.5 text-right font-mono whitespace-nowrap">{{ formatMoney(estimate.advances?.[kind].remaining ?? 0) }}</td>
                <td class="px-3 py-1.5 text-right font-mono whitespace-nowrap font-semibold"
                  :class="(estimate.settlement?.[kind] ?? 0) > 0 ? 'text-danger-600' : 'text-success-700'">
                  {{ formatMoney(estimate.settlement?.[kind] ?? 0) }}
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <p class="px-3 py-2 text-xs text-neutral-500 border-t border-neutral-100">{{ t('tax_evidence.tax_estimate.settlement_note') }}</p>
      </section>

      <section v-if="(estimate.warnings ?? []).length" class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden" data-test="estimate-warnings">
        <h2 class="px-3 py-2 text-sm font-semibold text-neutral-700 bg-neutral-50 border-b border-neutral-200">{{ t('tax_evidence.tax_estimate.warnings_title') }}</h2>
        <ul class="px-5 py-2 text-xs text-neutral-600 list-disc space-y-1">
          <li v-for="(w, i) in estimate.warnings" :key="i">{{ w }}</li>
        </ul>
      </section>
    </template>
  </div>
</template>

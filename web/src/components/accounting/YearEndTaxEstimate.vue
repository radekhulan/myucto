<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink, type RouteLocationRaw } from 'vue-router'
import { accountingApi, type YearEndTaxEstimate, type YearEndClosingItem } from '@/api/accounting'
import { closingProjectionLabelKey, closingProjectionSource, closingProjectionWarningLabelKey, type ClosingProjectionWarning } from '@/utils/closingProjection'

const props = defineProps<{
  periodId: number
  format: (value: number | null | undefined) => string
}>()

const { t } = useI18n()

const estimate = ref<YearEndTaxEstimate | null>(null)
const loading = ref(false)
const failed = ref(false)

let requestSeq = 0
async function load() {
  const seq = ++requestSeq
  loading.value = true
  failed.value = false
  estimate.value = null
  try {
    const data = await accountingApi.getYearEndTaxEstimate(props.periodId)
    if (seq === requestSeq) estimate.value = data
  } catch (e: any) {
    // Bez práva na přiznání (403) se blok prostě neukáže.
    if (seq === requestSeq) failed.value = e?.response?.status !== 403
  } finally {
    if (seq === requestSeq) loading.value = false
  }
}

watch(() => props.periodId, () => { void load() }, { immediate: true })

const fiscalYear = computed(() => String(estimate.value?.period.fiscal_year ?? ''))

function dppoLink(tab: 'podklady' | 'upravy' | 'nahled' | 'zalohy'): RouteLocationRaw {
  return { name: 'reports-income-tax', query: { year: fiscalYear.value, tab } }
}
function closingLabel(item: YearEndClosingItem): string {
  return t(closingProjectionLabelKey(item))
}

function warningLabel(w: ClosingProjectionWarning): string {
  const key = closingProjectionWarningLabelKey(w.source)
  return key ? t(key) : w.source
}

function signed(value: number, sign: number): string {
  return `${sign >= 0 ? '+' : '−'} ${props.format(Math.abs(value))}`
}
</script>

<template>
  <div v-if="loading" class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-3 mb-4 text-sm text-neutral-500" data-test="estimate-loading">
    {{ t('accounting.statement_accounts.estimate.loading') }}
  </div>
  <div v-else-if="failed" class="bg-warning-50 border border-warning-100 rounded-lg p-3 mb-4 text-sm text-warning-700" data-test="estimate-error">
    {{ t('accounting.statement_accounts.estimate.error') }}
  </div>
  <section v-else-if="estimate && (estimate.applicable || estimate.reason === 'tax_unavailable')"
    class="bg-surface border border-dashed border-warning-500 rounded-lg shadow-sm overflow-hidden mb-4" data-test="year-end-estimate">
    <div class="flex flex-wrap items-center gap-2 px-3 py-2 bg-warning-50 border-b border-warning-100">
      <h2 class="text-sm font-semibold text-warning-700">{{ t('accounting.statement_accounts.estimate.title') }}</h2>
      <span class="text-[11px] uppercase tracking-wide font-semibold px-2 py-0.5 rounded bg-warning-100 text-warning-700 whitespace-nowrap">
        {{ t('accounting.statement_accounts.estimate.badge') }}
      </span>
    </div>
    <p class="px-3 pt-2 text-xs text-neutral-500">{{ t('accounting.statement_accounts.estimate.note') }}</p>

    <p v-if="!estimate.applicable" class="px-3 py-3 text-sm text-warning-700" data-test="estimate-unavailable">
      {{ t('accounting.statement_accounts.estimate.unavailable', { message: estimate.message ?? '' }) }}
    </p>

    <div v-if="estimate.applicable && (estimate.projection_warnings ?? []).length"
      class="mx-3 mt-2 bg-warning-50 border border-warning-500/40 rounded-md p-2.5 text-xs text-warning-700" data-test="estimate-projection-warnings">
      <strong>{{ t('taxReturn.projection_incomplete') }}</strong>
      <ul class="mt-1 list-disc list-inside">
        <li v-for="w in estimate.projection_warnings" :key="w.source">{{ warningLabel(w) }}: {{ w.reason }}</li>
      </ul>
    </div>

    <div v-if="estimate.applicable" class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead class="text-xs text-neutral-500 uppercase tracking-wide">
          <tr>
            <th class="px-3 py-2 text-left font-medium">{{ t('accounting.statement_accounts.estimate.col_item') }}</th>
            <th class="px-3 py-2 text-right font-medium w-40">{{ t('accounting.statement_accounts.estimate.col_amount') }}</th>
            <th class="px-3 py-2 text-left font-medium w-44">{{ t('accounting.statement_accounts.estimate.col_source') }}</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-neutral-100">
          <tr data-test="estimate-vh-posted">
            <td class="px-3 py-1.5">{{ t('accounting.statement_accounts.estimate.vh_posted') }}</td>
            <td class="px-3 py-1.5 text-right font-mono whitespace-nowrap">{{ format(estimate.vh_posted) }}</td>
            <td class="px-3 py-1.5 text-xs"><RouterLink :to="dppoLink('podklady')" class="text-primary-600 hover:underline">{{ t('accounting.statement_accounts.estimate.src_dppo') }}</RouterLink></td>
          </tr>

          <tr v-for="item in estimate.closing_items ?? []" :key="item.key"
            :class="item.optional ? 'text-neutral-400' : ''" :data-test="`estimate-closing-${item.key}`">
            <td class="px-3 py-1.5 pl-6">
              {{ closingLabel(item) }}
              <span v-if="item.optional" class="text-[10px] uppercase ml-1">· {{ t('accounting.statement_accounts.estimate.optional_hint') }}</span>
            </td>
            <td class="px-3 py-1.5 text-right font-mono whitespace-nowrap">{{ signed(item.amount, item.sign) }}</td>
            <td class="px-3 py-1.5 text-xs">
              <RouterLink v-if="closingProjectionSource(item.key, periodId)" :to="closingProjectionSource(item.key, periodId)!.to" class="text-primary-600 hover:underline">
                {{ t(closingProjectionSource(item.key, periodId)!.labelKey) }}
              </RouterLink>
            </td>
          </tr>
          <tr v-if="!(estimate.closing_items ?? []).length">
            <td colspan="3" class="px-3 py-1.5 pl-6 text-xs text-neutral-500">{{ t('accounting.statement_accounts.estimate.closing_none') }}</td>
          </tr>


          <tr class="font-semibold bg-warning-50/40" data-test="estimate-vh-before-tax">
            <td class="px-3 py-1.5">{{ t('accounting.statement_accounts.estimate.vh_before_tax') }}</td>
            <td class="px-3 py-1.5 text-right font-mono whitespace-nowrap">{{ format(estimate.vh_before_tax) }}</td>
            <td class="px-3 py-1.5 text-xs font-normal"><RouterLink :to="dppoLink('nahled')" class="text-primary-600 hover:underline">{{ t('accounting.statement_accounts.estimate.src_dppo') }}</RouterLink></td>
          </tr>
          <tr>
            <td class="px-3 py-1.5 pl-6">{{ t('accounting.statement_accounts.estimate.increases') }}</td>
            <td class="px-3 py-1.5 text-right font-mono whitespace-nowrap">{{ signed(estimate.increases ?? 0, 1) }}</td>
            <td class="px-3 py-1.5 text-xs"><RouterLink :to="dppoLink('upravy')" class="text-primary-600 hover:underline">{{ t('accounting.statement_accounts.estimate.src_dppo_adjustments') }}</RouterLink></td>
          </tr>
          <tr>
            <td class="px-3 py-1.5 pl-6">{{ t('accounting.statement_accounts.estimate.decreases') }}</td>
            <td class="px-3 py-1.5 text-right font-mono whitespace-nowrap">{{ signed(estimate.decreases ?? 0, -1) }}</td>
            <td class="px-3 py-1.5 text-xs"><RouterLink :to="dppoLink('upravy')" class="text-primary-600 hover:underline">{{ t('accounting.statement_accounts.estimate.src_dppo_adjustments') }}</RouterLink></td>
          </tr>
          <tr>
            <td class="px-3 py-1.5 pl-6">{{ t('accounting.statement_accounts.estimate.tax_base') }}</td>
            <td class="px-3 py-1.5 text-right font-mono whitespace-nowrap">{{ format(estimate.tax_base) }}</td>
            <td class="px-3 py-1.5 text-xs"><RouterLink :to="dppoLink('nahled')" class="text-primary-600 hover:underline">{{ t('accounting.statement_accounts.estimate.src_dppo') }}</RouterLink></td>
          </tr>
          <tr data-test="estimate-tax">
            <td class="px-3 py-1.5">{{ t('accounting.statement_accounts.estimate.tax') }}</td>
            <td class="px-3 py-1.5 text-right font-mono whitespace-nowrap">{{ signed(estimate.tax ?? 0, -1) }}</td>
            <td class="px-3 py-1.5 text-xs"><RouterLink :to="dppoLink('nahled')" class="text-primary-600 hover:underline">{{ t('accounting.statement_accounts.estimate.src_dppo') }}</RouterLink></td>
          </tr>
          <tr>
            <td class="px-3 py-1.5 pl-6">
              {{ t('accounting.statement_accounts.estimate.advances') }}
              <span v-if="estimate.advances_source === 'schedules'" class="text-xs text-neutral-500">({{ t('accounting.statement_accounts.estimate.advances_from_schedules') }})</span>
            </td>
            <td class="px-3 py-1.5 text-right font-mono whitespace-nowrap">{{ format(estimate.advances_paid) }}</td>
            <td class="px-3 py-1.5 text-xs"><RouterLink :to="dppoLink('zalohy')" class="text-primary-600 hover:underline">{{ t('accounting.statement_accounts.estimate.src_advances') }}</RouterLink></td>
          </tr>
          <tr data-test="estimate-balance-due">
            <td class="px-3 py-1.5 pl-6">
              {{ (estimate.balance_due ?? 0) >= 0 ? t('accounting.statement_accounts.estimate.balance_due') : t('accounting.statement_accounts.estimate.overpayment') }}
            </td>
            <td class="px-3 py-1.5 text-right font-mono whitespace-nowrap"
              :class="(estimate.balance_due ?? 0) >= 0 ? 'text-danger-600' : 'text-success-700'">{{ format(Math.abs(estimate.balance_due ?? 0)) }}</td>
            <td class="px-3 py-1.5 text-xs"><RouterLink :to="dppoLink('nahled')" class="text-primary-600 hover:underline">{{ t('accounting.statement_accounts.estimate.src_dppo') }}</RouterLink></td>
          </tr>
        </tbody>
        <tfoot>
          <tr data-test="estimate-vh-after-tax"
            :class="(estimate.vh_after_tax ?? 0) >= 0 ? 'bg-success-50 text-success-700' : 'bg-danger-50 text-danger-600'"
            class="font-bold border-t-2 border-neutral-300">
            <td class="px-3 py-2">{{ t('accounting.statement_accounts.estimate.vh_after_tax') }}</td>
            <td class="px-3 py-2 text-right font-mono whitespace-nowrap">{{ format(estimate.vh_after_tax) }}</td>
            <td class="px-3 py-2 text-xs font-normal">{{ t('accounting.statement_accounts.estimate.vh_after_tax_source') }}</td>
          </tr>
        </tfoot>
      </table>
    </div>
  </section>
</template>

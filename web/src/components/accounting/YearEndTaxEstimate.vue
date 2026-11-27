<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink, type RouteLocationRaw } from 'vue-router'
import { accountingApi, type YearEndTaxEstimate, type YearEndClosingItem, type YearEndBalanceEffect } from '@/api/accounting'
import { closingProjectionLabelKey, closingProjectionSource, closingProjectionWarningLabelKey, type ClosingProjectionWarning } from '@/utils/closingProjection'
import { formatDate } from '@/composables/useFormat'
import { useDisplayDefault } from '@/composables/useDisplayPrefs'

const props = withDefaults(defineProps<{
  periodId: number
  /** Den výkazu (výsledovka) nebo rozvahový den (rozvaha): od něj vede můstek k VH celého období. */
  asOf?: string
  /** Rozvaha navíc ukazuje odhadované změny rozvahových účtů a daň k doplacení na 341. */
  part?: 'profit_loss' | 'balance'
  format: (value: number | null | undefined) => string
}>(), { asOf: '', part: 'profit_loss' })

const { t } = useI18n()

const estimate = ref<YearEndTaxEstimate | null>(null)
const loading = ref(false)
const failed = ref(false)
// Rozpad rozbalený napoprvé podle Předvoleb zobrazení; ruční přepnutí platí jen tady.
const expanded = useDisplayDefault('estimate_open')

let requestSeq = 0
async function load() {
  const seq = ++requestSeq
  loading.value = true
  failed.value = false
  estimate.value = null
  try {
    const data = await accountingApi.getYearEndTaxEstimate(props.periodId, props.asOf || undefined)
    if (seq === requestSeq) estimate.value = data
  } catch (e: any) {
    // Bez práva na přiznání (403) se blok prostě neukáže.
    if (seq === requestSeq) failed.value = e?.response?.status !== 403
  } finally {
    if (seq === requestSeq) loading.value = false
  }
}

watch(() => [props.periodId, props.asOf], () => { void load() }, { immediate: true })

const fiscalYear = computed(() => String(estimate.value?.period.fiscal_year ?? ''))
const endsOn = computed(() => formatDate(estimate.value?.period.ends_on ?? ''))
const hasTax = computed(() => estimate.value?.tax !== undefined && estimate.value?.tax !== null)
const bridge = computed(() => estimate.value?.bridge ?? null)

function dppoLink(tab: 'podklady' | 'upravy' | 'nahled' | 'zalohy'): RouteLocationRaw {
  return { name: 'reports-income-tax', query: { year: fiscalYear.value, tab } }
}

function nextDay(date: string): string {
  const d = new Date(`${date}T00:00:00Z`)
  d.setUTCDate(d.getUTCDate() + 1)
  return d.toISOString().slice(0, 10)
}

/** Deník se zaúčtovanými zápisy datovanými po dni výkazu do konce období. */
const laterJournalLink = computed<RouteLocationRaw | null>(() => bridge.value
  ? { name: 'accounting-journal', query: { date_from: nextDay(bridge.value.as_of), date_to: bridge.value.ends_on, posted: 'posted' } }
  : null)

function closingLabel(item: YearEndClosingItem | YearEndBalanceEffect): string {
  if (item.key === 'income_tax') return t('accounting.statement_accounts.estimate.balance_income_tax')
  return t(closingProjectionLabelKey(item))
}

function warningLabel(w: ClosingProjectionWarning): string {
  const key = closingProjectionWarningLabelKey(w.source)
  return key ? t(key) : w.source
}

function signed(value: number, sign: number): string {
  return `${sign >= 0 ? '+' : '−'} ${props.format(Math.abs(value))}`
}

function signedValue(value: number): string {
  return signed(value, value)
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

    <template v-if="estimate.applicable">
      <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 px-3 pt-3" data-test="estimate-main">
        <div class="rounded-md border border-warning-100 bg-warning-50/40 p-3" data-test="estimate-main-before-tax">
          <div class="text-xs text-neutral-500">{{ t('accounting.statement_accounts.estimate.vh_before_tax') }}</div>
          <div class="text-xl font-semibold font-mono whitespace-nowrap" :class="(estimate.vh_before_tax ?? 0) >= 0 ? 'text-success-700' : 'text-danger-600'">
            {{ format(estimate.vh_before_tax) }}
          </div>
        </div>
        <div v-if="hasTax" class="rounded-md border border-warning-100 bg-warning-50/40 p-3" data-test="estimate-main-after-tax">
          <div class="text-xs text-neutral-500">{{ t('accounting.statement_accounts.estimate.vh_after_tax') }}</div>
          <div class="text-xl font-semibold font-mono whitespace-nowrap" :class="(estimate.vh_after_tax ?? 0) >= 0 ? 'text-success-700' : 'text-danger-600'">
            {{ format(estimate.vh_after_tax) }}
          </div>
        </div>
        <div v-if="part === 'balance' && hasTax" class="rounded-md border border-warning-100 bg-warning-50/40 p-3" data-test="estimate-main-341">
          <div class="text-xs text-neutral-500">{{ t('accounting.statement_accounts.estimate.balance_341') }}</div>
          <div class="text-xl font-semibold font-mono whitespace-nowrap" :class="(estimate.balance_due ?? 0) >= 0 ? 'text-danger-600' : 'text-success-700'">
            {{ format(estimate.balance_due) }}
          </div>
        </div>
      </div>

      <div v-if="(estimate.projection_warnings ?? []).length"
        class="mx-3 mt-2 bg-warning-50 border border-warning-500/40 rounded-md p-2.5 text-xs text-warning-700" data-test="estimate-projection-warnings">
        <strong>{{ t('taxReturn.projection_incomplete') }}</strong>
        <ul class="mt-1 list-disc list-inside">
          <li v-for="w in estimate.projection_warnings" :key="w.source">{{ warningLabel(w) }}: {{ w.reason }}</li>
        </ul>
      </div>

      <ul v-if="(estimate.depreciation_warnings ?? []).length"
        class="mx-3 mt-2 bg-warning-50 border border-warning-500/40 rounded-md p-2.5 text-xs text-warning-700 list-disc list-inside space-y-1" data-test="estimate-depreciation-warnings">
        <li v-for="(w, i) in estimate.depreciation_warnings" :key="i">{{ w }}</li>
      </ul>

      <div class="px-3 py-2">
        <button type="button" class="inline-flex items-center gap-1 text-sm font-medium text-primary-600 hover:text-primary-700 cursor-pointer whitespace-nowrap"
          :aria-expanded="expanded" data-test="estimate-toggle" @click="expanded = !expanded">
          <span class="inline-block transition-transform" :class="{ 'rotate-90': expanded }">▸</span>
          {{ expanded ? t('accounting.statement_accounts.estimate.hide_detail') : t('accounting.statement_accounts.estimate.show_detail') }}
        </button>
      </div>

      <div v-if="expanded" class="overflow-x-auto" data-test="estimate-detail">
        <table class="w-full text-sm">
          <thead class="text-xs text-neutral-500 uppercase tracking-wide">
            <tr>
              <th class="px-3 py-2 text-left font-medium">{{ t('accounting.statement_accounts.estimate.col_item') }}</th>
              <th class="px-3 py-2 text-right font-medium w-40">{{ t('accounting.statement_accounts.estimate.col_amount') }}</th>
              <th class="px-3 py-2 text-left font-medium w-44">{{ t('accounting.statement_accounts.estimate.col_source') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <template v-if="bridge">
              <tr data-test="estimate-bridge-as-of">
                <td class="px-3 py-1.5">{{ t('accounting.statement_accounts.estimate.vh_as_of', { date: formatDate(bridge.as_of) }) }}</td>
                <td class="px-3 py-1.5 text-right font-mono whitespace-nowrap">{{ format(bridge.vh_as_of) }}</td>
                <td class="px-3 py-1.5 text-xs text-neutral-500">{{ t('accounting.statement_accounts.estimate.src_statement') }}</td>
              </tr>
              <tr v-for="g in bridge.groups" :key="g.key" :data-test="`estimate-bridge-${g.key}`">
                <td class="px-3 py-1.5 pl-6">{{ t(`accounting.statement_accounts.estimate.bridge_${g.key}`, { date: formatDate(bridge.as_of) }) }}</td>
                <td class="px-3 py-1.5 text-right font-mono whitespace-nowrap">{{ signedValue(g.amount) }}</td>
                <td class="px-3 py-1.5 text-xs"><RouterLink v-if="laterJournalLink" :to="laterJournalLink" class="text-primary-600 hover:underline">{{ t('accounting.statement_accounts.estimate.src_journal') }}</RouterLink></td>
              </tr>
            </template>
            <tr class="font-semibold" data-test="estimate-vh-posted">
              <td class="px-3 py-1.5">{{ t('accounting.statement_accounts.estimate.vh_posted', { date: endsOn }) }}</td>
              <td class="px-3 py-1.5 text-right font-mono whitespace-nowrap">{{ format(estimate.vh_posted) }}</td>
              <td class="px-3 py-1.5 text-xs font-normal"><RouterLink :to="dppoLink('podklady')" class="text-primary-600 hover:underline">{{ t('accounting.statement_accounts.estimate.src_dppo') }}</RouterLink></td>
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

        <template v-if="part === 'balance' && (estimate.balance_effects ?? []).length">
          <h3 class="px-3 pt-4 pb-1 text-sm font-semibold text-neutral-700">{{ t('accounting.statement_accounts.estimate.balance_title') }}</h3>
          <table class="w-full text-sm" data-test="estimate-balance-effects">
            <thead class="text-xs text-neutral-500 uppercase tracking-wide">
              <tr>
                <th class="px-3 py-2 text-left font-medium">{{ t('accounting.statement_accounts.estimate.col_item') }}</th>
                <th class="px-3 py-2 text-left font-medium w-40">{{ t('accounting.statement_accounts.estimate.col_account') }}</th>
                <th class="px-3 py-2 text-right font-medium w-36">{{ t('accounting.statement_accounts.estimate.col_assets') }}</th>
                <th class="px-3 py-2 text-right font-medium w-36">{{ t('accounting.statement_accounts.estimate.col_liabilities') }}</th>
                <th class="px-3 py-2 text-left font-medium w-44">{{ t('accounting.statement_accounts.estimate.col_source') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="b in estimate.balance_effects" :key="b.key" :class="b.optional ? 'text-neutral-400' : ''" :data-test="`estimate-balance-${b.key}`">
                <td class="px-3 py-1.5">
                  {{ closingLabel(b) }}
                  <span v-if="b.optional" class="text-[10px] uppercase ml-1">· {{ t('accounting.statement_accounts.estimate.optional_hint') }}</span>
                </td>
                <td class="px-3 py-1.5 font-mono text-xs whitespace-nowrap">
                  {{ b.accounts ?? '' }}
                  <span v-if="b.side === 'net'" class="font-sans text-neutral-500">({{ t('accounting.statement_accounts.estimate.side_net') }})</span>
                </td>
                <td class="px-3 py-1.5 text-right font-mono whitespace-nowrap">{{ b.side === 'liability' ? '' : signedValue(b.assets) }}</td>
                <td class="px-3 py-1.5 text-right font-mono whitespace-nowrap">{{ b.side === 'liability' ? signedValue(b.liabilities) : '' }}</td>
                <td class="px-3 py-1.5 text-xs">
                  <RouterLink v-if="b.key === 'income_tax'" :to="dppoLink('nahled')" class="text-primary-600 hover:underline">{{ t('accounting.statement_accounts.estimate.src_dppo') }}</RouterLink>
                  <RouterLink v-else-if="closingProjectionSource(b.key, periodId)" :to="closingProjectionSource(b.key, periodId)!.to" class="text-primary-600 hover:underline">
                    {{ t(closingProjectionSource(b.key, periodId)!.labelKey) }}
                  </RouterLink>
                </td>
              </tr>
            </tbody>
            <tfoot v-if="estimate.balance_check">
              <tr class="font-semibold border-t-2 border-neutral-300" data-test="estimate-balance-check">
                <td class="px-3 py-2" colspan="2">
                  {{ t('accounting.statement_accounts.estimate.balance_check', { difference: format(estimate.balance_check.difference), expected: format(estimate.balance_check.expected) }) }}
                </td>
                <td class="px-3 py-2 text-right font-mono whitespace-nowrap">{{ signedValue(estimate.balance_check.assets) }}</td>
                <td class="px-3 py-2 text-right font-mono whitespace-nowrap">{{ signedValue(estimate.balance_check.liabilities) }}</td>
                <td class="px-3 py-2 text-xs font-normal" :class="estimate.balance_check.matches ? 'text-success-700' : 'text-danger-600'">
                  {{ estimate.balance_check.matches ? t('accounting.statement_accounts.estimate.balance_check_ok') : t('accounting.statement_accounts.estimate.balance_check_mismatch') }}
                </td>
              </tr>
            </tfoot>
          </table>
        </template>
      </div>
    </template>
  </section>
</template>

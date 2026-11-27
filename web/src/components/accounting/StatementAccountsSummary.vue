<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { YearEndTaxEstimate } from '@/api/accounting'
import { formatDate } from '@/composables/useFormat'

/**
 * Souhrnný pruh nad pohledem po účtech otevřeného roku: VH k datu výkazu, zaúčtovaný VH
 * do konce období a odhad před a po zdanění. Data jsou z téhož odhadu, který ukazuje blok
 * Odhad do konce roku pod tabulkou (stránka je předává, žádný další request ani výpočet).
 */
const props = defineProps<{
  estimate: YearEndTaxEstimate
  /** Den výkazu; bez můstku (výkaz ke konci období) se VH k datu rovná zaúčtovanému VH. */
  asOf: string
  /** Tabulka je s průběžným časovým rozlišením a odpisy. */
  accrualApplied: boolean
  format: (value: number | null | undefined) => string
}>()

const { t } = useI18n()

const bridge = computed(() => props.estimate.bridge ?? null)
const asOfDate = computed(() => formatDate(bridge.value?.as_of ?? props.asOf))
const endsOn = computed(() => formatDate(props.estimate.period.ends_on))
const vhAsOf = computed(() => bridge.value ? bridge.value.vh_as_of : (props.estimate.vh_posted ?? null))
const hasTax = computed(() => props.estimate.tax !== undefined && props.estimate.tax !== null)

function tone(value: number | null | undefined): string {
  return (value ?? 0) >= 0 ? 'text-success-700' : 'text-danger-600'
}
</script>

<template>
  <section v-if="estimate.applicable" class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-3 mb-4" data-test="accounts-summary">
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
      <div class="rounded-md border border-neutral-200 p-3" data-test="accounts-summary-as-of">
        <div class="flex flex-wrap items-center gap-1.5 text-xs text-neutral-500">
          <span>{{ t('accounting.statement_accounts.summary.vh_as_of', { date: asOfDate }) }}</span>
          <span v-if="accrualApplied" class="text-[11px] font-semibold px-1.5 py-0.5 rounded bg-warning-100 text-warning-700 whitespace-nowrap"
            data-test="accounts-summary-spread-badge">
            {{ t('accounting.statement_accounts.summary.with_spread') }}
          </span>
        </div>
        <div class="text-lg font-semibold font-mono whitespace-nowrap" :class="tone(vhAsOf)">{{ format(vhAsOf) }}</div>
      </div>
      <div class="rounded-md border-2 border-primary-300 bg-primary-50 p-3" data-test="accounts-summary-posted">
        <div class="text-xs font-medium text-primary-800">{{ t('accounting.statement_accounts.summary.vh_posted', { date: endsOn }) }}</div>
        <div class="text-xl font-bold font-mono whitespace-nowrap" :class="tone(estimate.vh_posted)">{{ format(estimate.vh_posted) }}</div>
      </div>
      <div class="rounded-md border border-neutral-200 p-3" data-test="accounts-summary-before-tax">
        <div class="text-xs text-neutral-500">{{ t('accounting.statement_accounts.summary.before_tax') }}</div>
        <div class="text-lg font-semibold font-mono whitespace-nowrap" :class="tone(estimate.vh_before_tax)">{{ format(estimate.vh_before_tax) }}</div>
      </div>
      <div v-if="hasTax" class="rounded-md border border-neutral-200 p-3" data-test="accounts-summary-after-tax">
        <div class="text-xs text-neutral-500">{{ t('accounting.statement_accounts.summary.after_tax') }}</div>
        <div class="text-lg font-semibold font-mono whitespace-nowrap" :class="tone(estimate.vh_after_tax)">{{ format(estimate.vh_after_tax) }}</div>
      </div>
    </div>
    <p v-if="bridge" class="mt-2 text-xs text-neutral-500" data-test="accounts-summary-note">
      {{ t('accounting.statement_accounts.summary.note', { date: asOfDate, end: endsOn }) }}
    </p>
  </section>
</template>

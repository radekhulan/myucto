<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import {
  payrollPostingApi,
  PAYROLL_POSTING_INFORMATIONAL_CATEGORIES,
  type PayrollPostingReconciliation,
  type PayrollPostingReconciliationCategory,
  type PayrollPostingReconciliationCategoryKey,
} from '@/api/payrollPosting'
import { apiErrorMessage } from '@/api/errors'
import { btnOutline, ICONS } from '@/components/ui/buttonStyles'
// Formátování je sdílené (useFormat) — místní kopie se rozcházely v locale i tvaru.
import { formatMoneyMinor as formatMoney } from '@/composables/useFormat'
import EmptyState from '@/components/ui/EmptyState.vue'
import { localPayrollPeriod } from './payrollComponentsUi'

const { t, te } = useI18n()
const period = ref(localPayrollPeriod())
const loading = ref(true)
const loadError = ref('')
const result = ref<PayrollPostingReconciliation | null>(null)
const expandedKey = ref<PayrollPostingReconciliationCategoryKey | null>(null)
let loadSequence = 0

const CATEGORY_KEYS: PayrollPostingReconciliationCategoryKey[] = [
  'gross_wages',
  'employer_contributions',
  'social_health_insurance',
  'income_tax',
  'other_deductions',
  'enforcement',
  'net_wage',
  'partner_settlement',
  'risky_savings',
  // Informativní řádky jdou záměrně poslední: nejsou to porovnání, jen doložení
  // částek, které se z porovnání vyjmuly nebo se v můstku vůbec neúčtují.
  'non_monetary_neutral',
  'tax_bonus_receivable',
  'unposted_liabilities',
]

function isInformational(key: PayrollPostingReconciliationCategoryKey): boolean {
  return PAYROLL_POSTING_INFORMATIONAL_CATEGORIES.includes(key)
}

/** Vysvětlivka má jen část kategorií; u ostatních se řádek detailu nemění. */
function categoryNote(key: PayrollPostingReconciliationCategoryKey): string {
  const path = `payroll.posting_reconciliation.category_note.${key}`
  return te(path) ? t(path) : ''
}

async function load(): Promise<void> {
  const sequence = ++loadSequence
  const requestedPeriod = period.value
  loading.value = true
  loadError.value = ''
  expandedKey.value = null
  try {
    const response = await payrollPostingApi.reconciliation(requestedPeriod)
    if (sequence !== loadSequence || requestedPeriod !== period.value) return
    result.value = response
  } catch (error: unknown) {
    if (sequence !== loadSequence || requestedPeriod !== period.value) return
    loadError.value = apiErrorMessage(error, t('payroll.posting_reconciliation.load_failed'))
    result.value = null
  } finally {
    if (sequence === loadSequence) loading.value = false
  }
}

function formatDiff(diffMinor: number | null): string {
  if (diffMinor === null) return '—'
  const formatted = formatMoney(Math.abs(diffMinor))
  if (diffMinor === 0) return formatted
  return diffMinor > 0 ? `+${formatted}` : `−${formatted}`
}

function toggle(key: PayrollPostingReconciliationCategoryKey): void {
  expandedKey.value = expandedKey.value === key ? null : key
}

function categoryLabel(key: PayrollPostingReconciliationCategoryKey): string {
  return t(`payroll.posting_reconciliation.categories.${key}`)
}

/**
 * Informativní kategorie nesmí nést štítek „Nepoužije se" — v řadě, kde ostatní
 * řádky říkají souhlasí/rozdíl, to čte jako selhání kontroly. Dostává vlastní,
 * modrý tón: je to doložení částky, ne výsledek porovnání.
 */
function statusLabel(category: PayrollPostingReconciliationCategory): string {
  if (isInformational(category.key)) {
    return t('payroll.posting_reconciliation.informational_badge')
  }
  return t(`payroll.posting_reconciliation.category_status.${category.status}`)
}

function statusBadgeClass(category: PayrollPostingReconciliationCategory): string {
  if (isInformational(category.key)) return 'bg-primary-50 text-primary-700'
  if (category.status === 'diff') return 'bg-danger-50 text-danger-700'
  if (category.status === 'match') return 'bg-success-50 text-success-700'
  return 'bg-neutral-100 text-neutral-600'
}

const orderedCategories = computed<PayrollPostingReconciliationCategory[]>(() => {
  const byKey = new Map((result.value?.categories ?? []).map(category => [category.key, category]))
  return CATEGORY_KEYS.map(key => byKey.get(key)).filter((c): c is PayrollPostingReconciliationCategory => !!c)
})

const hasNoRun = computed(() => result.value !== null && result.value.run === null)
const isUnapproved = computed(() => result.value !== null
  && result.value.run !== null
  && (result.value.revision === null
    || !['approved', 'superseded'].includes(result.value.revision.status)))
const showCategories = computed(() => orderedCategories.value.length > 0)

const summaryVariant = computed<'success' | 'warning' | 'danger' | 'neutral'>(() => {
  if (!result.value) return 'neutral'
  if (result.value.overall_status === 'diff') return 'danger'
  if (result.value.overall_status === 'reconciled') return 'success'
  return 'neutral'
})

const summaryText = computed(() => {
  if (!result.value) return ''
  if (result.value.overall_status === 'diff') {
    return t('payroll.posting_reconciliation.summary.diff')
  }
  if (result.value.overall_status === 'reconciled') {
    return t('payroll.posting_reconciliation.summary.reconciled')
  }
  if (result.value.journal_state === 'unposted') {
    return t('payroll.posting_reconciliation.summary.unposted')
  }
  if (result.value.accounting_mode !== 'double_entry') {
    return t('payroll.posting_reconciliation.summary.tax_evidence')
  }
  return t('payroll.posting_reconciliation.summary.info')
})

onMounted(load)
</script>

<template>
  <div class="space-y-6">
    <header class="flex flex-wrap items-start justify-between gap-4">
      <div>
        <h1 class="text-2xl font-semibold text-neutral-900">
          {{ t('payroll.posting_reconciliation.title') }}
        </h1>
        <p class="mt-1 max-w-3xl text-sm text-neutral-500">
          {{ t('payroll.posting_reconciliation.subtitle') }}
        </p>
      </div>
      <div class="flex flex-wrap items-end gap-2">
        <label class="block">
          <span class="mb-1 block text-xs font-medium text-neutral-600">
            {{ t('payroll.posting_reconciliation.period') }}
          </span>
          <input
            v-model="period"
            type="month"
            min="2024-01"
            class="h-9 rounded-md border border-neutral-300 bg-surface px-3 text-sm focus:border-payroll-500 focus:ring-payroll-500/20"
            @change="load"
          >
        </label>
        <button
          type="button"
          :class="btnOutline('neutral')"
          :disabled="loading"
          data-test="reconciliation-reload"
          @click="load"
        >
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path :d="ICONS.cycle" />
          </svg>
          {{ t('payroll.posting_reconciliation.reload') }}
        </button>
      </div>
    </header>

    <div
      v-if="loadError"
      class="rounded-lg border border-danger-500/30 bg-danger-50 p-3 text-sm text-danger-700"
      role="alert"
    >
      <p>{{ loadError }}</p>
      <button
        type="button"
        :class="[btnOutline('danger'), 'mt-3']"
        data-test="reconciliation-retry"
        @click="load"
      >
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
          <path :d="ICONS.cycle" />
        </svg>
        {{ t('payroll.posting_reconciliation.retry') }}
      </button>
    </div>

    <p v-else-if="loading" class="text-sm text-neutral-500">
      {{ t('common.loading') }}
    </p>

    <template v-else-if="result">
      <EmptyState
        v-if="hasNoRun"
        boxed
        icon="inbox"
        accent="neutral"
        :title="t('payroll.posting_reconciliation.empty_no_run.title')"
        :message="t('payroll.posting_reconciliation.empty_no_run.message')"
      />
      <EmptyState
        v-else-if="isUnapproved"
        boxed
        icon="lock"
        accent="warning"
        :title="t('payroll.posting_reconciliation.empty_unapproved.title')"
        :message="t('payroll.posting_reconciliation.empty_unapproved.message')"
      />
      <template v-else>
        <section
          class="rounded-xl border p-4 text-sm shadow-sm"
          :class="{
            'border-success-500/30 bg-success-50 text-success-700': summaryVariant === 'success',
            'border-danger-500/30 bg-danger-50 text-danger-700': summaryVariant === 'danger',
            'border-neutral-200 bg-neutral-50 text-neutral-700': summaryVariant === 'neutral',
          }"
          role="status"
        >
          <p class="font-medium">{{ summaryText }}</p>
          <p v-if="result.accounting_mode !== 'double_entry'" class="mt-1 text-xs opacity-80">
            {{ t('payroll.posting_reconciliation.hint_tax_evidence') }}
          </p>
          <p v-else-if="result.journal_state === 'unposted'" class="mt-1 text-xs opacity-80">
            {{ t('payroll.posting_reconciliation.hint_unposted') }}
          </p>
          <p v-if="result.payments_state === 'not_materialized'" class="mt-1 text-xs opacity-80">
            {{ t('payroll.posting_reconciliation.hint_payments_not_materialized') }}
          </p>
        </section>

        <section
          v-if="showCategories"
          class="hidden overflow-x-auto rounded-xl border border-neutral-200 bg-surface shadow-sm md:block"
          data-test="reconciliation-desktop"
        >
          <table class="min-w-full divide-y divide-neutral-200 text-sm">
            <thead class="bg-neutral-50 text-left text-xs uppercase tracking-wide text-neutral-500">
              <tr>
                <th class="px-3 py-2">{{ t('payroll.posting_reconciliation.table.category') }}</th>
                <th class="px-3 py-2 text-right">{{ t('payroll.posting_reconciliation.table.payroll') }}</th>
                <th class="px-3 py-2 text-right">{{ t('payroll.posting_reconciliation.table.journal') }}</th>
                <th class="px-3 py-2 text-right">{{ t('payroll.posting_reconciliation.table.diff_journal') }}</th>
                <th class="px-3 py-2 text-right">{{ t('payroll.posting_reconciliation.table.payments_liability') }}</th>
                <th class="px-3 py-2 text-right">{{ t('payroll.posting_reconciliation.table.payments_paid') }}</th>
                <th class="px-3 py-2 text-right">{{ t('payroll.posting_reconciliation.table.diff_payments') }}</th>
                <th class="px-3 py-2 text-right">{{ t('payroll.posting_reconciliation.table.diff_journal_payments') }}</th>
                <th class="px-3 py-2">{{ t('payroll.posting_reconciliation.table.status') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-200">
              <template v-for="category in orderedCategories" :key="category.key">
                <tr
                  class="cursor-pointer hover:bg-neutral-50"
                  :class="isInformational(category.key) ? 'bg-primary-50/40 italic' : ''"
                  :data-test="`reconciliation-desktop-row-${category.key}`"
                  @click="toggle(category.key)"
                >
                  <td class="px-3 py-3 font-medium text-neutral-900">
                    <button
                      type="button"
                      class="cursor-pointer text-left font-medium"
                      :aria-expanded="expandedKey === category.key"
                      :aria-controls="`payroll-reconciliation-desktop-detail-${category.key}`"
                      :data-test="`reconciliation-desktop-toggle-${category.key}`"
                      @click.stop="toggle(category.key)"
                    >
                      {{ categoryLabel(category.key) }}
                    </button>
                  </td>
                  <td class="px-3 py-3 text-right font-mono">{{ formatMoney(category.payroll_minor) }}</td>
                  <td class="px-3 py-3 text-right font-mono">{{ formatMoney(category.journal_minor) }}</td>
                  <td
                    class="px-3 py-3 text-right font-mono"
                    :class="category.diff_payroll_journal_minor ? 'text-danger-700 font-semibold' : 'text-neutral-500'"
                  >
                    {{ formatDiff(category.diff_payroll_journal_minor) }}
                  </td>
                  <td class="px-3 py-3 text-right font-mono">{{ formatMoney(category.payments_liability_minor) }}</td>
                  <td class="px-3 py-3 text-right font-mono">{{ formatMoney(category.payments_paid_minor) }}</td>
                  <td
                    class="px-3 py-3 text-right font-mono"
                    :class="category.diff_payroll_payments_minor ? 'text-danger-700 font-semibold' : 'text-neutral-500'"
                  >
                    {{ formatDiff(category.diff_payroll_payments_minor) }}
                  </td>
                  <td
                    class="px-3 py-3 text-right font-mono"
                    :class="category.diff_journal_payments_minor ? 'text-danger-700 font-semibold' : 'text-neutral-500'"
                    :data-test="`reconciliation-desktop-diff-journal-payments-${category.key}`"
                  >
                    {{ formatDiff(category.diff_journal_payments_minor) }}
                  </td>
                  <td class="px-3 py-3">
                    <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium" :class="statusBadgeClass(category)">
                      {{ statusLabel(category) }}
                    </span>
                  </td>
                </tr>
                <tr
                  v-if="expandedKey === category.key"
                  :id="`payroll-reconciliation-desktop-detail-${category.key}`"
                >
                  <td colspan="9" class="bg-neutral-50 px-3 py-3 text-xs text-neutral-600">
                    <p v-if="categoryNote(category.key)" :data-test="`reconciliation-desktop-note-${category.key}`">
                      {{ categoryNote(category.key) }}
                    </p>
                    <p :class="categoryNote(category.key) ? 'mt-1' : ''">
                      {{ t('payroll.posting_reconciliation.detail_hint') }}
                    </p>
                  </td>
                </tr>
              </template>
            </tbody>
          </table>
        </section>

        <section
          v-if="showCategories"
          class="space-y-3 md:hidden"
          data-test="reconciliation-mobile"
        >
          <article
            v-for="category in orderedCategories"
            :key="`mobile-${category.key}`"
            class="rounded-lg border p-4"
            :class="isInformational(category.key)
              ? 'border-primary-500/30 bg-primary-50/40'
              : 'border-neutral-200'"
            :data-test="`reconciliation-mobile-row-${category.key}`"
            @click="toggle(category.key)"
          >
            <div class="flex items-start justify-between gap-3">
              <button
                type="button"
                class="cursor-pointer text-left font-medium text-neutral-900"
                :aria-expanded="expandedKey === category.key"
                :aria-controls="`payroll-reconciliation-mobile-detail-${category.key}`"
                :data-test="`reconciliation-mobile-toggle-${category.key}`"
                @click.stop="toggle(category.key)"
              >
                {{ categoryLabel(category.key) }}
              </button>
              <span class="inline-flex shrink-0 rounded-full px-2 py-0.5 text-xs font-medium" :class="statusBadgeClass(category)">
                {{ statusLabel(category) }}
              </span>
            </div>
            <dl class="mt-3 grid grid-cols-2 gap-x-3 gap-y-2 text-sm">
              <div>
                <dt class="text-xs text-neutral-500">{{ t('payroll.posting_reconciliation.table.payroll') }}</dt>
                <dd class="font-mono text-neutral-900">{{ formatMoney(category.payroll_minor) }}</dd>
              </div>
              <div>
                <dt class="text-xs text-neutral-500">{{ t('payroll.posting_reconciliation.table.journal') }}</dt>
                <dd class="font-mono text-neutral-900">{{ formatMoney(category.journal_minor) }}</dd>
              </div>
              <div>
                <dt class="text-xs text-neutral-500">{{ t('payroll.posting_reconciliation.table.payments_liability') }}</dt>
                <dd class="font-mono text-neutral-900">{{ formatMoney(category.payments_liability_minor) }}</dd>
              </div>
              <div>
                <dt class="text-xs text-neutral-500">{{ t('payroll.posting_reconciliation.table.payments_paid') }}</dt>
                <dd class="font-mono text-neutral-900">{{ formatMoney(category.payments_paid_minor) }}</dd>
              </div>
            </dl>
            <p
              v-if="category.diff_payroll_journal_minor || category.diff_payroll_payments_minor
                || category.diff_journal_payments_minor"
              class="mt-2 text-xs font-semibold text-danger-700"
            >
              {{ t('payroll.posting_reconciliation.table.diff_journal') }}: {{ formatDiff(category.diff_payroll_journal_minor) }}
              · {{ t('payroll.posting_reconciliation.table.diff_payments') }}: {{ formatDiff(category.diff_payroll_payments_minor) }}
              · {{ t('payroll.posting_reconciliation.table.diff_journal_payments') }}: {{ formatDiff(category.diff_journal_payments_minor) }}
            </p>
            <div
              v-if="expandedKey === category.key"
              :id="`payroll-reconciliation-mobile-detail-${category.key}`"
              class="mt-3 border-t border-neutral-200 pt-3 text-xs text-neutral-600"
            >
              <p v-if="categoryNote(category.key)" :data-test="`reconciliation-mobile-note-${category.key}`">
                {{ categoryNote(category.key) }}
              </p>
              <p :class="categoryNote(category.key) ? 'mt-1' : ''">
                {{ t('payroll.posting_reconciliation.detail_hint') }}
              </p>
            </div>
          </article>
        </section>
      </template>
    </template>
  </div>
</template>

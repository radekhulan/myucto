<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { payrollPostingApi, type PayrollPostingDetailReport } from '@/api/payrollPosting'
import { apiErrorMessage } from '@/api/errors'
import { btnOutline, ICONS } from '@/components/ui/buttonStyles'
import { formatMoneyMinor as formatMoney } from '@/composables/useFormat'
import EmptyState from '@/components/ui/EmptyState.vue'
import { payrollWorkingPeriod } from './payrollComponentsUi'

const { t } = useI18n()
const period = ref(payrollWorkingPeriod())
const loading = ref(true)
const loadError = ref('')
const exporting = ref<'xlsx' | 'csv' | null>(null)
const exportError = ref('')
const report = ref<PayrollPostingDetailReport | null>(null)
let loadSequence = 0

async function load(): Promise<void> {
  const sequence = ++loadSequence
  const requestedPeriod = period.value
  loading.value = true
  loadError.value = ''
  try {
    const response = await payrollPostingApi.detail(requestedPeriod)
    if (sequence !== loadSequence || requestedPeriod !== period.value) return
    report.value = response
  } catch (error: unknown) {
    if (sequence !== loadSequence || requestedPeriod !== period.value) return
    loadError.value = apiErrorMessage(error, t('payroll.posting_detail.load_failed'))
    report.value = null
  } finally {
    if (sequence === loadSequence) loading.value = false
  }
}

async function exportReport(format: 'xlsx' | 'csv'): Promise<void> {
  exporting.value = format
  exportError.value = ''
  try {
    await payrollPostingApi.exportDetail(format, period.value)
  } catch (error: unknown) {
    exportError.value = apiErrorMessage(error, t('payroll.posting_detail.export_failed'))
  } finally {
    exporting.value = null
  }
}

function formatHours(hours: number | null): string {
  if (hours === null) return ''
  return hours.toLocaleString('cs-CZ', { maximumFractionDigits: 2 })
}

function person(row: { personal_number: string | null; name: string | null }): string {
  if (row.personal_number === null) return t('payroll.posting_detail.company_wide')
  return row.name ? `${row.personal_number} - ${row.name}` : row.personal_number
}

const isEmpty = computed(() => report.value !== null && report.value.rows.length === 0)

onMounted(load)
</script>

<template>
  <div class="space-y-6">
    <header class="flex flex-wrap items-start justify-between gap-4">
      <div>
        <h1 class="text-2xl font-semibold text-neutral-900">
          {{ t('payroll.posting_detail.title') }}
        </h1>
        <p class="mt-1 max-w-3xl text-sm text-neutral-500">
          {{ t('payroll.posting_detail.subtitle') }}
        </p>
      </div>
      <div class="flex flex-wrap items-end gap-2">
        <label class="block">
          <span class="mb-1 block text-xs font-medium text-neutral-600">
            {{ t('payroll.posting_detail.period') }}
          </span>
          <input
            v-model="period"
            type="month"
            min="2024-01"
            class="h-9 rounded-md border border-neutral-300 bg-surface px-3 text-sm focus:border-payroll-500 focus:ring-payroll-500/20"
            data-test="posting-detail-period"
            @change="load"
          >
        </label>
        <button
          type="button"
          :class="[btnOutline('neutral'), 'whitespace-nowrap']"
          :disabled="loading"
          data-test="posting-detail-reload"
          @click="load"
        >
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path :d="ICONS.cycle" />
          </svg>
          {{ t('payroll.posting_detail.reload') }}
        </button>
        <button
          type="button"
          :class="[btnOutline('neutral'), 'whitespace-nowrap']"
          :disabled="loading || exporting !== null || isEmpty"
          data-test="posting-detail-export-xlsx"
          @click="exportReport('xlsx')"
        >
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path :d="ICONS.download" />
          </svg>
          {{ t('payroll.posting_detail.export_xlsx') }}
        </button>
        <button
          type="button"
          :class="[btnOutline('neutral'), 'whitespace-nowrap']"
          :disabled="loading || exporting !== null || isEmpty"
          data-test="posting-detail-export-csv"
          @click="exportReport('csv')"
        >
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path :d="ICONS.download" />
          </svg>
          {{ t('payroll.posting_detail.export_csv') }}
        </button>
      </div>
    </header>

    <p v-if="exportError" class="rounded-lg border border-danger-500/30 bg-danger-50 p-3 text-sm text-danger-700" role="alert">
      {{ exportError }}
    </p>

    <div
      v-if="loadError"
      class="rounded-lg border border-danger-500/30 bg-danger-50 p-3 text-sm text-danger-700"
      role="alert"
    >
      <p>{{ loadError }}</p>
      <button type="button" :class="[btnOutline('danger'), 'mt-3']" data-test="posting-detail-retry" @click="load">
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
          <path :d="ICONS.cycle" />
        </svg>
        {{ t('payroll.posting_detail.retry') }}
      </button>
    </div>

    <p v-else-if="loading" class="text-sm text-neutral-500">
      {{ t('common.loading') }}
    </p>

    <template v-else-if="report">
      <EmptyState
        v-if="isEmpty"
        boxed
        icon="inbox"
        accent="neutral"
        :title="t('payroll.posting_detail.empty.title')"
        :message="t('payroll.posting_detail.empty.message')"
        :cta="t('payroll.posting_detail.open_runs')"
        cta-icon="cycle"
        to="/payroll/runs"
      />
      <template v-else>
        <section class="rounded-xl border border-neutral-200 bg-surface shadow-sm" data-test="posting-detail-summary">
          <h2 class="border-b border-neutral-200 px-4 py-3 text-base font-semibold text-neutral-900">
            {{ t('payroll.posting_detail.summary_title') }}
          </h2>
          <div class="hidden overflow-x-auto md:block">
            <table class="min-w-full divide-y divide-neutral-200 text-sm">
              <thead class="bg-neutral-50 text-left text-xs uppercase tracking-wide text-neutral-500">
                <tr>
                  <th class="px-3 py-2">{{ t('payroll.posting_detail.table.label') }}</th>
                  <th class="px-3 py-2">{{ t('payroll.posting_detail.table.debit') }}</th>
                  <th class="px-3 py-2">{{ t('payroll.posting_detail.table.credit') }}</th>
                  <th class="px-3 py-2 text-right">{{ t('payroll.posting_detail.table.hours') }}</th>
                  <th class="px-3 py-2 text-right">{{ t('payroll.posting_detail.table.amount') }}</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-neutral-100">
                <tr v-for="(row, index) in report.summary" :key="`summary-${index}`">
                  <td class="px-3 py-2 text-neutral-900">{{ row.label }}</td>
                  <td class="px-3 py-2 font-mono">{{ row.debit_account ?? '' }}</td>
                  <td class="px-3 py-2 font-mono">{{ row.credit_account ?? '' }}</td>
                  <td class="px-3 py-2 text-right font-mono">{{ formatHours(row.hours) }}</td>
                  <td class="px-3 py-2 text-right font-mono">{{ formatMoney(row.amount_minor) }}</td>
                </tr>
              </tbody>
              <tfoot class="bg-neutral-50 font-semibold">
                <tr>
                  <td class="px-3 py-2" colspan="3">{{ t('payroll.posting_detail.total') }}</td>
                  <td class="px-3 py-2 text-right font-mono">{{ formatHours(report.totals.hours) }}</td>
                  <td class="px-3 py-2 text-right font-mono" data-test="posting-detail-total">{{ formatMoney(report.totals.amount_minor) }}</td>
                </tr>
              </tfoot>
            </table>
          </div>
          <div class="space-y-2 p-3 md:hidden">
            <article v-for="(row, index) in report.summary" :key="`summary-m-${index}`" class="rounded-lg border border-neutral-200 p-3 text-sm">
              <p class="font-medium text-neutral-900">{{ row.label }}</p>
              <p class="mt-1 flex flex-wrap justify-between gap-2 font-mono text-neutral-700">
                <span>{{ row.debit_account ?? '' }} / {{ row.credit_account ?? '' }}</span>
                <span>{{ formatMoney(row.amount_minor) }}</span>
              </p>
            </article>
            <p class="flex justify-between px-1 text-sm font-semibold">
              <span>{{ t('payroll.posting_detail.total') }}</span>
              <span class="font-mono">{{ formatMoney(report.totals.amount_minor) }}</span>
            </p>
          </div>
        </section>

        <section class="rounded-xl border border-neutral-200 bg-surface shadow-sm" data-test="posting-detail-rows">
          <h2 class="border-b border-neutral-200 px-4 py-3 text-base font-semibold text-neutral-900">
            {{ t('payroll.posting_detail.rows_title') }}
          </h2>
          <div class="hidden overflow-x-auto md:block">
            <table class="min-w-full divide-y divide-neutral-200 text-sm">
              <thead class="bg-neutral-50 text-left text-xs uppercase tracking-wide text-neutral-500">
                <tr>
                  <th class="px-3 py-2">{{ t('payroll.posting_detail.table.person') }}</th>
                  <th class="px-3 py-2">{{ t('payroll.posting_detail.table.label') }}</th>
                  <th class="px-3 py-2">{{ t('payroll.posting_detail.table.debit') }}</th>
                  <th class="px-3 py-2">{{ t('payroll.posting_detail.table.credit') }}</th>
                  <th class="px-3 py-2">{{ t('payroll.posting_detail.table.cost_center') }}</th>
                  <th class="px-3 py-2 text-right">{{ t('payroll.posting_detail.table.hours') }}</th>
                  <th class="px-3 py-2 text-right">{{ t('payroll.posting_detail.table.amount') }}</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-neutral-100">
                <tr v-for="(row, index) in report.rows" :key="`row-${index}`">
                  <td class="px-3 py-2 text-neutral-900">{{ person(row) }}</td>
                  <td class="px-3 py-2">{{ row.label }}</td>
                  <td class="px-3 py-2 font-mono">{{ row.debit_account ?? '' }}</td>
                  <td class="px-3 py-2 font-mono">{{ row.credit_account ?? '' }}</td>
                  <td class="px-3 py-2">{{ row.cost_center ?? '' }}</td>
                  <td class="px-3 py-2 text-right font-mono">{{ formatHours(row.hours) }}</td>
                  <td class="px-3 py-2 text-right font-mono">{{ formatMoney(row.amount_minor) }}</td>
                </tr>
              </tbody>
              <tfoot class="bg-neutral-50 font-semibold">
                <tr>
                  <td class="px-3 py-2" colspan="5">{{ t('payroll.posting_detail.total') }}</td>
                  <td class="px-3 py-2 text-right font-mono">{{ formatHours(report.totals.hours) }}</td>
                  <td class="px-3 py-2 text-right font-mono">{{ formatMoney(report.totals.amount_minor) }}</td>
                </tr>
              </tfoot>
            </table>
          </div>
          <div class="space-y-2 p-3 md:hidden">
            <article v-for="(row, index) in report.rows" :key="`row-m-${index}`" class="rounded-lg border border-neutral-200 p-3 text-sm">
              <p class="font-medium text-neutral-900">{{ person(row) }}</p>
              <p class="text-neutral-600">{{ row.label }}</p>
              <p class="mt-1 flex flex-wrap justify-between gap-2 font-mono text-neutral-700">
                <span>{{ row.debit_account ?? '' }} / {{ row.credit_account ?? '' }}</span>
                <span>{{ formatMoney(row.amount_minor) }}</span>
              </p>
            </article>
          </div>
        </section>
      </template>
    </template>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted, reactive } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import {
  accountingApi,
  type AccountingPeriod,
  type BalanceSheetReport,
  type EntityCategory,
  type StatementScope,
  type StatementRowAccount,
} from '@/api/accounting'
import { useToast } from '@/composables/useToast'
import { formatMoney } from '@/composables/useFormat'
import { ICONS, btnOutline } from '@/components/ui/buttonStyles'
import ActivationBanner from '@/components/settings/activation/ActivationBanner.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { findAccountingPeriod } from '@/utils/accountingPeriod'

const { t, locale } = useI18n()
const toast = useToast()

// Klientský přepínač Kč / tis. Kč (F4 R17) — jen formátování při renderu, data beze změny.
const unit = ref<'czk' | 'thousands'>('czk')
function fm(value: number | null | undefined): string {
  if (unit.value === 'czk') return formatMoney(value)
  if (value === null || value === undefined || Number.isNaN(value)) return '—'
  return new Intl.NumberFormat(locale.value === 'en' ? 'en-US' : 'cs-CZ', { maximumFractionDigits: 0 })
    .format(Math.round(value / 1000))
}

const periods = ref<AccountingPeriod[]>([])
const report = ref<BalanceSheetReport | null>(null)
const category = ref<EntityCategory | null>(null)
const loading = ref(false)

const filters = reactive({
  period_id: '' as number | '',
  as_of: '',
  scope: 'auto' as StatementScope,
})

function queryParams() {
  return {
    period_id: Number(filters.period_id),
    as_of: filters.as_of || undefined,
    scope: filters.scope,
  }
}

async function load() {
  if (!filters.period_id) return
  loading.value = true
  expandedRowCode.value = null
  try {
    report.value = await accountingApi.getBalanceSheet(queryParams())
    if (filters.scope === 'auto') {
      try {
        category.value = await accountingApi.getEntityCategory(Number(filters.period_id))
      } catch {
        category.value = null
      }
    } else {
      category.value = null
    }
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
    report.value = null
  } finally {
    loading.value = false
  }
}

function onPeriodChange() {
  const period = findAccountingPeriod(periods.value, filters.period_id)
  if (!period) return
  filters.as_of = period.ends_on
  void load()
}

const expandedRowCode = ref<string | null>(null)
function toggleExpand(rowCode: string, accounts: StatementRowAccount[]) {
  if (!accounts.length) return
  expandedRowCode.value = expandedRowCode.value === rowCode ? null : rowCode
}

function accountLink(acc: StatementRowAccount) {
  return {
    name: 'accounting-account-statement',
    params: { accountId: acc.account_id },
    query: { from: report.value?.period.starts_on, to: report.value?.as_of },
  }
}

const exporting = ref(false)
async function exportFile(format: 'pdf' | 'xlsx') {
  if (!filters.period_id || !report.value) return
  exporting.value = true
  try {
    const r = await accountingApi.exportReport('/accounting/reports/balance-sheet/export', { ...queryParams(), format })
    downloadBlob(r.data as unknown as Blob, `rozvaha-${report.value.as_of}.${format}`)
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  } finally {
    exporting.value = false
  }
}

function downloadBlob(blob: Blob, filename: string) {
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = filename
  document.body.appendChild(a); a.click(); a.remove()
  URL.revokeObjectURL(url)
}

onMounted(async () => {
  try { periods.value = await accountingApi.listPeriods() } catch { periods.value = [] }
  const open = periods.value.filter(p => p.status === 'open')
  const def = open.length
    ? open.reduce((a, b) => (b.fiscal_year > a.fiscal_year ? b : a))
    : periods.value[0]
  if (def) {
    filters.period_id = def.id
    await load()
  }
})
</script>

<template>
  <div>
    <ActivationBanner />
    <div class="flex items-center justify-between mb-4">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('accounting.balance_sheet.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5">{{ t('accounting.balance_sheet.subtitle') }}</p>
      </div>
      <div class="flex items-center gap-2">
        <div class="flex rounded-md border border-neutral-300 overflow-hidden text-sm font-medium">
          <button @click="unit = 'czk'"
            class="cursor-pointer h-9 px-3" :class="unit === 'czk' ? 'bg-primary-600 text-white' : 'hover:bg-neutral-50'">
            {{ t('reports.unit_czk') }}
          </button>
          <button @click="unit = 'thousands'"
            class="cursor-pointer h-9 px-3 border-l border-neutral-300" :class="unit === 'thousands' ? 'bg-primary-600 text-white' : 'hover:bg-neutral-50'">
            {{ t('reports.unit_thousands') }}
          </button>
        </div>
        <button :disabled="!report || exporting" @click="exportFile('pdf')" :class="btnOutline('primary')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.download" /></svg>
          {{ t('accounting.balance_sheet.export_pdf') }}
        </button>
        <button :disabled="!report || exporting" @click="exportFile('xlsx')" :class="btnOutline('primary')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.download" /></svg>
          {{ t('accounting.balance_sheet.export_xlsx') }}
        </button>
      </div>
    </div>

    <!-- Filtry -->
    <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-3 mb-4">
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
        <div>
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.balance_sheet.filter_period') }}</label>
          <select v-model="filters.period_id" @change="onPeriodChange"
            class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface">
            <option v-for="p in periods" :key="p.id" :value="p.id">{{ p.fiscal_year }}</option>
          </select>
        </div>
        <div>
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.balance_sheet.filter_as_of') }}</label>
          <input v-model="filters.as_of" type="date" @change="load"
            class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm" />
        </div>
        <div>
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.balance_sheet.filter_scope') }}</label>
          <select v-model="filters.scope" @change="load"
            class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface">
            <option value="auto">{{ t('accounting.balance_sheet.scope_auto') }}</option>
            <option value="full">{{ t('accounting.balance_sheet.scope_full') }}</option>
            <option value="small">{{ t('accounting.balance_sheet.scope_small') }}</option>
            <option value="micro">{{ t('accounting.balance_sheet.scope_micro') }}</option>
          </select>
        </div>
        <div v-if="filters.scope === 'auto' && category" class="flex items-end pb-1">
          <span class="text-xs px-2 py-1 rounded bg-primary-50 text-primary-700 font-medium">
            {{ t(`accounting.balance_sheet.category_${category.category}`) }}
          </span>
        </div>
      </div>
    </div>

    <div v-if="loading" class="text-center text-neutral-500 py-12 text-sm">{{ t('common.loading') }}</div>

    <EmptyState v-else-if="!report" boxed accent="neutral" icon="doc" :title="t('accounting.balance_sheet.empty')" />

    <template v-else>
      <div class="text-xs text-neutral-500 mb-3">
        {{ report.entity.name }}<template v-if="report.entity.ico"> · IČO {{ report.entity.ico }}</template>
        · {{ t('accounting.balance_sheet.prepared_at') }}: {{ report.entity.prepared_at }}
        · {{ t('accounting.balance_sheet.version') }}: {{ report.version_code }}
        <template v-if="unit === 'thousands'"> · {{ t('reports.unit_thousands_note') }}</template>
      </div>

      <!-- AKTIVA -->
      <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden mb-4">
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
              <tr>
                <th class="px-3 py-2 text-left font-medium w-24">{{ t('accounting.balance_sheet.col_code') }}</th>
                <th class="px-3 py-2 text-left font-medium">{{ t('accounting.balance_sheet.assets') }}</th>
                <th class="px-3 py-2 text-right font-medium w-32">{{ t('accounting.balance_sheet.col_gross') }}</th>
                <th class="px-3 py-2 text-right font-medium w-32">{{ t('accounting.balance_sheet.col_correction') }}</th>
                <th class="px-3 py-2 text-right font-medium w-32">{{ t('accounting.balance_sheet.col_net') }}</th>
                <th class="px-3 py-2 text-right font-medium w-32">{{ t('accounting.balance_sheet.col_prev') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <template v-for="row in report.assets" :key="row.row_code">
                <tr :class="[
                    row.accounts.length ? 'cursor-pointer hover:bg-neutral-50' : '',
                    row.row_type !== 'detail' ? 'font-semibold' : '',
                  ]"
                  @click="toggleExpand(row.row_code, row.accounts)">
                  <td class="px-3 py-1.5 whitespace-nowrap">
                    <span v-if="row.accounts.length" class="inline-block mr-1 text-neutral-400 transition-transform"
                      :class="{ 'rotate-90': expandedRowCode === row.row_code }">▸</span>
                    {{ row.display_code }}
                  </td>
                  <td class="px-3 py-1.5" :style="{ paddingLeft: `${12 + row.level * 14}px` }">{{ row.label }}</td>
                  <td class="px-3 py-1.5 text-right font-mono">{{ fm(row.gross) }}</td>
                  <td class="px-3 py-1.5 text-right font-mono">{{ fm(row.correction) }}</td>
                  <td class="px-3 py-1.5 text-right font-mono">{{ fm(row.net) }}</td>
                  <td class="px-3 py-1.5 text-right font-mono">{{ fm(row.prev_net) }}</td>
                </tr>
                <tr v-if="expandedRowCode === row.row_code">
                  <td colspan="6" class="px-3 py-3 bg-neutral-50">
                    <div class="text-xs text-neutral-500 uppercase tracking-wide font-medium mb-2">
                      {{ t('accounting.balance_sheet.accounts_detail') }}
                    </div>
                    <table class="w-full max-w-2xl text-sm">
                      <thead class="text-xs text-neutral-500 uppercase tracking-wide">
                        <tr>
                          <th class="px-2 py-1 text-left font-medium">{{ t('accounting.balance_sheet.acc_col_account') }}</th>
                          <th class="px-2 py-1 text-right font-medium w-40">{{ t('accounting.balance_sheet.acc_col_amount') }}</th>
                        </tr>
                      </thead>
                      <tbody class="divide-y divide-neutral-200">
                        <tr v-for="acc in row.accounts" :key="`${acc.account_id}-${acc.target}`">
                          <td class="px-2 py-1">
                            <RouterLink :to="accountLink(acc)"
                              class="font-mono text-primary-600 hover:text-primary-700 hover:underline">
                              {{ acc.account_code }}
                            </RouterLink>
                            <span class="text-neutral-600 ml-1">{{ acc.name }}</span>
                            <span v-if="acc.target === 'correction'"
                              class="ml-1 text-xs px-1.5 py-0.5 rounded bg-neutral-100 text-neutral-500">
                              {{ t('accounting.balance_sheet.target_correction') }}
                            </span>
                          </td>
                          <td class="px-2 py-1 text-right font-mono">{{ fm(acc.amount) }}</td>
                        </tr>
                      </tbody>
                    </table>
                  </td>
                </tr>
              </template>
            </tbody>
          </table>
        </div>
      </div>

      <!-- PASIVA -->
      <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden mb-4">
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
              <tr>
                <th class="px-3 py-2 text-left font-medium w-24">{{ t('accounting.balance_sheet.col_code') }}</th>
                <th class="px-3 py-2 text-left font-medium">{{ t('accounting.balance_sheet.liabilities') }}</th>
                <th class="px-3 py-2 text-right font-medium w-32">{{ t('accounting.balance_sheet.col_amount') }}</th>
                <th class="px-3 py-2 text-right font-medium w-32">{{ t('accounting.balance_sheet.col_prev') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <template v-for="row in report.liabilities" :key="row.row_code">
                <tr :class="[
                    row.accounts.length ? 'cursor-pointer hover:bg-neutral-50' : '',
                    row.row_type !== 'detail' ? 'font-semibold' : '',
                  ]"
                  @click="toggleExpand(row.row_code, row.accounts)">
                  <td class="px-3 py-1.5 whitespace-nowrap">
                    <span v-if="row.accounts.length" class="inline-block mr-1 text-neutral-400 transition-transform"
                      :class="{ 'rotate-90': expandedRowCode === row.row_code }">▸</span>
                    {{ row.display_code }}
                  </td>
                  <td class="px-3 py-1.5" :style="{ paddingLeft: `${12 + row.level * 14}px` }">{{ row.label }}</td>
                  <td class="px-3 py-1.5 text-right font-mono">{{ fm(row.amount) }}</td>
                  <td class="px-3 py-1.5 text-right font-mono">{{ fm(row.prev_amount) }}</td>
                </tr>
                <tr v-if="expandedRowCode === row.row_code">
                  <td colspan="4" class="px-3 py-3 bg-neutral-50">
                    <div class="text-xs text-neutral-500 uppercase tracking-wide font-medium mb-2">
                      {{ t('accounting.balance_sheet.accounts_detail') }}
                    </div>
                    <table class="w-full max-w-2xl text-sm">
                      <thead class="text-xs text-neutral-500 uppercase tracking-wide">
                        <tr>
                          <th class="px-2 py-1 text-left font-medium">{{ t('accounting.balance_sheet.acc_col_account') }}</th>
                          <th class="px-2 py-1 text-right font-medium w-40">{{ t('accounting.balance_sheet.acc_col_amount') }}</th>
                        </tr>
                      </thead>
                      <tbody class="divide-y divide-neutral-200">
                        <tr v-for="acc in row.accounts" :key="`${acc.account_id}-${acc.target}`">
                          <td class="px-2 py-1">
                            <RouterLink :to="accountLink(acc)"
                              class="font-mono text-primary-600 hover:text-primary-700 hover:underline">
                              {{ acc.account_code }}
                            </RouterLink>
                            <span class="text-neutral-600 ml-1">{{ acc.name }}</span>
                          </td>
                          <td class="px-2 py-1 text-right font-mono">{{ fm(acc.amount) }}</td>
                        </tr>
                      </tbody>
                    </table>
                  </td>
                </tr>
              </template>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Kontrola -->
      <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-3 flex flex-wrap items-center gap-x-6 gap-y-2 text-sm">
        <div class="flex items-center gap-2">
          <span :class="report.checks.balanced ? 'text-success-600' : 'text-danger-500'" class="font-semibold">
            {{ report.checks.balanced ? '✓' : '✗' }}
          </span>
          {{ t('accounting.balance_sheet.check_balanced') }}
        </div>
        <div class="text-neutral-500">
          {{ t('accounting.balance_sheet.check_assets') }}:
          <span class="font-mono text-neutral-700">{{ fm(report.checks.assets_net) }}</span>
        </div>
        <div class="text-neutral-500">
          {{ t('accounting.balance_sheet.check_liabilities') }}:
          <span class="font-mono text-neutral-700">{{ fm(report.checks.liabilities_total) }}</span>
        </div>
      </div>
    </template>
  </div>
</template>

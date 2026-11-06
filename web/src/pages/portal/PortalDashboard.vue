<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { portalApi, type PortalSummary, type PortalKpiPeriod, type PortalKpiRow, type PortalAgingRow, type PortalVatStatus } from '@/api/portal'
import { useAuthStore } from '@/stores/auth'
import { useSupplierStore } from '@/stores/supplier'
import { useWindowRefresh } from '@/composables/useWindowRefresh'
import { formatMoney, formatDate, formatMonth } from '@/composables/useFormat'
import PortalMonthlyChart from '@/components/charts/PortalMonthlyChart.vue'
import EmptyState from '@/components/ui/EmptyState.vue'

const { t } = useI18n()
const auth = useAuthStore()
const supplierStore = useSupplierStore()

const data = ref<PortalSummary | null>(null)
const loading = ref(true)
const noCompany = ref(false)
const error = ref('')

async function load(quiet = false) {
  if (!quiet) loading.value = true
  error.value = ''
  noCompany.value = false
  try {
    data.value = await portalApi.summary()
  } catch (e: any) {
    if (quiet) throw e
    data.value = null
    // 403 = klient bez membershipu (fail-closed) → empty state bez retry spamu.
    if (e?.response?.status === 403) {
      noCompany.value = true
    } else {
      error.value = e?.response?.data?.error?.message || t('common.error')
    }
  } finally {
    loading.value = false
  }
}

onMounted(load)
// Multi-firma: přepnutí ve switcheru přenačte portál.
watch(() => supplierStore.currentSupplierId, () => { void load() })
useWindowRefresh(() => load(true), () => !loading.value)

const KPI_PERIODS: PortalKpiPeriod[] = ['current_month', 'last_month', 'ytd', 'prev_year_ytd', 'last_12m']

const kpiCards = computed(() =>
  KPI_PERIODS.map(period => ({
    period,
    label: t(`portal.period_${period}`),
    rows: (data.value?.kpi[period] ?? []) as PortalKpiRow[],
  })),
)

// Nová firma bez dat → empty state s CTA kartami.
const isEmpty = computed(() => {
  const d = data.value
  if (!d) return false
  const anyKpi = KPI_PERIODS.some(p => (d.kpi[p] ?? []).length > 0)
  return !anyKpi && d.monthly.length === 0
    && d.cashflow.receivables.length === 0 && d.cashflow.payables.length === 0
})

// ── Měsíční graf: fakturace vs. náklady per zvolená měna ──────────────
const chartCurrency = ref('')
watch(data, (d) => {
  if (!d) return
  const currencies = d.kpi.currencies ?? []
  if (!chartCurrency.value || !currencies.includes(chartCurrency.value)) {
    chartCurrency.value = currencies.includes('CZK') ? 'CZK' : (currencies[0] ?? 'CZK')
  }
})

const chartData = computed(() => {
  const d = data.value
  if (!d) return { labels: [] as string[], invoiced: [] as number[], costs: [] as number[] }
  const byMonth = new Map<string, { invoiced: number; costs: number }>()
  for (const row of d.monthly) {
    if (!row.period || row.currency !== chartCurrency.value) continue
    byMonth.set(row.period, { invoiced: row.invoiced, costs: row.costs })
  }
  const labels = Array.from(byMonth.keys()).sort()
  return {
    labels: labels.map(m => formatMonth(m)),
    invoiced: labels.map(m => byMonth.get(m)!.invoiced),
    costs: labels.map(m => byMonth.get(m)!.costs),
  }
})

// ── Aging (pohledávky/závazky) — kompaktní tabulky per bucket ─────────
const AGING_BUCKETS = ['not_due', 'overdue_30', 'overdue_60', 'overdue_90', 'overdue_90_plus'] as const

function agingRows(rows: PortalAgingRow[]) {
  return rows.slice().sort((a, b) =>
    a.currency.localeCompare(b.currency)
    || AGING_BUCKETS.indexOf(a.bucket) - AGING_BUCKETS.indexOf(b.bucket))
}

// ── DPH + termíny ─────────────────────────────────────────────────────
const vatDeadlines = computed(() => data.value?.vat.deadlines ?? [])

function vatPeriodLabel(status: PortalVatStatus): string {
  if (status.period_type === 'quarterly' && status.quarter !== null) {
    return t('crm.tax_calendar.period_quarter', { quarter: status.quarter, year: status.period.slice(0, 4) })
  }
  return status.period
}
</script>

<template>
  <div class="space-y-5">
    <div v-if="loading" class="text-center text-neutral-500 py-12">{{ t('common.loading') }}</div>

    <!-- 403 / bez membershipu — žádný retry spam -->
    <EmptyState v-else-if="noCompany" boxed accent="neutral" icon="lock" :title="t('portal.no_company')" />

    <div v-else-if="error" class="rounded-md bg-danger-50 border border-danger-500/40 px-3 py-2 text-sm text-danger-500">
      {{ error }}
    </div>

    <template v-else-if="data">
      <!-- ═══ Hlavička ═══ -->
      <div>
        <h1 class="text-2xl font-semibold">{{ data.company.name || t('portal.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5">
          {{ t('portal.title') }} · {{ formatDate(data.company.period.ytd_from) }} – {{ formatDate(data.company.period.today) }}
        </p>
        <p class="text-xs text-neutral-400 mt-1">{{ t('portal.disclaimer') }}</p>
      </div>

      <!-- ═══ Vyžádání chybějících dokladů (Fáze F, audit 2026-07) ═══ -->
      <RouterLink v-if="auth.canRead('documents.submit') && (data.document_requests_open?.open ?? 0) > 0" to="/portal/document-requests"
        class="flex items-center justify-between gap-3 rounded-lg border px-4 py-3 shadow-sm transition hover:bg-primary-50"
        :class="(data.document_requests_open?.overdue ?? 0) > 0 ? 'border-danger-500/50 bg-danger-50' : 'border-warning-500/40 bg-warning-50'">
        <span class="text-sm font-medium"
          :class="(data.document_requests_open?.overdue ?? 0) > 0 ? 'text-danger-500' : 'text-warning-600'">
          {{ t('portal.document_requests_banner', { n: data.document_requests_open?.open ?? 0 }) }}
        </span>
        <span class="text-xs font-medium underline">{{ t('portal.document_requests_go') }}</span>
      </RouterLink>

      <!-- ═══ Empty state (nová firma bez dat) ═══ -->
      <EmptyState v-if="isEmpty" boxed icon="chart" :title="t('portal.empty_title')" :message="t('portal.empty_text')" />

      <template v-else>
        <!-- ═══ KPI karty (5 období, per měna) + měsíční graf ═══ -->
        <!-- 5 karet na 4sloupcovém gridu → graf vyplní 3 prázdné buňky vedle 5. karty. -->
        <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4">
          <div v-for="card in kpiCards" :key="card.period"
            class="bg-surface border border-neutral-200 rounded-lg p-4 shadow-sm">
            <h3 class="text-xs font-semibold uppercase tracking-wide text-neutral-500 mb-2">{{ card.label }}</h3>
            <div v-if="card.rows.length === 0" class="text-sm text-neutral-400">—</div>
            <div v-for="row in card.rows" :key="row.currency" class="mb-2 last:mb-0">
              <div v-if="card.rows.length > 1" class="text-[10px] font-mono text-neutral-400">{{ row.currency }}</div>
              <dl class="space-y-0.5 text-sm">
                <div class="flex justify-between gap-2">
                  <dt class="text-neutral-500">{{ t('portal.kpi_invoiced') }}</dt>
                  <dd class="font-mono font-semibold">{{ formatMoney(row.invoiced, row.currency) }}</dd>
                </div>
                <div class="flex justify-between gap-2">
                  <dt class="text-neutral-500">{{ t('portal.kpi_costs') }}</dt>
                  <dd class="font-mono">{{ formatMoney(row.costs, row.currency) }}</dd>
                </div>
                <div class="flex justify-between gap-2 border-t border-neutral-100 pt-0.5">
                  <dt class="text-neutral-500">{{ t('portal.kpi_margin') }}</dt>
                  <dd class="font-mono font-medium" :class="row.profit < 0 ? 'text-danger-500' : 'text-success-600'">
                    {{ formatMoney(row.profit, row.currency) }}
                  </dd>
                </div>
              </dl>
            </div>
          </div>

          <!-- Měsíční graf — vyplní zbytek posledního řádku vedle 5. karty. -->
          <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-4 sm:col-span-2 xl:col-span-3">
            <div class="flex items-center justify-between gap-3 mb-3 flex-wrap">
              <h3 class="text-sm font-medium text-neutral-700">
                {{ t('portal.kpi_invoiced') }} / {{ t('portal.kpi_costs') }}
              </h3>
              <select v-if="(data.kpi.currencies ?? []).length > 1" v-model="chartCurrency"
                class="h-8 px-2 border border-neutral-300 rounded-md bg-surface text-xs">
                <option v-for="c in data.kpi.currencies" :key="c" :value="c">{{ c }}</option>
              </select>
            </div>
            <div class="overflow-x-auto">
              <PortalMonthlyChart
                :labels="chartData.labels"
                :invoiced="chartData.invoiced"
                :costs="chartData.costs"
                :currency="chartCurrency"
                :invoiced-label="t('portal.kpi_invoiced')"
                :costs-label="t('portal.kpi_costs')" />
            </div>
          </div>
        </div>

        <!-- ═══ Cashflow: aging + forecast ═══ -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
          <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-4">
            <h3 class="text-sm font-medium text-neutral-700 mb-2">{{ t('portal.receivables') }}</h3>
            <p v-if="data.cashflow.receivables.length === 0" class="text-sm text-neutral-400">—</p>
            <table v-else class="w-full text-sm">
              <tbody class="divide-y divide-neutral-100">
                <tr v-for="r in agingRows(data.cashflow.receivables)" :key="`${r.currency}-${r.bucket}`">
                  <td class="py-1 text-neutral-500">{{ t(`crm.aging.bucket.${r.bucket}`) }}</td>
                  <td class="py-1 text-right text-xs text-neutral-400">{{ r.count }}×</td>
                  <td class="py-1 text-right font-mono">{{ formatMoney(r.total, r.currency) }}</td>
                </tr>
              </tbody>
            </table>
          </div>
          <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-4">
            <h3 class="text-sm font-medium text-neutral-700 mb-2">{{ t('portal.payables') }}</h3>
            <p v-if="data.cashflow.payables.length === 0" class="text-sm text-neutral-400">—</p>
            <table v-else class="w-full text-sm">
              <tbody class="divide-y divide-neutral-100">
                <tr v-for="r in agingRows(data.cashflow.payables)" :key="`${r.currency}-${r.bucket}`">
                  <td class="py-1 text-neutral-500">{{ t(`crm.aging.bucket.${r.bucket}`) }}</td>
                  <td class="py-1 text-right text-xs text-neutral-400">{{ r.count }}×</td>
                  <td class="py-1 text-right font-mono">{{ formatMoney(r.total, r.currency) }}</td>
                </tr>
              </tbody>
            </table>
          </div>
          <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-4">
            <h3 class="text-sm font-medium text-neutral-700 mb-2">{{ t('portal.forecast') }}</h3>
            <table class="w-full text-sm">
              <tbody class="divide-y divide-neutral-100">
                <tr v-for="w in data.cashflow.forecast.weeks" :key="w.week_start">
                  <td class="py-1 text-xs text-neutral-500 whitespace-nowrap">
                    {{ formatDate(w.week_start) }} – {{ formatDate(w.week_end) }}
                  </td>
                  <td class="py-1 text-right font-mono" :class="w.net < 0 ? 'text-danger-500' : 'text-success-600'">
                    {{ formatMoney(w.net, data.cashflow.forecast.currency) }}
                  </td>
                </tr>
              </tbody>
              <tfoot>
                <tr class="border-t border-neutral-200">
                  <td class="py-1.5 text-xs font-medium text-neutral-600">Σ</td>
                  <td class="py-1.5 text-right font-mono font-semibold"
                    :class="data.cashflow.forecast.total_net < 0 ? 'text-danger-500' : 'text-success-600'">
                    {{ formatMoney(data.cashflow.forecast.total_net, data.cashflow.forecast.currency) }}
                  </td>
                </tr>
              </tfoot>
            </table>
          </div>
        </div>

        <!-- ═══ DPH stav + daňové termíny ═══ -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
          <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-4">
            <h3 class="text-sm font-medium text-neutral-700 mb-2">{{ t('portal.vat_title') }}</h3>
            <p v-if="!data.vat.is_vat_payer" class="text-sm text-neutral-400">{{ t('portal.vat_not_payer') }}</p>
            <p v-else-if="!data.vat.status" class="text-sm text-neutral-400">—</p>
            <dl v-else class="space-y-1 text-sm">
              <div class="flex justify-between gap-2">
                <dt class="text-neutral-500">{{ t('portal.vat_period') }}</dt>
                <dd class="font-mono">{{ vatPeriodLabel(data.vat.status) }}</dd>
              </div>
              <div class="flex justify-between gap-2">
                <dt class="text-neutral-500">{{ t('reports.dph.vat_output') }}</dt>
                <dd class="font-mono">{{ formatMoney(data.vat.status.vat_output, 'CZK') }}</dd>
              </div>
              <div class="flex justify-between gap-2">
                <dt class="text-neutral-500">{{ t('reports.dph.vat_input') }}</dt>
                <dd class="font-mono">{{ formatMoney(data.vat.status.vat_input, 'CZK') }}</dd>
              </div>
              <div class="flex justify-between gap-2 border-t border-neutral-100 pt-1">
                <dt class="text-neutral-500 font-medium">
                  {{ data.vat.status.is_excess_deduction ? t('reports.dph.excess_deduction') : t('reports.dph.tax_due') }}
                </dt>
                <dd class="font-mono font-semibold">{{ formatMoney(Math.abs(data.vat.status.tax_due), 'CZK') }}</dd>
              </div>
              <div v-if="data.vat.status.submission_deadline" class="flex justify-between gap-2">
                <dt class="text-neutral-500">{{ t('portal.vat_deadline') }}</dt>
                <dd class="font-mono">{{ formatDate(data.vat.status.submission_deadline) }}</dd>
              </div>
            </dl>
          </div>
          <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-4">
            <h3 class="text-sm font-medium text-neutral-700 mb-2">{{ t('portal.deadlines_title') }}</h3>
            <EmptyState v-if="vatDeadlines.length === 0" dense icon="bell" :title="t('portal.deadlines_none')" />
            <ul v-else class="space-y-2">
              <li v-for="d in vatDeadlines" :key="d.type + d.title"
                class="flex flex-col gap-0.5 rounded-md border px-3 py-2 text-sm"
                :class="d.severity === 'high'
                  ? 'bg-danger-50 border-danger-500/40 text-danger-500'
                  : 'bg-warning-50 border-warning-500/40 text-warning-600'">
                <span class="font-medium">{{ d.title }}</span>
                <span class="text-xs opacity-90">{{ d.hint }}</span>
              </li>
            </ul>
          </div>
        </div>
      </template>

      <!-- ═══ Rychlé akce — 3 CTA karty (min. 44px touch target) ═══ -->
      <div v-if="auth.canWrite('dashboard')" class="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <RouterLink to="/invoices/new"
          class="flex items-center gap-3 min-h-[44px] bg-surface border border-neutral-200 rounded-lg shadow-sm px-4 py-3 hover:border-primary-400 hover:bg-primary-50 transition">
          <svg class="w-5 h-5 text-primary-600 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5.586a1 1 0 0 1 .707.293l5.414 5.414a1 1 0 0 1 .293.707V19a2 2 0 0 1-2 2z" />
          </svg>
          <span class="text-sm font-medium text-neutral-700">{{ t('portal.quick_invoice') }}</span>
        </RouterLink>
        <RouterLink to="/purchase-invoices/new"
          class="flex items-center gap-3 min-h-[44px] bg-surface border border-neutral-200 rounded-lg shadow-sm px-4 py-3 hover:border-primary-400 hover:bg-primary-50 transition">
          <svg class="w-5 h-5 text-primary-600 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 1 0 0 4 2 2 0 0 0 0-4zm-8 2a2 2 0 1 1-4 0 2 2 0 0 1 4 0z" />
          </svg>
          <span class="text-sm font-medium text-neutral-700">{{ t('portal.quick_purchase') }}</span>
        </RouterLink>
        <RouterLink to="/clients/new"
          class="flex items-center gap-3 min-h-[44px] bg-surface border border-neutral-200 rounded-lg shadow-sm px-4 py-3 hover:border-primary-400 hover:bg-primary-50 transition">
          <svg class="w-5 h-5 text-primary-600 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a4 4 0 0 0-3-3.87M9 20H4v-2a3 3 0 0 1 5.356-1.857M15 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0z" />
          </svg>
          <span class="text-sm font-medium text-neutral-700">{{ t('portal.quick_contact') }}</span>
        </RouterLink>
      </div>
    </template>
  </div>
</template>

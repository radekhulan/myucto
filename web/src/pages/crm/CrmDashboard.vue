<script setup lang="ts">
import { ref, onMounted, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { crmApi, type CrmKpi, type CrmOverview, type CrmMonthlyRow, type TopClient, type TopVendor,
  type AgingBucket, type DsoResult, type PunctualityResult, type ConcentrationResult,
  type VendorConcentrationResult,
  type ExpenseCategoryRow, type RevenueCategoryRow, type ChurnRiskClient,
  type CashFlowResult, type LateRiskClient,
  type ReminderEffectiveness, type PaymentTimeHistogram, type CrmYearlyRow } from '@/api/crm'
import { formatDate, formatMoney, formatNumber, formatPercent } from '@/composables/useFormat'
import { apiErrorMessage } from '@/api/errors'
import { ICONS, btnOutline } from '@/components/ui/buttonStyles'
import RevenueChart from '@/components/charts/RevenueChart.vue'
import CumulativeYtdChart from '@/components/charts/CumulativeYtdChart.vue'
import EmptyState from '@/components/ui/EmptyState.vue'

const { t } = useI18n()
const auth = useAuthStore()
const toast = useToast()

const overview = ref<CrmOverview | null>(null)
const monthly = ref<CrmMonthlyRow[]>([])
// Stabilní 24měsíční řada pro grafy zisku (12 m + loňské okno) — nezávislá na přepínači období.
const monthly24 = ref<CrmMonthlyRow[]>([])
const yearly = ref<CrmYearlyRow[]>([])
const topClients = ref<TopClient[]>([])
const topVendors = ref<TopVendor[]>([])
const agingRecv = ref<AgingBucket[]>([])
const agingPay  = ref<AgingBucket[]>([])
const dso = ref<DsoResult | null>(null)
const punctuality = ref<PunctualityResult | null>(null)
const concentration = ref<ConcentrationResult | null>(null)
const vendorConcentration = ref<VendorConcentrationResult | null>(null)
const dpo = ref<DsoResult | null>(null)
const expenses = ref<ExpenseCategoryRow[]>([])
const revenues = ref<RevenueCategoryRow[]>([])
const churn = ref<ChurnRiskClient[]>([])
const cashFlow = ref<CashFlowResult | null>(null)
const lateRisk = ref<LateRiskClient[]>([])
const reminderEff = ref<ReminderEffectiveness | null>(null)
const paymentHist = ref<PaymentTimeHistogram | null>(null)
const loading = ref(true)
const recomputing = ref(false)

// Filters
const periodMonths = ref(12)
const currencyFilter = ref<string>('')

// Sentinel pro volbu „Vše" — agreguje všechny měny přepočtené na CZK (*_czk pole).
const ALL_CURRENCIES = '__ALL__'
// Měna pro formátování částek: u „Vše" zobrazujeme v CZK (přepočet), jinak nativní.
const displayCurrency = computed(() => currencyFilter.value === ALL_CURRENCIES ? 'CZK' : currencyFilter.value)

const availableCurrencies = computed(() => overview.value?.currencies || [])

async function loadAll() {
  loading.value = true
  try {
    const ov = await crmApi.overview()
    if (!currencyFilter.value && ov.currencies.length > 0) {
      currencyFilter.value = ov.currencies.length > 1 ? ALL_CURRENCIES : ov.currencies[0]
    }
    // U „Vše" neposíláme měnu (cur=undefined) → endpointy vrátí všechny měny a
    // agregaci do CZK uděláme klientsky přes *_czk pole.
    const cur = (currencyFilter.value && currencyFilter.value !== ALL_CURRENCIES) ? currencyFilter.value : undefined
    const [mo, yr, tc, tv, ar, ap, d, p, conc, vc, dp, exp, rev, ch, cf, lr, re, ph, m24] = await Promise.all([
      crmApi.monthly(periodMonths.value, cur),
      crmApi.yearly(cur),
      crmApi.topClients(periodMonths.value, 10, cur),
      crmApi.topVendors(periodMonths.value, 10, cur),
      crmApi.agingReceivables(),
      crmApi.agingPayables(),
      crmApi.dso(periodMonths.value),
      crmApi.punctuality(periodMonths.value),
      crmApi.concentration(periodMonths.value, cur),
      crmApi.vendorConcentration(periodMonths.value, cur),
      crmApi.dpo(periodMonths.value),
      crmApi.expenseBreakdown(periodMonths.value, cur),
      crmApi.revenueBreakdown(periodMonths.value, cur),
      crmApi.churnRisk(60, 10),
      crmApi.cashFlowForecast(4, cur || 'CZK'),
      crmApi.lateRisk(10),
      crmApi.reminderEffectiveness(periodMonths.value),
      crmApi.paymentTimeHistogram(periodMonths.value),
      crmApi.monthly(24, cur),
    ])
    overview.value = ov
    monthly.value = mo
    yearly.value = yr
    topClients.value = tc
    topVendors.value = tv
    agingRecv.value = ar
    agingPay.value  = ap
    dso.value = d
    punctuality.value = p
    concentration.value = conc
    vendorConcentration.value = vc
    dpo.value = dp
    expenses.value = exp
    revenues.value = rev
    churn.value = ch
    cashFlow.value = cf
    lateRisk.value = lr
    reminderEff.value = re
    paymentHist.value = ph
    monthly24.value = m24
  } catch (e) {
    toast.error(apiErrorMessage(e))
  } finally {
    loading.value = false
  }
}

async function recompute() {
  if (recomputing.value) return
  recomputing.value = true
  try {
    const r = await crmApi.recompute()
    toast.success(t('crm.recompute_done', { ms: r.elapsed_ms }))
    await loadAll()
  } catch (e) {
    toast.error(apiErrorMessage(e))
  } finally {
    recomputing.value = false
  }
}

// Agregace per-currency KPI řádků do jednoho CZK řádku (volba „Vše"). Sčítá *_czk
// pole (revenue_czk/costs_czk…), takže míchání měn dává smysl (vše přepočteno na CZK).
function aggregateCzk(rows: CrmKpi[]): CrmKpi {
  const a = rows.reduce((acc, r) => {
    acc.revenue     += r.revenue_czk
    acc.revenue_net += r.revenue_net_czk
    acc.costs       += r.costs_czk
    acc.costs_net   += r.costs_net_czk
    acc.invoice_count  += r.invoice_count
    acc.purchase_count += r.purchase_count
    return acc
  }, { revenue: 0, revenue_net: 0, costs: 0, costs_net: 0, invoice_count: 0, purchase_count: 0 })
  return {
    currency: 'CZK',
    revenue: a.revenue, revenue_net: a.revenue_net, costs: a.costs, costs_net: a.costs_net,
    profit: a.revenue - a.costs,
    revenue_czk: a.revenue, revenue_net_czk: a.revenue_net, costs_czk: a.costs, costs_net_czk: a.costs_net,
    profit_czk: a.revenue - a.costs,
    invoice_count: a.invoice_count, purchase_count: a.purchase_count,
    vat_output: 0, vat_input: 0,
  }
}

// Derived: KPI řádek pro vybranou měnu (nebo CZK-agregát u „Vše").
// NEPADÁME na [0] fallback — když zvolená měna nemá za dané období data, vrať null
// (KPI dlaždice ukáže 0 ve zvolené měně). Dřív fallback na current_month[0] zobrazil
// částku JINÉ měny (typicky CZK, řazeno currency ASC) pod labelem zvolené měny —
// "579 481,93 USD" místo 0 USD, když USD nemá v aktuálním měsíci žádnou fakturu.
// Vybere KPI řádek pro zvolenou měnu (nebo CZK-agregát u „Vše").
// NEPADÁME na [0] fallback — když zvolená měna nemá za období data, vrať null
// (KPI dlaždice ukáže 0 ve zvolené měně). Dřív fallback na [0] zobrazil částku JINÉ
// měny (řazeno currency ASC) pod labelem zvolené měny.
function pickKpi(rows: CrmKpi[] | undefined | null): CrmKpi | null {
  if (!rows) return null
  if (currencyFilter.value === ALL_CURRENCIES) return aggregateCzk(rows)
  return rows.find(k => k.currency === currencyFilter.value) || null
}
const currentMonthKpi = computed(() => pickKpi(overview.value?.current_month))
const lastMonthKpi    = computed(() => pickKpi(overview.value?.last_month))
const ytdKpi          = computed(() => pickKpi(overview.value?.ytd))
const last12mKpi      = computed(() => pickKpi(overview.value?.last_12m))
const prev12mKpi      = computed(() => pickKpi(overview.value?.prev_12m))
const prevYearFullKpi = computed(() => pickKpi(overview.value?.prev_year_full))
const prevYearYtdKpi  = computed(() => pickKpi(overview.value?.prev_year_ytd))

// Dopředné tržby aktuálního měsíce (koncepty + nespárované proformy) — zatím nevystavené,
// proto NEjsou v currentMonthKpi.revenue. U „Vše" sčítáme CZK přepočet, jinak nativní měnu.
const pipelineThisMonth = computed(() => {
  const rows = overview.value?.current_month_pipeline || []
  if (currencyFilter.value === ALL_CURRENCIES) {
    return rows.reduce((a, r) => {
      a.draft += r.draft_revenue_czk; a.draftCount += r.draft_count
      a.proforma += r.proforma_revenue_czk; a.proformaCount += r.proforma_count
      return a
    }, { draft: 0, draftCount: 0, proforma: 0, proformaCount: 0 })
  }
  const row = rows.find(r => r.currency === currencyFilter.value)
  return {
    draft: row?.draft_revenue || 0,
    draftCount: row?.draft_count || 0,
    proforma: row?.proforma_revenue || 0,
    proformaCount: row?.proforma_count || 0,
  }
})
// Očekávané tržby = vystavené + koncepty + nespárované proformy.
const expectedThisMonth = computed(() =>
  (currentMonthKpi.value?.revenue || 0) + pipelineThisMonth.value.draft + pipelineThisMonth.value.proforma)
const hasPipeline = computed(() => pipelineThisMonth.value.draft !== 0 || pipelineThisMonth.value.proforma !== 0)
// Očekávaný zisk = očekávané tržby − náklady (symetrie s dlaždicí Tržby). Náklady jsou
// jen z ostrých dokladů (koncepty/proformy nákladů do pipeline nevstupují), takže
// expectedProfit = vystavený zisk + koncepty + nespárované proformy.
const expectedProfitThisMonth = computed(() =>
  expectedThisMonth.value - (currentMonthKpi.value?.costs || 0))

// Trend % vs last month
function trendPct(current: number, last: number): number {
  if (last === 0) return current > 0 ? 100 : 0
  return Math.round(((current - last) / Math.abs(last)) * 100)
}

// Meziroční/období delta v %. null když není s čím srovnávat (base i current 0).
function deltaPct(current?: number, base?: number): number | null {
  const c = current ?? 0, b = base ?? 0
  if (b === 0 && c === 0) return null
  return trendPct(c, b)
}

// Marže v % (zisk / tržby). null když nulové tržby.
function marginPct(profit?: number, revenue?: number): number | null {
  if (!revenue || revenue === 0) return null
  return ((profit ?? 0) / revenue) * 100
}

// YoY badge: směr + barva (upGood=true → růst zelený; u nákladů false → růst červený).
function yoy(current?: number, base?: number, upGood = true) {
  const pct = deltaPct(current, base)
  if (pct === null) return { show: false, cls: '', arrow: '', abs: 0 }
  const positive = pct >= 0
  const good = upGood ? positive : !positive
  return {
    show: true,
    cls: good ? 'text-success-600' : 'text-danger-500',
    arrow: positive ? '▲' : '▼',
    abs: Math.abs(pct),
  }
}

// Předpočítané YoY odznaky pro karty (YTD vs stejné okno loni, 12m vs předch. 12m).
const rev12Yoy     = computed(() => yoy(last12mKpi.value?.revenue, prev12mKpi.value?.revenue, true))
const revYtdYoy    = computed(() => yoy(ytdKpi.value?.revenue, prevYearYtdKpi.value?.revenue, true))
const cost12Yoy    = computed(() => yoy(last12mKpi.value?.costs, prev12mKpi.value?.costs, false))
const costYtdYoy   = computed(() => yoy(ytdKpi.value?.costs, prevYearYtdKpi.value?.costs, false))
const profit12Yoy  = computed(() => yoy(last12mKpi.value?.profit, prev12mKpi.value?.profit, true))
const profitYtdYoy = computed(() => yoy(ytdKpi.value?.profit, prevYearYtdKpi.value?.profit, true))

// Srovnávací tabulka období (tržby/náklady/zisk/marže) — nezávislá na přepínači období.
const comparisonRows = computed(() => {
  const mk = (key: string, label: string, k: CrmKpi | null) => ({
    key,
    label,
    revenue: k?.revenue ?? 0,
    costs: k?.costs ?? 0,
    profit: k?.profit ?? 0,
    margin: marginPct(k?.profit, k?.revenue),
  })
  return [
    mk('this_month', t('crm.compare.this_month'), currentMonthKpi.value),
    mk('last_month', t('crm.compare.last_month'), lastMonthKpi.value),
    mk('last_12m',   t('crm.compare.last_12m'),   last12mKpi.value),
    mk('ytd',        t('crm.compare.ytd'),        ytdKpi.value),
    mk('prev_year',  t('crm.compare.prev_year'),  prevYearFullKpi.value),
  ]
})

// ─── Proklik z tabulek do seznamů faktur (URL filtrační parametry year/month/from/to) ───
// Seznamy /invoices i /purchase-invoices čtou stejné query klíče (viz loadFiltersFromQuery).
function pad2(n: number): string { return String(n).padStart(2, '0') }
function ymd(d: Date): string { return `${d.getFullYear()}-${pad2(d.getMonth() + 1)}-${pad2(d.getDate())}` }

/** "2026-05" → { year:'2026', month:'5' } */
function monthQuery(period: string): Record<string, string> {
  const [y, m] = period.split('-')
  return { year: y, month: String(Number(m)) }
}

/** Query pro řádek srovnávací tabulky (období → year/month nebo from/to). */
function comparePeriodQuery(key: string): Record<string, string> {
  const now = new Date()
  const y = now.getFullYear()
  switch (key) {
    case 'this_month': return { year: String(y), month: String(now.getMonth() + 1) }
    case 'last_month': {
      const d = new Date(y, now.getMonth() - 1, 1)
      return { year: String(d.getFullYear()), month: String(d.getMonth() + 1) }
    }
    case 'last_12m': {
      const from = new Date(y, now.getMonth() - 11, 1)
      const to = new Date(y, now.getMonth() + 1, 0) // poslední den aktuálního měsíce
      return { from: ymd(from), to: ymd(to) }
    }
    case 'ytd': return { from: `${y}-01-01`, to: ymd(now) }
    case 'prev_year': return { year: String(y - 1) }
    default: return {}
  }
}

// Data pro grafy zisku: 12 měsíců končících aktuálním + odpovídající okno o rok dříve.
// Stabilní (z 24měsíční řady monthly24), nezávislé na přepínači období. Záporný zisk povolen.
// U „Vše" agregujeme přes profit_czk (CZK), jinak nativní profit zvolené měny.
const profitChartData = computed(() => {
  const map = new Map<string, number>()
  for (const m of monthly24.value) {
    const val = currencyFilter.value === ALL_CURRENCIES ? m.profit_czk : m.profit
    map.set(m.period, (map.get(m.period) || 0) + val)
  }
  const now = new Date()
  const months: { ym: string; total: number }[] = []
  const prevYear: { ym: string; total: number }[] = []
  for (let i = 11; i >= 0; i--) {
    const d = new Date(now.getFullYear(), now.getMonth() - i, 1)
    const ym = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`
    months.push({ ym, total: map.get(ym) || 0 })
    const dp = new Date(d.getFullYear() - 1, d.getMonth(), 1)
    const ymp = `${dp.getFullYear()}-${String(dp.getMonth() + 1).padStart(2, '0')}`
    prevYear.push({ ym: ymp, total: map.get(ymp) || 0 })
  }
  return { months, prevYear }
})

// Měsíční řádky k zobrazení: u „Vše" agregujeme všechny měny per období do CZK
// (přes *_czk pole), jinak vracíme server-filtrované řádky vybrané měny.
const monthlyDisplay = computed<CrmMonthlyRow[]>(() => {
  if (currencyFilter.value !== ALL_CURRENCIES) return monthly.value
  const byPeriod = new Map<string, CrmMonthlyRow>()
  for (const m of monthly.value) {
    let e = byPeriod.get(m.period)
    if (!e) {
      e = { period: m.period, currency: 'CZK', revenue: 0, revenue_net: 0, costs: 0, costs_net: 0, profit: 0,
            revenue_czk: 0, revenue_net_czk: 0, costs_czk: 0, costs_net_czk: 0, profit_czk: 0,
            invoice_count: 0, purchase_count: 0, vat_output: 0, vat_input: 0 }
      byPeriod.set(m.period, e)
    }
    e.revenue     += m.revenue_czk
    e.revenue_net += m.revenue_net_czk
    e.costs       += m.costs_czk
    e.costs_net   += m.costs_net_czk
    e.invoice_count  += m.invoice_count
    e.purchase_count += m.purchase_count
    e.profit = e.revenue - e.costs
  }
  return Array.from(byPeriod.values()).sort((a, b) => a.period.localeCompare(b.period))
})

// Chart max — pro proportional bar widths
const chartMaxValue = computed(() => {
  let max = 0
  for (const m of monthlyDisplay.value) {
    if (m.revenue > max) max = m.revenue
    if (m.costs > max) max = m.costs
  }
  return max
})

function barWidthPct(value: number): number {
  if (chartMaxValue.value === 0) return 0
  return Math.round((value / chartMaxValue.value) * 100)
}

// Aging buckets pro vybranou měnu. Aging nemá CZK přepočet (nativní částky per měna),
// takže u „Vše" zobrazíme CZK bázi (displayCurrency='CZK') — nikdy nepřeznačíme měnu.
const agingForCurrency = computed(() =>
  agingRecv.value.filter(b => b.currency === displayCurrency.value)
)
const agingPayForCurrency = computed(() =>
  agingPay.value.filter(b => b.currency === displayCurrency.value)
)
const agingTotal = computed(() => agingForCurrency.value.reduce((s, b) => s + b.total, 0))
const agingPayTotal = computed(() => agingPayForCurrency.value.reduce((s, b) => s + b.total, 0))

function agingPct(bucket: AgingBucket, total: number): number {
  if (total === 0) return 0
  return Math.round((bucket.total / total) * 100)
}

function agingBucketColor(bucket: string): string {
  switch (bucket) {
    case 'not_due':         return 'bg-success-500'
    case 'overdue_30':      return 'bg-warning-400'
    case 'overdue_60':      return 'bg-warning-500'
    case 'overdue_90':      return 'bg-danger-400'
    case 'overdue_90_plus': return 'bg-danger-600'
    default:                return 'bg-neutral-400'
  }
}

function riskColor(level: string): string {
  return level === 'high' ? 'text-danger-500' : level === 'medium' ? 'text-warning-600' : 'text-success-600'
}

function formatMonthLabel(period: string): string {
  // "2026-05" → "kvě 26" (cz) nebo "May 26"
  const [y, m] = period.split('-')
  if (!y || !m) return period
  const date = new Date(Number(y), Number(m) - 1, 1)
  return date.toLocaleDateString('cs-CZ', { month: 'short', year: '2-digit' })
}

// Pracovní kapitálový cyklus = DSO − DPO. Kladné = financuješ provoz (inkasuješ pomaleji než platíš),
// záporné = dodavatelé tě financují (platíš později, než ti platí klienti).
const wcCycle = computed<number | null>(() => {
  if (!dso.value || !dpo.value) return null
  if (dso.value.sample_size === 0 && dpo.value.sample_size === 0) return null
  return Math.round((dso.value.avg_days - dpo.value.avg_days) * 10) / 10
})

/** Krátký štítek zvoleného analytického období pro hlavičky sekcí (např. "12 m"). */
const periodChip = computed(() => t('crm.period_chip', { n: periodMonths.value }))

watch([periodMonths, currencyFilter], (_values, previous) => {
  if (previous[1] && currencyFilter.value) loadAll()
})

onMounted(loadAll)
</script>

<template>
  <div>
    <!-- Topbar -->
    <div class="flex items-center justify-between mb-4 gap-3 flex-wrap">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('crm.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5">{{ t('crm.subtitle') }}</p>
      </div>
      <div class="flex items-center gap-2 flex-wrap">
        <select v-if="availableCurrencies.length > 1" v-model="currencyFilter" class="h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
          <option v-for="c in availableCurrencies" :key="c" :value="c">{{ c }}</option>
          <option :value="ALL_CURRENCIES">{{ t('crm.all_currencies') }}</option>
        </select>
        <button
          v-if="auth.canWrite('dashboard.portfolio')"
          type="button" @click="recompute" :disabled="recomputing"
          :title="t('crm.recompute_hint')"
          :class="btnOutline('neutral')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.cycle" /></svg>
          {{ recomputing ? '…' : t('crm.recompute') }}
        </button>
      </div>
    </div>

    <div v-if="loading" class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-8 text-center text-neutral-400">
      {{ t('common.loading') }}…
    </div>

    <EmptyState v-else-if="!overview || overview.currencies.length === 0"
      icon="chart" :title="t('crm.no_data')" :message="t('crm.no_data_hint')"
      :cta="auth.canWrite('dashboard.portfolio') ? t('crm.recompute_now') : undefined"
      ctaIcon="cycle" boxed @action="recompute" />

    <div v-else class="space-y-4">
      <!-- Akce pro tebe se přesunuly na Přehled (Dashboard) — viz ActionItemsWidget. -->

      <!-- ═══ Headline KPI — aktuální měsíc + YTD (nezávislé na zvoleném období) ═══ -->
      <div>
        <h2 class="text-sm font-semibold uppercase tracking-wide text-neutral-600 mb-2">{{ t('crm.kpi_section') }}</h2>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <!-- Revenue -->
        <div @click="$router.push('/stats')"
          class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-5 cursor-pointer hover:border-primary-300 transition">
          <div class="flex items-center justify-between mb-1">
            <span class="text-xs uppercase tracking-wide text-neutral-500 font-medium">{{ t('crm.kpi.revenue') }}</span>
            <svg class="w-5 h-5 text-success-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M17 9V7a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h2m2 4h10a2 2 0 0 0 2-2v-6a2 2 0 0 0-2-2H9a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2zm7-5a2 2 0 1 1-4 0 2 2 0 0 1 4 0z"/>
            </svg>
          </div>
          <div class="text-2xl font-bold text-neutral-900 font-mono">
            {{ formatMoney(expectedThisMonth, displayCurrency) }}
          </div>
          <div class="text-xs text-neutral-500 mt-1">
            {{ t('crm.kpi.this_month') }}<span v-if="hasPipeline" class="text-neutral-400"> · {{ t('crm.kpi.expected') }}</span>
            <span v-if="lastMonthKpi" class="ml-2"
              :class="trendPct(expectedThisMonth, lastMonthKpi.revenue) >= 0 ? 'text-success-600' : 'text-danger-500'">
              {{ trendPct(expectedThisMonth, lastMonthKpi.revenue) >= 0 ? '▲' : '▼' }}
              {{ formatPercent(Math.abs(trendPct(expectedThisMonth, lastMonthKpi.revenue)), 0) }}
            </span>
          </div>
          <!-- Rozpad očekávaných tržeb: vystaveno + koncepty + nespárované proformy (jen když jsou dopředné složky) -->
          <div v-if="hasPipeline" class="text-xs mt-3 pt-2 border-t border-neutral-100 space-y-0.5" :title="t('crm.kpi.pipeline_hint')">
            <div class="flex items-center justify-between gap-2 text-neutral-500">
              <span>{{ t('crm.kpi.issued') }} <span class="text-neutral-400">({{ currentMonthKpi?.invoice_count || 0 }} {{ t('crm.kpi.invoices') }})</span></span>
              <span class="font-mono">{{ formatMoney(currentMonthKpi?.revenue || 0, displayCurrency) }}</span>
            </div>
            <div v-if="pipelineThisMonth.draft" class="flex items-center justify-between gap-2 text-neutral-500">
              <span>+ {{ t('crm.kpi.drafts') }} <span class="text-neutral-400">({{ pipelineThisMonth.draftCount }})</span></span>
              <span class="font-mono">{{ formatMoney(pipelineThisMonth.draft, displayCurrency) }}</span>
            </div>
            <div v-if="pipelineThisMonth.proforma" class="flex items-center justify-between gap-2 text-neutral-500">
              <span>+ {{ t('crm.kpi.proformas') }} <span class="text-neutral-400">({{ pipelineThisMonth.proformaCount }})</span></span>
              <span class="font-mono">{{ formatMoney(pipelineThisMonth.proforma, displayCurrency) }}</span>
            </div>
          </div>
          <div class="text-xs text-neutral-400 mt-3 pt-2 border-t border-neutral-100 space-y-0.5">
            <div class="flex items-center justify-between gap-2" :title="t('crm.kpi.yoy_12m_hint')">
              <span>{{ t('crm.kpi.last_12m') }}</span>
              <span class="font-mono text-neutral-600">
                {{ formatMoney(last12mKpi?.revenue || 0, displayCurrency) }}
                <span v-if="rev12Yoy.show" class="ml-1" :class="rev12Yoy.cls">{{ rev12Yoy.arrow }}{{ formatPercent(rev12Yoy.abs, 0) }}</span>
              </span>
            </div>
            <div class="flex items-center justify-between gap-2" :title="t('crm.kpi.yoy_ytd_hint')">
              <span>YTD</span>
              <span class="font-mono text-neutral-600">
                {{ formatMoney(ytdKpi?.revenue || 0, displayCurrency) }}
                <span v-if="revYtdYoy.show" class="ml-1" :class="revYtdYoy.cls">{{ revYtdYoy.arrow }}{{ formatPercent(revYtdYoy.abs, 0) }}</span>
              </span>
            </div>
            <div v-if="!hasPipeline">{{ currentMonthKpi?.invoice_count || 0 }} {{ t('crm.kpi.invoices') }}</div>
          </div>
        </div>

        <!-- Costs -->
        <div @click="$router.push('/purchase-stats')"
          class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-5 cursor-pointer hover:border-primary-300 transition">
          <div class="flex items-center justify-between mb-1">
            <span class="text-xs uppercase tracking-wide text-neutral-500 font-medium">{{ t('crm.kpi.costs') }}</span>
            <svg class="w-5 h-5 text-danger-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2M9 5a2 2 0 0 0 2 2h2a2 2 0 0 0 2-2M9 5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2"/>
            </svg>
          </div>
          <div class="text-2xl font-bold text-neutral-900 font-mono">
            {{ formatMoney(currentMonthKpi?.costs || 0, displayCurrency) }}
          </div>
          <div class="text-xs text-neutral-500 mt-1">
            {{ t('crm.kpi.this_month') }}
            <span v-if="lastMonthKpi" class="ml-2"
              :class="trendPct(currentMonthKpi?.costs || 0, lastMonthKpi.costs) >= 0 ? 'text-danger-500' : 'text-success-600'">
              {{ trendPct(currentMonthKpi?.costs || 0, lastMonthKpi.costs) >= 0 ? '▲' : '▼' }}
              {{ formatPercent(Math.abs(trendPct(currentMonthKpi?.costs || 0, lastMonthKpi.costs)), 0) }}
            </span>
          </div>
          <div class="text-xs text-neutral-400 mt-3 pt-2 border-t border-neutral-100 space-y-0.5">
            <div class="flex items-center justify-between gap-2" :title="t('crm.kpi.yoy_12m_hint')">
              <span>{{ t('crm.kpi.last_12m') }}</span>
              <span class="font-mono text-neutral-600">
                {{ formatMoney(last12mKpi?.costs || 0, displayCurrency) }}
                <span v-if="cost12Yoy.show" class="ml-1" :class="cost12Yoy.cls">{{ cost12Yoy.arrow }}{{ formatPercent(cost12Yoy.abs, 0) }}</span>
              </span>
            </div>
            <div class="flex items-center justify-between gap-2" :title="t('crm.kpi.yoy_ytd_hint')">
              <span>YTD</span>
              <span class="font-mono text-neutral-600">
                {{ formatMoney(ytdKpi?.costs || 0, displayCurrency) }}
                <span v-if="costYtdYoy.show" class="ml-1" :class="costYtdYoy.cls">{{ costYtdYoy.arrow }}{{ formatPercent(costYtdYoy.abs, 0) }}</span>
              </span>
            </div>
            <div>{{ currentMonthKpi?.purchase_count || 0 }} {{ t('crm.kpi.purchases') }}</div>
          </div>
        </div>

        <!-- Profit -->
        <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-5">
          <div class="flex items-center justify-between mb-1">
            <span class="text-xs uppercase tracking-wide text-neutral-500 font-medium">{{ t('crm.kpi.profit') }}</span>
            <svg class="w-5 h-5 text-primary-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/>
            </svg>
          </div>
          <div class="text-2xl font-bold font-mono"
            :class="expectedProfitThisMonth >= 0 ? 'text-success-600' : 'text-danger-500'">
            {{ formatMoney(expectedProfitThisMonth, displayCurrency) }}
          </div>
          <div class="text-xs text-neutral-500 mt-1">
            {{ t('crm.kpi.this_month') }}<span v-if="hasPipeline" class="text-neutral-400"> · {{ t('crm.kpi.expected') }}</span>
            <span v-if="expectedThisMonth > 0" class="ml-2">
              · {{ formatPercent((expectedProfitThisMonth / expectedThisMonth) * 100, 0) }} {{ t('crm.kpi.margin') }}
            </span>
          </div>
          <!-- Rozpad očekávaného zisku: vystavený zisk + koncepty + nespárované proformy (jen když jsou dopředné složky) -->
          <div v-if="hasPipeline" class="text-xs mt-3 pt-2 border-t border-neutral-100 space-y-0.5" :title="t('crm.kpi.pipeline_hint')">
            <div class="flex items-center justify-between gap-2 text-neutral-500">
              <span>{{ t('crm.kpi.issued') }}</span>
              <span class="font-mono">{{ formatMoney(currentMonthKpi?.profit || 0, displayCurrency) }}</span>
            </div>
            <div v-if="pipelineThisMonth.draft" class="flex items-center justify-between gap-2 text-neutral-500">
              <span>+ {{ t('crm.kpi.drafts') }} <span class="text-neutral-400">({{ pipelineThisMonth.draftCount }})</span></span>
              <span class="font-mono">{{ formatMoney(pipelineThisMonth.draft, displayCurrency) }}</span>
            </div>
            <div v-if="pipelineThisMonth.proforma" class="flex items-center justify-between gap-2 text-neutral-500">
              <span>+ {{ t('crm.kpi.proformas') }} <span class="text-neutral-400">({{ pipelineThisMonth.proformaCount }})</span></span>
              <span class="font-mono">{{ formatMoney(pipelineThisMonth.proforma, displayCurrency) }}</span>
            </div>
          </div>
          <div class="text-xs text-neutral-400 mt-3 pt-2 border-t border-neutral-100 space-y-0.5">
            <div class="flex items-center justify-between gap-2" :title="t('crm.kpi.yoy_12m_hint')">
              <span>{{ t('crm.kpi.last_12m') }}</span>
              <span class="font-mono text-neutral-600">
                {{ formatMoney(last12mKpi?.profit || 0, displayCurrency) }}
                <span v-if="profit12Yoy.show" class="ml-1" :class="profit12Yoy.cls">{{ profit12Yoy.arrow }}{{ formatPercent(profit12Yoy.abs, 0) }}</span>
              </span>
            </div>
            <div class="flex items-center justify-between gap-2" :title="t('crm.kpi.yoy_ytd_hint')">
              <span>YTD</span>
              <span class="font-mono text-neutral-600">
                {{ formatMoney(ytdKpi?.profit || 0, displayCurrency) }}
                <span v-if="profitYtdYoy.show" class="ml-1" :class="profitYtdYoy.cls">{{ profitYtdYoy.arrow }}{{ formatPercent(profitYtdYoy.abs, 0) }}</span>
              </span>
            </div>
            <div v-if="marginPct(ytdKpi?.profit, ytdKpi?.revenue) !== null">
              {{ t('crm.kpi.ytd_margin', { pct: formatNumber(marginPct(ytdKpi?.profit, ytdKpi?.revenue), { maximumFractionDigits: 0 }) }) }}
            </div>
          </div>
        </div>
        </div>
      </div>

      <!-- ═══ Srovnání období (tržby / náklady / zisk / marže) — nezávislé na přepínači níže ═══ -->
      <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
        <header class="px-5 py-3 border-b border-neutral-200">
          <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('crm.compare.title') }}</h3>
        </header>
        <div class="overflow-x-auto">
          <table class="w-full text-sm min-w-[560px]">
            <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
              <tr>
                <th class="text-left px-5 py-2 font-medium">{{ t('crm.compare.period') }}</th>
                <th class="text-right px-3 py-2 font-medium">{{ t('crm.kpi.revenue') }}</th>
                <th class="text-right px-3 py-2 font-medium">{{ t('crm.kpi.costs') }}</th>
                <th class="text-right px-3 py-2 font-medium">{{ t('crm.kpi.profit') }}</th>
                <th class="text-right px-5 py-2 font-medium">{{ t('crm.kpi.margin') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="(row, i) in comparisonRows" :key="i" class="hover:bg-neutral-50">
                <td class="px-5 py-2 font-medium text-neutral-700">{{ row.label }}</td>
                <td class="px-3 py-2 text-right">
                  <RouterLink :to="{ path: '/invoices', query: comparePeriodQuery(row.key) }"
                    class="font-mono text-neutral-900 hover:text-primary-700 hover:underline">
                    {{ formatMoney(row.revenue, displayCurrency) }}
                  </RouterLink>
                </td>
                <td class="px-3 py-2 text-right">
                  <RouterLink :to="{ path: '/purchase-invoices', query: comparePeriodQuery(row.key) }"
                    class="font-mono text-danger-500 hover:text-danger-600 hover:underline">
                    {{ formatMoney(row.costs, displayCurrency) }}
                  </RouterLink>
                </td>
                <td class="px-3 py-2 text-right font-mono" :class="row.profit >= 0 ? 'text-success-600' : 'text-danger-500'">
                  {{ row.profit >= 0 ? '+' : '' }}{{ formatMoney(row.profit, displayCurrency) }}
                </td>
                <td class="px-5 py-2 text-right font-mono text-neutral-600">
                  {{ formatPercent(row.margin, 1) }}
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- ═══ Grafy zisku: posledních 12 měsíců + kumulativní YTD vs loni (stabilní, 12 m) ═══ -->
      <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <div class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm">
          <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500 mb-4">
            {{ t('crm.profit_last_12_months', { currency: displayCurrency }) }}
          </h3>
          <RevenueChart :months="profitChartData.months" :prev-year="profitChartData.prevYear" :currency="displayCurrency" />
        </div>
        <div class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm">
          <div class="flex items-baseline justify-between mb-3">
            <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">
              {{ t('crm.cumulative_profit', { currency: displayCurrency }) }}
            </h3>
            <span class="text-xs text-neutral-400">{{ t('crm.cumulative_profit_hint') }}</span>
          </div>
          <CumulativeYtdChart :months="profitChartData.months" :prev-year="profitChartData.prevYear" :currency="displayCurrency" :allow-negative="true" />
        </div>
      </div>

      <!-- ═══ Analytické období — řídí žebříčky, grafy a metriky NÍŽE ═══ -->
      <div class="flex items-center justify-between gap-3 flex-wrap border-t border-neutral-200 pt-4">
        <div>
          <h2 class="text-sm font-semibold uppercase tracking-wide text-neutral-600">{{ t('crm.analytics_section') }}</h2>
          <p class="text-xs text-neutral-400 mt-0.5">{{ t('crm.analytics_section_hint') }}</p>
        </div>
        <label class="flex items-center gap-2 text-sm shrink-0">
          <span class="text-neutral-500">{{ t('crm.period_label') }}</span>
          <select v-model.number="periodMonths" class="h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
            <option :value="3">{{ t('crm.last_n_months', { n: 3 }) }}</option>
            <option :value="6">{{ t('crm.last_n_months', { n: 6 }) }}</option>
            <option :value="12">{{ t('crm.last_n_months', { n: 12 }) }}</option>
            <option :value="24">{{ t('crm.last_n_months', { n: 24 }) }}</option>
          </select>
        </label>
      </div>

      <!-- ═══ Monthly trend chart (HTML/CSS bars — no chart.js dependency) ═══ -->
      <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm">
        <header class="px-5 py-3 border-b border-neutral-200 flex items-center justify-between">
          <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">
            {{ t('crm.monthly_trend') }} <span class="normal-case font-normal text-[10px] text-neutral-400">({{ periodChip }})</span>
          </h3>
          <div class="flex items-center gap-3 text-xs">
            <span class="flex items-center gap-1">
              <span class="inline-block w-3 h-3 rounded-sm bg-success-500"></span>
              {{ t('crm.kpi.revenue') }}
            </span>
            <span class="flex items-center gap-1">
              <span class="inline-block w-3 h-3 rounded-sm bg-danger-500"></span>
              {{ t('crm.kpi.costs') }}
            </span>
          </div>
        </header>
        <EmptyState v-if="monthlyDisplay.length === 0" icon="chart" :title="t('crm.no_chart_data')" dense />
        <div v-else class="p-4 space-y-2">
          <div v-for="m in monthlyDisplay" :key="m.period + m.currency" class="grid grid-cols-[60px_1fr_120px] gap-2 items-center text-xs">
            <div class="text-neutral-600 font-medium">{{ formatMonthLabel(m.period) }}</div>
            <div class="space-y-1">
              <div class="flex items-center gap-2">
                <div class="bg-success-500 h-3 rounded-sm" :style="{ width: barWidthPct(m.revenue) + '%' }"></div>
                <span class="font-mono text-neutral-700">{{ formatMoney(m.revenue, m.currency) }}</span>
              </div>
              <div class="flex items-center gap-2">
                <div class="bg-danger-500 h-3 rounded-sm" :style="{ width: barWidthPct(m.costs) + '%' }"></div>
                <span class="font-mono text-neutral-700">{{ formatMoney(m.costs, m.currency) }}</span>
              </div>
            </div>
            <div class="text-right font-mono"
              :class="m.profit >= 0 ? 'text-success-600' : 'text-danger-500'">
              {{ m.profit >= 0 ? '+' : '' }}{{ formatMoney(m.profit, m.currency) }}
            </div>
          </div>
        </div>
      </div>

      <!-- ═══ Top klienti + Top vendoři side by side ═══ -->
      <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <!-- Top clients -->
        <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
          <header class="px-5 py-3 border-b border-neutral-200">
            <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">
              {{ t('crm.top_clients') }} <span class="normal-case font-normal text-[10px] text-neutral-400">({{ periodChip }})</span>
            </h3>
          </header>
          <EmptyState v-if="topClients.length === 0" icon="user" :title="t('crm.no_data')" dense />
          <table v-else class="w-full text-sm">
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="c in topClients" :key="c.client_id" class="hover:bg-neutral-50">
                <td class="px-5 py-2.5">
                  <RouterLink :to="`/clients/${c.client_id}`" class="font-medium text-primary-700 hover:underline">
                    {{ c.company_name }}
                  </RouterLink>
                  <span v-if="c.currencies && c.currencies !== 'CZK'" class="ml-1.5 text-xs text-neutral-400">({{ c.currencies }})</span>
                  <div class="text-xs text-neutral-500 mt-0.5">{{ c.invoice_count }} {{ t('crm.kpi.invoices') }}</div>
                </td>
                <td class="px-3 py-2.5 text-right font-mono text-neutral-900">
                  {{ formatMoney(c.revenue, 'CZK') }}
                </td>
                <td class="px-5 py-2.5 text-right text-xs text-neutral-500 font-mono">
                  {{ formatPercent(c.percent_share, 1) }}
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Top vendors -->
        <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
          <header class="px-5 py-3 border-b border-neutral-200">
            <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">
              {{ t('crm.top_vendors') }} <span class="normal-case font-normal text-[10px] text-neutral-400">({{ periodChip }})</span>
            </h3>
          </header>
          <EmptyState v-if="topVendors.length === 0" icon="user" :title="t('crm.no_data_vendors')" dense />
          <table v-else class="w-full text-sm">
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="v in topVendors" :key="v.vendor_id" class="hover:bg-neutral-50">
                <td class="px-5 py-2.5">
                  <RouterLink :to="`/clients/${v.vendor_id}`" class="font-medium text-primary-700 hover:underline">
                    {{ v.company_name }}
                  </RouterLink>
                  <span v-if="v.currencies && v.currencies !== 'CZK'" class="ml-1.5 text-xs text-neutral-400">({{ v.currencies }})</span>
                  <div class="text-xs text-neutral-500 mt-0.5">{{ v.purchase_count }} {{ t('crm.kpi.purchases') }}</div>
                </td>
                <td class="px-3 py-2.5 text-right font-mono text-neutral-900">
                  {{ formatMoney(v.costs, 'CZK') }}
                </td>
                <td class="px-5 py-2.5 text-right text-xs text-neutral-500 font-mono">
                  {{ formatPercent(v.percent_share, 1) }}
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- ═══ Aging buckets (pohledávky + závazky) ═══ -->
      <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <!-- Receivables -->
        <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
          <header class="px-5 py-3 border-b border-neutral-200 flex items-center justify-between">
            <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">
              {{ t('crm.aging.receivables_title') }} <span class="normal-case font-normal text-[10px] text-neutral-400">({{ t('crm.snapshot_now') }})</span>
            </h3>
            <div class="flex items-center gap-3">
              <span class="text-sm font-mono text-neutral-700">
                {{ formatMoney(agingTotal, displayCurrency) }}
              </span>
              <RouterLink :to="{ path: '/invoices', query: { year: 'all', overdue: '1' } }"
                class="text-xs text-primary-600 hover:text-primary-700 hover:underline whitespace-nowrap">
                {{ t('common.view_all') }}
              </RouterLink>
            </div>
          </header>
          <EmptyState v-if="agingForCurrency.length === 0" icon="coin" :title="t('crm.aging.no_open')" dense />
          <div v-else class="p-4 space-y-2">
            <div v-for="b in agingForCurrency" :key="b.bucket" class="grid grid-cols-[100px_1fr_120px] gap-2 items-center text-xs">
              <div class="text-neutral-700 font-medium">{{ t('crm.aging.bucket.' + b.bucket) }}</div>
              <div class="flex items-center gap-2">
                <div :class="['h-3 rounded-sm', agingBucketColor(b.bucket)]"
                  :style="{ width: agingPct(b, agingTotal) + '%' }"></div>
                <span class="text-neutral-500">{{ b.count }} {{ t('crm.kpi.invoices') }}</span>
              </div>
              <div class="text-right font-mono text-neutral-700">
                {{ formatMoney(b.total, b.currency) }}
              </div>
            </div>
          </div>
        </div>

        <!-- Payables -->
        <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
          <header class="px-5 py-3 border-b border-neutral-200 flex items-center justify-between">
            <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">
              {{ t('crm.aging.payables_title') }} <span class="normal-case font-normal text-[10px] text-neutral-400">({{ t('crm.snapshot_now') }})</span>
            </h3>
            <div class="flex items-center gap-3">
              <span class="text-sm font-mono text-neutral-700">
                {{ formatMoney(agingPayTotal, displayCurrency) }}
              </span>
              <RouterLink :to="{ path: '/purchase-invoices', query: { overdue: '1' } }"
                class="text-xs text-primary-600 hover:text-primary-700 hover:underline whitespace-nowrap">
                {{ t('common.view_all') }}
              </RouterLink>
            </div>
          </header>
          <EmptyState v-if="agingPayForCurrency.length === 0" icon="coin" :title="t('crm.aging.no_pay')" dense />
          <div v-else class="p-4 space-y-2">
            <div v-for="b in agingPayForCurrency" :key="b.bucket" class="grid grid-cols-[100px_1fr_120px] gap-2 items-center text-xs">
              <div class="text-neutral-700 font-medium">{{ t('crm.aging.bucket.' + b.bucket) }}</div>
              <div class="flex items-center gap-2">
                <div :class="['h-3 rounded-sm', agingBucketColor(b.bucket)]"
                  :style="{ width: agingPct(b, agingPayTotal) + '%' }"></div>
                <span class="text-neutral-500">{{ b.count }} {{ t('crm.kpi.invoices') }}</span>
              </div>
              <div class="text-right font-mono text-neutral-700">
                {{ formatMoney(b.total, b.currency) }}
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- ═══ Health metrics row: DSO + Punctuality + Concentration ═══ -->
      <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <!-- DSO -->
        <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-5">
          <div class="text-xs uppercase tracking-wide text-neutral-500 font-medium mb-1">
            {{ t('crm.dso.title') }} <span class="normal-case font-normal text-neutral-400">· {{ periodChip }}</span>
          </div>
          <div class="text-2xl font-bold font-mono text-neutral-900">
            {{ formatNumber(dso?.avg_days, { maximumFractionDigits: 1 }) }}<span class="text-base text-neutral-500 ml-1">{{ t('crm.dso.days') }}</span>
          </div>
          <div class="text-xs text-neutral-500 mt-1">{{ t('crm.dso.hint', { n: dso?.sample_size || 0 }) }}</div>
        </div>

        <!-- Punctuality -->
        <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-5">
          <div class="text-xs uppercase tracking-wide text-neutral-500 font-medium mb-1">
            {{ t('crm.punctuality.title') }} <span class="normal-case font-normal text-neutral-400">· {{ periodChip }}</span>
          </div>
          <div class="text-2xl font-bold font-mono"
            :class="(punctuality?.on_time_pct ?? 0) >= 80 ? 'text-success-600' : (punctuality?.on_time_pct ?? 0) >= 50 ? 'text-warning-600' : 'text-danger-500'">
            {{ formatPercent(punctuality?.on_time_pct ?? 0, 1) }}
          </div>
          <div class="text-xs text-neutral-500 mt-1">
            {{ t('crm.punctuality.detail', { on_time: punctuality?.on_time || 0, late: punctuality?.late || 0 }) }}
          </div>
        </div>

        <!-- Concentration risk -->
        <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-5">
          <div class="text-xs uppercase tracking-wide text-neutral-500 font-medium mb-1">
            {{ t('crm.concentration.title') }} <span class="normal-case font-normal text-neutral-400">· {{ periodChip }}</span>
          </div>
          <div class="text-2xl font-bold font-mono" :class="riskColor(concentration?.risk_level || 'low')">
            {{ formatPercent(concentration?.top1_share ?? 0, 1) }}
          </div>
          <div class="text-xs text-neutral-500 mt-1">
            {{ t('crm.concentration.top1', { pct: formatNumber(concentration?.top1_share ?? 0, { maximumFractionDigits: 1 }) }) }}
            <span class="ml-2">· {{ t('crm.concentration.pareto', { n: concentration?.pareto_80_count ?? 0 }) }}</span>
          </div>
          <div class="text-xs mt-2 pt-2 border-t border-neutral-100" :class="riskColor(concentration?.risk_level || 'low')">
            {{ t('crm.concentration.risk_' + (concentration?.risk_level || 'low')) }}
          </div>
        </div>
      </div>

      <!-- ═══ Health metrics row 2: DPO + Vendor concentration + Working capital cycle ═══ -->
      <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <!-- DPO -->
        <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-5">
          <div class="text-xs uppercase tracking-wide text-neutral-500 font-medium mb-1">
            {{ t('crm.dpo.title') }} <span class="normal-case font-normal text-neutral-400">· {{ periodChip }}</span>
          </div>
          <div class="text-2xl font-bold font-mono text-neutral-900">
            {{ formatNumber(dpo?.avg_days, { maximumFractionDigits: 1 }) }}<span class="text-base text-neutral-500 ml-1">{{ t('crm.dso.days') }}</span>
          </div>
          <div class="text-xs text-neutral-500 mt-1">{{ t('crm.dpo.hint', { n: dpo?.sample_size || 0 }) }}</div>
        </div>

        <!-- Vendor concentration risk -->
        <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-5">
          <div class="text-xs uppercase tracking-wide text-neutral-500 font-medium mb-1">
            {{ t('crm.vendor_concentration.title') }} <span class="normal-case font-normal text-neutral-400">· {{ periodChip }}</span>
          </div>
          <div class="text-2xl font-bold font-mono" :class="riskColor(vendorConcentration?.risk_level || 'low')">
            {{ formatPercent(vendorConcentration?.top1_share ?? 0, 1) }}
          </div>
          <div class="text-xs text-neutral-500 mt-1">
            {{ t('crm.vendor_concentration.top1', { pct: formatNumber(vendorConcentration?.top1_share ?? 0, { maximumFractionDigits: 1 }) }) }}
            <span class="ml-2">· {{ t('crm.vendor_concentration.pareto', { n: vendorConcentration?.pareto_80_count ?? 0 }) }}</span>
          </div>
          <div class="text-xs mt-2 pt-2 border-t border-neutral-100" :class="riskColor(vendorConcentration?.risk_level || 'low')">
            {{ t('crm.vendor_concentration.risk_' + (vendorConcentration?.risk_level || 'low')) }}
          </div>
        </div>

        <!-- Working capital cycle (DSO − DPO) -->
        <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-5">
          <div class="text-xs uppercase tracking-wide text-neutral-500 font-medium mb-1">
            {{ t('crm.wc_cycle.title') }}
          </div>
          <div class="text-2xl font-bold font-mono"
            :class="wcCycle === null ? 'text-neutral-400' : wcCycle > 0 ? 'text-warning-600' : 'text-success-600'">
            <template v-if="wcCycle !== null">{{ wcCycle > 0 ? '+' : '' }}{{ formatNumber(wcCycle, { maximumFractionDigits: 1 }) }}<span class="text-base text-neutral-500 ml-1">{{ t('crm.dso.days') }}</span></template>
            <template v-else>—</template>
          </div>
          <div class="text-xs text-neutral-500 mt-1">{{ t('crm.wc_cycle.formula') }}</div>
          <div v-if="wcCycle !== null" class="text-xs mt-2 pt-2 border-t border-neutral-100"
            :class="wcCycle > 0 ? 'text-warning-600' : 'text-success-600'">
            {{ wcCycle > 0 ? t('crm.wc_cycle.positive') : t('crm.wc_cycle.negative') }}
          </div>
        </div>
      </div>

      <!-- ═══ Revenue breakdown (rozpad tržeb po kategoriích, vždy CZK) ═══ -->
      <div v-if="revenues.length > 0" class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
        <header class="px-5 py-3 border-b border-neutral-200">
          <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">
            {{ t('crm.revenue_breakdown.title') }} <span class="normal-case font-normal text-[10px] text-neutral-400">({{ periodChip }})</span>
          </h3>
        </header>
        <table class="w-full text-sm">
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="r in revenues" :key="(r.category_id ?? 0) + '-' + (r.code ?? '')" class="hover:bg-neutral-50">
              <td class="px-5 py-2">
                <div class="font-medium text-neutral-900">
                  {{ r.label || t('crm.revenue_breakdown.uncategorized') }}
                </div>
                <div class="text-xs text-neutral-500">{{ r.count }} {{ t('crm.kpi.invoices') }}</div>
              </td>
              <td class="px-3 py-2">
                <div class="w-full h-2 bg-neutral-100 rounded">
                  <div class="h-full bg-success-500 rounded" :style="{ width: r.percent + '%' }"></div>
                </div>
              </td>
              <!-- revenue breakdown je VŽDY CZK-normalizovaný (server přepočítá ×exchange_rate) → label CZK -->
              <td class="px-3 py-2 text-right font-mono text-neutral-900">{{ formatMoney(r.total, 'CZK') }}</td>
              <td class="px-5 py-2 text-right text-xs text-neutral-500 font-mono w-12">{{ formatPercent(r.percent, 1) }}</td>
            </tr>
          </tbody>
        </table>
        <div v-if="revenues.length > 0 && revenues[0].category_id === null"
          class="px-5 py-2 text-xs text-warning-600 bg-warning-50 border-t border-warning-500/40">
          💡 {{ t('crm.revenue_breakdown.uncategorized_hint') }}
        </div>
      </div>

      <!-- ═══ Expense breakdown + Churn risk side-by-side ═══ -->
      <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <!-- Expense breakdown -->
        <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
          <header class="px-5 py-3 border-b border-neutral-200">
            <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">
              {{ t('crm.expense_breakdown.title') }} <span class="normal-case font-normal text-[10px] text-neutral-400">({{ periodChip }})</span>
            </h3>
          </header>
          <EmptyState v-if="expenses.length === 0" icon="coin" :title="t('crm.expense_breakdown.empty')" dense />
          <table v-else class="w-full text-sm">
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="e in expenses" :key="(e.category_id ?? 0) + '-' + (e.code ?? '')" class="hover:bg-neutral-50">
                <td class="px-5 py-2">
                  <div class="font-medium text-neutral-900">
                    {{ e.label || t('crm.expense_breakdown.uncategorized') }}
                  </div>
                  <div class="text-xs text-neutral-500">{{ e.count }} {{ t('crm.kpi.purchases') }}</div>
                </td>
                <td class="px-3 py-2">
                  <div class="w-full h-2 bg-neutral-100 rounded">
                    <div class="h-full bg-warning-500 rounded" :style="{ width: e.percent + '%' }"></div>
                  </div>
                </td>
                <!-- expense breakdown je VŽDY CZK-normalizovaný (server přepočítá ×exchange_rate) → label CZK -->
                <td class="px-3 py-2 text-right font-mono text-neutral-900">{{ formatMoney(e.total, 'CZK') }}</td>
                <td class="px-5 py-2 text-right text-xs text-neutral-500 font-mono w-12">{{ formatPercent(e.percent, 1) }}</td>
              </tr>
            </tbody>
          </table>
          <div v-if="expenses.length > 0 && expenses[0].category_id === null"
            class="px-5 py-2 text-xs text-warning-600 bg-warning-50 border-t border-warning-500/40">
            💡 {{ t('crm.expense_breakdown.uncategorized_hint') }}
          </div>
        </div>

        <!-- Churn risk -->
        <div id="churn-risk" class="scroll-mt-20 bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
          <header class="px-5 py-3 border-b border-neutral-200">
            <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">
              {{ t('crm.churn.title') }}
            </h3>
          </header>
          <EmptyState v-if="churn.length === 0" icon="user" :title="t('crm.churn.empty')" dense />
          <table v-else class="w-full text-sm">
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="c in churn" :key="c.client_id + c.currency" class="hover:bg-neutral-50">
                <td class="px-5 py-2">
                  <RouterLink :to="`/clients/${c.client_id}`" class="font-medium text-primary-700 hover:underline">
                    {{ c.company_name }}
                  </RouterLink>
                  <div class="text-xs text-neutral-500">{{ t('crm.churn.last', { date: formatDate(c.last_invoice_date) }) }}</div>
                </td>
                <td class="px-3 py-2 text-right">
                  <span class="text-sm font-mono"
                    :class="c.days_since > 180 ? 'text-danger-500' : c.days_since > 90 ? 'text-warning-600' : 'text-neutral-700'">
                    {{ formatNumber(c.days_since) }} {{ t('crm.payment_time.days') }}
                  </span>
                </td>
                <td class="px-5 py-2 text-right text-xs text-neutral-500 font-mono">
                  {{ formatMoney(c.total_revenue, c.currency) }}
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- ═══ Náklady po rocích + Náklady po měsících (obdoba Stats) ═══ -->
      <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <!-- Náklady po rocích -->
        <div v-if="yearly.length > 0" class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
          <header class="px-5 py-3 border-b border-neutral-200">
            <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">
              📅 {{ t('crm.costs_by_year_table') }}
            </h3>
          </header>
          <div class="overflow-x-auto">
            <table class="w-full text-sm">
              <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
                <tr>
                  <th class="text-left px-4 py-2 font-medium">{{ t('common.year') }}</th>
                  <th class="text-right px-4 py-2 font-medium">{{ t('common.costs') }}</th>
                  <th class="text-right px-4 py-2 font-medium whitespace-nowrap">{{ t('crm.purchase_invoices_short') }}</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-neutral-100">
                <tr v-for="r in yearly.filter(y => y.costs > 0 || y.purchase_count > 0)" :key="`cy-${r.year}-${r.currency}`"
                  class="cursor-pointer hover:bg-neutral-50" :title="t('crm.go_to_purchases')"
                  @click="$router.push({ path: '/purchase-invoices', query: { year: String(r.year) } })">
                  <td class="px-4 py-2 font-medium">{{ r.year }}</td>
                  <td class="px-4 py-2 text-right font-mono text-danger-500">{{ formatMoney(r.costs, r.currency) }}</td>
                  <td class="px-4 py-2 text-right text-xs text-neutral-500">{{ r.purchase_count }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <!-- Náklady po měsících (posledních N podle periodMonths) -->
        <div v-if="monthlyDisplay.length > 0" class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
          <header class="px-5 py-3 border-b border-neutral-200">
            <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">
              📊 {{ t('crm.costs_by_month_table') }} <span class="normal-case font-normal text-[10px] text-neutral-400">({{ periodChip }})</span>
            </h3>
          </header>
          <div class="overflow-x-auto">
            <table class="w-full text-sm">
              <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
                <tr>
                  <th class="text-left px-4 py-2 font-medium">{{ t('common.month') }}</th>
                  <th class="text-right px-4 py-2 font-medium">{{ t('common.costs') }}</th>
                  <th class="text-right px-4 py-2 font-medium whitespace-nowrap">{{ t('crm.purchase_invoices_short') }}</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-neutral-100">
                <tr v-for="row in [...monthlyDisplay].filter(m => m.costs > 0 || m.purchase_count > 0).reverse()" :key="`cm-${row.period}-${row.currency}`"
                  class="cursor-pointer hover:bg-neutral-50" :title="t('crm.go_to_purchases')"
                  @click="$router.push({ path: '/purchase-invoices', query: monthQuery(row.period) })">
                  <td class="px-4 py-2 font-mono text-neutral-700">{{ row.period }}</td>
                  <td class="px-4 py-2 text-right font-mono text-danger-500">{{ formatMoney(row.costs, row.currency) }}</td>
                  <td class="px-4 py-2 text-right text-xs text-neutral-500">{{ row.purchase_count }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- ═══ Zisk po rocích + Zisk po měsících (výsledovka tržby/zisk/marže) ═══ -->
      <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <!-- Zisk po rocích -->
        <div v-if="yearly.length > 0" class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
          <header class="px-5 py-3 border-b border-neutral-200">
            <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">
              📅 {{ t('crm.profit_by_year_table') }}
            </h3>
          </header>
          <div class="overflow-x-auto">
            <table class="w-full text-sm">
              <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
                <tr>
                  <th class="text-left px-4 py-2 font-medium">{{ t('common.year') }}</th>
                  <th class="text-right px-4 py-2 font-medium">{{ t('crm.kpi.revenue') }}</th>
                  <th class="text-right px-4 py-2 font-medium">{{ t('crm.kpi.profit') }}</th>
                  <th class="text-right px-4 py-2 font-medium">{{ t('crm.kpi.margin') }}</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-neutral-100">
                <tr v-for="r in yearly.filter(y => y.revenue > 0 || y.costs > 0)" :key="`py-${r.year}-${r.currency}`" class="hover:bg-neutral-50">
                  <td class="px-4 py-2 font-medium">{{ r.year }}</td>
                  <td class="px-4 py-2 text-right">
                    <RouterLink :to="{ path: '/invoices', query: { year: String(r.year) } }"
                      class="font-mono text-neutral-700 hover:text-primary-700 hover:underline" :title="t('crm.go_to_invoices')">
                      {{ formatMoney(r.revenue, r.currency) }}
                    </RouterLink>
                  </td>
                  <td class="px-4 py-2 text-right font-mono" :class="r.profit >= 0 ? 'text-success-600' : 'text-danger-500'">
                    {{ r.profit >= 0 ? '+' : '' }}{{ formatMoney(r.profit, r.currency) }}
                  </td>
                  <td class="px-4 py-2 text-right font-mono text-xs text-neutral-500">
                    {{ formatPercent(marginPct(r.profit, r.revenue), 1) }}
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <!-- Zisk po měsících (posledních N podle periodMonths) -->
        <div v-if="monthlyDisplay.length > 0" class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
          <header class="px-5 py-3 border-b border-neutral-200">
            <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">
              📊 {{ t('crm.profit_by_month_table') }} <span class="normal-case font-normal text-[10px] text-neutral-400">({{ periodChip }})</span>
            </h3>
          </header>
          <div class="overflow-x-auto">
            <table class="w-full text-sm">
              <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
                <tr>
                  <th class="text-left px-4 py-2 font-medium">{{ t('common.month') }}</th>
                  <th class="text-right px-4 py-2 font-medium">{{ t('crm.kpi.revenue') }}</th>
                  <th class="text-right px-4 py-2 font-medium">{{ t('crm.kpi.profit') }}</th>
                  <th class="text-right px-4 py-2 font-medium">{{ t('crm.kpi.margin') }}</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-neutral-100">
                <tr v-for="row in [...monthlyDisplay].filter(m => m.revenue > 0 || m.costs > 0).reverse()" :key="`pm-${row.period}-${row.currency}`" class="hover:bg-neutral-50">
                  <td class="px-4 py-2 font-mono text-neutral-700">{{ row.period }}</td>
                  <td class="px-4 py-2 text-right">
                    <RouterLink :to="{ path: '/invoices', query: monthQuery(row.period) }"
                      class="font-mono text-neutral-700 hover:text-primary-700 hover:underline" :title="t('crm.go_to_invoices')">
                      {{ formatMoney(row.revenue, row.currency) }}
                    </RouterLink>
                  </td>
                  <td class="px-4 py-2 text-right font-mono" :class="row.profit >= 0 ? 'text-success-600' : 'text-danger-500'">
                    {{ row.profit >= 0 ? '+' : '' }}{{ formatMoney(row.profit, row.currency) }}
                  </td>
                  <td class="px-4 py-2 text-right font-mono text-xs text-neutral-500">
                    {{ formatPercent(marginPct(row.profit, row.revenue), 1) }}
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- ═══ Cash flow forecast (4 týdny) ═══ -->
      <div v-if="cashFlow && cashFlow.weeks.length > 0" class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
        <header class="px-5 py-3 border-b border-neutral-200 flex items-center justify-between">
          <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">
            💰 {{ t('crm.cash_flow.title') }} ({{ cashFlow.currency }})
          </h3>
          <div class="text-xs text-neutral-500">{{ t('crm.cash_flow.next_n_weeks', { n: cashFlow.weeks.length }) }}</div>
        </header>
        <div class="overflow-x-auto">
        <table class="w-full text-sm min-w-[560px]">
          <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
            <tr>
              <th class="text-left px-5 py-2">{{ t('crm.cash_flow.week') }}</th>
              <th class="text-right px-3 py-2">{{ t('crm.cash_flow.in') }}</th>
              <th class="text-right px-3 py-2">{{ t('crm.cash_flow.out') }}</th>
              <th class="text-right px-3 py-2">{{ t('crm.cash_flow.net') }}</th>
              <th class="text-right px-5 py-2">{{ t('crm.cash_flow.running') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="(w, i) in cashFlow.weeks" :key="i" class="hover:bg-neutral-50">
              <td class="px-5 py-2 text-xs">
                <span class="font-medium">{{ formatDate(w.week_start) }}</span>
                <span class="text-neutral-400"> – {{ formatDate(w.week_end) }}</span>
              </td>
              <td class="px-3 py-2 text-right font-mono text-success-600">{{ formatMoney(w.in, cashFlow.currency) }}</td>
              <td class="px-3 py-2 text-right font-mono text-danger-500">−{{ formatMoney(w.out, cashFlow.currency) }}</td>
              <td class="px-3 py-2 text-right font-mono" :class="w.net >= 0 ? 'text-success-600' : 'text-danger-500'">
                {{ w.net >= 0 ? '+' : '' }}{{ formatMoney(w.net, cashFlow.currency) }}
              </td>
              <td class="px-5 py-2 text-right font-mono font-medium" :class="w.running >= 0 ? 'text-neutral-700' : 'text-danger-500'">
                {{ formatMoney(w.running, cashFlow.currency) }}
              </td>
            </tr>
          </tbody>
          <tfoot class="bg-neutral-50">
            <tr>
              <td class="px-5 py-2 text-xs font-medium">{{ t('crm.cash_flow.total') }}</td>
              <td class="px-3 py-2 text-right font-mono text-success-600 font-medium">{{ formatMoney(cashFlow.total_in, cashFlow.currency) }}</td>
              <td class="px-3 py-2 text-right font-mono text-danger-500 font-medium">−{{ formatMoney(cashFlow.total_out, cashFlow.currency) }}</td>
              <td colspan="2" class="px-5 py-2 text-right font-mono font-bold" :class="cashFlow.total_net >= 0 ? 'text-success-600' : 'text-danger-500'">
                {{ cashFlow.total_net >= 0 ? '+' : '' }}{{ formatMoney(cashFlow.total_net, cashFlow.currency) }}
              </td>
            </tr>
          </tfoot>
        </table>
        </div>
      </div>

      <!-- ═══ Late payment risk + Payment time histogram side-by-side ═══ -->
      <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <!-- Late risk -->
        <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
          <header class="px-5 py-3 border-b border-neutral-200">
            <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">
              ⚠️ {{ t('crm.late_risk.title') }}
            </h3>
          </header>
          <EmptyState v-if="lateRisk.length === 0" icon="bell" :title="t('crm.late_risk.no_data')" dense />
          <table v-else class="w-full text-sm">
            <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
              <tr>
                <th class="text-left px-5 py-2">{{ t('crm.late_risk.client') }}</th>
                <th class="text-right px-3 py-2">{{ t('crm.late_risk.late_rate') }}</th>
                <th class="text-right px-3 py-2">{{ t('crm.late_risk.avg_days') }}</th>
                <th class="text-center px-5 py-2">{{ t('crm.late_risk.score') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="c in lateRisk" :key="c.client_id" class="hover:bg-neutral-50">
                <td class="px-5 py-2">
                  <RouterLink :to="`/clients/${c.client_id}`" class="text-sm font-medium hover:text-primary-700 hover:underline">
                    {{ c.company_name }}
                  </RouterLink>
                  <div class="text-xs text-neutral-500">{{ c.late_count }}/{{ c.total_paid }} {{ t('crm.late_risk.late_paid') }}</div>
                </td>
                <td class="px-3 py-2 text-right font-mono text-xs">{{ formatPercent(c.late_rate * 100, 0) }}</td>
                <td class="px-3 py-2 text-right font-mono text-xs">{{ formatNumber(c.avg_days_late, { minimumFractionDigits: 1, maximumFractionDigits: 1 }) }} {{ t('crm.payment_time.days') }}</td>
                <td class="px-5 py-2 text-center">
                  <span :class="['inline-block px-2 py-0.5 rounded text-xs font-bold',
                    c.risk_level === 'high' ? 'bg-danger-50 text-danger-500' :
                    c.risk_level === 'medium' ? 'bg-warning-50 text-warning-600' : 'bg-success-50 text-success-600']">
                    {{ c.score }}
                  </span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Payment time histogram -->
        <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
          <header class="px-5 py-3 border-b border-neutral-200 flex items-center justify-between">
            <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">
              ⏱️ {{ t('crm.payment_time.title') }} <span class="normal-case font-normal text-[10px] text-neutral-400">({{ periodChip }})</span>
            </h3>
            <div v-if="paymentHist && paymentHist.median_days !== null" class="text-xs text-neutral-500">
              {{ t('crm.payment_time.median') }}: <span class="font-mono font-medium">{{ formatNumber(paymentHist.median_days, { maximumFractionDigits: 1 }) }} {{ t('crm.payment_time.days') }}</span>
            </div>
          </header>
          <EmptyState v-if="!paymentHist || paymentHist.total_invoices === 0" icon="chart" :title="t('crm.payment_time.no_data')" dense />
          <div v-else class="p-4 space-y-2">
            <div v-for="b in paymentHist.buckets" :key="b.label" class="text-xs">
              <div class="flex justify-between mb-1">
                <span class="text-neutral-700 font-medium">{{ b.label }}</span>
                <span class="font-mono text-neutral-600">{{ b.count }} ({{ formatPercent(b.percent, 1) }})</span>
              </div>
              <div class="w-full bg-neutral-100 rounded h-2 overflow-hidden">
                <div class="h-full rounded transition-all" :style="{ width: b.percent + '%' }"
                  :class="b.min >= 31 ? 'bg-danger-400' : b.min >= 15 ? 'bg-warning-400' : 'bg-success-500'"></div>
              </div>
            </div>
            <div class="text-xs text-neutral-500 mt-3 pt-3 border-t border-neutral-100">
              {{ t('crm.payment_time.total') }}: {{ paymentHist.total_invoices }} •
              {{ t('crm.payment_time.p90') }}: {{ formatNumber(paymentHist.p90_days, { maximumFractionDigits: 1 }) }} {{ t('crm.payment_time.days') }}
            </div>
          </div>
        </div>
      </div>

      <!-- ═══ Reminder effectiveness funnel ═══ -->
      <div v-if="reminderEff && reminderEff.total_paid > 0" class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
        <header class="px-5 py-3 border-b border-neutral-200">
          <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">
            📧 {{ t('crm.reminder.title') }} <span class="normal-case font-normal text-[10px] text-neutral-400">({{ periodChip }})</span>
          </h3>
        </header>
        <div class="p-4 grid grid-cols-2 md:grid-cols-5 gap-3 text-center">
          <div>
            <div class="text-2xl font-bold text-success-600">{{ reminderEff.no_reminder }}</div>
            <div class="text-xs text-neutral-500 mt-1">{{ t('crm.reminder.no_reminder') }}</div>
          </div>
          <div>
            <div class="text-2xl font-bold text-primary-600">{{ reminderEff.after_first }}</div>
            <div class="text-xs text-neutral-500 mt-1">{{ t('crm.reminder.after_first') }}</div>
          </div>
          <div>
            <div class="text-2xl font-bold text-warning-600">{{ reminderEff.after_second }}</div>
            <div class="text-xs text-neutral-500 mt-1">{{ t('crm.reminder.after_second') }}</div>
          </div>
          <div>
            <div class="text-2xl font-bold text-danger-500">{{ reminderEff.after_third_plus }}</div>
            <div class="text-xs text-neutral-500 mt-1">{{ t('crm.reminder.after_third_plus') }}</div>
          </div>
          <div>
            <div class="text-2xl font-bold text-neutral-400">{{ reminderEff.never_paid }}</div>
            <div class="text-xs text-neutral-500 mt-1">{{ t('crm.reminder.never_paid') }}</div>
          </div>
        </div>
        <div class="px-4 pb-3 text-xs text-neutral-500 text-center">
          {{ t('crm.reminder.avg_reminders') }}: <span class="font-mono font-medium">{{ formatNumber(reminderEff.avg_reminders_to_paid, { maximumFractionDigits: 1 }) }}</span>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted, computed } from 'vue'
import { useRouter, RouterLink } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import { useSupplierStore } from '@/stores/supplier'

const { t } = useI18n()
import { dashboardApi, type DashboardSummary } from '@/api/dashboard'
import { formatMoney, formatDate, formatNumber } from '@/composables/useFormat'
import SparklineChart from '@/components/charts/SparklineChart.vue'
import TopClientsPieChart from '@/components/charts/TopClientsPieChart.vue'
import TaxNetWidget from '@/components/dashboard/TaxNetWidget.vue'
import TaxAdvancesWidget from '@/components/dashboard/TaxAdvancesWidget.vue'
import TaxCalendarWidget from '@/components/dashboard/TaxCalendarWidget.vue'
import ActionItemsWidget from '@/components/dashboard/ActionItemsWidget.vue'
import WorkReportModal from '@/components/modals/WorkReportModal.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import OnboardingGuide from '@/components/dashboard/OnboardingGuide.vue'

const router = useRouter()
const auth = useAuthStore()
const supplierStore = useSupplierStore()
const canCreateSupplier = computed(() => auth.isSuperadmin || auth.isAdminPlusRole)
const canReviewApprovals = computed(() => auth.canRead('invoices.approval'))
const isDoubleEntry = computed(() => auth.hasCommercialFeatures && supplierStore.currentSupplier?.accounting_mode === 'double_entry')

// Onboarding gate (#151): bez dodavatele je dashboard prázdný a všechny zakládací akce
// padají → místo dat ukážeme výzvu k vytvoření prvního dodavatele.
const hasSupplier = computed(() => supplierStore.hasSupplier)

/**
 * Identifikace firmy pod nadpisem Přehledu: IČ · DIČ · adresa na jednom řádku.
 * Prázdné údaje se vynechají i s oddělovačem, takže u neplátce bez DIČ nebo
 * u firmy bez vyplněné adresy nezůstane viset osiřelá tečka.
 */
const supplierIdentity = computed<string>(() => {
  const s = supplierStore.currentSupplier
  if (!s) return ''
  const address = [s.street, [s.zip, s.city].filter(Boolean).join(' ')].filter(Boolean).join(', ')
  return [
    s.ic ? `${t('common.ic')} ${s.ic}` : '',
    s.dic ? `${t('common.dic')} ${s.dic}` : '',
    address,
  ].filter(Boolean).join(' · ')
})

// Průvodce prvním nastavením si sám řekne, jestli je vidět (uživatel si ho mohl
// schovat) — dokud svítí, prázdný stav pod ním by byl jen šum.
const guideVisible = ref(false)

const summary = ref<DashboardSummary | null>(null)
const loading = ref(true)
const error = ref('')

async function loadSummary() {
  // Žádný dodavatel → nenačítej summary (vrátilo by jen prázdná data), ukaž gate.
  if (!hasSupplier.value) {
    loading.value = false
    return
  }
  try {
    summary.value = await dashboardApi.summary()
  } catch (e: any) {
    error.value = e?.response?.data?.error?.message || t('errors.generic')
  } finally {
    loading.value = false
  }
}

onMounted(loadSummary)

// Výkaz práce modal — otevíráno z tlačítka „Výkaz" na kartě konceptu (stejný popup jako v /invoices).
const wrModalOpen = ref(false)
const wrModalInvoiceId = ref(0)
function openWorkReport(id: number) {
  wrModalInvoiceId.value = id
  wrModalOpen.value = true
}

const kpiGridCols = computed(() => {
  if (!summary.value) return 'lg:grid-cols-6'
  const showApprovals = canReviewApprovals.value
    && (summary.value.pending_approvals?.requested ?? 0) > 0
  const currencies = summary.value.kpi?.per_currency?.length ?? 0
  // Revenue tile spans 2 cols (lg:col-span-2), standardní boxy 1 col.
  // Sloty = currencies*2 + 4 standard (Vystaveno/Po splatnosti/Před splatností/Ø) [+ 1 schvalování]
  const slots = currencies * 2 + 4 + (showApprovals ? 1 : 0)
  // Tailwind musí vidět tyto třídy staticky — explicitní mapping.
  return ({
    6: 'lg:grid-cols-6',   // 1 měna: 1×wide + 4×slim, 1 řada
    7: 'lg:grid-cols-7',   // 1 měna + schvalování, 1 řada
    8: 'lg:grid-cols-4',   // 2 měny: 2 řady × 4 (revenue span 2)
    9: 'lg:grid-cols-3',   // 2 měny + schvalování
    10: 'lg:grid-cols-5',  // 3 měny: 2 řady × 5
  } as Record<number, string>)[slots] ?? 'lg:grid-cols-6'
})

const upcomingPerCurrency = computed(() => {
  if (!summary.value) return [] as Array<{ currency: string; total: number }>
  const map = new Map<string, number>()
  for (const i of summary.value.unpaid_upcoming) {
    map.set(i.currency, (map.get(i.currency) ?? 0) + Number(i.amount_to_pay || 0))
  }
  return Array.from(map, ([currency, total]) => ({ currency, total }))
})

const hasAnyData = computed(() => {
  if (!summary.value || !summary.value.kpi) return false
  return (summary.value.kpi.issued_count_ytd ?? 0) > 0
      || (summary.value.overdue?.length ?? 0) > 0
      || (summary.value.unpaid_upcoming?.length ?? 0) > 0
})

function openInvoice(id: number) {
  router.push(`/invoices/${id}`)
}

/**
 * Vrátí 12 posledních měsíců dat pro sparkline daného currency.
 * Bere `revenue_by_month[currency].months` (12 měsíců rolling).
 */
function sparklineFor(currency: string): { labels: string[]; values: number[] } {
  const rev = summary.value?.revenue_by_month.find(r => r.currency === currency)
  if (!rev) return { labels: [], values: [] }
  return {
    labels: rev.months.map(m => {
      const [y, mo] = m.ym.split('-')
      return `${mo}/${y}`
    }),
    values: rev.months.map(m => m.total),
  }
}

// Jen jedna (aktivní) měna → graf vlevo přes 2 řádky a boxy 2×2 vpravo.
const singleCurrency = computed(() => (summary.value?.kpi?.per_currency?.length ?? 0) === 1)

// Mini graf nákladů (přijaté faktury, 12 měsíců, CZK) — místo boxu CRM.
const costsSparkline = computed(() => {
  const arr = summary.value?.purchase_costs_by_month ?? []
  return {
    labels: arr.map(m => { const [y, mo] = m.ym.split('-'); return `${mo}/${y}` }),
    values: arr.map(m => m.total),
  }
})
const hasCostsData = computed(() => (summary.value?.purchase_costs_by_month ?? []).some(m => m.total !== 0))
</script>

<template>
  <div>
    <!-- Onboarding gate (#151): dodavatel přeskočen v setupu → bez něj nelze nic založit -->
    <div v-if="!hasSupplier" class="max-w-2xl mx-auto bg-surface border border-neutral-200 rounded-lg shadow-sm p-8 text-center">
      <div class="w-14 h-14 bg-primary-100 rounded-full mx-auto mb-4 flex items-center justify-center">
        <svg class="w-7 h-7 text-primary-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M19 21V5a2 2 0 0 0-2-2H7a2 2 0 0 0-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1v5m-4 0h4"/>
        </svg>
      </div>
      <h2 class="text-xl font-semibold mb-2">{{ t('dashboard.no_supplier.title') }}</h2>
      <p class="text-neutral-500 mb-6">{{ t('dashboard.no_supplier.intro') }}</p>
      <RouterLink v-if="canCreateSupplier" to="/admin/suppliers?create=supplier"
        class="px-5 h-10 inline-flex items-center bg-primary-600 hover:bg-primary-700 text-white text-sm font-medium rounded-md">
        {{ t('dashboard.no_supplier.cta_admin') }}
      </RouterLink>
      <p v-else class="text-sm text-neutral-600">{{ t('dashboard.no_supplier.non_admin') }}</p>
    </div>

    <template v-else>
    <div v-if="loading" class="text-center text-neutral-500 py-12">{{ t('dashboard.loading_data') }}</div>

    <div v-else-if="error" class="rounded-md bg-danger-50 border border-danger-500/40 px-3 py-2 text-sm text-danger-500">
      {{ error }}
    </div>

    <!-- Prázdný Přehled (typicky čerstvá instance ze SaaS provisioningu): místo
         holého „zatím tu nic není" ukážeme rozcestník po prvním nastavení.
         `EmptyState` zůstává pro readonly uživatele a pro chvíli, kdy si uživatel
         průvodce schová — bez něj by stránka byla úplně prázdná. -->
    <template v-else-if="!hasAnyData">
      <OnboardingGuide v-if="auth.canWrite('dashboard')" @update:visible="guideVisible = $event" />

      <EmptyState v-if="!guideVisible" boxed icon="chart"
        :title="t('dashboard.welcome')"
        :message="t('dashboard.empty_hint')"
        :cta="auth.canWrite('dashboard') ? t('client.new') : undefined"
        to="/clients/new"
        :secondary="auth.canWrite('dashboard') ? t('invoice.new') : undefined"
        secondary-to="/invoices/new"
        secondary-icon="doc"
        class="mt-6" />
    </template>

    <div v-else-if="summary && summary.kpi" class="space-y-6">
      <!-- Přehled byl jediná stránka v aplikaci bez H1 — začínal rovnou widgetem,
           takže nebylo vidět, čí čísla se vlastně čtou (a při přepínání mezi
           firmami to bylo znát nejvíc). Nadpisem je název aktivní firmy. -->
      <header>
        <h1 class="text-2xl font-semibold">{{ supplierStore.currentSupplier?.company_name ?? t('nav.dashboard') }}</h1>
        <p v-if="supplierIdentity" class="text-sm text-neutral-500 mt-0.5">{{ supplierIdentity }}</p>
      </header>

      <!-- „Akce pro tebe" a „Daňový kalendář" jsou obojí resty s termínem — na
           širokém monitoru patří vedle sebe, jinak jeden vytlačí druhý pod ohyb.
           `flex-wrap` s min-šířkou místo grid sloupců schválně: oba widgety se
           samy skrývají (readonly uživatel, neplátce DPH) a v gridu by po tom
           skrytém zůstala prázdná půlka. Takhle zbylý widget roztáhne celou
           šířku, protože prázdná komponenta nevytvoří flex položku.
           Min-šířka smí platit až od `sm`: 26rem je 416 px, takže na mobilu
           (374 px) karty přerostly viewport a `overflow-x: clip` na body jim
           uřízl pravý okraj i se stavovými štítky. Pod `sm` je proto plná
           šířka a widgety jdou pod sebe. -->
      <div class="flex flex-wrap items-start gap-4">
        <ActionItemsWidget v-if="auth.canWrite('dashboard')" class="flex-1 min-w-full sm:min-w-[26rem]" />
        <TaxCalendarWidget class="flex-1 min-w-full sm:min-w-[26rem]" />
      </div>

      <!-- ═══ Výkazy práce — rozpracované (draft) vystavené faktury k doplnění (skryto pro readonly) ═══ -->
      <section v-if="auth.canWrite('dashboard') && summary.draft_invoices && summary.draft_invoices.length" class="space-y-3">
        <!-- Sekční štítek: caps popisek + hairline k pravému okraji (.rule-label).
             Plovoucí barevné pilulky přetahovaly pozornost na nadpisy sekcí,
             i když nesou nejmíň informace na stránce. -->
        <h2 class="rule-label">
          <span>{{ t('dashboard.work_reports.title') }}</span>
          <span class="normal-case tracking-normal font-normal text-[11px] text-neutral-400">{{ t('dashboard.work_reports.hint') }}</span>
        </h2>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
          <div v-for="d in summary.draft_invoices" :key="d.id"
            class="bg-surface border border-neutral-200 rounded-lg p-4 shadow-sm flex flex-col gap-2">
            <div class="flex items-start justify-between gap-2">
              <RouterLink :to="`/clients/${d.client_id}`" class="font-medium text-neutral-900 hover:text-primary-700 hover:underline truncate" :title="d.client_company_name">
                {{ d.client_company_name }}
              </RouterLink>
              <span class="text-[10px] px-1.5 py-0.5 rounded bg-neutral-100 text-neutral-500 uppercase tracking-wide font-medium whitespace-nowrap">
                {{ t('status.draft') }}
              </span>
            </div>
            <div class="text-xs text-neutral-500 min-h-[1rem] truncate" :title="d.project_name || ''">
              <span v-if="d.project_name">📁 {{ d.project_name }}</span>
              <span v-else class="text-neutral-300">{{ t('dashboard.work_reports.no_project') }}</span>
            </div>
            <div class="flex items-center justify-between gap-2 mt-auto pt-2 border-t border-neutral-100">
              <span class="text-xs font-mono text-neutral-600 truncate">
                <span v-if="d.varsymbol">{{ d.varsymbol }} · </span>{{ formatMoney(d.total_with_vat, d.currency) }}
              </span>
              <div class="flex items-center gap-1.5 shrink-0">
                <RouterLink :to="`/invoices/${d.id}/edit`"
                  class="cursor-pointer inline-flex items-center justify-center h-6 px-2 rounded-md border border-neutral-300 text-neutral-700 hover:bg-neutral-50 text-xs font-medium">
                  {{ t('common.edit') }}
                </RouterLink>
                <button type="button" @click="openWorkReport(d.id)"
                  class="cursor-pointer inline-flex items-center gap-1 h-6 px-2 rounded-md bg-primary-600 hover:bg-primary-700 text-white text-xs font-medium">
                  <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 17v-6m3 6v-4m3 4v-2"/></svg>
                  {{ t('dashboard.work_reports.button') }}
                </button>
              </div>
            </div>
          </div>
        </div>
      </section>

      <!-- Daňový widget „co mi zbyde" — jen pro OSVČ (komponenta se sama skryje jinak) -->
      <TaxNetWidget />

      <!-- Nadcházející zálohy na daň a pojistné (E9) — self-gating -->
      <TaxAdvancesWidget />

      <!-- ═══ Sekce 1: VYSTAVENÉ FAKTURY ═══ -->
      <section class="space-y-3">
        <h2 class="rule-label">
          <span>{{ t('dashboard.section_issued') }}</span>
        </h2>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4" :class="singleCurrency ? 'lg:grid-cols-3' : 'lg:grid-cols-4'">
          <!-- Revenue card: 1 měna → vysoký vlevo (2 řádky); více měn → široký (2 sloupce) -->
          <div v-for="c in summary.kpi.per_currency" :key="c.currency"
            @click="router.push({ path: '/invoices', query: { year: String(summary.year), currency: c.currency } })"
            class="bg-surface-raised border border-neutral-200 rounded-xl p-6 shadow-md cursor-pointer hover:border-primary-300 hover:shadow-lg transition"
            :class="singleCurrency ? 'lg:row-span-2 flex flex-col' : 'md:col-span-2'">
            <!-- Hero KPI: obrat je jediné číslo, kvůli kterému se na dashboard chodí,
                 takže má být výrazně větší než ostatní boxy. Řez je ale MONO, ne
                 display serif — serif nese nadpis stránky a dvě různé věci ve stejném
                 řezu si konkurují; číslo navíc patří do téže rodiny jako částky
                 ve všech tabulkách. -->
            <div class="text-[11px] uppercase tracking-[0.14em] font-semibold text-neutral-500 mb-2">{{ t('dashboard.revenue', { year: summary.year, currency: c.currency }) }}</div>
            <!-- Velikost přes `clamp`, ne přes breakpointy: obrat je řetězec
                 proměnné délky s nedělitelnými mezerami, takže se nezalomí a na
                 320px displeji pevný `text-3xl` přetekl kartu. Na běžném mobilu
                 (390px) vyjde 29px ≈ původní `text-3xl`, od 600px strop 2,25rem
                 = původní `text-4xl`. -->
            <div class="font-mono text-[length:clamp(1.5rem,7.5vw,2.25rem)] font-semibold tracking-tight leading-[1.1] text-neutral-900 tabular-nums">{{ formatMoney(c.this_year, c.currency) }}</div>
            <div v-if="c.change_pct !== null" class="mt-2.5"
              :title="t('dashboard.yoy_ytd_tooltip', { year: summary.prev_year, total: formatMoney(c.prev_year, c.currency), ytd: formatMoney(c.prev_year_ytd, c.currency) })">
              <span class="inline-flex items-center gap-1 h-6 px-2 rounded-full text-xs font-semibold tabular-nums"
                :class="c.change_pct >= 0 ? 'bg-success-50 text-success-600' : 'bg-danger-50 text-danger-600'">
                {{ c.change_pct >= 0 ? '▲' : '▼' }} {{ Math.abs(c.change_pct) }} %
              </span>
              <span class="ml-1.5 text-xs text-neutral-500">{{ t('dashboard.vs_prev_ytd', { year: summary.prev_year }) }}</span>
            </div>
            <div v-else class="text-xs text-neutral-400 mt-2.5">{{ t('dashboard.no_prev_year', { year: summary.prev_year }) }}</div>
            <div v-if="sparklineFor(c.currency).values.some(v => v !== 0)"
              :class="singleCurrency ? 'mt-3 flex-1 flex items-end' : 'mt-3'">
              <!-- Bez loňských dat se linka průměru nekreslí: na kartě, která
                   nenabízí žádné srovnání, si ji uživatel vyloží jako „loňskou
                   úroveň" — a to není. -->
              <SparklineChart
                class="w-full"
                :labels="sparklineFor(c.currency).labels"
                :values="sparklineFor(c.currency).values"
                :format="(v: number) => formatMoney(v, c.currency)"
                :height="singleCurrency ? 150 : 40"
                :show-average="c.change_pct !== null"
              />
            </div>
          </div>

          <!-- 4 single-column boxes: issued count, overdue, upcoming, avg payment -->
          <div @click="router.push({ path: '/invoices', query: { year: String(summary.year) } })"
            class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm cursor-pointer hover:border-primary-300 transition">
            <div class="text-[11px] uppercase tracking-[0.12em] font-semibold text-neutral-500 mb-1.5">{{ t('dashboard.issued_count', { year: summary.year }) }}</div>
            <div class="text-xl font-semibold tabular-nums text-neutral-900">{{ summary.kpi.issued_count_ytd }}</div>
            <div class="text-xs text-neutral-400 mt-1">{{ t('dashboard.invoices_unit') }}</div>
          </div>

          <div @click="router.push({ path: '/invoices', query: { year: 'all', overdue: '1' } })"
            class="bg-surface border rounded-lg p-5 shadow-sm cursor-pointer hover:border-primary-300 transition" :class="summary.kpi.overdue_count > 0 ? 'border-danger-500/40' : 'border-neutral-200'">
            <div class="text-[11px] uppercase tracking-[0.12em] font-semibold text-neutral-500 mb-1.5">{{ t('dashboard.overdue') }}</div>
            <div class="text-xl font-semibold tabular-nums" :class="summary.kpi.overdue_count > 0 ? 'text-danger-500' : 'text-neutral-900'">
              {{ summary.kpi.overdue_count }}
            </div>
            <div class="text-xs mt-1 flex flex-wrap gap-x-3" :class="summary.kpi.overdue_count > 0 ? 'text-danger-500' : 'text-neutral-400'">
              <span v-for="o in summary.kpi.overdue_per_currency" :key="o.currency">{{ formatMoney(o.total, o.currency) }}</span>
              <span v-if="summary.kpi.overdue_count === 0">{{ t('dashboard.all_ok') }}</span>
            </div>
          </div>

          <div @click="router.push({ path: '/invoices', query: { year: 'all', unpaid: '1' } })"
            class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm cursor-pointer hover:border-primary-300 transition">
            <div class="text-[11px] uppercase tracking-[0.12em] font-semibold text-neutral-500 mb-1.5">{{ t('dashboard.upcoming') }}</div>
            <div class="text-xl font-semibold tabular-nums text-neutral-900">{{ summary.unpaid_upcoming.length }}</div>
            <div class="text-xs mt-1 text-neutral-400 flex flex-wrap gap-x-3">
              <span v-for="u in upcomingPerCurrency" :key="u.currency">{{ formatMoney(u.total, u.currency) }}</span>
              <span v-if="!upcomingPerCurrency.length">{{ t('dashboard.upcoming_none') }}</span>
            </div>
          </div>

          <div @click="router.push('/stats')"
            class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm cursor-pointer hover:border-primary-300 transition">
            <div class="text-[11px] uppercase tracking-[0.12em] font-semibold text-neutral-500 mb-1.5">{{ t('dashboard.avg_payment') }}</div>
            <div class="text-xl font-semibold tabular-nums text-neutral-900">
              {{ formatNumber(summary.kpi.avg_payment_days, { maximumFractionDigits: 1 }) }} {{ summary.kpi.avg_payment_days !== null ? t('dashboard.days') : '' }}
            </div>
            <div class="text-xs text-neutral-400 mt-1">{{ t('dashboard.this_year_paid') }}</div>
          </div>
        </div>
      </section>

      <!-- ═══ Sekce 2: PŘIJATÉ FAKTURY (visible jen pokud existují YTD) ═══ -->
      <section v-if="(summary.kpi.purchase_count_ytd ?? 0) > 0" class="space-y-3">
        <h2 class="rule-label">
          <span>{{ t('dashboard.section_purchase') }}</span>
        </h2>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
          <!-- Costs YTD -->
          <div @click="router.push({ path: '/purchase-invoices', query: { year: String(summary.year) } })"
            class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm cursor-pointer hover:border-primary-300 transition">
            <div class="text-[11px] uppercase tracking-[0.12em] font-semibold text-neutral-500 mb-1.5">{{ t('dashboard.purchase_costs_ytd', { year: summary.year }) }}</div>
            <div class="text-xl font-semibold tabular-nums text-neutral-900 font-mono">{{ formatMoney(summary.kpi.purchase_costs_ytd, 'CZK') }}</div>
            <div class="text-xs text-neutral-400 mt-1">{{ summary.kpi.purchase_count_ytd }} {{ t('dashboard.invoices_unit') }}</div>
          </div>

          <!-- Unpaid -->
          <div @click="router.push({ path: '/purchase-invoices', query: { unpaid: '1' } })"
            class="bg-surface border rounded-lg p-5 shadow-sm cursor-pointer hover:border-primary-300 transition"
            :class="(summary.kpi.purchase_unpaid_count ?? 0) > 0 ? 'border-warning-500/40' : 'border-neutral-200'">
            <div class="text-[11px] uppercase tracking-[0.12em] font-semibold text-neutral-500 mb-1.5">{{ t('dashboard.purchase_unpaid') }}</div>
            <div class="text-xl font-semibold tabular-nums"
              :class="(summary.kpi.purchase_unpaid_count ?? 0) > 0 ? 'text-warning-600' : 'text-neutral-900'">
              {{ summary.kpi.purchase_unpaid_count }}
            </div>
            <div class="text-xs mt-1"
              :class="(summary.kpi.purchase_unpaid_count ?? 0) > 0 ? 'text-warning-600' : 'text-neutral-400'">
              {{ formatMoney(summary.kpi.purchase_unpaid_total, 'CZK') }}
            </div>
          </div>

          <!-- Overdue (vždy zobrazené pro grid alignment, červené pokud > 0) -->
          <div @click="router.push({ path: '/purchase-invoices', query: { overdue: '1' } })"
            class="bg-surface border rounded-lg p-5 shadow-sm cursor-pointer hover:border-primary-300 transition"
            :class="(summary.kpi.purchase_overdue_count ?? 0) > 0 ? 'border-danger-500/40' : 'border-neutral-200'">
            <div class="text-[11px] uppercase tracking-[0.12em] font-semibold text-neutral-500 mb-1.5">{{ t('dashboard.purchase_overdue') }}</div>
            <div class="text-xl font-semibold tabular-nums"
              :class="(summary.kpi.purchase_overdue_count ?? 0) > 0 ? 'text-danger-500' : 'text-neutral-900'">
              {{ summary.kpi.purchase_overdue_count }}
            </div>
            <div class="text-xs mt-1"
              :class="(summary.kpi.purchase_overdue_count ?? 0) > 0 ? 'text-danger-500' : 'text-neutral-400'">
              <span v-if="(summary.kpi.purchase_overdue_count ?? 0) > 0" class="hover:underline">
                {{ t('dashboard.purchase_overdue_link') }}
              </span>
              <span v-else>{{ t('dashboard.all_ok') }}</span>
            </div>
          </div>

          <!-- 4. box: mini graf nákladů (12 měsíců, CZK) — odkaz do CRM analytics -->
          <RouterLink to="/crm"
            class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm hover:bg-neutral-50 hover:border-primary-300 transition flex flex-col">
            <div class="text-[11px] uppercase tracking-[0.12em] font-semibold text-neutral-500 mb-1.5">{{ t('dashboard.costs_trend_12m') }}</div>
            <div v-if="hasCostsData" class="mt-1 flex-1 flex items-end">
              <SparklineChart class="w-full"
                :labels="costsSparkline.labels" :values="costsSparkline.values"
                :format="(v: number) => formatMoney(v, 'CZK')" color="#D9822B" :height="48" />
            </div>
            <div v-else class="text-xl font-semibold tabular-nums text-primary-700">CRM →</div>
            <div class="text-xs text-neutral-400 mt-1">{{ t('dashboard.costs_trend_hint') }}</div>
          </RouterLink>
        </div>
      </section>

      <div class="grid grid-cols-1 md:grid-cols-2 gap-4" :class="kpiGridCols">
        <!-- Pending approvals tile (admin only, jen pokud existují requested) -->
        <RouterLink
          v-if="canReviewApprovals && summary.pending_approvals && summary.pending_approvals.requested > 0"
          to="/admin/approvals"
          class="bg-surface border rounded-lg p-5 shadow-sm hover:bg-primary-50 transition cursor-pointer"
          :class="summary.pending_approvals.overdue > 0 ? 'border-warning-500/50' : 'border-primary-500/40'">
          <div class="text-[11px] uppercase tracking-[0.12em] font-semibold text-neutral-500 mb-1.5">{{ t('dashboard.pending_approvals') }}</div>
          <div class="text-xl font-semibold tabular-nums"
            :class="summary.pending_approvals.overdue > 0 ? 'text-warning-600' : 'text-primary-700'">
            {{ summary.pending_approvals.requested }}
          </div>
          <div class="text-xs mt-1"
            :class="summary.pending_approvals.overdue > 0 ? 'text-warning-600' : 'text-neutral-400'">
            <span v-if="summary.pending_approvals.overdue > 0">
              {{ t('dashboard.pending_approvals_overdue', { n: summary.pending_approvals.overdue }) }}
            </span>
            <span v-else>{{ t('dashboard.pending_approvals_hint') }}</span>
          </div>
        </RouterLink>

        <!-- Vyžádání chybějících dokladů od klienta (Fáze F, audit 2026-07) -->
        <RouterLink
          v-if="(summary.document_requests_open?.open ?? 0) > 0"
          to="/document-requests"
          class="bg-surface border rounded-lg p-5 shadow-sm hover:bg-primary-50 transition cursor-pointer"
          :class="(summary.document_requests_open?.overdue ?? 0) > 0 ? 'border-danger-500/50' : 'border-neutral-200'">
          <div class="text-[11px] uppercase tracking-[0.12em] font-semibold text-neutral-500 mb-1.5">{{ t('dashboard.document_requests_open') }}</div>
          <div class="text-xl font-semibold tabular-nums"
            :class="(summary.document_requests_open?.overdue ?? 0) > 0 ? 'text-danger-500' : 'text-neutral-900'">
            {{ summary.document_requests_open?.open ?? 0 }}
          </div>
          <div class="text-xs mt-1"
            :class="(summary.document_requests_open?.overdue ?? 0) > 0 ? 'text-danger-500' : 'text-neutral-400'">
            <span v-if="(summary.document_requests_open?.overdue ?? 0) > 0">
              {{ t('dashboard.document_requests_overdue', { n: summary.document_requests_open?.overdue ?? 0 }) }}
            </span>
            <span v-else>{{ t('dashboard.document_requests_hint') }}</span>
          </div>
        </RouterLink>

        <!-- Bank posting: platby čekající na zaúčtování (jen double_entry) -->
        <RouterLink
          v-if="isDoubleEntry && (summary.accounting_backfill_pending ?? 0) > 0"
          :to="{ name: 'accounting-activation' }"
          class="bg-surface border border-warning-500/50 rounded-lg p-5 shadow-sm hover:bg-warning-50 transition cursor-pointer">
          <div class="text-[11px] uppercase tracking-[0.12em] font-semibold text-neutral-500 mb-1.5">{{ t('activation.title') }}</div>
          <div class="text-xl font-semibold tabular-nums text-warning-600">{{ summary.accounting_backfill_pending }}</div>
          <div class="text-xs mt-1 text-neutral-400">
            {{ t('activation.dashboard_card') }}
          </div>
        </RouterLink>

        <RouterLink
          v-if="isDoubleEntry && (summary.bank_posting_pending ?? 0) > 0"
          to="/automation?tab=pending"
          class="bg-surface border border-warning-500/50 rounded-lg p-5 shadow-sm hover:bg-warning-50 transition cursor-pointer">
          <div class="text-[11px] uppercase tracking-[0.12em] font-semibold text-neutral-500 mb-1.5">{{ t('bank.posting.tab_suggestions') }}</div>
          <div class="text-xl font-semibold tabular-nums text-warning-600">{{ summary.bank_posting_pending }}</div>
          <div class="text-xs mt-1 text-neutral-400">
            {{ t('bank.posting.dashboard_card', { count: summary.bank_posting_pending }) }}
          </div>
        </RouterLink>

        <RouterLink
          v-if="isDoubleEntry && (summary.automation_auto_today ?? 0) > 0"
          to="/automation?tab=auto"
          class="bg-surface border border-success-500/40 rounded-lg p-5 shadow-sm hover:bg-success-50 transition cursor-pointer">
          <div class="text-[11px] uppercase tracking-[0.12em] font-semibold text-neutral-500 mb-1.5">{{ t('automation.auto_today_card') }}</div>
          <div class="text-xl font-semibold tabular-nums text-success-600">{{ summary.automation_auto_today }}</div>
          <div class="text-xs mt-1 text-neutral-400">{{ t('automation.tab_auto', { n: summary.automation_auto_today }) }}</div>
        </RouterLink>
      </div>

      <!-- ═══ Sekce 3: SPLATNOST POHLEDÁVEK (vystavené, příchozí platby) ═══ -->
      <section v-if="summary.due_buckets.length" class="space-y-3">
        <h2 class="rule-label">
          <span>{{ t('dashboard.section_receivables_due') }}</span>
          <span class="normal-case tracking-normal font-normal text-[11px] text-neutral-400">{{ t('dashboard.receivables_hint') }}</span>
        </h2>
      <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div v-for="b in summary.due_buckets" :key="`db-today-${b.currency}`"
          @click="router.push({ path: '/invoices', query: { year: 'all', unpaid: '1' } })"
          class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm cursor-pointer hover:border-primary-300 transition"
          :class="{ 'border-warning-500/40 bg-warning-50/20': b.today_count > 0 }">
          <div class="text-[11px] uppercase tracking-[0.12em] font-semibold text-neutral-500 mb-1.5">{{ t('dashboard.due_today') }}</div>
          <!-- Nula je pořád údaj, ne placeholder: text-neutral-300 je v dark módu
               (#3B3656) na tmavé kartě prakticky neviditelný. -->
          <div class="text-xl font-semibold tabular-nums" :class="b.today_count > 0 ? 'text-warning-600' : 'text-neutral-400'">
            {{ b.today_count }}
          </div>
          <div class="text-xs mt-1 font-mono" :class="b.today_count > 0 ? 'text-neutral-700' : 'text-neutral-400'">
            {{ formatMoney(b.today_total, b.currency) }}
          </div>
        </div>
        <div v-for="b in summary.due_buckets" :key="`db-week-${b.currency}`"
          @click="router.push({ path: '/invoices', query: { year: 'all', unpaid: '1' } })"
          class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm cursor-pointer hover:border-primary-300 transition">
          <div class="text-[11px] uppercase tracking-[0.12em] font-semibold text-neutral-500 mb-1.5">{{ t('dashboard.due_this_week') }}</div>
          <div class="text-xl font-semibold tabular-nums text-neutral-900">{{ b.week_count }}</div>
          <div class="text-xs mt-1 font-mono text-neutral-500">{{ formatMoney(b.week_total, b.currency) }}</div>
        </div>
        <div v-for="b in summary.due_buckets" :key="`db-month-${b.currency}`"
          @click="router.push({ path: '/invoices', query: { year: 'all', unpaid: '1' } })"
          class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm cursor-pointer hover:border-primary-300 transition">
          <div class="text-[11px] uppercase tracking-[0.12em] font-semibold text-neutral-500 mb-1.5">{{ t('dashboard.due_this_month') }}</div>
          <div class="text-xl font-semibold tabular-nums text-neutral-900">{{ b.month_count }}</div>
          <div class="text-xs mt-1 font-mono text-neutral-500">{{ formatMoney(b.month_total, b.currency) }}</div>
        </div>
      </div>
      </section>

      <!-- Cash-flow forecast 30 / 60 / 90 dní — kolik se očekává inkasovat -->
      <div v-if="summary.cashflow_forecast.length" class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm">
        <div class="flex flex-col gap-1 mb-4 sm:flex-row sm:items-baseline sm:justify-between sm:gap-3">
          <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('dashboard.cashflow_forecast') }}</h3>
          <span class="text-xs text-neutral-400">{{ t('dashboard.cashflow_forecast_hint') }}</span>
        </div>
        <!-- Tři sloupce mají smysl jen tam, kde se do nich vejde částka: `1fr`
             je v Tailwindu `minmax(0,1fr)`, takže se na mobilu stlačily na ~87 px,
             kdežto „711 177,50 Kč" potřebuje 140 px a nezalomí se (nedělitelné
             mezery). Čísla si pak lezla přes sebe a přes okraj karty. Pod `sm`
             proto řádek na řádek: popisek vlevo, částky vpravo. -->
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 sm:gap-4">
          <div v-for="period in ['30','60','90']" :key="`cf-${period}`"
            class="flex items-baseline justify-between gap-3 sm:block sm:text-center">
            <div class="text-[11px] uppercase tracking-[0.12em] font-semibold text-neutral-500 sm:mb-1.5">{{ t('dashboard.cashflow_in_days', { n: period }) }}</div>
            <div class="text-right sm:text-center">
              <div class="space-y-0.5">
                <div v-for="cf in summary.cashflow_forecast" :key="`cf-${period}-${cf.currency}`"
                  class="font-mono text-base sm:text-lg font-semibold text-neutral-900">
                  {{ formatMoney(period === '30' ? cf.in_30 : period === '60' ? cf.in_60 : cf.in_90, cf.currency) }}
                </div>
              </div>
              <div class="text-xs text-neutral-500 mt-1 space-y-0.5">
                <div v-for="cf in summary.cashflow_forecast" :key="`cf-cnt-${period}-${cf.currency}`">
                  {{ period === '30' ? cf.count_30 : period === '60' ? cf.count_60 : cf.count_90 }} {{ t('dashboard.invoices_unit') }}
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <!-- Po splatnosti -->
        <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
          <header class="px-5 py-3 border-b border-neutral-200 flex items-center justify-between">
            <h3 class="font-semibold">{{ t('dashboard.overdue_table') }}</h3>
            <div class="flex items-center gap-2">
              <span v-if="summary.overdue.length" class="text-xs px-2 py-0.5 rounded bg-danger-50 text-danger-500">
                {{ summary.overdue.length }}
              </span>
              <RouterLink :to="{ path: '/invoices', query: { year: 'all', overdue: '1' } }"
                class="text-xs text-primary-600 hover:text-primary-700 hover:underline">
                {{ t('common.view_all') }}
              </RouterLink>
            </div>
          </header>
          <div v-if="!summary.overdue.length" class="p-6 text-center text-sm text-neutral-500">
            {{ t('dashboard.overdue_none') }}
          </div>
          <!-- Desktop: tabulka -->
          <div v-else class="hidden md:block overflow-x-auto"><table class="w-full text-sm table-sticky-first">
            <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
              <tr>
                <th class="px-3 py-2 text-left font-medium">{{ t('type.invoice') }}</th>
                <th class="px-3 py-2 text-left font-medium">{{ t('nav.clients') }}</th>
                <th class="px-3 py-2 text-right font-medium">{{ t('invoice.amount_to_pay') }}</th>
                <th class="px-3 py-2 text-center font-medium">{{ t('dashboard.overdue') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="i in summary.overdue" :key="i.id" @click="openInvoice(i.id)" class="cursor-pointer hover:bg-neutral-50">
                <td class="px-3 py-2 font-mono text-xs">
                  <RouterLink class="row-link" :to="`/invoices/${i.id}`" @click.stop @auxclick.stop>{{ i.varsymbol }}</RouterLink>
                  <span v-if="i.invoice_type === 'proforma'" class="ml-1 px-1.5 py-0.5 rounded bg-primary-50 text-primary-700 text-[10px] font-sans font-medium uppercase tracking-wide">{{ t('type.proforma') }}</span>
                </td>
                <td class="px-3 py-2 truncate max-w-[200px]">{{ i.client_company_name }}</td>
                <td class="px-3 py-2 text-right font-mono text-xs">{{ formatMoney(i.amount_to_pay, i.currency) }}</td>
                <td class="px-3 py-2 text-center">
                  <span class="text-xs px-1.5 py-0.5 rounded bg-danger-50 text-danger-500 font-medium">
                    +{{ i.days_overdue }}d
                  </span>
                </td>
              </tr>
            </tbody>
          </table></div>

          <!-- Mobile: kompaktní list -->
          <div v-if="summary.overdue.length" class="md:hidden divide-y divide-neutral-100">
            <div v-for="i in summary.overdue" :key="`m-${i.id}`" @click="openInvoice(i.id)"
              class="cursor-pointer hover:bg-neutral-50 px-3 py-2.5">
              <div class="flex items-baseline justify-between gap-2">
                <div class="font-medium text-neutral-900 truncate">{{ i.client_company_name }}</div>
                <div class="font-mono text-sm whitespace-nowrap">{{ formatMoney(i.amount_to_pay, i.currency) }}</div>
              </div>
              <div class="flex items-baseline justify-between gap-2 mt-0.5">
                <span class="flex items-center gap-1.5 min-w-0">
                  <RouterLink class="row-link font-mono text-xs text-neutral-500" :to="`/invoices/${i.id}`" @click.stop @auxclick.stop>{{ i.varsymbol }}</RouterLink>
                  <span v-if="i.invoice_type === 'proforma'" class="px-1.5 py-0.5 rounded bg-primary-50 text-primary-700 text-[10px] font-medium uppercase tracking-wide">{{ t('type.proforma') }}</span>
                </span>
                <span class="text-xs px-1.5 py-0.5 rounded bg-danger-50 text-danger-500 font-medium whitespace-nowrap">
                  +{{ i.days_overdue }}d
                </span>
              </div>
            </div>
          </div>
        </div>

        <!-- Nezaplacené -->
        <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
          <header class="px-5 py-3 border-b border-neutral-200 flex items-center justify-between">
            <h3 class="font-semibold">{{ t('dashboard.unpaid_upcoming') }}</h3>
            <div class="flex items-center gap-2">
              <span v-if="summary.unpaid_upcoming.length" class="text-xs px-2 py-0.5 rounded bg-primary-100 text-primary-700">
                {{ summary.unpaid_upcoming.length }}
              </span>
              <RouterLink :to="{ path: '/invoices', query: { year: 'all', unpaid: '1' } }"
                class="text-xs text-primary-600 hover:text-primary-700 hover:underline">
                {{ t('common.view_all') }}
              </RouterLink>
            </div>
          </header>
          <div v-if="!summary.unpaid_upcoming.length" class="p-6 text-center text-sm text-neutral-500">
            {{ t('dashboard.unpaid_none') }}
          </div>
          <!-- Desktop: tabulka -->
          <div v-else class="hidden md:block overflow-x-auto"><table class="w-full text-sm table-sticky-first">
            <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
              <tr>
                <th class="px-3 py-2 text-left font-medium">{{ t('type.invoice') }}</th>
                <th class="px-3 py-2 text-left font-medium">{{ t('nav.clients') }}</th>
                <th class="px-3 py-2 text-right font-medium">{{ t('invoice.amount_to_pay') }}</th>
                <th class="px-3 py-2 text-center font-medium">{{ t('invoice.due_date') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="i in summary.unpaid_upcoming" :key="i.id" @click="openInvoice(i.id)" class="cursor-pointer hover:bg-neutral-50">
                <td class="px-3 py-2 font-mono text-xs">
                  <RouterLink class="row-link" :to="`/invoices/${i.id}`" @click.stop @auxclick.stop>{{ i.varsymbol }}</RouterLink>
                </td>
                <td class="px-3 py-2 truncate max-w-[200px]">{{ i.client_company_name }}</td>
                <td class="px-3 py-2 text-right font-mono text-xs">{{ formatMoney(i.amount_to_pay, i.currency) }}</td>
                <td class="px-3 py-2 text-center text-xs">{{ formatDate(i.due_date) }}</td>
              </tr>
            </tbody>
          </table></div>

          <!-- Mobile: kompaktní list -->
          <div v-if="summary.unpaid_upcoming.length" class="md:hidden divide-y divide-neutral-100">
            <div v-for="i in summary.unpaid_upcoming" :key="`m-${i.id}`" @click="openInvoice(i.id)"
              class="cursor-pointer hover:bg-neutral-50 px-3 py-2.5">
              <div class="flex items-baseline justify-between gap-2">
                <div class="font-medium text-neutral-900 truncate">{{ i.client_company_name }}</div>
                <div class="font-mono text-sm whitespace-nowrap">{{ formatMoney(i.amount_to_pay, i.currency) }}</div>
              </div>
              <div class="flex items-baseline justify-between gap-2 mt-0.5 text-xs text-neutral-500">
                <RouterLink class="row-link font-mono" :to="`/invoices/${i.id}`" @click.stop @auxclick.stop>{{ i.varsymbol }}</RouterLink>
                <span class="font-mono">{{ formatDate(i.due_date) }}</span>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Top klienti — posledních 12 měsíců: tabulka vlevo, koláč vpravo -->
      <div v-if="summary.top_clients_12m.length" class="grid grid-cols-1 lg:grid-cols-2 gap-4 items-start">
      <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
        <header class="px-5 py-3 border-b border-neutral-200">
          <h3 class="font-semibold">{{ t('dashboard.top_clients_12m') }}</h3>
        </header>
        <!-- Desktop: tabulka -->
        <div class="hidden md:block overflow-x-auto">
        <table class="w-full text-sm table-sticky-first">
          <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
            <tr>
              <th class="px-4 py-2 text-left font-medium w-8">#</th>
              <th class="px-4 py-2 text-left font-medium">{{ t('nav.clients') }}</th>
              <th class="px-4 py-2 text-center font-medium">Faktur</th>
              <th class="px-4 py-2 text-right font-medium">Obrat</th>
              <th class="px-4 py-2 text-left font-medium w-32">{{ t('common.share') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="(c, i) in summary.top_clients_12m" :key="c.client_id" class="hover:bg-neutral-50 cursor-pointer"
                @click="router.push(`/clients/${c.client_id}`)">
              <td class="px-4 py-2.5 text-neutral-400 font-mono text-xs">{{ i + 1 }}</td>
              <td class="px-4 py-2.5 font-medium">
                <RouterLink class="row-link" :to="`/clients/${c.client_id}`" @click.stop @auxclick.stop>{{ c.company_name }}</RouterLink>
                <span v-if="c.currencies && c.currencies !== 'CZK'" class="ml-1.5 text-xs text-neutral-400 font-normal">({{ c.currencies }})</span>
              </td>
              <td class="px-4 py-2.5 text-center text-xs text-neutral-600">{{ c.invoice_count }}</td>
              <td class="px-4 py-2.5 text-right font-mono">{{ formatMoney(c.total_czk, 'CZK') }}</td>
              <td class="px-4 py-2.5">
                <div class="h-2 bg-neutral-100 rounded-full overflow-hidden">
                  <div class="h-full bg-primary-500 rounded-full" :style="{ width: (c.total_czk / summary.top_clients_12m[0].total_czk * 100) + '%' }"></div>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
        </div>

        <!-- Mobile: kompaktní list s share bar -->
        <div class="md:hidden divide-y divide-neutral-100">
          <div v-for="(c, i) in summary.top_clients_12m" :key="`m-${c.client_id}`"
            @click="router.push(`/clients/${c.client_id}`)"
            class="cursor-pointer hover:bg-neutral-50 px-3 py-2.5">
            <div class="flex items-baseline justify-between gap-2">
              <div class="flex items-baseline gap-2 min-w-0">
                <span class="text-neutral-400 font-mono text-xs whitespace-nowrap">{{ i + 1 }}.</span>
                <RouterLink class="row-link font-medium text-neutral-900 truncate" :to="`/clients/${c.client_id}`" @click.stop @auxclick.stop>{{ c.company_name }}</RouterLink>
                <span v-if="c.currencies && c.currencies !== 'CZK'" class="text-xs text-neutral-400 whitespace-nowrap">({{ c.currencies }})</span>
              </div>
              <div class="font-mono text-sm whitespace-nowrap">{{ formatMoney(c.total_czk, 'CZK') }}</div>
            </div>
            <div class="flex items-center gap-2 mt-1.5">
              <div class="h-1.5 flex-1 bg-neutral-100 rounded-full overflow-hidden">
                <div class="h-full bg-primary-500 rounded-full" :style="{ width: (c.total_czk / summary.top_clients_12m[0].total_czk * 100) + '%' }"></div>
              </div>
              <span class="text-xs text-neutral-500 font-mono whitespace-nowrap">{{ c.invoice_count }}×</span>
            </div>
          </div>
        </div>
      </div>

      <!-- Koláč Top klienti — totožná data, druhý úhel pohledu (vždy v CZK po přepočtu) -->
      <div class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm">
        <div class="flex items-baseline justify-between mb-4">
          <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('dashboard.top_clients_12m_share') }}</h3>
          <span class="text-xs font-mono text-neutral-500">CZK</span>
        </div>
        <TopClientsPieChart :clients="summary.top_clients_12m" />
      </div>
      </div>
    </div>
    </template>

    <!-- Výkaz práce modal — otevřený z tlačítka „Výkaz" na kartě konceptu. -->
    <WorkReportModal v-if="wrModalInvoiceId > 0"
      v-model="wrModalOpen"
      :invoice-id="wrModalInvoiceId"
      @saved="loadSummary" />
  </div>
</template>

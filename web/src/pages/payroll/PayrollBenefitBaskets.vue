<script setup lang="ts">
/**
 * Přehled čerpání košů osvobození za firmu (§ 6 odst. 9 ZDP).
 *
 * Dvě záložky, protože rozhodné období si koš nevybírá — plyne z ustanovení.
 * Roční záložka ukazuje koše písm. d) a m), měsíční koše písm. b) a i). Množiny
 * se nepřekrývají, takže přepnutí záložky zahodí i filtr na koš: server by ho
 * ve druhém režimu odmítl.
 *
 * PAST U STRAVNÉHO: limit podle písm. b) je za JEDNU SMĚNU, ale mzdový vstup je
 * měsíční. Měsíční součet se proti limitu za směnu poměřit nedá, takže server
 * u takového řádku limit netvrdí (`limit_basis = "per_shift"`) a obrazovka to
 * musí říct větou. Prázdný sloupec limitu bez věty by se četl jako „v pořádku".
 *
 * Náhled mzdového vstupu ukáže koš jen tomu, kdo ten vstup zrovna zadává —
 * účetní se tedy o blížícím se limitu dozví typicky v prosinci, kdy už se s tím
 * nedá nic dělat. Tahle obrazovka se ptá opačně: kdo z celé firmy je limitu
 * blízko a u koho už se nadlimitní část zdanila.
 *
 * Čísla se sem NEPOČÍTAJÍ. Osvobozená i nadlimitní část jsou zmrazené ze
 * schválení vstupu, takže sedí s výplatní páskou; server je jen sečte za osobu
 * a koš. Klient z nich odvozuje jedinou věc, a to šířku ukazatele.
 */
import { computed, onMounted, ref, watch, useId } from 'vue'
import { useI18n } from 'vue-i18n'
import {
  BENEFIT_EXEMPTION_BASKETS,
  MONTHLY_BENEFIT_EXEMPTION_BASKETS,
  payrollBenefitBasketsApi,
  type BenefitBasketStatus,
  type BenefitBasketUsage,
} from '@/api/payrollBenefitBaskets'
import type { PayrollBenefitExemptionBasket } from '@/api/payroll'
import { apiErrorMessage } from '@/api/errors'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import ColumnPicker from '@/components/ui/ColumnPicker.vue'
import DensityToggle from '@/components/ui/DensityToggle.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import PaginationBar from '@/components/ui/PaginationBar.vue'
import { formatMoneyMinor as money } from '@/composables/useFormat'
import { useTablePrefs, type ColumnDef } from '@/composables/useTablePrefs'

const { t } = useI18n()
const pageId = useId()

const PAGE_SIZE = 50

type BasketTab = 'annual' | 'monthly'

/** Aktuální měsíc jako `YYYY-MM`; `toISOString` by posunul lednový začátek o TZ. */
function thisMonth(): string {
  const now = new Date()
  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`
}

const items = ref<BenefitBasketUsage[]>([])
const years = ref<number[]>([])
const periods = ref<string[]>([])
const total = ref(0)
const offset = ref(0)
const loading = ref(true)
const failed = ref(false)
const error = ref('')

const tab = ref<BasketTab>('annual')
const year = ref(new Date().getFullYear())
const period = ref(thisMonth())
const basket = ref<PayrollBenefitExemptionBasket | ''>('')
const search = ref('')

const monthly = computed(() => tab.value === 'monthly')
// Popisky se skládají z LITERÁLNÍCH klíčů, ne z `${tab}` — statická i18n brána
// (`web/scripts/check-i18n.mjs`) na složený klíč nedosáhne a chybějící překlad
// by se projevil až syrovým kódem na obrazovce.
const tabs = computed<{ key: BasketTab; label: string }[]>(() => [
  { key: 'annual', label: t('payroll.benefit_baskets.tab_annual') },
  { key: 'monthly', label: t('payroll.benefit_baskets.tab_monthly') },
])
const basketOptions = computed(() =>
  monthly.value ? MONTHLY_BENEFIT_EXEMPTION_BASKETS : BENEFIT_EXEMPTION_BASKETS,
)

const currentPage = computed(() => Math.floor(offset.value / PAGE_SIZE) + 1)

/**
 * Sloupce se neřadí. Řadit by šla jen načtená stránka, takže „nejvíc vyčerpané
 * nahoře" by platilo vždy jen uvnitř padesátky — a to je horší než neřadit.
 */
const COLUMNS: ColumnDef[] = [
  { key: 'employee', labelKey: 'payroll.benefit_baskets.col.employee', required: true },
  { key: 'basket', labelKey: 'payroll.benefit_baskets.col.basket' },
  { key: 'statute', labelKey: 'payroll.benefit_baskets.col.statute', defaultHidden: true },
  { key: 'used', labelKey: 'payroll.benefit_baskets.col.used' },
  { key: 'limit', labelKey: 'payroll.benefit_baskets.col.limit' },
  { key: 'remaining', labelKey: 'payroll.benefit_baskets.col.remaining' },
  { key: 'taxable', labelKey: 'payroll.benefit_baskets.col.taxable' },
  { key: 'inputs', labelKey: 'payroll.benefit_baskets.col.inputs', defaultHidden: true },
  { key: 'status', labelKey: 'payroll.benefit_baskets.col.status' },
]
const tbl = useTablePrefs('payroll-benefit-baskets', COLUMNS)

const STATUS_BADGE: Record<BenefitBasketStatus, string> = {
  ok: 'bg-neutral-100 text-neutral-600',
  approaching: 'bg-warning-50 text-warning-700',
  exceeded: 'bg-danger-50 text-danger-600',
  incomplete: 'bg-neutral-100 text-neutral-600',
  limit_unavailable: 'bg-neutral-100 text-neutral-600',
}

const BAR_COLOR: Record<BenefitBasketStatus, string> = {
  ok: 'bg-success-500',
  approaching: 'bg-warning-500',
  exceeded: 'bg-danger-500',
  incomplete: 'bg-neutral-300',
  limit_unavailable: 'bg-neutral-300',
}

async function load() {
  loading.value = true
  error.value = ''
  try {
    if (monthly.value) {
      const data = await payrollBenefitBasketsApi.monthlyOverview({
        period: period.value,
        basket: basket.value,
        q: search.value.trim(),
        limit: PAGE_SIZE,
        offset: offset.value,
      })
      items.value = data.items
      periods.value = data.periods
      total.value = data.total
    } else {
      const data = await payrollBenefitBasketsApi.overview({
        year: year.value,
        basket: basket.value,
        q: search.value.trim(),
        limit: PAGE_SIZE,
        offset: offset.value,
      })
      items.value = data.items
      years.value = data.years
      total.value = data.total
    }
    failed.value = false
  } catch (e) {
    // Načtená data se při selhání NEMAŽOU: prázdná tabulka by tvrdila, že
    // nikdo nic nevyčerpal, což je právě ta věta, kvůli které obrazovka vznikla.
    error.value = apiErrorMessage(e)
    failed.value = true
  } finally {
    loading.value = false
  }
}

function goToPage(next: number) {
  offset.value = Math.max(0, (next - 1) * PAGE_SIZE)
  void load()
}

function resetFilters() {
  basket.value = ''
  search.value = ''
  year.value = new Date().getFullYear()
  period.value = thisMonth()
}

/**
 * Přepnutí záložky zahodí filtr na koš. Koše obou režimů se nepřekrývají, takže
 * ponechaný filtr by druhý režim odmítl (422) — a stránka by místo přehledu
 * ukázala chybu, kterou uživatel nezpůsobil. Obě změny padnou do jednoho tiku,
 * takže se načítá jednou.
 */
function selectTab(next: BasketTab) {
  if (tab.value === next) return
  basket.value = ''
  tab.value = next
}

/** Zúžený výběr má míň stránek; třetí stránka by po přefiltrování ukázala prázdno. */
watch([tab, year, period, basket, search], () => {
  offset.value = 0
  void load()
})

/** Procento je jen šířka ukazatele — bez limitu se nekreslí vůbec. */
function usedPercent(row: BenefitBasketUsage): number | null {
  if (row.limit_minor === null || row.limit_minor <= 0) return null
  return Math.min(100, Math.round((row.used_minor / row.limit_minor) * 100))
}

function amount(value: number | null): string {
  return value === null ? '—' : money(value)
}

const actions = computed<ActionItem[]>(() => [
  {
    key: 'reload',
    label: t('common.refresh'),
    icon: 'cycle',
    tier: 'primary',
    variant: 'primary',
    loading: loading.value,
    run: load,
  },
  {
    key: 'components',
    label: t('payroll.benefit_baskets.action_components'),
    icon: 'tag',
    tier: 'secondary',
    variant: 'neutral',
    title: t('payroll.benefit_baskets.action_components_hint'),
    to: '/payroll/components',
  },
])

onMounted(load)
</script>

<template>
  <div class="max-w-6xl">
    <div class="mb-4">
      <h1 class="text-2xl font-semibold">{{ t('payroll.benefit_baskets.title') }}</h1>
      <p class="text-sm text-neutral-500 mt-0.5">
        {{ monthly
          ? t('payroll.benefit_baskets.subtitle_monthly')
          : t('payroll.benefit_baskets.subtitle_annual') }}
      </p>
    </div>

    <ActionBar :actions="actions" class="mb-4" />

    <div
      class="flex flex-wrap gap-1 border-b border-neutral-200 mb-4"
      role="tablist"
      :aria-label="t('payroll.benefit_baskets.tabs_label')"
    >
      <button
        v-for="item in tabs"
        :key="item.key"
        type="button"
        role="tab"
        :aria-selected="tab === item.key"
        :data-test="`basket-tab-${item.key}`"
        class="-mb-px cursor-pointer whitespace-nowrap border-b-2 px-4 py-2 text-sm font-medium transition-colors"
        :class="tab === item.key
          ? 'border-payroll-600 text-payroll-600'
          : 'border-transparent text-neutral-600 hover:border-neutral-300 hover:text-neutral-900'"
        @click="selectTab(item.key)"
      >{{ item.label }}</button>
    </div>

    <div class="bg-primary-50 border border-primary-200 rounded-lg p-4 mb-4 text-sm text-neutral-700">
      <p class="font-medium text-primary-800 mb-1">{{ t('payroll.benefit_baskets.explainer_title') }}</p>
      <p>{{ t('payroll.benefit_baskets.explainer_body') }}</p>
      <!-- Past písm. b) se říká nahlas, ne až v prázdném sloupci limitu. -->
      <p v-if="monthly" class="mt-1.5" data-test="basket-monthly-explainer">
        {{ t('payroll.benefit_baskets.explainer_monthly') }}
      </p>
      <p class="mt-1.5 text-xs text-neutral-600">{{ t('payroll.benefit_baskets.explainer_frozen') }}</p>
    </div>

    <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-3 mb-4">
      <div class="grid grid-cols-1 sm:grid-cols-4 gap-3">
        <div v-if="monthly">
          <label class="block text-xs font-medium text-neutral-500 mb-1" :for="`${pageId}-basket-period`">
            {{ t('payroll.benefit_baskets.filter_period') }}
          </label>
          <input
            :id="`${pageId}-basket-period`"
            v-model="period"
            type="month"
            :list="`${pageId}-basket-period-options`"
            class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface"
          />
          <datalist :id="`${pageId}-basket-period-options`">
            <option v-for="p in periods" :key="p" :value="p" />
          </datalist>
        </div>
        <div v-else>
          <label class="block text-xs font-medium text-neutral-500 mb-1" :for="`${pageId}-basket-year`">
            {{ t('payroll.benefit_baskets.filter_year') }}
          </label>
          <input
            :id="`${pageId}-basket-year`"
            v-model.number="year"
            type="number"
            min="2000"
            max="2200"
            step="1"
            :list="`${pageId}-basket-year-options`"
            class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface"
          />
          <datalist :id="`${pageId}-basket-year-options`">
            <option v-for="y in years" :key="y" :value="y" />
          </datalist>
        </div>
        <div>
          <label class="block text-xs font-medium text-neutral-500 mb-1" :for="`${pageId}-basket-kind`">
            {{ t('payroll.benefit_baskets.col.basket') }}
          </label>
          <select
            :id="`${pageId}-basket-kind`"
            v-model="basket"
            class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface"
          >
            <option value="">{{ t('common.all') }}</option>
            <option v-for="b in basketOptions" :key="b" :value="b">
              {{ t(`payroll.benefit_baskets.basket.${b}`) }}
            </option>
          </select>
        </div>
        <div class="sm:col-span-2">
          <label class="block text-xs font-medium text-neutral-500 mb-1" :for="`${pageId}-basket-q`">
            {{ t('payroll.benefit_baskets.filter_q') }}
          </label>
          <input
            :id="`${pageId}-basket-q`"
            v-model="search"
            type="text"
            :placeholder="t('payroll.benefit_baskets.filter_q_placeholder')"
            class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface"
          />
        </div>
      </div>
      <div class="flex flex-wrap items-center justify-end gap-2 mt-2">
        <button
          type="button"
          class="cursor-pointer whitespace-nowrap text-xs text-neutral-500 hover:text-neutral-700"
          @click="resetFilters"
        >{{ t('payroll.benefit_baskets.reset_filters') }}</button>
        <ColumnPicker class="hidden md:block" :ctrl="tbl" />
        <DensityToggle class="hidden md:block" :ctrl="tbl" />
      </div>
    </div>

    <div v-if="loading" class="text-center text-neutral-500 py-12 text-sm">{{ t('common.loading') }}</div>

    <EmptyState
      v-else-if="failed && items.length === 0"
      boxed
      variant="failed"
      accent="danger"
      :title="t('payroll.benefit_baskets.load_failed')"
      :message="error"
      :cta="t('common.refresh')"
      @action="load"
    />

    <EmptyState
      v-else-if="items.length === 0"
      boxed
      variant="filtered"
      accent="neutral"
      icon="funnel"
      :title="t('payroll.benefit_baskets.no_match')"
      :message="t('payroll.benefit_baskets.no_match_hint')"
      :cta="t('payroll.benefit_baskets.reset_filters')"
      @action="resetFilters"
    />

    <div v-else class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
      <div v-if="failed" class="px-3 py-2 bg-danger-50 border-b border-danger-500/40 text-xs text-danger-600">
        {{ t('payroll.benefit_baskets.stale_warning', { error }) }}
      </div>

      <div class="hidden md:block overflow-x-auto">
        <table v-column-labels="tbl" class="w-full text-sm" :class="tbl.densityClass.value">
          <thead class="bg-neutral-50">
            <tr>
              <th v-if="tbl.isVisible('employee')" class="px-3 py-2 text-left text-xs uppercase tracking-wide font-medium text-neutral-500">{{ t('payroll.benefit_baskets.col.employee') }}</th>
              <th v-if="tbl.isVisible('basket')" class="px-3 py-2 text-left text-xs uppercase tracking-wide font-medium text-neutral-500">{{ t('payroll.benefit_baskets.col.basket') }}</th>
              <th v-if="tbl.isVisible('statute')" class="px-3 py-2 text-left text-xs uppercase tracking-wide font-medium text-neutral-500">{{ t('payroll.benefit_baskets.col.statute') }}</th>
              <th v-if="tbl.isVisible('used')" class="px-3 py-2 text-right text-xs uppercase tracking-wide font-medium text-neutral-500">{{ t('payroll.benefit_baskets.col.used') }}</th>
              <th v-if="tbl.isVisible('limit')" class="px-3 py-2 text-right text-xs uppercase tracking-wide font-medium text-neutral-500">{{ t('payroll.benefit_baskets.col.limit') }}</th>
              <th v-if="tbl.isVisible('remaining')" class="px-3 py-2 text-right text-xs uppercase tracking-wide font-medium text-neutral-500">{{ t('payroll.benefit_baskets.col.remaining') }}</th>
              <th v-if="tbl.isVisible('taxable')" class="px-3 py-2 text-right text-xs uppercase tracking-wide font-medium text-neutral-500">{{ t('payroll.benefit_baskets.col.taxable') }}</th>
              <th v-if="tbl.isVisible('inputs')" class="px-3 py-2 text-right text-xs uppercase tracking-wide font-medium text-neutral-500">{{ t('payroll.benefit_baskets.col.inputs') }}</th>
              <th v-if="tbl.isVisible('status')" class="px-3 py-2 text-left text-xs uppercase tracking-wide font-medium text-neutral-500">{{ t('payroll.benefit_baskets.col.status') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr
              v-for="row in items"
              :key="`${row.employee_id}-${row.basket}`"
              class="hover:bg-neutral-50"
              :data-test="`basket-row-${row.employee_id}-${row.basket}`"
            >
              <td v-if="tbl.isVisible('employee')" class="px-3 py-2 font-medium">{{ row.employee_name }}</td>
              <td v-if="tbl.isVisible('basket')" class="px-3 py-2">
                {{ t(`payroll.benefit_baskets.basket.${row.basket}`) }}
                <div v-if="usedPercent(row) !== null" class="mt-1 h-1.5 w-32 rounded bg-neutral-200 overflow-hidden">
                  <div class="h-full rounded" :class="BAR_COLOR[row.status]" :style="{ width: `${usedPercent(row)}%` }"></div>
                </div>
              </td>
              <td v-if="tbl.isVisible('statute')" class="px-3 py-2 text-xs text-neutral-600 whitespace-nowrap">{{ row.statute }}</td>
              <td v-if="tbl.isVisible('used')" class="px-3 py-2 text-right font-mono tabular-nums">{{ money(row.used_minor) }}</td>
              <td v-if="tbl.isVisible('limit')" class="px-3 py-2 text-right font-mono tabular-nums" :class="row.limit_minor === null ? 'text-neutral-400 italic' : ''">{{ amount(row.limit_minor) }}</td>
              <td v-if="tbl.isVisible('remaining')" class="px-3 py-2 text-right font-mono tabular-nums" :class="row.remaining_minor === 0 ? 'text-danger-600 font-semibold' : ''">{{ amount(row.remaining_minor) }}</td>
              <td v-if="tbl.isVisible('taxable')" class="px-3 py-2 text-right font-mono tabular-nums" :class="row.taxable_minor > 0 ? 'text-danger-600 font-semibold' : 'text-neutral-400'">{{ money(row.taxable_minor) }}</td>
              <td v-if="tbl.isVisible('inputs')" class="px-3 py-2 text-right font-mono tabular-nums">{{ row.input_count }}</td>
              <td v-if="tbl.isVisible('status')" class="px-3 py-2">
                <span
                  class="inline-block text-[10px] font-bold px-1.5 py-px rounded whitespace-nowrap"
                  :class="STATUS_BADGE[row.status]"
                  :data-test="`basket-status-${row.employee_id}-${row.basket}`"
                >{{ t(`payroll.benefit_baskets.status.${row.status}`) }}</span>
                <!--
                  Čeho se limit týká. U `per_shift` je prázdný sloupec limitu
                  JEDINÝ poctivý výsledek a bez téhle věty by se četl jako
                  „měsíční součet je v pořádku" — právě to tvrdit nesmíme.
                -->
                <div
                  v-if="row.limit_basis === 'per_shift'"
                  class="text-[11px] text-neutral-600 mt-0.5"
                  :data-test="`basket-limit-basis-${row.employee_id}-${row.basket}`"
                >
                  {{ t('payroll.benefit_baskets.limit_basis.per_shift') }}
                </div>
                <!-- Chybějící podklad se říká větou, nedopočítává se. -->
                <div v-if="row.unfrozen_count > 0" class="text-[11px] text-warning-700 mt-0.5">
                  {{ t('payroll.benefit_baskets.unfrozen_note', { count: row.unfrozen_count }) }}
                </div>
                <div v-if="row.split_drift" class="text-[11px] text-warning-700 mt-0.5">
                  {{ t('payroll.benefit_baskets.drift_note') }}
                </div>
                <!-- Uvolněný koš se musí dát odlišit od koše, který nikdy nečerpal. -->
                <div
                  v-if="row.reversed_count > 0"
                  class="text-[11px] text-neutral-600 mt-0.5"
                  :data-test="`basket-reversed-${row.employee_id}-${row.basket}`"
                >
                  {{ t('payroll.benefit_baskets.reversed_note', { count: row.reversed_count, amount: money(row.reversed_minor) }) }}
                </div>
                <div
                  v-if="row.taken_over_months > 0"
                  class="text-[11px] text-neutral-600 mt-0.5"
                  :data-test="`basket-taken-over-${row.employee_id}-${row.basket}`"
                >
                  {{ t('payroll.benefit_baskets.taken_over_note', { count: row.taken_over_months, amount: money(row.taken_over_minor) }) }}
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Mobil -->
      <div class="md:hidden divide-y divide-neutral-100">
        <div v-for="row in items" :key="`m-${row.employee_id}-${row.basket}`" class="p-3">
          <div class="flex items-start justify-between gap-2">
            <div>
              <div class="font-medium">{{ row.employee_name }}</div>
              <div class="text-xs text-neutral-500">{{ t(`payroll.benefit_baskets.basket.${row.basket}`) }}</div>
            </div>
            <span
              class="inline-block text-[10px] font-bold px-1.5 py-px rounded whitespace-nowrap"
              :class="STATUS_BADGE[row.status]"
            >{{ t(`payroll.benefit_baskets.status.${row.status}`) }}</span>
          </div>
          <dl class="mt-2 grid grid-cols-2 gap-x-3 gap-y-1 text-xs">
            <dt class="text-neutral-500">{{ t('payroll.benefit_baskets.col.used') }}</dt>
            <dd class="text-right font-mono tabular-nums">{{ money(row.used_minor) }}</dd>
            <dt class="text-neutral-500">{{ t('payroll.benefit_baskets.col.remaining') }}</dt>
            <dd class="text-right font-mono tabular-nums">{{ amount(row.remaining_minor) }}</dd>
            <dt class="text-neutral-500">{{ t('payroll.benefit_baskets.col.taxable') }}</dt>
            <dd class="text-right font-mono tabular-nums">{{ money(row.taxable_minor) }}</dd>
          </dl>
          <p v-if="row.limit_basis === 'per_shift'" class="text-[11px] text-neutral-600 mt-1">
            {{ t('payroll.benefit_baskets.limit_basis.per_shift') }}
          </p>
          <p v-if="row.unfrozen_count > 0" class="text-[11px] text-warning-700 mt-1">
            {{ t('payroll.benefit_baskets.unfrozen_note', { count: row.unfrozen_count }) }}
          </p>
          <p v-if="row.reversed_count > 0" class="text-[11px] text-neutral-600 mt-1">
            {{ t('payroll.benefit_baskets.reversed_note', { count: row.reversed_count, amount: money(row.reversed_minor) }) }}
          </p>
          <p v-if="row.taken_over_months > 0" class="text-[11px] text-neutral-600 mt-1">
            {{ t('payroll.benefit_baskets.taken_over_note', { count: row.taken_over_months, amount: money(row.taken_over_minor) }) }}
          </p>
        </div>
      </div>

      <PaginationBar
        data-test="basket-pagination"
        embedded
        :page="currentPage"
        :per-page="PAGE_SIZE"
        :total="total"
        @update:page="goToPage"
      />
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted, reactive, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, RouterLink } from 'vue-router'
import { accountingApi, type AccountDetailReport, type AccountDetailChild, type AccountingPeriod } from '@/api/accounting'
import { useToast } from '@/composables/useToast'
import { formatDate, formatMoney } from '@/composables/useFormat'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import DateInput from '@/components/ui/DateInput.vue'

/**
 * Karta účtu — rozcestník drill-through nad osnovou.
 *
 * Osnova → účet → (analytiky | opis účtu | hlavní kniha | deník) a zpátky.
 * Vlastní čísla nepočítá: PS/obraty/KS chodí z /accounting/accounts/{id},
 * kde je staví tytéž metody jako opis účtu (SSOT).
 */

const { t } = useI18n()
const route = useRoute()
const toast = useToast()

const accountId = computed(() => Number(route.params.accountId))

const report = ref<AccountDetailReport | null>(null)
const periods = ref<AccountingPeriod[]>([])
const loading = ref(false)
const notFound = ref(false)

function defaultRange(): { from: string; to: string } {
  const year = new Date().getFullYear()
  return { from: `${year}-01-01`, to: `${year}-12-31` }
}

const filters = reactive({
  from: typeof route.query.from === 'string' && route.query.from ? route.query.from : defaultRange().from,
  to: typeof route.query.to === 'string' && route.query.to ? route.query.to : defaultRange().to,
})

async function load() {
  if (!accountId.value) return
  loading.value = true
  notFound.value = false
  try {
    report.value = await accountingApi.getAccountDetail(accountId.value, { from: filters.from, to: filters.to })
  } catch (e: any) {
    if (e?.response?.status === 404) {
      notFound.value = true
    } else {
      toast.error(e?.response?.data?.error?.message || t('common.error'))
    }
    report.value = null
  } finally {
    loading.value = false
  }
}

/** Rychlá volba období — přepíše from/to na hranice účetního roku. */
function applyPeriod(p: AccountingPeriod) {
  filters.from = p.starts_on
  filters.to = p.ends_on
  load()
}

const activePeriodId = computed(() =>
  periods.value.find(p => p.starts_on === filters.from && p.ends_on === filters.to)?.id ?? null,
)

const statementLink = computed(() => ({
  name: 'accounting-account-statement',
  params: { accountId: accountId.value },
  query: { from: filters.from, to: filters.to },
}))

/**
 * Hlavní kniha filtrovaná na tenhle účet: kniha nemá filtr na účet, ale umí řádek
 * rozbalit — `account_id` v query ho po načtení sama otevře a odroluje k němu
 * (viz GeneralLedger.vue). Analytika potřebuje navíc `analytics=1`, jinak je
 * v knize zarolovaná pod syntetikou a řádek by tam vůbec nebyl.
 */
const ledgerLink = computed(() => {
  const r = report.value
  // Kniha odmítne rozsah mimo zvolené období (422) — mimo hranice ho radši
  // vynecháme a necháme knihu použít celé období.
  const inPeriod = !!r?.period && filters.from >= r.period.starts_on && filters.to <= r.period.ends_on
  return {
    name: 'accounting-general-ledger',
    query: {
      ...(r?.period ? { period_id: String(r.period.id) } : {}),
      ...(inPeriod ? { from: filters.from, to: filters.to } : {}),
      ...(r && !r.account.is_synthetic ? { analytics: '1' } : {}),
      account_id: String(accountId.value),
    },
  }
})

/**
 * Deník filtrovaný na tenhle účet. Deník nezná account_id, filtruje textovým
 * ROZSAHEM kódů — u syntetiky je proto horní mez `kód + 999999`, aby se do rozsahu
 * vešly i její analytiky (`221` … `221999999` chytí `221.100` i `221100`, ale už
 * ne `222`). Analytika je list, tam stačí rozsah sama na sebe.
 */
const journalLink = computed(() => {
  const r = report.value
  const code = r?.account.code ?? ''
  return {
    name: 'accounting-journal',
    query: {
      account_from: code,
      account_to: r?.account.is_synthetic ? code + '999999' : code,
      date_from: filters.from,
      date_to: filters.to,
    },
  }
})

function childLink(c: AccountDetailChild) {
  return { name: 'accounting-account-detail', params: { accountId: c.id }, query: { from: filters.from, to: filters.to } }
}

const actions = computed<ActionItem[]>(() => {
  if (!report.value) return []
  return [
    {
      key: 'statement',
      label: t('accounting.accounts.detail.statement'),
      icon: 'doc',
      tier: 'primary',
      variant: 'primary',
      to: statementLink.value,
    },
    {
      key: 'general_ledger',
      label: t('accounting.accounts.detail.general_ledger'),
      icon: 'chart',
      tier: 'secondary',
      variant: 'primary',
      to: ledgerLink.value,
    },
    {
      key: 'journal',
      label: t('accounting.accounts.detail.journal'),
      icon: 'clipboardCheck',
      tier: 'secondary',
      variant: 'neutral',
      to: journalLink.value,
    },
    {
      key: 'chart',
      label: t('accounting.accounts.detail.back_to_chart'),
      icon: 'archive',
      tier: 'overflow',
      variant: 'neutral',
      to: { name: 'accounting-accounts' },
    },
  ]
})

/** Zůstatek na „správné" straně účtu — kladné = MD, záporné = Dal. */
function balanceSide(v: number): string {
  return v >= 0 ? t('accounting.journal.side.debit') : t('accounting.journal.side.credit')
}

watch(accountId, () => { void load() })

onMounted(async () => {
  try { periods.value = await accountingApi.listPeriods() } catch { periods.value = [] }
  await load()
})
</script>

<template>
  <div>
    <div class="flex flex-wrap items-start justify-between gap-2 mb-4">
      <div>
        <div class="flex items-center gap-2 flex-wrap">
          <!-- Kód a název jako dvě položky flexu s gapem — mezera v textu se při
               zalomení dlouhého názvu ztratí a kód by se na název nalepil. -->
          <h1 class="text-2xl font-semibold flex flex-wrap items-baseline gap-x-2">
            <span class="font-mono">{{ report?.account.code ?? '…' }}</span>
            <span v-if="report">{{ report.account.name }}</span>
          </h1>
          <span v-if="report && !report.account.is_active"
            class="text-xs px-2 py-0.5 rounded font-medium bg-neutral-100 text-neutral-500">
            {{ t('accounting.accounts.detail.inactive') }}
          </span>
        </div>
        <p class="text-sm text-neutral-500 mt-0.5">{{ t('accounting.accounts.detail.subtitle') }}</p>
        <p v-if="report?.parent" class="text-xs text-neutral-500 mt-1 flex flex-wrap items-baseline gap-x-1.5">
          <span>{{ t('accounting.accounts.detail.parent') }}:</span>
          <RouterLink
            :to="{ name: 'accounting-account-detail', params: { accountId: report.parent.id }, query: { from: filters.from, to: filters.to } }"
            class="text-primary-600 hover:text-primary-700 hover:underline">
            <span class="font-mono">{{ report.parent.code }}</span>
            <span class="ml-1.5">{{ report.parent.name }}</span>
          </RouterLink>
        </p>
      </div>
      <div class="flex flex-wrap items-center gap-2">
        <RouterLink :to="{ name: 'accounting-accounts' }" class="text-sm text-neutral-500 hover:text-neutral-700 mr-1 whitespace-nowrap">
          {{ t('common.back') }}
        </RouterLink>
        <ActionBar :actions="actions" />
      </div>
    </div>

    <div v-if="notFound" class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-6 text-center text-sm text-neutral-500">
      {{ t('accounting.accounts.detail.not_found') }}
    </div>

    <template v-else>
      <!-- Rozsah -->
      <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-3 mb-4">
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
          <div>
            <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.accounts.detail.filter_from') }}</label>
            <DateInput v-model="filters.from" @change="load"
              class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm" />
          </div>
          <div>
            <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.accounts.detail.filter_to') }}</label>
            <DateInput v-model="filters.to" @change="load"
              class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm" />
          </div>
          <div class="sm:col-span-2 lg:col-span-2">
            <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.accounts.detail.period') }}</label>
            <div class="flex flex-wrap items-center gap-1.5">
              <button v-for="p in periods" :key="p.id" type="button" @click="applyPeriod(p)"
                class="cursor-pointer shrink-0 h-9 px-3 inline-flex items-center rounded-full border text-sm transition-colors whitespace-nowrap"
                :class="activePeriodId === p.id
                  ? 'border-primary-300 bg-primary-50 text-primary-700 font-medium'
                  : 'border-neutral-200 text-neutral-600 hover:bg-neutral-50'">
                {{ p.fiscal_year }}
              </button>
            </div>
          </div>
        </div>
      </div>

      <div v-if="loading" class="text-center text-neutral-500 py-12 text-sm">{{ t('common.loading') }}</div>

      <template v-else-if="report">
        <!-- Souhrn účtu -->
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-4">
          <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-3">
            <div class="text-xs text-neutral-500">{{ t('accounting.account_statement.opening') }}</div>
            <div class="text-lg font-semibold font-mono">{{ formatMoney(report.totals.opening_balance) }}</div>
          </div>
          <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-3">
            <div class="text-xs text-neutral-500">{{ t('accounting.account_statement.turnover_md') }}</div>
            <div class="text-lg font-semibold font-mono">{{ formatMoney(report.totals.turnover_md) }}</div>
          </div>
          <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-3">
            <div class="text-xs text-neutral-500">{{ t('accounting.account_statement.turnover_d') }}</div>
            <div class="text-lg font-semibold font-mono">{{ formatMoney(report.totals.turnover_d) }}</div>
          </div>
          <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-3">
            <div class="text-xs text-neutral-500">
              {{ t('accounting.account_statement.closing') }} · {{ balanceSide(report.totals.closing_balance) }}
            </div>
            <div class="text-lg font-semibold font-mono">{{ formatMoney(report.totals.closing_balance) }}</div>
          </div>
        </div>

        <!-- Kmenová data -->
        <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm mb-4 overflow-hidden">
          <div class="px-3 py-2 bg-neutral-50 border-b border-neutral-100 text-xs font-bold uppercase tracking-wide text-neutral-500">
            {{ t('accounting.accounts.detail.master_data') }}
          </div>
          <dl class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-3 p-3 text-sm">
            <div>
              <dt class="text-xs text-neutral-500">{{ t('accounting.accounts.code') }}</dt>
              <dd class="font-mono font-medium">{{ report.account.code }}</dd>
            </div>
            <div>
              <dt class="text-xs text-neutral-500">{{ t('accounting.accounts.type_col') }}</dt>
              <dd>{{ t(`accounting.accounts.type.${report.account.account_type}`) }}</dd>
            </div>
            <div>
              <dt class="text-xs text-neutral-500">{{ t('accounting.accounts.normal_side') }}</dt>
              <dd>{{ report.account.normal_side ? t(`accounting.journal.side.${report.account.normal_side}`) : '—' }}</dd>
            </div>
            <div>
              <dt class="text-xs text-neutral-500">{{ t('accounting.accounts.detail.kind') }}</dt>
              <dd>
                {{ report.account.is_synthetic
                  ? t('accounting.general_ledger.synthetic')
                  : t('accounting.general_ledger.analytic') }}
              </dd>
            </div>
            <div>
              <dt class="text-xs text-neutral-500">{{ t('accounting.accounts.detail.status') }}</dt>
              <dd :class="report.account.is_active ? 'text-success-600' : 'text-neutral-500'">
                {{ report.account.is_active ? t('accounting.accounts.detail.active') : t('accounting.accounts.detail.inactive') }}
              </dd>
            </div>
            <div>
              <dt class="text-xs text-neutral-500">{{ t('accounting.accounts.detail.children_count') }}</dt>
              <dd>{{ report.children.length }}</dd>
            </div>
          </dl>
        </div>

        <!-- Analytiky -->
        <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
          <div class="px-3 py-2 bg-neutral-50 border-b border-neutral-100 flex flex-wrap items-baseline justify-between gap-2">
            <span class="text-xs font-bold uppercase tracking-wide text-neutral-500">
              {{ t('accounting.accounts.detail.analytics') }}
            </span>
            <span class="text-xs text-neutral-400">{{ t('accounting.accounts.detail.analytics_hint') }}</span>
          </div>

          <EmptyState v-if="report.children.length === 0" accent="neutral" icon="doc"
            :title="t('accounting.accounts.detail.analytics_empty')" />

          <template v-else>
            <!-- Desktop tabulka -->
            <div class="hidden md:block overflow-x-auto">
              <table class="w-full text-sm table-sticky-first">
                <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
                  <tr>
                    <th class="px-3 py-2 text-left font-medium w-28">{{ t('accounting.accounts.code') }}</th>
                    <th class="px-3 py-2 text-left font-medium">{{ t('accounting.accounts.name') }}</th>
                    <th class="px-3 py-2 text-right font-medium w-36">{{ t('accounting.general_ledger.col_ps_md') }}</th>
                    <th class="px-3 py-2 text-right font-medium w-36">{{ t('accounting.general_ledger.col_turnover_md') }}</th>
                    <th class="px-3 py-2 text-right font-medium w-36">{{ t('accounting.general_ledger.col_turnover_d') }}</th>
                    <th class="px-3 py-2 text-right font-medium w-36">{{ t('accounting.account_statement.closing') }}</th>
                    <th class="px-3 py-2 text-right font-medium w-20">{{ t('accounting.accounts.detail.col_lines') }}</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-neutral-100">
                  <tr v-for="c in report.children" :key="c.id" class="hover:bg-neutral-50"
                    :class="{ 'opacity-50': !c.is_active }">
                    <td class="px-3 py-2">
                      <RouterLink :to="childLink(c)" class="row-link font-mono text-primary-600 hover:text-primary-700 hover:underline">
                        {{ c.code }}
                      </RouterLink>
                    </td>
                    <td class="px-3 py-2">{{ c.name }}</td>
                    <td class="px-3 py-2 text-right font-mono">{{ formatMoney(c.opening_balance) }}</td>
                    <td class="px-3 py-2 text-right font-mono">{{ formatMoney(c.turnover_md) }}</td>
                    <td class="px-3 py-2 text-right font-mono">{{ formatMoney(c.turnover_d) }}</td>
                    <td class="px-3 py-2 text-right font-mono font-medium">{{ formatMoney(c.closing_balance) }}</td>
                    <td class="px-3 py-2 text-right text-neutral-500">
                      <span v-if="c.line_count">{{ c.line_count }}</span>
                      <span v-else class="text-neutral-300" :title="t('accounting.accounts.detail.no_movement')">—</span>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>

            <!-- Mobile karty -->
            <div class="md:hidden divide-y divide-neutral-100">
              <RouterLink v-for="c in report.children" :key="`m-${c.id}`" :to="childLink(c)"
                class="block p-3 space-y-1 hover:bg-neutral-50" :class="{ 'opacity-50': !c.is_active }">
                <div class="flex items-baseline justify-between gap-2">
                  <span class="font-mono text-primary-600">{{ c.code }}</span>
                  <span class="font-mono font-medium">{{ formatMoney(c.closing_balance) }}</span>
                </div>
                <div class="text-neutral-900">{{ c.name }}</div>
                <div class="text-xs text-neutral-500 font-mono">
                  {{ t('accounting.general_ledger.col_turnover_md') }} {{ formatMoney(c.turnover_md) }}
                  · {{ t('accounting.general_ledger.col_turnover_d') }} {{ formatMoney(c.turnover_d) }}
                </div>
              </RouterLink>
            </div>
          </template>
        </div>

        <p v-if="report.account.created_at" class="text-xs text-neutral-400 mt-3">
          {{ t('accounting.accounts.detail.created_at') }}: {{ formatDate(report.account.created_at) }}
        </p>
      </template>
    </template>
  </div>
</template>

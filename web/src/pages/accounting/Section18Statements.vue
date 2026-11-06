<script setup lang="ts">
/**
 * Přehled o peněžních tocích a o změnách vlastního kapitálu — § 18 odst. 2 ZoÚ,
 * § 40 až § 44 vyhl. 500/2002 Sb.
 *
 * Oba výkazy na jedné stránce: povinnost je společná (velká a střední účetní jednotka
 * a každá s povinným auditem je má oba) a účetní je posuzuje spolu.
 *
 * Co musí UI říct nahlas:
 *   — zda se povinnost firmy TÝKÁ (podle kategorie ÚJ). Sestavit se dají oběma
 *     směry, ale malá ÚJ je do závěrky přikládat nemusí.
 *   — zda přehled o peněžních tocích SEDÍ na skutečnou změnu stavu peněz. Sestavuje se
 *     přímou klasifikací pohybů, takže má sedět konstrukčně; nesedí-li, je vada
 *     v datech (typicky pohyb, jehož protiúčet nejde zařadit) a výkaz se takhle
 *     odevzdat nedá.
 */
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { accountingApi, type AccountingPeriod, type Section18Statements } from '@/api/accounting'
import { apiErrorMessage } from '@/api/errors'
import { formatMoney } from '@/composables/useFormat'
import { ICONS, btnOutline } from '@/components/ui/buttonStyles'
import EmptyState from '@/components/ui/EmptyState.vue'
import { useToast } from '@/composables/useToast'
import ActivationBanner from '@/components/settings/activation/ActivationBanner.vue'

const { t } = useI18n()
const toast = useToast()

const periods = ref<AccountingPeriod[]>([])
const periodId = ref<number | ''>('')
const data = ref<Section18Statements | null>(null)
const loading = ref(false)
const error = ref('')

const cashFlow = computed(() => data.value?.cash_flow ?? null)
const equity = computed(() => data.value?.equity ?? null)

/** Skupiny toků v pořadí, v jakém je vyhláška vykazuje. */
const groups = computed(() => {
  const cf = cashFlow.value
  if (cf === null) return []
  return [
    { key: 'operating', label: t('accounting.section18.operating'), amount: cf.operating, rows: cf.breakdown.operating },
    { key: 'investing', label: t('accounting.section18.investing'), amount: cf.investing, rows: cf.breakdown.investing },
    { key: 'financing', label: t('accounting.section18.financing'), amount: cf.financing, rows: cf.breakdown.financing },
    // Nezařazené se NESLUČUJÍ do provozní činnosti — tichý přesun by udělal výkaz,
    // který formálně sedí a přitom lže. Proto vlastní řádek, i když je nula.
    { key: 'unclassified', label: t('accounting.section18.unclassified'), amount: cf.unclassified, rows: cf.breakdown.unclassified },
  ]
})

const expanded = ref<Record<string, boolean>>({})

function toggle(key: string): void {
  expanded.value[key] = !expanded.value[key]
}

async function load(): Promise<void> {
  if (!periodId.value) return
  loading.value = true
  error.value = ''
  try {
    data.value = await accountingApi.getSection18Statements(Number(periodId.value))
  } catch (e) {
    error.value = apiErrorMessage(e)
    data.value = null
  } finally {
    loading.value = false
  }
}

/**
 * Export jednoho z výkazů. Každý zvlášť: jsou to dvě samostatné přílohy závěrky
 * a sloučit je do jednoho souboru by znamenalo, že si je účetní musí zase rozdělit.
 */
const exporting = ref(false)
async function exportFile(statement: 'cash_flow' | 'equity', format: 'pdf' | 'xlsx'): Promise<void> {
  if (!periodId.value || !data.value) return
  exporting.value = true
  try {
    const r = await accountingApi.exportReport('/accounting/reports/section18-statements/export', {
      period_id: Number(periodId.value),
      statement,
      format,
    })
    const base = statement === 'cash_flow' ? 'penezni-toky' : 'zmeny-vlastniho-kapitalu'
    const from = (statement === 'cash_flow' ? cashFlow.value : equity.value)?.period.starts_on ?? ''
    downloadBlob(r.data as unknown as Blob, `${base}-${from}.${format}`)
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  } finally {
    exporting.value = false
  }
}

function downloadBlob(blob: Blob, filename: string): void {
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = filename
  document.body.appendChild(a); a.click(); a.remove()
  URL.revokeObjectURL(url)
}

onMounted(async () => {
  try { periods.value = await accountingApi.listPeriods() } catch { periods.value = [] }
  const open = periods.value.filter(p => p.status === 'open' || p.status === 'closing')
  const def = open.length
    ? open.reduce((a, b) => (b.fiscal_year > a.fiscal_year ? b : a))
    : periods.value[0]
  if (def) {
    periodId.value = def.id
    await load()
  }
})
</script>

<template>
  <div>
    <ActivationBanner />
    <div class="flex flex-wrap items-center justify-between gap-2 mb-4">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('accounting.section18.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5">{{ t('accounting.section18.subtitle') }}</p>
      </div>
    </div>

    <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-3 mb-4">
      <div class="grid grid-cols-1 sm:grid-cols-4 gap-3">
        <div>
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.section18.filter_period') }}</label>
          <select v-model="periodId" @change="load"
            class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface">
            <option v-for="p in periods" :key="p.id" :value="p.id">{{ p.fiscal_year }}</option>
          </select>
        </div>
      </div>
    </div>

    <div v-if="error" class="mb-4 px-3 py-2 rounded-md bg-danger-50 border border-danger-500/30 text-danger-600 text-sm">
      {{ error }}
    </div>
    <div v-if="loading" class="text-sm text-neutral-500">{{ t('common.loading') }}</div>

    <p v-if="data?.available === false && !loading" class="rounded-md border border-neutral-200 bg-neutral-50 p-4 text-sm">{{ t('accounting.statement504.supplements_unavailable') }}</p>
    <template v-else-if="data && !loading">
      <!-- Povinnost podle kategorie ÚJ. Malá jednotka výkazy sestavit může, do závěrky
           je ale přikládat nemusí — bez téhle věty by nevěděla, na čem je. -->
      <div class="mb-4 px-3 py-2 rounded-md text-sm border"
        :class="data.required
          ? 'bg-warning-50 border-warning-500/30 text-warning-700'
          : 'bg-neutral-50 border-neutral-200 text-neutral-600'">
        {{ data.required
          ? t('accounting.section18.required_hint', { category: t('accounting.balance_sheet.category_' + data.category) })
          : t('accounting.section18.optional_hint', { category: t('accounting.balance_sheet.category_' + data.category) }) }}
      </div>

      <!-- Přehled o peněžních tocích -->
      <div v-if="cashFlow" class="bg-surface border border-neutral-200 rounded-lg shadow-sm mb-6 overflow-hidden">
        <header class="px-5 py-3 border-b border-neutral-200 flex flex-wrap items-center justify-between gap-2">
          <h2 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('accounting.section18.cash_flow_title') }}</h2>
          <div class="flex items-center gap-2">
            <button :disabled="exporting" :class="btnOutline('primary')" @click="exportFile('cash_flow', 'pdf')">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.download" /></svg>
              {{ t('accounting.section18.export_pdf') }}
            </button>
            <button :disabled="exporting" :class="btnOutline('primary')" @click="exportFile('cash_flow', 'xlsx')">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.download" /></svg>
              {{ t('accounting.section18.export_xlsx') }}
            </button>
          </div>
        </header>
        <div class="p-5 space-y-4">
          <div v-if="!cashFlow.reconciles"
            class="px-3 py-2 rounded-md bg-danger-50 border border-danger-500/30 text-danger-600 text-sm">
            {{ t('accounting.section18.cash_flow_mismatch') }}
          </div>

          <dl class="space-y-1 text-sm">
            <div class="flex justify-between">
              <dt class="text-neutral-600">{{ t('accounting.section18.opening') }}</dt>
              <dd class="font-mono">{{ formatMoney(cashFlow.opening, 'CZK') }}</dd>
            </div>
          </dl>

          <div class="overflow-x-auto">
            <table class="w-full text-sm">
              <tbody>
                <template v-for="g in groups" :key="g.key">
                  <tr class="border-t border-neutral-200">
                    <!-- Rozbalovátko jen tam, kde je co rozbalit. Skupina bez pohybů se
                         nechává jako prostý řádek — „+" nad prázdnem slibuje obsah,
                         který neexistuje. -->
                    <td class="py-2">
                      <button v-if="g.rows.length > 0" type="button" @click="toggle(g.key)"
                        class="cursor-pointer text-left font-medium text-neutral-700 hover:text-primary-700">
                        <span class="inline-block w-4">{{ expanded[g.key] ? '−' : '+' }}</span>{{ g.label }}
                      </button>
                      <span v-else class="font-medium text-neutral-500">
                        <span class="inline-block w-4"></span>{{ g.label }}
                      </span>
                    </td>
                    <td class="py-2 text-right font-mono" :class="g.amount < 0 ? 'text-danger-600' : ''">
                      {{ formatMoney(g.amount, 'CZK') }}
                    </td>
                  </tr>
                  <tr v-for="row in (expanded[g.key] ? g.rows : [])" :key="g.key + row.account_code"
                    class="text-neutral-600">
                    <td class="py-1 pl-8">{{ row.account_code }} - {{ row.name }}</td>
                    <td class="py-1 text-right font-mono">{{ formatMoney(row.amount, 'CZK') }}</td>
                  </tr>
                </template>
                <tr class="border-t-2 border-neutral-300 font-semibold">
                  <td class="py-2">{{ t('accounting.section18.net_change') }}</td>
                  <td class="py-2 text-right font-mono">{{ formatMoney(cashFlow.net_change, 'CZK') }}</td>
                </tr>
                <tr class="border-t border-neutral-200">
                  <td class="py-2">{{ t('accounting.section18.closing') }}</td>
                  <td class="py-2 text-right font-mono">{{ formatMoney(cashFlow.closing, 'CZK') }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- Přehled o změnách vlastního kapitálu -->
      <div v-if="equity" class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
        <header class="px-5 py-3 border-b border-neutral-200 flex flex-wrap items-center justify-between gap-2">
          <h2 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('accounting.section18.equity_title') }}</h2>
          <div class="flex items-center gap-2">
            <button :disabled="exporting" :class="btnOutline('primary')" @click="exportFile('equity', 'pdf')">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.download" /></svg>
              {{ t('accounting.section18.export_pdf') }}
            </button>
            <button :disabled="exporting" :class="btnOutline('primary')" @click="exportFile('equity', 'xlsx')">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.download" /></svg>
              {{ t('accounting.section18.export_xlsx') }}
            </button>
          </div>
        </header>
        <div class="p-5 space-y-4">
          <div v-if="!equity.reconciles"
            class="px-3 py-2 rounded-md bg-danger-50 border border-danger-500/30 text-danger-600 text-sm">
            {{ t('accounting.section18.equity_mismatch') }}
          </div>
          <p class="text-xs text-neutral-500">{{ t('accounting.section18.equity_hint') }}</p>

          <EmptyState v-if="equity.rows.length === 0" dense accent="neutral" icon="chart" :title="t('accounting.section18.equity_empty')" />
          <div v-else class="overflow-x-auto">
            <table class="w-full text-sm">
              <thead>
                <tr class="text-left text-xs uppercase tracking-wide text-neutral-500">
                  <th class="py-2 pr-3 font-medium">{{ t('accounting.section18.account') }}</th>
                  <th class="py-2 pr-3 font-medium text-right">{{ t('accounting.section18.opening') }}</th>
                  <th class="py-2 pr-3 font-medium text-right">{{ t('accounting.section18.increase') }}</th>
                  <th class="py-2 pr-3 font-medium text-right">{{ t('accounting.section18.decrease') }}</th>
                  <th class="py-2 font-medium text-right">{{ t('accounting.section18.closing') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="row in equity.rows" :key="row.account_code" class="border-t border-neutral-200">
                  <td class="py-2 pr-3">{{ row.account_code }} - {{ row.name }}</td>
                  <td class="py-2 pr-3 text-right font-mono">{{ formatMoney(row.opening, 'CZK') }}</td>
                  <td class="py-2 pr-3 text-right font-mono">{{ formatMoney(row.increase, 'CZK') }}</td>
                  <td class="py-2 pr-3 text-right font-mono">{{ formatMoney(row.decrease, 'CZK') }}</td>
                  <td class="py-2 text-right font-mono">{{ formatMoney(row.closing, 'CZK') }}</td>
                </tr>
                <tr class="border-t-2 border-neutral-300 font-semibold">
                  <td class="py-2 pr-3">{{ t('accounting.section18.total') }}</td>
                  <td class="py-2 pr-3 text-right font-mono">{{ formatMoney(equity.totals.opening, 'CZK') }}</td>
                  <td class="py-2 pr-3 text-right font-mono">{{ formatMoney(equity.totals.increase, 'CZK') }}</td>
                  <td class="py-2 pr-3 text-right font-mono">{{ formatMoney(equity.totals.decrease, 'CZK') }}</td>
                  <td class="py-2 text-right font-mono">{{ formatMoney(equity.totals.closing, 'CZK') }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </template>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted, reactive, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import {
  accountingApi,
  type AccountingPeriod,
  type BalanceInventoryReport,
  type BalanceInventoryRow,
  type BalanceInventorySavePayload,
} from '@/api/accounting'
import { useToast } from '@/composables/useToast'
import { formatMoney } from '@/composables/useFormat'
import { ICONS, btnOutline, btnFilled } from '@/components/ui/buttonStyles'
import ActivationBanner from '@/components/settings/activation/ActivationBanner.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import DateInput from '@/components/ui/DateInput.vue'

const { t } = useI18n()
const toast = useToast()

const periods = ref<AccountingPeriod[]>([])
const report = ref<BalanceInventoryReport | null>(null)
const loading = ref(false)
const saving = ref(false)

const filters = reactive({
  period_id: '' as number | '',
})

// EP-6: hlavička inventarizace (odpovědná osoba, datum, protokol) + per-účet editace
// skutečného stavu / vyřešení. Klíčem editů je account_id.
const header = reactive({
  responsible_person: '' as string,
  inventory_date: '' as string,
  protocol_ref: '' as string,
  note: '' as string,
})
const edits = reactive<Record<number, { counted: string; resolved: boolean; note: string }>>({})
const rowVersion = ref(0)

const selectedPeriod = computed<AccountingPeriod | undefined>(() =>
  periods.value.find(p => p.id === Number(filters.period_id)))
// Uložit skutečný stav lze jen v otevřeném / uzavíraném období (server totéž vynucuje).
const editable = computed(() => ['open', 'closing'].includes(selectedPeriod.value?.status ?? ''))

/**
 * Účetní i skutečný stav se ukazují a zadávají na NORMÁLNÍ straně účtu, tedy
 * kladně: závazek 230 850 Kč, ne −230 850 Kč. Server drží znaménkovou bázi
 * (MD − D) — na ní staví rozdíl i gating uzavření knih — takže se převádí jen
 * na hranici UI. Dokud se ukazovala syrová báze, napsala účetní do skutečného
 * stavu pasivního účtu kladné číslo a rozdíl vyšel dvojnásobný.
 */
function sideSign(row: BalanceInventoryRow): 1 | -1 {
  return row.normal_side === 'credit' ? -1 : 1
}
/**
 * Obourstranný účet (343 DPH, 431 výsledek hospodaření) nemá v osnově normální
 * stranu — u něj zůstává znaménková báze a sloupec strany to přiznává, ať je
 * poznat, že se záporná hodnota čte jako opačná strana, ne jako chyba.
 */
function sideLabel(row: BalanceInventoryRow): string {
  if (row.normal_side === 'credit') return t('accounting.balance_inventory.side_credit')
  if (row.normal_side === 'debit') return t('accounting.balance_inventory.side_debit')
  return t('accounting.balance_inventory.side_both')
}
/** Nula bez znaménka: −1 × 0 dá v JS −0 a vypsalo by se „−0,00 Kč“. */
function orient(row: BalanceInventoryRow, value: number): number {
  const v = sideSign(row) * value
  return v === 0 ? 0 : v
}
/** Účetní stav na normální straně účtu (kladný zůstatek aktiva i pasiva). */
function bookBalance(row: BalanceInventoryRow): number {
  return orient(row, row.book_balance ?? (row.ks_md - row.ks_d))
}
/** Uložený skutečný stav na normální straně účtu; null = dosud nenapočítáno. */
function countedBalance(row: BalanceInventoryRow): number | null {
  return row.counted_balance != null ? orient(row, row.counted_balance) : null
}
function liveDifference(row: BalanceInventoryRow): number | null {
  const e = edits[row.account_id]
  if (!e || e.counted === '') return null
  const n = Number(e.counted.replace(',', '.'))
  if (Number.isNaN(n)) return null
  return Math.round((n - bookBalance(row)) * 100) / 100
}
function rowResolved(row: BalanceInventoryRow): boolean {
  const e = edits[row.account_id]
  if (!e) return false
  if (e.resolved) return true
  const diff = liveDifference(row)
  return diff !== null && Math.abs(diff) < 0.005
}
const unresolvedCount = computed(() =>
  (report.value?.rows ?? []).filter(r => !rowResolved(r)).length)

const inventoryStatus = computed(() => report.value?.inventory?.status ?? 'in_progress')

function hydrateEdits(r: BalanceInventoryReport) {
  for (const k of Object.keys(edits)) delete edits[Number(k)]
  for (const row of r.rows) {
    const counted = countedBalance(row)
    edits[row.account_id] = {
      counted: counted != null ? String(counted) : '',
      resolved: row.resolution === 'resolved',
      note: row.item_note ?? '',
    }
  }
  header.responsible_person = r.inventory?.responsible_person ?? ''
  header.inventory_date = r.inventory?.inventory_date ?? ''
  header.protocol_ref = r.inventory?.protocol_ref ?? ''
  header.note = r.inventory?.note ?? ''
  rowVersion.value = r.row_version ?? 0
}

async function load() {
  if (!filters.period_id) return
  loading.value = true
  try {
    // Náhled z uzávěrky nese uložený skutečný stav + row_version; fallback na
    // report-only endpoint (jiný než podvojný režim by na closing/inventory nedosáhl).
    report.value = await accountingApi.getClosingInventory(Number(filters.period_id))
    hydrateEdits(report.value)
  } catch {
    try {
      report.value = await accountingApi.getBalanceInventory(Number(filters.period_id))
      hydrateEdits(report.value)
    } catch (e: any) {
      toast.error(e?.response?.data?.error?.message || t('common.error'))
      report.value = null
    }
  } finally {
    loading.value = false
  }
}

function buildPayload(complete: boolean): BalanceInventorySavePayload {
  return {
    row_version: rowVersion.value,
    responsible_person: header.responsible_person.trim() || null,
    inventory_date: header.inventory_date || null,
    protocol_ref: header.protocol_ref.trim() || null,
    note: header.note.trim() || null,
    complete,
    items: (report.value?.rows ?? []).map(row => {
      const e = edits[row.account_id]
      const countedRaw = e?.counted?.replace(',', '.') ?? ''
      const counted = countedRaw === '' || Number.isNaN(Number(countedRaw)) ? null : Number(countedRaw)
      return {
        account_id: row.account_id,
        // Zpět na znaménkovou bázi serveru (MD − D).
        counted_balance: counted === null ? null : sideSign(row) * counted,
        resolution: rowResolved(row) ? 'resolved' as const : 'open' as const,
        note: e?.note?.trim() || null,
      }
    }),
  }
}

async function save(complete: boolean) {
  if (!filters.period_id || !report.value || saving.value) return
  saving.value = true
  try {
    const res = await accountingApi.saveClosingInventory(Number(filters.period_id), buildPayload(complete))
    rowVersion.value = res.row_version
    if (complete && !res.completed) {
      toast.warning(t('accounting.balance_inventory.completed_blocked', { count: res.unresolved_count }))
    } else if (res.completed) {
      toast.success(t('accounting.balance_inventory.saved_completed'))
    } else {
      toast.success(t('accounting.balance_inventory.saved'))
    }
    await load()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  } finally {
    saving.value = false
  }
}

function statementLink(row: BalanceInventoryRow) {
  return {
    name: 'accounting-account-statement',
    params: { accountId: row.account_id },
    query: { from: report.value?.period.starts_on, to: report.value?.period.ends_on },
  }
}

const exporting = ref(false)
async function exportFile(format: 'pdf' | 'xlsx') {
  if (!filters.period_id || !report.value) return
  exporting.value = true
  try {
    const r = await accountingApi.exportReport('/accounting/reports/balance-inventory/export', { period_id: Number(filters.period_id), format })
    downloadBlob(r.data as unknown as Blob, `inventarizace-uctu-${report.value.period.fiscal_year}.${format}`)
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
  const open = periods.value.filter(p => p.status === 'open' || p.status === 'closing')
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
    <div class="flex flex-wrap items-center justify-between gap-2 mb-4">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('accounting.balance_inventory.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5">{{ t('accounting.balance_inventory.subtitle') }}</p>
      </div>
      <div class="flex flex-wrap items-center gap-2">
        <button :disabled="!report || exporting" @click="exportFile('pdf')" :class="btnOutline('primary')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.download" /></svg>
          {{ t('accounting.balance_inventory.export_pdf') }}
        </button>
        <button :disabled="!report || exporting" @click="exportFile('xlsx')" :class="btnOutline('primary')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.download" /></svg>
          {{ t('accounting.balance_inventory.export_xlsx') }}
        </button>
      </div>
    </div>

    <!-- Filtry -->
    <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-3 mb-4">
      <div class="grid grid-cols-1 sm:grid-cols-4 gap-3">
        <div>
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.balance_inventory.filter_period') }}</label>
          <select v-model="filters.period_id" @change="load"
            class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface">
            <option v-for="p in periods" :key="p.id" :value="p.id">{{ p.fiscal_year }}</option>
          </select>
        </div>
      </div>
    </div>

    <div v-if="report" class="mb-4 px-3 py-2 rounded-md bg-neutral-50 border border-neutral-200 text-neutral-600 text-sm space-y-1">
      <div>{{ t('accounting.balance_inventory.as_of_hint', { date: report.as_of }) }}</div>
      <div>{{ t('accounting.balance_inventory.sign_hint') }}</div>
    </div>

    <div v-if="report && report.draft_count > 0"
      class="mb-4 px-3 py-2 rounded-md bg-warning-50 border border-warning-500/30 text-warning-600 text-sm">
      {{ t('accounting.balance_inventory.draft_warning', { n: report.draft_count }) }}
    </div>

    <!-- Hlavička inventarizace + stav (EP-6) -->
    <div v-if="report" class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-4 mb-4 space-y-3">
      <div class="flex items-center justify-between gap-2 flex-wrap">
        <h2 class="text-sm font-semibold text-neutral-700">{{ t('accounting.balance_inventory.protocol_title') }}</h2>
        <div class="flex items-center gap-2 text-xs">
          <span v-if="inventoryStatus === 'completed'" class="px-2 py-0.5 rounded bg-success-100 text-success-700 font-medium">
            {{ t('accounting.balance_inventory.status_completed') }}
          </span>
          <span v-else class="px-2 py-0.5 rounded bg-warning-100 text-warning-700 font-medium">
            {{ t('accounting.balance_inventory.status_in_progress') }}
          </span>
          <span class="px-2 py-0.5 rounded font-medium"
            :class="unresolvedCount === 0 ? 'bg-neutral-100 text-neutral-600' : 'bg-danger-100 text-danger-700'">
            {{ t('accounting.balance_inventory.unresolved', { n: unresolvedCount }) }}
          </span>
        </div>
      </div>
      <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
        <div>
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.balance_inventory.responsible_person') }}</label>
          <input v-model="header.responsible_person" :disabled="!editable" type="text"
            class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface disabled:bg-neutral-50" />
        </div>
        <div>
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.balance_inventory.inventory_date') }}</label>
          <DateInput v-model="header.inventory_date" :disabled="!editable"
            class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface disabled:bg-neutral-50" />
        </div>
        <div>
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.balance_inventory.protocol_ref') }}</label>
          <input v-model="header.protocol_ref" :disabled="!editable" type="text"
            :placeholder="t('accounting.balance_inventory.protocol_ref_ph')"
            class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface disabled:bg-neutral-50" />
        </div>
      </div>
      <div v-if="report.inventory?.back_filled" class="text-xs text-neutral-600 flex items-start gap-1.5 rounded-md bg-neutral-50 dark:bg-neutral-500/[0.06] border border-neutral-200 px-2.5 py-1.5">
        <svg class="w-3.5 h-3.5 mt-0.5 shrink-0 text-neutral-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        <span>{{ t('accounting.balance_inventory.back_filled_hint') }}</span>
      </div>
      <div v-else-if="!editable" class="text-xs text-neutral-500">{{ t('accounting.balance_inventory.readonly_hint') }}</div>
      <div v-if="editable" class="flex items-center justify-end gap-2 pt-1">
        <button :disabled="saving" @click="save(false)" :class="btnOutline('primary')">
          {{ t('accounting.balance_inventory.save') }}
        </button>
        <button :disabled="saving" @click="save(true)" :class="btnFilled('primary')">
          {{ t('accounting.balance_inventory.save_complete') }}
        </button>
      </div>
    </div>

    <div v-if="loading" class="text-center text-neutral-500 py-12 text-sm">{{ t('common.loading') }}</div>

    <EmptyState v-else-if="!report || report.rows.length === 0" boxed accent="neutral" icon="clipboardCheck" :title="t('accounting.balance_inventory.empty')" />

    <div v-else class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
            <tr>
              <th class="px-3 py-2 text-left font-medium w-24">{{ t('accounting.balance_inventory.col_account') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('accounting.balance_inventory.col_name') }}</th>
              <th class="px-3 py-2 text-center font-medium w-16">{{ t('accounting.balance_inventory.col_side') }}</th>
              <th class="px-3 py-2 text-right font-medium">{{ t('accounting.balance_inventory.col_book') }}</th>
              <th class="px-3 py-2 text-right font-medium">{{ t('accounting.balance_inventory.col_counted') }}</th>
              <th class="px-3 py-2 text-right font-medium">{{ t('accounting.balance_inventory.col_difference') }}</th>
              <th class="px-3 py-2 text-center font-medium">{{ t('accounting.balance_inventory.col_resolved') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('accounting.balance_inventory.col_hint') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="row in report.rows" :key="row.account_id" class="hover:bg-neutral-50"
              :class="!rowResolved(row) ? 'bg-danger-50/40' : ''">
              <td class="px-3 py-2 align-top">
                <RouterLink :to="statementLink(row)"
                  class="font-mono text-primary-600 hover:text-primary-700 hover:underline">
                  {{ row.account_code }}
                </RouterLink>
              </td>
              <td class="px-3 py-2 align-top">{{ row.name }}</td>
              <td class="px-3 py-2 text-center align-top text-xs text-neutral-500 font-mono">{{ sideLabel(row) }}</td>
              <td class="px-3 py-2 text-right font-mono align-top">{{ formatMoney(bookBalance(row)) }}</td>
              <td class="px-3 py-2 text-right align-top">
                <input v-if="editable" v-model="edits[row.account_id].counted" type="text" inputmode="decimal"
                  class="w-28 h-8 px-2 text-right font-mono border border-neutral-300 rounded-md text-sm bg-surface" />
                <span v-else class="font-mono">{{ countedBalance(row) != null ? formatMoney(countedBalance(row)!) : '—' }}</span>
              </td>
              <td class="px-3 py-2 text-right font-mono align-top"
                :class="liveDifference(row) != null && Math.abs(liveDifference(row)!) >= 0.005 ? 'text-danger-600 font-semibold' : 'text-neutral-500'">
                {{ liveDifference(row) != null ? formatMoney(liveDifference(row)!) : '—' }}
              </td>
              <td class="px-3 py-2 text-center align-top">
                <input v-if="editable" type="checkbox" v-model="edits[row.account_id].resolved"
                  class="h-4 w-4 rounded border-neutral-300 text-primary-600 focus:ring-primary-500" />
                <svg v-else-if="rowResolved(row)" class="w-4 h-4 inline text-success-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                <span v-else class="text-danger-600">•</span>
              </td>
              <td class="px-3 py-2 text-neutral-600 align-top">
                <div>{{ row.documentation_hint }}</div>
                <input v-if="editable" v-model="edits[row.account_id].note" type="text"
                  :placeholder="t('accounting.balance_inventory.note_ph')"
                  class="mt-1 w-full h-7 px-2 border border-neutral-200 rounded text-xs bg-surface" />
                <div v-else-if="row.item_note" class="mt-0.5 text-xs text-neutral-500">{{ row.item_note }}</div>
              </td>
            </tr>
          </tbody>
          <tfoot>
            <!-- Na normální straně účtu se aktiva a pasiva sčítat nedají (vyšel by
                 součet obou stran rozvahy dohromady) — proto Σ MD a Σ D zvlášť,
                 což je zároveň kontrola, že se rozvaha rovná. -->
            <tr class="border-t-2 border-neutral-300 font-semibold bg-neutral-50">
              <td class="px-3 py-2" colspan="3">{{ t('accounting.balance_inventory.totals', { n: report.count }) }}</td>
              <td class="px-3 py-2 text-right font-mono text-xs whitespace-nowrap">
                <div>{{ t('accounting.balance_inventory.side_debit') }} {{ formatMoney(report.totals.ks_md) }}</div>
                <div>{{ t('accounting.balance_inventory.side_credit') }} {{ formatMoney(report.totals.ks_d) }}</div>
              </td>
              <td class="px-3 py-2"></td>
              <td class="px-3 py-2"></td>
              <td class="px-3 py-2"></td>
              <td class="px-3 py-2"></td>
            </tr>
          </tfoot>
        </table>
      </div>
    </div>
  </div>
</template>

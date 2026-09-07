<script setup lang="ts">
import { ref, onMounted, reactive, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink, useRoute } from 'vue-router'
import {
  accountingApi,
  type AccountingPeriod,
  type SaldoReport,
  type SaldoAccountBlock,
  type SaldoItem,
} from '@/api/accounting'
import type { SortPref } from '@/api/preferences'
import { useToast } from '@/composables/useToast'
import { formatMoney, formatDate } from '@/composables/useFormat'
import { ICONS, btnOutline, btnFilled } from '@/components/ui/buttonStyles'
import EmptyState from '@/components/ui/EmptyState.vue'
import SortableTh from '@/components/ui/SortableTh.vue'
import DateInput from '@/components/ui/DateInput.vue'

const { t } = useI18n()
const toast = useToast()
const route = useRoute()

const periods = ref<AccountingPeriod[]>([])
const report = ref<SaldoReport | null>(null)
const loading = ref(false)

const ACCOUNT_OPTIONS = ['all', '311', '321', '314', '324']

const filters = reactive({
  period_id: '' as number | '',
  as_of: '',
  account: 'all',
})

function queryParams() {
  return {
    period_id: Number(filters.period_id),
    as_of: filters.as_of || undefined,
    account: filters.account || 'all',
  }
}

// ── Task #2: přepínač "podle partnera" / "podle dokladů" (plochý seznam) ────
type ViewMode = 'partner' | 'flat'
const viewMode = ref<ViewMode>('flat')

/**
 * Strana salda podle normální strany účtu: 311/314 (debet) = co dluží nám,
 * 321/324 (kredit) = co dlužíme my. Plochý pohled dřív míchal obojí do jedné
 * tabulky řazené dle splatnosti, takže mezi nezaplacenými fakturami odběratelů
 * seděla přijatá faktura od dodavatele — účetně opačná věc, kterou od sebe
 * odlišoval jen tříznakový kód účtu v prvním sloupci.
 */
type SaldoSide = 'receivable' | 'payable'

const SIDE_ORDER: SaldoSide[] = ['receivable', 'payable']

function sideOfAccount(normalSide: string): SaldoSide {
  return normalSide === 'credit' ? 'payable' : 'receivable'
}

interface FlatRow extends SaldoItem {
  account_code: string
  partner_id: number
  partner_name: string
  side: SaldoSide
}

/** Plochý rozpad accounts→partners→items, beze seskupení — zdroj pro flat pohled i export. */
const flatRows = computed<FlatRow[]>(() => {
  if (!report.value) return []
  const rows: FlatRow[] = []
  for (const acc of report.value.accounts) {
    const side = sideOfAccount(acc.account.normal_side)
    for (const p of acc.partners) {
      for (const it of p.items) {
        rows.push({ ...it, account_code: acc.account.code, partner_id: p.partner_id, partner_name: p.partner_name, side })
      }
    }
  }
  return rows
})

const partnerOptions = computed(() => {
  const seen = new Map<number, string>()
  for (const r of flatRows.value) seen.set(r.partner_id, r.partner_name)
  return Array.from(seen.entries())
    .map(([id, name]) => ({ id, name }))
    .sort((a, b) => a.name.localeCompare(b.name, 'cs'))
})

const flatFilters = reactive({
  partner_id: '' as number | '',
  min_overdue_days: '' as number | '',
})

// Vybraný partner v odjeté sestavě zmizel (jiné období/účet) → filtr by tiše
// vracel prázdno bez vysvětlení; reset je srozumitelnější než mrtvý filtr.
watch(partnerOptions, (opts) => {
  if (flatFilters.partner_id !== '' && !opts.some(o => o.id === flatFilters.partner_id)) {
    flatFilters.partner_id = ''
  }
})

const sort = ref<SortPref>({ key: 'due_date', dir: 'asc' })
function toggleSort(key: string) {
  sort.value = sort.value.key === key
    ? { key, dir: sort.value.dir === 'asc' ? 'desc' : 'asc' }
    : { key, dir: 'asc' }
}

const filteredFlatRows = computed<FlatRow[]>(() => {
  let rows = flatRows.value
  if (flatFilters.partner_id !== '') {
    rows = rows.filter(r => r.partner_id === flatFilters.partner_id)
  }
  const minDays = Number(flatFilters.min_overdue_days) || 0
  if (minDays > 0) {
    rows = rows.filter(r => r.days_overdue >= minDays)
  }
  const key = sort.value.key as keyof FlatRow
  const dir = sort.value.dir === 'asc' ? 1 : -1
  return [...rows].sort((a, b) => {
    const av = a[key]
    const bv = b[key]
    if (typeof av === 'number' && typeof bv === 'number') return (av - bv) * dir
    return String(av ?? '').localeCompare(String(bv ?? ''), 'cs') * dir
  })
})

/**
 * Sestava rozdělená na pohledávky a závazky — bloky konfrontace i řádky dokladů
 * dostane každá strana vlastní. Strana bez účtu v sestavě se vůbec nevykreslí.
 */
const sections = computed(() => {
  const r = report.value
  if (!r) return []
  return SIDE_ORDER
    .map(side => {
      const rows = filteredFlatRows.value.filter(x => x.side === side)
      return {
        side,
        blocks: r.accounts.filter(b => sideOfAccount(b.account.normal_side) === side),
        rows,
        total: Math.round(rows.reduce((s, x) => s + x.remaining_czk, 0) * 100) / 100,
      }
    })
    .filter(s => s.blocks.length > 0)
})

// ── Task #3: as_of napříč obdobími — UI upozornění, když se liší od výběru ──
const asOfPeriodNote = computed(() => {
  const r = report.value
  if (!r) return null
  if (r.as_of_period === null) return { kind: 'missing' as const }
  if (r.as_of_period.id !== r.period.id) return { kind: 'different' as const, period: r.as_of_period }
  return null
})

function switchToAsOfPeriod() {
  const note = asOfPeriodNote.value
  if (note?.kind !== 'different') return
  filters.period_id = note.period.id
  load()
}

async function load() {
  if (!filters.period_id) return
  loading.value = true
  try {
    report.value = await accountingApi.getSaldo(queryParams())
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
    report.value = null
  } finally {
    loading.value = false
  }
}

// Expand/collapse per partner (klíč = "accCode:partnerId").
const expanded = reactive<Record<string, boolean>>({})
function partnerKey(accCode: string, partnerId: number) {
  return `${accCode}:${partnerId}`
}
function toggle(accCode: string, partnerId: number) {
  const k = partnerKey(accCode, partnerId)
  expanded[k] = !expanded[k]
}

function docLink(it: SaldoItem) {
  return it.doc_type === 'purchase_invoice'
    ? { name: 'purchase-invoice-detail', params: { id: it.doc_id } }
    : { name: 'invoice-detail', params: { id: it.doc_id } }
}

const exporting = ref(false)
async function exportFile(format: 'pdf' | 'xlsx') {
  if (!filters.period_id || !report.value) return
  exporting.value = true
  try {
    // Task #2: PDF zůstává vždy grouped (zákonný inventarizační protokol) —
    // view ovlivňuje jen pracovní XLSX export dle aktuálního přepínače.
    const view = viewMode.value === 'flat' ? 'flat' : 'grouped'
    const r = await accountingApi.exportReport('/accounting/reports/saldo/export', { ...queryParams(), format, view })
    const suffix = format === 'xlsx' && view === 'flat' ? '-doklady' : ''
    downloadBlob(r.data as unknown as Blob, `saldokonto${suffix}-${report.value.as_of}.${format}`)
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

function accountBlockLabel(b: SaldoAccountBlock) {
  return `${b.account.code} — ${b.account.name}`
}

/**
 * Deep-link `?period_id=&as_of=&account=&view=&partner_id=&min_overdue_days=`.
 * Bez toho odkaz na konkrétní saldokonto (typicky „účet 311 k rozvahovému dni")
 * otevřel stránku ve výchozím nastavení a query tiše zahodil — příjemce viděl
 * jiná čísla, než co mu odesílatel poslal.
 */
function applyQuery(): { periodId: number | null } {
  const q = route.query
  const str = (v: unknown): string => (typeof v === 'string' ? v : '')
  if (/^\d{4}-\d{2}-\d{2}$/.test(str(q.as_of))) filters.as_of = str(q.as_of)
  if (ACCOUNT_OPTIONS.includes(str(q.account))) filters.account = str(q.account)
  if (str(q.view) === 'partner' || str(q.view) === 'flat') viewMode.value = str(q.view) as ViewMode
  const partnerId = Number(str(q.partner_id))
  if (Number.isInteger(partnerId) && partnerId > 0) flatFilters.partner_id = partnerId
  const minDays = Number(str(q.min_overdue_days))
  if (Number.isInteger(minDays) && minDays > 0) flatFilters.min_overdue_days = minDays
  const periodId = Number(str(q.period_id))
  return { periodId: Number.isInteger(periodId) && periodId > 0 ? periodId : null }
}

onMounted(async () => {
  try { periods.value = await accountingApi.listPeriods() } catch { periods.value = [] }
  const { periodId } = applyQuery()
  const open = periods.value.filter(p => p.status === 'open')
  const def = open.length
    ? open.reduce((a, b) => (b.fiscal_year > a.fiscal_year ? b : a))
    : periods.value[0]
  // Období z adresy jen tehdy, když opravdu existuje — jinak by překlep v odkazu
  // shodil načtení na 404 místo toho, aby stránka ukázala výchozí rok.
  const fromQuery = periodId !== null && periods.value.some(p => p.id === periodId) ? periodId : null
  const use = fromQuery ?? def?.id ?? null
  if (use !== null) {
    filters.period_id = use
    await load()
  }
})
</script>

<template>
  <div>
    <div class="flex flex-wrap items-center justify-between gap-2 mb-4">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('accounting.saldo.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5">{{ t('accounting.saldo.subtitle') }}</p>
      </div>
      <div class="flex flex-wrap items-center gap-2">
        <div class="flex items-center gap-1.5" role="group" :aria-label="t('accounting.saldo.view_group_label')">
          <button type="button" @click="viewMode = 'flat'"
            :class="viewMode === 'flat' ? btnFilled('neutral') : btnOutline('neutral')">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.doc" /></svg>
            {{ t('accounting.saldo.view_by_document') }}
          </button>
          <button type="button" @click="viewMode = 'partner'"
            :class="viewMode === 'partner' ? btnFilled('neutral') : btnOutline('neutral')">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.user" /></svg>
            {{ t('accounting.saldo.view_by_partner') }}
          </button>
        </div>
        <button :disabled="!report || exporting" @click="exportFile('pdf')" :class="btnOutline('primary')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.download" /></svg>
          {{ t('accounting.saldo.export_pdf') }}
        </button>
        <button :disabled="!report || exporting" @click="exportFile('xlsx')" :class="btnOutline('primary')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.download" /></svg>
          {{ t('accounting.saldo.export_xlsx') }}
        </button>
      </div>
    </div>

    <!-- Filtry -->
    <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-3 mb-4">
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
        <div>
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.saldo.filter_period') }}</label>
          <select v-model="filters.period_id" @change="load"
            class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface">
            <option v-for="p in periods" :key="p.id" :value="p.id">{{ p.fiscal_year }}</option>
          </select>
        </div>
        <div>
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.saldo.filter_as_of') }}</label>
          <DateInput v-model="filters.as_of" @change="load"
            class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm" />
        </div>
        <div>
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.saldo.filter_account') }}</label>
          <select v-model="filters.account" @change="load"
            class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface">
            <option v-for="a in ACCOUNT_OPTIONS" :key="a" :value="a">
              {{ a === 'all' ? t('accounting.saldo.account_all') : t(`accounting.saldo.account_${a}`) }}
            </option>
          </select>
        </div>
        <div v-if="viewMode === 'flat'">
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.saldo.filter_partner') }}</label>
          <select v-model="flatFilters.partner_id" class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface">
            <option value="">{{ t('accounting.saldo.filter_partner_all') }}</option>
            <option v-for="p in partnerOptions" :key="p.id" :value="p.id">{{ p.name }}</option>
          </select>
        </div>
        <div v-if="viewMode === 'flat'">
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.saldo.filter_overdue_min') }}</label>
          <input v-model="flatFilters.min_overdue_days" type="number" min="0" placeholder="0"
            class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm" />
        </div>
      </div>
      <div v-if="asOfPeriodNote" class="mt-3 flex flex-wrap items-center gap-2 text-xs px-3 py-2 rounded-md bg-warning-50 text-warning-700 border border-warning-500/30">
        <span v-if="asOfPeriodNote.kind === 'different'">
          {{ t('accounting.saldo.period_mismatch_hint', { fiscal_year: asOfPeriodNote.period.fiscal_year }) }}
        </span>
        <span v-else>{{ t('accounting.saldo.period_missing_hint') }}</span>
        <button v-if="asOfPeriodNote.kind === 'different'" type="button" @click="switchToAsOfPeriod"
          :class="[btnOutline('warning'), 'h-7 px-2 text-xs']">
          {{ t('accounting.saldo.period_mismatch_switch', { fiscal_year: asOfPeriodNote.period.fiscal_year }) }}
        </button>
      </div>
    </div>

    <div v-if="loading" class="text-center text-neutral-500 py-12 text-sm">{{ t('common.loading') }}</div>

    <EmptyState v-else-if="!report || report.accounts.length === 0" boxed accent="neutral" icon="coin" :title="t('accounting.saldo.empty')" />

    <!-- Task #2: plochý seznam dokladů — samostatně za pohledávky a za závazky -->
    <template v-else-if="viewMode === 'flat'">
      <div v-for="s in sections" :key="s.side" class="mb-6 last:mb-0">
        <div class="flex flex-wrap items-baseline gap-x-2 gap-y-0.5 mb-2">
          <h2 class="text-base font-semibold">{{ t(`accounting.saldo.side_${s.side}`) }}</h2>
          <span class="text-xs text-neutral-500">{{ t(`accounting.saldo.side_${s.side}_hint`) }}</span>
        </div>

        <!-- Konfrontace se zůstatkem hlavní knihy patří do OBOU pohledů. Dokud byla
             jen v pohledu podle partnera, četl uživatel seznam dokladů jako úplný,
             i když se na účtu lišil o statisíce (ruční zápisy, nezaúčtované úhrady). -->
        <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden mb-3">
          <div v-for="b in s.blocks" :key="b.account.id" class="border-b border-neutral-100 last:border-b-0">
            <div class="px-4 py-3 grid grid-cols-1 sm:grid-cols-4 gap-3 text-sm">
              <div>
                <div class="text-xs text-neutral-500 uppercase tracking-wide">{{ t('accounting.saldo.col_account') }}</div>
                <div class="font-semibold">{{ accountBlockLabel(b) }}</div>
              </div>
              <div>
                <div class="text-xs text-neutral-500 uppercase tracking-wide">{{ t('accounting.saldo.gl_balance') }}</div>
                <div class="font-mono font-semibold">{{ formatMoney(b.gl_balance) }}</div>
              </div>
              <div>
                <div class="text-xs text-neutral-500 uppercase tracking-wide">{{ t('accounting.saldo.open_items_total') }}</div>
                <div class="font-mono font-semibold">{{ formatMoney(b.open_items_total) }}</div>
              </div>
              <div>
                <div class="text-xs text-neutral-500 uppercase tracking-wide">{{ t('accounting.saldo.difference') }}</div>
                <div class="font-mono font-semibold flex items-center gap-1"
                  :class="b.matches ? 'text-success-600' : 'text-danger-500'">
                  <span>{{ b.matches ? '✓' : '✗' }}</span>
                  <span>{{ formatMoney(b.difference) }}</span>
                </div>
              </div>
            </div>
            <div v-if="!b.matches" class="px-4 py-2 text-xs text-danger-600 bg-danger-50 border-t border-danger-500/20">
              {{ t('accounting.saldo.difference_hint') }}
            </div>
          </div>
        </div>

        <EmptyState v-if="s.rows.length === 0" boxed accent="neutral" icon="search" :title="t('accounting.saldo.flat_empty')" />
        <div v-else class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
          <div class="overflow-x-auto">
            <table class="w-full text-sm">
              <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
                <tr>
                  <SortableTh :label="t('accounting.saldo.col_account')" sort-key="account_code" :sort="sort" @toggle="toggleSort" />
                  <SortableTh :label="t('accounting.saldo.col_partner')" sort-key="partner_name" :sort="sort" @toggle="toggleSort" />
                  <SortableTh :label="t('accounting.saldo.col_doc')" sort-key="doc_no" :sort="sort" @toggle="toggleSort" />
                  <SortableTh :label="t('accounting.saldo.col_issue')" sort-key="issue_date" :sort="sort" @toggle="toggleSort" />
                  <SortableTh :label="t('accounting.saldo.col_due')" sort-key="due_date" :sort="sort" @toggle="toggleSort" />
                  <SortableTh :label="t('accounting.saldo.col_overdue')" sort-key="days_overdue" :sort="sort" align="right" @toggle="toggleSort" />
                  <SortableTh :label="t('accounting.saldo.col_amount')" sort-key="booked_czk" :sort="sort" align="right" @toggle="toggleSort" />
                  <SortableTh :label="t('accounting.saldo.col_paid')" sort-key="paid_czk" :sort="sort" align="right" @toggle="toggleSort" />
                  <SortableTh :label="t('accounting.saldo.col_remaining')" sort-key="remaining_czk" :sort="sort" align="right" @toggle="toggleSort" />
                </tr>
              </thead>
              <tbody class="divide-y divide-neutral-100">
                <tr v-for="it in s.rows" :key="`${it.account_code}-${it.doc_type}-${it.doc_id}`" class="hover:bg-neutral-50">
                  <td class="px-3 py-2 font-mono text-xs whitespace-nowrap">{{ it.account_code }}</td>
                  <td class="px-3 py-2">{{ it.partner_name }}</td>
                  <td class="px-3 py-2">
                    <RouterLink :to="docLink(it)" class="text-primary-600 hover:text-primary-700 hover:underline font-mono">
                      {{ it.doc_no }}
                    </RouterLink>
                  </td>
                  <td class="px-3 py-2 whitespace-nowrap">{{ formatDate(it.issue_date) }}</td>
                  <td class="px-3 py-2 whitespace-nowrap">{{ formatDate(it.due_date) }}</td>
                  <td class="px-3 py-2 text-right" :class="it.days_overdue > 0 ? 'text-danger-500 font-medium' : 'text-neutral-400'">
                    {{ it.days_overdue > 0 ? it.days_overdue : '—' }}
                  </td>
                  <td class="px-3 py-2 text-right font-mono">
                    {{ formatMoney(it.booked_czk) }}
                    <span v-if="it.currency_code !== 'CZK'" class="block text-xs text-neutral-400">
                      {{ formatMoney(it.amount_foreign) }} {{ it.currency_code }}
                    </span>
                  </td>
                  <td class="px-3 py-2 text-right font-mono">{{ formatMoney(it.paid_czk) }}</td>
                  <td class="px-3 py-2 text-right font-mono">{{ formatMoney(it.remaining_czk) }}</td>
                </tr>
              </tbody>
              <tfoot class="bg-neutral-50 text-sm font-semibold">
                <tr>
                  <td class="px-3 py-2" colspan="8">{{ t(`accounting.saldo.side_${s.side}_total`) }}</td>
                  <td class="px-3 py-2 text-right font-mono">{{ formatMoney(s.total) }}</td>
                </tr>
              </tfoot>
            </table>
          </div>
        </div>
      </div>
    </template>

    <!-- Task #2: pohled "podle partnera" — účty seskupené po stranách salda -->
    <div v-else-if="viewMode === 'partner'" class="space-y-8">
      <div v-for="s in sections" :key="s.side" class="space-y-3">
        <div class="flex flex-wrap items-baseline gap-x-2 gap-y-0.5">
          <h2 class="text-base font-semibold">{{ t(`accounting.saldo.side_${s.side}`) }}</h2>
          <span class="text-xs text-neutral-500">{{ t(`accounting.saldo.side_${s.side}_hint`) }}</span>
        </div>
        <div v-for="b in s.blocks" :key="b.account.id"
          class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
        <div class="px-4 py-3 border-b border-neutral-200">
          <h3 class="text-sm font-semibold">{{ accountBlockLabel(b) }}</h3>
        </div>

        <!-- Konfrontace -->
        <div class="px-4 py-3 grid grid-cols-1 sm:grid-cols-3 gap-3 text-sm border-b border-neutral-100 bg-neutral-50">
          <div>
            <div class="text-xs text-neutral-500 uppercase tracking-wide">{{ t('accounting.saldo.gl_balance') }}</div>
            <div class="font-mono font-semibold">{{ formatMoney(b.gl_balance) }}</div>
          </div>
          <div>
            <div class="text-xs text-neutral-500 uppercase tracking-wide">{{ t('accounting.saldo.open_items_total') }}</div>
            <div class="font-mono font-semibold">{{ formatMoney(b.open_items_total) }}</div>
          </div>
          <div>
            <div class="text-xs text-neutral-500 uppercase tracking-wide">{{ t('accounting.saldo.difference') }}</div>
            <div class="font-mono font-semibold flex items-center gap-1"
              :class="b.matches ? 'text-success-600' : 'text-danger-500'">
              <span>{{ b.matches ? '✓' : '✗' }}</span>
              <span>{{ formatMoney(b.difference) }}</span>
            </div>
          </div>
        </div>

        <div v-if="!b.matches" class="px-4 py-2 text-xs text-danger-600 bg-danger-50 border-b border-danger-500/20">
          {{ t('accounting.saldo.difference_hint') }}
        </div>

        <EmptyState v-if="b.partners.length === 0" dense accent="success" icon="checkCircle" :title="t('accounting.saldo.no_open_items')" />

        <div v-else class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
              <tr>
                <th class="px-3 py-2 text-left font-medium w-8"></th>
                <th class="px-3 py-2 text-left font-medium">{{ t('accounting.saldo.col_partner') }}</th>
                <th class="px-3 py-2 text-left font-medium">{{ t('accounting.saldo.col_doc') }}</th>
                <th class="px-3 py-2 text-left font-medium">{{ t('accounting.saldo.col_issue') }}</th>
                <th class="px-3 py-2 text-left font-medium">{{ t('accounting.saldo.col_due') }}</th>
                <th class="px-3 py-2 text-right font-medium">{{ t('accounting.saldo.col_overdue') }}</th>
                <th class="px-3 py-2 text-right font-medium">{{ t('accounting.saldo.col_amount') }}</th>
                <th class="px-3 py-2 text-right font-medium">{{ t('accounting.saldo.col_paid') }}</th>
                <th class="px-3 py-2 text-right font-medium">{{ t('accounting.saldo.col_remaining') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <template v-for="p in b.partners" :key="p.partner_id">
                <tr class="cursor-pointer hover:bg-neutral-50 font-medium bg-neutral-50/50"
                  @click="toggle(b.account.code, p.partner_id)">
                  <td class="px-3 py-2">
                    <span class="inline-block transition-transform" :class="{ 'rotate-90': expanded[partnerKey(b.account.code, p.partner_id)] }">▸</span>
                  </td>
                  <td class="px-3 py-2" colspan="7">{{ p.partner_name }}</td>
                  <td class="px-3 py-2 text-right font-mono">{{ formatMoney(p.total_remaining) }}</td>
                </tr>
                <template v-if="expanded[partnerKey(b.account.code, p.partner_id)]">
                  <tr v-for="it in p.items" :key="`${it.doc_type}-${it.doc_id}`" class="hover:bg-neutral-50">
                    <td class="px-3 py-2"></td>
                    <td class="px-3 py-2"></td>
                    <td class="px-3 py-2">
                      <RouterLink :to="docLink(it)" class="text-primary-600 hover:text-primary-700 hover:underline font-mono">
                        {{ it.doc_no }}
                      </RouterLink>
                    </td>
                    <td class="px-3 py-2 whitespace-nowrap">{{ formatDate(it.issue_date) }}</td>
                    <td class="px-3 py-2 whitespace-nowrap">{{ formatDate(it.due_date) }}</td>
                    <td class="px-3 py-2 text-right" :class="it.days_overdue > 0 ? 'text-danger-500 font-medium' : 'text-neutral-400'">
                      {{ it.days_overdue > 0 ? it.days_overdue : '—' }}
                    </td>
                    <td class="px-3 py-2 text-right font-mono">
                      {{ formatMoney(it.booked_czk) }}
                      <span v-if="it.currency_code !== 'CZK'" class="block text-xs text-neutral-400">
                        {{ formatMoney(it.amount_foreign) }} {{ it.currency_code }}
                      </span>
                    </td>
                    <td class="px-3 py-2 text-right font-mono">{{ formatMoney(it.paid_czk) }}</td>
                    <td class="px-3 py-2 text-right font-mono">{{ formatMoney(it.remaining_czk) }}</td>
                  </tr>
                </template>
              </template>
            </tbody>
          </table>
        </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted, reactive, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter, RouterLink } from 'vue-router'
import {
  accountingApi,
  type AccountStatementReport,
  type AccountStatementItem,
  type OpenItemsReport,
  type OpenItemLine,
  type LinePairing,
  type PairingSuggestion,
} from '@/api/accounting'
import { useToast } from '@/composables/useToast'
import { formatDate, formatMoney } from '@/composables/useFormat'
import { useAccountBalance } from '@/composables/useAccountBalance'
import { ICONS, btnOutline, btnFilled, BTN_ICON_SM_BASE, OUTLINE } from '@/components/ui/buttonStyles'
import JournalSourceDrawer from '@/components/accounting/JournalSourceDrawer.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { journalSourceLink, journalEntryLink } from '@/utils/journalSourceLink'
import { appIsoDate, appYear } from '@/utils/date'
import DateInput from '@/components/ui/DateInput.vue'
import { useAuthStore } from '@/stores/auth'

const { t } = useI18n()
const { balanceLabel, formatBalance, formatBalanceAmount } = useAccountBalance()
const route = useRoute()
const router = useRouter()
const toast = useToast()
const auth = useAuthStore()

const accountId = computed(() => Number(route.params.accountId))
const canWrite = computed(() => auth.canWrite('accounting'))

/** Pohyby (opis) nebo otevřené položky s párováním do okruhů. */
type Mode = 'movements' | 'open'
const mode = ref<Mode>(route.query.mode === 'open' ? 'open' : 'movements')

/** Náhled zdrojového dokladu zápisu — sdílený drawer s deníkem (read-only). */
const previewEntryId = ref<number | null>(null)

const report = ref<AccountStatementReport | null>(null)
const loading = ref(false)

const page = ref(1)
const perPage = ref(50)
const totalPages = computed(() => {
  if (!report.value) return 1
  return Math.max(1, Math.ceil(report.value.total / (report.value.per_page || perPage.value)))
})

function defaultRange(): { from: string; to: string } {
  const today = new Date()
  return { from: `${appYear(today)}-01-01`, to: appIsoDate(today) }
}

const filters = reactive({
  from: typeof route.query.from === 'string' && route.query.from ? route.query.from : defaultRange().from,
  to: typeof route.query.to === 'string' && route.query.to ? route.query.to : defaultRange().to,
})

async function load() {
  if (!accountId.value) return
  loading.value = true
  try {
    report.value = await accountingApi.getAccountStatement(accountId.value, {
      from: filters.from,
      to: filters.to,
      page: page.value,
      per_page: perPage.value,
    })
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
    report.value = null
  } finally {
    loading.value = false
  }
}

function applyFilters() {
  page.value = 1
  openPage.value = 1
  suggestions.value = null
  reload()
}

function goToPage(p: number) {
  const np = Math.min(Math.max(1, p), totalPages.value)
  if (np !== page.value) {
    page.value = np
    load()
  }
}

/**
 * Drill-down na prvotní doklad dle source_type (sdílené s deníkem a hlavní knihou,
 * viz utils/journalSourceLink.ts); bez rozpoznaného zdroje se jde do deníku na zápis.
 */
function itemLink(it: AccountStatementItem | OpenItemLine) {
  return journalSourceLink(it) ?? journalEntryLink(it.entry_id)
}

/** Karta účtu — kmen, analytiky a odkazy zpátky do knihy/deníku. */
const accountCardLink = computed(() => ({
  name: 'accounting-account-detail',
  params: { accountId: accountId.value },
  query: { from: filters.from, to: filters.to },
}))

/** Zobrazovat sloupec s analytikou má smysl jen u syntetiky (opis pak míchá víc účtů). */
const showLineAccount = computed(() => {
  const items = mode.value === 'open' ? openReport.value?.items ?? [] : report.value?.items ?? []
  const own = mode.value === 'open' ? openReport.value?.account.id : report.value?.account.id
  return items.some(i => i.account_id !== own)
})

const headerAccount = computed(() => (mode.value === 'open' ? openReport.value?.account : report.value?.account) ?? null)

const exporting = ref(false)
async function exportFile(format: 'pdf' | 'xlsx') {
  if (!report.value) return
  exporting.value = true
  try {
    const r = await accountingApi.exportReport(`/accounting/reports/account-statement/${accountId.value}/export`, {
      from: filters.from,
      to: filters.to,
      format,
    })
    downloadBlob(r.data as unknown as Blob, `opis-uctu-${report.value.account.code}-${filters.from}-${filters.to}.${format}`)
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

function currencyLabel(it: { currency: string; amount_foreign: number | null }) {
  if (it.amount_foreign !== null && it.currency !== 'CZK') return `${formatMoney(it.amount_foreign)} ${it.currency}`
  return it.currency
}

// ── Otevřené položky a párování ────────────────────────────────────────────

const openReport = ref<OpenItemsReport | null>(null)
const onlyOpen = ref(route.query.only_open !== '0')
const openPage = ref(1)
const openPerPage = 100
const openTotalPages = computed(() => {
  if (!openReport.value) return 1
  return Math.max(1, Math.ceil(openReport.value.total / (openReport.value.per_page || openPerPage)))
})

/** Výběr řádků napříč stránkami — klíčem je id řádku deníku. */
const selected = ref(new Map<number, OpenItemLine>())
const busy = ref(false)

async function loadOpen() {
  if (!accountId.value) return
  loading.value = true
  try {
    openReport.value = await accountingApi.getOpenItems(accountId.value, {
      as_of: filters.to,
      only_open: onlyOpen.value ? 1 : 0,
      page: openPage.value,
      per_page: openPerPage,
    })
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
    openReport.value = null
  } finally {
    loading.value = false
  }
}

function reload() {
  if (mode.value === 'open') loadOpen()
  else load()
}

function setMode(m: Mode) {
  if (mode.value === m) return
  mode.value = m
  router.replace({ query: { ...route.query, mode: m === 'open' ? 'open' : undefined } })
  reload()
}

function toggleOnlyOpen() {
  openPage.value = 1
  router.replace({ query: { ...route.query, only_open: onlyOpen.value ? undefined : '0' } })
  loadOpen()
}

function goToOpenPage(p: number) {
  const np = Math.min(Math.max(1, p), openTotalPages.value)
  if (np !== openPage.value) {
    openPage.value = np
    loadOpen()
  }
}

function isSelected(it: OpenItemLine) {
  return selected.value.has(it.line_id)
}

function toggleRow(it: OpenItemLine) {
  const next = new Map(selected.value)
  if (next.has(it.line_id)) next.delete(it.line_id)
  else next.set(it.line_id, it)
  selected.value = next
}

const allOnPageSelected = computed(() => {
  const items = openReport.value?.items ?? []
  return items.length > 0 && items.every(i => selected.value.has(i.line_id))
})

function togglePage() {
  const items = openReport.value?.items ?? []
  const next = new Map(selected.value)
  if (allOnPageSelected.value) items.forEach(i => next.delete(i.line_id))
  else items.forEach(i => next.set(i.line_id, i))
  selected.value = next
}

function clearSelection() {
  selected.value = new Map()
}

/** Σ MD − Σ Dal výběru — nula znamená, že vybrané řádky se vyrovnají. */
const selectedDelta = computed(() => {
  let cents = 0
  selected.value.forEach(it => { cents += Math.round(it.amount * 100) * (it.side === 'debit' ? 1 : -1) })
  return cents / 100
})

const selectedPairingIds = computed(() => {
  const ids = new Set<number>()
  selected.value.forEach(it => { if (it.pairing_id) ids.add(it.pairing_id) })
  return [...ids]
})
const selectedUnpaired = computed(() => [...selected.value.values()].filter(it => !it.pairing_id))

const checkOk = computed(() => openReport.value !== null && Math.round(openReport.value.difference * 100) === 0)

function errorMessage(e: any) {
  return e?.response?.data?.error?.message || t('common.error')
}

async function pairSelected() {
  const lines = selectedUnpaired.value
  if (lines.length < 2) return
  busy.value = true
  try {
    const pairing = await accountingApi.createLinePairing(accountId.value, lines.map(l => l.line_id))
    toast.success(t('accounting.account_statement.open.paired_ok', { id: pairing.id }))
    clearSelection()
    suggestions.value = null
    activePairing.value = pairing
    await loadOpen()
  } catch (e: any) {
    toast.error(errorMessage(e))
  } finally {
    busy.value = false
  }
}

async function unpairSelected() {
  const ids = selectedPairingIds.value
  if (ids.length === 0) return
  busy.value = true
  try {
    await accountingApi.deleteLinePairings(ids)
    toast.success(t('accounting.account_statement.open.unpaired_ok'))
    clearSelection()
    if (activePairing.value && ids.includes(activePairing.value.id)) activePairing.value = null
    await loadOpen()
  } catch (e: any) {
    toast.error(errorMessage(e))
  } finally {
    busy.value = false
  }
}

// ── Detail okruhu (spodní panel) ──

const activePairing = ref<LinePairing | null>(null)

async function openPairing(id: number) {
  try {
    activePairing.value = await accountingApi.getLinePairing(id)
  } catch (e: any) {
    toast.error(errorMessage(e))
  }
}

async function addSelectedToPairing() {
  const pairing = activePairing.value
  const lines = selectedUnpaired.value
  if (!pairing || lines.length === 0) return
  busy.value = true
  try {
    activePairing.value = await accountingApi.addLinesToPairing(pairing.id, lines.map(l => l.line_id))
    clearSelection()
    suggestions.value = null
    await loadOpen()
  } catch (e: any) {
    toast.error(errorMessage(e))
  } finally {
    busy.value = false
  }
}

async function removeFromPairing(entryId: number, lineNo: number) {
  const pairing = activePairing.value
  if (!pairing) return
  busy.value = true
  try {
    const res = await accountingApi.removeLineFromPairing(pairing.id, entryId, lineNo)
    activePairing.value = res.pairing
    await loadOpen()
  } catch (e: any) {
    toast.error(errorMessage(e))
  } finally {
    busy.value = false
  }
}

async function deleteActivePairing() {
  const pairing = activePairing.value
  if (!pairing) return
  busy.value = true
  try {
    await accountingApi.deleteLinePairings([pairing.id])
    toast.success(t('accounting.account_statement.open.unpaired_ok'))
    activePairing.value = null
    await loadOpen()
  } catch (e: any) {
    toast.error(errorMessage(e))
  } finally {
    busy.value = false
  }
}

// ── Návrhy párování ──

const suggestionDays = ref(14)
const suggestions = ref<PairingSuggestion[] | null>(null)
const suggestionsLoading = ref(false)

async function loadSuggestions() {
  suggestionsLoading.value = true
  try {
    const res = await accountingApi.getPairingSuggestions(accountId.value, { as_of: filters.to, days: suggestionDays.value })
    suggestions.value = res.items
  } catch (e: any) {
    toast.error(errorMessage(e))
  } finally {
    suggestionsLoading.value = false
  }
}

async function applySuggestions() {
  const list = suggestions.value
  if (!list || list.length === 0) return
  busy.value = true
  try {
    const res = await accountingApi.applyPairingSuggestions(accountId.value, {
      as_of: filters.to,
      days: suggestionDays.value,
      pairs: list.map(s => s.lines.map(l => l.line_id)),
    })
    toast.success(t('accounting.account_statement.open.suggestions_applied', { count: res.created }))
    suggestions.value = null
    clearSelection()
    await loadOpen()
  } catch (e: any) {
    toast.error(errorMessage(e))
  } finally {
    busy.value = false
  }
}

watch(accountId, () => {
  page.value = 1
  openPage.value = 1
  clearSelection()
  activePairing.value = null
  suggestions.value = null
  reload()
})

onMounted(reload)
</script>

<template>
  <div>
    <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
      <div>
        <!-- Kód a název jako samostatné položky flexu s gapem — mezera v textu se při
             zalomení dlouhého názvu ztratí a kód by se na název nalepil. -->
        <h1 class="text-2xl font-semibold flex flex-wrap items-baseline gap-x-2">
          <span>{{ t('accounting.account_statement.title') }}</span>
          <template v-if="headerAccount">
            <span class="text-neutral-400">—</span>
            <RouterLink :to="accountCardLink" class="font-mono text-primary-600 hover:text-primary-700 hover:underline">
              {{ headerAccount.code }}
            </RouterLink>
            <span>{{ headerAccount.name }}</span>
          </template>
        </h1>
        <p class="text-sm text-neutral-500 mt-0.5">
          {{ mode === 'open' ? t('accounting.account_statement.open.subtitle') : t('accounting.account_statement.subtitle') }}
        </p>
      </div>
      <div class="flex flex-wrap items-center gap-2">
        <RouterLink v-if="headerAccount" :to="accountCardLink" :class="[btnOutline('primary'), 'whitespace-nowrap']">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.archive" /></svg>
          {{ t('accounting.account_statement.account_card') }}
        </RouterLink>
        <template v-if="mode === 'movements'">
          <button :disabled="!report || exporting" @click="exportFile('pdf')" :class="[btnOutline('primary'), 'whitespace-nowrap']">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.download" /></svg>
            {{ t('accounting.account_statement.export_pdf') }}
          </button>
          <button :disabled="!report || exporting" @click="exportFile('xlsx')" :class="[btnOutline('primary'), 'whitespace-nowrap']">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.download" /></svg>
            {{ t('accounting.account_statement.export_xlsx') }}
          </button>
        </template>
      </div>
    </div>

    <!-- Režim -->
    <div class="flex flex-wrap gap-1 mb-4 border-b border-neutral-200">
      <button type="button" @click="setMode('movements')"
        :class="['cursor-pointer whitespace-nowrap px-3 py-2 text-sm font-medium border-b-2 -mb-px',
          mode === 'movements' ? 'border-primary-600 text-primary-700' : 'border-transparent text-neutral-500 hover:text-neutral-700']">
        {{ t('accounting.account_statement.mode_movements') }}
      </button>
      <button type="button" @click="setMode('open')"
        :class="['cursor-pointer whitespace-nowrap px-3 py-2 text-sm font-medium border-b-2 -mb-px',
          mode === 'open' ? 'border-primary-600 text-primary-700' : 'border-transparent text-neutral-500 hover:text-neutral-700']">
        {{ t('accounting.account_statement.mode_open') }}
      </button>
    </div>

    <!-- Filtry -->
    <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-3 mb-4">
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 items-end">
        <div v-if="mode === 'movements'">
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.account_statement.filter_from') }}</label>
          <DateInput v-model="filters.from" @change="applyFilters"
            class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm" />
        </div>
        <div>
          <label class="block text-xs font-medium text-neutral-500 mb-1">
            {{ mode === 'open' ? t('accounting.account_statement.open.filter_as_of') : t('accounting.account_statement.filter_to') }}
          </label>
          <DateInput v-model="filters.to" @change="applyFilters"
            class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm" />
        </div>
        <label v-if="mode === 'open'" class="inline-flex items-center gap-2 h-9 text-sm text-neutral-700 cursor-pointer">
          <input v-model="onlyOpen" type="checkbox" class="rounded border-neutral-300" @change="toggleOnlyOpen" />
          {{ t('accounting.account_statement.open.only_open') }}
        </label>
      </div>
    </div>

    <!-- ═══ Pohyby ═══ -->
    <template v-if="mode === 'movements'">
      <div v-if="report" class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-4">
        <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-3">
          <div class="text-xs text-neutral-500" data-testid="opening-label">{{ balanceLabel(t('accounting.account_statement.opening'), report.opening_balance) }}</div>
          <div class="text-lg font-semibold font-mono">{{ formatBalanceAmount(report.opening_balance) }}</div>
        </div>
        <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-3">
          <div class="text-xs text-neutral-500">{{ t('accounting.account_statement.turnover_md') }}</div>
          <div class="text-lg font-semibold font-mono">{{ formatMoney(report.turnover_md) }}</div>
        </div>
        <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-3">
          <div class="text-xs text-neutral-500">{{ t('accounting.account_statement.turnover_d') }}</div>
          <div class="text-lg font-semibold font-mono">{{ formatMoney(report.turnover_d) }}</div>
        </div>
        <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-3">
          <div class="text-xs text-neutral-500" data-testid="closing-label">{{ balanceLabel(t('accounting.account_statement.closing'), report.closing_balance) }}</div>
          <div class="text-lg font-semibold font-mono" data-testid="closing-amount">{{ formatBalanceAmount(report.closing_balance) }}</div>
        </div>
      </div>

      <div v-if="loading" class="text-center text-neutral-500 py-12 text-sm">{{ t('common.loading') }}</div>

      <EmptyState v-else-if="!report || report.items.length === 0" boxed accent="neutral" icon="doc" :title="t('accounting.account_statement.empty')" />

      <div v-else class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
              <tr>
                <th class="px-3 py-2 text-left font-medium w-28">{{ t('accounting.account_statement.col_date') }}</th>
                <th class="px-3 py-2 text-left font-medium w-36">{{ t('accounting.account_statement.col_document') }}</th>
                <th v-if="showLineAccount" class="px-3 py-2 text-left font-medium w-28">{{ t('accounting.account_statement.col_line_account') }}</th>
                <th class="px-3 py-2 text-left font-medium">{{ t('accounting.account_statement.col_description') }}</th>
                <th class="px-3 py-2 text-left font-medium">{{ t('accounting.account_statement.col_partner') }}</th>
                <th class="px-3 py-2 text-left font-medium">{{ t('accounting.account_statement.col_vs') }}</th>
                <th class="px-3 py-2 text-right font-medium w-32">{{ t('accounting.account_statement.col_md') }}</th>
                <th class="px-3 py-2 text-right font-medium w-32">{{ t('accounting.account_statement.col_d') }}</th>
                <th class="px-3 py-2 text-right font-medium w-36">{{ t('accounting.account_statement.col_balance') }}</th>
                <th class="px-3 py-2 text-left font-medium">{{ t('accounting.account_statement.col_counter_account') }}</th>
                <th class="px-3 py-2 text-left font-medium">{{ t('accounting.account_statement.col_pairing') }}</th>
                <th class="px-3 py-2 text-left font-medium">{{ t('accounting.account_statement.col_currency') }}</th>
                <th class="px-3 py-2 w-20"></th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="(it, idx) in report.items" :key="`${it.entry_id}-${idx}`" class="hover:bg-neutral-50">
                <td class="px-3 py-2 whitespace-nowrap">{{ formatDate(it.entry_date) }}</td>
                <td class="px-3 py-2">
                  <RouterLink :to="itemLink(it)"
                    class="font-mono text-xs text-primary-600 hover:text-primary-700 inline-flex items-center gap-1">
                    {{ it.document_no || t('accounting.account_statement.journal_link', { id: it.entry_id }) }}
                    <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M10 6H6a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-4M14 4h6m0 0v6m0-6L10 14"/>
                    </svg>
                  </RouterLink>
                  <div v-if="it.source_bank_ref && it.source_bank_ref !== it.document_no"
                       class="font-mono text-[10px] text-neutral-400 whitespace-nowrap"
                       :title="t('accounting.journal.bank_ref_hint', { ref: it.source_bank_ref })">{{ it.source_bank_ref }}</div>
                </td>
                <td v-if="showLineAccount" class="px-3 py-2">
                  <RouterLink :to="{ name: 'accounting-account-detail', params: { accountId: it.account_id }, query: { from: filters.from, to: filters.to } }"
                    class="font-mono text-xs text-neutral-500 hover:text-primary-600 hover:underline" :title="it.account_name">
                    {{ it.account_code }}
                  </RouterLink>
                </td>
                <td class="px-3 py-2">{{ it.description || '—' }}</td>
                <td class="px-3 py-2 text-neutral-600">{{ it.partner || '' }}</td>
                <td class="px-3 py-2 font-mono text-xs text-neutral-600">{{ it.variable_symbol || '' }}</td>
                <td class="px-3 py-2 text-right font-mono whitespace-nowrap">
                  <template v-if="it.side === 'debit'">{{ formatMoney(it.amount) }}</template>
                </td>
                <td class="px-3 py-2 text-right font-mono whitespace-nowrap">
                  <template v-if="it.side === 'credit'">{{ formatMoney(it.amount) }}</template>
                </td>
                <td class="px-3 py-2 text-right font-mono whitespace-nowrap">{{ formatBalance(it.balance) }}</td>
                <td class="px-3 py-2 font-mono text-xs text-neutral-600 whitespace-nowrap">{{ it.counter_accounts || '' }}</td>
                <td class="px-3 py-2 font-mono text-xs text-neutral-600 whitespace-nowrap">{{ it.pairing_id ? `#${it.pairing_id}` : '' }}</td>
                <td class="px-3 py-2 text-xs text-neutral-600 whitespace-nowrap">{{ currencyLabel(it) }}</td>
                <!-- Náhled dokladu bez opuštění opisu + skok na zápis v deníku. -->
                <td class="px-3 py-2 text-right whitespace-nowrap">
                  <button type="button" :class="[BTN_ICON_SM_BASE, OUTLINE.neutral]" :title="t('accounting.account_statement.preview_source')"
                    @click="previewEntryId = it.entry_id">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.eye" /></svg>
                  </button>
                  <RouterLink :to="journalEntryLink(it.entry_id)" :class="[BTN_ICON_SM_BASE, OUTLINE.neutral, 'ml-1']"
                    :title="t('accounting.account_statement.open_journal')">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.clipboardCheck" /></svg>
                  </RouterLink>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <nav v-if="!loading && report && report.total > report.per_page" class="mt-4 flex items-center justify-end gap-1 text-sm">
        <button type="button" :disabled="page <= 1" @click="goToPage(page - 1)"
          class="cursor-pointer h-8 px-3 border border-neutral-300 rounded-md hover:bg-neutral-50 disabled:opacity-40 disabled:cursor-not-allowed">‹</button>
        <span class="px-2 text-neutral-600">{{ page }} / {{ totalPages }}</span>
        <button type="button" :disabled="page >= totalPages" @click="goToPage(page + 1)"
          class="cursor-pointer h-8 px-3 border border-neutral-300 rounded-md hover:bg-neutral-50 disabled:opacity-40 disabled:cursor-not-allowed">›</button>
      </nav>
    </template>

    <!-- ═══ Otevřené položky – párování ═══ -->
    <template v-else>
      <div v-if="openReport" class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-4">
        <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-3">
          <div class="text-xs text-neutral-500">{{ t('accounting.account_statement.open.open_md') }}</div>
          <div class="text-lg font-semibold font-mono">{{ formatMoney(openReport.open_md) }}</div>
        </div>
        <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-3">
          <div class="text-xs text-neutral-500">{{ t('accounting.account_statement.open.open_d') }}</div>
          <div class="text-lg font-semibold font-mono">{{ formatMoney(openReport.open_d) }}</div>
        </div>
        <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-3">
          <div class="text-xs text-neutral-500">{{ t('accounting.account_statement.open.open_total') }}</div>
          <div class="text-lg font-semibold font-mono">{{ formatMoney(openReport.open_total) }}</div>
          <div class="text-xs text-neutral-500 mt-0.5">{{ t('accounting.account_statement.open.open_count', { count: openReport.open_count }) }}</div>
        </div>
        <div :class="['border rounded-lg shadow-sm p-3', checkOk ? 'bg-success-50 border-success-200' : 'bg-danger-50 border-danger-200']">
          <div class="text-xs text-neutral-500">{{ t('accounting.account_statement.open.account_balance') }}</div>
          <div class="text-lg font-semibold font-mono">{{ formatBalance(openReport.balance) }}</div>
          <div :class="['text-xs mt-0.5', checkOk ? 'text-success-700' : 'text-danger-700']">
            {{ checkOk ? t('accounting.account_statement.open.check_ok') : t('accounting.account_statement.open.check_diff', { amount: formatMoney(openReport.difference) }) }}
          </div>
        </div>
      </div>

      <!-- Akce párování -->
      <div v-if="openReport && canWrite" class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-3 mb-4">
        <div class="flex flex-wrap items-center gap-2">
          <button type="button" :disabled="busy || selectedUnpaired.length < 2" @click="pairSelected" :class="[btnFilled('primary'), 'whitespace-nowrap']">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.link" /></svg>
            {{ t('accounting.account_statement.open.pair') }}
          </button>
          <button v-if="activePairing" type="button" :disabled="busy || selectedUnpaired.length === 0" @click="addSelectedToPairing" :class="[btnOutline('primary'), 'whitespace-nowrap']">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
            {{ t('accounting.account_statement.open.add_to_pairing', { id: activePairing.id }) }}
          </button>
          <button type="button" :disabled="busy || selectedPairingIds.length === 0" @click="unpairSelected" :class="[btnOutline('danger'), 'whitespace-nowrap']">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>
            {{ t('accounting.account_statement.open.unpair') }}
          </button>
          <button type="button" :disabled="suggestionsLoading" @click="loadSuggestions" :class="[btnOutline('neutral'), 'whitespace-nowrap']">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.search" /></svg>
            {{ t('accounting.account_statement.open.suggest') }}
          </button>
          <label class="inline-flex items-center gap-1 text-sm text-neutral-600 whitespace-nowrap">
            {{ t('accounting.account_statement.open.suggest_days') }}
            <input v-model.number="suggestionDays" type="number" min="0" max="366" class="w-16 h-8 px-2 border border-neutral-300 rounded-md text-sm" />
          </label>
          <span v-if="selected.size > 0" class="text-sm text-neutral-600 ml-auto whitespace-nowrap">
            {{ t('accounting.account_statement.open.selected', { count: selected.size, amount: formatMoney(selectedDelta) }) }}
            <button type="button" class="cursor-pointer ml-2 text-primary-600 hover:underline" @click="clearSelection">
              {{ t('accounting.account_statement.open.clear_selection') }}
            </button>
          </span>
        </div>
      </div>

      <!-- Návrhy -->
      <div v-if="suggestions" class="bg-surface border border-neutral-200 rounded-lg shadow-sm mb-4 overflow-hidden">
        <div class="flex flex-wrap items-center justify-between gap-2 px-3 py-2 border-b border-neutral-100">
          <div class="text-sm font-medium">{{ t('accounting.account_statement.open.suggestions_title', { count: suggestions.length }) }}</div>
          <div class="flex flex-wrap gap-2">
            <button v-if="canWrite" type="button" :disabled="busy || suggestions.length === 0" @click="applySuggestions" :class="[btnFilled('success'), 'whitespace-nowrap']">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
              {{ t('accounting.account_statement.open.apply_suggestions', { count: suggestions.length }) }}
            </button>
            <button type="button" @click="suggestions = null" :class="[btnOutline('neutral'), 'whitespace-nowrap']">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>
              {{ t('accounting.account_statement.open.close') }}
            </button>
          </div>
        </div>
        <div v-if="suggestions.length === 0" class="px-3 py-4 text-sm text-neutral-500">{{ t('accounting.account_statement.open.suggestions_empty') }}</div>
        <div v-else class="overflow-x-auto max-h-80">
          <table class="w-full text-sm">
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="(s, i) in suggestions" :key="i">
                <td class="px-3 py-2 whitespace-nowrap text-xs">
                  <span :class="['inline-block px-2 py-0.5 rounded-full', s.kind === 'reversal' ? 'bg-warning-50 text-warning-700' : 'bg-primary-50 text-primary-700']">
                    {{ s.kind === 'reversal' ? t('accounting.account_statement.open.kind_reversal') : t('accounting.account_statement.open.kind_amount') }}
                  </span>
                </td>
                <td class="px-3 py-2 text-right font-mono whitespace-nowrap">{{ formatMoney(s.amount) }}</td>
                <td v-for="l in s.lines" :key="l.line_id" class="px-3 py-2">
                  <span class="whitespace-nowrap">{{ formatDate(l.entry_date) }}</span>
                  <span class="font-mono text-xs text-neutral-600 ml-1">{{ l.document_no || `#${l.entry_id}` }}</span>
                  <span class="text-xs text-neutral-500 ml-1">{{ l.side === 'debit' ? t('accounting.account_statement.col_md') : t('accounting.account_statement.col_d') }}</span>
                </td>
                <td class="px-3 py-2 text-xs text-neutral-500 whitespace-nowrap">{{ t('accounting.account_statement.open.days_apart', { days: s.days_apart }) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <div v-if="loading" class="text-center text-neutral-500 py-12 text-sm">{{ t('common.loading') }}</div>

      <EmptyState v-else-if="!openReport || openReport.items.length === 0" boxed accent="neutral" icon="doc"
        :title="onlyOpen ? t('accounting.account_statement.open.empty') : t('accounting.account_statement.empty')" />

      <div v-else class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
              <tr>
                <th v-if="canWrite" class="px-3 py-2 w-8">
                  <input type="checkbox" class="rounded border-neutral-300" :checked="allOnPageSelected" @change="togglePage" />
                </th>
                <th class="px-3 py-2 text-left font-medium">{{ t('accounting.account_statement.col_date') }}</th>
                <th class="px-3 py-2 text-left font-medium">{{ t('accounting.account_statement.col_document') }}</th>
                <th v-if="showLineAccount" class="px-3 py-2 text-left font-medium">{{ t('accounting.account_statement.col_line_account') }}</th>
                <th class="px-3 py-2 text-left font-medium">{{ t('accounting.account_statement.col_description') }}</th>
                <th class="px-3 py-2 text-left font-medium">{{ t('accounting.account_statement.col_pairing') }}</th>
                <th class="px-3 py-2 text-right font-medium">{{ t('accounting.account_statement.open.col_open_md') }}</th>
                <th class="px-3 py-2 text-right font-medium">{{ t('accounting.account_statement.open.col_open_d') }}</th>
                <th class="px-3 py-2 text-right font-medium">{{ t('accounting.account_statement.open.col_open_balance') }}</th>
                <th class="px-3 py-2 text-right font-medium">{{ t('accounting.account_statement.col_md') }}</th>
                <th class="px-3 py-2 text-right font-medium">{{ t('accounting.account_statement.col_d') }}</th>
                <th class="px-3 py-2 text-right font-medium">{{ t('accounting.account_statement.col_balance') }}</th>
                <th class="px-3 py-2 text-left font-medium">{{ t('accounting.account_statement.col_counter_account') }}</th>
                <th class="px-3 py-2 text-left font-medium">{{ t('accounting.account_statement.col_partner') }}</th>
                <th class="px-3 py-2 text-left font-medium">{{ t('accounting.account_statement.col_vs') }}</th>
                <th class="px-3 py-2 text-left font-medium">{{ t('accounting.account_statement.col_currency') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="it in openReport.items" :key="it.line_id"
                :class="['hover:bg-neutral-50', isSelected(it) ? 'bg-primary-50' : '', activePairing && it.pairing_id === activePairing.id ? 'bg-warning-50' : '']">
                <td v-if="canWrite" class="px-3 py-2">
                  <input type="checkbox" class="rounded border-neutral-300" :checked="isSelected(it)" @change="toggleRow(it)" />
                </td>
                <td class="px-3 py-2 whitespace-nowrap">{{ formatDate(it.entry_date) }}</td>
                <td class="px-3 py-2 whitespace-nowrap">
                  <RouterLink :to="itemLink(it)" class="font-mono text-xs text-primary-600 hover:text-primary-700">
                    {{ it.document_no || t('accounting.account_statement.journal_link', { id: it.entry_id }) }}
                  </RouterLink>
                  <span v-if="it.is_reversed" class="ml-1 text-xs text-warning-700">{{ t('accounting.account_statement.open.reversed') }}</span>
                </td>
                <td v-if="showLineAccount" class="px-3 py-2 font-mono text-xs text-neutral-500" :title="it.account_name">{{ it.account_code }}</td>
                <td class="px-3 py-2">{{ it.description || '—' }}</td>
                <td class="px-3 py-2 whitespace-nowrap">
                  <button v-if="it.pairing_id" type="button" class="cursor-pointer font-mono text-xs text-primary-600 hover:underline" @click="openPairing(it.pairing_id)">
                    #{{ it.pairing_id }}
                  </button>
                </td>
                <td class="px-3 py-2 text-right font-mono whitespace-nowrap">
                  <template v-if="it.side === 'debit' && it.open_amount !== 0">{{ formatMoney(it.open_amount) }}</template>
                </td>
                <td class="px-3 py-2 text-right font-mono whitespace-nowrap">
                  <template v-if="it.side === 'credit' && it.open_amount !== 0">{{ formatMoney(it.open_amount) }}</template>
                </td>
                <td class="px-3 py-2 text-right font-mono whitespace-nowrap">{{ formatBalance(it.open_balance) }}</td>
                <td class="px-3 py-2 text-right font-mono whitespace-nowrap text-neutral-500">
                  <template v-if="it.side === 'debit'">{{ formatMoney(it.amount) }}</template>
                </td>
                <td class="px-3 py-2 text-right font-mono whitespace-nowrap text-neutral-500">
                  <template v-if="it.side === 'credit'">{{ formatMoney(it.amount) }}</template>
                </td>
                <td class="px-3 py-2 text-right font-mono whitespace-nowrap text-neutral-500">{{ formatBalance(it.balance) }}</td>
                <td class="px-3 py-2 font-mono text-xs text-neutral-600 whitespace-nowrap">{{ it.counter_accounts || '' }}</td>
                <td class="px-3 py-2 text-neutral-600">{{ it.partner || '' }}</td>
                <td class="px-3 py-2 font-mono text-xs text-neutral-600">{{ it.variable_symbol || '' }}</td>
                <td class="px-3 py-2 text-xs text-neutral-600 whitespace-nowrap">{{ currencyLabel(it) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <nav v-if="!loading && openReport && openReport.total > openReport.per_page" class="mt-4 flex items-center justify-end gap-1 text-sm">
        <button type="button" :disabled="openPage <= 1" @click="goToOpenPage(openPage - 1)"
          class="cursor-pointer h-8 px-3 border border-neutral-300 rounded-md hover:bg-neutral-50 disabled:opacity-40 disabled:cursor-not-allowed">‹</button>
        <span class="px-2 text-neutral-600">{{ openPage }} / {{ openTotalPages }}</span>
        <button type="button" :disabled="openPage >= openTotalPages" @click="goToOpenPage(openPage + 1)"
          class="cursor-pointer h-8 px-3 border border-neutral-300 rounded-md hover:bg-neutral-50 disabled:opacity-40 disabled:cursor-not-allowed">›</button>
      </nav>

      <!-- Spodní panel: položky vybraného okruhu -->
      <div v-if="activePairing" class="mt-4 bg-surface border border-warning-200 rounded-lg shadow-sm overflow-hidden">
        <div class="flex flex-wrap items-center justify-between gap-2 px-3 py-2 border-b border-neutral-100">
          <div class="text-sm">
            <span class="font-medium">{{ t('accounting.account_statement.open.pairing_title', { id: activePairing.id }) }}</span>
            <span class="font-mono text-xs text-neutral-500 ml-2">{{ activePairing.account_code }}</span>
            <span :class="['ml-2 inline-block px-2 py-0.5 rounded-full text-xs', activePairing.balanced ? 'bg-success-50 text-success-700' : 'bg-warning-50 text-warning-700']">
              {{ activePairing.balanced ? t('accounting.account_statement.open.pairing_balanced') : t('accounting.account_statement.open.pairing_remainder', { amount: formatMoney(activePairing.remainder) }) }}
            </span>
          </div>
          <div class="flex flex-wrap gap-2">
            <button v-if="canWrite" type="button" :disabled="busy" @click="deleteActivePairing" :class="[btnOutline('danger'), 'whitespace-nowrap']">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>
              {{ t('accounting.account_statement.open.unpair') }}
            </button>
            <button type="button" @click="activePairing = null" :class="[btnOutline('neutral'), 'whitespace-nowrap']">
              {{ t('accounting.account_statement.open.close') }}
            </button>
          </div>
        </div>
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="p in activePairing.items" :key="`${p.entry_id}-${p.line_no}`">
                <td class="px-3 py-2 whitespace-nowrap">{{ formatDate(p.entry_date) }}</td>
                <td class="px-3 py-2 whitespace-nowrap">
                  <RouterLink :to="journalEntryLink(p.entry_id)" class="font-mono text-xs text-primary-600 hover:text-primary-700">
                    {{ p.document_no || t('accounting.account_statement.journal_link', { id: p.entry_id }) }}
                  </RouterLink>
                </td>
                <td class="px-3 py-2">
                  {{ p.description || '—' }}
                  <span v-if="p.line_id === null" class="ml-1 text-xs text-danger-700">{{ t('accounting.account_statement.open.line_missing') }}</span>
                </td>
                <td class="px-3 py-2 text-right font-mono whitespace-nowrap">
                  <template v-if="p.side === 'debit' && p.amount !== null">{{ formatMoney(p.amount) }}</template>
                </td>
                <td class="px-3 py-2 text-right font-mono whitespace-nowrap">
                  <template v-if="p.side === 'credit' && p.amount !== null">{{ formatMoney(p.amount) }}</template>
                </td>
                <td class="px-3 py-2 text-right whitespace-nowrap">
                  <button v-if="canWrite" type="button" :disabled="busy" :class="[BTN_ICON_SM_BASE, OUTLINE.danger]"
                    :title="t('accounting.account_statement.open.remove_line')" @click="removeFromPairing(p.entry_id, p.line_no)">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </template>

    <JournalSourceDrawer v-if="previewEntryId" :entry-id="previewEntryId"
      @close="previewEntryId = null" @focus-entry="(id) => { previewEntryId = null; $router.push(journalEntryLink(id)) }" />
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { formatAccountNumber } from '@/utils/bankAccount'
import { useFillViewportHeight } from '@/composables/useFillViewportHeight'
import { useScrollLoadMore } from '@/composables/useScrollLoadMore'
import { useBankFilterMemory } from '@/composables/useBankFilterMemory'
import { useSupplierStore } from '@/stores/supplier'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { apiErrorMessage } from '@/api/errors'
import { downloadApiFile } from '@/utils/downloadFile'
import ActionBar from '@/components/ui/ActionBar.vue'
import BankTransactionRow from '@/components/bank/BankTransactionRow.vue'
import BankTransactionDialogs from '@/components/bank/BankTransactionDialogs.vue'
import BankMatchModal from '@/components/bank/BankMatchModal.vue'
import BankCreatePurchaseModal from '@/components/bank/BankCreatePurchaseModal.vue'
import BankRequestDocModal from '@/components/bank/BankRequestDocModal.vue'
import { bankPostingApi, type UnpostedBankTransaction } from '@/api/bankPosting'
import { bankApi, type BankAccountOption, type MatchSuggestion } from '@/api/bank'
import { useBankTransactionActions } from '@/composables/useBankTransactionActions'
import { useBankTransactionSort } from '@/composables/useBankTransactionSort'
import EmptyState from '@/components/ui/EmptyState.vue'
import SortableTh from '@/components/ui/SortableTh.vue'
import BankTransactionSortSelect from '@/components/bank/BankTransactionSortSelect.vue'

// scope='all' → záložka „Všechny pohyby": tatáž tabulka, ale i zaúčtované pohyby, napříč účty.
const props = withDefaults(defineProps<{ scope?: 'unposted' | 'all' }>(), { scope: 'unposted' })
const emit = defineEmits<{ 'counts-changed': [] }>()
const { t } = useI18n()
const supplierStore = useSupplierStore()
const auth = useAuthStore()
const toast = useToast()
const listBox = ref<HTMLElement | null>(null)
const loadMoreTarget = ref<HTMLElement | null>(null)
useFillViewportHeight(listBox)
const items = ref<UnpostedBankTransaction[]>([])
const page = ref(1)
const perPage = ref(50)
const total = ref(0)
const loading = ref(false)
const loadingMore = ref(false)
let internalPageChange = false
let silentPageChange = false
let loadGeneration = 0
const years = ref<number[]>([])
const year = ref<number | null>(null)
const search = ref('')
const accounts = ref<BankAccountOption[]>([])
const accountFilter = ref<string>('')
const statusFilter = ref<'' | 'unmatched' | 'auto_exact' | 'auto_partial' | 'manual' | 'ignored'>('')
const postingFilter = ref<'' | 'unposted' | 'posted'>('')
const STATUS_OPTIONS = ['unmatched', 'auto_exact', 'auto_partial', 'manual', 'ignored'] as const
function statusLabel(status: string): string {
  return t(`bank.match_status.${status}`)
}
function accountLabel(a: BankAccountOption): string {
  const num = formatAccountNumber(a.account_number, a.bank_code)
  return a.label ? `${num} - ${a.label}` : num
}

// Sdílená akční logika nad transakcí (match/ignore/unmatch/create/request-doc/…) —
// stejná komponenta i logika jako detail výpisu (BankTransactionRow.vue, #52).
// reload = changed() (přepočítá i county v záložkách bank sekce).
const bankActions = useBankTransactionActions({ reload: () => changed(), refresh: () => changed(true) })
// „Všechny pohyby" vidí každá firma s výpisy; stav zaúčtování, kontace a akce zaúčtování
// jen podvojné účetnictví s komerčními funkcemi (stejná podmínka jako detail výpisu).
const isDoubleEntry = computed(() => auth.hasCommercialFeatures && supplierStore.currentSupplier?.accounting_mode === 'double_entry')
const colspan = computed(() => 7 + (props.scope === 'all' ? 1 : 0) + (isDoubleEntry.value ? 1 : 0))
// Řazení podle sloupce — seznam je stránkovaný na serveru, řadí se tam (výchozí = nejnovější nahoře).
const txSort = useBankTransactionSort(['posted_at', 'amount', 'account', 'variable_symbol', 'counterparty', 'invoice', 'posting', 'status'])
const sortKeys = computed(() => txSort.keys.filter(k =>
  (k !== 'account' || props.scope === 'all')
  && (k !== 'posting' || isDoubleEntry.value)
  && (k !== 'status' || !isDoubleEntry.value)))
useBankFilterMemory(() => `${supplierStore.currentSupplierId}:movements:${props.scope}`, () => ({
  search: search.value, year: year.value, account: accountFilter.value, status: statusFilter.value,
  posting: postingFilter.value, sort: txSort.selectValue.value,
}), value => {
  search.value = typeof value.search === 'string' ? value.search : ''
  year.value = typeof value.year === 'number' && Number.isInteger(value.year) ? value.year : null
  accountFilter.value = typeof value.account === 'string' ? value.account : ''
  statusFilter.value = STATUS_OPTIONS.includes(value.status as never) ? value.status! : ''
  postingFilter.value = value.posting === 'unposted' || value.posting === 'posted' ? value.posting : ''
  txSort.selectValue.value = typeof value.sort === 'string' ? value.sort : ''
})
const totalPages = computed(() => Math.max(1, Math.ceil(total.value / perPage.value)))
useScrollLoadMore(loadMoreTarget, () => !loading.value && !loadingMore.value && page.value < totalPages.value, loadMore)
function loadMore(): Promise<void> {
  if (loading.value || loadingMore.value || page.value >= totalPages.value) return Promise.resolve()
  return load(false, true)
}
function onListScroll(event: Event) {
  const el = event.currentTarget as HTMLElement
  if (el.scrollTop + el.clientHeight >= el.scrollHeight - 240) void loadMore()
}
async function exportUnmatched() {
  try {
    await downloadApiFile(bankApi.unmatchedAllExportUrl({
      ...(year.value ? { year: year.value } : {}),
      ...(search.value.trim() ? { q: search.value.trim() } : {}),
      ...(accountFilter.value ? { account: accountFilter.value } : {}),
      ...(postingFilter.value ? { posting_status: postingFilter.value } : {}),
      ...txSort.params.value,
    }), 'nesparovane-pohyby.xlsx')
  } catch (error) { toast.error(apiErrorMessage(error)) }
}


// Match v2 („⏳ návrh párování") je párovaný per-výpis na BE — „Všechny pohyby"
// agreguje víc výpisů, takže návrhy dotáhneme dávkově (1 request na distinct
// statement_id z aktuální stránky) a sloučíme do jedné mapy. Best-effort — selhání
// jednoho výpisu jen připraví o badge, ne o zbytek stránky.
async function loadMatchSuggestions(txs: UnpostedBankTransaction[], generation: number) {
  const statementIds = [...new Set(txs.map(tx => tx.statement_id))]
  if (statementIds.length === 0) { bankActions.setSuggestions(new Map()); return }
  const results = await Promise.allSettled(statementIds.map(id => bankApi.matchSuggestions(id)))
  if (generation !== loadGeneration) return
  const map = new Map<number, MatchSuggestion>()
  for (const r of results) {
    if (r.status !== 'fulfilled') continue
    for (const s of r.value.suggestions) {
      if (s.status === 'pending') map.set(s.bank_transaction_id, s)
    }
  }
  bankActions.setSuggestions(map)
}

async function load(silent = false, append = false) {
  const generation = ++loadGeneration
  if (append) loadingMore.value = true
  else if (!silent) loading.value = true
  try {
    const params = {
      page: append ? page.value + 1 : page.value,
      per_page: perPage.value,
      ...(props.scope === 'all' && statusFilter.value ? { status: statusFilter.value } : {}),
      ...(year.value ? { year: year.value } : {}),
      ...(search.value.trim() ? { q: search.value.trim() } : {}),
      ...(accountFilter.value ? { account: accountFilter.value } : {}),
      ...(props.scope === 'all' && isDoubleEntry.value && postingFilter.value ? { posting_status: postingFilter.value } : {}),
      ...txSort.params.value,
    }
    // „Všechny pohyby" čtou bankovní endpoint (každý režim), fronta k zaúčtování účetní.
    const fetchPage = (p: typeof params) => props.scope === 'all'
      ? bankPostingApi.listMovements(p)
      : bankPostingApi.listUnposted({ ...p, scope: 'unposted' })
    const result = await fetchPage(params)
    if (generation !== loadGeneration) return
    if (append && result.items.length === 0) {
      total.value = result.total
      perPage.value = result.per_page
      const lastPage = Math.max(1, Math.ceil(result.total / result.per_page))
      if (page.value > lastPage) { internalPageChange = true; page.value = lastPage }
      await load(silent)
      return
    }
    if (result.items.length === 0 && result.total > 0 && page.value > 1) {
      silentPageChange = silent
      page.value = Math.max(1, Math.ceil(result.total / result.per_page))
      return
    }
    const preceding = !append && page.value > 1
      ? await Promise.all(Array.from({ length: page.value - 1 }, (_, index) => fetchPage({ ...params, page: index + 1 }))) : []
    if (generation !== loadGeneration) return
    items.value = append ? [...items.value, ...result.items] : [...preceding.flatMap(part => part.items), ...result.items]
    if (append) { internalPageChange = true; page.value = params.page }
    total.value = result.total
    perPage.value = result.per_page
    years.value = result.years ?? []
    accounts.value = result.accounts ?? []
    void loadMatchSuggestions(items.value, generation)
  } finally {
    if (generation === loadGeneration) { loading.value = false; loadingMore.value = false }
  }
}

async function changed(silent = false) {
  emit('counts-changed')
  await load(silent)
}

// Změna filtru vždy zpět na první stranu — jinak by uživatel skončil na prázdné stránce.
let searchTimer: ReturnType<typeof setTimeout> | undefined
watch([search, year, accountFilter, statusFilter, postingFilter, isDoubleEntry, () => props.scope, () => supplierStore.currentSupplierId, txSort.sort], () => { loadGeneration++ }, { flush: 'sync' })
watch(page, () => { if (!internalPageChange) loadGeneration++ }, { flush: 'sync' })
function resetAndLoad() {
  if (page.value !== 1) { page.value = 1; return } // watch(page) načte sám
  void load()
}
watch(search, () => {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(resetAndLoad, 300)
})
watch(year, resetAndLoad)
watch(accountFilter, resetAndLoad)
watch(statusFilter, resetAndLoad)
watch(postingFilter, resetAndLoad)
watch(isDoubleEntry, resetAndLoad)
watch(() => supplierStore.currentSupplierId, () => { items.value = []; resetAndLoad() })
watch(txSort.sort, resetAndLoad)
watch(() => props.scope, () => {
  if (props.scope !== 'all' && txSort.sort.value?.key === 'account') txSort.sort.value = null
  resetAndLoad()
})

onMounted(load)
onUnmounted(() => {
  clearTimeout(searchTimer)
  loadGeneration++
})
watch(page, () => {
  if (internalPageChange) { internalPageChange = false; return }
  const silent = silentPageChange
  silentPageChange = false
  void load(silent)
}, { flush: 'sync' })
</script>

<template>
  <div>
    <p class="text-sm text-neutral-500 mb-3">
      {{ scope !== 'all' ? t('bank.posting.unposted_hint') : isDoubleEntry ? t('bank.posting.all_hint') : t('bank.posting.all_hint_evidence') }}
    </p>

    <div class="flex flex-wrap items-center gap-2 mb-3">
      <input v-model="search" type="search" :placeholder="t('bank.posting.search_placeholder')"
        class="h-9 px-3 border border-neutral-300 rounded-md text-sm flex-1 min-w-[16rem]" />
      <select v-model="year" class="h-9 px-2 border border-neutral-300 rounded-md text-sm">
        <option :value="null">{{ t('bank.posting.all_years') }}</option>
        <option v-for="y in years" :key="y" :value="y">{{ y }}</option>
      </select>
      <select v-if="accounts.length > 1" v-model="accountFilter"
        class="h-9 px-2 border border-neutral-300 rounded-md text-sm min-w-0 max-w-full">
        <option value="">{{ t('bank.all_own_accounts') }}</option>
        <option v-for="a in accounts" :key="a.account_number" :value="a.account_number">{{ accountLabel(a) }}</option>
      </select>
      <select v-if="scope === 'all'" v-model="statusFilter" :aria-label="t('bank.filter_status')"
        class="h-9 px-2 border border-neutral-300 rounded-md text-sm max-w-full">
        <option value="">{{ t('bank.filter_all') }}</option>
        <option v-for="status in STATUS_OPTIONS" :key="status" :value="status">{{ statusLabel(status) }}</option>
      </select>
      <select v-if="scope === 'all' && isDoubleEntry" v-model="postingFilter" :aria-label="t('bank.filter_posting')"
        class="h-9 px-2 border border-neutral-300 rounded-md text-sm max-w-full">
        <option value="">{{ t('bank.filter_posting_all') }}</option>
        <option value="unposted">{{ t('bank.filter_posting_unposted') }}</option>
        <option value="posted">{{ t('bank.filter_posting_posted') }}</option>
      </select>
      <ActionBar v-if="scope === 'all'" :actions="[{ key: 'export', label: t('bank.unmatched_export.download'), icon: 'download', tier: 'secondary', run: exportUnmatched }]" />
      <BankTransactionSortSelect v-model="txSort.selectValue.value" class="md:hidden" :keys="sortKeys" />
      <span class="text-xs text-neutral-500 whitespace-nowrap">{{ t('bank.posting.count_found', { n: total }) }}</span>
    </div>

    <div v-if="loading && !items.length" class="text-center text-neutral-500 py-12 text-sm">{{ t('common.loading') }}</div>
    <EmptyState v-else-if="items.length === 0 && (search || year || accountFilter || (scope === 'all' && (statusFilter || (isDoubleEntry && postingFilter))))" boxed variant="filtered"
      :title="t('bank.posting.no_match')" />
    <EmptyState v-else-if="items.length === 0 && scope === 'all'" boxed :title="t('bank.posting.all_empty')" />
    <EmptyState v-else-if="items.length === 0" boxed icon="checkCircle" accent="success" :title="t('bank.posting.unposted_empty')" />
    <div v-else class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
      <div ref="listBox" class="hidden md:block overflow-auto scrollbar-slim" @scroll.passive="onListScroll">
        <table class="w-full text-sm">
          <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide sticky top-0 z-20 shadow-sm">
            <tr>
              <SortableTh :label="t('bank.date')" sort-key="posted_at" :sort="txSort.sort.value" @toggle="txSort.toggle" />
              <SortableTh :label="t('bank.amount')" sort-key="amount" :sort="txSort.sort.value" align="right" @toggle="txSort.toggle" />
              <SortableTh v-if="scope === 'all'" :label="t('bank.posting.col_account')" sort-key="account" :sort="txSort.sort.value" @toggle="txSort.toggle" />
              <SortableTh :label="t('bank.vs_ks')" sort-key="variable_symbol" :sort="txSort.sort.value" @toggle="txSort.toggle" />
              <SortableTh :label="t('bank.counterparty')" sort-key="counterparty" :sort="txSort.sort.value" @toggle="txSort.toggle" />
              <SortableTh :label="t('bank.invoice')" sort-key="invoice" :sort="txSort.sort.value" @toggle="txSort.toggle" />
              <SortableTh v-if="isDoubleEntry" :label="t('bank.posting_state')" sort-key="posting" :sort="txSort.sort.value" @toggle="txSort.toggle" />
              <SortableTh v-else :label="t('invoice.status_label')" sort-key="status" :sort="txSort.sort.value" @toggle="txSort.toggle" />
              <th v-if="isDoubleEntry" class="px-3 py-2">{{ t('bank.counter_account') }}</th>
              <th class="px-3 py-2 w-32"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <BankTransactionRow v-for="tx in items" :key="tx.id"
              layout="desktop" :tx="tx" :is-double-entry="isDoubleEntry"
              fallback-currency="CZK" :show-account="scope === 'all'" :show-statement-link="true" :show-counter-account="isDoubleEntry"
              :colspan="colspan" :actions="bankActions"
              @changed="changed" />
          </tbody>
        </table>
      </div>

      <div class="md:hidden divide-y divide-neutral-100">
        <BankTransactionRow v-for="tx in items" :key="`m-${tx.id}`"
          layout="mobile" :tx="tx" :is-double-entry="isDoubleEntry"
          fallback-currency="CZK" :show-account="scope === 'all'" :show-statement-link="true" :show-counter-account="isDoubleEntry"
          :actions="bankActions"
          @changed="changed" />
      </div>
    </div>
    <div v-if="page < totalPages" ref="loadMoreTarget" class="text-center text-sm text-neutral-500 pointer-fine-hidden">
      <button type="button" :disabled="loading || loadingMore" class="cursor-pointer my-3 h-9 px-4 border border-neutral-300 rounded-md hover:bg-neutral-50 disabled:opacity-50" @click="loadMore">
        {{ loadingMore ? t('common.loading_more') : t('common.load_more') }}
      </button>
    </div>

    <BankTransactionDialogs :actions="bankActions" fallback-currency="CZK" />
    <BankMatchModal :actions="bankActions" fallback-currency="CZK" />
    <BankCreatePurchaseModal :actions="bankActions" />
    <BankRequestDocModal :actions="bankActions" />
  </div>
</template>

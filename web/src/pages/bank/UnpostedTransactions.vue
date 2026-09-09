<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { formatAccountNumber } from '@/utils/bankAccount'
import PaginationBar from '@/components/ui/PaginationBar.vue'
import BankTransactionRow from '@/components/bank/BankTransactionRow.vue'
import BankTransactionDialogs from '@/components/bank/BankTransactionDialogs.vue'
import BankMatchModal from '@/components/bank/BankMatchModal.vue'
import BankCreatePurchaseModal from '@/components/bank/BankCreatePurchaseModal.vue'
import BankRequestDocModal from '@/components/bank/BankRequestDocModal.vue'
import { bankPostingApi, type UnpostedBankTransaction } from '@/api/bankPosting'
import { bankApi, type BankAccountOption, type MatchSuggestion } from '@/api/bank'
import { useBankTransactionActions } from '@/composables/useBankTransactionActions'
import EmptyState from '@/components/ui/EmptyState.vue'

// scope='all' → záložka „Všechny pohyby": tatáž tabulka, ale i zaúčtované pohyby, napříč účty.
const props = withDefaults(defineProps<{ scope?: 'unposted' | 'all' }>(), { scope: 'unposted' })
const emit = defineEmits<{ 'counts-changed': [] }>()
const { t } = useI18n()
const items = ref<UnpostedBankTransaction[]>([])
const page = ref(1)
const perPage = ref(50)
const total = ref(0)
const loading = ref(false)
let silentPageChange = false
const years = ref<number[]>([])
const year = ref<number | null>(null)
const search = ref('')
const accounts = ref<BankAccountOption[]>([])
const accountFilter = ref<string>('')
function accountLabel(a: BankAccountOption): string {
  const num = formatAccountNumber(a.account_number, a.bank_code)
  return a.label ? `${num} — ${a.label}` : num
}

// Sdílená akční logika nad transakcí (match/ignore/unmatch/create/request-doc/…) —
// stejná komponenta i logika jako detail výpisu (BankTransactionRow.vue, #52).
// reload = changed() (přepočítá i county v záložkách bank sekce).
const bankActions = useBankTransactionActions({ reload: () => changed(), refresh: () => changed(true) })
const colspan = computed(() => props.scope === 'all' ? 8 : 7)

// Match v2 („⏳ návrh párování") je párovaný per-výpis na BE — „Všechny pohyby"
// agreguje víc výpisů, takže návrhy dotáhneme dávkově (1 request na distinct
// statement_id z aktuální stránky) a sloučíme do jedné mapy. Best-effort — selhání
// jednoho výpisu jen připraví o badge, ne o zbytek stránky.
async function loadMatchSuggestions(txs: UnpostedBankTransaction[]) {
  const statementIds = [...new Set(txs.map(tx => tx.statement_id))]
  if (statementIds.length === 0) { bankActions.setSuggestions(new Map()); return }
  const results = await Promise.allSettled(statementIds.map(id => bankApi.matchSuggestions(id)))
  const map = new Map<number, MatchSuggestion>()
  for (const r of results) {
    if (r.status !== 'fulfilled') continue
    for (const s of r.value.suggestions) {
      if (s.status === 'pending') map.set(s.bank_transaction_id, s)
    }
  }
  bankActions.setSuggestions(map)
}

async function load(silent = false) {
  if (!silent) loading.value = true
  try {
    const result = await bankPostingApi.listUnposted({
      page: page.value,
      per_page: perPage.value,
      scope: props.scope,
      ...(year.value ? { year: year.value } : {}),
      ...(search.value.trim() ? { q: search.value.trim() } : {}),
      ...(accountFilter.value ? { account: accountFilter.value } : {}),
    })
    if (result.items.length === 0 && result.total > 0 && page.value > 1) {
      silentPageChange = silent
      page.value = Math.max(1, Math.ceil(result.total / result.per_page))
      return
    }
    items.value = result.items
    total.value = result.total
    perPage.value = result.per_page
    years.value = result.years ?? []
    accounts.value = result.accounts ?? []
    void loadMatchSuggestions(result.items)
  } finally {
    loading.value = false
  }
}

async function changed(silent = false) {
  emit('counts-changed')
  await load(silent)
}

// Změna filtru vždy zpět na první stranu — jinak by uživatel skončil na prázdné stránce.
let searchTimer: ReturnType<typeof setTimeout> | undefined
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

onMounted(load)
watch(page, () => {
  const silent = silentPageChange
  silentPageChange = false
  void load(silent)
})
</script>

<template>
  <div>
    <p class="text-sm text-neutral-500 mb-3">
      {{ scope === 'all' ? t('bank.posting.all_hint') : t('bank.posting.unposted_hint') }}
    </p>

    <div class="flex flex-wrap items-center gap-2 mb-3">
      <input v-model="search" type="search" :placeholder="t('bank.posting.search_placeholder')"
        class="h-9 px-3 border border-neutral-300 rounded-md text-sm flex-1 min-w-[16rem]" />
      <select v-model="year" class="h-9 px-2 border border-neutral-300 rounded-md text-sm">
        <option :value="null">{{ t('bank.posting.all_years') }}</option>
        <option v-for="y in years" :key="y" :value="y">{{ y }}</option>
      </select>
      <select v-if="accounts.length > 1" v-model="accountFilter"
        class="h-9 px-2 border border-neutral-300 rounded-md text-sm">
        <option value="">{{ t('bank.all_own_accounts') }}</option>
        <option v-for="a in accounts" :key="a.account_number" :value="a.account_number">{{ accountLabel(a) }}</option>
      </select>
      <span class="text-xs text-neutral-500 whitespace-nowrap">{{ t('bank.posting.count_found', { n: total }) }}</span>
    </div>

    <div v-if="loading" class="text-center text-neutral-500 py-12 text-sm">{{ t('common.loading') }}</div>
    <EmptyState v-else-if="items.length === 0 && (search || year || accountFilter)" boxed variant="filtered"
      :title="t('bank.posting.no_match')" />
    <EmptyState v-else-if="items.length === 0" boxed icon="checkCircle" accent="success" :title="t('bank.posting.unposted_empty')" />
    <div v-else class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
      <div class="hidden md:block overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
            <tr>
              <th class="px-3 py-2 text-left font-medium">{{ t('bank.date') }}</th>
              <th class="px-3 py-2 text-right font-medium">{{ t('bank.amount') }}</th>
              <th v-if="scope === 'all'" class="px-3 py-2 text-left font-medium">{{ t('bank.posting.col_account') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('bank.vs_ks') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('bank.counterparty') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('bank.invoice') }}</th>
              <th class="px-3 py-2 text-center font-medium">{{ t('bank.posting_state') }}</th>
              <th class="px-3 py-2 w-32"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <BankTransactionRow v-for="tx in items" :key="tx.id"
              layout="desktop" :tx="tx" :is-double-entry="true"
              fallback-currency="CZK" :show-account="scope === 'all'" :show-statement-link="true"
              :colspan="colspan" :actions="bankActions"
              @changed="changed" />
          </tbody>
        </table>
      </div>

      <div class="md:hidden divide-y divide-neutral-100">
        <BankTransactionRow v-for="tx in items" :key="`m-${tx.id}`"
          layout="mobile" :tx="tx" :is-double-entry="true"
          fallback-currency="CZK" :show-account="scope === 'all'" :show-statement-link="true"
          :actions="bankActions"
          @changed="changed" />
      </div>
    </div>
    <PaginationBar :page="page" :per-page="perPage" :total="total" @update:page="page = $event" />

    <BankTransactionDialogs :actions="bankActions" fallback-currency="CZK" />
    <BankMatchModal :actions="bankActions" fallback-currency="CZK" />
    <BankCreatePurchaseModal :actions="bankActions" />
    <BankRequestDocModal :actions="bankActions" />
  </div>
</template>

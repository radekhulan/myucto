<script setup lang="ts">
import { ref, computed, onMounted, watch, nextTick } from 'vue'
import { useRoute, RouterLink, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import {
  bankApi,
  type BankStatementDetail,
  type BankTransaction,
  type MatchStatus,
  type PostingFilter,
} from '@/api/bank'
import { formatMoney, formatDate } from '@/composables/useFormat'
import { useToast } from '@/composables/useToast'
import { apiErrorMessage } from '@/api/errors'
import { useAuthStore } from '@/stores/auth'
import { useSupplierStore } from '@/stores/supplier'
import { formatAccountNumber } from '@/utils/bankAccount'
import RuleHintBanner from '@/components/bank/RuleHintBanner.vue'
import BankTransactionRow from '@/components/bank/BankTransactionRow.vue'
import BankMatchModal from '@/components/bank/BankMatchModal.vue'
import BankCreatePurchaseModal from '@/components/bank/BankCreatePurchaseModal.vue'
import BankRequestDocModal from '@/components/bank/BankRequestDocModal.vue'
import type { PostResult } from '@/api/bankPosting'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import { useBankTransactionActions } from '@/composables/useBankTransactionActions'
import { usePaneDom } from '@/composables/usePaneDom'

const { t, locale } = useI18n()
const toast = useToast()
const router = useRouter()
const auth = useAuthStore()
const supplierStore = useSupplierStore()
const paneDom = usePaneDom()
const isDoubleEntry = computed(() => auth.hasCommercialFeatures && supplierStore.currentSupplier?.accounting_mode === 'double_entry')

// Počet transakcí s návrhem zaúčtování (pro chip v headeru) — počítá backend přes
// VŠECHNY transakce výpisu, ne jen aktuálně načtenou stránku (viz transactions_meta).
const pendingPostingCount = computed(() => statement.value?.pending_posting_count ?? 0)

// Learned hint banner po ručním zaúčtování (in-memory, zmizí reloadem).
const hintTx = ref<BankTransaction | null>(null)
const hintData = ref<{ count: number; debit: string; credit: string } | null>(null)
function onTxPosted(payload: { result: PostResult; debit: string; credit: string }, tx: BankTransaction) {
  if (payload.result.similar && payload.result.similar.count > 0) {
    hintTx.value = tx
    hintData.value = { count: payload.result.similar.count, debit: payload.debit, credit: payload.credit }
  }
  load()
}
function closeHint() { hintTx.value = null; hintData.value = null }

// Sdílená akční logika nad transakcí (match/ignore/unmatch/create/request-doc/…) —
// extrahováno do BankTransactionRow.vue + useBankTransactionActions, ať ji sdílí
// i „Všechny pohyby" (UnpostedTransactions.vue), #52.
const bankActions = useBankTransactionActions({ reload: () => load() })

// E-mailová avíza jsou měsíční agregát (statement_date = 1. den měsíce) → název měsíce.
function monthLabel(dateStr: string): string {
  const d = new Date(dateStr)
  if (isNaN(d.getTime())) return dateStr
  return d.toLocaleDateString(locale.value === 'en' ? 'en-US' : 'cs-CZ', { month: 'long', year: 'numeric' })
}

const route = useRoute()
const statement = ref<BankStatementDetail | null>(null)
const loading = ref(true)
const loadingMore = ref(false)
const isVirtual = computed(() =>
  statement.value?.source === 'email_notice' || statement.value?.source === 'idoklad'
)

// Stránkování transakcí (load-more) — viz transactions_meta v odpovědi detailu.
const txPage = ref(1)
const txPages = ref(1)
const txTotal = ref(0)

// Filtr transakcí dle stavu spárování ('' = vše) — řeší se server-side (query `status`).
const STATUS_OPTIONS = ['unmatched', 'auto_exact', 'auto_partial', 'manual', 'ignored'] as const
function statusLabel(s: string): string {
  const key = `bank.match_status.${s}`
  const label = t(key)
  return label === key ? s : label
}
const statusFilter = ref<string>('')
const postingFilter = ref<PostingFilter | ''>(route.query.posting_status === 'unposted' ? 'unposted' : '')
// Aktuálně načtené (a serverem filtrované) transakce — název ponechán kvůli šabloně.
const filteredTransactions = computed<BankTransaction[]>(() => statement.value?.transactions ?? [])
// Souhrn pro měsíční avízo-výpis: disponibilní zůstatek z nejnovějšího avíza,
// které ho neslo (Creditas/Fio/RB), + součty příjmů/výdajů měsíce — počítá backend
// přes VŠECHNY transakce výpisu (notice_summary), ne jen aktuálně načtenou stránku.
const noticeSummary = computed(() => {
  const s = statement.value
  if (!s || !isVirtual.value || !s.notice_summary) return null
  return {
    balance: s.notice_summary.balance,
    balanceAt: s.notice_summary.balance_at,
    credit: s.notice_summary.credit,
    debit: s.notice_summary.debit,
  }
})

async function load(reset = true) {
  if (reset) {
    loading.value = true
    txPage.value = 1
  } else {
    loadingMore.value = true
    txPage.value++
  }
  try {
    const statementId = Number(route.params.id)
    const [res, suggestionsResult] = await Promise.all([
      bankApi.get(statementId, {
        page: txPage.value,
        status: statusFilter.value ? (statusFilter.value as MatchStatus) : undefined,
        posting_status: postingFilter.value || undefined,
      }),
      reset
        ? bankApi.matchSuggestions(statementId)
        : Promise.resolve(null),
    ])
    const transactions = reset || !statement.value
      ? res.transactions
      : [...statement.value.transactions, ...res.transactions]
    statement.value = { ...res, transactions }
    if (suggestionsResult) {
      const map = new Map(suggestionsResult.suggestions
        .filter(s => s.status === 'pending')
        .map(s => [s.bank_transaction_id, s]))
      bankActions.setSuggestions(map)
    }
    txTotal.value = res.transactions_meta.total
    txPages.value = res.transactions_meta.pages
  } finally {
    loading.value = false
    loadingMore.value = false
  }
}
onMounted(() => { void load(true).then(highlightLinkedTx) })

/*
 * Přechod na JINÝ výpis přes odkaz „Druhá noha" mění jen parametr routy, takže
 * vue-router komponentu recykluje a `onMounted` se znovu nespustí. Bez tohohle
 * watcheru se přepsala URL, ale obsah zůstal ze starého výpisu — klik vypadal,
 * že nedělá vůbec nic.
 */
watch(() => route.params.id, (id, previous) => {
  if (id === previous) return
  statement.value = null
  load(true).then(highlightLinkedTx)
})
watch(() => route.query.tx, (id, previous) => {
  if (id === previous || !statement.value) return
  void highlightLinkedTx()
})

/**
 * `?tx=` z odkazu „Druhá noha" — doskroluje na protějšek a probleskne ho.
 * Bez toho uživatel přistál na výpisu, kde nešlo poznat, který ze čtyř řádků
 * je ten hledaný, a proklik vypadal k ničemu.
 */
async function highlightLinkedTx(): Promise<void> {
  const id = Number(route.query.tx)
  if (!Number.isInteger(id) || id <= 0) return
  while (!paneDom.querySelector<HTMLElement>(`[data-tx-id="${id}"]`) && txPage.value < txPages.value) {
    if (Number(route.query.tx) !== id) return
    await load(false)
    await nextTick()
  }
  await nextTick()
  const row = paneDom.querySelector<HTMLElement>(`[data-tx-id="${id}"]`)
  if (!row) return
  row.scrollIntoView({ block: 'center', behavior: 'smooth' })
  row.classList.add('row-flash')
  // Třídu je nutné sundat, jinak by se animace nespustila při druhém příchodu
  // na tentýž řádek (CSS animace se přehraje jen při nasazení třídy).
  setTimeout(() => row.classList.remove('row-flash'), 2000)
}

// Změna filtru stavu spárování → reset na 1. stránku (server-side filtr).
watch([statusFilter, postingFilter], () => {
  if (statement.value) load(true)
})

// --- PDF příloha (nahrání / smazání) ---
const uploadingPdf = ref(false)

async function onPdfSelected(e: Event) {
  const input = e.target as HTMLInputElement
  const file = input.files?.[0]
  if (!file || !statement.value) return
  uploadingPdf.value = true
  try {
    await bankApi.uploadPdf(statement.value.id, file)
    toast.success(t('bank.pdf_uploaded'))
    await load()
  } catch (err) {
    toast.error(apiErrorMessage(err, t('bank.pdf_upload_failed')))
  } finally {
    uploadingPdf.value = false
    if (input) input.value = ''
  }
}

async function onDeletePdf() {
  if (!statement.value) return
  if (!confirm(t('bank.pdf_delete_confirm'))) return
  try {
    await bankApi.deletePdf(statement.value.id)
    toast.success(t('bank.pdf_deleted'))
    await load()
  } catch (err) {
    toast.error(apiErrorMessage(err, t('bank.pdf_delete_failed')))
  }
}

// Smazání avízo-výpisu (e-mailová bankovní avíza). Nabízí se jen když na něm
// nezbývá žádná spárovaná položka — typicky poté, co párování převzal oficiální
// GPC výpis. Backend mazání je admin-only a guarduje matched_count > 0.
const deletingStatement = ref(false)
async function onDeleteStatement() {
  if (!statement.value) return
  if (!confirm(t('bank.statement_delete_confirm'))) return
  deletingStatement.value = true
  try {
    await bankApi.delete(statement.value.id)
    toast.success(t('bank.statement_deleted'))
    router.push({ name: 'bank-statements' })
  } catch (err) {
    toast.error(apiErrorMessage(err, t('bank.statement_delete_failed')))
  } finally {
    deletingStatement.value = false
  }
}

const rematching = ref(false)
async function rematchStatement() {
  if (!statement.value || rematching.value) return
  if (!confirm(t('bank.rematch_confirm'))) return
  rematching.value = true
  try {
    const r = await bankApi.rematch(statement.value.id)
    toast.success(t('bank.rematch_done', {
      matched: r.newly_matched,
      partial: r.newly_partial,
      remaining: r.still_unmatched,
    }))
    await load()
  } catch (e: any) {
    toast.error(apiErrorMessage(e, t('bank.rematch_failed')))
  } finally {
    rematching.value = false
  }
}

const pdfInput = ref<HTMLInputElement | null>(null)

/**
 * Akce nad výpisem pro sdílený ActionBar.
 *
 * Každé tlačítko si nese vlastní `loading`/`disabled` — tři nezávislé operace
 * (párování, nahrání PDF, mazání výpisu) můžou běžet každá zvlášť a sloučit je
 * do jednoho příznaku by zablokovalo i to, co běžet může.
 *
 * Stažení a nahrání PDF se navzájem vylučují stavem `has_pdf`, takže inline
 * trojice zůstává čitelná v obou případech.
 */
const statementActions = computed<ActionItem[]>(() => {
  const s = statement.value
  if (!s) return []
  return [
    {
      key: 'rematch',
      label: rematching.value ? t('bank.rematch_running') : t('bank.rematch'),
      icon: 'cycle',
      tier: 'primary',
      variant: 'primary',
      show: auth.canWrite('bank.match'),
      disabled: rematching.value,
      run: () => { void rematchStatement() },
    },
    {
      key: 'gpc',
      label: 'GPC',
      icon: 'download',
      tier: 'secondary',
      show: s.has_file,
      title: t('bank.download_gpc'),
      href: bankApi.downloadUrl(s.id),
    },
    {
      key: 'pdf',
      label: 'PDF',
      icon: 'download',
      tier: 'secondary',
      show: s.has_pdf,
      title: s.pdf_name ?? t('bank.download_pdf'),
      href: bankApi.pdfUrl(s.id),
    },
    {
      key: 'pdf_upload',
      label: t('bank.pdf_upload'),
      icon: 'upload',
      tier: 'secondary',
      show: auth.canWrite('bank.import') && !s.has_pdf && !isVirtual.value,
      title: t('bank.pdf_upload_hint'),
      loading: uploadingPdf.value,
      run: () => pdfInput.value?.click(),
    },
    {
      key: 'pdf_delete',
      label: t('bank.pdf_delete'),
      icon: 'trash',
      tier: 'overflow',
      variant: 'danger',
      show: auth.canWrite('bank.import') && s.has_pdf,
      run: () => { void onDeletePdf() },
    },
    {
      key: 'statement_delete',
      label: t('bank.statement_delete'),
      icon: 'trash',
      tier: 'advanced',
      variant: 'danger',
      show: auth.isSuperadmin && isVirtual.value && s.matched_count === 0,
      title: t('bank.statement_delete_hint'),
      disabled: deletingStatement.value,
      loading: deletingStatement.value,
      run: () => { void onDeleteStatement() },
    },
  ]
})
</script>

<template>
  <div v-if="loading" class="text-center text-neutral-500 py-12 text-sm">{{ t('common.loading') }}</div>

  <div v-else-if="statement">
    <RouterLink to="/bank" class="text-sm text-neutral-600 hover:text-neutral-900">{{ t('bank.back') }}</RouterLink>
    <h1 class="text-2xl font-semibold mt-1 flex items-center gap-2 flex-wrap">
      <span v-if="statement.source === 'email_notice'" :title="t('bank.email_notice_hint')"
        class="inline-flex items-center gap-1 text-xs px-2 py-0.5 rounded bg-neutral-100 text-neutral-500 font-medium">
        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
        {{ t('bank.email_notice_badge') }}
      </span>
      <span v-if="statement.source === 'idoklad'" :title="t('bank.idoklad_source_hint')"
        class="inline-flex items-center gap-1 text-xs px-2 py-0.5 rounded bg-neutral-100 text-neutral-500 font-medium">
        {{ t('bank.idoklad_source_badge') }}
      </span>
      <span v-if="statement.source === 'email_notice'">{{ t('bank.email_notice_statement_title', { month: monthLabel(statement.statement_date) }) }}</span>
      <span v-else-if="statement.source === 'idoklad'">{{ t('bank.idoklad_statement_title', { month: monthLabel(statement.statement_date) }) }}</span>
      <span v-else>{{ t('bank.statement_title', { number: statement.statement_number, date: formatDate(statement.statement_date) }) }}</span>
    </h1>
    <p class="text-sm text-neutral-500 mt-0.5 flex items-center gap-1.5 flex-wrap">
      <span class="text-neutral-500">{{ t('bank.account') }}</span>
      <span class="font-mono font-semibold text-neutral-800">{{ formatAccountNumber(statement.account_number, statement.bank_code) }}</span>
      <span v-if="statement.account_label" class="text-neutral-400">— {{ statement.account_label }}</span>
      <span v-if="statement.currency" class="text-xs px-1.5 py-0.5 rounded bg-neutral-100 text-neutral-700 font-medium">{{ statement.currency }}</span>
      <span>· {{ statement.file_name }}</span>
    </p>

    <!-- Měsíční avízo-výpis: disponibilní zůstatek z nejnovějšího avíza (nesou ho
         Creditas/Fio/RB) + součty příjmů/výdajů měsíce spočtené z transakcí. -->
    <div v-if="isVirtual && noticeSummary" class="grid grid-cols-1 md:grid-cols-4 gap-4 mt-4 mb-4">
      <div class="bg-surface border border-neutral-200 rounded-lg p-4 shadow-sm">
        <div class="text-xs text-neutral-500 uppercase">{{ t('bank.available_balance') }}</div>
        <div class="text-lg font-mono font-semibold">
          {{ noticeSummary.balance !== null ? formatMoney(noticeSummary.balance, statement.currency ?? 'CZK') : '—' }}
        </div>
      </div>
      <div class="bg-surface border border-neutral-200 rounded-lg p-4 shadow-sm">
        <div class="text-xs text-neutral-500 uppercase">{{ t('bank.available_balance_as_of') }}</div>
        <div class="text-lg font-mono">
          {{ noticeSummary.balanceAt !== null ? formatDate(noticeSummary.balanceAt) : '—' }}
        </div>
      </div>
      <div class="bg-surface border border-neutral-200 rounded-lg p-4 shadow-sm">
        <div class="text-xs text-neutral-500 uppercase">{{ t('bank.credit_total') }}</div>
        <div class="text-lg font-mono text-success-600">+{{ formatMoney(noticeSummary.credit, statement.currency ?? 'CZK') }}</div>
      </div>
      <div class="bg-surface border border-neutral-200 rounded-lg p-4 shadow-sm">
        <div class="text-xs text-neutral-500 uppercase">{{ t('bank.debit_total') }}</div>
        <div class="text-lg font-mono text-danger-500">−{{ formatMoney(noticeSummary.debit, statement.currency ?? 'CZK') }}</div>
      </div>
    </div>

    <div v-else-if="!isVirtual" class="grid grid-cols-1 md:grid-cols-4 gap-4 mt-4 mb-4">
      <div class="bg-surface border border-neutral-200 rounded-lg p-4 shadow-sm">
        <div class="text-xs text-neutral-500 uppercase">{{ t('bank.prev_balance') }}</div>
        <div class="text-lg font-mono">{{ formatMoney(statement.prev_balance, statement.currency ?? 'CZK') }}</div>
      </div>
      <div class="bg-surface border border-neutral-200 rounded-lg p-4 shadow-sm">
        <div class="text-xs text-neutral-500 uppercase">{{ t('bank.curr_balance') }}</div>
        <div class="text-lg font-mono font-semibold">{{ formatMoney(statement.curr_balance, statement.currency ?? 'CZK') }}</div>
      </div>
      <div class="bg-surface border border-neutral-200 rounded-lg p-4 shadow-sm">
        <div class="text-xs text-neutral-500 uppercase">{{ t('bank.credit_total') }}</div>
        <div class="text-lg font-mono text-success-600">+{{ formatMoney(statement.credit_total, statement.currency ?? 'CZK') }}</div>
      </div>
      <div class="bg-surface border border-neutral-200 rounded-lg p-4 shadow-sm">
        <div class="text-xs text-neutral-500 uppercase">{{ t('bank.debit_total') }}</div>
        <div class="text-lg font-mono text-danger-500">−{{ formatMoney(Math.abs(statement.debit_total), statement.currency ?? 'CZK') }}</div>
      </div>
    </div>

    <RuleHintBanner v-if="isDoubleEntry && hintTx && hintData" class="mt-4"
      :count="hintData.count" :tx="hintTx"
      :debit-account-code="hintData.debit" :credit-account-code="hintData.credit"
      @close="closeHint" @created="load" />

    <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden mt-4">
      <header class="px-5 py-3 border-b border-neutral-200 flex items-center justify-between gap-3">
        <h2 class="text-sm font-semibold uppercase tracking-wide text-neutral-500 flex items-center gap-2">
          {{ t('bank.transactions') }}
          ({{ txTotal }}<span v-if="statusFilter || postingFilter"> / {{ statement.transaction_count }}</span>)
          <span v-if="isDoubleEntry && pendingPostingCount > 0"
            class="inline-flex items-center bg-warning-50 text-warning-600 rounded-full px-2 py-0.5 text-xs normal-case font-medium">
            {{ t('bank.posting.pending_chip', { count: pendingPostingCount }) }}
          </span>
        </h2>
        <div class="flex items-center gap-2">
          <select v-model="statusFilter"
            :title="t('bank.filter_status')"
            class="h-8 px-2 text-xs border border-neutral-300 rounded-md text-neutral-700 bg-surface">
            <option value="">{{ t('bank.filter_all') }}</option>
            <option v-for="s in STATUS_OPTIONS" :key="s" :value="s">{{ statusLabel(s) }}</option>
          </select>
          <select v-if="isDoubleEntry" v-model="postingFilter"
            :title="t('bank.filter_posting')"
            class="h-8 px-2 text-xs border border-neutral-300 rounded-md text-neutral-700 bg-surface">
            <option value="">{{ t('bank.filter_posting_all') }}</option>
            <option value="unposted">{{ t('bank.filter_posting_unposted') }}</option>
            <option value="posted">{{ t('bank.filter_posting_posted') }}</option>
          </select>
          <!-- Pět akcí v řadě porušovalo konvenci „max 3 a zbytek do …". ActionBar
               cap řeší sám; nahrávání PDF jede přes skrytý input, protože položka
               lišty je tlačítko, ne <label>. -->
          <ActionBar :actions="statementActions" />
          <input ref="pdfInput" type="file" accept=".pdf,application/pdf" class="hidden" @change="onPdfSelected" />
        </div>
      </header>
      <!-- Desktop: tabulka -->
      <div class="hidden md:block overflow-x-auto">
      <table class="w-full text-sm table-sticky-first">
        <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
          <tr>
            <th class="px-3 py-2 text-left font-medium">{{ t('bank.date') }}</th>
            <th class="px-3 py-2 text-right font-medium">{{ t('bank.amount') }}</th>
            <th class="px-3 py-2 text-left font-medium">{{ t('bank.vs_ks') }}</th>
            <th class="px-3 py-2 text-left font-medium">{{ t('bank.counterparty') }}</th>
            <th class="px-3 py-2 text-left font-medium">{{ t('bank.invoice') }}</th>
            <th class="px-3 py-2 text-center font-medium">{{ t('invoice.status_label') }}</th>
            <th class="px-3 py-2 w-32"></th>
          </tr>
        </thead>
        <tbody class="divide-y divide-neutral-100">
          <BankTransactionRow v-for="tx in filteredTransactions" :key="tx.id"
            layout="desktop" :tx="tx" :is-double-entry="isDoubleEntry"
            :fallback-currency="statement.currency" :colspan="7" :actions="bankActions"
            @posted="onTxPosted" @changed="load" />
        </tbody>
      </table>
      </div>

      <!-- Mobile: stack karet -->
      <div class="md:hidden divide-y divide-neutral-100">
        <BankTransactionRow v-for="tx in filteredTransactions" :key="`m-${tx.id}`"
          layout="mobile" :tx="tx" :is-double-entry="isDoubleEntry"
          :fallback-currency="statement.currency" :actions="bankActions"
          @posted="onTxPosted" @changed="load" />
      </div>

      <div v-if="txPage < txPages" class="text-center py-3 border-t border-neutral-200">
        <button @click="load(false)" :disabled="loadingMore"
          class="cursor-pointer h-9 px-4 text-sm border border-neutral-300 rounded-md hover:bg-neutral-50 disabled:opacity-50">
          {{ loadingMore ? t('common.loading_more') : t('common.load_more') }}
        </button>
      </div>
    </div>

    <BankMatchModal :actions="bankActions" :fallback-currency="statement.currency" />
    <BankCreatePurchaseModal :actions="bankActions" />
    <BankRequestDocModal :actions="bankActions" />
  </div>
</template>

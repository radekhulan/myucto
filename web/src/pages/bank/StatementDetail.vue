<script setup lang="ts">
import { useWindowRefresh } from '@/composables/useWindowRefresh'
import { ref, computed, onMounted, onUnmounted, watch, nextTick } from 'vue'
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
import { statementClosingBalance, statementGpcUrl, statementGpcTitle, statementHasGpc } from '@/utils/bankStatement'
import { downloadApiFile } from '@/utils/downloadFile'
import RuleHintBanner from '@/components/bank/RuleHintBanner.vue'
import NoticeConfirmationSummary from '@/components/bank/NoticeConfirmationSummary.vue'
import NoticeConfirmationCheck from '@/components/bank/NoticeConfirmationCheck.vue'
import BankTransactionRow from '@/components/bank/BankTransactionRow.vue'
import BankTransactionDialogs from '@/components/bank/BankTransactionDialogs.vue'
import BankMatchModal from '@/components/bank/BankMatchModal.vue'
import BankCreatePurchaseModal from '@/components/bank/BankCreatePurchaseModal.vue'
import BankRequestDocModal from '@/components/bank/BankRequestDocModal.vue'
import type { PostResult } from '@/api/bankPosting'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import { ICONS, btnFilled } from '@/components/ui/buttonStyles'
import { useBankTransactionActions } from '@/composables/useBankTransactionActions'
import { useBankTransactionSort } from '@/composables/useBankTransactionSort'
import { useFillViewportHeight } from '@/composables/useFillViewportHeight'
import { useScrollLoadMore } from '@/composables/useScrollLoadMore'
import { useBankFilterMemory } from '@/composables/useBankFilterMemory'
import { usePaneDom } from '@/composables/usePaneDom'
import SortableTh from '@/components/ui/SortableTh.vue'
import BankTransactionSortSelect from '@/components/bank/BankTransactionSortSelect.vue'

const { t, te, locale } = useI18n()
const toast = useToast()
const router = useRouter()
const auth = useAuthStore()
const supplierStore = useSupplierStore()
const paneDom = usePaneDom()
const listBox = ref<HTMLElement | null>(null)
const loadMoreTarget = ref<HTMLElement | null>(null)
useFillViewportHeight(listBox)

// Stažení jde přes axios, aby nesl hlavičku X-Supplier-Id. Holý odkaz ji nepošle
// a server by u výpisu jiné než výchozí firmy vrátil not_found.
async function downloadStatementFile(url: string | undefined, fallbackName: string) {
  if (!url) return
  try {
    await downloadApiFile(url, fallbackName)
  } catch (e) {
    toast.error(apiErrorMessage(e))
  }
}
function blockerLabel(code: string) {
  const key = `bank.notice_confirmation.reasons.${code}`
  return te(key) ? t(key) : t('bank.notice_confirmation.reason_fallback')
}
const sendingUnmatched = ref(false)
async function sendUnmatchedToClient() {
  const id = Number(route.params.id)
  sendingUnmatched.value = true
  try {
    const preview = await bankApi.unmatchedRecipients(id)
    if (preview.count === 0) {
      toast.error(t('bank.unmatched_export.empty'))
      return
    }
    if (preview.to.length === 0) {
      toast.error(t('bank.unmatched_export.no_recipients'))
      return
    }
    if (!window.confirm(t('bank.unmatched_export.confirm', {
      count: preview.count,
      account: preview.account,
      recipients: preview.to.join(', '),
    }))) return
    const result = await bankApi.sendUnmatched(id)
    toast.success(t(result.deferred ? 'bank.unmatched_export.queued' : 'bank.unmatched_export.sent', { count: result.count }))
  } catch (e) {
    toast.error(apiErrorMessage(e))
  } finally {
    sendingUnmatched.value = false
  }
}
const isDoubleEntry = computed(() => !auth.isClientRole && auth.hasCommercialFeatures && supplierStore.currentSupplier?.accounting_mode === 'double_entry')

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
const bankActions = useBankTransactionActions({ reload: () => load(), refresh: () => refreshTransactions() })

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
const refreshing = ref(false)
let loadGeneration = 0
let pendingLoadMore: { generation: number; promise: Promise<void> } | null = null
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
const showConfirmed = ref(false)
const confirmationFilter = ref<'' | 'pending' | 'review' | 'confirmed'>('')
const noticeFilterParams = computed(() => ({ show_confirmed: showConfirmed.value || confirmationFilter.value === 'confirmed' ? 1 as const : undefined, confirmation_state: confirmationFilter.value || undefined }))
const statusFilter = ref<string>('')
const postingFilter = ref<PostingFilter | ''>(route.query.posting_status === 'unposted' ? 'unposted' : '')
// Řazení podle sloupce — řadí server přes všechny stránky (stránkuje se tam).
const txSort = useBankTransactionSort(['posted_at', 'amount', 'variable_symbol', 'counterparty', 'invoice', 'status'])
useBankFilterMemory(() => `${supplierStore.currentSupplierId}:statement:${route.params.id}`, () => ({
  status: statusFilter.value, posting: postingFilter.value, sort: txSort.selectValue.value,
}), value => {
  statusFilter.value = STATUS_OPTIONS.includes(value.status as never) ? value.status! : ''
  postingFilter.value = route.query.posting_status === 'unposted' ? 'unposted'
    : value.posting === 'unposted' || value.posting === 'posted' ? value.posting : ''
  txSort.selectValue.value = typeof value.sort === 'string' ? value.sort : ''
})
useScrollLoadMore(loadMoreTarget, () => !loading.value && !loadingMore.value && !refreshing.value && txPage.value < txPages.value, () => load(false))
function onListScroll(event: Event) {
  const el = event.currentTarget as HTMLElement
  if (el.scrollTop + el.clientHeight >= el.scrollHeight - 240 && !loading.value && !loadingMore.value && !refreshing.value && txPage.value < txPages.value) void load(false)
}

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
  if (reset) return loadPage(true)
  if (refreshing.value) return
  if (pendingLoadMore?.generation === loadGeneration) return pendingLoadMore.promise
  const promise = loadPage(false)
  pendingLoadMore = { generation: loadGeneration, promise }
  try {
    await promise
  } finally {
    if (pendingLoadMore?.promise === promise) pendingLoadMore = null
  }
}
async function loadPage(reset: boolean) {
  const generation = ++loadGeneration
  const requestedPage = reset ? 1 : txPage.value + 1
  refreshing.value = false
  if (reset) {
    loading.value = true
    txPage.value = 1
  } else {
    loadingMore.value = true
  }
  try {
    const statementId = Number(route.params.id)
    const status = statusFilter.value
    const posting = postingFilter.value
    const sort = txSort.sort.value
    const [res, suggestionsResult] = await Promise.all([
      bankApi.get(statementId, {
        ...noticeFilterParams.value,
        page: requestedPage,
        status: statusFilter.value ? (statusFilter.value as MatchStatus) : undefined,
        posting_status: postingFilter.value || undefined,
        ...txSort.params.value,
      }),
      reset
        ? bankApi.matchSuggestions(statementId)
        : Promise.resolve(null),
    ])
    if (generation !== loadGeneration || statementId !== Number(route.params.id) || status !== statusFilter.value || posting !== postingFilter.value || sort !== txSort.sort.value) return
    const transactions = reset || !statement.value
      ? res.transactions
      : [...statement.value.transactions, ...res.transactions]
    statement.value = { ...res, transactions }
    txPage.value = requestedPage
    if (suggestionsResult) {
      const map = new Map(suggestionsResult.suggestions
        .filter(s => s.status === 'pending')
        .map(s => [s.bank_transaction_id, s]))
      bankActions.setSuggestions(map)
    }
    txTotal.value = res.transactions_meta.total
    txPages.value = res.transactions_meta.pages
  } finally {
    if (generation === loadGeneration) {
      loading.value = false
      loadingMore.value = false
    }
  }
}
async function refreshTransactions() {
  const generation = ++loadGeneration
  refreshing.value = true
  const statementId = Number(route.params.id)
  const status = statusFilter.value
  const posting = postingFilter.value
  const requestedPage = txPage.value
  const sort = txSort.sort.value
  const params = { ...noticeFilterParams.value, status: status ? status as MatchStatus : undefined, posting_status: posting || undefined, ...txSort.params.value }
  try {
    const [first, suggestions] = await Promise.all([
      bankApi.get(statementId, { ...params, page: 1 }), bankApi.matchSuggestions(statementId),
    ])
    if (generation !== loadGeneration) return
    const lastPage = Math.max(1, Math.min(requestedPage, first.transactions_meta.pages))
    const remaining = await Promise.all(Array.from({ length: lastPage - 1 }, (_, index) =>
      bankApi.get(statementId, { ...params, page: index + 2 })))
    if (generation !== loadGeneration || statementId !== Number(route.params.id) || status !== statusFilter.value || posting !== postingFilter.value || sort !== txSort.sort.value) return
    statement.value = { ...first, transactions: [first, ...remaining].flatMap(result => result.transactions) }
    txPage.value = lastPage
    txTotal.value = first.transactions_meta.total
    txPages.value = first.transactions_meta.pages
    bankActions.setSuggestions(new Map(suggestions.suggestions.filter(s => s.status === 'pending').map(s => [s.bank_transaction_id, s])))
  } finally {
    if (generation === loadGeneration) {
      refreshing.value = false
      loading.value = false
      loadingMore.value = false
    }
  }
}

let linkedTxGeneration = 0
let disposed = false
let linkedStatementKey = ''

function wantsLinkedTxDetail(): boolean {
  const id = Number(route.query.tx)
  return route.query.detail === '1' && Number.isInteger(id) && id > 0
}

function currentStatementKey(): string {
  return `${supplierStore.currentSupplierId}:${route.params.id}`
}

async function loadLinkedStatement(): Promise<void> {
  const generation = ++linkedTxGeneration
  linkedStatementKey = currentStatementKey()
  statement.value = null
  bankActions.textDetail.value = null
  // Odkaz na konkrétní pohyb musí fungovat i s dříve zapamatovaným filtrem.
  if (wantsLinkedTxDetail()) {
    statusFilter.value = ''
    postingFilter.value = ''
  }
  try {
    await load(true)
    if (!disposed && generation === linkedTxGeneration) await highlightLinkedTx()
  } catch (error) {
    if (!disposed && generation === linkedTxGeneration) toast.error(apiErrorMessage(error))
  }
}

async function focusLinkedTx(): Promise<void> {
  const generation = ++linkedTxGeneration
  bankActions.textDetail.value = null
  try {
    await highlightLinkedTx()
  } catch (error) {
    if (!disposed && generation === linkedTxGeneration) toast.error(apiErrorMessage(error))
  }
}

onMounted(() => { void loadLinkedStatement() })

// Router recykluje stránku výpisu i při navigaci uvnitř aplikačního panelu.
// Celé načtení jen při změně výpisu; jiný pohyb téhož výpisu se jen dohledá.
watch([() => route.params.id, () => route.query.tx, () => route.query.detail,
  () => supplierStore.currentSupplierId], () => {
  const sameStatement = statement.value !== null && linkedStatementKey === currentStatementKey()
  const filtersHideDetail = wantsLinkedTxDetail() && (statusFilter.value !== '' || postingFilter.value !== '')
  if (sameStatement && !filtersHideDetail) void focusLinkedTx()
  else void loadLinkedStatement()
})

/** `?tx=` zvýrazní řádek; `&detail=1` navíc otevře jeho existující detail. */
async function highlightLinkedTx(): Promise<void> {
  const id = Number(route.query.tx)
  if (!Number.isInteger(id) || id <= 0) return
  const generation = linkedTxGeneration
  const statementId = route.params.id
  const supplierId = supplierStore.currentSupplierId
  const detail = route.query.detail
  const isCurrent = () => !disposed && generation === linkedTxGeneration
    && statementId === route.params.id && supplierId === supplierStore.currentSupplierId
    && Number(route.query.tx) === id && route.query.detail === detail
  let tx = statement.value?.transactions.find(tx => tx.id === id)
  while (!tx && txPage.value < txPages.value) {
    if (!isCurrent() || refreshing.value) return
    const previousPage = txPage.value
    await load(false)
    // Chyba či zneplatněný request nesmí vyvolat další hledání téže stránky.
    if (!isCurrent() || txPage.value <= previousPage) return
    tx = statement.value?.transactions.find(tx => tx.id === id)
  }
  await nextTick()
  if (!isCurrent() || !tx) return
  if (detail === '1') bankActions.textDetail.value = tx
  const row = paneDom.querySelector<HTMLElement>(`[data-tx-id="${id}"]`)
  if (!row) return
  row.scrollIntoView({ block: 'center', behavior: 'smooth' })
  row.classList.add('row-flash')
  setTimeout(() => row.classList.remove('row-flash'), 2000)
}

// Změna filtru stavu spárování → reset na 1. stránku (server-side filtr).
watch([showConfirmed, confirmationFilter, statusFilter, postingFilter, txSort.sort, () => route.params.id, () => supplierStore.currentSupplierId], () => { loadGeneration++ }, { flush: 'sync' })
onUnmounted(() => { disposed = true; loadGeneration++; linkedTxGeneration++ })
watch(() => supplierStore.currentSupplierId, () => { statement.value = null; void load(true) })
watch([showConfirmed, confirmationFilter, statusFilter, postingFilter, txSort.sort], () => {
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

// Bezpečnost odstranění avíz určuje backend podle potvrzení a závislostí;
// počet spárovaných faktur sám o sobě odstranitelnost nedokazuje.
const deletingStatement = ref(false)
async function onDeleteStatement() {
  if (!statement.value || (statement.value.source === 'email_notice' && !statement.value.can_delete_notices)) return
  const prompt = t(statement.value.source === 'email_notice' ? 'bank.notice_confirmation.delete_confirm' : statement.value.api_evidence ? 'bank.statement_delete_evidence_confirm' : 'bank.statement_delete_confirm')
  const noticeWarning = statement.value.deleted_notice_confirmations ? '\n\n' + t('bank.notice_confirmation.deleted_evidence_warning', { count: statement.value.deleted_notice_confirmations }) : ''
  if (!confirm(prompt + noticeWarning)) return
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
    // Nahrazená avíza jsou samostatná zpráva: netýkají se párování, ale toho, že ze
    // seznamu zmizely duplicitní řádky - bez vysvětlení by to mátlo.
    if (r.superseded > 0) toast.info(t('bank.rematch_superseded', { count: r.superseded }))
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
      show: !auth.isClientRole && auth.canWrite('bank.match'),
      disabled: rematching.value,
      run: () => { void rematchStatement() },
    },
    {
      key: 'unmatched_export',
      show: !auth.isClientRole,
      label: t('bank.unmatched_export.download'),
      icon: 'download',
      tier: 'secondary',
      run: () => { void downloadStatementFile(bankApi.unmatchedExportUrl(s.id), 'nesparovane-pohyby.xlsx') },
    },
    {
      key: 'unmatched_send',
      label: t('bank.unmatched_export.send'),
      icon: 'send',
      tier: 'secondary',
      variant: 'primary',
      show: !auth.isClientRole && auth.canWrite('bank'),
      loading: sendingUnmatched.value,
      disabled: sendingUnmatched.value,
      run: () => { void sendUnmatchedToClient() },
    },
    {
      key: 'gpc',
      label: 'GPC',
      icon: 'download',
      tier: 'secondary',
      show: statementHasGpc(s),
      disabled: !statementGpcUrl(s),
      disabledReason: t(`bank.balance_${s.balance_calculation?.status ?? 'unavailable'}`),
      title: t(statementGpcTitle(s)),
      run: () => { void downloadStatementFile(statementGpcUrl(s), 'vypis.gpc') },
    },
    {
      key: 'pdf',
      label: 'PDF',
      icon: 'download',
      tier: 'secondary',
      show: s.has_pdf,
      title: s.pdf_name ?? t('bank.download_pdf'),
      run: () => { void downloadStatementFile(bankApi.pdfUrl(s.id), s.pdf_name ?? 'vypis.pdf') },
    },
    ...(s.evidence_pdfs ?? []).map(pdf => ({
      key: `evidence_pdf_${pdf.id}`,
      label: t('bank.evidence_pdf', { name: pdf.pdf_name ?? String(pdf.id) }),
      icon: 'download' as const,
      tier: 'overflow' as const,
      run: () => { void downloadStatementFile(bankApi.pdfUrl(pdf.id), pdf.pdf_name ?? 'vypis.pdf') },
    })),
    ...(s.evidence_statements ?? []).map(source => ({
      key: `evidence_statement_${source.id}`,
      label: t('bank.evidence_statement', { name: source.file_name ?? String(source.id), count: source.transaction_count }),
      icon: 'eye' as const,
      tier: 'overflow' as const,
      run: () => { void router.push({ name: 'bank-detail', params: { id: source.id } }) },
    })),
    {
      key: 'pdf_upload',
      label: t('bank.pdf_upload'),
      icon: 'upload',
      tier: 'secondary',
      show: !auth.isClientRole && auth.canWrite('bank.import') && !s.has_pdf && !isVirtual.value,
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
      show: !auth.isClientRole && auth.canWrite('bank.import') && s.has_pdf,
      run: () => { void onDeletePdf() },
    },
    {
      key: 'statement_delete',
      label: t(s.api_evidence ? 'bank.statement_delete_evidence' : 'bank.statement_delete'),
      icon: 'trash',
      tier: 'advanced',
      variant: 'danger',
      show: !auth.isClientRole && auth.canWrite('bank') && (s.source === 'email_notice' || auth.isCompanyAdminRole) && (s.api_evidence === true || isVirtual.value),
      title: t(s.api_evidence ? 'bank.statement_delete_evidence_hint' : 'bank.statement_delete_hint'),
      disabled: deletingStatement.value || (s.source === 'email_notice' && !s.can_delete_notices),
      disabledReason: s.source === 'email_notice' && !s.can_delete_notices ? t('bank.notice_confirmation.delete_blocked') : undefined,
      loading: deletingStatement.value,
      run: () => { void onDeleteStatement() },
    },
  ]
})
useWindowRefresh(['bank', 'bank-statements', 'bank-transactions', 'bank-match-suggestions', 'accounting', 'invoices', 'purchase-invoices', 'clients'], refreshTransactions)
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

    <section v-if="!auth.isClientRole && statement.source === 'email_notice'" class="mt-4 p-4 rounded-lg bg-surface border border-neutral-200 space-y-3">
      <NoticeConfirmationSummary v-if="statement.confirmation_summary" :summary="statement.confirmation_summary" />
      <p class="text-sm text-neutral-600">{{ t(statement.can_delete_notices ? 'bank.notice_confirmation.delete_ready' : 'bank.notice_confirmation.delete_blocked') }}</p>
      <ul v-if="statement.deletion_blockers?.length" class="text-xs text-warning-700 space-y-1">
        <li v-for="blocker in statement.deletion_blockers" :key="blocker.code">{{ t('bank.notice_confirmation.blocker', { count: blocker.count, reason: blockerLabel(blocker.code) }) }}</li>
      </ul>
      <div class="flex flex-wrap items-center gap-3">
        <label class="text-sm flex items-center gap-2"><input v-model="showConfirmed" type="checkbox" />{{ t('bank.notice_confirmation.show_confirmed') }}</label>
        <select v-model="confirmationFilter" :aria-label="t('bank.notice_confirmation.filter')" class="border border-neutral-300 rounded bg-surface h-8 px-2 text-sm">
          <option value="">{{ t('bank.notice_confirmation.all_states') }}</option>
          <option v-for="state in (['pending', 'review', 'confirmed'] as const)" :key="state" :value="state">{{ t(`bank.notice_confirmation.states.${state}`) }}</option>
        </select>
        <NoticeConfirmationCheck v-if="auth.canWrite('bank.match')" :statement-id="statement.id" @changed="load()" />
      </div>
    </section>

    <!-- Import pohyby uložil, ale jejich zpracování (párování, zaúčtování) spadlo.
         Opakované nahrání souboru je nezpracuje, dokončí je jen přepárování výpisu. -->
    <div v-if="statement.processing_failed" role="alert" data-testid="statement-processing-failed"
      class="mt-4 flex flex-wrap items-start gap-3 rounded-lg border border-warning-200 bg-warning-50 px-4 py-3 text-sm">
      <svg class="w-5 h-5 text-warning-600 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" /></svg>
      <div class="flex-1 min-w-56">
        <div class="font-semibold text-warning-800">{{ t('bank.processing_failed_title') }}</div>
        <p class="text-neutral-700 mt-0.5">{{ t('bank.processing_failed_text', { count: statement.unprocessed_count ?? 0 }) }}</p>
        <p v-if="statement.processing_error" class="text-xs text-neutral-500 mt-1 break-words">{{ t('bank.processing_failed_error', { error: statement.processing_error }) }}</p>
      </div>
      <button v-if="!auth.isClientRole && auth.canWrite('bank.match')" type="button" data-testid="statement-processing-rematch"
        :class="btnFilled('warning')" class="whitespace-nowrap" :disabled="rematching" @click="rematchStatement">
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.cycle" /></svg>
        {{ rematching ? t('bank.rematch_running') : t('bank.rematch') }}
      </button>
    </div>

    <!-- Měsíční avízo-výpis: disponibilní zůstatek z nejnovějšího avíza (nesou ho
         Creditas/Fio/RB) + součty příjmů/výdajů měsíce spočtené z transakcí. -->
    <div v-if="isVirtual && noticeSummary" class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-3 md:gap-4 mt-4 mb-4">
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

    <div v-else-if="!isVirtual" class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-3 md:gap-4 mt-4 mb-4">
      <div class="bg-surface border border-neutral-200 rounded-lg p-4 shadow-sm">
        <div class="text-xs text-neutral-500 uppercase">{{ t('bank.prev_balance') }}</div>
        <div class="text-lg font-mono">{{ (statement.prev_balance ?? statement.balance_calculation?.opening) == null ? '-' : formatMoney(statement.prev_balance ?? statement.balance_calculation?.opening, statement.currency ?? 'CZK') }}</div>
      </div>
      <div class="bg-surface border border-neutral-200 rounded-lg p-4 shadow-sm">
        <div class="text-xs text-neutral-500 uppercase">{{ t('bank.curr_balance') }}</div>
        <div class="text-lg font-mono font-semibold">{{ (statementClosingBalance(statement)) == null ? '-' : formatMoney(statementClosingBalance(statement), statement.currency ?? 'CZK') }}</div>
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

    <div v-if="statement.balance_calculation" class="mt-4 mb-4 rounded-lg border border-neutral-200 bg-surface p-4 text-sm" role="status">
      <strong>{{ t(`bank.balance_${statement.balance_calculation.status}`) }}</strong>
      <p v-if="statement.balance_calculation.bank_statement_id" class="mt-1">
        <RouterLink :to="`/bank/${statement.balance_calculation.bank_statement_id}`" class="text-primary-600 underline">{{ t('bank.balance_bank_evidence') }}</RouterLink>
      </p>
      <p v-if="statement.balance_calculation.from" class="mt-1">
        {{ t('bank.balance_period', { from: formatDate(statement.balance_calculation.from), to: formatDate(statement.balance_calculation.to ?? ''), count: statement.balance_calculation.transaction_count }) }}
      </p>
      <p v-if="statement.balance_calculation.status === 'mismatch'" class="text-danger-600 mt-1">
        {{ t('bank.balance_difference', { amount: formatMoney(statement.balance_calculation.difference, statement.currency ?? 'CZK') }) }}
      </p>
      <!-- Zůstatek z bankovního avíza proti součtu evidovaných pohybů: rozdíl znamená
           nenačtený (nebo přebývající) pohyb a nesmí zůstat tichý. -->
      <p v-if="statement.balance_calculation.unexplained_difference" class="text-danger-600 mt-1" data-testid="balance-unexplained">
        {{ t('bank.balance_difference', { amount: formatMoney(statement.balance_calculation.unexplained_difference, statement.currency ?? 'CZK') }) }}
      </p>
      <p class="text-neutral-500 mt-1">{{ t('bank.gpc_calculated_hint') }}</p>
    </div>

    <RuleHintBanner v-if="isDoubleEntry && hintTx && hintData" class="mt-4"
      :count="hintData.count" :tx="hintTx"
      :debit-account-code="hintData.debit" :credit-account-code="hintData.credit"
      @close="closeHint" @created="load" />

    <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden mt-4">
      <!-- Nadpis, dva filtry a lišta akcí se na mobilu do jedné řady nevejdou —
           `flex-wrap` je nechá zalomit místo vytlačení mimo kartu. -->
      <header class="px-4 md:px-5 py-3 border-b border-neutral-200 flex flex-wrap items-center justify-between gap-2 md:gap-3">
        <h2 class="text-sm font-semibold uppercase tracking-wide text-neutral-500 flex flex-wrap items-center gap-2">
          {{ t('bank.transactions') }}
          ({{ txTotal }}<span v-if="statusFilter || postingFilter"> / {{ statement.transaction_count }}</span>)
          <span v-if="isDoubleEntry && pendingPostingCount > 0"
            class="inline-flex items-center bg-warning-50 text-warning-600 rounded-full px-2 py-0.5 text-xs normal-case font-medium">
            {{ t('bank.posting.pending_chip', { count: pendingPostingCount }) }}
          </span>
        </h2>
        <div class="flex flex-wrap items-center gap-2">
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
          <!-- Mobilní karty nemají hlavičku tabulky — řazení přes výběr. -->
          <BankTransactionSortSelect v-model="txSort.selectValue.value" class="md:hidden" :keys="txSort.keys" />
          <!-- Pět akcí v řadě porušovalo konvenci „max 3 a zbytek do …". ActionBar
               cap řeší sám; nahrávání PDF jede přes skrytý input, protože položka
               lišty je tlačítko, ne <label>. -->
          <ActionBar :actions="statementActions" />
          <input ref="pdfInput" type="file" accept=".pdf,application/pdf" class="hidden" @change="onPdfSelected" />
        </div>
      </header>
      <!-- Desktop: tabulka -->
      <div ref="listBox" class="hidden md:block overflow-auto scrollbar-slim" @scroll.passive="onListScroll">
      <table class="w-full text-sm table-sticky-first">
        <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide sticky top-0 z-20 shadow-sm">
          <tr>
            <SortableTh :label="t('bank.date')" sort-key="posted_at" :sort="txSort.sort.value" @toggle="txSort.toggle" />
            <SortableTh :label="t('bank.amount')" sort-key="amount" :sort="txSort.sort.value" align="right" @toggle="txSort.toggle" />
            <SortableTh :label="t('bank.vs_ks')" sort-key="variable_symbol" :sort="txSort.sort.value" @toggle="txSort.toggle" />
            <SortableTh :label="t('bank.counterparty')" sort-key="counterparty" :sort="txSort.sort.value" @toggle="txSort.toggle" />
            <SortableTh :label="t('bank.invoice')" sort-key="invoice" :sort="txSort.sort.value" @toggle="txSort.toggle" />
            <SortableTh :label="t('invoice.status_label')" sort-key="status" :sort="txSort.sort.value" @toggle="txSort.toggle" />
            <th v-if="isDoubleEntry" class="px-3 py-2">{{ t('bank.counter_account') }}</th>
            <th class="px-3 py-2 w-32"></th>
          </tr>
        </thead>
        <tbody class="divide-y divide-neutral-100">
          <BankTransactionRow v-for="tx in filteredTransactions" :key="tx.id"
            layout="desktop" :tx="tx" :is-double-entry="isDoubleEntry"
            :fallback-currency="statement.currency" :colspan="isDoubleEntry ? 8 : 7" :show-counter-account="isDoubleEntry" :actions="bankActions"
            @posted="onTxPosted" @changed="load" />
        </tbody>
      </table>
      </div>

      <!-- Mobile: stack karet -->
      <div class="md:hidden divide-y divide-neutral-100">
        <BankTransactionRow v-for="tx in filteredTransactions" :key="`m-${tx.id}`"
          layout="mobile" :tx="tx" :is-double-entry="isDoubleEntry"
          :fallback-currency="statement.currency" :show-counter-account="isDoubleEntry" :actions="bankActions"
          @posted="onTxPosted" @changed="load" />
      </div>

      <div v-if="txPage < txPages" ref="loadMoreTarget" class="text-center border-t border-neutral-200 pointer-fine-hidden">
        <button @click="load(false)" :disabled="loadingMore || refreshing"
          class="cursor-pointer my-3 h-9 px-4 text-sm border border-neutral-300 rounded-md hover:bg-neutral-50 disabled:opacity-50">
          {{ loadingMore ? t('common.loading_more') : t('common.load_more') }}
        </button>
      </div>
    </div>

    <BankTransactionDialogs :actions="bankActions" :fallback-currency="statement.currency" :own-account="statement.account_number" :own-bank-code="statement.bank_code" />
    <BankMatchModal :actions="bankActions" :fallback-currency="statement.currency" />
    <BankCreatePurchaseModal :actions="bankActions" />
    <BankRequestDocModal :actions="bankActions" />
  </div>
</template>

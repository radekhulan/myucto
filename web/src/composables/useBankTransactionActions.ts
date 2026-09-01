import { ref, computed } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useToast } from '@/composables/useToast'
import { useHotkey } from '@/composables/useHotkey'
import { formatMoney, formatDate } from '@/composables/useFormat'
import { apiErrorMessage } from '@/api/errors'
import {
  bankApi,
  type BankTransaction,
  type MatchCandidate,
  type MatchSuggestion,
  type SplitSuggestion,
  type MatchPostingResult,
} from '@/api/bank'
import { invoicesApi } from '@/api/invoices'
import { documentRequestsApi } from '@/api/documentRequests'
import { gopayApi, type GoPayPayoutCandidate } from '@/api/gopay'
import type { Client } from '@/api/clients'

const POSTING_REASON_KEYS: Record<string, string> = {
  fx_not_supported: 'bank.posting.reason_fx_not_supported',
  document_not_posted: 'bank.posting.reason_document_not_posted',
  period_closed: 'bank.posting.err_period_closed',
  not_double_entry: 'bank.posting.reason_not_double_entry',
  already_paid_verify: 'bank.posting.reason_already_paid_verify',
  ambiguous_supplier: 'bank.posting.reason_ambiguous_supplier',
}

type AnchorOption = { value: number; label: string; secondary?: string }

/**
 * Sdílená akční logika nad jednou bankovní transakcí (mini-epic AUTOMATIZACE + #52).
 * Extrahováno ze StatementDetail.vue, ať ji sdílí i UnpostedTransactions.vue (záložka
 * „Všechny pohyby") — nová/opravená párování/doklady se řeší na jednom místě.
 *
 * `reload` zavolá stránka po jakékoli akci měnící data (match/ignore/unmatch/create/
 * request-doc) — StatementDetail typicky reloadne celý výpis, UnpostedTransactions
 * jen aktuální stránku (+ přepočet countů v záložkách).
 */
export function useBankTransactionActions(opts: { reload: () => Promise<void> | void }) {
  const { t } = useI18n()
  const toast = useToast()
  const router = useRouter()

  function toastPosting(posting?: MatchPostingResult | { action: string; reason?: string } | null) {
    if (!posting) return
    if (posting.action === 'posted') toast.success(t('bank.posting.matched_and_posted'))
    else if (posting.action === 'suggested') toast.info(t('bank.posting.matched_suggested'))
    else if (posting.reason) {
      const key = POSTING_REASON_KEYS[posting.reason]
      toast.info(t('bank.posting.matched_not_posted', { reason: key ? t(key) : posting.reason }))
    }
  }

  // --- match v2 (párovací návrhy dle skóre) — mapu plní stránka (per-statement, nebo
  // batch přes víc výpisů u „Všechny pohyby"), tady jen držíme a čteme. ---
  const matchSuggestions = ref<Map<number, MatchSuggestion>>(new Map())
  const expandedSuggestions = ref<Set<number>>(new Set())
  function setSuggestions(map: Map<number, MatchSuggestion>) {
    matchSuggestions.value = map
    expandedSuggestions.value = new Set([...expandedSuggestions.value].filter(id => map.has(id)))
  }
  function suggestionFor(txId: number): MatchSuggestion | undefined {
    return matchSuggestions.value.get(txId)
  }
  function toggleSuggestion(txId: number) {
    const next = new Set(expandedSuggestions.value)
    if (next.has(txId)) next.delete(txId)
    else next.add(txId)
    expandedSuggestions.value = next
  }

  const expandedDocs = ref<Set<number>>(new Set())
  function toggleDocs(txId: number) {
    const next = new Set(expandedDocs.value)
    if (next.has(txId)) next.delete(txId)
    else next.add(txId)
    expandedDocs.value = next
  }

  const reviewingSuggestion = ref<number | null>(null)
  const matchError = ref('')

  async function acceptSuggestion(suggestion: MatchSuggestion, candidate: number) {
    if (reviewingSuggestion.value !== null) return
    reviewingSuggestion.value = suggestion.id
    matchError.value = ''
    try {
      const result = await bankApi.acceptMatchSuggestion(suggestion.id, candidate)
      matchingTx.value = null
      toast.success(t('bank.match_v2.accepted'))
      toastPosting(result.posting)
      await opts.reload()
    } catch (e) {
      const message = apiErrorMessage(e, t('bank.match_failed'))
      matchError.value = message
      toast.error(message)
    } finally {
      reviewingSuggestion.value = null
    }
  }

  async function rejectSuggestion(suggestion: MatchSuggestion) {
    if (reviewingSuggestion.value !== null) return
    reviewingSuggestion.value = suggestion.id
    matchError.value = ''
    try {
      await bankApi.rejectMatchSuggestion(suggestion.id)
      matchingTx.value = null
      toast.success(t('bank.match_v2.rejected'))
      await opts.reload()
    } catch (e) {
      const message = apiErrorMessage(e)
      matchError.value = message
      toast.error(message)
    } finally {
      reviewingSuggestion.value = null
    }
  }

  async function acceptTxSuggestion(txId: number, candidate: number) {
    const suggestion = suggestionFor(txId)
    if (suggestion) await acceptSuggestion(suggestion, candidate)
  }

  async function rejectTxSuggestion(txId: number) {
    const suggestion = suggestionFor(txId)
    if (suggestion) await rejectSuggestion(suggestion)
  }

  // --- manuální párování (modal): kandidáti dle částky + sloučená úhrada + ruční VS ---
  const matchingTx = ref<number | null>(null)
  const matchCtx = ref<BankTransaction | null>(null)
  const matchVarsymbol = ref('')
  const matchCandidates = ref<MatchCandidate[]>([])
  const loadingCandidates = ref(false)
  const gopayCandidate = ref<GoPayPayoutCandidate | null>(null)
  const loadingGoPayCandidate = ref(false)
  const matchingGoPay = ref(false)
  const candidatesFallback = ref(false)
  const splitSuggestions = ref<SplitSuggestion[]>([])
  const loadingSplit = ref(false)
  const splitWindow = ref(7)
  const anchorInvoiceId = ref<number | null>(null)
  const anchorOptions = ref<AnchorOption[]>([])
  const anchorSelected = ref<AnchorOption | null>(null)
  const anchorLoading = ref(false)
  let anchorSearchTimer: ReturnType<typeof setTimeout> | null = null

  const currentSuggestion = computed(() => matchingTx.value !== null
    ? suggestionFor(matchingTx.value)
    : undefined)

  function startMatch(tx: BankTransaction) {
    matchingTx.value = tx.id
    matchCtx.value = tx
    matchVarsymbol.value = tx.variable_symbol || ''
    matchError.value = ''
    matchCandidates.value = []
    gopayCandidate.value = null
    loadingGoPayCandidate.value = tx.amount > 0 && tx.source !== 'idoklad'
    if (loadingGoPayCandidate.value) {
      gopayApi.payoutCandidate(tx.id)
        .then(candidate => {
          if (matchingTx.value === tx.id) gopayCandidate.value = candidate
        })
        .catch(() => {})
        .finally(() => {
          if (matchingTx.value === tx.id) loadingGoPayCandidate.value = false
        })
    }
    candidatesFallback.value = false
    loadingCandidates.value = true
    bankApi.matchCandidates(tx.id)
      .then(r => {
        if (matchingTx.value !== tx.id) return
        matchCandidates.value = r.candidates
        candidatesFallback.value = r.fallback
      })
      .catch(() => {})
      .finally(() => { loadingCandidates.value = false })
    splitSuggestions.value = []
    splitWindow.value = 7
    anchorInvoiceId.value = null
    anchorOptions.value = []
    anchorSelected.value = null
    if (tx.amount > 0) loadSplitSuggestions(tx, 7)
  }

  function loadSplitSuggestions(tx: BankTransaction, window: number, anchorId?: number | null) {
    loadingSplit.value = true
    bankApi.splitSuggestions(tx.id, { window, invoiceId: anchorId ?? undefined })
      .then(r => {
        if (matchingTx.value === tx.id) {
          splitSuggestions.value = r.suggestions
          splitWindow.value = r.window
        }
      })
      .catch(() => {})
      .finally(() => { loadingSplit.value = false })
  }

  /**
   * Rozšíří okno o týden; po vyčerpání horní meze (60 dní) přepne na hledání BEZ datového
   * omezení (`window = 0`). Sloučená úhrada starých pohledávek — vymožená platba přijde
   * klidně rok po splatnosti — se do žádného rozumného okna nevejde (issue #31).
   */
  function widenSplitWindow() {
    if (!matchCtx.value) return
    const next = splitWindow.value >= 60 ? 0 : Math.min(60, splitWindow.value + 7)
    loadSplitSuggestions(matchCtx.value, next, anchorInvoiceId.value)
  }

  function onAnchorSearch(q: string) {
    if (anchorSearchTimer) clearTimeout(anchorSearchTimer)
    const query = q.trim()
    if (query.length < 2) { anchorOptions.value = []; return }
    anchorLoading.value = true
    anchorSearchTimer = setTimeout(() => {
      invoicesApi.searchMatchable(query, 20)
        .then(list => {
          anchorOptions.value = list.map(i => {
            const owed = i.amount_to_pay - (i.paid_total ?? 0)
            const shown = owed > 0 ? owed : i.amount_to_pay
            return {
              value: i.id,
              label: `${i.varsymbol || '#' + i.id} — ${i.client_company_name}`,
              secondary: `${formatMoney(shown, i.currency)} · ${formatDate(i.due_date || i.issue_date)}`,
            }
          })
        })
        .catch(() => { anchorOptions.value = [] })
        .finally(() => { anchorLoading.value = false })
    }, 220)
  }

  function onAnchorSelect(id: number | null) {
    anchorInvoiceId.value = id
    anchorSelected.value = id !== null
      ? (anchorOptions.value.find(o => o.value === id) ?? anchorSelected.value)
      : null
    if (!matchCtx.value) return
    loadSplitSuggestions(matchCtx.value, splitWindow.value, id)
  }

  function closeMatch() { matchingTx.value = null }

  async function confirmGoPayCandidate() {
    if (!matchingTx.value || !gopayCandidate.value || matchingGoPay.value) return
    matchingGoPay.value = true
    matchError.value = ''
    try {
      const result = await gopayApi.associatePayout(gopayCandidate.value.id, matchingTx.value)
      matchingTx.value = null
      toast.success(result.payout_issue_code === 'email_notice_provisional'
        ? t('bank.gopay_match.notice_matched')
        : t('bank.gopay_match.statement_matched'))
      await opts.reload()
    } catch (e) {
      matchError.value = apiErrorMessage(e, t('bank.gopay_match.failed'))
    } finally {
      matchingGoPay.value = false
    }
  }

  async function confirmSuggestion(s: SplitSuggestion) {
    if (!matchingTx.value) return
    matchError.value = ''
    try {
      const r = await bankApi.matchMultiple(matchingTx.value, s.invoices.map(i => i.id))
      matchingTx.value = null
      toastPosting(r.posting)
      await opts.reload()
    } catch (e: any) {
      matchError.value = apiErrorMessage(e, t('bank.match_failed'))
    }
  }

  async function confirmCandidate(c: MatchCandidate) {
    if (!matchingTx.value) return
    matchError.value = ''
    try {
      const r = await bankApi.matchManual(matchingTx.value,
        c.type === 'invoice' ? { invoiceId: c.id } : { purchaseInvoiceId: c.id })
      matchingTx.value = null
      toastPosting(r.posting)
      await opts.reload()
    } catch (e: any) {
      matchError.value = apiErrorMessage(e, t('bank.match_failed'))
    }
  }

  async function confirmMatch() {
    if (!matchingTx.value || !matchVarsymbol.value.trim()) return
    matchError.value = ''
    try {
      const r = await bankApi.matchManual(matchingTx.value, { varsymbol: matchVarsymbol.value.trim() })
      matchingTx.value = null
      toastPosting(r.posting)
      await opts.reload()
    } catch (e: any) {
      matchError.value = apiErrorMessage(e, t('bank.match_failed'))
    }
  }

  // --- vytvoření konceptu přijaté faktury z odchozí (záporné) platby ---
  const createTx = ref<BankTransaction | null>(null)
  const createVendorId = ref<number | null>(null)
  const vendorModalOpen = ref(false)
  const creatingPi = ref(false)

  function openCreate(tx: BankTransaction) {
    createTx.value = tx
    createVendorId.value = null
  }
  function closeCreate() { createTx.value = null }
  /** Template ref na VendorPicker (reload po vytvoření vendora) zůstává lokální
   *  BankCreatePurchaseModal.vue — sem patří jen business logika. */
  function onVendorCreated(client: Client) {
    vendorModalOpen.value = false
    createVendorId.value = client.id
  }
  async function submitCreatePurchase() {
    if (!createTx.value || !createVendorId.value || creatingPi.value) return
    creatingPi.value = true
    try {
      const r = await bankApi.createPurchaseInvoice(createTx.value.id, createVendorId.value)
      createTx.value = null
      router.push(`/purchase-invoices/${r.purchase_invoice_id}`)
    } catch (e) {
      toast.error(apiErrorMessage(e))
    } finally {
      creatingPi.value = false
    }
  }

  // --- vyžádání chybějícího dokladu od klienta (Fáze F, audit 2026-07) ---
  const requestDocTx = ref<BankTransaction | null>(null)
  const requestDocDeadline = ref('')
  const requestingDoc = ref(false)
  function openRequestDoc(tx: BankTransaction) {
    requestDocTx.value = tx
    requestDocDeadline.value = ''
  }
  function closeRequestDoc() { requestDocTx.value = null }
  async function submitRequestDoc() {
    if (!requestDocTx.value || requestingDoc.value) return
    requestingDoc.value = true
    try {
      await documentRequestsApi.createFromBankTransaction(requestDocTx.value.id, {
        deadline: requestDocDeadline.value || undefined,
      })
      toast.success(t('bank.document_request.created'))
      requestDocTx.value = null
      await opts.reload()
    } catch (e) {
      toast.error(apiErrorMessage(e, t('bank.document_request.failed')))
    } finally {
      requestingDoc.value = false
    }
  }

  // --- ignorovat / rozpárovat ---
  async function ignoreTx(tx: BankTransaction) {
    if (!confirm(t('bank.ignore_confirm'))) return
    await bankApi.ignore(tx.id)
    await opts.reload()
  }

  async function unmatchTx(tx: BankTransaction) {
    if (!confirm(t('bank.unmatch_confirm'))) return
    try {
      await bankApi.unmatch(tx.id)
      await opts.reload()
    } catch (e: any) {
      toast.error(apiErrorMessage(e, t('bank.unmatch_failed')))
    }
  }

  useHotkey('escape', () => {
    if (matchingTx.value !== null) matchingTx.value = null
    if (createTx.value !== null && !vendorModalOpen.value) createTx.value = null
  })

  return {
    toastPosting,
    // match v2
    matchSuggestions, expandedSuggestions, setSuggestions, suggestionFor, toggleSuggestion,
    reviewingSuggestion, matchError, acceptTxSuggestion, rejectTxSuggestion,
    // docs
    expandedDocs, toggleDocs,
    // manuální match modal
    matchingTx, matchCtx, matchVarsymbol, matchCandidates, loadingCandidates, candidatesFallback,
    gopayCandidate, loadingGoPayCandidate, matchingGoPay,
    splitSuggestions, loadingSplit, splitWindow,
    anchorInvoiceId, anchorOptions, anchorSelected, anchorLoading,
    currentSuggestion,
    startMatch, widenSplitWindow, onAnchorSearch, onAnchorSelect,
    confirmSuggestion, confirmCandidate, confirmMatch, confirmGoPayCandidate, closeMatch,
    // vytvoření přijaté faktury
    createTx, createVendorId, vendorModalOpen, creatingPi,
    openCreate, onVendorCreated, submitCreatePurchase, closeCreate,
    // vyžádání dokladu
    requestDocTx, requestDocDeadline, requestingDoc, openRequestDoc, submitRequestDoc, closeRequestDoc,
    // ignorovat / rozpárovat
    ignoreTx, unmatchTx,
  }
}

export type BankTransactionActions = ReturnType<typeof useBankTransactionActions>

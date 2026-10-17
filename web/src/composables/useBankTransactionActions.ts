import { ref, computed, watch, onBeforeUnmount, nextTick } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useToast } from '@/composables/useToast'
import { useHotkey } from '@/composables/useHotkey'
import { useAuthStore } from '@/stores/auth'
import { useSupplierStore } from '@/stores/supplier'
import { formatMoney, formatDate } from '@/composables/useFormat'
import { apiErrorMessage } from '@/api/errors'
import { isBankNoticeReadOnly } from '@/utils/bankNotice'
import {
  bankApi,
  type BankTransaction,
  type MatchCandidate,
  type MatchSuggestion,
  type MatchSuggestionCandidate,
  type SplitSuggestion,
  type MatchPostingResult,
} from '@/api/bank'
import { invoicesApi } from '@/api/invoices'
import { purchaseInvoicesApi } from '@/api/purchaseInvoices'
import { documentRequestsApi } from '@/api/documentRequests'
import { gopayApi, type GoPayPayoutCandidate } from '@/api/gopay'
import type { Client } from '@/api/clients'

const POSTING_REASON_KEYS: Record<string, string> = {
  fx_not_supported: 'bank.posting.reason_fx_not_supported',
  document_not_posted: 'bank.posting.reason_document_not_posted',
  period_closed: 'bank.posting.err_period_closed',
  not_double_entry: 'bank.posting.reason_not_double_entry',
  already_paid_verify: 'bank.posting.reason_already_paid_verify',
  overpaid_verify: 'bank.posting.reason_overpaid_verify',
  ambiguous_supplier: 'bank.posting.reason_ambiguous_supplier',
}

export type BankDetailAction = 'match' | 'create' | 'request' | 'ignore' | 'unmatch'

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
export function useBankTransactionActions(opts: { reload: () => Promise<void> | void; refresh?: () => Promise<void> | void }) {
  const { t } = useI18n()
  const toast = useToast()
  const router = useRouter()
  const matchSupplier = useSupplierStore()
  const detailReturnTarget = ref<BankTransaction | null>(null)
  let disposed = false

  function canMatchBank(): boolean {
    const auth = useAuthStore()
    return auth.canWrite('bank.match') && (!auth.isClientRole || auth.canRead('bank'))
  }

  function canMatchDocument(type: MatchCandidate['type']): boolean {
    const auth = useAuthStore()
    return canMatchBank() && (!auth.isClientRole || (type === 'invoice'
      ? auth.canRead('invoices') && auth.canWrite('invoices.mark_paid')
      : auth.canRead('purchase_invoices') && auth.canWrite('purchase_invoices.transition')))
  }

  function canMatchAnyDocument(): boolean {
    return canMatchDocument('invoice') || canMatchDocument('purchase_invoice')
  }

  function canAcceptCandidate(candidate: MatchSuggestionCandidate): boolean {
    return canMatchDocument(candidate.type === 'purchase_invoice' ? 'purchase_invoice' : 'invoice')
  }

  function canReviewSuggestion(suggestion: MatchSuggestion): boolean {
    const auth = useAuthStore()
    return canMatchBank() && (!auth.isClientRole || suggestion.candidates.every(candidate =>
      auth.canRead(candidate.type === 'purchase_invoice' ? 'purchase_invoices' : 'invoices')))
  }

  function canMatchCurrentDocument(): boolean {
    return !!matchCtx.value && canMatchDocument(matchCtx.value.amount < 0 ? 'purchase_invoice' : 'invoice')
  }

  function isLegacySupersededNotice(tx: BankTransaction): boolean {
    return tx.match_status === 'ignored' && tx.ignore_origin === 'superseded' && ['email_notice', 'idoklad'].includes(tx.source ?? '')
  }

  function canRunDetailAction(tx: BankTransaction, action: BankDetailAction): boolean {
    if (isBankNoticeReadOnly(tx) || tx.resolution === 'payroll' || tx.posting?.payroll_matched) return false
    const auth = useAuthStore()
    const status = tx.match_status
    if (action === 'create') return !auth.isClientRole && auth.canWrite('purchase_invoices.create') && tx.amount < 0 && status === 'unmatched'
    if (action === 'request') return !auth.isClientRole && auth.canWrite('documents.requests') && ['unmatched', 'auto_partial'].includes(status)
    if (!auth.isClientRole) {
      if (action === 'ignore') return auth.canWrite('bank.ignore') && status === 'unmatched'
      if (action === 'unmatch' && isLegacySupersededNotice(tx)) return auth.canWrite('bank.ignore') && tx.confirmation_record_exists === false
      if (!auth.canWrite('bank.match')) return false
      if (action === 'match') return ['unmatched', 'auto_partial'].includes(status)
      return ['auto_exact', 'auto_partial', 'manual', 'ignored'].includes(status)
    }
    if (!auth.canRead('bank')) return false
    if (action === 'ignore') return tx.ignore_available !== false && auth.canWrite('bank.ignore') && status === 'unmatched'
    if (action === 'unmatch' && status === 'ignored') return tx.ignore_available !== false && auth.canWrite('bank.ignore') && tx.ignore_origin === 'manual'
    if (!auth.canWrite('bank.match')) return false
    if (action === 'match') return tx.match_available !== false && ['unmatched', 'auto_partial'].includes(status)
      && canMatchAnyDocument()
    if (auth.isClientRole) {
      const invoices = !!tx.matched_invoice_id || !!tx.matched_invoices?.length
      const purchases = !!tx.matched_purchase_invoice_id || !!tx.matched_purchase_invoices?.length
      if ((!invoices && !purchases) || (invoices && !canMatchDocument('invoice')) || (purchases && !canMatchDocument('purchase_invoice'))) return false
    }
    return ['auto_exact', 'auto_partial', 'manual'].includes(status)
  }

  async function runDetailAction(action: BankDetailAction) {
    const tx = textDetail.value
    if (!tx || !canRunDetailAction(tx, action)) return
    const supplierId = matchSupplier.currentSupplierId
    detailReturnTarget.value = tx
    textDetail.value = null
    // Odmontovat detail před otevřením dalšího dialogu (scroll lock a Escape).
    await nextTick()
    if (disposed || supplierId !== matchSupplier.currentSupplierId) return
    const open = { match: startMatch, create: openCreate, request: openRequestDoc, ignore: ignoreTx, unmatch: unmatchTx }
    open[action](tx)
  }

  async function restoreDetail() {
    const tx = detailReturnTarget.value
    const supplierId = matchSupplier.currentSupplierId
    detailReturnTarget.value = null
    if (!tx) return
    await nextTick()
    if (!disposed && supplierId === matchSupplier.currentSupplierId) textDetail.value = tx
  }

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
    const selected = suggestion.candidates[candidate]
    if (matchBusy.value || !selected || !canReviewSuggestion(suggestion) || !canAcceptCandidate(selected)) return
    reviewingSuggestion.value = suggestion.id
    matchError.value = ''
    try {
      const result = await bankApi.acceptMatchSuggestion(suggestion.id, candidate)
      detailReturnTarget.value = null
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
    if (matchBusy.value || !canReviewSuggestion(suggestion)) return
    reviewingSuggestion.value = suggestion.id
    matchError.value = ''
    try {
      await bankApi.rejectMatchSuggestion(suggestion.id)
      detailReturnTarget.value = null
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
  const matchSearch = ref('')
  const matchCandidates = ref<MatchCandidate[]>([])
  const loadingCandidates = ref(false)
  const matchingCandidate = ref(false)
  let candidateSearchTimer: ReturnType<typeof setTimeout> | null = null
  let candidateLoadVersion = 0
  const gopayCandidate = ref<GoPayPayoutCandidate | null>(null)
  const loadingGoPayCandidate = ref(false)
  const matchingGoPay = ref(false)
  const candidatesFallback = ref(false)
  const splitSuggestions = ref<SplitSuggestion[]>([])
  const loadingSplit = ref(false)
  let splitLoadVersion = 0
  const splitWindow = ref(7)
  const anchorInvoiceId = ref<number | null>(null)
  const anchorOptions = ref<AnchorOption[]>([])
  const anchorSelected = ref<AnchorOption | null>(null)
  const anchorLoading = ref(false)
  let anchorSearchTimer: ReturnType<typeof setTimeout> | null = null
  let anchorSearchVersion = 0

  const currentSuggestion = computed(() => matchingTx.value !== null
    ? suggestionFor(matchingTx.value)
    : undefined)

  async function loadMatchCandidates(txId: number) {
    if (candidateSearchTimer) clearTimeout(candidateSearchTimer)
    candidateSearchTimer = null
    const version = ++candidateLoadVersion
    loadingCandidates.value = true
    try {
      const search = matchSearch.value.trim()
      const result = await (search ? bankApi.matchCandidates(txId, search) : bankApi.matchCandidates(txId))
      if (version !== candidateLoadVersion || matchingTx.value !== txId) return
      matchCandidates.value = result.candidates
      candidatesFallback.value = result.fallback
    } catch (error) {
      if (version === candidateLoadVersion && matchingTx.value === txId) matchError.value = apiErrorMessage(error, t('bank.match_failed'))
    } finally {
      if (version === candidateLoadVersion) loadingCandidates.value = false
    }
  }

  watch(matchSearch, () => {
    if (candidateSearchTimer) clearTimeout(candidateSearchTimer)
    candidateLoadVersion++
    matchCandidates.value = []
    candidatesFallback.value = false
    if (!matchingTx.value) return
    loadingCandidates.value = true
    candidateSearchTimer = setTimeout(() => {
      if (matchingTx.value) void loadMatchCandidates(matchingTx.value)
    }, 300)
  }, { flush: 'sync' })
  watch(() => matchSupplier.currentSupplierId, () => {
    detailReturnTarget.value = null
    textDetail.value = null
    dismissMatch()
    purchaseShortfall.value = null
  })
  onBeforeUnmount(() => {
    disposed = true
    detailReturnTarget.value = null
    candidateLoadVersion++
    if (candidateSearchTimer) clearTimeout(candidateSearchTimer)
  })

  function startMatch(tx: BankTransaction) {
    if (!canRunDetailAction(tx, 'match')) return
    if (anchorSearchTimer) clearTimeout(anchorSearchTimer)
    anchorSearchVersion++
    anchorLoading.value = false
    matchSearch.value = ''
    matchingTx.value = tx.id
    matchCtx.value = tx
    matchVarsymbol.value = tx.variable_symbol || ''
    matchError.value = ''
    matchCandidates.value = []
    gopayCandidate.value = null
    loadingGoPayCandidate.value = !useAuthStore().isClientRole && tx.amount > 0 && tx.source !== 'idoklad'
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
    void loadMatchCandidates(tx.id)
    splitSuggestions.value = []
    splitWindow.value = 7
    anchorInvoiceId.value = null
    anchorOptions.value = []
    anchorSelected.value = null
    if (tx.amount !== 0 && (!useAuthStore().isClientRole || useAuthStore().canRead(tx.amount > 0 ? 'invoices' : 'purchase_invoices'))) loadSplitSuggestions(tx, 7)
  }

  function loadSplitSuggestions(tx: BankTransaction, window: number, anchorId?: number | null) {
    const version = ++splitLoadVersion
    loadingSplit.value = true
    bankApi.splitSuggestions(tx.id, {
      window,
      invoiceId: tx.amount > 0 ? anchorId ?? undefined : undefined,
      purchaseInvoiceId: tx.amount < 0 ? anchorId ?? undefined : undefined,
    })
      .then(r => {
        if (matchingTx.value === tx.id && version === splitLoadVersion) {
          splitSuggestions.value = r.suggestions
          splitWindow.value = r.window
        }
      })
      .catch(e => {
        if (matchingTx.value === tx.id && version === splitLoadVersion) matchError.value = apiErrorMessage(e, t('bank.match_failed'))
      })
      .finally(() => { if (version === splitLoadVersion) loadingSplit.value = false })
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
    const version = ++anchorSearchVersion
    const tx = matchCtx.value
    const query = q.trim()
    if (query.length < 2 || !tx || (useAuthStore().isClientRole && !useAuthStore().canRead(tx.amount < 0 ? 'purchase_invoices' : 'invoices'))) { anchorOptions.value = []; anchorLoading.value = false; return }
    anchorLoading.value = true
    anchorSearchTimer = setTimeout(async () => {
      try {
        let options: AnchorOption[]
        if (tx.amount < 0) {
          const result = await purchaseInvoicesApi.listGrouped({
            q: query, status: ['received', 'booked', 'paid'], document_kind: ['invoice', 'advance'], per_page: 20,
          })
          options = result.data.flatMap(group => group.invoices).map(i => ({
            value: i.id,
            label: `${i.vendor_invoice_number || i.varsymbol || '#' + i.id} - ${i.vendor_company_name}`,
            secondary: `${formatMoney(i.amount_to_pay, i.currency)} · ${formatDate(i.due_date || i.issue_date)}`,
          }))
        } else {
          const list = await invoicesApi.searchMatchable(query, 20)
          options = list.map(i => {
            const owed = i.amount_to_pay - (i.paid_total ?? 0)
            const shown = owed > 0 ? owed : i.amount_to_pay
            return {
              value: i.id,
              label: `${i.varsymbol || '#' + i.id} - ${i.client_company_name}`,
              secondary: `${formatMoney(shown, i.currency)} · ${formatDate(i.due_date || i.issue_date)}`,
            }
          })
        }
        if (version === anchorSearchVersion && matchingTx.value === tx.id) anchorOptions.value = options
      } catch {
        if (version === anchorSearchVersion) anchorOptions.value = []
      } finally {
        if (version === anchorSearchVersion) anchorLoading.value = false
      }
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

  const matchingManual = ref(false)
  const matchBusy = computed(() => matchingManual.value || matchingCandidate.value || matchingGoPay.value || reviewingSuggestion.value !== null)

  function closeMatch() {
    if (matchBusy.value || matchingTx.value === null) return
    dismissMatch()
    void restoreDetail()
  }

  function dismissMatch() {
    matchingTx.value = null
    candidateLoadVersion++
    if (candidateSearchTimer) clearTimeout(candidateSearchTimer)
    candidateSearchTimer = null
    loadingCandidates.value = false
  }

  async function confirmGoPayCandidate() {
    if (!matchingTx.value || !gopayCandidate.value || matchBusy.value || useAuthStore().isClientRole) return
    matchingGoPay.value = true
    matchError.value = ''
    try {
      const result = await gopayApi.associatePayout(gopayCandidate.value.id, matchingTx.value)
      detailReturnTarget.value = null
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
    if (!matchingTx.value || matchBusy.value || !canMatchCurrentDocument()) return
    matchingManual.value = true
    matchError.value = ''
    try {
      const ids = s.invoices.map(i => i.id)
      const r = await (matchCtx.value && matchCtx.value.amount < 0
        ? bankApi.matchMultiplePurchases(matchingTx.value, ids)
        : bankApi.matchMultiple(matchingTx.value, ids))
      detailReturnTarget.value = null
      matchingTx.value = null
      toastPosting(r.posting)
      await opts.reload()
    } catch (e: any) {
      matchError.value = apiErrorMessage(e, t('bank.match_failed'))
    } finally {
      matchingManual.value = false
    }
  }

  /**
   * Ruční párování přijaté faktury s NIŽŠÍ platbou: doklad zůstal částečně uhrazený.
   * Účetní dostane volbu: nechat ho tak (a doplatit později), nebo rozdíl rovnou
   * vyrovnat zápočtem proti zvolenému účtu (321 MD / 648 nebo 663 D).
   */
  const purchaseShortfall = ref<{ purchaseInvoiceId: number; docNumber: string; remaining: number; currency: string } | null>(null)

  function notePurchaseShortfall(
    r: { partial_payment?: boolean; remaining?: number; currency?: string; purchase_invoice_id?: number },
    docNumber: string,
  ) {
    if (!r.partial_payment || !r.purchase_invoice_id || (r.remaining ?? 0) <= 0.005) return
    // Zápočet proti účtu je jen v podvojném účetnictví a jen s právem na účetnictví,
    // jinde zbývá oznámit, že doklad zůstal částečně uhrazený.
    const auth = useAuthStore()
    const canSettle = !auth.isClientRole && auth.hasCommercialFeatures && auth.canWrite('accounting')
      && useSupplierStore().currentSupplier?.accounting_mode === 'double_entry'
    if (!canSettle) {
      toast.info(t('bank.purchase_shortfall.partial_info', { amount: formatMoney(r.remaining ?? 0, r.currency || 'CZK') }))
      return
    }
    purchaseShortfall.value = {
      purchaseInvoiceId: r.purchase_invoice_id,
      docNumber,
      remaining: r.remaining ?? 0,
      currency: r.currency || 'CZK',
    }
  }

  function closePurchaseShortfall() { purchaseShortfall.value = null }

  async function onPurchaseShortfallSettled() {
    purchaseShortfall.value = null
    await opts.reload()
  }

  async function matchCandidate(txId: number, c: Pick<MatchCandidate, 'id' | 'type' | 'ref'>, fromDocument = false): Promise<boolean> {
    if (matchBusy.value || !canMatchDocument(c.type)) return false
    if (useAuthStore().isClientRole && fromDocument) return false
    matchingCandidate.value = true
    const supplierId = matchSupplier.currentSupplierId
    matchError.value = ''
    try {
      const reference: { invoiceId?: number; purchaseInvoiceId?: number; forceAdvanceFinal?: boolean } =
        c.type === 'invoice' ? { invoiceId: c.id } : { purchaseInvoiceId: c.id }
      const send = () => (fromDocument ? bankApi.matchDocument(txId, reference) : bankApi.matchManual(txId, reference))
      let r: Awaited<ReturnType<typeof send>>
      try {
        r = await send()
      } catch (e: any) {
        // Platba neuhrazené zálohy na konečnou fakturu: server ji odmítne a radí zálohu.
        // Jen vědomé potvrzení (opravdu doplatek faktury) párování provede.
        const err = e?.response?.data?.error
        if (err?.code !== 'advance_payment_belongs_to_advance'
          || !confirm(`${err.message}\n\n${t('bank.advance_final_force_confirm')}`)) throw e
        reference.forceAdvanceFinal = true
        r = await send()
      }
      if (supplierId !== matchSupplier.currentSupplierId) return true
      if (matchingTx.value === txId) { detailReturnTarget.value = null; dismissMatch() }
      toastPosting(r.posting)
      notePurchaseShortfall(r, c.ref || `#${c.id}`)
      await opts.reload()
      return true
    } catch (e: any) {
      if (supplierId === matchSupplier.currentSupplierId) matchError.value = apiErrorMessage(e, t('bank.match_failed'))
      return false
    } finally {
      matchingCandidate.value = false
    }
  }

  async function confirmCandidate(c: MatchCandidate): Promise<boolean> {
    return matchingTx.value !== null && matchCandidate(matchingTx.value, c)
  }

  function matchDocument(txId: number, document: Pick<MatchCandidate, 'id' | 'type' | 'ref'>): Promise<boolean> {
    return matchCandidate(txId, document, true)
  }

  async function confirmMatch() {
    if (!matchingTx.value || !matchVarsymbol.value.trim() || matchBusy.value || !canMatchAnyDocument()) return
    matchingManual.value = true
    matchError.value = ''
    try {
      const vs = matchVarsymbol.value.trim()
      const r = await bankApi.matchManual(matchingTx.value, { varsymbol: vs })
      detailReturnTarget.value = null
      matchingTx.value = null
      toastPosting(r.posting)
      notePurchaseShortfall(r, vs)
      await opts.reload()
    } catch (e: any) {
      matchError.value = apiErrorMessage(e, t('bank.match_failed'))
    } finally {
      matchingManual.value = false
    }
  }

  // --- vytvoření konceptu přijaté faktury z odchozí (záporné) platby ---
  const createTx = ref<BankTransaction | null>(null)
  const createVendorId = ref<number | null>(null)
  const vendorModalOpen = ref(false)
  const creatingPi = ref(false)

  function openCreate(tx: BankTransaction) {
    if (isBankNoticeReadOnly(tx)) return
    createTx.value = tx
    createVendorId.value = null
  }
  function closeCreate() {
    if (creatingPi.value || vendorModalOpen.value || !createTx.value) return
    createTx.value = null
    void restoreDetail()
  }
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
      detailReturnTarget.value = null
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
    if (isBankNoticeReadOnly(tx)) return
    requestDocTx.value = tx
    requestDocDeadline.value = ''
  }
  function closeRequestDoc() {
    if (requestingDoc.value || !requestDocTx.value) return
    requestDocTx.value = null
    void restoreDetail()
  }
  async function submitRequestDoc() {
    if (!requestDocTx.value || requestingDoc.value) return
    requestingDoc.value = true
    try {
      await documentRequestsApi.createFromBankTransaction(requestDocTx.value.id, {
        deadline: requestDocDeadline.value || undefined,
      })
      toast.success(t('bank.document_request.created'))
      detailReturnTarget.value = null
      requestDocTx.value = null
      await opts.reload()
    } catch (e) {
      toast.error(apiErrorMessage(e, t('bank.document_request.failed')))
    } finally {
      requestingDoc.value = false
    }
  }

  // --- ignorovat / rozpárovat ---
  const textDetail = ref<BankTransaction | null>(null)
  async function refreshAfterMutation() {
    try {
      await (opts.refresh ?? opts.reload)()
    } catch (e) {
      toast.error(apiErrorMessage(e))
    }
  }

  const ignoreTarget = ref<BankTransaction | null>(null)
  const ignoreNote = ref('')
  const ignoring = ref(false)
  const ignoreError = ref('')

  function ignoreTx(tx: BankTransaction) {
    if (!canRunDetailAction(tx, 'ignore')) return
    ignoreTarget.value = tx
    ignoreNote.value = tx.ignore_note ?? ''
    ignoreError.value = ''
  }

  function closeIgnore() {
    if (ignoring.value || !ignoreTarget.value) return
    ignoreTarget.value = null
    void restoreDetail()
  }

  async function confirmIgnore() {
    const tx = ignoreTarget.value
    if (!tx || ignoring.value || !canRunDetailAction(tx, 'ignore')) return
    ignoring.value = true
    ignoreError.value = ''
    try {
      const result = await bankApi.ignore(tx.id, ignoreNote.value.trim() || null)
      tx.match_status = 'ignored'
      tx.ignore_origin = 'manual'
      tx.ignore_note = result.ignore_note
      detailReturnTarget.value = null
      ignoreTarget.value = null
      await refreshAfterMutation()
    } catch (e) {
      ignoreError.value = apiErrorMessage(e, t('bank.ignore_failed'))
    } finally {
      ignoring.value = false
    }
  }

  const unmatchTarget = ref<BankTransaction | null>(null)
  const unmatching = ref(false)
  const unmatchError = ref('')

  function unmatchTx(tx: BankTransaction) {
    if (!canRunDetailAction(tx, 'unmatch')) return
    unmatchTarget.value = tx
    unmatchError.value = ''
  }

  function closeUnmatch() {
    if (unmatching.value || !unmatchTarget.value) return
    unmatchTarget.value = null
    void restoreDetail()
  }

  async function confirmUnmatch() {
    const tx = unmatchTarget.value
    if (!tx || unmatching.value || !canRunDetailAction(tx, 'unmatch')) return
    unmatching.value = true
    unmatchError.value = ''
    try {
      if (tx.match_status === 'ignored' && (useAuthStore().isClientRole || isLegacySupersededNotice(tx))) await bankApi.unignore(tx.id)
      else await bankApi.unmatch(tx.id)
      Object.assign(tx, {
        match_status: 'unmatched', ignore_origin: null, ignore_note: null, matched_invoice_id: null, matched_purchase_invoice_id: null,
        matched_varsymbol: null, matched_invoice_amount: null, matched_client_name: null,
        matched_purchase_ref: null, matched_vendor_name: null, matched_invoices: [], matched_at: null,
      })
      detailReturnTarget.value = null
      unmatchTarget.value = null
      await refreshAfterMutation()
    } catch (e) {
      unmatchError.value = apiErrorMessage(e, t('bank.unmatch_failed'))
    } finally {
      unmatching.value = false
    }
  }

  useHotkey('escape', () => {
    if (matchingTx.value !== null) closeMatch()
    if (createTx.value !== null && !vendorModalOpen.value) closeCreate()
  })

  return {
    toastPosting, canRunDetailAction, runDetailAction, matchBusy,
    canMatchDocument, canMatchAnyDocument, canAcceptCandidate, canReviewSuggestion, canMatchCurrentDocument,
    // match v2
    matchSuggestions, expandedSuggestions, setSuggestions, suggestionFor, toggleSuggestion,
    reviewingSuggestion, matchError, acceptTxSuggestion, rejectTxSuggestion,
    // docs
    expandedDocs, toggleDocs,
    // manuální match modal
    matchingTx, matchCtx, matchVarsymbol, matchSearch, matchCandidates, loadingCandidates, candidatesFallback, matchingCandidate,
    gopayCandidate, loadingGoPayCandidate, matchingGoPay,
    splitSuggestions, loadingSplit, splitWindow,
    anchorInvoiceId, anchorOptions, anchorSelected, anchorLoading,
    currentSuggestion,
    startMatch, widenSplitWindow, onAnchorSearch, onAnchorSelect,
    confirmSuggestion, confirmCandidate, confirmMatch, confirmGoPayCandidate, closeMatch, matchDocument,
    // nedoplatek přijaté faktury po ručním párování
    purchaseShortfall, closePurchaseShortfall, onPurchaseShortfallSettled,
    // vytvoření přijaté faktury
    createTx, createVendorId, vendorModalOpen, creatingPi,
    openCreate, onVendorCreated, submitCreatePurchase, closeCreate,
    // vyžádání dokladu
    requestDocTx, requestDocDeadline, requestingDoc, openRequestDoc, submitRequestDoc, closeRequestDoc,
    // ignorovat / rozpárovat
    textDetail, ignoreTx, ignoreTarget, ignoreNote, ignoring, ignoreError, closeIgnore, confirmIgnore,
    unmatchTx, unmatchTarget, unmatching, unmatchError, closeUnmatch, confirmUnmatch,
  }
}

export type BankTransactionActions = ReturnType<typeof useBankTransactionActions>

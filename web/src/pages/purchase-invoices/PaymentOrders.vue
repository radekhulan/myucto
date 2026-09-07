<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import {
  paymentOrdersApi,
  type PayerAccount,
  type PaymentCandidate,
  type PaymentOrderListItem,
  type PaymentAccountVerified,
  type PaymentOrderFormat,
} from '@/api/paymentOrders'
import {
  purchaseInvoicesApi,
  type PaymentAccountSource,
} from '@/api/purchaseInvoices'
import { formatMoney, formatDate } from '@/composables/useFormat'
import { bankNameByCode } from '@/utils/czBankCodes'
import { useToast } from '@/composables/useToast'
import { apiErrorCode, apiErrorMessage } from '@/api/errors'
import TableSkeleton from '@/components/ui/TableSkeleton.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { ICONS, OUTLINE, btnFilled, btnOutline } from '@/components/ui/buttonStyles'
import BulkActionBar from '@/components/ui/BulkActionBar.vue'
import JournalSourceDrawer from '@/components/accounting/JournalSourceDrawer.vue'
import { appIsoDate } from '@/utils/date'
import DateInput from '@/components/ui/DateInput.vue'
import BankPaymentSubmission from '@/components/bank/BankPaymentSubmission.vue'

const { t } = useI18n()
const auth = useAuthStore()
const route = useRoute()
const router = useRouter()
const toast = useToast()

const payerAccounts = ref<PayerAccount[]>([])
const candidates = ref<PaymentCandidate[]>([])
const selectedIds = ref<number[]>([])
const selectedPayerId = ref<number | ''>('')

const loading = ref(true)
const error = ref('')
const creating = ref(false)
const deletingOrderId = ref<number | null>(null)

async function deleteOrder(item: PaymentOrderListItem) {
  if (!auth.canWrite('purchase_invoices.payment_orders') || deletingOrderId.value !== null) return
  if (!window.confirm(t('payment_order.delete_confirm', { id: item.id }))) return
  deletingOrderId.value = item.id
  try {
    let archived = false
    try {
      await paymentOrdersApi.delete(item.id)
    } catch (e) {
      if (apiErrorCode(e) !== 'payment_order_delete_protected') throw e
      if (!window.confirm(t('bank_connection.archive_cancelled_confirm', { id: item.id }))) return
      await paymentOrdersApi.archiveAfterBankCancellation(item.id)
      archived = true
    }
    history.value = history.value.filter(order => order.id !== item.id)
    if (bankOrderId.value === item.id) bankOrderId.value = null
    toast.success(t(archived ? 'bank_connection.archived_cancelled' : 'payment_order.deleted'))
    await loadHistory()
  } catch (e) {
    toast.error(apiErrorMessage(e, t('payment_order.delete_failed')))
  } finally {
    deletingOrderId.value = null
  }
}

// Stránkování kandidátů (load-more).
const candPage = ref(1)
const candPages = ref(1)
const candTotal = ref(0)
const loadingMoreCandidates = ref(false)

// Ovládací pole
const today = appIsoDate()
const paymentDate = ref(today)
const constantSymbol = ref('')
const note = ref('')
const markPaid = ref(false)

// Historie
const history = ref<PaymentOrderListItem[]>([])
const bankOrderId = ref<number | null>(null)
const historyLoading = ref(false)
const historyLoadingMore = ref(false)
const historyPage = ref(1)
const historyPages = ref(1)
const editingAccountId = ref<number | null>(null)

// Předvybrané ID z InvoiceList.vue (?preselect=1,2,3)
const preselect = (() => {
  const raw = route.query.preselect
  if (typeof raw !== 'string' || raw === '') return []
  return raw.split(',').map(Number).filter(n => Number.isFinite(n))
})()

const selectedPayer = computed<PayerAccount | null>(() =>
  payerAccounts.value.find(a => a.id === selectedPayerId.value) ?? null,
)

/** Měna aktuálně zvoleného plátcovského účtu (filtruje kandidáty). */
const payerCurrency = computed(() => selectedPayer.value?.code ?? '')

const isCzk = computed(() => payerCurrency.value.toUpperCase() === 'CZK')
/** SEPA Credit Transfer (pain.001.001.03) je EUR-only scheme — jiné cizí měny jen CSV/PDF. */
const isEur = computed(() => payerCurrency.value.toUpperCase() === 'EUR')

onMounted(async () => {
  await loadCandidates()
  loadHistory()
})

function pickDefaultPayer(accounts: PayerAccount[]): number | '' {
  if (accounts.length === 0) return ''
  // Default = is_default + CZK; fallback první aktivní; pak první.
  const czkDefault = accounts.find(a => a.is_default && a.code.toUpperCase() === 'CZK')
  if (czkDefault) return czkDefault.id
  const anyDefault = accounts.find(a => a.is_default)
  if (anyDefault) return anyDefault.id
  const firstActive = accounts.find(a => a.is_active)
  return (firstActive ?? accounts[0]).id
}

async function loadCandidates(reset = true) {
  if (reset) {
    loading.value = true
    candPage.value = 1
  } else {
    loadingMoreCandidates.value = true
    candPage.value++
  }
  error.value = ''
  try {
    // Načteme nezaplacené faktury napříč měnami (stránkovaně) — uživatel vidí CZK
    // i ostatní a vybírat lze jen ty ve měně zvoleného účtu plátce (ostatní jsou disabled).
    const res = await paymentOrdersApi.candidates(undefined, candPage.value, 50, includeNonTransfer.value)
    candidates.value = reset ? res.data : [...candidates.value, ...res.data]
    candTotal.value = res.meta.total
    candPages.value = res.meta.pages
    if (payerAccounts.value.length === 0) {
      payerAccounts.value = res.payer_accounts
      selectedPayerId.value = pickDefaultPayer(res.payer_accounts)
      applyPreselect()
    }
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    loading.value = false
    loadingMoreCandidates.value = false
  }
}

function applyPreselect() {
  if (preselect.length === 0) return
  const eligible = new Set(candidates.value.filter(isSelectable).map(c => c.id))
  selectedIds.value = preselect.filter(id => eligible.has(id))
}

function onPayerChange() {
  // Po změně účtu plátce zruš výběr faktur, které neodpovídají nové měně účtu.
  selectedIds.value = selectedIds.value.filter(id => {
    const c = candidates.value.find(x => x.id === id)
    return c ? isSelectable(c) : false
  })
}

async function loadHistory(reset = true) {
  if (reset) {
    historyLoading.value = true
    historyPage.value = 1
  } else {
    historyLoadingMore.value = true
    historyPage.value++
  }
  try {
    const res = await paymentOrdersApi.list(historyPage.value)
    history.value = reset ? res.data : [...history.value, ...res.data]
    historyPages.value = res.meta.pages
  } catch {
    // historie není kritická — tichý fail
  } finally {
    historyLoading.value = false
    historyLoadingMore.value = false
  }
}

// ── Výběr ─────────────────────────────────────────────────────────────
const hideOrdered = ref(false)

/**
 * Opt-out ze serverového filtru „jen bankovní převod". Na rozdíl od `hideOrdered`
 * (klientský filtr) se tímhle mění DOTAZ, takže se kandidáti musí načíst znovu.
 * Smysl: inkasní faktury do příkazu nepatří, ale chybně označená faktura by jinak
 * z obrazovky zmizela beze stopy a nikdo by ji nezaplatil.
 */
const includeNonTransfer = ref(false)
watch(includeNonTransfer, () => { void loadCandidates(true) })

/** Měnově odpovídá zvolenému účtu plátce? (ABO/příkaz je jednoměnový.) */
function currencyMatches(c: PaymentCandidate): boolean {
  return !!payerCurrency.value && c.currency.toUpperCase() === payerCurrency.value.toUpperCase()
}

/**
 * Vyberatelný = má účet, sedí měna a platí se PŘEVODEM. Non-transfer faktury jsou
 * vidět jen kvůli opravě formy úhrady — zaškrtnout je nejde, a i kdyby výběr prošel,
 * backend je zahodí do `skipped` (PaymentOrderService::create).
 */
function isSelectable(c: PaymentCandidate): boolean {
  return c.has_account && currencyMatches(c) && c.payment_method === 'bank_transfer'
}

/** Kandidáti po aplikaci přepínače „skrýt už zařazené k úhradě". */
const visibleCandidates = computed(() =>
  hideOrdered.value ? candidates.value.filter(c => !c.payment_ordered_at) : candidates.value,
)

function toggleSelected(c: PaymentCandidate) {
  if (!isSelectable(c)) return
  const idx = selectedIds.value.indexOf(c.id)
  if (idx >= 0) selectedIds.value.splice(idx, 1)
  else selectedIds.value.push(c.id)
}

/** Kandidáti rozdělení do 2 tabulek: CZK (platba přes ABO) vs ostatní měny (CSV/PDF). */
const candidateGroups = computed(() => {
  const czk: PaymentCandidate[] = []
  const other: PaymentCandidate[] = []
  for (const c of visibleCandidates.value) {
    (c.currency.toUpperCase() === 'CZK' ? czk : other).push(c)
  }
  const groups: Array<{ key: string; via: 'abo' | 'other'; rows: PaymentCandidate[] }> = []
  if (czk.length) groups.push({ key: 'czk', via: 'abo', rows: czk })
  if (other.length) groups.push({ key: 'other', via: 'other', rows: other })
  return groups
})

function groupSelectableIds(rows: PaymentCandidate[]): number[] {
  return rows.filter(isSelectable).map(c => c.id)
}
function groupAllSelected(rows: PaymentCandidate[]): boolean {
  const ids = groupSelectableIds(rows)
  return ids.length > 0 && ids.every(id => selectedIds.value.includes(id))
}
function toggleGroup(rows: PaymentCandidate[]) {
  const ids = groupSelectableIds(rows)
  if (ids.length === 0) return
  if (ids.every(id => selectedIds.value.includes(id))) {
    selectedIds.value = selectedIds.value.filter(id => !ids.includes(id))
  } else {
    selectedIds.value = Array.from(new Set([...selectedIds.value, ...ids]))
  }
}

const selectedTotal = computed(() =>
  candidates.value
    .filter(c => selectedIds.value.includes(c.id))
    .reduce((sum, c) => sum + (c.amount_to_pay || 0), 0),
)

// Pro ABO: vybrané faktury, které nejsou abo_eligible (chybí CZ účet u CZK příkazu).
const selectedNotAboEligible = computed(() =>
  candidates.value.filter(c => selectedIds.value.includes(c.id) && isCzk.value && !c.abo_eligible),
)

// Pro SEPA: vybrané faktury, které nemají platný IBAN (u EUR příkazu).
const selectedNotSepaEligible = computed(() =>
  candidates.value.filter(c => selectedIds.value.includes(c.id) && isEur.value && !c.sepa_eligible),
)

// ── Badge helpery ─────────────────────────────────────────────────────
const sourceBadgeClass = (s: PaymentAccountSource | null): string => {
  if (!s) return 'bg-neutral-100 text-neutral-500'
  return {
    isdoc:        'bg-primary-50 text-primary-700',
    ai:           'bg-accent-50 text-accent-600',
    ai_reextract: 'bg-accent-50 text-accent-600',
    qr_image:     'bg-teal-50 text-teal-600',
    manual:       'bg-neutral-100 text-neutral-600',
  }[s]
}

const verifiedBadgeClass = (v: PaymentAccountVerified): string => ({
  verified:   'bg-success-50 text-success-600 border border-success-500/40',
  not_listed: 'bg-warning-50 text-warning-600 border border-warning-500/40',
  unreliable: 'bg-danger-50 text-danger-500 border border-danger-500/40',
  na:         '',
}[v])

function accountDisplay(c: { account_number: string | null; bank_code: string | null; iban: string | null }): string {
  if (c.account_number) return c.bank_code ? `${c.account_number}/${c.bank_code}` : c.account_number
  if (c.iban) return c.iban
  return ''
}

/** Název banky z číselníku dle kódu banky (CZ). */
function bankLabel(bankCode: string | null): string | null {
  return bankCode ? bankNameByCode(bankCode) : null
}

function payerDisplay(a: PayerAccount): string {
  const label = a.label || a.code
  const acc = accountDisplay(a)
  return acc ? `${label} — ${acc}` : label
}

// ── Inline editace účtu ───────────────────────────────────────────────
const editForm = ref<{ account_number: string; bank_code: string; iban: string; bic: string; variable_symbol: string }>({
  account_number: '', bank_code: '', iban: '', bic: '', variable_symbol: '',
})

function startEditAccount(c: PaymentCandidate) {
  if (!auth.canWrite('purchase_invoices.payment_orders')) return
  editingAccountId.value = c.id
  editForm.value = {
    account_number: c.account_number ?? '',
    bank_code: c.bank_code ?? '',
    iban: c.iban ?? '',
    bic: c.bic ?? '',
    variable_symbol: c.variable_symbol ?? '',
  }
}

function cancelEditAccount() {
  editingAccountId.value = null
}

const savingAccount = ref(false)
async function saveAccount(c: PaymentCandidate) {
  if (savingAccount.value) return
  savingAccount.value = true
  try {
    const res = await purchaseInvoicesApi.updatePaymentAccount(c.id, {
      account_number: editForm.value.account_number || null,
      bank_code: editForm.value.bank_code || null,
      iban: editForm.value.iban || null,
      bic: editForm.value.bic || null,
      variable_symbol: editForm.value.variable_symbol || null,
    })
    // Promítni změnu do řádku (refresh přes vrácený account + opětovné načtení kandidátů).
    c.account_number = res.account.account_number
    c.bank_code = res.account.bank_code
    c.iban = res.account.iban
    c.bic = res.account.bic
    c.variable_symbol = res.account.variable_symbol
    c.payment_account_source = res.source
    editingAccountId.value = null
    toast.success(t('payment_order.account_saved'))
    // Přenačti, aby has_account/abo_eligible/account_verified byly aktuální.
    await refreshCandidatesKeepSelection()
  } catch (e) {
    toast.error(apiErrorMessage(e))
  } finally {
    savingAccount.value = false
  }
}

async function refreshCandidatesKeepSelection() {
  const keep = [...selectedIds.value]
  try {
    // Reset na 1. stránku (stejné jako po libovolné jiné mutaci v seznamech s load-more).
    candPage.value = 1
    const res = await paymentOrdersApi.candidates(payerCurrency.value || undefined, 1, 50, includeNonTransfer.value)
    candidates.value = res.data
    candTotal.value = res.meta.total
    candPages.value = res.meta.pages
    const stillSelectable = new Set(res.data.filter(isSelectable).map(c => c.id))
    selectedIds.value = keep.filter(id => stillSelectable.has(id))
  } catch {
    // ponech stávající stav
  }
}

// ── Akce na řádku: PDF náhled / Detail / Ověření účtu ─────────────────
// Inline PDF náhled — rozbalí se v místě (stejně jako toggle „Zobrazit PDF" v detailu).
const pdfPreviewId = ref<number | null>(null)
function togglePdf(c: PaymentCandidate) {
  pdfPreviewId.value = pdfPreviewId.value === c.id ? null : c.id
}
const pdfPreviewUrlFor = (id: number) => purchaseInvoicesApi.pdfUrl(id, true) + '#view=FitH'

// Inline QR k platbě (z uloženého účtu příjemce).
const qrPreviewId = ref<number | null>(null)
const qrData = ref<string | null>(null)
const qrLoading = ref(false)
async function toggleQr(c: PaymentCandidate) {
  if (qrPreviewId.value === c.id) {
    qrPreviewId.value = null
    qrData.value = null
    return
  }
  qrPreviewId.value = c.id
  qrData.value = null
  qrLoading.value = true
  try {
    const r = await purchaseInvoicesApi.paymentQr(c.id)
    qrData.value = r.qr_data_uri
    if (!r.qr_data_uri) toast.info(t('payment_order.qr_unavailable'))
  } catch (e) {
    toast.error(apiErrorMessage(e))
    qrPreviewId.value = null
  } finally {
    qrLoading.value = false
  }
}

/**
 * Náhled dokladu v drawer — stejný jako v deníku, aby účetní při skládání
 * příkazu neztrácel rozpracovaný výběr odskokem na detail.
 *
 * Drawer je klíčovaný ID ZÁPISU, ne dokladu (viz JournalSourceAction: brání to
 * IDOR přes podstrčené source_id), takže bez zaúčtování ho otevřít nelze —
 * u nezaúčtovaných dokladů a v daňové evidenci zůstává původní odskok na detail.
 */
const sourceDrawerEntryId = ref<number | null>(null)

function openDetail(c: PaymentCandidate) {
  if (c.journal_entry_id) {
    sourceDrawerEntryId.value = c.journal_entry_id
    return
  }
  window.open(`/purchase-invoices/${c.id}`, '_blank')
}

/**
 * „Zobrazit v deníku" z draweru. Deník je jinde než tahle stránka, takže odskok
 * je tu na místě — na rozdíl od náhledu, kvůli kterému by se ztratil výběr.
 */
function focusInJournal(entryId: number) {
  sourceDrawerEntryId.value = null
  void router.push({ name: 'accounting-journal', query: { entry_id: String(entryId) } })
}

const verifyingId = ref<number | null>(null)
async function verifyAccountRow(c: PaymentCandidate) {
  if (verifyingId.value !== null) return
  verifyingId.value = c.id
  try {
    const res = await paymentOrdersApi.verifyAccount(c.id)
    c.account_verified = res.account_verified
    if (res.unreliable === true) {
      toast.error(t('payment_order.verify_unreliable'))
    } else if (res.account_verified === 'verified') {
      toast.success(t('payment_order.verify_ok'))
    } else if (res.account_verified === 'not_listed') {
      const list = res.accounts.length ? res.accounts.join(', ') : t('payment_order.verify_no_listed')
      toast.warning(t('payment_order.verify_not_listed', { list }))
    } else {
      toast.info(t('payment_order.verify_na'))
    }
  } catch (e) {
    toast.error(apiErrorMessage(e))
  } finally {
    verifyingId.value = null
  }
}

// ── Jen označit (bez exportu) ─────────────────────────────────────────
async function markOnly() {
  if (!auth.canWrite('purchase_invoices.payment_orders') || creating.value) return
  if (selectedIds.value.length === 0) {
    toast.error(t('payment_order.no_selection'))
    return
  }
  creating.value = true
  try {
    const res = await paymentOrdersApi.markOrdered({
      invoice_ids: selectedIds.value,
      mark_paid: markPaid.value || undefined,
    })
    toast.success(t('payment_order.marked_ordered', { n: res.count }))
    selectedIds.value = []
    await refreshCandidatesKeepSelection()
    await loadHistory()
  } catch (e) {
    toast.error(apiErrorMessage(e))
  } finally {
    creating.value = false
  }
}

// ── Vytvoření + stažení ───────────────────────────────────────────────
const reasonText = (reason: string): string => {
  const key = `payment_order.skip_reason.${reason}`
  const v = t(key)
  return v === key ? reason : v
}

async function createAndDownload(format: PaymentOrderFormat | 'bank') {
  if (!auth.canWrite('purchase_invoices.payment_orders') || creating.value) return
  if (format === 'bank' && (!auth.canWrite('settings.bank_accounts') || !isCzk.value)) return
  if (selectedIds.value.length === 0) {
    toast.error(t('payment_order.no_selection'))
    return
  }
  if (selectedPayerId.value === '') {
    toast.error(t('payment_order.no_payer'))
    return
  }
  if (format === 'abo' && !isCzk.value) {
    toast.error(t('payment_order.abo_only_czk'))
    return
  }
  if (format === 'sepa' && !selectedPayer.value?.iban) {
    toast.error(t('payment_order.no_payer_iban'))
    return
  }
  creating.value = true
  try {
    const res = await paymentOrdersApi.create({
      invoice_ids: selectedIds.value,
      payer_currency_id: selectedPayer.value!.id,
      payment_date: paymentDate.value,
      constant_symbol: constantSymbol.value || undefined,
      note: note.value || undefined,
      mark_paid: format === 'bank' ? false : markPaid.value || undefined,
    })

    if (res.clamped_date) {
      toast.warning(t('payment_order.date_clamped'))
    }
    if (res.skipped.length > 0) {
      const detail = res.skipped.map(s => `#${s.id}: ${reasonText(s.reason)}`).join(', ')
      toast.warning(t('payment_order.skipped_some', { n: res.skipped.length, detail }))
    }

    toast.success(t('payment_order.created', { n: res.view.item_count }))
    if (format !== 'bank') paymentOrdersApi.downloadPaymentOrder(res.order_id, format)

    // Reset výběru + přenačtení (uhrazené/zařazené faktury vypadnou) a historie.
    selectedIds.value = []
    await refreshCandidatesKeepSelection()
    await loadHistory()
    if (format === 'bank') bankOrderId.value = res.order_id
  } catch (e) {
    toast.error(apiErrorMessage(e))
  } finally {
    creating.value = false
  }
}

function redownload(item: PaymentOrderListItem, format: PaymentOrderFormat) {
  paymentOrdersApi.downloadPaymentOrder(item.id, format)
}

function payerAccountDisplay(item: PaymentOrderListItem): string {
  const label = item.payer_account_label || ''
  const acc = accountDisplay({
    account_number: item.payer_account_number,
    bank_code: item.payer_bank_code,
    iban: item.payer_iban,
  })
  if (label && acc) return `${label} — ${acc}`
  return label || acc || '—'
}
</script>

<template>
  <div>
    <!-- ═══ Topbar ═══ -->
    <div class="flex items-center justify-between mb-4 gap-3 flex-wrap">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('payment_order.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5">{{ t('payment_order.subtitle') }}</p>
      </div>
    </div>

    <!-- ═══ Ovládací box ═══ -->
    <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm mb-4 p-4 space-y-3">
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
        <div>
          <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('payment_order.payer_account') }}</label>
          <select v-model="selectedPayerId" @change="onPayerChange"
            class="w-full h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm disabled:opacity-50"
            :disabled="loading">
            <option v-if="payerAccounts.length === 0" :value="''">{{ t('payment_order.no_payer_accounts') }}</option>
            <option v-for="a in payerAccounts" :key="a.id" :value="a.id">{{ payerDisplay(a) }}</option>
          </select>
        </div>
        <div>
          <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('payment_order.payment_date') }}</label>
          <DateInput v-model="paymentDate"
            class="w-full h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm" />
        </div>
        <div>
          <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('payment_order.constant_symbol') }}</label>
          <input v-model="constantSymbol" type="text" inputmode="numeric" pattern="[0-9]*"
            :placeholder="t('payment_order.constant_symbol_placeholder')"
            class="w-full h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm" />
        </div>
        <div>
          <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('payment_order.note') }}</label>
          <input v-model="note" type="text"
            :placeholder="t('payment_order.note_placeholder')"
            class="w-full h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm" />
        </div>
      </div>

      <div class="flex items-center gap-4 flex-wrap">
        <label class="flex items-center gap-1.5 text-sm text-neutral-700">
          <input v-model="markPaid" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
          {{ t('payment_order.mark_paid') }}
        </label>
        <label class="flex items-center gap-1.5 text-sm text-neutral-700">
          <input v-model="hideOrdered" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
          {{ t('payment_order.hide_ordered') }}
        </label>
        <label class="flex items-center gap-1.5 text-sm text-neutral-700"
          :title="t('payment_method.include_non_transfer_hint')">
          <input v-model="includeNonTransfer" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
          {{ t('payment_method.include_non_transfer') }}
        </label>
      </div>

      <!-- Varování: vybrané faktury bez CZ účtu nejdou do ABO -->
      <div v-if="isCzk && selectedNotAboEligible.length > 0"
        class="rounded-md bg-warning-50 border border-warning-500/40 px-3 py-2 text-sm text-warning-700">
        {{ t('payment_order.warn_not_abo_eligible', { n: selectedNotAboEligible.length }) }}
      </div>
      <!-- Varování: vybrané faktury bez IBAN nejdou do SEPA -->
      <div v-if="isEur && selectedNotSepaEligible.length > 0"
        class="rounded-md bg-warning-50 border border-warning-500/40 px-3 py-2 text-sm text-warning-700">
        {{ t('payment_order.warn_not_sepa_eligible', { n: selectedNotSepaEligible.length }) }}
      </div>
      <div v-if="!auth.canWrite('purchase_invoices.payment_orders')"
        class="rounded-md bg-neutral-100 border border-neutral-200 px-3 py-2 text-sm text-neutral-500">
        {{ t('payment_order.readonly_hint') }}
      </div>
    </div>

    <!-- Hromadné akce nad vybranými fakturami v plovoucí liště u spodní hrany (BulkActionBar). -->
    <BulkActionBar :count="selectedIds.length" @clear="selectedIds = []">
      <span class="px-1.5 text-sm font-mono font-semibold text-neutral-900 whitespace-nowrap">
        {{ formatMoney(selectedTotal, payerCurrency || 'CZK') }}
      </span>
      <button v-if="auth.canWrite('purchase_invoices.payment_orders') && auth.canWrite('settings.bank_accounts') && isCzk" type="button" @click="createAndDownload('bank')"
        :disabled="creating || selectedIds.length === 0" :class="btnFilled('primary')">
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path :d="ICONS.plus" /></svg>
        {{ t('bank_connection.payment_prepare') }}
      </button>
      <button v-if="auth.canWrite('purchase_invoices.payment_orders')" type="button" @click="markOnly"
        :disabled="creating || selectedIds.length === 0"
        :title="t('payment_order.mark_only_hint')"
        :class="btnOutline('neutral')">
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.checkCircle" /></svg>
        {{ t('payment_order.mark_only') }}
      </button>
      <button v-if="auth.canWrite('purchase_invoices.payment_orders')" type="button" @click="createAndDownload('csv')"
        :disabled="creating || selectedIds.length === 0"
        :class="btnOutline('primary')">
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.download" /></svg>
        {{ t('payment_order.export_csv') }}
      </button>
      <button v-if="auth.canWrite('purchase_invoices.payment_orders')" type="button" @click="createAndDownload('pdf')"
        :disabled="creating || selectedIds.length === 0"
        :class="btnOutline('primary')">
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.download" /></svg>
        {{ t('payment_order.export_pdf') }}
      </button>
      <button v-if="auth.canWrite('purchase_invoices.payment_orders')" type="button" @click="createAndDownload('abo')"
        :disabled="creating || selectedIds.length === 0 || !isCzk"
        :title="!isCzk ? t('payment_order.abo_only_czk') : ''"
        :class="btnFilled('primary')">
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.download" /></svg>
        {{ creating ? '…' : t('payment_order.export_abo') }}
      </button>
      <button v-if="auth.canWrite('purchase_invoices.payment_orders') && isEur" type="button" @click="createAndDownload('sepa')"
        :disabled="creating || selectedIds.length === 0 || !selectedPayer?.iban"
        :title="!selectedPayer?.iban ? t('payment_order.no_payer_iban') : ''"
        :class="btnFilled('primary')">
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.download" /></svg>
        {{ creating ? '…' : t('payment_order.export_sepa') }}
      </button>
    </BulkActionBar>

    <!-- ═══ Kandidáti ═══ -->
    <div v-if="loading" class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
      <TableSkeleton :rows="6" :cols="6" />
    </div>

    <div v-else-if="error" class="bg-danger-50 border border-danger-500/40 text-danger-500 rounded-md p-3 text-sm">
      {{ error }}
    </div>

    <div v-else-if="!candidates.length" class="bg-surface border border-neutral-200 rounded-lg shadow-sm">
      <EmptyState :title="t('payment_order.empty')" />
    </div>

    <div v-else>
      <div v-if="candidateGroups.length === 0" class="bg-surface border border-neutral-200 rounded-lg shadow-sm">
        <EmptyState dense accent="neutral" :title="t('payment_order.empty')" />
      </div>
      <template v-for="grp in candidateGroups" :key="grp.key">
      <!-- Skupinová hlavička: CZK (platba přes ABO) vs ostatní měny (CSV/PDF) -->
      <div class="flex items-center gap-2 mb-2 mt-5 first:mt-0">
        <h3 class="text-sm font-semibold text-neutral-800">
          {{ grp.via === 'abo' ? t('payment_order.group_czk') : t('payment_order.group_other') }}
        </h3>
        <span class="text-[11px] px-2 py-0.5 rounded font-medium"
          :class="grp.via === 'abo' ? 'bg-primary-50 text-primary-700 border border-primary-500/30' : 'bg-warning-50 text-warning-700 border border-warning-500/30'">
          {{ grp.via === 'abo' ? t('payment_order.group_czk_via') : t('payment_order.group_other_via') }}
        </span>
        <span class="text-xs text-neutral-500">{{ t('payment_order.candidates_count', { n: grp.rows.length }) }}</span>
      </div>

      <!-- Desktop: tabulka -->
      <div class="hidden md:block bg-surface border rounded-lg overflow-hidden shadow-sm"
        :class="grp.via === 'abo' ? 'border-primary-500/30' : 'border-warning-500/30'">
        <div>
          <table class="w-full text-sm table-fixed">
            <colgroup>
              <col class="w-10" />
              <col class="w-56" />
              <col class="w-28" />
              <col class="w-80" />
              <col class="w-20" />
              <col class="w-32" />
              <col />
            </colgroup>
            <thead class="bg-neutral-50 text-neutral-500 text-xs uppercase tracking-wide">
              <tr>
                <th class="px-2 py-2 text-center">
                  <input type="checkbox" :checked="groupAllSelected(grp.rows)" @change="toggleGroup(grp.rows)"
                    :title="t('common.select_all')"
                    class="w-4 h-4 cursor-pointer rounded border-neutral-300 text-primary-600 focus:ring-2 focus:ring-primary-500/30" />
                </th>
                <th class="text-left px-4 py-2 font-medium">{{ t('payment_order.col_vendor') }}</th>
                <th class="text-center px-2 py-2 font-medium">{{ t('payment_order.col_due_date') }}</th>
                <th class="text-left px-4 py-2 font-medium">{{ t('payment_order.col_account') }}</th>
                <th class="text-left px-2 py-2 font-medium">{{ t('payment_order.col_vs') }}</th>
                <th class="text-right px-3 py-2 font-medium">{{ t('payment_order.col_amount') }}</th>
                <th class="text-center px-2 py-2 font-medium">{{ t('payment_order.col_actions') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <template v-for="c in grp.rows" :key="c.id">
                <tr class="transition" :class="!c.has_account ? 'bg-warning-50/50' : 'hover:bg-neutral-50'">
                  <td class="px-2 py-2.5 text-center align-top">
                    <input type="checkbox"
                      :checked="selectedIds.includes(c.id)"
                      :disabled="!isSelectable(c)"
                      @change="toggleSelected(c)"
                      :title="!c.has_account ? t('payment_order.no_account') : (!currencyMatches(c) ? t('payment_order.currency_mismatch') : (c.payment_method !== 'bank_transfer' ? t('payment_method.non_transfer_tooltip') : ''))"
                      class="w-5 h-5 mt-0.5 cursor-pointer rounded border-neutral-300 text-primary-600 focus:ring-2 focus:ring-primary-500/30 disabled:opacity-40 disabled:cursor-not-allowed" />
                  </td>
                  <td class="px-4 py-2.5 align-top">
                    <div class="font-medium text-neutral-900 truncate">{{ c.vendor_company_name }}</div>
                    <div class="text-xs text-neutral-500 font-mono truncate">{{ c.vendor_invoice_number }}</div>
                    <!-- Viditelné jen se zapnutým `includeNonTransfer` — jinak takové
                         faktury vůbec nechodí. Badge říká, PROČ nejde zaškrtnout. -->
                    <span v-if="c.payment_method !== 'bank_transfer'"
                      class="inline-flex items-center mt-1 text-[10px] px-1.5 py-0.5 rounded bg-amber-50 text-amber-700 border border-amber-500/30 whitespace-nowrap"
                      :title="t('payment_method.non_transfer_tooltip')">
                      {{ t(`payment_method.${c.payment_method}`) }}
                    </span>
                  </td>
                  <td class="px-3 py-2.5 text-center text-xs text-neutral-600 align-top">
                    <div>{{ formatDate(c.due_date) }}</div>
                    <span v-if="c.payment_ordered_at"
                      class="inline-flex items-center gap-0.5 mt-1 text-[10px] px-1.5 py-0.5 rounded bg-teal-50 text-teal-600 border border-teal-500/30 whitespace-nowrap"
                      :title="t('payment_order.ordered_badge_tooltip', { date: formatDate(c.payment_ordered_at) })">
                      <svg class="w-2.5 h-2.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                      {{ t('payment_order.ordered_badge') }}
                    </span>
                  </td>
                  <td class="px-4 py-2.5 text-xs align-top">
                    <!-- Bez účtu: jen tlačítko „Doplnit účet" (text „chybí" je redundantní). -->
                    <template v-if="!c.has_account">
                      <button v-if="auth.canWrite('purchase_invoices.payment_orders') && editingAccountId !== c.id" type="button"
                        @click="startEditAccount(c)"
                        class="cursor-pointer inline-flex items-center gap-1 h-7 px-2 text-[11px] font-medium rounded-md border border-primary-500 text-primary-700 hover:bg-primary-50 transition">
                        <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                        {{ t('payment_order.add_account') }}
                      </button>
                      <span v-else class="text-neutral-400">—</span>
                    </template>
                    <!-- S účtem: účet+banka na 1. řádku, badge + akce na 2. řádku (snug, 2 řádky). -->
                    <template v-else>
                      <div class="text-neutral-700 whitespace-nowrap overflow-hidden text-ellipsis">
                        <span class="font-mono">{{ accountDisplay(c) }}</span><span v-if="bankLabel(c.bank_code)" class="text-[10px] text-neutral-500 ml-1.5">{{ bankLabel(c.bank_code) }}</span>
                      </div>
                      <div class="flex items-center gap-1.5 flex-wrap mt-1">
                        <span v-if="c.payment_account_source" class="text-[10px] px-1.5 py-0.5 rounded" :class="sourceBadgeClass(c.payment_account_source)">
                          {{ t(`purchase_invoice.qr.source.${c.payment_account_source}`) }}
                        </span>
                        <span v-if="c.account_verified !== 'na'" class="text-[10px] px-1.5 py-0.5 rounded" :class="verifiedBadgeClass(c.account_verified)">
                          {{ t(`payment_order.verified.${c.account_verified}`) }}
                        </span>
                        <button v-if="c.can_verify" type="button" @click="verifyAccountRow(c)" :disabled="verifyingId === c.id"
                          :title="t('payment_order.verify_account')"
                          class="cursor-pointer inline-flex items-center gap-0.5 text-[10px] px-1.5 py-0.5 rounded border border-primary-300 text-primary-700 hover:bg-primary-50 disabled:opacity-50">
                          <svg class="w-2.5 h-2.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12c0 1.268-.63 2.39-1.593 3.068a3.745 3.745 0 0 1-1.043 3.296 3.745 3.745 0 0 1-3.296 1.043A3.745 3.745 0 0 1 12 21c-1.268 0-2.39-.63-3.068-1.593a3.746 3.746 0 0 1-3.296-1.043 3.745 3.745 0 0 1-1.043-3.296A3.745 3.745 0 0 1 3 12c0-1.268.63-2.39 1.593-3.068a3.745 3.745 0 0 1 1.043-3.296 3.746 3.746 0 0 1 3.296-1.043A3.746 3.746 0 0 1 12 3c1.268 0 2.39.63 3.068 1.593a3.746 3.746 0 0 1 3.296 1.043 3.746 3.746 0 0 1 1.043 3.296A3.745 3.745 0 0 1 21 12z"/></svg>
                          {{ verifyingId === c.id ? '…' : t('payment_order.verify_account_short') }}
                        </button>
                        <button v-if="auth.canWrite('purchase_invoices.payment_orders') && editingAccountId !== c.id" type="button"
                          @click="startEditAccount(c)"
                          :title="t('payment_order.edit_account')"
                          class="cursor-pointer inline-flex items-center justify-center w-6 h-6 rounded border border-neutral-300 text-neutral-600 hover:bg-neutral-100 transition">
                          <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931z"/></svg>
                        </button>
                      </div>
                      <div v-if="!currencyMatches(c)" class="text-[10px] text-neutral-500 mt-0.5">
                        {{ t('payment_order.currency_mismatch') }}
                      </div>
                      <div v-else-if="isCzk && !c.abo_eligible" class="text-[10px] text-warning-600 mt-0.5">
                        {{ t('payment_order.not_abo_eligible_row') }}
                      </div>
                      <div v-else-if="isEur && !c.sepa_eligible" class="text-[10px] text-warning-600 mt-0.5">
                        {{ t('payment_order.not_sepa_eligible_row') }}
                      </div>
                    </template>
                  </td>
                  <td class="px-2 py-2.5 font-mono text-xs text-neutral-600 align-top">{{ c.variable_symbol || c.varsymbol || '—' }}</td>
                  <td class="px-3 py-2.5 text-right font-mono align-top whitespace-nowrap">{{ formatMoney(c.amount_to_pay, c.currency) }}</td>
                  <td class="px-3 py-2.5 text-center align-top">
                    <div class="inline-flex items-center gap-1 flex-wrap justify-center">
                      <button v-if="c.has_account" type="button" @click="toggleQr(c)" :title="t('payment_order.qr_code')"
                        class="cursor-pointer inline-flex items-center gap-1 text-[11px] px-2 py-1 rounded border font-medium"
                        :class="qrPreviewId === c.id ? 'bg-neutral-800 text-neutral-50 border-neutral-800' : 'border-neutral-300 text-neutral-600 hover:bg-neutral-100'">
                        <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 4.875c0-.621.504-1.125 1.125-1.125h4.5c.621 0 1.125.504 1.125 1.125v4.5c0 .621-.504 1.125-1.125 1.125h-4.5A1.125 1.125 0 0 1 3.75 9.375v-4.5zM3.75 14.625c0-.621.504-1.125 1.125-1.125h4.5c.621 0 1.125.504 1.125 1.125v4.5c0 .621-.504 1.125-1.125 1.125h-4.5a1.125 1.125 0 0 1-1.125-1.125v-4.5zM13.5 4.875c0-.621.504-1.125 1.125-1.125h4.5c.621 0 1.125.504 1.125 1.125v4.5c0 .621-.504 1.125-1.125 1.125h-4.5A1.125 1.125 0 0 1 13.5 9.375v-4.5z"/><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 19.5h2.25M13.5 13.5h.008v.008H13.5V13.5zm.375 5.625h.008v.008h-.008v-.008z"/></svg>
                        {{ t('payment_order.qr_code') }}
                      </button>
                      <button v-if="c.has_pdf" type="button" @click="togglePdf(c)"
                        :title="t('payment_order.view_pdf')"
                        class="cursor-pointer inline-flex items-center gap-1 text-[11px] px-2 py-1 rounded font-medium"
                        :class="pdfPreviewId === c.id ? 'bg-primary-800 text-white' : 'bg-primary-600 text-white hover:bg-primary-700'">
                        <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                        {{ t('payment_order.view_pdf') }}
                      </button>
                      <button type="button" @click="openDetail(c)" :title="t('payment_order.detail')"
                        class="cursor-pointer text-[11px] px-2 py-1 border border-neutral-300 rounded hover:bg-neutral-100 text-neutral-600">
                        {{ t('payment_order.detail') }}
                      </button>
                    </div>
                  </td>
                </tr>
                <!-- Inline PDF náhled -->
                <tr v-if="pdfPreviewId === c.id">
                  <td></td>
                  <td colspan="6" class="px-4 py-3 bg-neutral-50">
                    <iframe :src="pdfPreviewUrlFor(c.id)" class="w-full h-[75vh] border border-neutral-200 rounded bg-neutral-100"
                      :title="c.vendor_invoice_number || 'PDF'"></iframe>
                  </td>
                </tr>
                <!-- Inline QR k platbě -->
                <tr v-if="qrPreviewId === c.id">
                  <td></td>
                  <td colspan="6" class="px-4 py-3 bg-neutral-50">
                    <div class="flex flex-col items-center gap-2">
                      <div v-if="qrLoading" class="text-sm text-neutral-500 py-6">{{ t('common.loading') }}</div>
                      <template v-else-if="qrData">
                        <img :src="qrData" :alt="t('payment_order.qr_code')" class="w-44 h-44 bg-white rounded border border-neutral-200 p-2" />
                        <div class="text-xs text-neutral-500">{{ accountDisplay(c) }} · {{ formatMoney(c.amount_to_pay, c.currency) }} · VS {{ c.variable_symbol || c.varsymbol || '—' }}</div>
                      </template>
                      <div v-else class="text-sm text-neutral-500 py-6">{{ t('payment_order.qr_unavailable') }}</div>
                    </div>
                  </td>
                </tr>
                <!-- Inline editace účtu -->
                <tr v-if="editingAccountId === c.id" class="bg-neutral-50">
                  <td></td>
                  <td colspan="6" class="px-4 py-3">
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-2">
                      <input v-model="editForm.account_number" type="text" :placeholder="t('purchase_invoice.qr.account')"
                        class="h-8 px-2 border border-neutral-300 rounded-md bg-surface text-sm" />
                      <input v-model="editForm.bank_code" type="text" :placeholder="t('purchase_invoice.qr.bank_code')"
                        class="h-8 px-2 border border-neutral-300 rounded-md bg-surface text-sm" />
                      <input v-model="editForm.iban" type="text" :placeholder="t('purchase_invoice.qr.iban')"
                        class="h-8 px-2 border border-neutral-300 rounded-md bg-surface text-sm" />
                      <input v-model="editForm.bic" type="text" :placeholder="t('purchase_invoice.qr.bic')"
                        class="h-8 px-2 border border-neutral-300 rounded-md bg-surface text-sm" />
                      <input v-model="editForm.variable_symbol" type="text" :placeholder="t('purchase_invoice.qr.variable_symbol')"
                        class="h-8 px-2 border border-neutral-300 rounded-md bg-surface text-sm" />
                    </div>
                    <div class="flex items-center gap-2 mt-2">
                      <button type="button" @click="saveAccount(c)" :disabled="savingAccount"
                        class="cursor-pointer h-8 px-3 bg-primary-600 hover:bg-primary-700 text-white text-sm font-medium rounded-md disabled:opacity-50">
                        {{ t('purchase_invoice.qr.save') }}
                      </button>
                      <button type="button" @click="cancelEditAccount"
                        class="cursor-pointer h-8 px-3 border border-neutral-300 text-neutral-600 hover:bg-neutral-100 text-sm rounded-md">
                        {{ t('common.cancel') }}
                      </button>
                    </div>
                  </td>
                </tr>
              </template>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Mobile: karty -->
      <div class="md:hidden bg-surface border rounded-lg divide-y divide-neutral-100 overflow-hidden shadow-sm"
        :class="grp.via === 'abo' ? 'border-primary-500/30' : 'border-warning-500/30'">
        <div v-for="c in grp.rows" :key="`m-${c.id}`" class="px-3 py-3"
          :class="!c.has_account ? 'bg-warning-50/50' : ''">
          <div class="flex items-start gap-3">
            <input type="checkbox" :checked="selectedIds.includes(c.id)" :disabled="!isSelectable(c)"
              @change="toggleSelected(c)"
              class="w-5 h-5 mt-0.5 cursor-pointer rounded border-neutral-300 text-primary-600 focus:ring-2 focus:ring-primary-500/30 disabled:opacity-40" />
            <div class="flex-1 min-w-0">
              <div class="flex items-baseline justify-between gap-2">
                <div class="font-medium text-neutral-900 truncate">{{ c.vendor_company_name }}</div>
                <div class="font-mono text-sm whitespace-nowrap flex items-center gap-1">
                  <span class="text-[10px] px-1 py-0.5 rounded font-medium"
                    :class="c.currency.toUpperCase() === 'CZK' ? 'bg-neutral-100 text-neutral-600' : 'bg-warning-50 text-warning-700'">{{ c.currency }}</span>
                  {{ formatMoney(c.amount_to_pay, c.currency) }}
                </div>
              </div>
              <div class="text-xs text-neutral-500 mt-0.5">
                <span class="font-mono">{{ c.vendor_invoice_number }}</span>
                <span class="text-neutral-400"> · </span>
                <span>{{ t('payment_order.col_due_date') }}: {{ formatDate(c.due_date) }}</span>
              </div>
              <span v-if="c.payment_ordered_at"
                class="inline-flex items-center gap-0.5 mt-1 text-[10px] px-1.5 py-0.5 rounded bg-teal-50 text-teal-600 border border-teal-500/30">
                <svg class="w-2.5 h-2.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                {{ t('payment_order.ordered_badge') }}
              </span>
              <span v-if="c.payment_method !== 'bank_transfer'"
                class="inline-flex items-center mt-1 ml-1 text-[10px] px-1.5 py-0.5 rounded bg-amber-50 text-amber-700 border border-amber-500/30 whitespace-nowrap"
                :title="t('payment_method.non_transfer_tooltip')">
                {{ t(`payment_method.${c.payment_method}`) }}
              </span>
              <div class="text-xs mt-1">
                <template v-if="!c.has_account">
                  <button v-if="auth.canWrite('purchase_invoices.payment_orders') && editingAccountId !== c.id" type="button" @click="startEditAccount(c)"
                    class="cursor-pointer inline-flex items-center gap-1 h-7 px-2 text-[11px] font-medium rounded-md border border-primary-500 text-primary-700 hover:bg-primary-50 transition">
                    <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                    {{ t('payment_order.add_account') }}
                  </button>
                  <span v-else class="text-neutral-400">—</span>
                </template>
                <template v-else>
                  <div class="flex items-start justify-between gap-2">
                    <div class="min-w-0 flex-1">
                      <div class="flex items-center gap-1.5 flex-wrap">
                        <span class="text-neutral-700"><span class="font-mono">{{ accountDisplay(c) }}</span><span v-if="bankLabel(c.bank_code)" class="text-[10px] text-neutral-500 ml-1.5">{{ bankLabel(c.bank_code) }}</span></span>
                        <span v-if="c.payment_account_source" class="text-[10px] px-1.5 py-0.5 rounded" :class="sourceBadgeClass(c.payment_account_source)">
                          {{ t(`purchase_invoice.qr.source.${c.payment_account_source}`) }}
                        </span>
                        <span v-if="c.account_verified !== 'na'" class="text-[10px] px-1.5 py-0.5 rounded" :class="verifiedBadgeClass(c.account_verified)">
                          {{ t(`payment_order.verified.${c.account_verified}`) }}
                        </span>
                      </div>
                      <div v-if="!currencyMatches(c)" class="text-[10px] text-neutral-500 mt-0.5">{{ t('payment_order.currency_mismatch') }}</div>
                      <span v-else-if="isCzk && !c.abo_eligible" class="text-[10px] text-warning-600">{{ t('payment_order.not_abo_eligible_row') }}</span>
                      <span v-else-if="isEur && !c.sepa_eligible" class="text-[10px] text-warning-600">{{ t('payment_order.not_sepa_eligible_row') }}</span>
                    </div>
                    <button v-if="auth.canWrite('purchase_invoices.payment_orders') && editingAccountId !== c.id" type="button" @click="startEditAccount(c)"
                      :title="t('payment_order.edit_account')"
                      class="cursor-pointer flex-shrink-0 inline-flex items-center gap-1 h-7 px-2 text-[11px] font-medium rounded-md border border-neutral-300 text-neutral-600 hover:bg-neutral-100 transition">
                      <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931z"/></svg>
                      {{ t('payment_order.edit_account') }}
                    </button>
                  </div>
                </template>
              </div>
              <!-- Akce (mobile) -->
              <div class="flex items-center gap-2 mt-2 flex-wrap">
                <button v-if="c.has_account" type="button" @click="toggleQr(c)"
                  class="cursor-pointer inline-flex items-center gap-1 text-[11px] px-2 py-1 rounded border font-medium"
                  :class="qrPreviewId === c.id ? 'bg-neutral-800 text-neutral-50 border-neutral-800' : 'border-neutral-300 text-neutral-600 hover:bg-neutral-100'">
                  <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 4.875c0-.621.504-1.125 1.125-1.125h4.5c.621 0 1.125.504 1.125 1.125v4.5c0 .621-.504 1.125-1.125 1.125h-4.5A1.125 1.125 0 0 1 3.75 9.375v-4.5zM3.75 14.625c0-.621.504-1.125 1.125-1.125h4.5c.621 0 1.125.504 1.125 1.125v4.5c0 .621-.504 1.125-1.125 1.125h-4.5a1.125 1.125 0 0 1-1.125-1.125v-4.5zM13.5 4.875c0-.621.504-1.125 1.125-1.125h4.5c.621 0 1.125.504 1.125 1.125v4.5c0 .621-.504 1.125-1.125 1.125h-4.5A1.125 1.125 0 0 1 13.5 9.375v-4.5z"/></svg>
                  {{ t('payment_order.qr_code') }}
                </button>
                <button v-if="c.has_pdf" type="button" @click="togglePdf(c)"
                  class="cursor-pointer inline-flex items-center gap-1 text-[11px] px-2 py-1 rounded text-white font-medium"
                  :class="pdfPreviewId === c.id ? 'bg-primary-800' : 'bg-primary-600 hover:bg-primary-700'">
                  <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                  {{ t('payment_order.view_pdf') }}
                </button>
                <button type="button" @click="openDetail(c)"
                  class="cursor-pointer text-[11px] px-2 py-1 border border-neutral-300 rounded hover:bg-neutral-100 text-neutral-600">
                  {{ t('payment_order.detail') }}
                </button>
                <button v-if="c.can_verify" type="button" @click="verifyAccountRow(c)" :disabled="verifyingId === c.id"
                  class="cursor-pointer text-[11px] px-2 py-1 border border-primary-300 rounded hover:bg-primary-50 text-primary-700 disabled:opacity-50">
                  {{ verifyingId === c.id ? '…' : t('payment_order.verify_account') }}
                </button>
              </div>
              <!-- Inline PDF náhled (mobile) -->
              <div v-if="pdfPreviewId === c.id" class="mt-2">
                <iframe :src="pdfPreviewUrlFor(c.id)" class="w-full h-[70vh] border border-neutral-200 rounded bg-neutral-100"
                  :title="c.vendor_invoice_number || 'PDF'"></iframe>
              </div>
              <!-- Inline QR (mobile) -->
              <div v-if="qrPreviewId === c.id" class="mt-2 flex flex-col items-center gap-1">
                <div v-if="qrLoading" class="text-sm text-neutral-500 py-4">{{ t('common.loading') }}</div>
                <img v-else-if="qrData" :src="qrData" :alt="t('payment_order.qr_code')" class="w-40 h-40 bg-white rounded border border-neutral-200 p-2" />
                <div v-else class="text-sm text-neutral-500 py-4">{{ t('payment_order.qr_unavailable') }}</div>
              </div>
              <!-- Inline editace (mobile) -->
              <div v-if="editingAccountId === c.id" class="mt-2 space-y-2">
                <input v-model="editForm.account_number" type="text" :placeholder="t('purchase_invoice.qr.account')"
                  class="w-full h-8 px-2 border border-neutral-300 rounded-md bg-surface text-sm" />
                <input v-model="editForm.bank_code" type="text" :placeholder="t('purchase_invoice.qr.bank_code')"
                  class="w-full h-8 px-2 border border-neutral-300 rounded-md bg-surface text-sm" />
                <input v-model="editForm.iban" type="text" :placeholder="t('purchase_invoice.qr.iban')"
                  class="w-full h-8 px-2 border border-neutral-300 rounded-md bg-surface text-sm" />
                <input v-model="editForm.bic" type="text" :placeholder="t('purchase_invoice.qr.bic')"
                  class="w-full h-8 px-2 border border-neutral-300 rounded-md bg-surface text-sm" />
                <input v-model="editForm.variable_symbol" type="text" :placeholder="t('purchase_invoice.qr.variable_symbol')"
                  class="w-full h-8 px-2 border border-neutral-300 rounded-md bg-surface text-sm" />
                <div class="flex items-center gap-2">
                  <button type="button" @click="saveAccount(c)" :disabled="savingAccount"
                    class="cursor-pointer h-8 px-3 bg-primary-600 hover:bg-primary-700 text-white text-sm font-medium rounded-md disabled:opacity-50">
                    {{ t('purchase_invoice.qr.save') }}
                  </button>
                  <button type="button" @click="cancelEditAccount"
                    class="cursor-pointer h-8 px-3 border border-neutral-300 text-neutral-600 hover:bg-neutral-100 text-sm rounded-md">
                    {{ t('common.cancel') }}
                  </button>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
      </template>

      <div v-if="candPage < candPages" class="text-center mt-3">
        <button @click="loadCandidates(false)" :disabled="loadingMoreCandidates"
          class="cursor-pointer h-9 px-4 text-sm border border-neutral-300 rounded-md hover:bg-neutral-50 disabled:opacity-50">
          {{ loadingMoreCandidates ? t('common.loading_more') : t('common.load_more') }}
        </button>
      </div>
    </div>

    <!-- ═══ Historie příkazů ═══ -->
    <section class="mt-8">
      <h2 class="text-lg font-semibold mb-3">{{ t('payment_order.history_title') }}</h2>
      <BankPaymentSubmission v-model="bankOrderId" :orders="history" />

      <div v-if="historyLoading" class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
        <TableSkeleton :rows="3" :cols="5" />
      </div>
      <div v-else-if="!history.length" class="bg-surface border border-neutral-200 rounded-lg shadow-sm">
        <EmptyState dense accent="neutral" icon="archive" :title="t('payment_order.history_empty')" />
      </div>
      <div v-else class="bg-surface border border-neutral-200 rounded-lg overflow-hidden shadow-sm">
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead class="bg-neutral-50 text-neutral-500 text-xs uppercase tracking-wide">
              <tr>
                <th class="text-center px-4 py-2 font-medium">{{ t('payment_order.col_payment_date') }}</th>
                <th class="text-left px-4 py-2 font-medium">{{ t('payment_order.payer_account') }}</th>
                <th class="text-center px-4 py-2 font-medium">{{ t('payment_order.col_item_count') }}</th>
                <th class="text-right px-4 py-2 font-medium">{{ t('payment_order.col_amount') }}</th>
                <th class="text-center px-4 py-2 font-medium">{{ t('payment_order.col_mark_paid') }}</th>
                <th class="text-right px-4 py-2 font-medium">{{ t('payment_order.col_actions') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="item in history" :key="item.id" class="hover:bg-neutral-50 transition">
                <td class="px-4 py-2.5 text-center text-xs">{{ formatDate(item.payment_date) }}</td>
                <td class="px-4 py-2.5 text-xs text-neutral-600">{{ payerAccountDisplay(item) }}</td>
                <td class="px-4 py-2.5 text-center text-xs">{{ item.item_count }}</td>
                <td class="px-4 py-2.5 text-right font-mono">{{ formatMoney(item.total_amount, item.currency) }}</td>
                <td class="px-4 py-2.5 text-center">
                  <span v-if="item.mark_paid" class="text-xs px-2 py-0.5 rounded bg-success-50 text-success-600 border border-success-500/40">
                    {{ t('payment_order.marked_paid') }}
                  </span>
                  <span v-else class="text-neutral-300">—</span>
                </td>
                <td class="px-4 py-2.5 text-right">
                  <div class="inline-flex flex-wrap items-center justify-end gap-1.5">
                    <button type="button" @click="redownload(item, 'csv')"
                      class="cursor-pointer text-xs px-2 py-1 border border-neutral-300 rounded hover:bg-neutral-100 text-neutral-600">
                      CSV
                    </button>
                    <button type="button" @click="redownload(item, 'pdf')"
                      class="cursor-pointer text-xs px-2 py-1 border border-neutral-300 rounded hover:bg-neutral-100 text-neutral-600">
                      PDF
                    </button>
                    <button type="button" @click="redownload(item, 'abo')"
                      :disabled="item.currency.toUpperCase() !== 'CZK'"
                      :title="item.currency.toUpperCase() !== 'CZK' ? t('payment_order.abo_only_czk') : ''"
                      class="cursor-pointer text-xs px-2 py-1 border border-primary-300 rounded hover:bg-primary-50 text-primary-700 disabled:opacity-40 disabled:cursor-not-allowed">
                      ABO
                    </button>
                    <button v-if="item.currency.toUpperCase() === 'EUR'" type="button" @click="redownload(item, 'sepa')"
                      :disabled="!item.payer_iban"
                      :title="!item.payer_iban ? t('payment_order.no_payer_iban') : ''"
                      class="cursor-pointer text-xs px-2 py-1 border border-primary-300 rounded hover:bg-primary-50 text-primary-700 disabled:opacity-40 disabled:cursor-not-allowed">
                      SEPA
                    </button>
                    <button v-if="auth.canWrite('purchase_invoices.payment_orders')" type="button" @click="deleteOrder(item)"
                      :disabled="deletingOrderId !== null" :class="OUTLINE.danger"
                      class="inline-flex items-center gap-1 whitespace-nowrap cursor-pointer text-xs px-2 py-1 rounded disabled:opacity-40 disabled:cursor-not-allowed">
                      <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path :d="ICONS.trash" /></svg>
                      {{ t('common.delete') }}
                    </button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <div v-if="historyPage < historyPages" class="text-center py-3 border-t border-neutral-200">
          <button @click="loadHistory(false)" :disabled="historyLoadingMore"
            class="cursor-pointer h-9 px-4 text-sm border border-neutral-300 rounded-md hover:bg-neutral-50 disabled:opacity-50">
            {{ historyLoadingMore ? t('common.loading_more') : t('common.load_more') }}
          </button>
        </div>
      </div>
    </section>

    <JournalSourceDrawer v-if="sourceDrawerEntryId" :entry-id="sourceDrawerEntryId"
      @close="sourceDrawerEntryId = null" @focus-entry="focusInJournal" />
  </div>
</template>

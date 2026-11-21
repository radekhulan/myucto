<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { otherItemsApi, type OtherItem, type OtherItemAllocation, type OtherItemPaymentCandidate, type OtherItemPostingLine } from '@/api/otherItems'
import { otherItemKindLabel } from '@/utils/otherItemKind'
import { isOtherItemOverdue, otherItemDisplayStatus, otherItemRemainingPayment, otherItemStatusClass } from '@/utils/otherItemStatus'
import { OTHER_ITEM_DEFAULT_FOLDER, otherItemSettingsApi, resolveOtherItemFolder } from '@/api/otherItemSettings'
import { accountingApi, type ChartAccount } from '@/api/accounting'
import { useAuthStore } from '@/stores/auth'
import { useSupplierStore } from '@/stores/supplier'
import { useToast } from '@/composables/useToast'
import { formatDate, formatMoney } from '@/composables/useFormat'
import { useWindowRefresh } from '@/composables/useWindowRefresh'
import { useWindowTitle } from '@/composables/useWindowTitle'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import PostingBadge from '@/components/ui/PostingBadge.vue'
import LinkedDocumentsPanel from '@/components/documents/LinkedDocumentsPanel.vue'
import OtherItemPlans, { type OtherItemPlansState } from '@/components/accounting/OtherItemPlans.vue'
import DocumentPostingPanel from '@/components/accounting/DocumentPostingPanel.vue'
import Modal from '@/components/ui/Modal.vue'
import DateInput from '@/components/ui/DateInput.vue'
import FieldNote from '@/components/ui/FieldNote.vue'
import { formDateInput } from '@/components/ui/formStyles'
import ChartAccountSelect from '@/components/accounting/ChartAccountSelect.vue'
import OtherItemPostingLines from '@/components/accounting/OtherItemPostingLines.vue'
import OtherItemPostingRules from '@/components/accounting/OtherItemPostingRules.vue'
import DocumentDimensionsPanel from '@/components/dimensions/DocumentDimensionsPanel.vue'
import LockedPeriodAckModal from '@/components/accounting/LockedPeriodAckModal.vue'
import { useLockedPeriodAck } from '@/composables/useLockedPeriodAck'
import { ICONS, btnFilled, btnOutline, btnOutlineSm } from '@/components/ui/buttonStyles'
import { appIsoDate } from '@/utils/date'
import { formatAccountNumber } from '@/utils/bankAccount'
import { cashDocumentLink, journalEntryLink } from '@/utils/journalSourceLink'
import ListNavArrows from '@/components/ui/ListNavArrows.vue'
import ClientQuickLinks from '@/components/clients/ClientQuickLinks.vue'
import { useIsPhone } from '@/composables/useIsPhone'
import { useListNavigation } from '@/composables/useListNavigation'
import { ACCRUAL_ERRORS, DRAFT_ACCRUAL_ERRORS, accrualErrorKey, accrualPeriodErrorKey } from './accrualError'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const supplier = useSupplierStore()
const toast = useToast()
const id = computed(() => Number(route.params.id))
const item = ref<OtherItem | null>(null)
const allocations = ref<OtherItemAllocation[]>([])
const loading = ref(false)
/** Položka neexistuje (404) — jiný stav než výpadek načtení. */
const notFound = ref(false)
/** Načtení selhalo; zobrazená data (jsou-li) pochází z posledního úspěšného načtení. */
const failed = ref(false)
const busy = ref(false)
const postOpen = ref(false)
const reverseOpen = ref(false)
const reverseReason = ref('')
const reverseDate = ref(appIsoDate())
const repostOpen = ref(false)
const repostLoading = ref(false)
const repostAccounts = ref<ChartAccount[]>([])
const repostAccountCode = ref('')
const repostCounterAccountCode = ref('')
const repostSplit = ref(false)
const repostLines = ref<OtherItemPostingLine[]>([])
const repostDate = ref(appIsoDate())
const repostReason = ref('')
const postingAccounts = ref<ChartAccount[]>([])
// Telefon jako u přijaté faktury: souhrn nahoře, karty a účetní panely za tlačítkem Podrobnosti.
const isPhone = useIsPhone()
// Opakování a splátky nastavují akce v liště; komponenta plánů říká, co je k dispozici.
const plans = ref<InstanceType<typeof OtherItemPlans> | null>(null)
const plansState = ref<OtherItemPlansState>({ canSchedule: false, canSplit: false, editing: false })
const phoneDetailsOpen = ref(false)
const isDoubleEntry = computed(() => supplier.currentSupplier?.accounting_mode === 'double_entry')
const isTaxEvidence = computed(() => supplier.currentSupplier?.accounting_mode === 'tax_evidence')
const canEdit = computed(() => item.value?.status === 'draft' && auth.canWrite('other_items'))
const canPost = computed(() => auth.canWrite('other_items') && (!isDoubleEntry.value || auth.canWrite('accounting.journal.post')))
const canManageBankPayment = computed(() => auth.canRead('bank') && auth.canWrite('bank.match'))
const canManageCashPayment = computed(() => auth.canRead('cash') && auth.canWrite('cash.document.write'))
const canAllocate = computed(() => auth.canWrite('other_items') && (canManageBankPayment.value || canManageCashPayment.value) && item.value?.currency === 'CZK'
  && (item.value?.status === 'posted' || item.value?.status === 'confirmed') && payableAmount.value > 0)
/** Sekce Zaúčtování čte deník (právo accounting); bez něj ukazuje vznik a přeúčtování část Účtování. */
const canReadJournal = computed(() => auth.canRead('accounting'))
const canRepost = computed(() => isDoubleEntry.value && item.value?.status === 'posted' && !!item.value?.journal_entry_id
  && auth.canWrite('other_items') && auth.canWrite('accounting.journal.post'))
/** Přeúčtování nabízí sekce Zaúčtování u živého zápisu; uhrazenou položku nejdřív odpárovat. */
const externalRepost = computed(() => {
  if (!canRepost.value) return null
  const paid = Number(item.value?.paid_amount || 0) > 0
  return { disabled: paid || busy.value || repostLoading.value, reason: paid ? t('other_items.repost_paid') : undefined }
})
const folderTemplate = ref(OTHER_ITEM_DEFAULT_FOLDER)
const documentFolderPath = computed(() => resolveOtherItemFolder(folderTemplate.value, item.value?.issued_on))
if (auth.canRead('documents')) otherItemSettingsApi.get().then(s => { folderTemplate.value = s.document_folder }, () => {})
const repostAccountChoices = computed(() => repostAccounts.value.filter(account => account.is_active).sort((a, b) => a.account_code.localeCompare(b.account_code)))
const repostBalanceAccounts = computed(() => repostAccountChoices.value.filter(account =>
  account.account_type === (item.value?.side === 'receivable' ? 'asset' : 'liability'),
))
const paymentQuery = ref('')
const paymentInput = ref<HTMLInputElement | null>(null)
const paymentCandidates = ref<OtherItemPaymentCandidate[]>([])
const candidatesLoading = ref(false)
/** Dotaz, na který přišla poslední odpověď — prázdný výsledek se hlásí jen k dokončenému hledání. */
const searchedQuery = ref<string | null>(null)
const selectedPayment = ref<OtherItemPaymentCandidate | null>(null)
/** Kolik zbývá zaplatit: u rozdělených splátek úvěru i úrok a poplatek, jinak saldo. */
const payableAmount = computed(() => item.value ? otherItemRemainingPayment(item.value) : 0)
const allocationAmount = ref<number | null>(null)
let paymentTimer: ReturnType<typeof setTimeout> | null = null
let paymentRequest = 0

/** Stav se řídí tím, kolik zbývá ZAPLATIT (otherItemRemainingPayment), stejně jako ve výpisu. */
const displayStatus = computed(() => item.value ? otherItemDisplayStatus(item.value) : '')
const overdue = computed(() => !!item.value && isOtherItemOverdue(item.value))
/** Stav úhrady živého dokladu; koncept a storno žádný nemají. Po splatnosti nese štítek v záhlaví. */
const paymentState = computed(() => {
  if (!item.value || !['posted', 'confirmed'].includes(String(item.value.status))) return null
  return ['paid', 'partial', 'overpaid'].includes(displayStatus.value) ? displayStatus.value : 'open'
})
const postingPanel = ref<InstanceType<typeof DocumentPostingPanel> | null>(null)
/** Kontace vzniku, kterou zaúčtování konceptu zapíše (týž výpočet jako server při zaúčtování). */
const originPreview = computed(() => item.value?.posting_rules?.origin ?? null)
/** Vznik bez protiúčtu zaúčtovat nejde: tlačítko v dialogu je zamčené a důvod stojí přímo nad ním. */
const postMissingCounter = computed(() => isDoubleEntry.value && !!originPreview.value && (originPreview.value.posting_lines || []).length === 0)

// Výběr seznamu, ze kterého se detail otevřel: šipky listují v něm a „Zpět“ vrátí jeho filtry.
const listNav = useListNavigation({
  kind: 'other-items',
  currentId: () => id.value || 0,
  detailRoute: itemId => ({ name: 'other-item-detail', params: { id: itemId } }),
  fetchPage: async (params, page) => {
    const result = await otherItemsApi.list({ ...(params as Parameters<typeof otherItemsApi.list>[0]), page })
    return { ids: result.items.map(row => Number(row.source_id)), pages: Math.max(1, Math.ceil(result.total / (result.per_page || 50))) }
  },
  scope: () => supplier.currentSupplierId,
  // Přepnutí položky zahodí rozepsanou poznámku, účet protistrany i vybranou úhradu.
  hotkeysEnabled: () => !editingSomething.value,
})
const backToList = computed(() => listNav.listPath.value ?? { name: 'other-items' })

useWindowTitle(() => item.value ? (item.value.document_no || t('other_items.status.draft')) : null, () => item.value?.title ?? null)

/** Štítky důvodů shody kandidáta (VS, účet protistrany, splátka, zbývající dluh). */
function matchLabels(candidate: OtherItemPaymentCandidate): string[] {
  return (candidate.match_reasons || []).map(reason => t(`other_items.match_reason.${reason}`,
    { number: candidate.installment?.position ?? '' }))
}

function statusLabel(value: string): string {
  const key = `other_items.status.${value}`
  return t(key) === key ? value : t(key)
}

let loadSeq = 0

async function fetchDetail(itemId: number) {
  const [detail, linked] = await Promise.all([otherItemsApi.get(itemId), otherItemsApi.allocations(itemId)])
  if (isDoubleEntry.value && postingAccounts.value.length === 0) {
    postingAccounts.value = await accountingApi.listAccounts().catch(() => [])
  }
  return { detail, linked }
}

async function load() {
  const seq = ++loadSeq
  const itemId = id.value
  loading.value = true
  try {
    const { detail, linked } = await fetchDetail(itemId)
    // Odpověď k položce, ze které se mezitím odešlo, nesmí přepsat novou.
    if (seq !== loadSeq) return
    // Opakované načtení téže položky následuje po akci (zaúčtování, storno, přeúčtování,
    // úhrada): sekce Zaúčtování si zápisy drží sama, a proto se obnoví také.
    const reloaded = item.value !== null && Number(item.value.id) === itemId
    item.value = detail
    allocations.value = linked
    notFound.value = false
    failed.value = false
    if (reloaded) void postingPanel.value?.reload()
  } catch (error: any) {
    if (seq !== loadSeq) return
    if (error?.response?.status === 404) {
      notFound.value = true
      item.value = null
      allocations.value = []
    } else {
      failed.value = true
      toast.error(error?.response?.data?.error?.message || t('common.error'))
    }
  } finally {
    if (seq === loadSeq) loading.value = false
  }
}

/** Tichá obnova po změně v jiném okně: obsah zůstane a jen se přepíše; chyba nechá obnovu čekat. */
async function refresh() {
  const seq = ++loadSeq
  let fetched: Awaited<ReturnType<typeof fetchDetail>>
  try {
    fetched = await fetchDetail(id.value)
  } catch (error: any) {
    // Položku mezitím smazali v jiném okně: detail to řekne, místo aby nabízel akce nad ní.
    if (seq === loadSeq && error?.response?.status === 404) {
      notFound.value = true
      item.value = null
      allocations.value = []
      return
    }
    throw error
  }
  const { detail, linked } = fetched
  if (seq !== loadSeq) return
  item.value = detail
  allocations.value = linked
  failed.value = false
}

async function searchPayments(query: string) {
  const request = ++paymentRequest
  candidatesLoading.value = true
  try {
    const rows = await otherItemsApi.paymentCandidates(id.value, query.trim())
    if (request === paymentRequest) {
      paymentCandidates.value = rows
      searchedQuery.value = query.trim()
    }
  } catch (error: any) {
    if (request === paymentRequest) toast.error(error?.response?.data?.error?.message || t('common.error'))
  } finally {
    if (request === paymentRequest) candidatesLoading.value = false
  }
}

watch(paymentQuery, query => {
  if (paymentTimer) clearTimeout(paymentTimer)
  // Vymazání pole uživatelem (pole má fokus) vrátí výchozí nabídku jako dřív. Vymazání kódem
  // (po přiřazení úhrady, po přechodu na jinou položku) nic nehledá, o to nikdo nežádal.
  if (!query.trim() && document.activeElement !== paymentInput.value) {
    paymentRequest++
    candidatesLoading.value = false
    paymentCandidates.value = []
    searchedQuery.value = null
    return
  }
  paymentTimer = setTimeout(() => void searchPayments(query), 250)
})
onBeforeUnmount(() => { loadSeq++; if (paymentTimer) clearTimeout(paymentTimer) })

function selectPayment(candidate: OtherItemPaymentCandidate) {
  selectedPayment.value = candidate
  allocationAmount.value = Math.min(Math.abs(Number(candidate.amount)), payableAmount.value)
  paymentCandidates.value = []
  searchedQuery.value = null
}

async function allocate() {
  const candidate = selectedPayment.value
  if (!candidate || !allocationAmount.value || allocationAmount.value <= 0 || busy.value) return
  busy.value = true
  try {
    await otherItemsApi.allocate(id.value, {
      ...(candidate.source === 'bank' ? { bank_transaction_id: candidate.id } : { cash_document_id: candidate.id }),
      amount: Number(allocationAmount.value),
    })
    toast.success(t('other_items.payment_linked'))
    selectedPayment.value = null
    allocationAmount.value = null
    paymentQuery.value = ''
    searchedQuery.value = null
    await load()
  } catch (error: any) {
    toast.error(error?.response?.data?.error?.message || t('common.error'))
  } finally {
    busy.value = false
  }
}

// Účet protistrany jde u potvrzeného či zaúčtovaného dokladu změnit samostatně (párování úhrad).
const canEditCounterparty = computed(() => auth.canWrite('other_items')
  && (item.value?.status === 'confirmed' || item.value?.status === 'posted'))
const counterpartyEditing = ref(false)
const counterpartyInput = ref('')
function startCounterpartyEdit() {
  counterpartyInput.value = formatAccountNumber(item.value?.counterparty_account, item.value?.counterparty_bank_code) || ''
  counterpartyEditing.value = true
}
async function saveCounterparty() {
  // Během načtení (Zkusit znovu) se neukládá: uložení by načtení zneplatnilo a to by nikdy
  // nevypnulo příznak načítání, takže by se zastavila i tichá obnova.
  if (!item.value || busy.value || loading.value) return
  busy.value = true
  // Uložení je také načtení: odpověď platí jen pro tutéž položku a zneplatní obnovu, která běží
  // ještě se starým účtem.
  const seq = ++loadSeq
  const itemId = Number(item.value.id)
  try {
    const saved = await otherItemsApi.updateCounterpartyAccount(itemId, counterpartyInput.value.trim() || null)
    if (seq !== loadSeq || itemId !== id.value) return
    item.value = saved
    counterpartyEditing.value = false
    toast.success(t('other_items.counterparty_saved'))
  } catch (error: any) {
    toast.error(error?.response?.data?.error?.message || t('common.error'))
  } finally {
    busy.value = false
  }
}

// Období časového rozlišení výnosu zaúčtované pohledávky: zápis se nemění, čte ho uzávěrka.
// V daňové evidenci jen ke čtení, a jen s uloženým obdobím (z dřívějšího podvojného účetnictví).
const showAccrual = computed(() => !!item.value && (isDoubleEntry.value || !!item.value.accrual_from))
const canEditAccrual = computed(() => isDoubleEntry.value && auth.canWrite('other_items') && item.value?.status === 'posted' && !!item.value?.journal_entry_id)
// Bez vlastního zápisu (daňová evidence, položka ze zůstatku deníku) období nic neodkládá a server
// ho dovolí jen odebrat, např. uložené z konceptu před přechodem na daňovou evidenci.
const canClearAccrual = computed(() => !canEditAccrual.value && auth.canWrite('other_items') && !!item.value
  && item.value.status !== 'draft' && !!item.value.accrual_from)
const accrualEditing = ref(false)
const accrualFrom = ref<string | null>(null)
const accrualTo = ref<string | null>(null)
/** Odmítnutí období zůstane u polí, dokud ho uživatel neopraví nebo úpravu nezavře. */
const accrualError = ref('')
watch([accrualFrom, accrualTo], () => { accrualError.value = '' })
function startAccrualEdit() {
  accrualFrom.value = item.value?.accrual_from?.slice(0, 10) ?? null
  accrualTo.value = item.value?.accrual_to?.slice(0, 10) ?? null
  accrualError.value = ''
  accrualEditing.value = true
}
function saveAccrual() {
  const key = accrualPeriodErrorKey(accrualFrom.value, accrualTo.value)
  if (key) {
    accrualError.value = t(key)
    return
  }
  void storeAccrual(accrualFrom.value || null, accrualTo.value || null)
}
function closeAccrualEdit() {
  accrualEditing.value = false
  accrualError.value = ''
}
// Zrušení bez vlastního zápisu je nevratné (období jde zadat jen u zaúčtované položky), proto potvrzení.
function clearAccrual() {
  if (!window.confirm(t('other_items.accrual.clear_confirm'))) return
  void storeAccrual(null, null)
}
async function storeAccrual(from: string | null, to: string | null) {
  if (!item.value || busy.value) return
  busy.value = true
  // Uložení je také načtení: odpověď platí jen pro tutéž položku a zneplatní obnovu, která běží
  // ještě se starým obdobím (jako u účtu protistrany).
  const seq = ++loadSeq
  const itemId = id.value
  // Odpověď změny nese jen doklad; posouzení smazání se zápisem (detail GET) období nemění.
  const postedDeletionBefore = item.value.posted_deletion
  try {
    const saved = await otherItemsApi.updateAccrual(itemId, from, to)
    if (seq !== loadSeq || itemId !== id.value) return
    item.value = { ...saved, posted_deletion: postedDeletionBefore }
    accrualEditing.value = false
    toast.success(t('accrual_period.saved'))
  } catch (error: any) {
    if (seq !== loadSeq || itemId !== id.value) return
    const key = accrualErrorKey(error, ACCRUAL_ERRORS)
    accrualError.value = key ? t(key) : error?.response?.data?.error?.message || t('common.error')
  } finally {
    busy.value = false
    // Uložení zneplatnilo případné probíhající načtení; jeho `finally` by `loading` neshodilo.
    if (seq === loadSeq) loading.value = false
  }
}

// Poznámku vystaveného dokladu jde doplnit kdykoli, do účetnictví se nepromítá.
const canEditNote = computed(() => auth.canWrite('other_items') && !!item.value && item.value.status !== 'draft')
const noteEditing = ref(false)
const noteInput = ref('')
function startNoteEdit() {
  noteInput.value = item.value?.note || ''
  noteEditing.value = true
}
async function saveNote() {
  if (!item.value || busy.value) return
  busy.value = true
  try {
    item.value = await otherItemsApi.updateText(id.value, noteInput.value.trim() || null)
    noteEditing.value = false
    toast.success(t('other_items.note_saved'))
    await load()
  } catch (error: any) {
    toast.error(error?.response?.data?.error?.message || t('common.error'))
  } finally {
    busy.value = false
  }
}

// Smazání zaúčtovaného dokladu i se zápisem: stejná práva jako smazání jeho zápisu v deníku.
const canDeletePosted = computed(() => isDoubleEntry.value && auth.canWrite('other_items') && auth.canWrite('accounting')
  && auth.canWrite('accounting.journal.write') && auth.canWrite('accounting.journal.post'))
const postedDeletion = computed(() => item.value?.posted_deletion ?? null)
const deleteOpen = ref(false)
const lockedAck = useLockedPeriodAck()
async function removePosted() {
  if (!item.value || busy.value) return
  deleteOpen.value = false
  busy.value = true
  try {
    const result = await lockedAck.run(ack => otherItemsApi.removePosted(id.value, ack))
    if (result === null) return
    toast.success(t(result.status === 'cancelled' ? 'other_items.delete_posted_cancelled' : 'other_items.delete_posted_done'))
    await router.push(backToList.value)
  } catch (error: any) {
    toast.error(error?.response?.data?.error?.message || t('common.error'))
  } finally {
    busy.value = false
  }
}

async function unallocate(allocation: OtherItemAllocation) {
  if (busy.value || !window.confirm(t('other_items.confirm_unlink_payment'))) return
  busy.value = true
  try {
    await otherItemsApi.unallocate(id.value, allocation.id)
    toast.success(t('other_items.payment_unlinked'))
    await load()
  } catch (error: any) {
    toast.error(error?.response?.data?.error?.message || t('common.error'))
  } finally {
    busy.value = false
  }
}

async function post() {
  if (!item.value || busy.value) return
  busy.value = true
  try {
    await otherItemsApi.post(id.value)
    postOpen.value = false
    toast.success(t(isDoubleEntry.value ? 'other_items.posted' : 'other_items.confirmed'))
    await load()
  } catch (error: any) {
    toast.error(error?.response?.data?.error?.message || t('common.error'))
  } finally {
    busy.value = false
  }
}

async function reverse() {
  if (!item.value || busy.value) return
  if (reverseReason.value.trim().length < 3) {
    toast.error(t('other_items.reason_required'))
    return
  }
  busy.value = true
  try {
    await otherItemsApi.reverse(id.value, { reason: reverseReason.value.trim(), ...(isDoubleEntry.value ? { entry_date: reverseDate.value } : {}) })
    toast.success(t(isDoubleEntry.value ? 'other_items.reversed' : 'other_items.cancelled'))
    reverseOpen.value = false
    await load()
  } catch (error: any) {
    toast.error(error?.response?.data?.error?.message || t('common.error'))
  } finally {
    busy.value = false
  }
}

async function openRepost() {
  if (!canRepost.value || !item.value || Number(item.value.paid_amount) > 0 || repostLoading.value) return
  repostLoading.value = true
  try {
    repostAccounts.value = await accountingApi.listAccounts()
    repostAccountCode.value = item.value.account_code || (item.value.side === 'receivable' ? '315' : '325')
    repostCounterAccountCode.value = item.value.counter_account_code || ''
    const lines = item.value.posting_lines || []
    repostSplit.value = lines.length > 1
    repostLines.value = repostSplit.value ? lines.map(line => ({ account_code: line.account_code, amount: Number(line.amount) })) : []
    if (!repostSplit.value && lines.length === 1) repostCounterAccountCode.value = lines[0].account_code
    repostDate.value = appIsoDate()
    repostReason.value = ''
    repostOpen.value = true
  } catch (error: any) {
    toast.error(error?.response?.data?.error?.message || t('common.error'))
  } finally {
    repostLoading.value = false
  }
}

function startRepostSplit() {
  repostLines.value = [
    { account_code: repostCounterAccountCode.value, amount: Number(item.value?.amount || 0) },
    { account_code: '', amount: 0 },
  ]
  repostSplit.value = true
}

function stopRepostSplit() {
  repostCounterAccountCode.value = repostLines.value[0]?.account_code || ''
  repostLines.value = []
  repostSplit.value = false
}

async function repost() {
  if (!canRepost.value || !item.value || busy.value) return
  if (!repostAccountCode.value || (!repostSplit.value && !repostCounterAccountCode.value)
    || !repostDate.value || repostReason.value.trim().length < 3) {
    toast.error(t('other_items.repost_required'))
    return
  }
  if (repostSplit.value && (repostLines.value.some(line => !line.account_code || !Number.isFinite(line.amount) || line.amount <= 0)
    || repostLines.value.reduce((sum, line) => sum + Math.round(line.amount * 100), 0) !== Math.round(Number(item.value.amount) * 100))) {
    toast.error(t('other_items.posting_lines_invalid'))
    return
  }
  const currentLines = item.value.posting_lines || (item.value.counter_account_code
    ? [{ account_code: item.value.counter_account_code, amount: Number(item.value.amount) }] : [])
  const nextLines = repostSplit.value ? repostLines.value : [{ account_code: repostCounterAccountCode.value, amount: Number(item.value.amount) }]
  if (repostAccountCode.value === (item.value.account_code || (item.value.side === 'receivable' ? '315' : '325'))
    && JSON.stringify(nextLines.map(line => [line.account_code, Math.round(line.amount * 100)]))
      === JSON.stringify(currentLines.map(line => [line.account_code, Math.round(Number(line.amount) * 100)]))) {
    toast.error(t('other_items.repost_unchanged'))
    return
  }
  busy.value = true
  try {
    await otherItemsApi.repost(id.value, {
      account_code: repostAccountCode.value,
      ...(repostSplit.value ? { posting_lines: repostLines.value } : { counter_account_code: repostCounterAccountCode.value }),
      entry_date: repostDate.value,
      reason: repostReason.value.trim(),
    })
    repostOpen.value = false
    toast.success(t('other_items.reposted'))
    await load()
  } catch (error: any) {
    toast.error(error?.response?.data?.error?.message || t('common.error'))
  } finally {
    busy.value = false
  }
}

async function remove() {
  if (!item.value || busy.value || !window.confirm(t('other_items.confirm_delete'))) return
  busy.value = true
  try {
    await otherItemsApi.remove(id.value)
    toast.success(t('common.deleted'))
    await router.push(backToList.value)
  } catch (error: any) {
    const key = accrualErrorKey(error, DRAFT_ACCRUAL_ERRORS)
    toast.error(key ? t(key) : error?.response?.data?.error?.message || t('common.error'))
  } finally {
    busy.value = false
  }
}

// Až po deklaraci všech stavů úprav: useWindowRefresh podmínku čte hned při založení (watch),
// dřívější volání by sáhlo na ještě nedeklarovaný ref (ReferenceError, detail bez lišty akcí).
const editingSomething = computed(() => busy.value || postOpen.value || reverseOpen.value || repostOpen.value || deleteOpen.value
  || counterpartyEditing.value || noteEditing.value || accrualEditing.value || !!selectedPayment.value || paymentQuery.value !== ''
  || plansState.value.editing)
useWindowRefresh(refresh, () => !loading.value && !editingSomething.value)
const actions = computed<ActionItem[]>(() => {
  const current = item.value
  if (!current) return []
  return [
    { key: 'post', label: t(isDoubleEntry.value ? 'other_items.post' : 'other_items.confirm'), icon: 'check', tier: 'primary', variant: 'success',
      show: current.status === 'draft' && canPost.value, disabled: busy.value, run: () => { postOpen.value = true } },
    // Upravit je u nekonceptu vidět zamčené v „…“ s důvodem, stejně jako zamčené Stornovat u uhrazené
    // položky. Zamčená akce je tlačítko bez cíle: odkaz by navigoval i zašedlý.
    canEdit.value
      ? { key: 'edit', label: t('common.edit'), icon: 'edit', tier: 'secondary', variant: 'neutral', to: { name: 'other-item-edit', params: { id: id.value } } }
      : { key: 'edit', label: t('common.edit'), icon: 'edit', tier: 'overflow', variant: 'neutral', show: auth.canWrite('other_items'),
          disabled: true, disabledReason: t(`other_items.edit_locked.${current.status}`) },
    // Plány jsou vždy pohromadě v „…“ pod jedním nadpisem, nerozdělí je šířka okna.
    { key: 'schedule', label: t('other_items.plans.create_schedule'), icon: 'cycle', tier: 'overflow', group: t('other_items.plans.group'), variant: 'neutral',
      show: plansState.value.canSchedule, disabled: busy.value, run: () => plans.value?.openSchedule() },
    { key: 'installments', label: t('other_items.plans.split_action'), icon: 'calendar', tier: 'overflow', group: t('other_items.plans.group'), variant: 'neutral',
      show: plansState.value.canSplit, disabled: busy.value, run: () => plans.value?.openInstallments() },
    { key: 'reverse', label: t(isDoubleEntry.value ? 'other_items.reverse' : 'other_items.cancel'), icon: 'uturn', tier: 'overflow', variant: 'warning',
      show: (current.status === 'posted' || current.status === 'confirmed') && canPost.value,
      disabled: busy.value || Number(current.paid_amount) > 0,
      disabledReason: Number(current.paid_amount) > 0 ? t('other_items.reverse_paid') : undefined,
      run: () => { reverseReason.value = ''; reverseDate.value = appIsoDate(); reverseOpen.value = true } },
    { key: 'delete', label: t('common.delete'), icon: 'trash', tier: 'overflow', variant: 'danger',
      show: current.status === 'draft' && auth.canWrite('other_items'), disabled: busy.value, run: () => void remove() },
    { key: 'delete_posted', label: t('other_items.delete_posted'), icon: 'trash', tier: 'overflow', variant: 'danger',
      show: current.status === 'posted' && canDeletePosted.value && !!postedDeletion.value,
      disabled: busy.value || !postedDeletion.value?.allowed,
      disabledReason: postedDeletion.value && !postedDeletion.value.allowed
        ? t('other_items.delete_posted_blocked', { reason: postedDeletion.value.message || '' }) : undefined,
      run: () => { deleteOpen.value = true } },
  ]
})

watch(id, () => {
  phoneDetailsOpen.value = false
  // Jiná položka ve stejném okně: stará data, rozepsané úpravy, otevřené dialogy ani stav
  // předchozího načtení k ní nepatří (uložení by jinak zapsalo text jedné položky do druhé).
  item.value = null
  allocations.value = []
  paymentQuery.value = ''
  selectedPayment.value = null
  paymentCandidates.value = []
  searchedQuery.value = null
  candidatesLoading.value = false
  paymentRequest++
  if (paymentTimer) clearTimeout(paymentTimer)
  noteEditing.value = false
  counterpartyEditing.value = false
  accrualEditing.value = false
  accrualError.value = ''
  postOpen.value = false
  reverseOpen.value = false
  repostOpen.value = false
  deleteOpen.value = false
  notFound.value = false
  failed.value = false
  void load()
})
onMounted(load)
</script>

<template>
  <div>
    <div v-if="loading && !item" class="py-12 text-center text-sm text-neutral-500">{{ t('common.loading') }}</div>
    <EmptyState v-else-if="notFound" boxed icon="doc" :title="t('other_items.not_found')" :cta="t('other_items.back')" :to="listNav.listPath.value ?? '/other-items'" />
    <EmptyState v-else-if="!item" boxed variant="failed" @action="load" />
    <template v-else>
      <div class="max-w-5xl space-y-4" data-test="detail-layout">
      <div class="flex items-center gap-2">
        <ListNavArrows v-if="listNav.active.value" :has-previous="listNav.hasPrevious.value" :has-next="listNav.hasNext.value"
          :busy="listNav.busy.value || editingSomething" @previous="listNav.previous" @next="listNav.next" />
        <RouterLink :to="backToList" class="text-sm text-neutral-600 hover:text-neutral-900" data-test="back-to-list">{{ t('other_items.back_to_list') }}</RouterLink>
      </div>

      <!-- Záhlaví jako u přijaté faktury: číslo a odznaky v nadpisu, akce vpravo. -->
      <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between md:gap-4">
        <h1 class="flex min-w-0 flex-wrap items-center gap-3 text-2xl font-semibold" data-test="detail-heading">
          <span v-if="item.document_no" class="font-mono">{{ item.document_no }}</span>
          <span v-else class="font-mono text-neutral-400">#{{ item.id }}</span>
          <span class="rounded px-2 py-0.5 text-xs font-normal" :class="otherItemStatusClass(displayStatus)" data-test="detail-status">{{ statusLabel(displayStatus) }}</span>
          <span v-if="overdue" class="badge badge-loud rounded bg-danger-50 px-2 py-0.5 text-xs font-normal text-danger-700" data-test="detail-overdue">{{ t('other_items.status.overdue') }}</span>
          <span class="rounded bg-neutral-100 px-2 py-0.5 text-xs font-normal text-neutral-600" data-test="detail-kind">{{ t(`other_items.side.${item.side}`) }} · {{ otherItemKindLabel(t, item) }}</span>
          <!-- Jen živý zápis: u storna by štítek stál vedle stavu Stornováno (zápis i protizápis ukazuje Zaúčtování, stejně jako výpis).
               Stav „Zaúčtováno“ už text nese, štítek pak jen odkazuje na zápis; u uhrazené položky ho i pojmenuje. -->
          <PostingBadge v-if="isDoubleEntry && canReadJournal && item.status === 'posted' && item.journal_entry_id" :booked-at="item.posted_at ?? item.accounting_on" :journal-entry-id="Number(item.journal_entry_id)" :with-label="displayStatus !== 'posted'" data-test="detail-posting" />
        </h1>
        <ActionBar :actions="actions" />
      </div>

      <!-- Název a protistrana (řádek pod záhlavím, paralela s dodavatelem u přijaté faktury). -->
      <div class="min-w-0 space-y-1">
        <div class="text-lg font-semibold text-neutral-900" data-test="detail-title">{{ item.title }}</div>
        <p v-if="item.occurrence" class="flex flex-wrap items-center gap-x-1.5 gap-y-1 text-sm text-neutral-600" data-test="occurrence-source">
          <span class="inline-flex items-center gap-1 rounded bg-primary-50 px-2 py-0.5 text-xs font-medium text-primary-700">
            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.cycle" /></svg>
            {{ t('other_items.occurrence.label') }}
          </span>
          <span>{{ t('other_items.occurrence.index', { index: item.occurrence.occurrence_index }) }}</span>
          <template v-if="item.occurrence.source_item_id">
            <span>{{ t('other_items.occurrence.source') }}</span>
            <RouterLink :to="{ name: 'other-item-detail', params: { id: item.occurrence.source_item_id } }" class="font-mono text-primary-700 hover:underline" data-test="occurrence-source-link"
                :title="item.occurrence.source_title ?? undefined">{{ item.occurrence.source_document_no || `#${item.occurrence.source_item_id}` }}</RouterLink>
          </template>
          <span v-if="item.occurrence.schedule_created_at" class="text-neutral-500">· {{ t('other_items.occurrence.created_by', { name: item.occurrence.created_by_name || t('other_items.plans.unknown_user'), date: formatDate(item.occurrence.schedule_created_at) }) }}</span>
          <span v-else class="text-neutral-500" data-test="occurrence-cancelled">· {{ t('other_items.occurrence.cancelled') }}</span>
        </p>
        <div class="text-sm text-neutral-600" data-test="detail-partner">
          <span class="text-neutral-500">{{ t('other_items.partner') }}:</span>
          <span class="ml-1" :class="item.partner_name ? 'font-medium' : 'text-neutral-400'">{{ item.partner_name || t('other_items.no_partner') }}</span>
          <ClientQuickLinks v-if="item.partner_id" :client-id="Number(item.partner_id)" :vendor="item.side === 'payable'" />
        </div>
      </div>

      <p v-if="failed" role="alert" class="flex flex-wrap items-center gap-2 rounded-md border border-danger-500/40 bg-danger-50 p-3 text-sm text-danger-600">
        {{ t('other_items.load_failed_stale') }}
        <button type="button" :class="btnOutlineSm('danger')" @click="load">
          <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.cycle" /></svg>
          {{ t('common.retry') }}
        </button>
      </p>

      <template v-if="isPhone">
        <div class="space-y-3 rounded-lg border border-neutral-200 bg-surface p-4 shadow-sm" data-test="phone-summary">
          <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
              <div class="text-xs text-neutral-500">{{ t(item.has_split_installments ? 'other_items.remaining_payment' : 'other_items.remaining') }}</div>
              <div class="break-words font-mono text-2xl font-semibold text-neutral-900">{{ formatMoney(item.has_split_installments ? payableAmount : item.remaining_amount, item.currency) }}</div>
              <div class="mt-0.5 text-xs text-neutral-500">{{ t('other_items.amount') }}: <span class="font-mono">{{ formatMoney(item.amount, item.currency) }}</span></div>
            </div>
            <span v-if="paymentState" class="shrink-0 rounded px-2 py-0.5 text-xs" :class="otherItemStatusClass(paymentState)">{{ statusLabel(paymentState) }}</span>
          </div>
          <dl class="grid grid-cols-2 gap-3 border-t border-neutral-100 pt-3 text-sm">
            <div><dt class="text-xs text-neutral-500">{{ t('other_items.due_on') }}</dt><dd class="font-mono" :class="overdue ? 'font-semibold text-danger-600' : ''">{{ item.due_on ? formatDate(item.due_on) : '—' }}</dd></div>
            <div><dt class="text-xs text-neutral-500">{{ t('other_items.issued_on') }}</dt><dd class="font-mono">{{ item.issued_on ? formatDate(item.issued_on) : '—' }}</dd></div>
          </dl>
        </div>
        <button type="button" data-test="phone-details-toggle" :aria-expanded="phoneDetailsOpen"
          class="inline-flex min-h-11 w-full cursor-pointer items-center justify-between gap-2 rounded-lg border border-neutral-200 bg-surface px-4 text-sm font-medium text-neutral-700 shadow-sm"
          @click="phoneDetailsOpen = !phoneDetailsOpen">
          <span>{{ t('other_items.phone_details') }}</span>
          <svg class="h-4 w-4 transition-transform" :class="phoneDetailsOpen ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" /></svg>
        </button>
      </template>

      <!-- Tři karty jako u přijaté faktury: data, úhrada, údaje. -->
      <div v-show="!isPhone || phoneDetailsOpen" class="grid grid-cols-1 gap-4 md:grid-cols-3" data-test="detail-cards">
        <div class="rounded-lg border border-neutral-200 bg-surface p-5 shadow-sm">
          <h3 class="mb-3 text-sm font-medium text-neutral-700">{{ t('other_items.issued_on') }}<template v-if="isDoubleEntry"> / {{ t('other_items.accounting_on') }}</template> / {{ t('other_items.due_on') }}</h3>
          <dl class="space-y-2 text-sm">
            <div class="flex justify-between gap-3"><dt class="text-neutral-500">{{ t('other_items.issued_on') }}</dt><dd class="font-mono">{{ item.issued_on ? formatDate(item.issued_on) : '—' }}</dd></div>
            <div v-if="isDoubleEntry" class="flex justify-between gap-3"><dt class="text-neutral-500">{{ t('other_items.accounting_on') }}</dt><dd class="font-mono">{{ item.accounting_on ? formatDate(item.accounting_on) : '—' }}</dd></div>
            <div class="flex justify-between gap-3"><dt class="text-neutral-500">{{ t('other_items.due_on') }}</dt><dd class="font-mono" :class="overdue ? 'font-medium text-danger-500' : ''">{{ item.due_on ? formatDate(item.due_on) : '—' }}</dd></div>
          </dl>
        </div>
        <div class="rounded-lg border border-neutral-200 bg-surface p-5 shadow-sm">
          <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
            <h3 class="text-sm font-medium text-neutral-700">{{ t('other_items.payment') }}</h3>
            <span v-if="paymentState" class="rounded px-2 py-0.5 text-xs" :class="otherItemStatusClass(paymentState)" data-test="payment-state">{{ statusLabel(paymentState) }}</span>
          </div>
          <dl class="space-y-2 text-sm">
            <div class="flex justify-between gap-3"><dt class="text-neutral-500">{{ t('other_items.amount') }}</dt><dd class="font-mono">{{ formatMoney(item.amount, item.currency) }}</dd></div>
            <div class="flex justify-between gap-3"><dt class="text-neutral-500">{{ t('other_items.paid_amount') }}</dt><dd class="font-mono">{{ formatMoney(item.paid_amount, item.currency) }}</dd></div>
            <div v-if="Number(item.settled_outside_amount || 0) > 0" class="flex justify-between gap-3 pl-3" :title="t('other_items.settled_outside_hint')"><dt class="text-neutral-500">{{ t('other_items.settled_outside') }}</dt><dd class="font-mono text-neutral-500">{{ formatMoney(item.settled_outside_amount ?? 0, item.currency) }}</dd></div>
            <div class="flex justify-between gap-3 border-t border-neutral-200 pt-2 font-semibold"><dt>{{ t(item.has_split_installments ? 'other_items.remaining_principal' : 'other_items.remaining') }}</dt><dd class="font-mono">{{ formatMoney(item.remaining_amount, item.currency) }}</dd></div>
            <div v-if="item.has_split_installments" class="flex justify-between gap-3" :title="t('other_items.remaining_payment_hint')" data-test="remaining-payment"><dt class="text-neutral-500">{{ t('other_items.remaining_payment') }}</dt><dd class="font-mono">{{ formatMoney(payableAmount, item.currency) }}</dd></div>
          </dl>
        </div>
        <div class="rounded-lg border border-neutral-200 bg-surface p-5 shadow-sm">
          <h3 class="mb-3 text-sm font-medium text-neutral-700">{{ t('other_items.details') }}</h3>
          <dl class="space-y-2 text-sm">
            <div class="flex justify-between gap-3"><dt class="text-neutral-500">{{ t('other_items.variable_symbol') }}</dt><dd class="font-mono">{{ item.variable_symbol || '—' }}</dd></div>
            <div v-if="item.currency !== 'CZK'" class="flex justify-between gap-3"><dt class="text-neutral-500">{{ t('other_items.exchange_rate') }}</dt><dd class="font-mono">{{ item.exchange_rate || '—' }}</dd></div>
            <div v-if="item.linked_invoice" class="flex justify-between gap-3" data-test="linked-invoice">
              <dt class="text-neutral-500">{{ t('other_items.linked_invoice') }}</dt>
              <dd><RouterLink :to="`/invoices/${item.linked_invoice.id}`" class="font-mono text-primary-700 hover:underline">{{ item.linked_invoice.varsymbol || `#${item.linked_invoice.id}` }}</RouterLink></dd>
            </div>
            <div>
              <div class="flex justify-between gap-3">
                <dt class="text-neutral-500">{{ t('other_items.counterparty_account') }}</dt>
                <dd v-if="!counterpartyEditing" class="flex flex-wrap items-center justify-end gap-1">
                  <span class="font-mono" data-test="counterparty-account">{{ formatAccountNumber(item.counterparty_account, item.counterparty_bank_code) || '—' }}</span>
                  <button v-if="canEditCounterparty" type="button" data-test="counterparty-edit" class="inline-flex h-6 w-6 items-center justify-center rounded text-neutral-400 hover:bg-neutral-100 hover:text-primary-700"
                    :title="`${t('common.edit')}: ${t('other_items.counterparty_account')}`" :aria-label="`${t('common.edit')}: ${t('other_items.counterparty_account')}`" @click="startCounterpartyEdit">
                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.edit" /></svg>
                  </button>
                </dd>
              </div>
              <div v-if="counterpartyEditing" class="mt-2">
                <form class="flex flex-wrap items-center gap-2" data-test="counterparty-form" @submit.prevent="saveCounterparty">
                  <input v-model="counterpartyInput" type="text" maxlength="60" :aria-label="t('other_items.counterparty_account')" :placeholder="t('other_items.counterparty_account_placeholder')" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-2 font-mono text-sm" />
                  <button type="submit" :disabled="busy || loading" :class="btnFilled('primary')" class="whitespace-nowrap" data-test="counterparty-save">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
                    {{ t('common.save') }}
                  </button>
                  <button type="button" :class="btnOutline('neutral')" class="whitespace-nowrap" @click="counterpartyEditing = false">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>
                    {{ t('common.cancel') }}
                  </button>
                </form>
                <p class="mt-1 text-xs text-neutral-500">{{ t('other_items.counterparty_edit_hint') }}</p>
              </div>
            </div>
            <div v-if="showAccrual" data-test="accrual">
              <div class="flex justify-between gap-3">
                <dt class="text-neutral-500">{{ t(`other_items.accrual.label_${item.side}`) }}</dt>
                <dd v-if="!accrualEditing" class="flex flex-wrap items-center justify-end gap-1">
                  <span :class="{ 'font-mono': item.accrual_from && item.accrual_to }" data-test="accrual-period">{{ item.accrual_from && item.accrual_to
                    ? t('other_items.accrual.period', { from: formatDate(item.accrual_from), to: formatDate(item.accrual_to) })
                    : t('other_items.accrual.none') }}</span>
                  <button v-if="canEditAccrual" type="button" data-test="accrual-edit" class="inline-flex h-6 w-6 items-center justify-center rounded text-neutral-400 hover:bg-neutral-100 hover:text-primary-700"
                    :title="`${t('common.edit')}: ${t(`other_items.accrual.label_${item.side}`)}`" :aria-label="`${t('common.edit')}: ${t(`other_items.accrual.label_${item.side}`)}`" @click="startAccrualEdit">
                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.edit" /></svg>
                  </button>
                  <button v-if="canClearAccrual" type="button" data-test="accrual-clear" :disabled="busy" class="inline-flex h-6 w-6 items-center justify-center rounded text-neutral-400 hover:bg-neutral-100 hover:text-danger-600"
                    :title="t('other_items.accrual.clear')" :aria-label="t('other_items.accrual.clear')" @click="clearAccrual">
                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>
                  </button>
                </dd>
              </div>
              <FieldNote v-if="!accrualEditing" :error="accrualError" />
              <div v-if="accrualEditing" class="mt-2">
                <form class="space-y-2" data-test="accrual-form" @submit.prevent="saveAccrual">
                  <div class="grid grid-cols-2 gap-2">
                    <label class="text-xs text-neutral-600">{{ t('accrual_period.from') }}
                      <DateInput v-model="accrualFrom" :aria-invalid="!!accrualError" :class="['mt-1 block', formDateInput, accrualError ? 'border-danger-500' : '']" data-test="accrual-from" />
                    </label>
                    <label class="text-xs text-neutral-600">{{ t('accrual_period.to') }}
                      <DateInput v-model="accrualTo" :aria-invalid="!!accrualError" :class="['mt-1 block', formDateInput, accrualError ? 'border-danger-500' : '']" data-test="accrual-to" />
                    </label>
                  </div>
                  <p class="text-xs text-neutral-500" data-test="accrual-hint">{{ t(`other_items.accrual.hint_${item.side}`) }}</p>
                  <FieldNote :error="accrualError" />
                  <div class="flex flex-wrap items-center gap-2">
                    <button type="submit" :disabled="busy" :class="btnFilled('primary')" class="whitespace-nowrap">
                      <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
                      {{ t('common.save') }}
                    </button>
                    <button type="button" :class="btnOutline('neutral')" class="whitespace-nowrap" @click="closeAccrualEdit">
                      <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>
                      {{ t('common.cancel') }}
                    </button>
                  </div>
                </form>
              </div>
            </div>
          </dl>
        </div>
      </div>

      <DocumentPostingPanel v-if="isDoubleEntry && canReadJournal" v-show="!isPhone || phoneDetailsOpen" ref="postingPanel" source="other-items" :doc-id="id" :doc-label="item.document_no"
                            :external-repost="externalRepost" @repost="openRepost">
        <LinkedDocumentsPanel v-if="auth.canRead('documents') && item.journal_entry_id" :key="Number(item.journal_entry_id)"
          entity-type="journal_entry" :entity-id="Number(item.journal_entry_id)" readonly collapsible
          :title="t('other_items.journal_documents')" />
      </DocumentPostingPanel>
      <!-- Pořadí jako u přijaté faktury: dimenze za Zaúčtováním. Zůstávají rozbalené, chybějící povinnou
           dimenzi (u faktury otevírá panel) detail položky nezná. -->
      <DocumentDimensionsPanel v-show="!isPhone || phoneDetailsOpen" doc-type="other-items" :doc-id="id" :readonly="item.status === 'cancelled'"
                               :prefill="{ client_id: item.partner_id ?? null }" @saved="postingPanel?.reload()" />
      <OtherItemPostingRules v-show="!isPhone || phoneDetailsOpen" v-if="isDoubleEntry && item.posting_rules?.payment_bank" :item="item" :accounts="postingAccounts"
                             :can-write="auth.canWrite('other_items')" :show-posted-origin="!canReadJournal"
                             :can-repost="canRepost && !canReadJournal" @changed="item = $event" @repost="openRepost" />
      <section class="rounded-lg border border-neutral-200 bg-surface p-5 shadow-sm" data-test="payments-card">
        <h3 class="mb-3 text-sm font-medium text-neutral-700">{{ t('other_items.payments') }}</h3>
        <p v-if="isTaxEvidence && allocations.some(allocation => !allocation.reversed_on)" class="mb-3 text-sm text-warning-700">
          {{ t('other_items.tax_classification_hint') }}
          <RouterLink v-if="auth.canRead('tax_evidence')" :to="{ name: 'tax-evidence-cash-journal' }" class="font-medium underline">{{ t('other_items.open_cash_journal') }}</RouterLink>
        </p>
        <div v-if="allocations.length" class="divide-y divide-neutral-100 text-sm">
          <div v-for="allocation in allocations" :key="allocation.id" class="flex flex-wrap items-center justify-between gap-2 py-2" data-test="allocation">
            <div class="min-w-0">
              <template v-if="allocation.bank_transaction_id">
                <RouterLink v-if="allocation.bank_statement_id" :to="{ name: 'bank-detail', params: { id: allocation.bank_statement_id } }" class="font-medium text-primary-700 hover:underline" data-test="allocation-source">{{ t('other_items.bank_payment') }} #{{ allocation.bank_transaction_id }}</RouterLink>
                <span v-else class="font-medium">{{ t('other_items.bank_payment') }} #{{ allocation.bank_transaction_id }}</span>
              </template>
              <RouterLink v-else-if="allocation.cash_document_id" :to="cashDocumentLink(allocation.cash_document_id)" class="font-medium text-primary-700 hover:underline" data-test="allocation-source">{{ t('other_items.cash_payment') }} {{ allocation.cash_doc_number || `#${allocation.cash_document_id}` }}</RouterLink>
              <span class="ml-2 text-neutral-500">{{ formatDate(allocation.payment_on) }}</span>
              <RouterLink v-if="allocation.journal_entry_id" :to="journalEntryLink(allocation.journal_entry_id)" class="ml-2 text-xs text-primary-700 hover:underline" data-test="allocation-journal">{{ t('other_items.open_journal') }}</RouterLink>
              <span v-if="allocation.auto_match_reason" class="ml-2 inline-flex rounded bg-primary-50 px-1.5 py-0.5 text-xs text-primary-700" data-test="allocation-auto" :title="t(`other_items.auto_match_reason.${allocation.auto_match_reason}`)">{{ t('other_items.auto_matched') }}</span>
              <span v-if="allocation.reversed_on" class="ml-2 text-warning-700">{{ t('other_items.payment_reversed_on', { date: formatDate(allocation.reversed_on) }) }}</span>
            </div>
            <div class="flex flex-wrap items-center gap-3">
              <span v-if="Number(allocation.interest_amount || 0) + Number(allocation.fee_amount || 0) > 0" class="text-xs tabular-nums text-neutral-500" data-test="allocation-split">
                {{ t('other_items.allocation_split', { principal: formatMoney(allocation.amount, item.currency), interest: formatMoney(allocation.interest_amount ?? 0, item.currency), fee: formatMoney(allocation.fee_amount ?? 0, item.currency) }) }}
              </span>
              <strong class="tabular-nums" :class="allocation.reversed_on ? 'line-through text-neutral-500' : ''">{{ formatMoney(allocation.total_amount ?? allocation.amount, item.currency) }}</strong>
              <button v-if="auth.canWrite('other_items') && (allocation.bank_transaction_id ? canManageBankPayment : canManageCashPayment)" type="button" :disabled="busy"
                :class="[btnOutlineSm('warning'), 'whitespace-nowrap']" data-test="allocation-unlink" @click="unallocate(allocation)">
                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>
                {{ t('other_items.unlink_payment') }}
              </button>
            </div>
          </div>
        </div>
        <p v-else class="text-sm text-neutral-500">{{ t('other_items.no_payments') }}</p>
        <form v-if="canAllocate" class="mt-4 border-t border-neutral-200 pt-4" @submit.prevent="allocate">
          <h3 class="mb-2 text-sm font-semibold">{{ t('other_items.link_payment') }}</h3>
          <div v-if="!selectedPayment" class="relative max-w-xl">
            <label class="block text-sm font-medium">{{ t('other_items.search_payment') }}
              <input ref="paymentInput" v-model="paymentQuery" type="search" class="mt-1 block h-9 w-full rounded-md border border-neutral-300 bg-surface px-2" @focus="searchPayments(paymentQuery)" />
            </label>
            <div v-if="candidatesLoading" class="mt-2 text-xs text-neutral-500">{{ t('common.loading') }}</div>
            <p v-else-if="searchedQuery !== null && searchedQuery === paymentQuery.trim() && !paymentCandidates.length" class="mt-2 text-sm text-neutral-500" data-test="no-payment-candidates">
              {{ t(searchedQuery ? 'other_items.no_payment_candidates_search' : 'other_items.no_payment_candidates') }}
            </p>
            <div v-if="paymentCandidates.length" class="mt-2 max-h-52 overflow-y-auto rounded-md border border-neutral-200">
              <button v-for="candidate in paymentCandidates" :key="`${candidate.source}:${candidate.id}`" type="button" class="flex w-full flex-wrap items-center justify-between gap-2 border-b border-neutral-100 px-3 py-2 text-left text-sm hover:bg-neutral-50" @click="selectPayment(candidate)">
                <span>
                  <span class="font-medium">{{ t(candidate.source === 'bank' ? 'other_items.bank_payment' : 'other_items.cash_payment') }} #{{ candidate.id }}</span><span class="ml-2 text-neutral-500">{{ formatDate(candidate.payment_on) }} · {{ candidate.description }}</span>
                  <span v-if="matchLabels(candidate).length" class="mt-1 flex flex-wrap gap-1" data-test="match-reasons">
                    <span v-for="label in matchLabels(candidate)" :key="label" class="rounded bg-success-50 px-1.5 py-0.5 text-[10px] font-semibold uppercase text-success-700">{{ label }}</span>
                  </span>
                </span>
                <strong class="tabular-nums">{{ formatMoney(Math.abs(candidate.amount), candidate.currency) }}</strong>
              </button>
            </div>
          </div>
          <div v-else class="flex flex-wrap items-end gap-3">
            <div class="text-sm"><span class="font-medium">{{ t(selectedPayment.source === 'bank' ? 'other_items.bank_payment' : 'other_items.cash_payment') }} #{{ selectedPayment.id }}</span><div class="text-neutral-500">{{ formatDate(selectedPayment.payment_on) }} · {{ selectedPayment.description }}</div></div>
            <label class="text-sm font-medium">{{ t('other_items.allocation_amount') }}
              <input v-model.number="allocationAmount" required type="number" min="0.01" step="0.01" :max="Math.min(Math.abs(selectedPayment.amount), payableAmount)" class="mt-1 block h-9 w-36 rounded-md border border-neutral-300 bg-surface px-2 text-right" />
            </label>
            <button type="submit" :disabled="busy" :class="btnFilled('success')">
              <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.link" /></svg>
              {{ t('other_items.link_payment') }}
            </button>
            <button type="button" :class="btnOutline('neutral')" @click="selectedPayment = null">
              <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>
              {{ t('common.cancel') }}
            </button>
            <p v-if="item.has_split_installments" class="w-full text-xs text-neutral-500">{{ t('other_items.allocation_split_hint') }}</p>
          </div>
        </form>
        <p v-else-if="item.currency !== 'CZK'" class="mt-3 text-sm text-neutral-500">{{ t('other_items.foreign_payment_hint') }}</p>
      </section>
      <OtherItemPlans ref="plans" :item="item" @state="plansState = $event" />
      <div class="rounded-lg border border-neutral-200 bg-surface p-5 shadow-sm" data-test="note-card">
        <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
          <h3 class="text-sm font-medium text-neutral-700">{{ t('other_items.note') }}</h3>
          <button v-if="canEditNote && !noteEditing" type="button" data-test="note-edit" :class="btnOutlineSm('neutral')" class="whitespace-nowrap" @click="startNoteEdit">
            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.edit" /></svg>
            {{ t('other_items.edit_note') }}
          </button>
        </div>
        <p v-if="!noteEditing" class="whitespace-pre-wrap text-sm" :class="item.note ? 'text-neutral-800' : 'text-neutral-400'" data-test="note">{{ item.note || '—' }}</p>
        <div v-else>
          <form class="space-y-2" data-test="note-form" @submit.prevent="saveNote">
            <textarea v-model="noteInput" maxlength="10000" rows="4" :aria-label="t('other_items.note')" class="block w-full rounded-md border border-neutral-300 bg-surface p-2 text-sm" />
            <div class="flex flex-wrap items-center gap-2">
              <button type="submit" :disabled="busy" :class="btnFilled('primary')" class="whitespace-nowrap">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
                {{ t('common.save') }}
              </button>
              <button type="button" :class="btnOutline('neutral')" class="whitespace-nowrap" @click="noteEditing = false">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>
                {{ t('common.cancel') }}
              </button>
            </div>
          </form>
          <p class="mt-1 text-xs text-neutral-500">{{ t('other_items.edit_note_hint') }}</p>
        </div>
      </div>
      <LinkedDocumentsPanel v-if="auth.canRead('documents')" class="block" entity-type="other_item" :entity-id="id" uploadable :upload-folder-path="documentFolderPath" :title="t('linked_documents.title')" />
      </div>
    </template>
    <Modal v-if="postOpen && item" :title="t(isDoubleEntry ? 'other_items.post' : 'other_items.confirm')" width-class="max-w-lg" @close="postOpen = false">
      <div class="space-y-3 text-sm" data-test="post-confirm">
        <p class="text-neutral-700">{{ t(isDoubleEntry ? 'other_items.confirm_post' : 'other_items.confirm_confirm') }}</p>
        <div v-if="isDoubleEntry && originPreview" class="rounded-md border border-neutral-200 bg-neutral-50 p-3" data-test="post-preview">
          <div class="text-xs font-medium text-neutral-500">{{ t('other_items.post_preview') }}</div>
          <ul class="mt-1 space-y-0.5 tabular-nums">
            <li v-for="(line, index) in originPreview.lines || []" :key="index" class="flex flex-wrap gap-2">
              <span class="w-8 text-neutral-500">{{ t(line.side === 'debit' ? 'other_items.posting.debit' : 'other_items.posting.credit') }}</span>
              <span class="font-mono">{{ line.account_code }}</span>
              <span class="text-neutral-500">{{ formatMoney(line.amount, 'CZK') }}</span>
            </li>
          </ul>
          <p v-if="postMissingCounter" id="other-item-post-missing-counter" class="mt-1 text-warning-700" data-test="post-missing-counter">{{ t('other_items.posting.missing_counter') }}</p>
        </div>
      </div>
      <template #footer>
        <div class="flex flex-wrap justify-end gap-2">
          <button type="button" :class="btnOutline('neutral')" class="whitespace-nowrap" @click="postOpen = false">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>
            {{ t('common.cancel') }}
          </button>
          <button type="button" :disabled="busy || postMissingCounter" :class="btnFilled('success')" class="whitespace-nowrap" data-test="post-confirm-submit"
            :title="postMissingCounter ? t('other_items.posting.missing_counter') : undefined"
            :aria-describedby="postMissingCounter ? 'other-item-post-missing-counter' : undefined" @click="post">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
            {{ t(isDoubleEntry ? 'other_items.post' : 'other_items.confirm') }}
          </button>
        </div>
      </template>
    </Modal>
    <Modal v-if="deleteOpen && item" :title="t('other_items.delete_posted_title')" width-class="max-w-lg" @close="deleteOpen = false">
      <div class="space-y-3 text-sm">
        <p class="text-neutral-700">{{ t('other_items.delete_posted_intro', { number: item.document_no || '' }) }}</p>
        <p class="text-neutral-600">{{ t('other_items.delete_posted_rules') }}</p>
        <p v-if="postedDeletion?.needs_ack" class="rounded-md border border-warning-500/40 bg-warning-50 p-3 text-warning-700">{{ t('other_items.delete_posted_locked') }}</p>
      </div>
      <template #footer>
        <div class="flex flex-wrap justify-end gap-2">
          <button type="button" :class="btnOutline('neutral')" class="whitespace-nowrap" @click="deleteOpen = false">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>
            {{ t('common.cancel') }}
          </button>
          <button type="button" :disabled="busy" :class="btnFilled('danger')" class="whitespace-nowrap" data-test="delete-posted-confirm" @click="removePosted">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.trash" /></svg>
            {{ t('other_items.delete_posted') }}
          </button>
        </div>
      </template>
    </Modal>
    <LockedPeriodAckModal :ref="(el: any) => { lockedAck.modal.value = el }" />
    <Modal v-if="reverseOpen" :title="t(isDoubleEntry ? 'other_items.reverse' : 'other_items.cancel')" width-class="max-w-lg" @close="reverseOpen = false">
      <form id="other-item-reverse" class="space-y-4" @submit.prevent="reverse">
        <p class="text-sm text-neutral-600">{{ t(isDoubleEntry ? 'other_items.confirm_reverse' : 'other_items.confirm_cancel') }}</p>
        <label class="block text-sm font-medium">{{ t('other_items.reverse_reason') }}
          <textarea v-model="reverseReason" required minlength="3" maxlength="500" rows="3" class="mt-1 block w-full rounded-md border border-neutral-300 bg-surface p-2" />
        </label>
        <label v-if="isDoubleEntry" class="block text-sm font-medium">{{ t('other_items.reverse_date') }}
          <DateInput v-model="reverseDate" class="mt-1 block h-9 w-full rounded-md border border-neutral-300 px-2" />
        </label>
      </form>
      <template #footer>
        <div class="flex flex-wrap justify-end gap-2">
          <button type="button" :class="btnOutline('neutral')" @click="reverseOpen = false">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>
            {{ t('common.cancel') }}
          </button>
          <button type="submit" form="other-item-reverse" :disabled="busy" :class="btnFilled('warning')">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.uturn" /></svg>
            {{ t(isDoubleEntry ? 'other_items.reverse' : 'other_items.cancel') }}
          </button>
        </div>
      </template>
    </Modal>
    <Modal v-if="repostOpen" :title="t('other_items.repost')" width-class="max-w-xl" @close="repostOpen = false">
      <form id="other-item-repost" class="space-y-4" @submit.prevent="repost">
        <p class="text-sm text-neutral-600">{{ t('other_items.repost_hint') }}</p>
        <div class="rounded-md border border-neutral-200 bg-neutral-50 p-3 text-sm">
          <div class="text-xs font-medium text-neutral-500">{{ t('other_items.repost_current') }}</div>
          <div class="mt-1 font-medium">{{ item?.account_code || '–' }} / {{ item?.posting_lines?.length && item.posting_lines.length > 1 ? t('other_items.posting_line_count', { count: item.posting_lines.length }) : item?.counter_account_code || item?.posting_lines?.[0]?.account_code || '–' }} · {{ item ? formatMoney(item.amount, item.currency) : '' }}</div>
          <ul v-if="item?.posting_lines && item.posting_lines.length > 1" class="mt-2 space-y-1">
            <li v-for="(line, index) in item.posting_lines" :key="index" class="flex justify-between gap-3"><span>{{ line.account_code }}</span><span class="tabular-nums">{{ formatMoney(line.amount, item.currency) }}</span></li>
          </ul>
        </div>
        <label class="block text-sm font-medium">{{ t('other_items.account_code') }}
          <ChartAccountSelect v-model="repostAccountCode" :accounts="repostBalanceAccounts" :placeholder="t('other_items.choose_account')" class="mt-1 block" />
        </label>
        <label v-if="!repostSplit" class="block text-sm font-medium">{{ t('other_items.counter_account_code') }}
          <ChartAccountSelect v-model="repostCounterAccountCode" :accounts="repostAccountChoices" :placeholder="t('other_items.choose_account')" class="mt-1 block" />
        </label>
        <OtherItemPostingLines v-else v-model="repostLines" :accounts="repostAccountChoices" :total="Number(item?.amount || 0)" />
        <button v-if="!repostSplit" type="button" :class="btnOutline('neutral')" class="whitespace-nowrap" @click="startRepostSplit">
          <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
          {{ t('other_items.split_posting') }}
        </button>
        <button v-else type="button" :class="btnOutline('neutral')" class="whitespace-nowrap" @click="stopRepostSplit">
          <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>
          {{ t('other_items.single_posting') }}
        </button>
        <label class="block text-sm font-medium">{{ t('other_items.repost_date') }}
          <DateInput v-model="repostDate" class="mt-1 block h-9 w-full rounded-md border border-neutral-300 px-2" />
        </label>
        <label class="block text-sm font-medium">{{ t('other_items.repost_reason') }}
          <textarea v-model="repostReason" required minlength="3" maxlength="500" rows="3" class="mt-1 block w-full rounded-md border border-neutral-300 bg-surface p-2" />
        </label>
      </form>
      <template #footer>
        <div class="flex flex-wrap justify-end gap-2">
          <button type="button" :class="btnOutline('neutral')" @click="repostOpen = false">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>
            {{ t('common.cancel') }}
          </button>
          <button type="submit" form="other-item-repost" :disabled="busy" :class="btnFilled('warning')">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.edit" /></svg>
            {{ t('other_items.repost') }}
          </button>
        </div>
      </template>
    </Modal>
  </div>
</template>

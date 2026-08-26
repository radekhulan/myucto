<script setup lang="ts">
/**
 * Firma → Datová schránka.
 *
 * Průřezový kanál podání: DPH, kontrolní i souhrnné hlášení, DPPO, přehledy
 * zdravotním pojišťovnám. Ne mzdová odbočka.
 *
 * ── Dvě věci, které musí být na první pohled vidět ──────────────────────────
 * 1. **„Doručeno" není „zpracováno".** Datovka vrací doručenku, tedy důkaz
 *    o doručení. Stav podání proto ukazujeme dvěma odznaky vedle sebe —
 *    dopravu a vyřízení — a nikdy je neslučujeme do jednoho.
 * 2. **Vybírání schránky je právní úkon.** Vyzvednutí zprávy ji doručí
 *    (§ 17 odst. 3 zák. 300/2008 Sb.) a rozjede lhůty, takže se zapíná
 *    vědomě, s vysvětlením, a ne přepínačem bez kontextu.
 */
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { isAxiosError } from 'axios'
import {
  dataBoxApi,
  type AcceptanceState,
  type DataBoxCredential,
  type DataBoxArchiveFolder,
  type DataBoxInboxStorageSetting,
  type DefectGround,
  type DefectNotice,
  type DeliveryBasis,
  type DispatchState,
  type GatewaySessionState,
  type GatewayStart,
  type IsdsGatewayCapability,
  type IsdsMobileCredentialProfile,
  type InboxMessage,
  type InboxPollState,
  type OutboxAttempt,
  type OutboxSubmission,
  type ReceiptCandidate,
  type ReceiptUploadResult,
  type RecipientKind,
  type SubmissionRecipient,
} from '@/api/dataBox'
import { apiErrorMessage } from '@/api/errors'
import { formatUtcDateTime } from '@/composables/useFormat'
import { useAutoSlug } from '@/composables/useAutoSlug'
import { useToast } from '@/composables/useToast'
import { useSupplierStore } from '@/stores/supplier'
import { ICONS, btnFilled, btnOutline, btnOutlineSm } from '@/components/ui/buttonStyles'
import EmptyState from '@/components/ui/EmptyState.vue'

const { t } = useI18n()
const toast = useToast()
const supplierStore = useSupplierStore()

type Tab = 'access' | 'outbox' | 'inbox' | 'notices' | 'recipients'
const tab = ref<Tab>('access')
const environment = ref<'production' | 'test'>('production')
const loading = ref(true)
const saving = ref(false)
const busyId = ref<number | null>(null)
const privacyBusyId = ref<number | null>(null)

const credentials = ref<DataBoxCredential[]>([])
const recipients = ref<SubmissionRecipient[]>([])
const outbox = ref<OutboxSubmission[]>([])
const inbox = ref<InboxMessage[]>([])
const inboxVisibility = ref<'active' | 'hidden'>('active')
const pollState = ref<InboxPollState | null>(null)
const inboxStorageItems = ref<DataBoxInboxStorageSetting[]>([])
const inboxArchiveFolders = ref<DataBoxArchiveFolder[]>([])
const selectedInboxArchiveFolderId = ref<number | ''>('')
const attempts = ref<Record<number, OutboxAttempt[]>>({})
const expanded = ref<number | null>(null)

// ── Ruční cesta: člověk odešle, člověk přinese doručenku ─────────────────────
// Strojový transport do ISDS nasazený není, takže tohle není nouzový režim,
// ale běžný provoz. UI proto nesmí jen konstatovat stav — musí říct, co udělat.
const unmatchedReceipts = ref<InboxMessage[]>([])
const receiptCandidates = ref<Record<number, ReceiptCandidate[]>>({})
const lastUpload = ref<ReceiptUploadResult | null>(null)
const receiptInput = ref<HTMLInputElement | null>(null)
const uploadTargetId = ref<number | null>(null)
const markSentFor = ref<number | null>(null)
const markSentMessageId = ref('')

// ── Přístup konkrétní firmy ──────────────────────────────────────────────────
// Odesílací brána nechává volbu přihlášení na oficiální stránce ISDS. Uložený
// systémový certifikát je samostatná možnost pro serverové operace této firmy.
const certLabel = ref('')
const certBoxId = ref('')
const certPassword = ref('')
const certFile = ref<File | null>(null)
const certFileInput = ref<HTMLInputElement | null>(null)

const currentCredential = computed(
  () => credentials.value.find(c => c.environment === environment.value) ?? null,
)

const currentInboxStorage = computed(
  () => inboxStorageItems.value.find(item => item.environment === environment.value) ?? null,
)

const inboxArchiveFolderOptions = computed(() => {
  const byId = new Map(inboxArchiveFolders.value.map(folder => [folder.id, folder]))
  return inboxArchiveFolders.value.map(folder => {
    const path = [folder.name]
    let parentId = folder.parent_id
    const visited = new Set<number>([folder.id])
    while (parentId !== null && !visited.has(parentId)) {
      visited.add(parentId)
      const parent = byId.get(parentId)
      if (!parent) break
      path.unshift(parent.name)
      parentId = parent.parent_id
    }
    return { id: folder.id, label: path.join(' / ') }
  }).sort((a, b) => a.label.localeCompare(b.label, undefined, { sensitivity: 'base' }))
})

// ── Jednorázové ruční načtení inboxu ────────────────────────────────────────
// Tohle není plánovač ani trvalé povolení. Volba přihlášení je na kartě stále
// viditelná; uživatel potvrdí právní účinek a spustí právě jeden síťový dotaz.
type InboxAuthMethod = 'mobile_key' | 'password' | 'sms' | 'certificate'
const inboxAuthMethod = ref<InboxAuthMethod>('mobile_key')
const inboxAcknowledged = ref(false)
const inboxUsername = ref('')
const inboxPassword = ref('')
const mobileCommunicationCode = ref('')
const mobileFlowToken = ref('')
const mobileStatus = ref('')
const savedMobileCredential = ref<IsdsMobileCredentialProfile | null>(null)
const rememberMobileCredential = ref(false)
const smsFlowToken = ref('')
const smsCode = ref('')
const smsStatus = ref('')
let mobileStatusTimer: ReturnType<typeof setTimeout> | null = null

// ── Recipient form ───────────────────────────────────────────────────────────
const recipientCode = ref('')
const recipientName = ref('')
const recipientBusinessId = ref('')
const recipientAddress = ref('')
const recipientKind = ref<RecipientKind>('tax_office')
const recipientBoxId = ref('')
const recipientSource = ref('')
const recipientEditing = ref(false)
const recipientEditingDefault = ref(false)
const recipientCodeSlug = useAutoSlug(value => { recipientCode.value = value }, { maxLen: 48 })

const recipientsWithoutBox = computed(() => recipients.value.filter(r => !r.has_box_id))

// ── Výzvy k odstranění vad (§ 74 daňového řádu) ──────────────────────────────
// Aplikace výzvy z došlých zpráv sama nerozpoznává — úřad naši spisovou značku
// opakovat nemusí a výzva přijde jako běžná zpráva pro člověka. Eviduje je
// proto uživatel, a UI o tom musí mluvit nahlas: prázdný seznam tady znamená
// „žádná zaevidovaná", ne „žádná nepřišla".
const notices = ref<DefectNotice[]>([])
const noticesSupported = ref(true)
const noticesHint = ref('')
const noticeForm = ref({
  inbox_message_id: null as number | null,
  outbox_id: null as number | null,
  notice_reference: '',
  defect_ground: 'unknown' as DefectGround,
  delivered_on: '',
  respond_by_on: '',
  stated_period_days: null as number | null,
  note: '',
})
const answerFor = ref<number | null>(null)
const answerDate = ref('')

const noticesNeedingAttention = computed(
  () => notices.value.filter(n => n.assessment.needs_attention).length,
)

/**
 * Barva podle toho, co uživateli hrozí — ne podle toho, jak stav zní.
 * „Nevíme" dostává varovnou, ne neutrální: neznalost lhůty je problém,
 * který někdo musí dořešit, ne klidový stav.
 */
function noticeTone(notice: DefectNotice): string {
  switch (notice.assessment.outcome) {
    case 'ineffective': return 'bg-danger-50 text-danger-700 dark:bg-danger-900/30 dark:text-danger-200'
    case 'penalty_risk': return 'bg-warning-50 text-warning-700 dark:bg-warning-900/30 dark:text-warning-200'
    case 'cured': return 'bg-success-50 text-success-700 dark:bg-success-900/30 dark:text-success-200'
    default: return notice.assessment.needs_attention
      ? 'bg-warning-50 text-warning-700 dark:bg-warning-900/30 dark:text-warning-200'
      : 'bg-neutral-100 text-neutral-600'
  }
}

/** Doručení: běžící lhůta je jiný stav než „nevíme" a nesmí splynout. */
function deliveryTone(basis: DeliveryBasis | undefined): string {
  switch (basis) {
    case 'login':
    case 'login_or_fiction': return 'bg-success-50 text-success-700 dark:bg-success-900/30 dark:text-success-200'
    case 'fiction': return 'bg-primary-50 text-primary-700 dark:bg-primary-900/30 dark:text-primary-200'
    case 'pending': return 'bg-warning-50 text-warning-700 dark:bg-warning-900/30 dark:text-warning-200'
    default: return 'bg-neutral-100 text-neutral-600'
  }
}

/**
 * Doprava a vyřízení jako dva NEZÁVISLÉ odznaky.
 *
 * Kdyby to byl jeden štítek, „doručeno" by nutně splynulo se „zpracováno" —
 * a přesně na téhle záměně projekt už jednou doplatil.
 */
function dispatchTone(state: DispatchState): string {
  switch (state) {
    case 'ready': return 'bg-neutral-100 text-neutral-700'
    case 'sending': return 'bg-primary-50 text-primary-700 dark:bg-primary-900/30 dark:text-primary-200'
    case 'send_uncertain': return 'bg-warning-50 text-warning-700 dark:bg-warning-900/30 dark:text-warning-200'
    case 'sent': return 'bg-primary-50 text-primary-700 dark:bg-primary-900/30 dark:text-primary-200'
    case 'delivered': return 'bg-success-50 text-success-700 dark:bg-success-900/30 dark:text-success-200'
    case 'failed': return 'bg-danger-50 text-danger-700 dark:bg-danger-900/30 dark:text-danger-200'
    default: return 'bg-neutral-100 text-neutral-600'
  }
}

function acceptanceTone(state: AcceptanceState): string {
  switch (state) {
    case 'accepted': return 'bg-success-50 text-success-700 dark:bg-success-900/30 dark:text-success-200'
    case 'rejected': return 'bg-danger-50 text-danger-700 dark:bg-danger-900/30 dark:text-danger-200'
    // `unknown` je u datovky legitimní KONCOVÝ stav, ne mezistupeň — proto
    // neutrální šeď, ne varovná barva. Není to porucha, je to fakt.
    default: return 'bg-neutral-100 text-neutral-600'
  }
}

/**
 * Právě jedna plná primární akce podle stavu — zbytek outline.
 *
 * U datovky závisí na tom, jestli je zapnutá odesílací brána. Je-li, primární
 * akcí je „připravit v datové schránce" — zprávu pořád odesílá člověk, ale
 * aplikace mu ji připraví jako koncept a stačí ji schválit. Není-li (nebo
 * o tom nevíme), zůstává primární „označit jako odesláno": nabízet jako hlavní
 * krok tlačítko, které skončí překážkou, by uživatele posílalo do zdi.
 */
function primaryAction(row: OutboxSubmission): 'gateway' | 'confirm' | 'resolve' | 'markSent' | 'uploadReceipt' | null {
  if (row.dispatch_state === 'send_uncertain' || row.dispatch_state === 'sending') return 'resolve'
  if (canUseGateway(row)) return 'gateway'
  if (row.dispatch_state === 'ready') return row.channel === 'isds' ? 'markSent' : 'confirm'
  if (row.dispatch_state === 'sent' && row.channel === 'isds' && !row.receipt_document_id) return 'uploadReceipt'
  return null
}

/** Ukazuje se u připraveného ISDS podání: konkrétní postup, ne obecná nápověda. */
function needsManualSteps(row: OutboxSubmission): boolean {
  return row.channel === 'isds' && row.dispatch_state === 'ready'
}

async function loadAll() {
  loading.value = true
  try {
    const storagePromise = typeof dataBoxApi.inboxStorage === 'function'
      ? dataBoxApi.inboxStorage().catch(() => ({ items: [], folders: [] }))
      : Promise.resolve({ items: [], folders: [] })
    const [creds, recips, out, inb, unmatched, mobileProfile, storage] = await Promise.all([
      dataBoxApi.credentials(),
      dataBoxApi.recipients(),
      dataBoxApi.outbox(environment.value),
      dataBoxApi.inbox(environment.value, undefined, inboxVisibility.value),
      // Nespárovaná doručenka nesmí zmizet z očí — načítá se vždycky, ne až
      // na vyžádání.
      dataBoxApi.unmatchedReceipts(environment.value).catch(() => [] as InboxMessage[]),
      dataBoxApi.mobileKeyProfile(environment.value).catch(() => ({
        saved: false as const,
        username: null,
        environment: environment.value,
      })),
      storagePromise,
    ])
    credentials.value = creds
    recipients.value = recips
    outbox.value = out
    inbox.value = inb.items
    pollState.value = inb.state
    unmatchedReceipts.value = unmatched
    savedMobileCredential.value = mobileProfile
    inboxStorageItems.value = storage.items
    inboxArchiveFolders.value = storage.folders
    selectedInboxArchiveFolderId.value = storage.items.find(item => item.environment === environment.value)?.base_folder_id ?? ''
    if (mobileProfile.saved && mobileProfile.username && inboxUsername.value === '') {
      inboxUsername.value = mobileProfile.username
    }
    await loadNotices()
  } catch (e) {
    toast.error(apiErrorMessage(e))
  } finally {
    loading.value = false
  }
}

async function saveInboxArchiveFolder() {
  saving.value = true
  try {
    const item = await dataBoxApi.saveInboxStorage(
      environment.value,
      selectedInboxArchiveFolderId.value === '' ? null : selectedInboxArchiveFolderId.value,
      currentInboxStorage.value?.row_version ?? 0,
    )
    inboxStorageItems.value = [
      ...inboxStorageItems.value.filter(row => row.environment !== environment.value),
      ...(item ? [item] : []),
    ]
    toast.success(t('databox.inbox.archive.saved'))
  } catch (e) {
    toast.error(apiErrorMessage(e))
    await loadAll()
  } finally {
    saving.value = false
  }
}

function canManageInboxPrivacy(message: InboxMessage): boolean {
  return message.classification === 'unclassified' && message.matched_outbox_id === null
}

async function setInboxVisibility(visibility: 'active' | 'hidden') {
  if (inboxVisibility.value === visibility) return
  inboxVisibility.value = visibility
  await loadAll()
}

async function hideInboxMessage(message: InboxMessage) {
  privacyBusyId.value = message.id
  try {
    await dataBoxApi.hideInboxMessage(message.id, message.lifecycle_row_version)
    toast.success(t('databox.inbox.privacy.hidden'))
    await loadAll()
  } catch (e) {
    toast.error(apiErrorMessage(e))
  } finally {
    privacyBusyId.value = null
  }
}

async function restoreInboxMessage(message: InboxMessage) {
  privacyBusyId.value = message.id
  try {
    await dataBoxApi.restoreInboxMessage(message.id, message.lifecycle_row_version)
    toast.success(t('databox.inbox.privacy.restored'))
    await loadAll()
  } catch (e) {
    toast.error(apiErrorMessage(e))
  } finally {
    privacyBusyId.value = null
  }
}

async function purgeInboxLocalContent(message: InboxMessage) {
  if (!window.confirm(t('databox.inbox.privacy.purgeConfirm'))) return
  privacyBusyId.value = message.id
  try {
    await dataBoxApi.purgeInboxLocalContent(message.id, message.lifecycle_row_version)
    toast.success(t('databox.inbox.privacy.purged'))
    await loadAll()
  } catch (e) {
    toast.error(apiErrorMessage(e))
  } finally {
    privacyBusyId.value = null
  }
}

// ── Doručení a jeho následky ─────────────────────────────────────────────────

async function loadNotices() {
  try {
    const result = await dataBoxApi.defectNotices(environment.value)
    notices.value = result.items
    noticesSupported.value = result.supported
    noticesHint.value = result.notice
  } catch (e) {
    // Selhání načtení NESMÍ vypadat jako „žádné výzvy". Seznam se vyprázdní,
    // ale hláška řekne, že o výzvách nic nevíme.
    notices.value = []
    noticesSupported.value = false
    noticesHint.value = apiErrorMessage(e)
  }
}

/**
 * Přepočet rozhodného dne doručení. Nesahá na schránku — jen znovu posoudí
 * už stažené zprávy, protože běžící lhůta fikce se mění pouhým během času.
 */
async function refreshDelivery() {
  saving.value = true
  try {
    const result = await dataBoxApi.refreshDelivery(environment.value)
    toast.success(t('databox.delivery.refreshed', {
      checked: result.checked,
      fiction: result.delivered_by_fiction,
    }))
    await loadAll()
  } catch (e) {
    toast.error(apiErrorMessage(e))
  } finally {
    saving.value = false
  }
}

/** Předvyplní výzvu ze zprávy, kterou má uživatel před sebou. */
function startNoticeFromMessage(message: InboxMessage) {
  tab.value = 'notices'
  noticeForm.value.inbox_message_id = message.id
  noticeForm.value.delivered_on = message.delivered_on ?? ''
  noticeForm.value.notice_reference = ''
}

async function submitNotice() {
  saving.value = true
  try {
    const created = await dataBoxApi.createDefectNotice({
      environment: environment.value,
      inbox_message_id: noticeForm.value.inbox_message_id,
      outbox_id: noticeForm.value.outbox_id,
      notice_reference: noticeForm.value.notice_reference || null,
      defect_ground: noticeForm.value.defect_ground,
      delivered_on: noticeForm.value.delivered_on || null,
      respond_by_on: noticeForm.value.respond_by_on || null,
      stated_period_days: noticeForm.value.stated_period_days,
      note: noticeForm.value.note || null,
    })
    toast.success(created.created ? t('databox.notices.saved') : t('databox.notices.duplicate'))
    noticeForm.value = {
      inbox_message_id: null,
      outbox_id: null,
      notice_reference: '',
      defect_ground: 'unknown',
      delivered_on: '',
      respond_by_on: '',
      stated_period_days: null,
      note: '',
    }
    await loadNotices()
  } catch (e) {
    toast.error(apiErrorMessage(e))
  } finally {
    saving.value = false
  }
}

async function submitAnswer(notice: DefectNotice) {
  if (answerDate.value === '') {
    toast.error(t('databox.notices.answerDateRequired'))
    return
  }
  busyId.value = notice.id
  try {
    await dataBoxApi.answerDefectNotice(notice.id, notice.row_version, answerDate.value)
    answerFor.value = null
    answerDate.value = ''
    await loadNotices()
    toast.success(t('databox.notices.answerSaved'))
  } catch (e) {
    toast.error(apiErrorMessage(e))
  } finally {
    busyId.value = null
  }
}

// ── Ruční odeslání ───────────────────────────────────────────────────────────

function startMarkSent(row: OutboxSubmission) {
  markSentFor.value = markSentFor.value === row.id ? null : row.id
  markSentMessageId.value = ''
}

async function submitMarkSent(row: OutboxSubmission) {
  const messageId = markSentMessageId.value.trim()
  if (messageId === '') {
    toast.error(t('databox.outbox.messageIdRequired'))
    return
  }
  busyId.value = row.id
  try {
    const result = await dataBoxApi.markSentManually(row.id, messageId)
    if (result.validation?.status === 'failed') {
      toast.error(t('databox.outbox.validationFailed'))
    } else {
      toast.success(t('databox.outbox.markedSent'))
    }
    markSentFor.value = null
    markSentMessageId.value = ''
    await loadAll()
  } catch (e) {
    toast.error(apiErrorMessage(e))
  } finally {
    busyId.value = null
  }
}

// ── Doručenka ────────────────────────────────────────────────────────────────

/** `null` = doručenka bez určeného podání; párování hledá aplikace. */
function openReceiptPicker(outboxId: number | null) {
  uploadTargetId.value = outboxId
  receiptInput.value?.click()
}

async function onReceiptChosen(event: Event) {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0] ?? null
  input.value = ''
  if (!file) return

  const target = uploadTargetId.value
  saving.value = true
  lastUpload.value = null
  try {
    const result = target !== null
      ? await dataBoxApi.uploadReceiptFor(target, environment.value, file)
      : await dataBoxApi.uploadReceipt(environment.value, file)
    lastUpload.value = result
    if (result.status === 'matched') {
      toast.success(result.message)
    } else {
      // Nespárováno není chyba uživatele ani selhání — je to stav, ve kterém
      // se čeká na jeho rozhodnutí. Proto informace, ne červená hláška.
      toast.success(result.message)
    }
    await loadAll()
  } catch (e) {
    toast.error(apiErrorMessage(e))
  } finally {
    saving.value = false
    uploadTargetId.value = null
  }
}

async function showCandidates(message: InboxMessage) {
  try {
    receiptCandidates.value = {
      ...receiptCandidates.value,
      [message.id]: await dataBoxApi.receiptCandidates(message.id),
    }
  } catch (e) {
    toast.error(apiErrorMessage(e))
  }
}

async function assignReceipt(inboxMessageId: number, outboxId: number) {
  busyId.value = outboxId
  try {
    const result = await dataBoxApi.matchReceipt(inboxMessageId, outboxId)
    toast.success(result.message)
    lastUpload.value = null
    await loadAll()
  } catch (e) {
    toast.error(apiErrorMessage(e))
  } finally {
    busyId.value = null
  }
}

async function saveCredential() {
  if (!certFile.value) {
    toast.error(t('databox.errors.certificateRequired'))
    return
  }
  saving.value = true
  try {
    const form = new FormData()
    form.append('environment', environment.value)
    form.append('label', certLabel.value)
    form.append('box_id', certBoxId.value)
    form.append('certificate', certFile.value)
    form.append('certificate_password', certPassword.value)
    await dataBoxApi.saveCredential(form)
    certPassword.value = ''
    certFile.value = null
    if (certFileInput.value) certFileInput.value.value = ''
    toast.success(t('databox.saved'))
    await loadAll()
  } catch (e) {
    toast.error(apiErrorMessage(e))
  } finally {
    saving.value = false
  }
}

async function confirmSend(row: OutboxSubmission) {
  busyId.value = row.id
  try {
    const result = await dataBoxApi.confirm(row.id, environment.value)
    if (result.dispatched) {
      toast.success(t('databox.outbox.sent'))
    } else if (result.row.dispatch_state === 'send_uncertain') {
      toast.error(t('databox.outbox.uncertain'))
    } else {
      toast.error(result.row.last_error_message ?? t('databox.outbox.notSent'))
    }
    await loadAll()
  } catch (e) {
    toast.error(apiErrorMessage(e))
  } finally {
    busyId.value = null
  }
}

async function resolveUncertain(row: OutboxSubmission) {
  busyId.value = row.id
  try {
    const updated = await dataBoxApi.resolve(row.id, environment.value)
    if (updated.dispatch_state === 'sent') {
      toast.success(t('databox.outbox.resolvedSent'))
    } else if (updated.dispatch_state === 'failed') {
      toast.success(t('databox.outbox.resolvedNotSent'))
    } else {
      toast.error(t('databox.outbox.resolveInconclusive'))
    }
    await loadAll()
  } catch (e) {
    toast.error(apiErrorMessage(e))
  } finally {
    busyId.value = null
  }
}

async function cancelSubmission(row: OutboxSubmission) {
  busyId.value = row.id
  try {
    await dataBoxApi.cancel(row.id)
    await loadAll()
  } catch (e) {
    toast.error(apiErrorMessage(e))
  } finally {
    busyId.value = null
  }
}

async function toggleAttempts(row: OutboxSubmission) {
  if (expanded.value === row.id) {
    expanded.value = null
    return
  }
  expanded.value = row.id
  if (!attempts.value[row.id]) {
    try {
      attempts.value[row.id] = await dataBoxApi.attempts(row.id)
    } catch (e) {
      toast.error(apiErrorMessage(e))
    }
  }
}

function clearMobileStatusTimer() {
  if (mobileStatusTimer !== null) clearTimeout(mobileStatusTimer)
  mobileStatusTimer = null
}

function resetInboxAuth() {
  clearMobileStatusTimer()
  mobileFlowToken.value = ''
  mobileStatus.value = ''
  inboxPassword.value = ''
  mobileCommunicationCode.value = ''
  smsFlowToken.value = ''
  smsCode.value = ''
  smsStatus.value = ''
}

async function forgetMobileCredential() {
  saving.value = true
  try {
    await dataBoxApi.deleteMobileKeyProfile(environment.value)
    savedMobileCredential.value = { saved: false, username: null, environment: environment.value }
    rememberMobileCredential.value = false
    inboxUsername.value = ''
    mobileCommunicationCode.value = ''
    toast.success(t('databox.inbox.mobileCredentialDeleted'))
  } catch (e) {
    toast.error(apiErrorMessage(e))
  } finally {
    saving.value = false
  }
}

async function finishInboxPoll(result: { stored: number; failed: number; error?: string | null }) {
  if (result.failed > 0 || result.error) {
    toast.warning(t('databox.inbox.polledWithErrors', { stored: result.stored, failed: result.failed }))
  } else {
    toast.success(t('databox.inbox.polled', { count: result.stored }))
  }
  inboxAcknowledged.value = false
  resetInboxAuth()
  await loadAll()
}

async function pollMobileKeyStatus() {
  if (mobileFlowToken.value === '') return
  try {
    const status = await dataBoxApi.mobileKeyInboxStatus(mobileFlowToken.value, environment.value)
    mobileStatus.value = status.description
    if (status.state === 2 && status.result) {
      await finishInboxPoll(status.result)
      return
    }
    mobileStatusTimer = setTimeout(() => { void pollMobileKeyStatus() }, 2000)
  } catch (e) {
    clearMobileStatusTimer()
    mobileFlowToken.value = ''
    toast.error(apiErrorMessage(e))
  }
}

async function pollInbox() {
  if (!inboxAcknowledged.value) {
    toast.error(t('databox.polling.ackRequired'))
    return
  }
  saving.value = true
  try {
    if (inboxAuthMethod.value === 'certificate') {
      if (!currentCredential.value) {
        toast.error(t('databox.inbox.certificateMissing'))
        return
      }
      await finishInboxPoll(await dataBoxApi.pollInbox(environment.value))
      return
    }
    if (inboxUsername.value.trim() === '') {
      toast.error(t('databox.inbox.usernameRequired'))
      return
    }
    if (inboxAuthMethod.value === 'password') {
      if (inboxPassword.value === '') {
        toast.error(t('databox.inbox.passwordRequired'))
        return
      }
      await finishInboxPoll(await dataBoxApi.pollInboxWithPassword(
        environment.value,
        inboxUsername.value,
        inboxPassword.value,
      ))
      return
    }
    if (inboxAuthMethod.value === 'sms') {
      if (smsFlowToken.value === '') {
        if (inboxPassword.value === '') {
          toast.error(t('databox.inbox.passwordRequired'))
          return
        }
        const start = await dataBoxApi.startSmsInbox(
          environment.value,
          inboxUsername.value,
          inboxPassword.value,
        )
        smsFlowToken.value = start.flow_token
        smsStatus.value = start.description
        inboxPassword.value = ''
        return
      }
      if (smsCode.value.trim() === '') {
        toast.error(t('databox.inbox.smsCodeRequired'))
        return
      }
      await finishInboxPoll(await dataBoxApi.completeSmsInbox(smsFlowToken.value, smsCode.value, environment.value))
      return
    }
    const useSaved = savedMobileCredential.value?.saved === true
      && savedMobileCredential.value.username === inboxUsername.value.trim()
      && mobileCommunicationCode.value === ''
    if (!useSaved && mobileCommunicationCode.value === '') {
      toast.error(t('databox.inbox.communicationCodeRequired'))
      return
    }
    const start = await dataBoxApi.startMobileKeyInbox(
      environment.value,
      inboxUsername.value,
      useSaved ? '' : mobileCommunicationCode.value,
      useSaved,
    )
    if (!useSaved && rememberMobileCredential.value) {
      savedMobileCredential.value = await dataBoxApi.saveMobileKeyProfile(
        environment.value,
        inboxUsername.value,
        mobileCommunicationCode.value,
      )
    }
    mobileFlowToken.value = start.flow_token
    mobileStatus.value = start.description
    mobileCommunicationCode.value = ''
    mobileStatusTimer = setTimeout(() => { void pollMobileKeyStatus() }, 1500)
  } catch (e) {
    toast.error(apiErrorMessage(e))
  } finally {
    saving.value = false
  }
}

async function changeEnvironment() {
  resetInboxAuth()
  inboxUsername.value = ''
  savedMobileCredential.value = null
  rememberMobileCredential.value = false
  inboxAcknowledged.value = false
  await loadAll()
}

async function saveRecipient() {
  saving.value = true
  try {
    await dataBoxApi.saveRecipient({
      code: recipientCode.value,
      name: recipientName.value,
      business_id: recipientBusinessId.value.trim() || null,
      address: recipientAddress.value.trim() || null,
      kind: recipientKind.value,
      isds_box_id: recipientBoxId.value.trim() || null,
      source_url: recipientSource.value.trim() || null,
      is_active: true,
    })
    resetRecipientForm()
    toast.success(t('databox.saved'))
    await loadAll()
  } catch (e) {
    toast.error(apiErrorMessage(e))
  } finally {
    saving.value = false
  }
}

async function deleteRecipient(row: SubmissionRecipient) {
  busyId.value = row.id
  try {
    await dataBoxApi.deleteRecipient(row.id)
    await loadAll()
  } catch (e) {
    toast.error(apiErrorMessage(e))
  } finally {
    busyId.value = null
  }
}

// ── Odesílací brána ISDS ─────────────────────────────────────────────────────
// Zpráva neodchází ze serveru: připravíme koncept, uživatel ho schválí přímo
// v datové schránce. Mezi tím jsou dvě přesměrování prohlížeče, takže tahle
// obrazovka je zároveň návratovou adresou registrace brány.

/**
 * Je brána pro tohle prostředí zaregistrovaná a zapnutá?
 *
 * `null` znamená „nevíme" — typicky proto, že uživatel nemá právo
 * `settings.signing` a výpis registrací mu vrátí 403. Nevědomost se nesmí
 * vydávat za „brána není", takže tlačítko se v takovém případě nenabízí
 * a ruční cesta zůstává beze změny.
 */
const gatewayCapabilities = ref<IsdsGatewayCapability[] | null>(null)
const gatewayBusyId = ref<number | null>(null)
const gatewayNotice = ref<{ state: GatewaySessionState; message: string; messageId: string | null } | null>(null)
const gatewayPreflight = ref<{ outboxId: number; start: GatewayStart } | null>(null)

const gatewayAvailable = computed(() =>
  (gatewayCapabilities.value ?? []).some(r => r.environment === environment.value && r.available),
)

const tabs: Tab[] = ['access', 'outbox', 'inbox', 'notices', 'recipients']

async function loadGatewayRegistrations() {
  try {
    gatewayCapabilities.value = await dataBoxApi.gatewayCapabilities()
  } catch {
    gatewayCapabilities.value = null
  }
}

function editRecipient(row: SubmissionRecipient) {
  recipientCode.value = row.code
  recipientCodeSlug.init(row.code)
  recipientCodeSlug.markManual(row.code)
  recipientName.value = row.name
  recipientBusinessId.value = row.business_id ?? ''
  recipientAddress.value = row.address ?? ''
  recipientKind.value = row.kind
  recipientBoxId.value = row.isds_box_id ?? ''
  recipientSource.value = row.source_url ?? ''
  recipientEditing.value = true
  recipientEditingDefault.value = row.is_system
}

function resetRecipientForm() {
  recipientCode.value = ''
  recipientCodeSlug.init('')
  recipientName.value = ''
  recipientBusinessId.value = ''
  recipientAddress.value = ''
  recipientKind.value = 'tax_office'
  recipientBoxId.value = ''
  recipientSource.value = ''
  recipientEditing.value = false
  recipientEditingDefault.value = false
}

/** Nabízí se jen tam, kde má smysl: připravené podání datovkou a zapnutá brána. */
function canUseGateway(row: OutboxSubmission): boolean {
  return gatewayAvailable.value && row.channel === 'isds' && row.dispatch_state === 'ready'
}

async function startGateway(row: OutboxSubmission) {
  gatewayBusyId.value = row.id
  try {
    const start = await dataBoxApi.gatewayStart(row.id)
    gatewayPreflight.value = { outboxId: row.id, start }
  } catch (e) {
    toast.error(apiErrorMessage(e))
  } finally {
    gatewayBusyId.value = null
  }
}

function continueGateway() {
  if (!gatewayPreflight.value) return
  // Plná navigace, ne router: cíl je v perimetru ISDS a musí se z něj dát
  // vrátit zpátky na naši návratovou adresu.
  window.location.assign(gatewayPreflight.value.start.redirect_url)
}

/**
 * Návrat z ISDS. Volá se pro obě fáze; která to je, ví server ze stavu relace.
 *
 * Parametry se z adresy odstraní hned po přečtení — `sessionId` je jednorázové
 * a obnovení stránky s ním v adrese by skončilo `SESSION_NOT_FOUND`.
 */
async function handleGatewayReturn(): Promise<boolean> {
  const params = new URLSearchParams(window.location.search)
  const appToken = params.get('appToken') ?? ''
  const sessionId = params.get('sessionId') ?? ''
  if (appToken === '' || sessionId === '') return false

  params.delete('appToken')
  params.delete('sessionId')
  const query = params.toString()
  window.history.replaceState({}, '', window.location.pathname + (query === '' ? '' : '?' + query))

  tab.value = 'outbox'
  try {
    let result
    try {
      result = await dataBoxApi.gatewayComplete(appToken, sessionId)
    } catch (exception) {
      // Mzdová role nespravuje globální certifikát brány. Vrací-li obecný
      // callback jen 403, dokončí vlastní relaci pod payroll.submissions.
      if (!isAxiosError(exception) || exception.response?.status !== 403) throw exception
      result = await dataBoxApi.gatewayCompletePayroll(appToken, sessionId)
    }
    if (result.redirect_url) {
      // Koncept leží v datové schránce. Uživatel ho jde zkontrolovat
      // a schválit — teprve tím zpráva odejde.
      gatewayNotice.value = { state: result.state, message: result.message, messageId: null }
      window.location.assign(result.redirect_url)

      return true
    }
    gatewayNotice.value = {
      state: result.state,
      message: result.message,
      messageId: result.external_message_id,
    }
    if (result.state === 'approved') toast.success(result.message)
    else if (result.state === 'rejected') toast.info(result.message)
    else toast.error(result.message)
  } catch (e) {
    const message = apiErrorMessage(e)
    // ⚠️ Nevědomost se nesmí ztratit v toastu. Zůstává na obrazovce, protože
    // z ní plyne pokyn „neodesílejte znovu, dokud si to neověříte".
    gatewayNotice.value = { state: 'uncertain', message, messageId: null }
    toast.error(message)
  }

  return true
}

onMounted(async () => {
  const requestedTab = new URLSearchParams(window.location.search).get('tab')
  if (requestedTab && ['access', 'outbox', 'inbox', 'notices', 'recipients'].includes(requestedTab)) {
    tab.value = requestedTab as Tab
  }
  await loadGatewayRegistrations()
  const returning = await handleGatewayReturn()
  // Při odchodu na schvalovací obrazovku se stránka stejně opouští — načítat
  // zbytek by bylo zbytečné volání navíc.
  if (!returning || gatewayNotice.value?.state !== 'awaiting_approval') await loadAll()
})

onUnmounted(clearMobileStatusTimer)
</script>

<template>
  <div class="space-y-6">
    <header class="flex flex-wrap items-center justify-between gap-3">
      <div>
        <h1 class="text-xl font-semibold text-neutral-900">{{ t('databox.title') }}</h1>
        <p class="text-sm text-neutral-500">{{ t('databox.subtitle') }}</p>
        <p class="mt-1 text-sm font-medium text-primary-700">
          {{ t('databox.companyScope', { company: supplierStore.currentSupplier?.company_name ?? '—' }) }}
        </p>
      </div>
      <select
        v-model="environment"
        class="h-9 min-w-[11rem] rounded-md border border-neutral-300 bg-surface px-3 text-sm text-neutral-900 shadow-xs outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-500/20"
        @change="changeEnvironment"
      >
        <option value="production">{{ t('databox.env.production') }}</option>
        <option value="test">{{ t('databox.env.test') }}</option>
      </select>
    </header>

    <nav class="flex flex-wrap gap-2 border-b border-neutral-200">
      <button
        v-for="key in tabs"
        :key="key"
        type="button"
        class="cursor-pointer whitespace-nowrap px-3 py-2 text-sm font-medium border-b-2 -mb-px"
        :class="tab === key
          ? 'border-primary-600 text-primary-700 dark:text-primary-300'
          : 'border-transparent text-neutral-500 hover:text-neutral-700'"
        @click="tab = key"
      >
        {{ t(`databox.tabs.${key}`) }}
        <span
          v-if="key === 'notices' && noticesNeedingAttention > 0"
          class="ml-1 rounded-full bg-warning-100 px-1.5 py-0.5 text-xs text-warning-800 dark:bg-warning-900/40 dark:text-warning-200"
        >{{ noticesNeedingAttention }}</span>
      </button>
    </nav>

    <!-- ─────────────── Přístup ─────────────── -->
    <section v-if="tab === 'access'" class="space-y-5">
      <div class="grid gap-4 lg:grid-cols-3">
        <article class="rounded-lg border border-primary-500/40 bg-primary-50 p-4 shadow-sm">
          <div class="flex flex-wrap items-start justify-between gap-2">
            <h2 class="font-medium text-neutral-900">{{ t('databox.access.gatewayTitle') }}</h2>
            <span class="rounded-full bg-success-50 px-2 py-0.5 text-xs font-medium text-success-700">
              {{ t('databox.access.recommended') }}
            </span>
          </div>
          <p class="mt-2 text-sm text-neutral-600">{{ t('databox.access.gatewayDescription') }}</p>
          <p class="mt-3 text-sm text-neutral-700">{{ t('databox.gateway.methodsByIsds') }}</p>
          <p class="mt-3 text-xs text-neutral-500">{{ t('databox.access.gatewayBoundary') }}</p>
          <div class="mt-3 flex items-center gap-2 text-sm">
            <span class="h-2.5 w-2.5 rounded-full" :class="gatewayAvailable ? 'bg-success-500' : 'bg-warning-500'" />
            <span>{{ gatewayAvailable ? t('databox.access.gatewayAvailable') : t('databox.access.gatewayUnavailable') }}</span>
          </div>
        </article>

        <article class="rounded-lg border border-neutral-200 bg-surface p-4 shadow-sm">
          <h2 class="font-medium text-neutral-900">{{ t('databox.access.certificateTitle') }}</h2>
          <p class="mt-2 text-sm text-neutral-500">{{ t('databox.access.certificateDescription') }}</p>
          <p class="mt-3 text-xs text-neutral-500">{{ t('databox.access.certificateRecommendedByIsds') }}</p>
        </article>

        <article class="rounded-lg border border-neutral-200 bg-surface p-4 shadow-sm">
          <h2 class="font-medium text-neutral-900">{{ t('databox.access.inboxLoginTitle') }}</h2>
          <p class="mt-2 text-sm text-neutral-500">{{ t('databox.access.inboxLoginDescription') }}</p>
          <ul class="mt-3 space-y-2 text-sm text-neutral-700">
            <li>{{ t('databox.access.inboxMobileKey') }}</li>
            <li>{{ t('databox.access.inboxPassword') }}</li>
            <li>{{ t('databox.access.inboxCertificate') }}</li>
          </ul>
          <p class="mt-3 text-xs text-neutral-500">{{ t('databox.access.inboxLoginBoundary') }}</p>
        </article>
      </div>

      <div class="rounded-lg border border-neutral-200 bg-surface p-4 shadow-sm">
        <h2 class="mb-1 font-medium text-neutral-900">{{ t('databox.access.certificateSettings') }}</h2>
        <p class="mb-4 text-sm text-neutral-500">{{ t('databox.access.certificateOnly') }}</p>

        <div v-if="currentCredential" class="mb-4 rounded-md bg-neutral-50 p-3 text-sm">
          <div class="font-medium">{{ currentCredential.label }}</div>
          <div class="text-neutral-500">
            {{ t('databox.access.boxId') }}: <code>{{ currentCredential.box_id }}</code>
          </div>
          <div v-if="currentCredential.certificate_valid_to" class="text-neutral-500">
            {{ t('databox.access.validTo') }}: {{ currentCredential.certificate_valid_to }}
          </div>
        </div>

        <div class="grid gap-3 sm:grid-cols-2">
          <label class="block">
            <span class="text-sm font-medium">{{ t('databox.access.label') }}</span>
            <input v-model="certLabel" type="text" class="form-input mt-1 w-full" />
          </label>
          <label class="block">
            <span class="text-sm font-medium">{{ t('databox.access.boxId') }}</span>
            <input v-model="certBoxId" type="text" maxlength="7" class="form-input mt-1 w-full" />
          </label>
          <label class="block">
            <span class="text-sm font-medium">{{ t('databox.access.certificate') }}</span>
            <input
              ref="certFileInput"
              type="file"
              accept=".pfx,.p12"
              class="form-input mt-1 w-full"
              @change="certFile = ($event.target as HTMLInputElement).files?.[0] ?? null"
            />
          </label>
          <label class="block">
            <span class="text-sm font-medium">{{ t('databox.access.certificatePassword') }}</span>
            <input v-model="certPassword" type="password" autocomplete="new-password" class="form-input mt-1 w-full" />
          </label>
        </div>
      </div>

      <!-- Schránku vybírá jen člověk samostatnou akcí; automatický režim není. -->
      <div class="rounded-lg border border-warning-500/50 bg-warning-50 p-4">
        <h2 class="mb-1 font-medium">{{ t('databox.polling.title') }}</h2>
        <p class="text-sm">{{ t('databox.polling.explanation') }}</p>
      </div>

      <!-- Jedno společné Uložit pro celou sekci -->
      <div class="sticky bottom-0 flex flex-wrap justify-end gap-2 border-t border-neutral-200 bg-surface/95 py-3">
        <button type="button" :class="btnFilled('primary')" :disabled="saving" @click="saveCredential">
          <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" />
          </svg>
          {{ t('common.save') }}
        </button>
      </div>
    </section>

    <!-- ─────────────── Odchozí ─────────────── -->
    <section v-else-if="tab === 'outbox'" class="space-y-3">
      <!-- Jediný skrytý file input pro celou sekci; cíl určuje uploadTargetId. -->
      <input ref="receiptInput" type="file" accept=".zfo" class="hidden" @change="onReceiptChosen" />

      <!-- Výsledek posledního nahrání, které se nespárovalo samo.
           Prázdno by tady bylo nejhorší možná odpověď: uživatel soubor nahrál
           a musí vidět, co s ním je a co má udělat dál. -->
      <div
        v-if="lastUpload && lastUpload.status !== 'matched'"
        class="rounded-lg border border-warning-500/50 bg-warning-50 p-4 text-sm"
      >
        <div class="font-medium">{{ lastUpload.message }}</div>
        <div class="mt-1 text-xs text-neutral-600">
          {{ t('databox.receipts.messageId') }}: <code>{{ lastUpload.receipt.message_id }}</code>
          <span v-if="lastUpload.receipt.delivered_at">
            · {{ t('databox.receipts.deliveredAt') }}: {{ lastUpload.receipt.delivered_at }}
          </span>
        </div>
        <div v-if="lastUpload.candidates.length" class="mt-3 space-y-2">
          <div class="text-xs font-medium uppercase text-neutral-500">{{ t('databox.receipts.candidatesHint') }}</div>
          <div
            v-for="c in lastUpload.candidates"
            :key="c.id"
            class="flex flex-wrap items-center justify-between gap-2 rounded-md bg-surface p-2"
          >
            <div class="min-w-0">
              <div class="font-medium">{{ c.subject }}</div>
              <div class="text-xs text-neutral-500">
                {{ c.agenda_code }} · <code>{{ c.correlation_reference }}</code> · {{ c.created_at }}
              </div>
              <div v-if="c.reasons.length" class="text-xs text-neutral-500">
                {{ c.reasons.map(r => t(`databox.receipts.reasons.${r}`)).join(' · ') }}
              </div>
            </div>
            <button
              type="button"
              :class="btnOutlineSm('primary')"
              :disabled="busyId === c.id"
              @click="assignReceipt(lastUpload.inbox_message_id, c.id)"
            >
              {{ t('databox.receipts.assign') }}
            </button>
          </div>
        </div>
      </div>

      <!-- Nespárované doručenky. Nezmizely, jen čekají na člověka. -->
      <div
        v-if="unmatchedReceipts.length"
        class="rounded-lg border border-neutral-200 bg-surface p-4"
      >
        <h2 class="font-medium">{{ t('databox.receipts.title', { count: unmatchedReceipts.length }) }}</h2>
        <p class="mb-3 text-sm text-neutral-500">{{ t('databox.receipts.intro') }}</p>
        <div v-for="m in unmatchedReceipts" :key="m.id" class="border-t border-neutral-100 py-2">
          <div class="flex flex-wrap items-center justify-between gap-2">
            <div class="min-w-0">
              <div class="text-sm font-medium">{{ m.subject ?? t('databox.receipts.noSubject') }}</div>
              <div class="text-xs text-neutral-500">
                {{ t('databox.receipts.messageId') }}: <code>{{ m.external_message_id }}</code>
                <span v-if="m.delivered_at"> · {{ m.delivered_at }}</span>
              </div>
            </div>
            <button type="button" :class="btnOutlineSm('primary')" @click="showCandidates(m)">
              {{ t('databox.receipts.showCandidates') }}
            </button>
          </div>
          <div v-if="receiptCandidates[m.id]" class="mt-2 space-y-2">
            <p v-if="!receiptCandidates[m.id].length" class="text-sm text-neutral-500">
              {{ t('databox.receipts.noCandidates') }}
            </p>
            <div
              v-for="c in receiptCandidates[m.id]"
              :key="c.id"
              class="flex flex-wrap items-center justify-between gap-2 rounded-md bg-neutral-50 p-2"
            >
              <div class="min-w-0 text-sm">
                <div class="font-medium">{{ c.subject }}</div>
                <div class="text-xs text-neutral-500">
                  {{ c.agenda_code }} · <code>{{ c.correlation_reference }}</code>
                  <span v-if="c.reasons.length">
                    · {{ c.reasons.map(r => t(`databox.receipts.reasons.${r}`)).join(' · ') }}
                  </span>
                </div>
              </div>
              <button
                type="button"
                :class="btnOutlineSm('primary')"
                :disabled="busyId === c.id"
                @click="assignReceipt(m.id, c.id)"
              >
                {{ t('databox.receipts.assign') }}
              </button>
            </div>
          </div>
        </div>
      </div>

      <div class="flex flex-wrap gap-2">
        <button type="button" :class="btnOutline('neutral')" :disabled="saving" @click="openReceiptPicker(null)">
          <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.upload" />
          </svg>
          {{ t('databox.receipts.uploadAny') }}
        </button>
      </div>

      <!-- ── Výsledek návratu z datové schránky ──
           Nezmizí jako toast: u nejistého konce z něj plyne pokyn
           „neodesílejte znovu, dokud si to neověříte v odeslaných zprávách". -->
      <div
        v-if="gatewayNotice"
        class="rounded-lg border p-3 text-sm"
        :class="gatewayNotice.state === 'approved'
          ? 'border-success-500/50 bg-success-50 text-success-800 dark:text-success-200'
          : gatewayNotice.state === 'uncertain'
            ? 'border-warning-500/50 bg-warning-50 text-warning-800 dark:text-warning-200'
            : 'border-neutral-200 bg-neutral-50 text-neutral-700'"
      >
        <div class="font-medium">{{ t(`databox.gateway.state.${gatewayNotice.state}`) }}</div>
        <p class="mt-1">{{ gatewayNotice.message }}</p>
        <p v-if="gatewayNotice.messageId" class="mt-1">
          {{ t('databox.receipts.messageId') }}: <code>{{ gatewayNotice.messageId }}</code>
        </p>
        <p v-if="gatewayNotice.state === 'approved'" class="mt-1 text-xs">
          {{ t('databox.gateway.receiptManual') }}
        </p>
        <button type="button" :class="[btnOutlineSm('neutral'), 'mt-2']" @click="gatewayNotice = null">
          {{ t('common.close') }}
        </button>
      </div>

      <!-- Přihlašovací instrukce musí uživatel vidět ještě PŘED odchodem do
           ISDS. Server je vrací podle doložené politiky registrace; klient je
           nesmí zahodit ani sám slibovat konkrétní metodu přihlášení. -->
      <div
        v-if="gatewayPreflight"
        class="rounded-lg border border-primary-500/40 bg-primary-50 p-4 text-sm text-primary-900"
        data-test="gateway-preflight"
      >
        <div class="font-medium">{{ t('databox.gateway.preflightTitle') }}</div>
        <p class="mt-1">{{ gatewayPreflight.start.login_guidance }}</p>
        <p class="mt-2 text-xs">
          {{ t('databox.gateway.credentialsStayInIsds') }}
        </p>
        <p v-if="!gatewayPreflight.start.login_policy_documented" class="mt-2 text-xs text-warning-800 dark:text-warning-200">
          {{ t('databox.gateway.methodsByIsds') }}
        </p>
        <p class="mt-2 text-xs">
          {{ t('databox.gateway.expiresAt', { value: gatewayPreflight.start.expires_at }) }}
        </p>
        <div class="mt-3 flex flex-wrap gap-2">
          <button type="button" :class="btnFilled('primary')" @click="continueGateway">
            <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.send" />
            </svg>
            {{ t('databox.gateway.continueToIsds') }}
          </button>
          <button type="button" :class="btnOutline('neutral')" @click="gatewayPreflight = null">
            {{ t('common.cancel') }}
          </button>
        </div>
      </div>

      <EmptyState v-if="!loading && outbox.length === 0" icon="send" :title="t('databox.outbox.empty')" />

      <div
        v-for="row in outbox"
        :key="row.id"
        class="rounded-lg border border-neutral-200 bg-surface p-4"
      >
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div class="min-w-0">
            <div class="font-medium">{{ row.subject }}</div>
            <div class="text-sm text-neutral-500">
              {{ row.agenda_code }} · {{ row.artifact_filename }}
              <span v-if="row.recipient_box_id"> · <code>{{ row.recipient_box_id }}</code></span>
            </div>
            <div class="mt-1 text-xs text-neutral-400">
              {{ t('databox.outbox.reference') }}: <code>{{ row.correlation_reference }}</code>
            </div>
          </div>

          <!-- DVĚ osy vedle sebe. Nikdy jeden sloučený štítek. -->
          <div class="flex flex-wrap items-center gap-2">
            <span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="dispatchTone(row.dispatch_state)">
              {{ t(`databox.dispatch.${row.dispatch_state}`) }}
            </span>
            <span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="acceptanceTone(row.acceptance_state)">
              {{ t(`databox.acceptance.${row.acceptance_state}`) }}
            </span>
          </div>
        </div>

        <!-- Věta, podle které se dá jednat -->
        <p
          v-if="row.dispatch_state === 'delivered' && row.acceptance_state === 'unknown'"
          class="mt-3 rounded-md bg-neutral-50 p-2 text-sm text-neutral-600"
        >
          {{ t('databox.outbox.deliveredNotProcessed') }}
        </p>
        <p
          v-else-if="row.dispatch_state === 'send_uncertain' || row.dispatch_state === 'sending'"
          class="mt-3 rounded-md bg-warning-50 p-2 text-sm text-warning-800 dark:bg-warning-900/20 dark:text-warning-200"
        >
          {{ t('databox.outbox.uncertainHint') }}
        </p>
        <p
          v-else-if="row.last_error_message"
          class="mt-3 rounded-md bg-danger-50 p-2 text-sm text-danger-700 dark:bg-danger-900/20 dark:text-danger-200"
        >
          {{ row.last_error_message }}
        </p>

        <!-- ── Co udělat teď ──
             Ne nápověda někde stranou: konkrétní postup u konkrétního podání,
             včetně čísla jednacího, díky kterému se doručenka spáruje sama. -->
        <div
          v-if="needsManualSteps(row)"
          class="mt-3 rounded-md border border-primary-500/40 bg-primary-50 p-3 text-sm"
        >
          <div class="font-medium">{{ t('databox.manual.title') }}</div>
          <ol class="mt-2 list-decimal space-y-1 pl-5">
            <li>{{ t('databox.manual.step1', { file: row.artifact_filename }) }}</li>
            <li>
              {{ t('databox.manual.step2', { box: row.recipient_box_id ?? '—' }) }}
              <code class="rounded bg-surface px-1">{{ row.correlation_reference }}</code>
            </li>
            <li>{{ t('databox.manual.step3') }}</li>
            <li>{{ t('databox.manual.step4') }}</li>
          </ol>
          <p class="mt-2 text-xs text-neutral-600">{{ t('databox.manual.why') }}</p>
        </div>

        <!-- Doručenka jako důkaz — a hned vedle poctivé „podpis neověřujeme". -->
        <p
          v-if="row.receipt_document_id"
          class="mt-3 rounded-md bg-neutral-50 p-2 text-sm text-neutral-600"
        >
          {{ t('databox.outbox.receiptAttached', { at: row.receipt_attached_at ?? '' }) }}
          <span v-if="row.receipt_matched_by">
            ({{ t(`databox.receipts.matchedBy.${row.receipt_matched_by}`) }})
          </span>
          — {{ t('databox.outbox.receiptUnverified') }}
        </p>
        <p v-else-if="row.dispatch_mode === 'manual'" class="mt-3 text-sm text-neutral-500">
          {{ t('databox.outbox.manualDispatch') }}
        </p>

        <p
          v-if="row.artifact_validation_status === 'failed'"
          class="mt-3 rounded-md bg-danger-50 p-2 text-sm text-danger-700 dark:bg-danger-900/20 dark:text-danger-200"
        >
          {{ t('databox.outbox.validationFailed') }}
        </p>

        <!-- „Odeslal jsem to" — ID zprávy je přesný identifikátor, ne formalita. -->
        <div
          v-if="markSentFor === row.id"
          class="mt-3 rounded-md border border-neutral-200 p-3"
        >
          <label class="block">
            <span class="text-sm font-medium">{{ t('databox.outbox.messageIdLabel') }}</span>
            <input v-model="markSentMessageId" type="text" maxlength="64" class="form-input mt-1 w-full sm:w-72" />
            <span class="mt-1 block text-xs text-neutral-500">
              {{ t('databox.outbox.messageIdHint') }}
            </span>
          </label>
          <div class="mt-3 flex flex-wrap gap-2">
            <button
              type="button"
              :class="btnFilled('primary')"
              :disabled="busyId === row.id"
              @click="submitMarkSent(row)"
            >
              <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" />
              </svg>
              {{ t('databox.outbox.markSentConfirm') }}
            </button>
            <button type="button" :class="btnOutline('neutral')" @click="markSentFor = null">
              {{ t('common.cancel') }}
            </button>
          </div>
        </div>

        <div class="mt-3 flex flex-wrap gap-2">
          <!-- Připraví koncept v datové schránce. NEODESÍLÁ — odeslání je
               právní úkon a potvrzuje ho uživatel přímo v ISDS. -->
          <button
            v-if="primaryAction(row) === 'gateway'"
            type="button"
            :class="btnFilled('primary')"
            :disabled="gatewayBusyId === row.id || busyId === row.id"
            @click="startGateway(row)"
          >
            <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.send" />
            </svg>
            {{ t('databox.gateway.prepare') }}
          </button>
          <button
            v-if="canUseGateway(row) && markSentFor !== row.id"
            type="button"
            :class="btnOutline('neutral')"
            :disabled="busyId === row.id"
            @click="startMarkSent(row)"
          >
            <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" />
            </svg>
            {{ t('databox.outbox.markSent') }}
          </button>
          <button
            v-if="primaryAction(row) === 'markSent' && markSentFor !== row.id"
            type="button"
            :class="btnFilled('primary')"
            :disabled="busyId === row.id"
            @click="startMarkSent(row)"
          >
            <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" />
            </svg>
            {{ t('databox.outbox.markSent') }}
          </button>
          <button
            v-if="primaryAction(row) === 'uploadReceipt'"
            type="button"
            :class="btnFilled('success')"
            :disabled="saving"
            @click="openReceiptPicker(row.id)"
          >
            <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.upload" />
            </svg>
            {{ t('databox.outbox.uploadReceipt') }}
          </button>
          <button
            v-else-if="row.channel === 'isds' && !row.receipt_document_id && row.dispatch_state !== 'cancelled'"
            type="button"
            :class="btnOutline('neutral')"
            :disabled="saving"
            @click="openReceiptPicker(row.id)"
          >
            <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.upload" />
            </svg>
            {{ t('databox.outbox.uploadReceipt') }}
          </button>
          <button
            v-if="primaryAction(row) === 'confirm'"
            type="button"
            :class="btnFilled('success')"
            :disabled="busyId === row.id"
            @click="confirmSend(row)"
          >
            <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.send" />
            </svg>
            {{ t('databox.outbox.confirmSend') }}
          </button>
          <button
            v-if="primaryAction(row) === 'resolve'"
            type="button"
            :class="btnFilled('warning')"
            :disabled="busyId === row.id"
            @click="resolveUncertain(row)"
          >
            <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.search" />
            </svg>
            {{ t('databox.outbox.resolve') }}
          </button>
          <button type="button" :class="btnOutline('neutral')" @click="toggleAttempts(row)">
            <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.doc" />
            </svg>
            {{ t('databox.outbox.attempts') }}
          </button>
          <button
            v-if="row.dispatch_state === 'ready'"
            type="button"
            :class="btnOutline('danger')"
            :disabled="busyId === row.id"
            @click="cancelSubmission(row)"
          >
            <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" />
            </svg>
            {{ t('databox.outbox.cancel') }}
          </button>
        </div>

        <div v-if="expanded === row.id" class="mt-3 overflow-x-auto">
          <table class="w-full text-sm">
            <thead>
              <tr class="text-left text-xs uppercase text-neutral-400">
                <th class="py-1 pr-3">#</th>
                <th class="py-1 pr-3">{{ t('databox.outbox.attemptOutcome') }}</th>
                <th class="py-1 pr-3">{{ t('databox.outbox.messageId') }}</th>
                <th class="py-1 pr-3">{{ t('databox.outbox.startedAt') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="a in attempts[row.id] ?? []" :key="a.id" class="border-t border-neutral-100">
                <td class="py-1 pr-3">{{ a.attempt_no }}</td>
                <td class="py-1 pr-3">{{ t(`databox.attempt.${a.outcome}`) }}</td>
                <td class="py-1 pr-3"><code>{{ a.external_message_id ?? '—' }}</code></td>
                <td class="py-1 pr-3">{{ a.started_at }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </section>

    <!-- ─────────────── Příchozí ─────────────── -->
    <section v-else-if="tab === 'inbox'" class="space-y-4">
      <div class="min-w-0 rounded-lg border border-neutral-200 bg-surface p-4 shadow-sm">
        <h2 class="font-medium text-neutral-900">{{ t('databox.inbox.archive.title') }}</h2>
        <p class="mt-1 text-sm text-neutral-500">{{ t('databox.inbox.archive.description') }}</p>
        <div class="mt-4 flex flex-wrap items-end gap-3">
          <label class="min-w-[16rem] flex-1">
            <span class="mb-1 block text-sm font-medium">{{ t('databox.inbox.archive.folder') }}</span>
            <select v-model="selectedInboxArchiveFolderId" class="form-select w-full" data-test="inbox-archive-folder">
              <option value="">{{ t('databox.inbox.archive.root') }}</option>
              <option v-for="folder in inboxArchiveFolderOptions" :key="folder.id" :value="folder.id">
                {{ folder.label }}
              </option>
            </select>
          </label>
          <button type="button" :class="btnOutline('primary')" :disabled="saving" data-test="inbox-archive-save" @click="saveInboxArchiveFolder">
            <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" />
            </svg>
            {{ t('databox.inbox.archive.save') }}
          </button>
        </div>
        <p class="mt-2 text-xs text-neutral-500">{{ t('databox.inbox.archive.pathHint') }}</p>
      </div>

      <!-- Prázdno vs. porucha musí být rozlišitelné na první pohled -->
      <div
        v-if="pollState && pollState.consecutive_failures > 0"
        class="rounded-lg border border-danger-500/50 bg-danger-50 p-3 text-sm text-danger-800 dark:text-danger-200"
      >
        {{ t('databox.inbox.unreachable', { count: pollState.consecutive_failures }) }}
        <div v-if="pollState.last_ok_at" class="mt-1 text-xs">
          {{ t('databox.inbox.lastOkAt', { at: formatUtcDateTime(pollState.last_ok_at) }) }}
        </div>
      </div>
      <div v-else-if="pollState?.last_ok_at" class="text-sm text-neutral-500">
        {{ t('databox.inbox.lastOkAt', { at: formatUtcDateTime(pollState.last_ok_at) }) }}
      </div>

      <div class="flex flex-wrap gap-2">
        <button type="button" :class="btnOutline('neutral')" :disabled="saving" @click="refreshDelivery">
          {{ t('databox.delivery.refresh') }}
        </button>
      </div>
      <p class="text-sm text-neutral-500">{{ t('databox.delivery.explain') }}</p>
      <p class="text-sm text-neutral-500">
        {{ t('databox.inbox.manualOnly') }}
      </p>

      <div class="rounded-lg border border-neutral-200 bg-surface p-4 shadow-sm">
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div class="min-w-0">
            <h2 class="font-medium text-neutral-900">{{ t('databox.inbox.privacy.title') }}</h2>
            <p class="mt-1 max-w-4xl text-sm text-neutral-500">{{ t('databox.inbox.privacy.description') }}</p>
          </div>
          <div class="flex flex-wrap gap-2" data-test="inbox-visibility">
            <button
              type="button"
              :class="inboxVisibility === 'active' ? btnFilled('primary') : btnOutline('neutral')"
              :disabled="loading"
              data-test="inbox-visibility-active"
              @click="setInboxVisibility('active')"
            >
              <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.eye" />
              </svg>
              {{ t('databox.inbox.privacy.active') }}
            </button>
            <button
              type="button"
              :class="inboxVisibility === 'hidden' ? btnFilled('primary') : btnOutline('neutral')"
              :disabled="loading"
              data-test="inbox-visibility-hidden"
              @click="setInboxVisibility('hidden')"
            >
              <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.archive" />
              </svg>
              {{ t('databox.inbox.privacy.hiddenList') }}
            </button>
          </div>
        </div>
      </div>

      <div class="min-w-0 rounded-lg border border-neutral-200 bg-surface p-4 shadow-sm">
        <h2 class="font-medium text-neutral-900">{{ t('databox.inbox.authTitle') }}</h2>
        <p class="mt-1 text-sm text-neutral-500">{{ t('databox.inbox.authIntro') }}</p>

        <div class="mt-4 grid min-w-0 gap-3 md:grid-cols-2 xl:grid-cols-4">
          <label
            v-for="method in (['mobile_key', 'password', 'sms', 'certificate'] as InboxAuthMethod[])"
            :key="method"
            class="flex min-w-0 cursor-pointer gap-3 rounded-lg border p-3"
            :class="inboxAuthMethod === method ? 'border-primary-500 bg-primary-50' : 'border-neutral-200 bg-surface'"
          >
            <input v-model="inboxAuthMethod" type="radio" :value="method" class="mt-0.5" />
            <span class="min-w-0 break-words">
              <span class="block text-sm font-medium text-neutral-900">{{ t(`databox.inbox.auth.${method}.title`) }}</span>
              <span class="mt-1 block text-xs text-neutral-500">{{ t(`databox.inbox.auth.${method}.description`) }}</span>
              <span v-if="method === 'certificate' && !currentCredential" class="mt-1 block text-xs text-warning-700 dark:text-warning-300">
                {{ t('databox.inbox.certificateMissing') }}
              </span>
            </span>
          </label>
        </div>

        <div
          v-if="inboxAuthMethod !== 'certificate' && !(inboxAuthMethod === 'sms' && smsFlowToken)"
          class="mt-4 grid gap-3 sm:grid-cols-2"
        >
          <label class="block">
            <span class="text-sm font-medium">{{ t('databox.inbox.username') }}</span>
            <input v-model="inboxUsername" type="text" autocomplete="username" class="form-input mt-1 w-full" />
          </label>
          <label v-if="inboxAuthMethod === 'password' || inboxAuthMethod === 'sms'" class="block">
            <span class="text-sm font-medium">{{ t('databox.inbox.password') }}</span>
            <input v-model="inboxPassword" type="password" autocomplete="current-password" class="form-input mt-1 w-full" />
          </label>
          <label v-else-if="inboxAuthMethod === 'mobile_key'" class="block">
            <span class="text-sm font-medium">{{ t('databox.inbox.communicationCode') }}</span>
            <input
              v-model="mobileCommunicationCode"
              type="password"
              autocomplete="off"
              class="form-input mt-1 w-full"
              :placeholder="savedMobileCredential?.saved ? t('databox.inbox.savedCommunicationCodePlaceholder') : ''"
            />
            <span class="mt-1 block text-xs text-neutral-500">{{ t('databox.inbox.communicationCodeHint') }}</span>
          </label>
        </div>

        <div v-if="inboxAuthMethod === 'mobile_key'" class="mt-3 flex flex-wrap items-center gap-3 text-sm">
          <label v-if="!savedMobileCredential?.saved || mobileCommunicationCode" class="flex items-center gap-2">
            <input v-model="rememberMobileCredential" type="checkbox" />
            <span>{{ t('databox.inbox.rememberMobileCredential') }}</span>
          </label>
          <template v-if="savedMobileCredential?.saved">
            <span class="text-success-700 dark:text-success-300">
              {{ t('databox.inbox.mobileCredentialSaved', { username: savedMobileCredential.username }) }}
            </span>
            <button type="button" :class="btnOutlineSm('danger')" :disabled="saving" @click="forgetMobileCredential">
              {{ t('databox.inbox.forgetMobileCredential') }}
            </button>
          </template>
        </div>

        <div v-if="inboxAuthMethod === 'sms' && smsFlowToken" class="mt-4 grid gap-3 sm:grid-cols-2">
          <label class="block">
            <span class="text-sm font-medium">{{ t('databox.inbox.smsCode') }}</span>
            <input v-model="smsCode" type="text" inputmode="numeric" autocomplete="one-time-code" class="form-input mt-1 w-full" />
          </label>
          <div class="flex flex-wrap items-end gap-2">
            <button
              type="button"
              :class="btnOutlineSm('neutral')"
              :disabled="saving"
              @click="smsFlowToken = ''; smsCode = ''; smsStatus = ''"
            >
              {{ t('databox.inbox.requestNewSms') }}
            </button>
          </div>
        </div>

        <div v-if="smsStatus" class="mt-4 rounded-lg border border-primary-500/40 bg-primary-50 p-3 text-sm text-primary-800 dark:text-primary-200">
          {{ smsStatus }}
        </div>

        <div v-if="mobileStatus" class="mt-4 rounded-lg border border-primary-500/40 bg-primary-50 p-3 text-sm text-primary-800 dark:text-primary-200">
          {{ mobileStatus }}
        </div>

        <label class="mt-4 flex gap-3 rounded-lg border border-warning-500/50 bg-warning-50 p-3 text-sm text-warning-900 dark:text-warning-100">
          <input v-model="inboxAcknowledged" type="checkbox" class="mt-0.5" />
          <span>{{ t('databox.polling.acknowledge') }}</span>
        </label>

        <div class="mt-4 flex flex-wrap justify-end gap-2">
          <button
            type="button"
            :class="btnFilled('primary')"
            :disabled="saving || mobileFlowToken !== '' || (inboxAuthMethod === 'certificate' && !currentCredential)"
            @click="pollInbox"
          >
            <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.inbox" />
            </svg>
            {{ inboxAuthMethod === 'mobile_key'
              ? t('databox.inbox.startMobileKey')
              : inboxAuthMethod === 'sms'
                ? (smsFlowToken ? t('databox.inbox.confirmSmsAndFetch') : t('databox.inbox.sendSms'))
                : t('databox.inbox.fetchOnce') }}
          </button>
        </div>
      </div>

      <EmptyState v-if="!loading && inbox.length === 0" icon="inbox" :title="t('databox.inbox.empty')" />

      <div class="overflow-x-auto">
        <table v-if="inbox.length" class="w-full text-sm">
          <thead>
            <tr class="text-left text-xs uppercase text-neutral-400">
              <th class="py-2 pr-3">{{ t('databox.inbox.subject') }}</th>
              <th class="py-2 pr-3">{{ t('databox.inbox.sender') }}</th>
              <th class="py-2 pr-3">{{ t('databox.inbox.classification') }}</th>
              <th class="py-2 pr-3">{{ t('databox.inbox.deliveredAt') }}</th>
              <th class="py-2 pr-3">{{ t('databox.delivery.column') }}</th>
              <th class="py-2 pr-3"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="m in inbox" :key="m.id" class="border-t border-neutral-100 align-top">
              <td class="py-2 pr-3">{{ m.subject ?? '—' }}</td>
              <td class="py-2 pr-3">{{ m.sender_name ?? m.sender_box_id ?? '—' }}</td>
              <td class="py-2 pr-3">
                <span
                  class="rounded-full px-2 py-0.5 text-xs"
                  :class="m.classification === 'unclassified'
                    ? 'bg-neutral-100 text-neutral-600'
                    : 'bg-primary-50 text-primary-700 dark:bg-primary-900/30 dark:text-primary-200'"
                >
                  {{ t(`databox.classification.${m.classification}`) }}
                </span>
              </td>
              <td class="py-2 pr-3">{{ m.delivered_at ?? '—' }}</td>
              <!--
                Rozhodný den doručení. Odznak nikdy neříká jen „doručeno" —
                u fikce i u běžící lhůty musí být poznat, čím je to podložené,
                protože od toho dne běží navazující lhůty.
              -->
              <td v-if="m.classification === 'delivery_receipt'" class="py-2 pr-3 text-xs text-neutral-500">
                <!--
                  Doručenka popisuje NAŠE odeslané podání, ne zprávu doručovanou
                  nám. Fikce doručení se na ni nevztahuje, takže tu odznak
                  „doručení neznáme" nemá co dělat — nebylo by co znát.
                -->
                {{ t('databox.delivery.notApplicable') }}
              </td>
              <td v-else class="py-2 pr-3">
                <span class="rounded-full px-2 py-0.5 text-xs" :class="deliveryTone(m.delivery_basis)">
                  {{ t(`databox.delivery.basis.${m.delivery_basis ?? 'unknown'}`) }}
                </span>
                <div v-if="m.delivered_on" class="mt-1 text-xs text-neutral-600">
                  {{ t('databox.delivery.deliveredOn', { date: m.delivered_on }) }}
                </div>
                <div v-else-if="m.fiction_due_on" class="mt-1 text-xs text-neutral-500">
                  {{ t('databox.delivery.fictionDueOn', { date: m.fiction_due_on }) }}
                </div>
                <div v-if="m.delivery_note" class="mt-1 max-w-md text-xs text-neutral-500">
                  {{ m.delivery_note }}
                </div>
              </td>
              <td class="py-2 pr-3">
                <div class="flex flex-wrap gap-2">
                  <RouterLink
                    v-if="m.document_id"
                    :to="{ name: 'document-detail', params: { id: m.document_id } }"
                    :class="btnOutlineSm('primary')"
                  >
                    <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.doc" />
                    </svg>
                    {{ t('databox.inbox.openMessage') }}
                  </RouterLink>
                  <span
                    v-else-if="m.local_content_state === 'purged'"
                    class="inline-flex h-7 items-center rounded-full bg-neutral-100 px-2 text-xs text-neutral-600"
                  >
                    {{ t('databox.inbox.privacy.contentPurged') }}
                  </span>
                  <button type="button" :class="btnOutlineSm('neutral')" @click="startNoticeFromMessage(m)">
                    {{ t('databox.notices.recordFromMessage') }}
                  </button>
                  <button
                    v-if="canManageInboxPrivacy(m) && inboxVisibility === 'active'"
                    type="button"
                    :class="btnOutlineSm('neutral')"
                    :disabled="privacyBusyId === m.id"
                    data-test="inbox-hide"
                    @click="hideInboxMessage(m)"
                  >
                    <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.archive" />
                    </svg>
                    {{ t('databox.inbox.privacy.hide') }}
                  </button>
                  <button
                    v-if="canManageInboxPrivacy(m) && inboxVisibility === 'hidden'"
                    type="button"
                    :class="btnOutlineSm('neutral')"
                    :disabled="privacyBusyId === m.id"
                    data-test="inbox-restore"
                    @click="restoreInboxMessage(m)"
                  >
                    <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.uturn" />
                    </svg>
                    {{ t('databox.inbox.privacy.restore') }}
                  </button>
                  <button
                    v-if="canManageInboxPrivacy(m) && m.local_content_state === 'available'"
                    type="button"
                    :class="btnOutlineSm('danger')"
                    :disabled="privacyBusyId === m.id"
                    data-test="inbox-purge-content"
                    @click="purgeInboxLocalContent(m)"
                  >
                    <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.trash" />
                    </svg>
                    {{ t('databox.inbox.privacy.purge') }}
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <!-- ─────────────── Výzvy k odstranění vad (§ 74 DŘ) ─────────────── -->
    <section v-else-if="tab === 'notices'" class="space-y-4">
      <div class="rounded-lg border border-neutral-200 bg-surface p-4 text-sm">
        <h2 class="mb-1 font-medium">{{ t('databox.notices.title') }}</h2>
        <p class="text-neutral-500">{{ t('databox.notices.intro') }}</p>
      </div>

      <!-- Prázdno není „nic nepřišlo" a tahle věta to musí říct nahlas. -->
      <div
        v-if="noticesHint"
        class="rounded-lg border p-3 text-sm"
        :class="noticesSupported
          ? 'border-neutral-200 bg-neutral-50 text-neutral-600'
          : 'border-warning-500/50 bg-warning-50 text-warning-800 dark:text-warning-200'"
      >
        {{ noticesHint }}
      </div>

      <div class="rounded-lg border border-neutral-200 bg-surface p-4">
        <h3 class="mb-3 font-medium">{{ t('databox.notices.addTitle') }}</h3>
        <div class="grid gap-3 sm:grid-cols-2">
          <label class="block">
            <span class="mb-1 block text-sm">{{ t('databox.notices.reference') }}</span>
            <input v-model="noticeForm.notice_reference" type="text" class="form-input w-full" />
          </label>
          <label class="block">
            <span class="mb-1 block text-sm">{{ t('databox.notices.ground') }}</span>
            <select v-model="noticeForm.defect_ground" class="form-select w-full">
              <option value="unknown">{{ t('databox.notices.grounds.unknown') }}</option>
              <option value="a_not_processable">{{ t('databox.notices.grounds.a_not_processable') }}</option>
              <option value="b_no_effects">{{ t('databox.notices.grounds.b_no_effects') }}</option>
              <option value="c_wrong_way">{{ t('databox.notices.grounds.c_wrong_way') }}</option>
              <option value="d_wrong_format">{{ t('databox.notices.grounds.d_wrong_format') }}</option>
            </select>
          </label>
          <label class="block">
            <span class="mb-1 block text-sm">{{ t('databox.notices.deliveredOn') }}</span>
            <input v-model="noticeForm.delivered_on" type="date" class="form-input w-full" />
          </label>
          <label class="block">
            <span class="mb-1 block text-sm">{{ t('databox.notices.respondBy') }}</span>
            <input v-model="noticeForm.respond_by_on" type="date" class="form-input w-full" />
          </label>
          <label class="block">
            <span class="mb-1 block text-sm">{{ t('databox.notices.periodDays') }}</span>
            <input v-model.number="noticeForm.stated_period_days" type="number" min="1" max="366" class="form-input w-full" />
          </label>
          <label class="block sm:col-span-2">
            <span class="mb-1 block text-sm">{{ t('databox.notices.note') }}</span>
            <input v-model="noticeForm.note" type="text" class="form-input w-full" />
          </label>
        </div>
        <p class="mt-2 text-sm text-neutral-500">{{ t('databox.notices.deadlineHint') }}</p>
        <div class="mt-4 flex justify-end">
          <button type="button" :class="btnFilled('primary')" :disabled="saving" @click="submitNotice">
            {{ t('databox.notices.save') }}
          </button>
        </div>
      </div>

      <EmptyState v-if="!loading && notices.length === 0" icon="inbox" :title="t('databox.notices.empty')" />

      <div v-for="n in notices" :key="n.id" class="rounded-lg border border-neutral-200 bg-surface p-4">
        <div class="flex flex-wrap items-center gap-2">
          <span class="rounded-full px-2 py-0.5 text-xs" :class="noticeTone(n)">
            {{ t(`databox.notices.statuses.${n.assessment.status}`) }}
          </span>
          <span class="rounded-full bg-neutral-100 px-2 py-0.5 text-xs text-neutral-600">
            {{ t(`databox.notices.grounds.${n.defect_ground}`) }}
          </span>
          <span v-if="n.notice_reference" class="text-sm font-medium">{{ n.notice_reference }}</span>
        </div>

        <!-- Věta, podle které se dá jednat — ne technický kód stavu. -->
        <p class="mt-2 text-sm">{{ n.assessment.sentence }}</p>

        <div v-if="n.assessment.respond_by_shifted" class="mt-1 text-xs text-neutral-500">
          {{ t('databox.notices.shifted') }}
        </div>
        <div v-if="n.assessment.suspiciously_short_period" class="mt-1 text-xs text-warning-700 dark:text-warning-300">
          {{ t('databox.notices.shortPeriod') }}
        </div>

        <div class="mt-3 flex flex-wrap gap-2">
          <button
            v-if="!n.responded_on && n.status !== 'withdrawn'"
            type="button"
            :class="btnOutlineSm('primary')"
            @click="answerFor = answerFor === n.id ? null : n.id; answerDate = ''"
          >
            {{ t('databox.notices.answer') }}
          </button>
        </div>

        <div v-if="answerFor === n.id" class="mt-3 flex flex-wrap items-end gap-2">
          <label class="block">
            <span class="mb-1 block text-sm">{{ t('databox.notices.answeredOn') }}</span>
            <input v-model="answerDate" type="date" class="form-input" />
          </label>
          <button type="button" :class="btnFilled('primary')" :disabled="busyId === n.id" @click="submitAnswer(n)">
            {{ t('databox.notices.answerSave') }}
          </button>
        </div>
      </div>
    </section>

    <!-- ─────────────── Příjemci ─────────────── -->
    <section v-else class="space-y-4">
      <div
        v-if="recipientsWithoutBox.length"
        class="rounded-lg border border-warning-500/50 bg-warning-50 p-3 text-sm text-warning-800 dark:text-warning-200"
      >
        {{ t('databox.recipients.missingBoxIds', { count: recipientsWithoutBox.length }) }}
      </div>
      <p class="text-sm text-neutral-500">{{ t('databox.recipients.taxOfficeHint') }}</p>

      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead>
            <tr class="text-left text-xs uppercase text-neutral-400">
              <th class="py-2 pr-3">{{ t('databox.recipients.name') }}</th>
              <th class="py-2 pr-3">{{ t('databox.recipients.businessId') }}</th>
              <th class="py-2 pr-3">{{ t('databox.recipients.address') }}</th>
              <th class="py-2 pr-3">{{ t('databox.recipients.kind') }}</th>
              <th class="py-2 pr-3">{{ t('databox.recipients.boxId') }}</th>
              <th class="py-2 pr-3">{{ t('databox.recipients.source') }}</th>
              <th class="py-2 pr-3"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="r in recipients" :key="r.id" class="border-t border-neutral-100">
              <td class="py-2 pr-3">{{ r.name }}</td>
              <td class="py-2 pr-3"><code v-if="r.business_id">{{ r.business_id }}</code><span v-else>—</span></td>
              <td class="max-w-xs py-2 pr-3 text-xs text-neutral-600">{{ r.address ?? '—' }}</td>
              <td class="py-2 pr-3">{{ t(`databox.recipientKind.${r.kind}`) }}</td>
              <td class="py-2 pr-3">
                <code v-if="r.isds_box_id">{{ r.isds_box_id }}</code>
                <span v-else class="text-warning-700 dark:text-warning-300">{{ t('databox.recipients.noBoxId') }}</span>
              </td>
              <td class="py-2 pr-3 max-w-xs truncate">
                <a v-if="r.source_url" :href="r.source_url" target="_blank" rel="noopener" class="text-primary-600 hover:underline">
                  {{ t('databox.recipients.sourceLink') }}
                </a>
                <span v-else>—</span>
              </td>
              <td class="py-2 pr-3">
                <div class="flex flex-wrap gap-2">
                <button
                  type="button"
                  :class="btnOutlineSm('neutral')"
                  :disabled="busyId === r.id"
                  @click="editRecipient(r)"
                >
                  {{ r.is_system
                    ? t('databox.recipients.overrideForCompany')
                    : t('common.edit') }}
                </button>
                <button
                  v-if="!r.is_system"
                  type="button"
                  :class="btnOutlineSm('danger')"
                  :disabled="busyId === r.id"
                  @click="deleteRecipient(r)"
                >
                  {{ t('common.delete') }}
                </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="rounded-lg border border-neutral-200 bg-surface p-4">
        <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
          <h2 class="font-medium">
            {{ recipientEditingDefault
              ? t('databox.recipients.overrideTitle')
              : recipientEditing
                ? t('databox.recipients.editTitle')
                : t('databox.recipients.addTitle') }}
          </h2>
          <button
            v-if="recipientEditing"
            type="button"
            :class="btnOutlineSm('neutral')"
            @click="resetRecipientForm"
          >
            {{ t('databox.recipients.newRecord') }}
          </button>
        </div>
        <p
          v-if="recipientEditingDefault"
          class="mb-3 rounded-lg border border-warning-500/30 bg-warning-50 p-3 text-sm text-warning-800"
        >
          {{ t('databox.recipients.overrideHint') }}
        </p>
        <div class="grid gap-3 sm:grid-cols-2">
          <label class="block">
            <span class="text-sm font-medium">{{ t('databox.recipients.name') }}</span>
            <input
              v-model="recipientName"
              type="text"
              class="form-input mt-1 w-full"
              @input="recipientCodeSlug.fromName(($event.target as HTMLInputElement).value)"
            />
          </label>
          <label class="block">
            <span class="text-sm font-medium">{{ t('databox.recipients.code') }}</span>
            <input
              v-model="recipientCode"
              type="text"
              maxlength="48"
              class="form-input mt-1 w-full font-mono"
              :readonly="recipientEditing"
              @input="recipientCodeSlug.markManual(($event.target as HTMLInputElement).value)"
            />
            <span class="mt-1 block text-xs text-neutral-500">{{ t('databox.recipients.codeHint') }}</span>
          </label>
          <label class="block">
            <span class="text-sm font-medium">{{ t('databox.recipients.businessId') }}</span>
            <input v-model="recipientBusinessId" type="text" inputmode="numeric" maxlength="8" class="form-input mt-1 w-full" />
          </label>
          <label class="block">
            <span class="text-sm font-medium">{{ t('databox.recipients.address') }}</span>
            <input v-model="recipientAddress" type="text" maxlength="500" class="form-input mt-1 w-full" />
          </label>
          <label class="block">
            <span class="text-sm font-medium">{{ t('databox.recipients.kind') }}</span>
            <select v-model="recipientKind" class="form-select mt-1 w-full">
              <option value="tax_office">{{ t('databox.recipientKind.tax_office') }}</option>
              <option value="cssz">{{ t('databox.recipientKind.cssz') }}</option>
              <option value="health_insurer">{{ t('databox.recipientKind.health_insurer') }}</option>
              <option value="other">{{ t('databox.recipientKind.other') }}</option>
            </select>
          </label>
          <label class="block">
            <span class="text-sm font-medium">{{ t('databox.recipients.boxId') }}</span>
            <input v-model="recipientBoxId" type="text" maxlength="7" class="form-input mt-1 w-full" />
          </label>
          <label class="block sm:col-span-2">
            <span class="text-sm font-medium">{{ t('databox.recipients.source') }}</span>
            <input v-model="recipientSource" type="url" class="form-input mt-1 w-full" />
            <span class="mt-1 block text-xs text-neutral-500">
              {{ t('databox.recipients.sourceHint') }}
            </span>
          </label>
        </div>
      </div>

      <div class="sticky bottom-0 flex flex-wrap justify-end gap-2 border-t border-neutral-200 bg-surface/95 py-3">
        <button type="button" :class="btnFilled('primary')" :disabled="saving" @click="saveRecipient">
          <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" />
          </svg>
          {{ t('common.save') }}
        </button>
      </div>
    </section>
  </div>
</template>

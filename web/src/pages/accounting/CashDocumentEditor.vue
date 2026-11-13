<script setup lang="ts">
import { ref, reactive, computed, onMounted, onBeforeUnmount, watch, nextTick, useId } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter, RouterLink } from 'vue-router'
import {
  cashApi, type CashRegister, type CashVatLine, type CashPurpose, type CashDocument,
  type CashDocType, type CashDocumentStatus, type UnpaidDocumentOption, type CreateCashDocumentPayload,
  type CashRulePreset, UNPAID_PAGE_SIZE,
} from '@/api/cash'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import { accountingApi, type ChartAccount, type PostingRuleMap } from '@/api/accounting'
import { taxConstantsApi, type TaxConstantsYear } from '@/api/taxConstants'
import { clientsApi, type Client, type ClientRoleFilter } from '@/api/clients'
import { cashErrorMessage, cashWarningMessage } from '@/api/cashErrors'
import { useToast } from '@/composables/useToast'
import { useSupplierStore } from '@/stores/supplier'
import { formatMoney } from '@/composables/useFormat'
import CashVatBreakdown from '@/components/cash/CashVatBreakdown.vue'
import { ICONS, btnFilled, btnOutline, disabledTitle, BTN_DISABLED_NOTE } from '@/components/ui/buttonStyles'
import { appIsoDate } from '@/utils/date'
import DateInput from '@/components/ui/DateInput.vue'
import DimensionFields from '@/components/dimensions/DimensionFields.vue'
import NumberSeriesSelect from '@/components/numberSeries/NumberSeriesSelect.vue'
import { useDocumentDimensions } from '@/composables/useDocumentDimensions'
import { useEetCapabilities } from '@/composables/useEetCapabilities'
import { eetApi, type EetCreditNoteRefundInfo } from '@/api/eet'
import { documentsApi, type DocItem } from '@/api/documents'
import { useAuthStore } from '@/stores/auth'
import { useSidePreview } from '@/composables/useSidePreview'
import DocumentPreviewSplit from '@/components/documents/DocumentPreviewSplit.vue'
import LinkedDocumentsPanel from '@/components/documents/LinkedDocumentsPanel.vue'
import CapturePhotoButton from '@/components/upload/CapturePhotoButton.vue'
import RowActionsMenu, { type RowAction } from '@/components/ui/RowActionsMenu.vue'
import { useIsPhone } from '@/composables/useIsPhone'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const toast = useToast()
const auth = useAuthStore()
// Firma → Dimenze — dimenze hlavičky pokladního dokladu.
const docDims = useDocumentDimensions('cash-documents')
const pageId = useId()
const supplierStore = useSupplierStore()

// Daňová evidence (Epic DE §6): pokladna běží no-journal path — MD/D náhled zaúčtování
// je akruální (podvojný) koncept a v tomto režimu nedává smysl, proto se skryje.
const isTaxEvidence = computed(() => supplierStore.currentSupplier?.accounting_mode === 'tax_evidence')

const today = appIsoDate()
// „Vystavit a další" předává datum posledního zápisu v query; obyčejné otevření
// nového dokladu query nemá, a tak začíná dnešním datem.
const queryIssueDate = typeof route.query.issue_date === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(route.query.issue_date)
  ? route.query.issue_date
  : null
const initialDate = queryIssueDate ?? today
const formEl = ref<HTMLElement | null>(null)

const registers = ref<CashRegister[]>([])
const accounts = ref<ChartAccount[]>([])
const rules = ref<PostingRuleMap>({})
const taxYears = ref<TaxConstantsYear[]>([])
const saving = ref(false)
const error = ref('')

// Režim úprav rozpracovaného dokladu (routa /accounting/cash/:id/edit). Vystavený
// doklad backend přes PUT odmítne (`doc_not_draft`) — opravuje se stornem.
const editId = computed(() => Number(route.params.id ?? 0) || 0)
const isEdit = computed(() => editId.value > 0)
const loadedStatus = ref<CashDocumentStatus | null>(null)
const isDraft = computed(() => !isEdit.value || loadedStatus.value === 'draft')
// Plnění formuláře z API nesmí spustit reset-watchery určené pro ruční přepnutí.
const hydrating = ref(false)

// Originál dokladu: první příloha, kterou prohlížeč umí zobrazit. Nový doklad vazbu
// mít nemůže, proto drží vybraný sken jen v paměti prohlížeče (blob: URL) a do modulu
// Dokumenty ho nahraje a připojí až po uložení dokladu, stejně jako přílohy ručního
// zápisu nebo PDF nové přijaté faktury. Zrušení formuláře na serveru nic nenechá.
const preview = useSidePreview('cash_documents')
const linkedDocs = ref<DocItem[]>([])
const original = computed(() => linkedDocs.value.find(d => d.doc_type === 'pdf' || d.doc_type === 'image') ?? null)
const canAttachOriginal = computed(() =>
  auth.canRead('documents') && auth.canWrite('documents.upload') && auth.canWrite('documents.move'))
const pendingOriginal = ref<File | null>(null)
const pendingOriginalUrl = ref<string | null>(null)
const originalInput = ref<HTMLInputElement | null>(null)

function setPendingOriginal(file: File | null) {
  if (pendingOriginalUrl.value) URL.revokeObjectURL(pendingOriginalUrl.value)
  pendingOriginalUrl.value = file ? URL.createObjectURL(file) : null
  pendingOriginal.value = file
}
function onPickOriginal(e: Event) {
  const input = e.target as HTMLInputElement
  const file = input.files?.[0] ?? null
  input.value = ''
  if (!file) return
  if (file.type !== 'application/pdf' && !file.type.startsWith('image/')) {
    toast.error(t('cash.original.unsupported'))
    return
  }
  setPendingOriginal(file)
  preview.openTemporarily()
}
onBeforeUnmount(() => setPendingOriginal(null))

const previewSource = computed<{ src: string; name: string; docType: string } | null>(() => {
  if (original.value) {
    return { src: documentsApi.previewUrl(original.value.id), name: original.value.title, docType: original.value.doc_type }
  }
  if (pendingOriginal.value && pendingOriginalUrl.value) {
    return {
      src: pendingOriginalUrl.value,
      name: pendingOriginal.value.name,
      docType: pendingOriginal.value.type.startsWith('image/') ? 'image' : 'pdf',
    }
  }
  return null
})

/**
 * Nahraje originál vybraný před prvním uložením a připojí ho k vytvořenému dokladu.
 * Doklad už je uložený, takže chyba ho nevrací, jen upozorní. Když se soubor nahraje,
 * ale vazba selže, nahraný dokument se smaže, ať v Dokumentech nezůstane sirotek.
 * Upload i vazba běží v kontextu aktivní firmy a server vazbu na doklad cizí firmy odmítne.
 */
async function attachPendingOriginal(cashDocumentId: number): Promise<void> {
  const file = pendingOriginal.value
  if (!file) return
  setPendingOriginal(null)
  let uploadedIds: number[] = []
  try {
    const r = await documentsApi.upload([file], { zipMode: 'keep' })
    uploadedIds = r.root_ids ?? []
    if (uploadedIds.length === 0) throw new Error('upload_failed')
    for (const id of uploadedIds) await documentsApi.addLink(id, 'cash_document', cashDocumentId)
  } catch {
    await Promise.allSettled(uploadedIds.map(id => documentsApi.remove(id)))
    toast.warning(t('cash.original.attach_failed'))
  }
}

const form = reactive({
  register_id: '' as number | '',
  doc_type: (route.query.doc_type === 'out' ? 'out' : 'in') as CashDocType,
  purpose: 'sale' as CashPurpose,
  issue_date: initialDate,
  tax_date: initialDate,
  partner_name: '',
  partner_ic: '',
  partner_dic: '',
  description: '',
  vat_mode: 'none' as 'none' | 'vat',
  total_amount: null as number | null,
  // Valutová pokladna: ruční kurz. Prázdné → backend si vezme denní kurz ČNB.
  // Bez tohohle pole končil doklad na `fx_rate_unavailable` ve slepé uličce —
  // hláška říkala „doplňte kurz" a nebylo kam.
  fx_rate: null as number | null,
  invoice_id: null as number | null,
  purchase_invoice_id: null as number | null,
  counter_account_code: '',
  rule_key: '',
})
const vatLines = ref<CashVatLine[]>([])
// Sedí ručně zadaný rozpad DPH na celkovou částku? Komponenta zbytek sice dorovná
// do posledního řádku, ale při větším rozdílu tím vyrobí daň neodpovídající sazbě —
// uložit takový doklad nemá smysl (backend ho stejně přepočítá jinak).
const vatMatches = ref(true)

const selectedReg = computed<CashRegister | null>(() =>
  form.register_id !== '' ? registers.value.find(r => r.id === form.register_id) ?? null : null,
)
const registerAccount = computed(() => selectedReg.value?.account_code || '211')
// Valutová pokladna (§11): měna dokladu = měna pokladny; CZK ekvivalent počítá BE kurzem ČNB.
const registerCurrency = computed(() => (selectedReg.value?.currency_code || 'CZK').toUpperCase())
const isForeign = computed(() => registerCurrency.value !== 'CZK')

const purposeOptions = computed<CashPurpose[]>(() => {
  // Valutová pokladna v1: jen prodej/nákup/ostatní (úhrady faktur a převody = korunová pokladna).
  if (isForeign.value) return form.doc_type === 'in' ? ['sale', 'other'] : ['purchase', 'other']
  return form.doc_type === 'in'
    // `purchase_payment` na příjmovém dokladu = VRATKA úhrady přijaté faktury
    // (vrácené zboží, přeplatek) — účtuje se opačným směrem a snižuje daňový výdaj.
    ? ['sale', 'invoice_payment', 'purchase_payment', 'transfer', 'other']
    // `invoice_payment` na výdajovém dokladu = výplata dokladu k vyplacení (dobropis,
    // vyúčtování se zápornou částkou), jen se zapnutou volbou dodavatele.
    : supplierStore.currentSupplier?.allow_refund_invoices === true
      ? ['purchase', 'invoice_payment', 'purchase_payment', 'transfer', 'other']
      : ['purchase', 'purchase_payment', 'transfer', 'other']
})

// ── EET: tržba z příjmového dokladu se eviduje při zaúčtování ────────────────
// Zařízení namapované na pokladnu, jinak zvolené tady (předvybrané výchozí zařízení firmy).
// Hotovostní tržbu evidovat musí; bez zařízení ji server odmítne zaúčtovat.
const eetCaps = useEetCapabilities()
const eetSale = computed(() =>
  eetCaps.enabled.value && form.doc_type === 'in' && (form.purpose === 'sale' || form.purpose === 'invoice_payment'))
const eetDevices = computed(() => eetCaps.capabilities.value?.devices ?? [])
const eetDefaultDeviceId = computed<number | null>(() => {
  const id = eetCaps.capabilities.value?.default_device_id ?? null
  return id !== null && eetDevices.value.some(d => d.id === id) ? id : null
})
const eetMappedDevice = computed(() =>
  form.register_id === '' ? null : eetDevices.value.find(d => d.cash_register_id === form.register_id) ?? null)
const eetChosenDeviceId = ref<number | null>(null)
const eetSelectedDeviceId = computed<number | null>({
  get: () => eetChosenDeviceId.value ?? eetDefaultDeviceId.value,
  set: (id) => { eetChosenDeviceId.value = id },
})
const eetDeviceToSend = computed<number | null>(() =>
  eetSale.value && eetMappedDevice.value === null ? eetSelectedDeviceId.value : null)

const isTaxDoc = computed(() => form.purpose === 'sale' || form.purpose === 'purchase')
const isPayment = computed(() => form.purpose === 'invoice_payment' || form.purpose === 'purchase_payment')
const hasPartner = computed(() => isTaxDoc.value || isPayment.value)
const isTransfer = computed(() => form.purpose === 'transfer')
const isOther = computed(() => form.purpose === 'other')

// Daňové konstanty pro rok dokladu — sazby DPH i práh KH (A4/O5c). Bez záznamu
// pro daný rok se bere nejnovější dostupný (číselník bývá napřed, ne pozadu).
const taxEntry = computed<TaxConstantsYear | null>(() => {
  const year = Number((form.tax_date || form.issue_date || '').slice(0, 4))
  const exact = taxYears.value.find(y => y.year === year)
  if (exact) return exact
  return taxYears.value.length ? [...taxYears.value].sort((a, b) => b.year - a.year)[0] : null
})

const availableRates = computed<number[]>(() => {
  const entry = taxEntry.value
  if (!entry) return [21, 12]
  const rates = [entry.data.vat_rate_standard, entry.data.vat_rate_reduced]
    .map(r => Math.round(Number(r) * 100) / 100)
    .filter(r => r > 0)
  return [...new Set(rates)].sort((a, b) => b - a)
})

// Práh Kontrolního hlášení (A.4/B.2) čte backend z `tax_constants` per rok — FE ho
// měl natvrdo jako 10000, takže po legislativní změně by UI blokovalo něco, co
// backend povolí (nebo naopak). 10 000 zůstává jen jako záchrana, než dojede číselník.
const khThreshold = computed(() => {
  const value = Number(taxEntry.value?.data.kh_item_threshold ?? 0)
  return value > 0 ? value : 10000
})

const counterAccounts = computed(() =>
  accounts.value
    .filter(a => a.is_active && a.account_code !== registerAccount.value)
    .sort((a, b) => a.account_code.localeCompare(b.account_code)),
)

const counterAccountOptions = computed(() =>
  counterAccounts.value.map(a => ({ value: a.account_code, label: `${a.account_code} - ${a.name}` })),
)

const expenseAccountOptions = computed(() =>
  counterAccounts.value
    .filter(a => a.account_code.startsWith('5') && a.account_type === 'expense')
    .map(a => ({ value: a.account_code, label: `${a.account_code} - ${a.name}` })),
)

// ── Předvolby „co to je" pro purpose=other ─────────────────────────────────
// Server nabízí kontace s nohou na 211 (a bez vlastního purpose). Volba předvolby
// posílá `rule_key`, ne protiúčet — doklad si tak zachová vazbu na kontaci včetně
// per-tenant override. Backend vyžaduje PRÁVĚ JEDNO z rule_key / counter_account_code,
// proto se v `pickPreset()` druhé pole vždy vyprázdní.
const rulePresets = ref<CashRulePreset[]>([])
const presetsForDocType = computed(() => rulePresets.value.filter(p => p.doc_type === form.doc_type))

function pickPreset(key: string) {
  form.rule_key = key
  if (key) form.counter_account_code = ''
}
function pickCounterAccount(code: string) {
  form.counter_account_code = code
  if (code) form.rule_key = ''
}

/** Popisek předvolby: kontace je globální (bez i18n), proto se zobrazí i protiúčet. */
function presetLabel(p: CashRulePreset): string {
  const name = accounts.value.find(a => a.account_code === p.counter_account_code)?.name
  return name ? `${p.description} (${p.counter_account_code} - ${name})` : `${p.description} (${p.counter_account_code})`
}

function ruleAccount(key: string, side: 'debit' | 'credit', fallback: string): string {
  const r = rules.value[key]
  const code = r ? (side === 'debit' ? r.debit_account_code : r.credit_account_code) : null
  return code || fallback
}

onMounted(async () => {
  try {
    // G6 (audit 2026-07): osnova/kontace jsou double_entry-only (gatované 403 pro
    // tax_evidence) — MD/D náhled je v DE stejně skrytý (isTaxEvidence výše), takže
    // se ani nefetchuje, jinak by Promise.all shodil i načtení pokladen (registers).
    const [regs, accs, ruleMap, years, presets] = await Promise.all([
      cashApi.listRegisters(),
      isTaxEvidence.value ? Promise.resolve<ChartAccount[]>([]) : accountingApi.listAccounts(),
      isTaxEvidence.value ? Promise.resolve<PostingRuleMap>({}) : accountingApi.listPostingRules(),
      taxConstantsApi.list(),
      // Předvolby stojí na osnově a kontacích → v tax_evidence nedávají smysl (stejný
      // důvod jako u listPostingRules výše).
      isTaxEvidence.value ? Promise.resolve<CashRulePreset[]>([]) : cashApi.listRulePresets(),
    ])
    registers.value = regs
    accounts.value = accs
    rules.value = ruleMap
    taxYears.value = years
    rulePresets.value = presets
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  }
  if (editId.value > 0) {
    await loadDocument(editId.value)
  } else {
    // Výběr pokladny z query, jinak výchozí.
    const qReg = Number(route.query.register_id || 0)
    if (qReg > 0 && registers.value.some(r => r.id === qReg)) form.register_id = qReg
    else form.register_id = (registers.value.find(r => r.is_default) ?? registers.value[0])?.id ?? ''
  }
  await loadPartners('')
  dimsReady.value = true
})

/**
 * Rozpad DPH z API je v CZK — `convertVatLinesToCzk()` běží před uložením, protože
 * `cash_document_vat_lines` drží koruny stejně jako u faktury. Formulář ale pracuje
 * v měně pokladny (`form.total_amount = amount_foreign`), takže bez převodu zpět
 * stály korunové řádky proti cizoměnovému součtu: `CashVatBreakdown` dorovnává jen
 * jednořádkový rozpad, u dvou a víc sazeb tedy `matches` zůstalo false a valutový
 * draft nešlo z UI uložit („rozpad nesedí" u dokladu, který v DB sedí na haléř).
 *
 * Zaokrouhlovací zbytek z dělení kurzem padá na poslední řádek, aby Σ dala přesně
 * `amount_foreign` — stejným pravidlem, jakým rozpad při ukládání vzniká.
 */
function vatLinesInDocumentCurrency(doc: CashDocument): CashVatLine[] {
  const lines = doc.vat_lines ?? []
  const rate = Number(doc.fx_rate ?? 0)
  const foreign = doc.amount_foreign ?? null
  if (lines.length === 0 || foreign === null || !(rate > 0)) return lines
  const out: CashVatLine[] = lines.map(l => ({
    ...l,
    base_amount: Math.round((l.base_amount / rate) * 100) / 100,
    vat_amount: Math.round((l.vat_amount / rate) * 100) / 100,
  }))
  const sumC = out.reduce((s, l) => s + Math.round(l.base_amount * 100) + Math.round(l.vat_amount * 100), 0)
  const residual = Math.round(Number(foreign) * 100) - sumC
  if (residual !== 0) {
    const last = out[out.length - 1]
    last.vat_amount = Math.round(last.vat_amount * 100 + residual) / 100
  }
  return out
}

/**
 * Naplní formulář z existujícího draftu (režim úprav).
 *
 * `hydrating` vypne reset-watchery na doc_type/register_id/purpose: ty jsou psané
 * pro ruční přepnutí uživatelem a při plnění formuláře by vzápětí vymazaly vazbu
 * na fakturu, kontaci i protiúčet, které se právě načetly.
 */
// Řada z číselníku (víc řad v jedné pokladně, např. hotovostní účtenky vedle PPD).
// undefined = beze změny, null = výchozí řada pokladny / uživatele.
const numberSeriesId = ref<number | null | undefined>(undefined)
const seriesOption = ref<{ prefix: string | null; name: string } | null>(null)
// Nápověda pod datem ukazuje vybranou řadu, ne napevno PPD.
const seriesHintPrefix = computed(() => seriesOption.value ? (seriesOption.value.prefix || seriesOption.value.name || '') : '')

async function loadDocument(id: number): Promise<void> {
  hydrating.value = true
  void docDims.load(id)
  try {
    const doc = await cashApi.getDocument(id)
    loadedStatus.value = doc.status
    form.register_id = doc.register_id
    form.doc_type = doc.doc_type
    form.purpose = doc.purpose
    form.issue_date = doc.issue_date
    form.tax_date = doc.tax_date || doc.issue_date
    form.partner_name = doc.partner_name ?? ''
    form.partner_ic = doc.partner_ic ?? ''
    form.partner_dic = doc.partner_dic ?? ''
    form.description = doc.description
    form.vat_mode = doc.vat_mode
    form.total_amount = doc.amount_foreign ?? doc.total_amount
    form.fx_rate = doc.fx_rate ?? null
    form.invoice_id = doc.invoice_id
    form.purchase_invoice_id = doc.purchase_invoice_id
    form.rule_key = doc.rule_key ?? ''
    form.counter_account_code = doc.counter_account_code ?? ''
    numberSeriesId.value = doc.doc_number ? undefined : (doc.number_series_id ?? undefined)
    vatLines.value = vatLinesInDocumentCurrency(doc)
    if (doc.status !== 'draft') error.value = t('cash.error.doc_not_draft')
  } catch (e: any) {
    error.value = cashErrorMessage(e, t)
  } finally {
    await nextTick()
    hydrating.value = false
  }
}

// Přepínač typu / pokladny → resetuj účel na první platný (valutová pokladna nabízí méně účelů).
watch([() => form.doc_type, () => form.register_id], () => {
  if (hydrating.value) return
  if (!purposeOptions.value.includes(form.purpose)) form.purpose = purposeOptions.value[0]
  // Řada patří směru a pokladně.
  if (numberSeriesId.value != null) numberSeriesId.value = null
  // Předvolby jsou směrové (211 na MD = příjem, na D = výdej) — po přepnutí
  // PPD/VPD by zvolená kontace mířila na opačnou stranu.
  form.rule_key = ''
})

// Účel bez DPH → vynuť vat_mode none; při KAŽDÉ změně účelu vyčisti výběr úhrady
// (přepnutí FV→PF by jinak drželo starou fakturu a zamčenou částku).
watch(() => form.purpose, () => {
  if (hydrating.value) return
  if (!isTaxDoc.value) form.vat_mode = 'none'
  form.rule_key = ''
  form.counter_account_code = ''
  form.invoice_id = null
  form.purchase_invoice_id = null
  selectedUnpaid.value = null
  unpaidQuery.value = ''
  unpaidOptions.value = []
  // Partner patří k účelu (odběratel prodeje, plátce faktury, dodavatel), nesmí
  // zůstat na dokladu s jiným účelem.
  clearPartner()
})

// ── Našeptávač nezaplacených FV/PF ──────────────────────────────────────────
const unpaidQuery = ref('')
const unpaidOptions = ref<UnpaidDocumentOption[]>([])
// L-8: nabídka je oříznutá na UNPAID_PAGE_SIZE — bez téhle informace vypadá
// oseknutý seznam jako úplný a chybějící faktura jako neexistující.
const unpaidTruncated = ref(false)
const unpaidLoading = ref(false)
const unpaidError = ref('')
const selectedUnpaid = ref<UnpaidDocumentOption | null>(null)
let unpaidTimer: ReturnType<typeof setTimeout> | null = null

function onUnpaidSearch() {
  if (unpaidTimer) clearTimeout(unpaidTimer)
  unpaidTimer = setTimeout(async () => {
    const kind = form.purpose === 'invoice_payment' ? 'invoice' : 'purchase_invoice'
    // Vratka míří na fakturu, která JE zaplacená — běžná nabídka „zbývá uhradit" je prázdná.
    unpaidLoading.value = true
    unpaidError.value = ''
    try {
      const res = await cashApi.searchUnpaid(kind, unpaidQuery.value, 20, isPurchaseRefund.value || isInvoiceRefund.value)
      unpaidOptions.value = res.items
      unpaidTruncated.value = res.truncated
    } catch (e: any) {
      // Selhaný dotaz vypadal jako „žádné neuhrazené faktury nejsou" — uživatel pak
      // místo úhrady faktury vystaví hotovostní prodej a DPH se vykáže dvakrát (M-15).
      unpaidOptions.value = []
      unpaidError.value = cashErrorMessage(e, t)
    } finally { unpaidLoading.value = false }
  }, 300)
}

function pickUnpaid(o: UnpaidDocumentOption) {
  selectedUnpaid.value = o
  unpaidOptions.value = []
  unpaidQuery.value = o.number
  if (form.purpose === 'invoice_payment') { form.invoice_id = o.id; form.purchase_invoice_id = null }
  else { form.purchase_invoice_id = o.id; form.invoice_id = null }
  form.partner_name = o.partner_name
  form.partner_ic = o.partner_ic ?? ''
  form.partner_dic = o.partner_dic ?? ''
  form.description = t(`cash.purpose.${form.purpose}`) + ' ' + o.number
  // U vratky nabídni jako výchozí částku to, co je uhrazeno; jinak zbytek k úhradě.
  form.total_amount = isPurchaseRefund.value ? o.paid : o.remaining
}
// Korunový ekvivalent jde ukázat jen u ručního kurzu — kurz ČNB zná až backend.
const czkEquivalent = computed<number | null>(() => {
  if (!isForeign.value) return null
  const rate = Number(form.fx_rate)
  const amount = Number(form.total_amount)
  if (!(rate > 0) || !(amount > 0)) return null
  return Math.round(amount * rate * 100) / 100
})

/** Vratka úhrady přijaté faktury — příjmový doklad s účelem `purchase_payment`. */
const isPurchaseRefund = computed(() => form.purpose === 'purchase_payment' && form.doc_type === 'in')
/** Výplata dokladu k vyplacení — výdajový doklad s účelem `invoice_payment`, jen v plné výši. */
const isInvoiceRefund = computed(() => form.purpose === 'invoice_payment' && form.doc_type === 'out')

// EET: hotovostní vrácení k dobropisu se při zaúčtování zaeviduje jako záporná tržba,
// nejvýš do neopravené evidované části tržeb opravované faktury.
const eetRefund = ref<EetCreditNoteRefundInfo | null>(null)
watch(() => [isInvoiceRefund.value, form.invoice_id, eetCaps.enabled.value] as const, async ([refund, id, enabled]) => {
  eetRefund.value = null
  if (!refund || !id || !enabled) return
  try {
    const r = await eetApi.invoiceSummary(id)
    if (form.invoice_id === id) eetRefund.value = r.refund ?? null
  } catch {
    // Jen informace; o evidenci rozhoduje server při zaúčtování.
  }
})
const eetRefundAmount = computed<number | null>(() => {
  const r = eetRefund.value
  const remaining = Number(r?.evidenced.remaining_czk ?? 0)
  if (!r || remaining <= 0) return null
  return Math.min(Number(form.total_amount) || Number(r.refund_amount), remaining)
})

// PF: úhrada jen v plné výši (R4) → částka readonly. U vratky se naopak vrací
// libovolná část, takže částka zůstává editovatelná.
const amountReadonly = computed(() =>
  (form.purpose === 'purchase_payment' && !isPurchaseRefund.value || isInvoiceRefund.value)
  && selectedUnpaid.value !== null,
)

// ── Našeptávač partnera z číselníku klientů ─────────────────────────────────
// Hledá se na serveru přes `q` (stejně jako InvoiceEditor / VendorPicker) — pevný
// strop by při stovkách klientů většinu z nich z nabídky vyhodil. Partner zůstává
// volný text (§11/1/b, bez FK na clients); z vybraného klienta jen předvyplníme
// IČO a DIČ, které formulář sám hlídá kvůli A.4 kontrolního hlášení.
const clients = ref<Client[]>([])
const partnersFailed = ref(false)
let partnerTimer: ReturnType<typeof setTimeout> | null = null
let matchedPartner = ''
/** Klient z číselníku, jehož název partner přesně nese (zdroj výchozích dimenzí). */
const partnerClientId = ref<number | null>(null)

// Nákup a úhrada přijaté faktury = dodavatel, ostatní účely (prodej, úhrada FV) = odběratel.
const partnerRole = computed<ClientRoleFilter>(() =>
  (form.purpose === 'purchase' || form.purpose === 'purchase_payment' ? 'vendors' : 'customers'))

function clearPartner() {
  form.partner_name = ''
  form.partner_ic = ''
  form.partner_dic = ''
  matchedPartner = ''
  partnerClientId.value = null
}

async function loadPartners(q: string) {
  try {
    const res = await clientsApi.list({ q: q || undefined, role: partnerRole.value, archived: false, per_page: 50 })
    clients.value = res.data
    partnersFailed.value = false
    applyPartnerMatch()
  } catch (e: any) {
    clients.value = []
    // Prázdná nabídka po chybě je k nerozeznání od „klient neexistuje“ → řekni to jednou.
    if (!partnersFailed.value) toast.error(cashErrorMessage(e, t))
    partnersFailed.value = true
  }
}

function onPartnerSearch() {
  applyPartnerMatch()
  if (partnerTimer) clearTimeout(partnerTimer)
  partnerTimer = setTimeout(() => loadPartners(form.partner_name.trim()), 300)
}

// Datalist nemá vlastní „select“ událost — shodu poznáme podle přesného názvu.
// Doplňujeme jen při změně shody, ať to nepřepisuje ruční úpravu IČO/DIČ.
function applyPartnerMatch() {
  const name = form.partner_name.trim().toLocaleLowerCase()
  if (!name) { partnerClientId.value = null; return }
  if (name === matchedPartner) return
  const hit = clients.value.find(c => c.company_name.trim().toLocaleLowerCase() === name)
  if (!hit) { partnerClientId.value = null; return }
  matchedPartner = name
  partnerClientId.value = hit.id
  form.partner_ic = hit.ic ?? ''
  form.partner_dic = hit.dic ?? ''
}

// Výchozí dimenze: u úhrady dimenze placeného dokladu (včetně jeho výchozích),
// jinak výchozí dimenze partnera nalezeného v číselníku klientů.
const dimsReady = ref(false)
docDims.watchDefaults(
  () => (form.invoice_id
    ? { invoice_id: form.invoice_id }
    : form.purchase_invoice_id
      ? { purchase_invoice_id: form.purchase_invoice_id }
      : { client_id: partnerClientId.value }),
  () => dimsReady.value,
  () => editId.value <= 0,
)

// Přepnutí prodej ↔ nákup mění roli (odběratelé/dodavatelé) → načti nabídku znovu.
watch(partnerRole, () => { loadPartners(form.partner_name.trim()) })

// ── Klientský hint / validace ───────────────────────────────────────────────
// Práh KH je inkluzivní (>= 10 000 → A.4/B.2), proto >= i tady. U valutové pokladny
// je zadaná částka v cizí měně a FE nezná kurz → práh 10 000 CZK vyhodnotí BE (CZK
// ekvivalent); klientský hint se pro cizí měnu vypne, ať nesrovnává EUR s 10 000 CZK.
const purchaseOverLimit = computed(() =>
  !isForeign.value && form.purpose === 'purchase' && form.vat_mode === 'vat' && Number(form.total_amount) >= khThreshold.value,
)
const saleOver10k = computed(() =>
  !isForeign.value && form.purpose === 'sale' && form.vat_mode === 'vat'
    && Number(form.total_amount) >= khThreshold.value && !form.partner_dic.trim(),
)

// ── Live náhled zaúčtování (MD/D) ────────────────────────────────────────────
interface PreviewLine { account_code: string; side: 'debit' | 'credit'; amount: number }
const previewLines = computed<PreviewLine[]>(() => {
  const total = Number(form.total_amount) || 0
  const cash = registerAccount.value
  const vat = form.vat_mode === 'vat'
  const baseSum = vat ? vatLines.value.reduce((s, l) => s + l.base_amount, 0) : total
  const lines: PreviewLine[] = []
  const push = (code: string, side: 'debit' | 'credit', amount: number) => lines.push({ account_code: code, side, amount })
  switch (form.purpose) {
    case 'sale':
      push(cash, 'debit', total)
      push(ruleAccount('cash.revenue', 'credit', '602'), 'credit', baseSum)
      if (vat) for (const l of vatLines.value) push('343', 'credit', l.vat_amount)
      break
    case 'purchase':
      push(form.counter_account_code || ruleAccount('cash.purchase', 'debit', '501'), 'debit', baseSum)
      if (vat) for (const l of vatLines.value) push('343', 'debit', l.vat_amount)
      push(cash, 'credit', total)
      break
    case 'invoice_payment':
      if (isInvoiceRefund.value) {
        push(ruleAccount('payment.receivable.cash', 'credit', '311'), 'debit', total)
        push(cash, 'credit', total)
      } else {
        push(cash, 'debit', total)
        push(ruleAccount('payment.receivable.cash', 'credit', '311'), 'credit', total)
      }
      break
    case 'purchase_payment':
      if (isPurchaseRefund.value) {
        push(cash, 'debit', total)
        push(ruleAccount('payment.payable.cash', 'debit', '321'), 'credit', total)
      } else {
        push(ruleAccount('payment.payable.cash', 'debit', '321'), 'debit', total)
        push(cash, 'credit', total)
      }
      break
    case 'transfer':
      if (form.doc_type === 'in') {
        push(cash, 'debit', total)
        push(ruleAccount('cash.transfer.frombank', 'credit', '261'), 'credit', total)
      } else {
        push(ruleAccount('cash.deposit.cashtobank', 'debit', '261'), 'debit', total)
        push(cash, 'credit', total)
      }
      break
    case 'other': {
      // Při zvolené předvolbě protiúčet odvodí server z kontace — v náhledu ho
      // vezmeme z předvolby, ať MD/D sedí s tím, co se doopravdy zaúčtuje.
      const fromPreset = form.rule_key
        ? rulePresets.value.find(p => p.rule_key === form.rule_key)?.counter_account_code
        : ''
      const counter = form.counter_account_code || fromPreset || '—'
      if (form.doc_type === 'in') { push(cash, 'debit', total); push(counter, 'credit', total) }
      else { push(counter, 'debit', total); push(cash, 'credit', total) }
      break
    }
  }
  return lines
})
const previewDebit = computed(() => previewLines.value.filter(l => l.side === 'debit').reduce((s, l) => s + l.amount, 0))
const previewCredit = computed(() => previewLines.value.filter(l => l.side === 'credit').reduce((s, l) => s + l.amount, 0))

function accountName(code: string): string {
  return accounts.value.find(a => a.account_code === code)?.name ?? ''
}

// ── Uložení ──────────────────────────────────────────────────────────────────
const vatBreakdownVisible = computed(() => isTaxDoc.value && form.vat_mode === 'vat')
const vatBreakdownInvalid = computed(() => vatBreakdownVisible.value && (!vatMatches.value || vatLines.value.length === 0))

const canSubmit = computed(() =>
  form.register_id !== '' && Number(form.total_amount) > 0 && form.description.trim() !== ''
  && !purchaseOverLimit.value && !vatBreakdownInvalid.value && !saving.value,
)

/** Věta pod zašedlým tlačítkem — proč uložit nejde (konvence buttonStyles.ts). */
const blockedReason = computed<string | null>(() => {
  if (form.register_id === '') return t('cash.validation.register')
  if (!(Number(form.total_amount) > 0)) return t('cash.validation.amount')
  if (!form.description.trim()) return t('cash.validation.description')
  if (purchaseOverLimit.value) return t('cash.form.purchase_over_10k_hint', { amount: formatMoney(khThreshold.value) })
  if (vatBreakdownInvalid.value) return t('cash.validation.vat_lines')
  return null
})

/**
 * `post = false` → doklad zůstane rozpracovaný (draft): číslo řady se nepřidělí
 * a nic se nezaúčtuje. Do audit 2026-08 byl `post: true` natvrdo, takže cesta
 * „uložit a dodělat později" i editace existujícího draftu byly z UI nedosažitelné,
 * přestože je backend umí (POST … {post:false}, PUT {id}, POST {id}/post).
 */
async function save(post = true, next = false) {
  error.value = ''
  if (isEdit.value && !isDraft.value) { error.value = t('cash.error.doc_not_draft'); return }
  if (form.register_id === '') { error.value = t('cash.validation.register'); return }
  if (!(Number(form.total_amount) > 0)) { error.value = t('cash.validation.amount'); return }
  if (!form.description.trim()) { error.value = t('cash.validation.description'); return }
  if (!/^\d{4}-\d{2}-\d{2}$/.test(form.issue_date)) { error.value = t('cash.validation.date'); return }
  if (vatBreakdownVisible.value && !/^\d{4}-\d{2}-\d{2}$/.test(form.tax_date || form.issue_date)) {
    error.value = t('cash.validation.tax_date'); return
  }
  if (isForeign.value && form.fx_rate !== null && !(Number(form.fx_rate) > 0)) {
    error.value = t('cash.validation.fx_rate'); return
  }
  if (purchaseOverLimit.value) {
    error.value = t('cash.form.purchase_over_10k_hint', { amount: formatMoney(khThreshold.value) }); return
  }
  if (vatBreakdownInvalid.value) { error.value = t('cash.validation.vat_lines'); return }

  // Úprava draftu je částečná (chybějící klíč = uložená hodnota), proto se posílá
  // každé pole formuláře a nepoužité/prázdné explicitně jako null.
  const withVat = isTaxDoc.value && form.vat_mode === 'vat'
  const payload: CreateCashDocumentPayload = {
    register_id: Number(form.register_id),
    doc_type: form.doc_type,
    number_series_id: numberSeriesId.value,
    purpose: form.purpose,
    issue_date: form.issue_date,
    description: form.description.trim(),
    total_amount: Number(form.total_amount),
    // Valutová pokladna: zadaná částka je v měně pokladny; CZK ekvivalent dopočítá BE
    // kurzem ČNB, pokud uživatel nezadal vlastní kurz (fx_rate null).
    amount_foreign: isForeign.value ? Number(form.total_amount) : null,
    fx_rate: isForeign.value && Number(form.fx_rate) > 0 ? Number(form.fx_rate) : null,
    // U úhrady faktury je partner plátce (předvyplněný z faktury, jde přepsat);
    // prázdný doplní backend z faktury.
    partner_name: hasPartner.value ? form.partner_name.trim() || null : null,
    partner_ic: hasPartner.value ? form.partner_ic.trim() || null : null,
    partner_dic: hasPartner.value ? form.partner_dic.trim() || null : null,
    vat_mode: withVat ? 'vat' : 'none',
    tax_date: withVat ? form.tax_date || form.issue_date : null,
    vat_lines: withVat ? vatLines.value : [],
    invoice_id: form.purpose === 'invoice_payment' && form.invoice_id ? form.invoice_id : null,
    purchase_invoice_id: form.purpose === 'purchase_payment' && form.purchase_invoice_id ? form.purchase_invoice_id : null,
    counter_account_code: (isOther.value || (form.purpose === 'purchase' && !isTaxEvidence.value)) && form.counter_account_code ? form.counter_account_code : null,
    rule_key: isOther.value && !form.counter_account_code && form.rule_key ? form.rule_key : null,
    post,
    eet_device_id: eetDeviceToSend.value,
  }

  saving.value = true
  try {
    if (isEdit.value) {
      await cashApi.updateDocument(editId.value, payload)
      // Dimenze před zaúčtováním, ať je zápis dostane rovnou.
      await docDims.save(editId.value)
      if (post) {
        const res = eetDeviceToSend.value !== null
          ? await cashApi.postDocument(editId.value, eetDeviceToSend.value)
          : await cashApi.postDocument(editId.value)
        toast.success(t('cash.new_document') + ' ' + res.doc_number)
        for (const w of res.warnings) toast.warning(cashWarningMessage(w, t))
      } else {
        toast.success(t('common.saved'))
      }
    } else {
      const res = await cashApi.createDocument(payload)
      // Zaúčtovaný doklad dostane dimenze přerazítkováním (mění se jen analytika).
      await docDims.save(res.id)
      await attachPendingOriginal(res.id)
      toast.success(post ? t('cash.new_document') + ' ' + (res.doc_number ?? '') : t('common.saved'))
      for (const w of res.warnings) toast.warning(cashWarningMessage(w, t))
      if (next) {
        await startNextDocument()
        return
      }
    }
    router.push('/accounting/cash')
  } catch (e: any) {
    error.value = cashErrorMessage(e, t)
  } finally {
    saving.value = false
  }
}

/**
 * „Vystavit a další": nový prázdný doklad ve stejné pokladně, se stejným typem
 * a datem posledního zápisu. Routa zůstává stejná (jen jiné query), takže Vue
 * komponentu znovu nepřipojí a formulář se vyprázdní tady.
 */
async function startNextDocument(): Promise<void> {
  const issueDate = form.issue_date
  void router.replace({
    path: '/accounting/cash/new',
    query: { register_id: String(form.register_id), doc_type: form.doc_type, issue_date: issueDate },
  })
  form.tax_date = issueDate
  form.purpose = purposeOptions.value[0]
  form.description = ''
  form.vat_mode = 'none'
  form.total_amount = null
  form.fx_rate = null
  form.invoice_id = null
  form.purchase_invoice_id = null
  form.counter_account_code = ''
  form.rule_key = ''
  clearPartner()
  vatLines.value = []
  vatMatches.value = true
  selectedUnpaid.value = null
  unpaidQuery.value = ''
  unpaidOptions.value = []
  unpaidTruncated.value = false
  unpaidError.value = ''
  docDims.header.value = {}
  docDims.autoFilled.value = {}
  error.value = ''
  await nextTick()
  formEl.value?.querySelector<HTMLElement>('input:not([type="hidden"]):not([readonly]):not([disabled]), select:not([disabled]), textarea:not([disabled])')?.focus()
}

// Telefon: nejdřív Typ, Pokladna, Datum a Částka; partner a dimenze pod „Další údaje"
// (otevřené, když už nesou hodnotu nebo formulář hlásí chybu), lišta akcí přilepená
// dole s Vystavit jako hlavní akcí a náhled zaúčtování sbalitelný. Mimo telefon beze změny.
const isPhone = useIsPhone()
const moreDetailsOpen = ref(false)
const moreDetailsFilled = computed(() =>
  !!(form.partner_name?.trim() || form.partner_ic?.trim() || form.partner_dic?.trim())
  || Object.values(docDims.header.value ?? {}).some(v => v != null),
)
const moreDetailsNeeded = computed(() => moreDetailsFilled.value || !!error.value || saleOver10k.value)
const showMoreDetails = computed(() => !isPhone.value || moreDetailsOpen.value || moreDetailsNeeded.value)
const hasMoreDetails = computed(() => hasPartner.value || docDims.enabled.value)
const postingPreviewOpen = ref(false)
const showPostingPreview = computed(() => !isPhone.value || postingPreviewOpen.value)
const phoneFooterActions = computed<RowAction[]>(() => [
  {
    key: 'post-and-next', label: t('cash.post_and_next'), icon: 'plus', variant: 'primary',
    run: () => { void save(true, true) }, show: !isEdit.value,
    disabled: !canSubmit.value, disabledReason: blockedReason.value ?? undefined,
  },
  {
    key: 'draft', label: t('cash.save_draft'), icon: 'doc', variant: 'neutral',
    run: () => { void save(false) },
    disabled: !canSubmit.value, disabledReason: blockedReason.value ?? undefined,
  },
  { key: 'cancel', label: t('common.cancel'), icon: 'x', variant: 'neutral', to: '/accounting/cash' },
])
</script>

<template>
  <DocumentPreviewSplit :ctrl="preview" :show="!!previewSource && preview.open.value"
    :src="previewSource?.src ?? null" :file-name="previewSource?.name" :doc-type="previewSource?.docType">
    <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
      <div>
        <h1 class="text-2xl font-semibold">{{ isEdit ? t('cash.edit_document') : t('cash.new_document') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5">{{ form.doc_type === 'in' ? t('cash.type.in') : t('cash.type.out') }}</p>
      </div>
      <div class="flex flex-wrap items-center gap-3">
        <template v-if="!isEdit && canAttachOriginal">
          <input ref="originalInput" type="file" accept="application/pdf,image/*" class="hidden" data-test="cash-original-file" @change="onPickOriginal" />
          <span v-if="pendingOriginal" class="text-sm text-neutral-600 truncate max-w-[16rem]" :title="t('cash.original.pending_hint')" data-test="cash-original-name">{{ pendingOriginal.name }}</span>
          <button v-if="pendingOriginal" type="button" :class="[btnOutline('danger'), 'whitespace-nowrap']" data-test="cash-original-remove" @click="setPendingOriginal(null)">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.trash" /></svg>
            {{ t('cash.original.remove') }}
          </button>
          <button v-else type="button" :class="[btnOutline('primary'), 'whitespace-nowrap']" data-test="cash-original-pick" @click="originalInput?.click()">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.upload" /></svg>
            {{ t('cash.original.pick') }}
          </button>
          <CapturePhotoButton v-if="isPhone && !pendingOriginal" data-test="cash-original-capture" @captured="setPendingOriginal" />
        </template>
        <button v-if="previewSource" type="button" :class="btnOutline('neutral')" data-test="cash-preview-toggle"
          :aria-pressed="preview.open.value" @click="preview.toggle()">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="preview.open.value ? ICONS.eyeOff : ICONS.eye" /></svg>
          {{ preview.open.value ? t('common.preview_side_hide') : t('common.preview_side_show') }}
        </button>
        <RouterLink to="/accounting/cash" class="text-sm text-neutral-500 hover:text-neutral-700">{{ t('common.back') }}</RouterLink>
      </div>
    </div>

    <datalist :id="`${pageId}-cash-partners`">
      <option v-for="c in clients" :key="c.id" :value="c.company_name">{{ c.ic || '' }}</option>
    </datalist>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
      <!-- Formulář -->
      <div ref="formEl" :class="isTaxEvidence ? 'lg:col-span-3' : 'lg:col-span-2'" class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-5 space-y-4 max-md:p-4 max-md:flex max-md:flex-col max-md:gap-4 max-md:space-y-0">
        <!-- Hlavička -->
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 max-md:-order-2">
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('cash.col.type') }}</label>
            <div class="inline-flex rounded-md border border-neutral-300 overflow-hidden w-full">
              <button type="button" @click="form.doc_type = 'in'"
                class="cursor-pointer flex-1 h-10 text-sm font-medium"
                :class="form.doc_type === 'in' ? 'bg-success-600 text-white' : 'bg-surface text-neutral-600 hover:bg-neutral-50'">
                {{ t('cash.type.in_short') }}
              </button>
              <button type="button" @click="form.doc_type = 'out'"
                class="cursor-pointer flex-1 h-10 text-sm font-medium border-l border-neutral-300"
                :class="form.doc_type === 'out' ? 'bg-warning-600 text-white' : 'bg-surface text-neutral-600 hover:bg-neutral-50'">
                {{ t('cash.type.out_short') }}
              </button>
            </div>
          </div>
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('cash.register') }}</label>
            <select v-model="form.register_id" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm bg-surface">
              <!-- Bez prázdné položky vypadal select s nenačtenými pokladnami jako by
                   pokladna vybraná byla; odkaz na správu je jediná cesta, jak ji založit. -->
              <option value="">—</option>
              <option v-for="r in registers" :key="r.id" :value="r.id">{{ r.name }} ({{ r.account_code }})</option>
            </select>
            <RouterLink v-if="registers.length === 0" to="/accounting/cash"
              class="text-xs text-primary-600 hover:text-primary-700 mt-1 inline-block">{{ t('cash.registers_manage') }}</RouterLink>
          </div>
          <NumberSeriesSelect v-if="form.register_id !== '' && loadedStatus !== 'posted' && loadedStatus !== 'reversed'"
            :scope="{ module: 'cash-documents', docType: form.doc_type, registerId: Number(form.register_id) }"
            v-model="numberSeriesId" @option="seriesOption = $event" />
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('cash.col.date') }}</label>
            <DateInput v-model="form.issue_date" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
          </div>
        </div>
        <p class="text-xs text-neutral-400 -mt-2 max-md:-order-2" data-test="cash-number-hint">{{ seriesHintPrefix ? t('cash.form.number_hint_series', { series: seriesHintPrefix }) : t('cash.form.number_hint') }}</p>

        <!-- Účel -->
        <div>
          <label class="block text-sm font-medium text-neutral-700 mb-2">{{ t('cash.col.link') }}</label>
          <div class="flex flex-wrap gap-2">
            <button v-for="p in purposeOptions" :key="p" type="button" @click="form.purpose = p"
              class="cursor-pointer px-3 h-9 text-sm rounded-md border"
              :class="form.purpose === p ? 'border-primary-500 bg-primary-50 text-primary-700 font-medium' : 'border-neutral-300 text-neutral-600 hover:bg-neutral-50'">
              {{ t(`cash.purpose.${p}`) }}
            </button>
          </div>
        </div>

        <!-- (b) Úhrada faktury -->
        <template v-if="isPayment">
          <div class="relative">
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('cash.form.pick_invoice') }}</label>
            <input v-model="unpaidQuery" @input="onUnpaidSearch" type="text"
              class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
            <div v-if="unpaidLoading" class="text-xs text-neutral-400 mt-1">{{ t('common.loading') }}</div>
            <p v-else-if="unpaidError" class="text-xs text-danger-500 mt-1">{{ unpaidError }}</p>
            <ul v-if="unpaidOptions.length" class="absolute z-10 mt-1 w-full bg-surface border border-neutral-200 rounded-md shadow-lg max-h-64 overflow-y-auto">
              <li v-for="o in unpaidOptions" :key="o.id" @click="pickUnpaid(o)"
                class="cursor-pointer px-3 py-2 text-sm hover:bg-neutral-50 flex items-center justify-between gap-2">
                <span>
                  <span class="font-mono">{{ o.number }}</span> · {{ o.partner_name }}
                  <!-- Proforma se účtuje jako přijatá záloha (211/324) a spouští navazující
                       doklad — v nabídce musí jít poznat na první pohled. -->
                  <span v-if="o.is_proforma"
                    class="ml-1 text-xs px-1.5 py-0.5 rounded bg-warning-50 text-warning-600 whitespace-nowrap">
                    {{ t('cash.form.proforma_badge') }}
                  </span>
                </span>
                <span class="text-neutral-500 font-mono">{{ t('cash.form.remaining') }} {{ formatMoney(o.remaining) }}</span>
              </li>
              <li v-if="unpaidTruncated" class="px-3 py-2 text-xs text-neutral-500 border-t border-neutral-100 bg-neutral-50">
                {{ t('cash.form.unpaid_truncated', { count: UNPAID_PAGE_SIZE }) }}
              </li>
            </ul>
          </div>
        </template>

        <button v-if="isPhone && hasMoreDetails" type="button" data-test="cash-more-details-toggle"
          class="order-1 cursor-pointer min-h-10 inline-flex items-center justify-between gap-2 rounded-md border border-neutral-200 px-3 text-sm font-medium text-neutral-700"
          :aria-expanded="showMoreDetails" :disabled="moreDetailsNeeded" @click="moreDetailsOpen = !moreDetailsOpen">
          {{ t('cash.form.more_details') }}
          <svg class="w-4 h-4 transition-transform" :class="{ 'rotate-180': showMoreDetails }" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" /></svg>
        </button>

        <!-- Partner: (a) odběratel/dodavatel prodeje a nákupu, (b) plátce faktury (předvyplněný z faktury) -->
        <template v-if="hasPartner && showMoreDetails">
          <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 max-md:order-1" data-test="cash-partner-fields">
            <div class="sm:col-span-1">
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('cash.form.partner') }}</label>
              <input v-model="form.partner_name" @input="onPartnerSearch" :list="`${pageId}-cash-partners`" type="text"
                class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
            </div>
            <div>
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('cash.form.partner_ic') }}</label>
              <input v-model="form.partner_ic" type="text" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
            </div>
            <div>
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('cash.form.partner_dic') }}</label>
              <input v-model="form.partner_dic" type="text" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
            </div>
          </div>
        </template>

        <!-- (a) Prodej / Nákup -->
        <template v-if="isTaxDoc">
          <div v-if="form.purpose === 'purchase' && !isTaxEvidence">
            <label :for="`${pageId}-cash-expense-account`" class="block text-sm font-medium text-neutral-700 mb-1">{{ t('cash.form.expense_account') }}</label>
            <SearchableSelect :model-value="form.counter_account_code || null" :options="expenseAccountOptions"
              data-testid="cash-expense-account" :input-id="`${pageId}-cash-expense-account`"
              :placeholder="t('cash.form.expense_account_default', { account: ruleAccount('cash.purchase', 'debit', '501') })"
              @update:model-value="pickCounterAccount(($event as string | null) ?? '')" />
          </div>
          <p v-if="form.purpose === 'sale'" class="text-xs text-neutral-500 bg-neutral-50 border border-neutral-200 rounded-md px-3 py-2">
            {{ t('cash.form.duplicate_vat_hint') }}
          </p>
        </template>

        <!-- (c) Převod -->
        <p v-if="isTransfer" class="text-xs text-neutral-500 bg-neutral-50 border border-neutral-200 rounded-md px-3 py-2">
          {{ t('cash.form.transfer_leg_hint') }}
        </p>

        <!-- (d) Ostatní -->
        <template v-if="isOther">
          <!-- Předvolba kontace („co to je") — vyplní protiúčet za uživatele. -->
          <div v-if="presetsForDocType.length">
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('cash.form.rule_key') }}</label>
            <select :value="form.rule_key" @change="pickPreset(($event.target as HTMLSelectElement).value)"
              class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm bg-surface">
              <option value="">{{ t('cash.form.rule_key_custom') }}</option>
              <option v-for="p in presetsForDocType" :key="p.rule_key" :value="p.rule_key">{{ presetLabel(p) }}</option>
            </select>
            <p class="text-xs text-neutral-500 mt-1">{{ t('cash.form.rule_key_hint') }}</p>
          </div>
          <!-- Volný protiúčet zůstává pro případy, které kontace nepokrývá. -->
          <div v-if="!form.rule_key">
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('cash.form.counter_account') }}</label>
            <!-- L-8: celá osnova v prostém <select> je u rozsáhlé osnovy nepoužitelná
                 (stovky položek bez hledání) — stejný vzor jako jinde v aplikaci. -->
            <SearchableSelect
              :model-value="form.counter_account_code || null"
              :options="counterAccountOptions"
              :placeholder="t('cash.form.counter_account_search')"
              @update:model-value="pickCounterAccount(($event as string | null) ?? '')" />
          </div>
        </template>

        <!-- Popis -->
        <div>
          <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('cash.col.description') }}</label>
          <input v-model="form.description" type="text" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
        </div>

        <div v-if="docDims.enabled.value && showMoreDetails" class="max-md:order-1" data-test="cash-header-dimensions">
          <p class="block text-sm font-medium text-neutral-700 mb-1">{{ t('dimensions.header_title') }}</p>
          <DimensionFields v-model="docDims.header.value" :disabled="!docDims.canEdit.value" />
          <p v-if="docDims.hasAutoFilled.value" class="text-xs text-neutral-500 mt-1" data-test="dimension-autofilled">{{ t('dimensions.defaults.autofilled') }}</p>
        </div>

        <!-- Částka + DPH přepínač -->
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 items-end max-md:-order-1">
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">
              {{ t('cash.form.total_incl') }}
              <span v-if="isForeign" class="font-mono text-primary-600">({{ registerCurrency }})</span>
            </label>
            <input v-model.number="form.total_amount" type="number" step="0.01" min="0" :readonly="amountReadonly"
              :inputmode="isPhone ? 'decimal' : undefined" data-test="cash-total-amount"
              class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm text-right"
              :class="{ 'bg-neutral-100': amountReadonly }" />
            <p v-if="isForeign" class="mt-1 text-xs text-neutral-500">{{ t('cash.form.foreign_hint') }}</p>
          </div>
          <div v-if="isTaxDoc">
            <label class="block text-sm font-medium text-neutral-700 mb-1">&nbsp;</label>
            <div class="inline-flex rounded-md border border-neutral-300 overflow-hidden w-full">
              <button type="button" @click="form.vat_mode = 'none'"
                class="cursor-pointer flex-1 h-10 text-sm"
                :class="form.vat_mode === 'none' ? 'bg-primary-600 text-white' : 'bg-surface text-neutral-600 hover:bg-neutral-50'">
                {{ t('cash.form.vat_mode_none') }}
              </button>
              <button type="button" @click="form.vat_mode = 'vat'"
                class="cursor-pointer flex-1 h-10 text-sm border-l border-neutral-300"
                :class="form.vat_mode === 'vat' ? 'bg-primary-600 text-white' : 'bg-surface text-neutral-600 hover:bg-neutral-50'">
                {{ t('cash.form.vat_mode_vat') }}
              </button>
            </div>
          </div>
        </div>

        <!-- Valutová pokladna: ruční kurz. Bez tohohle pole byl doklad s chybějícím
             denním kurzem ČNB nezachranitelný — `fx_rate_unavailable` hlásí „doplňte
             kurz", ale zadat ho nešlo, takže se doklad nedal dokončit vůbec. -->
        <div v-if="isForeign" class="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <div>
            <label :for="`${pageId}-cash-fx-rate`" class="block text-sm font-medium text-neutral-700 mb-1">
              {{ t('cash.form.fx_rate') }}
              <span class="font-mono text-primary-600">({{ registerCurrency }}/CZK)</span>
            </label>
            <input :id="`${pageId}-cash-fx-rate`" v-model.number="form.fx_rate" type="number" step="0.0001" min="0"
              class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm text-right" />
            <p v-if="czkEquivalent !== null" class="mt-1 text-xs text-neutral-600 font-mono">
              {{ t('cash.form.fx_amount_czk') }}: {{ formatMoney(czkEquivalent) }}
            </p>
          </div>
          <p class="text-xs text-neutral-500 self-end pb-2">{{ t('cash.form.fx_rate_hint') }}</p>
        </div>

        <!-- DUZP + DPH rozpad -->
        <template v-if="isTaxDoc && form.vat_mode === 'vat'">
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div>
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('cash.col.tax_date') }}</label>
              <DateInput v-model="form.tax_date" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
            </div>
          </div>
          <CashVatBreakdown v-model="vatLines" :total="Number(form.total_amount) || 0" :rates="availableRates"
            :deduction="form.purpose === 'purchase'" @update:matches="vatMatches = $event" />
          <p v-if="form.purpose === 'purchase'" class="text-xs px-3 py-2 rounded-md"
            :class="purchaseOverLimit ? 'bg-danger-50 text-danger-600' : 'bg-neutral-50 text-neutral-500 border border-neutral-200'">
            {{ t('cash.form.purchase_over_10k_hint', { amount: formatMoney(khThreshold) }) }}
          </p>
          <p v-if="saleOver10k" class="text-xs px-3 py-2 rounded-md bg-warning-50 text-warning-600">
            {{ t('cash.warning.dic_missing_over_10k') }}
          </p>
          <p class="text-xs text-neutral-400">{{ t('cash.form.simplified_limit_hint') }}</p>
        </template>

        <!-- EET: zařízení pro tržbu z tohoto dokladu -->
        <div v-if="eetSale && isDraft" class="rounded-md border px-3 py-2 text-sm"
          :class="eetMappedDevice ? 'border-primary-200 bg-primary-50 text-primary-800'
            : eetSelectedDeviceId === null ? 'border-warning-200 bg-warning-50 text-warning-800'
            : 'border-neutral-200 bg-neutral-50 text-neutral-700'"
          data-test="cash-eet-info">
          <p v-if="eetMappedDevice">
            {{ t('cash.eet.mapped', { device: `${eetMappedDevice.code}${eetMappedDevice.name ? ' - ' + eetMappedDevice.name : ''}` }) }}
          </p>
          <template v-else-if="eetDevices.length > 0">
            <label :for="`${pageId}-cash-eet-device`" class="block font-medium mb-1">{{ t('cash.eet.device') }}</label>
            <select :id="`${pageId}-cash-eet-device`" v-model="eetSelectedDeviceId"
              class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm bg-surface" data-test="cash-eet-device">
              <option v-if="eetSelectedDeviceId === null" :value="null" disabled>{{ t('cash.eet.device') }}</option>
              <option v-for="d in eetDevices" :key="d.id" :value="d.id">
                {{ d.code }}{{ d.name ? ' - ' + d.name : '' }} ({{ d.unit_number }}){{ d.id === eetDefaultDeviceId ? ' · ' + t('cash.eet.default') : '' }}
              </option>
            </select>
            <p class="mt-1 text-xs" :class="eetSelectedDeviceId === null ? 'text-warning-700' : 'text-neutral-500'">
              {{ eetSelectedDeviceId === null ? t('cash.eet.device_required') : t('cash.eet.chosen') }}
            </p>
          </template>
          <p v-else data-test="cash-eet-device-required">{{ t('cash.eet.device_required') }}</p>
        </div>
        <div v-if="eetRefundAmount !== null && isDraft" class="rounded-md border border-primary-200 bg-primary-50 px-3 py-2 text-sm text-primary-800"
          data-test="cash-eet-refund">
          {{ t('cash.eet.refund_info', { amount: formatMoney(eetRefundAmount) }) }}
        </div>

        <div v-if="error" class="text-sm text-danger-500 max-md:order-2">{{ error }}</div>

        <div v-if="isPhone" data-test="cash-phone-footer"
          class="order-2 sticky bottom-[calc(var(--app-footer-height,0px)+0.5rem)] z-10 -mx-1 rounded-lg border border-neutral-200 bg-surface p-3 shadow-lg flex flex-col gap-1.5">
          <div class="flex items-center gap-2 [&_button]:min-h-10">
            <button @click="save(true)" :disabled="!canSubmit" :class="btnFilled('primary')" class="flex-1 justify-center"
              :title="disabledTitle(!canSubmit, blockedReason)" data-test="cash-phone-post">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
              {{ saving ? t('common.saving') : t('cash.post_document') }}
            </button>
            <RowActionsMenu :actions="phoneFooterActions" :inline-count="0" />
          </div>
          <p v-if="blockedReason" :class="BTN_DISABLED_NOTE">{{ blockedReason }}</p>
        </div>
        <div v-else class="border-t border-neutral-200 pt-3 flex flex-col items-end gap-1.5">
          <div class="flex flex-wrap justify-end gap-2">
            <RouterLink to="/accounting/cash" :class="btnOutline('neutral')">{{ t('common.cancel') }}</RouterLink>
            <button @click="save(false)" :disabled="!canSubmit" :class="btnOutline('neutral')"
              :title="disabledTitle(!canSubmit, blockedReason)">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.doc" /></svg>
              {{ t('cash.save_draft') }}
            </button>
            <button v-if="!isEdit" @click="save(true, true)" :disabled="!canSubmit" :class="btnOutline('primary')"
              :title="disabledTitle(!canSubmit, blockedReason)" data-test="cash-post-and-next">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
              {{ t('cash.post_and_next') }}
            </button>
            <button @click="save(true)" :disabled="!canSubmit" :class="btnFilled('primary')"
              :title="disabledTitle(!canSubmit, blockedReason)">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
              {{ saving ? t('common.saving') : t('cash.post_document') }}
            </button>
          </div>
          <p v-if="blockedReason" :class="[BTN_DISABLED_NOTE, 'md:text-right']">{{ blockedReason }}</p>
        </div>
      </div>

      <!-- Live náhled zaúčtování (jen podvojné účetnictví — v daňové evidenci není journal) -->
      <div v-if="!isTaxEvidence" class="lg:col-span-1">
        <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-4 lg:sticky lg:top-20">
          <button v-if="isPhone" type="button" data-test="cash-preview-posting-toggle" :aria-expanded="postingPreviewOpen"
            class="cursor-pointer w-full min-h-10 -my-2 flex items-center justify-between gap-2 text-sm font-semibold text-neutral-700"
            @click="postingPreviewOpen = !postingPreviewOpen">
            {{ t('cash.preview.title') }}
            <svg class="w-4 h-4 transition-transform" :class="{ 'rotate-180': postingPreviewOpen }" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" /></svg>
          </button>
          <h3 v-else class="text-sm font-semibold text-neutral-700 mb-3">{{ t('cash.preview.title') }}</h3>
          <table v-if="showPostingPreview" class="w-full text-sm max-md:mt-3" data-test="cash-preview-posting">
            <thead class="text-xs text-neutral-500 uppercase tracking-wide">
              <tr>
                <th class="text-left font-medium py-1">{{ t('cash.col.number') }}</th>
                <th class="text-right font-medium py-1 w-24">{{ t('cash.col.debit') }}</th>
                <th class="text-right font-medium py-1 w-24">{{ t('cash.col.credit') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="(l, i) in previewLines" :key="i">
                <td class="py-1">
                  <span class="font-mono">{{ l.account_code }}</span>
                  <span class="text-neutral-500 text-xs ml-1 truncate">{{ accountName(l.account_code) }}</span>
                </td>
                <td class="py-1 text-right font-mono">
                  <template v-if="l.side === 'debit'">{{ formatMoney(l.amount) }}</template>
                </td>
                <td class="py-1 text-right font-mono">
                  <template v-if="l.side === 'credit'">{{ formatMoney(l.amount) }}</template>
                </td>
              </tr>
            </tbody>
            <tfoot>
              <tr class="border-t-2 border-neutral-300 font-semibold">
                <td class="py-1">{{ t('cash.col.amount') }}</td>
                <td class="py-1 text-right font-mono">{{ formatMoney(previewDebit) }}</td>
                <td class="py-1 text-right font-mono">{{ formatMoney(previewCredit) }}</td>
              </tr>
            </tfoot>
          </table>
        </div>
      </div>
    </div>

    <LinkedDocumentsPanel v-if="isEdit && auth.canRead('documents')" class="mt-4 block" entity-type="cash_document"
      :entity-id="editId" uploadable :title="t('linked_documents.title')" @docs="linkedDocs = $event" />
  </DocumentPreviewSplit>
</template>

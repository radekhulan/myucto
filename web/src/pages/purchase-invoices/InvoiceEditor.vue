<script setup lang="ts">
import { ref, reactive, computed, onMounted, onBeforeUnmount, nextTick, watch, useId } from 'vue'
import { useRoute, useRouter, RouterLink } from 'vue-router'
// RouterLink se používá i v Add Currency modalu — import už pokrývá
import { useI18n } from 'vue-i18n'
import {
  purchaseInvoicesApi,
  type PurchaseInvoice,
  type PurchaseInvoicePayload,
  type PurchaseInvoiceItem,
  type PurchaseInvoiceListItem,
  type PurchaseDocumentKind,
  type ExpenseKind,
  type ExpenseKindSuggestion,
  type ExchangeRateSource,
  type VatDeduction,
  type PurchaseVatAllocation,
  type AiPostingSuggestion,
} from '@/api/purchaseInvoices'
import {
  portalPurchaseInvoiceSubmissionsApi,
  purchaseInvoiceSubmissionsApi,
  type PurchaseInvoiceSubmission,
} from '@/api/purchaseInvoiceSubmissions'
import { PAYMENT_METHODS, type PaymentMethod } from '@/api/invoices'
import { accountingApi, type ChartAccount } from '@/api/accounting'
import { cashApi, type CashRegister } from '@/api/cash'
import type { CashSettlementResult } from '@/api/invoices'
import { codebooksApi, type VatRate, type Currency, type Unit } from '@/api/codebooks'
import { stockApi, type StockItemSearchResult } from '@/api/stock'
import { expenseCategoriesApi, type ExpenseCategory } from '@/api/expenseCategories'
import { projectsApi, type Project } from '@/api/projects'
import { vatClassificationsApi, type VatClassification } from '@/api/vatClassifications'
import { settingsApi } from '@/api/settings'
import { usePaneDom } from '@/composables/usePaneDom'
import AutomationBadge from '@/components/automation/AutomationBadge.vue'
import ConfidenceLabel from '@/components/automation/ConfidenceLabel.vue'
import { formatMoney } from '@/composables/useFormat'
import { evalMath } from '@/directives/vMath'
import { focusLastRow } from '@/composables/useRowFocus'
import { useToast } from '@/composables/useToast'
import { useDemoMode } from '@/composables/useDemoMode'
import { apiErrorMessage } from '@/api/errors'
import StockDescriptionField from '@/components/ui/StockDescriptionField.vue'
import ExpenseKindSuggestionHint from '@/components/purchase/ExpenseKindSuggestionHint.vue'
import VendorPicker from '@/components/purchase/VendorPicker.vue'
import ClientFormModal from '@/components/modals/ClientFormModal.vue'
import { clientsApi, type Client } from '@/api/clients'
import PdfDropzone from '@/components/purchase/PdfDropzone.vue'
import DocumentSidePreview from '@/components/documents/DocumentSidePreview.vue'
import PaymentCurrencyBlock from '@/components/purchase/PaymentCurrencyBlock.vue'
import ExchangeRateInput from '@/components/purchase/ExchangeRateInput.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { btnFilled } from '@/components/ui/buttonStyles'
import { useAuthStore } from '@/stores/auth'
import { useSupplierStore } from '@/stores/supplier'
import { appIsoDate } from '@/utils/date'
import { useSidePreviewWide } from '@/composables/useSidePreviewWide'

const route = useRoute()
const router = useRouter()
const { t, locale } = useI18n()
const toast = useToast()
const pageId = useId()
const paneDom = usePaneDom()
const { blockDemoMutation } = useDemoMode()
const auth = useAuthStore()
const supplierStore = useSupplierStore()

/** Volby formy úhrady — pořadí a doména ze sdíleného API typu (migrace 1128). */
const paymentMethodOptions = PAYMENT_METHODS

// Hotovostní vyrovnání (migrace 1327): u formy úhrady „Hotově" nabídneme pokladnu a
// backend z ní při přijetí dokladu vyrobí zaúčtovaný výdajový pokladní doklad.
// Jen KORUNOVÉ pokladny — úhradu faktury z valutové pokladny CashDocumentService
// odmítá, takže nabízet ji by znamenalo nabízet chybu. Klientská role do pokladny
// nevidí, tak se u ní volba vůbec nezobrazí.
const cashRegisters = ref<CashRegister[]>([])
const showCashSettlement = computed(() => form.value.payment_method === 'cash' && !auth.isClientRole)

async function loadCashRegisters() {
  if (auth.isClientRole) return
  try {
    cashRegisters.value = (await cashApi.listRegisters()).filter(r => r.currency_code === 'CZK')
  } catch {
    // Pokladna nemusí být u tenanta vůbec zapnutá — select prostě zůstane prázdný.
    cashRegisters.value = []
  }
}

/**
 * Co backend s pokladním dokladem udělal. `skipped` je legitimní mezistav (koncept,
 * nic k úhradě) — hlásí se jen s důvodem, ať uživatel nečeká doklad, který nevznikl.
 */
function notifyCashSettlement(s?: CashSettlementResult): void {
  if (!s) return
  if (s.status === 'created') {
    toast.success(t('cash_settlement.created', { number: s.doc_number ?? '' }))
  } else if (s.status === 'removed') {
    toast.info(t('cash_settlement.removed'))
  } else if (s.status === 'skipped' && s.reason) {
    toast.info(t(`cash_settlement.reason.${s.reason}`))
  }
}

// Sklad (Epic SKLAD) — na řádku přijaté faktury jen volitelná vazba na skladovou kartu.
// Na rozdíl od vydané faktury BEZ skladu (warehouse) a bez náhledu dostupnosti.
const stockEnabled = computed(() => auth.hasCommercialFeatures && supplierStore.currentSupplier?.stock_enabled === true)
// Per-řádek stav dropdownu (remote hledání) — klíčováno indexem; efemérní.
// Vybraný LABEL držíme podle stock_item_id (stabilní přes removeItem), ne podle indexu.
const stockRowOptions = reactive<Record<number, { value: number; label: string; secondary?: string }[]>>({})
const stockRowLoading = reactive<Record<number, boolean>>({})
const stockOptionById = reactive<Record<number, { value: number; label: string; secondary?: string }>>({})
const stockItemsCache = new Map<number, StockItemSearchResult>()

/** Vybraná option pro daný řádek — dle stock_item_id (stabilní přes reorder/smazání). */
function stockSelectedFor(it: PurchaseInvoiceItem): { value: number; label: string; secondary?: string } | null {
  return it.stock_item_id != null ? (stockOptionById[it.stock_item_id] ?? null) : null
}

async function onStockSearch(rowIndex: number, q: string) {
  stockRowLoading[rowIndex] = true
  try {
    const res = await stockApi.searchItems(q, 30)
    for (const r of res) stockItemsCache.set(r.id, r)
    stockRowOptions[rowIndex] = res.map(r => ({ value: r.id, label: `${r.sku} — ${r.name}`, secondary: r.unit }))
  } catch {
    stockRowOptions[rowIndex] = []
  } finally {
    stockRowLoading[rowIndex] = false
  }
}

function onStockSelect(rowIndex: number, itemId: number | null) {
  const it = form.value.items[rowIndex]
  if (!it) return
  it.stock_item_id = itemId
  if (itemId === null) return
  const si = stockItemsCache.get(itemId)
  if (si) {
    stockOptionById[si.id] = { value: si.id, label: `${si.sku} — ${si.name}`, secondary: si.unit }
    // Sloučené pole (popis = combobox): výběr karty popis přepíše názvem — dosavadní text byl
    // vyhledávací dotaz. Řádek jde dál libovolně přepsat ručně (volný text zůstává první občan).
    it.description = si.name
    if (si.unit) it.unit = si.unit
  }
}

/** Edit mode: naplní label (SKU — název) z joined polí načtené faktury (stock_sku/stock_name). */
function hydrateStockSelections() {
  if (!stockEnabled.value) return
  for (const it of form.value.items) {
    if (it.stock_item_id != null && it.stock_sku) {
      stockOptionById[it.stock_item_id] = {
        value: it.stock_item_id,
        label: `${it.stock_sku} — ${it.stock_name ?? ''}`.trim(),
      }
    }
  }
}

const isEdit = computed(() => route.params.id !== undefined && route.params.id !== 'new')
const invoiceId = computed(() => (isEdit.value ? Number(route.params.id) : null))
const submissionId = computed(() => {
  if (isEdit.value) return null
  const value = Number(route.query.submission_id)
  return Number.isInteger(value) && value > 0 ? value : null
})
const submission = ref<PurchaseInvoiceSubmission | null>(null)
const submissionCanPreviewInline = computed(() =>
  submission.value?.doc_type === 'pdf' || submission.value?.doc_type === 'image',
)

const loaded = ref(false)
const submitting = ref(false)
const error = ref('')
const fieldErrors = ref<Record<string, string[]>>({})

const vatRates = ref<VatRate[]>([])
const currencies = ref<Currency[]>([])
const units = ref<Unit[]>([])
const expenseCategories = ref<ExpenseCategory[]>([])
// Zakázky (issue #29). Na přijaté straně se NEfiltrují dle protistrany — akce má víc
// dodavatelů i víc odběratelů, takže vazba na klienta zakázky by picker vyprázdnila.
const projects = ref<Project[]>([])
const aiPostingSuggestion = ref<AiPostingSuggestion | null>(null)
const aiSuggestionSaving = ref(false)

// §DM — návrhy druhu nákladu, klíčované id ULOŽENÉ položky (nové řádky návrh nemají,
// dokud se doklad neuloží; BE je počítá nad DB). Read-only: `expense_kind` zapisuje
// výhradně uživatel kliknutím na „Použít", nikdy se neaplikují samy.
const expenseSuggestions = ref<Record<number, ExpenseKindSuggestion>>({})
/** Ručně zavřené návrhy — jen pro tuhle relaci editoru, nic se neukládá. */
const dismissedSuggestions = ref<Set<number>>(new Set())
const vatClassifications = ref<VatClassification[]>([])
const accountingAccounts = ref<ChartAccount[]>([])

// Uložený stav data přijetí (issue #9). Backend překlápí `received_at_source` na 'manual'
// jen při SKUTEČNÉ změně pole — bez těchhle dvou hodnot by editor neuměl předpovědět,
// jestli datum přijetí po uložení do období odpočtu vstoupí, nebo zůstane jen otiskem importu.
const savedReceivedAt = ref<string | null>(null)
const savedReceivedAtSource = ref<'manual' | 'import' | null>(null)

const today = appIsoDate()

const form = ref<{
  vendor_id: number | null
  vendor_invoice_number: string
  varsymbol: string
  document_kind: PurchaseDocumentKind
  issue_date: string
  tax_date: string
  due_date: string
  received_at: string
  currency_id: number | null
  exchange_rate: number | null
  exchange_rate_date: string
  exchange_rate_source: ExchangeRateSource
  reverse_charge: boolean
  prices_include_vat: boolean
  vendor_is_vat_payer: boolean
  is_fixed_asset: boolean
  vat_deduction: VatDeduction
  vat_deduction_percent: number
  tax_deductible: boolean
  language: 'cs' | 'en'
  note_above_items: string
  note_below_items: string
  payment_account_number: string
  payment_bank_code: string
  payment_iban: string
  payment_bic: string
  payment_variable_symbol: string
  payment_method: PaymentMethod
  cash_register_id: number | null
  advance_paid_amount: number
  rounding: number
  payment_currency_id: number | null
  payment_exchange_rate: number | null
  paid_amount_payment_ccy: number | null
  paid_amount_invoice_ccy: number | null
  exchange_diff_base: number | null
  expense_category_id: number | null
  project_id: number | null
  vat_classification_code: string | null
  parent_purchase_invoice_id: number | null
  items: PurchaseInvoiceItem[]
}>({
  vendor_id: null,
  vendor_invoice_number: '',
  varsymbol: '',
  document_kind: 'invoice',
  issue_date: today,
  tax_date: today,
  due_date: today,
  received_at: today,
  currency_id: null,
  exchange_rate: null,
  exchange_rate_date: today,
  exchange_rate_source: 'cnb',
  reverse_charge: false,
  prices_include_vat: false,
  vendor_is_vat_payer: true,
  is_fixed_asset: false,
  vat_deduction: 'full',
  vat_deduction_percent: 100,
  tax_deductible: true,
  language: 'cs',
  note_above_items: '',
  note_below_items: '',
  payment_account_number: '',
  payment_bank_code: '',
  payment_iban: '',
  payment_bic: '',
  payment_variable_symbol: '',
  payment_method: 'bank_transfer',
  cash_register_id: null,
  advance_paid_amount: 0,
  rounding: 0,
  payment_currency_id: null,
  payment_exchange_rate: null,
  paid_amount_payment_ccy: null,
  paid_amount_invoice_ccy: null,
  exchange_diff_base: null,
  expense_category_id: null,
  project_id: null,
  vat_classification_code: null,
  parent_purchase_invoice_id: null,
  items: [],
})

// Vazba na jiný přijatý doklad přes parent_purchase_invoice_id:
//  • dobropis (credit_note) → opravovaná běžná faktura téhož dodavatele (migrace 1096),
//  • DDKP (tax_document, daňový doklad k platbě §28 ZDPH) → uhrazená záloha téhož dodavatele.
// Vrací druh dokladu, mezi jehož kandidáty se vybírá (null = doklad se neváže).
const parentLinkKind = computed<'invoice' | 'advance' | null>(() => {
  if (form.value.document_kind === 'credit_note') return 'invoice'
  if (form.value.document_kind === 'tax_document') return 'advance'
  return null
})

const parentCandidates = ref<PurchaseInvoiceListItem[]>([])
const parentCandidatesLoading = ref(false)

async function loadParentCandidates() {
  const targetKind = parentLinkKind.value
  if (!targetKind || !form.value.vendor_id) {
    parentCandidates.value = []
    return
  }
  parentCandidatesLoading.value = true
  try {
    const res = await purchaseInvoicesApi.listGrouped({
      vendor_id: form.value.vendor_id,
      document_kind: targetKind,
      per_page: 200,
    })
    // Vlastní doklad (kdyby náhodou prošel filtrem) i storna vynech.
    parentCandidates.value = res.data
      .flatMap(g => g.invoices)
      .filter(inv => inv.id !== invoiceId.value && inv.status !== 'cancelled')
  } catch {
    parentCandidates.value = []
  } finally {
    parentCandidatesLoading.value = false
  }
}

// Přenačti kandidáty při změně druhu dokladu / dodavatele; při přepnutí na druh bez
// vazby ji vyčisti, a při přepnutí mezi dobropisem ⇄ DDKP taky (faktura vs. záloha nesedí).
watch(
  () => [form.value.document_kind, form.value.vendor_id] as const,
  ([kind], [prevKind]) => {
    if (kind !== 'credit_note' && kind !== 'tax_document') {
      form.value.parent_purchase_invoice_id = null
      parentCandidates.value = []
      return
    }
    if (prevKind !== kind && (prevKind === 'credit_note' || prevKind === 'tax_document')) {
      form.value.parent_purchase_invoice_id = null
    }
    void loadParentCandidates()
  },
)

// PDF state
const existingPdf = ref<{ path: string; hash: string; size: number; name: string; uploadedAt: string } | null>(null)
// Výchozí stav: zavřeno. FR5 (audit 2026-08) — u konceptu z AI extrakce (source_format
// mimo isdoc/isdocx) ho `populate()` přepne na otevřeno, ať má uživatel originál rovnou
// na očích při kontrole vytěžených dat (viz komentář u `structuredSource` v populate()).
const pdfPreviewOpen = ref(false)
const pdfPreviewWide = useSidePreviewWide()
const pdfSideBySide = computed(() => !!existingPdf.value && !!invoiceId.value && pdfPreviewOpen.value && pdfPreviewWide.value)
const pdfInlineUrl = computed(() => invoiceId.value
  ? `${purchaseInvoicesApi.pdfUrl(invoiceId.value, true)}#view=FitH`
  : '')
const pdfUploading = ref(false)
const handoffUploading = ref(false)
const dropzoneVisible = ref(true)

// Náhled PDF/obrázku připraveného k nahrání u NOVÉ faktury (ještě není na serveru).
// Soubor držíme jen v paměti prohlížeče (File), náhled tvoříme přes blob: URL —
// žádný server round-trip není potřeba. URL musíme po výměně/zrušení uvolnit (revoke),
// jinak by blob zůstal viset v paměti.
const pendingPdfUrl = ref<string | null>(null)
const pendingPdfPreviewOpen = ref(false)
const pendingPdfFile = ref<File | null>(null)
const canSubmitDocuments = computed(() =>
  auth.isClientRole && auth.canWrite('documents.submit'),
)
const canHandoffPendingDocument = computed(() =>
  canSubmitDocuments.value && pendingPdfFile.value !== null,
)
function setPendingPdfUrl(file: File | null) {
  if (pendingPdfUrl.value) {
    URL.revokeObjectURL(pendingPdfUrl.value)
    pendingPdfUrl.value = null
  }
  pendingPdfPreviewOpen.value = false
  if (file) pendingPdfUrl.value = URL.createObjectURL(file)
}
// Pro náhled obrázku (JPG/PNG/…) použijeme <img>, pro PDF <iframe> s PDF viewerem.
const pendingPdfIsImage = computed(() => !!pendingPdfFile.value?.type.startsWith('image/'))
onBeforeUnmount(() => setPendingPdfUrl(null))

// Diagnostické varování z AI extrakce (např. mezisoučty čteny jako items).
// Backend sets via PurchaseInvoiceRepository::setExtractionWarning po sanity-check.
const extractionWarning = ref<string | null>(null)
const dismissingWarning = ref(false)

async function dismissWarning() {
  const invId = Number(route.params.id)
  if (!invId || dismissingWarning.value) return
  dismissingWarning.value = true
  try {
    await purchaseInvoicesApi.dismissExtractionWarning(invId)
    extractionWarning.value = null
  } catch (e) {
    toast.error(apiErrorMessage(e))
  } finally {
    dismissingWarning.value = false
  }
}

// === Default vendor currency on selection ===
function onVendorSelected(v: any) {
  if (v && !isEdit.value) {
    // Pre-fill default currency from vendor.currency_default_id if available
    if (v.currency_default_id && form.value.currency_id === null) {
      form.value.currency_id = v.currency_default_id
    }
    if (v.language && !form.value.language) {
      form.value.language = v.language
    }
    // Pre-fill výchozí kategorie nákladu z dodavatele, pokud uživatel ještě nevybral jinou.
    if (v.default_expense_category_id && form.value.expense_category_id === null) {
      form.value.expense_category_id = v.default_expense_category_id
    }
  }
  // Změna dodavatele → online ověř plátcovství DPH (ARES/VIES) a u neplátce vynuť
  // vat_deduction='none' + vynuluj sazby (žádný nárok na odpočet).
  if (v && v.id) fetchVendorVatStatus(Number(v.id), true)
}

// === Plátcovství DPH dodavatele (online ARES/VIES) ===
const vendorVatStatusLoading = ref(false)

function zeroAllItemRates() {
  const zero = vatRates.value.find(r => Number(r.rate_percent) === 0 && !r.is_reverse_charge)
  if (zero) form.value.items.forEach(it => { it.vat_rate_id = zero.id })
}

/**
 * enforce=true (změna dodavatele / ruční přepnutí checkboxu) → u neplátce vynutí
 * vat_deduction='none' + vynuluje sazby. enforce=false (načtení existující faktury) →
 * jen nastaví příznak pro checkbox, NEpřepisuje uloženou volbu.
 */
function applyVendorVatStatus(isVatPayer: boolean, enforce: boolean) {
  form.value.vendor_is_vat_payer = isVatPayer
  if (enforce && !isVatPayer) {
    form.value.vat_deduction = 'none'
    zeroAllItemRates()
  }
}

async function fetchVendorVatStatus(vendorId: number, enforce: boolean) {
  vendorVatStatusLoading.value = true
  try {
    const r = await clientsApi.getVatStatus(vendorId)
    applyVendorVatStatus(r.is_vat_payer, enforce)
  } catch {
    // ARES/VIES nedostupné — necháme dosavadní příznak beze změny.
  } finally {
    vendorVatStatusLoading.value = false
  }
}

// Ruční přepnutí checkboxu „Dodavatel je plátce DPH" → u neplátce zakázat odpočet.
function onVendorVatPayerToggle() {
  if (!form.value.vendor_is_vat_payer) {
    form.value.vat_deduction = 'none'
    zeroAllItemRates()
  }
}

// === Quick "New vendor" modal — vytvoří klienta s is_vendor=true, is_customer=false ===
const vendorModalOpen = ref(false)
async function onVendorCreated(client: Client) {
  form.value.vendor_id = client.id
  vendorModalOpen.value = false
  // Pre-fill defaults pokud má vendor currency/language
  onVendorSelected(client)
}

const currencyCode = computed(() => {
  if (!form.value.currency_id) return ''
  return currencies.value.find(c => c.id === form.value.currency_id)?.code ?? ''
})

const showExchangeRate = computed(() => currencyCode.value && currencyCode.value !== 'CZK')

/**
 * Dropdown options: pro purchase invoice nás zajímá jen ISO currency code, ne vendor's
 * bankovní účet. Currencies tabulka má v dropdown často redundantní entries
 * (CZK — Fio, CZK — KB, atd.) — pro výběr měny faktury vendora vyfiltrujeme
 * jen unikátní currency codes (preferujeme is_default=1 z každé skupiny).
 */
const currencyOptions = computed(() => {
  const byCode = new Map<string, Currency>()
  for (const c of currencies.value) {
    const existing = byCode.get(c.code)
    if (!existing || c.is_default) byCode.set(c.code, c)
  }
  return Array.from(byCode.values()).sort((a, b) => a.code.localeCompare(b.code))
})

// Quick add currency modal state
const showAddCurrency = ref(false)
const newCurrencyCode = ref('')
const addingCurrency = ref(false)
async function addCurrency() {
  const code = newCurrencyCode.value.trim().toUpperCase()
  if (!/^[A-Z]{3}$/.test(code)) {
    toast.error(t('purchase_invoice.validation.invalid_currency_iso'))
    return
  }
  if (currencies.value.some(c => c.code === code)) {
    toast.error(`Měna ${code} už existuje`)
    return
  }
  addingCurrency.value = true
  try {
    // Měna přidaná z editoru přijaté faktury slouží jen jako "měna dokladu" — nemáme v ní
    // bankovní účet, nepoužívá se pro vystavované faktury. Proto is_active=false
    // (skryje ji z dropdownů u vystavených). V editoru přijatých ji ukážeme s badgem.
    // Pokud user chce měnu aktivovat pro vystavené (mám v ní reálný bankovní účet),
    // přejde do Nastavení → Měny a vyplní bankovní detaily + označí is_active=true.
    await settingsApi.createCurrency({
      code,
      label: `${code} — jen pro nákup`,
      symbol: code,
      name_cs: code,
      name_en: code,
      decimals: 2,
      is_active: false,
      is_default: false,
    })
    // Refresh list a vyber novou měnu — include_inactive=true protože nově přidaná
    // měna z editoru přijaté faktury má is_active=false (jen pro nákup).
    currencies.value = await codebooksApi.currencies(true)
    const newCcy = currencies.value.find(c => c.code === code)
    if (newCcy) form.value.currency_id = newCcy.id
    showAddCurrency.value = false
    newCurrencyCode.value = ''
    toast.success(`Měna ${code} přidána`)
  } catch (e) {
    toast.error(apiErrorMessage(e))
  } finally {
    addingCurrency.value = false
  }
}

async function loadSubmissionOrigin(): Promise<void> {
  const requestedId = submissionId.value
  submission.value = null
  if (!requestedId) return
  try {
    const item = await purchaseInvoiceSubmissionsApi.get(requestedId)
    if (submissionId.value !== requestedId) return
    if (item.status !== 'submitted' || item.purchase_invoice_id !== null) {
      error.value = t('purchase_submissions.editor_origin_unavailable')
      return
    }
    submission.value = item
    if (item.document_kind_hint && item.document_kind_hint !== 'other') {
      form.value.document_kind = item.document_kind_hint
    }
  } catch (e) {
    if (submissionId.value === requestedId) error.value = apiErrorMessage(e)
  }
}

onMounted(async () => {
  await loadCodebooks()
  void loadCashRegisters()
  if (isEdit.value && invoiceId.value) {
    await loadInvoice(invoiceId.value)
  } else {
    if (currencies.value.length > 0 && form.value.currency_id === null) {
      // Default na CZK měnu pokud existuje
      const czk = currencies.value.find(c => c.code === 'CZK')
      if (czk) form.value.currency_id = czk.id
    }
    await loadSubmissionOrigin()
    // Pre-fill vendor_id z ?vendor_id= (např. klik 'Nová přijatá faktura' v clientDetail)
    const qVendor = Number(route.query.vendor_id)
    if (!isNaN(qVendor) && qVendor > 0) {
      form.value.vendor_id = qVendor
      void fetchVendorVatStatus(qVendor, true)
    }
    // Default první prázdná položka pro nový draft (user feedback: UX, méně klikání).
    // Seed NEsmí schovat dropzone — jinak by upload PDF u nové faktury nikdy nebyl vidět.
    if (form.value.items.length === 0) {
      addItem(false)
    }
  }
  loaded.value = true
})

watch(submissionId, (next, previous) => {
  if (!loaded.value || isEdit.value || next === previous) return
  void loadSubmissionOrigin()
})

async function loadCodebooks() {
  try {
    const [v, c, u, ec, vc] = await Promise.all([
      codebooksApi.vatRates(),
      // Pro přijaté faktury chceme vidět i neaktivní měny (vendor's currency
      // může být USD/GBP, ve které nemáme bankovní účet a v Codebooks je marked
      // is_active=0). Backend přes ?include_inactive=1.
      codebooksApi.currencies(true),
      codebooksApi.units(),
      expenseCategoriesApi.list(false),  // jen aktivní pro picker
      vatClassificationsApi.list('purchase'),
    ])
    vatRates.value = v
    currencies.value = c
    units.value = u
    expenseCategories.value = ec
    vatClassifications.value = vc
    // Zakázky picker — klientská role je nevidí (stejně jako účtovou osnovu níž).
    if (!auth.isClientRole) {
      try {
        projects.value = (await projectsApi.list({ status: 'active', per_page: 200 })).data
      } catch {
        projects.value = []
      }
    }
    if (!auth.isClientRole) {
      try {
        accountingAccounts.value = await accountingApi.listAccounts()
      } catch {
        accountingAccounts.value = []
      }
    }
  } catch (e) {
    error.value = apiErrorMessage(e)
  }
}

async function loadInvoice(id: number) {
  try {
    const inv = await purchaseInvoicesApi.get(id)
    // Zamčený doklad (F6): klient editor vůbec neotevře — UX vrstva, BE by PUT stejně 403nul.
    if (auth.isClientRole && inv.locked?.is_locked) {
      toast.info(t('lock.client_hint'))
      router.replace(`/purchase-invoices/${inv.id}`)
      return
    }
    aiPostingSuggestion.value = inv.ai_posting_suggestion ?? null
    populate(inv)
    void loadExpenseSuggestions(id)
  } catch (e) {
    error.value = apiErrorMessage(e)
  }
}

/**
 * §DM — dotáhne návrhy druhu nákladu. Záměrně MIMO try/catch nad `loadInvoice`: doklad se
 * musí otevřít i bez nich. Endpoint je jen pro podvojné účetnictví, takže u daňové evidence
 * vrací 4xx — to není chyba uživatele a nemá o ní vědět (návrh je bonus, ne funkce editoru).
 */
async function loadExpenseSuggestions(id: number) {
  try {
    const res = await purchaseInvoicesApi.expenseSuggestions(id)
    const next: Record<number, ExpenseKindSuggestion> = {}
    for (const [itemId, suggestion] of Object.entries(res.items ?? {})) {
      next[Number(itemId)] = suggestion
    }
    expenseSuggestions.value = next
    dismissedSuggestions.value = new Set()
  } catch {
    expenseSuggestions.value = {}
  }
}

/**
 * Návrh k zobrazení u řádku, nebo null. Skryjeme ho, jakmile na řádku už je to, co navrhuje —
 * jinak by u potvrzené položky svítil „Použít", který nic neudělá.
 */
function suggestionFor(it: PurchaseInvoiceItem): ExpenseKindSuggestion | null {
  if (it.id == null || dismissedSuggestions.value.has(it.id)) return null
  const s = expenseSuggestions.value[it.id]
  if (!s || s.expense_kind === it.expense_kind) return null
  return s
}

/** Zápis návrhu na řádek. JEDINÁ cesta, jak se návrh promítne do dat — vždy na klik uživatele. */
function applySuggestion(it: PurchaseInvoiceItem) {
  const s = suggestionFor(it)
  if (!s) return
  it.expense_kind = s.expense_kind
}

function dismissSuggestion(it: PurchaseInvoiceItem) {
  if (it.id != null) dismissedSuggestions.value = new Set(dismissedSuggestions.value).add(it.id)
}

async function acceptAiPostingSuggestion() {
  if (!aiPostingSuggestion.value || aiSuggestionSaving.value) return
  aiSuggestionSaving.value = true
  try {
    const result = await purchaseInvoicesApi.acceptAiPostingSuggestion(aiPostingSuggestion.value.id)
    if (result.applied.expense_category_id != null) {
      form.value.expense_category_id = result.applied.expense_category_id
    }
    aiPostingSuggestion.value = null
    toast.success(t('common.saved'))
  } catch (e) {
    toast.error(apiErrorMessage(e))
  } finally {
    aiSuggestionSaving.value = false
  }
}

async function rejectAiPostingSuggestion() {
  if (!aiPostingSuggestion.value || aiSuggestionSaving.value) return
  aiSuggestionSaving.value = true
  try {
    await purchaseInvoicesApi.rejectAiPostingSuggestion(aiPostingSuggestion.value.id)
    aiPostingSuggestion.value = null
    toast.success(t('common.saved'))
  } catch (e) {
    toast.error(apiErrorMessage(e))
  } finally {
    aiSuggestionSaving.value = false
  }
}

/**
 * Období odpočtu DPH (§ 73 odst. 1 písm. a ZDPH) — issue #9.
 *
 * Nárok na odpočet lze uplatnit NEJDŘÍVE za období, ve kterém má plátce doklad k dispozici,
 * takže o zařazení nerozhoduje samotné DUZP. Uživatel to ale z formuláře nevyčetl a doklad
 * s červnovým DUZP mu „záhadně" vyskočil v červenci. Zrcadlí `purchaseClaimDateExpr()`
 * (tuzemská větev) — je to VÝKLAD pro uživatele, výpočet výkazů zůstává na backendu.
 *
 * Reverse charge má vlastní pravidlo (§ 25 / § 24 — vždy podle DUZP) a vlastní hint pod
 * poli, proto ho tenhle výklad vůbec nekomentuje, ať uživateli neříká dvě různé věci.
 */
const vatClaim = computed<{ date: string; basis: 'tax_date' | 'issue_date' | 'received_at' } | null>(() => {
  if (form.value.reverse_charge) return null
  const issue = form.value.issue_date
  if (!issue) return null
  const tax = form.value.tax_date || issue
  const received = form.value.received_at || null
  // Po uložení bude 'manual', pokud jím doklad už je, nebo pokud uživatel datum právě mění
  // (viz Create/UpdatePurchaseInvoiceAction). Nový doklad zadává účetní vždy vědomě.
  const willBeManual = received !== null
    && (!isEdit.value || savedReceivedAtSource.value === 'manual' || received !== savedReceivedAt.value)

  const candidates = willBeManual && received ? [tax, issue, received] : [tax, issue]
  const date = candidates.reduce((a, b) => (b > a ? b : a))
  const basis = date === tax ? 'tax_date' : date === issue ? 'issue_date' : 'received_at'
  return { date, basis }
})

const vatClaimPeriodLabel = computed(() => {
  const d = vatClaim.value?.date
  if (!d) return ''
  const parsed = new Date(d)
  if (isNaN(parsed.getTime())) return ''
  return parsed.toLocaleDateString(locale.value === 'en' ? 'en-US' : 'cs-CZ', { month: 'long', year: 'numeric' })
})

function populate(inv: PurchaseInvoice) {
  form.value.vendor_id = inv.vendor_id
  form.value.vendor_invoice_number = inv.vendor_invoice_number
  form.value.varsymbol = inv.varsymbol || ''
  form.value.document_kind = inv.document_kind
  form.value.issue_date = inv.issue_date
  form.value.tax_date = inv.tax_date || inv.issue_date
  form.value.due_date = inv.due_date
  form.value.received_at = inv.received_at
  savedReceivedAt.value = inv.received_at ? inv.received_at.slice(0, 10) : null
  savedReceivedAtSource.value = inv.received_at_source ?? 'import'
  form.value.currency_id = inv.currency_id
  form.value.exchange_rate = inv.exchange_rate
  form.value.exchange_rate_date = inv.exchange_rate_date || inv.issue_date
  form.value.exchange_rate_source = inv.exchange_rate_source
  form.value.reverse_charge = inv.reverse_charge
  form.value.prices_include_vat = (inv as { prices_include_vat?: boolean }).prices_include_vat ?? false
  form.value.is_fixed_asset = (inv as { is_fixed_asset?: boolean }).is_fixed_asset ?? false
  form.value.vat_deduction = inv.vat_deduction ?? 'full'
  form.value.vat_deduction_percent = inv.vat_deduction_percent ?? 100
  form.value.tax_deductible = inv.tax_deductible ?? true
  form.value.language = inv.language
  form.value.note_above_items = inv.note_above_items || ''
  form.value.note_below_items = inv.note_below_items || ''
  form.value.payment_account_number = inv.payment_account_number || ''
  form.value.payment_bank_code = inv.payment_bank_code || ''
  form.value.payment_iban = inv.payment_iban || ''
  form.value.payment_bic = inv.payment_bic || ''
  form.value.payment_variable_symbol = inv.payment_variable_symbol || ''
  form.value.payment_method = inv.payment_method || 'bank_transfer'
  form.value.cash_register_id = inv.cash_register_id ?? null
  form.value.advance_paid_amount = inv.advance_paid_amount
  form.value.rounding = Number(inv.rounding) || 0
  form.value.payment_currency_id = inv.payment_currency_id
  form.value.payment_exchange_rate = inv.payment_exchange_rate
  form.value.paid_amount_payment_ccy = inv.paid_amount_payment_ccy
  form.value.paid_amount_invoice_ccy = inv.paid_amount_invoice_ccy
  form.value.exchange_diff_base = inv.exchange_diff_base
  form.value.expense_category_id = inv.expense_category_id ?? null
  form.value.project_id = inv.project_id ?? null
  form.value.vat_classification_code = inv.vat_classification_code ?? null
  form.value.parent_purchase_invoice_id = inv.parent_purchase_invoice_id ?? null
  form.value.items = inv.items.length > 0 ? inv.items : []
  // Faktura s už zadaným časovým rozlišením → odkryj celý blok (jinak by řádek s pickery byl schovaný).
  showAccrual.value = form.value.items.some(it => !!it.accrual_from || !!it.accrual_to)
  // §DM legacy: doklad označený hlavičkovým `is_fixed_asset` z doby před item-level
  // klasifikací. Bez přepisu do položek by ho odvozený příznak při uložení tiše shodil
  // na 0 — starý příznak platil pro celý doklad, tak ho tak i přeneseme (uživatel může
  // po řádcích upřesnit). Klasifikované položky nepřepisujeme.
  if (form.value.is_fixed_asset && !form.value.items.some(it => it.expense_kind)) {
    form.value.items.forEach(it => { it.expense_kind = 'fixed_asset' })
  }
  hydrateStockSelections()
  extractionWarning.value = inv.extraction_warning ?? null
  // Ruční rekapitulace DPH dle dokladu (§ 73) → naplň override mapu.
  vatOverrides.value = {}
  for (const o of inv.vat_overrides ?? []) {
    vatOverrides.value[String(o.rate)] = { base: o.base, vat: o.vat }
  }
  vatAllocations.value = (inv.vat_allocations ?? []).map(a => ({ ...a }))
  // Existující faktura: plátcovství čteme ze SNAPSHOTU dokladu (migrace 0133), který si
  // fakturu drží k datu plnění. ZÁMĚRNĚ NEvoláme online ARES/VIES lookup — ten se ptá na
  // dnešní stav a persistoval by ho na klienta, čímž by u historické faktury příznak
  // „odškrtl" (dodavatel dnes nemusí být plátce, ačkoli v době plnění byl). Uživatel může
  // snapshot ručně upravit checkboxem; uloží se zpět na doklad.
  form.value.vendor_is_vat_payer = inv.vendor_is_vat_payer ?? true

  if (inv.pdf_path) {
    existingPdf.value = {
      path: inv.pdf_path,
      hash: inv.pdf_hash || '',
      size: inv.pdf_size_bytes || 0,
      name: inv.pdf_original_name || 'invoice.pdf',
      uploadedAt: inv.pdf_uploaded_at || '',
    }
    dropzoneVisible.value = false
    // FR5 (vendor audit 2026-08): u konceptu založeného z hodnot, které NEJSOU
    // ze strukturovaného ISDOC (tedy 'isdocx' — taky strojově parsované, beze ztráty —
    // nebo 'isdoc' samotné), otevři náhled originálu defaultně. Lidská kontrola má
    // probíhat proti originálu právě u AI-vytěžených dat (BUG 1: AI extrakce s chybnou
    // nulovou DPH a nepravdivým odůvodněním „dodavatel je neplátce"), ne u dat, která
    // strukturovaný formát dodal přesně. Jen na 'draft' — jakmile doklad postoupil dál,
    // uživatel ho už jednou zkontroloval, nemá smysl mu náhled vnucovat při každém otevření.
    const structuredSource = inv.source_format === 'isdoc' || inv.source_format === 'isdocx'
    if (inv.status === 'draft' && !structuredSource) {
      pdfPreviewOpen.value = true
    }
  }
}

const EXPENSE_KINDS: ExpenseKind[] = ['service', 'material', 'small_asset', 'fixed_asset']

// §DM: klasifikace je na položce (faktura běžně míchá majetek + službu), hlavičkový
// `is_fixed_asset` z ní jen odvozujeme — dva ručně editovatelné zdroje pravdy by se
// rozešly hned prvním uložením. Stejné pravidlo drží BE (PurchaseInvoiceRepository).
const derivedIsFixedAsset = computed(() =>
  form.value.items.some(it => it.expense_kind === 'fixed_asset'),
)

// §DČR — časové rozlišení nákladu (381). 99 % faktur ho nepoužije, proto je celý blok
// SKRYTÝ za jedním master přepínačem u rekapitulace DPH; teprve po jeho zapnutí se u
// položek ukáže per-řádek „časově rozlišit". Faktura s už zadaným rozlišením ho odkryje sama.
const showAccrual = ref(false)
const accrualOpen = reactive(new Set<number>())
function accrualVisible(it: PurchaseInvoiceItem, i: number): boolean {
  return accrualOpen.has(i) || !!it.accrual_from || !!it.accrual_to
}
function toggleAccrual(it: PurchaseInvoiceItem, i: number) {
  if (accrualVisible(it, i)) {
    accrualOpen.delete(i)
    it.accrual_from = null
    it.accrual_to = null
  } else {
    accrualOpen.add(i)
  }
}

function addItem(hideDropzone = true) {
  form.value.items.push({
    description: '',
    quantity: 1,
    unit: units.value.find(u => u.is_default)?.code || 'ks',
    unit_price_without_vat: 0,
    vat_rate_id: vatRates.value.find(v => v.is_default)?.id || vatRates.value[0]?.id || 1,
    order_index: form.value.items.length,
    expense_kind: null,
    accrual_from: null,
    accrual_to: null,
    stock_item_id: null,
  })
  // user začal editovat (klik na „přidat položku") → schovej dropzone, ať se nepřeplňuje.
  // Automatický seed první položky při mountu posílá hideDropzone=false (viz onMounted).
  if (hideDropzone) {
    dropzoneVisible.value = false
    focusLastRow('[data-row-input="pur-item"]', paneDom.root()) // jen u user kliku, ne u seedu při mountu
  }
}

function removeItem(idx: number) {
  form.value.items.splice(idx, 1)
}

// Per-item live calc preview (read-only, server přepočte při save)
function itemTotal(it: PurchaseInvoiceItem) {
  const amt = Number(it.quantity || 0) * Number(it.unit_price_without_vat || 0)
  const rate = form.value.reverse_charge ? 0 : (vatRates.value.find(v => v.id === it.vat_rate_id)?.rate_percent || 0)
  // Režim "ceny s DPH": unit_price_without_vat nese cenu S DPH (gross) → DPH shora.
  if (form.value.prices_include_vat) {
    const vat = round2(amt * rate / (100 + rate))
    return { base: round2(amt - vat), vat, with: round2(amt) }
  }
  const vat = amt * rate / 100
  return { base: round2(amt), vat: round2(vat), with: round2(amt + vat) }
}
function round2(n: number) { return Math.round(n * 100) / 100 }

// Zadání částky s DPH na řádku „Celkem s DPH" → dopočet jednotkové ceny.
// Přepínač „ceny s DPH" záměrně NEpřepínáme — respektujeme aktuální režim faktury:
//  • režim „ceny s DPH" zapnutý → unit_price nese gross → ulož gross / množství,
//  • režim vypnutý (běžný) → z gross odečti DPH shora a ulož netto / množství.
// Podporuje i výrazy (evalMath: "1210", "1000*1.21", desetinná čárka). Server přepočítá přesně.
function setItemGross(it: PurchaseInvoiceItem, raw: string): void {
  const gross = evalMath(raw)
  if (gross === null) return
  const qty = Number(it.quantity) || 0
  if (qty === 0) return
  if (form.value.prices_include_vat) {
    // unit_price_without_vat nese cenu S DPH → ulož gross jako jednotkovou cenu.
    it.unit_price_without_vat = round2(gross / qty)
    return
  }
  // Běžný režim: dopočti netto odečtením DPH shora (u reverse-charge je sazba 0).
  const rate = form.value.reverse_charge ? 0 : (vatRates.value.find(v => v.id === it.vat_rate_id)?.rate_percent || 0)
  const net = gross / (1 + rate / 100)
  it.unit_price_without_vat = round2(net / qty)
}

// Záhlaví sloupce jednotkové ceny — v režimu „ceny s DPH" je to cena včetně DPH.
const unitPriceHeaderLabel = computed(() => form.value.prices_include_vat
  ? t('purchase_invoice.items.unit_price_gross')
  : t('purchase_invoice.items.unit_price'))

// Popisek sazby — odliš dvě 0% sazby (osvobozeno vs. přenesená DPH), jako u vydané faktury.
function vatRateLabel(r: VatRate): string {
  if (Number(r.rate_percent) > 0) return `${r.rate_percent} %`
  if (r.is_reverse_charge) return t('invoice.vat_rate_label.reverse_charge')
  return t('invoice.vat_rate_label.exempt')
}

// ── Rekapitulace DPH per sazba + ruční override dle dokladu (§ 73 ZDPH) ──
// Vypočtená rekapitulace (per sazba) ze součtu řádků — default hodnoty.
const computedRecap = computed(() => {
  const map = new Map<number, { rate: number; base: number; vat: number }>()
  for (const it of form.value.items) {
    const t = itemTotal(it)
    const rate = form.value.reverse_charge ? 0 : (vatRates.value.find(v => v.id === it.vat_rate_id)?.rate_percent ?? 0)
    const cur = map.get(rate) ?? { rate, base: 0, vat: 0 }
    cur.base = round2(cur.base + t.base)
    cur.vat = round2(cur.vat + t.vat)
    map.set(rate, cur)
  }
  return [...map.values()].sort((a, b) => b.rate - a.rate)
})

// Ruční overridy per sazba (klíč = sazba jako string). Prázdné = počítat standardně.
const vatOverrides = ref<Record<string, { base: number; vat: number }>>({})
const vatAllocations = ref<PurchaseVatAllocation[]>([])
const hasVatAllocations = computed(() => vatAllocations.value.length > 0)

function accountExists(code: string): boolean {
  return accountingAccounts.value.some(a => a.is_active && a.account_code === code)
}

function defaultAllocationAccount(usage: PurchaseVatAllocation['usage_type']): string {
  const candidates = usage === 'personal' ? ['355', '335'] : usage === 'non_deductible' ? ['513', '518'] : ['518']
  return candidates.find(accountExists) ?? candidates[0]
}

function startVatAllocations(): void {
  vatAllocations.value = recapRows.value.map((row, index) => ({
    description: t('purchase_invoice.vat_allocation.business'),
    usage_type: 'business',
    vat_rate: row.rate,
    base_amount: row.base,
    vat_amount: row.vat,
    total_amount: round2(row.base + row.vat),
    vat_deduction: 'full',
    vat_deduction_percent: 100,
    tax_treatment: 'deductible',
    account_code: defaultAllocationAccount('business'),
    vat_classification_code: form.value.vat_classification_code,
    order_index: index,
  }))
}

function addVatAllocation(rate: number): void {
  vatAllocations.value.push({
    description: t('purchase_invoice.vat_allocation.personal'),
    usage_type: 'personal', vat_rate: rate,
    base_amount: 0, vat_amount: 0, total_amount: 0,
    vat_deduction: 'none', vat_deduction_percent: 0,
    tax_treatment: 'not_expense', account_code: defaultAllocationAccount('personal'),
    vat_classification_code: form.value.vat_classification_code,
    order_index: vatAllocations.value.length,
  })
}

function allocationsForRate(rate: number): PurchaseVatAllocation[] {
  return vatAllocations.value.filter(a => Number(a.vat_rate) === Number(rate))
}

function applyAllocationPreset(allocation: PurchaseVatAllocation): void {
  if (allocation.usage_type === 'personal') {
    allocation.vat_deduction = 'none'; allocation.vat_deduction_percent = 0
    allocation.tax_treatment = 'not_expense'; allocation.account_code = defaultAllocationAccount('personal')
  } else if (allocation.usage_type === 'non_deductible') {
    allocation.vat_deduction = 'none'; allocation.vat_deduction_percent = 0
    allocation.tax_treatment = 'non_deductible'; allocation.account_code = defaultAllocationAccount('non_deductible')
  } else if (allocation.usage_type === 'mixed') {
    allocation.vat_deduction = 'proportional'; allocation.vat_deduction_percent = 70
    allocation.tax_treatment = 'deductible'; allocation.account_code = defaultAllocationAccount('business')
  } else {
    allocation.vat_deduction = 'full'; allocation.vat_deduction_percent = 100
    allocation.tax_treatment = 'deductible'; allocation.account_code = defaultAllocationAccount('business')
  }
}

function syncAllocationResidual(rate: number): void {
  const recap = recapRows.value.find(r => Number(r.rate) === Number(rate))
  const rows = allocationsForRate(rate)
  const residual = rows.find(a => a.usage_type === 'business')
  if (!recap || !residual) return
  const others = rows.filter(a => a !== residual)
  residual.base_amount = round2(recap.base - others.reduce((s, a) => s + Number(a.base_amount || 0), 0))
  residual.vat_amount = round2(recap.vat - others.reduce((s, a) => s + Number(a.vat_amount || 0), 0))
  residual.total_amount = round2(residual.base_amount + residual.vat_amount)
}

function syncAllAllocationResiduals(): void {
  for (const row of recapRows.value) syncAllocationResidual(row.rate)
}

function setAllocationGross(allocation: PurchaseVatAllocation, raw: string): void {
  const total = evalMath(raw)
  if (total === null) return
  const recap = recapRows.value.find(r => Number(r.rate) === Number(allocation.vat_rate))
  const recapTotal = recap ? recap.base + recap.vat : 0
  const baseRatio = recapTotal !== 0 ? recap!.base / recapTotal : 1
  allocation.total_amount = round2(total)
  allocation.base_amount = round2(total * baseRatio)
  allocation.vat_amount = round2(allocation.total_amount - allocation.base_amount)
  syncAllocationResidual(allocation.vat_rate)
}

function removeVatAllocation(allocation: PurchaseVatAllocation): void {
  if (allocation.usage_type === 'business') return
  const rate = allocation.vat_rate
  vatAllocations.value = vatAllocations.value.filter(a => a !== allocation)
  syncAllocationResidual(rate)
}

const allocationInvalid = computed(() => hasVatAllocations.value && vatAllocations.value.some(a =>
  !a.description.trim() || !a.account_code.trim() || a.base_amount < -0.01 || a.vat_amount < -0.01 || a.total_amount < -0.01,
))

// Řádky pro UI: merge vypočtené rekapitulace s overridy (+ příznak „ručně upraveno").
const recapRows = computed(() => computedRecap.value.map(r => {
  const ov = vatOverrides.value[String(r.rate)]
  return {
    rate: r.rate,
    base: ov ? ov.base : r.base,
    vat: ov ? ov.vat : r.vat,
    computedBase: r.base,
    computedVat: r.vat,
    overridden: !!ov,
  }
}))

function setRecapBase(rate: number, raw: string): void {
  const v = evalMath(raw)
  if (v === null) return
  const key = String(rate)
  const row = computedRecap.value.find(r => r.rate === rate)
  const cur = vatOverrides.value[key] ?? { base: row?.base ?? 0, vat: row?.vat ?? 0 }
  vatOverrides.value = { ...vatOverrides.value, [key]: { ...cur, base: round2(v) } }
  nextTick(syncAllAllocationResiduals)
}
function setRecapVat(rate: number, raw: string): void {
  const v = evalMath(raw)
  if (v === null) return
  const key = String(rate)
  const row = computedRecap.value.find(r => r.rate === rate)
  const cur = vatOverrides.value[key] ?? { base: row?.base ?? 0, vat: row?.vat ?? 0 }
  vatOverrides.value = { ...vatOverrides.value, [key]: { ...cur, vat: round2(v) } }
  nextTick(syncAllAllocationResiduals)
}
function resetRecapRate(rate: number): void {
  const next = { ...vatOverrides.value }
  delete next[String(rate)]
  vatOverrides.value = next
  nextTick(syncAllAllocationResiduals)
}
const hasVatOverride = computed(() => Object.keys(vatOverrides.value).length > 0)

// Payload pro server — jen sazby, které na faktuře stále existují.
function buildVatOverridesPayload(): Array<{ rate: number; base: number; vat: number }> {
  return Object.entries(vatOverrides.value)
    .filter(([key]) => computedRecap.value.some(r => String(r.rate) === key))
    .map(([key, v]) => ({ rate: Number(key), base: v.base, vat: v.vat }))
}

// Součty z (případně přepsané) rekapitulace → všechny totály dole sedí na doklad.
const totals = computed(() => {
  let base = 0, vat = 0
  for (const r of recapRows.value) { base += r.base; vat += r.vat }
  return { without_vat: round2(base), vat: round2(vat), with_vat: round2(base + vat) }
})

async function onPdfDropped(file: File) {
  // U existujícího dokladu je dropzone čistě archiv přílohy. Strukturovaný import
  // patří jen k nové faktuře, jinak by nečekaně založil druhý doklad.
  if (isEdit.value && invoiceId.value) {
    await uploadPdfToInvoice(invoiceId.value, file)
    return
  }

  const extension = file.name.split('.').pop()?.toLowerCase() ?? ''
  const structuredCandidate = extension === 'isdoc' || extension === 'isdocx' || extension === 'pdf'
  if (structuredCandidate) {
    if (blockDemoMutation()) return
    pdfUploading.value = true
    try {
      const imported = await purchaseInvoicesApi.importStructured(file)
      toast.success(t(imported.duplicate
        ? 'purchase_invoice.extraction.isdoc_duplicate'
        : 'purchase_invoice.extraction.isdoc_found'))
      await router.replace(`/purchase-invoices/${imported.purchase_invoice_id}/edit`)
      // /new i /:id/edit používají tutéž komponentu, takže Vue ji při navigaci
      // nere-mountuje a onMounted() se znovu nespustí. Importovaný draft proto
      // načti explicitně; jinak se hodnoty objeví až po F5.
      await loadInvoice(imported.purchase_invoice_id)
      return
    } catch (e: any) {
      // Běžný PDF bez embedded ISDOC není vadný: zachovej dosavadní ruční flow
      // a nahraj ho jako přílohu až po uložení formuláře.
      if (extension === 'pdf' && e?.response?.data?.error?.code === 'no_embedded_isdoc') {
        queuePendingPdf(file)
        toast.info(t(canSubmitDocuments.value
          ? 'purchase_submissions.editor_handoff_available'
          : 'purchase_invoice.extraction.no_embedded_isdoc'))
        return
      }
      toast.error(apiErrorMessage(e))
      return
    } finally {
      pdfUploading.value = false
    }
  }

  queuePendingPdf(file)
  toast.success(t('purchase_invoice.pdf.pending_upload', { name: file.name }))
}

function queuePendingPdf(file: File) {
  pendingPdfFile.value = file
  setPendingPdfUrl(file)
  dropzoneVisible.value = false
}

// Odebrání souboru připraveného k nahrání (u nové faktury, před uložením).
function clearPendingPdf() {
  pendingPdfFile.value = null
  setPendingPdfUrl(null)
  dropzoneVisible.value = true
}

async function handoffPendingDocument() {
  const file = pendingPdfFile.value
  if (!file || !canHandoffPendingDocument.value || handoffUploading.value) return
  if (blockDemoMutation()) return
  handoffUploading.value = true
  try {
    const result = await portalPurchaseInvoiceSubmissionsApi.upload([file], '', 'invoice')
    clearPendingPdf()
    toast.success(t(result.duplicates > 0 && result.created === 0
      ? 'purchase_submissions.editor_handoff_duplicate'
      : 'purchase_submissions.editor_handoff_success'))
    await router.push('/portal/purchase-invoice-submissions')
  } catch (e) {
    toast.error(apiErrorMessage(e))
  } finally {
    handoffUploading.value = false
  }
}

async function uploadPdfToInvoice(id: number, file: File) {
  pdfUploading.value = true
  try {
    const result = await purchaseInvoicesApi.uploadPdf(id, file)
    // Debug: pokud size přijde 0 nebo name null, log pro diagnózu (OPcache stale code?)
    if (!result || !result.pdf_original_name || !result.pdf_size_bytes) {
      // eslint-disable-next-line no-console
      console.warn('[uploadPdf] suspicious response:', result)
    }
    existingPdf.value = {
      path: result.pdf_path,
      hash: result.pdf_hash,
      // Fallback na lokální file.size, protože backend někdy vrací 0 (PSR-7 Slim 4)
      size: Number(result.pdf_size_bytes) || file.size || 0,
      // Fallback na file.name, protože backend někdy vrací prázdný string
      name: result.pdf_original_name || file.name,
      uploadedAt: new Date().toISOString(),
    }
    dropzoneVisible.value = false
    toast.success(t('purchase_invoice.pdf.uploaded'))
  } catch (e) {
    toast.error(apiErrorMessage(e))
  } finally {
    pdfUploading.value = false
  }
}

function onPdfError(_code: string, message: string) {
  toast.error(message)
}

/**
 * "Nahradit PDF" — smaže existing přílohy server-side a otevře dropzone pro nový upload.
 * Pokud user neuploadne nic, faktura zůstane bez PDF (lze pak nahrát kdykoli).
 */
async function onReplacePdf() {
  if (isEdit.value && invoiceId.value && existingPdf.value) {
    try {
      await purchaseInvoicesApi.deletePdf(invoiceId.value)
    } catch (e) {
      toast.error(apiErrorMessage(e))
      return
    }
  }
  existingPdf.value = null
  pendingPdfFile.value = null
  setPendingPdfUrl(null)
  dropzoneVisible.value = true
}

async function submit() {
  if (blockDemoMutation()) return
  if (submitting.value) return
  if (submissionId.value && !submission.value) {
    error.value = t('purchase_submissions.editor_origin_unavailable')
    return
  }
  submitting.value = true
  error.value = ''
  fieldErrors.value = {}
  syncAllAllocationResiduals()
  if (allocationInvalid.value) {
    error.value = t('purchase_invoice.vat_allocation.invalid')
    submitting.value = false
    return
  }
  try {
    const payload: PurchaseInvoicePayload = {
      ...(submission.value ? { submission_id: submission.value.id } : {}),
      vendor_id: form.value.vendor_id!,
      vendor_invoice_number: form.value.vendor_invoice_number,
      varsymbol: form.value.varsymbol || null,
      document_kind: form.value.document_kind,
      issue_date: form.value.issue_date,
      tax_date: form.value.tax_date || null,
      due_date: form.value.due_date,
      received_at: form.value.received_at,
      currency_id: form.value.currency_id!,
      exchange_rate: form.value.exchange_rate,
      exchange_rate_date: form.value.exchange_rate_date || null,
      exchange_rate_source: form.value.exchange_rate_source,
      reverse_charge: form.value.reverse_charge,
      prices_include_vat: form.value.prices_include_vat,
      is_fixed_asset: derivedIsFixedAsset.value,
      // Snapshot plátcovství dodavatele k datu plnění (migrace 0133) — zmrazí se na doklad,
      // aby historickou fakturu šlo označit za plátce i když dodavatel dnes plátce není.
      vendor_is_vat_payer: form.value.vendor_is_vat_payer,
      vat_deduction: form.value.vat_deduction,
      vat_deduction_percent: form.value.vat_deduction_percent,
      tax_deductible: form.value.tax_deductible,
      language: form.value.language,
      note_above_items: form.value.note_above_items || null,
      note_below_items: form.value.note_below_items || null,
      // Platební účet dodavatele pro QR platbu (ruční úprava v editoru = source 'manual';
      // backend nastaví source/checked_at jen pokud je účet skutečně vyplněný).
      payment: {
        account_number: form.value.payment_account_number.trim() || null,
        bank_code: form.value.payment_bank_code.trim() || null,
        iban: form.value.payment_iban.trim().replace(/\s+/g, '').toUpperCase() || null,
        bic: form.value.payment_bic.trim().toUpperCase() || null,
        variable_symbol: form.value.payment_variable_symbol.trim() || null,
        source: 'manual',
      },
      // Forma úhrady — volba v editoru je vědomý úkon účetní, backend jí proto vždy
      // přiřadí source 'manual' a už ji nepřepíše AI ani předvolba dodavatele.
      payment_method: form.value.payment_method,
      // Hotovostní vyrovnání (migrace 1327): pokladna se posílá JEN u formy úhrady
      // „Hotově" — jinak natvrdo null, ať přepnutí na převod zruší i dřív založený VPD.
      cash_register_id: form.value.payment_method === 'cash' ? form.value.cash_register_id : null,
      advance_paid_amount: form.value.advance_paid_amount,
      rounding: form.value.rounding,
      payment_currency_id: form.value.payment_currency_id,
      payment_exchange_rate: form.value.payment_exchange_rate,
      paid_amount_payment_ccy: form.value.paid_amount_payment_ccy,
      paid_amount_invoice_ccy: form.value.paid_amount_invoice_ccy,
      exchange_diff_base: form.value.exchange_diff_base,
      expense_category_id: form.value.expense_category_id,
      project_id: form.value.project_id,
      vat_classification_code: form.value.vat_classification_code,
      // Vazba přes parent_purchase_invoice_id — dobropis → opravovaná faktura (migrace 1096),
      // DDKP (tax_document) → uhrazená záloha. BE ji stejně provaliduje (tenant/druh/self)
      // a u jiného druhu vynuluje.
      parent_purchase_invoice_id:
        form.value.document_kind === 'credit_note' || form.value.document_kind === 'tax_document'
          ? form.value.parent_purchase_invoice_id
          : null,
      // Ruční rekapitulace DPH dle dokladu (§ 73) — [] vyčistí případný starý override.
      vat_overrides: buildVatOverridesPayload(),
      vat_allocations: vatAllocations.value.map((a, i) => ({ ...a, order_index: i })),
      items: form.value.items.map((it, i) => ({
        description: it.description,
        quantity: Number(it.quantity || 0),
        unit: it.unit,
        unit_price_without_vat: Number(it.unit_price_without_vat || 0),
        vat_rate_id: it.vat_rate_id,
        order_index: i,
        vat_classification_code: it.vat_classification_code,
        expense_kind: it.expense_kind ?? null,
        accrual_from: it.accrual_from ?? null,
        accrual_to: it.accrual_to ?? null,
        stock_item_id: it.stock_item_id ?? null,
      })),
    }
    let inv: PurchaseInvoice
    if (isEdit.value && invoiceId.value) {
      // Force flag z URL query (?force=1) — pro admin edit received/booked faktur
      const force = String(route.query.force ?? '') === '1'
      inv = await purchaseInvoicesApi.update(invoiceId.value, payload, force)
    } else {
      inv = await purchaseInvoicesApi.create(payload)
    }
    // Upload pending PDF pokud byl drop před save
    if (pendingPdfFile.value && !submissionId.value) {
      await uploadPdfToInvoice(inv.id, pendingPdfFile.value)
      pendingPdfFile.value = null
      setPendingPdfUrl(null)
    }
    toast.success(isEdit.value ? t('common.saved') : t('common.created'))
    // Non-blocking varování ze serveru (např. dobropis s kladným součtem — issue #35).
    for (const code of inv._warnings ?? []) {
      if (code === 'exchange_rate_cnb_deviation') {
        const m = inv._warning_meta?.exchange_rate_cnb_deviation
        toast.warning(t('purchase_invoice.warning.exchange_rate_cnb_deviation', {
          used: m ? m.used_rate.toFixed(3) : '',
          cnb: m ? m.cnb_rate.toFixed(3) : '',
          diff: m ? m.diff_percent.toFixed(2) : '',
        }))
      } else if (code === 'exchange_rate_not_reloaded') {
        // Rozhodné datum / měna se změnily, ale kurz drží silnější zdroj (uživatel, import,
        // historický zápis) nebo se nepodařilo sáhnout na ČNB — důvod je v meta.
        const m = inv._warning_meta?.exchange_rate_not_reloaded
        toast.warning(t('purchase_invoice.warning.exchange_rate_not_reloaded', {
          rate: m?.rate != null ? Number(m.rate).toFixed(3) : '—',
          date: m?.rate_date ?? '—',
          reason: t(`purchase_invoice.warning.exchange_rate_not_reloaded_${m?.reason ?? 'source_locked'}`),
        }))
      } else if (code === 'cash_settlement_failed') {
        // Vlastní hláška — kód pokladny nese `_cash_settlement.reason`, ne
        // `purchase_invoice.warning.*` (tam by chyběl překlad).
        const s = inv._cash_settlement
        toast.warning(t('cash_settlement.failed') + (s?.message ? ` (${s.message})` : ''))
      } else {
        toast.warning(t(`purchase_invoice.warning.${code}`))
      }
    }
    notifyCashSettlement(inv._cash_settlement)
    router.push(`/purchase-invoices/${inv.id}`)
  } catch (e: any) {
    const data = e?.response?.data?.error
    if (data?.fields) {
      fieldErrors.value = data.fields
    }
    error.value = apiErrorMessage(e)
    // Toast + scroll k bannéru — uživatel může být odscrollovaný dole u tlačítka Uložit
    // a jinak by validační chybu vůbec neviděl (jen tichý 422).
    toast.error(error.value)
    await nextTick()
    paneDom.querySelector('[data-error-banner]')?.scrollIntoView({ behavior: 'smooth', block: 'center' })
  } finally {
    submitting.value = false
  }
}

function fieldErr(key: string): string | null {
  const errs = fieldErrors.value[key]
  return errs?.length ? errs[0] : null
}
</script>

<template>
  <div :class="pdfSideBySide ? 'flex items-start gap-4' : ''">
  <div class="space-y-4 max-w-5xl min-w-0" :class="pdfSideBySide ? 'flex-1' : ''">
    <header class="flex items-center justify-between">
      <h1 class="text-xl font-semibold">
        {{ isEdit ? t('purchase_invoice.title_edit') : t('purchase_invoice.title_new') }}
      </h1>
      <RouterLink to="/purchase-invoices" class="text-sm text-neutral-600 hover:text-primary-700">
        {{ t('purchase_invoice.back_to_list') }}
      </RouterLink>
    </header>

    <div v-if="error" data-error-banner class="p-3 bg-danger-50 border border-danger-500/40 text-danger-600 rounded-md text-sm">
      {{ error }}
    </div>

    <!-- AI extraction warning — žluté upozornění, pokud backend zaznamenal podezřelou neshodu
         mezi sumou řádků a AI-vráceným totalem (typicky: subtotal čten jako item). -->
    <div v-if="extractionWarning" class="p-3 bg-warning-50 border border-warning-500/40 rounded-md flex gap-3 items-start">
      <svg class="w-5 h-5 shrink-0 text-warning-600 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v4m0 4h.01M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z" />
      </svg>
      <div class="text-sm flex-1 min-w-0">
        <div class="font-medium text-warning-700">{{ t('purchase_invoice.extraction.warning_title') }}</div>
        <div class="text-warning-700/90 mt-1">{{ extractionWarning }}</div>
      </div>
      <button
        type="button"
        @click="dismissWarning"
        :disabled="dismissingWarning"
        class="cursor-pointer text-xs px-2 py-1 border border-warning-500/50 rounded text-warning-700 hover:bg-warning-100 disabled:opacity-50 shrink-0"
      >
        {{ t('purchase_invoice.extraction.dismiss') }}
      </button>
    </div>

    <div v-if="!loaded" class="text-center py-12 text-neutral-500">…</div>

    <form v-else @submit.prevent="submit" class="space-y-5">
      <section v-if="submission" class="bg-surface border border-primary-500/30 rounded-lg shadow-sm overflow-hidden">
        <div class="p-4 flex flex-wrap items-start justify-between gap-3 border-b border-neutral-200">
          <div class="min-w-0">
            <h2 class="font-semibold text-sm">{{ t('purchase_submissions.editor_origin_title') }}</h2>
            <p class="text-sm text-neutral-700 mt-1 truncate">{{ submission.original_name }}</p>
            <p class="text-xs text-neutral-500 mt-1">{{ t('purchase_submissions.editor_origin_hint') }}</p>
            <p v-if="submission.note" class="text-sm text-neutral-600 mt-2">{{ submission.note }}</p>
          </div>
          <a
            :href="purchaseInvoiceSubmissionsApi.downloadUrl(submission.id)"
            target="_blank"
            rel="noopener"
            class="cursor-pointer whitespace-nowrap px-3 h-9 text-sm border border-neutral-300 text-neutral-700 hover:bg-neutral-50 rounded-md inline-flex items-center gap-1.5"
          >
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v12m0 0l-4-4m4 4 4-4M5 20h14" /></svg>
            {{ t('purchase_submissions.download') }}
          </a>
        </div>
        <iframe
          v-if="submissionCanPreviewInline && submission.doc_type === 'pdf'"
          :src="purchaseInvoiceSubmissionsApi.previewUrl(submission.id)"
          class="w-full h-[65vh] min-h-[420px] border-0"
          :title="submission.original_name"
        />
        <div v-else-if="submissionCanPreviewInline" class="bg-neutral-50 p-4 flex justify-center">
          <img
            :src="purchaseInvoiceSubmissionsApi.previewUrl(submission.id)"
            :alt="submission.original_name"
            class="max-h-[65vh] object-contain"
          />
        </div>
        <div v-else class="bg-neutral-50 px-4 py-8 text-sm text-neutral-500 text-center">
          {{ t('purchase_submissions.no_inline_preview') }}
        </div>
      </section>

      <!-- DRAG & DROP PDF (jen nahoře u nové faktury, schovaný po prvním interaction) -->
      <div v-if="!isEdit && !submissionId && dropzoneVisible" class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm">
        <PdfDropzone accept-structured :uploading="pdfUploading" @file-dropped="onPdfDropped" @error="onPdfError" />
        <p class="text-xs text-neutral-500 mt-2">
          {{ t('purchase_invoice.extraction.ai_pending') }}
        </p>
      </div>

      <!-- Soubor připravený k nahrání u nové faktury (nahraje se po prvním uložení) -->
      <div v-if="!isEdit && !submissionId && pendingPdfFile" class="bg-success-50 border border-success-500/40 rounded-lg shadow-sm overflow-hidden">
        <div class="flex items-center justify-between px-4 py-3 gap-3">
          <div class="flex items-center gap-3 min-w-0">
            <svg class="w-7 h-8 shrink-0" viewBox="0 0 32 36" xmlns="http://www.w3.org/2000/svg">
              <path fill="#dc2626" d="M4 2h16l8 8v22a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2z"/>
              <path fill="#ffffff" opacity="0.35" d="M20 2v8h8z"/>
              <text x="16" y="26" fill="#ffffff" font-family="Arial,Helvetica,sans-serif" font-size="8" font-weight="700" text-anchor="middle" letter-spacing="0.3">PDF</text>
            </svg>
            <div class="min-w-0">
              <div class="font-medium text-sm truncate">{{ pendingPdfFile.name }}</div>
              <div class="text-xs text-success-700 flex items-center gap-1">
                <svg class="w-3.5 h-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                {{ t('purchase_invoice.pdf.pending_badge') }}
              </div>
            </div>
          </div>
          <div class="flex items-center gap-2 flex-wrap shrink-0">
            <button
              v-if="pendingPdfUrl"
              type="button"
              @click="pendingPdfPreviewOpen = !pendingPdfPreviewOpen"
              class="cursor-pointer px-3 h-9 text-sm border border-neutral-300 text-neutral-700 hover:bg-neutral-50 rounded-md inline-flex items-center gap-1.5"
            >
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0z"/>
                <path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
              </svg>
              {{ pendingPdfPreviewOpen ? t('purchase_invoice.pdf.hide') : t('purchase_invoice.pdf.show') }}
            </button>
            <a
              v-if="pendingPdfUrl"
              :href="pendingPdfUrl"
              target="_blank"
              rel="noopener"
              class="cursor-pointer px-3 h-9 text-sm border border-primary-500/40 text-primary-700 hover:bg-primary-50 rounded-md inline-flex items-center gap-1.5"
            >
              <svg class="w-4 h-4 text-primary-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 0 0 3 3h10a3 3 0 0 0 3-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
              {{ t('purchase_invoice.pdf.open') }}
            </a>
            <button
              type="button"
              @click="clearPendingPdf"
              class="cursor-pointer px-3 h-9 text-sm border border-danger-500/50 text-danger-500 hover:bg-danger-50 rounded-md inline-flex items-center gap-1.5"
            >
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
              {{ t('common.remove') }}
            </button>
          </div>
        </div>
        <div v-if="canHandoffPendingDocument" class="px-4 py-3 border-t border-success-500/30 bg-surface/70 flex flex-wrap items-center justify-between gap-3">
          <p class="text-sm text-neutral-700 max-w-2xl">
            {{ t('purchase_submissions.editor_handoff_hint') }}
          </p>
          <button
            type="button"
            data-testid="handoff-pending-document"
            :disabled="handoffUploading"
            :class="btnFilled('primary')"
            @click="handoffPendingDocument"
          >
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M12 16V4m0 0L7 9m5-5 5 5M5 14v5a1 1 0 001 1h12a1 1 0 001-1v-5" />
            </svg>
            {{ handoffUploading
              ? t('purchase_submissions.uploading')
              : t('purchase_submissions.editor_handoff_action') }}
          </button>
        </div>
        <!-- Inline náhled ze souboru v paměti (blob: URL) — faktura ještě není na serveru.
             Obrázek přes <img>, PDF přes <embed> (NE <iframe> ani #view= fragment — Chrome
             odmítá blob PDF v iframu / s fragmentem jako „local resource"). Když ani <embed>
             nevykreslí, je tu tlačítko „Otevřít" pro zobrazení v nové záložce. -->
        <div v-if="pendingPdfPreviewOpen && pendingPdfUrl" class="bg-neutral-100 border-t border-success-500/30">
          <img
            v-if="pendingPdfIsImage"
            :src="pendingPdfUrl"
            :alt="pendingPdfFile?.name || 'preview'"
            class="w-full max-h-[80vh] object-contain mx-auto"
          />
          <embed
            v-else
            :src="pendingPdfUrl"
            type="application/pdf"
            class="w-full h-[80vh] border-0"
            :title="pendingPdfFile?.name || 'PDF'"
          />
        </div>
      </div>

      <!-- Existující PDF na detail/edit (s inline preview, stejný pattern jako InvoiceDetail.vue) -->
      <div v-if="existingPdf" class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
        <div class="flex items-center justify-between px-4 py-3 border-b border-neutral-100">
          <div class="flex items-center gap-3">
            <svg class="w-7 h-8 shrink-0" viewBox="0 0 32 36" xmlns="http://www.w3.org/2000/svg">
              <path fill="#dc2626" d="M4 2h16l8 8v22a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2z"/>
              <path fill="#ffffff" opacity="0.35" d="M20 2v8h8z"/>
              <text x="16" y="26" fill="#ffffff" font-family="Arial,Helvetica,sans-serif" font-size="8" font-weight="700" text-anchor="middle" letter-spacing="0.3">PDF</text>
            </svg>
            <div>
              <div class="font-medium text-sm">{{ existingPdf.name }}</div>
              <div v-if="existingPdf.size > 0" class="text-xs text-neutral-500">{{ Math.round(existingPdf.size / 1024) }} KiB</div>
              <div v-else class="text-xs text-neutral-400 font-mono">{{ existingPdf.hash?.slice(0, 12) }}…</div>
            </div>
          </div>
          <div class="flex items-center gap-2 flex-wrap">
            <button
              v-if="invoiceId"
              type="button"
              @click="pdfPreviewOpen = !pdfPreviewOpen"
              class="cursor-pointer px-3 h-9 text-sm border border-neutral-300 text-neutral-700 hover:bg-neutral-50 rounded-md inline-flex items-center gap-1.5"
            >
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0z"/>
                <path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
              </svg>
              {{ pdfPreviewOpen ? t('purchase_invoice.pdf.hide') : t('purchase_invoice.pdf.show') }}
            </button>
            <a
              v-if="invoiceId"
              :href="purchaseInvoicesApi.pdfUrl(invoiceId)"
              target="_blank"
              class="cursor-pointer px-3 h-9 text-sm border border-primary-500/40 text-primary-700 hover:bg-primary-50 rounded-md inline-flex items-center gap-1.5"
            >
              <svg class="w-4 h-4 text-primary-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 0 0 3 3h10a3 3 0 0 0 3-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
              {{ t('purchase_invoice.pdf.open') }}
            </a>
            <button
              type="button"
              @click="onReplacePdf"
              class="cursor-pointer px-3 h-9 text-sm border border-danger-500/50 text-danger-500 hover:bg-danger-50 rounded-md inline-flex items-center gap-1.5"
            >
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0 1 16.138 21H7.862a2 2 0 0 1-1.995-1.858L5 7m5 4v6m4-6v6M1 7h22M9 7V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v3"/></svg>
              {{ t('common.delete') }}
            </button>
          </div>
        </div>
        <!-- Inline PDF preview přes browser PDF viewer. Musí být ?inline=1 (jinak
             Content-Disposition: attachment a Edge/IE blokují embed). -->
        <div v-if="pdfPreviewOpen && invoiceId && !pdfSideBySide" class="bg-neutral-100">
          <iframe
            :src="pdfInlineUrl"
            class="w-full h-[80vh] border-0"
            :title="existingPdf.name || 'PDF'"
          ></iframe>
        </div>
      </div>

      <!-- Replace dropzone když user vybere replace -->
      <div v-else-if="isEdit && dropzoneVisible" class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm">
        <PdfDropzone :uploading="pdfUploading" @file-dropped="onPdfDropped" @error="onPdfError" />
      </div>

      <!-- Box 1: Hlavička — vendor + typ + čísla + datumy + měna -->
      <div class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm space-y-4">
        <h2 class="text-sm font-medium text-neutral-700 pb-2 border-b border-neutral-100">
          {{ t('purchase_invoice.fields.vendor') }} & {{ t('purchase_invoice.fields.document_kind') }}
        </h2>

        <!-- Vendor + document kind -->
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div>
            <div class="flex gap-2">
              <div class="flex-1 min-w-0">
                <VendorPicker
                  v-model="form.vendor_id"
                  @selected="onVendorSelected"
                />
              </div>
              <button type="button" @click="vendorModalOpen = true"
                class="cursor-pointer shrink-0 h-9 px-3 mt-[26px] inline-flex items-center gap-1.5 border border-primary-500/40 text-primary-700 hover:bg-primary-50 rounded-md text-sm font-medium"
                :title="t('purchase_invoice.new_vendor')">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" />
                </svg>
                <span class="hidden sm:inline">{{ t('purchase_invoice.new_vendor') }}</span>
              </button>
            </div>
          </div>
          <div>
            <label class="block text-sm text-neutral-700 mb-1">{{ t('purchase_invoice.fields.document_kind') }}</label>
            <select v-model="form.document_kind" class="w-full h-10 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
              <option value="invoice">{{ t('purchase_invoice.document_kind.invoice') }}</option>
              <option value="receipt">{{ t('purchase_invoice.document_kind.receipt') }}</option>
              <option value="credit_note">{{ t('purchase_invoice.document_kind.credit_note') }}</option>
              <option value="advance">{{ t('purchase_invoice.document_kind.advance') }}</option>
              <option value="tax_document">{{ t('purchase_invoice.document_kind.tax_document') }}</option>
            </select>
          </div>
        </div>

        <!-- Dobropis: vazba na opravovanou přijatou fakturu (migrace 1096) -->
        <div v-if="form.document_kind === 'credit_note'"
             class="rounded-md border border-amber-200 bg-amber-50/60 px-3 py-2.5">
          <label class="block text-sm font-medium text-neutral-700 mb-1">
            {{ t('purchase_invoice.credit_note_link.label') }}
          </label>
          <select v-model="form.parent_purchase_invoice_id"
                  :disabled="!form.vendor_id || parentCandidatesLoading"
                  class="w-full h-10 px-3 border border-neutral-300 rounded-md bg-surface text-sm disabled:opacity-60">
            <option :value="null">{{ t('purchase_invoice.credit_note_link.none') }}</option>
            <option v-for="c in parentCandidates" :key="c.id" :value="c.id">
              {{ c.vendor_invoice_number }} · {{ c.issue_date }} · {{ formatMoney(c.total_with_vat, c.currency) }}
            </option>
          </select>
          <p class="text-xs text-neutral-500 mt-1">
            <span v-if="parentCandidatesLoading">{{ t('common.loading') }}</span>
            <span v-else-if="!form.vendor_id">{{ t('purchase_invoice.credit_note_link.pick_vendor') }}</span>
            <span v-else-if="parentCandidates.length === 0">{{ t('purchase_invoice.credit_note_link.no_candidates') }}</span>
            <span v-else>{{ t('purchase_invoice.credit_note_link.hint') }}</span>
          </p>
        </div>

        <!-- DDKP (daňový doklad k platbě §28 ZDPH): vazba na uhrazenou zálohu -->
        <div v-if="form.document_kind === 'tax_document'"
             class="rounded-md border border-primary-200 bg-primary-50/60 px-3 py-2.5">
          <label class="block text-sm font-medium text-neutral-700 mb-1">
            {{ t('purchase_invoice.tax_document_link.label') }}
          </label>
          <select v-model="form.parent_purchase_invoice_id"
                  :disabled="!form.vendor_id || parentCandidatesLoading"
                  class="w-full h-10 px-3 border border-neutral-300 rounded-md bg-surface text-sm disabled:opacity-60">
            <option :value="null">{{ t('purchase_invoice.tax_document_link.none') }}</option>
            <option v-for="c in parentCandidates" :key="c.id" :value="c.id">
              {{ c.vendor_invoice_number }} · {{ c.issue_date }} · {{ formatMoney(c.total_with_vat, c.currency) }}
            </option>
          </select>
          <p class="text-xs text-neutral-500 mt-1">
            <span v-if="parentCandidatesLoading">{{ t('common.loading') }}</span>
            <span v-else-if="!form.vendor_id">{{ t('purchase_invoice.tax_document_link.pick_vendor') }}</span>
            <span v-else-if="parentCandidates.length === 0">{{ t('purchase_invoice.tax_document_link.no_candidates') }}</span>
            <span v-else>{{ t('purchase_invoice.tax_document_link.hint') }}</span>
          </p>
        </div>

        <!-- Vendor invoice number + our varsymbol -->
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div>
            <label class="block text-sm text-neutral-700 mb-1">{{ t('purchase_invoice.fields.vendor_invoice_number') }} <span class="text-danger-500">*</span></label>
            <input v-model="form.vendor_invoice_number" type="text" maxlength="50" required
                   class="w-full h-10 px-3 border rounded-md text-sm font-mono"
                   :class="fieldErr('vendor_invoice_number') ? 'border-danger-500/40' : 'border-neutral-300'" />
            <p class="text-xs text-neutral-500 mt-1">{{ t('purchase_invoice.fields.vendor_invoice_number_hint') }}</p>
            <p v-if="fieldErr('vendor_invoice_number')" class="text-xs text-danger-600 mt-1">{{ fieldErr('vendor_invoice_number') }}</p>
          </div>
          <div>
            <label class="block text-sm text-neutral-700 mb-1">{{ t('purchase_invoice.fields.varsymbol') }}</label>
            <input v-model="form.varsymbol" type="text" maxlength="20"
                   class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono"
                   placeholder="PF2605001" />
            <p class="text-xs text-neutral-500 mt-1">{{ t('purchase_invoice.fields.varsymbol_hint') }}</p>
          </div>
        </div>

        <!-- Dates -->
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
          <div>
            <label class="block text-sm text-neutral-700 mb-1">{{ t('purchase_invoice.fields.issue_date') }} <span class="text-danger-500">*</span></label>
            <input v-model="form.issue_date" type="date" required class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
          </div>
          <div>
            <label class="block text-sm text-neutral-700 mb-1">{{ t('purchase_invoice.fields.tax_date') }}</label>
            <input v-model="form.tax_date" type="date" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
          </div>
          <div>
            <label class="block text-sm text-neutral-700 mb-1">{{ t('purchase_invoice.fields.due_date') }} <span class="text-danger-500">*</span></label>
            <input v-model="form.due_date" type="date" required class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
          </div>
          <div>
            <label class="block text-sm text-neutral-700 mb-1">{{ t('purchase_invoice.fields.received_at') }}</label>
            <input v-model="form.received_at" type="date" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
          </div>
        </div>
        <!-- RC: DPH období se řídí DUZP (§ 25 / § 24), ne datem vystavení — issue #117 -->
        <p v-if="form.reverse_charge" class="text-xs text-neutral-500 -mt-2">
          {{ t('purchase_invoice.fields.tax_date_rc_hint') }}
        </p>
        <!-- Období odpočtu (§ 73/1/a) — issue #9: uživatel musí z formuláře poznat, do
             kterého období DPH doklad půjde a proč, ne to zjišťovat až z Knihy DPH. -->
        <div v-else-if="vatClaim" class="-mt-2 text-xs rounded-md px-3 py-2"
             :class="vatClaim.basis === 'received_at'
               ? 'bg-warning-50 text-warning-700 border border-warning-500/30'
               : 'bg-neutral-50 text-neutral-600 border border-neutral-200'">
          <p>
            {{ t('purchase_invoice.fields.vat_claim_hint', {
              period: vatClaimPeriodLabel,
              basis: t('purchase_invoice.fields.vat_claim_basis.' + vatClaim.basis),
            }) }}
          </p>
          <p v-if="vatClaim.basis === 'received_at'" class="mt-1">
            {{ t('purchase_invoice.fields.vat_claim_received_note') }}
          </p>
        </div>

        <!-- Currency + exchange rate -->
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div>
            <label class="block text-sm text-neutral-700 mb-1">{{ t('purchase_invoice.fields.currency') }} <span class="text-danger-500">*</span></label>
            <div class="flex items-center gap-2">
              <select v-model="form.currency_id" required class="flex-1 h-10 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
                <option :value="null">—</option>
                <option v-for="c in currencyOptions" :key="c.id" :value="c.id">
                  {{ c.code }}{{ !c.is_active ? ' · ' + t('purchase_invoice.fields.currency_purchase_only') : '' }}
                </option>
              </select>
              <button
                type="button"
                @click="showAddCurrency = true"
                class="cursor-pointer h-10 px-3 text-sm border border-neutral-300 rounded-md hover:bg-neutral-50 whitespace-nowrap"
                :title="t('purchase_invoice.fields.currency_add_hint')"
              >+ měna</button>
            </div>
            <p v-if="form.currency_id && !currencyOptions.find(c => c.id === form.currency_id)?.is_active"
               class="text-xs text-neutral-500 mt-1">
              {{ t('purchase_invoice.fields.currency_inactive_hint') }}
            </p>
          </div>
          <ExchangeRateInput
            v-if="showExchangeRate"
            v-model="form.exchange_rate"
            :currency="currencyCode"
            :rate-date="form.tax_date || form.issue_date"
            @cnb-loaded="(v) => { form.exchange_rate_date = v.rate_date; form.exchange_rate_source = 'cnb' }"
            @source-change="(s) => form.exchange_rate_source = s"
          />
        </div>

        <!-- Reverse charge + fixed asset + language -->
        <div class="flex flex-wrap items-center gap-6 pt-2 border-t border-neutral-100">
          <label class="inline-flex items-center gap-2 text-sm">
            <input type="checkbox" v-model="form.reverse_charge" class="rounded" />
            {{ t('purchase_invoice.fields.reverse_charge') }}
          </label>
          <label class="inline-flex items-center gap-2 text-sm" :title="t('purchase_invoice.fields.vendor_is_vat_payer_hint')">
            <input type="checkbox" v-model="form.vendor_is_vat_payer" @change="onVendorVatPayerToggle" class="rounded" />
            <span :class="!form.vendor_is_vat_payer ? 'text-warning-700' : ''">{{ t('purchase_invoice.fields.vendor_is_vat_payer') }}</span>
            <span v-if="vendorVatStatusLoading" class="text-xs text-neutral-400">…</span>
          </label>
          <!-- Odvozeno z položek (§DM) — read-only, aby nevznikl druhý zdroj pravdy. -->
          <span
            class="inline-flex items-center gap-2 text-sm text-neutral-500"
            :title="t('purchase_invoice.fields.is_fixed_asset_derived_hint')"
          >
            <input type="checkbox" :checked="derivedIsFixedAsset" disabled class="rounded opacity-60" />
            {{ t('purchase_invoice.fields.is_fixed_asset') }}
          </span>
          <label class="inline-flex items-center gap-2 text-sm" :title="t('purchase_invoice.fields.prices_include_vat_hint')">
            <input type="checkbox" v-model="form.prices_include_vat" class="rounded" />
            {{ t('purchase_invoice.fields.prices_include_vat') }}
          </label>
          <div class="inline-flex items-center gap-2">
            <label class="text-sm text-neutral-700">{{ t('purchase_invoice.fields.language') }}:</label>
            <select v-model="form.language" class="h-8 px-2 border border-neutral-300 rounded-md bg-surface text-sm">
              <option value="cs">CS</option>
              <option value="en">EN</option>
            </select>
          </div>
        </div>
      </div>

      <!-- Box 2: Položky -->
      <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm">
        <header class="flex items-center justify-between px-5 py-3 border-b border-neutral-100">
          <h2 class="text-sm font-medium text-neutral-700">{{ t('purchase_invoice.items.title') }}</h2>
          <button type="button" @click="addItem()" class="cursor-pointer px-3 h-8 text-sm bg-primary-600 hover:bg-primary-700 text-white rounded-md font-medium">
            {{ t('purchase_invoice.items.add') }}
          </button>
        </header>
        <EmptyState v-if="form.items.length === 0" dense icon="doc"
          :title="t('purchase_invoice.items.empty')" :cta="t('purchase_invoice.items.add')" @action="addItem()" />
        <!-- Desktop: tabulka -->
        <div v-else class="hidden md:block overflow-x-auto">
        <table class="w-full text-sm border-collapse">
          <thead>
            <tr class="text-xs text-neutral-500 bg-neutral-50">
              <th class="text-left py-2 pl-5 pr-2 font-normal">{{ t('purchase_invoice.items.description') }}</th>
              <th class="text-right py-2 px-1 font-normal w-20">{{ t('purchase_invoice.items.quantity') }}</th>
              <th class="text-left py-2 px-1 font-normal w-20">{{ t('purchase_invoice.items.unit') }}</th>
              <th class="text-right py-2 px-1 font-normal w-28">{{ unitPriceHeaderLabel }}</th>
              <th class="text-left py-2 px-1 font-normal w-24">{{ t('purchase_invoice.items.vat_rate') }}</th>
              <th class="text-left py-2 px-1 font-normal w-36">{{ t('purchase_invoice.items.expense_kind') }}</th>
              <th class="text-right py-2 px-1 font-normal w-28">{{ t('purchase_invoice.items.total_with_vat') }}</th>
              <th class="w-10 pr-3"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(it, i) in form.items" :key="i" class="border-t border-neutral-200">
              <td class="py-2 pl-5 pr-2">
                <StockDescriptionField
                  v-model:description="it.description"
                  :stock-item-id="it.stock_item_id ?? null"
                  :stock-enabled="stockEnabled"
                  :options="stockRowOptions[i] ?? []"
                  :loading="stockRowLoading[i]"
                  :selected-option="stockSelectedFor(it)"
                  :placeholder="t('purchase_invoice.items.description')"
                  :invalid="!!fieldErr(`items.${i}.description`)"
                  row-input-marker="pur-item"
                  :no-results-label="t('common.no_results')"
                  :keep-free-text-label="t('common.keep_free_text')"
                  :unlink-label="t('common.unlink_stock')"
                  @search="(q: string) => onStockSearch(i, q)"
                  @select="(v: number | null) => onStockSelect(i, v)"
                />
                <p v-if="fieldErr(`items.${i}.description`)" class="text-xs text-danger-600 mt-1">{{ fieldErr(`items.${i}.description`) }}</p>
              </td>
              <td class="py-2 px-1">
                <input v-model="it.quantity" v-math type="text" inputmode="decimal" class="w-full h-9 px-2 border border-neutral-300 rounded text-sm text-right font-mono" />
              </td>
              <td class="py-2 px-1">
                <select v-model="it.unit" class="w-full h-9 px-1 border border-neutral-300 rounded bg-surface text-sm">
                  <option v-for="u in units" :key="u.code" :value="u.code">{{ u.code }}</option>
                </select>
              </td>
              <td class="py-2 px-1">
                <input v-model="it.unit_price_without_vat" v-math type="text" inputmode="decimal" class="w-full h-9 px-2 border border-neutral-300 rounded text-sm text-right font-mono" />
              </td>
              <td class="py-2 px-1">
                <select v-model.number="it.vat_rate_id" class="w-full h-9 px-1 border border-neutral-300 rounded bg-surface text-sm">
                  <option v-for="v in vatRates" :key="v.id" :value="v.id">{{ vatRateLabel(v) }}</option>
                </select>
              </td>
              <td class="py-2 px-1 align-top">
                <select
                  v-model="it.expense_kind"
                  :title="t('purchase_invoice.items.expense_kind_hint')"
                  class="w-full h-9 px-1 border border-neutral-300 rounded bg-surface text-sm"
                >
                  <option :value="null">{{ t('purchase_invoice.items.expense_kind_unset') }}</option>
                  <option v-for="k in EXPENSE_KINDS" :key="k" :value="k">{{ t(`purchase_invoice.expense_kind.${k}`) }}</option>
                </select>
                <ExpenseKindSuggestionHint
                  v-if="suggestionFor(it)"
                  :suggestion="suggestionFor(it)!"
                  @apply="applySuggestion(it)"
                  @dismiss="dismissSuggestion(it)"
                />
                <template v-if="showAccrual">
                  <button
                    type="button"
                    @click="toggleAccrual(it, i)"
                    :title="t('purchase_invoice.items.accrual_hint')"
                    class="mt-1 text-xs text-primary-600 hover:underline"
                  >
                    {{ accrualVisible(it, i) ? t('purchase_invoice.items.accrual_remove') : t('purchase_invoice.items.accrual_add') }}
                  </button>
                  <div v-if="accrualVisible(it, i)" class="mt-1 space-y-1">
                    <input v-model="it.accrual_from" type="date" :title="t('purchase_invoice.items.accrual_from')"
                      class="w-full h-8 px-1 border border-neutral-300 rounded bg-surface text-xs" />
                    <input v-model="it.accrual_to" type="date" :title="t('purchase_invoice.items.accrual_to')"
                      class="w-full h-8 px-1 border border-neutral-300 rounded bg-surface text-xs" />
                  </div>
                </template>
              </td>
              <td class="py-2 px-1">
                <input :value="itemTotal(it).with" @change="setItemGross(it, ($event.target as HTMLInputElement).value)"
                  type="text" inputmode="decimal" :title="t('purchase_invoice.items.gross_edit_hint')"
                  class="w-full h-9 px-2 border border-neutral-300 rounded text-sm text-right font-mono" />
              </td>
              <td class="py-2 px-1 pr-3 text-center">
                <button type="button" @click="removeItem(i)" class="cursor-pointer w-8 h-8 inline-flex items-center justify-center text-neutral-400 hover:text-danger-600 hover:bg-danger-50 rounded" :title="t('purchase_invoice.items.remove')">✕</button>
              </td>
            </tr>
          </tbody>
        </table>
        </div>

        <!-- Mobile: stack karet (každé pole na vlastním řádku, čitelné inputy) -->
        <div v-if="form.items.length > 0" class="md:hidden divide-y divide-neutral-200 border-t border-neutral-200">
          <div v-for="(it, i) in form.items" :key="`m-${i}`" class="p-3 space-y-2">
            <div class="flex items-center justify-between text-xs text-neutral-500">
              <span class="font-mono">#{{ i + 1 }}</span>
              <button type="button" @click="removeItem(i)" class="cursor-pointer w-8 h-8 inline-flex items-center justify-center border border-danger-500/40 text-danger-500 hover:bg-danger-50 rounded text-lg leading-none" :title="t('purchase_invoice.items.remove')">✕</button>
            </div>
            <div>
              <label class="block text-xs font-medium text-neutral-600 mb-1">{{ t('purchase_invoice.items.description') }}</label>
              <StockDescriptionField
                v-model:description="it.description"
                :stock-item-id="it.stock_item_id ?? null"
                :stock-enabled="stockEnabled"
                :options="stockRowOptions[i] ?? []"
                :loading="stockRowLoading[i]"
                :selected-option="stockSelectedFor(it)"
                :placeholder="t('purchase_invoice.items.description')"
                :invalid="!!fieldErr(`items.${i}.description`)"
                row-input-marker="pur-item"
                :no-results-label="t('common.no_results')"
                :keep-free-text-label="t('common.keep_free_text')"
                :unlink-label="t('common.unlink_stock')"
                @search="(q: string) => onStockSearch(i, q)"
                @select="(v: number | null) => onStockSelect(i, v)"
              />
              <p v-if="fieldErr(`items.${i}.description`)" class="text-xs text-danger-600 mt-1">{{ fieldErr(`items.${i}.description`) }}</p>
            </div>
            <div class="grid grid-cols-2 gap-2">
              <div>
                <label class="block text-xs font-medium text-neutral-600 mb-1">{{ t('purchase_invoice.items.quantity') }}</label>
                <input v-model="it.quantity" v-math type="text" inputmode="decimal" class="w-full h-10 px-3 border border-neutral-300 rounded text-sm text-right font-mono" />
              </div>
              <div>
                <label class="block text-xs font-medium text-neutral-600 mb-1">{{ t('purchase_invoice.items.unit') }}</label>
                <select v-model="it.unit" class="w-full h-10 px-2 border border-neutral-300 rounded bg-surface text-sm">
                  <option v-for="u in units" :key="u.code" :value="u.code">{{ u.code }}</option>
                </select>
              </div>
            </div>
            <div class="grid grid-cols-2 gap-2">
              <div>
                <label class="block text-xs font-medium text-neutral-600 mb-1">{{ unitPriceHeaderLabel }}</label>
                <input v-model="it.unit_price_without_vat" v-math type="text" inputmode="decimal" class="w-full h-10 px-3 border border-neutral-300 rounded text-sm text-right font-mono" />
              </div>
              <div>
                <label class="block text-xs font-medium text-neutral-600 mb-1">{{ t('purchase_invoice.items.vat_rate') }}</label>
                <select v-model.number="it.vat_rate_id" class="w-full h-10 px-2 border border-neutral-300 rounded bg-surface text-sm">
                  <option v-for="v in vatRates" :key="v.id" :value="v.id">{{ vatRateLabel(v) }}</option>
                </select>
              </div>
            </div>
            <div>
              <label class="block text-xs font-medium text-neutral-600 mb-1">{{ t('purchase_invoice.items.expense_kind') }}</label>
              <select
                v-model="it.expense_kind"
                :title="t('purchase_invoice.items.expense_kind_hint')"
                class="w-full h-10 px-2 border border-neutral-300 rounded bg-surface text-sm"
              >
                <option :value="null">{{ t('purchase_invoice.items.expense_kind_unset') }}</option>
                <option v-for="k in EXPENSE_KINDS" :key="k" :value="k">{{ t(`purchase_invoice.expense_kind.${k}`) }}</option>
              </select>
              <ExpenseKindSuggestionHint
                v-if="suggestionFor(it)"
                :suggestion="suggestionFor(it)!"
                @apply="applySuggestion(it)"
                @dismiss="dismissSuggestion(it)"
              />
              <template v-if="showAccrual">
                <button
                  type="button"
                  @click="toggleAccrual(it, i)"
                  class="mt-1 text-xs text-primary-600 hover:underline"
                >
                  {{ accrualVisible(it, i) ? t('purchase_invoice.items.accrual_remove') : t('purchase_invoice.items.accrual_add') }}
                </button>
                <div v-if="accrualVisible(it, i)" class="mt-1 grid grid-cols-2 gap-2">
                  <div>
                    <label class="block text-xs text-neutral-600 mb-1">{{ t('purchase_invoice.items.accrual_from') }}</label>
                    <input v-model="it.accrual_from" type="date" class="w-full h-10 px-2 border border-neutral-300 rounded bg-surface text-sm" />
                  </div>
                  <div>
                    <label class="block text-xs text-neutral-600 mb-1">{{ t('purchase_invoice.items.accrual_to') }}</label>
                    <input v-model="it.accrual_to" type="date" class="w-full h-10 px-2 border border-neutral-300 rounded bg-surface text-sm" />
                  </div>
                </div>
              </template>
            </div>
            <div class="flex items-baseline justify-between pt-1 border-t border-neutral-200">
              <span class="text-xs font-medium text-neutral-500 uppercase tracking-wide">{{ t('purchase_invoice.items.total_with_vat') }}</span>
              <input :value="itemTotal(it).with" @change="setItemGross(it, ($event.target as HTMLInputElement).value)"
                type="text" inputmode="decimal" :title="t('purchase_invoice.items.gross_edit_hint')"
                class="w-32 h-9 px-2 border border-neutral-300 rounded text-sm text-right font-mono font-semibold" />
            </div>
          </div>
        </div>

        <!-- Totals preview uvnitř Box 2 + editovatelné zaokrouhlení -->
        <div v-if="form.items.length > 0" class="px-5 py-3 border-t border-neutral-100 bg-neutral-50/50 flex justify-end">
          <table class="text-sm">
            <tr><td class="pr-4 py-0.5 text-neutral-600">{{ t('purchase_invoice.totals.without_vat') }}:</td><td class="text-right font-mono py-0.5">{{ formatMoney(totals.without_vat, currencyCode) }}</td></tr>
            <tr><td class="pr-4 py-0.5 text-neutral-600">{{ t('purchase_invoice.totals.vat') }}:</td><td class="text-right font-mono py-0.5">{{ formatMoney(totals.vat, currencyCode) }}</td></tr>
            <tr class="font-semibold border-t border-neutral-200"><td class="pr-4 pt-1.5">{{ t('purchase_invoice.totals.with_vat') }}:</td><td class="text-right font-mono pt-1.5">{{ formatMoney(totals.with_vat, currencyCode) }}</td></tr>
            <tr>
              <td class="pr-4 py-1 text-neutral-600">{{ t('purchase_invoice.totals.rounding') }}:</td>
              <td class="text-right">
                <input v-model.number="form.rounding" type="number" step="0.01"
                  class="w-24 h-7 px-2 text-right border border-neutral-300 rounded text-sm font-mono"
                  :title="t('purchase_invoice.totals.rounding_hint')" />
              </td>
            </tr>
            <tr v-if="form.rounding !== 0" class="font-semibold border-t border-neutral-100">
              <td class="pr-4 pt-1.5">{{ t('purchase_invoice.totals.with_vat_rounded') }}:</td>
              <td class="text-right font-mono pt-1.5">{{ formatMoney(totals.with_vat + form.rounding, currencyCode) }}</td>
            </tr>
            <!-- Uhrazená záloha — ručně editovatelná (propojení zálohy v detailu ji nastaví automaticky).
                 amount_to_pay je v DB generated column (total_with_vat − advance_paid_amount), přepočítá se po uložení. -->
            <tr class="border-t border-neutral-200">
              <td class="pr-4 py-1 text-neutral-600">{{ t('purchase_invoice.totals.advance_paid') }}:</td>
              <td class="text-right">
                <input v-model.number="form.advance_paid_amount" type="number" step="0.01" min="0"
                  class="w-28 h-7 px-2 text-right border border-neutral-300 rounded text-sm font-mono" />
              </td>
            </tr>
            <tr v-if="form.advance_paid_amount > 0" class="font-semibold border-t border-neutral-100">
              <td class="pr-4 pt-1.5">{{ t('purchase_invoice.totals.to_pay') }}:</td>
              <td class="text-right font-mono pt-1.5">{{ formatMoney(totals.with_vat + (form.rounding || 0) - (form.advance_paid_amount || 0), currencyCode) }}</td>
            </tr>
          </table>
        </div>
      </div>

      <!-- Box 2b: Rekapitulace DPH — editovatelná dle dokladu dodavatele (§ 73 ZDPH).
           Pod reverse-charge se skrývá (na dokladu zahr. dodavatele není česká DPH). -->
      <div v-if="form.items.length > 0 && !form.reverse_charge" class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
        <div class="px-5 py-3 border-b border-neutral-100 flex items-center justify-between gap-3">
          <h2 class="text-sm font-medium text-neutral-700">{{ t('purchase_invoice.vat_recap.title') }}</h2>
          <div class="flex flex-wrap items-center justify-end gap-3">
            <button type="button" @click="showAccrual = !showAccrual"
              :title="t('purchase_invoice.items.accrual_hint')"
              class="cursor-pointer text-xs hover:underline whitespace-nowrap"
              :class="showAccrual ? 'text-danger-600' : 'text-primary-700'">
              {{ showAccrual ? t('purchase_invoice.items.accrual_hide') : t('purchase_invoice.items.accrual_show') }}
            </button>
            <button v-if="!hasVatAllocations" type="button" @click="startVatAllocations"
              class="cursor-pointer text-xs text-primary-700 hover:underline whitespace-nowrap">
              {{ t('purchase_invoice.vat_allocation.enable') }}
            </button>
            <button v-else type="button" @click="vatAllocations = []"
              class="cursor-pointer text-xs text-danger-600 hover:underline whitespace-nowrap">
              {{ t('purchase_invoice.vat_allocation.disable') }}
            </button>
            <button v-if="hasVatOverride" type="button" @click="vatOverrides = {}"
              class="cursor-pointer text-xs text-primary-700 hover:underline whitespace-nowrap">
              {{ t('purchase_invoice.vat_recap.reset_all') }}
            </button>
          </div>
        </div>
        <div class="px-5 py-3">
          <p class="text-xs text-neutral-500 mb-3">{{ t('purchase_invoice.vat_recap.hint') }}</p>
          <table class="w-full sm:w-auto text-sm">
            <thead>
              <tr class="text-xs text-neutral-500">
                <th class="text-left font-normal py-1 pr-6">{{ t('purchase_invoice.vat_recap.rate') }}</th>
                <th class="text-right font-normal py-1 px-2">{{ t('purchase_invoice.vat_recap.base') }}</th>
                <th class="text-right font-normal py-1 px-2">{{ t('purchase_invoice.vat_recap.vat') }}</th>
                <th class="w-8"></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="r in recapRows" :key="r.rate" class="border-t border-neutral-100">
                <td class="py-1.5 pr-6 text-neutral-700 font-medium">{{ r.rate }} %</td>
                <td class="py-1.5 px-2">
                  <input :value="r.base" @change="setRecapBase(r.rate, ($event.target as HTMLInputElement).value)"
                    type="text" inputmode="decimal"
                    class="w-32 h-8 px-2 border rounded text-sm text-right font-mono"
                    :class="r.overridden ? 'border-warning-500/60 bg-warning-50' : 'border-neutral-300'" />
                </td>
                <td class="py-1.5 px-2">
                  <input :value="r.vat" @change="setRecapVat(r.rate, ($event.target as HTMLInputElement).value)"
                    type="text" inputmode="decimal"
                    class="w-32 h-8 px-2 border rounded text-sm text-right font-mono"
                    :class="r.overridden ? 'border-warning-500/60 bg-warning-50' : 'border-neutral-300'" />
                </td>
                <td class="py-1.5 pl-1 text-right">
                  <button v-if="r.overridden" type="button" @click="resetRecapRate(r.rate)"
                    :title="t('purchase_invoice.vat_recap.reset')"
                    class="cursor-pointer text-neutral-400 hover:text-primary-700 text-base leading-none">↺</button>
                </td>
              </tr>
            </tbody>
          </table>
          <p v-if="hasVatOverride" class="text-xs text-warning-700 mt-3 flex items-center gap-1.5">
            <svg class="w-3.5 h-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0z"/></svg>
            {{ t('purchase_invoice.vat_recap.overridden_note') }}
          </p>
          <div v-if="hasVatAllocations" class="mt-4 pt-4 border-t border-neutral-200 space-y-4">
            <p class="text-xs text-neutral-500">{{ t('purchase_invoice.vat_allocation.hint') }}</p>
            <section v-for="r in recapRows" :key="'alloc-' + r.rate" class="rounded-md border border-neutral-200 p-3">
              <div class="flex flex-wrap items-center justify-between gap-2 mb-3">
                <h3 class="text-sm font-medium text-neutral-700">{{ r.rate }} %</h3>
                <button type="button" @click="addVatAllocation(r.rate)"
                  class="cursor-pointer text-xs text-primary-700 hover:underline whitespace-nowrap">
                  + {{ t('purchase_invoice.vat_allocation.add') }}
                </button>
              </div>
              <div class="space-y-2">
                <div v-for="(a, ai) in allocationsForRate(r.rate)" :key="a.id ?? ai"
                  class="grid grid-cols-1 sm:grid-cols-12 gap-2 items-end rounded-md bg-neutral-50 p-3">
                  <div class="sm:col-span-3">
                    <label class="block text-xs text-neutral-500 mb-1">{{ t('purchase_invoice.vat_allocation.description') }}</label>
                    <input v-model="a.description" type="text" class="w-full h-9 px-2 border border-neutral-300 rounded text-sm" />
                  </div>
                  <div class="sm:col-span-2">
                    <label class="block text-xs text-neutral-500 mb-1">{{ t('purchase_invoice.vat_allocation.usage') }}</label>
                    <select v-model="a.usage_type" :disabled="ai === 0" @change="applyAllocationPreset(a); syncAllocationResidual(r.rate)"
                      class="w-full h-9 px-2 border border-neutral-300 rounded text-sm bg-surface disabled:bg-neutral-100">
                      <option value="business">{{ t('purchase_invoice.vat_allocation.usage_business') }}</option>
                      <option value="personal">{{ t('purchase_invoice.vat_allocation.usage_personal') }}</option>
                      <option value="mixed">{{ t('purchase_invoice.vat_allocation.usage_mixed') }}</option>
                      <option value="non_deductible">{{ t('purchase_invoice.vat_allocation.usage_non_deductible') }}</option>
                    </select>
                  </div>
                  <div class="sm:col-span-2">
                    <label class="block text-xs text-neutral-500 mb-1">{{ t('purchase_invoice.vat_allocation.total') }}</label>
                    <input :value="a.total_amount" :disabled="ai === 0"
                      @change="setAllocationGross(a, ($event.target as HTMLInputElement).value)"
                      type="text" inputmode="decimal"
                      class="w-full h-9 px-2 border border-neutral-300 rounded text-sm text-right font-mono disabled:bg-neutral-100" />
                  </div>
                  <div class="sm:col-span-2">
                    <label class="block text-xs text-neutral-500 mb-1">{{ t('purchase_invoice.vat_allocation.deduction') }}</label>
                    <select v-model="a.vat_deduction" :disabled="a.usage_type === 'personal' || a.usage_type === 'mixed'"
                      class="w-full h-9 px-2 border border-neutral-300 rounded text-sm bg-surface disabled:bg-neutral-100">
                      <option value="full">{{ t('purchase_invoice.vat_deduction.full') }}</option>
                      <option value="none">{{ t('purchase_invoice.vat_deduction.none') }}</option>
                      <option value="proportional">{{ t('purchase_invoice.vat_deduction.proportional') }}</option>
                      <option value="reduced">{{ t('purchase_invoice.vat_deduction.reduced') }}</option>
                    </select>
                    <input v-if="a.vat_deduction === 'proportional'" v-model.number="a.vat_deduction_percent"
                      type="number" min="0" max="100" step="0.01" class="w-full h-8 mt-1 px-2 border border-neutral-300 rounded text-xs text-right" />
                  </div>
                  <div class="sm:col-span-2">
                    <label class="block text-xs text-neutral-500 mb-1">{{ t('purchase_invoice.vat_allocation.account') }}</label>
                    <input v-model="a.account_code" :list="`${pageId}-purchase-allocation-accounts`" type="text"
                      class="w-full h-9 px-2 border border-neutral-300 rounded text-sm font-mono" />
                  </div>
                  <div class="sm:col-span-1 text-right">
                    <button v-if="ai > 0" type="button" @click="removeVatAllocation(a)"
                      class="cursor-pointer h-9 px-2 text-danger-600 hover:text-danger-700" :title="t('common.delete')">×</button>
                  </div>
                  <div class="sm:col-span-12 flex flex-wrap justify-end gap-x-4 gap-y-1 text-[11px] text-neutral-500 border-t border-neutral-200 pt-2">
                    <span>{{ t('purchase_invoice.vat_recap.base') }} <strong class="font-mono font-normal text-neutral-600">{{ formatMoney(a.base_amount, currencyCode) }}</strong></span>
                    <span>{{ t('purchase_invoice.vat_recap.vat') }} <strong class="font-mono font-normal text-neutral-600">{{ formatMoney(a.vat_amount, currencyCode) }}</strong></span>
                  </div>
                </div>
              </div>
            </section>
            <datalist :id="`${pageId}-purchase-allocation-accounts`">
              <option v-for="a in accountingAccounts.filter(a => a.is_active)" :key="a.id" :value="a.account_code">{{ a.name }}</option>
            </datalist>
            <p v-if="allocationInvalid" class="text-xs text-danger-600">{{ t('purchase_invoice.vat_allocation.invalid') }}</p>
          </div>
        </div>
      </div>

      <!-- Box 3: Multi-currency platba (collapsible — komponenta má vlastní wrapper) -->
      <div v-if="form.currency_id" class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
        <PaymentCurrencyBlock
          :invoice-currency-id="form.currency_id"
          :invoice-currency="currencyCode"
          :total-with-vat="totals.with_vat"
          :currencies="currencies"
          :invoice-exchange-rate="form.exchange_rate"
          :payment-currency-id="form.payment_currency_id"
          :payment-exchange-rate="form.payment_exchange_rate"
          :paid-amount-payment-ccy="form.paid_amount_payment_ccy"
          :paid-amount-invoice-ccy="form.paid_amount_invoice_ccy"
          :exchange-diff-base="form.exchange_diff_base"
          @update:payment-currency-id="(v) => form.payment_currency_id = v"
          @update:payment-exchange-rate="(v) => form.payment_exchange_rate = v"
          @update:paid-amount-payment-ccy="(v) => form.paid_amount_payment_ccy = v"
          @update:paid-amount-invoice-ccy="(v) => form.paid_amount_invoice_ccy = v"
          @update:exchange-diff-base="(v) => form.exchange_diff_base = v"
        />
      </div>

      <!-- Box: Klasifikace (kategorie nákladů + VAT klasifikace pro DPHDP3) -->
      <div class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm">
        <h2 class="text-sm font-medium text-neutral-700 mb-3">{{ t('purchase_invoice.classification.title') }}</h2>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div>
            <label class="block text-xs text-neutral-500 mb-1">{{ t('purchase_invoice.classification.expense_category') }}</label>
            <div v-if="aiPostingSuggestion" class="mb-3 rounded-md border border-warning-200 bg-warning-50 p-3">
              <div class="flex flex-wrap items-center gap-2">
                <AutomationBadge variant="ai" />
                <ConfidenceLabel :confidence="aiPostingSuggestion.confidence" />
              </div>
              <p class="mt-2 text-sm text-neutral-700">{{ t('automation.ai.pf_suggestion', { md: aiPostingSuggestion.payload.debit_account_code }) }}</p>
              <p v-if="aiPostingSuggestion.reasoning" class="mt-1 text-xs text-neutral-600">{{ aiPostingSuggestion.reasoning }}</p>
              <div class="mt-3 flex flex-wrap gap-2">
                <button type="button" :disabled="aiSuggestionSaving" class="inline-flex h-9 cursor-pointer items-center gap-2 rounded-md bg-success-600 px-3 text-sm font-medium text-white hover:bg-success-700 disabled:opacity-50" @click="acceptAiPostingSuggestion">
                  <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.704 5.29a1 1 0 0 1 .006 1.414l-7.25 7.31a1 1 0 0 1-1.42.002l-3.75-3.75a1 1 0 1 1 1.414-1.414l3.04 3.04 6.542-6.596a1 1 0 0 1 1.418-.006Z" clip-rule="evenodd" /></svg>
                  {{ t('automation.ai.apply') }}
                </button>
                <button type="button" :disabled="aiSuggestionSaving" class="inline-flex h-9 cursor-pointer items-center gap-2 rounded-md border border-danger-500 px-3 text-sm font-medium text-danger-600 hover:bg-danger-50 disabled:opacity-50" @click="rejectAiPostingSuggestion">
                  <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path d="M4.293 4.293a1 1 0 0 1 1.414 0L10 8.586l4.293-4.293a1 1 0 1 1 1.414 1.414L11.414 10l4.293 4.293a1 1 0 0 1-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 0 1-1.414-1.414L8.586 10 4.293 5.707a1 1 0 0 1 0-1.414Z" /></svg>
                  {{ t('automation.ai.reject') }}
                </button>
              </div>
            </div>
            <select v-model="form.expense_category_id" class="w-full h-10 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
              <option :value="null">— {{ t('purchase_invoice.classification.no_category') }} —</option>
              <option v-for="c in expenseCategories" :key="c.id" :value="c.id">
                {{ c.label }} <span class="text-neutral-400">({{ c.code }})</span>
              </option>
            </select>
            <p class="text-xs text-neutral-500 mt-1">
              <RouterLink to="/admin/codebooks?scope=company&tab=expense_categories" class="text-primary-600 hover:underline">
                {{ t('purchase_invoice.classification.manage_categories') }}
              </RouterLink>
            </p>
          </div>
          <div v-if="!auth.isClientRole">
            <label class="block text-xs text-neutral-500 mb-1">{{ t('purchase_invoice.classification.project') }}</label>
            <select v-model="form.project_id" class="w-full h-10 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
              <option :value="null">— {{ t('purchase_invoice.classification.no_project') }} —</option>
              <option v-for="p in projects" :key="p.id" :value="p.id">
                {{ p.name }}<span v-if="p.project_number" class="text-neutral-400"> ({{ p.project_number }})</span>
              </option>
            </select>
            <p class="text-xs text-neutral-500 mt-1">{{ t('purchase_invoice.classification.project_hint') }}</p>
          </div>
          <div>
            <label class="block text-xs text-neutral-500 mb-1">{{ t('purchase_invoice.classification.vat_classification') }}</label>
            <select v-model="form.vat_classification_code" class="w-full h-10 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
              <option :value="null">— {{ t('purchase_invoice.classification.no_vat_class') }} —</option>
              <option v-for="vc in vatClassifications" :key="vc.id" :value="vc.code">
                {{ vc.code }} — {{ vc.label.length > 60 ? vc.label.slice(0, 60) + '…' : vc.label }}
              </option>
            </select>
            <p class="text-xs text-neutral-500 mt-1">{{ t('purchase_invoice.classification.vat_classification_hint') }}</p>
          </div>
          <div>
            <label class="block text-xs text-neutral-500 mb-1">{{ t('purchase_invoice.classification.vat_deduction') }}</label>
            <select v-model="form.vat_deduction" class="w-full h-10 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
              <option value="full">{{ t('purchase_invoice.vat_deduction.full') }}</option>
              <option value="none">{{ t('purchase_invoice.vat_deduction.none') }}</option>
              <option value="proportional">{{ t('purchase_invoice.vat_deduction.proportional') }}</option>
              <option value="reduced">{{ t('purchase_invoice.vat_deduction.reduced') }}</option>
            </select>
            <template v-if="form.vat_deduction === 'proportional'">
              <div class="mt-2 flex items-center gap-2">
                <input v-model.number="form.vat_deduction_percent" type="number" min="0" max="100" step="0.01"
                  class="w-24 h-10 px-3 border border-neutral-300 rounded-md bg-surface text-sm text-right" />
                <span class="text-sm text-neutral-600">% {{ t('purchase_invoice.vat_deduction_percent') }}</span>
              </div>
              <p class="text-xs text-neutral-500 mt-1">{{ t('purchase_invoice.vat_deduction_percent_hint') }}</p>
            </template>
            <p v-else-if="form.vat_deduction === 'reduced'" class="text-xs text-neutral-500 mt-1">
              {{ t('purchase_invoice.vat_deduction_reduced_hint') }}
            </p>
            <p v-else class="text-xs text-neutral-500 mt-1">{{ t('purchase_invoice.classification.vat_deduction_hint') }}</p>
          </div>
          <div>
            <label class="inline-flex items-center gap-2 text-sm mt-6" :title="t('purchase_invoice.classification.tax_deductible_hint')">
              <input type="checkbox" v-model="form.tax_deductible" class="rounded" />
              {{ t('purchase_invoice.classification.tax_deductible') }}
            </label>
            <p class="text-xs text-neutral-500 mt-1">{{ t('purchase_invoice.classification.tax_deductible_hint') }}</p>
          </div>
        </div>
      </div>

      <!-- Box 4: Poznámky -->
      <div class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm">
        <h2 class="text-sm font-medium text-neutral-700 mb-3">{{ t('purchase_invoice.fields.note_above_items') }} / {{ t('purchase_invoice.fields.note_below_items') }}</h2>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div>
            <label class="block text-xs text-neutral-500 mb-1">{{ t('purchase_invoice.fields.note_above_items') }}</label>
            <textarea v-model="form.note_above_items" rows="3" class="w-full px-3 py-2 border border-neutral-300 rounded-md text-sm resize-y"></textarea>
          </div>
          <div>
            <label class="block text-xs text-neutral-500 mb-1">{{ t('purchase_invoice.fields.note_below_items') }}</label>
            <textarea v-model="form.note_below_items" rows="3" class="w-full px-3 py-2 border border-neutral-300 rounded-md text-sm resize-y"></textarea>
          </div>
        </div>
      </div>

      <!-- Box 5: Platební účet dodavatele (pro „Zaplatit pomocí QR") -->
      <div class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm">
        <h2 class="text-sm font-medium text-neutral-700 mb-1">{{ t('purchase_invoice.qr.account_section') }}</h2>
        <p class="text-xs text-neutral-500 mb-3">{{ t('purchase_invoice.qr.account_section_hint') }}</p>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div>
            <label class="block text-xs text-neutral-500 mb-1">{{ t('purchase_invoice.qr.account') }}</label>
            <input v-model="form.payment_account_number" type="text" placeholder="19-2000145399"
              class="w-full px-3 h-9 border border-neutral-300 rounded-md text-sm font-mono" />
          </div>
          <div>
            <label class="block text-xs text-neutral-500 mb-1">{{ t('purchase_invoice.qr.bank_code') }}</label>
            <input v-model="form.payment_bank_code" type="text" placeholder="0800"
              class="w-full px-3 h-9 border border-neutral-300 rounded-md text-sm font-mono" />
          </div>
          <div>
            <label class="block text-xs text-neutral-500 mb-1">{{ t('purchase_invoice.qr.iban') }}</label>
            <input v-model="form.payment_iban" type="text" placeholder="CZ65 0800 0000 1920 0014 5399"
              class="w-full px-3 h-9 border border-neutral-300 rounded-md text-sm font-mono" />
          </div>
          <div>
            <label class="block text-xs text-neutral-500 mb-1">{{ t('purchase_invoice.qr.bic') }}</label>
            <input v-model="form.payment_bic" type="text" placeholder="GIBACZPX"
              class="w-full px-3 h-9 border border-neutral-300 rounded-md text-sm font-mono" />
          </div>
          <div>
            <label class="block text-xs text-neutral-500 mb-1">{{ t('purchase_invoice.qr.variable_symbol') }}</label>
            <input v-model="form.payment_variable_symbol" type="text"
              class="w-full px-3 h-9 border border-neutral-300 rounded-md text-sm font-mono" />
          </div>
          <div>
            <label class="block text-xs text-neutral-500 mb-1">{{ t('payment_method.label') }}</label>
            <select v-model="form.payment_method"
              class="w-full px-3 h-9 border border-neutral-300 rounded-md text-sm bg-surface">
              <option v-for="m in paymentMethodOptions" :key="m" :value="m">{{ t(`payment_method.${m}`) }}</option>
            </select>
            <p v-if="form.payment_method !== 'bank_transfer'" class="text-xs text-warning-600 mt-1">
              {{ t('payment_method.purchase_hint') }}
            </p>
          </div>
          <!-- Hotovostní vyrovnání (migrace 1327): pokladna k formě úhrady „Hotově".
               Nepovinné — bez pokladny se nic nezaúčtuje a faktura zůstane závazkem. -->
          <div v-if="showCashSettlement">
            <label class="block text-xs text-neutral-500 mb-1">{{ t('cash_settlement.register_label') }}</label>
            <select v-model="form.cash_register_id"
              class="w-full px-3 h-9 border border-neutral-300 rounded-md text-sm bg-surface">
              <option :value="null">{{ t('cash_settlement.none') }}</option>
              <option v-for="r in cashRegisters" :key="r.id" :value="r.id">
                {{ r.name }} ({{ r.account_code }})
              </option>
            </select>
            <p v-if="cashRegisters.length === 0" class="text-xs text-neutral-500 mt-1">
              {{ t('cash_settlement.no_registers') }}
            </p>
            <p v-else-if="form.cash_register_id" class="text-xs text-neutral-500 mt-1">
              {{ t('cash_settlement.purchase_hint') }}
            </p>
          </div>
        </div>
      </div>

      <!-- Submit bar — sticky bottom -->
      <div class="bg-surface border border-neutral-200 rounded-lg p-4 shadow-sm flex items-center justify-between gap-2">
        <RouterLink to="/purchase-invoices" class="px-4 h-10 inline-flex items-center text-sm text-neutral-600 hover:text-neutral-900 hover:bg-neutral-100 rounded-lg transition-colors">
          ← {{ t('purchase_invoice.actions.back') }}
        </RouterLink>
        <button type="submit" :disabled="submitting" class="cursor-pointer px-5 h-10 inline-flex items-center text-sm font-medium bg-primary-600 hover:bg-primary-700 text-white rounded-md disabled:opacity-50">
          {{ submitting ? '…' : t('purchase_invoice.actions.save') }}
        </button>
      </div>
    </form>

    <!-- Quick-add currency modal -->
    <div v-if="showAddCurrency" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="showAddCurrency = false">
      <div class="bg-surface rounded-lg shadow-xl max-w-sm w-full p-5 space-y-3">
        <h3 class="font-medium">{{ t('purchase_invoice.fields.currency_add_title') }}</h3>
        <p class="text-xs text-neutral-500">{{ t('purchase_invoice.fields.currency_add_iso_hint') }}</p>
        <input
          v-model="newCurrencyCode"
          type="text"
          maxlength="3"
          @keydown.enter="addCurrency"
          class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono uppercase"
          placeholder="USD"
          autofocus
        />
        <div class="rounded-md bg-warning-50 border border-warning-500/40 px-3 py-2 text-xs text-warning-600">
          {{ t('purchase_invoice.fields.currency_add_inactive_note') }}
        </div>
        <div class="flex items-center justify-end gap-2 pt-2">
          <button type="button" @click="showAddCurrency = false" class="cursor-pointer px-3 h-9 text-sm border border-neutral-300 rounded-md hover:bg-neutral-50">{{ t('common.cancel') }}</button>
          <button type="button" @click="addCurrency" :disabled="addingCurrency" class="cursor-pointer px-4 h-9 text-sm bg-primary-600 hover:bg-primary-700 text-white rounded-md disabled:opacity-50">
            {{ addingCurrency ? '…' : t('common.add') }}
          </button>
        </div>
        <p class="text-xs text-neutral-500 pt-1 border-t border-neutral-100">
          {{ t('purchase_invoice.fields.currency_add_advanced_hint') }}
          <RouterLink to="/admin/codebooks?scope=global" class="text-primary-700 hover:underline">{{ t('nav.codebooks_global') }}</RouterLink>.
        </p>
      </div>
    </div>

    <!-- Quick "New vendor" modal — pre-fills is_vendor=true, is_customer=false -->
    <ClientFormModal v-if="vendorModalOpen"
      :defaults="{ is_vendor: true, is_customer: false }"
      @created="onVendorCreated"
      @close="vendorModalOpen = false" />
  </div>
  <DocumentSidePreview
    v-if="pdfSideBySide"
    :src="pdfInlineUrl"
    :file-name="existingPdf?.name"
    @close="pdfPreviewOpen = false"
  />
  </div>
</template>

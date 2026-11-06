<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { useI18n } from 'vue-i18n'
import Modal from '@/components/ui/Modal.vue'
import ExtractionWarningText from '@/components/purchase/ExtractionWarningText.vue'
import ChartAccountSelect from '@/components/accounting/ChartAccountSelect.vue'
import { purchaseExpenseAccountOptions } from '@/utils/chartAccountOptions'
import { accountingApi, type ChartAccount } from '@/api/accounting'
import { btnFilled, btnFilledSm, btnOutline, btnOutlineSm, disabledTitle, BTN_DISABLED_NOTE, ICONS } from '@/components/ui/buttonStyles'
import {
  purchaseInvoicesApi,
  type ExpenseKind,
  type ExpenseKindSuggestion,
  type ExtractionExpenseKindProposal,
  type PurchaseInvoice,
} from '@/api/purchaseInvoices'
import { vatClassificationsApi, type VatClassification } from '@/api/vatClassifications'
import { apiErrorMessage } from '@/api/errors'
import { formatDate, formatMoney } from '@/composables/useFormat'
import { useToast } from '@/composables/useToast'
import { useAuthStore } from '@/stores/auth'
import { parseExtractionWarning, withoutExpenseKindSection } from '@/utils/extractionWarning'
import { missingDimensions, needsReview } from '@/utils/purchaseReview'
import { reverseChargeCodeForKind } from '@/utils/reverseChargeSupplyCode'
import { announceApprovalRequested } from '@/utils/purchaseApproval'
import { approvalErrorMessage } from '@/api/purchaseApprovals'
import DimensionFields from '@/components/dimensions/DimensionFields.vue'
import ItemDimensionsToggle from '@/components/dimensions/ItemDimensionsToggle.vue'
import { useDimensions } from '@/composables/useDimensions'
import {
  compactDimensions,
  dimensionsApi,
  type DimensionMap,
  type DimensionPrefillSource,
  type DimensionSplits,
} from '@/api/dimensions'
import DimensionChips from '@/components/dimensions/DimensionChips.vue'
import DocumentPreviewSplit from '@/components/documents/DocumentPreviewSplit.vue'
import { useSidePreview } from '@/composables/useSidePreview'

/**
 * Kontrola AI vytěžených přijatých faktur po importu — faktura po faktuře.
 *
 * Řádky, u kterých hlášení navrhuje druh nákladu a druh zatím není zvolený, jsou
 * orámované červeně a návrh jde převzít jedním klikem. Uložení jde přes úzký
 * endpoint `expense-kinds`, takže funguje i u dokladu, který import rovnou označil
 * jako zaplacený (dřív jen přes vynucenou úpravu).
 *
 * Při zapnutých dimenzích má okno i sekci Dimenze: hlavička (a volitelně položky)
 * předvyplněná výchozími hodnotami dodavatele a zakázky, prázdné typy návrhem
 * z posledního dokladu dodavatele. Návrh je vyznačený; uloží se až „Uložit a další"
 * jako explicitní dimenze dokladu. Které doklady okno ukáže, rozhoduje backend
 * (`review`): hlášení vytěžení i chybějící povinná dimenze.
 */
const props = withDefaults(defineProps<{
  invoiceIds: number[]
  /** Přeskočí faktury bez hlášení (po hromadném importu jich je většina v pořádku). */
  onlyFlagged?: boolean
  /** Když není co kontrolovat, zavřít bez oznámení (rodič pokračuje vlastním krokem). */
  silentWhenEmpty?: boolean
}>(), { onlyFlagged: true, silentWhenEmpty: false })

const emit = defineEmits<{
  /** navigated = uživatel odešel na detail dokladu, rodič už nemá kam přesměrovávat. */
  (e: 'close', navigated?: boolean): void
  (e: 'updated', invoice: PurchaseInvoice): void
}>()

const { t } = useI18n()
const toast = useToast()
const auth = useAuthStore()

const EXPENSE_KINDS: ExpenseKind[] = ['service', 'material', 'small_asset', 'small_intangible', 'fixed_asset']

const queue = ref<PurchaseInvoice[]>([])
const index = ref(0)
const loading = ref(true)
const saving = ref(false)
const kinds = reactive<Record<number, ExpenseKind | null>>({})
const accounts = reactive<Record<number, string | null>>({})
/** Členění DPH položek (issue #150): AI určí zboží/službu jednou za doklad, u přenesené
 * povinnosti ho tu jde opravit po položkách. */
const vatCodes = reactive<Record<number, string | null>>({})
const vatClassifications = ref<VatClassification[]>([])
const showVatCodes = computed(() => !!invoice.value?.reverse_charge && vatClassifications.value.length > 0)
const accountingAccounts = ref<ChartAccount[]>([])
const expenseAccounts = computed(() => purchaseExpenseAccountOptions(accountingAccounts.value))
const assetAccounts = computed(() => purchaseExpenseAccountOptions(accountingAccounts.value, 'fixed_asset'))
const accountsForExpenseKind = (kind?: ExpenseKind | null) => kind === 'fixed_asset' ? assetAccounts.value : expenseAccounts.value
const expenseSuggestions = ref<Record<string, ExpenseKindSuggestion>>({})
let expenseSeq = 0

const invoice = computed<PurchaseInvoice | null>(() => queue.value[index.value] ?? null)
const isLast = computed(() => index.value >= queue.value.length - 1)

// ── Náhled originálu ────────────────────────────────────────────────────────
// Volba je lepkavá a výchozí stav otevřený: kontrola vytěžení je porovnání s dokladem.
const preview = useSidePreview('purchase_invoices', 'review_preview_open', { defaultOpen: true })
/** Detail dokladu už originál ukazuje vedle sebe a Modal od něj odsadil backdrop. */
const pageHasPreview = ref(false)
const previewShown = computed(() => preview.open.value && !pageHasPreview.value)
// Šířka okna se řídí volbou, ne změřeným výsledkem: v úzkém okně modál stejně nepřeroste
// okno, a kdyby šířka modálu závisela na tom, co se do něj vejde, šlo by o kruh.
const previewWantsSide = computed(() => previewShown.value && preview.placement.value === 'side')
const previewSrc = computed(() => invoice.value?.pdf_path ? purchaseInvoicesApi.pdfUrl(invoice.value.id, true) : null)

const proposals = computed(() => {
  const map = new Map<number, ExtractionExpenseKindProposal & { expense_account_code?: string | null }>()
  for (const p of invoice.value?.extraction_review?.expense_kinds ?? []) map.set(p.order_index, p)
  for (const it of items.value) {
    const suggestion = expenseSuggestions.value[String(it.id)]
    if (suggestion && (suggestion.source === 'rule' || !map.has(it.order_index))) {
      map.set(it.order_index, { order_index: it.order_index, kind: suggestion.expense_kind,
        confidence: suggestion.confidence, reason: suggestion.reason, expense_account_code: suggestion.expense_account_code })
    }
  }
  return map
})

const items = computed(() => (invoice.value?.items ?? []).filter((it) => it.id !== undefined))
const otherWarning = computed(() => withoutExpenseKindSection(invoice.value?.extraction_warning))

function proposalFor(orderIndex: number) {
  return proposals.value.get(orderIndex)
}
function needsAttention(it: { id?: number; order_index: number }): boolean {
  return !!proposalFor(it.order_index) && !kinds[it.id as number]
}
const pendingProposals = computed(() => items.value.filter((it) => {
  const p = proposalFor(it.order_index)
  return p && proposalDiffers(it)
}))
function proposalDiffers(it: { id?: number; order_index: number }): boolean {
  const p = proposalFor(it.order_index)
  return !!p && (kinds[it.id as number] !== p.kind
    || (!!p.expense_account_code && accounts[it.id as number] !== p.expense_account_code))
}
const attentionCount = computed(() => items.value.filter(needsAttention).length)

const readOnlyReason = computed<string | null>(() => {
  const inv = invoice.value
  if (!inv) return null
  if (inv.status === 'cancelled') return t('purchase_invoice.extraction_review.readonly_cancelled')
  // `locked.is_locked` znamená „zamčeno pro klienta" (účetní spravuje, datum…) —
  // účetní blokuje jen uzavřené období, stejně jako backend (GuardsDocumentLock).
  const closedPeriod = ['closed', 'approved'].includes(inv.locked?.period_status ?? '')
  if (closedPeriod || (auth.isClientRole && inv.locked?.is_locked)) return t('purchase_invoice.extraction_review.readonly_locked')
  if (inv.status !== 'draft' && auth.isClientRole) return t('purchase_invoice.extraction_review.readonly_client')
  return null
})

function resetKinds(): void {
  for (const k of Object.keys(kinds)) delete kinds[Number(k)]
  for (const k of Object.keys(accounts)) delete accounts[Number(k)]
  for (const k of Object.keys(vatCodes)) delete vatCodes[Number(k)]
  for (const it of items.value) {
    kinds[it.id as number] = it.expense_kind ?? null
    accounts[it.id as number] = it.expense_account_code ?? null
    vatCodes[it.id as number] = it.vat_classification_code ?? null
  }
}

/** Změna druhu nákladu přepne u samovyměření kód zboží ↔ služba (23 ↔ 24e, 25 ↔ 24). */
function setKind(id: number, kind: ExpenseKind | null): void {
  kinds[id] = kind
  if (showVatCodes.value) vatCodes[id] = reverseChargeCodeForKind(vatCodes[id], kind)
}

function applyProposal(it: { id?: number; order_index: number }): void {
  const p = proposalFor(it.order_index)
  if (p) {
    setKind(it.id as number, p.kind)
    if (p.expense_account_code) accounts[it.id as number] = p.expense_account_code
  }
}
function applyAll(): void {
  for (const it of pendingProposals.value) applyProposal(it)
}

function goTo(i: number): void {
  index.value = i
  resetKinds()
  const inv = invoice.value
  if (inv) {
    void loadDimensions(inv)
    void loadExpenseSuggestions(inv)
  }
}

async function loadExpenseSuggestions(inv: PurchaseInvoice): Promise<void> {
  const seq = ++expenseSeq
  expenseSuggestions.value = {}
  try {
    const result = await purchaseInvoicesApi.expenseSuggestions(inv.id)
    if (seq === expenseSeq) expenseSuggestions.value = result.items
  } catch {
    if (seq === expenseSeq) expenseSuggestions.value = {}
  }
}

// ── Dimenze dokladu ─────────────────────────────────────────────────────────
const dims = useDimensions()
const dimsShown = computed(() => dims.enabled.value && dims.documentTypes.value.length > 0)
const dimsEditable = computed(() => dimsShown.value && dims.canEdit.value && !readOnlyReason.value)
const dimHeader = ref<DimensionMap>({})
const dimLoadedHeader = ref<Record<number, number>>({})
/** Dimenze položek podle id položky (pořadí pro backend se dopočítá při uložení). */
const dimItems = reactive<Record<number, DimensionMap>>({})
const dimLoadedItems = ref<Record<number, Record<number, number>>>({})
const openItemDims = reactive(new Set<number>())
/** Typ → hodnota a zdroj, které doplnilo předvyplnění (návrh, dokud ho uživatel nezmění). */
const suggestions = ref<Record<number, { value: number; source: DimensionPrefillSource }>>({})
/** Rozpad hlavičky (0) a položek (pořadí od 1) mezi víc hodnot typu. V okně jen ke čtení:
 * typ s rozpadem se nepředvyplňuje ani nenabízí, takže ho uložení ponechá beze změny. */
const dimSplits = ref<Record<number, DimensionSplits>>({})
let dimSeq = 0

/** Položky v pořadí, v jakém je backend čísluje (order_index, od 1). */
const orderedItems = computed(() => [...(invoice.value?.items ?? [])]
  .sort((a, b) => (a.order_index - b.order_index) || ((a.id ?? 0) - (b.id ?? 0))))

function sameMap(a: Record<number, number>, b: Record<number, number>): boolean {
  const ka = Object.keys(a)
  return ka.length === Object.keys(b).length && ka.every(k => a[Number(k)] === b[Number(k)])
}

async function loadDimensions(inv: PurchaseInvoice): Promise<void> {
  const seq = ++dimSeq
  dimHeader.value = {}
  dimLoadedHeader.value = {}
  dimLoadedItems.value = {}
  suggestions.value = {}
  dimSplits.value = {}
  openItemDims.clear()
  for (const k of Object.keys(dimItems)) delete dimItems[Number(k)]
  if (!dims.enabled.value) return
  try {
    await dims.load()
    const [doc, prefill] = await Promise.all([
      dimensionsApi.getDocument('purchase-invoices', inv.id),
      dimsEditable.value
        ? dimensionsApi.prefill({
          client_id: inv.vendor_id,
          project_id: inv.project_id,
          history: 1,
          exclude_purchase_invoice_id: inv.id,
        }).catch(() => null)
        : Promise.resolve(null),
    ])
    if (seq !== dimSeq) return
    const header: DimensionMap = { ...doc.header }
    dimSplits.value = doc.splits ?? {}
    const headerSplit = dimSplits.value[0] ?? {}
    dimLoadedHeader.value = compactDimensions(doc.header)
    const loadedItems: Record<number, Record<number, number>> = {}
    orderedItems.value.forEach((it, i) => {
      if (it.id === undefined) return
      const map = compactDimensions(doc.items[i + 1])
      dimItems[it.id] = { ...map }
      loadedItems[it.id] = map
      if (Object.keys(map).length) openItemDims.add(it.id)
    })
    dimLoadedItems.value = loadedItems
    const next: Record<number, { value: number; source: DimensionPrefillSource }> = {}
    for (const [typeId, valueId] of Object.entries(prefill?.header ?? {})) {
      const key = Number(typeId)
      if (!header[key] && valueId && !headerSplit[key]?.length) {
        header[key] = valueId
        next[key] = { value: valueId, source: prefill?.sources[key] ?? 'client' }
      }
    }
    dimHeader.value = header
    suggestions.value = next
  } catch {
    // Doklad jde zkontrolovat i bez dimenzí (např. chybí právo na účetnictví).
  }
}

/** Návrhy, které uživatel nezměnil — vyznačené v okně. */
const activeSuggestions = computed(() => Object.entries(suggestions.value)
  .filter(([typeId, s]) => dimHeader.value[Number(typeId)] === s.value)
  .map(([typeId, s]) => ({ typeId: Number(typeId), ...s })))

const missingRequired = computed(() => missingDimensions(invoice.value)
  .filter(m => !dimHeader.value[m.type_id] && !dimSplits.value[0]?.[m.type_id]?.length))

/** Rozpad hlavičky (typ → podíly), zobrazený jen ke čtení. */
const headerSplits = computed<DimensionSplits>(() => dimSplits.value[0] ?? {})
const hasHeaderSplits = computed(() => Object.values(headerSplits.value).some(s => (s?.length ?? 0) > 0))

/** Typy bez rozpadu v dané části dokladu (0 = hlavička, jinak pořadí položky od 1). */
function editableTypes(itemNo: number) {
  const split = dimSplits.value[itemNo] ?? {}
  return dims.documentTypes.value.filter(ty => !(split[ty.id]?.length))
}
function itemNoOf(id: number | undefined): number {
  return orderedItems.value.findIndex(it => it.id === id) + 1
}

function typeName(typeId: number): string {
  return dims.typeById.value.get(typeId)?.name ?? `#${typeId}`
}

function itemDims(id: number | undefined): DimensionMap {
  return id === undefined ? {} : (dimItems[id] ?? {})
}
function setItemDims(id: number | undefined, map: DimensionMap): void {
  if (id !== undefined) dimItems[id] = map
}
function toggleItemDims(id: number | undefined): void {
  if (id === undefined) return
  if (openItemDims.has(id)) {
    openItemDims.delete(id)
    dimItems[id] = {}
  } else {
    openItemDims.add(id)
  }
}

const itemDimsChanged = computed(() => orderedItems.value.some(it => it.id !== undefined
  && !sameMap(compactDimensions(dimItems[it.id]), dimLoadedItems.value[it.id] ?? {})))
const dimsChanged = computed(() => !sameMap(compactDimensions(dimHeader.value), dimLoadedHeader.value) || itemDimsChanged.value)

async function saveDimensions(inv: PurchaseInvoice): Promise<boolean> {
  if (!dimsEditable.value || !dimsChanged.value) return false
  const items: Record<number, DimensionMap> = {}
  orderedItems.value.forEach((it, i) => {
    const map = compactDimensions(itemDims(it.id))
    if (Object.keys(map).length) items[i + 1] = map
  })
  const result = await dimensionsApi.saveDocument('purchase-invoices', inv.id, {
    header: dimHeader.value,
    ...(itemDimsChanged.value ? { items } : {}),
  })
  if (result.restamp.needs_repost) toast.warning(t('dimensions.needs_repost'))
  return true
}

async function save(): Promise<void> {
  const inv = invoice.value
  if (!inv || saving.value) return
  saving.value = true
  try {
    let updated: PurchaseInvoice = inv
    const vatChanged = (it: { id?: number; vat_classification_code?: string | null }) =>
      !!vatCodes[it.id as number] && (it.vat_classification_code ?? null) !== vatCodes[it.id as number]
    const changed = items.value
      .filter((it) => (it.expense_kind ?? null) !== kinds[it.id as number]
        || (it.expense_account_code ?? null) !== (accounts[it.id as number]?.trim() || null)
        || vatChanged(it))
      .map((it) => ({ id: it.id as number, expense_kind: kinds[it.id as number] ?? null,
        ...((it.expense_account_code ?? null) !== (accounts[it.id as number]?.trim() || null)
          ? { expense_account_code: accounts[it.id as number]?.trim() || null } : {}),
        ...(vatChanged(it) ? { vat_classification_code: vatCodes[it.id as number] as string } : {}),
      }))
    // Odrážky hlášení u řádků, které teď druh mají, odebere backend sám.
    if (changed.length && !readOnlyReason.value) {
      const res = await purchaseInvoicesApi.setExpenseKinds(inv.id, changed)
      if (res._repost) toast.info(t('purchase_invoice.extraction_review.reposted'))
      updated = res
    }
    const dimsSaved = await saveDimensions(inv)
    // Doklad i s přepočteným `review` — rodič (seznam, detail) nesmí ukazovat zastaralé upozornění.
    if (changed.length || dimsSaved) updated = await purchaseInvoicesApi.get(inv.id).catch(() => updated)
    // Uložením uživatel potvrzuje, že doklad zkontroloval: body hlášení zobrazené v okně
    // jsou vyřízené. Návrhy druhu nákladu bez zvoleného druhu zůstávají.
    if (!readOnlyReason.value) {
      for (const section of parseExtractionWarning(withoutExpenseKindSection(updated.extraction_warning))) {
        updated = await purchaseInvoicesApi.dismissExtractionWarning(inv.id, section.raw)
      }
    }
    queue.value[index.value] = updated
    emit('updated', updated)
    next()
  } catch (e) {
    toast.error(apiErrorMessage(e))
  } finally {
    saving.value = false
  }
}

// Podle PDF zaplacený doklad zůstal po importu konceptem (aby šel upravit) — úhradu
// potvrdí uživatel: koncept → přijato → uhrazeno přes běžné přechody stavu.
const canConfirmPaid = computed(() => auth.canWrite('purchase_invoices.transition') && invoice.value?.status === 'draft'
  && !!invoice.value?.extraction_review?.paid_per_document && !readOnlyReason.value)
const confirmingPaid = ref(false)
async function confirmPaid(): Promise<void> {
  const inv = invoice.value
  if (!inv || !canConfirmPaid.value || confirmingPaid.value) return
  confirmingPaid.value = true
  try {
    const received = await purchaseInvoicesApi.transition(inv.id, 'received')
    // Doklad vyžaduje schválení: zůstal konceptem a čeká na schvalovatele, úhrada se neprovádí.
    if (announceApprovalRequested(received, toast, t)) {
      const pending = await purchaseInvoicesApi.get(inv.id).catch(() => received)
      queue.value[index.value] = pending
      emit('updated', pending)
      return
    }
    const updated = await purchaseInvoicesApi.transition(inv.id, 'paid', inv.issue_date ?? undefined)
    queue.value[index.value] = updated
    emit('updated', updated)
  } catch (e) {
    toast.error(approvalErrorMessage(e, t))
    queue.value[index.value] = await purchaseInvoicesApi.get(inv.id).catch(() => inv)
  } finally {
    confirmingPaid.value = false
  }
}

const resolving = ref(false)
async function resolveSection(section: string): Promise<void> {
  const inv = invoice.value
  if (!inv || resolving.value) return
  resolving.value = true
  try {
    const updated = await purchaseInvoicesApi.dismissExtractionWarning(inv.id, section)
    queue.value[index.value] = updated
    emit('updated', updated)
  } catch (e) {
    toast.error(apiErrorMessage(e))
  } finally {
    resolving.value = false
  }
}

function next(): void {
  if (isLast.value) {
    toast.success(t('purchase_invoice.extraction_review.done'))
    emit('close')
    return
  }
  goTo(index.value + 1)
}

onMounted(async () => {
  pageHasPreview.value = !!document.querySelector('[data-side-preview]')
  if (!auth.isClientRole) {
    void accountingApi.listAccounts().then(result => { accountingAccounts.value = result }).catch(() => {})
  }
  void vatClassificationsApi.list('purchase').then(result => { vatClassifications.value = result }).catch(() => {})
  try {
    const loaded = (await Promise.all(props.invoiceIds.map((id) => purchaseInvoicesApi.get(id).catch(() => null))))
      .filter((inv): inv is PurchaseInvoice => inv !== null)
    queue.value = loaded.filter((inv) => !props.onlyFlagged || needsReview(inv))
  } finally {
    loading.value = false
  }
  if (!queue.value.length) {
    if (!props.silentWhenEmpty) toast.success(t('purchase_invoice.extraction_review.nothing'))
    emit('close')
    return
  }
  goTo(0)
})
</script>

<template>
  <Modal v-if="!loading && invoice" :title="t('purchase_invoice.extraction_review.title')"
    :width-class="previewWantsSide ? 'max-w-[112rem]' : 'max-w-5xl'" @close="emit('close')">
    <DocumentPreviewSplit :ctrl="preview" :show="previewShown" :src="previewSrc" :file-name="invoice.pdf_original_name"
      embedded form-class="space-y-4" side-class="sticky top-0 h-[calc(100dvh-13rem)] min-h-[24rem]">
      <!-- Hlavička dokladu -->
      <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
          <div class="font-medium text-neutral-900 truncate">{{ invoice.vendor_company_name }}</div>
          <div class="text-sm text-neutral-500">
            {{ invoice.vendor_invoice_number || invoice.varsymbol }} · {{ formatDate(invoice.issue_date) }}
            · {{ t(`purchase_invoice.status.${invoice.status}`) }}
          </div>
        </div>
        <div class="text-right">
          <div class="font-mono font-semibold">{{ formatMoney(invoice.total_with_vat, invoice.currency) }}</div>
          <RouterLink :to="`/purchase-invoices/${invoice.id}`" class="text-sm text-primary-600 hover:underline" @click="emit('close', true)">
            {{ t('purchase_invoice.extraction_review.open_invoice') }}
          </RouterLink>
        </div>
      </div>

      <!-- Ostatní části hlášení (sekce o druhu nákladu je níž jako seznam) -->
      <div v-if="otherWarning" class="p-3 bg-warning-50 border border-warning-500/40 rounded-md text-sm text-warning-700">
        <ExtractionWarningText :warning="otherWarning" :dismissible="!readOnlyReason" :busy="resolving" @dismiss="resolveSection" />
        <button v-if="canConfirmPaid" type="button" :class="btnFilledSm('success')" class="mt-2" :disabled="confirmingPaid" @click="confirmPaid">
          <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" /></svg>
          {{ t('purchase_invoice.extraction_review.confirm_paid') }}
        </button>
      </div>

      <!-- Dimenze dokladu (hlavička; položky rozbalením u řádku níž) -->
      <div v-if="dimsShown" class="rounded-md border p-3 space-y-2" data-test="review-dimensions"
        :class="missingRequired.length ? 'border-danger-500 ring-2 ring-danger-500/30' : 'border-neutral-200'">
        <div class="flex flex-wrap items-baseline justify-between gap-2">
          <h3 class="text-sm font-medium text-neutral-700">{{ t('purchase_invoice.extraction_review.dimensions.title') }}</h3>
          <span class="text-xs text-neutral-500">{{ t('purchase_invoice.extraction_review.dimensions.items_hint') }}</span>
        </div>
        <DimensionFields v-model="dimHeader" compact :types="editableTypes(0)" :disabled="!dimsEditable" />
        <p v-if="hasHeaderSplits" class="text-xs text-neutral-600 flex flex-wrap items-center gap-1.5" data-test="review-dimension-splits">
          <DimensionChips :dimensions="{}" :splits="headerSplits" />
          <RouterLink :to="`/purchase-invoices/${invoice.id}`" class="text-primary-600 hover:underline" @click="emit('close', true)">
            {{ t('purchase_invoice.extraction_review.dimensions.split_in_detail') }}
          </RouterLink>
        </p>
        <p v-for="m in missingRequired" :key="`missing-${m.type_id}`" class="text-xs text-danger-600" data-test="review-dimension-missing">
          {{ t('purchase_invoice.extraction_review.dimensions.required', { type: m.type_name, accounts: m.account_codes.join(', ') }) }}
        </p>
        <p v-for="s in activeSuggestions" :key="`suggest-${s.typeId}`" class="text-xs text-primary-700 flex flex-wrap items-center gap-1.5" data-test="review-dimension-suggestion">
          <span class="px-1.5 py-0.5 rounded bg-primary-50 font-medium">{{ t('purchase_invoice.extraction_review.dimensions.suggestion') }}</span>
          <span>{{ typeName(s.typeId) }}: {{ dims.valueLabel(s.value) }}</span>
          <span class="text-neutral-500">({{ t(`purchase_invoice.extraction_review.dimensions.source.${s.source}`) }})</span>
        </p>
      </div>

      <!-- Druh nákladu po položkách -->
      <div>
        <div class="flex flex-wrap items-center justify-between gap-2 mb-2">
          <h3 class="text-sm font-medium text-neutral-700">
            {{ t('purchase_invoice.extraction_review.items_title') }}
            <span v-if="attentionCount" class="ml-1 text-danger-600">
              ({{ t('purchase_invoice.extraction_review.attention_count', { count: attentionCount }) }})
            </span>
          </h3>
          <button v-if="pendingProposals.length && !readOnlyReason" type="button" :class="btnOutlineSm('primary')" @click="applyAll">
            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" /></svg>
            {{ t('purchase_invoice.extraction_review.apply_all', { count: pendingProposals.length }) }}
          </button>
        </div>

        <ul class="space-y-2">
          <li v-for="it in items" :key="it.id"
            class="rounded-md border p-3 grid grid-cols-1 gap-2 sm:grid-cols-[minmax(0,3fr)_minmax(0,2fr)] sm:gap-x-4"
            :class="needsAttention(it) ? 'border-danger-500 ring-2 ring-danger-500/30 bg-danger-50/40' : 'border-neutral-200'">
            <div class="min-w-0">
              <div class="text-sm text-neutral-900 break-words">
                <span class="text-neutral-400 font-mono mr-1">{{ it.order_index + 1 }}.</span>{{ it.description }}
              </div>
              <div class="text-xs text-neutral-500 font-mono">{{ formatMoney(it.total_without_vat ?? it.quantity * it.unit_price_without_vat, invoice.currency) }}</div>
              <div v-if="proposalFor(it.order_index)" class="mt-1 flex flex-wrap items-center gap-2 text-xs">
                <span class="text-neutral-600">
                  {{ t('purchase_invoice.extraction_review.proposal', {
                    kind: t(`purchase_invoice.expense_kind.${proposalFor(it.order_index)!.kind}`),
                    pct: Math.round(proposalFor(it.order_index)!.confidence * 100),
                  }) }}
                  <span v-if="proposalFor(it.order_index)!.expense_account_code" class="font-mono"> · {{ proposalFor(it.order_index)!.expense_account_code }}</span>
                  <span class="text-neutral-400">— {{ proposalFor(it.order_index)!.reason }}</span>
                </span>
                <button v-if="proposalDiffers(it) && !readOnlyReason" type="button"
                  :class="btnOutlineSm('primary')" @click="applyProposal(it)">
                  {{ t('purchase_invoice.extraction_review.apply') }}
                </button>
              </div>
            </div>
            <div class="min-w-0 flex flex-col gap-2">
              <div class="flex items-center gap-2">
                <select :value="kinds[it.id as number] ?? ''" :disabled="!!readOnlyReason" data-test="review-item-expense-kind"
                  class="min-w-0 flex-1 rounded-md border px-2 py-1.5 text-sm bg-surface disabled:opacity-60"
                  :class="needsAttention(it) ? 'border-danger-500' : 'border-neutral-300'"
                  @change="setKind(it.id as number, (($event.target as HTMLSelectElement).value || null) as ExpenseKind | null)">
                  <option value="">{{ t('purchase_invoice.extraction_review.kind_unset') }}</option>
                  <option v-for="k in EXPENSE_KINDS" :key="k" :value="k">{{ t(`purchase_invoice.expense_kind.${k}`) }}</option>
                </select>
                <ItemDimensionsToggle v-if="dimsShown" class="shrink-0"
                  :open="openItemDims.has(it.id as number)" :filled="Object.values(itemDims(it.id)).some(v => !!v)"
                  :disabled="!dimsEditable" @toggle="toggleItemDims(it.id)" />
              </div>
              <ChartAccountSelect v-if="accountsForExpenseKind(kinds[it.id as number]).length && !auth.isClientRole" v-model="accounts[it.id as number]"
                class="w-full" compact :accounts="accountsForExpenseKind(kinds[it.id as number])" :disabled="!!readOnlyReason"
                :aria-label="t('purchase_invoice.items.expense_account')" />
              <select v-if="showVatCodes" v-model="vatCodes[it.id as number]" :disabled="!!readOnlyReason"
                :aria-label="t('purchase_invoice.items.vat_classification')" :title="t('purchase_invoice.items.vat_classification')"
                data-test="review-item-vat-classification"
                class="w-full rounded-md border border-neutral-300 px-2 py-1.5 text-sm bg-surface disabled:opacity-60">
                <option v-if="!vatCodes[it.id as number]" :value="null" disabled>{{ t('purchase_invoice.items.vat_classification') }}</option>
                <option v-for="vc in vatClassifications" :key="vc.id" :value="vc.code">
                  {{ vc.code }} - {{ vc.label.length > 40 ? vc.label.slice(0, 40) + '…' : vc.label }}
                </option>
              </select>
            </div>
            <div v-if="dimsShown && openItemDims.has(it.id as number)" class="sm:col-span-2" data-test="review-item-dimensions">
              <DimensionFields compact :model-value="itemDims(it.id)" :types="editableTypes(itemNoOf(it.id))" :disabled="!dimsEditable"
                @update:model-value="setItemDims(it.id, $event)" />
            </div>
          </li>
        </ul>
        <p v-if="readOnlyReason" :class="BTN_DISABLED_NOTE" class="mt-2">{{ readOnlyReason }}</p>
      </div>
    </DocumentPreviewSplit>

    <template #footer>
      <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex flex-wrap items-center gap-4 text-sm text-neutral-600">
          <span>{{ t('purchase_invoice.extraction_review.counter', { n: index + 1, total: queue.length }) }}</span>
          <button v-if="!pageHasPreview" type="button" :class="btnOutlineSm('neutral')" class="whitespace-nowrap"
            data-test="review-preview-toggle" :aria-pressed="preview.open.value" @click="preview.toggle()">
            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" :d="preview.open.value ? ICONS.eyeOff : ICONS.eye" />
            </svg>
            {{ preview.open.value ? t('purchase_invoice.pdf.hide') : t('purchase_invoice.pdf.show') }}
          </button>
        </div>
        <div class="flex flex-wrap gap-2">
          <button type="button" :class="btnOutline('neutral')" class="whitespace-nowrap" :disabled="index === 0 || saving" @click="goTo(index - 1)">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" /></svg>
            {{ t('purchase_invoice.extraction_review.back') }}
          </button>
          <button type="button" :class="btnOutline('neutral')" class="whitespace-nowrap" :disabled="saving" @click="next">
            {{ isLast ? t('purchase_invoice.extraction_review.close') : t('purchase_invoice.extraction_review.skip') }}
          </button>
          <button type="button" :class="btnFilled('success')" class="whitespace-nowrap"
            :disabled="saving || !!readOnlyReason" :title="disabledTitle(!!readOnlyReason, readOnlyReason)" @click="save">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" /></svg>
            {{ isLast ? t('purchase_invoice.extraction_review.save_finish') : t('purchase_invoice.extraction_review.save_next') }}
          </button>
        </div>
      </div>
    </template>
  </Modal>
</template>

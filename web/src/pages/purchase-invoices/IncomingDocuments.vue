<script setup lang="ts">
import { computed, onMounted, onBeforeUnmount, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import {
  purchaseInvoiceSubmissionsApi,
  type PurchaseInvoiceSubmission,
  type PurchaseInvoiceSubmissionKindHint,
  type PurchaseInvoiceSubmissionStatus,
  type SubmissionMoveTargets,
  type SubmissionUploadResult,
} from '@/api/purchaseInvoiceSubmissions'
import { useSupplierStore } from '@/stores/supplier'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { apiErrorMessage } from '@/api/errors'
import { btnFilled, btnOutline, ICONS } from '@/components/ui/buttonStyles'
import EmptyState from '@/components/ui/EmptyState.vue'
import PdfDropzone from '@/components/purchase/PdfDropzone.vue'
import CapturePhotoButton from '@/components/upload/CapturePhotoButton.vue'
import ExtractionReviewModal from '@/components/purchase/ExtractionReviewModal.vue'
import DimensionFields from '@/components/dimensions/DimensionFields.vue'
import DimensionChips from '@/components/dimensions/DimensionChips.vue'
import DocumentPreviewSplit from '@/components/documents/DocumentPreviewSplit.vue'
import { useDimensions } from '@/composables/useDimensions'
import { useSidePreview } from '@/composables/useSidePreview'
import type { DimensionMap } from '@/api/dimensions'

const { t } = useI18n()
const router = useRouter()
const supplierStore = useSupplierStore()
const auth = useAuthStore()
const toast = useToast()
// Fronta je třídění podle obsahu dokladu, proto je náhled ve výchozím stavu otevřený.
const preview = useSidePreview('purchase_invoices', 'inbox_preview_open', { defaultOpen: true })

const items = ref<PurchaseInvoiceSubmission[]>([])
const selected = ref<PurchaseInvoiceSubmission | null>(null)
const status = ref<PurchaseInvoiceSubmissionStatus | ''>('submitted')
const reason = ref('')
const loading = ref(true)
const acting = ref(false)
const error = ref('')
const files = ref<File[]>([])
const note = ref('')
const kindHint = ref<PurchaseInvoiceSubmissionKindHint | null>(null)
/** Středisko (a další dimenze) účtenky — propíše se do hlavičky vytěženého dokladu. */
const uploadDims = ref<DimensionMap>({})
const dims = useDimensions()
const uploading = ref(false)
const uploadProgress = ref({ done: 0, total: 0 })
const fileInput = ref<HTMLInputElement | null>(null)
let context = 0
let loadRequest = 0

/** Výběr najednou; na server jde každý soubor zvlášť (limit požadavku i PHP max_file_uploads je 20). */
const MAX_FILES = 5000

const ALLOWED_FILES = '.pdf,.jpg,.jpeg,.png,.isdoc,.xml,.isdocx,application/pdf,image/jpeg,image/png'
/** Server bere podle `documents.max_file_bytes`, výchozí 50 MiB; tady jen UX pojistka. */
const MAX_FILE_BYTES = 50 * 1024 * 1024

const canWrite = computed(() => auth.canWrite('documents.inbox'))
const canCreateInvoice = computed(() => auth.canWrite('purchase_invoices.create'))
const canDelete = computed(() => auth.canWrite('documents.inbox.delete'))
/** U dokladu, který si účetní nahrála sama, není komu psát „co doplnit". */
const hasClientToInform = computed(() => selected.value !== null
  && selected.value.submitted_via !== 'staff' && selected.value.submitted_via !== 'email')

// ── Přesun do jiné firmy ────────────────────────────────────────────────────
// Nabízí se jen uživateli s víc firmami. Cílové firmy a návrh podle odběratele
// počítá server (oprávnění v cílové firmě, ISDOC, text PDF, vytěžený odběratel).
const hasOtherCompanies = computed(() => supplierStore.availableSuppliers.length > 1)
function canMove(item: PurchaseInvoiceSubmission): boolean {
  return canWrite.value && hasOtherCompanies.value
    && ['submitted', 'needs_information', 'rejected'].includes(item.status)
    && item.purchase_invoice_id === null
    && (item.submitted_via === 'staff' || item.submitted_via === 'email')
}
/** Nahrání dávky za víc firem: každý soubor do firmy podle odběratele. */
const distributeUpload = ref(false)
const moveOpen = ref(false)
const moveLoading = ref(false)
const moveTargets = ref<SubmissionMoveTargets | null>(null)
const moveTargetId = ref<number | null>(null)

async function openMove() {
  const item = selected.value
  if (!item || moveLoading.value) return
  moveOpen.value = true
  moveLoading.value = true
  moveTargets.value = null
  moveTargetId.value = item.move_suggestion?.supplier_id ?? null
  try {
    const result = await purchaseInvoiceSubmissionsApi.moveTargets(item.id)
    if (selected.value?.id !== item.id) return
    moveTargets.value = result
    moveTargetId.value = result.suggestion?.supplier_id ?? moveTargetId.value ?? result.targets[0]?.id ?? null
  } catch (e) {
    toast.error(apiErrorMessage(e))
    moveOpen.value = false
  } finally {
    moveLoading.value = false
  }
}

function moveTargetName(id: number | null): string {
  return moveTargets.value?.targets.find(f => f.id === id)?.name
    ?? (selected.value?.move_suggestion?.supplier_id === id ? selected.value?.move_suggestion?.supplier_name ?? '' : '')
}

async function moveSelected(targetId: number | null) {
  const item = selected.value
  if (!item || acting.value || targetId === null) return
  if (!confirm(t('purchase_submissions.move_confirm', { name: item.original_name, company: moveTargetName(targetId) }))) return
  acting.value = true
  try {
    const result = await purchaseInvoiceSubmissionsApi.move(item.id, targetId)
    toast.success(result.duplicate
      ? t('purchase_submissions.move_success_duplicate', { company: moveTargetName(targetId) })
      : t('purchase_submissions.move_success', { company: moveTargetName(targetId) }))
    moveOpen.value = false
    selected.value = null
    await load(false)
  } catch (e) {
    toast.error(apiErrorMessage(e))
  } finally {
    acting.value = false
  }
}
function canReview(item: PurchaseInvoiceSubmission): boolean {
  return (item.status === 'submitted' || item.status === 'needs_information')
    && item.replacement_submission_id === null && item.purchase_invoice_id === null
}
const canReviewSelected = computed(() => canWrite.value && selected.value !== null && canReview(selected.value))
/** Přerušené vytěžení (`processing_stale`) jde spustit znovu i smazat jako čekající podání. */
function canExtract(item: PurchaseInvoiceSubmission): boolean {
  return item.status === 'submitted' || !!item.processing_stale
}
function canDeleteItem(item: PurchaseInvoiceSubmission): boolean {
  return item.status !== 'processed' && (item.status !== 'processing' || !!item.processing_stale)
}
const canDeleteSelected = computed(() => canDelete.value && selected.value !== null && canDeleteItem(selected.value))

/** Hromadný výběr (checkboxy) je nezávislý na dokladu otevřeném v náhledu. */
const checkedIds = ref<Set<number>>(new Set())
const bulkReason = ref('')
const bulkDims = ref<DimensionMap>({})
const bulkProgress = ref<{ done: number; total: number } | null>(null)
const checkedItems = computed(() => items.value.filter(i => checkedIds.value.has(i.id)))
const allChecked = computed(() => items.value.length > 0 && checkedItems.value.length === items.value.length)
const bulkCanExtract = computed(() => canWrite.value && canCreateInvoice.value && checkedItems.value.some(canExtract))
const bulkCanReject = computed(() => canWrite.value && checkedItems.value.some(canReview))
const bulkCanDelete = computed(() => canDelete.value && checkedItems.value.some(canDeleteItem))
const bulkCanDimensions = computed(() => canWrite.value && dims.canEdit.value && dims.documentTypes.value.length > 0)
const bulkNeedsReason = computed(() =>
  checkedItems.value.some(canReview),
)
const bulkHasDims = computed(() => Object.values(bulkDims.value).some(v => !!v))

function toggleChecked(id: number) {
  const next = new Set(checkedIds.value)
  if (next.has(id)) next.delete(id)
  else next.add(id)
  checkedIds.value = next
}

function toggleAll() {
  checkedIds.value = allChecked.value ? new Set() : new Set(items.value.map(i => i.id))
}

/** Akce běží po jednom dokladu přes stejné endpointy jako u jednotlivého dokladu. */
async function runBulk(
  targets: PurchaseInvoiceSubmission[],
  action: (item: PurchaseInvoiceSubmission) => Promise<void>,
): Promise<{ ok: number; failed: number; lastError: string }> {
  let ok = 0
  let lastError = ''
  bulkProgress.value = { done: 0, total: targets.length }
  for (const item of targets) {
    try {
      await action(item)
      ok++
    } catch (e) {
      lastError = apiErrorMessage(e)
    }
    bulkProgress.value = { done: bulkProgress.value.done + 1, total: targets.length }
  }
  bulkProgress.value = null
  return { ok, failed: targets.length - ok, lastError }
}

function invoiceLabel(item: PurchaseInvoiceSubmission): string {
  return item.purchase_invoice_varsymbol || item.vendor_invoice_number || `#${item.purchase_invoice_id}`
}

function reportBulk(result: { ok: number; failed: number; lastError: string }, skipped: number) {
  const failed = result.failed + skipped
  if (failed === 0) toast.success(t('purchase_submissions.bulk_done', { ok: result.ok }))
  else if (result.ok === 0 && result.lastError) toast.error(result.lastError)
  else toast.warning(t('purchase_submissions.bulk_done_partial', { ok: result.ok, failed }))
}

async function bulkExtract() {
  if (acting.value || !bulkCanExtract.value) return
  const targets = checkedItems.value.filter(canExtract)
  const skipped = checkedItems.value.length - targets.length
  const invoiceIds: number[] = []
  let duplicates = 0
  acting.value = true
  try {
    const result = await runBulk(targets, async item => {
      const fresh = await purchaseInvoiceSubmissionsApi.extract(item.id)
      if (fresh.duplicate) duplicates++
      else if (fresh.purchase_invoice_id) invoiceIds.push(fresh.purchase_invoice_id)
    })
    reportBulk(result, skipped)
    if (duplicates > 0) toast.warning(t('purchase_submissions.bulk_duplicates', { n: duplicates }))
    checkedIds.value = new Set()
    await load()
    if (invoiceIds.length) bulkReviewInvoiceIds.value = invoiceIds
  } finally {
    acting.value = false
  }
}

async function bulkReject() {
  if (acting.value || !bulkCanReject.value) return
  if (bulkNeedsReason.value && !bulkReason.value.trim()) {
    toast.error(t('purchase_submissions.reason_required'))
    return
  }
  const targets = checkedItems.value.filter(canReview)
  const skipped = checkedItems.value.length - targets.length
  if (!confirm(t('purchase_submissions.bulk_reject_confirm', { n: targets.length }))) return
  acting.value = true
  try {
    const result = await runBulk(targets, async item => {
      await purchaseInvoiceSubmissionsApi.reject(item.id, bulkReason.value.trim())
    })
    reportBulk(result, skipped)
    bulkReason.value = ''
    checkedIds.value = new Set()
    await load()
  } finally {
    acting.value = false
  }
}

async function bulkDelete() {
  if (acting.value || !bulkCanDelete.value) return
  const targets = checkedItems.value.filter(canDeleteItem)
  const skipped = checkedItems.value.length - targets.length
  if (!confirm(t('purchase_submissions.bulk_delete_confirm', { n: targets.length }))) return
  acting.value = true
  try {
    const result = await runBulk(targets, async item => {
      await purchaseInvoiceSubmissionsApi.remove(item.id)
    })
    reportBulk(result, skipped)
    checkedIds.value = new Set()
    if (selected.value && targets.some(i => i.id === selected.value?.id)) selected.value = null
    await load()
  } finally {
    acting.value = false
  }
}

async function bulkApplyDimensions() {
  if (acting.value || !bulkCanDimensions.value || !bulkHasDims.value || checkedItems.value.length === 0) return
  acting.value = true
  try {
    const result = await purchaseInvoiceSubmissionsApi.bulkDimensions(checkedItems.value.map(i => i.id), bulkDims.value)
    if (result.updated.length === 0) toast.warning(t('purchase_submissions.bulk_nothing_applicable'))
    else reportBulk({ ok: result.updated.length, failed: 0, lastError: '' }, result.skipped.length)
    bulkDims.value = {}
    await load()
  } catch (e) {
    toast.error(apiErrorMessage(e))
  } finally {
    acting.value = false
  }
}

async function load(keepSelection = true) {
  const request = ++loadRequest
  const currentContext = context
  loading.value = true
  error.value = ''
  try {
    const page = await purchaseInvoiceSubmissionsApi.list(status.value || undefined)
    if (currentContext !== context || request !== loadRequest) return
    items.value = page.items
    const present = new Set(items.value.map(i => i.id))
    checkedIds.value = new Set([...checkedIds.value].filter(id => present.has(id)))
    if (keepSelection && selected.value) {
      selected.value = items.value.find(i => i.id === selected.value?.id) ?? null
    }
    if (!selected.value && items.value.length) selected.value = items.value[0]
  } catch (e) {
    if (currentContext === context && request === loadRequest) error.value = apiErrorMessage(e)
  } finally {
    if (currentContext === context && request === loadRequest) loading.value = false
  }
}

function limitFiles(list: File[]): File[] {
  if (list.length <= MAX_FILES) return list
  toast.warning(t('purchase_submissions.inbox_too_many_files', { max: MAX_FILES }))
  return list.slice(0, MAX_FILES)
}

function selectFiles(event: Event) {
  files.value = limitFiles(Array.from((event.target as HTMLInputElement).files ?? []))
}

/** Drag&drop i klik v dropzone plní tentýž výběr, odeslání zůstává na tlačítku. */
function addDroppedFiles(dropped: File[]) {
  const known = new Set(files.value.map(f => `${f.name}:${f.size}:${f.lastModified}`))
  files.value = limitFiles([
    ...files.value,
    ...dropped.filter(f => !known.has(`${f.name}:${f.size}:${f.lastModified}`)),
  ])
}

/** Fotka z fotoaparátu se přidá do výběru a odešle stejnou cestou jako vybrané soubory. */
function onPhotoCaptured(photo: File) {
  addDroppedFiles([photo])
  void upload()
}

function clearFiles() {
  files.value = []
  if (fileInput.value) fileInput.value.value = ''
}

async function upload() {
  if (uploading.value || files.value.length === 0) return
  uploading.value = true
  uploadProgress.value = { done: 0, total: files.value.length }
  try {
    // Po jednom souboru: velká dávka nenarazí na limit požadavku a chyba jednoho
    // souboru (velikost, formát, výpadek spojení) neshodí ostatní.
    const result: SubmissionUploadResult = { items: [], created: 0, duplicates: 0, errors: [] }
    for (const file of files.value) {
      try {
        const part = await purchaseInvoiceSubmissionsApi.upload([file], note.value, kindHint.value, dims.canEdit.value ? uploadDims.value : {},
          hasOtherCompanies.value && distributeUpload.value)
        result.items.push(...part.items)
        result.created += part.created
        result.duplicates += part.duplicates
        result.errors.push(...part.errors)
      } catch (e) {
        result.errors.push({ filename: file.name, code: 'upload_failed', message: apiErrorMessage(e) })
      }
      uploadProgress.value = { done: uploadProgress.value.done + 1, total: uploadProgress.value.total }
    }
    if (result.items.length === 0 && result.errors.length > 0) {
      throw new Error(result.errors[0].message)
    }
    if (result.errors.length > 0) {
      toast.warning(t('purchase_submissions.uploaded_partial', {
        accepted: result.items.length,
        failed: result.errors.length,
      }))
    } else {
      toast.success(result.duplicates > 0
        ? t('purchase_submissions.inbox_uploaded_with_duplicates', {
            created: result.created,
            duplicates: result.duplicates,
          })
        : t('purchase_submissions.inbox_uploaded', { n: result.created }))
    }
    const elsewhere = result.items.filter(item => item.supplier_id !== supplierStore.currentSupplierId)
    if (elsewhere.length > 0) {
      const companies = [...new Set(elsewhere.map(item => item.target_supplier_name).filter(Boolean))].join(', ')
      toast.info(t('purchase_submissions.distributed_upload', { n: elsewhere.length, companies }))
    }
    // Soubor, který už prošel zpracováním, ve frontě nečeká — bez hlášky by nebylo
    // poznat, kam se poděl.
    for (const item of result.items) {
      if (item.duplicate && item.status === 'processed' && item.purchase_invoice_id) {
        toast.warning(t('purchase_submissions.upload_already_processed', {
          file: item.original_name,
          number: invoiceLabel(item),
        }))
      }
    }
    clearFiles()
    note.value = ''
    kindHint.value = null
    uploadDims.value = {}
    // Nahraný doklad čeká ve stavu `submitted`. Kdyby účetní zrovna filtrovala jinak,
    // přepnutím filtru se seznam načte přes watch — jinak ho obnovíme sami.
    const uploadedId = result.items[0]?.id ?? null
    if (status.value !== '' && status.value !== 'submitted') {
      status.value = 'submitted'
    } else {
      await load(false)
      if (uploadedId !== null) {
        selected.value = items.value.find(i => i.id === uploadedId) ?? selected.value
      }
    }
  } catch (e) {
    toast.error(apiErrorMessage(e))
  } finally {
    uploading.value = false
  }
}

const reviewInvoiceId = ref<number | null>(null)
const bulkReviewInvoiceIds = ref<number[]>([])
async function onReviewClosed(navigated?: boolean) {
  const id = reviewInvoiceId.value
  reviewInvoiceId.value = null
  if (id && !navigated) await router.push(`/purchase-invoices/${id}/edit`)
}

async function extract() {
  if (!selected.value || acting.value) return
  acting.value = true
  try {
    const fresh = await purchaseInvoiceSubmissionsApi.extract(selected.value.id)
    selected.value = fresh
    if (fresh.duplicate) {
      // Doklad zůstane vybraný i s tlačítkem na existující fakturu, místo aby z fronty
      // potichu zmizel.
      items.value = items.value.map(i => (i.id === fresh.id ? fresh : i))
      toast.warning(t('purchase_submissions.already_exists', { number: invoiceLabel(fresh) }))
      return
    }
    toast.success(t('purchase_submissions.processed_success'))
    if (fresh.purchase_invoice_id) {
      // Nejdřív kontrola vytěženého (okno se samo zavře, když není co kontrolovat),
      // pak editor jako dosud.
      reviewInvoiceId.value = fresh.purchase_invoice_id
    } else {
      await load()
    }
  } catch (e) {
    toast.error(apiErrorMessage(e))
    await refreshSelected()
  } finally {
    acting.value = false
  }
}

async function removeSelected() {
  const item = selected.value
  if (!item || acting.value || !canDeleteSelected.value) return
  if (!confirm(t('purchase_submissions.delete_confirm', { name: item.original_name }))) return
  acting.value = true
  try {
    await purchaseInvoiceSubmissionsApi.remove(item.id)
    toast.success(t('purchase_submissions.delete_success'))
    selected.value = null
    await load(false)
  } catch (e) {
    toast.error(apiErrorMessage(e))
  } finally {
    acting.value = false
  }
}

async function setReviewStatus(target: 'needs_information' | 'rejected') {
  const item = selected.value
  if (!item || acting.value || !canReviewSelected.value) return
  if (target === 'needs_information' && item.status !== 'submitted') return
  const message = reason.value.trim()
  if (!message) {
    toast.error(t('purchase_submissions.reason_required'))
    return
  }
  const currentContext = context
  acting.value = true
  try {
    const fresh = target === 'needs_information'
      ? await purchaseInvoiceSubmissionsApi.needsInformation(item.id, message)
      : await purchaseInvoiceSubmissionsApi.reject(item.id, message)
    if (currentContext !== context) return
    items.value = items.value.map(current => current.id === fresh.id ? fresh : current)
    if (selected.value?.id === item.id) {
      selected.value = fresh
      reason.value = ''
    }
    toast.success(t(`purchase_submissions.${target}_success`))
    await load()
  } catch (e) {
    if (currentContext !== context) return
    toast.error(apiErrorMessage(e))
    await load()
  } finally {
    if (currentContext === context) acting.value = false
  }
}

async function refreshSelected() {
  if (!selected.value) return
  try {
    selected.value = await purchaseInvoiceSubmissionsApi.get(selected.value.id)
  } catch {
  }
}

function badge(s: PurchaseInvoiceSubmissionStatus): string {
  return {
    submitted: 'bg-warning-50 text-warning-600',
    processing: 'bg-primary-50 text-primary-700',
    needs_information: 'bg-danger-50 text-danger-600',
    processed: 'bg-success-50 text-success-600',
    rejected: 'bg-neutral-100 text-neutral-600',
  }[s]
}

function size(bytes: number): string {
  return bytes < 1024 * 1024 ? `${Math.max(1, Math.round(bytes / 1024))} kB` : `${(bytes / 1024 / 1024).toFixed(1)} MB`
}

function kindLabel(item: PurchaseInvoiceSubmission): string {
  return item.document_kind_hint && item.document_kind_hint !== 'other'
    ? t(`purchase_invoice.document_kind.${item.document_kind_hint}`)
    : t('purchase_submissions.kind_other')
}

onMounted(() => { void load(false); void dims.load() })
watch(status, () => { selected.value = null; checkedIds.value = new Set(); void load(false) })
watch(() => selected.value?.id, () => { reason.value = ''; moveOpen.value = false; moveTargets.value = null })
watch(() => supplierStore.currentSupplierId, () => {
  context++
  items.value = []
  selected.value = null
  acting.value = false
  checkedIds.value = new Set()
  void load(false)
})
onBeforeUnmount(() => { context++; loadRequest++ })
</script>

<template>
  <div class="space-y-5">
    <header class="flex flex-wrap items-end justify-between gap-3">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('purchase_submissions.inbox_title') }}</h1>
        <p class="text-sm text-neutral-500 mt-1">{{ t('purchase_submissions.inbox_subtitle') }}</p>
      </div>
      <label class="text-sm text-neutral-700 whitespace-nowrap">
        <span class="mr-2">{{ t('purchase_submissions.filter_status') }}</span>
        <select v-model="status" class="h-9 px-3 border border-neutral-300 rounded-md bg-surface">
          <option value="">{{ t('common.all') }}</option>
          <option value="submitted">{{ t('purchase_submissions.status.submitted') }}</option>
          <option value="processing">{{ t('purchase_submissions.status.processing') }}</option>
          <option value="needs_information">{{ t('purchase_submissions.status.needs_information') }}</option>
          <option value="processed">{{ t('purchase_submissions.status.processed') }}</option>
          <option value="rejected">{{ t('purchase_submissions.status.rejected') }}</option>
        </select>
      </label>
    </header>

    <section v-if="canWrite" class="bg-surface border border-primary-500/30 rounded-lg shadow-sm p-5 space-y-4">
      <div class="flex items-start gap-3">
        <span class="w-10 h-10 rounded-lg bg-primary-50 text-primary-700 flex items-center justify-center shrink-0">
          <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 16V4m0 0L7 9m5-5 5 5M5 14v5a1 1 0 001 1h12a1 1 0 001-1v-5" />
          </svg>
        </span>
        <div>
          <h2 class="font-semibold text-neutral-800">{{ t('purchase_submissions.inbox_submit_title') }}</h2>
          <p class="text-sm text-neutral-500">{{ t('purchase_submissions.inbox_submit_hint') }}</p>
        </div>
      </div>

      <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <label class="block text-sm text-neutral-700">
          <span class="block mb-1">{{ t('purchase_submissions.kind_hint') }}</span>
          <select v-model="kindHint" class="w-full h-10 px-3 border border-neutral-300 rounded-md bg-surface">
            <option :value="null">{{ t('purchase_submissions.kind_unknown') }}</option>
            <option value="invoice">{{ t('purchase_invoice.document_kind.invoice') }}</option>
            <option value="receipt">{{ t('purchase_invoice.document_kind.receipt') }}</option>
            <option value="credit_note">{{ t('purchase_invoice.document_kind.credit_note') }}</option>
            <option value="advance">{{ t('purchase_invoice.document_kind.advance') }}</option>
            <option value="tax_document">{{ t('purchase_invoice.document_kind.tax_document') }}</option>
            <option value="other">{{ t('purchase_submissions.kind_other') }}</option>
          </select>
        </label>
        <label class="block text-sm text-neutral-700">
          <span class="block mb-1">{{ t('purchase_submissions.inbox_note') }}</span>
          <input v-model="note" maxlength="8000" class="w-full h-10 px-3 border border-neutral-300 rounded-md bg-surface"
            :placeholder="t('purchase_submissions.inbox_note_placeholder')" />
        </label>
      </div>

      <div v-if="dims.canEdit.value && dims.documentTypes.value.length" class="space-y-1" data-test="inbox-dimensions">
        <span class="block text-sm text-neutral-700">{{ t('purchase_submissions.dimensions_title') }}</span>
        <DimensionFields v-model="uploadDims" compact />
        <p class="text-xs text-neutral-500">{{ t('purchase_submissions.dimensions_hint') }}</p>
      </div>

      <label v-if="hasOtherCompanies" class="flex items-start gap-2 text-sm text-neutral-700" data-testid="inbox-distribute">
        <input v-model="distributeUpload" type="checkbox" class="mt-0.5 rounded border-neutral-300 text-primary-600" />
        <span>
          {{ t('purchase_submissions.distribute_upload') }}
          <span class="block text-xs text-neutral-500">{{ t('purchase_submissions.distribute_upload_hint') }}</span>
        </span>
      </label>

      <PdfDropzone
        multiple
        accept-structured
        :extra-extensions="['xml']"
        :accept="ALLOWED_FILES"
        :uploading="uploading"
        :max-size-bytes="MAX_FILE_BYTES"
        :hint="t('purchase_submissions.inbox_dropzone_hint')"
        :size-hint="t('purchase_submissions.inbox_dropzone_formats')"
        @files-dropped="addDroppedFiles"
        @error="(_code, message) => toast.error(message)"
      />

      <ul v-if="files.length" class="text-sm text-neutral-700 space-y-1">
        <li v-for="file in files" :key="`${file.name}:${file.size}:${file.lastModified}`"
          class="flex items-center justify-between gap-3 bg-neutral-50 border border-neutral-200 rounded-md px-3 py-1.5">
          <span class="truncate">{{ file.name }}</span>
          <span class="text-xs text-neutral-500 shrink-0">{{ size(file.size) }}</span>
        </li>
      </ul>

      <div class="flex flex-wrap items-center gap-3">
        <label :class="btnOutline('neutral')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 16V4m0 0L7 9m5-5 5 5M5 14v5a1 1 0 001 1h12a1 1 0 001-1v-5" />
          </svg>
          {{ t('purchase_submissions.choose_files') }}
          <input ref="fileInput" type="file" multiple :accept="ALLOWED_FILES" class="hidden" @change="selectFiles" />
        </label>
        <CapturePhotoButton :disabled="uploading" @captured="onPhotoCaptured" />
        <span v-if="files.length" class="text-sm text-neutral-600">
          {{ t('purchase_submissions.files_selected', { n: files.length }) }}
        </span>
        <button v-if="files.length" type="button" :class="btnOutline('neutral')" :disabled="uploading" @click="clearFiles">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
          </svg>
          {{ t('purchase_submissions.clear_files') }}
        </button>
        <button type="button" data-testid="inbox-upload" :disabled="files.length === 0 || uploading"
          :class="btnFilled('primary')" @click="upload">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M5 12l4 4L19 6" />
          </svg>
          {{ uploading ? t('purchase_submissions.inbox_uploading_progress', uploadProgress) : t('purchase_submissions.inbox_submit_action') }}
        </button>
      </div>
    </section>

    <div v-if="error" class="rounded-md bg-danger-50 border border-danger-500/40 px-3 py-2 text-sm text-danger-600">{{ error }}</div>
    <div v-if="loading" class="text-center text-neutral-500 py-10">{{ t('common.loading') }}</div>
    <EmptyState v-else-if="items.length === 0" boxed accent="success" icon="checkCircle" :title="t('purchase_submissions.inbox_empty')" />

    <div v-else class="grid grid-cols-1 xl:grid-cols-[minmax(280px,380px)_minmax(0,1fr)] gap-4 items-start">
      <div class="space-y-2">
        <div class="bg-surface border border-neutral-200 rounded-lg p-3 space-y-3" data-testid="inbox-bulk">
          <div class="flex flex-wrap items-center justify-between gap-2">
            <label class="inline-flex items-center gap-2 text-sm text-neutral-700 cursor-pointer whitespace-nowrap">
              <input type="checkbox" class="w-4 h-4 accent-primary-600" :checked="allChecked"
                :indeterminate="checkedItems.length > 0 && !allChecked" data-testid="inbox-check-all" @change="toggleAll" />
              {{ allChecked ? t('purchase_submissions.bulk_clear') : t('purchase_submissions.bulk_select_all') }}
            </label>
            <span v-if="checkedItems.length" class="text-xs text-neutral-500 whitespace-nowrap">
              {{ bulkProgress
                ? t('purchase_submissions.bulk_running', bulkProgress)
                : t('purchase_submissions.bulk_selected', { n: checkedItems.length }) }}
            </span>
          </div>

          <template v-if="checkedItems.length">
            <div class="flex flex-wrap gap-2">
              <button v-if="bulkCanExtract" type="button" :disabled="acting" :class="btnFilled('primary')"
                class="whitespace-nowrap" data-testid="inbox-bulk-extract" @click="bulkExtract">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3v18m9-9H3" /></svg>
                {{ t('purchase_submissions.bulk_extract') }}
              </button>
              <button v-if="bulkCanReject" type="button" :disabled="acting || (bulkNeedsReason && !bulkReason.trim())"
                :class="btnOutline('danger')" class="whitespace-nowrap" data-testid="inbox-bulk-reject" @click="bulkReject">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                {{ t('purchase_submissions.bulk_reject') }}
              </button>
              <button v-if="bulkCanDelete" type="button" :disabled="acting" :class="btnOutline('danger')"
                class="whitespace-nowrap" data-testid="inbox-bulk-delete" @click="bulkDelete">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6M4 7h16M10 7V4h4v3" /></svg>
                {{ t('purchase_submissions.bulk_delete') }}
              </button>
            </div>
            <input v-if="bulkCanReject" v-model="bulkReason" maxlength="8000"
              class="w-full h-9 px-3 text-sm border border-neutral-300 rounded-md bg-surface"
              :placeholder="bulkNeedsReason ? t('purchase_submissions.bulk_reason_placeholder') : t('purchase_submissions.reason_internal_placeholder')" />

            <div v-if="bulkCanDimensions" class="space-y-2 pt-2 border-t border-neutral-100" data-testid="inbox-bulk-dimensions">
              <span class="block text-sm text-neutral-700">{{ t('purchase_submissions.bulk_dimensions_title') }}</span>
              <DimensionFields v-model="bulkDims" compact />
              <div class="flex flex-wrap items-center gap-2">
                <button type="button" :disabled="acting || !bulkHasDims" :class="btnOutline('primary')"
                  class="whitespace-nowrap" @click="bulkApplyDimensions">
                  <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M7 7h.01M7 3h5a1.99 1.99 0 011.41.59l7 7a2 2 0 010 2.82l-7 7a2 2 0 01-2.82 0l-7-7A1.99 1.99 0 013 12V7a4 4 0 014-4z" /></svg>
                  {{ t('purchase_submissions.bulk_dimensions_apply') }}
                </button>
              </div>
              <p class="text-xs text-neutral-500">{{ t('purchase_submissions.bulk_dimensions_hint') }}</p>
            </div>
          </template>
        </div>

        <div v-for="item in items" :key="item.id" class="flex items-start gap-2">
          <input type="checkbox" class="w-4 h-4 mt-3.5 shrink-0 accent-primary-600 cursor-pointer"
            :checked="checkedIds.has(item.id)" :aria-label="t('purchase_submissions.bulk_select_item', { name: item.original_name })"
            data-testid="inbox-check" @change="toggleChecked(item.id)" />
          <button type="button" @click="selected = item"
            class="flex-1 min-w-0 text-left bg-surface border rounded-lg p-3 transition"
            :class="selected?.id === item.id ? 'border-primary-500 ring-1 ring-primary-500/30' : 'border-neutral-200 hover:border-neutral-400'">
          <div class="flex items-start justify-between gap-2">
            <span class="font-medium text-sm truncate">{{ item.original_name }}</span>
            <span class="text-[11px] px-2 py-0.5 rounded font-medium shrink-0" :class="badge(item.status)">
              {{ t(`purchase_submissions.status.${item.status}`) }}
            </span>
          </div>
          <p class="text-xs text-neutral-500 mt-1">{{ item.submitted_by_name || '—' }} · {{ size(item.size_bytes) }}</p>
          <p v-if="item.note" class="text-xs text-neutral-600 mt-2 line-clamp-2">{{ item.note }}</p>
          <p v-if="item.move_suggestion" class="mt-2 inline-flex items-center px-2 py-0.5 rounded-full bg-primary-50 text-primary-700 text-[11px] font-medium">
            {{ t('purchase_submissions.belongs_to', { company: item.move_suggestion.supplier_name }) }}
          </p>
          <DimensionChips v-if="item.dimensions && Object.keys(item.dimensions).length" :dimensions="item.dimensions" class="mt-2" />
          </button>
        </div>
      </div>

      <section v-if="selected" class="min-w-0">
        <DocumentPreviewSplit :ctrl="preview" :show="preview.open.value" :src="purchaseInvoiceSubmissionsApi.previewUrl(selected.id)"
          :file-name="selected.original_name" :doc-type="selected.doc_type" :empty-text="t('purchase_submissions.no_inline_preview')"
          form-class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-4 space-y-3">
          <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
              <h2 class="font-semibold">{{ selected.original_name }}</h2>
              <p class="text-xs text-neutral-500 mt-1">
                {{ t('purchase_submissions.submitted_by', { name: selected.submitted_by_name || '—' }) }}
                · {{ new Date(selected.created_at).toLocaleString() }}
              </p>
            </div>
            <span class="text-xs px-2 py-0.5 rounded font-medium" :class="badge(selected.status)">
              {{ t(`purchase_submissions.status.${selected.status}`) }}
            </span>
          </div>
          <p v-if="selected.note" class="text-sm text-neutral-700">{{ selected.note }}</p>
          <DimensionChips v-if="selected.dimensions && Object.keys(selected.dimensions).length" :dimensions="selected.dimensions" />
          <p v-if="selected.document_kind_hint" class="text-xs text-neutral-500">
            {{ t('purchase_submissions.kind_provided', { kind: kindLabel(selected) }) }}
          </p>
          <p v-if="selected.request_count > 0 || selected.bank_transaction_id || selected.supersedes_submission_id"
            class="text-xs text-neutral-500 flex flex-wrap gap-x-3 gap-y-1">
            <span v-if="selected.request_count > 0">
              {{ t('purchase_submissions.linked_requests', { n: selected.request_count }) }}
            </span>
            <span v-if="selected.bank_transaction_id">
              {{ t('purchase_submissions.linked_bank_transaction', { id: selected.bank_transaction_id }) }}
            </span>
            <span v-if="selected.supersedes_submission_id">
              {{ t('purchase_submissions.replaces_submission', { id: selected.supersedes_submission_id }) }}
            </span>
          </p>
          <p v-if="selected.status_reason" class="text-sm text-danger-600">{{ selected.status_reason }}</p>
          <p v-if="selected.extraction_error" class="text-xs rounded bg-warning-50 text-warning-700 px-3 py-2">
            {{ selected.extraction_error }}
          </p>
          <p v-if="selected.processing_stale" class="text-xs rounded bg-warning-50 text-warning-700 px-3 py-2">
            {{ t('purchase_submissions.processing_stale') }}
          </p>

          <div class="flex flex-wrap gap-2">
            <button v-if="canExtract(selected) && canWrite && canCreateInvoice" type="button"
              :disabled="acting" :class="btnFilled('primary')" @click="extract">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3v18m9-9H3" /></svg>
              {{ acting ? t('purchase_submissions.processing') : t('purchase_submissions.extract') }}
            </button>
            <RouterLink v-if="selected.status === 'submitted' && canWrite && canCreateInvoice"
              :to="{ path: '/purchase-invoices/new', query: { submission_id: selected.id } }" :class="btnOutline('primary')">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 20h9M16.5 3.5a2.12 2.12 0 013 3L8 18l-4 1 1-4L16.5 3.5z" /></svg>
              {{ t('purchase_submissions.manual') }}
            </RouterLink>
            <RouterLink v-if="selected.purchase_invoice_id" :to="`/purchase-invoices/${selected.purchase_invoice_id}`" :class="btnFilled('success')">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
              {{ t('purchase_submissions.open_invoice') }}
            </RouterLink>
            <a :href="purchaseInvoiceSubmissionsApi.downloadUrl(selected.id)" :class="btnOutline('neutral')">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v12m0 0l-4-4m4 4 4-4M5 20h14" /></svg>
              {{ t('purchase_submissions.download') }}
            </a>
            <button type="button" :class="btnOutline('neutral')" data-testid="inbox-preview-toggle"
              :aria-pressed="preview.open.value" @click="preview.toggle()">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="preview.open.value ? ICONS.eyeOff : ICONS.eye" /></svg>
              {{ preview.open.value ? t('common.preview_side_hide') : t('common.preview_side_show') }}
            </button>
          </div>

          <div v-if="canMove(selected)" class="space-y-2 pt-2 border-t border-neutral-100" data-testid="inbox-move">
            <p v-if="selected.move_suggestion" class="text-sm text-neutral-700">
              {{ t('purchase_submissions.move_suggestion_hint', { company: selected.move_suggestion.supplier_name }) }}
            </p>
            <div class="flex flex-wrap gap-2">
              <button v-if="selected.move_suggestion" type="button" :disabled="acting" :class="btnFilled('primary')"
                class="whitespace-nowrap" data-testid="inbox-move-suggested" @click="moveSelected(selected.move_suggestion.supplier_id)">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 7l5 5m0 0l-5 5m5-5H6" /></svg>
                {{ t('purchase_submissions.move_to', { company: selected.move_suggestion.supplier_name }) }}
              </button>
              <button v-if="!moveOpen" type="button" :disabled="acting || moveLoading" :class="btnOutline('neutral')"
                class="whitespace-nowrap" data-testid="inbox-move-open" @click="openMove">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4" /></svg>
                {{ t('purchase_submissions.move_other') }}
              </button>
            </div>
            <div v-if="moveOpen" class="space-y-2">
              <p v-if="moveLoading" class="text-sm text-neutral-500">{{ t('common.loading') }}</p>
              <template v-else-if="moveTargets">
                <p v-if="moveTargets.blocked_reason" class="text-sm text-warning-700">{{ moveTargets.blocked_reason }}</p>
                <p v-else-if="moveTargets.targets.length === 0" class="text-sm text-neutral-500">{{ t('purchase_submissions.move_no_targets') }}</p>
                <div v-else class="flex flex-wrap items-center gap-2">
                  <select v-model="moveTargetId" class="h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm min-w-[14rem]" data-testid="inbox-move-target">
                    <option v-for="firm in moveTargets.targets" :key="firm.id" :value="firm.id">
                      {{ firm.name }}<template v-if="firm.ic"> · {{ firm.ic }}</template><template v-if="moveTargets.suggestion?.supplier_id === firm.id"> ({{ t('purchase_submissions.move_suggested') }})</template>
                    </option>
                  </select>
                  <button type="button" :disabled="acting || moveTargetId === null" :class="btnFilled('primary')"
                    class="whitespace-nowrap" data-testid="inbox-move-confirm" @click="moveSelected(moveTargetId)">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 7l5 5m0 0l-5 5m5-5H6" /></svg>
                    {{ t('purchase_submissions.move_confirm_button') }}
                  </button>
                  <button type="button" :class="btnOutline('neutral')" class="whitespace-nowrap" @click="moveOpen = false">
                    {{ t('common.cancel') }}
                  </button>
                </div>
              </template>
              <p class="text-xs text-neutral-500">{{ t('purchase_submissions.move_hint') }}</p>
            </div>
          </div>

          <div v-if="canReviewSelected" class="space-y-2 pt-2 border-t border-neutral-100">
            <label class="block text-sm text-neutral-700">
              <span class="block mb-1">
                {{ hasClientToInform ? t('purchase_submissions.reason') : t('purchase_submissions.reason_internal') }}
              </span>
              <textarea v-model="reason" rows="2" maxlength="8000" class="w-full px-3 py-2 border border-neutral-300 rounded-md bg-surface"
                :placeholder="hasClientToInform
                  ? t('purchase_submissions.reason_placeholder')
                  : t('purchase_submissions.reason_internal_placeholder')" />
            </label>
            <div class="flex flex-wrap gap-2">
              <button v-if="hasClientToInform && selected.status === 'submitted'" type="button" :disabled="acting || !reason.trim()"
                :class="btnOutline('warning')" @click="setReviewStatus('needs_information')">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3m0 4h.01M10.3 3.4L2.6 17a2 2 0 001.7 3h15.4a2 2 0 001.7-3L13.7 3.4a2 2 0 00-3.4 0z" /></svg>
                {{ t('purchase_submissions.needs_information') }}
              </button>
              <button type="button" :disabled="acting || !reason.trim()"
                :class="btnOutline('danger')" @click="setReviewStatus('rejected')">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                {{ t('purchase_submissions.reject') }}
              </button>
            </div>
            <p class="text-xs text-neutral-500">{{ t('purchase_submissions.reject_keeps_original') }}</p>
          </div>
          <details v-if="canDeleteSelected" class="pt-2 border-t border-neutral-100 text-sm">
            <summary class="cursor-pointer text-neutral-500">{{ t('purchase_submissions.manage_original') }}</summary>
            <p class="text-xs text-neutral-500 my-2">{{ t('purchase_submissions.delete_hint') }}</p>
            <button type="button" data-testid="inbox-delete" :disabled="acting" :class="btnOutline('danger')" @click="removeSelected">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6M4 7h16M10 7V4h4v3" /></svg>
              {{ t('purchase_submissions.delete') }}
            </button>
          </details>
        </DocumentPreviewSplit>
      </section>
    </div>
    <ExtractionReviewModal v-if="reviewInvoiceId" :invoice-ids="[reviewInvoiceId]" silent-when-empty @close="onReviewClosed" />
    <ExtractionReviewModal v-else-if="bulkReviewInvoiceIds.length" :invoice-ids="bulkReviewInvoiceIds" silent-when-empty
      @close="bulkReviewInvoiceIds = []" />
  </div>
</template>

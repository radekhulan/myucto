<script setup lang="ts">
import { computed, onMounted, onBeforeUnmount, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useMediaQuery } from '@vueuse/core'
import {
  portalPurchaseInvoiceSubmissionsApi,
  type PortalDimensionChoiceType,
  type PurchaseInvoiceSubmission,
  type PurchaseInvoiceSubmissionKindHint,
} from '@/api/purchaseInvoiceSubmissions'
import { useSupplierStore } from '@/stores/supplier'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { apiErrorMessage } from '@/api/errors'
import { ICONS, btnFilled, btnOutline } from '@/components/ui/buttonStyles'
import EmptyState from '@/components/ui/EmptyState.vue'
import Modal from '@/components/ui/Modal.vue'
import SubmissionPreview from '@/components/documents/SubmissionPreview.vue'
import CapturePhotoButton from '@/components/upload/CapturePhotoButton.vue'
import PhotoCropDialog from '@/components/upload/PhotoCropDialog.vue'
import { usePhotoCropQueue } from '@/composables/usePhotoCropQueue'

const { t } = useI18n()
const supplierStore = useSupplierStore()
const auth = useAuthStore()
const toast = useToast()

const items = ref<PurchaseInvoiceSubmission[]>([])
const files = ref<File[]>([])
const note = ref('')
const kindHint = ref<PurchaseInvoiceSubmissionKindHint | null>(null)
/** Střediska, která firma na portálu nabízí (jen aktivní), a volba k předávanému dokladu. */
const dimensionTypes = ref<PortalDimensionChoiceType[]>([])
const chosenDims = ref<Record<number, number | null>>({})
const loading = ref(true)
const uploading = ref(false)
const error = ref('')
const replacingId = ref<number | null>(null)
const deletingId = ref<number | null>(null)
const fileInput = ref<HTMLInputElement | null>(null)
const dragOver = ref(false)
const previewId = ref<number | null>(null)
const previewItem = computed(() => items.value.find(item => item.id === previewId.value) ?? null)
const widePreview = useMediaQuery('(min-width: 1280px)')
let context = 0
let loadRequest = 0
let dimensionRequest = 0

const allowed = '.pdf,.jpg,.jpeg,.png,.isdoc,.xml,.isdocx,application/pdf,image/jpeg,image/png'
const openItems = computed(() => items.value.filter(i =>
  i.status !== 'processed' && i.status !== 'rejected' && i.replacement_submission_id === null,
))
const closedItems = computed(() => items.value.filter(i =>
  i.status === 'processed' || i.status === 'rejected' || i.replacement_submission_id !== null,
))

async function loadDimensionChoices() {
  const request = ++dimensionRequest
  const currentContext = context
  try {
    const types = await portalPurchaseInvoiceSubmissionsApi.dimensions()
    if (currentContext !== context || request !== dimensionRequest) return
    dimensionTypes.value = types
  } catch {
    if (currentContext !== context || request !== dimensionRequest) return
    dimensionTypes.value = []
  }
  chosenDims.value = {}
}

function valueLabel(value: { code: string; name: string }): string {
  return value.code === value.name ? value.name : `${value.code} – ${value.name}`
}

/** Zvolené dimenze podání jako text (Typ: hodnota) podle nabídky portálu. */
function dimensionSummary(item: PurchaseInvoiceSubmission): string {
  const parts: string[] = []
  for (const [typeId, valueId] of Object.entries(item.dimensions ?? {})) {
    const type = dimensionTypes.value.find(ty => ty.id === Number(typeId))
    const value = type?.values.find(v => v.id === valueId)
    if (type && value) parts.push(`${type.name}: ${valueLabel(value)}`)
  }
  return parts.join(', ')
}

async function load() {
  const request = ++loadRequest
  const currentContext = context
  loading.value = true
  error.value = ''
  try {
    const page = await portalPurchaseInvoiceSubmissionsApi.list()
    if (currentContext !== context || request !== loadRequest) return
    items.value = page.items
    if (!items.value.some(item => item.id === previewId.value)) previewId.value = null
  } catch (e) {
    if (currentContext === context && request === loadRequest) error.value = apiErrorMessage(e)
  } finally {
    if (currentContext === context && request === loadRequest) loading.value = false
  }
}

const ALLOWED_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png', 'isdoc', 'xml', 'isdocx']

/**
 * Vybrané, přetažené i vyfocené soubory se PŘIDÁVAJÍ k už vybraným. Na telefonu
 * se vybírá a fotí po jednom, takže přepsání by víc dokladů najednou předat nedovolilo.
 * Nepodporovaný formát se vynechá nahlas, stejný soubor se nepřidá dvakrát.
 */
function addFiles(incoming: File[]) {
  if (uploading.value || incoming.length === 0) return
  const accepted = incoming.filter(file => ALLOWED_EXTENSIONS.includes(file.name.split('.').pop()?.toLowerCase() ?? ''))
  const rejected = incoming.filter(file => !accepted.includes(file))
  if (rejected.length > 0) {
    toast.warning(t('purchase_submissions.drop_rejected', { names: rejected.map(file => file.name).join(', ') }))
  }
  const known = new Set(files.value.map(file => `${file.name}|${file.size}`))
  files.value = [...files.value, ...accepted.filter(file => !known.has(`${file.name}|${file.size}`))]
}

/** Vybrané a přetažené fotky projdou ořezem dokladu stejně jako v AI importu. */
const crop = usePhotoCropQueue(addFiles)

function selectFiles(event: Event) {
  const input = event.target as HTMLInputElement
  crop.start(Array.from(input.files ?? []))
  // Reset, ať jde přidat další soubory i znovu vybrat týž soubor po odebrání.
  input.value = ''
}

function removeFile(file: File) {
  if (uploading.value) return
  files.value = files.value.filter(item => item !== file)
}

function onDrop(event: DragEvent) {
  dragOver.value = false
  if (uploading.value) return
  crop.start(Array.from(event.dataTransfer?.files ?? []))
}

async function submit() {
  if (uploading.value || files.value.length === 0) return
  const currentContext = context
  uploading.value = true
  try {
    const result = await portalPurchaseInvoiceSubmissionsApi.upload(files.value, note.value, kindHint.value, chosenDims.value)
    if (currentContext !== context) return
    if (result.errors.length > 0) {
      toast.warning(t('purchase_submissions.uploaded_partial', {
        accepted: result.items.length,
        failed: result.errors.length,
      }))
    } else {
      toast.success(result.duplicates > 0
        ? t('purchase_submissions.uploaded_with_duplicates', { created: result.created, duplicates: result.duplicates })
        : t('purchase_submissions.uploaded', { n: result.created }))
    }
    files.value = []
    note.value = ''
    kindHint.value = null
    chosenDims.value = {}
    if (fileInput.value) fileInput.value.value = ''
    await load()
  } catch (e) {
    if (currentContext === context) toast.error(apiErrorMessage(e))
  } finally {
    if (currentContext === context) uploading.value = false
  }
}

async function resubmit(item: PurchaseInvoiceSubmission, event: Event) {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]
  if (!file || !canReplace(item) || replacingId.value !== null || deletingId.value !== null) return
  const currentContext = context
  replacingId.value = item.id
  try {
    await portalPurchaseInvoiceSubmissionsApi.resubmit(item.id, file, '')
    if (currentContext !== context) return
    toast.success(t('purchase_submissions.replacement_uploaded'))
    await load()
  } catch (e) {
    if (currentContext === context) toast.error(apiErrorMessage(e))
  } finally {
    if (currentContext === context) replacingId.value = null
    input.value = ''
  }
}

function canReplace(item: PurchaseInvoiceSubmission): boolean {
  return auth.canWrite('documents.submit')
    && (item.status === 'needs_information' || item.status === 'rejected')
    && item.replacement_submission_id === null && item.purchase_invoice_id === null
}

async function remove(item: PurchaseInvoiceSubmission) {
  if (!auth.canWrite('documents.submit') || !item.can_delete || deletingId.value !== null || replacingId.value !== null) return
  if (!confirm(t('purchase_submissions.portal_delete_confirm', { name: item.original_name }))) return
  const currentContext = context
  deletingId.value = item.id
  try {
    await portalPurchaseInvoiceSubmissionsApi.remove(item.id)
    if (currentContext !== context) return
    items.value = items.value.filter(current => current.id !== item.id)
    if (previewId.value === item.id) previewId.value = null
    toast.success(t('purchase_submissions.portal_delete_success'))
    await load()
  } catch (e) {
    if (currentContext !== context) return
    toast.error(apiErrorMessage(e))
    await load()
  } finally {
    if (currentContext === context) deletingId.value = null
  }
}

function badge(status: PurchaseInvoiceSubmission['status']): string {
  return {
    submitted: 'bg-warning-50 text-warning-600',
    processing: 'bg-primary-50 text-primary-700',
    needs_information: 'bg-danger-50 text-danger-600',
    processed: 'bg-success-50 text-success-600',
    rejected: 'bg-neutral-100 text-neutral-600',
  }[status]
}

function itemBadge(item: PurchaseInvoiceSubmission): string {
  return item.replacement_submission_id !== null
    ? 'bg-primary-50 text-primary-700'
    : badge(item.status)
}

function itemStatus(item: PurchaseInvoiceSubmission): string {
  return item.replacement_submission_id !== null
    ? t('purchase_submissions.replacement_received')
    : t(`purchase_submissions.status.${item.status}`)
}

function size(bytes: number): string {
  return bytes < 1024 * 1024 ? `${Math.max(1, Math.round(bytes / 1024))} kB` : `${(bytes / 1024 / 1024).toFixed(1)} MB`
}

onMounted(() => { void load(); void loadDimensionChoices() })
watch(() => supplierStore.currentSupplierId, () => {
  context++
  items.value = []
  dimensionTypes.value = []
  uploading.value = false
  files.value = []
  note.value = ''
  kindHint.value = null
  chosenDims.value = {}
  if (fileInput.value) fileInput.value.value = ''
  replacingId.value = null
  deletingId.value = null
  previewId.value = null
  void load()
  void loadDimensionChoices()
})
onBeforeUnmount(() => { context++; loadRequest++; dimensionRequest++ })
</script>

<template>
  <div class="max-w-7xl space-y-5">
    <header>
      <h1 class="text-2xl font-semibold">{{ t('purchase_submissions.portal_title') }}</h1>
      <p class="text-sm text-neutral-500 mt-1">{{ t('purchase_submissions.portal_subtitle') }}</p>
      <p class="text-sm text-neutral-500" data-testid="portal-after-submit">{{ t('purchase_submissions.portal_after_submit') }}</p>
    </header>

    <section v-if="auth.canWrite('documents.submit')" class="bg-surface border border-primary-500/30 rounded-lg shadow-sm p-5 space-y-4">
      <div class="flex items-start gap-3">
        <span class="w-10 h-10 rounded-lg bg-primary-50 text-primary-700 flex items-center justify-center shrink-0">
          <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 16V4m0 0L7 9m5-5 5 5M5 14v5a1 1 0 001 1h12a1 1 0 001-1v-5" />
          </svg>
        </span>
        <div>
          <h2 class="font-semibold text-neutral-800">{{ t('purchase_submissions.submit_title') }}</h2>
          <p class="text-sm text-neutral-500">{{ t('purchase_submissions.submit_hint') }}</p>
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
          <span class="block mb-1">{{ t('purchase_submissions.note') }}</span>
          <input v-model="note" maxlength="8000" class="w-full h-10 px-3 border border-neutral-300 rounded-md bg-surface"
            :placeholder="t('purchase_submissions.note_placeholder')" />
        </label>
        <label v-for="type in dimensionTypes" :key="type.id" class="block text-sm text-neutral-700" data-test="portal-dimension">
          <span class="block mb-1">{{ t('purchase_submissions.dimension_optional', { type: type.name }) }}</span>
          <select v-model="chosenDims[type.id]" class="w-full h-10 px-3 border border-neutral-300 rounded-md bg-surface">
            <option :value="null">{{ t('purchase_submissions.dimension_none') }}</option>
            <option v-for="value in type.values" :key="value.id" :value="value.id">{{ valueLabel(value) }}</option>
          </select>
        </label>
      </div>

      <div class="flex flex-col gap-3">
      <label
        class="block cursor-pointer rounded-lg border-2 border-dashed p-6 text-center transition"
        :class="[
          dragOver ? 'border-primary-500 bg-primary-50' : 'border-neutral-300 hover:border-primary-400 hover:bg-primary-50/30',
          uploading ? 'pointer-events-none opacity-60' : '',
        ]"
        data-test="portal-dropzone"
        @dragenter.prevent="dragOver = true"
        @dragover.prevent="dragOver = true"
        @dragleave.prevent="dragOver = false"
        @drop.prevent="onDrop"
      >
        <input ref="fileInput" type="file" multiple :accept="allowed" class="hidden" :disabled="uploading" @change="selectFiles" />
        <svg class="mx-auto mb-2 h-8 w-8" :class="dragOver ? 'text-primary-500' : 'text-neutral-400'" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
          <path stroke-linecap="round" stroke-linejoin="round" d="M7 16a4 4 0 0 1-.88-7.9 5 5 0 0 1 9.9-1A5.5 5.5 0 0 1 18.5 16H17m-5-4v9m0-9l-3 3m3-3l3 3" />
        </svg>
        <span class="block text-sm font-medium" :class="dragOver ? 'text-primary-700' : 'text-neutral-700'">{{ t('purchase_submissions.drop_title') }}</span>
        <span class="mt-1 block text-xs text-neutral-500">{{ t('purchase_submissions.drop_hint') }}</span>
      </label>
      <div class="flex flex-wrap items-center gap-3">
        <CapturePhotoButton :disabled="uploading" @captured="(f: File) => addFiles([f])" />
        <span v-if="files.length" class="text-sm text-neutral-600">
          {{ t('purchase_submissions.files_selected', { n: files.length }) }}
        </span>
        <button type="button" :disabled="files.length === 0 || uploading" :class="btnFilled('primary')" @click="submit">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M5 12l4 4L19 6" />
          </svg>
          {{ uploading ? t('purchase_submissions.uploading') : t('purchase_submissions.submit_action') }}
        </button>
      </div>
      <ul v-if="files.length" class="flex flex-wrap gap-2 text-xs text-neutral-600" data-test="portal-selected-files">
        <li v-for="file in files" :key="`${file.name}|${file.size}`" class="inline-flex items-center gap-1 rounded bg-neutral-100 py-1 pl-2 pr-1">
          {{ file.name }}
          <button type="button" class="rounded p-0.5 text-neutral-500 hover:bg-neutral-200 hover:text-danger-600 disabled:opacity-50"
                  :disabled="uploading" :aria-label="t('purchase_submissions.remove_file', { name: file.name })"
                  data-test="portal-remove-file" @click="removeFile(file)">
            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>
          </button>
        </li>
      </ul>
      </div>
    </section>

    <div v-if="error" class="rounded-md bg-danger-50 border border-danger-500/40 px-3 py-2 text-sm text-danger-600">{{ error }}</div>
    <div v-if="loading" class="text-center text-neutral-500 py-10">{{ t('common.loading') }}</div>

    <div v-else :class="previewItem && widePreview ? 'grid grid-cols-[minmax(320px,1fr)_minmax(0,1fr)] gap-4 items-start' : ''">
      <div class="space-y-5 min-w-0">
      <section class="space-y-3">
        <h2 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('purchase_submissions.open_title') }}</h2>
        <EmptyState v-if="openItems.length === 0" boxed accent="success" icon="checkCircle" :title="t('purchase_submissions.empty')" />
        <article v-for="item in openItems" :key="item.id" class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-4">
          <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
              <div class="flex flex-wrap items-center gap-2">
                <span class="font-medium truncate">{{ item.original_name }}</span>
                <span class="text-xs px-2 py-0.5 rounded font-medium" :class="itemBadge(item)">
                  {{ itemStatus(item) }}
                </span>
              </div>
              <p class="text-xs text-neutral-500 mt-1">{{ size(item.size_bytes) }} · {{ new Date(item.created_at).toLocaleString() }}</p>
              <p v-if="item.note" class="text-sm text-neutral-600 mt-2">{{ item.note }}</p>
              <p v-if="dimensionSummary(item)" class="text-xs text-neutral-500 mt-1">{{ t('purchase_submissions.dimensions_chosen', { list: dimensionSummary(item) }) }}</p>
              <p v-if="item.status_reason" class="text-sm text-danger-600 mt-2">{{ item.status_reason }}</p>
            </div>
            <div class="flex flex-wrap gap-2">
              <button type="button" data-test="portal-preview" :class="btnOutline('neutral')" @click="previewId = item.id">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12zm10 3a3 3 0 100-6 3 3 0 000 6z" /></svg>
                {{ t('purchase_submissions.preview') }}
              </button>
              <button v-if="item.can_delete && auth.canWrite('documents.submit')" type="button" data-test="portal-delete"
                :disabled="deletingId !== null || replacingId !== null" :class="btnOutline('danger')" @click="remove(item)">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.trash" /></svg>
                {{ t('purchase_submissions.portal_delete') }}
              </button>
              <label v-if="canReplace(item)" :class="btnFilled('primary')">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 16V4m0 0L7 9m5-5 5 5M5 14v5a1 1 0 001 1h12a1 1 0 001-1v-5" /></svg>
                {{ replacingId === item.id ? t('purchase_submissions.uploading') : t('purchase_submissions.replace') }}
                <input type="file" :accept="allowed" class="hidden" data-test="portal-replace" :disabled="replacingId !== null || deletingId !== null" @change="resubmit(item, $event)" />
              </label>
            </div>
          </div>
        </article>
      </section>

      <section v-if="closedItems.length" class="space-y-2">
        <h2 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('purchase_submissions.closed_title') }}</h2>
        <div class="bg-surface border border-neutral-200 rounded-lg divide-y divide-neutral-100">
          <div v-for="item in closedItems" :key="item.id" class="p-3 flex flex-wrap items-center justify-between gap-2 text-sm">
            <div class="min-w-0">
              <span class="break-words">{{ item.original_name }}</span>
              <p v-if="item.status_reason" class="text-sm text-danger-600 mt-1">{{ item.status_reason }}</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
              <button type="button" data-test="portal-preview" :class="btnOutline('neutral')" @click="previewId = item.id">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.eye" /></svg>
                {{ t('purchase_submissions.preview') }}
              </button>
              <label v-if="canReplace(item)" :class="btnOutline('primary')">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.upload" /></svg>
                {{ replacingId === item.id ? t('purchase_submissions.uploading') : t('purchase_submissions.replace') }}
                <input type="file" :accept="allowed" class="hidden" data-test="portal-replace" :disabled="replacingId !== null || deletingId !== null" @change="resubmit(item, $event)" />
              </label>
              <a :href="portalPurchaseInvoiceSubmissionsApi.downloadUrl(item.id)" class="text-neutral-600 hover:underline">
                {{ t('purchase_submissions.download') }}
              </a>
              <RouterLink v-if="item.purchase_invoice_id && auth.canRead('purchase_invoices')" :to="`/purchase-invoices/${item.purchase_invoice_id}`" class="text-primary-700 hover:underline">
                {{ t('purchase_submissions.open_invoice') }}
              </RouterLink>
              <span class="text-xs px-2 py-0.5 rounded font-medium" :class="itemBadge(item)">{{ itemStatus(item) }}</span>
            </div>
          </div>
        </div>
      </section>
      </div>
      <aside v-if="previewItem && widePreview" data-test="portal-preview-pane"
        class="min-w-0 bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden sticky top-[calc(var(--instance-alert-h,0px)+3.5rem)]">
        <div class="flex flex-wrap items-center justify-between gap-2 p-3 border-b border-neutral-200">
          <h2 class="font-semibold text-sm min-w-0 break-all">{{ previewItem.original_name }}</h2>
          <button type="button" data-test="portal-preview-close" :class="btnOutline('neutral')" @click="previewId = null">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>
            {{ t('common.close') }}
          </button>
        </div>
        <SubmissionPreview :src="portalPurchaseInvoiceSubmissionsApi.previewUrl(previewItem.id)"
          :file-name="previewItem.original_name" :doc-type="previewItem.doc_type" />
      </aside>
    </div>
    <Modal v-if="previewItem && !widePreview" :title="previewItem.original_name" width-class="max-w-5xl" @close="previewId = null">
      <SubmissionPreview :src="portalPurchaseInvoiceSubmissionsApi.previewUrl(previewItem.id)"
        :file-name="previewItem.original_name" :doc-type="previewItem.doc_type" />
    </Modal>
    <PhotoCropDialog v-if="crop.current.value" :key="crop.step.value" :file="crop.current.value" from-file
      :step="crop.step.value" :steps="crop.steps.value" :skip-count="crop.remaining.value"
      @done="crop.done" @skip-rest="crop.skipRest" @cancel="crop.cancel" />
  </div>
</template>

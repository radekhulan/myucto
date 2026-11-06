<script setup lang="ts">
import { ref, computed, watch, onBeforeUnmount } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { integrationsApi, type AiExtractResult, type AiExtractionStatus, type AiProvider } from '@/api/integrations'
import { purchaseInvoicesApi, type ImportBatch, type PurchaseDocumentKind } from '@/api/purchaseInvoices'
import { useToast } from '@/composables/useToast'
import { formatDate } from '@/composables/useFormat'
import { apiErrorCode, apiErrorMessage } from '@/api/errors'
import AiNotConfiguredNotice from '@/components/purchase/AiNotConfiguredNotice.vue'
import { useAuthStore } from '@/stores/auth'
import { useSupplierStore } from '@/stores/supplier'
import { canUsePurchaseAiExtraction } from '@/security/purchaseAiExtraction'
import { ICONS, btnFilled, btnOutlineSm } from '@/components/ui/buttonStyles'
import ExtractionReviewModal from '@/components/purchase/ExtractionReviewModal.vue'
import CapturePhotoButton from '@/components/upload/CapturePhotoButton.vue'
import PhotoCropDialog from '@/components/upload/PhotoCropDialog.vue'
import { isPhotoFile } from '@/composables/usePhotoCapture'
import MergeDocumentsPanel from '@/components/upload/MergeDocumentsPanel.vue'

const { t } = useI18n()
const toast = useToast()
const router = useRouter()
const auth = useAuthStore()
const suppliers = useSupplierStore()
let contextVersion = 0
onBeforeUnmount(() => { contextVersion++ })

// ── AI import přijaté faktury (§12b) — extrakční flow vytažený z admin
// Integrations (?tab=ai). Nastavení brány (provideři, klíče, DPA) zůstává
// v adminu; tady je jen denní operativa účetní: nahrát doklad → extrakce →
// draft přijaté faktury.
const aiCreds = ref<AiExtractionStatus | null>(null)
const providerInfo = computed(() => aiCreds.value?.providers?.[aiCreds.value.ai_provider])
const models = computed(() => providerInfo.value?.models ?? [])
const aiConfigured = computed(() => !!providerInfo.value?.configured)
const canExtract = computed(() => canUsePurchaseAiExtraction(auth, {
  client_ai_extraction_available: aiCreds.value?.client_ai_extraction_enabled === true && aiConfigured.value,
}))
const clientUsage = computed(() => aiCreds.value?.client_ai_extraction_usage)
const quotaRejected = ref(false)
const quotaExhausted = computed(() => auth.isClientRole && (quotaRejected.value || (clientUsage.value?.remaining ?? 1) <= 0))
const canStartExtraction = computed(() => canExtract.value && !quotaExhausted.value)
const requestModel = computed(() => auth.isClientRole ? undefined : aiPerRequestModel.value || undefined)
const canEditKind = computed(() => auth.canWrite('purchase_invoices'))
const aiDefaultModel = computed(() => providerInfo.value?.default_model ?? '')
const credsLoaded = ref(false)

function providerLabel(p: AiProvider): string { return t(`aiGateway.provider.${p}`) }

async function loadAiCreds() {
  const version = contextVersion
  try {
    const status = await integrationsApi.getAiExtractionStatus('purchase')
    if (version !== contextVersion) return
    aiCreds.value = status
  } catch {
    // Bez oprávnění / brána nenasazená — degradujeme defenzivně (zobrazí se "není nakonfigurováno").
    if (version === contextVersion) aiCreds.value = null
  } finally {
    if (version === contextVersion) credsLoaded.value = true
  }
}

const aiPdfFile = ref<File | null>(null)
const aiExtracting = ref(false)
const aiResult = ref<AiExtractResult | null>(null)
const aiPerRequestModel = ref('')  // empty = použít default

/** Fotky z výběru čekající na ořez, zpracovávají se po jedné. */
const cropQueue = ref<File[]>([])
const cropIndex = ref(0)
const cropResults = ref<File[]>([])
/** Celý výběr; výřezy se do něj vrátí na svá místa a extrakce se spustí najednou. */
let cropSelection: File[] = []
const cropCurrent = computed<File | null>(() => cropQueue.value[cropIndex.value] ?? null)

/**
 * Fotka papírového dokladu jde nejdřív do ořezu: server fotku jen zmenší, takže
 * čím víc je v záběru stolu, tím míň pixelů zbyde na čísla, která z dokladu čte
 * AI. Z „Vyfotit“ přichází fotka ořezaná už z dialogu, proto tlačítko volá
 * {@link useAiFiles} a tudy nechodí.
 */
function acceptAiFiles(files: File[]) {
  if (!canStartExtraction.value || aiExtracting.value || aiBatchRunning.value || files.length === 0) return
  const photos = files.filter(isPhotoFile)
  if (photos.length === 0) {
    useAiFiles(files)
    return
  }
  cropSelection = files
  cropQueue.value = photos
  cropIndex.value = 0
  cropResults.value = []
}

/** Hotová fotka; po poslední se výřezy vrátí na svá místa v původním výběru. */
function onCropDone(file: File) {
  cropResults.value = [...cropResults.value, file]
  if (cropIndex.value + 1 < cropQueue.value.length) {
    cropIndex.value += 1
    return
  }
  const queue = [...cropResults.value]
  const merged = cropSelection.map(f => (isPhotoFile(f) ? queue.shift() ?? f : f))
  resetCrop()
  useAiFiles(merged)
}

/** Zrušení ořezu zahodí celý výběr — uživatel chtěl začít znovu, ne spustit extrakci. */
function resetCrop() {
  cropSelection = []
  cropQueue.value = []
  cropIndex.value = 0
  cropResults.value = []
}

// Přijme 1..N souborů z file-pickeru i drag&drop: 1 = single-file flow, více = dávka.
function useAiFiles(files: File[]) {
  if (!canStartExtraction.value || aiExtracting.value || aiBatchRunning.value || files.length === 0) return
  const pdfs = files.filter(f =>
    f.type === 'application/pdf' || f.type.startsWith('image/') ||
    /\.(pdf|jpe?g|png|webp|heic|heif|gif|bmp|isdoc|isdocx)$/i.test(f.name))
  if (pdfs.length === 0) {
    toast.error(t('integrations.ai.only_pdf'))
    return
  }
  if (pdfs.length === 1) {
    aiPdfFile.value = pdfs[0]
    aiBatchQueue.value = []
    aiBatchId.value = ''
  } else {
    aiBatchQueue.value = pdfs.map(f => ({ file: f, status: 'pending' as const, result: null }))
    aiBatchId.value = newBatchId()
    aiPdfFile.value = null
  }
  aiResult.value = null
}

function onAiPdfPick(e: Event) {
  const input = e.target as HTMLInputElement
  acceptAiFiles(Array.from(input.files ?? []))
  // Reset hodnoty inputu, ať jde znovu vybrat týž soubor (change se jinak nespustí).
  input.value = ''
}

// Drag & drop handlers (browser default = open PDF in tab; preventDefault zastaví)
const aiDragOver = ref(false)

// Batch queue — vícero PDF naráz, processed serial (1 v čase) aby se nepřetížil
// Anthropic API rate limit. Status: pending → processing → ok | failed.
interface BatchItem {
  file: File
  status: 'pending' | 'processing' | 'ok' | 'failed'
  result: any
}
const aiBatchQueue = ref<BatchItem[]>([])
const aiBatchRunning = ref(false)
// Identifikátor dávky (#232) — protáhne se do každého importu, ať jde celá dávka
// po dokončení dohledat/filtrovat v seznamu přijatých faktur.
const aiBatchId = ref('')
const kindBusyKey = ref<string | null>(null)

function newBatchId(): string {
  const uuid = (typeof crypto !== 'undefined' && crypto.randomUUID)
    ? crypto.randomUUID()
    : `${Date.now().toString(36)}${Math.random().toString(36).slice(2)}`
  return uuid.replace(/-/g, '').slice(0, 32)
}

async function runAiBatch() {
  if (!canStartExtraction.value || aiExtracting.value || aiBatchRunning.value || aiBatchQueue.value.length === 0) return
  const version = contextVersion
  if (!aiBatchId.value) aiBatchId.value = newBatchId()
  aiBatchRunning.value = true
  try {
    for (const item of aiBatchQueue.value) {
      if (version !== contextVersion) return
      if (!canStartExtraction.value) break
      if (item.status === 'ok') continue // skip already done (idempotent re-run)
      item.status = 'processing'
      try {
        const model = requestModel.value
        const r = await integrationsApi.extractPdfAi(item.file, model, aiBatchId.value)
        if (version !== contextVersion) return
        item.result = r
        item.status = r.ok ? 'ok' : 'failed'
      } catch (e: any) {
        if (version !== contextVersion) return
        item.result = e?.response?.data ?? { error: { message: apiErrorMessage(e) } }
        item.status = 'failed'
        if (apiErrorCode(e) === 'client_ai_extraction_limit_reached') {
          quotaRejected.value = true
          toast.error(t('aiGateway.client_extraction_limit_reached'))
        }
      }
      // Chyba i duplicita mohou spotřebovat pokus; před dalším souborem načteme skutečné čerpání.
      if (auth.isClientRole) await loadAiCreds()
      if (version !== contextVersion) return
    }
    await Promise.all([loadAiCreds(), loadLastBatch()])
    if (version !== contextVersion) return
    toast.success(t('integrations.ai.batch_done', { n: aiBatchQueue.value.filter(x => x.status === 'ok').length }))
    openReview(batchInvoiceIds.value)
  } finally {
    if (version === contextVersion) aiBatchRunning.value = false
  }
}

// Kontrola vytěžených dokladů faktura po faktuře — otevře se sama po importu,
// okno samo přeskočí doklady bez hlášení (a když žádný nezbude, jen to oznámí).
const reviewIds = ref<number[] | null>(null)
const batchInvoiceIds = computed(() => aiBatchQueue.value
  .filter(x => x.status === 'ok' && x.result?.purchase_invoice_id && !x.result?.duplicate && !x.result?.target_supplier_id)
  .map(x => x.result.purchase_invoice_id as number))
function openReview(ids: number[]) {
  if (ids.length) reviewIds.value = ids
}

const batchOkCount = computed(() => aiBatchQueue.value.filter(x => x.status === 'ok').length)
const batchFailedCount = computed(() => aiBatchQueue.value.filter(x => x.status === 'failed').length)
const batchDone = computed(() =>
  aiBatchQueue.value.length > 0 && aiBatchQueue.value.every(x => x.status === 'ok' || x.status === 'failed'))

// Inline oprava typu dokladu po importu (#232) — AI účtenku klasifikuje jako
// „Účtenka / paragon", účetní ji přehodí na „Faktura" bez otevírání detailu.
async function changeKind(result: { purchase_invoice_id?: number; document_kind?: string } | null, key: string, kind: PurchaseDocumentKind) {
  const id = result?.purchase_invoice_id
  if (!result || !id || !canEditKind.value) return
  const version = contextVersion
  kindBusyKey.value = key
  try {
    await purchaseInvoicesApi.setDocumentKind(id, kind)
    if (version !== contextVersion) return
    result.document_kind = kind
    toast.success(t('integrations.ai.kind_changed'))
  } catch (e) {
    toast.error(apiErrorMessage(e))
  } finally {
    if (version === contextVersion) kindBusyKey.value = null
  }
}

function batchListLink(batchId: string) {
  return { path: '/purchase-invoices', query: { import_batch: batchId } }
}

// Poslední import — zůstane dohledatelný i po odchodu ze stránky.
const lastBatch = ref<ImportBatch | null>(null)
async function loadLastBatch() {
  const version = contextVersion
  try {
    const batches = await purchaseInvoicesApi.listImportBatches(1)
    if (version === contextVersion) lastBatch.value = batches[0] ?? null
  } catch {
    if (version === contextVersion) lastBatch.value = null
  }
}

function clearBatch() {
  aiBatchQueue.value = []
  aiBatchId.value = ''
}
function onAiDragEnter(e: DragEvent) { e.preventDefault(); aiDragOver.value = true }
function onAiDragOver(e: DragEvent) {
  e.preventDefault()
  if (e.dataTransfer) e.dataTransfer.dropEffect = 'copy'
}
function onAiDragLeave(e: DragEvent) {
  if (e.target === e.currentTarget) aiDragOver.value = false
}
function onAiDrop(e: DragEvent) {
  e.preventDefault()
  aiDragOver.value = false
  acceptAiFiles(Array.from(e.dataTransfer?.files ?? []))
}

async function runAiExtract() {
  if (!canStartExtraction.value || !aiPdfFile.value || aiExtracting.value || aiBatchRunning.value) return
  const version = contextVersion
  aiExtracting.value = true
  aiResult.value = null
  try {
    const model = requestModel.value
    // I jednotlivý doklad je dávka (o jednom kusu) — jinak by ho „Poslední import" neukázal.
    const result = await integrationsApi.extractPdfAi(aiPdfFile.value, model, newBatchId())
    if (version !== contextVersion) return
    aiResult.value = result
    if (aiResult.value.ok) {
      toast.success(t('integrations.ai.extract_success'))
      await loadLastBatch()
      if (version !== contextVersion) return
      if (aiResult.value?.purchase_invoice_id && !aiResult.value.duplicate && !aiResult.value.target_supplier_id) openReview([aiResult.value.purchase_invoice_id])
    }
  } catch (e: any) {
    if (version !== contextVersion) return
    if (apiErrorCode(e) === 'client_ai_extraction_limit_reached') {
      quotaRejected.value = true
      toast.error(t('aiGateway.client_extraction_limit_reached'))
      return
    }
    if (apiErrorCode(e) === 'ai_not_configured') {
      toast.error(apiErrorMessage(e))
      await loadAiCreds()
      return
    }
    // Server vrátil 422 (extraction_failed) — extract ai_data ze response
    const respData = e?.response?.data
    if (respData?.error?.details) {
      aiResult.value = { ok: false, ...respData.error.details, error: respData.error.message, source: respData.error.details?.source ?? 'ai_failed' }
    } else {
      toast.error(apiErrorMessage(e))
    }
  } finally {
    if (version === contextVersion) {
      await loadAiCreds()
      if (version === contextVersion) aiExtracting.value = false
    }
  }
}

// Doklad spojený z více částí jde do stejného vytěžení jako jeden nahraný soubor.
function importMerged(file: File) {
  if (!canStartExtraction.value || aiExtracting.value || aiBatchRunning.value) return
  aiBatchQueue.value = []
  aiBatchId.value = ''
  aiPdfFile.value = file
  aiResult.value = null
  void runAiExtract()
}

/** Doklad jiné firmy: kde skončil (faktura v ní, nebo její příchozí doklady). */
function targetLabel(result: AiExtractResult): string {
  const company = result.target_supplier_name ?? ''
  return result.source === 'handed_to_inbox'
    ? t('integrations.ai.handed_to_inbox', { company })
    : t('integrations.ai.created_in_company', { company })
}

function gotoInvoice(id: number) {
  router.push(`/purchase-invoices/${id}`)
}

watch(() => suppliers.currentSupplierId, () => {
  contextVersion++
  aiCreds.value = null
  quotaRejected.value = false
  credsLoaded.value = false
  aiPdfFile.value = null
  aiResult.value = null
  aiPerRequestModel.value = ''
  aiBatchQueue.value = []
  aiBatchId.value = ''
  aiBatchRunning.value = false
  aiExtracting.value = false
  kindBusyKey.value = null
  reviewIds.value = null
  lastBatch.value = null
  void loadAiCreds()
  void loadLastBatch()
}, { immediate: true })
</script>

<template>
  <div class="max-w-4xl">
    <div class="mb-4">
      <h1 class="text-2xl font-semibold">{{ t('integrations.ai.import_title') }}</h1>
      <p class="text-sm text-neutral-500 mt-0.5">{{ t('integrations.ai.import_subtitle') }}</p>
    </div>

    <div v-if="lastBatch" class="mb-4 flex items-center justify-between gap-2 flex-wrap rounded-md border border-neutral-200 bg-surface px-4 py-2.5 text-sm">
      <span class="text-neutral-700">
        {{ t('integrations.ai.last_import', { date: formatDate(lastBatch.created_at), n: lastBatch.count }) }}
      </span>
      <RouterLink :to="batchListLink(lastBatch.import_batch_id)" :class="[btnOutlineSm('primary'), 'whitespace-nowrap']">
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.table" /></svg>
        {{ t('integrations.ai.show_in_list') }}
      </RouterLink>
    </div>

    <!-- Aktivní provider není nakonfigurován — odkaz na admin nastavení (jen kdo smí) -->
    <p v-if="credsLoaded && auth.isClientRole && !canExtract" data-test="client-ai-unavailable"
       class="rounded-md bg-warning-50 border border-warning-500/40 px-4 py-3 text-sm text-warning-700">
      {{ t('aiGateway.client_extraction_unavailable') }}
    </p>
    <AiNotConfiguredNotice v-else-if="credsLoaded && !aiConfigured" />

    <div v-if="auth.isClientRole && clientUsage" data-test="client-ai-usage" class="mb-4 rounded-md border border-neutral-200 bg-surface p-4 text-sm space-y-2">
      <p>{{ t('aiGateway.client_extraction_usage', clientUsage) }}</p>
      <p class="text-xs text-neutral-500">{{ t('aiGateway.client_extraction_usage_hint') }}</p>
    </div>
    <p v-if="quotaExhausted" data-test="client-ai-limit-reached" class="mb-4 rounded-md border border-warning-500/40 bg-warning-50 px-4 py-3 text-sm text-warning-700">
      {{ t('aiGateway.client_extraction_limit_reached') }}
    </p>

    <!-- AI PDF extract (primární akce — jen když je aktivní provider nakonfigurován) -->
    <div v-if="canExtract && aiConfigured" data-test="ai-extraction-upload" class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm">
      <h2 class="text-sm font-medium text-neutral-700 mb-2">{{ t('integrations.ai.extract_title') }}</h2>
      <p class="text-xs text-neutral-500 mb-4">{{ t('integrations.ai.extract_hint') }}</p>

      <div class="space-y-3">
        <div v-if="!quotaExhausted" class="flex flex-wrap gap-2 justify-end">
          <!-- Z „Vyfotit“ jde fotka už ořezaná, do ořezu podruhé nemá chodit. -->
          <CapturePhotoButton :disabled="aiExtracting || aiBatchRunning" @captured="(f: File) => useAiFiles([f])" />
        </div>
        <label class="block border-2 border-dashed rounded-lg p-6 text-center cursor-pointer transition"
          :class="aiDragOver
            ? 'border-primary-500 bg-primary-50'
            : 'border-neutral-300 hover:border-primary-400 hover:bg-primary-50/30'"
          @dragenter="onAiDragEnter" @dragover="onAiDragOver" @dragleave="onAiDragLeave" @drop="onAiDrop">
          <input type="file" :disabled="quotaExhausted" multiple accept="application/pdf,.pdf,image/*,.isdoc,.isdocx" @change="onAiPdfPick" class="hidden" />
          <svg class="w-8 h-8 mx-auto text-neutral-400 mb-2" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
            <path stroke-linecap="round" stroke-linejoin="round" d="M7 16a4 4 0 0 1-.88-7.9 5 5 0 0 1 9.9-1A5.5 5.5 0 0 1 18.5 16H17m-5-4v9m0-9l-3 3m3-3l3 3" />
          </svg>
          <div class="text-sm font-medium text-neutral-700">
            {{ aiPdfFile ? aiPdfFile.name : t('integrations.ai.drop_pdf') }}
          </div>
          <div v-if="aiPdfFile" class="text-xs text-neutral-500 mt-1">{{ Math.round(aiPdfFile.size / 1024) }} kB</div>
        </label>

        <div v-if="!auth.isClientRole" data-test="ai-model-override" class="flex flex-wrap items-center gap-2">
          <label class="text-sm text-neutral-700">{{ t('integrations.ai.model_override') }}</label>
          <select v-model="aiPerRequestModel" class="h-9 px-2 border border-neutral-300 rounded-md bg-surface text-sm">
            <option value="">{{ t('integrations.ai.use_default') }} ({{ aiDefaultModel }})</option>
            <option v-for="m in models" :key="m" :value="m">{{ m }}</option>
          </select>
        </div>

        <button type="button" @click="runAiExtract" :disabled="!canStartExtraction || !aiPdfFile || aiExtracting || aiBatchRunning"
                :class="[btnFilled('primary'), 'w-full whitespace-nowrap']">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path :d="ICONS.upload" /></svg>
          {{ aiExtracting ? t('integrations.ai.extracting') : t('integrations.ai.run_extract') }}
        </button>
      </div>

      <!-- Batch queue (multi-file drop) — processed serial -->
      <div v-if="aiBatchQueue.length > 0" class="mt-4 pt-4 border-t border-neutral-100">
        <div class="flex flex-wrap gap-2 items-center justify-between mb-2">
          <h3 class="text-sm font-medium text-neutral-700">
            {{ t('integrations.ai.batch_title', { n: aiBatchQueue.length }) }}
          </h3>
          <button type="button" @click="clearBatch" :disabled="aiBatchRunning"
                  :class="[btnOutlineSm('neutral'), 'whitespace-nowrap']">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path :d="ICONS.x" /></svg>
            {{ t('common.cancel') }}
          </button>
        </div>
        <div class="overflow-x-auto max-h-96 overflow-y-auto border border-neutral-200 rounded-md">
          <table class="w-full text-xs">
            <thead>
              <tr class="bg-neutral-50 text-neutral-500 text-left">
                <th class="px-2 py-1.5 font-medium">{{ t('integrations.ai.col_file') }}</th>
                <th class="px-2 py-1.5 font-medium">{{ t('integrations.ai.col_status') }}</th>
                <th class="px-2 py-1.5 font-medium">{{ t('integrations.ai.col_vendor') }}</th>
                <th class="px-2 py-1.5 font-medium text-right">{{ t('integrations.ai.col_amount') }}</th>
                <th class="px-2 py-1.5 font-medium">{{ t('integrations.ai.col_kind') }}</th>
                <th class="px-2 py-1.5 font-medium text-right"></th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="(item, idx) in aiBatchQueue" :key="idx" class="align-middle">
                <td class="px-2 py-1.5 font-mono max-w-[10rem] truncate" :title="item.file.name">{{ item.file.name }}</td>
                <td class="px-2 py-1.5 whitespace-nowrap">
                  <span :class="[
                    'px-2 py-0.5 rounded text-[10px] font-medium uppercase tracking-wide',
                    item.status === 'ok' ? 'bg-success-50 text-success-600' :
                    item.status === 'failed' ? 'bg-danger-50 text-danger-500' :
                    item.status === 'processing' ? 'bg-warning-50 text-warning-600' :
                    'bg-neutral-100 text-neutral-500']">
                    {{ item.status === 'processing' ? '…' : item.status }}
                  </span>
                  <span v-if="item.status === 'ok' && item.result?.duplicate" class="ml-1 text-[10px] text-neutral-400">
                    ({{ t('integrations.ai.duplicate') }})
                  </span>
                </td>
                <td class="px-2 py-1.5 text-neutral-700 max-w-[9rem] truncate" :title="item.result?.vendor_name || ''">
                  {{ item.result?.vendor_name || '—' }}
                </td>
                <td class="px-2 py-1.5 text-right whitespace-nowrap text-neutral-700">
                  <template v-if="item.result?.total_with_vat != null">
                    {{ Math.round(item.result.total_with_vat).toLocaleString('cs-CZ') }} {{ item.result?.currency || '' }}
                  </template>
                  <template v-else>—</template>
                </td>
                <td class="px-2 py-1.5">
                  <select v-if="canEditKind && item.status === 'ok' && item.result?.purchase_invoice_id && !item.result?.target_supplier_id && item.result?.document_kind !== 'advance'"
                    :value="item.result.document_kind"
                    :disabled="kindBusyKey === `b${idx}`"
                    @change="changeKind(item.result, `b${idx}`, ($event.target as HTMLSelectElement).value as PurchaseDocumentKind)"
                    class="h-7 px-1.5 border border-neutral-300 rounded bg-surface text-[11px] disabled:opacity-50">
                    <option value="invoice">{{ t('purchase_invoice.document_kind.invoice') }}</option>
                    <option value="receipt">{{ t('purchase_invoice.document_kind.receipt') }}</option>
                    <option value="credit_note">{{ t('purchase_invoice.document_kind.credit_note') }}</option>
                    <option v-if="item.result.document_kind === 'tax_document'" value="tax_document" disabled>{{ t('purchase_invoice.document_kind.tax_document') }}</option>
                  </select>
                  <span v-else-if="item.result?.document_kind === 'advance'" class="text-[11px] text-neutral-500">
                    {{ t('purchase_invoice.document_kind.advance') }}
                  </span>
                  <span v-else class="text-neutral-300">—</span>
                </td>
                <td class="px-2 py-1.5 text-right">
                  <span v-if="item.status === 'ok' && item.result?.target_supplier_id" class="text-[11px] text-primary-700 whitespace-nowrap">
                    {{ targetLabel(item.result) }}
                  </span>
                  <RouterLink v-else-if="item.status === 'ok' && item.result?.purchase_invoice_id"
                    :to="`/purchase-invoices/${item.result.purchase_invoice_id}`"
                    class="text-primary-600 hover:underline whitespace-nowrap">
                    {{ t('integrations.ai.open') }} #{{ item.result.purchase_invoice_id }}
                  </RouterLink>
                  <span v-else-if="item.status === 'failed'" class="text-danger-500 text-[11px]"
                    :title="typeof item.result?.error === 'string' ? item.result.error : (item.result?.error?.message || '')">
                    {{ t('integrations.ai.failed_short') }}
                  </span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <button type="button" @click="runAiBatch" :disabled="!canStartExtraction || aiBatchRunning || aiExtracting"
                :class="[btnFilled('primary'), 'mt-3 w-full whitespace-nowrap']">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path :d="ICONS.upload" /></svg>
          {{ aiBatchRunning ? t('integrations.ai.batch_running') : t('integrations.ai.batch_run') }}
        </button>

        <!-- Souhrn dokončené dávky + proklik na celou dávku v seznamu přijatých -->
        <div v-if="batchDone && aiBatchId" class="mt-3 flex items-center justify-between gap-2 flex-wrap rounded-md bg-success-50 border border-success-500/40 px-3 py-2">
          <span class="text-sm text-success-700">
            ✓ {{ t('integrations.ai.batch_summary', { ok: batchOkCount, failed: batchFailedCount }) }}
          </span>
          <div class="flex flex-wrap gap-2">
            <button v-if="batchInvoiceIds.length" type="button" :class="[btnOutlineSm('warning'), 'whitespace-nowrap']" @click="openReview(batchInvoiceIds)">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5 2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
              {{ t('purchase_invoice.extraction_review.open_count', { count: batchInvoiceIds.length }) }}
            </button>
            <RouterLink :to="batchListLink(aiBatchId)" :class="[btnOutlineSm('primary'), 'whitespace-nowrap']">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.table" /></svg>
              {{ t('integrations.ai.show_in_list') }}
            </RouterLink>
          </div>
        </div>
        <p class="text-xs text-neutral-500 mt-2">
          ℹ {{ t('integrations.ai.batch_serial_hint') }}
        </p>
      </div>

      <div v-if="aiResult" class="mt-4 pt-4 border-t border-neutral-100">
        <div v-if="aiResult.ok" class="rounded-md bg-success-50 border border-success-500/40 px-3 py-2 text-sm text-success-600">
          <strong>✓ {{ t('integrations.ai.extract_success') }}</strong>
          <span v-if="aiResult.target_supplier_id" class="ml-3">{{ targetLabel(aiResult) }}</span>
          <button v-if="aiResult.purchase_invoice_id && !aiResult.target_supplier_id" type="button" @click="gotoInvoice(aiResult.purchase_invoice_id!)"
                  class="ml-3 cursor-pointer underline hover:text-success-700">
            {{ t('integrations.ai.go_to_invoice') }} #{{ aiResult.purchase_invoice_id }}
          </button>
          <button v-if="aiResult.purchase_invoice_id && !aiResult.duplicate && !aiResult.target_supplier_id" type="button"
                  @click="openReview([aiResult.purchase_invoice_id!])"
                  class="ml-3 cursor-pointer underline hover:text-success-700">
            {{ t('purchase_invoice.extraction_review.open') }}
          </button>
          <div v-if="canEditKind && aiResult.purchase_invoice_id && !aiResult.target_supplier_id && aiResult.document_kind && aiResult.document_kind !== 'advance'"
               class="mt-2 flex items-center gap-2 flex-wrap text-neutral-700">
            <label class="text-xs">{{ t('integrations.ai.col_kind') }}</label>
            <select :value="aiResult.document_kind"
              :disabled="kindBusyKey === 'single'"
              @change="changeKind(aiResult, 'single', ($event.target as HTMLSelectElement).value as PurchaseDocumentKind)"
              class="h-8 px-2 border border-neutral-300 rounded bg-surface text-xs disabled:opacity-50">
              <option value="invoice">{{ t('purchase_invoice.document_kind.invoice') }}</option>
              <option value="receipt">{{ t('purchase_invoice.document_kind.receipt') }}</option>
              <option value="credit_note">{{ t('purchase_invoice.document_kind.credit_note') }}</option>
              <option v-if="aiResult.document_kind === 'tax_document'" value="tax_document" disabled>{{ t('purchase_invoice.document_kind.tax_document') }}</option>
            </select>
          </div>
          <!-- Provenance badge row (Epic F7): source (ISDOC = zelená preferovaná cesta | AI) + provider/model/region -->
          <div class="mt-2 flex flex-wrap items-center gap-1.5">
            <span :class="['px-1.5 py-0.5 rounded text-[10px] font-semibold border inline-flex items-center gap-1',
              (aiResult.source === 'isdocx' || aiResult.source === 'isdoc_embedded') ? 'bg-success-50 text-success-700 border-success-500/40'
              : aiResult.source === 'duplicate' ? 'bg-neutral-100 text-neutral-500 border-neutral-200'
              : 'bg-accent-50 text-accent-700 border-accent-500/40']">
              <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" :d="(aiResult.source === 'isdocx' || aiResult.source === 'isdoc_embedded') ? ICONS.check : ICONS.chart" /></svg>
              {{ t('aiGateway.source.' + aiResult.source) }}
            </span>
            <span v-if="aiResult.provider" class="px-1.5 py-0.5 rounded text-[10px] font-medium bg-neutral-100 text-neutral-600 border border-neutral-200">{{ providerLabel(aiResult.provider) }}</span>
            <span v-if="aiResult.model" class="px-1.5 py-0.5 rounded text-[10px] font-mono bg-neutral-100 text-neutral-600 border border-neutral-200">{{ aiResult.model }}</span>
            <span v-if="aiResult.region" :class="['px-1.5 py-0.5 rounded text-[10px] font-semibold border', aiResult.region === 'eu' ? 'bg-success-50 text-success-700 border-success-500/40' : 'bg-neutral-100 text-neutral-500 border-neutral-200']">{{ t('aiGateway.region_' + aiResult.region) }}</span>
          </div>
          <div v-if="aiResult.usage" class="text-xs mt-1 font-mono">
            Tokens: in={{ aiResult.usage.input_tokens }}, out={{ aiResult.usage.output_tokens }}
          </div>
        </div>
        <div v-else class="rounded-md bg-danger-50 border border-danger-500/40 px-3 py-2 text-sm text-danger-500">
          <strong>✗ {{ aiResult.error }}</strong>
          <div class="text-xs mt-1">Source: {{ aiResult.source }}</div>
        </div>

        <details v-if="aiResult.ai_data" class="mt-3 text-xs">
          <summary class="cursor-pointer text-neutral-600 hover:text-neutral-900">{{ t('integrations.ai.raw_data') }}</summary>
          <pre class="mt-2 max-h-72 overflow-y-auto bg-neutral-900 text-neutral-100 p-3 rounded font-mono text-[11px] whitespace-pre-wrap">{{ JSON.stringify(aiResult.ai_data, null, 2) }}</pre>
        </details>
      </div>
    </div>
    <MergeDocumentsPanel v-if="canExtract && aiConfigured" :disabled="!canStartExtraction"
      :busy="aiExtracting || aiBatchRunning" @import="importMerged" />
    <PhotoCropDialog v-if="cropCurrent" :key="cropIndex" :file="cropCurrent" from-file
      :step="cropIndex + 1" :steps="cropQueue.length"
      @done="onCropDone" @cancel="resetCrop" />
    <ExtractionReviewModal v-if="reviewIds" :invoice-ids="reviewIds" :only-flagged="!auth.isClientRole" @close="reviewIds = null" />
  </div>
</template>

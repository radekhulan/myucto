<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { integrationsApi, type AiIssuedExtractResult, type AiExtractionStatus, type AiProvider } from '@/api/integrations'
import { useToast } from '@/composables/useToast'
import { apiErrorCode, apiErrorMessage } from '@/api/errors'
import AiNotConfiguredNotice from '@/components/purchase/AiNotConfiguredNotice.vue'
import { ICONS } from '@/components/ui/buttonStyles'
import MergeDocumentsPanel from '@/components/upload/MergeDocumentsPanel.vue'

const { t } = useI18n()
const toast = useToast()
const router = useRouter()

// ── AI import VYDANÉ faktury — prodejní zrcadlo /purchase-invoices/ai-import.
// Nastavení AI brány (provideři, klíče, DPA) zůstává v adminu; tady je jen denní
// operativa: nahrát doklad → extrakce → draft vydané faktury k revizi v editoru.
const aiCreds = ref<AiExtractionStatus | null>(null)
const providerInfo = computed(() => aiCreds.value?.providers?.[aiCreds.value.ai_provider])
const models = computed(() => providerInfo.value?.models ?? [])
const aiConfigured = computed(() => !!providerInfo.value?.configured)
const aiDefaultModel = computed(() => providerInfo.value?.default_model ?? '')
const credsLoaded = ref(false)

function providerLabel(p: AiProvider): string { return t(`aiGateway.provider.${p}`) }

async function loadAiCreds() {
  try {
    aiCreds.value = await integrationsApi.getAiExtractionStatus('issued')
  } catch {
    aiCreds.value = null
  } finally {
    credsLoaded.value = true
  }
}

const aiPdfFile = ref<File | null>(null)
const aiExtracting = ref(false)
const aiResult = ref<AiIssuedExtractResult | null>(null)
const aiPerRequestModel = ref('')  // empty = použít default

function onAiPdfPick(e: Event) {
  const input = e.target as HTMLInputElement
  aiPdfFile.value = input.files?.[0] ?? null
  aiResult.value = null
}

const aiDragOver = ref(false)

// Batch queue — vícero PDF naráz, processed serial (1 v čase) aby se nepřetížil rate limit.
interface BatchItem {
  file: File
  status: 'pending' | 'processing' | 'ok' | 'failed'
  result: any
}
const aiBatchQueue = ref<BatchItem[]>([])
const aiBatchRunning = ref(false)

async function runAiBatch() {
  if (aiBatchRunning.value || aiBatchQueue.value.length === 0) return
  aiBatchRunning.value = true
  try {
    for (const item of aiBatchQueue.value) {
      if (item.status === 'ok') continue
      item.status = 'processing'
      try {
        const model = aiPerRequestModel.value || undefined
        const r = await integrationsApi.extractPdfAiIssued(item.file, model)
        item.result = r
        item.status = r.ok ? 'ok' : 'failed'
      } catch (e: any) {
        item.result = e?.response?.data ?? { error: { message: apiErrorMessage(e) } }
        item.status = 'failed'
      }
    }
    await loadAiCreds()
    toast.success(t('integrations.ai.batch_done', { n: aiBatchQueue.value.filter(x => x.status === 'ok').length }))
  } finally {
    aiBatchRunning.value = false
  }
}

function clearBatch() {
  aiBatchQueue.value = []
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
  const files = Array.from(e.dataTransfer?.files ?? [])
  if (files.length === 0) return

  const pdfs = files.filter(f =>
    f.type === 'application/pdf' || f.type.startsWith('image/') ||
    /\.(pdf|jpe?g|png|webp|heic|heif|gif|bmp|isdoc|isdocx)$/i.test(f.name))
  if (pdfs.length === 0) {
    toast.error(t('integrations.ai.only_pdf'))
    return
  }

  if (pdfs.length === 1) {
    aiPdfFile.value = pdfs[0]
    aiResult.value = null
  } else {
    aiBatchQueue.value = pdfs.map(f => ({ file: f, status: 'pending' as const, result: null }))
    aiPdfFile.value = null
    aiResult.value = null
  }
}

async function runAiExtract() {
  if (!aiPdfFile.value || aiExtracting.value) return
  aiExtracting.value = true
  aiResult.value = null
  try {
    const model = aiPerRequestModel.value || undefined
    aiResult.value = await integrationsApi.extractPdfAiIssued(aiPdfFile.value, model)
    if (aiResult.value.ok) {
      toast.success(t('integrations.ai.extract_success'))
      await loadAiCreds()
    }
  } catch (e: any) {
    if (apiErrorCode(e) === 'ai_not_configured') {
      toast.error(apiErrorMessage(e))
      await loadAiCreds()
      return
    }
    const respData = e?.response?.data
    if (respData?.error?.details) {
      aiResult.value = { ok: false, ...respData.error.details, error: respData.error.message, source: respData.error.details?.source ?? 'ai_failed' }
    } else {
      toast.error(apiErrorMessage(e))
    }
  } finally {
    aiExtracting.value = false
  }
}

// Doklad spojený z více částí jde do stejného vytěžení jako jeden nahraný soubor.
function importMerged(file: File) {
  if (aiExtracting.value || aiBatchRunning.value) return
  aiBatchQueue.value = []
  aiPdfFile.value = file
  aiResult.value = null
  void runAiExtract()
}

function gotoInvoice(id: number) {
  router.push(`/invoices/${id}/edit`)
}

onMounted(() => {
  loadAiCreds()
})
</script>

<template>
  <div class="max-w-4xl">
    <div class="mb-4">
      <h1 class="text-2xl font-semibold">{{ t('integrations.salesAi.import_title') }}</h1>
      <p class="text-sm text-neutral-500 mt-0.5">{{ t('integrations.salesAi.import_subtitle') }}</p>
    </div>

    <!-- Aktivní provider není nakonfigurován — odkaz na admin nastavení (jen kdo smí) -->
    <AiNotConfiguredNotice v-if="credsLoaded && !aiConfigured" />

    <!-- AI PDF extract (primární akce — jen když je aktivní provider nakonfigurován) -->
    <div v-if="aiConfigured" class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm">
      <h2 class="text-sm font-medium text-neutral-700 mb-2">{{ t('integrations.ai.extract_title') }}</h2>
      <p class="text-xs text-neutral-500 mb-4">{{ t('integrations.ai.extract_hint') }}</p>

      <div class="space-y-3">
        <label class="block border-2 border-dashed rounded-lg p-6 text-center cursor-pointer transition"
          :class="aiDragOver
            ? 'border-primary-500 bg-primary-50'
            : 'border-neutral-300 hover:border-primary-400 hover:bg-primary-50/30'"
          @dragenter="onAiDragEnter" @dragover="onAiDragOver" @dragleave="onAiDragLeave" @drop="onAiDrop">
          <input type="file" accept="application/pdf,.pdf,image/*,.isdoc,.isdocx" @change="onAiPdfPick" class="hidden" />
          <svg class="w-8 h-8 mx-auto text-neutral-400 mb-2" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
            <path stroke-linecap="round" stroke-linejoin="round" d="M7 16a4 4 0 0 1-.88-7.9 5 5 0 0 1 9.9-1A5.5 5.5 0 0 1 18.5 16H17m-5-4v9m0-9l-3 3m3-3l3 3" />
          </svg>
          <div class="text-sm font-medium text-neutral-700">
            {{ aiPdfFile ? aiPdfFile.name : t('integrations.ai.drop_pdf') }}
          </div>
          <div v-if="aiPdfFile" class="text-xs text-neutral-500 mt-1">{{ Math.round(aiPdfFile.size / 1024) }} kB</div>
        </label>

        <div class="flex items-center gap-2">
          <label class="text-sm text-neutral-700">{{ t('integrations.ai.model_override') }}</label>
          <select v-model="aiPerRequestModel" class="h-9 px-2 border border-neutral-300 rounded-md bg-surface text-sm">
            <option value="">{{ t('integrations.ai.use_default') }} ({{ aiDefaultModel }})</option>
            <option v-for="m in models" :key="m" :value="m">{{ m }}</option>
          </select>
        </div>

        <button type="button" @click="runAiExtract" :disabled="!aiPdfFile || aiExtracting"
                class="cursor-pointer w-full h-10 bg-primary-600 hover:bg-primary-700 disabled:bg-neutral-300 text-white text-sm font-medium rounded-md">
          {{ aiExtracting ? t('integrations.ai.extracting') : t('integrations.ai.run_extract') }}
        </button>
      </div>

      <!-- Batch queue (multi-file drop) — processed serial -->
      <div v-if="aiBatchQueue.length > 0" class="mt-4 pt-4 border-t border-neutral-100">
        <div class="flex items-center justify-between mb-2">
          <h3 class="text-sm font-medium text-neutral-700">
            {{ t('integrations.ai.batch_title', { n: aiBatchQueue.length }) }}
          </h3>
          <button type="button" @click="clearBatch" :disabled="aiBatchRunning"
                  class="cursor-pointer text-xs text-neutral-500 hover:text-danger-500">
            {{ t('common.cancel') }}
          </button>
        </div>
        <div class="space-y-1 max-h-64 overflow-y-auto border border-neutral-200 rounded-md p-2 text-xs">
          <div v-for="(item, idx) in aiBatchQueue" :key="idx" class="flex items-center justify-between gap-2 py-1">
            <span class="font-mono truncate flex-1">{{ item.file.name }}</span>
            <span class="text-neutral-400">{{ Math.round(item.file.size / 1024) }} kB</span>
            <span :class="[
              'px-2 py-0.5 rounded text-[10px] font-medium uppercase tracking-wide',
              item.status === 'ok' ? 'bg-success-50 text-success-600' :
              item.status === 'failed' ? 'bg-danger-50 text-danger-500' :
              item.status === 'processing' ? 'bg-warning-50 text-warning-600' :
              'bg-neutral-100 text-neutral-500']">
              {{ item.status === 'processing' ? '…' : item.status }}
            </span>
            <RouterLink v-if="item.status === 'ok' && item.result?.invoice_id"
              :to="`/invoices/${item.result.invoice_id}/edit`"
              class="text-primary-600 hover:underline text-[10px]">→</RouterLink>
          </div>
        </div>
        <button type="button" @click="runAiBatch" :disabled="aiBatchRunning"
                class="mt-3 cursor-pointer w-full h-10 bg-primary-600 hover:bg-primary-700 disabled:bg-neutral-300 text-white text-sm font-medium rounded-md">
          {{ aiBatchRunning ? t('integrations.ai.batch_running') : t('integrations.ai.batch_run') }}
        </button>
        <p class="text-xs text-neutral-500 mt-2">
          ℹ {{ t('integrations.ai.batch_serial_hint') }}
        </p>
      </div>

      <div v-if="aiResult" class="mt-4 pt-4 border-t border-neutral-100">
        <div v-if="aiResult.ok" class="rounded-md bg-success-50 border border-success-500/40 px-3 py-2 text-sm text-success-600">
          <strong>✓ {{ t('integrations.ai.extract_success') }}</strong>
          <button v-if="aiResult.invoice_id" type="button" @click="gotoInvoice(aiResult.invoice_id!)"
                  class="ml-3 cursor-pointer underline hover:text-success-700">
            {{ t('integrations.salesAi.go_to_invoice') }} #{{ aiResult.invoice_id }}
          </button>
          <!-- Provenance badge row: source (ISDOC = zelená preferovaná cesta | AI) + provider/model/region -->
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
    <MergeDocumentsPanel v-if="aiConfigured" :busy="aiExtracting || aiBatchRunning" @import="importMerged" />
  </div>
</template>

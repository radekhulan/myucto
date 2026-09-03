<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter, RouterLink } from 'vue-router'
import ImportReportPanel from '@/components/exchange/ImportReportPanel.vue'
import ImportJobProgress from '@/components/exchange/ImportJobProgress.vue'
import { useFileImportJob } from '@/composables/useFileImportJob'
import { purchaseInvoicesApi, type InboxScanResult } from '@/api/purchaseInvoices'
import { integrationsApi, type AnthropicCredentialsStatus } from '@/api/integrations'
import { useToast } from '@/composables/useToast'
import { apiErrorMessage } from '@/api/errors'
import { useAuthStore } from '@/stores/auth'
import { btnFilled, btnOutline, ICONS } from '@/components/ui/buttonStyles'

const { t } = useI18n()
const router = useRouter()
const toast = useToast()
const auth = useAuthStore()

/**
 * Spravovaná instalace (vzor Update.vue): cestu do souborového systému
 * (`purchase_invoice.inbox_dir`) nastavuje provozovatel, ne uživatel — scan
 * proto backend odmítá (409 `managed_installation`). Pojmenovaný box místo
 * zmizelého tlačítka, ať je to vidět dřív, než uživatel klikne a dostane chybu.
 * Bankovní strana (`ImportBank.vue`) tenhle problém nemá — `scan_configured`
 * tam vrací false, takže se tlačítko skrývá jinou cestou.
 */
const isManaged = computed(() => auth.isManagedInstallation)

// ── Upload (multipart, kind=purchase) ───────────────────────────────────────
// Import běží na pozadí, stejným composablem jako vydaná strana: dávka z Pohody má
// běžně stovky až tisíce dokladů a synchronní request ji nepřežije.
const { files, running, cancelling, error, report, job, percent, purchaseStatus, pick, start, cancel, reset } =
  useFileImportJob('purchase')

// Koncept se nezapočítává do nákladů, závazků ani výkazů. Doklad ze strukturovaného
// souboru je přitom úplný, takže výchozí je „přijatá"; koncept zůstává pro dávku,
// kterou chce účetní ještě projít.
const asDraft = computed({
  get: () => purchaseStatus.value === 'draft',
  set: (v: boolean) => { purchaseStatus.value = v ? 'draft' : 'received' },
})

// Viz ImportIssued.vue: `e.message` u axiosu nese jen HTTP status, kdežto hláška
// s návodem (chybějící číselník / migrace) je v `response.data.error.message`.
const failed = (e: unknown) => apiErrorMessage(e, t('imports.upload_failed'))

function onPick(e: Event) {
  pick((e.target as HTMLInputElement).files)
}
function onDrop(e: DragEvent) {
  e.preventDefault()
  pick(e.dataTransfer?.files ?? null)
}
const submit = () => start(failed)
const clear = reset
const statusBadge = (s: string) => {
  if (s === 'created') return 'bg-success-50 text-success-600 border-success-500/40'
  if (s === 'skipped') return 'bg-warning-50 text-warning-600 border-warning-500/40'
  return 'bg-danger-50 text-danger-500 border-danger-500/40'
}

// ── Přijaté (scan inbox flow) ──────────────────────────────────────────────
const scanRunning = ref(false)
const scanResult = ref<InboxScanResult | null>(null)
const scanDryRun = ref(false)

// Items které NEBYLY importovány (AI selhalo / ISDOC missing bez AI / parser error),
// plus doklady, které sice vznikly, ale u nichž spárované PDF nesedí s daty (`warning`) —
// ty potřebují oko uživatele stejně jako selhání, jen z jiného důvodu.
const notImportedItems = computed(() => {
  if (!scanResult.value) return []
  return scanResult.value.details.filter(d => d.status === 'skipped' || d.status === 'failed' || !!d.warning)
})

// AI integration status — load při mount aby uživatel věděl, zda PDF bez ISDOC
// bude zpracováno (AI fallback) nebo skipnuté (no AI config)
const aiStatus = ref<AnthropicCredentialsStatus | null>(null)
async function loadAiStatus() {
  try {
    aiStatus.value = await integrationsApi.getAnthropicCreds()
  } catch {
    aiStatus.value = { configured: false } as AnthropicCredentialsStatus
  }
}
onMounted(loadAiStatus)
async function runScan() {
  scanRunning.value = true
  scanResult.value = null
  try {
    scanResult.value = await purchaseInvoicesApi.scanInbox(scanDryRun.value)
    toast.success(t('purchase_invoice.scan_inbox.result_summary', {
      created: scanResult.value.created,
      skipped: scanResult.value.skipped,
      failed:  scanResult.value.failed,
    }))
  } catch (e) {
    toast.error(apiErrorMessage(e))
  } finally {
    scanRunning.value = false
  }
}
</script>

<template>
  <div>
    <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm max-w-3xl">
      <div class="p-5 space-y-4">
        <!-- Dropzone — multipart upload pro PDF/ISDOC přijatých faktur (kind=purchase) -->
        <div class="rounded-md bg-primary-50 border border-primary-200 px-3 py-2 text-sm text-primary-700">
          <strong>{{ t('imports.purchase_upload_title') }}:</strong>
          {{ t('imports.purchase_upload_hint') }}
        </div>
        <label
          @dragover.prevent
          @drop="onDrop"
          class="block border-2 border-dashed border-neutral-300 hover:border-primary-400 hover:bg-primary-50/30 rounded-lg p-8 text-center cursor-pointer transition"
        >
          <input
            type="file"
            multiple
            accept=".xml,.isdoc,.isdocx,.zip,.pdf,application/xml,application/zip,application/x-isdoc,application/pdf"
            @change="onPick"
            class="hidden"
          />
          <svg class="w-8 h-8 mx-auto text-neutral-400 mb-2" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
            <path stroke-linecap="round" stroke-linejoin="round" d="M7 16a4 4 0 0 1-.88-7.9 5 5 0 0 1 9.9-1A5.5 5.5 0 0 1 18.5 16H17m-5-4v9m0-9l-3 3m3-3l3 3" />
          </svg>
          <div class="text-sm font-medium text-neutral-700">{{ t('imports.drop_or_click') }}</div>
          <div class="text-xs text-neutral-500 mt-1">{{ t('imports.formats_hint') }}</div>
        </label>

        <div v-if="files.length > 0" class="border border-neutral-200 rounded-md p-3 bg-neutral-50">
          <div class="text-xs font-medium text-neutral-700 mb-2">{{ t('imports.selected_files') }} ({{ files.length }})</div>
          <ul class="text-sm space-y-1 font-mono">
            <li v-for="f in files" :key="f.name" class="flex justify-between text-neutral-700">
              <span class="truncate">{{ f.name }}</span>
              <span class="text-neutral-400 ml-2">{{ Math.round(f.size / 1024) }} kB</span>
            </li>
          </ul>
        </div>

        <label class="flex items-start gap-2 text-sm text-neutral-700">
          <input v-model="asDraft" type="checkbox" class="mt-0.5 rounded border-neutral-300 text-primary-600" />
          <span>
            {{ t('imports.purchase_as_draft') }}
            <span class="block text-xs text-neutral-500">{{ t('imports.purchase_as_draft_hint') }}</span>
          </span>
        </label>

        <ImportJobProgress :job="job" :percent="percent" :cancelling="cancelling" @cancel="cancel" />

        <div v-if="error" class="rounded-md bg-danger-50 border border-danger-500/40 px-3 py-2 text-sm text-danger-500">{{ error }}</div>

        <div class="flex gap-2 flex-wrap">
          <button
            @click="submit"
            :disabled="running || files.length === 0"
            :class="btnFilled('primary')"
            class="flex-1 justify-center whitespace-nowrap"
          >
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.upload"/></svg>
            {{ running ? t('imports.uploading') : t('imports.upload') }}
          </button>
          <button
            v-if="files.length > 0 || report"
            @click="clear"
            :disabled="running"
            :class="btnOutline('neutral')"
            class="whitespace-nowrap"
          >
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x"/></svg>
            {{ t('common.close') }}
          </button>
        </div>

        <div class="border-t border-neutral-100 pt-4 mt-2">
        <div class="rounded-md bg-primary-50 border border-primary-200 px-3 py-2 text-sm text-primary-700">
          <strong>{{ t('imports.purchase_scan_title') }}:</strong>
          {{ t('imports.purchase_scan_hint') }}
        </div>

        <!-- AI integration status — informuje user zda PDF bez ISDOC bude zpracováno -->
        <div class="mt-3">
          <div v-if="aiStatus === null" class="rounded-md bg-neutral-50 border border-neutral-200 px-3 py-2 text-sm text-neutral-500">
            {{ t('common.loading') }}…
          </div>
          <div v-else-if="aiStatus.configured"
               class="rounded-md bg-success-50 border border-success-500/40 px-3 py-2 text-sm text-success-600">
            <strong>✓ {{ t('imports.ai_active_title') }}</strong>
            <span class="ml-2 font-mono text-xs text-success-600/80">{{ aiStatus.default_model }}</span>
            <span class="ml-3 text-xs">{{ t('imports.ai_active_hint') }}</span>
          </div>
          <div v-else
               class="rounded-md bg-warning-50 border border-warning-500/40 px-3 py-2 text-sm text-warning-700">
            <strong>⚠ {{ t('imports.ai_not_configured_title') }}</strong>
            <span class="ml-2 text-xs">{{ t('imports.ai_not_configured_hint') }}</span>
            <RouterLink to="/admin/integrations?tab=ai" class="ml-1 text-xs text-primary-700 hover:underline whitespace-nowrap">
              → {{ t('nav.ai_settings') }}
            </RouterLink>
          </div>
        </div>

        <div class="border border-neutral-200 rounded-lg p-5 bg-neutral-50/50">
          <div class="flex items-start gap-3 mb-3">
            <svg class="w-6 h-6 text-primary-600 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
              <path stroke-linecap="round" stroke-linejoin="round" d="M3 7v10a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V7M3 7l2-3h14l2 3M3 7h18M8 11h8" />
            </svg>
            <div class="flex-1">
              <h3 class="font-medium text-neutral-900">{{ t('purchase_invoice.scan_inbox.title') }}</h3>
              <p class="text-xs text-neutral-500 mt-1">{{ t('imports.purchase_scan_path_hint') }}</p>
            </div>
          </div>

          <template v-if="!isManaged">
            <label class="inline-flex items-center gap-2 text-sm mb-3">
              <input v-model="scanDryRun" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
              <span>{{ t('purchase_invoice.scan_inbox.dry_run') }}</span>
            </label>

            <div class="flex gap-2">
              <button
                type="button"
                @click="runScan"
                :disabled="scanRunning"
                :class="btnFilled('primary')"
              >
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.inbox"/></svg>
                {{ scanRunning ? t('purchase_invoice.scan_inbox.running') : t('purchase_invoice.scan_inbox.trigger') }}
              </button>
            </div>
          </template>

          <!--
            Spravovaná instalace: místo tlačítka „Skenovat" pojmenovaná
            informace (vzor Update.vue). Zmizelé tlačítko bez vysvětlení
            vypadá jako rozbitá funkce.
          -->
          <div
            v-else
            class="rounded-lg border border-neutral-200 bg-neutral-50 px-4 py-3 text-sm text-neutral-600 flex gap-2.5"
          >
            <svg class="w-4 h-4 mt-0.5 shrink-0 text-neutral-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.lock" /></svg>
            <div>
              <div class="font-medium text-neutral-800">{{ t('purchase_invoice.scan_inbox.managed_title') }}</div>
              <p class="mt-0.5">{{ t('purchase_invoice.scan_inbox.managed_desc') }}</p>
            </div>
          </div>
        </div>

        <!-- Plus odkaz na PDF editor pro 1-by-1 upload (drag & drop přímo na faktuře) -->
        <div class="rounded-md bg-neutral-50 border border-neutral-200 px-3 py-2 text-sm text-neutral-700">
          <strong>{{ t('imports.purchase_manual_title') }}:</strong>
          {{ t('imports.purchase_manual_hint') }}
          <button type="button" @click="router.push('/purchase-invoices/new')"
                  class="cursor-pointer ml-1 text-primary-700 hover:underline font-medium">
            {{ t('purchase_invoice.new') }}
          </button>
        </div>

        <!-- Scan result -->
        <div v-if="scanResult" class="mt-4 bg-surface border border-neutral-200 rounded-lg p-4">
          <div class="flex flex-wrap items-center gap-4 mb-3 text-sm">
            <div><span class="font-semibold text-success-600">{{ scanResult.created }}</span> {{ t('imports.summary_created') }}</div>
            <div><span class="font-semibold text-warning-600">{{ scanResult.skipped }}</span> {{ t('imports.summary_skipped') }}</div>
            <div><span class="font-semibold text-danger-500">{{ scanResult.failed }}</span> {{ t('imports.summary_failed') }}</div>
          </div>
          <p v-if="scanResult.inbox_dir" class="text-xs text-neutral-500 mb-2 font-mono">{{ scanResult.inbox_dir }}</p>

          <!-- Prominent box: faktury které NEBYLY importovány (failed + skipped, ale ne 'imported') -->
          <div v-if="notImportedItems.length > 0" class="mt-3 rounded-md bg-warning-50 border border-warning-500/40 p-3">
            <div class="text-sm font-semibold text-warning-700 mb-2">
              ⚠ {{ t('purchase_invoice.scan_inbox.not_imported_title', { n: notImportedItems.length }) }}
            </div>
            <ul class="space-y-1.5 max-h-72 overflow-y-auto">
              <li v-for="(d, i) in notImportedItems" :key="i" class="text-xs flex items-start gap-2">
                <span class="inline-block px-1.5 py-0.5 rounded text-[10px] shrink-0" :class="statusBadge(d.status)">{{ d.status }}</span>
                <div class="flex-1 min-w-0">
                  <div class="font-mono text-neutral-800 truncate">{{ d.file ? d.file.split(/[/\\]/).pop() : '—' }}</div>
                  <div v-if="d.reason" class="text-neutral-600 mt-0.5">{{ d.reason }}</div>
                  <div v-if="d.warning" class="text-warning-700 mt-0.5">⚠ {{ d.warning }}</div>
                </div>
              </li>
            </ul>
          </div>

          <details v-if="scanResult.details.length > 0" class="text-xs mt-3">
            <summary class="cursor-pointer text-neutral-600 hover:text-neutral-900">
              {{ t('purchase_invoice.scan_inbox.details') }} ({{ scanResult.details.length }})
            </summary>
            <ul class="mt-2 space-y-1 max-h-72 overflow-y-auto">
              <li v-for="(d, i) in scanResult.details" :key="i" class="font-mono text-[11px] border-b border-neutral-50 py-1">
                <span class="inline-block px-1.5 py-0.5 rounded text-[10px]" :class="statusBadge(d.status)">{{ d.status }}</span>
                <span class="ml-1 text-neutral-700">{{ d.file ? d.file.split(/[/\\]/).pop() : '—' }}</span>
                <span v-if="d.reason" class="ml-1 text-neutral-500">— {{ d.reason }}</span>
                <span v-if="d.warning" class="ml-1 text-warning-700">⚠ {{ d.warning }}</span>
              </li>
            </ul>
          </details>
        </div>
        </div><!-- /border-t scan inbox wrap -->
      </div>
    </div>

    <!-- Report -->
    <ImportReportPanel v-if="report" :report="report" />
  </div>
</template>

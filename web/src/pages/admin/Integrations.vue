<script setup lang="ts">
import { ref, reactive, onMounted, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { integrationsApi,
  type IdokladCredentialsStatus, type FakturoidCredentialsStatus,
  type ImportJob,
  type AiProvider, type AiDataRegion, type AiCredentialsResponse, type AiCredentialsPayload } from '@/api/integrations'
import { settingsApi, type AiAssistSettings, type AiAssistScope } from '@/api/settings'
import { useRoute } from 'vue-router'
import { useToast } from '@/composables/useToast'
import { apiErrorMessage } from '@/api/errors'
import { btnFilled, btnOutline, ICONS } from '@/components/ui/buttonStyles'
import { useSessionAwarePolling } from '@/composables/useSessionAwarePolling'

const { t } = useI18n()
const toast = useToast()

type Tab = 'idoklad' | 'fakturoid' | 'ai'
// Tab z ?tab=... query (default idoklad). Watch pro proklik mezi sidebar položkami
// "Externí integrace" (no query) ↔ "AI nastavení" (?tab=ai, sekce Firma).
const route = useRoute()
function readTabFromQuery(): Tab {
  const q = String(route.query.tab ?? '')
  return q === 'fakturoid' || q === 'ai' ? q as Tab : 'idoklad'
}
const tab = ref<Tab>(readTabFromQuery())
watch(() => route.query.tab, () => {
  tab.value = readTabFromQuery()
})

// ── iDoklad credentials state ─────────────────────────────────────────
const idokladStatus = ref<IdokladCredentialsStatus | null>(null)
const idokladClientId = ref('')
const idokladClientSecret = ref('')
const idokladSaving = ref(false)
const idokladTestMsg = ref<{ ok: boolean; text: string } | null>(null)
const showSecret = ref(false)

async function loadIdokladStatus() {
  try {
    idokladStatus.value = await integrationsApi.getIdokladCreds()
    if (idokladStatus.value?.client_id) idokladClientId.value = idokladStatus.value.client_id
  } catch (e) {
    toast.error(apiErrorMessage(e))
  }
}

async function saveIdokladCreds() {
  if (!idokladClientId.value || !idokladClientSecret.value) {
    toast.error('Vyplň oboje pole (client_id i client_secret).')
    return
  }
  idokladSaving.value = true
  idokladTestMsg.value = null
  try {
    const r = await integrationsApi.setIdokladCreds(idokladClientId.value, idokladClientSecret.value)
    if (r.test_ok) {
      idokladTestMsg.value = { ok: true, text: t('integrations.idoklad.test_success') }
      idokladClientSecret.value = ''  // clear sensitive field
      await loadIdokladStatus()
    } else {
      idokladTestMsg.value = { ok: false, text: r.test_error || 'Test connectivity selhal' }
    }
  } catch (e) {
    idokladTestMsg.value = { ok: false, text: apiErrorMessage(e) }
  } finally {
    idokladSaving.value = false
  }
}

async function deleteIdokladCreds() {
  if (!confirm(t('integrations.idoklad.delete_confirm'))) return
  try {
    await integrationsApi.deleteIdokladCreds()
    idokladStatus.value = null
    idokladClientId.value = ''
    idokladClientSecret.value = ''
    idokladTestMsg.value = null
    toast.success(t('integrations.idoklad.deleted'))
  } catch (e) {
    toast.error(apiErrorMessage(e))
  }
}

// ── iDoklad import job state ──────────────────────────────────────────
const startParams = ref({
  include_bank_accounts: true,
  include_bank_transactions: false,
  include_clients: true,
  include_issued: true,
  include_received: true,
  incremental: false,
  download_attachments: false,
  dry_run: false,
})
const currentJob = ref<ImportJob | null>(null)
const starting = ref(false)
const polledJobId = ref<number | null>(null)
const jobPollingEnabled = ref(false)

async function startImport() {
  if (starting.value) return
  starting.value = true
  try {
    const r = await integrationsApi.startIdoklad(startParams.value)
    toast.success(t('integrations.idoklad.started', { jobId: r.job_id }))
    pollJob(r.job_id)
  } catch (e: any) {
    toast.error(apiErrorMessage(e))
  } finally {
    starting.value = false
  }
}

function pollJob(jobId: number) {
  polledJobId.value = jobId
  jobPollingEnabled.value = true
}

async function refreshJob(signal: AbortSignal) {
  if (polledJobId.value === null) return
  currentJob.value = await integrationsApi.getJob(polledJobId.value, signal)
  jobPollingEnabled.value = ['queued', 'running'].includes(currentJob.value.status)
}

useSessionAwarePolling(refreshJob, 2000, jobPollingEnabled)

async function cancelImport() {
  if (!currentJob.value) return
  if (!confirm(t('integrations.idoklad.cancel_confirm'))) return
  try {
    await integrationsApi.cancelJob(currentJob.value.id)
    toast.success(t('integrations.idoklad.cancel_requested'))
  } catch (e) {
    toast.error(apiErrorMessage(e))
  }
}

async function deleteImport() {
  if (!currentJob.value) return
  if (!confirm(t('integrations.idoklad.delete_confirm'))) return
  try {
    await integrationsApi.deleteJob(currentJob.value.id)
    jobPollingEnabled.value = false
    polledJobId.value = null
    currentJob.value = null
    toast.success(t('integrations.idoklad.deleted'))
  } catch (e) {
    toast.error(apiErrorMessage(e))
  }
}

const isJobRunning = computed(() =>
  currentJob.value && ['queued', 'running'].includes(currentJob.value.status)
)

const progressPercent = computed(() => {
  if (!currentJob.value || !currentJob.value.total_items) return null
  return Math.round((currentJob.value.processed / currentJob.value.total_items) * 100)
})

// ── Fakturoid credentials state ───────────────────────────────────────
// Dva paralelní auth flow (issue #31):
//   - Legacy BasicAuth  : slug + email + api_key (starší účty, deprecated 2024)
//   - OAuth2 Client Cred: slug + client_id + client_secret (nové účty)
// User vyplní jeden z bloků (nebo oba — OAuth2 má pak prioritu při requestech).
const fakStatus = ref<FakturoidCredentialsStatus | null>(null)
const fakAuthMode = ref<'oauth2' | 'basic'>('oauth2')
const fakSlug = ref('')
const fakEmail = ref('')
const fakApiKey = ref('')
const fakClientId = ref('')
const fakClientSecret = ref('')
const fakSaving = ref(false)
const fakShowKey = ref(false)
const fakShowSecret = ref(false)
const fakTestMsg = ref<{ ok: boolean; text: string } | null>(null)

const fakStartParams = ref({
  include_clients: true,
  include_issued: true,
  include_received: true,
  incremental: false,
  download_attachments: false,
  dry_run: false,
})
const fakStarting = ref(false)

async function loadFakStatus() {
  try {
    fakStatus.value = await integrationsApi.getFakturoidCreds()
    if (fakStatus.value?.slug) fakSlug.value = fakStatus.value.slug
    if (fakStatus.value?.email) fakEmail.value = fakStatus.value.email
    if (fakStatus.value?.client_id) fakClientId.value = fakStatus.value.client_id
    // Auto-pick aktivní auth mode pro úpravu (OAuth2 priorita)
    if (fakStatus.value?.auth_mode) fakAuthMode.value = fakStatus.value.auth_mode
  } catch (e) {
    toast.error(apiErrorMessage(e))
  }
}

async function saveFakCreds() {
  if (!fakSlug.value) {
    toast.error(t('integrations.fakturoid.slug_required'))
    return
  }
  if (fakAuthMode.value === 'oauth2') {
    if (!fakClientId.value || !fakClientSecret.value) {
      toast.error(t('integrations.fakturoid.oauth_required'))
      return
    }
  } else {
    if (!fakEmail.value || !fakApiKey.value) {
      toast.error(t('integrations.fakturoid.basic_required'))
      return
    }
  }
  fakSaving.value = true
  fakTestMsg.value = null
  try {
    const payload = fakAuthMode.value === 'oauth2'
      ? { slug: fakSlug.value, client_id: fakClientId.value, client_secret: fakClientSecret.value }
      : { slug: fakSlug.value, email: fakEmail.value, api_key: fakApiKey.value }
    const r = await integrationsApi.setFakturoidCreds(payload)
    if (r.test_ok) {
      fakTestMsg.value = { ok: true, text: t('integrations.fakturoid.test_success', { name: r.account_name || '' }) }
      fakApiKey.value = ''
      fakClientSecret.value = ''
      await loadFakStatus()
    } else {
      fakTestMsg.value = { ok: false, text: r.test_error || 'Test connectivity selhal' }
    }
  } catch (e) {
    fakTestMsg.value = { ok: false, text: apiErrorMessage(e) }
  } finally {
    fakSaving.value = false
  }
}

async function deleteFakCreds() {
  if (!confirm(t('integrations.fakturoid.delete_confirm'))) return
  try {
    await integrationsApi.deleteFakturoidCreds()
    fakStatus.value = null
    fakSlug.value = ''
    fakEmail.value = ''
    fakApiKey.value = ''
    fakClientId.value = ''
    fakClientSecret.value = ''
    fakTestMsg.value = null
    toast.success(t('integrations.fakturoid.deleted'))
  } catch (e) {
    toast.error(apiErrorMessage(e))
  }
}

async function startFakImport() {
  if (fakStarting.value) return
  fakStarting.value = true
  try {
    const r = await integrationsApi.startFakturoid(fakStartParams.value)
    toast.success(t('integrations.idoklad.started', { jobId: r.job_id }))
    pollJob(r.job_id)
  } catch (e: any) {
    toast.error(apiErrorMessage(e))
  } finally {
    fakStarting.value = false
  }
}

// ── AI extrakční brána (Epic F7) — 4 provideři + EU rezidence ─────────
// Nastavení brány (provideři, klíče, DPA, rozsah) zůstává tady v adminu;
// samotný AI import přijatých faktur je vytažený na /purchase-invoices/ai-import (§12b).
const ALL_PROVIDERS: AiProvider[] = ['anthropic', 'azure_openai', 'openai', 'gemini']

const aiCreds = ref<AiCredentialsResponse | null>(null)
const aiProvider = ref<AiProvider>('anthropic')   // aktivní provider tenanta (non-secret)
const aiRegion = ref<AiDataRegion>('us')
const aiEuRequired = ref(false)
const aiSavingSelection = ref(false)
const aiAssist = ref<AiAssistSettings | null>(null)
const aiAssistEnabled = ref(false)
const aiAssistScopes = ref<AiAssistScope[]>([])
const aiAssistDpa = ref(false)
const aiAssistSaving = ref(false)

// per-provider credential form (klíč WRITE-ONLY — nikdy neecho)
const credForm = reactive({
  api_key: '', default_model: '',
  endpoint: '', deployment: '', api_version: '',   // azure_openai
  base_url: '',                                     // openai
})
const credShowKey = ref(false)
const credSaving = ref(false)
const credTesting = ref(false)
const credTestMsg = ref<{ ok: boolean; text: string } | null>(null)

const providerInfo = computed(() => aiCreds.value?.providers?.[aiProvider.value])
const models = computed(() => providerInfo.value?.models ?? [])
function euCapable(p: AiProvider): boolean { return aiCreds.value?.providers?.[p]?.eu_capable ?? false }
function providerConfigured(p: AiProvider): boolean { return aiCreds.value?.providers?.[p]?.configured ?? false }
// EU-required → provider musí být EU-capable (fail-closed vizuálně; server ho stejně odmítne)
const providerBlockedByEu = computed(() => aiEuRequired.value && !euCapable(aiProvider.value))

const aiConfigured = computed(() => !!providerInfo.value?.configured)
const aiExtractCount = computed(() => providerInfo.value?.extractions_count ?? 0)

function syncCredForm() {
  const inf = providerInfo.value
  credForm.api_key = ''
  credForm.default_model = inf?.default_model ?? (models.value[0] ?? '')
  credForm.endpoint = inf?.endpoint ?? ''
  credForm.deployment = inf?.deployment ?? ''
  credForm.api_version = inf?.api_version ?? ''
  credForm.base_url = inf?.base_url ?? ''
  credTestMsg.value = null
}

async function loadAiCreds() {
  try {
    aiCreds.value = await integrationsApi.getAiCredentials()
    aiProvider.value = aiCreds.value.ai_provider
    aiRegion.value = aiCreds.value.ai_data_region
    aiEuRequired.value = aiCreds.value.ai_eu_residency_required
    syncCredForm()
  } catch {
    // Backend brána ještě nemusí být nasazená — degradujeme defenzivně (UI zůstane prázdné).
    aiCreds.value = null
  }
}

async function loadAiAssist() {
  try {
    aiAssist.value = await settingsApi.getAiAssist()
    aiAssistEnabled.value = aiAssist.value.enabled
    aiAssistScopes.value = [...aiAssist.value.scope]
    aiAssistDpa.value = !!aiAssist.value.dpa_confirmed[aiAssist.value.provider]
  } catch {
    aiAssist.value = null
  }
}

async function saveAiAssist() {
  if (!aiAssist.value || aiAssistSaving.value) return
  aiAssistSaving.value = true
  const provider = aiAssist.value.provider
  const wasConfirmed = !!aiAssist.value.dpa_confirmed[provider]
  try {
    aiAssist.value = await settingsApi.updateAiAssist({
      enabled: aiAssistEnabled.value,
      scope: aiAssistScopes.value,
      ...(!wasConfirmed && aiAssistDpa.value ? { dpa_confirm: provider } : {}),
      ...(wasConfirmed && !aiAssistDpa.value ? { dpa_revoke: provider } : {}),
    })
    aiAssistEnabled.value = aiAssist.value.enabled
    aiAssistScopes.value = [...aiAssist.value.scope]
    aiAssistDpa.value = !!aiAssist.value.dpa_confirmed[aiAssist.value.provider]
    toast.success(t('common.saved'))
  } catch (e) {
    toast.error(apiErrorMessage(e))
  } finally {
    aiAssistSaving.value = false
  }
}

async function unmuteAiSource(source: 'knn' | 'llm') {
  try {
    aiAssist.value = await settingsApi.updateAiAssist({ unmute_source: source })
    toast.success(t('common.saved'))
  } catch (e) {
    toast.error(apiErrorMessage(e))
  }
}

function selectProvider(p: AiProvider) {
  if (aiProvider.value === p) return
  aiProvider.value = p
  syncCredForm()
}

async function saveAiSelection() {
  aiSavingSelection.value = true
  try {
    await settingsApi.updateSupplier({
      ai_provider: aiProvider.value,
      ai_data_region: aiRegion.value,
      ai_eu_residency_required: aiEuRequired.value,
    })
    await loadAiCreds()
    await loadAiAssist()
    toast.success(t('aiGateway.selection_saved'))
    if (!providerConfigured(aiProvider.value)) toast.warning(t('aiGateway.warn_not_configured'))
  } catch (e) {
    toast.error(apiErrorMessage(e))
  } finally {
    aiSavingSelection.value = false
  }
}

function validateKeyClient(): string | null {
  const k = credForm.api_key
  if (!k) return null // prázdné = zachovat stávající
  if (aiProvider.value === 'anthropic' && !k.startsWith('sk-ant-')) return t('aiGateway.err_key_anthropic')
  if (aiProvider.value === 'openai' && !k.startsWith('sk-')) return t('aiGateway.err_key_openai')
  return null
}

async function saveAiCredentials() {
  const err = validateKeyClient()
  if (err) { toast.error(err); return }
  if (!credForm.api_key && !providerConfigured(aiProvider.value)) { toast.error(t('aiGateway.err_key_required')); return }
  credSaving.value = true
  credTestMsg.value = null
  try {
    const payload: AiCredentialsPayload = { provider: aiProvider.value }
    if (credForm.api_key) payload.api_key = credForm.api_key
    if (credForm.default_model) payload.default_model = credForm.default_model
    if (aiProvider.value === 'azure_openai') {
      payload.endpoint = credForm.endpoint
      payload.deployment = credForm.deployment
      payload.api_version = credForm.api_version
    }
    if (aiProvider.value === 'openai') payload.base_url = credForm.base_url
    const r = await integrationsApi.setAiCredentials(payload)
    credTestMsg.value = r.test_ok
      ? { ok: true, text: t('aiGateway.test_ok', { model: r.model || '' }) }
      : { ok: false, text: r.test_error || t('aiGateway.test_failed') }
    credForm.api_key = ''
    await loadAiCreds()
  } catch (e) {
    credTestMsg.value = { ok: false, text: apiErrorMessage(e) }
  } finally {
    credSaving.value = false
  }
}

async function testAiConnection() {
  credTesting.value = true
  credTestMsg.value = null
  try {
    const r = await integrationsApi.testAiConnection(aiProvider.value)
    credTestMsg.value = r.test_ok
      ? { ok: true, text: t('aiGateway.test_ok', { model: r.model || '' }) }
      : { ok: false, text: r.test_error || t('aiGateway.test_failed') }
  } catch (e) {
    credTestMsg.value = { ok: false, text: apiErrorMessage(e) }
  } finally {
    credTesting.value = false
  }
}

async function deleteAiCredentials() {
  if (!confirm(t('aiGateway.delete_confirm'))) return
  try {
    await integrationsApi.deleteAiCredentials(aiProvider.value)
    await loadAiCreds()
    toast.success(t('aiGateway.deleted'))
  } catch (e) {
    toast.error(apiErrorMessage(e))
  }
}

function providerLabel(p: AiProvider): string { return t(`aiGateway.provider.${p}`) }

onMounted(() => {
  loadIdokladStatus()
  loadFakStatus()
  loadAiCreds()
  loadAiAssist()
})

</script>

<template>
  <div class="max-w-4xl">
    <div class="mb-4">
      <h1 class="text-2xl font-semibold">{{ t('integrations.title') }}</h1>
      <p class="text-sm text-neutral-500 mt-0.5">{{ t('integrations.subtitle') }}</p>
    </div>

    <!-- Tabs: iDoklad / Fakturoid / AI -->
    <div class="border-b border-neutral-200 mb-4 flex gap-1 overflow-x-auto">
      <button
        v-for="tt in (['idoklad', 'fakturoid', 'ai'] as const)" :key="tt"
        @click="tab = tt"
        class="cursor-pointer px-4 py-2 text-sm border-b-2 transition whitespace-nowrap"
        :class="tab === tt
          ? 'border-primary-600 text-primary-700 font-medium'
          : 'border-transparent text-neutral-600 hover:text-neutral-900'"
      >
        {{ t('integrations.' + tt + '.tab') }}
      </button>
    </div>

    <!-- ════ iDoklad tab ════ -->
    <div v-if="tab === 'idoklad'" class="space-y-4">
      <!-- Box: credentials -->
      <div class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm">
        <h2 class="text-sm font-medium text-neutral-700 mb-2">{{ t('integrations.idoklad.credentials_title') }}</h2>
        <p class="text-xs text-neutral-500 mb-4">{{ t('integrations.idoklad.credentials_hint') }}</p>

        <div class="rounded-md bg-primary-50 border border-primary-200 px-3 py-2 text-sm text-primary-700 mb-4" v-if="idokladStatus?.configured">
          <strong>✓ {{ t('integrations.idoklad.configured') }}</strong>
          <span v-if="idokladStatus.client_id" class="ml-2 font-mono text-xs">{{ idokladStatus.client_id.slice(0, 12) }}…</span>
        </div>

        <div class="space-y-3">
          <div>
            <label class="block text-sm text-neutral-700 mb-1">{{ t('integrations.idoklad.client_id') }} *</label>
            <input v-model="idokladClientId" type="text" maxlength="256"
                   class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono"
                   placeholder="01234567-89ab-cdef-0123-456789abcdef" />
          </div>
          <div>
            <label class="block text-sm text-neutral-700 mb-1">{{ t('integrations.idoklad.client_secret') }} *</label>
            <div class="flex gap-2">
              <input v-model="idokladClientSecret" :type="showSecret ? 'text' : 'password'" maxlength="512"
                     class="flex-1 h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono"
                     :placeholder="idokladStatus?.configured ? t('integrations.idoklad.secret_placeholder_existing') : ''" />
              <button type="button" @click="showSecret = !showSecret"
                      class="cursor-pointer h-10 px-3 border border-neutral-300 rounded-md hover:bg-neutral-50 text-sm">
                {{ showSecret ? '🙈' : '👁' }}
              </button>
            </div>
            <p class="text-xs text-neutral-500 mt-1">{{ t('integrations.idoklad.secret_hint') }}</p>
          </div>
        </div>

        <div v-if="idokladTestMsg" class="mt-3 rounded-md px-3 py-2 text-sm"
             :class="idokladTestMsg.ok ? 'bg-success-50 text-success-600 border border-success-500/40' : 'bg-danger-50 text-danger-500 border border-danger-500/40'">
          {{ idokladTestMsg.text }}
        </div>

        <div class="flex items-center justify-between gap-2 mt-4 pt-4 border-t border-neutral-100">
          <button v-if="idokladStatus?.configured" type="button" @click="deleteIdokladCreds"
                  class="cursor-pointer h-10 px-4 text-sm border border-danger-500/50 text-danger-500 hover:bg-danger-50 rounded-md">
            {{ t('integrations.idoklad.delete') }}
          </button>
          <span v-else></span>
          <button type="button" @click="saveIdokladCreds" :disabled="idokladSaving"
                  class="cursor-pointer h-10 px-5 text-sm bg-primary-600 hover:bg-primary-700 disabled:bg-neutral-300 text-white font-medium rounded-md">
            {{ idokladSaving ? '…' : t('integrations.idoklad.save_and_test') }}
          </button>
        </div>

        <details class="mt-4 text-xs text-neutral-500">
          <summary class="cursor-pointer hover:text-neutral-700">{{ t('integrations.idoklad.how_to_get') }}</summary>
          <ol class="mt-2 list-decimal list-inside space-y-1">
            <li>{{ t('integrations.idoklad.step1') }}</li>
            <li>{{ t('integrations.idoklad.step2') }}</li>
            <li>{{ t('integrations.idoklad.step3') }}</li>
            <li>{{ t('integrations.idoklad.step4') }}</li>
          </ol>
        </details>
      </div>

      <!-- Box: import controls -->
      <div v-if="idokladStatus?.configured" class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm">
        <h2 class="text-sm font-medium text-neutral-700 mb-3">{{ t('integrations.idoklad.run_title') }}</h2>

        <div v-if="!isJobRunning" class="space-y-3">
          <p class="text-sm text-neutral-600">{{ t('integrations.idoklad.run_hint') }}</p>
          <div class="grid grid-cols-2 gap-3">
            <label class="flex items-center gap-2 text-sm">
              <input v-model="startParams.include_bank_accounts" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
              {{ t('integrations.idoklad.include_bank_accounts') }}
            </label>
            <label class="flex items-center gap-2 text-sm">
              <input v-model="startParams.include_bank_transactions" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
              <span :title="t('integrations.idoklad.include_bank_transactions_hint')">{{ t('integrations.idoklad.include_bank_transactions') }}</span>
            </label>
            <label class="flex items-center gap-2 text-sm">
              <input v-model="startParams.include_clients" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
              {{ t('integrations.idoklad.include_clients') }}
            </label>
            <label class="flex items-center gap-2 text-sm">
              <input v-model="startParams.include_issued" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
              {{ t('integrations.idoklad.include_issued') }}
            </label>
            <label class="flex items-center gap-2 text-sm">
              <input v-model="startParams.include_received" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
              {{ t('integrations.idoklad.include_received') }}
            </label>
            <label class="flex items-center gap-2 text-sm">
              <input v-model="startParams.incremental" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
              <span :title="t('integrations.idoklad.incremental_hint')">{{ t('integrations.idoklad.incremental') }}</span>
            </label>
            <label class="flex items-center gap-2 text-sm">
              <input v-model="startParams.download_attachments" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
              <span :title="t('integrations.idoklad.download_attachments_hint')">{{ t('integrations.idoklad.download_attachments') }}</span>
            </label>
            <label class="flex items-center gap-2 text-sm">
              <input v-model="startParams.dry_run" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
              {{ t('integrations.idoklad.dry_run') }}
            </label>
          </div>
          <button type="button" @click="startImport" :disabled="starting"
                  class="cursor-pointer w-full h-10 bg-primary-600 hover:bg-primary-700 disabled:bg-neutral-300 text-white text-sm font-medium rounded-md inline-flex items-center justify-center gap-2">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M14.752 11.168l-3.197-2.132A1 1 0 0 0 10 9.87v4.263a1 1 0 0 0 1.555.832l3.197-2.132a1 1 0 0 0 0-1.664z"/><path stroke-linecap="round" stroke-linejoin="round" d="M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0z"/></svg>
            {{ starting ? '…' : t('integrations.idoklad.start_import') }}
          </button>
        </div>

        <div v-if="currentJob" class="space-y-3">
          <div class="flex items-center justify-between text-sm">
            <div>
              <span class="font-medium">Job #{{ currentJob.id }}</span>
              <span class="ml-2 px-2 py-0.5 text-xs rounded border"
                    :class="{
                      'bg-neutral-100 text-neutral-600 border-neutral-200': currentJob.status === 'queued',
                      'bg-primary-50 text-primary-700 border-primary-500/40': currentJob.status === 'running',
                      'bg-success-50 text-success-600 border-success-500/40': currentJob.status === 'completed',
                      'bg-danger-50 text-danger-500 border-danger-500/40': currentJob.status === 'failed',
                      'bg-warning-50 text-warning-600 border-warning-500/40': currentJob.status === 'cancelled',
                    }">
                {{ t('integrations.idoklad.status.' + currentJob.status) }}
              </span>
            </div>
            <div class="flex gap-2">
              <button v-if="isJobRunning" type="button" @click="cancelImport"
                      :disabled="currentJob.cancel_requested"
                      class="cursor-pointer h-8 px-3 text-xs border border-danger-500/50 text-danger-500 hover:bg-danger-50 disabled:opacity-50 rounded-md">
                {{ currentJob.cancel_requested ? t('integrations.idoklad.cancelling') : t('integrations.idoklad.cancel') }}
              </button>
              <button type="button" @click="deleteImport"
                      class="cursor-pointer h-8 px-3 text-xs border border-neutral-300 text-neutral-600 hover:bg-neutral-100 rounded-md">
                {{ t('integrations.idoklad.delete') }}
              </button>
            </div>
          </div>

          <div v-if="currentJob.current_step" class="text-sm text-neutral-600">{{ currentJob.current_step }}</div>

          <div v-if="progressPercent !== null" class="space-y-1">
            <div class="w-full h-2 bg-neutral-100 rounded-full overflow-hidden">
              <div class="h-full bg-primary-500 transition-all" :style="{ width: progressPercent + '%' }"></div>
            </div>
            <div class="text-xs text-neutral-500 font-mono">
              {{ currentJob.processed }} / {{ currentJob.total_items }} ({{ progressPercent }}%)
            </div>
          </div>

          <div class="grid grid-cols-3 gap-2 text-sm">
            <div class="bg-success-50 border border-success-500/40 rounded p-2">
              <div class="text-xs text-success-600">{{ t('integrations.idoklad.created') }}</div>
              <div class="font-mono font-semibold text-success-600">{{ currentJob.created_count }}</div>
            </div>
            <div class="bg-warning-50 border border-warning-500/40 rounded p-2">
              <div class="text-xs text-warning-600">{{ t('integrations.idoklad.skipped') }}</div>
              <div class="font-mono font-semibold text-warning-600">{{ currentJob.skipped_count }}</div>
            </div>
            <div class="bg-danger-50 border border-danger-500/40 rounded p-2">
              <div class="text-xs text-danger-500">{{ t('integrations.idoklad.failed') }}</div>
              <div class="font-mono font-semibold text-danger-500">{{ currentJob.failed_count }}</div>
            </div>
          </div>

          <div v-if="currentJob.last_error" class="rounded-md bg-danger-50 border border-danger-500/40 px-3 py-2 text-sm text-danger-500">
            {{ currentJob.last_error }}
          </div>

          <details v-if="currentJob.log_text" class="text-xs">
            <summary class="cursor-pointer text-neutral-600 hover:text-neutral-900">{{ t('integrations.idoklad.log') }}</summary>
            <pre class="mt-2 max-h-72 overflow-y-auto bg-neutral-900 text-neutral-100 p-3 rounded font-mono text-[11px] whitespace-pre-wrap">{{ currentJob.log_text }}</pre>
          </details>
        </div>
      </div>
    </div>

    <!-- ════ Fakturoid tab ════ -->
    <div v-else-if="tab === 'fakturoid'" class="space-y-4">
      <div class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm">
        <h2 class="text-sm font-medium text-neutral-700 mb-2">{{ t('integrations.fakturoid.credentials_title') }}</h2>
        <p class="text-xs text-neutral-500 mb-4">{{ t('integrations.fakturoid.credentials_hint') }}</p>

        <div class="rounded-md bg-primary-50 border border-primary-200 px-3 py-2 text-sm text-primary-700 mb-4" v-if="fakStatus?.configured">
          <strong>✓ {{ t('integrations.idoklad.configured') }}</strong>
          <span v-if="fakStatus.slug" class="ml-2 font-mono text-xs">{{ fakStatus.slug }}</span>
          <span v-if="fakStatus.auth_mode === 'oauth2'" class="ml-2 text-xs">· OAuth2</span>
          <span v-else-if="fakStatus.auth_mode === 'basic'" class="ml-2 text-xs">· BasicAuth ({{ fakStatus.email }})</span>
        </div>

        <!-- OAuth2 vs BasicAuth auth mode picker -->
        <div class="rounded-md bg-neutral-50 border border-neutral-200 p-3 mb-4">
          <div class="flex gap-2 mb-2">
            <button type="button" @click="fakAuthMode = 'oauth2'"
                    class="cursor-pointer px-3 py-1.5 text-xs rounded-md border transition"
                    :class="fakAuthMode === 'oauth2'
                      ? 'bg-primary-600 text-white border-primary-600 font-medium'
                      : 'bg-surface text-neutral-700 border-neutral-300 hover:border-neutral-400'">
              {{ t('integrations.fakturoid.mode_oauth') }}
            </button>
            <button type="button" @click="fakAuthMode = 'basic'"
                    class="cursor-pointer px-3 py-1.5 text-xs rounded-md border transition"
                    :class="fakAuthMode === 'basic'
                      ? 'bg-primary-600 text-white border-primary-600 font-medium'
                      : 'bg-surface text-neutral-700 border-neutral-300 hover:border-neutral-400'">
              {{ t('integrations.fakturoid.mode_basic') }}
            </button>
          </div>
          <p class="text-xs text-neutral-600 leading-relaxed">
            {{ fakAuthMode === 'oauth2'
              ? t('integrations.fakturoid.mode_oauth_hint')
              : t('integrations.fakturoid.mode_basic_hint') }}
          </p>
        </div>

        <div class="space-y-3">
          <div>
            <label class="block text-sm text-neutral-700 mb-1">{{ t('integrations.fakturoid.slug') }} *</label>
            <input v-model="fakSlug" type="text" maxlength="64"
                   class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono"
                   placeholder="moje-firma" />
            <p class="text-xs text-neutral-500 mt-1">{{ t('integrations.fakturoid.slug_hint') }}</p>
          </div>

          <!-- OAuth2 block -->
          <template v-if="fakAuthMode === 'oauth2'">
            <div>
              <label class="block text-sm text-neutral-700 mb-1">{{ t('integrations.fakturoid.client_id') }} *</label>
              <input v-model="fakClientId" type="text" maxlength="190"
                     class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono"
                     placeholder="abcd1234efgh5678..." />
              <p class="text-xs text-neutral-500 mt-1">{{ t('integrations.fakturoid.client_id_hint') }}</p>
            </div>
            <div>
              <label class="block text-sm text-neutral-700 mb-1">{{ t('integrations.fakturoid.client_secret') }} *</label>
              <div class="flex gap-2">
                <input v-model="fakClientSecret" :type="fakShowSecret ? 'text' : 'password'" maxlength="512"
                       class="flex-1 h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono"
                       :placeholder="fakStatus?.has_oauth ? t('integrations.fakturoid.secret_placeholder_existing') : ''" />
                <button type="button" @click="fakShowSecret = !fakShowSecret"
                        class="cursor-pointer h-10 px-3 border border-neutral-300 rounded-md hover:bg-neutral-50 text-sm">
                  {{ fakShowSecret ? '🙈' : '👁' }}
                </button>
              </div>
              <p class="text-xs text-neutral-500 mt-1">{{ t('integrations.fakturoid.client_secret_hint') }}</p>
            </div>
          </template>

          <!-- Legacy BasicAuth block -->
          <template v-else>
            <div>
              <label class="block text-sm text-neutral-700 mb-1">{{ t('integrations.fakturoid.email') }} *</label>
              <input v-model="fakEmail" type="email" maxlength="255"
                     class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm"
                     placeholder="me@example.com" />
            </div>
            <div>
              <label class="block text-sm text-neutral-700 mb-1">{{ t('integrations.fakturoid.api_key') }} *</label>
              <div class="flex gap-2">
                <input v-model="fakApiKey" :type="fakShowKey ? 'text' : 'password'" maxlength="512"
                       class="flex-1 h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono"
                       :placeholder="fakStatus?.has_basic ? t('integrations.fakturoid.key_placeholder_existing') : ''" />
                <button type="button" @click="fakShowKey = !fakShowKey"
                        class="cursor-pointer h-10 px-3 border border-neutral-300 rounded-md hover:bg-neutral-50 text-sm">
                  {{ fakShowKey ? '🙈' : '👁' }}
                </button>
              </div>
              <p class="text-xs text-neutral-500 mt-1">{{ t('integrations.fakturoid.key_hint') }}</p>
            </div>
          </template>
        </div>

        <div v-if="fakTestMsg" class="mt-3 rounded-md px-3 py-2 text-sm"
             :class="fakTestMsg.ok ? 'bg-success-50 text-success-600 border border-success-500/40' : 'bg-danger-50 text-danger-500 border border-danger-500/40'">
          {{ fakTestMsg.text }}
        </div>

        <div class="flex items-center justify-between gap-2 mt-4 pt-4 border-t border-neutral-100">
          <button v-if="fakStatus?.configured" type="button" @click="deleteFakCreds"
                  class="cursor-pointer h-10 px-4 text-sm border border-danger-500/50 text-danger-500 hover:bg-danger-50 rounded-md">
            {{ t('integrations.idoklad.delete') }}
          </button>
          <span v-else></span>
          <button type="button" @click="saveFakCreds" :disabled="fakSaving"
                  class="cursor-pointer h-10 px-5 text-sm bg-primary-600 hover:bg-primary-700 disabled:bg-neutral-300 text-white font-medium rounded-md">
            {{ fakSaving ? '…' : t('integrations.idoklad.save_and_test') }}
          </button>
        </div>
      </div>

      <!-- Box: import controls -->
      <div v-if="fakStatus?.configured" class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm">
        <h2 class="text-sm font-medium text-neutral-700 mb-3">{{ t('integrations.idoklad.run_title') }}</h2>

        <div v-if="!isJobRunning" class="space-y-3">
          <div class="grid grid-cols-2 gap-3">
            <label class="flex items-center gap-2 text-sm">
              <input v-model="fakStartParams.include_clients" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
              {{ t('integrations.fakturoid.include_subjects') }}
            </label>
            <label class="flex items-center gap-2 text-sm">
              <input v-model="fakStartParams.include_issued" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
              {{ t('integrations.idoklad.include_issued') }}
            </label>
            <label class="flex items-center gap-2 text-sm">
              <input v-model="fakStartParams.include_received" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
              {{ t('integrations.fakturoid.include_expenses') }}
            </label>
            <label class="flex items-center gap-2 text-sm">
              <input v-model="fakStartParams.incremental" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
              <span :title="t('integrations.idoklad.incremental_hint')">{{ t('integrations.idoklad.incremental') }}</span>
            </label>
            <label class="flex items-center gap-2 text-sm">
              <input v-model="fakStartParams.download_attachments" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
              <span :title="t('integrations.idoklad.download_attachments_hint')">{{ t('integrations.idoklad.download_attachments') }}</span>
            </label>
            <label class="flex items-center gap-2 text-sm">
              <input v-model="fakStartParams.dry_run" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
              {{ t('integrations.idoklad.dry_run') }}
            </label>
          </div>
          <button type="button" @click="startFakImport" :disabled="fakStarting"
                  class="cursor-pointer w-full h-10 bg-primary-600 hover:bg-primary-700 disabled:bg-neutral-300 text-white text-sm font-medium rounded-md inline-flex items-center justify-center gap-2">
            {{ fakStarting ? '…' : t('integrations.idoklad.start_import') }}
          </button>
        </div>

        <div v-if="currentJob" class="space-y-3">
          <div class="flex items-center justify-between text-sm">
            <div>
              <span class="font-medium">Job #{{ currentJob.id }}</span>
              <span class="ml-2 px-2 py-0.5 text-xs rounded border"
                    :class="{
                      'bg-neutral-100 text-neutral-600 border-neutral-200': currentJob.status === 'queued',
                      'bg-primary-50 text-primary-700 border-primary-500/40': currentJob.status === 'running',
                      'bg-success-50 text-success-600 border-success-500/40': currentJob.status === 'completed',
                      'bg-danger-50 text-danger-500 border-danger-500/40': currentJob.status === 'failed',
                      'bg-warning-50 text-warning-600 border-warning-500/40': currentJob.status === 'cancelled',
                    }">
                {{ t('integrations.idoklad.status.' + currentJob.status) }}
              </span>
            </div>
            <div class="flex gap-2">
              <button v-if="isJobRunning" type="button" @click="cancelImport"
                      :disabled="currentJob.cancel_requested"
                      class="cursor-pointer h-8 px-3 text-xs border border-danger-500/50 text-danger-500 hover:bg-danger-50 disabled:opacity-50 rounded-md">
                {{ currentJob.cancel_requested ? t('integrations.idoklad.cancelling') : t('integrations.idoklad.cancel') }}
              </button>
              <button type="button" @click="deleteImport"
                      class="cursor-pointer h-8 px-3 text-xs border border-neutral-300 text-neutral-600 hover:bg-neutral-100 rounded-md">
                {{ t('integrations.idoklad.delete') }}
              </button>
            </div>
          </div>
          <div v-if="currentJob.current_step" class="text-sm text-neutral-600">{{ currentJob.current_step }}</div>
          <div class="grid grid-cols-3 gap-2 text-sm">
            <div class="bg-success-50 border border-success-500/40 rounded p-2">
              <div class="text-xs text-success-600">{{ t('integrations.idoklad.created') }}</div>
              <div class="font-mono font-semibold text-success-600">{{ currentJob.created_count }}</div>
            </div>
            <div class="bg-warning-50 border border-warning-500/40 rounded p-2">
              <div class="text-xs text-warning-600">{{ t('integrations.idoklad.skipped') }}</div>
              <div class="font-mono font-semibold text-warning-600">{{ currentJob.skipped_count }}</div>
            </div>
            <div class="bg-danger-50 border border-danger-500/40 rounded p-2">
              <div class="text-xs text-danger-500">{{ t('integrations.idoklad.failed') }}</div>
              <div class="font-mono font-semibold text-danger-500">{{ currentJob.failed_count }}</div>
            </div>
          </div>
          <div v-if="currentJob.last_error" class="rounded-md bg-danger-50 border border-danger-500/40 px-3 py-2 text-sm text-danger-500">
            {{ currentJob.last_error }}
          </div>
          <details v-if="currentJob.log_text" class="text-xs">
            <summary class="cursor-pointer text-neutral-600 hover:text-neutral-900">{{ t('integrations.idoklad.log') }}</summary>
            <pre class="mt-2 max-h-72 overflow-y-auto bg-neutral-900 text-neutral-100 p-3 rounded font-mono text-[11px] whitespace-pre-wrap">{{ currentJob.log_text }}</pre>
          </details>
        </div>
      </div>
    </div>

    <!-- ════ AI extrakční brána (Epic F7 — 4 provideři + EU rezidence) ════ -->
    <div v-else-if="tab === 'ai'" class="flex flex-col gap-4">
      <!-- Konfigurace brány: onboarding rozbalené když aktivní provider není nakonfig. -->
      <details :open="!aiConfigured"
               :class="['group space-y-4', aiConfigured ? 'order-2' : 'order-1']">
        <summary class="cursor-pointer select-none list-none [&::-webkit-details-marker]:hidden inline-flex items-center gap-2 px-3 py-2 rounded-md border border-neutral-200 bg-neutral-50 text-sm font-medium text-neutral-700 hover:bg-neutral-100">
          <svg class="w-4 h-4 text-neutral-400 transition-transform group-open:rotate-90" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
          </svg>
          <span>{{ t('aiGateway.settings_title') }}</span>
          <span v-if="aiConfigured" class="ml-1 font-mono text-xs font-normal text-success-600">✓ {{ providerLabel(aiProvider) }}</span>
          <span v-if="aiEuRequired" class="ml-1 px-1.5 py-0.5 rounded text-[10px] font-semibold bg-success-50 text-success-600 border border-success-500/40 inline-flex items-center gap-1">
            <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
            {{ t('aiGateway.eu_badge') }}
          </span>
        </summary>

        <!-- Privacy notice -->
        <div class="rounded-md bg-warning-50 border border-warning-500/40 px-4 py-3 text-sm text-warning-700">
          <div class="flex gap-2 items-start">
            <svg class="w-5 h-5 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01M4.93 19h14.14c1.54 0 2.5-1.67 1.73-3L13.73 4a2 2 0 0 0-3.46 0L3.2 16c-.77 1.33.19 3 1.73 3z"/>
            </svg>
            <div class="space-y-1">
              <strong>{{ t('integrations.ai.privacy_title') }}</strong>
              <p class="text-xs leading-relaxed">{{ t('aiGateway.privacy_body') }}</p>
            </div>
          </div>
        </div>

        <!-- Výběr providera + EU rezidence -->
        <div class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm space-y-4">
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-2">{{ t('aiGateway.provider_label') }}</label>
            <div class="flex flex-wrap gap-2">
              <button v-for="p in ALL_PROVIDERS" :key="p" type="button"
                :disabled="aiEuRequired && !euCapable(p)"
                @click="selectProvider(p)"
                :class="['cursor-pointer px-3 py-1.5 text-sm rounded-md border transition inline-flex items-center gap-1.5 disabled:opacity-40 disabled:cursor-not-allowed',
                  aiProvider === p ? 'bg-primary-600 text-white border-primary-600 font-medium' : 'bg-surface text-neutral-700 border-neutral-300 hover:border-neutral-400']">
                {{ providerLabel(p) }}
                <span v-if="providerConfigured(p)" class="text-success-500" :class="aiProvider === p ? 'text-white' : ''">✓</span>
              </button>
            </div>
          </div>

          <div class="flex flex-wrap items-center gap-4">
            <label class="inline-flex items-center gap-2 text-sm cursor-pointer">
              <input type="checkbox" v-model="aiEuRequired" class="rounded border-neutral-300 text-primary-600" />
              {{ t('aiGateway.eu_required') }}
            </label>
            <span v-if="aiEuRequired" class="px-2 py-0.5 rounded text-xs font-semibold bg-success-50 text-success-600 border border-success-500/40 inline-flex items-center gap-1">
              <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
              {{ t('aiGateway.eu_badge') }}
            </span>
            <div class="flex items-center gap-2">
              <label class="text-sm text-neutral-600">{{ t('aiGateway.region_label') }}</label>
              <select v-model="aiRegion" class="h-9 px-2 border border-neutral-300 rounded-md bg-surface text-sm">
                <option value="eu">{{ t('aiGateway.region_eu') }}</option>
                <option value="us" :disabled="aiEuRequired">{{ t('aiGateway.region_us') }}</option>
              </select>
            </div>
          </div>

          <p v-if="providerBlockedByEu" class="text-xs text-danger-500 inline-flex items-center gap-1">
            <svg class="w-3.5 h-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.bell" /></svg>
            {{ t('aiGateway.residency_conflict') }}
          </p>

          <div class="flex justify-end pt-2 border-t border-neutral-100">
            <button type="button" :class="btnFilled('primary')" :disabled="aiSavingSelection" @click="saveAiSelection">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
              {{ aiSavingSelection ? t('common.saving') : t('aiGateway.save_selection') }}
            </button>
          </div>
        </div>

        <!-- Per-provider credentials (klíč write-only) -->
        <div class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm">
          <h2 class="text-sm font-medium text-neutral-700 mb-1">{{ t('aiGateway.credentials_title', { provider: providerLabel(aiProvider) }) }}</h2>
          <p class="text-xs text-neutral-500 mb-4">{{ t('aiGateway.credentials_hint') }}</p>

          <div v-if="providerConfigured(aiProvider)" class="rounded-md bg-primary-50 border border-primary-200 px-3 py-2 text-sm text-primary-700 mb-4">
            <strong>✓ {{ t('integrations.idoklad.configured') }}</strong>
            <span v-if="providerInfo?.default_model" class="ml-2 font-mono text-xs">{{ providerInfo?.default_model }}</span>
            <span class="ml-3 text-xs">{{ t('aiGateway.extractions_count', { n: aiExtractCount }) }}</span>
            <span v-if="providerInfo?.residency_label" class="ml-3 text-xs">· {{ providerInfo?.residency_label }}</span>
          </div>

          <div class="space-y-3">
            <!-- API key (write-only) — anthropic/openai/gemini -->
            <div v-if="aiProvider !== 'azure_openai'">
              <label class="block text-sm text-neutral-700 mb-1">{{ t('aiGateway.api_key') }} *</label>
              <div class="flex gap-2">
                <input v-model="credForm.api_key" :type="credShowKey ? 'text' : 'password'" maxlength="512"
                       class="flex-1 h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono"
                       :placeholder="providerConfigured(aiProvider) ? t('aiGateway.key_placeholder_existing') : (aiProvider === 'anthropic' ? 'sk-ant-…' : aiProvider === 'openai' ? 'sk-…' : 'AIza…')" />
                <button type="button" @click="credShowKey = !credShowKey"
                        class="cursor-pointer h-10 px-3 border border-neutral-300 rounded-md hover:bg-neutral-50 text-sm">
                  {{ credShowKey ? '🙈' : '👁' }}
                </button>
              </div>
            </div>

            <!-- Azure OpenAI: endpoint + deployment + api-version + key -->
            <template v-if="aiProvider === 'azure_openai'">
              <div>
                <label class="block text-sm text-neutral-700 mb-1">{{ t('aiGateway.azure_endpoint') }} *</label>
                <input v-model="credForm.endpoint" type="text" maxlength="255"
                       class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono"
                       placeholder="https://my-res.openai.azure.com" />
              </div>
              <div class="grid grid-cols-2 gap-3">
                <div>
                  <label class="block text-sm text-neutral-700 mb-1">{{ t('aiGateway.azure_deployment') }} *</label>
                  <input v-model="credForm.deployment" type="text" maxlength="128"
                         class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono" placeholder="gpt-4o" />
                </div>
                <div>
                  <label class="block text-sm text-neutral-700 mb-1">{{ t('aiGateway.azure_api_version') }}</label>
                  <input v-model="credForm.api_version" type="text" maxlength="32"
                         class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono" placeholder="2024-10-21" />
                </div>
              </div>
              <div>
                <label class="block text-sm text-neutral-700 mb-1">{{ t('aiGateway.api_key') }} *</label>
                <div class="flex gap-2">
                  <input v-model="credForm.api_key" :type="credShowKey ? 'text' : 'password'" maxlength="512"
                         class="flex-1 h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono"
                         :placeholder="providerConfigured(aiProvider) ? t('aiGateway.key_placeholder_existing') : ''" />
                  <button type="button" @click="credShowKey = !credShowKey"
                          class="cursor-pointer h-10 px-3 border border-neutral-300 rounded-md hover:bg-neutral-50 text-sm">
                    {{ credShowKey ? '🙈' : '👁' }}
                  </button>
                </div>
              </div>
            </template>

            <!-- OpenAI: volitelný EU base_url -->
            <div v-if="aiProvider === 'openai'">
              <label class="block text-sm text-neutral-700 mb-1">{{ t('aiGateway.openai_base_url') }}</label>
              <input v-model="credForm.base_url" type="text" maxlength="255"
                     class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono"
                     placeholder="https://eu.api.openai.com" />
              <p class="text-xs text-neutral-500 mt-1">{{ t('aiGateway.openai_base_url_hint') }}</p>
            </div>

            <!-- Model whitelist z capability descriptoru -->
            <div v-if="models.length">
              <label class="block text-sm text-neutral-700 mb-1">{{ t('aiGateway.model') }}</label>
              <select v-model="credForm.default_model" class="w-full h-10 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
                <option v-for="m in models" :key="m" :value="m">{{ m }}</option>
              </select>
            </div>
          </div>

          <div v-if="credTestMsg" class="mt-3 rounded-md px-3 py-2 text-sm"
               :class="credTestMsg.ok ? 'bg-success-50 text-success-600 border border-success-500/40' : 'bg-danger-50 text-danger-500 border border-danger-500/40'">
            {{ credTestMsg.text }}
          </div>

          <div class="flex items-center justify-between gap-2 mt-4 pt-4 border-t border-neutral-100">
            <button v-if="providerConfigured(aiProvider)" type="button" :class="btnOutline('danger')" @click="deleteAiCredentials">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.trash" /></svg>
              {{ t('common.delete') }}
            </button>
            <span v-else></span>
            <div class="flex items-center gap-2">
              <button v-if="providerConfigured(aiProvider)" type="button" :class="btnOutline('neutral')" :disabled="credTesting" @click="testAiConnection">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.play" /></svg>
                {{ credTesting ? t('common.loading') : t('aiGateway.test_connection') }}
              </button>
              <button type="button" :class="btnFilled('primary')" :disabled="credSaving" @click="saveAiCredentials">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
                {{ credSaving ? t('common.saving') : t('integrations.idoklad.save_and_test') }}
              </button>
            </div>
          </div>
        </div>
      </details>

      <!-- Samotný AI import je vytažený do Nákup → AI import (§12b) — tady jen odkaz -->
      <div v-if="aiConfigured" class="order-1 rounded-md bg-primary-50 border border-primary-200 px-4 py-3 text-sm text-primary-700">
        {{ t('integrations.ai.import_moved') }}
        <RouterLink to="/purchase-invoices/ai-import" class="ml-1 font-medium underline hover:no-underline">
          {{ t('integrations.ai.open_import') }}
        </RouterLink>
      </div>

      <section v-if="aiAssist" class="order-3 rounded-lg border border-neutral-200 bg-surface p-5 shadow-sm">
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div>
            <h2 class="text-base font-semibold text-neutral-800">{{ t('automation.ai.settings_title') }}</h2>
            <p class="mt-1 text-xs text-neutral-500">{{ t('automation.ai.never_posts') }}</p>
          </div>
          <label class="inline-flex cursor-pointer items-center gap-2 text-sm font-medium text-neutral-700 whitespace-nowrap">
            <input v-model="aiAssistEnabled" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
            {{ t('automation.ai.enable') }}
          </label>
        </div>

        <div class="mt-4 flex flex-wrap gap-4">
          <label class="inline-flex cursor-pointer items-center gap-2 text-sm">
            <input v-model="aiAssistScopes" type="checkbox" value="bank_tx" class="rounded border-neutral-300 text-primary-600" />
            {{ t('automation.ai.scope_bank') }}
          </label>
          <label class="inline-flex cursor-pointer items-center gap-2 text-sm">
            <input v-model="aiAssistScopes" type="checkbox" value="purchase_invoices" class="rounded border-neutral-300 text-primary-600" />
            {{ t('automation.ai.scope_purchases') }}
          </label>
        </div>

        <div class="mt-4 rounded-md border border-primary-200 bg-primary-50 p-4 text-sm text-primary-800">
          <strong>{{ t('automation.ai.sent_data_title') }}</strong>
          <p class="mt-1 text-xs leading-relaxed">{{ t('automation.ai.sent_data_body') }}</p>
          <p class="mt-1 text-xs leading-relaxed">{{ t('automation.ai.never_sent_body') }}</p>
          <a href="/manual?ch=46_Automat#4611-ai-navrhy-uctovani" target="_blank" rel="noopener" class="mt-2 inline-block text-xs font-medium text-primary-700 underline">{{ t('automation.ai.manual_link') }}</a>
        </div>

        <label class="mt-4 flex cursor-pointer items-start gap-2 text-sm text-neutral-700">
          <input v-model="aiAssistDpa" type="checkbox" class="mt-0.5 rounded border-neutral-300 text-primary-600" />
          <span>{{ t('automation.ai.dpa_confirm', { provider: aiAssist.provider_label }) }}</span>
        </label>
        <p v-if="aiAssistEnabled && !aiAssistDpa" class="mt-1 text-xs text-danger-500">{{ t('automation.ai.dpa_required') }}</p>

        <div class="mt-4 grid gap-3 rounded-md bg-neutral-50 p-3 text-xs text-neutral-600 sm:grid-cols-3">
          <span>{{ t('automation.ai.knn_progress', { n: aiAssist.knn_warm.labels.bank_transaction, min: 20 }) }} · {{ t('automation.ai.scope_bank') }}</span>
          <span>{{ t('automation.ai.knn_progress', { n: aiAssist.knn_warm.labels.purchase_invoice, min: 20 }) }} · {{ t('automation.ai.scope_purchases') }}</span>
          <span>{{ t('automation.ai.usage_today', { used: aiAssist.today_used, limit: aiAssist.daily_limit }) }}</span>
        </div>

        <div v-if="aiAssist.muted_sources.length" class="mt-4 space-y-2">
          <div v-for="mute in aiAssist.muted_sources" :key="mute.source" class="flex flex-wrap items-center justify-between gap-3 rounded-md border border-warning-200 bg-warning-50 p-3 text-sm text-warning-700">
            <span>{{ t('automation.ai.muted', { source: mute.source }) }}</span>
            <button type="button" :class="btnOutline('neutral')" @click="unmuteAiSource(mute.source)">
              <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.play" /></svg>
              {{ t('automation.ai.unmute') }}
            </button>
          </div>
        </div>

        <div class="mt-4 flex flex-wrap justify-end gap-2 border-t border-neutral-100 pt-4">
          <button type="button" :class="btnFilled('primary')" :disabled="aiAssistSaving || (aiAssistEnabled && (!aiAssistDpa || aiAssistScopes.length === 0))" @click="saveAiAssist">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
            {{ aiAssistSaving ? t('common.saving') : t('common.save') }}
          </button>
        </div>
      </section>
    </div>

    <!-- Fallback (žádný tab nevyhovuje) -->
    <div v-else class="bg-surface border border-neutral-200 rounded-lg p-8 shadow-sm text-center text-neutral-500 text-sm">
      —
    </div>
  </div>
</template>

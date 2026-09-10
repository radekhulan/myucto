<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { eshopIntegrationsApi, type ConnectionInput, type IntegrationConnection, type IntegrationDiagnostics, type IntegrationStatus } from '@/api/eshopIntegrations'
import { useAuthStore } from '@/stores/auth'
import { useSupplierStore } from '@/stores/supplier'
import { useToast } from '@/composables/useToast'
import { formatDateTime } from '@/composables/useFormat'
import { btnFilled, btnOutline, ICONS } from '@/components/ui/buttonStyles'
import EmptyState from '@/components/ui/EmptyState.vue'
import CatalogJobProgress from '@/components/stock/CatalogJobProgress.vue'

const { t } = useI18n()
const auth = useAuthStore()
const supplier = useSupplierStore()
const toast = useToast()
const connections = ref<IntegrationConnection[]>([])
const selectedId = ref<number | null>(null)
const diagnostics = ref<IntegrationDiagnostics | null>(null)
const loading = ref(false)
const failed = ref(false)
const acting = ref(false)
const creating = ref(false)
const credentialJson = ref('')
const webhookSecret = ref<string | null>(null)
let timer: ReturnType<typeof setInterval> | undefined

const emptyInput = (): ConnectionInput => ({ connector_key: '', name: '', status: 'draft', mappings: {}, field_ownership: {}, rate_limit_per_minute: 60, retention_days: 30 })
const form = ref<ConnectionInput>(emptyInput())
const mappingsJson = ref('{}')
const ownershipJson = ref('{}')
const selected = computed(() => connections.value.find(row => row.id === selectedId.value) ?? null)
const canWrite = computed(() => auth.canWrite('eshop.integrations'))
const activeJob = computed(() => diagnostics.value?.jobs.find(job => ['queued', 'running'].includes(job.status)) ?? null)
const statusClass: Record<IntegrationStatus, string> = {
  draft: 'bg-neutral-100 text-neutral-700', active: 'bg-success-50 text-success-700',
  paused: 'bg-warning-50 text-warning-700', error: 'bg-danger-50 text-danger-700',
}

function applySelected(connection: IntegrationConnection | null) {
  webhookSecret.value = null
  diagnostics.value = null
  if (!connection) {
    form.value = emptyInput()
    mappingsJson.value = '{}'
    ownershipJson.value = '{}'
    return
  }
  form.value = {
    connector_key: connection.connector_key, name: connection.name, status: connection.status,
    mappings: connection.mappings, field_ownership: connection.field_ownership,
    rate_limit_per_minute: connection.rate_limit_per_minute, retention_days: connection.retention_days,
  }
  mappingsJson.value = JSON.stringify(connection.mappings, null, 2)
  ownershipJson.value = JSON.stringify(connection.field_ownership, null, 2)
  void loadDiagnostics(connection.id)
}

watch(selected, applySelected)
watch(() => supplier.currentSupplierId, () => { selectedId.value = null; void load() })

async function load(silent = false) {
  if (!silent) loading.value = true
  try {
    connections.value = await eshopIntegrationsApi.list()
    failed.value = false
    if (selectedId.value && !connections.value.some(row => row.id === selectedId.value)) selectedId.value = null
  } catch (e: any) {
    failed.value = true
    if (!silent) toast.error(e?.response?.data?.error?.message || t('common.error'))
  } finally { loading.value = false }
}

async function loadDiagnostics(id: number) {
  try { diagnostics.value = await eshopIntegrationsApi.diagnostics(id) }
  catch (e: any) { toast.error(e?.response?.data?.error?.message || t('common.error')) }
}

function parseObject(value: string): Record<string, any> {
  const parsed = JSON.parse(value)
  if (!parsed || Array.isArray(parsed) || typeof parsed !== 'object') throw new Error(t('eshop.integrations.json_object_required'))
  return parsed
}

async function save() {
  if (acting.value) return
  acting.value = true
  try {
    form.value.mappings = parseObject(mappingsJson.value)
    form.value.field_ownership = parseObject(ownershipJson.value)
    const saved = selected.value
      ? await eshopIntegrationsApi.update(selected.value.id, form.value)
      : await eshopIntegrationsApi.create(form.value)
    await load(true)
    selectedId.value = saved.id
    creating.value = false
    toast.success(t('common.saved'))
  } catch (e: any) { toast.error(e?.response?.data?.error?.message || e?.message || t('common.error')) }
  finally { acting.value = false }
}

async function saveCredentials() {
  if (!selected.value || acting.value) return
  acting.value = true
  try {
    const credentials = parseObject(credentialJson.value)
    await eshopIntegrationsApi.credentials(selected.value.id, credentials)
    credentialJson.value = ''
    await load(true)
    toast.success(t('eshop.integrations.credentials_saved'))
  } catch (e: any) { toast.error(e?.response?.data?.error?.message || e?.message || t('common.error')) }
  finally { acting.value = false }
}

async function rotateSecret() {
  if (!selected.value || acting.value) return
  acting.value = true
  try {
    webhookSecret.value = (await eshopIntegrationsApi.rotateWebhookSecret(selected.value.id)).secret
    await load(true)
    toast.success(t('eshop.integrations.webhook_rotated'))
  } catch (e: any) { toast.error(e?.response?.data?.error?.message || t('common.error')) }
  finally { acting.value = false }
}

async function reconcile() {
  if (!selected.value || acting.value) return
  acting.value = true
  try { await eshopIntegrationsApi.reconcile(selected.value.id); await loadDiagnostics(selected.value.id); toast.success(t('eshop.integrations.reconcile_queued')) }
  catch (e: any) { toast.error(e?.response?.data?.error?.message || t('common.error')) }
  finally { acting.value = false }
}

async function retry(eventId: number) {
  if (!selected.value || acting.value) return
  acting.value = true
  try { await eshopIntegrationsApi.retryOutbox(selected.value.id, eventId); await loadDiagnostics(selected.value.id); toast.success(t('eshop.integrations.retry_queued')) }
  catch (e: any) { toast.error(e?.response?.data?.error?.message || t('common.error')) }
  finally { acting.value = false }
}

function startCreate() { selectedId.value = null; creating.value = true; applySelected(null) }
onMounted(async () => { await load(); timer = setInterval(() => { if (selected.value && activeJob.value) void loadDiagnostics(selected.value.id) }, 3000) })
onBeforeUnmount(() => { if (timer) clearInterval(timer) })
</script>

<template>
  <div>
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
      <div><h1 class="text-2xl font-semibold">{{ t('eshop.integrations.title') }}</h1><p class="mt-0.5 text-sm text-neutral-500">{{ t('eshop.integrations.subtitle') }}</p></div>
      <button v-if="canWrite" type="button" :class="btnFilled('primary')" @click="startCreate"><svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path :d="ICONS.plus" /></svg>{{ t('eshop.integrations.new') }}</button>
    </div>
    <div v-if="loading" class="py-12 text-center text-sm text-neutral-500">{{ t('common.loading') }}</div>
    <EmptyState v-else-if="failed" variant="failed" boxed @action="load()" />
    <EmptyState v-else-if="connections.length === 0 && !creating" boxed accent="accent" icon="link" :title="t('eshop.integrations.empty_title')" :message="t('eshop.integrations.empty_hint')" :cta="canWrite ? t('eshop.integrations.new') : undefined" @action="startCreate" />
    <div v-else class="grid min-w-0 grid-cols-1 gap-4 lg:grid-cols-[minmax(15rem,20rem)_minmax(0,1fr)]">
      <nav class="space-y-2" :aria-label="t('eshop.integrations.connections')">
        <button v-for="connection in connections" :key="connection.id" type="button" class="w-full rounded-lg border bg-surface p-3 text-left shadow-sm transition-colors hover:border-primary-400" :class="selectedId === connection.id ? 'border-primary-500 ring-1 ring-primary-500/20' : 'border-neutral-200'" @click="selectedId = connection.id; creating = false">
          <span class="flex flex-wrap items-center justify-between gap-2"><strong class="truncate">{{ connection.name }}</strong><span class="rounded px-2 py-0.5 text-xs font-medium" :class="statusClass[connection.status]">{{ t(`eshop.integrations.status.${connection.status}`) }}</span></span>
          <span class="mt-1 block truncate text-xs text-neutral-500">{{ connection.connector_key }}</span>
        </button>
      </nav>

      <section v-if="selected || creating" class="min-w-0 space-y-4">
        <form class="rounded-lg border border-neutral-200 bg-surface p-4 shadow-sm" @submit.prevent="save">
          <h2 class="mb-3 text-lg font-semibold">{{ selected ? selected.name : t('eshop.integrations.new') }}</h2>
          <div class="grid min-w-0 grid-cols-1 gap-3 sm:grid-cols-2">
            <label class="text-sm"><span class="mb-1 block font-medium">{{ t('eshop.integrations.name') }}</span><input v-model="form.name" required maxlength="150" class="form-input w-full" /></label>
            <label class="text-sm"><span class="mb-1 block font-medium">{{ t('eshop.integrations.connector') }}</span><input v-model="form.connector_key" required pattern="[a-z][a-z0-9_.-]+" class="form-input w-full" /></label>
            <label class="text-sm"><span class="mb-1 block font-medium">{{ t('eshop.integrations.status_label') }}</span><select v-model="form.status" class="form-select w-full"><option value="draft">{{ t('eshop.integrations.status.draft') }}</option><option value="active">{{ t('eshop.integrations.status.active') }}</option><option value="paused">{{ t('eshop.integrations.status.paused') }}</option><option v-if="form.status === 'error'" value="error" disabled>{{ t('eshop.integrations.status.error') }}</option></select></label>
            <label class="text-sm"><span class="mb-1 block font-medium">{{ t('eshop.integrations.rate_limit') }}</span><input v-model.number="form.rate_limit_per_minute" type="number" min="1" max="6000" class="form-input w-full" /></label>
            <label class="text-sm"><span class="mb-1 block font-medium">{{ t('eshop.integrations.retention') }}</span><input v-model.number="form.retention_days" type="number" min="1" max="365" class="form-input w-full" /></label>
          </div>
          <div class="mt-3 grid min-w-0 grid-cols-1 gap-3 lg:grid-cols-2">
            <label class="text-sm"><span class="mb-1 block font-medium">{{ t('eshop.integrations.mappings') }}</span><textarea v-model="mappingsJson" rows="8" spellcheck="false" class="form-textarea w-full font-mono text-xs"></textarea><span class="mt-1 block text-xs text-neutral-500">{{ t('eshop.integrations.mappings_hint') }}</span></label>
            <label class="text-sm"><span class="mb-1 block font-medium">{{ t('eshop.integrations.ownership') }}</span><textarea v-model="ownershipJson" rows="8" spellcheck="false" class="form-textarea w-full font-mono text-xs"></textarea><span class="mt-1 block text-xs text-neutral-500">{{ t('eshop.integrations.ownership_hint') }}</span></label>
          </div>
          <div v-if="canWrite" class="mt-4 flex flex-wrap gap-2"><button type="submit" :disabled="acting" :class="btnFilled('primary')"><svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path :d="ICONS.check" /></svg>{{ t('common.save') }}</button></div>
        </form>

        <div v-if="selected" class="grid min-w-0 grid-cols-1 gap-4 xl:grid-cols-2">
          <section class="rounded-lg border border-neutral-200 bg-surface p-4 shadow-sm"><h3 class="font-semibold">{{ t('eshop.integrations.credentials') }}</h3><p class="mt-1 text-xs text-neutral-500">{{ t('eshop.integrations.credentials_hint') }}</p><textarea v-model="credentialJson" rows="5" spellcheck="false" autocomplete="off" class="form-textarea mt-3 w-full font-mono text-xs" placeholder="{}"></textarea><div class="mt-3 flex flex-wrap gap-2"><button v-if="canWrite" type="button" :disabled="acting || !credentialJson" :class="btnOutline('success')" @click="saveCredentials"><svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path :d="ICONS.lock" /></svg>{{ t('eshop.integrations.save_credentials') }}</button><span class="self-center text-xs" :class="selected.credentials_configured ? 'text-success-700' : 'text-neutral-500'">{{ t(selected.credentials_configured ? 'eshop.integrations.configured' : 'eshop.integrations.not_configured') }}</span></div></section>
          <section class="rounded-lg border border-neutral-200 bg-surface p-4 shadow-sm"><h3 class="font-semibold">{{ t('eshop.integrations.webhook') }}</h3><p class="mt-1 break-all text-xs text-neutral-500">/api/public/integrations/webhooks/{{ selected.connection_uuid }}</p><div class="mt-3 flex flex-wrap gap-2"><button v-if="canWrite" type="button" :disabled="acting" :class="btnOutline('warning')" @click="rotateSecret"><svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path :d="ICONS.cycle" /></svg>{{ t('eshop.integrations.rotate_secret') }}</button></div><div v-if="webhookSecret" class="mt-3 rounded border border-warning-200 bg-warning-50 p-3"><p class="text-xs font-medium text-warning-700">{{ t('eshop.integrations.secret_once') }}</p><code class="mt-1 block break-all select-all text-xs">{{ webhookSecret }}</code></div></section>
        </div>

        <section v-if="selected" class="rounded-lg border border-neutral-200 bg-surface p-4 shadow-sm">
          <div class="flex flex-wrap items-start justify-between gap-3"><div><h3 class="font-semibold">{{ t('eshop.integrations.diagnostics') }}</h3><p class="mt-1 text-xs text-neutral-500">{{ diagnostics?.last_synced_at ? formatDateTime(diagnostics.last_synced_at) : t('eshop.integrations.never_synced') }}</p></div><button v-if="canWrite" type="button" :disabled="acting" :class="btnOutline('primary')" @click="reconcile"><svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path :d="ICONS.cycle" /></svg>{{ t('eshop.integrations.reconcile') }}</button></div>
          <CatalogJobProgress v-if="activeJob" class="mt-3" :job="activeJob" />
          <div v-if="diagnostics" class="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-4"><div class="rounded bg-neutral-50 p-2"><div class="text-xs text-neutral-500">{{ t('eshop.integrations.inbox_pending') }}</div><strong>{{ (diagnostics.inbox.queued || 0) + (diagnostics.inbox.retry || 0) }}</strong></div><div class="rounded bg-neutral-50 p-2"><div class="text-xs text-neutral-500">{{ t('eshop.integrations.outbox_pending') }}</div><strong>{{ (diagnostics.outbox.pending || 0) + (diagnostics.outbox.retry || 0) }}</strong></div><div class="rounded bg-danger-50 p-2"><div class="text-xs text-danger-700">{{ t('eshop.integrations.dead_letters') }}</div><strong>{{ (diagnostics.inbox.dead_letter || 0) + (diagnostics.outbox.dead_letter || 0) }}</strong></div><div class="rounded bg-success-50 p-2"><div class="text-xs text-success-700">{{ t('eshop.integrations.delivered') }}</div><strong>{{ diagnostics.outbox.delivered || 0 }}</strong></div></div>
          <div v-if="diagnostics?.errors.length" class="mt-4 overflow-x-auto"><table class="w-full text-sm"><thead><tr class="border-b border-neutral-200 text-left text-xs text-neutral-500"><th class="p-2">{{ t('eshop.integrations.direction') }}</th><th class="p-2">{{ t('eshop.integrations.entity') }}</th><th class="p-2">{{ t('eshop.integrations.error') }}</th><th class="p-2"></th></tr></thead><tbody><tr v-for="error in diagnostics.errors" :key="`${error.direction}-${error.id}`" class="border-b border-neutral-100"><td class="p-2">{{ error.direction }}</td><td class="p-2"><span class="block">{{ error.entity_type }} · {{ error.entity_id }}</span><span class="text-xs text-neutral-500">{{ error.event_type }} · v{{ error.aggregate_version }}</span></td><td class="p-2 text-danger-700">{{ error.last_error_code }}</td><td class="p-2 text-right"><button v-if="canWrite && error.direction === 'outbox' && error.status === 'dead_letter' && !error.payload_redacted" type="button" :disabled="acting" :class="btnOutline('warning')" @click="retry(error.id)"><svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path :d="ICONS.cycle" /></svg>{{ t('common.retry') }}</button></td></tr></tbody></table></div>
        </section>
      </section>
      <EmptyState v-else dense boxed accent="neutral" icon="link" :title="t('eshop.integrations.select_title')" :message="t('eshop.integrations.select_hint')" />
    </div>
  </div>
</template>

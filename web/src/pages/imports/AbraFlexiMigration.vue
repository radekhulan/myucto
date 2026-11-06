<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { abraFlexiApi, type AbraFlexiConnection, type AbraFlexiRun } from '@/api/abraFlexi'
import { cancelImportJob, fetchImportJob, type FileImportJob } from '@/api/imports'
import { useSupplierStore } from '@/stores/supplier'
import { useActivationStatus } from '@/composables/useActivationStatus'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import { btnFilled, ICONS } from '@/components/ui/buttonStyles'
import ImportJobProgress from '@/components/exchange/ImportJobProgress.vue'
import MigrationNonprofitNotice from '@/components/migration/MigrationNonprofitNotice.vue'

const { t, te, locale } = useI18n()
const supplier = useSupplierStore()
const activation = useActivationStatus()
const connection = ref<AbraFlexiConnection | null>(null)
const selectedYears = ref<number[]>([])
const url = ref('')
const username = ref('')
const password = ref('')
const editing = ref(false)
const busy = ref(false)
const cancelling = ref(false)
const error = ref('')
const job = ref<FileImportJob | null>(null)
const runs = ref<AbraFlexiRun[]>([])
const selectedRunId = ref<number | null>(null)
const selectedRun = ref<AbraFlexiRun | null>(null)
const protocolWarnings = computed(() => Array.from(new Set([
  ...(selectedRun.value?.protocol?.warnings ?? []),
  ...(selectedRun.value?.protocol?.report?.warnings ?? []),
  ...(selectedRun.value?.protocol?.report?.blockers ?? []),
  ...(selectedRun.value?.protocol?.reconciliation?.warnings ?? []),
  ...(selectedRun.value?.protocol?.report?.reconciliation?.warnings ?? []),
])))
const reconciliation = computed(() => selectedRun.value?.protocol?.reconciliation ?? selectedRun.value?.protocol?.report?.reconciliation)
const blocked = computed(() => selectedRun.value?.protocol?.blocked || selectedRun.value?.protocol?.report?.blocked || !!selectedRun.value?.protocol?.report?.blockers?.length)
let generation = 0
let pollVersion = 0
let timer: ReturnType<typeof setTimeout> | undefined
const running = computed(() => !!connection.value?.active_job_id || job.value?.status === 'queued' || job.value?.status === 'running')
const percent = computed(() => job.value?.total_items ? Math.min(100, Math.round(job.value.processed / job.value.total_items * 100)) : null)
const terminal = computed(() => job.value && !['queued', 'running'].includes(job.value.status))
const currentStep = computed(() => running.value || connection.value?.imported || terminal.value ? 3 : connection.value?.configured && !editing.value ? 2 : 1)
const lastSynced = computed(() => connection.value?.last_synced_at ? new Date(connection.value.last_synced_at).toLocaleString(locale.value) : t('abra_flexi.never'))
const catalogSynced = computed(() => connection.value?.catalog?.last_synced_at
  ? new Date(connection.value.catalog.last_synced_at).toLocaleString(locale.value) : t('abra_flexi.never'))

function clearCredentials(): void {
  url.value = ''
  username.value = ''
  password.value = ''
}

function applyConnection(value: AbraFlexiConnection): void {
  clearCredentials()
  connection.value = value
  const available = new Set(value.years.map(item => item.year))
  selectedYears.value = (value.selected_years.length ? value.selected_years : value.default_years).filter(year => available.has(year))
  editing.value = !value.configured
}

function message(caught: unknown): string {
  return (caught as { response?: { data?: { error?: { message?: string } } } }).response?.data?.error?.message || t('abra_flexi.failed')
}

function issueLabel(issue: string): string {
  const code = issue.split(':')[0] ?? ''
  if (!/^[a-z][a-z0-9_]{1,79}$/.test(code)) return issue
  return te(`abra_flexi.issues.${code}`) ? t(`abra_flexi.issues.${code}`) : t('abra_flexi.issue_code', { code })
}

function runStatus(status: string): string {
  return ['completed', 'completed_with_warnings', 'failed', 'cancelled'].includes(status) ? t(`abra_flexi.status.${status}`) : t('imports.job_running')
}

async function loadRuns(scope: number, jobId?: number): Promise<void> {
  const result = await abraFlexiApi.runs()
  if (scope !== generation) return
  runs.value = result
  selectedRunId.value = jobId === undefined ? result[0]?.id ?? null : result.find(run => run.job_id === jobId)?.id ?? null
}

function stopPolling(): number {
  if (timer) clearTimeout(timer)
  return ++pollVersion
}

async function poll(id: number, scope: number, version: number): Promise<void> {
  if (scope !== generation || version !== pollVersion) return
  try {
    const result = await fetchImportJob(id)
    if (scope !== generation || version !== pollVersion) return
    job.value = result
    if (['queued', 'running'].includes(result.status)) {
      timer = setTimeout(() => { void poll(id, scope, version) }, 1500)
    } else {
      cancelling.value = false
      const state = await abraFlexiApi.connection()
      if (scope === generation && version === pollVersion) {
        applyConnection(state)
        await loadRuns(scope, id)
        if (['completed', 'completed_with_warnings'].includes(result.status)) {
          void activation.refresh(true).catch(() => undefined)
        }
      }
    }
  } catch (caught) {
    if (scope === generation && version === pollVersion) error.value = message(caught)
  }
}

async function perform(operation: () => Promise<void>): Promise<void> {
  if (busy.value) return
  const scope = generation
  busy.value = true
  error.value = ''
  try { await operation() }
  catch (caught) { if (scope === generation) error.value = message(caught) }
  finally { if (scope === generation) busy.value = false }
}

async function refresh(): Promise<void> {
  if (busy.value) return
  const scope = generation
  const version = stopPolling()
  await perform(async () => {
    const result = await abraFlexiApi.connection()
    if (scope !== generation) return
    applyConnection(result)
    void activation.refresh(true).catch(() => undefined)
    const activeId = result.active_job_id ?? (job.value && ['queued', 'running'].includes(job.value.status) ? job.value.id : null)
    if (activeId) void poll(activeId, scope, version)
    else await loadRuns(scope, job.value?.id)
  })
}

async function save(): Promise<void> {
  if (busy.value || running.value || !url.value.trim() || !username.value.trim() || !password.value) return
  const scope = generation
  const request = abraFlexiApi.save({ url: url.value.trim(), username: username.value.trim(), password: password.value })
  clearCredentials()
  await perform(async () => {
    const result = await request
    if (scope === generation) applyConnection(result)
  })
}

async function discover(): Promise<void> {
  const scope = generation
  await perform(async () => {
    const result = await abraFlexiApi.discover()
    if (scope === generation) applyConnection(result)
  })
}

async function start(): Promise<void> {
  if (running.value || !connection.value?.configured || connection.value.ico_mismatch || (!connection.value.imported && !selectedYears.value.length)) return
  const scope = generation
  await perform(async () => {
    const result = connection.value?.imported ? await abraFlexiApi.sync() : await abraFlexiApi.start([...selectedYears.value])
    if (scope !== generation) return
    job.value = null
    if (connection.value) connection.value.active_job_id = result.job_id
    void poll(result.job_id, scope, stopPolling())
  })
}

async function startCatalog(): Promise<void> {
  if (running.value || !connection.value?.imported) return
  const scope = generation
  await perform(async () => {
    const result = await abraFlexiApi.catalog()
    if (scope !== generation) return
    job.value = null
    if (connection.value) connection.value.active_job_id = result.job_id
    void poll(result.job_id, scope, stopPolling())
  })
}

async function remove(): Promise<void> {
  if (connection.value?.imported || running.value) return
  const scope = generation
  await perform(async () => {
    await abraFlexiApi.remove()
    if (scope !== generation) return
    const result = await abraFlexiApi.connection()
    if (scope === generation) { clearCredentials(); job.value = null; applyConnection(result) }
  })
}

async function cancel(): Promise<void> {
  const id = job.value?.id ?? connection.value?.active_job_id
  if (!id || cancelling.value) return
  const scope = generation
  cancelling.value = true
  try { await cancelImportJob(id) }
  catch (caught) {
    if (scope === generation) { cancelling.value = false; error.value = message(caught) }
  }
}

const actions = computed<ActionItem[]>(() => [
  { key: 'start', label: t(connection.value?.imported ? 'abra_flexi.sync' : 'abra_flexi.start'), icon: 'play', tier: 'primary', variant: 'primary', show: !!connection.value?.configured, disabled: busy.value || running.value || editing.value || !!connection.value?.ico_mismatch || (!connection.value?.imported && !selectedYears.value.length), run: () => { void start() } },
  { key: 'catalog', label: t(connection.value?.catalog ? 'abra_flexi.catalog_sync' : 'abra_flexi.catalog_start'), icon: 'stock_items', tier: 'secondary', variant: 'neutral', show: !!connection.value?.imported, disabled: busy.value || running.value || editing.value, run: () => { void startCatalog() } },
  { key: 'discover', label: t('abra_flexi.discover'), icon: 'cycle', tier: 'secondary', variant: 'neutral', show: !!connection.value?.configured && !connection.value?.imported, disabled: busy.value || running.value, run: () => { void discover() } },
  { key: 'refresh', label: t('abra_flexi.refresh'), icon: 'cycle', tier: 'secondary', variant: 'neutral', disabled: busy.value, run: () => { void refresh() } },
  { key: 'edit', label: t('abra_flexi.edit'), icon: 'edit', tier: 'secondary', variant: 'neutral', show: !!connection.value?.configured, disabled: busy.value || running.value, run: () => { editing.value = !editing.value; clearCredentials() } },
  { key: 'remove', label: t('abra_flexi.remove'), icon: 'trash', tier: 'overflow', variant: 'danger', show: !!connection.value?.configured && !connection.value?.imported, disabled: busy.value || running.value, run: () => { void remove() } },
])

let runRequest = 0
watch([selectedRunId, runs], async () => {
  const request = ++runRequest
  const scope = generation
  const id = selectedRunId.value
  selectedRun.value = null
  if (!id) return
  try {
    const result = await abraFlexiApi.run(id)
    if (scope === generation && request === runRequest) selectedRun.value = result
  } catch (caught) {
    if (scope === generation && request === runRequest) error.value = message(caught)
  }
})

watch(() => supplier.currentSupplierId, () => {
  generation++
  stopPolling()
  clearCredentials()
  connection.value = null
  selectedYears.value = []
  job.value = null
  runs.value = []
  selectedRunId.value = null
  selectedRun.value = null
  error.value = ''
  busy.value = false
  cancelling.value = false
  editing.value = false
  if (supplier.currentSupplierId) void refresh()
}, { immediate: true })

onBeforeUnmount(() => { generation++; stopPolling(); clearCredentials() })
</script>

<template>
  <div class="mx-auto max-w-6xl space-y-5">
    <div>
      <h1 class="text-2xl font-semibold">{{ t('abra_flexi.title') }}</h1>
      <p class="text-sm text-neutral-500 mt-1">{{ t('abra_flexi.intro') }}</p>
    </div>
    <MigrationNonprofitNotice :notice="connection?.nonprofit_notice" />
    <div class="rounded-lg border border-warning-500/30 bg-warning-50 px-4 py-3 text-sm text-warning-700" data-testid="abra-flexi-support-notice">
      <i18n-t keypath="abra_flexi.support_notice" tag="p">
        <template #recommend><strong><i18n-t keypath="abra_flexi.support_notice_recommend" tag="span"><template #contact><a href="https://myucto.cz/support#placena" target="_blank" rel="noopener" class="underline hover:no-underline">{{ t('abra_flexi.support_notice_contact') }}</a></template></i18n-t></strong></template>
      </i18n-t>
    </div>
    <ol class="grid grid-cols-1 gap-2 sm:grid-cols-3" :aria-label="t('abra_flexi.steps')">
      <li v-for="step in [1, 2, 3]" :key="step" class="flex items-center rounded-lg border px-3 py-3 text-sm"
        :aria-current="step === currentStep ? 'step' : undefined"
        :class="step === currentStep ? 'border-primary-500 bg-primary-50 text-primary-700' : step < currentStep ? 'border-success-500/40 bg-success-50 text-success-600' : 'border-neutral-200 text-neutral-400'">
        <span class="mr-2 inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-full border text-xs font-semibold">
          <svg v-if="step < currentStep" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path :d="ICONS.check" /></svg>
          <template v-else>{{ step }}</template>
        </span>{{ t(`abra_flexi.step${step}`) }}
      </li>
    </ol>
    <p v-if="error" role="alert" class="rounded-md border border-danger-200 bg-danger-50 p-3 text-sm text-danger-700">{{ error }}</p>
    <ActionBar v-if="!connection && supplier.currentSupplierId" :actions="actions" />
    <section v-if="connection" class="rounded-lg border border-neutral-200 bg-surface p-5 shadow-sm space-y-4">
      <div class="flex flex-wrap items-center justify-between gap-3">
        <h2 class="text-lg font-semibold">{{ t('abra_flexi.connection') }}</h2>
        <span class="text-sm" :class="connection.configured ? 'text-success-600' : 'text-neutral-500'">{{ t(connection.configured ? 'abra_flexi.connected' : 'abra_flexi.not_connected') }}</span>
      </div>
      <div class="rounded-lg border border-neutral-200 bg-neutral-50 px-4 py-3 text-sm" data-testid="abra-connection-help">
        <div class="grid gap-5 lg:grid-cols-2">
          <div>
            <h3 class="mb-2 font-medium text-neutral-700">{{ t('abra_flexi.connection_help_title') }}</h3>
            <ol class="list-decimal space-y-1 pl-5 text-neutral-600">
              <li>{{ t('abra_flexi.connection_help_url') }}</li>
              <li>{{ t('abra_flexi.connection_help_user') }}</li>
              <li>{{ t('abra_flexi.connection_help_save') }}</li>
            </ol>
          </div>
          <div>
            <h3 class="mb-2 font-medium text-neutral-700">{{ t('abra_flexi.transfer_help_title') }}</h3>
            <ol class="list-decimal space-y-1 pl-5 text-neutral-600">
              <li>{{ t('abra_flexi.transfer_help_years') }}</li>
              <li>{{ t('abra_flexi.transfer_help_job') }}</li>
              <li>{{ t('abra_flexi.transfer_help_sync') }}</li>
            </ol>
          </div>
        </div>
        <p class="mt-3 text-neutral-600">{{ t('abra_flexi.security_help') }}</p>
      </div>
      <fieldset class="space-y-3" data-testid="abra-transfer-scope">
        <legend class="font-medium">{{ t('abra_flexi.scope_title') }}</legend>
        <p class="text-sm text-neutral-500">{{ t('abra_flexi.scope_hint') }}</p>
        <div class="grid gap-3 lg:grid-cols-2">
          <label class="rounded-lg border border-primary-500 bg-primary-50 px-4 py-3">
            <span class="flex items-center gap-2 text-sm font-medium text-primary-700"><input type="checkbox" checked disabled />{{ t('abra_flexi.scope_accounting') }}</span>
            <span class="mt-2 block text-sm text-neutral-600">{{ t('abra_flexi.scope_accounting_hint') }}</span>
          </label>
          <div class="rounded-lg border border-neutral-200 px-4 py-3">
            <span class="flex flex-wrap items-center gap-2 text-sm font-medium">{{ t('abra_flexi.scope_catalog') }}<span class="rounded-full bg-primary-50 px-2 py-0.5 text-xs text-primary-700 whitespace-nowrap">{{ t('abra_flexi.addon_available') }}</span></span>
            <span class="mt-2 block text-sm text-neutral-600">{{ t('abra_flexi.scope_catalog_hint') }}</span>
            <span v-if="connection.imported" class="mt-2 block text-xs text-neutral-500">{{ t('abra_flexi.catalog_last_synced', { date: catalogSynced }) }}</span>
          </div>
        </div>
      </fieldset>
      <p v-if="connection.source_company" class="text-sm">{{ connection.source_company.name }} ({{ connection.source_company.ico }})</p>
      <div v-if="connection.ico_mismatch" role="alert" data-testid="abra-ico-mismatch" class="rounded-md border border-danger-200 bg-danger-50 p-4 space-y-1 text-sm text-danger-700">
        <h3 class="font-medium">{{ t('abra_flexi.ico_mismatch_title') }}</h3>
        <p>{{ t('abra_flexi.ico_mismatch', { source: connection.source_company?.name || '?', source_ico: connection.source_company?.ico || '?', target: connection.target_company?.name || '?', target_ico: connection.target_company?.ico || '?' }) }}</p>
      </div>
      <form v-if="editing" autocomplete="off" class="space-y-4" @submit.prevent="save">
        <p class="text-sm text-neutral-500">{{ t('abra_flexi.credentials_hint') }}</p>
        <div class="grid gap-4 md:grid-cols-2">
          <label class="block md:col-span-2"><span class="text-sm font-medium">{{ t('abra_flexi.url') }}</span><input v-model="url" data-testid="abra-url" type="url" autocomplete="off" required :placeholder="t('abra_flexi.url_example')" :disabled="busy || running" class="form-input mt-1 block w-full" /></label>
          <label class="block"><span class="text-sm font-medium">{{ t('abra_flexi.username') }}</span><input v-model="username" data-testid="abra-username" autocomplete="off" required :disabled="busy || running" class="form-input mt-1 block w-full" /></label>
          <label class="block"><span class="text-sm font-medium">{{ t('abra_flexi.password') }}</span><input v-model="password" data-testid="abra-password" type="password" autocomplete="new-password" required :disabled="busy || running" class="form-input mt-1 block w-full" /></label>
        </div>
        <div class="flex flex-wrap gap-2"><button type="submit" :class="btnFilled('primary')" :disabled="busy || running || !url.trim() || !username.trim() || !password"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path :d="ICONS.check" /></svg>{{ t('abra_flexi.save') }}</button></div>
      </form>
      <template v-if="connection.configured">
        <fieldset v-if="!connection.imported" data-testid="abra-years" :disabled="busy || running" class="space-y-3">
          <legend class="font-medium">{{ t('abra_flexi.years') }}</legend>
          <p class="text-sm text-neutral-500">{{ t('abra_flexi.years_hint') }}</p>
          <p v-if="!connection.years.length" class="text-sm text-warning-600">{{ t('abra_flexi.no_years') }}</p>
          <div class="flex flex-wrap gap-4"><label v-for="year in connection.years" :key="year.year" class="inline-flex items-center gap-2 text-sm"><input v-model="selectedYears" type="checkbox" :value="year.year" />{{ year.year }} <span class="text-neutral-500">({{ year.starts_on }} - {{ year.ends_on }})</span></label></div>
        </fieldset>
        <p v-else data-testid="abra-imported" class="text-sm text-neutral-500">{{ t('abra_flexi.imported_hint') }}</p>
        <p class="text-sm text-neutral-500">{{ t('abra_flexi.last_synced', { date: lastSynced }) }}</p>
      </template>
      <ActionBar :actions="actions" />
    </section>
    <ImportJobProgress :job="job" :percent="percent" :cancelling="cancelling" :show-cancel="!terminal" :background-hint-key="job?.current_step?.includes('Ceník') ? 'abra_flexi.catalog_progress' : 'abra_flexi.atomic_progress'" @cancel="cancel" />
    <div v-if="terminal && job" data-testid="abra-summary" class="rounded-lg border border-neutral-200 bg-surface p-5 space-y-2" aria-live="polite">
      <h2 class="font-semibold">{{ t('abra_flexi.result') }}</h2>
      <p>{{ t(`abra_flexi.status.${job.status}`) }}</p>
      <p class="text-sm">{{ t('abra_flexi.counts', { created: job.created_count, skipped: job.skipped_count, failed: job.failed_count }) }}</p>
      <p v-if="job.last_error" class="text-sm text-danger-600">{{ job.last_error }}</p>
    </div>
    <div v-if="runs.length" class="rounded-lg border border-neutral-200 bg-surface p-5 space-y-4" data-testid="abra-protocol">
      <h2 class="font-semibold">{{ t('abra_flexi.history') }}</h2>
      <label class="block text-sm">{{ t('abra_flexi.run') }}<select v-model="selectedRunId" class="form-select block mt-1 w-full"><option v-for="run in runs" :key="run.id" :value="run.id">{{ t('abra_flexi.run_number', { id: run.id }) }}</option></select></label>
      <p v-if="selectedRun" class="text-sm">{{ runStatus(selectedRun.status) }}</p>
      <p v-if="selectedRun?.protocol?.counts" class="text-sm">{{ t('abra_flexi.counts', { created: selectedRun.protocol.counts.created ?? 0, skipped: selectedRun.protocol.counts.skipped ?? 0, failed: selectedRun.protocol.counts.failed ?? 0 }) }}</p>
      <p v-if="blocked" class="text-sm text-danger-600">{{ t('abra_flexi.blocked') }}</p>
      <div v-if="reconciliation && typeof reconciliation.ok === 'boolean'" data-testid="abra-reconciliation" class="space-y-2 text-sm">
        <p :class="reconciliation.ok ? 'text-success-600' : 'text-danger-600'">{{ t(reconciliation.ok ? 'abra_flexi.reconciliation_ok' : 'abra_flexi.reconciliation_failed') }}</p>
        <ul class="space-y-1"><li v-for="year in reconciliation.years ?? []" :key="year.year">{{ year.year }}: {{ t(year.ok ? 'abra_flexi.reconciliation_ok' : 'abra_flexi.reconciliation_failed') }}</li></ul>
      </div>
      <ul v-if="protocolWarnings.length" class="list-disc pl-5 text-sm text-warning-700"><li v-for="(warning, index) in protocolWarnings" :key="index">{{ issueLabel(warning) }}</li></ul>
      <p v-else class="text-sm text-neutral-500">{{ t('abra_flexi.no_protocol_warnings') }}</p>
    </div>
    <div v-if="connection?.warnings.length" class="rounded-md border border-warning-200 bg-warning-50 p-4 space-y-2">
      <h2 class="font-medium text-warning-700">{{ t('abra_flexi.warnings') }}</h2>
      <ul class="list-disc pl-5 text-sm text-warning-700"><li v-for="(warning, index) in connection.warnings" :key="index">{{ issueLabel(warning) }}</li></ul>
    </div>
  </div>
</template>

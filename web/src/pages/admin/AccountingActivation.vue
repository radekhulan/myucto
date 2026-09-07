<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { activationApi, type ActivationStatus, type BackfillJob, type OpeningBalanceRow } from '@/api/activation'
import { useActivationStatus } from '@/composables/useActivationStatus'
import { useToast } from '@/composables/useToast'
import { useNavOrder } from '@/composables/useNavOrder'
import { useAuthStore } from '@/stores/auth'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import OpeningBalanceEditor from '@/components/settings/activation/OpeningBalanceEditor.vue'
import BackfillReportView from '@/components/settings/activation/BackfillReportView.vue'
import { ICONS, btnOutline } from '@/components/ui/buttonStyles'
import PaginationBar from '@/components/ui/PaginationBar.vue'
import DateInput from '@/components/ui/DateInput.vue'

const { t } = useI18n()
const router = useRouter()
const toast = useToast()
const auth = useAuthStore()
const sharedStatus = useActivationStatus()
const nav = useNavOrder()
const status = ref<ActivationStatus | null>(null)
const currentStep = ref(1)
const startsOn = ref(`${new Date().getFullYear()}-01-01`)
const rows = ref<OpeningBalanceRow[]>([])
const job = ref<BackfillJob | null>(null)
const busy = ref(false)
const executeConfirmed = ref(false)
const jobHistory = ref<BackfillJob[]>([])
const jobPage = ref(1)
const jobPerPage = 20
const jobTotal = ref(0)
const invalidRow = ref<number | null>(null)
let pollTimer: ReturnType<typeof setTimeout> | null = null

// Sběrné kódy, které samy o sobě neříkají nic — konkrétní důvod (který řádek, který
// účet) nese jen zpráva ze serveru, a ta prochází ErrorCatalogem, takže je přeložená.
// Překlad podle kódu by ji přebil obecnou větou a uživatel by hledal chybu naslepo.
const GENERIC_ERROR_CODES = new Set(['validation_failed'])

function errorMessage(error: any): string {
  const payload = error?.response?.data?.error
  const code = String(payload?.code || '')
  const serverMessage = String(payload?.message ?? '').trim()
  if (code && !GENERIC_ERROR_CODES.has(code)) {
    const key = `activation.error.${code}`
    const translated = t(key)
    if (translated !== key) return translated
  }
  return serverMessage || t('common.error')
}

function failedRow(error: any): number | null {
  const row = Number(error?.response?.data?.error?.row)
  return Number.isInteger(row) && row >= 0 ? row : null
}

const steps = computed(() => [1, 2, 3, 4, 5].map(number => ({ number, label: t(`activation.step${number}`) })))
const openingBalanced = computed(() => {
  const debit = rows.value.filter(row => row.side === 'debit').reduce((sum, row) => sum + Number(row.amount || 0), 0)
  const credit = rows.value.filter(row => row.side === 'credit').reduce((sum, row) => sum + Number(row.amount || 0), 0)
  return Math.abs(debit - credit) < 0.005
})
const dryRunOk = computed(() => job.value?.kind === 'dry_run' && job.value.status === 'completed'
  && (job.value.report_json?.failed_total ?? 1) === 0 && job.value.report_json?.balance?.balanced
  && Object.values(job.value.report_json?.document_coverage ?? {}).every(row => row.complete))
const progress = computed(() => {
  if (!job.value?.total_items) return job.value?.status === 'completed' ? 100 : 0
  return Math.min(100, Math.round(job.value.processed / job.value.total_items * 100))
})
const jobRunning = computed(() => job.value?.status === 'queued' || job.value?.status === 'running')
const activated = computed(() => status.value?.activation_status === 'completed')
// Dokud jde otevírací zápis založit, musí zůstat cesta zpět do kroku 2. Rozhoduje
// server (postBlocker): otevřenost cílového období a to, že otevření nepatří
// uzávěrce předchozího roku.
const openingEditable = computed(() => !!status.value?.opening.editable)
const openingPosted = computed(() => !!status.value?.opening.posted)
const openingMissing = computed(() => activated.value && !openingPosted.value)

function canGoTo(step: number): boolean {
  if (!status.value || busy.value || jobRunning.value || step === currentStep.value) return false
  // Krok 1 přepisuje datum zahájení a vrací stav na draft — po aktivaci nedává smysl.
  if (step === 1) return !activated.value
  if (!status.value.starts_on) return false
  if (step === 2) return openingEditable.value
  if (step === 3) return true
  if (step === 4) return !!dryRunOk.value
  return !!status.value.last_job
}

function stepTitle(step: number): string {
  if (step === 2 && !openingEditable.value && !!status.value?.starts_on && step !== currentStep.value) {
    return t(`activation.error.${status.value?.opening.blocked_reason || 'period_not_open'}`)
  }
  return ''
}

async function goTo(step: number) {
  if (!canGoTo(step)) return
  invalidRow.value = null
  if (step === 2) {
    busy.value = true
    try {
      await loadOpening()
    } catch (error: any) {
      toast.error(errorMessage(error))
      return
    } finally { busy.value = false }
  }
  // Report z minulého běhu patří ke svému kroku: pod „Kontrolou" nesmí svítit
  // protokol ostrého doúčtování, jinak by dryRunOk hlídal cizí běh.
  if (step === 3) job.value = status.value?.last_job?.kind === 'dry_run' ? status.value.last_job : null
  if (step === 5) job.value = status.value?.last_job ?? null
  currentStep.value = step
}

async function load() {
  busy.value = true
  try {
    status.value = await sharedStatus.refresh(true)
    if (!status.value) return
    startsOn.value = status.value.starts_on || startsOn.value
    if (status.value.active_job) {
      job.value = status.value.active_job
      currentStep.value = job.value.kind === 'execute' ? 4 : 3
      schedulePoll(job.value.id)
    } else if (status.value.activation_status === 'completed') {
      job.value = status.value.last_job
      currentStep.value = 5
    } else if (status.value.activation_status === 'failed') {
      job.value = status.value.last_job
      currentStep.value = 5
    } else if (status.value.last_job?.kind === 'dry_run' && status.value.last_job.status === 'completed') {
      job.value = status.value.last_job
      currentStep.value = 3
    } else if (status.value.starts_on) {
      currentStep.value = 2
    }
    if (status.value.starts_on) await loadOpening()
    await loadJobs()
  } catch (error: any) {
    toast.error(errorMessage(error))
  } finally {
    busy.value = false
  }
}

async function loadJobs() {
  const result = await activationApi.jobs(jobPage.value, jobPerPage)
  if (result.items.length === 0 && result.total > 0 && jobPage.value > 1) {
    jobPage.value = Math.max(1, Math.ceil(result.total / result.per_page))
    return
  }
  jobHistory.value = result.items
  jobTotal.value = result.total
}

function showJob(selected: BackfillJob) {
  job.value = selected
  currentStep.value = selected.kind === 'dry_run' ? 3 : 5
}

async function loadOpening() {
  const draft = await activationApi.opening()
  rows.value = draft.rows.map(row => ({ ...row }))
}

async function startActivation() {
  busy.value = true
  try {
    status.value = await activationApi.start(startsOn.value)
    await loadOpening()
    currentStep.value = 2
  } catch (error: any) {
    toast.error(errorMessage(error))
  } finally { busy.value = false }
}

async function prefill() {
  busy.value = true
  invalidRow.value = null
  try {
    const draft = await activationApi.prefillOpening()
    rows.value = draft.rows.map(row => ({ ...row }))
    toast.success(rows.value.length ? t('activation.prefill_done') : t('activation.prefill_empty'))
  } catch (error: any) {
    invalidRow.value = failedRow(error)
    toast.error(errorMessage(error))
  } finally { busy.value = false }
}

async function saveOpening() {
  busy.value = true
  invalidRow.value = null
  try {
    const draft = await activationApi.saveOpening(rows.value)
    rows.value = draft.rows.map(row => ({ ...row }))
    if (!draft.totals.balanced) {
      toast.error(t('activation.opening_unbalanced'))
      return
    }
    status.value = await sharedStatus.refresh(true)
    job.value = null
    currentStep.value = 3
  } catch (error: any) {
    invalidRow.value = failedRow(error)
    toast.error(errorMessage(error))
  } finally { busy.value = false }
}

/**
 * Přeskočení rozvahy je vědomé rozhodnutí, ne default. Prázdný výsledek předvyplnění
 * neznamená, že firma počáteční stavy nemá — u přechodu z jiného programu je běžný.
 */
async function skipOpening() {
  if (!confirm(t('activation.skip_opening_confirm', { date: status.value?.starts_on ?? startsOn.value }))) return
  await saveOpening()
}

async function runDry() {
  busy.value = true
  try {
    const created = await activationApi.dryRun()
    currentStep.value = 3
    await pollJob(created.job_id)
  } catch (error: any) {
    toast.error(errorMessage(error))
  } finally { busy.value = false }
}

async function runExecute() {
  if (!executeConfirmed.value) return
  busy.value = true
  try {
    const created = await activationApi.execute()
    currentStep.value = 4
    await pollJob(created.job_id)
  } catch (error: any) {
    toast.error(errorMessage(error))
  } finally { busy.value = false }
}

async function runRepair() {
  if (!confirm(t('activation.repair_confirm', { n: status.value?.pending.total ?? 0 }))) return
  executeConfirmed.value = true
  busy.value = true
  try {
    const created = await activationApi.execute()
    currentStep.value = 4
    await pollJob(created.job_id)
  } catch (error: any) {
    toast.error(errorMessage(error))
  } finally { busy.value = false }
}

function hideFromMenu() {
  nav.hideItem('/admin/accounting-activation')
  toast.success(t('activation.hidden_from_menu'))
}

async function pollJob(id: number) {
  job.value = await activationApi.job(id)
  if (job.value.status === 'queued' || job.value.status === 'running') {
    schedulePoll(id)
    return
  }
  status.value = await sharedStatus.refresh(true)
  await loadJobs()
  if (job.value.kind === 'execute') {
    if (job.value.status === 'completed') {
      await auth.refresh()
      currentStep.value = 5
    } else {
      currentStep.value = 5
      toast.error(t('activation.execute_failed'))
    }
  }
}

function schedulePoll(id: number) {
  if (pollTimer) clearTimeout(pollTimer)
  pollTimer = setTimeout(() => { void pollJob(id) }, 2000)
}

async function cancelJob() {
  if (!job.value) return
  busy.value = true
  try {
    await activationApi.cancel(job.value.id)
    await pollJob(job.value.id)
  } catch (error: any) {
    toast.error(errorMessage(error))
  } finally { busy.value = false }
}

const actions = computed<ActionItem[]>(() => {
  if (currentStep.value === 1) return [{ key: 'start', label: t('activation.continue'), icon: 'play', tier: 'primary', variant: 'primary', loading: busy.value, run: startActivation }]
  // Přeskočení zůstává dostupné, ale jako vedlejší akce s potvrzením — jako hlavní
  // tlačítko svádělo k prokliknutí kroku, jehož následek (chybějící počáteční stavy)
  // je vidět až v rozvaze a předvaze.
  if (currentStep.value === 2) return [
    { key: 'save', label: t('activation.save_and_continue'), icon: 'check', tier: 'primary', variant: 'primary', disabled: !rows.value.length || !openingBalanced.value, loading: busy.value, run: saveOpening },
    { key: 'prefill', label: t('activation.opening_prefill'), icon: 'cycle', tier: 'secondary', variant: 'neutral', disabled: busy.value, run: prefill },
    { key: 'skip', label: t('activation.skip_opening'), icon: 'x', tier: 'secondary', variant: 'neutral', show: !rows.value.length, disabled: busy.value, run: skipOpening },
  ]
  if (currentStep.value === 3) return dryRunOk.value
    ? [
        { key: 'next', label: t('activation.continue'), icon: 'check', tier: 'primary', variant: 'primary', run: () => { currentStep.value = 4 } },
        { key: 'rerun', label: t('activation.dry_run_again'), icon: 'cycle', tier: 'secondary', variant: 'neutral', run: runDry },
      ]
    : [{ key: 'dry', label: t('activation.dry_run_start'), icon: 'play', tier: 'primary', variant: 'primary', disabled: job.value?.status === 'queued' || job.value?.status === 'running', loading: busy.value, run: runDry }]
  if (currentStep.value === 4) return [
    { key: 'execute', label: t('activation.execute_start'), icon: 'play', tier: 'primary', variant: 'warning', disabled: !executeConfirmed.value || job.value?.status === 'queued' || job.value?.status === 'running', loading: busy.value, run: runExecute },
    { key: 'cancel', label: t('common.cancel'), icon: 'x', tier: 'overflow', variant: 'danger', show: job.value?.status === 'queued' || job.value?.status === 'running', run: cancelJob },
  ]
  return [
    { key: 'opening', label: t('activation.open_opening_step'), icon: 'coin', tier: 'primary', variant: 'warning', run: () => { void goTo(2) }, show: openingMissing.value && openingEditable.value },
    { key: 'repair', label: t('activation.repair'), icon: 'cycle', tier: 'primary', variant: 'warning', loading: busy.value, run: runRepair, show: status.value?.activation_status === 'completed' && (status.value?.pending.total ?? 0) > 0 },
    { key: 'journal', label: t('activation.open_journal'), icon: 'doc', tier: 'primary', variant: 'primary', to: { name: 'accounting-journal' }, show: status.value?.activation_status === 'completed' },
    { key: 'trial', label: t('activation.open_trial_balance'), icon: 'chart', tier: 'secondary', variant: 'neutral', to: { name: 'accounting-trial-balance' }, show: status.value?.activation_status === 'completed' },
    { key: 'hide', label: t('activation.hide_from_menu'), icon: 'x', tier: 'secondary', variant: 'neutral', run: hideFromMenu, show: status.value?.activation_status === 'completed' && !nav.isHidden('/admin/accounting-activation') },
    { key: 'retry', label: t('activation.retry'), icon: 'cycle', tier: 'primary', variant: 'warning', run: () => { job.value = null; currentStep.value = 3 }, show: status.value?.activation_status === 'failed' },
  ]
})

onMounted(load)
watch(jobPage, () => { void loadJobs() })
onBeforeUnmount(() => { if (pollTimer) clearTimeout(pollTimer) })
</script>

<template>
  <div class="mx-auto max-w-6xl space-y-5">
    <div class="flex flex-wrap items-start justify-between gap-3"><div><h1 class="text-2xl font-semibold">{{ t('activation.title') }}</h1><p class="mt-1 text-sm text-neutral-500">{{ t('activation.subtitle') }}</p></div><button type="button" :class="btnOutline('neutral')" @click="router.push({ name: 'admin-settings', query: { tab: 'accounting' } })"><svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.uturn" /></svg>{{ t('activation.back_settings') }}</button></div>

    <ol class="grid grid-cols-2 gap-2 sm:grid-cols-5">
      <li v-for="step in steps" :key="step.number">
        <button type="button" :disabled="!canGoTo(step.number)" :title="stepTitle(step.number)" class="flex w-full items-center rounded-lg border px-3 py-3 text-left text-sm transition-colors" :class="[step.number === currentStep ? 'border-primary-500 bg-primary-50 text-primary-700' : step.number < currentStep ? 'border-success-500/40 bg-success-50 text-success-600' : 'border-neutral-200 text-neutral-400', canGoTo(step.number) ? 'cursor-pointer hover:border-primary-400 hover:bg-primary-50' : 'cursor-default']" @click="goTo(step.number)"><span class="mr-2 inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-full border text-xs font-semibold">{{ step.number < currentStep ? '✓' : step.number }}</span>{{ step.label }}</button>
      </li>
    </ol>

    <section class="rounded-lg border border-neutral-200 bg-surface p-5 shadow-sm">
      <div v-if="busy && !status" class="py-12 text-center text-neutral-400">{{ t('common.loading') }}</div>
      <template v-else-if="currentStep === 1">
        <h2 class="mb-4 text-lg font-semibold">{{ t('activation.step1') }}</h2>
        <label class="block max-w-xs text-sm font-medium">{{ t('activation.starts_on') }}<DateInput v-model="startsOn" class="mt-1 h-10 w-full rounded-md border border-neutral-300 px-3" /></label>
        <p class="mt-3 max-w-3xl text-sm text-neutral-500">{{ t('activation.starts_on_hint') }}</p>
        <div v-if="status?.locked_until" class="mt-4 rounded-lg border border-warning-500/30 bg-warning-50 px-4 py-3 text-sm text-warning-700">{{ t('activation.locked_notice', { date: status.locked_until }) }}</div>
      </template>
      <template v-else-if="currentStep === 2">
        <h2 class="mb-1 text-lg font-semibold">{{ t('activation.step2') }}</h2><p class="mb-4 text-sm text-neutral-500">{{ t('activation.opening_hint') }}</p>
        <div v-if="status?.locked_until" class="mb-4 rounded-lg border border-warning-500/30 bg-warning-50 px-4 py-3 text-sm text-warning-700">{{ t('activation.locked_notice', { date: status.locked_until }) }}</div>
        <div v-if="activated" class="mb-4 rounded-lg border border-primary-500/30 bg-primary-50 px-4 py-3 text-sm text-primary-700">{{ t('activation.opening_amend_hint') }}</div>
        <div v-if="!rows.length" class="mb-4 rounded-lg border border-warning-500/30 bg-warning-50 px-4 py-3 text-sm text-warning-700">{{ t('activation.opening_empty_notice') }}</div>
        <OpeningBalanceEditor v-model="rows" :invalid-index="invalidRow" />
      </template>
      <template v-else-if="currentStep === 3">
        <h2 class="mb-1 text-lg font-semibold">{{ t('activation.step3') }}</h2><p class="mb-4 text-sm text-neutral-500">{{ t('activation.dry_run_hint') }}</p>
        <div v-if="job && (job.status === 'queued' || job.status === 'running')" class="space-y-3"><div class="h-2 overflow-hidden rounded-full bg-neutral-100"><div class="h-full bg-primary-600 transition-all" :style="{ width: `${progress}%` }"></div></div><p class="text-sm text-neutral-500">{{ t(`activation.phase.${job.phase || 'opening'}`) }} · {{ progress }} %</p></div>
        <BackfillReportView v-if="job?.report_json" :report="job.report_json" />
      </template>
      <template v-else-if="currentStep === 4">
        <h2 class="mb-1 text-lg font-semibold">{{ t('activation.step4') }}</h2>
        <label class="my-4 flex cursor-pointer items-start gap-3 rounded-lg border border-warning-500/30 bg-warning-50 p-4"><input v-model="executeConfirmed" type="checkbox" class="mt-1 rounded border-neutral-300 text-primary-600" /><span class="text-sm text-warning-700">{{ activated ? t('activation.execute_confirm_amend') : t('activation.execute_confirm', { n: status?.pending.total ?? job?.total_items ?? 0 }) }}</span></label>
        <div v-if="job && (job.status === 'queued' || job.status === 'running')" class="space-y-3"><div class="h-2 overflow-hidden rounded-full bg-neutral-100"><div class="h-full bg-warning-500 transition-all" :style="{ width: `${progress}%` }"></div></div><p class="text-sm text-neutral-500">{{ t(`activation.phase.${job.phase || 'opening'}`) }} · {{ progress }} %</p><pre v-if="job.log_text" class="max-h-72 overflow-auto rounded-lg bg-neutral-900 p-4 text-xs text-neutral-100">{{ job.log_text }}</pre></div>
      </template>
      <template v-else>
        <h2 class="mb-4 text-lg font-semibold">{{ t('activation.step5') }}</h2>
        <div v-if="status?.activation_status === 'completed'" class="mb-4 rounded-lg border border-success-500/30 bg-success-50 px-4 py-3 text-success-600">{{ t('activation.done', { date: status.starts_on }) }}</div>
        <div v-else class="mb-4 rounded-lg border border-danger-500/30 bg-danger-50 px-4 py-3 text-danger-600">{{ t('activation.execute_failed') }}</div>
        <div v-if="openingMissing" class="mb-4 rounded-lg border border-warning-500/30 bg-warning-50 px-4 py-3 text-sm text-warning-700">{{ openingEditable ? t('activation.opening_missing_notice') : t('activation.opening_missing_locked') }}</div>
        <BackfillReportView v-if="job?.report_json" :report="job.report_json" />
      </template>
    </section>

    <div class="flex justify-end"><ActionBar :actions="actions" /></div>

    <section v-if="jobHistory.length || jobTotal" class="overflow-hidden rounded-lg border border-neutral-200 bg-surface shadow-sm">
      <h2 class="border-b border-neutral-200 px-4 py-3 text-lg font-semibold">{{ t('activation.history_title') }}</h2>
      <div class="divide-y divide-neutral-100">
        <button v-for="item in jobHistory" :key="item.id" type="button" class="flex w-full cursor-pointer flex-wrap items-center justify-between gap-3 px-4 py-3 text-left hover:bg-neutral-50" @click="showJob(item)">
          <span><strong>#{{ item.id }} · {{ t(`activation.kind.${item.kind}`) }}</strong><span class="ml-2 text-xs text-neutral-500">{{ item.created_at }}</span></span>
          <span class="rounded-full px-2.5 py-1 text-xs font-medium" :class="item.status === 'completed' ? 'bg-success-50 text-success-600' : item.status === 'failed' ? 'bg-danger-50 text-danger-600' : 'bg-neutral-100 text-neutral-600'">{{ t(`activation.status.${item.status}`) }}</span>
        </button>
      </div>
      <PaginationBar embedded :page="jobPage" :per-page="jobPerPage" :total="jobTotal" @update:page="jobPage = $event" />
    </section>
  </div>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { moneyS3Api, type MoneyS3Run, type MoneyS3Upload } from '@/api/moneyS3'
import { cancelImportJob, fetchImportJob, type FileImportJob } from '@/api/imports'
import { useToast } from '@/composables/useToast'
import { useAuthStore } from '@/stores/auth'
import type { PermissionKey } from '@/security/permissions'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import ImportJobProgress from '@/components/exchange/ImportJobProgress.vue'
import DateInput from '@/components/ui/DateInput.vue'
import MoneyS3Protocol from '@/components/migration/MoneyS3Protocol.vue'

/**
 * Průvodce „Přechod z Money S3": záloha agendy → náhled a volby → zkouška nanečisto →
 * ostrý převod. Nahraná záloha zůstává na serveru pod tokenem, takže obnovení stránky
 * průvodce nevrátí na začátek (token drží sessionStorage).
 */
const TOKEN_KEY = 'myucto.moneyS3.token'

const { t, tm, rt } = useI18n()
const toast = useToast()
const fileHelpItems = computed(() => (tm('money_s3.file_help_items') as unknown[]).map(item => rt(item as Parameters<typeof rt>[0])))
const auth = useAuthStore()

const currentStep = ref(1)
const upload = ref<MoneyS3Upload | null>(null)
const file = ref<File | null>(null)
const closeHistory = ref(true)
const firstPeriodStart = ref('')
const job = ref<FileImportJob | null>(null)
const jobMode = ref<'dry_run' | 'import' | null>(null)
const run = ref<MoneyS3Run | null>(null)
const runs = ref<MoneyS3Run[]>([])
const busy = ref(false)
const cancelling = ref(false)
const confirmed = ref(false)
const confirmIco = ref(false)
const dryRunPassed = ref(false)
const reportBusy = ref<number | null>(null)
let pollTimer: ReturnType<typeof setTimeout> | null = null

function readToken(): string | null {
  try { return sessionStorage.getItem(TOKEN_KEY) } catch { return null }
}
function writeToken(token: string | null): void {
  try {
    if (token) sessionStorage.setItem(TOKEN_KEY, token)
    else sessionStorage.removeItem(TOKEN_KEY)
  } catch { /* prohlížeč bez úložiště — průvodce jen nepřežije obnovení stránky */ }
}

function errorMessage(error: any, fallback: string): string {
  const message = String(error?.response?.data?.error?.message ?? '').trim()
  return message || fallback
}

const steps = computed(() => [1, 2, 3, 4].map(number => ({ number, label: t(`money_s3.step${number}`) })))
const agenda = computed(() => upload.value?.agenda ?? null)
const preflightErrors = computed(() => (upload.value?.preflight ?? []).filter(m => m.level === 'error'))
const jobRunning = computed(() => job.value?.status === 'queued' || job.value?.status === 'running')
const percent = computed(() => {
  if (jobMode.value !== 'import' || !job.value?.total_items) return null
  return Math.min(100, Math.round(job.value.processed / job.value.total_items * 100))
})
// IČO chybí v záloze nebo ve firmě — ostrý převod potřebuje výslovné potvrzení (BE ico_unverified).
const icoUnverified = computed(() => (upload.value?.preflight ?? []).some(m => m.code === 'agenda_ico_missing' || m.code === 'supplier_ico_missing'))
// Stejná práva jako BE MoneyS3MigrationAction::missingLiveImportRights().
const missingRights = computed(() => {
  const required: PermissionKey[] = ['accounting.journal.write', 'settings.company.write']
  if (closeHistory.value) required.push('accounting.periods.close')
  return required.filter(key => !auth.canWrite(key))
})
const importDone = computed(() => jobMode.value === 'import' && !jobRunning.value && run.value?.mode === 'import'
  && (run.value.status === 'completed' || run.value.status === 'completed_with_warnings'))

function canGoTo(step: number): boolean {
  if (busy.value || jobRunning.value || step === currentStep.value) return false
  if (step === 1) return true
  if (step === 2 || step === 3) return upload.value !== null
  return dryRunPassed.value && upload.value !== null
}

function goTo(step: number): void {
  if (canGoTo(step)) currentStep.value = step
}

function onFile(event: Event): void {
  const input = event.target as HTMLInputElement
  file.value = input.files?.[0] ?? null
}

async function doUpload(): Promise<void> {
  if (!file.value) return
  busy.value = true
  try {
    upload.value = await moneyS3Api.upload(file.value)
    writeToken(upload.value.token)
    dryRunPassed.value = false
    confirmed.value = false
    confirmIco.value = false
    run.value = null
    currentStep.value = 2
  } catch (error: any) {
    toast.error(errorMessage(error, t('money_s3.upload_failed')))
  } finally {
    busy.value = false
  }
}

function resetUpload(): void {
  upload.value = null
  file.value = null
  run.value = null
  dryRunPassed.value = false
  confirmed.value = false
  writeToken(null)
  currentStep.value = 1
}

async function attachReport(year: number, event: Event): Promise<void> {
  const input = event.target as HTMLInputElement
  const selected = input.files?.[0]
  if (!selected || !upload.value) return
  reportBusy.value = year
  try {
    const result = await moneyS3Api.attachReport(upload.value.token, year, selected)
    if (!upload.value.reports.includes(year)) upload.value.reports.push(year)
    toast.success(t('money_s3.report_attached', { year, n: result.accounts }))
  } catch (error: any) {
    toast.error(errorMessage(error, t('money_s3.report_failed')))
  } finally {
    reportBusy.value = null
    input.value = ''
  }
}

async function start(mode: 'dry_run' | 'import'): Promise<void> {
  if (!upload.value) return
  busy.value = true
  try {
    const started = await moneyS3Api.start(upload.value.token, {
      mode,
      close_history: closeHistory.value,
      first_period_start: firstPeriodStart.value || null,
      confirm_ico: confirmIco.value,
    })
    jobMode.value = mode
    run.value = null
    currentStep.value = mode === 'dry_run' ? 3 : 4
    await pollJob(started.job_id)
  } catch (error: any) {
    toast.error(errorMessage(error, t('money_s3.start_failed')))
  } finally {
    busy.value = false
  }
}

async function pollJob(id: number): Promise<void> {
  job.value = await fetchImportJob(id)
  if (jobRunning.value) {
    schedulePoll(id)
    return
  }
  await loadRuns()
  const finished = runs.value.find(r => r.job_id === id)
  run.value = finished ? await moneyS3Api.run(finished.id) : null
  const ok = run.value?.status === 'completed' || run.value?.status === 'completed_with_warnings'
  if (jobMode.value === 'dry_run') {
    dryRunPassed.value = ok
  } else if (jobMode.value === 'import' && ok) {
    // Server nahranou zálohu po úspěšném převodu smazal.
    writeToken(null)
  }
}

function schedulePoll(id: number): void {
  if (pollTimer) clearTimeout(pollTimer)
  pollTimer = setTimeout(() => { void pollJob(id) }, 2000)
}

async function cancel(): Promise<void> {
  if (!job.value) return
  cancelling.value = true
  try {
    await cancelImportJob(job.value.id)
  } catch (error: any) {
    toast.error(errorMessage(error, t('common.error')))
  } finally {
    cancelling.value = false
  }
}

async function loadRuns(): Promise<void> {
  runs.value = (await moneyS3Api.runs()).items
}

async function showRun(item: MoneyS3Run): Promise<void> {
  try {
    run.value = await moneyS3Api.run(item.id)
  } catch (error: any) {
    toast.error(errorMessage(error, t('common.error')))
  }
}

async function load(): Promise<void> {
  busy.value = true
  try {
    await loadRuns()
    const token = readToken()
    if (token) {
      try {
        upload.value = await moneyS3Api.show(token)
        currentStep.value = 2
      } catch {
        writeToken(null)
      }
    }
    const active = runs.value.find(r => r.status === 'running' && r.job_id !== null)
    if (active?.job_id) {
      jobMode.value = active.mode
      currentStep.value = active.mode === 'dry_run' ? 3 : 4
      await pollJob(active.job_id)
    }
  } catch (error: any) {
    toast.error(errorMessage(error, t('common.error')))
  } finally {
    busy.value = false
  }
}

const actions = computed<ActionItem[]>(() => {
  const blocked = preflightErrors.value.length > 0
  if (currentStep.value === 1) return [
    { key: 'upload', label: busy.value ? t('money_s3.uploading') : t('money_s3.upload'), icon: 'upload', tier: 'primary', variant: 'primary', disabled: !file.value, disabledReason: t('money_s3.choose_file_first'), loading: busy.value, run: doUpload },
  ]
  if (currentStep.value === 2) return [
    { key: 'continue', label: t('money_s3.continue'), icon: 'check', tier: 'primary', variant: 'primary', disabled: blocked, disabledReason: t('money_s3.preflight_blocked'), run: () => { currentStep.value = 3 } },
    { key: 'new', label: t('money_s3.new_upload'), icon: 'x', tier: 'secondary', variant: 'neutral', run: resetUpload },
  ]
  if (currentStep.value === 3) return dryRunPassed.value && !jobRunning.value
    ? [
        { key: 'next', label: t('money_s3.continue'), icon: 'check', tier: 'primary', variant: 'primary', run: () => { currentStep.value = 4 } },
        { key: 'rerun', label: t('money_s3.dry_run_again'), icon: 'cycle', tier: 'secondary', variant: 'neutral', run: () => { void start('dry_run') } },
      ]
    : [
        { key: 'dry', label: t('money_s3.dry_run_start'), icon: 'play', tier: 'primary', variant: 'primary', disabled: blocked || jobRunning.value, disabledReason: t('money_s3.preflight_blocked'), loading: busy.value, run: () => { void start('dry_run') } },
      ]
  if (importDone.value) return [
    { key: 'journal', label: t('money_s3.open_journal'), icon: 'doc', tier: 'primary', variant: 'primary', to: { name: 'accounting-journal' } },
    { key: 'trial', label: t('money_s3.open_trial_balance'), icon: 'chart', tier: 'secondary', variant: 'neutral', to: { name: 'accounting-trial-balance' } },
  ]
  const icoPending = icoUnverified.value && !confirmIco.value
  const importReason = missingRights.value.length
    ? t('money_s3.rights_missing', { rights: missingRights.value.join(', ') })
    : icoPending ? t('money_s3.ico_confirm_first') : t('money_s3.import_confirm_first')
  return [
    { key: 'import', label: t('money_s3.import_start'), icon: 'play', tier: 'primary', variant: 'warning', disabled: !confirmed.value || jobRunning.value || blocked || icoPending || missingRights.value.length > 0, disabledReason: importReason, loading: busy.value, run: () => { void start('import') } },
  ]
})

onMounted(load)
onBeforeUnmount(() => { if (pollTimer) clearTimeout(pollTimer) })
</script>

<template>
  <div class="mx-auto max-w-6xl space-y-5">
    <div>
      <h1 class="text-2xl font-semibold">{{ t('money_s3.title') }}</h1>
      <p class="mt-1 text-sm text-neutral-500">{{ t('money_s3.subtitle') }}</p>
    </div>

    <div class="rounded-lg border border-warning-500/30 bg-warning-50 px-4 py-3 text-sm text-warning-700" data-testid="money-s3-support-notice">
      <p>{{ t('money_s3.support_notice') }}</p>
      <RouterLink to="/admin/support" class="mt-1 inline-block font-medium underline hover:no-underline">{{ t('money_s3.support_notice_link') }}</RouterLink>
    </div>

    <ol class="grid grid-cols-2 gap-2 sm:grid-cols-4">
      <li v-for="step in steps" :key="step.number">
        <button type="button" :disabled="!canGoTo(step.number)" class="flex w-full items-center rounded-lg border px-3 py-3 text-left text-sm transition-colors"
          :class="[step.number === currentStep ? 'border-primary-500 bg-primary-50 text-primary-700' : step.number < currentStep ? 'border-success-500/40 bg-success-50 text-success-600' : 'border-neutral-200 text-neutral-400', canGoTo(step.number) ? 'cursor-pointer hover:border-primary-400 hover:bg-primary-50' : 'cursor-default']"
          @click="goTo(step.number)">
          <span class="mr-2 inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-full border text-xs font-semibold">{{ step.number < currentStep ? '✓' : step.number }}</span>{{ step.label }}
        </button>
      </li>
    </ol>

    <section class="rounded-lg border border-neutral-200 bg-surface p-5 shadow-sm">
      <div v-if="busy && !upload && currentStep === 1 && !file" class="py-12 text-center text-neutral-400">{{ t('common.loading') }}</div>

      <template v-else-if="currentStep === 1">
        <h2 class="mb-1 text-lg font-semibold">{{ t('money_s3.upload_title') }}</h2>
        <p class="mb-4 text-sm text-neutral-500">{{ t('money_s3.upload_hint') }}</p>
        <div class="mb-5 rounded-lg border border-neutral-200 bg-neutral-50 px-4 py-3 text-sm" data-testid="backup-file-help">
          <h3 class="mb-2 font-medium text-neutral-700">{{ t('money_s3.file_help_title') }}</h3>
          <ul class="list-disc space-y-1 pl-5 text-neutral-600">
            <li v-for="(item, i) in fileHelpItems" :key="i">{{ item }}</li>
          </ul>
        </div>
        <label class="block max-w-xl text-sm font-medium">
          {{ t('money_s3.choose_file') }}
          <input type="file" accept=".lz,.zip" class="mt-1 block w-full rounded-md border border-neutral-300 px-3 py-2 text-sm" data-testid="backup-input" @change="onFile" />
        </label>
      </template>

      <template v-else-if="currentStep === 2 && agenda">
        <h2 class="mb-4 text-lg font-semibold">{{ t('money_s3.step2') }}</h2>
        <dl class="mb-5 grid grid-cols-1 gap-x-6 gap-y-2 text-sm sm:grid-cols-2 lg:grid-cols-3">
          <div><dt class="text-neutral-500">{{ t('money_s3.agenda') }}</dt><dd class="font-medium">{{ agenda.name || '—' }}</dd></div>
          <div><dt class="text-neutral-500">{{ t('money_s3.ico') }}</dt><dd class="font-medium">{{ agenda.ico || '—' }}</dd></div>
          <div><dt class="text-neutral-500">{{ t('money_s3.dic') }}</dt><dd class="font-medium">{{ agenda.dic || '—' }}</dd></div>
          <div><dt class="text-neutral-500">{{ t('money_s3.address') }}</dt><dd class="font-medium">{{ [agenda.street, agenda.zip, agenda.city].filter(Boolean).join(', ') || '—' }}</dd></div>
          <div>
            <dt class="text-neutral-500">{{ t('money_s3.version') }}</dt>
            <dd class="font-medium">{{ agenda.version || '—' }}
              <span v-if="!agenda.version_verified" class="ml-2 rounded-full bg-warning-50 px-2 py-0.5 text-xs text-warning-700">{{ t('money_s3.version_unverified') }}</span>
            </dd>
          </div>
          <div><dt class="text-neutral-500">{{ t('money_s3.backup_at') }}</dt><dd class="font-medium">{{ agenda.backup_at || '—' }}</dd></div>
        </dl>

        <h3 class="mb-2 text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('money_s3.years_title') }}</h3>
        <div class="mb-2 overflow-x-auto rounded-lg border border-neutral-200">
          <table class="min-w-full text-sm">
            <thead class="bg-neutral-50 text-left text-xs text-neutral-500">
              <tr>
                <th class="px-3 py-2">{{ t('money_s3.col_year') }}</th>
                <th class="px-3 py-2 text-right">{{ t('money_s3.col_journal') }}</th>
                <th class="px-3 py-2 text-right">{{ t('money_s3.col_opening') }}</th>
                <th class="px-3 py-2">{{ t('money_s3.col_range') }}</th>
                <th class="px-3 py-2 text-right">{{ t('money_s3.col_purchase') }}</th>
                <th class="px-3 py-2 text-right">{{ t('money_s3.col_issued') }}</th>
                <th class="px-3 py-2 text-right">{{ t('money_s3.col_cash') }}</th>
                <th class="px-3 py-2 text-right">{{ t('money_s3.col_bank') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="y in agenda.years" :key="y.dir">
                <td class="px-3 py-2 font-medium">{{ y.fiscal_year ?? y.dir }}</td>
                <td class="px-3 py-2 text-right">{{ y.journal_rows }}</td>
                <td class="px-3 py-2 text-right">{{ y.opening_rows }}</td>
                <td class="px-3 py-2 whitespace-nowrap">{{ y.first_date ?? '—' }} – {{ y.last_date ?? '—' }}</td>
                <td class="px-3 py-2 text-right">{{ y.purchase_invoices }}</td>
                <td class="px-3 py-2 text-right">{{ y.issued_invoices }}</td>
                <td class="px-3 py-2 text-right">{{ y.cash_documents }}</td>
                <td class="px-3 py-2 text-right">{{ y.bank_documents }}</td>
              </tr>
            </tbody>
          </table>
        </div>
        <p class="mb-5 text-sm text-neutral-500">{{ t('money_s3.partners', { n: agenda.partners }) }}</p>

        <h3 class="mb-2 text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('money_s3.preflight_title') }}</h3>
        <ul v-if="upload?.preflight.length" class="mb-5 space-y-2">
          <li v-for="m in upload.preflight" :key="m.code + m.message" class="rounded-lg border px-3 py-2 text-sm"
            :class="m.level === 'error' ? 'border-danger-500/30 bg-danger-50 text-danger-600' : m.level === 'warning' ? 'border-warning-500/30 bg-warning-50 text-warning-700' : 'border-primary-500/30 bg-primary-50 text-primary-700'">
            {{ m.message }}
          </li>
        </ul>
        <p v-else class="mb-5 rounded-lg border border-success-500/30 bg-success-50 px-3 py-2 text-sm text-success-600">{{ t('money_s3.preflight_ok') }}</p>

        <h3 class="mb-2 text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('money_s3.options_title') }}</h3>
        <label class="mb-2 flex cursor-pointer items-start gap-3">
          <input v-model="closeHistory" type="checkbox" class="mt-1 rounded border-neutral-300 text-primary-600" />
          <span class="text-sm"><span class="font-medium">{{ t('money_s3.close_history') }}</span><br /><span class="text-neutral-500">{{ t('money_s3.close_history_hint') }}</span></span>
        </label>
        <label class="mb-1 block max-w-xs text-sm font-medium">{{ t('money_s3.first_period_start') }}
          <DateInput v-model="firstPeriodStart" class="mt-1 h-10 w-full rounded-md border border-neutral-300 px-3" />
        </label>
        <p class="mb-5 text-sm text-neutral-500">{{ t('money_s3.first_period_start_hint') }}</p>

        <h3 class="mb-1 text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('money_s3.reports_title') }}</h3>
        <p class="mb-3 text-sm text-neutral-500">{{ t('money_s3.reports_hint') }}</p>
        <div class="flex flex-wrap gap-3">
          <label v-for="y in agenda.years.filter(y => y.fiscal_year !== null)" :key="y.dir"
            class="inline-flex cursor-pointer items-center gap-2 rounded-md border px-3 py-2 text-sm whitespace-nowrap"
            :class="upload?.reports.includes(y.fiscal_year as number) ? 'border-success-500/40 bg-success-50 text-success-600' : 'border-neutral-300 text-neutral-700 hover:bg-neutral-50'">
            <input type="file" accept=".csv,.txt" class="hidden" :disabled="reportBusy !== null" @change="attachReport(y.fiscal_year as number, $event)" />
            {{ upload?.reports.includes(y.fiscal_year as number) ? t('money_s3.report_ready', { year: y.fiscal_year }) : t('money_s3.report_upload', { year: y.fiscal_year }) }}
          </label>
        </div>
      </template>

      <template v-else-if="currentStep === 3">
        <h2 class="mb-1 text-lg font-semibold">{{ t('money_s3.dry_run_title') }}</h2>
        <p class="mb-2 text-sm text-neutral-500">{{ t('money_s3.dry_run_hint') }}</p>
        <p class="mb-4 rounded-lg border border-warning-500/30 bg-warning-50 px-3 py-2 text-sm text-warning-700">{{ t('money_s3.dry_run_locks_hint') }}</p>
        <ImportJobProgress v-if="jobRunning" :job="job" :percent="null" :cancelling="false" :show-cancel="false"
          counts-key="money_s3.job_counts" background-hint-key="money_s3.background_hint" running-key="money_s3.dry_run_running" />
        <MoneyS3Protocol v-if="run && run.mode === 'dry_run'" :run="run" />
      </template>

      <template v-else>
        <h2 class="mb-1 text-lg font-semibold">{{ t('money_s3.import_title') }}</h2>
        <label v-if="!importDone && !jobRunning" class="my-4 flex cursor-pointer items-start gap-3 rounded-lg border border-warning-500/30 bg-warning-50 p-4">
          <input v-model="confirmed" type="checkbox" class="mt-1 rounded border-neutral-300 text-primary-600" />
          <span class="text-sm text-warning-700">{{ t('money_s3.import_confirm', { company: agenda?.name ?? '' }) }}</span>
        </label>
        <label v-if="!importDone && !jobRunning && icoUnverified" class="my-4 flex cursor-pointer items-start gap-3 rounded-lg border border-warning-500/30 bg-warning-50 p-4">
          <input v-model="confirmIco" type="checkbox" class="mt-1 rounded border-neutral-300 text-primary-600" data-testid="confirm-ico" />
          <span class="text-sm text-warning-700">{{ t('money_s3.ico_confirm') }}</span>
        </label>
        <p v-if="!importDone && !jobRunning && missingRights.length" class="my-4 rounded-lg border border-danger-500/30 bg-danger-50 px-3 py-2 text-sm text-danger-600">
          {{ t('money_s3.rights_missing', { rights: missingRights.join(', ') }) }}
        </p>
        <ImportJobProgress v-if="jobRunning" :job="job" :percent="percent" :cancelling="cancelling" :show-cancel="true"
          counts-key="money_s3.job_counts" background-hint-key="money_s3.background_hint" running-key="money_s3.import_running"
          cancel-key="money_s3.cancel" cancelling-key="money_s3.cancelling" @cancel="cancel" />
        <MoneyS3Protocol v-if="run && run.mode === 'import'" :run="run" />
      </template>
    </section>

    <div class="flex flex-wrap justify-end"><ActionBar :actions="actions" /></div>

    <section class="overflow-hidden rounded-lg border border-neutral-200 bg-surface shadow-sm">
      <h2 class="border-b border-neutral-200 px-4 py-3 text-lg font-semibold">{{ t('money_s3.history_title') }}</h2>
      <p v-if="!runs.length" class="px-4 py-3 text-sm text-neutral-500">{{ t('money_s3.history_empty') }}</p>
      <div class="divide-y divide-neutral-100">
        <button v-for="item in runs" :key="item.id" type="button" class="flex w-full cursor-pointer flex-wrap items-center justify-between gap-3 px-4 py-3 text-left hover:bg-neutral-50" @click="showRun(item)">
          <span><strong>#{{ item.id }} · {{ t(`money_s3.mode.${item.mode}`) }}</strong><span class="ml-2 text-xs text-neutral-500">{{ item.agenda_name }} · {{ item.created_at }}</span></span>
          <span class="rounded-full px-2.5 py-1 text-xs font-medium"
            :class="item.status === 'completed' ? 'bg-success-50 text-success-600' : item.status === 'failed' ? 'bg-danger-50 text-danger-600' : item.status === 'completed_with_warnings' ? 'bg-warning-50 text-warning-700' : 'bg-neutral-100 text-neutral-600'">{{ t(`money_s3.status.${item.status}`) }}</span>
        </button>
      </div>
    </section>

    <section v-if="run && currentStep !== 3 && currentStep !== 4" class="rounded-lg border border-neutral-200 bg-surface p-5 shadow-sm">
      <MoneyS3Protocol :run="run" />
    </section>
  </div>
</template>

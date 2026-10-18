<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { isUploadReady, moneyS3Api, type MoneyS3Run, type MoneyS3StartParams, type MoneyS3Upload, type MoneyS3UploadPending } from '@/api/moneyS3'
import { useToast } from '@/composables/useToast'
import { useMigrationWizard } from '@/composables/useMigrationWizard'
import { useAuthStore } from '@/stores/auth'
import type { PermissionKey } from '@/security/permissions'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import { ICONS } from '@/components/ui/buttonStyles'
import ImportJobProgress from '@/components/exchange/ImportJobProgress.vue'
import CompanyProfileBox from '@/components/settings/CompanyProfileBox.vue'
import DateInput from '@/components/ui/DateInput.vue'
import MoneyS3Protocol from '@/components/migration/MoneyS3Protocol.vue'
import MigrationDifferences from '@/components/migration/MigrationDifferences.vue'
import MoneyS3BatchWizard from '@/components/migration/MoneyS3BatchWizard.vue'
import { migrationYearLabel } from '@/utils/migrationYear'

/**
 * Průvodce „Přechod z Money S3": záloha agendy → náhled a volby → zkouška nanečisto →
 * ostrý převod. Nahraná záloha zůstává na serveru pod tokenem, takže obnovení stránky
 * průvodce nevrátí na začátek (token drží sessionStorage). Společný průběh průvodců
 * je v useMigrationWizard.
 */
const TOKEN_KEY = 'myucto.moneyS3.token'
const TAB_KEY = 'myucto.moneyS3.tab'

// Režim průvodce: jedna firma, nebo dávka více firem (účetní kancelář). Volba přežije obnovení stránky.
function storedTab(): 'single' | 'batch' {
  try {
    return sessionStorage.getItem(TAB_KEY) === 'batch' ? 'batch' : 'single'
  } catch {
    return 'single'
  }
}
const tab = ref<'single' | 'batch'>(storedTab())
function setTab(value: 'single' | 'batch'): void {
  tab.value = value
  try {
    sessionStorage.setItem(TAB_KEY, value)
  } catch {
    // úložiště prohlížeče nedostupné, volba platí do obnovení stránky
  }
}

const { t, tm, rt, locale } = useI18n()
const toast = useToast()
const fileHelpItems = computed(() => (tm('money_s3.file_help_items') as unknown[]).map(item => rt(item as Parameters<typeof rt>[0])))
const auth = useAuthStore()

const closeHistory = ref(true)
const firstPeriodStart = ref('')
const fromYear = ref<number | null>(null)
const confirmIco = ref(false)
const reportBusy = ref<number | null>(null)

const {
  currentStep, upload, file, job, jobMode, run, runs, busy, cancelling, confirmed, dryRunPassed,
  differencesAcceptable, differences, acceptDifferences, canImport,
  uploadPercent, processing, deletingRun, jobRunning, percent,
  canGoTo, goTo, onFile, doUpload, resetUpload, start: startJob, cancel, showRun, deleteRun, errorMessage,
} = useMigrationWizard<MoneyS3Upload, MoneyS3UploadPending, MoneyS3Run, MoneyS3StartParams>({
  api: moneyS3Api,
  tokenKey: () => TOKEN_KEY,
  isReady: isUploadReady,
  text: (key, params) => t(`money_s3.${key}`, params ?? {}),
  multiYear: false,
  onReady: () => { confirmIco.value = false },
  restoreParams: params => {
    closeHistory.value = params.close_history
    firstPeriodStart.value = params.first_period_start ?? ''
    confirmIco.value = params.confirm_ico ?? false
    fromYear.value = params.from_year ?? null
  },
})

const steps = computed(() => [1, 2, 3, 4].map(number => ({ number, label: t(`money_s3.step${number}`) })))
const agenda = computed(() => upload.value?.agenda ?? null)
/** Agenda vedená v Money jako daňová evidence: bez voleb podvojného účetnictví (předvahy, začátek období). */
const taxRecords = computed(() => agenda.value?.tax_records === true)
const preflightErrors = computed(() => (upload.value?.preflight ?? []).filter(m => m.level === 'error'))
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
// Smazat jde jen doběhlou zkoušku nanečisto, protokol ostrého převodu zůstává (stejně jako u POHODY).
const canWrite = computed(() => (['accounting.journal.write', 'settings.company.write'] as PermissionKey[]).every(key => auth.canWrite(key)))

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
  await startJob(mode, {
    mode,
    close_history: closeHistory.value,
    first_period_start: firstPeriodStart.value || null,
    confirm_ico: confirmIco.value,
    from_year: fromYear.value,
  })
}

const actions = computed<ActionItem[]>(() => {
  const blocked = preflightErrors.value.length > 0
  if (currentStep.value === 1) return [
    { key: 'upload', label: busy.value ? (uploadPercent.value !== null ? t('money_s3.uploading_percent', { percent: uploadPercent.value }) : t('money_s3.uploading')) : t('money_s3.upload'), icon: 'upload', tier: 'primary', variant: 'primary', disabled: !file.value, disabledReason: t('money_s3.choose_file_first'), loading: busy.value, run: doUpload },
  ]
  if (currentStep.value === 2) return [
    { key: 'continue', label: t('money_s3.continue'), icon: 'check', tier: 'primary', variant: 'primary', disabled: blocked, disabledReason: t('money_s3.preflight_blocked'), run: () => { currentStep.value = 3 } },
    { key: 'new', label: t('money_s3.new_upload'), icon: 'x', tier: 'secondary', variant: 'neutral', run: resetUpload },
  ]
  if (currentStep.value === 3) return (dryRunPassed.value || differencesAcceptable.value) && !jobRunning.value
    ? [
        { key: 'next', label: t('money_s3.continue'), icon: 'check', tier: 'primary', variant: 'primary', disabled: !canImport.value, disabledReason: t('money_s3.differences.accept_first'), run: () => { currentStep.value = 4 } },
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
</script>

<template>
  <div class="mx-auto max-w-6xl space-y-5">
    <div>
      <h1 class="text-2xl font-semibold">{{ t('money_s3.title') }}</h1>
      <p class="mt-1 text-sm text-neutral-500">{{ t('money_s3.subtitle') }}</p>
    </div>

    <div class="rounded-lg border border-warning-500/30 bg-warning-50 px-4 py-3 text-sm text-warning-700" data-testid="money-s3-support-notice">
      <i18n-t keypath="money_s3.support_notice" tag="p">
        <template #recommend><strong><i18n-t keypath="money_s3.support_notice_recommend" tag="span"><template #contact><a href="https://myucto.cz/support#placena" target="_blank" rel="noopener" class="underline hover:no-underline">{{ t('money_s3.support_notice_contact') }}</a></template></i18n-t></strong></template>
      </i18n-t>
      <RouterLink to="/admin/support" class="mt-1 inline-block font-medium underline hover:no-underline">{{ t('money_s3.support_notice_link') }}</RouterLink>
    </div>

    <div role="tablist" :aria-label="t('money_s3.title')" class="flex flex-wrap items-center gap-1.5" data-testid="money-s3-mode-tabs">
      <button v-for="m in (['single', 'batch'] as const)" :key="m" type="button" role="tab" :aria-selected="tab === m"
        class="h-8 shrink-0 cursor-pointer whitespace-nowrap rounded-full border px-3 text-sm transition-colors"
        :class="tab === m ? 'border-primary-300 bg-primary-50 font-medium text-primary-700' : 'border-neutral-200 text-neutral-600 hover:bg-neutral-50'"
        :data-testid="`money-s3-tab-${m}`" @click="setTab(m)">{{ t(`money_s3.batch.tab_${m}`) }}</button>
    </div>

    <MoneyS3BatchWizard v-if="tab === 'batch'" />

    <template v-else>
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
      <div v-if="busy && !upload && currentStep === 1 && !file && !processing" class="py-12 text-center text-neutral-400">{{ t('common.loading') }}</div>

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
          <input type="file" accept=".lz,.zip" class="mt-1 block w-full rounded-md border border-neutral-300 px-3 py-2 text-sm" data-testid="backup-input" :disabled="busy" @change="onFile" />
        </label>
        <div v-if="uploadPercent !== null || processing" class="mt-4 max-w-xl space-y-2 rounded-md border border-primary-200 bg-primary-50/50 px-3 py-3" data-testid="backup-progress">
          <div class="text-sm font-medium text-primary-700">
            {{ processing ? t('money_s3.processing') : t('money_s3.uploading_percent', { percent: uploadPercent ?? 0 }) }}
          </div>
          <div class="h-2 overflow-hidden rounded-full bg-primary-100">
            <div class="h-full bg-primary-500 transition-all duration-300"
              :class="processing ? 'w-1/3 animate-pulse' : ''"
              :style="processing ? undefined : { width: (uploadPercent ?? 0) + '%' }"></div>
          </div>
          <p class="text-xs text-neutral-500">{{ processing ? t('money_s3.processing_hint') : t('money_s3.uploading_hint') }}</p>
        </div>
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
                <th class="px-3 py-2 text-right">{{ taxRecords ? t('money_s3.col_cashbook') : t('money_s3.col_journal') }}</th>
                <th v-if="!taxRecords" class="px-3 py-2 text-right">{{ t('money_s3.col_opening') }}</th>
                <th class="px-3 py-2">{{ t('money_s3.col_range') }}</th>
                <th class="px-3 py-2 text-right">{{ t('money_s3.col_purchase') }}</th>
                <th class="px-3 py-2 text-right">{{ t('money_s3.col_issued') }}</th>
                <th class="px-3 py-2 text-right">{{ t('money_s3.col_cash') }}</th>
                <th class="px-3 py-2 text-right">{{ t('money_s3.col_bank') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="y in agenda.years" :key="y.dir">
                <td class="px-3 py-2 font-medium" :data-testid="`money-year-${y.dir}`">{{ y.fiscal_year !== null ? migrationYearLabel(y.fiscal_year, y, locale) : y.dir }}</td>
                <td class="px-3 py-2 text-right">{{ taxRecords ? y.cashbook_rows : y.journal_rows }}</td>
                <td v-if="!taxRecords" class="px-3 py-2 text-right">{{ y.opening_rows }}</td>
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
          <span class="text-sm"><span class="font-medium">{{ taxRecords ? t('money_s3.close_history_tax_records') : t('money_s3.close_history') }}</span><br /><span class="text-neutral-500">{{ taxRecords ? t('money_s3.close_history_tax_records_hint') : t('money_s3.close_history_hint') }}</span></span>
        </label>
        <template v-if="!taxRecords">
          <label class="mb-1 block max-w-xs text-sm font-medium">{{ t('money_s3.first_period_start') }}
            <DateInput v-model="firstPeriodStart" class="mt-1 h-10 w-full rounded-md border border-neutral-300 px-3" />
          </label>
          <p class="mb-5 text-sm text-neutral-500">{{ t('money_s3.first_period_start_hint') }}</p>
        </template>
        <label class="mb-1 block max-w-xs text-sm font-medium">{{ t('money_s3.from_year') }}
          <select v-model="fromYear" class="mt-1 h-10 w-full rounded-md border border-neutral-300 px-3 text-sm" data-testid="from-year">
            <option :value="null">{{ t('money_s3.from_year_all') }}</option>
            <option v-for="y in agenda.years.filter(y => y.fiscal_year !== null)" :key="y.dir" :value="y.fiscal_year">{{ migrationYearLabel(y.fiscal_year as number, y, locale) }}</option>
          </select>
        </label>
        <p class="mb-5 text-sm text-neutral-500">{{ t('money_s3.from_year_hint') }}</p>

        <template v-if="!taxRecords">
          <h3 class="mb-1 text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('money_s3.reports_title') }}</h3>
          <p class="mb-3 text-sm text-neutral-500">{{ t('money_s3.reports_hint') }}</p>
          <div class="flex flex-wrap gap-3">
            <label v-for="y in agenda.years.filter(y => y.fiscal_year !== null)" :key="y.dir"
              class="inline-flex cursor-pointer items-center gap-2 rounded-md border px-3 py-2 text-sm whitespace-nowrap"
              :class="upload?.reports.includes(y.fiscal_year as number) ? 'border-success-500/40 bg-success-50 text-success-600' : 'border-neutral-300 text-neutral-700 hover:bg-neutral-50'">
              <input type="file" accept=".csv,.txt" class="hidden" :disabled="reportBusy !== null" @change="attachReport(y.fiscal_year as number, $event)" />
              {{ upload?.reports.includes(y.fiscal_year as number) ? t('money_s3.report_ready', { year: migrationYearLabel(y.fiscal_year as number, y, locale) }) : t('money_s3.report_upload', { year: migrationYearLabel(y.fiscal_year as number, y, locale) }) }}
            </label>
          </div>
        </template>
      </template>

      <template v-else-if="currentStep === 3">
        <h2 class="mb-1 text-lg font-semibold">{{ t('money_s3.dry_run_title') }}</h2>
        <p class="mb-2 text-sm text-neutral-500">{{ t('money_s3.dry_run_hint') }}</p>
        <p class="mb-4 rounded-lg border border-warning-500/30 bg-warning-50 px-3 py-2 text-sm text-warning-700">{{ t('money_s3.dry_run_locks_hint') }}</p>
        <ImportJobProgress v-if="jobRunning" :job="job" :percent="null" :cancelling="false" :show-cancel="false"
          counts-key="money_s3.job_counts" background-hint-key="money_s3.background_hint" running-key="money_s3.dry_run_running" />
        <MoneyS3Protocol v-if="run && run.mode === 'dry_run'" :run="run" />
        <MigrationDifferences v-if="differencesAcceptable && !jobRunning" v-model="acceptDifferences" :differences="differences" prefix="money_s3" />
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
        <p v-if="!importDone && !jobRunning && !dryRunPassed && acceptDifferences" class="my-4 rounded-lg border border-warning-500/30 bg-warning-50 px-3 py-2 text-sm text-warning-700" data-testid="money-s3-accepting-differences">
          {{ t('money_s3.differences.import_note', { count: differences.length }) }}
        </p>
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
        <div v-for="item in runs" :key="item.id" class="flex items-center gap-2 pr-3 hover:bg-neutral-50">
          <button type="button" class="flex min-w-0 flex-1 cursor-pointer flex-wrap items-center justify-between gap-3 px-4 py-3 text-left" @click="showRun(item)">
            <span><strong>#{{ item.id }} · {{ t(`money_s3.mode.${item.mode}`) }}</strong><span class="ml-2 text-xs text-neutral-500">{{ item.agenda_name }} · {{ item.created_at }}</span></span>
            <span class="rounded-full px-2.5 py-1 text-xs font-medium"
              :class="item.status === 'completed' ? 'bg-success-50 text-success-600' : item.status === 'failed' ? 'bg-danger-50 text-danger-600' : item.status === 'completed_with_warnings' ? 'bg-warning-50 text-warning-700' : 'bg-neutral-100 text-neutral-600'">{{ t(`money_s3.status.${item.status}`) }}</span>
          </button>
          <button v-if="item.mode === 'dry_run' && item.status !== 'running' && canWrite" type="button"
            class="shrink-0 rounded p-1.5 text-neutral-400 hover:bg-danger-50 hover:text-danger-600 disabled:opacity-40"
            :title="t('money_s3.run_delete')" :aria-label="t('money_s3.run_delete')" :disabled="deletingRun === item.id"
            :data-testid="`money-s3-run-delete-${item.id}`" @click="deleteRun(item)">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.trash" /></svg>
          </button>
        </div>
      </div>
    </section>

    <section v-if="run && currentStep !== 3 && currentStep !== 4" class="rounded-lg border border-neutral-200 bg-surface p-5 shadow-sm">
      <MoneyS3Protocol :run="run" />
    </section>

    <CompanyProfileBox variant="migration" />
    </template>
  </div>
</template>

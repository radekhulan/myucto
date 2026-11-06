<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { isPremierUploadReady, premierApi, type PremierRun, type PremierStartParams, type PremierUpload, type PremierUploadPending } from '@/api/premier'
import { useMigrationWizard } from '@/composables/useMigrationWizard'
import { useAuthStore } from '@/stores/auth'
import type { PermissionKey } from '@/security/permissions'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import { ICONS } from '@/components/ui/buttonStyles'
import ImportJobProgress from '@/components/exchange/ImportJobProgress.vue'
import CompanyProfileBox from '@/components/settings/CompanyProfileBox.vue'
import MoneyS3Protocol from '@/components/migration/MoneyS3Protocol.vue'
import MigrationDifferences from '@/components/migration/MigrationDifferences.vue'
import MigrationNonprofitNotice from '@/components/migration/MigrationNonprofitNotice.vue'
import { migrationYearLabel } from '@/utils/migrationYear'

/**
 * Průvodce převodem z PREMIER: záloha dat (Správce → Záloha dat, F11) → náhled agend
 * a výběr roků → zkouška nanečisto → ostrý převod (vybrané roky vzestupně v jednom jobu,
 * každý rok vlastní protokol). Nahraná záloha zůstává na serveru
 * pod tokenem, takže obnovení stránky průvodce nevrátí na začátek (token drží
 * sessionStorage). Společný průběh průvodců je v useMigrationWizard.
 *
 * Na rozdíl od průvodce POHODA/PAMICA (PohodaMigration.vue) tu není exportní nástroj
 * ke stažení (záloha se dělá přímo v PREMIERu) ani volba druhu převodu (mzdy PREMIER
 * v téže záloze nevede — nepřevádí se vůbec). Sdílený je jen server a protokol, proto
 * vlastní, trimnutá komponenta místo dalšího `system` v PohodaMigration.vue.
 */
const TOKEN_KEY = 'myucto.premier.token'

const { t, tm, rt, locale } = useI18n()
const auth = useAuthStore()

function tt(key: string, params?: Record<string, unknown>): string {
  return t(`premier.${key}`, params ?? {})
}
function list(key: string): string[] {
  const items = tm(`premier.${key}`) as unknown[]
  return (Array.isArray(items) ? items : []).map(item => rt(item as Parameters<typeof rt>[0]))
}
const backupHelpItems = computed(() => list('backup_help_items'))
const fileHelpItems = computed(() => list('file_help_items'))

const selectedYears = ref<number[]>([])

const {
  currentStep, upload, file, job, jobMode, run, jobRuns, runs, busy, cancelling, confirmed, dryRunPassed,
  differencesAcceptable, differences, acceptDifferences, canImport, clearDifferences,
  uploadPercent, processing, deletingRun, jobRunning, jobSucceeded, percent,
  canGoTo, goTo, onFile, doUpload, resetUpload, start: startJob, cancel, showRun, deleteRun,
} = useMigrationWizard<PremierUpload, PremierUploadPending, PremierRun, PremierStartParams>({
  api: premierApi,
  tokenKey: () => TOKEN_KEY,
  isReady: isPremierUploadReady,
  text: tt,
  multiYear: true,
  // Předvybrané všechny roky zálohy s IČO firmy.
  onReady: () => { selectedYears.value = [...years.value] },
  onReset: () => { selectedYears.value = [] },
  restoreParams: params => { selectedYears.value = [...params.years] },
})

const steps = computed(() => [1, 2, 3, 4].map(number => ({ number, label: tt(`step${number}`) })))
const agendas = computed(() => (upload.value?.agendas ?? []).filter(a => a.has_accounting))
// Roky vzestupně: záloha nese celé účetnictví najednou, ale doporučený postup je
// převádět od nejstaršího nepřevedeného roku, protože počáteční stavy roku vycházejí
// z let předchozích v záloze.
const years = computed(() => [...new Set(agendas.value.filter(a => a.ico === upload.value?.supplier_ico).map(a => a.year))].sort((a, b) => a - b))
const ownAgenda = computed(() => agendas.value.find(a => a.ico === upload.value?.supplier_ico) ?? null)
function isSelected(y: number): boolean {
  return selectedYears.value.includes(y)
}
function toggleYear(y: number, checked: boolean): void {
  const next = new Set(selectedYears.value)
  if (checked) next.add(y)
  else next.delete(y)
  selectedYears.value = [...next].sort((a, b) => a - b)
}
// U hospodářského roku firmy rok s hranicemi období, u kalendářního jen rok.
function yearLabel(y: number): string {
  return migrationYearLabel(y, agendas.value.find(a => a.ico === upload.value?.supplier_ico && a.year === y), locale?.value)
}
const yearsLabel = computed(() => selectedYears.value.map(yearLabel).join(', '))
// Kontrola před převodem po vybraných rocích vzestupně.
const preflightGroups = computed(() => selectedYears.value.map(y => ({ year: y, messages: upload.value?.preflight?.[String(y)] ?? [] })))
const preflightErrors = computed(() => preflightGroups.value.flatMap(g => g.messages).filter(m => m.level === 'error'))
const missingRights = computed<PermissionKey[]>(() => (['accounting.journal.write', 'settings.company.write'] as PermissionKey[]).filter(key => !auth.canWrite(key)))
const rightsMessage = computed(() => tt('rights_missing', { rights: missingRights.value.join(', ') }))
// Smazat jde jen doběhlou zkoušku nanečisto, protokol ostrého převodu zůstává (stejně jako u POHODY).
const canWrite = computed(() => missingRights.value.length === 0)
const blocked = computed(() => selectedYears.value.length === 0 || preflightErrors.value.length > 0)
const blockedReason = computed(() => jobRunning.value && !blocked.value
  ? tt(jobMode.value === 'import' ? 'import_running' : 'dry_run_running')
  : selectedYears.value.length === 0 ? tt('choose_year_first') : tt('preflight_blocked'))
const importDone = computed(() => jobMode.value === 'import' && !jobRunning.value && jobSucceeded.value
  && jobRuns.value.length > 0 && jobRuns.value.every(r => r.mode === 'import'))
// Roky jobu, které se po chybě nebo zrušení předchozího roku nespustily.
const yearsNotRun = computed(() => {
  const planned = jobRuns.value.map(r => r.protocol?.job_years?.years).find(Boolean) ?? []
  const done = new Set(jobRuns.value.map(r => r.agenda_year))
  return planned.filter(y => !done.has(y))
})
function statusClass(status: string): string {
  return status === 'completed' ? 'bg-success-50 text-success-600' : status === 'failed' ? 'bg-danger-50 text-danger-600'
    : status === 'completed_with_warnings' ? 'bg-warning-50 text-warning-700' : 'bg-neutral-100 text-neutral-600'
}

watch(selectedYears, () => {
  dryRunPassed.value = false
  clearDifferences()
  confirmed.value = false
})

async function start(mode: 'dry_run' | 'import'): Promise<void> {
  if (!upload.value || selectedYears.value.length === 0) return
  await startJob(mode, { mode, years: [...selectedYears.value] })
}

const actions = computed<ActionItem[]>(() => {
  if (currentStep.value === 1) return [
    { key: 'upload', label: busy.value ? (uploadPercent.value !== null ? tt('uploading_percent', { percent: uploadPercent.value }) : tt('uploading')) : tt('upload'), icon: 'upload', tier: 'primary', variant: 'primary', disabled: !file.value, disabledReason: tt('choose_file_first'), loading: busy.value, run: doUpload },
  ]
  if (currentStep.value === 2) return [
    { key: 'continue', label: tt('continue'), icon: 'check', tier: 'primary', variant: 'primary', disabled: blocked.value, disabledReason: blockedReason.value, run: () => { currentStep.value = 3 } },
    { key: 'new', label: tt('new_upload'), icon: 'x', tier: 'secondary', variant: 'neutral', run: resetUpload },
  ]
  if (currentStep.value === 3) return (dryRunPassed.value || differencesAcceptable.value) && !jobRunning.value
    ? [
        { key: 'next', label: tt('continue'), icon: 'check', tier: 'primary', variant: 'primary', disabled: !canImport.value, disabledReason: tt('differences.accept_first'), run: () => { currentStep.value = 4 } },
        { key: 'rerun', label: tt('dry_run_again'), icon: 'cycle', tier: 'secondary', variant: 'neutral', run: () => { void start('dry_run') } },
      ]
    : [
        { key: 'dry', label: tt('dry_run_start'), icon: 'play', tier: 'primary', variant: 'primary', disabled: blocked.value || jobRunning.value, disabledReason: blockedReason.value, loading: busy.value, run: () => { void start('dry_run') } },
      ]
  if (importDone.value) return [
    { key: 'journal', label: tt('open_journal'), icon: 'doc', tier: 'primary', variant: 'primary', to: { name: 'accounting-journal' } },
    { key: 'trial', label: tt('open_trial_balance'), icon: 'chart', tier: 'secondary', variant: 'neutral', to: { name: 'accounting-trial-balance' } },
  ]
  const importReason = missingRights.value.length
    ? rightsMessage.value
    : blocked.value ? blockedReason.value : tt('import_confirm_first')
  return [
    { key: 'import', label: tt('import_start'), icon: 'play', tier: 'primary', variant: 'warning', disabled: !confirmed.value || jobRunning.value || blocked.value || missingRights.value.length > 0, disabledReason: importReason, loading: busy.value, run: () => { void start('import') } },
  ]
})
</script>

<template>
  <div class="mx-auto max-w-6xl space-y-5">
    <div>
      <h1 class="text-2xl font-semibold">{{ tt('title') }}</h1>
      <p class="mt-1 text-sm text-neutral-500">{{ tt('subtitle') }}</p>
    </div>

    <MigrationNonprofitNotice :notice="upload?.nonprofit_notice" />

    <div class="rounded-lg border border-warning-500/30 bg-warning-50 px-4 py-3 text-sm text-warning-700" data-testid="premier-support-notice">
      <i18n-t keypath="premier.support_notice" tag="p">
        <template #recommend><strong><i18n-t keypath="premier.support_notice_recommend" tag="span"><template #contact><a href="https://myucto.cz/support#placena" target="_blank" rel="noopener" class="underline hover:no-underline">{{ tt('support_notice_contact') }}</a></template></i18n-t></strong></template>
      </i18n-t>
      <RouterLink to="/admin/support" class="mt-1 inline-block font-medium underline hover:no-underline">{{ tt('support_notice_link') }}</RouterLink>
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
      <div v-if="busy && !upload && currentStep === 1 && !file && !processing" class="py-12 text-center text-neutral-400">{{ t('common.loading') }}</div>

      <template v-else-if="currentStep === 1">
        <h2 class="mb-1 text-lg font-semibold">{{ tt('upload_title') }}</h2>
        <p class="mb-4 text-sm text-neutral-500">{{ tt('upload_hint') }}</p>

        <div class="mb-5 rounded-lg border border-neutral-200 bg-neutral-50 px-4 py-3 text-sm" data-testid="premier-backup-help">
          <h3 class="mb-2 font-medium text-neutral-700">{{ tt('backup_help_title') }}</h3>
          <ol class="list-decimal space-y-1 pl-5 text-neutral-600">
            <li v-for="(item, i) in backupHelpItems" :key="i">{{ item }}</li>
          </ol>
        </div>

        <div class="mb-5 rounded-lg border border-neutral-200 bg-neutral-50 px-4 py-3 text-sm" data-testid="premier-file-help">
          <h3 class="mb-2 font-medium text-neutral-700">{{ tt('file_help_title') }}</h3>
          <ul class="list-disc space-y-1 pl-5 text-neutral-600">
            <li v-for="(item, i) in fileHelpItems" :key="i">{{ item }}</li>
          </ul>
        </div>

        <label class="block max-w-xl text-sm font-medium">
          {{ tt('choose_file') }}
          <input type="file" accept=".izip,.icab,.zip" class="mt-1 block w-full rounded-md border border-neutral-300 px-3 py-2 text-sm" data-testid="premier-input" :disabled="busy" @change="onFile" />
        </label>
        <div v-if="uploadPercent !== null || processing" class="mt-4 max-w-xl space-y-2 rounded-md border border-primary-200 bg-primary-50/50 px-3 py-3" data-testid="premier-progress">
          <div class="text-sm font-medium text-primary-700">
            {{ processing ? tt('processing') : tt('uploading_percent', { percent: uploadPercent ?? 0 }) }}
          </div>
          <div class="h-2 overflow-hidden rounded-full bg-primary-100">
            <div class="h-full bg-primary-500 transition-all duration-300"
              :class="processing ? 'w-1/3 animate-pulse' : ''"
              :style="processing ? undefined : { width: (uploadPercent ?? 0) + '%' }"></div>
          </div>
          <p class="text-xs text-neutral-500">{{ processing ? tt('processing_hint') : tt('uploading_hint') }}</p>
        </div>
      </template>

      <template v-else-if="currentStep === 2 && upload">
        <h2 class="mb-4 text-lg font-semibold">{{ tt('step2') }}</h2>

        <h3 class="mb-2 text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ tt('agendas_title') }}</h3>
        <div class="mb-2 overflow-x-auto rounded-lg border border-neutral-200">
          <table class="min-w-full text-sm">
            <thead class="bg-neutral-50 text-left text-xs text-neutral-500">
              <tr>
                <th class="w-10 px-3 py-2 whitespace-nowrap">{{ tt('col_select') }}</th>
                <th class="px-3 py-2">{{ tt('col_ico') }}</th>
                <th class="px-3 py-2">{{ tt('col_company') }}</th>
                <th class="px-3 py-2">{{ tt('col_year') }}</th>
                <th class="px-3 py-2 text-right">{{ tt('col_entries') }}</th>
                <th class="px-3 py-2">{{ tt('col_payroll') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="a in agendas" :key="`${a.ico}-${a.year}`" :class="a.ico === upload.supplier_ico && isSelected(a.year) ? 'bg-primary-50/60' : ''">
                <td class="px-3 py-2">
                  <input v-if="a.ico === upload.supplier_ico" type="checkbox" class="rounded border-neutral-300 text-primary-600" :checked="isSelected(a.year)"
                    :aria-label="tt('select_year', { year: a.year })" :data-testid="`premier-year-${a.year}`"
                    @change="toggleYear(a.year, ($event.target as HTMLInputElement).checked)" />
                </td>
                <td class="px-3 py-2 font-mono whitespace-nowrap">{{ a.ico || '—' }}</td>
                <td class="px-3 py-2">
                  {{ a.company || '—' }}
                  <span v-if="a.ico !== upload.supplier_ico" class="ml-2 rounded-full bg-neutral-100 px-2 py-0.5 text-xs whitespace-nowrap text-neutral-600">{{ tt('other_company') }}</span>
                </td>
                <td class="px-3 py-2 font-medium" :data-testid="`premier-year-label-${a.year}`">{{ migrationYearLabel(a.year, a, locale) }}</td>
                <td class="px-3 py-2 text-right">{{ a.entries }}</td>
                <td class="px-3 py-2 whitespace-nowrap">{{ a.has_payroll ? tt('payroll_included') : '—' }}</td>
              </tr>
            </tbody>
          </table>
        </div>
        <p class="mb-5 text-sm text-neutral-500">{{ tt('supplier_ico', { ico: upload.supplier_ico || '—' }) }}</p>

        <p v-if="!years.length" class="mb-5 rounded-lg border border-danger-500/30 bg-danger-50 px-3 py-2 text-sm text-danger-600" data-testid="premier-no-agenda">
          {{ upload.supplier_ico ? tt('no_matching_agenda', { ico: upload.supplier_ico }) : tt('supplier_ico_missing') }}
        </p>
        <template v-else>
          <div class="mb-5 text-sm" data-testid="premier-years">
            <p class="font-medium">{{ tt('year') }}: <span data-testid="premier-years-selected">{{ yearsLabel || '—' }}</span></p>
            <p class="mt-1 text-neutral-500">{{ tt('year_hint') }}</p>
          </div>

          <p v-if="!selectedYears.length" class="mb-5 rounded-lg border border-warning-500/30 bg-warning-50 px-3 py-2 text-sm text-warning-700" data-testid="premier-no-year-selected">{{ tt('choose_year_first') }}</p>
          <div v-for="g in preflightGroups" :key="g.year" class="mb-5" :data-testid="`premier-preflight-${g.year}`">
            <h3 class="mb-2 text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ tt('preflight_title', { year: yearLabel(g.year) }) }}</h3>
            <ul v-if="g.messages.length" class="space-y-2">
              <li v-for="m in g.messages" :key="m.code + m.message" class="rounded-lg border px-3 py-2 text-sm"
                :class="m.level === 'error' ? 'border-danger-500/30 bg-danger-50 text-danger-600' : m.level === 'warning' ? 'border-warning-500/30 bg-warning-50 text-warning-700' : 'border-primary-500/30 bg-primary-50 text-primary-700'">
                {{ m.message }}
              </li>
            </ul>
            <p v-else class="rounded-lg border border-success-500/30 bg-success-50 px-3 py-2 text-sm text-success-600">{{ tt('preflight_ok') }}</p>
          </div>
        </template>
      </template>

      <template v-else-if="currentStep === 3">
        <h2 class="mb-1 text-lg font-semibold">{{ tt('dry_run_title') }}</h2>
        <p class="mb-2 text-sm text-neutral-500">{{ tt('dry_run_hint', { years: yearsLabel }) }}</p>
        <p v-if="selectedYears.length > 1" class="mb-2 rounded-lg border border-warning-500/30 bg-warning-50 px-3 py-2 text-sm text-warning-700" data-testid="premier-dry-run-years-hint">{{ tt('dry_run_years_hint') }}</p>
        <p class="mb-4 rounded-lg border border-warning-500/30 bg-warning-50 px-3 py-2 text-sm text-warning-700">{{ tt('dry_run_locks_hint') }}</p>
        <ImportJobProgress v-if="jobRunning" :job="job" :percent="null" :cancelling="false" :show-cancel="false"
          counts-key="premier.job_counts" background-hint-key="premier.background_hint" running-key="premier.dry_run_running" />
        <template v-if="jobRuns.length && jobRuns[0].mode === 'dry_run'">
          <p v-if="yearsNotRun.length" class="mb-3 rounded-lg border border-danger-500/30 bg-danger-50 px-3 py-2 text-sm text-danger-600" data-testid="premier-years-not-run">{{ tt('years_not_run', { years: yearsNotRun.join(', ') }) }}</p>
          <MoneyS3Protocol v-if="jobRuns.length === 1" :run="jobRuns[0]" prefix="premier" />
          <div v-else class="space-y-3" data-testid="premier-job-runs">
            <details v-for="(r, i) in jobRuns" :key="r.id" :open="r.status === 'failed' || i === jobRuns.length - 1" class="rounded-lg border border-neutral-200" :data-testid="`premier-job-run-${r.agenda_year}`">
              <summary class="flex cursor-pointer flex-wrap items-center justify-between gap-2 px-4 py-3">
                <span class="font-medium">{{ tt('job_run_year', { year: r.agenda_year ?? '', n: i + 1, total: jobRuns.length }) }}<span class="ml-2 text-xs text-neutral-500">#{{ r.id }}</span></span>
                <span class="rounded-full px-2.5 py-1 text-xs font-medium" :class="statusClass(r.status)">{{ tt(`status.${r.status}`) }}</span>
              </summary>
              <div class="border-t border-neutral-200 p-4"><MoneyS3Protocol :run="r" prefix="premier" /></div>
            </details>
          </div>
          <MigrationDifferences v-if="differencesAcceptable && !jobRunning" v-model="acceptDifferences" :differences="differences" prefix="premier" />
        </template>
      </template>

      <template v-else>
        <h2 class="mb-1 text-lg font-semibold">{{ tt('import_title') }}</h2>
        <label v-if="!importDone && !jobRunning" class="my-4 flex cursor-pointer items-start gap-3 rounded-lg border border-warning-500/30 bg-warning-50 p-4">
          <input v-model="confirmed" type="checkbox" class="mt-1 rounded border-neutral-300 text-primary-600" data-testid="premier-confirm" />
          <span class="text-sm text-warning-700">{{ tt('import_confirm', { company: ownAgenda?.company ?? '', years: yearsLabel }) }}</span>
        </label>
        <p v-if="!importDone && !jobRunning && !dryRunPassed && acceptDifferences" class="my-4 rounded-lg border border-warning-500/30 bg-warning-50 px-3 py-2 text-sm text-warning-700" data-testid="premier-accepting-differences">
          {{ tt('differences.import_note', { count: differences.length }) }}
        </p>
        <p v-if="!importDone && !jobRunning && missingRights.length" class="my-4 rounded-lg border border-danger-500/30 bg-danger-50 px-3 py-2 text-sm text-danger-600" data-testid="premier-rights-missing">
          {{ rightsMessage }}
        </p>
        <ImportJobProgress v-if="jobRunning" :job="job" :percent="percent" :cancelling="cancelling" :show-cancel="true"
          counts-key="premier.job_counts" background-hint-key="premier.background_hint" running-key="premier.import_running"
          cancel-key="premier.cancel" cancelling-key="premier.cancelling" @cancel="cancel" />
        <template v-if="jobRuns.length && jobRuns[0].mode === 'import'">
          <p v-if="yearsNotRun.length" class="mb-3 rounded-lg border border-danger-500/30 bg-danger-50 px-3 py-2 text-sm text-danger-600" data-testid="premier-years-not-run">{{ tt('years_not_run', { years: yearsNotRun.join(', ') }) }}</p>
          <MoneyS3Protocol v-if="jobRuns.length === 1" :run="jobRuns[0]" prefix="premier" />
          <div v-else class="space-y-3" data-testid="premier-job-runs">
            <details v-for="(r, i) in jobRuns" :key="r.id" :open="r.status === 'failed' || i === jobRuns.length - 1" class="rounded-lg border border-neutral-200" :data-testid="`premier-job-run-${r.agenda_year}`">
              <summary class="flex cursor-pointer flex-wrap items-center justify-between gap-2 px-4 py-3">
                <span class="font-medium">{{ tt('job_run_year', { year: r.agenda_year ?? '', n: i + 1, total: jobRuns.length }) }}<span class="ml-2 text-xs text-neutral-500">#{{ r.id }}</span></span>
                <span class="rounded-full px-2.5 py-1 text-xs font-medium" :class="statusClass(r.status)">{{ tt(`status.${r.status}`) }}</span>
              </summary>
              <div class="border-t border-neutral-200 p-4"><MoneyS3Protocol :run="r" prefix="premier" /></div>
            </details>
          </div>
        </template>
      </template>
    </section>

    <div class="flex flex-wrap justify-end"><ActionBar :actions="actions" /></div>

    <section class="overflow-hidden rounded-lg border border-neutral-200 bg-surface shadow-sm">
      <h2 class="border-b border-neutral-200 px-4 py-3 text-lg font-semibold">{{ tt('history_title') }}</h2>
      <p v-if="!runs.length" class="px-4 py-3 text-sm text-neutral-500">{{ tt('history_empty') }}</p>
      <div class="divide-y divide-neutral-100">
        <div v-for="item in runs" :key="item.id" class="flex items-center gap-2 pr-3 hover:bg-neutral-50">
          <button type="button" class="flex min-w-0 flex-1 cursor-pointer flex-wrap items-center justify-between gap-3 px-4 py-3 text-left" @click="showRun(item)">
            <span><strong>#{{ item.id }} · {{ tt(`mode.${item.mode}`) }}</strong><span class="ml-2 text-xs text-neutral-500">{{ [item.agenda_ico, item.agenda_year].filter(Boolean).join(' · ') }} · {{ item.created_at }}</span></span>
            <span class="rounded-full px-2.5 py-1 text-xs font-medium"
              :class="item.status === 'completed' ? 'bg-success-50 text-success-600' : item.status === 'failed' ? 'bg-danger-50 text-danger-600' : item.status === 'completed_with_warnings' ? 'bg-warning-50 text-warning-700' : 'bg-neutral-100 text-neutral-600'">{{ tt(`status.${item.status}`) }}</span>
          </button>
          <button v-if="item.mode === 'dry_run' && item.status !== 'running' && canWrite" type="button"
            class="shrink-0 rounded p-1.5 text-neutral-400 hover:bg-danger-50 hover:text-danger-600 disabled:opacity-40"
            :title="tt('run_delete')" :aria-label="tt('run_delete')" :disabled="deletingRun === item.id"
            :data-testid="`premier-run-delete-${item.id}`" @click="deleteRun(item)">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.trash" /></svg>
          </button>
        </div>
      </div>
    </section>

    <section v-if="run && !(currentStep >= 3 && jobRuns.some(r => r.id === run?.id))" class="rounded-lg border border-neutral-200 bg-surface p-5 shadow-sm">
      <MoneyS3Protocol :run="run" prefix="premier" />
    </section>

    <CompanyProfileBox variant="migration" />
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { apiErrorMessage } from '@/api/errors'
import {
  payrollApi,
  type PayrollHealthPaymentOverview,
  type PayrollJmhzPvpojOffice,
  type PayrollJmhzPvpojPreview,
  type PayrollRegzelEnvironment,
  type PayrollRun,
  type PayrollSubmissionAgendaGroup,
  type PayrollSubmissionDeadlinePhase,
  type PayrollSubmissionDetail,
  type PayrollSubmissionOverviewItem,
} from '@/api/payroll'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import PaginationBar from '@/components/ui/PaginationBar.vue'
import PayrollJmhzOrdinaryEvidencePanel from './PayrollJmhzOrdinaryEvidencePanel.vue'
import PayrollJmhzXmlDryRunPanel from './PayrollJmhzXmlDryRunPanel.vue'
import PayrollJmhzDispatchPanel from './PayrollJmhzDispatchPanel.vue'
import { btnOutline, btnOutlineSm, ICONS } from '@/components/ui/buttonStyles'
// Formátování je sdílené (useFormat) — místní kopie se rozcházely v locale i tvaru.
import { formatDate } from '@/composables/useFormat'
import { localPayrollPeriod } from './payrollComponentsUi'
import ColumnPicker from '@/components/ui/ColumnPicker.vue'
import DensityToggle from '@/components/ui/DensityToggle.vue'
import { useTablePrefs, type ColumnDef } from '@/composables/useTablePrefs'
import { usePayrollLabels } from '@/composables/usePayrollLabels'

/*
 * `mode` je zároveň `agenda_group` pro server. Skupina `other` je záchytná:
 * `agenda_code` povinnosti je volný text, takže se do přehledu může dostat
 * kód, který katalog agend nezná. Panel pro ni ukáže jen holou tabulku —
 * nástavby (náhledy JMHZ, přehledy plateb ZP) patří konkrétní agendě.
 */
const props = defineProps<{
  mode: PayrollSubmissionAgendaGroup
}>()

const { locale, t } = useI18n()
const {
  artifactKindLabel,
  issueSeverityLabel,
  submissionChannelLabel,
  submissionIssueMessage,
  submissionIssueRemediation,
  submissionKindLabel,
  submissionStatusLabel,
  validationStageLabel,
  verificationStatusLabel,
} = usePayrollLabels()
const loading = ref(true)
const error = ref('')
const healthError = ref('')
const period = ref(localPayrollPeriod())
const environment = defineModel<PayrollRegzelEnvironment>('environment', {
  default: 'production',
})
// Server filtruje podle `agenda_group`, takže `items` je rovnou to, co panel
// ukazuje — žádné doufiltrovávání na klientovi.
const items = ref<PayrollSubmissionOverviewItem[]>([])
const pageSize = 50
const total = ref(0)
const offset = ref(0)
const currentPage = computed(() => Math.floor(offset.value / pageSize) + 1)
const EMPTY_DEADLINE_SUMMARY: Record<PayrollSubmissionDeadlinePhase, number> = {
  not_open: 0,
  open: 0,
  due_soon: 0,
  due_today: 0,
  overdue: 0,
  awaiting_result: 0,
  fulfilled: 0,
  action_required: 0,
  cancelled: 0,
}
const deadlineSummary = ref<Record<PayrollSubmissionDeadlinePhase, number>>({
  ...EMPTY_DEADLINE_SUMMARY,
})
const healthOverviews = ref<PayrollHealthPaymentOverview[]>([])
const jmhzPreviews = ref<PayrollJmhzPvpojPreview[]>([])
const jmhzApprovedRuns = ref<PayrollRun[]>([])
const downloadingHealthKey = ref<string | null>(null)
const downloadingJmhzKey = ref<string | null>(null)
const jmhzError = ref('')
/**
 * Registrace u OSSZ, za které přehled sestavit NELZE. Držíme je zvlášť
 * a adresně: dokud se všechny chyby slévaly do jednoho banneru, uživatel se
 * nedozvěděl, KTERÉ účtárně chybí variabilní symbol — a ani to, že ostatní
 * přehledy jsou v pořádku.
 */
const jmhzBlockedOffices = ref<Array<{
  key: string
  runId: number
  revisionId: number
  office: PayrollJmhzPvpojOffice
  message: string
}>>([])
const detail = ref<PayrollSubmissionDetail | null>(null)
const detailLoadingId = ref<number | null>(null)
const detailError = ref('')
const downloadingArtifactId = ref<number | null>(null)
const artifactDownloadError = ref('')

const COLUMNS: ColumnDef[] = [
  { key: 'agenda', labelKey: 'payroll.submissions.overview.agenda', required: true },
  { key: 'subject', labelKey: 'payroll.submissions.overview.subject' },
  { key: 'due_on', labelKey: 'payroll.submissions.overview.due_on' },
  { key: 'channel', labelKey: 'payroll.submissions.overview.channel_label' },
  { key: 'status', labelKey: 'payroll.submissions.overview.status_label' },
  { key: 'actions', labelKey: 'common.actions', required: true },
]
const tbl = useTablePrefs('payroll-submission-overview', COLUMNS)

const environmentOptions = computed(() => [
  {
    value: 'production' as const,
    label: t('payroll.regzel.environment.production'),
  },
  {
    value: 'test' as const,
    label: t('payroll.regzel.environment.test'),
  },
])
/*
 * Karty stojí na serverovém `deadline_summary`, ne na načtené stránce — souhrn
 * platí za celé období a (díky `agenda_group`) právě za agendu tohohle panelu.
 */
const counts = computed(() => {
  const phases = deadlineSummary.value
  return {
    total: total.value,
    open: phases.not_open + phases.open + phases.due_soon + phases.due_today,
    submitted: phases.awaiting_result,
    fulfilled: phases.fulfilled,
    attention: phases.overdue + phases.action_required,
  }
})

function statusClass(status: string): string {
  if (status === 'fulfilled') return 'bg-success-50 text-success-700'
  if (status === 'submitted') return 'bg-primary-50 text-primary-700'
  if (['overdue', 'manual_review'].includes(status)) {
    return 'bg-warning-50 text-warning-700'
  }
  if (status === 'cancelled') return 'bg-neutral-100 text-neutral-600'
  return 'bg-payroll-50 text-payroll-700'
}

function deadlineClass(item: PayrollSubmissionOverviewItem): string {
  if (item.deadline.phase === 'fulfilled') return 'bg-success-50 text-success-700'
  if (item.deadline.phase === 'cancelled') return 'bg-neutral-100 text-neutral-600'
  if (['overdue', 'action_required'].includes(item.deadline.phase)) {
    return 'bg-danger-50 text-danger-700'
  }
  if (item.deadline.phase === 'due_today') return 'bg-warning-50 text-warning-700'
  if (item.deadline.phase === 'due_soon') return 'bg-payroll-50 text-payroll-700'
  if (item.deadline.phase === 'awaiting_result') return 'bg-primary-50 text-primary-700'
  return 'bg-neutral-100 text-neutral-700'
}

function deadlineLabel(item: PayrollSubmissionOverviewItem): string {
  return t(
    `payroll.submissions.overview.deadline_phase.${item.deadline.phase}`,
    { count: Math.abs(item.deadline.days_to_due) },
  )
}

function formatMinor(value: number): string {
  return new Intl.NumberFormat(locale.value === 'en' ? 'en-US' : 'cs-CZ', {
    style: 'currency',
    currency: 'CZK',
    maximumFractionDigits: 2,
  }).format(value / 100)
}

function formatCzk(value: number): string {
  return new Intl.NumberFormat(locale.value === 'en' ? 'en-US' : 'cs-CZ', {
    style: 'currency',
    currency: 'CZK',
    maximumFractionDigits: 0,
  }).format(value)
}

function healthOverviewKey(overview: PayrollHealthPaymentOverview): string {
  return `${overview.revision_id}:${overview.insurer.code}`
}

function readableBytes(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} kB`
  return `${(bytes / 1024 / 1024).toFixed(1)} MB`
}

async function openDetail(item: PayrollSubmissionOverviewItem) {
  if (!item.latest_submission || detailLoadingId.value !== null) return
  detailError.value = ''
  artifactDownloadError.value = ''
  detailLoadingId.value = item.latest_submission.id
  try {
    detail.value = await payrollApi.submissionDetail(item.latest_submission.id)
  } catch (exception) {
    detail.value = null
    detailError.value = apiErrorMessage(
      exception,
      t('payroll.submissions.overview.detail_load_failed'),
    )
  } finally {
    detailLoadingId.value = null
  }
}

async function downloadArtifact(
  artifact: PayrollSubmissionDetail['artifacts'][number],
) {
  if (!detail.value || downloadingArtifactId.value !== null) return
  artifactDownloadError.value = ''
  downloadingArtifactId.value = artifact.id
  try {
    await payrollApi.downloadSubmissionArtifact(
      detail.value.submission.id,
      artifact,
    )
  } catch (exception) {
    artifactDownloadError.value = apiErrorMessage(
      exception,
      t('payroll.submissions.overview.artifact_download_failed'),
    )
  } finally {
    downloadingArtifactId.value = null
  }
}

async function loadHealthOverviews() {
  healthOverviews.value = []
  healthError.value = ''
  if (props.mode !== 'health') return
  try {
    const runs = await payrollApi.runs(period.value)
    const approved = runs.filter(run =>
      run.revision_status === 'approved' && run.revision_id !== null,
    )
    const responses = await Promise.all(approved.map(run =>
      payrollApi.healthPaymentOverviews(run.revision_id!),
    ))
    healthOverviews.value = responses.flatMap(response => response.items)
  } catch (exception) {
    healthError.value = apiErrorMessage(
      exception,
      t('payroll.submissions.overview.health_load_failed'),
    )
  }
}

/**
 * Přehled o výši pojistného se podává ZA REGISTRACI u OSSZ, ne za mzdový běh.
 * Revize přes víc mzdových účtáren proto dá víc přehledů a každý se musí
 * vyžádat se svou účtárnou.
 */
async function loadJmhzPreviews() {
  jmhzPreviews.value = []
  jmhzBlockedOffices.value = []
  jmhzApprovedRuns.value = []
  jmhzError.value = ''
  if (props.mode !== 'jmhz') return
  try {
    const runs = await payrollApi.runs(period.value)
    const approved = runs.filter(run =>
      run.revision_status === 'approved' && run.revision_id !== null,
    )
    jmhzApprovedRuns.value = approved
    const officeLists = await Promise.allSettled(approved.map(run =>
      payrollApi.jmhzPvpojOffices(run.revision_id!),
    ))
    const wanted: Array<{ run: PayrollRun; office: PayrollJmhzPvpojOffice }> = []
    officeLists.forEach((response, index) => {
      const run = approved[index]!
      if (response.status === 'rejected') {
        jmhzError.value = apiErrorMessage(
          response.reason,
          t('payroll.submissions.overview.jmhz_load_failed'),
        )
        return
      }
      response.value.forEach(office => {
        if (office.submittable) {
          wanted.push({ run, office })
          return
        }
        jmhzBlockedOffices.value.push({
          key: `${run.revision_id}-${office.office_id}`,
          runId: run.id,
          revisionId: run.revision_id!,
          office,
          message: t(
            'payroll.submissions.overview.jmhz_office_variable_symbol_missing',
          ),
        })
      })
    })
    const responses = await Promise.allSettled(wanted.map(entry =>
      payrollApi.jmhzPvpojPreview(entry.run.revision_id!, entry.office.office_id),
    ))
    responses.forEach((response, index) => {
      const entry = wanted[index]!
      if (response.status === 'fulfilled') {
        jmhzPreviews.value.push(response.value)
        return
      }
      jmhzBlockedOffices.value.push({
        key: `${entry.run.revision_id}-${entry.office.office_id}`,
        runId: entry.run.id,
        revisionId: entry.run.revision_id!,
        office: entry.office,
        message: apiErrorMessage(
          response.reason,
          t('payroll.submissions.overview.jmhz_load_failed'),
        ),
      })
    })
  } catch (exception) {
    jmhzError.value = apiErrorMessage(
      exception,
      t('payroll.submissions.overview.jmhz_load_failed'),
    )
  }
}

function jmhzPreviewKey(preview: PayrollJmhzPvpojPreview): string {
  return `${preview.revision_id}-${preview.office.office_id}`
}

async function downloadJmhz(preview: PayrollJmhzPvpojPreview) {
  jmhzError.value = ''
  downloadingJmhzKey.value = jmhzPreviewKey(preview)
  try {
    await payrollApi.downloadJmhzPvpojPreview(preview)
  } catch (exception) {
    jmhzError.value = apiErrorMessage(
      exception,
      t('payroll.submissions.overview.jmhz_download_failed'),
    )
  } finally {
    downloadingJmhzKey.value = null
  }
}

async function downloadHealth(overview: PayrollHealthPaymentOverview) {
  healthError.value = ''
  downloadingHealthKey.value = healthOverviewKey(overview)
  try {
    await payrollApi.downloadHealthPaymentOverview(overview)
  } catch (exception) {
    healthError.value = apiErrorMessage(
      exception,
      t('payroll.submissions.overview.health_download_failed'),
    )
  } finally {
    downloadingHealthKey.value = null
  }
}

async function load() {
  loading.value = true
  error.value = ''
  detail.value = null
  detailError.value = ''
  artifactDownloadError.value = ''
  try {
    const response = await payrollApi.submissionOverview(
      environment.value,
      period.value,
      { agenda_group: props.mode, limit: pageSize, offset: offset.value },
    )
    items.value = response.items
    total.value = response.total
    deadlineSummary.value = response.deadline_summary
    await Promise.all([
      loadHealthOverviews(),
      loadJmhzPreviews(),
    ])
  } catch (exception) {
    items.value = []
    total.value = 0
    deadlineSummary.value = { ...EMPTY_DEADLINE_SUMMARY }
    healthOverviews.value = []
    jmhzPreviews.value = []
    jmhzApprovedRuns.value = []
    error.value = apiErrorMessage(
      exception,
      t('payroll.submissions.overview.load_failed'),
    )
  } finally {
    loading.value = false
  }
}

function goToPage(nextPage: number) {
  offset.value = Math.max(0, (nextPage - 1) * pageSize)
  void load()
}

// Změna filtru mění obsah seznamu, takže stránka musí zpět na začátek.
watch([environment, period, () => props.mode], () => {
  offset.value = 0
  void load()
})
onMounted(load)
</script>

<template>
  <section class="space-y-4">
    <div class="rounded-xl border border-neutral-200 bg-surface p-4 shadow-sm sm:p-6">
      <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="max-w-3xl">
          <div class="flex flex-wrap items-center gap-2">
            <h2 class="text-lg font-semibold text-neutral-900">
              {{ t(`payroll.submissions.${mode}_title`) }}
            </h2>
            <span
              class="rounded-full px-2.5 py-1 text-xs font-medium"
              :class="mode === 'jmhz'
                ? 'bg-primary-50 text-primary-700'
                : 'bg-warning-50 text-warning-700'"
            >
              {{ mode === 'jmhz'
                ? t('payroll.submissions.overview.transport_available')
                : t('payroll.submissions.overview.transport_unavailable') }}
            </span>
          </div>
          <p class="mt-2 text-sm text-neutral-600">
            {{ t(`payroll.submissions.${mode}_fail_closed`) }}
          </p>
        </div>
        <button type="button" :class="btnOutline('neutral')" :disabled="loading" @click="load">
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path :d="ICONS.cycle" />
          </svg>
          {{ t('common.refresh') }}
        </button>
      </div>

      <div class="mt-5 grid grid-cols-1 gap-4 sm:grid-cols-2">
        <label class="block text-sm font-medium text-neutral-700">
          {{ t('payroll.submissions.overview.period') }}
          <input
            v-model="period"
            type="month"
            class="mt-1 h-10 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm focus:border-payroll-500 focus:outline-none focus:ring-2 focus:ring-payroll-500/20"
            data-test="submission-overview-period"
          >
        </label>
        <label class="block text-sm font-medium text-neutral-700">
          {{ t('payroll.submissions.overview.environment') }}
          <SearchableSelect
            v-model="environment"
            class="mt-1"
            :options="environmentOptions"
            :clearable="false"
            accent="payroll"
            data-test="submission-overview-environment"
          />
        </label>
      </div>
    </div>

    <p
      v-if="error"
      class="rounded-xl border border-danger-500/30 bg-danger-50 p-4 text-sm text-danger-700"
      role="alert"
      data-test="submission-overview-error"
    >
      {{ error }}
    </p>

    <div v-if="loading" class="grid grid-cols-2 gap-3 lg:grid-cols-5">
      <div v-for="index in 5" :key="index" class="h-20 animate-pulse rounded-xl bg-neutral-100" />
    </div>

    <template v-else>
      <dl class="grid grid-cols-2 gap-3 lg:grid-cols-5">
        <div
          v-for="entry in (['total', 'open', 'submitted', 'fulfilled', 'attention'] as const)"
          :key="entry"
          class="rounded-xl border border-neutral-200 bg-surface p-4 shadow-sm"
        >
          <dt class="text-xs font-medium text-neutral-500">
            {{ t(`payroll.submissions.overview.summary.${entry}`) }}
          </dt>
          <dd class="mt-1 text-2xl font-semibold text-neutral-900">
            {{ counts[entry] }}
          </dd>
        </div>
      </dl>

      <section class="overflow-hidden rounded-xl border border-neutral-200 bg-surface shadow-sm">
        <div v-if="items.length === 0" class="p-6 text-sm text-neutral-500">
          {{ t('payroll.submissions.overview.empty') }}
        </div>

        <template v-else>
          <div class="hidden items-center justify-end gap-2 border-b border-neutral-200 px-4 py-2 md:flex">
            <ColumnPicker :ctrl="tbl" />
            <DensityToggle :ctrl="tbl" />
          </div>
          <div class="hidden overflow-x-auto md:block">
            <table class="min-w-full divide-y divide-neutral-200 text-sm" :class="tbl.densityClass.value">
              <thead>
                <tr class="text-left text-xs uppercase tracking-wide text-neutral-500">
                  <th v-if="tbl.isVisible('agenda')" class="px-4 py-3">{{ t('payroll.submissions.overview.agenda') }}</th>
                  <th v-if="tbl.isVisible('subject')" class="px-4 py-3">{{ t('payroll.submissions.overview.subject') }}</th>
                  <th v-if="tbl.isVisible('due_on')" class="px-4 py-3">{{ t('payroll.submissions.overview.due_on') }}</th>
                  <th v-if="tbl.isVisible('channel')" class="px-4 py-3">{{ t('payroll.submissions.overview.channel_label') }}</th>
                  <th v-if="tbl.isVisible('status')" class="px-4 py-3">{{ t('payroll.submissions.overview.status_label') }}</th>
                  <th v-if="tbl.isVisible('actions')" class="px-4 py-3 text-right">{{ t('common.actions') }}</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-neutral-100">
                <tr v-for="item in items" :key="item.id">
                  <td v-if="tbl.isVisible('agenda')" class="px-4 py-3 font-medium text-neutral-900">{{ item.agenda_code }}</td>
                  <td v-if="tbl.isVisible('subject')" class="px-4 py-3 text-neutral-700">{{ item.subject_reference }}</td>
                  <td v-if="tbl.isVisible('due_on')" class="px-4 py-3 text-neutral-700">
                    <span class="block">{{ formatDate(item.due_on) }}</span>
                    <span
                      class="mt-1 inline-flex rounded-full px-2 py-0.5 text-xs font-medium"
                      :class="deadlineClass(item)"
                      data-test="submission-deadline-phase"
                    >
                      {{ deadlineLabel(item) }}
                    </span>
                  </td>
                  <td v-if="tbl.isVisible('channel')" class="px-4 py-3 text-neutral-700">{{ submissionChannelLabel(item.preferred_channel) }}</td>
                  <td v-if="tbl.isVisible('status')" class="px-4 py-3">
                    <span class="rounded-full px-2.5 py-1 text-xs font-medium" :class="statusClass(item.status)">
                      {{ submissionStatusLabel(item.status) }}
                    </span>
                  </td>
                  <td v-if="tbl.isVisible('actions')" class="px-4 py-3 text-right">
                    <button
                      v-if="item.latest_submission"
                      type="button"
                      :class="btnOutlineSm('neutral')"
                      :disabled="detailLoadingId !== null"
                      data-test="submission-detail-open"
                      @click="openDetail(item)"
                    >
                      <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path :d="ICONS.doc" />
                      </svg>
                      {{ t('payroll.submissions.overview.detail_action') }}
                    </button>
                    <span v-else class="text-xs text-neutral-400">—</span>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </template>

        <div v-if="items.length" class="grid grid-cols-1 gap-3 p-4 md:hidden">
          <article v-for="item in items" :key="item.id" class="rounded-lg border border-neutral-200 p-4">
            <div class="flex flex-wrap items-start justify-between gap-2">
              <div>
                <h3 class="font-semibold text-neutral-900">{{ item.agenda_code }}</h3>
                <p class="mt-1 text-xs text-neutral-500">{{ item.subject_reference }}</p>
              </div>
              <span class="rounded-full px-2.5 py-1 text-xs font-medium" :class="statusClass(item.status)">
                {{ submissionStatusLabel(item.status) }}
              </span>
            </div>
            <dl class="mt-3 grid grid-cols-2 gap-3 text-xs">
              <div>
                <dt class="text-neutral-500">{{ t('payroll.submissions.overview.due_on') }}</dt>
                <dd class="mt-0.5 text-neutral-800">{{ formatDate(item.due_on) }}</dd>
                <dd
                  class="mt-1 inline-flex rounded-full px-2 py-0.5 text-xs font-medium"
                  :class="deadlineClass(item)"
                  data-test="submission-deadline-phase"
                >
                  {{ deadlineLabel(item) }}
                </dd>
              </div>
              <div>
                <dt class="text-neutral-500">{{ t('payroll.submissions.overview.channel_label') }}</dt>
                <dd class="mt-0.5 text-neutral-800">{{ submissionChannelLabel(item.preferred_channel) }}</dd>
              </div>
            </dl>
            <button
              v-if="item.latest_submission"
              type="button"
              class="mt-4"
              :class="btnOutline('neutral')"
              :disabled="detailLoadingId !== null"
              data-test="submission-detail-open"
              @click="openDetail(item)"
            >
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path :d="ICONS.doc" />
              </svg>
              {{ t('payroll.submissions.overview.detail_action') }}
            </button>
          </article>
        </div>

        <PaginationBar
          embedded
          :page="currentPage"
          :per-page="pageSize"
          :total="total"
          @update:page="goToPage"
        />
      </section>

      <p
        v-if="detailError"
        class="rounded-xl border border-danger-500/30 bg-danger-50 p-4 text-sm text-danger-700"
        role="alert"
        data-test="submission-detail-error"
      >
        {{ detailError }}
      </p>

      <section
        v-if="detail"
        class="overflow-hidden rounded-xl border border-neutral-200 bg-surface shadow-sm"
        data-test="submission-detail"
      >
        <div class="flex flex-wrap items-start justify-between gap-3 border-b border-neutral-200 p-4 sm:p-6">
          <div>
            <div class="flex flex-wrap items-center gap-2">
              <h2 class="text-lg font-semibold text-neutral-900">
                {{ t('payroll.submissions.overview.detail_title', {
                  agenda: detail.submission.agenda_code,
                  id: detail.submission.id,
                }) }}
              </h2>
              <span
                class="rounded-full px-2.5 py-1 text-xs font-medium"
                :class="statusClass(detail.submission.status)"
              >
                {{ submissionStatusLabel(detail.submission.status) }}
              </span>
            </div>
            <p class="mt-1 text-sm text-neutral-500">
              {{ detail.submission.subject_reference }} ·
              {{ detail.submission.period_start }}–{{ detail.submission.period_end }}
            </p>
          </div>
          <button type="button" :class="btnOutline('neutral')" @click="detail = null">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
              <path :d="ICONS.x" />
            </svg>
            {{ t('common.close') }}
          </button>
        </div>

        <dl class="grid grid-cols-2 gap-4 border-b border-neutral-200 p-4 text-sm sm:grid-cols-4 sm:p-6">
          <div>
            <dt class="text-neutral-500">{{ t('payroll.submissions.overview.detail_kind') }}</dt>
            <dd class="mt-1 font-medium text-neutral-900">{{ submissionKindLabel(detail.submission.submission_kind) }}</dd>
          </div>
          <div>
            <dt class="text-neutral-500">{{ t('payroll.submissions.overview.channel_label') }}</dt>
            <dd class="mt-1 font-medium text-neutral-900">{{ submissionChannelLabel(detail.submission.channel) }}</dd>
          </div>
          <div>
            <dt class="text-neutral-500">{{ t('payroll.submissions.overview.detail_created') }}</dt>
            <dd class="mt-1 font-medium text-neutral-900">{{ detail.submission.created_at }}</dd>
          </div>
          <div>
            <dt class="text-neutral-500">{{ t('payroll.submissions.overview.detail_correlation') }}</dt>
            <dd class="mt-1 break-all font-medium text-neutral-900">
              {{ detail.submission.correlation_reference || '—' }}
            </dd>
          </div>
        </dl>

        <div class="grid grid-cols-1 gap-4 p-4 lg:grid-cols-2 sm:p-6">
          <article class="rounded-lg border border-neutral-200 p-4">
            <h3 class="font-semibold text-neutral-900">
              {{ t('payroll.submissions.overview.detail_parts', { count: detail.parts.length }) }}
            </h3>
            <p v-if="detail.parts.length === 0" class="mt-3 text-sm text-neutral-500">
              {{ t('payroll.submissions.overview.detail_none') }}
            </p>
            <ul v-else class="mt-3 divide-y divide-neutral-100">
              <li v-for="part in detail.parts" :key="part.id" class="py-3 first:pt-0 last:pb-0">
                <div class="flex flex-wrap items-center justify-between gap-2">
                  <span class="font-medium text-neutral-900">{{ part.agenda_code }} · {{ part.part_reference }}</span>
                  <span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="statusClass(part.status)">
                    {{ submissionStatusLabel(part.status) }}
                  </span>
                </div>
                <p class="mt-1 text-xs text-neutral-500">{{ part.subject_reference }}</p>
              </li>
            </ul>
          </article>

          <article class="rounded-lg border border-neutral-200 p-4">
            <h3 class="font-semibold text-neutral-900">
              {{ t('payroll.submissions.overview.detail_artifacts', { count: detail.artifacts.length }) }}
            </h3>
            <p
              v-if="artifactDownloadError"
              class="mt-3 rounded-lg border border-danger-500/30 bg-danger-50 p-3 text-sm text-danger-700"
              role="alert"
              data-test="submission-artifact-download-error"
            >
              {{ artifactDownloadError }}
            </p>
            <p v-if="detail.artifacts.length === 0" class="mt-3 text-sm text-neutral-500">
              {{ t('payroll.submissions.overview.detail_none') }}
            </p>
            <ul v-else class="mt-3 divide-y divide-neutral-100">
              <li v-for="artifact in detail.artifacts" :key="artifact.id" class="py-3 first:pt-0 last:pb-0">
                <div class="flex flex-wrap items-center justify-between gap-2">
                  <div>
                    <span class="font-medium text-neutral-900">{{ artifactKindLabel(artifact.artifact_kind) }}</span>
                    <span class="ml-2 text-xs text-neutral-500">{{ readableBytes(artifact.byte_size) }}</span>
                  </div>
                  <button
                    type="button"
                    :class="btnOutlineSm('neutral')"
                    :disabled="downloadingArtifactId !== null"
                    data-test="submission-artifact-download"
                    @click="downloadArtifact(artifact)"
                  >
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                      <path :d="ICONS.download" />
                    </svg>
                    {{
                      downloadingArtifactId === artifact.id
                        ? t('payroll.submissions.overview.artifact_downloading')
                        : t('common.download')
                    }}
                  </button>
                </div>
                <p class="mt-1 text-xs text-neutral-500">
                  {{ artifact.mime_type }}
                  <template v-if="artifact.xsd_version"> · XSD {{ artifact.xsd_version }}</template>
                </p>
              </li>
            </ul>
          </article>

          <article class="rounded-lg border border-neutral-200 p-4">
            <h3 class="font-semibold text-neutral-900">
              {{ t('payroll.submissions.overview.detail_issues', { count: detail.issues.length }) }}
            </h3>
            <p v-if="detail.issues.length === 0" class="mt-3 text-sm text-neutral-500">
              {{ t('payroll.submissions.overview.detail_none') }}
            </p>
            <ul v-else class="mt-3 divide-y divide-neutral-100">
              <li v-for="issue in detail.issues" :key="issue.id" class="py-3 first:pt-0 last:pb-0">
                <div class="flex flex-wrap items-center justify-between gap-2">
                  <span
                    class="font-medium text-neutral-900"
                    data-test="submission-issue-message"
                  >{{ submissionIssueMessage(issue.issue_code) }}</span>
                  <span
                    class="rounded-full px-2 py-0.5 text-xs font-medium"
                    :class="issue.is_resolved
                      ? 'bg-success-50 text-success-700'
                      : 'bg-warning-50 text-warning-700'"
                  >
                    {{ issue.is_resolved
                      ? t('payroll.submissions.overview.detail_resolved')
                      : issueSeverityLabel(issue.severity) }}
                  </span>
                </div>
                <p class="mt-1 text-xs text-neutral-500">{{ validationStageLabel(issue.validation_stage) }}</p>
                <p
                  class="mt-2 text-xs text-neutral-600"
                  data-test="submission-issue-remediation"
                >
                  {{ submissionIssueRemediation(issue.validation_stage) }}
                </p>
                <details class="mt-2 text-xs text-neutral-500" data-test="submission-issue-technical">
                  <summary class="cursor-pointer">{{ t('payroll.submissions.overview.issue_technical') }}</summary>
                  <p class="mt-1 break-all font-mono">{{ issue.issue_code }}</p>
                </details>
              </li>
            </ul>
          </article>

          <article class="rounded-lg border border-neutral-200 p-4">
            <h3 class="font-semibold text-neutral-900">
              {{ t('payroll.submissions.overview.detail_receipts', { count: detail.receipts.length }) }}
            </h3>
            <p v-if="detail.receipts.length === 0" class="mt-3 text-sm text-neutral-500">
              {{ t('payroll.submissions.overview.detail_none') }}
            </p>
            <ul v-else class="mt-3 divide-y divide-neutral-100">
              <li v-for="receipt in detail.receipts" :key="receipt.id" class="py-3 first:pt-0 last:pb-0">
                <div class="flex flex-wrap items-center justify-between gap-2">
                  <span class="font-medium text-neutral-900">{{ receipt.protocol_code }}</span>
                  <span class="text-xs text-neutral-500">{{ verificationStatusLabel(receipt.verification_status) }}</span>
                </div>
                <p class="mt-1 break-all text-xs text-neutral-500">
                  {{ receipt.receipt_reference }} · {{ receipt.remote_status
                    ? submissionStatusLabel(receipt.remote_status)
                    : '—' }}
                </p>
              </li>
            </ul>
          </article>
        </div>
      </section>

      <section
        v-if="mode === 'jmhz'"
        class="overflow-hidden rounded-xl border border-neutral-200 bg-surface shadow-sm"
        data-test="jmhz-pvpoj-previews"
      >
        <div class="border-b border-neutral-200 p-4 sm:p-6">
          <h2 class="text-lg font-semibold text-neutral-900">
            {{ t('payroll.submissions.overview.jmhz_preview_title') }}
          </h2>
          <p class="mt-1 text-sm text-neutral-500">
            {{ t('payroll.submissions.overview.jmhz_preview_description') }}
          </p>
        </div>

        <p
          v-if="jmhzError"
          class="m-4 rounded-lg border border-warning-500/30 bg-warning-50 p-3 text-sm text-warning-700"
          role="alert"
          data-test="jmhz-preview-error"
        >
          {{ jmhzError }}
        </p>

        <ul
          v-if="jmhzBlockedOffices.length"
          class="m-4 space-y-2"
          data-test="jmhz-blocked-offices"
        >
          <li
            v-for="blocked in jmhzBlockedOffices"
            :key="blocked.key"
            class="rounded-lg border border-warning-500/30 bg-warning-50 p-3 text-sm text-warning-700"
          >
            <span class="font-medium">
              {{ blocked.office.code }} · {{ blocked.office.name }}
            </span>
            <span class="ml-1">{{ blocked.message }}</span>
          </li>
        </ul>

        <div
          v-if="jmhzPreviews.length === 0 && !jmhzError && !jmhzBlockedOffices.length"
          class="p-6 text-sm text-neutral-500"
        >
          {{ t('payroll.submissions.overview.jmhz_preview_empty') }}
        </div>

        <div v-if="jmhzPreviews.length" class="grid grid-cols-1 gap-3 p-4 lg:grid-cols-2">
          <article
            v-for="preview in jmhzPreviews"
            :key="jmhzPreviewKey(preview)"
            class="rounded-lg border border-neutral-200 p-4"
          >
            <div class="flex flex-wrap items-start justify-between gap-3">
              <div>
                <h3 class="font-semibold text-neutral-900">
                  {{ t('payroll.submissions.overview.jmhz_preview_card', {
                    period: preview.period,
                  }) }}
                </h3>
                <p class="mt-1 text-xs text-neutral-500">
                  {{ t('payroll.submissions.overview.health_run_revision', {
                    run: preview.run_id,
                    revision: preview.revision_no,
                  }) }} · XSD {{ preview.xsd.bundle_version }}
                </p>
                <p class="mt-1 text-xs text-neutral-500" data-test="jmhz-preview-office">
                  {{ t('payroll.submissions.overview.jmhz_preview_office', {
                    code: preview.office.code,
                    name: preview.office.name,
                    symbol: preview.office.variable_symbol,
                  }) }}
                </p>
              </div>
              <button
                type="button"
                :class="btnOutlineSm('neutral')"
                :disabled="downloadingJmhzKey === jmhzPreviewKey(preview)"
                @click="downloadJmhz(preview)"
              >
                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                  <path :d="ICONS.download" />
                </svg>
                {{ t('common.download') }}
              </button>
            </div>
            <dl class="mt-4 grid grid-cols-2 gap-3 text-xs">
              <div>
                <dt class="text-neutral-500">
                  {{ t('payroll.submissions.overview.jmhz_preview_base') }}
                </dt>
                <dd class="mt-0.5 font-medium text-neutral-900">
                  {{ formatCzk(preview.pvpoj.pojistne.zakladZamestnavateleA) }}
                </dd>
              </div>
              <div>
                <dt class="text-neutral-500">
                  {{ t('payroll.submissions.overview.jmhz_preview_payable') }}
                </dt>
                <dd class="mt-0.5 font-medium text-neutral-900">
                  {{ formatCzk(preview.pvpoj.pojistneUhrada) }}
                </dd>
              </div>
              <div>
                <dt class="text-neutral-500">
                  {{ t('payroll.submissions.overview.jmhz_preview_people') }}
                </dt>
                <dd class="mt-0.5 font-medium text-neutral-900">
                  {{ preview.reconciliation.length }}
                </dd>
              </div>
              <div>
                <dt class="text-neutral-500">
                  {{ t('payroll.submissions.overview.jmhz_preview_status') }}
                </dt>
                <dd class="mt-0.5 font-medium text-warning-700">
                  {{ t('payroll.submissions.overview.jmhz_preview_only') }}
                </dd>
              </div>
            </dl>
          </article>
        </div>
      </section>

      <PayrollJmhzOrdinaryEvidencePanel
        v-if="mode === 'jmhz'"
        :runs="jmhzApprovedRuns"
      />

      <PayrollJmhzXmlDryRunPanel
        v-if="mode === 'jmhz'"
        :runs="jmhzApprovedRuns"
      />

      <PayrollJmhzDispatchPanel
        v-if="mode === 'jmhz'"
        :environment="environment"
        :previews="jmhzPreviews"
        :obligations="items"
        @refresh="load"
      />

      <section
        v-if="mode === 'health'"
        class="overflow-hidden rounded-xl border border-neutral-200 bg-surface shadow-sm"
        data-test="health-payment-overviews"
      >
        <div class="border-b border-neutral-200 p-4 sm:p-6">
          <h2 class="text-lg font-semibold text-neutral-900">
            {{ t('payroll.submissions.overview.health_title') }}
          </h2>
          <p class="mt-1 text-sm text-neutral-500">
            {{ t('payroll.submissions.overview.health_description') }}
          </p>
        </div>

        <p
          v-if="healthError"
          class="m-4 rounded-lg border border-danger-500/30 bg-danger-50 p-3 text-sm text-danger-700"
          role="alert"
          data-test="health-overview-error"
        >
          {{ healthError }}
        </p>

        <div v-if="healthOverviews.length === 0 && !healthError" class="p-6 text-sm text-neutral-500">
          {{ t('payroll.submissions.overview.health_empty') }}
        </div>

        <div v-else-if="healthOverviews.length" class="grid grid-cols-1 gap-3 p-4 lg:grid-cols-2">
          <article
            v-for="overview in healthOverviews"
            :key="healthOverviewKey(overview)"
            class="rounded-lg border border-neutral-200 p-4"
          >
            <div class="flex flex-wrap items-start justify-between gap-3">
              <div>
                <h3 class="font-semibold text-neutral-900">
                  {{ t('payroll.submissions.overview.health_insurer', { code: overview.insurer.code }) }}
                </h3>
                <p class="mt-1 text-xs text-neutral-500">
                  {{ overview.period }} ·
                  {{ t('payroll.submissions.overview.health_people', { count: overview.totals.person_count }) }} ·
                  {{ t('payroll.submissions.overview.health_run_revision', {
                    run: overview.run_id,
                    revision: overview.revision_no,
                  }) }}
                </p>
              </div>
              <button
                type="button"
                :class="btnOutlineSm('neutral')"
                :disabled="downloadingHealthKey === healthOverviewKey(overview)"
                @click="downloadHealth(overview)"
              >
                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                  <path :d="ICONS.download" />
                </svg>
                {{ t('common.download') }}
              </button>
            </div>
            <dl class="mt-4 grid grid-cols-2 gap-3 text-xs">
              <div>
                <dt class="text-neutral-500">{{ t('payroll.submissions.overview.health_base') }}</dt>
                <dd class="mt-0.5 font-medium text-neutral-900">
                  {{ formatMinor(overview.totals.assessment_base_minor_units) }}
                </dd>
              </div>
              <div>
                <dt class="text-neutral-500">{{ t('payroll.submissions.overview.health_total') }}</dt>
                <dd class="mt-0.5 font-medium text-neutral-900">
                  {{ formatMinor(overview.totals.total_contribution_minor_units) }}
                </dd>
              </div>
            </dl>
          </article>
        </div>
      </section>
    </template>
  </section>
</template>

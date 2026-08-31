<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { apiErrorMessage } from '@/api/errors'
import {
  payrollApi,
  type PayrollRegzelEnvironment,
  type PayrollSubmissionDetail,
  type PayrollSubmissionInboxItem,
} from '@/api/payroll'
import { useAuthStore } from '@/stores/auth'
import Modal from '@/components/ui/Modal.vue'
import PaginationBar from '@/components/ui/PaginationBar.vue'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import { btnOutline, btnOutlineSm, ICONS } from '@/components/ui/buttonStyles'
// Formátování je sdílené (useFormat) — místní kopie se rozcházely v locale i tvaru.
import { formatDate, formatDateTime } from '@/composables/useFormat'
import ColumnPicker from '@/components/ui/ColumnPicker.vue'
import DensityToggle from '@/components/ui/DensityToggle.vue'
import { useTablePrefs, type ColumnDef } from '@/composables/useTablePrefs'
import { usePayrollLabels } from '@/composables/usePayrollLabels'

const emit = defineEmits<{
  /** `null` = počet se nepodařilo zjistit; rodič pak odznak nevykreslí vůbec. */
  (e: 'update:open-count', count: number | null): void
}>()

const { t } = useI18n()
const {
  artifactKindLabel,
  issueSeverityLabel,
  submissionAgendaLabel,
  submissionChannelLabel,
  submissionIssueMessage,
  submissionIssueRemediation,
  submissionKindLabel,
  submissionStatusLabel,
} = usePayrollLabels()
const auth = useAuthStore()
const canWrite = computed(() => auth.canWrite('payroll.submissions'))
const loading = ref(true)
const error = ref('')
const environment = defineModel<PayrollRegzelEnvironment>('environment', {
  default: 'production',
})
// Vyřešené položky odfiltrovává SERVER (výchozí `status=unresolved`), takže
// `total` popisuje právě ty řádky, které tabulka ukáže. Dokud se filtrovalo
// tady, pager počítal i vyřešené: stránka měla míň řádků, než sliboval, a
// poslední mohla vyjít prázdná.
const items = ref<PayrollSubmissionInboxItem[]>([])
const summary = ref({ total: 0, open: 0, acknowledged: 0, snoozed: 0 })
const acknowledgingId = ref<number | null>(null)
const actionError = ref('')

/*
 * Detail podání ROZBALENÝ inline, ne samostatná stránka — účetní se má na
 * co odkazovat DŘÍV, než položku potvrdí, ne až po odchodu z inboxu a
 * dohledání toho samého jinde (a se ztrátou místa ve stránkovaném seznamu).
 * Rozbaluje se jen tam, kde `submission_id` existuje — bez něj appka nemá
 * co ukázat (problém vznikl dřív, než se cokoliv připravilo), takže se
 * tlačítko vůbec nenabídne. Najednou smí být rozbalená jen jedna položka.
 */
const expandedId = ref<number | null>(null)
const expandedDetail = ref<PayrollSubmissionDetail | null>(null)
const expandedLoading = ref(false)
const expandedError = ref('')
const downloadingArtifactId = ref<number | null>(null)
const artifactDownloadError = ref('')

function readableBytes(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} kB`
  return `${(bytes / 1024 / 1024).toFixed(1)} MB`
}

async function toggleDetail(item: PayrollSubmissionInboxItem) {
  if (item.submission_id === null) return
  if (expandedId.value === item.id) {
    expandedId.value = null
    return
  }
  expandedId.value = item.id
  expandedDetail.value = null
  expandedError.value = ''
  artifactDownloadError.value = ''
  expandedLoading.value = true
  try {
    expandedDetail.value = await payrollApi.submissionDetail(item.submission_id)
  } catch (exception) {
    expandedError.value = apiErrorMessage(
      exception,
      t('payroll.submissions.overview.detail_load_failed'),
    )
  } finally {
    expandedLoading.value = false
  }
}

async function downloadDetailArtifact(artifact: PayrollSubmissionDetail['artifacts'][number]) {
  if (!expandedDetail.value || downloadingArtifactId.value !== null) return
  artifactDownloadError.value = ''
  downloadingArtifactId.value = artifact.id
  try {
    await payrollApi.downloadSubmissionArtifact(expandedDetail.value.submission.id, artifact)
  } catch (exception) {
    artifactDownloadError.value = apiErrorMessage(
      exception,
      t('payroll.submissions.overview.artifact_download_failed'),
    )
  } finally {
    downloadingArtifactId.value = null
  }
}

const COLUMNS: ColumnDef[] = [
  { key: 'agenda', labelKey: 'payroll.submissions.inbox.agenda', required: true },
  { key: 'due_on', labelKey: 'payroll.submissions.inbox.due_on' },
  { key: 'problem', labelKey: 'payroll.submissions.inbox.problem_label' },
  { key: 'status', labelKey: 'payroll.submissions.inbox.status_label' },
  { key: 'actions', labelKey: 'common.actions', required: true },
]
const tbl = useTablePrefs('payroll-submission-inbox', COLUMNS)

const pageSize = 25
const total = ref(0)
const offset = ref(0)
const currentPage = computed(() => Math.floor(offset.value / pageSize) + 1)

function goToPage(nextPage: number) {
  offset.value = Math.max(0, (nextPage - 1) * pageSize)
  void load()
}

const snoozeTarget = ref<PayrollSubmissionInboxItem | null>(null)
const snoozeUntilInput = ref('')
const snoozeReason = ref('')
const snoozeError = ref('')
const snoozing = ref(false)

const environmentOptions = computed(() => [
  { value: 'production' as const, label: t('payroll.regzel.environment.production') },
  { value: 'test' as const, label: t('payroll.regzel.environment.test') },
])

function escalationClass(item: PayrollSubmissionInboxItem): string {
  if (item.escalation_level === 'overdue') return 'bg-danger-50 text-danger-700'
  if (item.escalation_level === 'due_today') return 'bg-warning-50 text-warning-700'
  return 'bg-payroll-50 text-payroll-700'
}

function problemLabel(item: PayrollSubmissionInboxItem): string {
  return t(`payroll.submissions.inbox.problem.${item.problem_kind}`)
}

function statusClass(status: string): string {
  if (status === 'acknowledged') return 'bg-success-50 text-success-700'
  if (status === 'snoozed') return 'bg-neutral-100 text-neutral-700'
  return 'bg-payroll-50 text-payroll-700'
}

function statusLabel(status: string): string {
  return t(`payroll.submissions.inbox.status.${status}`)
}

/**
 * Barva stavu SAMOTNÉHO PODÁNÍ v rozbaleném detailu — jiný slovník než
 * `statusClass()` výš (ten barví stav POLOŽKY INBOXU: open/acknowledged/
 * snoozed/resolved). Podání má vlastní stavy (ready/submitted/accepted/
 * rejected/…), použít na ně stejnou funkci by dalo nesmyslnou barvu.
 */
function submissionStatusClass(status: string): string {
  if (status === 'accepted') return 'bg-success-50 text-success-700'
  if (['rejected', 'partially_accepted', 'waiting_for_identity', 'correction_required'].includes(status)) {
    return 'bg-danger-50 text-danger-700'
  }
  if (['submitted', 'processing'].includes(status)) return 'bg-primary-50 text-primary-700'
  if (status === 'cancelled_in_time') return 'bg-neutral-100 text-neutral-600'
  return 'bg-payroll-50 text-payroll-700'
}

async function load() {
  loading.value = true
  error.value = ''
  actionError.value = ''
  try {
    const response = await payrollApi.submissionInbox(environment.value, {
      limit: pageSize,
      offset: offset.value,
    })
    items.value = response.items
    total.value = response.total
    // Souhrn počítá server nad CELÝM inboxem — dopočítat ho ze stránky by
    // znamenalo hlásit odznakem jen tolik, kolik se zrovna vešlo na obrazovku.
    summary.value = response.summary
    emit('update:open-count', response.summary.total)
  } catch (exception) {
    items.value = []
    total.value = 0
    summary.value = { total: 0, open: 0, acknowledged: 0, snoozed: 0 }
    // Ať rodič odznak schová — zastaralý počet je horší než žádný.
    emit('update:open-count', null)
    error.value = apiErrorMessage(exception, t('payroll.submissions.inbox.load_failed'))
  } finally {
    loading.value = false
  }
}

async function acknowledge(item: PayrollSubmissionInboxItem) {
  if (acknowledgingId.value !== null) return
  actionError.value = ''
  acknowledgingId.value = item.id
  try {
    await payrollApi.acknowledgeSubmissionInboxItem(item.id, item.row_version)
    await load()
  } catch (exception) {
    actionError.value = apiErrorMessage(exception, t('payroll.submissions.inbox.acknowledge_failed'))
  } finally {
    acknowledgingId.value = null
  }
}

function openSnooze(item: PayrollSubmissionInboxItem) {
  snoozeTarget.value = item
  snoozeError.value = ''
  snoozeReason.value = ''
  const inHours = new Date(Date.now() + 24 * 60 * 60 * 1000)
  inHours.setSeconds(0, 0)
  snoozeUntilInput.value = new Date(inHours.getTime() - inHours.getTimezoneOffset() * 60000)
    .toISOString()
    .slice(0, 16)
}

function closeSnooze() {
  if (snoozing.value) return
  snoozeTarget.value = null
}

async function confirmSnooze() {
  if (!snoozeTarget.value) return
  snoozeError.value = ''
  if (!snoozeUntilInput.value) {
    snoozeError.value = t('payroll.submissions.inbox.snooze_until_required')
    return
  }
  if (!snoozeReason.value.trim()) {
    snoozeError.value = t('payroll.submissions.inbox.snooze_reason_required')
    return
  }
  snoozing.value = true
  try {
    await payrollApi.snoozeSubmissionInboxItem(
      snoozeTarget.value.id,
      snoozeTarget.value.row_version,
      new Date(snoozeUntilInput.value).toISOString(),
      snoozeReason.value.trim(),
    )
    snoozeTarget.value = null
    await load()
  } catch (exception) {
    snoozeError.value = apiErrorMessage(exception, t('payroll.submissions.inbox.snooze_failed'))
  } finally {
    snoozing.value = false
  }
}

// Jiné prostředí = jiný obsah seznamu, takže stránka musí zpět na začátek.
watch(environment, () => {
  offset.value = 0
  void load()
})
onMounted(load)

defineExpose({ reload: load })
</script>

<template>
  <section class="space-y-4">
    <div class="rounded-xl border border-neutral-200 bg-surface p-4 shadow-sm sm:p-6">
      <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="max-w-3xl">
          <h2 class="text-lg font-semibold text-neutral-900">
            {{ t('payroll.submissions.inbox.title') }}
          </h2>
          <p class="mt-2 text-sm text-neutral-600">
            {{ t('payroll.submissions.inbox.subtitle') }}
          </p>
        </div>
        <button type="button" :class="btnOutline('neutral')" :disabled="loading" @click="load">
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path :d="ICONS.cycle" />
          </svg>
          {{ t('common.refresh') }}
        </button>
      </div>

      <label class="mt-5 block max-w-xs text-sm font-medium text-neutral-700">
        {{ t('payroll.submissions.overview.environment') }}
        <SearchableSelect
          v-model="environment"
          class="mt-1"
          :options="environmentOptions"
          :clearable="false"
          accent="payroll"
          data-test="inbox-environment"
        />
      </label>
    </div>

    <p
      v-if="error"
      class="rounded-xl border border-danger-500/30 bg-danger-50 p-4 text-sm text-danger-700"
      role="alert"
      data-test="inbox-error"
    >
      {{ error }}
    </p>
    <p
      v-if="actionError"
      class="rounded-xl border border-danger-500/30 bg-danger-50 p-4 text-sm text-danger-700"
      role="alert"
      data-test="inbox-action-error"
    >
      {{ actionError }}
    </p>

    <div v-if="loading" class="grid grid-cols-2 gap-3 lg:grid-cols-4">
      <div v-for="index in 4" :key="index" class="h-20 animate-pulse rounded-xl bg-neutral-100" />
    </div>

    <template v-else>
      <dl class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <div
          v-for="entry in (['total', 'open', 'acknowledged', 'snoozed'] as const)"
          :key="entry"
          class="rounded-xl border border-neutral-200 bg-surface p-4 shadow-sm"
        >
          <dt class="text-xs font-medium text-neutral-500">
            {{ t(`payroll.submissions.inbox.summary.${entry}`) }}
          </dt>
          <dd class="mt-1 text-2xl font-semibold text-neutral-900">
            {{ summary[entry] }}
          </dd>
        </div>
      </dl>

      <section class="overflow-hidden rounded-xl border border-neutral-200 bg-surface shadow-sm">
        <div v-if="items.length === 0" class="p-6 text-sm text-neutral-500" data-test="inbox-empty">
          {{ t('payroll.submissions.inbox.empty') }}
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
                  <th v-if="tbl.isVisible('agenda')" class="px-4 py-3">{{ t('payroll.submissions.inbox.agenda') }}</th>
                  <th v-if="tbl.isVisible('due_on')" class="px-4 py-3">{{ t('payroll.submissions.inbox.due_on') }}</th>
                  <th v-if="tbl.isVisible('problem')" class="px-4 py-3">{{ t('payroll.submissions.inbox.problem_label') }}</th>
                  <th v-if="tbl.isVisible('status')" class="px-4 py-3">{{ t('payroll.submissions.inbox.status_label') }}</th>
                  <th v-if="tbl.isVisible('actions')" class="px-4 py-3 text-right">{{ t('common.actions') }}</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-neutral-100">
                <tr v-for="item in items" :key="item.id" data-test="inbox-row">
                    <td v-if="tbl.isVisible('agenda')" class="px-4 py-3">
                      <span class="block font-medium text-neutral-900">{{ submissionAgendaLabel(item.agenda_code) }}</span>
                      <span v-if="item.subject_label" class="block text-xs text-neutral-500">{{ item.subject_label }}</span>
                    </td>
                    <td v-if="tbl.isVisible('due_on')" class="px-4 py-3 text-neutral-700">{{ formatDate(item.due_on) }}</td>
                    <td v-if="tbl.isVisible('problem')" class="px-4 py-3">
                      <span
                        class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium"
                        :class="escalationClass(item)"
                        data-test="inbox-problem"
                      >
                        {{ problemLabel(item) }}
                      </span>
                    </td>
                    <td v-if="tbl.isVisible('status')" class="px-4 py-3">
                      <span class="rounded-full px-2.5 py-1 text-xs font-medium" :class="statusClass(item.status)">
                        {{ statusLabel(item.status) }}
                      </span>
                      <span v-if="item.status === 'snoozed' && item.snoozed_until" class="mt-1 block text-xs text-neutral-500">
                        {{ t('payroll.submissions.inbox.snoozed_until_label', { at: formatDateTime(item.snoozed_until) }) }}
                      </span>
                    </td>
                    <td v-if="tbl.isVisible('actions')" class="px-4 py-3">
                      <div class="flex flex-wrap justify-end gap-2">
                        <button
                          v-if="item.submission_id !== null"
                          type="button"
                          :class="btnOutlineSm('neutral')"
                          data-test="inbox-detail-toggle"
                          @click="toggleDetail(item)"
                        >
                          <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path :d="ICONS.doc" />
                          </svg>
                          {{ expandedId === item.id
                            ? t('payroll.submissions.overview.detail_hide')
                            : t('payroll.submissions.overview.detail_action') }}
                        </button>
                        <template v-if="canWrite">
                          <button
                            type="button"
                            :class="btnOutlineSm('success')"
                            :disabled="acknowledgingId !== null || item.status === 'acknowledged'"
                            data-test="inbox-acknowledge"
                            @click="acknowledge(item)"
                          >
                            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                              <path :d="ICONS.checkCircle" />
                            </svg>
                            {{ acknowledgingId === item.id
                              ? t('payroll.submissions.inbox.acknowledging')
                              : t('payroll.submissions.inbox.acknowledge') }}
                          </button>
                          <button
                            type="button"
                            :class="btnOutlineSm('warning')"
                            data-test="inbox-snooze"
                            @click="openSnooze(item)"
                          >
                            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                              <path :d="ICONS.pause" />
                            </svg>
                            {{ t('payroll.submissions.inbox.snooze') }}
                          </button>
                        </template>
                        <span v-if="!canWrite && item.submission_id === null" class="text-xs text-neutral-400">—</span>
                      </div>
                    </td>
                </tr>
              </tbody>
            </table>
          </div>
        </template>

        <div v-if="items.length" class="grid grid-cols-1 gap-3 p-4 md:hidden">
          <article v-for="item in items" :key="item.id" class="rounded-lg border border-neutral-200 p-4" data-test="inbox-card">
            <div class="flex flex-wrap items-start justify-between gap-2">
              <div>
                <h3 class="font-semibold text-neutral-900">{{ submissionAgendaLabel(item.agenda_code) }}</h3>
                <p v-if="item.subject_label" class="mt-1 text-xs text-neutral-500">{{ item.subject_label }}</p>
              </div>
              <span class="rounded-full px-2.5 py-1 text-xs font-medium" :class="statusClass(item.status)">
                {{ statusLabel(item.status) }}
              </span>
            </div>
            <dl class="mt-3 grid grid-cols-2 gap-3 text-xs">
              <div>
                <dt class="text-neutral-500">{{ t('payroll.submissions.inbox.due_on') }}</dt>
                <dd class="mt-0.5 text-neutral-800">{{ formatDate(item.due_on) }}</dd>
              </div>
              <div>
                <dt class="text-neutral-500">{{ t('payroll.submissions.inbox.problem_label') }}</dt>
                <dd class="mt-1">
                  <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium" :class="escalationClass(item)">
                    {{ problemLabel(item) }}
                  </span>
                </dd>
              </div>
            </dl>
            <p v-if="item.status === 'snoozed' && item.snoozed_until" class="mt-2 text-xs text-neutral-500">
              {{ t('payroll.submissions.inbox.snoozed_until_label', { at: formatDateTime(item.snoozed_until) }) }}
            </p>
            <button
              v-if="item.submission_id !== null"
              type="button"
              class="cursor-pointer mt-4"
              :class="btnOutline('neutral')"
              @click="toggleDetail(item)"
            >
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path :d="ICONS.doc" />
              </svg>
              {{ expandedId === item.id
                ? t('payroll.submissions.overview.detail_hide')
                : t('payroll.submissions.overview.detail_action') }}
            </button>
            <div v-if="canWrite" class="mt-4 flex flex-wrap gap-2">
              <button
                type="button"
                class="cursor-pointer flex-1"
                :class="btnOutline('success')"
                :disabled="acknowledgingId !== null || item.status === 'acknowledged'"
                @click="acknowledge(item)"
              >
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                  <path :d="ICONS.checkCircle" />
                </svg>
                {{ acknowledgingId === item.id
                  ? t('payroll.submissions.inbox.acknowledging')
                  : t('payroll.submissions.inbox.acknowledge') }}
              </button>
              <button type="button" class="cursor-pointer flex-1" :class="btnOutline('warning')" @click="openSnooze(item)">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                  <path :d="ICONS.pause" />
                </svg>
                {{ t('payroll.submissions.inbox.snooze') }}
              </button>
            </div>
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
        v-if="expandedError"
        class="rounded-xl border border-danger-500/30 bg-danger-50 p-4 text-sm text-danger-700"
        role="alert"
        data-test="inbox-detail-error"
      >
        {{ expandedError }}
      </p>

      <section
        v-if="expandedId !== null"
        class="overflow-hidden rounded-xl border border-neutral-200 bg-surface shadow-sm"
        data-test="inbox-detail"
      >
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-neutral-200 p-4 sm:p-6">
          <p v-if="expandedLoading" class="text-sm text-neutral-500">{{ t('common.loading') }}</p>
          <div v-else-if="expandedDetail" class="flex flex-wrap items-center gap-2 text-sm">
            <span class="font-medium text-neutral-900">{{ submissionKindLabel(expandedDetail.submission.submission_kind) }}</span>
            <span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="submissionStatusClass(expandedDetail.submission.status)">
              {{ submissionStatusLabel(expandedDetail.submission.status) }}
            </span>
            <span class="text-xs text-neutral-500">{{ submissionChannelLabel(expandedDetail.submission.channel) }}</span>
            <span class="text-xs text-neutral-500">{{ expandedDetail.submission.created_at }}</span>
          </div>
          <button type="button" :class="btnOutline('neutral')" @click="expandedId = null">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
              <path :d="ICONS.x" />
            </svg>
            {{ t('common.close') }}
          </button>
        </div>

        <div v-if="expandedDetail" class="grid grid-cols-1 gap-4 p-4 lg:grid-cols-2 sm:p-6">
          <article class="rounded-lg border border-neutral-200 p-4">
            <h3 class="font-semibold text-neutral-900">
              {{ t('payroll.submissions.overview.detail_artifacts', { count: expandedDetail.artifacts.length }) }}
            </h3>
            <p
              v-if="artifactDownloadError"
              class="mt-3 rounded-lg border border-danger-500/30 bg-danger-50 p-3 text-sm text-danger-700"
              role="alert"
              data-test="inbox-artifact-download-error"
            >
              {{ artifactDownloadError }}
            </p>
            <p v-if="expandedDetail.artifacts.length === 0" class="mt-3 text-sm text-neutral-500">
              {{ t('payroll.submissions.overview.detail_none') }}
            </p>
            <ul v-else class="mt-3 divide-y divide-neutral-100">
              <li v-for="artifact in expandedDetail.artifacts" :key="artifact.id" class="py-3 first:pt-0 last:pb-0">
                <div class="flex flex-wrap items-center justify-between gap-2">
                  <div>
                    <span class="font-medium text-neutral-900">{{ artifactKindLabel(artifact.artifact_kind) }}</span>
                    <span class="ml-2 text-xs text-neutral-500">{{ readableBytes(artifact.byte_size) }}</span>
                  </div>
                  <button
                    type="button"
                    :class="btnOutlineSm('neutral')"
                    :disabled="downloadingArtifactId !== null"
                    data-test="inbox-artifact-download"
                    @click="downloadDetailArtifact(artifact)"
                  >
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                      <path :d="ICONS.download" />
                    </svg>
                    {{ t('common.download') }}
                  </button>
                </div>
              </li>
            </ul>
          </article>

          <article class="rounded-lg border border-neutral-200 p-4">
            <h3 class="font-semibold text-neutral-900">
              {{ t('payroll.submissions.overview.detail_issues', { count: expandedDetail.issues.length }) }}
            </h3>
            <p v-if="expandedDetail.issues.length === 0" class="mt-3 text-sm text-neutral-500">
              {{ t('payroll.submissions.overview.detail_none') }}
            </p>
            <ul v-else class="mt-3 divide-y divide-neutral-100">
              <li v-for="issue in expandedDetail.issues" :key="issue.id" class="py-3 first:pt-0 last:pb-0">
                <div class="flex flex-wrap items-center justify-between gap-2">
                  <span class="font-medium text-neutral-900" data-test="inbox-issue-message">
                    {{ submissionIssueMessage(issue.issue_code) }}
                  </span>
                  <span
                    class="rounded-full px-2 py-0.5 text-xs font-medium"
                    :class="issue.is_resolved ? 'bg-success-50 text-success-700' : 'bg-warning-50 text-warning-700'"
                  >
                    {{ issue.is_resolved
                      ? t('payroll.submissions.overview.detail_resolved')
                      : issueSeverityLabel(issue.severity) }}
                  </span>
                </div>
                <p class="mt-2 text-xs text-neutral-600">{{ submissionIssueRemediation(issue.validation_stage) }}</p>
              </li>
            </ul>
          </article>
        </div>
      </section>
    </template>

    <Modal
      v-if="snoozeTarget"
      :title="t('payroll.submissions.inbox.snooze_modal_title')"
      width-class="max-w-md"
      @close="closeSnooze"
    >
      <div class="space-y-4">
        <p class="text-sm text-neutral-600">
          {{ submissionAgendaLabel(snoozeTarget.agenda_code) }}
          <template v-if="snoozeTarget.subject_label"> · {{ snoozeTarget.subject_label }}</template>
        </p>
        <p v-if="snoozeError" class="rounded-lg border border-danger-500/30 bg-danger-50 p-3 text-sm text-danger-700" role="alert">
          {{ snoozeError }}
        </p>
        <label class="block text-sm font-medium text-neutral-700">
          {{ t('payroll.submissions.inbox.snooze_until') }}
          <input
            v-model="snoozeUntilInput"
            type="datetime-local"
            data-test="snooze-until-input"
            class="mt-1 h-10 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm focus:border-payroll-500 focus:outline-none focus:ring-2 focus:ring-payroll-500/20"
          >
        </label>
        <label class="block text-sm font-medium text-neutral-700">
          {{ t('payroll.submissions.inbox.snooze_reason') }}
          <textarea
            v-model="snoozeReason"
            data-test="snooze-reason-input"
            rows="3"
            :placeholder="t('payroll.submissions.inbox.snooze_reason_placeholder')"
            class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm focus:border-payroll-500 focus:outline-none focus:ring-2 focus:ring-payroll-500/20"
          />
        </label>
        <div class="flex justify-end gap-2 pt-1">
          <button type="button" :class="btnOutline('neutral')" :disabled="snoozing" @click="closeSnooze">
            {{ t('common.cancel') }}
          </button>
          <button type="button" :class="btnOutline('warning')" :disabled="snoozing" data-test="snooze-confirm" @click="confirmSnooze">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
              <path :d="ICONS.pause" />
            </svg>
            {{ snoozing ? t('payroll.submissions.inbox.snoozing') : t('payroll.submissions.inbox.snooze_confirm') }}
          </button>
        </div>
      </div>
    </Modal>
  </section>
</template>

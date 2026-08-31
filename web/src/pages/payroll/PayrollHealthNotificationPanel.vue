<script setup lang="ts">
/**
 * MZ-23 — podání zdravotním pojišťovnám.
 *
 * Obrazovka stojí na jednom rozhodnutí: **co modul neumí, se říká nahoře, ne
 * až v chybové hlášce.** Portálové API bez doložené obálky nevolá; u
 * pojišťoven s doloženým formátem přílohy ale umí předat PPZ do obecné ISDS
 * fronty, kde odeslání vždy potvrzuje uživatel. HOZ umí za období a
 * pojišťovnu sestavit i stáhnout XML ověřené proti připnutému XSD, ale do
 * ISDS fronty ho nezařazuje — odeslání zůstává ruční (viz
 * `HealthInsuranceSubmissionService`, bod 5 docblocku třídy).
 *
 * Filtry i stránkování jsou serverové. Půl na půl (filtr u sebe, stránka na
 * serveru) by znamenalo, že počet nahoře popisuje jiný seznam než tabulka.
 */
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { apiErrorMessage } from '@/api/errors'
import { dataBoxApi, type GatewayStart } from '@/api/dataBox'
import {
  payrollHealthNotificationApi,
  type HealthCapability,
  type HealthDutyItem,
  type HealthDutyKind,
  type HealthDutySummary,
  type HealthPreparedOverview,
  type HealthPreparedBulkNotification,
  type HealthIsdsEnqueueResult,
  type HealthUnresolvedEmployment,
} from '@/api/payrollHealthNotifications'
import {
  payrollApi,
  type PayrollRun,
  type PayrollSubmissionDetail,
} from '@/api/payroll'
import { useAuthStore } from '@/stores/auth'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import ColumnPicker from '@/components/ui/ColumnPicker.vue'
import DensityToggle from '@/components/ui/DensityToggle.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import MobileKeySendButton from '@/components/submission/MobileKeySendButton.vue'
import PaginationBar from '@/components/ui/PaginationBar.vue'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import { btnFilledSm, btnOutlineSm, ICONS } from '@/components/ui/buttonStyles'
import { useTablePrefs, type ColumnDef } from '@/composables/useTablePrefs'
import { formatDate } from '@/composables/useFormat'
import { localPayrollPeriod } from './payrollComponentsUi'
import { appIsoDate } from '@/utils/date'
import { usePayrollLabels } from '@/composables/usePayrollLabels'

const DUTY_KINDS: HealthDutyKind[] = [
  'employment_start',
  'employment_end',
  'employee_data_change',
  'insurer_change',
  'maternity_leave_start',
  'parental_leave_start',
  'maternity_or_parental_leave_end',
  'state_category_other',
]

const COLUMNS: ColumnDef[] = [
  { key: 'employee', labelKey: 'payroll.health_notifications.table.employee', required: true },
  { key: 'duty', labelKey: 'payroll.health_notifications.table.duty', required: true },
  { key: 'insurer', labelKey: 'payroll.health_notifications.table.insurer' },
  { key: 'occurred_on', labelKey: 'payroll.health_notifications.table.occurred_on' },
  { key: 'deadline', labelKey: 'payroll.health_notifications.table.deadline', required: true },
  { key: 'change_code', labelKey: 'payroll.health_notifications.table.change_code' },
  { key: 'source', labelKey: 'payroll.health_notifications.table.source', defaultHidden: true },
  { key: 'state', labelKey: 'payroll.health_notifications.table.state', required: true },
]

const PAGE_SIZE = 50

const { t } = useI18n()
const { submissionStatusLabel } = usePayrollLabels()
const auth = useAuthStore()
const tbl = useTablePrefs('payroll-health-notifications', COLUMNS)

const canWrite = computed(() => auth.canWrite('payroll.submissions'))

const period = ref(localPayrollPeriod())
const filterInsurer = ref<string | null>(null)
const filterKind = ref<HealthDutyKind | null>(null)
const filterReported = ref<'all' | 'employer' | 'insured'>('all')
const filterUndocumented = ref(false)

const loading = ref(true)
/*
 * `loadFailed` je samostatný stav vedle `items`. V `catch` se kolekce
 * NEVYNULUJE — prázdná tabulka po výpadku by tvrdila „nic se nehlásí", a to
 * je u osmidenní lhůty nejdražší možná lež.
 */
const loadFailed = ref(false)
const error = ref('')
const items = ref<HealthDutyItem[]>([])
const total = ref(0)
const offset = ref(0)
const summary = ref<HealthDutySummary | null>(null)
const unresolved = ref<HealthUnresolvedEmployment[]>([])

const capability = ref<HealthCapability | null>(null)
const capabilityFailed = ref(false)

const runs = ref<PayrollRun[]>([])
const prepareRevisionId = ref<number | null>(null)
const prepareInsurer = ref<string | null>(null)
const preparing = ref(false)
const prepared = ref<HealthPreparedOverview | null>(null)
const prepareError = ref('')
const downloading = ref(false)
const downloadError = ref('')
const isdsBusy = ref(false)
const isdsError = ref('')
const isdsResult = ref<HealthIsdsEnqueueResult | null>(null)
const isdsGateway = ref<GatewayStart | null>(null)
const isdsMobileKeySent = ref(false)
const syncingObligations = ref(false)
const obligationSyncError = ref('')
const synchronizedObligationCount = ref<number | null>(null)

const prepareBulkInsurer = ref<string | null>(null)
const preparingBulk = ref(false)
const preparedBulk = ref<HealthPreparedBulkNotification | null>(null)
const prepareBulkError = ref('')
const downloadingBulk = ref(false)
const downloadBulkError = ref('')
const isdsBulkBusy = ref(false)
const isdsBulkError = ref('')
const isdsBulkResult = ref<HealthIsdsEnqueueResult | null>(null)
const isdsBulkGateway = ref<GatewayStart | null>(null)
const isdsBulkMobileKeySent = ref(false)

const currentPage = computed(() => Math.floor(offset.value / PAGE_SIZE) + 1)

const insurerOptions = computed(() =>
  Object.values(capability.value?.channels ?? {}).map(channel => ({
    value: channel.insurer_code,
    label: `${channel.insurer_code} — ${channel.insurer_name ?? channel.insurer_code}`,
  })),
)

const kindOptions = computed(() => DUTY_KINDS.map(kind => ({
  value: kind,
  label: t(`payroll.health_notifications.kind.${kind}`),
})))

const reportedOptions = computed(() => ([
  { value: 'all' as const, label: t('payroll.health_notifications.filter.reported_all') },
  { value: 'employer' as const, label: t('payroll.health_notifications.filter.reported_employer') },
  { value: 'insured' as const, label: t('payroll.health_notifications.filter.reported_insured') },
]))

const approvedRunOptions = computed(() =>
  runs.value
    .filter(run => run.revision_status === 'approved' && run.revision_id !== null)
    .map(run => ({
      value: run.revision_id as number,
      label: t('payroll.health_notifications.prepare.run_option', {
        period: run.period_start.slice(0, 7),
        revision: run.revision_no,
      }),
    })),
)

/** Druhy povinnosti, ke kterým schéma kód změny NEURČUJE. */
const undocumentedKinds = computed(() => {
  const documented = capability.value?.change_codes.mapping_from_duty_documented ?? []
  if (!capability.value) return []
  return DUTY_KINDS.filter(kind => !documented.includes(kind))
})

const filtersActive = computed(() =>
  filterInsurer.value !== null
  || filterKind.value !== null
  || filterReported.value !== 'all'
  || filterUndocumented.value,
)

const canPrepare = computed(() =>
  canWrite.value
  && prepareRevisionId.value !== null
  && prepareInsurer.value !== null
  && !preparing.value,
)

const canPrepareBulk = computed(() =>
  canWrite.value
  && prepareBulkInsurer.value !== null
  && !preparingBulk.value,
)

const preparedChannel = computed(() => {
  const insurerCode = prepared.value?.insurer_code
  return insurerCode
    ? capability.value?.channels[insurerCode] ?? null
    : null
})

const canQueueIsds = computed(() => Boolean(
  canWrite.value
  && prepared.value?.schema_validated
  && prepared.value.status === 'ready'
  && preparedChannel.value
  && preparedChannel.value.isds_attachment_format !== 'none'
  && preparedChannel.value.data_box_id
  && !isdsBusy.value
  && isdsResult.value === null
))

const preparedBulkChannel = computed(() => {
  const insurerCode = preparedBulk.value?.insurer_code
  return insurerCode
    ? capability.value?.channels[insurerCode] ?? null
    : null
})

/**
 * Stejná podmínka jako {@link canQueueIsds} u PPZ: bez doloženého formátu
 * přílohy a ID schránky by tlačítko slibovalo odeslání, které nemá kam jít.
 */
const canQueueBulkIsds = computed(() => Boolean(
  canWrite.value
  && preparedBulk.value?.schema_validated
  && preparedBulk.value.status === 'ready'
  && preparedBulkChannel.value
  && preparedBulkChannel.value.isds_attachment_format !== 'none'
  && preparedBulkChannel.value.data_box_id
  && !isdsBulkBusy.value
  && isdsBulkResult.value === null
))

function deadlineClass(item: HealthDutyItem): string {
  if (!item.reported_by_employer) return 'bg-neutral-100 text-neutral-600'
  const dueOn = item.deadline?.due_on
  if (!dueOn) return 'bg-neutral-100 text-neutral-600'
  const today = appIsoDate()
  if (dueOn < today) return 'bg-danger-50 text-danger-700'
  return 'bg-payroll-50 text-payroll-700'
}

async function load() {
  loading.value = true
  error.value = ''
  try {
    const page = await payrollHealthNotificationApi.duties(period.value, {
      insurer_code: filterInsurer.value,
      kind: filterKind.value,
      reported: filterReported.value === 'all'
        ? null
        : filterReported.value === 'employer',
      undocumented_code_only: filterUndocumented.value,
      limit: PAGE_SIZE,
      offset: offset.value,
    })
    items.value = page.items
    total.value = page.total
    summary.value = page.summary
    unresolved.value = page.unresolved_employments
    loadFailed.value = false
  } catch (exception) {
    loadFailed.value = true
    error.value = apiErrorMessage(
      exception,
      t('payroll.health_notifications.load_failed'),
    )
  } finally {
    loading.value = false
  }
}

async function loadCapability() {
  try {
    capability.value = await payrollHealthNotificationApi.capability()
    capabilityFailed.value = false
  } catch {
    // Přiznání „tohle modul neumí" se nesmí ztratit jen proto, že se
    // nenačetlo. Bez kapability se panel omezení NESCHOVÁ, jen řekne,
    // že je nedokázal načíst.
    capabilityFailed.value = true
  }
}

async function loadRuns() {
  try {
    runs.value = await payrollApi.runs(period.value)
  } catch {
    runs.value = []
  }
}

function goToPage(nextPage: number) {
  offset.value = Math.max(0, (nextPage - 1) * PAGE_SIZE)
  void load()
}

function resetFilters() {
  filterInsurer.value = null
  filterKind.value = null
  filterReported.value = 'all'
  filterUndocumented.value = false
}

async function prepare() {
  if (prepareRevisionId.value === null || prepareInsurer.value === null) return
  prepareError.value = ''
  downloadError.value = ''
  isdsError.value = ''
  isdsResult.value = null
  isdsGateway.value = null
  prepared.value = null
  preparing.value = true
  try {
    prepared.value = await payrollHealthNotificationApi.preparePaymentOverview(
      prepareRevisionId.value,
      prepareInsurer.value,
    )
  } catch (exception) {
    // Konkrétní věta ze serveru, ne „nepodařilo se". Doména jich vydává celou
    // řadu (haléře v pojistném, chybějící IČO, neznámý kód pojišťovny) a
    // každá vede k jinému kroku.
    prepareError.value = apiErrorMessage(
      exception,
      t('payroll.health_notifications.prepare.failed'),
    )
  } finally {
    preparing.value = false
  }
}

async function prepareBulk() {
  if (prepareBulkInsurer.value === null) return
  prepareBulkError.value = ''
  downloadBulkError.value = ''
  isdsBulkError.value = ''
  isdsBulkResult.value = null
  isdsBulkGateway.value = null
  isdsBulkMobileKeySent.value = false
  preparedBulk.value = null
  preparingBulk.value = true
  try {
    preparedBulk.value = await payrollHealthNotificationApi.prepareBulkNotification(
      period.value,
      prepareBulkInsurer.value,
    )
  } catch (exception) {
    prepareBulkError.value = apiErrorMessage(
      exception,
      t('payroll.health_notifications.prepare_bulk.failed'),
    )
  } finally {
    preparingBulk.value = false
  }
}

/**
 * Sestavuje se vždy nanovo ze zdroje, stejně jako `bulkNotificationDownload`
 * na backendu — funguje tedy i bez předchozího `prepareBulk()`, ale UI ho
 * nabízí až u výsledku, aby účetní vždy viděla, kolik vět a s jakým stavem
 * stahuje.
 */
async function downloadBulk() {
  const result = preparedBulk.value
  if (!result) return
  downloadBulkError.value = ''
  downloadingBulk.value = true
  try {
    await payrollHealthNotificationApi.downloadBulkNotification(
      result.period,
      result.insurer_code,
    )
  } catch (exception) {
    downloadBulkError.value = apiErrorMessage(
      exception,
      t('payroll.health_notifications.prepare_bulk.download_failed'),
    )
  } finally {
    downloadingBulk.value = false
  }
}

async function enqueueIsds() {
  const result = prepared.value
  if (!result || !canQueueIsds.value) return
  isdsError.value = ''
  isdsResult.value = null
  isdsGateway.value = null
  isdsBusy.value = true
  try {
    const queued = await payrollHealthNotificationApi.enqueuePaymentOverviewIsds(
      result.submission_id,
      result.insurer_code,
    )
    isdsResult.value = queued
    if (queued.transport.automatic) {
      try {
        isdsGateway.value = await dataBoxApi.gatewayStartPayroll(queued.outbox_id)
      } catch (exception) {
        isdsError.value = apiErrorMessage(
          exception,
          t('payroll.health_notifications.prepare.gateway_failed'),
        )
      }
    }
  } catch (exception) {
    isdsError.value = apiErrorMessage(
      exception,
      t('payroll.health_notifications.prepare.isds_failed'),
    )
  } finally {
    isdsBusy.value = false
  }
}

async function enqueueBulkIsds() {
  const result = preparedBulk.value
  if (!result || !canQueueBulkIsds.value) return
  isdsBulkError.value = ''
  isdsBulkResult.value = null
  isdsBulkGateway.value = null
  isdsBulkMobileKeySent.value = false
  isdsBulkBusy.value = true
  try {
    const queued = await payrollHealthNotificationApi.enqueueBulkNotificationIsds(
      result.submission_id,
      result.insurer_code,
    )
    isdsBulkResult.value = queued
    if (queued.transport.automatic) {
      try {
        isdsBulkGateway.value = await dataBoxApi.gatewayStartPayroll(queued.outbox_id)
      } catch (exception) {
        isdsBulkError.value = apiErrorMessage(
          exception,
          t('payroll.health_notifications.prepare.gateway_failed'),
        )
      }
    }
  } catch (exception) {
    isdsBulkError.value = apiErrorMessage(
      exception,
      t('payroll.health_notifications.prepare.isds_failed'),
    )
  } finally {
    isdsBulkBusy.value = false
  }
}

function continueIsdsBulkGateway() {
  if (isdsBulkGateway.value) {
    window.location.assign(isdsBulkGateway.value.redirect_url)
  }
}

function bulkMobileKeySent() {
  isdsBulkMobileKeySent.value = true
}

function mobileKeySent() {
  isdsMobileKeySent.value = true
}

async function synchronizeObligations() {
  if (!canWrite.value || syncingObligations.value) return
  syncingObligations.value = true
  obligationSyncError.value = ''
  synchronizedObligationCount.value = null
  try {
    const result = await payrollHealthNotificationApi
      .registerPeriodObligations(period.value)
    synchronizedObligationCount.value = result.total
    await load()
  } catch (exception) {
    obligationSyncError.value = apiErrorMessage(
      exception,
      t('payroll.health_notifications.hoz_sync.failed'),
    )
  } finally {
    syncingObligations.value = false
  }
}

function continueIsdsGateway() {
  if (isdsGateway.value) {
    window.location.assign(isdsGateway.value.redirect_url)
  }
}

/**
 * Stažení podkladu se NESCHOVÁVÁ za `schema_validated`. XML i PDF vznikají,
 * když podání zůstalo v `draft` s blokující výhradou — a právě tam ho účetní
 * potřebuje vidět, aby poznala, co se vyrobilo a proč to neprošlo.
 */
async function downloadArtifact() {
  // Výsledek se čte JEDNOU do lokální proměnné. Změna období `prepared`
  // vynuluje, a kdyby se ref četl znovu až po `await`, spadlo by stahování
  // na null uprostřed běhu.
  const result = prepared.value
  if (!result) return
  const format = preparedChannel.value?.isds_attachment_format ?? 'xml'
  const artifactId = format === 'text_pdf'
    ? result.pdf_artifact_id
    : result.artifact_id
  const mimeType = format === 'text_pdf'
    ? 'application/pdf'
    : 'application/xml'
  downloadError.value = ''
  downloading.value = true
  try {
    const detail: PayrollSubmissionDetail = await payrollApi.submissionDetail(
      result.submission_id,
    )
    const artifact = (artifactId !== undefined
      ? detail.artifacts.find(candidate => candidate.id === artifactId)
      : undefined)
      ?? detail.artifacts.find(
        candidate => candidate.mime_type === mimeType,
      )
    if (!artifact) {
      downloadError.value = t('payroll.health_notifications.prepare.artifact_missing')
      return
    }
    await payrollApi.downloadSubmissionArtifact(
      result.submission_id,
      artifact,
    )
  } catch (exception) {
    downloadError.value = apiErrorMessage(
      exception,
      t('payroll.health_notifications.prepare.download_failed'),
    )
  } finally {
    downloading.value = false
  }
}

const actions = computed<ActionItem[]>(() => [
  {
    key: 'prepare',
    label: t('payroll.health_notifications.prepare.action'),
    icon: 'doc',
    tier: 'primary',
    variant: 'primary',
    disabled: !canPrepare.value,
    disabledReason: canWrite.value
      ? t('payroll.health_notifications.prepare.pick_first')
      : t('payroll.health_notifications.read_only'),
    loading: preparing.value,
    run: () => void prepare(),
  },
  {
    key: 'sync-obligations',
    label: t('payroll.health_notifications.hoz_sync.action'),
    icon: 'cycle',
    tier: 'secondary',
    variant: 'neutral',
    disabled: !canWrite.value || loading.value || syncingObligations.value,
    disabledReason: canWrite.value
      ? t('payroll.health_notifications.hoz_sync.wait_for_load')
      : t('payroll.health_notifications.read_only'),
    loading: syncingObligations.value,
    run: () => void synchronizeObligations(),
  },
  {
    key: 'reload',
    label: t('common.refresh'),
    icon: 'cycle',
    tier: 'secondary',
    variant: 'neutral',
    disabled: loading.value,
    run: () => void load(),
  },
  {
    key: 'clear-filters',
    label: t('payroll.health_notifications.filter.reset'),
    icon: 'x',
    tier: 'overflow',
    variant: 'neutral',
    show: filtersActive.value,
    run: resetFilters,
  },
])

// Změna období nebo filtru mění obsah seznamu, takže stránka musí zpět na
// začátek — jinak by se na straně 3 zobrazilo prázdno u seznamu o dvou
// stránkách a vypadalo by to jako „nic k podání".
watch([period, filterInsurer, filterKind, filterReported, filterUndocumented], () => {
  offset.value = 0
  void load()
})
watch(period, () => {
  prepared.value = null
  isdsResult.value = null
  isdsError.value = ''
  isdsGateway.value = null
  prepareRevisionId.value = null
  synchronizedObligationCount.value = null
  obligationSyncError.value = ''
  preparedBulk.value = null
  prepareBulkError.value = ''
  downloadBulkError.value = ''
  void loadRuns()
})

onMounted(() => {
  void load()
  void loadCapability()
  void loadRuns()
})
</script>

<template>
  <section class="space-y-4" data-test="health-notifications">
    <div class="rounded-xl border border-neutral-200 bg-surface p-4 shadow-sm sm:p-6">
      <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="max-w-3xl">
          <h2 class="text-lg font-semibold text-neutral-900">
            {{ t('payroll.health_notifications.title') }}
          </h2>
          <p class="mt-2 text-sm text-neutral-600">
            {{ t('payroll.health_notifications.subtitle') }}
          </p>
        </div>
        <ActionBar :actions="actions" />
      </div>
    </div>

    <section
      class="rounded-xl border border-neutral-200 bg-surface p-4 shadow-sm sm:p-6"
      data-test="health-hoz-sync"
    >
      <h3 class="text-sm font-semibold text-neutral-900">
        {{ t('payroll.health_notifications.hoz_sync.title') }}
      </h3>
      <p class="mt-1 max-w-3xl text-sm text-neutral-600">
        {{ t('payroll.health_notifications.hoz_sync.description') }}
      </p>
      <p
        v-if="synchronizedObligationCount !== null"
        class="mt-3 rounded-lg border border-success-500/30 bg-success-50 p-3 text-sm text-success-800"
        data-test="health-hoz-sync-result"
      >
        {{ t('payroll.health_notifications.hoz_sync.done', {
          count: synchronizedObligationCount,
        }) }}
      </p>
      <p
        v-if="obligationSyncError"
        class="mt-3 rounded-lg border border-danger-500/30 bg-danger-50 p-3 text-sm text-danger-700"
        role="alert"
        data-test="health-hoz-sync-error"
      >
        {{ obligationSyncError }}
      </p>
    </section>

    <!--
      Omezení stojí NAD seznamem, ne pod ním: uživatel musí vědět, že modul
      neodesílá, dřív než začne skládat podání.
    -->
    <section
      class="rounded-xl border border-warning-500/40 bg-warning-50 p-4 sm:p-6"
      data-test="health-notifications-limits"
    >
      <h3 class="flex items-center gap-2 text-sm font-semibold text-warning-800">
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
          <path :d="ICONS.bell" />
        </svg>
        {{ t('payroll.health_notifications.limits.title') }}
      </h3>
      <ul class="mt-3 space-y-2 text-sm text-warning-800">
        <li class="flex gap-2">
          <span aria-hidden="true">•</span>
          <span>{{ t('payroll.health_notifications.limits.no_transport') }}</span>
        </li>
        <li class="flex gap-2">
          <span aria-hidden="true">•</span>
          <span>{{ t('payroll.health_notifications.limits.manual_delivery') }}</span>
        </li>
        <li v-if="undocumentedKinds.length" class="flex gap-2">
          <span aria-hidden="true">•</span>
          <span>
            {{ t('payroll.health_notifications.limits.undocumented_codes', {
              kinds: undocumentedKinds
                .map(kind => t(`payroll.health_notifications.kind.${kind}`))
                .join(', '),
            }) }}
          </span>
        </li>
        <li v-if="capabilityFailed" class="flex gap-2" data-test="health-capability-failed">
          <span aria-hidden="true">•</span>
          <span>{{ t('payroll.health_notifications.limits.capability_failed') }}</span>
        </li>
      </ul>

      <div v-if="capability" class="mt-4 grid grid-cols-1 gap-2 md:grid-cols-2">
        <p
          v-for="channel in Object.values(capability.channels)"
          :key="channel.insurer_code"
          class="rounded-lg border border-warning-500/30 bg-surface/60 p-3 text-xs text-neutral-700"
        >
          <span class="font-semibold text-neutral-900">
            {{ channel.insurer_code }} — {{ channel.insurer_name }}
          </span>
          <span
            class="mt-1 inline-flex rounded-full px-2 py-0.5 text-[11px] font-semibold"
            :class="channel.isds_attachment_format !== 'none'
              ? 'bg-success-100 text-success-800'
              : 'bg-neutral-100 text-neutral-700'"
          >
            {{ t(channel.isds_attachment_format === 'xml'
              ? 'payroll.health_notifications.isds_xml_supported'
              : channel.isds_attachment_format === 'text_pdf'
                ? 'payroll.health_notifications.isds_pdf_supported'
                : 'payroll.health_notifications.alternative_route') }}
          </span>
          <span class="mt-1 block text-neutral-500">
            {{ channel.note }}
          </span>
          <span
            v-if="channel.data_box_id"
            class="mt-1 block"
          >
            {{ t('payroll.health_notifications.data_box', { id: channel.data_box_id }) }}
          </span>
          <a
            v-if="channel.portal_url"
            :href="channel.portal_url"
            target="_blank"
            rel="noopener noreferrer"
            class="mt-1 block text-payroll-600 underline"
          >{{ channel.portal_url }}</a>
        </p>
      </div>
    </section>

    <!--
      Dlaždice popisují CELÉ období, ne filtrovanou tabulku pod nimi. Je to
      záměr: kdyby souhrn respektoval filtr, dalo by se zúžením filtru schovat
      propadlý termín. Aby to ale nesvádělo k záměně, říká to popisek nahlas —
      a při zapnutém filtru se to zopakuje ještě jednou pod dlaždicemi.
    -->
    <dl v-if="summary" class="grid grid-cols-2 gap-3 lg:grid-cols-4" data-test="health-notifications-summary">
      <div
        v-for="entry in (['total', 'reported_by_employer', 'code_undocumented', 'overdue'] as const)"
        :key="entry"
        class="rounded-xl border border-neutral-200 bg-surface p-4 shadow-sm"
      >
        <dt class="text-xs font-medium text-neutral-500">
          {{ t(`payroll.health_notifications.summary.${entry}`) }}
        </dt>
        <dd
          class="mt-1 text-2xl font-semibold"
          :class="entry === 'overdue' && summary[entry] > 0
            ? 'text-danger-600'
            : 'text-neutral-900'"
        >
          {{ summary[entry] }}
        </dd>
      </div>
    </dl>

    <p
      v-if="summary && filtersActive"
      class="-mt-2 text-xs text-neutral-500"
      data-test="health-notifications-summary-scope"
    >
      {{ t('payroll.health_notifications.summary.scope_note') }}
    </p>

    <section
      v-if="unresolved.length"
      class="rounded-xl border border-danger-500/30 bg-danger-50 p-4 sm:p-6"
      data-test="health-notifications-unresolved"
    >
      <h3 class="text-sm font-semibold text-danger-800">
        {{ t('payroll.health_notifications.unresolved.title', { count: unresolved.length }) }}
      </h3>
      <ul class="mt-3 space-y-1 text-sm text-danger-700">
        <li v-for="row in unresolved" :key="row.employment_id">
          <span class="font-medium">{{ row.full_name }}</span> — {{ row.reason }}
        </li>
      </ul>
    </section>

    <section class="overflow-hidden rounded-xl border border-neutral-200 bg-surface shadow-sm">
      <div class="flex flex-wrap items-end gap-3 border-b border-neutral-200 p-4 sm:p-6">
        <label class="block text-sm font-medium text-neutral-700">
          {{ t('payroll.health_notifications.filter.period') }}
          <input
            v-model="period"
            type="month"
            data-test="health-notifications-period"
            class="mt-1 h-10 rounded-md border border-neutral-300 bg-surface px-3 text-sm focus:border-payroll-500 focus:outline-none focus:ring-2 focus:ring-payroll-500/20"
          >
        </label>
        <label class="block min-w-[12rem] text-sm font-medium text-neutral-700">
          {{ t('payroll.health_notifications.filter.insurer') }}
          <SearchableSelect
            v-model="filterInsurer"
            class="mt-1"
            data-test="health-notifications-insurer"
            :options="insurerOptions"
            :placeholder="t('payroll.health_notifications.filter.all')"
            accent="payroll"
          />
        </label>
        <label class="block min-w-[14rem] text-sm font-medium text-neutral-700">
          {{ t('payroll.health_notifications.filter.kind') }}
          <SearchableSelect
            v-model="filterKind"
            class="mt-1"
            data-test="health-notifications-kind"
            :options="kindOptions"
            :placeholder="t('payroll.health_notifications.filter.all')"
            accent="payroll"
          />
        </label>
        <label class="block min-w-[12rem] text-sm font-medium text-neutral-700">
          {{ t('payroll.health_notifications.filter.reported') }}
          <SearchableSelect
            v-model="filterReported"
            class="mt-1"
            :options="reportedOptions"
            :clearable="false"
            accent="payroll"
          />
        </label>
        <label class="flex items-center gap-2 whitespace-nowrap py-2 text-sm text-neutral-700">
          <input
            v-model="filterUndocumented"
            type="checkbox"
            data-test="health-notifications-undocumented"
            class="h-4 w-4 rounded border-neutral-300 text-payroll-600 focus:ring-payroll-500"
          >
          {{ t('payroll.health_notifications.filter.undocumented_only') }}
        </label>
        <div class="ml-auto flex flex-wrap items-center gap-2">
          <ColumnPicker class="hidden md:block" :ctrl="tbl" />
          <DensityToggle class="hidden md:block" :ctrl="tbl" />
        </div>
      </div>

      <div v-if="loading" class="space-y-3 p-4 sm:p-6">
        <div v-for="index in 4" :key="index" class="h-10 animate-pulse rounded-lg bg-neutral-100" />
      </div>

      <EmptyState
        v-else-if="loadFailed && items.length === 0"
        variant="failed"
        accent="primary"
        :message="error"
        :cta="t('common.refresh')"
        data-test="health-notifications-failed"
        @action="load"
      />

      <EmptyState
        v-else-if="items.length === 0"
        :variant="filtersActive ? 'filtered' : 'empty'"
        accent="primary"
        icon="doc"
        :title="filtersActive
          ? t('payroll.health_notifications.empty_filtered_title')
          : t('payroll.health_notifications.empty_title')"
        :message="filtersActive
          ? t('payroll.health_notifications.empty_filtered')
          : t('payroll.health_notifications.empty')"
        :cta="filtersActive ? t('payroll.health_notifications.filter.reset') : undefined"
        data-test="health-notifications-empty"
        @action="resetFilters"
      />

      <template v-else>
        <p
          v-if="loadFailed"
          class="border-b border-danger-500/30 bg-danger-50 px-4 py-3 text-sm text-danger-700"
          role="alert"
          data-test="health-notifications-stale"
        >
          {{ t('payroll.health_notifications.stale', { message: error }) }}
        </p>

        <div class="hidden overflow-x-auto md:block">
          <table class="min-w-full divide-y divide-neutral-200 text-sm" :class="tbl.densityClass.value">
            <thead>
              <tr class="text-left text-xs uppercase tracking-wide text-neutral-500">
                <th v-if="tbl.isVisible('employee')" class="px-4 py-3">
                  {{ t('payroll.health_notifications.table.employee') }}
                </th>
                <th v-if="tbl.isVisible('duty')" class="px-4 py-3">
                  {{ t('payroll.health_notifications.table.duty') }}
                </th>
                <th v-if="tbl.isVisible('insurer')" class="px-4 py-3">
                  {{ t('payroll.health_notifications.table.insurer') }}
                </th>
                <th v-if="tbl.isVisible('occurred_on')" class="px-4 py-3">
                  {{ t('payroll.health_notifications.table.occurred_on') }}
                </th>
                <th v-if="tbl.isVisible('deadline')" class="px-4 py-3">
                  {{ t('payroll.health_notifications.table.deadline') }}
                </th>
                <th v-if="tbl.isVisible('change_code')" class="px-4 py-3">
                  {{ t('payroll.health_notifications.table.change_code') }}
                </th>
                <th v-if="tbl.isVisible('source')" class="px-4 py-3">
                  {{ t('payroll.health_notifications.table.source') }}
                </th>
                <th v-if="tbl.isVisible('state')" class="px-4 py-3">
                  {{ t('payroll.health_notifications.table.state') }}
                </th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="item in items" :key="item.id" data-test="health-notification-row">
                <td v-if="tbl.isVisible('employee')" class="px-4 py-3 font-medium text-neutral-900">
                  {{ item.full_name }}
                </td>
                <td v-if="tbl.isVisible('duty')" class="px-4 py-3 text-neutral-700">
                  {{ t(`payroll.health_notifications.kind.${item.kind}`) }}
                </td>
                <td v-if="tbl.isVisible('insurer')" class="px-4 py-3 text-neutral-700">
                  {{ item.insurer_code }}
                  <span v-if="item.channel.insurer_name" class="block text-xs text-neutral-500">
                    {{ item.channel.insurer_name }}
                  </span>
                </td>
                <td v-if="tbl.isVisible('occurred_on')" class="px-4 py-3 text-neutral-700">
                  {{ formatDate(item.occurred_on) }}
                </td>
                <td v-if="tbl.isVisible('deadline')" class="px-4 py-3">
                  <span v-if="item.deadline" class="block text-neutral-900">
                    {{ formatDate(item.deadline.due_on) }}
                  </span>
                  <span
                    class="mt-1 inline-flex rounded-full px-2 py-0.5 text-xs font-medium"
                    :class="deadlineClass(item)"
                    data-test="health-notification-deadline"
                  >
                    {{ item.reported_by_employer
                      ? t('payroll.health_notifications.deadline.employer')
                      : t('payroll.health_notifications.deadline.none') }}
                  </span>
                </td>
                <td v-if="tbl.isVisible('change_code')" class="px-4 py-3">
                  <span
                    v-if="item.change_code.documented"
                    class="rounded-full bg-success-50 px-2.5 py-1 text-xs font-semibold text-success-700"
                  >{{ item.change_code.code }}</span>
                  <span
                    v-else
                    class="rounded-full bg-warning-50 px-2.5 py-1 text-xs font-medium text-warning-700"
                    :title="item.change_code.reason ?? ''"
                    data-test="health-notification-code-undocumented"
                  >{{ t('payroll.health_notifications.code_undocumented') }}</span>
                </td>
                <td v-if="tbl.isVisible('source')" class="px-4 py-3 text-xs text-neutral-500">
                  {{ item.rule.source }}
                  <span
                    v-if="item.rule.source_status === 'external_unverified'"
                    class="mt-1 block text-warning-700"
                  >{{ t('payroll.health_notifications.source_unverified') }}</span>
                </td>
                <td v-if="tbl.isVisible('state')" class="px-4 py-3 text-xs text-neutral-600">
                  {{ t(!item.reported_by_employer
                    ? 'payroll.health_notifications.state.insured_reports'
                    : item.obligation_id !== null
                      ? 'payroll.health_notifications.state.obligation_registered'
                      : 'payroll.health_notifications.state.needs_sync') }}
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <div class="grid grid-cols-1 gap-3 p-4 md:hidden">
          <article
            v-for="item in items"
            :key="item.id"
            class="rounded-lg border border-neutral-200 p-4"
          >
            <div class="flex flex-wrap items-start justify-between gap-2">
              <div>
                <h3 class="font-semibold text-neutral-900">{{ item.full_name }}</h3>
                <p class="mt-1 text-xs text-neutral-500">
                  {{ t(`payroll.health_notifications.kind.${item.kind}`) }}
                </p>
              </div>
              <span
                class="rounded-full px-2.5 py-1 text-xs font-medium"
                :class="deadlineClass(item)"
              >
                {{ item.deadline ? formatDate(item.deadline.due_on)
                  : t('payroll.health_notifications.deadline.none') }}
              </span>
            </div>
            <dl class="mt-3 grid grid-cols-2 gap-3 text-xs">
              <div>
                <dt class="text-neutral-500">
                  {{ t('payroll.health_notifications.table.insurer') }}
                </dt>
                <dd class="mt-0.5 text-neutral-800">{{ item.insurer_code }}</dd>
              </div>
              <div>
                <dt class="text-neutral-500">
                  {{ t('payroll.health_notifications.table.occurred_on') }}
                </dt>
                <dd class="mt-0.5 text-neutral-800">{{ formatDate(item.occurred_on) }}</dd>
              </div>
              <div class="col-span-2">
                <dt class="text-neutral-500">
                  {{ t('payroll.health_notifications.table.change_code') }}
                </dt>
                <dd class="mt-0.5 text-neutral-800">
                  {{ item.change_code.documented
                    ? item.change_code.code
                    : (item.change_code.reason
                      ?? t('payroll.health_notifications.code_undocumented')) }}
                </dd>
              </div>
              <div class="col-span-2">
                <dt class="text-neutral-500">
                  {{ t('payroll.health_notifications.table.state') }}
                </dt>
                <dd class="mt-0.5 text-neutral-800">
                  {{ t(!item.reported_by_employer
                    ? 'payroll.health_notifications.state.insured_reports'
                    : item.obligation_id !== null
                      ? 'payroll.health_notifications.state.obligation_registered'
                      : 'payroll.health_notifications.state.needs_sync') }}
                </dd>
              </div>
            </dl>
          </article>
        </div>

        <PaginationBar
          embedded
          :page="currentPage"
          :per-page="PAGE_SIZE"
          :total="total"
          @update:page="goToPage"
        />
      </template>
    </section>

    <section
      class="rounded-xl border border-neutral-200 bg-surface p-4 shadow-sm sm:p-6"
      data-test="health-notifications-prepare"
    >
      <h3 class="text-lg font-semibold text-neutral-900">
        {{ t('payroll.health_notifications.prepare.title') }}
      </h3>
      <p class="mt-1 max-w-3xl text-sm text-neutral-500">
        {{ t('payroll.health_notifications.prepare.description') }}
      </p>

      <div class="mt-5 grid grid-cols-1 gap-4 lg:grid-cols-2">
        <label class="block text-sm font-medium text-neutral-700">
          {{ t('payroll.health_notifications.prepare.revision') }}
          <SearchableSelect
            v-model="prepareRevisionId"
            class="mt-1"
            data-test="health-prepare-revision"
            :options="approvedRunOptions"
            :placeholder="t('payroll.health_notifications.prepare.revision_placeholder')"
            :no-results-label="t('payroll.health_notifications.prepare.revision_empty')"
            accent="payroll"
          />
        </label>
        <label class="block text-sm font-medium text-neutral-700">
          {{ t('payroll.health_notifications.prepare.insurer') }}
          <SearchableSelect
            v-model="prepareInsurer"
            class="mt-1"
            data-test="health-prepare-insurer"
            :options="insurerOptions"
            :placeholder="t('payroll.health_notifications.prepare.insurer_placeholder')"
            accent="payroll"
          />
        </label>
      </div>

      <p v-if="!canWrite" class="mt-4 text-sm text-neutral-500">
        {{ t('payroll.health_notifications.read_only') }}
      </p>

      <p
        v-if="prepareError"
        class="mt-4 rounded-lg border border-danger-500/30 bg-danger-50 p-4 text-sm text-danger-700"
        role="alert"
        data-test="health-prepare-error"
      >
        {{ prepareError }}
      </p>

      <div
        v-if="prepared"
        class="mt-4 rounded-lg border p-4"
        :class="prepared.schema_validated
          ? 'border-success-500/30 bg-success-50'
          : 'border-warning-500/40 bg-warning-50'"
        data-test="health-prepare-result"
      >
        <h4
          class="text-sm font-semibold"
          :class="prepared.schema_validated ? 'text-success-800' : 'text-warning-800'"
        >
          {{ prepared.schema_validated
            ? t('payroll.health_notifications.prepare.valid')
            : t('payroll.health_notifications.prepare.blocked') }}
        </h4>
        <p class="mt-1 text-sm" :class="prepared.schema_validated ? 'text-success-700' : 'text-warning-800'">
          {{ prepared.schema_validated
            ? t('payroll.health_notifications.prepare.valid_hint', {
              insurer: prepared.insurer_code,
              due: formatDate(prepared.deadline.due_on),
            })
            : t('payroll.health_notifications.prepare.blocked_hint') }}
        </p>
        <dl class="mt-3 grid grid-cols-2 gap-3 text-xs sm:grid-cols-4">
          <div>
            <dt class="text-neutral-500">{{ t('payroll.health_notifications.prepare.status') }}</dt>
            <dd class="mt-0.5 font-medium text-neutral-900">{{ submissionStatusLabel(prepared.status) }}</dd>
          </div>
          <div>
            <dt class="text-neutral-500">{{ t('payroll.health_notifications.prepare.period') }}</dt>
            <dd class="mt-0.5 font-medium text-neutral-900">{{ prepared.period }}</dd>
          </div>
          <div>
            <dt class="text-neutral-500">{{ t('payroll.health_notifications.prepare.due_on') }}</dt>
            <dd class="mt-0.5 font-medium text-neutral-900">
              {{ formatDate(prepared.deadline.due_on) }}
            </dd>
          </div>
          <div>
            <dt class="text-neutral-500">{{ t('payroll.health_notifications.prepare.fingerprint') }}</dt>
            <dd class="mt-0.5 break-all font-mono text-[0.7rem] text-neutral-700">
              {{ prepared.artifact_sha256.slice(0, 16) }}…
            </dd>
          </div>
        </dl>
        <p class="mt-3 text-xs text-neutral-600">
          {{ prepared.dispatch.reason }}
        </p>
        <p
          v-if="downloadError"
          class="mt-3 rounded-lg border border-danger-500/30 bg-danger-50 p-3 text-sm text-danger-700"
          role="alert"
          data-test="health-prepare-download-error"
        >
          {{ downloadError }}
        </p>
        <p
          v-if="isdsError"
          class="mt-3 rounded-lg border border-danger-500/30 bg-danger-50 p-3 text-sm text-danger-700"
          role="alert"
          data-test="health-prepare-isds-error"
        >
          {{ isdsError }}
        </p>
        <div
          v-if="isdsResult"
          class="mt-3 rounded-lg border border-success-500/30 bg-success-50 p-3 text-sm text-success-800"
          data-test="health-prepare-isds-result"
        >
          <p class="font-semibold">
            {{ isdsResult.created
              ? t('payroll.health_notifications.prepare.isds_ready')
              : t('payroll.health_notifications.prepare.isds_already_ready') }}
          </p>
          <p class="mt-1">
            {{ t('payroll.health_notifications.prepare.isds_recipient', {
              name: isdsResult.recipient.name,
              id: isdsResult.recipient.box_id,
            }) }}
          </p>
          <p v-if="isdsMobileKeySent" class="mt-2 font-semibold">
            {{ t('databox.outbox.mobileKey.sent') }}
          </p>
          <MobileKeySendButton
            v-else-if="!isdsResult.transport.automatic && isdsResult.transport.channel === 'mobile_key'"
            class="mt-2"
            :outbox-id="isdsResult.outbox_id"
            environment="production"
            @sent="mobileKeySent"
          />
          <a
            v-else-if="!isdsResult.transport.automatic"
            :href="isdsResult.outbox_url"
            class="mt-2 inline-flex font-semibold underline"
          >
            {{ t('payroll.health_notifications.prepare.open_outbox') }}
          </a>
        </div>
        <div
          v-if="isdsGateway"
          class="mt-3 rounded-lg border border-primary-200 bg-primary-50 p-3 text-sm text-primary-900"
          data-test="health-prepare-isds-gateway"
        >
          <p class="font-semibold">
            {{ t('payroll.health_notifications.prepare.gateway_title') }}
          </p>
          <p class="mt-1">{{ isdsGateway.login_guidance }}</p>
          <p class="mt-2 text-xs">
            {{ t('payroll.health_notifications.prepare.gateway_credentials') }}
          </p>
          <button
            type="button"
            :class="[btnFilledSm('primary'), 'mt-3']"
            data-test="health-prepare-isds-continue"
            @click="continueIsdsGateway"
          >
            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
              <path :d="ICONS.send" />
            </svg>
            {{ t('payroll.health_notifications.prepare.gateway_continue') }}
          </button>
        </div>
        <p
          v-if="prepared.schema_validated && !canQueueIsds && !isdsBusy && !isdsResult"
          class="mt-3 text-xs text-neutral-600"
          data-test="health-prepare-isds-unavailable"
        >
          {{ preparedChannel?.data_box_id && preparedChannel?.isds_attachment_format !== 'none'
            ? t('payroll.health_notifications.read_only')
            : t('payroll.health_notifications.prepare.isds_unavailable') }}
        </p>
        <div class="mt-3 flex flex-wrap gap-2">
          <button
            type="button"
            :class="btnOutlineSm('neutral')"
            :disabled="downloading"
            data-test="health-prepare-download"
            @click="downloadArtifact"
          >
            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
              <path :d="ICONS.download" />
            </svg>
            {{ downloading
              ? t('payroll.health_notifications.prepare.downloading')
              : t('payroll.health_notifications.prepare.download') }}
          </button>
          <button
            type="button"
            :class="btnFilledSm('primary')"
            :disabled="!canQueueIsds"
            data-test="health-prepare-isds"
            @click="enqueueIsds"
          >
            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
              <path :d="ICONS.send" />
            </svg>
            {{ isdsBusy
              ? t('payroll.health_notifications.prepare.isds_preparing')
              : t('payroll.health_notifications.prepare.isds_action') }}
          </button>
        </div>
      </div>
    </section>

    <section
      class="rounded-xl border border-neutral-200 bg-surface p-4 shadow-sm sm:p-6"
      data-test="health-notifications-prepare-bulk"
    >
      <h3 class="text-lg font-semibold text-neutral-900">
        {{ t('payroll.health_notifications.prepare_bulk.title') }}
      </h3>
      <p class="mt-1 max-w-3xl text-sm text-neutral-500">
        {{ t('payroll.health_notifications.prepare_bulk.description') }}
      </p>

      <div class="mt-5 max-w-sm">
        <label class="block text-sm font-medium text-neutral-700">
          {{ t('payroll.health_notifications.prepare_bulk.insurer') }}
          <SearchableSelect
            v-model="prepareBulkInsurer"
            class="mt-1"
            data-test="health-prepare-bulk-insurer"
            :options="insurerOptions"
            :placeholder="t('payroll.health_notifications.prepare_bulk.insurer_placeholder')"
            accent="payroll"
          />
        </label>
        <button
          type="button"
          :class="[btnFilledSm('primary'), 'mt-3']"
          :disabled="!canPrepareBulk"
          data-test="health-prepare-bulk-action"
          @click="prepareBulk"
        >
          <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path :d="ICONS.doc" />
          </svg>
          {{ preparingBulk
            ? t('payroll.health_notifications.prepare.isds_preparing')
            : t('payroll.health_notifications.prepare_bulk.action') }}
        </button>
      </div>

      <p v-if="!canWrite" class="mt-4 text-sm text-neutral-500">
        {{ t('payroll.health_notifications.read_only') }}
      </p>

      <p
        v-if="prepareBulkError"
        class="mt-4 rounded-lg border border-danger-500/30 bg-danger-50 p-4 text-sm text-danger-700"
        role="alert"
        data-test="health-prepare-bulk-error"
      >
        {{ prepareBulkError }}
      </p>

      <div
        v-if="preparedBulk"
        class="mt-4 rounded-lg border p-4"
        :class="preparedBulk.schema_validated
          ? 'border-success-500/30 bg-success-50'
          : 'border-warning-500/40 bg-warning-50'"
        data-test="health-prepare-bulk-result"
      >
        <h4
          class="text-sm font-semibold"
          :class="preparedBulk.schema_validated ? 'text-success-800' : 'text-warning-800'"
        >
          {{ preparedBulk.schema_validated
            ? t('payroll.health_notifications.prepare_bulk.valid')
            : t('payroll.health_notifications.prepare_bulk.blocked') }}
        </h4>
        <p class="mt-1 text-sm" :class="preparedBulk.schema_validated ? 'text-success-700' : 'text-warning-800'">
          {{ preparedBulk.schema_validated
            ? t('payroll.health_notifications.prepare_bulk.valid_hint', {
              insurer: preparedBulk.insurer_code,
              count: preparedBulk.changes_count,
              due: formatDate(preparedBulk.deadline.due_on),
            })
            : t('payroll.health_notifications.prepare_bulk.blocked_hint') }}
        </p>
        <dl class="mt-3 grid grid-cols-2 gap-3 text-xs sm:grid-cols-4">
          <div>
            <dt class="text-neutral-500">{{ t('payroll.health_notifications.prepare_bulk.status') }}</dt>
            <dd class="mt-0.5 font-medium text-neutral-900">{{ submissionStatusLabel(preparedBulk.status) }}</dd>
          </div>
          <div>
            <dt class="text-neutral-500">{{ t('payroll.health_notifications.prepare_bulk.changes_count') }}</dt>
            <dd class="mt-0.5 font-medium text-neutral-900">{{ preparedBulk.changes_count }}</dd>
          </div>
          <div>
            <dt class="text-neutral-500">{{ t('payroll.health_notifications.prepare_bulk.due_on') }}</dt>
            <dd class="mt-0.5 font-medium text-neutral-900">
              {{ formatDate(preparedBulk.deadline.due_on) }}
            </dd>
          </div>
          <div>
            <dt class="text-neutral-500">{{ t('payroll.health_notifications.prepare_bulk.fingerprint') }}</dt>
            <dd class="mt-0.5 break-all font-mono text-[0.7rem] text-neutral-700">
              {{ preparedBulk.artifact_sha256.slice(0, 16) }}…
            </dd>
          </div>
        </dl>
        <p
          v-if="downloadBulkError"
          class="mt-3 rounded-lg border border-danger-500/30 bg-danger-50 p-3 text-sm text-danger-700"
          role="alert"
          data-test="health-prepare-bulk-download-error"
        >
          {{ downloadBulkError }}
        </p>
        <p
          v-if="isdsBulkError"
          class="mt-3 rounded-lg border border-danger-500/30 bg-danger-50 p-3 text-sm text-danger-700"
          role="alert"
          data-test="health-prepare-bulk-isds-error"
        >
          {{ isdsBulkError }}
        </p>
        <div
          v-if="isdsBulkResult"
          class="mt-3 rounded-lg border border-success-500/30 bg-success-50 p-3 text-sm text-success-800"
          data-test="health-prepare-bulk-isds-result"
        >
          <p class="font-semibold">
            {{ isdsBulkResult.created
              ? t('payroll.health_notifications.prepare.isds_ready')
              : t('payroll.health_notifications.prepare.isds_already_ready') }}
          </p>
          <p class="mt-1">
            {{ t('payroll.health_notifications.prepare.isds_recipient', {
              name: isdsBulkResult.recipient.name,
              id: isdsBulkResult.recipient.box_id,
            }) }}
          </p>
          <p v-if="isdsBulkMobileKeySent" class="mt-2 font-semibold">
            {{ t('databox.outbox.mobileKey.sent') }}
          </p>
          <MobileKeySendButton
            v-else-if="!isdsBulkResult.transport.automatic && isdsBulkResult.transport.channel === 'mobile_key'"
            class="mt-2"
            :outbox-id="isdsBulkResult.outbox_id"
            environment="production"
            @sent="bulkMobileKeySent"
          />
          <a
            v-else-if="!isdsBulkResult.transport.automatic"
            :href="isdsBulkResult.outbox_url"
            class="mt-2 inline-flex font-semibold underline"
          >
            {{ t('payroll.health_notifications.prepare.open_outbox') }}
          </a>
        </div>
        <div
          v-if="isdsBulkGateway"
          class="mt-3 rounded-lg border border-primary-200 bg-primary-50 p-3 text-sm text-primary-900"
          data-test="health-prepare-bulk-isds-gateway"
        >
          <p class="font-semibold">
            {{ t('payroll.health_notifications.prepare.gateway_title') }}
          </p>
          <p class="mt-1">{{ isdsBulkGateway.login_guidance }}</p>
          <p class="mt-2 text-xs">
            {{ t('payroll.health_notifications.prepare.gateway_credentials') }}
          </p>
          <button
            type="button"
            :class="[btnFilledSm('primary'), 'mt-3']"
            data-test="health-prepare-bulk-isds-continue"
            @click="continueIsdsBulkGateway"
          >
            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
              <path :d="ICONS.send" />
            </svg>
            {{ t('payroll.health_notifications.prepare.gateway_continue') }}
          </button>
        </div>
        <p
          v-if="preparedBulk.schema_validated && !canQueueBulkIsds && !isdsBulkBusy && !isdsBulkResult"
          class="mt-3 text-xs text-neutral-600"
          data-test="health-prepare-bulk-isds-unavailable"
        >
          {{ t('payroll.health_notifications.prepare.isds_unavailable') }}
        </p>
        <div class="mt-3 flex flex-wrap gap-2">
          <button
            type="button"
            :class="btnOutlineSm('neutral')"
            :disabled="downloadingBulk"
            data-test="health-prepare-bulk-download"
            @click="downloadBulk"
          >
            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
              <path :d="ICONS.download" />
            </svg>
            {{ downloadingBulk
              ? t('payroll.health_notifications.prepare_bulk.downloading')
              : t('payroll.health_notifications.prepare_bulk.download') }}
          </button>
          <button
            type="button"
            :class="btnFilledSm('primary')"
            :disabled="!canQueueBulkIsds"
            data-test="health-prepare-bulk-isds"
            @click="enqueueBulkIsds"
          >
            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
              <path :d="ICONS.send" />
            </svg>
            {{ isdsBulkBusy
              ? t('payroll.health_notifications.prepare.isds_preparing')
              : t('payroll.health_notifications.prepare.isds_action') }}
          </button>
        </div>
      </div>
    </section>
  </section>
</template>

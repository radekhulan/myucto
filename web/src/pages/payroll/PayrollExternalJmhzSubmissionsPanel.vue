<script setup lang="ts">
import DateInput from '@/components/ui/DateInput.vue'
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { apiErrorMessage } from '@/api/errors'
import {
  payrollApi,
  type PayrollJmhzExternalSubmission,
  type PayrollJmhzExternalSubmissionDetail,
  type PayrollJmhzExternalSubmissionPerson,
  type PayrollJmhzTransportEnvironment,
} from '@/api/payroll'
import { btnFilled, btnOutline, ICONS } from '@/components/ui/buttonStyles'
import Modal from '@/components/ui/Modal.vue'
import RowActionsMenu, { type RowAction } from '@/components/ui/RowActionsMenu.vue'
import { formatDate, formatDateTime, formatPeriod, formatPeriodRange } from '@/composables/useFormat'
import { usePerUserFlag } from '@/composables/usePerUserFlag'
import { useAuthStore } from '@/stores/auth'

/*
 * Podání, která za firmu podal předchozí mzdový program (převod z PAMICA, nahrané
 * XML hlášení). Firma bez převodu tu nic nemá a panel se nevykreslí vůbec.
 *
 * Přehled je seskupený po agendě (hlášení JMHZ, registrace) a měsíci a u každého
 * podání říká KDO v něm byl a JAKOU akcí; detail ukáže všechny formuláře.
 *
 * Záznam není jen informace: za měsíc s odeslaným řádným hlášením server vlastní
 * řádné hlášení nezmrazí (ČSSZ by ho zamítla jako duplicitní) a registrace
 * z historie počítá dohlášení A3 jako vyřízené. Když záznam neodpovídá
 * skutečnosti, jde odebrat — schovaně v „…", s vysvětlením a potvrzením.
 */
const props = defineProps<{
  environment: PayrollJmhzTransportEnvironment
}>()

const { t } = useI18n()
const auth = useAuthStore()
const route = useRoute() as ReturnType<typeof useRoute> | undefined
const canWrite = computed(() => auth.canWrite('payroll.submissions'))

const items = ref<PayrollJmhzExternalSubmission[]>([])
/** Převzaté měsíce, za které předchozí program žádné hlášení nemá (Q15-17). */
const missingPeriods = ref<string[]>([])
const loading = ref(true)
const error = ref('')
const removing = ref<number | null>(null)

const detail = ref<PayrollJmhzExternalSubmissionDetail | null>(null)
const detailLoading = ref(false)
const detailError = ref('')
const confirmTarget = ref<PayrollJmhzExternalSubmission | null>(null)

const PEOPLE_PREVIEW = 3

/*
 * Historie podání je hotová věc, kterou člověk otevře jen občas; ve výchozím
 * stavu je proto sbalená a volbu si pamatuje každý uživatel zvlášť. Upozornění,
 * která vyžadují akci (nepodané nebo neodeslané měsíce), zůstávají vidět vždy.
 */
const historyFlag = usePerUserFlag('payroll.external-jmhz.history-expanded')
const historyExpanded = ref(historyFlag.read() ?? false)

function toggleHistory() {
  historyExpanded.value = !historyExpanded.value
  historyFlag.write(historyExpanded.value)
}

/**
 * Seznam měsíců jako souvislé úseky: „duben–červenec 2026", nesouvislé
 * oddělené čárkou.
 */
function periodsLabel(periods: string[]): string {
  const sorted = [...periods].sort()
  const runs: Array<[string, string]> = []
  for (const period of sorted) {
    const last = runs[runs.length - 1]
    if (last && nextPeriod(last[1]) === period) {
      last[1] = period
    } else {
      runs.push([period, period])
    }
  }
  return runs.map(([from, to]) => formatPeriodRange(from, to)).join(', ')
}

function nextPeriod(period: string): string {
  const [year, month] = period.split('-').map(Number)
  return month === 12
    ? `${year + 1}-01`
    : `${year}-${String(month + 1).padStart(2, '0')}`
}

function todayIso(): string {
  const now = new Date()
  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`
}

const attestOpen = ref(false)
const attestPeriods = ref<string[]>([])
const attestDate = ref('')
const attestNote = ref('')
const attesting = ref(false)
const attestError = ref('')

function openAttest() {
  attestPeriods.value = [...missingPeriods.value]
  attestDate.value = todayIso()
  attestNote.value = ''
  attestError.value = ''
  attestOpen.value = true
}

async function confirmAttest() {
  if (!canWrite.value || attesting.value || attestPeriods.value.length === 0 || attestDate.value === '') return
  attesting.value = true
  attestError.value = ''
  try {
    await payrollApi.attestJmhzExternalSubmissions({
      periods: [...attestPeriods.value].sort(),
      submitted_on: attestDate.value,
      note: attestNote.value.trim() === '' ? null : attestNote.value.trim(),
    }, props.environment)
    attestOpen.value = false
    await load()
  } catch (exception) {
    attestError.value = apiErrorMessage(exception, t('payroll.external_jmhz.attest.failed'))
  } finally {
    attesting.value = false
  }
}

const monthly = computed(() => items.value.filter(item => item.document_kind === 'monthly'))
const registrations = computed(() => items.value.filter(item => item.document_kind === 'registration'))

interface Group {
  key: string
  items: PayrollJmhzExternalSubmission[]
}

function groupBy(list: PayrollJmhzExternalSubmission[], key: (item: PayrollJmhzExternalSubmission) => string): Group[] {
  const groups = new Map<string, PayrollJmhzExternalSubmission[]>()
  for (const item of list) {
    const k = key(item)
    groups.set(k, [...(groups.get(k) ?? []), item])
  }
  return [...groups.entries()]
    .sort(([a], [b]) => b.localeCompare(a))
    .map(([k, rows]) => ({ key: k, items: rows }))
}

/** Měsíc registrace = měsíc odeslání (registrace období nemá). */
function registrationMonth(item: PayrollJmhzExternalSubmission): string {
  return (item.submitted_at ?? item.filled_at ?? '').slice(0, 7)
}

const monthlyGroups = computed(() => groupBy(monthly.value, item => item.period ?? ''))
const registrationGroups = computed(() => groupBy(registrations.value, registrationMonth))

/** Měsíce, za které předchozí program hlášení připravil, ale žádné neodeslal. */
const unsentPeriods = computed(() => {
  const sent = new Set(monthly.value.filter(item => item.status === 'sent').map(item => item.period))
  const periods = new Set<string>()
  for (const item of monthly.value) {
    if (item.status === 'not_sent' && item.period && !sent.has(item.period)) periods.add(item.period)
  }
  return [...periods].sort()
})

/*
 * Registrace (A1/A2/A3), které předchozí program připravil, ale ČSSZ je
 * nedostala (C-4). U JMHZ upozornění s návodem bylo, u registrací ne, takže
 * neodeslaná přihláška nebo odhláška zůstala bez povšimnutí v seznamu.
 */
const unsentRegistrations = computed(() => registrations.value.filter(item => item.status !== 'sent'))

const UNSENT_PEOPLE_PREVIEW = 5

function unsentPeople(item: PayrollJmhzExternalSubmission): PayrollJmhzExternalSubmissionPerson[] {
  return (item.people ?? []).slice(0, UNSENT_PEOPLE_PREVIEW)
}

function unmatchedCount(item: PayrollJmhzExternalSubmission): number {
  return Math.max(0, item.form_count - item.matched_forms)
}

function typeLabel(item: PayrollJmhzExternalSubmission): string {
  if (item.document_kind === 'registration') return t('payroll.external_jmhz.type.registration')
  return t(`payroll.external_jmhz.type.${item.submission_type ?? 'R'}`)
}

const KNOWN_ACTIONS = ['A1', 'A2', 'A3', 'R', 'O', 'S']

function actionLabel(action: string | null): string {
  return action && KNOWN_ACTIONS.includes(action)
    ? t(`payroll.external_jmhz.action.${action}`)
    : t('payroll.external_jmhz.action.other', { code: action ?? '?' })
}

function actionEntries(item: PayrollJmhzExternalSubmission): Array<{ action: string; count: number }> {
  return Object.entries(item.actions ?? {}).map(([action, count]) => ({ action, count }))
}

function personLabel(person: PayrollJmhzExternalSubmissionPerson): string {
  return person.name ?? t('payroll.external_jmhz.unmatched_person')
}

function previewPeople(item: PayrollJmhzExternalSubmission): PayrollJmhzExternalSubmissionPerson[] {
  return (item.people ?? []).slice(0, PEOPLE_PREVIEW)
}

function morePeople(item: PayrollJmhzExternalSubmission): number {
  return Math.max(0, item.form_count - previewPeople(item).length)
}

function effectiveLabel(item: PayrollJmhzExternalSubmission): string {
  if (!item.effective_from) return ''
  if (!item.effective_to || item.effective_to === item.effective_from) return formatDate(item.effective_from)
  return `${formatDate(item.effective_from)} – ${formatDate(item.effective_to)}`
}

function isAttestation(item: PayrollJmhzExternalSubmission): boolean {
  return item.source === 'manual_attestation'
}

function sourceLabel(item: PayrollJmhzExternalSubmission): string {
  if (isAttestation(item)) {
    return item.note
      ? t('payroll.external_jmhz.source.manual_attestation_note', { note: item.note })
      : t('payroll.external_jmhz.source.manual_attestation')
  }
  if (item.source === 'jmhz_xml') {
    return item.file_name
      ? t('payroll.external_jmhz.source.jmhz_xml_file', { file: item.file_name })
      : t('payroll.external_jmhz.source.jmhz_xml')
  }
  return item.program ?? t('payroll.external_jmhz.source.pamica')
}

function statusClass(item: PayrollJmhzExternalSubmission): string {
  return item.status === 'sent'
    ? 'bg-success-50 text-success-700'
    : 'bg-warning-50 text-warning-700'
}

function resultText(item: PayrollJmhzExternalSubmission): string {
  if (item.status !== 'sent') return t('payroll.external_jmhz.result.not_sent')
  if (item.accepted_at) return t('payroll.external_jmhz.result.accepted', { date: formatDateTime(item.accepted_at) })
  return t('payroll.external_jmhz.result.sent', { date: formatDateTime(item.submitted_at) })
}

function personTarget(person: PayrollJmhzExternalSubmissionPerson) {
  return {
    name: 'payroll-people',
    query: {
      person: String(person.employee_id),
      ...(person.employment_id ? { employment: String(person.employment_id) } : {}),
    },
  }
}

function rowActions(item: PayrollJmhzExternalSubmission): RowAction[] {
  return [
    {
      key: 'detail',
      label: t('payroll.external_jmhz.detail'),
      icon: 'eye',
      show: !isAttestation(item),
      run: () => { void openDetail(item.id) },
    },
    {
      key: 'remove',
      label: isAttestation(item) ? t('payroll.external_jmhz.attest.revoke') : t('payroll.external_jmhz.remove'),
      icon: 'trash',
      variant: 'danger',
      show: canWrite.value,
      run: () => { confirmTarget.value = item },
    },
  ]
}

async function load() {
  loading.value = true
  error.value = ''
  try {
    const page = await payrollApi.jmhzExternalSubmissions(props.environment)
    items.value = page.items
    missingPeriods.value = page.missing_periods ?? []
  } catch (exception) {
    items.value = []
    missingPeriods.value = []
    error.value = apiErrorMessage(exception, t('payroll.external_jmhz.load_failed'))
  } finally {
    loading.value = false
  }
}

async function openDetail(id: number) {
  detailLoading.value = true
  detailError.value = ''
  detail.value = null
  try {
    detail.value = await payrollApi.jmhzExternalSubmission(id, props.environment)
  } catch (exception) {
    detailError.value = apiErrorMessage(exception, t('payroll.external_jmhz.detail_failed'))
  } finally {
    detailLoading.value = false
  }
}

function closeDetail() {
  detail.value = null
  detailError.value = ''
  detailLoading.value = false
}

function detailTitle(item: PayrollJmhzExternalSubmission): string {
  return item.document_kind === 'registration'
    ? t('payroll.external_jmhz.detail_title_registration', { date: formatDate(item.submitted_at ?? item.filled_at) })
    : t('payroll.external_jmhz.detail_title_monthly', { type: typeLabel(item), period: formatPeriod(item.period) })
}

function removeConsequence(item: PayrollJmhzExternalSubmission): string {
  if (isAttestation(item)) {
    return t('payroll.external_jmhz.attest.revoke_consequence', { period: formatPeriod(item.period) })
  }
  if (item.document_kind === 'registration') return t('payroll.external_jmhz.remove_dialog.registration')
  return item.status === 'sent'
    ? t('payroll.external_jmhz.remove_dialog.monthly_sent', { period: formatPeriod(item.period) })
    : t('payroll.external_jmhz.remove_dialog.monthly_unsent')
}

async function confirmRemove() {
  const item = confirmTarget.value
  if (!item || !canWrite.value || removing.value !== null) return
  removing.value = item.id
  error.value = ''
  try {
    if (isAttestation(item)) {
      await payrollApi.revokeJmhzExternalAttestation(item.id, props.environment)
      confirmTarget.value = null
      // Měsíc se vrací mezi nepodané — ty počítá server.
      await load()
      return
    }
    await payrollApi.deleteJmhzExternalSubmission(item.id, props.environment)
    items.value = items.value.filter(row => row.id !== item.id)
    if (detail.value?.id === item.id) closeDetail()
    confirmTarget.value = null
  } catch (exception) {
    error.value = apiErrorMessage(exception, t('payroll.external_jmhz.remove_failed'))
    confirmTarget.value = null
  } finally {
    removing.value = null
  }
}

watch(() => props.environment, load)
onMounted(async () => {
  await load()
  const requested = Number(route?.query?.external)
  if (Number.isInteger(requested) && requested > 0 && items.value.some(item => item.id === requested)) {
    await openDetail(requested)
  }
})
</script>

<template>
  <section
    v-if="items.length > 0 || missingPeriods.length > 0 || error"
    id="external-submissions"
    class="rounded-xl border border-neutral-200 bg-surface shadow-sm"
    data-test="external-jmhz-panel"
  >
    <div class="border-b border-neutral-200 p-4 sm:p-6">
      <div class="flex flex-wrap items-center justify-between gap-3">
        <h2 class="text-lg font-semibold text-neutral-900">
          {{ t('payroll.external_jmhz.title') }}
          <span v-if="items.length" class="text-sm font-normal text-neutral-500" data-test="external-jmhz-count">
            ({{ items.length }})
          </span>
        </h2>
        <button
          v-if="items.length"
          type="button"
          :class="btnOutline('neutral')"
          class="whitespace-nowrap"
          :aria-expanded="historyExpanded"
          data-test="external-jmhz-history-toggle"
          @click="toggleHistory"
        >
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path :d="historyExpanded ? 'M5 15l7-7 7 7' : 'M19 9l-7 7-7-7'" />
          </svg>
          {{ historyExpanded ? t('payroll.external_jmhz.history_hide') : t('payroll.external_jmhz.history_show') }}
        </button>
      </div>
      <p v-if="historyExpanded" class="mt-1 max-w-3xl text-sm text-neutral-500">{{ t('payroll.external_jmhz.description') }}</p>
    </div>

    <div
      v-if="error"
      class="m-4 rounded-lg border border-danger-500/30 bg-danger-50 p-3 text-sm text-danger-700"
      role="alert"
      data-test="external-jmhz-error"
    >
      {{ error }}
    </div>

    <div
      v-for="period in unsentPeriods"
      :key="period"
      class="m-4 rounded-lg border border-warning-500/30 bg-warning-50 p-3 text-sm text-warning-800"
      role="status"
      data-test="external-jmhz-unsent"
    >
      <p class="font-medium">{{ t('payroll.external_jmhz.unsent_title', { period: formatPeriod(period) }) }}</p>
      <p class="mt-1">{{ t('payroll.external_jmhz.unsent_hint') }}</p>
      <RouterLink
        :to="{ name: 'imports-pamica' }"
        class="mt-1 inline-block font-medium text-payroll-600 underline hover:text-payroll-700"
      >
        {{ t('payroll.external_jmhz.open_migration') }}
      </RouterLink>
    </div>

    <div
      v-if="missingPeriods.length"
      class="m-4 rounded-lg border border-danger-500/30 bg-danger-50 p-3 text-sm text-danger-700"
      role="status"
      data-test="external-jmhz-missing"
    >
      <p class="font-medium">
        {{ t('payroll.external_jmhz.missing_group_title', {
          count: missingPeriods.length,
          periods: periodsLabel(missingPeriods),
        }) }}
      </p>
      <p class="mt-1">{{ t('payroll.external_jmhz.missing_group_hint') }}</p>
      <div class="mt-2 flex flex-wrap gap-2">
        <button
          v-if="canWrite && environment === 'production'"
          type="button"
          :class="[btnFilled('success'), 'whitespace-nowrap']"
          data-test="external-jmhz-attest-open"
          @click="openAttest"
        >
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.check" /></svg>
          {{ t('payroll.external_jmhz.attest.open') }}
        </button>
        <RouterLink
          :to="{ name: 'imports-pamica' }"
          :class="[btnOutline('neutral'), 'whitespace-nowrap']"
        >
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.download" /></svg>
          {{ t('payroll.external_jmhz.open_migration') }}
        </RouterLink>
      </div>
    </div>

    <div
      v-for="item in unsentRegistrations"
      :key="`unsent-registration-${item.id}`"
      class="m-4 rounded-lg border border-warning-500/30 bg-warning-50 p-3 text-sm text-warning-800"
      role="status"
      data-test="external-jmhz-unsent-registration"
    >
      <p class="font-medium">
        {{ t('payroll.external_jmhz.unsent_registration.title', {
          actions: actionEntries(item).map(entry => entry.count > 1 ? `${actionLabel(entry.action)} × ${entry.count}` : actionLabel(entry.action)).join(', '),
          date: formatDate(item.filled_at ?? item.submitted_at),
        }) }}
      </p>
      <p class="mt-1">
        <template v-for="(person, index) in unsentPeople(item)" :key="index">
          <span v-if="index > 0">, </span>
          <RouterLink
            v-if="person.employee_id"
            :to="personTarget(person)"
            class="font-medium underline"
          >{{ personLabel(person) }}</RouterLink>
          <span v-else class="italic">{{ personLabel(person) }}</span>
          <span v-if="person.effective_on"> ({{ person.action }} {{ formatDate(person.effective_on) }})</span>
        </template>
        <span v-if="item.form_count > unsentPeople(item).length">
          {{ t('payroll.external_jmhz.more_people', { count: item.form_count - unsentPeople(item).length }) }}
        </span>
      </p>
      <p class="mt-1">{{ t('payroll.external_jmhz.unsent_registration.hint') }}</p>
      <p v-if="unmatchedCount(item) > 0" class="mt-1" data-test="external-jmhz-unsent-registration-unmatched">
        {{ t('payroll.external_jmhz.unsent_registration.unmatched', { count: unmatchedCount(item) }) }}
      </p>
      <div class="mt-2 flex flex-wrap gap-2">
        <button
          type="button"
          :class="[btnOutline('warning'), 'whitespace-nowrap']"
          :data-test="`external-jmhz-unsent-registration-detail-${item.id}`"
          @click="openDetail(item.id)"
        >
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.eye" /></svg>
          {{ t('payroll.external_jmhz.unsent_registration.open_detail') }}
        </button>
        <RouterLink
          :to="{ name: 'payroll-people' }"
          :class="[btnOutline('neutral'), 'whitespace-nowrap']"
        >
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.user" /></svg>
          {{ t('payroll.external_jmhz.unsent_registration.open_people') }}
        </RouterLink>
      </div>
    </div>

    <template
      v-for="section in [
        { key: 'monthly', title: t('payroll.external_jmhz.section.monthly'), hint: t('payroll.external_jmhz.section.monthly_hint'), groups: monthlyGroups },
        { key: 'registration', title: t('payroll.external_jmhz.section.registration'), hint: t('payroll.external_jmhz.section.registration_hint'), groups: registrationGroups },
      ]"
      :key="section.key"
    >
      <div v-if="historyExpanded && section.groups.length" class="border-t border-neutral-200 first:border-t-0" :data-test="`external-jmhz-section-${section.key}`">
        <div class="px-4 pt-4 sm:px-6">
          <h3 class="text-base font-semibold text-neutral-900">{{ section.title }}</h3>
          <p class="mt-0.5 max-w-3xl text-xs text-neutral-500">{{ section.hint }}</p>
        </div>
        <div
          v-for="group in section.groups"
          :key="group.key"
          class="px-4 pb-2 sm:px-6"
          :data-test="`external-jmhz-group-${section.key}-${group.key}`"
        >
          <h4 class="mt-4 text-xs font-semibold uppercase tracking-wide text-neutral-500">
            {{ group.key ? formatPeriod(group.key) : t('payroll.external_jmhz.no_period') }}
          </h4>
          <ul class="mt-2 divide-y divide-neutral-100 rounded-lg border border-neutral-200">
            <li
              v-for="item in group.items"
              :key="item.id"
              class="flex flex-col gap-3 p-3 sm:flex-row sm:items-start sm:justify-between"
              data-test="external-jmhz-row"
            >
              <div class="min-w-0 flex-1 space-y-1.5 text-sm">
                <div class="flex flex-wrap items-center gap-2">
                  <span class="font-medium text-neutral-900">{{ typeLabel(item) }}</span>
                  <span class="inline-flex whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-medium" :class="statusClass(item)">
                    {{ t(`payroll.external_jmhz.status.${item.status}`) }}
                  </span>
                  <span
                    v-for="entry in actionEntries(item)"
                    :key="entry.action"
                    class="inline-flex whitespace-nowrap rounded-full bg-neutral-100 px-2 py-0.5 text-xs font-medium text-neutral-700"
                    data-test="external-jmhz-action"
                  >
                    {{ actionLabel(entry.action) }}<template v-if="entry.count > 1"> × {{ entry.count }}</template>
                  </span>
                </div>
                <p class="text-neutral-700" data-test="external-jmhz-people">
                  <template v-for="(person, index) in previewPeople(item)" :key="index">
                    <span v-if="index > 0">, </span>
                    <span :class="person.name ? '' : 'italic text-neutral-500'">{{ personLabel(person) }}</span>
                    <span v-if="item.document_kind === 'registration' && person.effective_on" class="text-neutral-500">
                      ({{ person.action }} {{ formatDate(person.effective_on) }})</span>
                  </template>
                  <span v-if="morePeople(item) > 0" class="text-neutral-500">
                    {{ t('payroll.external_jmhz.more_people', { count: morePeople(item) }) }}
                  </span>
                </p>
                <p class="text-xs text-neutral-500">
                  {{ resultText(item) }}
                  <template v-if="effectiveLabel(item)"> · {{ t('payroll.external_jmhz.effective', { dates: effectiveLabel(item) }) }}</template>
                  · {{ t('payroll.external_jmhz.forms_matched', { matched: item.matched_forms, total: item.form_count }) }}
                  · {{ sourceLabel(item) }}
                </p>
              </div>
              <RowActionsMenu :actions="rowActions(item)" :inline-count="1" class="shrink-0" />
            </li>
          </ul>
        </div>
        <div class="h-4" />
      </div>
    </template>

    <p v-if="loading" class="sr-only">{{ t('common.loading') }}</p>

    <Modal
      v-if="detail || detailLoading || detailError"
      :title="detail ? detailTitle(detail) : t('payroll.external_jmhz.detail')"
      width-class="max-w-4xl"
      @close="closeDetail"
    >
      <div v-if="detailLoading" class="h-32 animate-pulse rounded-lg bg-neutral-100" />
      <div
        v-else-if="detailError"
        class="rounded-lg border border-danger-500/30 bg-danger-50 p-3 text-sm text-danger-700"
        role="alert"
      >
        {{ detailError }}
      </div>
      <div v-else-if="detail" class="space-y-4 text-sm" data-test="external-jmhz-detail">
        <dl class="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <div>
            <dt class="text-xs text-neutral-500">{{ t('payroll.external_jmhz.col_result') }}</dt>
            <dd class="mt-0.5 text-neutral-900">{{ resultText(detail) }}</dd>
          </div>
          <div>
            <dt class="text-xs text-neutral-500">{{ t('payroll.external_jmhz.col_source') }}</dt>
            <dd class="mt-0.5 text-neutral-900">{{ sourceLabel(detail) }}</dd>
          </div>
          <div v-if="detail.submission_guid">
            <dt class="text-xs text-neutral-500">{{ t('payroll.external_jmhz.col_guid') }}</dt>
            <dd class="mt-0.5 break-all font-mono text-xs text-neutral-900">{{ detail.submission_guid }}</dd>
          </div>
          <div v-if="detail.corrects">
            <dt class="text-xs text-neutral-500">{{ t('payroll.external_jmhz.col_corrects') }}</dt>
            <dd class="mt-0.5 text-neutral-900">
              {{ t(`payroll.external_jmhz.type.${detail.corrects.submission_type ?? 'R'}`) }}
              {{ formatPeriod(detail.corrects.period) }} · {{ formatDateTime(detail.corrects.submitted_at) }}
            </dd>
          </div>
        </dl>
        <div class="overflow-x-auto rounded-lg border border-neutral-200">
          <table class="min-w-full divide-y divide-neutral-200 text-sm">
            <thead class="bg-neutral-50 text-left text-xs font-medium text-neutral-600">
              <tr>
                <th class="px-3 py-2">#</th>
                <th class="px-3 py-2">{{ t('payroll.external_jmhz.col_person') }}</th>
                <th class="px-3 py-2">{{ t('payroll.external_jmhz.col_action') }}</th>
                <th v-if="detail.document_kind === 'registration'" class="px-3 py-2">{{ t('payroll.external_jmhz.col_effective') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="form in detail.forms" :key="form.position" data-test="external-jmhz-detail-form">
                <td class="px-3 py-2 text-xs text-neutral-500">{{ form.position }}</td>
                <td class="px-3 py-2">
                  <RouterLink
                    v-if="form.employee_id"
                    :to="personTarget(form)"
                    class="font-medium text-payroll-600 underline-offset-2 hover:underline"
                  >
                    {{ personLabel(form) }}
                  </RouterLink>
                  <span v-else class="italic text-neutral-500">
                    {{ t('payroll.external_jmhz.unmatched_form', { ref: form.source_relation_ref ?? '?' }) }}
                  </span>
                  <span v-if="form.code" class="ml-1 text-xs text-neutral-500">{{ form.code }}</span>
                  <p v-if="form.unreadable" class="text-xs text-warning-700">{{ t('payroll.external_jmhz.unreadable') }}</p>
                </td>
                <td class="px-3 py-2 text-neutral-700">{{ actionLabel(form.action) }}</td>
                <td v-if="detail.document_kind === 'registration'" class="whitespace-nowrap px-3 py-2 text-neutral-700">
                  {{ form.effective_on ? formatDate(form.effective_on) : '—' }}
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </Modal>

    <Modal
      v-if="confirmTarget"
      :title="t('payroll.external_jmhz.remove_dialog.title')"
      width-class="max-w-lg"
      @close="confirmTarget = null"
    >
      <div class="space-y-3 text-sm text-neutral-700" data-test="external-jmhz-remove-dialog">
        <p class="font-medium text-neutral-900">{{ detailTitle(confirmTarget) }}</p>
        <p v-if="!isAttestation(confirmTarget)">{{ t('payroll.external_jmhz.remove_dialog.when') }}</p>
        <p>{{ removeConsequence(confirmTarget) }}</p>
        <p v-if="!isAttestation(confirmTarget)" class="text-xs text-neutral-500">{{ t('payroll.external_jmhz.remove_dialog.restore') }}</p>
      </div>
      <template #footer>
        <div class="flex flex-wrap justify-end gap-2">
          <button
            type="button"
            :class="btnOutline('neutral')"
            class="whitespace-nowrap"
            @click="confirmTarget = null"
          >
            {{ t('common.cancel') }}
          </button>
          <button
            type="button"
            :class="btnFilled('danger')"
            class="whitespace-nowrap"
            :disabled="removing !== null"
            data-test="external-jmhz-remove-confirm"
            @click="confirmRemove"
          >
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
              <path :d="ICONS.trash" />
            </svg>
            {{ isAttestation(confirmTarget) ? t('payroll.external_jmhz.attest.revoke') : t('payroll.external_jmhz.remove_dialog.confirm') }}
          </button>
        </div>
      </template>
    </Modal>

    <Modal
      v-if="attestOpen"
      :title="t('payroll.external_jmhz.attest.title')"
      width-class="max-w-lg"
      @close="attestOpen = false"
    >
      <div class="space-y-3 text-sm text-neutral-700" data-test="external-jmhz-attest-dialog">
        <p>{{ t('payroll.external_jmhz.attest.intro') }}</p>
        <fieldset>
          <legend class="text-xs font-medium text-neutral-600">{{ t('payroll.external_jmhz.attest.periods') }}</legend>
          <div class="mt-1 flex flex-wrap gap-x-4 gap-y-1">
            <label
              v-for="period in missingPeriods"
              :key="period"
              class="inline-flex items-center gap-2 whitespace-nowrap"
            >
              <input
                v-model="attestPeriods"
                type="checkbox"
                :value="period"
                :data-test="`external-jmhz-attest-period-${period}`"
              >
              {{ formatPeriod(period) }}
            </label>
          </div>
        </fieldset>
        <label class="block">
          <span class="text-xs font-medium text-neutral-600">{{ t('payroll.external_jmhz.attest.date') }}</span>
          <DateInput
            v-model="attestDate"
            :max="todayIso()"
            class="mt-1 w-full rounded-md border border-neutral-300 px-3 py-2 text-sm"
            data-test="external-jmhz-attest-date" />
        </label>
        <label class="block">
          <span class="text-xs font-medium text-neutral-600">{{ t('payroll.external_jmhz.attest.note') }}</span>
          <input
            v-model="attestNote"
            type="text"
            maxlength="500"
            :placeholder="t('payroll.external_jmhz.attest.note_placeholder')"
            class="mt-1 w-full rounded-md border border-neutral-300 px-3 py-2 text-sm"
            data-test="external-jmhz-attest-note"
          >
        </label>
        <p class="text-xs text-neutral-500">{{ t('payroll.external_jmhz.attest.consequence') }}</p>
        <p
          v-if="attestError"
          class="rounded-lg border border-danger-500/30 bg-danger-50 p-2 text-danger-700"
          role="alert"
        >
          {{ attestError }}
        </p>
      </div>
      <template #footer>
        <div class="flex flex-wrap justify-end gap-2">
          <button
            type="button"
            :class="btnOutline('neutral')"
            class="whitespace-nowrap"
            @click="attestOpen = false"
          >
            {{ t('common.cancel') }}
          </button>
          <button
            type="button"
            :class="btnFilled('success')"
            class="whitespace-nowrap"
            :disabled="attesting || attestPeriods.length === 0 || attestDate === ''"
            data-test="external-jmhz-attest-confirm"
            @click="confirmAttest"
          >
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
              <path :d="ICONS.check" />
            </svg>
            {{ t('payroll.external_jmhz.attest.confirm', { count: attestPeriods.length }) }}
          </button>
        </div>
      </template>
    </Modal>
  </section>
</template>

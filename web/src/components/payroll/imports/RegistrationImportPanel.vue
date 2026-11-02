<script setup lang="ts">
import DateInput from '@/components/ui/DateInput.vue'
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { personalNumberLabel } from '@/pages/payroll/employmentLifecycleUi'
import { RouterLink } from 'vue-router'
import {
  payrollImportsApi,
  type RegistrationApplyResult,
  type RegistrationAverageStatus,
  type RegistrationEnvironment,
  type RegistrationHistory,
  type RegistrationMatchStatus,
  type RegistrationOpeningBalanceStatus,
  type RegistrationOperation,
  type RegistrationPreview,
  type RegistrationRecord,
  type RegistrationResultStatus,
  type RegistrationSubmissionType,
  type RegistrationTakeoverStatus,
} from '@/api/payrollImports'
import { payrollApi } from '@/api/payroll'
import { apiErrorMessage } from '@/api/errors'
import { payrollWorkingPeriod } from '@/pages/payroll/payrollComponentsUi'
import { useToast } from '@/composables/useToast'
import { useSubmissionEnvironment } from '@/composables/useSubmissionEnvironment'
import { formatDate, formatMoneyMinor, formatPeriod } from '@/composables/useFormat'
import { BTN_DISABLED_NOTE, btnFilled, btnOutline, btnOutlineSm, disabledTitle, ICONS } from '@/components/ui/buttonStyles'
import ImportFilesDropzone from './ImportFilesDropzone.vue'
import {
  IMPORT_XML_LIMITS,
  buildReceivedOn,
  buildRegistrationPairs,
  buildRelationChoices,
  filesFingerprint,
  filesToPayload,
  formatHours,
  hasReadyItem,
  hasTakeoverItem,
  isBenefitRecord,
  isRegistrationApplicable,
  minutesToHours,
  openingBalanceTotals,
  pruneReceivedOn,
  pruneRegistrationPairs,
  pruneRegistrationSelection,
  pruneRelationChoices,
  recordAcceptsReceivedOn,
  recordNeedsRelationChoice,
  registrationApplyBlock,
  registrationNeedsPairSelect,
  resolveHistoryToggle,
  selectableRegistrationKeys,
  setReceivedOn,
  setRegistrationPair,
  setRelationChoice,
  type RegistrationPairMap,
  type RegistrationReceivedOnMap,
  type RegistrationRelationChoiceMap,
} from './importHelpers'

const props = defineProps<{
  canWrite: boolean
}>()

const { t, te, locale } = useI18n()
const toast = useToast()

const SELECT_CLASS = 'h-8 w-full min-w-48 rounded-md border border-neutral-300 bg-surface px-2 text-sm disabled:bg-neutral-100'

const environment = ref<RegistrationEnvironment>('production')
const { testAllowed: submissionTestAllowed } = useSubmissionEnvironment(environment)
const files =ref<File[]>([])
const preview = ref<RegistrationPreview | null>(null)
const previewFingerprint = ref('')
const selected = ref<string[]>([])
const pairs = ref<RegistrationPairMap>({})
const relationChoices = ref<RegistrationRelationChoiceMap>({})
/** Den doručení podání předchozího programu podle protokolu ČSSZ (klíč věty => RRRR-MM-DD). */
const receivedOn = ref<RegistrationReceivedOnMap>({})
/** Klíče vět s potvrzeným „Ukončit vztah" podle konce pojištění z exportu. */
const terminations = ref<string[]>([])
const evidenceConfirmed = ref(false)
const applyOpenings = ref(false)
const applyAverages = ref(false)
const applyTakeover = ref(false)
const openingsTouched = ref(false)
const averagesTouched = ref(false)
const takeoverTouched = ref(false)
const autoApproveChanges = ref(true)
const autoApproveAverages = ref(true)
const result = ref<RegistrationApplyResult | null>(null)
const busy = ref<'preview' | 'apply' | null>(null)
const error = ref('')

const fingerprint = computed(() => `${environment.value}#${filesFingerprint(files.value)}`)
const records = computed<RegistrationRecord[]>(() => preview.value?.records ?? [])
const selectableKeys = computed(() => selectableRegistrationKeys(records.value))
const allSelectableSelected = computed(() =>
  selectableKeys.value.length > 0 && selectableKeys.value.every(key => selected.value.includes(key)))
const recordByKey = computed(() => new Map(records.value.map(record => [record.key, record])))
const hasJmhz = computed(() => records.value.some(isJmhz))
const employmentOptions = computed(() => preview.value?.employment_options ?? [])
const openingBalances = computed(() => preview.value?.opening_balances ?? [])
const averages = computed(() => preview.value?.averages ?? [])
const openingsReady = computed(() => hasReadyItem(openingBalances.value))
const averagesReady = computed(() => hasReadyItem(averages.value))
const takeover = computed(() => preview.value?.takeover ?? null)
const takeoverMonths = computed(() => takeover.value?.months ?? [])
const takeoverRelations = computed(() => takeover.value?.relations ?? [])
const takeoverReady = computed(() => hasTakeoverItem(takeoverMonths.value) || takeoverRelations.value.length > 0)
const showTakeoverWages = computed(() => takeoverMonths.value.length > 0 || takeoverRelations.value.length > 0)
const showTakeover = computed(() => openingBalances.value.length > 0 || averages.value.length > 0 || showTakeoverWages.value)
const historySelected = computed(() =>
  (applyOpenings.value && openingsReady.value) || (applyAverages.value && averagesReady.value)
  || (applyTakeover.value && takeoverReady.value))
const showAutoApproveChanges = computed(() => selected.value.length > 0)

const summaryItems = computed(() => {
  const items = ['total', 'create', 'update', 'terminate', 'none', 'blocked'] as const
  return (preview.value?.summary.pair_required ?? 0) > 0 ? [...items, 'pair_required' as const] : [...items]
})

const applyBlock = computed(() => registrationApplyBlock({
  hasPreview: preview.value !== null,
  selectedCount: selected.value.length,
  historySelected: historySelected.value,
  confirmed: evidenceConfirmed.value,
}))
const applyBlockedReason = computed<string>(() =>
  applyBlock.value === null ? '' : t(`payroll_imports.registration.reason.${applyBlock.value}`))
const canApply = computed(() => props.canWrite && busy.value === null && applyBlock.value === null)
const applyLabel = computed(() => {
  if (busy.value === 'apply') return t('payroll_imports.common.working')
  if (selected.value.length === 0 && historySelected.value) return t('payroll_imports.registration.apply_history_only')
  return t('payroll_imports.registration.apply', { count: selected.value.length })
})

const resultHasHistory = computed(() => {
  const value = result.value
  if (!value) return false
  return value.opening_balances.saved > 0 || value.opening_balances.skipped.length > 0
    || value.averages.created > 0 || value.averages.skipped.length > 0
    || (value.takeover !== null && (value.takeover.saved > 0 || value.takeover.skipped.length > 0))
})
const resultTakeoverCounts = computed(() => Object.entries(result.value?.takeover?.counts ?? {}))
const resultHasChecklist = computed(() => {
  const value = result.value
  if (!value) return false
  return value.change_checklist.completed > 0 || value.change_checklist.failed.length > 0
})

// Jiné soubory nebo prostředí = jiný náhled; výběr z toho starého by klamal.
watch(fingerprint, value => {
  if (preview.value && value !== previewFingerprint.value) {
    preview.value = null
    selected.value = []
    pairs.value = {}
    relationChoices.value = {}
    receivedOn.value = {}
    terminations.value = []
    evidenceConfirmed.value = false
    openingsTouched.value = false
    averagesTouched.value = false
    takeoverTouched.value = false
    autoApproveChanges.value = true
    autoApproveAverages.value = true
  }
  result.value = null
})

async function runPreview(options: { keepResult?: boolean; select?: string[] } = {}) {
  if (files.value.length === 0) return
  error.value = ''
  if (!options.keepResult) result.value = null
  busy.value = 'preview'
  try {
    const current = fingerprint.value
    const pairList = buildRegistrationPairs(pairs.value)
    const choiceList = buildRelationChoices(relationChoices.value)
    const receivedList = buildReceivedOn(receivedOn.value)
    const payload = {
      environment: environment.value,
      files: await filesToPayload(files.value),
      ...(pairList.length > 0 ? { pairs: pairList } : {}),
      ...(choiceList.length > 0 ? { relation_types: choiceList } : {}),
      ...(terminations.value.length > 0 ? { terminations: [...terminations.value] } : {}),
      ...(receivedList.length > 0 ? { received_on: receivedList } : {}),
    }
    const response = await payrollImportsApi.previewRegistrations(payload)
    preview.value = response
    previewFingerprint.value = current
    pairs.value = pruneRegistrationPairs(pairs.value, response.records, response.employment_options ?? [])
    relationChoices.value = pruneRelationChoices(relationChoices.value, response.records)
    receivedOn.value = pruneReceivedOn(receivedOn.value, response.records)
    terminations.value = terminations.value.filter(key => response.records.some(
      record => record.key === key && record.termination_offer,
    ))
    const wanted = [...selected.value, ...(options.select ?? []).filter(key => !selected.value.includes(key))]
    selected.value = pruneRegistrationSelection(wanted, response.records)
    // Předvýběr jen u čerstvého náhledu; po zápisu se znovu vybírá vědomě.
    if (selected.value.length === 0 && !options.keepResult) selected.value = selectableRegistrationKeys(response.records)
    applyOpenings.value = resolveHistoryToggle(applyOpenings.value, openingsTouched.value, hasReadyItem(response.opening_balances ?? []))
    applyAverages.value = resolveHistoryToggle(applyAverages.value, averagesTouched.value, hasReadyItem(response.averages ?? []))
    applyTakeover.value = resolveHistoryToggle(applyTakeover.value, takeoverTouched.value,
      hasTakeoverItem(response.takeover?.months ?? []) || (response.takeover?.relations.length ?? 0) > 0)
  } catch (err) {
    error.value = apiErrorMessage(err, t('payroll_imports.registration.preview_failed'))
  } finally {
    busy.value = null
  }
}

/*
 * Hlášení JMHZ ani export ČSSZ zdravotní pojišťovnu nenesou. Po zápisu se proto
 * zeptá, kolik lidí ji nemá, a výsledek nabídne hromadné zadání — jinak by se
 * na to přišlo až chybou výpočtu v mzdovém běhu.
 */
const healthMissing = ref(0)

async function loadHealthMissing() {
  try {
    const preview = await payrollApi.healthInsurerBulkPreview(`${payrollWorkingPeriod()}-01`)
    healthMissing.value = preview.people.length
  } catch {
    healthMissing.value = 0
  }
}

async function runApply() {
  if (!canApply.value) return
  error.value = ''
  busy.value = 'apply'
  try {
    const keys = [...selected.value]
    const response = await payrollImportsApi.applyRegistrations({
      environment: environment.value,
      files: await filesToPayload(files.value),
      keys,
      evidence_confirmed: evidenceConfirmed.value,
      office_id: null,
      pairs: buildRegistrationPairs(pairs.value),
      apply_opening_balances: applyOpenings.value && openingsReady.value,
      apply_averages: applyAverages.value && averagesReady.value,
      auto_approve_changes: keys.length > 0 && autoApproveChanges.value,
      auto_approve_averages: applyAverages.value && averagesReady.value && autoApproveAverages.value,
      apply_takeover: applyTakeover.value && takeoverReady.value,
      relation_types: buildRelationChoices(relationChoices.value),
      terminations: [...terminations.value],
      received_on: buildReceivedOn(receivedOn.value),
    })
    result.value = response
    void loadHealthMissing()
    const summary = response.summary
    if (response.outcome === 'incomplete' && response.unresolved.length > 0) {
      toast.warning(t('payroll_imports.registration.import_review.incomplete_toast', { count: response.unresolved.length }))
    } else if (keys.length === 0) {
      toast.success(t('payroll_imports.registration.takeover_applied', {
        months: response.takeover?.saved ?? 0,
        saved: response.opening_balances.saved,
        created: response.averages.created,
      }))
    } else if (summary.failed > 0) toast.warning(t('payroll_imports.registration.applied_with_errors', summary))
    else toast.success(t('payroll_imports.registration.applied', summary))
    evidenceConfirmed.value = false
    // Nový náhled ukáže stav po zápisu (založené osoby už vyjdou jako shoda).
    selected.value = []
    busy.value = null
    await runPreview({ keepResult: true })
  } catch (err) {
    error.value = apiErrorMessage(err, t('payroll_imports.registration.apply_failed'))
  } finally {
    busy.value = null
  }
}

/**
 * Export ČSSZ uvádí konec pojistného vztahu u vztahu, který evidence vede jako
 * trvající. Ukončení se zapíše jen po výslovném potvrzení; náhled se přepočítá,
 * aby u věty bylo vidět „Skončení vztahu" jako změna.
 */
async function toggleTermination(record: RegistrationRecord, checked: boolean) {
  terminations.value = checked
    ? [...terminations.value.filter(key => key !== record.key), record.key]
    : terminations.value.filter(key => key !== record.key)
  await runPreview({ keepResult: true, select: checked ? [record.key] : [] })
}

async function pairRecord(record: RegistrationRecord, value: string) {
  const employmentId = value === '' ? null : Number(value)
  pairs.value = setRegistrationPair(pairs.value, record.key, employmentId)
  // Náhled se přepočítá se spárováním — mění se operace věty i počáteční stavy.
  await runPreview({ keepResult: true, select: employmentId === null ? [] : [record.key] })
}

async function chooseRelation(record: RegistrationRecord, value: string) {
  relationChoices.value = setRelationChoice(relationChoices.value, record, value)
  // Druh vztahu mění plán věty (podmínky, druh činnosti) — náhled se přepočítá.
  await runPreview({ keepResult: true, select: [record.key] })
}

async function chooseReceivedOn(record: RegistrationRecord, value: string) {
  receivedOn.value = setReceivedOn(receivedOn.value, record.key, value)
  // Den doručení rozhoduje, zda jde OZUSPOJ zapsat, takže se náhled přepočítá.
  await runPreview({ keepResult: true, select: value === '' ? [] : [record.key] })
}

function receivedOnValue(record: RegistrationRecord): string {
  return receivedOn.value[record.key] ?? record.benefit?.received_on ?? ''
}

function benefitPeriod(record: RegistrationRecord): string | null {
  const benefit = record.benefit
  if (!benefit || (!benefit.incapacity_from && !benefit.incapacity_to)) return null
  return t('payroll_imports.registration.benefit.period', {
    from: dateText(benefit.incapacity_from),
    to: dateText(benefit.incapacity_to),
  })
}

function relationChoiceValue(record: RegistrationRecord): string {
  return relationChoices.value[record.key] ?? record.employment.relation_type ?? ''
}

/** Formulář, který dokládá další (souběžný) vztah téže osoby a čeká na jeho založení. */
function isConcurrentEmployment(record: RegistrationRecord): boolean {
  return isJmhz(record) && record.operation === 'create_employment'
}

function recordAnchor(key: string): string {
  return `registration-record-${key.replace(/[^0-9a-z]/gi, '-')}`
}

function focusRecord(key: string) {
  const row = document.getElementById(recordAnchor(key)) ?? document.getElementById(`${recordAnchor(key)}-card`)
  row?.scrollIntoView({ behavior: 'smooth', block: 'center' })
}

function toggle(key: string) {
  selected.value = selected.value.includes(key)
    ? selected.value.filter(item => item !== key)
    : [...selected.value, key]
}

function toggleAll() {
  selected.value = allSelectableSelected.value ? [] : [...selectableKeys.value]
}

function onOpeningsToggle(value: boolean) {
  applyOpenings.value = value
  openingsTouched.value = true
}

function onAveragesToggle(value: boolean) {
  applyAverages.value = value
  averagesTouched.value = true
}

function onTakeoverToggle(value: boolean) {
  applyTakeover.value = value
  takeoverTouched.value = true
}

function takeoverCountLabel(key: string, count: number): string {
  const label = `payroll_imports.registration.result_history.takeover_counts.${key}`
  return te(label) ? t(label, { count }) : `${key}: ${count}`
}

function isJmhz(record: RegistrationRecord): boolean {
  return record.document_type === 'JMHZ'
}

/** Kód formuláře ČSSZ zůstává kódem; export zaměstnanců žádný kód nemá, dostane název. */
function documentTypeLabel(code: string): string {
  const key = `payroll_imports.registration.document_types.${code}`
  return te(key) ? t(key) : code
}

function needsPairSelect(record: RegistrationRecord): boolean {
  return registrationNeedsPairSelect(record, pairs.value)
}

function pairValue(record: RegistrationRecord): string {
  const paired = pairs.value[record.key]
  if (paired !== undefined) return String(paired)
  return record.match.matched_by === 'manual' && record.match.employment_id !== null ? String(record.match.employment_id) : ''
}

function candidateIds(record: RegistrationRecord): Set<number> {
  return new Set(record.match.candidates.map(candidate => candidate.employment_id))
}

function relationLabel(record: RegistrationRecord): string {
  const code = personalNumberLabel(t, record.match.employment_code)
  return code ? `${record.match.employee_name ?? ''} · ${code}` : (record.match.employee_name ?? '')
}

function recordName(record: RegistrationRecord): string {
  return isJmhz(record) && record.match.employee_name ? relationLabel(record) : record.person.full_name
}

function matchClass(status: RegistrationMatchStatus): string {
  return {
    new: 'bg-payroll-50 text-payroll-700',
    matched: 'bg-success-50 text-success-700',
    ambiguous: 'bg-warning-50 text-warning-700',
    not_found: 'bg-danger-50 text-danger-600',
  }[status]
}

function operationClass(operation: RegistrationOperation): string {
  switch (operation) {
    case 'create_person':
    case 'create_employment':
    case 'create_case':
      return 'bg-primary-50 text-primary-700'
    case 'update':
    case 'assign_identifiers':
    case 'update_case':
    case 'import_intent':
      return 'bg-accent-50 text-accent-700'
    case 'terminate':
    case 'pair_required':
      return 'bg-warning-50 text-warning-700'
    case 'unsupported':
      return 'bg-danger-50 text-danger-600'
    default:
      return 'bg-neutral-100 text-neutral-600'
  }
}

function submissionClass(type: RegistrationSubmissionType): string {
  return {
    R: 'bg-neutral-100 text-neutral-700',
    O: 'bg-accent-50 text-accent-700',
    S: 'bg-warning-50 text-warning-700',
  }[type]
}

function takeoverStatusClass(status: RegistrationOpeningBalanceStatus | RegistrationAverageStatus | RegistrationTakeoverStatus): string {
  if (status === 'ready') return 'bg-success-50 text-success-700'
  if (status === 'partial') return 'bg-warning-50 text-warning-700'
  if (status === 'blocked') return 'bg-danger-50 text-danger-600'
  return 'bg-neutral-100 text-neutral-600'
}

function resultClass(status: RegistrationResultStatus): string {
  return {
    applied: 'bg-success-50 text-success-700',
    failed: 'bg-danger-50 text-danger-600',
    skipped: 'bg-neutral-100 text-neutral-600',
  }[status]
}

function dateText(value: string | null): string {
  return value ? formatDate(value) : '—'
}

function operationLabel(operation: string): string {
  const key = `payroll_imports.registration.result_operations.${operation}`
  return te(key) ? t(key) : operation
}

/** Klíč povinnosti checklistu je stejný napříč mzdovým jádrem — sdílený překlad, ne duplikát. */
function checklistItemLabel(itemKey: string): string {
  const key = `payroll.people.checklist.${itemKey}`
  return te(key) ? t(key) : itemKey
}

function historyRows(history: RegistrationHistory): { key: string; label: string; value: string }[] {
  return [
    { key: 'gross', label: t('payroll_imports.registration.history.gross'), value: formatMoneyMinor(history.gross_minor) },
    { key: 'tax_base', label: t('payroll_imports.registration.history.tax_base'), value: formatMoneyMinor(history.tax_base_minor) },
    { key: 'advance_tax', label: t('payroll_imports.registration.history.advance_tax'), value: formatMoneyMinor(history.advance_tax_minor) },
    { key: 'bonus', label: t('payroll_imports.registration.history.bonus'), value: formatMoneyMinor(history.bonus_minor) },
    { key: 'social_base', label: t('payroll_imports.registration.history.social_base'), value: formatMoneyMinor(history.social_base_minor) },
    { key: 'worked_hours', label: t('payroll_imports.registration.history.worked_hours'), value: formatHours(history.worked_hours, locale.value) },
    { key: 'average_hourly', label: t('payroll_imports.registration.history.average_hourly'), value: formatMoneyMinor(history.average_hourly_minor) },
  ]
}
</script>

<template>
  <section class="space-y-4" data-testid="registration-import">
    <div>
      <h2 class="text-lg font-semibold text-neutral-900">{{ t('payroll_imports.registration.title') }}</h2>
      <p class="mt-1 max-w-3xl text-sm text-neutral-500">{{ t('payroll_imports.registration.hint') }}</p>
    </div>

    <p v-if="!canWrite" class="rounded-lg border border-warning-500/30 bg-warning-50 px-4 py-3 text-sm text-warning-700">
      {{ t('payroll_imports.registration.no_permission') }}
    </p>

    <section class="rounded-xl border border-neutral-200 bg-surface p-4 shadow-sm sm:p-6">
      <fieldset v-if="submissionTestAllowed" class="mb-4">
        <legend class="mb-2 text-xs font-medium text-neutral-600">{{ t('payroll_imports.registration.environment') }}</legend>
        <div class="flex flex-wrap gap-4">
          <label v-for="env in (['production', 'test'] as const)" :key="env" class="inline-flex items-center gap-2 text-sm text-neutral-700">
            <input v-model="environment" type="radio" :value="env" class="border-neutral-300 text-payroll-600" :disabled="!canWrite || busy !== null">
            {{ t(`payroll_imports.registration.environments.${env}`) }}
          </label>
        </div>
        <p class="mt-1 text-xs text-neutral-500">{{ t('payroll_imports.registration.environment_hint') }}</p>
      </fieldset>

      <ImportFilesDropzone
        v-model:files="files"
        test-id="registration-dropzone"
        accept=".xml"
        :allowed-extensions="['xml']"
        :limits="IMPORT_XML_LIMITS"
        :drop-hint="t('payroll_imports.registration.drop_hint')"
        :disabled="!canWrite || busy !== null"
      />

      <div class="mt-4 flex flex-wrap items-center gap-2">
        <button
          type="button"
          data-testid="registration-preview"
          :class="btnOutline('primary')"
          :disabled="!canWrite || busy !== null || files.length === 0"
          :title="disabledTitle(files.length === 0, t('payroll_imports.registration.reason.no_files'))"
          @click="runPreview()"
        >
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.search" /></svg>
          {{ busy === 'preview' ? t('payroll_imports.common.working') : t(preview ? 'payroll_imports.registration.refresh_preview' : 'payroll_imports.registration.preview') }}
        </button>
      </div>

      <p v-if="error" role="alert" class="mt-4 rounded-lg border border-danger-500/30 bg-danger-50 px-4 py-3 text-sm text-danger-700">{{ error }}</p>
    </section>

    <template v-if="preview">
      <section class="space-y-3">
        <div v-if="preview.files.length" class="overflow-hidden rounded-lg border border-neutral-200 bg-surface">
          <ul class="divide-y divide-neutral-100">
            <li v-for="file in preview.files" :key="file.sha256 || file.name" class="px-3 py-2 text-sm">
              <div class="flex flex-wrap items-center justify-between gap-2">
                <span class="min-w-0 truncate font-medium text-neutral-800" :title="file.name">{{ file.name }}</span>
                <span class="flex flex-wrap items-center gap-2 text-xs">
                  <span v-if="file.document_type" class="rounded-full bg-neutral-100 px-2 py-0.5 text-neutral-700" :class="{ 'font-mono': file.document_type !== 'CSSZ_EXPORT' }">{{ documentTypeLabel(file.document_type) }}</span>
                  <span v-if="file.period" class="whitespace-nowrap text-neutral-700">{{ formatPeriod(file.period) }}</span>
                  <span v-if="file.submission_type" class="whitespace-nowrap rounded-full px-2 py-0.5" :class="submissionClass(file.submission_type)">{{ t(`payroll_imports.registration.submission_types.${file.submission_type}`) }}</span>
                  <span class="text-neutral-500">{{ t('payroll_imports.registration.record_count', { count: file.record_count }) }}</span>
                  <span v-if="file.error" class="rounded-full bg-danger-50 px-2 py-0.5 text-danger-600">{{ file.error }}</span>
                </span>
              </div>
              <ul v-if="file.warnings?.length" class="mt-1 space-y-0.5 text-xs text-warning-700">
                <li v-for="warning in file.warnings" :key="warning">{{ warning }}</li>
              </ul>
            </li>
          </ul>
        </div>

        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3" :class="summaryItems.length > 6 ? 'lg:grid-cols-7' : 'lg:grid-cols-6'">
          <article v-for="item in summaryItems" :key="item"
            class="rounded-lg p-3"
            :class="item === 'blocked' && preview.summary.blocked > 0 ? 'bg-danger-50' : item === 'pair_required' ? 'bg-warning-50' : 'bg-neutral-50'">
            <p class="text-xs text-neutral-500">{{ t(`payroll_imports.registration.summary.${item}`) }}</p>
            <p class="mt-1 text-lg font-semibold"
              :class="item === 'blocked' && preview.summary.blocked > 0 ? 'text-danger-600' : item === 'pair_required' ? 'text-warning-700' : 'text-neutral-900'">{{ preview.summary[item] ?? 0 }}</p>
          </article>
        </div>
      </section>

      <section class="rounded-xl border border-neutral-200 bg-surface shadow-sm">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-neutral-100 px-4 py-3">
          <h3 class="font-semibold text-neutral-900">{{ t('payroll_imports.registration.records_title') }}</h3>
          <div class="flex flex-wrap items-center gap-2">
            <span class="text-xs text-neutral-500">{{ t('payroll_imports.registration.selected_count', { count: selected.length, total: selectableKeys.length }) }}</span>
            <button type="button" :class="btnOutlineSm('neutral')" class="whitespace-nowrap" :disabled="selectableKeys.length === 0 || !canWrite" @click="toggleAll">
              <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="allSelectableSelected ? ICONS.x : ICONS.check" /></svg>
              {{ t(allSelectableSelected ? 'payroll_imports.registration.select_none' : 'payroll_imports.registration.select_all') }}
            </button>
          </div>
        </div>

        <p v-if="records.length === 0" class="px-4 py-6 text-sm text-neutral-500">{{ t('payroll_imports.registration.no_records') }}</p>

        <div v-else class="hidden max-h-[70vh] overflow-auto md:block">
          <table class="min-w-full divide-y divide-neutral-200 text-sm">
            <thead>
              <tr class="text-left text-xs uppercase tracking-wide text-neutral-500">
                <th class="sticky top-0 z-10 w-10 bg-surface px-3 py-2">
                  <input type="checkbox" :checked="allSelectableSelected" :disabled="selectableKeys.length === 0 || !canWrite"
                    :aria-label="t('payroll_imports.registration.select_all')" @change="toggleAll">
                </th>
                <th class="sticky top-0 z-10 bg-surface px-3 py-2">{{ t('payroll_imports.registration.columns.action') }}</th>
                <th v-if="hasJmhz" class="sticky top-0 z-10 bg-surface px-3 py-2">{{ t('payroll_imports.registration.columns.period') }}</th>
                <th class="sticky top-0 z-10 bg-surface px-3 py-2">{{ t('payroll_imports.registration.columns.person') }}</th>
                <th class="sticky top-0 z-10 bg-surface px-3 py-2">{{ t('payroll_imports.registration.columns.employment') }}</th>
                <th class="sticky top-0 z-10 bg-surface px-3 py-2">{{ t('payroll_imports.registration.columns.match') }}</th>
                <th class="sticky top-0 z-10 bg-surface px-3 py-2">{{ t('payroll_imports.registration.columns.operation') }}</th>
                <th class="sticky top-0 z-10 bg-surface px-3 py-2">{{ t('payroll_imports.registration.columns.changes') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="record in records" :id="recordAnchor(record.key)" :key="record.key" class="align-top" :class="record.blocker ? 'bg-danger-50/40' : ''">
                <td class="px-3 py-2">
                  <input v-if="isRegistrationApplicable(record)" type="checkbox" :checked="selected.includes(record.key)" :disabled="!canWrite"
                    :aria-label="t('payroll_imports.registration.select_record', { name: recordName(record) })" @change="toggle(record.key)">
                </td>
                <td class="px-3 py-2">
                  <p class="font-medium text-neutral-900">{{ record.action_label }}</p>
                  <template v-if="isJmhz(record)">
                    <p class="text-xs text-neutral-500"><span class="font-mono">{{ record.document_type }}</span></p>
                  </template>
                  <template v-else-if="isBenefitRecord(record)">
                    <p class="text-xs text-neutral-500"><span class="font-mono">{{ record.document_type }}</span></p>
                    <p v-if="record.benefit?.decision_number" class="text-xs text-neutral-500">{{ t('payroll_imports.registration.benefit.decision_number', { number: record.benefit.decision_number }) }}</p>
                    <p v-if="benefitPeriod(record)" class="text-xs text-neutral-500">{{ benefitPeriod(record) }}</p>
                  </template>
                  <template v-else>
                    <p v-if="record.document_type !== 'CSSZ_EXPORT' && record.document_type !== 'JMHZ_DERIVED'" class="text-xs text-neutral-500"><span class="font-mono">{{ record.document_type }}</span> · {{ t('payroll_imports.registration.action_code', { code: record.action_code }) }}</p>
                    <p class="text-xs text-neutral-500">{{ t('payroll_imports.registration.effective_on', { date: dateText(record.effective_on) }) }}</p>
                  </template>
                  <p class="truncate text-[11px] text-neutral-400" :title="record.file">{{ record.file }} #{{ record.sequence }}</p>
                </td>
                <td v-if="hasJmhz" class="whitespace-nowrap px-3 py-2 text-neutral-700">{{ record.period ? formatPeriod(record.period) : '—' }}</td>
                <td class="px-3 py-2">
                  <template v-if="isJmhz(record)">
                    <RouterLink v-if="record.match.employee_id" :to="{ name: 'payroll-person', params: { id: record.match.employee_id } }" class="font-medium text-payroll-600 hover:underline">
                      {{ relationLabel(record) }}
                    </RouterLink>
                    <p v-else class="font-medium text-neutral-900">{{ record.person.full_name }}</p>
                    <p v-if="record.match.employee_id" class="text-xs text-neutral-500">{{ t('payroll_imports.registration.form_person', { name: record.person.full_name }) }}</p>
                    <select v-if="needsPairSelect(record)" :class="SELECT_CLASS" class="mt-1" :value="pairValue(record)" :disabled="!canWrite || busy !== null"
                      :aria-label="t('payroll_imports.registration.pair.for', { name: record.person.full_name })"
                      @change="pairRecord(record, ($event.target as HTMLSelectElement).value)">
                      <option value="">{{ t('payroll_imports.registration.pair.placeholder') }}</option>
                      <optgroup v-if="record.match.candidates.length" :label="t('payroll_imports.registration.pair.candidates')">
                        <option v-for="candidate in record.match.candidates" :key="`c-${candidate.employment_id}`" :value="String(candidate.employment_id)">{{ candidate.label }}</option>
                      </optgroup>
                      <optgroup :label="t('payroll_imports.registration.pair.all')">
                        <option v-for="option in employmentOptions.filter(item => !candidateIds(record).has(item.employment_id))" :key="option.employment_id" :value="String(option.employment_id)">{{ option.label }}</option>
                      </optgroup>
                    </select>
                    <p v-if="isConcurrentEmployment(record)" data-testid="registration-concurrent-hint" class="mt-1 max-w-xs rounded-md bg-payroll-50 px-2 py-1 text-xs text-payroll-700">
                      <span class="font-medium">{{ t('payroll_imports.registration.import_review.concurrent_employment') }}.</span>
                      {{ t('payroll_imports.registration.import_review.concurrent_employment_hint') }}
                    </p>
                  </template>
                  <template v-else>
                    <p class="font-medium text-neutral-900">{{ record.person.full_name }}</p>
                    <p class="font-mono text-xs text-neutral-500">{{ record.person.birth_number_masked ?? '—' }}</p>
                    <p v-if="record.person.birth_date" class="text-xs text-neutral-500">{{ dateText(record.person.birth_date) }}</p>
                  </template>
                  <span v-if="record.person.has_oic" class="mt-1 inline-block rounded-full bg-neutral-100 px-2 py-0.5 text-[11px] text-neutral-600">{{ t('payroll_imports.registration.has_oic') }}</span>
                </td>
                <td class="px-3 py-2 text-xs text-neutral-600">
                  <p>{{ t('payroll_imports.registration.start_on', { date: dateText(record.employment.start_on) }) }}</p>
                  <p v-if="record.employment.end_on">{{ t('payroll_imports.registration.end_on', { date: dateText(record.employment.end_on) }) }}</p>
                  <p v-if="record.employment.relation_type && !recordNeedsRelationChoice(record)">{{ t(`payroll_imports.relation_types.${record.employment.relation_type}`) }}</p>
                  <label v-if="recordNeedsRelationChoice(record)" class="mt-1 block max-w-xs">
                    <span class="block text-[11px] font-medium text-neutral-700">{{ t('payroll_imports.registration.import_review.relation_choice_label') }}</span>
                    <select :class="SELECT_CLASS" class="mt-0.5" data-testid="registration-relation-choice" :value="relationChoiceValue(record)" :disabled="!canWrite || busy !== null"
                      :aria-label="t('payroll_imports.registration.import_review.relation_choice_for', { name: record.person.full_name })"
                      @change="chooseRelation(record, ($event.target as HTMLSelectElement).value)">
                      <option v-for="option in record.employment.relation_type_options" :key="option" :value="option">{{ t(`payroll_imports.relation_types.${option}`) }}</option>
                    </select>
                    <span class="mt-0.5 block text-[11px] text-neutral-500">{{ t('payroll_imports.registration.import_review.relation_choice_hint') }}</span>
                  </label>
                  <label v-if="record.termination_offer" class="mt-1 flex max-w-xs items-start gap-2 text-[11px] text-neutral-700">
                    <input type="checkbox" class="mt-0.5" data-testid="registration-termination" :checked="terminations.includes(record.key)" :disabled="!canWrite || busy !== null"
                      @change="toggleTermination(record, ($event.target as HTMLInputElement).checked)">
                    <span>
                      <span class="block font-medium">{{ t('payroll_imports.registration.termination.label', { date: dateText(record.termination_offer.end_on) }) }}</span>
                      <span class="block text-neutral-500">{{ t('payroll_imports.registration.termination.hint') }}</span>
                    </span>
                  </label>
                  <template v-if="record.employment.start_estimated">
                    <span data-testid="registration-start-estimated" class="mt-1 inline-block whitespace-nowrap rounded-full bg-warning-50 px-2 py-0.5 text-[11px] font-medium text-warning-700">{{ t('payroll_imports.registration.import_review.start_estimated') }}</span>
                    <p class="mt-0.5 max-w-xs text-[11px] text-neutral-500">{{ t('payroll_imports.registration.import_review.start_estimated_hint') }}</p>
                  </template>
                  <p v-if="record.employment.position_name" class="text-neutral-500">{{ record.employment.position_name }}</p>
                  <span v-if="record.employment.has_id_ppv" class="mt-1 inline-block rounded-full bg-neutral-100 px-2 py-0.5 text-[11px] text-neutral-600">{{ t('payroll_imports.registration.has_id_ppv') }}</span>
                </td>
                <td class="px-3 py-2">
                  <span class="whitespace-nowrap rounded-full px-2 py-1 text-xs font-medium" :class="matchClass(record.match.status)">{{ t(`payroll_imports.registration.match.${record.match.status}`) }}</span>
                  <span v-if="record.match.matched_by === 'manual'" class="mt-1 inline-block whitespace-nowrap rounded-full bg-payroll-50 px-2 py-0.5 text-[11px] font-medium text-payroll-700">{{ t('payroll_imports.registration.manual_pair') }}</span>
                  <p v-else-if="record.match.matched_by" class="mt-1 text-[11px] text-neutral-500">{{ t(`payroll_imports.registration.matched_by.${record.match.matched_by}`) }}</p>
                  <RouterLink v-if="record.match.employee_id && !isJmhz(record)" :to="{ name: 'payroll-person', params: { id: record.match.employee_id } }" class="mt-1 block text-xs text-payroll-600 hover:underline">
                    {{ relationLabel(record) }}
                  </RouterLink>
                  <ul v-if="record.match.candidates.length && !needsPairSelect(record)" class="mt-1 space-y-0.5 text-[11px] text-warning-700">
                    <li v-for="candidate in record.match.candidates" :key="candidate.employment_id">{{ candidate.label }}</li>
                  </ul>
                </td>
                <td class="px-3 py-2">
                  <span class="whitespace-nowrap rounded-full px-2 py-1 text-xs font-medium" :class="operationClass(record.operation)">{{ t(`payroll_imports.registration.operations.${record.operation}`) }}</span>
                  <label v-if="recordAcceptsReceivedOn(record)" class="mt-1 block max-w-[11rem]">
                    <span class="block text-[11px] font-medium text-neutral-700">{{ t(record.benefit?.needs_received_on ? 'payroll_imports.registration.benefit.received_on_required' : 'payroll_imports.registration.benefit.received_on') }}</span>
                    <DateInput class="mt-0.5 h-8 w-full rounded-md border border-neutral-300 bg-surface px-2 text-sm disabled:bg-neutral-100" data-testid="registration-received-on"
                      :model-value="receivedOnValue(record)" :disabled="!canWrite || busy !== null"
                      :aria-label="t('payroll_imports.registration.benefit.received_on_for', { name: record.person.full_name })"
                      @change="chooseReceivedOn(record, $event)" />
                  </label>
                </td>
                <td class="max-w-md px-3 py-2">
                  <ul v-if="record.changes.length" class="space-y-1 text-xs">
                    <li v-for="change in record.changes" :key="change.field">
                      <span class="font-medium text-neutral-700">{{ change.label }}:</span>
                      <span class="text-neutral-500 line-through decoration-neutral-300">{{ change.current ?? '—' }}</span>
                      <span class="text-neutral-400"> → </span>
                      <span class="font-medium text-neutral-900">{{ change.imported ?? '—' }}</span>
                    </li>
                  </ul>
                  <p v-else class="text-xs text-neutral-400">{{ t('payroll_imports.registration.no_changes') }}</p>
                  <details v-if="record.history" class="mt-1 text-xs">
                    <summary class="cursor-pointer text-payroll-600 hover:underline">{{ t('payroll_imports.registration.history.show') }}</summary>
                    <dl class="mt-1 grid grid-cols-[auto_1fr] gap-x-3 gap-y-0.5 rounded-md bg-neutral-50 p-2">
                      <template v-for="row in historyRows(record.history)" :key="row.key">
                        <dt class="text-neutral-500">{{ row.label }}</dt>
                        <dd class="text-right font-medium tabular-nums text-neutral-900">{{ row.value }}</dd>
                      </template>
                    </dl>
                  </details>
                  <ul v-if="record.warnings.length" class="mt-1 space-y-0.5 text-xs text-warning-700">
                    <li v-for="warning in record.warnings" :key="warning">{{ warning }}</li>
                  </ul>
                  <p v-if="record.blocker" class="mt-1 text-xs font-medium text-danger-600">{{ record.blocker }}</p>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <div v-if="records.length" class="space-y-2 p-3 md:hidden">
          <article v-for="record in records" :id="`${recordAnchor(record.key)}-card`" :key="record.key" class="rounded-lg border p-3 text-sm"
            :class="record.blocker ? 'border-danger-500/30 bg-danger-50/40' : 'border-neutral-200 bg-neutral-50'">
            <div class="flex items-start gap-3">
              <input v-if="isRegistrationApplicable(record)" type="checkbox" class="mt-1" :checked="selected.includes(record.key)" :disabled="!canWrite"
                :aria-label="t('payroll_imports.registration.select_record', { name: recordName(record) })" @change="toggle(record.key)">
              <div class="min-w-0 flex-1">
                <p class="font-medium text-neutral-900">{{ recordName(record) }}</p>
                <template v-if="isJmhz(record)">
                  <p class="text-xs text-neutral-500">{{ record.action_label }}</p>
                  <p v-if="record.match.employee_id" class="text-xs text-neutral-500">{{ t('payroll_imports.registration.form_person', { name: record.person.full_name }) }}</p>
                </template>
                <template v-else>
                  <p class="text-xs text-neutral-500">{{ record.action_label }} · {{ dateText(record.effective_on) }}</p>
                  <p class="font-mono text-xs text-neutral-500">{{ record.person.birth_number_masked ?? '—' }}</p>
                </template>
                <div class="mt-2 flex flex-wrap gap-1.5">
                  <span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="matchClass(record.match.status)">{{ t(`payroll_imports.registration.match.${record.match.status}`) }}</span>
                  <span v-if="record.match.matched_by === 'manual'" class="rounded-full bg-payroll-50 px-2 py-0.5 text-xs font-medium text-payroll-700">{{ t('payroll_imports.registration.manual_pair') }}</span>
                  <span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="operationClass(record.operation)">{{ t(`payroll_imports.registration.operations.${record.operation}`) }}</span>
                </div>
                <p v-if="isBenefitRecord(record) && benefitPeriod(record)" class="mt-2 text-xs text-neutral-600">{{ benefitPeriod(record) }}</p>
                <label v-if="recordAcceptsReceivedOn(record)" class="mt-2 block">
                  <span class="block text-xs font-medium text-neutral-700">{{ t(record.benefit?.needs_received_on ? 'payroll_imports.registration.benefit.received_on_required' : 'payroll_imports.registration.benefit.received_on') }}</span>
                  <DateInput class="mt-0.5 h-8 w-full rounded-md border border-neutral-300 bg-surface px-2 text-sm disabled:bg-neutral-100"
                    :model-value="receivedOnValue(record)" :disabled="!canWrite || busy !== null"
                    :aria-label="t('payroll_imports.registration.benefit.received_on_for', { name: record.person.full_name })"
                    @change="chooseReceivedOn(record, $event)" />
                </label>
                <select v-if="needsPairSelect(record)" :class="SELECT_CLASS" class="mt-2" :value="pairValue(record)" :disabled="!canWrite || busy !== null"
                  :aria-label="t('payroll_imports.registration.pair.for', { name: record.person.full_name })"
                  @change="pairRecord(record, ($event.target as HTMLSelectElement).value)">
                  <option value="">{{ t('payroll_imports.registration.pair.placeholder') }}</option>
                  <option v-for="option in employmentOptions" :key="option.employment_id" :value="String(option.employment_id)">{{ option.label }}</option>
                </select>
                <p v-if="isConcurrentEmployment(record)" class="mt-2 rounded-md bg-payroll-50 px-2 py-1 text-xs text-payroll-700">
                  <span class="font-medium">{{ t('payroll_imports.registration.import_review.concurrent_employment') }}.</span>
                  {{ t('payroll_imports.registration.import_review.concurrent_employment_hint') }}
                </p>
                <p v-if="!isJmhz(record)" class="mt-2 text-xs text-neutral-600">
                  {{ t('payroll_imports.registration.start_on', { date: dateText(record.employment.start_on) }) }}<template v-if="record.employment.end_on"> · {{ t('payroll_imports.registration.end_on', { date: dateText(record.employment.end_on) }) }}</template>
                </p>
                <label v-if="recordNeedsRelationChoice(record)" class="mt-2 block">
                  <span class="block text-xs font-medium text-neutral-700">{{ t('payroll_imports.registration.import_review.relation_choice_label') }}</span>
                  <select :class="SELECT_CLASS" class="mt-0.5" :value="relationChoiceValue(record)" :disabled="!canWrite || busy !== null"
                    :aria-label="t('payroll_imports.registration.import_review.relation_choice_for', { name: record.person.full_name })"
                    @change="chooseRelation(record, ($event.target as HTMLSelectElement).value)">
                    <option v-for="option in record.employment.relation_type_options" :key="option" :value="option">{{ t(`payroll_imports.relation_types.${option}`) }}</option>
                  </select>
                  <span class="mt-0.5 block text-xs text-neutral-500">{{ t('payroll_imports.registration.import_review.relation_choice_hint') }}</span>
                </label>
                <label v-if="record.termination_offer" class="mt-2 flex items-start gap-2 text-xs text-neutral-700">
                  <input type="checkbox" class="mt-0.5" :checked="terminations.includes(record.key)" :disabled="!canWrite || busy !== null"
                    @change="toggleTermination(record, ($event.target as HTMLInputElement).checked)">
                  <span>
                    <span class="block font-medium">{{ t('payroll_imports.registration.termination.label', { date: dateText(record.termination_offer.end_on) }) }}</span>
                    <span class="block text-neutral-500">{{ t('payroll_imports.registration.termination.hint') }}</span>
                  </span>
                </label>
                <p v-if="record.employment.start_estimated" class="mt-2 text-xs text-warning-700">
                  <span class="font-medium">{{ t('payroll_imports.registration.import_review.start_estimated') }}:</span>
                  {{ t('payroll_imports.registration.import_review.start_estimated_hint') }}
                </p>
                <ul v-if="record.changes.length" class="mt-2 space-y-1 text-xs">
                  <li v-for="change in record.changes" :key="change.field">
                    <span class="font-medium">{{ change.label }}:</span> {{ change.current ?? '—' }} → <strong>{{ change.imported ?? '—' }}</strong>
                  </li>
                </ul>
                <details v-if="record.history" class="mt-2 text-xs">
                  <summary class="cursor-pointer text-payroll-600">{{ t('payroll_imports.registration.history.show') }}</summary>
                  <dl class="mt-1 grid grid-cols-[auto_1fr] gap-x-3 gap-y-0.5 rounded-md bg-surface p-2">
                    <template v-for="row in historyRows(record.history)" :key="row.key">
                      <dt class="text-neutral-500">{{ row.label }}</dt>
                      <dd class="text-right font-medium tabular-nums text-neutral-900">{{ row.value }}</dd>
                    </template>
                  </dl>
                </details>
                <ul v-if="record.warnings.length" class="mt-2 space-y-0.5 text-xs text-warning-700">
                  <li v-for="warning in record.warnings" :key="warning">{{ warning }}</li>
                </ul>
                <p v-if="record.blocker" class="mt-2 text-xs font-medium text-danger-600">{{ record.blocker }}</p>
              </div>
            </div>
          </article>
        </div>
      </section>

      <section v-if="showTakeover" class="space-y-4 rounded-xl border border-neutral-200 bg-surface p-4 shadow-sm sm:p-6" data-testid="registration-takeover">
        <div>
          <h3 class="font-semibold text-neutral-900">{{ t('payroll_imports.registration.takeover.title') }}</h3>
          <p class="mt-1 max-w-3xl text-sm text-neutral-500">{{ t('payroll_imports.registration.takeover.hint') }}</p>
        </div>

        <div v-if="showTakeoverWages" class="space-y-3" data-testid="registration-takeover-wages">
          <div>
            <h4 class="text-sm font-medium text-neutral-800">{{ t('payroll_imports.registration.takeover.wages_title') }}</h4>
            <p class="mt-1 max-w-3xl text-xs text-neutral-500">{{ t('payroll_imports.registration.takeover.wages_hint') }}</p>
            <p class="mt-2 max-w-3xl rounded-md bg-warning-50 px-3 py-2 text-xs text-warning-800" data-testid="registration-takeover-missing">{{ t('payroll_imports.registration.takeover.missing_data_hint') }}</p>
            <p v-if="takeover?.start_period" class="mt-1 text-xs text-neutral-600">{{ t('payroll_imports.registration.takeover.start_period', { period: formatPeriod(takeover.start_period) }) }}</p>
            <p v-else class="mt-1 text-xs font-medium text-warning-700">{{ t('payroll_imports.registration.takeover.no_start_period') }}</p>
          </div>

          <div v-if="takeoverMonths.length" class="space-y-2">
            <h5 class="text-xs font-medium uppercase tracking-wide text-neutral-500">{{ t('payroll_imports.registration.takeover.months_title') }}</h5>
            <div class="hidden overflow-x-auto rounded-lg border border-neutral-200 md:block">
              <table class="min-w-full divide-y divide-neutral-200 text-sm">
                <thead>
                  <tr class="text-left text-xs uppercase tracking-wide text-neutral-500">
                    <th class="px-3 py-2">{{ t('payroll_imports.registration.takeover.wage_columns.period') }}</th>
                    <th class="px-3 py-2 text-right">{{ t('payroll_imports.registration.takeover.wage_columns.count') }}</th>
                    <th class="px-3 py-2 text-right">{{ t('payroll_imports.registration.takeover.wage_columns.gross') }}</th>
                    <th class="px-3 py-2 text-right">{{ t('payroll_imports.registration.takeover.wage_columns.net') }}</th>
                    <th class="px-3 py-2 text-right">{{ t('payroll_imports.registration.takeover.wage_columns.advance_tax') }}</th>
                    <th class="px-3 py-2">{{ t('payroll_imports.registration.takeover.wage_columns.status') }}</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-neutral-100">
                  <tr v-for="month in takeoverMonths" :key="month.period" class="align-top">
                    <td class="whitespace-nowrap px-3 py-2 font-medium text-neutral-900">{{ formatPeriod(month.period) }}</td>
                    <td class="px-3 py-2 text-right tabular-nums">{{ month.ready_count }}</td>
                    <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums">{{ formatMoneyMinor(month.gross_minor) }}</td>
                    <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums">{{ formatMoneyMinor(month.net_minor) }}</td>
                    <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums">{{ formatMoneyMinor(month.advance_tax_minor) }}</td>
                    <td class="px-3 py-2">
                      <span class="whitespace-nowrap rounded-full px-2 py-1 text-xs font-medium" :class="takeoverStatusClass(month.status)">{{ t(`payroll_imports.registration.takeover.month_status.${month.status}`) }}</span>
                      <p v-if="month.reason" class="mt-1 max-w-xs text-xs text-neutral-600">{{ month.reason }}</p>
                      <details v-if="month.blocked.length" class="mt-1 text-xs">
                        <summary class="cursor-pointer text-warning-700">{{ t('payroll_imports.registration.takeover.blocked_count', { count: month.blocked.length }) }}</summary>
                        <ul class="mt-1 space-y-0.5 text-neutral-600">
                          <li v-for="(item, index) in month.blocked" :key="`${month.period}-b-${index}`"><strong class="font-medium">{{ item.label }}</strong>: {{ item.reason }}</li>
                        </ul>
                      </details>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
            <div class="space-y-2 md:hidden">
              <article v-for="month in takeoverMonths" :key="`m-${month.period}`" class="rounded-lg border border-neutral-200 bg-neutral-50 p-3 text-sm">
                <div class="flex flex-wrap items-start justify-between gap-2">
                  <p class="font-medium text-neutral-900">{{ formatPeriod(month.period) }} · {{ month.ready_count }}</p>
                  <span class="whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-medium" :class="takeoverStatusClass(month.status)">{{ t(`payroll_imports.registration.takeover.month_status.${month.status}`) }}</span>
                </div>
                <p class="mt-1 text-xs text-neutral-600">{{ t('payroll_imports.registration.takeover.wage_columns.gross') }}: <strong class="tabular-nums">{{ formatMoneyMinor(month.gross_minor) }}</strong></p>
                <p class="text-xs text-neutral-600">{{ t('payroll_imports.registration.takeover.wage_columns.advance_tax') }}: <strong class="tabular-nums">{{ formatMoneyMinor(month.advance_tax_minor) }}</strong></p>
                <p v-if="month.reason" class="mt-1 text-xs text-neutral-600">{{ month.reason }}</p>
                <ul v-if="month.blocked.length" class="mt-1 space-y-0.5 text-xs text-warning-700">
                  <li v-for="(item, index) in month.blocked" :key="`mm-${month.period}-${index}`">{{ item.label }}: {{ item.reason }}</li>
                </ul>
              </article>
            </div>
          </div>

          <div v-if="takeoverRelations.length" class="space-y-2">
            <h5 class="text-xs font-medium uppercase tracking-wide text-neutral-500">{{ t('payroll_imports.registration.takeover.relations_title') }}</h5>
            <div class="hidden overflow-x-auto rounded-lg border border-neutral-200 md:block">
              <table class="min-w-full divide-y divide-neutral-200 text-sm">
                <thead>
                  <tr class="text-left text-xs uppercase tracking-wide text-neutral-500">
                    <th class="px-3 py-2">{{ t('payroll_imports.registration.takeover.wage_columns.employment') }}</th>
                    <th class="px-3 py-2">{{ t('payroll_imports.registration.takeover.wage_columns.start') }}</th>
                    <th class="px-3 py-2">{{ t('payroll_imports.registration.takeover.wage_columns.end') }}</th>
                    <th class="px-3 py-2 text-right">{{ t('payroll_imports.registration.takeover.wage_columns.monthly_wage') }}</th>
                    <th class="px-3 py-2">{{ t('payroll_imports.registration.takeover.wage_columns.averages') }}</th>
                    <th class="px-3 py-2 text-right">{{ t('payroll_imports.registration.takeover.wage_columns.leave') }}</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-neutral-100">
                  <tr v-for="relation in takeoverRelations" :key="relation.employment_id" class="align-top">
                    <td class="px-3 py-2">
                      <RouterLink :to="{ name: 'payroll-person', params: { id: relation.employee_id } }" class="font-medium text-payroll-600 hover:underline">{{ relation.label }}</RouterLink>
                      <p v-if="relation.deductions_recorded" class="mt-0.5 text-[11px] text-warning-700" :data-testid="`takeover-deductions-${relation.employment_id}`">{{ t('payroll_imports.registration.takeover.deductions_recorded') }}</p>
                      <p v-if="relation.sickness_review" class="mt-0.5 text-[11px] text-warning-700" :data-testid="`takeover-sickness-${relation.employment_id}`">{{ t('payroll_imports.registration.takeover.sickness_review') }}</p>
                    </td>
                    <td class="whitespace-nowrap px-3 py-2">{{ dateText(relation.start_on) }}</td>
                    <td class="whitespace-nowrap px-3 py-2">{{ dateText(relation.end_on) }}</td>
                    <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums">
                      {{ relation.monthly_wage_minor === null ? '—' : formatMoneyMinor(relation.monthly_wage_minor) }}
                      <p v-if="relation.monthly_wage_from" class="text-[11px] font-normal text-neutral-500">{{ t('payroll_imports.registration.takeover.wage_from', { date: dateText(relation.monthly_wage_from) }) }}</p>
                    </td>
                    <td class="px-3 py-2 text-xs text-neutral-700">{{ relation.average_quarters.length ? relation.average_quarters.join(', ') : '—' }}</td>
                    <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums">{{ relation.leave_minutes > 0 ? t('payroll_imports.registration.takeover.leave_hours', { hours: formatHours(minutesToHours(relation.leave_minutes), locale) }) : '—' }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
            <div class="space-y-2 md:hidden">
              <article v-for="relation in takeoverRelations" :key="`r-${relation.employment_id}`" class="rounded-lg border border-neutral-200 bg-neutral-50 p-3 text-sm">
                <p class="font-medium text-neutral-900">{{ relation.label }}</p>
                <p class="mt-1 text-xs text-neutral-600">{{ t('payroll_imports.registration.takeover.wage_columns.start') }}: {{ dateText(relation.start_on) }}<template v-if="relation.end_on"> · {{ t('payroll_imports.registration.takeover.wage_columns.end') }}: {{ dateText(relation.end_on) }}</template></p>
                <p v-if="relation.monthly_wage_minor !== null" class="text-xs text-neutral-600">{{ t('payroll_imports.registration.takeover.wage_columns.monthly_wage') }}: <strong class="tabular-nums">{{ formatMoneyMinor(relation.monthly_wage_minor) }}</strong></p>
                <p v-if="relation.average_quarters.length" class="text-xs text-neutral-600">{{ t('payroll_imports.registration.takeover.wage_columns.averages') }}: {{ relation.average_quarters.join(', ') }}</p>
                <p v-if="relation.leave_minutes > 0" class="text-xs text-neutral-600">{{ t('payroll_imports.registration.takeover.wage_columns.leave') }}: {{ t('payroll_imports.registration.takeover.leave_hours', { hours: formatHours(minutesToHours(relation.leave_minutes), locale) }) }}</p>
                <p v-if="relation.deductions_recorded" class="mt-1 text-xs text-warning-700">{{ t('payroll_imports.registration.takeover.deductions_recorded') }}</p>
                <p v-if="relation.sickness_review" class="mt-1 text-xs text-warning-700">{{ t('payroll_imports.registration.takeover.sickness_review') }}</p>
              </article>
            </div>
          </div>

          <label class="flex items-start gap-2 text-sm text-neutral-800">
            <input type="checkbox" data-testid="registration-apply-takeover" class="mt-0.5 rounded border-neutral-300 text-payroll-600"
              :checked="applyTakeover" :disabled="!canWrite || busy !== null || !takeoverReady"
              @change="onTakeoverToggle(($event.target as HTMLInputElement).checked)">
            <span>
              <span class="font-medium">{{ t('payroll_imports.registration.takeover.apply_wages') }}</span>
              <span v-if="!takeoverReady" class="mt-0.5 block text-xs text-neutral-500">{{ t('payroll_imports.registration.takeover.nothing_ready') }}</span>
            </span>
          </label>
        </div>

        <div v-if="openingBalances.length" class="space-y-2">
          <h4 class="text-sm font-medium text-neutral-800">{{ t('payroll_imports.registration.takeover.openings_title') }}</h4>
          <div class="hidden overflow-x-auto rounded-lg border border-neutral-200 md:block">
            <table class="min-w-full divide-y divide-neutral-200 text-sm">
              <thead>
                <tr class="text-left text-xs uppercase tracking-wide text-neutral-500">
                  <th class="px-3 py-2">{{ t('payroll_imports.registration.takeover.columns.person') }}</th>
                  <th class="px-3 py-2">{{ t('payroll_imports.registration.takeover.columns.year') }}</th>
                  <th class="px-3 py-2 text-right">{{ t('payroll_imports.registration.takeover.columns.months') }}</th>
                  <th class="px-3 py-2 text-right">{{ t('payroll_imports.registration.takeover.columns.tax_base') }}</th>
                  <th class="px-3 py-2 text-right">{{ t('payroll_imports.registration.takeover.columns.advance_tax') }}</th>
                  <th class="px-3 py-2">{{ t('payroll_imports.registration.takeover.columns.status') }}</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-neutral-100">
                <tr v-for="balance in openingBalances" :key="`${balance.employee_id}-${balance.year}`" class="align-top">
                  <td class="px-3 py-2">
                    <RouterLink :to="{ name: 'payroll-person', params: { id: balance.employee_id } }" class="font-medium text-payroll-600 hover:underline">{{ balance.employee_name }}</RouterLink>
                  </td>
                  <td class="px-3 py-2 tabular-nums">{{ balance.year }}</td>
                  <td class="px-3 py-2 text-right tabular-nums">{{ openingBalanceTotals(balance).months }}</td>
                  <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums">{{ formatMoneyMinor(openingBalanceTotals(balance).advance_base_minor) }}</td>
                  <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums">
                    {{ formatMoneyMinor(openingBalanceTotals(balance).advance_tax_minor) }}
                    <p v-if="openingBalanceTotals(balance).withholding_base_minor > 0" class="text-[11px] font-normal text-neutral-500">
                      {{ t('payroll_imports.registration.takeover.withholding', { base: formatMoneyMinor(openingBalanceTotals(balance).withholding_base_minor), tax: formatMoneyMinor(openingBalanceTotals(balance).withholding_tax_minor) }) }}
                    </p>
                  </td>
                  <td class="px-3 py-2">
                    <span class="whitespace-nowrap rounded-full px-2 py-1 text-xs font-medium" :class="takeoverStatusClass(balance.status)">{{ t(`payroll_imports.registration.takeover.opening_status.${balance.status}`) }}</span>
                    <p v-if="balance.reason" class="mt-1 max-w-xs text-xs text-neutral-600">{{ balance.reason }}</p>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
          <div class="space-y-2 md:hidden">
            <article v-for="balance in openingBalances" :key="`${balance.employee_id}-${balance.year}`" class="rounded-lg border border-neutral-200 bg-neutral-50 p-3 text-sm">
              <div class="flex flex-wrap items-start justify-between gap-2">
                <p class="font-medium text-neutral-900">{{ balance.employee_name }} · {{ balance.year }}</p>
                <span class="whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-medium" :class="takeoverStatusClass(balance.status)">{{ t(`payroll_imports.registration.takeover.opening_status.${balance.status}`) }}</span>
              </div>
              <p class="mt-1 text-xs text-neutral-600">{{ t('payroll_imports.registration.takeover.months_count', { count: openingBalanceTotals(balance).months }) }}</p>
              <p class="text-xs text-neutral-600">{{ t('payroll_imports.registration.takeover.columns.tax_base') }}: <strong class="tabular-nums">{{ formatMoneyMinor(openingBalanceTotals(balance).advance_base_minor) }}</strong></p>
              <p class="text-xs text-neutral-600">{{ t('payroll_imports.registration.takeover.columns.advance_tax') }}: <strong class="tabular-nums">{{ formatMoneyMinor(openingBalanceTotals(balance).advance_tax_minor) }}</strong></p>
              <p v-if="balance.reason" class="mt-1 text-xs text-neutral-600">{{ balance.reason }}</p>
            </article>
          </div>
          <label class="flex items-start gap-2 text-sm text-neutral-800">
            <input type="checkbox" data-testid="registration-apply-openings" class="mt-0.5 rounded border-neutral-300 text-payroll-600"
              :checked="applyOpenings" :disabled="!canWrite || busy !== null || !openingsReady"
              @change="onOpeningsToggle(($event.target as HTMLInputElement).checked)">
            <span>
              <span class="font-medium">{{ t('payroll_imports.registration.takeover.apply_openings') }}</span>
              <span v-if="!openingsReady" class="mt-0.5 block text-xs text-neutral-500">{{ t('payroll_imports.registration.takeover.nothing_ready') }}</span>
            </span>
          </label>
        </div>

        <div v-if="averages.length" class="space-y-2">
          <h4 class="text-sm font-medium text-neutral-800">{{ t('payroll_imports.registration.takeover.averages_title') }}</h4>
          <div class="hidden overflow-x-auto rounded-lg border border-neutral-200 md:block">
            <table class="min-w-full divide-y divide-neutral-200 text-sm">
              <thead>
                <tr class="text-left text-xs uppercase tracking-wide text-neutral-500">
                  <th class="px-3 py-2">{{ t('payroll_imports.registration.takeover.columns.employment') }}</th>
                  <th class="px-3 py-2">{{ t('payroll_imports.registration.takeover.columns.quarter') }}</th>
                  <th class="px-3 py-2 text-right">{{ t('payroll_imports.registration.takeover.columns.average') }}</th>
                  <th class="px-3 py-2 text-right">{{ t('payroll_imports.registration.takeover.columns.reported') }}</th>
                  <th class="px-3 py-2">{{ t('payroll_imports.registration.takeover.columns.status') }}</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-neutral-100">
                <tr v-for="average in averages" :key="`${average.employment_id}-${average.year}-${average.quarter}`" class="align-top">
                  <td class="px-3 py-2 font-medium text-neutral-900">{{ average.label }}</td>
                  <td class="whitespace-nowrap px-3 py-2">{{ t('payroll_imports.registration.takeover.quarter_label', { quarter: average.quarter, year: average.year }) }}</td>
                  <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums">
                    <span class="font-medium">{{ formatMoneyMinor(average.average_hourly_minor) }}</span>
                    <p class="text-[11px] text-neutral-500">{{ t('payroll_imports.registration.takeover.average_detail', { gross: formatMoneyMinor(average.gross_minor), hours: formatHours(minutesToHours(average.worked_minutes), locale) }) }}</p>
                  </td>
                  <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums">{{ formatMoneyMinor(average.reported_hourly_minor) }}</td>
                  <td class="px-3 py-2">
                    <span class="whitespace-nowrap rounded-full px-2 py-1 text-xs font-medium" :class="takeoverStatusClass(average.status)">{{ t(`payroll_imports.registration.takeover.average_status.${average.status}`) }}</span>
                    <p v-if="average.reason" class="mt-1 max-w-xs text-xs text-neutral-600">{{ average.reason }}</p>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
          <div class="space-y-2 md:hidden">
            <article v-for="average in averages" :key="`${average.employment_id}-${average.year}-${average.quarter}`" class="rounded-lg border border-neutral-200 bg-neutral-50 p-3 text-sm">
              <div class="flex flex-wrap items-start justify-between gap-2">
                <p class="min-w-0 font-medium text-neutral-900">{{ average.label }}</p>
                <span class="whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-medium" :class="takeoverStatusClass(average.status)">{{ t(`payroll_imports.registration.takeover.average_status.${average.status}`) }}</span>
              </div>
              <p class="mt-1 text-xs text-neutral-600">{{ t('payroll_imports.registration.takeover.quarter_label', { quarter: average.quarter, year: average.year }) }}</p>
              <p class="text-xs text-neutral-600">{{ t('payroll_imports.registration.takeover.columns.average') }}: <strong class="tabular-nums">{{ formatMoneyMinor(average.average_hourly_minor) }}</strong></p>
              <p class="text-xs text-neutral-600">{{ t('payroll_imports.registration.takeover.columns.reported') }}: <strong class="tabular-nums">{{ formatMoneyMinor(average.reported_hourly_minor) }}</strong></p>
              <p v-if="average.reason" class="mt-1 text-xs text-neutral-600">{{ average.reason }}</p>
            </article>
          </div>
          <label class="flex items-start gap-2 text-sm text-neutral-800">
            <input type="checkbox" data-testid="registration-apply-averages" class="mt-0.5 rounded border-neutral-300 text-payroll-600"
              :checked="applyAverages" :disabled="!canWrite || busy !== null || !averagesReady"
              @change="onAveragesToggle(($event.target as HTMLInputElement).checked)">
            <span>
              <span class="font-medium">{{ t('payroll_imports.registration.takeover.apply_averages') }}</span>
              <span v-if="!averagesReady" class="mt-0.5 block text-xs text-neutral-500">{{ t('payroll_imports.registration.takeover.nothing_ready') }}</span>
            </span>
          </label>
          <label class="ml-6 flex items-start gap-2 text-sm text-neutral-800">
            <input v-model="autoApproveAverages" type="checkbox" data-testid="registration-auto-approve-averages" class="mt-0.5 rounded border-neutral-300 text-payroll-600"
              :disabled="!canWrite || busy !== null || !applyAverages">
            <span>
              <span class="font-medium">{{ t('payroll_imports.registration.takeover.auto_approve_averages') }}</span>
              <span class="mt-0.5 block text-xs text-neutral-500">{{ t('payroll_imports.registration.takeover.auto_approve_averages_hint') }}</span>
            </span>
          </label>
        </div>
      </section>

      <section class="rounded-xl border border-payroll-500/30 bg-payroll-50 p-4 sm:p-6">
        <label class="flex items-start gap-2 text-sm text-neutral-800">
          <input v-model="evidenceConfirmed" type="checkbox" data-testid="registration-confirm" class="mt-0.5 rounded border-neutral-300 text-payroll-600" :disabled="!canWrite || busy !== null">
          <span>
            <span class="font-medium">{{ t('payroll_imports.registration.confirm') }}</span>
            <span class="mt-0.5 block text-xs text-neutral-600">{{ t('payroll_imports.registration.confirm_hint') }}</span>
          </span>
        </label>
        <label v-if="showAutoApproveChanges" class="mt-3 flex items-start gap-2 text-sm text-neutral-800">
          <input v-model="autoApproveChanges" type="checkbox" data-testid="registration-auto-approve-changes" class="mt-0.5 rounded border-neutral-300 text-payroll-600" :disabled="!canWrite || busy !== null">
          <span>
            <span class="font-medium">{{ t('payroll_imports.registration.auto_approve_changes') }}</span>
            <span class="mt-0.5 block text-xs text-neutral-600">{{ t('payroll_imports.registration.auto_approve_changes_hint') }}</span>
          </span>
        </label>
        <div class="mt-4 flex flex-col items-start gap-1.5">
          <button
            type="button"
            data-testid="registration-apply"
            :class="btnFilled('success')"
            class="whitespace-nowrap"
            :disabled="!canApply"
            :title="disabledTitle(!canApply, applyBlockedReason)"
            @click="runApply"
          >
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.check" /></svg>
            {{ applyLabel }}
          </button>
          <p v-if="applyBlockedReason && canWrite" :class="BTN_DISABLED_NOTE">{{ applyBlockedReason }}</p>
        </div>
      </section>
    </template>

    <section v-if="result" class="rounded-xl border border-neutral-200 bg-surface p-4 shadow-sm sm:p-6" data-testid="registration-result">
      <h3 class="font-semibold text-neutral-900">{{ t('payroll_imports.registration.result_title') }}</h3>
      <div v-if="result.outcome === 'incomplete' && result.unresolved.length" role="alert" data-testid="registration-result-incomplete"
        class="mt-3 rounded-lg border border-danger-500/30 bg-danger-50 px-4 py-3 text-sm text-danger-700">
        <p class="font-semibold">{{ t('payroll_imports.registration.import_review.incomplete_title') }}</p>
        <p class="mt-0.5 text-xs">{{ t('payroll_imports.registration.import_review.incomplete_hint') }}</p>
        <ul class="mt-2 divide-y divide-danger-500/20">
          <li v-for="item in result.unresolved" :key="`u-${item.key}`" class="flex flex-wrap items-start justify-between gap-2 py-1.5">
            <div class="min-w-0">
              <p class="font-medium">{{ item.label }} · {{ formatPeriod(item.period) }}</p>
              <p class="text-xs">{{ item.reason }}</p>
              <p class="truncate text-[11px] text-danger-600/80" :title="item.file">{{ item.file }}</p>
            </div>
            <button v-if="recordByKey.has(item.key)" type="button" :class="btnOutlineSm('warning')" class="whitespace-nowrap" @click="focusRecord(item.key)">
              <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.search" /></svg>
              {{ t('payroll_imports.registration.import_review.fix_in_preview') }}
            </button>
          </li>
        </ul>
      </div>
      <div
        v-if="healthMissing > 0"
        class="mt-3 flex flex-wrap items-center justify-between gap-2 rounded-lg border border-warning-500/40 bg-warning-50 px-4 py-3 text-sm text-warning-800"
        data-testid="registration-result-health-missing"
      >
        <p>{{ t('payroll_imports.registration.takeover.health_missing', { count: healthMissing }) }}</p>
        <RouterLink
          :to="{ name: 'payroll-people', query: { bulk: 'health_insurer' } }"
          :class="[btnOutlineSm('warning'), 'whitespace-nowrap']"
          data-testid="registration-result-health-missing-link"
        >
          <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.edit" /></svg>
          {{ t('payroll_imports.registration.takeover.health_missing_action') }}
        </RouterLink>
      </div>
      <template v-if="result.results.length">
        <p class="mt-1 text-sm text-neutral-600">{{ t('payroll_imports.registration.result_summary', result.summary) }}</p>
        <ul class="mt-3 divide-y divide-neutral-100 rounded-lg border border-neutral-200">
          <li v-for="item in result.results" :key="item.key" class="flex flex-wrap items-start justify-between gap-2 px-3 py-2 text-sm">
            <div class="min-w-0">
              <p class="font-medium text-neutral-900">{{ recordByKey.get(item.key) ? recordName(recordByKey.get(item.key)!) : item.key }}</p>
              <p v-if="recordByKey.get(item.key)" class="text-xs text-neutral-500">{{ recordByKey.get(item.key)?.action_label }}</p>
              <p v-if="item.message" class="mt-0.5 text-xs" :class="item.status === 'failed' ? 'text-danger-600' : 'text-neutral-600'">{{ item.message }}</p>
              <div v-if="item.operations.length" class="mt-1 flex flex-wrap gap-1">
                <span v-for="operation in item.operations" :key="operation" class="rounded-full bg-neutral-100 px-2 py-0.5 text-[11px] text-neutral-600">{{ operationLabel(operation) }}</span>
              </div>
              <p v-if="item.start_estimated && item.employee_id" data-testid="registration-result-start-estimated" class="mt-1 text-xs text-warning-700">
                <span class="font-medium">{{ t('payroll_imports.registration.import_review.start_estimated') }}.</span>
                {{ t('payroll_imports.registration.import_review.start_estimated_hint') }}
                <RouterLink :to="{ name: 'payroll-person', params: { id: item.employee_id } }" class="ml-1 font-medium text-payroll-600 hover:underline">{{ t('payroll_imports.registration.import_review.start_estimated_link') }}</RouterLink>
              </p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
              <RouterLink v-if="item.employee_id" :to="{ name: 'payroll-person', params: { id: item.employee_id } }" class="text-xs text-payroll-600 hover:underline">{{ t('payroll_imports.common.open_person') }}</RouterLink>
              <span class="rounded-full px-2 py-1 text-xs font-medium" :class="resultClass(item.status)">{{ t(`payroll_imports.registration.result_status.${item.status}`) }}</span>
            </div>
          </li>
        </ul>
      </template>

      <div v-if="resultHasHistory" class="mt-4 space-y-3" data-testid="registration-result-history">
        <h4 class="text-sm font-medium text-neutral-800">{{ t('payroll_imports.registration.result_history.title') }}</h4>
        <div class="flex flex-wrap gap-2 text-xs">
          <span class="rounded-full bg-success-50 px-2 py-1 font-medium text-success-700">{{ t('payroll_imports.registration.result_history.openings_saved', { count: result.opening_balances.saved }) }}</span>
          <span class="rounded-full bg-success-50 px-2 py-1 font-medium text-success-700">{{ t('payroll_imports.registration.result_history.averages_created', { created: result.averages.created, approved: result.averages.approved }) }}</span>
          <template v-if="result.takeover">
            <span class="rounded-full bg-success-50 px-2 py-1 font-medium text-success-700">{{ t('payroll_imports.registration.result_history.takeover_saved', { count: result.takeover.saved }) }}</span>
            <span class="rounded-full bg-neutral-100 px-2 py-1 font-medium text-neutral-700">{{ t('payroll_imports.registration.result_history.takeover_relations', { count: result.takeover.relations }) }}</span>
            <span v-for="[key, count] in resultTakeoverCounts" :key="`tc-${key}`" class="rounded-full bg-neutral-100 px-2 py-1 font-medium text-neutral-700">{{ takeoverCountLabel(key, count) }}</span>
          </template>
        </div>
        <ul v-if="result.takeover?.skipped.length" class="divide-y divide-neutral-100 rounded-lg border border-neutral-200 text-sm" data-testid="registration-result-takeover">
          <li v-for="(item, index) in result.takeover.skipped" :key="`t-${index}`" class="flex flex-wrap items-start justify-between gap-2 px-3 py-2">
            <div class="min-w-0">
              <p class="font-medium text-neutral-900">{{ item.label }}<template v-if="item.period"> · {{ formatPeriod(item.period) }}</template></p>
              <p class="text-xs text-neutral-600">{{ item.reason }}</p>
            </div>
            <span class="whitespace-nowrap rounded-full bg-neutral-100 px-2 py-1 text-xs font-medium text-neutral-600">{{ t('payroll_imports.registration.result_history.takeover_skipped') }}</span>
          </li>
        </ul>
        <ul v-if="result.opening_balances.skipped.length || result.averages.skipped.length" class="divide-y divide-neutral-100 rounded-lg border border-neutral-200 text-sm">
          <li v-for="item in result.opening_balances.skipped" :key="`o-${item.employee_id}-${item.year}`" class="flex flex-wrap items-start justify-between gap-2 px-3 py-2">
            <div class="min-w-0">
              <p class="font-medium text-neutral-900">{{ item.employee_name }} · {{ item.year }}</p>
              <p class="text-xs text-neutral-600">{{ item.reason }}</p>
            </div>
            <span class="whitespace-nowrap rounded-full bg-neutral-100 px-2 py-1 text-xs font-medium text-neutral-600">{{ t('payroll_imports.registration.result_history.opening_skipped') }}</span>
          </li>
          <li v-for="item in result.averages.skipped" :key="`a-${item.employment_id}-${item.year}-${item.quarter}`" class="flex flex-wrap items-start justify-between gap-2 px-3 py-2">
            <div class="min-w-0">
              <p class="font-medium text-neutral-900">{{ item.label }} · {{ t('payroll_imports.registration.takeover.quarter_label', { quarter: item.quarter, year: item.year }) }}</p>
              <p class="text-xs text-neutral-600">{{ item.reason }}</p>
            </div>
            <span class="whitespace-nowrap rounded-full bg-neutral-100 px-2 py-1 text-xs font-medium text-neutral-600">{{ t('payroll_imports.registration.result_history.average_skipped') }}</span>
          </li>
        </ul>
      </div>

      <div v-if="resultHasChecklist" class="mt-4 space-y-3" data-testid="registration-result-checklist">
        <h4 class="text-sm font-medium text-neutral-800">{{ t('payroll_imports.registration.result_checklist.title') }}</h4>
        <div class="flex flex-wrap gap-2 text-xs">
          <span class="rounded-full bg-success-50 px-2 py-1 font-medium text-success-700">{{ t('payroll_imports.registration.result_checklist.completed', { count: result.change_checklist.completed }) }}</span>
        </div>
        <ul v-if="result.change_checklist.failed.length" class="divide-y divide-neutral-100 rounded-lg border border-neutral-200 text-sm">
          <li v-for="(item, index) in result.change_checklist.failed" :key="`c-${item.employment_id}-${item.item_key}-${index}`" class="flex flex-wrap items-start justify-between gap-2 px-3 py-2">
            <div class="min-w-0">
              <p class="font-medium text-neutral-900">{{ checklistItemLabel(item.item_key) }}</p>
              <p class="text-xs text-neutral-600">{{ item.message }}</p>
            </div>
            <span class="whitespace-nowrap rounded-full bg-neutral-100 px-2 py-1 text-xs font-medium text-neutral-600">{{ t('payroll_imports.registration.result_checklist.failed') }}</span>
          </li>
        </ul>
      </div>
    </section>
  </section>
</template>

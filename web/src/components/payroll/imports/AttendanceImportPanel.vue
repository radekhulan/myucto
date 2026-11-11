<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import {
  payrollImportsApi,
  type AttendancePersonCreate,
  type AttendancePersonsResult,
  type AttendancePreview,
} from '@/api/payrollImports'
import {
  payrollAttendanceApprovalApi,
  type AttendanceApplyWithTimeResult,
  type AttendanceTimeApproval,
} from '@/api/payrollAttendanceApproval'
import { apiErrorMessage } from '@/api/errors'
import { useToast } from '@/composables/useToast'
import { BTN_DISABLED_NOTE, btnFilled, btnOutline, btnOutlineSm, disabledTitle, ICONS } from '@/components/ui/buttonStyles'
import { formatPeriod } from '@/composables/useFormat'
import { adoptableWageChanges, uniqueRefreshRuns, upgradeMatchesProfile } from './attendanceWages'
import ImportFilesDropzone from './ImportFilesDropzone.vue'
import AttendancePersonsStep from './AttendancePersonsStep.vue'
import AttendanceSummaryStep from './AttendanceSummaryStep.vue'
import AttendanceBatchHistory from './AttendanceBatchHistory.vue'
import AttendanceRecognitionStrip from './AttendanceRecognitionStrip.vue'
import AttendanceTimeApprovalResult from './AttendanceTimeApprovalResult.vue'
import AttendanceTemplateDownload from './AttendanceTemplateDownload.vue'
import { useAttendanceWorkspace } from './attendanceWorkspace'
import {
  ATTENDANCE_FILE_ACCEPT,
  ATTENDANCE_FILE_EXTENSIONS,
  autoCreatablePersons,
  autoPersonCreateDefaults,
  buildAttendanceLinks,
  buildPersonsPayload,
  chunk,
  componentsToCreate,
  filesFingerprint,
  guessRelationType,
  isValidPeriod,
  mismatchedPeriods,
  personCanBeCreated,
  sourceConfirmationMissing,
  personsWithoutEmploymentCount,
  pruneManualLinks,
  type ManualLinks,
} from './importHelpers'

const props = defineProps<{
  canWrite: boolean
  canCreatePersons: boolean
  /** Schválení pracovních měsíců (`payroll.approve`). */
  canApproveTime?: boolean
}>()

const { t, te } = useI18n()
const toast = useToast()
const workspace = useAttendanceWorkspace()
const { period, files, profiles } = workspace

type Step = 1 | 2 | 3
const STEPS: Step[] = [1, 2, 3]
const STEP_KEYS: Record<Step, string> = { 1: 'upload', 2: 'persons', 3: 'summary' }

const profileId = ref<number | null>(null)
const step = ref<Step>(1)
const preview = ref<AttendancePreview | null>(null)
const previewFingerprint = ref('')
const manualLinks = ref<ManualLinks>({})
const createResults = ref<AttendancePersonsResult | null>(null)
const saveLinks = ref(true)
const createInputs = ref(true)
const createComponents = ref(true)
const adoptPersonalNumbers = ref(true)
const adoptMonthlyWage = ref(false)
const createDeductions = ref(true)
// Výchozí stav drží backend: bez volby se souhrn nezapisuje ani neschvaluje.
const writeTimeSummary = ref(false)
const approveCleanTimeMonths = ref(false)
const materializeAbsenceCompensations = ref(false)
watch(writeTimeSummary, value => { if (!value) materializeAbsenceCompensations.value = false })
watch(writeTimeSummary, value => { if (!value) approveCleanTimeMonths.value = false })
const approveTimeBlockedReason = computed(() => {
  if (!props.canApproveTime) return t('payroll_imports.attendance_time.options.approve_no_permission')
  if (!writeTimeSummary.value) return t('payroll_imports.attendance_time.options.approve_needs_summary')
  return ''
})
const autoCreateMissingPersons = ref(true)
const autoCreateResult = ref<AttendancePersonsResult | null>(null)
const autoCreateNotes = computed(() => (autoCreateResult.value?.results ?? []).filter(item => item.status !== 'failed' && item.message))

// U chyb musí být vidět, u koho vznikly — anonymní seznam hlášek nejde vyřešit.
function personLabel(key: string): string {
  const person = preview.value?.persons.find(item => item.key === key)
  if (!person) return key
  return person.personal_number && !person.display_name.includes(person.personal_number)
    ? `${person.display_name} (${person.personal_number})`
    : person.display_name
}
const applyPhase = ref<'creating' | 'importing' | null>(null)
const result = ref<AttendanceApplyWithTimeResult | null>(null)
// Dodatečné schválení z výsledku nahradí výsledek schválení z importu.
const lateApproval = ref<AttendanceTimeApproval | null>(null)
watch(result, () => { lateApproval.value = null })
const timeApproval = computed(() => lateApproval.value ?? result.value?.time_approval ?? null)
const employmentLabels = computed(() => new Map((preview.value?.employment_options ?? [])
  .map(option => [option.employment_id, { name: option.label, code: option.code }] as const)))
const busy = ref<'preview' | 'apply' | 'persons' | 'approve' | null>(null)
const error = ref('')
const profileStale = ref(false)
const history = ref<InstanceType<typeof AttendanceBatchHistory> | null>(null)

const fingerprint = computed(() => `${period.value}#${filesFingerprint(files.value)}#${profileId.value ?? 'auto'}`)
const links = computed(() => preview.value ? buildAttendanceLinks(preview.value.persons, manualLinks.value) : [])
const fileErrors = computed(() => (preview.value?.files ?? []).filter(file => file.error))
const willCreate = computed(() => preview.value ? componentsToCreate(preview.value.component_checks) : [])
const adoptableWages = computed(() => adoptableWageChanges(preview.value?.wage_changes))
// Výchozí zapnuto jen tehdy, když je co převzít. Opakované načtení náhledu
// (třeba po založení osob) volbu účetní nepřepíše, dokud změny nezmizí.
watch(() => adoptableWages.value.length, (count, previous) => {
  if (count === 0) adoptMonthlyWage.value = false
  else if (!previous) adoptMonthlyWage.value = true
})
const usedSampleUpgrade = computed(() => {
  const upgrade = preview.value?.upgrade_available ?? null
  return upgradeMatchesProfile(upgrade, preview.value?.profile?.id) ? upgrade : null
})
const refreshRuns = computed(() => uniqueRefreshRuns(result.value?.runs_needing_refresh))

const loadBlockedReason = computed(() => {
  if (!isValidPeriod(period.value)) return t('payroll_imports.attendance.reason.no_period')
  if (files.value.length === 0) return t('payroll_imports.attendance.reason.no_files')
  return ''
})
// Nález kontrol období a dvojích vstupů potvrzuje účetní u každého náhledu znovu.
const confirmSourceChecks = ref(false)
watch(preview, () => { confirmSourceChecks.value = false })
const sourceChecks = computed(() => preview.value?.source_checks ?? null)
const detectedPeriods = computed(() => mismatchedPeriods(sourceChecks.value).map(item => formatPeriod(item)).join(', '))
const applyBlockedReason = computed(() => {
  if (!preview.value) return t('payroll_imports.attendance.reason.no_preview')
  if (profileStale.value) return t('payroll_imports.attendance.reason.profile_changed')
  if (sourceConfirmationMissing(sourceChecks.value, confirmSourceChecks.value)) return t('payroll_imports.attendance.reason.source_confirmation')
  if (links.value.length === 0 && !(autoCreateMissingPersons.value && showAutoCreateOption.value)) {
    return t('payroll_imports.attendance.reason.nothing_to_apply')
  }
  return ''
})
const creatableMissingPersons = computed(() =>
  preview.value ? autoCreatablePersons(preview.value.persons, manualLinks.value) : [])
// Nenalezené osoby bez čísel, které import sám nezaloží — rozhodne o nich účetní.
const manualOnlyPersonsCount = computed(() => preview.value
  ? preview.value.persons.filter(person => personCanBeCreated(person, manualLinks.value)).length - creatableMissingPersons.value.length
  : 0)
const deductionCount = computed(() => preview.value?.summary.deductions ?? 0)
const missingEmploymentCount = computed(() =>
  preview.value ? personsWithoutEmploymentCount(preview.value.persons, manualLinks.value) : 0)
const showAutoCreateOption = computed(() => props.canCreatePersons && creatableMissingPersons.value.length > 0)
const applyButtonLabel = computed(() => {
  if (applyPhase.value === 'creating') return t('payroll_imports.attendance.summary.phase_creating')
  if (applyPhase.value === 'importing') return t('payroll_imports.attendance.summary.phase_importing')
  if (busy.value === 'apply') return t('payroll_imports.common.working')
  return t('payroll_imports.attendance.summary.apply', { count: links.value.length })
})

// Jiné období, soubory nebo profil = jiný náhled; vazby osob se týkaly toho starého.
watch(fingerprint, value => {
  result.value = null
  autoCreateResult.value = null
  if (preview.value && value !== previewFingerprint.value) {
    preview.value = null
    manualLinks.value = {}
    createResults.value = null
    profileStale.value = false
    step.value = 1
  }
})

watch(profiles, list => {
  if (profileId.value !== null && !list.some(profile => profile.id === profileId.value)) profileId.value = null
})

// Profil upravený v záložce Mapování: náhled postavený na starých pravidlech
// už neodpovídá tomu, co by server při použití spočítal.
watch(workspace.profileRevision, revision => {
  if (!revision || !preview.value) return
  if (profileId.value === null || preview.value.profile?.id === revision.profileId) profileStale.value = true
})

function stepReachable(target: Step): boolean {
  return target === 1 || preview.value !== null
}

function goTo(target: Step) {
  if (stepReachable(target)) step.value = target
}

function kindLabel(kind: string | null): string {
  if (!kind) return ''
  const key = `payroll_imports.component_kinds.${kind}`
  return te(key) ? t(key) : kind
}

async function previewSource() {
  return {
    files: await workspace.payloadFiles(),
    rules: null,
    profile_id: profileId.value,
    components: null,
  }
}

// Po dávkách: server bere nejvýš 200 osob na požadavek a firma jich může mít víc.
const PERSONS_PER_REQUEST = 100

async function createPersonsInChunks(payload: AttendancePersonCreate[]): Promise<AttendancePersonsResult> {
  const source = await previewSource()
  const results: AttendancePersonsResult['results'] = []
  for (const part of chunk(payload, PERSONS_PER_REQUEST)) {
    const response = await payrollImportsApi.createAttendancePersons(period.value, part, source)
    results.push(...response.results)
  }
  return { results }
}

async function requestPreview(): Promise<boolean> {
  error.value = ''
  const current = fingerprint.value
  try {
    const response = await payrollImportsApi.previewAttendance({
      period: period.value,
      ...await previewSource(),
    })
    preview.value = response
    // Pravidla nové verze vzoru chodí jen s náhledem; Mapování je potřebuje k aktualizaci.
    if (response.upgrade_available) workspace.sampleUpgrade.value = response.upgrade_available
    previewFingerprint.value = current
    profileStale.value = false
    manualLinks.value = pruneManualLinks(manualLinks.value, response.persons, response.employment_options)
    return true
  } catch (err) {
    error.value = apiErrorMessage(err, t('payroll_imports.attendance.preview_failed'))
    return false
  }
}

async function loadPreview() {
  if (loadBlockedReason.value !== '' || busy.value !== null) return
  busy.value = 'preview'
  result.value = null
  createResults.value = null
  manualLinks.value = {}
  try {
    if (await requestPreview()) step.value = 2
  } finally {
    busy.value = null
  }
}

async function reloadPreview() {
  if (busy.value !== null) return
  busy.value = 'preview'
  try {
    await requestPreview()
  } finally {
    busy.value = null
  }
}

function editMapping() {
  const used = preview.value?.profile ?? null
  // Bez profilu se do mapování přenese automatický návrh — z něj vznikne nový profil.
  workspace.openMapping(used?.id ?? null, used?.id == null ? preview.value?.rules ?? null : null)
}

function setLink(personKey: string, employmentId: number | null) {
  const person = preview.value?.persons.find(item => item.key === personKey)
  const next = { ...manualLinks.value }
  // Volba shodná s automatickou shodou není ruční přebití.
  if (person && person.match.employment_id === employmentId) delete next[personKey]
  else next[personKey] = employmentId
  manualLinks.value = next
}

async function createPersons(payload: AttendancePersonCreate[]) {
  if (!preview.value || busy.value !== null) return
  busy.value = 'persons'
  error.value = ''
  try {
    const response = await createPersonsInChunks(payload)
    createResults.value = response
    // Použitá otevřená dohoda je úspěch stejně jako založená osoba.
    const created = response.results.filter(item => item.status !== 'failed').length
    const failed = response.results.length - created
    if (failed > 0) toast.warning(t('payroll_imports.attendance.persons.created', { created, failed }))
    else toast.success(t('payroll_imports.attendance.persons.created', { created, failed }))
    // Založené osoby mají uloženou vazbu, nový náhled je už ukáže jako propojené.
    await requestPreview()
  } catch (err) {
    error.value = apiErrorMessage(err, t('payroll_imports.attendance.persons_failed'))
  } finally {
    busy.value = null
  }
}

async function apply() {
  if (!preview.value || applyBlockedReason.value !== '' || busy.value !== null) return
  busy.value = 'apply'
  error.value = ''
  autoCreateResult.value = null
  try {
    if (autoCreateMissingPersons.value && showAutoCreateOption.value) {
      applyPhase.value = 'creating'
      const payload = buildPersonsPayload(
        creatableMissingPersons.value,
        {},
        autoPersonCreateDefaults(period.value),
        person => guessRelationType(person.relation_label),
      )
      try {
        autoCreateResult.value = await createPersonsInChunks(payload)
        // Krok Osoby pak ukáže výsledek u každého řádku.
        createResults.value = autoCreateResult.value
      } catch (err) {
        error.value = apiErrorMessage(err, t('payroll_imports.attendance.persons_failed'))
      }
      applyPhase.value = 'importing'
      // requestPreview() by chybu ze založení přepsalo — obnova náhledu ji nesmí smazat.
      const creationError = error.value
      // Nově založené osoby mají uloženou vazbu — nový náhled je ukáže jako propojené.
      await requestPreview()
      if (creationError) error.value = creationError
    }
    if (!preview.value) return
    const response = await payrollAttendanceApprovalApi.applyAttendance({
      period: period.value,
      files: await workspace.payloadFiles(),
      rules: preview.value.rules,
      profile_id: preview.value.profile?.id ?? null,
      components: null,
      links: links.value,
      save_links: saveLinks.value,
      create_inputs: createInputs.value,
      create_components: willCreate.value.length > 0 && createComponents.value,
      adopt_personal_numbers: adoptPersonalNumbers.value,
      adopt_monthly_wage: adoptMonthlyWage.value && adoptableWages.value.length > 0,
      create_deductions: createDeductions.value && deductionCount.value > 0,
      write_time_summary: writeTimeSummary.value,
      approve_clean_time_months: writeTimeSummary.value && approveCleanTimeMonths.value && props.canApproveTime === true,
      materialize_absence_compensations: writeTimeSummary.value && materializeAbsenceCompensations.value,
      confirm_source_checks: confirmSourceChecks.value,
    })
    result.value = response
    if (response.replayed) toast.warning(t('payroll_imports.attendance.summary.replayed_toast', { id: response.batch.id }))
    else toast.success(t('payroll_imports.attendance.summary.applied', { id: response.batch.id, count: response.inputs.created }))
    void history.value?.reload()
  } catch (err) {
    error.value = apiErrorMessage(err, t('payroll_imports.attendance.apply_failed'))
  } finally {
    busy.value = null
    applyPhase.value = null
  }
}

async function approveTimeMonths() {
  if (!result.value || !props.canApproveTime || busy.value !== null) return
  busy.value = 'approve'
  error.value = ''
  try {
    const approval = await payrollAttendanceApprovalApi.approveCleanTimeMonths(result.value.batch.id)
    lateApproval.value = approval
    const params = { approved: approval.approved, exceptions: approval.exceptions.length }
    if (approval.exceptions.length) toast.warning(t('payroll_imports.attendance_time.approved_toast', params))
    else toast.success(t('payroll_imports.attendance_time.approved_toast', params))
  } catch (err) {
    error.value = apiErrorMessage(err, t('payroll_imports.attendance_time.approve_failed'))
  } finally {
    busy.value = null
  }
}
</script>

<template>
  <section class="space-y-4" data-testid="attendance-import">
    <div>
      <h2 class="text-lg font-semibold text-neutral-900">{{ t('payroll_imports.attendance.title') }}</h2>
      <p class="mt-1 max-w-3xl text-sm text-neutral-500">{{ t('payroll_imports.attendance.hint') }}</p>
    </div>

    <p v-if="!canWrite" class="rounded-lg border border-warning-500/30 bg-warning-50 px-4 py-3 text-sm text-warning-700">
      {{ t('payroll_imports.attendance.no_permission') }}
    </p>

    <nav class="overflow-x-auto" :aria-label="t('payroll_imports.attendance.steps.label')">
      <ol class="flex min-w-max items-center gap-2">
        <li v-for="item in STEPS" :key="item" class="flex items-center gap-2">
          <button type="button"
            class="inline-flex cursor-pointer items-center gap-2 whitespace-nowrap rounded-full border px-3 py-1.5 text-sm font-medium transition-colors disabled:cursor-not-allowed disabled:opacity-50"
            :class="step === item
              ? 'border-payroll-600 bg-payroll-600 text-white'
              : stepReachable(item) ? 'border-payroll-500/40 bg-surface text-payroll-700 hover:bg-payroll-50' : 'border-neutral-200 bg-surface text-neutral-500'"
            :disabled="!stepReachable(item) || busy !== null"
            :aria-current="step === item ? 'step' : undefined"
            @click="goTo(item)">
            <span class="inline-flex h-5 w-5 items-center justify-center rounded-full text-xs"
              :class="step === item ? 'bg-white/20' : 'bg-neutral-100 text-neutral-600'">{{ item }}</span>
            {{ t(`payroll_imports.attendance.steps.${STEP_KEYS[item]}`) }}
          </button>
          <span v-if="item < 3" class="h-px w-6 bg-neutral-300" aria-hidden="true" />
        </li>
      </ol>
    </nav>

    <p v-if="error" role="alert" class="rounded-lg border border-danger-500/30 bg-danger-50 px-4 py-3 text-sm text-danger-700">{{ error }}</p>

    <!-- 1. Období a soubory -->
    <section v-show="step === 1" class="rounded-xl border border-neutral-200 bg-surface p-4 shadow-sm sm:p-6">
      <div class="mb-4 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <label class="block">
          <span class="mb-1 block text-xs font-medium text-neutral-600">{{ t('payroll_imports.attendance.period') }}</span>
          <input v-model="period" type="month" data-testid="attendance-period" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm" :disabled="!canWrite || busy !== null">
        </label>
        <label class="block lg:col-span-2">
          <span class="mb-1 block text-xs font-medium text-neutral-600">{{ t('payroll_imports.attendance.profile') }}</span>
          <select v-model="profileId" data-testid="attendance-profile" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm" :disabled="!canWrite || busy !== null">
            <option :value="null">{{ t('payroll_imports.attendance.profile_auto') }}</option>
            <option v-for="profile in profiles" :key="profile.id" :value="profile.id">
              {{ profile.name }}{{ profile.is_sample ? ` · ${t('payroll_imports.sample_badge')}` : '' }}
            </option>
          </select>
          <span class="mt-1 block text-[11px] text-neutral-500">{{ t('payroll_imports.attendance.profile_hint') }}</span>
        </label>
      </div>

      <div v-if="canWrite" class="mb-4 flex flex-wrap items-center gap-3" data-testid="attendance-template">
        <AttendanceTemplateDownload :disabled="busy !== null" />
        <p class="min-w-0 max-w-2xl flex-1 text-xs text-neutral-500">{{ t('payroll_imports.template.hint') }}</p>
      </div>

      <ImportFilesDropzone
        v-model:files="files"
        test-id="attendance-dropzone"
        :accept="ATTENDANCE_FILE_ACCEPT"
        :allowed-extensions="ATTENDANCE_FILE_EXTENSIONS"
        :drop-hint="t('payroll_imports.attendance.drop_hint')"
        :disabled="!canWrite || busy !== null"
      />

      <div class="mt-4 flex flex-col items-start gap-1.5">
        <button type="button" data-testid="attendance-load" :class="btnFilled('primary')"
          :disabled="!canWrite || busy !== null || loadBlockedReason !== ''"
          :title="disabledTitle(loadBlockedReason !== '', loadBlockedReason)" @click="loadPreview">
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.table" /></svg>
          {{ busy === 'preview' ? t('payroll_imports.common.working') : t('payroll_imports.attendance.load_preview') }}
        </button>
        <p v-if="loadBlockedReason && canWrite" :class="BTN_DISABLED_NOTE">{{ loadBlockedReason }}</p>
      </div>
    </section>

    <template v-if="preview">
      <div v-if="fileErrors.length" role="status" class="rounded-lg border border-danger-500/30 bg-danger-50 px-4 py-3 text-sm text-danger-700">
        <p v-for="file in fileErrors" :key="file.name">{{ t('payroll_imports.attendance.file_error', { name: file.name, error: file.error }) }}</p>
      </div>

      <div v-if="profileStale" role="status" class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-warning-500/30 bg-warning-50 px-4 py-3 text-sm text-warning-700">
        <p class="max-w-3xl">{{ t('payroll_imports.attendance.profile_stale') }}</p>
        <button type="button" data-testid="attendance-reload" :class="btnOutline('warning')" :disabled="busy !== null" @click="reloadPreview">
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.cycle" /></svg>
          {{ busy === 'preview' ? t('payroll_imports.common.working') : t('payroll_imports.attendance.reload_preview') }}
        </button>
      </div>

      <div v-if="usedSampleUpgrade" role="status" data-testid="attendance-sample-upgrade" class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-warning-500/30 bg-warning-50 px-4 py-3 text-sm text-warning-700">
        <p class="max-w-3xl">{{ t('payroll_imports.attendance.upgrade_notice', { name: usedSampleUpgrade.name, version: usedSampleUpgrade.version ?? '?', latest: usedSampleUpgrade.latest_version }) }}</p>
        <button type="button" class="whitespace-nowrap" :class="btnOutline('warning')" :disabled="busy !== null" @click="workspace.openMapping(usedSampleUpgrade.profile_id)">
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.table" /></svg>
          {{ t('payroll_imports.attendance.upgrade_open') }}
        </button>
      </div>

      <p v-if="detectedPeriods" role="alert" data-testid="attendance-period-banner" class="rounded-lg border border-warning-500/30 bg-warning-50 px-4 py-3 text-sm text-warning-700">
        {{ t('payroll_imports.attendance.source_checks.period_banner', { detected: detectedPeriods, selected: formatPeriod(sourceChecks?.period.selected ?? period) }) }}
      </p>

      <AttendanceRecognitionStrip :preview="preview" can-edit @edit-mapping="editMapping" />

      <!-- 2. Osoby -->
      <section v-show="step === 2" class="space-y-4">
        <div>
          <h3 class="font-semibold text-neutral-900">{{ t('payroll_imports.attendance.persons.title') }}</h3>
          <p class="max-w-3xl text-sm text-neutral-500">{{ t('payroll_imports.attendance.persons.intro') }}</p>
        </div>
        <AttendancePersonsStep
          :persons="preview.persons"
          :options="preview.employment_options"
          :manual-links="manualLinks"
          :period="period"
          :can-create="canCreatePersons"
          :disabled="!canWrite || busy !== null"
          :create-results="createResults"
          @set-link="setLink"
          @create="createPersons"
        />
        <div class="flex flex-wrap items-center justify-between gap-2">
          <button type="button" :class="btnOutline('neutral')" :disabled="busy !== null" @click="goTo(1)">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.uturn" /></svg>
            {{ t('payroll_imports.attendance.back') }}
          </button>
          <button type="button" data-testid="attendance-to-summary" :class="btnFilled('primary')" :disabled="busy !== null" @click="goTo(3)">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.chart" /></svg>
            {{ t('payroll_imports.attendance.next_summary') }}
          </button>
        </div>
      </section>

      <!-- 3. Souhrn a použití -->
      <section v-show="step === 3" class="space-y-4">
        <div>
          <h3 class="font-semibold text-neutral-900">{{ t('payroll_imports.attendance.summary.title') }}</h3>
          <p class="max-w-3xl text-sm text-neutral-500">{{ t('payroll_imports.attendance.summary.intro') }}</p>
        </div>
        <AttendanceSummaryStep :preview="preview" :manual-links="manualLinks" />

        <section class="rounded-xl border border-payroll-500/30 bg-payroll-50 p-4 sm:p-6">
          <div v-if="willCreate.length" class="mb-4 rounded-lg border border-primary-500/20 bg-surface p-3 text-sm" data-testid="attendance-create-components">
            <p class="font-medium text-neutral-900">{{ t('payroll_imports.attendance.summary.components_to_create') }}</p>
            <ul class="mt-1 space-y-0.5 text-neutral-700">
              <li v-for="check in willCreate" :key="check.component_code">
                <span class="font-mono text-xs font-medium">{{ check.component_code }}</span>
                <template v-if="check.name"> – {{ check.name }}</template>
                <span v-if="check.kind" class="text-neutral-500"> ({{ kindLabel(check.kind) }})</span>
              </li>
            </ul>
          </div>
          <div v-if="sourceChecks?.requires_confirmation" role="alert" class="mb-4 rounded-lg border border-warning-500/30 bg-warning-50 p-3 text-sm text-warning-700" data-testid="attendance-source-checks">
            <p class="font-medium">{{ t('payroll_imports.attendance.source_checks.title') }}</p>
            <p v-if="detectedPeriods" class="mt-1" data-testid="attendance-source-period">
              {{ t('payroll_imports.attendance.source_checks.period_mismatch', { detected: detectedPeriods, selected: formatPeriod(sourceChecks.period.selected) }) }}
            </p>
            <div v-if="sourceChecks.other_sources.length" class="mt-1" data-testid="attendance-source-other">
              <p>{{ t('payroll_imports.attendance.source_checks.other_sources', { period: formatPeriod(sourceChecks.period.selected) }) }}</p>
              <ul class="mt-1 list-disc space-y-0.5 pl-5 text-xs">
                <li v-for="source in sourceChecks.other_sources" :key="`${source.input_import_id}-${source.attendance_import_id ?? 0}`">
                  {{ t('payroll_imports.attendance.source_checks.other_source', { files: source.files.join(', '), count: source.active_inputs, date: source.created_at.slice(0, 10) }) }}
                </li>
              </ul>
            </div>
            <label class="mt-2 flex items-start gap-2 text-neutral-800">
              <input v-model="confirmSourceChecks" type="checkbox" data-testid="attendance-confirm-source-checks" class="mt-0.5 rounded border-neutral-300 text-payroll-600" :disabled="!canWrite || busy !== null">
              <span class="font-medium">{{ t('payroll_imports.attendance.source_checks.confirm') }}</span>
            </label>
          </div>
          <p v-if="missingEmploymentCount > 0" class="mb-3 text-xs text-neutral-600">
            {{ t('payroll_imports.attendance.summary.missing_employment_note', { count: missingEmploymentCount }) }}
            <button type="button" class="ml-1 font-medium text-payroll-600 hover:underline" @click="goTo(2)">{{ t('payroll_imports.attendance.summary.edit_in_persons_step') }}</button>
          </p>
          <div class="space-y-3">
            <label v-if="showAutoCreateOption" class="flex items-start gap-2 text-sm text-neutral-800">
              <input v-model="autoCreateMissingPersons" type="checkbox" data-testid="attendance-auto-create-persons" class="mt-0.5 rounded border-neutral-300 text-payroll-600" :disabled="!canWrite || busy !== null">
              <span><span class="font-medium">{{ t('payroll_imports.attendance.summary.auto_create_persons', { count: creatableMissingPersons.length }) }}</span><span class="mt-0.5 block text-xs text-neutral-600">{{ t('payroll_imports.attendance.summary.auto_create_persons_hint') }}</span></span>
            </label>
            <p v-if="manualOnlyPersonsCount > 0" class="text-xs text-warning-700" data-testid="attendance-manual-only-persons">
              {{ t('payroll_imports.attendance.summary.auto_create_manual_only', { count: manualOnlyPersonsCount }) }}
              <button type="button" class="ml-1 font-medium text-payroll-600 hover:underline" @click="goTo(2)">{{ t('payroll_imports.attendance.summary.edit_in_persons_step') }}</button>
            </p>
            <label v-if="willCreate.length" class="flex items-start gap-2 text-sm text-neutral-800">
              <input v-model="createComponents" type="checkbox" data-testid="attendance-create-components-toggle" class="mt-0.5 rounded border-neutral-300 text-payroll-600" :disabled="!canWrite || busy !== null">
              <span><span class="font-medium">{{ t('payroll_imports.attendance.summary.create_components') }}</span><span class="mt-0.5 block text-xs text-neutral-600">{{ t('payroll_imports.attendance.summary.create_components_hint') }}</span></span>
            </label>
            <label class="flex items-start gap-2 text-sm text-neutral-800">
              <input v-model="saveLinks" type="checkbox" class="mt-0.5 rounded border-neutral-300 text-payroll-600" :disabled="!canWrite || busy !== null">
              <span><span class="font-medium">{{ t('payroll_imports.attendance.summary.save_links') }}</span><span class="mt-0.5 block text-xs text-neutral-600">{{ t('payroll_imports.attendance.summary.save_links_hint') }}</span></span>
            </label>
            <label class="flex items-start gap-2 text-sm text-neutral-800">
              <input v-model="adoptPersonalNumbers" type="checkbox" data-testid="attendance-adopt-personal-numbers" class="mt-0.5 rounded border-neutral-300 text-payroll-600" :disabled="!canWrite || busy !== null">
              <span><span class="font-medium">{{ t('payroll_imports.attendance.summary.adopt_personal_numbers') }}</span><span class="mt-0.5 block text-xs text-neutral-600">{{ t('payroll_imports.attendance.summary.adopt_personal_numbers_hint') }}</span></span>
            </label>
            <label v-if="adoptableWages.length" class="flex items-start gap-2 text-sm text-neutral-800">
              <input v-model="adoptMonthlyWage" type="checkbox" data-testid="attendance-adopt-monthly-wage" class="mt-0.5 rounded border-neutral-300 text-payroll-600" :disabled="!canWrite || busy !== null">
              <span><span class="font-medium">{{ t('payroll_imports.attendance.summary.adopt_monthly_wage', { count: adoptableWages.length }) }}</span><span class="mt-0.5 block text-xs text-neutral-600">{{ t('payroll_imports.attendance.summary.adopt_monthly_wage_hint') }}</span></span>
            </label>
            <label class="flex items-start gap-2 text-sm text-neutral-800">
              <input v-model="createInputs" type="checkbox" class="mt-0.5 rounded border-neutral-300 text-payroll-600" :disabled="!canWrite || busy !== null">
              <span><span class="font-medium">{{ t('payroll_imports.attendance.summary.create_inputs') }}</span><span class="mt-0.5 block text-xs text-neutral-600">{{ t('payroll_imports.attendance.summary.create_inputs_hint') }}</span></span>
            </label>
            <label v-if="deductionCount > 0" class="flex items-start gap-2 text-sm text-neutral-800">
              <input v-model="createDeductions" type="checkbox" data-testid="attendance-create-deductions" class="mt-0.5 rounded border-neutral-300 text-payroll-600" :disabled="!canWrite || busy !== null">
              <span><span class="font-medium">{{ t('payroll_imports.attendance.summary.create_deductions', { count: deductionCount }) }}</span><span class="mt-0.5 block text-xs text-neutral-600">{{ t('payroll_imports.attendance.summary.create_deductions_hint') }}</span></span>
            </label>
            <label class="flex items-start gap-2 text-sm text-neutral-800">
              <input v-model="writeTimeSummary" type="checkbox" data-testid="attendance-write-time-summary" class="mt-0.5 rounded border-neutral-300 text-payroll-600" :disabled="!canWrite || busy !== null">
              <span><span class="font-medium">{{ t('payroll_imports.attendance_time.options.write_summary') }}</span><span class="mt-0.5 block text-xs text-neutral-600">{{ t('payroll_imports.attendance_time.options.write_summary_hint') }}</span></span>
            </label>
            <label class="ml-6 flex items-start gap-2 text-sm text-neutral-800">
              <input v-model="approveCleanTimeMonths" type="checkbox" data-testid="attendance-approve-clean-time-months" class="mt-0.5 rounded border-neutral-300 text-payroll-600"
                :disabled="!canWrite || busy !== null || approveTimeBlockedReason !== ''"
                :title="disabledTitle(approveTimeBlockedReason !== '', approveTimeBlockedReason)">
              <span>
                <span class="font-medium">{{ t('payroll_imports.attendance_time.options.approve_clean') }}</span>
                <span class="mt-0.5 block text-xs text-neutral-600">{{ t('payroll_imports.attendance_time.options.approve_clean_hint') }}</span>
                <span v-if="approveTimeBlockedReason && canWrite" class="mt-0.5 block" :class="BTN_DISABLED_NOTE" data-testid="attendance-approve-blocked">{{ approveTimeBlockedReason }}</span>
              </span>
            </label>
            <label class="ml-6 flex items-start gap-2 text-sm text-neutral-800">
              <input v-model="materializeAbsenceCompensations" type="checkbox" data-testid="attendance-materialize-absence-compensations" class="mt-0.5 rounded border-neutral-300 text-payroll-600"
                :disabled="!canWrite || busy !== null || !writeTimeSummary"
                :title="disabledTitle(!writeTimeSummary, t('payroll_imports.attendance_time.options.compensations_needs_summary'))">
              <span>
                <span class="font-medium">{{ t('payroll_imports.attendance_time.options.compensations') }}</span>
                <span class="mt-0.5 block text-xs text-neutral-600">{{ t('payroll_imports.attendance_time.options.compensations_hint') }}</span>
              </span>
            </label>
          </div>
          <div class="mt-4 flex flex-wrap items-center justify-between gap-2">
            <button type="button" :class="btnOutline('neutral')" :disabled="busy !== null" @click="goTo(2)">
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.uturn" /></svg>
              {{ t('payroll_imports.attendance.back') }}
            </button>
            <div class="flex flex-col items-end gap-1.5">
              <button type="button" data-testid="attendance-apply" :class="btnFilled('success')"
                :disabled="!canWrite || busy !== null || applyBlockedReason !== ''"
                :title="disabledTitle(applyBlockedReason !== '', applyBlockedReason)" @click="apply">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.check" /></svg>
                {{ applyButtonLabel }}
              </button>
              <p v-if="applyBlockedReason && canWrite" :class="BTN_DISABLED_NOTE">{{ applyBlockedReason }}</p>
            </div>
          </div>

          <div v-if="autoCreateResult?.results.some(item => item.status === 'failed')" class="mt-3 rounded-lg border border-warning-500/30 bg-warning-50 p-3" data-testid="attendance-auto-create-errors">
            <p class="font-medium text-warning-700">{{ t('payroll_imports.attendance.summary.auto_create_failed_title', { count: autoCreateResult.results.filter(item => item.status === 'failed').length }) }}</p>
            <ul class="mt-1 space-y-0.5 text-xs text-warning-700">
              <li v-for="item in autoCreateResult.results.filter(entry => entry.status === 'failed')" :key="item.person_key">
                <span class="font-medium">{{ personLabel(item.person_key) }}:</span> {{ item.message }}
              </li>
            </ul>
          </div>
          <div v-if="autoCreateNotes.length" class="mt-3 rounded-lg border border-neutral-200 bg-surface p-3" data-testid="attendance-auto-create-notes">
            <p class="font-medium text-neutral-800">{{ t('payroll_imports.attendance.summary.auto_create_notes_title', { count: autoCreateNotes.length }) }}</p>
            <ul class="mt-1 space-y-0.5 text-xs text-neutral-700">
              <li v-for="item in autoCreateNotes" :key="item.person_key">
                <span class="font-medium">{{ personLabel(item.person_key) }}:</span> {{ item.message }}
              </li>
            </ul>
          </div>
        </section>

        <section v-if="result" class="rounded-xl border p-4 text-sm sm:p-6" data-testid="attendance-result"
          :class="result.inputs.errors.length ? 'border-warning-500/30 bg-warning-50' : 'border-success-500/30 bg-success-50'">
          <h4 class="font-semibold text-neutral-900">{{ t('payroll_imports.attendance.result.title') }}</h4>
          <p class="mt-1 text-neutral-700">{{ t('payroll_imports.attendance.result.batch', { id: result.batch.id, period: result.batch.period }) }}</p>
          <p v-if="result.replayed" class="mt-1 font-medium text-warning-700">{{ t('payroll_imports.attendance.result.replayed') }}</p>
          <ul class="mt-2 space-y-0.5 text-neutral-700">
            <li>{{ t('payroll_imports.attendance.result.inputs_created', { count: result.inputs.created }) }}</li>
            <li v-if="result.inputs.updated !== undefined" data-testid="attendance-inputs-updated">{{ t('payroll_imports.attendance_time.result.inputs_updated', { count: result.inputs.updated }) }}</li>
            <li v-if="result.inputs.overridden" class="font-medium text-warning-700" data-testid="attendance-inputs-overridden">{{ t('payroll_imports.attendance_time.result.inputs_overridden', { count: result.inputs.overridden }) }}</li>
            <li>{{ t('payroll_imports.attendance.result.duplicates', { count: result.inputs.duplicates }) }}</li>
            <li>{{ t('payroll_imports.attendance.result.links_saved', { count: result.links_saved }) }}</li>
            <li v-if="result.personal_numbers_adopted" data-testid="attendance-personal-numbers-adopted">{{ t('payroll_imports.attendance.result.personal_numbers_adopted', { count: result.personal_numbers_adopted }) }}</li>
            <li v-if="result.monthly_wages_adopted" data-testid="attendance-monthly-wages-adopted">{{ t('payroll_imports.attendance.result.monthly_wages_adopted', { count: result.monthly_wages_adopted }) }}</li>
            <li v-if="result.deductions" data-testid="attendance-deductions-result">{{ t('payroll_imports.attendance.result.deductions', { created: result.deductions.created, updated: result.deductions.updated, unchanged: result.deductions.unchanged }) }}</li>
          </ul>
          <div v-if="result.deductions?.conflicts.length" class="mt-3 rounded-lg border border-warning-500/30 bg-warning-50 p-3" data-testid="attendance-deduction-conflicts">
            <p class="font-medium text-warning-700">{{ t('payroll_imports.attendance.result.deduction_conflicts_title', { count: result.deductions.conflicts.length }) }}</p>
            <ul class="mt-1 space-y-0.5 text-xs text-warning-700">
              <li v-for="item in result.deductions.conflicts" :key="`${item.key}-${item.reason}`">{{ item.display_name }}: {{ item.reason }}</li>
            </ul>
          </div>
          <div v-if="result.wage_conflicts?.length" class="mt-3 rounded-lg border border-warning-500/30 bg-warning-50 p-3" data-testid="attendance-wage-conflicts">
            <p class="font-medium text-warning-700">{{ t('payroll_imports.attendance.result.wage_conflicts_title', { count: result.wage_conflicts.length }) }}</p>
            <ul class="mt-1 space-y-0.5 text-xs text-warning-700">
              <li v-for="item in result.wage_conflicts" :key="item.key">{{ item.display_name }}: {{ item.reason }}</li>
            </ul>
          </div>
          <div v-if="refreshRuns.length" class="mt-3 rounded-lg border border-primary-500/30 bg-surface p-3" data-testid="attendance-runs-refresh">
            <p class="font-medium text-neutral-900">{{ t('payroll_imports.attendance.result.runs_refresh_title') }}</p>
            <p class="mt-0.5 text-xs text-neutral-600">{{ t('payroll_imports.attendance.result.runs_refresh_hint') }}</p>
            <div class="mt-2 flex flex-wrap gap-2">
              <RouterLink v-for="run in refreshRuns" :key="run.run_id" :to="{ name: 'payroll-runs', query: { period: run.period } }" class="whitespace-nowrap" :class="btnOutlineSm('primary')">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.cycle" /></svg>
                {{ t('payroll_imports.attendance.result.runs_refresh_link', { period: formatPeriod(run.period) }) }}
              </RouterLink>
            </div>
          </div>
          <div v-if="result.personal_number_conflicts?.length" class="mt-3 rounded-lg border border-warning-500/30 bg-warning-50 p-3" data-testid="attendance-personal-number-conflicts">
            <p class="font-medium text-warning-700">{{ t('payroll_imports.attendance.result.personal_number_conflicts_title', { count: result.personal_number_conflicts.length }) }}</p>
            <ul class="mt-1 space-y-0.5 text-xs text-warning-700">
              <li v-for="item in result.personal_number_conflicts" :key="item.key">{{ item.display_name }}: {{ item.reason }}</li>
            </ul>
          </div>
          <div v-if="result.inputs.errors.length" class="mt-3">
            <p class="font-medium text-danger-700">{{ t('payroll_imports.attendance.result.errors_title') }}</p>
            <ul class="mt-1 space-y-0.5 text-xs text-danger-700">
              <li v-for="item in result.inputs.errors" :key="`${item.row_number}-${item.error_message}`">{{ t('payroll_imports.attendance.result.error_row', { row: item.row_number, message: item.error_message }) }}</li>
            </ul>
          </div>
          <AttendanceTimeApprovalResult
            v-if="timeApproval || result.time_summary"
            :approval="timeApproval"
            :summary="result.time_summary ?? null"
            :period="result.batch.period"
            :labels="employmentLabels"
          />
          <div v-if="result.absence_compensation" class="mt-3 rounded-lg border border-neutral-200 bg-surface p-3" data-testid="attendance-absence-compensation">
            <p class="font-medium text-neutral-900">{{ t('payroll_imports.attendance_time.result.compensations_title') }}</p>
            <p class="mt-0.5 text-neutral-700" data-testid="attendance-absence-compensation-counts">{{ t('payroll_imports.attendance_time.result.compensations_counts', { created: result.absence_compensation.created, updated: result.absence_compensation.updated, unchanged: result.absence_compensation.unchanged, cancelled: result.absence_compensation.cancelled }) }}</p>
            <p class="mt-0.5 text-xs text-neutral-600">{{ t('payroll_imports.attendance_time.result.compensations_drafts_hint') }}</p>
            <template v-if="result.absence_compensation.skipped.length">
              <p class="mt-2 font-medium text-warning-700">{{ t('payroll_imports.attendance_time.result.compensations_skipped_title', { count: result.absence_compensation.skipped.length }) }}</p>
              <ul class="mt-1 space-y-0.5 text-xs text-warning-700" data-testid="attendance-absence-compensation-skipped">
                <li v-for="(item, index) in result.absence_compensation.skipped" :key="`${item.employment_id}-${item.meaning ?? ''}-${index}`">
                  <span class="font-medium">{{ employmentLabels.get(item.employment_id)?.name ?? t('payroll_imports.attendance_time.result.unknown_person', { id: item.employment_id }) }}:</span> {{ item.reason }}
                </li>
              </ul>
            </template>
            <template v-if="result.absence_compensation.warnings.length">
              <p class="mt-2 font-medium text-neutral-800">{{ t('payroll_imports.attendance_time.result.compensations_warnings_title', { count: result.absence_compensation.warnings.length }) }}</p>
              <ul class="mt-1 space-y-0.5 text-xs text-neutral-700" data-testid="attendance-absence-compensation-warnings">
                <li v-for="(item, index) in result.absence_compensation.warnings" :key="`${item.employment_id}-${item.meaning}-${index}`">
                  <span class="font-medium">{{ employmentLabels.get(item.employment_id)?.name ?? t('payroll_imports.attendance_time.result.unknown_person', { id: item.employment_id }) }}:</span> {{ item.message }}
                </li>
              </ul>
            </template>
          </div>
          <div v-if="result.skipped_persons.length" class="mt-3">
            <p class="font-medium text-warning-700">{{ t('payroll_imports.attendance.result.skipped_title', { count: result.skipped_persons.length }) }}</p>
            <ul class="mt-1 space-y-0.5 text-xs text-warning-700">
              <li v-for="item in result.skipped_persons" :key="item.key">{{ item.display_name }}: {{ item.reason }}</li>
            </ul>
          </div>
          <div class="mt-4 flex flex-wrap gap-2">
            <RouterLink :to="{ name: 'payroll-components', query: { tab: 'inputs', period: result.batch.period, ...(result.inputs.import_id ? { import: String(result.inputs.import_id) } : {}) } }" :class="btnOutline('primary')">
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.link" /></svg>
              {{ t('payroll_imports.attendance.result.open_inputs') }}
            </RouterLink>
            <button v-if="canApproveTime" type="button" data-testid="attendance-result-approve-time" class="whitespace-nowrap" :class="btnFilled('success')"
              :disabled="busy !== null" :title="t('payroll_imports.attendance_time.approve_hint')" @click="approveTimeMonths">
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.checkCircle" /></svg>
              {{ busy === 'approve' ? t('payroll_imports.attendance_time.approving') : t('payroll_imports.attendance_time.approve') }}
            </button>
          </div>
        </section>
      </section>
    </template>

    <AttendanceBatchHistory ref="history" :period="period" :can-approve="canApproveTime === true" />
  </section>
</template>

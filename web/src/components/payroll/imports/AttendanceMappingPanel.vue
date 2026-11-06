<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import {
  payrollImportsApi,
  type AttendanceComponentCheck,
  type AttendancePreview,
  type AttendanceProfile,
  type AttendanceRule,
  type AttendanceSampleKey,
  type AttendanceUnrecognizedColumn,
} from '@/api/payrollImports'
import { apiErrorMessage } from '@/api/errors'
import { useToast } from '@/composables/useToast'
import { formatDate } from '@/composables/useFormat'
import { useSupplierStore } from '@/stores/supplier'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import { BTN_DISABLED_NOTE, btnOutline, disabledTitle, ICONS } from '@/components/ui/buttonStyles'
import ImportFilesDropzone from './ImportFilesDropzone.vue'
import AttendanceMappingStep from './AttendanceMappingStep.vue'
import AttendanceRecognitionStrip from './AttendanceRecognitionStrip.vue'
import AttendanceRuleEditor from './AttendanceRuleEditor.vue'
import AttendanceProfileCopyDialog from './AttendanceProfileCopyDialog.vue'
import AttendanceTemplateDownload from './AttendanceTemplateDownload.vue'
import { useAttendanceWorkspace } from './attendanceWorkspace'
import { upgradedProfile, upgradeMatchesProfile } from './attendanceWages'
import {
  ATTENDANCE_FILE_ACCEPT,
  ATTENDANCE_FILE_EXTENSIONS,
  draftComponents,
  draftRules,
  draftSignature,
  duplicateDraft,
  emptyProfileDraft,
  emptyRuleDraft,
  filesFingerprint,
  isValidPeriod,
  missingSampleKeys,
  parseProfileExport,
  profileDraftIssues,
  profileExportFilename,
  profileSampleKey,
  profileToDraft,
  ruleFromUnrecognized,
  ruleIssues,
  uniqueProfileName,
  type ProfileDraft,
  type ProfileDraftIssue,
} from './importHelpers'

const props = defineProps<{
  /** Zápis profilů (oprávnění nastavení mezd); bez něj jen čtení a zkouška. */
  canManage: boolean
  /** Zkouška na souborech volá náhled docházky. */
  canPreview: boolean
}>()

const { t, te } = useI18n()
const toast = useToast()
const workspace = useAttendanceWorkspace()
const { period, files, profiles, profilesLoading, profilesError } = workspace
const supplierStore = useSupplierStore()

type Busy = 'save' | 'delete' | 'copy' | 'export' | 'import' | 'test' | 'restore'

const draft = ref<ProfileDraft | null>(null)
const baseline = ref('')
const lastOpenedId = ref<number | null>(null)
const busy = ref<Busy | null>(null)
const copyOpen = ref(false)
const testPreview = ref<AttendancePreview | null>(null)
const testSignature = ref('')
const testError = ref('')
const importInput = ref<HTMLInputElement | null>(null)
const restoringKey = ref<AttendanceSampleKey | null>(null)
const RESTORE_LABELS: Record<AttendanceSampleKey, { label: string; hint: string }> = {
  giriton: { label: 'payroll_imports.mapping.restore_sample', hint: 'payroll_imports.mapping.restore_sample_hint' },
  myucto: { label: 'payroll_imports.mapping.restore_sample_myucto', hint: 'payroll_imports.mapping.restore_sample_myucto_hint' },
  pamica: { label: 'payroll_imports.mapping.restore_sample_pamica', hint: 'payroll_imports.mapping.restore_sample_pamica_hint' },
}

const isNew = computed(() => draft.value !== null && draft.value.id === null)
const dirty = computed(() => draft.value !== null && draftSignature(draft.value) !== baseline.value)
const savedProfile = computed(() => {
  const id = draft.value?.id ?? null
  return id === null ? null : profiles.value.find(profile => profile.id === id) ?? null
})
const otherNames = computed(() => profiles.value.filter(profile => profile.id !== draft.value?.id).map(profile => profile.name))
const issues = computed<ProfileDraftIssue[]>(() => draft.value ? profileDraftIssues(draft.value, otherNames.value) : [])
const otherSuppliers = computed(() => supplierStore.availableSuppliers.filter(supplier => supplier.id !== supplierStore.currentSupplierId))
// Vzor jde vrátit, když ho firma smazala; dokud ho má, tlačítko se nenabízí.
const missingSamples = computed(() => missingSampleKeys(profiles.value))
const draftSampleKey = computed(() => draft.value?.is_sample ? profileSampleKey(savedProfile.value) : null)

const saveBlockedReason = computed(() => issues.value.length ? t('payroll_imports.mapping.reason.issues') : '')
/*
 * Nová verze vzoru pro profil, který firma upravila. Seznam profilů nese jen
 * příznak; pravidla nové verze posílá server s náhledem docházky, proto se
 * aktualizace nabízí až s nimi.
 */
const upgradePending = computed(() => savedProfile.value?.upgrade_available === true)
const upgradePayload = computed(() => {
  const upgrade = workspace.sampleUpgrade.value
  return upgradeMatchesProfile(upgrade, savedProfile.value?.id) ? upgrade : null
})
const upgradeBlockedReason = computed(() => upgradePayload.value ? '' : t('payroll_imports.mapping.upgrade_needs_preview'))
const savedOnlyReason = computed(() => dirty.value ? t('payroll_imports.mapping.reason.save_first') : '')

const currentTestSignature = computed(() => draft.value
  ? `${period.value}#${filesFingerprint(files.value)}#${draftSignature(draft.value)}`
  : '')
const testStale = computed(() => testPreview.value !== null && testSignature.value !== currentTestSignature.value)
const testBlockedReason = computed(() => {
  if (!props.canPreview) return t('payroll_imports.mapping.test.no_permission')
  if (!isValidPeriod(period.value)) return t('payroll_imports.attendance.reason.no_period')
  if (files.value.length === 0) return t('payroll_imports.attendance.reason.no_files')
  if (draft.value && ruleIssues(draft.value).length) return t('payroll_imports.mapping.reason.rule_issues')
  return ''
})
const testFileErrors = computed(() => (testPreview.value?.files ?? []).filter(file => file.error))

const actions = computed<ActionItem[]>(() => {
  if (!draft.value) return []
  const idle = busy.value === null
  return [
    {
      key: 'save', label: t('payroll_imports.mapping.save'), icon: 'check', tier: 'primary', variant: 'primary',
      show: props.canManage && (isNew.value || dirty.value),
      disabled: !idle || saveBlockedReason.value !== '', disabledReason: saveBlockedReason.value || undefined,
      loading: busy.value === 'save', run: () => { void save() },
    },
    {
      key: 'discard', label: t('payroll_imports.mapping.discard'), icon: 'uturn', tier: 'secondary', variant: 'neutral',
      show: props.canManage && (isNew.value || dirty.value), disabled: !idle, run: discard,
    },
    {
      key: 'duplicate', label: t('payroll_imports.mapping.duplicate'), icon: 'copy', tier: 'secondary', variant: 'neutral',
      show: props.canManage && !isNew.value, disabled: !idle, run: duplicate,
    },
    {
      key: 'export', label: t('payroll_imports.mapping.export'), icon: 'download', tier: 'overflow', variant: 'neutral',
      show: !isNew.value, disabled: !idle || dirty.value, disabledReason: savedOnlyReason.value || undefined,
      loading: busy.value === 'export', run: () => { void exportJson() },
    },
    {
      key: 'copy', label: t('payroll_imports.mapping.copy_to'), icon: 'swap', tier: 'overflow', variant: 'neutral',
      show: props.canManage && !isNew.value && otherSuppliers.value.length > 0,
      disabled: !idle || dirty.value, disabledReason: savedOnlyReason.value || undefined,
      run: () => { copyOpen.value = true },
    },
    {
      key: 'delete', label: t('payroll_imports.mapping.delete'), icon: 'trash', tier: 'overflow', variant: 'danger',
      show: props.canManage && !isNew.value, disabled: !idle, run: () => { void remove() },
    },
  ]
})

function issueText(issue: ProfileDraftIssue): string {
  const key = `payroll_imports.mapping.issues.${issue.kind}`
  return te(key) ? t(key, 'row' in issue ? { ...issue } : {}) : issue.kind
}

function kindLabel(kind: string | null): string {
  if (!kind) return ''
  const key = `payroll_imports.component_kinds.${kind}`
  return te(key) ? t(key) : kind
}

function checkClass(check: AttendanceComponentCheck): string {
  return {
    ok: 'bg-success-50 text-success-700',
    missing: 'bg-danger-50 text-danger-600',
    not_one_off: 'bg-warning-50 text-warning-700',
    will_create: 'bg-primary-50 text-primary-700',
  }[check.status] ?? 'bg-neutral-100 text-neutral-600'
}

function confirmDiscard(): boolean {
  return !dirty.value || window.confirm(t('payroll_imports.mapping.discard_confirm'))
}

function resetTest() {
  testPreview.value = null
  testSignature.value = ''
  testError.value = ''
}

function setDraft(next: ProfileDraft) {
  draft.value = next
  baseline.value = draftSignature(next)
}

function openProfile(profile: AttendanceProfile) {
  lastOpenedId.value = profile.id
  setDraft(profileToDraft(profile))
  resetTest()
}

function clearDraft() {
  draft.value = null
  baseline.value = ''
  resetTest()
}

function selectProfile(profile: AttendanceProfile) {
  if (draft.value?.id === profile.id) return
  if (confirmDiscard()) openProfile(profile)
}

function startNew(seedRules: AttendanceRule[] | null = null): boolean {
  if (!confirmDiscard()) return false
  const next = emptyProfileDraft(
    uniqueProfileName(t('payroll_imports.mapping.new_name'), profiles.value.map(profile => profile.name)),
    seedRules ?? [],
  )
  if (next.rules.length === 0) next.rules.push(emptyRuleDraft({ meaning: 'person_name', unit: 'text' }))
  setDraft(next)
  resetTest()
  return true
}

function duplicate() {
  if (!draft.value) return
  // Neuložené úpravy se do kopie přenesou, takže o ně uživatel nepřijde.
  const base = t('payroll_imports.mapping.copy_name', { name: draft.value.name.trim() || t('payroll_imports.mapping.new_name') })
  setDraft(duplicateDraft(draft.value, uniqueProfileName(base, profiles.value.map(profile => profile.name))))
  toast.success(t('payroll_imports.mapping.duplicated'))
}

function discard() {
  if (!draft.value) return
  if (dirty.value && !window.confirm(t('payroll_imports.mapping.discard_confirm'))) return
  const fallback = savedProfile.value
    ?? profiles.value.find(profile => profile.id === lastOpenedId.value)
    ?? profiles.value[0]
    ?? null
  if (fallback) openProfile(fallback)
  else clearDraft()
}

async function save() {
  const current = draft.value
  if (!current || !props.canManage || saveBlockedReason.value !== '' || busy.value !== null) return
  busy.value = 'save'
  try {
    const saved = await payrollImportsApi.saveAttendanceProfile({
      id: current.id,
      name: current.name.trim(),
      rules: draftRules(current),
      components: draftComponents(current),
    })
    workspace.upsertProfile(saved)
    lastOpenedId.value = saved.id
    // Výsledek zkoušky zůstává — pravidla se uložením nezměnila.
    setDraft(profileToDraft(saved))
    toast.success(t('payroll_imports.mapping.saved', { name: saved.name }))
  } catch (err) {
    toast.error(apiErrorMessage(err, t('payroll_imports.mapping.save_failed')))
  } finally {
    busy.value = null
  }
}

// Uloží pravidla a složky nové verze vzoru běžnou cestou uložení profilu;
// server pak vzor při dalším načtení označí jako aktuální.
async function updateSample() {
  const profile = savedProfile.value
  const upgrade = upgradePayload.value
  if (!profile || !upgrade || !props.canManage || busy.value !== null) return
  if (!window.confirm(t('payroll_imports.mapping.upgrade_confirm', { name: profile.name }))) return
  draft.value = profileToDraft(upgradedProfile(profile, upgrade))
  await save()
  if (!dirty.value) {
    workspace.sampleUpgrade.value = null
    await workspace.loadProfiles()
  }
}

async function remove() {
  const profile = savedProfile.value
  if (!profile || busy.value !== null) return
  if (!window.confirm(t('payroll_imports.mapping.delete_confirm', { name: profile.name }))) return
  busy.value = 'delete'
  try {
    await payrollImportsApi.deleteAttendanceProfile(profile.id)
    workspace.removeProfile(profile.id)
    toast.success(t('payroll_imports.mapping.deleted', { name: profile.name }))
    const next = profiles.value[0]
    if (next) openProfile(next)
    else clearDraft()
  } catch (err) {
    toast.error(apiErrorMessage(err, t('payroll_imports.mapping.delete_failed')))
  } finally {
    busy.value = null
  }
}

async function restoreSample(sampleKey: AttendanceSampleKey) {
  if (busy.value !== null || !confirmDiscard()) return
  busy.value = 'restore'
  restoringKey.value = sampleKey
  try {
    const { profile, restored } = await payrollImportsApi.restoreAttendanceSampleProfile(sampleKey)
    workspace.upsertProfile(profile)
    openProfile(profile)
    toast.success(t(restored ? 'payroll_imports.mapping.sample_restored' : 'payroll_imports.mapping.sample_exists', { name: profile.name }))
  } catch (err) {
    toast.error(apiErrorMessage(err, t('payroll_imports.mapping.sample_restore_failed')))
  } finally {
    busy.value = null
    restoringKey.value = null
  }
}

async function copyTo(supplierId: number) {
  const profile = savedProfile.value
  if (!profile || busy.value !== null) return
  busy.value = 'copy'
  try {
    await payrollImportsApi.copyAttendanceProfile(profile.id, supplierId)
    const company = otherSuppliers.value.find(supplier => supplier.id === supplierId)?.company_name ?? ''
    toast.success(t('payroll_imports.mapping.copy.done', { name: profile.name, company }))
    copyOpen.value = false
  } catch (err) {
    toast.error(apiErrorMessage(err, t('payroll_imports.mapping.copy.failed')))
  } finally {
    busy.value = null
  }
}

async function exportJson() {
  const profile = savedProfile.value
  if (!profile || dirty.value || busy.value !== null) return
  busy.value = 'export'
  try {
    await payrollImportsApi.exportAttendanceProfile(profile.id, profileExportFilename(profile.name))
  } catch (err) {
    toast.error(apiErrorMessage(err, t('payroll_imports.mapping.export_failed')))
  } finally {
    busy.value = null
  }
}

function pickImport() {
  if (busy.value !== null || !confirmDiscard()) return
  importInput.value?.click()
}

async function onImportFile(event: Event) {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]
  // Bez vyčištění by výběr téhož souboru podruhé neodpálil `change`.
  input.value = ''
  if (!file || busy.value !== null) return
  busy.value = 'import'
  try {
    const parsed = parseProfileExport(await file.text())
    if (!parsed.ok) {
      toast.error(t(`payroll_imports.mapping.import_reject.${parsed.reason}`, { name: file.name }))
      return
    }
    const wanted = parsed.profile.name.trim() || t('payroll_imports.mapping.new_name')
    const name = uniqueProfileName(wanted, profiles.value.map(profile => profile.name))
    const saved = await payrollImportsApi.importAttendanceProfile(parsed.profile, name !== parsed.profile.name ? name : undefined)
    workspace.upsertProfile(saved)
    openProfile(saved)
    toast.success(t('payroll_imports.mapping.imported', { name: saved.name }))
  } catch (err) {
    toast.error(apiErrorMessage(err, t('payroll_imports.mapping.import_failed')))
  } finally {
    busy.value = null
  }
}

async function runTest() {
  const current = draft.value
  if (!current || testBlockedReason.value !== '' || busy.value !== null) return
  busy.value = 'test'
  testError.value = ''
  const signature = currentTestSignature.value
  try {
    testPreview.value = await payrollImportsApi.previewAttendance({
      period: period.value,
      files: await workspace.payloadFiles(),
      rules: draftRules(current),
      profile_id: null,
      components: draftComponents(current),
    })
    if (testPreview.value.upgrade_available) workspace.sampleUpgrade.value = testPreview.value.upgrade_available
    testSignature.value = signature
  } catch (err) {
    testError.value = apiErrorMessage(err, t('payroll_imports.mapping.test.failed'))
  } finally {
    busy.value = null
  }
}

function addRuleFromColumn(column: AttendanceUnrecognizedColumn) {
  if (!draft.value || !testPreview.value || !props.canManage) return
  draft.value.rules.push(ruleFromUnrecognized(column, testPreview.value.sheets))
  toast.success(t('payroll_imports.mapping.rule_added', { header: column.header }))
}

// První návštěva záložky: otevřít první profil (typicky vzor), ať editor není prázdný.
watch(profiles, list => {
  if (draft.value === null && list.length > 0) openProfile(list[0])
}, { immediate: true })

// „Upravit mapování" z měsíčního importu: otevřít použitý profil a rovnou ho
// vyzkoušet na už nahraných souborech.
watch(workspace.mappingFocus, focus => {
  if (!focus) return
  let ready = true
  if (focus.profileId !== null) {
    const profile = profiles.value.find(item => item.id === focus.profileId)
    if (profile && draft.value?.id !== profile.id) {
      if (confirmDiscard()) openProfile(profile)
      else ready = false
    }
  } else if (props.canManage) {
    ready = startNew(focus.seedRules)
  }
  if (ready && testBlockedReason.value === '') void runTest()
})
</script>

<template>
  <section class="space-y-4" data-testid="attendance-mapping">
    <div>
      <h2 class="text-lg font-semibold text-neutral-900">{{ t('payroll_imports.mapping.title') }}</h2>
      <p class="mt-1 max-w-3xl text-sm text-neutral-500">{{ t('payroll_imports.mapping.hint') }}</p>
    </div>

    <p v-if="!canManage" class="rounded-lg border border-warning-500/30 bg-warning-50 px-4 py-3 text-sm text-warning-700">
      {{ t('payroll_imports.mapping.read_only') }}
    </p>

    <input ref="importInput" type="file" accept=".json,application/json" class="sr-only" tabindex="-1" aria-hidden="true" @change="onImportFile">

    <div class="grid min-w-0 grid-cols-1 gap-4 lg:grid-cols-[minmax(15rem,20rem)_minmax(0,1fr)]">
      <!-- Seznam profilů -->
      <aside class="min-w-0 space-y-3">
        <div v-if="canManage" class="flex flex-wrap gap-2">
          <button type="button" data-testid="attendance-profile-new" :class="btnOutline('primary')" :disabled="busy !== null" @click="startNew()">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.plus" /></svg>
            {{ t('payroll_imports.mapping.new') }}
          </button>
          <button type="button" data-testid="attendance-profile-import" :class="btnOutline('neutral')" :disabled="busy !== null" @click="pickImport">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.upload" /></svg>
            {{ busy === 'import' ? t('payroll_imports.common.working') : t('payroll_imports.mapping.import') }}
          </button>
          <template v-if="!profilesLoading && !profilesError">
            <button v-for="key in missingSamples" :key="key" type="button" :data-testid="`attendance-profile-restore-sample-${key}`"
              class="whitespace-nowrap" :class="btnOutline('neutral')"
              :disabled="busy !== null" :title="t(RESTORE_LABELS[key].hint)" @click="restoreSample(key)">
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.cycle" /></svg>
              {{ restoringKey === key ? t('payroll_imports.common.working') : t(RESTORE_LABELS[key].label) }}
            </button>
          </template>
        </div>

        <div v-if="profilesLoading && profiles.length === 0" class="space-y-2">
          <div v-for="index in 3" :key="index" class="h-14 animate-pulse rounded-lg bg-neutral-100" />
        </div>
        <div v-else-if="profilesError" role="alert" class="space-y-2 rounded-lg border border-danger-500/30 bg-danger-50 px-4 py-3 text-sm text-danger-700">
          <p>{{ profilesError }}</p>
          <button type="button" :class="btnOutline('neutral')" @click="workspace.loadProfiles()">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.cycle" /></svg>
            {{ t('payroll_imports.common.reload') }}
          </button>
        </div>
        <p v-else-if="profiles.length === 0 && !isNew" class="rounded-lg border border-dashed border-neutral-300 px-4 py-6 text-sm text-neutral-500">
          {{ t(canManage ? 'payroll_imports.mapping.empty' : 'payroll_imports.mapping.empty_read_only') }}
        </p>

        <nav v-if="profiles.length || isNew" class="space-y-2" :aria-label="t('payroll_imports.mapping.list_label')">
          <div v-if="isNew" class="rounded-lg border border-primary-500 bg-surface-raised p-3 ring-2 ring-primary-500/30">
            <span class="flex flex-wrap items-center justify-between gap-2">
              <strong class="truncate">{{ draft?.name.trim() || t('payroll_imports.mapping.new_name') }}</strong>
              <span class="rounded px-2 py-0.5 text-xs font-medium bg-warning-50 text-warning-700">{{ t('payroll_imports.mapping.unsaved_badge') }}</span>
            </span>
          </div>
          <button v-for="profile in profiles" :key="profile.id" type="button" :data-testid="`attendance-profile-${profile.id}`"
            class="w-full cursor-pointer rounded-lg border p-3 text-left shadow-sm transition-colors hover:border-primary-400"
            :class="draft?.id === profile.id ? 'border-primary-500 bg-surface-raised ring-2 ring-primary-500/30' : 'border-neutral-200 bg-surface'"
            :aria-current="draft?.id === profile.id ? 'true' : undefined"
            @click="selectProfile(profile)">
            <span class="flex flex-wrap items-center justify-between gap-2">
              <strong class="min-w-0 truncate">{{ profile.name }}</strong>
              <span class="flex flex-wrap gap-1">
                <span v-if="profile.is_sample" class="rounded bg-accent-50 px-2 py-0.5 text-xs font-medium text-accent-700">{{ t('payroll_imports.sample_badge') }}</span>
                <span v-if="profile.upgrade_available" :data-testid="`attendance-profile-${profile.id}-upgrade`" class="whitespace-nowrap rounded bg-warning-50 px-2 py-0.5 text-xs font-medium text-warning-700">{{ t('payroll_imports.mapping.upgrade_badge') }}</span>
              </span>
            </span>
            <span class="mt-1 block text-xs text-neutral-500">
              {{ t('payroll_imports.mapping.list_meta', { rules: profile.rules.length, date: formatDate(profile.updated_at) }) }}
            </span>
          </button>
        </nav>
      </aside>

      <!-- Editor profilu -->
      <div v-if="draft" class="min-w-0 space-y-4">
        <section class="rounded-xl border border-neutral-200 bg-surface p-4 shadow-sm sm:p-6">
          <div class="flex flex-wrap items-end justify-between gap-3">
            <label class="block min-w-56 flex-1">
              <span class="mb-1 block text-xs font-medium text-neutral-600">{{ t('payroll_imports.mapping.name') }}</span>
              <input v-model="draft.name" data-testid="attendance-profile-name" maxlength="120" :disabled="!canManage"
                class="h-9 w-full rounded-md border bg-surface px-3 text-sm disabled:bg-neutral-100"
                :class="issues.some(issue => issue.kind === 'name_missing' || issue.kind === 'name_taken') ? 'border-danger-500' : 'border-neutral-300'">
            </label>
            <div class="flex flex-wrap items-center gap-2 pb-1.5 text-xs">
              <span v-if="draft.is_sample" class="rounded bg-accent-50 px-2 py-0.5 font-medium text-accent-700">{{ t('payroll_imports.sample_badge') }}</span>
              <span class="rounded px-2 py-0.5 font-medium"
                :class="isNew || dirty ? 'bg-warning-50 text-warning-700' : 'bg-success-50 text-success-700'">
                {{ t(isNew || dirty ? 'payroll_imports.mapping.state_dirty' : 'payroll_imports.mapping.state_saved') }}
              </span>
              <span v-if="savedProfile" class="text-neutral-500">{{ t('payroll_imports.mapping.updated_at', { date: formatDate(savedProfile.updated_at) }) }}</span>
            </div>
          </div>
          <div v-if="draftSampleKey === 'myucto'" class="mt-3 flex flex-wrap items-center justify-between gap-3 rounded-lg bg-accent-50 px-3 py-2 text-sm text-accent-700"
            data-testid="attendance-profile-myucto-hint">
            <p class="min-w-0 max-w-2xl flex-1">{{ t('payroll_imports.mapping.sample_hint_myucto') }}</p>
            <AttendanceTemplateDownload v-if="canPreview" />
          </div>
          <div v-else-if="draftSampleKey === 'pamica'" class="mt-3 flex flex-wrap items-center justify-between gap-3 rounded-lg bg-accent-50 px-3 py-2 text-sm text-accent-700"
            data-testid="attendance-profile-pamica-hint">
            <p class="min-w-0 max-w-2xl flex-1">{{ t('payroll_imports.mapping.sample_hint_pamica') }}</p>
            <AttendanceTemplateDownload v-if="canPreview" sample-key="pamica" :period="period" />
          </div>
          <p v-else-if="draft.is_sample" class="mt-3 rounded-lg bg-accent-50 px-3 py-2 text-sm text-accent-700">{{ t('payroll_imports.mapping.sample_hint') }}</p>
          <div v-if="upgradePending" class="mt-3 rounded-lg border border-warning-500/30 bg-warning-50 px-3 py-2 text-sm text-warning-800" data-testid="attendance-profile-upgrade">
            <p class="font-medium">{{ t('payroll_imports.mapping.upgrade_title') }}</p>
            <p class="mt-0.5 text-xs">{{ t('payroll_imports.mapping.upgrade_hint') }}</p>
            <div v-if="canManage" class="mt-2 flex flex-col items-start gap-1.5">
              <button type="button" data-testid="attendance-profile-upgrade-run" class="whitespace-nowrap" :class="btnOutline('warning')"
                :disabled="busy !== null || upgradeBlockedReason !== ''"
                :title="disabledTitle(upgradeBlockedReason !== '', upgradeBlockedReason)" @click="updateSample">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.cycle" /></svg>
                {{ busy === 'save' ? t('payroll_imports.common.working') : t('payroll_imports.mapping.upgrade_action') }}
              </button>
              <p v-if="upgradeBlockedReason" :class="BTN_DISABLED_NOTE">{{ upgradeBlockedReason }}</p>
            </div>
          </div>
          <div class="mt-4">
            <ActionBar :actions="actions" />
          </div>
        </section>

        <div v-if="issues.length && canManage" role="status" class="rounded-lg border border-warning-500/30 bg-warning-50 px-4 py-3 text-sm text-warning-700">
          <p v-for="(issue, index) in issues" :key="`${issue.kind}-${index}`">{{ issueText(issue) }}</p>
        </div>

        <AttendanceRuleEditor v-model:draft="draft" :readonly="!canManage" :issues="issues" />

        <!-- Vyzkoušet na souborech -->
        <section class="rounded-xl border border-neutral-200 bg-surface p-4 shadow-sm sm:p-6" data-testid="attendance-mapping-test">
          <h3 class="font-semibold text-neutral-900">{{ t('payroll_imports.mapping.test.title') }}</h3>
          <p class="mt-1 max-w-3xl text-sm text-neutral-500">{{ t('payroll_imports.mapping.test.hint') }}</p>

          <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <label class="block">
              <span class="mb-1 block text-xs font-medium text-neutral-600">{{ t('payroll_imports.attendance.period') }}</span>
              <input v-model="period" type="month" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm" :disabled="busy !== null">
            </label>
          </div>
          <div class="mt-4">
            <ImportFilesDropzone
              v-model:files="files"
              test-id="attendance-mapping-dropzone"
              :accept="ATTENDANCE_FILE_ACCEPT"
              :allowed-extensions="ATTENDANCE_FILE_EXTENSIONS"
              :drop-hint="t('payroll_imports.attendance.drop_hint')"
              :disabled="busy !== null"
            />
          </div>

          <div class="mt-4 flex flex-col items-start gap-1.5">
            <button type="button" data-testid="attendance-mapping-test-run" :class="btnOutline('primary')"
              :disabled="busy !== null || testBlockedReason !== ''"
              :title="disabledTitle(testBlockedReason !== '', testBlockedReason)" @click="runTest">
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.play" /></svg>
              {{ busy === 'test' ? t('payroll_imports.common.working') : t('payroll_imports.mapping.test.run') }}
            </button>
            <p v-if="testBlockedReason" :class="BTN_DISABLED_NOTE">{{ testBlockedReason }}</p>
          </div>

          <p v-if="testError" role="alert" class="mt-4 rounded-lg border border-danger-500/30 bg-danger-50 px-4 py-3 text-sm text-danger-700">{{ testError }}</p>

          <div v-if="testPreview" class="mt-6 space-y-4">
            <p v-if="testStale" role="status" class="rounded-lg border border-warning-500/30 bg-warning-50 px-4 py-2 text-sm text-warning-700">{{ t('payroll_imports.mapping.test.stale') }}</p>

            <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
              <article class="rounded-lg bg-neutral-50 p-3"><p class="text-xs text-neutral-500">{{ t('payroll_imports.mapping.test.persons') }}</p><p class="mt-1 text-lg font-semibold">{{ testPreview.summary.persons }}</p></article>
              <article class="rounded-lg bg-success-50 p-3"><p class="text-xs text-success-700">{{ t('payroll_imports.mapping.test.matched') }}</p><p class="mt-1 text-lg font-semibold text-success-700">{{ testPreview.summary.matched }}</p></article>
              <article class="rounded-lg p-3" :class="testPreview.summary.ambiguous + testPreview.summary.not_found ? 'bg-warning-50' : 'bg-neutral-50'">
                <p class="text-xs" :class="testPreview.summary.ambiguous + testPreview.summary.not_found ? 'text-warning-700' : 'text-neutral-500'">{{ t('payroll_imports.mapping.test.unmatched') }}</p>
                <p class="mt-1 text-lg font-semibold">{{ testPreview.summary.ambiguous + testPreview.summary.not_found }}</p>
              </article>
              <article class="rounded-lg p-3" :class="(testPreview.unrecognized_columns ?? []).length ? 'bg-warning-50' : 'bg-neutral-50'">
                <p class="text-xs" :class="(testPreview.unrecognized_columns ?? []).length ? 'text-warning-700' : 'text-neutral-500'">{{ t('payroll_imports.mapping.test.unrecognized') }}</p>
                <p class="mt-1 text-lg font-semibold">{{ (testPreview.unrecognized_columns ?? []).length }}</p>
              </article>
            </div>

            <div v-if="testFileErrors.length" role="status" class="rounded-lg border border-danger-500/30 bg-danger-50 px-4 py-3 text-sm text-danger-700">
              <p v-for="file in testFileErrors" :key="file.name">{{ t('payroll_imports.attendance.file_error', { name: file.name, error: file.error }) }}</p>
            </div>

            <AttendanceRecognitionStrip :preview="testPreview" :show-profile="false" :can-add-rule="canManage" @add-rule="addRuleFromColumn" />

            <section v-if="testPreview.component_checks.length" class="rounded-xl border border-neutral-200 bg-surface p-4 shadow-sm">
              <h4 class="text-sm font-semibold text-neutral-900">{{ t('payroll_imports.attendance.summary.component_checks') }}</h4>
              <ul class="mt-2 space-y-1.5">
                <li v-for="check in testPreview.component_checks" :key="check.component_code" class="flex flex-wrap items-center gap-2 text-sm">
                  <span class="font-mono text-xs font-medium text-neutral-800">{{ check.component_code }}</span>
                  <span v-if="check.name" class="text-xs text-neutral-700">{{ check.name }}<template v-if="check.kind"> ({{ kindLabel(check.kind) }})</template></span>
                  <span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="checkClass(check)">{{ t(`payroll_imports.attendance.summary.check_status.${check.status}`) }}</span>
                  <span v-if="check.message" class="text-xs text-neutral-600">{{ check.message }}</span>
                </li>
              </ul>
            </section>

            <div>
              <h4 class="mb-2 text-sm font-semibold text-neutral-900">{{ t('payroll_imports.mapping.test.result_title') }}</h4>
              <AttendanceMappingStep :sheets="testPreview.sheets" />
            </div>
          </div>
        </section>
      </div>
    </div>

    <AttendanceProfileCopyDialog
      v-if="copyOpen && savedProfile"
      :profile-name="savedProfile.name"
      :suppliers="otherSuppliers"
      :busy="busy === 'copy'"
      @close="copyOpen = false"
      @submit="copyTo"
    />
  </section>
</template>

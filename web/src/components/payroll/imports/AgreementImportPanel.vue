<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import {
  payrollImportsApi,
  type AgreementImportOutcome,
  type AgreementImportPerson,
  type AgreementImportPreview,
  type AgreementImportResult,
  type AgreementImportStatus,
} from '@/api/payrollImports'
import { apiErrorMessage } from '@/api/errors'
import { useToast } from '@/composables/useToast'
import { formatDate } from '@/composables/useFormat'
import { BTN_DISABLED_NOTE, btnFilled, btnOutline, disabledTitle, ICONS } from '@/components/ui/buttonStyles'
import ImportFilesDropzone from './ImportFilesDropzone.vue'
import { filesFingerprint, filesToPayload } from './importHelpers'
import { registrationSummary } from './attendanceRegistration'

const props = defineProps<{ canWrite: boolean }>()

const { t } = useI18n()
const toast = useToast()

const CHUNK = 100
const SUMMARY_ITEMS = ['persons', 'new_persons', 'existing_persons', 'confirm', 'create', 'exists', 'errors'] as const
const STATUS_CLASS: Record<AgreementImportStatus, string> = {
  create: 'bg-success-50 text-success-700',
  exists: 'bg-neutral-100 text-neutral-600',
  duplicate: 'bg-warning-50 text-warning-700',
  error: 'bg-danger-50 text-danger-600',
}
const OUTCOME_CLASS: Record<AgreementImportOutcome, string> = {
  created: 'bg-success-50 text-success-700',
  existing: 'bg-neutral-100 text-neutral-600',
  skipped: 'bg-warning-50 text-warning-700',
  failed: 'bg-danger-50 text-danger-600',
}

const files = ref<File[]>([])
const preview = ref<AgreementImportPreview | null>(null)
const previewFingerprint = ref('')
const confirmations = ref<Record<string, number | 'new' | null>>({})
const result = ref<AgreementImportResult | null>(null)
const progress = ref<{ done: number; total: number } | null>(null)
const busy = ref<'preview' | 'apply' | 'template' | null>(null)
const error = ref('')

const fingerprint = computed(() => filesFingerprint(files.value))

watch(fingerprint, value => {
  if (preview.value && value !== previewFingerprint.value) {
    preview.value = null
    confirmations.value = {}
  }
  result.value = null
})

function creatable(person: AgreementImportPerson): boolean {
  return person.errors.length === 0 && person.agreements.some(agreement => agreement.status === 'create')
}

function needsConfirmation(person: AgreementImportPerson): boolean {
  return person.existing_person?.requires_confirmation === true && creatable(person)
}

const unconfirmed = computed(() => (preview.value?.persons ?? [])
  .filter(person => needsConfirmation(person) && (confirmations.value[person.key] ?? null) === null).length)
const toCreate = computed(() => (preview.value?.persons ?? []).filter(creatable))
const agreementsToCreate = computed(() => toCreate.value
  .reduce((sum, person) => sum + person.agreements.filter(agreement => agreement.status === 'create').length, 0))
const applyBlockedReason = computed(() => {
  if (!preview.value) return ''
  if (agreementsToCreate.value === 0) return t('payroll_imports.agreements.reason.nothing')
  if (unconfirmed.value > 0) return t('payroll_imports.agreements.reason.unconfirmed', { count: unconfirmed.value })
  return ''
})
const canApply = computed(() => props.canWrite && busy.value === null && preview.value !== null && applyBlockedReason.value === '')

async function runPreview(options: { keepResult?: boolean } = {}) {
  if (files.value.length === 0) return
  error.value = ''
  if (!options.keepResult) result.value = null
  busy.value = 'preview'
  try {
    const current = fingerprint.value
    preview.value = await payrollImportsApi.previewAgreements(await filesToPayload(files.value))
    previewFingerprint.value = current
    if (!options.keepResult) confirmations.value = {}
  } catch (err) {
    error.value = apiErrorMessage(err, t('payroll_imports.agreements.preview_failed'))
  } finally {
    busy.value = null
  }
}

async function runApply() {
  if (!canApply.value) return
  error.value = ''
  busy.value = 'apply'
  const keys = toCreate.value.map(person => person.key)
  const chosen: Record<string, number | 'new'> = {}
  for (const [key, value] of Object.entries(confirmations.value)) {
    if (value !== null) chosen[key] = value
  }
  const merged: AgreementImportResult = { results: [], counts: { created: 0, existing: 0, skipped: 0, failed: 0 } }
  progress.value = { done: 0, total: keys.length }
  try {
    const payload = await filesToPayload(files.value)
    for (let offset = 0; offset < keys.length; offset += CHUNK) {
      const response = await payrollImportsApi.applyAgreements({
        files: payload,
        confirmations: chosen,
        person_keys: keys.slice(offset, offset + CHUNK),
      })
      merged.results.push(...response.results)
      for (const outcome of Object.keys(merged.counts) as AgreementImportOutcome[]) {
        merged.counts[outcome] += response.counts[outcome] ?? 0
      }
      progress.value = { done: Math.min(offset + CHUNK, keys.length), total: keys.length }
    }
    result.value = merged
    if (merged.counts.failed > 0) toast.warning(t('payroll_imports.agreements.applied_with_errors', merged.counts))
    else toast.success(t('payroll_imports.agreements.applied', merged.counts))
    busy.value = null
    await runPreview({ keepResult: true })
  } catch (err) {
    error.value = apiErrorMessage(err, t('payroll_imports.agreements.apply_failed'))
    if (merged.results.length > 0) result.value = merged
  } finally {
    busy.value = null
    progress.value = null
  }
}

async function downloadTemplate() {
  if (busy.value !== null) return
  busy.value = 'template'
  try {
    await payrollImportsApi.downloadAgreementTemplate()
  } catch (err) {
    toast.error(apiErrorMessage(err, t('payroll_imports.template.failed')))
  } finally {
    busy.value = null
  }
}

function relationText(type: string | null, label: string | null): string {
  return type ? t(`payroll_imports.agreements.relation.${type}`) : (label ?? '—')
}

function period(start: string | null, end: string | null): string {
  if (!start) return '—'
  return end ? `${formatDate(start)} – ${formatDate(end)}` : t('payroll_imports.agreements.open_ended', { date: formatDate(start) })
}

const failedResults = computed(() => (result.value?.results ?? []).filter(item => item.outcome === 'failed' || item.outcome === 'skipped' || item.message))
</script>

<template>
  <section class="space-y-4" data-testid="agreement-import">
    <div>
      <h2 class="text-lg font-semibold text-neutral-900">{{ t('payroll_imports.agreements.title') }}</h2>
      <p class="mt-1 max-w-3xl text-sm text-neutral-500">{{ t('payroll_imports.agreements.hint') }}</p>
    </div>

    <p v-if="!canWrite" class="rounded-lg border border-warning-500/30 bg-warning-50 px-4 py-3 text-sm text-warning-700">
      {{ t('payroll_imports.agreements.no_permission') }}
    </p>

    <section class="rounded-xl border border-neutral-200 bg-surface p-4 shadow-sm sm:p-6">
      <ImportFilesDropzone
        v-model:files="files"
        test-id="agreement-dropzone"
        accept=".xlsx,.csv"
        :allowed-extensions="['xlsx', 'csv']"
        :drop-hint="t('payroll_imports.agreements.drop_hint')"
        :disabled="!canWrite || busy !== null"
      />
      <div class="mt-4 flex flex-wrap items-center gap-2">
        <button
          type="button"
          data-testid="agreement-preview"
          :class="btnOutline('primary')"
          class="whitespace-nowrap"
          :disabled="!canWrite || busy !== null || files.length === 0"
          :title="disabledTitle(files.length === 0, t('payroll_imports.agreements.reason.no_files'))"
          @click="runPreview()"
        >
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.search" /></svg>
          {{ busy === 'preview' ? t('payroll_imports.common.working') : t(preview ? 'payroll_imports.agreements.refresh_preview' : 'payroll_imports.agreements.preview') }}
        </button>
        <button type="button" data-testid="agreement-template" class="whitespace-nowrap" :class="btnOutline('neutral')"
          :disabled="busy !== null" :title="t('payroll_imports.agreements.template_hint')" @click="downloadTemplate">
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.download" /></svg>
          {{ busy === 'template' ? t('payroll_imports.common.working') : t('payroll_imports.agreements.template') }}
        </button>
      </div>
      <p v-if="error" role="alert" class="mt-4 rounded-lg border border-danger-500/30 bg-danger-50 px-4 py-3 text-sm text-danger-700">{{ error }}</p>
    </section>

    <template v-if="preview">
      <ul v-if="preview.files.some(file => file.error)" class="space-y-1">
        <li v-for="file in preview.files.filter(item => item.error)" :key="file.name" class="rounded-lg bg-danger-50 px-3 py-2 text-sm text-danger-700">
          {{ file.name }}: {{ file.error }}
        </li>
      </ul>

      <div class="grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-7" data-testid="agreement-summary">
        <article v-for="item in SUMMARY_ITEMS" :key="item" class="rounded-lg p-3"
          :class="item === 'errors' && preview.summary.errors > 0 ? 'bg-danger-50' : item === 'confirm' && preview.summary.confirm > 0 ? 'bg-warning-50' : 'bg-neutral-50'">
          <p class="text-xs text-neutral-500">{{ t(`payroll_imports.agreements.summary.${item}`) }}</p>
          <p class="mt-1 text-lg font-semibold text-neutral-900">{{ preview.summary[item] }}</p>
        </article>
      </div>

      <section class="rounded-xl border border-neutral-200 bg-surface shadow-sm">
        <h3 class="border-b border-neutral-100 px-4 py-3 font-semibold text-neutral-900">{{ t('payroll_imports.agreements.persons_title') }}</h3>
        <p v-if="preview.persons.length === 0" class="px-4 py-6 text-sm text-neutral-500">{{ t('payroll_imports.agreements.no_rows') }}</p>
        <ul v-else class="max-h-[70vh] divide-y divide-neutral-100 overflow-auto">
          <li v-for="person in preview.persons" :key="person.key" class="px-4 py-3 text-sm" :data-testid="`agreement-person-${person.key}`">
            <div class="flex flex-wrap items-start justify-between gap-2">
              <div class="min-w-0">
                <p class="font-medium text-neutral-900">{{ person.display_name || '—' }}</p>
                <p class="font-mono text-xs text-neutral-500">
                  {{ person.birth_number_masked ?? (person.birth_date ? formatDate(person.birth_date) : '—') }}<template v-if="person.personal_number"> · {{ person.personal_number }}</template>
                </p>
              </div>
              <span v-if="person.existing_person && !person.existing_person.requires_confirmation" class="text-xs text-neutral-600">
                {{ t('payroll_imports.agreements.existing', { name: person.existing_person.employee_name }) }}
                <RouterLink :to="{ name: 'payroll-person', params: { id: person.existing_person.employee_id } }" class="ml-1 text-payroll-600 hover:underline">{{ t('payroll_imports.common.open_person') }}</RouterLink>
              </span>
              <span v-else-if="!person.existing_person" class="rounded-full bg-payroll-50 px-2 py-0.5 text-xs text-payroll-700">{{ t('payroll_imports.agreements.new_person') }}</span>
            </div>
            <fieldset v-if="needsConfirmation(person)" class="mt-2 rounded-lg bg-warning-50 p-2 text-xs text-warning-800">
              <legend class="sr-only">{{ t('payroll_imports.agreements.confirm_legend') }}</legend>
              <p>{{ t('payroll_imports.agreements.confirm_hint', { name: person.existing_person!.employee_name }) }}</p>
              <label class="mr-4 inline-flex items-center gap-1">
                <input v-model="confirmations[person.key]" type="radio" :value="person.existing_person!.employee_id" :disabled="!canWrite || busy !== null">
                {{ t('payroll_imports.agreements.confirm_existing') }}
              </label>
              <label class="inline-flex items-center gap-1">
                <input v-model="confirmations[person.key]" type="radio" value="new" :disabled="!canWrite || busy !== null">
                {{ t('payroll_imports.agreements.confirm_new') }}
              </label>
            </fieldset>
            <ul v-if="person.errors.length || person.warnings.length" class="mt-1 space-y-0.5 text-xs">
              <li v-for="item in person.errors" :key="item" class="text-danger-600">{{ item }}</li>
              <li v-for="item in person.warnings" :key="item" class="text-warning-700">{{ item }}</li>
            </ul>
            <ul class="mt-2 space-y-1">
              <li v-for="agreement in person.agreements" :key="agreement.key" class="rounded-lg bg-neutral-50 px-3 py-2">
                <div class="flex flex-wrap items-center gap-2">
                  <span class="whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-medium" :class="STATUS_CLASS[agreement.status]">{{ t(`payroll_imports.agreements.status.${agreement.status}`) }}</span>
                  <span class="font-medium text-neutral-800">{{ relationText(agreement.relation_type, agreement.relation_label) }}</span>
                  <span class="text-neutral-600">{{ period(agreement.start_on, agreement.end_on) }}</span>
                  <span class="text-[11px] text-neutral-400">{{ t('payroll_imports.agreements.source_row', { sheet: agreement.sheet, row: agreement.row }) }}</span>
                </div>
                <p v-if="agreement.note" class="mt-0.5 text-xs text-neutral-600">{{ agreement.note }}</p>
                <p v-if="agreement.registration.length" class="mt-0.5 text-xs text-neutral-500">{{ registrationSummary(agreement, t) }}</p>
                <ul v-if="agreement.errors.length || agreement.warnings.length" class="mt-0.5 space-y-0.5 text-xs">
                  <li v-for="item in agreement.errors" :key="item" class="text-danger-600">{{ item }}</li>
                  <li v-for="item in agreement.warnings" :key="item" class="text-warning-700">{{ item }}</li>
                </ul>
              </li>
            </ul>
          </li>
        </ul>
      </section>

      <section class="rounded-xl border border-payroll-500/30 bg-payroll-50 p-4 sm:p-6">
        <p class="text-sm text-neutral-700">{{ t('payroll_imports.agreements.apply_hint') }}</p>
        <div class="mt-3 flex flex-col items-start gap-1.5">
          <button type="button" data-testid="agreement-apply" :class="btnFilled('success')" class="whitespace-nowrap"
            :disabled="!canApply" :title="disabledTitle(!canApply, applyBlockedReason)" @click="runApply">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.check" /></svg>
            {{ busy === 'apply'
              ? t('payroll_imports.agreements.applying', progress ?? { done: 0, total: 0 })
              : t('payroll_imports.agreements.apply', { count: agreementsToCreate }) }}
          </button>
          <p v-if="applyBlockedReason && canWrite" :class="BTN_DISABLED_NOTE">{{ applyBlockedReason }}</p>
        </div>
      </section>
    </template>

    <section v-if="result" class="rounded-xl border border-neutral-200 bg-surface p-4 shadow-sm sm:p-6" data-testid="agreement-result">
      <h3 class="font-semibold text-neutral-900">{{ t('payroll_imports.agreements.result_title') }}</h3>
      <p class="mt-1 text-sm text-neutral-600">{{ t('payroll_imports.agreements.result_summary', result.counts) }}</p>
      <p class="mt-1 text-sm text-neutral-600">
        {{ t('payroll_imports.agreements.next_a1') }}
        <RouterLink :to="{ name: 'payroll-submissions-tab', params: { tab: 'registration_a1' } }" class="text-payroll-600 hover:underline">{{ t('payroll_imports.agreements.next_a1_link') }}</RouterLink>
      </p>
      <ul v-if="failedResults.length" class="mt-3 divide-y divide-neutral-100 rounded-lg border border-neutral-200">
        <li v-for="item in failedResults" :key="item.row_key" class="flex flex-wrap items-start justify-between gap-2 px-3 py-2 text-sm">
          <div class="min-w-0">
            <p class="font-medium text-neutral-900">{{ item.display_name }} · {{ relationText(item.relation_type, null) }} {{ item.start_on ? formatDate(item.start_on) : '' }}</p>
            <p class="mt-0.5 text-xs" :class="item.outcome === 'failed' ? 'text-danger-600' : 'text-neutral-600'">{{ item.message }}</p>
          </div>
          <div class="flex flex-wrap items-center gap-2">
            <RouterLink v-if="item.employee_id" :to="{ name: 'payroll-person', params: { id: item.employee_id } }" class="text-xs text-payroll-600 hover:underline">{{ t('payroll_imports.common.open_person') }}</RouterLink>
            <span class="whitespace-nowrap rounded-full px-2 py-1 text-xs font-medium" :class="OUTCOME_CLASS[item.outcome]">{{ t(`payroll_imports.agreements.outcome.${item.outcome}`) }}</span>
          </div>
        </li>
      </ul>
    </section>
  </section>
</template>

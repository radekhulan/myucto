<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { documentsApi, type DocItem } from '@/api/documents'
import {
  payrollApi,
  type PayrollModuleState,
  type PayrollRun,
} from '@/api/payroll'
import { useToast } from '@/composables/useToast'
import { btnFilled, btnOutline, ICONS } from '@/components/ui/buttonStyles'
import { formatPeriod } from '@/composables/useFormat'

const props = defineProps<{
  state: PayrollModuleState
  matrixVersion: string
}>()
const emit = defineEmits<{
  qualified: [state: PayrollModuleState]
  refresh: []
}>()

const { t } = useI18n()
const toast = useToast()
const loading = ref(true)
const saving = ref(false)
const runs = ref<PayrollRun[]>([])
const firstRunId = ref<number | null>(null)
const secondRunId = ref<number | null>(null)
const correctionRunId = ref<number | null>(null)
const documentQuery = ref('')
const documents = ref<DocItem[]>([])
const selectedDocument = ref<DocItem | null>(null)
const approverName = ref('')
const approverRole = ref('')
const today = new Date().toISOString().slice(0, 10)
const recoveryOn = ref(today)
const approvedOn = ref(today)
const rollbackOn = ref(today)
const monitoringOn = ref(today)

const approvedRuns = computed(() => runs.value.filter(run => run.revision_status === 'approved'))
const correctionRuns = computed(() => approvedRuns.value.filter(run => run.revision_kind === 'correction'))
const selectedRunsAreDistinct = computed(() => {
  const first = approvedRuns.value.find(run => run.id === firstRunId.value)
  const second = approvedRuns.value.find(run => run.id === secondRunId.value)
  return first !== undefined && second !== undefined
    && first.id !== second.id && first.period_start !== second.period_start
})
const canSubmit = computed(() =>
  selectedRunsAreDistinct.value
  && correctionRunId.value !== null
  && selectedDocument.value !== null
  && approverName.value.trim() !== ''
  && approverRole.value.trim() !== ''
  && [recoveryOn.value, approvedOn.value, rollbackOn.value, monitoringOn.value]
    .every(value => /^\d{4}-\d{2}-\d{2}$/.test(value)),
)

function runLabel(run: PayrollRun): string {
  const kind = run.revision_kind === 'correction'
    ? t('payroll.activation.qualification.correction_badge')
    : t('payroll.activation.qualification.regular_badge')
  return `${formatPeriod(run.period_start.slice(0, 7))} · #${run.id} · ${kind}`
}

async function loadRuns() {
  loading.value = true
  try {
    const page = await payrollApi.runsPage(undefined, { limit: 100 })
    runs.value = page.runs
  } catch {
    toast.error(t('payroll.activation.qualification.load_failed'))
  } finally {
    loading.value = false
  }
}

async function searchDocuments() {
  const query = documentQuery.value.trim()
  if (query.length < 2) {
    documents.value = []
    return
  }
  try {
    documents.value = (await documentsApi.search(query))
      .filter(document => document.scope !== 'user')
  } catch {
    toast.error(t('payroll.activation.qualification.document_search_failed'))
  }
}

function chooseDocument(document: DocItem) {
  selectedDocument.value = document
  documentQuery.value = document.title
  documents.value = []
}

function clearDocument() {
  selectedDocument.value = null
  documentQuery.value = ''
  documents.value = []
}

async function submit() {
  if (!canSubmit.value || selectedDocument.value === null
    || firstRunId.value === null || secondRunId.value === null
    || correctionRunId.value === null
  ) return
  if (!window.confirm(t('payroll.activation.qualification.confirm'))) return

  const documentId = selectedDocument.value.id
  saving.value = true
  try {
    const result = await payrollApi.qualifyProduction({
      row_version: props.state.row_version,
      support_matrix_version: props.matrixVersion,
      evidence: {
        parallel_runs: [
          { payroll_run_id: firstRunId.value, document_id: documentId },
          { payroll_run_id: secondRunId.value, document_id: documentId },
        ],
        correction_scenario: { payroll_run_id: correctionRunId.value, document_id: documentId },
        recovery_drill: { completed_on: recoveryOn.value, document_id: documentId },
        expert_approval: {
          approver_name: approverName.value.trim(),
          approver_role: approverRole.value.trim(),
          approved_on: approvedOn.value,
          document_id: documentId,
        },
        rollback_plan: { verified_on: rollbackOn.value, document_id: documentId },
        post_go_live_monitoring: { prepared_on: monitoringOn.value, document_id: documentId },
      },
    })
    toast.success(t('payroll.activation.qualification.completed'))
    emit('qualified', result.state)
  } catch (error: any) {
    const code = error?.response?.data?.error?.code
    if (code === 'row_version_conflict' || code === 'support_matrix_changed') {
      emit('refresh')
    }
    toast.error(error?.response?.data?.error?.message || t('payroll.activation.qualification.failed'))
  } finally {
    saving.value = false
  }
}

onMounted(loadRuns)
</script>

<template>
  <section class="rounded-xl border border-warning-500/40 bg-surface p-4 shadow-sm sm:p-6" data-test="production-qualification-panel">
    <div class="max-w-4xl">
      <h2 class="text-lg font-semibold text-neutral-900">{{ t('payroll.activation.qualification.title') }}</h2>
      <p class="mt-1 text-sm text-neutral-600">{{ t('payroll.activation.qualification.description') }}</p>
    </div>

    <div class="mt-5 grid gap-5 xl:grid-cols-2">
      <fieldset class="space-y-3 rounded-lg border border-neutral-200 p-4" :disabled="loading || saving">
        <legend class="px-1 text-sm font-semibold text-neutral-900">{{ t('payroll.activation.qualification.runs_title') }}</legend>
        <label class="block text-sm font-medium text-neutral-700">
          {{ t('payroll.activation.qualification.first_run') }}
          <select v-model="firstRunId" data-test="first-run" class="mt-1 h-10 w-full rounded-md border border-neutral-300 bg-surface px-3 text-neutral-900">
            <option :value="null">{{ t('payroll.activation.qualification.choose') }}</option>
            <option v-for="run in approvedRuns" :key="run.id" :value="run.id">{{ runLabel(run) }}</option>
          </select>
        </label>
        <label class="block text-sm font-medium text-neutral-700">
          {{ t('payroll.activation.qualification.second_run') }}
          <select v-model="secondRunId" data-test="second-run" class="mt-1 h-10 w-full rounded-md border border-neutral-300 bg-surface px-3 text-neutral-900">
            <option :value="null">{{ t('payroll.activation.qualification.choose') }}</option>
            <option v-for="run in approvedRuns" :key="run.id" :value="run.id">{{ runLabel(run) }}</option>
          </select>
        </label>
        <p v-if="firstRunId && secondRunId && !selectedRunsAreDistinct" class="text-xs text-danger-600">
          {{ t('payroll.activation.qualification.runs_distinct') }}
        </p>
        <label class="block text-sm font-medium text-neutral-700">
          {{ t('payroll.activation.qualification.correction_run') }}
          <select v-model="correctionRunId" data-test="correction-run" class="mt-1 h-10 w-full rounded-md border border-neutral-300 bg-surface px-3 text-neutral-900">
            <option :value="null">{{ t('payroll.activation.qualification.choose') }}</option>
            <option v-for="run in correctionRuns" :key="run.id" :value="run.id">{{ runLabel(run) }}</option>
          </select>
        </label>
        <p v-if="!loading && approvedRuns.length < 2" class="text-xs text-warning-700">{{ t('payroll.activation.qualification.runs_missing') }}</p>
        <p v-if="!loading && correctionRuns.length === 0" class="text-xs text-warning-700">{{ t('payroll.activation.qualification.correction_missing') }}</p>
      </fieldset>

      <fieldset class="space-y-3 rounded-lg border border-neutral-200 p-4" :disabled="saving">
        <legend class="px-1 text-sm font-semibold text-neutral-900">{{ t('payroll.activation.qualification.evidence_title') }}</legend>
        <p class="text-xs text-neutral-600">{{ t('payroll.activation.qualification.evidence_hint') }}</p>
        <div class="relative">
          <div class="flex flex-wrap gap-2">
            <input v-model="documentQuery" data-test="document-query" :readonly="selectedDocument !== null" type="search" class="h-10 min-w-64 flex-1 rounded-md border border-neutral-300 bg-surface px-3 text-sm text-neutral-900" :placeholder="t('payroll.activation.qualification.document_search')" @keyup.enter.prevent="searchDocuments">
            <button v-if="selectedDocument === null" data-test="document-search" type="button" :class="btnOutline('neutral')" @click="searchDocuments">
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.search" /></svg>
              {{ t('common.search') }}
            </button>
            <button v-else type="button" :class="btnOutline('neutral')" @click="clearDocument">{{ t('common.edit') }}</button>
          </div>
          <div v-if="documents.length" class="absolute z-20 mt-1 max-h-52 w-full overflow-auto rounded-md border border-neutral-200 bg-surface shadow-lg">
            <button v-for="document in documents" :key="document.id" data-test="document-option" type="button" class="block w-full px-3 py-2 text-left text-sm text-neutral-900 hover:bg-neutral-100" @click="chooseDocument(document)">
              <span class="font-medium">{{ document.title }}</span>
              <span class="ml-2 text-xs text-neutral-500">{{ document.original_name }}</span>
            </button>
          </div>
        </div>
        <p v-if="selectedDocument" class="rounded-md bg-success-50 px-3 py-2 text-xs text-success-700">
          {{ t('payroll.activation.qualification.document_bound', { name: selectedDocument.title }) }}
        </p>
        <p class="text-xs text-neutral-500">{{ t('payroll.activation.qualification.hash_server') }}</p>
      </fieldset>

      <fieldset class="space-y-3 rounded-lg border border-neutral-200 p-4" :disabled="saving">
        <legend class="px-1 text-sm font-semibold text-neutral-900">{{ t('payroll.activation.qualification.expert_title') }}</legend>
        <div class="grid gap-3 sm:grid-cols-2">
          <label class="text-sm font-medium text-neutral-700">{{ t('payroll.activation.qualification.approver_name') }}<input v-model="approverName" data-test="approver-name" maxlength="190" class="mt-1 h-10 w-full rounded-md border border-neutral-300 bg-surface px-3 text-neutral-900"></label>
          <label class="text-sm font-medium text-neutral-700">{{ t('payroll.activation.qualification.approver_role') }}<input v-model="approverRole" data-test="approver-role" maxlength="190" class="mt-1 h-10 w-full rounded-md border border-neutral-300 bg-surface px-3 text-neutral-900"></label>
          <label class="text-sm font-medium text-neutral-700">{{ t('payroll.activation.qualification.approved_on') }}<input v-model="approvedOn" type="date" class="mt-1 h-10 w-full rounded-md border border-neutral-300 bg-surface px-3 text-neutral-900"></label>
          <label class="text-sm font-medium text-neutral-700">{{ t('payroll.activation.qualification.recovery_on') }}<input v-model="recoveryOn" type="date" class="mt-1 h-10 w-full rounded-md border border-neutral-300 bg-surface px-3 text-neutral-900"></label>
        </div>
      </fieldset>

      <fieldset class="space-y-3 rounded-lg border border-neutral-200 p-4" :disabled="saving">
        <legend class="px-1 text-sm font-semibold text-neutral-900">{{ t('payroll.activation.qualification.operation_title') }}</legend>
        <div class="grid gap-3 sm:grid-cols-2">
          <label class="text-sm font-medium text-neutral-700">{{ t('payroll.activation.qualification.rollback_on') }}<input v-model="rollbackOn" type="date" class="mt-1 h-10 w-full rounded-md border border-neutral-300 bg-surface px-3 text-neutral-900"></label>
          <label class="text-sm font-medium text-neutral-700">{{ t('payroll.activation.qualification.monitoring_on') }}<input v-model="monitoringOn" type="date" class="mt-1 h-10 w-full rounded-md border border-neutral-300 bg-surface px-3 text-neutral-900"></label>
        </div>
        <p class="text-xs text-neutral-600">{{ t('payroll.activation.qualification.single_accountant') }}</p>
      </fieldset>
    </div>

    <div class="mt-5 flex flex-wrap items-center justify-between gap-3 border-t border-neutral-200 pt-4">
      <p class="max-w-3xl text-xs text-neutral-500">{{ t('payroll.activation.qualification.final_warning') }}</p>
      <button data-test="qualification-submit" type="button" :class="btnFilled('success')" :disabled="!canSubmit || saving" @click="submit">
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.check" /></svg>
        {{ saving ? t('common.saving') : t('payroll.activation.qualification.submit') }}
      </button>
    </div>
  </section>
</template>

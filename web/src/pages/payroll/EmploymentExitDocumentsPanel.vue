<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import {
  payrollApi,
  type PayrollDocument,
  type PayrollEmployment,
  type PayrollEmploymentCertificateDeductionEvidence,
  type PayrollEmploymentCertificateEvidence,
  type PayrollEmploymentCertificatePensionPeriod,
  type PayrollEmploymentExitDocumentList,
  type PayrollPensionInsurancePeriod,
  type PayrollTerminationReasonKind,
} from '@/api/payroll'
import { apiErrorMessage } from '@/api/errors'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import {
  btnFilled,
  btnFilledSm,
  btnOutline,
  btnOutlineSm,
  ICONS,
} from '@/components/ui/buttonStyles'
// Formátování je sdílené (useFormat) — místní kopie se rozcházely v locale i tvaru.
import { formatDate } from '@/composables/useFormat'
import { useToast } from '@/composables/useToast'
import { usePayrollLabels } from '@/composables/usePayrollLabels'

const props = defineProps<{
  employment: PayrollEmployment
  canWrite: boolean
}>()

const { t } = useI18n()
const { employmentExitReadinessLabel } = usePayrollLabels()
const toast = useToast()
const loading = ref(true)
const generating = ref(false)
const downloadingId = ref<number | null>(null)
const activeTab = ref<'employment' | 'average' | 'statement'>('employment')
const showForm = ref(false)
const loadError = ref('')
const formError = ref('')
const data = ref<PayrollEmploymentExitDocumentList | null>(null)
const pendingIdempotencyKey = ref('')
const exposureFactsText = ref('')
const workDescription = ref('')
const achievedQualification = ref('')
const exposureAssessmentComplete = ref(false)
const deductionAssessmentComplete = ref(false)
const pensionCategoryAssessmentComplete = ref(false)
const correctionReason = ref('')
const deductions = ref<PayrollEmploymentCertificateDeductionEvidence[]>([])
const pensionPeriods = ref<PayrollEmploymentCertificatePensionPeriod[]>([])
const pensionCategoryOptions: Array<{
  value: PayrollEmploymentCertificatePensionPeriod['category']
  label: string
}> = [
  { value: 'I', label: 'I' },
  { value: 'II', label: 'II' },
]

const showAverageForm = ref(false)
const showStatementForm = ref(false)
const terminationAssessmentComplete = ref(false)
const terminationReasonKind = ref<PayrollTerminationReasonKind>('none')
const employeeStatedReason = ref('')
const pensionInsurancePeriods = ref<PayrollPensionInsurancePeriod[]>([])
const averageCorrectionReason = ref('')
const requestedPurpose = ref('')
const statementCorrectionReason = ref('')
const terminationReasonOptions = computed(() =>
  ([
    'none',
    'gross_breach',
    'sickness_regime_breach',
    'organizational',
    'health',
    'employer_breach',
    'employee_unilateral',
    'agreement',
  ] as const).map(value => ({
    value,
    label: t(`payroll.people.exit_documents.termination_reasons.${value}`),
  })),
)

const employmentReadiness = computed(() => data.value?.readiness.employment_certificate ?? null)
const averageReadiness = computed(() => data.value?.readiness.average_earnings_certificate ?? null)
const employmentDocuments = computed(() =>
  (data.value?.items ?? []).filter(item => item.document_kind === 'employment_certificate'),
)
const averageDocuments = computed(() =>
  (data.value?.items ?? []).filter(item => item.document_kind === 'average_earnings_certificate'),
)
const statementReadiness = computed(() => data.value?.readiness.average_earnings_statement ?? null)
const statementDocuments = computed(() =>
  (data.value?.items ?? []).filter(item => item.document_kind === 'average_earnings_statement'),
)
const hasExistingAverage = computed(() => averageDocuments.value.length > 0)
const hasExistingStatement = computed(() => statementDocuments.value.length > 0)
const statedReasonRequired = computed(() =>
  terminationReasonKind.value === 'employee_unilateral'
  || terminationReasonKind.value === 'agreement',
)
const pensionInsurancePeriodsComplete = computed(() =>
  pensionInsurancePeriods.value.every(row => row.from && row.to && row.from <= row.to),
)
const canGenerateAverage = computed(() =>
  props.canWrite
  && averageReadiness.value?.available === true
  && terminationAssessmentComplete.value
  && pensionInsurancePeriodsComplete.value
  && (!hasExistingAverage.value || averageCorrectionReason.value.trim() !== ''),
)
const canGenerateStatement = computed(() =>
  props.canWrite
  && statementReadiness.value?.available === true
  && requestedPurpose.value.trim() !== ''
  && (!hasExistingStatement.value || statementCorrectionReason.value.trim() !== ''),
)
const hasExistingCertificate = computed(() => employmentDocuments.value.length > 0)
const isDpp = computed(() => props.employment.relation_type === 'dpp')
const deductionClaimIds = computed(() => employmentReadiness.value?.deduction_claim_ids ?? [])
const dppIssuanceBlocked = computed(() => isDpp.value && deductionClaimIds.value.length === 0)
const deductionEvidenceComplete = computed(() =>
  deductions.value.every(row =>
    row.beneficiary.trim()
    && row.ordering_authority.trim()
    && row.decision_reference.trim(),
  ),
)
const pensionPeriodsComplete = computed(() =>
  pensionPeriods.value.every(row => row.from && row.to && row.from <= row.to),
)
const canGenerate = computed(() =>
  props.canWrite
  && employmentReadiness.value?.available === true
  && !dppIssuanceBlocked.value
  && workDescription.value.trim() !== ''
  && achievedQualification.value.trim() !== ''
  && exposureAssessmentComplete.value
  && deductionAssessmentComplete.value
  && deductionEvidenceComplete.value
  && pensionCategoryAssessmentComplete.value
  && pensionPeriodsComplete.value
  && (!hasExistingCertificate.value || correctionReason.value.trim() !== ''),
)

function formatSize(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`
  return `${(bytes / 1024).toFixed(1)} kB`
}

function resetEvidence(): void {
  exposureFactsText.value = ''
  workDescription.value = ''
  achievedQualification.value = ''
  exposureAssessmentComplete.value = false
  deductionAssessmentComplete.value = false
  pensionCategoryAssessmentComplete.value = false
  correctionReason.value = ''
  terminationAssessmentComplete.value = false
  terminationReasonKind.value = 'none'
  employeeStatedReason.value = ''
  pensionInsurancePeriods.value = []
  averageCorrectionReason.value = ''
  requestedPurpose.value = ''
  statementCorrectionReason.value = ''
  pensionPeriods.value = []
  deductions.value = deductionClaimIds.value.map(sourceClaimId => ({
    source_claim_id: sourceClaimId,
    beneficiary: '',
    ordering_authority: '',
    decision_reference: '',
  }))
}

async function load(): Promise<void> {
  loading.value = true
  loadError.value = ''
  try {
    data.value = await payrollApi.employmentExitDocuments(props.employment.id)
    resetEvidence()
  } catch (error) {
    loadError.value = apiErrorMessage(
      error,
      t('payroll.people.exit_documents.load_failed'),
    )
  } finally {
    loading.value = false
  }
}

function addPensionPeriod(): void {
  pensionPeriods.value.push({ category: 'I', from: '', to: '' })
}

function removePensionPeriod(index: number): void {
  pensionPeriods.value.splice(index, 1)
}

function createIdempotencyKey(): string {
  const random = globalThis.crypto?.randomUUID?.()
    ?? `${Date.now()}-${Math.random().toString(36).slice(2)}`
  return `employment-exit-${props.employment.id}-${random}`
}

async function generate(): Promise<void> {
  if (!canGenerate.value || generating.value) return
  generating.value = true
  formError.value = ''
  pendingIdempotencyKey.value ||= createIdempotencyKey()
  const payload: PayrollEmploymentCertificateEvidence = {
    work_description: workDescription.value.trim(),
    achieved_qualification: achievedQualification.value.trim(),
    exposure_assessment_complete: exposureAssessmentComplete.value,
    exposure_facts: exposureFactsText.value
      .split(/\r?\n/)
      .map(value => value.trim())
      .filter(Boolean),
    deduction_assessment_complete: deductionAssessmentComplete.value,
    deductions: deductions.value.map(row => ({
      source_claim_id: row.source_claim_id,
      beneficiary: row.beneficiary.trim(),
      ordering_authority: row.ordering_authority.trim(),
      decision_reference: row.decision_reference.trim(),
    })),
    pension_category_assessment_complete: pensionCategoryAssessmentComplete.value,
    pre1993_pension_category_periods: pensionPeriods.value.map(row => ({ ...row })),
    dpp_issuance_basis: isDpp.value ? 'wage_deductions' : null,
    correction_reason: correctionReason.value.trim() || null,
  }
  try {
    await payrollApi.generateEmploymentCertificate(
      props.employment.id,
      payload,
      pendingIdempotencyKey.value,
    )
    pendingIdempotencyKey.value = ''
    showForm.value = false
    toast.success(t('payroll.people.exit_documents.created'))
    await load()
  } catch (error) {
    formError.value = apiErrorMessage(
      error,
      t('payroll.people.exit_documents.create_failed'),
    )
    toast.error(formError.value)
  } finally {
    generating.value = false
  }
}

function addPensionInsurancePeriod(): void {
  pensionInsurancePeriods.value.push({ from: '', to: '' })
}

function removePensionInsurancePeriod(index: number): void {
  pensionInsurancePeriods.value.splice(index, 1)
}

async function generateAverage(): Promise<void> {
  if (!canGenerateAverage.value || generating.value) return
  generating.value = true
  formError.value = ''
  pendingIdempotencyKey.value ||= createIdempotencyKey()
  try {
    await payrollApi.generateAverageEarningsCertificate(
      props.employment.id,
      {
        termination_assessment_complete: true,
        termination_reason_kind: terminationReasonKind.value,
        employee_stated_reason: statedReasonRequired.value
          ? (employeeStatedReason.value.trim() || null)
          : null,
        pension_insurance_periods: pensionInsurancePeriods.value.map(row => ({ ...row })),
        correction_reason: averageCorrectionReason.value.trim() || null,
      },
      pendingIdempotencyKey.value,
    )
    pendingIdempotencyKey.value = ''
    showAverageForm.value = false
    toast.success(t('payroll.people.exit_documents.created'))
    await load()
  } catch (error) {
    formError.value = apiErrorMessage(error, t('payroll.people.exit_documents.create_failed'))
    toast.error(formError.value)
  } finally {
    generating.value = false
  }
}

async function generateStatement(): Promise<void> {
  if (!canGenerateStatement.value || generating.value) return
  generating.value = true
  formError.value = ''
  pendingIdempotencyKey.value ||= createIdempotencyKey()
  try {
    await payrollApi.generateAverageEarningsStatement(
      props.employment.id,
      {
        requested_purpose: requestedPurpose.value.trim(),
        correction_reason: statementCorrectionReason.value.trim() || null,
      },
      pendingIdempotencyKey.value,
    )
    pendingIdempotencyKey.value = ''
    showStatementForm.value = false
    toast.success(t('payroll.people.exit_documents.created'))
    await load()
  } catch (error) {
    formError.value = apiErrorMessage(error, t('payroll.people.exit_documents.create_failed'))
    toast.error(formError.value)
  } finally {
    generating.value = false
  }
}

async function download(document: PayrollDocument): Promise<void> {
  if (downloadingId.value !== null) return
  downloadingId.value = document.id
  try {
    await payrollApi.downloadDocument(document)
  } catch (error) {
    toast.error(apiErrorMessage(error, t('payroll.people.exit_documents.download_failed')))
  } finally {
    downloadingId.value = null
  }
}

onMounted(() => void load())
</script>

<template>
  <section class="mt-5 rounded-lg border border-neutral-200 bg-neutral-50/60">
    <div class="flex flex-wrap items-start justify-between gap-3 px-3 pt-3 sm:px-4 sm:pt-4">
      <div>
        <h4 class="text-sm font-semibold text-neutral-900">
          {{ t('payroll.people.exit_documents.title') }}
        </h4>
        <p class="mt-1 text-xs text-neutral-500">
          {{ t('payroll.people.exit_documents.subtitle') }}
        </p>
      </div>
      <button
        type="button"
        :class="btnOutlineSm('neutral')"
        :disabled="loading"
        data-test="reload-exit-documents"
        @click="load"
      >
        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.cycle" /></svg>
        {{ t('common.refresh') }}
      </button>
    </div>

    <nav
      class="mt-3 flex gap-1 overflow-x-auto border-b border-neutral-200 px-3 sm:px-4"
      :aria-label="t('payroll.people.exit_documents.tabs.label')"
    >
      <button
        v-for="tab in (['employment', 'average', 'statement'] as const)"
        :key="tab"
        type="button"
        class="cursor-pointer whitespace-nowrap border-b-2 px-3 py-2.5 text-sm font-medium transition"
        :class="activeTab === tab
          ? 'border-payroll-500 text-payroll-700'
          : 'border-transparent text-neutral-600 hover:text-neutral-900'"
        :data-test="`exit-tab-${tab}`"
        @click="activeTab = tab"
      >
        {{ t(`payroll.people.exit_documents.tabs.${tab}`) }}
      </button>
    </nav>

    <div class="p-3 sm:p-4">
      <div v-if="loading" class="h-20 animate-pulse rounded-lg bg-neutral-100" />
      <div
        v-else-if="loadError"
        class="rounded-lg border border-danger-500/30 bg-danger-50 p-3 text-sm text-danger-700"
        role="alert"
      >
        {{ loadError }}
      </div>

      <template v-else-if="activeTab === 'employment'">
        <div
          v-if="employmentReadiness?.available"
          class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-success-500/30 bg-success-50 p-3"
        >
          <div>
            <p class="text-sm font-medium text-success-800">
              {{ t('payroll.people.exit_documents.ready') }}
            </p>
            <p class="mt-0.5 text-xs text-success-700">
              {{ t('payroll.people.exit_documents.ready_hint') }}
            </p>
          </div>
          <button
            v-if="canWrite && !dppIssuanceBlocked"
            type="button"
            :class="btnFilled('primary')"
            data-test="open-employment-certificate-form"
            @click="showForm = !showForm"
          >
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="showForm ? ICONS.x : ICONS.doc" /></svg>
            {{ t(showForm ? 'common.cancel' : 'payroll.people.exit_documents.generate') }}
          </button>
        </div>
        <div
          v-else
          class="rounded-lg border border-warning-500/30 bg-warning-50 p-3 text-sm text-warning-800"
          role="status"
          data-test="employment-certificate-blocker"
        >
          <p class="font-medium">{{ t('payroll.people.exit_documents.not_ready') }}</p>
          <p class="mt-1 text-xs">{{ employmentExitReadinessLabel(employmentReadiness?.readiness_code) }}</p>
        </div>

        <div
          v-if="dppIssuanceBlocked"
          class="mt-3 rounded-lg border border-warning-500/30 bg-warning-50 p-3 text-sm text-warning-800"
          role="status"
        >
          {{ employmentExitReadinessLabel('dpp_sickness_evidence_not_ready') }}
        </div>

        <form
          v-if="showForm && employmentReadiness?.available && !dppIssuanceBlocked"
          class="mt-4 space-y-4 rounded-lg border border-payroll-500/30 bg-surface p-3 sm:p-4"
          data-test="employment-certificate-form"
          @submit.prevent="generate"
        >
          <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <label class="text-xs text-neutral-600">
              {{ t('payroll.people.exit_documents.work_description') }}
              <textarea v-model="workDescription" required rows="3" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm"></textarea>
            </label>
            <label class="text-xs text-neutral-600">
              {{ t('payroll.people.exit_documents.qualification') }}
              <textarea v-model="achievedQualification" required rows="3" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm"></textarea>
            </label>
          </div>

          <div>
            <label class="text-xs text-neutral-600">
              {{ t('payroll.people.exit_documents.exposure_facts') }}
              <textarea
                v-model="exposureFactsText"
                rows="3"
                class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm"
                :placeholder="t('payroll.people.exit_documents.one_per_line')"
              ></textarea>
            </label>
            <label class="mt-2 flex items-start gap-2 text-sm text-neutral-700">
              <input v-model="exposureAssessmentComplete" type="checkbox" class="mt-0.5 rounded border-neutral-300 text-payroll-600">
              {{ t('payroll.people.exit_documents.exposure_confirm') }}
            </label>
          </div>

          <div>
            <div class="flex flex-wrap items-center justify-between gap-2">
              <div>
                <p class="text-sm font-semibold text-neutral-900">
                  {{ t('payroll.people.exit_documents.deductions') }}
                </p>
                <p class="text-xs text-neutral-500">
                  {{ t('payroll.people.exit_documents.deductions_hint') }}
                </p>
              </div>
              <span class="rounded-full bg-neutral-100 px-2 py-1 text-xs text-neutral-600">
                {{ t('payroll.people.exit_documents.claim_count', { count: deductions.length }) }}
              </span>
            </div>
            <div v-for="row in deductions" :key="row.source_claim_id" class="mt-3 grid grid-cols-1 gap-3 rounded-lg border border-neutral-200 p-3 sm:grid-cols-3">
              <p class="text-xs font-medium text-neutral-700 sm:col-span-3">
                {{ t('payroll.people.exit_documents.claim_id', { id: row.source_claim_id }) }}
              </p>
              <label class="text-xs text-neutral-600">{{ t('payroll.people.exit_documents.beneficiary') }}<input v-model="row.beneficiary" required class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm"></label>
              <label class="text-xs text-neutral-600">{{ t('payroll.people.exit_documents.ordering_authority') }}<input v-model="row.ordering_authority" required class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm"></label>
              <label class="text-xs text-neutral-600">{{ t('payroll.people.exit_documents.decision_reference') }}<input v-model="row.decision_reference" required class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm"></label>
            </div>
            <label class="mt-3 flex items-start gap-2 text-sm text-neutral-700">
              <input v-model="deductionAssessmentComplete" type="checkbox" class="mt-0.5 rounded border-neutral-300 text-payroll-600">
              {{ t('payroll.people.exit_documents.deductions_confirm') }}
            </label>
          </div>

          <div>
            <div class="flex flex-wrap items-center justify-between gap-2">
              <div>
                <p class="text-sm font-semibold text-neutral-900">
                  {{ t('payroll.people.exit_documents.pension_periods') }}
                </p>
                <p class="text-xs text-neutral-500">
                  {{ t('payroll.people.exit_documents.pension_periods_hint') }}
                </p>
              </div>
              <button
                type="button"
                :class="btnFilledSm('primary')"
                data-test="add-pension-period"
                @click="addPensionPeriod"
              >
                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.plus" /></svg>
                {{ t('payroll.people.exit_documents.add_period') }}
              </button>
            </div>
            <div v-for="(row, index) in pensionPeriods" :key="index" class="mt-3 grid grid-cols-1 gap-3 rounded-lg border border-neutral-200 p-3 sm:grid-cols-[1fr_1fr_1fr_auto]">
              <div class="text-xs text-neutral-600">
                <span>{{ t('payroll.people.exit_documents.category') }}</span>
                <SearchableSelect
                  v-model="row.category"
                  class="mt-1"
                  :options="pensionCategoryOptions"
                  :clearable="false"
                  accent="payroll"
                  :aria-label="t('payroll.people.exit_documents.category')"
                />
              </div>
              <label class="text-xs text-neutral-600">{{ t('payroll.people.exit_documents.from') }}<input v-model="row.from" required type="date" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm"></label>
              <label class="text-xs text-neutral-600">{{ t('payroll.people.exit_documents.to') }}<input v-model="row.to" required type="date" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm"></label>
              <button type="button" :class="btnOutlineSm('danger')" class="self-end" @click="removePensionPeriod(index)">
                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.trash" /></svg>
                {{ t('common.remove') }}
              </button>
            </div>
            <label class="mt-3 flex items-start gap-2 text-sm text-neutral-700">
              <input v-model="pensionCategoryAssessmentComplete" type="checkbox" class="mt-0.5 rounded border-neutral-300 text-payroll-600">
              {{ t('payroll.people.exit_documents.pension_confirm') }}
            </label>
          </div>

          <label v-if="hasExistingCertificate" class="block text-xs text-neutral-600">
            {{ t('payroll.people.exit_documents.correction_reason') }}
            <textarea v-model="correctionReason" required rows="2" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm"></textarea>
          </label>

          <p v-if="formError" class="rounded-lg border border-danger-500/30 bg-danger-50 p-3 text-sm text-danger-700" role="alert">
            {{ formError }}
          </p>
          <div class="flex flex-wrap justify-end gap-2">
            <button type="button" :class="btnOutline('neutral')" @click="showForm = false">
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.x" /></svg>
              {{ t('common.cancel') }}
            </button>
            <button type="submit" :class="btnFilled('primary')" :disabled="!canGenerate || generating" data-test="generate-employment-certificate">
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.doc" /></svg>
              {{ t('payroll.people.exit_documents.generate') }}
            </button>
          </div>
        </form>

        <div class="mt-4 space-y-2">
          <p v-if="employmentDocuments.length === 0" class="text-sm text-neutral-500">
            {{ t('payroll.people.exit_documents.empty') }}
          </p>
          <article v-for="document in employmentDocuments" :key="document.id" class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-neutral-200 bg-surface p-3">
            <div class="min-w-0">
              <p class="truncate text-sm font-medium text-neutral-900">{{ document.suggested_filename }}</p>
              <p class="mt-0.5 text-xs text-neutral-500">
                {{ formatDate(document.created_at) }} · {{ formatSize(document.size_bytes) }} ·
                {{ t('payroll.people.exit_documents.revision', { revision: document.document_revision_no ?? 1 }) }}
              </p>
            </div>
            <button type="button" :class="btnOutlineSm('neutral')" :disabled="downloadingId !== null" data-test="download-exit-document" @click="download(document)">
              <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.download" /></svg>
              {{ t('common.download') }}
            </button>
          </article>
        </div>
      </template>

      <template v-else-if="activeTab === 'average'">
        <div
          v-if="averageReadiness?.available"
          class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-success-500/30 bg-success-50 p-3"
        >
          <div>
            <p class="text-sm font-medium text-success-800">
              {{ t('payroll.people.exit_documents.average_ready') }}
            </p>
            <p class="mt-0.5 text-xs text-success-700">
              {{ t('payroll.people.exit_documents.average_hint') }}
            </p>
          </div>
          <button
            v-if="canWrite"
            type="button"
            :class="btnFilled('primary')"
            data-test="open-average-certificate-form"
            @click="showAverageForm = !showAverageForm"
          >
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="showAverageForm ? ICONS.x : ICONS.doc" /></svg>
            {{ t(showAverageForm ? 'common.cancel' : 'payroll.people.exit_documents.generate') }}
          </button>
        </div>
        <div
          v-else
          class="rounded-lg border border-warning-500/30 bg-warning-50 p-3 text-warning-800"
          role="status"
          data-test="average-certificate-unavailable"
        >
          <p class="text-sm font-medium">{{ t('payroll.people.exit_documents.average_unavailable') }}</p>
          <p class="mt-1 text-xs">
            {{ employmentExitReadinessLabel(
              averageReadiness?.readiness_code,
              { year: averageReadiness?.decisive_year, quarter: averageReadiness?.decisive_quarter },
            ) }}
          </p>
        </div>

        <form
          v-if="showAverageForm && averageReadiness?.available"
          class="mt-4 space-y-4 rounded-lg border border-payroll-500/30 bg-surface p-3 sm:p-4"
          data-test="average-certificate-form"
          @submit.prevent="generateAverage"
        >
          <div class="text-xs text-neutral-600">
            <span>{{ t('payroll.people.exit_documents.termination_reason') }}</span>
            <SearchableSelect
              v-model="terminationReasonKind"
              class="mt-1"
              :options="terminationReasonOptions"
              :clearable="false"
              accent="payroll"
              :aria-label="t('payroll.people.exit_documents.termination_reason')"
            />
          </div>
          <label v-if="statedReasonRequired" class="block text-xs text-neutral-600">
            {{ t('payroll.people.exit_documents.employee_stated_reason') }}
            <textarea v-model="employeeStatedReason" rows="2" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm"></textarea>
          </label>

          <div>
            <div class="flex flex-wrap items-center justify-between gap-2">
              <div>
                <p class="text-sm font-semibold text-neutral-900">
                  {{ t('payroll.people.exit_documents.pension_insurance_periods') }}
                </p>
                <p class="text-xs text-neutral-500">
                  {{ t('payroll.people.exit_documents.pension_insurance_hint') }}
                </p>
              </div>
              <button
                type="button"
                :class="btnFilledSm('primary')"
                data-test="add-pension-insurance-period"
                @click="addPensionInsurancePeriod"
              >
                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.plus" /></svg>
                {{ t('payroll.people.exit_documents.add_period') }}
              </button>
            </div>
            <div v-for="(row, index) in pensionInsurancePeriods" :key="index" class="mt-3 grid grid-cols-1 gap-3 rounded-lg border border-neutral-200 p-3 sm:grid-cols-[1fr_1fr_auto]">
              <label class="text-xs text-neutral-600">{{ t('payroll.people.exit_documents.from') }}<input v-model="row.from" required type="date" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm"></label>
              <label class="text-xs text-neutral-600">{{ t('payroll.people.exit_documents.to') }}<input v-model="row.to" required type="date" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm"></label>
              <button type="button" :class="btnOutlineSm('danger')" class="self-end" @click="removePensionInsurancePeriod(index)">
                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.trash" /></svg>
                {{ t('common.remove') }}
              </button>
            </div>
          </div>

          <label class="flex items-start gap-2 text-sm text-neutral-700">
            <input v-model="terminationAssessmentComplete" type="checkbox" class="mt-0.5 rounded border-neutral-300 text-payroll-600">
            {{ t('payroll.people.exit_documents.termination_confirm') }}
          </label>

          <label v-if="hasExistingAverage" class="block text-xs text-neutral-600">
            {{ t('payroll.people.exit_documents.correction_reason') }}
            <textarea v-model="averageCorrectionReason" required rows="2" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm"></textarea>
          </label>

          <p v-if="formError" class="rounded-lg border border-danger-500/30 bg-danger-50 p-3 text-sm text-danger-700" role="alert">
            {{ formError }}
          </p>
          <div class="flex flex-wrap justify-end gap-2">
            <button type="button" :class="btnOutline('neutral')" @click="showAverageForm = false">
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.x" /></svg>
              {{ t('common.cancel') }}
            </button>
            <button type="submit" :class="btnFilled('primary')" :disabled="!canGenerateAverage || generating" data-test="generate-average-certificate">
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.doc" /></svg>
              {{ t('payroll.people.exit_documents.generate') }}
            </button>
          </div>
        </form>

        <div class="mt-4 space-y-2">
          <p v-if="averageDocuments.length === 0" class="text-sm text-neutral-500">
            {{ t('payroll.people.exit_documents.empty') }}
          </p>
          <article v-for="document in averageDocuments" :key="document.id" class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-neutral-200 bg-surface p-3">
            <div class="min-w-0">
              <p class="truncate text-sm font-medium text-neutral-900">{{ document.suggested_filename }}</p>
              <p class="mt-0.5 text-xs text-neutral-500">
                {{ formatDate(document.created_at) }} · {{ formatSize(document.size_bytes) }} ·
                {{ t('payroll.people.exit_documents.revision', { revision: document.document_revision_no ?? 1 }) }}
              </p>
            </div>
            <button type="button" :class="btnOutlineSm('neutral')" :disabled="downloadingId !== null" data-test="download-average-document" @click="download(document)">
              <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.download" /></svg>
              {{ t('common.download') }}
            </button>
          </article>
        </div>
      </template>

      <template v-else>
        <div
          v-if="statementReadiness?.available"
          class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-success-500/30 bg-success-50 p-3"
        >
          <div>
            <p class="text-sm font-medium text-success-800">
              {{ t('payroll.people.exit_documents.statement_ready') }}
            </p>
            <p class="mt-0.5 text-xs text-success-700">
              {{ t('payroll.people.exit_documents.statement_hint') }}
            </p>
          </div>
          <button
            v-if="canWrite"
            type="button"
            :class="btnFilled('primary')"
            data-test="open-average-statement-form"
            @click="showStatementForm = !showStatementForm"
          >
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="showStatementForm ? ICONS.x : ICONS.doc" /></svg>
            {{ t(showStatementForm ? 'common.cancel' : 'payroll.people.exit_documents.generate') }}
          </button>
        </div>
        <div
          v-else
          class="rounded-lg border border-warning-500/30 bg-warning-50 p-3 text-warning-800"
          role="status"
          data-test="average-statement-unavailable"
        >
          <p class="text-sm font-medium">{{ t('payroll.people.exit_documents.statement_unavailable') }}</p>
          <p class="mt-1 text-xs">
            {{ employmentExitReadinessLabel(
              statementReadiness?.readiness_code,
              { year: statementReadiness?.decisive_year, quarter: statementReadiness?.decisive_quarter },
            ) }}
          </p>
        </div>

        <form
          v-if="showStatementForm && statementReadiness?.available"
          class="mt-4 space-y-4 rounded-lg border border-payroll-500/30 bg-surface p-3 sm:p-4"
          data-test="average-statement-form"
          @submit.prevent="generateStatement"
        >
          <label class="block text-xs text-neutral-600">
            {{ t('payroll.people.exit_documents.requested_purpose') }}
            <input v-model="requestedPurpose" required class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm">
          </label>
          <label v-if="hasExistingStatement" class="block text-xs text-neutral-600">
            {{ t('payroll.people.exit_documents.correction_reason') }}
            <textarea v-model="statementCorrectionReason" required rows="2" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm"></textarea>
          </label>
          <p v-if="formError" class="rounded-lg border border-danger-500/30 bg-danger-50 p-3 text-sm text-danger-700" role="alert">
            {{ formError }}
          </p>
          <div class="flex flex-wrap justify-end gap-2">
            <button type="button" :class="btnOutline('neutral')" @click="showStatementForm = false">
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.x" /></svg>
              {{ t('common.cancel') }}
            </button>
            <button type="submit" :class="btnFilled('primary')" :disabled="!canGenerateStatement || generating" data-test="generate-average-statement">
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.doc" /></svg>
              {{ t('payroll.people.exit_documents.generate') }}
            </button>
          </div>
        </form>

        <div class="mt-4 space-y-2">
          <p v-if="statementDocuments.length === 0" class="text-sm text-neutral-500">
            {{ t('payroll.people.exit_documents.empty') }}
          </p>
          <article v-for="document in statementDocuments" :key="document.id" class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-neutral-200 bg-surface p-3">
            <div class="min-w-0">
              <p class="truncate text-sm font-medium text-neutral-900">{{ document.suggested_filename }}</p>
              <p class="mt-0.5 text-xs text-neutral-500">
                {{ formatDate(document.created_at) }} · {{ formatSize(document.size_bytes) }} ·
                {{ t('payroll.people.exit_documents.revision', { revision: document.document_revision_no ?? 1 }) }}
              </p>
            </div>
            <button type="button" :class="btnOutlineSm('neutral')" :disabled="downloadingId !== null" data-test="download-statement-document" @click="download(document)">
              <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.download" /></svg>
              {{ t('common.download') }}
            </button>
          </article>
        </div>
      </template>

    </div>
  </section>
</template>

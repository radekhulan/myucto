<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'
import { documentsApi, type DocItem } from '@/api/documents'
import {
  payrollEnforcementApi,
  type EnforcementMonthEvidence,
  type InsolvencyOptions,
} from '@/api/payrollEnforcement'
import PayrollPersonSearchSelect from '@/components/payroll/PayrollPersonSearchSelect.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { btnFilled, btnOutline, ICONS } from '@/components/ui/buttonStyles'
import { useToast } from '@/composables/useToast'
import { useAuthStore } from '@/stores/auth'
import { appIsoDate } from '@/utils/date'
import { payrollQueryId, payrollQueryValue } from '@/pages/payroll/payrollAgendaLinks'

const { t } = useI18n()
const route = useRoute()
const auth = useAuthStore()
const toast = useToast()
const employeeId = ref<number | null>(payrollQueryId(route.query, 'person'))
const requestedPeriod = payrollQueryValue(route.query, 'period')
const period = ref(requestedPeriod !== null && /^\d{4}-(0[1-9]|1[0-2])$/.test(requestedPeriod)
  ? requestedPeriod
  : appIsoDate().slice(0, 7))
const evidence = ref<EnforcementMonthEvidence | null>(null)
const options = ref<InsolvencyOptions>({ employments: [], recipient_accounts: [] })
const employmentId = ref<number | null>(null)
const accountId = ref<number | null>(null)
const documentId = ref<number | null>(null)
const storedTarget = ref<[number | null, number | null, number | null]>([null, null, null])
const storedInstructionId = ref<number | null>(null)
const loading = ref(false)
const loadFailed = ref(false)
const saving = ref(false)
const documentQuery = ref('')
const documentCandidates = ref<DocItem[]>([])
const documentSearching = ref(false)
const courtAmountCzk = ref('')
const canWrite = computed(() => auth.canWrite('payroll.enforcement')
  && auth.canWrite('payroll.insolvency'))
const canReadDocuments = computed(() => auth.canRead('documents'))
let loadSequence = 0
let documentSequence = 0
let documentTimer: ReturnType<typeof setTimeout> | null = null

const targetUnchanged = computed(() => storedTarget.value[0] === employmentId.value
  && storedTarget.value[1] === accountId.value
  && storedTarget.value[2] === documentId.value)
const hasApprovedInstruction = computed(() => storedInstructionId.value !== null)
const leavingApprovedInstruction = computed(() => hasApprovedInstruction.value
  && evidence.value?.insolvency_mode !== 'approved_standard')
const standardReady = computed(() => evidence.value?.insolvency_mode !== 'approved_standard'
  || (employmentId.value !== null
    && accountId.value !== null
    && documentId.value !== null
    && canReadDocuments.value))
const canSave = computed(() => canWrite.value
  && evidence.value !== null
  && !saving.value
  && !leavingApprovedInstruction.value
  && standardReady.value)

function applyLoaded(loadedEvidence: EnforcementMonthEvidence, loadedOptions: InsolvencyOptions) {
  evidence.value = loadedEvidence
  options.value = loadedOptions
  employmentId.value = loadedEvidence.insolvency_employment_id
  accountId.value = loadedEvidence.insolvency_institution_account_id
  documentId.value = loadedEvidence.insolvency_decision_document_id
  storedTarget.value = [employmentId.value, accountId.value, documentId.value]
  storedInstructionId.value = loadedEvidence.insolvency_payment_instruction_id
  courtAmountCzk.value = loadedEvidence.court_determined_amount_minor_units === null
    ? ''
    : String(loadedEvidence.court_determined_amount_minor_units / 100)
  documentQuery.value = documentId.value === null
    ? ''
    : t('payroll.insolvency.document_selected', { id: documentId.value })
  documentCandidates.value = []
}

async function load() {
  const selectedEmployee = employeeId.value
  const selectedPeriod = period.value
  const sequence = ++loadSequence
  if (selectedEmployee === null) {
    evidence.value = null
    options.value = { employments: [], recipient_accounts: [] }
    loadFailed.value = false
    return
  }
  loading.value = true
  loadFailed.value = false
  try {
    const [loadedOptions, loadedEvidence] = await Promise.all([
      payrollEnforcementApi.insolvencyOptions(selectedEmployee, selectedPeriod),
      payrollEnforcementApi.insolvencyEvidence(selectedEmployee, selectedPeriod),
    ])
    if (sequence !== loadSequence) return
    applyLoaded(loadedEvidence, loadedOptions)
  } catch {
    if (sequence !== loadSequence) return
    evidence.value = null
    options.value = { employments: [], recipient_accounts: [] }
    loadFailed.value = true
    toast.error(t('payroll.insolvency.load_failed'))
  } finally {
    if (sequence === loadSequence) loading.value = false
  }
}

function minorUnits(value: string): number | null {
  const normalized = value.trim().replace(',', '.')
  if (normalized === '') return null
  const amount = Number(normalized)
  if (!Number.isFinite(amount) || amount <= 0) {
    throw new Error(t('payroll.insolvency.invalid_amount'))
  }
  return Math.round(amount * 100)
}

async function save() {
  const current = evidence.value
  const selectedEmployee = employeeId.value
  if (!current || selectedEmployee === null || !canSave.value) return
  saving.value = true
  try {
    const {
      id: _id,
      employee_id: _employeeId,
      period_start: _periodStart,
      ...payload
    } = current
    payload.court_determined_amount_minor_units = current.insolvency_mode === 'court_determined_amount'
      ? minorUnits(courtAmountCzk.value)
      : null
    if (current.insolvency_mode === 'approved_standard') {
      payload.insolvency_decision_verified = true
      payload.insolvency_recipient_verified = true
      payload.insolvency_employment_id = employmentId.value
      payload.insolvency_institution_account_id = accountId.value
      payload.insolvency_decision_document_id = documentId.value
      payload.insolvency_payment_instruction_id = targetUnchanged.value
        ? storedInstructionId.value
        : null
    } else {
      payload.insolvency_decision_verified = false
      payload.insolvency_recipient_verified = false
      payload.insolvency_employment_id = null
      payload.insolvency_institution_account_id = null
      payload.insolvency_decision_document_id = null
      payload.insolvency_payment_instruction_id = null
      payload.insolvency_payment_instruction_hash = null
    }
    const saved = await payrollEnforcementApi.saveInsolvencyEvidence(
      selectedEmployee,
      period.value,
      payload,
    )
    applyLoaded(saved, options.value)
    toast.success(t('payroll.insolvency.saved'))
  } catch (error: any) {
    if (error?.response?.data?.error?.code === 'row_version_conflict') {
      await load()
      toast.warning(t('payroll.insolvency.conflict'))
    } else {
      toast.error(error?.response?.data?.error?.message
        || error?.message
        || t('payroll.insolvency.save_failed'))
    }
  } finally {
    saving.value = false
  }
}

async function cancelApproved() {
  const current = evidence.value
  const selectedEmployee = employeeId.value
  if (!current?.row_version || selectedEmployee === null || !canWrite.value) return
  if (!window.confirm(t('payroll.insolvency.cancel_confirm'))) return
  saving.value = true
  try {
    const cancelled = await payrollEnforcementApi.cancelInsolvency(
      selectedEmployee,
      period.value,
      current.row_version,
    )
    applyLoaded(cancelled, options.value)
    toast.success(t('payroll.insolvency.cancelled'))
  } catch (error: any) {
    if (error?.response?.data?.error?.code === 'row_version_conflict') {
      await load()
      toast.warning(t('payroll.insolvency.conflict'))
    } else {
      toast.error(error?.response?.data?.error?.message || t('payroll.insolvency.cancel_failed'))
    }
  } finally {
    saving.value = false
  }
}

function searchDocuments(query: string) {
  documentQuery.value = query
  documentId.value = null
  documentCandidates.value = []
  if (documentTimer !== null) clearTimeout(documentTimer)
  const normalized = query.trim()
  if (normalized.length < 2 || !canReadDocuments.value) return
  const sequence = ++documentSequence
  documentTimer = setTimeout(async () => {
    documentSearching.value = true
    try {
      const candidates = await documentsApi.search(normalized)
      if (sequence === documentSequence) {
        documentCandidates.value = candidates.filter(document => document.scope === 'company')
      }
    } catch {
      if (sequence === documentSequence) toast.error(t('payroll.insolvency.document_search_failed'))
    } finally {
      if (sequence === documentSequence) documentSearching.value = false
    }
  }, 250)
}

function onDocumentInput(event: Event) {
  searchDocuments((event.target as HTMLInputElement).value)
}

function selectDocument(document: DocItem) {
  documentId.value = document.id
  documentQuery.value = document.title
  documentCandidates.value = []
}

watch([employeeId, period], load, { immediate: true })
</script>

<template>
  <div class="space-y-5">
    <header>
      <h1 class="text-2xl font-semibold text-neutral-900">{{ t('payroll.insolvency.title') }}</h1>
      <p class="mt-1 max-w-3xl text-sm text-neutral-500">{{ t('payroll.insolvency.subtitle') }}</p>
    </header>

    <section class="rounded-lg border border-neutral-200 bg-surface p-4">
      <div class="flex flex-wrap items-end gap-3">
        <label class="min-w-64 flex-1 text-xs font-medium text-neutral-600">
          {{ t('payroll.insolvency.employee') }}
          <PayrollPersonSearchSelect
            v-model="employeeId"
            class="mt-1"
            :label="t('payroll.insolvency.employee')"
            :disabled="saving"
            data-test="insolvency-person"
          />
        </label>
        <label class="text-xs font-medium text-neutral-600">
          {{ t('payroll.insolvency.period') }}
          <input v-model="period" type="month" :disabled="saving" class="mt-1 block rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm">
        </label>
      </div>
    </section>

    <div v-if="loading" class="rounded-lg border border-neutral-200 bg-surface p-6 text-sm text-neutral-500">
      {{ t('common.loading') }}
    </div>
    <div v-else-if="loadFailed" class="rounded-lg border border-danger-200 bg-danger-50 p-4 text-sm text-danger-700" role="alert">
      {{ t('payroll.insolvency.load_failed') }}
    </div>
    <EmptyState
      v-else-if="employeeId === null"
      :title="t('payroll.insolvency.empty_title')"
      :message="t('payroll.insolvency.empty_message')"
      :icon-path="ICONS.user"
    />

    <section v-else-if="evidence" class="rounded-lg border border-neutral-200 bg-surface p-4">
      <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <label class="text-xs font-medium text-neutral-600">
          {{ t('payroll.insolvency.mode') }}
          <select v-model="evidence.insolvency_mode" :disabled="!canWrite || saving" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm" data-test="insolvency-mode">
            <option value="none">{{ t('payroll.insolvency.mode_none') }}</option>
            <option value="approved_standard">{{ t('payroll.insolvency.mode_standard') }}</option>
            <option value="alert_only">{{ t('payroll.insolvency.mode_alert') }}</option>
            <option value="court_determined_amount">{{ t('payroll.insolvency.mode_court') }}</option>
          </select>
        </label>
        <p class="rounded-md border border-warning-500/40 bg-warning-50 p-3 text-xs text-warning-700">
          {{ t(`payroll.insolvency.impact.${evidence.insolvency_mode}`) }}
        </p>
      </div>

      <div v-if="evidence.insolvency_mode === 'approved_standard'" class="mt-5 grid grid-cols-1 gap-4 lg:grid-cols-2">
        <label class="text-xs font-medium text-neutral-600">
          {{ t('payroll.insolvency.employment') }}
          <select v-model="employmentId" :disabled="!canWrite || saving" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm" data-test="insolvency-employment">
            <option :value="null">{{ t('payroll.insolvency.select_employment') }}</option>
            <option v-for="item in options.employments" :key="item.id" :value="item.id">
              {{ item.code }} · {{ item.actual_start_date || item.start_date || '—' }}<template v-if="item.end_date"> – {{ item.end_date }}</template>
            </option>
          </select>
          <span v-if="options.employments.length === 0" class="mt-1 block text-danger-700">{{ t('payroll.insolvency.no_employments') }}</span>
        </label>
        <label class="text-xs font-medium text-neutral-600">
          {{ t('payroll.insolvency.account') }}
          <select v-model="accountId" :disabled="!canWrite || saving" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm" data-test="insolvency-account">
            <option :value="null">{{ t('payroll.insolvency.select_account') }}</option>
            <option v-for="account in options.recipient_accounts" :key="account.id" :value="account.id">
              {{ account.institution_name }} · {{ account.bank_account_masked }} · {{ account.currency_code }}
            </option>
          </select>
          <span v-if="options.recipient_accounts.length === 0" class="mt-1 block text-danger-700">{{ t('payroll.insolvency.no_accounts') }}</span>
        </label>
        <div class="relative lg:col-span-2">
          <label class="text-xs font-medium text-neutral-600" for="insolvency-document-search">{{ t('payroll.insolvency.document') }}</label>
          <input
            id="insolvency-document-search"
            :value="documentQuery"
            :disabled="!canWrite || !canReadDocuments || saving"
            class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm"
            :placeholder="t('payroll.insolvency.document_search')"
            data-test="insolvency-document"
            @input="onDocumentInput"
          >
          <p v-if="!canReadDocuments" class="mt-1 text-xs text-danger-700">{{ t('payroll.insolvency.document_permission') }}</p>
          <p v-else-if="documentSearching" class="mt-1 text-xs text-neutral-500">{{ t('common.loading') }}</p>
          <ul v-if="documentCandidates.length" class="absolute z-10 mt-1 max-h-56 w-full overflow-auto rounded-md border border-neutral-200 bg-surface p-1 shadow-lg">
            <li v-for="document in documentCandidates" :key="document.id">
              <button type="button" class="w-full rounded px-3 py-2 text-left text-sm hover:bg-neutral-50" @click="selectDocument(document)">
                {{ document.title }}
              </button>
            </li>
          </ul>
        </div>
      </div>

      <label v-if="evidence.insolvency_mode === 'court_determined_amount'" class="mt-4 block text-xs font-medium text-neutral-600">
        {{ t('payroll.insolvency.court_amount') }}
        <input v-model="courtAmountCzk" inputmode="decimal" :disabled="!canWrite || saving" class="mt-1 w-full max-w-sm rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm">
      </label>

      <div v-if="hasApprovedInstruction" class="mt-4 rounded-md border border-success-200 bg-success-50 p-3 text-xs text-success-700">
        {{ t('payroll.insolvency.instruction_bound', { id: evidence.insolvency_payment_instruction_id }) }}
      </div>
      <div v-if="leavingApprovedInstruction" class="mt-4 rounded-md border border-warning-500/40 bg-warning-50 p-3 text-sm text-warning-700">
        {{ t('payroll.insolvency.explicit_cancel_required') }}
      </div>

      <div class="mt-5 flex flex-wrap gap-2">
        <button type="button" :class="btnFilled('primary')" :disabled="!canSave" data-test="insolvency-save" @click="save">
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.check" /></svg>
          {{ t('common.save') }}
        </button>
        <button v-if="hasApprovedInstruction" type="button" :class="btnOutline('danger')" :disabled="!canWrite || saving" data-test="insolvency-cancel" @click="cancelApproved">
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.x" /></svg>
          {{ t('payroll.insolvency.cancel') }}
        </button>
      </div>
    </section>
  </div>
</template>

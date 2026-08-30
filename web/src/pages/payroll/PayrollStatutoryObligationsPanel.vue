<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { isAxiosError } from 'axios'
import { useI18n } from 'vue-i18n'
import {
  payrollApi,
  type PayrollPersonOption,
  type PayrollRegzelEnvironment,
  type PayrollStatutoryAgendaCapabilityItem,
  type PayrollStatutoryObligationEvidencePayload,
  type PayrollStatutoryObligationOverview,
} from '@/api/payroll'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import { btnFilled, btnOutline, ICONS } from '@/components/ui/buttonStyles'
import { useAuthStore } from '@/stores/auth'

const props = defineProps<{ environment: PayrollRegzelEnvironment }>()
const emit = defineEmits<{
  'update:environment': [value: PayrollRegzelEnvironment]
}>()

const { t } = useI18n()
const auth = useAuthStore()
const environmentModel = computed({
  get: () => props.environment,
  set: value => emit('update:environment', value),
})
const period = ref(localPeriod())
const overview = ref<PayrollStatutoryObligationOverview | null>(null)
const people = ref<PayrollPersonOption[]>([])
const loading = ref(false)
const saving = ref(false)
const error = ref('')
const success = ref('')
const activeAgenda = ref<
  'NEMPRI' | 'HZUPN' | 'STATUTORY_ACCIDENT_INSURANCE' | null
>(null)
const employeeId = ref<number | null>(null)
const caseReference = ref('')
const receiptReference = ref('')
const completedOn = ref(localDate())
const documentId = ref<number | null>(null)
const paymentAmount = ref('')
const confirmed = ref(false)

const canWrite = computed(() => auth.canWrite('payroll.submissions'))
const environmentOptions = computed(() => [
  { value: 'production', label: t('payroll.regzel.environment.production') },
  { value: 'test', label: t('payroll.regzel.environment.test') },
])
const peopleOptions = computed(() => people.value.map(person => ({
  value: person.id,
  label: person.full_name,
  secondary: `#${person.id}`,
})))
const selectedPerson = computed(() =>
  peopleOptions.value.find(option => option.value === employeeId.value) ?? null)
const accidentInsurance = computed(() =>
  activeAgenda.value === 'STATUTORY_ACCIDENT_INSURANCE')
const canSave = computed(() =>
  canWrite.value
  && activeAgenda.value !== null
  && (accidentInsurance.value
    ? /^[0-9]{1,12}(?:[.,][0-9]{1,2})?$/.test(paymentAmount.value)
      && Number(paymentAmount.value.replace(',', '.')) > 0
    : employeeId.value !== null)
  && caseReference.value.trim() !== ''
  && receiptReference.value.trim() !== ''
  && completedOn.value !== ''
  && documentId.value !== null
  && documentId.value > 0
  && confirmed.value)

function localDate(): string {
  const now = new Date()
  const year = now.getFullYear()
  const month = String(now.getMonth() + 1).padStart(2, '0')
  const day = String(now.getDate()).padStart(2, '0')
  return `${year}-${month}-${day}`
}

function localPeriod(): string {
  return localDate().slice(0, 7)
}

function apiMessage(exception: unknown): string {
  if (isAxiosError<{ error?: { message?: string } }>(exception)) {
    return exception.response?.data?.error?.message
      || t('payroll.submissions.statutory.error')
  }
  return t('payroll.submissions.statutory.error')
}

function badgeClass(item: PayrollStatutoryAgendaCapabilityItem): string {
  if (item.capability === 'not_supported') {
    return 'bg-danger-50 text-danger-700 border-danger-500/30'
  }
  if (item.capability === 'prepared_only') {
    return 'bg-primary-50 text-primary-700 border-primary-500/30'
  }
  return 'bg-warning-50 text-warning-700 border-warning-500/30'
}

async function load() {
  loading.value = true
  error.value = ''
  try {
    overview.value = await payrollApi.statutoryObligationOverview(
      props.environment,
      period.value,
    )
  } catch (exception: unknown) {
    overview.value = null
    error.value = apiMessage(exception)
  } finally {
    loading.value = false
  }
}

function openEvidence(agenda: PayrollStatutoryAgendaCapabilityItem) {
  if (!agenda.evidence_supported || agenda.agenda_code === 'ELDP') return
  activeAgenda.value = agenda.agenda_code
  success.value = ''
  error.value = ''
  confirmed.value = false
}

function closeEvidence() {
  activeAgenda.value = null
}

async function saveEvidence() {
  if (!canSave.value || activeAgenda.value === null || documentId.value === null) return
  saving.value = true
  error.value = ''
  success.value = ''
  try {
    const common = {
      environment: props.environment,
      period: period.value,
      case_reference: caseReference.value.trim(),
      receipt_reference: receiptReference.value.trim(),
      completed_on: completedOn.value,
      document_id: documentId.value,
    }
    let payload: PayrollStatutoryObligationEvidencePayload
    if (activeAgenda.value === 'STATUTORY_ACCIDENT_INSURANCE') {
      payload = {
        ...common,
        agenda_code: activeAgenda.value,
        payment_amount: paymentAmount.value,
        manual_payment_confirmed: true,
      }
    } else {
      if (employeeId.value === null) return
      payload = {
        ...common,
        agenda_code: activeAgenda.value,
        employee_id: employeeId.value,
        manual_submission_confirmed: true,
      }
    }
    await payrollApi.recordStatutoryObligationEvidence(
      payload,
      crypto.randomUUID(),
    )
    success.value = t('payroll.submissions.statutory.saved')
    caseReference.value = ''
    receiptReference.value = ''
    documentId.value = null
    paymentAmount.value = ''
    confirmed.value = false
    activeAgenda.value = null
    await load()
  } catch (exception: unknown) {
    error.value = apiMessage(exception)
  } finally {
    saving.value = false
  }
}

onMounted(async () => {
  try {
    people.value = await payrollApi.peopleOptions()
  } catch (exception: unknown) {
    error.value = apiMessage(exception)
  }
  await load()
})
watch(() => props.environment, load)
watch(period, load)
</script>

<template>
  <div class="space-y-5" data-test="statutory-obligations-panel">
    <section class="rounded-xl border border-neutral-200 bg-surface p-4 shadow-sm sm:p-6">
      <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
          <h2 class="text-lg font-semibold text-neutral-900">
            {{ t('payroll.submissions.statutory.title') }}
          </h2>
          <p class="mt-1 max-w-3xl text-sm text-neutral-500">
            {{ t('payroll.submissions.statutory.description') }}
          </p>
        </div>
        <div class="flex flex-wrap gap-3">
          <label class="block min-w-44">
            <span class="mb-1 block text-xs font-medium text-neutral-600">
              {{ t('payroll.submissions.statutory.environment') }}
            </span>
            <SearchableSelect
              v-model="environmentModel"
              :options="environmentOptions"
              :clearable="false"
              accent="payroll"
            />
          </label>
          <label class="block">
            <span class="mb-1 block text-xs font-medium text-neutral-600">
              {{ t('payroll.submissions.statutory.period') }}
            </span>
            <input
              v-model="period"
              data-test="statutory-period"
              type="month"
              class="rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm"
            >
          </label>
        </div>
      </div>
      <p
        v-if="overview"
        class="mt-4 break-all text-xs text-neutral-400"
        data-test="statutory-matrix-version"
      >
        {{ t('payroll.submissions.statutory.matrix_version', {
          version: overview.matrix_version,
          hash: overview.matrix_sha256,
        }) }}
      </p>
    </section>

    <p v-if="error" class="rounded-lg border border-danger-500/30 bg-danger-50 p-3 text-sm text-danger-700">
      {{ error }}
    </p>
    <p v-if="success" class="rounded-lg border border-success-500/30 bg-success-50 p-3 text-sm text-success-700">
      {{ success }}
    </p>
    <p v-if="loading" class="text-sm text-neutral-500">{{ t('common.loading') }}</p>

    <section v-if="overview" class="grid grid-cols-1 gap-4 lg:grid-cols-2">
      <article
        v-for="agenda in overview.agendas"
        :key="agenda.agenda_code"
        class="rounded-xl border border-neutral-200 bg-surface p-4 shadow-sm sm:p-5"
        :data-test="`statutory-agenda-${agenda.agenda_code}`"
      >
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div>
            <h3 class="font-semibold text-neutral-900">
              {{ t(`payroll.submissions.statutory.agenda.${agenda.agenda_code}`) }}
            </h3>
            <p class="mt-1 text-xs text-neutral-500">{{ agenda.agenda_code }}</p>
          </div>
          <span class="rounded-full border px-2.5 py-1 text-xs font-medium" :class="badgeClass(agenda)">
            {{ t(`payroll.submissions.statutory.capability.${agenda.capability}`) }}
          </span>
        </div>
        <p class="mt-3 text-sm text-neutral-700">
          {{ t(`payroll.submissions.statutory.reason.${agenda.reason_code}`) }}
        </p>
        <dl class="mt-3 grid grid-cols-1 gap-2 text-xs sm:grid-cols-2">
          <div>
            <dt class="text-neutral-500">{{ t('payroll.submissions.statutory.replacement') }}</dt>
            <dd class="mt-0.5 font-medium text-neutral-800">
              {{ t(`payroll.submissions.statutory.replacement_mode.${agenda.replacement_mode}`) }}
            </dd>
          </div>
          <div>
            <dt class="text-neutral-500">{{ t('payroll.submissions.statutory.transport') }}</dt>
            <dd class="mt-0.5 font-medium"
              :class="agenda.transport_capability === 'isds' ? 'text-neutral-800' : 'text-danger-700'">
              {{ t(`payroll.submissions.statutory.transport_${agenda.transport_capability === 'isds' ? 'isds' : 'not_supported'}`) }}
            </dd>
          </div>
        </dl>
        <ol v-if="agenda.workflow_codes.length" class="mt-4 space-y-2 text-sm text-neutral-700">
          <li v-for="(step, index) in agenda.workflow_codes" :key="step" class="flex gap-2">
            <span class="font-semibold text-payroll-700">{{ index + 1 }}.</span>
            <span>{{ t(`payroll.submissions.statutory.workflow.${step}`) }}</span>
          </li>
        </ol>
        <div v-if="agenda.evidence_supported && canWrite" class="mt-4 flex flex-wrap justify-end gap-2">
          <button type="button" :class="btnFilled('primary')" @click="openEvidence(agenda)">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
              <path :d="ICONS.doc" />
            </svg>
            {{ t('payroll.submissions.statutory.record_evidence') }}
          </button>
        </div>
      </article>
    </section>

    <section v-if="activeAgenda" class="rounded-xl border border-payroll-500/30 bg-surface p-4 shadow-sm sm:p-6" data-test="statutory-evidence-form">
      <h3 class="font-semibold text-neutral-900">
        {{ t('payroll.submissions.statutory.form.title', {
          agenda: t(`payroll.submissions.statutory.agenda.${activeAgenda}`),
        }) }}
      </h3>
      <p class="mt-1 text-sm text-neutral-500">{{ t('payroll.submissions.statutory.form.description') }}</p>
      <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
        <label v-if="!accidentInsurance" class="block">
          <span class="mb-1 block text-sm font-medium text-neutral-700">{{ t('payroll.submissions.statutory.form.employee') }}</span>
          <SearchableSelect
            v-model="employeeId"
            data-test="statutory-employee"
            :options="peopleOptions"
            :selected-option="selectedPerson"
            :clearable="false"
            accent="payroll"
          />
        </label>
        <label v-else class="block">
          <span class="mb-1 block text-sm font-medium text-neutral-700">{{ t('payroll.submissions.statutory.form.payment_amount') }}</span>
          <div class="flex rounded-md border border-neutral-300 bg-surface focus-within:ring-2 focus-within:ring-payroll-500">
            <input v-model="paymentAmount" data-test="statutory-payment-amount" inputmode="decimal" class="min-w-0 flex-1 rounded-l-md bg-transparent px-3 py-2 text-sm">
            <span class="border-l border-neutral-300 px-3 py-2 text-sm text-neutral-500">CZK</span>
          </div>
        </label>
        <label class="block">
          <span class="mb-1 block text-sm font-medium text-neutral-700">
            {{ t(accidentInsurance
              ? 'payroll.submissions.statutory.form.paid_on'
              : 'payroll.submissions.statutory.form.completed_on') }}
          </span>
          <input v-model="completedOn" data-test="statutory-completed-on" type="date" class="w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm">
        </label>
        <label class="block">
          <span class="mb-1 block text-sm font-medium text-neutral-700">
            {{ t(accidentInsurance
              ? 'payroll.submissions.statutory.form.obligation_reference'
              : 'payroll.submissions.statutory.form.case_reference') }}
          </span>
          <input v-model="caseReference" data-test="statutory-case-reference" maxlength="128" class="w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm">
        </label>
        <label class="block">
          <span class="mb-1 block text-sm font-medium text-neutral-700">
            {{ t(accidentInsurance
              ? 'payroll.submissions.statutory.form.payment_reference'
              : 'payroll.submissions.statutory.form.receipt_reference') }}
          </span>
          <input v-model="receiptReference" data-test="statutory-receipt-reference" maxlength="128" class="w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm">
        </label>
        <label class="block md:col-span-2">
          <span class="mb-1 block text-sm font-medium text-neutral-700">{{ t('payroll.submissions.statutory.form.document_id') }}</span>
          <input v-model.number="documentId" data-test="statutory-document-id" min="1" type="number" class="w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm">
          <span class="mt-1 block text-xs text-neutral-500">{{ t('payroll.submissions.statutory.form.document_hint') }}</span>
        </label>
      </div>
      <label class="mt-4 flex cursor-pointer items-start gap-3 rounded-lg border border-warning-500/30 bg-warning-50 p-4">
        <input v-model="confirmed" data-test="statutory-confirmation" type="checkbox" class="mt-0.5 h-4 w-4 rounded border-neutral-300 text-payroll-600 focus:ring-payroll-500">
        <span class="text-sm text-neutral-700">
          {{ t(accidentInsurance
            ? 'payroll.submissions.statutory.form.payment_confirmation'
            : 'payroll.submissions.statutory.form.confirmation') }}
        </span>
      </label>
      <div class="mt-4 flex flex-wrap justify-end gap-2">
        <button type="button" :class="btnOutline('neutral')" :disabled="saving" @click="closeEvidence">
          {{ t('common.cancel') }}
        </button>
        <button data-test="statutory-evidence-save" type="button" :class="btnFilled('success')" :disabled="saving || !canSave" @click="saveEvidence">
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.check" /></svg>
          {{ saving ? t('common.saving') : t('payroll.submissions.statutory.form.save') }}
        </button>
      </div>
    </section>

    <section v-if="overview" class="rounded-xl border border-neutral-200 bg-surface shadow-sm">
      <div class="border-b border-neutral-200 p-4 sm:p-5">
        <h3 class="font-semibold text-neutral-900">{{ t('payroll.submissions.statutory.evidence.title') }}</h3>
        <p class="mt-1 text-sm text-neutral-500">{{ t('payroll.submissions.statutory.evidence.description') }}</p>
      </div>
      <p v-if="overview.evidence.length === 0" class="p-5 text-sm text-neutral-500">
        {{ t('payroll.submissions.statutory.evidence.empty') }}
      </p>
      <ul v-else class="divide-y divide-neutral-100">
        <li v-for="item in overview.evidence" :key="item.id" class="p-4 sm:p-5">
          <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
              <p class="font-medium text-neutral-900">
                {{ item.agenda_code }}<template v-if="item.full_name"> · {{ item.full_name }}</template>
              </p>
              <p class="mt-1 text-sm text-neutral-600">{{ item.case_reference }} · {{ item.receipt_reference }}</p>
              <p v-if="item.payment_amount_minor !== null" class="mt-1 text-sm font-medium text-neutral-800">
                {{ t('payroll.submissions.statutory.evidence.payment_amount', {
                  amount: (item.payment_amount_minor / 100).toFixed(2),
                  currency: item.payment_currency,
                }) }}
              </p>
            </div>
            <span class="rounded-full bg-success-50 px-2.5 py-1 text-xs font-medium text-success-700">
              {{ item.completed_on }}
            </span>
          </div>
          <p class="mt-2 break-all text-xs text-neutral-500">
            {{ t('payroll.submissions.statutory.evidence.document', { id: item.document_id, title: item.document_title }) }}
            · SHA-256 {{ item.document_sha256 }}
          </p>
        </li>
      </ul>
    </section>
  </div>
</template>

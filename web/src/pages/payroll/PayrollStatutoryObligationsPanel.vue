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
import { documentsApi, type DocItem } from '@/api/documents'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import EnvironmentSwitch from '@/components/ui/EnvironmentSwitch.vue'
import { btnFilled, btnOutline, ICONS } from '@/components/ui/buttonStyles'
import { useAuthStore } from '@/stores/auth'
import { usePayrollLabels } from '@/composables/usePayrollLabels'
import { formatDate, formatMoneyMinor } from '@/composables/useFormat'

const props = defineProps<{ environment: PayrollRegzelEnvironment }>()
const emit = defineEmits<{
  'update:environment': [value: PayrollRegzelEnvironment]
}>()

const { t } = useI18n()
const { submissionAgendaLabel } = usePayrollLabels()
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
/*
 * Dokument se VYHLEDÁVÁ, nezadává se jeho ID.
 *
 * Dřív tu bylo číselné pole „ID dokumentu": účetní musela otevřít Dokumenty
 * v druhém okně, najít doklad, opsat číslo z adresního řádku a doufat, že se
 * neseklo o řádek. Databázové ID není nic, co by kdokoli mimo aplikaci znal —
 * a překlep v něm připne k důkazu cizí soubor. Server pořád dostává
 * `document_id`, jen ho vybírá vyhledávání.
 */
const documentId = ref<number | null>(null)
const documentQuery = ref('')
const documentResults = ref<DocItem[]>([])
const selectedDocument = ref<DocItem | null>(null)
const documentSearching = ref(false)
const paymentAmount = ref('')
const confirmed = ref(false)

const canWrite = computed(() => auth.canWrite('payroll.submissions'))
// Doplňkový řádek u jména nesmí být databázové ID — účetní podle „#4217"
// zaměstnance nepozná a při shodě jmen jí to nepomůže rozhodnout. Osobní
// číslo, které by pomohlo, `PayrollPersonOption` nenese, takže se raději
// nezobrazuje nic než zavádějící číslo.
const peopleOptions = computed(() => people.value.map(person => ({
  value: person.id,
  label: person.full_name,
})))
const selectedPerson = computed(() =>
  peopleOptions.value.find(option => option.value === employeeId.value) ?? null)
const accidentInsurance = computed(() =>
  activeAgenda.value === 'STATUTORY_ACCIDENT_INSURANCE')
const amountValid = computed(() =>
  /^[0-9]{1,12}(?:[.,][0-9]{1,2})?$/.test(paymentAmount.value)
  && Number(paymentAmount.value.replace(',', '.')) > 0)

const canSave = computed(() =>
  canWrite.value
  && activeAgenda.value !== null
  && (accidentInsurance.value ? amountValid.value : employeeId.value !== null)
  && caseReference.value.trim() !== ''
  && receiptReference.value.trim() !== ''
  && completedOn.value !== ''
  && documentId.value !== null
  && documentId.value > 0
  && confirmed.value)

/**
 * Co ještě chybí, aby šlo uložit.
 *
 * Formulář má šest polí a zaškrtávátko; tlačítko viselo zhasnuté a neřeklo
 * které z nich zlobí. Všechny vyjmenované podmínky vynucuje
 * `PayrollStatutoryObligationService` — nejsou to naše ceremonie, ale bez
 * nich by z důkazu o ručním podání nešlo po roce nic dohledat.
 */
const saveBlockers = computed<string[]>(() => {
  if (!canWrite.value) return [t('payroll.submissions.statutory.form.read_only')]
  const missing: string[] = []
  if (accidentInsurance.value) {
    if (!amountValid.value) missing.push(t('payroll.submissions.statutory.form.missing_amount'))
  } else if (employeeId.value === null) {
    missing.push(t('payroll.submissions.statutory.form.missing_employee'))
  }
  if (completedOn.value === '') missing.push(t('payroll.submissions.statutory.form.missing_completed_on'))
  if (caseReference.value.trim() === '') missing.push(t('payroll.submissions.statutory.form.missing_case_reference'))
  if (receiptReference.value.trim() === '') missing.push(t('payroll.submissions.statutory.form.missing_receipt_reference'))
  if (documentId.value === null || documentId.value <= 0) {
    missing.push(t('payroll.submissions.statutory.form.missing_document'))
  }
  if (!confirmed.value) missing.push(t('payroll.submissions.statutory.form.missing_confirmation'))
  return missing
})

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

async function searchDocuments() {
  const query = documentQuery.value.trim()
  if (query.length < 2) {
    documentResults.value = []
    return
  }
  documentSearching.value = true
  error.value = ''
  try {
    // Osobní dokumenty a koš server u důkazu stejně odmítne, takže se
    // nenabízejí — jinak by uživatel vybral doklad a dozvěděl se to až z chyby.
    documentResults.value = (await documentsApi.search(query))
      .filter(document => document.scope !== 'user' && document.deleted_at === null)
  } catch (exception: unknown) {
    documentResults.value = []
    error.value = apiMessage(exception)
  } finally {
    documentSearching.value = false
  }
}

function chooseDocument(document: DocItem) {
  selectedDocument.value = document
  documentId.value = document.id
  documentQuery.value = document.title
  documentResults.value = []
}

function clearDocument() {
  selectedDocument.value = null
  documentId.value = null
  documentQuery.value = ''
  documentResults.value = []
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
    clearDocument()
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
          <div class="block min-w-44">
            <span class="mb-1 block text-xs font-medium text-neutral-600">
              {{ t('payroll.submissions.statutory.environment') }}
            </span>
            <EnvironmentSwitch
              v-model="environmentModel"
              size="sm"
              :aria-label="t('payroll.submissions.statutory.environment')"
              data-test="statutory-environment"
            />
          </div>
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
      <!--
        Verze a otisk matice jsou údaj pro podporu, ne pro účetní. Pod
        nadpisem stránky stál 64znakový hash a čtyři pětiny šířky zabíral text,
        se kterým nikdo nic nedělá. Zůstává dostupný, jen složený.
      -->
      <details
        v-if="overview"
        class="mt-4 text-xs text-neutral-400"
        data-test="statutory-matrix-version"
      >
        <summary class="cursor-pointer">
          {{ t('payroll.submissions.statutory.matrix_technical') }}
        </summary>
        <p class="mt-1 break-all">
          {{ t('payroll.submissions.statutory.matrix_version', {
            version: overview.matrix_version,
            hash: overview.matrix_sha256,
          }) }}
        </p>
      </details>
    </section>

    <!--
      Tlačítko u hlášky, protože panel nemá v hlavičce žádné Obnovit. Po
      výpadku zbývala jen červená věta a jediná cesta ven bylo přepnout měsíc
      na jiný a zpátky, což nikoho nenapadne.
    -->
    <div
      v-if="error"
      class="rounded-lg border border-danger-500/30 bg-danger-50 p-3 text-sm text-danger-700"
      role="alert"
      data-test="statutory-error"
    >
      <p>{{ error }}</p>
      <button
        type="button"
        :class="[btnOutline('danger'), 'mt-3']"
        :disabled="loading"
        data-test="statutory-retry"
        @click="load"
      >
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
          <path :d="ICONS.cycle" />
        </svg>
        {{ t('common.retry') }}
      </button>
    </div>
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
            <!-- Sdílený slovník: kód, který katalog nezná, spadne na poctivé
                 „neznámá agenda", ne na vypsaný překladový klíč. -->
            <h3 class="font-semibold text-neutral-900">
              {{ submissionAgendaLabel(agenda.agenda_code) }}
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
          agenda: submissionAgendaLabel(activeAgenda),
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
        <div class="relative block md:col-span-2">
          <label class="block">
            <span class="mb-1 block text-sm font-medium text-neutral-700">{{ t('payroll.submissions.statutory.form.document') }}</span>
            <span class="flex flex-wrap gap-2">
              <input
                v-model="documentQuery"
                data-test="statutory-document-query"
                type="search"
                :readonly="selectedDocument !== null"
                :placeholder="t('payroll.submissions.statutory.form.document_placeholder')"
                class="h-10 min-w-64 flex-1 rounded-md border border-neutral-300 bg-surface px-3 text-sm"
                @keyup.enter.prevent="searchDocuments"
              >
              <button
                v-if="selectedDocument === null"
                type="button"
                :class="btnOutline('neutral')"
                :disabled="documentSearching"
                data-test="statutory-document-search"
                @click="searchDocuments"
              >
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                  <path :d="ICONS.search" />
                </svg>
                {{ t('common.search') }}
              </button>
              <button v-else type="button" :class="btnOutline('neutral')" data-test="statutory-document-clear" @click="clearDocument">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                  <path :d="ICONS.edit" />
                </svg>
                {{ t('common.edit') }}
              </button>
            </span>
          </label>
          <div
            v-if="documentResults.length"
            class="absolute z-20 mt-1 max-h-52 w-full overflow-auto rounded-md border border-neutral-200 bg-surface shadow-lg"
          >
            <button
              v-for="document in documentResults"
              :key="document.id"
              type="button"
              class="cursor-pointer block w-full px-3 py-2 text-left text-sm text-neutral-900 hover:bg-neutral-100"
              data-test="statutory-document-option"
              @click="chooseDocument(document)"
            >
              <span class="font-medium">{{ document.title }}</span>
              <span class="ml-2 text-xs text-neutral-500">{{ document.original_name }}</span>
            </button>
          </div>
          <span class="mt-1 block text-xs text-neutral-500">{{ t('payroll.submissions.statutory.form.document_hint') }}</span>
        </div>
      </div>
      <label class="mt-4 flex cursor-pointer items-start gap-3 rounded-lg border border-warning-500/30 bg-warning-50 p-4">
        <input v-model="confirmed" data-test="statutory-confirmation" type="checkbox" class="mt-0.5 h-4 w-4 rounded border-neutral-300 text-payroll-600 focus:ring-payroll-500">
        <span class="text-sm text-neutral-700">
          {{ t(accidentInsurance
            ? 'payroll.submissions.statutory.form.payment_confirmation'
            : 'payroll.submissions.statutory.form.confirmation') }}
        </span>
      </label>
      <div class="mt-4 flex flex-wrap items-center justify-end gap-3">
        <p
          v-if="saveBlockers.length"
          class="flex-1 text-sm text-neutral-600"
          data-test="statutory-save-blockers"
        >
          {{ t('payroll.submissions.statutory.form.missing', { list: saveBlockers.join(', ') }) }}
        </p>
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
                {{ submissionAgendaLabel(item.agenda_code) }}<template v-if="item.full_name"> · {{ item.full_name }}</template>
              </p>
              <p class="mt-1 text-sm text-neutral-600">{{ item.case_reference }} · {{ item.receipt_reference }}</p>
              <p v-if="item.payment_amount_minor !== null" class="mt-1 text-sm font-medium text-neutral-800">
                <!-- Částka v místním tvaru („1 234,00 Kč"), ne holé
                     `toFixed(2)` — vedle ní stojí částky ze zbytku appky. -->
                {{ formatMoneyMinor(item.payment_amount_minor, item.payment_currency ?? 'CZK') }}
              </p>
            </div>
            <span class="rounded-full bg-success-50 px-2.5 py-1 text-xs font-medium text-success-700">
              {{ formatDate(item.completed_on) }}
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
